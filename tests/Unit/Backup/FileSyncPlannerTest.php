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
