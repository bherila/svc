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
  through that list, except the two inversions it names.

## What is *not* claimed here

Ordering discipline, and only that.

The fast lane runs on SQLite, which cannot exercise a genuine multi-connection
race, and the MariaDB lane runs the same single-connection tests. Nothing in the
suite makes two transactions contend, so a green conformance run is not evidence
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
| 1 | `client_proposals` | Acceptance starts from the proposal and reaches the company through it |
| 2 | `client_billing_schedules` | A schedule run starts from the schedule row and produces invoices |
| 3 | `client_agreements` | Generation serialises on the agreement, because the invoice rows it guards against may not exist yet |
| 4 | `client_invoice_payments` | Payment status and refund both start from the payment and reach the invoice through it — never the reverse |
| 5 | `payment_reconciliations` | Hangs off a payment already held |
| 6 | `client_invoices` | Every generator has an agreement or a schedule before it has an invoice |
| 7 | `workspaces` | Reached only to serialise the number counter, which is reached only once there is an invoice to number |
| 8 | `workspace_invoice_counters` | Locked immediately after the workspace that serialises it |
| 9 | `client_time_entries` | What an invoice is built out of, drawn after the invoice exists |
| 10 | `client_tasks` | Milestone claims, composed after time in every path but one |
| 11 | `client_expenses` | Drawn into an invoice the way milestones are; #75 puts the generator hook beside the milestone one. **The one row not read off a recorded multi-lock sequence** — see below |
| 12 | `client_companies` | Last, and this is the surprise — see below |
| 13 | `client_projects` | Never co-acquired with anything above |
| 14 | `users` | Never co-acquired with anything above |
| 15 | `oauth_access_tokens` | Agent disconnection, which takes no other lock; orders only against itself |
| 16 | `stripe_payment_method_states` | Provider state, a family of its own |
| 17 | `client_stripe_customers` | |
| 18 | `client_stripe_payment_methods` | |

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
ClientAgreement, ClientInvoice, Workspace,
    WorkspaceInvoiceCounter, ClientTimeEntry, ClientTask       cadence generation
StripePaymentMethodState, ClientStripeCustomer,
    ClientStripePaymentMethod                                  provider sync
```

## The two known inversions

Both are real, both are reachable, and neither is fixed here. This work
documents and enforces the order; changing an acquisition order is a
behavioural change that belongs in its own commit with its own reasoning and its
own follow-up. They are pinned as an exact set in
`LockOrderConformanceTest::KNOWN_INVERSIONS`, so they cannot multiply and fixing
one fails the test rather than silently loosening it.

**1. `client_time_entries` before `workspaces` / `workspace_invoice_counters`.**
`InterimOverageGenerator::generateInterimOverageInvoice()` recombines fragments
— which locks a lineage group of time entries — and *then* creates the invoice,
which allocates a number and so locks the workspace and its counter. Every
cadence path does the reverse: invoice and number first, time second. Two
callers running those two paths against one workspace at the same time can each
hold what the other is waiting for. This is the inversion with a real deadlock
story behind it.

**2. `client_tasks` before `client_time_entries`.** Only inside one long
transaction covering several periods, which is the shape `svc:billing:replay`
produces: it wraps its whole run in a transaction it will roll back, so every
period's locks are held together to the end. The first period claims a milestone
and finds no fragments to recombine — its own allocation is what creates them —
and the second recombines what the first left. Each generation is correctly
ordered on its own; the pair is not. This is the inversion a per-call review
cannot see, and the reason conformance is checked per transaction rather than
per call site.

## Check-then-act inventory

Every guard that reads a condition and then writes on the strength of it, with
what makes it sound. A guard with neither a lock nor a constraint is a gap, and
gets a follow-up rather than an inline fix.

| Guard | Backed by |
| --- | --- |
| `InvoiceLifecycleService::issue()` — only a draft may be issued | The invoice row lock taken by `lockInvoice()` at the top of the same transaction |
| `InvoiceLifecycleService::issue()` — overpayment credit is not spent twice | The **company** row lock, taken deliberately after the invoice: two drafts lock two different invoice rows and would both read the same unconsumed pool |
| `InvoiceLifecycleService::applyPayment()` — one payment per idempotency key | `payment_idempotency_unique` on `(workspace_id, idempotency_key)`. The constraint, not the lock, is what makes this absolute |
| `InvoiceLifecycleService::void()` / `releaseAllocations()` — released time is re-approved, not left invoiced | The invoice row lock, then the time-entry rows before they are rewritten |
| `InvoiceNumberAllocator::next()` — the next number is not handed out twice | The workspace row lock, then the counter row; and `(workspace_id, invoice_number)` unique behind both |
| `BillingScheduleService::generateDue()` — a period is not billed twice **by this schedule** | The schedule row lock, plus the application guard in `BillingPeriodCollisionResolver`. `billing_schedule_service_period_unique` **does not** carry this: a unique index does not constrain a null, so it never covered the unlinked case. Since #219/#224 the guard matches the tenant and the *overlapping* period first and reads ownership only to decide whose invoice it is — a null `client_billing_schedule_id` means *unclaimed* rather than no match, narrowed to this agreement and, for unlinked rows only, to the kinds `InvoiceKind::cycleGuardExclusions()` allows to block. Every non-null id is resolved against the invoice's own workspace and client, so lineage that dangles, crosses tenants or contradicts itself is refused rather than read as someone else's; so is a row attributable to nobody when any other agreement or active schedule could own it, and one of this schedule's own invoices that states no complete period. Serialised against itself by the lock; **not** against the other generator — see the gap below. Covered by `BillingWorkflowTest::test_an_unlinked_invoice_stops_a_schedule_billing_its_period_again`, `::test_an_invoice_owned_by_another_schedule_does_not_block_this_one`, `::test_an_ad_hoc_invoice_sharing_the_period_does_not_block_the_schedule`, `::test_another_agreements_unlinked_invoice_does_not_block_this_schedule`, `::test_an_invoice_naming_a_schedule_that_does_not_exist_is_refused`, `::test_an_invoice_naming_another_clients_schedule_is_refused`, `::test_an_invoice_naming_another_companys_agreement_is_refused`, `::test_an_invoice_whose_schedule_and_agreement_disagree_is_refused`, `::test_an_unattributed_invoice_is_refused_when_a_scheduleless_agreement_could_own_it`, `::test_an_invoice_containing_the_period_is_refused_rather_than_billed_again` and `::test_an_invoice_of_this_schedule_with_no_period_end_is_refused` |
| `ClientInvoicingService::generateMonthlyInvoiceForWorkPeriod()` — one cadence invoice per period | The agreement row lock, taken first because the invoice rows it guards against may not exist yet |
| `InterimOverageGenerator::generateInterimOverageInvoice()` — no interim after the cycle is charged, no duplicate interim draft | The agreement row lock, then the candidate invoice rows under it |
| `InterimOverageGenerator::releaseUnchargedInterimClaims()` — only an unsettled draft is stripped | Locks the drafts, then **re-reads each one and re-checks its status** before rewriting. The cadence path holds the agreement and `issue()` holds the invoice and the company, so nothing else stops an operator issuing a draft between the read and the delete |
| `AllocationService::recombineUnlinkedFragments()` — only a wholly unbilled group merges | Locks the lineage group, then validates the project chains **after** taking those locks, so a concurrent edit cannot move a fragment between the check and the destructive merge |
| `TimeEntryMutationService::update()` — an entry on a draft may be edited | Locks the company's agreements, then its invoices, then the entry — then re-verifies that the entry's allocated invoice is among the ids it locked, and refuses if the allocation moved |
| `PaymentReconciliationService::upsert()` — active allocations do not exceed the payment net of refunds | Locks the payment, the existing reconciliation, and the sibling active rows it sums, all before the write; `pr_payment_system_transaction_unique` behind it |
| `UndatedCollectibleInvoiceRepairer::repair()` — the set repaired is the set counted | Counts under the lock and refuses if the count differs from the operator's stated expectation |
| `OAuthLoginController::resolveUser()` — one account per provider subject and per email | Locks by provider subject, then by email; `users.email` unique behind it |
| `ProposalWorkflow::accept()` — a proposal is accepted once | The proposal row lock, then the company; `client_agreements.source_proposal_id` unique behind it |
| `WorkspaceExpenses::approve()` / `unapprove()` — only a status the lifecycle allows may move | The expense row lock, taken through the workspace-scoped query so the lock statement itself carries the tenant predicate; the status is then re-read from the **locked** row, never from the model the caller passed in |
| `WorkspaceExpenses::update()` — only a draft's facts may be rewritten | The same expense row lock and the same re-read. An approved expense is refused, so the amount a manager passed is the amount that is billed |
| `WorkspaceExpenses::discard()` — an invoiced expense is not withdrawn | The same lock, and `ExpenseStatus::hasBeenInvoicedValue()`, which answers yes to a status it does not recognise |
| `AgentConnectionController::destroy()` — an unrevoked connection is revoked once | The access-token row lock, taken before the refresh credential is revoked so a concurrent refresh cannot mint a replacement between the read and the write |

### Known gap: the two cadence generators do not exclude each other

Two paths can create a cadence invoice for one agreement and period, and they
lock **different rows**:

- `BillingScheduleService::generateDue()` locks the `client_billing_schedules`
  row.
- `ClientInvoicingService::generateMonthlyInvoiceForWorkPeriod()` locks the
  `client_agreements` row.

Neither lock is visible to the other, so both transactions can read "no invoice
covers this period" and both can insert. `billing_schedule_service_period_unique`
does not reject the pair either: the schedule path writes its own id and the
other writes null, so the two rows differ on the first column of the index — and
a unique index does not constrain a null in any case.

Each guard is sound against a concurrent copy of *itself*, which is what the
application guard and the row lock are for, and that is the race #219 was filed
about. This is the other one, and it is recorded here rather than fixed inline
because closing it means choosing a single lock object for both generators —
the agreement is the obvious candidate, and taking it in `generateDue()` puts a
new acquisition into `LockOrderConformanceTest`'s ordering. That belongs in its
own change with its own reproduction, per the rule above this table.


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
