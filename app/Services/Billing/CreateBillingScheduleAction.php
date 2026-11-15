<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingPeriod;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared creation boundary for website and API billing schedules. */
final class CreateBillingScheduleAction
{
    /**
     * How far before today a new schedule's first period may start.
     *
     * Generation bills every due period from `next_run_on` forward, so the
     * first run of a schedule created years in the past issues years of
     * invoices (and queues their client emails). Two years is one
     * {@see BillingScheduleService::GENERATION_BATCH_LIMIT} of monthly periods:
     * enough to catch up a schedule set up late, while an older backlog is
     * billed deliberately rather than by a date typed into a form.
     */
    public const int MAX_BACKDATE_YEARS = 2;

    public function __construct(
        private readonly AgreementHandoverRepository $handovers = new AgreementHandoverRepository,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Workspace $workspace, ClientCompany $company, array $data, ?string $expectedVersion = null): ClientBillingSchedule
    {
        abort_unless($company->workspace_id === $workspace->id, 404);

        return DB::transaction(function () use ($workspace, $company, $data, $expectedVersion): ClientBillingSchedule {
            $agreement = ClientAgreement::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->where('public_id', $data['client_agreement'])
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($expectedVersion !== null) {
                abort_unless(AgentApiVersion::matches($agreement, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
            }
            abort_if(ClientBillingSchedule::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $agreement->id)->exists(), 409, 'This agreement already has a billing schedule; read it before generating invoices.');

            $nextRun = $data['next_run_on'] ?? null;
            $cadence = $data['cadence'] ?? null;
            if (! is_string($nextRun) || ! is_string($cadence)) {
                throw new \DomainException('The billing schedule needs a next run date and cadence.');
            }
            $this->assertFirstRunInRange($workspace, $agreement, CarbonImmutable::parse($nextRun));
            $period = BillingPeriod::beginningAt(CarbonImmutable::parse($nextRun), $cadence);
            $this->handovers->forAgreement($agreement)?->assertClear($period->start->toDateString(), $period->end->toDateString());

            try {
                // An earlier consistent read can retain a stale REPEATABLE READ
                // snapshot even after the agreement lock. Let the unique key
                // arbitrate competing creates, and roll back the insert's
                // savepoint before handling a PostgreSQL constraint failure.
                return DB::transaction(fn (): ClientBillingSchedule => ClientBillingSchedule::query()->create([
                    ...Arr::only($data, ['cadence', 'anchor_month', 'anchor_day', 'next_run_on', 'due_days', 'currency', 'is_active', 'line_template']),
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $company->id,
                    'client_agreement_id' => $agreement->id,
                ]));
            } catch (UniqueConstraintViolationException $collision) {
                // SQLite reports columns; MariaDB and PostgreSQL name the key.
                $constraint = $collision->index ?? $collision->columns;
                if ($constraint !== 'billing_schedule_agreement_unique' && $constraint !== ['workspace_id', 'client_agreement_id']) {
                    throw $collision;
                }

                abort(409, 'This agreement already has a billing schedule; read it before generating invoices.');
            }
        });
    }

    /**
     * Refuse a first period before the agreement starts, or further back than
     * {@see self::MAX_BACKDATE_YEARS}. The same boundary for the website and
     * the API, so neither can backdate past the other.
     *
     * @throws ValidationException
     */
    private function assertFirstRunInRange(Workspace $workspace, ClientAgreement $agreement, CarbonImmutable $nextRun): void
    {
        $startsOn = $agreement->starts_on->toDateString();
        if ($nextRun->toDateString() < $startsOn) {
            throw ValidationException::withMessages(['next_run_on' => "The first billing period cannot start before the agreement starts on {$startsOn}."]);
        }
        $earliest = $this->clock->today($workspace)->subYearsNoOverflow(self::MAX_BACKDATE_YEARS)->toDateString();
        if ($nextRun->toDateString() < $earliest) {
            throw ValidationException::withMessages(['next_run_on' => 'The first billing period cannot start more than '.self::MAX_BACKDATE_YEARS." years ago (before {$earliest}). Bill an older backlog with individual invoices."]);
        }
    }
}
