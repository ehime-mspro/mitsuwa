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
 *
 * ⚠ 2026-08-19: `_form.blade.php` の段階フォールバックを名指しで見ていたケースを削除した。
 *   所在地の入力欄を画面から外したので、フォームの住所検索そのものが無くなったため。
 *   ⚠ **一括取得（index.blade.php）側へは移していない。** 設計書 §6.1 に
 *   「1 クリックで最大 5 回ジオコーディングを叩く。一括処理でこの関数を使い回さないこと」と
 *   あり、移すと一括取得の費用が最大 5 倍になる。
 *   `resources/views` 全体を走査する残りのケースはそのまま有効。
 */
class AreaBuildingAddressFallbackRegexTest extends TestCase
{
    /**
     * この文字クラスパターンを使う既知のフォームの総数（2026-08-19 実測）:
     * procurements / projects（不動産）/ projects（DAD）の 3 ファイル × 5 箇所 = 15。
     * 走査が空振りして「対象 0 件だから両方緑」という事故を防ぐための下限。
     *
     * ⚠ 2026-08-19 に 20 から 15 へ下げた。area-buildings の住所検索 JS を削除した
     *   （所在地の入力欄が画面から無くなり到達不能になったため）ので、
     *   4 ファイル × 5 = 20 → 3 ファイル × 5 = 15 になった。
     */
    private const MIN_TOTAL_OCCURRENCES = 15;

    private const CORRECT_CLASS   = '[\\d０-９]';
    private const DEGRADED_CLASS  = '[\\d0-9]';

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
     * （既知の 3 ファイル × 5 箇所 = 15 が resources/views/ 全体で実在することを確認）。
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
