<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\FileSyncPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileSyncPlannerTest extends TestCase
{
    private const KEY_ID = '0123456789abcdef';

    private const OTHER_KEY_ID = 'fedcba9876543210';

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
        $key = FileSyncPlanner::keyFor($path, self::KEY_ID);

        $this->assertMatchesRegularExpression('#^files/[0-9a-f]{16}/[A-Za-z0-9_-]+\.enc$#', $key);
        $this->assertSame($path, FileSyncPlanner::pathFor($key));
    }

    public static function unsafeKeys(): array
    {
        $encode = fn (string $path, string $keyId = '0123456789abcdef') => 'files/'.$keyId.'/'.rtrim(strtr(base64_encode($path), '+/', '-_'), '=').'.enc';

        return [
            'db のキー' => ['db/manage-20260912-030000.sql.gz.enc'],
            '使えない文字' => ['files/0123456789abcdef/abc!.enc'],
            '上のフォルダへ出る' => [$encode('public/../../etc/passwd')],
            '絶対パス' => [$encode('/etc/passwd')],
            '空の区切り' => [$encode('public//a.txt')],
            '. の区切り' => [$encode('public/./a.txt')],
            '末尾が空の区切り(スラッシュ終わり)' => [$encode('public/a/')],
            'パス内に NUL' => [$encode("public/a\0.pdf")],
            '不正な長さの base64' => ['files/0123456789abcdef/QUJDR.enc'],
            '.enc の後ろに改行' => [$encode('public/a.pdf')."\n"],
            '非正規の base64(QR は QQ と同じバイト列)' => ['files/0123456789abcdef/QR.enc'],
            'キー識別子のフォルダが無い旧形式' => ['files/'.rtrim(strtr(base64_encode('public/a.pdf'), '+/', '-_'), '=').'.enc'],
            'キー識別子が大文字' => ['files/0123456789ABCDEF/'.rtrim(strtr(base64_encode('public/a.pdf'), '+/', '-_'), '=').'.enc'],
        ];
    }

    #[DataProvider('unsafeKeys')]
    public function test_unexpected_or_dangerous_keys_map_to_null(string $key): void
    {
        $this->assertNull(FileSyncPlanner::pathFor($key));
    }

    public function test_prefix_for_rejects_an_invalid_key_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FileSyncPlanner::prefixFor('not-hex');
    }

    public function test_keys_for_different_key_ids_differ_and_each_maps_back_to_the_path(): void
    {
        $keyA = FileSyncPlanner::keyFor('public/a.pdf', self::KEY_ID);
        $keyB = FileSyncPlanner::keyFor('public/a.pdf', self::OTHER_KEY_ID);

        $this->assertNotSame($keyA, $keyB);
        $this->assertSame('public/a.pdf', FileSyncPlanner::pathFor($keyA));
        $this->assertSame('public/a.pdf', FileSyncPlanner::pathFor($keyB));
    }

    public function test_an_overly_long_path_makes_key_for_throw(): void
    {
        $this->expectException(RuntimeException::class);
        FileSyncPlanner::keyFor('public/'.str_repeat('a', 800), self::KEY_ID);
    }

    public function test_only_missing_or_changed_files_are_uploaded(): void
    {
        $local = [
            'public/a.pdf' => 100,
            'public/b.pdf' => 200,
            'private/c.xlsx' => 300,
        ];
        $remote = [
            FileSyncPlanner::keyFor('public/a.pdf', self::KEY_ID) => BackupCipher::encryptedSize(100), // 送信済み
            FileSyncPlanner::keyFor('public/b.pdf', self::KEY_ID) => BackupCipher::encryptedSize(150), // 大きさが違う
            FileSyncPlanner::keyFor('public/gone.pdf', self::KEY_ID) => 10,                             // 手元から消えたもの（何もしない）
        ];

        $this->assertSame(['private/c.xlsx', 'public/b.pdf'], FileSyncPlanner::filesToUpload($local, $remote, self::KEY_ID));
    }

    public function test_files_to_upload_ignores_objects_under_another_key_ids_folder(): void
    {
        $local = ['public/a.pdf' => 100];
        $remote = [
            FileSyncPlanner::keyFor('public/a.pdf', self::KEY_ID) => BackupCipher::encryptedSize(100), // 旧キーで送信済み
        ];

        $this->assertSame(['public/a.pdf'], FileSyncPlanner::filesToUpload($local, $remote, self::OTHER_KEY_ID));
    }
}
