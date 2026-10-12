<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP adapter for the read-only Square pass-through (#438). Validation,
 * authorization, the workspace binding and the rate limit live in
 * {@see SquareReadService}, which the MCP tools share.
 */
final class AgentSquareController extends Controller
{
    public function __construct(private readonly SquareReadService $square) {}

    public function locations(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->square->locations($this->user($request), $workspace));
    }

    public function customers(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->square->customers($this->user($request), $workspace, $this->limit($request), $this->text($request, 'cursor')));
    }

    public function searchCustomers(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->square->searchCustomers(
            $this->user($request),
            $workspace,
            $this->text($request, 'email'),
            $this->text($request, 'phone'),
            $this->text($request, 'match') ?? 'fuzzy',
            $this->limit($request),
            $this->text($request, 'cursor'),
        ));
    }

    public function customer(Request $request, Workspace $workspace, string $customer): JsonResponse
    {
        return response()->json($this->square->customer($this->user($request), $workspace, $customer));
    }

    public function invoices(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->square->invoices($this->user($request), $workspace, $this->text($request, 'location_id') ?? '', $this->limit($request), $this->text($request, 'cursor')));
    }

    public function searchInvoices(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->square->searchInvoices(
            $this->user($request),
            $workspace,
            $this->text($request, 'location_id') ?? '',
            $this->text($request, 'customer_id'),
            $this->text($request, 'sort_order') ?? 'desc',
            $this->limit($request),
            $this->text($request, 'cursor'),
        ));
    }

    public function invoice(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        return response()->json($this->square->invoice($this->user($request), $workspace, $invoice));
    }

    public function order(Request $request, Workspace $workspace, string $order): JsonResponse
    {
        return response()->json($this->square->order($this->user($request), $workspace, $order));
    }

    public function payments(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->square->payments(
            $this->user($request),
            $workspace,
            $this->text($request, 'begin_time'),
            $this->text($request, 'end_time'),
            $this->text($request, 'location_id'),
            $this->text($request, 'sort_order') ?? 'desc',
            $this->limit($request),
            $this->text($request, 'cursor'),
        ));
    }

    public function payment(Request $request, Workspace $workspace, string $payment): JsonResponse
    {
        return response()->json($this->square->payment($this->user($request), $workspace, $payment));
    }

    private function user(Request $request): User|AgentPrincipal
    {
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);

        return $user;
    }

    /** A query value as given, so the service's validation sees what the caller sent. */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            // An array (`?email[]=`) is not a value any Square read takes.
            abort(422, "The {$key} must be a string.");
        }

        return $value;
    }

    private function limit(Request $request): int
    {
        $limit = $this->text($request, 'limit');
        if ($limit === null) {
            return SquareReadService::DEFAULT_LIMIT;
        }
        abort_unless(preg_match('/^\d{1,4}$/', $limit) === 1, 422, 'The limit must be an integer.');

        return (int) $limit;
    }
}
