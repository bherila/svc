# Billing simplification: READ COMMITTED and whole time entries

**Status:** approved by the owner on 2026-11-16 (both parts, and the decisions in Part 2). No code has changed yet. It comes out of #718: the guard gaps left there are symptoms of two design choices, and removing the choices removes the need for most of the guards.

The two parts are independent. Part 1 is small and should go first. Part 2 is larger and benefits from Part 1 being in place.

## Part 1: billing runs at READ COMMITTED

### The problem it removes

MariaDB's default isolation level, REPEATABLE READ, fixes a transaction's snapshot at its first ordinary read. A locking read sees the latest committed row, but every later ordinary read in the same transaction still sees the old snapshot. #661 and #718 spent most of their effort on that gap:

- **The ratchet:** `tests/Fixtures/Concurrency/snapshot-reads.txt` has 723 reviewed entries, recorded by `tests/Concerns/RecordsSnapshotReads.php` and asserted by `LockOrderConformanceTest`. Each entry needed a reason it cannot change money.
- **Revision counters and snapshot comparisons:** each reads a value twice, once locked and once plain, and refuses when they differ. They exist only because the plain read can be older:
  - `credit_revision` and `CreditPoolChanged` (`OverpaymentCreditService`);
  - the `client_agreement_revisions` table and `AgreementsChanged` (`ProposalAcceptanceAgreementQuery::lockCompany`), added in #744 as a table of its own because of snapshot semantics;
  - `InvoiceLifecycleService::assertCurrentInvoiceSnapshot` and `assertSnapshotHoldsTheLockedLines`;
  - `PaymentRefundLedger::assertSnapshotCurrent` (`PaymentVersionChanged`);
  - `HeldWorkDisclosure::assertCurrent`.
- **Race tests that reproduce stale snapshots on purpose,** including `CreditSpendConcurrencyTest`, `AgreementActivationConcurrencyTest`, `InterimClaimConcurrencyTest`, `BuyDownAttachSnapshotTest`, `CadenceGenerationSnapshotTest`, `TimeMutationConcurrencyTest` and `ManualClientEmailBudgetConcurrencyTest`.

At READ COMMITTED, every statement reads the latest committed data. A read after a lock sees what the lock protects, so a decision computed from reads made after its lock cannot be stale.

READ COMMITTED doesn't make a decision computed from reads made *before* the lock safe. Under REPEATABLE READ, those checks catch it by re-reading plainly: the plain read returns the old snapshot, which differs from the locked row. Under READ COMMITTED, the plain re-read returns the current row and matches the lock, so the check passes on a decision made from older data. So the switch only simplifies the code if every check that compares "what was used" with "what is locked" is rewritten. It must compare the locked row with the values the computation actually used, carried in memory, never with a re-read.

### The mechanism

One line per server connection in `config/database.php`:

```php
'isolation_level' => 'READ COMMITTED',
```

Laravel's `MySqlConnector` runs `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` on connect, so every transaction on every path gets it. There's no need to find the outermost opener of each of the ~200 `DB::transaction` sites. SQLite has no isolation levels and is unaffected.

A transaction that truly needs one consistent view opts back in before it begins, with `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, which is allowed outside a transaction. Today that's one place, `OverpaymentCreditAuditor::partitions()`. It's a read-only scan whose docblock relies on REPEATABLE READ so that it never reports "a balance that existed at no moment". `scripts/db-snapshot.sh` uses `mariadb-dump --single-transaction` in a session of its own and is unaffected.

### What must change in the same release

Every change below must be correct at **both** isolation levels, so a rollback stays a one-line revert.

1. **Every plain-versus-locked comparison is audited.** Each check that reads plainly and under a lock and refuses on a difference falls into one of two classes:
   - **(a)** Its decision reads only after the lock. At READ COMMITTED it is dead, and it is removed in Release 2.
   - **(b)** Its decision was computed from reads made before the lock. Its plain re-read must be replaced by the values the computation used, carried in memory. That version also works at REPEATABLE READ.

   The sites:
   - `InvoiceLineComposer::pricedEntry`. Under the time it guards, `TimeChangedDuringBilling`, the composer prices fragments and lines, then recovers the priced row with a plain `find()`. At READ COMMITTED that returns the current row, so an entry cut from 120 to 60 minutes between composition and attach passes both `pricedEntry` and `TimeEntrySplitter::assertCurrent`. The fix is to carry each entry's priced minutes and `lock_version` from composition into attach.
   - `credit_revision` (`OverpaymentCreditService`), `ProposalAcceptanceAgreementQuery::lockCompany`, `InvoiceLifecycleService::assertCurrentInvoiceSnapshot` and `assertSnapshotHoldsTheLockedLines`, `PaymentRefundLedger::assertSnapshotCurrent`, `HeldWorkDisclosure::assertCurrent`, and the time and ledger fingerprints behind `InterimLedgerChanged`.
   - `PricingBasisRecorder::record`, item 2.

   Each site gets a two-session test at READ COMMITTED for the interleaving it guards, a change committed after the computation's reads and before its lock, in the `BuyDownAttachSnapshotTest` pattern.
2. **`PricingBasisRecorder::record` must describe what was priced.** This is a class (b) case.
   - **The problem:** it re-reads the time entries and invoices when it records the basis, after the lines are composed. Generation prices from many plain queries (`CapacityLedgerInputs`, `monthlyBalances`, `BilledOverageLedger`). At READ COMMITTED, a commit between pricing and recording would put the newer state into the basis. The issue's re-check would then match the basis, and a draft priced from older state would issue.
   - **The fix:** build the basis from the inputs the pricing actually consumed, passed in from the generator rather than re-read.
   - **Alternative:** take the basis's input rows with a lock before pricing, which the agreement lock already partly does.
3. **`ManualClientEmailBudget::admit`** reads its count before the upsert and adds `current - seen` to compensate for an old snapshot. At READ COMMITTED the locked count already includes those admissions, so they'd be counted twice and refused too early.
   - **Don't just delete the compensation.** Back at REPEATABLE READ, `usedInLastDay()`'s ordinary reads could miss an admission committed after a caller's snapshot, and the daily limit would be exceeded.
   - **The fix:** count under a locking read, which sees the latest committed rows at either level, then drop the compensation and the one-admit-per-transaction rule.
4. **`TimeEntryMutationService::allocatedInvoice`** runs its plain read before its locked read. At READ COMMITTED that only causes a rare spurious 409, but taking the lock first removes it.
5. **`docs/client-management/concurrency.md`:** rewrite the sections that explain behaviour by "the snapshot follows the agreement lock". The rule becomes: take the lock, then read. A value read before the lock is compared, under the lock, with what was used, never with a re-read.

### What stays

- **Checks against a value the caller sent:** the expected-version checks in `InvoiceLifecycleService`, `BillingScheduleService`, `AgreementWorkflow`, `DeferredBuyDownAction`, `SquareLinkService` and `PackageMigrationWorkflow`.
- **`TimeChangedDuringBilling`:** it guards a lost update between pricing a time entry and attaching it.
- **`PricingBasisChanged`:** it compares against the basis stored on the draft.
- **Lock order** (`LockResource`, `LockOrderConformanceTest`), parent-row locks and unique indexes.
- **`InterimLedgerChanged`:** at READ COMMITTED it can still fire on a row inserted between its two reads. That refusal is conservative and already documented.

### Gap locks

READ COMMITTED disables InnoDB gap and next-key locks, except for foreign-key and duplicate-key checks. So a `FOR UPDATE` over a range no longer stops a concurrent insert into it. The survey for this proposal found no billing path that relies on a gap lock alone. Each is also serialized by a parent row lock or a unique index:

- **Overlapping activation:** the company row lock.
- **A cadence invoice per period:** the agreement lock and `CadencePeriodGuard`.
- **Allocation:** `invoice_time_entry_once` and the locked time entries.
- **Idempotency and receipt tables:** unique indexes.
- **Invoice numbers:** the counter row lock and a unique index.
- **Square links:** the workspace row lock.

A test for this belongs in the change. For each of those writers, two sessions race an insert into an empty range at READ COMMITTED, and the outcome must be exactly one row.

### What goes away afterwards

A second release, once READ COMMITTED has run in production, removes:

- the revision counters and snapshot comparisons listed above, including dropping `client_agreement_revisions` and `credit_revision`, each with a migration;
- the ratchet, its recorder and its 723-line fixture;
- the snapshot sections of `concurrency.md`.

The stale-snapshot race tests become plain race tests and keep their assertions.

### Operator check before the switch

READ COMMITTED with binary logging requires row-based or mixed logging. Checked on web1 on 2026-11-16 (shared hosting; neither setting can be changed there):

```sql
SELECT @@log_bin, @@binlog_format;  -- 0, MIXED
```

Binary logging is off, so the restriction does not apply, and `MIXED` would satisfy it if the host turned logging on. The isolation level is set per connection (`SET SESSION TRANSACTION ISOLATION LEVEL`, run by Laravel's connector from the `isolation_level` key), which needs no server privilege, so nothing on the host changes. If the app moves to a host with `binlog_format = STATEMENT`, change it to `ROW` or `MIXED` before deploying there.

### Rollout and rollback

- **Release 1:** the config line, the audit and fixes above, the auditor's opt-in, and the gap-lock and interleaving race tests.
  - **The checks stay.** Class (a) checks are kept, dead at READ COMMITTED. Class (b) checks are rewritten to compare carried values, which holds at both levels.
  - **Rollback:** going back to REPEATABLE READ is just reverting the line.
  - **Size:** several days, not one, because the audit is per site.
- **Release 2:** removes the class (a) checks, the table and column, and the ratchet.

## Part 2: time entries stay whole

### The problem it removes

To bill part of an entry, `TimeEntrySplitter` cuts it into rows:

- **Where it splits:** at the retainer and capacity boundary on the monthly path, at covered versus overage on the cadence path, and for interim overage claims, where the overage part sits on one invoice and the rest on another.
- **What the split does:** the root's `minutes` is reduced to its first piece, and each fragment is a new row copying about 30 columns, including approvals and all seven dispute fields.
- **Recombining:** `AllocationService::recombineUnlinkedFragments` merges them back at the start of generation, but only if a 30-field signature still matches.

Every fragment is a full time entry with its own status, approval, dispute and deferral. That's where the complexity comes from:

- **Divergent state:** a fragment can be disputed or written off while its sibling bills.
- **Rows the client sees:** clients see the fragments as separate rows.
- **Stale ids:** the agent API has to answer `409 inconsistent split lineage` when a merge makes an id disappear.
- **Fingerprints:** they must include the lineage.
- **Guards:** #746 added the "a split family charges at most the minutes logged" guard.
- **Tests:** about 120 test methods across about 15 files pin this behaviour.

### The proposal

- **The allocation records the minutes billed.** `client_invoice_line_time_entries` gains `minutes`. An entry can be allocated in parts, to one line or several, and a line's hours are the sum of its allocations' minutes.
- **The invariant becomes one sum.** Per entry, the live allocations' minutes (excluding void invoices) add up to at most the entry's minutes. `invoice_time_entry_once` (one allocation per entry, ever) is replaced by that invariant, enforced under the entry's row lock at attach and asserted by `BillingHistoryInvariantTest`. The database can't enforce a sum across rows, so the lock and the test do.
- **The composer attaches minutes instead of splitting.** `TimeEntrySplitter`'s pooling logic (`allocateTimeEntries`) stays. It already works in minutes. `splitEntry`, recombination and `split_from_time_entry_id` go.
- **Readers sum allocation minutes instead of trusting `entry.minutes`.** These assume that linked means all of the row:
  - `InvoiceLinePreview` and `InvoiceLineDetail` (the PDF appendix);
  - statement reconciliation in `ClientInvoicingService`;
  - `CapacityLedgerInputs`;
  - `ClientTimeEntry::scopeUnbilled`, which becomes "has unallocated minutes";
  - `DeferredBacklogReader`, `LateWork` and `ConsultantTimeReleaser`;
  - `TimeEntryAllocation` and `AgentReadService`.
- **Writers that refuse an entry with another allocation must allow a partial one.** Each needs remaining-minute pricing, and an entry on several drafts and issued invoices needs explicit regeneration and immutability rules:
  - `TimeEntryMutationService::allocatedInvoice` answers 409 when an entry is on more than one invoice, even two editable drafts.
  - `InvoiceFromTimeService::prepareLines` refuses an entry allocated to another invoice and prices the whole of `entry.minutes`.
  - `InvoiceFromTimeService::addTime` refuses any existing allocation.
- **"Invoiced" is derived, not stamped.** Today issue sets `status='invoiced'` per row. Instead, an entry is invoiced when its allocations on issued invoices cover all its minutes. Until then it stays approved, with the remaining minutes open.

### Decisions (approved by the owner, 2026-11-16)

1. **Dispute, approval and write-off apply to the whole entry.** Today a client can dispute one fragment. Decided: the client reviews the entry as logged.
   - **When part of it is already on an issued invoice,** the dispute follows the existing already-charged path: it can be answered, not written off.
   - **Write-off is all or nothing.** If writing off part of an entry is needed, the operator first splits it by editing, as two entries a person chose, not ones the generator made.
2. **What the time sheet and portal show:** one row per entry, with "billed 45 of 90 min" when it's partly allocated, instead of separate fragment rows.
3. **Editing a partly billed entry:** fields that change billing (minutes, date, rate, project) are refused once any allocation is on an issued invoice. That's today's rule for an invoiced row, applied to the whole entry.

Deferral doesn't change. It never splits today, and an entry is deferred whole.

### Migrating existing data

For each root with live fragments:

1. Set the root's `minutes` to the root's minutes plus the live fragments' minutes. Soft-deleted fragments are excluded either way:
   - a merge loser's minutes were already added to its survivor;
   - a fragment the user deleted no longer counts.
2. Re-point each fragment's allocations to the root, keeping each allocation's `minutes` exactly as stored. They were backfilled in Release 1 and may have been split further by Release 2: a 90-minute fragment can by then carry two 45-minute allocations. The root's own allocations keep theirs too. Nothing is reset from a row's minutes after Release 1. A family survives generation untouched until this step, because `AllocationService::canMerge()` refuses to recombine a family with any allocated member.
3. Soft-delete the fragments.

Families whose members diverge (different status, dispute, approval, deferral or description) can't be folded without a decision. A read-only artisan command counts them first. The owner then picks one of these per class:

- keep the root's state;
- detach the fragment as an independent entry, which nulls its lineage and leaves it whole.

Only Release 1 backfills `minutes` from a row, which at that point is always the whole row.

### Rollout and rollback

1. **Release 1 (no behaviour change):** add `allocation.minutes`, backfilled from each entry's minutes, and switch every reader to sum it. Because every allocation is still a whole row, the output is identical. The test is that all existing billing tests pass unchanged.
2. **Release 2:** the composer, `InvoiceFromTimeService` and `TimeEntryMutationService` attach and price partial minutes, the composer stops splitting, and the unique index is dropped.
   - **Rollback risk:** this is the one release a rollback can't fully undo. Previous code reads any allocation as the whole row, so an entry partly allocated by Release 2 would look fully billed to it, and its remaining minutes would wait until a forward fix.
   - **Recommendation:** deploy it alone and roll forward rather than back.
3. **Release 3:** migrate existing families as above, then remove the splitter's `splitEntry`, recombination, the lineage column and its 409, and the lineage in fingerprints. Rewrite or delete the tests that pin splitting, and replace the split-family guard with the sum invariant.

## Order and what it buys

1. **Part 1, Release 1:** several days for the per-site audit. The binlog check is done (above).
2. **Part 1, Release 2:** mostly deletion.
3. **Part 2, Releases 1 to 3:** the larger piece. Its decisions above are settled; the divergent-family classes are decided when the count command has run.

Together they retire the snapshot ratchet and its 723 entries, the revision counters, split lineage and recombination, and the split-family guard. They also make two of #718's guard gaps unnecessary: "charged once" becomes a single sum, and the lossy allocation digest after a buy-down disappears with lineage in digests.
