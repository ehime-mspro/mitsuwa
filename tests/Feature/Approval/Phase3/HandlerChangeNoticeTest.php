<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Enums\UserRole;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 担当が入れ替わったときの知らせ（段階3 設計書 D1・§5.4 の場面 2）。部門長の交代と審査担当者の追加は部門の管理、
 * 社長の交代は基幹の利用者の管理から（画面と同じ形で送る）。
 */
class HandlerChangeNoticeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    /** @var array<string, mixed> */
    private array $w;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 決裁No の年度が動かないように止める（日本時間 2026-09-26 → R8）
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'Asia/Tokyo')->utc());
        Mail::fake();
        $this->w     = $this->approvalWorld();
        $this->admin = $this->approvalAdmin();
    }

    private function judge(ApprovalRequest $request, User $actor, string $kind): ApprovalRequest
    {
        $request->refresh();
        $method = ['head' => 'judgeHead', 'review' => 'judgeReview'][$kind];
        app(Workflow::class)->{$method}($request, $actor, $request->lock_version, $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve, null);

        return $request->refresh();
    }

    /** 部門の管理の編集のモーダルが送るのと同じ形で更新する */
    private function updateDepartment(ApprovalDepartment $dept, array $overrides, ?User $admin = null): TestResponse
    {
        $dept = $dept->fresh(['reviewers']);

        return $this->actingAs($admin ?? $this->admin)->put(route('approvals.admin.organization.departments.update', $dept), array_merge([
            'company_id'        => (string) $dept->company_id,
            'name'              => $dept->name,
            'short_name'        => $dept->short_name,
            'code'              => $dept->code,
            'sort_order'        => (string) $dept->sort_order,
            'head_user_id'      => $dept->head_user_id === null ? '' : (string) $dept->head_user_id,
            'reviewer_ids'      => $dept->reviewers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'next_number'       => (string) ApprovalNumber::currentState($dept)['next'],
            'next_number_shown' => (string) ApprovalNumber::currentState($dept)['next'],
        ], $overrides))->assertRedirect(route('approvals.admin.organization.index'))->assertSessionHas('success');
    }

    /** 部門長の交代: 部門長の確認を待っている申請ごとに新しい部門長へ（付け替えていたものも移る。D23）。前の部門長・外れた付け替え先には出さない（D9） */
    public function test_a_new_head_gets_one_notice_per_waiting_request(): void
    {
        $this->launchApprovals();
        $plain      = $this->submittedFor($this->w);
        $reassigned = $this->submittedFor($this->w);
        $other      = $this->baseUser(['name' => '付け替え 先']);
        app(Workflow::class)->reassignHead($reassigned, $this->admin, $reassigned->lock_version, $other, '出張のため');
        $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');   // 審査中（部門長の確認は済んだ）
        ApprovalNotice::query()->delete();
        $newHead = $this->mailable($this->baseUser(['name' => '新 部門長']), 'newhead');

        $this->updateDepartment($this->w['dept'], ['head_user_id' => (string) $newHead->id]);

        $notices = $this->noticesOf($newHead);
        $this->assertEqualsCanonicalizing([$plain->id, $reassigned->id], $notices->pluck('approval_request_id')->all());
        $this->assertSame(['部門長の確認のお願い', '部門長の確認のお願い'], $notices->map(fn ($n) => $n->data['headline'])->all());
        $this->assertSame('決裁 管理者', $notices->first()->data['actor']);
        $this->assertSame([], $this->headlinesOf($this->w['head']));
        $this->assertSame([], $this->headlinesOf($other));
        $this->assertCount(2, $this->mailSubjectsTo('newhead@mitsuwat.co.jp'));
    }

    /** 自分を新しい部門長にした管理者には出さない（操作した本人。§5.4 の決まり 1） */
    public function test_the_admin_who_became_the_head_gets_nothing(): void
    {
        $this->launchApprovals();
        $this->submittedFor($this->w);
        ApprovalNotice::query()->delete();

        $this->updateDepartment($this->w['dept'], ['head_user_id' => (string) $this->admin->id]);

        $this->assertSame(0, ApprovalNotice::count());
    }

    /** 審査担当者の追加: 足した人にだけ、審査を待っている申請ごとに（まだ審査に届いていない申請は、届いたときに場面 1 で） */
    public function test_an_added_reviewer_gets_one_notice_per_waiting_review(): void
    {
        $this->launchApprovals();
        $first   = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $second  = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $pending = $this->submittedFor($this->w);   // 部門長確認中（審査はまだ届いていない）
        ApprovalNotice::query()->delete();
        $added = $this->baseUser(['name' => '追加 審査']);

        $this->updateDepartment($this->w['reviewDept'], ['reviewer_ids' => [(string) $this->w['reviewer']->id, (string) $added->id]]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->noticesOf($added)->pluck('approval_request_id')->all());
        $this->assertSame(['審査の意見のお願い', '審査の意見のお願い'], $this->headlinesOf($added));
        $this->assertSame([], $this->headlinesOf($this->w['reviewer']), '前からいる審査担当者には出さない');
        $this->assertNotContains($pending->id, ApprovalNotice::pluck('approval_request_id')->all());

        // 外しただけ（足した人がいない）なら誰にも出ない
        ApprovalNotice::query()->delete();
        $this->updateDepartment($this->w['reviewDept'], ['reviewer_ids' => [(string) $added->id]]);
        $this->assertSame(0, ApprovalNotice::count());
    }

    /**
     * MySQL のデッドロック（1213）を避ける順は SQLite でも見える: 審査担当者を足すときは、部門の行を更新する前に、
     * 審査を待っている申請の行をロック付きで読む（お知らせの行が申請の行に共有ロックを取るため。lockWaitingReviews()）
     */
    public function test_the_waiting_reviews_are_locked_before_the_department_row_is_updated(): void
    {
        $this->launchApprovals();
        $waiting = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $added   = $this->baseUser(['name' => '追加 審査']);
        $first   = [];
        DB::listen(function ($q) use (&$first): void {
            foreach ([
                'requests' => '/^select .* from [`"]approval_requests[`"] where [`"]approval_requests[`"]\.[`"]id[`"] in/',
                'dept'     => '/^update [`"]approval_departments[`"]/',
                'notice'   => '/^insert into [`"]notifications[`"]/',
            ] as $k => $re) {
                if (! isset($first[$k]) && preg_match($re, $q->sql)) {
                    $first[$k] = count($first);
                }
            }
        });

        // 略称も変える（部門の行の UPDATE が走る。審査担当者だけの変更では部門の行を更新しない）
        $this->updateDepartment($this->w['reviewDept'], ['short_name' => '総務2', 'reviewer_ids' => [(string) $this->w['reviewer']->id, (string) $added->id]]);

        $this->assertSame(['requests' => 0, 'dept' => 1, 'notice' => 2], $first, '部門の行を更新する前に申請の行をロックしていない（逆だと MySQL で 1213）');
        $this->assertSame([$waiting->id], $this->noticesOf($added)->pluck('approval_request_id')->all());
    }

    /** 使い始める前は、部門長や審査担当者を変えても何も出ない（§5.2） */
    public function test_nothing_is_made_before_launch(): void
    {
        $this->submittedFor($this->w);
        $newHead = $this->baseUser(['name' => '新 部門長']);

        $this->updateDepartment($this->w['dept'], ['head_user_id' => (string) $newHead->id]);
        $this->updateDepartment($this->w['reviewDept'], ['reviewer_ids' => [(string) $this->w['reviewer']->id, (string) $newHead->id]]);

        $this->assertSame(0, ApprovalNotice::count());
    }

    /** 社長の交代: 社長の決裁を待っている申請ごとに新しい社長へ。同じ人を選び直しても出ない。申請者本人の申請には出ない（D16） */
    public function test_a_new_president_gets_one_notice_per_waiting_request(): void
    {
        $this->launchApprovals();
        $first  = $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $second = $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $this->submittedFor($this->w);   // 部門長確認中（社長の決裁はまだ）
        // 新しい社長が出していた申請（社長の決裁待ち）
        $newPresident = $this->baseUser(['name' => '新 社長']);
        $newPresident->approvalDepartments()->attach($this->w['dept']->id);
        $own = $this->judge($this->judge($this->submittedFor(array_merge($this->w, ['applicant' => $newPresident])), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        ApprovalNotice::query()->delete();
        $executive = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);

        $this->actingAs($executive)->post(route('admin.users.president'), ['president_user_id' => (string) $newPresident->id])
            ->assertRedirect(route('admin.users.index'));

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->noticesOf($newPresident)->pluck('approval_request_id')->all());
        $this->assertSame(['社長の決裁のお願い', '社長の決裁のお願い'], $this->headlinesOf($newPresident));
        $this->assertNotContains($own->id, ApprovalNotice::pluck('approval_request_id')->all());
        $this->assertSame([], $this->headlinesOf($this->w['president']), '前の社長には出さない');

        ApprovalSetting::forget();
        $this->actingAs($executive)->post(route('admin.users.president'), ['president_user_id' => (string) $newPresident->id]);
        $this->assertCount(2, $this->noticesOf($newPresident), '同じ人を選び直しても出さない');
    }

    /** 社長の指定は、設定の更新・記録・知らせを 1 つのトランザクションで（知らせが作れなければ、指定も記録も残らない） */
    public function test_the_president_designation_rolls_back_when_the_notice_fails(): void
    {
        $this->launchApprovals();
        $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $newPresident = $this->baseUser(['name' => '新 社長']);
        $executive    = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        ApprovalNotice::creating(fn () => throw new RuntimeException('お知らせを作れない'));

        $this->actingAs($executive)->post(route('admin.users.president'), ['president_user_id' => (string) $newPresident->id])
            ->assertServerError();

        ApprovalSetting::forget();
        $this->assertSame($this->w['president']->id, ApprovalSetting::current()->president_user_id);
        $this->assertSame(0, ApprovalSettingLog::where('action', 'president.changed')->count());
    }
}
