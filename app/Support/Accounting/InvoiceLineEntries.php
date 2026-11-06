<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * The entries of the invoice-line feed, built from facts alone: no database,
 * no clock.
 *
 * An issued invoice line is one `invoice_line` entry, as it was issued. A
 * correction made after issue never edits that entry: it is a new
 * `correction` entry whose signed amounts are the change the correction made,
 * and a void is a new `void` entry for each line whose amounts are the line's
 * negated, so the entries of one line always add up to what the line stands
 * for now. Both name the `invoice_line` entry they reverse in
 * `reverses_entry_id`.
 *
 * Amounts are signed integer minor units. Net is the line before its own tax.
 */
final class InvoiceLineEntries
{
    /** Namespaces the stable ids of correction and void entries, which no stored row carries. */
    private const string ID_NAMESPACE = '6d1f6a0e-3c52-4b8e-9a47-0f2d5c9b7e14';

    public static function issued(InvoiceFacts $invoice, LineFacts $line, FeedPosition $position): FeedEntry
    {
        return self::entry(
            $position, $invoice, $line, LineEntryType::InvoiceLine, $line->id,
            $invoice->issueDate ?? self::recordedOn($position, $invoice->timezone),
            $line->netAmount(), $line->taxAmount, $line->totalAmount,
            $line->taxRate(), $line->taxable(), null,
        );
    }

    /**
     * What one line diff of an audited in-place correction did to the line's
     * money, or null when it changed no money (a wording-only diff, or one
     * this cannot read).
     *
     * @param  array<array-key, mixed>  $diff  one `line_diffs` element of the correction record
     * @param  LineFacts  $current  the line as it stands now
     */
    public static function corrected(InvoiceFacts $invoice, LineFacts $current, array $diff, string $correctionId, FeedPosition $position): ?FeedEntry
    {
        $before = self::state($current, $diff['before'] ?? null);
        $after = self::state($current, $diff['after'] ?? null);
        if ($before === null || $after === null || ! $before->moneyDiffersFrom($after)) {
            return null;
        }

        return self::entry(
            $position, $invoice, $after, LineEntryType::Correction, self::derivedId('correction', $correctionId, $current->id),
            self::recordedOn($position, $invoice->timezone),
            $after->netAmount() - $before->netAmount(), $after->taxAmount - $before->taxAmount, $after->totalAmount - $before->totalAmount,
            $after->taxRate(), $before->taxable() || $after->taxable(), $correctionId,
        );
    }

    /** The reversal of one line of a voided invoice, in the amounts the line stands for now. */
    public static function voided(InvoiceFacts $invoice, LineFacts $line, FeedPosition $position): FeedEntry
    {
        return self::entry(
            $position, $invoice, $line, LineEntryType::Void, self::derivedId('void', $invoice->id, $line->id),
            self::recordedOn($position, $invoice->timezone),
            -$line->netAmount(), -$line->taxAmount, -$line->totalAmount,
            $line->taxRate(), $line->taxable(), null,
        );
    }

    /**
     * The line as it was issued: its current facts with every correction
     * rolled back, the oldest correction's "before" standing for the issue.
     *
     * @param  list<array<array-key, mixed>>  $diffs  this line's diffs, oldest correction first
     */
    public static function asIssued(LineFacts $current, array $diffs): LineFacts
    {
        $issued = $current;
        $worded = false;
        $priced = false;
        foreach ($diffs as $diff) {
            $before = self::state($current, $diff['before'] ?? null);
            $after = self::state($current, $diff['after'] ?? null);
            if ($before === null || $after === null) {
                continue;
            }
            if (! $worded) {
                $issued = $issued->withDescription($before->description);
                $worded = true;
            }
            if (! $priced && $before->moneyDiffersFrom($after)) {
                $issued = $issued->restated($issued->description, $before->quantity, $before->unitAmount, $before->taxAmount);
                $priced = true;
            }
        }

        return $issued;
    }

    /** The workspace calendar date of the instant a position was recorded. */
    public static function recordedOn(FeedPosition $position, string $timezone): string
    {
        return CarbonImmutable::parse($position->at, 'UTC')->setTimezone($timezone)->toDateString();
    }

    /**
     * The line as one side of a diff states it: the current line with that
     * side's wording, quantity, unit amount and tax. The current total stands
     * when the money is the current money, which keeps a credit's signed total
     * through a wording-only correction. Null for a state this cannot read.
     */
    private static function state(LineFacts $current, mixed $state): ?LineFacts
    {
        if (! is_array($state)) {
            return null;
        }
        $description = $state['description'] ?? null;
        $quantity = $state['quantity'] ?? null;
        $unitAmount = $state['unit_amount'] ?? null;
        $taxAmount = $state['tax_amount'] ?? null;
        if (! is_string($description) || ! is_string($quantity) || ! is_int($unitAmount) || ! is_int($taxAmount)) {
            return null;
        }
        if ($unitAmount === $current->unitAmount && $taxAmount === $current->taxAmount && is_numeric($quantity) && (float) $quantity === (float) $current->quantity) {
            return $current->withDescription($description);
        }
        try {
            return $current->restated($description, $quantity, $unitAmount, $taxAmount);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function derivedId(string $kind, string $recordId, string $lineId): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, $kind.':'.$recordId.':'.$lineId)->toString();
    }

    private static function entry(
        FeedPosition $position,
        InvoiceFacts $invoice,
        LineFacts $line,
        LineEntryType $type,
        string $id,
        string $effectiveOn,
        int $net,
        int $tax,
        int $total,
        ?string $taxRate,
        bool $taxable,
        ?string $correctionId,
    ): FeedEntry {
        return new FeedEntry($position, [
            'id' => $id,
            'entry_type' => $type->value,
            'entry_type_label' => $type->label(),
            'recorded_at' => CarbonImmutable::parse($position->at, 'UTC')->toIso8601String(),
            'effective_on' => $effectiveOn,
            'reverses_entry_id' => $type === LineEntryType::InvoiceLine ? null : $line->id,
            'correction_id' => $correctionId,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'currency' => $invoice->currency,
            'client_id' => $invoice->clientId,
            'project_id' => $line->projectId,
            'line_id' => $line->id,
            'line_type' => $line->type,
            'line_type_label' => StoredValueLabel::of($line->type),
            'description' => $line->description,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'tax_rate' => $taxRate,
            'taxable' => $taxable,
            'service_period_start' => $invoice->servicePeriodStart,
            'service_period_end' => $invoice->servicePeriodEnd,
        ]);
    }
}
