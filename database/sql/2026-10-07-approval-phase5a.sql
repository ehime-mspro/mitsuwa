-- 決裁申請 段階5（5a）— 2026-10-07
--
-- 設計書: docs/superpowers/specs/2026-10-07-approval-phase5-design.md §5.3
--
-- ⚠ database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase5aTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_types.body_form などと approval_requests.amount_table などを
--   読み書きするので、コードを先に送ると、申請種類の管理（⑨）・申請の画面・詳細・台帳が Unknown column で 500 になる。
--
-- ⚠ 今の種類は本文の形の既定（points＝5W2H の見出し）になるだけ（埋め直す SQL は要らない）。
--
-- 適用: 段階1〜4 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_types に body_form があるか、approval_type_department があれば 1 文も流さずに止まる」確認をする（計画 Task 13）。

-- 1. 申請の種類ごとの作り込み（要件 5.5.1。D4・D12）
ALTER TABLE `approval_types`
  ADD COLUMN `body_form` VARCHAR(20) NOT NULL DEFAULT 'points' COMMENT '本文の形（points＝5W2H の見出し／table＝金額の明細表）' AFTER `headings`,
  ADD COLUMN `table_layout` JSON NULL COMMENT '明細表の行（前半・後半の行の名前。名前が空なら自由行）と「計」の行の有無' AFTER `body_form`,
  ADD COLUMN `subject_suffix` VARCHAR(50) NULL COMMENT '件名の決まり文句' AFTER `table_layout`,
  ADD COLUMN `uses_tsubo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '坪数を使う' AFTER `subject_suffix`,
  ADD COLUMN `uses_tsubo_price` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '坪単価を使う' AFTER `uses_tsubo`,
  ADD COLUMN `uses_staff` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '担当者を使う（使う種類では提出に必須）' AFTER `uses_tsubo_price`,
  ADD COLUMN `uses_contract_date` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '契約予定日を使う（使う種類では提出に必須）' AFTER `uses_staff`,
  ADD COLUMN `fixed_text` TEXT NULL COMMENT '定型文（本文の下に出す固定の文）' AFTER `uses_contract_date`;

-- 2. 種類を使える部門（1 行も無ければ全部門。要件 5.5.1・D13）
CREATE TABLE `approval_type_department` (
  `type_id` BIGINT UNSIGNED NOT NULL,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`type_id`, `department_id`),
  KEY `idx_approval_type_department_dept` (`department_id`),
  CONSTRAINT `fk_approval_type_department_type` FOREIGN KEY (`type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_type_department_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 申請の明細表と追加の入力欄（要件 5.5.4・5.5.5。D5・D14）
ALTER TABLE `approval_requests`
  ADD COLUMN `amount_table` JSON NULL COMMENT '金額の明細表（前半・後半の行と「計」の行の有無。明細表の種類だけ）' AFTER `body`,
  ADD COLUMN `tsubo` DECIMAL(7,2) NULL COMMENT '坪数' AFTER `amount_table`,
  ADD COLUMN `tsubo_price` BIGINT UNSIGNED NULL COMMENT '坪単価（円）' AFTER `tsubo`,
  ADD COLUMN `staff` VARCHAR(50) NULL COMMENT '担当者' AFTER `tsubo_price`,
  ADD COLUMN `contract_date` DATE NULL COMMENT '契約予定日' AFTER `staff`,
  ADD COLUMN `fixed_text` TEXT NULL COMMENT '定型文（保存したときに種類から写す。提出したあとは変わらない）' AFTER `contract_date`;
