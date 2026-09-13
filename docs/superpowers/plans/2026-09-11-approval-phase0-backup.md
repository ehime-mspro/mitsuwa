# 段階0（定期実行・メール送信の土台・バックアップ） Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

---

## 実装中の変更（レビュー反映）

各タスクの実装後のコードレビュー（仕様の突き合わせ・品質の点検・ブランチ全体の点検）を反映して、以下の章（Task 1〜15 の本文）から変わった点。以下の章のコードや文面は当初の案のままで、実際とは違う所がある。正はリポジトリのコードとテスト、手順は `docs/運用_バックアップとメール.md`。

- **BackupCipher**: ファイルのヘッダーに 8 バイトの鍵の識別子（keyId）を含む（復号時に「鍵が違う」と「ファイルが壊れている」を区別するためで、秘密情報ではない）。出力は同じフォルダの一時ファイルへ 0600 で書き、成功したときだけ rename で本来の名前に置き換える（失敗時は一時ファイルだけを消す）。この識別子を取り出す `keyId(): string`（16 桁の小文字16進数）を追加した。
- **添付の保管キー**: `files/<keyId の 16 桁16進数>/<パスの base64url>.enc` の形にし、暗号化キーごとにフォルダを分けた（キーを変えた直後は新しいキーのフォルダが空なので、最初のバックアップで全添付を送り直す。古いキーのフォルダは残したままにして、そのキーが使える限り復元できるようにする）。これに伴い `FileSyncPlanner` の API は次の形になった。
  - `prefixFor(string $keyId): string`
  - `keyFor(string $relativePath, string $keyId): string`
  - `pathFor(string $key): ?string`
  - `filesToUpload(array $localFiles, array $remoteObjects, string $keyId): array`
- **以降のタスクで対応した点**:
  - `BackupSummary` に `filesScanned`（走査した添付の総数）を追加した（Task 7）。
  - `BackupRunner` は、実行の途中で手元から消えたファイルがあっても送信対象から外すだけにして、失敗にしないようにした（Task 7）。
  - `ops:backup` の出力を「添付 N 件を追加（対象 M 件）」の形式で表示するようにした（Task 8）。
  - `ops:backup-restore` は現在の鍵の識別子のフォルダだけを読み、`.work` という一時フォルダを使い、ファイルごとの失敗を集めて最後にまとめて報告し、1 件でも失敗したら 0 以外で終了するようにした（Task 9）。

- **Task 3（RetentionPolicy）**: `RetentionPolicy::databaseKey()` が `db/manage-YYYYMMDD-HHMMSS.sql.gz.enc`（日本時間）を組み立て、`expiredDatabaseKeys()`・`latestDatabaseKey()` で保存期間切れ・最新の判定を行う。一覧の絞り込みは前後を固定した形式チェックで、最新の 1 件は日数によらず残す。
- **Task 4（BackupStorage・LocalDirectoryBackupStorage）**: 保管先のインターフェース（put/get/list/delete）を定義し、テスト・手元の通し確認用にローカルフォルダ実装を追加した。約束事は、put は同じキーを上書きする・get はキーが無ければ例外を投げる・list は前方一致でキー順に大きさ付きで返す（末尾が `/` のものは除く）・存在しないキーの delete は何もしない、の 4 つ。
- **Task 5（S3BackupStorage）**: `S3BackupStorage::fromConfig()` が接続 10 秒・送受信 1800 秒の上限、パス形式、`when_required` のチェックサムで `S3Client` を組み立てる。一覧は続きの目印（`NextContinuationToken`）が無いまま打ち切られたら例外にする。削除は空のキーと末尾が `/` のキーを断る（ローカルの実装が断るのは空のキーだけ）。
- **Task 6（MysqlDatabaseDumper）**: パスワードは 0600 の一時 `--defaults-file` で渡し、`umask(0077)` でダンプを本人だけが読める権限にする。定期実行は PATH が短いため `BACKUP_MYSQLDUMP_BINARY` の指定が必須（コードでは強制しない。既定は `mysqldump` で、書き忘れると夜間だけ not found で失敗する）、時間切れは `ProcessTimedOutException` を専用の文言で包む。
- **Task 7（BackupRunner・BackupWorkDirectory・DumpCompressor・config/backup.php）**: 作業フォルダは `BackupWorkDirectory`（名前は `backup-work` 固定・symlink 不可・対象フォルダの外・`flock` で同時実行を防ぎ、ロックを取った後に前回の途中の作業ファイルを消す）、圧縮は `DumpCompressor` に部品を分けた（`gzwrite()`・`gzclose()` は空き容量が切れても成功を返すことがあるため使わず、`deflate_init()`・`deflate_add()` で圧縮し、`fwrite()` の戻り値と最後の大きさで書き込みを確かめる）。保管先への送信が `BackupRunner::MAX_CONSECUTIVE_FAILURES`（5）件続けて失敗したら、「データベースは保存済みです（…）」の文脈を保ったまま中断する。
- **Task 8（BackupCommand・BackupFailureNotifier・BackupFailedMail）**: `BackupFailureNotifier` が宛先の区切り（カンマ・読点・セミコロン・全角・空白）と誤り・文字化けの警告を担い、宛先ごとに 1 通ずつ送る。自分で扱った失敗は終了コード `BackupCommand::HANDLED_FAILURE`（3）。
- **Task 9（BackupKeyCommand・BackupDecryptCommand・BackupRestoreCommand）**: `ops:backup-key` はキーの識別番号（`keyId()`）も表示する。`ops:backup-restore` は `--without-db`・`--without-files`・`--ask-key` を持ち、現在の鍵のフォルダだけを読んで `.work` の一時フォルダを使う。保管先からの取得が `MAX_CONSECUTIVE_GET_FAILURES`（5）件連続で失敗したら打ち切り、ファイルごとの失敗をまとめて報告する。
- **Task 10（MailTestCommand・OpsTestMail）**: `ops:mail-test` は今の設定を 1 行で表示し、`log`・`array` の送信方式や `sync` のキューを断る・警告する。宛先ごとに 1 通ずつ送り、案内は `PHP_BINARY` から組み立てる（`SuggestsArtisanCommands`）。`OpsTestMail::$tries = 1` により `failed()` が 1 回の失敗ですぐ記録される。
- **Task 11（routes/console.php の定期実行）**: `queue:work` は `schedule:run` の起動ごと（`everyMinute()`）、`ops:backup` は `0-4 3 * * *`（日本時間）・キュー処理より先・`evenInMaintenanceMode()`、出力は `storage/logs/backup-command.log`、`withoutOverlapping(180)`/`(10)`。
- **Task 12（設定のひな形・deploy の保険・手順書）**: `.env.example` に `BACKUP_*` のプレースホルダーを足し、`deploy.sh` の rsync に `--exclude='storage/app/backup-work'` を足した。`docs/運用_バックアップとメール.md` と `CLAUDE.md` に運用の要点を記録した。
- **Task 13（ブランチ全体の点検を受けた手直し）**: 夜間バックアップが kill などのシグナルで強制終了されたときも、`routes/console.php` の `ScheduledTaskFailed` の listener が失敗を知らせるようにした（`before()` で前回の終了コードをリセットし、二重通知はしない）。画面に出す失敗の理由は `OutputFormatter::escape()` で書式の印から守り、`ops:backup-decrypt` の保存先はバックアップ対象フォルダの中にできないようにした。定期実行のテストをふるまい（実際の `isDue()` や `schedule:run`）で補強した。

### 段階1 以降への申し送り
- メール送信の失敗（SMTP）は `storage/logs/laravel.log` と `failed_jobs` テーブルにしか残らない。`failed_jobs` が増えたら知らせる仕組みを検討する。
- Laravel 12.55 の `schedule:run` の画面表示は、失敗しても DONE と出る（`ScheduleRunCommand::runEvent()` の中で `components->task()` に渡す closure が bool（`$event->exitCode == 0`）を返し、`Illuminate\Console\View\Components\Task` の表示側は `TaskResult`（int）と厳密に比較するため、bool は常に default 側＝DONE に落ちる）。成否の判定には使わない。
- 本番（FreeBSD）の sh は予定のコマンドを直接実行するため、kill などのシグナルで止まると `onFailure` が呼ばれない。失敗を知らせたい予定を新しく足すときは、`routes/console.php` の `ScheduledTaskFailed` の listener と同じ形にする。
- 本番の .env の落とし穴（手順書 2.5）: 引用符なしの値に空白が 1 つでもあると .env 全体が読めなくなり、`config:cache`（最初に設定のキャッシュを消す）の時点で「The environment file is invalid!」になってサイト全体が止まる。`#` を含む値は `'…'` で囲まないと `#` から後ろが黙って消え、`"` の閉じ忘れはその行から後ろが黙って読み捨てられる（phpdotenv v5）。.env の項目を足す作業では、手順書 2.5 の「書き間違いの確認 → `config:cache`」の順を案内する。
- 段階1 で添付を足すときは、`storage/app/{public,private}` のファイルを上書き保存しない（CLAUDE.md の「定期実行とバックアップ（本番）」。バックアップは同じパス・同じ大きさなら送り直さないため、上書きした変更がバックアップに入らない）。
- 見送った軽微な点（実害がほぼ無いため）:
  - Task 9-1（取り出し先の名前の `\<` の表示。起きる見込みがほぼ無い）
  - Task 9-3（mkdir の失敗が英語の「mkdir(): Permission denied」になる。Laravel のエラーハンドラーは戻り値 null なので error_get_last() に理由が残らず、@ を付けると理由が消える。手順書 5 章の「どの行にも当てはまらなければ開発担当へ転送」で拾える）
  - Task 9-4（trait の名前・説明文・シンボリックリンクのテスト。実害なし）

---

## この計画でやること（ご確認用の要約）

**なぜ**: 要件定義書 v1.0（`docs/決裁申請_要件定義書_v1.md`）14.6・15.3・16.1 段階0。基幹システムのデータベースは今どこにも自動でバックアップされていない。決裁機能の土台（メール送信・定期実行）と一緒に、毎晩の暗号化バックアップを先に作って本番で動かす。

**できあがるもの**
1. 毎晩 3:00（日本時間）に、データベース全体と添付ファイルを暗号化して、さくらのオブジェクトストレージへ送る。30 日を過ぎたデータベースのバックアップは自動で消す（いちばん新しい 1 件は必ず残す）。失敗したらメールで知らせる
2. 5 分おきの定期実行で、送信待ちのメールを送る仕組み（決裁の通知メールの土台）
3. 動作確認用のテストメール、復元用のコマンド、運用の手順書（`docs/運用_バックアップとメール.md`）

**社員の使い方は何も変わらない**（画面の変更なし）。

**開発側が行うこと**: 作業用ブランチでテストを先に書いてから作り、全テストを通す（コミットは手元のブランチだけ。GitHub には送らない）。

**あとでお願いすること（手順書を用意します。本番の作業は、その都度確認してから進めます）**
- さくらのコントロールパネル: 決裁専用メールアドレスの作成、SPF・DKIM の有効化、バックアップ専用バケットと鍵の作成、定期実行の登録
- 本番の設定ファイル（.env）への秘密情報の記入（開発側は秘密情報に触れません）
- 暗号化キーを紙に書いて金庫に保管

**確かめ方**: 自動テスト → 手元での通し確認（本物の mysqldump で手元の DB を暗号化・復元）→ 本番でテストメール・手動バックアップ・復元の確認。

---

**Goal:** 本番の基幹システムに、5 分おきの定期実行（キュー処理）と、毎晩の暗号化バックアップ（DB 全体＋添付 → さくらのオブジェクトストレージ）を入れる。

**Architecture:** `routes/console.php` の Laravel スケジューラを、さくらの CRON（5 分おき・1 件）から起動する。バックアップは `app/Support/Backup/` の小さな部品（暗号化・保管先・ダンプ・差分計画・保存期間）を `BackupRunner` が順に呼ぶ。保管先は `BackupStorage` インターフェースで抽象化し、本番は S3 互換（aws-sdk-php の `S3Client` を直接使う）、テストはローカルフォルダ実装に差し替える。

**Tech Stack:** Laravel 12.55 / PHP 8.3（本番・手元とも）/ MySQL 8 / OpenSSL の AES-256-GCM＋HKDF（本番に sodium が無いため）/ zlib / aws/aws-sdk-php ^3.337 / PHPUnit 11

---

## 前提と既存の事実（調査済み）

| 事実 | 根拠 |
|------|------|
| 定期実行・メール・キューは未導入。`routes/console.php` は `inspire` だけ | `routes/console.php:1-8` |
| アプリの timezone は UTC。`app.schedule_timezone` に対応（Laravel 12） | `config/app.php:68`、`vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php:303` |
| キューの既定は database（`jobs` テーブル）、キャッシュの既定は database | `config/queue.php:16`、`config/cache.php:18` |
| メールの既定は log。SMTP の設定項目は env（`MAIL_SCHEME` など） | `config/mail.php:17,40-50` |
| `local` ディスクの場所は `storage/app/private`、`public` は `storage/app/public`（既存の添付の置き場） | `config/filesystems.php:33-48` |
| aws-sdk-php は未インストール（laravel/framework の suggest のみ）。symfony/process は導入済み | `composer.lock:1243,1186` |
| `AppServiceProvider` は空 | `app/Providers/AppServiceProvider.php` |
| テスト環境: SQLite メモリ、`MAIL_MAILER=array`、`QUEUE_CONNECTION=sync`、`CACHE_STORE=array`、`APP_LOCALE=ja` | `phpunit.xml:28-46` |
| 手元の `php` は 8.3.30、mysqldump 8.4.8 あり | 実測 |
| **本番（2026-09-11 に読み取りのみで実測）**: PHP 8.3.32。拡張は curl・json・mbstring・openssl・pdo_mysql・SimpleXML・zlib で、**sodium は無い**。CLI の memory_limit 128M・max_execution_time 0。`/usr/local/bin/mysqldump` 8.0.35（`--set-gtid-purged` 対応）。キャッシュ=file、キュー=**sync**、メール=log。`jobs`・`failed_jobs`・`job_batches`・`cache`・`cache_locks`・`sessions` はすべて存在。crontab は 0 件 | 実測 |
| 本番のログインシェルは csh。SSH でのコマンドは `ssh <server> /bin/sh <<'SH' … SH` の形で送る | `docs/superpowers/plans/2026-07-30-procurement-land-building-tax.md:3436-3443` |
| 新しい composer 部品の本番反映: worktree で `composer require` → main repo で `composer install --no-dev` と `composer dump-autoload --no-dev --optimize` → `vendor` ごと deploy | `docs/BACKLOG.md:700-702` |
| テストは worktree で `composer install` → `APP_KEY` を環境変数で渡して `./vendor/bin/phpunit`（worktree に .env を作らない）。最新の全件結果は `OK (1315 tests, 8681 assertions)` | `docs/superpowers/plans/2026-09-03-schedule-board-gantt.md:22-35`、`2026-09-04-schedule-board-year-header.md:1569` |
| テスト環境でも `Schedule` はシングルトンで、`app.schedule_timezone` を使い、`routes/console.php` の予定が載る。`Event` の `$command`・`$expression`・`$timezone`・`$withoutOverlapping` は public | `FoundationServiceProvider.php:107-111`、`Console/Kernel.php:287-304`、`ManagesAttributes.php:14,28,56`、`Event.php:34` |
| `deploy.sh` の rsync は `storage/` を除外していない（手元の storage が本番へ行く）。別作業として切り出し済み | `deploy.sh:32-55` |
| 本番: アプリ `~/apps/manage`、PHP は `/usr/local/php/8.3/bin/php`（既定の CLI は 7.4）、`deploy.sh` は rsync（`composer install` は本番で走らない） | `deploy.sh:4-6,32-55,76-79` |
| さくら: 常駐プロセス禁止、CRON は最大 10 件で毎分は不可、オブジェクトストレージは石狩 `s3.isk01.sakurastorage.jp` / `jp-north-1`、パス形式、ライフサイクル無し、SSE 無し、チェックサムは `when_required` 推奨、キー名に `:` 不可 | 要件定義書 15.3・14.6、2026-09-11 調査 |

## 実装上の決まりごと

- コメント・画面や出力の文言は、既存コードに合わせて日本語。クラス名・変数名は英語。
- 秘密情報（DB パスワード・S3 のキー・暗号化キー）はログ・出力・例外メッセージ・メールに出さない。
- 暗号化は OpenSSL（AES-256-GCM）。本番に sodium が無いので sodium の関数は使わない。`composer.json` に拡張の requirement は足さない（platform check でサイト全体が落ちる危険を避ける）。
- 平文のダンプは作業フォルダ（`storage/app/backup-work`、0700）にだけ置き、処理の最後に必ず消す。作業フォルダの名前は `backup-work` 以外を受け付けない（設定ミスで別のフォルダの中身を消さないため）。
- composer は必ず PHP 8.3 で実行する（手元の既定 `php` が 8.3.30）。追加後に `vendor/composer/platform_check.php` の要求が 8.3 のままかを確かめる。
- 定期実行の予定の時刻は日本時間（`config/app.php` の `schedule_timezone`）。アプリ全体の `timezone`（UTC）は変えない。

## ファイル構成

| ファイル | 役割 |
|---------|------|
| `app/Support/Backup/BackupCipher.php`（新規） | ファイルのストリーム暗号化・復号、暗号化後サイズの計算、鍵の生成 |
| `app/Support/Backup/BackupStorage.php`（新規） | 保管先のインターフェース（put/get/list/delete） |
| `app/Support/Backup/S3BackupStorage.php`（新規） | さくらのオブジェクトストレージ（aws-sdk-php `S3Client`） |
| `app/Support/Backup/LocalDirectoryBackupStorage.php`（新規） | ローカルフォルダの保管先（テストと手元の通し確認用） |
| `app/Support/Backup/DatabaseDumper.php`（新規） | ダンプのインターフェース |
| `app/Support/Backup/MysqlDatabaseDumper.php`（新規） | mysqldump の実行（パスワードは一時の設定ファイルで渡す） |
| `app/Support/Backup/StorageFileScanner.php`（新規） | `storage/app` 配下の対象ファイルの一覧（相対パス => サイズ） |
| `app/Support/Backup/FileSyncPlanner.php`（新規） | 添付の保管キーの変換と「送る必要があるファイル」の判定 |
| `app/Support/Backup/RetentionPolicy.php`（新規） | DB バックアップのキー名、保存期間切れの判定、最新の判定 |
| `app/Support/Backup/BackupSummary.php`（新規） | 実行結果（DB のキー・送った添付の数・消した数） |
| `app/Support/Backup/BackupRunner.php`（新規） | ダンプ → 圧縮 → 暗号化 → 送信 → 添付の差分送信 → 古いものの削除 |
| `app/Console/Commands/BackupCommand.php`（新規） | `ops:backup`（失敗時は通知メール） |
| `app/Console/Commands/BackupKeyCommand.php`（新規） | `ops:backup-key`（新しい暗号化キーを表示） |
| `app/Console/Commands/BackupDecryptCommand.php`（新規） | `ops:backup-decrypt`（1 ファイルを復号） |
| `app/Console/Commands/BackupRestoreCommand.php`（新規） | `ops:backup-restore`（最新の DB と全添付を取り出して復号） |
| `app/Console/Commands/MailTestCommand.php`（新規） | `ops:mail-test`（テストメールを送信待ちへ） |
| `app/Mail/BackupFailedMail.php`・`app/Mail/OpsTestMail.php`（新規） | 失敗通知・テストメール |
| `resources/views/mail/backup-failed.blade.php`・`ops-test.blade.php`（新規） | 上記のテキスト本文 |
| `config/backup.php`（新規） | バックアップの設定 |
| `config/app.php`（変更） | `schedule_timezone` を追加 |
| `routes/console.php`（変更） | キュー処理と夜間バックアップの予定 |
| `app/Providers/AppServiceProvider.php`（変更） | 部品の組み立て（バインド） |
| `composer.json`・`composer.lock`（変更） | aws/aws-sdk-php の追加 |
| `.env.example`（追記） | 新しい設定項目のプレースホルダー |
| `deploy.sh`（変更・1 行） | rsync に `--exclude='storage/app/backup-work'` を足す（平文のダンプを本番へ送らない保険） |
| `CLAUDE.md`（追記） | 定期実行・バックアップの要点（今後の作業者向け） |
| `docs/運用_バックアップとメール.md`（新規） | 設定・確認・復元の手順書 |
| `tests/Unit/Backup/*`・`tests/Feature/Backup/*`・`tests/Feature/Ops/*`（新規） | テスト |

---

## Task 0: 作業用ブランチと部品の追加

**Files:** Modify: `composer.json`, `composer.lock`

- [ ] **Step 1: worktree を作る**（main repo は `13.x`・要件定義書 a14463b5 を含む）

```bash
cd /Users/masanori/site/manage
git worktree add .claude/worktrees/approval-phase0 -b feature/approval-phase0-backup 13.x
cd .claude/worktrees/approval-phase0
php -v | head -1   # PHP 8.3.x であること
composer install --no-interaction
```

- [ ] **Step 2: 追加前の全テストを記録する**（基準値）

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
./vendor/bin/phpunit 2>&1 | tail -3
```
Expected: `OK (1315 tests, …)`（件数が違えばその値を基準にする）

- [ ] **Step 3: aws-sdk-php を追加する**

```bash
composer require "aws/aws-sdk-php:^3.337" --no-interaction
grep -n "PHP_VERSION_ID >=" vendor/composer/platform_check.php
```
Expected: `80300` のみ（`80400` 以上が出たら、`composer require` を取り消して `composer.json` に `"config": {"platform": {"php": "8.3.32"}}` を足してからやり直す）

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: バックアップ送信用に aws-sdk-php を追加する"
```

---

## Task 1: 暗号化（BackupCipher）

※ レビューにより強化済み（鍵の識別子をヘッダーに追加・出力は 0600 の一時ファイル経由で置き換え・防御のテストを追加）。最新のコードは `app/Support/Backup/BackupCipher.php` を正とする。

**Files:**
- Create: `app/Support/Backup/BackupCipher.php`
- Test: `tests/Unit/Backup/BackupCipherTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/backup-cipher-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public static function sizes(): array
    {
        return [
            '空' => [0],
            '1 バイト' => [1],
            'かたまり未満' => [63],
            'ちょうど 1 かたまり' => [64],
            '複数のかたまり' => [64 * 3 + 5],
        ];
    }

    #[DataProvider('sizes')]
    public function test_roundtrip_restores_the_original_bytes(int $size): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $plain = $this->put('plain.bin', $this->bytes($size));

        $cipher->encryptFile($plain, $this->dir.'/data.enc');
        $cipher->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');

        $this->assertSame(file_get_contents($plain), file_get_contents($this->dir.'/restored.bin'));
    }

    #[DataProvider('sizes')]
    public function test_encrypted_size_matches_the_real_output(int $size): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $cipher->encryptFile($this->put('plain.bin', $this->bytes($size)), $this->dir.'/data.enc');

        $this->assertSame(BackupCipher::encryptedSize($size, 64), filesize($this->dir.'/data.enc'));
    }

    public function test_same_input_encrypts_differently_each_time(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $plain = $this->put('plain.bin', 'same data');
        $cipher->encryptFile($plain, $this->dir.'/a.enc');
        $cipher->encryptFile($plain, $this->dir.'/b.enc');

        $this->assertNotSame(file_get_contents($this->dir.'/a.enc'), file_get_contents($this->dir.'/b.enc'));
    }

    public function test_wrong_key_is_rejected_and_leaves_no_output(): void
    {
        $plain = $this->put('plain.bin', 'secret data');
        (new BackupCipher(BackupCipher::generateKey(), 64))->encryptFile($plain, $this->dir.'/data.enc');

        try {
            (new BackupCipher(BackupCipher::generateKey(), 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
            $this->fail('別の鍵で復号できてしまった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('復号できません', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/restored.bin');
    }

    public function test_tampered_file_is_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(200)), $this->dir.'/data.enc');
        $bytes = file_get_contents($this->dir.'/data.enc');
        $bytes[60] = chr(ord($bytes[60]) ^ 1); // 1 つ目のかたまりの暗号文の中を 1 ビット変える
        file_put_contents($this->dir.'/data.enc', $bytes);

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_file_cut_at_a_chunk_boundary_is_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(64 * 2 + 10)), $this->dir.'/data.enc');
        $full = file_get_contents($this->dir.'/data.enc');
        $lastChunk = 4 + 1 + 10 + 16; // 最後のかたまり（最終フラグ付き）を丸ごと落とす
        file_put_contents($this->dir.'/data.enc', substr($full, 0, -$lastChunk));

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_trailing_bytes_are_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', 'abc'), $this->dir.'/data.enc');
        file_put_contents($this->dir.'/data.enc', 'x', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_decryption_does_not_depend_on_the_chunk_setting(): void
    {
        $key = BackupCipher::generateKey();
        $plain = $this->put('plain.bin', $this->bytes(300));
        (new BackupCipher($key, 64))->encryptFile($plain, $this->dir.'/data.enc');
        (new BackupCipher($key))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');

        $this->assertSame(file_get_contents($plain), file_get_contents($this->dir.'/restored.bin'));
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        new BackupCipher(base64_encode('too short'));
    }

    public function test_generated_key_is_32_random_bytes(): void
    {
        $key = BackupCipher::generateKey();

        $this->assertSame(32, strlen(base64_decode($key, true)));
        $this->assertNotSame($key, BackupCipher::generateKey());
    }

    private function bytes(int $size): string
    {
        return $size === 0 ? '' : random_bytes($size);
    }

    private function put(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/BackupCipherTest.php`
Expected: FAIL（`Class "App\Support\Backup\BackupCipher" not found`）

- [ ] **Step 3: 実装する**

```php
<?php

namespace App\Support\Backup;

use RuntimeException;
use Throwable;

/**
 * バックアップファイルの暗号化・復号（AES-256-GCM を 1MB ごとのかたまりで）。
 *
 * 本番の PHP には sodium が無いため OpenSSL を使う（2026-09-11 実測）。
 * ファイルごとにランダムな salt から鍵を派生させ（HKDF-SHA256）、かたまりの番号を IV にする。
 *
 * 形式: "MTWBK1"(6) + salt(32) + { 長さ(4, big-endian) + 最終フラグ(1) + 暗号文 + タグ(16) } の繰り返し。
 * かたまりの番号と最終フラグを AAD に含めるので、書き換え・並べ替え・途中で切れたファイルは必ず復号エラーになる。
 */
final class BackupCipher
{
    public const MAGIC = 'MTWBK1';

    public const DEFAULT_CHUNK_BYTES = 1048576;

    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private const SALT_BYTES = 32;

    private const TAG_BYTES = 16;

    // 壊れた長さの値で巨大なメモリを取らないための上限
    private const MAX_CHUNK_BYTES = 16777216;

    private string $masterKey;

    public function __construct(string $base64Key, private int $chunkBytes = self::DEFAULT_CHUNK_BYTES)
    {
        if (! in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('この PHP の OpenSSL は AES-256-GCM に対応していません。');
        }

        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('バックアップの暗号化キーが正しくありません（BACKUP_ENCRYPTION_KEY を確認してください）。');
        }

        if ($chunkBytes < 1 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new RuntimeException('かたまりの大きさが範囲外です。');
        }

        $this->masterKey = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /**
     * 平文のバイト数から暗号化後のバイト数を求める（保管先にあるファイルが最新かの判定に使う）。
     */
    public static function encryptedSize(int $plainBytes, int $chunkBytes = self::DEFAULT_CHUNK_BYTES): int
    {
        $chunks = max(1, intdiv($plainBytes + $chunkBytes - 1, $chunkBytes));

        return strlen(self::MAGIC) + self::SALT_BYTES + $chunks * (4 + 1 + self::TAG_BYTES) + $plainBytes;
    }

    public function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->transform($sourcePath, $destinationPath, function ($in, $out): void {
            $salt = random_bytes(self::SALT_BYTES);
            $fileKey = $this->fileKey($salt);
            $this->write($out, self::MAGIC.$salt);

            $index = 0;
            $current = $this->readUpTo($in, $this->chunkBytes);
            while (true) {
                $next = $this->readUpTo($in, $this->chunkBytes);
                $final = $next === '';
                $this->write($out, $this->sealChunk($fileKey, $salt, $index, $final, $current));
                if ($final) {
                    return;
                }
                $current = $next;
                $index++;
            }
        });
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->transform($sourcePath, $destinationPath, function ($in, $out): void {
            if ($this->readUpTo($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw $this->unreadable();
            }
            $salt = $this->readUpTo($in, self::SALT_BYTES);
            if (strlen($salt) !== self::SALT_BYTES) {
                throw $this->unreadable();
            }
            $fileKey = $this->fileKey($salt);

            for ($index = 0; ; $index++) {
                $head = $this->readUpTo($in, 5);
                if (strlen($head) !== 5 || ($head[4] !== "\x00" && $head[4] !== "\x01")) {
                    throw $this->unreadable();
                }
                $length = unpack('N', substr($head, 0, 4))[1];
                $final = $head[4] === "\x01";
                if ($length > self::MAX_CHUNK_BYTES) {
                    throw $this->unreadable();
                }

                $cipherText = $this->readUpTo($in, $length);
                $tag = $this->readUpTo($in, self::TAG_BYTES);
                if (strlen($cipherText) !== $length || strlen($tag) !== self::TAG_BYTES) {
                    throw $this->unreadable();
                }

                $plain = openssl_decrypt($cipherText, self::CIPHER, $fileKey, OPENSSL_RAW_DATA, $this->iv($index), $tag, $this->aad($salt, $index, $final));
                if ($plain === false) {
                    throw $this->unreadable();
                }
                $this->write($out, $plain);

                if ($final) {
                    break;
                }
            }

            if ($this->readUpTo($in, 1) !== '') {
                throw $this->unreadable();
            }
        });
    }

    private function sealChunk(string $fileKey, string $salt, int $index, bool $final, string $plain): string
    {
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::CIPHER, $fileKey, OPENSSL_RAW_DATA, $this->iv($index), $tag, $this->aad($salt, $index, $final), self::TAG_BYTES);
        if ($cipherText === false) {
            throw new RuntimeException('暗号化に失敗しました。');
        }

        return pack('N', strlen($cipherText)).($final ? "\x01" : "\x00").$cipherText.$tag;
    }

    private function fileKey(string $salt): string
    {
        return hash_hkdf('sha256', $this->masterKey, self::KEY_BYTES, 'mtw-backup-v1', $salt);
    }

    private function iv(int $index): string
    {
        return str_repeat("\0", 8).pack('N', $index);
    }

    private function aad(string $salt, int $index, bool $final): string
    {
        return self::MAGIC.$salt.pack('N', $index).($final ? "\x01" : "\x00");
    }

    /**
     * 失敗したら書きかけの出力ファイルを消す。
     *
     * @param  callable(resource, resource): void  $body
     */
    private function transform(string $sourcePath, string $destinationPath, callable $body): void
    {
        $in = $this->open($sourcePath, 'rb');
        $out = null;

        try {
            $out = $this->open($destinationPath, 'wb');
            $body($in, $out);
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
                $out = null;
                @unlink($destinationPath);
            }
            throw $e;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException('ファイルを開けません: '.$path);
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function readUpTo($handle, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes && ! feof($handle)) {
            $chunk = fread($handle, $bytes - strlen($data));
            if ($chunk === false) {
                throw new RuntimeException('ファイルの読み込みに失敗しました。');
            }
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('ファイルの書き込みに失敗しました（空き容量を確認してください）。');
            }
            $written += $result;
        }
    }

    private function unreadable(): RuntimeException
    {
        return new RuntimeException('バックアップファイルを復号できません（鍵が違う・ファイルが壊れている・途中で切れている可能性があります）。');
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/BackupCipherTest.php`
Expected: PASS（18 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Support/Backup/BackupCipher.php tests/Unit/Backup/BackupCipherTest.php
git commit -m "feat(backup): バックアップファイルを AES-256-GCM で暗号化・復号する"
```

---

## Task 2: 添付の一覧と「送る必要があるファイル」の判定

> レビューで API・キーの形式に変更が入りました。「実装中の変更（レビュー反映）」を参照してください（最終的にはリポジトリのコードが正）。

**Files:**
- Create: `app/Support/Backup/FileSyncPlanner.php`, `app/Support/Backup/StorageFileScanner.php`
- Test: `tests/Unit/Backup/FileSyncPlannerTest.php`, `tests/Unit/Backup/StorageFileScannerTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Backup/FileSyncPlannerTest.php`:

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\FileSyncPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileSyncPlannerTest extends TestCase
{
    public static function paths(): array
    {
        return [
            '英数字' => ['public/attachments/contracts/12/abc123.pdf'],
            '日本語と空白と記号' => ['public/attachments/contracts/12/契約書 (最終版)+1.pdf'],
            '非公開フォルダ' => ['private/approvals/7/見積.xlsx'],
        ];
    }

    #[DataProvider('paths')]
    public function test_key_uses_only_safe_characters_and_maps_back_to_the_path(string $path): void
    {
        $key = FileSyncPlanner::keyFor($path);

        $this->assertMatchesRegularExpression('#^files/[A-Za-z0-9_-]+\.enc$#', $key);
        $this->assertSame($path, FileSyncPlanner::pathFor($key));
    }

    public static function unsafeKeys(): array
    {
        $encode = fn (string $path) => 'files/'.rtrim(strtr(base64_encode($path), '+/', '-_'), '=').'.enc';

        return [
            'db のキー' => ['db/manage-20260912-030000.sql.gz.enc'],
            '使えない文字' => ['files/abc!.enc'],
            '上のフォルダへ出る' => [$encode('public/../../etc/passwd')],
            '絶対パス' => [$encode('/etc/passwd')],
            '空の区切り' => [$encode('public//a.txt')],
        ];
    }

    #[DataProvider('unsafeKeys')]
    public function test_unexpected_or_dangerous_keys_map_to_null(string $key): void
    {
        $this->assertNull(FileSyncPlanner::pathFor($key));
    }

    public function test_only_missing_or_changed_files_are_uploaded(): void
    {
        $local = [
            'public/a.pdf' => 100,
            'public/b.pdf' => 200,
            'private/c.xlsx' => 300,
        ];
        $remote = [
            FileSyncPlanner::keyFor('public/a.pdf') => BackupCipher::encryptedSize(100), // 送信済み
            FileSyncPlanner::keyFor('public/b.pdf') => BackupCipher::encryptedSize(150), // 大きさが違う
            FileSyncPlanner::keyFor('public/gone.pdf') => 10,                             // 手元から消えたもの（何もしない）
        ];

        $this->assertSame(['private/c.xlsx', 'public/b.pdf'], FileSyncPlanner::filesToUpload($local, $remote));
    }
}
```

`tests/Unit/Backup/StorageFileScannerTest.php`:

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\StorageFileScanner;
use PHPUnit\Framework\TestCase;

class StorageFileScannerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/scanner-'.bin2hex(random_bytes(4));
        $this->put('public/.gitignore', "*\n");
        $this->put('public/a.txt', 'aaa');
        $this->put('public/sub/b.txt', 'bb');
        $this->put('private/c.txt', 'c');
        $this->put('other/d.txt', 'dddd');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_lists_files_under_the_roots_with_their_sizes(): void
    {
        $this->assertSame(
            ['private/c.txt' => 1, 'public/a.txt' => 3, 'public/sub/b.txt' => 2],
            StorageFileScanner::scan($this->base, ['public', 'private']),
        );
    }

    public function test_missing_root_is_ignored(): void
    {
        $this->assertSame(['public/a.txt' => 3, 'public/sub/b.txt' => 2], StorageFileScanner::scan($this->base, ['public', 'nothing']));
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->base.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/FileSyncPlannerTest.php tests/Unit/Backup/StorageFileScannerTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

`app/Support/Backup/FileSyncPlanner.php`:

```php
<?php

namespace App\Support\Backup;

/**
 * storage/app 配下のファイルと、保管先のキー（files/…）の対応。
 *
 * パスには日本語や空白が入る一方、オブジェクトストレージのキーに使える記号は限られるため、
 * 相対パスを base64url にしてキーにする（復元時に元のパスへ戻せる）。
 */
final class FileSyncPlanner
{
    public const PREFIX = 'files/';

    public static function keyFor(string $relativePath): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=').'.enc';
    }

    /**
     * キーから元の相対パスへ戻す。形式の違うキーや、危険なパス（..・絶対パス・空の区切り）は null。
     */
    public static function pathFor(string $key): ?string
    {
        if (preg_match('#^files/([A-Za-z0-9_-]+)\.enc$#', $key, $m) !== 1) {
            return null;
        }

        $base64 = strtr($m[1], '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $path = base64_decode($base64, true);

        if ($path === false || $path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * 送る必要がある相対パス（保管先に無い、または大きさが合わないもの）。
     *
     * @param  array<string, int>  $localFiles  相対パス => バイト数
     * @param  array<string, int>  $remoteObjects  キー => バイト数
     * @return list<string>
     */
    public static function filesToUpload(array $localFiles, array $remoteObjects): array
    {
        $paths = [];
        foreach ($localFiles as $path => $size) {
            if (($remoteObjects[self::keyFor($path)] ?? null) !== BackupCipher::encryptedSize($size)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }
}
```

`app/Support/Backup/StorageFileScanner.php`:

```php
<?php

namespace App\Support\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * storage/app 配下のうち、バックアップの対象フォルダにあるファイルの一覧を作る。
 */
final class StorageFileScanner
{
    /**
     * @param  list<string>  $roots  $basePath からの相対フォルダ（例: public, private）
     * @return array<string, int> 相対パス（/ 区切り） => バイト数
     */
    public static function scan(string $basePath, array $roots): array
    {
        $basePath = rtrim($basePath, '/');
        $files = [];

        foreach ($roots as $root) {
            $directory = $basePath.'/'.trim($root, '/');
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.gitignore') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($basePath) + 1);
                $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $file->getSize();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/FileSyncPlannerTest.php tests/Unit/Backup/StorageFileScannerTest.php`
Expected: PASS（11 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Support/Backup/FileSyncPlanner.php app/Support/Backup/StorageFileScanner.php tests/Unit/Backup/FileSyncPlannerTest.php tests/Unit/Backup/StorageFileScannerTest.php
git commit -m "feat(backup): 添付の一覧と未送信のファイルを判定する"
```

---

## Task 3: DB バックアップの名前と保存期間（RetentionPolicy）

**Files:**
- Create: `app/Support/Backup/RetentionPolicy.php`
- Test: `tests/Unit/Backup/RetentionPolicyTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\RetentionPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RetentionPolicyTest extends TestCase
{
    public function test_key_is_named_in_japan_time_without_colons(): void
    {
        $at = CarbonImmutable::create(2026, 9, 11, 18, 0, 0, 'UTC'); // 日本時間 9/12 3:00

        $this->assertSame('db/manage-20260912-030000.sql.gz.enc', RetentionPolicy::databaseKey($at));
    }

    public function test_backups_older_than_the_period_expire(): void
    {
        $now = CarbonImmutable::create(2026, 10, 15, 3, 0, 0, 'Asia/Tokyo');
        $keys = [
            'db/manage-20260901-030000.sql.gz.enc', // 44 日前 → 消す
            'db/manage-20260915-030000.sql.gz.enc', // ちょうど 30 日前 → 残す
            'db/manage-20261014-030000.sql.gz.enc', // 昨日 → 残す
            'db/other.txt',                          // 形式が違う → 触らない
            'db/manage-20261399-030000.sql.gz.enc', // ありえない日付 → 触らない
        ];

        $this->assertSame(['db/manage-20260901-030000.sql.gz.enc'], RetentionPolicy::expiredDatabaseKeys($keys, $now, 30));
    }

    public function test_newest_backup_is_kept_even_when_it_is_old(): void
    {
        $now = CarbonImmutable::create(2026, 10, 15, 3, 0, 0, 'Asia/Tokyo');
        $keys = ['db/manage-20260201-030000.sql.gz.enc', 'db/manage-20260101-030000.sql.gz.enc'];

        $this->assertSame(['db/manage-20260101-030000.sql.gz.enc'], RetentionPolicy::expiredDatabaseKeys($keys, $now, 30));
    }

    public function test_latest_key_is_found(): void
    {
        $keys = ['db/manage-20260912-030000.sql.gz.enc', 'db/manage-20260913-030000.sql.gz.enc', 'db/other.txt'];

        $this->assertSame('db/manage-20260913-030000.sql.gz.enc', RetentionPolicy::latestDatabaseKey($keys));
        $this->assertNull(RetentionPolicy::latestDatabaseKey(['db/other.txt']));
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/RetentionPolicyTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

```php
<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * データベースのバックアップのキー名と保存期間（要件定義書 14.6: 30 日分。最新の 1 件は必ず残す）。
 *
 * キーの日時は日本時間。オブジェクトストレージのキーに ":" は使えないので Ymd-His にする。
 */
final class RetentionPolicy
{
    public const DB_PREFIX = 'db/';

    private const TIMEZONE = 'Asia/Tokyo';

    private const KEY_PATTERN = '#^db/manage-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.sql\.gz\.enc$#';

    public static function databaseKey(CarbonInterface $at): string
    {
        return self::DB_PREFIX.'manage-'.CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE)->format('Ymd-His').'.sql.gz.enc';
    }

    /**
     * 保存期間を過ぎたキー。形式の違うキーは消さない。いちばん新しい 1 件は期間を過ぎていても残す。
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function expiredDatabaseKeys(array $keys, CarbonInterface $now, int $days): array
    {
        $dated = self::datedKeys($keys);
        if ($dated === []) {
            return [];
        }

        $newest = array_key_last($dated);
        $threshold = CarbonImmutable::instance($now)->subDays($days);

        $expired = [];
        foreach ($dated as $key => $at) {
            if ($key !== $newest && $at->lessThan($threshold)) {
                $expired[] = $key;
            }
        }

        return $expired;
    }

    /**
     * @param  list<string>  $keys
     */
    public static function latestDatabaseKey(array $keys): ?string
    {
        $dated = self::datedKeys($keys);

        return $dated === [] ? null : array_key_last($dated);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, CarbonImmutable> 古い順
     */
    private static function datedKeys(array $keys): array
    {
        $dated = [];
        foreach ($keys as $key) {
            if (preg_match(self::KEY_PATTERN, $key, $m) !== 1) {
                continue;
            }
            [, $year, $month, $day, $hour, $minute, $second] = array_map('intval', $m);
            // 存在しない日付は checkdate で弾く（strtotime の繰り上げに頼らない。RULES.md Bug #54）
            if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
                continue;
            }
            $dated[$key] = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, self::TIMEZONE);
        }
        uasort($dated, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $dated;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/RetentionPolicyTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Support/Backup/RetentionPolicy.php tests/Unit/Backup/RetentionPolicyTest.php
git commit -m "feat(backup): DB バックアップの名前と 30 日の保存期間を決める"
```

---

## Task 4: 保管先のインターフェースとローカル実装

**Files:**
- Create: `app/Support/Backup/BackupStorage.php`, `app/Support/Backup/LocalDirectoryBackupStorage.php`
- Test: `tests/Unit/Backup/LocalDirectoryBackupStorageTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\LocalDirectoryBackupStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalDirectoryBackupStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/local-storage-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/work', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_put_list_get_and_delete(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        file_put_contents($this->dir.'/work/a', 'aaa');
        file_put_contents($this->dir.'/work/b', 'bbbbb');

        $storage->put('db/x.enc', $this->dir.'/work/a');
        $storage->put('files/y.enc', $this->dir.'/work/b');

        $this->assertSame(['db/x.enc' => 3], $storage->list('db/'));
        $this->assertSame(['db/x.enc' => 3, 'files/y.enc' => 5], $storage->list(''));

        $storage->get('files/y.enc', $this->dir.'/work/copy');
        $this->assertStringEqualsFile($this->dir.'/work/copy', 'bbbbb');

        $storage->delete('db/x.enc');
        $this->assertSame([], $storage->list('db/'));
    }

    public function test_list_of_an_empty_storage_is_empty(): void
    {
        $this->assertSame([], (new LocalDirectoryBackupStorage($this->dir.'/nothing'))->list('db/'));
    }

    public function test_keys_that_escape_the_root_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new LocalDirectoryBackupStorage($this->dir.'/remote'))->delete('../outside');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/LocalDirectoryBackupStorageTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

`app/Support/Backup/BackupStorage.php`:

```php
<?php

namespace App\Support\Backup;

/**
 * バックアップの保管先（本番: さくらのオブジェクトストレージ / テスト・手元の確認: ローカルフォルダ）。
 */
interface BackupStorage
{
    /**
     * ローカルのファイルを保管先へ送る。
     */
    public function put(string $key, string $localPath): void;

    /**
     * 保管先のファイルをローカルへ取り出す。
     */
    public function get(string $key, string $localPath): void;

    /**
     * 接頭辞に合うファイルの一覧。
     *
     * @return array<string, int> キー => バイト数
     */
    public function list(string $prefix): array;

    public function delete(string $key): void;
}
```

`app/Support/Backup/LocalDirectoryBackupStorage.php`:

```php
<?php

namespace App\Support\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * ローカルフォルダを保管先にする（テストと、手元での通し確認用。本番では使わない）。
 */
final class LocalDirectoryBackupStorage implements BackupStorage
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function put(string $key, string $localPath): void
    {
        $path = $this->path($key);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('保管フォルダを作れません: '.$directory);
        }
        if (! copy($localPath, $path)) {
            throw new RuntimeException('保管に失敗しました: '.$key);
        }
    }

    public function get(string $key, string $localPath): void
    {
        $path = $this->path($key);
        if (! is_file($path) || ! copy($path, $localPath)) {
            throw new RuntimeException('取り出しに失敗しました: '.$key);
        }
    }

    public function list(string $prefix): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        $objects = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->root) + 1));
            if (str_starts_with($key, $prefix)) {
                $objects[$key] = $file->getSize();
            }
        }
        ksort($objects, SORT_STRING);

        return $objects;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('削除に失敗しました: '.$key);
        }
    }

    private function path(string $key): string
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '..')) {
            throw new RuntimeException('キーが正しくありません: '.$key);
        }

        return $this->root.'/'.$key;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/LocalDirectoryBackupStorageTest.php`
Expected: PASS（3 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Support/Backup/BackupStorage.php app/Support/Backup/LocalDirectoryBackupStorage.php tests/Unit/Backup/LocalDirectoryBackupStorageTest.php
git commit -m "feat(backup): 保管先のインターフェースとローカル実装を足す"
```

---

## Task 5: さくらのオブジェクトストレージへの保管（S3BackupStorage）

**Files:**
- Create: `app/Support/Backup/S3BackupStorage.php`
- Test: `tests/Unit/Backup/S3BackupStorageTest.php`

- [ ] **Step 1: 失敗するテストを書く**（aws-sdk の `MockHandler` で、実際には通信しない）

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\S3BackupStorage;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class S3BackupStorageTest extends TestCase
{
    private const CONFIG = [
        'endpoint' => 'https://s3.isk01.sakurastorage.jp',
        'region' => 'jp-north-1',
        'bucket' => 'manage-backup',
        'key' => 'test-access-key',
        'secret' => 'test-secret-key',
    ];

    public function test_put_sends_a_path_style_request_without_the_crc32_checksum(): void
    {
        $requests = [];
        $mock = new MockHandler();
        $mock->append(function (CommandInterface $command, RequestInterface $request) use (&$requests) {
            $requests[] = $request;

            return new Result([]);
        });
        $file = tempnam(sys_get_temp_dir(), 's3test');
        file_put_contents($file, 'hello');

        S3BackupStorage::fromConfig(self::CONFIG, $mock)->put('db/manage-20260912-030000.sql.gz.enc', $file);
        unlink($file);

        $this->assertCount(1, $requests);
        $this->assertSame('PUT', $requests[0]->getMethod());
        $this->assertSame(
            'https://s3.isk01.sakurastorage.jp/manage-backup/db/manage-20260912-030000.sql.gz.enc',
            (string) $requests[0]->getUri(),
        );
        // さくらは SDK 既定の CRC32 を検証しないため、付けない設定（when_required）になっていること
        $this->assertFalse($requests[0]->hasHeader('x-amz-checksum-crc32'));
    }

    public function test_list_collects_every_page(): void
    {
        $mock = new MockHandler([
            new Result(['Contents' => [['Key' => 'files/a.enc', 'Size' => 10]], 'IsTruncated' => true, 'NextContinuationToken' => 't1']),
            new Result(['Contents' => [['Key' => 'files/b.enc', 'Size' => 20]], 'IsTruncated' => false]),
        ]);

        $this->assertSame(
            ['files/a.enc' => 10, 'files/b.enc' => 20],
            S3BackupStorage::fromConfig(self::CONFIG, $mock)->list('files/'),
        );
    }

    public function test_delete_refuses_an_empty_or_folder_key(): void
    {
        $storage = S3BackupStorage::fromConfig(self::CONFIG, new MockHandler());

        $this->expectException(RuntimeException::class);
        $storage->delete('db/');
    }

    public function test_missing_settings_are_reported_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bucket');

        S3BackupStorage::fromConfig(['bucket' => null] + self::CONFIG);
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/S3BackupStorageTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

```php
<?php

namespace App\Support\Backup;

use Aws\S3\S3Client;
use RuntimeException;

/**
 * さくらのクラウド オブジェクトストレージ（S3 互換）に保管する。
 *
 * - パス形式の URL を使う（さくらの SDK 例に合わせる）
 * - SDK 既定の CRC32 チェックサムはさくらが検証しないため、必要なときだけにする（when_required）
 * - ACL は付けない（バケットだけに権限を絞った鍵で動かすため、Flysystem 経由にしない）
 */
final class S3BackupStorage implements BackupStorage
{
    public function __construct(private S3Client $client, private string $bucket) {}

    /**
     * @param  array{endpoint?: string|null, region?: string|null, bucket?: string|null, key?: string|null, secret?: string|null}  $config
     * @param  callable|null  $handler  テストで通信を差し替えるときだけ渡す
     */
    public static function fromConfig(array $config, ?callable $handler = null): self
    {
        foreach (['endpoint', 'region', 'bucket', 'key', 'secret'] as $name) {
            if (empty($config[$name])) {
                throw new RuntimeException("オブジェクトストレージの設定（{$name}）がありません。本番の .env を確認してください。");
            }
        }

        $options = [
            'version' => '2006-03-01',
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];
        if ($handler !== null) {
            $options['handler'] = $handler;
        }

        return new self(new S3Client($options), (string) $config['bucket']);
    }

    public function put(string $key, string $localPath): void
    {
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'SourceFile' => $localPath]);
    }

    public function get(string $key, string $localPath): void
    {
        $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $localPath]);
    }

    public function list(string $prefix): array
    {
        $objects = [];
        foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $prefix]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $objects[(string) $object['Key']] = (int) $object['Size'];
            }
        }
        ksort($objects, SORT_STRING);

        return $objects;
    }

    public function delete(string $key): void
    {
        // 空のキーやフォルダ指定で、意図しない範囲を消さないための安全策
        if ($key === '' || str_ends_with($key, '/')) {
            throw new RuntimeException('削除するキーが正しくありません: '.$key);
        }

        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/S3BackupStorageTest.php`
Expected: PASS（4 tests）。もし `x-amz-checksum-crc32` のテストだけ落ちたら、`composer show aws/aws-sdk-php` の版が 3.337 以上かを確かめる（それ未満は設定が効かない）。

- [ ] **Step 5: Commit**

```bash
git add app/Support/Backup/S3BackupStorage.php tests/Unit/Backup/S3BackupStorageTest.php
git commit -m "feat(backup): さくらのオブジェクトストレージへの保管を足す"
```

---

## Task 6: データベースの書き出し（MysqlDatabaseDumper）

**Files:**
- Create: `app/Support/Backup/DatabaseDumper.php`, `app/Support/Backup/MysqlDatabaseDumper.php`
- Test: `tests/Feature/Backup/MysqlDatabaseDumperTest.php`（Process ファサードを使うので Laravel を起動する）

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\MysqlDatabaseDumper;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class MysqlDatabaseDumperTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/dumper-'.bin2hex(random_bytes(4)).'/backup-work';
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg(dirname($this->workDir)));
        parent::tearDown();
    }

    public function test_password_goes_to_the_options_file_not_the_command_line(): void
    {
        $command = $this->dumper()->command('/tmp/opts.cnf', '/tmp/out.sql');

        $this->assertSame('/usr/local/bin/mysqldump', $command[0]);
        $this->assertSame('--defaults-extra-file=/tmp/opts.cnf', $command[1]);
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--set-gtid-purged=OFF', $command);
        $this->assertSame(['--result-file=/tmp/out.sql', 'manage_db'], array_slice($command, -2));
        foreach ($command as $part) {
            $this->assertStringNotContainsString('p@ss', $part);
        }
    }

    public function test_options_file_holds_the_credentials(): void
    {
        $this->assertSame(
            "[client]\nuser=\"manage_user\"\npassword=\"p@ss w0rd\\\\x\"\nhost=\"mysql.example.jp\"\nport=3306\n",
            $this->dumper()->optionsFileContents(),
        );
    }

    public function test_extra_options_come_just_before_the_result_file(): void
    {
        $dumper = new MysqlDatabaseDumper(['database' => 'manage_db', 'username' => 'u'], 'mysqldump', ' --column-statistics=0  --hex-blob ', 60, $this->workDir);

        $this->assertSame(
            ['--column-statistics=0', '--hex-blob', '--result-file=/tmp/out.sql', 'manage_db'],
            array_slice($dumper->command('/tmp/o.cnf', '/tmp/out.sql'), -4),
        );
    }

    public function test_unix_socket_replaces_host_and_port(): void
    {
        $contents = $this->dumper(['unix_socket' => '/tmp/mysql.sock'])->optionsFileContents();

        $this->assertStringContainsString('socket="/tmp/mysql.sock"', $contents);
        $this->assertStringNotContainsString('host=', $contents);
    }

    public function test_successful_dump_removes_the_options_file(): void
    {
        Process::fake(function (PendingProcess $process) {
            foreach ((array) $process->command as $part) {
                if (str_starts_with($part, '--result-file=')) {
                    file_put_contents(substr($part, strlen('--result-file=')), "-- dump\n");
                }
            }

            return Process::result();
        });

        $this->dumper()->dumpTo($this->workDir.'/dump.sql');

        $this->assertStringEqualsFile($this->workDir.'/dump.sql', "-- dump\n");
        $this->assertSame([$this->workDir.'/dump.sql'], glob($this->workDir.'/*'));
        Process::assertRan(fn (PendingProcess $process) => $process->timeout === 1800);
    }

    public function test_failed_dump_reports_the_error_and_removes_the_options_file(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Access denied for user', exitCode: 2));

        try {
            $this->dumper()->dumpTo($this->workDir.'/dump.sql');
            $this->fail('例外が出なかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Access denied', $e->getMessage());
        }
        $this->assertSame([], glob($this->workDir.'/*'));
    }

    public function test_missing_dump_file_is_an_error(): void
    {
        Process::fake();

        $this->expectExceptionMessage('ダンプファイルが作られませんでした');
        $this->dumper()->dumpTo($this->workDir.'/dump.sql');
    }

    public function test_quote_in_credentials_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->dumper(['password' => 'a"b'])->optionsFileContents();
    }

    private function dumper(array $overrides = []): MysqlDatabaseDumper
    {
        return new MysqlDatabaseDumper(array_merge([
            'host' => 'mysql.example.jp',
            'port' => '3306',
            'database' => 'manage_db',
            'username' => 'manage_user',
            'password' => 'p@ss w0rd\\x',
        ], $overrides), '/usr/local/bin/mysqldump', '', 1800, $this->workDir);
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Backup/MysqlDatabaseDumperTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

`app/Support/Backup/DatabaseDumper.php`:

```php
<?php

namespace App\Support\Backup;

interface DatabaseDumper
{
    /**
     * データベース全体（テーブル定義とデータ）を SQL ファイルに書き出す。
     */
    public function dumpTo(string $path): void;
}
```

`app/Support/Backup/MysqlDatabaseDumper.php`:

```php
<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * mysqldump でデータベース全体を書き出す。
 *
 * パスワードはコマンドライン（ps で他人に見える）ではなく、0600 の一時設定ファイル（--defaults-extra-file）で渡し、
 * 終わったら必ず消す。本番のテーブルは SQL ファイルで作られているため、定義も含めて丸ごと書き出す。
 */
final class MysqlDatabaseDumper implements DatabaseDumper
{
    /**
     * @param  array<string, mixed>  $connection  config('database.connections.mysql') の形
     */
    public function __construct(
        private array $connection,
        private string $binary,
        private string $extraOptions,
        private int $timeout,
        private string $workDir,
    ) {}

    public static function fromConfig(): self
    {
        $name = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}");
        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('データベースのバックアップは MySQL にだけ対応しています。');
        }

        return new self(
            $connection,
            (string) config('backup.mysqldump.binary'),
            (string) config('backup.mysqldump.extra_options'),
            (int) config('backup.mysqldump.timeout'),
            (string) config('backup.work_dir'),
        );
    }

    public function dumpTo(string $path): void
    {
        $optionsFile = $this->writeOptionsFile();

        try {
            $result = Process::timeout($this->timeout)->run($this->command($optionsFile, $path));

            if ($result->failed()) {
                throw new RuntimeException('mysqldump が失敗しました: '.trim($result->errorOutput()));
            }
            if (! is_file($path) || filesize($path) === 0) {
                throw new RuntimeException('ダンプファイルが作られませんでした。');
            }
        } finally {
            if (is_file($optionsFile)) {
                unlink($optionsFile);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function command(string $optionsFile, string $path): array
    {
        $extra = preg_split('/\s+/', trim($this->extraOptions), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            $this->binary,
            '--defaults-extra-file='.$optionsFile, // mysqldump の決まりで、最初のオプションに置く
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--default-character-set=utf8mb4',
            ...$extra,
            '--result-file='.$path,
            (string) $this->connection['database'],
        ];
    }

    public function optionsFileContents(): string
    {
        $lines = ['[client]', 'user='.$this->quote((string) $this->connection['username'])];

        $password = (string) ($this->connection['password'] ?? '');
        if ($password !== '') {
            $lines[] = 'password='.$this->quote($password);
        }

        if (! empty($this->connection['unix_socket'])) {
            $lines[] = 'socket='.$this->quote((string) $this->connection['unix_socket']);
        } else {
            $lines[] = 'host='.$this->quote((string) ($this->connection['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($this->connection['port'] ?? 3306);
        }

        return implode("\n", $lines)."\n";
    }

    private function writeOptionsFile(): string
    {
        if (! is_dir($this->workDir) && ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException('作業フォルダを作れません: '.$this->workDir);
        }

        $file = $this->workDir.'/mysqldump-'.bin2hex(random_bytes(8)).'.cnf';
        $previousUmask = umask(0077); // 作った瞬間から本人しか読めないようにする
        try {
            $written = file_put_contents($file, $this->optionsFileContents(), LOCK_EX);
        } finally {
            umask($previousUmask);
        }

        if ($written === false) {
            throw new RuntimeException('一時の設定ファイルを書けません。');
        }

        return $file;
    }

    private function quote(string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('データベースの接続設定に、設定ファイルで扱えない文字（" や改行）が含まれています。');
        }

        return '"'.str_replace('\\', '\\\\', $value).'"';
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Backup/MysqlDatabaseDumperTest.php`
Expected: PASS（8 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Support/Backup/DatabaseDumper.php app/Support/Backup/MysqlDatabaseDumper.php tests/Feature/Backup/MysqlDatabaseDumperTest.php
git commit -m "feat(backup): mysqldump でデータベースを書き出す（パスワードは一時ファイルで渡す）"
```

---

## Task 7: バックアップ本体（BackupRunner）と設定ファイル

**Files:**
- Create: `config/backup.php`, `app/Support/Backup/BackupSummary.php`, `app/Support/Backup/BackupRunner.php`
- Test: `tests/Unit/Backup/BackupRunnerTest.php`

- [ ] **Step 1: 設定ファイルを作る**（`config/backup.php`。コマンドは `env()` ではなく `config('backup.*')` を読む。本番は deploy で `config:cache` されるため）

```php
<?php

/*
|--------------------------------------------------------------------------
| 夜間バックアップ（要件定義書 14.6 / 手順書 docs/運用_バックアップとメール.md）
|--------------------------------------------------------------------------
|
| 毎晩、データベース全体と storage/app 配下のファイルを暗号化して、
| さくらのオブジェクトストレージへ送る。秘密情報は本番の .env にだけ書く。
|
*/

return [

    // 保管先: s3（本番） / local（手元での通し確認用）
    'storage' => env('BACKUP_STORAGE', 's3'),

    // local のときの保管フォルダ（deploy.sh は storage/ を本番へ送るため、既定はリポジトリの外）
    'local_root' => env('BACKUP_LOCAL_ROOT', sys_get_temp_dir().'/manage-backup-local'),

    // 暗号化キー（base64 の 32 バイト）。`php artisan ops:backup-key` で作り、紙にも控える
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // 失敗を知らせる宛先（カンマ区切りで複数可）
    'notify_to' => env('BACKUP_NOTIFY_TO'),

    // データベースのバックアップを残す日数（最新の 1 件は日数に関係なく残す）
    'retention_days' => 30,

    // 平文のダンプを一時的に置く作業フォルダ。名前は backup-work 以外を受け付けない
    'work_dir' => env('BACKUP_WORK_DIR', storage_path('app/backup-work')),

    // storage/app からの相対パスで、バックアップするフォルダ
    'file_roots' => ['public', 'private'],

    'mysqldump' => [
        'binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        // 環境に合わせて足すオプション（空白区切り）
        'extra_options' => env('BACKUP_MYSQLDUMP_EXTRA_OPTIONS', ''),
        'timeout' => 1800,
    ],

    // さくらのクラウド オブジェクトストレージ（S3 互換・石狩第1）
    's3' => [
        'endpoint' => env('BACKUP_S3_ENDPOINT', 'https://s3.isk01.sakurastorage.jp'),
        'region' => env('BACKUP_S3_REGION', 'jp-north-1'),
        'bucket' => env('BACKUP_S3_BUCKET'),
        'key' => env('BACKUP_S3_ACCESS_KEY'),
        'secret' => env('BACKUP_S3_SECRET_KEY'),
    ],

];
```

- [ ] **Step 2: 失敗するテストを書く**

```php
<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupRunnerTest extends TestCase
{
    private string $root;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/runner-'.bin2hex(random_bytes(4));
        $this->key = BackupCipher::generateKey();
        $this->put('app/public/attachments/1/a.pdf', 'AAA');
        $this->put('app/private/approvals/2/b.xlsx', 'BB');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_backs_up_database_and_files_and_leaves_no_plain_files(): void
    {
        $summary = $this->runner()->run($this->at(2026, 9, 12));

        $this->assertSame('db/manage-20260912-030000.sql.gz.enc', $summary->databaseKey);
        $this->assertSame(2, $summary->filesUploaded);
        $this->assertSame(0, $summary->databaseBackupsDeleted);

        $this->decrypt('db/manage-20260912-030000.sql.gz.enc', $this->root.'/restored.gz');
        $this->assertSame("CREATE TABLE t (id INT);\n", gzdecode(file_get_contents($this->root.'/restored.gz')));

        $this->decrypt(FileSyncPlanner::keyFor('public/attachments/1/a.pdf'), $this->root.'/a.pdf');
        $this->assertStringEqualsFile($this->root.'/a.pdf', 'AAA');

        $this->assertSame([], glob($this->root.'/backup-work/*'));
    }

    public function test_second_run_sends_only_new_files(): void
    {
        $this->runner()->run($this->at(2026, 9, 12));
        $this->put('app/public/attachments/1/c.pdf', 'CCCC');

        $this->assertSame(1, $this->runner()->run($this->at(2026, 9, 13))->filesUploaded);
    }

    public function test_database_backups_past_the_period_are_deleted(): void
    {
        $this->put('remote/db/manage-20260801-030000.sql.gz.enc', 'old');
        $this->put('remote/db/manage-20260901-030000.sql.gz.enc', 'recent');

        $summary = $this->runner()->run($this->at(2026, 9, 12));

        $this->assertSame(1, $summary->databaseBackupsDeleted);
        $this->assertSame(
            ['db/manage-20260901-030000.sql.gz.enc', 'db/manage-20260912-030000.sql.gz.enc'],
            array_keys((new LocalDirectoryBackupStorage($this->root.'/remote'))->list('db/')),
        );
    }

    public function test_failure_still_empties_the_work_directory(): void
    {
        $failing = new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, 'partial plain dump');
                throw new RuntimeException('mysqldump が失敗しました: test');
            }
        };

        try {
            $this->runner($failing)->run($this->at(2026, 9, 12));
            $this->fail('例外が出なかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mysqldump', $e->getMessage());
        }
        $this->assertSame([], glob($this->root.'/backup-work/*'));
    }

    public function test_work_directory_must_be_named_backup_work(): void
    {
        $runner = new BackupRunner(
            $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/app/public', // 誤設定の例: 中身を消されては困るフォルダ
            $this->root.'/app',
            ['public', 'private'],
            30,
        );

        $this->expectExceptionMessage('backup-work');
        $runner->run($this->at(2026, 9, 12));
    }

    private function runner(?DatabaseDumper $dumper = null): BackupRunner
    {
        return new BackupRunner(
            $dumper ?? $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/backup-work',
            $this->root.'/app',
            ['public', 'private'],
            30,
        );
    }

    private function fakeDumper(): DatabaseDumper
    {
        return new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, "CREATE TABLE t (id INT);\n");
            }
        };
    }

    private function at(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 3, 0, 0, 'Asia/Tokyo');
    }

    private function decrypt(string $key, string $to): void
    {
        (new BackupCipher($this->key))->decryptFile($this->root.'/remote/'.$key, $to);
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
```

- [ ] **Step 3: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/BackupRunnerTest.php`
Expected: FAIL（`BackupRunner` が無い）

- [ ] **Step 4: 実装する**

`app/Support/Backup/BackupSummary.php`:

```php
<?php

namespace App\Support\Backup;

/**
 * 夜間バックアップ 1 回分の結果。
 */
final class BackupSummary
{
    public function __construct(
        public readonly string $databaseKey,
        public readonly int $filesUploaded,
        public readonly int $databaseBackupsDeleted,
    ) {}
}
```

`app/Support/Backup/BackupRunner.php`:

```php
<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * 夜間バックアップの本体（要件定義書 14.6）。
 *
 * 1. データベースを書き出し → 圧縮 → 暗号化 → db/ へ送る
 * 2. storage/app の対象フォルダのうち、未送信・大きさの違うファイルを暗号化して files/ へ送る
 * 3. 保存期間を過ぎた db/ のバックアップを消す（最新の 1 件は残す）
 * 平文のファイルは作業フォルダにだけ置き、成功しても失敗しても最後に消す。
 */
final class BackupRunner
{
    private const WORK_DIR_NAME = 'backup-work';

    /**
     * @param  list<string>  $fileRoots  $storageAppPath からの相対フォルダ
     */
    public function __construct(
        private DatabaseDumper $dumper,
        private BackupStorage $storage,
        private BackupCipher $cipher,
        private string $workDir,
        private string $storageAppPath,
        private array $fileRoots,
        private int $retentionDays,
    ) {}

    public function run(CarbonImmutable $now): BackupSummary
    {
        $this->prepareWorkDir();

        try {
            $databaseKey = $this->backupDatabase($now);
            $filesUploaded = $this->backupFiles();
            $deleted = $this->pruneDatabaseBackups($now);
        } finally {
            $this->emptyWorkDir();
        }

        return new BackupSummary($databaseKey, $filesUploaded, $deleted);
    }

    private function backupDatabase(CarbonImmutable $now): string
    {
        $sql = $this->workDir.'/dump.sql';
        $gzip = $sql.'.gz';
        $encrypted = $gzip.'.enc';

        $this->dumper->dumpTo($sql);
        $this->gzip($sql, $gzip);
        unlink($sql);
        $this->cipher->encryptFile($gzip, $encrypted);
        unlink($gzip);

        $key = RetentionPolicy::databaseKey($now);
        $this->storage->put($key, $encrypted);
        unlink($encrypted);

        return $key;
    }

    private function backupFiles(): int
    {
        $local = StorageFileScanner::scan($this->storageAppPath, $this->fileRoots);
        $remote = $this->storage->list(FileSyncPlanner::PREFIX);
        $encrypted = $this->workDir.'/file.enc';

        $count = 0;
        foreach (FileSyncPlanner::filesToUpload($local, $remote) as $relativePath) {
            $this->cipher->encryptFile($this->storageAppPath.'/'.$relativePath, $encrypted);
            $this->storage->put(FileSyncPlanner::keyFor($relativePath), $encrypted);
            unlink($encrypted);
            $count++;
        }

        return $count;
    }

    private function pruneDatabaseBackups(CarbonImmutable $now): int
    {
        $keys = array_keys($this->storage->list(RetentionPolicy::DB_PREFIX));
        $expired = RetentionPolicy::expiredDatabaseKeys($keys, $now, $this->retentionDays);

        foreach ($expired as $key) {
            $this->storage->delete($key);
        }

        return count($expired);
    }

    private function gzip(string $source, string $destination): void
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('ダンプファイルを開けません。');
        }
        $out = gzopen($destination, 'wb6');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('圧縮ファイルを作れません。');
        }

        try {
            while (! feof($in)) {
                $buffer = fread($in, 1048576);
                if ($buffer === false) {
                    throw new RuntimeException('ダンプの読み込みに失敗しました。');
                }
                if ($buffer !== '' && gzwrite($out, $buffer) === false) {
                    throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function prepareWorkDir(): void
    {
        if (basename($this->workDir) !== self::WORK_DIR_NAME) {
            throw new RuntimeException('作業フォルダの名前は backup-work にしてください（設定の誤りで別のフォルダの中身を消さないため）: '.$this->workDir);
        }
        if (! is_dir($this->workDir) && ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException('作業フォルダを作れません: '.$this->workDir);
        }
        chmod($this->workDir, 0700);
        $this->emptyWorkDir();
    }

    private function emptyWorkDir(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
```

- [ ] **Step 5: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Unit/Backup/BackupRunnerTest.php`
Expected: PASS（5 tests）

- [ ] **Step 6: Commit**

```bash
git add config/backup.php app/Support/Backup/BackupSummary.php app/Support/Backup/BackupRunner.php tests/Unit/Backup/BackupRunnerTest.php
git commit -m "feat(backup): DB と添付を暗号化して送る夜間バックアップ本体を足す"
```

---

## Task 8: `ops:backup` コマンド・失敗通知メール・部品の組み立て

**Files:**
- Create: `app/Console/Commands/BackupCommand.php`, `app/Mail/BackupFailedMail.php`, `resources/views/mail/backup-failed.blade.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Backup/BackupCommandTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Backup;

use App\Mail\BackupFailedMail;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/backup-command-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/storage/app/public/attachments/1', 0700, true);
        file_put_contents($this->root.'/storage/app/public/attachments/1/a.pdf', 'AAA');

        $this->app->useStoragePath($this->root.'/storage');
        config([
            'backup.encryption_key' => BackupCipher::generateKey(),
            'backup.notify_to' => 'admin@example.com, it@example.com',
            'backup.work_dir' => $this->root.'/backup-work',
        ]);
        $this->app->instance(BackupStorage::class, new LocalDirectoryBackupStorage($this->root.'/remote'));
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, "CREATE TABLE t (id INT);\n");
            }
        });
        $this->travelTo(CarbonImmutable::create(2026, 9, 12, 3, 0, 0, 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_backup_succeeds_and_reports_the_summary(): void
    {
        Mail::fake();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('バックアップ完了: db/manage-20260912-030000.sql.gz.enc / 添付 1 件を追加 / 古いバックアップ 0 件を削除')
            ->assertExitCode(0);

        $this->assertArrayHasKey(
            'db/manage-20260912-030000.sql.gz.enc',
            (new LocalDirectoryBackupStorage($this->root.'/remote'))->list('db/'),
        );
        Mail::assertNothingSent();
    }

    public function test_failure_sends_the_notice_to_every_recipient(): void
    {
        Mail::fake();
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                throw new RuntimeException('mysqldump が失敗しました: Access denied');
            }
        });

        $this->artisan('ops:backup')
            ->expectsOutputToContain('バックアップに失敗しました')
            ->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => $mail->hasTo('admin@example.com')
            && $mail->hasTo('it@example.com')
            && str_contains($mail->reason, 'Access denied'));
    }

    public function test_missing_encryption_key_is_reported_as_a_failure(): void
    {
        Mail::fake();
        config(['backup.encryption_key' => null]);

        $this->artisan('ops:backup')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => str_contains($mail->reason, 'BACKUP_ENCRYPTION_KEY'));
    }

    public function test_notice_is_plain_japanese_text(): void
    {
        $mail = new BackupFailedMail('オブジェクトストレージに接続できません', CarbonImmutable::create(2026, 9, 12, 3, 0, 5, 'Asia/Tokyo'));

        $mail->assertHasSubject('【要対応】基幹システムのバックアップに失敗しました');
        $mail->assertSeeInText('2026/09/12 03:00');
        $mail->assertSeeInText('オブジェクトストレージに接続できません');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Backup/BackupCommandTest.php`
Expected: FAIL（`ops:backup` が無い / `BackupFailedMail` が無い）

- [ ] **Step 3: 部品の組み立てを書く**（`app/Providers/AppServiceProvider.php` の `register()`。使うときに組み立てるので、設定が空でもアプリの起動は止まらない）

```php
<?php

namespace App\Providers;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\LocalDirectoryBackupStorage;
use App\Support\Backup\MysqlDatabaseDumper;
use App\Support\Backup\S3BackupStorage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 夜間バックアップの部品（要件定義書 14.6）
        $this->app->bind(BackupStorage::class, function () {
            return config('backup.storage') === 'local'
                ? new LocalDirectoryBackupStorage((string) config('backup.local_root'))
                : S3BackupStorage::fromConfig((array) config('backup.s3'));
        });

        $this->app->bind(DatabaseDumper::class, fn () => MysqlDatabaseDumper::fromConfig());

        $this->app->bind(BackupRunner::class, fn ($app) => new BackupRunner(
            $app->make(DatabaseDumper::class),
            $app->make(BackupStorage::class),
            new BackupCipher((string) config('backup.encryption_key')),
            (string) config('backup.work_dir'),
            storage_path('app'),
            (array) config('backup.file_roots'),
            (int) config('backup.retention_days'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
```

- [ ] **Step 4: 通知メールを書く**

`app/Mail/BackupFailedMail.php`:

```php
<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 夜間バックアップが失敗したときの知らせ（キューに積まずにすぐ送る）。
 */
class BackupFailedMail extends Mailable
{
    public function __construct(
        public string $reason,
        public CarbonInterface $failedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【要対応】基幹システムのバックアップに失敗しました');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.backup-failed');
    }
}
```

`resources/views/mail/backup-failed.blade.php`（テキストメールなので HTML の置き換えをしない `{!! !!}` を使う）:

```blade
基幹システムの夜間バックアップに失敗しました。

日時: {{ $failedAt->format('Y/m/d H:i') }}（日本時間）
理由: {!! $reason !!}

対応の手順は、手順書（docs/運用_バックアップとメール.md）の「5. 失敗したとき」を見てください。
翌日の夜も自動でバックアップを試みます。

※ このメールはシステムから自動で送っています。
```

- [ ] **Step 5: コマンドを書く**

`app/Console/Commands/BackupCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Mail\BackupFailedMail;
use App\Support\Backup\BackupRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'ops:backup';

    protected $description = 'データベースと添付ファイルを暗号化して、オブジェクトストレージへバックアップする（毎晩 3:00 に自動実行）';

    public function handle(): int
    {
        $now = CarbonImmutable::now('Asia/Tokyo');

        try {
            // 設定の誤り（鍵が無い等）もここで捕まえて知らせるため、組み立ても try の中で行う
            $summary = $this->laravel->make(BackupRunner::class)->run($now);
        } catch (Throwable $e) {
            Log::error('バックアップに失敗しました', ['exception' => $e]);
            $this->error('バックアップに失敗しました: '.$e->getMessage());
            $this->notifyFailure($e->getMessage(), $now);

            return self::FAILURE;
        }

        $message = sprintf(
            'バックアップ完了: %s / 添付 %d 件を追加 / 古いバックアップ %d 件を削除',
            $summary->databaseKey,
            $summary->filesUploaded,
            $summary->databaseBackupsDeleted,
        );
        Log::info($message);
        $this->info($message);

        return self::SUCCESS;
    }

    private function notifyFailure(string $reason, CarbonImmutable $failedAt): void
    {
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) config('backup.notify_to')))));
        if ($recipients === []) {
            return;
        }

        try {
            // キューの不調が原因の失敗でも届くよう、キューに積まずにすぐ送る
            Mail::to($recipients)->send(new BackupFailedMail($reason, $failedAt));
        } catch (Throwable $mailError) {
            Log::error('バックアップ失敗の通知メールを送れませんでした', ['exception' => $mailError]);
        }
    }
}
```

- [ ] **Step 6: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Backup/BackupCommandTest.php`
Expected: PASS（4 tests）

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/BackupCommand.php app/Mail/BackupFailedMail.php resources/views/mail/backup-failed.blade.php app/Providers/AppServiceProvider.php tests/Feature/Backup/BackupCommandTest.php
git commit -m "feat(backup): ops:backup コマンドと失敗通知メールを足す"
```

---

## Task 9: 鍵の作成・復号・取り出しのコマンド

**Files:**
- Create: `app/Console/Commands/BackupKeyCommand.php`, `app/Console/Commands/BackupDecryptCommand.php`, `app/Console/Commands/BackupRestoreCommand.php`
- Test: `tests/Feature/Backup/BackupToolsCommandTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackupToolsCommandTest extends TestCase
{
    private string $root;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/backup-tools-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->key = BackupCipher::generateKey();
        config(['backup.encryption_key' => $this->key]);
        $this->app->instance(BackupStorage::class, new LocalDirectoryBackupStorage($this->root.'/remote'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_backup_key_prints_a_new_32_byte_key(): void
    {
        $this->assertSame(0, Artisan::call('ops:backup-key'));

        $firstLine = trim((string) strtok(Artisan::output(), "\n"));
        $this->assertSame(32, strlen((string) base64_decode($firstLine, true)));
    }

    public function test_decrypt_restores_a_single_file(): void
    {
        $this->put('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/plain.txt.enc', 'destination' => $this->root.'/out.txt'])
            ->assertExitCode(0);

        $this->assertStringEqualsFile($this->root.'/out.txt', '中身');
    }

    public function test_decrypt_never_overwrites_an_existing_file(): void
    {
        $this->put('exists.txt', 'keep');
        $this->put('x.enc', 'dummy');

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/x.enc', 'destination' => $this->root.'/exists.txt'])
            ->expectsOutputToContain('上書きしません')
            ->assertExitCode(1);

        $this->assertStringEqualsFile($this->root.'/exists.txt', 'keep');
    }

    public function test_restore_brings_back_the_latest_database_and_every_file(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('添付ファイル: 2 件')
            ->assertExitCode(0);

        $this->assertSame('day12', gzdecode(file_get_contents($this->root.'/restore/db/manage-20260912-030000.sql.gz')));
        $this->assertStringEqualsFile($this->root.'/restore/files/public/attachments/1/契約書.pdf', 'PDF');
        $this->assertStringEqualsFile($this->root.'/restore/files/private/approvals/2/x.xlsx', 'XLSX');
    }

    public function test_restore_can_pick_a_database_backup_without_files(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->artisan('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--db' => 'db/manage-20260911-030000.sql.gz.enc',
            '--without-files' => true,
        ])->assertExitCode(0);

        $this->assertSame('day11', gzdecode(file_get_contents($this->root.'/restore/db/manage-20260911-030000.sql.gz')));
        $this->assertDirectoryDoesNotExist($this->root.'/restore/files');
    }

    public function test_restore_refuses_a_non_empty_destination(): void
    {
        $this->put('restore/already.txt', 'x');

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('空ではありません')
            ->assertExitCode(1);
    }

    private function makeTwoDaysOfBackups(): void
    {
        $this->put('storage/app/public/attachments/1/契約書.pdf', 'PDF');
        $this->put('storage/app/private/approvals/2/x.xlsx', 'XLSX');

        foreach ([11 => 'day11', 12 => 'day12'] as $day => $sql) {
            $dumper = new class($sql) implements DatabaseDumper
            {
                public function __construct(private string $sql) {}

                public function dumpTo(string $path): void
                {
                    file_put_contents($path, $this->sql);
                }
            };

            (new BackupRunner(
                $dumper,
                new LocalDirectoryBackupStorage($this->root.'/remote'),
                new BackupCipher($this->key),
                $this->root.'/backup-work',
                $this->root.'/storage/app',
                ['public', 'private'],
                30,
            ))->run(CarbonImmutable::create(2026, 9, $day, 3, 0, 0, 'Asia/Tokyo'));
        }
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Backup/BackupToolsCommandTest.php`
Expected: FAIL（コマンドが無い）

- [ ] **Step 3: 実装する**

`app/Console/Commands/BackupKeyCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;

class BackupKeyCommand extends Command
{
    protected $signature = 'ops:backup-key';

    protected $description = 'バックアップの暗号化キーを新しく作って表示する（.env には書き込まない）';

    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());
        $this->newLine();
        $this->info('上のキーを本番の .env の BACKUP_ENCRYPTION_KEY に書き、紙にも書き写して金庫などに保管してください。');
        $this->warn('キーを失くすとバックアップを復元できません。キーを変えた場合、それまでのバックアップは古いキーでしか復元できないため、古いキーも保管してください。');

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/BackupDecryptCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;
use RuntimeException;

class BackupDecryptCommand extends Command
{
    protected $signature = 'ops:backup-decrypt
        {source : 暗号化されたバックアップファイル（.enc）}
        {destination : 復号したファイルの保存先（すでにあるファイルは上書きしない）}';

    protected $description = 'バックアップファイルを 1 つ復号する（BACKUP_ENCRYPTION_KEY を使う）';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $destination = (string) $this->argument('destination');

        if (! is_file($source)) {
            $this->error('ファイルが見つかりません: '.$source);

            return self::FAILURE;
        }
        if (file_exists($destination)) {
            $this->error('保存先にすでにファイルがあります（上書きしません）: '.$destination);

            return self::FAILURE;
        }

        try {
            (new BackupCipher((string) config('backup.encryption_key')))->decryptFile($source, $destination);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('復号しました: '.$destination);

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/BackupRestoreCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\RetentionPolicy;
use FilesystemIterator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BackupRestoreCommand extends Command
{
    protected $signature = 'ops:backup-restore
        {destination : 取り出し先のフォルダ（空、またはまだ無いフォルダ）}
        {--db=latest : 取り出すデータベースのバックアップ（db/ から始まるキー、または latest）}
        {--without-files : 添付ファイルは取り出さない}';

    protected $description = 'バックアップを保管先から取り出して復号する（本番のデータベースやファイルには触れない）';

    public function handle(): int
    {
        $destination = rtrim((string) $this->argument('destination'), '/');

        if (file_exists($destination) && ! is_dir($destination)) {
            $this->error('取り出し先がフォルダではありません: '.$destination);

            return self::FAILURE;
        }
        if (is_dir($destination) && (new FilesystemIterator($destination))->valid()) {
            $this->error('取り出し先のフォルダが空ではありません: '.$destination);

            return self::FAILURE;
        }

        try {
            $storage = $this->laravel->make(BackupStorage::class);
            $cipher = new BackupCipher((string) config('backup.encryption_key'));
            $this->makeDirectory($destination);

            $databaseKey = $this->restoreDatabase($storage, $cipher, $destination);
            $files = $this->option('without-files') ? 0 : $this->restoreFiles($storage, $cipher, $destination);
        } catch (Throwable $e) {
            $this->error('取り出しに失敗しました: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('データベース: '.$destination.'/db/'.basename($databaseKey, '.enc'));
        $this->info("添付ファイル: {$files} 件（{$destination}/files/ 以下）");

        return self::SUCCESS;
    }

    private function restoreDatabase(BackupStorage $storage, BackupCipher $cipher, string $destination): string
    {
        $option = (string) $this->option('db');
        $key = $option === 'latest'
            ? RetentionPolicy::latestDatabaseKey(array_keys($storage->list(RetentionPolicy::DB_PREFIX)))
            : $option;

        if ($key === null || ! str_starts_with($key, RetentionPolicy::DB_PREFIX)) {
            throw new RuntimeException('データベースのバックアップが見つかりません。');
        }

        $this->makeDirectory($destination.'/db');
        $encrypted = $destination.'/db/download.tmp';
        $storage->get($key, $encrypted);
        try {
            $cipher->decryptFile($encrypted, $destination.'/db/'.basename($key, '.enc'));
        } finally {
            unlink($encrypted);
        }

        return $key;
    }

    private function restoreFiles(BackupStorage $storage, BackupCipher $cipher, string $destination): int
    {
        $count = 0;
        foreach (array_keys($storage->list(FileSyncPlanner::PREFIX)) as $key) {
            $path = FileSyncPlanner::pathFor($key);
            if ($path === null) {
                $this->warn('形式の違うキーを飛ばしました: '.$key);

                continue;
            }

            $target = $destination.'/files/'.$path;
            $this->makeDirectory(dirname($target));
            $encrypted = $target.'.download';
            $storage->get($key, $encrypted);
            try {
                $cipher->decryptFile($encrypted, $target);
            } finally {
                unlink($encrypted);
            }
            $count++;
        }

        return $count;
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('フォルダを作れません: '.$path);
        }
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Backup/BackupToolsCommandTest.php`
Expected: PASS（6 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BackupKeyCommand.php app/Console/Commands/BackupDecryptCommand.php app/Console/Commands/BackupRestoreCommand.php tests/Feature/Backup/BackupToolsCommandTest.php
git commit -m "feat(backup): 暗号化キーの作成・復号・取り出しのコマンドを足す"
```

---

## Task 10: テストメール（`ops:mail-test`）

**Files:**
- Create: `app/Mail/OpsTestMail.php`, `resources/views/mail/ops-test.blade.php`, `app/Console/Commands/MailTestCommand.php`
- Test: `tests/Feature/Ops/MailTestCommandTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Ops;

use App\Mail\OpsTestMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_test_mail_is_queued_for_the_address(): void
    {
        Mail::fake();

        $this->artisan('ops:mail-test', ['to' => 'kessai@example.com'])
            ->expectsOutputToContain('送信待ちに入れました')
            ->assertExitCode(0);

        // 送信待ち（キュー）に積むことで、定期実行によるキュー処理まで一緒に確かめられる
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('kessai@example.com'));
    }

    public function test_invalid_address_is_refused(): void
    {
        Mail::fake();

        $this->artisan('ops:mail-test', ['to' => 'not-an-address'])->assertExitCode(1);

        Mail::assertNothingQueued();
    }

    public function test_body_explains_what_the_mail_proves(): void
    {
        $mail = new OpsTestMail('2026/09/12 10:00');

        $mail->assertHasSubject('【テスト】基幹システムからのメール送信テスト');
        $mail->assertSeeInText('定期実行');
        $mail->assertSeeInText('2026/09/12 10:00');
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Ops/MailTestCommandTest.php`
Expected: FAIL（コマンドが無い）

- [ ] **Step 3: 実装する**

`app/Mail/OpsTestMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * メール送信の設定と、定期実行によるキュー処理を確かめるためのテストメール。
 */
class OpsTestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $requestedAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【テスト】基幹システムからのメール送信テスト');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-test');
    }
}
```

`resources/views/mail/ops-test.blade.php`:

```blade
基幹システムからのテストメールです。

このメールが届いていれば、メール送信の設定と定期実行（5 分おき）は正しく動いています。
依頼した日時: {{ $requestedAt }}（日本時間）

迷惑メールのフォルダに入っていた場合は、手順書（docs/運用_バックアップとメール.md）の「2.2 迷惑メール対策」を確認してください。

※ このメールはシステムから自動で送っています。
```

`app/Console/Commands/MailTestCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Mail\OpsTestMail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTestCommand extends Command
{
    protected $signature = 'ops:mail-test {to : 送り先のメールアドレス}';

    protected $description = 'メール送信の設定を確かめるため、テストメールを送信待ちに入れる';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('メールアドレスの形式が正しくありません: '.$to);

            return self::FAILURE;
        }

        Mail::to($to)->queue(new OpsTestMail(CarbonImmutable::now('Asia/Tokyo')->format('Y/m/d H:i')));
        $this->info('テストメールを送信待ちに入れました。定期実行（5 分おき）で送られます。');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Ops/MailTestCommandTest.php`
Expected: PASS（3 tests）

- [ ] **Step 5: Commit**

```bash
git add app/Mail/OpsTestMail.php resources/views/mail/ops-test.blade.php app/Console/Commands/MailTestCommand.php tests/Feature/Ops/MailTestCommandTest.php
git commit -m "feat(ops): メール送信を確かめる ops:mail-test を足す"
```

---

## Task 11: 定期実行の予定（日本時間）

**Files:**
- Modify: `config/app.php:68`（`timezone` の直後）、`routes/console.php`
- Test: `tests/Feature/Ops/ScheduleTest.php`

- [ ] **Step 1: 失敗するテストを書く**

```php
<?php

namespace Tests\Feature\Ops;

use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_queue_worker_runs_every_five_minutes_and_exits_when_empty(): void
    {
        $event = $this->event('queue:work');

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=240', $event->command);
        $this->assertStringContainsString('--tries=3', $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_backup_runs_at_three_in_the_morning_japan_time(): void
    {
        $event = $this->event('ops:backup');

        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $this->timezoneName($event));
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_application_timezone_stays_utc(): void
    {
        // 予定の時刻だけを日本時間にし、アプリ全体の日時の扱いは変えない
        $this->assertSame('UTC', config('app.timezone'));
    }

    private function event(string $needle): Event
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, $needle),
        ));
        $this->assertCount(1, $events, $needle.' の予定がちょうど 1 件あること');

        return $events[0];
    }

    private function timezoneName(Event $event): string
    {
        return $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : (string) $event->timezone;
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Ops/ScheduleTest.php`
Expected: FAIL（予定が 0 件）

- [ ] **Step 3: `config/app.php` に追記する**（`'timezone' => 'UTC',` の直後）

```php
    /*
    |--------------------------------------------------------------------------
    | Schedule Timezone
    |--------------------------------------------------------------------------
    |
    | 定期実行（routes/console.php）の時刻は日本時間で書く（夜間バックアップ 3:00 など）。
    | アプリ全体の timezone（UTC）は変えない。
    |
    */

    'schedule_timezone' => 'Asia/Tokyo',
```

- [ ] **Step 4: `routes/console.php` に予定を書く**（ファイル全体）

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 定期実行（要件定義書 15.3）
|--------------------------------------------------------------------------
|
| さくらの CRON から 5 分おきに `schedule:run` を起動する（毎分は不可・常駐プロセスは禁止）。
| 時刻は日本時間（config/app.php の schedule_timezone）。
|
*/

// 送信待ちのメールなどを、空になるまで処理して終わる（常駐させない）
Schedule::command('queue:work --stop-when-empty --max-time=240 --tries=3 --backoff=60')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// 夜間バックアップ（DB 全体と添付を暗号化してオブジェクトストレージへ）
Schedule::command('ops:backup')
    ->dailyAt('03:00')
    ->withoutOverlapping(180);
```

- [ ] **Step 5: 通ることを確かめる**

Run: `./vendor/bin/phpunit tests/Feature/Ops/ScheduleTest.php`
Expected: PASS（3 tests）

- [ ] **Step 6: Commit**

```bash
git add config/app.php routes/console.php tests/Feature/Ops/ScheduleTest.php
git commit -m "feat(ops): キュー処理（5 分おき）と夜間バックアップ（3:00）を定期実行に登録する"
```

---

## Task 12: 設定のひな形・deploy の保険・手順書

**Files:**
- Modify: `.env.example`（追記のみ。秘密ファイルの決まりにより中身は読まない）、`deploy.sh`（1 行）、`CLAUDE.md`（追記）
- Create: `docs/運用_バックアップとメール.md`

- [ ] **Step 1: `.env.example` にプレースホルダーを追記する**（新しい項目名だけ。値は空）

```bash
cat >> .env.example <<'EOF'

# 夜間バックアップ（docs/運用_バックアップとメール.md）
BACKUP_ENCRYPTION_KEY=
BACKUP_NOTIFY_TO=
BACKUP_S3_BUCKET=
BACKUP_S3_ACCESS_KEY=
BACKUP_S3_SECRET_KEY=
EOF
git diff --stat .env.example   # 6〜7 行の追加だけであること
```

- [ ] **Step 2: `deploy.sh` の rsync（[2/6]）に作業フォルダの除外を足す**（`--exclude='*.log' \` の次の行）

```bash
  --exclude='storage/app/backup-work' \
```

- [ ] **Step 3: 手順書 `docs/運用_バックアップとメール.md` を作る**

````markdown
# 運用手順書: バックアップとメール送信

対象: 基幹システム（本番: さくらのレンタルサーバ ビジネスプロ）
関連: 要件定義書 `docs/決裁申請_要件定義書_v1.md`（14.6・15.3）

## 1. この仕組みでできること

| いつ | 何をする |
|------|---------|
| 5 分おき | 送信待ちのメールを送る（決裁の通知メールの土台） |
| 毎日 3:00（日本時間） | データベース全体と添付ファイルを暗号化して、さくらのオブジェクトストレージへ送る。データベースは 30 日分を残し（最新の 1 件は必ず残す）、添付は消さずに残す |
| 失敗したとき | `BACKUP_NOTIFY_TO` のアドレスへメールで知らせる |

## 2. 最初に一度だけ行う設定

画面の名前は、さくらの画面変更で少し違うことがあります。

### 2.1 決裁専用のメールアドレスを作る
1. さくらのコントロールパネル → メール → メール一覧 → 新規追加
2. ユーザ名を決める（例: `kessai` → `kessai@mitsuwat.co.jp`）
3. パスワードを決める（2.5 で本番の .env にだけ書く。ほかには書かない）

### 2.2 迷惑メール対策（SPF・DKIM）を有効にする
1. コントロールパネル → メール → メールドメイン → `mitsuwat.co.jp` の設定 → DKIM 設定を有効にする
   （さくらのネームサーバーを使っていない場合は、表示される TXT レコードを使っている DNS に登録する）
2. コントロールパネル → ドメイン/SSL → `mitsuwat.co.jp` の設定 → メールドメイン設定で SPF を有効にする

### 2.3 バックアップ専用のバケットと鍵を作る（さくらのクラウド オブジェクトストレージ）
1. サイトは「石狩第1」。バケット名は小文字・数字・ハイフンだけ（例: `mitsuwa-manage-backup`）
2. 「パーミッション」を作り、**このバケットだけ**を「READ/WRITE」にする
3. アクセスキーを発行する（シークレットキーは発行したときにしか表示されない）
- 費用: バケット 1 つにつき月 495 円（100GiB まで含む。2026/09 時点）

### 2.4 暗号化キーを作る
本番に入って次を実行する（ターミナル）。

```
ssh mitsuwa-ud@www3586.sakura.ne.jp
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan ops:backup-key
```

表示されたキーを、①紙に書き写して金庫などに保管し、②次の 2.5 で .env に書く。
**このキーを失くすと、バックアップを復元できません。**

### 2.5 本番の .env に書く（ご自身で）
`/home/mitsuwa-ud/apps/manage/.env` に次を書く（同じ名前の行がすでにあれば、その行を書き換える）。

```
MAIL_MAILER=smtp
MAIL_HOST=（さくらの初期ドメイン。例: xxxx.sakura.ne.jp）
MAIL_PORT=587
MAIL_USERNAME=kessai@mitsuwat.co.jp
MAIL_PASSWORD=（2.1 で決めたパスワード）
MAIL_FROM_ADDRESS=kessai@mitsuwat.co.jp
MAIL_FROM_NAME="ミツワ都市開発 基幹システム"
QUEUE_CONNECTION=database
BACKUP_ENCRYPTION_KEY=（2.4 のキー）
BACKUP_NOTIFY_TO=（失敗を知らせる宛先。複数ならカンマ区切り）
BACKUP_S3_BUCKET=（2.3 のバケット名）
BACKUP_S3_ACCESS_KEY=（2.3 のアクセスキー）
BACKUP_S3_SECRET_KEY=（2.3 のシークレットキー）
```

書いたら設定を反映する。

```
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan config:cache
```

### 2.6 定期実行を登録する（コントロールパネル → スクリプト設定 → CRON 設定）
- 実行間隔: 5 分おき（分に `*/5`、ほかは `*`）
- コマンド（1 行）:

```
cd /home/mitsuwa-ud/apps/manage && /usr/bin/nice -n 19 /usr/local/php/8.3/bin/php artisan schedule:run >> /home/mitsuwa-ud/apps/manage/storage/logs/scheduler.log 2>&1
```

## 3. 動作の確かめ方
1. テストメール: `cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan ops:mail-test あなたのアドレス`
   → 5 分以内に届くこと、迷惑メールに入っていないこと
2. 手動バックアップ: `cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan ops:backup`
   → 「バックアップ完了」と出ること。バケットに `db/` と `files/` ができていること
3. 取り出しの確認: `cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan ops:backup-restore ~/restore-check`
   → `gzip -t ~/restore-check/db/*.sql.gz` がエラーなく終わること。確認したら `~/restore-check` を消す

## 4. ふだんの見方
- 失敗メールが来なければ正常に動いています
- 記録: `storage/logs/laravel.log`（完了・失敗）、`storage/logs/scheduler.log`（定期実行）

## 5. 失敗したとき

| メールの「理由」 | 考えられる原因と対処 |
|----------------|--------------------|
| 暗号化キーが正しくありません | .env の `BACKUP_ENCRYPTION_KEY` を確認し、`config:cache` をやり直す |
| オブジェクトストレージの設定（…）がありません | .env の `BACKUP_S3_*` を確認し、`config:cache` をやり直す |
| 403 / AccessDenied など | 鍵の権限（そのバケットの READ/WRITE）とバケット名を確認する |
| mysqldump が失敗しました | データベースの接続設定やディスクの空きを確認する |
| 空き容量を確認してください | サーバーのディスク容量を確認する |

直したら、2 の手動バックアップで確かめる。

## 6. 復元の手順（サーバーが壊れたときなど）
1. 新しい環境にアプリを置き、.env に `BACKUP_*` を書く（暗号化キーは金庫の紙から）
2. `php artisan ops:backup-restore /path/to/restore`（特定の日なら `--db=db/manage-YYYYMMDD-HHMMSS.sql.gz.enc`）
3. データベース: `gunzip -c /path/to/restore/db/manage-….sql.gz | mysql -u ユーザー -p データベース名`
4. ファイル: `/path/to/restore/files/public/` を `storage/app/public/` へ、`files/private/` を `storage/app/private/` へコピーする

## 7. 注意
- 暗号化キーを変えた場合、それまでのバックアップは古いキーでしか復元できません。古いキーも保管してください
- バックアップにはお客様の個人情報も含まれます。取り出したファイルは確認が済んだらすぐ消してください
````

- [ ] **Step 4: `CLAUDE.md` の「Server environment」の最後に追記する**

```markdown
- 定期実行: さくらの CRON（5 分おき・1 件）が `schedule:run` を起動（予定は `routes/console.php`、時刻は `config/app.php` の `schedule_timezone`=Asia/Tokyo）。キューは database で、`queue:work --stop-when-empty` を 5 分おきに回す（常駐禁止のため）
- 夜間バックアップ: `ops:backup`（3:00）→ DB 全体と `storage/app/{public,private}` を AES-256-GCM で暗号化してさくらのオブジェクトストレージへ。本番に sodium は無い。手順は @docs/運用_バックアップとメール.md
```

- [ ] **Step 5: Commit**

```bash
git add .env.example deploy.sh CLAUDE.md "docs/運用_バックアップとメール.md"
git commit -m "docs(ops): バックアップとメール送信の手順書と設定のひな形を足す"
```

---

## Task 13: 全テスト・書式・セルフレビュー（worktree）

- [ ] **Step 1: 全テスト**

```bash
./vendor/bin/phpunit 2>&1 | tail -3
```
Expected: `OK`。件数は Task 0 の基準値＋ 265（`tests/Unit/Backup`・`tests/Feature/Backup`・`tests/Feature/Ops` の合計。Task 13 の手直しの後の値、2026-09-13 時点）。既存テストが 1 件でも落ちたら原因を調べる（消したり飛ばしたりしない）。当初の案の内訳（暗号化 18・添付 11・保存期間 4・ローカル保管 3・S3 4・ダンプ 8・本体 5・ops:backup 4・道具 6・テストメール 3・予定 3＝69）は、その後のレビューでの手直し（Task 1〜13）で件数が増えている。

- [ ] **Step 2: 書式（新しく触ったファイルだけ）**

```bash
./vendor/bin/pint --test app/Support/Backup app/Console/Commands app/Mail app/Providers/AppServiceProvider.php config/backup.php routes/console.php tests/Unit/Backup tests/Feature/Backup tests/Feature/Ops
```
差分が出たら同じ範囲で `./vendor/bin/pint …`（`--test` なし）→ 全テスト → `git commit -m "style: バックアップ関連の書式を整える"`

- [ ] **Step 3: セルフレビュー**: code-review スキルでブランチの差分をレビューし、指摘を直す（直したら全テスト → コミット）。特に確かめる点: 秘密情報がログ・出力・メールに出ないこと／平文のファイルが必ず消えること／削除が `db/` の期限切れだけに限られること。

---

## Task 14: 取り込みと手元での通し確認（main repo）

- [ ] **Step 1: 13.x へ取り込み、本番用の vendor を作る**（main repo の cwd で）

```bash
cd /Users/masanori/site/manage
git merge --ff-only feature/approval-phase0-backup
composer install --no-dev --no-interaction
composer dump-autoload --no-dev --optimize
test ! -e vendor/bin/phpunit && test -d vendor/aws/aws-sdk-php && echo "vendor OK"
grep -n "PHP_VERSION_ID >=" vendor/composer/platform_check.php   # 80300 のみ
```

- [ ] **Step 2: 手元の DB で通し確認**（本物の mysqldump・暗号化・復号。保管先は /tmp のローカルフォルダ。秘密ファイルは読まず、環境変数で上書きする）

```bash
cd /Users/masanori/site/manage
test -f bootstrap/cache/config.php && php artisan config:clear
SMOKE=/tmp/manage-smoke-$$ && mkdir -p "$SMOKE"
export BACKUP_STORAGE=local BACKUP_LOCAL_ROOT="$SMOKE/remote" BACKUP_WORK_DIR="$SMOKE/backup-work" BACKUP_NOTIFY_TO=
export BACKUP_ENCRYPTION_KEY="$(php artisan ops:backup-key | head -1)"
php artisan ops:backup
php artisan ops:backup-restore "$SMOKE/restore"
gzip -t "$SMOKE"/restore/db/*.sql.gz && gunzip -c "$SMOKE"/restore/db/*.sql.gz | grep -c "CREATE TABLE"
diff -r -x .gitignore storage/app/public "$SMOKE/restore/files/public" && echo "files identical"
ls -A "$SMOKE/backup-work"   # 空であること（平文が残っていない）
rm -rf "$SMOKE"
```
Expected: 「バックアップ完了」、テーブル数が 1 以上、`files identical`、作業フォルダが空。

---

## Task 15: 本番への反映（各ステップでユーザーの確認を取ってから進める）

- [ ] **Step 1: 事前確認**（2026-09-11 に済み）: sodium 無し → OpenSSL 採用 / 必要なテーブルはすべてあり / mysqldump 8.0.35 / crontab 0 件 / ホームは `/home/mitsuwa-ud`
- [ ] **Step 2: ユーザー作業**: 手順書 2.1〜2.3（メールアドレス・SPF/DKIM・バケットと鍵）
- [ ] **Step 3: デプロイ**（ユーザーの了承後）: main repo で `./deploy.sh`。この時点では CRON も .env も未設定なので、動きは何も変わらない
- [ ] **Step 4: ユーザー作業**: 手順書 2.4・2.5（暗号化キーの作成と紙での保管、.env の記入、`config:cache`）
- [ ] **Step 5: メールの確認**（SSH。`/bin/sh` のヒアドキュメント形式で送る）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan ops:mail-test （ユーザーのアドレス） && /usr/local/php/8.3/bin/php artisan queue:work --stop-when-empty --tries=3
SH
```
→ ユーザーに、届いたこと・迷惑メールに入っていないこと（ヘッダーに `dkim=pass`）を確かめてもらう

- [ ] **Step 6: 手動バックアップ**: 同じ形で `artisan ops:backup` → 「バックアップ完了」。ユーザーにバケットの `db/`・`files/` を確かめてもらう
- [ ] **Step 7: 取り出しの確認**: `artisan ops:backup-restore ~/restore-check` → `gzip -t ~/restore-check/db/*.sql.gz`、`find ~/restore-check/files -type f | wc -l` と `find ~/apps/manage/storage/app/public ~/apps/manage/storage/app/private -type f ! -name .gitignore | wc -l` が一致 → ユーザーの了承を得て `rm -rf ~/restore-check`
- [ ] **Step 8: ユーザー作業**: 手順書 2.6（CRON を 5 分おきで 1 件登録）
- [ ] **Step 9: 翌朝の確認**: `storage/logs/laravel.log` に 3:00 台の「バックアップ完了」、`scheduler.log` にエラーが無いこと、バケットに新しい `db/manage-YYYYMMDD-030000…` があること、失敗メールが来ていないこと
- [ ] **Step 10: 記録**: 引き継ぎメモ（memory）を更新。GitHub への push はユーザーの指示があるときだけ

---

## 確かめ方（まとめ）

| 段階 | 何で確かめるか |
|------|--------------|
| 部品ごと | Task 1〜11 の自動テスト（暗号の改ざん・切断・鍵違いの検出、保存期間の境目、パスワードがコマンドに出ないこと、失敗時の通知、予定の時刻が日本時間） |
| 全体 | 全テスト（既存 1315 件＋新規 265 件＝1580 件）が通ること、書式、セルフレビュー |
| 手元の実物 | 本物の mysqldump で手元の DB を暗号化 → 取り出し → gzip の検査とファイルの一致（Task 14） |
| 本番 | テストメールの到着と DKIM、手動バックアップ、取り出しの検査、翌朝の自動実行（Task 15） |

## この計画の外（別の計画で扱う）
- 段階1〜6（基幹の改修・決裁の本体・通知と催促・PDF と台帳・過去分の取り込み・受け入れ）
- deploy.sh が手元の `storage/` を本番へ送る問題（別作業として切り出し済み）
- 本番の php.ini のアップロード上限（段階2 の添付 10MB に向けて。要件定義書 15.7）
