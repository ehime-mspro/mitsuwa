<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 取込のコントローラが、断るときにリファラー（「元の画面」）や今の URL へ戻していないことを全件分類で守る
 * （docs/RULES.md Bug #64・Top trap #13）。
 *
 * 取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。確認画面に載ったフォームから
 * 送って `back()` や `url()->previous()` で戻すと、リファラー＝その URL へ GET で戻り 405 になる
 * （2026-09-24 実測: テナント・賃貸マンション・ZEAL 会員・工程表）。今の URL へ戻す形（`redirect()->refresh()`・
 * `redirect($request->url())`・`url()->current()`・`$request->path()` など）も、POST 専用の URL を GET で開くので
 * 同じ 405 になる。戻り先は取込の画面に固定する。
 *
 * ⚠ 対象は `ScansImportControllers` が列挙する `*ImportController.php`（新しい取込は自動で検査対象に入る）。
 *   入力チェックの包み方を見る `ImportControllerValidationRedirectScanTest` と同じ集合を見る。
 *   許すのは ALLOWED に載せたもの（件数と理由つき）だけ。ほかは 0 件でないと落ちる。件数が合わない・
 *   実在しないファイルが載っている、も落とす。
 * ⚠ コメントを落としてから数える。docblock に「`back()` を使わない」と書いてあるため（Bug #42 ②）。
 * ⚠ 分担: 入力チェックの既定の戻り先（`validate()` などを try で包み `redirectTo()` を渡しているか）と
 *   FormRequest は `ImportControllerValidationRedirectScanTest` が見る。こちらは戻り先の書き方（リファラー・今の URL）を、
 *   `redirectTo()` に渡すものも含めて数える（`throw $e->redirectTo(url()->current())` はこちらが拾う）。
 *
 * ⚠ 見えないもの:
 *   - 走査するのは `*ImportController.php` の本文だけ。トレイト・親クラス・サービスへ切り出した `back()`
 *     （たとえばテナントと賃貸マンションでほぼ同じ `loadCsv()` をトレイトへ移す）は見えない
 *   - `*ImportController.php` という名前でない取込（ほかのコントローラに内蔵された確認画面）
 *   - 戻り先の URL を変数に入れて渡す形（`$to = $request->headers->get('referer')` や `$request->url()` は
 *     字面で拾うが、別のメソッドやクラスで作った URL を受け取る形は見えない）
 *   - 今の URL を読む呼び出し元が `$request`・`request()`・`Request::` 以外の形（引数名が違う `$req->url()`・
 *     変数に入れた形・`$this->request->url()`。2026-09-25 時点で取込のコントローラの引数名はすべて `$request`）
 *   - 大文字小文字の違う書き方（`->Back(`・`url()->Current()`。PHP では同じ呼び出しだが、正規表現は区別する）
 *   - 別名やコンテナから取った形（`use …\URL as Link;` の `Link::current()`・`app(UrlGenerator::class)->current()`）
 *   - `REQUEST_URI` を読む形・今のルート名へ戻す形（`redirect()->route(Route::currentRouteName())`。POST 専用の
 *     ルートなら GET で 405）
 * ⚠ 過剰に拾うもの: 文字列リテラルの中の `back(`・`referer`（走査はトークンを見ない）・
 *   Carbon の `->previous(` のように別の意味の `previous()`・戻り先でない場面の `$request->path()` や
 *   `url()->current()`（ログやビューへ渡すなど）・`Foo\Request::url()` のような別のクラス。出てきたら ALLOWED に
 *   理由つきで載せる（検出器を緩めない）。
 */
class ImportControllerReturnPathScanTest extends TestCase
{
    use ScansImportControllers;

    /** @var array<string, array{0: int, 1: string}> 相対パス => [件数, 理由] */
    private const ALLOWED = [
        'app/Http/Controllers/Tenant/AreaBuildingImportController.php' => [
            2,
            '確認は画面の中（SheetJS）で、確定の送信元は GET の取込の画面。'
                . 'リファラーへ戻ると取込の画面が開く（2026-09-24 実測 200）',
        ],
    ];

    /** @return list<array{int, string}> [行, 呼び出し] */
    private function returnsToReferer(string $code): array
    {
        $found = [];
        $patterns = [
            // 1. back()（ヘルパー・\back()・redirect()->back()・Redirect::back()。後読みは \ を除かないので \back( も当たる）
            '/(?<![\w$])back\s*\(/',
            // 2. url()->previous()・URL::previous()・app('url')->previous()・url()->previousPath()（previousPath() もリファラーから作る）
            '/(?:->|::)\s*previous(?:Path)?\s*\(/',
            // 3. リファラーのヘッダーを直接読む（headers->get('referer')・HTTP_REFERER・綴りの違う referrer）
            '/referr?er/i',
            // 4. 今の URL へ戻す redirect()->refresh()・Redirect::refresh()（Eloquent の $model->refresh() は拾わない）
            '/(?:redirect\s*\(\s*\)\s*->|Redirect\s*::)\s*refresh\s*\(/',
            // 5. リクエストから今の URL を読む（$request->url()・request()->fullUrl()・Request::url()・$request->path() など。
            //    相対パスの path() も、redirect() が今のホストの URL に組み直す。Request:: の前は語の途中でないこと
            //    ＝ FormRequest::url( は拾わない）
            '/(?:\$request\s*->|request\s*\(\s*\)\s*->|(?<!\w)Request\s*::)\s*'
                . '(?:url|fullUrl|fullUrlWithQuery|fullUrlWithoutQuery|getUri|getRequestUri|path|decodedPath|getPathInfo)\s*\(/',
            // 6. 今の URL を作る（url()->current()・url()->full()・URL::current()・URL::full()・app('url')->current()。
            //    ->url() や ::url() のようなほかのメソッドの url() は拾わない）
            '/(?:(?<![\w$>:])url\s*\(\s*\)\s*->|(?<!\w)URL\s*::|app\s*\(\s*[\'"]url[\'"]\s*\)\s*->)\s*(?:current|full)\s*\(/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$text, $offset]) {
                    $found[] = [substr_count(substr($code, 0, $offset), "\n") + 1, trim($text)];
                }
            }
        }

        return $found;
    }

    /** コメントを改行に潰したソース（行番号は保たれる） */
    private function withoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(File::get($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array<string, list<array{int, string}>> 相対パス => 一致（0 件のファイルも入れる） */
    private function scan(): array
    {
        return array_map(
            fn (string $absolute) => $this->returnsToReferer($this->withoutComments($absolute)),
            $this->importControllerFiles()
        );
    }

    public function test_the_scan_sees_every_import_controller(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MIN_IMPORT_CONTROLLERS,
            count($this->scan()),
            '走査が空振りしている（*ImportController.php が少なすぎる）'
        );
    }

    public function test_import_controllers_never_send_the_user_back_to_the_referer(): void
    {
        $hits = $this->scan();
        $problems = [];

        foreach ($hits as $path => $found) {
            $expected = self::ALLOWED[$path][0] ?? 0;

            if (count($found) !== $expected) {
                $problems[] = "{$path}: 件数が " . count($found) . "（分類は {$expected}）: "
                    . implode(' / ', array_map(fn ($f) => ":{$f[0]} {$f[1]}", $found));
            }
        }

        foreach (array_keys(self::ALLOWED) as $path) {
            if (! array_key_exists($path, $hits)) {
                $problems[] = "{$path}: 分類にあるのに、そのファイルが無い（古い項目）";
            }
        }

        $this->assertSame([], $problems, "取込のコントローラがリファラーか今の URL へ戻している（確認画面の URL が POST 専用だと 405。"
            . "戻り先は取込の画面に固定する。docs/RULES.md Bug #64）:\n" . implode("\n", $problems));
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        // ⚠ 正規表現の枝は 1 つずつサンプルを通す。サンプルの無い枝は消しても全テストが緑になる（Bug #61 ③）
        $caught = [
            'return back();', 'return \back()->withErrors($e);', 'return redirect()->back();',
            'return Redirect::back();', 'return redirect() -> back ();',
            'return redirect(url()->previous());', 'return redirect(URL::previous());',
            'return redirect(app(\'url\')->previous());', 'return redirect(url() -> previous ());',
            'throw $e->redirectTo(url()->previousPath());', 'return redirect(URL::previousPath());',
            'return redirect($request->headers->get(\'referer\'));', '$to = $_SERVER[\'HTTP_REFERER\'];',
            '$to = $request->header(\'Referrer\');',
            'return redirect()->refresh();', 'return Redirect::refresh();', 'return redirect( ) -> refresh ( );',
            'return redirect($request->url());', 'return redirect()->to(request()->fullUrl());',
            'return redirect($request -> fullUrlWithQuery([\'a\' => 1]));',
            'return redirect(request( )->fullUrlWithoutQuery(\'page\'));',
            // 5. の呼び出し元 Request::（ファサード）と、今の URL を読むメソッド
            'return redirect(Request::url());', 'return redirect(Request::fullUrl());',
            'return redirect(\Illuminate\Support\Facades\Request::getPathInfo());',
            'return redirect($request->getUri());', 'return redirect($request->getRequestUri());',
            'return redirect($request->path());', 'return redirect(request()->decodedPath());',
            'return redirect($request->getPathInfo());',
            // 6. 今の URL を作る
            'return redirect(url()->current());', 'return redirect(url()->full());', 'return redirect(\url() -> current ());',
            'return redirect(URL::current());', 'return redirect(URL::full());',
            'return redirect(\Illuminate\Support\Facades\URL::current());',
            'return redirect(app(\'url\')->current());', 'return redirect(app("url")->full());',
            'throw $e->redirectTo(url()->current());',
        ];
        $ignored = [
            'return redirect()->route(\'admin.tenant-import\', [\'tab\' => $tab]);',
            'throw $e->redirectTo(route(\'admin.zeal.member-import\'));',
            'return redirect()->to(route(\'x\'));', '$url = session()->previousUrl();',
            '$next = $paginator->previousPageUrl();', 'return $this->fallback();', '$back();',
            'feedback($x);', 'goBack();', 'backup($db);',
            '$property->refresh();', '$this->refreshToken();', 'if ($request->fullUrlIs(\'x\')) {}',
            '$u = Storage::url($path);', '$u = $paginator->url(2);', '$u = $request->root();',
            // 5. と 6. が名前だけ同じ別の呼び出しを拾わないこと
            '$item = $iterator->current();', '$p = Storage::path($path);', '$p = $request->file(\'csv\')->path();',
            '$u = FormRequest::url();', '$u = $menu->url()->current();', '$u = Menu::url()->current();',
            '$u = shorturl()->current();', '$u = ShortURL::current();', '$u = $url()->current();',
        ];

        foreach ($caught as $sample) {
            $this->assertNotSame([], $this->returnsToReferer($sample), "拾えていない: {$sample}");
        }
        foreach ($ignored as $sample) {
            $this->assertSame([], $this->returnsToReferer($sample), "拾うべきでない: {$sample}");
        }
    }

    public function test_comments_are_dropped_before_scanning(): void
    {
        $php = sys_get_temp_dir() . '/import-back-' . uniqid('', true) . '.php';
        File::put($php, "<?php\n// back()\n/** url()->previous() と referer */\n# Redirect::back()\nreturn back();\n");

        try {
            // コメントの 3 つは数えず、5 行目の本物だけを行番号つきで拾う
            $this->assertSame([[5, 'back(']], $this->returnsToReferer($this->withoutComments($php)));
        } finally {
            File::delete($php);
        }
    }
}
