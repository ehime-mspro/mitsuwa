-- =====================================================
-- 区画テーブルの用途をマスター連動に変更
-- 実行前にphpMyAdminでinquiry_usage_typesの現在のsort_order最大値を確認
-- =====================================================

-- 1. マスターに不足分を追加（既存レコードはスキップ）
SET @max_sort = IFNULL((SELECT MAX(sort_order) FROM inquiry_usage_types), 0);

INSERT INTO inquiry_usage_types (name, sort_order, created_at, updated_at)
SELECT '店舗', @max_sort + 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inquiry_usage_types WHERE name = '店舗');

INSERT INTO inquiry_usage_types (name, sort_order, created_at, updated_at)
SELECT '倉庫', @max_sort + 2, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inquiry_usage_types WHERE name = '倉庫');

INSERT INTO inquiry_usage_types (name, sort_order, created_at, updated_at)
SELECT '事務所', @max_sort + 3, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inquiry_usage_types WHERE name = '事務所');

INSERT INTO inquiry_usage_types (name, sort_order, created_at, updated_at)
SELECT 'その他', @max_sort + 4, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inquiry_usage_types WHERE name = 'その他');

-- 2. unitsテーブルにusage_type_idカラム追加
ALTER TABLE units ADD COLUMN usage_type_id BIGINT UNSIGNED NULL AFTER usage_type;
ALTER TABLE units ADD CONSTRAINT fk_units_usage_type FOREIGN KEY (usage_type_id) REFERENCES inquiry_usage_types(id) ON DELETE SET NULL;

-- 3. 既存EnumデータをマスターIDにマッピング
UPDATE units u JOIN inquiry_usage_types iut ON iut.name = '店舗' SET u.usage_type_id = iut.id WHERE u.usage_type = 'shop';
UPDATE units u JOIN inquiry_usage_types iut ON iut.name = '倉庫' SET u.usage_type_id = iut.id WHERE u.usage_type = 'warehouse';
UPDATE units u JOIN inquiry_usage_types iut ON iut.name = '事務所' SET u.usage_type_id = iut.id WHERE u.usage_type = 'office';
UPDATE units u JOIN inquiry_usage_types iut ON iut.name = 'その他' SET u.usage_type_id = iut.id WHERE u.usage_type = 'other';

-- 4. 旧Enumカラム削除
ALTER TABLE units DROP COLUMN usage_type;
