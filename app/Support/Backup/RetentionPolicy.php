<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * データベースのバックアップのキー名と保存期間（要件定義書 14.6: 30 日分。最新の 1 件は必ず残す）。
 *
 * キーの日時は日本時間。オブジェクトストレージのキーに ":" は使えないので Ymd-His にする。
 */
final class RetentionPolicy
{
    public const DB_PREFIX = 'db/';

    private const TIMEZONE = 'Asia/Tokyo';

    private const NAME_PREFIX = 'manage-';

    private const DATE_FORMAT = 'Ymd-His';

    private const SUFFIX = '.sql.gz.enc';

    public static function databaseKey(CarbonInterface $at): string
    {
        return self::DB_PREFIX.self::NAME_PREFIX.CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE)->format(self::DATE_FORMAT).self::SUFFIX;
    }

    /**
     * 保存期間を過ぎたキー。形式の違うキーは消さない。いちばん新しい 1 件は期間を過ぎていても残す。
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function expiredDatabaseKeys(array $keys, CarbonInterface $now, int $days): array
    {
        if ($days < 1) {
            throw new InvalidArgumentException('保存日数は 1 以上にしてください。');
        }

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
        $pattern = self::keyPattern();

        $dated = [];
        foreach ($keys as $key) {
            if (preg_match($pattern, $key, $m) !== 1) {
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

    /**
     * databaseKey() が作る形式からパターンを組み立てる（表記の重複を避ける）。
     * 前後は \A・\z で固定し、"$" が末尾の改行の前にもマッチしてしまう問題を避ける。
     */
    private static function keyPattern(): string
    {
        return '#\A'
            .preg_quote(self::DB_PREFIX.self::NAME_PREFIX, '#')
            .'(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})'
            .preg_quote(self::SUFFIX, '#')
            .'\z#';
    }
}
