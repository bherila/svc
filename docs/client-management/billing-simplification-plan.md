# Billing simplification: READ COMMITTED and whole time entries

**Status:** proposal for the owner's review. No code has changed. It comes out of #718: the guard gaps left there are symptoms of two design choices, and removing the choices removes the need for most of the guards.

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

At READ COMMITTED, every statement reads the latest committed data. A read after a lock sees what the lock protects, so this whole class of bug cannot occur.

### The mechanism

One line per server connection in `config/database.php`:

```php
'isolation_level' => 'READ COMMITTED',
```

Laravel's `MySqlConnector` runs `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` on connect, so every transaction on every path gets it. There's no need to find the outermost opener of each of the ~200 `DB::transaction` sites. SQLite has no isolation levels and is unaffected.

A transaction that truly needs one consistent view opts back in before it begins, with `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, which is allowed outside a transaction. Today that's one place, `OverpaymentCreditAuditor::partitions()`. It's a read-only scan whose docblock relies on REPEATABLE READ so that it never reports "a balance that existed at no moment". `scripts/db-snapshot.sh` uses `mariadb-dump --single-transaction` in a session of its own and is unaffected.

### What must change in the same release

1. **`PricingBasisRecorder::record` must describe what was priced.** This is the one real correctness hazard.
   - **The problem:** it re-reads the time entries and invoices when it records the basis, after the lines are composed. Generation prices from many plain queries (`CapacityLedgerInputs`, `monthlyBalances`, `BilledOverageLedger`). At READ COMMITTED, a commit between pricing and recording would put the newer state into the basis. The issue's re-check would then match the basis, and a draft priced from older state would issue.
   - **The fix:** build the basis from the inputs the pricing actually consumed, passed in from the generator rather than re-read.
   - **Alternative:** take the basis's input rows with a lock before pricing, which the agreement lock already partly does.
2. **`ManualClientEmailBudget::admit`** reads its count before the upsert and adds `current - seen` to compensate for an old snapshot. At READ COMMITTED the locked count already includes those admissions, so they'd be counted twice and refused too early. Remove the compensation and the one-admit-per-transaction rule that came with it.
3. **`TimeEntryMutationService::allocatedInvoice`** runs its plain read before its locked read. At READ COMMITTED that only causes a rare spurious 409, but taking the lock first removes it.
4. **`docs/client-management/concurrency.md`:** rewrite the sections that explain behaviour by "the snapshot follows the agreement lock". The rule becomes simpler: take the lock, then read.

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

READ COMMITTED with binary logging requires row-based or mixed logging. Nothing in the repository mentions the binlog. On web1, check:

```sql
SELECT @@log_bin, @@binlog_format;
```

If `log_bin` is on and the format is `STATEMENT`, change it to `ROW` (or `MIXED`) first.

### Rollout and rollback

- **Release 1:** the config line, the three fixes above, the auditor's opt-in and the gap-lock race tests. The snapshot checks stay. At READ COMMITTED they're dead but harmless, so a rollback to REPEATABLE READ is just reverting the line.
- **Release 2:** removes the dead checks, the table and column, and the ratchet.

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
- **"Invoiced" is derived, not stamped.** Today issue sets `status='invoiced'` per row. Instead, an entry is invoiced when its allocations on issued invoices cover all its minutes. Until then it stays approved, with the remaining minutes open.

### Decisions for the owner

1. **Dispute, approval and write-off apply to the whole entry.** Today a client can dispute one fragment. Proposed: the client reviews the entry as logged.
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
2. Re-point each fragment's allocations to the root, with `minutes` set to the fragment's minutes. Set the root's own allocations' `minutes` to the root's former minutes.
3. Soft-delete the fragments.

Families whose members diverge (different status, dispute, approval, deferral or description) can't be folded without a decision. A read-only artisan command counts them first. The owner then picks one of these per class:

- keep the root's state;
- detach the fragment as an independent entry, which nulls its lineage and leaves it whole.

Existing allocations backfill `minutes` from the entry, which today is always the whole row.

### Rollout and rollback

1. **Release 1 (no behaviour change):** add `allocation.minutes`, backfilled from each entry's minutes, and switch every reader to sum it. Because every allocation is still a whole row, the output is identical. The test is that all existing billing tests pass unchanged.
2. **Release 2:** the composer attaches partial minutes and stops splitting, and the unique index is dropped.
   - **Rollback risk:** this is the one release a rollback can't fully undo. Previous code reads any allocation as the whole row, so an entry partly allocated by Release 2 would look fully billed to it, and its remaining minutes would wait until a forward fix.
   - **Recommendation:** deploy it alone and roll forward rather than back.
3. **Release 3:** migrate existing families as above, then remove the splitter's `splitEntry`, recombination, the lineage column and its 409, and the lineage in fingerprints. Rewrite or delete the tests that pin splitting, and replace the split-family guard with the sum invariant.

## Order and what it buys

1. **Part 1, Release 1:** about a day's work, plus the binlog check.
2. **Part 1, Release 2:** mostly deletion.
3. **Part 2, Releases 1 to 3:** the larger piece. Its decisions above should be settled before Release 2.

Together they retire the snapshot ratchet and its 723 entries, the revision counters, split lineage and recombination, and the split-family guard. They also make two of #718's guard gaps unnecessary: "charged once" becomes a single sum, and the lossy allocation digest after a buy-down disappears with lineage in digests.
