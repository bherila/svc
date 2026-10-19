<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\IssuesAgentOAuthTokens;
use Tests\TestCase;

/**
 * A reconciliation change is a change to its payment (S2, G5): the payment's
 * `version` and `updated_at` move with it, so a synchronizer keyed on either
 * sees a change to an allocation alone. And an upsert over OAuth states
 * whether the allocation counts (G7), so a late retry never reactivates one
 * withdrawn since.
 */
final class AgentFinanceRevisionTest extends TestCase
{
    use IssuesAgentOAuthTokens;
    use RefreshDatabase;

    public function test_a_change_to_an_allocation_alone_advances_the_payment_version_even_within_one_second(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        [$owner, $workspace, $payment] = $this->payment('Revisions');
        ClientInvoicePayment::query()->whereKey($payment->id)->toBase()->update(['updated_at' => '2026-09-01 00:00:00']);
        $this->actingAsMcp($owner, [AgentApiScopes::FINANCE_READ, AgentApiScopes::FINANCE_RECONCILE]);
        $original = $this->paymentVersion($workspace);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $original);
        $path = $this->allocationPath($workspace, $payment, (string) Str::uuid());
        $body = ['allocated_amount' => 2500, 'currency' => 'USD', 'is_active' => true];

        // Only the allocation row changes, yet the payment's version and change
        // time move with it, so updated_since finds it and version says so.
        $this->putJson($path, $body)->assertCreated();
        $allocated = $this->paymentVersion($workspace);
        $this->assertNotSame($original, $allocated);
        $this->assertSame('2026-10-01 12:00:00', $payment->fresh()?->updated_at?->format('Y-m-d H:i:s'));
        $this->assertSame([$payment->public_id], array_column($this->getJson($this->listPath($workspace, ['updated_since' => '2026-10-01T12:00:00Z']))->json('data'), 'id'));

        // Two more revisions in the same second still differ.
        $this->putJson($path, [...$body, 'allocated_amount' => 2000])->assertOk();
        $changed = $this->paymentVersion($workspace);
        $this->assertNotSame($allocated, $changed);
        $this->deleteJson($path)->assertOk();
        $withdrawn = $this->paymentVersion($workspace);
        $this->assertNotSame($changed, $withdrawn);

        // Repeating a request that changes nothing leaves the version alone.
        $this->deleteJson($path)->assertOk();
        $afterRepeatedWithdrawal = $this->paymentVersion($workspace);
        $this->putJson($path, [...$body, 'allocated_amount' => 2000, 'is_active' => false])->assertOk();
        $this->assertSame([$withdrawn, $withdrawn], [$afterRepeatedWithdrawal, $this->paymentVersion($workspace)]);
    }

    public function test_an_upsert_over_oauth_states_whether_the_allocation_counts_through_the_real_flow(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Explicit activity');
        $tokens = $this->issueAgentTokens($owner, 'rest', [AgentApiScopes::FINANCE_RECONCILE]);
        $path = $this->allocationPath($workspace, $payment, (string) Str::uuid());
        $put = function (array $body) use ($tokens, $path) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($tokens['access_token'])->putJson($path, $body);
        };

        $put(['allocated_amount' => 100, 'currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $put(['allocated_amount' => 100, 'currency' => 'USD', 'is_active' => null])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertDatabaseCount('payment_reconciliations', 0);

        // A late retry of an earlier "active" request still says so, and
        // reactivates only because it says so.
        $put(['allocated_amount' => 100, 'currency' => 'USD', 'is_active' => true])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->deleteJson($path)->assertOk();
        $put(['allocated_amount' => 100, 'currency' => 'USD', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $put(['allocated_amount' => 100, 'currency' => 'USD', 'is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_a_finance_token_put_that_omits_is_active_never_reactivates_a_withdrawn_allocation(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Delayed PUT');
        $token = $owner->createToken('finance', ['finance.read', 'finance.reconcile'], CarbonImmutable::now()->addHour())->plainTextToken;
        $path = $this->allocationPath($workspace, $payment, (string) Str::uuid());
        $body = ['allocated_amount' => 2500, 'currency' => 'USD'];

        // Omitted on a new allocation: active, as before.
        $this->withToken($token)->putJson($path, $body)->assertCreated()->assertJsonPath('data.is_active', true);
        $this->withToken($token)->deleteJson($path)->assertOk()->assertJsonPath('data.is_active', false);

        // A delayed retry of the first PUT arrives after the withdrawal: the
        // allocation keeps its current state rather than coming back.
        $this->withToken($token)->putJson($path, $body)->assertOk()->assertJsonPath('data.is_active', false);
        $this->withToken($token)->putJson($path, [...$body, 'is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->withToken($token)->getJson($this->listPath($workspace))
            ->assertOk()
            ->assertJsonPath('data.0.version', fn (mixed $version): bool => is_string($version) && strlen($version) === 64);
    }

    /** The listed version of the workspace's one payment, read afresh. */
    private function paymentVersion(Workspace $workspace): string
    {
        return (string) $this->getJson($this->listPath($workspace, ['status' => 'any']))->assertOk()->json('data.0.version');
    }

    /** @param  array<string, string>  $query */
    private function listPath(Workspace $workspace, array $query = []): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/invoice-payments".($query === [] ? '' : '?'.http_build_query($query));
    }

    private function allocationPath(Workspace $workspace, ClientInvoicePayment $payment, string $transaction): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/invoice-payments/{$payment->public_id}/reconciliations/finance/{$transaction}";
    }

    /** @return array{0: User, 1: Workspace, 2: ClientInvoicePayment} */
    private function payment(string $name): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' Client',
            'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(5)),
        ]);
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, ['invoice_number' => 'INV-'.Str::upper(Str::random(10)), 'currency' => 'USD'], [[
            'type' => 'service', 'description' => 'Synthetic finance service', 'quantity' => '1', 'unit_amount' => 6000, 'tax_amount' => 0,
        ]]);
        $invoices->issue($invoice, $workspace);

        return [$owner, $workspace, $invoices->applyPayment($invoice, [
            'amount' => 6000, 'currency' => 'USD', 'method' => 'wire', 'status' => 'succeeded', 'idempotency_key' => 'finance-'.Str::random(16),
        ], $workspace)];
    }
}
