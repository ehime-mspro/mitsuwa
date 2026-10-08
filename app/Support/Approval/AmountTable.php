<?php

namespace App\Support\Approval;

/**
 * 金額の明細表（要件 5.5.4・段階5 設計書 §5.4〜§5.8・D2・D3・D5・D14〜D16）。**形と計算はここ 1 か所**
 * （申請の画面・保存・控え・詳細・変更点・PDF・Excel が共有する。画面の JS の計算も同じ式）。
 *
 * - 種類の行の設定（layout）: `['subtotal' => bool, 'upper' => list<?string>, 'lower' => list<?string>]`。名前が null の行は自由行
 * - 申請の明細表（table）: `['subtotal' => bool, 'upper' => list<Row>, 'lower' => list<Row>]`。
 *   Row は `['name' => ?string, 'fixed' => bool, 'sale' => ?int, 'cost' => ?int]`（fixed は種類に名前を設定した行＝名前は種類のもの・消せない）
 * - 「計」＝前半の行の合計（`subtotal` の種類だけ）、「合計金額」＝前半と後半の行の合計。粗利益金額＝販売金額−工事原価、
 *   粗利率＝粗利益金額÷販売金額×100（小数第 1 位）。販売金額が 0 なら粗利率は無い（画面と PDF は「—」）
 * ⚠ 申請は「計」の有無も自分の明細表に持つ（あとで種類の設定を変えても、提出した申請の見た目は変わらない。D14）
 * ⚠ JSON はオブジェクトのキーを並べ替えて返す（MySQL。RequestSnapshot の注意）ので、行は配列にし、キーで取り出す
 */
final class AmountTable
{
    /** 前半（建物側）・後半（土地側） */
    public const SECTIONS = ['upper', 'lower'];

    /** 種類に設定できる行の数（前半・後半それぞれ） */
    public const LAYOUT_MAX_ROWS = 20;

    /** 申請の行の数の上限（前半・後半それぞれ。種類の行に「行を足す」で足した行を合わせて） */
    public const MAX_ROWS = 30;

    /** 行の名前の文字数 */
    public const NAME_MAX = 30;

    /** 販売金額・工事原価の上限（12 桁。マイナスも同じ桁まで。D3） */
    public const AMOUNT_MAX = 999_999_999_999;

    /**
     * 種類の行の設定をそろえる（⑨ の入力から。名前の前後の空白〈全角を含む〉を落とし、空は自由行）
     *
     * @param array<int, mixed> $upper
     * @param array<int, mixed> $lower
     * @return array{subtotal: bool, upper: list<?string>, lower: list<?string>}
     */
    public static function layout(bool $subtotal, array $upper, array $lower): array
    {
        $names = fn (array $rows): array => array_values(array_map(fn (mixed $name): ?string => self::name($name), $rows));

        return ['subtotal' => $subtotal, 'upper' => $names($upper), 'lower' => $names($lower)];
    }

    /**
     * 申請の画面に出す行（D16）。種類の今の設定の並びに、申請が持つ行を当てる。
     *
     * - 名前を設定した行は、申請の同じ名前の名前を設定した行の金額を当てる。申請に無ければ、同じ名前の自由行（前から数えて
     *   最初の 1 つ）を取り込む（一度自由行になった行が、種類を選び直したときやコピーで種類を選んだときに、空の名前の行と並んで
     *   二重にならない。画面の JS の mergeRows・保存の fromInput も同じ規則）
     * - 種類の自由行の位置には、申請の自由行を順に当てる（足りなければ空の自由行。要件 5.5.4「最初から空欄で出す」）
     * - 残った申請の自由行は、その側の後ろへ。種類から名前が消えた行は、金額が入っていれば自由行として残す（黙って消さない）
     *
     * @param array{subtotal?: bool, upper?: list<?string>, lower?: list<?string>} $layout
     * @param array<string, mixed>|null $table 申請の明細表（無ければ種類の設定のとおりの空の表）
     * @return array{subtotal: bool, upper: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>, lower: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>}
     */
    public static function forForm(array $layout, ?array $table): array
    {
        $result = ['subtotal' => (bool) ($layout['subtotal'] ?? false)];

        foreach (self::SECTIONS as $section) {
            $names = $layout[$section] ?? [];
            $fixed = [];
            $free  = [];
            foreach (self::rowsOf($table, $section) as $row) {
                if ($row['fixed'] && in_array($row['name'], $names, true) && ! isset($fixed[$row['name']])) {
                    $fixed[$row['name']] = $row;
                } elseif (! $row['fixed'] || $row['sale'] !== null || $row['cost'] !== null) {
                    $free[] = ['name' => $row['name'], 'fixed' => false, 'sale' => $row['sale'], 'cost' => $row['cost']];
                }
            }
            // 名前を設定した行が申請に無ければ、同じ名前の自由行（最初の 1 つ）を取り込む
            foreach ($names as $name) {
                $at = ($name === null || isset($fixed[$name])) ? null : self::firstFreeRow($free, $name);
                if ($at !== null) {
                    $fixed[$name] = array_splice($free, $at, 1)[0];
                }
            }

            $rows = [];
            foreach ($names as $name) {
                $rows[] = $name !== null
                    ? ['name' => $name, 'fixed' => true, 'sale' => $fixed[$name]['sale'] ?? null, 'cost' => $fixed[$name]['cost'] ?? null]
                    : (array_shift($free) ?? self::emptyRow());
            }
            $result[$section] = array_merge($rows, $free);
        }

        return $result;
    }

    /**
     * 画面から送られた明細表の形をそろえる（入力の検査の前）。
     *
     * `amount_table[upper][0][name]`・`[fixed]`（名前を設定した行はその名前）・`[sale]`・`[cost]` を読み、配列でない値は捨て、
     * 金額は数字の形にそろえる（FormInput::digits。全角・カンマ・「円」・空白を落とし、マイナスの記号を `-` に）。数でない値はそのまま残して検査で断る
     *
     * @return array{upper: list<array{name: mixed, fixed: ?string, sale: mixed, cost: mixed}>, lower: list<array{name: mixed, fixed: ?string, sale: mixed, cost: mixed}>}
     */
    public static function cleanInput(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $clean = [];

        foreach (self::SECTIONS as $section) {
            $rows = is_array($input[$section] ?? null) ? array_values($input[$section]) : [];
            $clean[$section] = array_values(array_map(fn (array $row): array => [
                'name'  => $row['name'] ?? null,
                'fixed' => is_string($row['fixed'] ?? null) ? $row['fixed'] : null,
                'sale'  => FormInput::digits($row['sale'] ?? null, FormInput::YEN),
                'cost'  => FormInput::digits($row['cost'] ?? null, FormInput::YEN),
            ], array_filter($rows, 'is_array')));
        }

        return $clean;
    }

    /**
     * 検査を通った入力から、保存する明細表を作る（どの行が名前を設定した行かはサーバーが種類の設定で決める。画面を信用しない）。
     *
     * - `fixed` に種類の名前を設定した行の名前が来た行（同じ名前は 1 回だけ）→ 名前を設定した行（名前は種類のもの）
     * - ほかの行 → 自由行（名前は打ったもの）
     * - 送られてこなかった名前を設定した行は、同じ名前の自由行（前から数えて最初の 1 つ）があればその行を名前を設定した行にする
     *   （forForm・画面の mergeRows と同じ規則。古い画面から送ったときなどに、空の名前の行と同じ名前の自由行に分かれない）。
     *   それも無ければ、その側の後ろに空で足す
     *
     * @param array{subtotal?: bool, upper?: list<?string>, lower?: list<?string>} $layout
     * @param array{upper?: list<array<string, mixed>>, lower?: list<array<string, mixed>>} $clean cleanInput() の形（検査済み）
     * @return array{subtotal: bool, upper: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>, lower: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>}
     */
    public static function fromInput(array $layout, array $clean): array
    {
        $table = ['subtotal' => (bool) ($layout['subtotal'] ?? false)];

        foreach (self::SECTIONS as $section) {
            $remaining = array_values(array_filter($layout[$section] ?? [], fn (?string $name) => $name !== null));
            $rows      = [];

            foreach ($clean[$section] ?? [] as $row) {
                $at   = $row['fixed'] === null ? false : array_search($row['fixed'], $remaining, true);
                $sale = self::intOrNull($row['sale'] ?? null);
                $cost = self::intOrNull($row['cost'] ?? null);

                if ($at !== false) {
                    unset($remaining[$at]);
                    $rows[] = ['name' => $row['fixed'], 'fixed' => true, 'sale' => $sale, 'cost' => $cost];
                } else {
                    $rows[] = ['name' => self::name($row['name'] ?? null), 'fixed' => false, 'sale' => $sale, 'cost' => $cost];
                }
            }

            // 送られてこなかった名前を設定した行: 同じ名前の自由行（最初の 1 つ）をその位置のまま名前を設定した行にする。無ければ後ろに空で足す
            foreach ($remaining as $name) {
                $at = self::firstFreeRow($rows, $name);
                if ($at !== null) {
                    $rows[$at]['fixed'] = true;
                } else {
                    $rows[] = ['name' => $name, 'fixed' => true, 'sale' => null, 'cost' => null];
                }
            }

            $table[$section] = $rows;
        }

        return $table;
    }

    /**
     * 「計」（前半の合計。使う種類だけ）と「合計金額」（前半と後半の合計）。空の金額は 0 として足す
     *
     * @param array<string, mixed> $table
     * @return array{subtotal: ?array{sale: int, cost: int, profit: int, rate: ?float}, total: array{sale: int, cost: int, profit: int, rate: ?float}}
     */
    public static function totals(array $table): array
    {
        [$upperSale, $upperCost] = self::sums(self::rowsOf($table, 'upper'));
        [$lowerSale, $lowerCost] = self::sums(self::rowsOf($table, 'lower'));

        return [
            'subtotal' => ($table['subtotal'] ?? false) ? self::line($upperSale, $upperCost) : null,
            'total'    => self::line($upperSale + $lowerSale, $upperCost + $lowerCost),
        ];
    }

    /**
     * 申請の金額（合計金額の販売金額。0 円より大きいときだけ。D15）。台帳・検索・PDF の「金額」はこれ
     *
     * @param array<string, mixed> $table
     */
    public static function amount(array $table): ?int
    {
        $sale = self::totals($table)['total']['sale'];

        return $sale > 0 ? $sale : null;
    }

    /**
     * 詳細と PDF に出す行（名前も金額も無い自由行は出さない。要件 5.5.4）。粗利益金額と粗利率を添える
     *
     * @param array<string, mixed> $table
     * @return list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int, profit: ?int, rate: ?float}>
     */
    public static function shownRows(array $table, string $section): array
    {
        $rows = [];
        foreach (self::rowsOf($table, $section) as $row) {
            if ($row['fixed'] || $row['name'] !== null || $row['sale'] !== null || $row['cost'] !== null) {
                $rows[] = $row + self::rowLine($row);
            }
        }

        return $rows;
    }

    /**
     * 名前のない行に金額が入っているか（提出できない。D2）
     *
     * @param array<string, mixed> $table
     */
    public static function hasUnnamedAmount(array $table): bool
    {
        foreach (self::SECTIONS as $section) {
            foreach (self::rowsOf($table, $section) as $row) {
                if ($row['name'] === null && ($row['sale'] !== null || $row['cost'] !== null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 粗利率（％・小数第 1 位。四捨五入は 0 から遠い方へ）。販売金額が 0 なら無い。
     * ⚠ 整数で計算する（画面の JS も同じ式）。浮動小数の割り算は 63.75% を 63.7499…% にし、PHP の `round()` は版で丸め方が違う
     *   （8.3 は 63.8・8.4 からは 63.7。粗利率は保存せず表示のたびに計算するので、版を上げると決裁済みの数が変わる）。
     *   |粗利益| は 2 兆円まで（合計の 12 桁の上限。RequestFields::totalError）なので、2,000 倍しても int に収まる
     */
    public static function rate(int $sale, int $profit): ?float
    {
        if ($sale === 0) {
            return null;
        }
        // |粗利益| ÷ |販売金額| × 1,000（＝粗利率の 10 倍）を四捨五入した整数
        $tenths = intdiv(abs($profit) * 2000 + abs($sale), abs($sale) * 2);

        return (($profit < 0) !== ($sale < 0) ? -$tenths : $tenths) / 10;
    }

    /** 粗利率の表示（「12.3%」。3 桁の区切りは付けない＝画面の JS と同じ。無ければ「—」） */
    public static function rateLabel(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1, '.', '') . '%';
    }

    /** 金額の表示（「1,200,000円」「-300,000円」。無ければ空。規約: `¥` を付けない） */
    public static function yen(?int $value): string
    {
        return $value === null ? '' : number_format($value) . '円';
    }

    /**
     * 申請の明細表の 1 つの側の行（形の崩れた行は捨てる＝古い控えや手で組んだ JSON でも落ちない）
     *
     * @param array<string, mixed>|null $table
     * @return list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>
     */
    public static function rowsOf(?array $table, string $section): array
    {
        $rows = [];
        foreach (is_array($table[$section] ?? null) ? $table[$section] : [] as $row) {
            if (is_array($row)) {
                $rows[] = [
                    'name'  => self::name($row['name'] ?? null),
                    'fixed' => (bool) ($row['fixed'] ?? false),
                    'sale'  => self::intOrNull($row['sale'] ?? null),
                    'cost'  => self::intOrNull($row['cost'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * 名前を設定した行が申請に無いときに取り込む、同じ名前の自由行（前から数えて最初の 1 つ）の位置（forForm・fromInput。D16）
     *
     * @param list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}> $rows
     */
    private static function firstFreeRow(array $rows, string $name): ?int
    {
        foreach ($rows as $at => $row) {
            if (! $row['fixed'] && $row['name'] === $name) {
                return $at;
            }
        }

        return null;
    }

    /** @return array{name: null, fixed: false, sale: null, cost: null} */
    private static function emptyRow(): array
    {
        return ['name' => null, 'fixed' => false, 'sale' => null, 'cost' => null];
    }

    /**
     * 1 行の粗利益金額と粗利率（販売金額も工事原価も空なら、どちらも無い）
     *
     * @param array{sale: ?int, cost: ?int} $row
     * @return array{profit: ?int, rate: ?float}
     */
    private static function rowLine(array $row): array
    {
        if ($row['sale'] === null && $row['cost'] === null) {
            return ['profit' => null, 'rate' => null];
        }
        $line = self::line($row['sale'] ?? 0, $row['cost'] ?? 0);

        return ['profit' => $line['profit'], 'rate' => $line['rate']];
    }

    /** @return array{sale: int, cost: int, profit: int, rate: ?float} */
    private static function line(int $sale, int $cost): array
    {
        return ['sale' => $sale, 'cost' => $cost, 'profit' => $sale - $cost, 'rate' => self::rate($sale, $sale - $cost)];
    }

    /**
     * @param list<array{sale: ?int, cost: ?int}> $rows
     * @return array{0: int, 1: int}
     */
    private static function sums(array $rows): array
    {
        $sale = 0;
        $cost = 0;
        foreach ($rows as $row) {
            $sale += $row['sale'] ?? 0;
            $cost += $row['cost'] ?? 0;
        }

        return [$sale, $cost];
    }

    /** 行の名前（前後の空白〈全角を含む〉を落とし、空は null） */
    private static function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $name = FormInput::trim($value);

        return $name === '' ? null : $name;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
