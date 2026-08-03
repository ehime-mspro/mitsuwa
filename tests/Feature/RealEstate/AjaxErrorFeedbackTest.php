<?php

namespace Tests\Feature\RealEstate;

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
 *   ② 原価・図面のハンドラには `!r.ok` の分岐が**無かった**ので、403/422 が返っても
 *      `data.success` が undefined で if を素通りし、`.catch` も reject しないため発火せず、
 *      **画面に何も出なかった**（バリデーションエラーも同様に不可視だった）
 *
 * ①だけ直しても②の画面は無反応のままなので、**サーバ側と呼び出し側を対で**固定する
 * （docs/RULES.md Bug #28 / #35 と同じ構図）。
 */
class AjaxErrorFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** 呼び出し元 JS を持つ Blade（この 3 本が対象の全て。2026-08-03 実測）*/
    private const VIEWS = [
        'realestate/projects/show.blade.php',
        'realestate/procurements/show.blade.php',
        'realestate/projects/lots.blade.php',
    ];

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

    /** 不動産コントローラに `error` キーの JSON 応答が 1 件も残っていないこと */
    public function test_no_controller_returns_legacy_error_key(): void
    {
        $files = glob(app_path('Http/Controllers/RealEstate/*.php'));

        $this->assertGreaterThanOrEqual(4, count($files), 'コントローラを拾えていない（走査の空振り防止）');

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
    // ============================================================

    /**
     * ⚠ サーバ側テストだけでは原理的に検出できない — Blade から `!r.ok` の分岐が消えても
     *    サーバのテストは緑のまま通る（Bug #28 / #35 と同じ構図）。
     */
    public function test_ajax_handlers_surface_server_errors(): void
    {
        $checked = 0;

        foreach (self::VIEWS as $view) {
            $blade = file_get_contents(resource_path('views/' . $view));

            // 4xx を素通りさせる形（!r.ok の分岐が無い）が残っていないこと
            $this->assertStringNotContainsString(
                '.then(function(r) { return r.json(); })',
                $blade,
                "{$view}: 4xx を握り潰すハンドラが残っている（!r.ok の分岐が要る）"
            );

            // fetch のたびに !r.ok を見て err.message を読んでいること
            $fetches = preg_match_all('/fetch\(/', $blade);
            $guards  = preg_match_all('/if \(!r\.ok\) \{/', $blade);
            $reads   = preg_match_all('/err\.message \|\| \x27エラーが発生しました。\x27/u', $blade);

            $this->assertGreaterThan(0, $fetches, "{$view}: fetch を 1 件も拾えていない（走査の空振り防止）");
            $this->assertSame($guards, $reads, "{$view}: !r.ok の分岐数と err.message の読み取り数が不一致");
            $this->assertGreaterThanOrEqual(2, $guards, "{$view}: エラー処理付きハンドラが少なすぎる");

            // 握り潰し防止のガード（null を受けたら何もしない）
            $this->assertSame(
                $guards,
                preg_match_all('/if \(!data\) return;/', $blade),
                "{$view}: !r.ok の分岐数と !data ガードの数が不一致（二重アラートになる）"
            );

            $checked++;
        }

        $this->assertSame(count(self::VIEWS), $checked);
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
