<?php

namespace Tests\Unit\Support;

use App\Models\ClientInvoice;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The lock registry's own arithmetic, without a database.
 *
 * `LockOrderConformanceTest` proves the services take their locks in order. It
 * cannot prove the things that order is computed from - a rank that silently
 * returned the same number for two resources, or a table resolver that answered
 * for the wrong table, would make every sequence it records monotonic and every
 * assertion it makes vacuous. Those are pure functions over an enum and a
 * builder, so they are checked here, where nothing is mocked and nothing is
 * stored.
 *
 * The suite this file is in is also the one the diff-scoped mutation gate runs:
 * `infection.diff.json5` measures the Unit suite deliberately, to keep the PR
 * lane fast. Registry code reachable only from a feature test is reported as
 * zero mutants, which the workflow itself says is not evidence that anything
 * discriminated a behavioural change - so the discrimination belongs here.
 */
final class ConcurrencyLockRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        LockOrderRecorder::stop();
        LockOrderRecorder::forgetListeners();

        parent::tearDown();
    }

    /**
     * Rank is declaration order, and every resource has one of its own.
     *
     * The comparison the conformance test makes is `>`, so two resources
     * sharing a rank would compare as ordered in both directions and hide a
     * real inversion between them. Distinctness is the load-bearing half.
     */
    public function test_rank_is_declaration_order_and_unique_per_resource(): void
    {
        $ranks = [];

        foreach (LockResource::cases() as $position => $case) {
            $this->assertSame($position, $case->rank(), $case->name);
            $ranks[] = $case->rank();
        }

        $this->assertSame($ranks, array_values(array_unique($ranks)));
        $this->assertCount(count(LockResource::cases()), $ranks);

        // The pair the order exists for, stated once so a reshuffle that keeps
        // the ranks distinct still has to be deliberate: generation serialises
        // on the agreement precisely because the invoice rows it guards against
        // may not exist yet.
        $this->assertLessThan(
            LockResource::ClientInvoice->rank(),
            LockResource::ClientAgreement->rank(),
        );
    }

    /** Every case is reachable by the table it names. */
    public function test_every_registered_table_resolves_to_its_own_resource(): void
    {
        foreach (LockResource::cases() as $case) {
            $this->assertSame($case, LockResource::forTable($case->value));
        }
    }

    /**
     * An unregistered table is refused, and the refusal names it.
     *
     * Failing closed is the point: an unranked lock would be recorded nowhere,
     * ordered against nothing, and pass every conformance check while being
     * exactly the lock nobody has thought about. The message has to carry the
     * table, because the caller is a builder chain several frames down.
     */
    public function test_an_unregistered_table_is_refused_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No lock-order registry entry for table "client_invoice_lines"');

        LockResource::forTable('client_invoice_lines');
    }

    /** A model query is placed by its model's table, not by its class name. */
    public function test_an_eloquent_query_resolves_through_its_models_table(): void
    {
        $this->assertSame(
            LockResource::ClientInvoice,
            LockResource::forQuery(ClientInvoice::query()->whereKey(1)),
        );
    }

    /**
     * A plain table query resolves too, aliased or not.
     *
     * Aliasing changes what the rows are called and not which rows are locked,
     * so the base name is the answer. Left unhandled, `from ... as x` would
     * miss every case and be refused as unregistered - which reads like a
     * missing registry entry and is not one.
     */
    public function test_a_plain_table_query_resolves_with_or_without_an_alias(): void
    {
        $this->assertSame(
            LockResource::StripePaymentMethodState,
            LockResource::forQuery(DB::table('stripe_payment_method_states')),
        );

        $this->assertSame(
            LockResource::StripePaymentMethodState,
            LockResource::forQuery(DB::table('stripe_payment_method_states as states')),
        );
    }

    /** A table this cannot read is refused rather than guessed at. */
    public function test_a_query_whose_table_is_an_expression_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('whose table is an expression');

        LockResource::forQuery(DB::table(DB::raw('client_invoices')));
    }

    /**
     * The helper sets the builder's lock flag.
     *
     * Asserted on the flag rather than on compiled SQL, because the SQLite
     * grammar renders no lock clause at all: a `toSql()` assertion would pass
     * on the fast lane whether or not the lock was ever requested, which is the
     * shape of green-and-wrong this registry exists to avoid.
     */
    public function test_acquiring_marks_the_query_for_update(): void
    {
        $query = ClientInvoice::query()->whereKey(1);

        $this->assertNull($query->getQuery()->lock);

        $query->tap(Locks::forUpdate());

        $this->assertTrue($query->getQuery()->lock);
    }

    /**
     * Recording is off until a test asks for it, and off again after.
     *
     * This is the whole of the recorder's production behaviour, and the one
     * claim that cannot be made by reading `Locks::forUpdate()`: it calls
     * `record()` unconditionally, so "nothing is recorded in production" is a
     * property of the recorder alone.
     */
    public function test_nothing_is_recorded_until_a_test_asks_and_nothing_after_it_stops(): void
    {
        ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
        $this->assertSame([], LockOrderRecorder::sequences());

        LockOrderRecorder::start();
        ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
        $this->assertSame([[LockResource::ClientInvoice]], LockOrderRecorder::sequences());

        LockOrderRecorder::stop();
        ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
        $this->assertSame([], LockOrderRecorder::sequences());
    }

    /**
     * Starting again forgets what the previous run recorded.
     *
     * Sequences are compared as an exact set by the conformance test, so a
     * recorder that accumulated across runs would report another test's
     * inversions as this one's - and the failure would name a workflow the test
     * never drove.
     */
    public function test_starting_again_discards_the_previous_recording(): void
    {
        LockOrderRecorder::start();
        ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
        $this->assertCount(1, LockOrderRecorder::sequences());

        LockOrderRecorder::start();

        $this->assertSame([], LockOrderRecorder::sequences());
    }
}
