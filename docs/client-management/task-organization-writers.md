# Task organization writer contract

`TASK_ORGANIZATION_WRITES_ENABLED` defaults to false. It gates new management
reads, mutations and CLI discovery. Stored task ancestry and list privacy remain
enforced by the existing readers regardless of this flag. Enable it only after
the combined graph and file R1 reader floor has been deployed and verified.

The domain supports list creation, updates, archival, restoration and ordering;
task creation inside a container; subtree moves; and complete sibling ordering.
Every operation stays within one workspace and project. Only root tasks store a
list; descendants inherit their root's list. Lists start private. Archived lists
remain retained and hide descendants from Portal readers. Moving a subtree does
not change completion, billing, responsibility, ownership or access grants.

Organization analysis rejects projects above 1,000 tasks or 100 retained lists.
Ordering rejects more than 100 siblings and requires the complete current UUID
set and container revision. These limits do not affect ordinary task metadata or
assignment operations. Errors never supply omitted or foreign sibling identities.

Writes take the existing shared billing-maintenance lock, exclusive workspace
mutex, graph state, ascending task rows, ascending list rows, then current
membership rows. Graph revisions and current task/list revisions invalidate stale
organization snapshots. Both source and destination container tokens advance on
a move; list archival invalidates dependent container tokens. Native website and
local CLI use internal authority; Agent adapters deny dual Portal/staff users
before receipt creation and repeat that decision under current locks for writes
and receipt replays.

New state is initialized only inside mutations. Ordinary/current inspection and
management snapshots never create graph state. The CLI previews by rolling back
the entire database transaction, including assignments, receipts and audits.
`--apply` is required to retain writes. Retry digests keep the trusted project
identity separate from the request body.

The public snapshot excludes internal keys and descriptions. It returns bounded
topology plus optional complete sibling details, with an explicit error above
100 siblings. The maximum supported Unicode names and sibling titles fit the
default MCP result and response envelopes; smaller deployment limits need their
own transport budget handling before advertising management endpoints.

Migration `180160` is additive. Ordinary application rollback retains its schema
and data. Explicit schema rollback refuses before DDL if any graph revision or
nonzero organization position remains. A rollback that has never retained writer
state preserves existing tasks and the prerequisite graph reader schema.

Website controls and REST/MCP discovery remain integration work until the shared
foundation routes/catalog and reader deployment checkpoint are ready. The new
domain does not add parent/list mass assignment or expose organization fields in
existing task serialization.
