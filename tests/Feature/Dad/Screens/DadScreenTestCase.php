<?php

namespace Tests\Feature\Dad\Screens;

use App\Enums\UserRole;
use App\Models\DadClient;
use App\Models\DadEmployee;
use App\Models\DadProject;
use App\Models\DadSpecialty;
use App\Models\DadSubcontractor;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ComposesScreenForms;
use Tests\Concerns\CreatesDadSchema;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsScreenForms;
use Tests\TestCase;

/**
 * DAD（土木事業）の画面のテストの土台（発注者・協力業者・従業員・工事案件の登録・編集・削除を、描いた画面から送る往復で見る）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。工事案件のフォームは原価・人員配置の行を Alpine（projectForm()）が `<template x-for>` で描き、
 *   日付は入れ子の部品（datePicker）が hidden に入れるので、projectForm() で JS の状態から組む。
 * ⚠ `dad_*` は raw SQL 管理でテスト用スキーマ（CreatesDadSchema）に外部キーが無い。本番は原価・人員配置→工事案件が CASCADE。
 * ⚠ 入力エラーは各画面の上の赤い箱に 1 件ずつ `<p class="text-sm text-red-800">` で出る（帯の `<span>` とは別）。1 件の全文で見る（Bug #49）。
 */
abstract class DadScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use CreatesDadSchema;
    use SubmitsScreenForms;
    use ComposesScreenForms;

    /** 本番の金額の列（INT UNSIGNED）の上限 */
    protected const UINT_MAX = 4294967295;

    protected User $user;

    private bool $departmentsSeeded = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDadSchema();

        $this->user = $this->member(UserRole::Executive);
    }

    /**
     * DAD の部署に属する利用者（経営層以外は部署の紐付けが無いと 403）。
     * ⚠ DepartmentSeeder は冪等ではないので 1 度だけ流す。
     */
    protected function member(UserRole $role, string $name = '確認 太郎'): User
    {
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }
        $user = User::factory()->create(['name' => $name, 'role' => $role->value, 'must_change_password' => false]);
        $user->departments()->attach(Department::where('code', 'dad')->value('id'));

        return $user;
    }

    /** 画面の上の入力エラーの箱に $message が出ている（1 件の全文） */
    protected function assertErrorItem(string $html, string $message): void
    {
        $pattern = '/<p class="text-sm text-red-800">\s*' . preg_quote(e($message), '/') . '\s*<\/p>/u';
        $this->assertSame(1, preg_match($pattern, $html), "入力エラーに「{$message}」が出ていない");
    }

    protected function client(array $overrides = []): DadClient
    {
        return DadClient::create(array_merge([
            'client_type' => 'municipality',
            'name' => '松山市 都市整備部',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function specialty(string $name = '土工', bool $active = true): DadSpecialty
    {
        return DadSpecialty::create(['name' => $name, 'is_active' => $active, 'sort_order' => DadSpecialty::count() + 1]);
    }

    protected function subcontractor(array $overrides = []): DadSubcontractor
    {
        return DadSubcontractor::create(array_merge([
            'company_name' => '有限会社 土木サービス',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function employee(string $code = 'D-001', string $name = '高橋 一郎', string $status = 'active'): DadEmployee
    {
        return DadEmployee::create(['employee_code' => $code, 'name' => $name, 'status' => $status]);
    }

    protected function project(array $overrides = []): DadProject
    {
        return DadProject::create(array_merge([
            'project_code' => 'DAD-' . str_pad((string) (DadProject::count() + 1), 3, '0', STR_PAD_LEFT),
            'project_name' => '市道一番町線 舗装補修工事',
            'project_type' => 'public',
            'status' => 'in_progress',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /**
     * 削除フォーム（`@method('DELETE')`）を描いた画面から取る。
     * ⚠ 編集画面は更新のフォームと削除のフォームが同じ URL を送り先にする（PUT と DELETE）。送り先が $action のフォームのうち DELETE を送るものを選ぶ。
     * ⚠ DAD の編集画面は 2 つのフォームの開きタグが同じ文字列なので、タグの文字列でなく位置から読む。
     */
    protected function deleteForm(string $html, string $action): array
    {
        preg_match_all('/<form\b([^>]*\baction="' . preg_quote($action, '/') . '"[^>]*)>/', $html, $tags, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($tags[1] as [$attributes, $offset]) {
            $form = $this->parseForm(substr($html, $offset - 5), $attributes);
            if (($form['fields']['_method'] ?? null) === 'DELETE') {
                $found[] = $form;
            }
        }
        $this->assertCount(1, $found, "送り先が {$action} の削除フォームがちょうど 1 つ描かれていない");

        return $found[0];
    }

    /**
     * 工事案件の登録・編集画面のフォームを、ブラウザが送る項目で組む（$steps は projectForm の `data` を操る JS。
     * 先に `data.initForm()`＝画面の `x-init` を呼ぶ）。日付の部品（datePicker）は入れ子のコンポーネントとして描かれた順に評価する
     * （$dateSteps は部品ごとの JS。見積日・受注日・入金日・着工日・完工日・工期開始・工期終了の順）。
     *
     * ⚠ 人員配置の行の日付の部品（_date-picker-row）は `<template x-for>` の中の入れ子で、道具はそこを評価できない。
     *   部品は選んだ日を `$watch` で行の値（`a.start_date`）へ写し、送る hidden の値はその日なので、部品を「行の値を送る hidden」に置き換えて組む。
     *   （行の値が日付として読めない文字のときだけ、部品の hidden は空になる。画面からは日付しか入らない）
     *
     * @param  array<int, string>  $dateSteps
     */
    protected function projectForm(string $html, string $action, string $steps = '', array $dateSteps = []): array
    {
        $html = $this->replaceRowDatePickers($html);

        return $this->composedForm($html, $action, 'projectForm', null, ['datePicker' => $dateSteps], 'data.initForm(); ' . $steps);
    }

    private function replaceRowDatePickers(string $html): string
    {
        while (preg_match('/<div class="date-picker-wrap"\s+x-data="datePicker\(([a-z_]+\.[a-z_]+)\)"/', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $section = $this->balancedElement($html, $start, 'div');
            $this->assertSame(1, preg_match('/<input type="hidden" :name="([^"]+)" :value="isoValue">/', $section, $hidden), '行の日付の部品の hidden が見つからない');
            $replacement = '<input type="hidden" :name="' . $hidden[1] . '" :value="' . $m[1][0] . '">';
            $html = substr_replace($html, $replacement, $start, strlen($section));
        }

        return $html;
    }
}
