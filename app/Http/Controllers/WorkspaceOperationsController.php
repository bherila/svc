<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentMetadata;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentSharingGate;
use App\Services\Files\AttachmentSharingPresenter;
use App\Services\Files\AttachmentSharingView;
use App\Support\Access\StaffRole;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\BillingScheduleReadPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceOperationsController extends Controller
{
    public function __construct(private readonly BillingScheduleReadPresenter $schedules) {}

    public function __invoke(Request $request, Workspace $workspace): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()?->id)
            ->first();
        abort_unless($membership instanceof WorkspaceMembership, 403);
        $canManage = StaffRole::tryFrom($membership->role)?->managesWorkspace() === true;

        $companies = $workspace->clientCompanies()
            ->with(['projects' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();
        $companyIds = $companies->pluck('id')->all();
        $projectIds = $companies
            ->flatMap(fn (ClientCompany $company): array => $company->projects->modelKeys())
            ->all();

        $timeEntriesByProject = $projectIds === []
            ? collect()
            // The ranked derived table already applies ClientTimeEntry's soft-delete
            // scope. Applying it a second time to the outer query would qualify
            // `client_time_entries.deleted_at`, but that table is named
            // `ranked_time_entries` at that level.
            : ClientTimeEntry::withoutGlobalScopes()
                ->fromSub(
                    ClientTimeEntry::query()
                        ->select('client_time_entries.*')
                        ->selectRaw(
                            'ROW_NUMBER() OVER (PARTITION BY client_project_id ORDER BY worked_on DESC, id DESC) AS operation_row_number',
                        )
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('client_project_id', $projectIds),
                    'ranked_time_entries',
                )
                ->where('operation_row_number', '<=', 25)
                ->orderByDesc('worked_on')
                ->orderByDesc('id')
                ->get()
                ->groupBy('client_project_id');

        $proposals = $companyIds === []
            ? collect()
            : ClientProposal::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->with('items')
                ->latest('id')
                ->get();
        $proposalsByCompany = $proposals->groupBy('client_company_id');

        $agreements = $companyIds === []
            ? collect()
            : ClientAgreement::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->latest('id')
                ->get();
        $agreementsByCompany = $agreements->groupBy('client_company_id');

        $schedules = $companyIds === []
            ? collect()
            : ClientBillingSchedule::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->with('agreement')
                ->latest('id')
                ->get();
        $schedulesByCompany = $schedules->groupBy('client_company_id');

        $invoices = $companyIds === []
            ? collect()
            : ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('client_company_id', $companyIds)
                ->latest('id')
                ->get();
        $invoicesByCompany = $invoices->groupBy('client_company_id');

        $activitiesByCompany = $companyIds === []
            ? collect()
            : ClientCompanyActivity::query()
                ->fromSub(
                    ClientCompanyActivity::query()
                        ->select('client_company_activity.*')
                        ->selectRaw(
                            'ROW_NUMBER() OVER (PARTITION BY client_company_id ORDER BY created_at DESC, id DESC) AS activity_row_number',
                        )
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('client_company_id', $companyIds),
                    'ranked_company_activity',
                )
                ->with('actor:id,name')
                ->where('activity_row_number', '<=', 100)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('client_company_id');

        $attachmentRecordIds = [
            'proposal' => $proposals->pluck('public_id')->all(),
            'agreement' => $agreements->pluck('public_id')->all(),
            'invoice' => $invoices->pluck('public_id')->all(),
        ];
        $attachments = collect();
        $filesNextHref = null;
        $filesPreviousHref = null;
        $filePage = $request->integer('files_page', 1);
        abort_unless($filePage >= 1 && $filePage <= 1_000_000, 422);

        if (collect($attachmentRecordIds)->contains(fn (array $recordIds): bool => $recordIds !== [])) {
            $fileRows = app(AttachmentReader::class)->queryForTypes($actor, $workspace, AttachmentAudience::Native, ['proposal', 'agreement', 'invoice'])
                ->select('client_attachments.*')
                ->where('workspace_id', $workspace->id)
                ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
                ->where(function ($query) use ($attachmentRecordIds): void {
                    $query
                        ->where(fn ($query) => $query
                            ->where('record_type', 'proposal')
                            ->whereIn('record_public_id', $attachmentRecordIds['proposal']))
                        ->orWhere(fn ($query) => $query
                            ->where('record_type', 'agreement')
                            ->whereIn('record_public_id', $attachmentRecordIds['agreement']))
                        ->orWhere(fn ($query) => $query
                            ->where('record_type', 'invoice')
                            ->whereIn('record_public_id', $attachmentRecordIds['invoice']));
                })
                ->orderByDesc('id')->simplePaginate(300, ['*'], 'files_page', $filePage)
                ->withPath(route('workspaces.operations', $workspace, absolute: false))->fragment('files-pagination');
            $attachments = $fileRows->getCollection()->groupBy(fn (ClientAttachment $attachment): string => $attachment->record_type.':'.$attachment->record_public_id);
            $filesNextHref = $fileRows->nextPageUrl();
            $filesPreviousHref = $fileRows->previousPageUrl();
        }
        $attachmentPayload = fn (string $type, string $recordPublicId): array => ($attachments->get($type.':'.$recordPublicId) ?? collect())
            ->map(fn (ClientAttachment $attachment): array => [
                'id' => $attachment->public_id,
                'name' => $attachment->original_filename,
                'media_type' => $attachment->media_type,
                'bytes' => $attachment->bytes,
                'download_url' => "/workspaces/{$workspace->public_id}/attachments/{$attachment->public_id}",
            ])->values()->all();

        $parents = ['proposal' => $proposals->keyBy('public_id'), 'agreement' => $agreements->keyBy('public_id'), 'invoice' => $invoices->keyBy('public_id')];
        $visible = [];
        if ($canManage && AttachmentSharingGate::available()) {
            foreach (array_keys($parents) as $type) {
                /** @var list<string> $ids Scoped character UUID columns from the authorized file window. */
                $ids = array_values($attachments->flatten(1)->where('record_type', $type)->pluck('record_public_id')->unique()->all());
                $visible[$type] = array_fill_keys(app(AttachmentReader::class)->visibleParentIds($actor, $workspace, $type, $ids), true);
            }
        }
        $filePanel = function (string $type, string $id) use ($attachments, $parents, $visible, $canManage, $workspace): array {
            $parent = $parents[$type]->get($id);
            $view = new AttachmentSharingView(new AttachmentParentReference($type, $id), $parent, isset($visible[$type][$id]), $canManage);
            $rows = $attachments->get($type.':'.$id) ?? collect();

            return ['files' => $rows->map(fn (ClientAttachment $file): array => AttachmentMetadata::native($file,
                route('svc.files.download', [$workspace, $file->public_id], absolute: false),
                $canManage ? route('agent-api.v1.attachments.destroy', [$workspace, $file->public_id], absolute: false) : null,
                app(AttachmentSharingPresenter::class)->nativeControl($file, $view, route('agent-api.v1.attachments.visibility.update', [$workspace, $file->public_id], absolute: false), AttachmentAudience::Internal)))->values()->all(),
                'upload' => $canManage ? ['href' => route('agent-api.v1.attachments.upload-url', ['workspace' => $workspace, 'recordType' => $type, 'recordPublicId' => $id], absolute: false), 'parent_version' => AgentApiVersion::for($parent)] : null, 'next_href' => null];
        };

        $clients = [];

        foreach ($companies as $company) {
            $projects = [];

            foreach ($company->projects as $project) {
                $projects[] = [
                    'id' => $project->public_id,
                    'name' => $project->name,
                    'time_url' => route('clients.time', [$workspace, $company, 'log_project' => $project->public_id], absolute: false),
                    'time_entries' => ($timeEntriesByProject->get($project->id) ?? collect())
                        ->map(fn (ClientTimeEntry $entry): array => [
                            'id' => $entry->public_id,
                            'worked_on' => $entry->worked_on->toDateString(),
                            'minutes' => $entry->minutes,
                            'description' => $entry->description,
                            'status' => $entry->status,
                            'is_billable' => $entry->is_billable,
                            'is_deferred' => $entry->is_deferred,
                        ])
                        ->all(),
                ];
            }

            $clients[] = [
                'id' => $company->public_id,
                'name' => $company->name,
                'version' => AgentApiVersion::for($company),
                'create_proposal_href' => $canManage ? route('agent-api.v1.proposals.store', [$workspace], absolute: false) : null,
                'projects' => $projects,
                'proposals' => ($proposalsByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientProposal $proposal): array => [
                        'id' => $proposal->public_id,
                        'title' => $proposal->title,
                        'version' => AgentApiVersion::for($proposal),
                        'send_href' => $canManage && $proposal->status === 'draft' ? route('agent-api.v1.proposals.send', [$workspace, $proposal->public_id], absolute: false) : null,
                        'status' => $proposal->status,
                        'total_amount' => $proposal->totalAmount(),
                        'currency' => $proposal->currency,
                        'attachments' => $attachmentPayload('proposal', $proposal->public_id),
                        'attachment_panel' => $filePanel('proposal', $proposal->public_id),
                    ])
                    ->all(),
                'agreements' => ($agreementsByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientAgreement $agreement): array => [
                        'id' => $agreement->public_id,
                        'title' => $agreement->title,
                        'version' => AgentApiVersion::for($agreement),
                        'activate_href' => $canManage && $agreement->status === 'draft' ? route('agent-api.v1.agreements.activate', [$workspace, $agreement->public_id], absolute: false) : null,
                        'create_schedule_href' => $canManage && $agreement->status === 'active' && ! $schedules->contains('client_agreement_id', $agreement->id) ? route('agent-api.v1.billing-schedules.store', [$workspace], absolute: false) : null,
                        'status' => $agreement->status,
                        'billing_cadence' => $agreement->billing_cadence,
                        'currency' => $agreement->currency,
                        'attachments' => $attachmentPayload('agreement', $agreement->public_id),
                        'attachment_panel' => $filePanel('agreement', $agreement->public_id),
                    ])
                    ->all(),
                'billing_schedules' => ($schedulesByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientBillingSchedule $schedule): array => [...$this->schedules->present($schedule), 'generate_href' => $canManage && $schedule->is_active ? route('agent-api.v1.billing-schedules.generate', [$workspace, $schedule->public_id], absolute: false) : null])
                    ->all(),
                'invoices' => ($invoicesByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientInvoice $invoice): array => [
                        'id' => $invoice->public_id,
                        'invoice_number' => $invoice->invoice_number,
                        'status' => $invoice->status,
                        'total_amount' => $invoice->total_amount,
                        'paid_amount' => $invoice->paid_amount,
                        'balance_amount' => $invoice->balance_amount,
                        'currency' => $invoice->currency,
                        // What the record-payment form measures a payment date
                        // against: a payment dated before the invoice existed
                        // is legitimate and is warned about rather than
                        // refused, and the screen cannot warn about a date it
                        // was never told.
                        'issue_date' => $invoice->issue_date?->toDateString(),
                        'due_date' => $invoice->due_date?->toDateString(),
                        'attachments' => $attachmentPayload('invoice', $invoice->public_id),
                        'attachment_panel' => $filePanel('invoice', $invoice->public_id),
                    ])
                    ->all(),
                'activities' => ($activitiesByCompany->get($company->id) ?? collect())
                    ->map(fn (ClientCompanyActivity $activity): array => [
                        'id' => $activity->public_id,
                        'action' => $activity->action,
                        'actor_name' => $activity->actor?->name,
                        'subject_type' => $activity->subject_type,
                        'subject_id' => $activity->subject_public_id,
                        'payload' => $activity->payload ?? [],
                        'correction_url' => $canManage && $activity->action === 'invoice.corrected'
                            ? ($activity->payload['correction_url'] ?? null) : null,
                        'created_at' => $activity->created_at?->toISOString(),
                    ])
                    ->all(),
            ];
        }

        return Inertia::render('operations', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'clients' => $clients,
                'files_next_href' => $filesNextHref,
                'files_previous_href' => $filesPreviousHref,
                // The browser's calendar is not the workspace's, and neither
                // is UTC's. Defaulting a date from `toISOString()` gives UTC's
                // day, which the write validators - bounded by the workspace's
                // own window - refuse for the hours the two disagree. The
                // calendar travels rather than a date, because this page can
                // sit open past its own midnight.
                'timezone' => $workspace->timezone,
            ],
        ]);
    }
}
