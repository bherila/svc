<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBillingScheduleRequest;
use App\Models\AgentPrincipal;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentBillingScheduleReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\CreateBillingScheduleAction;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\WorkspaceClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentBillingScheduleController extends Controller
{
    public function index(Request $request, Workspace $workspace, AgentBillingScheduleReadService $schedules): JsonResponse
    {
        if (in_array($request->query('is_active'), ['true', 'false'], true)) {
            $request->merge(['is_active' => $request->query('is_active') === 'true']);
        }
        $data = $request->validate(['is_active' => ['sometimes', 'nullable', 'boolean'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:2048']]);

        return response()->json($schedules->list($this->user($request), $workspace, isset($data['is_active']) ? (bool) $data['is_active'] : null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function show(Request $request, Workspace $workspace, string $schedule, AgentBillingScheduleReadService $schedules): JsonResponse
    {
        return response()->json(['data' => $schedules->get($this->user($request), $workspace, $schedule)]);
    }

    public function store(Request $request, Workspace $workspace, AgentAccess $access, CreateBillingScheduleAction $create, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'billing_schedules.create', $context->idempotencyKey, $request->all(), function () use ($request, $workspace, $access, $context, $create): array {
            abort_unless($access->isWorkspaceManager($context->user, $workspace), 403);
            $data = $request->validate([
                ...(new StoreBillingScheduleRequest)->rules(),
                'company_id' => ['required', 'uuid'],
                'expected_version' => ['required', 'string', 'size:64'],
                'next_run_on' => ['required', 'date_format:Y-m-d'],
                'line_template' => ['required', 'array', 'min:1', 'max:100'],
            ]);
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->firstOrFail();
            $record = $create->create($workspace, $company, $data, $data['expected_version']);

            return [$record->public_id];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));

        return response()->json(['data' => $this->mutation($this->schedule($workspace, $ids[0]))], 201);
    }

    public function generate(Request $request, Workspace $workspace, string $schedule, AgentAccess $access, BillingScheduleService $service, WorkspaceClock $clock, AgentInvoicePresenter $presenter, AgentMutationContextFactory $contexts, AgentMutationExecutor $mutations): JsonResponse
    {
        $context = $contexts->from($request);
        $ids = $mutations->run($context->user, $workspace, $context->oauthClientId, 'billing_schedules.generate', $context->idempotencyKey, ['schedule_id' => $schedule, 'body' => $request->all()], function () use ($request, $workspace, $schedule, $access, $context, $service, $clock): array {
            abort_unless($access->isWorkspaceManager($context->user, $workspace), 403);
            $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'confirm' => ['required', 'boolean', 'accepted']]);
            abort_unless($request->input('confirm') === true, 422, 'Confirm must be true.');
            $record = $this->schedule($workspace, $schedule);
            $invoices = $service->generateDue($record, $clock->today($workspace), $data['expected_version']);

            return [$record->public_id, ...array_map(static fn (ClientInvoice $invoice): string => $invoice->public_id, $invoices)];
        }, fn (array $ids) => abort_unless($access->isWorkspaceManager($context->user, $workspace), 403));
        $invoices = [];
        $records = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereIn('public_id', array_slice($ids, 1))->get()->keyBy('public_id');
        foreach (array_slice($ids, 1) as $id) {
            $invoice = $records->get($id);
            abort_unless($invoice instanceof ClientInvoice, 404);
            $invoices[] = $presenter->mutation($workspace, $invoice);
        }

        return response()->json(['data' => ['schedule' => $this->mutation($this->schedule($workspace, $ids[0])), 'invoices' => $invoices]]);
    }

    private function schedule(Workspace $workspace, string $id): ClientBillingSchedule
    {
        return ClientBillingSchedule::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->with(['agreement' => fn ($query) => $query->where('workspace_id', $workspace->id)])->firstOrFail();
    }

    private function user(Request $request): User|AgentPrincipal
    {
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);

        return $user;
    }

    /** @return array{id: string, version: string, next_run_on: string} */
    private function mutation(ClientBillingSchedule $schedule): array
    {
        return ['id' => $schedule->public_id, 'version' => AgentApiVersion::for($schedule), 'next_run_on' => $schedule->next_run_on->toDateString()];
    }
}
