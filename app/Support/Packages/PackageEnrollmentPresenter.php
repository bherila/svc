<?php

namespace App\Support\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceStatus;
use App\Support\Engagement\Cancellation\CancellationState;
use App\Support\Packages\Migration\PackageMigrationPresenter;
use App\Support\WorkspaceClock;
use LogicException;

/**
 * The shapes of an enrollment: the owner's and admin's (`enrollments.*`) and
 * the client's (`client_services.*` and the portal's Services pages).
 *
 * Reads only what the caller loaded - the company, project, agreement,
 * package, the version offered and the version accepted with their
 * components, the sign-up invoice - and the company's client-consented
 * autopay the caller read for the page, so it issues no queries. URLs are finished here, after the caller's authorization: an owner
 * is pointed at the operator screens and a client at the portal, never at a
 * page their route family does not serve.
 */
final class PackageEnrollmentPresenter
{
    /**
     * @param  ClientAutopay|null  $clientAutopay  where the company's autopay stands on the client's own consent, if it does
     * @return array<string, mixed>
     */
    public static function forManager(Workspace $workspace, ServicePackageEnrollment $enrollment, ?ClientAutopay $clientAutopay = null): array
    {
        [$company, $project, $agreement, $package, $offered, $accepted, $current] = self::loaded($enrollment);
        $base = rtrim((string) config('app.url'), '/');
        $invoice = self::signupInvoice($enrollment);
        // The terms in force: a move's version (MIG-3), else the one signed, else the offer.
        $carried = $current ?? $accepted ?? $offered;
        $deadline = self::lapseWindow($package, $enrollment, [$current, $accepted, $offered, $package->publishedVersion]);

        return [
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'package' => ['id' => $package->public_id, 'link_name' => $package->link_name, 'name' => $carried->name],
            'offered_version' => self::versionRef($offered),
            'accepted_version' => $accepted === null ? null : self::versionRef($accepted),
            'current_version' => $current === null ? null : self::versionRef($current),
            'version_changed' => self::versionChanged($enrollment),
            'client' => ['id' => $company->public_id, 'name' => $company->name],
            'project_id' => $project->public_id,
            'agreement_id' => $agreement->public_id,
            'agreement_url' => $base.route('clients.agreement', ['workspace' => $workspace, 'clientCompany' => $company, 'clientAgreement' => $agreement], false),
            'request_id' => $enrollment->packageRequest?->public_id,
            'offered_at' => $enrollment->offered_at->toIso8601String(),
            'acceptance' => self::acceptance($enrollment),
            'activated_at' => $enrollment->activated_at?->toIso8601String(),
            'lapsed_at' => $enrollment->lapsed_at?->toIso8601String(),
            'payment' => self::payment($enrollment, $carried, $deadline, $clientAutopay, (new WorkspaceClock)->now($workspace)->tzName, $invoice === null ? null
                : $base.route('clients.invoice', ['workspace' => $workspace, 'clientCompany' => $company, 'clientInvoice' => $invoice], false)),
            'invoicing' => self::invoicing($workspace, $enrollment, $company, $agreement, $carried),
            // The scheduled move to a new version it is part of, if any (MIG-1, MIG-3).
            'move' => PackageMigrationPresenter::forEnrollment($enrollment->migrationTargets->first()),
            'version' => AgentApiVersion::for($enrollment),
        ];
    }

    /**
     * How the service's recurring invoices are made (INV-1, INV-2): issued
     * automatically or drafted for review, when its next billing period
     * begins, and what automatic invoicing last did - with the refusal an
     * owner should act on, or the invoice it issued and its page.
     *
     * @return array{issuing: string, next_period_start: string|null, last_automatic: array<string, mixed>|null}
     */
    public static function invoicing(Workspace $workspace, ServicePackageEnrollment $enrollment, ClientCompany $company, ClientAgreement $agreement, ServicePackageVersion $carried): array
    {
        $outcome = $enrollment->autoInvoiceOutcome();
        $last = null;
        if ($outcome !== null) {
            $refusal = $enrollment->auto_invoice_refusal === null ? null : AutoInvoiceRefusal::from($enrollment->auto_invoice_refusal);
            $invoice = null;
            if ($enrollment->auto_invoice_id !== null) {
                $invoice = $enrollment->autoInvoice;
                if (! $invoice instanceof ClientInvoice) {
                    throw new LogicException('An enrollment names an automatic invoice outside its workspace, or was presented without it loaded.');
                }
            }
            $last = [
                'outcome' => $outcome->value,
                'period_start' => $enrollment->auto_invoice_period_start?->toDateString(),
                'recorded_at' => $enrollment->auto_invoice_recorded_at?->toIso8601String(),
                'refusal' => $refusal === null ? null : [
                    'code' => $refusal->value,
                    'message' => $refusal->message(),
                    'detail' => $enrollment->auto_invoice_detail,
                ],
                'invoice' => $invoice === null ? null : [
                    'id' => $invoice->public_id,
                    'number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'url' => rtrim((string) config('app.url'), '/').route('clients.invoice', ['workspace' => $workspace, 'clientCompany' => $company, 'clientInvoice' => $invoice], false),
                ],
            ];
        }

        return [
            'issuing' => PackageInvoiceIssuing::from($carried->invoice_issuing)->value,
            'next_period_start' => PackageBillingPeriods::nextPeriodStart($enrollment->enrollmentStatus(), $agreement, (new WorkspaceClock)->today($workspace)),
            'last_automatic' => $last,
        ];
    }

    /**
     * Where the sign-up's own payment stands (ACC-3 to ACC-6): the state of
     * the sign-up invoice, what an enrollment `awaiting_payment` waits for,
     * the terms that decide it, and the day it lapses while it is open.
     * Nothing about the card or the processor: no method, no Stripe id, no
     * secret (API-4).
     *
     * @param  ServicePackageVersion  $carried  the version whose terms apply, components loaded
     * @param  ServicePackageVersion  $deadline  the version whose lapse window applies ({@see EnrollmentVersions::lapseWindow()})
     * @param  ClientAutopay|null  $clientAutopay  where the company's autopay stands on the client's own consent, if it does
     * @param  string|null  $invoiceUrl  the sign-up invoice's page for this reader, finished
     * @return array{state: string, waiting_for: list<string>, signup_invoice: array<string, mixed>|null, requires_autopay: bool, autopay_limit_amount: int|null, pay_before_activation: bool, lapses_on: string|null}
     */
    public static function payment(ServicePackageEnrollment $enrollment, ServicePackageVersion $carried, ServicePackageVersion $deadline, ?ClientAutopay $clientAutopay, string $timezone, ?string $invoiceUrl): array
    {
        $terms = PackageTerms::fromVersion($carried);
        $invoice = self::signupInvoice($enrollment);
        $status = $enrollment->enrollmentStatus();
        $waiting = $status === EnrollmentStatus::AwaitingPayment
            ? SignupGate::waitingFor($terms, SignupGate::invoiceSettled($invoice), ClientAutopay::meets($clientAutopay, $terms))
            : [];

        return [
            'state' => SignupPaymentState::of($terms->chargesAtSignup(), $invoice === null ? null : InvoiceStatus::from($invoice->status))->value,
            'waiting_for' => array_map(static fn (SignupWait $wait): string => $wait->value, $waiting),
            'signup_invoice' => $invoice === null ? null : [
                'id' => $invoice->public_id,
                'number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'total_amount' => (int) $invoice->total_amount,
                'balance_amount' => (int) $invoice->balance_amount,
                'url' => $invoiceUrl,
            ],
            'requires_autopay' => $terms->requireAutopay,
            'autopay_limit_amount' => $terms->requireAutopay ? $terms->autopayLimitAmount : null,
            'pay_before_activation' => $terms->payBeforeActivation,
            'lapses_on' => EnrollmentLapse::lapsesOn($status, $enrollment->offered_at, $enrollment->accepted_at, (int) $deadline->payment_lapse_days, $timezone)?->toDateString(),
        ];
    }

    /**
     * A client's view of one of their services: the terms they agreed to (or,
     * before acceptance, the terms on offer), never the operator's settings.
     *
     * @param  ServicePackageVersion  $terms  the version whose terms to show, components loaded
     * @param  string  $url  the service's own portal page: review and sign while offered, its record after
     * @param  ClientAutopay|null  $clientAutopay  where the company's autopay stands on the client's own consent, if it does
     * @return array<string, mixed>
     */
    public static function forClient(ServicePackageEnrollment $enrollment, ServicePackageVersion $terms, string $url, ?ClientAutopay $clientAutopay = null, string $timezone = 'UTC'): array
    {
        [$company, $project, $agreement] = self::loaded($enrollment);
        $base = rtrim((string) config('app.url'), '/');
        $invoice = self::signupInvoice($enrollment);
        $status = $enrollment->enrollmentStatus();
        // The portal never serves a draft agreement; a started one is the client's to read.
        $agreementUrl = match ($status) {
            EnrollmentStatus::Active => $base.route('portal.agreement', [$company, $agreement], false),
            EnrollmentStatus::Offered, EnrollmentStatus::AwaitingPayment, EnrollmentStatus::AwaitingReview, EnrollmentStatus::Lapsed, EnrollmentStatus::Withdrawn => null,
        };

        return [
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'company' => ['id' => $company->public_id, 'name' => $company->name],
            'project_id' => $project->public_id,
            'name' => $terms->name,
            'version_number' => $terms->number,
            // Accepted on a newer version than the one offered (EDGE-7).
            'version_changed' => self::versionChanged($enrollment),
            'terms' => self::clientTerms(PackageTerms::fromVersion($terms)),
            'acceptance' => self::acceptance($enrollment),
            'activated_at' => $enrollment->activated_at?->toIso8601String(),
            'agreement_url' => $agreementUrl,
            'payment' => self::payment($enrollment, $terms, $terms, $clientAutopay, $timezone, $invoice === null || ! $invoice->is_visible_to_client ? null
                : $base.route('portal.invoice', [$company, $invoice], false)),
            // When the next billing period, and so its invoice, begins; not how
            // that invoice is made, which is the operator's setting.
            'next_period_start' => PackageBillingPeriods::nextPeriodStart($status, $agreement, (new WorkspaceClock)->today($timezone)),
            // The agreement's own cancellation terms, the end dates the client
            // could choose today, and what is pending or scheduled (CAN-2,
            // CAN-3, EDGE-9). Read-only here: a client cancels in the portal.
            'cancellation' => CancellationState::of($agreement, $agreement->cancellationRequests->first(), $agreement->scheduledTerminations->first(), (new WorkspaceClock)->today($timezone))->forClient(),
            // A scheduled move to new terms, with the old and new terms and the date (MIG-2).
            'move' => PackageMigrationPresenter::forClient($enrollment->migrationTargets->first(), PackageTerms::fromVersion($terms), $company),
            'url' => $url,
        ];
    }

    /**
     * What a client reads of a version's terms: the offer and the agreement
     * text, never the operator's settings (invoice issuing, lapse days).
     *
     * @return array<string, mixed>
     */
    public static function clientTerms(PackageTerms $terms): array
    {
        return [
            'name' => $terms->name,
            'summary' => $terms->summary,
            'body_markdown' => $terms->bodyMarkdown,
            'agreement_text' => $terms->agreementText,
            'currency' => $terms->currency,
            'billing_cadence' => $terms->billingCadence->value,
            'cycle_amount' => PublicPackagePresenter::cycleAmount($terms),
            'setup_fee_amount' => $terms->setupFeeTotal() > 0 ? $terms->setupFeeTotal() : null,
            'components' => array_map(static fn (PackageComponentTerms $component): array => [
                'description' => $component->description,
                'kind' => $component->kind->value,
                'cadence' => $component->cadence->value,
                'amount' => $component->total(),
            ], $terms->components),
            'hourly_rate_amount' => $terms->hourlyRateAmount,
            'retainer_amount' => $terms->retainerAmount,
            'retainer_minutes' => $terms->retainerMinutes,
            'rollover_months' => $terms->rolloverMonths,
            'activation_mode' => $terms->activationMode->value,
            'notice_days' => $terms->noticeDays,
            'minimum_term_months' => $terms->minimumTermMonths,
            'cancellation_mode' => $terms->cancellationMode->value,
        ];
    }

    /** Whether the version signed differs from the one offered (EDGE-7). */
    public static function versionChanged(ServicePackageEnrollment $enrollment): bool
    {
        return $enrollment->accepted_version_id !== null && $enrollment->accepted_version_id !== $enrollment->service_package_version_id;
    }

    /** @return array{accepted_at: string, signer_name: string, signer_title: string|null}|null */
    public static function acceptance(ServicePackageEnrollment $enrollment): ?array
    {
        if ($enrollment->accepted_at === null) {
            return null;
        }

        return [
            'accepted_at' => $enrollment->accepted_at->toIso8601String(),
            'signer_name' => (string) $enrollment->signer_name,
            'signer_title' => $enrollment->signer_title,
        ];
    }

    /** @return array{id: string, number: int} */
    private static function versionRef(ServicePackageVersion $version): array
    {
        return ['id' => $version->public_id, 'number' => $version->number];
    }

    private static function signupInvoice(ServicePackageEnrollment $enrollment): ?ClientInvoice
    {
        if ($enrollment->signup_invoice_id === null) {
            return null;
        }
        $invoice = $enrollment->signupInvoice;
        if (! $invoice instanceof ClientInvoice) {
            throw new LogicException('An enrollment names a sign-up invoice outside its workspace, or was presented without it loaded.');
        }

        return $invoice;
    }

    /**
     * The version among those loaded that {@see EnrollmentVersions::lapseWindow()} names.
     *
     * @param  list<ServicePackageVersion|null>  $loaded
     */
    private static function lapseWindow(ServicePackage $package, ServicePackageEnrollment $enrollment, array $loaded): ServicePackageVersion
    {
        $id = EnrollmentVersions::lapseWindow($package, $enrollment);
        foreach ($loaded as $version) {
            if ($version instanceof ServicePackageVersion && (int) $version->id === $id) {
                return $version;
            }
        }

        throw new LogicException('An enrollment was presented without the version its lapse window comes from loaded.');
    }

    /** @return array{ClientCompany, ClientProject, ClientAgreement, ServicePackage, ServicePackageVersion, ServicePackageVersion|null, ServicePackageVersion|null} */
    private static function loaded(ServicePackageEnrollment $enrollment): array
    {
        $current = $enrollment->current_version_id === null ? null : $enrollment->currentVersion;
        $company = $enrollment->clientCompany;
        $project = $enrollment->project;
        $agreement = $enrollment->agreement;
        $package = $enrollment->package;
        $offered = $enrollment->version;
        $accepted = $enrollment->accepted_version_id === null ? null : $enrollment->acceptedVersion;
        if (! $company instanceof ClientCompany || ! $project instanceof ClientProject || ! $agreement instanceof ClientAgreement
            || ! $package instanceof ServicePackage || ! $offered instanceof ServicePackageVersion
            || ($enrollment->accepted_version_id !== null && ! $accepted instanceof ServicePackageVersion)
            || ($enrollment->current_version_id !== null && ! $current instanceof ServicePackageVersion)) {
            throw new LogicException('An enrollment names a record outside its workspace, or was presented without its relations loaded.');
        }

        return [$company, $project, $agreement, $package, $offered, $accepted, $current];
    }
}
