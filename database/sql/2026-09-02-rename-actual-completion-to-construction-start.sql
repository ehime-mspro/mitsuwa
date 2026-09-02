-- 住宅事業: 「実際の完成日」を「着工予定日」へ付け替える（設計書 §5.1）
--
-- 実行: sudo mysql は非対話でパスワードを渡せないため使えない。main repo の cwd で
--   1 呼び出し 1 ALTER として tinker に流す（多重ステートメントは
--   PDO::MYSQL_ATTR_MULTI_STATEMENTS 未設定のため保証されない。同種の DDL は既に
--   tinker 案内へ改められている: database/sql/2026-09-01-add-source-to-schedule-steps.sql）:
--     php artisan tinker --execute="DB::statement('ALTER TABLE hs_properties CHANGE COLUMN actual_completion_date construction_start_date DATE NULL DEFAULT NULL');"
--     php artisan tinker --execute="DB::statement('ALTER TABLE hs_custom_orders CHANGE COLUMN actual_completion_date construction_start_date DATE NULL DEFAULT NULL');"
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

-- ロールバック（データは失われない。全行 NULL のため）:
--   ALTER TABLE `hs_properties`    CHANGE COLUMN `construction_start_date` `actual_completion_date` DATE NULL DEFAULT NULL;
--   ALTER TABLE `hs_custom_orders` CHANGE COLUMN `construction_start_date` `actual_completion_date` DATE NULL DEFAULT NULL;
