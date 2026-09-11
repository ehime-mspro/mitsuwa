<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\RetentionPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
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
            'db/manage-20260915-025959.sql.gz.enc', // 30 日と 1 秒前 → 消す
            'db/manage-20260915-030000.sql.gz.enc', // ちょうど 30 日前 → 残す
            'db/manage-20261014-030000.sql.gz.enc', // 昨日 → 残す
            'db/other.txt',                          // 形式が違う → 触らない
            'db/manage-20260230-030000.sql.gz.enc', // 2/30（繰り上げると 3/2 で期間切れ）→ 触らない
            'db/manage-20260901-240000.sql.gz.enc', // 24 時（繰り上げると 9/2 0:00）→ 触らない
            'db/manage-20260901-030000.sql.gz.enc.bak', // 後ろに続きがある → 触らない
            'db/old/db/manage-20260901-030000.sql.gz.enc', // 別の階層 → 触らない
        ];

        $this->assertSame(
            ['db/manage-20260901-030000.sql.gz.enc', 'db/manage-20260915-025959.sql.gz.enc'],
            RetentionPolicy::expiredDatabaseKeys($keys, $now, 30),
        );
    }

    public function test_key_with_trailing_newline_is_neither_expired_nor_latest(): void
    {
        $now = CarbonImmutable::create(2026, 10, 15, 3, 0, 0, 'Asia/Tokyo');
        $key = "db/manage-20260101-030000.sql.gz.enc\n"; // 末尾に改行がある → 触らない

        $this->assertSame([], RetentionPolicy::expiredDatabaseKeys([$key, 'db/manage-20261014-030000.sql.gz.enc'], $now, 30));
        $this->assertNull(RetentionPolicy::latestDatabaseKey([$key]));
    }

    public function test_newest_backup_is_kept_even_when_it_is_old(): void
    {
        $now = CarbonImmutable::create(2026, 10, 15, 3, 0, 0, 'Asia/Tokyo');
        $keys = ['db/manage-20260201-030000.sql.gz.enc', 'db/manage-20260101-030000.sql.gz.enc'];

        $this->assertSame(['db/manage-20260101-030000.sql.gz.enc'], RetentionPolicy::expiredDatabaseKeys($keys, $now, 30));
    }

    public function test_latest_key_is_found(): void
    {
        $keys = [
            'db/manage-20260912-030000.sql.gz.enc',
            'db/manage-20260913-030000.sql.gz.enc',
            'db/other.txt',
            'db/manage-20261399-030000.sql.gz.enc', // ありえない日付（繰り上げると最新になってしまう）→ 触らない
        ];

        $this->assertSame('db/manage-20260913-030000.sql.gz.enc', RetentionPolicy::latestDatabaseKey($keys));
        $this->assertNull(RetentionPolicy::latestDatabaseKey(['db/other.txt']));
    }

    public function test_generated_key_is_recognised_as_the_latest(): void
    {
        $at = CarbonImmutable::create(2026, 9, 11, 18, 0, 0, 'UTC');
        $key = RetentionPolicy::databaseKey($at);

        $this->assertSame($key, RetentionPolicy::latestDatabaseKey([$key]));
    }

    public function test_expired_database_keys_rejects_zero_days(): void
    {
        $now = CarbonImmutable::create(2026, 10, 15, 3, 0, 0, 'Asia/Tokyo');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('保存日数は 1 以上にしてください。');

        RetentionPolicy::expiredDatabaseKeys(['db/manage-20260901-030000.sql.gz.enc'], $now, 0);
    }

    public function test_expired_database_keys_rejects_negative_days(): void
    {
        $now = CarbonImmutable::create(2026, 10, 15, 3, 0, 0, 'Asia/Tokyo');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('保存日数は 1 以上にしてください。');

        RetentionPolicy::expiredDatabaseKeys(['db/manage-20260901-030000.sql.gz.enc'], $now, -1);
    }
}
