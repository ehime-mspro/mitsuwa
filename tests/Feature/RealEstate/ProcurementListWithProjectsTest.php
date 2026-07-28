<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Services\RealEstate\ProcurementListRow;
use App\Services\RealEstate\ProcurementListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件一覧（分譲地統合版）の検証。
 *
 * re_* / hs_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementListWithProjectsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** ⚠ 一覧が描画するのは procurement_code ではなく property_name（既存テストの実測メモ） */
    private function makeProcurement(string $code, array $attrs = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code'   => $code,
            'property_type'      => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'   => RealEstateTransactionType::Purchase->value,
            'status'             => 'selling',
            'property_name'      => "物件{$code}",
            'address'            => '愛媛県松山市1-1-1',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => 1,
        ], $attrs));
    }

    /** 既定では assessment/purchase price を入れない（ReProject の saved フックを no-op に保つ） */
    private function makeProject(string $code, array $attrs = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code'       => $code,
            'project_name'       => "分譲地{$code}",
            'status'             => 'selling',
            'address'            => '愛媛県松山市2-2-2',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => 1,
        ], $attrs));
    }

    private function makeLot(ReProject $project, int $lotNumber, string $status = 'on_sale'): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => $status,
        ]);
    }

    // ================================================================
    // Task 1: DTO
    // ================================================================

    public function test_row_from_procurement_maps_fields(): void
    {
        $p = $this->makeProcurement('PRC-001', [
            'purchase_price'       => 30000000,
            'target_selling_price' => 40000000,
            'latitude'             => 33.8416,
            'longitude'            => 132.7657,
        ]);

        $row = ProcurementListRow::fromProcurement($p->fresh()->load('costs'));

        $this->assertSame('procurement', $row->kind);
        $this->assertSame($p->id, $row->id);
        $this->assertSame('物件PRC-001', $row->name);
        $this->assertSame(ProcurementStatus::Selling, $row->status);
        $this->assertSame('中古戸建', $row->propertyTypeLabel);
        $this->assertSame('自社買取', $row->transactionTypeLabel);
        $this->assertSame(30000000, $row->purchasePrice);
        $this->assertSame(40000000, $row->targetSellingPrice);
        // purchase_price を入れたので syncPropertyPurchaseCost() が原価 1 行を作る
        // → 粗利 = 40,000,000 − 30,000,000
        $this->assertSame(10000000, $row->expectedProfit);
        $this->assertEqualsWithDelta(33.8416, $row->latitude, 0.0001);
        $this->assertNull($row->soldLotCount);
        $this->assertNull($row->lotCount);
        $this->assertNull($row->lotsUrl);
        $this->assertStringContainsString("/realestate/procurements/{$p->id}", $row->showUrl);
    }

    public function test_row_from_project_maps_lot_counts(): void
    {
        $pj = $this->makeProject('PJ-001', ['target_selling_price' => 50000000]);
        $this->makeLot($pj, 1, 'sold');
        $this->makeLot($pj, 2, 'sold');
        $this->makeLot($pj, 3, 'on_sale');

        $row = ProcurementListRow::fromProject($pj->fresh()->load('costs', 'lots'));

        $this->assertSame('project', $row->kind);
        $this->assertSame('分譲地PJ-001', $row->name);
        $this->assertSame(ProjectStatus::Selling, $row->status);
        $this->assertSame('分譲地', $row->propertyTypeLabel);
        $this->assertNull($row->transactionTypeLabel);
        $this->assertSame(2, $row->soldLotCount);
        $this->assertSame(3, $row->lotCount);
        $this->assertStringContainsString("/realestate/projects/{$pj->id}", $row->showUrl);
        $this->assertStringContainsString("/realestate/projects/{$pj->id}/lots", $row->lotsUrl);
    }

    /** 区画 0 件でも「区画 0 / 0」と区画ボタンを出すため、null ではなく 0 と URL を返す */
    public function test_row_from_project_with_zero_lots(): void
    {
        $pj = $this->makeProject('PJ-002');

        $row = ProcurementListRow::fromProject($pj->fresh()->load('costs', 'lots'));

        $this->assertSame(0, $row->soldLotCount);
        $this->assertSame(0, $row->lotCount);
        $this->assertNotNull($row->lotsUrl);
    }

    // ================================================================
    // Task 2: サービス（マージ・ソート・ページング・ステータスフィルタ）
    // ================================================================

    /**
     * サービスを直接叩く。
     *
     * ⚠ Paginator::resolveCurrentPage() / resolveCurrentPath() は
     *   コンテナの 'request' を見る（PaginationServiceProvider がそう束ねている）ので、
     *   作った Request をコンテナへも差し込む。
     *
     * ⚠ **このヘルパーは実 HTTP と等価ではない。** `ConvertEmptyStringsToNull` は
     *   HTTP ミドルウェアなので `Request::create()` には効かず、同じ `?status=` が
     *   ここでは **''**、実 HTTP では **null** で届く。型ガードの置き方次第で
     *   この 2 つは別経路に落ちる（実際に 0 件になる事故を起こした。RULES.md Bug #31）。
     *   null 側は `paginateViaNullStatus()` で明示的に固定し、最終的な担保は
     *   ミドルウェアを通る HTTP テスト（`$this->get('/realestate/procurements?status=')`）で取る。
     */
    private function paginateVia(string $queryString = ''): LengthAwarePaginator
    {
        $uri     = '/realestate/procurements' . ($queryString !== '' ? '?' . $queryString : '');
        $request = Request::create($uri, 'GET');
        $this->app->instance('request', $request);

        return app(ProcurementListService::class)->paginate($request);
    }

    /**
     * 実 HTTP の `?status=`（ConvertEmptyStringsToNull 通過後）と同じ **null** を注入して叩く。
     *
     * `Request::create()` のクエリ文字列では null を作れないため、query バッグへ直接入れる
     * （実測: この方法なら `$request->input('status', 'active')` が null を返す）。
     */
    private function paginateViaNullStatus(): LengthAwarePaginator
    {
        $request = Request::create('/realestate/procurements', 'GET');
        $request->query->set('status', null);
        $this->app->instance('request', $request);

        return app(ProcurementListService::class)->paginate($request);
    }

    /** @return array<int, string> */
    private function namesOf(LengthAwarePaginator $rows): array
    {
        return collect($rows->items())->pluck('name')->all();
    }

    /**
     * 空ケース1: 仕入れ案件 0 件・分譲地のみ。
     * ⚠ Bug #27 回帰。空の Eloquent\Collection に配列要素のコレクションを merge すると
     *   getKey() が呼ばれて 500 になる。keysFrom() の ->toBase() が効いていることの検証。
     */
    public function test_projects_only_does_not_error(): void
    {
        $this->makeProject('PJ-001');

        $rows = $this->paginateVia();

        $this->assertSame(1, $rows->total());
        $this->assertSame('project', $rows->items()[0]->kind);
    }

    /** 空ケース2: 分譲地 0 件・仕入れ案件のみ（Bug #27 回帰） */
    public function test_procurements_only_does_not_error(): void
    {
        $this->makeProcurement('PRC-001');

        $rows = $this->paginateVia();

        $this->assertSame(1, $rows->total());
        $this->assertSame('procurement', $rows->items()[0]->kind);
    }

    /** 空ケース3: 両方 0 件 */
    public function test_both_empty_does_not_error(): void
    {
        $rows = $this->paginateVia();

        $this->assertSame(0, $rows->total());
        $this->assertCount(0, $rows->items());
    }

    /** 情報入手日の降順で両種別が 1 本に混ざる（NULL は末尾） */
    public function test_sorted_by_info_obtained_date_desc_with_nulls_last(): void
    {
        $this->makeProcurement('PRC-OLD', ['info_obtained_date' => '2026-01-01']);
        $this->makeProject('PJ-NEW',      ['info_obtained_date' => '2026-07-01']);
        $this->makeProcurement('PRC-MID', ['info_obtained_date' => '2026-04-01']);
        $this->makeProject('PJ-NULL',     ['info_obtained_date' => null]);

        $this->assertSame(
            ['分譲地PJ-NEW', '物件PRC-MID', '物件PRC-OLD', '分譲地PJ-NULL'],
            $this->namesOf($this->paginateVia())
        );
    }

    /** 既定（進行中のみ）は 仕入れ sold/lost と 分譲地 sold_out/lost を除外する */
    public function test_default_active_excludes_closed_of_both_types(): void
    {
        $this->makeProcurement('PRC-OK',   ['status' => 'selling']);
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProcurement('PRC-LOST', ['status' => 'lost']);
        $this->makeProject('PJ-OK',        ['status' => 'selling']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);
        $this->makeProject('PJ-LOST',      ['status' => 'lost']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-OK', '分譲地PJ-OK'],
            $this->namesOf($this->paginateVia())
        );
    }

    /** ?status=sold は 仕入れ sold と 分譲地 sold_out の両方にヒットする */
    public function test_status_sold_matches_both_sold_and_sold_out(): void
    {
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);
        $this->makeProcurement('PRC-SELL', ['status' => 'selling']);
        $this->makeProject('PJ-SELL',      ['status' => 'selling']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-SOLD', '分譲地PJ-SOLDOUT'],
            $this->namesOf($this->paginateVia('status=sold'))
        );
    }

    /** ?status=site_survey は分譲地に該当が無いので分譲地は消える */
    public function test_status_site_survey_excludes_projects(): void
    {
        $this->makeProcurement('PRC-SURVEY', ['status' => 'site_survey']);
        $this->makeProject('PJ-001',         ['status' => 'selling']);

        $this->assertSame(
            ['物件PRC-SURVEY'],
            $this->namesOf($this->paginateVia('status=site_survey'))
        );
    }

    /** ?status=（全て）は終了状態も含めて両種別を出す */
    public function test_status_all_includes_closed_of_both_types(): void
    {
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-SOLD', '分譲地PJ-SOLDOUT'],
            $this->namesOf($this->paginateVia('status='))
        );
    }

    /**
     * 「全て」が **null** で届いても全件出る（実 HTTP の `?status=` はこちら）。
     *
     * ⚠ RULES.md Bug #31 の回帰。`is_string()` の型ガードを `filled()` 判定より前に置くと、
     *   `''`（Request::create 経由）は通るのに `null`（実 HTTP 経由）だけが
     *   「想定外の型」に落ちて 0 件になる。上の `status=` のテストは '' しか通らないので
     *   この経路を守れない。
     */
    public function test_status_null_is_treated_as_all(): void
    {
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-SOLD', '分譲地PJ-SOLDOUT'],
            $this->namesOf($this->paginateViaNullStatus())
        );
    }

    /**
     * ?status[]=selling のように配列で来ても 500 にならない。
     *
     * ⚠ 配列を enum の tryFrom() へ渡すと TypeError になる。未知の文字列（?status=zzz）が
     *   0 件になる既存挙動に合わせ、配列も 0 件へ落とす。
     */
    public function test_status_as_array_does_not_error(): void
    {
        $this->makeProcurement('PRC-001', ['status' => 'selling']);
        $this->makeProject('PJ-001',      ['status' => 'selling']);

        $rows = $this->paginateVia('status[]=selling');

        $this->assertSame(0, $rows->total());
    }

    /** 未知のステータス値は 0 件（既存挙動の維持。配列ケースと同じ結果になること） */
    public function test_unknown_status_returns_no_rows(): void
    {
        $this->makeProcurement('PRC-001', ['status' => 'selling']);
        $this->makeProject('PJ-001',      ['status' => 'selling']);

        $this->assertSame(0, $this->paginateVia('status=zzz')->total());
    }

    /**
     * 日付も id も一致する別テーブル同士でも順序が確定し、仕入れ案件が先に来る（設計書 §3.4）。
     * 順序が確定しないとページ境界（20 件目 / 21 件目）で行が重複・欠落しうる。
     *
     * ⚠ このテストが守るのは「**向き**が仕入れ案件先であること」（契約）であって、
     *   「ソートキーの第 4 要素（種別）が無いと壊れること」ではない。
     *   PHP 8.0+ の `arsort()` は安定ソート（実測確認済み）で、Laravel の
     *   `Collection::sortByDesc()` はそれを使う（`Collection.php:1603`）。
     *   `sortedKeys()` は常に `$procKeys->merge($projKeys)` の順でマージするため、
     *   第 4 要素を消しても merge 順（仕入れ案件が先）がそのまま保持されて PASS する。
     *   第 4 要素が守るのは「merge 順が変わっても向きが保たれること」（実装の頑健性）で、
     *   別レイヤーの関心。消すと正しさが merge 順への暗黙依存になり、それを守るのが
     *   このテスト 1 本だけになる（変異試験: 第 4 要素なし＋merge 順反転で FAIL）。
     */
    public function test_cross_table_id_collision_is_deterministic(): void
    {
        $this->makeProcurement('PRC-1', ['info_obtained_date' => '2026-06-01']);  // id=1
        $this->makeProject('PJ-1',      ['info_obtained_date' => '2026-06-01']);  // id=1

        $this->assertSame(
            ['物件PRC-1', '分譲地PJ-1'],   // 仕入れ案件が先
            $this->namesOf($this->paginateVia())
        );
    }

    // ================================================================
    // Task 3: 物件種別・取引種別・キーワード フィルタ
    // ================================================================

    /**
     * ?property_type=project は分譲地だけを出す（擬似値。enum には無い値）。
     *
     * ⚠ これは観測可能な振る舞い（分譲地だけが出ること）の検証。
     *   procurementQuery() 冒頭の早期 return を消しても、後続の
     *   where('property_type', 'project') が実在しない値を課すので結果は 0 件のままで
     *   このテストは PASS する（実測）。早期 return は「意図を明示し無駄なクエリを避ける」ためのもの。
     */
    public function test_property_type_project_shows_only_projects(): void
    {
        $this->makeProcurement('PRC-001');
        $this->makeProject('PJ-001');

        $this->assertSame(
            ['分譲地PJ-001'],
            $this->namesOf($this->paginateVia('property_type=project'))
        );
    }

    /** 実在の物件種別で絞ると分譲地が消える */
    public function test_property_type_enum_excludes_projects(): void
    {
        $this->makeProcurement('PRC-HOUSE', ['property_type' => 'used_house']);
        $this->makeProcurement('PRC-MANSION', ['property_type' => 'used_mansion']);
        $this->makeProject('PJ-001');

        $this->assertSame(
            ['物件PRC-HOUSE'],
            $this->namesOf($this->paginateVia('property_type=used_house'))
        );
    }

    /** 取引種別で絞ると分譲地が消える（分譲地に transaction_type カラムが無いため） */
    public function test_transaction_type_excludes_projects(): void
    {
        $this->makeProcurement('PRC-BUY',    ['transaction_type' => 'purchase']);
        $this->makeProcurement('PRC-BROKER', ['transaction_type' => 'brokerage']);
        $this->makeProject('PJ-001');

        $this->assertSame(
            ['物件PRC-BUY'],
            $this->namesOf($this->paginateVia('transaction_type=purchase'))
        );
    }

    /** キーワードは 仕入れ案件（案件番号/物件名/所在地）と分譲地（PJ番号/PJ名/所在地）を横断する */
    public function test_keyword_searches_both_tables(): void
    {
        $this->makeProcurement('PRC-777');
        $this->makeProject('PJ-777');
        $this->makeProcurement('PRC-100');
        $this->makeProject('PJ-100');

        // 案件番号 / PJ番号 の両方でヒットする
        $this->assertEqualsCanonicalizing(
            ['物件PRC-777', '分譲地PJ-777'],
            $this->namesOf($this->paginateVia('keyword=777'))
        );

        // 仕入れ案件だけに一致する語（分譲地が混ざらないこと）
        // ⚠ makeProcurement の既定は property_name = "物件{$code}" なので "PRC-100" は
        //   procurement_code と property_name の両方に含まれる。ここで検証しているのは
        //   「テーブルをまたいで漏れないこと」であって、どのカラムでヒットしたかではない。
        //   カラム個別の検証は test_keyword_matches_each_column_independently が担う。
        $this->assertSame(
            ['物件PRC-100'],
            $this->namesOf($this->paginateVia('keyword=PRC-100'))
        );

        // 分譲地だけに一致する語（仕入れ案件が混ざらないこと）
        $this->assertSame(
            ['分譲地PJ-100'],
            $this->namesOf($this->paginateVia('keyword=PJ-100'))
        );
    }

    /**
     * キーワードが 4 つの検索カラムそれぞれで独立にヒットする。
     *
     * ⚠ ヘルパーの既定値（property_name = "物件{$code}" / project_name = "分譲地{$code}"）を
     *   そのまま使うと、コードが名前にも含まれてしまい「どのカラムでヒットしたか」を
     *   区別できない。検索カラムを 1 本外してもテストが緑のまま通る（実測）。
     *   そのため、ここではコードと名前が重ならないデータを明示的に作る。
     */
    public function test_keyword_matches_each_column_independently(): void
    {
        // 案件番号だけに 'ALPHA' を含む（物件名・所在地には無い）
        $this->makeProcurement('PRC-ALPHA', ['property_name' => '三番町ハイツ']);
        // 物件名だけに '道後' を含む（案件番号・所在地には無い）
        $this->makeProcurement('PRC-002',   ['property_name' => '道後温泉ビル']);
        // PJ番号だけに 'BETA' を含む
        $this->makeProject('PJ-BETA', ['project_name' => '六軒家町分譲地']);
        // PJ名だけに '星岡' を含む
        $this->makeProject('PJ-002',  ['project_name' => '星岡ガーデン']);

        $this->assertSame(['三番町ハイツ'],   $this->namesOf($this->paginateVia('keyword=ALPHA')));
        $this->assertSame(['道後温泉ビル'],   $this->namesOf($this->paginateVia('keyword=' . urlencode('道後'))));
        $this->assertSame(['六軒家町分譲地'], $this->namesOf($this->paginateVia('keyword=BETA')));
        $this->assertSame(['星岡ガーデン'],   $this->namesOf($this->paginateVia('keyword=' . urlencode('星岡'))));
    }

    /**
     * 日本語キーワードで所在地を横断検索できる（本番の検索対象は日本語）。
     *
     * ⚠ `Request::create()` にエンコードしていない日本語をそのまま渡すと値が文字化けし
     *   （実測: '松山' → "\xef\xbf\xbd_\xef\xbf\xbd山"）、ヒット 0 件になって
     *   「実装が悪い」と誤診する。**必ず urlencode() を通すこと。**
     */
    public function test_keyword_matches_japanese_address_in_both_tables(): void
    {
        $this->makeProcurement('PRC-001', ['address' => '愛媛県松山市水泥町1-1']);
        $this->makeProject('PJ-001',      ['address' => '愛媛県松山市水泥町2-2']);
        $this->makeProcurement('PRC-002', ['address' => '愛媛県今治市別宮町3-3']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-001', '分譲地PJ-001'],
            $this->namesOf($this->paginateVia('keyword=' . urlencode('水泥町')))
        );
    }

    // ================================================================
    // Task 4: 画面描画
    // ================================================================

    /** 一覧に両種別が並ぶ */
    public function test_index_renders_both_types(): void
    {
        $this->makeProcurement('PRC-001');
        $this->makeProject('PJ-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('物件PRC-001');
        $response->assertSee('分譲地PJ-001');
        $this->assertSame(2, $response->viewData('rows')->total());

        // 分譲地の物件種別は素のテキスト「分譲地」。
        // ⚠ assertSee('分譲地') は PJ 名にも一致して false-pass するので DTO の値で見る
        $this->assertSame(
            ['分譲地'],
            collect($response->viewData('rows')->items())
                ->where('kind', 'project')->pluck('propertyTypeLabel')->all()
        );

        // 区画サブ行は分譲地の行にだけ出る（混在時に仕入れ案件の行へ漏れないこと）。
        // ⚠ 「分譲地が 1 件でもあれば全行に出す」型の条件ミスは、仕入れ案件だけの一覧を見る
        //   test_procurement_row_has_no_lot_subline では検出できない（実測）。件数で固定する。
        $this->assertSame(1, substr_count($response->getContent(), 'data-lot-count'));
    }

    /** 分譲地の行には物件名の下に「区画 成約数 / 総数」が出る */
    public function test_project_row_shows_lot_counts(): void
    {
        $pj = $this->makeProject('PJ-001');
        $this->makeLot($pj, 1, 'sold');
        $this->makeLot($pj, 2, 'sold');
        $this->makeLot($pj, 3, 'on_sale');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 意匠（inline style）の変更に耐えるよう data 属性 + 数値で見る
        $this->assertMatchesRegularExpression(
            '/data-lot-count[^>]*>\s*区画\s*<span[^>]*>2<\/span>\s*\/\s*3/u',
            $response->getContent()
        );
    }

    /** 区画 0 件の分譲地でも「区画 0 / 0」と区画ボタンを出す */
    public function test_project_with_zero_lots_shows_zero_and_lots_button(): void
    {
        $pj = $this->makeProject('PJ-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/data-lot-count[^>]*>\s*区画\s*<span[^>]*>0<\/span>\s*\/\s*0/u',
            $response->getContent()
        );
        $response->assertSee("/realestate/projects/{$pj->id}/lots", false);
    }

    /** 仕入れ案件の行には区画サブ行の要素自体を出さない */
    public function test_procurement_row_has_no_lot_subline(): void
    {
        $this->makeProcurement('PRC-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('物件PRC-001');
        $response->assertDontSee('data-lot-count', false);
    }

    /** 0 件のときは colspan=10 で「該当するデータがありません。」 */
    public function test_empty_list_shows_updated_message(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('colspan="10"', false);
        $response->assertSee('該当するデータがありません。');
    }

    /** 分譲地のステータスバッジ CSS が同梱されている（忘れると無色で描画される） */
    public function test_project_badge_css_is_present(): void
    {
        $this->makeProject('PJ-001', ['status' => 'selling']);

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('badge-prj-selling', false);
        $response->assertSee('.badge-prj-selling {', false);
    }

    /**
     * ステータスセルの「呼び出し側（属性）」と「定義側（<script>）」が対で存在する。
     *
     * ⚠ Bug #28 の教訓: 属性だけ見ると定義が無くても緑になる。必ず対で検証する。
     */
    public function test_status_cell_caller_and_definition_are_both_present(): void
    {
        $this->makeProcurement('PRC-001');
        $this->makeProject('PJ-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 呼び出し側 — 種別ごとに別の kind が渡る
        $response->assertSee("realestateStatusCell('procurement'", false);
        $response->assertSee("realestateStatusCell('project'", false);
        // 定義側
        $response->assertSee('function realestateStatusCell(', false);
        // 種別ごとの選択肢マップと更新先エンドポイント
        $response->assertSee('__reStatusOptions', false);
        $response->assertSee('__reStatusEndpoint', false);
        // ⚠ ここは assertSee('/realestate/projects') では駄目。その文字列は分譲地行の
        //   「詳細」「区画」の href にも必ず出るので、エンドポイントマップを取り違えても緑になる。
        //   取り違えると分譲地バッジのクリックが PATCH /realestate/procurements/{分譲地のid}/status
        //   を叩き、id が衝突した別テーブルのレコードのステータスを黙って書き換える。
        $response->assertSee("procurement: '" . url('/realestate/procurements') . "'", false);
        $response->assertSee("project: '" . url('/realestate/projects') . "'", false);
        // 旧関数名が残っていない
        $response->assertDontSee('procurementStatusCell', false);
    }

    /**
     * ポップオーバーの選択肢が種別ごとに正しい enum から組まれている。
     *
     * ⚠ `assertSee('__reStatusOptions')` だけでは中身を見ていない。分譲地側に
     *   ProcurementStatus を渡しても緑のままで、本番では分譲地に存在しない「現地調査」が並び、
     *   選ぶと ProjectController の in: バリデーションに弾かれて 422 になる
     *   （「販売済」も sold ⇔ sold_out の食い違いで失敗する）。
     */
    public function test_status_options_are_built_from_the_right_enum_per_kind(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $options = $response->viewData('statusOptionsByKind');

        $this->assertSame(
            array_column(ProcurementStatus::cases(), 'value'),
            array_column($options['procurement'], 'value')
        );
        $this->assertSame(
            array_column(ProjectStatus::cases(), 'value'),
            array_column($options['project'], 'value')
        );
    }

    // ================================================================
    // Task 5: フィルタバー
    // ================================================================

    /** 物件種別セレクトの「全て」直下＝実種別の先頭に「分譲地」がある */
    public function test_property_type_select_has_project_option_right_after_all(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // ⚠ assertSee('分譲地') は他所にも一致して false-pass するので option の生 HTML で見る
        //   （Blade コメントはコンパイル時に消え、間に残るのは空白のみ）
        $this->assertMatchesRegularExpression(
            '/<option value="">物件種別: 全て<\/option>\s*<option value="project"[^>]*>分譲地<\/option>/u',
            $response->getContent()
        );
    }

    /** 「分譲地」を選択したら selected が付く */
    public function test_property_type_project_option_is_marked_selected(): void
    {
        $response = $this->actingAs($this->executive())
            ->get('/realestate/procurements?property_type=project');

        $response->assertOk();
        $response->assertSee('<option value="project" selected>分譲地</option>', false);
    }

    /** 「現地調査」は分譲地に無いステータスなので選択肢に補記が付く */
    public function test_site_survey_option_is_annotated(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('現地調査（仕入れ案件のみ）');
        // ⚠ 「付いていること」だけを見ると、三項演算子を消して全ステータスに付けても緑になる（実測）。
        //   補記は現地調査だけに付くことまで固定する。
        $response->assertDontSee('査定・検討（仕入れ案件のみ）');
    }

    /**
     * 物件種別セレクトに enum 由来の選択肢が全部あり、かつ enum は汚れていない。
     *
     * ⚠ RealEstatePropertyType に Project ケースを足すと登録フォームの選択肢にも出てしまい、
     *   「物件種別＝分譲地の仕入れ案件」がバリデーションを素通りして作れてしまう。
     */
    public function test_property_type_enum_is_not_polluted_by_the_pseudo_value(): void
    {
        $values = array_column(RealEstatePropertyType::cases(), 'value');

        $this->assertNotContains('project', $values);

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');
        $response->assertOk();
        foreach (RealEstatePropertyType::cases() as $pt) {
            $response->assertSee('<option value="' . $pt->value . '"', false);
        }
    }

    // ================================================================
    // Task 6: ページネーション
    // ================================================================

    /** 21 件（両種別混在）で 2 ページに割れ、1 ページ目 20 件・2 ページ目 1 件 */
    public function test_pagination_spans_both_types(): void
    {
        // 情報入手日をずらして順序を確定させる（同着タイブレークに依存しない）
        for ($i = 1; $i <= 11; $i++) {
            $this->makeProcurement(sprintf('PRC-%03d', $i), [
                'info_obtained_date' => sprintf('2026-06-%02d', $i),
            ]);
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get('/realestate/procurements');
        $page1->assertOk();
        $this->assertSame(21, $page1->viewData('rows')->total());
        $this->assertCount(20, $page1->viewData('rows')->items());

        $page2 = $this->actingAs($user)->get('/realestate/procurements?page=2');
        $page2->assertOk();
        $this->assertCount(1, $page2->viewData('rows')->items());
        // 最も古い＝ PRC-001（2026-06-01）が最後のページに来る
        $this->assertSame('物件PRC-001', $page2->viewData('rows')->items()[0]->name);
    }

    /** ページ送りリンクに絞り込みクエリが載る */
    public function test_pagination_keeps_filters(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }
        // ⚠ 仕入れ案件も 1 件混ぜる。分譲地だけだと「property_type=project の絞り込みで
        //   仕入れ案件が漏れないこと」を同時に検証できない（データが無いので当然 0 件になる）
        $this->makeProcurement('PRC-001', ['info_obtained_date' => '2026-07-31']);

        $response = $this->actingAs($this->executive())
            ->get('/realestate/procurements?property_type=project&keyword=PJ');

        $response->assertOk();
        $rows = $response->viewData('rows');

        // 絞り込みが効いていること（仕入れ案件が混ざらない）
        $this->assertSame(21, $rows->total());
        $this->assertNotContains('物件PRC-001', collect($rows->items())->pluck('name')->all());

        parse_str(parse_url($rows->url(2), PHP_URL_QUERY) ?? '', $q);

        $this->assertSame('project', $q['property_type']);
        $this->assertSame('PJ', $q['keyword']);
        $this->assertSame('2', $q['page']);
    }

    /**
     * 「ステータス: 全て」がページ送りで保持される。
     *
     * ⚠ ConvertEmptyStringsToNull で ?status= は null になり、そのまま
     *   LengthAwarePaginator へ渡すと http_build_query が null のキーを捨てる。
     *   すると 2 ページ目で既定の「進行中のみ」に戻り、終了状態の行が消える。
     */
    public function test_pagination_keeps_status_all_filter(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'status'             => 'sold_out',
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }

        $response = $this->actingAs($this->executive())
            ->get('/realestate/procurements?status=');

        $response->assertOk();
        $this->assertSame(21, $response->viewData('rows')->total());

        parse_str(parse_url($response->viewData('rows')->url(2), PHP_URL_QUERY) ?? '', $q);

        $this->assertArrayHasKey('status', $q);
        $this->assertSame('', $q['status']);
        $this->assertSame('2', $q['page']);
    }

    /**
     * 2 ページ以上のとき、インライン番号付きページネーションが描画される。
     *
     * ⚠ このプロジェクトは `->links()` を使わずインライン番号付きマークアップで描く規約
     *   （RULES.md Bug #24）。Task 4 で `$procurements` → `$rows` へ置換したので、
     *   置換漏れでブロックごと消えていないことを HTML で固定する。
     */
    public function test_numbered_pagination_links_are_rendered(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 現在ページ（1）は緑のバッジ、2 ページ目はリンク
        $response->assertSee('bg-emerald-600 border border-emerald-600 font-semibold">1</span>', false);
        $response->assertSee('?page=2', false);
        // 全件数の表示も両種別の合算になっている
        $response->assertSee('全 21 件');
    }
}
