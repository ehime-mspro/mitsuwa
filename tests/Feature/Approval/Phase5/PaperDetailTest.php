<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 紙の決裁の詳細と、関連する決裁No の候補・リンク（段階5 設計書 §5.12・§5.15・D21）。
 * 見られるのは、その部門に所属する人・その部門の部門長・全件閲覧者・決裁の管理者だけ（申請の審査担当者や社長だけでは見られない）
 */
class PaperDetailTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private array $w;

    private ApprovalPaperRecord $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launchApprovals();
        $this->w     = $this->approvalWorld();   // 住宅事業部 J（部門長・所属の申請者）・総務部 S（審査担当者）・社長
        $this->paper = $this->paperRecord($this->w['dept'], ['seq' => 21, 'subject' => '見本倉庫購入', 'amount' => 50000000, 'cost' => 42000000, 'profit' => 8000000, 'note' => "原本は総務の棚\n2 段目"]);
    }

    private function show(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('approvals.papers.show', $this->paper));
    }

    public function test_the_people_who_may_see_it_can_open_it(): void
    {
        foreach (['所属する人' => $this->w['applicant'], '部門長' => $this->w['head'], '全件閲覧者' => $this->viewAllUser(), '決裁の管理者' => $this->approvalAdmin()] as $who => $user) {
            $this->show($user)->assertOk()->assertSee('見本倉庫購入');
        }
        foreach (['ほかの部門の審査担当者' => $this->w['reviewer'], '社長（全件閲覧者でない）' => $this->w['president'], '決裁のみ利用者' => $this->approvalOnlyUser()] as $who => $user) {
            $this->show($user)->assertNotFound();
        }
    }

    public function test_the_detail_shows_the_record_and_offers_the_edit_to_admins(): void
    {
        $html = $this->show($this->w['applicant'])->getContent();

        foreach (['R8-J-021', '紙の決裁です。原本は紙で保管しています。', '2026/05/13', '甲野', '50,000,000円', '42,000,000円', '8,000,000円', '16.0%', "原本は総務の棚\n2 段目"] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        $this->assertStringNotContainsString(route('approvals.admin.papers.edit', $this->paper), $html, '管理者でない人に「直す」を出さない');

        $this->assertStringContainsString('href="' . route('approvals.admin.papers.edit', $this->paper) . '"', $this->show($this->approvalAdmin())->getContent());

        // 金額の無い記録は「—」（0 円と分ける）
        $empty = $this->paperRecord($this->w['dept'], ['seq' => 22]);
        $html  = $this->actingAs($this->w['applicant'])->get(route('approvals.papers.show', $empty))->getContent();
        $this->assertSame(4, substr_count($html, '<dd class="text-gray-900 tabular-nums">—</dd>'), '金額・工事原価・粗利益・粗利率');
    }

    /** 使い始める前は誰にも見せない（D11） */
    public function test_it_is_closed_before_launch(): void
    {
        ApprovalSetting::current()->update(['launched_at' => null]);

        $this->assertSame(302, $this->show($this->approvalAdmin())->getStatusCode());
    }

    // --- 関連する決裁No ---

    /** 申請の詳細の関連する決裁No は、見られる紙の決裁ならその詳細へのリンクにする */
    public function test_a_related_paper_number_links_to_the_paper(): void
    {
        $this->paperRecord($this->w['reviewDept'], ['seq' => 5]);   // 総務部の紙（住宅事業部の部門長は見られない）
        $request = $this->submittedFor($this->w, ['related_numbers' => ['R8-J-021', 'R8-S-005', 'R7-J-001']]);

        $html = $this->actingAs($this->w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('approvals.papers.show', $this->paper) . '" class="font-mono text-emerald-600 hover:underline mr-2">R8-J-021</a>', $html);
        $this->assertStringContainsString('<span class="font-mono mr-2">R8-S-005</span>', $html, '見られない紙の決裁はリンクにしない');
        $this->assertStringContainsString('<span class="font-mono mr-2">R7-J-001</span>', $html, '取り込んでいない紙の番号は文字だけ');
    }

    /** 候補に見られる紙の決裁も出す（`paper: true`）。申請と合わせて決裁日の新しい順 */
    public function test_visible_papers_are_offered_as_candidates(): void
    {
        $request = $this->draftFor($this->w, ['subject' => '見本倉庫の改修']);
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-030', 'round' => 1, 'decided_at' => '2026-05-20 01:00:00']);
        $this->paperRecord($this->w['reviewDept'], ['seq' => 3, 'subject' => '見本倉庫の見積り']);   // 総務部の紙（申請者は見られない）

        $search = fn (User $user) => $this->actingAs($user)->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest']);

        $search($this->w['applicant'])->assertOk()->assertExactJson(['items' => [
            ['number' => 'R8-J-030', 'subject' => '見本倉庫の改修'],
            ['number' => 'R8-J-021', 'subject' => '見本倉庫購入', 'paper' => true],
        ]]);
        $this->assertSame(['R8-J-030', 'R8-S-003', 'R8-J-021'], array_column($search($this->approvalAdmin())->json('items'), 'number'), '決裁日の新しい順');

        // 番号でも当たる
        $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=r8-j-021', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertExactJson(['items' => [['number' => 'R8-J-021', 'subject' => '見本倉庫購入', 'paper' => true]]]);

        // 申請書の画面は候補に「紙」と添える
        $this->assertStringContainsString('<span x-show="item.paper"', $this->actingAs($this->w['applicant'])->get(route('approvals.requests.create'))->getContent());
    }

    /** 紙の決裁のほうが新しければ、申請より先に並べる（申請と紙を別々に読んでから、決裁日の新しい順に並べ直す） */
    public function test_a_newer_paper_comes_before_an_older_request(): void
    {
        $request = $this->draftFor($this->w, ['subject' => '見本倉庫の改修']);
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-030', 'round' => 1, 'decided_at' => '2026-05-01 01:00:00']);

        $items = $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest'])->json('items');

        $this->assertSame(['R8-J-021', 'R8-J-030'], array_column($items, 'number'), '紙 5/13 → 申請 5/1');
    }

    /** 同じ決裁日の申請と紙の決裁は、申請を先に並べる */
    public function test_a_request_comes_before_a_paper_of_the_same_day(): void
    {
        $request = $this->draftFor($this->w, ['subject' => '見本倉庫の改修']);
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-030', 'round' => 1, 'decided_at' => '2026-05-13 01:00:00']);

        $items = $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest'])->json('items');

        $this->assertSame(['R8-J-030', 'R8-J-021'], array_column($items, 'number'), '日本時間で同じ 5/13');
    }

    /** 候補の数は申請と紙を合わせて上限まで */
    public function test_candidates_are_capped_across_requests_and_papers(): void
    {
        for ($seq = 1; $seq <= 12; $seq++) {
            $this->paperRecord($this->w['dept'], ['seq' => 100 + $seq, 'subject' => "見本倉庫 {$seq}", 'decided_on' => sprintf('2026-06-%02d', $seq)]);
        }

        $items = $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest'])->json('items');

        $this->assertCount(10, $items);
        $this->assertSame('R8-J-112', $items[0]['number']);
        $this->assertSame(0, ApprovalRequest::count());
    }
}
