<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * ベル・お知らせ一覧（⑥）・ホームの「新しいお知らせ」・既読の決まり（段階3 設計書 §5.6・D13〜D15）。
 */
class NoticeScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    /** @var array<string, mixed> */
    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:05', 'Asia/Tokyo')->utc());
        Mail::fake();
        $this->w = $this->approvalWorld();
    }

    /** お知らせを直接作る（中身は Notifier が作るものと同じ形） */
    private function notice(User $user, ApprovalRequest $request, string $headline = '部門長の確認のお願い', array $data = []): ApprovalNotice
    {
        return ApprovalNotice::create([
            'id' => (string) Str::orderedUuid(), 'type' => ApprovalNotice::TYPE,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'approval_request_id' => $request->id,
            'data' => array_merge([
                'scene' => 'turn', 'headline' => $headline, 'subject' => $request->subject, 'number' => null,
                'applicant' => '申請 花子', 'department' => '住宅事業部', 'action' => '部門長の確認（承認か差戻し）', 'actor' => '申請 花子',
            ], $data),
        ]);
    }

    private function bell(string $html): ?string
    {
        return preg_match('#<a href="' . preg_quote(route('approvals.notices.index'), '#') . '"[^>]*aria-label="[^"]*"[^>]*>.*?</a>#s', $html, $m) ? $m[0] : null;
    }

    /** 使い始める前はベルを出さない（基幹の画面にも決裁のみ利用者の画面にも。§5.2） */
    public function test_there_is_no_bell_before_launch(): void
    {
        $this->assertNull($this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->assertOk()->getContent()));
        $this->assertNull($this->bell($this->actingAs($this->w['applicant'])->get(route('approvals.home'))->assertOk()->getContent()));
    }

    /** ベル: 未読 0 は数を出さない・1 は「1」・100 は「99+」。読み上げは「お知らせ（未読 N 件）」。基幹の画面にも決裁のみ利用者にも */
    public function test_the_bell_shows_the_unread_count(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);   // 部門長に 1 つ
        ApprovalNotice::query()->delete();

        $bell = $this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 0 件）"', $bell);
        $this->assertStringNotContainsString('bg-red-600', $bell);

        $this->notice($this->w['head'], $request);
        $bell = $this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 1 件）"', $bell);
        $this->assertMatchesRegularExpression('#bg-red-600[^>]*>1</span>#', $bell);

        for ($i = 0; $i < 99; $i++) {
            $this->notice($this->w['head'], $request);
        }
        $this->notice($this->w['head'], $request)->markAsRead();
        $bell = $this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 100 件）"', $bell);
        $this->assertMatchesRegularExpression('#bg-red-600[^>]*>99\+</span>#', $bell);

        $this->notice($this->w['applicant'], $request, '差戻しされました');
        $bell = $this->bell($this->actingAs($this->w['applicant'])->get(route('approvals.home'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 1 件）"', $bell);
    }

    /** ⑥: 新しい順・未読は太字と「未読」の印・自分のお知らせだけ。日時は日本時間 */
    public function test_the_list_shows_my_notices_newest_first(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w, ['subject' => '社用車の購入']);
        $this->notice($this->w['head'], $request, '部門長の確認のお願い')->markAsRead();
        $this->travel(1)->minutes();
        $this->notice($this->w['head'], $request, '取り下げられました');
        $this->notice($this->w['reviewer'], $request, '審査の意見のお願い');

        $html = $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk()
            ->assertSeeInOrder(['取り下げられました：社用車の購入', '部門長の確認のお願い：社用車の購入'])
            ->assertDontSee('審査の意見のお願い')
            ->assertSee('9/30 10:06')
            ->getContent();

        $this->assertMatchesRegularExpression('#>未読</span>\s*<span class="[^"]*font-bold[^"]*">取り下げられました：社用車の購入</span>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#>未読</span>\s*<span class="[^"]*">部門長の確認のお願い#u', $html);
        $this->assertSame(1, substr_count($html, '>未読</span>'));
    }

    /**
     * ⑥: 20 件ずつ。範囲の外のページ（手で打った URL）は最後のページを出す
     * （前は行の無いページに「お知らせはありません。」を出し、整数の最大では PageNumbers::around() が TypeError の 500。Bug #100 の残り）
     */
    public function test_the_list_pages_by_twenty(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w);
        for ($i = 1; $i <= 21; $i++) {
            $this->notice($this->w['head'], $request, "見出し{$i}番");
            $this->travel(1)->seconds();
        }

        $first = $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk();
        $first->assertSee('見出し21番')->assertDontSee('見出し1番：')->assertSee('aria-label="ページ送り"', false);

        $this->actingAs($this->w['head'])->get(route('approvals.notices.index', ['page' => 2]))->assertOk()->assertSee('見出し1番：');
        foreach (['99', '9223372036854775807'] as $page) {
            $this->actingAs($this->w['head'])->get(route('approvals.notices.index', ['page' => $page]))->assertOk()
                ->assertSee('見出し1番：')->assertDontSee('見出し21番')->assertDontSee('お知らせはありません。')->assertSee('aria-label="ページ送り"', false);
        }
        $this->actingAs($this->w['head'])->get(route('approvals.notices.index', ['page' => 'abc']))->assertOk()->assertSee('見出し21番');
    }

    /** お知らせを押すと既読にして詳細へ。ほかの人のお知らせ・無い ID は 404 で、既読にもしない */
    public function test_opening_a_notice_marks_it_read_and_goes_to_the_request(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        $mine    = $this->noticesOf($this->w['head'])->sole();
        $theirs  = $this->notice($this->w['reviewer'], $request);

        $this->actingAs($this->w['head'])->get(route('approvals.notices.open', $mine->id))
            ->assertRedirect(route('approvals.requests.show', $request));
        $this->assertNotNull($mine->fresh()->read_at);

        $this->actingAs($this->w['head'])->get(route('approvals.notices.open', $theirs->id))->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
        $this->actingAs($this->w['head'])->get(route('approvals.notices.open', (string) Str::uuid()))->assertNotFound();
        $this->actingAs($this->w['head'])->get('/approvals/notices/not-a-uuid')->assertNotFound();
    }

    /** 見られなくなった申請のお知らせは、詳細の 404 にせず ⑥ に戻して知らせる（お知らせは既読にする。§5.6） */
    public function test_a_notice_of_a_request_that_can_no_longer_be_seen_goes_back_to_the_list(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        app(Workflow::class)->judgeHead($request, $this->w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $notice = $this->noticesOf($this->w['reviewer'])->sole();
        // 審査の意見を入れる前に審査部門から外れた（後任の審査担当者は残る）
        $this->w['reviewDept']->reviewers()->attach($this->baseUser(['name' => '後任 審査'])->id);
        $this->w['reviewDept']->reviewers()->detach($this->w['reviewer']->id);

        $this->actingAs($this->w['reviewer'])->get(route('approvals.notices.open', $notice->id))
            ->assertRedirect(route('approvals.notices.index'))
            ->assertSessionHas('error', 'この申請は、今は見られません。');
        $this->assertNotNull($notice->fresh()->read_at);
    }

    /** 詳細を開くと、開いた人のその申請の未読がすべて既読になる。ほかの申請の未読・ほかの人の未読は残す（D13） */
    public function test_opening_the_request_marks_only_my_notices_of_that_request_read(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        $other   = $this->submittedFor($this->w, ['subject' => '別の申請']);
        $this->notice($this->w['head'], $request, '取り下げられました');
        $this->notice($this->w['president'], $request, '社長の決裁のお願い');

        $this->actingAs($this->w['head'])->get(route('approvals.requests.show', $request))->assertOk();

        $this->assertSame(0, ApprovalNotice::ownedBy($this->w['head'])->where('approval_request_id', $request->id)->unread()->count());
        $this->assertSame(1, ApprovalNotice::ownedBy($this->w['head'])->where('approval_request_id', $other->id)->unread()->count());
        $this->assertSame(1, ApprovalNotice::ownedBy($this->w['president'])->unread()->count());
    }

    /** 「すべて既読にする」: 自分の未読だけ。未読が無ければボタンを出さない */
    public function test_marking_all_read_touches_only_my_notices(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        $this->notice($this->w['head'], $request, '取り下げられました');
        $this->notice($this->w['reviewer'], $request, '審査の意見のお願い');

        $html = $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->getContent();
        $form = $this->formAction($html, route('approvals.notices.readAll'));
        $this->assertNotNull($form, '「すべて既読にする」のフォームが無い');

        $this->actingAs($this->w['head'])->post(route('approvals.notices.readAll'))
            ->assertRedirect(route('approvals.notices.index'))
            ->assertSessionHas('success', 'お知らせをすべて既読にしました。');

        $this->assertSame(0, ApprovalNotice::ownedBy($this->w['head'])->unread()->count());
        $this->assertSame(1, ApprovalNotice::ownedBy($this->w['reviewer'])->unread()->count());
        $this->assertNull($this->formAction($this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->getContent(), route('approvals.notices.readAll')));
    }

    /** ホーム: 未読の新しい 5 件と ⑥ へのリンク。未読が無ければ「新しいお知らせはありません」 */
    public function test_the_home_shows_the_five_newest_unread_notices(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w);

        $this->actingAs($this->w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['新しいお知らせ', '新しいお知らせはありません。', 'お知らせをすべて見る']);

        for ($i = 1; $i <= 6; $i++) {
            $this->notice($this->w['head'], $request, "見出し{$i}番");
            $this->travel(1)->seconds();
        }
        $this->notice($this->w['head'], $request, '既読の見出し')->markAsRead();

        $this->actingAs($this->w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['新しいお知らせ', '見出し6番', '見出し5番', '見出し4番', '見出し3番', '見出し2番', 'お知らせをすべて見る'])
            ->assertDontSee('見出し1番')
            ->assertDontSee('既読の見出し')
            ->assertSee(route('approvals.notices.index'));
    }

    /** 件名などは画面ではエスケープする（お知らせの控えに < や & が入っていても HTML にならない） */
    public function test_the_notice_text_is_escaped(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w, ['subject' => '<b>A&B社</b>の契約']);
        $this->notice($this->w['head'], $request, '差戻しされました', ['actor' => '<i>社長</i>']);

        foreach ([route('approvals.notices.index'), route('approvals.home')] as $url) {
            $html = $this->actingAs($this->w['head'])->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('&lt;b&gt;A&amp;B社&lt;/b&gt;の契約', $html);
            $this->assertStringNotContainsString('<b>A&B社</b>', $html);
            $this->assertStringNotContainsString('<i>社長</i>', $html);
        }
    }

    /** ⑥ の問い合わせの数は、お知らせの件数で増えない */
    public function test_the_list_does_not_query_per_notice(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w);
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });
        $measure = function () use (&$queries): int {
            $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk();
            $before = $queries;
            $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk();

            return $queries - $before;
        };

        $this->notice($this->w['head'], $request);
        $few = $measure();
        for ($i = 0; $i < 10; $i++) {
            $this->notice($this->w['head'], $request);
        }

        $this->assertSame($few, $measure());
    }

    /** そのフォームの action（無ければ null） */
    private function formAction(string $html, string $action): ?string
    {
        return preg_match('#<form method="POST" action="' . preg_quote($action, '#') . '">#', $html) === 1 ? $action : null;
    }
}
