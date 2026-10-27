<?php

namespace Tests\Feature\Approval\Phase6;

use App\Enums\UserStatus;
use App\Http\Controllers\Approval\LaunchController;
use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Support\Approval\LaunchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * ⑬ 使い始め（段階6 設計書 §5.9・計画 §0.8）。決裁の管理者だけが開く。
 *
 * 欄 1「試す人」: 役のある人の一覧から試す人にする・外す。役の無い人は名前か社員番号で探して足す。
 * 欄 2「試せる部門と種類」: 社長・部門ごとの部門長・種類ごとの審査の担当を ○／× で、いちばん下に「いま試せる組み合わせ」。
 * 欄 3・4（片付け・使い始める）は 6b で足す。使い始めたあとは試す人を変えられない。
 */
class LaunchScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    public function test_the_screen_lists_the_role_holders_and_the_testers(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin(['name' => '管理 一郎']);
        $this->makeTester($w['head']);

        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))->assertOk()
            ->assertSeeInOrder(['使い始め', '試す期間', '試す人 1 人'])
            ->assertSeeInOrder([$w['president']->name, '社長', $w['head']->name, '部門長（住宅事業部）', $w['reviewer']->name, '審査の担当（総務部）', '管理 一郎', '決裁の管理者'])
            ->assertSee(route('approvals.admin.launch.testers.destroy', $w['head']));
    }

    /** 「試す人にする」「外す」は 1 回だけ送る（決裁の管理の画面の部品。PaperRecordCrudTest::test_the_save_form_is_sent_only_once と同じ形） */
    public function test_the_tester_forms_are_sent_only_once(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);
        $html = $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.launch.index'))->getContent();

        foreach ([route('approvals.admin.launch.testers.store'), route('approvals.admin.launch.testers.destroy', $w['head'])] as $action) {
            $pos = strpos($html, 'action="' . $action . '"');
            $this->assertNotFalse($pos, "{$action} のフォームが無い");
            $form = substr($html, strrpos(substr($html, 0, $pos), '<form'));
            $form = substr($form, 0, strpos($form, '</form>'));
            $open = substr($form, 0, strpos($form, '>') + 1);
            foreach (['x-data="approvalSubmitOnce()"', 'x-on:submit="onSubmit($event)"', 'x-on:pageshow.window="resetSubmit()"'] as $attribute) {
                $this->assertStringContainsString($attribute, $open, "{$action} のフォームに {$attribute} が無い");
            }
            $this->assertMatchesRegularExpression('/<button type="submit" :disabled="submitting"/', $form);
        }
        $this->assertSame(1, substr_count($html, 'function approvalSubmitOnce()'), '二度押し止めの部品がページに 1 回だけ描かれていない');
    }

    public function test_adding_and_removing_a_tester_is_recorded(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $w['applicant']->id])
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('success', "{$w['applicant']->name} さんを試す人にしました。");
        $this->assertTrue(LaunchState::isTester($w['applicant']->fresh()));

        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $w['applicant']))
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('success', "{$w['applicant']->name} さんを試す人から外しました。");
        $this->assertFalse(LaunchState::isTester($w['applicant']->fresh()));

        $this->assertSame(
            [['member.tester_added', ['is_tester' => false], ['is_tester' => true]], ['member.tester_removed', ['is_tester' => true], ['is_tester' => false]]],
            ApprovalSettingLog::where('target_type', 'user')->where('target_id', $w['applicant']->id)->orderBy('id')->get()->map(fn ($l) => [$l->action, $l->old_values, $l->new_values])->all(),
        );
    }

    public function test_only_an_active_user_who_is_not_deleted_can_be_added(): void
    {
        $admin    = $this->approvalAdmin();
        $inactive = $this->approvalOnlyUser(['status' => UserStatus::Inactive->value]);
        $deleted  = tap($this->approvalOnlyUser())->delete();

        foreach ([$inactive->id, $deleted->id, 999999, 'abc'] as $id) {
            $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $id])
                ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHasErrors('user_id');
        }
        $this->assertSame(0, ApprovalMember::where('is_tester', true)->count());

        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $inactive->id]);
        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))->assertSee('有効な利用者を選んでください。');   // 断りの理由が画面に出る
    }

    public function test_adding_a_tester_twice_or_removing_a_non_tester_changes_nothing(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->makeTester($w['head']);

        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $w['head']->id])
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('error', "{$w['head']->name} さんはすでに試す人です。");
        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $w['applicant']))
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('error', "{$w['applicant']->name} さんは試す人ではありません。");
        $this->assertSame(0, ApprovalSettingLog::whereIn('action', ['member.tester_added', 'member.tester_removed'])->count());
    }

    public function test_an_inactive_or_deleted_tester_is_listed_and_can_be_removed(): void
    {
        $admin    = $this->approvalAdmin();
        $inactive = $this->approvalOnlyUser(['name' => '無効 試す']);
        $deleted  = $this->approvalOnlyUser(['name' => '削除 試す']);
        $this->makeTester($inactive, $deleted);
        $inactive->forceFill(['status' => UserStatus::Inactive->value])->save();
        $deleted->delete();

        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))
            ->assertSeeInOrder(['無効 試す', '無効'])->assertSeeInOrder(['削除 試す', '削除済み']);

        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $deleted->id))->assertRedirect(route('approvals.admin.launch.index'));
        $this->assertFalse(ApprovalMember::where('user_id', $deleted->id)->sole()->is_tester);
    }

    public function test_the_search_finds_people_without_a_role(): void
    {
        $admin = $this->approvalAdmin();
        $found = $this->approvalOnlyUser(['name' => '鈴木 三郎', 'employee_number' => 'M0123']);
        foreach (range(1, LaunchController::SEARCH_LIMIT + 1) as $i) {
            $this->approvalOnlyUser(['name' => "佐藤 {$i}"]);
        }

        $this->actingAs($admin)->get(route('approvals.admin.launch.index', ['q' => 'M0123']))->assertSee('鈴木 三郎');
        $this->actingAs($admin)->get(route('approvals.admin.launch.index', ['q' => '佐藤']))->assertSee('20 人まで出しています。絞り込んでください。');
        $this->actingAs($admin)->get(route('approvals.admin.launch.index', ['q' => ['佐藤']]))->assertOk();   // 配列でも 500 にしない
    }

    public function test_the_coverage_shows_the_marks_and_the_missing_people(): void
    {
        $w = $this->trialWorld();
        $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'code' => 'F', 'head_user_id' => $this->baseUser(['name' => '田中 次郎'])->id]);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.launch.index'))
            ->assertSeeInOrder(['試せる部門と種類', 'ミツワ不動産', '田中 次郎', 'いま試せる組み合わせ', "住宅事業部 × {$w['type']->name}"]);
    }

    public function test_after_launch_the_testers_cannot_be_changed(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->makeTester($w['head']);
        $this->launchApprovals();

        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))->assertSee('使い始めました（')->assertDontSee('試す人にする');
        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $w['applicant']->id])
            ->assertSessionHas('error', '使い始めたあとは試す人を変えられません。');
        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $w['head']))
            ->assertSessionHas('error', '使い始めたあとは試す人を変えられません。');
        $this->assertSame([$w['head']->id], ApprovalMember::where('is_tester', true)->pluck('user_id')->all());
    }
}
