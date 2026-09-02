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

    // ============================================================
    // 行チップ / 凡例チップの抽出ヘルパー
    //
    // ⚠ **凡例は行チップと同じラベル文字列（>これから</span> 等）を出す。** 素の文字列で
    //   見ると、行チップを丸ごと消しても凡例だけで assertStringContainsString が満たされ、
    //   全テストが緑のまま通る（Bug #43。実測で確認済み — レビューで指摘され、実際の
    //   レンダリング結果を突き合わせて below の判別子を選んだ）。
    //
    // ⚠ 判別子は実 HTML を見て決めた（`_schedule_gantt.blade.php` の 2 箇所の <span> の
    //   style を突き合わせ）:
    //   - 行チップ  : `flex: 0 0 auto; font-size: 10px; ... white-space: nowrap; <色>`
    //   - 凡例チップ: `font-size: 10px; font-weight: 700; ... <色>`（`flex:` も
    //     `white-space:` も無い）
    //   行チップは必ず `flex: 0 0 auto;` から始まるので、それを唯一のアンカーにする。
    // ============================================================

    /** @return list<string> 行チップのラベルを出現順で返す（ラベル欄の中。凡例は含まない） */
    private function rowChipLabels(string $html): array
    {
        preg_match_all('/<span style="flex: 0 0 auto; font-size: 10px;[^"]*">([^<]*)<\/span>/u', $html, $m);

        return $m[1];
    }

    /** @return list<string> 凡例チップのラベルを出現順で返す（行チップは含まない） */
    private function legendChipLabels(string $html): array
    {
        preg_match_all('/<span style="font-size: 10px; font-weight: 700;[^"]*">([^<]*)<\/span>/u', $html, $m);

        return $m[1];
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

    /**
     * ⚠ **「チップが出ること」自体は `test_each_housing_row_carries_its_own_state_chip()` が
     *   行の側だけを数えて固定している。ここでは遅延バッジが出ないことだけを見る。**
     *   以前はここでも `assertStringContainsString('>これから</span>', ...)` のように
     *   素の文字列で見ていたが、凡例（`_schedule_gantt.blade.php` の状態の行）が
     *   **同じ `>これから</span>` を出す**ため、行チップを丸ごと削除しても
     *   凡例だけでこのアサートが満たされ緑のまま通ってしまう（Bug #43。レビューで指摘され実測）。
     */
    public function test_the_housing_detail_page_shows_no_delay_badge(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+\d+日/', $html, '住宅に遅延バッジを出さない');
    }

    /**
     * ⚠ **行チップだけを数える。** 凡例チップ（`rowChipLabels()` 冒頭の注記）と混ざらないよう
     *   判別子で分離する。この 1 本が壊れると:
     *   - 行チップの `<span>` を丸ごと削除しても（凡例が残っていれば）他のテストは気づけない
     *   - ラベル欄の `@if($g['tracksActuals'])` を `@if(true)` にして住宅の行が不動産の
     *     分岐へ落ち、チップが全部消えても気づけない（住宅は delayDays が常に 0 なので
     *     遅延バッジも出ず、period テキストが出るだけで見た目上「壊れていない」ように映る）
     */
    public function test_each_housing_row_carries_its_own_state_chip(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertSame(['済', '進行中', 'これから'], $this->rowChipLabels($html), '行チップが 3 個・状態の並び順で出ていない');
    }

    /**
     * ⚠ **チップの色を語と対で固定する。** `$chipStyle` の `running`/`done` を入れ替える変異は、
     *   ラベル文字列だけを見るテスト（`>進行中</span>` の有無等）では検出できない
     *   —— 文字列自体は変わらず色だけが変わるため。行チップの style 属性に色とラベルを
     *   対で埋め込んで見る（実 HTML の実測値そのまま。running/done を入れ替えると
     *   進行中チップが #F3F4F6/#9CA3AF になりこの部分文字列が消える）。
     */
    public function test_the_running_chip_is_paired_with_its_dark_color(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            'background: #111827; color: #fff; border: 1px solid #111827;">進行中</span>',
            $html,
            '進行中チップの色が違う（$chipStyle の running/done 入れ替わりを検出できていない）'
        );
    }

    /**
     * ⚠ **「＋ 工程を追加」を押した直後に毎回通る経路。** `_schedule_section.blade.php` の
     *   `startAdd()` は `planned_start: null, planned_end: null` で工程を作るので、
     *   最も踏みやすい経路なのに一度もテストが実行していなかった（レビュー指摘）。
     *   既存の `ScheduleSectionRenderTest::test_a_step_without_dates_is_listed_with_a_placeholder`
     *   は procurement（実績あり）で書かれているため `$chipStyle` に到達しない。
     *   `$chipStyle['undated']` を消しても fatal にならず（`Undefined array key` の警告＋
     *   素の style）、本番では枠の無いチップが無音で出る。
     */
    public function test_a_housing_step_without_dates_shows_the_undated_chip(): void
    {
        $owner = $this->makeParent('property');
        $owner->scheduleSteps()->create(['name' => '未定の工程', 'category' => 'other', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertSame(['未定'], $this->rowChipLabels($html));
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

        // ⚠ **件数の下限も固定する。** `assertNotEmpty` だけだと「行のラベル欄だけ 262px→260px に
        //   変える」変異が素通りする（月ヘッダの `flex: 0 0 262px;` だけ拾えれば非空を満たすため）。
        //   このフィクスチャ（工程 3 行・自動マイルストーン 0 件）での内訳は月ヘッダ 1 ＋ 行 3 の
        //   4 個（実測で確認済み）。マイルストーンが増えると「節目」ラベル欄も同じ形で 1 個増える。
        $this->assertCount(4, $m[1], 'ラベル欄（flex: 0 0 262px;）の総数が想定と違う（月ヘッダ1 + 行3）');

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

    /**
     * 凡例にも状態の説明が出ること（棒の検査とは別に見る）。
     *
     * ⚠ **`進行中は棒にも輪郭` の文字列だけでは、凡例のチップ 3 個（`@foreach(['upcoming',
     *   'running', 'done'] ...)`）自体は守れない。** その `@foreach` を丸ごと消しても、
     *   直後の兄弟 `<span>`（`進行中は棒にも輪郭`）は無関係に残るので緑のまま通る。
     *   `$g['stateLabels']` のキーを消す変異も同様（`@foreach` の中身が空文字になるだけ）。
     *   凡例チップの個数とラベルを `legendChipLabels()` で別途固定する。
     */
    public function test_the_legend_explains_the_states(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('進行中は棒にも輪郭', $html);
        $this->assertSame(['これから', '進行中', '済'], $this->legendChipLabels($html), '凡例のチップ 3 個が消えている');
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
