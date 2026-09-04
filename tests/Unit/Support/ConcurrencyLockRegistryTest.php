<?php

namespace Tests\Unit\Support;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
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
     * exactly the lock nobody has thought about.
     *
     * The whole message, not a fragment. The caller is a builder chain several
     * frames down, so the refusal has to carry the table it could not place
     * *and* say what to do about it - and asserting only the first clause lets
     * the actionable half be dropped silently.
     */
    public function test_an_unregistered_table_is_refused_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'No lock-order registry entry for table "client_invoice_lines". Add a case to '
            .LockResource::class
            .' in the position the acquisition order puts it, and record why in '
            .'docs/client-management/concurrency.md.',
        );

        LockResource::forTable('client_invoice_lines');
    }

    /**
     * A model query is placed by its model's table, not by its query's `from`.
     *
     * The second half is the load-bearing one and the reason this does not just
     * read `$query->from` for everything: an Eloquent builder can be pointed at
     * another table, and the rows a model lock takes are still its model's. A
     * resolver that fell through to the query would place this lock under a
     * table the registry has never ranked - and would say so by refusing,
     * which is a confusing way to be told the resolver is wrong.
     */
    public function test_an_eloquent_query_resolves_through_its_models_table(): void
    {
        $this->assertSame(
            LockResource::ClientInvoice,
            LockResource::forQuery(ClientInvoice::query()->whereKey(1)),
        );

        $repointed = ClientInvoice::query()->whereKey(1);
        $repointed->getQuery()->from = 'client_invoice_lines';

        $this->assertSame(LockResource::ClientInvoice, LockResource::forQuery($repointed));
    }

    /** A plain table query resolves by the name it names. */
    public function test_a_plain_table_query_resolves_through_its_table_name(): void
    {
        $this->assertSame(
            LockResource::StripePaymentMethodState,
            LockResource::forQuery(DB::table('stripe_payment_method_states')),
        );
    }

    /**
     * An aliased table is refused, and refused *as itself*.
     *
     * Deliberate. Nothing here locks an aliased table - a lock is taken on rows
     * by key - so accepting the form would be defence against a shape that does
     * not occur, and it cost a fallback branch and a cast that no test could
     * reach. Refusing names the table in full, which is the direction a
     * registry whose point is "an unranked lock is refused" should be wrong in,
     * and it says exactly what to add if a call site ever needs it.
     */
    public function test_an_aliased_table_is_refused_rather_than_silently_resolved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No lock-order registry entry for table "stripe_payment_method_states as states"');

        LockResource::forQuery(DB::table('stripe_payment_method_states as states'));
    }

    /**
     * A table this cannot read is refused rather than guessed at.
     *
     * The whole message again, for the same reason: it has to say what to do,
     * not only that something went wrong.
     */
    public function test_a_query_whose_table_is_an_expression_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'A pessimistic lock was taken on a query whose table is an expression, so it cannot be placed in the '
            .'lock-order registry. Lock a plain table, or a model.',
        );

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
     * Two locks outside any transaction are two sequences, not one.
     *
     * A lock taken outside a transaction is released at the end of the
     * statement, so it is its own one-element sequence and never part of the
     * next one. Asserted with *two* locks on purpose: with one, a recorder that
     * appended to the open frame instead of closing a sequence produces exactly
     * the same output, and the distinction only shows on the second.
     */
    public function test_locks_outside_a_transaction_are_each_their_own_sequence(): void
    {
        LockOrderRecorder::start();

        ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
        ClientInvoice::query()->whereKey(2)->tap(Locks::forUpdate());

        $this->assertSame(
            [[LockResource::ClientInvoice], [LockResource::ClientInvoice]],
            LockOrderRecorder::sequences(),
        );
    }

    /**
     * Locks inside one transaction are one sequence, in order.
     *
     * The grouping the conformance test's whole claim rests on, at unit scale:
     * the queries are built and never run, so this needs a transaction and not
     * a schema. Read once while the transaction is still open - a sequence that
     * only appeared on commit would be invisible to any assertion made inside
     * the workflow it is about - and once after, to show it is archived rather
     * than merely still open.
     */
    public function test_locks_inside_one_transaction_are_one_ordered_sequence(): void
    {
        LockOrderRecorder::start();

        DB::transaction(function (): void {
            ClientAgreement::query()->whereKey(1)->tap(Locks::forUpdate());
            ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());

            $this->assertSame(
                [[LockResource::ClientAgreement, LockResource::ClientInvoice]],
                LockOrderRecorder::sequences(),
            );
        });

        $this->assertSame(
            [[LockResource::ClientAgreement, LockResource::ClientInvoice]],
            LockOrderRecorder::sequences(),
        );
    }

    /**
     * A sequence is archived when its transaction ends, not left open.
     *
     * The distinction is invisible to a single transaction, because
     * `sequences()` reports a still-open frame as well as the archived ones -
     * so a recorder that never closed anything would return exactly the same
     * list. It shows on what comes next: once the transaction has ended, the
     * following lock is a new sequence rather than the tail of the old one.
     */
    public function test_a_sequence_is_archived_when_its_transaction_ends(): void
    {
        LockOrderRecorder::start();

        DB::transaction(function (): void {
            ClientAgreement::query()->whereKey(1)->tap(Locks::forUpdate());
            ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
        });

        ClientInvoice::query()->whereKey(2)->tap(Locks::forUpdate());

        $this->assertSame(
            [
                [LockResource::ClientAgreement, LockResource::ClientInvoice],
                [LockResource::ClientInvoice],
            ],
            LockOrderRecorder::sequences(),
        );
    }

    /** A transaction that takes no locks records no sequence, not an empty one. */
    public function test_a_transaction_that_takes_no_locks_records_nothing(): void
    {
        LockOrderRecorder::start();

        DB::transaction(static fn (): null => null);

        $this->assertSame([], LockOrderRecorder::sequences());
    }

    /**
     * A nested transaction continues its parent's sequence.
     *
     * A nested `DB::transaction()` is a savepoint, and releasing a savepoint
     * releases no locks - so its locks are still held by the outermost
     * transaction when the next one is asked for, and they belong to the same
     * sequence. A recorder that started a frame per savepoint would lose
     * everything the parent took before it; one that archived on each savepoint
     * release would split a single held set into several, and the ordering
     * claim would be made about neither.
     *
     * The third lock, taken after the savepoint is released and before the
     * parent commits, is what makes both of those visible.
     */
    public function test_a_nested_transaction_continues_its_parents_sequence(): void
    {
        LockOrderRecorder::start();

        DB::transaction(function (): void {
            ClientAgreement::query()->whereKey(1)->tap(Locks::forUpdate());

            DB::transaction(function (): void {
                ClientInvoice::query()->whereKey(1)->tap(Locks::forUpdate());
            });

            ClientTimeEntry::query()->whereKey(1)->tap(Locks::forUpdate());
        });

        $this->assertSame(
            [[
                LockResource::ClientAgreement,
                LockResource::ClientInvoice,
                LockResource::ClientTimeEntry,
            ]],
            LockOrderRecorder::sequences(),
        );
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
