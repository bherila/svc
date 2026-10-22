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
into database writes and advances the provider scan positions. Provider reads
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
Preview does not advance, create or reset either persisted provider scan position.

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

Square discovers every configured location's payouts in ascending creation-time
order. `--since` defaults to UTC now minus 30 days and `--until` to UTC now. An
explicit day means midnight UTC on that day, so `--until` at the next month's
first day bounds the preceding month. Movement dates come from each entry's
`effective_at`, not payout creation, arrival or sync time. Historical payouts
outside the default window require an explicit earlier `--since`.

Applied runs automatically continue a bounded cycle across invocations. Its
actual start/end instants stay frozen even when the default recent window moves
between daily runs. A small cached position is isolated by workspace, processor
account/environment and the explicit/default bound selection. The cycle retains
its sorted configured locations; a changed location set refuses continuation
with `sync_incomplete` while preserving the old position. Restore that set and
finish the cycle before changing it. If a location is permanently removed, an
operator must explicitly retire the cached cycle and document its incomplete
coverage before starting a replacement scan.

The position retains the current location, a validated pending page of payout
identities and creation instants, the precise last attempted creation instant,
identities tied at that instant and whether earlier facts were incomplete. It
stores no raw provider payload. Changing limits does not start a different cycle;
changing a date selection or connected account does. Keep the cache persistent
between command invocations; losing it safely replays the selected window, but
also loses remembered progress and incomplete-cycle history.

A payout's complete bounded pages and referenced sources are read before its
first write. Its position advances only after that read and processing finish,
including explicit deferred/conflicting facts or record-specific refusals.
Infrastructure failures and real call/time budget denials leave that payout
pending. A later clean batch cannot erase earlier incomplete facts from the same
cycle: the final batch still exits nonzero with `needs_review`. At the end the
position resets for the next cycle, revisiting late source facts and previously
held records. Replays are idempotent. Explicit date selections revisit the same
window; default selections open a new recent 30-day window after completion, so
facts arriving outside that horizon require an explicit historical scan.

[Square cursors expire after five minutes](https://developer.squareup.com/docs/build-basics/common-api-patterns/pagination).
They are used only within the current invocation, with identical query bounds
throughout each cursor chain. After draining saved rows, continuation starts a
fresh ascending query at the saved precise creation instant, using the documented
[inclusive `begin_time`](https://developer.squareup.com/reference/square/payouts-api/list-payouts),
and skips already attempted identities at that exact instant. It never sends a
previous invocation's cursor or narrows only to a calendar day. This lets dense
single-day histories cross API pages under small per-run record and call caps.

Reaching a record/call/page/time limit is **incomplete**, exits nonzero and never
claims full coverage. At exactly the record cap the report is conservatively
incomplete, even if the next invocation only needs to finish the cycle. Each
invocation still admits at most 100 discovery pages per location and 10,000
listed rows; at most 10,000 identities at one exact timestamp are retained. An
unusually large group sharing the *same exact creation instant* can consume the
listing budget before any unseen row is found. Such a cycle remains visibly
incomplete: increase the call budget enough to traverse that tie group, and
investigate groups exceeding the hard bounds. Day-sized windows do not solve an
exact-instant tie. No tie identities are silently dropped and no exhausted scan
is reported complete.

Accepted one-operator coordination: scheduled and manual commands share the
existing cache mutex, while movement persistence retains its database row and
revision checks. There is no reservation subsystem. Independent unattended
writers, or overlapping commands exceeding that lease, require revisiting this
procedure. Run a preview for the next batch before applying; previews never
create, move or reset the saved position, including at the end of a cycle.

The daily scheduler runs Stripe and Square separately, 15 minutes apart, each
with its own limits and provider budget and `withoutOverlapping(30)`. This avoids
one provider consuming the other's daily budget. No background queue worker is
needed. `--provider=all` remains available for a bounded manual run, with one
shared call budget that may leave the second provider incomplete.

Both scheduled runs are withheld until `SVC_PROCESSOR_MOVEMENTS_SCHEDULED_APPLY=true`
(`svc.processor_movements.scheduled_apply`). A new deployment previews first, has
the figures approved, then turns the schedule on and re-caches config; no redeploy
is needed, and turning it back off withholds the next run the same way. Manual
`--apply` runs are not gated by the setting.

Read recorded facts through `svc:finance:processor-movements`, or the corresponding
read-only finance API change feed. Incomplete coverage and a held entry require
review; neither means a missing movement had zero value.
