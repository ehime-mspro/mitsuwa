<?php

namespace App\Support;

/**
 * 周辺ビル調査の空室率ヘルパー。
 *
 * 空室率(%) = (空き + 不明) × 100 ÷ (営業 + 空き + 不明)
 * 「不明」は空きとして扱う（現地で判断できなかった区画は稼働していない前提で見る）。
 *
 * 丸めは 1/10 % 単位の切り捨て。整数演算だけで行う。
 *
 * ⚠ 四捨五入に戻さないこと。2 ÷ 3 が 66.6% でなく 66.7% に、
 *   2 ÷ 7 が 28.5% でなく 28.6% になる（VacancyRateTest がこの 2 値で固定している）。
 *
 * ⚠ この計算はここ 1 箇所だけに置く。一覧・詳細・取込プレビューが同じ式を
 *   別々に持つと、片方だけ直す事故が起きる（Bug #41）。
 *   SQL 側で率を計算するのも禁止（MySQL と SQLite で整数除算の意味が違う）。
 *
 * 区画数（営業・空き・不明）は非負を前提とする（呼び出し元は DB の INT UNSIGNED 制約と
 * min:0 バリデーションで検証済み）。負数を渡しても例外にはならないが、
 * 0〜100% の範囲外の値を無警告で返す（例: percent(10, -5, 0) は -100.0、
 * percent(-10, 50, 0) は 125.0）。
 */
class VacancyRate
{
    /** 1/10 % 単位で扱うための係数（100% × 10） */
    private const SCALE = 1000;

    /**
     * 空室率の帯（%）。⚠ **閾値はここ 1 箇所だけ。**
     * 一覧のフィルタ（AreaBuildingListService::matchesVacancy）も
     * 地図の色分け・凡例もこれを見る。別々に持つと片方だけ直す事故が起きる（Bug #41）。
     *
     * 2026-08-19 の実データ 187 棟で 24:18:26:31 に割れることを確認して決めた（設計書 §3）。
     */
    public const BAND_MID  = 25.0;

    public const BAND_HIGH = 50.0;

    public const LEVEL_NONE    = 'none';

    public const LEVEL_LOW     = 'low';

    public const LEVEL_MID     = 'mid';

    public const LEVEL_HIGH    = 'high';

    public const LEVEL_UNKNOWN = 'unknown';

    /**
     * 凡例と地図のピンの見た目。
     *
     * ⚠ 色は Tailwind クラスでなく 16 進で持つ。Google Maps のマーカーへ
     *   そのまま渡す値で、CSS クラスでは指定できないため（UnitStatus とは事情が違う）。
     */
    public const LEVELS = [
        self::LEVEL_NONE    => ['label' => '満室（0%）', 'color' => '#059669'],
        self::LEVEL_LOW     => ['label' => '1〜24%',     'color' => '#eab308'],
        self::LEVEL_MID     => ['label' => '25〜49%',    'color' => '#f97316'],
        self::LEVEL_HIGH    => ['label' => '50% 以上',   'color' => '#dc2626'],
        self::LEVEL_UNKNOWN => ['label' => '調査なし',   'color' => '#9ca3af'],
    ];

    /**
     * 空室率（%）。総区画数が 0 のときは null（ゼロ除算＝未調査）。
     */
    public static function percent(int $operating, int $vacant, int $unknown): ?float
    {
        $total = $operating + $vacant + $unknown;

        if ($total <= 0) {
            return null;
        }

        return intdiv(($vacant + $unknown) * self::SCALE, $total) / 10;
    }

    /**
     * 入居率（%）＝ 100 − 空室率。総区画数が 0 のときは null（ゼロ除算＝未調査）。
     *
     * ⚠ **「営業 ÷ 総数」で独立に切り捨てないこと。** 画面には空室率と並べて出すので、
     *   2 つの和が 100.0% にならない行が出てはいけない（営業 1 / 空き 2 なら
     *   空室率 66.6% ＋ 入居率 33.3% ＝ **99.9%**。Bug #46「並べた数字が無音で食い違う」）。
     *   裏返しにする以上、入居率側の丸めは 1/10% 単位の**切り上げ**になる。
     *
     * ⚠ **float の引き算にしないこと**（Bug #33 / #34）。`100.0 - self::percent(...)` は
     *   営業 1 / 空き 2 で `33.400000000000006` を返す（2026-08-20 実測）。
     *   1/10% 単位の**整数のまま引いてから、最後に 1 回だけ 10 で割る**。
     *
     * ⚠ 「不明」は入居に数えない（空室率が空きに数えているのと対）。上の式で自動的にそうなる。
     *
     * ⚠ 割り算の式が percent() と**対で 2 つある**（percent() は既存の構造テストが
     *   経路を固定しているので触らない）。片方だけ直すと和が 100.0% でなくなるが、
     *   VacancyRateTest::test_the_two_rates_on_screen_always_add_up_to_100_percent が
     *   39,710 通りの内訳で突き合わせているので無音にはならない。
     */
    public static function occupancyPercent(int $operating, int $vacant, int $unknown): ?float
    {
        $total = $operating + $vacant + $unknown;

        if ($total <= 0) {
            return null;
        }

        return (self::SCALE - intdiv(($vacant + $unknown) * self::SCALE, $total)) / 10;
    }

    /**
     * 画面表示用のラベル。未調査は「—」。
     */
    public static function label(int $operating, int $vacant, int $unknown): string
    {
        $rate = self::percent($operating, $vacant, $unknown);

        return $rate === null ? '—' : number_format($rate, 1) . '%';
    }

    /**
     * 入居率の表示用ラベル。未調査は「—」。⚠ 空室率の label() と同じ流儀。
     */
    public static function occupancyLabel(int $operating, int $vacant, int $unknown): string
    {
        $rate = self::occupancyPercent($operating, $vacant, $unknown);

        return $rate === null ? '—' : number_format($rate, 1) . '%';
    }


    /**
     * 地図のピンに載せる短いラベル。**切り捨ての整数** ＋ '%'（42.8% → '42%'）。
     * 総区画数が 0（＝率が出せない）なら '—'。
     *
     * ⚠ **四捨五入にしない。** 49.6% を「50%」と出すと、色は橙（25〜49% の帯）のままで
     *   数字だけが 50 になり、**橙の丸に 50% と書いてある**状態になる。切り捨てなら
     *   帯の境界（BAND_MID / BAND_HIGH）をまたぐ表示が原理的に起きない。
     *   VacancyRateTest::test_compact_label_never_contradicts_the_band が固定している。
     *
     * ⚠ **float を一度も経由しない。** 二進誤差で下振れするため（Bug #33 / #34）。
     *   percent() は intdiv((空き + 不明) × 1000, 総数) / 10、ここは
     *   intdiv((空き + 不明) × 100, 総数)。正の整数では
     *   intdiv(intdiv(a, b), c) === intdiv(a, b × c) なので、
     *   **percent() の 1/10% 値を 10 で割って切り捨てた数と厳密に一致する**
     *   （intdiv(v × 1000, t × 10) === intdiv(v × 100, t)）。
     *
     * ⚠ 吹き出しは従来どおり 1/10% 刻みの label() を使う。短いのは丸の中だけ。
     */
    public static function compactLabel(int $operating, int $vacant, int $unknown): string
    {
        // ⚠ 「率が出せない」の定義は percent() 1 箇所に置く（総数の判定を二重に持たない）
        if (self::percent($operating, $vacant, $unknown) === null) {
            return '—';
        }

        return intdiv(($vacant + $unknown) * 100, $operating + $vacant + $unknown) . '%';
    }

    /**
     * 地図の丸に載せる入居率の短いラベル。**切り捨ての整数** ＋ '%'（57.2% → '57%'）。
     * 総区画数が 0（＝率が出せない）なら '—'。
     *
     * ⚠ **float を一度も経由しない**（Bug #33 / #34）。1/10% 単位の整数を
     *   intdiv(…, 10) で落とす。occupancyPercent() の値を (int) キャストするのと
     *   同値だが、float を挟むと下振れしうる。
     *
     * ⚠ **帯（色）の言い方から 1 だけはみ出す内訳がある。** 帯のキーは空室率の段階のままで、
     *   入居率の切り捨ては空室率から見ると切り上げになるため、境界のすぐ上で
     *   low（凡例は「76〜99%」）に 75%、mid（同「51〜75%」）に 50% が出る
     *   （例: 29 区画中 7 空きは空室率 24.1% ＝ low、入居率 75.8% → 「75%」）。
     *   閾値を動かさない判断なのでそのままにしてある。実データ 187 棟の規模では
     *   総区画 22 以上の棟でしか起こらない。
     *   VacancyRateTest::test_occupancy_compact_label_against_the_bands が件数を固定している。
     */
    public static function occupancyCompactLabel(int $operating, int $vacant, int $unknown): string
    {
        // ⚠ 「率が出せない」の定義は occupancyPercent() 1 箇所に置く（総数の判定を二重に持たない）
        if (self::occupancyPercent($operating, $vacant, $unknown) === null) {
            return '—';
        }

        $total = $operating + $vacant + $unknown;

        return intdiv(self::SCALE - intdiv(($vacant + $unknown) * self::SCALE, $total), 10) . '%';
    }

    /**
     * 空室率の帯。総区画数が 0（＝率が出せない）なら unknown。
     *
     * ⚠ 調査回がまだ無いビルは呼び出し側で unknown にする。ここへ null は渡せない
     *   （引数は int なので TypeError になる）。
     */
    public static function level(int $operating, int $vacant, int $unknown): string
    {
        $rate = self::percent($operating, $vacant, $unknown);

        return match (true) {
            $rate === null           => self::LEVEL_UNKNOWN,
            $rate >= self::BAND_HIGH => self::LEVEL_HIGH,
            $rate >= self::BAND_MID  => self::LEVEL_MID,
            $rate > 0.0              => self::LEVEL_LOW,
            default                  => self::LEVEL_NONE,
        };
    }
}
