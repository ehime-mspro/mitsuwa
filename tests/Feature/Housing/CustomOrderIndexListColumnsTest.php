<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 注文住宅一覧（/housing/custom-orders）の金額列を検証する。
 *
 * hs_* は migration 管理外のため CreatesRealEstateSchema trait でスキーマを構築する。
 *
 * ⚠ order_code は列としては消えるが、進捗バッジの data-code 属性に残るため
 *   assertDontSee($order->order_code) は必ず失敗する。列の消失は <th> で判定する。
 */
class CustomOrderIndexListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access:housing を無条件通過する） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** グループ見出しにシステム既定の消費税率が出る（小数以下の 0 は落とす） */
    public function test_building_group_header_shows_tax_rate(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('消費税 10%', false);
        $res->assertSee('消費税 非課税', false);
    }

    // ============================================================
    // ヘルパー — テスト対象の案件を作る
    // ============================================================

    /**
     * 自社土地（分譲地区画）の案件。
     * 建物: 28,500,000 / 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 12,800,000 /  9,600,000 → 粗利 3,200,000（25.0%）
     */
    private function makeCompanyLandOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0001',
            'order_name'              => '石井町A様邸 新築工事',
            'status'                  => 'contracted',
            'customer_name'           => '山田 太郎',
            'address'                 => '松山市石井町1-2-3',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 28500000,
            'building_cost'           => 21300000,
            'land_selling_price'      => 12800000,
            'land_cost'               => 9600000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * お客様所有土地の案件。土地カラムに値を入れてあるが、
     * isCompanyLand() が false なので土地 4 セルは「—」でなければならない。
     * 建物: 32,000,000 / 24,800,000 → 粗利 7,200,000（22.5%）／税込 35,200,000
     */
    private function makeCustomerLandOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0002',
            'order_name'              => '見奈良B様邸 新築工事',
            'status'                  => 'design',
            'customer_name'           => '佐藤 花子',
            'address'                 => '東温市見奈良456',
            'land_source_type'        => 'customer_land',
            'building_contract_price' => 32000000,
            'building_cost'           => 24800000,
            // ↓ 表示されてはいけない値をあえて残す（§3.4 のガード検証）
            'land_selling_price'      => 12800000,
            'land_cost'               => 9600000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /** 金額が 1 つも入っていない案件 */
    private function makeEmptyAmountOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'    => 'CO-2026-0003',
            'order_name'    => '市場D様邸 新築工事',
            'status'        => 'consultation',
            'customer_name' => '高橋 実',
            'address'       => '伊予市市場321',
            'created_by'    => 1,
        ]);
    }

    /**
     * 赤字の案件。
     * 建物: 20,000,000 / 23,000,000 → 粗利 -3,000,000（-15.0%）
     */
    private function makeNegativeProfitOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0004',
            'order_name'              => '筒井C様邸 新築工事',
            'status'                  => 'construction',
            'customer_name'           => '鈴木 一郎',
            'address'                 => '伊予郡松前町筒井789',
            'land_source_type'        => 'customer_land',
            'building_contract_price' => 20000000,
            'building_cost'           => 23000000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * 自社土地で、建物は黒字・土地が赤字の案件。
     * 建物: 30,000,000 / 25,500,000 → 粗利  4,500,000（ 15.0%）／税込 33,000,000
     * 土地: 10,000,000 / 12,000,000 → 粗利 -2,000,000（-20.0%）
     *
     * 建物と土地で符号が逆になるので、土地セルが建物の値を使い回していたら破綻する。
     */
    private function makeNegativeLandProfitOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0005',
            'order_name'              => '北条E様邸 新築工事',
            'status'                  => 'estimation',
            'customer_name'           => '田中 次郎',
            'address'                 => '松山市北条辻555',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 30000000,
            'building_cost'           => 25500000,
            'land_selling_price'      => 10000000,
            'land_cost'               => 12000000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    // ============================================================
    // テスト
    // ============================================================

    /**
     * 案件番号の「列」が消えている。
     *
     * ⚠ assertDontSee($order->order_code) は使えない。
     *   進捗バッジの data-code 属性に order_code が残るため必ず失敗する。
     *   列の消失は <th> の生 HTML で判定する。
     */
    public function test_order_code_column_header_is_removed(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('>案件番号</th>', false);
        // order_code 自体は data-code に残っている（消えていないことを明示的に固定する）
        $res->assertSee('data-code="CO-2026-0001"', false);
    }

    /** 2 段ヘッダーのグループ見出しが colspan="4" で出る */
    public function test_group_headers_render_with_colspan_four(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // colspan と見出し文言を別々に見ると相関が取れないので <th> ごと 1 本で見る。
        // 「建　物」「土　地」の間は全角スペース（U+3000）。
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        // 「進捗 / 案件名 / 顧客名 / 詳細」は 2 段ぶち抜き
        $res->assertSee('rowspan="2"', false);
    }

    /** 自社土地の案件で建物 4 値（税抜 / 税込 / 原価 / 粗利 / 粗利率）が出る */
    public function test_company_land_order_shows_building_amounts(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('28,500,000円');            // 販売金額（税抜）
        $res->assertSee('税込 31,350,000円');       // 税込サブ行
        $res->assertSee('21,300,000円');            // 原価額
        $res->assertSee('7,200,000円');             // 粗利額
        $res->assertSee('25.3%');                   // 粗利率
    }

    /** 同じ案件で土地 4 値が出る */
    public function test_company_land_order_shows_land_amounts(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('12,800,000円');   // 販売金額
        $res->assertSee('9,600,000円');    // 原価額
        $res->assertSee('3,200,000円');    // 粗利額
        $res->assertSee('25.0%');          // 粗利率（常に小数1桁）
    }

    /**
     * お客様所有土地の案件は土地 4 値を出さない。
     * 生カラムに値が残っていても isCompanyLand() が false なら全部「—」。
     * 建物側は通常どおり出る。
     */
    public function test_customer_land_order_hides_all_land_amounts(): void
    {
        $this->makeCustomerLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // 土地: 生カラムに入れた値が 1 つも出ない
        $res->assertDontSee('12,800,000円');
        $res->assertDontSee('9,600,000円');
        $res->assertDontSee('3,200,000円');
        // 建物: 出る
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('22.5%');
    }

    /**
     * 金額 null の案件で「税込 0円」が出ない。
     * getBuildingTax() は null 時 0 を返すので、ガードが無いと税込サブ行が 0円で出る。
     */
    public function test_null_amount_order_does_not_render_tax_included_row(): void
    {
        $this->makeEmptyAmountOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('税込 0円');
        // 税込サブ行の要素が 1 つも描画されていないことを見る。
        // ⚠ 裸のクラス名 'co-tax-sub' で探すと <style> ブロックの
        //   セレクタ定義に一致して必ず失敗する。開始タグの形で探すこと。
        // ⚠ assertDontSee('税込') も使えない — ヘッダーの
        //   <span class="co-subhead">税抜 / 税込</span> に一致する。
        $res->assertDontSee('<div class="co-tax-sub"', false);
    }

    /** 粗利が正なら緑（#047857）、負なら赤（#dc2626） */
    public function test_profit_color_is_green_when_positive(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertDontSee('color: #dc2626; font-weight: 700;', false);
    }

    /** 赤字案件は赤（#dc2626）で、粗利率も負の小数1桁 */
    public function test_profit_color_is_red_when_negative(): void
    {
        $this->makeNegativeProfitOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('-3,000,000円');
        $res->assertSee('-15.0%');
    }

    /**
     * 土地だけが赤字の案件で、土地側の赤字分岐が実際に描画される。
     *
     * 建物（黒字）と土地（赤字）で符号が逆なので、土地の 2 セルが
     * 建物の値を使い回していたらこのテストで落ちる。
     * ⚠ 期待値の数字は互いに部分文字列にならないものを選んである
     *   （例: 建物 15.0% / 土地 -20.0%。仮に建物を 20.0% にすると
     *    '-20.0%' の中に '20.0%' が含まれて false-pass する）。
     */
    public function test_land_side_negative_profit_renders_independently(): void
    {
        $this->makeNegativeLandProfitOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // 土地: 赤字
        $res->assertSee('-2,000,000円');
        $res->assertSee('-20.0%');
        // 建物: 黒字（同じ行で符号が逆であること）
        $res->assertSee('4,500,000円');
        $res->assertSee('15.0%');
        // 両方の色が同じページに出る
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('color: #047857; font-weight: 700;', false);
    }

    /** 案件名が詳細画面へのリンクになっている */
    public function test_order_name_links_to_show_page(): void
    {
        $order = $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // ⚠ href だけで assert してはいけない。同じ行の「詳細」ボタンが
        //   まったく同じ href を持つため、案件名リンクが剥がれても通ってしまう。
        //   href・class・案件名を 1 本の文字列にして同一要素であることを強制する。
        $res->assertSee(
            '<a href="' . route('housing.custom-orders.show', $order) . '" class="text-blue-700 underline">石井町A様邸 新築工事</a>',
            false
        );
    }

    /** 該当 0 件のとき colspan が 12 になっている */
    public function test_empty_state_spans_twelve_columns(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('colspan="12"', false);
        $res->assertSee('該当する案件がありません');
    }
}
