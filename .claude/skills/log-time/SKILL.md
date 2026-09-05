---
name: log-time
description: Log work time to SVC over its MCP server from whatever repository you are working in. Use when the user asks to log, record, or bill time for work just done, wants to know what time is already logged for a project, or asks which SVC project the current repository maps to.
---

# Log time to SVC from another repository

SVC is the system of record for client time and billing. This skill is for the
common case where the work happened somewhere else — you are in a product repo,
you did some work, and it needs to land against the right client project.

It carries only the part the SVC MCP server cannot: deciding **which project the
current repository belongs to**, and turning **git activity into a defensible
time entry**. Everything after that is the server's own tools.

## Prerequisite

The `svc` MCP server must be connected for the user account, not one project:

```bash
claude mcp add --transport http --scope user svc https://svc.bherila.net/api/v1/mcp
claude mcp login svc
```

If its tools are not available, say so and stop. Do not fall back to the SVC web
UI, a database, or a local checkout — this skill has no other write path.

## 1. Resolve the project

Call `context.get` first to get the authorized identity and workspaces. Never
guess a workspace when the identity has more than one.

Then resolve the repository to a project, in this order, stopping at the first
that succeeds:

1. **The project's own `repository` field.** Read the current remote
   (`git remote get-url origin`), normalize it to `host/owner/name` — dropping
   any `git@`/`https://` prefix, `.git` suffix and trailing slash — and look for
   a project from `projects.list` whose `repository` matches. This is the
   authoritative answer when present, because the mapping is workspace data
   maintained in SVC rather than a copy living on one machine.

2. **A local override file**, `~/.claude/svc-time-projects.json`, keyed by the
   same normalized remote:

   ```json
   {
     "github.com/owner/repo": { "workspace": "<workspace-id>", "project_id": "<project-id>" }
   }
   ```

   This file is personal, lives outside any repository, and must never be
   committed to one. It is a bridge for projects that have no `repository` set
   yet — prefer setting the field in SVC over growing this file.

3. **Ask.** List the plausible projects from `projects.list` with their client
   names and let the user choose. Offer to record the choice in the override
   file for next time.

Never infer a project from a directory name alone without confirming it. Two
repositories for the same client, or one repository billed to two projects, are
both ordinary situations, and a silently wrong project bills the wrong client.

## 2. Establish what was done, and when

Call `time_entries.list` for the resolved project first, newest first. The most
recent entry's date is the boundary: work before it is probably already logged,
and re-logging it double-bills.

Gather the evidence from git, scoped to the user and to that boundary:

```bash
git log --author="$(git config user.email)" --since=<last-entry-date> \
  --pretty=format:'%h %ad %s' --date=short
```

Merged pull requests are usually the better unit of description than individual
commits. `gh pr list --author @me --state merged --json number,title,mergedAt`
gives them when the remote is GitHub.

**Never derive minutes from commit timestamps.** Commit times measure when work
was saved, not how long it took, and the gap between the two is where an
indefensible invoice comes from. Propose a duration only if the user has stated
one, and otherwise ask. It is correct to say "I can see three merged PRs on
Tuesday but I cannot tell you how long they took."

## 3. Propose, confirm, log

Show the user a table of the entries you intend to create — date, minutes,
description, billable, client-visible — and get explicit confirmation before
calling anything. Then call `time_entries.log`, which takes up to 20 entries and
is idempotent: pass a stable idempotency key per entry so a retry after a
timeout cannot duplicate the work.

Write descriptions for the person who will read them on an invoice. "Fixed the
retry loop in the webhook consumer so duplicate deliveries stop creating second
payments" bills; "misc dev work" invites a dispute.

Report back the created entry IDs, dates and minutes as the server returned
them, not as you proposed them.

## What this skill does not do

Approving time, building invoices, issuing or sending them are separate acts
with their own confirmations, and the server exposes a `prepare-invoice-safely`
prompt for them. Use that rather than reimplementing invoice assembly here.

Creating a client, a project, an agreement or a rate is not available over MCP
at all. Those are the money-defining acts and they happen in the SVC web UI.
