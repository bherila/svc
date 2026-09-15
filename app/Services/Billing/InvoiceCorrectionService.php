<?php

namespace App\Services\Billing;

use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceAdministratorNotification;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A bounded, audited correction of an unpaid and never-delivered invoice. */
final class InvoiceCorrectionService
{
    public function __construct(
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Existing line identities and allocations are preserved. Money may be
     * changed only on operator-authored lines; generated/allocated lines may
     * receive a wording correction but retain their accounting facts.
     *
     * @param  list<array{id:string,description:string,quantity:string,unit_amount:int,tax_amount:int}>  $lines
     */
    public function correct(
        ClientInvoice $invoice,
        Workspace $workspace,
        int $expectedRevision,
        ?string $dueDate,
        string $reason,
        array $lines,
    ): ClientInvoice {
        return DB::transaction(function () use ($invoice, $workspace, $expectedRevision, $dueDate, $reason, $lines): ClientInvoice {
            $locked = ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($invoice->id)
                ->tap(Locks::forUpdate())
                ->with(['workspace', 'clientCompany'])
                ->firstOrFail();

            if ($locked->status !== InvoiceStatus::Issued->value || $locked->paid_amount > 0) {
                throw new DomainException('Only an unpaid issued invoice can be corrected. Paid, partially paid, draft, and void invoices are unchanged.');
            }
            if ($locked->document_revision !== $expectedRevision) {
                throw new DomainException('The invoice changed after this correction form was opened. Reload it and try again.');
            }
            if ($locked->payments()->where('workspace_id', $workspace->id)->exists()) {
                throw new DomainException('Resolve or remove the recorded payment attempt before correcting this invoice.');
            }
            if ($locked->emailDeliveries()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', ['pending', 'sending', 'sent'])
                ->exists()) {
                throw new DomainException('An invoice already sent, or currently being sent, cannot be corrected in place. Void and reissue it through the accounting workflow instead.');
            }

            $originalNotification = ClientInvoiceAdministratorNotification::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $locked->id)
                ->where('invoice_revision', 1)
                ->first();
            if (! $originalNotification instanceof ClientInvoiceAdministratorNotification
                || $originalNotification->pdf_content_base64 === null) {
                throw new DomainException('The originally issued PDF is not available, so an audited in-place correction cannot preserve its evidence.');
            }

            $existing = ClientInvoiceLine::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $locked->id)
                ->orderBy('id')
                ->tap(Locks::forUpdate())
                ->get()
                ->keyBy('public_id');
            $submittedIds = collect($lines)->pluck('id')->sort()->values()->all();
            $existingIds = $existing->keys()->sort()->values()->all();
            if ($submittedIds !== $existingIds) {
                throw new DomainException('A correction must preserve every existing invoice line; lines cannot be added or removed after issue.');
            }

            $lineIds = array_values($existing->values()
                ->map(fn (ClientInvoiceLine $line): int => $line->id)
                ->all());
            $allocated = $this->allocatedLineIds($lineIds, $workspace);

            $changes = [];
            foreach ($lines as $submitted) {
                $line = $existing->get($submitted['id']);
                if (! $line instanceof ClientInvoiceLine) {
                    throw new DomainException('One or more invoice lines no longer exist.');
                }

                $moneyChanged = $this->normalizedQuantity((string) $line->quantity)
                    !== $this->normalizedQuantity((string) $submitted['quantity'])
                    || (int) $line->unit_amount !== (int) $submitted['unit_amount']
                    || (int) $line->tax_amount !== (int) $submitted['tax_amount'];
                $operatorAuthored = $this->isMoneyCorrectable($line, $allocated);
                if ($moneyChanged && ! $operatorAuthored) {
                    throw new DomainException('Generated and allocated invoice lines may only have their description corrected. Void and regenerate the invoice to change their accounting values.');
                }

                $descriptionChanged = $line->description !== trim($submitted['description']);
                if (! $moneyChanged && ! $descriptionChanged) {
                    continue;
                }

                $before = [
                    'quantity' => (string) $line->quantity,
                    'unit_amount' => (int) $line->unit_amount,
                    'tax_amount' => (int) $line->tax_amount,
                ];
                $quantity = (string) $submitted['quantity'];
                $unit = (int) $submitted['unit_amount'];
                $tax = (int) $submitted['tax_amount'];
                $line->forceFill([
                    'description' => trim($submitted['description']),
                    'quantity' => $quantity,
                    'unit_amount' => $unit,
                    'tax_amount' => $tax,
                    'total_amount' => InvoiceLifecycleService::lineTotal([
                        'quantity' => $quantity,
                        'unit_amount' => $unit,
                        'tax_amount' => $tax,
                    ]),
                ])->save();
                $changes[] = [
                    'line' => $line->public_id,
                    'description_changed' => $descriptionChanged,
                    'before' => $before,
                    'after' => [
                        'quantity' => $quantity,
                        'unit_amount' => $unit,
                        'tax_amount' => $tax,
                    ],
                ];
            }

            $parsedDueDate = $dueDate === null ? null : CarbonImmutable::parse($dueDate)->startOfDay();
            if ($parsedDueDate !== null && $locked->issue_date !== null && $parsedDueDate->lt($locked->issue_date)) {
                throw new DomainException('The corrected due date cannot precede the issue date.');
            }
            $dueChanged = $locked->due_date?->toDateString() !== $parsedDueDate?->toDateString();
            if ($changes === [] && ! $dueChanged) {
                throw new DomainException('The correction does not change the invoice.');
            }

            $locked->refresh();
            $locked->recalculateTotals();
            $locked->forceFill([
                'due_date' => $parsedDueDate,
                'document_revision' => $locked->document_revision + 1,
                'automatic_delivery_status' => $locked->automatic_delivery_status === null
                    ? null
                    : 'held',
                'automatic_delivery_held_at' => $locked->automatic_delivery_status === null
                    ? null
                    : $this->clock->now($workspace)->utc(),
                'automatic_delivery_note' => $locked->automatic_delivery_status === null
                    ? null
                    : 'Held after an invoice correction; release explicitly after review.',
            ])->save();

            $this->activities->record(
                $workspace,
                $locked->clientCompany,
                'invoice.corrected',
                $locked,
                [
                    'from_revision' => $expectedRevision,
                    'to_revision' => $locked->document_revision,
                    'reason' => trim($reason),
                    'due_date_changed' => $dueChanged,
                    'lines' => $changes,
                ],
                occurrence: (string) Str::uuid(),
            );

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    public function hold(ClientInvoice $invoice, Workspace $workspace): ClientInvoice
    {
        return $this->setHold($invoice, $workspace, true);
    }

    public function release(ClientInvoice $invoice, Workspace $workspace): ClientInvoice
    {
        return $this->setHold($invoice, $workspace, false);
    }

    /**
     * Finished correction capabilities for the invoice page.
     *
     * @return list<string> public line ids
     */
    public function moneyCorrectableLineIds(ClientInvoice $invoice, Workspace $workspace): array
    {
        $lines = ClientInvoiceLine::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->get();
        $lineIds = array_values($lines->map(fn (ClientInvoiceLine $line): int => $line->id)->all());
        $allocated = $this->allocatedLineIds($lineIds, $workspace);

        return array_values($lines
            ->filter(fn (ClientInvoiceLine $line): bool => $this->isMoneyCorrectable($line, $allocated))
            ->map(fn (ClientInvoiceLine $line): string => $line->public_id)
            ->values()
            ->all());
    }

    private function setHold(ClientInvoice $invoice, Workspace $workspace, bool $hold): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $hold): ClientInvoice {
            $locked = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)
                ->tap(Locks::forUpdate())->with(['workspace', 'clientCompany'])->firstOrFail();
            if ($locked->status !== InvoiceStatus::Issued->value || $locked->automatic_delivery_due_at === null) {
                throw new DomainException('This invoice has no active automatic delivery to change.');
            }
            if ($hold && ! in_array($locked->automatic_delivery_status, ['scheduled', 'failed'], true)) {
                throw new DomainException('Only a scheduled or failed automatic delivery can be held.');
            }
            if (! $hold && $locked->automatic_delivery_status !== 'held') {
                throw new DomainException('Only a held automatic delivery can be released.');
            }

            $now = $this->clock->now($workspace)->utc();
            $locked->forceFill([
                'automatic_delivery_status' => $hold ? 'held' : 'scheduled',
                'automatic_delivery_held_at' => $hold ? $now : null,
                'automatic_delivery_due_at' => $hold
                    ? $locked->automatic_delivery_due_at
                    : ($locked->automatic_delivery_due_at->isPast()
                        ? $now
                        : $locked->automatic_delivery_due_at),
                'automatic_delivery_note' => $hold
                    ? 'Held by an administrator.'
                    : 'Released by an administrator after review.',
            ])->save();

            $this->activities->record(
                $workspace,
                $locked->clientCompany,
                $hold ? 'invoice.automatic_delivery_held' : 'invoice.automatic_delivery_released',
                $locked,
                ['revision' => $locked->document_revision],
                occurrence: (string) Str::uuid(),
            );

            return $locked;
        });
    }

    private function normalizedQuantity(string $quantity): string
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    /** @param list<int> $lineIds
     * @return list<int>
     */
    private function allocatedLineIds(array $lineIds, Workspace $workspace): array
    {
        $time = DB::table('client_invoice_line_time_entries')
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_line_id', $lineIds)
            ->pluck('client_invoice_line_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $expenses = ClientExpense::withTrashed()->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_line_id', $lineIds)
            ->pluck('client_invoice_line_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $tasks = ClientTask::query()->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_line_id', $lineIds)
            ->pluck('client_invoice_line_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$time, ...$expenses, ...$tasks]));
    }

    /** @param list<int> $allocated */
    private function isMoneyCorrectable(ClientInvoiceLine $line, array $allocated): bool
    {
        return ! in_array((string) $line->type, InvoiceLineType::systemGeneratedValues(), true)
            && (string) $line->type !== InvoiceLineType::Credit->value
            && ! in_array($line->id, $allocated, true);
    }
}
