# Private file storage plan

## Goals

SVC files can contain contracts, invoices, work product, and client-supplied
documents. They are private tenant data, not deploy artifacts. Every file must
be workspace-owned, authorized through an application route, integrity-checked,
and portable between local and object-storage Laravel disks.

## Application boundary

- Use a dedicated private Laravel disk selected by `SVC_FILESYSTEM_DISK`.
- Store only opaque object keys in domain rows. Controllers never call
  `Storage` directly; a file service performs writes, reads, hashing, and
  deletion.
- Stream downloads through an authenticated controller after workspace and
  record authorization. Do not place SVC blobs below `public/`, create a public
  storage link, or persist bearer-style public URLs.
- Use immutable blob UUIDs. A key has the shape
  `workspaces/{workspace_uuid}/{record_type}/{record_uuid}/{blob_uuid}` and
  never contains a client name, email address, invoice number, or original
  filename.
- Store the original filename as encrypted metadata. Store media type, byte
  count, SHA-256 digest, uploader, timestamps, and lifecycle state as ordinary
  metadata used for verification and retention.

## Initial deployment

The first deployment can use Laravel's local driver rooted outside the public
web tree, for example `storage/app/private/svc-blobs`. The same service contract
must support an S3-compatible disk later without changing domain models or
routes. Files are private by default on either driver.

Database writes and blob writes cannot share a transaction. Uploads therefore
use a staged key and this sequence:

1. stream the upload to a staged object while calculating size and SHA-256;
2. create the authorized attachment row in a database transaction;
3. promote the staged object to its immutable final key;
4. mark the attachment available;
5. let a scheduled repair command remove abandoned staged objects and flag
   rows whose blobs are absent or have the wrong digest.

Deletion is two-phase: hide and mark the row first, then enqueue physical blob
deletion after the retention window. A blob-reference registry must cover every
attachment model so pruning cannot delete a still-referenced object.

## Backup and migration

Backups must include both the database and the private blob root from a
consistent recovery point. Restores are verified by attachment count, total
bytes, and SHA-256 manifest before the application is reopened.

Legacy files are copied, never moved, during migration. The importer records
source identity, source path hash, destination key, byte count, and digest in a
migration ledger. A rerun is idempotent; source deletion is a separate,
explicitly approved operation after readback and backup verification.
