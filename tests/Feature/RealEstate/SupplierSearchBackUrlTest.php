<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\ZoningType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ先 Ajax 検索がセッションの「直前 URL」を汚染しないことの検証。
 *
 * 汚染されると $request->validate() 失敗時の back() が JSON エンドポイントへ飛び、
 * ユーザーはフォームに戻れず入力を全部失う（本番で発生していた既存バグ）。
 *
 * ⚠ 挙動テスト側は fetch のヘッダーを PHP から手で送るため、
 *    Blade がヘッダーを送らなくなっても緑のままになる。
 *    そこで「Blade が実際にヘッダーを送っていること」も対で固定する
 *    （docs/RULES.md Bug #28 の教訓: 呼び出し側と定義側を必ず対で検証する）。
 */
class SupplierSearchBackUrlTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        ZoningType::create(['name' => '第一種住居地域', 'sort_order' => 5]);
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** supplier-picker の検索 fetch が送るヘッダー（Blade 側と一致させること） */
    private function searchAsBladeDoes(User $user): void
    {
        $this->actingAs($user)->get('/api/realestate/suppliers/search?q=abc', [
            'Accept'           => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk();
    }

    /** 分譲地: 検索を挟んでもバリデーションエラーがフォームへ戻り ?from も保つ */
    public function test_project_create_back_survives_supplier_search(): void
    {
        $user = $this->executive();

        $this->actingAs($user)->get('/realestate/projects/create?from=procurements')->assertOk();
        $this->searchAsBladeDoes($user);

        $this->actingAs($user)->post('/realestate/projects', [])
            ->assertRedirect(route('realestate.projects.create', ['from' => 'procurements']));
    }

    /** 仕入れ案件: 同じ partial を使う画面でも戻り先が壊れない */
    public function test_procurement_create_back_survives_supplier_search(): void
    {
        $user = $this->executive();

        $this->actingAs($user)->get('/realestate/procurements/create')->assertOk();
        $this->searchAsBladeDoes($user);

        $this->actingAs($user)->post('/realestate/procurements', [])
            ->assertRedirect(route('realestate.procurements.create'));
    }

    /**
     * Blade の検索 fetch が実際に X-Requested-With を送っていること。
     *
     * ⚠ このテストが無いと、上の 2 本はヘッダーを手で送るので
     *    Blade からヘッダーが消えても緑のまま通ってしまう。
     */
    public function test_supplier_picker_search_fetch_sends_ajax_header(): void
    {
        $path = resource_path('views/realestate/_partials/supplier-picker.blade.php');
        $this->assertFileExists($path);

        $source = file_get_contents($path);

        // 検索 fetch の呼び出し位置を起点に、その fetch のオプション部分だけを見る。
        // アンカーが見つからないまま素通りして緑になる事故を防ぐため、存在も明示的に固定する。
        $pos = strpos($source, '/api/realestate/suppliers/search');
        $this->assertNotFalse($pos, '検索 fetch が見つからない（partial の構造が変わった可能性）');

        // 窓は「次の fetch( の直前まで」で取る。固定長にするとヘッダーが増えたときに
        // 目的の文字列が窓の外へ出て、実際には有るのに「無い」と報告する嘘の失敗になる。
        $nextFetch = strpos($source, 'fetch(', $pos + 1);
        $searchFetchBlock = $nextFetch !== false
            ? substr($source, $pos, $nextFetch - $pos)
            : substr($source, $pos);

        $this->assertStringContainsString(
            'X-Requested-With',
            $searchFetchBlock,
            '検索 fetch に X-Requested-With が無い。'
            . 'セッションの直前 URL が JSON エンドポイントで上書きされ、'
            . 'バリデーションエラー時の back() がフォームに戻らなくなる'
        );
    }
}
