CREATE TABLE `action_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` int NOT NULL,
  `contact_id` int NOT NULL,
  `node_id` varchar(50) NOT NULL,
  `action_data` json NOT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_cashouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `usdt_wallet` varchar(100) DEFAULT NULL,
  `request_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','processed','paid','revoked') NOT NULL DEFAULT 'pending',
  `processed_date` datetime DEFAULT NULL,
  `comments` text,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `affiliate_cashouts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_earnings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `source` enum('referral','membership') NOT NULL,
  `source_id` int DEFAULT NULL,
  `earned_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_paid` tinyint(1) NOT NULL DEFAULT '0',
  `is_notified` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `affiliate_earnings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_referrals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `referrer_id` int NOT NULL,
  `referred_id` int NOT NULL,
  `referral_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `has_converted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `referred_id` (`referred_id`),
  KEY `referrer_id` (`referrer_id`),
  CONSTRAINT `affiliate_referrals_ibfk_1` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `affiliate_referrals_ibfk_2` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_rewards` (
  `membership_id` int NOT NULL,
  `percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`membership_id`),
  CONSTRAINT `affiliate_rewards_ibfk_1` FOREIGN KEY (`membership_id`) REFERENCES `membership_levels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `api_usage_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `api_key` varchar(64) NOT NULL,
  `endpoint` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `used_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `api_key` (`api_key`),
  KEY `used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `automation_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` int NOT NULL,
  `contact_id` int NOT NULL,
  `status` enum('pending','success','failed') NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details` text,
  PRIMARY KEY (`id`),
  KEY `workflow_id` (`workflow_id`),
  KEY `contact_id` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `automation_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` int NOT NULL,
  `contact_id` int NOT NULL,
  `node_id` varchar(50) NOT NULL,
  `status` enum('pending','processed','failed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  `error` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `automation_workflows` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `source_type` enum('stream','cohort','contact') NOT NULL,
  `source_config_json` json NOT NULL,
  `condition_type` enum('churn_probability','competitor_visit','feature_usage','last_login') NOT NULL,
  `condition_config_json` json NOT NULL,
  `action_type` enum('email','sms','change_cohort','notification','external') NOT NULL,
  `action_config_json` json NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `automation_workflows_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `bank_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(100) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `routing_number` varchar(50) DEFAULT NULL,
  `swift_code` varchar(50) DEFAULT NULL,
  `iban` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `campaign_actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `action_type` enum('email','sms','change_cohort','notification','external') NOT NULL,
  `execution_method` enum('internal','external') DEFAULT 'internal',
  `external_action_id` int DEFAULT NULL,
  `template_id` int DEFAULT NULL,
  `cohort_id` int DEFAULT NULL,
  `phone_field` varchar(100) DEFAULT NULL,
  `action_metadata` text,
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `template_id` (`template_id`),
  KEY `cohort_id` (`cohort_id`),
  KEY `campaign_actions_ibfk_4` (`external_action_id`),
  CONSTRAINT `campaign_actions_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `winback_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `campaign_actions_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `campaign_actions_ibfk_3` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `campaign_actions_ibfk_4` FOREIGN KEY (`external_action_id`) REFERENCES `user_external_actions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `campaign_conditions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `condition_type` enum('churn_probability','competitor_visit','feature_usage','last_login') NOT NULL,
  `value` varchar(255) NOT NULL,
  `operator` enum('>','<','=','>=','<=','!=') NOT NULL DEFAULT '>',
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  CONSTRAINT `campaign_conditions_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `winback_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `campaign_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `contact_id` int NOT NULL,
  `action_type` enum('email','sms','change_cohort') NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `details` text,
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `campaign_logs_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `winback_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `campaign_logs_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `campaign_sources` (
  `campaign_id` int NOT NULL,
  `source_type` enum('stream','cohort','contact') NOT NULL,
  `source_id` int NOT NULL,
  PRIMARY KEY (`campaign_id`,`source_type`,`source_id`),
  CONSTRAINT `campaign_sources_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `winback_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `churned_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `churned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` varchar(255) DEFAULT NULL,
  `revenue_impact` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_id` (`contact_id`),
  CONSTRAINT `churned_users_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `churn_index` (
  `user_id` int NOT NULL,
  `month` date NOT NULL,
  `index_value` decimal(3,1) NOT NULL,
  `percentile` decimal(5,2) NOT NULL,
  PRIMARY KEY (`user_id`,`month`),
  CONSTRAINT `churn_index_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `churn_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` enum('activity','subscription','engagement','custom') NOT NULL,
  `description` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `churn_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `scored_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `report` text,
  `model_used` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `churn_scores_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `cohorts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stream_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `cost_per_user` decimal(10,2) DEFAULT NULL,
  `revenue_per_user` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `cohorts_ibfk_1` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `competitors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stream_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `url` varchar(255) NOT NULL,
  `is_pricing` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `competitors_ibfk_1` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting` varchar(255) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting` (`setting`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `contacts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stream_id` int NOT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity` datetime DEFAULT NULL,
  `custom_data` text,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `contacts_ibfk_1` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `contact_cohorts` (
  `contact_id` int NOT NULL,
  `cohort_id` int NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`contact_id`,`cohort_id`),
  KEY `cohort_id` (`cohort_id`),
  CONSTRAINT `contact_cohorts_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_cohorts_ibfk_2` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `contact_custom_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_value` text,
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `contact_custom_fields_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `contact_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `user_id` int NOT NULL,
  `note` text NOT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `is_private` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `contact_notes_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `contact_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `custom_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `custom_metrics_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `email_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `category` enum('discount','feature','survey','support','marketing','general') NOT NULL,
  `sender_name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `email_templates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `experiments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_experiment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `experiment_metrics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_id` int NOT NULL,
  `source_id` int NOT NULL COMMENT 'Links to experiment_sources',
  `metric_date` date NOT NULL,
  `churn_rate` decimal(5,2) NOT NULL,
  `session_duration` decimal(6,2) DEFAULT NULL,
  `feature_adoption` decimal(5,2) DEFAULT NULL,
  `sample_size` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_metric` (`experiment_id`,`source_id`,`metric_date`),
  KEY `source_id` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `experiment_sources` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_id` int NOT NULL,
  `channel_type` enum('blog','landing','social','email') NOT NULL,
  `source_type` enum('stream','cohort','contact') NOT NULL,
  `source_id` int NOT NULL,
  `specific_value` varchar(255) DEFAULT NULL COMMENT 'URL for blog/landing, platform for social, campaign name for email',
  PRIMARY KEY (`id`),
  KEY `experiment_id` (`experiment_id`),
  CONSTRAINT `fk_experiment_source` FOREIGN KEY (`experiment_id`) REFERENCES `experiments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `external_actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_name` enum('mautic','hubspot','salesforce','zapier','custom') NOT NULL,
  `action_name` varchar(100) NOT NULL,
  `description` text,
  `required_fields` text COMMENT 'JSON array of required fields',
  `config_template` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_action` (`action_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `external_action_configs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `integration_id` int NOT NULL,
  `action_name` varchar(100) NOT NULL,
  `config_json` json NOT NULL,
  PRIMARY KEY (`id`),
  KEY `integration_id` (`integration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `external_action_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action_id` int NOT NULL,
  `contact_id` int DEFAULT NULL,
  `execution_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `response` text,
  `payload` text,
  PRIMARY KEY (`id`),
  KEY `action_id` (`action_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `external_action_logs_ibfk_1` FOREIGN KEY (`action_id`) REFERENCES `campaign_actions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `external_action_logs_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `external_integrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `service_name` enum('mautic','hubspot','salesforce','zapier','custom') NOT NULL,
  `auth_config` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_connected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `external_service_auth` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `service_name` enum('zapier','mautic','hubspot','salesforce','monday') NOT NULL,
  `auth_type` enum('api_key','oauth') NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `access_token` text,
  `refresh_token` text,
  `token_expires` datetime DEFAULT NULL,
  `base_url` varchar(255) DEFAULT NULL,
  `metadata` text,
  `last_connected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_service` (`user_id`,`service_name`),
  CONSTRAINT `external_service_auth_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stream_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `features_ibfk_1` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `feature_trends` (
  `tag` varchar(100) NOT NULL,
  `month` date NOT NULL,
  `count` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`tag`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `helpdesk_responses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL,
  `response` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `helpdesk_responses_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_responses_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `helpdesk_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `stream_id` int DEFAULT NULL,
  `status` enum('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  `review_requested` tinyint(1) NOT NULL DEFAULT '0',
  `reviewed` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `helpdesk_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helpdesk_tickets_ibfk_2` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `membership_gifts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `membership_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `duration_months` int NOT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `membership_id` (`membership_id`),
  CONSTRAINT `membership_gifts_ibfk_1` FOREIGN KEY (`membership_id`) REFERENCES `membership_levels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `membership_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `standard_price` decimal(10,2) NOT NULL,
  `promo_price` decimal(10,2) NOT NULL,
  `yearly_discount` decimal(5,2) NOT NULL DEFAULT '0.00',
  `max_streams` int NOT NULL DEFAULT '1',
  `max_contacts` int NOT NULL DEFAULT '50',
  `features` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `allow_sms_alerts` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `metric_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `metric_id` int DEFAULT NULL,
  `custom_metric_id` int DEFAULT NULL,
  `value` text NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` enum('tracking','api','import','helpdesk') NOT NULL DEFAULT 'api',
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `metric_id` (`metric_id`),
  KEY `custom_metric_id` (`custom_metric_id`),
  CONSTRAINT `metric_data_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `metric_data_ibfk_2` FOREIGN KEY (`metric_id`) REFERENCES `churn_metrics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `metric_data_ibfk_3` FOREIGN KEY (`custom_metric_id`) REFERENCES `custom_metrics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `niches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `note_recipients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `note_id` int NOT NULL,
  `user_id` int NOT NULL,
  `notified_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note_recipient` (`note_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_note_recipients_note_id` FOREIGN KEY (`note_id`) REFERENCES `contact_notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_note_recipients_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT NULL,
  `type` enum('system','payment','affiliate','churn','helpdesk','team_invite','team_invite_accepted','team_invite_declined','team_removed','team_role_changed','note_added','private_note_added') NOT NULL DEFAULT 'system',
  `related_id` int DEFAULT NULL,
  `related_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `payment_gateways` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `paypal_client_id` varchar(255) DEFAULT NULL,
  `paypal_secret` varchar(255) DEFAULT NULL,
  `flutterwave_public_key` varchar(255) DEFAULT NULL,
  `flutterwave_secret_key` varchar(255) DEFAULT NULL,
  `usdt_wallet` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `resurrected_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `churned_at` datetime NOT NULL,
  `resurrected_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_id` (`contact_id`),
  CONSTRAINT `resurrected_users_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `streams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `team_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `is_app` tinyint(1) NOT NULL DEFAULT '0',
  `description` text,
  `color_code` varchar(7) NOT NULL DEFAULT '#3366ff',
  `niche_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tracking_code` varchar(32) NOT NULL,
  `acquisition_cost` decimal(10,2) DEFAULT '0.00',
  `cover_image` varchar(255) DEFAULT NULL,
  `marketing_channel` varchar(255) DEFAULT NULL,
  `revenue_per_user` decimal(10,2) DEFAULT '0.00',
  `currency` varchar(3) DEFAULT 'USD',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `niche_id` (`niche_id`),
  KEY `streams_ibfk_3` (`team_id`),
  CONSTRAINT `streams_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `streams_ibfk_2` FOREIGN KEY (`niche_id`) REFERENCES `niches` (`id`),
  CONSTRAINT `streams_ibfk_3` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `teams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `teams_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `team_invites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(64) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `invited_by` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `team_id` (`team_id`),
  KEY `invited_by` (`invited_by`),
  CONSTRAINT `team_invites_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_invites_ibfk_2` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `team_members` (
  `team_id` int NOT NULL,
  `user_id` int NOT NULL,
  `role` enum('owner','editor','viewer') NOT NULL DEFAULT 'viewer',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invited_by` int NOT NULL,
  PRIMARY KEY (`team_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `invited_by` (`invited_by`),
  CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_members_ibfk_3` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `team_streams` (
  `team_id` int NOT NULL,
  `stream_id` int NOT NULL,
  `access_level` enum('edit','view') NOT NULL DEFAULT 'view',
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by` int NOT NULL,
  PRIMARY KEY (`team_id`,`stream_id`),
  KEY `stream_id` (`stream_id`),
  KEY `added_by` (`added_by`),
  CONSTRAINT `team_streams_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_streams_ibfk_2` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_streams_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ticket_reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `review` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `ticket_reviews_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `helpdesk_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('paypal','flutterwave','usdt','bank') NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `bank_proof` varchar(255) DEFAULT NULL,
  `membership_id` int DEFAULT NULL,
  `is_yearly` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `membership_id` (`membership_id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`membership_id`) REFERENCES `membership_levels` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `activation_token` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `login_token` varchar(64) DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_alert_thresholds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `stream_id` int DEFAULT NULL,
  `threshold` decimal(5,2) NOT NULL,
  `alert_methods` varchar(255) NOT NULL DEFAULT 'email',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `stream_id` (`stream_id`),
  CONSTRAINT `user_alert_thresholds_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_alert_thresholds_ibfk_2` FOREIGN KEY (`stream_id`) REFERENCES `streams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used` datetime DEFAULT NULL,
  `tracking_method` enum('gdpr','non_gdpr','hybrid') NOT NULL DEFAULT 'gdpr',
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_api_keys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_external_actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `external_action_id` int NOT NULL,
  `configuration` text NOT NULL COMMENT 'JSON configuration',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `external_action_id` (`external_action_id`),
  CONSTRAINT `user_external_actions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_external_actions_ibfk_2` FOREIGN KEY (`external_action_id`) REFERENCES `external_actions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_gifts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `gift_id` int NOT NULL,
  `earned_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_notified` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `gift_id` (`gift_id`),
  CONSTRAINT `user_gifts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_gifts_ibfk_2` FOREIGN KEY (`gift_id`) REFERENCES `membership_gifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_payment_methods` (
  `user_id` int NOT NULL,
  `method` enum('paypal','bank','usdt') NOT NULL,
  `paypal_email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `routing_number` varchar(50) DEFAULT NULL,
  `usdt_wallet` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_payment_methods_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_profiles` (
  `user_id` int NOT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `company_name` varchar(100) DEFAULT NULL,
  `industry` varchar(50) DEFAULT NULL,
  `company_size` varchar(20) DEFAULT NULL,
  `company_info` text,
  `full_name` varchar(100) DEFAULT NULL,
  `alert_is_email` tinyint(1) NOT NULL DEFAULT '1',
  `alert_is_sms` tinyint(1) NOT NULL DEFAULT '0',
  `phone_number` varchar(20) DEFAULT NULL,
  `webhook_type` enum('slack','microsoft_teams','discord') DEFAULT NULL,
  `webhook_url` varchar(255) DEFAULT NULL,
  `stripe_key` varchar(255) DEFAULT NULL,
  `chargebee_key` varchar(255) DEFAULT NULL,
  `segment_key` varchar(255) DEFAULT NULL,
  `zendesk_key` varchar(255) DEFAULT NULL,
  `freshdesk_key` varchar(255) DEFAULT NULL,
  `zapier_webhook` varchar(255) DEFAULT NULL,
  `webhook_slack` varchar(255) DEFAULT NULL,
  `webhook_teams` varchar(255) DEFAULT NULL,
  `webhook_discord` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `membership_id` int NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `is_yearly` tinyint(1) NOT NULL DEFAULT '0',
  `duration_days` int DEFAULT NULL,
  `payment_method` enum('paypal','flutterwave','usdt','bank') NOT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `cancelled_at` datetime DEFAULT NULL,
  `admin_assigned` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `membership_id` (`membership_id`),
  CONSTRAINT `user_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_subscriptions_ibfk_2` FOREIGN KEY (`membership_id`) REFERENCES `membership_levels` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `winback_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `execution_data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `winback_campaigns_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `winback_suggestions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contact_id` int NOT NULL,
  `suggestion` text NOT NULL,
  `suggested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_implemented` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `winback_suggestions_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
