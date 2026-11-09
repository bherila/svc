CREATE TABLE "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE "workspaces"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "name" varchar not null,
  "slug" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "timezone" varchar not null default 'UTC',
  "default_currency" varchar not null default 'USD',
  "has_square_connection" tinyint(1) not null default '0',
  "edition" varchar not null default 'full',
  "stripe_refused_at" datetime,
  "package_catalog_token" varchar
);
CREATE UNIQUE INDEX "workspaces_public_id_unique" on "workspaces"("public_id");
CREATE UNIQUE INDEX "workspaces_slug_unique" on "workspaces"("slug");
CREATE TABLE "workspace_memberships"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "user_id" integer not null,
  "role" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "public_id" varchar not null,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "workspace_memberships_public_id_unique" on "workspace_memberships"(
  "public_id"
);
CREATE UNIQUE INDEX "workspace_memberships_workspace_id_user_id_unique" on "workspace_memberships"(
  "workspace_id",
  "user_id"
);
CREATE TABLE "client_stripe_events"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer,
  "stripe_event_id" varchar not null,
  "event_type" varchar not null,
  "object_id" varchar,
  "payload_hash" varchar not null,
  "status" varchar not null default 'received',
  "error_summary" varchar,
  "processed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete set null
);
CREATE INDEX "client_stripe_events_workspace_id_status_index" on "client_stripe_events"(
  "workspace_id",
  "status"
);
CREATE UNIQUE INDEX "client_stripe_events_public_id_unique" on "client_stripe_events"(
  "public_id"
);
CREATE UNIQUE INDEX "client_stripe_events_stripe_event_id_unique" on "client_stripe_events"(
  "stripe_event_id"
);
CREATE TABLE "external_import_runs"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "source_connection" varchar not null,
  "source_identity_hash" varchar not null,
  "mode" varchar not null,
  "status" varchar not null,
  "source_high_water_marks" text,
  "counts" text,
  "fingerprints" text,
  "started_at" datetime,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "legacy_migration_runs_public_id_unique" on "external_import_runs"(
  "public_id"
);
CREATE TABLE "external_import_items"(
  "id" integer primary key autoincrement not null,
  "external_import_run_id" integer not null,
  "source_connection" varchar not null,
  "source_identity_hash" varchar not null,
  "source_table" varchar not null,
  "source_key" varchar not null,
  "target_type" varchar not null,
  "target_public_id" varchar,
  "source_fingerprint" varchar not null,
  "status" varchar not null,
  "reason_code" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("external_import_run_id") references "external_import_runs"("id") on delete cascade
);
CREATE TABLE "external_import_run_items"(
  "id" integer primary key autoincrement not null,
  "external_import_run_id" integer not null,
  "external_import_item_id" integer not null,
  "observed_status" varchar not null,
  "source_fingerprint" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("external_import_run_id") references "external_import_runs"("id") on delete cascade,
  foreign key("external_import_item_id") references "external_import_items"("id") on delete cascade
);
CREATE TABLE "external_import_failures"(
  "id" integer primary key autoincrement not null,
  "external_import_run_id" integer not null,
  "external_import_item_id" integer,
  "source_connection" varchar not null,
  "source_table" varchar not null,
  "source_key_hash" varchar not null,
  "reason_code" varchar not null,
  "redacted_context" text,
  "failure_fingerprint" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("external_import_run_id") references "external_import_runs"("id") on delete cascade,
  foreign key("external_import_item_id") references "external_import_items"("id") on delete set null
);
CREATE TABLE "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" text not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "provider_subject" varchar,
  "provider_generation" integer
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens"(
  "expires_at"
);
CREATE TABLE "external_import_attachment_copies"(
  "id" integer primary key autoincrement not null,
  "external_import_item_id" integer not null,
  "workspace_id" integer not null,
  "client_attachment_id" integer,
  "source_path_hash" varchar not null,
  "source_sha256" varchar not null,
  "source_bytes" integer not null,
  "destination_object_key_hash" varchar not null,
  "copied_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("external_import_item_id") references "external_import_items"("id") on delete cascade,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("client_attachment_id") references "client_attachments"("id") on delete set null
);
CREATE UNIQUE INDEX "legacy_attachment_copies_legacy_migration_item_id_unique" on "external_import_attachment_copies"(
  "external_import_item_id"
);
CREATE UNIQUE INDEX "legacy_attachment_copies_client_attachment_id_unique" on "external_import_attachment_copies"(
  "client_attachment_id"
);
CREATE TABLE "oauth_auth_codes"(
  "id" varchar not null,
  "user_id" integer not null,
  "client_id" varchar not null,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  "resource_uri" varchar,
  "provider_subject" varchar,
  "provider_generation" integer,
  primary key("id")
);
CREATE INDEX "oauth_auth_codes_user_id_index" on "oauth_auth_codes"("user_id");
CREATE TABLE "oauth_access_tokens"(
  "id" varchar not null,
  "user_id" integer,
  "client_id" varchar not null,
  "name" varchar,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  "expires_at" datetime,
  "resource_uri" varchar,
  "provider_subject" varchar,
  "provider_generation" integer,
  primary key("id")
);
CREATE INDEX "oauth_access_tokens_user_id_index" on "oauth_access_tokens"(
  "user_id"
);
CREATE TABLE "oauth_refresh_tokens"(
  "id" varchar not null,
  "access_token_id" varchar not null,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  "resource_uri" text,
  "provider_subject" varchar,
  "provider_generation" integer,
  "provider_user_id" varchar,
  "client_id" varchar,
  primary key("id")
);
CREATE INDEX "oauth_refresh_tokens_access_token_id_index" on "oauth_refresh_tokens"(
  "access_token_id"
);
CREATE TABLE "oauth_clients"(
  "id" varchar not null,
  "owner_type" varchar,
  "owner_id" integer,
  "name" varchar not null,
  "secret" varchar,
  "provider" varchar,
  "redirect_uris" text not null,
  "grant_types" text not null,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  "dynamically_registered_at" datetime,
  "last_used_at" datetime,
  "scopes" text,
  primary key("id")
);
CREATE INDEX "oauth_clients_owner_type_owner_id_index" on "oauth_clients"(
  "owner_type",
  "owner_id"
);
CREATE TABLE "agent_mutation_audits"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "oauth_client_id" varchar not null,
  "workspace_id" integer not null,
  "operation" varchar not null,
  "affected_public_ids" text not null,
  "request_id" varchar not null,
  "outcome" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "error_category" varchar,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE INDEX "agent_mutation_audits_workspace_id_created_at_index" on "agent_mutation_audits"(
  "workspace_id",
  "created_at"
);
CREATE INDEX "oauth_access_tokens_resource_uri_index" on "oauth_access_tokens"(
  "resource_uri"
);
CREATE TABLE "agent_mutation_receipts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "oauth_client_id" varchar not null,
  "operation" varchar not null,
  "idempotency_key" varchar not null,
  "request_digest" varchar not null,
  "result_public_ids" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  "workspace_id" integer,
  "status" varchar not null default 'completed',
  "completed_at" datetime,
  "response_snapshot" text,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete set null
);
CREATE TABLE "workspace_invoice_counters"(
  "workspace_id" integer not null,
  "next_number" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  primary key("workspace_id")
);
CREATE INDEX "oauth_clients_dynamically_registered_at_index" on "oauth_clients"(
  "dynamically_registered_at"
);
CREATE INDEX "oauth_clients_last_used_at_index" on "oauth_clients"(
  "last_used_at"
);
CREATE UNIQUE INDEX "external_import_source_identity_unique" on "external_import_items"(
  "source_identity_hash",
  "source_table",
  "source_key"
);
CREATE INDEX "external_import_items_target_type_target_public_id_index" on "external_import_items"(
  "target_type",
  "target_public_id"
);
CREATE INDEX "external_import_items_external_import_run_id_status_index" on "external_import_items"(
  "external_import_run_id",
  "status"
);
CREATE UNIQUE INDEX "external_import_run_item_unique" on "external_import_run_items"(
  "external_import_run_id",
  "external_import_item_id"
);
CREATE INDEX "eiri_run_observed_status_idx" on "external_import_run_items"(
  "external_import_run_id",
  "observed_status"
);
CREATE INDEX "eif_run_reason_idx" on "external_import_failures"(
  "external_import_run_id",
  "reason_code"
);
CREATE UNIQUE INDEX "external_import_failure_identity_unique" on "external_import_failures"(
  "external_import_run_id",
  "source_table",
  "source_key_hash",
  "reason_code"
);
CREATE INDEX "eiac_workspace_copied_idx" on "external_import_attachment_copies"(
  "workspace_id",
  "copied_at"
);
CREATE INDEX "eir_source_identity_idx" on "external_import_runs"(
  "source_connection",
  "source_identity_hash"
);
CREATE INDEX "external_import_runs_workspace_id_created_at_index" on "external_import_runs"(
  "workspace_id",
  "created_at"
);
CREATE UNIQUE INDEX "agent_mut_receipts_workspace_idempotency_unique" on "agent_mutation_receipts"(
  "workspace_id",
  "oauth_client_id",
  "user_id",
  "operation",
  "idempotency_key"
);
CREATE TABLE "stripe_payment_method_states"(
  "id" integer primary key autoincrement not null,
  "provider_id_hash" varchar not null,
  "workspace_id" integer,
  "client_company_id" integer,
  "client_stripe_customer_id" integer,
  "state" varchar not null default 'unknown',
  "provider_created_at" integer not null default '0',
  "stripe_event_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete set null,
  foreign key("client_company_id") references "client_companies"("id") on delete set null,
  foreign key("client_stripe_customer_id") references "client_stripe_customers"("id") on delete set null
);
CREATE INDEX "stripe_payment_method_states_workspace_id_state_index" on "stripe_payment_method_states"(
  "workspace_id",
  "state"
);
CREATE UNIQUE INDEX "stripe_payment_method_states_provider_id_hash_unique" on "stripe_payment_method_states"(
  "provider_id_hash"
);
CREATE TABLE "client_company_memberships"(
  "id" integer primary key autoincrement not null,
  "client_company_id" integer not null,
  "user_id" integer not null,
  "role" varchar not null default('client'),
  "created_at" datetime,
  "updated_at" datetime,
  "public_id" varchar not null,
  "access_scope" varchar not null default('company'),
  "workspace_id" integer not null,
  "can_approve_time_and_expenses" tinyint(1),
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade
);
CREATE INDEX "ccm_workspace_idx" on "client_company_memberships"(
  "workspace_id"
);
CREATE INDEX "ccm_ws_company_idx" on "client_company_memberships"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "client_company_memberships_client_company_id_user_id_unique" on "client_company_memberships"(
  "client_company_id",
  "user_id"
);
CREATE UNIQUE INDEX "client_company_memberships_public_id_unique" on "client_company_memberships"(
  "public_id"
);
CREATE UNIQUE INDEX "client_company_memberships_workspace_id_id_unique" on "client_company_memberships"(
  "workspace_id",
  "id"
);
CREATE TABLE "client_company_activity"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "actor_user_id" integer,
  "action" varchar not null,
  "subject_type" varchar,
  "external_subject_id" integer,
  "payload" text,
  "created_at" datetime,
  "updated_at" datetime,
  "subject_public_id" varchar,
  "deduplication_key" varchar,
  foreign key("actor_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cca_workspace_company_created_idx" on "client_company_activity"(
  "workspace_id",
  "client_company_id",
  "created_at"
);
CREATE UNIQUE INDEX "cca_workspace_deduplication_unique" on "client_company_activity"(
  "workspace_id",
  "deduplication_key"
);
CREATE INDEX "cca_workspace_subject_public_idx" on "client_company_activity"(
  "workspace_id",
  "subject_type",
  "subject_public_id"
);
CREATE UNIQUE INDEX "client_company_activity_public_id_unique" on "client_company_activity"(
  "public_id"
);
CREATE TABLE "client_proposals"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "created_by_user_id" integer,
  "title" varchar not null,
  "summary" text,
  "terms" text,
  "currency" varchar not null,
  "is_visible_to_client" tinyint(1) not null default('0'),
  "valid_until" date,
  "status" varchar not null default('draft'),
  "sent_at" datetime,
  "accepted_at" datetime,
  "declined_at" datetime,
  "expired_at" datetime,
  "accepted_by_user_id" integer,
  "acceptance_signer_name" varchar,
  "acceptance_signer_title" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default '1',
  foreign key("accepted_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("created_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_project_id") references "client_projects"("id") on delete set null on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade
);
CREATE INDEX "client_proposals_client_company_id_status_index" on "client_proposals"(
  "client_company_id",
  "status"
);
CREATE UNIQUE INDEX "client_proposals_public_id_unique" on "client_proposals"(
  "public_id"
);
CREATE INDEX "client_proposals_workspace_id_client_company_id_index" on "client_proposals"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "client_proposals_workspace_id_id_unique" on "client_proposals"(
  "workspace_id",
  "id"
);
CREATE INDEX "client_proposals_workspace_id_status_index" on "client_proposals"(
  "workspace_id",
  "status"
);
CREATE TABLE "client_proposal_items"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_proposal_id" integer not null,
  "description" text not null,
  "quantity" numeric not null,
  "unit_amount" integer not null,
  "cadence" varchar not null default('one_time'),
  "sort_order" integer not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_proposal_id") references "client_proposals"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_proposal_id") references "client_proposals"("workspace_id", "id") on delete cascade
);
CREATE INDEX "client_proposal_items_client_proposal_id_sort_order_index" on "client_proposal_items"(
  "client_proposal_id",
  "sort_order"
);
CREATE UNIQUE INDEX "client_proposal_items_public_id_unique" on "client_proposal_items"(
  "public_id"
);
CREATE INDEX "client_proposal_items_workspace_id_client_proposal_id_index" on "client_proposal_items"(
  "workspace_id",
  "client_proposal_id"
);
CREATE TABLE "client_agreement_recurring_items"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_agreement_id" integer not null,
  "description" text not null,
  "cadence" varchar not null default('monthly'),
  "anchor_month" integer,
  "anchor_day" integer,
  "effective_on" date,
  "expires_on" date,
  "quantity" numeric not null default('1'),
  "amount" integer not null,
  "currency" varchar not null,
  "is_taxable" tinyint(1) not null default('0'),
  "is_active" tinyint(1) not null default('1'),
  "sort_order" integer not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_agreement_id") references "client_agreements"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_agreement_id") references "client_agreements"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cari_agreement_active_idx" on "client_agreement_recurring_items"(
  "client_agreement_id",
  "is_active"
);
CREATE INDEX "cari_workspace_agreement_idx" on "client_agreement_recurring_items"(
  "workspace_id",
  "client_agreement_id"
);
CREATE UNIQUE INDEX "client_agreement_recurring_items_public_id_unique" on "client_agreement_recurring_items"(
  "public_id"
);
CREATE TABLE "client_billing_schedules"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_agreement_id" integer not null,
  "cadence" varchar not null,
  "anchor_month" integer,
  "anchor_day" integer,
  "next_run_on" date not null,
  "due_days" integer not null default('0'),
  "currency" varchar not null,
  "is_active" tinyint(1) not null default('1'),
  "line_template" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default '1',
  foreign key("client_company_id") references "client_companies"("id") on delete restrict on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_agreement_id") references "client_agreements"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "billing_schedule_agreement_unique" on "client_billing_schedules"(
  "workspace_id",
  "client_agreement_id"
);
CREATE INDEX "cbs_workspace_active_next_run_idx" on "client_billing_schedules"(
  "workspace_id",
  "is_active",
  "next_run_on"
);
CREATE INDEX "cbs_ws_company_idx" on "client_billing_schedules"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "client_billing_schedules_public_id_unique" on "client_billing_schedules"(
  "public_id"
);
CREATE TABLE "client_project_memberships"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_project_id" integer not null,
  "user_id" integer not null,
  "role" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id", "user_id") references "workspace_memberships"("workspace_id", "user_id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_project_id") references "client_projects"("id") on delete cascade on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "client_project_memberships_client_project_id_user_id_unique" on "client_project_memberships"(
  "client_project_id",
  "user_id"
);
CREATE UNIQUE INDEX "client_project_memberships_public_id_unique" on "client_project_memberships"(
  "public_id"
);
CREATE INDEX "client_project_memberships_workspace_id_user_id_index" on "client_project_memberships"(
  "workspace_id",
  "user_id"
);
CREATE INDEX "cpm_ws_project_idx" on "client_project_memberships"(
  "workspace_id",
  "client_project_id"
);
CREATE TABLE "client_portal_project_access"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_company_membership_id" integer not null,
  "client_project_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_project_id") references "client_projects"("id") on delete cascade on update no action,
  foreign key("client_company_membership_id") references "client_company_memberships"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade,
  foreign key("workspace_id", "client_company_membership_id") references "client_company_memberships"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cppa_ws_membership_idx" on "client_portal_project_access"(
  "workspace_id",
  "client_company_membership_id"
);
CREATE UNIQUE INDEX "portal_project_access_unique" on "client_portal_project_access"(
  "client_company_membership_id",
  "client_project_id"
);
CREATE INDEX "portal_project_access_workspace_idx" on "client_portal_project_access"(
  "workspace_id",
  "client_project_id"
);
CREATE TABLE "client_invoices"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_agreement_id" integer,
  "client_billing_schedule_id" integer,
  "invoice_number" varchar not null,
  "status" varchar not null default('draft'),
  "issue_date" date,
  "due_date" date,
  "service_period_start" date,
  "service_period_end" date,
  "currency" varchar not null,
  "subtotal_amount" integer not null default('0'),
  "tax_amount" integer not null default('0'),
  "total_amount" integer not null default('0'),
  "paid_amount" integer not null default('0'),
  "balance_amount" integer not null default('0'),
  "notes" text,
  "is_visible_to_client" tinyint(1) not null default('0'),
  "issued_at" datetime,
  "voided_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default('1'),
  "void_reason" text,
  "invoice_kind" varchar,
  "cycle_start" date,
  "cycle_end" date,
  "paid_on" date,
  "retainer_hours_included" numeric,
  "hours_worked" numeric,
  "rollover_hours_used" numeric,
  "unused_hours_balance" numeric,
  "negative_hours_balance" numeric,
  "hours_billed_at_rate" numeric,
  "starting_unused_hours" numeric,
  "starting_negative_hours" numeric,
  "document_revision" integer not null default '0',
  "automatic_delivery_status" varchar,
  "automatic_delivery_delay_days" integer,
  "automatic_delivery_due_at" datetime,
  "automatic_delivery_held_at" datetime,
  "automatic_delivery_note" varchar,
  "hours_statement" text,
  "catch_up_basis" text,
  "scheduled_issue_date" date,
  "reopened_at" datetime,
  "reopen_reason" text,
  "reissued_at" datetime,
  "deferred_work_statement" text,
  "held_work_basis" text,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete restrict on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "billing_schedule_service_period_unique" on "client_invoices"(
  "client_billing_schedule_id",
  "service_period_start",
  "service_period_end"
);
CREATE UNIQUE INDEX "client_invoices_public_id_unique" on "client_invoices"(
  "public_id"
);
CREATE INDEX "client_invoices_workspace_id_client_company_id_index" on "client_invoices"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "client_invoices_workspace_id_id_unique" on "client_invoices"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "client_invoices_workspace_id_invoice_number_unique" on "client_invoices"(
  "workspace_id",
  "invoice_number"
);
CREATE INDEX "client_invoices_workspace_id_status_index" on "client_invoices"(
  "workspace_id",
  "status"
);
CREATE TABLE "client_invoice_lines"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "type" varchar not null,
  "description" text not null,
  "quantity" numeric not null,
  "unit_amount" integer not null,
  "tax_amount" integer not null default('0'),
  "total_amount" integer not null,
  "sort_order" integer not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "client_project_id" integer,
  "line_date" date,
  "hours" numeric,
  "client_agreement_id" integer,
  "client_agreement_recurring_item_id" integer,
  foreign key("client_project_id") references "client_projects"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_invoice_id") references "client_invoices"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cil_workspace_agreement_idx" on "client_invoice_lines"(
  "workspace_id",
  "client_agreement_id"
);
CREATE UNIQUE INDEX "client_invoice_lines_public_id_unique" on "client_invoice_lines"(
  "public_id"
);
CREATE INDEX "client_invoice_lines_workspace_id_client_invoice_id_index" on "client_invoice_lines"(
  "workspace_id",
  "client_invoice_id"
);
CREATE INDEX "client_invoice_lines_workspace_id_client_project_id_index" on "client_invoice_lines"(
  "workspace_id",
  "client_project_id"
);
CREATE UNIQUE INDEX "client_invoice_lines_workspace_id_id_unique" on "client_invoice_lines"(
  "workspace_id",
  "id"
);
CREATE TABLE "client_invoice_line_time_entries"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_invoice_line_id" integer not null,
  "client_time_entry_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_invoice_line_id") references "client_invoice_lines"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_line_id") references "client_invoice_lines"("workspace_id", "id") on delete cascade,
  foreign key("workspace_id", "client_time_entry_id") references "client_time_entries"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cilte_ws_line_idx" on "client_invoice_line_time_entries"(
  "workspace_id",
  "client_invoice_line_id"
);
CREATE UNIQUE INDEX "invoice_line_time_entry_unique" on "client_invoice_line_time_entries"(
  "client_invoice_line_id",
  "client_time_entry_id"
);
CREATE UNIQUE INDEX "invoice_time_entry_once" on "client_invoice_line_time_entries"(
  "workspace_id",
  "client_time_entry_id"
);
CREATE TABLE "client_invoice_payments"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "status" varchar not null default('pending'),
  "amount" integer not null,
  "refunded_amount" integer not null default('0'),
  "currency" varchar not null,
  "received_on" date,
  "method" varchar not null,
  "reference" varchar,
  "notes" text,
  "provider" varchar,
  "provider_payment_identifier" varchar,
  "external_finance_transaction_uuid" varchar,
  "idempotency_key" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "provider_event_created_at" integer,
  "provider_event_id" varchar,
  "lock_version" integer not null default '1',
  "disputed_amount" integer,
  "provider_dispute_updated_at_ms" integer,
  "provider_charged_at" datetime,
  "provider_fee_amount" integer,
  "provider_payout_identifier" varchar,
  "provider_payout_arrival_date" date,
  "provider_payout_reported_at_ms" integer,
  foreign key("client_invoice_id") references "client_invoices"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cip_workspace_invoice_status_idx" on "client_invoice_payments"(
  "workspace_id",
  "client_invoice_id",
  "status"
);
CREATE UNIQUE INDEX "client_invoice_payments_public_id_unique" on "client_invoice_payments"(
  "public_id"
);
CREATE UNIQUE INDEX "client_invoice_payments_workspace_id_id_unique" on "client_invoice_payments"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "payment_idempotency_unique" on "client_invoice_payments"(
  "workspace_id",
  "idempotency_key"
);
CREATE UNIQUE INDEX "provider_payment_unique" on "client_invoice_payments"(
  "provider",
  "provider_payment_identifier"
);
CREATE TABLE "client_invoice_email_deliveries"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "recipients" text not null,
  "subject" varchar not null,
  "status" varchar not null default('pending'),
  "provider_message_reference" varchar,
  "error_summary" varchar,
  "queued_at" datetime,
  "sent_at" datetime,
  "failed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "external_metadata" text,
  "bcc" text,
  "body" text,
  "provider_status" varchar,
  "provider_status_at" datetime,
  "origin" varchar not null default 'manual',
  "invoice_revision" integer not null default '0',
  "attempt_number" integer not null default '1',
  "idempotency_key" varchar,
  "claimed_at" datetime,
  "next_attempt_at" datetime,
  "is_reissue" tinyint(1) not null default '0',
  foreign key("client_invoice_id") references "client_invoices"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cied_workspace_invoice_status_idx" on "client_invoice_email_deliveries"(
  "workspace_id",
  "client_invoice_id",
  "status"
);
CREATE UNIQUE INDEX "client_invoice_email_deliveries_public_id_unique" on "client_invoice_email_deliveries"(
  "public_id"
);
CREATE TABLE "payment_reconciliations"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_payment_id" integer not null,
  "external_system_slug" varchar not null,
  "external_transaction_uuid" varchar not null,
  "allocated_amount" integer not null,
  "currency" varchar not null,
  "reconciled_on" date,
  "created_by_user_id" integer,
  "is_active" tinyint(1) not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  "review_reason" varchar,
  "review_flagged_at" datetime,
  foreign key("created_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_invoice_payment_id") references "client_invoice_payments"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_payment_id") references "client_invoice_payments"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "pr_payment_system_transaction_unique" on "payment_reconciliations"(
  "workspace_id",
  "client_invoice_payment_id",
  "external_system_slug",
  "external_transaction_uuid"
);
CREATE UNIQUE INDEX "pr_public_id_unique" on "payment_reconciliations"(
  "public_id"
);
CREATE INDEX "pr_workspace_payment_active_idx" on "payment_reconciliations"(
  "workspace_id",
  "client_invoice_payment_id",
  "is_active"
);
CREATE INDEX "pr_workspace_system_transaction_idx" on "payment_reconciliations"(
  "workspace_id",
  "external_system_slug",
  "external_transaction_uuid"
);
CREATE TABLE "client_stripe_customers"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "stripe_customer_id" varchar not null,
  "metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  "default_payment_method_revision" integer not null default '0',
  foreign key("client_company_id") references "client_companies"("id") on delete restrict on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "client_stripe_customers_public_id_unique" on "client_stripe_customers"(
  "public_id"
);
CREATE UNIQUE INDEX "client_stripe_customers_stripe_customer_id_unique" on "client_stripe_customers"(
  "stripe_customer_id"
);
CREATE UNIQUE INDEX "client_stripe_customers_workspace_id_client_company_id_unique" on "client_stripe_customers"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "client_stripe_customers_workspace_id_id_unique" on "client_stripe_customers"(
  "workspace_id",
  "id"
);
CREATE TABLE "client_stripe_payment_methods"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_stripe_customer_id" integer not null,
  "stripe_payment_method_id" varchar not null,
  "type" varchar not null,
  "brand" varchar,
  "last4" varchar,
  "exp_month" integer,
  "exp_year" integer,
  "is_default" tinyint(1) not null default('0'),
  "metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("client_stripe_customer_id") references "client_stripe_customers"("id") on delete cascade on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete restrict on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_stripe_customer_id") references "client_stripe_customers"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "client_stripe_payment_methods_public_id_unique" on "client_stripe_payment_methods"(
  "public_id"
);
CREATE UNIQUE INDEX "client_stripe_payment_methods_stripe_payment_method_id_unique" on "client_stripe_payment_methods"(
  "stripe_payment_method_id"
);
CREATE INDEX "cspm_workspace_company_idx" on "client_stripe_payment_methods"(
  "workspace_id",
  "client_company_id"
);
CREATE INDEX "cspm_ws_customer_idx" on "client_stripe_payment_methods"(
  "workspace_id",
  "client_stripe_customer_id"
);
CREATE TABLE "client_agreements"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "source_proposal_id" integer,
  "title" varchar not null,
  "status" varchar not null default('draft'),
  "starts_on" date not null,
  "ends_on" date,
  "agreement_text" text,
  "is_visible_to_client" tinyint(1) not null default('0'),
  "currency" varchar not null,
  "hourly_rate_amount" integer,
  "retainer_amount" integer,
  "retainer_minutes" integer,
  "billing_cadence" varchar not null default('one_time'),
  "rollover_policy" varchar,
  "activated_at" datetime,
  "signed_at" datetime,
  "signed_by_user_id" integer,
  "signer_name" varchar,
  "signer_title" varchar,
  "terminated_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "catch_up_threshold_minutes" integer,
  "period_retainer_minutes" integer,
  "period_retainer_amount" integer,
  "rollover_months" integer,
  "initial_rollover_minutes" integer,
  "bill_overage_interim" tinyint(1),
  "first_cycle_proration" varchar,
  "agreement_link" varchar,
  "lock_version" integer not null default '1',
  "square_series_key" varchar,
  "show_deferred_backlog_to_client" tinyint(1) not null default '0',
  "square_location_id" varchar,
  "notice_days" integer not null default '0',
  "minimum_term_months" integer,
  "cancellation_mode" varchar not null default 'none',
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("client_project_id") references "client_projects"("id") on delete set null on update no action,
  foreign key("source_proposal_id") references "client_proposals"("id") on delete set null on update no action,
  foreign key("signed_by_user_id") references "users"("id") on delete set null on update no action
);
CREATE INDEX "client_agreements_client_company_id_is_visible_to_client_index" on "client_agreements"(
  "client_company_id",
  "is_visible_to_client"
);
CREATE UNIQUE INDEX "client_agreements_public_id_unique" on "client_agreements"(
  "public_id"
);
CREATE UNIQUE INDEX "client_agreements_source_proposal_id_unique" on "client_agreements"(
  "source_proposal_id"
);
CREATE INDEX "client_agreements_workspace_id_client_company_id_index" on "client_agreements"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "client_agreements_workspace_id_id_unique" on "client_agreements"(
  "workspace_id",
  "id"
);
CREATE INDEX "client_agreements_workspace_id_status_index" on "client_agreements"(
  "workspace_id",
  "status"
);
CREATE TABLE "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "oauth_provider" varchar,
  "oauth_subject" varchar,
  "public_id" varchar not null,
  "last_workspace_id" integer,
  "last_client_company_id" integer,
  "duration_style" varchar not null default 'decimal',
  foreign key("last_workspace_id") references "workspaces"("id") on delete set null,
  foreign key("last_client_company_id") references "client_companies"("id") on delete set null
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE UNIQUE INDEX "users_oauth_provider_oauth_subject_unique" on "users"(
  "oauth_provider",
  "oauth_subject"
);
CREATE UNIQUE INDEX "users_public_id_unique" on "users"("public_id");
CREATE INDEX "invoice_delivery_provider_reference_idx" on "client_invoice_email_deliveries"(
  "provider_message_reference"
);
CREATE TABLE "client_expense_schedules"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "starts_on" date not null,
  "cadence" varchar not null,
  "next_occurrence" integer not null default '0',
  "amount" integer not null,
  "currency" varchar not null,
  "description" text not null,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default '1',
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "ces_ws_id_unique" on "client_expense_schedules"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "client_expense_schedules_public_id_unique" on "client_expense_schedules"(
  "public_id"
);
CREATE INDEX "ci_automatic_delivery_due_idx" on "client_invoices"(
  "automatic_delivery_status",
  "automatic_delivery_due_at",
  "id"
);
CREATE INDEX "cied_dispatch_due_idx" on "client_invoice_email_deliveries"(
  "workspace_id",
  "origin",
  "status",
  "next_attempt_at"
);
CREATE UNIQUE INDEX "cied_idempotency_unique" on "client_invoice_email_deliveries"(
  "workspace_id",
  "idempotency_key"
);
CREATE TABLE "client_invoice_administrator_notifications"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "recipient_user_id" integer,
  "invoice_revision" integer not null,
  "recipient" varchar,
  "subject" varchar not null,
  "open_url" varchar not null,
  "client_name" varchar not null,
  "invoice_number" varchar not null,
  "currency" varchar not null,
  "total_amount" integer not null,
  "pdf_filename" varchar,
  "pdf_content_base64" text,
  "status" varchar not null default 'pending',
  "attempt_count" integer not null default '0',
  "attempt_history" text,
  "next_attempt_at" datetime,
  "claimed_at" datetime,
  "sent_at" datetime,
  "failed_at" datetime,
  "provider_message_reference" varchar,
  "error_summary" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("client_invoice_id") references "client_invoices"("id") on delete cascade,
  foreign key("recipient_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "cian_invoice_revision_unique" on "client_invoice_administrator_notifications"(
  "client_invoice_id",
  "invoice_revision"
);
CREATE INDEX "cian_dispatch_due_idx" on "client_invoice_administrator_notifications"(
  "status",
  "next_attempt_at",
  "id"
);
CREATE UNIQUE INDEX "client_invoice_administrator_notifications_public_id_unique" on "client_invoice_administrator_notifications"(
  "public_id"
);
CREATE TABLE "agent_workspace_creations"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "oauth_client_id" varchar not null,
  "idempotency_key" varchar not null,
  "request_digest" varchar not null,
  "created_workspace_id" integer,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("created_workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "agent_workspace_creation_actor_key" on "agent_workspace_creations"(
  "user_id",
  "oauth_client_id",
  "idempotency_key"
);
CREATE TABLE "access_audit_events"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "actor_type" varchar not null,
  "actor_user_id" integer,
  "subject_user_id" integer,
  "action" varchar not null,
  "client_company_public_id" varchar,
  "before_state" text,
  "after_state" text,
  "operation_id" varchar,
  "request_id" varchar,
  "created_at" datetime not null default CURRENT_TIMESTAMP,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("actor_user_id") references "users"("id") on delete set null,
  foreign key("subject_user_id") references "users"("id") on delete set null
);
CREATE INDEX "access_audit_events_workspace_id_created_at_index" on "access_audit_events"(
  "workspace_id",
  "created_at"
);
CREATE INDEX "access_audit_events_workspace_id_operation_id_index" on "access_audit_events"(
  "workspace_id",
  "operation_id"
);
CREATE UNIQUE INDEX "access_audit_events_public_id_unique" on "access_audit_events"(
  "public_id"
);
CREATE INDEX "oauth_refresh_tokens_provider_user_id_index" on "oauth_refresh_tokens"(
  "provider_user_id"
);
CREATE TABLE "bherila_auth_delegated_nonces"(
  "key" varchar not null,
  "expires_at" integer not null,
  primary key("key")
);
CREATE INDEX "bherila_auth_delegated_nonces_expires_at_index" on "bherila_auth_delegated_nonces"(
  "expires_at"
);
CREATE TABLE "bherila_auth_delegated_receipts"(
  "application" varchar not null,
  "operation_key" varchar not null,
  "operation_id" varchar not null,
  "actor" varchar not null,
  "request_hash" varchar not null,
  "status" integer,
  "response" text,
  "claimed_at" integer not null,
  "created_at" integer not null,
  primary key("application", "operation_key")
);
CREATE INDEX "bherila_auth_delegated_receipts_created_at_index" on "bherila_auth_delegated_receipts"(
  "created_at"
);
CREATE INDEX "oauth_refresh_tokens_client_id_index" on "oauth_refresh_tokens"(
  "client_id"
);
CREATE TABLE "client_invoice_corrections"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "from_revision" integer not null,
  "to_revision" integer not null,
  "reason" text not null,
  "previous_due_date" date,
  "due_date" date,
  "line_diffs" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "invoice_correction_revision_unique" on "client_invoice_corrections"(
  "workspace_id",
  "client_invoice_id",
  "to_revision"
);
CREATE UNIQUE INDEX "client_invoice_corrections_public_id_unique" on "client_invoice_corrections"(
  "public_id"
);
CREATE TABLE "billing_maintenance_locks"(
  "workspace_id" integer not null,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  primary key("workspace_id")
);
CREATE TABLE "client_portal_invitations"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "email" varchar not null,
  "pending_email" varchar,
  "access_scope" varchar not null,
  "project_ids" text not null,
  "inviter_user_id" integer,
  "provider_invitation_id" integer,
  "provider_revocation_pending" tinyint(1) not null default '0',
  "link" text,
  "email_sent" tinyint(1) not null default '0',
  "expires_at" datetime,
  "status" varchar not null,
  "not_applied_reason" varchar,
  "accepted_user_id" integer,
  "resolved_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("inviter_user_id") references "users"("id") on delete set null,
  foreign key("accepted_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "portal_invitation_pending_unique" on "client_portal_invitations"(
  "workspace_id",
  "client_company_id",
  "pending_email"
);
CREATE INDEX "portal_invitation_email_status_idx" on "client_portal_invitations"(
  "email",
  "status"
);
CREATE INDEX "portal_invitation_company_status_idx" on "client_portal_invitations"(
  "workspace_id",
  "client_company_id",
  "status"
);
CREATE UNIQUE INDEX "client_portal_invitations_public_id_unique" on "client_portal_invitations"(
  "public_id"
);
CREATE TABLE "invoice_square_links"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "square_invoice_id" varchar not null,
  "square_invoice_number" varchar not null,
  "series_key" varchar,
  "currency" varchar not null,
  "match_method" varchar not null,
  "score" integer,
  "reasons" text,
  "active_invoice_slot" integer,
  "active_square_invoice_slot" varchar,
  "linked_by_user_id" integer,
  "linked_at" datetime not null,
  "unlinked_by_user_id" integer,
  "unlinked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("linked_by_user_id") references "users"("id") on delete set null,
  foreign key("unlinked_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "isql_active_invoice_unique" on "invoice_square_links"(
  "workspace_id",
  "active_invoice_slot"
);
CREATE UNIQUE INDEX "isql_active_square_unique" on "invoice_square_links"(
  "workspace_id",
  "active_square_invoice_slot"
);
CREATE INDEX "isql_ws_invoice_idx" on "invoice_square_links"(
  "workspace_id",
  "client_invoice_id"
);
CREATE INDEX "isql_ws_square_idx" on "invoice_square_links"(
  "workspace_id",
  "square_invoice_id"
);
CREATE UNIQUE INDEX "invoice_square_links_public_id_unique" on "invoice_square_links"(
  "public_id"
);
CREATE TABLE "square_sync_runs"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "trigger" varchar not null,
  "status" varchar not null,
  "requested_by_user_id" integer,
  "api_version" varchar not null,
  "started_at" datetime,
  "finished_at" datetime,
  "locations_seen" integer not null default '0',
  "customers_seen" integer not null default '0',
  "customers_changed" integer not null default '0',
  "invoices_seen" integer not null default '0',
  "invoices_changed" integer not null default '0',
  "payments_resolved" integer not null default '0',
  "high_water_mark" datetime,
  "truncated" tinyint(1) not null default '0',
  "error_code" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("requested_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "sqsr_ws_status_idx" on "square_sync_runs"(
  "workspace_id",
  "status",
  "finished_at"
);
CREATE UNIQUE INDEX "square_sync_runs_public_id_unique" on "square_sync_runs"(
  "public_id"
);
CREATE TABLE "square_location_snapshots"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "square_location_id" varchar not null,
  "name" varchar,
  "business_name" varchar,
  "status" varchar,
  "currency" varchar,
  "timezone" varchar,
  "fingerprint" varchar not null,
  "synced_at" datetime not null,
  "last_seen_at" datetime not null,
  "last_sync_run_id" integer,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "sqls_ws_location_unique" on "square_location_snapshots"(
  "workspace_id",
  "square_location_id"
);
CREATE TABLE "square_customer_snapshots"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "square_customer_id" varchar not null,
  "square_version" integer,
  "given_name" varchar,
  "family_name" varchar,
  "company_name" varchar,
  "email_address" varchar,
  "phone_number" varchar,
  "reference_id" varchar,
  "square_created_at" datetime,
  "square_updated_at" datetime,
  "fingerprint" varchar not null,
  "synced_at" datetime not null,
  "last_seen_at" datetime not null,
  "last_sync_run_id" integer,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "sqcs_ws_customer_unique" on "square_customer_snapshots"(
  "workspace_id",
  "square_customer_id"
);
CREATE INDEX "sqcs_ws_email_idx" on "square_customer_snapshots"(
  "workspace_id",
  "email_address"
);
CREATE TABLE "company_square_customers"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "square_customer_id" varchar not null,
  "active_company_slot" integer,
  "active_customer_slot" varchar,
  "confirmed_by_user_id" integer,
  "confirmed_at" datetime not null,
  "cleared_by_user_id" integer,
  "cleared_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade on update no action,
  foreign key("cleared_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("confirmed_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "company_square_customers_public_id_unique" on "company_square_customers"(
  "public_id"
);
CREATE UNIQUE INDEX "csqc_active_company_unique" on "company_square_customers"(
  "workspace_id",
  "active_company_slot"
);
CREATE UNIQUE INDEX "csqc_active_customer_unique" on "company_square_customers"(
  "workspace_id",
  "active_customer_slot"
);
CREATE INDEX "csqc_ws_company_idx" on "company_square_customers"(
  "workspace_id",
  "client_company_id"
);
CREATE TABLE "square_webhook_receipts"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "event_hash" varchar not null,
  "event_type" varchar not null,
  "received_at" datetime not null,
  "claim_token" varchar,
  "lease_expires_at" datetime,
  "completed_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "square_webhook_event_unique" on "square_webhook_receipts"(
  "workspace_id",
  "event_hash"
);
CREATE INDEX "square_webhook_retention_index" on "square_webhook_receipts"(
  "workspace_id",
  "received_at"
);
CREATE TABLE "invoice_reminder_logs"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "origin" varchar not null,
  "cadence_step" varchar,
  "channel" varchar not null,
  "pay_link" varchar not null,
  "square_invoice_number" varchar,
  "recipients" text not null,
  "subject" varchar not null,
  "currency" varchar not null,
  "amount_due" integer not null,
  "due_on" date,
  "status" varchar not null,
  "attempt_number" integer not null default '1',
  "triggered_by" varchar not null,
  "triggered_by_user_id" integer,
  "oauth_client_id" varchar,
  "provider_message_reference" varchar,
  "error_summary" varchar,
  "queued_at" datetime not null,
  "claimed_at" datetime,
  "sent_at" datetime,
  "failed_at" datetime,
  "next_attempt_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("triggered_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "irl_step_claim_unique" on "invoice_reminder_logs"(
  "workspace_id",
  "client_invoice_id",
  "cadence_step"
);
CREATE INDEX "irl_ws_invoice_idx" on "invoice_reminder_logs"(
  "workspace_id",
  "client_invoice_id",
  "id"
);
CREATE INDEX "irl_retry_idx" on "invoice_reminder_logs"(
  "status",
  "next_attempt_at"
);
CREATE UNIQUE INDEX "invoice_reminder_logs_public_id_unique" on "invoice_reminder_logs"(
  "public_id"
);
CREATE TABLE "client_invoice_payment_refunds"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_payment_id" integer not null,
  "kind" varchar not null,
  "amount" integer not null,
  "reason" varchar not null,
  "reverses_refund_id" integer,
  "recorded_by_user_id" integer,
  "created_at" datetime not null,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("recorded_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_invoice_payment_id") references "client_invoice_payments"("workspace_id", "id") on delete cascade,
  foreign key("workspace_id", "reverses_refund_id") references "client_invoice_payment_refunds"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "cipr_ws_id_unique" on "client_invoice_payment_refunds"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "cipr_reverses_unique" on "client_invoice_payment_refunds"(
  "reverses_refund_id"
);
CREATE INDEX "cipr_ws_payment_idx" on "client_invoice_payment_refunds"(
  "workspace_id",
  "client_invoice_payment_id",
  "id"
);
CREATE UNIQUE INDEX "cipr_public_id_unique" on "client_invoice_payment_refunds"(
  "public_id"
);
CREATE INDEX "client_invoices_ws_updated_id_index" on "client_invoices"(
  "workspace_id",
  "updated_at",
  "id"
);
CREATE INDEX "client_invoice_payments_ws_updated_id_index" on "client_invoice_payments"(
  "workspace_id",
  "updated_at",
  "id"
);
CREATE INDEX "client_invoice_payments_ws_payout_index" on "client_invoice_payments"(
  "workspace_id",
  "provider",
  "provider_payout_identifier"
);
CREATE TABLE "processor_payouts"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "provider" varchar not null,
  "provider_payout_identifier" varchar not null,
  "amount" integer not null,
  "currency" varchar not null,
  "arrival_date" date,
  "type" varchar not null,
  "status" varchar not null,
  "provider_updated_at_ms" integer not null,
  "lock_version" integer not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "processor_payouts_ws_provider_id_unique" on "processor_payouts"(
  "workspace_id",
  "provider",
  "provider_payout_identifier"
);
CREATE INDEX "processor_payouts_ws_updated_id_index" on "processor_payouts"(
  "workspace_id",
  "updated_at",
  "id"
);
CREATE INDEX "processor_payouts_ws_arrival_id_index" on "processor_payouts"(
  "workspace_id",
  "arrival_date",
  "id"
);
CREATE UNIQUE INDEX "processor_payouts_public_id_unique" on "processor_payouts"(
  "public_id"
);
CREATE TABLE "square_pending_settlements"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "payment_identifier" varchar not null,
  "payout_identifier" varchar not null,
  "arrival_date" date,
  "fee_amount" integer,
  "fee_currency" varchar,
  "reported_at_ms" integer not null,
  "received_at" datetime not null,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "square_pending_ws_payment_unique" on "square_pending_settlements"(
  "workspace_id",
  "payment_identifier"
);
CREATE INDEX "square_pending_retention_index" on "square_pending_settlements"(
  "workspace_id",
  "received_at",
  "id"
);
CREATE TABLE "oauth_credential_revocations"(
  "scope_key" varchar not null,
  "sequence" integer not null default '0',
  primary key("scope_key")
);
CREATE TABLE "oauth_credential_lineages"(
  "id" varchar not null,
  "owner_key" varchar not null,
  "client_key" varchar not null,
  "owner_id" varchar,
  "client_id" varchar not null,
  "issued_sequence" integer not null,
  "revoked" tinyint(1) not null default '0',
  primary key("id")
);
CREATE INDEX "oauth_credential_lineages_owner_key_index" on "oauth_credential_lineages"(
  "owner_key"
);
CREATE INDEX "oauth_credential_lineages_client_key_index" on "oauth_credential_lineages"(
  "client_key"
);
CREATE TABLE "oauth_credential_members"(
  "credential_key" varchar not null,
  "lineage_id" varchar not null,
  "kind" varchar not null,
  "credential_id" varchar not null,
  primary key("credential_key")
);
CREATE INDEX "oauth_credential_members_lineage_id_index" on "oauth_credential_members"(
  "lineage_id"
);
CREATE TABLE "square_invoice_snapshots"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "square_invoice_id" varchar not null,
  "square_version" integer,
  "square_location_id" varchar,
  "square_order_id" varchar,
  "square_customer_id" varchar,
  "square_subscription_id" varchar,
  "invoice_number" varchar,
  "series_key" varchar,
  "series_occurrence" integer,
  "title" varchar,
  "recipient_name" varchar,
  "status" varchar not null,
  "currency" varchar,
  "total_amount" integer,
  "paid_amount" integer,
  "next_payment_amount" integer,
  "due_on" date,
  "scheduled_at" datetime,
  "sale_or_service_date" date,
  "square_created_at" datetime,
  "square_updated_at" datetime,
  "public_url" text,
  "autopay" tinyint(1) not null default('0'),
  "payment_requests" text not null,
  "payment_ids" text,
  "payment_ids_basis" varchar,
  "fingerprint" varchar not null,
  "synced_at" datetime not null,
  "last_seen_at" datetime not null,
  "last_sync_run_id" integer,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action
);
CREATE INDEX "sqis_ws_customer_idx" on "square_invoice_snapshots"(
  "workspace_id",
  "square_customer_id"
);
CREATE INDEX "sqis_ws_due_idx" on "square_invoice_snapshots"(
  "workspace_id",
  "due_on"
);
CREATE UNIQUE INDEX "sqis_ws_invoice_unique" on "square_invoice_snapshots"(
  "workspace_id",
  "square_invoice_id"
);
CREATE INDEX "sqis_ws_series_idx" on "square_invoice_snapshots"(
  "workspace_id",
  "series_key",
  "series_occurrence"
);
CREATE INDEX "sqis_ws_status_idx" on "square_invoice_snapshots"(
  "workspace_id",
  "status"
);
CREATE UNIQUE INDEX "ca_ws_square_location_series_unique" on "client_agreements"(
  "workspace_id",
  "square_location_id",
  "square_series_key"
);
CREATE TABLE "square_invoice_coverages"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "square_invoice_id" varchar not null,
  "square_invoice_number" varchar not null,
  "active_square_invoice_slot" varchar,
  "covered_by_user_id" integer,
  "covered_at" datetime not null,
  "cleared_by_user_id" integer,
  "cleared_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("covered_by_user_id") references "users"("id") on delete set null,
  foreign key("cleared_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "sqic_active_square_unique" on "square_invoice_coverages"(
  "workspace_id",
  "active_square_invoice_slot"
);
CREATE INDEX "sqic_ws_invoice_idx" on "square_invoice_coverages"(
  "workspace_id",
  "client_invoice_id"
);
CREATE UNIQUE INDEX "square_invoice_coverages_public_id_unique" on "square_invoice_coverages"(
  "public_id"
);
CREATE TABLE "processor_movements"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_payment_id" integer not null,
  "provider" varchar not null,
  "account_key" varchar not null,
  "provider_entry_identifier" varchar not null,
  "source_identifier" varchar,
  "provider_payout_identifier" varchar,
  "kind" varchar not null,
  "principal_minor" integer not null,
  "fee_minor" integer not null,
  "net_minor" integer not null,
  "currency" varchar not null,
  "provider_occurred_at" datetime not null,
  "occurred_on" date not null,
  "occurred_timezone" varchar not null,
  "date_basis" varchar not null,
  "available_on" datetime,
  "needs_review" tinyint(1) not null default '0',
  "review_reason" varchar,
  "lock_version" integer not null default '1',
  "created_at" datetime not null,
  "updated_at" datetime not null,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_invoice_payment_id") references "client_invoice_payments"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "pm_entry_unique" on "processor_movements"(
  "workspace_id",
  "provider",
  "account_key",
  "provider_entry_identifier"
);
CREATE INDEX "pm_ws_updated_idx" on "processor_movements"(
  "workspace_id",
  "updated_at",
  "id"
);
CREATE INDEX "pm_ws_occurred_idx" on "processor_movements"(
  "workspace_id",
  "occurred_on",
  "id"
);
CREATE INDEX "pm_ws_payment_idx" on "processor_movements"(
  "workspace_id",
  "client_invoice_payment_id"
);
CREATE UNIQUE INDEX "pm_public_unique" on "processor_movements"("public_id");
CREATE TABLE "historical_sources"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "system" varchar not null,
  "identity_hash" varchar not null,
  "label" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "historical_sources_ws_id_unique" on "historical_sources"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "historical_sources_identity_unique" on "historical_sources"(
  "workspace_id",
  "system",
  "identity_hash"
);
CREATE UNIQUE INDEX "historical_sources_public_id_unique" on "historical_sources"(
  "public_id"
);
CREATE TABLE "historical_records"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "historical_source_id" integer not null,
  "source_table" varchar not null,
  "source_key" varchar not null,
  "fingerprint" varchar not null,
  "snapshot" text not null,
  "target_type" varchar,
  "target_public_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "historical_source_id") references "historical_sources"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "historical_records_source_unique" on "historical_records"(
  "workspace_id",
  "historical_source_id",
  "source_table",
  "source_key"
);
CREATE INDEX "historical_records_target_index" on "historical_records"(
  "workspace_id",
  "target_type",
  "target_public_id"
);
CREATE UNIQUE INDEX "historical_records_public_id_unique" on "historical_records"(
  "public_id"
);
CREATE UNIQUE INDEX "historical_records_ws_id_unique" on "historical_records"(
  "workspace_id",
  "id"
);
CREATE TABLE "historical_entries"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "historical_record_id" integer not null,
  "parent_record_id" integer,
  "client_company_id" integer,
  "kind" varchar not null,
  "title" varchar not null,
  "body" text not null,
  "search_text" text not null,
  "author" varchar,
  "occurred_on" varchar,
  "details" text not null,
  "document_fingerprint" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "historical_record_id") references "historical_records"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "parent_record_id") references "historical_records"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "historical_entries_ws_id_unique" on "historical_entries"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "historical_entries_record_unique" on "historical_entries"(
  "workspace_id",
  "historical_record_id"
);
CREATE INDEX "historical_entries_kind_index" on "historical_entries"(
  "workspace_id",
  "kind",
  "id"
);
CREATE INDEX "historical_entries_parent_index" on "historical_entries"(
  "workspace_id",
  "parent_record_id"
);
CREATE UNIQUE INDEX "historical_entries_public_id_unique" on "historical_entries"(
  "public_id"
);
CREATE TABLE "historical_blobs"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "historical_entry_id" integer not null,
  "filename" varchar not null,
  "object_key" varchar not null,
  "bytes" integer not null,
  "sha256" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "historical_entry_id") references "historical_entries"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "history_blob_entry_unique" on "historical_blobs"(
  "workspace_id",
  "historical_entry_id"
);
CREATE UNIQUE INDEX "historical_blobs_public_id_unique" on "historical_blobs"(
  "public_id"
);
CREATE TABLE "client_companies"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "name" varchar not null,
  "slug" varchar not null,
  "billing_email" varchar,
  "is_active" tinyint(1) not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  "automatic_invoice_email_enabled" tinyint(1) not null default('0'),
  "automatic_invoice_email_delay_days" integer,
  "credit_revision" integer not null default('0'),
  "lock_version" integer not null default('1'),
  "payment_reminders_enabled_at" datetime,
  "billing_address" text,
  "phone" varchar,
  "website_url" varchar,
  "tax_id" varchar,
  "currency" varchar,
  "notes" text,
  "autopay_enabled_at" datetime,
  "autopay_limit_amount" integer,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "client_companies_public_id_unique" on "client_companies"(
  "public_id"
);
CREATE UNIQUE INDEX "client_companies_workspace_id_id_unique" on "client_companies"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "client_companies_workspace_id_slug_unique" on "client_companies"(
  "workspace_id",
  "slug"
);
CREATE INDEX "client_companies_ws_updated_id_index" on "client_companies"(
  "workspace_id",
  "updated_at",
  "id"
);
CREATE TABLE "client_contacts"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "first_name" varchar,
  "last_name" varchar,
  "title" varchar,
  "email" varchar,
  "phone" varchar,
  "notes" text,
  "is_archived" tinyint(1) not null default('0'),
  "lock_version" integer not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "client_contacts_public_id_unique" on "client_contacts"(
  "public_id"
);
CREATE UNIQUE INDEX "contacts_ws_id_unique" on "client_contacts"(
  "workspace_id",
  "id"
);
CREATE TABLE "client_collaboration_topics"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "kind" varchar not null,
  "title" varchar not null,
  "body" text not null,
  "author_user_id" integer,
  "author_name" varchar not null,
  "is_visible_to_client" tinyint(1) not null default '0',
  "lock_version" integer not null default '1',
  "edited_at" datetime,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete restrict,
  foreign key("author_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "cct_ws_id_unique" on "client_collaboration_topics"(
  "workspace_id",
  "id"
);
CREATE INDEX "cct_company_page_idx" on "client_collaboration_topics"(
  "workspace_id",
  "client_company_id",
  "id"
);
CREATE UNIQUE INDEX "client_collaboration_topics_public_id_unique" on "client_collaboration_topics"(
  "public_id"
);
CREATE TABLE "client_task_lists"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_project_id" integer not null,
  "name" varchar not null,
  "is_visible_to_client" tinyint(1) not null default '0',
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "organization_position" integer not null default '0',
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "task_lists_ws_id_unique" on "client_task_lists"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "task_lists_ws_project_id_unique" on "client_task_lists"(
  "workspace_id",
  "client_project_id",
  "id"
);
CREATE UNIQUE INDEX "client_task_lists_public_id_unique" on "client_task_lists"(
  "public_id"
);
CREATE TABLE "client_tasks"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_project_id" integer not null,
  "title" varchar not null,
  "description" text,
  "status" varchar not null default('open'),
  "is_visible_to_client" tinyint(1) not null default('1'),
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default('1'),
  "milestone_price_amount" integer,
  "client_invoice_line_id" integer,
  "starts_on" date,
  "due_on" date,
  "parent_task_id" integer,
  "client_task_list_id" integer,
  "organization_position" integer not null default '0',
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade on update no action,
  foreign key("client_project_id") references "client_projects"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_invoice_line_id") references "client_invoice_lines"("id") on delete set null on update no action,
  foreign key("workspace_id", "client_project_id", "parent_task_id") references "client_tasks"("workspace_id", "client_project_id", "id") on delete restrict,
  foreign key("workspace_id", "client_project_id", "client_task_list_id") references "client_task_lists"("workspace_id", "client_project_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "client_tasks_public_id_unique" on "client_tasks"(
  "public_id"
);
CREATE INDEX "ct_ws_project_idx" on "client_tasks"(
  "workspace_id",
  "client_project_id"
);
CREATE INDEX "task_invoice_line_idx" on "client_tasks"(
  "workspace_id",
  "client_invoice_line_id"
);
CREATE UNIQUE INDEX "task_invoice_line_once" on "client_tasks"(
  "client_invoice_line_id"
);
CREATE UNIQUE INDEX "tasks_ws_id_unique" on "client_tasks"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "tasks_ws_project_id_unique" on "client_tasks"(
  "workspace_id",
  "client_project_id",
  "id"
);
CREATE INDEX "task_parent_scope_idx" on "client_tasks"(
  "workspace_id",
  "client_project_id",
  "parent_task_id"
);
CREATE INDEX "task_list_scope_idx" on "client_tasks"(
  "workspace_id",
  "client_project_id",
  "client_task_list_id"
);
CREATE TABLE "client_task_graph_states"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_project_id" integer not null,
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "task_graph_ws_project_unique" on "client_task_graph_states"(
  "workspace_id",
  "client_project_id"
);
CREATE UNIQUE INDEX "task_graph_ws_id_unique" on "client_task_graph_states"(
  "workspace_id",
  "id"
);
CREATE INDEX "task_organization_order_idx" on "client_tasks"(
  "workspace_id",
  "client_project_id",
  "parent_task_id",
  "client_task_list_id",
  "organization_position",
  "id"
);
CREATE INDEX "task_list_organization_order_idx" on "client_task_lists"(
  "workspace_id",
  "client_project_id",
  "organization_position",
  "id"
);
CREATE TABLE "client_attachments"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "record_type" varchar not null,
  "record_public_id" varchar not null,
  "object_key" varchar not null,
  "staged_object_key" varchar,
  "original_filename" text not null,
  "media_type" varchar not null,
  "bytes" integer not null,
  "sha256" varchar not null,
  "uploader_id" integer,
  "lifecycle_state" varchar not null default('staged'),
  "available_at" datetime,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default('1'),
  "is_visible_to_client" tinyint(1) not null default '0',
  foreign key("uploader_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action
);
CREATE INDEX "ca_workspace_record_idx" on "client_attachments"(
  "workspace_id",
  "record_type",
  "record_public_id"
);
CREATE INDEX "client_attachments_lifecycle_state_created_at_index" on "client_attachments"(
  "lifecycle_state",
  "created_at"
);
CREATE UNIQUE INDEX "client_attachments_object_key_unique" on "client_attachments"(
  "object_key"
);
CREATE UNIQUE INDEX "client_attachments_public_id_unique" on "client_attachments"(
  "public_id"
);
CREATE UNIQUE INDEX "client_attachments_staged_object_key_unique" on "client_attachments"(
  "staged_object_key"
);
CREATE TABLE "client_task_labels"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "name" varchar not null,
  "name_key" varchar not null,
  "is_archived" tinyint(1) not null default '0',
  "lock_version" integer not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "task_labels_ws_id_unique" on "client_task_labels"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "task_labels_ws_name_unique" on "client_task_labels"(
  "workspace_id",
  "name_key"
);
CREATE INDEX "task_labels_active_idx" on "client_task_labels"(
  "workspace_id",
  "is_archived",
  "id"
);
CREATE UNIQUE INDEX "client_task_labels_public_id_unique" on "client_task_labels"(
  "public_id"
);
CREATE TABLE "client_task_label_assignments"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_task_id" integer not null,
  "client_task_label_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_task_id") references "client_tasks"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_task_label_id") references "client_task_labels"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "task_label_assignment_unique" on "client_task_label_assignments"(
  "workspace_id",
  "client_task_id",
  "client_task_label_id"
);
CREATE TABLE "client_task_dependencies"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_project_id" integer not null,
  "client_task_id" integer not null,
  "prerequisite_task_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_project_id", "client_task_id") references "client_tasks"("workspace_id", "client_project_id", "id") on delete restrict,
  foreign key("workspace_id", "client_project_id", "prerequisite_task_id") references "client_tasks"("workspace_id", "client_project_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "task_dependency_unique" on "client_task_dependencies"(
  "workspace_id",
  "client_project_id",
  "client_task_id",
  "prerequisite_task_id"
);
CREATE INDEX "task_dependency_reverse_idx" on "client_task_dependencies"(
  "workspace_id",
  "client_project_id",
  "prerequisite_task_id",
  "client_task_id"
);
CREATE TRIGGER task_dependency_not_self_insert BEFORE INSERT ON client_task_dependencies WHEN NEW.client_task_id = NEW.prerequisite_task_id BEGIN SELECT RAISE(ABORT, 'A task cannot depend on itself.'); END;
CREATE TRIGGER task_dependency_not_self_update BEFORE UPDATE ON client_task_dependencies WHEN NEW.client_task_id = NEW.prerequisite_task_id BEGIN SELECT RAISE(ABORT, 'A task cannot depend on itself.'); END;
CREATE UNIQUE INDEX "contacts_ws_company_id_unique" on "client_contacts"(
  "workspace_id",
  "client_company_id",
  "id"
);
CREATE TABLE "client_task_contact_responsibilities"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer not null,
  "client_task_id" integer not null,
  "client_contact_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_project_id", "client_task_id") references "client_tasks"("workspace_id", "client_project_id", "id") on delete restrict,
  foreign key("workspace_id", "client_company_id", "client_project_id") references "client_projects"("workspace_id", "client_company_id", "id") on delete restrict,
  foreign key("workspace_id", "client_company_id", "client_contact_id") references "client_contacts"("workspace_id", "client_company_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "task_contact_responsibility_unique" on "client_task_contact_responsibilities"(
  "workspace_id",
  "client_task_id",
  "client_contact_id"
);
CREATE TABLE "accounting_invoice_entries"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "source_key" varchar not null,
  "effective_on" date,
  "payload" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete restrict,
  foreign key("client_invoice_id") references "client_invoices"("id") on delete restrict,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "accounting_invoice_entries_workspace_id_source_key_unique" on "accounting_invoice_entries"(
  "workspace_id",
  "source_key"
);
CREATE INDEX "accounting_invoice_sync" on "accounting_invoice_entries"(
  "workspace_id",
  "updated_at",
  "id"
);
CREATE INDEX "accounting_invoice_entries_workspace_id_effective_on_index" on "accounting_invoice_entries"(
  "workspace_id",
  "effective_on"
);
CREATE UNIQUE INDEX "accounting_invoice_entries_public_id_unique" on "accounting_invoice_entries"(
  "public_id"
);
CREATE TABLE "workspace_payment_connections"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "processor" varchar not null,
  "external_account_id" varchar not null,
  "display_name" varchar,
  "environment" varchar,
  "origin" varchar not null,
  "reuses_connection_id" integer,
  "endpoint_token" varchar not null,
  "secret" text,
  "webhook_secret" text,
  "secret_hint" varchar,
  "webhook_endpoint_id" varchar,
  "location_id" varchar,
  "connected_by_user_id" integer,
  "connected_at" datetime not null,
  "revoked_at" datetime,
  "revoked_by_user_id" integer,
  "active_slot" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "publishable_key" varchar,
  "webhook_check_status" varchar,
  "webhook_check_missing" text,
  "webhook_checked_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("connected_by_user_id") references "users"("id") on delete set null,
  foreign key("revoked_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "workspace_payment_connection_active_unique" on "workspace_payment_connections"(
  "workspace_id",
  "active_slot"
);
CREATE INDEX "workspace_payment_connection_account_index" on "workspace_payment_connections"(
  "processor",
  "external_account_id"
);
CREATE UNIQUE INDEX "workspace_payment_connections_public_id_unique" on "workspace_payment_connections"(
  "public_id"
);
CREATE UNIQUE INDEX "workspace_payment_connections_endpoint_token_unique" on "workspace_payment_connections"(
  "endpoint_token"
);
CREATE TABLE "client_invoice_deferred_projects"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "client_project_id" integer not null,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "cidp_invoice_project_unique" on "client_invoice_deferred_projects"(
  "workspace_id",
  "client_invoice_id",
  "client_project_id"
);
CREATE INDEX "cidp_ws_project_idx" on "client_invoice_deferred_projects"(
  "workspace_id",
  "client_project_id"
);
CREATE TABLE "client_project_participation_invitations"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "host_workspace_id" integer not null,
  "client_project_id" integer not null,
  "email" varchar not null,
  "invited_by_user_id" integer,
  "expires_at" datetime not null,
  "accepted_at" datetime,
  "declined_at" datetime,
  "revoked_at" datetime,
  "resolved_by_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("host_workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("invited_by_user_id") references "users"("id") on delete set null,
  foreign key("resolved_by_user_id") references "users"("id") on delete set null,
  foreign key("host_workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade
);
CREATE INDEX "cppinv_email_idx" on "client_project_participation_invitations"(
  "email"
);
CREATE INDEX "cppinv_host_project_idx" on "client_project_participation_invitations"(
  "host_workspace_id",
  "client_project_id"
);
CREATE UNIQUE INDEX "cppinv_public_id_unique" on "client_project_participation_invitations"(
  "public_id"
);
CREATE TABLE "client_project_participations"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "host_workspace_id" integer not null,
  "client_project_id" integer not null,
  "participant_workspace_id" integer not null,
  "invitation_id" integer,
  "granted_by_user_id" integer,
  "granted_at" datetime not null,
  "accepted_by_user_id" integer,
  "accepted_at" datetime not null,
  "revoked_at" datetime,
  "revoked_by_user_id" integer,
  "left_at" datetime,
  "left_by_user_id" integer,
  "active_marker" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("host_workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("participant_workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("invitation_id") references "client_project_participation_invitations"("id") on delete set null,
  foreign key("granted_by_user_id") references "users"("id") on delete set null,
  foreign key("accepted_by_user_id") references "users"("id") on delete set null,
  foreign key("revoked_by_user_id") references "users"("id") on delete set null,
  foreign key("left_by_user_id") references "users"("id") on delete set null,
  foreign key("host_workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "cppart_one_active_unique" on "client_project_participations"(
  "client_project_id",
  "participant_workspace_id",
  "active_marker"
);
CREATE INDEX "cppart_participant_active_idx" on "client_project_participations"(
  "participant_workspace_id",
  "active_marker"
);
CREATE INDEX "cppart_host_project_idx" on "client_project_participations"(
  "host_workspace_id",
  "client_project_id"
);
CREATE UNIQUE INDEX "cppart_public_id_unique" on "client_project_participations"(
  "public_id"
);
CREATE TABLE "client_projects"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "name" varchar not null,
  "description" text,
  "status" varchar not null default('active'),
  "is_visible_to_client" tinyint(1) not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default('1'),
  "repository" varchar,
  "shared_participation_id" integer,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("shared_participation_id") references "client_project_participations"("id") on delete set null
);
CREATE UNIQUE INDEX "client_projects_client_company_id_name_unique" on "client_projects"(
  "client_company_id",
  "name"
);
CREATE UNIQUE INDEX "client_projects_public_id_unique" on "client_projects"(
  "public_id"
);
CREATE UNIQUE INDEX "client_projects_workspace_id_id_unique" on "client_projects"(
  "workspace_id",
  "id"
);
CREATE INDEX "cp_ws_company_idx" on "client_projects"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "projects_ws_company_id_unique" on "client_projects"(
  "workspace_id",
  "client_company_id",
  "id"
);
CREATE UNIQUE INDEX "cp_shared_participation_unique" on "client_projects"(
  "shared_participation_id"
);
CREATE TABLE "client_comments"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "client_task_id" integer,
  "client_collaboration_topic_id" integer,
  "body" text not null,
  "author_user_id" integer,
  "author_name" varchar not null,
  "is_visible_to_client" tinyint(1) not null default('0'),
  "lock_version" integer not null default('1'),
  "edited_at" datetime,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "shared_with_participants" tinyint(1) not null default '0',
  "author_workspace_id" integer,
  foreign key("workspace_id", "client_collaboration_topic_id") references "client_collaboration_topics"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_task_id") references "client_tasks"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict on update no action,
  foreign key("author_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete restrict on update no action,
  foreign key("author_workspace_id") references "workspaces"("id") on delete set null
);
CREATE INDEX "cc_task_page_idx" on "client_comments"(
  "workspace_id",
  "client_task_id",
  "id"
);
CREATE INDEX "cc_topic_page_idx" on "client_comments"(
  "workspace_id",
  "client_collaboration_topic_id",
  "id"
);
CREATE UNIQUE INDEX "cc_ws_id_unique" on "client_comments"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "client_comments_public_id_unique" on "client_comments"(
  "public_id"
);
CREATE TABLE "client_task_assignments"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "client_task_id" integer not null,
  "user_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  "assignee_workspace_id" integer,
  foreign key("workspace_id", "client_task_id") references "client_tasks"("workspace_id", "id") on delete cascade on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_task_id") references "client_tasks"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("assignee_workspace_id") references "workspaces"("id") on delete cascade
);
CREATE UNIQUE INDEX "task_assignment_unique" on "client_task_assignments"(
  "workspace_id",
  "client_task_id",
  "user_id"
);
CREATE TABLE "reviewer_grants"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "granter_workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "project_key" integer not null default '0',
  "grantee_kind" varchar not null,
  "grantee_key" varchar not null,
  "reviewer_email" varchar,
  "reviewer_workspace_id" integer,
  "reviewer_label" varchar,
  "granted_by_user_id" integer,
  "granted_at" datetime not null,
  "revoked_at" datetime,
  "revoked_by_user_id" integer,
  "active_marker" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("granter_workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("reviewer_workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("granted_by_user_id") references "users"("id") on delete set null,
  foreign key("revoked_by_user_id") references "users"("id") on delete set null,
  foreign key("granter_workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade,
  foreign key("granter_workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "revgr_one_active_unique" on "reviewer_grants"(
  "granter_workspace_id",
  "client_company_id",
  "project_key",
  "grantee_key",
  "active_marker"
);
CREATE INDEX "revgr_granter_active_idx" on "reviewer_grants"(
  "granter_workspace_id",
  "active_marker"
);
CREATE INDEX "revgr_email_active_idx" on "reviewer_grants"(
  "reviewer_email",
  "active_marker"
);
CREATE INDEX "revgr_reviewer_ws_active_idx" on "reviewer_grants"(
  "reviewer_workspace_id",
  "active_marker"
);
CREATE UNIQUE INDEX "revgr_public_id_unique" on "reviewer_grants"("public_id");
CREATE TABLE "mcp_credential_settings"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "oauth_client_id" varchar,
  "access_token_id" varchar,
  "show_all_tools" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("oauth_client_id") references "oauth_clients"("id") on delete cascade,
  foreign key("access_token_id") references "oauth_access_tokens"("id") on delete cascade
);
CREATE UNIQUE INDEX "mcp_credential_settings_connection_unique" on "mcp_credential_settings"(
  "user_id",
  "oauth_client_id"
);
CREATE UNIQUE INDEX "mcp_credential_settings_token_unique" on "mcp_credential_settings"(
  "access_token_id"
);
CREATE INDEX "mcp_credential_settings_user_index" on "mcp_credential_settings"(
  "user_id"
);
CREATE TABLE "client_autopay_consents"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "action" varchar not null,
  "actor_user_id" integer,
  "actor_name" varchar,
  "actor_role" varchar not null,
  "method" varchar not null,
  "limit_amount" integer,
  "currency" varchar,
  "payment_method_label" varchar,
  "wording" text,
  "attestation" text,
  "reason" varchar,
  "notice_sent" tinyint(1) not null default '0',
  "occurred_at" datetime not null,
  "created_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("actor_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade
);
CREATE INDEX "aco_ws_company_idx" on "client_autopay_consents"(
  "workspace_id",
  "client_company_id",
  "id"
);
CREATE UNIQUE INDEX "client_autopay_consents_public_id_unique" on "client_autopay_consents"(
  "public_id"
);
CREATE TABLE "invoice_autopay_attempts"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_invoice_id" integer not null,
  "client_company_id" integer not null,
  "state" varchar not null,
  "amount" integer,
  "currency" varchar,
  "limit_amount" integer,
  "consent_public_id" varchar,
  "payment_method_label" varchar,
  "payment_intent_id" varchar,
  "failure_code" varchar,
  "failure_reason" varchar,
  "skipped_by_user_id" integer,
  "skipped_at" datetime,
  "claimed_at" datetime not null,
  "resolved_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "resolved_by_user_id" integer,
  "resolution_note" varchar,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_id") references "client_invoices"("workspace_id", "id") on delete cascade on update no action,
  foreign key("skipped_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("resolved_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "iaa_invoice_unique" on "invoice_autopay_attempts"(
  "workspace_id",
  "client_invoice_id"
);
CREATE INDEX "iaa_review_idx" on "invoice_autopay_attempts"(
  "state",
  "claimed_at"
);
CREATE INDEX "iaa_ws_company_idx" on "invoice_autopay_attempts"(
  "workspace_id",
  "client_company_id",
  "id"
);
CREATE UNIQUE INDEX "invoice_autopay_attempts_public_id_unique" on "invoice_autopay_attempts"(
  "public_id"
);
CREATE TABLE "client_time_entries"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer not null,
  "client_task_id" integer,
  "user_id" integer not null,
  "worked_on" date not null,
  "minutes" integer not null,
  "description" text not null,
  "is_billable" tinyint(1) not null default('1'),
  "is_deferred" tinyint(1) not null default('0'),
  "billing_rate_amount" integer,
  "currency" varchar,
  "status" varchar not null default('draft'),
  "approved_by_user_id" integer,
  "approved_at" datetime,
  "subcontractor_cost_amount" integer,
  "subcontractor_cost_currency" varchar,
  "subcontractor_cost_metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  "lock_version" integer not null default('1'),
  "is_visible_to_client" tinyint(1) not null default('0'),
  "client_visible_description" text,
  "deleted_at" datetime,
  "billing_rate_source" varchar,
  "job_type" varchar,
  "split_from_time_entry_id" integer,
  "subcontractor_billing_mode" varchar,
  "client_approved_at" datetime,
  "client_approved_by_user_id" integer,
  "client_approval_source" varchar,
  "client_disputed_at" datetime,
  "client_disputed_by_user_id" integer,
  "client_dispute_note" varchar,
  "submitted_at" datetime,
  "submitted_by_user_id" integer,
  "client_dispute_settlement" varchar,
  "client_dispute_reply" varchar,
  "client_dispute_settled_at" datetime,
  "client_dispute_settled_by_user_id" integer,
  foreign key("submitted_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete cascade on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade on update no action,
  foreign key("approved_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("user_id") references "users"("id") on delete restrict on update no action,
  foreign key("client_task_id") references "client_tasks"("id") on delete set null on update no action,
  foreign key("client_project_id") references "client_projects"("id") on delete cascade on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("split_from_time_entry_id") references "client_time_entries"("id") on delete set null on update no action,
  foreign key("client_approved_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_disputed_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_dispute_settled_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "client_time_entries_client_company_id_worked_on_index" on "client_time_entries"(
  "client_company_id",
  "worked_on"
);
CREATE UNIQUE INDEX "client_time_entries_public_id_unique" on "client_time_entries"(
  "public_id"
);
CREATE INDEX "client_time_entries_workspace_id_deleted_at_index" on "client_time_entries"(
  "workspace_id",
  "deleted_at"
);
CREATE UNIQUE INDEX "client_time_entries_workspace_id_id_unique" on "client_time_entries"(
  "workspace_id",
  "id"
);
CREATE INDEX "client_time_entries_workspace_id_status_index" on "client_time_entries"(
  "workspace_id",
  "status"
);
CREATE INDEX "client_time_entries_workspace_id_worked_on_index" on "client_time_entries"(
  "workspace_id",
  "worked_on"
);
CREATE INDEX "cte_workspace_subcontractor_mode_worked_idx" on "client_time_entries"(
  "workspace_id",
  "subcontractor_billing_mode",
  "worked_on"
);
CREATE INDEX "cte_ws_company_idx" on "client_time_entries"(
  "workspace_id",
  "client_company_id"
);
CREATE INDEX "cte_ws_project_idx" on "client_time_entries"(
  "workspace_id",
  "client_project_id"
);
CREATE INDEX "cte_ws_submitted_idx" on "client_time_entries"(
  "workspace_id",
  "submitted_at"
);
CREATE INDEX "time_entry_split_parent_idx" on "client_time_entries"(
  "workspace_id",
  "split_from_time_entry_id"
);
CREATE TABLE "client_expenses"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer,
  "created_by_user_id" integer,
  "spent_on" date not null,
  "amount" integer not null,
  "currency" varchar not null,
  "description" text not null,
  "status" varchar not null default('draft'),
  "approved_by_user_id" integer,
  "approved_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "client_invoice_line_id" integer,
  "lock_version" integer not null default('1'),
  "client_expense_schedule_id" integer,
  "occurrence_on" date,
  "external_finance_transaction_uuid" varchar,
  "client_approved_at" datetime,
  "client_approved_by_user_id" integer,
  "client_approval_source" varchar,
  "client_disputed_at" datetime,
  "client_disputed_by_user_id" integer,
  "client_dispute_note" varchar,
  "submitted_at" datetime,
  "submitted_by_user_id" integer,
  "client_dispute_settlement" varchar,
  "client_dispute_reply" varchar,
  "client_dispute_settled_at" datetime,
  "client_dispute_settled_by_user_id" integer,
  foreign key("submitted_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id", "client_expense_schedule_id") references "client_expense_schedules"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete cascade on update no action,
  foreign key("approved_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("created_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_project_id") references "client_projects"("id") on delete set null on update no action,
  foreign key("client_company_id") references "client_companies"("id") on delete cascade on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "client_invoice_line_id") references "client_invoice_lines"("workspace_id", "id") on delete restrict on update no action,
  foreign key("client_approved_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_disputed_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("client_dispute_settled_by_user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "cex_schedule_occurrence_unique" on "client_expenses"(
  "workspace_id",
  "client_expense_schedule_id",
  "occurrence_on"
);
CREATE INDEX "cex_ws_company_idx" on "client_expenses"(
  "workspace_id",
  "client_company_id"
);
CREATE UNIQUE INDEX "cex_ws_line_unique" on "client_expenses"(
  "workspace_id",
  "client_invoice_line_id"
);
CREATE INDEX "cex_ws_submitted_idx" on "client_expenses"(
  "workspace_id",
  "submitted_at"
);
CREATE INDEX "client_expenses_client_company_id_spent_on_index" on "client_expenses"(
  "client_company_id",
  "spent_on"
);
CREATE UNIQUE INDEX "client_expenses_public_id_unique" on "client_expenses"(
  "public_id"
);
CREATE INDEX "client_expenses_workspace_id_deleted_at_index" on "client_expenses"(
  "workspace_id",
  "deleted_at"
);
CREATE INDEX "client_expenses_workspace_id_spent_on_index" on "client_expenses"(
  "workspace_id",
  "spent_on"
);
CREATE INDEX "client_expenses_workspace_id_status_index" on "client_expenses"(
  "workspace_id",
  "status"
);
CREATE TABLE "palette_recents"(
  "id" integer primary key autoincrement not null,
  "workspace_id" integer not null,
  "user_id" integer not null,
  "kind" varchar not null,
  "record_public_id" varchar not null,
  "opened_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "palette_recents_record_unique" on "palette_recents"(
  "workspace_id",
  "user_id",
  "kind",
  "record_public_id"
);
CREATE INDEX "palette_recents_user_recent_idx" on "palette_recents"(
  "user_id",
  "workspace_id",
  "opened_at"
);
CREATE UNIQUE INDEX "ws_package_catalog_token_unique" on "workspaces"(
  "package_catalog_token"
);
CREATE TABLE "service_package_versions"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "service_package_id" integer not null,
  "number" integer not null,
  "draft_marker" integer,
  "name" varchar not null,
  "summary" varchar,
  "body_markdown" text,
  "agreement_text" text,
  "currency" varchar not null,
  "billing_cadence" varchar not null,
  "hourly_rate_amount" integer,
  "retainer_amount" integer,
  "retainer_minutes" integer,
  "rollover_months" integer,
  "first_cycle_proration" varchar,
  "charge_setup_fee_at_signup" tinyint(1) not null default '0',
  "charge_first_period" tinyint(1) not null default '0',
  "require_autopay" tinyint(1) not null default '0',
  "autopay_limit_amount" integer,
  "pay_before_activation" tinyint(1) not null default '0',
  "payment_lapse_days" integer not null default '14',
  "activation_mode" varchar not null,
  "invoice_issuing" varchar not null,
  "notice_days" integer not null default '0',
  "minimum_term_months" integer,
  "cancellation_mode" varchar not null,
  "intake_questions" text,
  "published_at" datetime,
  "published_by_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("published_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "service_package_id") references "service_packages"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "svpkv_ws_id_unique" on "service_package_versions"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "svpkv_number_unique" on "service_package_versions"(
  "workspace_id",
  "service_package_id",
  "number"
);
CREATE UNIQUE INDEX "svpkv_one_draft_unique" on "service_package_versions"(
  "workspace_id",
  "service_package_id",
  "draft_marker"
);
CREATE UNIQUE INDEX "svpkv_public_id_unique" on "service_package_versions"(
  "public_id"
);
CREATE TABLE "service_packages"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "link_name" varchar not null,
  "status" varchar not null,
  "is_listed" tinyint(1) not null default('0'),
  "portal_addable" tinyint(1) not null default('1'),
  "published_version_id" integer,
  "created_by_user_id" integer,
  "lock_version" integer not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("created_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("workspace_id", "published_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "svpkg_public_id_unique" on "service_packages"(
  "public_id"
);
CREATE UNIQUE INDEX "svpkg_ws_id_unique" on "service_packages"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "svpkg_ws_link_unique" on "service_packages"(
  "workspace_id",
  "link_name"
);
CREATE INDEX "svpkg_ws_status_idx" on "service_packages"(
  "workspace_id",
  "status"
);
CREATE TABLE "service_package_components"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "service_package_version_id" integer not null,
  "kind" varchar not null,
  "description" varchar not null,
  "cadence" varchar not null,
  "amount" integer not null,
  "quantity" numeric not null default '1',
  "is_taxable" tinyint(1) not null default '0',
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "service_package_version_id") references "service_package_versions"("workspace_id", "id") on delete cascade
);
CREATE UNIQUE INDEX "svpkc_ws_id_unique" on "service_package_components"(
  "workspace_id",
  "id"
);
CREATE UNIQUE INDEX "svpkc_public_id_unique" on "service_package_components"(
  "public_id"
);
CREATE TABLE "service_package_requests"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "service_package_id" integer not null,
  "service_package_version_id" integer not null,
  "status" varchar not null,
  "company_name" varchar,
  "contact_name" varchar,
  "email" varchar,
  "email_hash" varchar not null,
  "phone" varchar,
  "answers" text,
  "requester_user_id" integer,
  "client_fingerprint" varchar,
  "submitted_at" datetime not null,
  "client_company_id" integer,
  "decided_by_user_id" integer,
  "decided_at" datetime,
  "decline_reason" varchar,
  "erased_at" datetime,
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("requester_user_id") references "users"("id") on delete set null,
  foreign key("decided_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "service_package_id") references "service_packages"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "service_package_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "svpkr_ws_id_unique" on "service_package_requests"(
  "workspace_id",
  "id"
);
CREATE INDEX "svpkr_ws_status_idx" on "service_package_requests"(
  "workspace_id",
  "status",
  "id"
);
CREATE INDEX "svpkr_ws_email_status_idx" on "service_package_requests"(
  "workspace_id",
  "email_hash",
  "status"
);
CREATE UNIQUE INDEX "svpkr_public_id_unique" on "service_package_requests"(
  "public_id"
);
CREATE TABLE "agreement_cancellation_requests"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_company_id" integer not null,
  "client_agreement_id" integer not null,
  "status" varchar not null,
  "requested_end_on" date not null,
  "reason" varchar,
  "requested_by_user_id" integer,
  "requested_at" datetime not null,
  "decided_by_user_id" integer,
  "decided_at" datetime,
  "decided_end_on" date,
  "notice_waived" tinyint(1) not null default '0',
  "decision_note" varchar,
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("requested_by_user_id") references "users"("id") on delete set null,
  foreign key("decided_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_agreement_id") references "client_agreements"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "agcr_ws_id_unique" on "agreement_cancellation_requests"(
  "workspace_id",
  "id"
);
CREATE INDEX "agcr_ws_status_idx" on "agreement_cancellation_requests"(
  "workspace_id",
  "status",
  "id"
);
CREATE INDEX "agcr_ws_agreement_status_idx" on "agreement_cancellation_requests"(
  "workspace_id",
  "client_agreement_id",
  "status"
);
CREATE UNIQUE INDEX "agcr_public_id_unique" on "agreement_cancellation_requests"(
  "public_id"
);
CREATE TABLE "agreement_scheduled_terminations"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "client_agreement_id" integer not null,
  "agreement_cancellation_request_id" integer,
  "status" varchar not null,
  "source" varchar not null,
  "ends_on" date not null,
  "previous_ends_on" date,
  "scheduled_by_user_id" integer,
  "scheduled_at" datetime not null,
  "undone_by_user_id" integer,
  "undone_at" datetime,
  "terminated_at" datetime,
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("scheduled_by_user_id") references "users"("id") on delete set null,
  foreign key("undone_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "client_agreement_id") references "client_agreements"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "agreement_cancellation_request_id") references "agreement_cancellation_requests"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "agst_ws_id_unique" on "agreement_scheduled_terminations"(
  "workspace_id",
  "id"
);
CREATE INDEX "agst_status_idx" on "agreement_scheduled_terminations"(
  "status",
  "id"
);
CREATE INDEX "agst_ws_agreement_status_idx" on "agreement_scheduled_terminations"(
  "workspace_id",
  "client_agreement_id",
  "status"
);
CREATE INDEX "agst_ws_request_idx" on "agreement_scheduled_terminations"(
  "workspace_id",
  "agreement_cancellation_request_id"
);
CREATE UNIQUE INDEX "agst_public_id_unique" on "agreement_scheduled_terminations"(
  "public_id"
);
CREATE TABLE "service_package_migrations"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "service_package_id" integer not null,
  "from_version_id" integer not null,
  "to_version_id" integer not null,
  "effective_on" date not null,
  "message" varchar,
  "status" varchar not null,
  "scheduled_by_user_id" integer,
  "scheduled_at" datetime not null,
  "cancelled_by_user_id" integer,
  "cancelled_at" datetime,
  "completed_at" datetime,
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("scheduled_by_user_id") references "users"("id") on delete set null,
  foreign key("cancelled_by_user_id") references "users"("id") on delete set null,
  foreign key("workspace_id", "service_package_id") references "service_packages"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "from_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "to_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "svpkm_ws_id_unique" on "service_package_migrations"(
  "workspace_id",
  "id"
);
CREATE INDEX "svpkm_ws_package_status_idx" on "service_package_migrations"(
  "workspace_id",
  "service_package_id",
  "status"
);
CREATE INDEX "svpkm_ws_from_version_idx" on "service_package_migrations"(
  "workspace_id",
  "from_version_id"
);
CREATE INDEX "svpkm_ws_to_version_idx" on "service_package_migrations"(
  "workspace_id",
  "to_version_id"
);
CREATE UNIQUE INDEX "svpkm_public_id_unique" on "service_package_migrations"(
  "public_id"
);
CREATE TABLE "service_package_migration_targets"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "service_package_migration_id" integer not null,
  "service_package_enrollment_id" integer not null,
  "client_company_id" integer not null,
  "client_agreement_id" integer not null,
  "successor_agreement_id" integer,
  "effective_on" date not null,
  "status" varchar not null,
  "outcome_code" varchar,
  "outcome_detail" varchar,
  "outcome_recorded_at" datetime,
  "new_price_amount" integer not null,
  "new_price_currency" varchar not null,
  "autopay_limit_amount" integer,
  "settled_at" datetime,
  "lock_version" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade,
  foreign key("workspace_id", "service_package_migration_id") references "service_package_migrations"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "service_package_enrollment_id") references "service_package_enrollments"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "client_agreement_id") references "client_agreements"("workspace_id", "id") on delete restrict,
  foreign key("workspace_id", "successor_agreement_id") references "client_agreements"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "svpkt_ws_id_unique" on "service_package_migration_targets"(
  "workspace_id",
  "id"
);
CREATE INDEX "svpkt_status_idx" on "service_package_migration_targets"(
  "status",
  "id"
);
CREATE INDEX "svpkt_ws_migration_idx" on "service_package_migration_targets"(
  "workspace_id",
  "service_package_migration_id"
);
CREATE INDEX "svpkt_ws_enrollment_status_idx" on "service_package_migration_targets"(
  "workspace_id",
  "service_package_enrollment_id",
  "status"
);
CREATE INDEX "svpkt_ws_company_idx" on "service_package_migration_targets"(
  "workspace_id",
  "client_company_id"
);
CREATE INDEX "svpkt_ws_agreement_idx" on "service_package_migration_targets"(
  "workspace_id",
  "client_agreement_id"
);
CREATE INDEX "svpkt_ws_successor_idx" on "service_package_migration_targets"(
  "workspace_id",
  "successor_agreement_id"
);
CREATE UNIQUE INDEX "svpkt_public_id_unique" on "service_package_migration_targets"(
  "public_id"
);
CREATE TABLE "service_package_enrollments"(
  "id" integer primary key autoincrement not null,
  "public_id" varchar not null,
  "workspace_id" integer not null,
  "service_package_id" integer not null,
  "service_package_version_id" integer not null,
  "client_company_id" integer not null,
  "client_project_id" integer not null,
  "client_agreement_id" integer not null,
  "service_package_request_id" integer,
  "status" varchar not null,
  "offered_by_user_id" integer,
  "offered_at" datetime not null,
  "lock_version" integer not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "accepted_at" datetime,
  "accepted_by_user_id" integer,
  "signer_name" varchar,
  "signer_title" varchar,
  "accepted_version_id" integer,
  "activated_at" datetime,
  "activated_by_user_id" integer,
  "signup_invoice_id" integer,
  "lapsed_at" datetime,
  "auto_invoice_outcome" varchar,
  "auto_invoice_period_start" date,
  "auto_invoice_refusal" varchar,
  "auto_invoice_detail" varchar,
  "auto_invoice_id" integer,
  "auto_invoice_recorded_at" datetime,
  "current_version_id" integer,
  foreign key("workspace_id", "auto_invoice_id") references "client_invoices"("workspace_id", "id") on delete restrict on update no action,
  foreign key("activated_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id", "accepted_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict on update no action,
  foreign key("accepted_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id") references "workspaces"("id") on delete cascade on update no action,
  foreign key("offered_by_user_id") references "users"("id") on delete set null on update no action,
  foreign key("workspace_id", "service_package_id") references "service_packages"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "service_package_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_company_id") references "client_companies"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_project_id") references "client_projects"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "client_agreement_id") references "client_agreements"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "service_package_request_id") references "service_package_requests"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "signup_invoice_id") references "client_invoices"("workspace_id", "id") on delete restrict on update no action,
  foreign key("workspace_id", "current_version_id") references "service_package_versions"("workspace_id", "id") on delete restrict
);
CREATE UNIQUE INDEX "svpke_agreement_unique" on "service_package_enrollments"(
  "workspace_id",
  "client_agreement_id"
);
CREATE UNIQUE INDEX "svpke_public_id_unique" on "service_package_enrollments"(
  "public_id"
);
CREATE UNIQUE INDEX "svpke_request_unique" on "service_package_enrollments"(
  "workspace_id",
  "service_package_request_id"
);
CREATE INDEX "svpke_ws_accepted_version_idx" on "service_package_enrollments"(
  "workspace_id",
  "accepted_version_id"
);
CREATE INDEX "svpke_ws_auto_invoice_idx" on "service_package_enrollments"(
  "workspace_id",
  "auto_invoice_id"
);
CREATE INDEX "svpke_ws_company_package_idx" on "service_package_enrollments"(
  "workspace_id",
  "client_company_id",
  "service_package_id",
  "status"
);
CREATE INDEX "svpke_ws_company_status_idx" on "service_package_enrollments"(
  "workspace_id",
  "client_company_id",
  "status"
);
CREATE UNIQUE INDEX "svpke_ws_id_unique" on "service_package_enrollments"(
  "workspace_id",
  "id"
);
CREATE INDEX "svpke_ws_signup_invoice_idx" on "service_package_enrollments"(
  "workspace_id",
  "signup_invoice_id"
);
CREATE INDEX "svpke_ws_status_idx" on "service_package_enrollments"(
  "workspace_id",
  "status",
  "id"
);
CREATE INDEX "svpke_ws_current_version_idx" on "service_package_enrollments"(
  "workspace_id",
  "current_version_id"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_08_15_000000_create_svc_foundation',1);
INSERT INTO migrations VALUES(5,'2026_08_15_005000_add_public_ids_to_identity_memberships',1);
INSERT INTO migrations VALUES(6,'2026_08_15_010000_create_engagement_workflow',1);
INSERT INTO migrations VALUES(7,'2026_08_15_015000_create_client_attachments',1);
INSERT INTO migrations VALUES(8,'2026_08_15_020000_create_billing_workflow',1);
INSERT INTO migrations VALUES(9,'2026_08_15_030000_create_legacy_migration_ledger',1);
INSERT INTO migrations VALUES(10,'2026_08_15_040000_create_payment_reconciliations',1);
INSERT INTO migrations VALUES(11,'2026_08_15_200954_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(12,'2026_08_16_000000_create_legacy_attachment_copies',1);
INSERT INTO migrations VALUES(13,'2026_08_18_120000_preserve_legacy_activity_and_email_delivery',1);
INSERT INTO migrations VALUES(14,'2026_08_23_090000_add_agent_workflow_foundation',1);
INSERT INTO migrations VALUES(15,'2026_08_23_173334_create_oauth_auth_codes_table',1);
INSERT INTO migrations VALUES(16,'2026_08_23_173335_create_oauth_access_tokens_table',1);
INSERT INTO migrations VALUES(17,'2026_08_23_173336_create_oauth_refresh_tokens_table',1);
INSERT INTO migrations VALUES(18,'2026_08_23_173337_create_oauth_clients_table',1);
INSERT INTO migrations VALUES(19,'2026_08_23_180000_create_agent_mutation_audit_tables',1);
INSERT INTO migrations VALUES(20,'2026_08_23_190000_add_project_attribution_to_invoice_lines',1);
INSERT INTO migrations VALUES(21,'2026_08_23_200000_add_oauth_resource_audiences',1);
INSERT INTO migrations VALUES(22,'2026_08_23_210000_couple_project_memberships_to_workspace_memberships',1);
INSERT INTO migrations VALUES(23,'2026_08_23_220000_harden_agent_mutation_integrity',1);
INSERT INTO migrations VALUES(24,'2026_08_23_230000_create_workspace_invoice_counters',1);
INSERT INTO migrations VALUES(25,'2026_08_23_235000_add_dynamic_registration_metadata_to_oauth_clients',1);
INSERT INTO migrations VALUES(26,'2026_08_24_000000_rename_external_import_ledger_tables',1);
INSERT INTO migrations VALUES(27,'2026_08_24_000001_rename_external_import_columns_and_indexes',1);
INSERT INTO migrations VALUES(28,'2026_08_25_000001_scope_agent_mutation_receipts_by_workspace',1);
INSERT INTO migrations VALUES(29,'2026_08_26_000000_restore_billing_ledger_columns',1);
INSERT INTO migrations VALUES(30,'2026_08_26_000100_add_billing_rate_source_to_time_entries',1);
INSERT INTO migrations VALUES(31,'2026_08_26_000200_add_access_scope_to_client_company_memberships',1);
INSERT INTO migrations VALUES(32,'2026_08_26_000300_add_split_lineage_to_time_entries',1);
INSERT INTO migrations VALUES(33,'2026_08_26_000400_link_milestone_tasks_to_invoice_lines',1);
INSERT INTO migrations VALUES(34,'2026_08_27_000000_restore_invoice_starting_balances',1);
INSERT INTO migrations VALUES(35,'2026_08_28_000000_align_invoice_hour_precision',1);
INSERT INTO migrations VALUES(36,'2026_08_28_010000_one_task_per_milestone_line',1);
INSERT INTO migrations VALUES(37,'2026_08_29_000000_add_subcontractor_billing_mode_to_time_entries',1);
INSERT INTO migrations VALUES(38,'2026_08_30_000000_add_native_subjects_to_client_company_activity',1);
INSERT INTO migrations VALUES(39,'2026_08_31_000000_add_workspace_id_to_client_company_memberships',1);
INSERT INTO migrations VALUES(40,'2026_08_31_000100_index_tenant_parents_by_workspace',1);
INSERT INTO migrations VALUES(41,'2026_08_31_000200_add_composite_tenant_foreign_keys',1);
INSERT INTO migrations VALUES(42,'2026_09_01_000000_require_an_agreement_start_date',1);
INSERT INTO migrations VALUES(43,'2026_09_02_000000_add_oauth_server_metadata',1);
INSERT INTO migrations VALUES(44,'2026_09_03_000000_remember_where_a_user_was_working',1);
INSERT INTO migrations VALUES(45,'2026_09_03_000100_record_what_was_sent_with_an_invoice',1);
INSERT INTO migrations VALUES(46,'2026_09_04_000000_index_invoice_delivery_provider_references',1);
INSERT INTO migrations VALUES(47,'2026_09_04_010000_create_client_expenses',1);
INSERT INTO migrations VALUES(48,'2026_09_05_000000_record_where_a_project_is_worked',1);
INSERT INTO migrations VALUES(49,'2026_09_08_010000_add_expense_invoice_claims',1);
INSERT INTO migrations VALUES(50,'2026_09_08_020000_add_expense_lock_version',1);
INSERT INTO migrations VALUES(51,'2026_09_08_030000_create_expense_schedules',1);
INSERT INTO migrations VALUES(52,'2026_09_08_070000_allow_expense_attachments',1);
INSERT INTO migrations VALUES(53,'2026_09_15_000000_add_invoice_review_delivery',1);
INSERT INTO migrations VALUES(54,'2026_09_27_000000_add_credit_revision_to_client_companies',1);
INSERT INTO migrations VALUES(55,'2026_09_28_000000_add_lock_version_to_client_invoice_payments',1);
INSERT INTO migrations VALUES(56,'2026_09_28_010000_add_hours_statement_to_client_invoices',1);
INSERT INTO migrations VALUES(57,'2026_09_29_000000_record_the_catch_up_an_invoice_was_sized_against',1);
INSERT INTO migrations VALUES(58,'2026_09_29_010000_store_time_entry_dates_without_a_time',1);
INSERT INTO migrations VALUES(59,'2026_09_30_000000_store_every_calendar_date_without_a_time',1);
INSERT INTO migrations VALUES(60,'2026_10_08_000000_add_agent_revisions_to_clients_and_agreements',1);
INSERT INTO migrations VALUES(61,'2026_10_08_000002_add_agent_revision_to_billing_schedules',1);
INSERT INTO migrations VALUES(62,'2026_10_08_010000_add_agent_revision_to_client_proposals',1);
INSERT INTO migrations VALUES(63,'2026_10_08_130000_add_expense_schedule_lock_version',1);
INSERT INTO migrations VALUES(64,'2026_10_08_140000_add_workspace_creation_reservations_and_attachment_revisions',1);
INSERT INTO migrations VALUES(65,'2026_10_10_000000_create_access_audit_events',1);
INSERT INTO migrations VALUES(66,'2026_10_11_000000_add_provider_identity_to_oauth_credentials',1);
INSERT INTO migrations VALUES(67,'2026_10_11_010000_add_provider_identity_to_personal_access_tokens',1);
INSERT INTO migrations VALUES(68,'2026_10_11_020000_create_delegated_access_nonces',1);
INSERT INTO migrations VALUES(69,'2026_10_11_020100_create_delegated_access_receipts',1);
INSERT INTO migrations VALUES(70,'2026_10_11_030000_add_response_snapshot_to_agent_mutation_receipts',1);
INSERT INTO migrations VALUES(71,'2026_10_11_120000_add_client_id_to_oauth_refresh_tokens',1);
INSERT INTO migrations VALUES(72,'2026_10_12_000000_create_client_invoice_corrections',1);
INSERT INTO migrations VALUES(73,'2026_10_12_060000_create_billing_maintenance_locks',1);
INSERT INTO migrations VALUES(74,'2026_10_12_120000_add_scheduled_issue_date_to_client_invoices',1);
INSERT INTO migrations VALUES(75,'2026_10_12_180000_create_client_portal_invitations',1);
INSERT INTO migrations VALUES(76,'2026_10_12_180000_link_svc_records_to_square',1);
INSERT INTO migrations VALUES(77,'2026_10_12_210000_create_square_mirror',1);
INSERT INTO migrations VALUES(78,'2026_10_13_090000_widen_square_customer_mappings',1);
INSERT INTO migrations VALUES(79,'2026_10_14_090000_create_square_webhook_receipts',1);
INSERT INTO migrations VALUES(80,'2026_10_14_091000_make_provider_payment_identifiers_case_sensitive',1);
INSERT INTO migrations VALUES(81,'2026_10_14_120000_create_invoice_reminder_logs',1);
INSERT INTO migrations VALUES(82,'2026_10_18_120000_backfill_oauth_refresh_token_owners',1);
INSERT INTO migrations VALUES(83,'2026_10_19_120000_add_review_flags_to_payment_reconciliations',1);
INSERT INTO migrations VALUES(84,'2026_10_19_130000_add_dispute_facts_to_client_invoice_payments',1);
INSERT INTO migrations VALUES(85,'2026_10_19_140000_create_client_invoice_payment_refunds',1);
INSERT INTO migrations VALUES(86,'2026_10_19_140000_index_change_feeds_by_update_time',1);
INSERT INTO migrations VALUES(87,'2026_10_20_000000_add_settlement_facts_to_client_invoice_payments',1);
INSERT INTO migrations VALUES(88,'2026_10_20_000100_create_processor_payouts',1);
INSERT INTO migrations VALUES(89,'2026_10_20_090000_create_square_pending_settlements',1);
INSERT INTO migrations VALUES(90,'2026_10_20_104143_add_finance_reference_to_client_expenses',1);
INSERT INTO migrations VALUES(91,'2026_10_20_220000_add_deferred_backlog_portal_visibility_to_agreements',1);
INSERT INTO migrations VALUES(92,'2026_10_20_230000_create_oauth_credential_lineages',1);
INSERT INTO migrations VALUES(93,'2026_10_21_000000_widen_square_payment_ids_basis',1);
INSERT INTO migrations VALUES(94,'2026_10_21_000100_bind_square_series_marker_to_a_location',1);
INSERT INTO migrations VALUES(95,'2026_10_21_000200_record_square_invoices_covered_by_svc_invoices',1);
INSERT INTO migrations VALUES(96,'2026_10_21_210000_create_processor_movements',1);
INSERT INTO migrations VALUES(97,'2026_10_22_100000_create_historical_client_foundation',1);
INSERT INTO migrations VALUES(98,'2026_10_22_110000_create_historical_library',1);
INSERT INTO migrations VALUES(99,'2026_10_22_180000_add_task_planning_dates',1);
INSERT INTO migrations VALUES(100,'2026_10_22_180010_create_task_assignments',1);
INSERT INTO migrations VALUES(101,'2026_10_22_180020_widen_client_profile_notes',1);
INSERT INTO migrations VALUES(102,'2026_10_22_180100_create_client_collaboration_topics',1);
INSERT INTO migrations VALUES(103,'2026_10_22_180110_create_client_comments',1);
INSERT INTO migrations VALUES(104,'2026_10_22_180150_add_task_organization_reader',1);
INSERT INTO migrations VALUES(105,'2026_10_22_180160_add_task_organization_writers',1);
INSERT INTO migrations VALUES(106,'2026_10_22_180200_add_attachment_reader_visibility',1);
INSERT INTO migrations VALUES(107,'2026_10_22_180210_use_private_attachment_default',1);
INSERT INTO migrations VALUES(108,'2026_10_22_180300_create_task_labels',1);
INSERT INTO migrations VALUES(109,'2026_10_22_180310_create_task_dependencies',1);
INSERT INTO migrations VALUES(110,'2026_10_22_180320_create_task_contact_responsibilities',1);
INSERT INTO migrations VALUES(111,'2026_10_28_090000_mark_invoices_with_disputed_payments',1);
INSERT INTO migrations VALUES(112,'2026_10_31_210917_add_oauth_identity_to_users_table',1);
INSERT INTO migrations VALUES(113,'2026_11_01_120000_add_reopen_and_reissue_to_client_invoices',1);
INSERT INTO migrations VALUES(114,'2026_11_03_230000_add_deferred_work_statement_to_client_invoices',1);
INSERT INTO migrations VALUES(115,'2026_11_06_000000_add_duration_style_to_users_table',1);
INSERT INTO migrations VALUES(116,'2026_11_06_081700_create_accounting_invoice_entries_table',1);
INSERT INTO migrations VALUES(117,'2026_11_06_090000_create_workspace_payment_connections',1);
INSERT INTO migrations VALUES(118,'2026_11_06_120000_add_publishable_key_to_workspace_payment_connections',1);
INSERT INTO migrations VALUES(119,'2026_11_06_120000_create_client_invoice_deferred_projects',1);
INSERT INTO migrations VALUES(120,'2026_11_06_140000_add_client_approval',1);
INSERT INTO migrations VALUES(121,'2026_11_07_090000_create_shared_project_participations',1);
INSERT INTO migrations VALUES(122,'2026_11_07_100000_create_reviewer_grants',1);
INSERT INTO migrations VALUES(123,'2026_11_08_090000_create_mcp_credential_settings',1);
INSERT INTO migrations VALUES(124,'2026_11_08_100000_add_submission_and_light_workspaces',1);
INSERT INTO migrations VALUES(125,'2026_11_09_090000_create_autopay',1);
INSERT INTO migrations VALUES(126,'2026_11_09_100000_make_expense_receipts_private',1);
INSERT INTO migrations VALUES(127,'2026_11_10_090000_add_autopay_resolution_and_connection_refusal',1);
INSERT INTO migrations VALUES(128,'2026_11_10_100000_add_webhook_health_to_workspace_payment_connections',1);
INSERT INTO migrations VALUES(129,'2026_11_11_090000_add_client_dispute_settlement',1);
INSERT INTO migrations VALUES(130,'2026_11_11_090000_create_palette_recents',1);
INSERT INTO migrations VALUES(131,'2026_11_11_100000_reconcile_default_payment_method_by_revision',1);
INSERT INTO migrations VALUES(132,'2026_11_12_090000_add_held_work_basis_to_client_invoices',1);
INSERT INTO migrations VALUES(133,'2026_11_12_090000_create_service_packages',1);
INSERT INTO migrations VALUES(134,'2026_11_12_100000_create_service_package_requests_and_enrollments',1);
INSERT INTO migrations VALUES(135,'2026_11_13_090000_add_acceptance_to_service_package_enrollments',1);
INSERT INTO migrations VALUES(136,'2026_11_14_090000_add_signup_payment_to_service_package_enrollments',1);
INSERT INTO migrations VALUES(137,'2026_11_15_090000_add_automatic_invoicing_to_service_package_enrollments',1);
INSERT INTO migrations VALUES(138,'2026_11_16_090000_add_cancellation_to_client_agreements',1);
INSERT INTO migrations VALUES(139,'2026_11_17_090000_create_service_package_migrations',1);
