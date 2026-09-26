<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 顧客 CSV 取込の 1 行の検査と変換（DB に触らない）。
 *
 * 取込（`Admin\CustomerImportController`）はプレビューでも確定でもこれを通す。確定でも検査をやり直すので、
 * 確認画面から送り返された CSV（`csv_data`）が書き換えられていても、プレビューで通らない行は入らない。
 *
 * ⚠ 1 行の誤りはすべて集め、CSV の列の順に並べる（直して上げ直すたびに次の誤りが出る、を避ける）。
 * ⚠ 日付は `CsvDate::normalize()`（`checkdate()`）で読む。モデルの date キャストに任せると `19800102` は
 *   Unix 時刻として読まれ、`2026-02-30` は 3/2 に繰り上がる（docs/RULES.md Bug #54・#66）。
 * ⚠ 上限は本番の列の大きさ（2026-09-26 の読み取り。計画 docs/superpowers/plans/2026-09-26-customer-import-confirm.md の
 *   実測記録「Task 1」。設計書 §2.6 は担当者名を 50 としているが、本番は 100）。
 *   超えると本番の MySQL（strict モード）が 1 行の誤りで取込の全行を巻き戻す。
 * ⚠ Laravel を起動しない Unit テストで使うので、時刻や timezone に頼る処理を入れない（Bug #54 ①）。
 */
final class BuyerCsvRow
{
    /** CSV の見出し => 内部のキー（テンプレートの並び。設問の列（Q1:…）は含まない） */
    public const COLUMNS = [
        '姓' => 'last_name', '名' => 'first_name',
        'セイ' => 'last_name_kana', 'メイ' => 'first_name_kana',
        '生年月日' => 'birth_date', '元号' => 'birth_era',
        '大人人数' => 'family_adults', '子供人数' => 'family_children',
        '郵便番号' => 'postal_code', '都道府県' => 'prefecture',
        '市区町村' => 'city', '住所詳細' => 'address_detail',
        '建物名' => 'building_name', '電話番号' => 'phone',
        'メールアドレス' => 'email', '職業' => 'occupation',
        '勤務先' => 'employer', '勤続年数' => 'years_employed',
        '取得日' => 'acquired_date', '来場分譲地名' => 'project_name',
        '担当者名' => 'staff_name',
    ];

    /** 空欄なら誤りにする列（文言は「〇〇が未入力です」） */
    private const REQUIRED = ['last_name', 'first_name', 'acquired_date'];

    /** 文字の列の上限（buyers の列の大きさ。担当者名は buyer_surveys.staff_name） */
    private const MAX_LENGTH = [
        'last_name' => 50, 'first_name' => 50,
        'last_name_kana' => 50, 'first_name_kana' => 50,
        'postal_code' => 10, 'prefecture' => 10, 'city' => 50,
        'address_detail' => 255, 'building_name' => 255,
        'phone' => 20, 'email' => 255, 'occupation' => 50, 'employer' => 100,
        'staff_name' => 100,
    ];

    /** 整数の列の上限（大人人数・子供人数は tinyint unsigned、勤続年数は smallint unsigned） */
    private const MAX_INTEGER = [
        'family_adults' => 255, 'family_children' => 255, 'years_employed' => 65535,
    ];

    /**
     * 元号の記号 => [名前, 最初の年, 最後の年（令和は null）]。
     * 年は詳細画面（`Buyer::getBirthDateDisplayAttribute()`）の差と同じ（昭和 1〜64 年・平成 1〜31 年・令和 1 年〜）。
     */
    private const ERAS = [
        'S' => ['昭和', 1926, 1989],
        'H' => ['平成', 1989, 2019],
        'R' => ['令和', 2019, null],
    ];

    /** buyers に入れない列（部署の取得日・アンケートの分譲地と担当者に使う） */
    private const NOT_BUYER = ['acquired_date', 'project_name', 'staff_name'];

    /**
     * @param  array<string, string|int>  $buyer  buyers に入れる値（空欄の項目は入れない）
     * @param  string|null  $acquiredDate  取得日（Y-m-d）。誤りなら null
     * @param  list<string>  $errors  誤り（CSV の列の順）
     */
    private function __construct(
        public readonly array $buyer,
        public readonly ?string $acquiredDate,
        public readonly string $projectName,
        public readonly string $staffName,
        public readonly array $errors,
    ) {}

    /** @param  array<string, string>  $values  内部のキー => セルの値（列が無ければ空文字でよい） */
    public static function from(array $values): self
    {
        $parsed = [];
        $errors = [];

        foreach (self::COLUMNS as $label => $key) {
            // 画面の登録は TrimStrings（Str::trim()）が全角スペースも落とすので、CSV も同じ扱いにする
            $cell = Str::trim((string) ($values[$key] ?? ''));

            if ($cell === '') {
                if (in_array($key, self::REQUIRED, true)) {
                    $errors[] = "{$label}が未入力です";
                }

                continue;
            }

            [$value, $error] = self::check($key, $label, $cell, $parsed);

            if ($error !== null) {
                $errors[] = $error;

                continue;
            }

            $parsed[$key] = $value;
        }

        return new self(
            array_diff_key($parsed, array_flip(self::NOT_BUYER)),
            $parsed['acquired_date'] ?? null,
            $parsed['project_name'] ?? '',
            $parsed['staff_name'] ?? '',
            $errors,
        );
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** 1 行の誤りを 1 件の文にする（プレビューの「⚠ 行N: …」） */
    public function errorMessage(): string
    {
        return implode('／', $this->errors);
    }

    /**
     * 空欄でないセルを 1 つ検査する。
     *
     * @param  array<string, string|int>  $parsed  ここまでの列の読めた値（元号の範囲は、先に読んだ生年月日で見る）
     * @return array{0: string|int|null, 1: string|null} [保存する値, 誤り]
     */
    private static function check(string $key, string $label, string $cell, array $parsed): array
    {
        if (isset(self::MAX_INTEGER[$key])) {
            return self::integer($label, $cell, self::MAX_INTEGER[$key]);
        }

        return match ($key) {
            'birth_date', 'acquired_date' => self::date($label, $cell),
            'birth_era'    => self::era($cell, $parsed['birth_date'] ?? null),
            // 検査しない（保存せず、分譲地を探すのに使うだけ）
            'project_name' => [$cell, null],
            default        => self::text($label, $cell, self::MAX_LENGTH[$key]),
        };
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function date(string $label, string $cell): array
    {
        $date = CsvDate::normalize($cell);

        if ($date === null) {
            return [null, "{$label}「{$cell}」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）"];
        }

        return [$date, null];
    }

    /**
     * 元号を S・H・R にする。生年月日が読めた行では、その年が元号の範囲にあるかも見る
     * （CSV は元号と生年月日が別の列なので「S と 2000 年」のような食い違いが起きやすい。
     * そのままだと詳細画面に「S.75年」と出る）。生年月日が空欄か誤りなら範囲は見ない。
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function era(string $cell, ?string $birthDate): array
    {
        $code = self::eraCode($cell);

        if ($code === null) {
            return [null, "元号「{$cell}」は S・H・R（昭和・平成・令和）で入力してください"];
        }

        [$name, $first, $last] = self::ERAS[$code];

        if ($birthDate !== null) {
            $year = (int) substr($birthDate, 0, 4);

            if ($year < $first || ($last !== null && $year > $last)) {
                $range = $last === null ? "{$name}は{$first}年から" : "{$name}は{$first}〜{$last}年";

                return [null, "元号「{$cell}」と生年月日「{$birthDate}」が合いません（{$range}）"];
            }
        }

        return [$code, null];
    }

    /** S・H・R（全角・小文字も可）か 昭和・平成・令和 を記号にする。どれでもなければ null */
    private static function eraCode(string $cell): ?string
    {
        $letter = strtoupper(mb_convert_kana($cell, 'a'));

        if (isset(self::ERAS[$letter])) {
            return $letter;
        }

        foreach (self::ERAS as $code => [$name]) {
            if ($cell === $name) {
                return $code;
            }
        }

        return null;
    }

    /**
     * 0 から上限までの整数。全角の数字は半角に直す。先頭の 0 は可（02 は 2）。
     * 桁あふれするほど長い数字は、(int) が PHP_INT_MAX に張り付くので上限で落ちる。
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function integer(string $label, string $cell, int $max): array
    {
        $digits = mb_convert_kana($cell, 'n');

        if (preg_match('/^[0-9]+$/', $digits) !== 1 || (int) $digits > $max) {
            return [null, "{$label}「{$cell}」は0〜{$max}の整数で入力してください"];
        }

        return [(int) $digits, null];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function text(string $label, string $cell, int $max): array
    {
        $length = mb_strlen($cell);

        if ($length > $max) {
            return [null, "{$label}は{$max}文字以内で入力してください（{$length}文字）"];
        }

        return [$cell, null];
    }
}
