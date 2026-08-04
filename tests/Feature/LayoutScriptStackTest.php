<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * layouts/app.blade.php の @stack('scripts') が @push('scripts') の中身を実際に出力することを検証する。
 *
 * 【背景】2026-07-26 に本番で発見した既存バグ。
 * layouts/app.blade.php には @stack('scripts') が一度も存在せず
 * （`git log -S"@stack" -- resources/views/layouts/app.blade.php` が空）、
 * @push('scripts') に入れたスクリプトは初期コミット 2046289d 以来ずっと
 * サイレントに破棄されていた。本番実測では注文住宅一覧の進捗バッジに
 * onclick="openStepBar(this)" が付いているのに window.openStepBar が undefined で、
 * バッジをクリックしても無反応だった（コンパイルも view:cache も成功するので気づけない）。
 *
 * ⚠ このテストは「スクリプトが HTML に到達するか」だけを見る。
 *   JS が実際に動くかはブラウザでの目視確認でしか担保できない（実施済み）。
 *
 * ⚠ @push('scripts') を使うビューを新設したら、ここに 1 本足すこと。
 *   実使用箇所は `grep -rc "^@push('scripts')" resources/views` で数える
 *   （`grep -rln` だと `@@push` やコメント中の記述も拾うので数を誤る）。
 *
 * ⚠ **2026-08-04 にカバレッジが 2 ページ → 1 ページへ後退している。**
 *   housing/contracts/edit.blade.php の顧客名フリーテキスト（テナント事業の顧客検索 API を
 *   叩いていた）を _buyer-select パーシャルへ置き換えた結果、そのビューから
 *   @push('scripts') が無くなり、対応するテストを削除した。
 *   移設先が無かったため（実測で @push('scripts') を使うビューは
 *   housing/custom-orders/index.blade.php だけになった）復旧できていない。
 *   **@push('scripts') を使うビューを次に作るときは、必ずここにテストを 1 本足して 2 ページへ戻すこと。**
 */
class LayoutScriptStackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 注文住宅一覧の進捗ステップバー JS が出力される。
     *
     * 一覧からのステータス変更（PATCH /housing/custom-orders/{id}/status）は
     * この JS が無いと起動できない。バッジの onclick 属性だけが残り無反応になる。
     */
    public function test_custom_order_index_renders_step_bar_script(): void
    {
        HsCustomOrder::create([
            'order_code'    => 'CO-9001',
            'order_name'    => 'スタック検証邸 新築工事',
            'status'        => 'contracted',
            'customer_name' => '検証 用',
            'address'       => '松山市中央1-1-1',
            'created_by'    => 1,
        ]);

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // バッジ側の呼び出しと、それを定義する関数の両方が同一ページに載っていること。
        // ⚠ onclick だけを見ると「定義が無いのに通る」ため、必ず対で検証する。
        $res->assertSee('onclick="openStepBar(this)"', false);
        $res->assertSee('function openStepBar(badge)', false);
        $res->assertSee('function changeStatus(orderId, code, statusValue, statusLabel)', false);
        $res->assertSee('function closePopover()', false);
        // ステップバーが参照する定数とポップオーバー要素
        $res->assertSee("var STEPS = ['商談','設計','見積り','契約','着工','完成','引渡し'];", false);
        $res->assertSee('id="global-step-popover"', false);
    }

    /*
     * 【削除済み】test_housing_contract_edit_renders_alpine_component_script
     *
     * 2026-08-04 に housing/contracts/edit.blade.php の顧客名フリーテキストを
     * _buyer-select パーシャルへ置き換えたため、そのビューから @push('scripts') と
     * contractEditForm() が無くなり、このテストは検証対象を失った。
     * （_buyer-select は自前のインライン <script> で buyerSelect() を定義するので push を使わない）
     *
     * ⚠ 消したのは「テストが赤になったから」ではなく**検証対象が実在しなくなったから**。
     *   @stack('scripts') 自体の保護はクラス docblock に書いたとおり 1 ページに減っている。
     */

    /**
     * 何も push していないページでは @stack('scripts') が余計な出力を足さない。
     *
     * @stack は約 200 ルートが通る共有レイアウトに入るため、
     * 「追加しても既存ページの出力は変わらない」ことを固定する。
     */
    public function test_stack_adds_nothing_on_pages_without_pushed_scripts(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertDontSee('openStepBar', false);
        $res->assertDontSee('contractEditForm', false);
    }
}
