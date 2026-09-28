-- 決裁申請 段階2（2a）— 2026-09-25
--
-- 設計書: docs/superpowers/specs/2026-09-25-approval-phase2-design.md §5.3
--
-- ⚠ database/migrations/2026_09_25_000001_create_approval_phase2_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_departments.head_user_id と
--   approval_settings.launched_at を読むので、コードを先に送ると部門の管理と決裁のホームが
--   Unknown column で 500 になる。
--
-- ⚠ 索引名・外部キー名は段階1（2026-09-16-approval-phase1.sql）と同じ流儀
--   （一意は uq_・索引は idx_・外部キーは fk_）。照合順序は utf8mb4_unicode_ci。
--
-- ⚠ 状態などの値は ENUM にせず VARCHAR（設計書 §5.3。値を足すたびの ALTER と、
--   SQLite で enum を変えると CHECK が消える落とし穴（Bug #60）を避ける）。
--
-- ⚠ approval_requests.body は MEDIUMTEXT。本文の上限は 20000 文字だが、TEXT は 65535 バイトまでなので
--   4 バイトの文字（絵文字・𠮷 など）だと入りきらず、本番だけ 1406 で落ちる（SQLite のテストでは見えない）。
--
-- 適用: 段階1 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_types がすでにあれば 1 文も流さずに止まる」確認をする（計画 Task 21）。

-- 1. 既存の表に列を足す
ALTER TABLE `approval_departments`
  ADD COLUMN `head_user_id` BIGINT UNSIGNED NULL COMMENT '部門長' AFTER `code`,
  ADD KEY `idx_approval_departments_head` (`head_user_id`),
  ADD CONSTRAINT `fk_approval_departments_head` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`id`);

ALTER TABLE `approval_settings`
  ADD COLUMN `launched_at` TIMESTAMP NULL COMMENT '使い始めた日時（NULL のあいだは準備中）' AFTER `president_user_id`;

-- 2. 新しい表
CREATE TABLE `approval_reviewers` (
  `department_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`department_id`, `user_id`),
  KEY `idx_approval_reviewers_user` (`user_id`),
  CONSTRAINT `fk_approval_reviewers_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_reviewers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `headings` TEXT NOT NULL COMMENT '5W2H の見出し（本文の初期値）',
  `review_department_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_types_name` (`name`),
  KEY `idx_approval_types_review_dept` (`review_department_id`),
  CONSTRAINT `fk_approval_types_review_dept` FOREIGN KEY (`review_department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '申請者',
  `department_id` BIGINT UNSIGNED NULL COMMENT '申請部門（下書きでは空でもよい）',
  `type_id` BIGINT UNSIGNED NULL COMMENT '申請の種類（下書きでは空でもよい）',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `decision` VARCHAR(20) NULL COMMENT '社長の判断（approve / conditional / reject）',
  `subject` VARCHAR(100) NULL,
  `amount` BIGINT UNSIGNED NULL COMMENT '円・税抜',
  `schedule` VARCHAR(50) NULL COMMENT '実施時期',
  `body` MEDIUMTEXT NULL COMMENT '重点ポイント（5W2H）',
  `related_numbers` JSON NULL COMMENT '関連する決裁No（10 個まで）',
  `round` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提出の回数',
  `number` VARCHAR(20) NULL COMMENT '決裁No（R8-J-001）',
  `number_department_id` BIGINT UNSIGNED NULL,
  `number_fiscal_year` SMALLINT UNSIGNED NULL COMMENT '期の始まりの年',
  `number_seq` INT UNSIGNED NULL,
  `first_submitted_at` TIMESTAMP NULL,
  `last_submitted_at` TIMESTAMP NULL COMMENT '発信日',
  `decided_at` TIMESTAMP NULL COMMENT '決裁日',
  `finished_at` TIMESTAMP NULL COMMENT '決裁が完了した日時（可・否・条件確認のあと）',
  `status_changed_at` TIMESTAMP NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '同時操作の見張り',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_requests_number` (`number`),
  KEY `idx_approval_requests_user_status` (`user_id`, `status`),
  KEY `idx_approval_requests_dept_status` (`department_id`, `status`),
  KEY `idx_approval_requests_type` (`type_id`),
  KEY `idx_approval_requests_status` (`status`),
  CONSTRAINT `fk_approval_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_approval_requests_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_requests_type` FOREIGN KEY (`type_id`) REFERENCES `approval_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_requests_number_dept` FOREIGN KEY (`number_department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_steps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `round` SMALLINT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL COMMENT 'head / review / president',
  `department_id` BIGINT UNSIGNED NULL COMMENT '部門長の段階は申請部門・審査の段階は審査部門の控え',
  `assignee_user_id` BIGINT UNSIGNED NULL COMMENT '付け替えた担当（NULL なら設定から引く）',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending / waiting / done / skipped / cancelled',
  `arrived_at` TIMESTAMP NULL,
  `acted_at` TIMESTAMP NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `result` VARCHAR(20) NULL,
  `comment` TEXT NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_steps_request_round` (`request_id`, `round`),
  KEY `idx_approval_steps_kind_status` (`kind`, `status`),
  KEY `idx_approval_steps_dept` (`department_id`),
  KEY `idx_approval_steps_assignee` (`assignee_user_id`),
  KEY `idx_approval_steps_actor` (`actor_user_id`),
  CONSTRAINT `fk_approval_steps_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_steps_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_steps_assignee` FOREIGN KEY (`assignee_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_approval_steps_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `round` SMALLINT UNSIGNED NOT NULL,
  `snapshot` JSON NOT NULL COMMENT '提出したときの中身をまるごと',
  `submitted_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_revisions_request_round` (`request_id`, `round`),
  CONSTRAINT `fk_approval_revisions_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_revisions_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `round` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `actor_user_id` BIGINT UNSIGNED NULL COMMENT '省略は NULL・部門長の交代は変えた管理者',
  `action` VARCHAR(40) NOT NULL,
  `from_status` VARCHAR(20) NULL,
  `to_status` VARCHAR(20) NULL,
  `step_id` BIGINT UNSIGNED NULL,
  `result` VARCHAR(20) NULL,
  `comment` TEXT NULL,
  `reason` TEXT NULL COMMENT '管理者の操作の理由（2b）',
  `meta` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_histories_request` (`request_id`, `id`),
  KEY `idx_approval_histories_actor` (`actor_user_id`),
  CONSTRAINT `fk_approval_histories_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_histories_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_approval_histories_step` FOREIGN KEY (`step_id`) REFERENCES `approval_steps` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(255) NOT NULL COMMENT 'local ディスクの approvals/{申請}/{無作為}.{拡張子}',
  `mime` VARCHAR(100) NOT NULL,
  `size` INT UNSIGNED NOT NULL,
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  `added_round` SMALLINT UNSIGNED NOT NULL COMMENT 'この添付が初めて入る提出の回',
  `removed_round` SMALLINT UNSIGNED NULL,
  `removed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_attachments_path` (`stored_path`),
  KEY `idx_approval_attachments_request` (`request_id`),
  CONSTRAINT `fk_approval_attachments_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_download_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `attachment_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL COMMENT 'attachment（段階4 で pdf / excel）',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_download_logs_request` (`request_id`),
  KEY `idx_approval_download_logs_user` (`user_id`),
  CONSTRAINT `fk_approval_download_logs_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_download_logs_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `approval_attachments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_download_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_number_sequences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL COMMENT '期の始まりの年',
  `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_issued` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_number_sequences` (`department_id`, `fiscal_year`),
  CONSTRAINT `fk_approval_number_sequences_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
