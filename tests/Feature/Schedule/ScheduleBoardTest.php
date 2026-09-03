<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleBoardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 横断ボード（設計書 §4.2 / §4.3）。
 *
 * ⚠ **部署をまたがせないことを必ず 1 本置く。** 住宅だけの権限の利用者に
 *   不動産の案件が出てはいけない。
 *
 * ⚠ **同じ数字・同じ語が 2 箇所に出るときは役割ごとに `viewData()` で見る**
 *   （Bug #43 / #46 / #49）。KPI カードは 2026-09-03 に削除した（設計書 §2 D1）。
 *
 * ⚠ **「今日」を固定する。** 遅延判定・状態判定が実行日に依存するため。
 */
class ScheduleBoardTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** テスト中の「今日」。すべての日付をこの日基準で置く。 */
    private const TODAY = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        CarbonImmutable::setTestNow(self::TODAY);
        \Carbon\Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        // ⚠ 戻さないと同一プロセスの後続テストへ漏れる
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    // ============================================================
    // 表示と絞り込み
    // ============================================================

    public function test_the_realestate_board_lists_both_realestate_kinds(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $prj = $this->makeParent('project');
        $prj->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk();

        $codes = array_column($response->viewData('board')['rows'], 'code');

        $this->assertContains('PRC-001', $codes);
        $this->assertContains('PRJ-001', $codes);
    }

    public function test_the_housing_board_lists_both_housing_kinds(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $order = $this->makeParent('customOrder');
        $order->scheduleSteps()->create(['name' => '実施設計', 'category' => 'permit', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $codes = array_column(
            $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->viewData('board')['rows'],
            'code'
        );

        $this->assertContains('HS-001', $codes);
        $this->assertContains('CO-001', $codes);
    }

    /**
     * ⚠ **これが「対象クラスを全部の親に広げる」変異を止めるテスト**（設計書 §4.3）。
     */
    public function test_the_realestate_board_never_shows_housing_cases(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk();

        $this->assertSame([], array_column($response->viewData('board')['rows'], 'code'));
        $this->assertStringNotContainsString('HS-001', $response->getContent(), '不動産のボードに住宅の案件が出ている');
    }

    /** 住宅だけの権限しか無い利用者は不動産のボードを開けない（設計書 §4.3） */
    public function test_a_housing_only_user_cannot_open_the_realestate_board(): void
    {
        $this->actingAs($this->staff(['housing']))->get('/realestate/schedules')->assertForbidden();
        $this->actingAs($this->staff(['housing']))->get('/housing/schedules')->assertOk();
    }

    /**
     * ⚠ **工程が 0 件の案件はボードに出さない**（設計書 §4.2）。
     *   出すと、まだ使い始めていない案件で画面が埋まる。件数だけ別に出す。
     */
    public function test_cases_without_steps_are_counted_but_not_listed(): void
    {
        $withSteps = $this->makeParent('procurement');
        $withSteps->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '未登録案件']);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk()->viewData('board');

        $this->assertSame(['PRC-001'], array_column($board['rows'], 'code'));
        $this->assertSame(1, $board['unregisteredCount'], '工程未登録の件数が合わない');
    }

    public function test_the_kind_filter_narrows_to_one_kind(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $prj = $this->makeParent('project');
        $prj->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?kind=project')->assertOk()->viewData('board');

        $this->assertSame(['PRJ-001'], array_column($board['rows'], 'code'));
    }

    public function test_the_keyword_filter_matches_the_case_name_and_the_step_name(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '確定測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $prj = $this->makeParent('project');
        $prj->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $byCase = $this->actingAs($this->manager())->get('/realestate/schedules?q=' . urlencode('余戸南'))->viewData('board');
        $this->assertSame(['PRJ-001'], array_column($byCase['rows'], 'code'), '案件名で絞れていない');

        $byStep = $this->actingAs($this->manager())->get('/realestate/schedules?q=' . urlencode('確定測量'))->viewData('board');
        $this->assertSame(['PRC-001'], array_column($byStep['rows'], 'code'), '工程名で絞れていない');
    }

    /**
     * ⚠ **「すべて」の値は空文字ではなく `all`**（決定 I / Bug #31）。
     *   空値だと `Arr::query()` がキーごと捨てて既定（進行中）に戻る。
     */
    public function test_the_all_status_option_is_not_an_empty_value(): void
    {
        $html = $this->actingAs($this->manager())->get('/realestate/schedules')->getContent();

        $this->assertStringContainsString('value="all"', $html, '「すべて」の値が all でない（Bug #31 の形）');
        // ⚠ 空値の option が 1 つでもあると、そのキーが Arr::query() に捨てられて既定へ戻る
        $this->assertStringNotContainsString('<option value=""', $html, '空値の絞り込み option がある（Bug #31 の形）');
    }

    // ============================================================
    // ステータスの判定（決定 G: 完了 > 遅延 > 進行中）
    // ============================================================

    private function caseWithSteps(string $code, array $steps): void
    {
        $owner = $this->makeParent('procurement', ['procurement_code' => $code, 'property_name' => $code]);

        foreach ($steps as $i => $s) {
            $owner->scheduleSteps()->create(array_merge(
                ['name' => "工程{$i}", 'category' => 'work', 'sort_order' => $i + 1],
                $s
            ));
        }
    }

    public function test_status_is_done_when_every_step_has_finished_even_if_it_was_late(): void
    {
        // 予定 8/20 に対し実績 8/25 = 遅れたが完了
        $this->caseWithSteps('PRC-DONE', [
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-08-20', 'actual_start' => '2026-08-01', 'actual_end' => '2026-08-25'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $row   = collect($board['rows'])->firstWhere('code', 'PRC-DONE');

        $this->assertSame('done', $row['status'], '完了は遅延より優先する（決定 G）');
    }

    public function test_status_is_late_when_a_step_is_overdue_and_unfinished(): void
    {
        $this->caseWithSteps('PRC-LATE', [
            ['planned_start' => '2026-07-01', 'planned_end' => '2026-08-20'],   // 未着手のまま予定終了を過ぎた
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $row   = collect($board['rows'])->firstWhere('code', 'PRC-LATE');

        $this->assertSame('late', $row['status']);
        $this->assertSame(11, $row['delayDays'], '8/20 から 8/31 で 11 日');
    }

    public function test_status_is_running_otherwise(): void
    {
        $this->caseWithSteps('PRC-RUN', [
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'actual_start' => '2026-08-01'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');

        $this->assertSame('running', collect($board['rows'])->firstWhere('code', 'PRC-RUN')['status']);
    }

    public function test_the_status_filter_narrows_the_rows(): void
    {
        $this->caseWithSteps('PRC-LATE', [['planned_start' => '2026-07-01', 'planned_end' => '2026-08-20']]);
        $this->caseWithSteps('PRC-RUN',  [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'actual_start' => '2026-08-01']]);

        $late = $this->actingAs($this->manager())->get('/realestate/schedules?status=late')->viewData('board');
        $this->assertSame(['PRC-LATE'], array_column($late['rows'], 'code'));

        $all = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $this->assertCount(2, $all['rows']);
    }

    // ============================================================
    // 軸（決定 F。ズームは 2026-09-03 に削除した。設計書 §2 D7）
    // ============================================================

    public function test_the_default_axis_is_six_months_back_and_twelve_forward(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');

        $this->assertSame('2026-02-01', $board['axis']['from'], '既定の軸の始まりが設計書 §5.5 と違う');
        $this->assertSame('2027-08-31', $board['axis']['to'], '既定の軸の終わりが設計書 §5.5 と違う');
    }

    /**
     * `headers()` の label / strong / widthPct を固定する。
     *
     * ⚠ **3 つとも無防備だった**（2026-09-03 のレビューで判明）。`label` を `''` に、
     *   `strong` を常に `false` に、`widthPct` を正規化前の日数に、それぞれ潰しても
     *   フルスイートが緑のまま通ることを実測済み。
     *
     * ⚠ **件数（19）も固定する**（Bug #45）。件数を見ないと、ループ境界が壊れて
     *   見出しが 1 つも無くなっても先頭・末尾の値だけ合っていれば緑になる。
     *
     * ⚠ 軸は Task 4 で「データの範囲」へ変わるので、そのとき件数と末尾の月は書き換わる。
     */
    public function test_the_axis_headers_are_month_labels_with_quarter_emphasis(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $headers = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis']['headers'];

        $this->assertCount(19, $headers, '既定の軸（19 ヶ月分）から見出しの数が変わっている');

        $this->assertSame('2月', $headers[0]['label'], '先頭の見出しが「2月」でない');
        $this->assertFalse($headers[0]['strong'], '四半期の頭でない月が強調されている');

        // 2026-04 は四半期の頭（1, 4, 7, 10 月）
        $this->assertSame('4月', $headers[2]['label']);
        $this->assertTrue($headers[2]['strong'], '四半期の頭（4月）が強調されていない');

        $this->assertSame('8月', $headers[18]['label'], '末尾の見出しが「8月」でない');
        $this->assertFalse($headers[18]['strong']);

        // ⚠ widthPct は月の日数に比例するので、全セルの合計が軸の全長（100%）と一致するはず。
        //   ここが崩れると、$next / $end の計算が壊れて棒とヘッダの位置がズレる。
        $sum = array_sum(array_column($headers, 'widthPct'));
        $this->assertEqualsWithDelta(100.0, $sum, 0.01, 'widthPct の合計が軸の全長（100%）と一致しない');
    }

    // ============================================================
    // 段の振り分け（設計書 §5.3）
    // ============================================================

    public function test_overlapping_steps_are_spread_across_lanes(): void
    {
        $this->caseWithSteps('PRC-OVERLAP', [
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-10-31'],
            ['planned_start' => '2026-08-15', 'planned_end' => '2026-10-31'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $row   = collect($board['rows'])->firstWhere('code', 'PRC-OVERLAP');

        $this->assertSame(2, $row['laneCount'], '重なる工程が同じ段に載っている（読めなくなる）');
        $this->assertSame([0, 1], array_column($row['bars'], 'lane'));
    }

    // ============================================================
    // サービスの契約（設計書 §4.3）
    // ============================================================

    // ============================================================
    // サイドバーの導線
    // ============================================================

    /**
     * ⚠ **`assertSee('工程表')` では原理的に検出できない。**
     *   「工程表」はボードの `<h1>` にも詳細カードの見出しにも出るので、
     *   サイドバーのリンクを 4 箇所とも消しても緑になる（Bug #43 の部分一致と同型）。
     *   **href の出現回数**で見る。
     *
     * ⚠ **ボード自身のページで数えない。** フィルタの `action` と「クリア」リンクが
     *   同じ URL を指すので数が混ざる。**別の画面**（一覧）で数える。
     *
     * ⚠ 期待値 2 は「PC 展開サイドバー」＋「モバイルドロワー」の 2 ブロック
     *   （折りたたみサイドバーは部署ごとにアイコン 1 個なので項目を持たない）。
     *   **どちらか片方の編集を忘れると 1 になって落ちる。** これが 4 箇所編集の担保。
     */
    public function test_both_sidebar_blocks_link_to_each_board(): void
    {
        $executive = $this->actor(\App\Enums\UserRole::Executive);

        foreach ([
            '/realestate/procurements' => '/realestate/schedules',
            '/housing/properties'      => '/housing/schedules',
        ] as $page => $link) {
            $html = $this->actingAs($executive)->get($page)->assertOk()->getContent();

            $this->assertSame(
                2,
                substr_count($html, 'href="' . url($link) . '"'),
                "{$link} へのサイドバー導線が 2 箇所（PC 展開 / モバイルドロワー）ありません。"
                . 'sidebar.blade.php は同じグループが 2 ブロックに出てくるので両方直すこと'
            );
        }
    }

    /**
     * ⚠ **対象クラスを既定値にしない。** 既定を持たせると、新しい部署のボードを足した人が
     *   引数を省略した瞬間に全部署の案件が漏れる。
     */
    public function test_the_service_requires_its_target_kinds_explicitly(): void
    {
        $method = new \ReflectionMethod(ScheduleBoardService::class, 'build');

        $this->assertFalse(
            $method->getParameters()[0]->isDefaultValueAvailable(),
            '対象クラスの引数に既定値があります（引数を省略すると全部署が漏れます。設計書 §4.3）'
        );
    }

    // ============================================================
    // 住宅事業ボード（設計書 §8）— 状態 3 種 / 遅延なし
    // ============================================================

    /**
     * 済 / 進行中 / これから を 1 件ずつ持つ 3 物件 ＋ `dateStatus()` の残り 2 分岐
     * （コード品質レビュー ②）を通す 2 物件、計 5 物件を作る。
     *
     * ⚠ **HS-MIX / HS-UNDATED は他の多くのテストにも影響する。** このフィクスチャを使う
     *   既存テストの期待値（KPI の枚数・棒の本数・絞り込み結果の件数）は全部この 5 件を
     *   前提に実測し直してある。ここへさらに案件を足すときは、使っている全テストを
     *   再実測すること。
     */
    private function housingBoardFixture(): void
    {
        // ⚠ 日付は ScheduleBoardTest の凍結日 2026-08-31 基準。
        //   30 日以内の窓は 2026-08-31 〜 2026-09-30。
        foreach ([
            ['HS-DONE', '2026-05-01', '2026-05-20'],
            ['HS-RUN',  '2026-08-20', '2026-09-30'],
            ['HS-SOON', '2026-09-15', '2026-09-20'],
        ] as $i => [$code, $s, $e]) {
            $owner = $this->makeParent('property', ['property_code' => $code, 'property_name' => $code . ' 邸']);
            $owner->scheduleSteps()->create([
                'name' => '工程' . $i, 'category' => 'work',
                'planned_start' => $s, 'planned_end' => $e, 'sort_order' => 1,
            ]);
        }

        // ⚠ コード品質レビュー ②: dateStatus() は 3 分岐あるが、上の 3 物件（1 案件 1 工程）
        //   だけでは「DONE と UPCOMING が混ざれば RUNNING」「全部未定なら UPCOMING」の
        //   2 分岐が一度も実行されない。2 物件を足して通す。
        $mix = $this->makeParent('property', ['property_code' => 'HS-MIX', 'property_name' => 'HS-MIX 邸']);
        $mix->scheduleSteps()->create(['name' => '済です', 'category' => 'work', 'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1]);
        $mix->scheduleSteps()->create(['name' => 'これからです', 'category' => 'work', 'planned_start' => '2026-09-15', 'planned_end' => '2026-09-20', 'sort_order' => 2]);

        // ⚠ 日付が 1 つも無い工程。isDrawable() が false になるので「棒が 1 本も描けない行」の
        //   レンダリング経路も併せて通す。
        $undated = $this->makeParent('property', ['property_code' => 'HS-UNDATED', 'property_name' => 'HS-UNDATED 邸']);
        $undated->scheduleSteps()->create(['name' => '日付未定', 'category' => 'work', 'sort_order' => 1]);
    }

    public function test_the_housing_board_offers_the_three_date_states(): void
    {
        $board = $this->actingAs($this->manager())->get('/housing/schedules')->viewData('board');

        $this->assertSame(
            ['running' => '進行中', 'all' => 'すべて', 'upcoming' => 'これから', 'done' => '済'],
            $board['statuses'],
            '住宅事業に「遅延」は無い（設計書 §8）'
        );
    }

    /** ⚠ 不動産のボードは 4 種のまま（巻き込み事故の検出。Bug #41） */
    public function test_the_realestate_board_keeps_its_own_statuses(): void
    {
        $board = $this->actingAs($this->manager())->get('/realestate/schedules')->viewData('board');

        $this->assertSame(
            ['running' => '進行中', 'all' => 'すべて', 'late' => '遅延', 'done' => '完了'],
            $board['statuses']
        );
    }

    public function test_a_housing_case_is_classified_by_its_dates(): void
    {
        $this->housingBoardFixture();

        $rows = collect(
            $this->actingAs($this->manager())->get('/housing/schedules?status=all')->viewData('board')['rows']
        )->keyBy('code');

        $this->assertSame('done', $rows['HS-DONE']['status']);
        $this->assertSame('running', $rows['HS-RUN']['status']);
        $this->assertSame('upcoming', $rows['HS-SOON']['status']);
        // ⚠ コード品質レビュー ②: dateStatus() の残り 2 分岐をここで直接固定する
        //   （1 案件 1 工程の 3 件だけでは一度も実行されない）。
        $this->assertSame('running', $rows['HS-MIX']['status'], '済とこれからが混ざれば進行中に見せる');
        $this->assertSame('upcoming', $rows['HS-UNDATED']['status'], '全部未定ならこれから');
        // ⚠ 件数はフィクスチャの案件数（5 件）に追従させる。固定の [0, 0, 0] は
        //   フィクスチャに案件が増えるたびに書き換えが要る壊れやすい形になる。
        $this->assertSame(array_fill(0, $rows->count(), 0), $rows->pluck('delayDays')->all(), '住宅に遅延日数は出さない');
    }

    public function test_the_housing_status_filter_narrows_the_rows(): void
    {
        $this->housingBoardFixture();

        $rows = $this->actingAs($this->manager())->get('/housing/schedules?status=upcoming')->viewData('board')['rows'];

        // ⚠ HS-UNDATED（全部未定）も「これから」に倒れるので、HS-SOON と並んで出る。
        $this->assertSame(['HS-SOON', 'HS-UNDATED'], array_column($rows, 'code'));
    }

    public function test_the_housing_board_never_paints_a_delay_badge(): void
    {
        $this->housingBoardFixture();

        $html = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+\d+日/', $html);
        $this->assertStringNotContainsString('border: 2px solid #DC2626', $html, '棒の赤枠も出さない');
        $this->assertStringContainsString('HS-RUN 邸', $html, '走査の空振りでないこと');
    }

    // ============================================================
    // 住宅事業ボード — 棒の見せ方（設計書 §8。コード品質レビュー ③）
    // ⚠ 設計書 §8 は「分類色のまま（濃さを変えない）＋ 進行中だけ輪郭。§4.2 と同じ」と
    //   定めている。プラン初版の Files 一覧が KPI と遅延バッジしか挙げておらず、
    //   future / opacity の対処が Task 5 の範囲から取りこぼされていた
    //   （設計書は承認済みなので、Task 5 の範囲としてここで直す）。
    // ============================================================

    /** 棒 1 本ぶんの style 属性を出現順で返す（Task 4 の _schedule_gantt 用ヘルパーと同じ流儀） */
    private function barStyles(string $html): array
    {
        preg_match_all('/position: absolute; height: 13px;[^"]*/', $html, $m);

        return $m[0];
    }

    /**
     * ⚠ **§4.2 は opacity 案を明示的に却下している**（「済」を 1.6:1 まで落とすため）。
     *   住宅は 3 本とも分類色のまま出す（HS-DONE=済 / HS-RUN=進行中 / HS-SOON=これから の
     *   3 状態を跨いで確認する）。
     */
    public function test_the_housing_board_bars_are_never_dimmed(): void
    {
        $this->housingBoardFixture();

        $html  = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->getContent();
        $styles = $this->barStyles($html);

        // HS-DONE 1 ＋ HS-RUN 1 ＋ HS-SOON 1 ＋ HS-MIX 2（済です・これからです）＋
        // HS-UNDATED 0（日付未定は isDrawable() が false で棒にならない）＝ 5 本
        $this->assertCount(5, $styles, '棒が 5 本描かれている（HS-UNDATED は棒 0 本）');
        foreach ($styles as $style) {
            $this->assertStringNotContainsString('opacity', $style, '住宅の棒を薄くしてはいけない（設計書 §8）');
        }
    }

    /**
     * 進行中の棒だけに輪郭が出る（詳細カード `_schedule_gantt.blade.php` と同じ規則。設計書 §8）。
     *
     * ⚠ **棒の side だけを数える。** ボード partial にはこのフィクスチャでは凡例が無いので
     *   衝突の心配は無いが、Task 4 と同じ流儀（`barStyles()` でスコープを絞る）を踏襲する。
     */
    public function test_the_housing_board_puts_a_ring_only_on_the_running_bar(): void
    {
        $this->housingBoardFixture();

        $html   = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->getContent();
        $styles = $this->barStyles($html);
        $ringed = array_filter($styles, fn ($s) => str_contains($s, 'box-shadow: 0 0 0 1.5px #111827'));

        $this->assertCount(5, $styles, '棒が 5 本描かれている');
        // ⚠ HS-MIX の「これからです」は案件としては running に見える組でも、工程自体は
        //   UPCOMING（個別には進行中でない）なので輪郭は付かない。付くのは HS-RUN の 1 本だけ。
        $this->assertCount(1, $ringed, '輪郭が付くのは進行中（HS-RUN）の 1 本だけ');
    }

    /** ⚠ 不動産側は従来どおり「これから」の棒を薄くする（巻き込み事故の検出） */
    public function test_the_realestate_board_still_dims_future_bars(): void
    {
        $this->caseWithSteps('PRC-FUTURE', [
            ['planned_start' => '2026-10-01', 'planned_end' => '2026-10-31'],
        ]);

        $html   = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->getContent();
        $styles = $this->barStyles($html);
        $dimmed = array_filter($styles, fn ($s) => str_contains($s, 'opacity: 0.45;'));

        $this->assertNotEmpty($styles, '走査の空振りでないこと');
        $this->assertCount(1, $dimmed, '不動産の「これから」の棒は従来どおり薄く出す');
    }

    /**
     * ⚠ **1 枚のボードに実績を持つ親と持たない親を混ぜない。** 静かにどちらかへ倒れるのを防ぐ
     *   （決定 P4）。
     *
     * ⚠ M-1: 例外メッセージにどのボードの組み合わせかを名指しする
     *   （`ScheduleBoardController` の KINDS は複数あるので、「混ぜられません」だけでは
     *   どの設定を直せばいいか分からない）。
     */
    public function test_mixing_tracked_and_untracked_kinds_on_one_board_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('procurement / property');

        app(\App\Services\ScheduleBoardService::class)->build([
            'procurement' => [\App\Models\ReProcurement::class, '仕入れ案件'],
            'property'    => [\App\Models\HsProperty::class, '建売'],
        ], new \Illuminate\Http\Request());
    }

    /**
     * ⚠ M-1: 空と混在は別の例外にする。空は「対象クラスの指定漏れ」であって
     *   「部署をまたいで束ねた」わけではないので、メッセージが違う必要がある。
     */
    public function test_an_empty_kinds_list_is_refused_with_its_own_message(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('対象クラスが空です');

        app(\App\Services\ScheduleBoardService::class)->build([], new \Illuminate\Http\Request());
    }

    // ============================================================
    // 住宅事業ボード — HTML に実際に描画されているか
    // ⚠ **viewData だけでは partial が描いているか分からない。** Task 4 のレビューで
    //   「凡例が行チップと同じ文字列を出すため、行チップを消しても緑のまま」という穴が
    //   見つかった（Bug #43 と同型）。ここでは絞り込みの option を、
    //   実際にレンダリングされた HTML から「value・ラベル」の対で抜き出して見る。
    // ============================================================

    /**
     * 絞り込み「ステータス」の `<option>` を value → ラベルの対で抜き出す。
     *
     * ⚠ **タグ込みで見る。** 素の文字列検索だと「すべて」のように種別・ステータスの
     *   両セレクトに出る語もあり、値が混ざる（Bug #43）。「ステータス: 」の接頭辞は他の
     *   セレクト（種別:）と衝突しないので、これをアンカーにする。
     *   ⚠ ズームの選択肢（表示:）は 2026-09-03 に削除した（設計書 §2 D7）。
     *   ⚠ かつては素の「進行中」が KPI ラベル「進行中の工程」とも衝突していたが、KPI カードは
     *     2026-09-03 に削除した（設計書 §2 D1）。実測（2026-09-03）では、この画面で
     *     「進行中」が出るのはこの `<option>` の 1 箇所だけ。
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptionsInOrder(string $html): array
    {
        preg_match_all('/<option value="(\w+)"[^>]*>ステータス: ([^<]*)<\/option>/u', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($row) => ['value' => $row[1], 'label' => $row[2]], $m);
    }

    public function test_the_housing_status_options_are_actually_rendered(): void
    {
        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertSame(
            [
                ['value' => 'running',  'label' => '進行中'],
                ['value' => 'all',      'label' => 'すべて'],
                ['value' => 'upcoming', 'label' => 'これから'],
                ['value' => 'done',     'label' => '済'],
            ],
            $this->statusOptionsInOrder($html),
            '住宅の絞り込み option が 4 つ・想定の並びで描かれていない'
        );

        $this->assertStringNotContainsString('ステータス: 遅延', $html, '住宅のボードに「遅延」の選択肢が出ている');
    }

    /** ⚠ 不動産側は従来の 4 択のまま描かれることも同じ形で見る（巻き込み事故の検出） */
    public function test_the_realestate_status_options_are_unchanged(): void
    {
        $html = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk()->getContent();

        $this->assertSame(
            [
                ['value' => 'running', 'label' => '進行中'],
                ['value' => 'all',     'label' => 'すべて'],
                ['value' => 'late',    'label' => '遅延'],
                ['value' => 'done',    'label' => '完了'],
            ],
            $this->statusOptionsInOrder($html)
        );
    }

    // ============================================================
    // 消したものが戻らないこと（設計書 §2 D1 / D2 / D7）
    // ============================================================

    /**
     * ⚠ **viewData と HTML の両方を見る。** サービスが `kpi` を返さなくなっても、
     *   Blade が別のところから数字を組み立てて描いてしまう経路を塞ぐ。
     *
     * ⚠ **ラベルの文字列で見る。** 「カードの div が無い」で見ると、意匠を変えただけで
     *   落ちるテストになる。
     */
    public function test_the_boards_no_longer_show_kpi_cards(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        foreach (['/housing/schedules', '/realestate/schedules'] as $url) {
            $response = $this->actingAs($this->manager())->get($url)->assertOk();

            // ⚠ 否定のアサートだけだと、行が 0 件でも「KPI ラベルが無い」は真になって通る
            //   （実測: build() の 'rows' を [] に潰しても緑だった）。描画されていることを先に固定する。
            $this->assertNotEmpty($response->viewData('board')['rows'], "{$url} に案件の行が 1 つも無い");

            $this->assertArrayNotHasKey('kpi', $response->viewData('board'), "{$url} が kpi を返している");

            $html = $response->getContent();

            foreach (['進行中の工程', '進行中の案件', '遅れている案件', '30日以内に始まる工程', '30日以内に終わる工程'] as $label) {
                $this->assertStringNotContainsString($label, $html, "{$url} に KPI ラベル「{$label}」が残っている");
            }
        }
    }

    /**
     * 工程が未登録の案件の件数は**残す**（設計書 §2 D2）。
     *
     * ⚠ KPI を消すついでにこの行まで消す変異を止める。
     */
    public function test_the_unregistered_count_line_survives(): void
    {
        $withSteps = $this->makeParent('property');
        $withSteps->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $this->makeParent('property', ['property_code' => 'HS-002', 'property_name' => '未登録物件']);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertStringContainsString('工程が未登録の案件が 1 件あります', $html);
    }

    /**
     * ズームセレクタは削除した（設計書 §2 D7 / §5）。
     *
     * ⚠ **「効かない」だけでなく「画面に出ていない」ことも見る。** サービスが `zoom` を
     *   無視するようになっても、Blade に `<select name="zoom">` が残っていれば
     *   利用者は押せてしまい、押しても何も起きない画面になる。
     *
     * ⚠ 既存の URL `?zoom=week` は**無視されるだけ**でよい（リダイレクトはしない）。
     */
    public function test_the_zoom_selector_is_gone_and_the_query_key_is_ignored(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $plain = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk();
        $zoomed = $this->actingAs($this->manager())->get('/housing/schedules?zoom=quarter')->assertOk();

        $this->assertArrayNotHasKey('zooms', $plain->viewData('board'), 'zooms がまだ view へ渡っている');
        $this->assertArrayNotHasKey('zoom', $plain->viewData('board')['filters'], 'filters に zoom が残っている');

        $this->assertStringNotContainsString('name="zoom"', $plain->getContent(), 'ズームの select が残っている');
        $this->assertStringNotContainsString('表示: ', $plain->getContent(), 'ズームのラベルが残っている');

        // ⚠ 軸が ?zoom で変わらないこと（無視されている証拠）
        $this->assertSame(
            $plain->viewData('board')['axis']['from'],
            $zoomed->viewData('board')['axis']['from'],
            '?zoom= が軸を変えている'
        );
        $this->assertSame(
            $plain->viewData('board')['axis']['to'],
            $zoomed->viewData('board')['axis']['to'],
            '?zoom= が軸を変えている'
        );
    }
}
