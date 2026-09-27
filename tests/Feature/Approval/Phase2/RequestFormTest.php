<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 申請書（画面②）・下書き・提出・削除・コピーと、詳細の中身（設計書 §5.6・§5.12）。
 *
 * ⚠ 画面の文言を見るテストでは assertSessionHas*() を呼ばない（呼ぶとエラーの表示が消える。Bug #49）。
 */
class RequestFormTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    /** 中身のそろった入力（そのまま提出できる） */
    private function filled(array $w, array $overrides = []): array
    {
        return array_merge([
            'type_id'         => (string) $w['type']->id,
            'department_id'   => (string) $w['dept']->id,
            'subject'         => '社用車の購入',
            'amount'          => '2,850,000',
            'schedule'        => '2026年10月',
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため",
            'related_numbers' => [],
        ], $overrides);
    }

    /** 描いたフォーム（関連する決裁No は JS が描くので、x-for の中の空の hidden は送らない） */
    private function renderedForm(string $html, string $action): array
    {
        $form = $this->parseForm($html, 'action="' . $action . '"');
        unset($form['fields']['related_numbers[]']);

        return $form;
    }

    /** 申請者の編集の画面が描いたフォーム（送り返せば保存になる） */
    private function editFormOf(array $w, ApprovalRequest $request): array
    {
        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();

        return $this->renderedForm($html, route('approvals.requests.update', $request));
    }

    public function test_the_create_page_offers_active_types_and_preselects_the_only_department(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $stopped = $this->approvalType($w['reviewDept'], ['name' => '停止した種類', 'is_active' => false]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();
        $form = $this->renderedForm($html, route('approvals.requests.store'));

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertSame((string) $w['dept']->id, $form['fields']['department_id']);
        $this->assertSame('', $form['fields']['type_id']);
        $this->assertSame('', $form['fields']['amount'], '金額に 0 の既定値を入れない');
        $this->assertStringContainsString($w['type']->name, $html);
        $this->assertStringNotContainsString($stopped->name, $html);

        // 見出しは種類を選んだときに JS が入れる。対応表と部品の定義が同じページに載っていること
        $this->assertStringContainsString(Js::from([$w['type']->id => BodyTemplate::DEFAULT])->toHtml(), $html);
        $this->assertStringContainsString('function approvalRequestForm()', $html);
        // 保存と提出の区別は、サーバーが描いたボタンの値が送る（Bug #47）
        $this->assertStringContainsString('name="intent" value="save"', $html);
        $this->assertStringContainsString('name="intent" value="submit"', $html);

        // 候補の検索は X-Requested-With を付けて呼ぶ（Bug #35。基幹の走査は /api/ しか見ないのでここで見る）
        $fetchAt = strpos($html, "fetch('" . route('approvals.numbers.search'));
        $this->assertNotFalse($fetchAt, '候補の検索の fetch が見つからない');
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", substr($html, $fetchAt, 300));
    }

    /** 下書きは途中でも保存できる（D13）。初めての保存で行ができ、編集の画面へ移る */
    public function test_a_partial_draft_can_be_saved(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        // 送られてきた user_id は使わない（持ち主は必ずログイン中の人。Task 3 の点検の申し送り）
        $response = $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), ['subject' => '途中まで', 'intent' => 'save', 'user_id' => (string) $w['head']->id]);

        $request = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.requests.edit', $request))->assertSessionHas('success', '下書きを保存しました。');
        $this->assertSame(ApprovalStatus::Draft, $request->status);
        $this->assertSame('途中まで', $request->subject);
        $this->assertNull($request->type_id);
        $this->assertSame($w['applicant']->id, $request->user_id);
    }

    /** 金額はカンマ・全角・「円」を落とし、関連する決裁No はそろえて重複を除く */
    public function test_inputs_are_normalized_before_saving(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->filled($w, [
            'amount'          => '２８，５００，０００円',
            'related_numbers' => ['ｒ７－ｊ－０１５', 'R7-J-015', ' r8-s-001 ', ''],
            'intent'          => 'save',
        ]))->assertSessionHasNoErrors();

        $request = ApprovalRequest::sole();
        $this->assertSame(28500000, $request->amount);
        $this->assertSame(['R7-J-015', 'R8-S-001'], $request->related_numbers);
    }

    public function test_the_shape_of_inputs_is_checked_even_for_a_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $stopped   = $this->approvalType($w['reviewDept'], ['is_active' => false]);
        $otherDept = $this->approvalDepartment($w['company']);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), [
            'type_id'         => (string) $stopped->id,
            'department_id'   => (string) $otherDept->id,
            'subject'         => str_repeat('あ', 101),
            'amount'          => 'たくさん',
            'related_numbers' => ['R8-J-1'],
            'intent'          => 'save',
        ])->assertRedirect(route('approvals.requests.create'))->assertSessionHasErrors([
            'type_id'           => '選んだ申請の種類は使えません。選び直してください。',
            'department_id'     => '申請部門は、自分の所属部門から選んでください。',
            'subject'           => '件名は100文字以下で入力してください。',
            'amount'            => '金額は整数で入力してください。',
            'related_numbers.0' => '関連する決裁No「R8-J-1」の形が違います（例: R8-J-001）。',
        ]);

        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_more_than_ten_related_numbers_are_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $numbers = array_map(fn (int $i) => sprintf('R8-J-%03d', $i), range(1, 11));

        $this->actingAs($w['applicant'])
            ->post(route('approvals.requests.store'), $this->filled($w, ['related_numbers' => $numbers, 'intent' => 'save']))
            ->assertSessionHasErrors(['related_numbers' => '関連する決裁No は 10 個までです。']);
    }

    /** 保存と同時に提出する（計画 §0.8 の 2）。提出すると詳細の画面へ */
    public function test_a_complete_request_can_be_submitted_from_the_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $response = $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->filled($w, ['intent' => 'submit']));

        $request = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.requests.show', $request))->assertSessionHas('success', '提出しました。');
        $this->assertSame(ApprovalStatus::HeadReview, $request->status);
        $this->assertSame(1, $request->round);
    }

    /** 提出できないときも中身は下書きとして残る（計画 §0.11） */
    public function test_a_refused_submission_keeps_the_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $response = $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), [
            'department_id' => (string) $w['dept']->id,
            'amount'        => '1000',
            'body'          => BodyTemplate::DEFAULT,
            'intent'        => 'submit',
        ]);

        $request = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.requests.edit', $request))
            ->assertSessionHasErrors(['submit' => '申請の種類を選んでください。'])
            ->assertSessionHasErrors(['submit' => '件名を入力してください。'])
            ->assertSessionHasErrors(['submit' => '重点ポイント（5W2H）が見出しのままです。中身を書いてください。']);
        $this->assertSame(ApprovalStatus::Draft, $request->status);
        $this->assertSame(1000, $request->amount);
    }

    /** 理由は編集の画面の上にすべて出る（⚠ assertSessionHas* を呼ばずに描く。Bug #49） */
    public function test_the_refusal_reasons_are_shown_on_the_edit_page(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->draftFor($w, ['subject' => null, 'body' => BodyTemplate::DEFAULT]);

        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $request), $this->filled($w, [
            'subject' => '', 'body' => BodyTemplate::DEFAULT, 'intent' => 'submit',
        ]))->assertRedirect(route('approvals.requests.edit', $request));

        $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->assertSeeInOrder([
            '提出することができませんでした',
            '件名を入力してください。',
            '重点ポイント（5W2H）が見出しのままです。中身を書いてください。',
        ]);
    }

    /** 編集の画面は保存した中身をそのまま描く（描いたフォームを送り返すと同じ中身になる） */
    public function test_the_edit_form_round_trips_the_saved_content(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->draftFor($w, ['related_numbers' => ['R7-J-015']]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $form = $this->renderedForm($html, route('approvals.requests.update', $request));

        $this->assertSame('PUT', $form['method']);
        $this->assertSame((string) $w['type']->id, $form['fields']['type_id']);
        $this->assertSame((string) $w['dept']->id, $form['fields']['department_id']);
        $this->assertSame('2,850,000', $form['fields']['amount']);
        $this->assertSame((string) $request->lock_version, $form['fields']['lock_version']);
        // 関連する決裁No は JS（x-for）が描くので、初期値を見る
        $this->assertStringContainsString(Js::from(['R7-J-015'])->toHtml(), $html);

        $this->actingAs($w['applicant'])
            ->post($form['action'], $form['fields'] + ['related_numbers' => ['R7-J-015'], 'intent' => 'save'])
            ->assertRedirect(route('approvals.requests.edit', $request));

        $fresh = $request->fresh();
        $this->assertSame('社用車の購入', $fresh->subject);
        $this->assertSame(2850000, $fresh->amount);
        $this->assertSame('2026年10月', $fresh->schedule);
        $this->assertSame("■ なぜ（目的・理由）\n・老朽化のため", $fresh->body);
        $this->assertSame(['R7-J-015'], $fresh->related_numbers);
        $this->assertSame($request->lock_version + 1, $fresh->lock_version, '保存は lock_version を 1 進める');
    }

    /**
     * 古いタブの保存は、新しい保存を上書きしない（編集のフォームの lock_version。計画 §0.3・Task 7 の点検の申し送り）。
     * 断るときは入れた中身を残し、知らせたうえでの保存し直しは通す
     */
    public function test_a_stale_edit_form_does_not_overwrite_a_newer_save(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->draftFor($w);
        $tabA    = $this->editFormOf($w, $request);
        $tabB    = $this->editFormOf($w, $request);

        // タブ B で先に保存した（保存は lock_version を 1 進める）
        $this->actingAs($w['applicant'])->post($tabB['action'], array_merge($tabB['fields'], ['subject' => 'タブ B の件名', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.edit', $request));
        $this->assertSame($request->lock_version + 1, $request->fresh()->lock_version);

        // タブ A が入力の誤りで戻っても、A のフォームは A が読んだ版のまま（古い入力に新しい版を付けて上書きさせない）
        $this->actingAs($w['applicant'])->post($tabA['action'], array_merge($tabA['fields'], ['subject' => str_repeat('あ', 101), 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.edit', $request));
        $this->assertSame($tabA['fields']['lock_version'], $this->editFormOf($w, $request)['fields']['lock_version']);

        // タブ A の保存（提出も）は断り、入れた中身を残して編集の画面へ戻す
        $this->actingAs($w['applicant'])->post($tabA['action'], array_merge($tabA['fields'], ['subject' => 'タブ A の件名', 'intent' => 'submit']))
            ->assertRedirect(route('approvals.requests.edit', $request));
        $this->assertSame('タブ B の件名', $request->fresh()->subject);
        $this->assertSame(ApprovalStatus::Draft, $request->fresh()->status);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $this->assertStringContainsString('別の画面で先にこの申請が保存されていたため、保存しませんでした', $html);
        $again = $this->renderedForm($html, route('approvals.requests.update', $request));
        $this->assertSame('タブ A の件名', $again['fields']['subject'], '入れた中身が残っていない');
        $this->assertSame((string) $request->fresh()->lock_version, $again['fields']['lock_version'], '描き直したフォームが今の版でない');

        // 知らせたうえで保存し直すと、A の中身になる
        $this->actingAs($w['applicant'])->post($again['action'], $again['fields'] + ['intent' => 'save'])
            ->assertRedirect(route('approvals.requests.edit', $request));
        $this->assertSame('タブ A の件名', $request->fresh()->subject);
    }

    /** 差戻し中の申請は、種類が停止していても保存して出し直せる（D10）。差戻しの理由が上に出る */
    public function test_a_returned_request_keeps_its_stopped_type_and_can_be_resubmitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '金額の根拠を足してください');
        $w['type']->update(['is_active' => false]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $this->assertStringContainsString('（停止中）', $html);
        $this->assertStringContainsString('金額の根拠を足してください', $html);

        $form = $this->renderedForm($html, route('approvals.requests.update', $request));
        $this->assertSame((string) $w['type']->id, $form['fields']['type_id']);

        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'] + ['intent' => 'submit'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '出し直しました。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
        $this->assertSame(2, $request->fresh()->round);
    }

    /** 他人の下書きは、管理者・全件閲覧者・部門長でも見られない（404。設計書 §5.10） */
    public function test_someone_elses_draft_is_not_found_even_for_admins(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        foreach ([$this->approvalAdmin(), $this->viewAllUser(), $w['head']] as $user) {
            $this->actingAs($user)->get(route('approvals.requests.show', $draft))->assertNotFound();
            $this->actingAs($user)->get(route('approvals.requests.edit', $draft))->assertNotFound();
            $this->actingAs($user)->put(route('approvals.requests.update', $draft), ['subject' => '書き換え'])->assertNotFound();
            $this->actingAs($user)->delete(route('approvals.requests.destroy', $draft))->assertNotFound();
        }

        $this->assertSame('社用車の購入', $draft->fresh()->subject);
    }

    /** 回覧中の申請は申請者でも直せない（直せるのは下書きと差戻し中だけ。§5.11） */
    public function test_a_request_in_circulation_cannot_be_edited(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))
            ->assertRedirect(route('approvals.requests.show', $request));
        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $request), $this->filled($w, ['subject' => '書き換え', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', 'この申請は直せる状態ではありません。');

        $this->assertSame('社用車の購入', $request->fresh()->subject);
    }

    public function test_a_draft_that_was_never_submitted_can_be_deleted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->actingAs($w['applicant'])->delete(route('approvals.requests.destroy', $draft))
            ->assertRedirect(route('approvals.home'))
            ->assertSessionHas('success', '下書きを削除しました。');

        $this->assertNull($draft->fresh());
    }

    /** 一度でも提出した申請は、差し戻されても削除できない（記録を残す。要件 4.5） */
    public function test_a_request_that_was_submitted_cannot_be_deleted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');

        $this->actingAs($w['applicant'])->delete(route('approvals.requests.destroy', $request))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', '削除できるのは、一度も提出していない下書きだけです。');

        $this->assertNotNull($request->fresh());
    }

    /** コピーして作成: 中身を写す。停止した種類と、今は所属していない部門は空にする（設計書 §5.6） */
    public function test_copying_a_request_prefills_a_new_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $source = $this->draftFor($w, ['related_numbers' => ['R7-J-015']]);
        $w['type']->update(['is_active' => false]);
        $other = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部']);
        $w['applicant']->approvalDepartments()->sync([$other->id]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->getContent();
        $form = $this->renderedForm($html, route('approvals.requests.store'));

        $this->assertSame('社用車の購入', $form['fields']['subject']);
        $this->assertSame('2,850,000', $form['fields']['amount']);
        $this->assertSame('', $form['fields']['type_id']);
        // 写した部門は使えないので空に戻し、所属が 1 つだけならそれを選んでおく
        $this->assertSame((string) $other->id, $form['fields']['department_id']);
        $this->assertStringContainsString(Js::from(['R7-J-015'])->toHtml(), $html);
        $this->assertSame(1, ApprovalRequest::count(), '保存するまで下書きはできない');
    }

    /** 写せるのは自分の申請だけ（部門長は見られるが写せない） */
    public function test_only_your_own_request_can_be_copied(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['head'])->get(route('approvals.requests.create', ['copy' => $request->id]))->assertNotFound();
    }

    /** 詳細: 中身と、見られる関連の申請へのリンク（紙の時代の番号は文字だけ） */
    public function test_the_detail_shows_the_content_and_links_related_requests(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $old = $this->draftFor($w, ['subject' => '前の決裁']);
        DB::table('approval_requests')->where('id', $old->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-001', 'round' => 1]);
        // 申請者が見られない、他部門の決裁（番号はあるがリンクにしない）
        $stranger  = $this->approvalOnlyUser(['name' => '賃貸 次郎']);
        $otherDept = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'code' => 'K']);
        $stranger->approvalDepartments()->attach($otherDept->id);
        $hidden = $this->draftFor(['applicant' => $stranger, 'dept' => $otherDept, 'type' => $w['type']], ['subject' => '他部門の決裁']);
        DB::table('approval_requests')->where('id', $hidden->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-K-001', 'round' => 1]);
        $request = $this->submittedFor($w, ['related_numbers' => ['R8-J-001', 'R8-K-001', 'R6-J-099']]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();

        $this->assertStringContainsString('社用車の購入', $html);
        $this->assertStringContainsString('2,850,000円', $html);
        $this->assertStringContainsString('部門長確認中', $html);
        $this->assertStringContainsString('href="' . route('approvals.requests.show', $old) . '"', $html);
        $this->assertStringContainsString('<span class="font-mono mr-2">R6-J-099</span>', $html);
        // 見られない申請の番号は文字だけ（リンクから在ることを漏らさない。§5.10）
        $this->assertStringContainsString('<span class="font-mono mr-2">R8-K-001</span>', $html);
        $this->assertStringNotContainsString(route('approvals.requests.show', $hidden), $html);
    }

    public function test_the_detail_is_not_found_for_someone_who_cannot_see_it(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk();
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.requests.show', $request))->assertNotFound();
    }

    /**
     * 差戻し中の直しかけは、出し直すまで申請者だけが見る（利用者の決定 2026-09-27。要件 4.8）。
     * 申請者以外（部門長・管理者）には最後に提出した控えの中身を出し、「申請者が直しています」と添える。
     * 関連する決裁No のリンクも控えの番号から作る
     */
    public function test_others_see_the_last_submission_while_the_request_is_returned(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $old = $this->draftFor($w, ['subject' => '前の決裁']);
        DB::table('approval_requests')->where('id', $old->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-001', 'round' => 1]);
        $newType = $this->approvalType($w['reviewDept'], ['name' => '修繕・工事']);
        $newDept = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'code' => 'K']);
        $w['applicant']->approvalDepartments()->attach($newDept->id);
        $request = $this->submittedFor($w, ['related_numbers' => ['R8-J-001', 'R7-J-015']]);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '金額の根拠を足してください');

        // 申請者が直して保存した（まだ出し直していない）
        $form = $this->editFormOf($w, $request);
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], [
            'type_id'         => (string) $newType->id,
            'department_id'   => (string) $newDept->id,
            'subject'         => '直しかけの件名',
            'amount'          => '5,700,000',
            'schedule'        => '2027年1月',
            'body'            => "■ なぜ（目的・理由）\n・直しかけの本文",
            'related_numbers' => ['R8-J-009'],
            'intent'          => 'save',
        ]))->assertRedirect(route('approvals.requests.edit', $request));
        $this->assertSame('直しかけの件名', $request->fresh()->subject, '前提: 直しかけを保存できていない');

        $admin = $this->approvalAdmin();
        foreach (['部門長' => $w['head'], '管理者' => $admin] as $who => $other) {
            $html = $this->actingAs($other)->get(route('approvals.requests.show', $request))->assertOk()->getContent();

            $this->assertStringContainsString('申請者が直しています', $html, $who);
            foreach (['社用車の購入', '2,850,000円', '2026年10月', '・老朽化のため', 'R8-J-001', 'R7-J-015', $w['type']->name, '住宅事業部'] as $submitted) {
                $this->assertStringContainsString($submitted, $html, "{$who}に最後に提出した中身が出ていない: {$submitted}");
            }
            foreach (['直しかけ', '5,700,000円', '2027年1月', 'R8-J-009', '修繕・工事', '賃貸事業部'] as $editing) {
                $this->assertStringNotContainsString($editing, $html, "{$who}に直しかけが見えた: {$editing}");
            }
        }
        // 見られる申請へのリンクも控えの番号から作る（管理者は決裁済みの R8-J-001 を見られる）
        $this->assertStringContainsString(
            'href="' . route('approvals.requests.show', $old) . '"',
            $this->actingAs($admin)->get(route('approvals.requests.show', $request))->getContent()
        );

        // 申請者本人は今の中身（直しかけ）を見る
        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        foreach (['直しかけの件名', '5,700,000円', '2027年1月', '・直しかけの本文', 'R8-J-009', '修繕・工事', '賃貸事業部'] as $editing) {
            $this->assertStringContainsString($editing, $html, "申請者に今の中身が出ていない: {$editing}");
        }
        $this->assertStringNotContainsString('申請者が直しています', $html);
    }

    /** 何回か出し直した申請では、最後に提出した回の控えを出す（前の回の中身でも、直しかけでもない） */
    public function test_others_see_the_latest_round_that_was_submitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $workflow = app(Workflow::class);
        $request  = $this->submittedFor($w, ['subject' => '1 回目の件名']);
        $workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');
        $form = $this->editFormOf($w, $request);
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['subject' => '2 回目の件名', 'intent' => 'submit']))
            ->assertRedirect(route('approvals.requests.show', $request));
        $request->refresh();
        $workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, 'もう一度直してください');
        $form = $this->editFormOf($w, $request);
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['subject' => '直しかけの件名', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.edit', $request));

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('2 回目の件名')
            ->assertSee('最後に提出した中身（2 回目の提出）')
            ->assertDontSee('1 回目の件名')
            ->assertDontSee('直しかけの件名');
    }

    /** 差戻し中に直して保存してから取り下げても、提出していない中身は申請者だけに残る（要件 4.5・4.8） */
    public function test_others_keep_seeing_the_last_submission_after_a_withdrawal(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');
        $form = $this->editFormOf($w, $request);
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['subject' => '直しかけの件名', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.edit', $request));
        $request->refresh();
        app(Workflow::class)->withdraw($request, $w['applicant'], $request->lock_version, '別の案で出し直します');

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('社用車の購入')
            ->assertDontSee('直しかけの件名')
            ->assertDontSee('申請者が直しています');
        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('直しかけの件名');
    }

    /** 申請者以外には、回覧中も最後に提出した控えを出す（種類と部門の名前は提出したときのもの。申請者は今の名前） */
    public function test_others_see_the_names_as_submitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request       = $this->submittedFor($w);
        $submittedType = $w['type']->name;
        $w['type']->update(['name' => '名前を変えた種類']);
        $w['dept']->update(['name' => '名前を変えた部門']);

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee($submittedType)
            ->assertSee('住宅事業部')
            ->assertDontSee('名前を変えた種類')
            ->assertDontSee('申請者が直しています');
        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('名前を変えた種類')
            ->assertSee('名前を変えた部門');
    }

    /** 社長は申請できない（D4）。作成の画面で先に知らせる */
    public function test_the_president_is_told_they_cannot_apply(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $this->actingAs($w['president'])->get(route('approvals.requests.create'))->assertOk()
            ->assertSee('社長に指定されている人は申請できません');
    }
}
