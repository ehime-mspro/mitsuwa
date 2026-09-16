-- 決裁申請 段階1 — 2026-09-16
--
-- 設計書: docs/superpowers/specs/2026-09-16-approval-phase1-design.md §5.16
--
-- ⚠ tests/... ではなく database/migrations/2026_09_16_000001_create_approval_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** ログインが employee_number を読むので、
--   コードを先に送るとログインが Unknown column で 500 になる。
--
-- ⚠ 型・照合順序・索引名は 2026-09-16 に本番の SHOW CREATE TABLE を読んで合わせてある
--   （`users` は utf8mb4_unicode_ci・一意索引は Laravel 既定の `users_email_unique` の形）。
--   `users_employee_number_unique` はテスト用 migration の `->unique()` が付ける名前と同じ。
--
-- 適用: php artisan tinker --execute で DB::statement() に **1 文ずつ**流す
--   （sudo mysql は非対話でパスワードを渡せない。PDO::MYSQL_ATTR_MULTI_STATEMENTS も未設定）

-- 1. 利用者に社員番号を足し、メールアドレスを任意にする
ALTER TABLE `users`
  ADD COLUMN `employee_number` VARCHAR(20) NULL COMMENT '社員番号（ログインID）' AFTER `name`,
  ADD UNIQUE KEY `users_employee_number_unique` (`employee_number`);

ALTER TABLE `users`
  MODIFY COLUMN `email` VARCHAR(255) NULL;

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('executive','manager','staff','approval_only') NOT NULL DEFAULT 'staff';

-- 2. 決裁の表
CREATE TABLE `approval_companies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `fiscal_start_month` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '期の始まりの月（1〜12）',
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_approval_companies_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_departments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `short_name` VARCHAR(6) NOT NULL COMMENT 'データ印の上段',
  `code` VARCHAR(3) NOT NULL COMMENT '英大文字 1〜3 文字',
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_departments_code` (`code`),
  UNIQUE KEY `uq_approval_departments_company_name` (`company_id`, `name`),
  CONSTRAINT `fk_approval_departments_company` FOREIGN KEY (`company_id`) REFERENCES `approval_companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_department_user` (
  `department_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`department_id`, `user_id`),
  KEY `idx_approval_dept_user_user` (`user_id`),
  CONSTRAINT `fk_approval_dept_user_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_dept_user_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `can_view_all` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '全件閲覧者',
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '決裁の管理者',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_approval_members_user` (`user_id`),
  CONSTRAINT `fk_approval_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `president_user_id` BIGINT UNSIGNED NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_settings_president` (`president_user_id`),
  CONSTRAINT `fk_approval_settings_president` FOREIGN KEY (`president_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_mail_domains` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_approval_mail_domains_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_setting_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_logs_target` (`target_type`, `target_id`),
  KEY `idx_approval_logs_actor` (`actor_user_id`),
  KEY `idx_approval_logs_created` (`created_at`),
  CONSTRAINT `fk_approval_logs_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 最初のデータ（要件 6.2 の 3 社。名前と月は画面で直せる）
INSERT INTO `approval_settings` (`id`, `president_user_id`, `updated_at`) VALUES (1, NULL, NOW());

INSERT INTO `approval_companies` (`name`, `fiscal_start_month`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('ミツワ都市開発', 5, 1, NOW(), NOW()),
  ('DAD', 6, 2, NOW(), NOW()),
  ('ZEAL', 6, 3, NOW(), NOW());
