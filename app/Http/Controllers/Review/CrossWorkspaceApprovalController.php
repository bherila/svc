<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Queries\Approval\CrossWorkspaceApprovalQueue;
use App\Services\Approval\ClientApprovals;
use App\Services\Review\ReviewerGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "To approve" across every provider that lets this person decide, and the
 * approve and dispute writes behind it (docs/ux-overhaul.md sections 7c, 7d).
 *
 * A block is one of two sources: a portal membership (`company`) or a reviewer
 * grant (`grant`). Either is resolved from the signed-in person's own
 * memberships or grants, so an id that is not theirs - another person's, a
 * revoked grant's, a made-up one - is a 404 and says nothing about whether it
 * exists. Everything after that is the same code the portal home uses, so no
 * rule is stated twice and nobody approves their own work on either path.
 *
 * The decisions are interactive and a person's own, so, like the portal's, they
 * are browser routes and not agent operations.
 */
final class CrossWorkspaceApprovalController extends Controller
{
    public function __construct(
        private readonly CrossWorkspaceApprovalQueue $queue,
        private readonly ClientApprovals $approvals,
        private readonly ReviewerGrants $grants,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $viewer = $this->user($request);
        $queue = $this->queue->for($viewer);

        foreach ($queue['reads'] as $read) {
            $this->grants->recordRead($viewer, $read['grant'], $read['rows']);
        }

        return Inertia::render('approvals/index', [
            'blocks' => $queue['blocks'],
            'approval_result' => session('approval_result'),
            'workspaces_url' => route('workspaces.index', absolute: false),
        ]);
    }

    public function approve(Request $request, string $source, string $id): RedirectResponse
    {
        $viewer = $this->user($request);
        $data = $request->validate([
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'time_ids' => ['sometimes', 'array', 'max:300'],
            'time_ids.*' => ['uuid', 'distinct'],
            'expense_ids' => ['sometimes', 'array', 'max:150'],
            'expense_ids.*' => ['uuid', 'distinct'],
        ]);
        $timeIds = array_values($data['time_ids'] ?? []);
        $expenseIds = array_values($data['expense_ids'] ?? []);

        try {
            $result = $source === 'grant'
                ? $this->approvals->approveThroughGrant($viewer, $id, $data['period'] ?? null, $timeIds, $expenseIds)
                : $this->approvals->approve($this->company($viewer, $id), $viewer, $data['period'] ?? null, $timeIds, $expenseIds);
        } catch (HttpException $refusal) {
            $this->actionable($refusal, $id);
        }

        return redirect()->back()->with('approval_result', $result + ['kind' => 'approved', 'key' => $id]);
    }

    public function dispute(Request $request, string $source, string $id): RedirectResponse
    {
        $viewer = $this->user($request);
        $data = $request->validate([
            'kind' => ['required', 'in:time,expense'],
            'id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($source === 'grant') {
                $this->approvals->disputeThroughGrant($viewer, $id, $data['kind'], $data['id'], $data['note'] ?? null);
            } else {
                $this->approvals->dispute($this->company($viewer, $id), $viewer, $data['kind'], $data['id'], $data['note'] ?? null);
            }
        } catch (HttpException $refusal) {
            $this->actionable($refusal, $id);
        }

        return redirect()->back()->with('approval_result', ['kind' => 'disputed', 'key' => $id]);
    }

    /** The client the person holds a portal membership of, or a 404. */
    private function company(User $viewer, string $companyPublicId): ClientCompany
    {
        $membership = ClientCompanyMembership::query()
            ->where('user_id', $viewer->id)
            ->whereHas('clientCompany', fn ($company) => $company->where('public_id', $companyPublicId))
            ->with('clientCompany.workspace')
            ->first();
        abort_if($membership === null, 404);
        $company = $membership->clientCompany;
        abort_if($company === null || (int) $company->workspace_id !== (int) $membership->workspace_id, 404);

        return $company;
    }

    /** A refusal the person can act on is put where the block shows errors; the rest keep their status. */
    private function actionable(HttpException $refusal, string $key): never
    {
        if ($refusal->getStatusCode() === 409) {
            throw ValidationException::withMessages(['approval_'.$key => $refusal->getMessage()]);
        }

        throw $refusal;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
