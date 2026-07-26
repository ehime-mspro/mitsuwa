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

    /** グループ見出し・列見出しに税の注記を出さない（見出しは名称だけ） */
    public function test_group_headers_have_no_tax_annotation(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // 「消費税 10%」「消費税 非課税」を撤去したので、ページ内に「消費税」は一切出ない。
        $res->assertDontSee('消費税', false);
        // 建物の販売金額列の「税抜 / 税込」サブ表記も撤去した。
        $res->assertDontSee('税抜 / 税込', false);
        // 見出し自体は残っていること。
        $res->assertSee('建　物', false);
        $res->assertSee('土　地', false);
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

    /**
     * 建物赤字・土地黒字で「合計も赤字」になる案件（モック行 4「重信D様邸」相当）。
     * 建物: 24,000,000 / 25,500,000 → 粗利 -1,500,000 ／税込 26,400,000
     * 土地: 10,000,000 /  9,200,000 → 粗利    800,000（8.0%）
     * 合計: 34,000,000 / 34,700,000 → 粗利   -700,000 ／税込 36,400,000（建物税 2,400,000）
     *
     * 合計が「建物のコピー」になっていたら -1,500,000 になるので、-700,000 で区別できる。
     */
    private function makeNegativeTotalOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0006',
            'order_name'              => '重信D様邸 新築工事',
            'status'                  => 'construction',
            'customer_name'           => '松本 五郎',
            'address'                 => '東温市田窪1122',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 24000000,
            'building_cost'           => 25500000,
            'land_selling_price'      => 10000000,
            'land_cost'               => 9200000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * 建物「原価」だけ未入力の案件（設計書 §3.4 の 1 ケース目・モック行 5 相当）。
     * 建物: 30,000,000 /     —     → 粗利 —（率 —）／税込 33,000,000
     * 土地: 13,000,000 / 9,800,000 → 粗利 3,200,000
     * 合計: 43,000,000 / 9,800,000 → 粗利 33,200,000（★過大）／税込 46,000,000
     *
     * 金額 4 カラムはすべて nullable（CustomOrderController::validateOrder）＝実際に保存できる状態。
     */
    private function makeBuildingCostMissingOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0007',
            'order_name'              => '久米H様邸 新築工事',
            'status'                  => 'estimation',
            'customer_name'           => '藤原 六郎',
            'address'                 => '松山市南久米町80',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 30000000,
            // building_cost は未入力（意図的に入れない）
            'land_selling_price'      => 13000000,
            'land_cost'               => 9800000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * 土地「販売金額」だけ未入力の案件（設計書 §3.4 の 2 ケース目・モック行 6 相当）。
     * 建物: 29,000,000 / 22,000,000 → 粗利 7,000,000（24.1%）／税込 31,900,000
     * 土地:     —      /  8,500,000 → 粗利 —（率 —）
     * 合計: 29,000,000 / 30,500,000 → 粗利 -1,500,000（★過小）／税込 31,900,000
     *
     * ⚠ land_source_type は project_lot（自社土地）。「お客様所有土地」ではなく
     *   「自社土地なのに土地販売額が未入力」というケースを作っている。
     */
    private function makeLandPriceMissingOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0008',
            'order_name'              => '新居浜G様邸 新築工事',
            'status'                  => 'estimation',
            'customer_name'           => '越智 七郎',
            'address'                 => '新居浜市中村松木2-8',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 29000000,
            'building_cost'           => 22000000,
            // land_selling_price は未入力（意図的に入れない）
            'land_cost'               => 8500000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    // ============================================================
    // ヘルパー — ゾーンアンカー付きアサート（code review I-1・2026-07-26 追加）
    // ============================================================
    //
    // ⚠ なぜページ全体を見る assertSee / substr_count では不十分か:
    //   assertSee は「値がページのどこかに存在するか」しか見ておらず、
    //   substr_count による出現回数チェックも「値が指定回数どこかにあるか」しか見ていない。
    //   どちらも「その値がどの <td>（どのゾーン）に描画されているか」を検証できないため、
    //   合計ゾーンと建物ゾーンの中身を丸ごと入れ替える変異でも旧アサートは green のまま
    //   通過してしまうことをコードレビューで実測確認した。
    //   <td class="... {zone}...">value という形の正規表現にして、値の中身とセルの
    //   所属ゾーンの対応まで固定する。

    /**
     * 指定ゾーン（例: 'co-zone-t'）の販売金額セルに、税抜金額＋税込サブ行が
     * 描画されていることを class 署名込みの正規表現で相関させる。
     * 販売金額セルは常にゾーン先頭列なので co-gstart が付く。
     */
    private function assertZonePrice(string $html, string $zone, string $price, string $taxIncluded): void
    {
        // ⚠ メッセージには生の値（$rawXxx）を使う。preg_quote() 後の変数をそのまま使うと
        //   ハイフンや # が \- や \# にエスケープされ、メッセージに余計なバックスラッシュが
        //   混入する（code review M-5・実測。$zone だけでなく負の金額 "-700,000" や
        //   色コード "#dc2626" も preg_quote でエスケープされるため同様に汚染される）。
        $rawZone = $zone;
        $rawPrice = $price;
        $rawTaxIncluded = $taxIncluded;
        $zone = preg_quote($zone, '/');
        $price = preg_quote($price, '/');
        $taxIncluded = preg_quote($taxIncluded, '/');

        $this->assertMatchesRegularExpression(
            '/' . $zone . ' co-gstart">\s*' . $price . '円\s*<div class="co-tax-sub">税込 ' . $taxIncluded . '円/',
            $html,
            "[{$rawZone}] 販売金額セルに {$rawPrice}円 + 税込サブ行 {$rawTaxIncluded}円 が無い"
        );
    }

    /**
     * 指定ゾーンの原価額・粗利額セルに値が描画されていることを相関させる。
     * ⚠ zone クラスの直後を閉じ引用符 `">` にすることで、co-gstart 付き（販売金額セル）や
     *   他ゾーンのセルと区別する（`co-zone-t co-gstart">` は `co-zone-t">` にマッチしない）。
     */
    private function assertZoneCostProfit(string $html, string $zone, string $cost, string $profit, string $profitColor): void
    {
        // ⚠ メッセージには生の値（$rawXxx）を使う（code review M-5。理由は assertZonePrice 参照）。
        $rawZone = $zone;
        $rawCost = $cost;
        $rawProfit = $profit;
        $rawProfitColor = $profitColor;
        $zone = preg_quote($zone, '/');
        $cost = preg_quote($cost, '/');
        $profit = preg_quote($profit, '/');
        $profitColor = preg_quote($profitColor, '/');

        $this->assertMatchesRegularExpression(
            '/' . $zone . '">\s*' . $cost . '円/', $html,
            "[{$rawZone}] 原価額セルに {$rawCost}円 が無い"
        );
        $this->assertMatchesRegularExpression(
            '/' . $zone . '">\s*<span style="color: ' . $profitColor . '; font-weight: 700;">' . $profit . '円/',
            $html,
            "[{$rawZone}] 粗利額セルに {$rawProfit}円（色 {$rawProfitColor}）が無い"
        );
    }

    /**
     * 指定ゾーンの 3 値（販売金額+税込・原価額・粗利額）すべてがそのゾーンにあることを固定する。
     * assertZonePrice + assertZoneCostProfit をまとめて呼ぶだけ（合計ゾーン用の通常ケース）。
     */
    private function assertZoneAmounts(
        string $html,
        string $zone,
        string $price,
        string $taxIncluded,
        string $cost,
        string $profit,
        string $profitColor
    ): void {
        $this->assertZonePrice($html, $zone, $price, $taxIncluded);
        $this->assertZoneCostProfit($html, $zone, $cost, $profit, $profitColor);
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

    /** 2 段ヘッダーのグループ見出し（合計 colspan=3 / 建物・土地 colspan=4） */
    public function test_group_headers_render_with_colspans(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // colspan と見出し文言を別々に見ると相関が取れないので <th> ごと 1 本で見る。
        // 「合　計」「建　物」「土　地」の間は全角スペース（U+3000）。
        $res->assertSee('<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        // 「進捗 / 案件名 / 詳細」は 2 段ぶち抜き
        $res->assertSee('rowspan="2"', false);
        // 個別の assertSee だけだとゾーンの並び順（決定 #3）が固定できない
        // （3 つとも存在すればどの順でも通ってしまう）。並び順も 1 本で固定する（code review M-4）。
        $res->assertSeeInOrder(['合　計', '建　物', '土　地'], false);
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
        // ⚠ co-name-link は 230px 固定幅での省略（…）用に足したクラス（設計書 §3.6）。
        $res->assertSee(
            '<a href="' . route('housing.custom-orders.show', $order) . '" class="text-blue-700 underline co-name-link">石井町A様邸 新築工事</a>',
            false
        );
    }

    /** 該当 0 件のとき colspan が 14 になっている（合計 3 列を足したので 11 → 14） */
    public function test_empty_state_spans_fourteen_columns(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('colspan="14"', false);
        $res->assertSee('該当する案件がありません');
    }

    /**
     * 顧客名の「列」が消えている（案件名で識別できるため一覧からは外す）。
     *
     * 顧客名は tbody のセルにしか出ていなかったので、列を消せば値ごと消える。
     * ⚠ assertDontSee('顧客名') は使えない — 検索窓のプレースホルダ
     *   「案件番号・案件名・顧客名・住所」に一致して必ず失敗する。
     *   列ヘッダーは <th> の形で、値は具体的な氏名で判定する。
     */
    public function test_customer_name_column_is_removed(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('>顧客名</th>', false);
        $res->assertDontSee('山田 太郎');   // 顧客名の値そのものが本文に出ない
        // 検索窓のプレースホルダは維持する（表示列を外すだけで検索対象からは外さない）
        $res->assertSee('案件番号・案件名・顧客名・住所', false);
    }

    // ============================================================
    // 合計ゾーン（2026-07-26 追加）
    // ============================================================

    /**
     * 自社土地の案件で合計 3 値と税込サブ行が出る。
     * 建物 28,500,000 / 21,300,000 ＋ 土地 12,800,000 / 9,600,000
     *   → 合計 41,300,000 / 30,900,000 / 10,400,000、税込 44,150,000（建物税 2,850,000 のみ）
     * 期待値は建物・土地のどの値の部分文字列にもならない。
     */
    public function test_company_land_order_shows_total_amounts(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $this->assertZoneAmounts(
            html: $res->getContent(),
            zone: 'co-zone-t',
            price: '41,300,000',
            taxIncluded: '44,150,000',   // 合計 税込サブ行（土地は非課税なので建物ぶんの税だけ）
            cost: '30,900,000',
            profit: '10,400,000',
            profitColor: '#047857'
        );
    }

    /**
     * お客様所有土地は「合計＝建物のみ」で成立する。
     * land_selling_price 12,800,000 / land_cost 9,600,000 が入っていても合算しない。
     *
     * ⚠ 合計 3 値は建物 3 値と同額になる。旧実装は出現回数（合計セル + 建物セル = 2）
     *   の substr_count で区別していたが、これだと「合計セルと建物セルの中身を
     *   丸ごと入れ替える」変異でも回数は変わらないため green のまま通過する
     *   （code review I-1・実測）。co-zone-t と co-zone-b の両方に独立して値が
     *   あることをゾーンアンカーで固定する。
     */
    public function test_customer_land_order_total_is_building_only(): void
    {
        $this->makeCustomerLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // 土地の生カラム値は合計にも土地列にも出ない
        $res->assertDontSee('12,800,000円');
        $res->assertDontSee('9,600,000円');

        $html = $res->getContent();
        $this->assertZoneAmounts(
            html: $html,
            zone: 'co-zone-t',
            price: '32,000,000',
            taxIncluded: '35,200,000',
            cost: '24,800,000',
            profit: '7,200,000',
            profitColor: '#047857'
        );
        $this->assertZoneAmounts(
            html: $html,
            zone: 'co-zone-b',
            price: '32,000,000',
            taxIncluded: '35,200,000',
            cost: '24,800,000',
            profit: '7,200,000',
            profitColor: '#047857'
        );
        // 土地 4 セルだけが「—」（合計 3 セル・建物 4 セルは値あり）
        $this->assertSame(4, substr_count($html, '<span class="co-muted">—</span>'));
    }

    /**
     * 合計粗利が負なら赤（#dc2626）。建物赤字・土地黒字で合計も赤字になる案件。
     * 合計 34,000,000 / 34,700,000 → -700,000（建物 -1,500,000 のコピーではない）
     */
    public function test_negative_total_profit_is_red(): void
    {
        $this->makeNegativeTotalOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $this->assertZoneAmounts(
            html: $res->getContent(),
            zone: 'co-zone-t',
            price: '34,000,000',
            taxIncluded: '36,400,000',
            cost: '34,700,000',
            profit: '-700,000',
            profitColor: '#dc2626'
        );
        // 建物（赤字）と土地（黒字）が独立に出ている＝合計は建物のコピーではない
        $res->assertSee('-1,500,000円');
        $res->assertSee('800,000円');
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('color: #047857; font-weight: 700;', false);
    }

    /**
     * 金額が 1 つも入っていない案件は合計 3 セルも「—」で、税込サブ行を出さない。
     * getBuildingTax() は null 時 0 を返すので、ガードが無いと合計に「税込 0円」が出る。
     *
     * 「—」の総数 11（合計 3 + 建物 4 + 土地 4）で全ゾーンが空であることを固定する。
     */
    public function test_empty_amount_order_shows_muted_total_cells(): void
    {
        $this->makeEmptyAmountOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('税込 0円');
        // ⚠ 裸のクラス名 'co-tax-sub' は <style> のセレクタ定義に一致するので開始タグで探す
        $res->assertDontSee('<div class="co-tax-sub"', false);
        $this->assertSame(11, substr_count($res->getContent(), '<span class="co-muted">—</span>'));
    }

    /**
     * 【決定 #5・先行 2 画面と同一挙動】建物「原価」だけ未入力のとき、
     * 合計原価が土地ぶんだけになり合計粗利が過大に出ることを**仕様として固定する**。
     *
     * ⚠ これはバグではない。積み上げ式 ($b !== null || $l !== null) ? ($b ?? 0) + ($l ?? 0) : null は
     *   建売物件一覧・契約管理一覧と 1 文字も同じものを使う、という決定（設計書 §2.1 / §3.4）。
     *   3 画面で挙動を揃えることが優先事項。**「合計がおかしい」と判断して直さないこと。**
     *   仕様を変える場合はこのテストと設計書 §2.1、先行 2 画面を必ず同時に直す。
     *
     * 建物 30,000,000 / —（粗利 —）＋ 土地 13,000,000 / 9,800,000
     *   → 合計販売 43,000,000 / 合計原価 9,800,000 / 合計粗利 33,200,000（過大・緑）
     */
    public function test_building_cost_only_missing_inflates_total_profit(): void
    {
        $this->makeBuildingCostMissingOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $html = $res->getContent();
        $this->assertZoneAmounts(
            html: $html,
            zone: 'co-zone-t',
            price: '43,000,000',        // 合計販売 = 30,000,000 + 13,000,000
            taxIncluded: '46,000,000',  // 43,000,000 + 建物税 3,000,000
            cost: '9,800,000',          // 合計原価 = 土地ぶんだけ（建物原価は 0 円扱い）
            profit: '33,200,000',       // 合計粗利（★過大。建物粗利は「—」なのに緑で出る）
            profitColor: '#047857'
        );
        // 建物 3 セル（原価・粗利・粗利率）だけが「—」＝合計と土地は値あり
        $this->assertSame(3, substr_count($html, '<span class="co-muted">—</span>'));
    }

    /**
     * 【決定 #5・先行 2 画面と同一挙動】土地「販売金額」だけ未入力のとき、
     * 合計販売が建物ぶんだけになり合計粗利が過小に出ることを**仕様として固定する**。
     *
     * ⚠ これはバグではない。理由・注意点は test_building_cost_only_missing_inflates_total_profit と同じ
     *   （設計書 §2.1 / §3.4）。**「合計がおかしい」と判断して直さないこと。**
     *
     * 建物 29,000,000 / 22,000,000（粗利 +7,000,000・緑）＋ 土地 —（販売未入力）/ 8,500,000
     *   → 合計販売 29,000,000 / 合計原価 30,500,000 / 合計粗利 -1,500,000（過小・赤）
     *   同じ行に「建物 緑」と「合計 赤」が並ぶ。
     */
    public function test_land_price_only_missing_deflates_total_profit(): void
    {
        $this->makeLandPriceMissingOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $html = $res->getContent();
        $this->assertZoneAmounts(
            html: $html,
            zone: 'co-zone-t',
            price: '29,000,000',
            taxIncluded: '31,900,000',
            cost: '30,500,000',   // 合計原価 = 22,000,000 + 8,500,000
            profit: '-1,500,000', // 合計粗利（★過小・赤）
            profitColor: '#dc2626'
        );
        // 合計販売 29,000,000 / 税込 31,900,000 は建物販売と同額＝土地ぶんは 0 円で合算されている。
        // ⚠ 建物の原価・粗利は合計と異なる（22,000,000 / +7,000,000）ので、この行は
        //   販売金額+税込サブ行だけを co-zone-b で独立検証する（code review I-1）。
        $this->assertZonePrice($html, 'co-zone-b', '29,000,000', '31,900,000');
        $res->assertSee('7,000,000円');         // 建物粗利（緑）— 同じ行で符号が逆
        $res->assertSee('24.1%');               // 建物粗利率
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('color: #047857; font-weight: 700;', false);
        // 土地 3 セル（販売・粗利・粗利率）だけが「—」
        $this->assertSame(3, substr_count($html, '<span class="co-muted">—</span>'));
    }

    /**
     * 左 2 列（進捗 → 案件名）が横スクロール時に固定される。
     *
     * ⚠ 境界の影は「右端の固定列」に付ける。本画面の列順は 進捗 → 案件名 なので
     *   右端は**案件名**（.co-sticky-name）。先行 2 画面（建売物件一覧・契約管理）は
     *   右端が進捗 / 進行状況なので .co-sticky-stat に付いている。
     *   そちらからコピペすると影が表の途中に出る（設計書 §3.2 / 罠 #1）。
     */
    public function test_left_two_columns_are_sticky(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $html = $res->getContent();
        // ヘッダー（進捗 → 案件名 の順）
        $res->assertSee('class="co-th co-sticky co-sticky-stat co-col-stat"', false);
        $res->assertSee('class="co-th co-th-name co-sticky co-sticky-name co-col-name"', false);
        // ボディ
        $res->assertSee('class="co-td co-sticky co-sticky-stat co-col-stat"', false);
        $res->assertSee('class="co-td co-td-name co-sticky co-sticky-name co-col-name"', false);
        // 案件名の left（96px）は進捗列の width と一致していること。
        // ⚠ セレクタと値を別々の assertSee で見ると、.co-sticky-stat と .co-sticky-name の
        //   left 値を入れ替える変異を検出できない（code review I-2・実測）。
        //   セレクタ・プロパティ・値を 1 本の正規表現にして相関させる。
        // ⚠ 閉じ `}` までは固定しない（code review M-6） — 宣言を 1 つ足しただけで
        //   落ちるのは守るべき不変条件ではない。`[^}]*` にして規則内なら順不同・追加可にする。
        $this->assertMatchesRegularExpression('/\.co-sticky-stat\s*\{[^}]*left:\s*0;/', $html);
        $this->assertMatchesRegularExpression('/\.co-sticky-name\s*\{[^}]*left:\s*96px;/', $html);
        // 境界の影は右端の固定列＝案件名に付く（進捗ではない）
        $res->assertSee('td.co-sticky-name, th.co-sticky-name', false);
        // 影の規則が進捗側（co-sticky-stat）にコピペ残骸として残っていないこと（罠 #1）
        $res->assertDontSee('td.co-sticky-stat, th.co-sticky-stat', false);
        // 住所サブ行の省略クラス（230px を超える住所が隣列へはみ出さない）
        $res->assertSee('class="text-xs text-gray-500 co-name-sub"', false);
    }

    /** 合計ゾーンはレッド配色（決定 #7・契約管理と同じ。建売物件一覧のグレーは採らない） */
    public function test_total_zone_is_red(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $html = $res->getContent();
        // ⚠ セレクタと値を別々の assertSee で見ると、.co-grp-t と .co-grp-b の配色を
        //   入れ替える変異を検出できない（code review I-2・実測）。
        //   セレクタと値を 1 本の正規表現にして相関させる。
        // ⚠ 閉じ `}` までは固定しない（code review M-6） — 宣言を 1 つ足しただけで
        //   落ちるのは守るべき不変条件ではない。`[^}]*` にして規則内なら順不同・追加可にする。
        $this->assertMatchesRegularExpression('/\.co-grp-t\s*\{[^}]*background:\s*#fee2e2;\s*color:\s*#991b1b;/', $html); // 合計 見出し
        $this->assertMatchesRegularExpression('/td\.co-zone-t\s*\{[^}]*background:\s*#fef2f2;/', $html);                 // 合計 地色
        // td の背景は tr の背景を上書きするため、行ホバーの上書き規則が必須（罠 #3）
        $res->assertSee('tbody tr:hover td.co-zone-t { background: #fee2e2; }', false);
        // 建売物件一覧のグレー（#eef2f6）を持ち込んでいない
        $res->assertDontSee('background: #eef2f6;', false);
    }
}
