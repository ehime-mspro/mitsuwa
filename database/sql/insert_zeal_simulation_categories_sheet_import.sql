-- 本部 Google Sheets 連携で新規に必要となる項目マスタ 2 件を追加
-- 実行: sudo mysql manage < database/sql/insert_zeal_simulation_categories_sheet_import.sql
--
-- 既存項目マスタ (create_zeal_simulation_tables.sql) に以下が既存:
--   - royalty           ロイヤリティ (revenue_linked, 3.0%)
--   - payment_fee       決済手数料   (revenue_linked, 3.5%)  ※PDF は 3.3%、差分は preview で警告
--   - training_system   研修システム  (fixed, 15000)         ※PDF は 16500、差分は preview で警告
--   - web_operation     web運用      (fixed, 15000)         ※PDF は 16500、差分は preview で警告
--   - outsourcing       委託費        (fixed, 400000)        ※PDF は店舗運営委託費 440000、差分は preview で警告
--
-- 不足: session_fee / store_supplies の 2 件のみ新規追加。
-- INSERT IGNORE で既に存在する場合は skip (二重実行安全)。

INSERT IGNORE INTO `zeal_simulation_categories`
  (`code`, `name`, `group_type`, `calc_type`, `default_amount`, `rate_percent`, `sort_order`, `is_system`, `is_active`, `created_at`, `updated_at`)
VALUES
  ('session_fee',     '時間帯業務委託費', 'expense', 'manual', NULL, NULL, 65,  0, 1, NOW(), NOW()),
  ('store_supplies',  '店舗備品費',       'expense', 'manual', NULL, NULL, 145, 0, 1, NOW(), NOW());
