# Stored timestamps

Every instant SVC writes is UTC. The app writes UTC (`config/app.php`, `WorkspaceClock::instant()`), and the MariaDB and MySQL connections pin the session to `+00:00` (`config/database.php`), so a `TIMESTAMP` column stores and reads back the instant as written. A workspace's zone decides calendar dates only: billing periods, due dates, time-sheet windows.

## Known offsets in older rows

Two earlier bugs left stored values that read wrong. The owner chose to fix both going forward only, so the old rows were deliberately not converted. Treat these windows as approximate when reading history, for example in an audit or a dispute.

### Event times in the workspace's wall clock (before #712)

- **The bug:** from #127 (merged 2026-08-30 06:29 UTC) until #712 (deployed 2026-11-13 22:28 UTC), many writers stored `WorkspaceClock::now($workspace)` as is. The driver dropped the offset, and the value read back as UTC.
- **The effect:** in a workspace off UTC, those values read early or late by the zone's offset. In America/Los_Angeles they read seven hours early until 1 November and eight hours early after.
- **The columns affected:**
  - agreement activation and signing (`activated_at`, `signed_at`);
  - proposal `sent_at` and `accepted_at`;
  - invoice `voided_at`;
  - time and expense approvals, client approvals, disputes, settlements and submissions;
  - time-entry deletions made through the agent API;
  - attachment availability and deletion;
  - reviewer grants and shared-project invitations and participations;
  - agent mutation receipts;
  - task-list archiving;
  - activity, portal-access and access-audit rows;
  - an email delivery's status time when the provider gave none.
- **Unaffected:** values written by other paths in the same columns, such as allocation's soft deletes, were UTC.

### British summer time in the database session (before the UTC pin)

- **The bug:** until the release that pinned the session, the connection took the server's default zone, which on web1 is the OS clock in Europe/London. MariaDB stored each `TIMESTAMP` the app wrote during British summer time an hour early, and read it back an hour late. So the app saw the value it wrote.
- **The effect:** under the UTC session, those values read one hour earlier than they were written. That covers values falling between the last Sunday of March and the last Sunday of October, including future-dated values such as an invitation's expiry.
- **Unaffected:** winter values, and `DATETIME` columns, which the session zone never touched.
- **Note:** a value in the hour the clocks go forward could not be stored under London time, and was shifted by up to an hour when it was written.
