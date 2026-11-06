<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Time waiting for approval, across clients: the workspace tier's Time.
 *
 * Owners and admins only. Approving still happens on each client's time sheet,
 * which carries the version checks and the invoice-selection rules; this is the
 * queue that says where to go. Workspace-scoped, one page, and a row whose
 * project or company is not this workspace's is dropped.
 */
final class WorkspaceTimeQueueController extends Controller
{
    public const int PER_PAGE = 50;

    public function __invoke(Request $request, Workspace $workspace, AgentAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);

        $page = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'draft')
            ->orderBy('worked_on')->orderBy('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        $entries = $page->getCollection();
        $companies = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $entries->pluck('client_company_id')->unique()->all())
            ->get(['id', 'public_id', 'name'])->keyBy('id');
        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $entries->pluck('client_project_id')->unique()->all())
            ->get(['id', 'public_id', 'name'])->keyBy('id');

        $rows = [];

        foreach ($entries as $entry) {
            $company = $companies->get($entry->client_company_id);
            $project = $projects->get($entry->client_project_id);

            if (! $company instanceof ClientCompany || ! $project instanceof ClientProject) {
                continue;
            }

            $rows[] = [
                'id' => $entry->public_id,
                'client' => $company->name,
                'project' => $project->name,
                'worked_on' => $entry->worked_on->toDateString(),
                'minutes' => $entry->minutes,
                'description' => $entry->description,
                'href' => route('clients.time', [$workspace, $company], absolute: false),
            ];
        }

        return Inertia::render('workspaces/time', [
            'entries' => $rows,
            'next_href' => $page->nextPageUrl(),
            'previous_href' => $page->previousPageUrl(),
        ]);
    }
}
