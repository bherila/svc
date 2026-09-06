<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\BillingPeriod;
use App\Support\Billing\BillingScheduleLineTemplate;
use App\Support\Billing\PeriodClaim;
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

            // Read before the loop, so a schedule that cannot bill anything
            // halts whether or not a period is due, and read through the same
            // normaliser `ScheduleGenerationPreflight` uses, so that what it
            // reports as unbillable is exactly what throws here. An empty
            // template is refused there too: it used to pass, and priced to
            // an issued invoice for nothing.
            $template = BillingScheduleLineTemplate::normalize($locked->getAttribute('line_template'));

            while ($nextRun->lte($through)) {
                $period = BillingPeriod::beginningAt($nextRun, (string) $locked->cadence);

                // Whether this period is already covered, and by whose invoice,
                // is decided by `BillingPeriodCollisionResolver`. It used to be
                // one nested `where` closure here, and three reviews each found
                // a real defect inside it; the reasoning is long enough to need
                // its own class and its own tests per branch.
                $claim = $this->collisions->resolve($locked, $period->start, $period->end);

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
                //
                // Exhaustive, with no `default`, and that is the point of it
                // being a `match` rather than the if-chain it replaced. The
                // chain tested for the verdicts it knew and let anything else
                // fall into the arm that creates and issues an invoice - so a
                // fifth verdict added to `PeriodClaimVerdict` would have failed
                // *open* here, writing an invoice for a period the resolver had
                // declined to decide, in the one place whose job is to fail
                // closed. Now it throws `UnhandledMatchError` instead, and
                // PHPStan rejects the omission before that.
                $created[] = match ($claim->verdict) {
                    PeriodClaimVerdict::Refused => throw new DomainException($claim->refusal()),
                    PeriodClaimVerdict::PendingDraft => throw new DomainException($this->pendingDraftMessage($claim, $period)),
                    PeriodClaimVerdict::AlreadyBilled => $claim->invoice(),
                    PeriodClaimVerdict::Clear => $this->bill($locked, $period, $template),
                };

                $nextRun = $period->next;
                $locked->forceFill(['next_run_on' => $nextRun->toDateString()])->save();
            }

            return $created;
        });
    }

    /**
     * Create and issue this schedule's invoice for one period.
     *
     * @param  non-empty-list<array<string, mixed>>  $template
     */
    private function bill(ClientBillingSchedule $schedule, BillingPeriod $period, array $template): ClientInvoice
    {
        $draft = $this->invoices->createDraft(
            $schedule->workspace,
            $schedule->clientCompany,
            [
                'invoice_number' => $this->invoiceNumber($schedule, $period->start),
                'issue_date' => $period->start->toDateString(),
                'due_date' => $period->start->addDays((int) $schedule->due_days)->toDateString(),
                'service_period_start' => $period->start->toDateString(),
                'service_period_end' => $period->end->toDateString(),
                'currency' => $schedule->currency,
                'client_agreement_id' => $schedule->client_agreement_id,
                'client_billing_schedule_id' => $schedule->id,
            ],
            $template,
        );

        return $this->invoices->issue($draft, $schedule->workspace);
    }

    /**
     * Why a draft covering the period stops the run, and what to do about it.
     *
     * A draft covering exactly this period has *claimed* it without *billing*
     * it, and treating those as the same fact is how a period gets silently
     * skipped. Reporting the draft as already billed advanced the cursor past
     * a period no money had been asked for, and nothing brought it back:
     * `InvoiceLifecycleService::discardDraft()` turns the draft into a void
     * invoice that keeps its period, so even rewinding `next_run_on` met an
     * exact void and honoured it as a waiver.
     *
     * Issuing it here instead is not available either - the draft may be
     * another generator's, mid-review, and issuing is the act of asking a
     * client for money. So the schedule stops and names it. Issue the draft
     * and the next run advances normally; void it deliberately and the waiver
     * is honoured.
     *
     * Safe advice only because the resolver reaches this verdict only when the
     * draft is the sole claim on the period. A draft beside any other invoice
     * covering the period exactly refuses as a conflict instead, with advice
     * specific to what it sits beside - `issue()` runs no overlap check, so
     * "issue that draft" next to an issued invoice would charge the client
     * twice.
     */
    private function pendingDraftMessage(PeriodClaim $claim, BillingPeriod $period): string
    {
        return sprintf(
            'Invoice %s is a draft covering exactly %s to %s, the period being billed now. A draft has '
            .'charged nobody, so this period is not billed and the schedule is not advanced past it. '
            .'Issue that draft to bill the period, or void it to waive the period deliberately.',
            $claim->invoice()->invoice_number,
            $period->start->toDateString(),
            $period->end->toDateString(),
        );
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
}
