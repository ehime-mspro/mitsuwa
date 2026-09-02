<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleCardService;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 住宅事業の工程表は状態（これから / 進行中 / 済）を日付だけで出し、遅延を出さない（設計書 §4）。
 *
 * ⚠ **不動産が変わっていないことも対で見る。** 「住宅が変わった」だけを見ると、
 *   共有部品の変更が不動産を壊しても緑のまま通る（Bug #41）。
 */
class ScheduleDateStateTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** テスト中の「今日」。すべての日付をこの日基準で置く。 */
    private const TODAY = '2026-09-02';

    /**
     * ⚠ **「今日」を固定する。** HTTP 経由の描画は `ScheduleCardService` が
     *   `CarbonImmutable::today()` に落ちるので、凍結しないと**実行日に依存**する
     *   （このクラスの注意書きと同じ失敗を、テスト側で作らないこと）。
     */
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

    /** 済 / 進行中 / これから の 3 本を持つ建売物件 */
    private function housingWithThreeStates(): \App\Models\HsProperty
    {
        $owner = $this->makeParent('property');

        foreach ([
            ['済です',     '2026-05-01', '2026-05-20'],
            ['進行中です', '2026-08-20', '2026-09-30'],
            ['これからです', '2026-10-01', '2026-10-05'],
        ] as $i => [$name, $s, $e]) {
            $owner->scheduleSteps()->create([
                'name' => $name, 'category' => 'work',
                'planned_start' => $s, 'planned_end' => $e, 'sort_order' => $i + 1,
            ]);
        }

        return $owner;
    }

    private function card(\Illuminate\Database\Eloquent\Model $owner): array
    {
        return app(ScheduleCardService::class)->build($owner, CarbonImmutable::parse(self::TODAY));
    }

    public function test_housing_rows_carry_a_state_derived_from_the_dates(): void
    {
        $card = $this->card($this->housingWithThreeStates());

        $this->assertFalse($card['tracksActuals'], '住宅は実績を扱わない');
        $this->assertFalse($card['gantt']['tracksActuals']);

        $this->assertSame(
            [ScheduleStepStatus::DONE, ScheduleStepStatus::RUNNING, ScheduleStepStatus::UPCOMING],
            array_column($card['gantt']['rows'], 'state')
        );
        $this->assertSame(['済', '進行中', 'これから'], array_column($card['gantt']['rows'], 'stateLabel'));
    }

    /** 進行中の棒だけ輪郭を出す（案B′。モックで確定） */
    public function test_only_the_running_row_asks_for_a_ring(): void
    {
        $rows = $this->card($this->housingWithThreeStates())['gantt']['rows'];

        $this->assertSame([false, true, false], array_column($rows, 'ring'));
    }

    /**
     * ⚠ **住宅では遅延を 0 にする。** 実績を取り込まない仕様なので、予定終了を過ぎた工程は
     *   全部「遅延」になってしまう（本番で 64 本中 57 本が赤くなった）。
     */
    public function test_housing_rows_never_report_a_delay(): void
    {
        $rows = $this->card($this->housingWithThreeStates())['gantt']['rows'];

        $this->assertSame([0, 0, 0], array_column($rows, 'delayDays'));
    }

    /** ⚠ 不動産は従来どおり遅延を出し、状態チップは出さない（巻き込み事故の検出。Bug #41） */
    public function test_realestate_still_reports_delays_and_gets_no_state(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-07-01', 'planned_end' => '2026-08-20', 'sort_order' => 1,
        ]);

        $card = $this->card($owner);

        $this->assertTrue($card['tracksActuals']);
        $this->assertTrue($card['gantt']['tracksActuals']);
        $this->assertSame(13, $card['gantt']['rows'][0]['delayDays'], '8/20 から 9/02 で 13 日');
        $this->assertNull($card['gantt']['rows'][0]['state'], '不動産に状態は付けない');
        $this->assertFalse($card['gantt']['rows'][0]['ring']);
    }

    // ============================================================
    // 画面（HTML）— 「配列に載った」だけでは足りない
    // ============================================================

    public function test_the_housing_detail_page_shows_state_chips_and_no_delay_badge(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        // ⚠ タグ込みで見る。素の「進行中」はボードの KPI 名などにも前方一致する（Bug #43）
        $this->assertStringContainsString('>これから</span>', $html);
        $this->assertStringContainsString('>進行中</span>', $html);
        $this->assertStringContainsString('>済</span>', $html);
        $this->assertDoesNotMatchRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+\d+日/', $html, '住宅に遅延バッジを出さない');
    }

    /**
     * ⚠ **ラベル欄の min-width: 0 を落とさない。** flex の min-width は既定が auto なので、
     *   チップで押し広げられた行は 262px を超え、**その行の棒だけ最大 31.1px（約 12.6 日）
     *   ずれる**（モックで実測。Bug #29）。HTML では位置ズレを測れないので属性で固定する。
     */
    public function test_the_label_column_cannot_be_pushed_wider_than_its_track(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        preg_match_all('/flex: 0 0 262px;([^"]*)"/', $html, $m);

        $this->assertNotEmpty($m[1], 'ラベル欄が 1 つも見つからない（走査の空振り）');
        foreach ($m[1] as $style) {
            $this->assertStringContainsString('min-width: 0', $style);
            $this->assertStringContainsString('overflow: hidden', $style);
        }
    }

    /**
     * ⚠ **棒の側だけを数える。** 同じ box-shadow は凡例のサンプルにも 1 つ出るので、
     *   HTML 全体で数えると凡例を消しても緑のまま通る（Bug #43 の型）。
     */
    public function test_the_running_bar_carries_the_ring_in_the_html(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        preg_match_all('/position: absolute; top: 11px; height: 12px;[^"]*/', $html, $bars);
        $ringed = array_filter($bars[0], fn ($s) => str_contains($s, 'box-shadow: 0 0 0 1.5px #111827'));

        $this->assertCount(3, $bars[0], '棒が 3 本描かれている');
        $this->assertCount(1, $ringed, '輪郭が付くのは進行中の 1 本だけ');
    }

    /** 凡例にも状態の説明が出ること（棒の検査とは別に見る） */
    public function test_the_legend_explains_the_states(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('進行中は棒にも輪郭', $html);
    }

    // ============================================================
    // 編集表の実績 2 列
    // ============================================================

    public function test_the_housing_edit_table_has_no_actual_columns(): void
    {
        $owner = $this->makeParent('property');
        $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('>実績開始<', $html);
        $this->assertStringNotContainsString('>実績終了<', $html);
        $this->assertStringNotContainsString('x-model="row.actual_start"', $html);
        $this->assertStringNotContainsString('x-model="row.actual_end"', $html);
        $this->assertStringContainsString('>予定開始<', $html, '予定の列は残る');
    }

    /** ⚠ 不動産の編集表は 4 列のまま（巻き込み事故の検出） */
    public function test_the_realestate_edit_table_keeps_the_actual_columns(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('>実績開始<', $html);
        $this->assertStringContainsString('>実績終了<', $html);
        $this->assertStringContainsString('x-model="row.actual_start"', $html);
    }
}
