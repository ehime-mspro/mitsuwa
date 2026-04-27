-- DAD（土木事業）管理 テーブル一式
-- 実行: sudo mysql manage < database/sql/create_dad_tables.sql

-- 1. 専門分野マスタ（協力業者プルダウン用 / 色設定込み）
CREATE TABLE IF NOT EXISTS `dad_specialties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL COMMENT '専門分野名 例: 土工/舗装/配管',
  `color_bg` VARCHAR(7) NOT NULL DEFAULT '#f3f4f6' COMMENT 'バッジ背景色 hex',
  `color_text` VARCHAR(7) NOT NULL DEFAULT '#374151' COMMENT 'バッジ文字色 hex',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '表示順',
  `is_active` BOOLEAN NOT NULL DEFAULT 1 COMMENT '有効/無効',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_dad_specialties_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 専門分野マスタ';

-- 2. 発注者・元請け
CREATE TABLE IF NOT EXISTS `dad_clients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_type` VARCHAR(20) NOT NULL COMMENT 'government/municipality/company/individual',
  `name` VARCHAR(100) NOT NULL COMMENT '発注者名',
  `representative` VARCHAR(50) NULL COMMENT '代表者名・担当者名',
  `postal_code` VARCHAR(10) NULL,
  `address` VARCHAR(200) NULL,
  `phone` VARCHAR(20) NULL,
  `fax` VARCHAR(20) NULL,
  `email` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 発注者';

-- 3. 協力業者
CREATE TABLE IF NOT EXISTS `dad_subcontractors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `company_name` VARCHAR(100) NOT NULL COMMENT '会社名',
  `representative` VARCHAR(50) NULL COMMENT '代表者名',
  `postal_code` VARCHAR(10) NULL,
  `address` VARCHAR(200) NULL,
  `phone` VARCHAR(20) NULL,
  `fax` VARCHAR(20) NULL,
  `email` VARCHAR(255) NULL,
  `specialty_id` BIGINT UNSIGNED NULL COMMENT 'dad_specialties FK',
  `notes` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_dad_subcontractors_specialty` FOREIGN KEY (`specialty_id`) REFERENCES `dad_specialties`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 協力業者';

-- 4. 従業員
CREATE TABLE IF NOT EXISTS `dad_employees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `employee_code` VARCHAR(20) NOT NULL COMMENT '社員番号',
  `name` VARCHAR(50) NOT NULL,
  `name_kana` VARCHAR(50) NULL,
  `phone` VARCHAR(20) NULL,
  `position` VARCHAR(50) NULL COMMENT '役職',
  `qualifications` TEXT NULL COMMENT '保有資格（テキスト）',
  `hire_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/retired',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_dad_employees_code` (`employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 従業員';

-- 5. 工事案件
CREATE TABLE IF NOT EXISTS `dad_projects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_code` VARCHAR(20) NOT NULL COMMENT '案件番号 DAD-NNN',
  `project_name` VARCHAR(200) NOT NULL,
  `project_type` VARCHAR(20) NOT NULL COMMENT 'public/private',
  `status` VARCHAR(20) NOT NULL COMMENT 'estimate/ordered/in_progress/completed/paid/lost',
  `client_id` BIGINT UNSIGNED NULL,
  `site_address` VARCHAR(300) NULL COMMENT '工事現場住所',
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `estimate_amount` INT UNSIGNED NULL COMMENT '見積金額（税抜）',
  `contract_amount` INT UNSIGNED NULL COMMENT '受注金額（税抜）',
  `estimate_date` DATE NULL,
  `order_date` DATE NULL,
  `start_date` DATE NULL COMMENT '着工日',
  `completion_date` DATE NULL COMMENT '完工日',
  `payment_date` DATE NULL,
  `period_start` DATE NULL COMMENT '工期開始',
  `period_end` DATE NULL COMMENT '工期終了',
  `staff_user_id` BIGINT UNSIGNED NULL COMMENT '担当者（現場代理人）users FK',
  `memo` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_dad_projects_code` (`project_code`),
  CONSTRAINT `fk_dad_projects_client` FOREIGN KEY (`client_id`) REFERENCES `dad_clients`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dad_projects_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 工事案件';

-- 6. 工事原価明細
CREATE TABLE IF NOT EXISTS `dad_project_costs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `cost_category` VARCHAR(30) NOT NULL COMMENT 'material/subcontract/labor/equipment/overhead/other',
  `description` VARCHAR(200) NULL COMMENT '内容・摘要',
  `estimated_amount` INT UNSIGNED NULL COMMENT '見積額',
  `actual_amount` INT UNSIGNED NULL COMMENT '実績額',
  `subcontractor_id` BIGINT UNSIGNED NULL COMMENT '外注費の場合の協力業者',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  KEY `idx_dad_project_costs_project` (`project_id`),
  CONSTRAINT `fk_dad_project_costs_project` FOREIGN KEY (`project_id`) REFERENCES `dad_projects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dad_project_costs_sub` FOREIGN KEY (`subcontractor_id`) REFERENCES `dad_subcontractors`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 工事原価明細';

-- 7. 工事人員配置
CREATE TABLE IF NOT EXISTS `dad_project_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(50) NULL COMMENT '現場代理人/主任技術者/作業員等',
  `start_date` DATE NULL COMMENT '配置開始日',
  `end_date` DATE NULL COMMENT '配置終了日',
  `notes` VARCHAR(200) NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_dad_assign_project_emp` (`project_id`, `employee_id`),
  CONSTRAINT `fk_dad_assign_project` FOREIGN KEY (`project_id`) REFERENCES `dad_projects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dad_assign_employee` FOREIGN KEY (`employee_id`) REFERENCES `dad_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DAD 工事人員配置';

-- 部署マスタに「DAD（土木事業）」を追加（既存レコードはスキップ）
INSERT IGNORE INTO `departments` (`code`, `name`, `display_order`, `created_at`, `updated_at`)
VALUES ('dad', 'DAD（土木事業）', 5, NOW(), NOW());

-- 専門分野マスタの初期データ（モック準拠の8プリセット）
INSERT IGNORE INTO `dad_specialties` (`name`, `color_bg`, `color_text`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
('土工',   '#fef3c7', '#92400e', 1, 1, NOW(), NOW()),
('舗装',   '#e5e7eb', '#374151', 2, 1, NOW(), NOW()),
('配管',   '#dbeafe', '#1e40af', 3, 1, NOW(), NOW()),
('電気',   '#fef9c3', '#854d0e', 4, 1, NOW(), NOW()),
('解体',   '#fee2e2', '#991b1b', 5, 1, NOW(), NOW()),
('仮設',   '#ede9fe', '#5b21b6', 6, 1, NOW(), NOW()),
('緑系',   '#d1fae5', '#065f46', 7, 1, NOW(), NOW()),
('その他', '#f3f4f6', '#4b5563', 8, 1, NOW(), NOW());
