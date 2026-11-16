<?php

namespace App\Support;

/**
 * CSV 取込で、本番の列に入らない値をその行の誤りにする部品（賃貸マンション・テナントの取込が使う）。
 *
 * 本番の MySQL は strict なので、列に入らない値が 1 行でもあると確定で例外になり、全行が取り消される
 * （どの値が悪いかは画面に出ない）。行の検査でこれを止めて、その行の誤りにする。上限は画面の入力チェックと同じ値を
 * 呼ぶ側が渡す。テストの SQLite は長さも範囲も見ないので、呼ぶ側のテストは「その行が誤りになり、確定で書かれない」を件数で見る。
 * docs/RULES.md Bug #132（賃貸マンション）・Bug #133（テナント）
 */
final class CsvImportLimits
{
    /**
     * 列より長い値があれば、最初の 1 つの誤りの文を返す（無ければ null）。
     * MySQL の utf8mb4 の VARCHAR は文字数で数えるので mb_strlen で測る。
     * 改行は 2 文字と数える — 編集画面の textarea はブラウザが改行を CRLF で送り、画面の入力チェックは 2 文字と数えるので、
     * 1 文字と数えると「取り込めたのに編集画面で保存できない」備考ができる（docs/RULES.md Bug #130 の形・Bug #133）。
     * 改行が残るのは CsvImportReader::rows() の改行を残す列（備考・メモ）だけで、ほかの列の改行は空白になっている。
     *
     * @param  array<string, string>  $row  内部キー => セルの値（CsvImportReader::rows() の 1 行。無い列は '' で埋まっている）
     * @param  array<string, array{0: string, 1: int}>  $limits  内部キー => [CSV の見出し, 上限]（この順に見る）
     */
    public static function tooLongMessage(array $row, array $limits): ?string
    {
        foreach ($limits as $key => [$label, $max]) {
            if (mb_strlen(str_replace("\n", "\r\n", $row[$key])) > $max) {
                return "{$label}は{$max}文字以内で入力してください";
            }
        }

        return null;
    }

    /**
     * 0〜$max の整数のセルを読む。小数・負・指数（「1e10」）は整数でないとして断る
     * （直す前は is_numeric で読み、「55000.9」を黙って 55000 で保存していた。画面の入力チェックは integer）。
     * 上限との比べは float で行う（PHP の int を超える桁に頼らない）。
     *
     * @param  string  $formatError  整数でないとき・カンマの区切りが崩れているときの文（呼ぶ側の今の文）
     * @param  bool  $allowCommas  3 桁ごとの正しい区切りのカンマを除いて読むか。金額は除く（「55,000」）。
     *                             総戸数・階数・階は除かない（「1,000」も形の誤り。Bug #132 の最後の点検で決めた）
     * @return array{0: int, 1: null}|array{0: null, 1: string}  [値, null] か [null, 誤りの文]
     */
    public static function readUnsignedInt(string $raw, string $label, int $max, string $formatError, bool $allowCommas = true): array
    {
        $digits = $allowCommas ? self::withoutThousandsSeparators($raw) : $raw;
        if ($digits === null || ! ctype_digit($digits)) {
            return [null, $formatError];
        }
        if ((float) $digits > $max) {
            return [null, "{$label}「{$raw}」は{$max}以下の値にしてください"];
        }

        return [(int) $digits, null];
    }

    /**
     * 3 桁ごとの正しい区切りのカンマを除いた文字を返す（金額と面積の読み方が使う）。カンマが無ければそのまま返す
     * （数かどうかは呼ぶ側が見る）。区切りが崩れていれば null。
     * 正しい区切り: 整数の部分（最初の「.」より前）が「1〜3 桁」＋「, と 3 桁」の繰り返しで、小数の部分にカンマが無い。
     * 直す前はカンマを全部除いていたので、打ち間違いの「5,5000」が 55000・「5,50」が 550 で黙って入っていた。
     * Excel が書き出す CSV は表示どおりの正しい区切りなので、崩れた区切りは人が打ち間違えたときにだけ入る。
     * docs/RULES.md Bug #132 の追記（2026-11-16）
     */
    public static function withoutThousandsSeparators(string $raw): ?string
    {
        if (! str_contains($raw, ',')) {
            return $raw;
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        if (preg_match('/^\d{1,3}(?:,\d{3})+$/', $integer) !== 1 || str_contains($fraction, ',')) {
            return null;
        }

        return str_replace(',', '', $raw);
    }
}
