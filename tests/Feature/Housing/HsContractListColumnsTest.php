<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約管理一覧（/housing/contracts）を建売物件一覧の 3 ゾーン様式へ刷新した後の
 * 列・2 段ヘッダー・固定 3 列・税込サブ行・粗利色・顧客所有地の「—」を検証する。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 *
 * ⚠ 一覧の既定フィルタは fiscal_year=当年度。年度取りこぼしを避けるため全リクエストに
 *   ?fiscal_year=all を付ける。注文住宅は status IN(contracted..) かつ contract_date 必須。
 * ⚠ 各テストは自分がアサートする 1 案件だけを作る。金額はカンマ入り完全文字列、構造は生 HTML(false)で判定。
 */
class HsContractListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 住宅事業へ無条件アクセスできる経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    // ⚠ 基底 TestCase の public get() を private で上書きするとロード時に致命的エラーになるため
    //   一覧取得ヘルパーは visitIndex() に改名（プラン Step 1 の get() 命名を是正）。内部の ->get() は基底の HTTP メソッド。
    private function visitIndex(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())->get('/housing/contracts?fiscal_year=all' . $query);
    }

    // ---- フィクスチャ（各値は設計書 §8 のケースに対応）----

    /**
     * 建売契約（自社土地・全ゾーン正）。
     * 建物: 販売 28,500,000 / 原価 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 販売 12,800,000 / 原価  9,600,000 → 粗利 3,200,000（25.0%）
     * 合計: 販売 41,300,000 / 原価 30,900,000 / 粗利 10,400,000 ／税込 44,150,000（建物税 2,850,000）
     */
    private function makeTateuriContract(): HsContract
    {
        $prop = HsProperty::create([
            'property_code'    => 'HS-101',
            'property_name'    => '契約用A号地',
            'status'           => 'construction',
            'land_source_type' => 'project_lot',
            'address'          => '松山市石井町1-2-3',
            'building_cost'    => 21300000,
            'land_cost'        => 9600000,
            'created_by'       => 1,
        ]);

        return HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '契約 太郎',
            'selling_price_building' => 28500000,
            'selling_price_land'     => 12800000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);
    }

    /**
     * 注文住宅契約（自社土地・全ゾーン正）。
     * 建物: 32,000,000 / 24,800,000 → 7,200,000（22.5%）／税込 35,200,000（建物税 3,200,000）
     * 土地: 15,000,000 / 11,000,000 → 4,000,000（26.7%）
     * 合計: 47,000,000 / 35,800,000 / 11,200,000 ／税込 50,200,000
     */
    private function makeCustomCompanyLand(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-101',
            'order_name'              => '注文契約B邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 花子',
            'land_source_type'        => 'project_lot',
            'address'                 => '松山市余戸4-5-6',
            'building_contract_price' => 32000000,
            'building_cost'           => 24800000,
            'land_selling_price'      => 15000000,
            'land_cost'               => 11000000,
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-02',
            'created_by'              => 1,
        ]);
    }

    /**
     * 注文住宅契約（顧客所有地）。land_cost 9,600,000 は入っているが土地 4 セルは「—」。
     * 建物: 32,000,000 / 24,800,000 → 7,200,000（22.5%）／税込 35,200,000
     * 合計＝建物のみ（販売 32,000,000 / 税込 35,200,000）。
     */
    private function makeCustomCustomerLand(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-102',
            'order_name'              => '注文契約C邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 次郎',
            'land_source_type'        => 'customer_land',
            'address'                 => '松山市道後7-8-9',
            'building_contract_price' => 32000000,
            'building_cost'           => 24800000,
            'land_cost'               => 9600000, // 表示されてはいけない
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-03',
            'created_by'              => 1,
        ]);
    }

    /** 建物赤字（顧客所有地）。20,000,000 / 23,000,000 → -3,000,000（-15.0%） */
    private function makeNegativeBuilding(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-103',
            'order_name'              => '注文契約D赤字邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 三郎',
            'land_source_type'        => 'customer_land',
            'address'                 => '松山市朝生田1-1-1',
            'building_contract_price' => 20000000,
            'building_cost'           => 23000000,
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-04',
            'created_by'              => 1,
        ]);
    }

    /**
     * 建物黒字・土地赤字（値の使い回し検出）。
     * 建物: 30,000,000 / 25,500,000 →  4,500,000（ 15.0%）
     * 土地: 10,000,000 / 12,000,000 → -2,000,000（-20.0%）
     */
    private function makeMixedSign(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-104',
            'order_name'              => '注文契約E混在邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 四郎',
            'land_source_type'        => 'project_lot',
            'address'                 => '松山市北条辻5-5-5',
            'building_contract_price' => 30000000,
            'building_cost'           => 25500000,
            'land_selling_price'      => 10000000,
            'land_cost'               => 12000000,
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-05',
            'created_by'              => 1,
        ]);
    }

    // ============================================================
    // 2 段ヘッダー / 固定列 / 空状態
    // ============================================================

    /** 2 段ヘッダーのグループ見出し（合計 colspan=3 / 建物・土地 colspan=4、間は全角スペース U+3000） */
    public function test_group_headers_render_with_colspans(): void
    {
        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        $res->assertSee('rowspan="2"', false);
    }

    /** 契約固有の列（契約日・顧客・担当・進行状況）が保持されている（設計書 §2） */
    public function test_contract_specific_columns_are_retained(): void
    {
        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('>契約日</th>', false);
        $res->assertSee('>顧客</th>', false);
        $res->assertSee('>担当</th>', false);
        $res->assertSee('>進行状況</th>', false);
    }

    /** 左 3 列（物件名・種別・進行状況）が横スクロール時に固定される（ヘッダー・ボディ両方） */
    public function test_three_left_columns_are_sticky(): void
    {
        $this->makeTateuriContract();

        $res = $this->visitIndex();

        $res->assertOk();
        // ヘッダー
        $res->assertSee('class="co-th co-th-name co-sticky co-sticky-name co-col-name"', false);
        $res->assertSee('class="co-th co-sticky co-sticky-type co-col-type"', false);
        $res->assertSee('class="co-th co-sticky co-sticky-stat co-col-stat"', false);
        // ボディ
        $res->assertSee('class="co-td co-td-name co-sticky co-sticky-name co-col-name"', false);
        $res->assertSee('class="co-td co-sticky co-sticky-type co-col-type"', false);
        $res->assertSee('class="co-td co-sticky co-sticky-stat co-col-stat"', false);
    }

    /** 合計ゾーンがレッド配色（決定 #9） */
    public function test_total_zone_is_red(): void
    {
        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('background: #fee2e2; color: #991b1b;', false); // 合計見出し
        $res->assertSee('background: #fef2f2;', false);                 // 合計地色
    }

    /** 該当 0 件のとき colspan が 18 */
    public function test_empty_state_spans_eighteen_columns(): void
    {
        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('colspan="18"', false);
        $res->assertSee('契約データがありません。');
    }

    /** 進行状況は読み取り専用の静的バッジ（建売一覧のような Ajax セルにしない） */
    public function test_status_is_readonly_no_ajax(): void
    {
        $this->makeTateuriContract();

        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertDontSee('housingPropertyStatusCell', false);
    }

    /** 種別バッジ・進行状況バッジが描画される */
    public function test_type_and_status_badges_render(): void
    {
        $this->makeTateuriContract();

        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('background: #DBEAFE; color: #1E40AF;', false); // 種別＝建売
        $res->assertSee('background: #D1FAE5; color: #065F46;', false); // 進行状況＝契約済
        $res->assertSee('契約済');
    }

    /** 物件名が詳細画面へのリンク（建売一覧に準拠した青リンク＋案件名まで含めて 1 本で判定） */
    public function test_property_name_links_to_detail(): void
    {
        $c = $this->makeTateuriContract();

        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee(
            '<a href="' . route('housing.contracts.show-building', $c) . '" class="text-blue-700 underline co-name-link">契約用A号地</a>',
            false
        );
    }

    // ============================================================
    // 金額（3 ゾーン）
    // ============================================================

    /** 建売契約: 合計 / 建物 / 土地 の全ゾーンに値・税込サブ行・粗利率 */
    public function test_tateuri_shows_all_zone_amounts(): void
    {
        $this->makeTateuriContract();

        $res = $this->visitIndex();

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

    /** 注文住宅・自社土地: 全ゾーンに値 */
    public function test_custom_company_land_shows_all_zone_amounts(): void
    {
        $this->makeCustomCompanyLand();

        $res = $this->visitIndex();

        $res->assertOk();
        // 合計
        $res->assertSee('47,000,000円');
        $res->assertSee('税込 50,200,000円');
        $res->assertSee('35,800,000円');
        $res->assertSee('11,200,000円');
        // 建物
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('7,200,000円');
        $res->assertSee('22.5%');
        // 土地
        $res->assertSee('15,000,000円');
        $res->assertSee('11,000,000円');
        $res->assertSee('4,000,000円');
        $res->assertSee('26.7%');
    }

    /** 注文住宅・顧客所有地: 土地 4 セルは出さない（land_cost が漏れない）。合計＝建物のみで整合 */
    public function test_custom_customer_land_hides_land_cells(): void
    {
        $this->makeCustomCustomerLand();

        $res = $this->visitIndex();

        $res->assertOk();
        // 土地原価に入れた 9,600,000 は土地列に出てはいけない
        $res->assertDontSee('9,600,000円');
        // 建物は出る（合計＝建物と同一文字列で整合）
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('22.5%');
    }

    /** 土地は非課税＝税込サブ行なし（税込は合計・建物のみ） */
    public function test_land_zone_has_no_tax_subrow(): void
    {
        $this->makeCustomCompanyLand();

        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertDontSee('税込 15,000,000'); // 土地売価に税込は付かない
        $res->assertDontSee('税込 16,500,000'); // 15,000,000×1.10（誤って土地課税したら出る値）
    }

    /** 建物赤字は赤（#dc2626）＋負の金額・率 */
    public function test_negative_building_is_red(): void
    {
        $this->makeNegativeBuilding();

        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('-3,000,000円');
        $res->assertSee('-15.0%');
    }

    /** 建物黒字・土地赤字が同一行で独立に描画される（値の使い回しが無い） */
    public function test_building_and_land_profit_render_independently(): void
    {
        $this->makeMixedSign();

        $res = $this->visitIndex();

        $res->assertOk();
        $res->assertSee('4,500,000円');   // 建物黒字
        $res->assertSee('15.0%');
        $res->assertSee('-2,000,000円');  // 土地赤字
        $res->assertSee('-20.0%');
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
    }

    // ============================================================
    // 回帰ガード（データ依存の本番 500）
    // ============================================================

    /**
     * 回帰: 建売0件＋注文住宅ありの期間で一覧が 500 にならない。
     *
     * WHY: 建売契約が 0 件だと $tateuriItems は空マップの Eloquent\Collection のままになり、
     * 配列 DTO を merge すると EloquentCollection::merge() が各要素に getKey() を呼び 500 になる。
     * index() の ->toBase() 正規化が無いと再発するため、明示的にピン留めする
     * （データ依存で空ローカルでは素通りする本番 500。docs/RULES.md Bug #22/#25/#26 と同型）。
     */
    public function test_custom_order_only_period_does_not_500(): void
    {
        // 注文住宅契約のみを作成し、HsContract（建売）は 1 件も作らない
        $this->makeCustomCompanyLand();

        $res = $this->visitIndex();

        $res->assertOk();               // 200 であること（500 でない）が主眼
        $res->assertSee('注文契約B邸'); // 行が実際に描画されることも確認
    }
}
