<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\MissingBilledOverageAuditor;
use App\Services\Billing\OpeningRolloverAuditor;
use App\Services\Billing\ScheduleGenerationPreflight;
use App\Services\Billing\UndatedCollectibleInvoiceAuditor;
use App\Services\Billing\UnplaceableInvoiceAuditor;
use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Tenant-scoped presentation of the canonical billing data-quality audits.
 *
 * The underlying auditors are also used by the operator commands. This layer
 * deliberately returns their aggregate-only DTO arrays, never invoice rows or
 * identifiers, so an MCP caller cannot turn a diagnostic into an invoice
 * export.
 */
final class AgentBillingAuditReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly UnplaceableInvoiceAuditor $unplaceable,
        private readonly UndatedCollectibleInvoiceAuditor $undatedCollectible,
        private readonly MissingBilledOverageAuditor $missingBilledOverage,
        private readonly OpeningRolloverAuditor $openingRollover,
        private readonly ScheduleGenerationPreflight $schedulePreflight,
        private readonly UnlinkedProposalAgreementAuditor $unlinkedProposalAgreements,
    ) {}

    /** @return array<string, float|int> */
    public function unplaceableInvoices(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->unplaceable->count($workspace)->toArray();
    }

    /**
     * @return array{
     *     invoices: int,
     *     collectible: int,
     *     undated: int,
     *     with_an_issue_date: int,
     *     without_an_issue_date: int,
     *     would_become_overdue_if_backfilled: int,
     *     undated_balances: array<string, int>,
     *     would_become_overdue_balances: array<string, int>,
     * }
     */
    public function undatedCollectibleInvoices(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->undatedCollectible->count($workspace)->toArray();
    }

    /** @return array<string, int> */
    public function missingBilledOverage(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->missingBilledOverage->count($workspace)->toArray();
    }

    /** @return array<string, int> */
    public function openingRollover(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->openingRollover->count($workspace)->toArray();
    }

    /**
     * The schedule-generation preflight for this workspace's due periods, as
     * `svc:billing:preflight-schedule-generation` reports it for every
     * workspace: counts only, and `complete` false when a backlog was longer
     * than the preflight examined.
     *
     * @return array<string, mixed>
     */
    public function scheduleGenerationPreflight(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->schedulePreflight->run($workspace)->toArray();
    }

    /** @return array<string, int> */
    public function unlinkedProposalAgreements(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->unlinkedProposalAgreements->count($workspace)->toArray();
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientInvoice::class);
        }
    }
}
