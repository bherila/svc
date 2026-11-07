<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\Autopay\AutopaySkipService;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\AgentApi\Presenters\AutopayPresenter;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Autopay for the provider side, over REST and MCP and for the website through
 * the same routes (#636). Workspace owners and admins only, `billing:*` scopes,
 * one receipt per idempotency key.
 *
 * Turning autopay on records the provider's attestation of how the client
 * agreed outside SVC and emails the client's billing contacts after the change
 * commits. Turning it off, by either side, is the same operation. The client's
 * own consent is given in the portal, not here.
 */
final class AgentAutopayController extends Controller
{
    public function __construct(
        private readonly AutopayConsentService $consents,
        private readonly AutopaySkipService $skips,
        private readonly AutopayPresenter $presenter,
        private readonly AgentAccess $access,
        private readonly AgentMutationContextFactory $contexts,
        private readonly AgentMutationExecutor $mutations,
    ) {}

    public function company(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $company = $this->clientCompany($workspace, $client, $actor);

        return response()->json(['data' => $this->presenter->company($workspace, $company)]);
    }

    public function setCompany(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'companies.autopay.set', $context->idempotencyKey, ['client_id' => $client, 'body' => $request->all()], function () use ($request, $workspace, $client, $context): array {
            $this->requireManager($context->user, $workspace);
            $company = $this->clientCompany($workspace, $client, $context->user);
            $data = $this->validated($request, [
                'expected_version' => ['required', 'string', 'size:64'],
                'enabled' => ['required', static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('enabled must be true or false.');
                    }
                }],
                'limit_amount' => ['sometimes', 'integer', 'min:1', 'max:'.AutopayConsentService::MAX_LIMIT],
                'attestation' => ['sometimes', 'string', 'max:1000'],
                'confirm' => ['required', new ExplicitConfirmation],
            ]);

            if ($data['enabled'] === true) {
                // Authorizing charges is a decision for the person, never inferred.
                foreach (['limit_amount', 'attestation'] as $required) {
                    if (! array_key_exists($required, $data)) {
                        throw ValidationException::withMessages([$required => 'This field is required to turn autopay on or change its limit.']);
                    }
                }
                $this->consents->turnOn($workspace, $company, new AutopayConsentRequest(
                    AutopayActorRole::Provider,
                    $context->user,
                    (int) $data['limit_amount'],
                    attestation: (string) $data['attestation'],
                ), $data['expected_version']);
            } else {
                foreach (['limit_amount', 'attestation'] as $forbidden) {
                    if (array_key_exists($forbidden, $data)) {
                        throw ValidationException::withMessages([$forbidden => 'This field applies only when turning autopay on.']);
                    }
                }
                $this->consents->turnOff($workspace, $company, AutopayActorRole::Provider, $context->user, null, $data['expected_version']);
            }

            return [$company->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->company($workspace, $this->clientCompany($workspace, $client, $context->user))]);
    }

    public function invoice(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json(['data' => $this->presenter->invoice($workspace, $this->clientInvoice($workspace, $invoice, $actor))]);
    }

    public function skip(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.autopay.skip', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $context): array {
            $this->requireManager($context->user, $workspace);
            $record = $this->clientInvoice($workspace, $invoice, $context->user);
            $data = $this->validated($request, [
                'expected_version' => ['required', 'string', 'size:64'],
                'confirm' => ['required', new ExplicitConfirmation],
            ]);
            $this->skips->skip($workspace, $record, $context->user, $data['expected_version']);

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->invoice($workspace, $this->clientInvoice($workspace, $invoice, $context->user))]);
    }

    /**
     * Exactly these fields: an unknown one is a mistake to report.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([(string) reset($unknown) => 'This field is not accepted here.']);
        }

        return Validator::make($request->all(), $rules)->validate();
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }

    /** A non-manager is answered as the other manager-only reads answer: not found. */
    private function clientInvoice(Workspace $workspace, string $id, User|AgentPrincipal $user): ClientInvoice
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientInvoice::class);
        }

        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }

    private function clientCompany(Workspace $workspace, string $id, User|AgentPrincipal $user): ClientCompany
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientCompany::class);
        }

        return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }
}
