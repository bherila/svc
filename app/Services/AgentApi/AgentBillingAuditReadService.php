<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\MissingBilledOverageAuditor;
use App\Services\Billing\UndatedCollectibleInvoiceAuditor;
use App\Services\Billing\UnplaceableInvoiceAuditor;
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
    ) {}

    /** @return array<string, float|int> */
    public function unplaceableInvoices(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $this->requireManager($user, $workspace);

        return $this->unplaceable->count($workspace)->toArray();
    }

    /** @return array<string, array<string, int>|int> */
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

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientInvoice::class);
        }
    }
}
