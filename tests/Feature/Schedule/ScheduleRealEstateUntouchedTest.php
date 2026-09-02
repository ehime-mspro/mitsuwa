<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleCardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 住宅事業の改修が不動産（仕入れ案件 / 分譲地PJ）を巻き込んでいないこと（設計書 §12 の変異 8）。
 *
 * ⚠ **「住宅が変わった」だけを見ると、共有部品の変更が不動産を壊しても緑のまま通る**（Bug #41）。
 *   共有しているのは ScheduleCardService / ScheduleBoardService / _schedule_gantt /
 *   _schedule_section / _schedule_board / ScheduleStepController の 6 つ。
 *
 * ⚠ 最初の 4 本（カード＋工程 CRUD）は ScheduleBoardService / _schedule_board.blade.php を
 *   一度も実行しない。既存の ScheduleBoardTest と重複するが、共有部品を触ったときの
 *   巻き込みをこのファイル 1 枚でも捕まえられるよう、不動産のボードを 1 本足してある
 *   （末尾の test_the_realestate_board_still_shows_four_kpis_a_delay_badge_and_its_four_way_filter）。
 */
class ScheduleRealEstateUntouchedTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** テスト中の「今日」。⚠ 凍結しないと遅延日数が実行日に依存する */
    private const TODAY = '2026-09-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        CarbonImmutable::setTestNow(self::TODAY);
        \Carbon\Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /** 不動産の 2 親とも、実績で描き・遅延を出し・状態チップを出さない */
    public function test_both_realestate_parents_still_draw_from_actuals_and_report_delays(): void
    {
        foreach (['procurement', 'project'] as $key) {
            $owner = $this->makeParent($key);
            $owner->scheduleSteps()->create([
                'name' => '造成工事', 'category' => 'work',
                'planned_start' => '2026-05-18', 'planned_end' => '2026-08-20',
                'actual_start'  => '2026-06-01', 'actual_end'  => null,
                'sort_order'    => 1,
            ]);

            $card = app(ScheduleCardService::class)->build($owner, CarbonImmutable::parse(self::TODAY));
            $row  = $card['gantt']['rows'][0];

            $this->assertTrue($card['tracksActuals'], "{$key} は実績を扱う");
            $this->assertNull($row['state'], "{$key} に状態チップは付けない");
            $this->assertFalse($row['ring'], "{$key} に輪郭は付けない");
            $this->assertSame(13, $row['delayDays'], "{$key} の遅延は 8/20 から 9/02 で 13 日");
            $this->assertSame('6/01〜9/02', $row['periodText'], "{$key} は実績開始から今日まで描く（設計書 §5.2）");
        }
    }

    /** 不動産の詳細画面は遅延バッジを出し、状態チップを出さない */
    public function test_the_realestate_detail_page_still_shows_the_delay_badge(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-07-01', 'planned_end' => '2026-08-20', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+13日/', $html);
        $this->assertStringNotContainsString('>これから</span>', $html);
        $this->assertStringNotContainsString('box-shadow: 0 0 0 1.5px #111827', $html);
    }

    /** 不動産の工程は実績を受け付け、DB に残る */
    public function test_a_realestate_step_still_accepts_actual_dates_over_http(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())
            ->postJson(route($owner->scheduleStepRoute('store'), $owner), $this->stepInput([
                'actual_start' => '2026-05-11',
                'actual_end'   => '2026-06-05',
            ]))
            ->assertOk();

        $step = $owner->scheduleSteps()->first();
        $this->assertSame('2026-05-11', $step->actual_start->toDateString());
        $this->assertSame('2026-06-05', $step->actual_end->toDateString());
    }

    /** 実績終了だけを送るのは従来どおり弾く（設計書 §4.5） */
    public function test_a_realestate_step_still_rejects_an_end_without_a_start(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())
            ->postJson(route($owner->scheduleStepRoute('store'), $owner), $this->stepInput([
                'actual_start' => null,
                'actual_end'   => '2026-06-05',
            ]))
            // ⚠ M-1: assertStatus(422) だけでは「なぜ 422 か」を見ていない（name 等、別のルールで
            //   落ちても緑になる）。ScheduleStepCrudTest::test_an_actual_end_without_an_actual_start_is_rejected
            //   と同じ流儀で、落ちた項目まで見る。
            ->assertStatus(422)->assertJsonValidationErrors('actual_start');
    }

    // ============================================================
    // (B) 追加 — 不動産のボード（親エージェント指示）。
    // ⚠ 上の 4 本は ScheduleBoardService / _schedule_board.blade.php を一度も実行しない。
    //   既存の ScheduleBoardTest と重複するが、それでよい
    //   （このファイルの趣旨は「共有部品を触ったときの巻き込みを 1 枚で捕まえる」こと）。
    // ============================================================

    /** 不動産のボードは KPI 4 枚・遅延バッジ・4 択の絞り込みを保っている（住宅の 3 種に巻き込まれない） */
    public function test_the_realestate_board_still_shows_four_kpis_a_delay_badge_and_its_four_way_filter(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-07-01', 'planned_end' => '2026-08-20', 'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $board    = $response->viewData('board');
        $html     = $response->getContent();

        // KPI 4 枚（住宅の 3 枚スタイルに巻き込まれていない。ラベルまで見る＝Bug #43）
        $this->assertSame(
            ['進行中の案件', '遅れている案件', '30日以内に始まる工程', '30日以内に終わる工程'],
            array_column($board['kpi'], 'label')
        );

        // 4 択の絞り込み（住宅の 3 択に巻き込まれていない）
        $this->assertSame(
            ['running' => '進行中', 'all' => 'すべて', 'late' => '遅延', 'done' => '完了'],
            $board['statuses']
        );

        // ⚠ M-2: 走査の空振り（PRC-001 の行が無い）を「行が無いので null」と誤読しないよう名指しする
        $row = collect($board['rows'])->firstWhere('code', 'PRC-001');
        $this->assertNotNull($row, 'PRC-001 の行がボードに出ていない');

        // 行自身も遅延を持つ（住宅は delayDays を常に 0 に潰す。ここが不動産だけの経路であること）
        $this->assertSame('late', $row['status']);
        $this->assertSame(13, $row['delayDays']);
        // ⚠ I-2: steps[].delayDays（展開した工程明細の遅延）は row['delayDays']（案件行の遅延の
        //   最大値）と別のサイトで、以前は不動産方向にまったく固定されていなかった（0 に潰しても
        //   1283 全緑）。$s->delayDays($today) と同じ計算なので同じ 13 日。
        $this->assertSame(13, $row['steps'][0]['delayDays'], '工程明細の遅延も残る');

        // ⚠ I-2: bars[].late（遅延した棒の赤枠）を肯定的に見ているテストはアプリ全体に無かった
        //   （`false` に潰しても 1283 全緑）。border: 2px solid #DC2626 はこの画面に 1 箇所しか無い
        //   （grep 済み）ので部分一致の心配は無い。
        $this->assertStringContainsString('border: 2px solid #DC2626', $html, '遅延した棒は赤枠');

        // ⚠ I-1（Bug #43）: 遅延バッジは案件行と工程明細の 2 箇所にあり、
        //   `/color: #DC2626; font-weight: 700[^>]*>\+13日/` は両方に当たるため片方だけ消しても
        //   緑のままだった。役割ごとに分けて見る。
        //   案件行のバッジは margin-left: auto; font-size: 10.5px; を前置きに持つのに対し、
        //   工程明細のバッジは style="color: #DC2626; font-weight: 700;" だけ（この前置きが無い）
        //   ので、正規表現とタグ全体一致で確実に区別できる（どちらも grep 済みでこの画面に 1 箇所）。
        $this->assertMatchesRegularExpression(
            '/margin-left: auto; font-size: 10\.5px; color: #DC2626; font-weight: 700[^>]*>\+13日/',
            $html,
            '案件行の遅延バッジが描かれていない'
        );
        $this->assertStringContainsString(
            '<span style="color: #DC2626; font-weight: 700;">+13日</span>',
            $html,
            '工程明細の遅延バッジが描かれていない'
        );
    }
}
