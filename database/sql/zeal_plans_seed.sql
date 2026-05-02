-- ZEAL プランマスタ 初期データ（v1 シード）
-- 実行: sudo mysql manage < database/sql/zeal_plans_seed.sql
-- ※ 事前に create_zeal_tables.sql を実行済みであること
--
-- 価格はすべて税抜。
-- ペアプラン: 通常 22,770円（税込）→ 20,700円（税抜）
--              キャンペーン 20,700円（税込）→ 18,818円（税抜）
-- キャンペーン期間は運用開始後に phpMyAdmin で設定するため、初期は NULL。

INSERT INTO `zeal_plans`
  (`name`, `regular_price_excl`, `campaign_price_excl`,
   `campaign_starts_on`, `campaign_ends_on`,
   `max_concurrent_reservations`,
   `includes_personal`, `includes_semi_personal`,
   `monthly_session_limit`, `is_pair_plan`,
   `display_order`, `active`,
   `created_at`, `updated_at`)
VALUES
  -- 通い放題 2枠
  ('パーソナル&セミパーソナル通い放題（2枠）',
   24000, 21819, NULL, NULL,
   2, 1, 1, NULL, 0,
   1, 1, NOW(), NOW()),

  -- 通い放題 1枠
  ('パーソナル&セミパーソナル通い放題（1枠）',
   18000, 16364, NULL, NULL,
   1, 1, 1, NULL, 0,
   2, 1, NOW(), NOW()),

  -- 月4回プラン
  ('パーソナル&セミパーソナル月4回',
   13000, 11819, NULL, NULL,
   1, 1, 1, 4, 0,
   3, 1, NOW(), NOW()),

  -- セミパーソナル通い放題
  ('セミパーソナル通い放題',
   9800, 8909, NULL, NULL,
   1, 0, 1, NULL, 0,
   4, 1, NOW(), NOW()),

  -- ペアプラン（同伴者向け。pair_parent_member_id で主契約者と紐付く）
  ('ペアプラン',
   20700, 18818, NULL, NULL,
   1, 1, 1, NULL, 1,
   5, 1, NOW(), NOW());
