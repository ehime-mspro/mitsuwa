-- 賃貸マンション 部屋契約 解約時の敷金精算 — 2026-08-17
--
-- 背景: 解約画面（mansion/contracts/terminate.blade.php）には原状回復費・清掃費・
--   差引項目・解約理由の入力欄があり Alpine が返金額を計算して表示するが、
--   ContractController::terminate() は move_out_date と terminate_parkings しか
--   検証・保存しておらず、DB にも受け皿が無かった。入力は必ず失われ、しかも
--   「契約を解約しました」と成功表示が出ていた。
--
-- ⚠ ms_* は Laravel migration に無く raw SQL 管理。テストは
--   tests/Concerns/CreatesMansionSchema.php が SQLite 用の同等スキーマを組む。
--   **この DDL を変えたら trait も必ず追従すること**（片方だけだと SQLite テストだけ落ちる）。
--
-- 適用（ローカル / 本番とも 1 文ずつ実行する）:
--   php artisan tinker --execute='DB::statement("<1 文>");'
--   ⚠ sudo mysql は非対話でパスワードを渡せない。DB::unprepared() は
--     マルチステートメント依存なので複数文をまとめて流さない。
--
-- ⚠ 合計（差引合計・返金額）は保存しない。保存すると内訳と合計が別ソースになり
--   無音で食い違う（Bug #46 を本番で踏んでいる）。構成要素だけ持ち、表示時に積み上げる。

-- -----------------------------------------------------------------------
-- 1. ms_contracts に精算の固定項目を追加（契約 1 件につき解約は 1 回なので列で持つ）
-- -----------------------------------------------------------------------
ALTER TABLE `ms_contracts`
  ADD COLUMN `termination_reason`    VARCHAR(200) NULL COMMENT '解約理由'            AFTER `move_out_date`,
  ADD COLUMN `restoration_cost`      INT UNSIGNED NULL COMMENT '原状回復費（差引）'  AFTER `termination_reason`,
  ADD COLUMN `cleaning_cost`         INT UNSIGNED NULL COMMENT '清掃費（差引）'      AFTER `restoration_cost`,
  ADD COLUMN `deposit_at_settlement` INT UNSIGNED NULL COMMENT '精算時点の預かり敷金。deposit は解約後も編集できるため、返金根拠が動かないようスナップショットする' AFTER `cleaning_cost`;

-- -----------------------------------------------------------------------
-- 2. 差引項目（個数可変なので子テーブル）
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ms_contract_deductions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `name`        VARCHAR(100) NOT NULL COMMENT '差引項目名（例: 鍵交換費）',
  `amount`      INT UNSIGNED NOT NULL COMMENT '差引金額',
  `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '画面上の並び順',
  `created_at`  TIMESTAMP NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP NULL DEFAULT NULL,
  KEY `idx_ms_cd_contract` (`contract_id`),
  CONSTRAINT `fk_ms_cd_contract` FOREIGN KEY (`contract_id`) REFERENCES `ms_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部屋契約 敷金精算の差引項目';
