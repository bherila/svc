/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `access_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_audit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `actor_type` varchar(16) NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `subject_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `client_company_public_id` char(36) DEFAULT NULL,
  `before_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_state`)),
  `after_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_state`)),
  `operation_id` varchar(64) DEFAULT NULL,
  `request_id` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_audit_events_public_id_unique` (`public_id`),
  KEY `access_audit_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `access_audit_events_subject_user_id_foreign` (`subject_user_id`),
  KEY `access_audit_events_workspace_id_created_at_index` (`workspace_id`,`created_at`),
  KEY `access_audit_events_workspace_id_operation_id_index` (`workspace_id`,`operation_id`),
  CONSTRAINT `access_audit_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `access_audit_events_subject_user_id_foreign` FOREIGN KEY (`subject_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `access_audit_events_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounting_invoice_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_invoice_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `effective_on` date DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_invoice_entries_workspace_id_source_key_unique` (`workspace_id`,`source_key`),
  UNIQUE KEY `accounting_invoice_entries_public_id_unique` (`public_id`),
  KEY `accounting_invoice_entries_client_invoice_id_foreign` (`client_invoice_id`),
  KEY `accounting_invoice_ws_invoice_fk` (`workspace_id`,`client_invoice_id`),
  KEY `accounting_invoice_sync` (`workspace_id`,`updated_at`,`id`),
  KEY `accounting_invoice_entries_workspace_id_effective_on_index` (`workspace_id`,`effective_on`),
  CONSTRAINT `accounting_invoice_entries_client_invoice_id_foreign` FOREIGN KEY (`client_invoice_id`) REFERENCES `client_invoices` (`id`),
  CONSTRAINT `accounting_invoice_entries_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`),
  CONSTRAINT `accounting_invoice_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_mutation_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_mutation_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `oauth_client_id` varchar(100) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `operation` varchar(100) NOT NULL,
  `affected_public_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`affected_public_ids`)),
  `request_id` char(36) NOT NULL,
  `outcome` varchar(30) NOT NULL,
  `error_category` varchar(40) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_mutation_audits_user_id_foreign` (`user_id`),
  KEY `agent_mutation_audits_workspace_id_created_at_index` (`workspace_id`,`created_at`),
  CONSTRAINT `agent_mutation_audits_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_mutation_audits_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_mutation_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_mutation_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `workspace_id` bigint(20) unsigned DEFAULT NULL,
  `oauth_client_id` varchar(100) NOT NULL,
  `operation` varchar(100) NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `request_digest` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'completed',
  `result_public_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`result_public_ids`)),
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `response_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_snapshot`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_mut_receipts_workspace_idempotency_unique` (`workspace_id`,`oauth_client_id`,`user_id`,`operation`,`idempotency_key`),
  KEY `agent_mutation_receipts_user_id_foreign` (`user_id`),
  CONSTRAINT `agent_mutation_receipts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_mutation_receipts_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_workspace_creations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_workspace_creations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `oauth_client_id` varchar(100) NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `request_digest` char(64) NOT NULL,
  `created_workspace_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_workspace_creation_actor_key` (`user_id`,`oauth_client_id`,`idempotency_key`),
  KEY `agent_workspace_creations_created_workspace_id_foreign` (`created_workspace_id`),
  CONSTRAINT `agent_workspace_creations_created_workspace_id_foreign` FOREIGN KEY (`created_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_workspace_creations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreement_cancellation_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_cancellation_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned NOT NULL,
  `status` varchar(16) NOT NULL,
  `requested_end_on` date NOT NULL,
  `reason` varchar(1000) DEFAULT NULL,
  `requested_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `requested_at` timestamp NOT NULL,
  `decided_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decided_end_on` date DEFAULT NULL,
  `notice_waived` tinyint(1) NOT NULL DEFAULT 0,
  `decision_note` varchar(1000) DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agcr_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `agcr_public_id_unique` (`public_id`),
  KEY `agcr_requester_fk` (`requested_by_user_id`),
  KEY `agcr_decider_fk` (`decided_by_user_id`),
  KEY `agcr_ws_status_idx` (`workspace_id`,`status`,`id`),
  KEY `agcr_ws_agreement_status_idx` (`workspace_id`,`client_agreement_id`,`status`),
  KEY `agcr_ws_company_fk` (`workspace_id`,`client_company_id`),
  CONSTRAINT `agcr_decider_fk` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agcr_requester_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agcr_ws_agreement_fk` FOREIGN KEY (`workspace_id`, `client_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`),
  CONSTRAINT `agcr_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `agreement_cancellation_requests_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agreement_scheduled_terminations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_scheduled_terminations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned NOT NULL,
  `agreement_cancellation_request_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `source` varchar(16) NOT NULL,
  `ends_on` date NOT NULL,
  `previous_ends_on` date DEFAULT NULL,
  `scheduled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `scheduled_at` timestamp NOT NULL,
  `undone_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `undone_at` timestamp NULL DEFAULT NULL,
  `terminated_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agst_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `agst_public_id_unique` (`public_id`),
  KEY `agst_scheduler_fk` (`scheduled_by_user_id`),
  KEY `agst_undoer_fk` (`undone_by_user_id`),
  KEY `agst_status_idx` (`status`,`id`),
  KEY `agst_ws_agreement_status_idx` (`workspace_id`,`client_agreement_id`,`status`),
  KEY `agst_ws_request_idx` (`workspace_id`,`agreement_cancellation_request_id`),
  CONSTRAINT `agreement_scheduled_terminations_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agst_scheduler_fk` FOREIGN KEY (`scheduled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agst_undoer_fk` FOREIGN KEY (`undone_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agst_ws_agreement_fk` FOREIGN KEY (`workspace_id`, `client_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`),
  CONSTRAINT `agst_ws_request_fk` FOREIGN KEY (`workspace_id`, `agreement_cancellation_request_id`) REFERENCES `agreement_cancellation_requests` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bherila_auth_delegated_nonces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bherila_auth_delegated_nonces` (
  `key` varchar(64) NOT NULL,
  `expires_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`key`),
  KEY `bherila_auth_delegated_nonces_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bherila_auth_delegated_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bherila_auth_delegated_receipts` (
  `application` varchar(191) NOT NULL,
  `operation_key` varchar(64) NOT NULL,
  `operation_id` varchar(64) NOT NULL,
  `actor` varchar(64) NOT NULL,
  `request_hash` varchar(64) NOT NULL,
  `status` smallint(5) unsigned DEFAULT NULL,
  `response` mediumtext DEFAULT NULL,
  `claimed_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`application`,`operation_key`),
  KEY `bherila_auth_delegated_receipts_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_maintenance_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_maintenance_locks` (
  `workspace_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`workspace_id`),
  CONSTRAINT `billing_maintenance_locks_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_agreement_recurring_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_agreement_recurring_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned NOT NULL,
  `description` text NOT NULL,
  `cadence` varchar(255) NOT NULL DEFAULT 'monthly',
  `anchor_month` tinyint(3) unsigned DEFAULT NULL,
  `anchor_day` tinyint(3) unsigned DEFAULT NULL,
  `effective_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 1.000,
  `amount` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_agreement_recurring_items_public_id_unique` (`public_id`),
  KEY `cari_workspace_agreement_idx` (`workspace_id`,`client_agreement_id`),
  KEY `cari_agreement_active_idx` (`client_agreement_id`,`is_active`),
  CONSTRAINT `cari_ws_agreement_fk` FOREIGN KEY (`workspace_id`, `client_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_agreement_recurring_items_client_agreement_id_foreign` FOREIGN KEY (`client_agreement_id`) REFERENCES `client_agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_agreement_recurring_items_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_agreements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `source_proposal_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `starts_on` date NOT NULL,
  `ends_on` date DEFAULT NULL,
  `agreement_text` text DEFAULT NULL,
  `agreement_link` varchar(2048) DEFAULT NULL,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `currency` char(3) NOT NULL,
  `hourly_rate_amount` bigint(20) unsigned DEFAULT NULL,
  `retainer_amount` bigint(20) unsigned DEFAULT NULL,
  `retainer_minutes` int(10) unsigned DEFAULT NULL,
  `catch_up_threshold_minutes` int(10) unsigned DEFAULT NULL,
  `period_retainer_minutes` int(10) unsigned DEFAULT NULL,
  `period_retainer_amount` bigint(20) unsigned DEFAULT NULL,
  `billing_cadence` varchar(255) NOT NULL DEFAULT 'one_time',
  `rollover_policy` varchar(255) DEFAULT NULL,
  `rollover_months` int(10) unsigned DEFAULT NULL,
  `initial_rollover_minutes` int(10) unsigned DEFAULT NULL,
  `bill_overage_interim` tinyint(1) DEFAULT NULL,
  `first_cycle_proration` varchar(30) DEFAULT NULL,
  `notice_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `minimum_term_months` smallint(5) unsigned DEFAULT NULL,
  `cancellation_mode` varchar(16) NOT NULL DEFAULT 'none',
  `activated_at` timestamp NULL DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `signed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `signer_name` varchar(255) DEFAULT NULL,
  `signer_title` varchar(255) DEFAULT NULL,
  `terminated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `square_series_key` char(6) DEFAULT NULL,
  `square_location_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `show_deferred_backlog_to_client` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_agreements_public_id_unique` (`public_id`),
  UNIQUE KEY `client_agreements_workspace_id_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `client_agreements_source_proposal_id_unique` (`source_proposal_id`),
  UNIQUE KEY `ca_ws_square_location_series_unique` (`workspace_id`,`square_location_id`,`square_series_key`),
  KEY `client_agreements_client_project_id_foreign` (`client_project_id`),
  KEY `client_agreements_signed_by_user_id_foreign` (`signed_by_user_id`),
  KEY `client_agreements_workspace_id_status_index` (`workspace_id`,`status`),
  KEY `client_agreements_workspace_id_client_company_id_index` (`workspace_id`,`client_company_id`),
  KEY `client_agreements_client_company_id_is_visible_to_client_index` (`client_company_id`,`is_visible_to_client`),
  CONSTRAINT `cag_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_agreements_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_agreements_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_agreements_signed_by_user_id_foreign` FOREIGN KEY (`signed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_agreements_source_proposal_id_foreign` FOREIGN KEY (`source_proposal_id`) REFERENCES `client_proposals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_agreements_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `record_type` enum('company','project','task','proposal','agreement','invoice','expense','topic','comment') NOT NULL,
  `record_public_id` char(36) NOT NULL,
  `object_key` varchar(512) NOT NULL,
  `staged_object_key` varchar(512) DEFAULT NULL,
  `original_filename` text NOT NULL,
  `media_type` varchar(255) NOT NULL,
  `bytes` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploader_id` bigint(20) unsigned DEFAULT NULL,
  `lifecycle_state` enum('staged','available','deleting','deleted','corrupt') NOT NULL DEFAULT 'staged',
  `available_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_attachments_public_id_unique` (`public_id`),
  UNIQUE KEY `client_attachments_object_key_unique` (`object_key`),
  UNIQUE KEY `client_attachments_staged_object_key_unique` (`staged_object_key`),
  KEY `client_attachments_uploader_id_foreign` (`uploader_id`),
  KEY `ca_workspace_record_idx` (`workspace_id`,`record_type`,`record_public_id`),
  KEY `client_attachments_lifecycle_state_created_at_index` (`lifecycle_state`,`created_at`),
  CONSTRAINT `client_attachments_uploader_id_foreign` FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_attachments_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_autopay_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_autopay_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `action` varchar(16) NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_name` varchar(191) DEFAULT NULL,
  `actor_role` varchar(16) NOT NULL,
  `method` varchar(24) NOT NULL,
  `limit_amount` bigint(20) unsigned DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `payment_method_label` varchar(120) DEFAULT NULL,
  `wording` text DEFAULT NULL,
  `attestation` text DEFAULT NULL,
  `reason` varchar(191) DEFAULT NULL,
  `notice_sent` tinyint(1) NOT NULL DEFAULT 0,
  `occurred_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_autopay_consents_public_id_unique` (`public_id`),
  KEY `aco_actor_fk` (`actor_user_id`),
  KEY `aco_ws_company_idx` (`workspace_id`,`client_company_id`,`id`),
  CONSTRAINT `aco_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aco_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_autopay_consents_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_billing_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_billing_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned NOT NULL,
  `cadence` varchar(24) NOT NULL,
  `anchor_month` tinyint(3) unsigned DEFAULT NULL,
  `anchor_day` tinyint(3) unsigned DEFAULT NULL,
  `next_run_on` date NOT NULL,
  `due_days` int(10) unsigned NOT NULL DEFAULT 0,
  `currency` char(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `line_template` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`line_template`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_schedule_agreement_unique` (`workspace_id`,`client_agreement_id`),
  UNIQUE KEY `client_billing_schedules_public_id_unique` (`public_id`),
  KEY `client_billing_schedules_client_company_id_foreign` (`client_company_id`),
  KEY `cbs_workspace_active_next_run_idx` (`workspace_id`,`is_active`,`next_run_on`),
  KEY `cbs_ws_company_idx` (`workspace_id`,`client_company_id`),
  CONSTRAINT `cbs_ws_agreement_fk` FOREIGN KEY (`workspace_id`, `client_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cbs_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `client_billing_schedules_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`),
  CONSTRAINT `client_billing_schedules_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_collaboration_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_collaboration_topics` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` mediumtext NOT NULL,
  `author_user_id` bigint(20) unsigned DEFAULT NULL,
  `author_name` varchar(255) NOT NULL,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cct_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `client_collaboration_topics_public_id_unique` (`public_id`),
  KEY `client_collaboration_topics_author_user_id_foreign` (`author_user_id`),
  KEY `cct_company_page_idx` (`workspace_id`,`client_company_id`,`id`),
  KEY `cct_ws_project_fk` (`workspace_id`,`client_project_id`),
  CONSTRAINT `cct_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `cct_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`),
  CONSTRAINT `client_collaboration_topics_author_user_id_foreign` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_collaboration_topics_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `client_task_id` bigint(20) unsigned DEFAULT NULL,
  `client_collaboration_topic_id` bigint(20) unsigned DEFAULT NULL,
  `body` mediumtext NOT NULL,
  `author_user_id` bigint(20) unsigned DEFAULT NULL,
  `author_name` varchar(255) NOT NULL,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `shared_with_participants` tinyint(1) NOT NULL DEFAULT 0,
  `author_workspace_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cc_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `client_comments_public_id_unique` (`public_id`),
  KEY `client_comments_author_user_id_foreign` (`author_user_id`),
  KEY `cc_task_page_idx` (`workspace_id`,`client_task_id`,`id`),
  KEY `cc_topic_page_idx` (`workspace_id`,`client_collaboration_topic_id`,`id`),
  KEY `cc_ws_company_fk` (`workspace_id`,`client_company_id`),
  KEY `cc_ws_project_fk` (`workspace_id`,`client_project_id`),
  KEY `cc_author_workspace_fk` (`author_workspace_id`),
  CONSTRAINT `cc_author_workspace_fk` FOREIGN KEY (`author_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cc_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `cc_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`),
  CONSTRAINT `cc_ws_task_fk` FOREIGN KEY (`workspace_id`, `client_task_id`) REFERENCES `client_tasks` (`workspace_id`, `id`),
  CONSTRAINT `cc_ws_topic_fk` FOREIGN KEY (`workspace_id`, `client_collaboration_topic_id`) REFERENCES `client_collaboration_topics` (`workspace_id`, `id`),
  CONSTRAINT `client_comments_author_user_id_foreign` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_comments_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `billing_email` varchar(255) DEFAULT NULL,
  `automatic_invoice_email_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `automatic_invoice_email_delay_days` smallint(5) unsigned DEFAULT NULL,
  `credit_revision` bigint(20) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `payment_reminders_enabled_at` timestamp NULL DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `phone` varchar(191) DEFAULT NULL,
  `website_url` varchar(191) DEFAULT NULL,
  `tax_id` varchar(191) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `notes` mediumtext DEFAULT NULL,
  `autopay_enabled_at` timestamp NULL DEFAULT NULL,
  `autopay_limit_amount` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_companies_workspace_id_slug_unique` (`workspace_id`,`slug`),
  UNIQUE KEY `client_companies_public_id_unique` (`public_id`),
  UNIQUE KEY `client_companies_workspace_id_id_unique` (`workspace_id`,`id`),
  KEY `client_companies_ws_updated_id_index` (`workspace_id`,`updated_at`,`id`),
  CONSTRAINT `client_companies_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_company_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_company_activity` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `external_subject_id` bigint(20) unsigned DEFAULT NULL,
  `subject_public_id` char(36) DEFAULT NULL,
  `deduplication_key` varchar(64) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_company_activity_public_id_unique` (`public_id`),
  UNIQUE KEY `cca_workspace_deduplication_unique` (`workspace_id`,`deduplication_key`),
  KEY `client_company_activity_client_company_id_foreign` (`client_company_id`),
  KEY `client_company_activity_actor_user_id_foreign` (`actor_user_id`),
  KEY `cca_workspace_company_created_idx` (`workspace_id`,`client_company_id`,`created_at`),
  KEY `cca_workspace_subject_public_idx` (`workspace_id`,`subject_type`,`subject_public_id`),
  CONSTRAINT `cca_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_company_activity_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_company_activity_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_company_activity_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_company_memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_company_memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'client',
  `access_scope` varchar(20) NOT NULL DEFAULT 'company',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `public_id` char(36) NOT NULL,
  `can_approve_time_and_expenses` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_company_memberships_client_company_id_user_id_unique` (`client_company_id`,`user_id`),
  UNIQUE KEY `client_company_memberships_public_id_unique` (`public_id`),
  UNIQUE KEY `client_company_memberships_workspace_id_id_unique` (`workspace_id`,`id`),
  KEY `client_company_memberships_user_id_foreign` (`user_id`),
  KEY `ccm_workspace_idx` (`workspace_id`),
  KEY `ccm_ws_company_idx` (`workspace_id`,`client_company_id`),
  CONSTRAINT `ccm_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_company_memberships_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_company_memberships_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_company_memberships_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `title` varchar(191) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(191) DEFAULT NULL,
  `notes` mediumtext DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contacts_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `client_contacts_public_id_unique` (`public_id`),
  UNIQUE KEY `contacts_ws_company_id_unique` (`workspace_id`,`client_company_id`,`id`),
  CONSTRAINT `client_contacts_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contacts_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_expense_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_expense_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `starts_on` date NOT NULL,
  `cadence` varchar(255) NOT NULL,
  `next_occurrence` int(10) unsigned NOT NULL DEFAULT 0,
  `amount` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `description` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ces_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `client_expense_schedules_public_id_unique` (`public_id`),
  KEY `ces_ws_company_fk` (`workspace_id`,`client_company_id`),
  KEY `ces_ws_project_fk` (`workspace_id`,`client_project_id`),
  CONSTRAINT `ces_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `ces_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`),
  CONSTRAINT `client_expense_schedules_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `spent_on` date NOT NULL,
  `amount` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `description` text NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `client_invoice_line_id` bigint(20) unsigned DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `client_expense_schedule_id` bigint(20) unsigned DEFAULT NULL,
  `occurrence_on` date DEFAULT NULL,
  `external_finance_transaction_uuid` char(36) DEFAULT NULL,
  `client_approved_at` timestamp NULL DEFAULT NULL,
  `client_approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_approval_source` varchar(16) DEFAULT NULL,
  `client_disputed_at` timestamp NULL DEFAULT NULL,
  `client_disputed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_dispute_note` varchar(1000) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_dispute_settlement` varchar(16) DEFAULT NULL,
  `client_dispute_reply` varchar(1000) DEFAULT NULL,
  `client_dispute_settled_at` timestamp NULL DEFAULT NULL,
  `client_dispute_settled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_expenses_public_id_unique` (`public_id`),
  UNIQUE KEY `cex_ws_line_unique` (`workspace_id`,`client_invoice_line_id`),
  UNIQUE KEY `cex_schedule_occurrence_unique` (`workspace_id`,`client_expense_schedule_id`,`occurrence_on`),
  KEY `client_expenses_client_project_id_foreign` (`client_project_id`),
  KEY `client_expenses_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `client_expenses_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `client_expenses_workspace_id_spent_on_index` (`workspace_id`,`spent_on`),
  KEY `client_expenses_workspace_id_status_index` (`workspace_id`,`status`),
  KEY `client_expenses_workspace_id_deleted_at_index` (`workspace_id`,`deleted_at`),
  KEY `client_expenses_client_company_id_spent_on_index` (`client_company_id`,`spent_on`),
  KEY `cex_ws_company_idx` (`workspace_id`,`client_company_id`),
  KEY `client_expenses_client_approved_by_user_id_foreign` (`client_approved_by_user_id`),
  KEY `client_expenses_client_disputed_by_user_id_foreign` (`client_disputed_by_user_id`),
  KEY `cex_submitted_by_fk` (`submitted_by_user_id`),
  KEY `cex_ws_submitted_idx` (`workspace_id`,`submitted_at`),
  KEY `client_expenses_client_dispute_settled_by_user_id_foreign` (`client_dispute_settled_by_user_id`),
  CONSTRAINT `cex_submitted_by_fk` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cex_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cex_ws_line_fk` FOREIGN KEY (`workspace_id`, `client_invoice_line_id`) REFERENCES `client_invoice_lines` (`workspace_id`, `id`),
  CONSTRAINT `cex_ws_schedule_fk` FOREIGN KEY (`workspace_id`, `client_expense_schedule_id`) REFERENCES `client_expense_schedules` (`workspace_id`, `id`),
  CONSTRAINT `client_expenses_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_expenses_client_approved_by_user_id_foreign` FOREIGN KEY (`client_approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_expenses_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_expenses_client_dispute_settled_by_user_id_foreign` FOREIGN KEY (`client_dispute_settled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_expenses_client_disputed_by_user_id_foreign` FOREIGN KEY (`client_disputed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_expenses_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_expenses_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_expenses_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_administrator_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_administrator_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `recipient_user_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_revision` int(10) unsigned NOT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `open_url` varchar(2048) NOT NULL,
  `client_name` varchar(160) NOT NULL,
  `invoice_number` varchar(80) NOT NULL,
  `currency` char(3) NOT NULL,
  `total_amount` bigint(20) unsigned NOT NULL,
  `pdf_filename` varchar(255) DEFAULT NULL,
  `pdf_content_base64` longtext DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `attempt_history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attempt_history`)),
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `provider_message_reference` varchar(255) DEFAULT NULL,
  `error_summary` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cian_invoice_revision_unique` (`client_invoice_id`,`invoice_revision`),
  UNIQUE KEY `client_invoice_administrator_notifications_public_id_unique` (`public_id`),
  KEY `cian_recipient_user_fk` (`recipient_user_id`),
  KEY `cian_dispatch_due_idx` (`status`,`next_attempt_at`,`id`),
  KEY `cian_ws_invoice_fk` (`workspace_id`,`client_invoice_id`),
  CONSTRAINT `cian_invoice_fk` FOREIGN KEY (`client_invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cian_recipient_user_fk` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cian_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_administrator_notifications_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_corrections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `from_revision` int(10) unsigned NOT NULL,
  `to_revision` int(10) unsigned NOT NULL,
  `reason` text NOT NULL,
  `previous_due_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `line_diffs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`line_diffs`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_correction_revision_unique` (`workspace_id`,`client_invoice_id`,`to_revision`),
  UNIQUE KEY `client_invoice_corrections_public_id_unique` (`public_id`),
  CONSTRAINT `client_invoice_corrections_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_correction_tenant_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_deferred_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_deferred_projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cidp_invoice_project_unique` (`workspace_id`,`client_invoice_id`,`client_project_id`),
  KEY `cidp_ws_project_idx` (`workspace_id`,`client_project_id`),
  CONSTRAINT `cidp_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_deferred_projects_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_email_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_email_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `origin` varchar(24) NOT NULL DEFAULT 'manual',
  `invoice_revision` int(10) unsigned NOT NULL DEFAULT 0,
  `attempt_number` smallint(5) unsigned NOT NULL DEFAULT 1,
  `idempotency_key` varchar(255) DEFAULT NULL,
  `recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`recipients`)),
  `bcc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bcc`)),
  `subject` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `provider_message_reference` varchar(255) DEFAULT NULL,
  `provider_status` varchar(40) DEFAULT NULL,
  `provider_status_at` timestamp NULL DEFAULT NULL,
  `error_summary` varchar(1000) DEFAULT NULL,
  `external_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`external_metadata`)),
  `queued_at` timestamp NULL DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_reissue` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invoice_email_deliveries_public_id_unique` (`public_id`),
  UNIQUE KEY `cied_idempotency_unique` (`workspace_id`,`idempotency_key`),
  KEY `client_invoice_email_deliveries_client_invoice_id_foreign` (`client_invoice_id`),
  KEY `cied_workspace_invoice_status_idx` (`workspace_id`,`client_invoice_id`,`status`),
  KEY `invoice_delivery_provider_reference_idx` (`provider_message_reference`),
  KEY `cied_dispatch_due_idx` (`workspace_id`,`origin`,`status`,`next_attempt_at`),
  CONSTRAINT `cied_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_email_deliveries_client_invoice_id_foreign` FOREIGN KEY (`client_invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_email_deliveries_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_line_time_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_line_time_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_line_id` bigint(20) unsigned NOT NULL,
  `client_time_entry_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_time_entry_once` (`workspace_id`,`client_time_entry_id`),
  UNIQUE KEY `invoice_line_time_entry_unique` (`client_invoice_line_id`,`client_time_entry_id`),
  KEY `cilte_ws_line_idx` (`workspace_id`,`client_invoice_line_id`),
  CONSTRAINT `cilte_ws_line_fk` FOREIGN KEY (`workspace_id`, `client_invoice_line_id`) REFERENCES `client_invoice_lines` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cilte_ws_time_entry_fk` FOREIGN KEY (`workspace_id`, `client_time_entry_id`) REFERENCES `client_time_entries` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_line_time_entries_client_invoice_line_id_foreign` FOREIGN KEY (`client_invoice_line_id`) REFERENCES `client_invoice_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_line_time_entries_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `client_agreement_id` bigint(20) unsigned DEFAULT NULL,
  `client_agreement_recurring_item_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `description` text NOT NULL,
  `line_date` date DEFAULT NULL,
  `quantity` decimal(16,4) NOT NULL,
  `hours` decimal(10,4) DEFAULT NULL,
  `unit_amount` bigint(20) NOT NULL,
  `tax_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `total_amount` bigint(20) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invoice_lines_public_id_unique` (`public_id`),
  UNIQUE KEY `client_invoice_lines_workspace_id_id_unique` (`workspace_id`,`id`),
  KEY `client_invoice_lines_client_invoice_id_foreign` (`client_invoice_id`),
  KEY `client_invoice_lines_workspace_id_client_invoice_id_index` (`workspace_id`,`client_invoice_id`),
  KEY `client_invoice_lines_client_project_id_foreign` (`client_project_id`),
  KEY `client_invoice_lines_workspace_id_client_project_id_index` (`workspace_id`,`client_project_id`),
  KEY `cil_workspace_agreement_idx` (`workspace_id`,`client_agreement_id`),
  CONSTRAINT `cil_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_lines_client_invoice_id_foreign` FOREIGN KEY (`client_invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_lines_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invoice_lines_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_payment_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_payment_refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_payment_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `amount` bigint(20) unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `reverses_refund_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cipr_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `cipr_public_id_unique` (`public_id`),
  UNIQUE KEY `cipr_reverses_unique` (`reverses_refund_id`),
  KEY `cipr_recorded_by_fk` (`recorded_by_user_id`),
  KEY `cipr_ws_payment_idx` (`workspace_id`,`client_invoice_payment_id`,`id`),
  KEY `cipr_ws_reverses_fk` (`workspace_id`,`reverses_refund_id`),
  CONSTRAINT `cipr_recorded_by_fk` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cipr_workspace_fk` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cipr_ws_payment_fk` FOREIGN KEY (`workspace_id`, `client_invoice_payment_id`) REFERENCES `client_invoice_payments` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cipr_ws_reverses_fk` FOREIGN KEY (`workspace_id`, `reverses_refund_id`) REFERENCES `client_invoice_payment_refunds` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `amount` bigint(20) unsigned NOT NULL,
  `refunded_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `disputed_amount` bigint(20) unsigned DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `received_on` date DEFAULT NULL,
  `method` varchar(40) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `provider` varchar(40) DEFAULT NULL,
  `provider_payment_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `external_finance_transaction_uuid` char(36) DEFAULT NULL,
  `idempotency_key` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `provider_event_created_at` bigint(20) unsigned DEFAULT NULL,
  `provider_event_id` varchar(255) DEFAULT NULL,
  `provider_dispute_updated_at_ms` bigint(20) unsigned DEFAULT NULL,
  `provider_charged_at` timestamp NULL DEFAULT NULL,
  `provider_fee_amount` bigint(20) DEFAULT NULL,
  `provider_payout_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_payout_arrival_date` date DEFAULT NULL,
  `provider_payout_reported_at_ms` bigint(20) unsigned DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invoice_payments_public_id_unique` (`public_id`),
  UNIQUE KEY `client_invoice_payments_workspace_id_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `payment_idempotency_unique` (`workspace_id`,`idempotency_key`),
  UNIQUE KEY `provider_payment_unique` (`provider`,`provider_payment_identifier`),
  KEY `client_invoice_payments_client_invoice_id_foreign` (`client_invoice_id`),
  KEY `cip_workspace_invoice_status_idx` (`workspace_id`,`client_invoice_id`,`status`),
  KEY `client_invoice_payments_ws_updated_id_index` (`workspace_id`,`updated_at`,`id`),
  KEY `client_invoice_payments_ws_payout_index` (`workspace_id`,`provider`,`provider_payout_identifier`),
  CONSTRAINT `cip_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_payments_client_invoice_id_foreign` FOREIGN KEY (`client_invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_payments_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned DEFAULT NULL,
  `client_billing_schedule_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_number` varchar(80) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'draft',
  `document_revision` int(10) unsigned NOT NULL DEFAULT 0,
  `invoice_kind` varchar(32) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `scheduled_issue_date` date DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `service_period_start` date DEFAULT NULL,
  `service_period_end` date DEFAULT NULL,
  `cycle_start` date DEFAULT NULL,
  `cycle_end` date DEFAULT NULL,
  `retainer_hours_included` decimal(12,4) DEFAULT NULL,
  `hours_worked` decimal(12,4) DEFAULT NULL,
  `rollover_hours_used` decimal(12,4) DEFAULT NULL,
  `unused_hours_balance` decimal(12,4) DEFAULT NULL,
  `negative_hours_balance` decimal(12,4) DEFAULT NULL,
  `starting_unused_hours` decimal(12,4) DEFAULT NULL,
  `starting_negative_hours` decimal(12,4) DEFAULT NULL,
  `hours_billed_at_rate` decimal(12,4) DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `subtotal_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tax_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `total_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `paid_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `balance_amount` bigint(20) unsigned NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `issued_at` timestamp NULL DEFAULT NULL,
  `automatic_delivery_status` varchar(32) DEFAULT NULL,
  `automatic_delivery_delay_days` smallint(5) unsigned DEFAULT NULL,
  `automatic_delivery_due_at` timestamp NULL DEFAULT NULL,
  `automatic_delivery_held_at` timestamp NULL DEFAULT NULL,
  `automatic_delivery_note` varchar(500) DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `reopened_at` timestamp NULL DEFAULT NULL,
  `reopen_reason` text DEFAULT NULL,
  `reissued_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `hours_statement` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hours_statement`)),
  `catch_up_basis` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`catch_up_basis`)),
  `deferred_work_statement` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deferred_work_statement`)),
  `held_work_basis` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`held_work_basis`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invoices_workspace_id_invoice_number_unique` (`workspace_id`,`invoice_number`),
  UNIQUE KEY `client_invoices_public_id_unique` (`public_id`),
  UNIQUE KEY `client_invoices_workspace_id_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `billing_schedule_service_period_unique` (`client_billing_schedule_id`,`service_period_start`,`service_period_end`),
  KEY `client_invoices_client_company_id_foreign` (`client_company_id`),
  KEY `client_invoices_workspace_id_status_index` (`workspace_id`,`status`),
  KEY `client_invoices_workspace_id_client_company_id_index` (`workspace_id`,`client_company_id`),
  KEY `ci_automatic_delivery_due_idx` (`automatic_delivery_status`,`automatic_delivery_due_at`,`id`),
  KEY `client_invoices_ws_updated_id_index` (`workspace_id`,`updated_at`,`id`),
  CONSTRAINT `ci_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `client_invoices_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`),
  CONSTRAINT `client_invoices_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_portal_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_portal_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `pending_email` varchar(255) DEFAULT NULL,
  `access_scope` varchar(20) NOT NULL,
  `project_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`project_ids`)),
  `inviter_user_id` bigint(20) unsigned DEFAULT NULL,
  `provider_invitation_id` bigint(20) unsigned DEFAULT NULL,
  `provider_revocation_pending` tinyint(1) NOT NULL DEFAULT 0,
  `link` text DEFAULT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `not_applied_reason` varchar(40) DEFAULT NULL,
  `accepted_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_portal_invitations_public_id_unique` (`public_id`),
  UNIQUE KEY `portal_invitation_pending_unique` (`workspace_id`,`client_company_id`,`pending_email`),
  KEY `client_portal_invitations_inviter_user_id_foreign` (`inviter_user_id`),
  KEY `client_portal_invitations_accepted_user_id_foreign` (`accepted_user_id`),
  KEY `portal_invitation_email_status_idx` (`email`,`status`),
  KEY `portal_invitation_company_status_idx` (`workspace_id`,`client_company_id`,`status`),
  CONSTRAINT `client_portal_invitations_accepted_user_id_foreign` FOREIGN KEY (`accepted_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_portal_invitations_inviter_user_id_foreign` FOREIGN KEY (`inviter_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_portal_invitations_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cpi_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_portal_project_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_portal_project_access` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_membership_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `portal_project_access_unique` (`client_company_membership_id`,`client_project_id`),
  KEY `client_portal_project_access_client_project_id_foreign` (`client_project_id`),
  KEY `portal_project_access_workspace_idx` (`workspace_id`,`client_project_id`),
  KEY `cppa_ws_membership_idx` (`workspace_id`,`client_company_membership_id`),
  CONSTRAINT `client_portal_project_access_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_portal_project_access_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cppa_ws_membership_fk` FOREIGN KEY (`workspace_id`, `client_company_membership_id`) REFERENCES `client_company_memberships` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cppa_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `portal_access_membership_fk` FOREIGN KEY (`client_company_membership_id`) REFERENCES `client_company_memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(24) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_project_memberships_client_project_id_user_id_unique` (`client_project_id`,`user_id`),
  UNIQUE KEY `client_project_memberships_public_id_unique` (`public_id`),
  KEY `client_project_memberships_user_id_foreign` (`user_id`),
  KEY `client_project_memberships_workspace_id_user_id_index` (`workspace_id`,`user_id`),
  KEY `cpm_ws_project_idx` (`workspace_id`,`client_project_id`),
  CONSTRAINT `client_project_memberships_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_memberships_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_memberships_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_project_memberships_workspace_id_user_id_foreign` FOREIGN KEY (`workspace_id`, `user_id`) REFERENCES `workspace_memberships` (`workspace_id`, `user_id`) ON DELETE CASCADE,
  CONSTRAINT `cpm_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_participation_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_participation_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `host_workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `invited_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cppinv_public_id_unique` (`public_id`),
  KEY `cppinv_inviter_fk` (`invited_by_user_id`),
  KEY `cppinv_resolver_fk` (`resolved_by_user_id`),
  KEY `cppinv_email_idx` (`email`),
  KEY `cppinv_host_project_idx` (`host_workspace_id`,`client_project_id`),
  CONSTRAINT `cppinv_host_project_fk` FOREIGN KEY (`host_workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cppinv_host_workspace_fk` FOREIGN KEY (`host_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cppinv_inviter_fk` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cppinv_resolver_fk` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_project_participations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_project_participations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `host_workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `participant_workspace_id` bigint(20) unsigned NOT NULL,
  `invitation_id` bigint(20) unsigned DEFAULT NULL,
  `granted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NOT NULL,
  `accepted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `accepted_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `left_at` timestamp NULL DEFAULT NULL,
  `left_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `active_marker` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cppart_public_id_unique` (`public_id`),
  UNIQUE KEY `cppart_one_active_unique` (`client_project_id`,`participant_workspace_id`,`active_marker`),
  KEY `cppart_invitation_fk` (`invitation_id`),
  KEY `cppart_granter_fk` (`granted_by_user_id`),
  KEY `cppart_accepter_fk` (`accepted_by_user_id`),
  KEY `cppart_revoker_fk` (`revoked_by_user_id`),
  KEY `cppart_leaver_fk` (`left_by_user_id`),
  KEY `cppart_participant_active_idx` (`participant_workspace_id`,`active_marker`),
  KEY `cppart_host_project_idx` (`host_workspace_id`,`client_project_id`),
  CONSTRAINT `cppart_accepter_fk` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cppart_granter_fk` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cppart_host_project_fk` FOREIGN KEY (`host_workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cppart_host_workspace_fk` FOREIGN KEY (`host_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cppart_invitation_fk` FOREIGN KEY (`invitation_id`) REFERENCES `client_project_participation_invitations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cppart_leaver_fk` FOREIGN KEY (`left_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cppart_participant_fk` FOREIGN KEY (`participant_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cppart_revoker_fk` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `repository` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `shared_participation_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_projects_client_company_id_name_unique` (`client_company_id`,`name`),
  UNIQUE KEY `client_projects_public_id_unique` (`public_id`),
  UNIQUE KEY `client_projects_workspace_id_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `projects_ws_company_id_unique` (`workspace_id`,`client_company_id`,`id`),
  UNIQUE KEY `cp_shared_participation_unique` (`shared_participation_id`),
  KEY `cp_ws_company_idx` (`workspace_id`,`client_company_id`),
  CONSTRAINT `client_projects_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_projects_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cp_shared_participation_fk` FOREIGN KEY (`shared_participation_id`) REFERENCES `client_project_participations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cp_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_proposal_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_proposal_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_proposal_id` bigint(20) unsigned NOT NULL,
  `description` text NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_amount` bigint(20) unsigned NOT NULL,
  `cadence` varchar(255) NOT NULL DEFAULT 'one_time',
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_proposal_items_public_id_unique` (`public_id`),
  KEY `client_proposal_items_workspace_id_client_proposal_id_index` (`workspace_id`,`client_proposal_id`),
  KEY `client_proposal_items_client_proposal_id_sort_order_index` (`client_proposal_id`,`sort_order`),
  CONSTRAINT `client_proposal_items_client_proposal_id_foreign` FOREIGN KEY (`client_proposal_id`) REFERENCES `client_proposals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_proposal_items_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cpi_ws_proposal_fk` FOREIGN KEY (`workspace_id`, `client_proposal_id`) REFERENCES `client_proposals` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `summary` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `valid_until` date DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `sent_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `accepted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `acceptance_signer_name` varchar(255) DEFAULT NULL,
  `acceptance_signer_title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_proposals_public_id_unique` (`public_id`),
  UNIQUE KEY `client_proposals_workspace_id_id_unique` (`workspace_id`,`id`),
  KEY `client_proposals_client_project_id_foreign` (`client_project_id`),
  KEY `client_proposals_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `client_proposals_accepted_by_user_id_foreign` (`accepted_by_user_id`),
  KEY `client_proposals_workspace_id_status_index` (`workspace_id`,`status`),
  KEY `client_proposals_workspace_id_client_company_id_index` (`workspace_id`,`client_company_id`),
  KEY `client_proposals_client_company_id_status_index` (`client_company_id`,`status`),
  CONSTRAINT `client_proposals_accepted_by_user_id_foreign` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_proposals_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_proposals_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_proposals_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_proposals_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cpr_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_stripe_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_stripe_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `stripe_customer_id` varchar(255) NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `default_payment_method_revision` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_stripe_customers_workspace_id_client_company_id_unique` (`workspace_id`,`client_company_id`),
  UNIQUE KEY `client_stripe_customers_public_id_unique` (`public_id`),
  UNIQUE KEY `client_stripe_customers_stripe_customer_id_unique` (`stripe_customer_id`),
  UNIQUE KEY `client_stripe_customers_workspace_id_id_unique` (`workspace_id`,`id`),
  KEY `client_stripe_customers_client_company_id_foreign` (`client_company_id`),
  CONSTRAINT `client_stripe_customers_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`),
  CONSTRAINT `client_stripe_customers_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `csc_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_stripe_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_stripe_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned DEFAULT NULL,
  `stripe_event_id` varchar(255) NOT NULL,
  `event_type` varchar(120) NOT NULL,
  `object_id` varchar(255) DEFAULT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'received',
  `error_summary` varchar(1000) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_stripe_events_public_id_unique` (`public_id`),
  UNIQUE KEY `client_stripe_events_stripe_event_id_unique` (`stripe_event_id`),
  KEY `client_stripe_events_workspace_id_status_index` (`workspace_id`,`status`),
  CONSTRAINT `client_stripe_events_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_stripe_payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_stripe_payment_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_stripe_customer_id` bigint(20) unsigned NOT NULL,
  `stripe_payment_method_id` varchar(255) NOT NULL,
  `type` varchar(40) NOT NULL,
  `brand` varchar(40) DEFAULT NULL,
  `last4` varchar(4) DEFAULT NULL,
  `exp_month` tinyint(3) unsigned DEFAULT NULL,
  `exp_year` smallint(5) unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_stripe_payment_methods_public_id_unique` (`public_id`),
  UNIQUE KEY `client_stripe_payment_methods_stripe_payment_method_id_unique` (`stripe_payment_method_id`),
  KEY `client_stripe_payment_methods_client_company_id_foreign` (`client_company_id`),
  KEY `client_stripe_payment_methods_client_stripe_customer_id_foreign` (`client_stripe_customer_id`),
  KEY `cspm_workspace_company_idx` (`workspace_id`,`client_company_id`),
  KEY `cspm_ws_customer_idx` (`workspace_id`,`client_stripe_customer_id`),
  CONSTRAINT `client_stripe_payment_methods_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`),
  CONSTRAINT `client_stripe_payment_methods_client_stripe_customer_id_foreign` FOREIGN KEY (`client_stripe_customer_id`) REFERENCES `client_stripe_customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_stripe_payment_methods_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cspm_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `cspm_ws_customer_fk` FOREIGN KEY (`workspace_id`, `client_stripe_customer_id`) REFERENCES `client_stripe_customers` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_task_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `assignee_workspace_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_assignment_unique` (`workspace_id`,`client_task_id`,`user_id`),
  KEY `client_task_assignments_client_task_id_foreign` (`client_task_id`),
  KEY `client_task_assignments_user_id_foreign` (`user_id`),
  KEY `cta_assignee_workspace_fk` (`assignee_workspace_id`),
  CONSTRAINT `client_task_assignments_client_task_id_foreign` FOREIGN KEY (`client_task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_task_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_task_assignments_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cta_assignee_workspace_fk` FOREIGN KEY (`assignee_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_assignments_ws_task_fk` FOREIGN KEY (`workspace_id`, `client_task_id`) REFERENCES `client_tasks` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_contact_responsibilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_contact_responsibilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `client_task_id` bigint(20) unsigned NOT NULL,
  `client_contact_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_contact_responsibility_unique` (`workspace_id`,`client_task_id`,`client_contact_id`),
  KEY `task_contact_responsibility_task_fk` (`workspace_id`,`client_project_id`,`client_task_id`),
  KEY `task_contact_responsibility_project_fk` (`workspace_id`,`client_company_id`,`client_project_id`),
  KEY `task_contact_responsibility_contact_fk` (`workspace_id`,`client_company_id`,`client_contact_id`),
  CONSTRAINT `client_task_contact_responsibilities_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_contact_responsibility_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `task_contact_responsibility_contact_fk` FOREIGN KEY (`workspace_id`, `client_company_id`, `client_contact_id`) REFERENCES `client_contacts` (`workspace_id`, `client_company_id`, `id`),
  CONSTRAINT `task_contact_responsibility_project_fk` FOREIGN KEY (`workspace_id`, `client_company_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `client_company_id`, `id`),
  CONSTRAINT `task_contact_responsibility_task_fk` FOREIGN KEY (`workspace_id`, `client_project_id`, `client_task_id`) REFERENCES `client_tasks` (`workspace_id`, `client_project_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_dependencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `client_task_id` bigint(20) unsigned NOT NULL,
  `prerequisite_task_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_dependency_unique` (`workspace_id`,`client_project_id`,`client_task_id`,`prerequisite_task_id`),
  KEY `task_dependency_reverse_idx` (`workspace_id`,`client_project_id`,`prerequisite_task_id`,`client_task_id`),
  CONSTRAINT `client_task_dependencies_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_dependency_prerequisite_fk` FOREIGN KEY (`workspace_id`, `client_project_id`, `prerequisite_task_id`) REFERENCES `client_tasks` (`workspace_id`, `client_project_id`, `id`),
  CONSTRAINT `task_dependency_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`),
  CONSTRAINT `task_dependency_source_fk` FOREIGN KEY (`workspace_id`, `client_project_id`, `client_task_id`) REFERENCES `client_tasks` (`workspace_id`, `client_project_id`, `id`),
  CONSTRAINT `task_dependency_not_self` CHECK (`client_task_id` <> `prerequisite_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_graph_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_graph_states` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_graph_ws_project_unique` (`workspace_id`,`client_project_id`),
  UNIQUE KEY `task_graph_ws_id_unique` (`workspace_id`,`id`),
  CONSTRAINT `client_task_graph_states_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_graph_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_label_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_label_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_task_id` bigint(20) unsigned NOT NULL,
  `client_task_label_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_label_assignment_unique` (`workspace_id`,`client_task_id`,`client_task_label_id`),
  KEY `task_label_assignment_label_fk` (`workspace_id`,`client_task_label_id`),
  CONSTRAINT `client_task_label_assignments_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_label_assignment_label_fk` FOREIGN KEY (`workspace_id`, `client_task_label_id`) REFERENCES `client_task_labels` (`workspace_id`, `id`),
  CONSTRAINT `task_label_assignment_task_fk` FOREIGN KEY (`workspace_id`, `client_task_id`) REFERENCES `client_tasks` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_labels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `name_key` char(64) NOT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_labels_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `task_labels_ws_name_unique` (`workspace_id`,`name_key`),
  UNIQUE KEY `client_task_labels_public_id_unique` (`public_id`),
  KEY `task_labels_active_idx` (`workspace_id`,`is_archived`,`id`),
  CONSTRAINT `client_task_labels_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_task_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_task_lists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `organization_position` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_lists_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `task_lists_ws_project_id_unique` (`workspace_id`,`client_project_id`,`id`),
  UNIQUE KEY `client_task_lists_public_id_unique` (`public_id`),
  KEY `task_list_organization_order_idx` (`workspace_id`,`client_project_id`,`organization_position`,`id`),
  CONSTRAINT `client_task_lists_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_lists_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 1,
  `completed_at` timestamp NULL DEFAULT NULL,
  `milestone_price_amount` bigint(20) unsigned DEFAULT NULL,
  `client_invoice_line_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `starts_on` date DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `parent_task_id` bigint(20) unsigned DEFAULT NULL,
  `client_task_list_id` bigint(20) unsigned DEFAULT NULL,
  `organization_position` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_tasks_public_id_unique` (`public_id`),
  UNIQUE KEY `tasks_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `tasks_ws_project_id_unique` (`workspace_id`,`client_project_id`,`id`),
  UNIQUE KEY `task_invoice_line_once` (`client_invoice_line_id`),
  KEY `client_tasks_client_project_id_foreign` (`client_project_id`),
  KEY `task_invoice_line_idx` (`workspace_id`,`client_invoice_line_id`),
  KEY `ct_ws_project_idx` (`workspace_id`,`client_project_id`),
  KEY `task_parent_scope_idx` (`workspace_id`,`client_project_id`,`parent_task_id`),
  KEY `task_list_scope_idx` (`workspace_id`,`client_project_id`,`client_task_list_id`),
  KEY `task_organization_order_idx` (`workspace_id`,`client_project_id`,`parent_task_id`,`client_task_list_id`,`organization_position`,`id`),
  CONSTRAINT `client_tasks_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_tasks_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ct_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `task_invoice_line_fk` FOREIGN KEY (`client_invoice_line_id`) REFERENCES `client_invoice_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tasks_project_list_fk` FOREIGN KEY (`workspace_id`, `client_project_id`, `client_task_list_id`) REFERENCES `client_task_lists` (`workspace_id`, `client_project_id`, `id`),
  CONSTRAINT `tasks_project_parent_fk` FOREIGN KEY (`workspace_id`, `client_project_id`, `parent_task_id`) REFERENCES `client_tasks` (`workspace_id`, `client_project_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_time_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_time_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `client_task_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `split_from_time_entry_id` bigint(20) unsigned DEFAULT NULL,
  `worked_on` date NOT NULL,
  `minutes` int(10) unsigned NOT NULL,
  `description` text NOT NULL,
  `job_type` varchar(100) DEFAULT NULL,
  `client_visible_description` text DEFAULT NULL,
  `is_billable` tinyint(1) NOT NULL DEFAULT 1,
  `is_deferred` tinyint(1) NOT NULL DEFAULT 0,
  `is_visible_to_client` tinyint(1) NOT NULL DEFAULT 0,
  `billing_rate_amount` bigint(20) unsigned DEFAULT NULL,
  `billing_rate_source` varchar(20) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `subcontractor_billing_mode` varchar(32) DEFAULT NULL,
  `subcontractor_cost_amount` bigint(20) unsigned DEFAULT NULL,
  `subcontractor_cost_currency` char(3) DEFAULT NULL,
  `subcontractor_cost_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`subcontractor_cost_metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `client_approved_at` timestamp NULL DEFAULT NULL,
  `client_approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_approval_source` varchar(16) DEFAULT NULL,
  `client_disputed_at` timestamp NULL DEFAULT NULL,
  `client_disputed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_dispute_note` varchar(1000) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_dispute_settlement` varchar(16) DEFAULT NULL,
  `client_dispute_reply` varchar(1000) DEFAULT NULL,
  `client_dispute_settled_at` timestamp NULL DEFAULT NULL,
  `client_dispute_settled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_time_entries_public_id_unique` (`public_id`),
  UNIQUE KEY `client_time_entries_workspace_id_id_unique` (`workspace_id`,`id`),
  KEY `client_time_entries_client_project_id_foreign` (`client_project_id`),
  KEY `client_time_entries_client_task_id_foreign` (`client_task_id`),
  KEY `client_time_entries_user_id_foreign` (`user_id`),
  KEY `client_time_entries_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `client_time_entries_workspace_id_worked_on_index` (`workspace_id`,`worked_on`),
  KEY `client_time_entries_workspace_id_status_index` (`workspace_id`,`status`),
  KEY `client_time_entries_client_company_id_worked_on_index` (`client_company_id`,`worked_on`),
  KEY `client_time_entries_workspace_id_deleted_at_index` (`workspace_id`,`deleted_at`),
  KEY `time_entry_split_parent_fk` (`split_from_time_entry_id`),
  KEY `time_entry_split_parent_idx` (`workspace_id`,`split_from_time_entry_id`),
  KEY `cte_workspace_subcontractor_mode_worked_idx` (`workspace_id`,`subcontractor_billing_mode`,`worked_on`),
  KEY `cte_ws_company_idx` (`workspace_id`,`client_company_id`),
  KEY `cte_ws_project_idx` (`workspace_id`,`client_project_id`),
  KEY `client_time_entries_client_approved_by_user_id_foreign` (`client_approved_by_user_id`),
  KEY `client_time_entries_client_disputed_by_user_id_foreign` (`client_disputed_by_user_id`),
  KEY `cte_submitted_by_fk` (`submitted_by_user_id`),
  KEY `cte_ws_submitted_idx` (`workspace_id`,`submitted_at`),
  KEY `client_time_entries_client_dispute_settled_by_user_id_foreign` (`client_dispute_settled_by_user_id`),
  CONSTRAINT `client_time_entries_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_time_entries_client_approved_by_user_id_foreign` FOREIGN KEY (`client_approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_time_entries_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_time_entries_client_dispute_settled_by_user_id_foreign` FOREIGN KEY (`client_dispute_settled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_time_entries_client_disputed_by_user_id_foreign` FOREIGN KEY (`client_disputed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_time_entries_client_project_id_foreign` FOREIGN KEY (`client_project_id`) REFERENCES `client_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_time_entries_client_task_id_foreign` FOREIGN KEY (`client_task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_time_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `client_time_entries_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cte_submitted_by_fk` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cte_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `cte_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `time_entry_split_parent_fk` FOREIGN KEY (`split_from_time_entry_id`) REFERENCES `client_time_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_square_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_square_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `square_customer_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `active_company_slot` bigint(20) unsigned DEFAULT NULL,
  `active_customer_slot` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `confirmed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NOT NULL,
  `cleared_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cleared_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_square_customers_public_id_unique` (`public_id`),
  UNIQUE KEY `csqc_active_company_unique` (`workspace_id`,`active_company_slot`),
  UNIQUE KEY `csqc_active_customer_unique` (`workspace_id`,`active_customer_slot`),
  KEY `csqc_confirmed_by_fk` (`confirmed_by_user_id`),
  KEY `csqc_cleared_by_fk` (`cleared_by_user_id`),
  KEY `csqc_ws_company_idx` (`workspace_id`,`client_company_id`),
  CONSTRAINT `company_square_customers_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `csqc_cleared_by_fk` FOREIGN KEY (`cleared_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `csqc_confirmed_by_fk` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `csqc_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_import_attachment_copies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_import_attachment_copies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `external_import_item_id` bigint(20) unsigned NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_attachment_id` bigint(20) unsigned DEFAULT NULL,
  `source_path_hash` char(64) NOT NULL,
  `source_sha256` char(64) NOT NULL,
  `source_bytes` bigint(20) unsigned NOT NULL,
  `destination_object_key_hash` char(64) NOT NULL,
  `copied_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legacy_attachment_copies_legacy_migration_item_id_unique` (`external_import_item_id`),
  UNIQUE KEY `legacy_attachment_copies_client_attachment_id_unique` (`client_attachment_id`),
  KEY `eiac_workspace_copied_idx` (`workspace_id`,`copied_at`),
  CONSTRAINT `legacy_attachment_copies_client_attachment_id_foreign` FOREIGN KEY (`client_attachment_id`) REFERENCES `client_attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `legacy_attachment_copies_legacy_migration_item_id_foreign` FOREIGN KEY (`external_import_item_id`) REFERENCES `external_import_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legacy_attachment_copies_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_import_failures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_import_failures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `external_import_run_id` bigint(20) unsigned NOT NULL,
  `external_import_item_id` bigint(20) unsigned DEFAULT NULL,
  `source_connection` varchar(100) NOT NULL,
  `source_table` varchar(191) NOT NULL,
  `source_key_hash` varchar(64) NOT NULL,
  `reason_code` varchar(100) NOT NULL,
  `redacted_context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`redacted_context`)),
  `failure_fingerprint` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_import_failure_identity_unique` (`external_import_run_id`,`source_table`,`source_key_hash`,`reason_code`),
  KEY `legacy_migration_failures_legacy_migration_item_id_foreign` (`external_import_item_id`),
  KEY `eif_run_reason_idx` (`external_import_run_id`,`reason_code`),
  CONSTRAINT `legacy_migration_failures_legacy_migration_item_id_foreign` FOREIGN KEY (`external_import_item_id`) REFERENCES `external_import_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `legacy_migration_failures_legacy_migration_run_id_foreign` FOREIGN KEY (`external_import_run_id`) REFERENCES `external_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_import_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_import_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `external_import_run_id` bigint(20) unsigned NOT NULL,
  `source_connection` varchar(100) NOT NULL,
  `source_identity_hash` varchar(64) NOT NULL,
  `source_table` varchar(191) NOT NULL,
  `source_key` varchar(191) NOT NULL,
  `target_type` varchar(191) NOT NULL,
  `target_public_id` char(36) DEFAULT NULL,
  `source_fingerprint` varchar(64) NOT NULL,
  `status` varchar(40) NOT NULL,
  `reason_code` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_import_source_identity_unique` (`source_identity_hash`,`source_table`,`source_key`),
  KEY `external_import_items_target_type_target_public_id_index` (`target_type`,`target_public_id`),
  KEY `external_import_items_external_import_run_id_status_index` (`external_import_run_id`,`status`),
  CONSTRAINT `legacy_migration_items_legacy_migration_run_id_foreign` FOREIGN KEY (`external_import_run_id`) REFERENCES `external_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_import_run_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_import_run_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `external_import_run_id` bigint(20) unsigned NOT NULL,
  `external_import_item_id` bigint(20) unsigned NOT NULL,
  `observed_status` varchar(40) NOT NULL,
  `source_fingerprint` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_import_run_item_unique` (`external_import_run_id`,`external_import_item_id`),
  KEY `legacy_migration_run_items_legacy_migration_item_id_foreign` (`external_import_item_id`),
  KEY `eiri_run_observed_status_idx` (`external_import_run_id`,`observed_status`),
  CONSTRAINT `legacy_migration_run_items_legacy_migration_item_id_foreign` FOREIGN KEY (`external_import_item_id`) REFERENCES `external_import_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legacy_migration_run_items_legacy_migration_run_id_foreign` FOREIGN KEY (`external_import_run_id`) REFERENCES `external_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `source_connection` varchar(100) NOT NULL,
  `source_identity_hash` varchar(64) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `status` varchar(40) NOT NULL,
  `source_high_water_marks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_high_water_marks`)),
  `counts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counts`)),
  `fingerprints` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fingerprints`)),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legacy_migration_runs_public_id_unique` (`public_id`),
  KEY `external_import_runs_workspace_id_created_at_index` (`workspace_id`,`created_at`),
  KEY `eir_source_identity_idx` (`source_connection`,`source_identity_hash`),
  CONSTRAINT `legacy_migration_runs_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historical_blobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `historical_blobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `historical_entry_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(512) NOT NULL,
  `object_key` varchar(191) NOT NULL,
  `bytes` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `history_blob_entry_unique` (`workspace_id`,`historical_entry_id`),
  UNIQUE KEY `historical_blobs_public_id_unique` (`public_id`),
  CONSTRAINT `historical_blobs_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `history_blob_entry_fk` FOREIGN KEY (`workspace_id`, `historical_entry_id`) REFERENCES `historical_entries` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historical_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `historical_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `historical_record_id` bigint(20) unsigned NOT NULL,
  `parent_record_id` bigint(20) unsigned DEFAULT NULL,
  `client_company_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(32) NOT NULL,
  `title` varchar(512) NOT NULL,
  `body` longtext NOT NULL,
  `search_text` longtext NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `occurred_on` varchar(40) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`details`)),
  `document_fingerprint` char(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `historical_entries_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `historical_entries_record_unique` (`workspace_id`,`historical_record_id`),
  UNIQUE KEY `historical_entries_public_id_unique` (`public_id`),
  KEY `historical_entries_kind_index` (`workspace_id`,`kind`,`id`),
  KEY `historical_entries_parent_index` (`workspace_id`,`parent_record_id`),
  KEY `history_entry_company_fk` (`workspace_id`,`client_company_id`),
  FULLTEXT KEY `historical_entries_text_index` (`title`,`search_text`),
  CONSTRAINT `historical_entries_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `history_entry_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `history_entry_parent_fk` FOREIGN KEY (`workspace_id`, `parent_record_id`) REFERENCES `historical_records` (`workspace_id`, `id`),
  CONSTRAINT `history_entry_record_fk` FOREIGN KEY (`workspace_id`, `historical_record_id`) REFERENCES `historical_records` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historical_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `historical_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `historical_source_id` bigint(20) unsigned NOT NULL,
  `source_table` varchar(100) NOT NULL,
  `source_key` varchar(191) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`snapshot`)),
  `target_type` varchar(50) DEFAULT NULL,
  `target_public_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `historical_records_source_unique` (`workspace_id`,`historical_source_id`,`source_table`,`source_key`),
  UNIQUE KEY `historical_records_public_id_unique` (`public_id`),
  UNIQUE KEY `historical_records_ws_id_unique` (`workspace_id`,`id`),
  KEY `historical_records_target_index` (`workspace_id`,`target_type`,`target_public_id`),
  CONSTRAINT `historical_records_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `historical_records_ws_source_fk` FOREIGN KEY (`workspace_id`, `historical_source_id`) REFERENCES `historical_sources` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historical_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `historical_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `system` varchar(50) NOT NULL,
  `identity_hash` char(64) NOT NULL,
  `label` varchar(160) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `historical_sources_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `historical_sources_identity_unique` (`workspace_id`,`system`,`identity_hash`),
  UNIQUE KEY `historical_sources_public_id_unique` (`public_id`),
  CONSTRAINT `historical_sources_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_autopay_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_autopay_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `state` varchar(16) NOT NULL,
  `amount` bigint(20) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `limit_amount` bigint(20) unsigned DEFAULT NULL,
  `consent_public_id` char(36) DEFAULT NULL,
  `payment_method_label` varchar(120) DEFAULT NULL,
  `payment_intent_id` varchar(255) DEFAULT NULL,
  `failure_code` varchar(64) DEFAULT NULL,
  `failure_reason` varchar(255) DEFAULT NULL,
  `skipped_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `skipped_at` timestamp NULL DEFAULT NULL,
  `claimed_at` datetime NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolution_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `iaa_invoice_unique` (`workspace_id`,`client_invoice_id`),
  UNIQUE KEY `invoice_autopay_attempts_public_id_unique` (`public_id`),
  KEY `iaa_skipped_by_fk` (`skipped_by_user_id`),
  KEY `iaa_ws_company_idx` (`workspace_id`,`client_company_id`,`id`),
  KEY `iaa_review_idx` (`state`,`claimed_at`),
  KEY `iaa_resolved_by_fk` (`resolved_by_user_id`),
  CONSTRAINT `iaa_resolved_by_fk` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `iaa_skipped_by_fk` FOREIGN KEY (`skipped_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `iaa_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `iaa_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_autopay_attempts_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_reminder_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_reminder_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `origin` varchar(16) NOT NULL,
  `cadence_step` varchar(16) DEFAULT NULL,
  `channel` varchar(16) NOT NULL,
  `pay_link` varchar(16) NOT NULL,
  `square_invoice_number` varchar(191) DEFAULT NULL,
  `recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`recipients`)),
  `subject` varchar(255) NOT NULL,
  `currency` char(3) NOT NULL,
  `amount_due` bigint(20) NOT NULL,
  `due_on` date DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `attempt_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `triggered_by` varchar(16) NOT NULL,
  `triggered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `oauth_client_id` varchar(100) DEFAULT NULL,
  `provider_message_reference` varchar(255) DEFAULT NULL,
  `error_summary` varchar(255) DEFAULT NULL,
  `queued_at` datetime NOT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_reminder_logs_public_id_unique` (`public_id`),
  UNIQUE KEY `irl_step_claim_unique` (`workspace_id`,`client_invoice_id`,`cadence_step`),
  KEY `irl_triggered_by_fk` (`triggered_by_user_id`),
  KEY `irl_ws_invoice_idx` (`workspace_id`,`client_invoice_id`,`id`),
  KEY `irl_retry_idx` (`status`,`next_attempt_at`),
  CONSTRAINT `invoice_reminder_logs_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `irl_triggered_by_fk` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `irl_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_square_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_square_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `square_invoice_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `square_invoice_number` varchar(191) NOT NULL,
  `series_key` char(6) DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `match_method` varchar(16) NOT NULL,
  `score` tinyint(3) unsigned DEFAULT NULL,
  `reasons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reasons`)),
  `active_invoice_slot` bigint(20) unsigned DEFAULT NULL,
  `active_square_invoice_slot` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `linked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `linked_at` timestamp NOT NULL,
  `unlinked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `unlinked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_square_links_public_id_unique` (`public_id`),
  UNIQUE KEY `isql_active_invoice_unique` (`workspace_id`,`active_invoice_slot`),
  UNIQUE KEY `isql_active_square_unique` (`workspace_id`,`active_square_invoice_slot`),
  KEY `isql_linked_by_fk` (`linked_by_user_id`),
  KEY `isql_unlinked_by_fk` (`unlinked_by_user_id`),
  KEY `isql_ws_invoice_idx` (`workspace_id`,`client_invoice_id`),
  KEY `isql_ws_square_idx` (`workspace_id`,`square_invoice_id`),
  CONSTRAINT `invoice_square_links_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `isql_linked_by_fk` FOREIGN KEY (`linked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `isql_unlinked_by_fk` FOREIGN KEY (`unlinked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `isql_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_credential_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_credential_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `oauth_client_id` char(36) DEFAULT NULL,
  `access_token_id` char(80) DEFAULT NULL,
  `show_all_tools` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_credential_settings_connection_unique` (`user_id`,`oauth_client_id`),
  UNIQUE KEY `mcp_credential_settings_token_unique` (`access_token_id`),
  KEY `mcp_credential_settings_oauth_client_id_foreign` (`oauth_client_id`),
  KEY `mcp_credential_settings_user_index` (`user_id`),
  CONSTRAINT `mcp_credential_settings_oauth_client_id_foreign` FOREIGN KEY (`oauth_client_id`) REFERENCES `oauth_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mcp_credential_settings_token_foreign` FOREIGN KEY (`access_token_id`) REFERENCES `oauth_access_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` char(36) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `resource_uri` varchar(255) DEFAULT NULL,
  `provider_subject` varchar(191) DEFAULT NULL,
  `provider_generation` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`),
  KEY `oauth_access_tokens_resource_uri_index` (`resource_uri`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_auth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `client_id` char(36) NOT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `resource_uri` varchar(255) DEFAULT NULL,
  `provider_subject` varchar(191) DEFAULT NULL,
  `provider_generation` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` char(36) NOT NULL,
  `owner_type` varchar(255) DEFAULT NULL,
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `secret` varchar(255) DEFAULT NULL,
  `provider` varchar(255) DEFAULT NULL,
  `redirect_uris` text NOT NULL,
  `grant_types` text NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `dynamically_registered_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scopes`)),
  PRIMARY KEY (`id`),
  KEY `oauth_clients_owner_type_owner_id_index` (`owner_type`,`owner_id`),
  KEY `oauth_clients_dynamically_registered_at_index` (`dynamically_registered_at`),
  KEY `oauth_clients_last_used_at_index` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_credential_lineages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_credential_lineages` (
  `id` char(36) NOT NULL,
  `owner_key` varchar(64) NOT NULL,
  `client_key` varchar(64) NOT NULL,
  `owner_id` varchar(191) DEFAULT NULL,
  `client_id` varchar(191) NOT NULL,
  `issued_sequence` bigint(20) unsigned NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `oauth_credential_lineages_owner_key_index` (`owner_key`),
  KEY `oauth_credential_lineages_client_key_index` (`client_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_credential_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_credential_members` (
  `credential_key` varchar(64) NOT NULL,
  `lineage_id` char(36) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `credential_id` varchar(191) NOT NULL,
  PRIMARY KEY (`credential_key`),
  KEY `oauth_credential_members_lineage_id_index` (`lineage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_credential_revocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_credential_revocations` (
  `scope_key` varchar(64) NOT NULL,
  `sequence` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`scope_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` char(80) NOT NULL,
  `access_token_id` char(80) NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `resource_uri` text DEFAULT NULL,
  `provider_subject` varchar(191) DEFAULT NULL,
  `provider_generation` bigint(20) unsigned DEFAULT NULL,
  `provider_user_id` varchar(191) DEFAULT NULL,
  `client_id` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`),
  KEY `oauth_refresh_tokens_provider_user_id_index` (`provider_user_id`),
  KEY `oauth_refresh_tokens_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `palette_recents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `palette_recents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) NOT NULL,
  `record_public_id` char(36) NOT NULL,
  `opened_at` datetime(6) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `palette_recents_record_unique` (`workspace_id`,`user_id`,`kind`,`record_public_id`),
  KEY `palette_recents_user_recent_idx` (`user_id`,`workspace_id`,`opened_at`),
  CONSTRAINT `palette_recents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `palette_recents_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_reconciliations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_payment_id` bigint(20) unsigned NOT NULL,
  `external_system_slug` varchar(80) NOT NULL,
  `external_transaction_uuid` char(36) NOT NULL,
  `allocated_amount` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `reconciled_on` date DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `review_reason` varchar(40) DEFAULT NULL,
  `review_flagged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pr_payment_system_transaction_unique` (`workspace_id`,`client_invoice_payment_id`,`external_system_slug`,`external_transaction_uuid`),
  UNIQUE KEY `pr_public_id_unique` (`public_id`),
  KEY `pr_payment_fk` (`client_invoice_payment_id`),
  KEY `pr_creator_fk` (`created_by_user_id`),
  KEY `pr_workspace_system_transaction_idx` (`workspace_id`,`external_system_slug`,`external_transaction_uuid`),
  KEY `pr_workspace_payment_active_idx` (`workspace_id`,`client_invoice_payment_id`,`is_active`),
  CONSTRAINT `pr_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pr_payment_fk` FOREIGN KEY (`client_invoice_payment_id`) REFERENCES `client_invoice_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pr_workspace_fk` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pr_ws_payment_fk` FOREIGN KEY (`workspace_id`, `client_invoice_payment_id`) REFERENCES `client_invoice_payments` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `provider_subject` varchar(191) DEFAULT NULL,
  `provider_generation` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `processor_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `processor_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_payment_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(24) NOT NULL,
  `account_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `provider_entry_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `source_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_payout_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `kind` varchar(24) NOT NULL,
  `principal_minor` bigint(20) NOT NULL,
  `fee_minor` bigint(20) NOT NULL,
  `net_minor` bigint(20) NOT NULL,
  `currency` char(3) NOT NULL,
  `provider_occurred_at` datetime(6) NOT NULL,
  `occurred_on` date NOT NULL,
  `occurred_timezone` varchar(64) NOT NULL,
  `date_basis` varchar(32) NOT NULL,
  `available_on` datetime(6) DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT 0,
  `review_reason` varchar(40) DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pm_entry_unique` (`workspace_id`,`provider`,`account_key`,`provider_entry_identifier`),
  UNIQUE KEY `pm_public_unique` (`public_id`),
  KEY `pm_ws_updated_idx` (`workspace_id`,`updated_at`,`id`),
  KEY `pm_ws_occurred_idx` (`workspace_id`,`occurred_on`,`id`),
  KEY `pm_ws_payment_idx` (`workspace_id`,`client_invoice_payment_id`),
  CONSTRAINT `pm_ws_fk` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pm_ws_payment_fk` FOREIGN KEY (`workspace_id`, `client_invoice_payment_id`) REFERENCES `client_invoice_payments` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `processor_payouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `processor_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(24) NOT NULL,
  `provider_payout_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `amount` bigint(20) NOT NULL,
  `currency` char(3) NOT NULL,
  `arrival_date` date DEFAULT NULL,
  `type` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `provider_updated_at_ms` bigint(20) unsigned NOT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `processor_payouts_ws_provider_id_unique` (`workspace_id`,`provider`,`provider_payout_identifier`),
  UNIQUE KEY `processor_payouts_public_id_unique` (`public_id`),
  KEY `processor_payouts_ws_updated_id_index` (`workspace_id`,`updated_at`,`id`),
  KEY `processor_payouts_ws_arrival_id_index` (`workspace_id`,`arrival_date`,`id`),
  CONSTRAINT `processor_payouts_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reviewer_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reviewer_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `granter_workspace_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned DEFAULT NULL,
  `project_key` bigint(20) unsigned NOT NULL DEFAULT 0,
  `grantee_kind` varchar(16) NOT NULL,
  `grantee_key` varchar(64) NOT NULL,
  `reviewer_email` varchar(255) DEFAULT NULL,
  `reviewer_workspace_id` bigint(20) unsigned DEFAULT NULL,
  `reviewer_label` varchar(255) DEFAULT NULL,
  `granted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `active_marker` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `revgr_public_id_unique` (`public_id`),
  UNIQUE KEY `revgr_one_active_unique` (`granter_workspace_id`,`client_company_id`,`project_key`,`grantee_key`,`active_marker`),
  KEY `revgr_granter_user_fk` (`granted_by_user_id`),
  KEY `revgr_revoker_fk` (`revoked_by_user_id`),
  KEY `revgr_granter_project_fk` (`granter_workspace_id`,`client_project_id`),
  KEY `revgr_granter_active_idx` (`granter_workspace_id`,`active_marker`),
  KEY `revgr_email_active_idx` (`reviewer_email`,`active_marker`),
  KEY `revgr_reviewer_ws_active_idx` (`reviewer_workspace_id`,`active_marker`),
  CONSTRAINT `revgr_granter_company_fk` FOREIGN KEY (`granter_workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `revgr_granter_project_fk` FOREIGN KEY (`granter_workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `revgr_granter_user_fk` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `revgr_granter_ws_fk` FOREIGN KEY (`granter_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revgr_reviewer_ws_fk` FOREIGN KEY (`reviewer_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revgr_revoker_fk` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_package_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_components` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `service_package_version_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `description` varchar(500) NOT NULL,
  `cadence` varchar(20) NOT NULL,
  `amount` bigint(20) unsigned NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 1.000,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpkc_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpkc_public_id_unique` (`public_id`),
  KEY `svpkc_ws_version_fk` (`workspace_id`,`service_package_version_id`),
  CONSTRAINT `service_package_components_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpkc_ws_version_fk` FOREIGN KEY (`workspace_id`, `service_package_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_package_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `service_package_id` bigint(20) unsigned NOT NULL,
  `service_package_version_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_project_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned NOT NULL,
  `service_package_request_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(24) NOT NULL,
  `offered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `offered_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `accepted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `signer_name` varchar(200) DEFAULT NULL,
  `signer_title` varchar(200) DEFAULT NULL,
  `accepted_version_id` bigint(20) unsigned DEFAULT NULL,
  `current_version_id` bigint(20) unsigned DEFAULT NULL,
  `signup_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `activated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `lapsed_at` timestamp NULL DEFAULT NULL,
  `auto_invoice_outcome` varchar(32) DEFAULT NULL,
  `auto_invoice_period_start` date DEFAULT NULL,
  `auto_invoice_refusal` varchar(64) DEFAULT NULL,
  `auto_invoice_detail` varchar(500) DEFAULT NULL,
  `auto_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `auto_invoice_recorded_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpke_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpke_agreement_unique` (`workspace_id`,`client_agreement_id`),
  UNIQUE KEY `svpke_public_id_unique` (`public_id`),
  UNIQUE KEY `svpke_request_unique` (`workspace_id`,`service_package_request_id`),
  KEY `svpke_offerer_fk` (`offered_by_user_id`),
  KEY `svpke_ws_company_status_idx` (`workspace_id`,`client_company_id`,`status`),
  KEY `svpke_ws_package_fk` (`workspace_id`,`service_package_id`),
  KEY `svpke_ws_version_fk` (`workspace_id`,`service_package_version_id`),
  KEY `svpke_ws_project_fk` (`workspace_id`,`client_project_id`),
  KEY `svpke_acceptor_fk` (`accepted_by_user_id`),
  KEY `svpke_ws_accepted_version_idx` (`workspace_id`,`accepted_version_id`),
  KEY `svpke_activator_fk` (`activated_by_user_id`),
  KEY `svpke_ws_status_idx` (`workspace_id`,`status`,`id`),
  KEY `svpke_ws_company_package_idx` (`workspace_id`,`client_company_id`,`service_package_id`,`status`),
  KEY `svpke_ws_signup_invoice_idx` (`workspace_id`,`signup_invoice_id`),
  KEY `svpke_ws_auto_invoice_idx` (`workspace_id`,`auto_invoice_id`),
  KEY `svpke_ws_current_version_idx` (`workspace_id`,`current_version_id`),
  CONSTRAINT `service_package_enrollments_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpke_acceptor_fk` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpke_activator_fk` FOREIGN KEY (`activated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpke_offerer_fk` FOREIGN KEY (`offered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpke_ws_accepted_version_fk` FOREIGN KEY (`workspace_id`, `accepted_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_agreement_fk` FOREIGN KEY (`workspace_id`, `client_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_auto_invoice_fk` FOREIGN KEY (`workspace_id`, `auto_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_current_version_fk` FOREIGN KEY (`workspace_id`, `current_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_package_fk` FOREIGN KEY (`workspace_id`, `service_package_id`) REFERENCES `service_packages` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_project_fk` FOREIGN KEY (`workspace_id`, `client_project_id`) REFERENCES `client_projects` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_request_fk` FOREIGN KEY (`workspace_id`, `service_package_request_id`) REFERENCES `service_package_requests` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_signup_invoice_fk` FOREIGN KEY (`workspace_id`, `signup_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`),
  CONSTRAINT `svpke_ws_version_fk` FOREIGN KEY (`workspace_id`, `service_package_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_package_migration_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_migration_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `service_package_migration_id` bigint(20) unsigned NOT NULL,
  `service_package_enrollment_id` bigint(20) unsigned NOT NULL,
  `client_company_id` bigint(20) unsigned NOT NULL,
  `client_agreement_id` bigint(20) unsigned NOT NULL,
  `successor_agreement_id` bigint(20) unsigned DEFAULT NULL,
  `effective_on` date NOT NULL,
  `status` varchar(16) NOT NULL,
  `outcome_code` varchar(64) DEFAULT NULL,
  `outcome_detail` varchar(1000) DEFAULT NULL,
  `outcome_recorded_at` timestamp NULL DEFAULT NULL,
  `new_price_amount` int(11) NOT NULL,
  `new_price_currency` varchar(3) NOT NULL,
  `autopay_limit_amount` int(11) DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpkt_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpkt_public_id_unique` (`public_id`),
  KEY `svpkt_status_idx` (`status`,`id`),
  KEY `svpkt_ws_migration_idx` (`workspace_id`,`service_package_migration_id`),
  KEY `svpkt_ws_enrollment_status_idx` (`workspace_id`,`service_package_enrollment_id`,`status`),
  KEY `svpkt_ws_company_idx` (`workspace_id`,`client_company_id`),
  KEY `svpkt_ws_agreement_idx` (`workspace_id`,`client_agreement_id`),
  KEY `svpkt_ws_successor_idx` (`workspace_id`,`successor_agreement_id`),
  CONSTRAINT `service_package_migration_targets_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpkt_ws_agreement_fk` FOREIGN KEY (`workspace_id`, `client_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`),
  CONSTRAINT `svpkt_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `svpkt_ws_enrollment_fk` FOREIGN KEY (`workspace_id`, `service_package_enrollment_id`) REFERENCES `service_package_enrollments` (`workspace_id`, `id`),
  CONSTRAINT `svpkt_ws_migration_fk` FOREIGN KEY (`workspace_id`, `service_package_migration_id`) REFERENCES `service_package_migrations` (`workspace_id`, `id`),
  CONSTRAINT `svpkt_ws_successor_fk` FOREIGN KEY (`workspace_id`, `successor_agreement_id`) REFERENCES `client_agreements` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_package_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `service_package_id` bigint(20) unsigned NOT NULL,
  `from_version_id` bigint(20) unsigned NOT NULL,
  `to_version_id` bigint(20) unsigned NOT NULL,
  `effective_on` date NOT NULL,
  `message` varchar(2000) DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `scheduled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `scheduled_at` timestamp NOT NULL,
  `cancelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpkm_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpkm_public_id_unique` (`public_id`),
  KEY `svpkm_scheduler_fk` (`scheduled_by_user_id`),
  KEY `svpkm_canceller_fk` (`cancelled_by_user_id`),
  KEY `svpkm_ws_package_status_idx` (`workspace_id`,`service_package_id`,`status`),
  KEY `svpkm_ws_from_version_idx` (`workspace_id`,`from_version_id`),
  KEY `svpkm_ws_to_version_idx` (`workspace_id`,`to_version_id`),
  CONSTRAINT `service_package_migrations_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpkm_canceller_fk` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpkm_scheduler_fk` FOREIGN KEY (`scheduled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpkm_ws_from_version_fk` FOREIGN KEY (`workspace_id`, `from_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`),
  CONSTRAINT `svpkm_ws_package_fk` FOREIGN KEY (`workspace_id`, `service_package_id`) REFERENCES `service_packages` (`workspace_id`, `id`),
  CONSTRAINT `svpkm_ws_to_version_fk` FOREIGN KEY (`workspace_id`, `to_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_package_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `service_package_id` bigint(20) unsigned NOT NULL,
  `service_package_version_id` bigint(20) unsigned NOT NULL,
  `status` varchar(16) NOT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `contact_name` varchar(200) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `email_hash` char(64) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answers`)),
  `requester_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_fingerprint` char(64) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL,
  `client_company_id` bigint(20) unsigned DEFAULT NULL,
  `decided_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decline_reason` varchar(1000) DEFAULT NULL,
  `erased_at` timestamp NULL DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpkr_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpkr_public_id_unique` (`public_id`),
  KEY `svpkr_requester_fk` (`requester_user_id`),
  KEY `svpkr_decider_fk` (`decided_by_user_id`),
  KEY `svpkr_ws_status_idx` (`workspace_id`,`status`,`id`),
  KEY `svpkr_ws_email_status_idx` (`workspace_id`,`email_hash`,`status`),
  KEY `svpkr_ws_package_fk` (`workspace_id`,`service_package_id`),
  KEY `svpkr_ws_version_fk` (`workspace_id`,`service_package_version_id`),
  KEY `svpkr_ws_company_fk` (`workspace_id`,`client_company_id`),
  CONSTRAINT `service_package_requests_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpkr_decider_fk` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpkr_requester_fk` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpkr_ws_company_fk` FOREIGN KEY (`workspace_id`, `client_company_id`) REFERENCES `client_companies` (`workspace_id`, `id`),
  CONSTRAINT `svpkr_ws_package_fk` FOREIGN KEY (`workspace_id`, `service_package_id`) REFERENCES `service_packages` (`workspace_id`, `id`),
  CONSTRAINT `svpkr_ws_version_fk` FOREIGN KEY (`workspace_id`, `service_package_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_package_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `service_package_id` bigint(20) unsigned NOT NULL,
  `number` int(10) unsigned NOT NULL,
  `draft_marker` tinyint(3) unsigned DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `summary` varchar(300) DEFAULT NULL,
  `body_markdown` text DEFAULT NULL,
  `agreement_text` text DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `billing_cadence` varchar(20) NOT NULL,
  `hourly_rate_amount` bigint(20) unsigned DEFAULT NULL,
  `retainer_amount` bigint(20) unsigned DEFAULT NULL,
  `retainer_minutes` int(10) unsigned DEFAULT NULL,
  `rollover_months` smallint(5) unsigned DEFAULT NULL,
  `first_cycle_proration` varchar(32) DEFAULT NULL,
  `charge_setup_fee_at_signup` tinyint(1) NOT NULL DEFAULT 0,
  `charge_first_period` tinyint(1) NOT NULL DEFAULT 0,
  `require_autopay` tinyint(1) NOT NULL DEFAULT 0,
  `autopay_limit_amount` bigint(20) unsigned DEFAULT NULL,
  `pay_before_activation` tinyint(1) NOT NULL DEFAULT 0,
  `payment_lapse_days` smallint(5) unsigned NOT NULL DEFAULT 14,
  `activation_mode` varchar(16) NOT NULL,
  `invoice_issuing` varchar(16) NOT NULL,
  `notice_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `minimum_term_months` smallint(5) unsigned DEFAULT NULL,
  `cancellation_mode` varchar(16) NOT NULL,
  `intake_questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`intake_questions`)),
  `published_at` timestamp NULL DEFAULT NULL,
  `published_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpkv_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpkv_number_unique` (`workspace_id`,`service_package_id`,`number`),
  UNIQUE KEY `svpkv_public_id_unique` (`public_id`),
  UNIQUE KEY `svpkv_one_draft_unique` (`workspace_id`,`service_package_id`,`draft_marker`),
  KEY `svpkv_publisher_fk` (`published_by_user_id`),
  CONSTRAINT `service_package_versions_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpkv_publisher_fk` FOREIGN KEY (`published_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpkv_ws_package_fk` FOREIGN KEY (`workspace_id`, `service_package_id`) REFERENCES `service_packages` (`workspace_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_packages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `link_name` varchar(64) NOT NULL,
  `status` varchar(16) NOT NULL,
  `is_listed` tinyint(1) NOT NULL DEFAULT 0,
  `portal_addable` tinyint(1) NOT NULL DEFAULT 1,
  `published_version_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `lock_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svpkg_ws_id_unique` (`workspace_id`,`id`),
  UNIQUE KEY `svpkg_ws_link_unique` (`workspace_id`,`link_name`),
  UNIQUE KEY `svpkg_public_id_unique` (`public_id`),
  KEY `svpkg_creator_fk` (`created_by_user_id`),
  KEY `svpkg_ws_status_idx` (`workspace_id`,`status`),
  KEY `svpkg_ws_published_version_fk` (`workspace_id`,`published_version_id`),
  CONSTRAINT `service_packages_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `svpkg_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `svpkg_ws_published_version_fk` FOREIGN KEY (`workspace_id`, `published_version_id`) REFERENCES `service_package_versions` (`workspace_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_customer_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_customer_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `square_customer_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `square_version` bigint(20) unsigned DEFAULT NULL,
  `given_name` varchar(255) DEFAULT NULL,
  `family_name` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `email_address` varchar(255) DEFAULT NULL,
  `phone_number` varchar(64) DEFAULT NULL,
  `reference_id` varchar(255) DEFAULT NULL,
  `square_created_at` timestamp NULL DEFAULT NULL,
  `square_updated_at` timestamp NULL DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `synced_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_sync_run_id` bigint(20) unsigned DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sqcs_ws_customer_unique` (`workspace_id`,`square_customer_id`),
  KEY `sqcs_ws_email_idx` (`workspace_id`,`email_address`),
  CONSTRAINT `square_customer_snapshots_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_invoice_coverages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_invoice_coverages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `client_invoice_id` bigint(20) unsigned NOT NULL,
  `square_invoice_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `square_invoice_number` varchar(191) NOT NULL,
  `active_square_invoice_slot` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `covered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `covered_at` timestamp NOT NULL,
  `cleared_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cleared_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_invoice_coverages_public_id_unique` (`public_id`),
  UNIQUE KEY `sqic_active_square_unique` (`workspace_id`,`active_square_invoice_slot`),
  KEY `sqic_covered_by_fk` (`covered_by_user_id`),
  KEY `sqic_cleared_by_fk` (`cleared_by_user_id`),
  KEY `sqic_ws_invoice_idx` (`workspace_id`,`client_invoice_id`),
  CONSTRAINT `sqic_cleared_by_fk` FOREIGN KEY (`cleared_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sqic_covered_by_fk` FOREIGN KEY (`covered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sqic_ws_invoice_fk` FOREIGN KEY (`workspace_id`, `client_invoice_id`) REFERENCES `client_invoices` (`workspace_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `square_invoice_coverages_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_invoice_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_invoice_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `square_invoice_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `square_version` bigint(20) unsigned DEFAULT NULL,
  `square_location_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `square_order_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `square_customer_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `square_subscription_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `invoice_number` varchar(191) DEFAULT NULL,
  `series_key` char(6) DEFAULT NULL,
  `series_occurrence` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `currency` char(3) DEFAULT NULL,
  `total_amount` bigint(20) DEFAULT NULL,
  `paid_amount` bigint(20) DEFAULT NULL,
  `next_payment_amount` bigint(20) DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sale_or_service_date` date DEFAULT NULL,
  `square_created_at` timestamp NULL DEFAULT NULL,
  `square_updated_at` timestamp NULL DEFAULT NULL,
  `public_url` text DEFAULT NULL,
  `autopay` tinyint(1) NOT NULL DEFAULT 0,
  `payment_requests` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payment_requests`)),
  `payment_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_ids`)),
  `payment_ids_basis` varchar(128) DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `synced_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_sync_run_id` bigint(20) unsigned DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sqis_ws_invoice_unique` (`workspace_id`,`square_invoice_id`),
  KEY `sqis_ws_series_idx` (`workspace_id`,`series_key`,`series_occurrence`),
  KEY `sqis_ws_customer_idx` (`workspace_id`,`square_customer_id`),
  KEY `sqis_ws_status_idx` (`workspace_id`,`status`),
  KEY `sqis_ws_due_idx` (`workspace_id`,`due_on`),
  CONSTRAINT `square_invoice_snapshots_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_location_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_location_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `square_location_id` varchar(192) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `synced_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_sync_run_id` bigint(20) unsigned DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sqls_ws_location_unique` (`workspace_id`,`square_location_id`),
  CONSTRAINT `square_location_snapshots_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_pending_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_pending_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `payment_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `payout_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `arrival_date` date DEFAULT NULL,
  `fee_amount` bigint(20) DEFAULT NULL,
  `fee_currency` char(3) DEFAULT NULL,
  `reported_at_ms` bigint(20) unsigned NOT NULL,
  `received_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_pending_ws_payment_unique` (`workspace_id`,`payment_identifier`),
  KEY `square_pending_retention_index` (`workspace_id`,`received_at`,`id`),
  CONSTRAINT `square_pending_settlements_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_sync_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `trigger` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `requested_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `api_version` varchar(16) NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `locations_seen` int(10) unsigned NOT NULL DEFAULT 0,
  `customers_seen` int(10) unsigned NOT NULL DEFAULT 0,
  `customers_changed` int(10) unsigned NOT NULL DEFAULT 0,
  `invoices_seen` int(10) unsigned NOT NULL DEFAULT 0,
  `invoices_changed` int(10) unsigned NOT NULL DEFAULT 0,
  `payments_resolved` int(10) unsigned NOT NULL DEFAULT 0,
  `high_water_mark` timestamp NULL DEFAULT NULL,
  `truncated` tinyint(1) NOT NULL DEFAULT 0,
  `error_code` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_sync_runs_public_id_unique` (`public_id`),
  KEY `sqsr_requested_by_fk` (`requested_by_user_id`),
  KEY `sqsr_ws_status_idx` (`workspace_id`,`status`,`finished_at`),
  CONSTRAINT `sqsr_requested_by_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `square_sync_runs_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `square_webhook_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `square_webhook_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `event_hash` char(64) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `received_at` datetime NOT NULL,
  `claim_token` char(36) DEFAULT NULL,
  `lease_expires_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `square_webhook_event_unique` (`workspace_id`,`event_hash`),
  KEY `square_webhook_retention_index` (`workspace_id`,`received_at`),
  CONSTRAINT `square_webhook_receipts_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stripe_payment_method_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stripe_payment_method_states` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider_id_hash` char(64) NOT NULL,
  `workspace_id` bigint(20) unsigned DEFAULT NULL,
  `client_company_id` bigint(20) unsigned DEFAULT NULL,
  `client_stripe_customer_id` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'unknown',
  `provider_created_at` bigint(20) unsigned NOT NULL DEFAULT 0,
  `stripe_event_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_payment_method_states_provider_id_hash_unique` (`provider_id_hash`),
  KEY `stripe_payment_method_states_client_company_id_foreign` (`client_company_id`),
  KEY `stripe_payment_method_states_client_stripe_customer_id_foreign` (`client_stripe_customer_id`),
  KEY `stripe_payment_method_states_workspace_id_state_index` (`workspace_id`,`state`),
  CONSTRAINT `stripe_payment_method_states_client_company_id_foreign` FOREIGN KEY (`client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stripe_payment_method_states_client_stripe_customer_id_foreign` FOREIGN KEY (`client_stripe_customer_id`) REFERENCES `client_stripe_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stripe_payment_method_states_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `oauth_provider` varbinary(256) DEFAULT NULL,
  `oauth_subject` varbinary(191) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `last_workspace_id` bigint(20) unsigned DEFAULT NULL,
  `last_client_company_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `public_id` char(36) NOT NULL,
  `duration_style` varchar(16) NOT NULL DEFAULT 'decimal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_public_id_unique` (`public_id`),
  UNIQUE KEY `users_oauth_provider_oauth_subject_unique` (`oauth_provider`,`oauth_subject`),
  KEY `users_last_workspace_id_foreign` (`last_workspace_id`),
  KEY `users_last_client_company_id_foreign` (`last_client_company_id`),
  CONSTRAINT `users_last_client_company_id_foreign` FOREIGN KEY (`last_client_company_id`) REFERENCES `client_companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_last_workspace_id_foreign` FOREIGN KEY (`last_workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspace_invoice_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_invoice_counters` (
  `workspace_id` bigint(20) unsigned NOT NULL,
  `next_number` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`workspace_id`),
  CONSTRAINT `workspace_invoice_counters_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspace_memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `public_id` char(36) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspace_memberships_workspace_id_user_id_unique` (`workspace_id`,`user_id`),
  UNIQUE KEY `workspace_memberships_public_id_unique` (`public_id`),
  KEY `workspace_memberships_user_id_foreign` (`user_id`),
  CONSTRAINT `workspace_memberships_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_memberships_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspace_payment_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_payment_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `workspace_id` bigint(20) unsigned NOT NULL,
  `processor` varchar(16) NOT NULL,
  `external_account_id` varchar(64) NOT NULL,
  `display_name` varchar(160) DEFAULT NULL,
  `environment` varchar(16) DEFAULT NULL,
  `origin` varchar(16) NOT NULL,
  `reuses_connection_id` bigint(20) unsigned DEFAULT NULL,
  `endpoint_token` char(40) NOT NULL,
  `secret` text DEFAULT NULL,
  `webhook_secret` text DEFAULT NULL,
  `secret_hint` varchar(40) DEFAULT NULL,
  `publishable_key` varchar(255) DEFAULT NULL,
  `webhook_endpoint_id` varchar(64) DEFAULT NULL,
  `webhook_check_status` varchar(24) DEFAULT NULL,
  `webhook_check_missing` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`webhook_check_missing`)),
  `webhook_checked_at` datetime DEFAULT NULL,
  `location_id` varchar(64) DEFAULT NULL,
  `connected_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `connected_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `active_slot` varchar(16) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspace_payment_connections_public_id_unique` (`public_id`),
  UNIQUE KEY `workspace_payment_connections_endpoint_token_unique` (`endpoint_token`),
  UNIQUE KEY `workspace_payment_connection_active_unique` (`workspace_id`,`active_slot`),
  KEY `workspace_payment_connections_connected_by_user_id_foreign` (`connected_by_user_id`),
  KEY `workspace_payment_connections_revoked_by_user_id_foreign` (`revoked_by_user_id`),
  KEY `workspace_payment_connection_account_index` (`processor`,`external_account_id`),
  CONSTRAINT `workspace_payment_connections_connected_by_user_id_foreign` FOREIGN KEY (`connected_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workspace_payment_connections_revoked_by_user_id_foreign` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workspace_payment_connections_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workspaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspaces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `default_currency` char(3) NOT NULL DEFAULT 'USD',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `has_square_connection` tinyint(1) NOT NULL DEFAULT 0,
  `edition` varchar(16) NOT NULL DEFAULT 'full',
  `stripe_refused_at` datetime DEFAULT NULL,
  `package_catalog_token` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspaces_public_id_unique` (`public_id`),
  UNIQUE KEY `workspaces_slug_unique` (`slug`),
  UNIQUE KEY `ws_package_catalog_token_unique` (`package_catalog_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

/*M!999999\- enable the sandbox mode */ 
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_08_15_000000_create_svc_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_15_005000_add_public_ids_to_identity_memberships',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_15_010000_create_engagement_workflow',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_15_015000_create_client_attachments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_15_020000_create_billing_workflow',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_15_030000_create_legacy_migration_ledger',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_15_040000_create_payment_reconciliations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_15_200954_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_16_000000_create_legacy_attachment_copies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_18_120000_preserve_legacy_activity_and_email_delivery',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_23_090000_add_agent_workflow_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_23_173334_create_oauth_auth_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_23_173335_create_oauth_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_23_173336_create_oauth_refresh_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_23_173337_create_oauth_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_08_23_180000_create_agent_mutation_audit_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_23_190000_add_project_attribution_to_invoice_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_23_200000_add_oauth_resource_audiences',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_23_210000_couple_project_memberships_to_workspace_memberships',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_23_220000_harden_agent_mutation_integrity',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_23_230000_create_workspace_invoice_counters',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_23_235000_add_dynamic_registration_metadata_to_oauth_clients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_24_000000_rename_external_import_ledger_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_24_000001_rename_external_import_columns_and_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_25_000001_scope_agent_mutation_receipts_by_workspace',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_08_26_000000_restore_billing_ledger_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_08_26_000100_add_billing_rate_source_to_time_entries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_08_26_000200_add_access_scope_to_client_company_memberships',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_08_26_000300_add_split_lineage_to_time_entries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_08_26_000400_link_milestone_tasks_to_invoice_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_08_27_000000_restore_invoice_starting_balances',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_08_28_000000_align_invoice_hour_precision',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_08_28_010000_one_task_per_milestone_line',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_08_29_000000_add_subcontractor_billing_mode_to_time_entries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_08_30_000000_add_native_subjects_to_client_company_activity',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_08_31_000000_add_workspace_id_to_client_company_memberships',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_08_31_000100_index_tenant_parents_by_workspace',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_08_31_000200_add_composite_tenant_foreign_keys',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_09_01_000000_require_an_agreement_start_date',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_09_02_000000_add_oauth_server_metadata',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_09_03_000000_remember_where_a_user_was_working',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_09_03_000100_record_what_was_sent_with_an_invoice',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_09_04_000000_index_invoice_delivery_provider_references',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_09_04_010000_create_client_expenses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_09_05_000000_record_where_a_project_is_worked',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_09_08_010000_add_expense_invoice_claims',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_09_08_020000_add_expense_lock_version',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_09_08_030000_create_expense_schedules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_09_08_070000_allow_expense_attachments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_09_15_000000_add_invoice_review_delivery',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_09_27_000000_add_credit_revision_to_client_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_09_28_000000_add_lock_version_to_client_invoice_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_09_28_010000_add_hours_statement_to_client_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_09_29_000000_record_the_catch_up_an_invoice_was_sized_against',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_09_29_010000_store_time_entry_dates_without_a_time',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_09_30_000000_store_every_calendar_date_without_a_time',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_10_08_000000_add_agent_revisions_to_clients_and_agreements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_10_08_000002_add_agent_revision_to_billing_schedules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_10_08_010000_add_agent_revision_to_client_proposals',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_10_08_130000_add_expense_schedule_lock_version',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_10_08_140000_add_workspace_creation_reservations_and_attachment_revisions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_10_10_000000_create_access_audit_events',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_10_11_000000_add_provider_identity_to_oauth_credentials',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_10_11_010000_add_provider_identity_to_personal_access_tokens',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_10_11_020000_create_delegated_access_nonces',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_10_11_020100_create_delegated_access_receipts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_10_11_030000_add_response_snapshot_to_agent_mutation_receipts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_10_11_120000_add_client_id_to_oauth_refresh_tokens',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_10_12_000000_create_client_invoice_corrections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_10_12_060000_create_billing_maintenance_locks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_10_12_120000_add_scheduled_issue_date_to_client_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_10_12_180000_create_client_portal_invitations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_10_12_180000_link_svc_records_to_square',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_10_12_210000_create_square_mirror',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_10_13_090000_widen_square_customer_mappings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_10_14_090000_create_square_webhook_receipts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_10_14_091000_make_provider_payment_identifiers_case_sensitive',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_10_14_120000_create_invoice_reminder_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_10_18_120000_backfill_oauth_refresh_token_owners',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_10_19_120000_add_review_flags_to_payment_reconciliations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_10_19_130000_add_dispute_facts_to_client_invoice_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_10_19_140000_create_client_invoice_payment_refunds',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_10_19_140000_index_change_feeds_by_update_time',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_10_20_000000_add_settlement_facts_to_client_invoice_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_10_20_000100_create_processor_payouts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_10_20_090000_create_square_pending_settlements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_10_20_104143_add_finance_reference_to_client_expenses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_10_20_220000_add_deferred_backlog_portal_visibility_to_agreements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_10_20_230000_create_oauth_credential_lineages',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_10_21_000000_widen_square_payment_ids_basis',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_10_21_000100_bind_square_series_marker_to_a_location',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_10_21_000200_record_square_invoices_covered_by_svc_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_10_21_210000_create_processor_movements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_10_22_100000_create_historical_client_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_10_22_110000_create_historical_library',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_10_22_180000_add_task_planning_dates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_10_22_180010_create_task_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_10_22_180020_widen_client_profile_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_10_22_180100_create_client_collaboration_topics',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_10_22_180110_create_client_comments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_10_22_180150_add_task_organization_reader',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_10_22_180160_add_task_organization_writers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_10_22_180200_add_attachment_reader_visibility',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_10_22_180210_use_private_attachment_default',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_10_22_180300_create_task_labels',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_10_22_180310_create_task_dependencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_10_22_180320_create_task_contact_responsibilities',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_10_28_090000_mark_invoices_with_disputed_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_10_31_210917_add_oauth_identity_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_11_01_120000_add_reopen_and_reissue_to_client_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_11_03_230000_add_deferred_work_statement_to_client_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_11_06_000000_add_duration_style_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_11_06_081700_create_accounting_invoice_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_11_06_090000_create_workspace_payment_connections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_11_06_120000_add_publishable_key_to_workspace_payment_connections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_11_06_120000_create_client_invoice_deferred_projects',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_11_06_140000_add_client_approval',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_11_07_090000_create_shared_project_participations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_11_07_100000_create_reviewer_grants',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_11_08_090000_create_mcp_credential_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_11_08_100000_add_submission_and_light_workspaces',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_11_09_090000_create_autopay',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_11_09_100000_make_expense_receipts_private',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_11_10_090000_add_autopay_resolution_and_connection_refusal',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_11_10_100000_add_webhook_health_to_workspace_payment_connections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_11_11_090000_add_client_dispute_settlement',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_11_11_090000_create_palette_recents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_11_11_100000_reconcile_default_payment_method_by_revision',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_11_12_090000_add_held_work_basis_to_client_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_11_12_090000_create_service_packages',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_11_12_100000_create_service_package_requests_and_enrollments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_11_13_090000_add_acceptance_to_service_package_enrollments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_11_14_090000_add_signup_payment_to_service_package_enrollments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_11_15_090000_add_automatic_invoicing_to_service_package_enrollments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_11_16_090000_add_cancellation_to_client_agreements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_11_17_090000_create_service_package_migrations',1);
