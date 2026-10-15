-- AdoptAPet public database schema
-- Structure only. No application records or account data.
-- Import into a newly created empty database in phpMyAdmin.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `demo_seed_registry` (
  `seed_key` varchar(100) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `manifest_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`seed_key`),
  UNIQUE KEY `uniq_seed_target` (`table_name`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('registered_user','administrator','super_administrator') NOT NULL DEFAULT 'registered_user',
  `account_status` enum('active','suspended','deactivated') NOT NULL DEFAULT 'active',
  `failed_login_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_account_status` (`account_status`),
  KEY `idx_users_locked_until` (`locked_until`),
  KEY `idx_users_created_at` (`created_at`),
  CONSTRAINT `chk_users_failed_login_count` CHECK (`failed_login_count` between 0 and 5),
  CONSTRAINT `chk_users_username_format` CHECK (`username` regexp '^[A-Za-z0-9_]{3,20}$')
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `administrator_mfa_settings` (
  `user_id` bigint(20) unsigned NOT NULL,
  `totp_secret_encrypted` varbinary(255) NOT NULL,
  `last_accepted_step` bigint(20) unsigned DEFAULT NULL,
  `enabled_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_administrator_mfa_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `old_values_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values_json`)),
  `new_values_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values_json`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_audit_actor` (`actor_user_id`,`created_at`),
  KEY `idx_audit_action` (`action_type`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `assigned_admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `conversation_status` enum('open','closed') NOT NULL DEFAULT 'open',
  `subject` varchar(255) DEFAULT NULL,
  `last_message_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_user_status` (`user_id`,`conversation_status`),
  KEY `idx_chat_admin_status` (`assigned_admin_user_id`,`conversation_status`),
  KEY `idx_chat_last_message` (`last_message_at`),
  CONSTRAINT `fk_chat_conversation_admin` FOREIGN KEY (`assigned_admin_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_chat_conversation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `community_posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `author_user_id` bigint(20) unsigned NOT NULL,
  `content` text NOT NULL,
  `post_status` enum('published','removed') NOT NULL DEFAULT 'published',
  `removed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_posts_status_created` (`post_status`,`created_at`),
  KEY `idx_posts_author_created` (`author_user_id`,`created_at`),
  KEY `idx_posts_removed_by` (`removed_by_user_id`),
  CONSTRAINT `fk_posts_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_posts_removed_by` FOREIGN KEY (`removed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `donor_name` varchar(150) NOT NULL,
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'MYR',
  `donation_status` enum('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `donated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_donations_status_date` (`donation_status`,`donated_at`),
  KEY `idx_donations_user_date` (`user_id`,`created_at`),
  CONSTRAINT `fk_donations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_donations_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_change_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `new_email` varchar(255) NOT NULL,
  `verification_code_hash` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_change_requests_user_pending` (`user_id`,`used_at`,`created_at`,`id`),
  KEY `idx_email_change_requests_expires_at` (`expires_at`),
  CONSTRAINT `fk_email_change_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `verification_code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_verifications_user_id` (`user_id`),
  KEY `idx_email_verifications_expires_at` (`expires_at`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `email_attempted` varchar(255) NOT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `failure_reason` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_user_attempted` (`user_id`,`attempted_at`),
  KEY `idx_login_email_attempted` (`email_attempted`,`attempted_at`),
  KEY `idx_login_success` (`was_successful`),
  CONSTRAINT `fk_login_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `news_posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `author_user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `cover_image_path` varchar(255) DEFAULT NULL,
  `publication_status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_news_status_published` (`publication_status`,`published_at`),
  KEY `idx_news_author_created` (`author_user_id`,`created_at`),
  CONSTRAINT `fk_news_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` bigint(20) unsigned NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `message` varchar(500) NOT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_recipient_read` (`recipient_user_id`,`is_read`,`created_at`),
  KEY `idx_notifications_type` (`notification_type`),
  CONSTRAINT `fk_notifications_recipient` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `reset_code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_password_reset_codes_user_id` (`user_id`),
  KEY `idx_password_reset_codes_expires_at` (`expires_at`),
  CONSTRAINT `fk_password_reset_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `species` enum('dog','cat','other') NOT NULL,
  `breed` varchar(150) DEFAULT NULL,
  `sex` enum('male','female','unknown') NOT NULL DEFAULT 'unknown',
  `birth_date` date DEFAULT NULL,
  `estimated_age_months` smallint(5) unsigned DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `intake_date` date DEFAULT NULL,
  `adoption_status` enum('available','pending','adopted','inactive') NOT NULL DEFAULT 'available',
  `story` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deactivated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pets_status_species` (`adoption_status`,`species`),
  KEY `idx_pets_location` (`location`),
  KEY `idx_pets_name` (`name`),
  KEY `idx_pets_breed` (`breed`),
  KEY `idx_pets_intake_date` (`intake_date`),
  KEY `idx_pets_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_pets_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_pets_age` CHECK (`estimated_age_months` is null or `estimated_age_months` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stray_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reporter_user_id` bigint(20) unsigned DEFAULT NULL,
  `animal_type` enum('dog','cat','other') NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `colour` varchar(100) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `sex` varchar(20) DEFAULT NULL,
  `condition_category` varchar(40) DEFAULT NULL,
  `description` text NOT NULL,
  `condition_notes` text DEFAULT NULL,
  `admin_notes` varchar(2000) DEFAULT NULL,
  `location_text` varchar(255) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `urgency` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `report_status` enum('submitted','under_review','assigned','resolved','closed') NOT NULL DEFAULT 'submitted',
  `assigned_admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stray_status_urgency` (`report_status`,`urgency`,`submitted_at`),
  KEY `idx_stray_reporter` (`reporter_user_id`),
  KEY `idx_stray_assigned_admin` (`assigned_admin_user_id`),
  CONSTRAINT `fk_stray_assigned_admin` FOREIGN KEY (`assigned_admin_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stray_reporter` FOREIGN KEY (`reporter_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_stray_latitude` CHECK (`latitude` is null or `latitude` between -90 and 90),
  CONSTRAINT `chk_stray_longitude` CHECK (`longitude` is null or `longitude` between -180 and 180)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_auth_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `session_handle_hash` binary(32) NOT NULL,
  `created_at` datetime NOT NULL,
  `last_active_at` datetime NOT NULL,
  `absolute_expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_reason` varchar(40) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_auth_sessions_handle_hash` (`session_handle_hash`),
  KEY `idx_user_auth_sessions_user_active` (`user_id`,`revoked_at`,`last_active_at`,`id`),
  CONSTRAINT `fk_user_auth_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_profiles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `profile_image_path` varchar(500) DEFAULT NULL,
  `preferences_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferences_json`)),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(48) NOT NULL,
  `occurred_at` datetime NOT NULL,
  `device_summary` varchar(120) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_security_events_user_time` (`user_id`,`occurred_at`,`id`),
  CONSTRAINT `fk_user_security_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `administrator_mfa_recovery_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_administrator_mfa_recovery_user_unused` (`user_id`,`used_at`,`id`),
  CONSTRAINT `fk_administrator_mfa_recovery_settings` FOREIGN KEY (`user_id`) REFERENCES `administrator_mfa_settings` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `adoption_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pet_id` bigint(20) unsigned NOT NULL,
  `applicant_user_id` bigint(20) unsigned NOT NULL,
  `reason` text NOT NULL,
  `application_status` enum('pending','approved','rejected','withdrawn','cancelled') NOT NULL DEFAULT 'pending',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_applicant_submitted` (`applicant_user_id`,`submitted_at`),
  KEY `idx_application_pet_status` (`pet_id`,`application_status`),
  KEY `idx_application_status_submitted` (`application_status`,`submitted_at`),
  KEY `idx_application_reviewed_by` (`reviewed_by_user_id`),
  CONSTRAINT `fk_application_applicant` FOREIGN KEY (`applicant_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_application_pet` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_application_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender_user_id` bigint(20) unsigned NOT NULL,
  `message_body` text NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_messages_conversation` (`conversation_id`,`created_at`),
  KEY `idx_chat_messages_sender` (`sender_user_id`,`created_at`),
  CONSTRAINT `fk_chat_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chat_messages_sender` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medical_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pet_id` bigint(20) unsigned NOT NULL,
  `record_type` enum('vaccination','treatment','sterilization','checkup','other') NOT NULL,
  `title` varchar(200) NOT NULL,
  `details` text DEFAULT NULL,
  `record_date` date NOT NULL,
  `next_due_date` date DEFAULT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medical_pet_date` (`pet_id`,`record_date`),
  KEY `idx_medical_type_due` (`record_type`,`next_due_date`),
  KEY `idx_medical_recorded_by` (`recorded_by_user_id`),
  CONSTRAINT `fk_medical_pet` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_medical_recorded_by` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `donation_id` bigint(20) unsigned NOT NULL,
  `transaction_reference` varchar(100) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'MYR',
  `transaction_status` enum('initiated','pending','successful','failed','refunded') NOT NULL DEFAULT 'initiated',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_reference` (`transaction_reference`),
  KEY `idx_payment_donation_status` (`donation_id`,`transaction_status`),
  KEY `idx_payment_status_processed` (`transaction_status`,`processed_at`),
  CONSTRAINT `fk_payment_donation` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_payment_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pet_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pet_id` bigint(20) unsigned NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size_bytes` int(10) unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pet_images_file_path` (`file_path`),
  KEY `idx_pet_images_pet` (`pet_id`,`is_primary`,`sort_order`),
  KEY `idx_pet_images_uploaded_by` (`uploaded_by_user_id`),
  CONSTRAINT `fk_pet_images_pet` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pet_images_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_pet_images_file_size` CHECK (`file_size_bytes` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `post_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL,
  `author_user_id` bigint(20) unsigned NOT NULL,
  `content` text NOT NULL,
  `comment_status` enum('published','removed') NOT NULL DEFAULT 'published',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comments_post_created` (`post_id`,`created_at`),
  KEY `idx_comments_author_created` (`author_user_id`,`created_at`),
  CONSTRAINT `fk_comments_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `post_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size_bytes` int(10) unsigned NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_images_file_path` (`file_path`),
  KEY `idx_post_images_post` (`post_id`,`sort_order`),
  CONSTRAINT `fk_post_images_post` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_post_images_file_size` CHECK (`file_size_bytes` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `post_likes` (
  `post_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`post_id`,`user_id`),
  KEY `idx_post_likes_user` (`user_id`),
  CONSTRAINT `fk_post_likes_post` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_post_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stray_report_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stray_report_id` bigint(20) unsigned NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size_bytes` int(10) unsigned NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stray_images_file_path` (`file_path`),
  KEY `idx_stray_images_report` (`stray_report_id`,`sort_order`),
  CONSTRAINT `fk_stray_images_report` FOREIGN KEY (`stray_report_id`) REFERENCES `stray_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_stray_images_file_size` CHECK (`file_size_bytes` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `application_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) NOT NULL,
  `changed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_history_application` (`application_id`,`changed_at`),
  KEY `idx_status_history_new_status` (`new_status`),
  KEY `idx_status_history_changed_by` (`changed_by_user_id`),
  CONSTRAINT `fk_status_history_application` FOREIGN KEY (`application_id`) REFERENCES `adoption_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_status_history_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `generated_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_type` enum('donation_receipt','adoption_certificate') NOT NULL,
  `document_number` varchar(100) NOT NULL,
  `donation_id` bigint(20) unsigned DEFAULT NULL,
  `application_id` bigint(20) unsigned DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `generated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `email_sending_at` datetime DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_number` (`document_number`),
  UNIQUE KEY `uq_document_file_path` (`file_path`),
  UNIQUE KEY `uq_document_donation` (`donation_id`),
  UNIQUE KEY `uq_document_application` (`application_id`),
  KEY `idx_documents_type` (`document_type`),
  KEY `idx_documents_generated_by` (`generated_by_user_id`),
  KEY `idx_generated_documents_email_delivery` (`email_sent_at`,`email_sending_at`),
  CONSTRAINT `fk_documents_application` FOREIGN KEY (`application_id`) REFERENCES `adoption_applications` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_donation` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_generated_by` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_documents_source` CHECK (`document_type` = 'donation_receipt' and `donation_id` is not null and `application_id` is null or `document_type` = 'adoption_certificate' and `application_id` is not null and `donation_id` is null)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time first Super Administrator initialization state.
-- The row is installed with the schema and is never created/reset at runtime.
CREATE TABLE `first_super_admin_bootstrap` (
  `singleton_id` tinyint(3) unsigned NOT NULL,
  `state` enum('uninitialized','prepared','completed') NOT NULL DEFAULT 'uninitialized',
  `target_user_id` bigint(20) unsigned DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`singleton_id`),
  UNIQUE KEY `uq_first_super_admin_bootstrap_target` (`target_user_id`),
  CONSTRAINT `chk_first_super_admin_bootstrap_singleton` CHECK (`singleton_id` = 1),
  CONSTRAINT `chk_first_super_admin_bootstrap_state` CHECK (
    (`state` = 'uninitialized' AND `target_user_id` IS NULL AND `prepared_at` IS NULL AND `completed_at` IS NULL)
    OR (`state` = 'prepared' AND `target_user_id` IS NOT NULL AND `prepared_at` IS NOT NULL AND `completed_at` IS NULL)
    OR (`state` = 'completed' AND `target_user_id` IS NOT NULL AND `prepared_at` IS NOT NULL AND `completed_at` IS NOT NULL)
  ),
  CONSTRAINT `fk_first_super_admin_bootstrap_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `first_super_admin_bootstrap` (`singleton_id`, `state`) VALUES (1, 'uninitialized');

-- FYP DEMONSTRATION ACCOUNT ONLY. This shared credential is not suitable for production.
-- Login email: demo.admin@example.invalid
-- Username: demo_superadmin
-- Password: DemoAdmin!2026
-- First sign-in requires local MFA setup; generate a unique config/mfa.local.php key first.
INSERT INTO users (id, username, name, email, password_hash, role, account_status)
VALUES (1, 'demo_superadmin', 'FYP Demonstration Administrator', 'demo.admin@example.invalid', '$2y$10$bfyrA7Gdqg1wtuFGX/1Jlu4nUHo0OWYrvxIeBWMjIk8zgoGTOzpcq', 'super_administrator', 'active');

INSERT INTO user_profiles (user_id) VALUES (1);

INSERT INTO email_verifications (user_id, verification_code_hash, expires_at, verified_at)
VALUES (1, '$2y$10$BH6v.IS6P1tJJhFBDE/3oeLrljAelwBeBg779P/XGn.VCQ8RWEccW', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW());

UPDATE first_super_admin_bootstrap
SET state = 'completed', target_user_id = 1, prepared_at = NOW(), completed_at = NOW()
WHERE singleton_id = 1 AND state = 'uninitialized';

SET FOREIGN_KEY_CHECKS=1;
