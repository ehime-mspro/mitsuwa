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

    private const WITHOUT_DB_HINT = '添付だけを取り出すときは --without-db を付けてください。';

    private const AVAILABLE_DB_HEADING = '使えるデータベースのバックアップ（新しい順）:';

    private const OTHER_LOCATION_TEMPLATE = 'ほかの暗号化キー（キーを変える前のキー）で作った添付が、別の置き場所に %d 件あります。取り出すには、空の取り出し先を指定して php artisan ops:backup-restore <取り出し先> --without-db --ask-key を実行し、以前のキーを入力してください。';

    private const ABORT_TEMPLATE = '保管先から続けて 5 件取り出せなかったため、残り %d 件を試さずに打ち切りました（保管先の不調の可能性があります）。';

    private const INSIDE_BACKUP_ROOT = '取り出し先を、バックアップの対象フォルダ（storage/app/public・private）の中にはできません（翌晩のバックアップに、取り出したファイルが入ってしまうため）。';

    private const DB_NOT_FOUND_PREFIX = '指定されたデータベースのバックアップがありません: ';

    private const KEY_ID_PREFIX = 'キーの識別番号: ';

    private const ALREADY_CONFIGURED_WARNING = 'すでに BACKUP_ENCRYPTION_KEY が設定されています。キーを変えると、次のバックアップで添付をすべて送り直します（手順書の「7. 注意」を見てください）。';

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

    public function test_storage_get_failures_abort_after_five_in_a_row(): void
    {
        $real = new LocalDirectoryBackupStorage($this->root.'/remote');
        $keyId = (new BackupCipher($this->key))->keyId();
        for ($i = 0; $i < 6; $i++) {
            $this->putFile("source{$i}.enc", 'dummy');
            $real->put(FileSyncPlanner::keyFor("attachments/{$i}.pdf", $keyId), $this->root."/source{$i}.enc");
        }

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

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore', '--without-db' => true])
            ->expectsOutputToContain(sprintf(self::ABORT_TEMPLATE, 1))
            ->assertExitCode(1);
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
