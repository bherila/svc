<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\BillingPeriod;
use App\Support\Billing\PeriodClaimVerdict;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class BillingScheduleService
{
    public function __construct(
        private readonly InvoiceLifecycleService $invoices,
        private readonly BillingPeriodCollisionResolver $collisions,
    ) {}

    /** @return list<ClientInvoice> */
    public function generateDue(ClientBillingSchedule $schedule, CarbonImmutable $through): array
    {
        return DB::transaction(function () use ($schedule, $through): array {
            $locked = ClientBillingSchedule::query()->whereKey($schedule->id)->tap(Locks::forUpdate())->firstOrFail();
            if (! $locked->is_active) {
                return [];
            }

            $created = [];
            $nextRun = CarbonImmutable::parse((string) $locked->next_run_on);
            $template = $this->normalizedTemplate($locked->getAttribute('line_template'));
            while ($nextRun->lte($through)) {
                $period = BillingPeriod::beginningAt($nextRun, (string) $locked->cadence);
                [$start, $end] = [$period->start, $period->end];

                // Whether this period is already covered, and by whose invoice,
                // is decided by `BillingPeriodCollisionResolver`. It used to be
                // one nested `where` closure here, and three reviews each found
                // a real defect inside it; the reasoning is long enough to need
                // its own class and its own tests per branch.
                $claim = $this->collisions->resolve($locked, $start, $end);

                // A refusal rolls the whole transaction back, including periods
                // already created earlier in this loop. That is deliberate:
                // `createDraft()` and `issue()` each mutate invoices,
                // activities and time entries, so a partial run would leave
                // some of a schedule's periods billed and its `next_run_on`
                // pointing into the middle of the batch. All-or-nothing is
                // recoverable by re-running once the named row is repaired;
                // half-applied is not. Doing the classification for every
                // period up front, before creating anything, would avoid the
                // wasted work - #252.
                if ($claim->verdict === PeriodClaimVerdict::Refused) {
                    throw new DomainException($claim->refusal());
                }

                // A draft covering exactly this period has *claimed* it without
                // *billing* it, and treating those as the same fact is how a
                // period gets silently skipped. Reporting the draft as already
                // billed advanced the cursor past a period no money had been
                // asked for, and nothing brought it back:
                // `InvoiceLifecycleService::discardDraft()` turns the draft into
                // a void invoice that keeps its period, so even rewinding
                // `next_run_on` met an exact void and honoured it as a waiver.
                //
                // Issuing it here instead is not available either - the draft
                // may be another generator's, mid-review, and issuing is the
                // act of asking a client for money. So the schedule stops and
                // names it. Issue the draft and the next run advances normally;
                // void it deliberately and the waiver is honoured.
                //
                // Safe advice only because the resolver reaches this verdict
                // only when the draft is the sole claim on the period. A draft
                // beside an invoice that already covers the period exactly
                // refuses as a conflict instead, and says to remove the
                // duplicate - `issue()` runs no overlap check, so "issue that
                // draft" there would charge the client twice.
                if ($claim->verdict === PeriodClaimVerdict::PendingDraft) {
                    throw new DomainException(sprintf(
                        'Invoice %s is a draft covering exactly %s to %s, the period being billed now. A draft has '
                        .'charged nobody, so this period is not billed and the schedule is not advanced past it. '
                        .'Issue that draft to bill the period, or void it to waive the period deliberately.',
                        $claim->invoice()->invoice_number,
                        $start->toDateString(),
                        $end->toDateString(),
                    ));
                }

                if ($claim->verdict === PeriodClaimVerdict::AlreadyBilled) {
                    $created[] = $claim->invoice();
                } else {
                    $draft = $this->invoices->createDraft(
                        $locked->workspace,
                        $locked->clientCompany,
                        [
                            'invoice_number' => $this->invoiceNumber($locked, $start),
                            'issue_date' => $start->toDateString(),
                            'due_date' => $start->addDays((int) $locked->due_days)->toDateString(),
                            'service_period_start' => $start->toDateString(),
                            'service_period_end' => $end->toDateString(),
                            'currency' => $locked->currency,
                            'client_agreement_id' => $locked->client_agreement_id,
                            'client_billing_schedule_id' => $locked->id,
                        ],
                        $template,
                    );
                    $created[] = $this->invoices->issue($draft, $locked->workspace);
                }

                $nextRun = $period->next;
                $locked->forceFill(['next_run_on' => $nextRun->toDateString()])->save();
            }

            return $created;
        });
    }

    private function invoiceNumber(ClientBillingSchedule $schedule, CarbonImmutable $start): string
    {
        $base = 'INV-'.$start->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', $schedule->public_id), 0, 8));
        $number = $base;
        $suffix = 2;
        while (ClientInvoice::query()->where('workspace_id', $schedule->workspace_id)->where('invoice_number', $number)->exists()) {
            $number = $base.'-'.$suffix++;
        }

        return $number;
    }

    /** @return list<array<string, mixed>> */
    private function normalizedTemplate(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('A billing schedule line template must be an array.');
        }

        $template = [];
        foreach (array_values($value) as $line) {
            if (! is_array($line)) {
                throw new DomainException('A billing schedule line template entry must be an object.');
            }
            $template[] = $line;
        }

        return $template;
    }
}
