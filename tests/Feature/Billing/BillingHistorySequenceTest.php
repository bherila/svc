<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Approval\DisputedDraftRebuilder;
use App\Services\Approval\DisputeSettlements;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DeferredBuyDownAction;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use App\Support\Billing\DeferredBuyDownRequest;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

/**
 * Billing invariants over generated operation sequences (docs/ux-overhaul.md
 * 7b and 7b″).
 *
 * BillingHistoryInvariantTest asks the same kind of question of a fixed set of
 * hand-written histories. Every bug the reviews of #655 found was an ordering
 * nobody had written down, so this test writes the orderings by seeded chance:
 * logging, deferring, generating, disputing, answering, writing off, issuing,
 * regenerating, voiding, reopening and buying down, in any order, with a
 * dispute's rebuild sometimes lost, on five agreement shapes. A refusal is a legal outcome of a step. Breaking one of
 * these is not:
 *
 * - **Every minute is accounted for.** Splitting a row never creates or loses time.
 * - **Charged once.** No hour is allocated by two live invoices.
 * - **Written off stays off.** No live invoice carries a written-off hour.
 * - **Held is not billed.** Generating or regenerating never takes a disputed
 *   hour, and a draft that still carries one cannot issue.
 * - **Stale is refused.** A draft regeneration would change cannot issue.
 * - **Issued is final.** An issued invoice changes only by being voided or reopened.
 * - **Lines add up.** An invoice's total is the sum of its lines.
 * - **Sold once.** No retainer cycle is sold twice by live invoices.
 *
 * Then the history is closed out - every dispute answered, every period
 * generated, every draft issued, the backlog bought down - and the money is
 * checked against arithmetic that shares nothing with the billing engine. On an
 * hourly agreement every hour not written off is billed exactly once, whatever
 * happened on the way, so the total is exactly hours times rate. On a retainer
 * the order can legitimately change the overage, so the total is bounded by the
 * retainers sold plus every hour at the full rate.
 *
 * A backdated termination is one of the steps, once per history: it ends the
 * agreement at a period close or mid-period, and must rebuild the drafts it
 * makes stale in the same request (#699), so none issues as it was. Successor
 * agreements are left out: their handovers are scripted in
 * BillingHistoryInvariantTest. One known gap is allowed for, narrowly, where
 * it is cited: #703.
 *
 * A failure prints its seed and steps. Replay one with
 * `--filter 'hourly, monthly, seed 3'`; widen a run with BILLING_HISTORY_SEEDS.
 */
final class BillingHistorySequenceTest extends TestCase
{
    use RefreshDatabase;

    private const int DEFAULT_SEEDS = 4;

    private const int STEPS = 16;

    private const int RATE = 10000;

    /** @var array<string, array<string, mixed>> */
    private const array WORLDS = [
        'hourly, monthly' => ['retainer_minutes' => 0, 'retainer_amount' => 0],
        'hourly, quarterly' => [
            'billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 0, 'retainer_amount' => 0,
            'period_retainer_minutes' => 0, 'period_retainer_amount' => 0,
        ],
        'retainer, monthly' => ['retainer_minutes' => 600, 'retainer_amount' => 60000],
        'retainer with rollover, monthly' => ['retainer_minutes' => 600, 'retainer_amount' => 60000, 'rollover_months' => 2],
        'retainer with rollover, quarterly' => [
            'billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'retainer_amount' => 60000, 'rollover_months' => 3,
        ],
    ];

    private Randomizer $random;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $owner;

    private bool $hourly;

    /** @var list<string> the periods still to generate, earliest first */
    private array $periods = [];

    /** The period after the last, which rolled-forward work can land on at close-out. */
    private string $onePeriodMore = '';

    private int $loggedMinutes = 0;

    /** @var array<int, string> each issued invoice as it was issued */
    private array $issued = [];

    /** @var array<int, string> the period each generated invoice was asked for */
    private array $generatedFor = [];

    /** @var array<int, true> drafts generated before the latest logged hour: behind the work, which is not the same as stale */
    private array $behindNewWork = [];

    private int $logsLeft = 3;

    /** the backdated end, once the history has terminated the agreement */
    private ?string $endsOn = null;

    /** @var list<string> */
    private array $trace = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return iterable<string, array{string, int}> */
    public static function histories(): iterable
    {
        $seeds = max(1, (int) (getenv('BILLING_HISTORY_SEEDS') ?: self::DEFAULT_SEEDS));
        foreach (array_keys(self::WORLDS) as $world) {
            foreach (range(1, $seeds) as $seed) {
                yield "{$world}, seed {$seed}" => [$world, $seed];
            }
        }
    }

    #[DataProvider('histories')]
    public function test_a_generated_history_keeps_its_invariants(string $world, int $seed): void
    {
        Carbon::setTestNow('2027-02-01 12:00:00');
        CarbonImmutable::setTestNow('2027-02-01 12:00:00');
        $this->random = new Randomizer(new Mt19937(crc32($world) + $seed));
        $this->build($world, $seed);

        try {
            foreach (range(1, self::STEPS) as $step) {
                $this->takeStep($step);
            }
            $this->closeOut();
        } catch (Throwable $failure) {
            $this->fail(sprintf(
                "%s, seed %d: %s at %s:%d\n%s\n\nSteps:\n  %s",
                $world, $seed, $failure::class, basename($failure->getFile()), $failure->getLine(), $failure->getMessage(), implode("\n  ", $this->trace),
            ));
        }
    }

    /** #704: January billed only the retainer before a 1110-minute row arrived. */
    public function test_late_work_after_an_empty_retainer_invoice_seed_6(): void
    {
        $this->test_a_generated_history_keeps_its_invariants('retainer with rollover, monthly', 6);
    }

    // ---- the generator ----

    private function takeStep(int $step): void
    {
        $operations = $this->availableOperations();
        $name = array_keys($operations)[$this->random->getInt(0, count($operations) - 1)];
        $this->attempt("{$step}. {$name}", $operations[$name]);
    }

    /** @return array<string, callable(): (string|null)> */
    private function availableOperations(): array
    {
        $operations = [];
        if ($this->logsLeft > 0) {
            $operations['log hours'] = function (): string {
                $this->logsLeft--;

                return $this->logHours();
            };
        }
        if ($this->periods !== []) {
            $operations['generate the next period'] = fn (): string => $this->generateNextPeriod();
        }
        if ($this->endsOn === null) {
            $operations['terminate, backdated'] = fn (): string => $this->terminateBackdated();
        }

        $entries = $this->entries();
        $open = $entries->filter(fn (ClientTimeEntry $entry): bool => $entry->client_disputed_at === null && $entry->client_dispute_settlement !== DisputeSettlement::WrittenOff->value);
        $disputed = $entries->filter(fn (ClientTimeEntry $entry): bool => $entry->client_disputed_at !== null);
        if ($open->isNotEmpty()) {
            $operations['dispute'] = fn (): string => $this->dispute($this->pick($open));
            $operations['dispute, rebuild lost'] = fn (): string => $this->dispute($this->pick($open), rebuild: false);
            $operations['dispute then answer, answer\'s rebuild lost'] = function () use ($open): string {
                $entry = $this->pick($open);
                $this->dispute($entry);

                return $this->answerWithoutRebuild($entry->fresh() ?? $entry);
            };
        }
        if ($disputed->isNotEmpty()) {
            $operations['answer'] = fn (): string => $this->settle($this->pick($disputed), DisputeSettlement::Answered);
            $operations['answer, rebuild lost'] = fn (): string => $this->answerWithoutRebuild($this->pick($disputed));
            $operations['write off'] = fn (): string => $this->settle($this->pick($disputed), DisputeSettlement::WrittenOff);
        }

        $drafts = $this->invoices(InvoiceStatus::Draft->value);
        if ($drafts->isNotEmpty()) {
            $operations['issue'] = fn (): string => $this->issue($this->pick($drafts));
            $generated = $drafts->filter(fn (ClientInvoice $draft): bool => $this->isRegenerable($draft));
            if ($generated->isNotEmpty()) {
                $operations['regenerate'] = fn (): string => $this->regenerate($this->pick($generated));
            }
        }
        if ($this->deferredWithinTheSegment()->exists()) {
            $operations['buy down'] = fn (): string => $this->buyDown(null);
            if ($drafts->isNotEmpty()) {
                $operations['buy down onto a draft'] = fn (): string => $this->buyDown($this->pick($drafts));
            }
        }

        $issued = $this->invoices(InvoiceStatus::Issued->value);
        if ($issued->isNotEmpty()) {
            $operations['void'] = fn (): string => $this->void($this->pick($issued));
            $operations['reopen'] = fn (): string => $this->reopen($this->pick($issued));
        }

        return $operations;
    }

    /**
     * Run one operation and check the invariants after it. A step the system
     * refuses is recorded and is still a state the invariants must hold in.
     *
     * @param  callable(): (string|null)  $operation
     */
    private function attempt(string $label, callable $operation): void
    {
        try {
            $this->trace[] = trim($label.': '.$operation());
        } catch (DomainException|HttpExceptionInterface $refused) {
            $this->trace[] = $label.': refused ('.$refused->getMessage().')';
        }

        $this->assertInvariants($label);
    }

    /**
     * @template T of Model
     *
     * @param  Collection<int, T>  $rows
     * @return T
     */
    private function pick(Collection $rows): Model
    {
        return $rows->values()->all()[$this->random->getInt(0, $rows->count() - 1)];
    }

    // ---- the operations ----

    private function logHours(): string
    {
        $day = sprintf('2026-%02d-%02d', $this->random->getInt(1, 6), $this->random->getInt(1, 28));
        $minutes = 30 * $this->random->getInt(1, 40);
        $deferred = $this->random->getInt(1, 5) === 1;
        $this->hours($day, $minutes, $deferred);
        $this->markDraftsBehind();

        return "{$minutes}m on {$day}".($deferred ? ', deferred' : '');
    }

    private function generateNextPeriod(): string
    {
        $period = $this->periods[0];
        $invoice = app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $this->agreement->fresh() ?? $this->agreement, Carbon::parse($period));
        array_shift($this->periods);
        $this->generatedFor[$invoice->id] = $period;
        $this->assertGeneratedDraftTakesNoHeldWork($invoice, "generating {$period}");

        return "{$period} as #{$invoice->id}, total {$invoice->total_amount}";
    }

    /**
     * End the agreement in the past, at a period close or inside a period.
     * The cycle it falls in is prorated and the final reconciliation now
     * collects the deferred work, so the drafts already written are stale:
     * the termination rebuilds them in the request, and the stale-draft
     * invariant asks every one of them after this step.
     */
    private function terminateBackdated(): string
    {
        $ends = ($this->agreement->billing_cadence === 'quarterly')
            ? ['2026-03-31', '2026-05-15', '2026-06-30']
            : ['2026-03-31', '2026-04-15', '2026-05-31', '2026-06-10'];
        $end = $ends[$this->random->getInt(0, count($ends) - 1)];
        app(AgreementWorkflow::class)->terminate($this->agreement->fresh() ?? $this->agreement, $end);
        $this->endsOn = $end;

        return "on {$end}";
    }

    /**
     * The rebuild runs after the dispute commits and can fail or be lost; the
     * dispute stands either way (7b″), and only the issue check is left.
     */
    private function dispute(ClientTimeEntry $entry, bool $rebuild = true): string
    {
        ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->whereKey($entry->id)->update([
            'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic question',
            'client_dispute_settlement' => null, 'lock_version' => DB::raw('lock_version + 1'),
        ]);
        if ($rebuild) {
            app(DisputedDraftRebuilder::class)->withdraw($this->workspace->id, [$entry->id], []);
        }

        return "row {$entry->id}, {$entry->minutes}m on {$entry->worked_on->toDateString()}";
    }

    private function settle(ClientTimeEntry $entry, DisputeSettlement $outcome): string
    {
        $fresh = $entry->fresh() ?? $entry;
        app(DisputeSettlements::class)->settleTime($this->workspace, $fresh->public_id, $this->owner, $outcome, 'Synthetic reply', AgentApiVersion::for($fresh));

        return "row {$entry->id}";
    }

    /** An answer whose rebuild never ran, and which may have left the row waiting as deferred work. */
    private function answerWithoutRebuild(ClientTimeEntry $entry): string
    {
        // As LateAnswer decides: its period already charged, the row can only wait.
        $periodCharged = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('service_period_start', '<=', $entry->worked_on->toDateString())->whereDate('service_period_end', '>=', $entry->worked_on->toDateString())->exists();
        $defer = $periodCharged || $this->random->getInt(0, 1) === 1;
        ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->whereKey($entry->id)->update([
            'client_disputed_at' => null, 'client_dispute_settlement' => DisputeSettlement::Answered->value, 'client_dispute_reply' => 'Synthetic reply',
            'is_deferred' => $defer || $entry->is_deferred, 'lock_version' => DB::raw('lock_version + 1'),
        ]);

        return "row {$entry->id}".($defer ? ', now deferred' : '');
    }

    private function issue(ClientInvoice $draft): string
    {
        app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $this->workspace);
        // Whatever is logged next arrives after this was issued.
        $later = CarbonImmutable::now()->addMinute();
        Carbon::setTestNow($later);
        CarbonImmutable::setTestNow($later);

        return "#{$draft->id}";
    }

    private function regenerate(ClientInvoice $draft): string
    {
        $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft->fresh() ?? $draft);
        $this->markCaughtUp($draft);
        if ($rebuilt !== null) {
            $this->assertGeneratedDraftTakesNoHeldWork($rebuilt, "regenerating #{$draft->id}");
        }

        return "#{$draft->id}";
    }

    private function void(ClientInvoice $invoice): string
    {
        $was = $this->issued[$invoice->id] ?? null;
        $releasesOnlyOrdinaryWork = ! $this->carriesDisputeTouchedWork($invoice);
        unset($this->issued[$invoice->id]);
        try {
            app(InvoiceLifecycleService::class)->void($invoice->fresh() ?? $invoice, $this->workspace, 'Synthetic void');
        } catch (DomainException|HttpExceptionInterface $refused) {
            if ($was !== null) {
                $this->issued[$invoice->id] = $was;
            }

            throw $refused;
        }
        if ($releasesOnlyOrdinaryWork) {
            $this->markDraftsBehind();
        }
        // Voiding a period's invoice hands the period back to be billed again.
        if (isset($this->generatedFor[$invoice->id])) {
            $this->periods[] = $this->generatedFor[$invoice->id];
            sort($this->periods);
        }

        return "#{$invoice->id}";
    }

    private function reopen(ClientInvoice $invoice): string
    {
        $was = $this->issued[$invoice->id] ?? null;
        $releasesOnlyOrdinaryWork = ! $this->carriesDisputeTouchedWork($invoice);
        unset($this->issued[$invoice->id]);
        try {
            app(InvoiceLifecycleService::class)->reopen($invoice->fresh() ?? $invoice, 'Synthetic reopen', $this->workspace);
        } catch (DomainException|HttpExceptionInterface $refused) {
            if ($was !== null) {
                $this->issued[$invoice->id] = $was;
            }

            throw $refused;
        }
        if ($releasesOnlyOrdinaryWork) {
            $this->markDraftsBehind();
        }

        return "#{$invoice->id}";
    }

    /** New or released ordinary work leaves a draft behind, which is not stale: only disputes must refuse it (7b″). */
    private function markDraftsBehind(): void
    {
        foreach ($this->invoices(InvoiceStatus::Draft->value) as $draft) {
            $this->behindNewWork[$draft->id] = true;
        }
    }

    /** A draft has caught up only once every earlier draft it is sized against has too. */
    private function markCaughtUp(ClientInvoice $draft): void
    {
        foreach ($this->invoices(InvoiceStatus::Draft->value) as $earlier) {
            if ($earlier->id === $draft->id) {
                unset($this->behindNewWork[$draft->id]);

                return;
            }
            if (isset($this->behindNewWork[$earlier->id])) {
                return;
            }
        }
    }

    private function carriesDisputeTouchedWork(ClientInvoice $invoice): bool
    {
        return $this->allocations()->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('invoice.id', $invoice->id)
            ->where(fn ($query) => $query->whereNotNull('entry.client_disputed_at')->orWhereNotNull('entry.client_dispute_settlement'))
            ->exists();
    }

    private function buyDown(?ClientInvoice $target): string
    {
        $billing = app(DeferredBuyDownAction::class);
        $agreement = $this->agreement->fresh() ?? $this->agreement;
        $request = DeferredBuyDownRequest::from(['hours' => 0.5 * $this->random->getInt(1, 40), 'round_up' => true]);
        $invoice = $billing->create($this->workspace, $agreement, $request, $billing->preview($this->workspace, $agreement, $request)->version, $target?->fresh());

        return "on #{$invoice->id}, total {$invoice->total_amount}";
    }

    // ---- closing the history ----

    /**
     * Settle everything still open and bill everything that can be billed, so
     * the money can be compared with the hours.
     */
    private function closeOut(): void
    {
        foreach ($this->entries()->filter(fn (ClientTimeEntry $entry): bool => $entry->client_disputed_at !== null) as $entry) {
            $this->attempt('close: answer', fn (): string => $this->settle($entry, DisputeSettlement::Answered));
        }
        $this->generateTheRest();
        $this->issueEverything();

        // Work rolled forward onto a draft after the last period was generated
        // needs one more period to land on.
        $this->periods = [$this->onePeriodMore];
        $this->generateTheRest();
        $this->issueEverything();

        $waiting = (int) $this->deferredWithinTheSegment()->sum('minutes');
        if ($waiting > 0) {
            $billing = app(DeferredBuyDownAction::class);
            $agreement = $this->agreement->fresh() ?? $this->agreement;
            $request = DeferredBuyDownRequest::from(['hours' => $waiting / 60, 'round_up' => true]);
            $invoice = $billing->create($this->workspace, $agreement, $request, $billing->preview($this->workspace, $agreement, $request)->version);
            $this->trace[] = "close: buy down {$waiting}m on #{$invoice->id}, total {$invoice->total_amount}";
            $this->issueEverything();
        }

        $this->assertInvariants('closed out');
        $this->assertTheMoneyMatchesTheHours();
    }

    private function generateTheRest(): void
    {
        while ($this->periods !== []) {
            $count = count($this->periods);
            $this->attempt('close: generate', fn (): string => $this->generateNextPeriod());
            if (count($this->periods) === $count) {
                array_shift($this->periods);
            }
        }
    }

    private function issueEverything(): void
    {
        foreach ($this->invoices(InvoiceStatus::Draft->value) as $draft) {
            $current = $draft->fresh() ?? $draft;
            if ($this->isRegenerable($draft)) {
                try {
                    $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($current);
                    $current = $rebuilt === null ? null : ($rebuilt->fresh() ?? $rebuilt);
                    $this->markCaughtUp($draft);
                } catch (DomainException|HttpExceptionInterface) {
                    $current = $draft->fresh() ?? $draft;
                }
            }
            if ($current !== null) {
                $this->attempt('close: issue #'.$current->id, fn (): string => $this->issue($current));
            }
        }
    }

    // ---- the invariants ----

    private function assertInvariants(string $step): void
    {
        $this->assertEveryMinuteIsAccountedFor($step);
        $this->assertNoHourIsChargedTwice($step);
        $this->assertWrittenOffWorkIsOnNoLiveInvoice($step);
        $this->assertDraftsThatMustNotIssueDoNot($step);
        $this->assertIssuedInvoicesAreFinal($step);
        $this->assertEveryTotalIsItsLines($step);
        $this->assertEachCycleIsSoldOnce($step);
    }

    /** Only a buy-down's own line may still hold a disputed hour: it is not built by the generator. */
    private function assertGeneratedDraftTakesNoHeldWork(ClientInvoice $invoice, string $step): void
    {
        $held = $this->allocations()->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('invoice.id', $invoice->id)->where('line.type', '!=', InvoiceLineType::DeferredBuyDown->value)
            ->whereNotNull('entry.client_disputed_at')->pluck('entry.id')->all();
        $this->assertSame([], $held, "{$step} put disputed work on draft #{$invoice->id}.");
    }

    private function assertEveryMinuteIsAccountedFor(string $step): void
    {
        $this->assertSame($this->loggedMinutes, (int) ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)
            ->where('client_company_id', $this->company->id)->sum('minutes'), "After {$step}, the time on record no longer adds up to the time logged.");
    }

    private function assertNoHourIsChargedTwice(string $step): void
    {
        $twice = $this->allocations()->groupBy('allocation.client_time_entry_id')->havingRaw('count(distinct invoice.id) > 1')->pluck('allocation.client_time_entry_id')->all();
        $this->assertSame([], $twice, "After {$step}, these hours are allocated by two live invoices.");
    }

    private function assertWrittenOffWorkIsOnNoLiveInvoice(string $step): void
    {
        $charged = $this->allocations()->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('entry.client_dispute_settlement', DisputeSettlement::WrittenOff->value)
            ->pluck('entry.id')->all();
        $this->assertSame([], $charged, "After {$step}, a live invoice still carries written-off work.");
    }

    /** A draft carrying a held or written-off hour, or one regeneration would change, must refuse to issue. */
    private function assertDraftsThatMustNotIssueDoNot(string $step): void
    {
        foreach ($this->invoices(InvoiceStatus::Draft->value) as $draft) {
            $before = $this->shape($draft);
            $after = ! $this->isRegenerable($draft) || isset($this->behindNewWork[$draft->id]) ? $before : $this->inSavepoint(function () use ($draft): mixed {
                try {
                    $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft);

                    return $rebuilt === null ? null : $this->shape($rebuilt->fresh() ?? $rebuilt);
                } catch (DomainException|HttpExceptionInterface) {
                    return $this->shape($draft);
                }
            });
            $carriesHeldWork = $this->allocations()->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
                ->where('invoice.id', $draft->id)
                ->where(fn ($query) => $query->whereNotNull('entry.client_disputed_at')->orWhere('entry.client_dispute_settlement', DisputeSettlement::WrittenOff->value))
                ->exists();
            $unchanged = $after === $before || ($after === null && $before === []);
            if ($unchanged && ! $carriesHeldWork) {
                continue;
            }

            $issued = $this->inSavepoint(function () use ($draft): bool {
                try {
                    app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $this->workspace);

                    return true;
                } catch (DomainException|HttpExceptionInterface) {
                    return false;
                }
            });
            $this->assertFalse($issued, sprintf(
                "After %s, draft #%d %s and issued anyway.\nNow: %s\nRegenerated: %s",
                $step, $draft->id, $carriesHeldWork ? 'carries disputed or written-off work' : 'would change on regeneration', json_encode($before), json_encode($after),
            ));
        }
    }

    private function assertIssuedInvoicesAreFinal(string $step): void
    {
        $seen = [];
        foreach ($this->invoices(InvoiceStatus::Issued->value) as $invoice) {
            $now = json_encode([(int) $invoice->total_amount, $this->shape($invoice)], JSON_THROW_ON_ERROR);
            $this->issued[$invoice->id] ??= $now;
            $this->assertSame($this->issued[$invoice->id], $now, "After {$step}, issued invoice #{$invoice->id} changed.");
            $seen[$invoice->id] = true;
        }
        $this->assertSame([], array_diff_key($this->issued, $seen), "After {$step}, an issued invoice stopped being issued by no void or reopen.");
    }

    private function assertEveryTotalIsItsLines(string $step): void
    {
        foreach ($this->liveInvoices() as $invoice) {
            $this->assertSame((int) $invoice->lines()->sum('total_amount'), (int) $invoice->total_amount, "After {$step}, invoice #{$invoice->id} does not total its lines.");
        }
    }

    private function assertEachCycleIsSoldOnce(string $step): void
    {
        $sold = $this->lines()->where('line.type', InvoiceLineType::Retainer->value)
            ->groupBy('line.line_date')->havingRaw('count(*) > 1')->pluck('line.line_date')->all();
        $this->assertSame([], $sold, "After {$step}, a retainer cycle is sold twice by live invoices.");
    }

    /**
     * Arithmetic that shares nothing with the engine. Hourly: what was billed
     * is every hour not written off, at the rate. Retainer: at most the
     * retainers sold plus every such hour at the full rate.
     */
    private function assertTheMoneyMatchesTheHours(): void
    {
        $minutes = (int) ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)
            ->where(fn ($query) => $query->whereNull('client_dispute_settlement')->orWhere('client_dispute_settlement', '!=', DisputeSettlement::WrittenOff->value))
            ->sum('minutes');
        $leftOver = ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)
            ->unbilled()->where('is_billable', true)->whereNull('client_disputed_at')->get();
        // #703: deferred work ends with the agreement's segment (#655), so an
        // hour deferred after a termination waits for a buy-down nothing can
        // make. Only such rows may be left over; any other is a lost hour.
        $deferredAfterTheEnd = $leftOver->filter(fn (ClientTimeEntry $entry): bool => $this->endsOn !== null && $entry->is_deferred
            && $entry->worked_on->toDateString() > $this->endsOn);
        $this->assertSame([], array_values(array_diff($leftOver->modelKeys(), $deferredAfterTheEnd->modelKeys())), 'Closed out: these hours were never billed, and none of them was deferred after the end.');
        $minutes -= (int) $deferredAfterTheEnd->sum(static fn (ClientTimeEntry $entry): int => (int) $entry->minutes);
        $this->assertSame([], $this->invoices(InvoiceStatus::Draft->value)->modelKeys(), 'Closed out: these drafts never issued.');
        $hoursAtRate = (int) round($minutes * self::RATE / 60);
        $billed = (int) $this->invoices(InvoiceStatus::Issued->value)->sum(static fn (ClientInvoice $invoice): int => (int) $invoice->total_amount);

        if ($this->hourly) {
            $this->assertSame($hoursAtRate, $billed, "Closed out: {$minutes} billable minutes at the rate is {$hoursAtRate}, and the live invoices total {$billed}.");

            return;
        }

        $sold = (int) $this->lines()->whereIn('line.type', [InvoiceLineType::Retainer->value, InvoiceLineType::PriorMonthRetainer->value])->sum('line.total_amount');
        $this->assertLessThanOrEqual($sold + $hoursAtRate, $billed, "Closed out: retainers sold ({$sold}) plus every hour at the rate ({$hoursAtRate}) is the most this can bill, and the live invoices total {$billed}.");
    }

    // ---- reads ----

    /** @return EloquentBuilder<ClientTimeEntry> deferred work a buy-down can still take: none after the agreement's end */
    private function deferredWithinTheSegment(): EloquentBuilder
    {
        return ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)
            ->awaitingDeferredBilling()->when($this->endsOn !== null, fn ($query) => $query->whereDate('worked_on', '<=', $this->endsOn));
    }

    private function isRegenerable(ClientInvoice $draft): bool
    {
        return $draft->invoiceKindValue() === InvoiceKind::CadencePeriod->value;
    }

    /** @return Collection<int, ClientTimeEntry> */
    private function entries(): Collection
    {
        return ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)->orderBy('id')->get();
    }

    /** @return Collection<int, ClientInvoice> */
    private function invoices(string $status): Collection
    {
        return ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)
            ->where('status', $status)->orderBy('service_period_start')->orderBy('id')->get();
    }

    /** @return Collection<int, ClientInvoice> */
    private function liveInvoices(): Collection
    {
        return ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)
            ->where('status', '!=', InvoiceStatus::Void->value)->orderBy('id')->get();
    }

    /** Lines on live invoices. */
    private function lines(): Builder
    {
        return DB::table('client_invoice_lines as line')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->where('invoice.workspace_id', $this->workspace->id)->where('invoice.client_company_id', $this->company->id)
            ->where('invoice.status', '!=', InvoiceStatus::Void->value);
    }

    /** Time allocations on live invoices. */
    private function allocations(): Builder
    {
        return $this->lines()->join('client_invoice_line_time_entries as allocation', 'allocation.client_invoice_line_id', '=', 'line.id');
    }

    /**
     * Each line's type, quantity, total and time by day and minutes:
     * regeneration re-splits rows, so an allocation can return under new ids.
     *
     * @return array<int, array{string, string, int, array<int, string>}>
     */
    private function shape(ClientInvoice $invoice): array
    {
        return $invoice->lines()->with('timeEntries:id,worked_on,minutes')->get()
            ->map(fn ($line): array => [(string) $line->type, (string) $line->quantity, (int) $line->total_amount, $line->timeEntries
                ->map(fn (ClientTimeEntry $entry): string => $entry->worked_on->toDateString().':'.(int) $entry->minutes)->sort()->values()->all()])
            ->sortBy(fn (array $shape): string => json_encode($shape, JSON_THROW_ON_ERROR))->values()->all();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inSavepoint(callable $work): mixed
    {
        DB::beginTransaction();
        try {
            return $work();
        } finally {
            DB::rollBack();
        }
    }

    // ---- the world ----

    private function build(string $world, int $seed): void
    {
        $slug = 'synthetic-sequence-'.crc32($world).'-'.$seed;
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic sequence', 'slug' => $slug]);
        $this->owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic sequence client', 'slug' => $slug.'-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic sequence project']);
        $terms = self::WORLDS[$world];
        $this->agreement = ClientAgreement::query()->create($terms + [
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic agreement', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => self::RATE,
            'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
        $this->hourly = $terms['retainer_minutes'] === 0;
        $this->periods = ($terms['billing_cadence'] ?? 'monthly') === 'quarterly'
            ? ['2026-04-01', '2026-07-01', '2026-10-01']
            : ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'];
        $this->onePeriodMore = ($terms['billing_cadence'] ?? 'monthly') === 'quarterly' ? '2027-01-01' : '2026-09-01';

        foreach (range(1, 9) as $_) {
            $this->logHours();
        }
    }

    private function hours(string $day, int $minutes, bool $deferred): int
    {
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => $this->owner->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => $deferred, 'billing_rate_amount' => self::RATE, 'currency' => 'USD',
        ]);
        $this->loggedMinutes += $minutes;

        return (int) $entry->id;
    }
}
