<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * パンくずの「ホーム」の行き先（`layouts/app.blade.php`）。
 *
 * ⚠ かつては `route('dashboard')` 固定だった。`/dashboard` は門番
 *   `RestrictApprovalOnlyUsers::ALLOWED_NAMES` に無く、ルート名も `approvals.` で始まらないので、
 *   **決裁のみ利用者が押すと毎回「決裁以外の画面は使えません。」が出ていた**（行き先は正しいのに）。
 *   決裁の 4 画面すべてにパンくずがあるので全部で起きる。
 *
 * ⚠ `/dashboard` 自身が `User::homeRouteName()` へ転送するだけなので、**基幹を使う人の行き先は不変**
 *   （転送が 1 回減るだけ）。それを「変わっていないこと」として下で固定する。
 *
 * ⚠ **`assertSee` で URL を探さない**（Bug #43）。ページ本文にも同じ URL のリンクがありうるので、
 *   パンくずの `<nav>` を切り出してその中だけを見る。
 */
class LayoutBreadcrumbHomeTest extends TestCase
{
    use RefreshDatabase;

    /** パンくずの `<nav>`（`@hasSection('breadcrumb')` の中。ページ本文より前にある） */
    private function breadcrumb(string $html): string
    {
        $this->assertSame(
            1,
            preg_match_all('#<nav\b[^>]*>.*?</nav>#s', $html, $m),
            'パンくずの <nav> が 1 本に定まらない（走査が壊れていないか）'
        );

        return $m[0][0];
    }

    private function assertHomeLinkPointsAt(string $html, string $routeName): void
    {
        $nav = $this->breadcrumb($html);

        $this->assertMatchesRegularExpression(
            '#<a\b[^>]*\bhref="' . preg_quote(route($routeName), '#') . '"[^>]*>\s*ホーム\s*</a>#u',
            $nav,
            "パンくずの「ホーム」が {$routeName} を指していない"
        );
    }

    /** 決裁のみ利用者: 決裁のホームを指す（門番に跳ね返されない） */
    public function test_an_approval_only_user_goes_to_the_approval_home(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        $this->assertHomeLinkPointsAt($html, 'approvals.home');

        // ⚠ 古い行き先が残っていないこと。残っていると門番が警告つきで跳ね返す
        $this->assertStringNotContainsString(
            'href="' . route('dashboard') . '"',
            $this->breadcrumb($html),
            'パンくずが /dashboard を指したまま（門番に跳ね返されて警告が出る）'
        );
    }

    /**
     * 決裁のみ利用者が、**画面が描いたパンくずのリンクを実際に辿っても**警告が出ないこと。
     *
     * ⚠ 行き先を手で書いて叩いてはいけない（Bug #47）— それだと画面のリンクが壊れても緑のまま。
     *   **描画された href を取り出してそのまま GET する**（往復）。
     */
    public function test_following_the_rendered_link_does_not_warn(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $nav = $this->breadcrumb(
            $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent()
        );

        $this->assertSame(
            1,
            preg_match('#<a\b[^>]*\bhref="([^"]+)"[^>]*>\s*ホーム\s*</a>#u', $nav, $m),
            'パンくずの「ホーム」のリンクが取り出せない'
        );

        // ⚠ 門番は GET を警告つきで転送する。302 になった時点で「叱られている」
        $this->actingAs($user)->get($m[1])
            ->assertOk()
            ->assertSessionMissing('warning');
    }

    /** 基幹を使う人の行き先は変わらない（経営層） */
    public function test_an_executive_still_goes_to_the_executive_dashboard(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $html = $this->actingAs($user)->get(route('tenant.customers.index'))->assertOk()->getContent();

        $this->assertHomeLinkPointsAt($html, 'dashboard.executive');
    }

    /** 基幹を使う人の行き先は変わらない（経営層以外） */
    public function test_everyone_else_still_goes_to_the_tenant_dashboard(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Manager->value,
            'must_change_password' => false,
        ]);
        // 経営層でない人は部門のアクセスが要る（経営層は素通りする）。
        // ⚠ 部門は seeder で入れる — 入れずに attach すると id が null で**黙って何も付かず** 403 になる。
        $this->seed(DepartmentSeeder::class);
        $user->departments()->attach(Department::where('code', 'tenant')->value('id'));

        $html = $this->actingAs($user)->get(route('tenant.customers.index'))->assertOk()->getContent();

        $this->assertHomeLinkPointsAt($html, 'dashboard.tenant');
    }
}
