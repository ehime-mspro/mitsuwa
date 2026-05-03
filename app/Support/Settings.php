<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * システム共通設定（key/value 型）への安全なアクセサ。
 *
 * 単一の `settings` テーブルから値を取得する。設計上の特徴:
 *
 *   - リクエストスコープのメモリキャッシュにより、同一リクエスト内で
 *     同じキーを複数回参照してもクエリは 1 回のみ。
 *   - settings テーブル不在 / カラム欠如 / DB 接続不可など全例外を
 *     try/catch で吸収し、必ず指定したデフォルト値を返す。
 *     （Phase 3 開発中、settings テーブル未作成の環境で
 *      画面ごと 500 エラーになる事故を防止）
 *
 * 使い方:
 *   $taxRate = (float) Settings::get('tax_rate', 10);
 *
 * 値の型変換は呼び出し側の責務（cast せず文字列のまま返す）。
 */
class Settings
{
    /** @var array<string, mixed> リクエストスコープのキャッシュ */
    private static array $cache = [];

    /**
     * 指定キーの値を取得する。失敗時は $default を返す。
     *
     * @param  string  $key      取得するキー
     * @param  mixed   $default  値が無い / 取得失敗時のフォールバック
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // キャッシュヒット
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $value = DB::table('settings')->where('key', $key)->value('value');
        } catch (Throwable $e) {
            // settings テーブル不在 / DB 切断など、運用上ありうる例外は
            // 黙って吸収し、デフォルトを返す。
            // ログには出すが、画面は落とさない。
            \Log::warning('[Settings::get] failed to read key=' . $key . ' : ' . $e->getMessage());
            return self::$cache[$key] = $default;
        }

        return self::$cache[$key] = ($value ?? $default);
    }

    /**
     * 消費税率（%表記の数値、例: 10.0）を返す。
     * 共通利用が多いためショートカットを用意。
     */
    public static function taxRate(float $default = 10.0): float
    {
        return (float) self::get('tax_rate', $default);
    }

    /**
     * テスト等でキャッシュをクリアしたい場合に使用。
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
