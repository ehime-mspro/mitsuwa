<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsContract;
use App\Models\HsProperty;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 建売物件一覧（/housing/properties）の金額列を検証する。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 *
 * ⚠ 各テストは自分がアサートする案件だけを作る（複数行混在で assertDontSee が false-fail するのを避ける）。
 * ⚠ 金額はカンマ入りの完全文字列で、構造は生 HTML（escape:false）で判定する。
 */
class PropertyIndexListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access:housing を無条件通過し、進捗ドロップダウンが出る manager 以上） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 契約なし・自社土地（分譲地区画）。坪数も検証できるよう面積を入れる。
     * 建物: 予定 28,500,000 / 原価 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 参考 12,800,000 / 原価  9,600,000 → 粗利 3,200,000（25.0%）
     * 合計: 販売 41,300,000 / 原価 30,900,000 / 粗利 10,400,000 ／税込 44,150,000
     * 坪数: 土地 165.50㎡ → 50.06坪 / 建物 105.20㎡ → 31.82坪
     */
    private function makeCompanyLandUnsold(): HsProperty
    {
        $lot = ReProjectLot::create([
            'project_id'    => 1,
            'lot_number'    => 1,
            'area_sqm'      => 165.29,
            'area_tsubo'    => 50.00,
            'selling_price' => 12800000,
            'status'        => 'unsold',
        ]);

        return HsProperty::create([
            'property_code'                 => 'HS-001',
            'property_name'                 => '石井町A号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            're_project_lot_id'             => $lot->id,
            'address'                       => '松山市石井町1-2-3',
            'land_area_sqm'                 => 165.50,
            'building_area_sqm'             => 105.20,
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);
    }

    /**
     * 契約あり・自社土地。契約価格が予定価格を上書きする。
     * 契約 建物 30,000,000 / 土地 13,000,000（予定 28,500,000 は使われない）
     */
    private function makeCompanyLandSold(): HsProperty
    {
        $prop = HsProperty::create([
            'property_code'                 => 'HS-002',
            'property_name'                 => '余戸B号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            'address'                       => '松山市余戸4-5-6',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);

        HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '山田 太郎',
            'selling_price_building' => 30000000,
            'selling_price_land'     => 13000000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);

        return $prop;
    }

    /**
     * お客様所有土地。土地原価に値があるが土地 4 セルは「—」でなければならない。
     * 建物: 予定 32,000,000 / 原価 24,800,000 → 粗利 7,200,000（22.5%）／税込 35,200,000
     */
    private function makeCustomerLand(): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => 'HS-003',
            'property_name'                 => '道後C邸',
            'status'                        => 'construction',
            'land_source_type'              => 'customer_land',
            'address'                       => '松山市道後7-8-9',
            'building_cost'                 => 24800000,
            'land_cost'                     => 9600000, // 表示されてはいけない値
            'target_selling_price_building' => 32000000,
            'created_by'                    => 1,
        ]);
    }

    /** 建物赤字。建物: 20,000,000 / 23,000,000 → 粗利 -3,000,000（-15.0%） */
    private function makeNegativeBuilding(): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => 'HS-004',
            'property_name'                 => '朝生田D邸',
            'status'                        => 'construction',
            'land_source_type'              => 'customer_land',
            'address'                       => '松山市朝生田1-1-1',
            'building_cost'                 => 23000000,
            'target_selling_price_building' => 20000000,
            'created_by'                    => 1,
        ]);
    }

    /**
     * 建物黒字・土地赤字（値の使い回し検出用）。
     * 建物: 30,000,000 / 25,500,000 → 粗利  4,500,000（ 15.0%）
     * 土地: 10,000,000 / 12,000,000 → 粗利 -2,000,000（-20.0%）
     */
    private function makeMixedSignProfit(): HsProperty
    {
        $lot = ReProjectLot::create([
            'project_id'    => 1,
            'lot_number'    => 2,
            'area_sqm'      => 132.23,
            'area_tsubo'    => 40.00,
            'selling_price' => 10000000,
            'status'        => 'unsold',
        ]);

        return HsProperty::create([
            'property_code'                 => 'HS-005',
            'property_name'                 => '北条E号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            're_project_lot_id'             => $lot->id,
            'address'                       => '松山市北条辻5-5-5',
            'building_cost'                 => 25500000,
            'land_cost'                     => 12000000,
            'target_selling_price_building' => 30000000,
            'created_by'                    => 1,
        ]);
    }

    /** 金額が 1 つも入っていない案件（建物販売 null） */
    private function makeEmptyAmount(): HsProperty
    {
        return HsProperty::create([
            'property_code' => 'HS-006',
            'property_name' => '未設定F邸',
            'status'        => 'design',
            'address'       => '松山市中央2-2-2',
            'created_by'    => 1,
        ]);
    }

    // ============================================================
    // ヘッダー / 構造
    // ============================================================

    /** 2 段ヘッダーのグループ見出し（合計 colspan=3 / 建物・土地 colspan=4） */
    public function test_group_headers_render_with_colspans(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // colspan と見出し文言を <th> ごと 1 本で見る。間は全角スペース（U+3000）。
        $res->assertSee('<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        // 物件名 / 進捗 / 詳細は 2 段ぶち抜き
        $res->assertSee('rowspan="2"', false);
    }

    /** 土地面積・建物面積の独立列ヘッダーが消えている（坪数サブ行に集約したため） */
    public function test_area_columns_are_removed(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertDontSee('>土地面積</th>', false);
        $res->assertDontSee('>建物面積</th>', false);
    }

    /** 進捗の Ajax ドロップダウンが維持されている（ステップバーに変えていない） */
    public function test_status_dropdown_is_preserved(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('x-data="housingPropertyStatusCell(', false);
    }

    // ============================================================
    // 金額表示
    // ============================================================

    /** 契約なし・自社土地で 合計/建物/土地 の金額が出る（予定価格・参考価格ベース） */
    public function test_unsold_company_land_shows_all_amounts(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // 合計
        $res->assertSee('41,300,000円');
        $res->assertSee('税込 44,150,000円');
        $res->assertSee('30,900,000円');
        $res->assertSee('10,400,000円');
        // 建物
        $res->assertSee('28,500,000円');
        $res->assertSee('税込 31,350,000円');
        $res->assertSee('21,300,000円');
        $res->assertSee('7,200,000円');
        $res->assertSee('25.3%');
        // 土地
        $res->assertSee('12,800,000円');
        $res->assertSee('9,600,000円');
        $res->assertSee('3,200,000円');
        $res->assertSee('25.0%');
    }

    /**
     * 契約ありは契約価格が使われ、予定価格は使われない。
     * ⚠ コントローラのデフォルトフィルタは status=non_sold（契約ありを除外）のため、
     *   契約済み物件を表示させるには ?status=all を明示する必要がある。
     */
    public function test_sold_uses_contract_price_not_target(): void
    {
        $this->makeCompanyLandSold();

        $res = $this->actingAs($this->executive())->get('/housing/properties?status=all');

        $res->assertOk();
        $res->assertSee('30,000,000円');   // 契約 建物
        $res->assertSee('13,000,000円');   // 契約 土地
        $res->assertSee('43,000,000円');   // 合計販売
        $res->assertDontSee('28,500,000円'); // 予定価格は使われない
    }

    /** お客様所有土地は土地 4 セルを出さない。建物側は出る（設計書 §3.5） */
    public function test_customer_land_hides_land_cells(): void
    {
        $this->makeCustomerLand();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // 土地原価に入れた値が土地列に出ない。合計は積み上げ式になり §3.6 の不整合が根絶されたため、
        // 以下で「合計＝表示している建物のみ」の整合も検証する（旧直呼び方式の値が出ないことを確認）。
        $res->assertDontSee('9,600,000円');
        // 合計は積み上げ式（表示内訳から算出）なので、お客様所有土地でも合計＝建物のみで整合する。
        // 旧・getTotalCost()/getGrossProfit() 直呼びなら合計原価 34,400,000 円・合計粗利 -2,400,000 円(赤) の
        // 不整合になっていたが、積み上げ化で根絶される（final review §3.6）。
        $res->assertDontSee('34,400,000円'); // 旧 getTotalCost()（建物原価+土地原価）は出ない
        $res->assertDontSee('-2,400,000円'); // 旧 getGrossProfit()（土地原価が効いた赤字合計）は出ない
        // 建物は出る
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('22.5%');
    }

    /** 建物販売 null の案件で「税込 0円」が出ない（設計書 §3.3） */
    public function test_null_building_price_hides_tax_row(): void
    {
        $this->makeEmptyAmount();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertDontSee('税込 0円');
        // 税込サブ行タグそのものが出ない。⚠ 裸の 'co-tax-sub' は <style> 定義に一致するので開始タグで見る
        $res->assertDontSee('<div class="co-tax-sub">', false);
    }

    // ============================================================
    // 色・符号
    // ============================================================

    /** 粗利が正なら緑（#047857）のみ、赤は出ない */
    public function test_positive_profit_is_green(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertDontSee('color: #dc2626; font-weight: 700;', false);
    }

    /** 建物赤字は赤（#dc2626）＋負の金額・率 */
    public function test_negative_building_is_red(): void
    {
        $this->makeNegativeBuilding();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('-3,000,000円');
        $res->assertSee('-15.0%');
    }

    /**
     * 建物黒字・土地赤字が同一行で独立に描画される（値の使い回しが無い）。
     * ⚠ 期待値は互いに部分文字列にならない（建物 15.0% / 土地 -20.0%）。
     */
    public function test_building_and_land_profit_render_independently(): void
    {
        $this->makeMixedSignProfit();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // 建物: 黒字
        $res->assertSee('4,500,000円');
        $res->assertSee('15.0%');
        // 土地: 赤字
        $res->assertSee('-2,000,000円');
        $res->assertSee('-20.0%');
        // 両色が同ページに出る
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
    }

    // ============================================================
    // 物件名リンク・坪数・空状態
    // ============================================================

    /** 物件名が詳細画面へのリンク（class・物件名まで含めて 1 本で判定＝詳細ボタンと区別） */
    public function test_property_name_links_to_show(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee(
            '<a href="' . route('housing.properties.show', $p) . '" class="text-blue-700 underline">石井町A号地</a>',
            false
        );
    }

    /** 物件名の下に坪数サブ行が出る */
    public function test_tsubo_subrow_is_shown(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('50.06坪');
        $res->assertSee('31.82坪');
    }

    /** 該当 0 件のとき colspan が 14 */
    public function test_empty_state_spans_fourteen_columns(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('colspan="14"', false);
        $res->assertSee('該当する物件がありません');
    }
}
