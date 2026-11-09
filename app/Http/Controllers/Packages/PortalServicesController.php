<?php

namespace App\Http\Controllers\Packages;

use App\Exceptions\ServicePackageRefused;
use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Packages\ClientServiceReadService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal's Services tab: a client's services, adding a package (ACC-8),
 * and reviewing and signing an offer (ACC-1, ACC-2).
 *
 * Browser-only by design: adding and accepting are the client's own acts and
 * are not agent operations. The pages are open to anyone who may view the
 * portal, staff previewing included; the two writes are a company-wide
 * contact's alone, decided by PackageEnrollmentWorkflow, which checks again
 * whatever a page showed.
 */
final class PortalServicesController extends Controller
{
    public function __construct(
        private readonly ClientServiceReadService $reads,
        private readonly PackageEnrollmentWorkflow $workflow,
    ) {}

    public function index(Request $request, ClientCompany $clientCompany): Response
    {
        $viewer = $this->viewer($request, $clientCompany);

        return Inertia::render('portal/services', [
            'company' => AgentClientPresenter::identity($clientCompany),
            ...$this->reads->forPortal($viewer, $clientCompany),
        ]);
    }

    public function add(Request $request, ClientCompany $clientCompany): RedirectResponse
    {
        $viewer = $this->viewer($request, $clientCompany);
        $data = $request->validate(['package' => ['required', 'string', 'uuid']]);
        $package = ServicePackage::query()
            ->where('workspace_id', $clientCompany->workspace_id)
            ->where('public_id', $data['package'])
            ->firstOrFail();

        try {
            $enrollment = $this->workflow->addFromPortal($viewer, $clientCompany, $package);
        } catch (ServicePackageRefused $refused) {
            throw ValidationException::withMessages(['package' => $refused->getMessage()]);
        }

        return redirect()->route('portal.services.show', [$clientCompany, $enrollment->public_id]);
    }

    public function show(Request $request, ClientCompany $clientCompany, string $enrollment): Response
    {
        $viewer = $this->viewer($request, $clientCompany);

        return Inertia::render('portal/service', [
            'company' => AgentClientPresenter::identity($clientCompany),
            ...$this->reads->reviewForPortal($viewer, $clientCompany, $enrollment),
        ]);
    }

    public function accept(Request $request, ClientCompany $clientCompany, string $enrollment): RedirectResponse
    {
        $viewer = $this->viewer($request, $clientCompany);
        $found = $this->reads->findForPortal($viewer, $clientCompany, $enrollment);

        try {
            $this->workflow->accept($viewer, $clientCompany, $found, $request->only(['signer_name', 'signer_title', 'accepted_terms']));
        } catch (ServicePackageRefused $refused) {
            throw ValidationException::withMessages(['acceptance' => $refused->getMessage()]);
        }

        return redirect()->route('portal.services.show', [$clientCompany, $found->public_id])->with('status', 'Accepted.');
    }

    private function viewer(Request $request, ClientCompany $company): User
    {
        Gate::authorize('viewPortal', $company);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        return $viewer;
    }
}
