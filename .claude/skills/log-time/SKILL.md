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

Then resolve the repository to a project:

1. Read `git remote get-url origin` and normalize it to `host/owner/name`.
   Remove **any** `scheme://` prefix — `https`, `http`, `ssh`, `git` and
   anything else Git accepts, not only the two common ones — then the user
   information before the host, then a `:port` when it is followed by `/` or
   the end. For SCP-style SSH (`git@host:owner/name.git`), replace the colon
   after the host with a slash; its first colon starts the path, so
   `host:1234/owner/name` is a path and not a port. Drop anything from a `?` or
   `#`, remove a trailing slash and a `.git` suffix, collapse repeated slashes,
   and **lowercase the whole reference**. SVC stores the field the same way, so
   the two sides only meet if both fold case; matching on a lowercase host alone
   would miss a project someone had typed as `github.com/Owner/Name`.

   Some remotes have no canonical form and must not be forced into one. Stop and
   ask instead of guessing when the remote is a local path (`/srv/git/repo`,
   `C:/srv/git/repo`, `file://…`), when an SCP path is absolute
   (`host:/owner/name`, which is a different repository from `host:owner/name`),
   or when what is left is not `host/owner/name` at all.
2. Call `projects.list` with `limit: 100` for every authorized workspace and
   follow every `meta.next_cursor`. Do not declare a repository absent or show
   a fallback picker until all pages have been read.
3. Match the normalized remote against each project's `repository` field, which
   SVC returns already canonical — compare it as-is, do not re-normalize it. One
   match is the preferred target. Multiple matches are ambiguous: show their
   workspace, `company_name`, project name, and IDs, and ask the user to choose.
   Never silently select the first match.

If no SVC mapping matches, check `~/.claude/svc-time-projects.json`. Each remote
maps to a list so one repository can represent more than one work context:

```json
{
  "github.com/example/repo": [
    { "workspace_id": "<workspace-id>", "project_id": "<project-id>" }
  ]
}
```

Validate every override ID against the projects returned by SVC. A stale or
unknown ID is not a match. If the list contains multiple valid targets, ask the
user to choose. If there is still no match, show the fully paginated candidates
with workspace, `company_name`, project name, and IDs, and ask the user to
choose. Offer to add the choice to the override file. The file is personal and
must never be committed; prefer setting the project's `repository` in SVC.

Never infer a project from a directory name. Before preparing a write, find the
selected project in the selected workspace's `project_capabilities` from
`context.get` and require `time:write`. For a listing-only request, require
`time:read` instead. Stop with the reported capability gap before gathering a
write proposal.

## 2. Establish what was done, and when

Call `time_entries.list` for the resolved project with `limit: 100` and follow
every `meta.next_cursor`. Keep only rows whose `author_id` equals the identity
ID from `context.get`. The greatest `worked_on` value is a useful discovery
boundary, but it is not proof that earlier work is fully logged. Compare every
candidate with all of the signed-in author's returned entries and call out any
possible overlap before proposing a write.

Gather git evidence from that boundary. First read `git config user.email`. If
it is empty, stop and ask which author identity to use; `--author=''` matches
everyone.

```bash
git log --author="$(git config user.email)" --since=<last-entry-date> \
  --pretty=format:'%h %ad %s' --date=short
```

Merged pull requests are usually the better unit of description than individual
commits. For GitHub, query only the discovery period and fetch enough results to
cover it:

```bash
gh pr list --author @me --state merged --search "merged:>=<boundary-date>" \
  --limit 1000 --json number,title,mergedAt
```

Discard any result whose `mergedAt` precedes the boundary. If 1,000 results are
returned, say the evidence may be incomplete and narrow the period or paginate
through the API before proceeding.

Git provides evidence about what changed, not when the work actually happened or
how long it took. Require the user to state or confirm the actual `worked_on`
date and duration. Never infer either from commit or merge timestamps. It is
correct to say "I can see three merged PRs on Tuesday, but I need the work date
and duration."

## 3. Propose, confirm, log

Show the destination workspace, client (`company_name`), project name, and their
IDs above the proposal. Then show every entry's date, minutes, internal
description, billable flag, and client-visible flag. When client-visible is
true, also show a separate non-empty client-visible description. Get explicit
confirmation of the destination and exact rows before calling anything.

Call `time_entries.log` once for up to 20 confirmed entries. Pass one stable
top-level `idempotency_key` for the exact confirmed batch. Reuse it only to retry
an identical request; if any entry changes, generate a new key.

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
