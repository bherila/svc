<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
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
            $body + ['company_id' => $company->public_id], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function billingSessionRequest(User $actor, string $method, string $url, array $body, array $headers): TestResponse
    {
        $cookies = $this->unencryptedCookies;
        $credentials = $this->withCredentials;
        try {
            return $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
                ->{$method}($url, $body, $headers);
        } finally {
            // A later fake OAuth request must not accidentally become a web
            // request because this helper left a session cookie attached.
            $this->unencryptedCookies = $cookies;
            $this->withCredentials = $credentials;
        }
    }
}
