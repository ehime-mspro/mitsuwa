<?php

namespace App\Support\Approval;

/**
 * 関連する決裁No（要件 5.1・設計書 D15）。
 *
 * 紙の時代の番号も手で入れられるので、**形だけ**を確かめる（その番号の決裁が在るかは見ない）。
 */
final class RelatedNumbers
{
    public const MAX = 10;

    /**
     * 和暦（令和 R・平成 H。年は 1 から・先頭に 0 を付けない）-部門のアルファベット-3〜5 桁の連番。
     * 5 桁までにするのは、1 部門・1 年度で 10 万件は出ないため（採番そのものに上限は無く、99999 の次は
     * R8-J-100000 になってこの形では断る。画面で入れる「次の番号」は 99999 まで）。
     * ⚠ 申請書の画面の JS（addNumber）にも同じ形の正規表現がある。変えるときは両方そろえる。
     */
    public const PATTERN = '/\A[RH][1-9][0-9]?-[A-Z]{1,3}-[0-9]{3,5}\z/';

    /**
     * 1 つの番号を正規化する（全角→半角・大文字・空白を除く・長音やダッシュ類をハイフンに）。
     * 番号に空白は無いので、途中の空白（`R8 - J - 001`）も除く。
     * ⚠ 申請書の画面の JS（normalizeNumber）も同じ一覧でそろえる。変えるときは両方そろえる。
     */
    public static function normalize(?string $value): string
    {
        $value = mb_convert_kana((string) $value, 'as');
        $value = str_replace(['ー', '―', '‐', '−', '–', '—', "\u{FF70}", "\u{2011}", "\u{FE63}"], '-', $value);

        return mb_strtoupper(preg_replace('/[\s\x{3000}]+/u', '', $value), 'UTF-8');
    }

    /**
     * 画面から来た配列を正規化し、空を落として重複を除く（並びは入れた順）。
     *
     * @param  array<int, mixed>|null  $values
     * @return list<string>
     */
    public static function clean(?array $values): array
    {
        $out  = [];
        $seen = [];   // ⚠ 重複は配列のキーで見る（in_array を繰り返すと個数の 2 乗で遅くなる。JSON の本文なら数に上限が無い）

        foreach ($values ?? [] as $value) {
            $n = self::normalize(is_string($value) ? $value : '');
            if ($n !== '' && ! isset($seen[$n])) {
                $seen[$n] = true;
                $out[]    = $n;   // 返すのは文字列のまま（キーにすると数字だけの文字列が int になる）
            }
        }

        return $out;
    }
}
