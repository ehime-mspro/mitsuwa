-- ZEAL 経営試算表（経営シミュレーション）テーブル一式
-- 実行: sudo mysql manage < database/sql/create_zeal_simulation_tables.sql
--
-- 構成:
--   1. zeal_simulation_categories  項目マスター（賃料・委託費・売上等）
--   2. zeal_simulations            試算表ヘッダー（会計年度ごとに 1 件）
--   3. zeal_simulation_values      試算表セル（年月 × 項目 × 金額）
--
-- 会計年度: ZEAL/DAD は 6月始まり（App\Support\ZealFiscalYear）

-- -----------------------------------------------------------------------
-- 1. 項目マスター
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_simulation_categories` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`            VARCHAR(50)  NOT NULL COMMENT 'コード（例: rent, royalty）',
  `name`            VARCHAR(100) NOT NULL COMMENT '表示名（例: 賃料）',
  `group_type`      ENUM('revenue','member','expense','summary') NOT NULL COMMENT 'グループ: 売上/会員数/経費/集計',
  `calc_type`       ENUM('manual','fixed','revenue_linked','calculated') NOT NULL COMMENT '計算タイプ',
  `default_amount`  INT          NULL COMMENT '固定額デフォルト（calc_type=fixed 用）',
  `rate_percent`    DECIMAL(6,3) NULL COMMENT '売上連動率 %（calc_type=revenue_linked 用、例: 3.000）',
  `sort_order`      INT          NOT NULL DEFAULT 0 COMMENT '並び順',
  `is_system`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'システム固定（経費計/営業利益/累計利益）削除不可',
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  `created_at`      TIMESTAMP NULL DEFAULT NULL,
  `updated_at`      TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_zeal_sim_cat_code` (`code`),
  KEY `idx_zeal_sim_cat_active_order` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL 試算表 項目マスター';

-- -----------------------------------------------------------------------
-- 2. 試算表ヘッダー
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_simulations` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year`  SMALLINT     NOT NULL COMMENT '会計年度開始年（例: 2025 = 2025/06〜2026/05）',
  `name`         VARCHAR(100) NULL     COMMENT '任意名（例: 2025年度 経営試算表）',
  `notes`        TEXT         NULL     COMMENT '備考',
  `created_by`   BIGINT UNSIGNED NULL  COMMENT '作成ユーザー',
  `updated_by`   BIGINT UNSIGNED NULL  COMMENT '更新ユーザー',
  `created_at`   TIMESTAMP NULL DEFAULT NULL,
  `updated_at`   TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_zeal_sim_fiscal_year` (`fiscal_year`),
  KEY `idx_zeal_sim_year_desc` (`fiscal_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL 経営試算表ヘッダー';

-- -----------------------------------------------------------------------
-- 3. 試算表セル
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_simulation_values` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `simulation_id`       BIGINT UNSIGNED NOT NULL COMMENT '試算表 ID',
  `category_id`         BIGINT UNSIGNED NOT NULL COMMENT '項目 ID',
  `year_month`          CHAR(7)         NOT NULL COMMENT 'YYYY-MM 形式',
  `amount`              BIGINT          NULL COMMENT '金額（負数可、null=未入力）',
  `is_manual_override`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '実績連動値の手動上書きフラグ',
  `created_at`          TIMESTAMP NULL DEFAULT NULL,
  `updated_at`          TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_zeal_sim_val` (`simulation_id`, `category_id`, `year_month`),
  KEY `idx_zeal_sim_val_sim` (`simulation_id`),
  CONSTRAINT `fk_zeal_sim_val_sim`
    FOREIGN KEY (`simulation_id`) REFERENCES `zeal_simulations`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_zeal_sim_val_cat`
    FOREIGN KEY (`category_id`) REFERENCES `zeal_simulation_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL 試算表セル値';

-- -----------------------------------------------------------------------
-- 4. 初期データ seed（PDF サンプル基準 19 項目）
-- -----------------------------------------------------------------------
INSERT INTO `zeal_simulation_categories`
  (`code`, `name`, `group_type`, `calc_type`, `default_amount`, `rate_percent`, `sort_order`, `is_system`, `is_active`, `created_at`, `updated_at`)
VALUES
  -- 売上・会員
  ('revenue',        '売上',       'revenue', 'manual',         NULL,    NULL,    10, 0, 1, NOW(), NOW()),
  ('member_count',   '会員数',     'member',  'manual',         NULL,    NULL,    20, 0, 1, NOW(), NOW()),
  -- 固定費（PDF の月額デフォルト）
  ('rent',           '賃料',       'expense', 'fixed',          200000,  NULL,    30, 0, 1, NOW(), NOW()),
  ('depreciation',   '減価償却',   'expense', 'manual',         NULL,    NULL,    40, 0, 1, NOW(), NOW()),
  ('outsourcing',    '委託費',     'expense', 'fixed',          400000,  NULL,    50, 0, 1, NOW(), NOW()),
  ('outsourcing_extra','委託費追加','expense', 'manual',         NULL,    NULL,    60, 0, 1, NOW(), NOW()),
  -- 売上連動費
  ('royalty',        'ロイヤリティ', 'expense', 'revenue_linked', NULL,   3.000,   70, 0, 1, NOW(), NOW()),
  -- 固定費（システム関連）
  ('training_system','研修システム','expense', 'fixed',          15000,   NULL,    80, 0, 1, NOW(), NOW()),
  ('web_operation',  'web運用',    'expense', 'fixed',          15000,   NULL,    90, 0, 1, NOW(), NOW()),
  ('management_system','管理システム','expense','fixed',         36000,   NULL,   100, 0, 1, NOW(), NOW()),
  -- 売上連動費
  ('payment_fee',    '決済手数料', 'expense', 'revenue_linked', NULL,    3.500,  110, 0, 1, NOW(), NOW()),
  -- 変動費
  ('advertising',    '広告費',     'expense', 'manual',         NULL,    NULL,   120, 0, 1, NOW(), NOW()),
  ('utilities',      '水道光熱費', 'expense', 'fixed',          35000,   NULL,   130, 0, 1, NOW(), NOW()),
  ('misc',           '雑費',       'expense', 'fixed',          50000,   NULL,   140, 0, 1, NOW(), NOW()),
  ('insurance',      '保険',       'expense', 'manual',         NULL,    NULL,   150, 0, 1, NOW(), NOW()),
  ('other',          'その他',     'expense', 'manual',         NULL,    NULL,   160, 0, 1, NOW(), NOW()),
  -- 集計行（システム固定、削除・グループ変更不可）
  ('expense_total',  '経費計',     'summary', 'calculated',     NULL,    NULL,   200, 1, 1, NOW(), NOW()),
  ('operating_profit','営業利益',  'summary', 'calculated',     NULL,    NULL,   210, 1, 1, NOW(), NOW()),
  ('cumulative_profit','累計利益', 'summary', 'calculated',     NULL,    NULL,   220, 1, 1, NOW(), NOW());
