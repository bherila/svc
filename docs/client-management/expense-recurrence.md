# Recurring expense drafts

Managers open **Recurring expenses** from a client's Expenses page. A schedule stores the company, optional project, amount in minor units, currency, description, first occurrence and cadence. The available cadences are monthly, quarterly, every six months and annual.

Generation is **on demand**: **Generate due drafts** materializes up to 24 occurrences through the workspace's current calendar date. No queue worker, daemon or automatic billing run creates them. If a backlog remains, the page shows the next due occurrence and keeps the action available. Each materialized expense starts as a draft and needs its own approval before invoice allocation can claim it.

Calendar arithmetic starts from the original date for every occurrence. January 31 produces February's last day and then March 31; an annual February 29 occurrence returns to February 29 in the next leap year. Editing a template changes only occurrences materialized afterward, including any backlog. The anchor, cadence and cursor cannot be edited. Pause retains the backlog; resuming allows catch-up. To use a different calendar, pause the old schedule and create another deliberately.

All edits, pauses and generation serialize on the schedule row. The cursor and new expense rows share one transaction. A unique workspace/schedule/occurrence-date key retains proof even when an occurrence is discarded, so retries cannot recreate it. Schedule deletion has no UI or write action; composite foreign keys restrict deletion rather than clearing the non-null workspace ownership column.

The manager page is paginated at 25 schedules. `pnpm test:layout` covers its initial, create and edit states at 390, 820, 1440 and 1920 with synthetic oversized company/project/description values. Occurrences use the ordinary expense lifecycle and do not inherit receipt attachments or approval stamps from other expenses.

The MariaDB test lane runs independent processes for generation/generation and both generation/pause orderings. It observes an actual InnoDB lock wait before releasing the first writer and verifies the resulting occurrence count and cursor. SQLite skips these cases explicitly.
