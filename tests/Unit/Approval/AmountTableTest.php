<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\AmountTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 金額の明細表の形と計算（要件 5.5.4・段階5 設計書 §5.5〜§5.8・D2・D3・D15・D16） */
class AmountTableTest extends TestCase
{
    /** 要件 5.5.7 の請負新築工事契約の設定（前半: 工事請負金額・自由行・紹介料／「計」あり／後半: 土地契約金額・自由行） */
    private const LAYOUT = ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]];

    private static function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 に利用者が見た申請の画面の見本の数 */
    private static function sample(bool $subtotal = true): array
    {
        return [
            'subtotal' => $subtotal,
            'upper'    => [
                self::row('工事請負金額', true, 28500000, 22000000),
                self::row('オプション工事', false, 1200000, 850000),
                self::row('紹介料', true, 0, 300000),
            ],
            'lower' => [
                self::row('土地契約金額', true, 12000000, 10500000),
                self::row(null, false, null, null),
            ],
        ];
    }

    public function test_the_layout_trims_the_names_and_an_empty_name_is_a_free_row(): void
    {
        $this->assertSame(
            ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => [null]],
            AmountTable::layout(true, [' 工事請負金額　', '', '紹介料'], ['　'])
        );
        $this->assertSame(['subtotal' => false, 'upper' => [], 'lower' => []], AmountTable::layout(false, [], []));
    }

    public function test_the_subtotal_and_the_total_follow_the_paper_form(): void
    {
        $totals = AmountTable::totals(self::sample());

        // 計＝前半の合計・合計金額＝計＋後半。粗利益金額＝販売金額−工事原価・粗利率は小数第 1 位
        $this->assertSame(['sale' => 29700000, 'cost' => 23150000, 'profit' => 6550000, 'rate' => 22.1], $totals['subtotal']);
        $this->assertSame(['sale' => 41700000, 'cost' => 33650000, 'profit' => 8050000, 'rate' => 19.3], $totals['total']);

        // 「計」を使わない種類は計が無い
        $this->assertNull(AmountTable::totals(self::sample(false))['subtotal']);
        $this->assertSame(41700000, AmountTable::totals(self::sample(false))['total']['sale']);
    }

    public function test_the_rate_of_a_zero_sale_is_a_dash_and_minus_amounts_are_added(): void
    {
        $this->assertNull(AmountTable::rate(0, -300000));
        $this->assertSame('—', AmountTable::rateLabel(null));
        $this->assertSame('22.1%', AmountTable::rateLabel(22.1));
        $this->assertSame('-5.0%', AmountTable::rateLabel(-5.0));
        $this->assertSame('-5000.0%', AmountTable::rateLabel(AmountTable::rate(1, -50)), '3 桁の区切りは付けない（画面の JS と同じ）');

        // 値引きの行（マイナス。D3）も足す
        $table = ['subtotal' => false, 'upper' => [self::row('工事請負金額', true, 10000000, 8000000), self::row('値引き', false, -300000, null)], 'lower' => []];
        $this->assertSame(['sale' => 9700000, 'cost' => 8000000, 'profit' => 1700000, 'rate' => 17.5], AmountTable::totals($table)['total']);
        $this->assertSame('-300,000円', AmountTable::yen(-300000));
        $this->assertSame('', AmountTable::yen(null));
    }

    /**
     * 粗利率の四捨五入の境目（点検の I-1。浮動小数の割り算では 63.75% が 63.7499…% になり、画面の JS は 63.7・PHP 8.3 は 63.8・
     * PHP 8.4 からは 63.7 と答えが割れていた）。整数で計算し、0 から遠い方へ丸める
     *
     * @return array<string, array{int, int, float}>
     */
    public static function rateEdges(): array
    {
        return [
            '63.75% は 63.8'              => [8_000_000, 5_100_000, 63.8],
            '-63.75% は -63.8'            => [8_000_000, -5_100_000, -63.8],
            '12.25% は 12.3'              => [400, 49, 12.3],
            '12.2499…% は 12.2'           => [400_000_001, 49_000_000, 12.2],
            '3 分の 1 は 33.3'            => [3, 1, 33.3],
            '3 分の 2 は 66.7'            => [3, 2, 66.7],
            '販売金額もマイナス'          => [-1_000, -300, 30.0],
            '販売金額だけマイナス'        => [-1_000, 300, -30.0],
            '粗利益 0'                    => [-1_000, 0, 0.0],
            '12 桁どうし'                 => [999_999_999_999, 999_999_999_999, 100.0],
            '粗利益がいちばん大きいとき'  => [999_999_999_999, 1_999_999_999_998, 200.0],
        ];
    }

    #[DataProvider('rateEdges')]
    public function test_the_rate_is_rounded_away_from_zero_with_integers(int $sale, int $profit, float $rate): void
    {
        $this->assertSame($rate, AmountTable::rate($sale, $profit));
    }

    public function test_the_amount_is_the_total_sale_only_when_it_is_more_than_zero(): void
    {
        $this->assertSame(41700000, AmountTable::amount(self::sample()));

        $zero = ['subtotal' => false, 'upper' => [self::row('工事請負金額', true, null, 5000)], 'lower' => []];
        $this->assertNull(AmountTable::amount($zero));

        $minus = ['subtotal' => false, 'upper' => [self::row('値引き', false, -1, null)], 'lower' => []];
        $this->assertNull(AmountTable::amount($minus));
        $this->assertNull(AmountTable::amount([]));
    }

    public function test_the_shown_rows_hide_empty_free_rows_and_carry_the_profit_and_the_rate(): void
    {
        $upper = AmountTable::shownRows(self::sample(), 'upper');
        $lower = AmountTable::shownRows(self::sample(), 'lower');

        $this->assertSame(['工事請負金額', 'オプション工事', '紹介料'], array_column($upper, 'name'));
        $this->assertSame([6500000, 350000, -300000], array_column($upper, 'profit'));
        $this->assertSame([22.8, 29.2, null], array_column($upper, 'rate'));
        $this->assertSame(['土地契約金額'], array_column($lower, 'name'), '名前も金額も無い自由行は出さない');

        // 名前を設定した行は金額が空でも出す（粗利益金額も粗利率も無い）
        $empty = AmountTable::shownRows(['upper' => [self::row('紹介料', true, null, null)]], 'upper');
        $this->assertSame([['name' => '紹介料', 'fixed' => true, 'sale' => null, 'cost' => null, 'profit' => null, 'rate' => null]], $empty);

        // 名前だけの自由行・金額だけの自由行は出す
        $this->assertCount(2, AmountTable::shownRows(['upper' => [self::row('追加工事', false, null, null), self::row(null, false, null, 1)]], 'upper'));
    }

    /**
     * 販売金額だけ・工事原価だけの行も、空の側を 0 として粗利益金額と粗利率を出す（片方だけ空の行を「どちらも無い」にしない。
     * 最終点検 L87 の (c)。販売金額が 0 の行の粗利率は無い）
     */
    public function test_a_row_with_only_the_sale_or_only_the_cost_still_carries_the_profit_and_the_rate(): void
    {
        $rows = AmountTable::shownRows(['upper' => [
            self::row('販売だけ', false, 1000, null),
            self::row('原価だけ', false, null, 400),
            self::row('原価だけ（マイナス）', false, null, -400),
            self::row('販売だけ（マイナス）', false, -300, null),
        ]], 'upper');

        $this->assertSame([1000, -400, 400, -300], array_column($rows, 'profit'));
        $this->assertSame([100.0, null, null, 100.0], array_column($rows, 'rate'), '販売金額が空（0）の行の粗利率は無い');
    }

    public function test_an_amount_without_a_name_is_found(): void
    {
        $this->assertFalse(AmountTable::hasUnnamedAmount(self::sample()));
        $this->assertTrue(AmountTable::hasUnnamedAmount(['lower' => [self::row(null, false, null, 1)]]));
        $this->assertTrue(AmountTable::hasUnnamedAmount(['upper' => [self::row(null, false, 0, null)]]), '0 円も金額');
        $this->assertFalse(AmountTable::hasUnnamedAmount(['upper' => [self::row(null, false, null, null)]]));
    }

    public function test_the_input_is_cleaned_before_the_check(): void
    {
        $clean = AmountTable::cleanInput([
            'upper' => [
                ['fixed' => '工事請負金額', 'sale' => '28,500,000円', 'cost' => '２２，０００，０００'],
                ['name' => '値引き', 'sale' => '−300,000', 'cost' => ''],
                'こわれた行',
                ['name' => '文字', 'sale' => 'abc', 'fixed' => ['x']],
            ],
            'lower' => 'こわれた側',
        ]);

        $this->assertSame([
            ['name' => null, 'fixed' => '工事請負金額', 'sale' => '28500000', 'cost' => '22000000'],
            ['name' => '値引き', 'fixed' => null, 'sale' => '-300000', 'cost' => null],
            ['name' => '文字', 'fixed' => null, 'sale' => 'abc', 'cost' => null],
        ], $clean['upper']);
        $this->assertSame([], $clean['lower']);
        $this->assertSame(['upper' => [], 'lower' => []], AmountTable::cleanInput('こわれた表'));
    }

    public function test_the_server_decides_which_rows_have_a_fixed_name(): void
    {
        $table = AmountTable::fromInput(self::LAYOUT, AmountTable::cleanInput([
            'upper' => [
                ['fixed' => '工事請負金額', 'sale' => '1000', 'cost' => '600'],
                ['name' => ' オプション工事 ', 'sale' => '200', 'cost' => '100'],
                ['fixed' => '工事請負金額', 'name' => '二度目', 'sale' => '5', 'cost' => null],
                ['fixed' => '設定に無い名前', 'name' => '自由行', 'sale' => '7', 'cost' => null],
            ],
            'lower' => [['name' => '', 'sale' => null, 'cost' => null]],
        ]));

        $this->assertTrue($table['subtotal']);
        $this->assertSame([
            self::row('工事請負金額', true, 1000, 600),
            self::row('オプション工事', false, 200, 100),
            self::row('二度目', false, 5, null),           // 同じ名前を設定した行は 1 回だけ
            self::row('自由行', false, 7, null),           // 設定に無い名前は自由行
            self::row('紹介料', true, null, null),         // 送られてこなかった名前を設定した行は後ろに空で足す
        ], $table['upper']);
        $this->assertSame([self::row(null, false, null, null), self::row('土地契約金額', true, null, null)], $table['lower']);
    }

    /** 名前を設定した行が送られてこなければ、同じ名前の自由行（最初の 1 つ）を名前を設定した行にする（forForm と同じ規則。D16） */
    public function test_the_server_takes_a_free_row_of_the_same_name_when_the_fixed_row_is_not_sent(): void
    {
        $table = AmountTable::fromInput(self::LAYOUT, AmountTable::cleanInput([
            'upper' => [
                ['name' => '紹介料', 'sale' => '50', 'cost' => null],
                ['fixed' => '工事請負金額', 'sale' => '1000', 'cost' => '600'],
                ['name' => '工事請負金額', 'sale' => '7', 'cost' => null],
                ['name' => '紹介料', 'sale' => '9', 'cost' => null],
            ],
            'lower' => [['name' => '　土地契約金額 ', 'sale' => '3', 'cost' => null]],
        ]));

        $this->assertSame([
            self::row('紹介料', true, 50, null),           // 送られてこなかった名前を設定した行に、同じ名前の自由行を当てる（その位置のまま）
            self::row('工事請負金額', true, 1000, 600),
            self::row('工事請負金額', false, 7, null),     // 名前を設定した行が送られていれば、同じ名前の自由行は自由行のまま
            self::row('紹介料', false, 9, null),           // 当てるのは前から数えて最初の 1 つだけ
        ], $table['upper']);
        $this->assertSame([self::row('土地契約金額', true, 3, null)], $table['lower'], '名前は前後の空白を落として比べる・後ろに空の行を足さない');
    }

    public function test_a_new_request_shows_the_rows_of_the_layout(): void
    {
        $this->assertSame([
            'subtotal' => true,
            'upper'    => [self::row('工事請負金額', true, null, null), self::row(null, false, null, null), self::row('紹介料', true, null, null)],
            'lower'    => [self::row('土地契約金額', true, null, null), self::row(null, false, null, null)],
        ], AmountTable::forForm(self::LAYOUT, null));
    }

    public function test_the_form_keeps_what_was_written_when_the_layout_changed(): void
    {
        $stored = [
            'subtotal' => true,
            'upper'    => [
                self::row('工事請負金額', true, 1000, 600),
                self::row('外構工事', false, 300, 200),
                self::row('紹介料', true, 50, null),
                self::row('追加の行', false, 10, null),
            ],
            'lower' => [self::row('土地契約金額', true, null, null)],
        ];
        // 管理者が「紹介料」を「紹介手数料」に変え、前半の自由行を 1 つ減らし、「計」をやめた
        $layout = ['subtotal' => false, 'upper' => ['工事請負金額', '紹介手数料', null], 'lower' => ['土地契約金額']];

        $this->assertSame([
            'subtotal' => false,
            'upper'    => [
                self::row('工事請負金額', true, 1000, 600),
                self::row('紹介手数料', true, null, null),
                self::row('外構工事', false, 300, 200),     // 自由行の位置に、申請の自由行を順に当てる
                self::row('紹介料', false, 50, null),       // 名前が設定から消えた行は、金額があれば自由行として残す（D16）
                self::row('追加の行', false, 10, null),     // 残った自由行は申請の並びのまま後ろへ
            ],
            'lower' => [self::row('土地契約金額', true, null, null)],
        ], AmountTable::forForm($layout, $stored));

        // 名前が設定から消えた行に金額が無ければ残さない
        $renamed = AmountTable::forForm(['subtotal' => false, 'upper' => ['新しい名前'], 'lower' => []], ['upper' => [self::row('古い名前', true, null, null)]]);
        $this->assertSame([self::row('新しい名前', true, null, null)], $renamed['upper']);
    }

    /**
     * 名前を設定した行が申請に無ければ、同じ名前の自由行（前から数えて最初の 1 つ）を名前を設定した行にする（一度自由行になった
     * 「紹介料」などが、元の種類に戻したときやコピーで種類を選んだときに、空の名前の行と並んで二重にならない。D16・§5.6）
     */
    public function test_the_form_takes_a_free_row_of_the_same_name_into_the_fixed_row(): void
    {
        $stored = [
            'upper' => [
                self::row('外構', false, 1, null),
                self::row('紹介料', false, 50, null),
                self::row('紹介料', false, 60, null),
                self::row('工事請負金額', true, 1000, null),
                self::row('工事請負金額', false, 7, null),
            ],
            'lower' => [self::row(' 土地契約金額　', false, 3, null)],
        ];

        $this->assertSame([
            'subtotal' => true,
            'upper'    => [
                self::row('工事請負金額', true, 1000, null),
                self::row('外構', false, 1, null),
                self::row('紹介料', true, 50, null),        // 同じ名前の自由行を取り込む
                self::row('紹介料', false, 60, null),       // 取り込むのは最初の 1 つだけ
                self::row('工事請負金額', false, 7, null),  // 同じ名前の名前を設定した行があれば、そちらが先（自由行のまま）
            ],
            'lower' => [self::row('土地契約金額', true, 3, null), self::row(null, false, null, null)],
        ], AmountTable::forForm(self::LAYOUT, $stored));

        // 種類が無い（コピーで空にした・5W2H の種類）ときに自由行になった行も、明細表の種類を選べば名前を設定した行に戻る
        $this->assertSame(AmountTable::forForm(self::LAYOUT, self::sample()), AmountTable::forForm(self::LAYOUT, AmountTable::forForm([], self::sample())));
    }

    /**
     * 同じ名前の名前を設定した行が 2 つ来たら、最初の 1 つだけが名前を設定した行で、2 つ目は自由行として残る（金額を上書きして
     * 失わない。古い画面や手で組んだ入力。最終点検 L87 の (a)）
     */
    public function test_a_second_fixed_row_of_the_same_name_becomes_a_free_row(): void
    {
        $stored = ['upper' => [self::row('紹介料', true, 50, 10), self::row('紹介料', true, 60, 20), self::row('紹介料', true, 70, 30)], 'lower' => []];

        $this->assertSame(
            [self::row('紹介料', true, 50, 10), self::row('紹介料', false, 60, 20), self::row('紹介料', false, 70, 30)],
            AmountTable::forForm(['subtotal' => false, 'upper' => ['紹介料'], 'lower' => []], $stored)['upper']
        );
    }

    /**
     * 種類から名前が消えた行は、販売金額だけでも工事原価だけでも入っていれば自由行として残す（黙って消さない。D16。
     * 工事原価だけの行が消えると、粗利益金額がマイナスの行が申請から消える。最終点検 L87 の (b)）
     */
    public function test_a_row_whose_name_left_the_layout_is_kept_when_only_the_sale_or_only_the_cost_is_filled(): void
    {
        $stored = ['upper' => [
            self::row('古い名前A', true, null, 700),
            self::row('古い名前B', true, 800, null),
            self::row('古い名前C', true, null, 0),
            self::row('古い名前D', true, null, null),
        ], 'lower' => []];

        $this->assertSame([
            self::row('新しい名前', true, null, null),
            self::row('古い名前A', false, null, 700),
            self::row('古い名前B', false, 800, null),
            self::row('古い名前C', false, null, 0),   // 0 円も金額
        ], AmountTable::forForm(['subtotal' => false, 'upper' => ['新しい名前'], 'lower' => []], $stored)['upper'], '金額の無い古い名前D だけが消える');
    }

    public function test_broken_rows_are_ignored(): void
    {
        $this->assertSame([], AmountTable::rowsOf(null, 'upper'));
        $this->assertSame([], AmountTable::rowsOf(['upper' => 'こわれた'], 'upper'));
        $this->assertSame([self::row(null, false, 5, null)], AmountTable::rowsOf(['upper' => ['こわれた行', ['sale' => '5']]], 'upper'));
    }
}
