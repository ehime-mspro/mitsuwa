<?php

namespace Tests\Feature\Tenant;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Google Maps ローダーの `callback=<関数名>` と、その関数の**定義**を対で固定する。
 *
 * ⚠ **片方だけ壊れても HTML としては妥当**なので、画面を開いても 500 にならず、
 *   200 を見るだけのテストは全部緑のまま通る（docs/RULES.md Bug #28 と同型）。
 *   実際の症状は「ボタンを押しても地図が一度も開かない」で、
 *   `tenant/area-buildings/_form.blade.php` なら `areaMapsReady` が永久に false のまま
 *   「Google Maps を読み込み中です」を出し続ける。
 *   2026-08-19 の変異テストで、`callback=onGoogleMapsReady` を存在しない関数名へ変えても
 *   887 テスト全部が緑だったことを実測している。
 *
 * ⚠ 対象を「直したファイル」に限定しない（Bug #45 ①「列挙リスト方式は列挙漏れで無音になる」）。
 *   `resources/views` 全体を機械的に走査し、地図を読み込むビューを**全件分類**する。
 *   新しい地図画面が増えたら自動で検査対象に入る。
 */
class GoogleMapsCallbackWiringTest extends TestCase
{
    /**
     * Maps API を読み込むビューの下限（2026-08-19 実測 10 本）。
     * 走査が空振りして「対象 0 件だから緑」という事故を防ぐため。
     */
    private const MIN_LOADER_VIEWS = 10;

    /**
     * うち `callback=` を持つものの下限（同 8 本。残り 2 本は
     * realestate の {procurements,projects}/index で、callback 無しで読み込んでいる）。
     * ⚠ これが無いと「全ビューから callback= が消える」変異で
     *   対の検査が空回りしたまま緑になる。
     */
    private const MIN_CALLBACK_LOADERS = 8;

    public function test_every_maps_callback_has_a_matching_function_in_the_same_view(): void
    {
        $loaderViews    = 0;
        $callbackLoaders = 0;
        $orphans        = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // ⚠ 呼び出し側も定義側も**コメントを落としてから**測る。
            //   注意書きに一致して false-pass する事故を防ぐ（Bug #42 ②）。
            $code = $this->withoutJsComments($file->getContents());

            if (! str_contains($code, 'maps.googleapis.com')) {
                continue;
            }
            $loaderViews++;

            // ⚠ 文字クラスを狭めない（Bug #45 ③）。JS の識別子は先頭 `_`/`$`、以降 `\w`/`$`。
            preg_match_all('/callback=([A-Za-z_$][\w$]*)/', $code, $matches);

            if ($matches[1] === []) {
                continue;   // callback 無しで読み込む画面。対にすべき相手がいないので対象外
            }
            $callbackLoaders++;

            foreach (array_unique($matches[1]) as $function) {
                // `\s*\(` まで見るので `onGoogleMapsReadyX` のような前方一致では通らない
                if (! preg_match('/function\s+' . preg_quote($function, '/') . '\s*\(/', $code)) {
                    $orphans[] = $file->getRelativePathname() . ' → callback=' . $function;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_LOADER_VIEWS,
            $loaderViews,
            '走査ロジックが壊れている可能性がある（Maps を読み込むビューの数が既知の下限を下回った）'
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_CALLBACK_LOADERS,
            $callbackLoaders,
            '走査ロジックが壊れている可能性がある（callback= を持つビューの数が既知の下限を下回った）'
        );

        $this->assertSame(
            [],
            $orphans,
            "callback= が指す関数が同じビューに定義されていません（本番では地図が一度も開きません）:\n"
                . implode("\n", $orphans)
        );
    }

    /** JS の `/* *&#47;` と行頭 `//` コメントを落とす。 */
    private function withoutJsComments(string $source): string
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        // ⚠ 行頭アンカーを外さないこと。URL の `https://` まで消える。
        return preg_replace('#^[ \t]*//.*$#m', '', $source);
    }
}
