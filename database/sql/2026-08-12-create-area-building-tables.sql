-- 周辺ビル調査（テナント管理）— 2026-08-12
--
-- ⚠ database/migrations/2026_08_12_000001_create_area_building_tables.php と対で維持すること。
--   片方だけ直すと SQLite テストだけが落ちる drift になる
--   （AreaBuildingSchemaTest::test_raw_sql_and_migration_declare_the_same_columns が拾う）。
--
-- 適用: sudo mysql manage < database/sql/2026-08-12-create-area-building-tables.sql
--   CREATE TABLE IF NOT EXISTS なので、途中で失敗しても再実行して安全。
--
-- 代替: php artisan tinker --execute="DB::unprepared(file_get_contents('database/sql/2026-08-12-create-area-building-tables.sql'));"
--   ⚠ このファイルは CREATE TABLE を 3 本含むため、PDO::exec() のマルチステートメント
--   挙動に依存する。この方式はこのリポジトリに前例が無い（database/sql/ の他ファイルで
--   tinker + DB::unprepared() を案内しているのは単一ステートメントのみ。複数テーブルの
--   ファイルは create_mansion_tables.sql 等すべて sudo mysql の直接実行を案内している）。

CREATE TABLE IF NOT EXISTS area_buildings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'ビル名',
    address VARCHAR(255) NULL COMMENT '所在地',
    latitude DECIMAL(10,7) NULL COMMENT '緯度',
    longitude DECIMAL(10,7) NULL COMMENT '経度',
    total_floors INT NULL COMMENT '総階数',
    notes TEXT NULL COMMENT '備考',
    created_by BIGINT UNSIGNED NULL COMMENT '登録者',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_area_buildings_name (name),
    CONSTRAINT fk_area_buildings_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='周辺ビル（恒久情報）';

CREATE TABLE IF NOT EXISTS area_building_surveys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_building_id BIGINT UNSIGNED NOT NULL,
    surveyed_month DATE NOT NULL COMMENT '調査年月。日は 01 固定',
    operating_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '営業',
    vacant_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '空き',
    unknown_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '不明',
    surveyed_by BIGINT UNSIGNED NULL COMMENT '調査者',
    notes TEXT NULL COMMENT 'その回の所見',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uk_area_survey_building_month (area_building_id, surveyed_month),
    CONSTRAINT fk_area_surveys_building FOREIGN KEY (area_building_id) REFERENCES area_buildings(id) ON DELETE CASCADE,
    CONSTRAINT fk_area_surveys_surveyed_by FOREIGN KEY (surveyed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='周辺ビルの調査回（時点情報）';

CREATE TABLE IF NOT EXISTS area_building_tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_building_id BIGINT UNSIGNED NOT NULL,
    floor INT NULL COMMENT '階。地下は負数（B1 = -1）',
    room_number VARCHAR(50) NULL COMMENT '部屋番号・区画名',
    name VARCHAR(255) NULL COMMENT 'テナント名。空き区画の行では NULL',
    industry VARCHAR(100) NULL COMMENT '業種',
    status VARCHAR(20) NOT NULL COMMENT 'operating / vacant / unknown',
    confirmed_on DATE NULL COMMENT '最終確認日',
    moved_out_on DATE NULL COMMENT '退去日',
    notes TEXT NULL COMMENT '備考',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_area_tenants_building_active (area_building_id, moved_out_on),
    INDEX idx_area_tenants_name (name),
    CONSTRAINT fk_area_tenants_building FOREIGN KEY (area_building_id) REFERENCES area_buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='周辺ビルの入居テナント（現況リスト）';
