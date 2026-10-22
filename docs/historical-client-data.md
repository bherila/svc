# Historical client data

Client settings now holds a company's billing/contact profile and people who
belong to that company. Business contacts are independent of authenticating
`User` accounts: adding or archiving a contact never creates a login, workspace
membership, portal grant, invitation or outbound message. Only workspace owners
and admins can read or edit these details. Contact edits carry a revision and
refuse stale submissions rather than overwriting another operator's change.

Company profiles contain a billing address, phone, website, tax identifier,
preferred currency and internal notes. Updating these fields changes neither
billing automation nor existing invoice contents. Profile fields and private
contact data are hidden from default model serialization; the authorized settings
controller explicitly selects the fields it presents.

## Preserving original source facts

`HistoricalSource` identifies a source system within a workspace using a stable
identity hash. It is separate from the retained predecessor import ledger.
`HistoricalRecordRepository` preserves original business snapshots and optional
native-record links under `(workspace, source, source table, source key)`.

- Repeating the same snapshot returns the same record. Decimal strings stay
  strings and nested key ordering does not change its fingerprint.
- Changed original facts are a conflict, not an upsert. Source identities and
  preserved snapshots cannot be changed through model updates.
- Two distinct source companies can point to the same native company without
  losing their separate original names and source keys.
- A preserved record may receive its first destination link later. It cannot be
  silently retargeted to another native record.
- Both the source and the destination are queried through the selected workspace.
  Foreign sources and records are concealed as missing. Composite foreign keys
  enforce contact/company and history/source ownership in the database.
- Source reservations take the source lock before business locks. See
  [concurrency](client-management/concurrency.md#historical-sources-and-contacts).

These are preservation primitives, not a production importer. A source adapter
must audit and whitelist business fields, keep legacy credentials/runtime state
in a restricted recovery archive, map source identities explicitly, and rehearse
its transformations against synthetic fixtures and an isolated private copy.
Do not put production exports, mapping files, records or credentials in this
repository. No monetary precision or billing behavior changes in this foundation.
