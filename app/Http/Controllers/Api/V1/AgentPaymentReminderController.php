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
use App\Services\Billing\Reminders\PaymentReminderService;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\AgentApi\FirstPartySession;
use App\Support\AgentApi\Presenters\PaymentReminderPresenter;
use App\Support\Billing\Reminders\PaymentReminderTrigger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Payment reminders over REST and MCP, and for the website through the same
 * routes (#438). Workspace managers only, `billing:*` scopes, one receipt per
 * idempotency key.
 *
 * Sending registers the reminder inside the mutation's transaction, beside
 * its receipt, and sends it only after that commits: an email already gone is
 * not something a rollback can take back. A replay of the same key finds the
 * reminder already resolved and sends nothing more.
 */
final class AgentPaymentReminderController extends Controller
{
    public function __construct(
        private readonly PaymentReminderService $reminders,
        private readonly PaymentReminderPresenter $presenter,
        private readonly AgentAccess $access,
        private readonly AgentMutationContextFactory $contexts,
        private readonly AgentMutationExecutor $mutations,
    ) {}

    public function index(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json(['data' => $this->presenter->invoice($workspace, $this->invoice($workspace, $invoice, $actor))]);
    }

    public function send(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $trigger = FirstPartySession::current() ? PaymentReminderTrigger::Website : PaymentReminderTrigger::Agent;
        [$reminderId] = $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.send_reminder', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $context, $trigger): array {
            $this->requireManager($context->user, $workspace);
            $record = $this->invoice($workspace, $invoice, $context->user);
            $data = $this->validated($request, [
                'expected_version' => ['required', 'string', 'size:64'],
                'confirm' => ['required', new ExplicitConfirmation],
            ]);

            return [$this->reminders->register($workspace, $record, $context->user, $trigger, $context->oauthClientId, $data['expected_version'])->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        $sent = $this->reminders->sendRegistered($workspace, $this->invoice($workspace, $invoice, $context->user), $reminderId);

        return response()->json(['data' => $this->presenter->reminder($sent)]);
    }

    public function company(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json(['data' => $this->presenter->company($this->clientCompany($workspace, $client, $actor))]);
    }

    public function setCompany(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'companies.payment_reminders.set', $context->idempotencyKey, ['client_id' => $client, 'body' => $request->all()], function () use ($request, $workspace, $client, $context): array {
            $this->requireManager($context->user, $workspace);
            $company = $this->clientCompany($workspace, $client, $context->user);
            $data = $this->validated($request, [
                'expected_version' => ['required', 'string', 'size:64'],
                'enabled' => ['required', static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('enabled must be true or false.');
                    }
                }],
            ]);
            $this->reminders->setCadence($workspace, $company, $data['enabled'] === true, $context->user, $data['expected_version']);

            return [$company->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->company($this->clientCompany($workspace, $client, $context->user))]);
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
    private function invoice(Workspace $workspace, string $id, User|AgentPrincipal $user): ClientInvoice
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
