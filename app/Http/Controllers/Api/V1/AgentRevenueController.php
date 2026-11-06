<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\Operations\RevenueOperations;
use App\Services\Revenue\RevenueReadService;
use App\Services\Revenue\RevenueSummaries;
use App\Support\Revenue\RevenueBasis;
use App\Support\Revenue\RevenueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The revenue planning reads over REST, and over MCP through the same routes.
 * A thin adapter: {@see RevenueReadService} checks the caller manages the
 * workspace and computes every figure.
 */
final class AgentRevenueController extends Controller
{
    private const array BOOLEANS = ['true', 'false', '1', '0'];

    public function __construct(private readonly RevenueReadService $revenue) {}

    public function summary(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->revenue->summary($this->principal($request), $workspace, $this->query($request, 'revenue.summary')));
    }

    public function monthly(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->revenue->monthly($this->principal($request), $workspace, $this->query($request, 'revenue.monthly')));
    }

    public function projection(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->revenue->projection($this->principal($request), $workspace, $this->query($request, 'revenue.projection')));
    }

    public function clients(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->data($this->revenue->clients($this->principal($request), $workspace, $this->query($request, 'revenue.clients')));
    }

    /** The toggles this operation documents, validated; the others keep their defaults. */
    private function query(Request $request, string $operationId): RevenueQuery
    {
        $rules = [
            'basis' => ['sometimes', 'string', Rule::enum(RevenueBasis::class)],
            'include_expenses' => ['sometimes', 'string', Rule::in(self::BOOLEANS)],
            'include_projected_overage' => ['sometimes', 'string', Rule::in(self::BOOLEANS)],
            'band' => ['sometimes', 'string', Rule::in(RevenueSummaries::BANDS)],
            'client_id' => ['sometimes', 'string', 'uuid'],
        ];
        $accepted = RevenueOperations::DEFINITIONS[$operationId][3];
        $values = Validator::make($request->query(), array_intersect_key($rules, array_flip($accepted)))->validate();
        $flag = static fn (string $name, bool $default): bool => isset($values[$name]) ? filter_var($values[$name], FILTER_VALIDATE_BOOLEAN) : $default;

        return new RevenueQuery(
            basis: isset($values['basis']) ? RevenueBasis::from((string) $values['basis']) : RevenueBasis::Invoiced,
            includeExpenses: $flag('include_expenses', true),
            includeProjectedOverage: $flag('include_projected_overage', false),
            band: isset($values['band']) ? (string) $values['band'] : RevenueSummaries::BAND_ALL,
            clientId: isset($values['client_id']) ? (string) $values['client_id'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function data(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
