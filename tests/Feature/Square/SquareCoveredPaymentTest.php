<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareLinkService;
use App\Services\Square\SquarePaymentReconciler;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

final class SquareCoveredPaymentTest extends TestCase
{
    use CallsMcp, FakesSquarePaymentSync, RefreshDatabase;

    /** @return array{Workspace,User,ClientCompany,ClientInvoice,string} */
    private function fixture(): array
    {
        [$workspace, $owner, $company, $invoice] = $this->paymentFixture();
        config(['agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $links = app(SquareLinkService::class);
        $links->link($workspace, $invoice->refresh(), $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv_primary_fixture', '000001-R-0002', 'USD', SquareMatchMethod::Manual), true);
        $links->cover($workspace, $invoice->refresh(), $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv_fixture_1', '000001-R-0001', 'USD', SquareMatchMethod::Manual));
        $this->actingAsMcp($owner, array_keys(AgentApiScopes::descriptions()));

        return [$workspace, $owner, $company, $invoice->refresh(), "/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/square-coverage/inv_fixture_1/payments"];
    }

    public static function transports(): array
    {
        return [['rest'], ['mcp']];
    }

    #[DataProvider('transports')]
    public function test_review_record_replay_and_later_refund_share_the_canonical_provider_identity(string $transport): void
    {
        [$workspace, $owner, , $invoice, $base] = $this->fixture();
        $session = $transport === 'mcp' ? $this->initialize() : null;
        $arguments = ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id, 'square_invoice_id' => 'inv_fixture_1'];
        $review = $transport === 'mcp'
            ? $this->callTool((string) $session, 'invoices.square_covered_payments', $arguments)['result']['structuredContent']['data']
            : $this->getJson($base)->assertOk()->json('data');
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertSame(12_345, $review['balance_amount']);
        $candidate = $review['payments'][0];
        $this->assertNull($candidate['refusal']);
        $this->assertSame($base.'/pay_fixture_1', $candidate['record_href']);
        $body = ['expected_version' => $review['invoice_version'], 'reviewed_version' => $candidate['reviewed_version'], 'confirm' => true];
        $record = fn (): array => $transport === 'mcp'
            ? $this->callTool((string) $session, 'invoices.square_covered_payment_record', [...$arguments, 'payment_id' => 'pay_fixture_1', 'idempotency_key' => 'synthetic-covered-record', ...$body])['result']['structuredContent']['data']
            : $this->withHeader('Idempotency-Key', 'synthetic-covered-record')->postJson($candidate['record_href'], $body)->assertOk()->json('data');
        $first = $record();
        $this->assertSame($invoice->public_id, $first['invoice_id']);
        $this->assertDatabaseHas('client_invoice_payments', ['public_id' => $first['payment_id'], 'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id, 'provider' => 'square', 'provider_payment_identifier' => 'pay_fixture_1', 'amount' => 12_345]);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertSame($first, $record());
        $this->assertDatabaseCount('client_invoice_payments', 1);
        app(SquareLinkService::class)->uncover($workspace, $invoice->refresh(), $owner, AgentApiVersion::for($invoice), 'inv_fixture_1');
        $this->assertSame($first, $record(), 'Completed receipts replay after historical coverage is removed.');
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        app(SquarePaymentReconciler::class)->payment($workspace, 'pay_fixture_1');
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.square_covered_payment_record', 'outcome' => 'replay']);
    }

    public function test_changed_provider_facts_or_invoice_version_require_a_fresh_review(): void
    {
        [, , , $invoice, $base] = $this->fixture();
        $review = $this->getJson($base)->assertOk()->json('data');
        $body = ['expected_version' => $review['invoice_version'], 'reviewed_version' => $review['payments'][0]['reviewed_version'], 'confirm' => true];
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 10, 'currency' => 'USD'];
        $this->withHeader('Idempotency-Key', 'synthetic-covered-refund-race')->postJson($base.'/pay_fixture_1', $body)->assertConflict();
        $this->squarePayments['pay_fixture_1']['refunded_money']['amount'] = 0;
        $this->squareDisputes['dispute_fixture'] = self::squareDispute('dispute_fixture', 'pay_fixture_1', 'EVIDENCE_REQUIRED');
        $this->withHeader('Idempotency-Key', 'synthetic-covered-dispute-race')->postJson($base.'/pay_fixture_1', $body)->assertConflict();
        $this->squareDisputes = [];
        $invoice->advanceAgentRevision();
        $this->withHeader('Idempotency-Key', 'synthetic-covered-invoice-race')->postJson($base.'/pay_fixture_1', $body)->assertConflict();
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public static function refusedFacts(): array
    {
        return [['refunded'], ['disputed'], ['overpaid'], ['pending'], ['draft'], ['void'], ['old'], ['future']];
    }

    #[DataProvider('refusedFacts')]
    public function test_unsafe_payments_are_explained_without_a_record_action(string $reason): void
    {
        [$workspace, , , $invoice, $base] = $this->fixture();
        if ($reason === 'refunded') {
            $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 100, 'currency' => 'USD'];
        } elseif ($reason === 'disputed') {
            $invoice->forceFill(['status' => 'disputed'])->save();
            $this->squareDisputes['dispute_fixture'] = self::squareDispute('dispute_fixture', 'pay_fixture_1', 'LOST');
        } elseif ($reason === 'overpaid') {
            $invoice->forceFill(['balance_amount' => 12_344])->save();
        } elseif (in_array($reason, ['old', 'future'], true)) {
            $this->squarePayments['pay_fixture_1']['card_details']['card_payment_timeline']['captured_at'] = $reason === 'old' ? '2027-01-01T12:00:00Z' : '2030-02-03T12:00:00Z';
        } elseif ($reason === 'pending') {
            $invoice->payments()->create(['workspace_id' => $workspace->id, 'amount' => 1, 'currency' => 'USD', 'method' => 'cheque', 'status' => 'pending']);
        } else {
            $invoice->forceFill(['status' => $reason])->save();
        }
        $before = ClientInvoicePayment::query()->count();
        $review = $this->getJson($base)->assertOk()->json('data');
        $candidate = $review['payments'][0];
        $this->assertNotEmpty($candidate['refusal']);
        $this->assertNull($candidate['record_href']);
        $this->withHeader('Idempotency-Key', 'synthetic-covered-refused')->postJson($base.'/pay_fixture_1', [
            'expected_version' => $review['invoice_version'], 'reviewed_version' => $candidate['reviewed_version'], 'confirm' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('client_invoice_payments', $before);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_scope_role_and_workspace_boundaries_are_enforced_before_square_is_read(): void
    {
        [$workspace, $owner, , $invoice, $base] = $this->fixture();
        $this->actingAsMcp($owner, [AgentApiScopes::IDENTITY_READ]);
        $this->getJson($base)->assertForbidden();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, array_keys(AgentApiScopes::descriptions()));
        $this->getJson($base)->assertForbidden();
        $other = Workspace::query()->create(['name' => 'Synthetic covered foreign workspace', 'slug' => 'synthetic-covered-foreign-workspace']);
        $other->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAsMcp($owner, array_keys(AgentApiScopes::descriptions()));
        $this->getJson(str_replace($workspace->public_id, $other->public_id, $base))->assertNotFound();
        Http::assertNothingSent();
        $this->actingAsMcp($owner, [AgentApiScopes::BILLING_READ]);
        $review = $this->getJson($base)->assertOk()->json('data');
        $this->assertNull($review['payments'][0]['record_href']);
        $this->withHeader('Idempotency-Key', 'synthetic-covered-no-scope')->postJson($base.'/pay_fixture_1', [
            'expected_version' => AgentApiVersion::for($invoice), 'reviewed_version' => $review['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    public function test_many_covered_invoices_share_one_balance_and_require_a_fresh_version_between_records(): void
    {
        [$workspace, $owner, , $invoice, $base] = $this->fixture();
        app(SquareLinkService::class)->cover($workspace, $invoice->refresh(), $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv_fixture_2', '000001-R-0003', 'USD', SquareMatchMethod::Manual));
        $this->squarePayments['pay_fixture_1']['amount_money']['amount'] = 6000;
        $firstProviderInvoice = $this->paymentInvoice;
        $firstProviderOrder = $this->paymentOrder;
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $first = $this->getJson($base)->assertOk()->json('data');
        $this->paymentInvoice['id'] = 'inv_fixture_2';
        $this->paymentInvoice['order_id'] = 'order_fixture_2';
        $this->paymentOrder = ['id' => 'order_fixture_2', 'location_id' => 'LOCFIXTURE01', 'tenders' => [['payment_id' => 'pay_fixture_2']]];
        $this->squarePayments['pay_fixture_2'] = [...$this->squarePayments['pay_fixture_1'], 'id' => 'pay_fixture_2', 'order_id' => 'order_fixture_2', 'amount_money' => ['amount' => 6345, 'currency' => 'USD']];
        $secondBase = str_replace('inv_fixture_1', 'inv_fixture_2', $base);
        $secondProviderInvoice = $this->paymentInvoice;
        $secondProviderOrder = $this->paymentOrder;
        $second = $this->getJson($secondBase)->assertOk()->json('data');
        $this->paymentInvoice = $firstProviderInvoice;
        $this->paymentOrder = $firstProviderOrder;
        $this->withHeader('Idempotency-Key', 'synthetic-many-covered-first')->postJson($base.'/pay_fixture_1', [
            'expected_version' => $first['invoice_version'], 'reviewed_version' => $first['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertOk();
        $this->assertSame(6345, $invoice->fresh()->balance_amount);
        $this->paymentInvoice = $secondProviderInvoice;
        $this->paymentOrder = $secondProviderOrder;
        $this->withHeader('Idempotency-Key', 'synthetic-many-covered-stale')->postJson($secondBase.'/pay_fixture_2', [
            'expected_version' => $second['invoice_version'], 'reviewed_version' => $second['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertConflict();
        $second = $this->getJson($secondBase)->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'synthetic-many-covered-current')->postJson($secondBase.'/pay_fixture_2', [
            'expected_version' => $second['invoice_version'], 'reviewed_version' => $second['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertOk();
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertDatabaseCount('client_invoice_payments', 2);
    }

    public function test_incomplete_or_malformed_dispute_observations_cannot_offer_recording(): void
    {
        [, , , , $base] = $this->fixture();
        $this->squareDisputePages = ['' => ['disputes' => null]];
        $this->getJson($base)->assertStatus(502);
        $this->squareDisputePages = ['' => ['disputes' => [], 'cursor' => 'synthetic-repeat'], 'synthetic-repeat' => ['disputes' => [], 'cursor' => 'synthetic-repeat']];
        $this->getJson($base)->assertStatus(502);
        $this->squareDisputePages = [];
        for ($page = 0; $page < 10; $page++) {
            $this->squareDisputePages[$page === 0 ? '' : 'synthetic-page-'.$page] = ['disputes' => [], 'cursor' => 'synthetic-page-'.($page + 1)];
        }
        $this->getJson($base)->assertStatus(502);
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    public function test_confirmation_rechecks_coverage_changed_during_the_provider_read(): void
    {
        [$workspace, $owner, , $invoice, $base] = $this->fixture();
        $review = $this->getJson($base)->assertOk()->json('data');
        $this->whileReadingSquareDisputes = fn () => app(SquareLinkService::class)->uncover($workspace, $invoice->refresh(), $owner, AgentApiVersion::for($invoice), 'inv_fixture_1');
        $this->withHeader('Idempotency-Key', 'synthetic-covered-coverage-race')->postJson($base.'/pay_fixture_1', [
            'expected_version' => $review['invoice_version'], 'reviewed_version' => $review['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertConflict();
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_changed_workspace_timezone_invalidates_the_reviewed_received_date(): void
    {
        [$workspace, , , , $base] = $this->fixture();
        $review = $this->getJson($base)->assertOk()->json('data');
        $workspace->update(['timezone' => 'America/Los_Angeles']);
        $this->withHeader('Idempotency-Key', 'synthetic-covered-timezone-race')->postJson($base.'/pay_fixture_1', [
            'expected_version' => $review['invoice_version'], 'reviewed_version' => $review['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertConflict();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    public function test_first_party_session_uses_the_same_operation_when_agent_writers_are_disabled(): void
    {
        [$workspace, $owner, , , $base] = $this->fixture();
        $this->actingAs($owner, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str_repeat('s', 40));
        config(['agent_api.writes_enabled' => false, 'agent_api.payment_writes_enabled' => false]);
        $review = $this->getJson($base)->assertOk()->json('data');
        $this->assertNotNull($review['payments'][0]['record_href']);
        $this->withHeader('Idempotency-Key', 'synthetic-covered-session')->postJson($review['payments'][0]['record_href'], [
            'expected_version' => $review['invoice_version'], 'reviewed_version' => $review['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertOk();
        $this->assertDatabaseHas('agent_mutation_receipts', ['workspace_id' => $workspace->id, 'oauth_client_id' => 'svc-web', 'operation' => 'invoices.square_covered_payment_record']);
        $this->assertDatabaseCount('client_invoice_payments', 1);
    }

    public function test_role_revocation_during_provider_read_refuses_confirmation_and_receipt_replay(): void
    {
        [$workspace, $owner, , , $base] = $this->fixture();
        $review = $this->getJson($base)->assertOk()->json('data');
        $this->whileReadingSquareDisputes = fn () => $workspace->memberships()->where('user_id', $owner->id)->delete();
        $this->withHeader('Idempotency-Key', 'synthetic-covered-role-race')->postJson($base.'/pay_fixture_1', [
            'expected_version' => $review['invoice_version'], 'reviewed_version' => $review['payments'][0]['reviewed_version'], 'confirm' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_cli_previews_by_default_and_requires_the_reviewed_versions_and_confirmation(): void
    {
        [$workspace, $owner, , $invoice, $base] = $this->fixture();
        $review = $this->getJson($base)->assertOk()->json('data');
        $arguments = ['workspace' => $workspace->public_id, 'invoice' => $invoice->public_id, 'square_invoice' => 'inv_fixture_1', '--actor' => $owner->public_id, '--format' => 'json'];
        $this->artisan('svc:square:covered-payments', $arguments)->assertSuccessful();
        $this->assertDatabaseCount('square_invoice_snapshots', 0);
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $apply = [...$arguments, '--apply' => true, '--payment' => 'pay_fixture_1', '--expected-version' => $review['invoice_version'], '--reviewed-version' => $review['payments'][0]['reviewed_version'], '--idempotency-key' => 'synthetic-covered-cli'];
        $this->artisan('svc:square:covered-payments', $apply)->assertFailed();
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->artisan('svc:square:covered-payments', [...$apply, '--confirm' => true])->assertSuccessful();
        $this->assertDatabaseCount('client_invoice_payments', 1);
    }
}
