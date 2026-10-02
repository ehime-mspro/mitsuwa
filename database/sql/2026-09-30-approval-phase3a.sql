-- 決裁申請 段階3（3a）— 2026-09-30
--
-- 設計書: docs/superpowers/specs/2026-09-30-approval-phase3-design.md §5.3
--
-- ⚠ database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase3TablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは notifications と approval_settings.mail_last_* を読むので、
--   コードを先に送ると、決裁の画面（ヘッダーのベル・管理の画面の帯）が Unknown table / column で 500 になる。
--
-- ⚠ notifications は Laravel 標準の形に approval_request_id（その申請の未読を索引で引く）を足したもの（3a 計画 §0.2）。
--   索引名・外部キー名は段階1・2 と同じ流儀（索引は idx_・外部キーは fk_）。照合順序は utf8mb4_unicode_ci。
--
-- 適用: 段階1・2 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「notifications がすでにあるか、approval_settings に mail_last_sent_at があれば 1 文も流さずに止まる」確認をする（計画 Task 12）。

-- 1. 既存の表に列を足す（D2 の表示に使う。書くのは App\Support\Approval\MailDelivery）
ALTER TABLE `approval_settings`
  ADD COLUMN `mail_last_sent_at` TIMESTAMP NULL COMMENT '決裁のメールが最後に送れた日時' AFTER `launched_at`,
  ADD COLUMN `mail_last_failed_at` TIMESTAMP NULL COMMENT '決裁のメールが最後に送れなかった日時（送り直し 3 回のあと）' AFTER `mail_last_sent_at`,
  ADD COLUMN `mail_last_failed_to` VARCHAR(255) NULL COMMENT '最後に送れなかったメールの宛先（氏名）' AFTER `mail_last_failed_at`;

-- 2. 新しい表（お知らせ。1 人 1 行・消さない。D16）
CREATE TABLE `notifications` (
  `id` CHAR(36) NOT NULL,
  `type` VARCHAR(255) NOT NULL,
  `notifiable_type` VARCHAR(255) NOT NULL,
  `notifiable_id` BIGINT UNSIGNED NOT NULL,
  `approval_request_id` BIGINT UNSIGNED NULL COMMENT '決裁の申請（その申請の未読を既読にするため）',
  `data` TEXT NOT NULL,
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_notifiable` (`notifiable_type`, `notifiable_id`, `read_at`),
  KEY `idx_notifications_request` (`approval_request_id`),
  CONSTRAINT `fk_notifications_approval_request` FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
