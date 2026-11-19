<?php

namespace Tests\Feature\Dad\Screens;

use App\Enums\UserRole;
use Illuminate\Support\Carbon;

/**
 * DAD の一覧の崩れたクエリ（A8。Bug #97 の形）と、権限の無いボタン（A9。Bug #96 の形）。
 *
 * ⚠ ボタンは「押せる人にだけ出す」。ルートの役割（登録・編集＝経営層と管理者／削除＝経営層）と同じ判定で見る。
 */
class ListAndPermissionTest extends DadScreenTestCase
{
    /** A8: 手で組んだ URL（配列・数字でない年度）でも一覧が 500 にならない */
    public function test_malformed_list_queries_do_not_fail(): void
    {
        $this->project(['project_name' => '工事A', 'order_date' => '2026-06-01']);
        foreach ([
            route('dad.projects.index') . '?fiscal_year=abc',
            route('dad.projects.index') . '?fiscal_year[]=2026',
            route('dad.projects.index') . '?project_type[]=public',
            route('dad.projects.index') . '?keyword[]=a',
            route('dad.projects.index') . '?staff_user_id=abc',
            route('dad.clients.index') . '?keyword[]=a',
            route('dad.clients.index') . '?client_type[]=a',
            route('dad.subcontractors.index') . '?keyword[]=a',
            route('dad.subcontractors.index') . '?specialty_id[]=1',
            route('dad.employees.index') . '?keyword[]=a',
            route('dad.employees.index') . '?status[]=a',
        ] as $url) {
            $this->actingAs($this->user)->get($url)->assertOk();
        }
    }

    /** 正しい年度の絞り込みは今までどおり効く（崩れた値を外すだけ） */
    public function test_the_fiscal_year_filter_still_works(): void
    {
        $this->project(['project_name' => '2026年度の工事', 'order_date' => '2026-06-01']);
        $this->project(['project_name' => '2024年度の工事', 'order_date' => '2024-06-01']);

        $html = $this->htmlOf(route('dad.projects.index', ['fiscal_year' => 2026]));

        $this->assertStringContainsString('2026年度の工事', $html);
        $this->assertStringNotContainsString('2024年度の工事', $html);
    }

    /**
     * DAD の年度は 6 月始まり（CLAUDE.md・ZealFiscalYear と同じ。Bug #141。直す前は 5 月始まりだった）。
     * 境目の両側（5/31 と 6/1）を、年度の始まりと終わりの両方で見る。見積日だけの工事も入る。
     */
    public function test_the_fiscal_year_filter_starts_in_june(): void
    {
        $this->project(['project_name' => '工事・5月末', 'order_date' => '2026-05-31']);
        $this->project(['project_name' => '工事・6月1日', 'order_date' => '2026-06-01']);
        $this->project(['project_name' => '工事・翌年の5月末', 'order_date' => '2027-05-31']);
        $this->project(['project_name' => '工事・翌年の6月1日', 'order_date' => '2027-06-01']);
        $this->project(['project_name' => '工事・見積日だけ', 'estimate_date' => '2026-06-15']);

        $html = $this->htmlOf(route('dad.projects.index', ['fiscal_year' => 2026]));
        foreach (['工事・6月1日', '工事・翌年の5月末', '工事・見積日だけ'] as $name) {
            $this->assertStringContainsString($name, $html, "2026 年度に「{$name}」が出ていない");
        }
        foreach (['工事・5月末', '工事・翌年の6月1日'] as $name) {
            $this->assertStringNotContainsString($name, $html, "2026 年度に「{$name}」が出ている");
        }

        $html = $this->htmlOf(route('dad.projects.index', ['fiscal_year' => 2025]));
        $this->assertStringContainsString('工事・5月末', $html, '2025 年度に 5/31 の工事が出ていない');
        $this->assertStringNotContainsString('工事・6月1日', $html, '2025 年度に 6/1 の工事が出ている');
    }

    /** 年度の選択肢の先頭（今年度）は、日本時間の 6/1 0:00 に変わる */
    public function test_the_current_fiscal_year_turns_over_on_june_first_in_japan(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-31 14:59:00', 'UTC')); // 日本時間 5/31 23:59
            $this->assertSame('2025', $this->firstFiscalYearOption(), '5/31 はまだ 2025 年度');

            Carbon::setTestNow(Carbon::parse('2026-05-31 15:00:00', 'UTC')); // 日本時間 6/1 0:00
            $this->assertSame('2026', $this->firstFiscalYearOption(), '6/1 から 2026 年度');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function firstFiscalYearOption(): string
    {
        $html = $this->htmlOf(route('dad.projects.index'));
        $this->assertSame(1, preg_match('/<select name="fiscal_year".*?<\/select>/su', $html, $select), '年度の選択欄が無い');
        $this->assertSame(1, preg_match('/<option value="(\d{4})"/', $select[0], $year), '年度の選択肢が無い');

        return $year[1];
    }

    /** A9: 一般担当には、一覧の「新規登録」「編集」と工事案件の詳細の「編集」を出さない（押すと 403） */
    public function test_staff_do_not_see_register_or_edit_links(): void
    {
        $staff = $this->member(UserRole::Staff, '一般 担当');
        $project = $this->project();
        $client = $this->client();
        $sub = $this->subcontractor();
        $employee = $this->employee();
        $resources = ['projects' => $project, 'clients' => $client, 'subcontractors' => $sub, 'employees' => $employee];

        foreach ($resources as $name => $model) {
            $html = $this->actingAs($staff)->get(route("dad.{$name}.index"))->assertOk()->getContent();
            $this->assertStringNotContainsString('href="' . route("dad.{$name}.create") . '"', $html, "{$name} の一覧に新規登録が出ている");
            $this->assertStringNotContainsString('href="' . route("dad.{$name}.edit", $model) . '"', $html, "{$name} の一覧に編集が出ている");
            $this->actingAs($staff)->get(route("dad.{$name}.create"))->assertForbidden();
        }
        $html = $this->actingAs($staff)->get(route('dad.projects.show', $project))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="' . route('dad.projects.edit', $project) . '"', $html, '工事案件の詳細に編集が出ている');
    }

    /** A9: 管理者は登録・編集のボタンを見るが、編集画面の削除は見ない（削除は経営層だけ） */
    public function test_managers_see_edit_links_but_not_the_delete_button(): void
    {
        $manager = $this->member(UserRole::Manager, '管理 者');
        $resources = ['projects' => $this->project(), 'clients' => $this->client(), 'subcontractors' => $this->subcontractor(), 'employees' => $this->employee()];

        foreach ($resources as $name => $model) {
            $html = $this->actingAs($manager)->get(route("dad.{$name}.index"))->assertOk()->getContent();
            $this->assertStringContainsString('href="' . route("dad.{$name}.create") . '"', $html);
            $this->assertStringContainsString('href="' . route("dad.{$name}.edit", $model) . '"', $html);

            $html = $this->actingAs($manager)->get(route("dad.{$name}.edit", $model))->assertOk()->getContent();
            // 更新と削除は同じ URL（PUT と DELETE）なので、送り先ではなく DELETE を送る hidden の有無で見る
            $this->assertStringNotContainsString('name="_method" value="DELETE"', $html, "{$name} の編集画面に削除のフォームが出ている");
            $this->assertStringNotContainsString('confirmDelete = !confirmDelete', $html, "{$name} の編集画面に削除のボタンが出ている");
            $this->actingAs($manager)->delete(route("dad.{$name}.destroy", $model))->assertForbidden();
        }
        $project = $resources['projects'];
        $html = $this->actingAs($manager)->get(route('dad.projects.show', $project))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('dad.projects.edit', $project) . '"', $html);
    }

    /** 経営層には編集画面の削除が出る */
    public function test_executives_see_the_delete_button_on_edit_screens(): void
    {
        $resources = ['projects' => $this->project(), 'clients' => $this->client(), 'subcontractors' => $this->subcontractor(), 'employees' => $this->employee()];

        foreach ($resources as $name => $model) {
            $html = $this->htmlOf(route("dad.{$name}.edit", $model));
            $this->deleteForm($html, route("dad.{$name}.destroy", $model));
            $this->assertMatchesRegularExpression('/@click="confirmDelete = !confirmDelete"[^>]*>削除<\/button>/u', $html, "{$name} の編集画面に削除のボタンが無い");
        }
    }
}
