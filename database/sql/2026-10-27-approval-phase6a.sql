-- 決裁申請 段階6（6a）— 2026-10-27
--
-- 設計書: docs/superpowers/specs/2026-10-26-approval-phase6-design.md §5.3・§5.7
--
-- ⚠ database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase6aColumnsTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_members.is_tester と approval_number_sequences.next_without_trials を
--   読み書きするので、コードを先に送ると、列が足されるまで次の所が Unknown column で 500（例外）になる。
--   - 試しの帯（「試す期間か」を決裁の管理者に毎回問い合わせる）＝決裁の管理者が開く決裁の画面すべて。決裁の管理者のホーム・⑫ 休日・⑬ 使い始めも
--   - 連番の行の作成・更新が next_without_trials を書く所＝社長の判断（採番）・開始番号の設定・⑪ 紙の決裁の登録と取り込み
--   - 毎朝の催促（approvals:remind。最初に今の状態を読む）
--
-- ⚠ **3 は、番号を付けた申請が 1 件も無いときだけ正しい。** 今の次の番号をそのまま「試しの採番だけを除いた次の番号」にするため
--   （使い始める前に付いた番号は試しの採番にあたるので、番号を付けた申請が 1 件でもあると、今の次の番号に試しの採番が混ざる）。流す前に確かめる（計画 Task 10）。
--
-- 適用: 段階1〜5b と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_members に is_tester があるか、approval_number_sequences に next_without_trials があれば 1 文も流さずに止まる」確認をする（計画 Task 10）。
--   ⚠ 3 は 2 の後（列を足してから埋める）。

-- 1. 試す人（段階6 §5.3・D1。使い始める前に本番で申請を回して確かめる人）
ALTER TABLE `approval_members`
  ADD COLUMN `is_tester` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '試す人（使い始める前に本番で申請を回して確かめる人）' AFTER `stamp_text`;

-- 2. 試しの採番だけを除いた次の番号（段階6 §5.7。開始番号と紙の番号で動き、社長の判断の採番では動かない。6b の片付けで次の番号をこれに戻す）
ALTER TABLE `approval_number_sequences`
  ADD COLUMN `next_without_trials` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '試しの採番だけを除いた次の番号' AFTER `last_issued`;

-- 3. 今の行をそろえる（番号を付けた申請が 1 件も無いので、今の次の番号＝試しの採番を除いた次の番号）
UPDATE `approval_number_sequences` SET `next_without_trials` = `next_number`;
