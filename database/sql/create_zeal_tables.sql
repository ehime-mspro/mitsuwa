-- ZEAL フィットネス事業 テーブル一式
-- 実行: sudo mysql manage < database/sql/create_zeal_tables.sql
-- ※ 実行前に zeal_plans_seed.sql は不要。seed は別ファイル

-- -----------------------------------------------------------------------
-- 1. 店舗マスタ
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_stores` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100) NOT NULL COMMENT '店舗名',
  `address`       VARCHAR(300) NULL COMMENT '住所',
  `phone`         VARCHAR(20)  NULL COMMENT '電話',
  `open_date`     DATE         NULL COMMENT '開店日',
  `display_order` INT          NOT NULL DEFAULT 0 COMMENT '表示順',
  `active`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  `created_at`    TIMESTAMP    NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP    NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL 店舗マスタ';

-- -----------------------------------------------------------------------
-- 2. プランマスタ
-- マスタは「現状の標準価格 + 現状適用中のキャンペーン情報」のみ保持。
-- 過去キャンペーン履歴は zeal_member_contracts に焼き付けるため不要。
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_plans` (
  `id`                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`                         VARCHAR(100) NOT NULL COMMENT 'プラン名',
  `regular_price_excl`           INT UNSIGNED NOT NULL COMMENT '通常価格（税抜）',
  `campaign_price_excl`          INT UNSIGNED NULL COMMENT 'キャンペーン価格（税抜）',
  `campaign_starts_on`           DATE NULL COMMENT 'キャンペーン適用可能期間 開始日',
  `campaign_ends_on`             DATE NULL COMMENT 'キャンペーン適用可能期間 終了日',
  `max_concurrent_reservations`  INT  NULL COMMENT '同時予約可能数（NULL=月回数制プラン等）',
  `includes_personal`            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'パーソナル含む',
  `includes_semi_personal`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'セミパーソナル含む',
  `monthly_session_limit`        INT NULL COMMENT '月間利用上限（NULL=通い放題、4=月4回）',
  `is_pair_plan`                 TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ペアプランフラグ',
  `display_order`                INT NOT NULL DEFAULT 0 COMMENT '表示順',
  `active`                       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  `created_at`                   TIMESTAMP NULL DEFAULT NULL,
  `updated_at`                   TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL プランマスタ';

-- -----------------------------------------------------------------------
-- 3. トレーナーマスタ
-- セレクトボックス用の最小マスタ。給与・雇用形態等は管理しない。
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_trainers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100) NOT NULL COMMENT 'トレーナー氏名',
  `display_order` INT NOT NULL DEFAULT 0 COMMENT '表示順',
  `active`        TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  `created_at`    TIMESTAMP   NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP   NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL トレーナーマスタ';

-- -----------------------------------------------------------------------
-- 4. 会員マスタ
-- current_plan_id は zeal_member_contracts の最新 open contract をミラーする
-- キャッシュカラム（N+1 回避用）。真実は zeal_member_contracts。
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_members` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `store_id`              BIGINT UNSIGNED NOT NULL COMMENT '所属店舗',
  `gym_inquiry_id`        INT NULL COMMENT '外部DB gym_inquiries.id への参照（体験から入会した会員）',
  `name`                  VARCHAR(100) NOT NULL COMMENT '氏名',
  `name_kana`             VARCHAR(100) NULL COMMENT 'フリガナ',
  `gender`                VARCHAR(10)  NULL COMMENT 'male / female / other',
  `birthday`              DATE         NULL COMMENT '生年月日',
  `phone`                 VARCHAR(20)  NULL COMMENT '電話',
  `email`                 VARCHAR(100) NULL COMMENT 'メール',
  `postal_code`           VARCHAR(8)   NULL COMMENT '郵便番号',
  `address`               VARCHAR(300) NULL COMMENT '住所',
  `joined_on`             DATE NOT NULL COMMENT '入会日',
  `withdrew_on`           DATE NULL COMMENT '退会日（NULL=在籍中）',
  `withdraw_reason`       VARCHAR(50)  NULL COMMENT '退会理由 Enum',
  `withdraw_note`         TEXT         NULL COMMENT '退会理由詳細',
  `current_plan_id`       BIGINT UNSIGNED NULL COMMENT '【キャッシュ】最新 open contract の plan_id',
  `trainer_id`            BIGINT UNSIGNED NULL COMMENT '担当トレーナー',
  `pair_parent_member_id` BIGINT UNSIGNED NULL COMMENT 'ペアプランの主契約者ID（NULL=通常会員）',
  `acquisition_source`    VARCHAR(30)  NULL COMMENT '当店を知ったきっかけ Enum',
  `purpose`               VARCHAR(50)  NULL COMMENT '目的 Enum',
  `memo`                  TEXT         NULL COMMENT '備考メモ（自由記述）',
  `created_by`            INT UNSIGNED NOT NULL COMMENT '登録者',
  `updated_by`            INT UNSIGNED NULL COMMENT '更新者',
  `created_at`            TIMESTAMP    NULL DEFAULT NULL,
  `updated_at`            TIMESTAMP    NULL DEFAULT NULL,
  INDEX `idx_zeal_members_store`       (`store_id`),
  INDEX `idx_zeal_members_joined_on`   (`joined_on`),
  INDEX `idx_zeal_members_withdrew_on` (`withdrew_on`),
  INDEX `idx_zeal_members_current_plan` (`current_plan_id`),
  INDEX `idx_zeal_members_pair_parent` (`pair_parent_member_id`),
  INDEX `idx_zeal_members_gym_inquiry` (`gym_inquiry_id`),
  CONSTRAINT `fk_zeal_members_store`       FOREIGN KEY (`store_id`)              REFERENCES `zeal_stores`(`id`)  ON DELETE RESTRICT,
  CONSTRAINT `fk_zeal_members_current_plan` FOREIGN KEY (`current_plan_id`)      REFERENCES `zeal_plans`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_zeal_members_trainer`     FOREIGN KEY (`trainer_id`)            REFERENCES `zeal_trainers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_zeal_members_pair_parent` FOREIGN KEY (`pair_parent_member_id`) REFERENCES `zeal_members`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL 会員マスタ';

-- -----------------------------------------------------------------------
-- 5. プラン契約履歴（SCD Type-2）
-- 1行 = 1契約期間。period_end IS NULL が現行契約。
-- プラン変更時: 現契約の period_end を変更日前日にし、新レコードを INSERT。
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zeal_member_contracts` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id`            BIGINT UNSIGNED NOT NULL COMMENT '会員ID',
  `plan_id`              BIGINT UNSIGNED NOT NULL COMMENT 'プランID（当時）',
  `period_start`         DATE NOT NULL COMMENT '契約開始日',
  `period_end`           DATE NULL COMMENT '契約終了日（NULL=現契約）',
  `applied_price_excl`   INT UNSIGNED NOT NULL COMMENT '焼付け: 適用税抜価格（通常 or キャンペーン）',
  `is_campaign_applied`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'キャンペーン価格適用フラグ',
  `tax_rate_at_contract` DECIMAL(5,2) NOT NULL COMMENT '焼付け: 契約時消費税率（例: 10.00）',
  `change_reason`        VARCHAR(50) NULL COMMENT '変更理由 Enum（new_join / plan_change / campaign_apply / price_revise / withdraw）',
  `note`                 VARCHAR(200) NULL COMMENT '備考',
  `created_by`           INT UNSIGNED NOT NULL COMMENT '登録者',
  `created_at`           TIMESTAMP NULL DEFAULT NULL,
  `updated_at`           TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_member_period` (`member_id`, `period_start`, `period_end`),
  CONSTRAINT `fk_zeal_contracts_member` FOREIGN KEY (`member_id`) REFERENCES `zeal_members`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_zeal_contracts_plan`   FOREIGN KEY (`plan_id`)   REFERENCES `zeal_plans`(`id`)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ZEAL プラン契約履歴（SCD Type-2）';
