<?php

namespace App\Support\Billing;

use App\Services\Billing\FirstCycleChargeAuditor;
use App\Services\Billing\RecurringItemBiller;

/**
 * One agreement whose recurring items the engine used to charge an extra
 * time in their first periods (#685, #695), and what those periods' invoices
 * actually hold.
 *
 * The shape: a quarterly, semi-annual or annual agreement started on a day
 * other than the 1st, with a recurring item and no anchor of its own. Such an
 * item was anchored on the 1st of its start month with a first charge on its
 * start date, so the first period of its own cadence held two charges: a
 * quarterly item in a quarter from 9 January on 9 January and 1 April, a
 * monthly one on 9 January, 1 February, 1 March and 1 April, an annual one on
 * 9 January and again on 1 January. The engine now places it on the
 * agreement's periods ({@see RecurringItemBiller::followsBillingCycles()}); this
 * reads what was already written against what it places now, invoice by
 * invoice. {@see FirstCycleChargeAuditor} loads the facts; the reading is
 * here, database-free.
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
        /** The invoice holding the extra charge, or with none the first period's. */
        public ?string $invoice,
        public ?string $invoiceStatus,
        public ?string $currency,
        /** Lines beyond the charges the engine places in their invoice's period. */
        public int $duplicateLines,
        /** What those lines charge, in the invoice's minor units. */
        public int $overchargeMinor,
    ) {}

    /**
     * Each invoice's lines for an item beyond the number of charges the
     * engine places for it in that invoice's period are extra: the latest of
     * them, by date and then by row. Extra lines on an issued invoice make the
     * outcome issued, and only those are counted and summed; with none, extra
     * lines on a draft make it a draft's.
     *
     * @param  array{workspace: string, company: string, agreement: string, cadence: string, starts_on: string, package: bool, items: int}  $agreement
     * @param  list<array{public_id: string, status: string, currency: string, expected: array<int, int>, lines: list<array{item: int, line_date: string, id: int, total: int}>}>  $invoices  the earliest live invoice of each period the items' first own periods span, by period; `expected`: the charges the engine places per item in that period; `lines`: the invoice's lines for those items
     */
    public static function classify(array $agreement, array $invoices): self
    {
        $lineCount = ['issued' => 0, 'draft' => 0];
        $minor = ['issued' => 0, 'draft' => 0];
        $holding = [];
        foreach ($invoices as $invoice) {
            $lines = $invoice['lines'];
            usort($lines, static fn (array $a, array $b): int => [$a['line_date'], $a['id']] <=> [$b['line_date'], $b['id']]);
            $seen = [];
            $kind = $invoice['status'] === InvoiceStatus::Draft->value ? 'draft' : 'issued';
            foreach ($lines as $line) {
                $seen[$line['item']] = ($seen[$line['item']] ?? 0) + 1;
                if ($seen[$line['item']] > ($invoice['expected'][$line['item']] ?? 0)) {
                    $lineCount[$kind]++;
                    $minor[$kind] += $line['total'];
                    $holding[$kind] ??= $invoice;
                }
            }
        }

        $kind = $lineCount['issued'] > 0 ? 'issued' : ($lineCount['draft'] > 0 ? 'draft' : null);
        if ($invoices === []) {
            $outcome = FirstCycleChargeOutcome::NotInvoiced;
        } elseif ($kind === 'issued') {
            $outcome = FirstCycleChargeOutcome::IssuedWithDuplicate;
        } elseif ($kind === 'draft') {
            $outcome = FirstCycleChargeOutcome::DraftWithDuplicate;
        } else {
            $outcome = FirstCycleChargeOutcome::ChargedOnce;
        }
        $named = $kind === null ? ($invoices[0] ?? null) : ($holding[$kind] ?? null);
        $duplicates = $kind === null ? 0 : $lineCount[$kind];
        $overcharge = $kind === null ? 0 : $minor[$kind];

        return new self(
            $agreement['workspace'],
            $agreement['company'],
            $agreement['agreement'],
            $agreement['cadence'],
            $agreement['starts_on'],
            $agreement['package'],
            $agreement['items'],
            $outcome,
            $named['public_id'] ?? null,
            $named['status'] ?? null,
            $named['currency'] ?? null,
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
