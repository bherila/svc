<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class InvoiceLifecycleService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(Workspace $workspace, ClientCompany $company, array $attributes, array $lines): ClientInvoice
    {
        $this->assertCompanyTenant($workspace, $company);
        $currency = MoneyService::currency($attributes['currency'] ?? null);
        $totals = MoneyService::invoiceTotals($lines);

        return DB::transaction(function () use ($workspace, $company, $attributes, $lines, $currency, $totals): ClientInvoice {
            $invoice = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_agreement_id' => $attributes['client_agreement_id'] ?? null,
                'client_billing_schedule_id' => $attributes['client_billing_schedule_id'] ?? null,
                'invoice_number' => $this->requiredString($attributes['invoice_number'] ?? null, 'invoice_number'),
                'status' => 'draft',
                'issue_date' => $attributes['issue_date'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'service_period_start' => $attributes['service_period_start'] ?? null,
                'service_period_end' => $attributes['service_period_end'] ?? null,
                'currency' => $currency,
                ...$totals,
                'balance_amount' => $totals['total_amount'],
                'notes' => $attributes['notes'] ?? null,
                'is_visible_to_client' => (bool) ($attributes['is_visible_to_client'] ?? false),
            ]);

            foreach ($lines as $line) {
                $lineTotal = self::lineTotal($line);
                $invoice->lines()->create([
                    'workspace_id' => $workspace->id,
                    'type' => $this->requiredString($line['type'] ?? null, 'line type'),
                    'description' => $this->requiredString($line['description'] ?? null, 'line description'),
                    'quantity' => $line['quantity'],
                    'unit_amount' => MoneyService::nonNegativeInteger($line['unit_amount'] ?? null, 'unit_amount'),
                    'tax_amount' => MoneyService::nonNegativeInteger($line['tax_amount'] ?? 0, 'tax_amount'),
                    'total_amount' => $lineTotal,
                    'sort_order' => $this->nonNegativeSortOrder($line['sort_order'] ?? 0),
                ]);
            }

            return $invoice->load('lines', 'clientCompany');
        });
    }

    public function issue(ClientInvoice $invoice, ?Workspace $workspace = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status !== 'draft') {
                if ($locked->status === 'issued' || $locked->status === 'partially_paid' || $locked->status === 'paid') {
                    return $locked;
                }

                throw new DomainException('Only draft invoices can be issued.');
            }

            $issueDate = $locked->issue_date ?? CarbonImmutable::today();
            if ($locked->due_date !== null && $locked->due_date->lt($issueDate)) {
                throw new DomainException('The due date cannot precede the issue date.');
            }

            $locked->forceFill([
                'issue_date' => $issueDate,
                'due_date' => $locked->due_date ?? $issueDate,
                'issued_at' => now(),
                'status' => 'issued',
                'is_visible_to_client' => true,
                'balance_amount' => $locked->total_amount,
            ])->save();

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    public function void(ClientInvoice $invoice, ?Workspace $workspace = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status === 'void') {
                return $locked;
            }

            if ($locked->paid_amount > 0 || $locked->status === 'paid') {
                throw new DomainException('A paid invoice cannot be voided.');
            }

            $hasPendingPayments = $locked->payments()->where('status', 'pending')->exists();
            if ($hasPendingPayments) {
                throw new DomainException('Cancel or resolve pending payments before voiding this invoice.');
            }

            $locked->forceFill(['status' => 'void', 'voided_at' => now(), 'balance_amount' => 0])->save();

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    /** @param array<string, mixed> $data */
    public function applyPayment(ClientInvoice $invoice, array $data, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $workspace): ClientInvoicePayment {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status === 'void') {
                throw new DomainException('A payment cannot be applied to a void invoice.');
            }
            if ($locked->status === 'draft') {
                throw new DomainException('A draft invoice must be issued before accepting payment.');
            }

            $currency = MoneyService::currency($data['currency'] ?? null);
            if ($currency !== $locked->currency) {
                throw new DomainException('Payment currency must match the invoice currency.');
            }

            $amount = MoneyService::nonNegativeInteger($data['amount'] ?? null, 'amount');
            if ($amount === 0) {
                throw new DomainException('Payment amount must be greater than zero.');
            }
            if (($data['status'] ?? 'succeeded') === 'succeeded' && $amount > $locked->balance_amount) {
                throw new DomainException('Payment cannot exceed the invoice balance.');
            }

            $key = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;
            if ($key !== null && $key !== '') {
                $existing = ClientInvoicePayment::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing !== null) {
                    if ($existing->client_invoice_id !== $locked->id
                        || $existing->amount !== $amount
                        || $existing->currency !== $currency
                        || $existing->method !== ($data['method'] ?? null)
                        || $existing->status !== ($data['status'] ?? 'succeeded')) {
                        throw new DomainException('The idempotency key is already bound to a different payment.');
                    }

                    return $existing;
                }
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'status' => $data['status'] ?? 'succeeded',
                'amount' => $amount,
                'refunded_amount' => 0,
                'currency' => $currency,
                'received_on' => $data['received_on'] ?? now()->toDateString(),
                'method' => $this->requiredString($data['method'] ?? null, 'method'),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'provider' => $data['provider'] ?? null,
                'provider_payment_identifier' => $data['provider_payment_identifier'] ?? null,
                'external_finance_transaction_uuid' => $data['external_finance_transaction_uuid'] ?? null,
                'idempotency_key' => $key,
            ]);

            $this->refreshStatus($locked);

            return $payment->fresh();
        });
    }

    public function setPaymentStatus(ClientInvoicePayment $payment, string $status, ?Workspace $workspace = null): ClientInvoicePayment
    {
        if (! in_array($status, ['pending', 'succeeded', 'failed', 'refunded', 'disputed'], true)) {
            throw new DomainException('Unsupported payment status.');
        }

        return DB::transaction(function () use ($payment, $status, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->whereKey($payment->id)->lockForUpdate();
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }
            $lockedPayment = $query->firstOrFail();
            $invoice = $this->lockInvoice($lockedPayment->invoice, $workspace);
            if ($status === 'succeeded' && $invoice->status === 'void') {
                throw new DomainException('A payment succeeded against a void invoice; refund it or un-void the invoice before recording it.');
            }
            if ($status === 'succeeded') {
                $otherPaid = (int) $invoice->payments()
                    ->where('id', '!=', $lockedPayment->id)
                    ->where('status', 'succeeded')
                    ->get(['amount', 'refunded_amount'])
                    ->sum(fn (ClientInvoicePayment $other): int => max(0, $other->amount - $other->refunded_amount));
                if (($lockedPayment->amount - $lockedPayment->refunded_amount) > ($invoice->total_amount - $otherPaid)) {
                    throw new DomainException('Successful payments cannot exceed the invoice total.');
                }
            }
            $lockedPayment->forceFill([
                'status' => $status,
                'refunded_amount' => $status === 'refunded'
                    ? $lockedPayment->amount
                    : $lockedPayment->refunded_amount,
            ])->save();
            $this->refreshStatus($invoice);

            return $lockedPayment->fresh();
        });
    }

    public function setRefundedAmount(ClientInvoicePayment $payment, int $amount, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($payment, $amount, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->whereKey($payment->id)->lockForUpdate();
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }

            $lockedPayment = $query->firstOrFail();
            if (! in_array($lockedPayment->status, ['succeeded', 'refunded'], true)) {
                throw new DomainException('Only a successful payment can be refunded.');
            }
            if ($amount < 0 || $amount > $lockedPayment->amount) {
                throw new DomainException('Refunded amount must be between zero and the payment amount.');
            }

            $invoice = $this->lockInvoice($lockedPayment->invoice, $workspace);
            $lockedPayment->forceFill([
                'refunded_amount' => $amount,
                'status' => $amount === $lockedPayment->amount ? 'refunded' : 'succeeded',
            ])->save();
            $this->refreshStatus($invoice);

            return $lockedPayment->fresh();
        });
    }

    public function refreshStatus(ClientInvoice $invoice): ClientInvoice
    {
        $paid = (int) $invoice->payments()
            ->where('status', 'succeeded')
            ->get(['amount', 'refunded_amount'])
            ->sum(fn (ClientInvoicePayment $payment): int => max(0, $payment->amount - $payment->refunded_amount));
        $paid = min($paid, (int) $invoice->total_amount);
        $balance = max(0, (int) $invoice->total_amount - $paid);
        $status = $invoice->status === 'void'
            ? 'void'
            : ($paid >= (int) $invoice->total_amount ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued'));

        $invoice->forceFill([
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'status' => $status,
        ])->save();

        return $invoice->fresh();
    }

    public function assertTenant(Workspace $workspace, ClientInvoice $invoice): void
    {
        if ($invoice->workspace_id !== $workspace->id) {
            throw (new ModelNotFoundException)->setModel(ClientInvoice::class, [$invoice->id]);
        }
    }

    /** @param array<string, mixed> $line */
    public static function lineTotal(array $line): int
    {
        $totals = MoneyService::invoiceTotals([$line]);

        return $totals['subtotal_amount'] + $totals['tax_amount'];
    }

    private function lockInvoice(ClientInvoice $invoice, ?Workspace $workspace): ClientInvoice
    {
        $query = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate();
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        return $query->firstOrFail();
    }

    private function assertCompanyTenant(Workspace $workspace, ClientCompany $company): void
    {
        if ($company->workspace_id !== $workspace->id) {
            throw (new ModelNotFoundException)->setModel(ClientCompany::class, [$company->id]);
        }
    }

    private function requiredString(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("{$name} is required.");
        }

        return trim($value);
    }

    private function nonNegativeSortOrder(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new DomainException('sort_order must be a non-negative integer.');
    }
}
