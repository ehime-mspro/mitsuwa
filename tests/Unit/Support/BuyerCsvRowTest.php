<?php

namespace Tests\Unit\Support;

use App\Support\BuyerCsvRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 顧客 CSV 取込の 1 行の検査と変換（設計書 docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md §4.3）。
 *
 * ⚠ 下の「誤り」の値は、どれも直す前の取込を素通りして、壊れた値を書くか全行を巻き戻していたもの（設計書 §2.2）。
 *   消さないこと。
 * ⚠ 上限の数は本番の列の大きさ（設計書 §2.6）。クラスの定数を読まずに数を書く（読むと同じ式で確かめることになる）。
 * ⚠ Laravel を起動しないテスト。`BuyerCsvRow` は時刻も timezone も使わないので、固定は要らない（Bug #54 ①）。
 */
class BuyerCsvRowTest extends TestCase
{
    /** 必須の 3 項目が入った行。ほかの列は空欄（$cells で足す・上書きする） */
    private function row(array $cells = []): BuyerCsvRow
    {
        return BuyerCsvRow::from($cells + [
            'last_name' => '山田', 'first_name' => '太郎', 'acquired_date' => '2026-09-01',
        ]);
    }

    // ================================================================
    // 必須・空欄・前後の空白
    // ================================================================

    public function test_a_row_with_only_the_required_columns_is_valid(): void
    {
        $row = $this->row();

        $this->assertFalse($row->hasErrors());
        $this->assertSame([], $row->errors);
        $this->assertSame(['last_name' => '山田', 'first_name' => '太郎'], $row->buyer);
        $this->assertSame('2026-09-01', $row->acquiredDate);
        $this->assertSame('', $row->projectName);
        $this->assertSame('', $row->staffName);
    }

    public function test_the_three_required_columns_report_their_own_messages(): void
    {
        $row = BuyerCsvRow::from([]);

        $this->assertTrue($row->hasErrors());
        $this->assertSame(['姓が未入力です', '名が未入力です', '取得日が未入力です'], $row->errors);
        $this->assertNull($row->acquiredDate);
    }

    public function test_blank_cells_are_left_out_of_the_buyer_values(): void
    {
        $row = $this->row(['last_name_kana' => '', 'city' => '   ', 'birth_era' => '']);

        $this->assertFalse($row->hasErrors());
        $this->assertSame(['last_name', 'first_name'], array_keys($row->buyer));
    }

    public function test_cells_are_trimmed(): void
    {
        $row = $this->row(['last_name' => '  山田 ', 'city' => "松山市\r", 'staff_name' => ' 田中 ']);

        $this->assertSame('山田', $row->buyer['last_name']);
        $this->assertSame('松山市', $row->buyer['city']);
        $this->assertSame('田中', $row->staffName);
    }

    // 直す前: trim() は ASCII の空白しか落とさない。画面の登録は TrimStrings（Str::trim()）が全角スペースも落とす
    public function test_a_cell_of_only_full_width_spaces_is_blank(): void
    {
        $row = BuyerCsvRow::from(['last_name' => '　', 'first_name' => '太郎', 'acquired_date' => '2026-09-01']);

        $this->assertSame(['姓が未入力です'], $row->errors);
    }

    public function test_full_width_spaces_around_a_cell_are_trimmed(): void
    {
        $row = $this->row(['last_name' => '　山田　', 'birth_era' => '昭和　', 'family_adults' => '２　', 'acquired_date' => '2026/9/1　']);

        $this->assertSame([], $row->errors);
        $this->assertSame('山田', $row->buyer['last_name']);
        $this->assertSame('S', $row->buyer['birth_era']);
        $this->assertSame(2, $row->buyer['family_adults']);
        $this->assertSame('2026-09-01', $row->acquiredDate);
    }

    public function test_every_column_goes_to_the_right_place(): void
    {
        $row = BuyerCsvRow::from([
            'last_name' => '山田', 'first_name' => '太郎', 'last_name_kana' => 'ヤマダ', 'first_name_kana' => 'タロウ',
            'birth_date' => '1980/1/2', 'birth_era' => '昭和', 'family_adults' => '２', 'family_children' => '1',
            'postal_code' => '790-0001', 'prefecture' => '愛媛県', 'city' => '松山市', 'address_detail' => '一番町1-1',
            'building_name' => 'ミツワビル', 'phone' => '089-000-0000', 'email' => 'taro@example.com',
            'occupation' => '会社員', 'employer' => '株式会社ミツワ', 'years_employed' => '12',
            'acquired_date' => '2026/9/1', 'project_name' => '南梅本の杜', 'staff_name' => '田中',
        ]);

        $this->assertSame([], $row->errors);
        $this->assertSame([
            'last_name' => '山田', 'first_name' => '太郎', 'last_name_kana' => 'ヤマダ', 'first_name_kana' => 'タロウ',
            'birth_date' => '1980-01-02', 'birth_era' => 'S', 'family_adults' => 2, 'family_children' => 1,
            'postal_code' => '790-0001', 'prefecture' => '愛媛県', 'city' => '松山市', 'address_detail' => '一番町1-1',
            'building_name' => 'ミツワビル', 'phone' => '089-000-0000', 'email' => 'taro@example.com',
            'occupation' => '会社員', 'employer' => '株式会社ミツワ', 'years_employed' => 12,
        ], $row->buyer);
        $this->assertSame('2026-09-01', $row->acquiredDate);
        $this->assertSame('南梅本の杜', $row->projectName);
        $this->assertSame('田中', $row->staffName);
    }

    // ================================================================
    // 日付（CsvDate::normalize()。checkdate() で存在を見る）
    // ================================================================

    /** @return array<string, array{0: string, 1: string}> [内部のキー, 見出し] */
    public static function dateColumns(): array
    {
        return [
            '生年月日' => ['birth_date', '生年月日'],
            '取得日'   => ['acquired_date', '取得日'],
        ];
    }

    #[DataProvider('dateColumns')]
    public function test_a_date_that_cannot_be_read_is_an_error(string $key, string $label): void
    {
        // 直す前: 19800102 は date キャストが Unix 時刻として読み 1970-08-18 に、
        // 2026-02-30 は 3/2 に繰り上がり、昭和55年1月2日 は確定で例外になり全行が巻き戻った
        foreach (['19800102', '2026-02-30', '1980-02-30', '昭和55年1月2日'] as $value) {
            $row = $this->row([$key => $value]);

            $this->assertSame(
                ["{$label}「{$value}」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）"],
                $row->errors,
                "{$label} {$value}"
            );
        }
    }

    #[DataProvider('dateColumns')]
    public function test_a_date_is_normalized_to_hyphens(string $key): void
    {
        // 直す前: 2026/9/1 は取得日の正規表現（2 桁を要求）で、1970-01-01 は strtotime() が 0 を返して弾かれた
        foreach (['2026/9/1' => '2026-09-01', '2026-09-01' => '2026-09-01', '1970-01-01' => '1970-01-01'] as $value => $expected) {
            $row = $this->row([$key => $value]);

            $this->assertSame([], $row->errors, "{$key} {$value}");
            $this->assertSame($expected, $key === 'acquired_date' ? $row->acquiredDate : $row->buyer[$key]);
        }
    }

    // ================================================================
    // 元号
    // ================================================================

    public function test_an_era_is_accepted_as_a_letter_in_any_width_or_case_or_as_its_name(): void
    {
        $cases = [
            'S' => 'S', 's' => 'S', 'Ｓ' => 'S', 'ｓ' => 'S', '昭和' => 'S',
            'H' => 'H', 'ｈ' => 'H', '平成' => 'H',
            'R' => 'R', 'Ｒ' => 'R', '令和' => 'R',
        ];

        foreach ($cases as $value => $expected) {
            $row = $this->row(['birth_era' => $value]);

            $this->assertSame([], $row->errors, "元号 {$value}");
            $this->assertSame($expected, $row->buyer['birth_era'], "元号 {$value}");
        }
    }

    public function test_an_unknown_era_is_an_error(): void
    {
        foreach (['明治', 'T', 'S.', '昭'] as $value) {
            $this->assertSame(
                ["元号「{$value}」は S・H・R（昭和・平成・令和）で入力してください"],
                $this->row(['birth_era' => $value])->errors,
                "元号 {$value}"
            );
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null}> [元号, 生年月日, 誤り（合えば null）] */
    public static function eraRanges(): array
    {
        return [
            '昭和の前の年'       => ['S', '1925-12-31', '元号「S」と生年月日「1925-12-31」が合いません（昭和は1926〜1989年）'],
            '昭和の最初の年'     => ['S', '1926-01-01', null],
            '昭和の最後の年'     => ['S', '1989-01-07', null],
            '昭和の次の年'       => ['S', '1990-01-01', '元号「S」と生年月日「1990-01-01」が合いません（昭和は1926〜1989年）'],
            '平成の最初の年'     => ['H', '1989-01-08', null],
            '平成の前の年'       => ['H', '1988-12-31', '元号「H」と生年月日「1988-12-31」が合いません（平成は1989〜2019年）'],
            '平成の最後の年'     => ['H', '2019-04-30', null],
            '平成の次の年'       => ['H', '2020-01-01', '元号「H」と生年月日「2020-01-01」が合いません（平成は1989〜2019年）'],
            '令和の前の年'       => ['R', '2018-12-31', '元号「R」と生年月日「2018-12-31」が合いません（令和は2019年から）'],
            '令和の最初の年'     => ['R', '2019-05-01', null],
            '名前で書いた元号'   => ['昭和', '2000-01-01', '元号「昭和」と生年月日「2000-01-01」が合いません（昭和は1926〜1989年）'],
        ];
    }

    #[DataProvider('eraRanges')]
    public function test_an_era_must_match_the_year_of_birth(string $era, string $birthDate, ?string $error): void
    {
        $row = $this->row(['birth_era' => $era, 'birth_date' => $birthDate]);

        $this->assertSame($error === null ? [] : [$error], $row->errors);
    }

    public function test_an_era_without_a_birth_date_is_kept_and_not_range_checked(): void
    {
        $row = $this->row(['birth_era' => 'R']);

        $this->assertSame([], $row->errors);
        $this->assertSame('R', $row->buyer['birth_era']);
        $this->assertArrayNotHasKey('birth_date', $row->buyer);
    }

    public function test_an_era_is_not_range_checked_against_a_birth_date_that_cannot_be_read(): void
    {
        $row = $this->row(['birth_era' => 'R', 'birth_date' => '1980-02-30']);

        $this->assertSame(['生年月日「1980-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'], $row->errors);
    }

    // ================================================================
    // 整数（人数・勤続年数）
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: int}> [内部のキー, 見出し, 上限] */
    public static function integerColumns(): array
    {
        return [
            '大人人数' => ['family_adults', '大人人数', 255],
            '子供人数' => ['family_children', '子供人数', 255],
            '勤続年数' => ['years_employed', '勤続年数', 65535],
        ];
    }

    #[DataProvider('integerColumns')]
    public function test_an_integer_is_read_up_to_its_limit(string $key, string $label, int $max): void
    {
        $cases = ['0' => 0, '02' => 2, '２' => 2, (string) $max => $max];

        foreach ($cases as $value => $expected) {
            $row = $this->row([$key => (string) $value]);

            $this->assertSame([], $row->errors, "{$label} {$value}");
            $this->assertSame($expected, $row->buyer[$key], "{$label} {$value}");
        }
    }

    #[DataProvider('integerColumns')]
    public function test_a_value_that_is_not_an_integer_in_range_is_an_error(string $key, string $label, int $max): void
    {
        // 直す前: 2人 は SQLite にそのまま入り、本番の MySQL（strict モード）では全行が巻き戻った
        foreach ([(string) ($max + 1), '2人', '-1', '1.5', '99999999999999999999'] as $value) {
            $this->assertSame(
                ["{$label}「{$value}」は0〜{$max}の整数で入力してください"],
                $this->row([$key => $value])->errors,
                "{$label} {$value}"
            );
        }
    }

    // ================================================================
    // 文字数（全角で数える）
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: int}> [内部のキー, 見出し, 上限] */
    public static function textColumns(): array
    {
        return [
            '姓'             => ['last_name', '姓', 50],
            '名'             => ['first_name', '名', 50],
            'セイ'           => ['last_name_kana', 'セイ', 50],
            'メイ'           => ['first_name_kana', 'メイ', 50],
            '郵便番号'       => ['postal_code', '郵便番号', 10],
            '都道府県'       => ['prefecture', '都道府県', 10],
            '市区町村'       => ['city', '市区町村', 50],
            '住所詳細'       => ['address_detail', '住所詳細', 255],
            '建物名'         => ['building_name', '建物名', 255],
            '電話番号'       => ['phone', '電話番号', 20],
            'メールアドレス' => ['email', 'メールアドレス', 255],
            '職業'           => ['occupation', '職業', 50],
            '勤務先'         => ['employer', '勤務先', 100],
            '担当者名'       => ['staff_name', '担当者名', 100],
        ];
    }

    #[DataProvider('textColumns')]
    public function test_text_up_to_the_limit_is_kept(string $key, string $label, int $max): void
    {
        $row = $this->row([$key => str_repeat('あ', $max)]);

        $this->assertSame([], $row->errors, $label);
        $this->assertSame(str_repeat('あ', $max), $key === 'staff_name' ? $row->staffName : $row->buyer[$key]);
    }

    #[DataProvider('textColumns')]
    public function test_text_over_the_limit_is_an_error(string $key, string $label, int $max): void
    {
        // 担当者名は、回答が空欄でアンケートを作らない行でも見る（行によって規則が変わらないように）
        $this->assertSame(
            ["{$label}は{$max}文字以内で入力してください（" . ($max + 1) . '文字）'],
            $this->row([$key => str_repeat('あ', $max + 1)])->errors,
            $label
        );
    }

    public function test_the_project_name_is_not_checked(): void
    {
        $row = $this->row(['project_name' => str_repeat('あ', 300)]);

        $this->assertSame([], $row->errors);
        $this->assertSame(str_repeat('あ', 300), $row->projectName);
        $this->assertArrayNotHasKey('project_name', $row->buyer);
    }

    // ================================================================
    // 1 行に誤りが複数あるとき
    // ================================================================

    public function test_every_error_in_a_row_is_reported_in_column_order_and_joined(): void
    {
        $row = BuyerCsvRow::from([
            'first_name' => '太郎', 'birth_date' => '19800102', 'birth_era' => '明治',
            'family_adults' => '2人', 'city' => str_repeat('あ', 51), 'acquired_date' => '2026-02-30',
        ]);

        $expected = [
            '姓が未入力です',
            '生年月日「19800102」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）',
            '元号「明治」は S・H・R（昭和・平成・令和）で入力してください',
            '大人人数「2人」は0〜255の整数で入力してください',
            '市区町村は50文字以内で入力してください（51文字）',
            '取得日「2026-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）',
        ];

        $this->assertSame($expected, $row->errors);
        $this->assertSame(implode('／', $expected), $row->errorMessage());
        $this->assertNull($row->acquiredDate);
    }

    public function test_the_columns_follow_the_template_order(): void
    {
        // 誤りの並び（CSV の列の順）と、コントローラが見出しを引く表はこの並びに依存する
        $this->assertSame([
            '姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数', '郵便番号', '都道府県',
            '市区町村', '住所詳細', '建物名', '電話番号', 'メールアドレス', '職業', '勤務先', '勤続年数',
            '取得日', '来場分譲地名', '担当者名',
        ], array_keys(BuyerCsvRow::COLUMNS));
    }
}
