<?php

namespace App\Services\Billing\Autopay;

use App\Exceptions\AutopayVersionConflict;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A provider's decision to skip autopay for one invoice (#636), recorded with
 * who and when. It is the invoice's one attempt row: once it exists the
 * charge command finds the key taken and never charges the invoice, and once a
 * charge was claimed there is nothing left to skip.
 */
final class AutopaySkipService
{
    public function __construct(
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @throws DomainException when the invoice cannot be skipped
     * @throws AutopayVersionConflict
     */
    public function skip(Workspace $workspace, ClientInvoice $invoice, User $actor, ?string $expectedVersion = null): InvoiceAutopayAttempt
    {
        return DB::transaction(function () use ($workspace, $invoice, $actor, $expectedVersion): InvoiceAutopayAttempt {
            $locked = ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($invoice->id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($expectedVersion !== null && ! AgentApiVersion::matches($locked, $expectedVersion)) {
                throw new AutopayVersionConflict('The invoice has changed; read it and retry.');
            }
            if (! in_array($locked->status, InvoiceStatus::collectible(), true) || $locked->balance_amount <= 0) {
                throw new DomainException('Only an invoice with a balance still to collect can have autopay skipped.');
            }
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($locked->client_company_id)->firstOrFail();
            if ($company->autopay_enabled_at === null) {
                throw new DomainException('Autopay is not on for this client, so there is nothing to skip.');
            }
            $existing = InvoiceAutopayAttempt::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_id', $locked->id)
                ->first();
            if ($existing !== null) {
                if ($existing->state === AutopayAttemptState::Skipped) {
                    return $existing;
                }
                throw new DomainException('Autopay already tried to charge this invoice, so it can no longer be skipped.');
            }

            $now = $this->clock->now($workspace)->utc();
            try {
                $attempt = InvoiceAutopayAttempt::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_invoice_id' => $locked->id,
                    'client_company_id' => $company->id,
                    'state' => AutopayAttemptState::Skipped,
                    'skipped_by_user_id' => $actor->id,
                    'skipped_at' => $now,
                    'claimed_at' => $now,
                    'resolved_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new AutopayVersionConflict('Autopay changed for this invoice while you were skipping it; read it and retry.');
            }
            $this->activities->record($workspace, $company, 'invoice.autopay_skipped', $locked, [], $actor, $attempt->public_id);

            return $attempt;
        });
    }
}
