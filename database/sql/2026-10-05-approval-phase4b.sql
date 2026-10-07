-- 決裁申請 段階4（4b）— 2026-10-05
--
-- 設計書: docs/superpowers/specs/2026-10-03-approval-phase4-design.md §5.3・§5.7・D25
--
-- ⚠ database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase4bTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは Excel の出力を、申請の欄が空の行と filters・request_count で記録するので、
--   コードを先に送ると、台帳の Excel の出力が 500 になる（使い始める前は誰も開けないが、順番は守る）。
--
-- 適用: 段階1〜4a と同じく php artisan tinker --execute で DB::statement() に流す（1 文）。
--   先頭で「approval_download_logs に filters があれば流さずに止まる」確認をする（計画 Task 8）。

-- 出力の記録を Excel にも使える形に（D25。Excel の行は申請の欄が空で、絞り込みの条件と件数を控える）
ALTER TABLE `approval_download_logs`
  MODIFY COLUMN `request_id` BIGINT UNSIGNED NULL COMMENT '添付・PDF の申請（Excel は空）',
  ADD COLUMN `filters` JSON NULL COMMENT 'Excel の絞り込みの条件' AFTER `user_agent`,
  ADD COLUMN `request_count` INT UNSIGNED NULL COMMENT 'Excel に出した件数' AFTER `filters`;
