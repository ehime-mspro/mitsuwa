<?php

namespace Tests\Feature\Admin\Master;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDadSchema;

/**
 * DAD 専門分野マスター（Admin\DadSpecialtyController）。登録・編集は別の画面で、色は Alpine の `x-model`
 * （specialtyForm()）が入れる（静的な value を持たない）ので、送る値は JS の状態で評価する（alpineForm()）。
 * 削除は編集画面の削除フォーム。協力業者（`dad_subcontractors.specialty_id`）で使っている分野は消さない。
 */
class DadSpecialtyMasterTest extends MasterScreenTestCase
{
    use CreatesDadSchema;

    /** @var array<string, int> 名前 => id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDadSchema();
        foreach ([['土工', '#fef3c7', '#92400e'], ['舗装', '#e5e7eb', '#374151'], ['配管', '#dbeafe', '#1e40af']] as $i => [$name, $bg, $text]) {
            $this->ids[$name] = DB::table('dad_specialties')->insertGetId([
                'name' => $name, 'color_bg' => $bg, 'color_text' => $text, 'sort_order' => $i + 1, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function indexUrl(): string
    {
        return route('admin.master.dad-specialties.index');
    }

    private function namesInOrder(): array
    {
        return DB::table('dad_specialties')->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
    }

    /** 登録画面のフォームを、ブラウザで $steps を操作したときの値で送る */
    private function register(string $steps)
    {
        $create = route('admin.master.dad-specialties.create');
        $form = $this->alpineForm($this->htmlOf($create), 'action="' . route('admin.master.dad-specialties.store') . '"', 'specialtyForm', $steps);
        $this->assertSame('POST', $form['method']);

        return [$form, $this->actingAs($this->user)->from($create)->post($form['action'], $form['fields'])];
    }

    /** 編集画面の更新フォームを、ブラウザで $steps を操作したときの値で送る */
    private function edit(string $name, string $steps)
    {
        $edit = route('admin.master.dad-specialties.edit', $this->ids[$name]);
        $form = $this->alpineForm($this->htmlOf($edit), 'action="' . route('admin.master.dad-specialties.update', $this->ids[$name]) . '"', 'specialtyForm', $steps);
        $this->assertSame('PUT', $form['method']);

        return $this->actingAs($this->user)->from($edit)->post($form['action'], $form['fields']);
    }

    public function test_the_list_hands_the_specialties_to_the_screen_with_links_to_edit(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $this->assertSame(1, preg_match('/<a :href="([^"]*)"[^>]*>編集<\/a>/u', $html, $link), '編集のリンクが見つからない');

        $run = $this->driveAlpine($html, 'dadSpecialtyManager', $this->xData($html, 'dadSpecialtyManager'), '', [], true,
            ['(function (item) { return ' . html_entity_decode($link[1], ENT_QUOTES) . '; })(items[1])']);

        $this->assertSame(['土工', '舗装', '配管'], array_column($run['state']['items'], 'name'));
        $this->assertSame('#e5e7eb', $run['state']['items'][1]['color_bg']);
        $this->assertSame(route('admin.master.dad-specialties.edit', $this->ids['舗装']), $run['evaluated'][0]);
        $this->htmlOf($run['evaluated'][0]);
    }

    /** 登録画面は、名前が空・色は既定（土工のプリセット）で始まる */
    public function test_the_create_screen_starts_with_the_default_colors(): void
    {
        $form = $this->alpineForm($this->htmlOf(route('admin.master.dad-specialties.create')),
            'action="' . route('admin.master.dad-specialties.store') . '"', 'specialtyForm');

        $this->assertSame('', $form['fields']['name']);
        $this->assertSame('#fef3c7', $form['fields']['color_bg']);
        $this->assertSame('#92400e', $form['fields']['color_text']);
        $this->assertNotSame('', $form['fields']['_token'] ?? '');
    }

    /**
     * 色の「背景色」「文字色」の 2 列は、中に固定幅の入力（色の見本＋120px の hex の欄）を持つので、375px で 14px 横にはみ出した
     * （2026-10-07 に実ブラウザで実測）。狭い画面では 1 列に落とす。⚠ 本当に収まるかは実ブラウザで測る。
     */
    public function test_the_color_columns_stack_on_narrow_screens(): void
    {
        foreach ([route('admin.master.dad-specialties.create'), route('admin.master.dad-specialties.edit', $this->ids['土工'])] as $url) {
            $this->assertMatchesRegularExpression(
                '/<div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">\s*<div class="fld">\s*<label>背景色/u',
                $this->htmlOf($url),
                "{$url} の色の 2 列が狭い画面で 1 列に落ちない"
            );
        }
    }

    public function test_a_specialty_is_registered_from_the_create_screen_with_a_preset(): void
    {
        [$form, $response] = $this->register("data.name = '電気'; data.applyPreset(data.presets[3]);");

        $this->assertSame(['name' => '電気', 'color_bg' => '#fef9c3', 'color_text' => '#854d0e'],
            array_intersect_key($form['fields'], array_flip(['name', 'color_bg', 'color_text'])));
        $response->assertRedirect($this->indexUrl());
        $row = DB::table('dad_specialties')->where('name', '電気')->first();
        $this->assertSame(['#fef9c3', '#854d0e', 4, 1], [$row->color_bg, $row->color_text, (int) $row->sort_order, (int) $row->is_active]);
        $this->assertFlash($this->landed($response), 'success', '「電気」を追加しました。');
    }

    public function test_a_duplicate_name_is_refused_and_the_input_is_kept(): void
    {
        [, $response] = $this->register("data.name = '土工'; data.colorBg = '#ffffff';");

        $response->assertRedirect(route('admin.master.dad-specialties.create'));
        $this->assertSame(1, DB::table('dad_specialties')->where('name', '土工')->count());
        $html = $this->landed($response);
        $this->assertStringContainsString('<p class="text-sm text-red-800">同名の専門分野が既に登録されています。</p>', $html);
        $state = $this->driveAlpine($html, 'specialtyForm', $this->xData($html, 'specialtyForm'), '')['state'];
        $this->assertSame(['土工', '#ffffff'], [$state['name'], $state['colorBg']], '差し戻された画面が入力を残していない');
    }

    public function test_a_malformed_color_is_refused_with_the_reason(): void
    {
        [, $response] = $this->register("data.name = '電気'; data.colorBg = 'blue';");

        $this->assertFalse(DB::table('dad_specialties')->where('name', '電気')->exists());
        $this->assertStringContainsString('<p class="text-sm text-red-800">背景色は #RRGGBB 形式で入力してください。</p>', $this->landed($response));
    }

    public function test_a_specialty_is_updated_from_the_edit_screen(): void
    {
        $response = $this->edit('舗装', "data.name = '舗装（アスファルト）'; data.colorText = '#000000';");

        $response->assertRedirect($this->indexUrl());
        $row = DB::table('dad_specialties')->where('id', $this->ids['舗装'])->first();
        $this->assertSame(['舗装（アスファルト）', '#e5e7eb', '#000000'], [$row->name, $row->color_bg, $row->color_text]);
        $this->assertFlash($this->landed($response), 'success', '「舗装（アスファルト）」を更新しました。');
    }

    /** 名前を変えずに色だけ直すのは、同名の確かめに引っかからない */
    public function test_changing_only_the_colors_keeps_the_name_valid(): void
    {
        $response = $this->edit('配管', "data.colorBg = '#000000';");

        $response->assertSessionHasNoErrors();
        $this->assertSame('#000000', DB::table('dad_specialties')->where('id', $this->ids['配管'])->value('color_bg'));
    }

    /**
     * 編集画面の削除フォーム（削除の確認の箱の中）。
     * ⚠ 更新と削除は送り先も開始タグも同じ（`<form method="POST" action="…/{id}">`）なので、送り先で探すと
     *   先にある更新フォームを掴む。確認の箱から先だけを見て探す。
     */
    private function deleteForm(string $name): array
    {
        $html = $this->htmlOf(route('admin.master.dad-specialties.edit', $this->ids[$name]));
        $box = strpos($html, 'x-show="confirmDelete"');
        $this->assertNotFalse($box, '編集画面に削除の確認の箱が無い');
        $form = $this->parseForm(substr($html, $box), 'action="' . route('admin.master.dad-specialties.destroy', $this->ids[$name]) . '"');
        $this->assertSame('DELETE', $form['method']);

        return $form;
    }

    public function test_an_unused_specialty_is_deleted_from_the_edit_screen(): void
    {
        $edit = route('admin.master.dad-specialties.edit', $this->ids['配管']);
        $form = $this->deleteForm('配管');

        $response = $this->actingAs($this->user)->from($edit)->post($form['action'], $form['fields']);

        $response->assertRedirect($this->indexUrl());
        $this->assertFalse(DB::table('dad_specialties')->where('id', $this->ids['配管'])->exists());
        $this->assertFlash($this->landed($response), 'success', '「配管」を削除しました。');
    }

    public function test_a_specialty_used_by_a_subcontractor_is_not_deleted(): void
    {
        DB::table('dad_subcontractors')->insert(['company_name' => '協力土木', 'specialty_id' => $this->ids['土工'], 'created_by' => 1]);
        $edit = route('admin.master.dad-specialties.edit', $this->ids['土工']);
        $form = $this->deleteForm('土工');

        $response = $this->actingAs($this->user)->from($edit)->post($form['action'], $form['fields']);

        $this->assertTrue(DB::table('dad_specialties')->where('id', $this->ids['土工'])->exists());
        $this->assertFlash($this->landed($response), 'error', '「土工」は協力業者で使用中のため削除できません。');
    }

    public function test_dragging_a_row_saves_the_new_order(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $factory = $this->xData($html, 'dadSpecialtyManager');
        $ev = $this->dragEventJs();
        $steps = "data.handleDragStart(0, {$ev}); data.handleDragOver(2, {$ev}); data.handleDrop(2, {$ev});";

        $request = $this->driveAlpine($html, 'dadSpecialtyManager', $factory, $steps)['requests'][0];
        $this->assertSame(route('admin.master.dad-specialties.reorder'), $request['url']);
        $this->assertSame(['ids' => [$this->ids['舗装'], $this->ids['配管'], $this->ids['土工']]], json_decode((string) $request['body'], true));
        $response = $this->actingAs($this->user)->sendCaptured($request)->assertOk();
        $after = $this->driveAlpine($html, 'dadSpecialtyManager', $factory, $steps, [$this->asFetchResponse($response)]);

        $this->assertSame(['舗装', '配管', '土工'], $this->namesInOrder());
        $this->assertSame('並び順を更新しました', $after['state']['reorderMessage']);
    }

    /** 末尾の行を先頭へ（末尾へ落とすだけだと、差し込む位置のずれが同じ結果になり捕まらない） */
    public function test_dragging_the_last_row_to_the_top_saves_the_new_order(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $ev = $this->dragEventJs();

        $request = $this->driveAlpine($html, 'dadSpecialtyManager', $this->xData($html, 'dadSpecialtyManager'),
            "data.handleDragStart(2, {$ev}); data.handleDrop(0, {$ev});")['requests'][0];
        $this->assertSame(['ids' => [$this->ids['配管'], $this->ids['土工'], $this->ids['舗装']]], json_decode((string) $request['body'], true));
        $this->actingAs($this->user)->sendCaptured($request)->assertOk();

        $this->assertSame(['配管', '土工', '舗装'], $this->namesInOrder());
    }
}
