-- 募集家賃の賃料改定履歴テーブル（rent_revisions のミラー、unit_id 版）
-- 追加のみ・既存テーブルへの変更なし。
-- 適用（ローカル）: sudo mysql manage < database/sql/2026-06-22-create-unit-rent-revisions.sql
-- 適用（本番）    : 本番DBに対し同SQLを実行（要ユーザー承認・Task 10 参照）
CREATE TABLE `unit_rent_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `revision_date` DATE NOT NULL COMMENT '改定適用日',
  `old_rent` INT NOT NULL COMMENT '旧募集家賃（円）',
  `new_rent` INT NOT NULL COMMENT '新募集家賃（円）',
  `old_common_fee` INT NULL COMMENT '旧共益費（円）',
  `new_common_fee` INT NULL COMMENT '新共益費（円）',
  `old_garbage_fee` INT NULL COMMENT '旧ゴミ代（円）',
  `new_garbage_fee` INT NULL COMMENT '新ゴミ代（円）',
  `old_pest_control_fee` INT NULL COMMENT '旧駆除代（円）',
  `new_pest_control_fee` INT NULL COMMENT '新駆除代（円）',
  `reason` TEXT NULL COMMENT '改定理由',
  `revised_by` BIGINT UNSIGNED NOT NULL COMMENT '改定実行者（経営層）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_unit_revisions_unit` (`unit_id`),
  CONSTRAINT `fk_unit_revisions_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_unit_revisions_revised_by` FOREIGN KEY (`revised_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
