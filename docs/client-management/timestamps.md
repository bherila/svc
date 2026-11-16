# Stored timestamps

Every instant SVC stores is UTC. The app writes UTC (`config/app.php`, `WorkspaceClock::instant()`), and the MariaDB session is pinned to `+00:00` (`config/database.php`, `App\Support\Database\UtcTimestampSession`), so a `TIMESTAMP` column reads back as written. A workspace's zone decides calendar dates only: billing periods, due dates, time-sheet windows.

## Before #712: event times in the workspace's wall clock

From #127 (merged 2026-08-30 06:29 UTC) until #712 (released 2026-11-13 22:28 UTC), many writers stored `WorkspaceClock::now($workspace)` as is. The driver dropped the offset, and the value read back as UTC. In America/Los_Angeles an agreement's `signed_at` read seven or eight hours early.

`2026_11_24_090000_correct_pre_712_local_timestamps` corrects them. The columns and what is known about each are in `App\Support\Database\LocalStamps\LocalStampSpec::writtenLocalBefore712()`, and `LocalStampClassifier` decides each value. Only workspaces whose zone was off UTC during that time are visited. Rows the retired importer wrote, which carry their source's times, are skipped.

### How a value is decided

1. **After #712's release:** a value stamped at or after the release is UTC.
2. **The companion:** most writers stored the local value with Eloquent, which writes `updated_at` (or `created_at` for a create) in UTC in the same statement.
   - If the two are a zone offset apart, the value is local and is corrected.
   - If they are equal, it is already UTC.
   - An invitation's `expires_at` was set fourteen days ahead of its `created_at`, so the comparison takes that away.
   - In the hour the autumn change repeats, whichever reading matches the companion wins.
3. **Before the switch:** a value earlier than any local write could have been was written in UTC before the switch.
4. **The era:** when only local writers wrote a column (`exclusive`), a value inside that time is local even if the row has been updated since.
   - The time starts two hours after the switch merged, because every deploy finished within ten minutes.
   - It ends when the old release stopped.
5. **Otherwise:** the value is left as stored and reported.

### Columns

| Table | Columns | Companion | Local since | Exclusive |
| --- | --- | --- | --- | --- |
| `client_agreements` | `activated_at`, `signed_at` | `updated_at` | #127 | yes |
| `client_proposals` | `sent_at`, `accepted_at` | `updated_at` | #127 | yes |
| `client_invoices` | `voided_at` | `updated_at` | #127 | yes |
| `client_time_entries` | `approved_at` | `updated_at` | #127 | yes |
| `client_time_entries` | `deleted_at` | `updated_at` | #127 | no: allocation soft-deletes split fragments in UTC |
| `client_time_entries`, `client_expenses` | `client_approved_at`, `client_disputed_at`, `client_dispute_settled_at`, `submitted_at` | `updated_at` | new with the column | yes |
| `client_expenses` | `approved_at` | `updated_at` | 2026-09-04 (expense approval) | yes |
| `client_invoice_email_deliveries` | `provider_status_at` | `updated_at` | #127 | no: usually the provider's event time |
| `client_attachments` | `available_at`, `deleted_at` | `updated_at` | #127 | yes |
| `reviewer_grants` | `granted_at` | `created_at` | new | yes |
| `reviewer_grants` | `revoked_at` | `updated_at` | new | yes |
| `client_project_participation_invitations` | `expires_at` | `created_at` + 14 days | new | yes |
| `client_project_participation_invitations` | `accepted_at`, `declined_at`, `revoked_at` | `updated_at` | new | yes |
| `client_project_participations` | `accepted_at` | `created_at` | new | yes |
| `client_project_participations` | `revoked_at`, `left_at` | `updated_at` | new | yes |
| `agent_mutation_receipts` | `completed_at` | `updated_at` | #127 | yes |
| `external_import_runs` | `completed_at` | `updated_at` | #127 | no |
| `client_task_lists` | `deleted_at` | `updated_at` | new | yes |
| `client_company_activity` | `created_at`, `updated_at` | none (written with `DB::table`) | #127; #111 recorded UTC for the four hours before | yes |
| `client_portal_project_access` | `created_at`, `updated_at` | none | 2026-10-10 (access boundary) | yes |
| `access_audit_events` | `created_at` | none | new | yes |

### Not corrected

- **`client_stripe_payment_methods.updated_at`:** written local when a default was replaced (`DB::table`, no companion). Other writes to the same column are UTC, and nothing marks which kind a row holds. It is bookkeeping only.
- **Participation `granted_at`:** copied from the invitation's UTC `created_at`, so it was never local.

### Reading the report

The migration logs every correction with the value it replaced (`Corrected a timestamp written in workspace-local time before #712.`). It prints and logs a summary listing the corrected counts by column and the ambiguous rows by table, column and id. An ambiguous value was left exactly as stored. To decide one by hand, compare it with an event that dates the same act: the activity row for it, the access audit row, or an email delivery. Its local reading is the stored value interpreted in the workspace's zone.
