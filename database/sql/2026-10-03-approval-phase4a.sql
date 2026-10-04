-- 決裁申請 段階4（4a）— 2026-10-03
--
-- 設計書: docs/superpowers/specs/2026-10-03-approval-phase4-design.md §5.3
--
-- ⚠ database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase4aTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_members.stamp_text と approval_steps.stamp_* を読み書きするので、
--   コードを先に送ると、判断の操作と利用者の管理（⑦）が Unknown column で 500 になる。
--
-- 適用: 段階1〜3 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_members に stamp_text があるか、approval_steps に stamp_label があれば 1 文も流さずに止まる」確認をする（計画 Task 8）。

-- 1. 利用者ごとの「印に使う文字」（D9。空なら氏名の最初の空白より前。D8）
ALTER TABLE `approval_members`
  ADD COLUMN `stamp_text` VARCHAR(4) NULL COMMENT '印に使う文字（空なら氏名の最初の空白より前）' AFTER `is_admin`;

-- 2. 判断したときの印の控え（D3。押したあとで設定を変えても変わらない。取り消しで空に戻す）
ALTER TABLE `approval_steps`
  ADD COLUMN `stamp_label` VARCHAR(6) NULL COMMENT '押したときの印の上段（部門の略称か「社長」）' AFTER `comment`,
  ADD COLUMN `stamp_text` VARCHAR(100) NULL COMMENT '押したときの印の下段（印に使う文字）' AFTER `stamp_label`;
