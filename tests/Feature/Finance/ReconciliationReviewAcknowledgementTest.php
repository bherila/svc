<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ReconciliationReviewAcknowledgementService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\ReconciliationReviewAcknowledgement;
use App\Support\Finance\ReconciliationTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class ReconciliationReviewAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
    }

    #[DataProvider('reviewedPayments')]
    public function test_acknowledgement_keeps_all_money_and_activity_even_above_net(string $status, string $reason, bool $active): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture($status, $reason, $active);
        $money = $payment->fresh()->getAttributes();
        unset($money['lock_version'], $money['updated_at']);
        $attributes = $allocation->getAttributes();
        unset($attributes['review_reason'], $attributes['review_flagged_at'], $attributes['updated_at']);
        $revision = $payment->lock_version;
        $written = $this->acknowledge($owner, $workspace, $payment, $allocation, $this->input($payment, $allocation));
        $this->assertNull($written->review_reason);
        $this->assertNull($written->review_flagged_at);
        $stored = $written->getAttributes();
        unset($stored['review_reason'], $stored['review_flagged_at'], $stored['updated_at']);
        $this->assertSame($attributes, $stored);
        $fresh = $payment->fresh()->getAttributes();
        unset($fresh['lock_version'], $fresh['updated_at']);
        $this->assertSame($money, $fresh);
        $this->assertSame($revision + 1, $payment->fresh()->lock_version);
    }

    public static function reviewedPayments(): iterable
    {
        yield 'partial refund over net' => ['succeeded', 'processor_refund', true];
        yield 'disputed' => ['disputed', 'processor_dispute', true];
        yield 'fully refunded' => ['refunded', 'processor_refund', true];
        yield 'withdrawn allocation stays withdrawn' => ['disputed', 'processor_dispute', false];
    }

    #[DataProvider('conflictingReview')]
    public function test_every_stale_guard_refuses_without_changing_anything(string $field, string $value): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $input = [...$this->input($payment, $allocation), $field => $value];
        $this->assertConflict($owner, $workspace, $payment, $allocation, $input);
    }

    public static function conflictingReview(): iterable
    {
        yield 'revision' => ['expected_payment_version', str_repeat('0', 64)];
        yield 'reason' => ['expected_review_reason', 'processor_dispute'];
        yield 'second' => ['expected_review_flagged_at', '2030-01-20T11:59:59+00:00'];
        yield 'fraction' => ['expected_review_flagged_at', '2030-01-20T12:00:00.000001+00:00'];
    }

    public function test_a_later_review_at_the_same_second_is_not_acknowledged_by_an_old_request(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $input = $this->input($payment, $allocation);
        $payment->advanceAgentRevision();
        $this->assertConflict($owner, $workspace, $payment->fresh(), $allocation, $input);
    }

    public function test_repeating_a_cleared_review_is_a_conflict_even_with_a_refreshed_payment_version(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $input = $this->input($payment, $allocation);
        $this->acknowledge($owner, $workspace, $payment, $allocation, $input);
        $this->assertConflict($owner, $workspace, $payment->fresh(), $allocation->fresh(), $input);
        $input['expected_payment_version'] = AgentApiVersion::for($payment->fresh());
        $this->assertConflict($owner, $workspace, $payment->fresh(), $allocation->fresh(), $input);
    }

    public function test_an_equivalent_offset_instant_matches_the_current_review(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $input = [...$this->input($payment, $allocation), 'expected_review_flagged_at' => '2030-01-20T04:00:00-08:00'];
        $this->assertNull($this->acknowledge($owner, $workspace, $payment, $allocation, $input)->review_reason);
    }

    public function test_only_a_workspace_manager_can_acknowledge(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->expectException(AuthorizationException::class);
        $this->acknowledge($member, $workspace, $payment, $allocation, $this->input($payment, $allocation));
    }

    public function test_an_admin_can_acknowledge(): void
    {
        [, $workspace, $payment, $allocation] = $this->fixture();
        $admin = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $this->assertNull($this->acknowledge($admin, $workspace, $payment, $allocation, $this->input($payment, $allocation))->review_reason);
    }

    public function test_a_foreign_payment_or_allocation_is_not_found(): void
    {
        [$owner, $workspace, , $allocation] = $this->fixture();
        [, , $foreignPayment] = $this->fixture('succeeded', 'processor_refund', true, 'foreign');
        $this->expectException(ModelNotFoundException::class);
        $this->acknowledge($owner, $workspace, $foreignPayment, $allocation, $this->input($foreignPayment, $allocation));
    }

    public function test_a_transaction_from_another_payment_is_not_found(): void
    {
        [$owner, $workspace, $payment, $allocation] = $this->fixture();
        $target = ReconciliationTarget::of($payment->public_id, 'synthetic-finance', (string) Str::uuid());
        $this->expectException(ModelNotFoundException::class);
        app(ReconciliationReviewAcknowledgementService::class)->acknowledge(FinanceCaller::operator($owner), $workspace, $target, ReconciliationReviewAcknowledgement::fromInput($this->input($payment, $allocation)));
    }

    #[DataProvider('invalidInputs')]
    public function test_the_explicit_review_proof_is_required_and_no_other_fields_are_accepted(array $input): void
    {
        $this->expectException(ValidationException::class);
        ReconciliationReviewAcknowledgement::fromInput($input);
    }

    public static function invalidInputs(): iterable
    {
        $valid = ['expected_payment_version' => str_repeat('a', 64), 'expected_review_reason' => 'processor_refund', 'expected_review_flagged_at' => '2030-01-20T12:00:00+00:00'];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield 'missing '.$field => [$missing];
        }
        yield 'wrong revision' => [[...$valid, 'expected_payment_version' => 'not-a-version']];
        yield 'unknown reason' => [[...$valid, 'expected_review_reason' => 'unknown']];
        yield 'no timezone' => [[...$valid, 'expected_review_flagged_at' => '2030-01-20 12:00:00']];
        yield 'money edit' => [[...$valid, 'allocated_amount' => 1]];
        yield 'activity edit' => [[...$valid, 'is_active' => false]];
    }

    private function fixture(string $status = 'succeeded', string $reason = 'processor_refund', bool $active = true, string $suffix = 'owned'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic acknowledgement workspace', 'slug' => 'synthetic-ack-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-ack-'.$suffix]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-ACK-'.$suffix, 'currency' => 'USD']);
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_ack_'.$suffix, 'method' => 'card', 'amount' => 1000, 'refunded_amount' => 900, 'currency' => 'USD', 'status' => $status]);
        $allocation = PaymentReconciliation::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id, 'external_system_slug' => 'synthetic-finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 1000, 'currency' => 'USD', 'reconciled_on' => '2030-01-19', 'created_by_user_id' => $owner->id, 'is_active' => $active]);
        $allocation->forceFill(['review_reason' => $reason, 'review_flagged_at' => now()])->save();

        return [$owner, $workspace, $payment, $allocation->fresh()];
    }

    private function input(ClientInvoicePayment $payment, PaymentReconciliation $allocation): array
    {
        return ['expected_payment_version' => AgentApiVersion::for($payment), 'expected_review_reason' => $allocation->review_reason->value, 'expected_review_flagged_at' => $allocation->review_flagged_at->toIso8601String()];
    }

    private function acknowledge(User $user, Workspace $workspace, ClientInvoicePayment $payment, PaymentReconciliation $allocation, array $input): PaymentReconciliation
    {
        return app(ReconciliationReviewAcknowledgementService::class)->acknowledge(FinanceCaller::operator($user), $workspace, ReconciliationTarget::of($payment->public_id, $allocation->external_system_slug, $allocation->external_transaction_uuid), ReconciliationReviewAcknowledgement::fromInput($input));
    }

    private function assertConflict(User $user, Workspace $workspace, ClientInvoicePayment $payment, PaymentReconciliation $allocation, array $input): void
    {
        $beforePayment = $payment->fresh()->getAttributes();
        $beforeAllocation = $allocation->fresh()->getAttributes();
        try {
            $this->acknowledge($user, $workspace, $payment, $allocation, $input);
            $this->fail('A stale or cleared review must be refused.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('refresh', $exception->getMessage());
        }
        $this->assertSame($beforePayment, $payment->fresh()->getAttributes());
        $this->assertSame($beforeAllocation, $allocation->fresh()->getAttributes());
    }
}
