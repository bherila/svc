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
| 11 | `client_companies` | Last, and this is the surprise — see below |
| 12 | `client_projects` | Never co-acquired with anything above |
| 13 | `users` | Never co-acquired with anything above |
| 14 | `oauth_access_tokens` | Agent disconnection, which takes no other lock; orders only against itself |
| 15 | `stripe_payment_method_states` | Provider state, a family of its own |
| 16 | `client_stripe_customers` | |
| 17 | `client_stripe_payment_methods` | |

The company being *last* is the one entry that reads wrong and is right. It
looks like a parent, so the intuitive order puts it first; the code puts it at
the end of three separate paths. `InvoiceLifecycleService::issue()` locks the
invoice and then the company whose overpayment credit pool it is about to spend.
`ProposalWorkflow::accept()` and `AgreementWorkflow::activate()` both lock the
row they started from and then take the company as the shared serialisation
point added in #209. Writing "company first" here would have been inventing an
order rather than recording one, and every one of those three paths would then
have needed changing to match a document.

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
| `BillingScheduleService::generateDue()` — a period is not billed twice | The schedule row lock, plus `billing_schedule_service_period_unique`. **Both are defeated by a null `client_billing_schedule_id`**: the guard's `where` cannot match a null and a unique index does not constrain one either, so an unlinked invoice for the period is invisible to both. Covered by `BillingWorkflowTest::test_an_unlinked_invoice_does_not_stop_a_schedule_billing_its_period_again` |
| `ClientInvoicingService::generateMonthlyInvoiceForWorkPeriod()` — one cadence invoice per period | The agreement row lock, taken first because the invoice rows it guards against may not exist yet |
| `InterimOverageGenerator::generateInterimOverageInvoice()` — no interim after the cycle is charged, no duplicate interim draft | The agreement row lock, then the candidate invoice rows under it |
| `InterimOverageGenerator::releaseUnchargedInterimClaims()` — only an unsettled draft is stripped | Locks the drafts, then **re-reads each one and re-checks its status** before rewriting. The cadence path holds the agreement and `issue()` holds the invoice and the company, so nothing else stops an operator issuing a draft between the read and the delete |
| `AllocationService::recombineUnlinkedFragments()` — only a wholly unbilled group merges | Locks the lineage group, then validates the project chains **after** taking those locks, so a concurrent edit cannot move a fragment between the check and the destructive merge |
| `TimeEntryMutationService::update()` — an entry on a draft may be edited | Locks the company's agreements, then its invoices, then the entry — then re-verifies that the entry's allocated invoice is among the ids it locked, and refuses if the allocation moved |
| `PaymentReconciliationService::upsert()` — active allocations do not exceed the payment net of refunds | Locks the payment, the existing reconciliation, and the sibling active rows it sums, all before the write; `pr_payment_system_transaction_unique` behind it |
| `UndatedCollectibleInvoiceRepairer::repair()` — the set repaired is the set counted | Counts under the lock and refuses if the count differs from the operator's stated expectation |
| `OAuthLoginController::resolveUser()` — one account per provider subject and per email | Locks by provider subject, then by email; `users.email` unique behind it |
| `ProposalWorkflow::accept()` — a proposal is accepted once | The proposal row lock, then the company; `client_agreements.source_proposal_id` unique behind it |
| `AgentConnectionController::destroy()` — an unrevoked connection is revoked once | The access-token row lock, taken before the refresh credential is revoked so a concurrent refresh cannot mint a replacement between the read and the write |

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
