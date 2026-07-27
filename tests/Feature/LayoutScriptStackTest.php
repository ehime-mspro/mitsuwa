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
 *   grep -rln "@push('scripts')" resources/views/ で現在の利用箇所を確認できる。
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

    /**
     * 建売契約 編集フォームの Alpine コンポーネント定義が出力される。
     *
     * edit.blade.php は x-data="contractEditForm()" を使うため、定義が
     * 捨てられていると Alpine がコンポーネントを初期化できず顧客名検索が死ぬ
     * （docs/RULES.md Bug #23 と同じ症状クラス）。
     * 到達経路: GET /housing/properties/{property}/contract/edit
     */
    public function test_housing_contract_edit_renders_alpine_component_script(): void
    {
        $property = HsProperty::create([
            'property_code' => 'HS-9001',
            'property_name' => 'スタック検証A号地',
            'status'        => 'construction',
            'address'       => '松山市中央2-2-2',
            'created_by'    => 1,
        ]);

        HsContract::create([
            'property_id'            => $property->id,
            'customer_name'          => '検証 太郎',
            'selling_price_building' => 28500000,
            'selling_price_land'     => 12800000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);

        $res = $this->actingAs($this->executive())->get("/housing/properties/{$property->id}/contract/edit");

        $res->assertOk();
        // x-data の呼び出しと関数定義を対で検証する
        $res->assertSee('x-data="contractEditForm()"', false);
        $res->assertSee('function contractEditForm()', false);
    }

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
