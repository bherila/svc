<?php

namespace App\Services\Accounting;

use App\Models\AccountingInvoiceEntry;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Accounting\InvoiceLineFacts;

/** Appends under the invoice lock in the same transaction as the document change. */
final class InvoiceAccountingJournal
{
    /** Seed pre-existing documents, reconstructing original values from correction evidence. */
    public function ensure(ClientInvoice $invoice): void
    {
        if ($invoice->status === 'draft' && $invoice->issued_at === null) {
            return;
        }
        if (AccountingInvoiceEntry::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->exists()) {
            return;
        }
        $lines = $this->facts($invoice);
        $corrections = ClientInvoiceCorrection::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->orderBy('to_revision')->get();
        foreach ($corrections->reverse() as $correction) {
            foreach ($correction->line_diffs as $diff) {
                $id = (string) $diff['line'];
                if (isset($lines[$id]) && is_array($diff['before'])) {
                    $lines[$id] = $this->corrected($lines[$id], $diff['before']);
                }
            }
        }
        foreach ($lines as $id => $facts) {
            $this->append($invoice, 'original:'.$id, $facts, $invoice->issued_at ?? $invoice->created_at);
        }
        foreach ($corrections as $correction) {
            $this->correction($invoice, $correction);
        }
        if ($invoice->voided_at !== null) {
            $this->reverse($invoice, 'void', 'historical-void');
        }
    }

    public function issued(ClientInvoice $invoice): void
    {
        foreach ($this->facts($invoice) as $id => $facts) {
            $this->append($invoice, 'issue:'.$invoice->document_revision.':'.$id, $facts);
        }
    }

    public function correction(ClientInvoice $invoice, ClientInvoiceCorrection $correction): void
    {
        foreach ($correction->line_diffs as $diff) {
            $id = (string) $diff['line'];
            $previous = $this->current($invoice, $id);
            if ($previous === null || ! is_array($diff['after'])) {
                continue;
            }
            $facts = new InvoiceLineFacts($previous->payload);
            $this->append($invoice, 'correction:'.$correction->public_id.':reverse:'.$id, $facts->reverse('correction_reversal', $previous->public_id), $correction->created_at);
            $next = $this->corrected($facts, $diff['after']);
            $this->append($invoice, 'correction:'.$correction->public_id.':replace:'.$id,
                new InvoiceLineFacts([...$next->data, 'kind' => 'correction', 'reverses_id' => null]), $correction->created_at);
        }
    }

    /** Void or reopen reverses the most recent positive snapshot of every line. */
    public function reverse(ClientInvoice $invoice, string $kind, string $occurrence): void
    {
        $entries = AccountingInvoiceEntry::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->orderBy('id')->get();
        $current = [];
        foreach ($entries as $entry) {
            $line = (string) $entry->payload['line_id'];
            if ($entry->payload['reverses_id'] !== null) {
                unset($current[$line]);
            } else {
                $current[$line] = $entry;
            }
        }
        foreach ($current as $line => $entry) {
            $this->append($invoice, $kind.':'.$occurrence.':'.$line, (new InvoiceLineFacts($entry->payload))->reverse($kind, $entry->public_id));
        }
    }

    /** @return array<string, InvoiceLineFacts> */
    private function facts(ClientInvoice $invoice): array
    {
        $client = ClientCompany::query()->where('workspace_id', $invoice->workspace_id)->whereKey($invoice->client_company_id)->firstOrFail();
        $lines = ClientInvoiceLine::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->get();
        $projects = ClientProject::query()->where('workspace_id', $invoice->workspace_id)->where('client_company_id', $client->id)->whereIn('id', $lines->pluck('client_project_id')->filter())->pluck('public_id', 'id');
        $facts = [];
        foreach ($lines as $line) {
            $tax = (int) $line->tax_amount;
            $net = (int) $line->total_amount - $tax;
            $facts[$line->public_id] = new InvoiceLineFacts([
                'invoice_id' => $invoice->public_id, 'line_id' => $line->public_id,
                'client_id' => $client->public_id, 'project_id' => $projects[$line->client_project_id] ?? null,
                'kind' => 'issued', 'reverses_id' => null, 'currency' => $invoice->currency,
                'line_type' => $line->type, 'line_type_label' => $this->label($line->type),
                'description' => $line->description, 'quantity' => (string) $line->quantity, 'unit_amount' => (int) $line->unit_amount, 'net_amount' => $net, 'tax_amount' => $tax,
                'total_amount' => (int) $line->total_amount,
                // No tax policy is stored per line. Null rate distinguishes unknown from zero.
                'tax_rate' => $net === 0 ? null : round(abs($tax / $net) * 100, 6),
                'taxable' => $tax !== 0,
                'service_period_start' => $invoice->service_period_start?->toDateString(),
                'service_period_end' => $invoice->service_period_end?->toDateString(),
                'effective_on' => $invoice->issue_date?->toDateString(),
            ]);
        }

        return $facts;
    }

    /** @param array<string, mixed> $change */
    private function corrected(InvoiceLineFacts $facts, array $change): InvoiceLineFacts
    {
        $tax = (int) $change['tax_amount'];
        $moneyChanged = (string) $change['quantity'] !== (string) $facts->data['quantity']
            || (int) $change['unit_amount'] !== (int) $facts->data['unit_amount']
            || $tax !== (int) $facts->data['tax_amount'];
        $total = $moneyChanged ? InvoiceLifecycleService::lineTotal($change) : (int) $facts->data['total_amount'];
        $net = $total - $tax;

        return new InvoiceLineFacts([...$facts->data, 'description' => (string) $change['description'],
            'quantity' => (string) $change['quantity'], 'unit_amount' => (int) $change['unit_amount'],
            'net_amount' => $net, 'tax_amount' => $tax, 'total_amount' => $total,
            'tax_rate' => $net === 0 ? null : round(abs($tax / $net) * 100, 6), 'taxable' => $tax !== 0]);
    }

    private function current(ClientInvoice $invoice, string $line): ?AccountingInvoiceEntry
    {
        return AccountingInvoiceEntry::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)
            ->where('payload->line_id', $line)->whereNull('payload->reverses_id')->latest('id')->first();
    }

    private function append(ClientInvoice $invoice, string $key, InvoiceLineFacts $facts, ?\DateTimeInterface $at = null): void
    {
        AccountingInvoiceEntry::query()->firstOrCreate(
            ['workspace_id' => $invoice->workspace_id, 'source_key' => $invoice->public_id.':'.$key],
            ['client_invoice_id' => $invoice->id, 'effective_on' => $facts->data['effective_on'], 'payload' => $facts->data, ...($at === null ? [] : ['created_at' => $at, 'updated_at' => $at])],
        );
    }

    private function label(string $type): string
    {
        // The UI uses the same readable labels; unknown imported values stay readable.
        $words = trim((string) preg_replace('/[_-]+/', ' ', $type));

        return $words === '' ? '—' : ucfirst($words);
    }
}
