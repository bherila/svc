<?php

namespace Tests\Feature\Finance;

use App\Exceptions\WorkspaceOwnershipImmutable;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Services\Finance\ProcessorMovementRecorder;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use App\Support\Finance\ProcessorMovementRecordOutcome;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ProcessorMovementRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_the_default_and_the_first_apply_is_idempotent(): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        $this->assertSame(ProcessorMovementRecordOutcome::Created, $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account'));
        $this->assertDatabaseCount('processor_movements', 0);
        $this->assertSame(ProcessorMovementRecordOutcome::Created, $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true));
        $stored = ProcessorMovement::query()->sole()->getAttributes();
        $this->travel(2)->seconds();
        $this->assertSame(ProcessorMovementRecordOutcome::Unchanged, $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true));
        $this->assertSame($stored, ProcessorMovement::query()->sole()->getAttributes());
        $this->assertDatabaseCount('processor_movements', 1);
    }

    public function test_money_and_existing_payment_allocations_do_not_change(): void
    {
        [$workspace, $payment] = $this->fixture();
        PaymentReconciliation::query()->create(['workspace_id' => $workspace->id, 'client_invoice_payment_id' => $payment->id,
            'external_system_slug' => 'synthetic-finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 1000, 'currency' => 'USD', 'is_active' => true]);
        $before = [$this->table('client_invoice_payments'), $this->table('client_invoices'), $this->table('payment_reconciliations')];
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $this->assertSame($before, [$this->table('client_invoice_payments'), $this->table('client_invoices'), $this->table('payment_reconciliations')]);
        $stored = ProcessorMovement::query()->sole();
        $this->assertSame([-100, -3, -97, 'USD', 1, false], [$stored->principal_minor, $stored->fee_minor, $stored->net_minor, $stored->currency, $stored->lock_version, $stored->needs_review]);
        $this->assertSame($payment->id, $stored->client_invoice_payment_id);
        $this->assertNotEmpty($stored->public_id);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function currencyMismatchCases(): iterable
    {
        foreach (['stripe', 'square'] as $provider) {
            foreach ([false, true] as $apply) {
                yield $provider.($apply ? ' apply' : ' preview') => [$provider, $apply];
            }
        }
    }

    #[DataProvider('currencyMismatchCases')]
    public function test_a_movement_cannot_bind_to_a_payment_in_another_currency(string $provider, bool $apply): void
    {
        [$workspace, $payment] = $this->fixture();
        $payment->update(['provider' => $provider]);
        $facts = $this->facts($payment, ['provider' => $provider, 'dateBasis' => $provider === 'stripe' ? 'stripe_balance_created' : 'square_effective_at']);
        // A previously loaded payment is deliberately stale at the recorder boundary.
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->where('id', $payment->id)->update(['currency' => 'EUR']);
        $before = [$this->table('client_invoice_payments'), $this->table('client_invoices')];
        $this->assertSame(ProcessorMovementRecordOutcome::Conflict,
            app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $facts, 'synthetic_account', $apply));
        $this->assertDatabaseCount('processor_movements', 0);
        $this->assertSame($before, [$this->table('client_invoice_payments'), $this->table('client_invoices')]);
    }

    #[DataProvider('currencyMismatchCases')]
    public function test_a_later_payment_currency_change_holds_the_original_movement_without_rewriting_it(string $provider, bool $apply): void
    {
        [$workspace, $payment] = $this->fixture();
        $payment->update(['provider' => $provider]);
        $facts = $this->facts($payment, ['provider' => $provider, 'dateBasis' => $provider === 'stripe' ? 'stripe_balance_created' : 'square_effective_at']);
        $recorder = app(ProcessorMovementRecorder::class);
        $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true);
        $before = ProcessorMovement::query()->sole()->getAttributes();
        $payment->update(['currency' => 'EUR']);
        $paymentBefore = $this->table('client_invoice_payments');
        $this->assertSame(ProcessorMovementRecordOutcome::Conflict,
            $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', $apply));
        $stored = ProcessorMovement::query()->sole();
        if (! $apply) {
            $this->assertSame($before, $stored->getAttributes());
        } else {
            $this->assertTrue($stored->needs_review);
            $this->assertSame('immutable_facts_changed', $stored->review_reason);
            $this->assertSame(2, $stored->lock_version);
            $this->assertSame(array_diff_key($before, array_flip(['needs_review', 'review_reason', 'lock_version', 'updated_at'])),
                array_diff_key($stored->getAttributes(), array_flip(['needs_review', 'review_reason', 'lock_version', 'updated_at'])));
            $payment->update(['currency' => 'USD']);
            $paymentBefore = $this->table('client_invoice_payments');
            $this->assertSame(ProcessorMovementRecordOutcome::Conflict,
                $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true));
            $this->assertSame($stored->getAttributes(), $stored->fresh()?->getAttributes());
        }
        $this->assertSame($paymentBefore, $this->table('client_invoice_payments'));
    }

    public function test_the_local_day_and_its_timezone_are_captured_once(): void
    {
        [$workspace, $payment] = $this->fixture();
        $workspace->update(['timezone' => 'America/Los_Angeles']);
        $facts = $this->facts($payment, ['occurredAt' => CarbonImmutable::parse('2030-01-01T00:30:00.123456Z')]);
        $recorder = app(ProcessorMovementRecorder::class);
        $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true);
        $stored = ProcessorMovement::query()->sole();
        $this->assertSame('2029-12-31', $stored->occurred_on->toDateString());
        $this->assertSame('2029-12-31', $stored->getRawOriginal('occurred_on'));
        $this->assertSame('America/Los_Angeles', $stored->occurred_timezone);
        $this->assertSame('2030-01-01 00:30:00.123456', $stored->getRawOriginal('provider_occurred_at'));
        $workspace->update(['timezone' => 'Pacific/Auckland']);
        $this->assertSame(ProcessorMovementRecordOutcome::Unchanged, $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true));
        $this->assertSame('2029-12-31', $stored->fresh()?->occurred_on->toDateString());
        $this->assertSame('America/Los_Angeles', $stored->fresh()?->occurred_timezone);
    }

    public function test_unknown_optional_facts_enrich_once_and_never_clear_known_facts(): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        $unknown = $this->facts($payment, ['sourceIdentifier' => null]);
        $recorder->record($workspace, $payment->id, $unknown, 'synthetic_account', true);
        $known = $this->facts($payment, ['availableOn' => CarbonImmutable::parse('2030-01-05T00:00:00Z'), 'payoutIdentifier' => 'synthetic_payout']);
        $this->assertSame(ProcessorMovementRecordOutcome::Enriched, $recorder->record($workspace, $payment->id, $known, 'synthetic_account'));
        $this->assertNull(ProcessorMovement::query()->sole()->source_identifier);
        $this->assertSame(ProcessorMovementRecordOutcome::Enriched, $recorder->record($workspace, $payment->id, $known, 'synthetic_account', true));
        $stored = ProcessorMovement::query()->sole();
        $this->assertSame(['synthetic_refund', 'synthetic_payout', 2], [$stored->source_identifier, $stored->provider_payout_identifier, $stored->lock_version]);
        $this->assertSame('2030-01-05', $stored->available_on?->toDateString());
        $this->assertSame(ProcessorMovementRecordOutcome::Unchanged, $recorder->record($workspace, $payment->id, $unknown, 'synthetic_account', true));
        $this->assertSame($stored->getAttributes(), $stored->fresh()?->getAttributes());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function conflictingFacts(): iterable
    {
        yield 'principal' => [['principalMinor' => -101, 'netMinor' => -98]];
        yield 'fee' => [['feeMinor' => -2, 'netMinor' => -98]];
        yield 'currency' => [['currency' => 'EUR']];
        yield 'kind' => [['kind' => ProcessorMovementKind::DisputeDebit]];
        yield 'timestamp' => [['occurredAt' => CarbonImmutable::parse('2030-01-03T12:00:00Z')]];
        yield 'source reference' => [['sourceIdentifier' => 'synthetic_other_refund']];
        yield 'known payout' => [['payoutIdentifier' => 'synthetic_other_payout']];
        yield 'known availability' => [['availableOn' => CarbonImmutable::parse('2030-01-06T00:00:00Z')]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('conflictingFacts')]
    public function test_a_conflict_holds_without_rewriting_immutable_history(array $overrides): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        $base = ['payoutIdentifier' => 'synthetic_payout', 'availableOn' => CarbonImmutable::parse('2030-01-05T00:00:00Z')];
        $facts = $this->facts($payment, $base);
        $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true);
        $before = ProcessorMovement::query()->sole()->getAttributes();
        $conflict = $this->facts($payment, array_replace($base, $overrides));
        $this->assertSame(ProcessorMovementRecordOutcome::Conflict, $recorder->record($workspace, $payment->id, $conflict, 'synthetic_account'));
        $this->assertSame($before, ProcessorMovement::query()->sole()->getAttributes());
        $this->assertSame(ProcessorMovementRecordOutcome::Conflict, $recorder->record($workspace, $payment->id, $conflict, 'synthetic_account', true));
        $stored = ProcessorMovement::query()->sole();
        $this->assertTrue($stored->needs_review);
        $this->assertSame('immutable_facts_changed', $stored->review_reason);
        $this->assertSame(array_diff_key($before, array_flip(['needs_review', 'review_reason', 'lock_version', 'updated_at'])),
            array_diff_key($stored->getAttributes(), array_flip(['needs_review', 'review_reason', 'lock_version', 'updated_at'])));
        $this->assertSame(2, $stored->lock_version);
        $this->assertSame(ProcessorMovementRecordOutcome::Conflict, $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true));
        $this->assertSame($stored->getAttributes(), $stored->fresh()?->getAttributes());
        $this->assertTrue(ProcessorMovementRecordOutcome::Conflict->needsReview());
        $this->assertSame('immutable_facts_changed', ProcessorMovementRecordOutcome::Conflict->reason());
    }

    public function test_a_provider_entry_cannot_be_rebound_to_another_owned_payment(): void
    {
        [$workspace, $payment] = $this->fixture();
        [, $other] = $this->fixture($workspace, 'synthetic_other_payment');
        $recorder = app(ProcessorMovementRecorder::class);
        $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $this->assertSame(ProcessorMovementRecordOutcome::Conflict, $recorder->record($workspace, $other->id, $this->facts($other), 'synthetic_account', true));
        $this->assertSame($payment->id, ProcessorMovement::query()->sole()->client_invoice_payment_id);
        $this->assertTrue(ProcessorMovement::query()->sole()->needs_review);
    }

    public function test_foreign_unknown_and_mismatched_payments_have_the_same_fixed_refusal(): void
    {
        [$workspace, $payment] = $this->fixture();
        [$foreignWorkspace, $foreign] = $this->fixture(null, 'synthetic_foreign_payment');
        $before = [$this->table('client_invoice_payments'), $this->table('client_invoices')];
        foreach ([$foreign->id, 999999, $payment->id] as $paymentId) {
            try {
                app(ProcessorMovementRecorder::class)->record($workspace, $paymentId, $this->facts($foreign), 'synthetic_account', true);
                $this->fail('An unproved payment was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The processor movement payment is unavailable.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('processor_movements', 0);
        $this->assertSame($before, [$this->table('client_invoice_payments'), $this->table('client_invoices')]);
        $this->assertNotSame($workspace->id, $foreignWorkspace->id);
    }

    public function test_payment_proof_is_refreshed_instead_of_trusting_an_earlier_model(): void
    {
        [$workspace, $payment] = $this->fixture();
        $facts = $this->facts($payment);
        DB::table('client_invoice_payments')->where('workspace_id', $workspace->id)->where('id', $payment->id)->update(['provider_payment_identifier' => 'synthetic_new_identity']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The processor movement payment is unavailable.');
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $facts, 'synthetic_account', true);
    }

    public function test_another_account_namespace_can_reuse_a_provider_entry_id(): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        foreach (['synthetic_account_a', 'synthetic_account_b'] as $account) {
            $this->assertSame(ProcessorMovementRecordOutcome::Created, $recorder->record($workspace, $payment->id, $this->facts($payment), $account, true));
        }
        $this->assertDatabaseCount('processor_movements', 2);
    }

    public function test_the_same_entry_identity_is_independent_between_workspaces(): void
    {
        foreach (['synthetic_payment_a', 'synthetic_payment_b'] as $identifier) {
            [$workspace, $payment] = $this->fixture(null, $identifier);
            $this->assertSame(ProcessorMovementRecordOutcome::Created, app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true));
        }
        $this->assertDatabaseCount('processor_movements', 2);
    }

    public function test_an_invalid_workspace_timezone_is_refused_without_utc_fallback(): void
    {
        [$workspace, $payment] = $this->fixture();
        $workspace->update(['timezone' => 'Synthetic/Unknown']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The processor movement workspace timezone is unavailable.');
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
    }

    public function test_every_tenant_read_and_update_names_the_workspace(): void
    {
        [$workspace, $payment] = $this->fixture();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (preg_match('/^(select|update).*"(?:client_invoice_payments|processor_movements)"/', str_replace('`', '"', $query->sql)) === 1) {
                $queries[] = $query;
            }
        });
        $recorder = app(ProcessorMovementRecorder::class);
        $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $recorder->record($workspace, $payment->id, $this->facts($payment, ['principalMinor' => -101, 'netMinor' => -98]), 'synthetic_account', true);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('"workspace_id" = ?', str_replace('`', '"', $query->sql));
            $this->assertContains($workspace->id, $query->bindings);
        }
    }

    public function test_a_failure_after_insert_rolls_back_the_new_movement(): void
    {
        [$workspace, $payment] = $this->fixture();
        $before = $this->table('client_invoice_payments');
        $failed = false;
        DB::listen(static function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with($query->sql, 'insert') && str_contains(str_replace('`', '"', $query->sql), '"processor_movements"')) {
                $failed = true;
                throw new RuntimeException('Synthetic interrupted movement write.');
            }
        });
        try {
            app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
            $this->fail('The synthetic failure was not reached.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic interrupted movement write.', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertDatabaseCount('processor_movements', 0);
        $this->assertSame($before, $this->table('client_invoice_payments'));
    }

    public function test_the_composite_foreign_key_refuses_cross_workspace_payment_references(): void
    {
        [$workspace, $payment] = $this->fixture();
        [, $foreign] = $this->fixture(null, 'synthetic_foreign_payment');
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $row = ProcessorMovement::query()->sole()->getAttributes();
        unset($row['id']);
        $row['public_id'] = (string) Str::uuid();
        $row['provider_entry_identifier'] = 'synthetic_cross_tenant_entry';
        $row['client_invoice_payment_id'] = $foreign->id;
        $this->expectException(QueryException::class);
        DB::table('processor_movements')->insert($row);
    }

    public function test_model_saves_cannot_replace_recorded_money(): void
    {
        [$workspace, $payment] = $this->fixture();
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $stored = ProcessorMovement::query()->sole();
        $stored->principal_minor = -101;
        $this->expectException(LogicException::class);
        $stored->save();
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function outOfRangeDates(): iterable
    {
        $cases = [
            'early UTC occurrence' => [['occurredAt' => CarbonImmutable::create(999, 12, 31, 23, 59, 59, 'UTC')], 'UTC'],
            'late UTC occurrence' => [['occurredAt' => CarbonImmutable::create(10000, 1, 1, 0, 0, 0, 'UTC')], 'UTC'],
            'early availability' => [['availableOn' => CarbonImmutable::create(999, 12, 31, 23, 59, 59, 'UTC')], 'UTC'],
            'late availability' => [['availableOn' => CarbonImmutable::create(10000, 1, 1, 0, 0, 0, 'UTC')], 'UTC'],
            'local date underflow' => [['occurredAt' => CarbonImmutable::create(1000, 1, 1, 0, 30, 0, 'UTC')], 'America/Los_Angeles'],
            'local date overflow' => [['occurredAt' => CarbonImmutable::create(9999, 12, 31, 23, 30, 0, 'UTC')], 'Pacific/Kiritimati'],
        ];
        foreach ($cases as $name => [$overrides, $timezone]) {
            foreach ([false, true] as $apply) {
                yield $name.($apply ? ' apply' : ' preview') => [$overrides, $timezone, $apply];
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('outOfRangeDates')]
    public function test_dates_outside_the_database_range_are_refused_before_any_insert(array $overrides, string $timezone, bool $apply): void
    {
        [$workspace, $payment] = $this->fixture();
        $workspace->update(['timezone' => $timezone]);
        $inserts = 0;
        DB::listen(static function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with($query->sql, 'insert') && str_contains(str_replace('`', '"', $query->sql), '"processor_movements"')) {
                $inserts++;
            }
        });
        try {
            app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment, $overrides), 'synthetic_account', $apply);
            $this->fail('An out-of-range processor date was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('The processor movement date is outside the supported range.', $exception->getMessage());
        }
        $this->assertSame(0, $inserts);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_the_first_and_last_supported_database_years_remain_available(): void
    {
        [$workspace, $payment] = $this->fixture();
        foreach ([1000, 9999] as $year) {
            $at = CarbonImmutable::create($year, 1, 1, 12, 0, 0, 'UTC');
            $facts = $this->facts($payment, ['identifier' => 'synthetic_entry_'.$year, 'occurredAt' => $at, 'availableOn' => $at]);
            $this->assertSame(ProcessorMovementRecordOutcome::Created, app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $facts, 'synthetic_account', true));
        }
        $this->assertSame(['1000-01-01', '9999-01-01'], ProcessorMovement::query()->orderBy('id')->get()->map(static fn (ProcessorMovement $movement): string => $movement->occurred_on->toDateString())->all());
    }

    public function test_case_sensitive_provider_entries_and_account_namespaces_remain_distinct(): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $recorder->record($workspace, $payment->id, $this->facts($payment), 'SYNTHETIC_ACCOUNT', true);
        $recorder->record($workspace, $payment->id, $this->facts($payment, ['identifier' => 'SYNTHETIC_ENTRY']), 'synthetic_account', true);
        $this->assertDatabaseCount('processor_movements', 3);
    }

    public function test_the_model_refuses_moving_a_recorded_entry_to_another_workspace(): void
    {
        [$workspace, $payment] = $this->fixture();
        [$foreign] = $this->fixture(null, 'synthetic_foreign_payment');
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $stored = ProcessorMovement::query()->sole();
        $stored->workspace_id = $foreign->id;
        $this->expectException(WorkspaceOwnershipImmutable::class);
        $stored->save();
    }

    public function test_the_actual_recorder_takes_payment_before_movement_locks(): void
    {
        [$workspace, $payment] = $this->fixture();
        LockOrderRecorder::start();
        try {
            app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
            $this->assertSame([[LockResource::BillingMaintenance, LockResource::ClientInvoicePayment, LockResource::ProcessorMovement]], LockOrderRecorder::sequences());
        } finally {
            LockOrderRecorder::stop();
        }
    }

    public function test_an_insert_race_compares_the_unique_key_winner(): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        $facts = $this->facts($payment);
        $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true);
        $winner = ProcessorMovement::query()->sole()->getAttributes();
        DB::table('processor_movements')->where('workspace_id', $workspace->id)->where('id', $winner['id'])->delete();
        $interleaved = false;
        // One connection models the insert losing after its absent-row read;
        // this asserts duplicate handling, not real engine lock contention.
        DB::connection()->beforeExecuting(static function (string $sql) use (&$interleaved, $winner): void {
            if (! $interleaved && str_starts_with($sql, 'insert') && str_contains(str_replace('`', '"', $sql), '"processor_movements"')) {
                $interleaved = true;
                DB::table('processor_movements')->insert($winner);
            }
        });
        $this->assertSame(ProcessorMovementRecordOutcome::Unchanged, $recorder->record($workspace, $payment->id, $facts, 'synthetic_account', true));
        $this->assertTrue($interleaved);
        $this->assertSame($winner, ProcessorMovement::query()->sole()->getAttributes());
        $this->assertDatabaseCount('processor_movements', 1);
    }

    public function test_an_empty_or_overwide_account_namespace_is_refused(): void
    {
        [$workspace, $payment] = $this->fixture();
        foreach (['', '  ', 'synthetic account', 'synthetic_account ', "synthetic\taccount", str_repeat('x', 256)] as $account) {
            try {
                app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $this->facts($payment), $account, true);
                $this->fail('An invalid account namespace was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The processor movement account namespace is unavailable.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_a_padded_or_control_character_entry_id_is_refused_before_sql(): void
    {
        [$workspace, $payment] = $this->fixture();
        $recorder = app(ProcessorMovementRecorder::class);
        $recorder->record($workspace, $payment->id, $this->facts($payment), 'synthetic_account', true);
        $before = ProcessorMovement::query()->sole()->getAttributes();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        foreach (['synthetic_entry ', ' synthetic_entry', "synthetic\tentry"] as $identifier) {
            try {
                $recorder->record($workspace, $payment->id, $this->facts($payment, ['identifier' => $identifier]), 'synthetic_account', true);
                $this->fail('A padded or unreadable entry identity was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The processor movement entry identity is unavailable.', $exception->getMessage());
            }
        }
        $this->assertSame(0, $queries);
        $this->assertSame($before, ProcessorMovement::query()->sole()->getAttributes());
    }

    public function test_a_mismatched_provider_cannot_claim_an_existing_payment(): void
    {
        [$workspace, $payment] = $this->fixture();
        $facts = $this->facts($payment, ['provider' => 'square', 'dateBasis' => 'square_effective_at']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The processor movement payment is unavailable.');
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $facts, 'synthetic_account', true);
    }

    /** @param array<string, mixed> $overrides */
    private function facts(ClientInvoicePayment $payment, array $overrides = []): ProcessorMovementFacts
    {
        return new ProcessorMovementFacts(...array_replace([
            'provider' => 'stripe', 'identifier' => 'synthetic_entry', 'paymentIdentifiers' => [(string) $payment->provider_payment_identifier],
            'sourceIdentifier' => 'synthetic_refund', 'kind' => ProcessorMovementKind::Refund, 'principalMinor' => -100, 'feeMinor' => -3, 'netMinor' => -97,
            'currency' => 'USD', 'occurredAt' => CarbonImmutable::parse('2030-01-02T12:00:00Z'), 'dateBasis' => 'stripe_balance_created',
        ], $overrides));
    }

    /** @return array{Workspace, ClientInvoicePayment} */
    private function fixture(?Workspace $workspace = null, string $identifier = 'synthetic_payment'): array
    {
        $suffix = (string) Str::uuid();
        $workspace ??= Workspace::query()->create(['name' => 'Synthetic workspace', 'slug' => 'synthetic-'.$suffix, 'timezone' => 'UTC']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-'.$suffix]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-'.$suffix, 'currency' => 'USD']);
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'status' => 'succeeded',
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'provider' => 'stripe', 'provider_payment_identifier' => $identifier]);

        return [$workspace, $payment];
    }

    /** @return list<array<string, mixed>> */
    private function table(string $name): array
    {
        return DB::table($name)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
