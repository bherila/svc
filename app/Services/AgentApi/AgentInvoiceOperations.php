<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\InvoiceKind;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Tenant-scoped orchestration; money and lifecycle decisions stay in the shared billing services. */
final class AgentInvoiceOperations
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentAccess $access,
        private readonly InvoiceCorrectionService $corrections,
        private readonly InvoiceFromTimeService $fromTime,
        private readonly ClientInvoicingService $cadence,
        private readonly BillingCycleResolver $cycles,
        private readonly WorkspaceClock $clock,
        private readonly AgentInvoicePresenter $presenter,
    ) {}

    /** @param array<string, mixed> $body */
    public function hold(User $actor, Workspace $workspace, string $client, string $key, string $invoice, array $body, bool $hold): ClientInvoice
    {
        $operation = $hold ? 'invoices.hold_delivery' : 'invoices.release_delivery';
        $ids = $this->run($operation, $actor, $workspace, $client, $key, ['invoice_id' => $invoice, 'body' => $body], function () use ($workspace, $invoice, $body, $hold): string {
            $data = $this->validate($body, ['expected_version' => ['required', 'string', 'size:64']] + ($hold ? [] : ['confirm' => ['required', new ExplicitConfirmation]]));
            $record = $this->invoice($workspace, $invoice);
            $check = static fn (ClientInvoice $locked) => abort_unless(AgentApiVersion::matches($locked, $data['expected_version']), 409, 'The invoice has changed; read it and retry.');
            $updated = $hold ? $this->corrections->hold($record, $workspace, $check) : $this->corrections->release($record, $workspace, $check);

            return $updated->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /** @param array<string, mixed> $body */
    public function addTime(User $actor, Workspace $workspace, string $client, string $key, string $invoice, array $body): ClientInvoice
    {
        $ids = $this->run('invoices.add_time', $actor, $workspace, $client, $key, ['invoice_id' => $invoice, 'body' => $body], function () use ($workspace, $invoice, $body): string {
            $data = $this->validate($body, ['expected_version' => ['required', 'string', 'size:64'], 'time_entry_ids' => ['required', 'array', 'min:1', 'max:100'], 'time_entry_ids.*' => ['required', 'uuid', 'distinct']]);

            return $this->fromTime->addTime($this->invoice($workspace, $invoice), $workspace, $data['expected_version'], array_values($data['time_entry_ids']))->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /** @param array<string, mixed> $body */
    public function generate(User $actor, Workspace $workspace, string $client, string $key, string $agreement, array $body): ClientInvoice
    {
        $ids = $this->run('invoices.generate_period', $actor, $workspace, $client, $key, ['agreement_id' => $agreement, 'body' => $body], function () use ($workspace, $agreement, $body): string {
            $data = $this->validate($body, ['expected_version' => ['required', 'string', 'size:64'], 'period_start' => ['required', 'date_format:Y-m-d'], 'confirm' => ['required', new ExplicitConfirmation]]);
            [$company, $record, $start] = $this->lockPeriod($workspace, $agreement, $data['period_start'], $data['expected_version']);

            return $this->cadence->generateCadencePeriod($company, $record, $start)->public_id;
        });

        return $this->invoice($workspace, $ids[0]);
    }

    /**
     * What invoices.generate_period would write for this period, written and
     * then rolled back.
     *
     * Not a second implementation of the cadence engine: the same checks under
     * the same locks, then the same `generateCadencePeriod()` call, read before
     * an unconditional rollback - the rehearsal command's method, narrowed to
     * one period. A prediction computed beside the engine would drift from it;
     * this cannot, because it is the engine. Nothing survives the rollback: no
     * invoice, line, allocation, number or activity, and no receipt or audit,
     * because a read takes no idempotency key.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function preview(User|AgentPrincipal $actor, Workspace $workspace, string $agreement, array $query): array
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
        $data = $this->validate($query, ['period_start' => ['required', 'date_format:Y-m-d']]);

        DB::beginTransaction();
        try {
            return $this->refusalsAsValidation(function () use ($workspace, $agreement, $data): array {
                [$company, $record, $start] = $this->lockPeriod($workspace, $agreement, $data['period_start'], null);
                // Read before generation, which is what a generate call
                // following this preview must present as expected_version.
                $version = AgentApiVersion::for($record);
                $before = (int) ClientInvoice::query()->where('workspace_id', $workspace->id)->max('id');
                $draft = $this->cadence->generateCadencePeriod($company, $record, $start);
                $others = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
                    ->where('id', '>', $before)->whereKeyNot($draft->id)->orderBy('id')->get();

                return $this->presenter->periodPreview($workspace, $company, $record, $version, $draft->refresh(), $others);
            });
        } finally {
            // Unconditional, exactly as in the rehearsal: a preview that
            // returned, refused or failed has written nothing.
            DB::rollBack();
        }
    }

    /**
     * Lock and check one cadence period of an agreement: the guard
     * invoices.generate_period and invoices.preview_period share, so a preview
     * refuses exactly what generation would. The caller holds the transaction.
     *
     * @return array{ClientCompany, ClientAgreement, Carbon}
     */
    private function lockPeriod(Workspace $workspace, string $agreement, string $periodStart, ?string $expectedVersion): array
    {
        BillingMaintenance::share($workspace->id);
        $record = ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $agreement)->tap(Locks::forUpdate())->firstOrFail();
        if ($expectedVersion !== null) {
            abort_unless(AgentApiVersion::matches($record, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
        }
        if ($record->status !== 'active' || ! $record->billsOnARecurringCadence()) {
            throw ValidationException::withMessages(['agreement_id' => 'Only active recurring agreements can generate cadence invoices.']);
        }
        $start = Carbon::parse($periodStart)->startOfDay();
        $earliest = $record->starts_on->copy();
        if ($record->effectiveBillingCadence() === BillingCadence::Monthly) {
            $earliest = $earliest->startOfMonth();
        }
        if ($start->toDateString() < $earliest->toDateString() || $record->starts_on->toDateString() > $this->clock->today($workspace)->toDateString()) {
            throw ValidationException::withMessages(['period_start' => 'Choose a cadence period after the agreement has begun.']);
        }
        $cycle = $this->cycles->cycleContaining($record, $start);
        if (! $cycle->start->isSameDay($start) || $cycle->end->toDateString() < $record->starts_on->toDateString() || $start->toDateString() > $this->clock->today($workspace)->toDateString()
            || ($record->ends_on !== null && $start->toDateString() > $record->ends_on->toDateString())) {
            throw ValidationException::withMessages(['period_start' => 'Choose the start of a cadence period that has begun during this agreement.']);
        }
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($record->client_company_id)->firstOrFail();
        $live = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('client_agreement_id', $record->id)
            ->where('status', '!=', 'void')->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->where(function ($period) use ($cycle): void {
                $period->whereDate('cycle_start', $cycle->start->toDateString())->orWhere(function ($legacy) use ($cycle): void {
                    $legacy->whereNull('cycle_start')->whereDate('service_period_end', $cycle->start->copy()->subDay()->toDateString());
                });
            })->tap(Locks::forUpdate())->first(['id']);
        abort_if($live !== null, 409, 'A non-void invoice already exists for this cadence period. Read or regenerate that draft instead.');

        return [$company, $record, $start];
    }

    /** @param array<string, mixed> $payload
     * @param Closure(): string $work
     * @return list<string> */
    private function run(string $operation, User $actor, Workspace $workspace, string $client, string $key, array $payload, Closure $work): array
    {
        $this->authorize($actor, $workspace);

        return $this->mutations->run($actor, $workspace, $client, $operation, $key, $payload, fn (): array => $this->refusalsAsValidation(static fn (): array => [$work()]), fn (array $ids) => $this->authorize($actor, $workspace));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function refusalsAsValidation(Closure $work): mixed
    {
        try {
            return $work();
        } catch (RuntimeException $refused) {
            // The legacy cadence engine uses plain RuntimeException for
            // its domain refusals. Database/adapter exception subclasses
            // must retain their real failure semantics.
            if ($refused::class !== RuntimeException::class) {
                throw $refused;
            }
            throw ValidationException::withMessages(['billing' => $refused->getMessage()]);
        }
    }

    /** @param array<string, mixed> $body
     * @param array<string, list<mixed>> $rules
     * @return array<string, mixed> */
    private function validate(array $body, array $rules): array
    {
        $fields = array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.'));
        $closed = ['body' => ['required', 'array:'.implode(',', $fields)]];
        foreach ($rules as $field => $rule) {
            $closed['body.'.$field] = $rule;
        }

        return Validator::make(['body' => $body], $closed)->validate()['body'];
    }

    private function authorize(User $actor, Workspace $workspace): void
    {
        abort_unless(AgentWriteCutover::invoices(), 404);
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    private function invoice(Workspace $workspace, string $id): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->with(['clientCompany' => fn ($companies) => $companies->where('workspace_id', $workspace->id)])->firstOrFail();
    }
}
