<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 取込のコントローラが、断るときにリファラー（「元の画面」）へ戻していないことを全件分類で守る
 * （docs/RULES.md Bug #64・Top trap #13）。
 *
 * 取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。確認画面に載ったフォームから
 * 送って `back()` や `url()->previous()` で戻すと、リファラー＝その URL へ GET で戻り 405 になる
 * （2026-09-24 実測: テナント・賃貸マンション・ZEAL 会員・工程表）。戻り先は取込の画面に固定する。
 *
 * ⚠ `app/Http/Controllers` 配下の `*ImportController.php` を機械的に列挙する（新しい取込は自動で検査対象に入る）。
 *   許すのは ALLOWED に載せたもの（件数と理由つき）だけ。ほかは 0 件でないと落ちる。件数が合わない・
 *   実在しないファイルが載っている、も落とす。
 * ⚠ コメントを落としてから数える。docblock に「`back()` を使わない」と書いてあるため（Bug #42 ②）。
 *
 * ⚠ 見えないもの:
 *   - **入力チェックの既定の戻り先。** `$request->validate([...])` を try で包まずに書くと、失敗したときの
 *     戻り先はリファラーになるが、コードに `back(` が現れないので走査では拾えない（FormRequest の既定の戻り先も
 *     同じ）。ここは挙動のテスト（確認画面の URL をリファラーにして送る）が守る:
 *     `Admin\TenantImportRejectionTest` / `Admin\MansionImportRejectionTest` /
 *     `Admin\ZealMemberImportControllerTest` / `Housing\ScheduleImportTest`
 *   - `*ImportController.php` という名前でない取込（ほかのコントローラに内蔵された確認画面）
 *   - 戻り先の URL を変数に入れて渡す形（`$to = $request->headers->get('referer')` は字面で拾うが、
 *     別のメソッドやクラスで作った URL を受け取る形は見えない）
 * ⚠ 過剰に拾うもの: 文字列リテラルの中の `back(`・`referer`（走査はトークンを見ない）・
 *   Carbon の `->previous(` のように別の意味の `previous()`。出てきたら ALLOWED に理由つきで載せる
 *   （検出器を緩めない）。
 */
class ImportControllerReturnPathScanTest extends TestCase
{
    /** `*ImportController.php` の本数の下限（2026-09-24 実測 8 本）。下回ったら列挙が空振りしている */
    private const MIN_IMPORT_CONTROLLERS = 8;

    /** @var array<string, array{0: int, 1: string}> 相対パス => [件数, 理由] */
    private const ALLOWED = [
        'app/Http/Controllers/Admin/CustomerImportController.php' => [
            2,
            '確認画面の URL が取込の画面と同じ（GET と POST がどちらも /admin/customers/import）。'
                . 'リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）',
        ],
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
            // 1. back()（ヘルパー・\back()・redirect()->back()・Redirect::back()）
            '/(?<![\w$])\\\\?back\s*\(/',
            // 2. url()->previous()・URL::previous()・app('url')->previous()
            '/(?:->|::)\s*previous\s*\(/',
            // 3. リファラーのヘッダーを直接読む（headers->get('referer')・HTTP_REFERER・綴りの違う referrer）
            '/referr?er/i',
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
        $hits = [];

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            if (! str_ends_with($file->getFilename(), 'ImportController.php')) {
                continue;
            }

            $hits[str_replace(base_path() . '/', '', $file->getPathname())] =
                $this->returnsToReferer($this->withoutComments($file->getPathname()));
        }

        ksort($hits);

        return $hits;
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

        $this->assertSame([], $problems, "取込のコントローラがリファラーへ戻している（確認画面の URL が POST 専用だと 405。"
            . "戻り先は取込の画面に固定する。docs/RULES.md Bug #64）:\n" . implode("\n", $problems));
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        // ⚠ 正規表現の枝は 1 つずつサンプルを通す。サンプルの無い枝は消しても全テストが緑になる（Bug #61 ③）
        $caught = [
            'return back();', 'return \back()->withErrors($e);', 'return redirect()->back();',
            'return Redirect::back();', 'return redirect() -> back ();',
            'return redirect(url()->previous());', 'return redirect(URL::previous());',
            'return redirect(app(\'url\')->previous());',
            'return redirect($request->headers->get(\'referer\'));', '$to = $_SERVER[\'HTTP_REFERER\'];',
            '$to = $request->header(\'Referrer\');',
        ];
        $ignored = [
            'return redirect()->route(\'admin.tenant-import\', [\'tab\' => $tab]);',
            'throw $e->redirectTo(route(\'admin.zeal.member-import\'));',
            'return redirect()->to(route(\'x\'));', '$url = session()->previousUrl();',
            '$next = $paginator->previousPageUrl();', 'return $this->fallback();', '$back();',
            'feedback($x);', 'goBack();', 'backup($db);',
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
