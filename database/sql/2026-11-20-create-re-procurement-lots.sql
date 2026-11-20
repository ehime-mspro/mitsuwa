-- 土地（旧「仲介土地」）の仕入れ案件の区画 — 2026-11-20
--
-- 設計書: docs/superpowers/specs/2026-11-20-realestate-land-lot-design.md §6
--
-- ⚠ tests/Concerns/CreatesRealEstateSchema.php と対で維持すること（あちらは SQLite のテストのための鏡。
--   ProcurementLotModelTest::test_the_test_schema_matches_the_production_sql が列を突き合わせる）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは仕入れ案件の詳細・削除で re_procurement_lots を読み、
--   分譲地の保存で re_projects.source_procurement_code を読み書きするので、コードを先に送ると 500 になる。
--
-- 適用: php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「re_procurement_lots か re_projects.source_procurement_code があれば 1 文も流さずに止まる」確認をする。
--   ⚠ 1 → 2 の順に流す。1 文目だけ入って 2 文目で止まったときは、先頭の確かめを飛ばして 2 文目だけを流す（1 文目をもう一度流さない）。

-- 1. 土地の仕入れ案件の区画（1 案件に 1 区画まで）
CREATE TABLE `re_procurement_lots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_id` BIGINT UNSIGNED NOT NULL COMMENT '仕入れ案件（土地。1 案件に 1 区画まで）',
  `lot_number` INT NOT NULL COMMENT '号地番号',
  `area_sqm` DECIMAL(10,2) NOT NULL COMMENT '面積（㎡）。坪数は保存しない',
  `selling_price` INT NULL COMMENT '販売価格。坪単価は保存しない',
  `status` VARCHAR(30) NOT NULL COMMENT 'LotStatus',
  `notes` VARCHAR(200) NULL COMMENT '備考',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_procurement_lots_procurement` (`procurement_id`),
  CONSTRAINT `fk_re_procurement_lots_procurement` FOREIGN KEY (`procurement_id`) REFERENCES `re_procurements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 土地から変えた分譲地の元の仕入れ案件番号
ALTER TABLE `re_projects`
  ADD COLUMN `source_procurement_code` VARCHAR(20) NULL COMMENT '土地から変えた分譲地の元の仕入れ案件番号' AFTER `project_code`,
  ADD UNIQUE KEY `uq_re_projects_source_procurement_code` (`source_procurement_code`);
