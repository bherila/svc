<?php

namespace App\Queries\ClientHome;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\Workspace;
use App\Support\ClientHome\InvoiceSummary;

/**
 * The parts of the client overview that are not revenue figures (docs/ux-overhaul.md
 * section 4, and the client-owned pieces of the retired Operations screen):
 * recent invoices, the client's projects with a way to log time on each, and
 * its proposals.
 *
 * For owners and admins only; the controller does not call it for anyone else.
 * Every read is keyed by the workspace and the company, and bounded by a
 * constant, so the screen costs three queries whatever the engagement's size.
 */
final class ClientOverviewQuery
{
    public const int RECENT_INVOICES = 5;

    public const int PROJECTS = 12;

    public const int PROPOSALS = 5;

    /**
     * @return array{recent_invoices: list<array<string, mixed>>, projects: list<array<string, mixed>>, proposals: list<array<string, mixed>>}
     */
    public function for(Workspace $workspace, ClientCompany $company): array
    {
        return [
            'recent_invoices' => $this->recentInvoices($workspace, $company),
            'projects' => $this->projects($workspace, $company),
            'proposals' => $this->proposals($workspace, $company),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentInvoices(Workspace $workspace, ClientCompany $company): array
    {
        return array_values(ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_INVOICES)
            ->get()
            ->map(fn (ClientInvoice $invoice): array => InvoiceSummary::fromInvoice(
                $invoice,
                route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
            )->toArray())
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function projects(Workspace $workspace, ClientCompany $company): array
    {
        return array_values(ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::PROJECTS)
            ->get()
            ->map(fn (ClientProject $project): array => [
                'id' => (string) $project->public_id,
                'name' => (string) $project->name,
                'status' => (string) $project->status,
                'href' => route('clients.project', [$workspace, $company, $project], absolute: false),
                // The time sheet opens its log form on this project.
                'log_time_href' => route('clients.time', [$workspace, $company, 'log_project' => $project->public_id], absolute: false),
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function proposals(Workspace $workspace, ClientCompany $company): array
    {
        return array_values(ClientProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('id')
            ->limit(self::PROPOSALS)
            ->get()
            ->map(fn (ClientProposal $proposal): array => [
                'id' => (string) $proposal->public_id,
                'title' => (string) $proposal->title,
                'status' => (string) $proposal->status,
                'sent_at' => $proposal->sent_at?->toDateString(),
                'href' => route('clients.proposal', [$workspace, $company, $proposal], absolute: false),
            ])
            ->all());
    }
}
