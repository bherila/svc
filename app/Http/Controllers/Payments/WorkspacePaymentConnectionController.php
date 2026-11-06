<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspacePaymentConnection;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\PaymentConnectionService;
use App\Services\Payments\PaymentCredentials;
use App\Services\Payments\PaymentCredentialsRejected;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Services\Square\SquareConfiguration;
use App\Support\Payments\PaymentProcessor;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * A workspace's payment connections: Stripe and Square keys entered once by an
 * owner or admin. The page carries the masked hint of a stored key, never the
 * key, the signing secret or any id; every URL arrives finished. The page
 * writes through the session-authenticated fetch helper, so each action answers
 * 204 or a validation refusal and has no JSON branch of its own.
 */
class WorkspacePaymentConnectionController extends Controller
{
    public function __construct(
        private readonly WorkspacePaymentConnections $connections,
        private readonly PaymentConnectionService $service,
        private readonly PaymentConnectionResolver $resolver,
    ) {}

    public function show(Request $request, Workspace $workspace): InertiaResponse
    {
        $user = $this->user($request);
        abort_unless(WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->exists(), 403);
        $manages = $this->connections->manages($user, $workspace);

        $processors = [];
        foreach (PaymentProcessor::cases() as $processor) {
            $row = $this->connections->activeRow($workspace, $processor);
            $reusable = $manages && $row === null
                ? $this->connections->reusableFor($workspace, $processor, $user)
                : collect();
            $names = Workspace::query()->whereIn('id', $reusable->pluck('workspace_id'))->pluck('name', 'id');
            $processors[] = [
                'processor' => $processor->value,
                'label' => $processor->label(),
                'connection' => $row === null ? null : $this->present($row, $manages),
                'environment_path_active' => $row === null && $this->environmentCredentialsServe($workspace, $processor),
                'reusable' => $reusable->map(fn (WorkspacePaymentConnection $source): array => [
                    'id' => $source->public_id,
                    'label' => 'Use the '.$processor->label().' account connected to '.($names[$source->workspace_id] ?? 'another workspace'),
                ])->values()->all(),
                'save_url' => $manages ? route('workspaces.payments.store', [$workspace, $processor->value], absolute: false) : null,
                'reuse_url' => $manages ? route('workspaces.payments.reuse', [$workspace, $processor->value], absolute: false) : null,
                'remove_url' => $manages && $row !== null ? route('workspaces.payments.destroy', [$workspace, $processor->value], absolute: false) : null,
            ];
        }

        return Inertia::render('workspaces/payments', [
            'workspaceName' => $workspace->name,
            'canManage' => $manages,
            'processors' => $processors,
        ]);
    }

    public function store(Request $request, Workspace $workspace, PaymentProcessor $processor): Response
    {
        $user = $this->user($request);
        $input = $request->validate(match ($processor) {
            PaymentProcessor::Stripe => [
                'secret' => ['required', 'string', 'min:12', 'max:512'],
            ],
            PaymentProcessor::Square => [
                'secret' => ['required', 'string', 'min:12', 'max:512'],
                'webhook_secret' => ['required', 'string', 'min:8', 'max:512'],
                'location_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
                'environment' => ['required', 'string', 'in:production,sandbox'],
            ],
        });
        try {
            $this->service->save($workspace, $processor, new PaymentCredentials(
                trim((string) $input['secret']),
                isset($input['webhook_secret']) ? trim((string) $input['webhook_secret']) : null,
                $input['location_id'] ?? null,
                $input['environment'] ?? null,
            ), $user);
        } catch (AuthorizationException) {
            abort(403);
        } catch (PaymentCredentialsRejected $rejected) {
            throw ValidationException::withMessages(['secret' => $rejected->getMessage()]);
        }

        return response()->noContent();
    }

    public function reuse(Request $request, Workspace $workspace, PaymentProcessor $processor): Response
    {
        $user = $this->user($request);
        $input = $request->validate(['source' => ['required', 'uuid']]);
        try {
            $this->service->reuse($workspace, $processor, (string) $input['source'], $user);
        } catch (AuthorizationException) {
            abort(403);
        } catch (DomainException $refused) {
            throw ValidationException::withMessages(['source' => $refused->getMessage()]);
        }

        return response()->noContent();
    }

    public function destroy(Request $request, Workspace $workspace, PaymentProcessor $processor): Response
    {
        try {
            $this->service->revoke($workspace, $processor, $this->user($request));
        } catch (AuthorizationException) {
            abort(403);
        }

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(WorkspacePaymentConnection $row, bool $manages): array
    {
        $source = $this->connections->credentialSource($row);
        $sourceWorkspace = $row->isReused() && $source !== null
            ? Workspace::query()->whereKey($source->workspace_id)->value('name')
            : null;

        return [
            'display_name' => $row->display_name,
            'environment' => $row->environment,
            'key_hint' => $source?->secret_hint,
            'location_id' => $source?->location_id,
            'reused_from' => $sourceWorkspace,
            'usable' => $source !== null,
            'webhook_url' => $manages && ! $row->isReused() ? $this->service->webhookUrl($row->processorEnum(), $row->endpoint_token) : null,
        ];
    }

    /** Whether the deployment's own credentials really are what this workspace's payments use today. */
    private function environmentCredentialsServe(Workspace $workspace, PaymentProcessor $processor): bool
    {
        if (! $this->resolver->environmentMayServe($workspace, $processor)) {
            return false;
        }

        return match ($processor) {
            PaymentProcessor::Stripe => $this->resolver->stripeEnvironment() !== null,
            PaymentProcessor::Square => SquareConfiguration::for($workspace)->isConfigured(),
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
