-- ============================================================================
-- settings テーブル作成 + 初期データ投入
-- ----------------------------------------------------------------------------
-- システム全体で参照する key/value 形式のシンプルな設定ストア。
-- ZEAL モジュールの消費税率（tax_rate）参照のために導入。
-- 既存環境向けに IF NOT EXISTS / ON DUPLICATE KEY UPDATE で冪等化。
--
-- 実行方法（macOS）:
--   sudo mysql masa8787kanri63732 < database/sql/create_settings_table.sql
--   ※ 接続先 DB 名は config/database.php の DB_DATABASE を参照
-- ============================================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`        VARCHAR(100)    NOT NULL COMMENT '設定キー（例: tax_rate）',
    `value`      TEXT            NULL     COMMENT '設定値（数値も文字列で保持）',
    `created_at` TIMESTAMP       NULL     DEFAULT NULL,
    `updated_at` TIMESTAMP       NULL     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='システム共通設定 (key/value)';

-- 初期値: 消費税率 10%
INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('tax_rate', '10', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `value`      = VALUES(`value`),
    `updated_at` = VALUES(`updated_at`);
