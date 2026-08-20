<?php

namespace Tests\Unit\Support;

use App\Support\VacancyRate;
use PHPUnit\Framework\TestCase;

/**
 * 空室率 = (空き + 不明) × 100 ÷ (営業 + 空き + 不明)、1/10 % 単位の切り捨て。
 *
 * ⚠ float 除算に戻して赤になる値は、この規模では存在しない（プラン §1-2）。
 *    値テストが守るのは「不明を空きに含めること」「切り捨てであること」「総数 0 で null」の 3 点。
 *    intdiv 経路そのものは test_implementation_uses_integer_division が守る。
 */
class VacancyRateTest extends TestCase
{
    /** 切り捨て。⚠ round に戻すと 66.7 / 28.6 になって赤になる値を明示的に置いている */
    public function test_truncates_to_one_tenth_percent(): void
    {
        // 2 ÷ 3 = 66.666…% → 66.6（round なら 66.7）
        $this->assertSame(66.6, VacancyRate::percent(1, 2, 0));

        // 2 ÷ 7 = 28.571…% → 28.5（round なら 28.6）
        $this->assertSame(28.5, VacancyRate::percent(5, 2, 0));
    }

    /** 「不明」は空きとして数える。⚠ 不明を除外 or 営業に寄せると 0.0 になって赤になる */
    public function test_unknown_counts_as_vacant(): void
    {
        $this->assertSame(20.0, VacancyRate::percent(8, 0, 2));
        $this->assertSame(50.0, VacancyRate::percent(5, 3, 2));
    }

    public function test_returns_null_when_there_are_no_units(): void
    {
        $this->assertNull(VacancyRate::percent(0, 0, 0));
    }

    public function test_boundaries(): void
    {
        $this->assertSame(0.0, VacancyRate::percent(3, 0, 0));
        $this->assertSame(100.0, VacancyRate::percent(0, 1, 0));
        $this->assertSame(100.0, VacancyRate::percent(0, 0, 2));
    }

    public function test_label_formats_one_decimal_and_dashes_when_unsurveyed(): void
    {
        $this->assertSame('66.6%', VacancyRate::label(1, 2, 0));
        $this->assertSame('0.0%', VacancyRate::label(3, 0, 0));
        $this->assertSame('100.0%', VacancyRate::label(0, 1, 0));
        $this->assertSame('—', VacancyRate::label(0, 0, 0));
    }

    /**
     * 入居率 = 100 − 空室率。
     *
     * ⚠ **「営業 ÷ 総数」で独立に切り捨てないこと。** 2 つを並べて出す以上、
     *   和が 100.0% にならない行が出てはいけない（営業 1 / 空き 2 なら
     *   空室率 66.6% ＋ 入居率 33.3% ＝ 99.9%。Bug #46「並べた数字が無音で食い違う」）。
     *   ここは切り捨てた空室率の裏返しなので、入居率側は 1/10% 単位で**切り上げ**になる。
     *
     * ⚠ **float の引き算にしないこと。** `100.0 - percent(...)` は
     *   営業 1 / 空き 2 で 33.400000000000006 を返す（実測）。Bug #33 / #34。
     */
    public function test_occupancy_is_the_complement_of_the_vacancy_rate(): void
    {
        // 2 ÷ 3 = 66.666…% の裏返し。⚠ float 引き算だと 33.400000000000006 になって赤
        $this->assertSame(33.4, VacancyRate::occupancyPercent(1, 2, 0));
        // 2 ÷ 7 = 28.571…% の裏返し
        $this->assertSame(71.5, VacancyRate::occupancyPercent(5, 2, 0));
        // 3 ÷ 7 = 42.857…% の裏返し（地図の丸に出る 57 の元）
        $this->assertSame(57.2, VacancyRate::occupancyPercent(4, 3, 0));
    }

    /** 「不明」は入居に数えない（空室率が空きに数えているのと対） */
    public function test_unknown_does_not_count_as_occupied(): void
    {
        $this->assertSame(80.0, VacancyRate::occupancyPercent(8, 0, 2));
        $this->assertSame(50.0, VacancyRate::occupancyPercent(5, 3, 2));
    }

    public function test_occupancy_boundaries(): void
    {
        $this->assertNull(VacancyRate::occupancyPercent(0, 0, 0));
        $this->assertSame(100.0, VacancyRate::occupancyPercent(3, 0, 0));
        $this->assertSame(0.0, VacancyRate::occupancyPercent(0, 1, 0));
        $this->assertSame(0.0, VacancyRate::occupancyPercent(0, 0, 2));
    }

    public function test_occupancy_label_formats_one_decimal_and_dashes_when_unsurveyed(): void
    {
        $this->assertSame('33.4%', VacancyRate::occupancyLabel(1, 2, 0));
        $this->assertSame('100.0%', VacancyRate::occupancyLabel(3, 0, 0));
        $this->assertSame('0.0%', VacancyRate::occupancyLabel(0, 1, 0));
        $this->assertSame('—', VacancyRate::occupancyLabel(0, 0, 0));
    }

    /**
     * 地図の丸に載る短いラベルは **100 − 空室率の整数**（実質は切り上げ）。
     *
     * ⚠ 1/10% 表示との食い違いは仕様。空室率 42.857…% は
     *   表に「42.8%」/ 丸の裏返しに「58%」と出るが、**丸は空室率の整数 42 と足して 100**。
     *   独立に切り捨てた 57% だと 42 + 57 = 99 になり、帯（色）からもはみ出す。
     */
    public function test_occupancy_compact_label_is_the_complement_of_the_vacancy_integer(): void
    {
        $cases = [
            ['区画 0 は —',                     0,   0,   0, '—'],
            ['満室は 100%',                     3,   0,   0, '100%'],
            ['全空きは 0%',                     0,   5,   0, '0%'],
            ['空室率 42% の裏返しは 58%',       4,   3,   0, '58%'],
            ['空室率 66% の裏返しは 34%',       1,   2,   0, '34%'],
            ['ちょうど 75.0% は 75%',           3,   1,   0, '75%'],
            ['ちょうど 50.0% は 50%',           1,   1,   0, '50%'],
            ['不明は入居に数えない',            1,   0,   1, '50%'],
        ];

        foreach ($cases as [$label, $operating, $vacant, $unknown, $expected]) {
            $this->assertSame(
                $expected,
                VacancyRate::occupancyCompactLabel($operating, $vacant, $unknown),
                $label . '（営業 ' . $operating . ' / 空き ' . $vacant . ' / 不明 ' . $unknown . '）'
            );
        }
    }

    /**
     * **丸の整数と、表に出る空室率の整数の和も必ず 100 になる。**
     *
     * ⚠ 1 桁側の掃引（test_the_two_rates_on_screen_always_add_up_to_100_percent）と対。
     *   丸だけ独立に切り捨てると 42 + 57 = 99 になり、ここが赤になる。
     * ⚠ **`%` を外して整数として足すこと。** float に戻すと 1 桁側で踏んだ丸めの罠に落ちる。
     */
    public function test_the_two_whole_percent_labels_also_add_up_to_100(): void
    {
        $this->assertLabelFormats();

        // ① 名指しの 1 件（掃引が空振りしてもここだけは 99 を捕まえる）
        $this->assertSame(100, $this->compactTotal(4, 3, 0), '空室率 42% ＋ 入居率 58% になっていない');

        // ② 総当たり（1〜60 区画のあらゆる 営業/空き/不明 の内訳）
        $offenders = [];
        $checked   = 0;

        for ($total = 1; $total <= 60; $total++) {
            for ($vacant = 0; $vacant <= $total; $vacant++) {
                for ($unknown = 0; $unknown <= $total - $vacant; $unknown++) {
                    $operating = $total - $vacant - $unknown;
                    $checked++;
                    $sum = $this->compactTotal($operating, $vacant, $unknown);

                    if ($sum !== 100) {
                        $offenders[] = sprintf(
                            '営業 %d / 空き %d / 不明 %d → 空室率 %s ＋ 入居率 %s = %d%%',
                            $operating, $vacant, $unknown,
                            VacancyRate::compactLabel($operating, $vacant, $unknown),
                            VacancyRate::occupancyCompactLabel($operating, $vacant, $unknown),
                            $sum
                        );
                    }
                }
            }
        }

        $this->assertSame(39710, $checked, '掃引が空振りしている（内訳を 1 つも見ていない）');
        $this->assertSame([], $offenders,
            "整数どうしの和が 100% になっていません:\n" . implode("\n", array_slice($offenders, 0, 10)));
    }

    /** 空室率の整数 ＋ 入居率の整数。⚠ 書式は assertLabelFormats() が掃引の外で見る */
    private function compactTotal(int $operating, int $vacant, int $unknown): int
    {
        return (int) rtrim(VacancyRate::compactLabel($operating, $vacant, $unknown), '%')
            + (int) rtrim(VacancyRate::occupancyCompactLabel($operating, $vacant, $unknown), '%');
    }

    /**
     * **画面に並ぶ 2 つの数字の和が必ず 100.0% になる。** 本件の一番大事な不変条件。
     *
     * ⚠ **画面に出る文字列**を 1/10% 単位の整数へ戻し、**整数のまま足す**。
     *   理由は二進誤差ではない（2026-08-20 実測: `66.6 + 33.4 === 100.0` は **true**、
     *   `%.20f` で `100.00000000000000000000`）。**PHP の `/` は割り切れると int を返す**ので、
     *   `intdiv(200000, 1000) / 10` は `int(20)`。`int(20) + int(80) === 100.0` は
     *   **値ではなく型**で false になる。生の式で掃引すると 20,300 通り中 2,840 通りが
     *   これに当たった（`percent()` は戻り値型 `?float` があるので実際には矯正され、
     *   型付き API 経由なら 0 件）。整数で足せば型にも丸めにも左右されない。
     *   ⚠ この注記自体が次の人を誤らせないよう、断定は必ず実測してから書くこと（Bug #42 ②）。
     *
     * ⚠ 「営業 ÷ 総数」で独立に切り捨てる実装へ変異させたら赤になること。そのため
     *   **営業 1 / 空き 2（99.9% になる）を必ず掃引に含める** ＝ 総数 3 を含める。
     */
    public function test_the_two_rates_on_screen_always_add_up_to_100_percent(): void
    {
        $this->assertLabelFormats();

        // ① 名指しの 1 件。掃引が空振りしても、この行だけは必ず 99.9% を捕まえる
        $this->assertSame(1000, $this->labelTenths(VacancyRate::label(1, 2, 0))
            + $this->labelTenths(VacancyRate::occupancyLabel(1, 2, 0)),
            '営業 1 / 空き 2 で 空室率 66.6% ＋ 入居率 33.3% ＝ 99.9% になっている');

        // ② 総当たり（1〜60 区画のあらゆる 営業/空き/不明 の内訳）
        $offenders = [];
        $checked   = 0;

        for ($total = 1; $total <= 60; $total++) {
            for ($vacant = 0; $vacant <= $total; $vacant++) {
                for ($unknown = 0; $unknown <= $total - $vacant; $unknown++) {
                    $operating = $total - $vacant - $unknown;
                    $checked++;

                    $vacancyLabel   = VacancyRate::label($operating, $vacant, $unknown);
                    $occupancyLabel = VacancyRate::occupancyLabel($operating, $vacant, $unknown);
                    $sum = $this->labelTenths($vacancyLabel) + $this->labelTenths($occupancyLabel);

                    if ($sum !== 1000) {
                        $offenders[] = sprintf(
                            '営業 %d / 空き %d / 不明 %d → 空室率 %s ＋ 入居率 %s = %s%%',
                            $operating, $vacant, $unknown, $vacancyLabel, $occupancyLabel, $sum / 10
                        );
                    }
                }
            }
        }

        $this->assertSame(39710, $checked, '掃引が空振りしている（内訳を 1 つも見ていない）');
        $this->assertSame([], $offenders,
            "空室率と入居率の和が 100.0% になっていません:\n" . implode("\n", array_slice($offenders, 0, 10)));
    }

    /**
     * 丸に出る整数と帯（色）の関係。**ズレが出る内訳を隠さず件数で固定する**（Bug #43）。
     *
     * ⚠ 帯のキーは**空室率**の段階のままで、ラベルだけを入居率で言い換えている。
     *   丸の整数を「100 − 空室率の整数」にしたので、mid / high / none は**完全に一致**する。
     *   残るはみ出しは **low の帯に 100%** の 1 種類だけ ——
     *   空室率が 1% 未満だと整数は 0 になり、裏返すと 100 になるため（黄色い丸に「100%」）。
     *   これは compactLabel() 側に既にある「1% 未満」の穴と**同じ入力**（1 棟で 101 区画以上が要る）。
     *   ⚠ 以前の「入居率を独立に切り捨てる」実装ではこれが **290 件**あり、
     *     **総区画 29 以上**（最小例は 営業 22 / 空き 7 ＝ 空室率 24.1% で黄なのに丸は「75%」）
     *     ＝実データにある規模で起きていた。今は **100 件**で総区画 101 以上のみ。
     *   件数を固定してあるので、丸め方や帯を変えたらここが動く。
     */
    public function test_occupancy_compact_label_against_the_bands(): void
    {
        // 帯 → 凡例のラベルが謳う整数の範囲（入居率）
        $bands = [
            VacancyRate::LEVEL_NONE => [100, 100],
            VacancyRate::LEVEL_LOW  => [76, 99],
            VacancyRate::LEVEL_MID  => [51, 75],
            VacancyRate::LEVEL_HIGH => [0, 50],
        ];

        $offenders = [];
        $belowOne  = 0;
        $checked   = 0;

        for ($total = 1; $total <= 200; $total++) {
            for ($vacant = 0; $vacant <= $total; $vacant++) {
                $operating = $total - $vacant;
                $level     = VacancyRate::level($operating, $vacant, 0);
                $shown     = (int) rtrim(VacancyRate::occupancyCompactLabel($operating, $vacant, 0), '%');
                [$min, $max] = $bands[$level];
                $checked++;

                // 既知のはみ出し（1 種類だけ）: 空室率 1% 未満 → 整数 0 の裏返しで low に 100%
                if ($level === VacancyRate::LEVEL_LOW && $shown === 100) {
                    $belowOne++;

                    continue;
                }

                if ($shown < $min || $shown > $max) {
                    $offenders[] = sprintf(
                        '営業 %d / 空き %d → %d%%（%s の帯は %d〜%d%%）',
                        $operating, $vacant, $shown, $level, $min, $max
                    );
                }
            }
        }

        $this->assertSame(20300, $checked, '掃引が空振りしている（内訳を 1 つも見ていない）');
        $this->assertSame([], $offenders,
            "丸の数字が帯の言い方からはみ出しています:\n" . implode("\n", array_slice($offenders, 0, 10)));
        // 空き 1 区画 × 総数 101〜200 の 100 通り。compactLabel() 側の既知の穴と同じ入力
        $this->assertSame(100, $belowOne, '1% 未満の扱いが変わっている（丸めの向きが変わった可能性）');
    }

    /**
     * '66.6%' → 666。⚠ 整数へ戻してから足す（型で `!==` が落ちるのを避ける）。
     *
     * ⚠ 書式そのものは**掃引の外で 1 回だけ**検査する（assertLabelFormats）。
     *   ラベルは `number_format($x, 1) . '%'` の 1 本道で入力によって形が変わらないので、
     *   ループ内で毎回 assert しても検出力は増えず assertion 数だけが 8 万件増える。
     */
    private function labelTenths(string $label): int
    {
        return (int) str_replace('.', '', rtrim($label, '%'));
    }

    /**
     * 2 つの掃引が前提にしている**ラベルの書式**を、掃引の外で 1 回だけ固定する。
     *
     * ⚠ ここが無いと `'42 %'` のような書式変更を `rtrim` + `(int)` が黙って吸収する
     *   （和は 100 のままなので掃引では捕まらない）。
     */
    private function assertLabelFormats(): void
    {
        foreach ([[1, 2, 0], [3, 0, 0], [0, 5, 0], [4, 3, 0]] as [$o, $v, $u]) {
            $this->assertMatchesRegularExpression('/^\d+\.\d%$/', VacancyRate::label($o, $v, $u));
            $this->assertMatchesRegularExpression('/^\d+\.\d%$/', VacancyRate::occupancyLabel($o, $v, $u));
            $this->assertMatchesRegularExpression('/^\d+%$/', VacancyRate::compactLabel($o, $v, $u));
            $this->assertMatchesRegularExpression('/^\d+%$/', VacancyRate::occupancyCompactLabel($o, $v, $u));
        }
    }

    /**
     * 経路を構造で固定する。
     *
     * ⚠ コメントを落としてから検索すること。この判定を消すと、クラスの docblock に
     *    書いた「round は使わない」の 'round' に一致して、実装を round に戻しても
     *    緑のまま通る(Bug #42 ②と同型)。
     */
    public function test_implementation_uses_integer_division(): void
    {
        $src = $this->sourceWithoutComments(__DIR__ . '/../../../app/Support/VacancyRate.php');

        $this->assertStringContainsString('intdiv(', $src, 'intdiv による整数演算になっていない');
        $this->assertStringNotContainsString('round(', $src, '丸めに round を使っている');
        $this->assertDoesNotMatchRegularExpression('/\bfloor\s*\(/', $src, '丸めに floor を使っている');
    }

    /** PHP トークナイザでコメント / docblock を落としたソースを返す */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * 帯の境界。
     *
     * ⚠ 閾値は 2026-08-19 の実データ 187 棟で決めた（設計書 §3）。
     *   0 / 25 / 50 で 24:18:26:31 とほぼ四等分になる。20 / 40 に戻すとここが赤になる。
     *
     * ⚠ 境界は**両側から挟む**。「ちょうど 25.0%」だけを見ると `>` へ変異させても
     *   気づけない（AreaBuildingListTest が 20/40 時代に同じ穴を踏んでいる）。
     */
    public function test_level_bands(): void
    {
        $cases = [
            ['区画 0 は未調査',        0,   0,   0, VacancyRate::LEVEL_UNKNOWN],
            ['満室は none',            10,  0,   0, VacancyRate::LEVEL_NONE],
            ['1.0% は low',            99,  1,   0, VacancyRate::LEVEL_LOW],
            ['24.9% は low',           301, 100, 0, VacancyRate::LEVEL_LOW],
            ['ちょうど 25.0% は mid',  3,   1,   0, VacancyRate::LEVEL_MID],
            ['49.9% は mid',           501, 500, 0, VacancyRate::LEVEL_MID],
            ['ちょうど 50.0% は high', 1,   1,   0, VacancyRate::LEVEL_HIGH],
            ['100% は high',           0,   5,   0, VacancyRate::LEVEL_HIGH],
            ['不明も空きとして数える', 1,   0,   1, VacancyRate::LEVEL_HIGH],
        ];

        foreach ($cases as [$label, $operating, $vacant, $unknown, $expected]) {
            $this->assertSame(
                $expected,
                VacancyRate::level($operating, $vacant, $unknown),
                $label . '（営業 ' . $operating . ' / 空き ' . $vacant . ' / 不明 ' . $unknown . '）'
            );
        }
    }

    /**
     * ピンに載せる短いラベル。**切り捨ての整数**（42.8% → '42%'）。
     *
     * ⚠ 吹き出しは従来どおり 1/10% 刻み（`label()`）。短いのは地図の丸の中だけ。
     */
    public function test_compact_label_truncates_to_a_whole_percent(): void
    {
        $cases = [
            ['区画 0 は —',            0,   0,   0, '—'],
            ['満室は 0%',              3,   0,   0, '0%'],
            ['24.9% は 24%',           301, 100, 0, '24%'],
            ['ちょうど 25.0% は 25%',  3,   1,   0, '25%'],
            ['42.8% は 42%',           4,   3,   0, '42%'],
            ['49.9% は 49%',           501, 500, 0, '49%'],
            ['ちょうど 50.0% は 50%',  1,   1,   0, '50%'],
            ['100% は 100%',           0,   5,   0, '100%'],
            ['不明も空きとして数える', 1,   0,   1, '50%'],
        ];

        foreach ($cases as [$label, $operating, $vacant, $unknown, $expected]) {
            $this->assertSame(
                $expected,
                VacancyRate::compactLabel($operating, $vacant, $unknown),
                $label . '（営業 ' . $operating . ' / 空き ' . $vacant . ' / 不明 ' . $unknown . '）'
            );
        }
    }

    /**
     * **数字と色が矛盾しない。** 丸に出る整数が `level()` の帯からはみ出さないこと。
     *
     * ⚠ ここが切り捨てを選んだ理由そのもの。四捨五入だと 49.6% が「50%」と出るのに
     *   色は橙（25〜49% の帯）のままで、**橙の丸に 50% と書いてある**状態になる。
     *   24.6% も同型（「25%」と出るのに黄）。切り捨てなら帯の境界（25.0 / 50.0）を
     *   またぐ表示が原理的に起きない。
     *
     * ⚠ 狙い撃ちの 2 値だけでなく総当たりも通す。狙い撃ちは「その値だけ辻褄を合わせる」
     *   変異に弱く、総当たりは「境界に当たる内訳が掃引に入っていない」と空振りするため。
     */
    public function test_compact_label_never_contradicts_the_band(): void
    {
        // 帯 → 出てよい整数の範囲
        $bands = [
            VacancyRate::LEVEL_NONE => [0, 0],
            VacancyRate::LEVEL_LOW  => [1, 24],
            VacancyRate::LEVEL_MID  => [25, 49],
            VacancyRate::LEVEL_HIGH => [50, 100],
        ];

        // ① 四捨五入に変異させたら赤になる 2 値（round: 49.6 → 50 / 24.6 → 25）
        $this->assertSame('49%', VacancyRate::compactLabel(504, 496, 0), '49.6% が切り捨てられていない');
        $this->assertSame(VacancyRate::LEVEL_MID, VacancyRate::level(504, 496, 0));
        $this->assertSame('24%', VacancyRate::compactLabel(754, 246, 0), '24.6% が切り捨てられていない');
        $this->assertSame(VacancyRate::LEVEL_LOW, VacancyRate::level(754, 246, 0));

        // ② 総当たり（1〜200 区画のあらゆる内訳）
        $offenders = [];
        $checked   = 0;
        $belowOne  = 0;

        for ($total = 1; $total <= 200; $total++) {
            for ($vacant = 0; $vacant <= $total; $vacant++) {
                $operating = $total - $vacant;
                $level     = VacancyRate::level($operating, $vacant, 0);
                $shown     = (int) rtrim(VacancyRate::compactLabel($operating, $vacant, 0), '%');
                [$min, $max] = $bands[$level];
                $checked++;

                // ⚠ **既知の例外を 1 つだけ名指しで除外する**（偽の安心より正直な穴。Bug #43）。
                //   率が 1% 未満のとき切り捨ては 0 になるが帯は low（黄）＝
                //   **黄の丸に「0%」と出る**。満室の緑も「0%」なので、数字だけ見ると紛らわしい。
                //   純粋な切り捨てを保つことを優先して直していない —— 1% 未満になるには
                //   1 棟で 101 区画以上が要り（1 ÷ 101 = 0.99%）、周辺ビル調査の実データには
                //   その規模の棟が無い。⚠ 件数まで固定するので、切り捨ての仕方を変えたら動く。
                if ($level === VacancyRate::LEVEL_LOW && $shown === 0) {
                    $belowOne++;

                    continue;
                }

                if ($shown < $min || $shown > $max) {
                    $offenders[] = sprintf(
                        '営業 %d / 空き %d → %s（%s の帯は %d〜%d%%）',
                        $operating, $vacant, $shown . '%', $level, $min, $max
                    );
                }
            }
        }

        $this->assertSame(20300, $checked, '掃引が空振りしている（内訳を 1 つも見ていない）');
        $this->assertSame([], $offenders, "丸の数字が色の帯と矛盾しています:\n" . implode("\n", array_slice($offenders, 0, 10)));

        // 空き 1 区画 × 総数 101〜200 の 100 通りだけが上の例外に当たる
        $this->assertSame(100, $belowOne, '1% 未満の扱いが変わっている（切り捨て以外になった可能性）');
    }

    /**
     * 帯のラベルは**入居率の言い方**。⚠ キー（none / low / mid / high）は空室率の段階のまま。
     *
     * ⚠ 閾値（BAND_MID / BAND_HIGH）も level() も空室率のままで、ここは言い換えだけ。
     *   キーとラベルが逆向きに見えるのはそのため（LEVEL_LOW ＝ 空室率が低い ＝ 入居率 76〜99%）。
     */
    public function test_levels_are_labelled_by_occupancy(): void
    {
        $this->assertSame([
            VacancyRate::LEVEL_NONE    => '満室（100%）',
            VacancyRate::LEVEL_LOW     => '76〜99%',
            VacancyRate::LEVEL_MID     => '51〜75%',
            VacancyRate::LEVEL_HIGH    => '50% 以下',
            VacancyRate::LEVEL_UNKNOWN => '調査なし',
        ], array_map(fn (array $level) => $level['label'], VacancyRate::LEVELS));
    }

    /** 凡例に使う 5 段が全部あり、色とラベルを持つこと（地図の凡例が欠けないように） */
    public function test_levels_table_covers_every_level(): void
    {
        $keys = [
            VacancyRate::LEVEL_NONE,
            VacancyRate::LEVEL_LOW,
            VacancyRate::LEVEL_MID,
            VacancyRate::LEVEL_HIGH,
            VacancyRate::LEVEL_UNKNOWN,
        ];

        $this->assertSame($keys, array_keys(VacancyRate::LEVELS), '凡例の並び順が変わっている');

        foreach (VacancyRate::LEVELS as $key => $level) {
            $this->assertArrayHasKey('label', $level, $key . ' にラベルが無い');
            $this->assertArrayHasKey('color', $level, $key . ' に色が無い');
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $level['color'], $key . ' の色が 16 進表記でない');
        }
    }
}
