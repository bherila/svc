<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceKind;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BillingScheduleService
{
    public function __construct(private readonly InvoiceLifecycleService $invoices) {}

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
                [$start, $end, $next] = $this->period($nextRun, (string) $locked->cadence);
                // Matched on the tenant and the period first, and on the
                // schedule link only to decide whose invoice it is.
                //
                // `client_billing_schedule_id` is legitimately null - a cadence
                // invoice created through `ClientInvoicingService` never sets
                // it, and an ad-hoc one has no schedule to name - so asking
                // `where('client_billing_schedule_id', $locked->id)` alone made
                // an unlinked invoice for exactly this period invisible: SQL
                // compares a null to a value as UNKNOWN, the schedule concluded
                // the period was unbilled, and raised - and issued - a second
                // invoice for it. The unique index does not save this either,
                // because a unique index does not constrain a null.
                //
                // An invoice naming a *different* schedule does not block. That
                // is another schedule's period, and a company can hold one
                // schedule per agreement, so blocking there would trade a
                // double-charge for a schedule that silently stops billing.
                //
                // The unclaimed arm is narrower than "any null", because a null
                // link is not by itself a claim on this period. Two conditions,
                // for the two ways such a row belongs to someone else:
                //
                // - **Kind.** `InvoiceKind::cycleGuardExclusions()` already
                //   says an interim or ad-hoc invoice must not block a cadence
                //   one, and `ClientInvoicingService::assertNoOverlappingInvoice()`
                //   honours it. Neither kind carries a schedule, so an
                //   unqualified `orWhereNull` reads an operator's ad-hoc
                //   invoice that happens to share these dates as this period's
                //   cadence invoice, returns it, and advances `next_run_on`
                //   past a period nothing has billed - lost revenue, arrived at
                //   through the guard meant to protect it. Same predicate as
                //   the sibling guard, so the two cannot drift apart.
                // - **Agreement.** A company can hold several, each billing its
                //   own periods, and one agreement's cadence invoice says
                //   nothing about another's. A row naming *no* agreement is
                //   matched here and then attributed below, because "blocks"
                //   is only the right answer when this schedule is the one it
                //   could belong to.
                $invoice = ClientInvoice::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('client_company_id', $locked->client_company_id)
                    ->whereDate('service_period_start', $start)
                    ->whereDate('service_period_end', $end)
                    ->where(function (Builder $mineOrUnclaimed) use ($locked): void {
                        $mineOrUnclaimed
                            ->where('client_billing_schedule_id', $locked->id)
                            ->orWhere(function (Builder $unclaimed) use ($locked): void {
                                $unclaimed
                                    ->whereNull('client_billing_schedule_id')
                                    ->where(function (Builder $couldBlockACadencePeriod): void {
                                        $couldBlockACadencePeriod
                                            ->whereNull('invoice_kind')
                                            ->orWhereNotIn('invoice_kind', InvoiceKind::cycleGuardExclusions());
                                    })
                                    ->where(function (Builder $onThisAgreement) use ($locked): void {
                                        $onThisAgreement
                                            ->where('client_agreement_id', $locked->client_agreement_id)
                                            ->orWhereNull('client_agreement_id');
                                    });
                            });
                    })
                    ->first();

                // A row naming neither a schedule nor an agreement matches
                // *every* schedule this company has, and at most one of them
                // can be the one it covers. Blocking on it silently suppresses
                // the others - two schedules due for these dates would both
                // return this single invoice, both advance their own
                // `next_run_on`, and at least one agreement would go unbilled
                // for a period nothing charged.
                //
                // So the fail-closed reading is kept only where it is
                // unambiguous. With one active schedule on the company there is
                // nowhere else the row could belong and it blocks, which is
                // #219's case and the common one. With a rival, this cannot be
                // decided here at all: billing anyway double-charges and
                // skipping loses a period, so it refuses and says what to fix.
                //
                // Deliberately narrow, per the lesson of #144 - a refusal on a
                // null must be checked against the paths that write it. Nothing
                // in this application writes this shape: `generateDue()` and
                // `ClientInvoicingService` both set the agreement, and
                // `createDraft()` without one stamps `ad_hoc`, which the kind
                // filter above has already excluded. Only a migrated or
                // hand-repaired row reaches here, and only while a second
                // schedule is live.
                if ($invoice instanceof ClientInvoice
                    && $invoice->client_billing_schedule_id === null
                    && $invoice->client_agreement_id === null
                    && $this->hasARivalSchedule($locked)) {
                    throw new DomainException(sprintf(
                        'Invoice %s covers %s to %s for this client but names neither a billing schedule nor an agreement, '
                        .'and this client has more than one active billing schedule. It cannot be attributed to one of them '
                        .'here. Set its agreement, or its schedule, before billing this period.',
                        $invoice->invoice_number,
                        $start->toDateString(),
                        $end->toDateString(),
                    ));
                }

                if ($invoice === null) {
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
                } else {
                    $created[] = $invoice;
                }

                $nextRun = $next;
                $locked->forceFill(['next_run_on' => $nextRun->toDateString()])->save();
            }

            return $created;
        });
    }

    /**
     * Whether another active schedule on this company could claim the period.
     *
     * Active only. An inactive schedule generates nothing - `generateDue()`
     * returns early for one - so it cannot be the rival claimant that makes an
     * unattributed invoice ambiguous, and counting it would refuse on a
     * question nobody is actually asking. Reactivating it puts the ambiguity
     * back, which is correct: this is re-evaluated on every run rather than
     * decided once.
     */
    private function hasARivalSchedule(ClientBillingSchedule $schedule): bool
    {
        return ClientBillingSchedule::query()
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->where('is_active', true)
            ->whereKeyNot($schedule->id)
            ->exists();
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:CarbonImmutable} */
    private function period(CarbonImmutable $start, string $cadence): array
    {
        $months = match ($cadence) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            default => throw new DomainException('Unsupported billing cadence.'),
        };
        $next = $start->addMonthsNoOverflow($months);

        return [$start, $next->subDay(), $next];
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
