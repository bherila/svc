<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Square\SquareLinkRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\CursorPage;
use App\Support\AgentApi\Presenters\SquareLinkPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Manager-only client company reads.
 *
 * A 404 rather than a 403 for a caller who is not a manager: a workspace member
 * learns nothing about a client record they cannot reach, including that it exists.
 */
final class AgentClientReadService
{
    private const string SQUARE_CUSTOMER = 'activeSquareCustomer';

    public function __construct(
        private readonly AgentAccess $access,
        private readonly SquareLinkRepository $squareLinks,
        private readonly SquareLinkPresenter $square,
    ) {}

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function list(User|AgentPrincipal $user, Workspace $workspace, ?string $status, int $limit, ?string $cursor): array
    {
        $this->requireManager($user, $workspace);
        $query = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id');
        if ($status !== null && $status !== '') {
            $query->where('is_active', $status === 'active');
        }

        return CursorPage::run(
            $workspace,
            'clients|status='.($status ?? ''),
            $limit,
            $cursor,
            fn (int $take, ?int $after): Collection => $this->withSquareCustomers($workspace, $query->when($after !== null, fn ($rows) => $rows->where('id', '>', $after))->limit($take)->get()),
            $this->present(...),
        );
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $user, Workspace $workspace, string $clientId): array
    {
        $this->requireManager($user, $workspace);

        return $this->present(ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $clientId)
            ->firstOrFail());
    }

    /** @return array<string, mixed> */
    public function present(ClientCompany $company): array
    {
        return [
            'id' => $company->public_id,
            'version' => AgentApiVersion::for($company),
            'name' => $company->name,
            'billing_email' => $company->billing_email,
            'is_active' => $company->is_active,
            'automatic_invoice_email_enabled' => $company->automatic_invoice_email_enabled,
            'automatic_invoice_email_delay_days' => $company->automatic_invoice_email_delay_days,
            'square_customer' => $this->square->customer($company->relationLoaded(self::SQUARE_CUSTOMER)
                ? $company->getRelation(self::SQUARE_CUSTOMER)
                : $this->squareLinks->activeCustomer($company)),
        ];
    }

    /**
     * A page's Square customers in one query rather than one per row.
     *
     * @param  Collection<int, ClientCompany>  $companies
     * @return Collection<int, ClientCompany>
     */
    private function withSquareCustomers(Workspace $workspace, Collection $companies): Collection
    {
        $mappings = $this->squareLinks->activeCustomers($workspace->id, array_values(array_map('intval', $companies->modelKeys())));
        foreach ($companies as $company) {
            $company->setRelation(self::SQUARE_CUSTOMER, $mappings->get($company->id));
        }

        return $companies;
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientCompany::class);
        }
    }
}
