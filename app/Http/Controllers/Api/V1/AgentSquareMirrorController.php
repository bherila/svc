<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Square\Mirror\SquareCandidateService;
use App\Services\Square\Mirror\SquareCustomerImporter;
use App\Services\Square\Mirror\SquareMirrorPresenter;
use App\Services\Square\Mirror\SquareMirrorReadService;
use App\Services\Square\Mirror\SquareMirrorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP adapter for svc's read-only mirror of Square (#438). Authorization,
 * the workspace binding and the receipts live in {@see SquareMirrorService},
 * which the MCP tools reach through this same route.
 */
final class AgentSquareMirrorController extends Controller
{
    public function __construct(
        private readonly SquareMirrorService $mirror,
        private readonly SquareMirrorPresenter $presenter,
        private readonly SquareMirrorReadService $reads,
    ) {}

    public function sync(Request $request, Workspace $workspace, AgentMutationContextFactory $contexts): JsonResponse
    {
        $context = $contexts->from($request);
        $run = $this->mirror->sync($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey);

        return response()->json(['data' => $this->presenter->run($run)]);
    }

    public function invoices(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->invoices(
            $this->actor($request),
            $workspace,
            $this->only($request, ['location_id', 'status', 'customer_id', 'series_key', 'due_from', 'due_to']),
            $this->limit($request),
            $this->text($request, 'cursor'),
        ));
    }

    public function series(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->series(
            $this->actor($request),
            $workspace,
            $this->only($request, ['location_id', 'customer_id']),
            $this->limit($request),
            $this->text($request, 'cursor'),
        ));
    }

    public function seriesDetail(Request $request, Workspace $workspace, string $series): JsonResponse
    {
        return response()->json($this->reads->seriesDetail($this->actor($request), $workspace, $series));
    }

    public function candidates(Request $request, Workspace $workspace, string $invoice, SquareCandidateService $candidates): JsonResponse
    {
        return response()->json($candidates->candidates(
            $this->actor($request), $workspace, $invoice,
            $this->boolean($request, 'include_canceled') ?? false,
            $request->query('limit') === null ? SquareCandidateService::DEFAULT_LIMIT : $this->limit($request),
        ));
    }

    public function unlinkedCustomers(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->unlinkedCustomers(
            $this->actor($request), $workspace, $this->boolean($request, 'with_open_balance'),
            $request->query('limit') === null ? SquareMirrorReadService::DEFAULT_UNLINKED_LIMIT : $this->limit($request),
        ));
    }

    public function importCustomer(Request $request, Workspace $workspace, string $customer, AgentMutationContextFactory $contexts, SquareCustomerImporter $importer): JsonResponse
    {
        $context = $contexts->from($request);
        [$company, $agreement] = $importer->import($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $customer, $request->all());

        return response()->json(['data' => [
            'company_id' => $company->public_id,
            'company_name' => $company->name,
            'company_href' => route('clients.show', [$workspace, $company], absolute: false),
            'agreement_id' => $agreement?->public_id,
            'agreement_href' => $agreement === null ? null : route('clients.agreement', [$workspace, $company, $agreement], absolute: false),
        ]]);
    }

    private function boolean(Request $request, string $key): ?bool
    {
        $value = $this->text($request, $key);
        abort_unless($value === null || in_array($value, ['true', 'false', '1', '0'], true), 422, "The {$key} must be a boolean.");

        return $value === null ? null : in_array($value, ['true', '1'], true);
    }

    private function actor(Request $request): User|AgentPrincipal
    {
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);

        return $user;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function only(Request $request, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = $this->text($request, $key);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** A query value as given, so the service's validation sees what the caller sent. */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if ($value === null) {
            return null;
        }
        // An array (`?status[]=`) is not a value any mirror read takes.
        abort_unless(is_string($value), 422, "The {$key} must be a string.");

        return $value;
    }

    private function limit(Request $request): int
    {
        $limit = $this->text($request, 'limit');
        if ($limit === null) {
            return SquareMirrorReadService::DEFAULT_LIMIT;
        }
        abort_unless(preg_match('/^\d{1,4}$/', $limit) === 1, 422, 'The limit must be an integer.');

        return (int) $limit;
    }
}
