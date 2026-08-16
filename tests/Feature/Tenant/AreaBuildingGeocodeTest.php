<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * 座標の一括取得（設計 §7.4）。
 *
 * ⚠ サーバ側のテストは JSON を手で送るだけなので、**ブラウザの Geocoder ループが
 *   1 度も走らなくても緑になる**（Bug #28 / #35 と同じ構図）。しかもここで守りたいのは
 *   「1 棟につき Google を 1 回しか叩かない」という**課金に直結する不変条件**で、
 *   これは PHP からは原理的に測れない。
 *   → 画面に載った `<script>` をそのまま node の `vm` で実駆動して測る
 *     （Bug #47 の 2026-08-17 追記「振る舞いの正本は実駆動」）。
 */
class AreaBuildingGeocodeTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    private const LIST_URL = '/tenant/area-buildings';

    private const GEOCODE_URL = '/tenant/area-buildings/geocode';

    /**
     * ⚠ `post()` という名前にしてはいけない。`MakesHttpRequests::post()` を
     *   private で上書きすることになり **PHP の fatal**（可視性の縮小）になる。
     *   プランの雛形はこの名前だった（2026-08-17 実測で fatal を確認）。
     */
    private function postCoordinates(array $coordinates, ?\App\Models\User $actor = null)
    {
        return $this->actingAs($actor ?? $this->manager())->post(self::GEOCODE_URL, [
            'coordinates' => json_encode($coordinates),
        ]);
    }

    // ============================================================
    // 権限
    // ============================================================

    public function test_staff_cannot_post_coordinates(): void
    {
        $this->actingAs($this->staff())
            ->post(self::GEOCODE_URL, ['coordinates' => '[]'])
            ->assertForbidden();
    }

    /**
     * 経営層でも通ること。
     * ⚠ これが無いとルートを `role:manager` に狭める変異が素通りする
     *   （Task 8 / 9 / 10 で 3 回続けて空いた穴）。
     */
    public function test_executive_can_post_coordinates(): void
    {
        $building = $this->makeBuilding('経営層ビル', ['address' => '松山市1-1']);

        $this->postCoordinates(
            [['id' => $building->id, 'latitude' => 33.84, 'longitude' => 132.77]],
            $this->executive()
        )->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertNotNull($building->fresh()->latitude);
    }

    // ============================================================
    // 保存
    // ============================================================

    public function test_saves_coordinates_for_pending_buildings(): void
    {
        $building = $this->makeBuilding('未取得ビル', ['address' => '松山市1-1']);

        $this->postCoordinates([
            ['id' => $building->id, 'latitude' => 33.83921234567, 'longitude' => 132.76571234567],
        ])->assertRedirect(route('tenant.area-buildings.index'));

        // ⚠ モデル経由で読むと `decimal:7` キャストの number_format() が丸めてしまうので、
        //   **書き込みを測るなら生の DB 値を見る**。2026-08-17 実測:
        //     number_format(33.83921234567, 7, '.', '')          === '33.8392123'
        //     number_format(round(33.83921234567, 7), 7, '.', '') === '33.8392123'   ← 同じ
        //   つまりモデル経由のアサートは round() を消しても緑になり、
        //   「小数第7位に丸めて保存されていない」という失敗メッセージが嘘になる。
        // ⚠ 本番 MySQL は decimal(10,7) 列なのでエンジン側でも丸まる。round() が
        //   観測可能なのは REAL で持つ SQLite（＝このテスト）だけで、本番では
        //   「保存値と表示値を一致させる」ための防御。だからこそテストで固定する。
        $raw = DB::table('area_buildings')->where('id', $building->id)->first();
        $this->assertEqualsWithDelta(33.8392123, (float) $raw->latitude, 1e-9, '小数第7位に丸めて保存されていない');
        $this->assertEqualsWithDelta(132.7657123, (float) $raw->longitude, 1e-9, '小数第7位に丸めて保存されていない');
        $this->assertStringContainsString('1 件', session('success'));
    }

    /**
     * ⚠ 既に座標がある行は上書きしない（手で直した位置を一括処理で潰さない）。
     *   二重課金の防止と対で load-bearing（設計 §7.4 / §11-11）。
     */
    public function test_does_not_overwrite_buildings_that_already_have_coordinates(): void
    {
        $building = $this->makeBuilding('取得済みビル', [
            'address' => '松山市1-1', 'latitude' => 33.8000000, 'longitude' => 132.7000000,
        ]);

        $this->postCoordinates([
            ['id' => $building->id, 'latitude' => 34.0, 'longitude' => 133.0],
        ])->assertRedirect();

        $building->refresh();
        $this->assertSame('33.8000000', $building->latitude);
        $this->assertSame('132.7000000', $building->longitude);
    }

    public function test_ignores_out_of_range_and_malformed_entries(): void
    {
        $a = $this->makeBuilding('A', ['address' => '松山市1-1']);
        $b = $this->makeBuilding('B', ['address' => '松山市2-2']);
        $c = $this->makeBuilding('C', ['address' => '松山市3-3']);
        $d = $this->makeBuilding('D', ['address' => '松山市4-4']);
        $e = $this->makeBuilding('E', ['address' => '松山市5-5']);

        $this->postCoordinates([
            ['id' => $a->id, 'latitude' => 91.0, 'longitude' => 132.0],        // 緯度が範囲外
            ['id' => $b->id, 'latitude' => 33.8, 'longitude' => 181.0],        // 経度が範囲外
            ['id' => $c->id, 'latitude' => 'あ', 'longitude' => 132.0],         // 数値でない
            ['id' => $d->id],                                                   // キー欠落
            // ⚠ id が配列だと whereKey() が whereIn に化けて**無関係な行まで一括更新**される
            ['id' => [$e->id], 'latitude' => 33.8, 'longitude' => 132.7],
            'これは配列ですらない',
        ])->assertRedirect();

        foreach ([$a, $b, $c, $d, $e] as $building) {
            $this->assertNull($building->fresh()->latitude, "{$building->name} が更新されている");
        }
    }

    public function test_rejects_malformed_json(): void
    {
        $this->actingAs($this->manager())
            ->from(self::LIST_URL)
            ->post(self::GEOCODE_URL, ['coordinates' => 'ぐちゃぐちゃ'])
            ->assertRedirect(self::LIST_URL);

        $this->assertNotNull(session('error'));
    }

    /**
     * サーバ側にも 1 リクエストあたりの上限がある。
     *
     * ⚠ ブラウザは `pendingGeocode(200)` しか回さないので、通常この上限には届かない
     *   ＝**画面からは一生実行されない分岐**。2026-08-17 の変異テストで `array_slice` を
     *   外しても 18 本すべて緑だったので、ここで固定する（Bug #45 ①「対象を測る」）。
     * ⚠ 上限を超えた分は保存されない。ブラウザ経由では起こりえず、手組みの payload だけが
     *   到達するので、1 リクエストの仕事量を有界にするほうを優先している。
     */
    public function test_the_server_caps_the_batch_it_will_write(): void
    {
        $ids = [];
        for ($i = 1; $i <= 201; $i++) {
            $ids[] = $this->makeBuilding("未取得{$i}", ['address' => "松山市{$i}"])->id;
        }

        $this->postCoordinates(array_map(
            fn (int $id) => ['id' => $id, 'latitude' => 33.84, 'longitude' => 132.77],
            $ids
        ));

        $this->assertSame(200, AreaBuilding::whereNotNull('latitude')->count(), 'サーバ側の上限が効いていない');
        $this->assertNull(AreaBuilding::find($ids[200])->latitude, '201 件目まで書き込んでいる');
    }

    /** 残件がある / 無いで文言が変わる（次に押すべきかが分かる） */
    public function test_success_message_reports_the_remainder(): void
    {
        $done    = $this->makeBuilding('埋める', ['address' => '松山市1-1']);
        $pending = $this->makeBuilding('残す', ['address' => '松山市2-2']);

        $this->postCoordinates([
            ['id' => $done->id, 'latitude' => 33.84, 'longitude' => 132.77],
        ]);
        $this->assertStringContainsString('残り 1 件', session('success'));

        $this->postCoordinates([
            ['id' => $pending->id, 'latitude' => 33.85, 'longitude' => 132.78],
        ]);
        $this->assertStringContainsString('座標未設定はありません', session('success'));
    }

    // ============================================================
    // 一覧側の表示（費用の見え方）
    // ============================================================

    /** 座標未取得が 0 件なら Maps のスクリプト自体を出さない（設計 §6.0 / プラン §1-8） */
    public function test_list_does_not_load_maps_when_nothing_is_pending(): void
    {
        $this->makeBuilding('座標あり', ['address' => '松山市1-1', 'latitude' => 33.8, 'longitude' => 132.7]);
        $this->makeBuilding('住所なし');

        $html = $this->actingAs($this->manager())->get(self::LIST_URL)->getContent();

        $this->assertStringNotContainsString('maps.googleapis.com', $html);
        $this->assertStringNotContainsString('一括取得', $html);
    }

    /** 未取得があるときだけボタンと Geocoder が出る。⚠ Map は 1 つも作らない */
    public function test_list_shows_the_button_with_the_pending_count(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市1-1']);
        $this->makeBuilding('未取得B', ['address' => '松山市2-2']);

        $response = $this->actingAs($this->manager())->get(self::LIST_URL);
        $html     = $response->getContent();

        $this->assertSame(2, $response->viewData('pendingGeocodeCount'));
        $this->assertStringContainsString('座標未設定 2 件を一括取得', $html);
        $this->assertStringContainsString('maps.googleapis.com', $html);
        $this->assertStringContainsString('new google.maps.Geocoder()', $html);
        $this->assertStringNotContainsString('new google.maps.Map(', $html, '一覧で地図を生成している（課金する）');

        // ⚠ 読み込み待ちの disabled は置かない。Maps が読めない環境で「押せず理由も出ない」
        //   ボタンが残る（Bug #43）。未読込のクリックはスクリプトが alert で理由を出す。
        // ⚠ `\bdisabled\b` で見てはいけない — class の `disabled:opacity-50` に一致して
        //   **正しい実装のほうが赤になる**（Bug #43 の「テストが不具合を守る」と同型）。
        $at = strpos($html, '<button type="button" id="btn-bulk-geocode"');
        $this->assertNotFalse($at, '一括取得ボタンが無い');
        $this->assertDoesNotMatchRegularExpression(
            '/\sdisabled(\s|>|=)/',
            substr($html, $at, strpos($html, '>', $at) - $at + 1),
            'ボタンが最初から disabled になっている（Maps が読めないと理由も出せない）'
        );
    }

    /**
     * 呼び出し側と定義側を**対で**固定する（Bug #28）。
     *
     * ⚠ 実駆動テストは `context.runBulkGeocode()` を自分で呼ぶので、**ボタンの onclick を
     *   消しても緑のまま**通る（2026-08-17 実測: 19 本すべて緑）。ブラウザでは押しても
     *   無反応になる。同様に、ローダーの `callback=` が定義名とズレると Geocoder が
     *   永久に作られず、押すたび「読み込み中です」だけが出る。
     */
    public function test_the_button_and_the_maps_callback_are_wired_to_their_definitions(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市1-1']);

        $html   = $this->actingAs($this->manager())->get(self::LIST_URL)->getContent();
        $script = $this->bulkGeocodeScript($html);

        $this->assertStringContainsString('onclick="runBulkGeocode()"', $html, 'ボタンが関数を呼んでいない');
        $this->assertStringContainsString('function runBulkGeocode(', $script, 'runBulkGeocode の実体が無い');

        $this->assertStringContainsString('callback=onAreaGeocodeReady', $html, 'ローダーの callback が指定されていない');
        $this->assertStringContainsString('function onAreaGeocodeReady(', $script, 'onAreaGeocodeReady の実体が無い');
    }

    /** staff にはボタンを出さない */
    public function test_staff_does_not_see_the_button(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市1-1']);

        $html = $this->actingAs($this->staff())->get(self::LIST_URL)->getContent();

        $this->assertStringNotContainsString('一括取得', $html);
        $this->assertStringNotContainsString('maps.googleapis.com', $html);
    }

    /** 1 回の実行で叩く上限（設計 §7.4）。超過分は次回に回し、残件数を知らせる */
    public function test_pending_list_is_capped_and_the_remainder_is_reported(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            $this->makeBuilding("未取得{$i}", ['address' => "松山市{$i}"]);
        }

        $response = $this->actingAs($this->manager())->get(self::LIST_URL);

        $this->assertSame(205, $response->viewData('pendingGeocodeCount'));
        $this->assertCount(200, $response->viewData('pendingGeocode'), '1 回の上限が効いていない');
        $response->assertSee('座標未設定 205 件を一括取得');
        $response->assertSee('今回は 200 件まで');
    }

    // ============================================================
    // 画面が描画したフォームの往復（Bug #47）
    // ============================================================

    /**
     * ⚠ URL を assertSee するだけでは HTTP メソッドも hidden も @csrf も見ていない。
     *   描画されたフォームをそのまま送り返す。
     * ⚠ `coordinates` の hidden に静的な値が入っていないことも見る（JS が入れる想定）。
     */
    public function test_the_geocode_form_round_trips_to_the_geocode_route(): void
    {
        $manager  = $this->manager();
        $building = $this->makeBuilding('往復ビル', ['address' => '松山市1-1']);

        $html = $this->actingAs($manager)->get(self::LIST_URL)->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.geocode') . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');
        $this->assertArrayHasKey('coordinates', $form['fields'], 'hidden coordinates が描画されていない');
        $this->assertSame('', $form['fields']['coordinates'], 'coordinates に静的な value が付いている');

        $this->actingAs($manager)->post($form['action'], array_merge($form['fields'], [
            'coordinates' => json_encode([
                ['id' => $building->id, 'latitude' => 33.84, 'longitude' => 132.77],
            ]),
        ]))->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertNotNull($building->fresh()->latitude);
    }

    // ============================================================
    // ブラウザで走る JS の振る舞い（node の vm で実駆動）
    // ============================================================

    /**
     * **課金の不変条件**: 1 棟につき Geocoder を 1 回だけ叩く。
     *
     * ⚠ `_form.blade.php` の手作業用 `geocodeAreaAddress()` は 1 クリックで**最大 5 回**
     *   叩く（段階フォールバック）。それを一括処理へ持ち込むと請求が 5 倍になる。
     *   構造テスト（呼び出し箇所が 1 つ）だけでは「ループが 2 周する」変異を拾えないので、
     *   実際に走らせて**呼ばれた回数と住所**を数える。
     *
     * さらに、実駆動が吐いた payload を**そのままサーバへ POST** して DB まで通す
     * （画面 → JS → HTTP → DB の全経路が 1 本で繋がる）。
     */
    public function test_the_browser_script_geocodes_each_building_exactly_once_and_posts_the_result(): void
    {
        $manager = $this->manager();
        $a = $this->makeBuilding('未取得A', ['address' => '松山市一番町1-1']);
        $b = $this->makeBuilding('未取得B', ['address' => '松山市二番町2-2']);
        $c = $this->makeBuilding('未取得C', ['address' => '松山市三番町3-3']);

        $html = $this->actingAs($manager)->get(self::LIST_URL)->getContent();
        $run  = $this->runBrowserScript($html, ['statuses' => ['OK', 'OK', 'OK']]);

        // 1 棟 1 回。順番も住所もそのまま（フル住所のみ。市区町村への切り詰めをしていない）
        $this->assertSame(
            ['松山市一番町1-1', '松山市二番町2-2', '松山市三番町3-3'],
            $run['calls'],
            '1 棟につき 1 回・フル住所で問い合わせていない（課金が増える）'
        );
        $this->assertSame(1, $run['submitted'], 'フォームを 1 回だけ送信していない');
        $this->assertTrue($run['buttonDisabled'], '実行中にボタンを止めていない（二重送信で二重課金する）');

        $payload = json_decode($run['payload'], true);
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            array_column($payload, 'id'),
            'ブラウザが組んだ payload のビルが合っていない'
        );

        // 実駆動が吐いた payload をそのままサーバへ
        $this->actingAs($manager)->post(self::GEOCODE_URL, ['coordinates' => $run['payload']]);

        foreach ([$a, $b, $c] as $building) {
            $this->assertNotNull($building->fresh()->latitude, "{$building->name} の座標が保存されていない");
        }
        $this->assertStringContainsString('3 件', session('success'));
    }

    /** 失敗した棟は飛ばして残りを保存する（1 件の失敗で全部を捨てない） */
    public function test_the_browser_script_skips_failures_and_still_saves_the_rest(): void
    {
        $a = $this->makeBuilding('未取得A', ['address' => '松山市一番町1-1']);
        $this->makeBuilding('未取得B', ['address' => '松山市二番町2-2']);
        $c = $this->makeBuilding('未取得C', ['address' => '松山市三番町3-3']);

        $html = $this->actingAs($this->manager())->get(self::LIST_URL)->getContent();
        $run  = $this->runBrowserScript($html, ['statuses' => ['OK', 'ZERO_RESULTS', 'OK']]);

        $this->assertCount(3, $run['calls'], '失敗しても残りを続けていない');
        $this->assertSame(
            [$a->id, $c->id],
            array_column(json_decode($run['payload'], true), 'id'),
            '失敗した棟が payload に混ざっている / 成功した棟が落ちている'
        );
        $this->assertStringContainsString('失敗 1 件', $run['progress']);
    }

    /**
     * Google が上限を返したら**その場で止める**（叩き続けても全部失敗して課金だけ増える）。
     * 取れた分は捨てずに保存する。
     */
    public function test_the_browser_script_stops_when_google_reports_the_query_limit(): void
    {
        $a = $this->makeBuilding('未取得A', ['address' => '松山市一番町1-1']);
        $this->makeBuilding('未取得B', ['address' => '松山市二番町2-2']);
        $this->makeBuilding('未取得C', ['address' => '松山市三番町3-3']);

        $html = $this->actingAs($this->manager())->get(self::LIST_URL)->getContent();
        $run  = $this->runBrowserScript($html, ['statuses' => ['OK', 'OVER_QUERY_LIMIT', 'OK']]);

        $this->assertCount(2, $run['calls'], '上限に達しても叩き続けている（課金が増える）');
        $this->assertSame([$a->id], array_column(json_decode($run['payload'], true), 'id'));
        $this->assertSame(1, $run['submitted'], '取れた分を保存していない');
        $this->assertStringContainsString('上限', $run['progress']);
    }

    /**
     * Maps が読めていないうちに押しても、空 payload で送信しない。
     *
     * ⚠ ボタンは**最初から押せる状態**にしてある（読み込み待ちで `disabled` にすると、
     *   Maps が読めない環境で「押せず理由も出ない」ボタンが残る。Bug #43）。
     *   よってこのガードが唯一の防波堤で、単独で測る必要がある（Bug #48）。
     */
    public function test_the_browser_script_refuses_to_run_before_maps_is_ready(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市一番町1-1']);

        $html = $this->actingAs($this->manager())->get(self::LIST_URL)->getContent();
        $run  = $this->runBrowserScript($html, ['statuses' => ['OK'], 'ready' => false]);

        $this->assertSame([], $run['calls']);
        $this->assertSame(0, $run['submitted'], 'Maps 未読込なのに送信している');
        $this->assertNotEmpty($run['alerts'], '読み込み中であることを知らせていない');
        $this->assertFalse($run['buttonDisabled'], '読み込みを待てばもう一度押せる状態になっていない');
    }

    /** 確認ダイアログでキャンセルしたら 1 回も叩かない（課金は取り消せない） */
    public function test_the_browser_script_does_nothing_when_the_confirmation_is_cancelled(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市一番町1-1']);

        $html = $this->actingAs($this->manager())->get(self::LIST_URL)->getContent();
        $run  = $this->runBrowserScript($html, ['statuses' => ['OK'], 'confirm' => false]);

        $this->assertSame([], $run['calls'], 'キャンセルしたのに Google を叩いている');
        $this->assertSame(0, $run['submitted']);
        // ⚠ confirm より先に disabled にすると、キャンセルしたユーザーに死んだボタンが残る
        $this->assertFalse($run['buttonDisabled'], 'キャンセル後にボタンが押せなくなっている');
    }

    /**
     * 構造側の保険: 一覧のスクリプトに geocode の呼び出しは 1 箇所しか無い。
     *
     * ⚠ 実駆動テストと重複しているように見えるが役割が違う。実駆動は「今の入力での
     *   呼び出し回数」を測り、こちらは「呼び出し**箇所**が増えていないこと」を測る
     *   （`_form.blade.php` の段階フォールバックを一括処理へコピーする退行を止める）。
     */
    public function test_the_list_script_has_a_single_geocode_call_site(): void
    {
        $this->makeBuilding('未取得A', ['address' => '松山市1-1']);

        $script = $this->bulkGeocodeScript(
            $this->actingAs($this->manager())->get(self::LIST_URL)->getContent()
        );

        $this->assertSame(1, substr_count($script, '.geocode('), 'Geocoder を叩く箇所が 1 つでない');
        $this->assertStringNotContainsString(
            'buildAreaAddressFallbacks',
            $script,
            '手作業用の段階フォールバック（最大 5 回）を一括処理へ持ち込んでいる'
        );
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /** 一覧に載った「一括取得」用の inline `<script>` の中身 */
    private function bulkGeocodeScript(string $html): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $m);

        foreach ($m[1] as $body) {
            if (str_contains($body, 'function runBulkGeocode')) {
                return $body;
            }
        }

        $this->fail('runBulkGeocode を含む <script> が画面に無い');
    }

    /**
     * 画面に載ったスクリプトを node の `vm` でそのまま実駆動する。
     *
     * ⚠ ここで実行するのは**テスト用に書き写したコピーではなく、画面が返した文字列そのもの**。
     *   書き写すと Blade を壊しても緑のままになる（Bug #28 と同型）。
     *
     * @param  array{statuses: list<string>, ready?: bool, confirm?: bool}  $plan
     * @return array{calls: list<string>, payload: string, submitted: int, progress: string, alerts: list<string>, buttonDisabled: bool}
     */
    private function runBrowserScript(string $html, array $plan): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いのでブラウザ側スクリプトの実駆動を飛ばす');
        }

        $dir = sys_get_temp_dir() . '/area-geocode-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir . '/script.js', $this->bulkGeocodeScript($html));
            file_put_contents($dir . '/plan.json', json_encode($plan));
            file_put_contents($dir . '/harness.js', $this->harness());

            $output = shell_exec(sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg($dir . '/harness.js'),
                escapeshellarg($dir . '/script.js'),
                escapeshellarg($dir . '/plan.json')
            ));

            $decoded = json_decode((string) $output, true);
            $this->assertIsArray($decoded, "node の実行に失敗した:\n" . $output);

            return $decoded;
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    /**
     * 最小の DOM / Google Maps スタブ。
     *
     * ⚠ `setTimeout` は同期実行せず**キューに積んで後で回す**。同期実行にすると
     *   `step()` が自分を呼び直す形になり、棟数ぶんスタックが深くなる。
     */
    private function harness(): string
    {
        return <<<'JS'
const fs = require('fs');
const vm = require('vm');

const code = fs.readFileSync(process.argv[2], 'utf8');
const plan = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));

const calls = [];
const alerts = [];
const queue = [];
const elements = {};

function el(id) {
    if (!elements[id]) {
        elements[id] = {
            id: id,
            value: '',
            textContent: '',
            disabled: false,
            submitted: 0,
            submit: function () { this.submitted++; }
        };
    }
    return elements[id];
}

function FakeGeocoder() {
    this.geocode = function (request, callback) {
        calls.push(request.address);
        const status = plan.statuses[calls.length - 1] || 'ZERO_RESULTS';
        if (status !== 'OK') {
            callback([], status);
            return;
        }
        const n = calls.length;
        callback([{ geometry: { location: {
            lat: function () { return 33.8 + n / 10000; },
            lng: function () { return 132.7 + n / 10000; }
        } } }], 'OK');
    };
}

const sandbox = {
    console: console,
    document: { getElementById: function (id) { return el(id); } },
    alert: function (message) { alerts.push(String(message)); },
    confirm: function () { return plan.confirm !== false; },
    setTimeout: function (fn) { queue.push(fn); },
    google: { maps: { Geocoder: FakeGeocoder } }
};

const context = vm.createContext(sandbox);
vm.runInContext(code, context, { filename: 'list-script.js' });

if (plan.ready !== false) {
    context.onAreaGeocodeReady();
}

context.runBulkGeocode();
let guard = 0;
while (queue.length) {
    if (++guard > 10000) { throw new Error('setTimeout のキューが尽きない（無限ループ）'); }
    queue.shift()();
}

process.stdout.write(JSON.stringify({
    calls: calls,
    alerts: alerts,
    payload: el('geocode-payload').value,
    submitted: el('geocode-form').submitted,
    progress: el('geocode-progress').textContent,
    buttonDisabled: el('btn-bulk-geocode').disabled
}));
JS;
    }
}
