<?php

namespace App\Http\Controllers\Expenses;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Files\AttachmentListing;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseReceiptController extends Controller
{
    public function __invoke(Workspace $workspace, ClientCompany $clientCompany, string $expense, WorkspaceAuthorization $authorization): Response
    {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $record = ClientExpense::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('public_id', $expense)->firstOrFail();

        return Inertia::render('clients/expense-receipts', [
            'expense' => [
                'description' => $record->description,
                'spent_on' => $record->spent_on->toDateString(),
                'amount' => $record->amount,
                'currency' => $record->currency,
                'status' => $record->status,
            ],
            'files' => AttachmentListing::for($workspace, 'expense', $record->public_id, true),
            'upload_href' => route('svc.files.store', [$workspace, 'expense', $record->public_id], absolute: false),
        ]);
    }
}
