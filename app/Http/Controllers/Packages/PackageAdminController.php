<?php

namespace App\Http\Controllers\Packages;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\ProjectAccess;
use App\Services\Packages\PackageMigrationPlanner;
use App\Services\Packages\PackageMigrationReadService;
use App\Services\Packages\PackageRequestReadService;
use App\Services\Packages\ServicePackageReadService;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\ChargeCadence;
use App\Support\Packages\CancellationMode;
use App\Support\Packages\IntakeQuestionKind;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\PackageActivationMode;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageInvoiceIssuing;
use App\Support\Packages\PackagePublicUrls;
use App\Support\Packages\PackageRequestStatus;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner and admin package screens: the list, the editor and moving a
 * version's subscribers to the published one.
 *
 * Pages only. Every change posts to the agent REST endpoints the server names
 * here, which run ServicePackageWorkflow, so the website and agents share one
 * write path (API-1). A stranger gets a 404; a member who does not manage the
 * workspace gets 403.
 */
final class PackageAdminController extends Controller
{
    public function __construct(
        private readonly ServicePackageReadService $reads,
        private readonly AgentAccess $access,
        private readonly PaymentConnectionResolver $payments,
        private readonly ProjectAccess $roles,
        private readonly PackageRequestReadService $requestReads,
        private readonly PackageMigrationReadService $migrationReads,
        private readonly PackageMigrationPlanner $planner,
    ) {}

    /** Packages a page of the list shows; the rest are a page link away (Codex P2 on #665). */
    public const PAGE_SIZE = 100;

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $this->manager($request, $workspace);
        $cursor = $request->query('cursor');
        $page = $this->reads->list($user, $workspace, limit: self::PAGE_SIZE, cursor: is_string($cursor) ? $cursor : null);

        return Inertia::render('packages/index', [
            ...$this->workspaceProps($workspace),
            'packages' => array_map(fn (array $package): array => [
                ...$package,
                'edit_url' => route('packages.edit', ['workspace' => $workspace, 'servicePackage' => $package['id']], absolute: false),
            ], $page['data']),
            'next_page_url' => $page['meta']['next_cursor'] === null ? null : route('packages.index', ['workspace' => $workspace, 'cursor' => $page['meta']['next_cursor']], absolute: false),
            'first_page_url' => is_string($cursor) ? route('packages.index', $workspace, absolute: false) : null,
            'new_package_url' => route('packages.create', $workspace, absolute: false),
        ]);
    }

    public function create(Request $request, Workspace $workspace): Response
    {
        $this->manager($request, $workspace);

        return Inertia::render('packages/edit', [
            ...$this->workspaceProps($workspace),
            ...$this->vocabularies(),
            'package' => null,
            'defaults' => PackageTerms::defaults((string) ($workspace->default_currency ?? 'USD')),
            'urls' => [
                'index' => route('packages.index', $workspace, absolute: false),
                'store' => route('agent-api.v1.packages.store', $workspace, absolute: false),
                'update' => null,
                'publish' => null,
                'retire' => null,
            ],
        ]);
    }

    public function edit(Request $request, Workspace $workspace, ServicePackage $servicePackage): Response
    {
        $user = $this->manager($request, $workspace);
        $package = $this->reads->get($user, $workspace, $servicePackage->public_id);
        $status = $servicePackage->packageStatus();
        $parameters = ['workspace' => $workspace, 'package' => $servicePackage->public_id];

        return Inertia::render('packages/edit', [
            ...$this->workspaceProps($workspace),
            ...$this->vocabularies(),
            'package' => $package,
            'moves' => $this->movesPanel($user, $workspace, $servicePackage),
            'defaults' => null,
            'urls' => [
                'index' => route('packages.index', $workspace, absolute: false),
                'store' => null,
                'update' => route('agent-api.v1.packages.update', $parameters, absolute: false),
                'publish' => $package['draft'] === null ? null : route('agent-api.v1.packages.publish', $parameters, absolute: false),
                'retire' => $status === PackageStatus::Published ? route('agent-api.v1.packages.retire', $parameters, absolute: false) : null,
            ],
        ]);
    }

    /**
     * Moving a version's subscribers to the published version (MIG-1, MIG-4):
     * what would change, each subscriber's dates and autopay flag, the date
     * chosen (or suggested), and the endpoint that schedules it. Changes
     * nothing; another date asks this page again.
     */
    public function move(Request $request, Workspace $workspace, ServicePackage $servicePackage): Response
    {
        $user = $this->manager($request, $workspace);
        $data = $request->validate([
            'from' => ['required', 'string', 'uuid'],
            'effective_on' => ['nullable', 'string', 'date_format:Y-m-d'],
        ]);
        $package = $this->reads->find($user, $workspace, $servicePackage->public_id);

        return Inertia::render('packages/move', [
            ...$this->workspaceProps($workspace),
            'preview' => $this->planner->preview($user, $workspace, $package, $data['from'], $data['effective_on'] ?? null),
            'package_version' => AgentApiVersion::for($package),
            'urls' => [
                'schedule' => route('agent-api.v1.package-migrations.store', ['workspace' => $workspace, 'package' => $package->public_id], absolute: false),
                'editor' => route('packages.edit', ['workspace' => $workspace, 'servicePackage' => $package->public_id], absolute: false),
                'preview' => route('packages.move', ['workspace' => $workspace, 'servicePackage' => $package->public_id, 'from' => $data['from']], absolute: false),
            ],
        ]);
    }

    /**
     * The editor's versions and moves: each published version with its active
     * subscribers and, for an older one with subscribers while the package is
     * published, where moving them starts; and the package's moves, newest
     * first, each with its cancel endpoint while it may be cancelled.
     *
     * @return array{versions: list<array<string, mixed>>, moves: list<array<string, mixed>>}
     */
    private function movesPanel(User $user, Workspace $workspace, ServicePackage $package): array
    {
        $published = $package->packageStatus() === PackageStatus::Published ? $package->published_version_id : null;
        $current = null;
        $versions = $this->migrationReads->versions($workspace, $package);
        foreach ($versions as ['version' => $version]) {
            if ($version->id === $published) {
                $current = $version->number;
            }
        }

        return [
            'versions' => array_map(fn (array $row): array => [
                'id' => $row['version']->public_id,
                'number' => $row['version']->number,
                'published_at' => $row['version']->published_at?->toIso8601String(),
                'subscribers' => $row['subscribers'],
                'move_url' => $current !== null && $row['version']->number < $current && $row['subscribers'] > 0
                    ? route('packages.move', ['workspace' => $workspace, 'servicePackage' => $package->public_id, 'from' => $row['version']->public_id], absolute: false)
                    : null,
            ], $versions),
            'moves' => array_map(fn (array $move): array => [
                ...$move,
                'cancel_url' => $move['status'] === MigrationStatus::Scheduled->value
                    ? route('agent-api.v1.package-migrations.cancel', ['workspace' => $workspace, 'migration' => $move['id']], absolute: false)
                    : null,
            ], $this->migrationReads->list($user, $workspace, limit: self::PAGE_SIZE, package: $package)['data']),
        ];
    }

    /** The sign-up request queue (ADM-1), with every decision as a finished URL. */
    public function requests(Request $request, Workspace $workspace): Response
    {
        $user = $this->manager($request, $workspace);
        $cursor = $request->query('cursor');
        $page = $this->requestReads->list($user, $workspace, limit: self::PAGE_SIZE, cursor: is_string($cursor) ? $cursor : null);

        return Inertia::render('packages/requests', [
            ...$this->workspaceProps($workspace),
            'requests' => array_map(function (array $packageRequest) use ($workspace): array {
                $parameters = ['workspace' => $workspace, 'request' => $packageRequest['id']];
                $open = $packageRequest['status'] === PackageRequestStatus::New->value;

                return [
                    ...$packageRequest,
                    'urls' => [
                        'approve' => $open ? route('agent-api.v1.package-requests.approve', $parameters, absolute: false) : null,
                        'decline' => $open ? route('agent-api.v1.package-requests.decline', $parameters, absolute: false) : null,
                        'erase' => ! $open && $packageRequest['erased_at'] === null ? route('agent-api.v1.package-requests.erase', $parameters, absolute: false) : null,
                    ],
                ];
            }, $page['data']),
            // Older requests are a page link away (Codex P2 on #666).
            'next_page_url' => $page['meta']['next_cursor'] === null ? null : route('packages.requests.index', ['workspace' => $workspace, 'cursor' => $page['meta']['next_cursor']], absolute: false),
            'first_page_url' => is_string($cursor) ? route('packages.requests.index', $workspace, absolute: false) : null,
            // Existing clients a request may be attached to instead of creating one (EDGE-4).
            'clients' => ClientCompany::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['public_id', 'name'])
                ->map(static fn (ClientCompany $company): array => ['id' => $company->public_id, 'name' => $company->name])->values()->all(),
        ]);
    }

    private function manager(Request $request, Workspace $workspace): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // A stranger learns nothing; a member who does not manage is refused.
        abort_if($this->roles->workspaceRole($user, $workspace) === null, 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function workspaceProps(Workspace $workspace): array
    {
        return [
            'catalog_url' => PackagePublicUrls::catalog($workspace),
            'stripe_connected' => $this->payments->stripe($workspace) !== null,
            'requests_url' => route('packages.requests.index', $workspace, absolute: false),
            'open_requests_count' => $this->requestReads->openCount($workspace),
        ];
    }

    /**
     * Every choice the editor offers, from the enums themselves, so a select
     * can never offer a value the server would refuse.
     *
     * @return array{options: array<string, list<string>>}
     */
    private function vocabularies(): array
    {
        return ['options' => [
            'billing_cadences' => array_map(static fn (ChargeCadence $case): string => $case->value, ChargeCadence::cases()),
            'component_kinds' => array_map(static fn (PackageComponentKind $case): string => $case->value, PackageComponentKind::cases()),
            'activation_modes' => array_map(static fn (PackageActivationMode $case): string => $case->value, PackageActivationMode::cases()),
            'invoice_issuing' => array_map(static fn (PackageInvoiceIssuing $case): string => $case->value, PackageInvoiceIssuing::cases()),
            'cancellation_modes' => array_map(static fn (CancellationMode $case): string => $case->value, CancellationMode::cases()),
            'question_kinds' => array_map(static fn (IntakeQuestionKind $case): string => $case->value, IntakeQuestionKind::cases()),
        ]];
    }
}
