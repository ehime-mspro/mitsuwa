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
    // 軸はデータの範囲（案B。設計書 §2 D3 / §6）
    // ============================================================

    /**
     * ⚠ **今日は 2026-08-31 に固定してある**（setUp）。旧実装なら軸は
     *   2026-02-01 〜 2027-08-31 の 19 ヶ月になる。案B ではデータの範囲だけ。
     */
    public function test_the_axis_spans_only_the_range_the_bars_occupy(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);
        $proc->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-07-01', 'planned_end' => '2026-07-20', 'sort_order' => 2]);

        $axis = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->viewData('board')['axis'];

        $this->assertSame('2026-05-01', $axis['from'], '軸の始まりが最小の開始月でない');
        $this->assertSame('2026-07-31', $axis['to'], '軸の終わりが最大の終了月でない');
        $this->assertSame(450, $axis['trackWidthPx'], '3 ヶ月 × 150px でない');
    }

    /**
     * ⚠ **絞り込みを変えると軸が変わる**（案B のトレードオフ。設計書 §2 D3）。
     *   意図した挙動なのでテストで固定する。「軸が動くのは不具合」と読んで
     *   全件から軸を出す変異に戻すのを止める。
     */
    public function test_the_axis_follows_the_filter(): void
    {
        $lateCase = $this->makeParent('procurement');
        $lateCase->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);

        $doneCase = $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '完了案件']);
        $doneCase->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $all = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board')['axis'];
        $this->assertSame('2026-01-01', $all['from'], '全件では完了案件まで含めた範囲になるはず');

        // ⚠ **`status=running` にしない。** 測量は予定終了 6/5 が今日（8/31）を過ぎていて
        //   実績が無いので判定は `late`、決済は実績が揃っているので `done` ＝ **running は 0 件**になり、
        //   軸が「今日の 1 ヶ月」フォールバックに落ちて別の理由で緑になる。
        $lateAxis = $this->actingAs($this->manager())->get('/realestate/schedules?status=late')->viewData('board')['axis'];
        $this->assertSame('2026-05-01', $lateAxis['from'], '絞り込み後の案件だけで軸を出していない');
        $this->assertSame('2026-06-30', $lateAxis['to'], '完了案件の範囲が混ざっている');
    }

    /**
     * ⚠ **◆（自動マイルストーン）の日付も軸に入れる**（設計書 §6.1）。
     *   入れないと clamp() で 0% / 100% に貼り付き、**軸の端に嘘の位置で出る**。
     */
    public function test_the_axis_includes_the_auto_milestones(): void
    {
        $prop = $this->makeParent('property', ['construction_start_date' => '2026-02-10']);
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);

        $board = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->viewData('board');

        $this->assertSame('2026-02-01', $board['axis']['from'], '着工予定日が軸に入っていない');

        // ⚠ 端に貼り付いていないこと（0% ちょうどでも 100% ちょうどでもない）
        $left = $board['rows'][0]['milestones'][0]['leftPct'];
        $this->assertGreaterThan(0.0, $left);
        $this->assertLessThan(100.0, $left);
    }

    /**
     * `drawEnd($today)` を使う（生の `planned_end` ではない）（設計書 §6.1）。
     *   実績開始があり実績終了が無い工程は「進行中」で棒が今日まで伸びるので、軸もそこで
     *   打ち切る。生の `planned_end`（まだ先の予定終了日）を使うと、軸が不要に伸びて
     *   案B が解消したはずの「空白だらけ」（設計書 §1.2）に逆戻りする。
     */
    public function test_the_axis_uses_draw_end_not_the_raw_planned_end_for_running_steps(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create([
            'name' => '造成', 'category' => 'work',
            'planned_start' => '2026-01-05', 'planned_end' => '2027-06-30',
            'actual_start'  => '2026-01-05', 'actual_end'   => null,
            'sort_order' => 1,
        ]);

        $axis = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->viewData('board')['axis'];

        $this->assertSame('2026-01-01', $axis['from'], '軸の始まりが実績開始の月でない');
        $this->assertSame('2026-08-31', $axis['to'], '軸の終わりが今日（drawEnd）でなく、まだ先の planned_end になっている');
        $this->assertSame(1200, $axis['trackWidthPx'], '8 ヶ月 × 150px でない（planned_end を使うと 18 ヶ月分に広がる）');
    }

    /**
     * 今日が軸の外なら今日線を描かない（設計書 §2 D8 / §6）。**軸は伸ばさない。**
     *
     * ⚠ **viewData と HTML の両方を見る。** `todayPct` が null でも Blade が
     *   別経路で赤い線を描いていたら意味がない。
     */
    public function test_a_past_only_board_draws_no_today_marker_and_does_not_stretch_the_axis(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $board    = $response->viewData('board');

        $this->assertSame('2026-01-01', $board['axis']['from']);
        $this->assertSame('2026-01-31', $board['axis']['to'], '今日まで軸が伸びている');
        $this->assertNull($board['axis']['todayPct']);

        $html = $response->getContent();
        $this->assertStringNotContainsString('今日 8/31', $html, '今日バッジが出ている');
        $this->assertStringNotContainsString('dashed #EF4444', $html, '今日線が出ている');
    }

    /**
     * 工程はあるが日付が 1 つも無い案件だけが残ったとき（設計書 §6.2）。
     *
     * ⚠ **0 除算で落ちない・案件が画面から消えない**の 2 つを見る。
     *   軸は今日の 1 ヶ月にフォールバックする。
     */
    public function test_a_board_of_undated_steps_falls_back_to_the_current_month(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '日付未定', 'category' => 'work', 'sort_order' => 1]);

        $board = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->viewData('board');

        $this->assertSame('2026-08-01', $board['axis']['from']);
        $this->assertSame('2026-08-31', $board['axis']['to']);
        $this->assertSame(150, $board['axis']['trackWidthPx']);
        $this->assertSame(['HS-001'], array_column($board['rows'], 'code'), '案件が画面から消えている');
        // ⚠ **この行はフォールバックの「値」を守っていない。** 日付未設定の工程は
        //   isDrawable() が false で、position() の $drawable も同じ判定を使うので、
        //   軸が何であっても bars は構造的に必ず [] になる（実測）。
        //   フォールバックの値を守っているのは上の from / to / trackWidthPx の 3 行。
        //   ここで見ているのは「案件の行が消えず 0 除算もしない」ことの副次確認。
        $this->assertSame([], $board['rows'][0]['bars'], '棒が描かれている');
    }

    /**
     * 案件（`re_procurements` / `re_projects` の行）が 1 件も無いとき（設計書 §6.2）。
     *
     * ⚠ **本番の不動産ボードは、今まさにこの状態に近い**（本番の `schedule_steps` は 64 行すべて
     *   建売に紐づき、工程を持つ仕入れ案件・分譲地は 0 件。2026-09-03 の総合レビューで判明）。
     *   既存の `test_cases_without_steps_are_counted_but_not_listed` は「1 件 kept ＋ N 件
     *   unregistered」の**混在**しか見ておらず、`rows` が丸ごと空になるケース（かつ
     *   `unregisteredCount` も 0 のまま）は未テストだった。
     *
     * ⚠ **案B（軸＝データの範囲）では行が 0 件だと軸を作る種が無い。** `scale()` のフォールバック
     *   （今日の 1 ヶ月。設計書 §6.2）が効いて 0 除算にならないこと、「該当する案件がありません。」
     *   が出ること、スクロールのスクリプトが出ないこと（`@else` の中にしかない。行 0 件と
     *   「今日が軸の外」は別の理由だが、どちらも `@else` を通らない点は同じ）を対で見る。
     */
    public function test_a_board_with_no_cases_at_all_falls_back_to_the_current_month(): void
    {
        $response = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk();
        $board    = $response->viewData('board');

        $this->assertSame([], $board['rows'], '案件を 1 件も作っていないのに rows が空でない');
        $this->assertSame(0, $board['unregisteredCount'], '案件を 1 件も作っていないのに未登録件数が 0 でない');

        $this->assertSame('2026-08-01', $board['axis']['from'], 'フォールバック軸の開始が今日の月初でない');
        $this->assertSame('2026-08-31', $board['axis']['to'], 'フォールバック軸の終わりが今日の月末でない');
        $this->assertSame(150, $board['axis']['trackWidthPx'], 'フォールバック軸が 1 ヶ月ぶん(150px)でない');
        $this->assertNotNull($board['axis']['todayPct'], 'フォールバック軸（今日の1ヶ月）は今日を含むはず');

        $html = $response->getContent();
        $this->assertStringContainsString('該当する案件がありません。', $html);
        $this->assertStringNotContainsString('schedule-board-scroller', $html, 'rows が空なのにスクローラーの要素が出ている');
        $this->assertStringNotContainsString('scheduleBoardScrollToToday(', $html, 'rows が空なのにスクロールのスクリプトが出ている');
    }

    /**
     * `headers()` の label / strong / widthPct を固定する。
     *
     * ⚠ **3 つとも無防備だった**（2026-09-03 のレビューで判明）。`label` を `''` に、
     *   `strong` を常に `false` に、`widthPct` を正規化前の日数に、それぞれ潰しても
     *   フルスイートが緑のまま通ることを実測済み。
     *
     * ⚠ **件数も固定する**（Bug #45）。件数を見ないと、ループ境界が壊れて
     *   見出しが 1 つも無くなっても先頭・末尾の値だけ合っていれば緑になる。
     *
     * ⚠ **軸は案B（データの範囲）なのでフィクスチャが軸を決める。**
     *   6/15〜11/10 → 軸 2026-06-01 〜 2026-11-30 ＝ 6 ヶ月（6/7/8/9/10/11 月）。
     *   四半期の頭（1・4・7・10 月）が **7月 と 10月 の 2 つ**入るので、
     *   `strong` の true / false を両方見られる。8/1〜9/30 のような 2 ヶ月の範囲だと
     *   四半期の頭が 1 つも入らず、`strong = true` のカバレッジが消える。
     */
    public function test_the_axis_headers_are_month_labels_with_quarter_emphasis(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-06-15', 'planned_end' => '2026-11-10']]);

        $headers = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis']['headers'];

        $this->assertCount(6, $headers, '軸の月数（データの範囲 6/1〜11/30）が変わっている');

        $this->assertSame(
            ['6月', '7月', '8月', '9月', '10月', '11月'],
            array_column($headers, 'label'),
            '月の見出しが順に出ていない'
        );
        $this->assertSame(
            [false, true, false, false, true, false],
            array_column($headers, 'strong'),
            '四半期の頭（7月 / 10月）だけが強調されるはず'
        );

        // ⚠ widthPct は月の日数に比例するので、全セルの合計が軸の全長（100%）と一致するはず。
        //   ここが崩れると、$next / $end の計算が壊れて棒とヘッダの位置がズレる。
        $sum = array_sum(array_column($headers, 'widthPct'));
        $this->assertEqualsWithDelta(100.0, $sum, 0.01, 'widthPct の合計が軸の全長（100%）と一致しない');
    }

    /**
     * 軸が 12 ヶ月を超えると同じ月名が複数出るので、月ごとに年を持たせる
     * （Codex レビュー Minor 4 / 設計書 §12.2 D13）。
     *
     * ⚠ **既存の `test_the_axis_headers_are_month_labels_with_quarter_emphasis` は
     *   単年 6 ヶ月の軸**なので、年またぎのケースを一度も通らない。ここは別に置く。
     *
     * ⚠ **フィクスチャは 6月 / 7月 が 2 回ずつ出る軸にする。** これが Minor 4 の再現で、
     *   年が無ければ利用者が区別できない状態そのもの。単年の軸で測ると
     *   「年を落としても月名だけで読める」ので、欠陥の再現になっていない。
     */
    public function test_the_axis_headers_carry_the_year_of_every_month(): void
    {
        // 工程 2025-06-15〜2026-07-10 → 軸 2025-06-01〜2026-07-31 ＝ 14 ヶ月
        $this->caseWithSteps('PRC-CROSS', [['planned_start' => '2025-06-15', 'planned_end' => '2026-07-10']]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $headers = $axis['headers'];

        $this->assertSame('2025-06-01', $axis['from'], '軸の始まりが変わっている');
        $this->assertSame('2026-07-31', $axis['to'], '軸の終わりが変わっている');
        $this->assertCount(14, $headers, '年をまたぐ 14 ヶ月の軸になっていない');

        $this->assertSame(
            ['6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月', '4月', '5月', '6月', '7月'],
            array_column($headers, 'label'),
            '同じ月名が 2 回出る軸になっていない（このテストの前提が崩れている）'
        );

        $this->assertSame(
            ['2025', '2025', '2025', '2025', '2025', '2025', '2025',
             '2026', '2026', '2026', '2026', '2026', '2026', '2026'],
            array_column($headers, 'year'),
            '月ごとの年が出ていない（6月 / 7月 が 2 回ずつ出るのに区別できない）'
        );
    }

    /**
     * 開いた直後の横スクロール位置は「今日の前月の 1 日」（設計書 §2 D15 / §12.4）。
     *
     * ⚠ **「今日」をクラス既定（2026-08-31）から `2026-03-31` に上書きするのが load-bearing。**
     *   Carbon の `subMonth()` は月末日で溢れるので、月初へ正規化する**前**に引くと
     *   前月ではなく当月が返る（設計書 §12.5）。8/31 や 9/4 では**どちらの順序でも同じ値**に
     *   なるため、月末以外の日で測るとこの変異は素通りする。
     *   実測（2026-09-04）: 2026-03-31 → 正 2026-02-01 / 誤 2026-03-01。
     *
     * ⚠ **クランプの端（0 / 100）でない値で測る。** 0 や 100 だと「常に 0 を返す」
     *   「常に 100 を返す」変異と区別が付かない。
     */
    public function test_the_initial_scroll_puts_the_first_day_of_last_month_at_the_left_edge(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-03-31');
        \Carbon\Carbon::setTestNow('2026-03-31');

        // ⚠ この「今日」が月末（＝前月に存在しない日）でないと §12.5 の順序ミスが素通りする。
        //   日付を差し替えた瞬間にここで落ちる ＝ 上の docblock の警告を実行可能にするための自己防衛。
        //   （軸は工程の日付だけで決まり「今日」に依存しないので、日を変えても期待値 20.53% は
        //     正しいまま残る。だから検出力だけが無音で消える。実測済み: 2026-03-15 では M1 が緑）
        $t = \Carbon\CarbonImmutable::now();
        $this->assertNotSame(
            $t->subMonth()->startOfMonth()->toDateString(),
            $t->startOfMonth()->subMonth()->toDateString(),
            'この「今日」では 2 通りの順序が同じ値になる＝順序ミスの変異が素通りする（設計書 §12.5）'
        );

        // 工程 2026-01-15〜2026-05-20 → 軸 2026-01-01〜2026-05-31（151 日 / 5 ヶ月）
        $this->caseWithSteps('PRC-MONTHEND', [['planned_start' => '2026-01-15', 'planned_end' => '2026-05-20']]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $this->assertSame('2026-01-01', $axis['from'], 'このテストが前提にしている軸が変わっている');
        $this->assertSame('2026-05-31', $axis['to'], 'このテストが前提にしている軸が変わっている');
        $this->assertSame(750, $axis['trackWidthPx'], '5 ヶ月 × 150px でない');

        // 2026-02-01 は軸の 31 日目 → 31 / 151 * 100 = 20.529801324503%
        // ⚠ 順序を逆にすると 2026-03-01（59 日目）= 39.072847682119% になる
        $this->assertEqualsWithDelta(
            20.529801324503,
            $axis['initialPct'],
            0.0001,
            '初期スクロールが「前月の 1 日」でない（39.07 なら前月になっていない＝順序ミスか subMonth() の欠落。設計書 §12.5）'
        );
    }

    /**
     * ⚠ **今日が軸より後でもスクロールする**（設計書 §12.4）。
     *   従来（D9）は今日が軸の外ならスクリプトごと出さず左端＝一番古い月に留まっていた。
     *   工程表は「現状の工程を確認するもの」なので、直近の月が見えるほうが妥当。
     */
    public function test_the_initial_scroll_clamps_to_the_right_edge_when_today_is_after_the_axis(): void
    {
        // 工程は 2026-01 で終わり。今日（2026-08-31）は軸の外。
        $this->caseWithSteps('PRC-PAST', [[
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
        ]]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $this->assertSame('2026-01-01', $axis['from']);
        $this->assertSame('2026-01-31', $axis['to']);
        $this->assertNull($axis['todayPct'], 'このフィクスチャでは今日が軸の外のはず');
        $this->assertSame(100.0, $axis['initialPct'], '軸より後の今日が右端（100）にクランプされていない');
    }

    /** ⚠ 今日が軸より前なら 0%（＝左端。従来と同じ見え方）。上の 100 と**対で**置く。 */
    public function test_the_initial_scroll_clamps_to_the_left_edge_when_today_is_before_the_axis(): void
    {
        // 工程は 2026-10〜12。今日（2026-08-31）の前月 2026-07-01 は軸より前。
        $this->caseWithSteps('PRC-FUTURE', [['planned_start' => '2026-10-05', 'planned_end' => '2026-12-20']]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $this->assertSame('2026-10-01', $axis['from']);
        $this->assertSame('2026-12-31', $axis['to']);
        $this->assertNull($axis['todayPct'], 'このフィクスチャでは今日が軸の外のはず');
        $this->assertSame(0.0, $axis['initialPct'], '軸より前の今日が左端（0）にクランプされていない');
    }

    // ============================================================
    // トラックの幅と案件名の固定表示（設計書 §3 / §4）
    // ============================================================

    /**
     * ⚠ **CSS はレンダリング済み HTML で見る**（先行例 `CustomOrderIndexListColumnsTest`）。
     *   `app.css` に置くとビルド済み CSS が worktree に無く（`.gitignore`）テストで測れない。
     */
    public function test_the_gantt_style_partial_is_rendered_with_the_label_width_variables(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/\.gantt-scroll\s*\{[^}]*--gantt-label-w:\s*320px;/', $html, 'ボードの --gantt-label-w(320px) が無い');
        $this->assertMatchesRegularExpression('/\.gantt-scroll--card\s*\{[^}]*--gantt-label-w:\s*262px;/', $html, 'カードの --gantt-label-w(262px) が無い');
        $this->assertMatchesRegularExpression('/\.gantt-label\s*\{[^}]*position:\s*sticky;/', $html, 'ラベル欄が sticky でない');
        $this->assertMatchesRegularExpression('/\.gantt-label\s*\{[^}]*left:\s*0;/', $html, 'ラベル欄の left が 0 でない');

        // ⚠ **`.gantt-label--head` を丸ごと消しても検出できなかった**（実測 2026-09-03: 0/119）。
        //   消えると①ヘッダの背景が白になり行の #F9FAFB と食い違う②z-index が本文行のラベル
        //   （同じく 5）と同値になりスタッキング順が DOM 順依存の不定挙動になる。
        $this->assertMatchesRegularExpression('/\.gantt-label--head\s*\{[^}]*z-index:\s*6;/', $html, 'ヘッダのラベル欄の z-index が無い');
        $this->assertMatchesRegularExpression('/\.gantt-label--head\s*\{[^}]*background:\s*#F9FAFB;/', $html, 'ヘッダのラベル欄の背景が無い');

        // ⚠ **box-shadow は「ここから先はスクロールする」ことを示す唯一の視覚的手掛かり。**
        //   消しても検出できなかった（実測 2026-09-03: 0/119）。
        $this->assertMatchesRegularExpression('/\.gantt-label\s*\{[^}]*box-shadow:\s*6px 0 6px -6px rgba\(0, 0, 0, 0\.18\);/', $html, 'スクロール可能を示す box-shadow が無い');

        // ⚠ **メディアクエリは `.gantt-scroll--card` の実ルールより後ろでなければならない。**
        //   詳細度が同じ (0,1,0) なので後勝ち。前に置くとカードだけ 262px のまま残る。
        // ⚠ **`strpos($html, '.gantt-scroll--card')` で探してはいけない** ——
        //   partial の警告コメント自身が同じ文字列を含むので、コメント側（@media より前）を
        //   拾って**常に真になる**（実測 2026-09-03: 本物のルールだけを @media の後ろへ
        //   移す変異が 41 本すべて緑だった。Bug #42 ②）。宣言 `.gantt-scroll--card {` に限定する。
        $this->assertMatchesRegularExpression('/\.gantt-scroll--card\s*\{/', $html, '.gantt-scroll--card の実ルールが無い');
        preg_match('/\.gantt-scroll--card\s*\{/', $html, $cardMatch, PREG_OFFSET_CAPTURE);
        $card  = $cardMatch[0][1];
        $media = strpos($html, '@media (max-width: 640px)');
        $this->assertNotFalse($media, '@media (max-width: 640px) が見つからない');
        $this->assertGreaterThan($card, $media, 'メディアクエリが .gantt-scroll--card の実ルールより前にある');
        $this->assertMatchesRegularExpression('/@media \(max-width: 640px\)\s*\{[^}]*\.gantt-scroll\s*\{[^}]*--gantt-label-w:\s*140px;/', $html, 'モバイル用の --gantt-label-w(140px) が無い');
    }

    /**
     * 年の見た目は共有 partial が**唯一の定義**として持つ（設計書 §12.4）。
     *
     * ⚠ **`font-size: 9.5px` を needle にしない。** 今日のピル（`今日 8/31`）が
     *   ボード・カードとも同じ 9.5px をインラインで持っており、部分一致で false-pass する
     *   （実測 2026-09-04: `_schedule_board:67` と `_schedule_gantt:48`。Bug #43）。
     *
     * ⚠ **宣言がちょうど 1 つであることまで見る。** 片方の Blade へインラインで複製する
     *   変更（`_map_style.blade.php` の AREA_MAP_STYLES で実際に踏んだ型）を止める。
     */
    public function test_the_year_style_is_declared_exactly_once_on_the_board(): void
    {
        $this->caseWithSteps('PRC-STYLE', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $html = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->getContent();

        // 宣言の個数は**セレクタ**で数える。
        // ⚠ `{` をアンカーにしない —— セレクタリスト（`.gantt-year, .x { … }`）を取りこぼす
        //    （RULES「Tailwind 監査の落とし穴 3」）。実測 2026-09-04: その形の複製を
        //    _schedule_gantt.blade.php へ足すと全 61 本が緑のまま通り、しかも後勝ちで
        //    実行時にはその複製が勝つ。
        // ⚠ `(?![\w-])` は `.gantt-year-foo` への前方一致を防ぐ。`class="gantt-year"` は
        //    先頭のドットが無いので元から当たらない。
        // ⚠ **この形にすると、注意書きの中で `.gantt-year` と書いた瞬間に個数が狂う**
        //    （設計書 §9.5 の自己参照の罠が「値」から「個数」へ移る）。共有 partial の
        //    コメントにクラス名を書かないこと。
        preg_match_all('/\.gantt-year(?![\w-])/', $html, $m);
        $this->assertCount(1, $m[0], '年のスタイル宣言が 1 つでない（共有 partial 以外にも定義がある）');

        // ⚠ 3 宣言を 1 本の正規表現で連結して見ない —— CSS の宣言順に意味は無いので、
        //    並べ替えただけで「D13 と違う」と嘘の理由で赤くなる（実測）。役割ごとに分ける。
        $this->assertSame(1, preg_match('/\.gantt-year[^{]*\{([^}]*)\}/', $html, $r), '年のスタイルのブロックを切り出せない');
        $this->assertMatchesRegularExpression('/font-size:\s*9\.5px;/', $r[1], '年の文字サイズが D13 と違う');
        $this->assertMatchesRegularExpression('/color:\s*#9CA3AF;/', $r[1], '年の色が D13 と違う');
        $this->assertMatchesRegularExpression('/margin-right:\s*3px;/', $r[1], '年と月名の間隔が D13 と違う');
    }

    /**
     * ⚠ **`gantt-scroll` は `--gantt-label-w` のスコープそのもの。**
     *   このクラスが外れると子孫の `calc(var(--gantt-label-w) + Npx)` と
     *   `flex: 0 0 var(--gantt-label-w)` が**未定義のカスタムプロパティ参照で丸ごと無効**になり、
     *   固定幅も横スクロールも効かなくなる（＝ D4 が壊れる）。
     *   ⚠ **「文字列がページのどこかに在るか」では守れない** ——
     *   CSS ルール `.gantt-scroll { … }` は `<style>` の中に残るので、
     *   **id と class が同じタグに共起すること**を見る必要がある
     *   （実測 2026-09-03: class だけ落とす変異が 119 本すべて緑だった）。
     */
    public function test_the_scroller_div_carries_the_css_variable_scoping_class(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<div id="schedule-board-scroller" class="gantt-scroll" style="overflow-x: auto;">',
            $html,
            'gantt-scroll クラスが外れると --gantt-label-w が未定義になり calc() が丸ごと無効化される'
        );
    }

    /**
     * トラックの幅は「ラベル幅 + 月数 × 150px」（設計書 §3）。
     *
     * ⚠ **`min-width` が残っていないことも見る。** 残ると狭い画面で意図しない下限ができる。
     */
    public function test_the_track_is_as_wide_as_the_months_it_spans(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-07-20', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();

        $this->assertSame(450, $response->viewData('board')['axis']['trackWidthPx']);

        $html = $response->getContent();
        $this->assertStringContainsString('width: calc(var(--gantt-label-w) + 450px)', $html);
        $this->assertStringNotContainsString('min-width: 1000px', $html, '旧い固定 min-width が残っている');
    }

    /**
     * 案件名の列が固定表示になっていること。
     *
     * ⚠ **件数の下限も固定する**（走査が空振りして緑になる事故を防ぐ。Bug #45）。
     *   このフィクスチャ（案件 1 件）ではヘッダ 1 + 行 1 の 2 個。
     *
     * ⚠ **`flex: 0 0 320px` のような px 直書きが残っていないことも見る。**
     *   残ると CSS 変数が効かず、モバイルで 140px にならない。
     *
     * ⚠ **`min-width: 0` と `overflow: hidden` を落とさない**（Bug #29）。
     *   flex の min-width は既定が auto なので、案件名・種別バッジ・遅延バッジという
     *   可変長の中身に押し広げられ、**その行の棒だけ右へずれる**（月ヘッダは
     *   var(--gantt-label-w) のままなので月境界とも合わなくなる）。
     *   ⚠ HTML では位置ズレを測れないので属性で固定する。
     *   ⚠ 実測（2026-09-03）: この 2 行が無いと、ヘッダ側・行側のどちらから
     *     `min-width: 0` を落としても 1292 本すべて緑のまま通った。
     */
    public function test_the_case_name_column_is_sticky_and_sized_by_the_css_variable(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertStringContainsString('class="gantt-label gantt-label--head"', $html, 'ヘッダのラベル欄にクラスが無い');
        $this->assertStringContainsString('class="gantt-label"', $html, '行のラベル欄にクラスが無い');
        $this->assertStringNotContainsString('flex: 0 0 320px', $html, 'px 直書きが残っている');

        preg_match_all('/flex: 0 0 var\(--gantt-label-w\);([^"]*)"/', $html, $styles);
        $this->assertCount(2, $styles[1], 'ラベル欄の style を 2 つ拾えていない（ヘッダ 1 + 行 1）');

        foreach ($styles[1] as $style) {
            $this->assertStringContainsString('min-width: 0', $style);
            $this->assertStringContainsString('overflow: hidden', $style);
        }
    }

    /**
     * 開いた直後に今日が見える位置までスクロールしておく（設計書 §2 D9 / §7.1）。
     *
     * ⚠ **定義側と呼び出し側を対で見る。** 片方だけ消えても HTML としては妥当なので、
     *   呼び出しだけ見ると「関数が無いのに緑」になる（Bug #28）。
     *
     * ⚠ **`@push('scripts')` が実際に出ていることまで見る。** `@stack` が無い時代は
     *   push した中身が黙って捨てられていた（Bug #28）。
     */
    public function test_the_board_scrolls_to_today_on_open(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $html     = $response->getContent();
        $axis     = $response->viewData('board')['axis'];

        $this->assertNotNull($axis['todayPct'], 'このフィクスチャでは今日が軸の中にあるはず');

        // 定義側
        $this->assertStringContainsString('function scheduleBoardScrollToToday(id, pct, trackPx)', $html);
        // 呼び出し側(引数は実際の値で出ていること)
        $this->assertStringContainsString(
            "scheduleBoardScrollToToday('schedule-board-scroller', {$axis['todayPct']}, {$axis['trackWidthPx']});",
            $html
        );
        // スクロール先の要素
        $this->assertStringContainsString('id="schedule-board-scroller"', $html);

        // ⚠ **スクリプトはスクローラーの HTML より後ろに出ていなければならない**（Bug #28）。
        //   @push('scripts') を @push('styles') に押し間違えると <head> 側に出るため、
        //   body の描画前に走って document.getElementById() が null を返し、
        //   if (! el) return; のガードで**無音でスクロールが起きない**（コンソールエラーも出ない）。
        //   ⚠ **文字列の存在を見るだけでは検出できない** —— 内容は消えず場所が変わるだけなので、
        //     実測（2026-09-03）では styles へ押し間違える変異が 2 本とも緑のまま通った。
        $scrollerPos = strpos($html, 'id="schedule-board-scroller"');
        $scriptPos   = strpos($html, 'function scheduleBoardScrollToToday');
        $this->assertNotFalse($scrollerPos, 'スクローラーの要素が無い');
        $this->assertNotFalse($scriptPos, 'スクロールの関数定義が無い');
        $this->assertGreaterThan(
            $scrollerPos,
            $scriptPos,
            'スクロールのスクリプトがスクローラーより前に出ている（@push の宛先が scripts でない可能性）'
        );
    }

    /**
     * ⚠ 今日が軸の外なら**スクロールのスクリプトごと出さない**（設計書 §7.1）。
     *   `pct` が null のまま出すと `scrollLeft = NaN` になる。
     */
    public function test_no_scroll_script_when_today_is_outside_the_axis(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->getContent();

        $this->assertStringNotContainsString('scheduleBoardScrollToToday', $html);
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

    /**
     * 行のキーが 12 種ちょうどであることを固定する（`meta()` の 8 ＋ `position()` の 4）。
     *
     * ⚠ **これは「キーの追加・削除・改名」を止めるテストであって、衝突は検出しない。**
     *   `position()` は `$meta + [...]`（**左辺優先**。`array_merge()` と左右が逆）で合成するため、
     *   将来 `meta()` に `position()` と同名のキーが増えると、`position()` 側の値が
     *   例外も Warning も無く黙って捨てられる。だが**キーの集合は変わらない**ので
     *   `array_keys()` を見るこのテストは緑のまま通る（2026-09-03 に `meta()` へ
     *   `'bars' => []` を足す変異を当てて実測: このテストは落ちない）。
     *
     * ⚠ **衝突を実際に捕まえているのは、`bars` の中身を見ている次の 5 本**（同じ実測で確認）:
     *     ScheduleBoardTest::test_overlapping_steps_are_spread_across_lanes
     *     ScheduleBoardTest::test_the_housing_board_bars_are_never_dimmed
     *     ScheduleBoardTest::test_the_housing_board_puts_a_ring_only_on_the_running_bar
     *     ScheduleBoardTest::test_the_realestate_board_still_dims_future_bars
     *     ScheduleRealEstateUntouchedTest::test_the_realestate_board_keeps_its_delay_badge_and_four_way_filter
     *   ⚠ **この 5 本を減らすときは、衝突の守り手が消えることを思い出すこと。**
     */
    public function test_a_row_has_exactly_the_twelve_keys_meta_and_position_produce(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $row = collect(
            $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board')['rows']
        )->firstWhere('code', 'PRC-RUN');

        $this->assertEqualsCanonicalizing(
            ['kind', 'kindLabel', 'code', 'name', 'url', 'status', 'delayDays', 'steps',
             'laneCount', 'rowHeight', 'bars', 'milestones'],
            array_keys($row),
            'meta() と position() のキーが 12 種で揃っていない（衝突の可能性）'
        );
    }

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
