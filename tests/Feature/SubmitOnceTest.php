<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 確定のボタンの二度押し止めの部品（resources/views/_partials/_submit_once.blade.php。
 * 設計書 2026-09-28-import-double-submit-design.md §4.5・§5.4）。
 *
 * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、部品が描いた script をそのまま node の vm で読み込み、
 *   window（location.reload と performance）だけを偽物で渡して動かす（CustomerImportTest::csvImportInNode() と同じ流儀。
 *   Bug #47 の「振る舞いの正本は実駆動」）。Alpine との結びつき（x-data・x-on・:disabled）は、
 *   各画面のテストが構造で見る（Tests\Concerns\ChecksDoubleSubmit::assertSubmitOnceForm()）。
 */
class SubmitOnceTest extends TestCase
{
    /** 部品を読み込んで、レイアウトと同じく scripts のスタックを最後に出したページ */
    private function renderedPage(string $includes = "@include('_partials._submit_once')"): string
    {
        return Blade::render($includes . "\n@stack('scripts')");
    }

    /**
     * 部品が描いた script を node の vm で読み込み、$steps（JavaScript）を走らせて、result に入れた値を返す。
     *
     * @param  string  $steps  make('submitOnce(…)') で作り、press(data) で送信を 1 回押し、返したい値を result に入れる。
     *                         偽の window は nav.type（ナビゲーションの種類）と reloads.count（reload() の回数）で操る
     */
    private function runInNode(string $steps): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので二度押し止めの JavaScript の実駆動を飛ばす');
        }

        $found = preg_match('/<script>\s*(function submitOnce\(options\) \{.*?)<\/script>/su', $this->renderedPage(), $m);
        $this->assertSame(1, $found, '部品の script（function submitOnce）が描かれていない');

        $prelude = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const nav = { type: 'navigate' };
            const reloads = { count: 0 };
            const context = vm.createContext({
                window: {
                    location: { reload() { reloads.count++; } },
                    performance: { getEntriesByType(kind) { return kind === 'navigation' ? [{ type: nav.type }] : []; } },
                },
            });
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context, { filename: '_partials/_submit_once.blade.php' });
            const make = (code) => vm.runInContext(code, context);
            const press = (data) => {
                const event = { cancelled: false, preventDefault() { this.cancelled = true; } };
                data.onSubmit(event);
                return event.cancelled;
            };
            let result;
            JS;
        $harness = $prelude . "\n" . $steps . "\nprocess.stdout.write(JSON.stringify(result));\n";

        $file = tempnam(sys_get_temp_dir(), 'submit-once-js-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で submitOnce() を動かせなかった:\n" . $output);

        return $result;
    }

    public function test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again(): void
    {
        // 確認画面（reloadOnReturn なし）は、「戻る」で戻っても読み込み直さない（押すとサーバの鍵が断る）
        $this->assertSame(
            [
                'before' => false, 'first' => false, 'afterFirst' => true, 'second' => true, 'third' => true,
                'afterBfcache' => false, 'again' => false, 'afterAgain' => true, 'afterBack' => false, 'reloads' => 0,
            ],
            $this->runInNode(<<<'JS'
                const data = make('submitOnce()');
                result = { before: data.submitting };
                result.first = press(data);
                result.afterFirst = data.submitting;
                result.second = press(data);
                result.third = press(data);
                nav.type = 'back_forward';
                data.onPageShow({ persisted: true });
                result.afterBfcache = data.submitting;
                result.again = press(data);
                result.afterAgain = data.submitting;
                data.onPageShow({ persisted: false });
                result.afterBack = data.submitting;
                result.reloads = reloads.count;
                JS)
        );
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: string, 3: int, 4: bool}>
     *   [pageshow の persisted, 送ったあとか, ナビゲーションの種類, 期待する reload() の回数, 期待する submitting]
     */
    public static function returnsToTheImportArea(): array
    {
        return [
            '送ったあと bfcache から戻った'               => [true,  true,  'navigate',     1, true],
            '送らずに別の画面へ移り bfcache から戻った'    => [true,  false, 'navigate',     0, false],
            'bfcache から戻った（元の表示も「戻る」だった）' => [true,  false, 'back_forward', 0, false],
            'bfcache を使わずに「戻る」で表示した'         => [false, false, 'back_forward', 1, false],
            '読み込み直したあと（繰り返さない）'           => [false, false, 'reload',       0, false],
            'ふつうに開いた'                               => [false, false, 'navigate',     0, false],
        ];
    }

    /**
     * 周辺ビル（reloadOnReturn: true）は、送ったあと bfcache から戻ったとき・bfcache を使わずに「戻る」で表示したときだけ
     * 読み込み直して新しい鍵にする（設計書 §4.5）。作業の途中で別の画面へ移って bfcache から戻ったときは、
     * 読み込んだファイルと列の対応づけを残す（鍵もまだ使える）。
     */
    #[DataProvider('returnsToTheImportArea')]
    public function test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state(
        bool $persisted,
        bool $submitted,
        string $navigationType,
        int $reloads,
        bool $submittingAfter
    ): void {
        $steps = sprintf(
            <<<'JS'
                nav.type = %s;
                const data = make('submitOnce({ reloadOnReturn: true })');
                if (%s) { press(data); }
                data.onPageShow({ persisted: %s });
                result = { reloads: reloads.count, submitting: data.submitting };
                JS,
            json_encode($navigationType),
            $submitted ? 'true' : 'false',
            $persisted ? 'true' : 'false'
        );

        $this->assertSame(['reloads' => $reloads, 'submitting' => $submittingAfter], $this->runInNode($steps));
    }

    public function test_the_script_is_defined_once_in_the_scripts_stack(): void
    {
        // 部品を読み込んだ場所には何も出さない（レイアウトの scripts のスタックへ積む）
        $this->assertStringNotContainsString('function submitOnce(', Blade::render("@include('_partials._submit_once')"));

        // 1 つのページで 2 回読み込んでも、定義は 1 回だけ
        $page = $this->renderedPage("@include('_partials._submit_once')\n@include('_partials._submit_once')");
        $this->assertSame(1, substr_count($page, 'function submitOnce('), '部品を 2 回読み込むと、関数が 2 回定義される');
        $this->assertSame(1, substr_count($page, 'function submitOnceNavigationType('));
    }
}
