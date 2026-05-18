-- ZEAL 本部 Google Sheets 取り込み履歴テーブル
-- 実行: sudo mysql manage < database/sql/create_zeal_sheet_imports_table.sql
--
-- 「いつ・どの試算表に・どの月の・どのソース (売上/経費) を取り込んだか」
-- および取得した CSV 原文 / パース後の構造化データを保存する。後から
-- 監査・再パース可能にするための履歴テーブル。

CREATE TABLE IF NOT EXISTS `zeal_sheet_imports` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `simulation_id` BIGINT UNSIGNED NOT NULL COMMENT '対象試算表 ID',
  `import_type`   ENUM('sales','expense') NOT NULL COMMENT '取り込み種別: sales=売上清算書 / expense=運営費請求根拠',
  `year_month`    CHAR(7)         NOT NULL COMMENT 'YYYY-MM 形式 — 取り込み対象月',
  `raw_csv`       MEDIUMTEXT      NULL COMMENT '取得した CSV 原文 (監査用)',
  `parsed_data`   JSON            NULL COMMENT 'パース後の構造化データ',
  `imported_by`   BIGINT UNSIGNED NULL COMMENT '取り込み実施ユーザー',
  `created_at`    TIMESTAMP NULL DEFAULT NULL,
  KEY `idx_zeal_sheet_imports_sim_month` (`simulation_id`, `year_month`),
  KEY `idx_zeal_sheet_imports_type` (`import_type`),
  CONSTRAINT `fk_zeal_sheet_imports_sim`
    FOREIGN KEY (`simulation_id`) REFERENCES `zeal_simulations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL 本部 Sheet 取り込み履歴';
