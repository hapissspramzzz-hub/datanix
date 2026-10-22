CREATE DATABASE IF NOT EXISTS datanix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE datanix;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('member','admin') NOT NULL DEFAULT 'member',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 ai_disclosure_at DATETIME NULL,
 last_login_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workspaces (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(140) NOT NULL,
 slug VARCHAR(160) NOT NULL,
 plan ENUM('free','pro','business') NOT NULL DEFAULT 'free',
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_workspace_slug (slug),
 KEY idx_workspace_owner (owner_id),
 CONSTRAINT fk_workspace_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workspace_members (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 member_role ENUM('owner','admin','editor','analyst','viewer') NOT NULL DEFAULT 'viewer',
 invited_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_workspace_member (workspace_id,user_id),
 KEY idx_member_user (user_id),
 CONSTRAINT fk_member_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_member_inviter FOREIGN KEY(invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS data_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 source_type ENUM('csv','xlsx','json','mysql','pgsql','api') NOT NULL,
 status ENUM('connected','syncing','error','disconnected') NOT NULL DEFAULT 'connected',
 schema_json LONGTEXT NULL,
 connection_ciphertext LONGTEXT NULL,
 connection_nonce VARCHAR(128) NULL,
 connection_tag VARCHAR(128) NULL,
 storage_path VARCHAR(300) NULL,
 row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_updated_at DATETIME NULL,
 error_message VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_source_workspace (workspace_id),
 KEY idx_source_owner (owner_id),
 CONSTRAINT fk_source_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_source_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS datasets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 file_name VARCHAR(255) NULL,
 row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 column_count INT UNSIGNED NOT NULL DEFAULT 0,
 schema_json LONGTEXT NOT NULL,
 source_license VARCHAR(180) NULL,
 source_citation TEXT NULL,
 is_sample TINYINT(1) NOT NULL DEFAULT 0,
 storage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_dataset_workspace (workspace_id),
 KEY idx_dataset_source (source_id),
 CONSTRAINT fk_dataset_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_dataset_source FOREIGN KEY(source_id) REFERENCES data_sources(id) ON DELETE CASCADE,
 CONSTRAINT fk_dataset_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dataset_columns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 dataset_id BIGINT UNSIGNED NOT NULL,
 column_name VARCHAR(190) NOT NULL,
 data_type ENUM('integer','decimal','date','boolean','text') NOT NULL DEFAULT 'text',
 ordinal INT UNSIGNED NOT NULL,
 nullable TINYINT(1) NOT NULL DEFAULT 1,
 sample_values_json TEXT NULL,
 UNIQUE KEY uq_dataset_column_name (dataset_id,column_name),
 UNIQUE KEY uq_dataset_column_order (dataset_id,ordinal),
 CONSTRAINT fk_column_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dataset_rows (
 dataset_id BIGINT UNSIGNED NOT NULL,
 `row_number` BIGINT UNSIGNED NOT NULL,
 row_json LONGTEXT NOT NULL,
 PRIMARY KEY(dataset_id,`row_number`),
 CONSTRAINT fk_row_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 dataset_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(220) NOT NULL DEFAULT 'Percakapan baru',
 status ENUM('open','archived') NOT NULL DEFAULT 'open',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_conversation_workspace (workspace_id,user_id,updated_at),
 CONSTRAINT fk_conversation_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_conversation_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE SET NULL,
 CONSTRAINT fk_conversation_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL,
 role ENUM('user','assistant','system') NOT NULL,
 content LONGTEXT NOT NULL,
 result_json LONGTEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_message_conversation (conversation_id,created_at),
 CONSTRAINT fk_message_conversation FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_queries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 dataset_id BIGINT UNSIGNED NULL,
 conversation_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 question VARCHAR(1500) NOT NULL,
 query_kind VARCHAR(60) NULL,
 result_json LONGTEXT NULL,
 model VARCHAR(100) NULL,
 status ENUM('success','insufficient','error') NOT NULL DEFAULT 'success',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_query_workspace_date (workspace_id,created_at),
 KEY idx_query_user_date (user_id,created_at),
 CONSTRAINT fk_query_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_query_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE SET NULL,
 CONSTRAINT fk_query_conversation FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
 CONSTRAINT fk_query_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dashboards (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 dataset_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 description TEXT NULL,
 filter_json LONGTEXT NULL,
 layout_json LONGTEXT NULL,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_dashboard_workspace (workspace_id,updated_at),
 CONSTRAINT fk_dashboard_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_dashboard_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE SET NULL,
 CONSTRAINT fk_dashboard_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dashboard_widgets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 dashboard_id BIGINT UNSIGNED NOT NULL,
 dataset_id BIGINT UNSIGNED NULL,
 widget_type ENUM('metric','line','bar','donut','table') NOT NULL DEFAULT 'metric',
 title VARCHAR(180) NOT NULL,
 metric_key VARCHAR(120) NULL,
 config_json LONGTEXT NULL,
 position_x INT NOT NULL DEFAULT 0,
 position_y INT NOT NULL DEFAULT 0,
 width_units INT NOT NULL DEFAULT 6,
 height_units INT NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_widget_dashboard (dashboard_id,sort_order),
 CONSTRAINT fk_widget_dashboard FOREIGN KEY(dashboard_id) REFERENCES dashboards(id) ON DELETE CASCADE,
 CONSTRAINT fk_widget_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 dataset_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 title VARCHAR(220) NOT NULL,
 period_start DATE NULL,
 period_end DATE NULL,
 summary LONGTEXT NULL,
 result_json LONGTEXT NULL,
 format VARCHAR(12) NOT NULL DEFAULT 'html',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_report_workspace (workspace_id,created_at),
 CONSTRAINT fk_report_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_report_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE SET NULL,
 CONSTRAINT fk_report_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS saved_insights (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 dataset_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 title VARCHAR(220) NOT NULL,
 question TEXT NOT NULL,
 result_json LONGTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_insight_workspace (workspace_id,updated_at),
 CONSTRAINT fk_insight_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_insight_dataset FOREIGN KEY(dataset_id) REFERENCES datasets(id) ON DELETE SET NULL,
 CONSTRAINT fk_insight_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 workspace_id BIGINT UNSIGNED NOT NULL,
 plan ENUM('pro','business') NOT NULL,
 status ENUM('pending','active','cancelled','expired') NOT NULL DEFAULT 'pending',
 price_idr BIGINT UNSIGNED NOT NULL DEFAULT 0,
 starts_at DATETIME NULL,
 ends_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL,
 note VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_subscription_workspace (workspace_id,status),
 CONSTRAINT fk_subscription_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_subscription_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_subscription_approver FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 amount_idr BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'IDR',
 status ENUM('pending','confirmed','failed','refunded') NOT NULL DEFAULT 'pending',
 reference VARCHAR(150) NULL,
 confirmed_by BIGINT UNSIGNED NULL,
 confirmed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_payment_status_date (status,created_at),
 CONSTRAINT fk_payment_subscription FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
 CONSTRAINT fk_payment_confirmed_by FOREIGN KEY(confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usage_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 usage_type ENUM('ai_query','dataset_upload','export') NOT NULL,
 units INT UNSIGNED NOT NULL DEFAULT 1,
 month_key CHAR(7) NOT NULL,
 metadata_json TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_usage_month_workspace (workspace_id,month_key,usage_type),
 KEY idx_usage_month_user (user_id,month_key,usage_type),
 CONSTRAINT fk_usage_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
 CONSTRAINT fk_usage_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(120) NOT NULL,
 entity_type VARCHAR(100) NULL,
 entity_id BIGINT UNSIGNED NULL,
 metadata_json TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_audit_workspace_date (workspace_id,created_at),
 KEY idx_audit_actor_date (actor_id,created_at),
 CONSTRAINT fk_audit_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL,
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_reset_expiry (expires_at),
 CONSTRAINT fk_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- No seeded users, workspaces, subscriptions, payments, or analytics totals.
-- Register an account, then promote the intended first platform administrator
-- through a restricted database console as documented in README.md.
