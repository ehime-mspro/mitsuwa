<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ReCostItem;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * Ajax のエラーがユーザーに届くことを固定する。
 *
 * ⚠ 欠陥は 2 段構えだった（2026-08-03 実測）:
 *   ① サーバが `['error' => ...]` を返していたが、呼び出し元 JS は `err.message` を読む
 *      → 理由が出ず「エラーが発生しました。」になる
 *   ② `!res.ok` の分岐が**無い**ハンドラでは、403/422 が返っても
 *      `data.success` が undefined で if を素通りし、`.catch` も reject しないため発火せず、
 *      **画面に何も出なかった**（バリデーションエラーも同様に不可視だった）
 *
 * ①だけ直しても②の画面は無反応のままなので、**サーバ側と呼び出し側を対で**固定する
 * （docs/RULES.md Bug #28 / #35 と同じ構図）。
 *
 * ⚠ **2026-08-03: アプリ全体を網羅した。** 以前は「直した分だけ」を列挙するラチェットで、
 *    未修正のファイルが 19 本残っていた。現在は `fetch` を持つ Blade を**全件**分類し、
 *    どのリストにも入っていない新規ファイルが現れたら
 *    `test_every_fetch_view_is_classified` が落ちる。列挙漏れによる無検査を原理的に防ぐ。
 */
class AjaxErrorFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /**
     * P1「null 返し」方式。`if (!res.ok) { …; return null; }` と
     * `if (!data …) return;` が対で書かれているので、両者の数の一致を固定できる。
     */
    private const VIEWS_NULL_RETURN = [
        // 工程表（4 親の詳細画面が共有する唯一の定義。設計書 §4.1）
        //   ⚠ fetch は send() の 1 本だけ。`.ok` 分岐 1 個 == `!data` ガード 1 個 の対応を崩さないこと
        //     （増やすなら send() を経由させる）。
        '_partials/_schedule_section.blade.php',                    // null 返し（工程表の CRUD）
        // 不動産
        'realestate/_partials/supplier-picker.blade.php',
        'realestate/contracts/create.blade.php',
        'realestate/contracts/edit.blade.php',
        'realestate/procurements/index.blade.php',
        'realestate/procurements/show.blade.php',
        'realestate/projects/index.blade.php',
        'realestate/projects/lots.blade.php',
        'realestate/projects/show.blade.php',
        // 住宅事業
        // ⚠ housing/contracts/edit.blade.php は 2026-08-04 にリストから外した。
        //    顧客名のフリーテキスト（テナント事業の顧客検索 API を叩いていた）を
        //    _buyer-select パーシャルへ置き換えた結果、このビューから fetch が無くなったため。
        //    このラチェットの test_every_fetch_view_is_classified が陳腐化を検出してくれた。
        'housing/custom-orders/_form.blade.php',
        'housing/custom-orders/index.blade.php',
        'housing/custom-orders/show.blade.php',
        'housing/properties/_form.blade.php',
        'housing/properties/index.blade.php',
        'housing/properties/show.blade.php',
        // テナント
        'tenant/area-buildings/_map.blade.php',
        'tenant/contracts/create.blade.php',
        'tenant/contracts/edit.blade.php',
        'tenant/inquiries/create.blade.php',
        'tenant/inquiries/edit.blade.php',
        // 賃貸マンション
        'mansion/parking-contracts/create.blade.php',
        // ZEAL
        'zeal/stores/index.blade.php',
        'zeal/trainers/index.blade.php',
        // マスタ（並び替え）
        'admin/master/dad-specialties/index.blade.php',
        'admin/master/re-cost-items/index.blade.php',
        'admin/master/structure-types/index.blade.php',
        'admin/master/usage-types/index.blade.php',
        'admin/master/zoning-types/index.blade.php',
        // 共通コンポーネント
        'components/attachment-section.blade.php',
    ];

    /**
     * null 返し以外の**正当な**方式。数の対応は取れないが、
     * 「`.ok` を見ていること」と「エラーがユーザーに見える先へ届くこと」は固定する。
     *
     *  - エンベロープ … `.then(r => r.json().then(j => ({ok: r.ok, status, body: j})))`
     *  - throw       … `if (!res.ok) { throw new Error(...) }` + 表示する `.catch`
     *  - async/await … `Promise.all` の結果を `if (!a.ok || !b.ok)` でまとめて判定
     */
    private const VIEWS_OTHER = [
        'admin/master/zeal-simulation-categories/index.blade.php',  // throw
        'housing/contracts/_buyer-select.blade.php',                // null 返し + throw の混在
        'mansion/contracts/create.blade.php',                       // async/await + Promise.all
        'realestate/_partials/_cost_excel_import_script.blade.php', // エンベロープ
        'zeal/simulations/show.blade.php',                          // throw
    ];

    /**
     * 外部 API（zipcloud の郵便番号補完）。**握り潰しで正しい**。
     *
     * 住所の自動補完は任意の補助機能で、失敗しても利用者は住所を手入力できる。
     * 外部サービスの不調でエラーを出すとかえって邪魔になるため、
     * `data.results.length` を見て静かに諦める設計になっている。
     */
    private const VIEWS_EXTERNAL_API = [
        'dad/clients/_form.blade.php',
        'dad/subcontractors/_form.blade.php',
        'zeal/members/_form.blade.php',
    ];

    /** 4xx を素通りさせる形。⚠ 引数名は r / res / response が混在するので後方参照で見る。 */
    private const SWALLOW = '/\.then\(\s*function\s*\(\s*(\w+)\s*\)\s*\{\s*return\s+\1\.json\(\)\s*;?\s*\}\s*\)/';

    /** `if (!res.ok)` `if (!roomsRes.ok || …)`。⚠ camelCase があるので [a-z]+ では足りない。 */
    private const OK_GUARD = '/if \(![a-zA-Z_$][\w$]*\.ok\b/';

    /** `if (!data) return;` と `if (!data || !data.success) return;` の両方。 */
    private const DATA_GUARD = '/if \(!data\b/';

    /** ユーザーに見える出力。これが無ければ握り潰しと同じ。 */
    private const SINK = '/alert\(|showMessage\(|errorMessage\s*=|errorMsg\s*=|customerError\s*=|\.error\s*=|throw new Error/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** `fetch(` を持つ Blade を相対パスで全件返す。 */
    private function fetchViews(): array
    {
        $views = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (str_contains(file_get_contents($file->getPathname()), 'fetch(')) {
                $views[] = str_replace(resource_path('views') . '/', '', $file->getPathname());
            }
        }

        sort($views);

        return $views;
    }

    /**
     * `if (!x.ok …) { … }` のブロック本体を波括弧の対応で切り出す。
     *
     * ⚠ 固定長で切り出すと隣のハンドラまで含んでしまい、
     *    無関係な `alert(` に一致して**誤って緑になる**。
     */
    private function okGuardBodies(string $blade): array
    {
        $bodies = [];

        if (! preg_match_all(self::OK_GUARD, $blade, $m, PREG_OFFSET_CAPTURE)) {
            return $bodies;
        }

        foreach ($m[0] as [$_match, $start]) {
            $open = strpos($blade, '{', $start);
            if ($open === false) {
                continue;
            }
            $depth = 0;
            for ($i = $open, $len = strlen($blade); $i < $len; $i++) {
                if ($blade[$i] === '{') {
                    $depth++;
                } elseif ($blade[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $bodies[] = substr($blade, $open, $i - $open + 1);
                        break;
                    }
                }
            }
        }

        return $bodies;
    }

    // ============================================================
    // ① サーバ側 — 4xx の JSON キーは message で統一されている
    // ============================================================

    /**
     * 所属違いの原価を消そうとしたら 403 + message。
     * ⚠ `error` キーに戻すと、呼び出し元 JS（`err.message`）が理由を出せなくなる。
     */
    public function test_destroy_cost_of_another_project_returns_message_key(): void
    {
        $projectA = ReProject::create([
            'project_code' => 'PJ-001', 'project_name' => '分譲地A', 'status' => 'selling',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
        ]);
        $projectB = ReProject::create([
            'project_code' => 'PJ-002', 'project_name' => '分譲地B', 'status' => 'selling',
            'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
        ]);
        $item = ReCostItem::create(['name' => '造成費', 'sort_order' => 1, 'is_active' => true]);
        $cost = ReProjectCost::create([
            'project_id' => $projectB->id, 'cost_item_id' => $item->id, 'estimated_amount' => 1000,
        ]);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$projectA->id}/costs/{$cost->id}");

        $response->assertStatus(403);
        $response->assertExactJson(['message' => '不正なリクエストです。']);
    }

    /** 区画の更新も同じ（呼び出し元は既に err.message を読んでいた＝キーだけが不一致だった） */
    public function test_update_lot_of_another_project_returns_message_key(): void
    {
        $projectA = ReProject::create([
            'project_code' => 'PJ-001', 'project_name' => '分譲地A', 'status' => 'selling',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
        ]);
        $projectB = ReProject::create([
            'project_code' => 'PJ-002', 'project_name' => '分譲地B', 'status' => 'selling',
            'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
        ]);
        $lot = ReProjectLot::create([
            'project_id' => $projectB->id, 'lot_number' => 1,
            'area_sqm' => 100.00, 'area_tsubo' => 30.25, 'status' => 'on_sale',
        ]);

        $response = $this->actingAs($this->executive())
            ->putJson("/realestate/projects/{$projectA->id}/lots/{$lot->id}", [
                'lot_number' => 1, 'area_sqm' => 100, 'status' => 'on_sale',
            ]);

        $response->assertStatus(403);
        $response->assertExactJson(['message' => '不正なリクエストです。']);
    }

    /**
     * 不動産・住宅事業のコントローラに `error` キーの JSON 応答が 1 件も残っていないこと。
     *
     * ⚠ **アプリ全体ではない。** `CustomerController` の 7 箇所は郵便番号検索などで、
     *    呼び出し元がどのキーを読むか未確認のため対象外にしてある（読まずに変えると
     *    今度はそちらで理由が出なくなる）。確認できたらここに足すこと。
     */
    public function test_no_controller_returns_legacy_error_key(): void
    {
        $files = array_merge(
            glob(app_path('Http/Controllers/RealEstate/*.php')),
            glob(app_path('Http/Controllers/Housing/*.php')),
        );

        $this->assertGreaterThanOrEqual(7, count($files), 'コントローラを拾えていない（走査の空振り防止）');

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                "response()->json(['error'",
                file_get_contents($file),
                basename($file) . ' に古い error キーの JSON 応答が残っている'
            );
        }
    }

    // ============================================================
    // ② 呼び出し側 — 4xx を握り潰さずユーザーに見せる
    //
    // ⚠ サーバ側テストだけでは原理的に検出できない — Blade から `!res.ok` の分岐が
    //    消えてもサーバのテストは緑のまま通る（Bug #28 / #35 と同じ構図）。
    // ============================================================

    /**
     * `fetch` を持つビューが 3 つのリストのいずれかに分類されていること。
     *
     * ⚠ **これが列挙漏れを防ぐ要。** 新しく `fetch` を書いたビューを追加すると、
     *    分類するまでこのテストが落ちる（無検査のまま増えることが無い）。
     */
    public function test_every_fetch_view_is_classified(): void
    {
        $known = array_merge(self::VIEWS_NULL_RETURN, self::VIEWS_OTHER, self::VIEWS_EXTERNAL_API);
        $found = $this->fetchViews();

        $this->assertGreaterThanOrEqual(30, count($found), 'fetch を持つ Blade の走査が機能していない（空振り防止）');

        $this->assertSame(
            [],
            array_values(array_diff($found, $known)),
            "分類されていない fetch 付きビューがあります。\n"
            . 'VIEWS_NULL_RETURN / VIEWS_OTHER / VIEWS_EXTERNAL_API のいずれかに追加してください。'
        );

        $this->assertSame(
            [],
            array_values(array_diff($known, $found)),
            'リストに載っているのに fetch を持たないビューがあります（リストが陳腐化している）'
        );
    }

    /**
     * 外部 API の 3 本を除き、4xx を素通りさせる形が 1 つも残っていないこと。
     *
     * ⚠ **引数名を決め打ちにしない。** かつて `.then(function(r) { return r.json(); })` を
     *    リテラル比較していたため、`(res)` で書かれた `_buyer-select` の checkDup を
     *    見逃していた（2026-08-03 実測）。
     */
    public function test_no_view_swallows_ajax_errors(): void
    {
        $offenders = [];

        foreach ($this->fetchViews() as $view) {
            if (in_array($view, self::VIEWS_EXTERNAL_API, true)) {
                continue;
            }
            if (preg_match(self::SWALLOW, file_get_contents(resource_path('views/' . $view)))) {
                $offenders[] = $view;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "4xx を握り潰すハンドラが残っています（!res.ok の分岐が要る）:\n" . implode("\n", $offenders)
        );
    }

    /** null 返し方式は `.ok` の分岐数と `!data` ガードの数が一致すること。 */
    public function test_null_return_views_pair_ok_guard_with_data_guard(): void
    {
        foreach (self::VIEWS_NULL_RETURN as $view) {
            $blade = file_get_contents(resource_path('views/' . $view));

            $this->assertGreaterThan(0, preg_match_all('/fetch\(/', $blade), "{$view}: fetch を 1 件も拾えていない（走査の空振り防止）");

            $guards = preg_match_all(self::OK_GUARD, $blade);
            $this->assertGreaterThan(0, $guards, "{$view}: エラーを見ているハンドラが 1 つも無い");

            // 4xx で null を返す以上、後続は必ずガードが要る（無いと二重アラート／TypeError）
            $this->assertSame(
                $guards,
                preg_match_all(self::DATA_GUARD, $blade),
                "{$view}: .ok の分岐数と !data ガードの数が不一致"
            );
        }
    }

    /** null 返し以外の方式でも、`.ok` を見ていて表示先があること。 */
    public function test_other_views_check_ok_and_have_a_sink(): void
    {
        foreach (self::VIEWS_OTHER as $view) {
            $blade = file_get_contents(resource_path('views/' . $view));

            $this->assertMatchesRegularExpression('/\.ok\b/', $blade, "{$view}: レスポンスの .ok を見ていない");
            $this->assertMatchesRegularExpression(self::SINK, $blade, "{$view}: エラーの表示先が無い");
        }
    }

    /**
     * すべての `if (!x.ok)` ブロックが、ユーザーに見える出力へ到達すること。
     *
     * ⚠ `.ok` の分岐があっても中身が `console.error` だけなら画面は無音のまま。
     *    「分岐の数」ではなく「分岐の中身」を見る。
     */
    public function test_every_ok_guard_reaches_a_user_visible_sink(): void
    {
        $offenders = [];
        $checked   = 0;

        foreach (array_merge(self::VIEWS_NULL_RETURN, self::VIEWS_OTHER) as $view) {
            $blade = file_get_contents(resource_path('views/' . $view));

            foreach ($this->okGuardBodies($blade) as $i => $body) {
                $checked++;
                if (! preg_match(self::SINK, $body)) {
                    $offenders[] = "{$view} の " . ($i + 1) . ' 番目の !ok ブロック';
                }
            }
        }

        $this->assertGreaterThanOrEqual(40, $checked, '!ok ブロックの走査が機能していない（空振り防止）');
        $this->assertSame(
            [],
            $offenders,
            "エラーを画面に出さない !ok ブロックがあります:\n" . implode("\n", $offenders)
        );
    }

    // ============================================================
    // 既存バグ — 同一タグへの style 属性の二重指定
    // ============================================================

    /**
     * HTML パーサは 2 個目の `style` を捨てるため、そこに書いた装飾は**描画されない**。
     * `procurements/show.blade.php` の 4 行が該当し、採算パターンの罫線が出ていなかった
     * （対になる `projects/show.blade.php` は正しく 1 つに merge 済みだった。2026-08-03 実測）。
     */
    public function test_no_view_has_duplicate_style_attribute(): void
    {
        $views = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[] = $file->getPathname();
            }
        }

        $this->assertGreaterThanOrEqual(100, count($views), 'Blade を 100 本以上拾えていない（走査の空振り防止）');

        $offenders = [];
        foreach ($views as $path) {
            foreach (file($path) as $i => $line) {
                if (preg_match('/style="[^"]*"[^>]*style="/', $line)) {
                    $offenders[] = str_replace(resource_path('views') . '/', '', $path) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "同一タグに style 属性が 2 個ある（2 個目は捨てられる）:\n" . implode("\n", $offenders));
    }
}
