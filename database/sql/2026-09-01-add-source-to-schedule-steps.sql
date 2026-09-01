-- 工程に取込元（source）を足す — 2026-09-01
--
-- 設計書: docs/superpowers/specs/2026-09-01-schedule-import-design.md §4.1  （工程表の取込）
--
-- ⚠ 2026-08-31-create-schedule-steps.sql（新規構築用）にも同じ列を足してある。
--   ScheduleSchemaTest は CREATE TABLE 本体からしか列を拾わないので、
--   ALTER だけ足すとテスト用スキーマと食い違って赤くなる。
--
-- ⚠ tests/Concerns/CreatesRealEstateSchema.php と対で維持すること。
--
-- ⚠ 既存行は NULL（手入力扱い）。工程表は 2026-09-01 に本番稼働したばかりで
--   実データ 0 件なので移行は不要。
--
-- ⚠ 本番反映は **この DDL が先・./deploy.sh が後**。逆にすると取込画面が
--   Unknown column 'source' で 500 する。
--
-- 適用: php artisan tinker で DB::statement() に流す
--   （sudo mysql は非対話でパスワードを渡せない。memory 参照）

ALTER TABLE `schedule_steps`
  ADD COLUMN `source` VARCHAR(20) NULL COMMENT '取込元。NULL=手入力 / import=工程表の取込' AFTER `notes`,
  ADD KEY `idx_sched_source` (`schedulable_type`, `schedulable_id`, `source`);
