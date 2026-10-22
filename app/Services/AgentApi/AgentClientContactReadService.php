<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Collection;

/** Business contacts are private records, independent of portal accounts. */
final class AgentClientContactReadService
{
    public function __construct(private readonly AgentAccess $access) {}

    /** @return array<string, mixed> */
    public function listing(User|AgentPrincipal $actor, Workspace $workspace, string $companyId, ?string $status, int $limit, ?string $cursor): array
    {
        $company = $this->company($actor, $workspace, $companyId);
        $query = ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id);
        if ($status !== null) {
            $query->where('is_archived', $status === 'archived');
        }

        return CursorPage::run($workspace, 'business-contacts|client='.$companyId.'|status='.($status ?? ''), $limit, $cursor,
            fn (int $take, ?int $after): Collection => $query->when($after !== null, fn ($q) => $q->where('id', '>', $after))->orderBy('id')->limit($take)->get(),
            fn (ClientContact $contact): array => $this->present($company, $contact), AgentPageBudget::dataBytes());
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $companyId, string $contactId): array
    {
        $company = $this->company($actor, $workspace, $companyId);
        $contact = ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->where('public_id', $contactId)->firstOrFail();

        return $this->present($company, $contact);
    }

    public function company(User|AgentPrincipal $actor, Workspace $workspace, string $companyId): ClientCompany
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);

        return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $companyId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(ClientCompany $company, ClientContact $contact): array
    {
        return ['id' => $contact->public_id, 'company_id' => $company->public_id,
            'version' => AgentApiVersion::for($contact), 'first_name' => $contact->first_name,
            'last_name' => $contact->last_name, 'title' => $contact->title, 'email' => $contact->email,
            'phone' => $contact->phone, 'notes' => $contact->notes, 'is_archived' => $contact->is_archived];
    }
}
