# Ordering late-work eligibility and invoice issue

The ordering point is the outermost physical transaction commit, rather than
second-resolution timestamps or auto-increment ID allocation. An explicit domain writer
reserves an ID in `billing_eligibility_event_reservations`, an ID-only allocator
with no tenant data or foreign keys, and stores the ID on the entry or first
issue. No company share lock is introduced between business phases. Immediately
before PDO commits, one narrow coordinator publishes all its actual event rows
in ascending workspace/company order, acquiring their company foreign-key parents
before any counter fence. It then assigns consecutive per-company sequences by
locking existing counters in that same order. Only counter updates, updates of
its own new event rows and exact deletion of its own ID reservations follow.
Counter rows are seeded when companies are created and backfilled by migration;
the counter phase never inserts a counter or acquires a new foreign-key parent.

A committed event always has a sequence. Before commit, pending positions are
resolved only from this coordinator's registrations for the current connection
and savepoint; actual event rows do not exist yet. Other connections cannot use
those registrations. Two pending events compare their reserved IDs, preserving
operation order inside an outer transaction (including rollback-only replay).
Pending work follows a committed issue; committed work precedes a pending issue.
Two committed events compare their per-company sequences. Thus a lower allocated
ID that commits later is still later work. No event ordering is inferred from an
unlocked snapshot of time-entry membership or a maximum time-entry ID.

The coordinator is scoped to a connection and tracks savepoint depth. Savepoint
commit promotes its registrations to the parent; rollback drops registrations
from abandoned depths; outer commit/rollback clears them. Its commit listener
runs only at physical depth one. If sequence assignment fails, it rolls back the
physical transaction before propagating the error (Laravel does not itself
always roll back a non-retryable committing-listener exception). There are no
other application or installed-package `TransactionCommitting` listeners.

## Writer inventory

- `TimeEntryWorkflow::create`: browser, agent API and CLI logging share this
  writer; it records creation inside the entry's transaction.
- `TimeEntryMutationService::approve`: operator/bulk approval records a new event
  after the approved row is saved. Client approval records a client's decision,
  not billing eligibility, and does not move this event.
- `TimeEntryMutationService::update`: date, billable and deferred-flag changes
  record a new event; wording, visibility, task and rate edits do not.
- `ConsultantTimeReleaser::release`: successful draft-to-approved compare-and-set
  records eligibility even though system release deliberately names no approver.
- `TimeEntrySplitter`: copies the original event to every fragment. Recombination
  requires the same event as part of its unchanged-fragment signature.
- `InvoiceLifecycleService::issue`: records the first cadence issue once, in its
  existing invoice transaction. Reissue retains that original boundary.
- Void/reopen and rollback-only replay restore previously eligible statuses and
  keep their event; they do not manufacture newly logged or newly approved work.
- Imports and existing rows start without ordering evidence. A missing event uses
  the previous strict timestamp comparison. Equal legacy timestamps are ambiguous
  and are not automatically billed; explicit operator correction is required.
  Imported invoice provenance remains excluded regardless of event availability.

Supported time edits do not reassign company/project ownership; the edit request
and writer omit both fields. The ordering does not depend on this membership
assumption: event queries still check the entry/invoice workspace and company.
Events are internal immutable history, retained until their company is deleted.
Event pointers are nullable attribution, queried through workspace/company, and
are not accepted by public mutation payloads. Company deletion removes its event
and counter rows; deleting an entry/invoice does not renumber or erase an event.

The allocator has no tenant-owned columns and is not an ownership exemption: it
contains anonymous IDs only and retains no row after a successful commit. Final
event and counter rows enforce composite workspace/company foreign keys. Nullable
event pointers intentionally have no immediate FK because that would acquire a
company/event parent during domain work; a non-null unknown or foreign pointer
is refused. Supported single-company business operations cannot acquire another
company parent at commit. Bulk approval locks all entries up front and writes no
company row; commit-time company shares and counters are acquired in sorted order.
