<?php

namespace App\Http\Controllers\Packages;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Packages\ServicePackageReadService;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\Billing\ChargeCadence;
use App\Support\Packages\CancellationMode;
use App\Support\Packages\IntakeQuestionKind;
use App\Support\Packages\PackageActivationMode;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageInvoiceIssuing;
use App\Support\Packages\PackagePublicUrls;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner and admin package screens: the list and the editor.
 *
 * Pages only. Every change posts to the agent REST endpoints the server names
 * here, which run ServicePackageWorkflow, so the website and agents share one
 * write path (API-1). A stranger gets the binding's 404; a member who does not
 * manage the workspace gets 403.
 */
final class PackageAdminController extends Controller
{
    public function __construct(
        private readonly ServicePackageReadService $reads,
        private readonly AgentAccess $access,
        private readonly PaymentConnectionResolver $payments,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $this->manager($request, $workspace);

        return Inertia::render('packages/index', [
            ...$this->workspaceProps($workspace),
            'packages' => array_map(fn (array $package): array => [
                ...$package,
                'edit_url' => route('packages.edit', ['workspace' => $workspace, 'servicePackage' => $package['id']], absolute: false),
            ], $this->reads->list($user, $workspace, limit: 100)['data']),
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

    private function manager(Request $request, Workspace $workspace): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function workspaceProps(Workspace $workspace): array
    {
        return [
            'catalog_url' => PackagePublicUrls::catalog($workspace),
            'stripe_connected' => $this->payments->stripe($workspace) !== null,
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
        $values = static fn (array $cases): array => array_map(static fn (\BackedEnum $case): string|int => $case->value, $cases);

        return ['options' => [
            'billing_cadences' => $values(ChargeCadence::cases()),
            'component_kinds' => $values(PackageComponentKind::cases()),
            'activation_modes' => $values(PackageActivationMode::cases()),
            'invoice_issuing' => $values(PackageInvoiceIssuing::cases()),
            'cancellation_modes' => $values(CancellationMode::cases()),
            'question_kinds' => $values(IntakeQuestionKind::cases()),
        ]];
    }
}
