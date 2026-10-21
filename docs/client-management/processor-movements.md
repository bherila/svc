# Reading signed processor movements

`svc:finance:sync-processor-movements` synchronously reads actual Stripe balance
transactions and Square payout entries. It records immutable signed principal,
fee and net facts tied to an already recorded payment; it never issues a refund
or changes an original payment amount, settlement, payout or reconciliation.
The Stripe pass also recovers missed dispute state using the existing invoice
policy, from the same complete provider read. It does not infer money from a
refund or dispute status. A fee on an entry does not prove all related fees are
known. Unbound or incomplete facts remain deferred rather than guessed.
A payment-currency mismatch is a review conflict: no conversion is inferred, and
any existing movement facts stay immutable and held for review.

## Preview before applying

Before the first application, the operator reviews a preview and the coordinator
checks filed-year figures. Production access and that check are separate from
building this feature. Run only the preview for that handoff:

```bash
php -d memory_limit=1G artisan svc:finance:sync-processor-movements --provider=stripe --format=json
php -d memory_limit=1G artisan svc:finance:sync-processor-movements --provider=square --since=2030-01-01 --until=2030-02-01 --format=json
```

The dates above are synthetic examples. Preview is the default; `--apply` opts
into database writes and advances the Stripe attempt cursor. Provider reads
still happen in preview. Reports contain only counts and fixed reason codes,
never record identifiers, amounts, credentials or provider exception text.

`--workspace` accepts a public UUID only. `--payment` selects one existing,
workspace-owned Stripe payment by public UUID and requires `--provider=stripe`;
it does not alter the rotation cursor. An unknown selection is refused, never
expanded into a full scan. Omit both to visit configured providers' known rows.

`--limit` caps records **per provider**, default 50 and maximum 200: a Stripe
record is a known payment, and a Square record is a payout. `--max-calls` caps
shared provider-client calls across the entire command, default 200 and maximum
1000. Provider reads have a ten-minute monotonic deadline. Existing client retries
are bounded separately; the final in-flight read can finish after the deadline.
A 15-minute cache mutex normally separates scheduled and manual commands. Persistence can outlast the provider deadline; entry identity and existing database row/revision locks remain the correctness guard if a run outlasts that cache lease.
Preview does not advance or reset the persisted Stripe cursor.

## Resume and historical coverage

Stripe visits known payments in local-ID order. Each attempted payment advances a
small cached position scoped to the requested workspace or the global scan,
including a deferred, conflicting or provider-refused record, so an old held
payment cannot starve later payments. A second boolean remembers that this cycle
had incomplete records: a clean final batch cannot report full completion after
an earlier batch skipped held facts. Such runs retain nonzero exit and fixed
review/refusal counts. Reaching the end resets the position and that cycle flag,
so the next cycle retries history, including held records and late refunds.
An actual call/time budget denial inside a record preserves its pending position;
simply using the last permitted call does not. Preview reads the next applied
batch without changing either cursor fact. Previously recorded entries replay
safely. An explicit payment never changes the cycle cursor.

Square discovers every configured location's payouts using explicit, fixed
creation-time bounds. `--since` defaults to UTC now minus 30 days and `--until`
to UTC now. An explicit day means midnight UTC on that day, so `--until` at the
next month's first day bounds the preceding month. Movement dates come from
each entry's `effective_at`, not payout creation, arrival or sync time.
Historical payouts outside that window require an explicit earlier `--since`.

A Square payout's complete bounded pages and referenced sources are read before
its first write. Earlier completed payouts may already be recorded if a later
payout fails; rerunning is idempotent. Reaching a record/call/page/time limit is
**incomplete**, exits nonzero and never claims full coverage. At exactly the
Square record cap the result is conservatively incomplete even if that was the
last payout. Narrow `--since` / `--until` and retry until each chosen window
completes. Stripe record-limit failures resume after the last attempted payment. Square record-specific refusals count as deferred and later payouts still run; credential, rate-limit and infrastructure failures stop the pass.

Accepted one-operator procedure: the scheduled recent Square window can be
re-read on every run, while historical windows are narrowed manually when the
budget cannot finish them. There is no persistent per-payout reservation or
coordination subsystem. The mutex is sufficient for this one operator and the
scheduled command; ordinary concurrent webhooks retain database row/revision
checks. Multiple independent unattended readers would require revisiting this
procedure rather than assuming a truncated scan covered history.

The daily scheduler runs Stripe and Square separately, 15 minutes apart, each
with its own limits and provider budget and `withoutOverlapping(30)`. This avoids
one provider consuming the other's daily budget. No background queue worker is
needed. `--provider=all` remains available for a bounded manual run, with one
shared call budget that may leave the second provider incomplete.

Read recorded facts through `svc:finance:processor-movements`, or the corresponding
read-only finance API change feed. Incomplete coverage and a held entry require
review; neither means a missing movement had zero value.
