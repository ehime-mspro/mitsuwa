-- 用途地域マスターテーブル作成
CREATE TABLE IF NOT EXISTS zoning_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '用途地域名',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期データ（都市計画法の13用途地域）
INSERT INTO zoning_types (name, sort_order, created_at, updated_at) VALUES
('第一種低層住居専用地域',   1, NOW(), NOW()),
('第二種低層住居専用地域',   2, NOW(), NOW()),
('第一種中高層住居専用地域', 3, NOW(), NOW()),
('第二種中高層住居専用地域', 4, NOW(), NOW()),
('第一種住居地域',           5, NOW(), NOW()),
('第二種住居地域',           6, NOW(), NOW()),
('準住居地域',               7, NOW(), NOW()),
('田園住居地域',             8, NOW(), NOW()),
('近隣商業地域',             9, NOW(), NOW()),
('商業地域',                10, NOW(), NOW()),
('準工業地域',              11, NOW(), NOW()),
('工業地域',                12, NOW(), NOW()),
('工業専用地域',            13, NOW(), NOW());
