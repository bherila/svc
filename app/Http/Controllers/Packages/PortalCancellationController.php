<?php

namespace App\Http\Controllers\Packages;

use App\Exceptions\AgreementCancellationRefused;
use App\Http\Controllers\Controller;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Engagement\PortalCancellationPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A company contact cancelling one of their company's agreements in the
 * portal (CAN-3), withdrawing a pending request (CAN-4), or undoing an end
 * they scheduled themselves (EDGE-9).
 *
 * Browser-only by design: each is the client's own act, given in the portal,
 * and none is an agent operation - client agents read the state through
 * `client_services.*`. Every rule is AgreementCancellations', which checks
 * again under the agreement's lock that the viewer speaks for the company.
 */
final class PortalCancellationController extends Controller
{
    public function __construct(
        private readonly AgreementCancellations $cancellations,
        private readonly PortalCancellationPanel $panel,
    ) {}

    public function store(Request $request, ClientCompany $clientCompany, ClientAgreement $clientAgreement): RedirectResponse
    {
        $viewer = $this->viewer($request, $clientCompany, $clientAgreement);
        $data = $request->validate([
            'end_date' => ['required', 'string', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->refusedAs('end_date', fn () => $this->cancellations->cancelByContact($viewer, $clientCompany, $clientAgreement, $data['end_date'], $data['reason'] ?? null));

        return redirect($this->panel->pageUrl($clientCompany, $clientAgreement))
            ->with('status', $result instanceof AgreementScheduledTermination ? 'Cancellation scheduled.' : 'Cancellation requested.');
    }

    public function withdraw(Request $request, ClientCompany $clientCompany, ClientAgreement $clientAgreement): RedirectResponse
    {
        $viewer = $this->viewer($request, $clientCompany, $clientAgreement);
        $this->refusedAs('cancellation', fn () => $this->cancellations->withdrawByContact($viewer, $clientCompany, $clientAgreement));

        return redirect($this->panel->pageUrl($clientCompany, $clientAgreement))->with('status', 'Cancellation request withdrawn.');
    }

    public function undo(Request $request, ClientCompany $clientCompany, ClientAgreement $clientAgreement): RedirectResponse
    {
        $viewer = $this->viewer($request, $clientCompany, $clientAgreement);
        $this->refusedAs('cancellation', fn () => $this->cancellations->undoByContact($viewer, $clientCompany, $clientAgreement));

        return redirect($this->panel->pageUrl($clientCompany, $clientAgreement))->with('status', 'Cancellation undone.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $act
     * @return T
     */
    private function refusedAs(string $field, callable $act): mixed
    {
        try {
            return $act();
        } catch (AgreementCancellationRefused $refused) {
            throw ValidationException::withMessages([$field => $refused->getMessage()]);
        }
    }

    /** Someone who may view the portal, on an agreement the portal shows; acting is checked by the workflow. */
    private function viewer(Request $request, ClientCompany $company, ClientAgreement $agreement): User
    {
        Gate::authorize('viewPortal', $company);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);
        abort_unless(PortalCancellationPanel::shownInPortal($company, $agreement), 404);

        return $viewer;
    }
}
