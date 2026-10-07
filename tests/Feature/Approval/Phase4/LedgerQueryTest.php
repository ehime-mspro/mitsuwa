<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳の問い合わせ（要件 7 章・10 章・段階4 設計書 §5.8・D19〜D22）。
 *
 * ⚠ 見られる範囲の規則そのものは RequestVisibilityTest が見る。ここは台帳がその規則を通すこと・下書きを出さないこと・
 *   各絞り込み・中身の出し分け（D19）・年度（D20）・並び（D22）を見る。
 */
class LedgerQueryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));   // 日本時間 10/5 10:00
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, string> $query */
    private function ids(User $viewer, array $query = []): array
    {
        return Ledger::sortedIds($viewer, LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query)));
    }

    /** 提出した申請の状態の列を書き換える（状態の列は $fillable に無い。BuildsApprovalFixtures の注意） */
    private function made(array $w, array $columns = [], array $attributes = []): int
    {
        $r = $this->submittedFor($w, $attributes);
        if ($columns !== []) {
            DB::table('approval_requests')->where('id', $r->id)->update($columns);
        }

        return $r->id;
    }

    /** 部門長・審査・社長と回して決裁する */
    private function decided(array $w, ApprovalStepResult $result = ApprovalStepResult::Approve, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, $result, $result === ApprovalStepResult::Approve ? null : '条件・理由');

        return $r->fresh();
    }

    /** 部門長が差し戻した申請 */
    private function returned(array $w, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');

        return $r->fresh();
    }

    public function test_drafts_are_never_listed_even_for_their_applicant(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        $sent  = $this->made($w);

        $this->assertSame([$sent], $this->ids($w['applicant'], ['status' => 'all']));
        $this->assertNotContains($draft->id, $this->ids($this->viewAllUser(), ['status' => 'all']));
    }

    public function test_it_lists_only_what_the_viewer_may_see(): void
    {
        $w        = $this->approvalWorld();
        $mine     = $this->made($w);
        $other    = $this->approvalOnlyUser(['name' => '別部門 太郎']);
        $outside  = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B', 'head_user_id' => $this->baseUser()->id]);
        $other->approvalDepartments()->attach($outside->id);
        $elsewhere = $this->submittedFor(array_merge($w, ['applicant' => $other->fresh(), 'dept' => $outside]))->id;

        $this->assertSame([$elsewhere, $mine], $this->ids($this->viewAllUser(), ['status' => 'all']));
        $this->assertSame([$mine], $this->ids($w['applicant'], ['status' => 'all']), '申請者は自分の申請だけ');
        $this->assertSame([$mine], $this->ids($w['head'], ['status' => 'all']), '部門長は自分の部門の申請だけ');
        $this->assertSame([], $this->ids($this->baseUser(), ['status' => 'all']), '関わっていない人には何も出ない');
    }

    public function test_the_default_is_the_numbered_ones(): void
    {
        $w        = $this->approvalWorld();
        $progress = $this->made($w);
        $approved = $this->decided($w)->id;
        $rejected = $this->decided($w, ApprovalStepResult::Reject)->id;
        $waiting  = $this->decided($w, ApprovalStepResult::Conditional)->id;
        $missing  = $this->made($w, ['status' => ApprovalStatus::Withdrawn->value, 'number' => 'R8-J-099', 'number_department_id' => $w['dept']->id, 'number_fiscal_year' => 2026, 'number_seq' => 99]);
        $dropped  = $this->made($w, ['status' => ApprovalStatus::Withdrawn->value]);
        $viewer   = $this->viewAllUser();

        $default = $this->ids($viewer);
        sort($default);
        $this->assertSame([$approved, $rejected, $waiting, $missing], $default, '決裁済み・否決・条件確認待ち・番号のあと取り下げた欠番');
        $this->assertSame($default, $this->sorted($this->ids($viewer, ['status' => 'numbered'])));
        $this->assertSame([$progress], $this->ids($viewer, ['status' => 'progress']));
        $this->assertSame([$missing, $dropped], $this->sorted($this->ids($viewer, ['status' => 'withdrawn'])));
        $this->assertCount(6, $this->ids($viewer, ['status' => 'all']));
    }

    public function test_in_progress_includes_every_step_and_a_returned_request(): void
    {
        $w        = $this->approvalWorld();
        $head     = $this->made($w);
        $review   = $this->made($w, ['status' => ApprovalStatus::Review->value]);
        $president = $this->made($w, ['status' => ApprovalStatus::President->value, 'number' => 'R8-J-050', 'number_fiscal_year' => 2026]);
        $returned = $this->returned($w)->id;

        $this->assertSame([$head, $review, $president, $returned], $this->sorted($this->ids($this->viewAllUser(), ['status' => 'progress'])), '取り消しで番号が残ったまま戻った申請も進行中');
    }

    public function test_the_decision_filter(): void
    {
        $w           = $this->approvalWorld();
        $approved    = $this->decided($w)->id;
        $conditional = $this->decided($w, ApprovalStepResult::Conditional)->id;
        $rejected    = $this->decided($w, ApprovalStepResult::Reject)->id;
        $viewer      = $this->viewAllUser();

        $this->assertSame([$approved], $this->ids($viewer, ['decision' => 'approve']));
        $this->assertSame([$conditional], $this->ids($viewer, ['decision' => 'conditional']));
        $this->assertSame([$rejected], $this->ids($viewer, ['decision' => 'reject']));
    }

    public function test_the_decided_dates_are_days_of_the_japanese_calendar(): void
    {
        $w      = $this->approvalWorld();
        $before = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-001', 'decided_at' => '2026-10-04 14:59:59']);   // 日本時間 10/4 23:59
        $first  = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-002', 'decided_at' => '2026-10-04 15:00:00']);   // 日本時間 10/5 0:00
        $last   = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-003', 'decided_at' => '2026-10-05 14:59:59']);   // 日本時間 10/5 23:59
        $after  = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-004', 'decided_at' => '2026-10-05 15:00:00']);   // 日本時間 10/6 0:00
        $viewer = $this->viewAllUser();

        $this->assertSame([$first, $last], $this->sorted($this->ids($viewer, ['from' => '2026-10-05', 'to' => '2026-10-05'])));
        $this->assertSame([$first, $last, $after], $this->sorted($this->ids($viewer, ['from' => '2026-10-05'])));
        $this->assertSame([$before, $first, $last], $this->sorted($this->ids($viewer, ['to' => '2026-10-05'])));
    }

    public function test_the_applicant_is_found_by_part_of_the_name_and_by_every_word(): void
    {
        $w        = $this->approvalWorld();
        $hanako   = $this->decided($w)->id;   // 申請 花子
        $taro     = $this->approvalOnlyUser(['name' => '山田 太郎']);
        $taro->approvalDepartments()->attach($w['dept']->id);
        $yamada   = $this->decided(array_merge($w, ['applicant' => $taro->fresh()]))->id;
        $jiro     = $this->approvalOnlyUser(['name' => '佐藤　次郎']);
        $jiro->approvalDepartments()->attach($w['dept']->id);
        $sato     = $this->decided(array_merge($w, ['applicant' => $jiro->fresh()]))->id;
        $viewer   = $this->viewAllUser();

        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '山田']));
        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '山田太郎']), '登録名の半角の空白を無視して当てる');
        $this->assertSame([$sato], $this->ids($viewer, ['applicant' => '佐藤次郎']), '登録名の全角の空白を無視して当てる');
        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '田　郎']), '全角の空白で分けた語のどれも含む');
        $this->assertSame([], $this->ids($viewer, ['applicant' => '山田 花子']), 'どれも含まないと当たらない');
        $this->assertSame([$hanako], $this->ids($viewer, ['applicant' => '花子']));

        $taro->delete();
        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '山田']), '退職して消した人の申請も探せる');
    }

    public function test_the_keyword_is_found_in_the_subject_or_the_body_with_every_word(): void
    {
        $w      = $this->approvalWorld();
        $car    = $this->decided($w, attributes: ['subject' => '社用車の購入', 'body' => '老朽化のため'])->id;
        $desk   = $this->decided($w, attributes: ['subject' => '机の購入', 'body' => "社用車の駐車場の横に置く\n"])->id;
        $viewer = $this->viewAllUser();

        $this->assertSame([$car, $desk], $this->sorted($this->ids($viewer, ['q' => '社用車'])), '件名か本文');
        $this->assertSame([$desk], $this->ids($viewer, ['q' => '駐車場 机']), 'どの語も含む（件名と本文にまたがってよい）');
        $this->assertSame([$car], $this->ids($viewer, ['q' => '老朽化']));
    }

    public function test_the_department_and_the_type_filters(): void
    {
        $w      = $this->approvalWorld();
        $mine   = $this->decided($w)->id;
        $other  = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($other->id);
        $type   = $this->approvalType($w['reviewDept'], ['name' => '工事の発注']);
        $theirs = $this->decided(array_merge($w, ['dept' => $other, 'type' => $type]))->id;
        $viewer = $this->viewAllUser();

        $this->assertSame([$mine], $this->ids($viewer, ['department' => (string) $w['dept']->id]));
        $this->assertSame([$theirs], $this->ids($viewer, ['department' => (string) $other->id]));
        $this->assertSame([$theirs], $this->ids($viewer, ['type' => (string) $type->id]));
        $this->assertSame([$mine], $this->ids($viewer, ['type' => (string) $w['type']->id]));
    }

    public function test_others_search_what_was_last_submitted_while_it_is_returned(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B', 'head_user_id' => $this->baseUser()->id]);
        $type  = $this->approvalType($w['reviewDept'], ['name' => '工事の発注']);
        $r     = $this->returned($w, ['subject' => '出した件名', 'body' => '出した本文']);
        // 差戻しのあと、申請者が直しかけている（出し直していない）
        $r->update(['subject' => '直しかけの件名', 'body' => '直しかけの本文', 'department_id' => $other->id, 'type_id' => $type->id]);

        $others = $this->viewAllUser();
        foreach ([$others, $w['head']] as $viewer) {
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'q' => '出した件名']), '最後に提出した件名で当たる');
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'q' => '出した本文']));
            $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'q' => '直しかけ']), '直しかけの中身を検索に掛けない');
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'department' => (string) $w['dept']->id]));
            $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'department' => (string) $other->id]));
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'type' => (string) $w['type']->id]));
            $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'type' => (string) $type->id]));
        }

        // 申請者本人は今の中身で探す
        $me = $w['applicant'];
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'q' => '直しかけの件名']));
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'q' => '直しかけの本文']));
        $this->assertSame([], $this->ids($me, ['status' => 'progress', 'q' => '出した件名']));
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'department' => (string) $other->id]));
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'type' => (string) $type->id]));
    }

    public function test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted(): void
    {
        $w = $this->approvalWorld();
        $r = $this->returned($w, ['subject' => '出した件名']);
        $r->update(['subject' => '直しかけの件名']);
        $this->workflow->withdraw($r->fresh(), $w['applicant'], $r->fresh()->lock_version, null);

        $viewer = $this->viewAllUser();
        $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'withdrawn', 'q' => '出した件名']));
        $this->assertSame([], $this->ids($viewer, ['status' => 'withdrawn', 'q' => '直しかけ']));
        $this->assertSame([$r->id], $this->ids($w['applicant'], ['status' => 'withdrawn', 'q' => '直しかけ']));
    }

    /** 2 回以上提出した申請は、最後に提出した回の控えで当てる（1 回目の控えや直しかけで当てない） */
    public function test_others_search_the_latest_of_several_submissions(): void
    {
        $w = $this->approvalWorld();
        $r = $this->returned($w, ['subject' => '件名いち']);
        $r->fresh()->update(['subject' => '件名に']);
        $this->workflow->submit($r->fresh(), $w['applicant'], $r->fresh()->lock_version);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, 'もう一度');
        $r->fresh()->update(['subject' => '件名さん']);

        $viewer = $this->viewAllUser();
        $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'q' => '件名に']));
        $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'q' => '件名いち']), '前の回の控えで当てない');
        $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'q' => '件名さん']), '直しかけで当てない');
    }

    /** 番号の無い申請の年度も、ほかの人には最後に提出した申請部門の会社の期で数える（直しかけの部門の期で数えない。D19・D20） */
    public function test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department(): void
    {
        $w       = $this->approvalWorld();   // 住宅事業部は 5 月始まりの会社
        $dad     = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $dadDept = $this->approvalDepartment($dad, ['name' => '土木', 'code' => 'D', 'head_user_id' => $w['head']->id]);
        $r       = $this->returned($w);
        DB::table('approval_requests')->where('id', $r->id)->update(['last_submitted_at' => '2026-05-15 01:00:00']);   // 日本時間 5/15
        // 差戻し中に、6 月始まりの会社の部門へ直しかけている（5/15 は DAD では 2025 年度）
        $r->fresh()->update(['department_id' => $dadDept->id]);

        $viewer = $this->viewAllUser();
        $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'year' => '2026']));
        $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'year' => '2025']));
        $this->assertSame([$r->id], $this->ids($w['applicant'], ['status' => 'progress', 'year' => '2025']), '申請者本人は今の中身（直しかけの部門）で数える');
    }

    public function test_the_year_of_a_numbered_request_is_the_year_of_its_number(): void
    {
        $w = $this->approvalWorld();
        // 4/30 に付いた R7 の番号を取り消し、5/2 に判断し直しても R7 のまま（要件 6.5）
        $kept = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R7-J-010', 'number_fiscal_year' => 2025, 'decided_at' => '2026-05-02 01:00:00', 'last_submitted_at' => '2026-05-01 01:00:00']);
        $viewer = $this->viewAllUser();

        $this->assertSame([$kept], $this->ids($viewer, ['year' => '2025']));
        $this->assertSame([], $this->ids($viewer, ['year' => '2026']));
    }

    public function test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department(): void
    {
        $w   = $this->approvalWorld();   // 会社は 5 月始まり
        $dad = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $dadDept = $this->approvalDepartment($dad, ['name' => '土木', 'code' => 'D', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($dadDept->id);

        $mayFirst = $this->made($w, ['last_submitted_at' => '2026-04-30 15:00:00']);   // 日本時間 5/1 0:00 → ミツワの 2026 年度
        $aprilEnd = $this->made($w, ['last_submitted_at' => '2026-04-30 14:59:59']);   // 日本時間 4/30 23:59 → 2025 年度
        $inMay    = $this->made(array_merge($w, ['dept' => $dadDept]), ['last_submitted_at' => '2026-05-15 01:00:00']);   // DAD は 6 月始まり → 2025 年度
        $viewer   = $this->viewAllUser();

        $this->assertSame([$mayFirst], $this->ids($viewer, ['status' => 'all', 'year' => '2026']));
        $this->assertSame([$aprilEnd, $inMay], $this->sorted($this->ids($viewer, ['status' => 'all', 'year' => '2025'])));
    }

    public function test_the_order_is_by_day_then_number_then_submission(): void
    {
        $w = $this->approvalWorld();
        $s = $w['reviewDept'];   // アルファベット S
        $j = $w['dept'];         // アルファベット J
        $number = fn (string $code, int $seq, int $deptId, string $decidedAt) => [
            'status' => ApprovalStatus::Approved->value, 'number' => "R8-{$code}-" . sprintf('%03d', $seq), 'number_department_id' => $deptId,
            'number_fiscal_year' => 2026, 'number_seq' => $seq, 'decided_at' => $decidedAt,
        ];

        // 日本時間の 10/5（UTC の 10/4 15:00〜10/5 14:59）に決裁したもの。時刻の順と番号の順をずらしてある
        $j10 = $this->made($w, $number('J', 10, $j->id, '2026-10-04 15:30:00'));
        $j2  = $this->made($w, $number('J', 2, $j->id, '2026-10-05 05:00:00'));
        $s1  = $this->made($w, $number('S', 1, $s->id, '2026-10-04 16:00:00'));
        // 同じ日に提出して番号の無いもの（発信の新しい順で、番号のあるものの後ろ）
        $older = $this->made($w, ['status' => ApprovalStatus::President->value, 'last_submitted_at' => '2026-10-05 00:10:00']);
        $newer = $this->made($w, ['status' => ApprovalStatus::President->value, 'last_submitted_at' => '2026-10-05 02:00:00']);
        // 前の日（日本時間 10/4 23:59）と次の日
        $yesterday = $this->made($w, $number('J', 1, $j->id, '2026-10-04 14:59:00'));
        $tomorrow  = $this->made($w, ['status' => ApprovalStatus::Review->value, 'last_submitted_at' => '2026-10-05 15:00:00']);

        $this->assertSame(
            [$tomorrow, $j2, $j10, $s1, $newer, $older, $yesterday],
            $this->ids($this->viewAllUser(), ['status' => 'all'])
        );
    }

    public function test_the_years_run_from_this_year_back_to_the_oldest_request(): void
    {
        $w = $this->approvalWorld();
        $this->assertSame([2026 => 'R8 年度（2026）'], Ledger::years(), '申請が無ければ今の年度だけ');

        $this->made($w, ['number' => 'R6-J-001', 'number_fiscal_year' => 2024]);
        $this->assertSame([2026, 2025, 2024], array_keys(Ledger::years()));

        $this->made($w, ['last_submitted_at' => '2023-05-31 01:00:00']);   // 6 月始まりの会社なら 2022 年度
        $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $this->assertSame([2026, 2025, 2024, 2023, 2022], array_keys(Ledger::years()));
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
