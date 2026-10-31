<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceEmailDraft;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Session-authenticated calls to the same canonical operations the invoice UI uses. */
trait CallsInvoiceApi
{
    protected function invoiceApi(User $actor, Workspace $workspace, ClientInvoice $invoice, string $operation, array $body = [], ?string $key = null): TestResponse
    {
        $route = match ($operation) {
            'issue' => 'invoices.issue', 'void' => 'invoices.void', 'correct' => 'invoices.correct',
            'send' => 'invoices.send', 'hold' => 'invoices.hold_delivery', 'release' => 'invoices.release_delivery',
        };

        return $this->billingSessionRequest($actor, 'postJson', route('agent-api.v1.'.$route, [$workspace, $invoice->public_id], absolute: false),
            $body + ['expected_version' => AgentApiVersion::for($invoice)] + ($operation === 'hold' ? [] : ['confirm' => true]),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function recordPaymentApi(User $actor, Workspace $workspace, ClientInvoice $invoice, array $body, ?string $key = null): TestResponse
    {
        return $this->billingSessionRequest($actor, 'postJson', route('agent-api.v1.payments.store', $workspace, absolute: false),
            $body + ['invoice_id' => $invoice->public_id, 'expected_version' => AgentApiVersion::for($invoice), 'confirm' => true],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function correctPaymentApi(User $actor, Workspace $workspace, ClientInvoicePayment $payment, array $body, ?string $key = null): TestResponse
    {
        return $this->billingSessionRequest($actor, 'patchJson', route('agent-api.v1.payments.update', [$workspace, $payment->public_id], absolute: false),
            $body + ['expected_version' => AgentApiVersion::for($payment), 'confirm' => true],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function createInvoiceApi(User $actor, Workspace $workspace, ClientCompany $company, array $body, ?string $key = null): TestResponse
    {
        return $this->billingSessionRequest($actor, 'postJson', route('agent-api.v1.invoices.store', $workspace, absolute: false),
            $body + ['company_id' => $company->public_id, 'is_visible_to_client' => false], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /**
     * RefreshDatabase holds the outer transaction open. Register through the
     * API, explicitly drive its registered callback, then replay to read the
     * recorded outcome. InvoiceSendOutcomeTest separately proves real commits
     * run this callback in the request and a rollback sends nothing.
     */
    protected function sendAndResolveInvoiceApi(User $actor, Workspace $workspace, ClientInvoice $invoice, array $body = [], ?string $key = null): TestResponse
    {
        $key ??= $body['idempotency_key'] ?? (string) Str::uuid();
        unset($body['idempotency_key']);
        $body += ['recipients' => array_values(array_filter([$invoice->clientCompany?->billing_email]))];
        $registered = $this->invoiceApi($actor, $workspace, $invoice, 'send', $body, $key);
        $id = $registered->json('data.delivery.id');
        if (! $registered->isSuccessful() || ! is_string($id)) {
            return $registered;
        }
        $delivery = ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)->where('public_id', $id)->firstOrFail();
        app(InvoiceEmailService::class)->deliverRegistered($invoice, $delivery->id,
            InvoiceEmailDraft::of($delivery->recipients, $delivery->bcc ?? [], $delivery->subject, $delivery->body));

        return $this->invoiceApi($actor, $workspace, $invoice, 'send', $body, $key);
    }

    private function billingSessionRequest(User $actor, string $method, string $url, array $body, array $headers): TestResponse
    {
        $cookies = $this->unencryptedCookies;
        $credentials = $this->withCredentials;
        $guard = $this->app['auth']->getDefaultDriver();
        try {
            return $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
                ->{$method}($url, $body, $headers);
        } finally {
            // A later fake OAuth request must not accidentally become a web
            // request because this helper left a session cookie attached.
            $this->unencryptedCookies = $cookies;
            $this->withCredentials = $credentials;
            $this->app['auth']->shouldUse($guard);
        }
    }
}
