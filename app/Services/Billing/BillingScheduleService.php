<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\BillingPeriod;
use App\Support\Billing\BillingScheduleLineTemplate;
use App\Support\Billing\PeriodClaim;
use App\Support\Billing\PeriodClaimVerdict;
use App\Support\Billing\PlannedBillingPeriod;
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

    /**
     * Bill every period this schedule is due for, up to and including `$through`.
     *
     * Two passes over the same periods, under one lock and one transaction.
     * The first classifies every due period and mutates nothing; the second
     * creates and issues. A period the first pass cannot decide throws there,
     * before anything has been written.
     *
     * The order is the whole point. `BillingPeriodCollisionResolver` can refuse
     * a period whose lineage is dangling or contradictory, that overlaps
     * another invoice partially, or that a draft has claimed without billing -
     * and a single pass reached that refusal on the third period only after
     * `createDraft()` and `issue()` had written invoices, lines, activities and
     * time-entry statuses for the first two. Those writes were rolled back, and
     * had been doomed from the moment the third period was read.
     *
     * Whole-run atomicity is unchanged and deliberate, not something this order
     * relaxes. Committing the clear periods and refusing the rest would leave
     * `next_run_on` pointing into the middle of a batch, some of the schedule's
     * periods billed and a partial-success contract nothing else here has.
     * All-or-nothing is recoverable by re-running once the named row is
     * repaired; half-applied is not. What changes is that the refusal no longer
     * has a rollback of real money-facing writes behind it - #252.
     *
     * @return list<ClientInvoice>
     */
    public function generateDue(ClientBillingSchedule $schedule, CarbonImmutable $through): array
    {
        return DB::transaction(function () use ($schedule, $through): array {
            $locked = ClientBillingSchedule::query()->whereKey($schedule->id)->tap(Locks::forUpdate())->firstOrFail();
            if (! $locked->is_active) {
                return [];
            }

            // Read before the loop, so a schedule that cannot bill anything
            // halts whether or not a period is due, and read through the same
            // normaliser `ScheduleGenerationPreflight` uses, so that what it
            // reports as unbillable is exactly what throws here. An empty
            // template is refused there too: it used to pass, and priced to
            // an issued invoice for nothing.
            $template = BillingScheduleLineTemplate::normalize($locked->getAttribute('line_template'));

            [$plan, $nextRun] = $this->plan($locked, $through);
            if ($plan === []) {
                return [];
            }

            // Second pass. Every period here has already been decided, so
            // nothing below can halt the run on the state of the data, and
            // nothing above has written anything.
            $created = [];
            foreach ($plan as $planned) {
                $created[] = $planned->existingInvoice() ?? $this->bill($locked, $planned->period, $template);
            }

            // Once, at the end, rather than after each period. A refusal used
            // to be able to arrive with the cursor already advanced past
            // earlier periods in the same transaction; the rollback undid that
            // too, but there is now no point in the run at which the stored
            // cursor and the invoices disagree.
            $locked->forceFill(['next_run_on' => $nextRun->toDateString()])->save();

            return $created;
        });
    }

    /**
     * Classify every period due by `$through`, writing nothing.
     *
     * Refusals happen here, which is what makes this a first pass rather than
     * a prediction: it throws for the same reasons and with the same messages
     * the single-pass loop did, only earlier. The schedule must already be
     * locked - {@see BillingPeriodCollisionResolver} takes no lock of its own,
     * and the plan is only good for as long as the lock the caller holds.
     *
     * @return array{0: list<PlannedBillingPeriod>, 1: CarbonImmutable} the plan, and where the schedule
     *                                                                  stands once every period in it is billed
     *
     * @throws DomainException if any due period cannot be decided.
     */
    private function plan(ClientBillingSchedule $schedule, CarbonImmutable $through): array
    {
        $plan = [];
        $nextRun = CarbonImmutable::parse((string) $schedule->next_run_on);

        while ($nextRun->lte($through)) {
            $period = BillingPeriod::beginningAt($nextRun, (string) $schedule->cadence);

            // Whether this period is already covered, and by whose invoice,
            // is decided by `BillingPeriodCollisionResolver`. It used to be
            // one nested `where` closure here, and three reviews each found
            // a real defect inside it; the reasoning is long enough to need
            // its own class and its own tests per branch.
            $claim = $this->collisions->resolve($schedule, $period->start, $period->end);

            // Exhaustive, with no `default`, and that is the point of it
            // being a `match` rather than the if-chain it replaced. The
            // chain tested for the verdicts it knew and let anything else
            // fall into the arm that creates and issues an invoice - so a
            // fifth verdict added to `PeriodClaimVerdict` would have failed
            // *open* here, writing an invoice for a period the resolver had
            // declined to decide, in the one place whose job is to fail
            // closed. Now it throws `UnhandledMatchError` instead, and
            // PHPStan rejects the omission before that.
            //
            // A refusal still rolls the whole transaction back, including any
            // period already planned. Nothing has been created yet, so there
            // is nothing for the rollback to undo beyond the lock itself.
            $plan[] = match ($claim->verdict) {
                PeriodClaimVerdict::Refused => throw new DomainException($claim->refusal()),
                PeriodClaimVerdict::PendingDraft => throw new DomainException($this->pendingDraftMessage($claim, $period)),
                PeriodClaimVerdict::AlreadyBilled => PlannedBillingPeriod::alreadyBilled($period, $claim->invoice()),
                PeriodClaimVerdict::Clear => PlannedBillingPeriod::toBill($period),
            };

            $nextRun = $period->next;
        }

        return [$plan, $nextRun];
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
