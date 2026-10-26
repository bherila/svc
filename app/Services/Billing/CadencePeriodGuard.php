<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Lock and check one cadence period of an agreement before its draft is
 * generated: the guard invoices.generate_period, invoices.preview_period and
 * the scheduled `svc:billing:generate-cadence-drafts` share, so a preview
 * refuses exactly what generation would and the scheduler can create nothing
 * an operator could not. The caller holds the transaction.
 */
final class CadencePeriodGuard
{
    public function __construct(private readonly BillingCycleResolver $cycles, private readonly WorkspaceClock $clock) {}

    /** @return array{ClientCompany, ClientAgreement, Carbon} */
    public function lock(Workspace $workspace, string $agreement, string $periodStart, ?string $expectedVersion): array
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
        $claims = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('client_agreement_id', $record->id)
            ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->where(function ($period) use ($cycle): void {
                $period->whereDate('cycle_start', $cycle->start->toDateString())->orWhere(function ($legacy) use ($cycle): void {
                    $legacy->whereNull('cycle_start')->whereDate('service_period_end', $cycle->start->copy()->subDay()->toDateString());
                });
            })->tap(Locks::forUpdate())->get(['id', 'status']);
        abort_if($claims->contains(static fn (ClientInvoice $claim): bool => InvoiceStatus::claimsPeriod($claim->status)), 409, 'A non-void invoice already exists for this cadence period. Read or regenerate that draft instead.');

        return [$company, $record, $start];
    }
}
