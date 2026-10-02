-- 決裁申請 段階3（3b）— 2026-10-02
--
-- 設計書: docs/superpowers/specs/2026-09-30-approval-phase3-design.md §5.3・§5.8〜§5.10
--
-- ⚠ database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase3bTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_holidays と approval_reminder_runs を読むので、
--   コードを先に送ると、催促の設定（⑫）の画面と朝の催促のコマンドが Unknown table で止まる。
--
-- ⚠ 索引名は段階1・2・3a と同じ流儀（一意は uq_）。照合順序は utf8mb4_unicode_ci。
--
-- 適用: 3a と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_holidays か approval_reminder_runs がすでにあれば 1 文も流さずに止まる」確認をする（計画 Task 9）。

-- 1. 催促を送らない日（⑫・要件 8.3。毎年繰り返すものは月と日だけで比べる。D19）
CREATE TABLE `approval_holidays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `start_date` DATE NOT NULL COMMENT '開始日',
  `end_date` DATE NOT NULL COMMENT '終了日（開始日と同じか後）',
  `repeats_yearly` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '毎年繰り返すか（1 なら月と日だけで比べる）',
  `description` VARCHAR(50) NOT NULL COMMENT '説明（例: 年末年始）',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 催促を送った日（1 日 1 行。一意の日付で、同じ日に 2 回送らない。⑫ の「前回の催促」にも使う）
CREATE TABLE `approval_reminder_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sent_on` DATE NOT NULL COMMENT '催促を送った日（日本の日付）',
  `recipient_count` INT UNSIGNED NOT NULL COMMENT '催促のメールを送った人数',
  `item_count` INT UNSIGNED NOT NULL COMMENT '催促した申請の件数（のべ。1 通に載せきれなかった分も数える）',
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_reminder_runs_sent_on` (`sent_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
