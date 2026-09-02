-- 住宅事業: 「実際の完成日」を「着工予定日」へ付け替える（設計書 §5.1）
-- 実行: sudo mysql manage < database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql
--
-- ⚠ 実行前に本番を実測したところ、両テーブルとも actual_completion_date は
--    全行 NULL だった（建売 7 件 / 注文住宅 2 件。2026-09-02）。よってデータの移行は不要。
-- ⚠ このファイルと tests/Concerns/CreatesRealEstateSchema.php は対で維持する。
--    片方だけ直すと SQLite テストと本番が黙って drift する。
-- ⚠ **DB が先・deploy.sh が後。** 列が無い DB に新しいコードを乗せると住宅の画面が 500 する。

ALTER TABLE `hs_properties`
  CHANGE COLUMN `actual_completion_date` `construction_start_date` DATE NULL DEFAULT NULL;

ALTER TABLE `hs_custom_orders`
  CHANGE COLUMN `actual_completion_date` `construction_start_date` DATE NULL DEFAULT NULL;
