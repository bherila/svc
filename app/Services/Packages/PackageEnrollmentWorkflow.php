<?php

namespace App\Services\Packages;

use App\Exceptions\ServicePackageRefused;
use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\CompanyContacts;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\EngagementException;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Packages\EnrollmentLapse;
use App\Support\Packages\EnrollmentSigner;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\EnrollmentVersions;
use App\Support\Packages\LapseOutcome;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PortalAddRule;
use App\Support\Packages\SignupGate;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A client's enrollment from offer to service: adding a package from the
 * portal (ACC-8), accepting it (ACC-1, ACC-2) and the payment at sign-up it
 * takes (ACC-3, ACC-4), activating it (ACC-5), and lapsing it unpaid (ACC-6).
 *
 * Adding and accepting are a company contact's own acts, in their browser:
 * neither is an agent operation. Activating an enrollment that waits for
 * review is an owner's or admin's, from the website or the agent API.
 *
 * Locks, in registry order: the billing maintenance share lock (acceptance
 * and lapsing issue or void an invoice, which only a transaction that began
 * with it may), the package (shared, so a publish waits for an acceptance in
 * flight and the version accepted is the current one), then the enrollment,
 * then the agreement, the invoice rows and the company the billing services
 * and AgreementWorkflow take. Access is checked again under the enrollment's
 * lock (EDGE-3), and once recorded an acceptance is never changed (ACC-2).
 *
 * Payment at sign-up: acceptance issues the one sign-up invoice
 * ({@see PackageSignupInvoices}). An enrollment then waits `awaiting_payment`
 * while {@see SignupGate} says it must - for the invoice when the version
 * starts only once paid, for autopay when it requires it - and otherwise goes
 * straight on with the invoice simply outstanding. The payment that settles
 * the invoice, recorded from Stripe's webhook, and autopay turned on in the
 * portal each move a waiting enrollment on through {@see continueSignup()},
 * after their own write commits and in the same request: nothing is queued.
 */
final class PackageEnrollmentWorkflow
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly CompanyContacts $contacts,
        private readonly PackageOffers $offers,
        private readonly PackageAgreementTerms $agreementTerms,
        private readonly AgreementWorkflow $agreements,
        private readonly PackageNotifier $notifier,
        private readonly PackageSignupInvoices $signupInvoices,
        private readonly PackageAutopayRequirement $autopay,
        private readonly PaymentConnectionResolver $payments,
        private readonly InvoiceLifecycleService $invoices,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * A company contact adds a published, portal-addable package (ACC-8):
     * an offer straight away, with no request. Adding the same package again
     * while its offer is open returns that offer rather than a second one.
     */
    public function addFromPortal(User $contact, ClientCompany $company, ServicePackage $package): ServicePackageEnrollment
    {
        abort_unless($package->workspace_id === $company->workspace_id, 404);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $package, $workspace): ServicePackageEnrollment {
            $locked = ServicePackage::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($package->getKey())
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            $this->requireCompanyContact($company, $contact);

            $open = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->where('service_package_id', $locked->id)
                ->where('status', EnrollmentStatus::Offered->value)
                ->orderBy('id')
                ->first();
            if ($open instanceof ServicePackageEnrollment) {
                return $open;
            }

            $version = $this->offers->publishedVersion($workspace, $locked);
            $refusal = PortalAddRule::refusal($locked, $version === null ? null : PackageTerms::fromVersion($version), $this->payments->stripe($workspace) !== null);
            if ($refusal !== null || $version === null) {
                throw ServicePackageRefused::because($refusal ?? PackageRefusal::NotAddable);
            }

            return $this->offers->offer($workspace, $locked, $version, $company, $contact);
        });
    }

    /**
     * A company contact accepts an offer (ACC-1, ACC-2), and the version's
     * activation rule follows (ACC-5).
     *
     * The terms accepted are the package's currently published version
     * (EDGE-7): when it is newer than the one offered, its terms are written
     * onto the draft agreement first. A retired package's offer is accepted on
     * the terms it was offered with (EDGE-8). The agreement starts on the day
     * of acceptance, in the workspace's timezone.
     *
     * @param  array<string, mixed>  $input  signer_name, signer_title, accepted_terms
     */
    public function accept(User $contact, ClientCompany $company, ServicePackageEnrollment $enrollment, array $input): ServicePackageEnrollment
    {
        $signer = EnrollmentSigner::fromInput($input);
        abort_unless($enrollment->workspace_id === $company->workspace_id && $enrollment->client_company_id === $company->id, 404);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $enrollment, $signer, $workspace): ServicePackageEnrollment {
            // First, ahead of every other lock: a sign-up invoice is generated
            // and issued in this transaction, and those lock time and tasks.
            BillingMaintenance::share($workspace->id);
            $package = ServicePackage::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($enrollment->service_package_id)
                ->tap(Locks::forShare())
                ->firstOrFail();
            $locked = $this->locked($workspace, $enrollment);
            // Again, under the lock: access may have gone since the page loaded (EDGE-3).
            $this->requireCompanyContact($company, $contact);

            $status = $locked->enrollmentStatus();
            if ($status->isAccepted()) {
                if ($signer->signed($locked, $contact)) {
                    return $locked;
                }
                throw ServicePackageRefused::because(PackageRefusal::AlreadyAccepted);
            }
            if ($status !== EnrollmentStatus::Offered) {
                throw ServicePackageRefused::because(PackageRefusal::OfferNotOpen);
            }

            $version = $this->versionToAccept($workspace, $package, $locked);
            $terms = PackageTerms::fromVersion($version);
            // The Stripe connection that let it publish (PKG-4) may have gone.
            if ($terms->involvesPayment() && $this->payments->stripe($workspace) === null) {
                throw ServicePackageRefused::because(PackageRefusal::PaymentUnavailable);
            }
            $agreement = $this->agreementOf($workspace, $locked);
            if ($agreement->status !== 'draft') {
                throw ServicePackageRefused::because(PackageRefusal::OfferNotOpen);
            }
            if ($version->id !== $locked->service_package_version_id) {
                $agreement = $this->agreementTerms->apply($workspace, $agreement, $version);
            }
            $agreement = $this->agreements->update($workspace, $agreement, ['starts_on' => $this->clock->today($workspace)->toDateString()]);

            $locked->forceFill([
                'accepted_at' => $this->clock->now($workspace),
                'accepted_by_user_id' => $contact->id,
                'signer_name' => $signer->name,
                'signer_title' => $signer->title,
                'accepted_version_id' => $version->id,
            ]);
            // ACC-3: the one sign-up invoice, issued now on the draft agreement.
            $invoice = $this->signupInvoices->issue($workspace, $company, $agreement, $terms);
            $locked->forceFill(['signup_invoice_id' => $invoice?->id]);
            $next = SignupGate::next($terms, SignupGate::invoiceSettled($invoice), $this->autopayReady($workspace, $company, $terms));
            $this->moveOn($workspace, $company, $locked, $agreement, $version, $next);

            return $locked;
        });
    }

    /**
     * Move an enrollment waiting at sign-up on, once what it waits for is in
     * place: its sign-up invoice paid, autopay on at the version's limit
     * (ACC-4, ACC-5). It then follows the version's activation mode, as an
     * acceptance owing nothing would have. Anything not waiting, or still
     * waiting, is left as it is, so calling it again - a replayed webhook, a
     * second turn-on - changes nothing.
     */
    public function continueSignup(Workspace $workspace, ServicePackageEnrollment $enrollment): ServicePackageEnrollment
    {
        return DB::transaction(function () use ($workspace, $enrollment): ServicePackageEnrollment {
            $locked = $this->locked($workspace, $enrollment);
            if ($locked->enrollmentStatus() !== EnrollmentStatus::AwaitingPayment) {
                return $locked;
            }
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($locked->client_company_id)->firstOrFail();
            $version = $this->offers->version($workspace, $locked->carriedVersionId());
            $terms = PackageTerms::fromVersion($version);
            $invoice = $this->signupInvoiceOf($workspace, $locked);
            $next = SignupGate::next($terms, SignupGate::invoiceSettled($invoice), $this->autopayReady($workspace, $company, $terms));
            if ($next === EnrollmentStatus::AwaitingPayment) {
                return $locked;
            }

            $agreement = $this->agreementOf($workspace, $locked);
            if (! self::billsFirstPeriod($invoice)) {
                // The service starts on the day it can, unless the sign-up
                // invoice already billed a period starting at acceptance.
                $agreement = $this->agreements->update($workspace, $agreement, ['starts_on' => $this->clock->today($workspace)->toDateString()]);
            }
            $this->moveOn($workspace, $company, $locked, $agreement, $version, $next);

            return $locked;
        });
    }

    /**
     * The payment that settled an invoice has committed: if it is an
     * enrollment's sign-up invoice, move that enrollment on (ACC-5). Called by
     * the payment recorder once its transaction commits, in the same request;
     * a payment rolled back never gets here (X-5).
     */
    public function signupInvoicePaid(int $workspaceId, int $invoiceId): void
    {
        if (! EnsureServicePackagesEnabled::enabled()) {
            return;
        }
        $enrollment = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspaceId)
            ->where('signup_invoice_id', $invoiceId)
            ->where('status', EnrollmentStatus::AwaitingPayment->value)
            ->first();
        if ($enrollment instanceof ServicePackageEnrollment) {
            $this->continueSignup(Workspace::query()->findOrFail($workspaceId), $enrollment);
        }
    }

    /**
     * A company contact turned autopay on or changed its limit in the portal:
     * move on every one of the company's enrollments it was waiting for.
     */
    public function autopayChanged(Workspace $workspace, ClientCompany $company): void
    {
        if (! EnsureServicePackagesEnabled::enabled() || $company->workspace_id !== $workspace->id) {
            return;
        }
        $waiting = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('status', EnrollmentStatus::AwaitingPayment->value)
            ->orderBy('id')
            ->limit(25)
            ->get();
        foreach ($waiting as $enrollment) {
            $this->continueSignup($workspace, $enrollment);
        }
    }

    /**
     * End an open enrollment at its lapse date (ACC-6): an offer never
     * accepted, or an acceptance whose sign-up still waits. Its unpaid sign-up
     * invoice is voided through InvoiceLifecycleService, and its draft
     * agreement stays a draft, never activated.
     *
     * Decided under the enrollment's lock against the invoice as it is now, so
     * a payment and the lapse cannot both win: a sign-up invoice already paid
     * moves the enrollment on instead, and one with money received or still in
     * flight is not voided (the void refuses it) and is held for a later run.
     * Running it again changes nothing.
     */
    public function lapse(Workspace $workspace, ServicePackageEnrollment $enrollment): LapseOutcome
    {
        return DB::transaction(function () use ($workspace, $enrollment): LapseOutcome {
            // Voiding takes it; first, as every invoice writer does.
            BillingMaintenance::share($workspace->id);
            $locked = $this->locked($workspace, $enrollment);
            $status = $locked->enrollmentStatus();
            if (! $status->mayLapse()) {
                return LapseOutcome::NotDue;
            }
            $version = $this->offers->version($workspace, $locked->carriedVersionId());
            $today = $this->clock->today($workspace);
            if (! EnrollmentLapse::hasLapsed($status, $locked->offered_at, $locked->accepted_at, (int) $version->payment_lapse_days, $today->tzName, $today)) {
                return LapseOutcome::NotDue;
            }

            $invoice = $this->signupInvoiceOf($workspace, $locked);
            if ($status === EnrollmentStatus::AwaitingPayment && SignupGate::invoiceSettled($invoice)) {
                $moved = $this->continueSignup($workspace, $locked);

                return $moved->enrollmentStatus() === EnrollmentStatus::AwaitingPayment ? LapseOutcome::Held : LapseOutcome::Continued;
            }
            if ($invoice instanceof ClientInvoice && $invoice->status !== InvoiceStatus::Void->value) {
                try {
                    $this->invoices->void($invoice, $workspace, 'The sign-up lapsed unpaid.');
                } catch (DomainException) {
                    // A payment received or still in flight: never voided
                    // under it. The next run decides once it resolves.
                    return LapseOutcome::Held;
                }
            }

            $this->assertMove($status, EnrollmentStatus::Lapsed);
            $locked->forceFill(['status' => EnrollmentStatus::Lapsed->value, 'lapsed_at' => $this->clock->now($workspace)])->save();

            return LapseOutcome::Lapsed;
        });
    }

    /**
     * An owner or admin starts an accepted enrollment that waits for review
     * (ACC-5, ACC-7). Activating one already active changes nothing.
     */
    public function activate(User $actor, Workspace $workspace, ServicePackageEnrollment $enrollment, ?string $expectedVersion = null): ServicePackageEnrollment
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);

        return DB::transaction(function () use ($actor, $workspace, $enrollment, $expectedVersion): ServicePackageEnrollment {
            $locked = $this->locked($workspace, $enrollment);
            abort_unless($expectedVersion === null || AgentApiVersion::matches($locked, $expectedVersion), 409, 'The enrollment changed. Read it again before retrying.');
            $status = $locked->enrollmentStatus();
            if ($status === EnrollmentStatus::Active) {
                return $locked;
            }
            if ($status !== EnrollmentStatus::AwaitingReview) {
                throw ServicePackageRefused::because(PackageRefusal::NotAwaitingReview);
            }

            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($locked->client_company_id)->firstOrFail();
            $version = $this->offers->version($workspace, $locked->carriedVersionId());
            $agreement = $this->agreementOf($workspace, $locked);
            // The service starts on the day it is activated - unless the
            // sign-up invoice billed a first period starting at acceptance,
            // which is the period the client paid for (EDGE-6).
            if (! self::billsFirstPeriod($this->signupInvoiceOf($workspace, $locked))) {
                $agreement = $this->agreements->update($workspace, $agreement, ['starts_on' => $this->clock->today($workspace)->toDateString()]);
            }
            $this->start($workspace, $company, $locked, $agreement, $version, $actor);

            return $locked;
        });
    }

    /**
     * Activate the agreement, sign it as the contact who accepted, mark the
     * enrollment active, and tell the client once it commits. One savepoint,
     * so a refused activation leaves no part of it behind.
     */
    private function start(Workspace $workspace, ClientCompany $company, ServicePackageEnrollment $enrollment, ClientAgreement $agreement, ServicePackageVersion $version, ?User $activatedBy): void
    {
        $this->assertMove($enrollment->enrollmentStatus(), EnrollmentStatus::Active);
        DB::transaction(function () use ($workspace, $company, $enrollment, $agreement, $version, $activatedBy): void {
            try {
                $active = $this->agreements->activate($agreement);
            } catch (EngagementException) {
                throw ServicePackageRefused::because(PackageRefusal::ActivationConflict);
            }
            $signer = $enrollment->accepted_by_user_id === null ? null : User::query()->find($enrollment->accepted_by_user_id);
            $this->agreements->sign($active, $signer, (string) $enrollment->signer_name, $enrollment->signer_title);
            $enrollment->forceFill([
                'status' => EnrollmentStatus::Active->value,
                'activated_at' => $this->clock->now($workspace),
                'activated_by_user_id' => $activatedBy?->id,
            ])->save();

            $this->notifier->serviceActivated($workspace, $company, $version->name, route('portal.agreement', [$company, $active], absolute: true));
        });
    }

    /**
     * Save an accepted enrollment's next status, starting it when that is
     * `active`. An automatic start that cannot happen - the agreement would
     * overlap another active one - keeps the signature and waits for an owner
     * or admin in Needs you.
     */
    private function moveOn(Workspace $workspace, ClientCompany $company, ServicePackageEnrollment $enrollment, ClientAgreement $agreement, ServicePackageVersion $version, EnrollmentStatus $next): void
    {
        if ($next === EnrollmentStatus::Active) {
            try {
                $this->start($workspace, $company, $enrollment, $agreement, $version, null);

                return;
            } catch (ServicePackageRefused $refused) {
                if ($refused->refusalCode() !== PackageRefusal::ActivationConflict->value) {
                    throw $refused;
                }
                $next = EnrollmentStatus::AwaitingReview;
            }
        }
        $this->assertMove($enrollment->enrollmentStatus(), $next);
        $enrollment->forceFill(['status' => $next->value])->save();
    }

    private function autopayReady(Workspace $workspace, ClientCompany $company, PackageTerms $terms): bool
    {
        return ! $terms->requireAutopay || $this->autopay->satisfied($workspace, $company, (int) $terms->autopayLimitAmount);
    }

    /** Whether the sign-up invoice is the agreement's first cycle invoice, which fixes the day its service began. */
    private static function billsFirstPeriod(?ClientInvoice $invoice): bool
    {
        return $invoice instanceof ClientInvoice && $invoice->invoiceKindValue() === InvoiceKind::CadencePeriod->value;
    }

    private function signupInvoiceOf(Workspace $workspace, ServicePackageEnrollment $enrollment): ?ClientInvoice
    {
        return $enrollment->signup_invoice_id === null ? null : ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($enrollment->signup_invoice_id)
            ->firstOrFail();
    }

    /** The version an acceptance signs ({@see EnrollmentVersions::toAccept()}). */
    private function versionToAccept(Workspace $workspace, ServicePackage $package, ServicePackageEnrollment $enrollment): ServicePackageVersion
    {
        $versionId = EnrollmentVersions::toAccept($package, $enrollment) ?? throw ServicePackageRefused::because(PackageRefusal::OfferNotOpen);

        return $this->offers->version($workspace, $versionId);
    }

    private function assertMove(EnrollmentStatus $from, EnrollmentStatus $to): void
    {
        if ($from !== $to && ! $from->canMoveTo($to)) {
            throw new LogicException("An enrollment cannot move from {$from->value} to {$to->value}.");
        }
    }

    private function requireCompanyContact(ClientCompany $company, User $contact): void
    {
        // Staff previewing the portal and project-limited contacts may look, not act (ACC-1, ACC-8).
        abort_unless($this->contacts->isCompanyContact($company, $contact), 403, 'Only a contact with access to the whole company can do this.');
    }

    private function locked(Workspace $workspace, ServicePackageEnrollment $enrollment): ServicePackageEnrollment
    {
        return ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($enrollment->getKey())
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    private function agreementOf(Workspace $workspace, ServicePackageEnrollment $enrollment): ClientAgreement
    {
        return ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($enrollment->client_agreement_id)->firstOrFail();
    }

    private function workspaceOf(ClientCompany $company): Workspace
    {
        return Workspace::query()->findOrFail($company->workspace_id);
    }
}
