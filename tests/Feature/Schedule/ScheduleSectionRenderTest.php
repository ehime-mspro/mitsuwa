<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 詳細ページの工程表カード（設計書 §4.1）。
 *
 * ⚠ **4 画面すべてを開く。** 共通 partial なので 1 画面で足りると思いがちだが、
 *   `@include` の位置と親の autoMilestones() は画面ごとに違う（設計書 §10-6）。
 *
 * ⚠ **partial の定義が 1 箇所であることも固定する。** 同じマークアップを 4 箇所にコピーすると
 *   一部だけ直す事故が起きる（Bug #41 / 地図 POI の件で実測済み）。
 */
class ScheduleSectionRenderTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const SECTION_PARTIAL = 'resources/views/_partials/_schedule_section.blade.php';

    private const GANTT_PARTIAL   = 'resources/views/_partials/_schedule_gantt.blade.php';

    /** 4 つの show.blade.php */
    private const SHOW_VIEWS = [
        'resources/views/realestate/procurements/show.blade.php',
        'resources/views/realestate/projects/show.blade.php',
        'resources/views/housing/properties/show.blade.php',
        'resources/views/housing/custom-orders/show.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 4 親の詳細 URL */
    private function showUrl(string $label, \Illuminate\Database\Eloquent\Model $owner): string
    {
        return route(self::PARENTS[$label][1] . '.show', $owner);
    }

    // ============================================================
    // 描画
    // ============================================================

    public function test_every_detail_page_renders_the_schedule_card(): void
    {
        foreach (array_keys(self::PARENTS) as $label) {
            $owner = $this->makeParent($label);
            $owner->scheduleSteps()->create([
                'name' => '建築確認申請', 'category' => 'permit',
                'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1,
            ]);

            $html = $this->actingAs($this->manager())->get($this->showUrl($label, $owner))
                ->assertOk()->getContent();

            $this->assertStringContainsString('工程表', $html, "{$label}: カードの見出しが無い");
            $this->assertStringContainsString('建築確認申請', $html, "{$label}: 工程名が出ていない");
            $this->assertStringContainsString('id="schedule-gantt"', $html, "{$label}: ガントの入れ物が無い");
        }
    }

    /**
     * ⚠ **工程 0 件のとき、空のガントを描かず案内文を出すこと**（設計書 §5.5）。
     *   日付が 1 つも無い案件は時間軸を作れない（0 除算とレイアウト崩れの両方を防ぐ）。
     */
    public function test_a_case_without_steps_shows_a_notice_instead_of_an_empty_gantt(): void
    {
        foreach (array_keys(self::PARENTS) as $label) {
            $owner = $this->makeParent($label);

            $html = $this->actingAs($this->manager())->get($this->showUrl($label, $owner))
                ->assertOk()->getContent();

            $this->assertStringContainsString('工程が登録されていません', $html, "{$label}: 案内文が無い");
            $this->assertStringNotContainsString('schedule-gantt-track', $html, "{$label}: 空のガントを描いている");
        }
    }

    /**
     * ⚠ **日付が 1 つも無い工程は一覧に残す**（設計書 §3.7）。
     *   黙って消すと利用者は「保存できていない」と誤解する。
     */
    public function test_a_step_without_dates_is_listed_with_a_placeholder(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create(['name' => '未定の工程', 'category' => 'other', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get($this->showUrl('procurement', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('未定の工程', $html, '日付が無い工程を画面から消してはいけない');
        $this->assertStringContainsString('日付未設定', $html, '期間欄に「日付未設定」を出すこと');
    }

    /** 自動マイルストーンが画面に出ること（設計書 §3.4） */
    public function test_auto_milestones_are_drawn_from_the_existing_date_columns(): void
    {
        $owner = $this->makeParent('procurement', [
            'contract_date'   => '2026-01-23',
            'settlement_date' => '2026-05-29',
        ]);
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-02-01', 'planned_end' => '2026-03-01', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())->get($this->showUrl('procurement', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('契約', $html);
        $this->assertStringContainsString('決済', $html);
    }

    // ============================================================
    // 権限（画面側）
    // ============================================================

    /**
     * staff は閲覧できるが編集 UI は出ない。
     *
     * ⚠ **「無いこと」は生 HTML で見る。** Ajax の URL が消えていることを直接確かめる
     *   （ボタンの文言だけ見ると別の場所の同じ語に一致しうる）。
     */
    public function test_a_staff_user_sees_the_gantt_but_no_editing_ui(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-02-01', 'planned_end' => '2026-03-01', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->staff())->get($this->showUrl('procurement', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('測量', $html, 'staff もガントは見られる');
        $this->assertStringNotContainsString(
            route('realestate.procurements.schedule-steps.store', $owner),
            $html,
            'staff の画面に工程追加のエンドポイントが出ている'
        );
    }

    // ============================================================
    // 画面が描いたエンドポイントを、そのまま送り返す（設計書 §8.2 / プラン 決定 B）
    // ============================================================

    /**
     * ⚠ **URL をテスト側で route() から組み立ててはいけない。** 画面側の配線が壊れても
     *   緑のまま通る（Bug #47 / #54 ②）。**画面が出力した設定を抜き出して、それを叩く。**
     */
    private function endpointsFromPage(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/var SCHEDULE_ENDPOINTS = (\{.*?\});/s',
            $html,
            '画面がエンドポイント設定を出力していない'
        );

        preg_match('/var SCHEDULE_ENDPOINTS = (\{.*?\});/s', $html, $m);
        $endpoints = json_decode($m[1], true);

        $this->assertIsArray($endpoints, 'SCHEDULE_ENDPOINTS が JSON として読めない');

        return $endpoints;
    }

    public function test_the_endpoints_the_page_emits_actually_work(): void
    {
        foreach (array_keys(self::PARENTS) as $label) {
            $owner   = $this->makeParent($label);
            $manager = $this->manager();

            $html      = $this->actingAs($manager)->get($this->showUrl($label, $owner))->getContent();
            $endpoints = $this->endpointsFromPage($html);

            // 追加
            $created = $this->actingAs($manager)
                ->postJson($endpoints['store'], $this->stepInput())
                ->assertOk()->json('step.id');

            // 更新（__ID__ を実 id に差し替えるのは JS と同じ手順）
            $this->actingAs($manager)->patchJson(
                str_replace('__ID__', (string) $created, $endpoints['update']),
                $this->stepInput(['name' => '差し替え後'])
            )->assertOk();

            $this->assertSame('差し替え後', ScheduleStep::findOrFail($created)->name, "{$label}: update の URL が違う");

            // 削除
            $this->actingAs($manager)->deleteJson(
                str_replace('__ID__', (string) $created, $endpoints['destroy'])
            )->assertOk();

            $this->assertNull(ScheduleStep::find($created), "{$label}: destroy の URL が違う");
        }
    }

    /**
     * ⚠ **CSRF トークンの meta が出ていること。**
     *   `@csrf` / `_token` の欠落は Feature テストでは原理的に挙動から検出できない
     *   （`VerifyCsrfToken::handle()` が `runningUnitTests()` で素通りする）。
     *   描画されていることを見るのが唯一の手（Bug #47）。
     */
    public function test_the_page_exposes_a_csrf_token_for_the_ajax_calls(): void
    {
        $owner = $this->makeParent('procurement');

        $html = $this->actingAs($this->manager())->get($this->showUrl('procurement', $owner))->getContent();

        $this->assertStringContainsString('name="csrf-token"', $html, 'Ajax が使う CSRF トークンが無い');
    }

    /** Ajax の応答が、描き直したガントを返すこと（プラン 決定 A） */
    public function test_saving_returns_a_freshly_rendered_gantt(): void
    {
        $owner = $this->makeParent('procurement');

        $json = $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['name' => '地盤改良'])
        )->assertOk()->json();

        $this->assertArrayHasKey('gantt_html', $json, 'ガントを描き直して返していない（保存後に画面が古いままになる）');
        $this->assertStringContainsString('id="schedule-gantt"', $json['gantt_html']);
        $this->assertStringContainsString('地盤改良', $json['gantt_html'], '保存した工程が描き直しに反映されていない');
    }

    // ============================================================
    // 構造 — partial の定義は 1 箇所
    // ============================================================

    /**
     * ⚠ 4 つの show が**同じ partial を `@include` している**こと。
     *   インラインの複製に置き換える変異をここで止める（Bug #41）。
     */
    public function test_all_four_detail_views_include_the_one_shared_partial(): void
    {
        foreach (self::SHOW_VIEWS as $view) {
            $this->assertStringContainsString(
                "@include('_partials._schedule_section'",
                File::get(base_path($view)),
                "{$view} が共通 partial を include していない（マークアップを複製していないか）"
            );
        }
    }

    /**
     * ⚠ **マークアップの実体が partial 側にしか無いこと。**
     *   include を残したままインラインへ複製する変異は、上のテストだけでは止まらない。
     */
    public function test_the_gantt_markup_lives_only_in_the_partial(): void
    {
        $owners = [];

        foreach (array_merge(self::SHOW_VIEWS, [self::SECTION_PARTIAL, self::GANTT_PARTIAL]) as $view) {
            if (str_contains(File::get(base_path($view)), 'schedule-gantt-track')) {
                $owners[] = $view;
            }
        }

        $this->assertSame(
            [self::GANTT_PARTIAL],
            $owners,
            'ガントのマークアップが partial 以外にもあります（複製すると一部だけ直す事故が起きます）'
        );
    }
    /**
     * ⚠ **月末日から余白 1 ヶ月を引いても月が飛ばないこと。**
     *   Carbon の subMonths() は月末日で溢れる（実測: 2026-03-31 の 1 ヶ月前が 2026-03-03）。
     *   溢れると startOfMonth() を通しても 3/1 になり、**前の余白が丸ごと消えて
     *   棒が軸の左端に貼り付く**（画面では「なんとなく詰まっている」だけなので気づけない）。
     */
    public function test_the_padding_month_survives_a_step_that_starts_on_the_last_day_of_a_month(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '月末開始', 'category' => 'work',
            'planned_start' => '2026-03-31', 'planned_end' => '2026-04-30', 'sort_order' => 1,
        ]);

        $card = app(\App\Services\ScheduleCardService::class)
            ->build($owner, \Carbon\CarbonImmutable::parse('2026-04-01'));

        $this->assertNotNull($card['gantt']);
        $this->assertSame(
            ['2026', '2月'],
            [$card['gantt']['months'][0]['year'], $card['gantt']['months'][0]['label']],
            '前の余白 1 ヶ月が消えている（Carbon の月末オーバーフロー）'
        );
    }
}
