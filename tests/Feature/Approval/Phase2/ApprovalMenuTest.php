<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserRole;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 基幹のメニューとダッシュボードの「決裁」と対応待ちの件数（段階2 設計書 §5.15・要件 12.2・15.2・2b 計画 Task 7）。
 *
 * ⚠ サイドバーは 3 か所（展開・折りたたみ・スマホのドロワー）を別々に見る（ページ全体を 1 回見るだけだと、
 *   1 か所を丸ごと消しても緑のまま通る。ApprovalSidebarTest と同じ理由）。
 */
class ApprovalMenuTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use CreatesMansionSchema;
    use CreatesRealEstateSchema;

    /** @return array{expanded: string, rail: string, drawer: string} */
    private function sidebars(string $html): array
    {
        $out = [];
        foreach (['expanded' => 'sidebarExpanded', 'rail' => '!sidebarExpanded', 'drawer' => 'sidebarOpen'] as $key => $xShow) {
            $this->assertSame(1, preg_match('#<aside\b[^>]*\bx-show="' . preg_quote($xShow, '#') . '"[^>]*>(.*?)</aside>#s', $html, $m), "{$key} の <aside> が 1 本に定まらない");
            $out[$key] = $m[1];
        }

        return $out;
    }

    private function html(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    /** 部門長に 2 件の対応待ちがある組織 */
    private function worldWithTwoWaiting(): array
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);
        $this->submittedFor($w);

        return $w;
    }

    public function test_nothing_is_added_before_launch(): void
    {
        $w = $this->worldWithTwoWaiting();

        $html = $this->html($w['head'], '/dashboard/tenant');

        $this->assertStringNotContainsString('決裁', $html, '使い始める前に基幹の画面へ決裁が出た（D1）');
    }

    public function test_the_base_sidebar_shows_the_count_in_all_three_places(): void
    {
        $w = $this->worldWithTwoWaiting();
        $this->launchApprovals();

        $sidebars = $this->sidebars($this->html($w['head'], '/dashboard/tenant'));

        $badge = '<span class="sr-only">対応待ち </span>2<span class="sr-only"> 件</span>';
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertMatchesRegularExpression('#<a\s+href="' . preg_quote(route('approvals.home'), '#') . '"[^>]*>\s*決裁\s*<span[^>]*>' . preg_quote($badge, '#') . '</span>#u', $sidebars[$key], "{$key} に「決裁」と件数が無い");
        }
        $this->assertStringContainsString('title="決裁（対応待ち 2 件）"', $sidebars['rail']);
        $this->assertMatchesRegularExpression('#<span class="absolute[^"]*"[^>]*aria-hidden="true">2</span>#', $sidebars['rail'], '折りたたみ版に件数の丸印が無い');
    }

    public function test_no_badge_when_nothing_is_waiting(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $html     = $this->html($w['head'], '/dashboard/tenant');
        $sidebars = $this->sidebars($html);

        $this->assertMatchesRegularExpression('#<a\s+href="' . preg_quote(route('approvals.home'), '#') . '"[^>]*>\s*決裁\s*</a>#u', $sidebars['expanded'], '件数が 0 でも「決裁」は出す（丸印は出さない）');
        $this->assertStringNotContainsString('対応待ち ', $sidebars['expanded']);
        // 折りたたみとドロワーも 0 件は丸を出さない（2b 計画 Task 8 の変異 N06）
        $this->assertSame(1, preg_match('#<a href="' . preg_quote(route('approvals.home'), '#') . '" title="決裁（対応待ち 0 件）"[^>]*>(.*?)</a>#s', $sidebars['rail'], $rail), '折りたたみに「決裁」が無い');
        $this->assertStringNotContainsString('<span', $rail[1], '折りたたみに 0 件の丸が出た');
        $this->assertStringNotContainsString('対応待ち ', $sidebars['drawer']);
        $this->assertStringContainsString('決裁の対応待ち</span> <span class="font-bold tabular-nums">0</span> 件', $html, 'ダッシュボードには 0 件と出す');
    }

    public function test_both_dashboards_link_to_the_approval_home_with_the_count(): void
    {
        // 経営ダッシュボードは賃貸マンション（ms_*）・不動産と住宅（re_*・hs_*）も読む（本番は raw SQL の表）
        $this->createMansionSchema();
        $this->createRealEstateSchema();
        $w         = $this->worldWithTwoWaiting();
        $executive = $this->baseUser(['name' => '経営 太郎', 'role' => UserRole::Executive->value]);
        $w['dept']->update(['head_user_id' => $executive->id]);   // 経営層も部門長として対応待ちを持つ
        $this->launchApprovals();

        foreach (['/dashboard/executive' => $executive, '/dashboard/tenant' => $w['head']] as $url => $user) {
            $html = $this->html($user, $url);
            $card = substr($html, strpos($html, 'href="' . route('approvals.home') . '" class="mb-4'));
            $card = substr($card, 0, strpos($card, '</a>'));
            $expected = $user->is($executive) ? '2' : '0';
            $this->assertStringContainsString('決裁の対応待ち</span> <span class="font-bold tabular-nums">' . $expected . '</span> 件', $card, "{$url} のカード");
            $this->assertStringContainsString('決裁のホームへ', $card);
        }
    }

    /** 件数は 1 リクエストに 1 回だけ数える（サイドバー 3 か所とダッシュボードで数え直さない。§5.15） */
    public function test_the_count_is_computed_once_per_request(): void
    {
        $w = $this->worldWithTwoWaiting();
        $this->launchApprovals();
        $counts = 0;
        DB::listen(function ($query) use (&$counts): void {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'approval_steps')) {
                $counts++;
            }
        });

        $this->html($w['head'], '/dashboard/tenant');

        $this->assertSame(1, $counts, '対応待ちの段階を数える問い合わせが 1 回でない');
    }

    public function test_the_approval_only_sidebar_shows_the_count_on_the_home_link(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(\App\Support\Approval\Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, \App\Enums\ApprovalStepResult::Return, '直してください');

        $sidebars = $this->sidebars($this->html($w['applicant'], route('approvals.home')));

        foreach (['expanded', 'drawer'] as $key) {
            $this->assertStringContainsString('決裁のホーム', $sidebars[$key]);
            $this->assertStringContainsString('<span class="sr-only">対応待ち </span>1<span class="sr-only"> 件</span>', $sidebars[$key], "{$key} に件数が無い（申請者の差戻しの対応）");
        }
        $this->assertMatchesRegularExpression('#title="決裁のホーム"[^>]*>決<span[^>]*aria-hidden="true">1</span></a>#u', $sidebars['rail']);
    }

    /** 決裁のみ利用者のサイドバーも、0 件は丸を出さない（折りたたみを含む。2b 計画 Task 8 の変異 N07） */
    public function test_the_approval_only_sidebar_has_no_badge_when_nothing_is_waiting(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $sidebars = $this->sidebars($this->html($w['applicant'], route('approvals.home')));

        foreach (['expanded', 'drawer'] as $key) {
            $this->assertStringContainsString('決裁のホーム', $sidebars[$key]);
            $this->assertStringNotContainsString('対応待ち ', $sidebars[$key], "{$key} に 0 件の丸が出た");
        }
        $this->assertMatchesRegularExpression('#title="決裁のホーム"[^>]*>決</a>#u', $sidebars['rail'], '折りたたみに 0 件の丸が出た');
    }

    /** 基幹の画面は、決裁の設定の行が無くても作らない（読むだけ。計画 §0.10・2b 計画 Task 8 の変異 N05・N08） */
    public function test_a_base_page_does_not_create_the_settings_row(): void
    {
        $user = $this->baseUser();
        $this->assertSame(0, ApprovalSetting::count(), '前提: 設定の行が無い');

        $html = $this->html($user, '/dashboard/tenant');

        $this->assertStringNotContainsString('決裁', $html);
        $this->assertSame(0, ApprovalSetting::count(), '基幹の画面が設定の行を作った');
    }
}
