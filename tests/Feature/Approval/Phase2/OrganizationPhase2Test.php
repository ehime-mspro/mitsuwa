<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Enums\UserStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\Workflow;
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
    /** 各「編集」ボタンが openDepartmentEdit() に渡すデータ（部門の id ごと） */
    private function editRows(string $html): array
    {
        preg_match_all("/openDepartmentEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $rows = [];
        foreach ($m[1] as $inner) {
            $row = json_decode(json_decode('"' . $inner . '"'), true);
            $rows[$row['id']] = $row;
        }

        return $rows;
    }

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

    /** D6: 1 人が複数の部門の部門長を兼ねられる（例: J と JB） */
    public function test_one_person_can_head_two_departments(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '住宅（少額・追加工事）', 'short_name' => '住宅少', 'code' => 'JB']);

        $this->update($this->approvalAdmin(), $other, ['head_user_id' => (string) $w['head']->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '部門を更新しました。');

        $this->assertSame($w['head']->id, $other->fresh()->head_user_id);
        $this->assertSame($w['head']->id, $w['dept']->fresh()->head_user_id);
    }

    /** 編集で変えた審査担当者が保存され、記録は 1 回だけ（同じ人を別の順で送っても変えない・記録しない） */
    public function test_reviewers_changed_on_edit_are_saved_and_logged_once(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r2    = $this->baseUser(['name' => '審査 二']);
        $r3    = $this->baseUser(['name' => '審査 三']);
        $want  = collect([$r2->id, $r3->id])->sort()->values()->all();

        $this->update($admin, $w['reviewDept'], ['reviewer_ids' => [(string) $r3->id, (string) $r2->id]])
            ->assertSessionHas('success', '部門を更新しました。');

        $this->assertSame($want, $w['reviewDept']->reviewers()->pluck('users.id')->sort()->values()->all());
        $log = ApprovalSettingLog::where('action', 'department.reviewers_changed')->sole();
        $this->assertSame($w['reviewDept']->id, $log->target_id);
        $this->assertSame(['reviewer_ids' => [$w['reviewer']->id]], $log->old_values);
        $this->assertSame(['reviewer_ids' => $want], $log->new_values);

        // 同じ人を別の順で送っても、何も変わらず記録も付かない
        $this->update($admin, $w['reviewDept'], ['reviewer_ids' => [(string) $r2->id, (string) $r3->id]]);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.reviewers_changed')->count());
    }

    /** ほかの項目だけの保存では、待ちの申請に触らない（交代の記録も lock_version の繰り上げも無い） */
    public function test_saving_other_fields_leaves_waiting_requests_alone(): void
    {
        $w  = $this->approvalWorld();
        $r  = $this->submittedFor($w);
        $lv = $r->lock_version;

        $this->update($this->approvalAdmin(), $w['dept'], ['short_name' => '住宅2'])
            ->assertSessionHas('success', '部門を更新しました。');

        $this->assertSame(0, ApprovalHistory::where('action', 'head_changed')->count());
        $this->assertSame($lv, $r->fresh()->lock_version);
        $this->assertSame(['short_name' => '住宅2'], ApprovalSettingLog::where('action', 'department.updated')->sole()->new_values);
        $this->assertSame(0, ApprovalSettingLog::whereIn('action', ['department.reviewers_changed', 'department.next_number_set'])->count());
    }

    /** 部門長の交代は、前の部門長とともに記録する（申請の記録と設定の記録） */
    public function test_the_head_change_records_the_previous_head(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $new   = $this->baseUser(['name' => '新 部門長']);
        $admin = $this->approvalAdmin();

        $this->update($admin, $w['dept'], ['head_user_id' => (string) $new->id])->assertSessionHas('success', '部門を更新しました。');

        $h = ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole();
        $this->assertSame($w['head']->id, $h->meta['from_user_id']);
        $this->assertSame($new->id, $h->meta['to_user_id']);
        $this->assertSame($admin->id, $h->actor_user_id);

        $log = ApprovalSettingLog::where('action', 'department.updated')->sole();
        $this->assertSame(['head_user_id' => $w['head']->id], $log->old_values);
        $this->assertSame(['head_user_id' => $new->id], $log->new_values);
    }

    /** 部門長を空にできないのは、この部門の「部門長の段階」が待っているときだけ（ほかの部門・審査の段階・済んだ段階は数えない） */
    public function test_the_head_can_be_cleared_when_no_head_step_of_this_department_waits(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $wf    = app(Workflow::class);

        $this->submittedFor($w);                                                   // J: 部門長の段階が待っている（別の部門）
        $sHead = $this->baseUser(['name' => '総務 部長']);
        $w['reviewDept']->update(['head_user_id' => $sHead->id]);
        $reviewed = $this->submittedFor($w, ['subject' => '審査中の申請']);
        $wf->judgeHead($reviewed, $w['head'], $reviewed->lock_version, ApprovalStepResult::Approve, null);   // S: 審査の段階が待っている

        $kHead = $this->baseUser(['name' => '賃貸 部長']);
        $k     = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'short_name' => '賃貸', 'code' => 'K', 'head_user_id' => $kHead->id]);
        $w['applicant']->approvalDepartments()->attach($k->id);
        $done = $this->submittedFor($w, ['department_id' => $k->id, 'subject' => '賃貸の申請']);
        $wf->judgeHead($done->refresh(), $kHead, $done->lock_version, ApprovalStepResult::Approve, null); // K: 部門長の段階は済んだ

        $this->update($admin, $w['reviewDept'], ['head_user_id' => ''])->assertSessionHas('success', '部門を更新しました。');
        $this->assertNull($w['reviewDept']->fresh()->head_user_id);

        $this->update($admin, $k, ['head_user_id' => ''])->assertSessionHas('success', '部門を更新しました。');
        $this->assertNull($k->fresh()->head_user_id);
    }

    /** D8: 番号を付けた申請がある部門は、会社も変えられない（ほかの項目は直せる） */
    public function test_the_company_is_locked_once_numbers_exist(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $draft = $this->draftFor($w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-001', 'number_department_id' => $w['dept']->id]);
        $dad = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);

        $this->update($admin, $w['dept'], ['company_id' => (string) $dad->id])
            ->assertSessionHas('error', 'この部門には決裁No を付けた申請があるため、会社とアルファベットは変えられません。');
        $this->assertSame($w['company']->id, $w['dept']->fresh()->company_id);

        $this->update($admin, $w['dept'], ['short_name' => '住宅2'])->assertSessionHas('success', '部門を更新しました。');
        $this->assertSame('住宅2', $w['dept']->fresh()->short_name);
    }

    /** D8: 番号が無ければ、会社もアルファベットも変えられる（アルファベットは全角でも半角の大文字にそろう） */
    public function test_company_and_code_can_change_while_no_number_exists(): void
    {
        $w   = $this->approvalWorld();
        $dad = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);

        $this->update($this->approvalAdmin(), $w['dept'], ['company_id' => (string) $dad->id, 'code' => 'ｊｘ'])
            ->assertSessionHas('success', '部門を更新しました。');

        $fresh = $w['dept']->fresh();
        $this->assertSame($dad->id, $fresh->company_id);
        $this->assertSame('JX', $fresh->code);
    }

    /** 審査の段階だけが指している部門（種類の審査部門を別の部門へ移したあと）も削除できない（外部キーで 500 にしない） */
    public function test_a_department_used_only_by_review_steps_cannot_be_deleted(): void
    {
        $w  = $this->approvalWorld();
        $this->submittedFor($w);                                                   // 審査の段階は S を指す
        $s2 = $this->approvalDepartment($w['company'], ['name' => '経理部', 'short_name' => '経理', 'code' => 'KR']);
        $w['type']->update(['review_department_id' => $s2->id]);                  // S を使う種類は無くなる（D11: 申請は S のまま）

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $w['reviewDept']))
            ->assertSessionHas('error', 'この部門は申請 1 件に使われているため削除できません。');

        $this->assertNotNull($w['reviewDept']->fresh());
    }

    /** D7: 論理削除した人（状態は有効・メールも残る）は、部門長にも審査担当者にも選べない */
    public function test_a_deleted_user_cannot_be_chosen(): void
    {
        $w    = $this->approvalWorld();
        $gone = $this->baseUser(['name' => '削除 済み']);
        $gone->delete();

        $this->update($this->approvalAdmin(), $w['dept'], [
            'head_user_id' => (string) $gone->id,
            'reviewer_ids' => [(string) $gone->id],
        ])->assertSessionHasErrors([
            'head_user_id'   => '部門長には、有効でメールアドレスのある人を選んでください。',
            'reviewer_ids.0' => '審査担当者には、有効でメールアドレスのある人を選んでください。',
        ]);

        $this->assertSame($w['head']->id, $w['dept']->fresh()->head_user_id);
    }

    /** 選択肢に出るのは、有効でメールアドレスのある、削除していない人だけ */
    public function test_only_assignable_people_are_offered(): void
    {
        $this->approvalWorld();
        $this->baseUser(['name' => '無効 の人', 'status' => UserStatus::Inactive->value]);
        $this->approvalOnlyUser(['name' => 'メール なし']);
        $this->baseUser(['name' => '削除 済み'])->delete();

        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString('>審査 担当</span>', $html);
        $this->assertStringNotContainsString('無効 の人', $html);
        $this->assertStringNotContainsString('メール なし', $html);
        $this->assertStringNotContainsString('削除 済み', $html);
    }

    /** 編集のモーダルは今の設定で埋まる（部門長・審査担当者・番号・古い画面の対策）。フォームの x-model・hidden と JS の代入も固定する（Alpine が動くかはブラウザで見る＝Task 19） */
    public function test_the_edit_modal_is_filled_from_the_current_settings(): void
    {
        $w = $this->approvalWorld();
        ApprovalNumber::setNext($w['dept'], 5);
        ApprovalNumber::issue($w['dept'], now());                                  // R8-J-005 まで使った
        $draft = $this->draftFor($w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-005', 'number_department_id' => $w['dept']->id]);

        $html = $this->indexHtml($this->approvalAdmin());
        $rows = $this->editRows($html);

        $j = $rows[$w['dept']->id];
        $this->assertSame($w['head']->id, $j['head_user_id']);
        $this->assertSame([], $j['reviewer_ids']);
        $this->assertSame(6, $j['next_number']);
        $this->assertTrue($j['has_numbers']);
        $this->assertSame('今年度（R8）は R8-J-005 まで使っています。', $j['number_hint']);

        $s = $rows[$w['reviewDept']->id];
        $this->assertNull($s['head_user_id']);
        $this->assertSame([$w['reviewer']->id], $s['reviewer_ids']);
        $this->assertSame(1, $s['next_number']);
        $this->assertFalse($s['has_numbers']);
        $this->assertSame('今年度（R8）はまだ番号を使っていません。', $s['number_hint']);

        // 編集のフォームはその値を読む（開いたときの番号も送り返す）
        $this->assertStringContainsString('<select name="head_user_id" x-model="editDepartmentHeadId"', $html);
        $this->assertStringContainsString('name="reviewer_ids[]" value="' . $w['reviewer']->id . '" x-model="editDepartmentReviewerIds"', $html);
        $this->assertStringContainsString('<input type="number" name="next_number" x-model="editDepartmentNext"', $html);
        $this->assertStringContainsString('<input type="hidden" name="next_number_shown" :value="editDepartmentNextShown">', $html);
        $this->assertStringContainsString("this.editDepartmentHeadId = row.head_user_id === null ? '' : String(row.head_user_id);", $html);
        $this->assertStringContainsString('this.editDepartmentReviewerIds = row.reviewer_ids.map(String);', $html);
        $this->assertStringContainsString('this.editDepartmentNext = String(row.next_number);', $html);
        $this->assertStringContainsString('this.editDepartmentNextShown = String(row.next_number);', $html);
    }

    /** 表のセルそのものに部門長と審査担当者が出る（氏名は選択肢にも出るので <td> を見る） */
    public function test_the_table_cells_show_the_head_and_the_reviewers(): void
    {
        $this->approvalWorld();
        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString('whitespace-nowrap text-gray-900">部門 長</td>', $html);    // J: 部門長
        $this->assertStringContainsString('whitespace-nowrap text-red-700">未設定</td>', $html);      // S: 部門長なし（赤）
        $this->assertStringContainsString('text-[13px] text-gray-700">審査 担当</td>', $html);        // S: 審査担当者
        $this->assertStringContainsString('text-[13px] text-gray-700">—</td>', $html);                // J: 審査担当者なし
    }

    /** 開始番号の記録は前後の番号を持ち、すでにその番号なら 2 回目の記録を付けない */
    public function test_the_start_number_is_recorded_once_with_before_and_after(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $this->update($admin, $w['dept'], ['next_number' => '21', 'next_number_shown' => '1'])->assertSessionHas('success', '部門を更新しました。');
        $log = ApprovalSettingLog::where('action', 'department.next_number_set')->sole();
        $this->assertSame(['next_number' => 1], $log->old_values);
        $this->assertSame(['next_number' => 21], $log->new_values);

        // 2 人目の管理者の画面も 1 を出していて 21 を入れた: もう 21 なので記録しない
        $this->update($admin, $w['dept'], ['next_number' => '21', 'next_number_shown' => '1'])->assertSessionHas('success', '部門を更新しました。');
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.next_number_set')->count());
    }

    /** MySQL のデッドロック（1213）を避ける順は SQLite でも見える: 部門の行を更新する前に、申請の行をロック付きで読む */
    public function test_the_waiting_requests_are_locked_before_the_department_row_is_updated(): void
    {
        $w     = $this->approvalWorld();
        $this->submittedFor($w);
        $new   = $this->baseUser(['name' => '新 部門長']);
        $first = [];
        DB::listen(function ($q) use (&$first): void {
            foreach (['requests' => '/^select \* from "approval_requests" where "approval_requests"\."id" in/', 'dept' => '/^update "approval_departments"/'] as $k => $re) {
                if (! isset($first[$k]) && preg_match($re, $q->sql)) {
                    $first[$k] = count($first);
                }
            }
        });

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => (string) $new->id])->assertSessionHas('success', '部門を更新しました。');

        $this->assertSame(['requests' => 0, 'dept' => 1], $first, '部門の行を更新する前に申請の行をロックしていない（逆だと MySQL で 1213）');
    }

    /** 削除の記録に部門長が残る */
    public function test_the_deletion_record_keeps_the_head(): void
    {
        $company = $this->approvalCompany();
        $head    = $this->baseUser(['name' => '部門 長']);
        $dept    = $this->approvalDepartment($company, ['head_user_id' => $head->id]);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('success', '部門を削除しました。');

        $this->assertSame($head->id, ApprovalSettingLog::where('action', 'department.deleted')->sole()->old_values['head_user_id']);
    }

    /** 決裁No の部門（number_department_id）だけが指している部門も削除できない（外部キーで 500 にしない。Task 9 の点検の軽微） */
    public function test_a_department_referenced_only_by_a_number_cannot_be_deleted(): void
    {
        $w     = $this->approvalWorld();
        $dept  = $this->approvalDepartment($w['company'], ['name' => '番号だけの部門', 'short_name' => '番号', 'code' => 'NB']);
        $draft = $this->draftFor($w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-NB-001', 'number_department_id' => $dept->id]);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('error', 'この部門は申請 1 件に使われているため削除できません。');

        $this->assertNotNull($dept->fresh());
    }

    /** 審査を待っている申請があるうちは、審査担当者を 0 人にできない（部門長の歯止めと同じ。Task 9 の点検の軽微） */
    public function test_the_reviewers_cannot_all_be_removed_while_reviews_wait(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);   // 審査を待っている

        $this->update($admin, $w['reviewDept'], ['reviewer_ids' => []])
            ->assertSessionHas('error', 'この部門には、審査を待っている（これから審査に届くものを含む）申請が 1 件あるため、審査担当者を 0 人にできません。後任を選んでください。');
        $this->assertSame([$w['reviewer']->id], $w['reviewDept']->reviewers()->pluck('users.id')->all());

        // 入れ替える（0 人にしない）のは通る
        $next = $this->baseUser(['name' => '後任 審査']);
        $this->update($admin, $w['reviewDept'], ['reviewer_ids' => [(string) $next->id]])
            ->assertSessionHas('success', '部門を更新しました。');
        $this->assertSame([$next->id], $w['reviewDept']->reviewers()->pluck('users.id')->all());
    }

    /** 削除の記録に、審査担当者と年度ごとの次の番号も残す（どちらも部門と一緒に消えるため。Task 9 の点検の軽微） */
    public function test_the_deletion_record_keeps_the_reviewers_and_the_next_numbers(): void
    {
        $company = $this->approvalCompany();
        $dept    = $this->approvalDepartment($company, ['name' => '審査だけの部門']);
        $r1      = $this->baseUser(['name' => '審査 一']);
        $r2      = $this->baseUser(['name' => '審査 二']);
        $dept->reviewers()->attach([$r2->id, $r1->id]);
        ApprovalNumber::setNext($dept, 21);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('success', '部門を削除しました。');

        $old = ApprovalSettingLog::where('action', 'department.deleted')->sole()->old_values;
        $this->assertSame(collect([$r1->id, $r2->id])->sort()->values()->all(), $old['reviewer_ids']);
        $this->assertSame([2026 => 21], $old['next_numbers']);
    }

    /** 画面の文言に設計の記号（「要件 6.4」「D8」）を出さない（決裁の管理者には意味が分からない。Task 9 の点検の軽微） */
    public function test_the_screen_does_not_show_design_references(): void
    {
        $this->approvalWorld();
        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString('紙で 20 番まで使っていたら 21 を入れる</p>', $html);
        $this->assertStringContainsString('決裁No を付けた申請があるため、会社とアルファベットは変えられません。</p>', $html);
        $this->assertStringNotContainsString('要件 6.4', $html);
        $this->assertStringNotContainsString('（D8）', $html);
    }

    /** 決裁No を付けた申請がある部門を持つ会社は、期の始まりの月を変えられない（D8 と同じ理由。Task 9 の点検の申し送り） */
    public function test_the_fiscal_start_month_is_locked_once_numbers_exist(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $month = $w['company']->fiscal_start_month;
        $other = $month === 6 ? 5 : 6;
        $route = route('approvals.admin.organization.companies.update', $w['company']);
        $send  = fn (int $m, ?string $name = null) => ['name' => $name ?? $w['company']->name, 'fiscal_start_month' => (string) $m, 'sort_order' => (string) $w['company']->sort_order];

        // 番号が無いうちは変えられる
        $this->actingAs($admin)->put($route, $send($other))->assertSessionHas('success', '会社を更新しました。');
        $this->actingAs($admin)->put($route, $send($month))->assertSessionHas('success', '会社を更新しました。');

        $draft = $this->draftFor($w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-001', 'number_department_id' => $w['dept']->id]);

        $this->actingAs($admin)->put($route, $send($other))
            ->assertSessionHas('error', 'この会社には決裁No を付けた申請がある部門があるため、期の始まりの月は変えられません。');
        $this->assertSame($month, $w['company']->fresh()->fiscal_start_month);

        // 名前や並びは変えられる
        $this->actingAs($admin)->put($route, $send($month, '名前を直した会社'))->assertSessionHas('success', '会社を更新しました。');
        $this->assertSame('名前を直した会社', $w['company']->fresh()->name);
    }

    /** 部門長の確認中で、これから審査に届く申請があるうちも、審査担当者を 0 人にできない（部門長が承認すると審査で止まるため。Task 9 の再点検の軽微） */
    public function test_the_reviewers_cannot_all_be_removed_while_reviews_are_coming(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);   // 部門長の確認中（審査の段階はまだ届いていない）

        $this->update($this->approvalAdmin(), $w['reviewDept'], ['reviewer_ids' => []])
            ->assertSessionHas('error', 'この部門には、審査を待っている（これから審査に届くものを含む）申請が 1 件あるため、審査担当者を 0 人にできません。後任を選んでください。');
        $this->assertSame([$w['reviewer']->id], $w['reviewDept']->reviewers()->pluck('users.id')->all());
    }

    /** 審査が済んだ・取り下げで打ち切った審査の段階しか無ければ、審査担当者を 0 人にできる（数えるのは待ち・これから届く段階だけ） */
    public function test_reviewers_can_be_emptied_once_the_reviews_are_done_or_cancelled(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $wf    = app(Workflow::class);

        $done = $this->submittedFor($w, ['subject' => '審査が済んだ申請']);
        $wf->judgeHead($done, $w['head'], $done->lock_version, ApprovalStepResult::Approve, null);
        $done->refresh();
        $wf->judgeReview($done, $w['reviewer'], $done->lock_version, ApprovalStepResult::Ok, null);          // S: 審査は済んだ

        $gone = $this->submittedFor($w, ['subject' => '取り下げた申請']);
        $wf->judgeHead($gone, $w['head'], $gone->lock_version, ApprovalStepResult::Approve, null);
        $gone->refresh();
        $wf->withdraw($gone, $w['applicant'], $gone->lock_version, null);                                    // S: 審査は打ち切り

        $this->update($admin, $w['reviewDept'], ['reviewer_ids' => []])->assertSessionHas('success', '部門を更新しました。');
        $this->assertSame(0, $w['reviewDept']->reviewers()->count());
    }
}
