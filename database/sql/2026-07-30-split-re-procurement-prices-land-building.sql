-- re_procurements: 査定・購入・想定販売の 3 金額を土地/建物に分割し、消費税率を持たせる
--
-- 方針: 合計カラムを _land に CHANGE（リネーム）して廃止し、_building を追加する。
--       リネームなので既存値はそのまま土地側に残る ＝「既存データは全額を土地に寄せる」が自動成立。
--       合計は派生カラムを持たず ReProcurement のメソッドで都度算出する（Bug #34 の stale 化回避）。
--
-- ⚠ re_projects（分譲地PJ）は同名の 3 カラムを持つが**対象外**。分譲地は土地のみの取引。
--
-- 適用（ローカル）: main repo の cwd で
--   php artisan tinker --execute="DB::unprepared(file_get_contents('database/sql/2026-07-30-split-re-procurement-prices-land-building.sql'));"
--   （sudo mysql は非対話でパスワードを渡せない）
-- 適用（本番）    : 実装プランの Task 9 の手順。要ユーザー明示承認
-- ロールバック    : 逆向きの CHANGE + DROP COLUMN（データは失われない）

ALTER TABLE `re_procurements`
  CHANGE `assessment_price`     `assessment_price_land`     INT NULL,
  CHANGE `purchase_price`       `purchase_price_land`       INT NULL,
  CHANGE `target_selling_price` `target_selling_price_land` INT NULL,
  ADD COLUMN `assessment_price_building`     INT NULL COMMENT '査定価格の建物分（円・税抜）'     AFTER `assessment_price_land`,
  ADD COLUMN `purchase_price_building`       INT NULL COMMENT '購入価格の建物分（円・税抜）'     AFTER `purchase_price_land`,
  ADD COLUMN `target_selling_price_building` INT NULL COMMENT '想定販売価格の建物分（円・税抜）' AFTER `target_selling_price_land`,
  ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT '消費税率（%）。案件ごとのスナップショット' AFTER `target_selling_price_building`;
