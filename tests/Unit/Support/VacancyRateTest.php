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
