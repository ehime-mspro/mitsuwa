<?php

namespace Tests\Feature\Zeal;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use App\Models\ZealSimulation;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesZealSimulationSchema;
use Tests\TestCase;

/**
 * 経営試算表の編集で、検証に落ちた**理由が画面に出る**こと。
 *
 * ⚠ **2026-08-17 に本番でこの画面が無音だった。** `update()` は name（max:100）と
 *   notes（max:5000）を検証するのに、ビューにはサマリも `@error` も 1 つも無く、
 *   101 文字の名前を送ると `old()` で入力だけ戻って**理由が一切出なかった**
 *   （本番で再現。保存もされないので利用者からは「更新ボタンが効かない」に見える）。
 *
 * ⚠ **`layouts/app.blade.php` は `$errors` を描画しない**（`session('success')` /
 *   `session('error')` のみ）。だから各フォーム画面が自前で出す必要があり、
 *   書き忘れると無音になる。ビューが表示手段を持つこと自体は
 *   `ValidationErrorFeedbackTest` が全件分類で守っている。ここは**実際に出るか**を見る。
 *
 * ⚠ **`assertSessionHasErrors()` を呼んではいけない**（Bug #49）。呼ぶと、そのあと描画した
 *   画面から**エラー表示が丸ごと消える**。`old()` の復元だけは生き残るので、
 *   入力保持を見ているテストでは気づけない。期待文言はセッションから取らず
 *   `trans()` で組み立てる。
 */
class SimulationValidationFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSimulationSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
        $this->seed(DepartmentSeeder::class);
    }

    /** zeal 部門所属の経営層（`department.access:zeal` と `role:executive,manager` を通す） */
    private function actor(): User
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'zeal')->value('id'));

        return $user;
    }

    private function makeSimulation(): ZealSimulation
    {
        return ZealSimulation::create([
            'fiscal_year' => 2025,
            'name'        => '令和7年度',
            'notes'       => null,
        ]);
    }

    public function test_edit_screen_renders(): void
    {
        $simulation = $this->makeSimulation();

        $this->actingAs($this->actor())
            ->get(route('zeal.simulations.edit', $simulation))
            ->assertOk();
    }

    /**
     * 長すぎる名称を送ったとき、**理由が画面に出る**こと。
     *
     * ⚠ ここが赤くなるのは「サマリのマークアップが無い」ときだけでなく、
     *   `old()` の復元が壊れたときも同じ。両方を別々にアサートして役割を分ける
     *   （まとめると片方だけ壊れても緑になる。Bug #43 / #49）。
     */
    public function test_a_too_long_name_shows_the_reason_on_screen(): void
    {
        $simulation = $this->makeSimulation();
        $actor      = $this->actor();
        $editUrl    = route('zeal.simulations.edit', $simulation);
        $tooLong    = str_repeat('あ', 101);

        // ⚠ 期待文言はセッションから取らず trans() で組み立てる（Bug #49）
        $expected = trans('validation.max.string', ['attribute' => '名称', 'max' => 100]);

        $this->actingAs($actor)
            ->from($editUrl)
            ->put(route('zeal.simulations.update', $simulation), [
                'mode'  => 'actual',
                'name'  => $tooLong,
                'notes' => '',
            ])
            ->assertRedirect($editUrl);

        // 差し戻された画面を実際に描画して見る
        $html = $this->actingAs($actor)->get($editUrl)->getContent();

        // ① 理由が画面に出ている（サマリ）
        $this->assertStringContainsString(
            '入力内容にエラーがあります',
            $html,
            'エラーサマリの見出しが出ていない（この画面は 2026-08-17 まで無音だった）'
        );
        $this->assertStringContainsString(
            '<li>' . e($expected) . '</li>',
            $html,
            'サマリに具体的な理由が並んでいない'
        );

        // ② 入力が消えていない（①とは別の機構。役割ごとに分けてアサートする）
        $this->assertStringContainsString(
            'value="' . e($tooLong) . '"',
            $html,
            'old() の復元が効いていない（入力が消える）'
        );

        // ③ 保存されていない
        $this->assertSame('令和7年度', $simulation->fresh()->name, '検証に落ちたのに保存された');
    }

    /** 正常な値なら保存でき、エラー表示は出ないこと（①の対偶。常時赤いテストでないことの確認） */
    public function test_a_valid_name_is_saved_without_any_error_display(): void
    {
        $simulation = $this->makeSimulation();
        $actor      = $this->actor();
        $editUrl    = route('zeal.simulations.edit', $simulation);

        $this->actingAs($actor)
            ->from($editUrl)
            ->put(route('zeal.simulations.update', $simulation), [
                'mode'  => 'actual',
                'name'  => '令和8年度 経営試算表',
                'notes' => '',
            ]);

        $this->assertSame('令和8年度 経営試算表', $simulation->fresh()->name);

        $html = $this->actingAs($actor)->get($editUrl)->getContent();
        $this->assertStringNotContainsString('入力内容にエラーがあります', $html);
    }
}
