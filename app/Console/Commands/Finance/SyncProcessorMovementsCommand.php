<?php

namespace App\Console\Commands\Finance;

use App\Console\Commands\MutationCommand;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\StripeGateway;
use App\Services\Finance\SquareProcessorMovementSync;
use App\Services\Finance\StripeProcessorMovementSync;
use App\Services\Square\SquareConfiguration;
use App\Support\Finance\ProcessorReadBudget;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bounded synchronous provider reads; preview is the default and reports contain counts only.
 *
 * @phpstan-type MovementCounts array{records_read: int, created: int, unchanged: int, enriched: int, conflict: int, deferred: int, ignored: int, refused: int, note: string|null}
 */
final class SyncProcessorMovementsCommand extends MutationCommand
{
    protected $signature = 'svc:finance:sync-processor-movements
        {--provider=all : all, stripe or square}
        {--workspace= : Only this workspace public UUID}
        {--payment= : Only this Stripe payment public UUID; requires --provider=stripe}
        {--limit=50 : Records per provider, from 1 to 200}
        {--max-calls=200 : Shared provider-call budget, from 1 to 1000}
        {--since= : Square payout creation lower bound at UTC midnight, YYYY-MM-DD; default now minus 30 days}
        {--until= : Square payout creation upper bound at UTC midnight, YYYY-MM-DD; default now}
        {--apply : Record movements and recover missed Stripe dispute state; otherwise preview}
        {--format=text : Output text or json}';

    protected $description = 'Synchronously read signed processor movements with bounded, resumable history';

    private const string LOCK = 'finance:processor-movements:sync';

    public function handle(StripeGateway $gateway, StripeProcessorMovementSync $stripe, SquareProcessorMovementSync $square, WorkspaceClock $clock): int
    {
        $apply = $this->applying();
        try {
            $provider = (string) $this->option('provider');
            if (! in_array($provider, ['all', 'stripe', 'square'], true)) {
                throw new DomainException;
            }
            $limit = $this->boundedOption('limit', 200);
            $maxCalls = $this->boundedOption('max-calls', 1000);
            $now = $clock->now('UTC');
            $since = $this->day('since') ?? $now->subDays(30);
            $until = $this->day('until') ?? $now;
            if ($since->greaterThan($until)) {
                throw new DomainException;
            }
            $workspace = null;
            if ($this->option('workspace') !== null || $this->input->hasParameterOption('--workspace')) {
                $id = (string) $this->option('workspace');
                $workspace = Str::isUuid($id) ? $this->workspaceByPublicId($id) : null;
                if ($workspace === null) {
                    throw new DomainException;
                }
            }
            $payment = null;
            if ($this->option('payment') !== null || $this->input->hasParameterOption('--payment')) {
                $id = (string) $this->option('payment');
                if ($provider !== 'stripe' || ! Str::isUuid($id)) {
                    throw new DomainException;
                }
                $payment = $this->payments($workspace)->where('public_id', $id)->with('workspace')->first();
                if ($payment === null) {
                    throw new DomainException;
                }
            }
        } catch (Throwable) {
            return $this->finish($apply, false, [], 0, 'invalid_options', self::INVALID);
        }

        // One operator's scheduled and manual runs use the same cheap cache
        // mutex. Provider reads have a 600-second deadline; persistence can
        // finish later and remains idempotent under existing database row locks.
        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            return $this->finish($apply, false, [], 0, 'already_running', self::FAILURE);
        }
        try {
            $budget = new ProcessorReadBudget($maxCalls);
            $providers = [];
            if ($provider !== 'square') {
                $providers['stripe'] = $this->stripe($gateway, $stripe, $workspace, $payment, $limit, $budget, $apply);
            }
            if ($provider !== 'stripe') {
                $providers['square'] = $this->square($square, $workspace, $since, $until, $limit, $budget, $apply);
            }
            $completed = true;
            foreach ($providers as $counts) {
                if (! in_array($counts['note'], [null, 'not_configured', 'different_workspace'], true)) {
                    $completed = false;
                }
            }

            return $this->finish($apply, $completed, $providers, $budget->used(), null, $completed ? self::SUCCESS : self::FAILURE);
        } finally {
            $lock->release();
        }
    }

    /** @return MovementCounts */
    private function stripe(StripeGateway $gateway, StripeProcessorMovementSync $sync, ?Workspace $workspace, ?ClientInvoicePayment $payment, int $limit, ProcessorReadBudget $budget, bool $apply): array
    {
        $counts = $this->counts();
        if (! in_array($gateway->mode(), ['test', 'live'], true)) {
            $counts['note'] = 'not_configured';

            return $counts;
        }
        $cursorKey = 'finance:processor-movements:stripe:last-attempted:'.($workspace === null ? 'all' : 'workspace-'.$workspace->id);
        try {
            $position = Cache::get($cursorKey, ['last' => 0, 'incomplete' => false]);
            $last = is_array($position) && is_int($position['last'] ?? null) && $position['last'] >= 0 ? $position['last'] : 0;
            $cycleIncomplete = $payment === null && is_array($position) && ($position['incomplete'] ?? false) === true;
            $rows = $payment !== null ? collect([$payment]) : $this->payments($workspace)
                ->where('id', '>', $last)->with('workspace')->orderBy('id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit;
            if ($more) {
                $rows->pop();
            }
            foreach ($rows as $row) {
                try {
                    $result = $sync->sync($row->workspace, $row->id, $apply, $budget, recoverDisputes: true);
                    $counts['records_read']++;
                    $this->tally($counts, $result);
                    if ($result['deferred'] > 0 || $result['conflict'] > 0) {
                        $cycleIncomplete = true;
                        $counts['note'] ??= 'needs_review';
                    }
                } catch (Throwable) {
                    if ($budget->refused()) {
                        // The current record did not finish its bounded read.
                        // Leave its position pending; a normal final call that
                        // merely used the remaining allowance is not a denial.
                        $counts['note'] = 'sync_incomplete';

                        return $counts;
                    }
                    $counts['records_read']++;
                    $counts['refused']++;
                    $cycleIncomplete = true;
                    $counts['note'] = 'sync_incomplete';
                }
                // A held or record-specific refused payment must not starve
                // later known rows. Revisit it when this bounded cycle wraps.
                if ($apply && $payment === null && ! Cache::forever($cursorKey, ['last' => $row->id, 'incomplete' => $cycleIncomplete])) {
                    throw new DomainException;
                }
            }
            if ($cycleIncomplete) {
                $counts['note'] ??= 'needs_review';
            }
            if ($payment === null && $more) {
                $counts['note'] ??= 'record_limit';
            } elseif ($apply && $payment === null && ! Cache::forever($cursorKey, ['last' => 0, 'incomplete' => false])) {
                throw new DomainException;
            }
        } catch (Throwable) {
            // Provider exceptions can include private source fields. Neither
            // their text nor the selected record's identity is printed/logged.
            $counts['note'] = 'sync_incomplete';
        }

        return $counts;
    }

    /** @return MovementCounts */
    private function square(SquareProcessorMovementSync $sync, ?Workspace $only, CarbonImmutable $since, CarbonImmutable $until, int $limit, ProcessorReadBudget $budget, bool $apply): array
    {
        $counts = $this->counts();
        $configuration = SquareConfiguration::fromConfig();
        $workspace = $configuration->workspace();
        if ($workspace === null || ($only !== null && $workspace->id !== $only->id)) {
            $counts['note'] = $workspace === null ? 'not_configured' : 'different_workspace';

            return $counts;
        }
        try {
            $selection = json_encode([$this->option('since'), $this->option('until')], JSON_THROW_ON_ERROR);
            $scan = $sync->continuePayouts($workspace, $since, $until, $selection, $apply, $budget);
            foreach ($scan as $result) {
                $counts['records_read']++;
                $this->tally($counts, $result);
                if ($result['deferred'] > 0 || $result['conflict'] > 0) {
                    $counts['note'] = 'needs_review';
                }
                // Stop before advancing the generator: obtaining another
                // yielded result would already read and apply that payout.
                // Even an exact-cap end is conservatively incomplete here.
                if ($counts['records_read'] >= $limit) {
                    $counts['note'] ??= 'record_limit';

                    return $counts;
                }
            }
            if ($scan->getReturn()) {
                $counts['note'] = 'needs_review';
            }
        } catch (Throwable) {
            $counts['note'] = 'sync_incomplete';
        }

        return $counts;
    }

    /** @return Builder<ClientInvoicePayment> */
    private function payments(?Workspace $workspace): Builder
    {
        return ClientInvoicePayment::query()->where('provider', 'stripe')
            ->whereNotNull('provider_payment_identifier')->where('provider_payment_identifier', '!=', '')
            ->when($workspace !== null, static fn (Builder $payments) => $payments->where('workspace_id', $workspace->id));
    }

    /** @return MovementCounts */
    private function counts(): array
    {
        return ['records_read' => 0, 'created' => 0, 'unchanged' => 0, 'enriched' => 0, 'conflict' => 0, 'deferred' => 0, 'ignored' => 0, 'refused' => 0, 'note' => null];
    }

    /**
     * @param  MovementCounts  $counts
     * @param  array<string, int>  $result
     */
    private function tally(array &$counts, array $result): void
    {
        foreach (['created', 'unchanged', 'enriched', 'conflict', 'deferred', 'ignored'] as $key) {
            $counts[$key] += $result[$key] ?? 0;
        }
    }

    private function boundedOption(string $name, int $maximum): int
    {
        $value = $this->option($name);
        if (! is_scalar($value)) {
            throw new DomainException;
        }
        $value = (string) $value;
        if (preg_match('/^[0-9]+$/D', $value) !== 1 || strlen($value) > 4 || (int) $value < 1 || (int) $value > $maximum) {
            throw new DomainException;
        }

        return (int) $value;
    }

    private function day(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);
        if ($value === null && ! $this->input->hasParameterOption('--'.$name)) {
            return null;
        }
        if (! is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            throw new DomainException;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if (! $date instanceof CarbonImmutable || $date->year < 1000 || $date->toDateString() !== $value) {
            throw new DomainException;
        }

        return $date;
    }

    /** @param array<string, MovementCounts> $providers */
    private function finish(bool $apply, bool $completed, array $providers, int $calls, ?string $reason, int $exit): int
    {
        if ($this->option('format') === 'json') {
            $this->json(['applied' => $apply, 'completed' => $completed, 'calls_used' => $calls, 'reason' => $reason, 'providers' => $providers]);
        } else {
            foreach ($providers as $provider => $counts) {
                $this->components->twoColumnDetail($provider, (string) ($counts['note'] ?? 'complete'));
                foreach ($counts as $key => $value) {
                    if ($key !== 'note') {
                        $this->components->twoColumnDetail('  '.$key, (string) $value);
                    }
                }
            }
            $this->components->info($apply ? 'Applied completed records.' : 'Preview: no movement or scan position writes.');
            if (! $completed) {
                $this->components->warn($reason ?? 'Incomplete: retry bounded batches; review deferred or conflicting facts.');
            }
        }

        return $exit;
    }
}
