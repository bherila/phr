/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `agent_api_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_api_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` char(36) NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `oauth_client_id` char(36) DEFAULT NULL,
  `oauth_token_hash` char(64) DEFAULT NULL,
  `event` varchar(40) NOT NULL,
  `route_name` varchar(100) NOT NULL,
  `http_method` varchar(10) NOT NULL,
  `response_status` smallint(5) unsigned NOT NULL,
  `duration_ms` int(10) unsigned NOT NULL,
  `sampling_key` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_api_audits_request_id_unique` (`request_id`),
  UNIQUE KEY `agent_api_audits_sampling_key_unique` (`sampling_key`),
  KEY `agent_api_audits_actor_user_id_index` (`actor_user_id`),
  KEY `agent_api_audits_oauth_client_id_index` (`oauth_client_id`),
  KEY `agent_api_audits_oauth_token_hash_index` (`oauth_token_hash`),
  KEY `agent_api_audits_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_api_mutation_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_api_mutation_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `oauth_client_id` char(36) NOT NULL,
  `operation` varchar(64) NOT NULL,
  `external_id_hash` char(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `target_table` varchar(64) NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_mutation_identity_uq` (`patient_id`,`oauth_client_id`,`operation`,`external_id_hash`),
  KEY `agent_mutation_target_idx` (`target_table`,`target_id`),
  CONSTRAINT `agent_mutation_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `acting_user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `event` varchar(64) NOT NULL,
  `auth_method` varchar(32) DEFAULT NULL,
  `succeeded` tinyint(1) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  `is_suspicious` tinyint(1) NOT NULL DEFAULT 0,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_audit_log_acting_user_id_foreign` (`acting_user_id`),
  KEY `auth_audit_log_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `auth_audit_log_event_index` (`event`),
  KEY `auth_audit_log_email_index` (`email`),
  KEY `auth_audit_log_created_at_index` (`created_at`),
  CONSTRAINT `auth_audit_log_acting_user_id_foreign` FOREIGN KEY (`acting_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `auth_audit_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `credential_id` varchar(2048) NOT NULL,
  `credential_id_hash` varchar(64) DEFAULT NULL,
  `public_key` text NOT NULL,
  `counter` bigint(20) unsigned NOT NULL DEFAULT 0,
  `aaguid` varchar(64) DEFAULT NULL,
  `rp_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'Passkey',
  `transports` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`transports`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `auth_passkeys_credential_id_hash_unique` (`credential_id_hash`),
  UNIQUE KEY `passkey_credential_id_hash_unique` (`credential_id_hash`),
  KEY `auth_passkeys_user_id_index` (`user_id`),
  CONSTRAINT `auth_passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_two_factor_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_two_factor_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token` varchar(128) NOT NULL,
  `code` varchar(6) NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `is_suspicious` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `auth_two_factor_attempts_token_unique` (`token`),
  KEY `auth_two_factor_attempts_user_id_is_used_index` (`user_id`,`is_used`),
  KEY `auth_two_factor_attempts_expires_at_index` (`expires_at`),
  CONSTRAINT `auth_two_factor_attempts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bherila_auth_delegated_nonces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bherila_auth_delegated_nonces` (
  `key` varchar(64) NOT NULL,
  `expires_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`key`),
  KEY `bherila_auth_delegated_nonces_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bherila_auth_delegated_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `genai_daily_quota`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_daily_quota` (
  `usage_date` date NOT NULL,
  `request_count` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`usage_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_import_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_import_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `ai_configuration_id` bigint(20) unsigned DEFAULT NULL,
  `ai_provider` varchar(32) DEFAULT NULL,
  `ai_model` varchar(255) DEFAULT NULL,
  `job_type` varchar(64) NOT NULL,
  `file_hash` varchar(64) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `s3_path` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL,
  `context_json` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `raw_response` longtext DEFAULT NULL,
  `retry_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `scheduled_for` date DEFAULT NULL,
  `parsed_at` timestamp NULL DEFAULT NULL,
  `input_tokens` int(10) unsigned DEFAULT NULL,
  `output_tokens` int(10) unsigned DEFAULT NULL,
  `processing_tier` varchar(32) DEFAULT NULL,
  `execution_mode` varchar(16) NOT NULL DEFAULT 'api',
  `mcp_generation` int(10) unsigned NOT NULL DEFAULT 0,
  `mcp_request_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genai_import_jobs_mcp_request_id_unique` (`mcp_request_id`),
  KEY `genai_import_jobs_user_id_status_index` (`user_id`,`status`),
  KEY `genai_import_jobs_file_hash_index` (`file_hash`),
  KEY `genai_import_jobs_scheduled_for_status_index` (`scheduled_for`,`status`),
  KEY `genai_import_jobs_ai_configuration_id_index` (`ai_configuration_id`),
  KEY `genai_import_jobs_ai_configuration_id_created_at_index` (`ai_configuration_id`,`created_at`),
  KEY `genai_import_execution_idx` (`user_id`,`execution_mode`,`status`),
  CONSTRAINT `genai_import_jobs_ai_configuration_id_foreign` FOREIGN KEY (`ai_configuration_id`) REFERENCES `user_ai_configurations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `genai_import_jobs_mcp_request_id_foreign` FOREIGN KEY (`mcp_request_id`) REFERENCES `genai_mcp_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `genai_import_jobs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_import_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_import_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint(20) unsigned NOT NULL,
  `result_index` int(10) unsigned NOT NULL,
  `result_json` longtext NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_review',
  `produced_by` varchar(16) DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `genai_import_results_job_id_result_index_index` (`job_id`,`result_index`),
  CONSTRAINT `genai_import_results_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `genai_import_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_mcp_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_mcp_attachments` (
  `id` char(36) NOT NULL,
  `request_id` char(36) NOT NULL,
  `disk` varchar(255) DEFAULT NULL,
  `path` text DEFAULT NULL,
  `host_reference` text DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `mime_type` varchar(191) NOT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `package_owned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `genai_mcp_attachments_request_id_foreign` (`request_id`),
  CONSTRAINT `genai_mcp_attachments_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `genai_mcp_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_mcp_claim_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_mcp_claim_receipts` (
  `id` char(36) NOT NULL,
  `request_id` char(36) NOT NULL,
  `mailbox_id` char(36) NOT NULL,
  `principal_key` varchar(255) NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `queue_filter` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genai_mcp_claim_receipt_unique` (`principal_key`,`idempotency_key`),
  KEY `genai_mcp_claim_receipts_request_id_foreign` (`request_id`),
  KEY `genai_mcp_claim_receipts_mailbox_id_foreign` (`mailbox_id`),
  CONSTRAINT `genai_mcp_claim_receipts_mailbox_id_foreign` FOREIGN KEY (`mailbox_id`) REFERENCES `genai_mcp_mailboxes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `genai_mcp_claim_receipts_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `genai_mcp_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_mcp_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_mcp_deliveries` (
  `id` char(36) NOT NULL,
  `request_id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `available_at` datetime NOT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `leased_until` timestamp NULL DEFAULT NULL,
  `lease_owner` char(36) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genai_mcp_deliveries_request_id_type_unique` (`request_id`,`type`),
  KEY `genai_mcp_deliveries_pending_idx` (`acknowledged_at`,`available_at`,`leased_until`),
  CONSTRAINT `genai_mcp_deliveries_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `genai_mcp_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_mcp_mailboxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_mcp_mailboxes` (
  `id` char(36) NOT NULL,
  `owner_type` varchar(255) NOT NULL,
  `owner_id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genai_mcp_mailboxes_owner_type_owner_id_name_unique` (`owner_type`,`owner_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_mcp_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_mcp_requests` (
  `id` char(36) NOT NULL,
  `mailbox_id` char(36) NOT NULL,
  `queue` varchar(255) NOT NULL DEFAULT 'default',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `priority` int(11) NOT NULL DEFAULT 0,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `idempotency_key` varchar(191) DEFAULT NULL,
  `enqueue_hash` char(64) DEFAULT NULL,
  `available_at` datetime NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `max_attempts` int(10) unsigned NOT NULL DEFAULT 3,
  `leased_at` timestamp NULL DEFAULT NULL,
  `lease_expires_at` timestamp NULL DEFAULT NULL,
  `lease_token_hash` char(64) DEFAULT NULL,
  `lease_principal` varchar(255) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result`)),
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`error`)),
  `completion_hash` char(64) DEFAULT NULL,
  `completion_lease_hash` char(64) DEFAULT NULL,
  `completion_principal` varchar(255) DEFAULT NULL,
  `completion_receipt_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genai_mcp_requests_mailbox_id_idempotency_key_unique` (`mailbox_id`,`idempotency_key`),
  KEY `genai_mcp_claim_idx` (`mailbox_id`,`queue`,`status`,`available_at`,`priority`),
  CONSTRAINT `genai_mcp_requests_mailbox_id_foreign` FOREIGN KEY (`mailbox_id`) REFERENCES `genai_mcp_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genai_mcp_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genai_mcp_tokens` (
  `id` char(36) NOT NULL,
  `mailbox_id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`scopes`)),
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genai_mcp_tokens_token_hash_unique` (`token_hash`),
  KEY `genai_mcp_tokens_mailbox_id_foreign` (`mailbox_id`),
  CONSTRAINT `genai_mcp_tokens_mailbox_id_foreign` FOREIGN KEY (`mailbox_id`) REFERENCES `genai_mcp_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!50503 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` char(36) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `oauth_security_version` bigint(20) unsigned DEFAULT NULL,
  `oauth_family_id` char(80) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `resource_uri` varchar(255) DEFAULT NULL,
  `provider_subject` varchar(191) DEFAULT NULL,
  `provider_generation` bigint(20) unsigned DEFAULT NULL,
  `oauth_consent_version` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`),
  KEY `oauth_access_tokens_oauth_family_id_index` (`oauth_family_id`),
  KEY `oauth_access_tokens_resource_uri_index` (`resource_uri`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_auth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `client_id` char(36) NOT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `oauth_security_version` bigint(20) unsigned DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `resource_uri` varchar(255) DEFAULT NULL,
  `provider_subject` varchar(191) DEFAULT NULL,
  `provider_generation` bigint(20) unsigned DEFAULT NULL,
  `oauth_consent_version` bigint(20) unsigned DEFAULT NULL,
  `patient_public_id` char(36) DEFAULT NULL,
  `patient_consent_version` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_client_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_client_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `client_id` char(36) NOT NULL,
  `resource` varchar(191) NOT NULL,
  `scopes` text NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `granted_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_client_consents_user_id_client_id_resource_unique` (`user_id`,`client_id`,`resource`),
  KEY `oauth_client_consents_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `dynamically_registered_at` timestamp NULL DEFAULT NULL,
  `first_authorized_at` timestamp NULL DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_clients_owner_type_owner_id_index` (`owner_type`,`owner_id`),
  KEY `oauth_clients_dynamically_registered_at_index` (`dynamically_registered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_patient_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_patient_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `consent_id` bigint(20) unsigned NOT NULL,
  `patient_public_id` char(36) NOT NULL,
  `client_consent_version` bigint(20) unsigned NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `granted_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_patient_consent_unique` (`consent_id`,`patient_public_id`),
  CONSTRAINT `oauth_patient_parent_fk` FOREIGN KEY (`consent_id`) REFERENCES `oauth_client_consents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `oauth_token_families`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_token_families` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` char(36) NOT NULL,
  `oauth_security_version` bigint(20) unsigned DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `oauth_consent_version` bigint(20) unsigned DEFAULT NULL,
  `patient_public_id` char(36) DEFAULT NULL,
  `patient_consent_version` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_token_families_user_id_index` (`user_id`),
  KEY `oauth_token_families_client_id_index` (`client_id`),
  KEY `oauth_token_families_revoked_index` (`revoked`),
  KEY `oauth_token_families_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_allergies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_allergies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `substance` varchar(255) NOT NULL,
  `rxnorm_code` varchar(50) DEFAULT NULL,
  `snomed_code` varchar(50) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `criticality` varchar(50) DEFAULT NULL,
  `clinical_status` varchar(50) NOT NULL DEFAULT 'active',
  `verification_status` varchar(50) NOT NULL DEFAULT 'confirmed',
  `reaction` varchar(255) DEFAULT NULL,
  `severity` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_allergies_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_allergies_patient_id_index` (`patient_id`),
  KEY `phr_allergies_user_id_index` (`user_id`),
  KEY `phr_allergies_src_doc_idx` (`source_document_id`),
  KEY `phr_allergies_review_status_index` (`review_status`),
  KEY `phr_allergies_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_allergies_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_allergies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_blob_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_blob_migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `artifact_class` varchar(32) NOT NULL,
  `storage_disk` varchar(64) NOT NULL,
  `reference_table` varchar(64) NOT NULL,
  `reference_id` bigint(20) unsigned NOT NULL,
  `reference_column` varchar(64) NOT NULL,
  `source_key` varchar(1024) NOT NULL,
  `destination_key` varchar(1024) NOT NULL,
  `source_size_bytes` bigint(20) unsigned NOT NULL,
  `source_sha256` char(64) NOT NULL,
  `migrated_at` datetime NOT NULL,
  `retain_until` datetime NOT NULL,
  `legacy_deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_blob_migrations_reference_uq` (`reference_table`,`reference_id`,`reference_column`),
  KEY `phr_blob_migrations_patient_id_foreign` (`patient_id`),
  KEY `phr_blob_migrations_disk_live_idx` (`storage_disk`,`legacy_deleted_at`),
  KEY `phr_blob_migrations_retention_idx` (`retain_until`,`legacy_deleted_at`),
  CONSTRAINT `phr_blob_migrations_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_conditions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `icd10_code` varchar(20) DEFAULT NULL,
  `snomed_code` varchar(50) DEFAULT NULL,
  `onset_date` date DEFAULT NULL,
  `abated_date` date DEFAULT NULL,
  `clinical_status` varchar(50) NOT NULL DEFAULT 'active',
  `verification_status` varchar(50) NOT NULL DEFAULT 'confirmed',
  `severity` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_conds_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_conditions_patient_id_index` (`patient_id`),
  KEY `phr_conditions_user_id_index` (`user_id`),
  KEY `phr_conds_src_doc_idx` (`source_document_id`),
  KEY `phr_conditions_review_status_index` (`review_status`),
  KEY `phr_conditions_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_conditions_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_conditions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_device_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_device_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL COMMENT 'Client-chosen device identifier; not a secret.',
  `name` varchar(100) NOT NULL COMMENT 'Human-readable device name shown in device management.',
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the plaintext key, shown to the device exactly once.',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime NOT NULL COMMENT 'A key with no expiry is rejected; see isActive().',
  `revoked_at` timestamp NULL DEFAULT NULL COMMENT 'Set by the user-facing device management endpoints.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dk_token_hash_uq` (`token_hash`),
  UNIQUE KEY `phr_dk_user_device_uq` (`user_id`,`device_id`),
  CONSTRAINT `phr_dk_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_device_pairing_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_device_pairing_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL COMMENT 'Client-chosen device identifier; not a secret.',
  `name` varchar(100) NOT NULL COMMENT 'Human-readable device name shown on the approve page.',
  `code_hash` char(64) NOT NULL COMMENT 'SHA-256 of the one-time plaintext code; never store the code itself.',
  `code_challenge` varchar(128) NOT NULL COMMENT 'PKCE S256 challenge; verified against code_verifier at exchange time.',
  `expires_at` datetime NOT NULL COMMENT '5 minutes after approval. A code with no expiry is never issued.',
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dpc_code_hash_uq` (`code_hash`),
  KEY `phr_dpc_user_fk` (`user_id`),
  CONSTRAINT `phr_dpc_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_dicom_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_dicom_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `upload_id` bigint(20) unsigned NOT NULL,
  `file_kind` varchar(32) NOT NULL,
  `r2_key` varchar(1024) NOT NULL,
  `original_relative_path` varchar(1024) NOT NULL,
  `original_path_hash` char(64) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dicom_files_upload_path_unique` (`upload_id`,`original_path_hash`),
  KEY `phr_dicom_files_patient_sha_idx` (`patient_id`,`sha256`),
  CONSTRAINT `phr_dicom_files_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_files_upload_fk` FOREIGN KEY (`upload_id`) REFERENCES `phr_dicom_uploads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_dicom_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_dicom_instances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `study_id` bigint(20) unsigned NOT NULL,
  `series_id` bigint(20) unsigned NOT NULL,
  `upload_id` bigint(20) unsigned NOT NULL,
  `file_id` bigint(20) unsigned NOT NULL,
  `sop_instance_uid` varchar(128) NOT NULL,
  `sop_class_uid` varchar(128) DEFAULT NULL,
  `instance_number` int(11) DEFAULT NULL,
  `transfer_syntax_uid` varchar(128) DEFAULT NULL,
  `rows` int(11) DEFAULT NULL,
  `columns` int(11) DEFAULT NULL,
  `number_of_frames` int(11) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dicom_instances_patient_sop_unique` (`patient_id`,`sop_instance_uid`),
  KEY `phr_dicom_instances_study_fk` (`study_id`),
  KEY `phr_dicom_instances_upload_fk` (`upload_id`),
  KEY `phr_dicom_instances_file_fk` (`file_id`),
  KEY `phr_dicom_instances_series_num_idx` (`series_id`,`instance_number`),
  CONSTRAINT `phr_dicom_instances_file_fk` FOREIGN KEY (`file_id`) REFERENCES `phr_dicom_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_instances_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_instances_series_fk` FOREIGN KEY (`series_id`) REFERENCES `phr_dicom_series` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_instances_study_fk` FOREIGN KEY (`study_id`) REFERENCES `phr_dicom_studies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_instances_upload_fk` FOREIGN KEY (`upload_id`) REFERENCES `phr_dicom_uploads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_dicom_series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_dicom_series` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `study_id` bigint(20) unsigned NOT NULL,
  `series_instance_uid` varchar(128) NOT NULL,
  `modality` varchar(16) DEFAULT NULL,
  `series_number` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `body_part` varchar(100) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dicom_series_study_uid_unique` (`study_id`,`series_instance_uid`),
  KEY `phr_dicom_series_patient_modality_idx` (`patient_id`,`modality`),
  CONSTRAINT `phr_dicom_series_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_series_study_fk` FOREIGN KEY (`study_id`) REFERENCES `phr_dicom_studies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_dicom_studies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_dicom_studies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `upload_id` bigint(20) unsigned DEFAULT NULL,
  `study_instance_uid` varchar(128) NOT NULL,
  `study_date` date DEFAULT NULL,
  `study_time` varchar(32) DEFAULT NULL,
  `accession_number` varchar(128) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `modalities` varchar(255) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dicom_studies_patient_uid_unique` (`patient_id`,`study_instance_uid`),
  KEY `phr_dicom_studies_upload_fk` (`upload_id`),
  KEY `phr_dicom_studies_patient_date_idx` (`patient_id`,`study_date`),
  CONSTRAINT `phr_dicom_studies_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_studies_upload_fk` FOREIGN KEY (`upload_id`) REFERENCES `phr_dicom_uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_dicom_upload_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_dicom_upload_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `upload_id` bigint(20) unsigned NOT NULL,
  `staging_key` varchar(255) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `expires_at` timestamp NOT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_dicom_upload_reservations_staging_key_unique` (`staging_key`),
  KEY `phr_dicom_reservations_patient_idx` (`patient_id`),
  KEY `phr_dicom_reservations_upload_idx` (`upload_id`,`expires_at`),
  CONSTRAINT `phr_dicom_reservations_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_reservations_upload_fk` FOREIGN KEY (`upload_id`) REFERENCES `phr_dicom_uploads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_dicom_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_dicom_uploads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `uploaded_by_user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `original_root_name` varchar(255) DEFAULT NULL,
  `total_files` int(10) unsigned NOT NULL DEFAULT 0,
  `stored_files` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped_files` int(10) unsigned NOT NULL DEFAULT 0,
  `total_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `stored_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `r2_prefix` varchar(512) NOT NULL,
  `manifest_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`manifest_json`)),
  `skipped_files_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`skipped_files_json`)),
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `signed_url_expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_dicom_uploads_patient_created_idx` (`patient_id`,`created_at`),
  KEY `phr_dicom_uploads_user_idx` (`uploaded_by_user_id`),
  CONSTRAINT `phr_dicom_uploads_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_dicom_uploads_user_fk` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_document_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_document_uploads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `content_type` varchar(100) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `staging_path` varchar(500) DEFAULT NULL,
  `received_size` bigint(20) unsigned DEFAULT NULL,
  `received_sha256` char(64) DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `storage_disk` varchar(64) NOT NULL DEFAULT 'phr_documents',
  `transport` varchar(16) NOT NULL DEFAULT 'local',
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_document_uploads_public_id_unique` (`public_id`),
  KEY `phr_document_uploads_actor_user_id_foreign` (`actor_user_id`),
  KEY `phr_document_uploads_patient_id_index` (`patient_id`),
  KEY `phr_document_uploads_status_index` (`status`),
  KEY `phr_document_uploads_expires_at_index` (`expires_at`),
  KEY `phr_document_uploads_document_id_index` (`document_id`),
  CONSTRAINT `phr_document_uploads_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_document_uploads_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_document_uploads_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `genai_job_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `document_type` varchar(80) NOT NULL DEFAULT 'other',
  `observed_at` timestamp NULL DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `storage_disk` varchar(80) NOT NULL DEFAULT 'phr_documents',
  `storage_path` varchar(512) DEFAULT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `byte_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `file_hash` varchar(64) DEFAULT NULL,
  `extracted_text` longtext DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `source` varchar(80) DEFAULT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_docs_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_docs_patient_idx` (`patient_id`),
  KEY `phr_docs_user_idx` (`user_id`),
  KEY `phr_docs_genai_idx` (`genai_job_id`),
  KEY `phr_docs_uploaded_by_fk` (`uploaded_by_user_id`),
  KEY `phr_docs_patient_type_idx` (`patient_id`,`document_type`),
  KEY `phr_docs_patient_source_idx` (`patient_id`,`source`),
  KEY `phr_docs_patient_observed_idx` (`patient_id`,`observed_at`),
  KEY `phr_docs_agent_hash_idx` (`patient_id`,`import_source`,`file_hash`),
  KEY `phr_documents_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_docs_genai_job_fk` FOREIGN KEY (`genai_job_id`) REFERENCES `genai_import_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_docs_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_docs_uploaded_by_fk` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_docs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_blobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_blobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `storage_disk` varchar(64) NOT NULL,
  `storage_path` varchar(1024) NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_blobs_patient_sha_uq` (`patient_id`,`sha256`),
  CONSTRAINT `phr_ehi_blobs_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_entities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_entities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `table_name` varchar(128) NOT NULL,
  `key_digest` char(64) NOT NULL,
  `key_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`key_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_entities_source_key_uq` (`source_id`,`table_name`,`key_digest`),
  KEY `phr_ehi_entities_patient_id_foreign` (`patient_id`),
  CONSTRAINT `phr_ehi_entities_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_entities_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `phr_ehi_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_findings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_findings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `severity` varchar(16) NOT NULL,
  `line_number` bigint(20) unsigned DEFAULT NULL,
  `byte_offset` bigint(20) unsigned DEFAULT NULL,
  `column_name` varchar(128) DEFAULT NULL,
  `detail_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detail_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_ehi_findings_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_findings_member_id_foreign` (`member_id`),
  KEY `phr_ehi_findings_package_code_idx` (`package_id`,`code`),
  CONSTRAINT `phr_ehi_findings_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `phr_ehi_package_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_findings_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_findings_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_occurrences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_occurrences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `revision_id` bigint(20) unsigned NOT NULL,
  `line_number` bigint(20) unsigned NOT NULL,
  `byte_offset` bigint(20) unsigned NOT NULL,
  `disposition` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_occurrences_member_line_uq` (`member_id`,`line_number`),
  KEY `phr_ehi_occurrences_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_occurrences_revision_id_foreign` (`revision_id`),
  KEY `phr_ehi_occurrences_package_disp_idx` (`package_id`,`disposition`),
  CONSTRAINT `phr_ehi_occurrences_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `phr_ehi_package_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_occurrences_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_occurrences_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_occurrences_revision_id_foreign` FOREIGN KEY (`revision_id`) REFERENCES `phr_ehi_revisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_package_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_package_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `blob_id` bigint(20) unsigned NOT NULL,
  `path` varchar(1024) NOT NULL,
  `path_sha256` char(64) NOT NULL,
  `original_path` varchar(1024) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `table_name` varchar(128) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_members_package_path_uq` (`package_id`,`path_sha256`),
  KEY `phr_ehi_package_members_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_package_members_blob_id_foreign` (`blob_id`),
  KEY `phr_ehi_members_package_kind_idx` (`package_id`,`kind`),
  CONSTRAINT `phr_ehi_package_members_blob_id_foreign` FOREIGN KEY (`blob_id`) REFERENCES `phr_ehi_blobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_package_members_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_package_members_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_packages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `bound_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `manifest_digest` char(64) NOT NULL,
  `intake_kind` varchar(16) NOT NULL,
  `original_archive_sha256` char(64) DEFAULT NULL,
  `original_archive_bytes` bigint(20) unsigned DEFAULT NULL,
  `member_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `intake_count` int(10) unsigned NOT NULL DEFAULT 1,
  `last_intake_at` datetime DEFAULT NULL,
  `detected_source_patient_key` varchar(128) DEFAULT NULL,
  `dictionary_fingerprint` char(64) DEFAULT NULL,
  `dictionary_version` varchar(64) DEFAULT NULL,
  `source_version` varchar(64) DEFAULT NULL,
  `preservation_status` varchar(32) NOT NULL DEFAULT 'pending',
  `accounting_status` varchar(32) NOT NULL DEFAULT 'pending',
  `clinical_status` varchar(32) NOT NULL DEFAULT 'not_started',
  `binding_status` varchar(32) NOT NULL DEFAULT 'unbound',
  `bound_at` datetime DEFAULT NULL,
  `failure_category` varchar(64) DEFAULT NULL,
  `lease_token` char(36) DEFAULT NULL,
  `lease_expires_at` datetime DEFAULT NULL,
  `checkpoint_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checkpoint_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `preview_generation_started` int(10) unsigned DEFAULT NULL,
  `preview_generation_completed` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_packages_patient_manifest_uq` (`patient_id`,`manifest_digest`),
  KEY `phr_ehi_packages_source_id_foreign` (`source_id`),
  KEY `phr_ehi_packages_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `phr_ehi_packages_bound_by_user_id_foreign` (`bound_by_user_id`),
  KEY `phr_ehi_packages_patient_lease_idx` (`patient_id`,`lease_expires_at`),
  CONSTRAINT `phr_ehi_packages_bound_by_user_id_foreign` FOREIGN KEY (`bound_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_packages_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_packages_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_packages_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `phr_ehi_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_preview_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_preview_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `requested_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `failure_reason` varchar(64) DEFAULT NULL,
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_ehi_preview_runs_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_preview_runs_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `phr_ehi_preview_runs_package_status_idx` (`package_id`,`status`),
  CONSTRAINT `phr_ehi_preview_runs_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_preview_runs_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_preview_runs_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_proposal_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_proposal_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `decision` varchar(32) NOT NULL,
  `previous_status` varchar(32) NOT NULL,
  `mapping_version` varchar(32) NOT NULL,
  `revision_digest` char(64) NOT NULL,
  `linked_immunization_id` bigint(20) unsigned DEFAULT NULL,
  `linked_document_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `linked_lab_result_id` bigint(20) unsigned DEFAULT NULL,
  `linked_office_visit_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_ehi_proposal_decisions_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_proposal_decisions_proposal_id_foreign` (`proposal_id`),
  KEY `phr_ehi_proposal_decisions_actor_user_id_foreign` (`actor_user_id`),
  KEY `phr_ehi_proposal_decisions_linked_immunization_id_foreign` (`linked_immunization_id`),
  KEY `phr_ehi_proposal_decisions_linked_document_id_foreign` (`linked_document_id`),
  KEY `phr_ehi_proposal_decisions_linked_lab_result_id_foreign` (`linked_lab_result_id`),
  KEY `phr_ehi_proposal_decisions_linked_office_visit_id_foreign` (`linked_office_visit_id`),
  CONSTRAINT `phr_ehi_proposal_decisions_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_proposal_decisions_linked_document_id_foreign` FOREIGN KEY (`linked_document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_proposal_decisions_linked_immunization_id_foreign` FOREIGN KEY (`linked_immunization_id`) REFERENCES `phr_immunizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_proposal_decisions_linked_lab_result_id_foreign` FOREIGN KEY (`linked_lab_result_id`) REFERENCES `phr_lab_results` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_proposal_decisions_linked_office_visit_id_foreign` FOREIGN KEY (`linked_office_visit_id`) REFERENCES `phr_office_visits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_proposal_decisions_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_proposal_decisions_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `phr_ehi_proposals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_proposal_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_proposal_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned NOT NULL,
  `revision_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_proposal_revisions_uq` (`proposal_id`,`revision_id`),
  KEY `phr_ehi_proposal_revisions_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_proposal_revisions_revision_id_foreign` (`revision_id`),
  CONSTRAINT `phr_ehi_proposal_revisions_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_proposal_revisions_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `phr_ehi_proposals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_proposal_revisions_revision_id_foreign` FOREIGN KEY (`revision_id`) REFERENCES `phr_ehi_revisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `mapping` varchar(64) NOT NULL,
  `mapping_version` varchar(32) NOT NULL,
  `subject_key` char(64) NOT NULL,
  `revision_digest` char(64) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `target_table` varchar(64) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `target_digest` char(64) DEFAULT NULL,
  `proposed_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`proposed_json`)),
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `candidate_ids_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`candidate_ids_json`)),
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `preview_digest` char(64) NOT NULL,
  `preview_generation` int(10) unsigned DEFAULT NULL,
  `decided_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_proposals_subject_uq` (`package_id`,`mapping`,`mapping_version`,`subject_key`),
  KEY `phr_ehi_proposals_decided_by_user_id_foreign` (`decided_by_user_id`),
  KEY `phr_ehi_proposals_patient_status_idx` (`patient_id`,`status`),
  CONSTRAINT `phr_ehi_proposals_decided_by_user_id_foreign` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_proposals_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_proposals_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_record_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_record_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `revision_id` bigint(20) unsigned NOT NULL,
  `proposal_id` bigint(20) unsigned DEFAULT NULL,
  `immunization_id` bigint(20) unsigned DEFAULT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `link_kind` varchar(32) NOT NULL,
  `mapping_version` varchar(32) NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lab_result_id` bigint(20) unsigned DEFAULT NULL,
  `bridge_version` varchar(32) DEFAULT NULL,
  `bridge_rule` varchar(64) DEFAULT NULL,
  `office_visit_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_links_rev_imm_uq` (`revision_id`,`immunization_id`,`link_kind`),
  UNIQUE KEY `phr_ehi_links_rev_doc_uq` (`revision_id`,`document_id`,`link_kind`),
  UNIQUE KEY `phr_ehi_links_rev_lab_uq` (`revision_id`,`lab_result_id`,`link_kind`),
  UNIQUE KEY `phr_ehi_links_rev_visit_uq` (`revision_id`,`office_visit_id`,`link_kind`),
  KEY `phr_ehi_record_links_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_record_links_proposal_id_foreign` (`proposal_id`),
  KEY `phr_ehi_record_links_immunization_id_foreign` (`immunization_id`),
  KEY `phr_ehi_record_links_document_id_foreign` (`document_id`),
  KEY `phr_ehi_record_links_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `phr_ehi_record_links_lab_result_id_foreign` (`lab_result_id`),
  KEY `phr_ehi_record_links_office_visit_id_foreign` (`office_visit_id`),
  CONSTRAINT `phr_ehi_record_links_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_record_links_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `phr_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_record_links_immunization_id_foreign` FOREIGN KEY (`immunization_id`) REFERENCES `phr_immunizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_record_links_lab_result_id_foreign` FOREIGN KEY (`lab_result_id`) REFERENCES `phr_lab_results` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_record_links_office_visit_id_foreign` FOREIGN KEY (`office_visit_id`) REFERENCES `phr_office_visits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_record_links_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_record_links_proposal_id_foreign` FOREIGN KEY (`proposal_id`) REFERENCES `phr_ehi_proposals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_record_links_revision_id_foreign` FOREIGN KEY (`revision_id`) REFERENCES `phr_ehi_revisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `first_package_id` bigint(20) unsigned DEFAULT NULL,
  `content_digest` char(64) NOT NULL,
  `values_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`values_json`)),
  `dictionary_fingerprint` char(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_revisions_entity_content_uq` (`entity_id`,`content_digest`),
  KEY `phr_ehi_revisions_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_revisions_first_package_id_foreign` (`first_package_id`),
  CONSTRAINT `phr_ehi_revisions_entity_id_foreign` FOREIGN KEY (`entity_id`) REFERENCES `phr_ehi_entities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_revisions_first_package_id_foreign` FOREIGN KEY (`first_package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_revisions_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `label` varchar(120) NOT NULL,
  `source_patient_key` varchar(128) NOT NULL,
  `dictionary_fingerprint` char(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_sources_patient_label_uq` (`patient_id`,`label`),
  KEY `phr_ehi_sources_created_by_user_id_foreign` (`created_by_user_id`),
  CONSTRAINT `phr_ehi_sources_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_sources_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_table_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_table_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `table_name` varchar(128) NOT NULL,
  `state` varchar(32) NOT NULL,
  `header_columns_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`header_columns_json`)),
  `unknown_columns_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`unknown_columns_json`)),
  `missing_columns_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`missing_columns_json`)),
  `source_row_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `malformed_row_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `disposition_counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`disposition_counts_json`)),
  `parser_version` varchar(32) NOT NULL,
  `dictionary_fingerprint` char(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_ehi_accounts_package_table_uq` (`package_id`,`table_name`),
  KEY `phr_ehi_table_accounts_patient_id_foreign` (`patient_id`),
  KEY `phr_ehi_table_accounts_member_id_foreign` (`member_id`),
  CONSTRAINT `phr_ehi_table_accounts_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `phr_ehi_package_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_table_accounts_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_table_accounts_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_ehi_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_ehi_uploads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `package_id` bigint(20) unsigned DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `storage_disk` varchar(64) NOT NULL,
  `storage_path` varchar(1024) DEFAULT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL,
  `uploaded_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL,
  `format` varchar(16) DEFAULT NULL,
  `failure_reason` varchar(64) DEFAULT NULL,
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `expires_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `transport` varchar(16) NOT NULL DEFAULT 'chunks',
  `multipart_upload_id` varchar(1024) DEFAULT NULL,
  `part_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `part_url_expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_ehi_uploads_actor_user_id_foreign` (`actor_user_id`),
  KEY `phr_ehi_uploads_package_id_foreign` (`package_id`),
  KEY `phr_ehi_uploads_patient_status_idx` (`patient_id`,`status`),
  KEY `phr_ehi_uploads_status_expires_idx` (`status`,`expires_at`),
  KEY `phr_ehi_uploads_source_document_id_foreign` (`source_document_id`),
  CONSTRAINT `phr_ehi_uploads_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_uploads_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `phr_ehi_packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_ehi_uploads_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_ehi_uploads_source_document_id_foreign` FOREIGN KEY (`source_document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_eob_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_eob_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `eob_id` bigint(20) unsigned NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `line_number` int(10) unsigned NOT NULL,
  `procedure_code` varchar(30) NOT NULL,
  `revenue_code` varchar(30) DEFAULT NULL,
  `code_type` varchar(40) NOT NULL DEFAULT 'unknown',
  `description` varchar(255) DEFAULT NULL,
  `service_start` date DEFAULT NULL,
  `service_end` date DEFAULT NULL,
  `accepted_fee` decimal(14,2) DEFAULT NULL,
  `total_charges` decimal(14,2) DEFAULT NULL,
  `provider_discount` decimal(14,2) DEFAULT NULL,
  `ineligible_amount` decimal(14,2) DEFAULT NULL,
  `notes_applied` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`notes_applied`)),
  `deductible_applied` decimal(14,2) DEFAULT NULL,
  `copay_applied` decimal(14,2) DEFAULT NULL,
  `benefit_percent` decimal(7,2) DEFAULT NULL,
  `carrier_payment` decimal(14,2) DEFAULT NULL,
  `plan_payment` decimal(14,2) DEFAULT NULL,
  `patient_responsibility` decimal(14,2) DEFAULT NULL,
  `parsed_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parsed_data`)),
  `raw_text` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_eob_lines_eob_line_uid` (`eob_id`,`line_number`),
  KEY `phr_eob_lines_patient_service_idx` (`patient_id`,`service_start`),
  KEY `phr_eob_lines_patient_code_idx` (`patient_id`,`procedure_code`),
  CONSTRAINT `phr_eob_lines_eob_fk` FOREIGN KEY (`eob_id`) REFERENCES `phr_eobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_eob_lines_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_eobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_eobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `import_source` varchar(50) NOT NULL,
  `external_id` varchar(255) NOT NULL,
  `claim_fingerprint` varchar(64) DEFAULT NULL,
  `claim_number` varchar(80) DEFAULT NULL,
  `claim_type` varchar(30) NOT NULL DEFAULT 'unknown',
  `administrator` varchar(255) DEFAULT NULL,
  `carrier` varchar(255) DEFAULT NULL,
  `plan_name` varchar(255) DEFAULT NULL,
  `group_number` varchar(100) DEFAULT NULL,
  `member_id` varchar(100) DEFAULT NULL,
  `participant_name` varchar(255) DEFAULT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `provider_name` varchar(255) DEFAULT NULL,
  `provider_phone` varchar(50) DEFAULT NULL,
  `payment_to` varchar(255) DEFAULT NULL,
  `provider_tin` varchar(50) DEFAULT NULL,
  `check_number` varchar(100) DEFAULT NULL,
  `check_amount` decimal(14,2) DEFAULT NULL,
  `submission_date` date DEFAULT NULL,
  `print_date` date DEFAULT NULL,
  `processed_date` date DEFAULT NULL,
  `total_accepted_fee` decimal(14,2) DEFAULT NULL,
  `total_charges` decimal(14,2) DEFAULT NULL,
  `total_provider_discount` decimal(14,2) DEFAULT NULL,
  `total_ineligible_amount` decimal(14,2) DEFAULT NULL,
  `total_deductible_applied` decimal(14,2) DEFAULT NULL,
  `total_copay_applied` decimal(14,2) DEFAULT NULL,
  `total_benefit_percent` decimal(7,2) DEFAULT NULL,
  `total_carrier_payment` decimal(14,2) DEFAULT NULL,
  `total_plan_payment` decimal(14,2) DEFAULT NULL,
  `total_patient_responsibility` decimal(14,2) DEFAULT NULL,
  `parsed_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parsed_data`)),
  `raw_text` longtext DEFAULT NULL,
  `parser_version` varchar(50) DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `lines_digest` char(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_eobs_import_uid` (`patient_id`,`import_source`,`external_id`),
  UNIQUE KEY `phr_eobs_claim_fingerprint_uid` (`patient_id`,`import_source`,`claim_fingerprint`),
  KEY `phr_eobs_patient_processed_idx` (`patient_id`,`processed_date`),
  KEY `phr_eobs_patient_claim_idx` (`patient_id`,`claim_number`),
  KEY `phr_eobs_user_fk` (`user_id`),
  KEY `phr_eobs_source_document_fk` (`source_document_id`),
  KEY `phr_eobs_patient_submission_idx` (`patient_id`,`submission_date`),
  KEY `phr_eobs_patient_review_idx` (`patient_id`,`review_status`),
  CONSTRAINT `phr_eobs_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_eobs_source_document_fk` FOREIGN KEY (`source_document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_eobs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_exports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_exports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `requested_by_user_id` bigint(20) unsigned NOT NULL,
  `format` varchar(20) NOT NULL DEFAULT 'zip',
  `formats_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`formats_json`)),
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `storage_disk` varchar(80) NOT NULL DEFAULT 'phr_exports',
  `storage_path` varchar(512) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `file_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_exports_patient_created_idx` (`patient_id`,`created_at`),
  KEY `phr_exports_status_exp_idx` (`status`,`expires_at`),
  KEY `phr_exports_user_fk` (`user_id`),
  KEY `phr_exports_req_user_fk` (`requested_by_user_id`),
  CONSTRAINT `phr_exports_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_exports_req_user_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_exports_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_health_log_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_health_log_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `health_log_id` bigint(20) unsigned NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `intensity` tinyint(3) unsigned DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_hle_log_at_idx` (`health_log_id`,`occurred_at`),
  KEY `phr_hle_patient_at_idx` (`patient_id`,`occurred_at`),
  KEY `phr_hle_user_idx` (`user_id`),
  KEY `phr_hle_recorder_idx` (`recorded_by_user_id`),
  CONSTRAINT `phr_hle_log_fk` FOREIGN KEY (`health_log_id`) REFERENCES `phr_health_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_hle_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_hle_recorder_fk` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_hle_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_health_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_health_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_hl_patient_name_uid` (`patient_id`,`name`),
  KEY `phr_hl_patient_arch_idx` (`patient_id`,`archived_at`),
  KEY `phr_hl_user_idx` (`user_id`),
  KEY `phr_hl_creator_idx` (`created_by_user_id`),
  CONSTRAINT `phr_hl_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_hl_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_hl_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_immunizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_immunizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `vaccine_name` varchar(255) NOT NULL,
  `cvx_code` varchar(20) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `lot_number` varchar(100) DEFAULT NULL,
  `administered_on` date DEFAULT NULL,
  `dose_number` smallint(5) unsigned DEFAULT NULL,
  `series_doses` smallint(5) unsigned DEFAULT NULL,
  `site` varchar(100) DEFAULT NULL,
  `route` varchar(100) DEFAULT NULL,
  `administered_by` varchar(255) DEFAULT NULL,
  `facility_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `administered_at_source` varchar(64) DEFAULT NULL,
  `administered_at_precision` varchar(16) DEFAULT NULL,
  `administered_at_timezone` varchar(16) DEFAULT NULL,
  `dose_amount` varchar(32) DEFAULT NULL,
  `dose_unit` varchar(32) DEFAULT NULL,
  `administration_status` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_imms_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_immunizations_patient_id_index` (`patient_id`),
  KEY `phr_immunizations_user_id_index` (`user_id`),
  KEY `phr_imms_src_doc_idx` (`source_document_id`),
  KEY `phr_immunizations_review_status_index` (`review_status`),
  KEY `phr_immunizations_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_immunizations_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_immunizations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_lab_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_lab_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `test_name` varchar(255) DEFAULT NULL,
  `collection_datetime` datetime DEFAULT NULL,
  `result_datetime` datetime DEFAULT NULL,
  `result_status` varchar(50) DEFAULT NULL,
  `ordering_provider` varchar(100) DEFAULT NULL,
  `resulting_lab` varchar(100) DEFAULT NULL,
  `analyte` varchar(100) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL,
  `value_numeric` decimal(18,10) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `range_min` decimal(18,10) DEFAULT NULL,
  `range_max` decimal(18,10) DEFAULT NULL,
  `range_min_inclusive` tinyint(1) DEFAULT NULL,
  `range_max_inclusive` tinyint(1) DEFAULT NULL,
  `range_unit` varchar(50) DEFAULT NULL,
  `reference_range_text` varchar(255) DEFAULT NULL,
  `normal_value` varchar(100) DEFAULT NULL,
  `abnormal_flag` varchar(50) DEFAULT NULL,
  `message_from_provider` mediumtext DEFAULT NULL,
  `result_comment` mediumtext DEFAULT NULL,
  `lab_director` varchar(100) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `value_raw` varchar(255) DEFAULT NULL,
  `value_comparator` varchar(8) DEFAULT NULL,
  `analyte_code` varchar(64) DEFAULT NULL,
  `analyte_code_system` varchar(32) DEFAULT NULL,
  `collected_at_source` varchar(64) DEFAULT NULL,
  `collected_at_precision` varchar(16) DEFAULT NULL,
  `collected_at_timezone` varchar(16) DEFAULT NULL,
  `resulted_at_source` varchar(64) DEFAULT NULL,
  `resulted_at_precision` varchar(16) DEFAULT NULL,
  `resulted_at_timezone` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_labs_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_labs_patient_result_dt_idx` (`patient_id`,`result_datetime`),
  KEY `phr_labs_user_result_dt_idx` (`user_id`,`result_datetime`),
  KEY `phr_labs_patient_analyte_idx` (`patient_id`,`analyte`),
  KEY `phr_labs_src_doc_idx` (`source_document_id`),
  KEY `phr_lab_results_review_status_index` (`review_status`),
  KEY `phr_lab_results_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_lab_results_new_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_lab_results_new_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_medication_fill_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_medication_fill_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `fill_id` bigint(20) unsigned NOT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `eob_line_id` bigint(20) unsigned DEFAULT NULL,
  `source_kind` varchar(32) NOT NULL,
  `source_locator` varchar(255) DEFAULT NULL,
  `evidence_key` char(40) NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `related_fill_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_fill_evidence_uid` (`fill_id`,`evidence_key`),
  KEY `phr_fill_evidence_alias_idx` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_medication_fill_evidence_patient_id_index` (`patient_id`),
  KEY `phr_medication_fill_evidence_document_id_index` (`document_id`),
  KEY `phr_medication_fill_evidence_eob_line_id_index` (`eob_line_id`),
  KEY `phr_medication_fill_evidence_related_fill_id_index` (`related_fill_id`),
  CONSTRAINT `phr_medication_fill_evidence_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_medication_fill_evidence_eob_line_id_foreign` FOREIGN KEY (`eob_line_id`) REFERENCES `phr_eob_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_medication_fill_evidence_fill_id_foreign` FOREIGN KEY (`fill_id`) REFERENCES `phr_medication_fills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_medication_fill_evidence_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_medication_fill_evidence_related_fill_id_foreign` FOREIGN KEY (`related_fill_id`) REFERENCES `phr_medication_fills` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_medication_fills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_medication_fills` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `medication_id` bigint(20) unsigned DEFAULT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `patient_name_as_printed` varchar(255) DEFAULT NULL,
  `ndc_as_captured` varchar(64) DEFAULT NULL,
  `ndc_10` varchar(16) DEFAULT NULL,
  `ndc_11` varchar(16) DEFAULT NULL,
  `ndc_status` varchar(16) NOT NULL DEFAULT 'manual',
  `ndc_source` varchar(16) DEFAULT NULL,
  `substance_names` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`substance_names`)),
  `strengths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`strengths`)),
  `brand_name` varchar(255) DEFAULT NULL,
  `dosage_form` varchar(255) DEFAULT NULL,
  `route` varchar(255) DEFAULT NULL,
  `labeler_name` varchar(255) DEFAULT NULL,
  `package_description` varchar(500) DEFAULT NULL,
  `rxcui` varchar(50) DEFAULT NULL,
  `drug_as_printed` varchar(255) DEFAULT NULL,
  `fda_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fda_snapshot`)),
  `fda_fetched_at` timestamp NULL DEFAULT NULL,
  `filled_on` date NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `quantity_unit` varchar(50) DEFAULT NULL,
  `days_supply` int(10) unsigned DEFAULT NULL,
  `rx_number` varchar(64) DEFAULT NULL,
  `refills_remaining` int(10) unsigned DEFAULT NULL,
  `directions` text DEFAULT NULL,
  `prescriber_name` varchar(255) DEFAULT NULL,
  `paid_cents` bigint(20) unsigned DEFAULT NULL,
  `retail_cents` bigint(20) unsigned DEFAULT NULL,
  `pharmacy_name` varchar(255) DEFAULT NULL,
  `pharmacy_location` varchar(255) DEFAULT NULL,
  `source_kind` varchar(32) NOT NULL DEFAULT 'manual',
  `source_locator` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_fills_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_fills_patient_filled_idx` (`patient_id`,`filled_on`),
  KEY `phr_medication_fills_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  KEY `phr_medication_fills_patient_id_index` (`patient_id`),
  KEY `phr_medication_fills_user_id_index` (`user_id`),
  KEY `phr_medication_fills_medication_id_index` (`medication_id`),
  KEY `phr_medication_fills_source_document_id_index` (`source_document_id`),
  KEY `phr_medication_fills_review_status_index` (`review_status`),
  CONSTRAINT `phr_medication_fills_medication_id_foreign` FOREIGN KEY (`medication_id`) REFERENCES `phr_medications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_medication_fills_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_medication_fills_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_medications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_medications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `rxnorm_code` varchar(50) DEFAULT NULL,
  `dose` varchar(100) DEFAULT NULL,
  `dose_unit` varchar(50) DEFAULT NULL,
  `route` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `started_on` date DEFAULT NULL,
  `ended_on` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `prescriber_name` varchar(255) DEFAULT NULL,
  `reason_for_use` text DEFAULT NULL,
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_meds_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_medications_patient_id_index` (`patient_id`),
  KEY `phr_medications_user_id_index` (`user_id`),
  KEY `phr_meds_src_doc_idx` (`source_document_id`),
  KEY `phr_medications_review_status_index` (`review_status`),
  KEY `phr_medications_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_medications_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_medications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_native_backup_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_native_backup_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `patient_root_id` bigint(20) unsigned NOT NULL,
  `operation` varchar(32) NOT NULL,
  `schema_version` smallint(5) unsigned NOT NULL,
  `archive_sha256` char(64) DEFAULT NULL,
  `counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counts_json`)),
  `outcome` varchar(24) NOT NULL,
  `failure_category` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_native_audits_patient_created_idx` (`patient_root_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_native_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_native_backups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `requested_by_user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) NOT NULL,
  `schema_version` smallint(5) unsigned NOT NULL,
  `storage_disk` varchar(64) NOT NULL DEFAULT 'phr_exports',
  `storage_path` varchar(1024) DEFAULT NULL,
  `file_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `archive_sha256` char(64) DEFAULT NULL,
  `counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counts_json`)),
  `failure_category` varchar(64) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_native_backups_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `phr_native_backups_patient_created_idx` (`patient_id`,`created_at`),
  KEY `phr_native_backups_expires_idx` (`expires_at`),
  CONSTRAINT `phr_native_backups_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_native_backups_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_native_record_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_native_record_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `record_table` varchar(96) NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `native_id` char(36) NOT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `restore_attempt_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_native_identity_source_uq` (`patient_id`,`record_table`,`record_id`),
  UNIQUE KEY `phr_native_identity_native_uq` (`patient_id`,`native_id`),
  UNIQUE KEY `phr_native_identity_public_uq` (`record_table`,`native_id`),
  KEY `phr_native_identity_restored_idx` (`record_table`,`restored_at`),
  KEY `phr_native_identity_restore_attempt_fk` (`restore_attempt_id`),
  CONSTRAINT `phr_native_identity_restore_attempt_fk` FOREIGN KEY (`restore_attempt_id`) REFERENCES `phr_native_restore_attempts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_native_record_identities_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_native_restore_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_native_restore_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `source_storage_disk` varchar(64) NOT NULL DEFAULT 'phr_exports',
  `source_storage_path` varchar(1024) DEFAULT NULL,
  `source_file_size_bytes` bigint(20) unsigned NOT NULL,
  `uploaded_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `archive_sha256` char(64) DEFAULT NULL,
  `schema_version` smallint(5) unsigned DEFAULT NULL,
  `patient_native_id` char(36) DEFAULT NULL,
  `target_patient_root_id` bigint(20) unsigned DEFAULT NULL,
  `plan_digest` char(64) DEFAULT NULL,
  `plan_counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`plan_counts_json`)),
  `access_grant_count` int(10) unsigned NOT NULL DEFAULT 0,
  `restore_access_grants` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL,
  `failure_category` varchar(64) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_native_restores_actor_status_idx` (`actor_user_id`,`status`),
  KEY `phr_native_restores_status_expires_idx` (`status`,`expires_at`),
  CONSTRAINT `phr_native_restore_attempts_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_negative_assertions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_negative_assertions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `assertion_type` varchar(100) NOT NULL,
  `statement` text NOT NULL,
  `scope` varchar(255) DEFAULT NULL,
  `observed_on` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `source_refs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_refs`)),
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_na_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_na_patient_type_idx` (`patient_id`,`assertion_type`),
  KEY `phr_na_src_doc_idx` (`source_document_id`),
  KEY `phr_na_user_fk` (`user_id`),
  CONSTRAINT `phr_na_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_na_src_doc_fk` FOREIGN KEY (`source_document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_na_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_office_visit_dicom_studies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_office_visit_dicom_studies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `office_visit_id` bigint(20) unsigned NOT NULL,
  `dicom_study_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_visit_dicom_visit_study_uid` (`office_visit_id`,`dicom_study_id`),
  KEY `phr_visit_dicom_patient_visit_idx` (`patient_id`,`office_visit_id`),
  KEY `phr_visit_dicom_study_idx` (`dicom_study_id`),
  CONSTRAINT `phr_visit_dicom_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_visit_dicom_study_fk` FOREIGN KEY (`dicom_study_id`) REFERENCES `phr_dicom_studies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_visit_dicom_visit_fk` FOREIGN KEY (`office_visit_id`) REFERENCES `phr_office_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_office_visit_eobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_office_visit_eobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `office_visit_id` bigint(20) unsigned NOT NULL,
  `eob_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_visit_eobs_visit_eob_uid` (`office_visit_id`,`eob_id`),
  KEY `phr_visit_eobs_patient_visit_idx` (`patient_id`,`office_visit_id`),
  KEY `phr_visit_eobs_eob_idx` (`eob_id`),
  CONSTRAINT `phr_visit_eobs_eob_fk` FOREIGN KEY (`eob_id`) REFERENCES `phr_eobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_visit_eobs_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_visit_eobs_visit_fk` FOREIGN KEY (`office_visit_id`) REFERENCES `phr_office_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_office_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_office_visits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `visit_date` date DEFAULT NULL,
  `visit_started_at` datetime DEFAULT NULL,
  `visit_ended_at` datetime DEFAULT NULL,
  `visit_type` varchar(100) DEFAULT NULL,
  `provider_name` varchar(255) DEFAULT NULL,
  `provider_specialty` varchar(100) DEFAULT NULL,
  `facility_name` varchar(255) DEFAULT NULL,
  `chief_complaint` text DEFAULT NULL,
  `assessment` text DEFAULT NULL,
  `plan` text DEFAULT NULL,
  `subjective` text DEFAULT NULL,
  `objective` text DEFAULT NULL,
  `icd10_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`icd10_codes`)),
  `cpt_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cpt_codes`)),
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `encounter_category` varchar(16) DEFAULT NULL,
  `encounter_status` varchar(50) DEFAULT NULL,
  `encounter_diagnoses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`encounter_diagnoses`)),
  `visit_date_source` varchar(64) DEFAULT NULL,
  `visit_started_source` varchar(64) DEFAULT NULL,
  `visit_started_precision` varchar(16) DEFAULT NULL,
  `visit_started_timezone` varchar(16) DEFAULT NULL,
  `visit_ended_source` varchar(64) DEFAULT NULL,
  `visit_ended_precision` varchar(16) DEFAULT NULL,
  `visit_ended_timezone` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_visits_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_office_visits_patient_id_index` (`patient_id`),
  KEY `phr_office_visits_user_id_index` (`user_id`),
  KEY `phr_visits_src_doc_idx` (`source_document_id`),
  KEY `phr_office_visits_review_status_index` (`review_status`),
  KEY `phr_office_visits_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_office_visits_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_office_visits_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_patient_deletion_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_patient_deletion_artifacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deletion_id` bigint(20) unsigned NOT NULL,
  `storage_disk` varchar(64) NOT NULL,
  `storage_key` varchar(1024) NOT NULL,
  `storage_key_hash` char(64) NOT NULL,
  `expected_bytes` bigint(20) unsigned DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `last_attempt_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_patient_deletion_artifact_uq` (`deletion_id`,`storage_disk`,`storage_key_hash`),
  CONSTRAINT `phr_patient_deletion_artifacts_deletion_id_foreign` FOREIGN KEY (`deletion_id`) REFERENCES `phr_patient_deletions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_patient_deletions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_patient_deletions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `patient_root_id` bigint(20) unsigned NOT NULL,
  `preview_digest` char(64) NOT NULL,
  `record_counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`record_counts_json`)),
  `active_share_count` int(10) unsigned NOT NULL,
  `artifact_count` int(10) unsigned NOT NULL,
  `artifact_bytes` bigint(20) unsigned NOT NULL,
  `status` varchar(32) NOT NULL,
  `failure_category` varchar(64) DEFAULT NULL,
  `deleted_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_patient_deletions_actor_created_idx` (`actor_user_id`,`created_at`),
  KEY `phr_patient_deletions_patient_created_idx` (`patient_root_id`,`created_at`),
  KEY `phr_patient_deletions_status_created_idx` (`status`,`created_at`),
  CONSTRAINT `phr_patient_deletions_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_patient_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_patient_user_access` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `access_level` varchar(32) NOT NULL DEFAULT 'viewer',
  `granted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `granted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_patient_access_patient_user_unique` (`patient_id`,`user_id`),
  KEY `phr_patient_user_access_granted_by_user_id_foreign` (`granted_by_user_id`),
  KEY `phr_patient_access_user_level_idx` (`user_id`,`access_level`),
  CONSTRAINT `phr_patient_user_access_granted_by_user_id_foreign` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_patient_user_access_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_patient_user_access_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_patient_vitals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_patient_vitals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `vital_name` varchar(255) DEFAULT NULL,
  `vital_date` date DEFAULT NULL,
  `observed_at` datetime DEFAULT NULL,
  `vital_value` varchar(255) DEFAULT NULL,
  `value_numeric` decimal(18,10) DEFAULT NULL,
  `value_numeric_secondary` decimal(18,10) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `secondary_unit` varchar(50) DEFAULT NULL,
  `body_site` varchar(100) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_vitals_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_vitals_patient_date_idx` (`patient_id`,`vital_date`),
  KEY `phr_vitals_user_date_idx` (`user_id`,`vital_date`),
  KEY `phr_vitals_patient_name_idx` (`patient_id`,`vital_name`),
  KEY `phr_vitals_src_doc_idx` (`source_document_id`),
  KEY `phr_patient_vitals_review_status_index` (`review_status`),
  KEY `phr_patient_vitals_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_patient_vitals_new_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_patient_vitals_new_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_patients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint(20) unsigned NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `real_names` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`real_names`)),
  `relationship` varchar(50) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `sex_at_birth` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phr_patients_owner_name_idx` (`owner_user_id`,`display_name`),
  KEY `phr_patients_archived_idx` (`archived_at`),
  CONSTRAINT `phr_patients_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_portal_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_portal_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `message_at` datetime DEFAULT NULL,
  `direction` varchar(32) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `clinical_relevance` text DEFAULT NULL,
  `source_refs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_refs`)),
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_pm_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_pm_patient_at_idx` (`patient_id`,`message_at`),
  KEY `phr_pm_src_doc_idx` (`source_document_id`),
  KEY `phr_pm_user_fk` (`user_id`),
  CONSTRAINT `phr_pm_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_pm_src_doc_fk` FOREIGN KEY (`source_document_id`) REFERENCES `phr_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `phr_pm_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_procedure_eobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_procedure_eobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `procedure_id` bigint(20) unsigned NOT NULL,
  `eob_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_procedure_eobs_procedure_eob_uid` (`procedure_id`,`eob_id`),
  KEY `phr_procedure_eobs_patient_procedure_idx` (`patient_id`,`procedure_id`),
  KEY `phr_procedure_eobs_eob_idx` (`eob_id`),
  CONSTRAINT `phr_procedure_eobs_eob_fk` FOREIGN KEY (`eob_id`) REFERENCES `phr_eobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_procedure_eobs_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_procedure_eobs_procedure_fk` FOREIGN KEY (`procedure_id`) REFERENCES `phr_procedures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_procedures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_procedures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `cpt_code` varchar(20) DEFAULT NULL,
  `snomed_code` varchar(50) DEFAULT NULL,
  `performed_at` datetime DEFAULT NULL,
  `performed_on` date DEFAULT NULL,
  `performer_name` varchar(255) DEFAULT NULL,
  `performer_specialty` varchar(100) DEFAULT NULL,
  `facility_name` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'completed',
  `reason` text DEFAULT NULL,
  `outcome` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `raw_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `retracted_at` timestamp NULL DEFAULT NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'confirmed',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_procs_imp_uid` (`patient_id`,`import_source`,`external_id`),
  KEY `phr_procedures_patient_id_index` (`patient_id`),
  KEY `phr_procedures_user_id_index` (`user_id`),
  KEY `phr_procs_src_doc_idx` (`source_document_id`),
  KEY `phr_procedures_review_status_index` (`review_status`),
  KEY `phr_procedures_lifecycle_idx` (`patient_id`,`deleted_at`,`retracted_at`),
  CONSTRAINT `phr_procedures_patient_id_foreign` FOREIGN KEY (`patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phr_procedures_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_respiratory_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_respiratory_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phr_patient_id` bigint(20) unsigned NOT NULL,
  `client_event_uuid` varchar(64) NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `occurred_at` datetime NOT NULL,
  `tz_offset_min` int(11) NOT NULL DEFAULT 0,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `confidence` double DEFAULT NULL,
  `peak_dbfs` double DEFAULT NULL,
  `mean_dbfs` double DEFAULT NULL,
  `noise_floor_dbfs` double DEFAULT NULL,
  `burst_count` int(10) unsigned NOT NULL DEFAULT 1,
  `source` varchar(32) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `model_version` varchar(64) DEFAULT NULL,
  `false_positive_at` datetime DEFAULT NULL,
  `corrected_to_event_type` varchar(32) DEFAULT NULL,
  `corrected_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_resp_evt_patient_uuid_uq` (`phr_patient_id`,`client_event_uuid`),
  KEY `phr_resp_evt_patient_time_ix` (`phr_patient_id`,`occurred_at`),
  KEY `phr_resp_evt_patient_type_ix` (`phr_patient_id`,`event_type`),
  KEY `phr_resp_evt_patient_fp_ix` (`phr_patient_id`,`false_positive_at`),
  CONSTRAINT `phr_resp_evt_patient_fk` FOREIGN KEY (`phr_patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_sinus_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_sinus_enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phr_patient_id` bigint(20) unsigned NOT NULL,
  `client_enrollment_uuid` binary(16) NOT NULL,
  `class` varchar(32) NOT NULL,
  `is_negative` tinyint(1) NOT NULL DEFAULT 0,
  `negative_scoped` tinyint(1) NOT NULL DEFAULT 0,
  `embedding` varbinary(16384) NOT NULL,
  `embedding_dim` smallint(5) unsigned NOT NULL,
  `model_version` varchar(64) DEFAULT NULL,
  `similarity` double DEFAULT NULL,
  `separation` double DEFAULT NULL,
  `peak_dbfs` double DEFAULT NULL,
  `source_event_uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `captured_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_sinus_enr_patient_uuid_uq` (`phr_patient_id`,`client_enrollment_uuid`),
  KEY `phr_sinus_enr_patient_class_ix` (`phr_patient_id`,`class`),
  CONSTRAINT `phr_sinus_enr_patient_fk` FOREIGN KEY (`phr_patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_sinus_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_sinus_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phr_patient_id` bigint(20) unsigned NOT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`settings`)),
  `settings_updated_at` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  `updated_by_device` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phr_sinus_set_patient_uq` (`phr_patient_id`),
  CONSTRAINT `phr_sinus_set_patient_fk` FOREIGN KEY (`phr_patient_id`) REFERENCES `phr_patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_storage_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_storage_settings` (
  `id` int(10) unsigned NOT NULL,
  `global_limit_bytes` bigint(20) unsigned DEFAULT NULL,
  `default_user_limit_bytes` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phr_user_storage_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `phr_user_storage_limits` (
  `user_id` bigint(20) unsigned NOT NULL,
  `limit_bytes` bigint(20) unsigned DEFAULT NULL,
  `unlimited` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `phr_user_storage_limits_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `uptime_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `uptime_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(64) NOT NULL,
  `status` varchar(16) NOT NULL,
  `started_at` timestamp NOT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `duration_ms` bigint(20) unsigned DEFAULT NULL,
  `exit_code` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uptime_runs_job_name_started_at_index` (`job_name`,`started_at`),
  KEY `uptime_runs_job_name_index` (`job_name`),
  KEY `uptime_runs_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_ai_configurations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_ai_configurations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `provider` enum('gemini','anthropic','bedrock') NOT NULL,
  `api_key` text NOT NULL,
  `region` varchar(64) DEFAULT NULL,
  `session_token` text DEFAULT NULL,
  `model` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` timestamp NULL DEFAULT NULL,
  `api_key_invalid_at` timestamp NULL DEFAULT NULL,
  `api_key_invalid_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_ai_configurations_user_id_foreign` (`user_id`),
  CONSTRAINT `user_ai_configurations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `oauth_provider` varbinary(256) DEFAULT NULL,
  `oauth_subject` varbinary(191) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `gemini_api_key` varchar(255) DEFAULT NULL,
  `mcp_api_key` varchar(255) DEFAULT NULL,
  `mcp_api_key_expires_at` timestamp NULL DEFAULT NULL COMMENT 'Expiry for mcp_api_key. A key with no expiry is rejected.',
  `mcp_api_key_last_used_at` timestamp NULL DEFAULT NULL COMMENT 'Last time mcp_api_key authenticated a request, for spotting misuse.',
  `genai_daily_quota_limit` int(10) unsigned DEFAULT NULL COMMENT 'Per-user GenAI daily quota limit. NULL = use system default.',
  `genai_execution_mode` varchar(16) NOT NULL DEFAULT 'api',
  `genai_execution_mode_chosen_at` timestamp NULL DEFAULT NULL,
  `user_role` varchar(255) NOT NULL DEFAULT '',
  `last_login_date` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `oauth_security_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `display_preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`display_preferences`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_oauth_identity_unique` (`oauth_provider`,`oauth_subject`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER users_increment_oauth_security_version BEFORE UPDATE ON users FOR EACH ROW SET NEW.oauth_security_version = CASE WHEN NOT (OLD.user_role <=> NEW.user_role) THEN OLD.oauth_security_version + 1 ELSE NEW.oauth_security_version END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
--
-- WARNING: can't read the INFORMATION_SCHEMA.libraries table. It's most probably an old server 5.5.5-10.11.19-MariaDB.
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_03_22_000001_create_user_ai_configurations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_03_23_000001_create_genai_import_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_03_23_000002_create_genai_import_results_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_03_23_000003_create_genai_daily_quota_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_03_23_000004_add_genai_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_05_03_000000_create_bherila_auth_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_05_17_042848_create_missing_phr_tables_if_needed',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_05_17_042849_normalize_phr_patient_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_05_17_060948_create_phr_dicom_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_05_17_184730_create_phr_conditions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_05_17_184730_create_phr_medications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_05_17_184730_create_phr_office_visits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_05_17_184730_create_phr_procedures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_05_17_184731_create_phr_allergies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_05_17_184731_create_phr_immunizations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_05_17_225900_add_import_source_columns_to_phr_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_05_17_225901_create_phr_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_05_17_225902_create_phr_exports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_05_18_073239_normalize_phr_documents_browser_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_05_18_073242_add_source_document_links_to_phr_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_05_18_201245_drop_legacy_phr_documents_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_06_03_110000_add_passkey_credential_id_hash',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_06_05_000000_create_auth_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_06_14_090102_create_phr_negative_assertions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_06_14_090102_create_phr_portal_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_07_13_000000_create_phr_respiratory_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_07_13_193359_create_phr_health_logs_and_entries_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_07_22_081116_alter_phr_respiratory_events_add_intensity_and_correction',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_07_22_081116_create_phr_sinus_enrollments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_07_22_081116_create_phr_sinus_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_07_26_052603_add_mcp_api_key_lifecycle_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_07_26_060000_add_oauth_identity_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_08_02_000000_create_uptime_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_08_03_000001_create_phr_native_backup_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_08_09_000001_create_phr_eob_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_08_10_000001_add_claim_fingerprint_to_phr_eobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_08_10_000002_create_phr_office_visit_eobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_08_10_000003_create_phr_procedure_eobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_08_11_000001_create_phr_device_pairing_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_08_11_000002_create_phr_device_keys_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_08_12_000000_normalize_device_pairing_expires_at',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_08_15_000001_add_delta_dental_fields_to_phr_eobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_08_16_000001_create_phr_blob_migrations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_08_16_000002_create_phr_patient_deletions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_08_16_000003_create_phr_native_restore_attempts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_08_16_093224_create_oauth_auth_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_08_16_093225_create_oauth_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_08_16_093226_create_oauth_refresh_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_08_16_093227_create_oauth_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_08_16_100000_create_agent_api_audits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_08_16_100001_add_oauth_security_version_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_08_16_100002_create_oauth_token_families_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_08_16_100003_add_restored_at_to_phr_native_record_identities',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_08_16_100004_add_oauth_client_registration_and_resource_audience',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_08_16_180000_create_phr_office_visit_dicom_studies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_08_16_210000_add_review_status_to_agent_writable_clinical_records',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_08_16_220000_index_agent_document_deduplication',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_08_16_230000_create_agent_api_mutation_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_08_20_120000_add_review_status_to_remaining_agent_clinical_records',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_08_22_090000_reset_agent_self_asserted_review_status',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_08_22_120000_make_agent_external_id_binary',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_08_23_100000_add_record_lifecycle_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_08_24_120000_add_rp_id_to_passkey_credentials',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_09_02_000000_add_oauth_server_metadata',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_09_07_000000_create_delegated_access_nonces',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_09_15_000000_create_genai_mcp_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_09_15_100000_add_external_genai_execution',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_10_10_000000_create_delegated_access_receipts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_10_11_000000_add_provider_identity_to_oauth_credentials',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_10_11_120000_add_client_id_to_oauth_refresh_tokens',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_10_19_000000_create_oauth_client_consents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_10_20_000000_make_native_record_identities_globally_unique',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_10_20_120000_add_oauth_patient_consents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_10_21_000000_create_phr_ehi_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_10_22_000000_create_phr_ehi_evidence_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_10_22_000000_preserve_genai_mcp_scheduling_instants',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_10_28_000000_create_phr_ehi_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_10_28_000001_create_phr_ehi_preview_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_10_28_000002_add_format_to_phr_ehi_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_10_28_000003_add_source_document_to_phr_ehi_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_10_29_000000_add_genai_execution_mode_chosen_at_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_10_31_000000_normalize_legacy_phr_document_sources',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_10_31_000001_undouble_phr_lab_reference_range_text',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_10_31_000002_drop_repeated_units_from_phr_lab_reference_range_text',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_10_31_211137_add_oauth_identity_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_11_01_000000_create_phr_medication_fills_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_11_01_000001_add_force_lowercase_display_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_11_01_000002_backfill_ndc_10_on_phr_medication_fills',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_11_01_000003_run_fill_ndc_text_renormalize',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_11_01_000004_drop_force_lowercase_display_from_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_11_01_000005_add_ndc_source_to_phr_medication_fills',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_11_01_000006_run_fill_resolve_discontinued',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_11_01_100000_create_phr_document_uploads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_11_01_100001_add_review_status_to_phr_eobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_11_01_100002_create_phr_medication_fill_evidence_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_11_01_120000_add_range_bound_inclusive_to_phr_lab_results',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_11_01_200000_add_blob_storage_limits',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_11_01_200000_default_phr_documents_document_type_to_other',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_11_02_000000_add_display_preferences_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_11_02_100000_add_real_names_to_phr_patients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_11_02_200000_make_genai_import_context_json_on_postgresql',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_11_02_300000_add_ehi_lab_provenance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_11_02_300001_add_ehi_encounter_context',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_11_03_000000_add_direct_transport_to_phr_ehi_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_11_03_000001_add_signed_write_windows_to_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_11_03_000002_create_phr_dicom_upload_reservations',1);
