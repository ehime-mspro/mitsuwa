<?php

namespace Tests\Feature\Admin\Master;

use App\Models\ZealSimulation;
use App\Models\ZealSimulationValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesZealSimulationSchema;

/**
 * ZEAL 試算表の項目マスター（Admin\ZealSimulationCategoryController）。登録・編集は別の画面の普通のフォーム、
 * 削除は一覧の各行のフォーム（onsubmit で確認）、並び替えは行の DOM を入れ替えて fetch で送る。
 *
 * ⚠ 今日を 2026-10-04（日本時間）に固定する。固定額の編集画面は「適用開始月」の既定を来月（2026-11）にする。
 * ⚠ 並び替えは DOM（tbody の行）を動かすので、描いた行の `data-id` の順に偽の DOM を組んで handleDrop を動かす。
 */
class ZealSimulationCategoryMasterTest extends MasterScreenTestCase
{
    use CreatesZealSimulationSchema;

    private int $webAd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-04 03:00:00', 'UTC'));   // 日本時間 12:00
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
        $this->webAd = DB::table('zeal_simulation_categories')->insertGetId([
            'code' => 'web_ad', 'name' => 'Web広告費', 'group_type' => 'expense', 'calc_type' => 'manual',
            'sort_order' => 40, 'is_system' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function indexUrl(): string
    {
        return route('admin.master.zeal-simulation-categories.index');
    }

    private function idOf(string $code): int
    {
        return (int) DB::table('zeal_simulation_categories')->where('code', $code)->value('id');
    }

    private function row(string $code): ?object
    {
        return DB::table('zeal_simulation_categories')->where('code', $code)->first();
    }

    /** 編集画面の更新フォームを、$over の項目だけ差し替えて送る */
    private function sendEdit(string $code, array $over)
    {
        $id = $this->idOf($code);
        $edit = route('admin.master.zeal-simulation-categories.edit', $id);
        $form = $this->parseForm($this->htmlOf($edit), 'action="' . route('admin.master.zeal-simulation-categories.update', $id) . '"');
        $this->assertSame('PUT', $form['method']);

        return $this->actingAs($this->user)->from($edit)->post($form['action'], array_merge($form['fields'], $over));
    }

    /** 一覧の行の削除フォーム（無ければ null） */
    private function deleteFormOf(string $html, int $id): ?array
    {
        $needle = 'action="' . route('admin.master.zeal-simulation-categories.destroy', $id) . '"';

        return str_contains($html, $needle) ? $this->parseForm($html, $needle) : null;
    }

    public function test_the_list_offers_delete_only_for_items_that_are_not_system_ones(): void
    {
        $html = $this->htmlOf($this->indexUrl());

        foreach (['revenue', 'member_count', 'expense_total', 'operating_profit'] as $system) {
            $this->assertNull($this->deleteFormOf($html, $this->idOf($system)), "システム固定の {$system} に削除フォームがある");
        }
        foreach (['rent', 'web_ad'] as $code) {
            $form = $this->deleteFormOf($html, $this->idOf($code));
            $this->assertNotNull($form, "{$code} に削除フォームが無い");
            $this->assertSame('DELETE', $form['method']);
        }
        $this->assertSame(1, preg_match_all('/href="' . preg_quote(route('admin.master.zeal-simulation-categories.edit', $this->webAd), '/') . '"/', $html));
    }

    /** 登録画面のフォームを、$over の項目だけ差し替えて送る */
    private function sendCreate(array $over)
    {
        $create = route('admin.master.zeal-simulation-categories.create');
        $form = $this->parseForm($this->htmlOf($create), 'action="' . route('admin.master.zeal-simulation-categories.store') . '"');
        $this->assertSame('POST', $form['method']);

        return [$form, $this->actingAs($this->user)->from($create)->post($form['action'], array_merge($form['fields'], $over))];
    }

    /**
     * G1: 新規登録画面が開き、そこから登録できる。
     * ⚠ 以前は共通フォームの `use ($category, $isEdit)` が登録画面では未定義の変数を拾い、画面が 500 だった
     *   （最初の実装 2026-05-12 から）。
     */
    public function test_an_item_is_registered_from_the_create_screen(): void
    {
        [$form, $response] = $this->sendCreate(['code' => 'cleaning', 'name' => '清掃費', 'calc_type' => 'fixed', 'default_amount' => '30000']);

        $this->assertSame(['expense', 'manual', '1'], [$form['fields']['group_type'], $form['fields']['calc_type'], $form['fields']['is_active']],
            '登録画面の既定（グループ＝経費・計算タイプ＝手入力・有効）が違う');
        $response->assertRedirect($this->indexUrl());
        $row = $this->row('cleaning');
        $this->assertSame(['清掃費', 'expense', 'fixed', 30000, 220, 0, 1],
            [$row->name, $row->group_type, $row->calc_type, (int) $row->default_amount, (int) $row->sort_order, (int) $row->is_system, (int) $row->is_active]);
        $this->assertFlash($this->landed($response), 'success', '「清掃費」を追加しました。');
    }

    public function test_a_duplicate_or_malformed_code_is_refused_on_the_create_screen(): void
    {
        [, $duplicate] = $this->sendCreate(['code' => 'rent', 'name' => '賃料2']);
        $this->assertStringContainsString('>同じコードが既に登録されています。</p>', $this->landed($duplicate));

        [, $malformed] = $this->sendCreate(['code' => 'Web-Ad', 'name' => 'Web広告2']);
        $this->assertStringContainsString('>コードは半角小文字英数字とアンダースコアで入力してください（例: rent, web_operation）。</p>', $this->landed($malformed));

        $this->assertSame(6, DB::table('zeal_simulation_categories')->count());
    }

    public function test_an_item_is_updated_from_the_edit_screen(): void
    {
        $response = $this->sendEdit('web_ad', ['name' => 'Web広告・SNS', 'calc_type' => 'revenue_linked', 'rate_percent' => '2.5']);

        $response->assertRedirect($this->indexUrl());
        $row = $this->row('web_ad');
        $this->assertSame(['Web広告・SNS', 'revenue_linked', '2.500'], [$row->name, $row->calc_type, number_format((float) $row->rate_percent, 3)]);
        $this->assertFlash($this->landed($response), 'success', '「Web広告・SNS」を更新しました。');
    }

    public function test_unchecking_the_active_box_makes_the_item_inactive(): void
    {
        // チェックを外すとブラウザは checkbox を送らず、hidden の 0 だけが届く
        $response = $this->sendEdit('web_ad', ['is_active' => '0']);

        $response->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $this->row('web_ad')->is_active);
    }

    public function test_a_rate_over_100_is_refused_with_the_reason(): void
    {
        $response = $this->sendEdit('web_ad', ['rate_percent' => '100.5']);

        $this->assertNull($this->row('web_ad')->rate_percent);
        $this->assertStringContainsString('>率は 0〜100 の範囲で入力してください。</p>', $this->landed($response));
    }

    /**
     * 固定額を変えると、適用開始月（既定は来月）以降の、手で直していないセルだけが新しい額になる。
     */
    public function test_changing_a_fixed_amount_updates_cells_from_the_apply_month_except_edited_ones(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2026]);
        $rent = $this->idOf('rent');
        foreach (['2026-09' => [200000, false], '2026-11' => [200000, false], '2026-12' => [150000, true], '2027-01' => [200000, false]] as $ym => [$amount, $manual]) {
            ZealSimulationValue::create(['simulation_id' => $simulation->id, 'category_id' => $rent, 'year_month' => $ym, 'amount' => $amount, 'is_manual_override' => $manual]);
        }

        $html = $this->htmlOf(route('admin.master.zeal-simulation-categories.edit', $rent));
        $this->assertSame('2026-11', $this->parseForm($html, 'action="' . route('admin.master.zeal-simulation-categories.update', $rent) . '"')['fields']['apply_from_month'],
            '適用開始月の既定が来月でない');
        $response = $this->sendEdit('rent', ['default_amount' => '210000']);

        $cells = ZealSimulationValue::where('category_id', $rent)->orderBy('year_month')->pluck('amount', 'year_month')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(['2026-09' => 200000, '2026-11' => 210000, '2026-12' => 150000, '2027-01' => 210000], $cells);
        $this->assertSame(210000, (int) $this->row('rent')->default_amount);
        $this->assertFlash($this->landed($response), 'success', '「賃料」を更新しました。既存試算表セルへの反映: 2 セル更新／1 セル手動上書き保持。');
    }

    public function test_a_blank_apply_month_leaves_the_cells_alone(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2026]);
        ZealSimulationValue::create(['simulation_id' => $simulation->id, 'category_id' => $this->idOf('rent'), 'year_month' => '2026-11', 'amount' => 200000]);

        $response = $this->sendEdit('rent', ['default_amount' => '210000', 'apply_from_month' => '']);

        $this->assertSame(200000, (int) ZealSimulationValue::where('category_id', $this->idOf('rent'))->value('amount'));
        $this->assertFlash($this->landed($response), 'success', '「賃料」を更新しました。');
    }

    /** システム固定の項目は、手で書き換えた値を送ってもコード・グループ・計算タイプが変わらない（名前は変わる） */
    public function test_a_system_item_keeps_its_code_group_and_calc_type(): void
    {
        $id = $this->idOf('revenue');
        $html = $this->htmlOf(route('admin.master.zeal-simulation-categories.edit', $id));
        $this->assertMatchesRegularExpression('/<input type="text" name="code" value="revenue"\s+readonly/', $html);
        $this->assertMatchesRegularExpression('/<select name="group_type"\s+disabled/', $html);

        $response = $this->sendEdit('revenue', ['name' => '売上（税抜）', 'code' => 'hacked', 'group_type' => 'expense', 'calc_type' => 'fixed']);

        $response->assertRedirect($this->indexUrl());
        $row = DB::table('zeal_simulation_categories')->where('id', $id)->first();
        $this->assertSame(['revenue', '売上（税抜）', 'revenue', 'manual'], [$row->code, $row->name, $row->group_type, $row->calc_type]);
    }

    public function test_an_item_is_deleted_from_the_list(): void
    {
        $form = $this->deleteFormOf($this->htmlOf($this->indexUrl()), $this->webAd);

        $response = $this->actingAs($this->user)->from($this->indexUrl())->post($form['action'], $form['fields']);

        $response->assertRedirect($this->indexUrl());
        $this->assertNull($this->row('web_ad'));
        $this->assertFlash($this->landed($response), 'success', '「Web広告費」を削除しました。');
    }

    /**
     * G3: 削除の確認（onsubmit の confirm）は、項目名に ' や \ があっても壊れない（Bug #76 と同じ形）。
     * ⚠ 名前を JS の文字列に生で埋め込むと、ブラウザが属性の実体参照を戻した時点で文字列が閉じ、
     *   関数が組み立てられない（＝確認を出さずに送信される）。
     */
    #[DataProvider('namesThatBreakAJsString')]
    public function test_the_delete_confirmation_survives_any_item_name(string $name): void
    {
        DB::table('zeal_simulation_categories')->where('id', $this->webAd)->update(['name' => $name]);
        $html = $this->htmlOf($this->indexUrl());
        $action = 'action="' . route('admin.master.zeal-simulation-categories.destroy', $this->webAd) . '"';
        $pos = strpos($html, $action);
        $open = strrpos(substr($html, 0, $pos), '<form');
        $tag = substr($html, $open, strpos($html, '>', $pos) - $open + 1);
        $this->assertSame(1, preg_match('/\sonsubmit="([^"]*)"/', $tag, $m), '削除フォームに onsubmit が無い');
        $handler = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        $declined = $this->runInlineHandler($handler, false);
        $this->assertTrue($declined['compiled'], "削除の確認の JS が組み立てられない: {$declined['error']}");
        $this->assertSame(false, $declined['returned'], '確認で「いいえ」を選んでも送信が止まらない');
        $this->assertSame(['「' . $name . '」を削除しますか？既存試算表のセル値も削除されます。'], $declined['confirms']);
        $this->assertSame(true, $this->runInlineHandler($handler, true)['returned'], '確認で「はい」を選んでも送信されない');
    }

    public static function namesThatBreakAJsString(): array
    {
        return [
            'シングルクォート' => ["O'Brien 費"],
            'バックスラッシュ' => ['広告費\\'],
            '閉じて続ける'     => ["x');alert(1);('"],
        ];
    }

    /** 画面に削除フォームが無いシステム固定の項目は、手で送っても消えない */
    public function test_a_system_item_is_not_deleted_even_when_asked(): void
    {
        $response = $this->actingAs($this->user)->from($this->indexUrl())
            ->delete(route('admin.master.zeal-simulation-categories.destroy', $this->idOf('revenue')));

        $response->assertRedirect($this->indexUrl());
        $this->assertNotNull($this->row('revenue'));
        $this->assertFlash($this->landed($response), 'error', 'システム固定項目（売上）は削除できません。');
    }

    /** 先頭の行を 3 番目へドラッグすると、行の並びのとおりに保存され（10 刻み）、トーストが出る */
    public function test_dragging_a_row_saves_the_new_order(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        preg_match_all('/<tr data-id="(\d+)"/', $html, $rows);
        $ids = array_map('intval', $rows[1]);
        $this->assertSame(['revenue', 'member_count', 'rent', 'web_ad', 'expense_total', 'operating_profit'],
            array_map(fn ($id) => DB::table('zeal_simulation_categories')->where('id', $id)->value('code'), $ids));
        $factory = $this->xData($html, 'zealCategoryReorder');
        $steps = $this->fakeRowsJs($ids)
            . ' data.handleDragStart({ currentTarget: rows[0], dataTransfer: { effectAllowed: "", setData: function () {} } });'
            . ' data.handleDragOver({ currentTarget: rows[2], dataTransfer: {} });'
            . ' data.handleDrop({ currentTarget: rows[2] });';

        $request = $this->driveAlpine($html, 'zealCategoryReorder', $factory, $steps)['requests'][0];
        $expected = [$ids[1], $ids[2], $ids[0], $ids[3], $ids[4], $ids[5]];
        $this->assertSame(route('admin.master.zeal-simulation-categories.reorder'), $request['url']);
        $this->assertSame(['ids' => $expected], json_decode((string) $request['body'], true));
        $response = $this->actingAs($this->user)->sendCaptured($request)->assertOk();
        $after = $this->driveAlpine($html, 'zealCategoryReorder', $factory, $steps, [$this->asFetchResponse($response)]);

        $this->assertSame($expected, DB::table('zeal_simulation_categories')->orderBy('sort_order')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([10, 20, 30, 40, 50, 60], DB::table('zeal_simulation_categories')->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('並び順を更新しました', $after['state']['reorderMessage']);
    }

    /** 描いた行の順に、行（data-id・classList・nextSibling）と tbody（children・querySelector・insertBefore）の偽物を組む JS */
    private function fakeRowsJs(array $ids): string
    {
        return 'var rows = ' . json_encode($ids) . '.map(function (id) { return { id: id, getAttribute: function (n) { return n === "data-id" ? String(id) : null; }, classList: { add: function () {}, remove: function () {} } }; });'
            . ' var tbody = { children: rows,'
            . ' querySelector: function (sel) { var m = sel.match(/data-id="(\d+)"/); return tbody.children.filter(function (r) { return String(r.id) === m[1]; })[0] || null; },'
            . ' querySelectorAll: function () { return tbody.children.slice(); },'
            . ' insertBefore: function (node, ref) { var c = tbody.children; c.splice(c.indexOf(node), 1); c.splice(ref ? c.indexOf(ref) : c.length, 0, node); } };'
            . ' rows.slice().forEach(function (r) { r.parentNode = tbody; Object.defineProperty(r, "nextSibling", { get: function () { var c = tbody.children; return c[c.indexOf(r) + 1] || null; } }); });'
            . ' rows = rows.slice();';
    }
}
