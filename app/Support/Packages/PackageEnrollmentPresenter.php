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
        [$company, $project, $agreement, $package, $offered, $accepted] = self::loaded($enrollment);
        $base = rtrim((string) config('app.url'), '/');
        $invoice = self::signupInvoice($enrollment);

        return [
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'package' => ['id' => $package->public_id, 'link_name' => $package->link_name, 'name' => ($accepted ?? $offered)->name],
            'offered_version' => self::versionRef($offered),
            'accepted_version' => $accepted === null ? null : self::versionRef($accepted),
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
            'payment' => self::payment($enrollment, $accepted ?? $offered, $clientAutopay, (new WorkspaceClock)->now($workspace)->tzName, $invoice === null ? null
                : $base.route('clients.invoice', ['workspace' => $workspace, 'clientCompany' => $company, 'clientInvoice' => $invoice], false)),
            'version' => AgentApiVersion::for($enrollment),
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
     * @param  ClientAutopay|null  $clientAutopay  where the company's autopay stands on the client's own consent, if it does
     * @param  string|null  $invoiceUrl  the sign-up invoice's page for this reader, finished
     * @return array{state: string, waiting_for: list<string>, signup_invoice: array<string, mixed>|null, requires_autopay: bool, autopay_limit_amount: int|null, pay_before_activation: bool, lapses_on: string|null}
     */
    public static function payment(ServicePackageEnrollment $enrollment, ServicePackageVersion $carried, ?ClientAutopay $clientAutopay, string $timezone, ?string $invoiceUrl): array
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
            'lapses_on' => EnrollmentLapse::lapsesOn($status, $enrollment->offered_at, $enrollment->accepted_at, $terms->paymentLapseDays, $timezone)?->toDateString(),
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
            'payment' => self::payment($enrollment, $terms, $clientAutopay, $timezone, $invoice === null || ! $invoice->is_visible_to_client ? null
                : $base.route('portal.invoice', [$company, $invoice], false)),
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

    /** @return array{ClientCompany, ClientProject, ClientAgreement, ServicePackage, ServicePackageVersion, ServicePackageVersion|null} */
    private static function loaded(ServicePackageEnrollment $enrollment): array
    {
        $company = $enrollment->clientCompany;
        $project = $enrollment->project;
        $agreement = $enrollment->agreement;
        $package = $enrollment->package;
        $offered = $enrollment->version;
        $accepted = $enrollment->accepted_version_id === null ? null : $enrollment->acceptedVersion;
        if (! $company instanceof ClientCompany || ! $project instanceof ClientProject || ! $agreement instanceof ClientAgreement
            || ! $package instanceof ServicePackage || ! $offered instanceof ServicePackageVersion
            || ($enrollment->accepted_version_id !== null && ! $accepted instanceof ServicePackageVersion)) {
            throw new LogicException('An enrollment names a record outside its workspace, or was presented without its relations loaded.');
        }

        return [$company, $project, $agreement, $package, $offered, $accepted];
    }
}
