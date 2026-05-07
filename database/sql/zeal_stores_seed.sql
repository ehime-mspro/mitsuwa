-- ZEAL 店舗マスタ 初期データ
-- 実行: sudo mysql manage < database/sql/zeal_stores_seed.sql
-- ※ 事前に create_zeal_tables.sql を実行済みであること
-- 冪等性: name で重複チェックしてから INSERT

INSERT INTO `zeal_stores`
  (`name`, `address`, `phone`, `open_date`,
   `display_order`, `active`,
   `created_at`, `updated_at`)
SELECT
  'ZEAL BOXING FITNESS 松山市駅前店',
  '愛媛県松山市湊町6-2-2 ミツワ市駅西ビル2階',
  NULL,
  '2025-10-17',
  1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `zeal_stores`
  WHERE `name` = 'ZEAL BOXING FITNESS 松山市駅前店'
);
