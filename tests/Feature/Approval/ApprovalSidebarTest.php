<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\ApprovalMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * サイドバーの出し分け（設計書 §5.15）。
 *
 * ⚠ 一般の利用者に見える変化は増やさない（D2）。決裁の管理へのリンクは、
 *   決裁の管理者に指定された人にだけ出す。
 * ⚠ Bug #56 の決まりを守る（展開サイドバーに `x-cloak` を付けない）。
 * ⚠ **サイドバーは 3 か所ある**（PC 展開版・PC 折りたたみ版・モバイルのドロワー）。
 *   1 か所でも漏れるとその画面幅でだけリンクが消えるのに、ページ全体を見る
 *   `assertStringContainsString` は 1 か所でも在れば緑になる（Bug #41 の型）。
 *   実測: プラン記載の素朴な形ではドロワーの塊を丸ごと消しても 6 本すべて緑だった。
 *   → `sidebars()` で 3 か所に切り分け、**それぞれに対して**アサートする。
 */
class ApprovalSidebarTest extends TestCase
{
    use RefreshDatabase;

    private function approvalAdmin(string $role = UserRole::Staff->value): User
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    /**
     * サイドバーの 3 か所を切り出す（`expanded` / `rail` / `drawer`）。
     *
     * ⚠ `<aside>` は入れ子にならないので非貪欲に `</aside>` まで取れば足りる。
     *   モバイルのオーバーレイは `<div x-show="sidebarOpen">` なので要素名で除かれる。
     */
    private function sidebars(string $html): array
    {
        $found = [];

        foreach (['expanded' => 'sidebarExpanded', 'rail' => '!sidebarExpanded', 'drawer' => 'sidebarOpen'] as $key => $xShow) {
            $pattern = '#<aside\b[^>]*\bx-show="' . preg_quote($xShow, '#') . '"[^>]*>.*?</aside>#s';
            $this->assertSame(1, preg_match_all($pattern, $html, $m), "サイドバー（{$key}）の <aside> が 1 本に定まらない");
            $found[$key] = $m[0][0];
        }

        return $found;
    }

    /**
     * サイドバーの塊の中に、その行き先とその文字を持つリンクが在ること。
     *
     * ⚠ ラベルは **タグの境目ごと** 見る — 素の部分一致は「利用者の管理ZZZ」のような
     *   接尾辞を足す改名を素通りさせる（実測。Bug #43 の型）。
     * ⚠ 行き先（href）と文字の**両方**を見る。片方だけだと、リンクは在るのに
     *   文字が変わった／文字は在るのに別の画面へ飛ぶ、のどちらかを見逃す。
     */
    private function assertHasLink(string $aside, string $href, string $label, string $where): void
    {
        $this->assertStringContainsString($href, $aside, "{$where} に「{$label}」の行き先が無い");
        $this->assertMatchesRegularExpression(
            '/>\s*' . preg_quote($label, '/') . '\s*</u',
            $aside,
            "{$where} に「{$label}」の文字が無い"
        );
    }

    /** 決裁のみ利用者には決裁用のサイドバーだけが出る */
    public function test_an_approval_only_user_gets_the_approval_sidebar(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('テナントダッシュボード', $html, '基幹のサイドバーが出ている');
        $this->assertStringNotContainsString('システム管理', $html);
        // 管理者でなければ管理のリンクは出ない
        $this->assertStringNotContainsString(route('approvals.admin.users.index'), $html);

        // 3 か所すべてが決裁のホームへのリンクを持つ（1 か所でも欠けるとその画面幅で行き場が無くなる）
        $sidebars = $this->sidebars($html);

        foreach ($sidebars as $key => $aside) {
            $this->assertStringContainsString(route('approvals.home'), $aside, "{$key} に決裁のホームへのリンクが無い");
        }

        // 折りたたみ版はアイコンだけなので、ラベルは展開版とドロワーで見る。
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], route('approvals.home'), '決裁のホーム', $key);
        }
    }

    public function test_an_approval_only_admin_sees_the_management_links(): void
    {
        $user = $this->approvalAdmin(UserRole::ApprovalOnly->value);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        // ⚠ ページ全体で見てはいけない — 決裁のホームの本文自身が同じ 2 本のリンクを持つので、
        //   サイドバーが 1 本も出さなくても緑になる（実測。Bug #43 / #46 の型）。
        $sidebars = $this->sidebars($html);

        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], route('approvals.admin.users.index'), '利用者の管理', $key);
            $this->assertHasLink($sidebars[$key], route('approvals.admin.organization.index'), '部門の管理', $key);
        }

        // 折りたたみ版は 1 本のアイコンリンクだけ（既存の「システム管理」と同じ形）
        $this->assertStringContainsString(route('approvals.admin.users.index'), $sidebars['rail'], 'rail に管理のアイコンリンクが無い');
    }

    /** 基幹を使う人で決裁の管理者に指定された人は、基幹のサイドバーに「決裁の管理」が増える */
    public function test_a_base_user_with_the_flag_gets_an_extra_group(): void
    {
        $user = $this->approvalAdmin();

        $html = $this->actingAs($user)->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString('テナントダッシュボード', $html, '基幹のサイドバーが消えている');

        // ⚠ 3 か所すべてを別々に見る。ページ全体を 1 回見るだけだと、ドロワーの塊を
        //   丸ごと消しても緑のまま通った（実測）。
        $sidebars = $this->sidebars($html);

        foreach (['expanded', 'drawer'] as $key) {
            // ⚠ グループの見出しもタグの境目ごと見る（`x-sidebar-group` の label なのでリンクではない）
            $this->assertMatchesRegularExpression('/>\s*決裁の管理\s*</u', $sidebars[$key], "{$key} に「決裁の管理」の見出しが無い");
            $this->assertHasLink($sidebars[$key], route('approvals.admin.users.index'), '利用者の管理', $key);
            $this->assertHasLink($sidebars[$key], route('approvals.admin.organization.index'), '部門の管理', $key);
        }

        // 折りたたみ版はアイコン 1 本（title に「決裁の管理」）
        $this->assertStringContainsString(route('approvals.admin.users.index'), $sidebars['rail'], 'rail に決裁の管理のアイコンリンクが無い');
        $this->assertStringContainsString('title="決裁の管理"', $sidebars['rail'], 'rail のアイコンに title が無い');
    }

    /** 指定されていない人には何も増えない（D2） */
    public function test_nothing_changes_for_everyone_else(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $html = $this->actingAs($user)->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringNotContainsString('決裁', $html, '一般の利用者の画面に決裁の文字が出ている');
    }

    /** 経営層（システム管理が見える人）でも、指定されていなければ決裁は出ない */
    public function test_an_executive_without_the_flag_gets_nothing_either(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $html = $this->actingAs($user)->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString('システム管理', $html, '経営層なのにシステム管理が出ていない（前提が崩れている）');
        $this->assertStringNotContainsString('決裁', $html, '決裁の管理者でない経営層に決裁が出ている');
    }

    /** ヘッダーのロール表示は自動で「決裁のみ」になる */
    public function test_the_header_shows_the_role_label(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $this->actingAs($user)->get(route('approvals.home'))->assertOk()->assertSee('決裁のみ');
    }

    /** 決裁のサイドバーも Bug #56 の決まりを守る */
    public function test_the_approval_sidebar_follows_the_cloak_rules(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        preg_match_all('#<aside\b[^>]*\bx-show="sidebarExpanded"[^>]*>#s', $html, $expanded);
        $this->assertCount(1, $expanded[0], 'PC 展開サイドバーの <aside> が 1 本に定まらない');
        $this->assertStringNotContainsString('x-cloak', $expanded[0][0], '展開サイドバーに x-cloak が付いている（Bug #56）');

        foreach (['!sidebarExpanded', 'sidebarOpen'] as $xShow) {
            preg_match_all('#<aside\b[^>]*\bx-show="' . preg_quote($xShow, '#') . '"[^>]*>#s', $html, $m);
            $this->assertCount(1, $m[0], "{$xShow} の <aside> が 1 本に定まらない");
            $this->assertStringContainsString('x-cloak', $m[0][0], "{$xShow} の x-cloak が無い");
        }
    }
}
