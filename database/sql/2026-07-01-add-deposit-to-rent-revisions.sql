-- 賃料改定に「敷金」を追加（契約=rent_revisions / 区画募集=unit_rent_revisions）
-- 敷金は金額のみ記録（月数はUI補助のため非保存）。既存カラムへの変更は無し・追加のみ。
-- 適用（ローカル）: sudo mysql manage < database/sql/2026-07-01-add-deposit-to-rent-revisions.sql
-- 適用（本番）    : 本番DBに対し同SQLを実行（要ユーザー承認）

ALTER TABLE `rent_revisions`
  ADD COLUMN `old_deposit` INT NULL COMMENT '旧敷金（円）' AFTER `new_pest_control_fee`,
  ADD COLUMN `new_deposit` INT NULL COMMENT '新敷金（円）' AFTER `old_deposit`;

ALTER TABLE `unit_rent_revisions`
  ADD COLUMN `old_deposit` INT NULL COMMENT '旧募集敷金（円）' AFTER `new_pest_control_fee`,
  ADD COLUMN `new_deposit` INT NULL COMMENT '新募集敷金（円）' AFTER `old_deposit`;
