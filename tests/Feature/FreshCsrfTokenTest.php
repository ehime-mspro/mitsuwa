<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 419 の画面から「前の画面に戻る」で戻った画面の送信の鍵を、新しい鍵に差し替える部品
 * （resources/views/_partials/_fresh_csrf_token.blade.php。設計メモ 2026-11-18-419-back-fresh-token-design.md）。
 *
 * 直す前は、戻った画面が古い鍵のままで、そのまま送るとまた 419 になり、開き直すまで抜けられなかった（Bug #137 の後回し）。
 *
 * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、部品が描いた script をそのまま node の vm で読み込み、
 *   window（sessionStorage・performance）と document（メタ・hidden の _token）を偽物で渡して動かす（SubmitOnceTest と同じ流儀）。
 * ⚠ 部品は head で走るので、走る時点では本文（hidden の _token）はまだ無い。偽の document も parse() を呼ぶまで _token を返さない
 *   （最初から返すと、DOMContentLoaded での差し替えを消しても気づけない）。
 */
class FreshCsrfTokenTest extends TestCase
{
    private const INCLUDE = "@include('_partials._fresh_csrf_token')";

    private const TOKEN_META = '<meta name="csrf-token"';

    /** 送信の鍵を持たない HTML の画面（ビュー => 理由）。部品を読まなくてよい */
    private const DOCUMENTS_WITHOUT_TOKEN = [
        'approvals/login-guide.blade.php' => 'ログイン案内の印刷用の画面。フォームも送信の鍵も持たない',
    ];

    /** 走査が必ず拾うはずのレイアウト（拾えなければ走査が壊れている） */
    private const KNOWN_LAYOUTS = ['layouts/app.blade.php', 'components/guest-layout.blade.php'];

    /** HTML の画面の数の下限（2026-11-18 実測 3: 2 つのレイアウトとログイン案内） */
    private const MIN_DOCUMENTS = 3;

    /**
     * 部品の script を node の vm で読み込み、$steps（JavaScript）を走らせて、result に入れた値を返す。
     *
     * @param  string  $steps  setMode('ok'|'throws'|'missing')・store.set(KEY, …)・nav.type = …・run()・parse()・
     *                         fire('window'|'document', type, event)・remember() を並べ、最後に result = state()
     * @return array{meta: string, inputs: list<string>, stored: ?string, error: ?string}
     */
    private function runInNode(string $steps): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので鍵の差し替えの JavaScript の実駆動を飛ばす');
        }

        $found = preg_match('/<script>(.*?)<\/script>/s', Blade::render(self::INCLUDE), $m);
        $this->assertSame(1, $found, '部品の script が描かれていない');

        $prelude = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const KEY = 'freshCsrfTokenAfter419';
            const nav = { type: 'navigate' };
            const store = new Map();
            let mode = 'ok';
            const sessionStorage = {
                getItem(k) { if (mode === 'throws') throw new Error('denied'); return store.has(k) ? store.get(k) : null; },
                setItem(k, v) { if (mode === 'throws') throw new Error('denied'); store.set(k, String(v)); },
                removeItem(k) { if (mode === 'throws') throw new Error('denied'); store.delete(k); },
            };
            const meta = {
                content: 'old',
                getAttribute(name) { return name === 'content' ? this.content : null; },
                setAttribute(name, value) { if (name === 'content') this.content = String(value); },
            };
            let inputs = [];
            const listeners = { window: {}, document: {} };
            const on = (target) => (type, fn) => { (listeners[target][type] = listeners[target][type] || []).push(fn); };
            const document = {
                querySelector(selector) { return selector === 'meta[name="csrf-token"]' ? meta : null; },
                querySelectorAll(selector) { return selector === 'input[name="_token"]' ? inputs.slice() : []; },
                addEventListener: on('document'),
            };
            const win = {
                sessionStorage,
                performance: { getEntriesByType(kind) { return kind === 'navigation' ? [{ type: nav.type }] : []; } },
                addEventListener: on('window'),
            };
            const context = vm.createContext({ window: win, document });
            const code = fs.readFileSync(process.argv[1], 'utf8');
            let error = null;
            const setMode = (m) => { mode = m; if (m === 'missing') { delete win.sessionStorage; } };
            const run = () => { try { vm.runInContext(code, context, { filename: '_partials/_fresh_csrf_token.blade.php' }); } catch (e) { error = String(e); } };
            const parse = () => { inputs = [{ value: 'old' }, { value: 'old' }]; };
            const fire = (target, type, event) => {
                for (const fn of (listeners[target][type] || [])) {
                    try { fn(event || {}); } catch (e) { error = String(e); }
                }
            };
            const remember = () => { try { win.rememberFreshCsrfToken(); } catch (e) { error = String(e); } };
            const state = () => ({ meta: meta.content, inputs: inputs.map((i) => i.value), stored: store.has(KEY) ? store.get(KEY) : null, error });
            let result;
            JS;
        $harness = $prelude . "\n" . $steps . "\nprocess.stdout.write(JSON.stringify(result));\n";

        $file = tempnam(sys_get_temp_dir(), 'fresh-csrf-js-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で部品を動かせなかった:\n" . $output);

        return $result;
    }

    /**
     * 419 の画面のあと「前の画面に戻る」で表示した（HTTP のキャッシュから。ナビゲーションの種類が back_forward）。
     * メタは head で走った時点で、hidden の _token は本文を読み終えた時点で差し替え、ブラウザがフォームの値を戻したあと
     * （pageshow）にも差し替え直す。残した鍵は 1 回で消す。
     */
    public function test_a_back_navigation_swaps_in_the_token_left_by_the_419_page(): void
    {
        $result = $this->runInNode(<<<'JS'
            store.set(KEY, 'fresh');
            nav.type = 'back_forward';
            run();
            const metaAtHead = meta.content;
            parse();
            fire('document', 'DOMContentLoaded');
            const inputsAfterParse = inputs.map((i) => i.value);
            inputs.forEach((i) => { i.value = 'old'; });
            fire('window', 'pageshow', { persisted: false });
            result = Object.assign(state(), { metaAtHead, inputsAfterParse });
            JS);

        $this->assertSame('fresh', $result['metaAtHead'], 'head で走った時点でメタが差し替わっていない（ページの script が古い鍵を読む）');
        $this->assertSame(['fresh', 'fresh'], $result['inputsAfterParse'], '本文を読み終えた時点で hidden の _token が差し替わっていない');
        $this->assertSame(['fresh', 'fresh'], $result['inputs'], 'ブラウザがフォームの値を戻したあと（pageshow）に差し替え直していない');
        $this->assertSame('fresh', $result['meta']);
        $this->assertNull($result['stored'], '残した鍵を消していない');
        $this->assertNull($result['error']);
    }

    /** @return array<string, array{0: string}> */
    public static function normalLoads(): array
    {
        return [
            'ふつうに開いた' => ['navigate'],
            '読み込み直した' => ['reload'],
        ];
    }

    /**
     * 419 の画面のあと、戻らずにほかの画面へ進んだ（読み込み直した）。残した鍵は当てずに消す
     * （あとで「戻る」で別の画面を出したときに、古くなった鍵を当てないため）。
     */
    #[DataProvider('normalLoads')]
    public function test_a_normal_load_throws_the_token_away(string $type): void
    {
        $result = $this->runInNode("store.set(KEY, 'fresh');\nnav.type = " . json_encode($type) . ";\n" . <<<'JS'
            run();
            parse();
            fire('document', 'DOMContentLoaded');
            fire('window', 'pageshow', { persisted: false });
            result = state();
            JS);

        $this->assertSame('old', $result['meta'], 'ふつうの読み込みでメタを差し替えた');
        $this->assertSame(['old', 'old'], $result['inputs'], 'ふつうの読み込みで hidden の _token を差し替えた');
        $this->assertNull($result['stored'], 'ふつうの読み込みで残した鍵を消していない');
        $this->assertNull($result['error']);
    }

    /** bfcache から出した（ページの script は走り直さない。pageshow の persisted だけが届く） */
    public function test_a_page_shown_from_the_back_forward_cache_swaps_in_the_token(): void
    {
        $result = $this->runInNode(<<<'JS'
            run();
            parse();
            fire('document', 'DOMContentLoaded');
            fire('window', 'pageshow', { persisted: false });
            store.set(KEY, 'fresh');
            fire('window', 'pageshow', { persisted: true });
            result = state();
            JS);

        $this->assertSame('fresh', $result['meta'], 'bfcache から出したときにメタを差し替えていない');
        $this->assertSame(['fresh', 'fresh'], $result['inputs'], 'bfcache から出したときに hidden の _token を差し替えていない');
        $this->assertNull($result['stored'], 'bfcache から出したときに残した鍵を消していない');
        $this->assertNull($result['error']);
    }

    public function test_a_page_shown_from_the_back_forward_cache_without_a_token_is_left_alone(): void
    {
        $result = $this->runInNode(<<<'JS'
            run();
            parse();
            fire('document', 'DOMContentLoaded');
            fire('window', 'pageshow', { persisted: false });
            fire('window', 'pageshow', { persisted: true });
            result = state();
            JS);

        $this->assertSame('old', $result['meta']);
        $this->assertSame(['old', 'old'], $result['inputs']);
        $this->assertNull($result['error']);
    }

    /** @return array<string, array{0: string}> */
    public static function unusableStorage(): array
    {
        return [
            '鍵が無い'     => ['ok'],
            '例外を投げる' => ['throws'],
            '無い'         => ['missing'],
        ];
    }

    /** sessionStorage が使えない（プライベートの窓・サイトのデータを止めた）か、鍵が無い。何もせず、画面の JS を止めない */
    #[DataProvider('unusableStorage')]
    public function test_nothing_happens_without_a_token_or_storage(string $mode): void
    {
        $result = $this->runInNode('setMode(' . json_encode($mode) . ");\n" . <<<'JS'
            nav.type = 'back_forward';
            run();
            parse();
            fire('document', 'DOMContentLoaded');
            fire('window', 'pageshow', { persisted: true });
            remember();
            result = state();
            JS);

        $this->assertNull($result['error'], '例外で止まった');
        $this->assertSame('old', $result['meta']);
        $this->assertSame(['old', 'old'], $result['inputs']);
    }

    /** 419 の画面が呼ぶ関数（自分のメタの鍵を残す） */
    public function test_the_419_page_leaves_its_own_token(): void
    {
        $result = $this->runInNode(<<<'JS'
            run();
            meta.content = 'fresh';
            remember();
            result = state();
            JS);

        $this->assertSame('fresh', $result['stored'], '419 の画面の鍵が freshCsrfTokenAfter419 に残っていない');
        $this->assertNull($result['error']);
    }

    /**
     * HTML の画面（`<html` を持つビュー）と、鍵のメタを宣言するビューは、どれも
     * 鍵のメタの直後・@vite（ページの script）より前で部品をちょうど 1 回読み込む。
     * 送信の鍵を持たない画面だけは、理由を書いて DOCUMENTS_WITHOUT_TOKEN に載せる。
     *
     * ⚠ 後ろに置くと、画面を開いた時にメタを読む script が古い鍵を読む
     * ⚠ 2 つのレイアウトを手書きの配列で見ていた頃は、どこからも使われていない components/app-layout
     *   （鍵のメタを持つのに部品を読まない）が検査の外にあった（2026-11-18 に消した）。
     *   全件を走査するので、新しいレイアウトを足すと自動で検査に入る（Top trap #13）
     */
    public function test_every_html_document_reads_the_partial_right_after_the_token_meta(): void
    {
        $documents = [];
        $problems = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $view = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            // コメントに書いた説明を本物と数えない
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            $isDocument = stripos($source, '<html') !== false;
            if ($isDocument) {
                $documents[] = $view;
            }
            if (! $isDocument && ! str_contains($source, self::TOKEN_META)) {
                continue;
            }

            if (array_key_exists($view, self::DOCUMENTS_WITHOUT_TOKEN)) {
                foreach (['@csrf', 'csrf_token(', 'csrf-token', '<form'] as $needle) {
                    if (stripos($source, $needle) !== false) {
                        $problems[] = "{$view} は送信の鍵を持たない画面として分類しているのに {$needle} がある";
                    }
                }

                continue;
            }

            $problems = [...$problems, ...$this->partialPlacementProblems($view, $source)];
        }

        foreach (array_keys(self::DOCUMENTS_WITHOUT_TOKEN) as $view) {
            if (! in_array($view, $documents, true)) {
                $problems[] = "分類表の {$view} が実在しないか、HTML の画面でない";
            }
        }

        // 走査の空振りを先に止める（後ろに置くと、見つからない理由が別の文で報告される）
        $this->assertGreaterThanOrEqual(self::MIN_DOCUMENTS, count($documents), 'HTML の画面を拾えていない（走査が機能していない）: ' . implode(', ', $documents));
        foreach (self::KNOWN_LAYOUTS as $layout) {
            $this->assertContains($layout, $documents, "{$layout} を HTML の画面として拾えていない（走査が機能していない）");
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * 鍵のメタがちょうど 1 つ・部品がちょうど 1 回・並びが メタ → 部品 → @vite でなければ、理由を返す。
     *
     * @return list<string>
     */
    private function partialPlacementProblems(string $view, string $source): array
    {
        $problems = [];
        if (substr_count($source, self::TOKEN_META) !== 1) {
            $problems[] = "{$view} に鍵のメタがちょうど 1 つない";
        }
        if (substr_count($source, self::INCLUDE) !== 1) {
            $problems[] = "{$view} が部品をちょうど 1 回読み込んでいない";
        }
        if (strpos($source, '@vite(') === false) {
            $problems[] = "{$view} に @vite が無い";
        }
        if ($problems !== []) {
            return $problems;
        }

        $meta = strpos($source, self::TOKEN_META);
        $include = strpos($source, self::INCLUDE);
        $vite = strpos($source, '@vite(');
        if (! ($meta < $include && $include < $vite)) {
            $problems[] = "{$view} の部品が鍵のメタの直後・@vite より前にない";
        }

        return $problems;
    }
}
