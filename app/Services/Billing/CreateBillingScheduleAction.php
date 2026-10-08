<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Shared creation boundary for website and API billing schedules. */
final class CreateBillingScheduleAction
{
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
}
