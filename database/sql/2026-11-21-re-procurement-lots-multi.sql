-- 土地の仕入れ案件に区画を何区画でも登録する — 2026-11-21
--
-- 設計書: docs/superpowers/specs/2026-11-21-realestate-land-multi-lot-design.md
--
-- ⚠ tests/Concerns/CreatesRealEstateSchema.php と対で維持すること（あちらは SQLite のテストのための鏡。
--   ProcurementLotModelTest::test_the_test_schema_matches_the_multi_lot_sql が列と索引を突き合わせる）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは re_contracts.procurement_lot_id・
--   hs_properties.re_procurement_lot_id・hs_custom_orders.re_procurement_lot_id を読み書きするので、コードを先に送ると 500 になる。
--
-- 適用: php artisan tinker --execute で DB::statement() に **1 文ずつ**流す（6 文）。
--   先頭で「re_contracts.procurement_lot_id があれば 1 文も流さずに止まる」確認をする。
--   ⚠ 1 → 2 → 3 → 4 → 5 → 6 の順に流す。1 文目より前に 2 文目を流さない
--     （外部キー fk_re_procurement_lots_procurement が一意の索引 uq_re_procurement_lots_procurement を使っているので、
--      先にふつうの索引を足してから一意の索引を外す。順が逆だと MySQL が error 1553 で断る）。
--   ⚠ 途中の文で止まったときは、先頭の確かめを飛ばして、止まった文から続きを流す（入った文をもう一度流さない。
--     例: 1 文目だけ入って止まったら、2 文目から流す）。流したあとは SHOW CREATE TABLE で 4 つの表を確かめる。

-- 1. 区画の仕入れ案件の索引を、一意でないふつうの索引にする（1 案件に何区画でも）
ALTER TABLE `re_procurement_lots`
  ADD INDEX `idx_re_procurement_lots_procurement` (`procurement_id`);

-- 2. 一意の索引を外す（1 の後）
ALTER TABLE `re_procurement_lots`
  DROP INDEX `uq_re_procurement_lots_procurement`;

-- 3. 列のコメントを直す
ALTER TABLE `re_procurement_lots`
  MODIFY `procurement_id` BIGINT UNSIGNED NOT NULL COMMENT '仕入れ案件（土地。1 案件に何区画でも）';

-- 4. 不動産の契約に区画の列（1 つの区画に付けられる契約は 1 件だけ）
ALTER TABLE `re_contracts`
  ADD COLUMN `procurement_lot_id` BIGINT UNSIGNED NULL COMMENT '土地の仕入れ案件の区画' AFTER `lot_id`,
  ADD UNIQUE KEY `uq_re_contracts_procurement_lot` (`procurement_lot_id`),
  ADD CONSTRAINT `fk_re_contracts_procurement_lot` FOREIGN KEY (`procurement_lot_id`) REFERENCES `re_procurement_lots` (`id`) ON DELETE RESTRICT;

-- 5. 建売物件に区画の列
ALTER TABLE `hs_properties`
  ADD COLUMN `re_procurement_lot_id` BIGINT UNSIGNED NULL COMMENT '土地の仕入れ案件の区画' AFTER `re_procurement_id`,
  ADD KEY `idx_hs_properties_re_procurement_lot` (`re_procurement_lot_id`),
  ADD CONSTRAINT `fk_hs_properties_re_procurement_lot` FOREIGN KEY (`re_procurement_lot_id`) REFERENCES `re_procurement_lots` (`id`) ON DELETE RESTRICT;

-- 6. 注文住宅に区画の列
ALTER TABLE `hs_custom_orders`
  ADD COLUMN `re_procurement_lot_id` BIGINT UNSIGNED NULL COMMENT '土地の仕入れ案件の区画' AFTER `re_procurement_id`,
  ADD KEY `idx_hs_custom_orders_re_procurement_lot` (`re_procurement_lot_id`),
  ADD CONSTRAINT `fk_hs_custom_orders_re_procurement_lot` FOREIGN KEY (`re_procurement_lot_id`) REFERENCES `re_procurement_lots` (`id`) ON DELETE RESTRICT;
