<?php

namespace App\Support\Concurrency;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

/**
 * Every row this application takes a pessimistic lock on, in acquisition order.
 *
 * The order is declaration order, and it was read off the code rather than
 * chosen for it. Every service was instrumented, the whole suite was run with
 * the recorder on, and the pairs the recorded transactions actually fix are
 * what this list encodes; where two paths disagreed, the majority order won and
 * the minority is named as an inversion rather than accommodated.
 * `docs/client-management/concurrency.md` is that derivation written out, with
 * the pair-by-pair evidence.
 *
 * Bending the order around a disagreement would have been the tempting move and
 * it is the wrong one: it makes the exception invisible, and a registry that
 * has been quietly reshaped to fit whatever the code does cannot answer the
 * next ordering question, which is the only thing it is for. So
 * `LockOrderConformanceTest` refuses any sequence that walks backwards through
 * this list except the two it names, and it fails just as loudly if a named
 * inversion stops happening.
 *
 * What none of this does is prove race freedom. It proves ordering discipline.
 * The suite runs on one connection, so it cannot make two transactions contend;
 * a green conformance run says the code takes its locks in one consistent
 * order, not that concurrent callers are safe. See the doc's honest-limits
 * section.
 */
enum LockResource: string
{
    // Engagement, which is upstream of everything it produces.
    case ClientProposal = 'client_proposals';
    case ClientBillingSchedule = 'client_billing_schedules';
    case ClientAgreement = 'client_agreements';

    // Money already taken, before the invoice it is taken against: payment
    // status and refund both start from the payment row and reach the invoice
    // through it, never the other way.
    case ClientInvoicePayment = 'client_invoice_payments';
    case PaymentReconciliation = 'payment_reconciliations';

    case ClientInvoice = 'client_invoices';

    // Numbering, which every generator reaches only once it has an invoice to
    // number, and the workspace row that serialises the counter.
    case Workspace = 'workspaces';
    case WorkspaceInvoiceCounter = 'workspace_invoice_counters';

    // What an invoice is built out of.
    case ClientTimeEntry = 'client_time_entries';
    case ClientTask = 'client_tasks';

    // The company is last because that is where the code puts it: issuing locks
    // the invoice and then the company whose credit pool it spends, and
    // proposal acceptance and agreement activation both reach it after the row
    // they started from. It reads like a parent and behaves like a shared
    // resource acquired at the end.
    case ClientCompany = 'client_companies';
    case ClientProject = 'client_projects';
    case User = 'users';

    // Credentials. Disconnecting an agent revokes its refresh token and then
    // its access token, and takes no other lock on the way; nothing else in the
    // application locks either table, so this pair orders only against itself.
    case OAuthAccessToken = 'oauth_access_tokens';

    // Provider state, which no sequence mixes with any of the above.
    case StripePaymentMethodState = 'stripe_payment_method_states';
    case ClientStripeCustomer = 'client_stripe_customers';
    case ClientStripePaymentMethod = 'client_stripe_payment_methods';

    /**
     * Where this resource sits in the acquisition order.
     *
     * Positions are derived from declaration order rather than written down a
     * second time, so inserting a case cannot leave a rank stale - and cannot
     * silently renumber an existing pair either, because a case may only be
     * inserted where the recorded sequences already put it.
     */
    public function rank(): int
    {
        foreach (self::cases() as $position => $case) {
            if ($case === $this) {
                return $position;
            }
        }

        // Unreachable: `$this` is always one of `self::cases()`. Stated rather
        // than assumed because the alternative is an implicit null rank that
        // would compare as smaller than every real one and make any sequence
        // containing it monotonic.
        throw new RuntimeException('A lock resource is not one of its own cases.');
    }

    /**
     * The resource a locking query is about to take a lock on.
     *
     * Resolved from the table rather than passed in by the caller, because a
     * caller that names its own resource can name the wrong one, and the whole
     * value of the registry is that the recorded sequence is the real one.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    public static function forQuery(EloquentBuilder|QueryBuilder $query): self
    {
        if ($query instanceof EloquentBuilder) {
            return self::forTable($query->getModel()->getTable());
        }

        $from = $query->from;

        if (! is_string($from)) {
            throw new RuntimeException('A pessimistic lock was taken on a query whose table is an expression, so it cannot be placed in the lock-order registry. Lock a plain table, or a model.');
        }

        // Taken whole, so `from ... as alias` is refused rather than resolved.
        // An earlier revision split it on Laravel's own `\s+as\s+`; that was
        // defence against a shape nothing here produces - a lock is taken on
        // rows by key, and no call site aliases the table it locks - and it
        // bought that defence with a fallback branch and a cast the analyser
        // requires and no test can reach. Failing closed and naming the table
        // in full is the direction a registry whose whole point is "an unranked
        // lock is refused" should be wrong in. Add the split back alongside a
        // call site that needs it.
        return self::forTable($from);
    }

    /**
     * Fails closed on an unregistered table.
     *
     * A silently unranked lock is worse than no registry: it would be recorded
     * nowhere, ordered against nothing, and pass every conformance check. The
     * cost of the refusal is one enum case, and adding it forces whoever adds
     * the lock to say where in the order it belongs.
     */
    public static function forTable(string $table): self
    {
        $resource = self::tryFrom($table);

        if (! $resource instanceof self) {
            throw new RuntimeException(sprintf(
                'No lock-order registry entry for table "%s". Add a case to %s in the position the acquisition order puts it, and record why in docs/client-management/concurrency.md.',
                $table,
                self::class,
            ));
        }

        return $resource;
    }
}
