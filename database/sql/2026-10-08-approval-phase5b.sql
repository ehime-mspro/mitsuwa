-- 決裁申請 段階5（5b）— 2026-10-08
--
-- 設計書: docs/superpowers/specs/2026-10-07-approval-phase5-design.md §5.3・§5.9〜§5.11・D6・D9・D10
--
-- ⚠ database/migrations/2026_10_08_000001_create_approval_paper_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase5bTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは決裁台帳・部門の管理・関連する決裁No の候補で approval_paper_records を読むので、
--   コードを先に送ると、部門の管理（⑧）が Table doesn't exist で 500 になる（台帳などは使い始める前なので誰も開けないが、順番は守る）。
--
-- 適用: 段階1〜5a と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_paper_imports か approval_paper_records があれば 1 文も流さずに止まる」確認をする（計画 Task 12）。
--   ⚠ approval_paper_records は approval_paper_imports を指すので、**1 → 2 の順**に流す。

-- 1. 過去の紙の決裁の取り込み 1 回分（取り消しの単位。D9）
CREATE TABLE `approval_paper_imports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name` VARCHAR(255) NOT NULL COMMENT '取り込んだファイルの名前',
  `fiscal_year` SMALLINT UNSIGNED NOT NULL COMMENT '年度（期の始まりの年）',
  `added_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '足した記録の数',
  `filled_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '金額を補った、すでにある記録の数',
  `imported_by` BIGINT UNSIGNED NOT NULL,
  `undone_at` TIMESTAMP NULL COMMENT '取り消した日時',
  `undone_by` BIGINT UNSIGNED NULL,
  `undone_count` INT UNSIGNED NULL COMMENT '取り消しで消した記録の数',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_paper_imports_imported_by` (`imported_by`),
  KEY `idx_approval_paper_imports_undone_by` (`undone_by`),
  CONSTRAINT `fk_approval_paper_imports_imported_by` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_imports_undone_by` FOREIGN KEY (`undone_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 取り込んだ過去の紙の決裁（要件 11.2・D10。申請の表とは別に持ち、台帳で合わせて並べる）
CREATE TABLE `approval_paper_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL COMMENT '年度（期の始まりの年）',
  `seq` INT UNSIGNED NOT NULL COMMENT '受付簿のＮＯ',
  `number` VARCHAR(20) NOT NULL COMMENT '決裁No（年度・部門・ＮＯから組み立てる。R8-J-021）',
  `decided_on` DATE NOT NULL COMMENT '決裁日（受付簿の日付）',
  `applicant_name` VARCHAR(50) NULL COMMENT '申請者（受付簿の文字のまま。利用者と結び付けない）',
  `subject` VARCHAR(100) NOT NULL COMMENT '件名',
  `amount` BIGINT NULL COMMENT '金額（金額の控えの契約金額）',
  `cost` BIGINT NULL COMMENT '工事原価',
  `profit` BIGINT NULL COMMENT '粗利益',
  `note` TEXT NULL COMMENT '備考',
  `import_id` BIGINT UNSIGNED NULL COMMENT '足した取り込み（手で登録したものは空）',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `updated_by` BIGINT UNSIGNED NULL COMMENT '手で直した人（取り込みで金額を補っても変えない）',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_paper_records_number` (`number`),
  UNIQUE KEY `uq_approval_paper_records_seq` (`department_id`, `fiscal_year`, `seq`),
  KEY `idx_approval_paper_records_decided` (`decided_on`),
  KEY `idx_approval_paper_records_import` (`import_id`),
  KEY `idx_approval_paper_records_created_by` (`created_by`),
  KEY `idx_approval_paper_records_updated_by` (`updated_by`),
  CONSTRAINT `fk_approval_paper_records_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_records_import` FOREIGN KEY (`import_id`) REFERENCES `approval_paper_imports` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_records_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_records_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
