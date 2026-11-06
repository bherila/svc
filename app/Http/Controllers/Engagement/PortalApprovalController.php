<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\User;
use App\Services\Approval\ClientApprovals;
use App\Services\Approval\PortalApprovalAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A client contact's approvals, from the portal home's "To approve".
 *
 * The permission is the portal membership's own - see
 * {@see PortalApprovalAuthority} - and the company in
 * the route is bound through the tenant, so another client's rows do not
 * resolve. A refusal the contact can act on (a row that is no longer waiting,
 * or one of their own) is put where the screen shows errors; everything else
 * keeps its status.
 */
final class PortalApprovalController extends Controller
{
    public function approve(Request $request, ClientCompany $clientCompany, ClientApprovals $approvals): RedirectResponse
    {
        Gate::authorize('viewPortal', $clientCompany);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        $data = $request->validate([
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'time_ids' => ['sometimes', 'array', 'max:300'],
            'time_ids.*' => ['uuid', 'distinct'],
            'expense_ids' => ['sometimes', 'array', 'max:150'],
            'expense_ids.*' => ['uuid', 'distinct'],
        ]);

        try {
            $result = $approvals->approve(
                $clientCompany,
                $viewer,
                $data['period'] ?? null,
                array_values($data['time_ids'] ?? []),
                array_values($data['expense_ids'] ?? []),
            );
        } catch (HttpException $refusal) {
            $this->actionable($refusal);
        }

        return redirect()->back()->with('approval_result', $result + ['kind' => 'approved']);
    }

    public function dispute(Request $request, ClientCompany $clientCompany, ClientApprovals $approvals): RedirectResponse
    {
        Gate::authorize('viewPortal', $clientCompany);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        $data = $request->validate([
            'kind' => ['required', 'in:time,expense'],
            'id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approvals->dispute($clientCompany, $viewer, $data['kind'], $data['id'], $data['note'] ?? null);
        } catch (HttpException $refusal) {
            $this->actionable($refusal);
        }

        return redirect()->back()->with('approval_result', ['kind' => 'disputed']);
    }

    private function actionable(HttpException $refusal): never
    {
        if ($refusal->getStatusCode() === 409) {
            throw ValidationException::withMessages(['approval' => $refusal->getMessage()]);
        }

        throw $refusal;
    }
}
