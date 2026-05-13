-- ZEAL 試算表セルテーブルに予算額列を追加
-- Phase 7+: 予算機能 + 未確定月の予測表示
--
-- 既存環境向け ALTER TABLE。新規セットアップは create_zeal_simulation_tables.sql を参照。
-- 実行:
--   ローカル:  sudo mysql manage < database/sql/alter_zeal_simulation_values_add_budget_amount.sql
--   本番:      phpMyAdmin で本 SQL を直接実行

ALTER TABLE `zeal_simulation_values`
  ADD COLUMN `budget_amount` BIGINT NULL AFTER `amount`
  COMMENT '予算額（負数可、null=未入力）。実績と独立管理';

-- 検証: 列が追加されたか確認
-- DESCRIBE `zeal_simulation_values`;
-- SELECT COUNT(*) AS total, SUM(CASE WHEN budget_amount IS NULL THEN 1 ELSE 0 END) AS null_budgets FROM zeal_simulation_values;
