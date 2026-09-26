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

    /** 和暦（令和 R・平成 H）-部門のアルファベット-3 桁以上の連番 */
    public const PATTERN = '/\A[RH][0-9]{1,2}-[A-Z]{1,3}-[0-9]{3,}\z/';

    /** 1 つの番号を正規化する（全角→半角・大文字・前後の空白・長音やダッシュ類をハイフンに） */
    public static function normalize(?string $value): string
    {
        $value = mb_convert_kana((string) $value, 'as');
        $value = str_replace(['ー', '―', '‐', '−', '–', '—'], '-', $value);

        return mb_strtoupper(preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value), 'UTF-8');
    }

    /**
     * 画面から来た配列を正規化し、空を落として重複を除く（並びは入れた順）。
     *
     * @param  array<int, mixed>|null  $values
     * @return list<string>
     */
    public static function clean(?array $values): array
    {
        $out = [];

        foreach ($values ?? [] as $value) {
            $n = self::normalize(is_string($value) ? $value : '');
            if ($n !== '' && ! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }
}
