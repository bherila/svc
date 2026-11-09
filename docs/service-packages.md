# Service packages: self-serve sign-up, add-ons and cancellation

Status: **design, not built.** Decisions below were settled with the owner on
2026-11-09. Mockups live in [`ux-mockups/`](ux-mockups/README.md) under
"Service packages". The MCP and REST surface is at the end of this document. Every name, amount and address in them is made up.

## What it is

An owner or admin defines **packages**: fixed bundles of agreement terms, such
as "Web hosting · Essentials" or "Hosting + Care plan" (hosting plus a
retainer). Each package has its own public page and link, and can also be
listed on a workspace catalog page.

- **New clients** request a package from its page. An owner or admin approves
  the request, which creates the client and invites the contact. The contact
  then signs in, accepts the agreement and pays.
- **Existing clients** add packages from the portal's new **Services** tab,
  and request or schedule cancellation there.
- **Owners and admins** can move a package's subscribers to a new version of
  its terms, after notice.

## Settled decisions

| Area | Decision |
| --- | --- |
| Shape | Fixed bundles; no client-selectable add-ons. "Hosting" and "Hosting + retainer" are two packages. |
| Components | Recurring items (e.g. hosting), an optional hourly rate, an optional retainer (amount, minutes, rollover), and an optional one-time setup fee. All use the agreement and recurring-item fields that exist today. |
| Admission of a new person | **Request, then invite.** The public form records a request only. An owner or admin approves it, which creates the client company and the project and sends the existing portal invitation (`PortalInvitations`) as the approver. Nothing is admitted automatically, so the provider's per-actor invitation limits are never exposed to the public. |
| What approval covers | **The client, not the agreement.** After sign-in the client accepts and pays; the agreement then follows the package's own activation rule. |
| Existing clients | Add a package from the portal with no request step: review, sign, pay, then the package's activation rule. |
| Who at the client may add or cancel | Portal contacts with **company-wide** access, the same people who manage cards and autopay today. Project-limited contacts see their services, read-only. |
| Projects | Every subscription gets **its own client project**, with its agreement attached to that project. This satisfies today's one-active-agreement-per-company-and-project rule without changing it, and gives labor packages a project to log time against. |
| Activation | Per package: **start automatically** or **I review first**. |
| Up-front payment | Per package, any combination of: a **setup fee**, the **first period in advance**, a **saved card with autopay** (at a limit the package sets and the client authorizes as worded today), and **start only once paid**. Stripe only. |
| Payment processor | **Stripe only.** SVC only reads Square (`SquareEndpoint` allows GETs and searches), so a package that takes money requires a Stripe connection to publish. |
| Recurring invoices | Per package: **issue and charge automatically** (a new scheduled job) or **draft for review**. Auto-issue is allowed only for fixed-price packages. A package with an hourly rate or retainer overage always drafts. |
| Intake | Contact details (company, name, email, optional phone) plus **questions defined per package** (short text or paragraph, optionally required). |
| Anti-spam | Hidden honeypot field, a minimum fill time, per-IP and per-package throttles, and a per-address cap on open requests. No third-party script. |
| Public pages | A per-package link, plus a **catalog** page for packages marked listed. The pages show the workspace name and an uploaded logo, served by SVC. |
| Cancellation terms | Every **agreement** can carry them (packages pre-fill them): a notice period, an optional minimum term in months, and portal mode **none**, **request** (owner/admin approves) or **self-serve** (ends automatically). |
| End date | The **close of the billing period** in which the notice period runs out, and never before the minimum term ends. Paid periods are never cut short. The server offers only legal end dates. |
| Editing a package | Publishing creates a new **version**. Each agreement records the version it came from and keeps its own copy of the terms. |
| Moving subscribers | **Notice, then automatic.** The owner/admin picks subscribers and an effective date at the start of a billing period, after every selected client's notice period. Clients get an email and a portal notice, and the new terms apply on that date unless they cancel first. |
| Email | Owners/admins: a new request; a cancellation requested or scheduled. Clients: service activated; cancellation accepted (with its end date); request declined (to the visitor). |

### Defaults the owner accepted

- **Already signed in:** a visitor with an account still submits a request.
  Approval grants access directly, with no provider invitation.
- **Lapsing:** an accepted package that requires payment and stays unpaid
  lapses after 14 days (set per package). Its setup invoice is voided, and an
  owner/admin can offer the package again.
- **Tax:** recurring items keep their existing taxable flag.
- **Feature flag:** `SVC_SERVICE_PACKAGES_ENABLED`, off by default. Public
  requests from new people also need `SVC_PORTAL_INVITATIONS_ENABLED`.
- **Bug fix:** `AgreementWorkflow::terminate()` will write `terminated_at`.
  The screens and revenue projection already read it, but nothing has ever
  written it.

## Flows

### New client

    public page ── request ──▶ Needs you ── approve ──▶ company + project + draft agreement
                                         └─ decline ──▶ email to visitor
    portal invitation ──▶ sign-in ──▶ review & sign ──▶ pay (if required)
        ──▶ activate automatically, or Needs you ── activate
        └─ unpaid past the lapse date ──▶ lapsed; setup invoice voided

The agreement is created as `draft` when the request is approved. It is
signed at acceptance, and activated through the existing
`AgreementWorkflow::activate()`. That call is made either by the payment
webhook, by the acceptance itself, or by the owner/admin, according to the
package's activation and payment rules. No new agreement status is needed: an
**enrollment** row records why a draft is waiting (see the data model below).

### Existing client adds a package

Portal Services ▸ Review and add… ▸ sign ▸ pay ▸ the same activation rule.
SVC creates the project and the draft agreement when the client accepts, not
when they open the page.

### Cancellation

The client picks an end date from the dates the server offers.

- **Self-serve mode:** the end is scheduled straight away. The agreement stays
  active with `ends_on` set, and the client sees "Ends Dec 31" with an undo
  control.
- **Request mode:** a cancellation request sits in Needs you. The owner/admin
  approves it (which schedules the end on the date they choose) or declines
  it. The client can withdraw a pending request.

A scheduled end can be undone until its date (EDGE-9). On the date, a
scheduled command runs `terminate()`. Billing then follows today's
termination rules: deferred work is collected, and rollover is forfeited the
month after. Because the end falls at a period close, there is no partial
final period.

### Moving subscribers to a new version

The owner/admin selects subscribers, sets an effective date and adds a
message. Each client gets an email and a portal notice. On the effective date,
a scheduled step applies the new terms through an audited, versioned update of
the agreement and its recurring items, skipping any client that has cancelled.
An autopay limit below the new price is flagged and never raised.

## Data model sketch

All tables are tenant-owned. Each needs: `workspace_id`, `public_id`,
composite tenant keys, a `TenantReferenceInventory` entry, `LockResource`
placement, and isolation tests (see
[tenant foreign keys](client-management/tenant-foreign-keys.md) and
[concurrency](client-management/concurrency.md)).

- `service_packages`: link name, status (`draft`, `published`, `retired`),
  `is_listed`, `portal_addable`, the current published version.
- `service_package_versions`: immutable once published. Name, short
  description, Markdown body, agreement text, currency, cadence, hourly rate,
  retainer terms, setup fee, payment rules (first period in advance, autopay
  required and its limit, pay before activation, lapse days), activation mode,
  invoice issuing mode, cancellation terms, and intake questions.
- `service_package_components`: per version. Description, kind (recurring,
  setup fee), cadence, amount, quantity, taxable, order.
- `service_package_requests`: version, company name, contact name, email,
  phone, answers, status (`new`, `approved`, `declined`, `withdrawn`), the
  resulting company and portal invitation, who decided, a reason, and a
  hashed client fingerprint for throttling.
- `service_package_enrollments`: company, project, agreement, version, status
  (`offered`, `accepted`, `awaiting_payment`, `awaiting_review`, `active`,
  `lapsed`, `declined`), signer, acceptance time, setup invoice, and autopay
  consent.
- `client_agreements` gains: `service_package_version_id`, `notice_days`,
  `minimum_term_months`, `cancellation_mode` (`none`, `request`,
  `self_serve`). `terminated_at` is now written.
- `agreement_cancellation_requests`: requested by, requested end date, reason,
  status (`pending`, `approved`, `declined`, `withdrawn`), who decided, the
  decided end date, a note.
- `agreement_scheduled_terminations`: agreement, end date, the previous
  `ends_on`, the source (self-serve, an approved request, or an owner/admin),
  status (`scheduled`, `undone`, `terminated`), who scheduled it, and who
  undid it. At most one is `scheduled` per agreement.
- `service_package_migrations` and their targets: from/to version, effective
  date, message, status, and per-agreement outcome.
- Workspace public profile: the catalog token and the logo, as a private
  attachment served by SVC.

## Requirements

Each requirement is meant to be checked against the code and its tests once
the feature is built. "Owner/admin" means a workspace membership with the
`manage` ability. "Company contact" means a portal member of the client
company with `access_scope = company`.

### Packages (PKG)

- **PKG-1** Only owners/admins can create, edit, publish, retire or list a
  workspace's packages. Every package read and write is scoped to the
  workspace and covered by an isolation test.
- **PKG-2** A package has a draft version until it is published. Publishing
  freezes that version. Any later edit makes a new draft version, and
  published versions are never changed.
- **PKG-3** A version holds every term listed in the data model sketch.
  Amounts are integer minor units in an ISO 4217 currency; durations are
  integer minutes, days or months.
- **PKG-4** Publishing is refused when the version requires any payment (a
  setup fee, the first period, autopay or pay-before-activation) and the
  workspace has no active Stripe connection.
- **PKG-5** Publishing is refused when the version is set to issue invoices
  automatically and has an hourly rate, a retainer, or anything else that
  bills by usage.
- **PKG-6** A retired package accepts no new requests or portal additions.
  Its existing agreements are untouched.
- **PKG-7** An agreement made from a package records the version it came
  from. Every term it uses is copied onto the agreement and its recurring
  items at acceptance, and nothing reads package terms back at billing time.

### Public pages and requests (PUB)

- **PUB-1** The public package page and the catalog need no sign-in, and
  appear only while the feature flag is on and the package is published.
  A retired, draft or unknown package returns a 404 that does not reveal
  which.
- **PUB-2** Public URLs contain no workspace or package database ID or UUID.
  The catalog is addressed by an unguessable per-workspace token; a package by
  that token plus its link name.
- **PUB-3** The catalog lists only published packages marked listed. An
  unlisted package is reachable only by its own link.
- **PUB-4** Submitting the request form writes one request row and nothing
  else: no company, user, invitation, agreement or invoice.
- **PUB-5** The form refuses a filled honeypot or a submission faster than
  the minimum fill time, without saying which. It is throttled per IP and per
  package, and capped at a set number of open requests per email address.
- **PUB-6** Visitor-supplied text is stored as written, rendered escaped, and
  marked untrusted wherever an agent can read it.
- **PUB-7** Public pages load no third-party script, font or image, and the
  logo is served by SVC. The page satisfies the enforcing CSP.
- **PUB-8** Each new request emails the workspace's owners/admins, and the
  request appears in the dashboard's Needs you list.

### Approval and admission (ADM)

- **ADM-1** Only an owner/admin can approve or decline a request. The decision
  is recorded with who made it and when, and is applied under a row lock so
  that two decisions on the same request cannot both succeed.
- **ADM-2** Approving creates (or, if the owner/admin chooses, reuses) the
  client company, a project for the subscription, and a draft agreement
  carrying the version's terms. It then invites the contact through
  `PortalInvitations` with company-wide access, acting as the approver. If the
  provider refuses the invitation, nothing is created.
- **ADM-3** A visitor who already has an account here is given portal access
  directly on approval; no provider invitation is sent.
- **ADM-4** Declining emails the visitor, with the optional reason, and
  creates nothing.
- **ADM-5** The provider's invitation link is never shown on a public page or
  returned to an agent.

### Acceptance, payment and activation (ACC)

- **ACC-1** Only a company contact of the subscribing company can accept a
  package, whether it was offered after approval or added from the portal.
- **ACC-2** Accepting records the signer's name, optional title, the user,
  the time and the exact version accepted, all of which are immutable. It
  signs the draft agreement and is idempotent for the same signer.
- **ACC-3** When the version charges a setup fee or the first period, one
  invoice for those amounts is issued at acceptance and paid through the
  existing Stripe PaymentIntent flow. A setup fee appears either on that
  invoice or as an agreement recurring item, never both.
- **ACC-4** When the version requires autopay, acceptance saves a card, and
  the client's consent is recorded through `AutopayConsentService` as client
  consent, using the existing wording with the version's limit.
- **ACC-5** Activation runs `AgreementWorkflow::activate()`, and only once
  three things hold: acceptance is recorded, any required payment has
  succeeded (as confirmed by the Stripe webhook), and the version starts
  automatically or an owner/admin has activated it.
- **ACC-6** An enrollment still unpaid at its lapse date is marked lapsed, its
  unpaid setup invoice is voided, and its draft agreement is never activated.
- **ACC-7** Activation emails the client. Activation awaiting review appears
  in Needs you.
- **ACC-8** A company contact can add a portal-addable package without a
  request. Project-limited contacts and staff previewing the portal cannot.

### Recurring invoices (INV)

- **INV-1** A package agreement set to issue automatically has each billing
  period's invoice generated and issued by a scheduled command. The run is
  idempotent per agreement and period, and follows the existing cadence
  guards and automatic delivery.
- **INV-2** A package agreement set to draft gets drafts only. Nothing issues
  them without an owner/admin.
- **INV-3** No path issues an invoice for an agreement that Square collects.

### Cancellation (CAN)

- **CAN-1** Any agreement can carry a notice period in days, a minimum term
  in months and a portal cancellation mode (`none`, `request` or
  `self_serve`). Package agreements copy these from their version; other
  agreements default to `none`.
- **CAN-2** The earliest end date is the close of the billing period in which
  (today + notice) falls, and no earlier than the end of the minimum term.
  The server offers that date and later period closes; any other date is
  refused.
- **CAN-3** In `self_serve` mode, a company contact's cancellation schedules
  the end on the chosen date immediately (EDGE-9). In `request` mode it
  creates a pending request. In `none` mode the portal offers no
  cancellation.
- **CAN-4** A company contact can withdraw a pending request. An owner/admin
  can approve it (on the requested or another date, including waiving notice)
  or decline it, with a note.
- **CAN-5** On the scheduled end date, termination writes `status`,
  `ends_on` and `terminated_at`, and records activity (EDGE-9). Billing after
  it follows the existing termination rules.
- **CAN-6** A scheduled or requested cancellation emails owners/admins; an
  accepted cancellation emails the client with the end date.

### Version migration (MIG)

- **MIG-1** An owner/admin can schedule a move from one version to another
  for selected agreements. The effective date is the start of a billing
  period, and no earlier than each selected agreement's notice period allows.
- **MIG-2** Scheduling emails each affected client and shows a portal notice
  with the old and new terms and the date.
- **MIG-3** On the effective date, each still-active agreement's terms and
  recurring items are updated through an audited, versioned update, and the
  agreement records the new version. Agreements that have been terminated are
  skipped and the skip is recorded.
- **MIG-4** SVC never raises an autopay limit. A limit below the new price is
  flagged to the owner/admin before scheduling.
- **MIG-5** A scheduled move can be cancelled before its effective date, which
  notifies the clients again.

### Cross-cutting (X)

- **X-1** Everything sits behind `SVC_SERVICE_PACKAGES_ENABLED`, which is off
  by default. While it is off, no route, nav item, tool or scheduled command
  of this feature is reachable.
- **X-2** Money moves only through Stripe adapters. Nothing writes to Square.
- **X-3** Every enum this feature adds to billing paths is matched
  exhaustively, with no `default` arm.
- **X-4** Every new screen renders through `WorkspaceShell` (or the portal and
  public shells) and passes the 390/820/1440/1920 layout checks in
  [docs/ui.md](ui.md).
- **X-5** No work depends on a queue worker. Emails and provider calls that
  must happen after a commit are deferred to commit explicitly.

## Decision log (2026-11-09 interview)

| Question | Answer |
| --- | --- |
| Activation on sign-up | Per-package setting |
| Payment at sign-up | Per package: initial payment and recurring billing through the payment integration |
| Add-ons | Fixed bundles |
| Cancellation | The agreement or package specifies the notice period, and whether a request terminates automatically |
| Admitting a new person | Request, then invite |
| Recurring invoices | Per-package toggle; automatic issuing for fixed-price packages only |
| Up-front options | Setup fee, first period in advance, card and autopay on file, activate only once paid (all available) |
| Second package for a client | A project per subscription |
| What approval covers | The client, not the agreement |
| Intake | Questions per package |
| Anti-spam | Honeypot and rate limits |
| Public pages | Per-package links plus an optional catalog, with a logo |
| End date | End of the billing period after notice |
| Minimum term | Optional, per package |
| Package edits | Offer a migration |
| Email | New request (admins); cancellation (admins); activation and cancellation confirmations (client); request declined (visitor) |
| Who can add or cancel | Company-wide contacts |
| Scope of cancellation terms | Any agreement |
| Migration consent | Notice, then automatic |
| Autopay limit | Set by the package |
| The defaults listed above | Accepted |
| Package authoring by agents | Full authoring (publish and retire need `confirm`) |
| Agents approving requests | Yes, with `confirm` |
| Client agents | Read services only |
| Scope grouping | A new `packages` scope group |
| Edge cases | EDGE-1 to EDGE-11, all as proposed except retention (kept indefinitely, with manual deletion) |

## Edge cases (EDGE)

Decided on 2026-11-09, before any code. These are traced like every other
requirement.

- **EDGE-1 A payment arrives after the sign-up has lapsed.** Stripe reports a
  payment as succeeded after the lapse job voided its setup invoice. SVC
  records it as an overpayment credit through the existing overpayment path,
  keeps the agreement inactive, and puts it in Needs you. An owner/admin
  reactivates the enrollment (which uses the credit) or refunds it. Nothing is
  refunded or activated automatically.
- **EDGE-2 Autopay is required but stops working.** On a package that requires
  autopay, neither a failed charge nor removing the card changes the
  agreement. A failed charge follows today's autopay attempt handling.
  Removing the last saved card is allowed after a warning in the portal, and
  puts "autopay required but off" in Needs you. Nothing is suspended
  automatically.
- **EDGE-3 A contact loses access part-way through accepting.** Acceptance and
  payment each check access again under a lock. A recorded signature stays.
  A person who has lost access can't start a payment, and any other company
  contact can finish the enrollment before its lapse date.
- **EDGE-4 A request comes from a known email.** When the address belongs to a
  contact of an existing client in this workspace, the review panel shows the
  match and pre-selects "attach to existing client". Nothing the visitor sees
  reveals that the address is known.
- **EDGE-5 A currency Stripe can't charge.** Publishing checks the version's
  currency against the connected Stripe account and refuses with a coded
  refusal (this extends PKG-4).
- **EDGE-6 First period in advance.** The amount is prorated: it covers the
  rest of the current period, according to the agreement's
  `first_cycle_proration`. The first regular invoice covers the next full
  period.
- **EDGE-7 A new version is published before the client accepts.** Approval
  and acceptance always offer the currently published version. When it
  differs from the version the visitor requested, the review panel and the
  acceptance page show what changed.
- **EDGE-8 A package is retired with offers still open.** Offers already made
  can still be accepted until their lapse date. Retiring stops only new
  requests and portal additions (this refines PKG-6).
- **EDGE-9 Cancellation is a scheduled end that can be undone.** Accepting a
  cancellation keeps the agreement `active`, sets `ends_on` to the chosen
  date, and records a scheduled termination holding the previous `ends_on`.
  On that date a scheduled command runs `terminate()`. Until then the
  cancellation can be undone, which restores the previous `ends_on`:
  - by a company contact, in `self_serve` mode;
  - by an owner/admin, in any mode.

  Undoing records activity and emails owners/admins and the client.
  Recurring invoices never cover a period after a scheduled end. This changes
  CAN-3 and CAN-5: `terminate()` now runs on the end date, not when the
  request is accepted.
- **EDGE-10 Requests are kept.** Requests, declined and abandoned ones
  included, are kept indefinitely. An owner/admin can delete a closed request,
  which erases its contact details and answers and leaves a stub recording
  the package, the dates and the outcome.
- **EDGE-11 An overdue balance never blocks cancellation.** Cancelling
  (self-serve or by request) is never blocked by a balance. The amount still
  owed is shown on the confirmation and stays owed.

### Decisions made during implementation

Slice 3a (acceptance and activation without payment) settled these, which the
spec above leaves open:

- **Adding from the portal makes the offer.** "Review and add" is a POST that
  creates the subscription's project, its draft agreement and an `offered`
  enrollment; opening the Services page creates nothing. Adding a package
  again while its offer is open returns that offer instead of a second one.
- **Versions on the enrollment.** `service_package_version_id` stays the
  version offered; `accepted_version_id` is the exact version signed and is
  never changed (ACC-2). The agreement carries the signed version's terms. A
  `version_changed` flag says the two differ (EDGE-7). The agreement itself
  gains no package column in this slice: the enrollment, one per agreement,
  records where it came from (PKG-7).
- **A retired package's offer** is accepted on the version it was offered with,
  not on the last version published (EDGE-8).
- **Same signer** means the same user, name and title: a repeat is a no-op;
  anyone or anything else is refused with `package_already_accepted`.
- **No `accepted` status.** Acceptance moves an offer straight to
  `awaiting_review` or `active` (and, in slice 3b, to `awaiting_payment`).
  Every activation goes through `next()` in `EnrollmentStatus`.
- **Start date.** The agreement starts on the day the service starts: the day
  of acceptance for a package that starts automatically, the day an owner or
  admin activates it for one they review. Dates are in the workspace's
  timezone. The agreement is signed in the name the contact typed, as the
  contact, when it is activated.
- **An automatic start that cannot happen** (the agreement would overlap another
  active one on its project) keeps the signature and leaves the enrollment
  `awaiting_review` for an owner or admin; activating it then refuses with
  `package_activation_conflict` until the overlap is resolved.
- **Until slice 3b**, a version that charges at sign-up, requires autopay or
  starts only once paid was refused when added and when accepted. Slice 3b
  replaced that refusal with the payment step below.
- **Who sees what.** Staff previewing the portal see a company's services but
  are offered no add or sign action. Withdrawn offers are not shown to clients.
  `client_services.*` answers only a client's portal contacts; staff who are not
  contacts see no services there and use `enrollments.*`.
- **Activation email** goes to the client's billing address and portal
  contacts, the recipients every client notice uses.

Slice 3b (payment at sign-up) settled these:

- **The first period is the cadence engine's own invoice.** Acceptance calls
  `ClientInvoicingService::generateCadencePeriod()` for the cycle containing
  the agreement's start, on the still-`draft` agreement. The engine reads the
  agreement's terms, dates and recurring items and never its status on that
  path (only the bulk walk, the selector and the handover reader filter by
  status), so no change to the engine was needed. The invoice it writes is the
  one a later cadence run would have written - same cycle columns, same
  proration, same number series - so the engine's own "already sold" and
  overlap guards make the next regular run bill the next cycle and refuse this
  one (tested: the next run bills December only, and asking for November again
  is refused).
- **What EDGE-6's proration covers.** The engine prorates the retainer (fee
  and hours) by the agreement's `first_cycle_proration`; it bills each
  recurring item per incidence, so a monthly hosting item's opening month is
  charged in full on the start date, as for every other agreement. The engine
  was not changed: prorating recurring items would change every existing
  agreement's first invoice. A quarterly item anchored on the 1st also bills
  its next incidence inside a first quarter anchored mid-month. Both are
  recorded in the tests and are an owner decision if they should change.
- **The setup fee charged at sign-up** is added to that invoice as manual
  `adjustment` lines with no date (the one line type regeneration keeps, and
  undated so it never widens the work period the engine reconciles). With no
  first period it is the whole of an ad hoc invoice. Either way it is never
  also a recurring item.
- **One invoice, issued at acceptance**, through `InvoiceLifecycleService::issue()`,
  so automatic client delivery follows the company's own setting. A cadence
  invoice is dated the first of the month it sells; the sign-up invoice is
  made due the day it is issued so it is not overdue on arrival. The
  enrollment records it as `signup_invoice_id` (composite tenant key),
  written once.
- **Lock order.** Acceptance and lapsing take the billing maintenance share
  lock first, then the package, the enrollment, the agreement, the invoice and
  line rows (an existence check on the draft agreement that also ranks them
  before invoice numbering), then what issuing takes. The conformance test
  drives both with no new inversion.
- **What waits.** An accepted sign-up goes to `awaiting_payment` while it
  waits for the invoice (only when the version starts only once paid) or for
  autopay (when it requires it); otherwise it follows its activation mode with
  the invoice simply outstanding. `SignupGate` is that rule, pure.
- **Autopay that meets the version** is on at exactly the version's limit,
  with a default saved method, standing on the client's own portal consent
  (`AutopayConsentService`, the existing wording with the version's limit). A
  provider's attested turn-on, or another limit, does not meet it. The review
  page carries the existing save-card endpoints and the existing autopay
  enable endpoint with the exact fields to post; enabling from there returns
  the client to the service page.
- **Payment moves it on in the same request.** The one place a payment marks
  an invoice paid (`InvoiceLifecycleService::recordMarkedPaid()`, reached by
  the Stripe webhook and by recorded payments alike) defers a call to
  `PackageEnrollmentWorkflow::signupInvoicePaid()` to commit explicitly; a
  rolled-back payment never reaches it (X-5), and a replayed webhook finds the
  invoice already paid and the enrollment already moved. A payment recorded
  by hand counts as one confirmed by Stripe: an owner recording it attests
  the money arrived. Turning autopay on in the portal moves waiting
  enrollments on the same way.
- **Starting date with a paid first period.** When the sign-up invoice billed
  the first cycle, activation (by an owner, or after payment) keeps the start
  date of acceptance: that is the period the client paid for. Otherwise the
  service starts the day it is activated, as in 3a.
- **Lapsing (ACC-6)** is `svc:packages:lapse-enrollments`, hourly, behind the
  feature flag. An offer not accepted lapses `payment_lapse_days` after it was
  made, an unpaid acceptance that many days after it was signed, on that day
  in the workspace's timezone. A lapsed offer cannot be accepted. Each run
  walks every open enrollment in pages by id (no elapsed-time filter: a
  one-day offer made late in the evening is due at the workspace's next
  midnight) and locks only those due on the workspace's calendar. Each is
  decided under its own lock against the invoice as it stands: one whose wait
  is over (paid, autopay on) is moved on instead, a paid invoice still waiting
  for autopay is held, one with a payment in flight is held (the void refuses
  it) for a later run, and an autopay-only sign-up with no invoice lapses.
- **Event times are UTC instants** (`offered_at`, `accepted_at`,
  `activated_at`, `lapsed_at`); the dates the workspace's calendar decides
  (`starts_on`, the lapse day) are the workspace's.
- **A sign-up's agreement starts only with its service.**
  `AgreementWorkflow::activate()` refuses a draft agreement an enrollment owns
  with `package_agreement_starts_with_service` unless the enrollment workflow
  passes that enrollment, so `agreements.activate` (and the Agreements
  screen's control behind it) cannot start an unpaid or unreviewed sign-up.
  The Agreements screen offers such an agreement the enrollment's own
  activation while it waits for review.
- **Autopay's currency.** Autopay's limit is in the company's billing
  currency. Required autopay is met only by a client consent in the
  version's currency; a version in another currency is refused at
  acceptance with `package_autopay_currency_mismatch`, and the review page
  says why instead of offering the consent. A client authorizing the limit a
  provider already turned on is recorded as the client's own consent (no
  second notice); a replay by the same side records nothing.
- **Money after the lapse (EDGE-1)** follows the existing path: a payment
  succeeding on a void invoice is refused and its Stripe event recorded as
  failed for review. Nothing is refunded or activated; Needs you shows
  "Payment received after <client>'s sign-up lapsed". The overpayment credit
  EDGE-1 describes is not created automatically, because the existing path
  does not credit a void invoice.
- **Autopay required but off (EDGE-2)** is a Needs you row for an active
  service, and the portal's billing page sends a `remove_warning` on the card
  autopay charges (or the only card) while a service requires autopay.
- **EDGE-5** asks Stripe for the account's country and that country's
  supported currencies, outside the transaction and only for a draft that
  takes money; nothing is cached. Refused with
  `package_currency_not_chargeable`, or `package_stripe_currencies_unknown`
  when Stripe cannot be asked. Charging the first period also needs a
  recurring cadence (`package_first_period_needs_recurring_cadence`).
- **New refusals at acceptance:** `package_payment_unavailable` when the Stripe
  connection has gone since publishing (also when adding from the portal),
  `package_collected_in_square` for an agreement carrying the Square marker
  (X-2), and `package_first_period_unbillable` when the engine refuses the
  first cycle. That last one happens when another invoice of the same company
  already covers the work period the first cycle reconciles: the engine's
  overlap guard was per company, not per project, so a company already billed
  on a cadence through another agreement could not get a package's first
  period invoiced. Slice 4 narrowed the guard (below); the refusal now remains
  only where the other agreement's work genuinely overlaps.

Slice 4 (automatic recurring invoices) settled these:

- **The overlap guard compares invoices whose work can overlap.** The
  engine's `assertNoOverlappingInvoice()` refused a period when any live
  cadence invoice *of the company* covered overlapping dates. What it protects
  is one piece of work being reconciled twice - and every selector a period
  reconciles (time, deferred work, milestones, expenses, subcontractor time,
  the retainer ledger and the billed-overage ledger) already reads through the
  agreement's project scope; the agreement selector already treats "two
  project retainers under one company" as running concurrently, and the
  handover guard keys successors by project. Across agreements the company
  guard was never serialised either (each generator holds only its own
  agreement's lock). So it now ignores an invoice only when that invoice names
  an agreement of the same company on a *different* project and this
  agreement is project-scoped too (`ClientInvoice::scopeSharingWorkWith()`).
  Everything else still refuses: a company-wide agreement (its selectors read
  every project's work) against any agreement of the company in either
  direction, another agreement on the same project, an invoice naming no
  agreement, and one naming an agreement the company cannot account for. Tested
  on monthly and quarterly cadences (`ProjectScopedOverlapGuardTest`), with a
  new history in `BillingHistoryInvariantTest` (two project agreements billing
  the same periods each price exactly as they would alone, no hour charged
  twice or by the other agreement); every existing billing test passes (the
  one billing-test edit registers the new `auto_invoice_period_start` date
  column with `DateColumnBoundaryTest`).
  Before the change that history lost the second agreement's revenue: the bulk
  walk skipped its periods with the overlap error. Consequence: a client's
  second package (its own project, its own agreement) is invoiced on the same
  days as the first. A client with a *company-wide* agreement on a cadence
  still cannot have a package period invoiced over the same dates; that
  refusal is surfaced (below), not hidden.
- **A company-wide agreement keeps its dates.** The guard is symmetric:
  between a company-wide agreement and a package, whichever invoice is written
  first refuses the other, so an hourly automatic run could take the dates of
  a client's main retainer before its own draft is made, every period. The
  issuer therefore refuses a package period (`company_wide_agreement`, shown
  to the owner) while any company-wide agreement of the client that bills on
  a recurring cadence is in force or ended no earlier than the work that
  period reconciles (its final invoice still needs those dates). It is asked
  with a share lock before the package agreement's own lock. The owner's
  options are to scope that agreement to a project, or to bill the package
  by hand. The acceptance-time first period (slice 3b) does not defer this
  way; a company-wide agreement activated after a package period was issued
  can still find its first period refused by the engine, as before.
- **`svc:packages:issue-invoices`** (hourly, behind the feature flag, in
  process) issues INV-1. For each workspace with an active enrollment whose
  *signed* version issues automatically, it reads the current period of each
  such agreement that has begun with nothing billed (`UncoveredCadencePeriods`,
  the reader the cadence drafts and the stale-and-missing audit share), then
  per agreement, in its own transaction: the billing maintenance share lock,
  the enrollment's row lock, the company-wide check (above),
  `CadencePeriodGuard::lock()` (the agreement lock and a locking read of every
  invoice claiming the period), the Square check (INV-3), the cadence engine,
  `EmptyCadenceDraft` (a period that asks for nothing is rolled back, recorded
  `nothing_to_bill`), then
  `InvoiceLifecycleService::issue()`, so automatic delivery and autopay follow
  the company's own settings. Generating and issuing commit together: nothing
  is left claimed and unissued. A re-run, or a concurrent run waiting on the
  agreement lock, finds the claim and issues nothing (tested by two runs that
  both read the period as due). Only the period containing today is
  considered, as for the cadence drafts: a period missed entirely (the
  scheduler down for a whole period) is an owner's to bill.
- **Due date.** The invoice keeps the engine's date (the first day of the
  period it sells); issued later than that day, it is due the day it is issued,
  not overdue on arrival.
- **Lock order.** Generating and issuing in one transaction would lock the
  invoice's lines and the agreement's time after the engine numbers the
  invoice and locks milestones; the run takes the agreement's lines, the
  workspace numbering and the agreement's time first, in registry order, as
  acceptance does. The conformance test drives an issued and a refused period;
  its MariaDB snapshot-read ratchet now pins the engine's reads under this
  opener (and the slice 3b acceptance entries it was missing).
- **Refusals reach an owner.** A refused period rolls back and is recorded on
  the enrollment (`auto_invoice_outcome` `refused`, a code -
  `collected_in_square`, `company_wide_agreement`, `period_unbillable`,
  `issue_refused` or `failed` - and the engine's own words), which Needs you
  lists ("… invoice not issued automatically") and `enrollments.*` returns
  under `invoicing.last_automatic`. It is retried every run, does not stop any
  other agreement, and is written only when it changes (no hourly version
  churn). Once the period is invoiced by the run the record says `issued`;
  invoiced by hand, `covered`; either way the row goes. The record is the
  enrollment's *last* result: a period still refused when the next period
  begins is replaced by that period's result, and from then on the gap shows
  only in the agreement's invoices (no reader looks back further). There
  was no existing record of failed scheduled generation to reuse (the cadence
  drafts only log), so this is new.
- **INV-2 reuses the cadence drafts.** A draft-mode package agreement is an
  ordinary recurring agreement: `svc:billing:generate-cadence-drafts` (while
  `svc.billing.scheduled_cadence_drafts` is on) or `invoices.generate_period`
  drafts its period and only an owner issues it. That command now leaves an
  automatic package agreement to the issuer while the feature is on, as it
  leaves an agreement with a billing schedule to that schedule; with the
  feature off, an automatic package agreement is only ever drafted.
- **INV-3.** An agreement carrying the Square marker gets nothing - not even a
  draft - and the refusal `collected_in_square` is recorded for the owner.
- **Where "automatic" is read.** From the enrollment's signed version
  (`accepted_version_id`), which is immutable; the agreement carries no
  issuing column. Slice 6 (version migration) must keep this right when it
  moves an agreement to another version.
- **Agent read state.** `enrollments.*` gains `invoicing` (`issuing`,
  `next_period_start`, `last_automatic`); `client_services.*` gains only
  `next_period_start` - how the invoice is made is the operator's setting.

Slice 5 (cancellation) settled these:

- **Two records, as sketched.** `agreement_cancellation_requests` holds a
  company contact's request in `request` mode and its decision;
  `agreement_scheduled_terminations` holds an accepted end until it is reached
  or undone, with the `ends_on` it replaced and its source (`self_serve`,
  `request` or `operator`). A self-serve cancellation writes no request. At most
  one request is pending and one end scheduled per agreement, held under the
  agreement's lock rather than by an index.
- **Terms on every agreement (CAN-1).** `notice_days` (default 0),
  `minimum_term_months` (null for none) and `cancellation_mode` (default
  `none`) are agreement terms like any other: `AgreementTermRules` is the one
  list of constraints for packages and agreements, `agreements.update` and the
  terms form edit them, and `PackageAgreementTerms` copies a version's. The
  migration gives agreements already made from a package their carried
  version's terms.
- **The dates offered (CAN-2)** are `CancellationDates::offer()` on the
  workspace's today: up to three period closes of the billing engine's own
  periods, from the earliest, each before the agreement's current end. An
  agreement already ending before the earliest offers nothing
  (`cancellation_no_dates_offered`); one that does not bill in periods cannot be
  cancelled this way (`cancellation_not_recurring`: terminate it). Waiving
  notice, an owner's or admin's alone, counts the notice as zero days; the
  minimum term always holds. Every screen and agent read shows the server's
  dates; any other date is refused with `cancellation_date_not_offered`.
- **Approving** takes the date the client asked for unless the owner chooses
  another offered one, and `waive_notice` must be sent for a date only waiving
  allows. **Declining emails the client** with the note: the spec's email list
  names no such message, but a client who asked deserves the answer.
- **An owner or admin can schedule an end directly** (`agreements.schedule_end`,
  "End this agreement" on the agreement's page), in any portal mode, on an
  offered date; refused while a client's request is pending
  (`cancellation_request_pending`), so that request is answered.
- **Who undoes (EDGE-9).** An owner or admin undoes any scheduled end; a company
  contact only an end they scheduled themselves in `self_serve` mode, while the
  agreement is still in that mode, never one an owner or admin decided, even
  after the mode becomes self-serve (`cancellation_undo_not_self_serve`). Undo
  is possible through the end date itself and refused after it
  (`cancellation_end_reached`), even if the scheduler missed the date; on the
  date the first hourly run terminates it. While an end is scheduled the
  agreement's `ends_on` cannot be edited (`cancellation_end_scheduled`): undoing
  restores the end it replaced and would discard the edit.
- **Termination on the date (CAN-5)** is `svc:agreements:terminate-scheduled`,
  hourly, behind the feature flag, in process. It walks every scheduled end in
  pages by id, decides which are due on each workspace's calendar, and carries
  out each in its own transaction under its agreement's lock through
  `AgreementWorkflow::terminate()` with `ends_on` set to the scheduled date, so
  an undo racing it, or a second run, finds it settled.
- **The `terminated_at` fix.** `AgreementWorkflow::terminate()` now writes
  `terminated_at` (a UTC instant), for any termination, and settles what was
  open on the agreement: its scheduled end becomes `terminated` and a pending
  request is closed as declined with the note "The agreement was terminated."
  (no email).
- **Billing.** Nothing in the engine changed. A scheduled end is the agreement's
  `ends_on`, at a period close, so automatic recurring invoices never sell a
  period after it (`UncoveredCadencePeriods` already reads `ends_on`; tested
  monthly and quarterly beside a package not cancelled), and termination
  follows the existing rules. `BillingHistoryInvariantTest` gains a history: a
  cancelled agreement invoices exactly as one ended on the same date by hand -
  including the engine's existing settlement invoice for the period after an
  ended agreement - and an undone one exactly as one never cancelled.
- **Emails (CAN-6)**, through `PackageNotifier` after commit: owners and admins
  on a request and on a client's self-serve end; the client, with the end date,
  on any accepted cancellation (self-serve, approved or scheduled by an owner);
  both on an undo; the client on a decline. A withdrawal sends nothing.
- **Lock order.** The agreement, then its pending request, then its scheduled
  end (`LockResource` places both after `ClientAgreement`); every read of them
  under the agreement's lock is a locking read. `AgreementWorkflow::update()`
  now reads its result back under its own lock instead of from the snapshot,
  which the MariaDB ratchet caught once package agreements carried terms that
  differ from a new draft's. No new inversion, no new pinned snapshot read.
- **Agent surface.** `cancellation_requests.list|get` (`billing:read`) and
  `cancellation_requests.approve|decline`, `agreements.schedule_end` and
  `agreements.undo_scheduled_end` (`clients:write`, the clients cutover,
  `confirm` and `expected_version` - the request's for a decision, the
  agreement's for an end) are declared in `ServicePackageOperations` and exist
  only while the feature is on. `agreements.get|list` gain the three terms and
  `cancellation` (the dates with and without notice, the pending request, the
  scheduled end); `client_services.*` gains `cancellation` for the client,
  read-only. A client contact cancels, withdraws and undoes in the portal only.
- **Screens.** The portal's panel is keyed by the agreement
  (`portal.agreements.cancellation.*`), drawn on the service's page and on the
  portal's agreement page, with URLs for a company-wide contact only and the
  amount still owed on the confirmation (EDGE-11, from the Balance card's own
  reader); it is not drawn for `none` mode with nothing pending or scheduled.
  The operator's panel on the agreement's page posts to the agent endpoints.
  Each pending request is its own Needs you row, dated with the end asked for.

Slice 6 (moving subscribers to a new version) settled these:

- **A move is a new agreement term, not an edit.** The billing engine reads an
  agreement's terms - hourly rate, retainer fee and hours, rollover, cadence -
  for every period it reconciles, including work done before today and
  invoiced after it (December's time is reconciled on January's invoice), and
  its retainer ledger is recomputed from the agreement's start. Rewriting an
  active agreement in place would therefore price the last old period's work,
  and rewrite past months' ledger, on the new terms. The engine's own way to
  change terms over time is a sequence of agreements on one project
  (`AgreementSelector`: "each segment owns the cycles that fall inside it",
  and `AgreementBillingRateResolver` already prefers a renewal over the term it
  replaced). So on the effective date the move ends the current agreement on
  the day before through `AgreementWorkflow::terminate()` (it stays the record
  of what the client signed, its text never rewritten) and starts an agreement
  on the same project carrying the new version's terms and recurring items
  (`PackageAgreementTerms::createDraft()`), activated through
  `AgreementWorkflow::activate()`. That is the "audited, versioned update" of
  MIG-3: both agreements' activity, `agreement.moved` / `agreement.moved_in`,
  and the move's own record. The enrollment - the service - then names the new
  agreement and `current_version_id`; `accepted_version_id`, what the client
  signed, never changes (ACC-2), and everything that reads the terms in force
  (automatic invoicing - slice 4's note - required autopay, Needs you names,
  the client's terms) reads the carried version
  (`ServicePackageEnrollment::carriedVersionId()`, `CARRIED_VERSION_SQL`).
- **What carries over** to the new agreement: its `ends_on`, what is left of
  the minimum term in whole months from the effective date (a move never
  starts a new commitment; `MigrationRules::remainingMinimumTerm()`), the
  notice period and portal mode of the new version, a pending cancellation
  request and a scheduled end from the date on (re-pointed, so the client's
  request is still answered and their end still happens), the client-visibility
  settings, interim overage and rollover policy, and the Square marker (INV-3).
  **Unused retainer hours do not** - the engine's ledger ends with its
  agreement - which is moot today because of the next point.
- **Agreements that bill by usage are refused** (`package_migration_bills_by_usage`).
  An hourly rate or a retainer means the last old period's work is settled on
  the invoice that opens the next period; the new agreement's first invoice
  claims that same work period, so the engine's overlap guard refuses
  whichever is written second - the old term's settlement, leaving its work to
  be priced on the new terms, or the new term's first period, never sold.
  `BillingHistoryInvariantTest` caught exactly this while the move was being
  built. Until the engine can settle a last period beside a same-project
  successor (a follow-up), only fixed-price services move. At the effective
  date the move asks the engine, in a savepoint it always rolls back, for the
  old agreement's closing invoice: empty for a fixed-price service (its
  recurring items are ended the day before with it), so nothing is written and
  no invoice number spent; anything to bill (work or expenses that reached a
  fixed-price service) holds the move (`last_period_to_settle`).
- **Dates (MIG-1).** `MigrationDates`: the earliest effective date is the day
  after the earliest end a cancellation made today could choose (the close of
  the period in which today plus the agreement's own notice falls), so a client
  told now can always leave before the new terms; later period starts of the
  engine's own periods follow; none on or before the last day of a period an
  issued invoice already sold (so nothing is billed twice and no issued invoice
  rewritten); none after the agreement's end. MIG-1 names the notice alone, so
  the minimum term does not hold a move back. One date per move; a subscriber
  whose own periods it does not start, or whose notice it falls inside, is
  refused by name (`package_migration_date_not_offered`), and the preview
  offers every subscriber's dates with how many each suits, suggesting the
  earliest that suits the most. Only to the package's published version, from
  an older one, in the same currency, both billing in periods; a subscriber in
  one scheduled move at a time.
- **Records.** `service_package_migrations` (package, from/to version, date,
  message, `scheduled`/`completed`/`cancelled`) and
  `service_package_migration_targets` (enrollment, company, the agreement moved
  from and, once applied, the successor; `scheduled`/`applied`/`skipped`/
  `cancelled`; an outcome code - a skip, or why a scheduled one waits; the new
  price and the company's autopay limit at scheduling). Composite tenant keys;
  `LockResource` places the move and its targets after the enrollment, so
  automatic invoicing can ask under the enrollment's lock whether a move is due.
- **On the date (MIG-3)** `svc:packages:apply-migrations`, hourly behind the
  flag, before the invoicing command, walks every scheduled target in pages by
  id, decides which are due on each workspace's calendar, and applies each in
  its own transaction: the maintenance share lock, the enrollment, the move,
  the target, the agreement, its pending request and scheduled end, its
  invoices from the date on, then the successor, the termination, the closing
  invoice probe, any draft's discard and the activation. Decisions
  (`MigrationRules::decide()`, pure): a stopped service, a service already moved
  by another move, an agreement no longer active or ending before the date is
  **skipped** and recorded; a pending request to leave before the date, or an
  issued invoice already selling a period from the date on, **holds** it
  (recorded once, retried every run, a Needs you row). Drafts the walk already
  made for those periods were priced on the old terms and are discarded through
  the ordinary discard. Automatic invoicing skips a period a due move has not
  applied to yet (`waits_for_move`), so the new period is never issued on the
  old terms. A second run, or one beside it, finds the target settled.
- **Cancelling (MIG-5)** is allowed while the move is scheduled: before the date
  the whole move, from it on the subscribers still waiting (held); one already
  moved stays moved and the move completes. Each client whose move is cancelled
  is emailed.
- **Notices (MIG-2, MIG-4).** `MigrationTermChanges` words each term that
  changes, old and new, once, for the email, the portal notice, the owner's
  preview and the agent API (the owner also sees operator settings and terms
  that only shape new sign-ups, marked so). Clients are emailed at scheduling
  and at a cancellation, after commit; nothing is emailed when the move applies
  (the spec names no such message). An autopay limit below the new price - read
  live against the company's limit - is flagged in the preview, the email, the
  portal notice and Needs you, and never raised.
- **Agent surface.** `package_migrations.preview|list|get` (`packages:read`)
  and `package_migrations.schedule|cancel` (`packages:write`, `confirm`,
  `expected_version` - the package's to schedule, the move's to cancel), behind
  the package write cutover. `enrollments.*` gains `current_version` and
  `move`; `client_services.*` gains `move`, read-only.
- **Screens.** The package editor lists versions with their active subscribers
  and a link to move an older version's, and the package's moves with a
  confirmed cancel; the move page (`packages.move`) is the preview with the
  date Select, the subscribers and their flags, and the confirmed schedule; the
  portal service page carries the notice and the Services list notes the date.

## Validation plan

### Tracing requirements to tests

- **Naming the requirement.** Every test that proves a requirement names its
  ID:
  - PHP: `#[Requirement('CAN-2')]` (a new attribute in `tests/Support`) on
    the test method or class.
  - Vitest and Playwright: `[CAN-2]` in the test title.
- **The tracing test** (`tests/Feature/ServicePackages/RequirementTraceabilityTest.php`):
  - reads every `**XXX-n**` identifier in this document;
  - reads every ID named in `tests/`, `resources/js/**/*.test.ts(x)` and the
    browser journeys;
  - fails when a requirement has no test, or a test names an ID that doesn't
    exist;
- **What the tracing test can't do.** It proves coverage exists, not that it
  is meaningful. The post-build review checks each requirement against its
  tests and code.

### Layers

1. **Pure calculations, with no database** (`tests/Unit/ServicePackages`):
   - the earliest end date and the dates offered (CAN-2), and the scheduled
    end and its undo (EDGE-9), table-driven
     across every cadence, notice periods crossing period boundaries,
     month-end and leap-year dates, the minimum term, and the workspace's
     timezone;
   - the enrollment state machine: every from/to pair, legal and illegal;
   - whether a package may auto-issue (PKG-5), whether a migration date is
     valid (MIG-1), and the differences between two package versions.

   These classes are the main target of `composer test:mutation-diff`. The
   aim is no escaped mutants; any exception needs an `@infection-ignore-all`
   comment saying why.
2. **Workflows, with a database** (`tests/Feature/ServicePackages`):
   - **Isolation:** one test per new table, plus the inventory tests that pick
     up new tables on their own: tenant keys, the composite-key rollback,
     scoped write statements, lock order, the lock registry, and fillable
     coverage.
   - **Concurrency:** two approvals of the same request; approve racing
     decline; the payment webhook racing the lapse job (EDGE-1); a double
     acceptance; a cancellation racing a version move; an undo racing the
     scheduled termination (EDGE-9). Each test asserts that exactly one
     side wins.
   - **Idempotency:** a replayed Stripe webhook activates once; the auto-issue
     job run twice for one period produces one invoice; applying a migration
     twice changes nothing the second time; a repeated acceptance from the
     same signer is a no-op.
   - **After-commit effects (X-5):** a rolled-back approval sends no email and
     no invitation. These tests assert on the effect itself, never on
     `DB::transactionLevel()`.
   - **Billing goldens** (`BuildsGoldenInvoices`):
     - the setup fee is billed once (ACC-3);
     - the first period is prorated;
     - termination at a period end leaves no partial final period (CAN-5);
     - a migrated price applies from its effective period (MIG-3);
     - nothing auto-issues for a Square-collected agreement (INV-3).
3. **Public surface:**
   - a submission writes exactly one row, in `service_package_requests`, and
     nothing else (PUB-4, using `CapturesTenantOwnedWrites`);
   - identical 404 status and body for a draft, retired, unknown or
     wrong-token link (PUB-1);
   - the honeypot, minimum fill time, throttles and per-address cap each
     refuse without saying which (PUB-5);
   - a snapshot of every route reachable without signing in, so a new public
     endpoint has to be added to it deliberately;
   - the public pages pass under the CSP in enforce mode (PUB-7);
   - with the feature flag off, no route, tool or scheduled command of the
     feature exists (X-1, API-6).
4. **API:**
   - the existing parity, inventory, OpenAPI and visibility-snapshot tests,
     updated with the new operations;
   - every `confirm` operation refuses without it (API-2);
   - visitor text is returned only inside the untrusted wrapper (API-3);
   - a client contact's token gets "not found" from owner/admin operations
     (API-5).
5. **Front end:**
   - Vitest: pages render exactly the actions and links the server sends; for
     example, no cancel link when the server sends `null`.
   - `pnpm test:layout` at 390, 820, 1440 and 1920 px, with deliberately
     oversized data (long company, package and contact names, long emails and
     domains), plus `uiConsistencyRisks` and axe on every new screen (X-4).
6. **Browser journeys** (Playwright, with fake Stripe and identity provider):
   - request → approve → sign in → accept and pay → active → request
     cancellation → approve;
   - an existing client adds a service from the portal;
   - a self-serve cancellation.

   Keep these few; the layers above carry the detail.

### Where each check runs

- **Locally while building:** the unit tests and the feature tests for the
  slice being built, Pint on the files touched, the sensitive-data scan, and
  the layout check for any screen that changed.
- **On every push, in hosted CI:** the full PHPUnit run, PHPStan, Pint, the
  mutation diff, Vitest and the layout runs.
- **Before merging:** CI green on the exact commit being merged, the tracing
  test green, and the post-build review against this document.

## Open items for the next round

- Wording of the client emails and the "Approving will" summary.

## MCP and REST surface

Settled on 2026-11-09:

- Owner/admin agents get **full authoring**.
- Agents may **approve or decline requests**, with `confirm`.
- Client agents may **read services only**.
- The operations go in a **new `packages` scope group**.

The conventions are the ones every existing operation follows. Each operation
is declared once in `AgentOperationCatalog`, with REST bindings, MCP tools and
the OpenAPI paths generated from that one declaration. Writes take an
idempotency key, versioned writes an opaque `expected_version`, and acts that
reach a client or cannot be undone take `confirm: true`. Public pages are not
part of the agent API.

### Scopes and switches

- **New scopes:** `packages:read` and `packages:write` (`packages:write`
  implies `packages:read`). The new scope group automatically becomes a
  `packages` toolset.
- **Write switch:** package writes sit behind their own deployment switch,
  `AGENT_API_PACKAGE_WRITES_ENABLED`, which is off by default. Like the other
  write groups, it appears in the visibility snapshot.
- **Feature flag:** while `SVC_SERVICE_PACKAGES_ENABLED` is off, none of these
  operations is registered or listed.
- **Cancellation decisions** stay under `clients:write`, next to
  `agreements.terminate`, behind the existing clients cutover. Reading
  cancellation requests uses `billing:read`, like `agreements.list`.
- **Admin (sensitive) tier:** `packages:write` goes in this tier of the scope
  table in [mcp.md](mcp.md), because it can email outside people.

### Operations

| Operation | Scope | Who | Notes |
| --- | --- | --- | --- |
| `packages.list`, `packages.get` | `packages:read` | owner/admin | Versions, subscriber counts, public URL, readiness (Stripe, auto-issue eligibility) |
| `packages.create` | `packages:write` | owner/admin | Creates a package with a draft version |
| `packages.update` | `packages:write` | owner/admin | Edits the draft version only (creates one from the published version if none exists); `expected_version` |
| `packages.publish` | `packages:write` | owner/admin | `confirm`; refused under PKG-4 and PKG-5 with coded refusals |
| `packages.retire` | `packages:write` | owner/admin | `confirm` |
| `package_requests.list`, `package_requests.get` | `packages:read` | owner/admin | Visitor-supplied fields are returned under an `untrusted` object and named as untrusted in the tool description |
| `package_requests.approve` | `packages:write` | owner/admin | `confirm`; takes `client` (new, or an existing client's public id); sends the invitation as the token's user |
| `package_requests.decline` | `packages:write` | owner/admin | `confirm`; optional reason; emails the visitor |
| `enrollments.list`, `enrollments.get` | `packages:read` | owner/admin | Status, payment state, lapse date |
| `enrollments.activate` | `packages:write` | owner/admin | `confirm`; the same rule as `agreements.activate`, refused until acceptance (and payment, if required) |
| `cancellation_requests.list`, `cancellation_requests.get` | `billing:read` | owner/admin | |
| `cancellation_requests.approve`, `cancellation_requests.decline` | `clients:write` | owner/admin | `confirm`; approve takes an end date from the offered dates |
| `agreements.update` (extended) | `clients:write` | owner/admin | Gains `notice_days`, `minimum_term_months`, `cancellation_mode` |
| `agreements.get` (extended) | `billing:read` | owner/admin | Adds cancellation terms, the earliest end date, the package version, and any pending cancellation or scheduled move |
| `package_migrations.preview` | `packages:read` | owner/admin | Term differences, eligible subscribers, earliest effective date, autopay-limit flags; changes nothing |
| `package_migrations.schedule`, `package_migrations.cancel` | `packages:write` | owner/admin | `confirm`; both email clients |
| `client_services.list`, `client_services.get` | `packages:read` | company contact | Client side, read-only. The company's service agreements and terms, the earliest end date and offered end dates, any pending cancellation or scheduled move, and the portal-addable packages. Project-limited contacts see only their projects' services. |

### Not exposed

- Acceptance, signing and payment. These need the client's own browser and
  Stripe.
- Adding a service, or requesting, withdrawing or scheduling a cancellation,
  for client agents. The portal only.
- The provider invitation link, as today.
- Anything on the public request form.

### Files and tests this touches

These are the inventories listed in the research for this design:

- `AgentOperationCatalog` (routes and tools), `AgentRestDocumentation`,
  `public/openapi/svc-agent-v1.json`, `routes/api.php`, the controllers and
  mutation actions, and an MCP tools class.
- `AgentApiScopes`, the deployment flags, `docs/mcp.md`, and
  `resources/js/types/api.generated.ts`.
- These tests and snapshots:
  - `AgentOperationRegistryTest` (the confirmed-operation list)
  - `OpenApiDocumentGenerationTest`
  - `VersionedApiRouteInventoryTest`
  - `AgentMcpInventoryTest` (the tool count)
  - the visibility snapshot
  - `AgentMcpProgressiveDiscoveryTest`
  - a REST/MCP parity test
  - `WorkspaceConcealmentTest`

### Requirements (API)

- **API-1** Every operation above is declared once and is reachable through
  both REST and MCP, with matching request and response schemas (a parity
  test).
- **API-2** `packages.publish`, `packages.retire`, `package_requests.approve`,
  `package_requests.decline`, `enrollments.activate`,
  `cancellation_requests.approve`, `cancellation_requests.decline`,
  `package_migrations.schedule` and `package_migrations.cancel` refuse to run
  without `confirm: true`. They are added to the confirmed-operation list and
  to the server instructions.
- **API-3** Visitor-supplied request content is never returned outside an
  `untrusted` envelope.
- **API-4** No operation returns an invitation link, a Stripe secret or a
  card detail.
- **API-5** A company contact's token reaches only `client_services.*`,
  scoped to their companies and projects. Owner/admin operations answer them
  as if the resource does not exist.
- **API-6** With the feature flag off, the `packages` toolset and its
  operations are absent from discovery, OpenAPI and the route inventory.
