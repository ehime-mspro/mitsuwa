-- 賃貸マンション管理 テーブル一式
-- 実行: sudo mysql manage < database/sql/create_mansion_tables.sql

CREATE TABLE IF NOT EXISTS `ms_properties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_code` VARCHAR(20) NOT NULL COMMENT '物件番号 MS-NNN',
  `property_name` VARCHAR(100) NOT NULL COMMENT '物件名',
  `ownership_type` VARCHAR(20) NOT NULL COMMENT 'self_owned/managed',
  `owner_name` VARCHAR(100) NULL COMMENT '管理受託時オーナー名',
  `postal_code` VARCHAR(10) NULL,
  `address` VARCHAR(200) NOT NULL,
  `total_units` SMALLINT UNSIGNED NULL COMMENT '総戸数',
  `total_floors` TINYINT UNSIGNED NULL COMMENT '階数',
  `structure` VARCHAR(50) NULL COMMENT 'RC造等',
  `built_year_month` VARCHAR(7) NULL COMMENT 'YYYY-MM',
  `notes` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_ms_properties_code` (`property_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション物件';

CREATE TABLE IF NOT EXISTS `ms_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_id` BIGINT UNSIGNED NOT NULL,
  `room_number` VARCHAR(20) NOT NULL COMMENT '101等',
  `floor` TINYINT UNSIGNED NULL,
  `room_type` VARCHAR(20) NULL COMMENT '1K/2LDK等',
  `area_sqm` DECIMAL(8,2) NULL COMMENT '専有面積㎡',
  `status` VARCHAR(20) NOT NULL COMMENT 'vacant/occupied/negotiating/move_out_planned',
  `rent` INT UNSIGNED NULL COMMENT '募集賃料 税抜',
  `common_fee` INT UNSIGNED NULL COMMENT '共益費',
  `deposit` INT UNSIGNED NULL,
  `key_money` INT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_ms_rooms_property_room` (`property_id`, `room_number`),
  CONSTRAINT `fk_ms_rooms_property` FOREIGN KEY (`property_id`) REFERENCES `ms_properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション部屋';

CREATE TABLE IF NOT EXISTS `ms_parkings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_id` BIGINT UNSIGNED NOT NULL,
  `parking_number` VARCHAR(20) NOT NULL COMMENT 'A-1等',
  `monthly_fee` INT UNSIGNED NOT NULL COMMENT '月額料金 税抜',
  `status` VARCHAR(20) NOT NULL COMMENT 'vacant/occupied',
  `has_roof` BOOLEAN NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_ms_parkings_property_number` (`property_id`, `parking_number`),
  CONSTRAINT `fk_ms_parkings_property` FOREIGN KEY (`property_id`) REFERENCES `ms_properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション駐車場';

CREATE TABLE IF NOT EXISTS `ms_tenants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `tenant_type` VARCHAR(20) NOT NULL COMMENT 'resident/parking_only',
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `email` VARCHAR(255) NULL,
  `workplace` VARCHAR(100) NULL,
  `emergency_contact_name` VARCHAR(100) NULL,
  `emergency_contact_phone` VARCHAR(20) NULL,
  `emergency_contact_relation` VARCHAR(50) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション利用者';

CREATE TABLE IF NOT EXISTS `ms_contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL COMMENT 'active/terminated',
  `contract_date` DATE NULL,
  `move_in_date` DATE NULL,
  `move_out_date` DATE NULL,
  `rent` INT UNSIGNED NULL,
  `common_fee` INT UNSIGNED NULL,
  `deposit` INT UNSIGNED NULL,
  `key_money` INT UNSIGNED NULL,
  `staff_user_id` BIGINT UNSIGNED NULL,
  `memo` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_contracts_room` FOREIGN KEY (`room_id`) REFERENCES `ms_rooms`(`id`),
  CONSTRAINT `fk_ms_contracts_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `ms_tenants`(`id`),
  CONSTRAINT `fk_ms_contracts_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション部屋契約';

CREATE TABLE IF NOT EXISTS `ms_parking_contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parking_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `contract_id` BIGINT UNSIGNED NULL COMMENT '部屋契約と連動するときのみ',
  `status` VARCHAR(20) NOT NULL,
  `contract_date` DATE NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `monthly_fee` INT UNSIGNED NOT NULL,
  `deposit` INT UNSIGNED NULL,
  `staff_user_id` BIGINT UNSIGNED NULL,
  `memo` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_pc_parking` FOREIGN KEY (`parking_id`) REFERENCES `ms_parkings`(`id`),
  CONSTRAINT `fk_ms_pc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `ms_tenants`(`id`),
  CONSTRAINT `fk_ms_pc_contract` FOREIGN KEY (`contract_id`) REFERENCES `ms_contracts`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_pc_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション駐車場契約';

CREATE TABLE IF NOT EXISTS `ms_contract_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `revision_date` DATE NOT NULL,
  `new_rent` INT UNSIGNED NULL,
  `new_common_fee` INT UNSIGNED NULL,
  `reason` VARCHAR(200) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_cr_contract` FOREIGN KEY (`contract_id`) REFERENCES `ms_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部屋契約 賃料改定履歴';

CREATE TABLE IF NOT EXISTS `ms_parking_contract_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parking_contract_id` BIGINT UNSIGNED NOT NULL,
  `revision_date` DATE NOT NULL,
  `new_monthly_fee` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(200) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_pcr_pc` FOREIGN KEY (`parking_contract_id`) REFERENCES `ms_parking_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='駐車場契約 料金改定履歴';
