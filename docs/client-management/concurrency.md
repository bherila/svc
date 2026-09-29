# Lock order and check-then-act

Fifty-one `lockForUpdate()` call sites across twenty-one files, and until this
document nothing anywhere said what order they were meant to be taken in. That
is how locking gaps kept being found one at a time — a claim released with no lock at
all, an invoice freeze that read outside the lock it depended on, credit spend
and claim release re-verified at issue time only after a reviewer asked. Each
fix was right, and none of them told the next reviewer anything.

This page is the answer to "is this lock in the right place?", and it is
enforced rather than aspirational:

- `App\Support\Concurrency\Locks::forUpdate()` is the only way to take a
  pessimistic lock. `Tests\PHPStan\DisallowRawLockForUpdateRule` fails the
  build on a raw `lockForUpdate()` anywhere else, so the record below is
  complete by construction.
- `App\Support\Concurrency\LockResource` declares the order, one case per
  lockable table, in acquisition order.
- `Tests\Feature\Concurrency\LockOrderConformanceTest` drives the concurrent
  writers with a recorder on and refuses any transaction that walks backwards
  through that list, except the one inversion it names.

## What is *not* claimed here

Ordering discipline, and only that.

The fast lane runs on SQLite, which cannot exercise a genuine multi-connection
race, and the conformance test is also single-connection in the MariaDB lane.
A separate `LegacyReceiptConcurrencyTest` uses two processes and a disposable
MariaDB schema to observe actual lock waits for the receipt-namespace cutover.
That focused probe does not cover the other business locks: a green conformance run is not evidence
that concurrent callers are safe — it says the code takes its locks in one
consistent order, which is the precondition for safety and not the thing itself.

The registry is also granular to the *table*, not the row. "Already locked"
means some row of that table. A transaction that locks invoice A, then a time
entry, then invoice B is monotonic by this measure and is not, in fact, ordered.
Making it stricter would mean ranking rows, which no static order can do.

Where a guarantee has to be absolute, the final arbiter is a database
constraint, not a lock. That is why the check-then-act inventory below names the
constraint wherever one exists.

## The acquisition order

Read off the code rather than chosen for it: every service was instrumented, the
whole suite was run with the recorder on, and the pairs the recorded
transactions actually fix are what the list encodes. Where two paths disagreed,
the majority order won and the minority is named as an inversion below.

| # | Resource | Why it sits here |
| --- | --- | --- |
| 0 | `agent_mutation_receipts` | Agent compatibility guards and authenticated receipt reservations precede the domain callback and its business locks |
| 1 | `client_proposals` | Acceptance starts from the proposal and reaches the company through it |
| 2 | `client_billing_schedules` | A schedule run starts from the schedule row and produces invoices |
| 3 | `client_agreements` | Generation serialises on the agreement, because the invoice rows it guards against may not exist yet |
| 4 | `client_invoice_payments` | Payment status and refund both start from the payment and reach the invoice through it — never the reverse |
| 5 | `payment_reconciliations` | Hangs off a payment already held |
| 6 | `client_invoices` | Every generator has an agreement or a schedule before it has an invoice |
| 7 | `client_invoice_lines` | Issued correction locks the invoice before preserving and changing its existing lines |
| 8 | `workspaces` | Reached only to serialise the number counter, which is reached only once there is an invoice to number |
| 9 | `workspace_invoice_counters` | Locked immediately after the workspace that serialises it |
| 10 | `client_time_entries` | What an invoice is built out of, drawn after the invoice exists |
| 11 | `client_tasks` | Milestone claims, composed after time in every path but one |
| 12 | `client_expenses` | Drawn into an invoice the way milestones are; #75 puts the generator hook beside the milestone one. **The one row not read off a recorded multi-lock sequence** — see below |
| 13 | `client_companies` | Last, and this is the surprise — see below |
| 14 | `client_projects` | Never co-acquired with anything above |
| 15 | `users` | Never co-acquired with anything above |
| 16 | `client_invoice_email_deliveries` | A short post-commit client-email claim, or the result row after its invoice is locked so both sides of the result commit together |
| 17 | `client_invoice_administrator_notifications` | A short post-commit administrator-email claim; never co-acquired with billing locks |
| 18 | `oauth_access_tokens` | Agent disconnection, which takes no other lock; orders only against itself |
| 19 | `stripe_payment_method_states` | Provider state, a family of its own |
| 20 | `client_stripe_customers` | |
| 21 | `client_stripe_payment_methods` | |

The company being *last* is the one entry that reads wrong and is right. It
looks like a parent, so the intuitive order puts it first; the code puts it at
the end of three separate paths. `InvoiceLifecycleService::issue()` locks the
invoice and then the company whose overpayment credit pool it is about to spend.
`ProposalWorkflow::accept()` and `AgreementWorkflow::activate()` both lock the
row they started from and then take the company as the shared serialisation
point added in #209. Writing "company first" here would have been inventing an
order rather than recording one, and every one of those three paths would then
have needed changing to match a document.

`client_expenses` is the one entry that does **not** come from a recorded
sequence, and it is worth saying so rather than letting the table imply
otherwise. Nothing locks an expense alongside anything else yet: the approval
moves in `WorkspaceExpenses` lock the expense row and nothing more, so they
record a sequence of one, which cannot invert against anything. The position
comes from #75's own design — the expense generator hook sits beside the
milestone one — so a composer that reaches expenses reaches them after the
tasks it is written next to. The first transaction that locks an expense with
an invoice is what settles it, and if that transaction disagrees, the
conformance test fails and the case moves. That is the registry working, not
the registry being wrong.

### Sequences that fix these pairs

Recorded, not asserted from reading:

```
ClientProposal, ClientCompany                                  proposal acceptance
ClientAgreement, ClientCompany                                 agreement activation
ClientBillingSchedule, ClientInvoice, ClientCompany            schedule run, issuing
ClientInvoicePayment, ClientInvoice                            payment status, refund
ClientInvoicePayment, PaymentReconciliation                    reconciliation upsert
ClientInvoice, ClientCompany                                   issuing, credit spend
ClientInvoice, ClientCompany                                   disabling automatic delivery after locking the affected invoices
ClientInvoice, ClientInvoiceLine                               issued correction
ClientInvoice, ClientInvoiceEmailDelivery                      atomic client-delivery result persistence
ClientAgreement, ClientInvoice, Workspace,
    WorkspaceInvoiceCounter, ClientTimeEntry, ClientTask       cadence generation
ClientAgreement, ClientInvoice, Workspace,
    WorkspaceInvoiceCounter, ClientTimeEntry                   interim overage
ClientInvoiceEmailDelivery                                    post-commit client delivery claim
ClientInvoiceAdministratorNotification                        post-commit administrator notification claim
StripePaymentMethodState, ClientStripeCustomer,
    ClientStripePaymentMethod                                  provider sync
```

## The known inversions

Two were recorded when the registry was written. Both were real and both were
reachable; the first is fixed and kept here rather than deleted, because the
next reader's question is "was this ever the other way round, and why", and a
deleted entry answers it with silence. What remains is pinned as an exact set in
`LockOrderConformanceTest::KNOWN_INVERSIONS`, so it cannot multiply and fixing it
fails the test rather than silently loosening it.

**1. `client_time_entries` before `workspaces` / `workspace_invoice_counters` —
fixed in #222.** `InterimOverageGenerator::generateInterimOverageInvoice()`
recombined fragments — which locks a lineage group of time entries — and *then*
created the invoice, which allocates a number and so locks the workspace and its
counter. Every cadence path does the reverse: invoice and number first, time
second. A cadence generation holding the counter and reaching for that client's
time was holding exactly what an interim run was waiting for, and waiting for
exactly what it held.

The recombination could not simply move after the create, because whether an
invoice is created at all depends on the hours the recombined entries carry: the
merge has to happen before the decision. So the generator takes the two
numbering rows first instead, through `InvoiceNumberAllocator::lockNumbering()`,
and allocates the number at the create as before. The rows are held to commit
whenever they are acquired, so nothing is held longer than it was; what changed
is that a run which then finds nothing to bill has serialised numbering for the
workspace for the rest of its transaction without consuming a number. That is
the price of one order for everybody. The invoice this path produces is
unchanged — `CapacityAndScopeGuardsTest`'s interim cases pass untouched — and
`::test_an_interim_month_with_no_time_of_its_own_allocates_no_invoice_number`
pins the distinction the fix rests on: the counter is *locked* early and the
number is still drawn at the create, so a month that turns out to have nothing
to bill leaves no gap in the client's invoice sequence.

Bending the registry so `client_time_entries` outranked numbering was the
alternative and it was rejected twice: once when the registry was written and
again here. It would have made the exception invisible and left the cadence
paths — the majority, and the request paths — walking backwards instead.

**2. `client_tasks` before `client_time_entries` — open, see #223.** Only inside
one long transaction covering several periods, which is the shape
`svc:billing:replay` and `svc:billing:rehearse-generation` produce: each wraps its whole run in a transaction it will
roll back, so every period's locks are held together to the end. The first
period claims a milestone and finds no fragments to recombine — its own
allocation is what creates them — and the second recombines what the first left.
Each generation is correctly ordered on its own; the pair is not. This is the
inversion a per-call review cannot see, and the reason conformance is checked per
transaction rather than per call site.

The decision on it is recorded on #223 and neither of the two obvious fixes is
it. Narrowing the replay transaction so each period releases its locks means
ending the transaction each period, and both ways of ending it are closed:
committing writes the deletions and regenerated invoices that command exists to
never write, and rolling back per period discards the invoices the comparison is
about. Claiming the milestone after the first period's time-entry work is
already what the code does — the first period locks no entry because the only
time-entry lock a generation takes is inside fragment recombination, which locks
a lineage group only when one exists, and the first period's own allocation is
what creates them. Making that lock unconditional would widen the locking
footprint of a request path to buy monotonicity against a race the agreement row
lock already serialises, and would report a lock that was recorded rather than a
row that was held. The pair is left listed, and the operational rule is that
neither `svc:billing:replay` nor `svc:billing:rehearse-generation` is run against
a workspace that is generating invoices. Rehearsal does not clear history first,
but it still calls `generateAllInvoices()` across periods inside one outer
rollback-only transaction. Its successful output proves its stated comparison,
not safety against a concurrent writer.

The real replay also acquires implicit UPDATE/DELETE locks while clearing billing
rows before regeneration. The conformance fixture drives generation without that
clearing phase, so fixing its recorded inversion alone would not prove replay
safe. Keep #223 deferred until an explicit maintenance design provides either an
isolated database or bounded exclusion honored by competing writers, with
command-level MariaDB tests covering concurrency and rollback. Existing receipt
probes already provide multi-process test infrastructure; the missing work is the
maintenance protocol and its specific proof, not a new testing platform.

### Time-mutation snapshot validation

A row lock does not refresh ordinary relationship reads under `REPEATABLE READ`.
`TimeEntryMutationService` keeps the invoice models returned by its locking read,
then locks the time entry and its tenant-owned allocation pivots. It compares the
current pivot line IDs with the transaction snapshot before interpreting that
snapshot's invoice relationships. A difference returns 409 without changing the
entry: the caller must read again and retry. An invoice discovered after the
invoice-lock phase is also refused rather than acquired out of order.

The pivot resource `client_invoice_line_time_entries` follows `client_time_entries`
in the registry. This adds a bounded lock on one entry's allocations, not locks
on all unbilled time. `TimeMutationConcurrencyTest` drives a separate invoice
writer after the initial probe, including a caller with an existing transaction
snapshot, and checks committed billing state from a fresh connection. It also
covers issuance winning that race. A failed draft regeneration restores approval,
pricing and allocation together.

## Check-then-act inventory

Every guard that reads a condition and then writes on the strength of it, with
what makes it sound. A guard with neither a lock nor a constraint is a gap, and
gets a follow-up rather than an inline fix.

| Guard | Backed by |
| --- | --- |
| `InvoiceLifecycleService::issue()` — only a draft may be issued | The invoice row lock taken by `lockInvoice()` at the top of the same transaction |
| `InvoiceLifecycleService::issue()` — a row that claims a span states one, and its `invoice_kind` is readable | The same invoice row lock. The period, kind and interval-direction checks read the **locked** row and run before `$issueDate` and every mutation, so a concurrent `updateDraft()` cannot slip a boundary out from under them and a refusal leaves the draft byte-identical. Not backed by a constraint: both boundaries are nullable by design (#73) and stay that way, because an incomplete draft has charged nobody and must remain creatable. Covered by `UndatedPeriodIssueRefusalTest` |
| `InvoiceLifecycleService::refreshStatus()` — a status nobody can read is neither rewritten nor valued at zero | The invoice row lock its three callers already hold, plus the payment row lock in `setPaymentStatus()` / `setRefundedAmount()`. It refuses a draft invoice, an unrecognised invoice status, and any payment row whose status is outside `InvoicePaymentStatus`; all three throw inside the payment transaction, so the insert or update rolls back. The repair path stays open because `setPaymentStatus()` writes its recognised replacement *before* recomputing. Covered by `PaymentStatusVocabularyTest` and `UndatedPeriodIssueRefusalTest` |
| `InvoiceLifecycleService::issue()` — an interim claim never charges more than the cycle's cumulative excess | The **agreement** row lock, taken before the invoice from the caller's copy and checked against the locked invoice, then a locking read of the cycle's charged interim claims before the company — see [Issuing an interim overage claim](#issuing-an-interim-overage-claim). Refuses rather than recomputing the reviewed amount. Covered by `InterimClaimValidityTest` and `InterimClaimConcurrencyTest` |
| `InvoiceLifecycleService::issue()` — overpayment credit is not spent twice | The **company** row lock, taken straight after the invoice lock and before `issue()`'s first ordinary read, plus the `credit_revision` check in `OverpaymentCreditService::lockForSpending()` — see [Spending overpayment credit](#spending-overpayment-credit). The lock alone was not enough: the ledger is built from ordinary reads, and a transaction whose snapshot predated a competing spend could wait for the lock and still read the credit as unspent. Covered by `CreditSpendConcurrencyTest` and, sequentially, `BillingTenantIsolationTest::test_two_drafts_cannot_both_spend_the_same_credit` |
| `InvoiceLifecycleService::applyPayment()` — one payment per idempotency key | `payment_idempotency_unique` on `(workspace_id, idempotency_key)`. The constraint, not the lock, is what makes this absolute |
| `InvoiceLifecycleService::applyPayment()` — payment does not overtake an automatic provider call | The invoice lock, then the automatic delivery claim lock. A claim newer than one hour refuses payment; an older ambiguous claim remains as audit evidence while the invoice workflow is cancelled and payment may proceed |
| `InvoiceEmailService::record()` — one client delivery per workspace/idempotency key | `cied_idempotency_unique` on `(workspace_id, idempotency_key)`. Cross-invoice insert collisions reload the winner and return the same result or a bounded domain conflict; `InvoiceDeliveryConcurrencyTest` forces both MariaDB processes past the initial empty lookup before either insert |
| `InvoiceDeliveryStatusService::record()` — a later provider event never overwrites a more severe one | The delivery row lock, and the severity comparison reads the status from the **locked** row. The provider retries and batches independently, so a hard bounce and a late `delivered` for one message can arrive together; read unlocked, both saw no status and the second write won. Covered by `InvoiceDeliveryStatusConcurrencyTest` (two MariaDB processes, the bounce held after its comparison) |
| `InvoiceLifecycleService::issue()` — a monthly invoice is not issued on earlier catch-up that has moved since it was generated | The invoice row lock, then `DraftCatchUpDependencies::assertSizedAgainstCurrentCharges()`: a **locking** read of the earlier invoices its `catch_up_basis` window covers, straight after the invoice's own lock and before the credit lines, so it keeps the acquisition order and fixes no snapshot. A locking read returns the current row and waits for a generation still writing one, so an agent transaction whose snapshot predates the lock cannot pass on an old figure. Covered by `EarlierDraftCatchUpTest`. Accepted, not fixed: an earlier draft that billed nothing when the later invoice was issued and is regenerated afterwards (see [billing.md](billing.md#minimum-availability-rule-catch-up-billing)) |
| `InvoiceLifecycleService::void()` — an issued invoice a live later invoice was sized against is not voided | The **agreement** row lock for any invoice with an agreement, taken from the caller's copy before the invoice and checked against the locked row, which monthly generation holds while it records its basis; then a locking read of the agreement's live invoices whose `catch_up_basis` names this one. Covered by `EarlierDraftCatchUpTest` |
| `InvoiceLifecycleService::void()` / `releaseAllocations()` — released time is re-approved, not left invoiced | The invoice row lock, then the time-entry rows before they are rewritten |
| `InvoiceNumberAllocator::next()` / `forIssueMonth()` — the next number is not handed out twice | The workspace row lock, then the counter row; and `(workspace_id, invoice_number)` unique behind both. `forIssueMonth()` reads the highest `PREFIX-YYYYMM-NNN` sequence under the same two locks, so two cadence runs in one workspace are serialised before either reads it |
| `BillingScheduleService::generateDue()` — a period is not billed twice, by this schedule or by the agreement's own cadence path | The schedule row lock, then the agreement row lock, plus the application guard in `BillingPeriodCollisionResolver`. `billing_schedule_service_period_unique` **does not** carry this: a unique index does not constrain a null, so it never covered the unlinked case. Since #219/#224 the guard matches the tenant and the *overlapping* period first and reads ownership only to decide whose invoice it is — a null `client_billing_schedule_id` means *unclaimed* rather than no match, narrowed to this agreement and, for unlinked rows only, to the kinds `InvoiceKind::cycleGuardExclusions()` allows to block. Every non-null id is resolved against the invoice's own workspace and client, so lineage that dangles, crosses tenants or contradicts itself is refused rather than read as someone else's; so is a row attributable to nobody when any other agreement or active schedule could own it, one of this schedule's own invoices that states no complete period, and one carrying a status no enum case matches (unknown statuses fail closed, matching `InvoiceStatus::isSettledValue()`). Complete and incomplete periods are fetched by one query and classified by one set of ownership rules — a missing boundary reads as unbounded in that direction, and only candidates that could overlap *this* period are considered at all. A **known** void clears before any of that unless it covers the period exactly, so voiding stays the documented way out. Serialised against itself by the schedule lock, and against the other cadence generator by the **agreement** row lock it takes next — see below. Covered by `BillingWorkflowTest::test_an_unlinked_invoice_stops_a_schedule_billing_its_period_again`, `::test_an_invoice_owned_by_another_schedule_does_not_block_this_one`, `::test_an_ad_hoc_invoice_sharing_the_period_does_not_block_the_schedule`, `::test_another_agreements_unlinked_invoice_does_not_block_this_schedule`, `::test_an_invoice_naming_a_schedule_that_does_not_exist_is_refused`, `::test_an_invoice_naming_another_clients_schedule_is_refused`, `::test_an_invoice_naming_another_companys_agreement_is_refused`, `::test_an_invoice_whose_schedule_and_agreement_disagree_is_refused`, `::test_an_unattributed_invoice_is_refused_when_a_scheduleless_agreement_could_own_it`, `::test_an_invoice_containing_the_period_is_refused_rather_than_billed_again` and `::test_an_invoice_of_this_schedule_with_no_period_end_is_refused`, `::test_an_unrecognised_status_refuses_rather_than_clearing`, `::test_a_voided_overlap_clears_even_with_dangling_lineage`, `::test_a_periodless_invoice_that_cannot_reach_this_period_does_not_halt_it` and `::test_consecutive_periods_are_adjacent_for_every_cadence_and_awkward_start` (the adjacency the overlap refusal rests on). `::test_a_pending_draft_for_the_period_neither_bills_it_nor_advances_the_schedule` (a draft has claimed the period without billing it, so the schedule stops rather than advancing past it). `ScheduleGenerationPreflightTest::assertPredictionMatchesTheRun()` asserts the pre-deployment preflight and this run agree in both directions |
| `ClientInvoicingService::generateMonthlyInvoiceForWorkPeriod()` — one cadence invoice per period | The agreement row lock, taken first because the invoice rows it guards against may not exist yet |
| `InterimOverageGenerator::generateInterimOverageInvoice()` — no interim after the cycle is charged, no duplicate interim draft | The agreement row lock, then the candidate invoice rows under it. On the create path it then takes the numbering rows through `InvoiceNumberAllocator::lockNumbering()` **before** recombining fragments, so this path reaches `client_time_entries` after `workspaces` and `workspace_invoice_counters` like every cadence path (#222) |
| `InterimOverageGenerator::releaseUnchargedInterimClaims()` — only an unsettled draft is stripped | Locks the drafts, then **re-reads each one and re-checks its status** before rewriting. The cadence path holds the agreement and `issue()` holds the invoice and the company, so nothing else stops an operator issuing a draft between the read and the delete |
| `AllocationService::recombineUnlinkedFragments()` — only a wholly unbilled group merges | Locks the lineage group, then validates the project chains **after** taking those locks, so a concurrent edit cannot move a fragment between the check and the destructive merge |
| `TimeEntryMutationService::update()` — an entry on a draft may be edited | Locks the company's agreements, then its invoices, then the entry — then re-verifies that the entry's allocated invoice is among the ids it locked, and refuses if the allocation moved |
| `InvoiceFromTimeService::addTime()` — an existing ad-hoc draft gains only unallocated time | Locks the target invoice, verifies its version and draft kind, then locks selected entries in ID order and reads their allocation pivots with a current locking read. Existing lines are retained; totals increment from the locked invoice state. A two-process MariaDB test covers an allocation committed after an older transaction snapshot |
| `TimeEntryMutationService::unapprove()` — billed time keeps its approval | The same agreement, invoice, entry and allocation-pivot locks as `update()`. Snapshot pivot membership must match the current read, and invoice status comes from the locked invoice model, so an `issue()` that commits first leaves the entry `invoiced` (refused) and one that waits finds it already returned to draft and the draft rebuilt without it |
| `PaymentReconciliationService::upsert()` — active allocations do not exceed the payment net of refunds | Locks the payment, the existing reconciliation, and the sibling active rows it sums, all before the write; `pr_payment_system_transaction_unique` behind it |
| `UndatedCollectibleInvoiceRepairer::repair()` — the set repaired is the set counted | Counts under the lock and refuses if the count differs from the operator's stated expectation |
| `OAuthLoginController::resolveUser()` — one account per provider subject and per email | Locks by provider subject, then by email; `users.email` unique behind it |
| `ProposalWorkflow::accept()` — a proposal is accepted once | The proposal row lock, then the company; `client_agreements.source_proposal_id` unique behind it |
| `WorkspaceExpenses::approve()` / `unapprove()` — only a status the lifecycle allows may move | The expense row lock, taken through the workspace-scoped query so the lock statement itself carries the tenant predicate; the status is then re-read from the **locked** row, never from the model the caller passed in |
| `WorkspaceExpenses::update()` — only a draft's facts may be rewritten | The same expense row lock and the same re-read. An approved expense is refused, so the amount a manager passed is the amount that is billed |
| `WorkspaceExpenses::discard()` — an invoiced expense is not withdrawn | The same lock, and `ExpenseStatus::hasBeenInvoicedValue()`, which answers yes to a status it does not recognise |
| `AgentConnectionController::destroy()` — an unrevoked connection is revoked once | The access-token row lock, taken before the refresh credential is revoked so a concurrent refresh cannot mint a replacement between the read and the write |

### The two cadence generators exclude each other on the agreement

Two paths can create a cadence invoice for one agreement and period:

- `BillingScheduleService::generateDue()`
- `ClientInvoicingService::generateMonthlyInvoiceForWorkPeriod()` and the
  non-monthly cadence path

They used to lock **different rows** — the schedule and the agreement — so
neither lock was visible to the other, both transactions could read "no invoice
covers this period", and both could insert. `billing_schedule_service_period_unique`
does not reject the pair: the schedule path writes its own id and the other
writes null, and a unique index does not constrain a null in any case.

`generateDue()` now takes the agreement row lock immediately after the schedule
lock and before anything reads a period, so the two generators serialise on one
row and the second reads what the first wrote. The registry already ranks
`client_billing_schedules` before `client_agreements`, so this is an acquisition
in order rather than a new pair, and `LockOrderConformanceTest` records it.
`CadenceGeneratorConcurrencyTest` runs the two generators in separate MariaDB
processes, each held after its guard and before its insert, in both orders:
before the change both reached the insert and August was billed twice; now the
second waits on the agreement and finds the first one's invoice.

## Spending overpayment credit

`issue()` spends credit: it caps the draft's credit line at what the company's
pool still holds, then issues. The pool is derived, not stored — settled money
over each live invoice's total, less the credit lines on charged invoices — and
it is derived with ordinary reads. Under REPEATABLE READ an ordinary read returns
the snapshot fixed by the transaction's *first* ordinary read, and taking a lock
later does not refresh it. So before this section existed, the company lock
serialised spenders without making them see each other: a transaction whose
snapshot predated a competing spend waited for the lock, got it, read the credit
as unspent, and spent it again. `CreditSpendConcurrencyTest` reproduced that on
MariaDB 10.11 (`innodb_snapshot_isolation=OFF`) in both shapes below — 100.00 of
credit consumed twice — before the change.

**What serialises consumption.** The company row lock, as before. It ranks after
invoices and payments, so it is taken after them: `issue()` takes it straight after
the invoice lock; the payment writers after the payment, the invoice and the
reconciliation rows they lock. Nothing locks an invoice or a payment after the
company, and no lock is added beneath it.

**Which reads are authoritative.** The locked invoice and the locked company row
(a locking read is a current read). The ledger's ordinary reads are trusted only
when `lockForSpending()` has shown the snapshot is not older than the last pool
change: it reads `client_companies.credit_revision` once through the lock and
once through the snapshot, and refuses with `CreditPoolChanged` when they differ.
The revision is compared only when the draft carries credit to spend — known from a locking read of its own credit lines, taken after the invoice and before the company — so a draft spending nothing is never refused because another invoice of the company moved the pool. That refusal is the only defined outcome for a stale snapshot — it cannot be
refreshed from inside the transaction — and it writes nothing, so the draft is
exactly as reviewed and no delivery or notification is registered. A retry in a
fresh transaction is always safe.

Two shapes of stale snapshot, and what each now does:

- **`issue()` owns its transaction.** The company is locked before its first
  ordinary read, so the snapshot starts after any competitor has committed. The
  revision check passes by construction and the existing cap applies unchanged.
- **The caller's transaction already read something** — `generateDue()` plans
  inside its transaction and then issues, and any caller can do the same. The
  snapshot predates `issue()`; if a pool change committed after it, the issue
  refuses. Before, it spent credit the snapshot still showed.

**Which writers participate.** Every writer that can *shrink* available credit
advances `credit_revision` through `recordPoolChange()`, in the same transaction
as its change and under the company lock:

| Writer | Pool effect | Participates |
| --- | --- | --- |
| `InvoiceLifecycleService::issue()` | Spends credit when it issues with a credit line | Yes, when a credit line survives the cap |
| `InvoiceLifecycleService::setPaymentStatus()` (and the Stripe webhook, which calls it) | A payment leaving `succeeded` shrinks funding; entering it cannot create credit, because the total is capped | Yes, on every status change |
| `InvoiceLifecycleService::setRefundedAmount()` (and the Stripe webhook) | A larger refund shrinks funding | Yes, on every change |
| `InvoiceLifecycleService::applyPayment()` | A succeeded payment is capped at the balance, so it cannot create or remove credit | No |
| `InvoiceLifecycleService::void()` | Releases credit a charged invoice spent (grows the pool); a paid invoice cannot be voided, so no funding is removed | No — growth only |
| `InvoiceCorrectionService::correct()` | Credit lines are not correctable, and only an issued invoice with no payment attempt can be corrected, so neither side moves | No |
| Draft regeneration (`applyCreditsToDraftInvoice()`) | Drafts do not consume | No |

A writer that only *grows* the pool need not participate: a stale snapshot can
then only under-state the credit, and the cap spends less than it could — the
remainder stays in the pool for the next invoice, which is the cap's existing
behaviour. The claim is therefore exactly this: **no participating shrink can be
missed by a spend**. A new writer that can shrink the pool must call
`recordPoolChange()`, or that claim no longer holds.

**Credit sources today.** `applyPayment()` and `setPaymentStatus()` both cap a
succeeded payment at the invoice, so the application cannot create new
overpayment credit. The credit a pool holds is overpayment carried in from
imported history, which is why the tests seed it as rows.

**Auditing a pool.** `svc:billing:audit-overpayment-credit` compares funded with
consumed per workspace, company and currency in integer minor units. The ledger
clamps what remains at zero, so "no credit available" says nothing about whether
credit was ever spent twice; the audit asks directly. A deficit is never offset
by another pool's surplus, and a pool holding data the ledger cannot read is
reported as unevaluable, with reasons, rather than as zero. A deficit is a
current state to investigate, not proof of its cause, and the command repairs
nothing.

## Issuing an interim overage claim

An interim overage draft bills `cumulative excess through its month - interim
hours already charged before it`. A draft is not a charge, so two drafts
generated before either is issued each claim the overage the other covers.
`InterimClaimValidityTest` reproduced it on the legacy monthly-terms branch
(quarterly, interim billing, 10 retainer hours a month, 15 worked in each of
January and February): the January draft claimed 5 hours, the February draft
10, both issued in either order, and 15 hours were charged against 10 of
excess. The cycle's closing invoice then recorded the 15 as "already billed" in
a zero-value reconciliation line and corrected nothing.
`InterimClaimConcurrencyTest` reproduced the same outcome with the two issues in
separate MariaDB processes.

**The invariant**, per agreement cycle: at the end of every month before the
closing one, the hours on charged interim invoices ending on or before that month
never exceed the cumulative excess through that month. `issue()` refuses a draft
that would break it, through `InterimOverageGenerator::assertClaimIssuable()`.
Cumulative excess never decreases through a cycle, so it is checked at the
draft's month end and at the end of every charged claim that ends later.

**Out-of-order issuance.** When the check fails, it is repeated at the figure a
regeneration would target now. If that passes, the draft is stale — a claim
before it was charged after it was generated — and the refusal
(`InterimClaimRefused`, `regenerate: true`) says to regenerate it. If it fails
too, a later month's claim has already charged this overage, and no regeneration
of this month changes that: the refusal (`regenerate: false`) names the later
invoice and says to discard the draft or close the cycle, which releases
uncharged interim drafts. The reviewed amount is never rewritten at issue, and
drafts are still not counted as charged, so an abandoned draft cannot cause
underbilling. A draft that is stale in the other direction — its claim is now
*smaller* than the cycle could bear, for example after an earlier interim was
voided — is not refused: it cannot overcharge, and the cycle's closing invoice
bills the remaining overage.

**Serialisation and lock order.** Two interim issues of one agreement must not
be checked at once. `issue()` locks the agreement first — from the caller's copy
of the invoice, because nothing may be read or locked ahead of it — then the
invoice, and refuses if the locked invoice names a different agreement. The
cycle's charged interim claims are then locked (`lockCycleClaims()`, a current
read, so a claim voided or charged a moment ago is seen as it is), then the
company, and only then is the ledger read. The order is agreement → invoice →
cycle claims → company, which the registry already declares; nothing is locked
inside an invoice-locked body that ranks before invoices.

**What is authoritative.** The charged claims, through the locking read, and
the time the ledger is built from, through a fingerprint. `lockCycleClaims()`
fingerprints the company's time entries in the agreement's scope (its project,
when it names one) from the agreement's first month to the cycle's end — every column the ledger's arithmetic reads, soft-deleted rows
included — with a locking read (time entries rank after invoices and before the
company); `assertClaimIssuable()` fingerprints the same rows with an ordinary
read after every lock. When `issue()` owns its transaction the two agree by
construction. A caller whose transaction already read something — the agent
API's `invoices.issue` runs inside its receipt transaction — may see time
through a snapshot older than a committed change, and then the fingerprints
differ and the issue is refused with the retryable `InterimLedgerChanged`,
writing nothing (`InterimClaimConcurrencyTest::test_a_stale_time_snapshot_cannot_approve_an_interim_claim`
cut January's time after such a snapshot; before this check February's 10-hour
claim issued against 5 hours of excess). A time entry inserted into the range
between the two reads can also make them differ; that refusal is conservative
and a retry resolves it.

**Unreadable statuses fail closed.** An interim invoice of the cycle whose status is not one the application recognises may have charged the client, so it is not left out of the claims: the draft is refused until that status is classified, as `InvoiceStatus::hasChargedValue()` treats an unknown value everywhere else.

**Scope.** Only drafts with an agreement and a complete, ordered period are
checked; the period checks refuse the rest with their own repair advice,
unchanged. The native period-retainer branch caps each month's claim by that
month's own hours against a pool that already includes earlier months, so it
does not produce the overcharge in the reproduced shape; it is covered by the
same tests as regression coverage and must keep issuing both drafts.

## Adding a lock

1. Write `->tap(Locks::forUpdate())` where you would have written
   `->lockForUpdate()`. It goes in the same chain and returns the same builder.
2. If the table has no `LockResource` case, add one **in the position the
   acquisition order puts it** — not at the end. An unregistered table is
   refused at runtime rather than silently unranked.
3. Run `LockOrderConformanceTest`. If it reports a new inversion, the lock is in
   the wrong place, or the registry is, and the failure names which pair
   disagrees. Deciding it is the registry means moving the case *and* saying why
   here.

The table a lock is filed under is read off the query's own `from`, for a model
builder as much as a plain one — never off `$query->getModel()->getTable()`.
They agree for every chain in this application, and they are still not the same
fact: `for update` locks the rows the statement selects, so a builder repointed
at another table locks that table, and filing it under the model would record a
lock on rows nobody held. So a lock on a table with no case is refused even when
the model in the chain has one.
