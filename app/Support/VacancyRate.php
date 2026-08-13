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
     * 画面表示用のラベル。未調査は「—」。
     */
    public static function label(int $operating, int $vacant, int $unknown): string
    {
        $rate = self::percent($operating, $vacant, $unknown);

        return $rate === null ? '—' : number_format($rate, 1) . '%';
    }
}
