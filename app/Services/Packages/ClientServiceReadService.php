<?php

namespace App\Services\Packages;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\CompanyContacts;
use App\Services\Authorization\PortalAccess;
use App\Services\Billing\InvoicePaymentOptionsReader;
use App\Services\Billing\PortalPaymentMethodService;
use App\Services\Engagement\PortalCancellationPanel;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\Billing\Autopay\AutopayWording;
use App\Support\Billing\InvoiceLineType;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\EnrollmentVersions;
use App\Support\Packages\PackageEnrollmentPresenter;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PortalAddRule;
use App\Support\Packages\PublicPackagePresenter;
use App\Support\Packages\SignupChargeKind;
use App\Support\Packages\SignupCharges;
use App\Support\Packages\SignupPaymentState;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A client's own services, for the client: `client_services.*` over the agent
 * API (API-5) and the portal's Services pages.
 *
 * A portal contact sees their company's enrollments - only their projects'
 * when their access is limited to projects - with the terms they agreed to,
 * or before acceptance the terms on offer. Only a company-wide contact is
 * offered the packages they could add (ACC-8). Withdrawn offers are not
 * shown. Workspace staff reach enrollments through `enrollments.*` instead,
 * except an owner or admin previewing the portal, who sees what the company's
 * contacts see.
 *
 * Every page is presented from bounded loads: the enrollments with their
 * records, then one query for every version whose terms are shown.
 */
final class ClientServiceReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly PortalAccess $portal,
        private readonly CompanyContacts $contacts,
        private readonly PackageAutopayRequirement $autopay,
        private readonly PaymentConnectionResolver $payments,
        private readonly InvoicePaymentOptionsReader $paymentOptions,
        private readonly PortalPaymentMethodService $methodManagement,
        private readonly PortalCancellationPanel $cancellationPanel,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, addable_packages: list<array<string, mixed>>, meta: array{next_cursor: string|null}}
     */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?string $companyId = null, int $limit = 25, ?string $cursor = null): array
    {
        $query = $this->visibleToContact($actor, $workspace);
        if ($companyId !== null) {
            $query->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id)->where('public_id', $companyId));
        }
        $key = 'client_services|'.($companyId ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $key);
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        $enrollments = PackageEnrollmentReadService::withRelations($workspace, $query)->orderByDesc('id')->limit($limit + 1)->get();
        $more = $enrollments->count() > $limit;
        if ($more) {
            $enrollments->pop();
        }
        $last = $enrollments->last();

        return [
            'data' => $this->present($workspace, $enrollments),
            'addable_packages' => $this->companyContactIn($actor, $workspace, $companyId) ? $this->addable($workspace, null) : [],
            'meta' => ['next_cursor' => $more && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $key) : null],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        $enrollment = PackageEnrollmentReadService::withRelations($workspace, $this->visibleToContact($actor, $workspace))->where('public_id', $publicId)->firstOrFail();

        return $this->present($workspace, new Collection([$enrollment]))[0];
    }

    /**
     * The portal's Services page: this company's services as the viewer may
     * see them, and - for a company-wide contact only - what they could add.
     * Null `addable_packages` means the viewer may not add, and the section
     * is not drawn.
     *
     * @return array{services: list<array<string, mixed>>, addable_packages: list<array<string, mixed>>|null}
     */
    public function forPortal(User $viewer, ClientCompany $company): array
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $enrollments = PackageEnrollmentReadService::withRelations($workspace, $this->visibleInPortal($viewer, $company))->orderByDesc('id')->get();

        return [
            'services' => $this->present($workspace, $enrollments),
            'addable_packages' => $this->contacts->isCompanyContact($company, $viewer) ? $this->addable($workspace, $company) : null,
        ];
    }

    /** One of this company's enrollments, as the viewer may see it in the portal; 404 otherwise. */
    public function findForPortal(User $viewer, ClientCompany $company, string $publicId): ServicePackageEnrollment
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);

        return PackageEnrollmentReadService::withRelations($workspace, $this->visibleInPortal($viewer, $company))->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * One service's review-and-sign page: its terms (the version an acceptance
     * signs now, or the one signed), and - when a newer version was published
     * after the offer - the offered terms and which of them changed (EDGE-7).
     * `accept_url` is set only while the offer is open, it can be accepted
     * now, and the viewer is a company-wide contact (ACC-1).
     *
     * `checkout` is what sign-up asks for (ACC-3, ACC-4), or null when it
     * asks for nothing: what is due now, line by line (an estimate before
     * acceptance, the sign-up invoice's own lines after), and for a
     * company-wide contact the finished URLs that pay it, save a card and turn
     * autopay on at the version's limit with the exact wording.
     *
     * @return array{service: array<string, mixed>, offered_terms: array<string, mixed>|null, changed_terms: list<string>, accept_url: string|null, accept_refusal: array{code: string, message: string}|null, checkout: array<string, mixed>|null, cancellation: array<string, mixed>|null}
     */
    public function reviewForPortal(User $viewer, ClientCompany $company, string $publicId): array
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $enrollment = $this->findForPortal($viewer, $company, $publicId);
        $package = $enrollment->package ?? throw new \LogicException('An enrollment names a package outside its workspace.');
        $shownId = EnrollmentVersions::shown($package, $enrollment);
        $versions = $this->versions($workspace, [$shownId, $enrollment->service_package_version_id]);
        $shown = PackageTerms::fromVersion($versions->get($shownId) ?? throw new \LogicException('An enrollment names a version outside its workspace.'));
        $offered = PackageTerms::fromVersion($versions->get($enrollment->service_package_version_id) ?? throw new \LogicException('An enrollment names a version outside its workspace.'));
        $changes = $shownId === $enrollment->service_package_version_id ? [] : $shown->differences($offered);

        $open = $enrollment->enrollmentStatus() === EnrollmentStatus::Offered;
        $refusal = match (true) {
            ! $open => null,
            EnrollmentVersions::toAccept($package, $enrollment) === null => PackageRefusal::OfferNotOpen,
            $shown->involvesPayment() && $this->payments->stripe($workspace) === null => PackageRefusal::PaymentUnavailable,
            default => null,
        };
        $presented = $this->present($workspace, new Collection([$enrollment]))[0];

        return [
            'service' => $presented,
            'checkout' => $this->checkout($workspace, $company, $viewer, $enrollment, $shown, $presented['payment']),
            'offered_terms' => $changes === [] ? null : PackageEnrollmentPresenter::clientTerms($offered),
            'changed_terms' => $changes,
            'accept_url' => $open && $refusal === null && $this->contacts->isCompanyContact($company, $viewer)
                ? route('portal.services.accept', [$company, $enrollment->public_id], absolute: false)
                : null,
            'accept_refusal' => $refusal === null ? null : ['code' => $refusal->value, 'message' => $refusal->message()],
            // Ending the service from the portal (CAN-3, EDGE-9); null when there is nothing to show.
            'cancellation' => $enrollment->agreement === null ? null : $this->cancellationPanel->for($viewer, $company, $enrollment->agreement),
        ];
    }

    /**
     * The portal page of one of this company's services, finished, when the
     * viewer may see it; null otherwise. Where turning autopay on for a
     * sign-up sends the client back to.
     */
    public function portalServiceUrl(User $viewer, ClientCompany $company, string $publicId): ?string
    {
        $found = $this->visibleInPortal($viewer, $company)->where('public_id', $publicId)->first();

        return $found === null ? null : route('portal.services.show', [$company, $found->public_id], absolute: false);
    }

    /**
     * The warning the portal shows before the card autopay charges is removed,
     * when a service of this company requires autopay (EDGE-2); null when none
     * does. Removing it stays allowed: it turns autopay off and nothing is
     * suspended.
     */
    public function autopayRemovalWarning(Workspace $workspace, ClientCompany $company): ?string
    {
        if (! EnsureServicePackagesEnabled::enabled()) {
            return null;
        }
        $names = [];
        foreach (ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('status', [EnrollmentStatus::AwaitingPayment->value, EnrollmentStatus::AwaitingReview->value, EnrollmentStatus::Active->value])
            ->with(['version' => fn ($version) => $version->where('workspace_id', $workspace->id), 'acceptedVersion' => fn ($version) => $version->where('workspace_id', $workspace->id)])
            ->orderBy('id')
            ->limit(25)
            ->get() as $enrollment) {
            $carried = $enrollment->accepted_version_id === null ? $enrollment->version : $enrollment->acceptedVersion;
            if ($carried instanceof ServicePackageVersion && $carried->require_autopay) {
                $names[] = $carried->name;
            }
        }
        if ($names === []) {
            return null;
        }

        return implode(', ', array_values(array_unique($names))).' '.(count(array_unique($names)) === 1 ? 'requires' : 'require')
            .' autopay. Removing this payment method turns autopay off, and '.$workspace->name.' will follow up with you about how to pay.';
    }

    /**
     * What sign-up asks for on the review page, or null when it asks nothing.
     *
     * @param  array<string, mixed>  $payment  the service's presented payment block
     * @return array<string, mixed>|null
     */
    private function checkout(Workspace $workspace, ClientCompany $company, User $viewer, ServicePackageEnrollment $enrollment, PackageTerms $terms, array $payment): ?array
    {
        if (! $terms->chargesAtSignup() && ! $terms->requireAutopay) {
            return null;
        }
        $status = $enrollment->enrollmentStatus();
        $acting = $this->contacts->isCompanyContact($company, $viewer);
        $invoice = $enrollment->signup_invoice_id === null ? null : $enrollment->signupInvoice;
        $collecting = $invoice instanceof ClientInvoice && SignupPaymentState::from((string) $payment['state']) === SignupPaymentState::Due;
        $open = match ($status) {
            EnrollmentStatus::Offered, EnrollmentStatus::AwaitingPayment, EnrollmentStatus::AwaitingReview, EnrollmentStatus::Active => true,
            EnrollmentStatus::Lapsed, EnrollmentStatus::Withdrawn => false,
        };
        $limit = $terms->requireAutopay ? (int) $terms->autopayLimitAmount : null;
        ['management' => $management, 'publishable_key' => $publishableKey] = $this->methodManagement->access($workspace, $company, $viewer);

        return [
            'due_now' => $invoice instanceof ClientInvoice ? $this->invoiceCharges($workspace, $invoice) : [
                ...SignupCharges::estimate($terms, $this->clock->today($workspace))->toArray(),
                'estimate' => true,
            ],
            'pay_before_activation' => $terms->payBeforeActivation,
            // The sign-up invoice's own portal page and the pay flow it already
            // has, for a company contact while something is owed on it.
            'pay_url' => $acting && $open && $collecting ? $payment['signup_invoice']['url'] : null,
            'payment_options' => $acting && $open && $collecting ? $this->paymentOptions->for($workspace, $invoice)['stripe'] : null,
            'card_setup' => $acting && $open && $limit !== null && $management->mayAdd() && $publishableKey !== null ? [
                'setup_url' => route('portal.payment-methods.setup', $company, absolute: false),
                'confirm_url' => route('portal.payment-methods.confirm', $company, absolute: false),
                'publishable_key' => $publishableKey,
            ] : null,
            'autopay' => $limit === null ? null : $this->autopayStep($workspace, $company, $enrollment, $terms, $limit, $acting && $open && $status !== EnrollmentStatus::Offered),
        ];
    }

    /**
     * Autopay at the version's limit: whether it is on, the exact sentence the
     * client authorizes (with their default method; null until one is saved),
     * and the existing enable endpoint with the fields it takes. The enable
     * URL is offered once the offer is accepted, to a company-wide contact.
     * Autopay in a currency other than the version's can never meet it, so
     * then nothing is offered and `unavailable` says why.
     *
     * @return array<string, mixed>
     */
    private function autopayStep(Workspace $workspace, ClientCompany $company, ServicePackageEnrollment $enrollment, PackageTerms $terms, int $limit, bool $mayEnable): array
    {
        $currency = $terms->currency;
        $satisfied = $this->autopay->satisfied($workspace, $company, $terms);
        $unavailable = $satisfied || $this->autopay->currencyMatches($workspace, $company, $terms) ? null
            : PackageRefusal::AutopayCurrencyMismatch->message();
        $wording = $unavailable === null ? $this->autopay->wording($workspace, $company, $limit) : null;

        return [
            'limit_amount' => $limit,
            'currency' => $currency,
            'limit_label' => AutopayWording::money($limit, $currency),
            'satisfied' => $satisfied,
            'unavailable' => $unavailable,
            'wording' => $wording,
            'enable_url' => $mayEnable && ! $satisfied && $wording !== null ? route('portal.autopay.enable', $company, absolute: false) : null,
            'enable_fields' => $mayEnable && ! $satisfied && $wording !== null ? ['limit_amount' => $limit, 'wording' => $wording, 'service' => $enrollment->public_id] : null,
            'billing_url' => route('portal.billing', $company, absolute: false),
        ];
    }

    /**
     * The sign-up invoice's lines as the "Due now" breakdown: its setup fee
     * lines, and the first period as one line carrying the cycle it sells.
     *
     * @return array<string, mixed>
     */
    private function invoiceCharges(Workspace $workspace, ClientInvoice $invoice): array
    {
        $lines = ClientInvoiceLine::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['type', 'description', 'total_amount']);
        $setup = [];
        $period = 0;
        foreach ($lines as $line) {
            if ($line->type === InvoiceLineType::Adjustment->value) {
                $setup[] = ['kind' => SignupChargeKind::SetupFee->value, 'description' => (string) $line->description, 'amount' => (int) $line->total_amount, 'period_start' => null, 'period_end' => null];
            } else {
                $period += (int) $line->total_amount;
            }
        }
        $breakdown = $setup;
        if ($invoice->cycle_start !== null) {
            $breakdown[] = [
                'kind' => SignupChargeKind::FirstPeriod->value,
                'description' => (string) $invoice->invoice_number,
                'amount' => $period,
                'period_start' => $invoice->cycle_start->toDateString(),
                'period_end' => $invoice->cycle_end?->toDateString(),
            ];
        }

        return [
            'currency' => $invoice->currency,
            'lines' => $breakdown,
            'total' => (int) $invoice->total_amount,
            'balance' => (int) $invoice->balance_amount,
            'estimate' => false,
        ];
    }

    /**
     * Enrollments of companies this principal is a portal contact of, narrowed
     * to their projects when their access is limited (API-5).
     *
     * @return Builder<ServicePackageEnrollment>
     */
    private function visibleToContact(User|AgentPrincipal $actor, Workspace $workspace): Builder
    {
        abort_unless($this->access->canViewWorkspace($actor, $workspace), 404);

        return ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', EnrollmentStatus::Withdrawn->value)
            ->whereExists(fn (QueryBuilder $membership): QueryBuilder => $membership->selectRaw('1')->from('client_company_memberships')
                ->whereColumn('client_company_memberships.workspace_id', 'service_package_enrollments.workspace_id')
                ->whereColumn('client_company_memberships.client_company_id', 'service_package_enrollments.client_company_id')
                ->where('client_company_memberships.user_id', $actor->getAuthIdentifier()))
            ->whereIn('client_project_id', $this->portal->constrainProjectQuery(ClientProject::query()->where('workspace_id', $workspace->id), $actor)->select('id'));
    }

    /**
     * One company's enrollments for a portal viewer, by the portal's own
     * project rule: everything for a company-wide contact or an owner or
     * admin previewing, the granted projects for anyone narrower.
     *
     * @return Builder<ServicePackageEnrollment>
     */
    private function visibleInPortal(User $viewer, ClientCompany $company): Builder
    {
        $projects = $this->portal->visibleProjectIds($company, $viewer);

        return ServicePackageEnrollment::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('status', '!=', EnrollmentStatus::Withdrawn->value)
            ->when($projects !== null, fn (Builder $query): Builder => $query->whereIn('client_project_id', $projects ?? []));
    }

    private function companyContactIn(User|AgentPrincipal $actor, Workspace $workspace, ?string $companyId): bool
    {
        return ClientCompanyMembership::query()
            ->where('client_company_memberships.workspace_id', $workspace->id)
            ->where('client_company_memberships.user_id', $actor->getAuthIdentifier())
            ->where('client_company_memberships.access_scope', ClientCompanyMembership::SCOPE_COMPANY)
            ->when($companyId !== null, fn (Builder $query): Builder => $query->whereIn('client_company_memberships.client_company_id', ClientCompany::query()
                ->where('workspace_id', $workspace->id)->where('public_id', $companyId)->select('id')))
            ->exists();
    }

    /**
     * The workspace's published, portal-addable packages, each with the reason
     * it cannot be added now, if any. With a company, each also carries the
     * portal address that adds it, when it may be added.
     *
     * @return list<array<string, mixed>>
     */
    private function addable(Workspace $workspace, ?ClientCompany $company): array
    {
        $packages = ServicePackage::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', PackageStatus::Published->value)
            ->where('portal_addable', true)
            ->whereNotNull('published_version_id')
            ->orderBy('id')
            ->get();
        $versions = $this->versions($workspace, array_values(array_map(static fn (ServicePackage $package): ?int => $package->published_version_id, $packages->all())));
        $stripe = $this->payments->stripe($workspace) !== null;

        $addable = [];
        foreach ($packages as $package) {
            $version = $versions->get((int) $package->published_version_id);
            if (! $version instanceof ServicePackageVersion) {
                continue;
            }
            $terms = PackageTerms::fromVersion($version);
            $refusal = PortalAddRule::refusal($package, $terms, $stripe);
            $addable[] = [
                'id' => $package->public_id,
                ...PublicPackagePresenter::summary($workspace, $package, $terms),
                'add_refusal' => $refusal === null ? null : ['code' => $refusal->value, 'message' => $refusal->message()],
                ...($company === null ? [] : ['add_url' => $refusal === null ? route('portal.services.add', $company, absolute: false) : null]),
            ];
        }

        return $addable;
    }

    /**
     * @param  Collection<int, ServicePackageEnrollment>  $enrollments  loaded with their relations
     * @return list<array<string, mixed>>
     */
    private function present(Workspace $workspace, Collection $enrollments): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }
        $shown = [];
        foreach ($enrollments as $enrollment) {
            $package = $enrollment->package ?? throw new \LogicException('An enrollment names a package outside its workspace.');
            $shown[$enrollment->id] = EnrollmentVersions::shown($package, $enrollment);
        }
        $versions = $this->versions($workspace, array_values(array_unique($shown)));
        $base = rtrim((string) config('app.url'), '/');
        $autopay = $this->autopay->clientAutopay($workspace, array_values($enrollments->pluck('client_company_id')->all()));
        $timezone = $this->clock->now($workspace)->tzName;

        return array_values($enrollments->map(static function (ServicePackageEnrollment $enrollment) use ($versions, $shown, $base, $autopay, $timezone): array {
            $company = $enrollment->clientCompany ?? throw new \LogicException('An enrollment names a company outside its workspace.');

            return PackageEnrollmentPresenter::forClient(
                $enrollment,
                $versions->get($shown[$enrollment->id]) ?? throw new \LogicException('An enrollment names a version outside its workspace.'),
                $base.route('portal.services.show', [$company, $enrollment->public_id], false),
                $autopay[$enrollment->client_company_id] ?? null,
                $timezone,
            );
        })->all());
    }

    /**
     * @param  list<int|null>  $ids
     * @return Collection<int, ServicePackageVersion> keyed by id
     */
    private function versions(Workspace $workspace, array $ids): Collection
    {
        return ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_filter($ids, static fn (mixed $id): bool => $id !== null)))
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->get()
            ->keyBy('id');
    }
}
