<?php

namespace App\Support\Billing;

use App\Services\Billing\FirstCycleChargeAuditor;

/**
 * One agreement whose recurring items the engine used to charge twice in its
 * first billing period (#685), and what that period's invoice actually holds.
 *
 * The shape: a quarterly, semi-annual or annual agreement started on a day
 * other than the 1st, with an item on that cadence and no anchor of its own.
 * Such an item was anchored on the 1st of its start month with a first charge
 * on its start date, so the period anchored on the start held two charges.
 * The engine now charges it once per period, on the period's first day; this
 * reads what was already written. {@see FirstCycleChargeAuditor} loads the
 * facts; the reading is here, database-free.
 */
final readonly class FirstCycleChargeFinding
{
    public function __construct(
        public string $workspace,
        public string $company,
        public string $agreement,
        public string $cadence,
        public string $startsOn,
        /** Signed up for, or moved from, through a service package. */
        public bool $package,
        /** The agreement's items placed on its billing periods. */
        public int $items,
        public FirstCycleChargeOutcome $outcome,
        public ?string $invoice,
        public ?string $invoiceStatus,
        public ?string $currency,
        /** Lines beyond each item's first on the first period's invoice. */
        public int $duplicateLines,
        /** What those lines charge, in the invoice's minor units. */
        public int $overchargeMinor,
    ) {}

    /**
     * @param  array{workspace: string, company: string, agreement: string, cadence: string, starts_on: string, package: bool, items: int}  $agreement
     * @param  array{public_id: string, status: string, currency: string}|null  $invoice  the first period's earliest non-void invoice
     * @param  list<array{item: int, line_date: string, id: int, total: int}>  $lines  that invoice's lines for the items placed on the periods
     */
    public static function classify(array $agreement, ?array $invoice, array $lines): self
    {
        usort($lines, static fn (array $a, array $b): int => [$a['line_date'], $a['id']] <=> [$b['line_date'], $b['id']]);
        $seen = [];
        $duplicates = 0;
        $overcharge = 0;
        foreach ($lines as $line) {
            if (isset($seen[$line['item']])) {
                $duplicates++;
                $overcharge += $line['total'];
            }
            $seen[$line['item']] = true;
        }

        if ($invoice === null) {
            $outcome = FirstCycleChargeOutcome::NotInvoiced;
        } elseif ($duplicates === 0) {
            $outcome = FirstCycleChargeOutcome::ChargedOnce;
        } elseif ($invoice['status'] === InvoiceStatus::Draft->value) {
            $outcome = FirstCycleChargeOutcome::DraftWithDuplicate;
        } else {
            $outcome = FirstCycleChargeOutcome::IssuedWithDuplicate;
        }

        return new self(
            $agreement['workspace'],
            $agreement['company'],
            $agreement['agreement'],
            $agreement['cadence'],
            $agreement['starts_on'],
            $agreement['package'],
            $agreement['items'],
            $outcome,
            $invoice['public_id'] ?? null,
            $invoice['status'] ?? null,
            $invoice['currency'] ?? null,
            $duplicates,
            $overcharge,
        );
    }

    /** @return array{workspace: string, company: string, agreement: string, cadence: string, starts_on: string, package: bool, items: int, outcome: string, invoice: string|null, invoice_status: string|null, currency: string|null, duplicate_lines: int, overcharge_minor: int} */
    public function toArray(): array
    {
        return [
            'workspace' => $this->workspace,
            'company' => $this->company,
            'agreement' => $this->agreement,
            'cadence' => $this->cadence,
            'starts_on' => $this->startsOn,
            'package' => $this->package,
            'items' => $this->items,
            'outcome' => $this->outcome->value,
            'invoice' => $this->invoice,
            'invoice_status' => $this->invoiceStatus,
            'currency' => $this->currency,
            'duplicate_lines' => $this->duplicateLines,
            'overcharge_minor' => $this->overchargeMinor,
        ];
    }
}
