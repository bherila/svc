<?php

namespace App\Http\Controllers\Expenses;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\WorkspaceAuthorization;
use App\Support\AgentApi\Presenters\AgentExpensePresenter;
use App\Support\Files\AttachmentListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseReceiptController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $expense, WorkspaceAuthorization $authorization): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $record = ClientExpense::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('public_id', $expense)->firstOrFail();

        $filePage = AttachmentListing::page($workspace, 'expense', $record->public_id, true, $actor, AttachmentAudience::Native,
            $request->integer('files_page', 1), route('svc.expenses.receipts', [$workspace, $clientCompany, $record->public_id], absolute: false));

        return Inertia::render('clients/expense-receipts', [
            'expense' => AgentExpensePresenter::summary($record),
            'files' => $filePage->files,
            'files_next_href' => $filePage->nextHref,
            'files_previous_href' => $filePage->previousHref,
            'upload_href' => route('svc.files.store', [$workspace, 'expense', $record->public_id], absolute: false),
        ]);
    }
}
