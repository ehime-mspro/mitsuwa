<?php

namespace Tests\Feature\Dad\Screens;

use App\Models\DadProject;
use App\Models\DadProjectAssignment;
use App\Models\DadProjectCost;

/**
 * DAD の工事案件（一覧・登録・詳細・編集・削除）。登録・編集は原価明細と人員配置の行を Alpine（projectForm()）が描くので、
 * 描いた画面のフォームを JS の状態で組んで送る（projectForm()）。
 *
 * ⚠ 原価・人員配置の入力エラーは「原価明細 2 行目の見積額」の形で、行の番号は画面の行（空の行を数えて）と同じ。
 * ⚠ 退職した従業員の配置は、選択肢に「（退職）」つきで残る。選択肢に無いと、そのまま保存するだけで配置が消える（ブラウザで再現した）。
 */
class ProjectScreensTest extends DadScreenTestCase
{
    private function storeAction(): string
    {
        return route('dad.projects.store');
    }

    private function createForm(string $steps = '', array $dateSteps = []): array
    {
        return $this->projectForm($this->htmlOf(route('dad.projects.create')), $this->storeAction(), $steps, $dateSteps);
    }

    private function editForm(DadProject $project, string $steps = '', array $dateSteps = []): array
    {
        return $this->projectForm($this->htmlOf(route('dad.projects.edit', $project)), route('dad.projects.update', $project), $steps, $dateSteps);
    }

    /** 原価の行を 1 行足す JS（$row の欄だけを入れる） */
    private function addCost(array $row): string
    {
        $js = 'data.addCostRow(); var r = data.costRows[data.costRows.length - 1];';
        foreach ($row as $key => $value) {
            $js .= ' r.' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE) . ';';
        }

        return $js;
    }

    /** 人員配置の行を 1 行足す JS */
    private function addAssignment(array $row): string
    {
        $js = 'data.addAssignmentRow(); var a = data.assignmentRows[data.assignmentRows.length - 1];';
        foreach ($row as $key => $value) {
            $js .= ' a.' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE) . ';';
        }

        return $js;
    }

    // ============================================================
    // 一覧・詳細
    // ============================================================

    public function test_the_list_shows_projects_and_filters_by_type_and_keyword(): void
    {
        $this->project(['project_name' => '市道一番町線 舗装補修工事', 'project_type' => 'public']);
        $this->project(['project_name' => '民間駐車場 造成工事', 'project_type' => 'private']);

        $html = $this->htmlOf(route('dad.projects.index'));
        $this->assertStringContainsString('市道一番町線 舗装補修工事', $html);
        $this->assertStringContainsString('民間駐車場 造成工事', $html);

        $html = $this->htmlOf(route('dad.projects.index', ['project_type' => 'private']));
        $this->assertStringNotContainsString('市道一番町線 舗装補修工事', $html);
        $this->assertStringContainsString('民間駐車場 造成工事', $html);

        $html = $this->htmlOf(route('dad.projects.index', ['keyword' => '舗装']));
        $this->assertStringContainsString('市道一番町線 舗装補修工事', $html);
        $this->assertStringNotContainsString('民間駐車場 造成工事', $html);
    }

    public function test_the_detail_shows_costs_and_assignments(): void
    {
        $client = $this->client();
        $sub = $this->subcontractor();
        $employee = $this->employee();
        $project = $this->project(['client_id' => $client->id]);
        $project->costs()->create(['cost_category' => 'subcontract', 'description' => '掘削・路盤工', 'estimated_amount' => 4000000, 'actual_amount' => 4100000, 'subcontractor_id' => $sub->id]);
        $project->assignments()->create(['employee_id' => $employee->id, 'role' => '現場代理人']);

        $html = $this->htmlOf(route('dad.projects.show', $project));

        $this->assertStringContainsString('発注者: 松山市 都市整備部', $html);
        $this->assertStringContainsString('高橋 一郎', $html);
        // 原価の表は Alpine が JS の行（costRows）から描く。協力業者ごとの発注履歴はサーバが描く
        $this->assertSame(1, preg_match('/costRows: (\[.*?\]),\n/', $html, $m), '原価の行の JS が見つからない');
        $rows = json_decode($m[1], true);
        $this->assertSame([['外注費', '掘削・路盤工', 4000000, 4100000, '有限会社 土木サービス']], array_map(fn ($r) => [$r['cost_category_label'], $r['description'], $r['estimateAmount'], $r['actualAmount'], $r['subcontractor_name']], $rows));
        $this->assertMatchesRegularExpression('/<td[^>]*>\s*有限会社 土木サービス\s*<\/td>/u', $html);
    }

    // ============================================================
    // 登録
    // ============================================================

    public function test_a_project_with_cost_rows_is_registered_from_the_screen(): void
    {
        $client = $this->client();
        $sub = $this->subcontractor();
        $form = $this->createForm(
            $this->addCost(['cost_category' => 'material', 'description' => 'アスファルト合材', 'estimated_amount' => '3000000', 'actual_amount' => '2900000'])
            . $this->addCost(['cost_category' => 'subcontract', 'description' => '掘削・路盤工', 'estimated_amount' => '4000000', 'subcontractor_id' => (string) $sub->id]),
            ['data.selected = new Date(2026, 4, 10);'],
        );
        $form = $this->fill($form, ['project_name' => '市道一番町線 舗装補修工事', 'client_id' => (string) $client->id, 'estimate_amount' => '12000000']);

        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertFlash($html, 'success', '工事案件「市道一番町線 舗装補修工事」を登録しました。');
        $project = DadProject::sole();
        $this->assertSame(['DAD-001', 12000000, '2026-05-10', $client->id], [$project->project_code, $project->estimate_amount, $project->estimate_date->format('Y-m-d'), $project->client_id]);
        $this->assertSame(
            [['material', 'アスファルト合材', 3000000, 2900000, null], ['subcontract', '掘削・路盤工', 4000000, null, $sub->id]],
            $project->costs()->orderBy('id')->get()->map(fn ($c) => [$c->cost_category->value, $c->description, $c->estimated_amount, $c->actual_amount, $c->subcontractor_id])->all(),
        );
    }

    /** A1: 原価の行の項目名は DAD の画面の語（見積額・実績額）で、何行目かも出る */
    public function test_cost_row_errors_use_the_screen_labels_and_the_row_number(): void
    {
        $form = $this->createForm(
            $this->addCost(['cost_category' => 'material', 'estimated_amount' => '3000000'])
            . $this->addCost(['cost_category' => 'labor', 'estimated_amount' => 'abc', 'actual_amount' => 'xyz']),
        );
        $form = $this->fill($form, ['project_name' => '工事A']);

        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertErrorItem($html, trans('validation.integer', ['attribute' => '原価明細 2 行目の見積額']));
        $this->assertErrorItem($html, trans('validation.integer', ['attribute' => '原価明細 2 行目の実績額']));
        $this->assertSame(0, DadProject::count());
    }

    /** A2: 原価の内容は列（200 文字）まで。Excel 取込は長さを切らずに行へ入れるので、保存で断る */
    public function test_a_cost_description_longer_than_the_column_is_refused(): void
    {
        $ok = $this->fill($this->createForm($this->addCost(['cost_category' => 'material', 'description' => str_repeat('あ', 200)])), ['project_name' => '工事A']);
        $this->assertFlash($this->landed($this->submit($ok, route('dad.projects.create'))), 'success', '工事案件「工事A」を登録しました。');
        $this->assertSame(200, mb_strlen(DadProjectCost::sole()->description));

        $form = $this->fill($this->createForm($this->addCost(['cost_category' => 'material', 'description' => str_repeat('あ', 201)])), ['project_name' => '工事B']);
        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertErrorItem($html, trans('validation.max.string', ['attribute' => '原価明細 1 行目の内容', 'max' => 200]));
        $this->assertSame(1, DadProject::count());
    }

    /** A5: 入力エラーで戻ると、原価の行は送った行のまま（空の行も含めて同じ並び）で描かれる */
    public function test_cost_rows_survive_an_input_error_on_the_registration_screen(): void
    {
        $form = $this->createForm(
            'data.addCostRow();'
            . $this->addCost(['cost_category' => 'material', 'description' => 'アスファルト合材', 'estimated_amount' => 'abc'])
        );
        $form = $this->fill($form, ['project_name' => '']);

        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertErrorItem($html, '工事名は必須です。');
        $this->assertErrorItem($html, trans('validation.integer', ['attribute' => '原価明細 2 行目の見積額']));
        $again = $this->projectForm($html, $this->storeAction());
        $this->assertSame('アスファルト合材', $again['fields']['costs'][1]['description'] ?? null, '入力エラーのあと原価の行が消えた');
        $this->assertSame('material', $again['fields']['costs'][1]['cost_category']);
        $this->assertSame('abc', $again['fields']['costs'][1]['estimated_amount']);
    }

    /** A13: カテゴリを選んでいない原価の行（金額などを入れた行）を、黙って捨てずに断る。すべての欄が空の行だけを空の行とみなす */
    public function test_a_cost_row_without_a_category_is_refused_instead_of_being_dropped(): void
    {
        $form = $this->createForm(
            'data.addCostRow();'
            . $this->addCost(['description' => 'アスファルト合材', 'estimated_amount' => '3000000'])
        );
        $form = $this->fill($form, ['project_name' => '工事A']);

        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertErrorItem($html, '原価明細 2 行目のカテゴリを選択してください。');
        $this->assertSame(0, DadProject::count());
    }

    public function test_an_all_blank_cost_row_is_ignored(): void
    {
        $form = $this->fill($this->createForm('data.addCostRow();'), ['project_name' => '工事A']);

        $this->assertFlash($this->landed($this->submit($form, route('dad.projects.create'))), 'success', '工事案件「工事A」を登録しました。');
        $this->assertSame(0, DadProjectCost::count());
    }

    /** A14: 案件の誤りと原価の誤りを一度に出す */
    public function test_project_and_cost_errors_are_shown_at_once(): void
    {
        $form = $this->fill($this->createForm($this->addCost(['cost_category' => 'material', 'actual_amount' => 'abc'])), ['project_name' => '']);

        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertErrorItem($html, '工事名は必須です。');
        $this->assertErrorItem($html, trans('validation.integer', ['attribute' => '原価明細 1 行目の実績額']));
    }

    // ============================================================
    // 編集・人員配置
    // ============================================================

    public function test_a_project_is_updated_with_costs_and_assignments_from_the_screen(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $project->costs()->create(['cost_category' => 'material', 'description' => '旧', 'estimated_amount' => 1000]);

        $form = $this->editForm($project, 'data.costRows[0].description = "アスファルト合材"; ' . $this->addAssignment(['employee_id' => (string) $employee->id, 'role' => '現場代理人', 'start_date' => '2026-06-15']));
        $form = $this->fill($form, ['project_name' => '市道一番町線 舗装補修工事（変更）']);

        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertFlash($html, 'success', '工事案件「市道一番町線 舗装補修工事（変更）」を更新しました。');
        $this->assertSame(['アスファルト合材'], DadProjectCost::pluck('description')->all());
        $assignment = DadProjectAssignment::sole();
        $this->assertSame([$employee->id, '現場代理人', '2026-06-15'], [$assignment->employee_id, $assignment->role, $assignment->start_date->format('Y-m-d')]);
    }

    /** 保存し直しても、同じ従業員の配置を消して入れ直せる（一意制約〈工事案件, 従業員〉の上で） */
    public function test_saving_again_keeps_the_same_assignment(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $project->assignments()->create(['employee_id' => $employee->id, 'role' => '現場代理人']);

        $html = $this->landed($this->submit($this->editForm($project), route('dad.projects.edit', $project)));

        $this->assertFlash($html, 'success', '工事案件「市道一番町線 舗装補修工事」を更新しました。');
        $this->assertSame([[$employee->id, '現場代理人']], DadProjectAssignment::get()->map(fn ($a) => [$a->employee_id, $a->role])->all());
    }

    /** A6: 退職した従業員の配置は、編集画面をそのまま保存しても消えない（選択肢に「（退職）」つきで残る） */
    public function test_a_retired_employee_assignment_survives_saving_the_edit_screen(): void
    {
        $active = $this->employee('D-001', '高橋 一郎');
        $retired = $this->employee('D-002', '伊藤 次郎', 'retired');
        $project = $this->project();
        $project->assignments()->create(['employee_id' => $active->id, 'role' => '現場代理人']);
        $project->assignments()->create(['employee_id' => $retired->id, 'role' => '作業員', 'start_date' => '2026-06-15', 'end_date' => '2026-07-31']);

        $html = $this->htmlOf(route('dad.projects.edit', $project));
        $this->assertMatchesRegularExpression('/<option value="' . $retired->id . '">D-002 伊藤 次郎（退職）<\/option>/u', $html);
        $form = $this->projectForm($html, route('dad.projects.update', $project));
        $this->assertSame((string) $retired->id, $form['fields']['assignments'][1]['employee_id'] ?? null, '退職した従業員の行の従業員が空で送られる（保存で配置が消える）');

        $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertSame([$active->id, $retired->id], DadProjectAssignment::orderBy('employee_id')->pluck('employee_id')->all());
    }

    /** A6 / D2: 配置されていない退職者は新しく配置できない（選択肢に出ない人を手で組んで送っても断る） */
    public function test_a_retired_employee_who_is_not_assigned_cannot_be_newly_assigned(): void
    {
        $retired = $this->employee('D-002', '伊藤 次郎', 'retired');
        $project = $this->project();

        $html = $this->htmlOf(route('dad.projects.edit', $project));
        $this->assertStringNotContainsString('伊藤 次郎', $html);
        $form = $this->editForm($project);
        $form['fields']['assignments'] = [['employee_id' => (string) $retired->id, 'role' => '', 'start_date' => '', 'end_date' => '', 'notes' => '']];

        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertErrorItem($html, trans('validation.in', ['attribute' => '人員配置 1 行目の従業員']));
        $this->assertSame(0, DadProjectAssignment::count());
    }

    /** A4: 人員配置の検査（同じ従業員の 2 行・無い従業員・長すぎる役割と備考・日付でない値・配置終了が配置開始より前） */
    public function test_assignment_rows_are_validated(): void
    {
        $employee = $this->employee();
        $project = $this->project();

        $form = $this->editForm($project,
            $this->addAssignment(['employee_id' => (string) $employee->id, 'role' => str_repeat('あ', 51), 'start_date' => '2026-07-01', 'end_date' => '2026-06-30'])
            . $this->addAssignment(['employee_id' => (string) $employee->id, 'notes' => str_repeat('い', 201)])
        );
        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertErrorItem($html, trans('validation.max.string', ['attribute' => '人員配置 1 行目の役割', 'max' => 50]));
        $this->assertErrorItem($html, '人員配置 1 行目の配置終了は、配置開始以降の日付を指定してください。');
        $this->assertErrorItem($html, trans('validation.distinct', ['attribute' => '人員配置 2 行目の従業員']));
        $this->assertErrorItem($html, trans('validation.max.string', ['attribute' => '人員配置 2 行目の備考', 'max' => 200]));
        $this->assertSame(0, DadProjectAssignment::count());
    }

    public function test_assignment_values_sent_by_hand_do_not_fail(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $form = $this->editForm($project);

        $form['fields']['assignments'] = [['employee_id' => '999', 'role' => '', 'start_date' => 'abc', 'end_date' => '', 'notes' => '']];
        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));
        $this->assertErrorItem($html, trans('validation.in', ['attribute' => '人員配置 1 行目の従業員']));
        $this->assertErrorItem($html, trans('validation.date', ['attribute' => '人員配置 1 行目の配置開始']));

        $form['fields']['assignments'] = 'abc';
        $this->submit($form, route('dad.projects.edit', $project))->assertRedirect(route('dad.projects.edit', $project));
        $this->assertSame(0, DadProjectAssignment::count());
        $this->assertNotNull($employee);
    }

    /** A13: 従業員を選んでいない人員配置の行（役割などを入れた行）を、黙って捨てずに断る */
    public function test_an_assignment_row_without_an_employee_is_refused(): void
    {
        $project = $this->project();
        $form = $this->editForm($project, $this->addAssignment(['role' => '作業員']));

        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertErrorItem($html, '人員配置 1 行目の従業員を選択してください。');
    }

    /** A5: 編集画面で入力エラーになって戻ると、人員配置の行も送ったまま描かれる */
    public function test_assignment_rows_survive_an_input_error_on_the_edit_screen(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $form = $this->editForm($project, $this->addAssignment(['employee_id' => (string) $employee->id, 'role' => '現場代理人']));
        $form = $this->fill($form, ['project_name' => '']);

        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertErrorItem($html, '工事名は必須です。');
        $again = $this->projectForm($html, route('dad.projects.update', $project));
        $this->assertSame((string) $employee->id, $again['fields']['assignments'][0]['employee_id'] ?? null, '入力エラーのあと人員配置の行が消えた');
        $this->assertSame('現場代理人', $again['fields']['assignments'][0]['role']);
    }

    /** A11: 日付の部品は値を JS の文字列として正しく書く（手で組んだ `'` や `\` を含む値で、戻った画面の JS が止まらない） */
    public function test_a_date_value_sent_back_after_an_input_error_does_not_break_the_screen_script(): void
    {
        $project = $this->project();
        $form = $this->editForm($project);
        $form['fields']['estimate_date'] = "2026'-01\\";

        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertErrorItem($html, trans('validation.date', ['attribute' => '見積日']));
        $again = $this->projectForm($html, route('dad.projects.update', $project));
        $this->assertSame('', $again['fields']['estimate_date']);
    }

    // ============================================================
    // 削除
    // ============================================================

    public function test_a_project_is_deleted_from_the_edit_screen(): void
    {
        $project = $this->project();
        $form = $this->deleteForm($this->htmlOf(route('dad.projects.edit', $project)), route('dad.projects.destroy', $project));

        $html = $this->landed($this->submit($form, route('dad.projects.edit', $project)));

        $this->assertFlash($html, 'success', '工事案件「市道一番町線 舗装補修工事」を削除しました。');
        $this->assertSame(0, DadProject::count());
    }

    // ============================================================
    // 詳細の地図の吹き出し（A12）
    // ============================================================

    /** A12: 地図の吹き出しは HTML として描かれるので、工事名・現場住所の文字を逃がしてから組む */
    public function test_the_map_info_window_does_not_render_the_project_name_as_html(): void
    {
        $project = $this->project([
            'project_name' => '<img src=x onerror=alert(1)>工事',
            'site_address' => '松山市 <b>一番町</b>',
            'latitude' => 33.8392, 'longitude' => 132.7657,
        ]);
        $html = $this->htmlOf(route('dad.projects.show', $project));

        $this->assertSame(1, preg_match('/<script>\s*(function initDadDetailMap\(\).*?)<\/script>/s', $html, $m), '地図の script が見つからない');
        $content = $this->runMapScript($m[1]);

        $this->assertStringNotContainsString('<img', $content);
        $this->assertStringNotContainsString('<b>', $content);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;工事', $content);
        $this->assertStringContainsString('松山市 &lt;b&gt;一番町&lt;/b&gt;', $content);
    }

    /** 地図の script を node で動かし、吹き出し（InfoWindow）に渡る content を返す */
    private function runMapScript(string $script): string
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので画面の JavaScript の実駆動を飛ばす');
        }
        $harness = <<<'JS'
            const vm = require('vm');
            const fs = require('fs');
            let content = null;
            const google = { maps: {
                Map: function () {},
                Marker: function () {},
                InfoWindow: function (o) { content = o.content; this.open = function () {}; },
            } };
            const document = { getElementById: function () { return {}; } };
            const ctx = vm.createContext({ google, document, console });
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8') + '\ninitDadDetailMap();', ctx);
            process.stdout.write(JSON.stringify({ content }));
            JS;
        $file = tempnam(sys_get_temp_dir(), 'dad-map-');
        try {
            file_put_contents($file, $script);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }
        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "地図の script を動かせなかった:\n" . $output);
        $this->assertIsString($result['content'], '吹き出しが作られなかった');

        return $result['content'];
    }
}
