<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Square\SquareLinkRepository;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\AgentApi\Presenters\SquareLinkPresenter;
use App\Support\Square\SquareIdentifiers;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SVC's side of Square (#438): invoice links, the client's Square customer and
 * the billed-in-Square agreement marker. Workspace managers only, `billing:*`
 * scopes, one receipt per idempotency key; nothing here calls Square.
 */
final class AgentSquareLinkController extends Controller
{
    public function __construct(
        private readonly SquareLinkService $square,
        private readonly SquareLinkRepository $links,
        private readonly SquareLinkPresenter $presenter,
        private readonly AgentAccess $access,
        private readonly AgentMutationContextFactory $contexts,
        private readonly AgentMutationExecutor $mutations,
    ) {}

    public function link(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.square_link', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $context): array {
            $record = $this->invoice($workspace, $invoice, $context->user);
            $data = $this->validated($request, [
                ...$this->confirmedVersion(),
                'square_invoice_id' => SquareIdentifiers::invoiceIdRule(),
                'square_invoice_number' => ['required', 'string', 'max:191', 'regex:'.SquareIdentifiers::INVOICE_NUMBER_PATTERN],
                'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/D'],
                'match_method' => ['sometimes', 'string', Rule::enum(SquareMatchMethod::class)],
                'score' => ['sometimes', 'nullable', 'integer', 'between:0,100', Rule::prohibitedIf($request->input('match_method', 'manual') === 'manual' && $request->input('score') !== null)],
                'reasons' => ['sometimes', 'nullable', 'array', 'list', 'max:10', Rule::prohibitedIf($request->input('match_method', 'manual') === 'manual' && $request->input('reasons') !== null)],
                'reasons.*' => ['string', 'min:1', 'max:200'],
                'replace' => ['sometimes', static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('replace must be true or false.');
                    }
                }],
            ]);
            $reasons = isset($data['reasons']) ? array_values(array_map(strval(...), $data['reasons'])) : null;
            $this->square->link($workspace, $record, $context->user, $data['expected_version'], new SquareInvoiceReference(
                $data['square_invoice_id'],
                $data['square_invoice_number'],
                $data['currency'] ?? null,
                SquareMatchMethod::from($data['match_method'] ?? SquareMatchMethod::Manual->value),
                isset($data['score']) ? (int) $data['score'] : null,
                $reasons,
            ), ($data['replace'] ?? false) === true);

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->invoice($this->invoice($workspace, $invoice, $context->user))]);
    }

    public function unlink(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.square_unlink', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $context): array {
            $record = $this->invoice($workspace, $invoice, $context->user);
            $data = $this->validated($request, $this->confirmedVersion());
            $this->square->unlink($workspace, $record, $context->user, $data['expected_version']);

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->invoice($this->invoice($workspace, $invoice, $context->user))]);
    }

    public function cover(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.square_cover', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $context): array {
            $record = $this->invoice($workspace, $invoice, $context->user);
            $data = $this->validated($request, [
                ...$this->confirmedVersion(),
                'square_invoice_id' => SquareIdentifiers::invoiceIdRule(),
                'square_invoice_number' => ['required', 'string', 'max:191', 'regex:'.SquareIdentifiers::INVOICE_NUMBER_PATTERN],
                'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/D'],
            ]);
            $this->square->cover($workspace, $record, $context->user, $data['expected_version'], new SquareInvoiceReference(
                $data['square_invoice_id'],
                $data['square_invoice_number'],
                $data['currency'] ?? null,
                SquareMatchMethod::Manual,
            ));

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->invoice($this->invoice($workspace, $invoice, $context->user))]);
    }

    public function uncover(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'invoices.square_uncover', $context->idempotencyKey, ['invoice_id' => $invoice, 'body' => $request->all()], function () use ($request, $workspace, $invoice, $context): array {
            $record = $this->invoice($workspace, $invoice, $context->user);
            $data = $this->validated($request, [
                ...$this->confirmedVersion(),
                'square_invoice_id' => SquareIdentifiers::invoiceIdRule(),
            ]);
            $this->square->uncover($workspace, $record, $context->user, $data['expected_version'], $data['square_invoice_id']);

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->presenter->invoice($this->invoice($workspace, $invoice, $context->user))]);
    }

    public function customer(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        // A read answers a non-manager as the client reads do: not found, so
        // a member learns nothing about a client record they cannot reach.
        if (! $this->access->isWorkspaceManager($actor, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientCompany::class);
        }

        return response()->json(['data' => $this->customerState($this->company($workspace, $client, $actor))]);
    }

    public function setCustomer(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'companies.square_customer.set', $context->idempotencyKey, ['client_id' => $client, 'body' => $request->all()], function () use ($request, $workspace, $client, $context): array {
            $company = $this->company($workspace, $client, $context->user);
            $data = $this->validated($request, [
                ...$this->confirmedVersion(),
                'square_customer_id' => ['required', 'string', 'max:191', 'regex:'.SquareIdentifiers::CUSTOMER_ID_PATTERN],
            ]);
            $this->square->setCustomer($workspace, $company, $context->user, $data['expected_version'], $data['square_customer_id']);

            return [$company->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->customerState($this->company($workspace, $client, $context->user))]);
    }

    public function clearCustomer(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'companies.square_customer.clear', $context->idempotencyKey, ['client_id' => $client, 'body' => $request->all()], function () use ($request, $workspace, $client, $context): array {
            $company = $this->company($workspace, $client, $context->user);
            $data = $this->validated($request, $this->confirmedVersion());
            $this->square->clearCustomer($workspace, $company, $context->user, $data['expected_version']);

            return [$company->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $this->customerState($this->company($workspace, $client, $context->user))]);
    }

    public function markAgreement(Request $request, Workspace $workspace, string $agreement, AgentAgreementReadService $agreements): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'agreements.square_series.set', $context->idempotencyKey, ['agreement_id' => $agreement, 'body' => $request->all()], function () use ($request, $workspace, $agreement, $context): array {
            $record = $this->agreement($workspace, $agreement, $context->user);
            $data = $this->validated($request, [
                ...$this->confirmedVersion(),
                'series_key' => ['required', 'string', 'regex:'.SquareIdentifiers::SERIES_KEY_PATTERN],
                'location_id' => ['sometimes', 'string', 'regex:'.SquareIdentifiers::LOCATION_ID_PATTERN],
            ]);
            $this->square->markAgreement($workspace, $record, $context->user, $data['expected_version'], $data['series_key'], $data['location_id'] ?? null);

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $agreements->get($context->user, $workspace, $agreement)]);
    }

    public function clearAgreement(Request $request, Workspace $workspace, string $agreement, AgentAgreementReadService $agreements): JsonResponse
    {
        $context = $this->contexts->from($request);
        $this->mutations->run($context->user, $workspace, $context->oauthClientId, 'agreements.square_series.clear', $context->idempotencyKey, ['agreement_id' => $agreement, 'body' => $request->all()], function () use ($request, $workspace, $agreement, $context): array {
            $record = $this->agreement($workspace, $agreement, $context->user);
            $data = $this->validated($request, $this->confirmedVersion());
            $this->square->clearAgreement($workspace, $record, $context->user, $data['expected_version']);

            return [$record->public_id];
        }, fn (array $ids) => $this->requireManager($context->user, $workspace));

        return response()->json(['data' => $agreements->get($context->user, $workspace, $agreement)]);
    }

    /** @return array<string, mixed> */
    private function customerState(ClientCompany $company): array
    {
        return [
            'client_id' => $company->public_id,
            'version' => AgentApiVersion::for($company),
            'square_customer' => $this->presenter->customer($this->links->activeCustomer($company)),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function confirmedVersion(): array
    {
        return [
            'expected_version' => ['required', 'string', 'size:64'],
            'confirm' => ['required', new ExplicitConfirmation],
        ];
    }

    /**
     * Validate the body against exactly these fields: an unknown field is a
     * mistake to report, not a value to ignore.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $fields = array_values(array_filter(array_keys($rules), static fn (string $field): bool => ! str_contains($field, '.')));
        $unknown = array_diff(array_keys($request->all()), $fields);
        if ($unknown !== []) {
            throw ValidationException::withMessages([(string) reset($unknown) => 'This field is not accepted here.']);
        }

        return Validator::make($request->all(), $rules)->validate();
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }

    private function invoice(Workspace $workspace, string $id, User|AgentPrincipal $user): ClientInvoice
    {
        $this->requireManager($user, $workspace);

        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }

    private function company(Workspace $workspace, string $id, User|AgentPrincipal $user): ClientCompany
    {
        $this->requireManager($user, $workspace);

        return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }

    private function agreement(Workspace $workspace, string $id, User|AgentPrincipal $user): ClientAgreement
    {
        $this->requireManager($user, $workspace);

        return ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }
}
