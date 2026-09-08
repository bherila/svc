# Client Management — Billing and Invoicing

This page introduces the billing model and points to its implementations and
focused references. It does not define a second inventory of routes, enums or
screen controls. See [setup](setup.md) for workspace access.

## Related billing topics

- [Cadence billing and regeneration](cadence-billing.md): period placement,
  cycle reconciliation, regeneration and interim overage.
- [Milestone billing](milestone-billing.md): fixed-price task charges.
- [Payments](payments.md) and [Stripe billing](stripe-billing.md): payment records
  and the processor integration.
- [Deferred billing](deferred-billing.md): allocation of deferred work.
- [Overpayment credits](overpayment-credits.md): carrying excess payments forward.
- [Billing CLI](cli.md): inspection, generation rehearsal and audits.
- [Concurrency](concurrency.md): transaction and lock-order constraints.

## Core Concepts

### Prior-Period Billing Model

A cadence-period invoice reconciles work from a service period and can charge
for the following retainer cycle. For example, a monthly February retainer
invoice can reconcile January work. The stored invoice columns are
`service_period_start` / `service_period_end` for work and `cycle_start` /
`cycle_end` for the cycle. These dates are different from `issue_date` and
`due_date`; see [Invoice Period](cadence-billing.md#invoice-period).

[ClientInvoicingService](../../app/Services/Billing/ClientInvoicingService.php)
composes the agreement invoice and its allocation plan.
[BillingScheduleService](../../app/Services/Billing/BillingScheduleService.php)
provides the due-schedule generation path. The existence of an engine method
such as `generateAllInvoices()` does not imply a corresponding browser action;
consult [the billing routes](../../routes/billing.php) for request entry points.

### Billing Cadence and Cycle Fields

[BillingCadence](../../app/Support/Billing/BillingCadence.php) owns cadence values,
month counts and calendar boundaries.
[BillingCycleResolver](../../app/Services/Billing/BillingCycleResolver.php)
places an agreement within those cycles, including its start, end and first-cycle
proration. Do not infer cycle anchoring from a cadence label alone.

[InvoiceKind](../../app/Support/Billing/InvoiceKind.php) owns invoice kinds.
[ServicePeriodRequirement](../../app/Support/Billing/ServicePeriodRequirement.php)
combines kind and schedule ownership to decide whether issuance requires a
complete service period. This includes handling legacy null and unsupported
kind values; it is not just a list of enum cases.

### Rollover Hours

[RolloverCalculator](../../app/Services/Billing/RolloverCalculator.php) maintains
chronological monthly balances. `rollover_months = 0` expires unused capacity at
the end of its earning month. A value of `1` lets January capacity be spent in
February; `2` also allows March. Expiry uses elapsed calendar months, including
months with no remaining balance.

Cadence grouping does not replace this monthly calculation.
[InvoiceLedgerBuilder](../../app/Services/Billing/InvoiceLedgerBuilder.php)
builds the ledger, while
[BilledOverageLedger](../../app/Services/Billing/BilledOverageLedger.php) places
already-charged overage in the month it settled. A charged nonzero overage with
no service-period end cannot be placed and refuses chronological pricing,
rather than disappearing from the calculation.

#### Opening Rollover

`client_agreements.initial_rollover_minutes` stores opening capacity;
[ClientAgreement](../../app/Models/ClientAgreement.php) exposes it to the engine
in hours. `InvoiceLedgerBuilder::withOpeningRollover()` grants it in the month
before the recorded agreement start, subject to the agreement's ordinary
rollover expiry. The period-retainer ledger path returns before that grant.
See [Audit Opening Rollover](cli.md#audit-opening-rollover) for the read-only
population check; historical audit counts do not describe a current database.

### Minimum Availability Rule (Catch-up Billing)

The threshold is configurable through
`client_agreements.catch_up_threshold_minutes`; it is not always one hour.
`ClientAgreement::getCatchUpThresholdHoursAttribute()` defaults an unset value
to one hour capped at the period retainer's hours. Generation stops maintaining
a future availability buffer after termination.

The monthly catch-up path in `ClientInvoicingService` combines the allocation
plan's uncovered work with the buffer needed for the remaining capacity. For a
synthetic example with a one-hour threshold, an opening net capacity of minus
six hours needs seven catch-up hours to reach that threshold. The agreement's
actual terms and the charged-overage ledger determine the real calculation.

## Invoice Line Items

The persisted column is `client_invoice_lines.type`.
[InvoiceLineType](../../app/Support/Billing/InvoiceLineType.php) defines its known
values and the subsets used for regeneration and work-period dating. These
subsets differ: for example, a recurring charge billed in advance should not
extend the period of work being reconciled.

[InvoiceLineComposer](../../app/Services/Billing/InvoiceLineComposer.php) and
[ClientInvoicingService](../../app/Services/Billing/ClientInvoicingService.php)
compose lines; [AllocationService](../../app/Services/Billing/AllocationService.php)
records the time-entry allocation. Invoice lines may summarize hours, so an
invoice's total hours alone is not evidence that a particular entry is linked.

### Subcontractor billing

Time entries store the billing-mode and rate snapshots used for subcontractor
billing. `subcontractor_id` refers to a client-company row; there is no
`client_subcontractors` table. Inspect the scopes on
[ClientTimeEntry](../../app/Models/ClientTimeEntry.php) and the allocation/composer
paths for the distinction between retainer, flat-hourly and direct billing.
See [the overview](overview.md) for the surrounding access model.

## Invoice Balance Fields

Monetary invoice fields such as `total_amount`, `paid_amount` and
`balance_amount` are integer minor-unit amounts. Retainer balances such as
`unused_hours_balance`, `negative_hours_balance`, `starting_unused_hours` and
`starting_negative_hours` are hour quantities. The generation paths write these
snapshots; they are not interchangeable with a payment balance.

### Server serialization and invoice pages

The operator invoice page is rendered by
[ClientDirectoryController](../../app/Http/Controllers/ClientDirectoryController.php)
as `clients/invoice`; the portal invoice page is rendered by
[ClientPortalController](../../app/Http/Controllers/ClientPortalController.php)
as `portal/invoice`. Both use Inertia props with page-specific payloads.
The portal payload is narrower than the operator's and should not be inferred
from it. The controllers construct the props for their respective React pages.

[InvoiceLineDetail](../../app/Support/Billing/InvoiceLineDetail.php) supplies the
line itemization with operator and client visibility modes. Inspect those
payloads and their React pages when changing invoice display fields.

## Billing Validation and Automation

[InvoiceLifecycleService](../../app/Services/Billing/InvoiceLifecycleService.php)
controls draft issuance, voiding and payment-related state. Its issuance guard
refuses a required but incomplete service period before turning a draft into a
charge. Generating a correctly dated interim beside an incomplete draft remains
allowed: the incomplete draft has charged nobody, but it must not later issue
as a second charge.

[TimeEntryMutationService](../../app/Services/AgentApi/TimeEntryMutationService.php)
handles updates and deletion, delegating draft rebuilding to
[DraftInvoiceTimeRegenerator](../../app/Services/Billing/DraftInvoiceTimeRegenerator.php).
The mutation guards distinguish the linked invoice from other invoices covering
the proposed work date. See
[Draft Invoice Regeneration](cadence-billing.md#draft-invoice-regeneration) for
the related billing workflow; do not reduce the guards to a two-status UI list.

## Recurring Items

Recurring charges are stored in `client_agreement_recurring_items` and billed by
[RecurringItemBiller](../../app/Services/Billing/RecurringItemBiller.php). Consult
that service for incidence dates. There are no dedicated recurring-item CRUD
routes in the current route files; the predecessor's `/api/client/mgmt/...`
endpoints are not the SVC route contract.

## Agreement Transitions

There is no `AgreementTransitionService` or matching transition endpoint in the
current application. Agreement actions are declared in
[routes/engagement.php](../../routes/engagement.php); the engine's treatment of
terminated and successor agreements is implemented in
[ClientInvoicingService](../../app/Services/Billing/ClientInvoicingService.php).
A rule used during calculation does not by itself expose a workflow for changing
an existing agreement's terms.

## Payment Handling

See [Payments](payments.md), [Stripe billing](stripe-billing.md), and
[Overpayment credits](overpayment-credits.md). The current request entry points
are in [routes/billing.php](../../routes/billing.php), with agent invoice writes
separately controlled as documented in [MCP](../mcp.md).
