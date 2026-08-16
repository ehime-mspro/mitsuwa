<?php

namespace Tests\Feature\Tenant;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 住所の段階的フォールバック（フル→番地除去→丁目除去→市区町村→都道府県。設計 §6.1）の
 * 正規表現が全角数字（０-９）を含むことを固定する。
 *
 * ⚠ **PHP のテストだけでは原理的に守れない。** これは JS の正規表現であり、住所欄
 *   （`type="text"`）は `inputmode="numeric"` も `type="number"` も持たないため、
 *   layouts/app.blade.php のグローバル全角→半角変換リスナー（対象は
 *   `input[inputmode="numeric"]` / `input[type="number"]` のみ）の対象外。
 *   IME で自然に入る全角数字がそのまま JS の正規表現へ渡る。
 *
 *   `[\d０-９]`（半角 `\d` ＋ **全角数字** U+FF10-FF19）を `[\d0-9]` に縮退させると、
 *   `0-9` は `\d` の**部分集合**でしかないため全角数字には一切マッチしなくなり、
 *   5 段階フォールバックのうち番地除去・丁目除去の中間 2 段が**無音でスキップ**され、
 *   実質 3 段に劣化する（500 やデータ破壊は起きないため、他の走査テスト・変異テストには
 *   一切引っかからない。docs/RULES.md Bug #28 / #35 と同じ「HTML/挙動には出るが精度だけ
 *   落ちる」型に近いが、本件はさらに症状そのものが無い）。
 *
 * 2026-08-17 コード品質レビューで発見: `realestate/procurements/_form.blade.php` の
 * 地図パーツを `tenant/area-buildings/_form.blade.php` へ移植する際、識別子リネームの
 * ついでに `[\d０-９]` を `[\d0-9]` へ転記ミスした。Bug #45 ③「正規表現の文字クラスが
 * 狭い」と同型 ── **移植するときは正規表現の文字クラスを 1 文字ずつ照合すること。**
 */
class AreaBuildingAddressFallbackRegexTest extends TestCase
{
    /**
     * この文字クラスパターンを使う既知のフォームの総数（2026-08-17 実測）:
     * procurements / projects（不動産）/ projects（DAD）/ area-buildings の 4 ファイル ×
     * 5 箇所 = 20。走査が空振りして「対象 0 件だから両方緑」という事故を防ぐための下限。
     */
    private const MIN_TOTAL_OCCURRENCES = 20;

    /** area-buildings 単体の下限（2026-08-17 実測 5 箇所）。 */
    private const MIN_AREA_BUILDING_OCCURRENCES = 5;

    private const CORRECT_CLASS   = '[\\d０-９]';
    private const DEGRADED_CLASS  = '[\\d0-9]';

    /** area-buildings の _form.blade.php が正しい文字クラスを実測どおりの数だけ持つこと。 */
    public function test_area_building_form_fallback_regex_includes_fullwidth_digits(): void
    {
        $content = File::get(resource_path('views/tenant/area-buildings/_form.blade.php'));

        $count = substr_count($content, self::CORRECT_CLASS);

        $this->assertGreaterThanOrEqual(
            self::MIN_AREA_BUILDING_OCCURRENCES,
            $count,
            '_form.blade.php の段階フォールバック正規表現に全角数字 [\\d０-９] が '
                . self::MIN_AREA_BUILDING_OCCURRENCES . ' 箇所見つからない（劣化、または走査の空振り）'
        );
    }

    /**
     * ⚠ 対象を「直したファイル」に限定しない（Bug #45 ①「列挙リスト方式は列挙漏れで
     *   無音になる」と同型の穴を作らないため）。resources/views/ 全体を機械的に走査し、
     *   劣化形 [\d0-9] がどこにも無いことを固定する。将来 procurements / projects /
     *   dad/projects 側にこの縮退が入っても、あるいは同じパーツが別画面へ再度移植されて
     *   同じ転記ミスが起きても検出できる。
     */
    public function test_no_view_uses_the_degraded_half_width_only_digit_class(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains($file->getContents(), self::DEGRADED_CLASS)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "劣化形 [\\d0-9] を含むファイルがあります（0-9 は \\d の部分集合で冗長なだけ、"
                . "全角数字には一切マッチしません）:\n" . implode("\n", $offenders)
        );
    }

    /**
     * 走査ロジック自体が壊れて「対象が見つからないから両方緑」にならないことを固定する
     * （既知の 4 ファイル × 5 箇所 = 20 が resources/views/ 全体で実在することを確認）。
     */
    public function test_scan_finds_the_known_fallback_regex_call_sites(): void
    {
        $total = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $total += substr_count($file->getContents(), self::CORRECT_CLASS);
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_TOTAL_OCCURRENCES,
            $total,
            '走査ロジックが壊れている可能性がある（全角数字クラスの総数が既知の下限を下回った）'
        );
    }
}
