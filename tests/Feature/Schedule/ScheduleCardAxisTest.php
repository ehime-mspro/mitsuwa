<?php

namespace Tests\Feature\Schedule;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 詳細カードのガントの軸と幅（設計書 §2 D8 / §6）。
 *
 * ⚠ **ボードと規則を揃える。** 今日が軸の外でも軸を伸ばさない。
 */
class ScheduleCardAxisTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

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
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 今日が軸の外（±1 ヶ月のパディングを足してもなお外）なら、軸を伸ばさない。
     *
     * ⚠ 工程は 2026-01-05〜2026-01-20。パディング込みで 2025-12-01〜2026-02-28。
     *   今日は 2026-08-31 なので範囲外。旧実装は `to` を 2026-08-31 まで伸ばしていた。
     */
    public function test_the_card_axis_is_not_stretched_to_today(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $gantt = $response->viewData('schedule')['gantt'];

        $this->assertSame('2025-12-01', $gantt['from'] ?? null, 'パディング込みの始まりが違う');
        $this->assertSame('2026-02-28', $gantt['to'] ?? null, '今日まで軸が伸びている');
        $this->assertNull($gantt['todayPct'], '軸の外なのに今日線を描こうとしている');

        $this->assertStringNotContainsString('dashed #EF4444', $response->getContent(), '今日線が出ている');
    }

    /**
     * ⚠ **今日より前に始まる案件では軸を伸ばさなくても今日が入る**ことも 1 本置く。
     *   「常に null になる」変異（`contains()` を false に固定する等）を止める。
     */
    public function test_a_current_case_still_draws_the_today_line(): void
    {
        $owner = $this->makeParent('procurement', ['procurement_code' => 'PRC-002']);
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $this->assertNotNull($response->viewData('schedule')['gantt']['todayPct']);
        $this->assertStringContainsString('今日 8/31', $response->getContent());
    }

    /**
     * ⚠ **4 親すべてで確かめる**（Bug #44 — 「代表 1 種だけ」だと残り 3 種の経路が
     *   一度も実行されないまま緑になる。過去に 3 回続けて踏んでいる）。カードは
     *   仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の 4 画面すべてに埋め込まれるので、
     *   procurement だけでは足りない。
     *
     * ⚠ **軸（from/to/todayPct）そのものは、4 親を回しても追加の検出力を持たない。**
     *   `ScheduleCardService::gantt()` の日付収集ループ（`$dates` を作る foreach。
     *   ScheduleCardService.php:101-107）は `$step->planned_start` 等の**生カラムを
     *   そのまま読むだけ**で、`$owner` の型はおろか drawStart() / drawEnd() も一切
     *   経由しない。drawStart() / drawEnd() が呼ばれるのは row()（同ファイル 210,216 行）
     *   の中だけで、そこは**軸が確定したあとに**棒の位置(leftPct)・幅(widthPct) を
     *   出す場所であって、軸そのもの（from/to）には影響しない。
     *
     * ⚠ **住宅（property / customOrder）は実績を持たない。** `ScheduleStep::booted()` の
     *   saving フックが `actual_start` / `actual_end` を null 化する（設計書 §3.3）。
     *   ここでは planned と actual に**同じ値**を与えている——軸の日付収集は上記のとおり
     *   生カラムを素通しで集めるだけなので、actual_* が null 化されようがされまいが、
     *   集合には planned_* 側に同じ値が残る。だから min()/max() が変わらず、
     *   4 親とも同じ from/to になる（drawStart() / drawEnd() とは無関係）。
     *
     * ⚠ **このループの本当の価値は軸の外にある。**
     *   ① saving フックによる null 化が**部署ごとに正しく分岐する**こと
     *     （このアサートだけが親ごとに違う結果になる）
     *   ② 共通 trait を持たない **4 つの独立したコントローラ**
     *     （RealEstate\ProcurementController / ProjectController /
     *     Housing\PropertyController / CustomOrderController）が、
     *     いずれも修正済みの ScheduleCardService へ正しく配線されていること。
     *   「4 親を実際に回した」ことそのものは、この 2 つとは別に `$visited` の
     *   突き合わせで固定する（Bug #45 — ループの消費先を procurement 1 種に絞る
     *   変異が実測 0/3 本で素通りしていた）。
     */
    public function test_every_parent_avoids_stretching_the_axis_to_today(): void
    {
        // ⚠ **回した親を記録して最後に突き合わせる**（Bug #45）。これが無いと、
        //   ループの消費先を procurement 1 種に絞る変異（PARENTS 定数自体は 4 件のまま）
        //   が素通りする（実測 2026-09-03: 0/3 本しか落ちなかった）。
        $visited = [];

        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $visited[] = $label;

            $owner = $this->makeParent($label);
            $step  = $owner->scheduleSteps()->create([
                'name' => '決済', 'category' => 'other',
                'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
                'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
                'sort_order' => 1,
            ]);

            // procurement / project は実績を扱うので残る。property / customOrder は
            // saving フックで null 化される（設計書 §3.3）。
            $tracksActuals = in_array($label, ['procurement', 'project'], true);
            if ($tracksActuals) {
                $this->assertSame('2026-01-05', $step->actual_start?->toDateString(), "{$label}: 実績開始が残っていない");
                $this->assertSame('2026-01-20', $step->actual_end?->toDateString(), "{$label}: 実績終了が残っていない");
            } else {
                $this->assertNull($step->actual_start, "{$label}: 実績開始が null 化されていない");
                $this->assertNull($step->actual_end, "{$label}: 実績終了が null 化されていない");
            }

            $response = $this->actingAs($this->manager())
                ->get(route("{$prefix}.show", $owner))
                ->assertOk();

            $gantt = $response->viewData('schedule')['gantt'];

            $this->assertSame('2025-12-01', $gantt['from'] ?? null, "{$label}: パディング込みの始まりが違う");
            $this->assertSame('2026-02-28', $gantt['to'] ?? null, "{$label}: 今日まで軸が伸びている");
            $this->assertNull($gantt['todayPct'], "{$label}: 軸の外なのに今日線を描こうとしている");
            $this->assertStringNotContainsString('dashed #EF4444', $response->getContent(), "{$label}: 今日線が出ている");
        }

        $this->assertSame(array_keys(self::PARENTS), $visited, '4 親すべてを回せていない（Bug #45）');
    }

    /**
     * カードも「ラベル幅 + 月数 × 150px」（設計書 §2 D10）。
     *
     * ⚠ 工程 2026-05-11〜2026-06-05 に ±1 ヶ月のパディングで
     *   2026-04-01 〜 2026-07-31 の 4 ヶ月 = 600px。
     */
    public function test_the_card_track_is_as_wide_as_the_months_it_spans(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $this->assertSame(600, $response->viewData('schedule')['gantt']['trackWidthPx']);

        $html = $response->getContent();
        $this->assertStringContainsString('width: calc(var(--gantt-label-w) + 600px)', $html);
        $this->assertStringNotContainsString('min-width: 940px', $html, '旧い固定 min-width が残っている');
        $this->assertStringContainsString('class="gantt-scroll gantt-scroll--card"', $html, 'カード用のクラスが無い');
    }

    /**
     * 工程名の列が固定表示になっていること。
     *
     * ⚠ **件数の下限も固定する**（Bug #45）。このフィクスチャ（工程 1 行・◆ 0 件）では
     *   月ヘッダ 1 + 行 1 の 2 個。◆ が増えると「節目」のラベル欄も 1 個増える。
     */
    public function test_the_step_name_column_is_sticky(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        preg_match_all('/flex: 0 0 var\(--gantt-label-w\);/', $html, $m);
        $this->assertCount(2, $m[0], 'ラベル欄の数が想定と違う（月ヘッダ 1 + 行 1）');

        $this->assertStringContainsString('class="gantt-label gantt-label--head"', $html);
        $this->assertStringNotContainsString('flex: 0 0 262px', $html, 'px 直書きが残っている');
    }

    /**
     * ⚠ **カードには初期スクロールを入れない**（設計書 §2 D11 / §7.2）。
     *   Ajax 保存のたびにガントを差し替えるので、毎回今日へ跳ぶ画面になる。
     */
    public function test_the_card_has_no_scroll_script(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('scheduleBoardScrollToToday', $html);
    }

    /**
     * ⚠ **縞模様が固定表示の列で切れないこと。**
     *   `.gantt-label` は共有 CSS で `background: #fff` を持つので、行側の
     *   `$loop->odd` の縞模様（#FCFCFD）を**ラベル欄にも**インラインで足さないと、
     *   奇数行だけラベル欄が白く抜ける。
     *   ⚠ `background: inherit` では解決しない —— 行の div の背景は既定 transparent なので
     *   ラベル欄が透けて、スクロールしたとき棒が透けて見える（sticky の意味が消える）。
     *
     * ⚠ **奇数行と偶数行を対で見る**（Bug #43）。片方だけ見ると「全行に付ける」変異も
     *   「全行から消す」変異も見逃す。
     */
    public function test_the_striping_reaches_into_the_sticky_label_column(): void
    {
        $owner = $this->makeParent('procurement');
        foreach ([
            ['1本目', '2026-05-11', '2026-05-15'],
            ['2本目', '2026-05-16', '2026-05-20'],
            ['3本目', '2026-05-21', '2026-05-25'],
        ] as $i => [$name, $s, $e]) {
            $owner->scheduleSteps()->create([
                'name' => $name, 'category' => 'survey',
                'planned_start' => $s, 'planned_end' => $e, 'sort_order' => $i + 1,
            ]);
        }

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        // 工程の行のラベル欄だけを、出現順（1本目・2本目・3本目）に切り出す。
        // ⚠ 月ヘッダ・節目のラベル欄（class="gantt-label" だけで --head も odd 背景も持たない）
        //   と混同しないよう、gap: 6px を持つ「工程の行」のラベル欄だけを対象にする。
        preg_match_all(
            '/<div class="gantt-label" style="flex: 0 0 var\(--gantt-label-w\);[^"]*gap: 6px;[^"]*"/',
            $html,
            $m
        );

        $this->assertCount(3, $m[0], '工程行のラベル欄が 3 個見つからない');

        // 奇数行（1本目=$loop->odd, 3本目=$loop->odd）は縞模様が付き、偶数行（2本目）は付かない。
        $this->assertStringContainsString('background: #FCFCFD;', $m[0][0], '1本目（奇数行）に縞模様が無い');
        $this->assertStringNotContainsString('background: #FCFCFD;', $m[0][1], '2本目（偶数行）に縞模様が付いている');
        $this->assertStringContainsString('background: #FCFCFD;', $m[0][2], '3本目（奇数行）に縞模様が無い');
    }

    /**
     * カードの画面にも共有 CSS が出ていること（設計書 §4.2）。
     *
     * ⚠ **クラス名を見るだけでは守れない。** `class="gantt-scroll gantt-scroll--card"` は
     *   CSS の定義が無くても HTML に出るので、`@include('_partials._schedule_gantt_style')` を
     *   消しても緑のまま通る（実測 2026-09-03: 1302 本すべて緑だった）。定義が消えると
     *   `--gantt-label-w` が未定義になり、`flex: 0 0 var(…)` と `width: calc(var(…) + Npx)` が
     *   **CSS 仕様上まるごと無効**になって sticky も固定幅も崩れる。**定義そのもの**を見ること。
     *
     * ⚠ ボード側の同型テストは
     *   `ScheduleBoardTest::test_the_gantt_style_partial_is_rendered_with_the_label_width_variables`。
     */
    public function test_the_card_page_renders_the_shared_gantt_style(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/\.gantt-scroll--card\s*\{[^}]*--gantt-label-w:\s*262px;/', $html, 'カードの --gantt-label-w(262px) が無い');
        $this->assertMatchesRegularExpression('/\.gantt-label\s*\{[^}]*position:\s*sticky;/', $html, '固定表示の定義が無い');
    }

    /**
     * ⚠ **節目（◆）の行のラベル欄も押し広げられないこと**（Bug #29）。
     *   既存の `ScheduleDateStateTest::test_the_label_column_cannot_be_pushed_wider_than_its_track` と
     *   `test_the_step_name_column_is_sticky` は**どちらも自動 ◆ が 0 件のフィクスチャ**を使っており、
     *   節目の行そのものが描画されないため構造的に検査できていなかった（実測 2026-09-03: 節目行の
     *   `min-width: 0` を落としても 1302 本すべて緑）。◆ が出るフィクスチャで、ヘッダ・節目・工程の
     *   3 つの行型すべてを対で見る。
     *
     * ⚠ `ReProcurement::autoMilestones()` は `contract_date` / `settlement_date` が入っているときだけ
     *   ◆ を返す（`ScheduleSectionRenderTest::MILESTONE_TEST_DATES['procurement']` と同じ値）。
     *
     * ⚠ **件数の下限も固定する**（Bug #45）。ヘッダ 1 ＋ 節目 1 ＋ 工程 1 の 3 個。
     */
    public function test_the_milestone_row_label_cannot_be_pushed_wider_either(): void
    {
        $owner = $this->makeParent('procurement', [
            'contract_date'   => '2026-01-23',
            'settlement_date' => '2026-05-29',
        ]);
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        // ⚠ フィクスチャが実際に節目を描画しているかを先に確かめる。ここが偽なら
        //   下の件数アサートは「節目が無いから 2 個」という別の理由で緑や赤になり得る。
        $this->assertStringContainsString('>節目</div>', $html, '節目の行が描画されていない（フィクスチャが機能していない）');

        preg_match_all('/flex: 0 0 var\(--gantt-label-w\);([^"]*)"/', $html, $m);
        $this->assertCount(3, $m[1], 'ラベル欄の総数が想定と違う（月ヘッダ1 + 節目1 + 工程1）');

        foreach ($m[1] as $style) {
            $this->assertStringContainsString('min-width: 0', $style);
            $this->assertStringContainsString('overflow: hidden', $style);
        }
    }
}
