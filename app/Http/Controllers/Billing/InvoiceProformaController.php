<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceOperations;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\BillingCycleResolver;
use App\Services\WorkspaceAuthorization;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * "View proforma invoice": the next cadence invoice of an agreement, drawn as
 * the invoice document, stamped as not an invoice and never stored.
 *
 * It is the period preview (`invoices.preview_period`) read as a document: the
 * generator drafts the period inside a transaction that is always rolled back,
 * and the document is built from that draft before the rollback, so the
 * proforma and the invoice later issued from it print the same hours
 * statement. Operators only: it generates a draft, and an operator who may not
 * manage the workspace cannot do that.
 */
final class InvoiceProformaController extends Controller
{
    public function __construct(
        private readonly AgentInvoiceOperations $operations,
        private readonly BillingCycleResolver $cycles,
        private readonly WorkspaceClock $clock,
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProjectAccess $access,
    ) {}

    public function show(Request $request, Workspace $workspace, ClientCompany $clientCompany, ClientAgreement $clientAgreement): Response
    {
        Gate::authorize('manage', $workspace);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorization->assertOwnedBy($workspace, $clientCompany);
        $this->authorization->assertOwnedBy($workspace, $clientAgreement);
        abort_unless((int) $clientAgreement->client_company_id === (int) $clientCompany->id, 404);
        $reachable = $this->access->reachableCompanyIds($user, $workspace);
        abort_if($reachable !== null && ! in_array((int) $clientCompany->id, $reachable, true), 404);

        $today = $this->clock->today($workspace);
        $document = null;
        $periodStart = null;
        $reason = null;

        if ($clientAgreement->starts_on->toDateString() > $today->toDateString()) {
            $reason = 'This agreement has not started yet, so there is no invoice to preview.';
        } else {
            try {
                $periodStart = $this->cycles->cycleContaining($clientAgreement, $today)->start->toDateString();
                $document = $this->operations->previewDocument($user, $workspace, $clientAgreement->public_id, $periodStart, InvoiceLineDetail::CLIENT)->toArray();
            } catch (ValidationException $refused) {
                $reason = (string) collect($refused->errors())->flatten()->first();
            } catch (DomainException $refused) {
                // A billing rule that says the period cannot be priced yet, in its own words.
                $reason = $refused->getMessage();
            } catch (InvalidArgumentException) {
                $reason = 'There is no billing period to preview for this agreement yet.';
            } catch (HttpExceptionInterface $refused) {
                // 409: the period already has an invoice, which is the document to read.
                abort_if($refused->getStatusCode() !== 409, $refused->getStatusCode());
                $reason = 'The current period already has an invoice, so there is nothing left to preview.';
            }
        }

        return Inertia::render('clients/proforma', [
            'company' => AgentClientPresenter::identity($clientCompany),
            'agreement_title' => $clientAgreement->title,
            'period_start' => $periodStart,
            'document' => $document,
            'reason' => $reason,
        ]);
    }
}
