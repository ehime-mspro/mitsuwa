-- 工程表（不動産 / 住宅事業）— 2026-08-31
--
-- 設計書: docs/superpowers/specs/2026-08-31-realestate-schedule-gantt-design.md §3.1
--
-- ⚠ tests/Concerns/CreatesRealEstateSchema.php と対で維持すること。
--   片方だけ直すと「テストは緑なのに本番で Unknown column」になる
--   （ScheduleSchemaTest::test_raw_sql_and_test_schema_declare_the_same_columns が拾う）。
--
-- ⚠ テーブル名に re_ / hs_ の接頭辞を付けない。不動産と住宅の両方がぶら下がるため
--   （attachments / buyers / users と同じ扱い）。
--
-- ⚠ 外部キーは張らない。schedulable_type が 4 種類あるため単一の FK では表現できない。
--   親を消したときの削除は各コントローラの destroy() が行う（設計書 §3.5）。
--
-- 適用: sudo mysql manage < database/sql/2026-08-31-create-schedule-steps.sql
--   CREATE TABLE IF NOT EXISTS なので再実行して安全。
--
-- ⚠ 本番反映は **この DDL が先・./deploy.sh が後**。逆にすると詳細 4 画面とボード 2 画面が
--   Base table or view not found で 500 する（設計書 §7）。

CREATE TABLE IF NOT EXISTS `schedule_steps` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedulable_type` VARCHAR(255)    NOT NULL COMMENT '親クラスの FQCN',
  `schedulable_id`   BIGINT UNSIGNED NOT NULL COMMENT '親の id',
  `name`             VARCHAR(100)    NOT NULL COMMENT '工程名（自由入力）',
  `category`         VARCHAR(20)     NOT NULL DEFAULT 'other' COMMENT '色分けのみに使う分類',
  `planned_start`    DATE            NULL COMMENT '予定開始',
  `planned_end`      DATE            NULL COMMENT '予定終了',
  `actual_start`     DATE            NULL COMMENT '実績開始',
  `actual_end`       DATE            NULL COMMENT '実績終了',
  `sort_order`       INT             NOT NULL DEFAULT 0 COMMENT '画面の並び順',
  `notes`            VARCHAR(255)    NULL COMMENT '備考',
  `source`           VARCHAR(20)     NULL COMMENT '取込元。NULL=手入力 / andpad=ANDPAD 取込',
  `created_by`       BIGINT UNSIGNED NULL,
  `updated_by`       BIGINT UNSIGNED NULL,
  `created_at`       TIMESTAMP       NULL,
  `updated_at`       TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sched_owner` (`schedulable_type`, `schedulable_id`, `sort_order`),
  KEY `idx_sched_planned_start` (`planned_start`),
  KEY `idx_sched_planned_end`   (`planned_end`),
  KEY `idx_sched_source` (`schedulable_type`, `schedulable_id`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工程表の 1 行';
