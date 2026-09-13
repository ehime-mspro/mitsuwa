<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class BackupToolsCommandTest extends TestCase
{
    private const ASK_KEY_QUESTION = '暗号化キーを入力してください（画面には表示されません）';

    private const WITHOUT_DB_HINT = '添付だけを取り出すときは --without-db を、別のキーを使うときは --ask-key を付けてください。';

    private const AVAILABLE_DB_HEADING = '使えるデータベースのバックアップ（新しい順）:';

    // さくらでは `php` とだけ打つと既定の PHP 7.4 が動いてしまうため、案内は実装と同じく PHP_BINARY から組み立てる
    private const OTHER_LOCATION_TEMPLATE = '今のキーの置き場所に無い添付が、ほかの暗号化キーの置き場所に %d 件あります（キーを変える前に消した添付も含まれます）。取り出すには、空の取り出し先を指定して '.PHP_BINARY.' artisan ops:backup-restore <取り出し先> --without-db --ask-key を実行し、そのときのキーを入力してください。';

    private const ABORT_TEMPLATE = '保管先から続けて %d 件取り出せなかったため、打ち切りました（保管先の不調の可能性があります）。';

    private const INSIDE_BACKUP_ROOT = '取り出し先を、バックアップの対象フォルダ（storage/app/public・private）の中にはできません（翌晩のバックアップに、取り出したファイルが入ってしまうため）。';

    private const DECRYPT_INSIDE_BACKUP_ROOT = '保存先を、バックアップの対象フォルダ（storage/app/public・private）の中にはできません（復号した中身が公開のフォルダに置かれたり、翌晩のバックアップに入ったりするため）。';

    private const DB_NOT_FOUND_PREFIX = '指定されたデータベースのバックアップがありません: ';

    private const KEY_ID_PREFIX = 'キーの識別番号: ';

    private const ALREADY_CONFIGURED_WARNING = 'すでに BACKUP_ENCRYPTION_KEY が設定されています。キーを変えると、次のバックアップで添付をすべて送り直します（手順書の「7. 注意」を見てください）。';

    private const DOT_DOT_REFUSAL_PREFIX = '取り出し先に「..」を含めないでください: ';

    private const SYMLINK_REFUSAL_PREFIX = '取り出し先にシンボリックリンクは使えません: ';

    private const GETCWD_FALSE_MESSAGE = '今いるフォルダを確認できません。取り出し先は絶対パスで指定してください。';

    private const MALFORMED_KEY_MESSAGE = '入力したキーの形式が正しくありません（base64 の 32 バイトのキーを入力してください）。';

    private const TERMINAL_CANNOT_HIDE_MESSAGE = '画面に表示せずにキーを入力できない端末です。SSH で直接ログインした端末で実行してください。';

    private const FAILURE_HEADING_LONG_TEMPLATE = '取り出せなかった添付（先頭 %d 件）:';

    private const UNTRIED_COUNT_TEMPLATE = '打ち切りで試していない添付: %d 件';

    private const FAILED_COUNT_TEMPLATE = '取り出せなかった添付: %d 件';

    private string $root;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/backup-tools-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->key = BackupCipher::generateKey();
        config(['backup.encryption_key' => $this->key]);
        $this->app->useStoragePath($this->root.'/storage');
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
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/plain.txt.enc', 'destination' => $this->root.'/out.txt'])
            ->assertExitCode(0);

        $this->assertStringEqualsFile($this->root.'/out.txt', '中身');
    }

    public function test_decrypt_never_overwrites_an_existing_file(): void
    {
        $this->putFile('exists.txt', 'keep');
        $this->putFile('x.enc', 'dummy');

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
        $this->assertDirectoryDoesNotExist($this->root.'/restore/.work');
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
        $this->putFile('restore/already.txt', 'x');

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('空ではありません')
            ->assertExitCode(1);
    }

    public function test_one_broken_file_is_reported_and_the_rest_are_restored(): void
    {
        $this->makeTwoDaysOfBackups();
        $keyId = (new BackupCipher($this->key))->keyId();
        file_put_contents($this->root.'/remote/'.FileSyncPlanner::keyFor('private/approvals/2/x.xlsx', $keyId), 'broken');

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('取り出せなかった添付: 1 件')
            ->expectsOutputToContain('private/approvals/2/x.xlsx')
            ->assertExitCode(1);

        $this->assertStringEqualsFile($this->root.'/restore/files/public/attachments/1/契約書.pdf', 'PDF');
        $this->assertFileDoesNotExist($this->root.'/restore/files/private/approvals/2/x.xlsx');
        $this->assertDirectoryDoesNotExist($this->root.'/restore/.work');
        $this->assertSame([], glob($this->root.'/restore/files/private/approvals/2/*.part'));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->root.'/restore/files/public/attachments/1')), -4));
    }

    public function test_restore_with_both_without_flags_does_nothing(): void
    {
        $this->artisan('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--without-db' => true,
            '--without-files' => true,
        ])
            ->expectsOutputToContain('取り出すものがありません')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->root.'/restore');
    }

    public function test_without_db_restores_files_only(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--without-db' => true])
            ->expectsOutputToContain('添付ファイル: 2 件')
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->root.'/restore/db');
        $this->assertStringEqualsFile($this->root.'/restore/files/public/attachments/1/契約書.pdf', 'PDF');
    }

    public function test_ask_key_restores_using_the_previous_key(): void
    {
        $this->makeTwoDaysOfBackups();
        $previousKey = $this->key;
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--ask-key' => true])
            ->expectsQuestion(self::ASK_KEY_QUESTION, $previousKey)
            ->assertExitCode(0);

        $this->assertSame('day12', gzdecode(file_get_contents($this->root.'/restore/db/manage-20260912-030000.sql.gz')));
        $this->assertStringEqualsFile($this->root.'/restore/files/public/attachments/1/契約書.pdf', 'PDF');
    }

    public function test_ask_key_shows_the_entered_keys_id(): void
    {
        $this->makeTwoDaysOfBackups();
        $previousKey = $this->key;
        $previousKeyId = (new BackupCipher($previousKey))->keyId();
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--ask-key' => true])
            ->expectsQuestion(self::ASK_KEY_QUESTION, $previousKey)
            ->expectsOutputToContain('入力したキーの識別番号: '.$previousKeyId)
            ->assertExitCode(0);
    }

    public function test_ask_key_with_empty_input_is_reported_without_mentioning_the_env_variable(): void
    {
        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--ask-key' => true])
            ->expectsQuestion(self::ASK_KEY_QUESTION, '')
            ->expectsOutputToContain('暗号化キーが入力されませんでした。')
            ->doesntExpectOutputToContain('BACKUP_ENCRYPTION_KEY')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->root.'/restore');
    }

    public function test_ask_key_with_a_malformed_key_is_reported_without_mentioning_the_env_variable(): void
    {
        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--ask-key' => true])
            ->expectsQuestion(self::ASK_KEY_QUESTION, 'not-a-valid-key')
            ->expectsOutputToContain(self::MALFORMED_KEY_MESSAGE)
            ->doesntExpectOutputToContain('BACKUP_ENCRYPTION_KEY')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->root.'/restore');
    }

    public function test_ask_key_reports_a_terminal_that_cannot_hide_input(): void
    {
        // secret() の呼び出しに expectsQuestion() を用意しないと、Laravel のテスト用ハーネスが
        // 例外を出す。これを使って「secret() が例外を出したとき」の分岐を検証する
        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--ask-key' => true])
            ->expectsOutputToContain(self::TERMINAL_CANNOT_HIDE_MESSAGE)
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->root.'/restore');
    }

    public function test_decrypt_ask_key_uses_the_provided_key(): void
    {
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');
        $previousKey = $this->key;
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        $this->artisan('ops:backup-decrypt', [
            'source' => $this->root.'/plain.txt.enc',
            'destination' => $this->root.'/out.txt',
            '--ask-key' => true,
        ])
            ->expectsQuestion(self::ASK_KEY_QUESTION, $previousKey)
            ->assertExitCode(0);

        $this->assertStringEqualsFile($this->root.'/out.txt', '中身');
    }

    public function test_decrypt_ask_key_with_empty_input_is_reported_without_mentioning_the_env_variable(): void
    {
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');

        $this->artisan('ops:backup-decrypt', [
            'source' => $this->root.'/plain.txt.enc',
            'destination' => $this->root.'/out.txt',
            '--ask-key' => true,
        ])
            ->expectsQuestion(self::ASK_KEY_QUESTION, '')
            ->expectsOutputToContain('暗号化キーが入力されませんでした。')
            ->doesntExpectOutputToContain('BACKUP_ENCRYPTION_KEY')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->root.'/out.txt');
    }

    public function test_decrypt_ask_key_with_a_malformed_key_is_reported_without_mentioning_the_env_variable(): void
    {
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');

        $this->artisan('ops:backup-decrypt', [
            'source' => $this->root.'/plain.txt.enc',
            'destination' => $this->root.'/out.txt',
            '--ask-key' => true,
        ])
            ->expectsQuestion(self::ASK_KEY_QUESTION, 'not-a-valid-key')
            ->expectsOutputToContain(self::MALFORMED_KEY_MESSAGE)
            ->doesntExpectOutputToContain('BACKUP_ENCRYPTION_KEY')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->root.'/out.txt');
    }

    public function test_db_restore_failure_hints_without_db_and_lists_available_backups(): void
    {
        $this->makeTwoDaysOfBackups();
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        // 長い1行(取り出し先のパスを含む)は PendingCommand::expectsOutputToContain() 側の折り返しで
        // 部分一致に失敗することがあるため、折り返しの影響を受けない Artisan::output() で比較する
        $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/restore']));
        $output = Artisan::output();

        $this->assertStringContainsString(self::WITHOUT_DB_HINT, $output);
        $this->assertStringContainsString(self::AVAILABLE_DB_HEADING, $output);
        $this->assertStringContainsString('db/manage-20260912-030000.sql.gz.enc', $output);
        $this->assertStringContainsString('やり直すときは、先に rm -rf '.escapeshellarg($this->root.'/restore'), $output);

        // db/ フォルダ自体は先に作られるが、鍵違いで復号できないため中身(平文)は残らない
        $this->assertSame([], glob($this->root.'/restore/db/*'));
    }

    public function test_restore_announces_the_destination_and_cleanup_command_before_starting(): void
    {
        $this->makeTwoDaysOfBackups();

        // 長い1行(取り出し先のパスを含む)は PendingCommand::expectsOutputToContain() 側の折り返しで
        // 部分一致に失敗することがあるため、折り返しの影響を受けない Artisan::output() で比較する
        $this->assertSame(0, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/restore']));
        $output = Artisan::output();

        $this->assertStringContainsString('取り出し先: '.$this->root.'/restore', $output);
        $this->assertStringContainsString('rm -rf '.escapeshellarg($this->root.'/restore'), $output);
    }

    public function test_destination_announcement_appears_before_the_database_stage(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->assertSame(0, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/restore']));
        $output = Artisan::output();

        $announcedAt = strpos($output, '取り出し先: ');
        $databaseStartedAt = strpos($output, 'データベースを取り出しています');

        $this->assertIsInt($announcedAt);
        $this->assertIsInt($databaseStartedAt);
        $this->assertLessThan($databaseStartedAt, $announcedAt);
    }

    public function test_restore_quotes_a_japanese_destination_correctly_regardless_of_locale(): void
    {
        $this->makeTwoDaysOfBackups();
        $destination = $this->root.'/復元チェック';
        $previousLocale = setlocale(LC_CTYPE, '0');

        try {
            // 「そのロケールで escapeshellarg() が実際に日本語を落とす」ことを先に確かめてから
            // 本題(quoteForShell() は落とさない)を確かめる。落とせなければ前提が成り立たないので skip
            $applied = setlocale(LC_CTYPE, 'en_US.US-ASCII', 'C');
            if ($applied === false || escapeshellarg('日本語') !== "''") {
                $this->markTestSkipped('この環境では escapeshellarg() が日本語を落とすロケールを再現できないため、確認をスキップします。');
            }

            $this->assertSame(0, Artisan::call('ops:backup-restore', ['destination' => $destination]));
            $output = Artisan::output();

            $this->assertStringContainsString('取り出し先: '.$destination, $output);
            $this->assertStringContainsString("rm -rf '{$destination}'", $output);
        } finally {
            setlocale(LC_CTYPE, (string) $previousLocale);
        }
    }

    public function test_restore_normalizes_a_relative_destination_to_an_absolute_path(): void
    {
        $this->makeTwoDaysOfBackups();
        $previousCwd = getcwd();
        chdir($this->root);
        $cwd = (string) getcwd();

        try {
            $this->assertSame(0, Artisan::call('ops:backup-restore', ['destination' => 'restore']));
            $output = Artisan::output();

            $this->assertStringContainsString('取り出し先: '.$cwd.'/restore', $output);
            $this->assertStringContainsString("rm -rf '{$cwd}/restore'", $output);
        } finally {
            chdir((string) $previousCwd);
        }
    }

    public function test_restore_accepts_a_destination_with_a_single_dot_segment(): void
    {
        $this->makeTwoDaysOfBackups();
        $previousCwd = getcwd();
        chdir($this->root);
        $cwd = (string) getcwd();

        try {
            $this->assertSame(0, Artisan::call('ops:backup-restore', ['destination' => './restore']));
            $this->assertStringContainsString('取り出し先: '.$cwd.'/restore', Artisan::output());
        } finally {
            chdir((string) $previousCwd);
        }
    }

    public function test_restore_reports_when_the_current_directory_cannot_be_determined(): void
    {
        $previousCwd = getcwd();
        $vanishing = $this->root.'/vanishing';
        mkdir($vanishing, 0700);
        chdir($vanishing);
        rmdir($vanishing);

        try {
            if (getcwd() !== false) {
                $this->markTestSkipped('この環境では削除済みのフォルダからでも getcwd() が値を返すため、確認をスキップします。');
            }

            $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => 'restore']));
            $this->assertStringContainsString(self::GETCWD_FALSE_MESSAGE, Artisan::output());
        } finally {
            chdir((string) $previousCwd);
        }
    }

    public function test_restore_refuses_a_destination_that_is_itself_a_symlink(): void
    {
        mkdir($this->root.'/real-target', 0700);
        symlink($this->root.'/real-target', $this->root.'/link-destination');

        $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/link-destination']));
        $this->assertStringContainsString(self::SYMLINK_REFUSAL_PREFIX, Artisan::output());
    }

    public function test_restore_refuses_a_destination_containing_dot_dot_segments(): void
    {
        $destination = $this->root.'/nested/../restore';

        $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => $destination]));
        $this->assertStringContainsString(self::DOT_DOT_REFUSAL_PREFIX, Artisan::output());

        $this->assertDirectoryDoesNotExist($this->root.'/restore');
        $this->assertDirectoryDoesNotExist($this->root.'/nested');
    }

    public function test_restore_announcement_survives_a_console_format_tag_in_the_destination_name(): void
    {
        $this->makeTwoDaysOfBackups();
        $destination = $this->root.'/<comment>evil/restore';

        $this->assertSame(0, Artisan::call('ops:backup-restore', ['destination' => $destination]));
        $output = Artisan::output();

        $this->assertStringContainsString('取り出し先: '.$destination, $output);
        $this->assertStringContainsString("rm -rf '{$destination}'", $output);
    }

    public function test_storage_construction_failure_is_reported_and_does_not_leak(): void
    {
        $this->app->bind(BackupStorage::class, fn () => throw new RuntimeException('S3 に接続できません'));

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('取り出しに失敗しました')
            ->assertExitCode(1);
    }

    public function test_files_restore_after_key_rotation_gives_guidance_and_fails(): void
    {
        $this->makeTwoDaysOfBackups();
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        // 長い1行は PendingCommand::expectsOutputToContain() 側の折り返しで部分一致に失敗することが
        // あるため、折り返しの影響を受けない Artisan::output() で比較する
        $this->assertSame(1, Artisan::call('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--without-db' => true,
        ]));
        $this->assertStringContainsString(sprintf(self::OTHER_LOCATION_TEMPLATE, 2), Artisan::output());
    }

    public function test_no_guidance_when_everything_was_reuploaded_under_the_new_key(): void
    {
        $this->makeTwoDaysOfBackups();
        $newKey = BackupCipher::generateKey();
        $newCipher = new BackupCipher($newKey);
        $storage = new LocalDirectoryBackupStorage($this->root.'/remote');
        $this->uploadEncrypted($storage, $newCipher, 'public/attachments/1/契約書.pdf', 'PDF');
        $this->uploadEncrypted($storage, $newCipher, 'private/approvals/2/x.xlsx', 'XLSX');
        config(['backup.encryption_key' => $newKey]);

        $this->assertSame(0, Artisan::call('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--without-db' => true,
        ]));

        $this->assertStringNotContainsString('ほかの暗号化キーの置き場所に', Artisan::output());
    }

    public function test_other_location_guidance_counts_only_files_missing_from_the_current_location(): void
    {
        $this->makeTwoDaysOfBackups();
        $newKey = BackupCipher::generateKey();
        $newCipher = new BackupCipher($newKey);
        $storage = new LocalDirectoryBackupStorage($this->root.'/remote');
        // 2 件のうち 1 件だけを新しいキーで送り直す(もう 1 件だけが「ほかの置き場所」に残る)
        $this->uploadEncrypted($storage, $newCipher, 'public/attachments/1/契約書.pdf', 'PDF');
        config(['backup.encryption_key' => $newKey]);

        $this->assertSame(0, Artisan::call('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--without-db' => true,
        ]));

        $this->assertStringContainsString(sprintf(self::OTHER_LOCATION_TEMPLATE, 1), Artisan::output());
    }

    public function test_db_option_with_unknown_key_lists_available_backups(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->artisan('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--db' => 'db/manage-20260101-000000.sql.gz.enc',
        ])
            ->expectsOutputToContain(self::DB_NOT_FOUND_PREFIX.'db/manage-20260101-000000.sql.gz.enc')
            ->expectsOutputToContain(self::AVAILABLE_DB_HEADING)
            ->assertExitCode(1);
    }

    public function test_restore_db_option_containing_a_console_format_like_tag_does_not_crash_and_is_shown_verbatim(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->assertSame(1, Artisan::call('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--db' => 'db/<fg=nope>',
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString(self::DB_NOT_FOUND_PREFIX.'db/<fg=nope>', $output);
        $this->assertStringContainsString(self::AVAILABLE_DB_HEADING, $output);
        $this->assertStringContainsString('db/manage-20260912-030000.sql.gz.enc', $output);
    }

    public function test_storage_get_failures_abort_after_five_in_a_row(): void
    {
        $real = new LocalDirectoryBackupStorage($this->root.'/remote');
        $keyId = (new BackupCipher($this->key))->keyId();
        for ($i = 0; $i < 6; $i++) {
            $this->putFile("source{$i}.enc", 'dummy');
            $real->put(FileSyncPlanner::keyFor("attachments/{$i}.pdf", $keyId), $this->root."/source{$i}.enc");
        }
        $this->bindAlwaysFailingGet($real);

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--without-db' => true])
            ->expectsOutputToContain(sprintf(self::ABORT_TEMPLATE, 5))
            ->expectsOutputToContain(sprintf(self::UNTRIED_COUNT_TEMPLATE, 1))
            ->assertExitCode(1);
    }

    public function test_the_last_items_fifth_failure_is_not_treated_as_an_abort(): void
    {
        $real = new LocalDirectoryBackupStorage($this->root.'/remote');
        $keyId = (new BackupCipher($this->key))->keyId();
        for ($i = 0; $i < 5; $i++) {
            $this->putFile("source{$i}.enc", 'dummy');
            $real->put(FileSyncPlanner::keyFor("attachments/{$i}.pdf", $keyId), $this->root."/source{$i}.enc");
        }
        $this->bindAlwaysFailingGet($real);

        $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/restore', '--without-db' => true]));
        $output = Artisan::output();

        $this->assertStringNotContainsString('打ち切りました', $output);
        $this->assertStringNotContainsString('試していない', $output);
        $this->assertStringContainsString(sprintf(self::FAILED_COUNT_TEMPLATE, 5), $output);
    }

    public function test_a_successful_retrieval_resets_the_consecutive_failure_count(): void
    {
        $real = new LocalDirectoryBackupStorage($this->root.'/remote');
        $keyId = (new BackupCipher($this->key))->keyId();
        for ($i = 0; $i < 9; $i++) {
            $this->putFile("source{$i}.enc", 'dummy');
            $real->put(FileSyncPlanner::keyFor("attachments/{$i}.pdf", $keyId), $this->root."/source{$i}.enc");
        }

        // 保管先のキーの並び順(base64 化されたキー文字列順)に依存しないよう、呼び出しの順番ではなく
        // 実際に並んだ順の真ん中のキーを、成功させる対象として選ぶ(前後 4 件ずつになるので、
        // 5 件連続の失敗にはならない)。復号も通るよう、本物の暗号化ファイルに差し替える
        $sortedKeys = array_keys($real->list(FileSyncPlanner::prefixFor($keyId)));
        $succeedingKey = $sortedKeys[4];
        $succeedingPath = FileSyncPlanner::pathFor($succeedingKey);
        $this->uploadEncrypted($real, new BackupCipher($this->key), $succeedingPath, 'ok');

        $this->app->instance(BackupStorage::class, new class($real, $succeedingKey) implements BackupStorage
        {
            public function __construct(private BackupStorage $real, private string $succeedingKey) {}

            public function put(string $key, string $localPath): void
            {
                $this->real->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                if ($key !== $this->succeedingKey) {
                    throw new RuntimeException('保管先に接続できません');
                }
                $this->real->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->real->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->real->delete($key);
            }
        });

        $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/restore', '--without-db' => true]));
        $output = Artisan::output();

        $this->assertStringNotContainsString('打ち切りました', $output);
        $this->assertStringContainsString(sprintf(self::FAILED_COUNT_TEMPLATE, 8), $output);
    }

    public function test_failure_list_shows_first_twenty_and_a_count_of_the_rest(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->root.'/remote');
        $keyId = (new BackupCipher($this->key))->keyId();
        for ($i = 0; $i < 21; $i++) {
            $this->putFile("broken{$i}.enc", 'broken');
            $storage->put(FileSyncPlanner::keyFor(sprintf('attachments/%02d.pdf', $i), $keyId), $this->root."/broken{$i}.enc");
        }

        $this->assertSame(1, Artisan::call('ops:backup-restore', ['destination' => $this->root.'/restore', '--without-db' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString(sprintf(self::FAILURE_HEADING_LONG_TEMPLATE, 20), $output);
        $this->assertStringContainsString('ほか 1 件', $output);
        $this->assertStringContainsString(sprintf(self::FAILED_COUNT_TEMPLATE, 21), $output);
    }

    public function test_restore_destination_that_is_a_file_fails(): void
    {
        $this->putFile('restore', 'x');

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('取り出し先がフォルダではありません')
            ->assertExitCode(1);
    }

    public function test_restore_refuses_a_destination_inside_a_backed_up_root(): void
    {
        mkdir($this->root.'/storage/app/public', 0700, true);

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/storage/app/public/evil'])
            ->expectsOutputToContain(self::INSIDE_BACKUP_ROOT)
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/public/evil');
    }

    public function test_restore_reports_an_unreadable_destination_folder(): void
    {
        mkdir($this->root.'/restore', 0000);

        try {
            $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
                ->expectsOutputToContain('取り出し先のフォルダを読めません')
                ->assertExitCode(1);
        } finally {
            // tearDown() の rm -rf が消せるように戻す
            chmod($this->root.'/restore', 0700);
        }
    }

    public function test_decrypt_reports_a_key_mismatch(): void
    {
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/plain.txt.enc', 'destination' => $this->root.'/out.txt'])
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->root.'/out.txt');
    }

    public function test_decrypt_reports_a_missing_source_file(): void
    {
        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/missing.enc', 'destination' => $this->root.'/out.txt'])
            ->assertExitCode(1);
    }

    public function test_decrypt_refuses_a_destination_inside_a_backed_up_root(): void
    {
        mkdir($this->root.'/storage/app/public', 0700, true);
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');
        $destination = $this->root.'/storage/app/public/x.sql.gz';

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/plain.txt.enc', 'destination' => $destination])
            ->expectsOutputToContain(self::DECRYPT_INSIDE_BACKUP_ROOT)
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($destination);
    }

    public function test_backup_key_prints_the_key_id(): void
    {
        $this->assertSame(0, Artisan::call('ops:backup-key'));
        $output = Artisan::output();
        $firstLine = trim((string) strtok($output, "\n"));
        $keyId = (new BackupCipher($firstLine))->keyId();

        $this->assertStringContainsString(self::KEY_ID_PREFIX.$keyId, $output);
        $this->assertStringContainsString('files/'.$keyId.'/', $output);
    }

    public function test_backup_key_warns_when_a_key_is_already_configured(): void
    {
        $this->assertSame(0, Artisan::call('ops:backup-key'));

        $this->assertStringContainsString(self::ALREADY_CONFIGURED_WARNING, Artisan::output());
    }

    private function bindAlwaysFailingGet(BackupStorage $real): void
    {
        $this->app->instance(BackupStorage::class, new class($real) implements BackupStorage
        {
            public function __construct(private BackupStorage $real) {}

            public function put(string $key, string $localPath): void
            {
                $this->real->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                throw new RuntimeException('保管先に接続できません');
            }

            public function list(string $prefix): array
            {
                return $this->real->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->real->delete($key);
            }
        });
    }

    private function uploadEncrypted(LocalDirectoryBackupStorage $storage, BackupCipher $cipher, string $relative, string $contents): void
    {
        $plain = $this->root.'/upload-plain-'.bin2hex(random_bytes(4)).'.tmp';
        $encrypted = $plain.'.enc';
        file_put_contents($plain, $contents);
        $cipher->encryptFile($plain, $encrypted);
        $storage->put(FileSyncPlanner::keyFor($relative, $cipher->keyId()), $encrypted);
        unlink($plain);
        unlink($encrypted);
    }

    private function makeTwoDaysOfBackups(): void
    {
        $this->putFile('storage/app/public/attachments/1/契約書.pdf', 'PDF');
        $this->putFile('storage/app/private/approvals/2/x.xlsx', 'XLSX');

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

    private function putFile(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
