<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\PaymentVersionChanged;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One audited correction for a payment's descriptive fields, and nothing else.
 *
 * Method, reference, notes and the received date describe money; they are not
 * money. These pin that each can be corrected, that everything that *is*
 * money is refused by name, that each field keeps the bound it has on the way
 * in, and that every correction is one recorded event with a reason.
 */
final class PaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(CarbonImmutable::parse('2026-08-29 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string|null, string|null}> */
    public static function correctableFields(): iterable
    {
        yield 'method' => ['method', 'ach', 'ach'];
        yield 'method is trimmed' => ['method', '  check ', 'check'];
        yield 'reference' => ['reference', 'SYN-REF-2', 'SYN-REF-2'];
        yield 'reference cleared' => ['reference', null, null];
        yield 'reference blank clears' => ['reference', '   ', null];
        yield 'notes' => ['notes', 'Synthetic corrected note', 'Synthetic corrected note'];
        yield 'received_on' => ['received_on', '2026-08-18', '2026-08-18'];
    }

    #[DataProvider('correctableFields')]
    public function test_each_descriptive_field_can_be_corrected_and_money_is_untouched(string $field, ?string $value, ?string $stored): void
    {
        [, $workspace, $invoice, $payment] = $this->recordedPayment();
        $before = $invoice->fresh();

        $corrected = app(InvoiceLifecycleService::class)
            ->correctPayment($payment, [$field => $value], 'Synthetic correction', null, $workspace);

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $read = $field === 'received_on' ? $fresh->received_on?->toDateString() : $fresh->getAttribute($field);
        $this->assertSame($stored, $read);
        $this->assertSame($corrected->lock_version, $fresh->lock_version);
        $this->assertSame(2, $fresh->lock_version);

        // Money, status and the invoice it settles are exactly as they were.
        $this->assertSame(1000, $fresh->amount);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(0, $fresh->refunded_amount);
        $after = $invoice->fresh();
        $this->assertSame($before?->paid_amount, $after?->paid_amount);
        $this->assertSame($before?->balance_amount, $after?->balance_amount);
        $this->assertSame($before?->status, $after?->status);

        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
    }

    public function test_the_activity_records_only_what_changed_and_the_reason(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        app(InvoiceLifecycleService::class)->correctPayment($payment, [
            // Named with the value it already has: not a change, not recorded.
            'method' => 'wire',
            'reference' => 'SYN-REF-2',
            'received_on' => '2026-08-20',
        ], '  Synthetic statement shows the 20th  ', null, $workspace);

        $activity = ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->sole();
        $this->assertSame([
            'reference' => ['old' => 'SYN-REF-1', 'new' => 'SYN-REF-2'],
            'received_on' => ['old' => '2026-08-25', 'new' => '2026-08-20'],
        ], $activity->payload['changes'] ?? null);
        $this->assertSame('Synthetic statement shows the 20th', $activity->payload['reason'] ?? null);
    }

    /** Each correction is its own event rather than a deduplicated repeat of the last. */
    public function test_two_identical_corrections_in_turn_are_two_events(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);

        $service->correctPayment($payment, ['method' => 'ach'], 'Synthetic first', null, $workspace);
        $service->correctPayment($payment, ['method' => 'wire'], 'Synthetic first', null, $workspace);
        $service->correctPayment($payment, ['method' => 'ach'], 'Synthetic first', null, $workspace);

        $this->assertSame(3, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
    }

    /**
     * A note can be as long as its column allows; its audit entry cannot.
     *
     * The activity payload is capped at 10,000 bytes, so a whole note on both
     * sides would refuse a legitimate correction for the size of its own
     * record. The activity keeps an excerpt; the payment keeps the note.
     */
    public function test_a_long_note_is_corrected_and_recorded_as_an_excerpt(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $long = str_repeat('é', 10000);

        app(InvoiceLifecycleService::class)->correctPayment($payment, ['notes' => $long], str_repeat('ü', 500), null, $workspace);

        $this->assertSame($long, $payment->fresh()?->notes);
        $recorded = ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->sole()->payload['changes']['notes']['new'] ?? null;
        $this->assertSame(str_repeat('é', 120).'…', $recorded);
    }

    public function test_a_correction_that_changes_nothing_writes_and_records_nothing(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        $result = app(InvoiceLifecycleService::class)->correctPayment($payment, [
            'method' => 'wire',
            'reference' => 'SYN-REF-1',
            'notes' => null,
            'received_on' => '2026-08-25',
        ], 'Synthetic restatement', null, $workspace);

        $this->assertSame(1, $result->lock_version);
        $this->assertSame(1, $payment->fresh()?->lock_version);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedChanges(): iterable
    {
        yield 'amount' => [['amount' => 1], 'setPaymentStatus'];
        yield 'currency' => [['currency' => 'EUR'], 'setPaymentStatus'];
        yield 'status' => [['status' => 'refunded'], 'setPaymentStatus'];
        yield 'refunded amount' => [['refunded_amount' => 1000], 'setRefundedAmount'];
        yield 'invoice' => [['client_invoice_id' => 1], 'another invoice'];
        yield 'provider id' => [['provider_payment_identifier' => 'synthetic'], 'payment processor'];
        yield 'reconciliation link' => [['external_finance_transaction_uuid' => 'synthetic'], 'reconciliation'];
        yield 'unknown' => [['colour' => 'blue'], 'Only method, reference, notes, received_on'];
        yield 'money beside an allowed field' => [['method' => 'ach', 'amount' => 1], 'setPaymentStatus'];
        yield 'nothing named' => [[], 'Name at least one'];
        yield 'method cleared' => [['method' => null], 'method is required'];
        yield 'method blank' => [['method' => '  '], 'method is required'];
        yield 'method too long' => [['method' => str_repeat('m', 41)], 'longer than 40'];
        yield 'method not text' => [['method' => 12], 'method is required'];
        yield 'reference too long' => [['reference' => str_repeat('r', 256)], 'longer than 255'];
        yield 'reference not text' => [['reference' => ['x']], 'reference must be text'];
        yield 'notes too long' => [['notes' => str_repeat('n', 10001)], 'longer than 10000'];
        yield 'date cleared' => [['received_on' => null], 'cannot be cleared'];
        yield 'date not a day' => [['received_on' => '08/18/2026'], 'real calendar date'];
        yield 'date impossible' => [['received_on' => '2026-02-31'], 'real calendar date'];
        yield 'date in the future' => [['received_on' => '2026-08-30'], 'cannot be dated after 2026-08-29'];
        yield 'date too old' => [['received_on' => '2024-08-28'], 'cannot be dated before 2024-08-29'];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('refusedChanges')]
    public function test_money_and_malformed_values_are_refused_and_nothing_changes(array $changes, string $message): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        try {
            app(InvoiceLifecycleService::class)->correctPayment($payment, $changes, 'Synthetic attempt', null, $workspace);
            $this->fail('The correction was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertUnchanged($payment);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedReasons(): iterable
    {
        yield 'empty' => ['', 'A reason is required'];
        yield 'blank' => ["  \n ", 'A reason is required'];
        yield 'too long' => [str_repeat('r', 501), 'longer than 500'];
    }

    #[DataProvider('refusedReasons')]
    public function test_a_reason_is_required(string $reason, string $message): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        try {
            app(InvoiceLifecycleService::class)->correctPayment($payment, ['method' => 'ach'], $reason, null, $workspace);
            $this->fail('A correction without a usable reason was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertUnchanged($payment);
    }

    /**
     * The version the caller read is checked against the locked row.
     *
     * Any write to the payment moves it - here a refund, through its own
     * operation - so a correction prepared against the earlier reading is
     * refused rather than applied over a row the caller has not seen.
     */
    public function test_a_stale_version_is_refused_and_a_current_one_is_accepted(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();
        $service = app(InvoiceLifecycleService::class);
        $read = AgentApiVersion::for($payment);

        $service->setRefundedAmount($payment, 100, $workspace);

        try {
            $service->correctPayment($payment, ['method' => 'ach'], 'Synthetic stale', $read, $workspace);
            $this->fail('A correction against a stale version was accepted.');
        } catch (PaymentVersionChanged) {
            $this->assertSame('wire', $payment->fresh()?->method);
        }

        $current = AgentApiVersion::for($payment->fresh() ?? $payment);
        $service->correctPayment($payment, ['method' => 'ach'], 'Synthetic current', $current, $workspace);
        $this->assertSame('ach', $payment->fresh()?->method);
    }

    public function test_a_payment_in_another_workspace_is_not_found(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment('Alpha');
        [, , , $otherPayment] = $this->recordedPayment('Beta');

        try {
            app(InvoiceLifecycleService::class)->correctPayment($otherPayment, ['method' => 'ach'], 'Synthetic reach', null, $workspace);
            $this->fail('A payment was corrected from outside its workspace.');
        } catch (ModelNotFoundException) {
            $this->assertUnchanged($otherPayment);
            $this->assertUnchanged($payment);
        }
    }

    /**
     * The date-only door now runs through the general operation and keeps the
     * history it has always written: its own action, its own payload, and no
     * reason, because its door never asked for one.
     */
    public function test_the_date_only_door_keeps_its_own_activity(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        app(InvoiceLifecycleService::class)->setPaymentReceivedOn($payment, '2026-08-18', $workspace);

        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.payment_date_corrected')->sole();
        $this->assertSame('2026-08-25', $activity->payload['previous_received_on'] ?? null);
        $this->assertSame('2026-08-18', $activity->payload['received_on'] ?? null);
        $this->assertArrayNotHasKey('reason', $activity->payload);
        $this->assertArrayNotHasKey('changes', $activity->payload);
        $this->assertSame(2, $payment->fresh()?->lock_version);
    }

    public function test_the_command_corrects_with_a_reason_and_reports_json(): void
    {
        [, $workspace, $invoice, $payment] = $this->recordedPayment();

        $exit = Artisan::call('svc:billing:correct-payment', [
            'payment' => $payment->public_id,
            '--workspace' => $workspace->public_id,
            '--method' => 'ach',
            '--reference' => '',
            '--received-on' => '2026-08-20',
            '--reason' => 'Synthetic bank statement',
            '--expected-version' => AgentApiVersion::for($payment),
            '--format' => 'json',
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame([
            'payment_public_id' => $payment->public_id,
            'invoice_public_id' => $invoice->public_id,
            'dry_run' => false,
            'changed' => true,
            'changes' => [
                'method' => ['old' => 'wire', 'new' => 'ach'],
                'reference' => ['old' => 'SYN-REF-1', 'new' => null],
                'received_on' => ['old' => '2026-08-25', 'new' => '2026-08-20'],
            ],
            'version' => AgentApiVersion::for($fresh),
        ], $result);
        $this->assertSame('ach', $fresh->method);
        $this->assertNull($fresh->reference);
        $this->assertSame('Synthetic bank statement', ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->sole()->payload['reason'] ?? null);
    }

    public function test_the_command_dry_run_reports_the_change_and_writes_nothing(): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        $exit = Artisan::call('svc:billing:correct-payment', [
            'payment' => $payment->public_id,
            '--workspace' => $workspace->public_id,
            '--notes' => 'Synthetic dry note',
            '--reason' => 'Synthetic dry run',
            '--dry-run' => true,
            '--format' => 'json',
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(['notes' => ['old' => null, 'new' => 'Synthetic dry note']], $result['changes']);
        $this->assertSame(AgentApiVersion::for($payment), $result['version']);
        $this->assertUnchanged($payment);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.payment_corrected')->count());
    }

    /** @return iterable<string, array{array<string, mixed>, string, int}> */
    public static function refusedCommands(): iterable
    {
        yield 'no reason' => [['--method' => 'ach'], 'A reason is required', 1];
        yield 'no field' => [['--reason' => 'Synthetic'], 'Name at least one', 2];
        yield 'bad date' => [['--received-on' => '2099-01-01', '--reason' => 'Synthetic'], 'cannot be dated after', 1];
        yield 'stale version' => [['--method' => 'ach', '--reason' => 'Synthetic', '--expected-version' => str_repeat('0', 64)], 'changed since it was read', 1];
        yield 'no workspace' => [['--workspace' => '', '--method' => 'ach', '--reason' => 'Synthetic'], '--workspace option is required', 2];
        yield 'bad format' => [['--method' => 'ach', '--reason' => 'Synthetic', '--format' => 'yaml'], 'must be text or json', 2];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('refusedCommands')]
    public function test_the_command_refuses_and_writes_nothing(array $options, string $message, int $exitCode): void
    {
        [, $workspace, , $payment] = $this->recordedPayment();

        $exit = Artisan::call('svc:billing:correct-payment', [
            'payment' => $payment->public_id,
            '--workspace' => $workspace->public_id,
            ...$options,
        ]);

        $this->assertSame($exitCode, $exit);
        $this->assertStringContainsString($message, Artisan::output());
        $this->assertUnchanged($payment);
    }

    public function test_the_command_cannot_reach_another_workspaces_payment(): void
    {
        [, $workspace] = $this->recordedPayment('Alpha');
        [, , , $otherPayment] = $this->recordedPayment('Beta');

        $exit = Artisan::call('svc:billing:correct-payment', [
            'payment' => $otherPayment->public_id,
            '--workspace' => $workspace->public_id,
            '--method' => 'ach',
            '--reason' => 'Synthetic reach',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Payment not found in the requested workspace', Artisan::output());
        $this->assertUnchanged($otherPayment);
    }

    private function assertUnchanged(ClientInvoicePayment $payment): void
    {
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('wire', $fresh->method);
        $this->assertSame('SYN-REF-1', $fresh->reference);
        $this->assertNull($fresh->notes);
        $this->assertSame('2026-08-25', $fresh->received_on?->toDateString());
        $this->assertSame(1000, $fresh->amount);
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(1, $fresh->lock_version);
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoice,3:ClientInvoicePayment} */
    private function recordedPayment(string $name = 'Correction Workspace'): array
    {
        $owner = User::factory()->create(['email' => 'payment-correction-'.str()->random(6).'@synthetic.test']);
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->random(5),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client-'.$workspace->id,
        ]);

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-CORRECT-'.str()->upper(str()->random(8)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic service',
            'quantity' => '2',
            'unit_amount' => 5000,
            'tax_amount' => 0,
            'sort_order' => 1,
        ]]), $workspace);

        $payment = $service->applyPayment($invoice, [
            'amount' => 1000,
            'currency' => 'USD',
            'method' => 'wire',
            'reference' => 'SYN-REF-1',
            'received_on' => '2026-08-25',
        ], $workspace);

        return [$owner, $workspace, $invoice, $payment];
    }
}
