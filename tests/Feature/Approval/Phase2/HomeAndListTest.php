<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Http\Middleware\RestrictApprovalOnlyUsers;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 使い始めたあとのホーム（画面①）と自分の申請一覧（画面④）（設計書 §5.12） */
class HomeAndListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 状態と番号を直接入れた、終わった申請（ここで見るのは画面の並びだけ） */
    private function finished(array $w, string $subject, string $status, ?string $number): ApprovalRequest
    {
        $request = $this->draftFor($w, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => $status, 'number' => $number, 'round' => 1, 'status_changed_at' => now(),
            'decision' => $status === 'approved' ? 'approve' : ($status === 'rejected' ? 'reject' : null),
        ]);

        return $request->refresh();
    }

    /** 部門長の承認と審査の意見まで進めた申請（社長決裁待ち） */
    private function atPresident(array $w, array $attributes = []): ApprovalRequest
    {
        $workflow = app(Workflow::class);
        $request  = $this->submittedFor($w, $attributes);
        $workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $request->refresh();
        $workflow->judgeReview($request, $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);

        return $request->refresh();
    }

    /** 画面の本文（サイドバーを除く。管理のリンクはサイドバーにもあるため） */
    private function mainOf(string $html): string
    {
        $this->assertSame(1, preg_match('/<main\b.*?<\/main>/s', $html, $m), '本文（main）が見つからない');

        return $m[0];
    }

    public function test_before_launch_the_home_is_still_the_placeholder(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSee('決裁の機能は準備中です')
            ->assertDontSee('対応待ち');
    }

    /** 部門長のホームに、自分の番の申請が待ち日数つきで出る（D20） */
    public function test_the_head_sees_what_waits_for_them(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00', 'Asia/Tokyo')->utc());
        $request = $this->submittedFor($w);
        $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00', 'Asia/Tokyo')->utc());

        $this->actingAs($w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['対応待ち', '1 件', '部門長・承認・差戻し', '社用車の購入', '申請 花子・住宅事業部', '2 日待ち'])
            ->assertSee('href="' . route('approvals.requests.show', $request) . '"', false);
    }

    /** 申請者のホームには、自分の申請の進み具合（いま誰の番か）と最近の完了が出る */
    public function test_the_applicant_sees_the_progress_of_their_requests(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        $this->finished($w, '終わった申請', 'approved', 'R8-J-001');

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSee('いま対応が必要な申請はありません。')
            ->assertSeeInOrder(['自分の申請の進み具合', '回覧中の申請', 'いま: 部門長（部門 長）', '最近の完了', 'R8-J-001', '終わった申請']);
    }

    /** 一覧は自分の申請だけ・新しい順（他人の申請は出さない） */
    public function test_the_list_shows_only_my_requests_newest_first(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '社用車の購入']);
        $this->submittedFor($w, ['subject' => '事務所の改装']);
        $other = $this->approvalOnlyUser(['name' => '別 申請者']);
        $other->approvalDepartments()->attach($w['dept']->id);
        $this->draftFor(['applicant' => $other] + $w, ['subject' => '他人の申請']);

        $response = $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->assertOk()
            ->assertSeeInOrder(['事務所の改装', '社用車の購入'])
            ->assertSee('部門長（部門 長）')
            ->assertDontSee('他人の申請');
        // ⚠ 画面ではカードと表に件名が 2 回ずつ出るので、assertSeeInOrder だけでは古い順でも通る。並びは渡したデータで見る
        $this->assertSame(['事務所の改装', '社用車の購入'], $response->viewData('requests')->pluck('subject')->all(), '新しい順になっていない');
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '下書きの申請']);
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        $returned = $this->submittedFor($w, ['subject' => '差戻しの申請']);
        app(Workflow::class)->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $this->finished($w, '取り下げた申請', 'withdrawn', null);
        $this->finished($w, '決裁済みの申請', 'approved', 'R8-J-001');

        $expected = [
            'draft'     => '下書きの申請',
            'progress'  => '回覧中の申請',
            'returned'  => '差戻しの申請',
            'done'      => '決裁済みの申請',
            'withdrawn' => '取り下げた申請',
        ];

        foreach ($expected as $filter => $subject) {
            $response = $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => $filter]))->assertOk();
            $this->assertSame([$subject], $response->viewData('requests')->pluck('subject')->all(), "絞り込み {$filter}");
        }

        // 知らない絞り込みは「すべて」
        $this->assertCount(5, $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => 'bogus']))->viewData('requests'));
    }

    public function test_the_list_pages_by_twenty(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 21; $i++) {
            $this->draftFor($w, ['subject' => "下書き{$i}"]);
        }

        $this->assertCount(20, $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->viewData('requests'));
        $this->assertSame(['下書き1'], $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['page' => 2]))->viewData('requests')->pluck('subject')->all());
    }

    /**
     * ホームの進み具合・最近の完了は自分の申請だけ。他人の申請も、差戻し中に直しかけた中身も出さない
     * （§5.12・利用者の決定 2026-09-27「差戻し中は、申請者以外には最後に提出した中身」）。
     * 部門長のホームの対応待ちには、判断できる回覧中の申請だけが出る。
     */
    public function test_the_home_shows_only_my_own_requests(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other = $this->approvalOnlyUser(['name' => '別 申請者']);
        $other->approvalDepartments()->attach($w['dept']->id);
        $theirs = ['applicant' => $other] + $w;

        $this->submittedFor($theirs, ['subject' => '他人の回覧中の申請']);
        $returned = $this->submittedFor($theirs, ['subject' => '他人の差戻しの申請']);
        app(Workflow::class)->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        // 差戻しのあとに直しかけた件名（出し直すまで申請者だけが見る）
        DB::table('approval_requests')->where('id', $returned->id)->update(['subject' => '他人の直しかけの件名']);
        $this->finished($theirs, '他人の終わった申請', 'approved', 'R8-J-001');
        $this->submittedFor($w, ['subject' => '自分の回覧中の申請']);

        $mine = $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['自分の申請の進み具合', '自分の回覧中の申請']);
        $head = $this->actingAs($w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['対応待ち', '2 件', '自分の申請の進み具合', 'まだ申請はありません。']);

        foreach (['他人の回覧中の申請', '他人の差戻しの申請', '他人の直しかけの件名', '他人の終わった申請'] as $subject) {
            $mine->assertDontSee($subject);
        }
        // 部門長のホームの「新しいお知らせ」（段階3）には、提出されたときの知らせ（最後に提出した件名）が残るので、
        // 対応待ちと進み具合だけを見る。直しかけの件名はお知らせにも出ない（控えから作る。D26）
        $headSections = strstr($head->getContent(), '新しいお知らせ', true);
        $this->assertNotFalse($headSections, 'ホームに「新しいお知らせ」の欄が無い');
        foreach (['他人の差戻しの申請', '他人の直しかけの件名', '他人の終わった申請'] as $subject) {
            $this->assertStringNotContainsString($subject, $headSections);
        }
        $head->assertDontSee('他人の直しかけの件名');
    }

    /**
     * 進み具合には回覧中・差戻し中・条件確認待ち（下書きは出さない）が、動いた日時の新しい順に「いま誰の番か」つきで出る。
     * 最近の完了は決裁済み・否決・取り下げの新しい 5 件（§5.12）。
     */
    public function test_the_progress_and_the_recently_finished(): void
    {
        $w        = $this->approvalWorld();
        $this->launchApprovals();
        $workflow = app(Workflow::class);
        $at       = fn (string $japanTime) => $this->travelTo(CarbonImmutable::parse($japanTime, 'Asia/Tokyo')->utc());

        $at('2026-09-01 10:00');
        $returned  = $this->submittedFor($w, ['subject' => '差戻しの申請']);
        $condition = $this->atPresident($w, ['subject' => '条件付きの申請']);
        $this->draftFor($w, ['subject' => '下書きの申請']);
        $at('2026-09-02 10:00');
        $workflow->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $at('2026-09-03 10:00');
        $workflow->judgePresident($condition, $w['president'], $condition->lock_version, ApprovalStepResult::Conditional, '2 社から見積りを取ること');
        $at('2026-09-04 10:00');
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        foreach ([['完了1', 'approved', 'R8-J-101'], ['完了2', 'rejected', 'R8-J-102'], ['完了3', 'withdrawn', null],
                  ['完了4', 'approved', 'R8-J-103'], ['完了5', 'withdrawn', null], ['完了6', 'approved', 'R8-J-104']] as $i => [$subject, $status, $number]) {
            $at('2026-09-1' . $i . ' 10:00');
            $this->finished($w, $subject, $status, $number);
        }

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder([
                '自分の申請の進み具合',
                '回覧中の申請', 'いま: 部門長（部門 長）',
                '条件付きの申請', 'いま: 申請者（条件の確認）',
                '差戻しの申請', 'いま: 申請者（差戻しの対応）',
                '最近の完了', '完了6', '完了5', '完了4', '完了3', '完了2',
            ])
            ->assertDontSee('完了1')
            ->assertDontSee('下書きの申請');
    }

    /**
     * 一覧とホームの「いま誰の番か」（CurrentHandler::label()）は状態ごとに決まった言葉（設計書 §5.12）。
     * 部門長・社長は名前、審査は審査部門の名前。終わった申請と、回覧中なのに待ちの段階が無い申請（2a の流れでは
     * 起きない。手で直したデータなど）は「—」で、500 にしない。
     */
    public function test_whose_turn_it_is_on_the_list_and_the_home(): void
    {
        $w        = $this->approvalWorld();
        $this->launchApprovals();
        $workflow = app(Workflow::class);

        $this->draftFor($w, ['subject' => '下書きの申請']);
        $this->submittedFor($w, ['subject' => '部門長の番の申請']);
        $review = $this->submittedFor($w, ['subject' => '審査の番の申請']);
        $workflow->judgeHead($review, $w['head'], $review->lock_version, ApprovalStepResult::Approve, null);
        $this->atPresident($w, ['subject' => '社長の番の申請']);
        $returned = $this->submittedFor($w, ['subject' => '差戻しの申請']);
        $workflow->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $condition = $this->atPresident($w, ['subject' => '条件確認の申請']);
        $workflow->judgePresident($condition, $w['president'], $condition->lock_version, ApprovalStepResult::Conditional, '2 社から見積りを取ること');
        $this->finished($w, '決裁済みの申請', 'approved', 'R8-J-101');
        $this->finished($w, '否決の申請', 'rejected', 'R8-J-102');
        $this->finished($w, '取り下げの申請', 'withdrawn', null);
        $broken = $this->submittedFor($w, ['subject' => '待ちの段階が無い申請']);
        ApprovalStep::where('request_id', $broken->id)->where('status', 'waiting')->update(['status' => 'done']);

        $inProgress = [
            '部門長の番の申請'     => '部門長（部門 長）',
            '審査の番の申請'       => '審査（総務部）',
            '社長の番の申請'       => '社長（社長 太郎）',
            '差戻しの申請'         => '申請者（差戻しの対応）',
            '条件確認の申請'       => '申請者（条件の確認）',
            '待ちの段階が無い申請' => '—',
        ];
        $all = $inProgress + ['下書きの申請' => '申請者（下書き）', '決裁済みの申請' => '—', '否決の申請' => '—', '取り下げの申請' => '—'];

        $list = $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->assertOk()->getContent();
        foreach ($all as $subject => $label) {
            $s = preg_quote($subject, '/');
            $l = preg_quote($label, '/');
            // スマホの幅のカード（件名の段落の後ろの「いま: …」）と、PC の幅の表（件名の行の最後の列）
            $this->assertMatchesRegularExpression("/{$s}<\/p>(?:(?!<\/li>).)*いま: {$l}<\/p>/su", $list, "一覧のカード: {$subject}");
            $this->assertMatchesRegularExpression("/{$s}<\/a>(?:(?!<\/tr>).)*<td[^>]*>{$l}<\/td>\s*<\/tr>/su", $list, "一覧の表: {$subject}");
        }

        $home = $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()->getContent();
        foreach ($inProgress as $subject => $label) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($subject, '/') . '<\/span>\s*<span[^>]*>いま: ' . preg_quote($label, '/') . '<\/span>/u',
                $home,
                "ホームの進み具合: {$subject}"
            );
        }
    }

    /** ページ送りの URL に絞り込みが残る。配列で送られた絞り込みは知らない値として「すべて」（500 にしない）。絞り込んで 0 件の言葉 */
    public function test_the_page_links_keep_the_filter(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 21; $i++) {
            $this->draftFor($w, ['subject' => "下書き{$i}"]);
        }
        $this->submittedFor($w, ['subject' => '回覧中の申請']);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => 'draft']))->assertOk()
            ->assertSee('href="' . e(route('approvals.requests.index', ['filter' => 'draft', 'page' => 2])) . '"', false);

        $response = $this->actingAs($w['applicant'])->get('/approvals/requests?filter[]=draft')->assertOk();
        $this->assertNull($response->viewData('filter'));
        $this->assertSame(22, $response->viewData('requests')->total());

        // 絞り込んで 0 件なら「該当する申請はありません。」（申請が 1 件も無いときの言葉と分ける）
        $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => 'withdrawn']))->assertOk()
            ->assertSee('該当する申請はありません。')
            ->assertDontSee('まだ申請はありません。');
    }

    /** 使い始めたあとのホームの管理のリンクは、決裁の管理者だけ（管理の画面の門番とは別に、押せないリンクを出さない） */
    public function test_the_admin_links_are_only_for_approval_admins(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $links = [route('approvals.admin.users.index'), route('approvals.admin.organization.index'), route('approvals.admin.types.index')];

        $admin = $this->mainOf($this->actingAs($this->approvalAdmin())->get(route('approvals.home'))->assertOk()->getContent());
        $plain = $this->mainOf($this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()->getContent());
        foreach ($links as $link) {
            $this->assertStringContainsString('href="' . $link . '"', $admin, "管理者のホームに {$link} が無い");
            $this->assertStringNotContainsString('href="' . $link . '"', $plain, "管理者でない人のホームに {$link} が出ている");
        }
    }

    /**
     * 使い始めたあとも、基幹の画面から跳ね返された決裁のみ利用者にはホームに理由が出る。ホームの入口から来たときは出さない
     * （Bug #63 の流れ。ApprovalOnlyLockoutTest は準備中のホームで固定している）。
     */
    public function test_after_launch_the_home_still_explains_a_bounce(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        // 「自分の申請の進み具合」は使い始めたあとのホームにしか無い見出し（着いた画面の確かめ）
        $this->actingAs($w['applicant'])->followingRedirects()->get('/dashboard/tenant')->assertOk()
            ->assertSee('自分の申請の進み具合')
            ->assertSee(RestrictApprovalOnlyUsers::MESSAGE);
        $this->actingAs($w['applicant'])->followingRedirects()->get('/')->assertOk()
            ->assertSee('自分の申請の進み具合')
            ->assertDontSee(RestrictApprovalOnlyUsers::MESSAGE);
    }

    /** 今日届いた申請は「今日」（日本時間の朝 9 時前に届いても。0 日待ちと出さない。D20） */
    public function test_a_request_that_arrived_today_says_today(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->travelTo(CarbonImmutable::parse('2026-09-26 08:30', 'Asia/Tokyo')->utc());   // UTC ではまだ 9/25
        $this->submittedFor($w);
        $this->travelTo(CarbonImmutable::parse('2026-09-26 18:00', 'Asia/Tokyo')->utc());

        $this->actingAs($w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['社用車の購入', '今日'])
            ->assertDontSee('日待ち');
    }

    /** スマホの幅では 1 件 1 枚のカード、PC の幅では表（要件 14.4。表は横スクロールの親の中＝MobileLayoutTest） */
    public function test_each_request_is_a_card_on_a_phone_and_a_row_on_a_pc(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->submittedFor($w, ['subject' => '事務所の改装']);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<ul class="md:hidden[^"]*">(?:(?!<\/ul>).)*事務所の改装/s', $html, 'スマホの幅のカードに無い');
        $this->assertMatchesRegularExpression('/<div class="hidden md:block">(?:(?!<\/table>).)*<table(?:(?!<\/table>).)*事務所の改装/s', $html, 'PC の幅の表に無い');
    }

    /** 退職などで削除した利用者（申請者・部門長・付け替えの担当・社長）が関わっていても、ホームと一覧は開ける（Top trap #18） */
    public function test_deleted_people_do_not_break_the_home_or_the_list(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $deputy   = $this->baseUser(['name' => '代理 部長']);
        $this->submittedFor($w, ['subject' => '部門長の番']);
        $assigned = $this->submittedFor($w, ['subject' => '付け替えの番']);
        ApprovalStep::where('request_id', $assigned->id)->where('kind', 'head')->update(['assignee_user_id' => $deputy->id]);
        $this->atPresident($w, ['subject' => '社長の番']);

        $w['applicant']->delete();
        foreach ([$w['head'], $deputy, $w['president']] as $judge) {
            $this->actingAs($judge)->get(route('approvals.home'))->assertOk()->assertSee('申請 花子・住宅事業部');
        }
        $w['applicant']->restore();

        foreach ([$w['head'], $deputy, $w['president']] as $judge) {
            $judge->delete();
        }
        foreach ([route('approvals.home'), route('approvals.requests.index')] as $url) {
            $this->actingAs($w['applicant'])->get($url)->assertOk()
                ->assertSee('部門長（部門 長）')->assertSee('部門長（代理 部長）')->assertSee('社長（社長 太郎）');
        }
    }

    /** ホームと一覧の問い合わせの数は、申請の件数で増えない（いま誰の番かの担当・申請者・部門を先に読む。N+1） */
    public function test_the_home_and_the_list_do_not_query_per_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $deputy  = $this->baseUser(['name' => '代理 部長']);
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });

        $measure = function () use ($w, $deputy, &$queries): array {
            $counts = [];
            foreach (['申請者' => $w['applicant'], '部門長' => $w['head'], '付け替えの担当' => $deputy, '審査担当者' => $w['reviewer'], '社長' => $w['president']] as $label => $user) {
                foreach (['ホーム' => route('approvals.home'), '一覧' => route('approvals.requests.index')] as $page => $url) {
                    $this->actingAs($user)->get($url)->assertOk();   // 設定などを先に読ませる
                    $before = $queries;
                    $this->actingAs($user)->get($url)->assertOk();
                    $counts["{$page}（{$label}）"] = $queries - $before;
                }
            }

            return $counts;
        };

        $this->oneOfEach($w, $deputy, 'A');
        $few = $measure();
        $this->oneOfEach($w, $deputy, 'B');
        $this->oneOfEach($w, $deputy, 'C');

        $this->assertSame($few, $measure(), '申請が増えると問い合わせが増える（先に読んでいない関係がある）');
    }

    /** 下書き・部門長の番・付け替え・審査の番・社長の番・差戻し・条件確認・決裁済み・取り下げを 1 件ずつ */
    private function oneOfEach(array $w, User $deputy, string $tag): void
    {
        $workflow = app(Workflow::class);
        $this->draftFor($w, ['subject' => "下書き{$tag}"]);
        $this->submittedFor($w, ['subject' => "部門長の番{$tag}"]);
        $assigned = $this->submittedFor($w, ['subject' => "付け替え{$tag}"]);
        ApprovalStep::where('request_id', $assigned->id)->where('kind', 'head')->update(['assignee_user_id' => $deputy->id]);
        $review = $this->submittedFor($w, ['subject' => "審査の番{$tag}"]);
        $workflow->judgeHead($review, $w['head'], $review->lock_version, ApprovalStepResult::Approve, null);
        $this->atPresident($w, ['subject' => "社長の番{$tag}"]);
        $returned = $this->submittedFor($w, ['subject' => "差戻し{$tag}"]);
        $workflow->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $condition = $this->atPresident($w, ['subject' => "条件確認{$tag}"]);
        $workflow->judgePresident($condition, $w['president'], $condition->lock_version, ApprovalStepResult::Conditional, '条件');
        $approved = $this->atPresident($w, ['subject' => "決裁済み{$tag}"]);
        $workflow->judgePresident($approved, $w['president'], $approved->lock_version, ApprovalStepResult::Approve, null);
        $withdrawn = $this->submittedFor($w, ['subject' => "取り下げ{$tag}"]);
        $workflow->withdraw($withdrawn, $w['applicant'], $withdrawn->lock_version, null);
    }

    /** 下書きだけの人に「まだ申請はありません」と出さない（下書きは進み具合に出さないので、一覧から開けることを伝える） */
    public function test_a_user_with_only_drafts_is_pointed_to_the_list(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '保存した下書き']);

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertDontSee('まだ申請はありません。')
            ->assertSee('下書きは「自分の申請」から開けます。');
    }
}
