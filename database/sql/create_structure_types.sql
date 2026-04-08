-- 構造マスターテーブル作成
CREATE TABLE IF NOT EXISTS `structure_types` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '構造名',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '表示順',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='構造マスター';

-- 初期データ投入（既存の選択肢をそのまま登録）
INSERT INTO `structure_types` (`name`, `sort_order`, `created_at`, `updated_at`) VALUES
('RC造', 1, NOW(), NOW()),
('S造', 2, NOW(), NOW()),
('SRC造', 3, NOW(), NOW()),
('木造', 4, NOW(), NOW()),
('その他', 5, NOW(), NOW());
