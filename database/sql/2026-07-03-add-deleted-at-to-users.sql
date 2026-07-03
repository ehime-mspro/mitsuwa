-- users に deleted_at を追加（論理削除 / SoftDeletes）
-- テスト DB は migration 側（create_users_table の softDeletes）で構築されるため、この SQL は既存 live DB 専用。
-- 適用（ローカル）: sudo mysql masa8787kanri63732 < database/sql/2026-07-03-add-deleted-at-to-users.sql
-- 適用（本番）    : 本番 DB に同 SQL を実行（要ユーザー明示承認・csh）
ALTER TABLE `users`
  ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
