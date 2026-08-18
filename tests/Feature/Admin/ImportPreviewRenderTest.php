<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\TestCase;

/**
 * CSV 取込のプレビューが**実際に描画される**こと。
 *
 * ⚠ **2026-08-17〜18 の間、3 画面 12 経路すべてのプレビューが 500 だった。**
 *   コントローラが行エラーの配列を `'errors' => $errors` という名前で view に渡しており、
 *   Blade が `ShareErrorsFromSession` で共有する `$errors`（`ViewErrorBag`）を**上書き**する。
 *   そこへ 846cdf9d が `@if($errors->any())` を足したため、配列に対して `any()` を呼んで
 *   `Call to a member function any() on array` で落ちた。
 *   ファイルを選んで取込ボタンを押すと 500 ＝ **取込機能が丸ごと使用不能**だった。
 *
 * ⚠ **皮肉なことに 846cdf9d は「検証エラーが無音になる画面を直す」コミットだった。**
 *   不正な拡張子を投げて差し戻し先を見る検証はしていたが、それは **500 する経路を通らない**
 *   （`validate()` で弾かれて `back()` するだけでプレビューを描画しない）。
 *   **プレビューを一度も実際に描画していなかった**のが見逃した理由。
 *
 * ⚠ **`$errors` は Blade の予約変数。** view に `'errors'` キーを渡してはいけない。
 *   行エラーは `rowErrors` のような別名にする。
 */
class ImportPreviewRenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use CreatesSurveyQuestionSchema;

    /** 取込を持つコントローラ。新しい取込画面が増えたらここに足す。 */
    private const IMPORT_CONTROLLERS = [
        \App\Http\Controllers\Admin\CustomerImportController::class,
        \App\Http\Controllers\Admin\TenantImportController::class,
        \App\Http\Controllers\Admin\MansionImportController::class,
    ];

    /**
     * 走査が空振りして緑になる事故を防ぐ下限（Bug #45）。
     * 実測 176 件（2026-08-18）。
     */
    private const MIN_VIEW_CALLS = 150;

    /** 実測 12 経路（顧客 1 / テナント 5 / 賃貸マンション 6）。 */
    private const MIN_PREVIEW_ROUTES = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
        $this->createSurveyQuestionSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    // ================================================================
    // 挙動 — テンプレートを落として、そのまま上げ直す（往復）
    // ================================================================

    /**
     * 取込の入口を**全件機械的に列挙**し、それぞれのプレビューが 200 を返すこと。
     *
     * 列挙はルート名でなく**コントローラのメソッド名**で行う（ルート名は
     * `execute-property` / `property` / `execute` と 3 画面で不揃いだが、
     * メソッド名は `execute{X}` ↔ `download{X}Template` で完全に規則的）。
     * よって**新しいタブが増えたら自動で検査対象に入る**（Bug #45 の「全件分類」）。
     *
     * 送るのはその画面自身のテンプレート CSV なので、ヘッダー整合・サンプル列数・
     * BOM の読み戻しも同時に押さえられる。行が検証に落ちても**プレビューは描画される**
     * 設計なので、DB のフィクスチャは要らない。
     *
     * ⚠ **データプロバイダにはできない。** プロバイダは Laravel 起動前に評価されるので
     *   `Route::getRoutes()` が `A facade root has not been set.` で落ちる（実測）。
     *   よって列挙はテスト本体で行い、全経路の結果をまとめて突き合わせる。
     *
     * @return array<string, array{0: string, 1: string}> [テンプレート URL, 取込 URL]
     */
    private function importPreviewRoutes(): array
    {
        $byAction = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            if (! in_array($class, self::IMPORT_CONTROLLERS, true)) {
                continue;
            }
            $byAction[$class][$method] = $route;
        }

        $cases = [];
        foreach ($byAction as $class => $methods) {
            foreach ($methods as $method => $route) {
                if (! preg_match('/^execute(.*)$/', $method, $m)) {
                    continue;
                }
                if (! in_array('POST', $route->methods(), true)) {
                    continue;
                }

                $templateMethod = 'download' . $m[1] . 'Template';
                $templateRoute  = $methods[$templateMethod] ?? null;

                $label = class_basename($class) . '::' . $method;

                if ($templateRoute === null) {
                    // テンプレートが無い取込は、この往復では測れない。
                    // 「静かに対象外」にしないため、あえて失敗する組を返す。
                    $cases[$label] = ['__MISSING_TEMPLATE__:' . $templateMethod, '/' . $route->uri()];

                    continue;
                }

                $cases[$label] = ['/' . $templateRoute->uri(), '/' . $route->uri()];
            }
        }

        return $cases;
    }

    public function test_every_import_preview_renders(): void
    {
        $routes = $this->importPreviewRoutes();

        $this->assertGreaterThanOrEqual(
            self::MIN_PREVIEW_ROUTES,
            count($routes),
            '取込の入口の列挙が痩せている。走査が壊れていないか確認すること'
        );

        $actor   = $this->executive();
        $results = [];

        foreach ($routes as $label => [$templateUrl, $executeUrl]) {
            if (str_contains($templateUrl, '__MISSING_TEMPLATE__')) {
                $results[$label] = "テンプレート配信メソッドが無い（{$templateUrl}）";

                continue;
            }

            // ① その画面自身のテンプレート CSV を落とす
            $template = $this->actingAs($actor)->get($templateUrl . '?department=housing');

            if ($template->getStatusCode() !== 200) {
                $results[$label] = "テンプレート {$templateUrl} が " . $template->getStatusCode();

                continue;
            }

            // テンプレートは `response($csv, ...)` なので streamed ではない
            $csv = $template->getContent();

            if ($csv === '') {
                $results[$label] = "テンプレート {$templateUrl} が空の CSV を返した";

                continue;
            }

            // ② そのまま上げ直してプレビューを描画させる
            $response = $this->actingAs($actor)->post($executeUrl, [
                'csv_file'   => UploadedFile::fake()->createWithContent('template.csv', $this->withDataRow($csv)),
                'department' => 'housing',
            ]);

            if ($response->getStatusCode() !== 200) {
                $results[$label] = "{$executeUrl} が " . $response->getStatusCode()
                    . '（' . $this->failureReason($response) . '）';

                continue;
            }

            $results[$label] = $this->errorRowsAreVisible($response);
        }

        $expected = array_fill_keys(array_keys($results), 'OK');

        $this->assertSame($expected, $results, "取込プレビューが描画できない入口がある:\n");
    }

    /**
     * テンプレートに、全項目が空欄の行を 1 本足す。
     *
     * 2 つの理由でこれが要る:
     *
     * ① 顧客取込のテンプレートは**ヘッダー行だけ**で見本の行を持たない（賃貸マンションと
     *    テナントは見本を持つ）。行が無いと取込は「CSVファイルにデータがありません。」で
     *    差し戻し、**プレビューを描画しない**ので、この往復では何も測れない。
     *
     * ② 全項目が空欄の行は**どの取込でも必ず必須チェックに落ちる**。よって
     *    「行エラーが 1 件以上あるプレビュー」を全経路で確実に作れる。
     *    エラーが 0 件だと、下記 [[errorRowsAreVisible]] の検査が空振りする。
     *
     * 行が検証に落ちること自体は問題ない — 見たいのは取込の成功ではなく、
     * **エラー行を抱えたプレビューが正しく描画されること**。
     */
    private function withDataRow(string $csv): string
    {
        $lines = array_values(array_filter(
            explode("\n", $csv),
            fn (string $line): bool => trim($line) !== ''
        ));

        $columns = count(str_getcsv($lines[0] ?? ''));

        return rtrim($csv, "\n") . "\n" . str_repeat(',', max(0, $columns - 1)) . "\n";
    }

    /**
     * コントローラが出した行エラーが、**画面に実際に出ている**こと。
     *
     * ⚠ **200 を見るだけでは足りない。** ビューが `$rowErrors` でなく `$errors`
     *   （ViewErrorBag）を読むよう戻すと、`count()` が 0 を返すので
     *   `@if(count(...) > 0)` が false になり、**エラー行の一覧が画面から丸ごと消える**。
     *   例外は出ないので 200 のままで、**830 テスト全部が緑になる**（2026-08-18 実測）。
     *   Bug #43 / #46 / #49 と同じ「表示が消えても緑」型。
     *
     * コントローラ側の件数（`viewData('rowErrors')`）と画面の表示を突き合わせるので、
     * 変数名がズレた瞬間に赤くなる。
     */
    private function errorRowsAreVisible(\Illuminate\Testing\TestResponse $response): string
    {
        $rowErrors = $response->viewData('rowErrors');

        if (! is_array($rowErrors) || $rowErrors === []) {
            return '行エラーが 0 件。空欄だけの行が必須チェックに落ちていない';
        }

        $expected = 'エラー: <strong>' . count($rowErrors) . '</strong> 件';

        if (! str_contains($response->getContent(), $expected)) {
            return "行エラー " . count($rowErrors) . ' 件がコントローラにあるのに画面に出ていない'
                . "（「{$expected}」が見当たらない）";
        }

        return 'OK';
    }

    /** 失敗した応答から原因の 1 行を取り出す（500 の例外メッセージなど）。 */
    private function failureReason(\Illuminate\Testing\TestResponse $response): string
    {
        $exception = $response->baseResponse->exception ?? null;

        if ($exception !== null) {
            return get_class($exception) . ': ' . $exception->getMessage();
        }

        return 'リダイレクト先 ' . ($response->headers->get('Location') ?? '不明');
    }

    // ================================================================
    // 構造 — view に予約変数 $errors を渡していないこと
    // ================================================================

    /**
     * どのコントローラも view へ `'errors'` キーを渡していないこと。
     *
     * 挙動テストだけでは足りない: プレビューを描画しない**別の**画面が同じ形を
     * 持ち込んでも、その画面の往復テストが無ければ緑のまま通る。
     * 逆に構造テストだけでも足りない（キー名を変えただけで描画が壊れていないことの
     * 証明にならない）。**両方を対で置く**（Bug #28 / #35 と同じ構図）。
     */
    public function test_no_controller_passes_the_reserved_errors_key_to_a_view(): void
    {
        [$viewCalls, $offenders] = $this->scanViewCalls();

        $this->assertGreaterThanOrEqual(
            self::MIN_VIEW_CALLS,
            $viewCalls,
            '走査が空振りしている。view() の検出ロジックが壊れていないか確認すること'
        );

        $this->assertSame(
            [],
            $offenders,
            "view() に予約変数 `errors` を渡している箇所がある。\n"
            . "Blade の `\$errors` は ShareErrorsFromSession が共有する ViewErrorBag で、\n"
            . "同名のキーを渡すと上書きされ `\$errors->any()` が fatal になる。\n"
            . "行エラーは `rowErrors` のような別名にすること。\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * `app/Http/Controllers` 配下の `view(...)` 呼び出しを括弧の対応で切り出し、
     * `'errors' =>` を持つものを列挙する。
     *
     * ⚠ コメントを落としてから測る（注意書きに書いた文字列に一致して
     *   実体を消しても緑のまま、という事故を防ぐ。Bug #42 ②）。
     *
     * @return array{0: int, 1: list<string>}
     */
    private function scanViewCalls(): array
    {
        $base  = base_path('app/Http/Controllers');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        $count     = 0;
        $offenders = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $clean = $this->sourceWithoutComments(file_get_contents($file->getPathname()));
            $short = str_replace(base_path() . '/', '', $file->getPathname());

            $offset = 0;
            while (($pos = strpos($clean, 'view(', $offset)) !== false) {
                // `->view(` `::view(` `previewX(` のような別物を除く
                $prev = $pos > 0 ? $clean[$pos - 1] : ' ';
                if (preg_match('/[A-Za-z0-9_>:]/', $prev)) {
                    $offset = $pos + 5;

                    continue;
                }

                $end = $this->matchingParen($clean, $pos + 4);
                if ($end === null) {
                    $offset = $pos + 5;

                    continue;
                }

                $block = substr($clean, $pos, $end - $pos + 1);
                $count++;

                if (preg_match("/'errors'\s*=>/", $block)) {
                    $line        = substr_count(substr($clean, 0, $pos), "\n") + 1;
                    $offenders[] = "  {$short}:{$line}";
                }

                $offset = $end + 1;
            }
        }

        sort($offenders);

        return [$count, $offenders];
    }

    /** `$open` の位置にある `(` に対応する `)` の位置。見つからなければ null。 */
    private function matchingParen(string $src, int $open): ?int
    {
        $depth = 0;
        $len   = strlen($src);

        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '(') {
                $depth++;
            } elseif ($src[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** コメントを改行に潰したソース（行番号は保たれる）。 */
    private function sourceWithoutComments(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
