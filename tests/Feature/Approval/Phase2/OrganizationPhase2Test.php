<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/** 部門の管理の段階2 の分（設計書 §5.4・D6〜D9・D23） */
class OrganizationPhase2Test extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26（R8）
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
    }

    /** 編集のモーダルが送るのと同じ形の中身 */
    private function payload(ApprovalDepartment $dept, array $overrides = []): array
    {
        $dept = $dept->fresh(['reviewers']);

        return array_merge([
            'company_id'   => (string) $dept->company_id,
            'name'         => $dept->name,
            'short_name'   => $dept->short_name,
            'code'         => $dept->code,
            'sort_order'   => (string) $dept->sort_order,
            'head_user_id' => $dept->head_user_id === null ? '' : (string) $dept->head_user_id,
            'reviewer_ids' => $dept->reviewers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'next_number'  => (string) ApprovalNumber::currentState($dept)['next'],
            // 画面を開いたときの値（変えていなければ番号に触らない。期の変わる日をまたいだ古い画面の対策）
            'next_number_shown' => (string) ApprovalNumber::currentState($dept)['next'],
        ], $overrides);
    }

    private function update(User $admin, ApprovalDepartment $dept, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->put(route('approvals.admin.organization.departments.update', $dept), $this->payload($dept, $overrides));
    }

    /** レイアウトの赤帯に、ちょうど 1 回だけ出ていること（段階1 の OrganizationManagementTest と同じ見方） */
    private function assertErrorBanner(string $html, string $message): void
    {
        $this->assertSame(1, substr_count($html, '<span class="text-sm text-red-800">' . e($message) . '</span>'), "赤帯に理由が出ていない: {$message}");
    }

    public function test_the_table_shows_the_head_reviewers_and_the_next_number(): void
    {
        $this->approvalWorld();
        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString('部門 長', $html);
        $this->assertStringContainsString('審査 担当', $html);
        $this->assertStringContainsString('R8-J-001', $html);
        // ⚠ 編集のモーダルへ渡す説明（number_hint）は Js::from で \uXXXX に符号化されるので、HTML の文字列では探さない
    }

    /** 追加のフォームに新しい欄が載っている（描いたフォームの往復） */
    public function test_a_department_can_be_created_with_a_head_reviewers_and_a_start_number(): void
    {
        $company = $this->approvalCompany();
        $admin   = $this->approvalAdmin();
        $head    = $this->baseUser(['name' => '部門 長']);
        $rev     = $this->baseUser(['name' => '審査 担当']);

        $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.organization.departments.store') . '"');
        $this->assertArrayHasKey('head_user_id', $form['fields']);
        $this->assertSame('1', $form['fields']['next_number']);

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'company_id'   => (string) $company->id,
            'name'         => '住宅事業部',
            'short_name'   => '住宅',
            'code'         => 'j',
            'sort_order'   => '1',
            'head_user_id' => (string) $head->id,
            'reviewer_ids' => [(string) $rev->id],
            'next_number'  => '21',
        ]))->assertRedirect(route('approvals.admin.organization.index'));

        $dept = ApprovalDepartment::where('code', 'J')->sole();
        $this->assertSame($head->id, $dept->head_user_id);
        $this->assertSame([$rev->id], $dept->reviewers()->pluck('users.id')->all());
        $this->assertSame(21, ApprovalNumber::currentState($dept)['next']);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.reviewers_changed')->count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.next_number_set')->count());
    }

    /** 選べるのは有効でメールアドレスのある人（D7） */
    public function test_only_active_users_with_mail_can_be_chosen(): void
    {
        $w        = $this->approvalWorld();
        $noMail   = $this->approvalOnlyUser(['name' => 'メール なし']);
        $inactive = $this->baseUser(['name' => '無効 の人', 'status' => UserStatus::Inactive->value]);

        $this->update($this->approvalAdmin(), $w['dept'], [
            'head_user_id' => (string) $noMail->id,
            'reviewer_ids' => [(string) $inactive->id],
        ])->assertSessionHasErrors([
            'head_user_id'   => '部門長には、有効でメールアドレスのある人を選んでください。',
            'reviewer_ids.0' => '審査担当者には、有効でメールアドレスのある人を選んでください。',
        ]);

        $this->assertSame($w['head']->id, $w['dept']->fresh()->head_user_id);
    }

    /** 許可していないドメインの人も選べるが、注意を出す（D7） */
    public function test_people_without_an_allowed_domain_are_marked(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        $this->approvalCompany();
        $this->baseUser(['name' => '外部 ドメイン', 'email' => 'x@example.com']);
        $this->baseUser(['name' => '社内 ドメイン', 'email' => 'y@mitsuwat.co.jp']);

        $html = $this->indexHtml($this->approvalAdmin(['email' => 'admin@mitsuwat.co.jp']));

        $this->assertStringContainsString('外部 ドメイン ※通知メールが届きません', $html);
        $this->assertStringNotContainsString('社内 ドメイン ※通知メールが届きません', $html);
    }

    /** 部門長・審査担当者は所属していなくてもよい（D6） */
    public function test_the_head_need_not_belong_to_the_department(): void
    {
        $w       = $this->approvalWorld();
        $outside = $this->baseUser(['name' => '所属 外']);

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => (string) $outside->id])->assertSessionHasNoErrors();

        $this->assertSame($outside->id, $w['dept']->fresh()->head_user_id);
    }

    /** 4.3 のケース 5・D23: 部門長を変えると待っている申請が移り、申請ごとに記録が残る */
    public function test_changing_the_head_moves_waiting_requests(): void
    {
        $w   = $this->approvalWorld();
        $r   = $this->submittedFor($w);
        $new = $this->baseUser(['name' => '新 部門長']);

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => (string) $new->id])
            ->assertRedirect(route('approvals.admin.organization.index'));

        $history = ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole();
        $this->assertSame($new->id, $history->meta['to_user_id']);
        $this->assertSame(['head_user_id' => $new->id], ApprovalSettingLog::where('action', 'department.updated')->sole()->new_values);
    }

    public function test_the_head_cannot_be_cleared_while_requests_wait(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => ''])
            ->assertSessionHas('error', 'この部門には部門長の確認を待っている申請が 1 件あるため、部門長を空にできません。後任を選んでください。');

        $this->assertSame($w['head']->id, $w['dept']->fresh()->head_user_id);
    }

    /** 断る理由が赤帯に出る（役割と表示を別々に見る。Bug #46・#49） */
    public function test_the_reason_the_head_cannot_be_cleared_is_shown(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->submittedFor($w);

        $this->update($admin, $w['dept'], ['head_user_id' => ''])->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertErrorBanner($this->indexHtml($admin), 'この部門には部門長の確認を待っている申請が 1 件あるため、部門長を空にできません。後任を選んでください。');
    }

    /** D9: 使った番号より小さくできない。ほかの変更もまとめて巻き戻る */
    public function test_the_next_number_cannot_go_back(): void
    {
        $w = $this->approvalWorld();
        ApprovalNumber::setNext($w['dept'], 5);
        ApprovalNumber::issue($w['dept'], now());   // R8-J-005

        $this->update($this->approvalAdmin(), $w['dept'], ['next_number' => '3', 'short_name' => '住宅2'])
            ->assertSessionHas('error', '今年度はすでに R8-J-005 まで使っています。6 以上を入れてください。');

        $this->assertSame('住宅', $w['dept']->fresh()->short_name, 'ほかの変更が巻き戻っていない');
    }

    /**
     * 期の変わる日の前に開いた画面から保存しても、前の年度の「次の番号」を新しい年度に持ち込まない
     * （番号の欄を変えていなければ触らない。変えたときは今までどおり今年度に入れる）
     */
    public function test_a_stale_form_does_not_carry_the_next_number_into_a_new_fiscal_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 06:00:00', 'UTC'));   // 日本時間 4/30 15:00（R7 年度）
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        ApprovalNumber::setNext($w['dept'], 40);

        Carbon::setTestNow(Carbon::parse('2026-05-01 00:30:00', 'UTC'));   // 日本時間 5/1 9:30（R8 年度）
        $this->update($admin, $w['dept'], ['next_number' => '40', 'next_number_shown' => '40', 'short_name' => '住宅2']);

        $this->assertSame('住宅2', $w['dept']->fresh()->short_name, 'ほかの項目が保存されていない');
        $this->assertSame(1, ApprovalNumber::currentState($w['dept']->fresh('company'))['next'], '前の年度の次の番号が新しい年度に入った');

        // 番号の欄を変えて保存すれば、新しい年度（R8）の次の番号になる
        $this->update($admin, $w['dept'], ['next_number' => '41', 'next_number_shown' => '40']);
        $this->assertSame(41, ApprovalNumber::currentState($w['dept']->fresh('company'))['next']);
    }

    /** D8: 番号を付けた申請がある部門は、会社とアルファベットを変えられない */
    public function test_company_and_code_are_locked_once_numbers_exist(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-001', 'number_department_id' => $w['dept']->id]);

        $this->update($this->approvalAdmin(), $w['dept'], ['code' => 'JX'])
            ->assertSessionHas('error', 'この部門には決裁No を付けた申請があるため、会社とアルファベットは変えられません。');

        $this->assertSame('J', $w['dept']->fresh()->code);
    }

    public function test_a_department_used_by_requests_cannot_be_deleted(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'head_user_id' => $w['head']->id]);
        $this->draftFor($w, ['department_id' => $other->id]);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $other))
            ->assertSessionHas('error', 'この部門は申請 1 件に使われているため削除できません。');

        $this->assertNotNull($other->fresh());
    }

    public function test_a_review_department_of_a_type_cannot_be_deleted(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $w['reviewDept']))
            ->assertSessionHas('error', 'この部門は申請の種類 1 件の審査部門になっているため削除できません。先に申請種類の管理で審査部門を変えてください。');
    }

    /** 開始番号を入れただけ（番号を使っていない）の部門は消せる。連番の行も一緒に消える */
    public function test_an_unused_department_with_a_start_number_can_be_deleted(): void
    {
        $company = $this->approvalCompany();
        $dept    = $this->approvalDepartment($company);
        ApprovalNumber::setNext($dept, 30);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('success', '部門を削除しました。');

        $this->assertNull($dept->fresh());
        $this->assertSame(0, ApprovalNumberSequence::count());
    }
}
