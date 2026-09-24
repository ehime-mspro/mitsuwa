<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * モバイルのドロワーを閉じる手段（オーバーレイのタップ ＋ 閉じるボタン）。
 *
 * ⚠ **サイドバーの partial は 2 本ある**（基幹 `sidebar.blade.php` と決裁 `sidebar_approval.blade.php`）。
 *   2026-09-18 のコード品質レビューで、決裁側だけ閉じるボタンが無く、しかも
 *   **オーバーレイを丸ごと消しても全 2050 本が緑**だったことが実測で分かった
 *   （`grep -rn "sidebarOpen = false\|bg-black/50" tests/` が 0 件）。
 *   ドロワーを開いた利用者が閉じられなくなるのに、テストも `view:cache` も HTML も全部通る。
 *
 * ⚠ **列挙でなく全件分類**（docs/RULES.md Bug #45 ①）。`partials/sidebar*.blade.php` を機械的に
 *   走査し、`RENDERED_BY` に無い partial があれば落とす ＝ **3 本目が無検査のまま増えない**。
 *   逆向き（表に在るのにファイルが無い）も見る。
 *
 * ⚠ この分類は**初回の実行でいきなり 3 本目を拾った** — `layouts/partials/` に
 *   `sidebar_*_snippet.blade.php` が 3 本あり、どれも過去の実装セッションが残した手順書で
 *   参照 0 件だった（2026-09-18 に削除）。列挙で書いていたら気づかないままだった（Bug #45 ① の実例）。
 *
 * ⚠ 描画した HTML で見る（partial のソースを読むだけでは、その partial が本当に
 *   `layouts/app.blade.php` から出るのかを確かめられない）。
 *
 * 関連: `LayoutSidebarCloakTest`（x-cloak の決まり。Bug #56）— あちらは基幹しか描画しない。
 */
class LayoutSidebarDrawerTest extends TestCase
{
    use RefreshDatabase;

    /** partial のファイル名 => それを描画させる利用者の種類 */
    private const RENDERED_BY = [
        'sidebar.blade.php'          => 'base',
        'sidebar_approval.blade.php' => 'approval',
    ];

    /**
     * サイドバーではないもの（ファイル名 => 何か）。**いまは空**。
     *
     * ⚠ これは**逃げ道ではない** — 下の `test_the_snippets_are_not_included_anywhere` が
     *   「どこからも @include されていない」ことを対で固定する。include した瞬間に落ちる。
     * ⚠ 2026-09-18 にこの分類を入れた初回の実行で、`sidebar_{buyer,contract,housing}_snippet.blade.php`
     *   の 3 本が「分類されていない」で落ちた。中身は Blade ではなく**過去のセッションが人間向けに
     *   残した作業手順**で、どこからも `@include` されていない死にファイルだった（実測）。
     *   **3 本は同日に削除した**ので、ここは空のまま。サイドバーでない `sidebar*` を置くなら
     *   理由つきでここへ。
     */
    private const NOT_A_SIDEBAR = [];

    private function html(string $kind): string
    {
        if ($kind === 'base') {
            $user = User::factory()->create([
                'role'                 => UserRole::Executive->value,
                'must_change_password' => false,
            ]);

            return $this->actingAs($user)->get(route('tenant.contracts.index'))->assertOk()->getContent();
        }

        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        return $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();
    }

    /** `<aside x-show="sidebarOpen">` の塊（`<aside>` は入れ子にならないので非貪欲で足りる） */
    private function drawer(string $html, string $partial): string
    {
        $this->assertSame(
            1,
            preg_match_all('#<aside\b[^>]*\bx-show="sidebarOpen"[^>]*>.*?</aside>#s', $html, $m),
            "{$partial}: モバイルのドロワーの <aside> が 1 本に定まらない（走査が壊れていないか）"
        );

        return $m[0][0];
    }

    public function test_every_sidebar_partial_is_classified(): void
    {
        $files = array_map('basename', glob(resource_path('views/layouts/partials/sidebar*.blade.php')));

        $this->assertNotEmpty($files, 'サイドバーの partial が 1 本も見つからない（走査が壊れていないか）');

        foreach ($files as $file) {
            $inRendered = array_key_exists($file, self::RENDERED_BY);
            $inNot      = array_key_exists($file, self::NOT_A_SIDEBAR);

            $this->assertTrue(
                $inRendered || $inNot,
                "{$file} が分類されていない。サイドバーの partial を足したら、それを描画する利用者を"
                    . ' RENDERED_BY に足して、ドロワーを閉じる手段が在ることを課すこと'
                    . '（サイドバーでないなら理由つきで NOT_A_SIDEBAR へ）'
            );
            $this->assertFalse($inRendered && $inNot, "{$file} が両方のリストに入っている");
        }

        foreach ([...array_keys(self::RENDERED_BY), ...array_keys(self::NOT_A_SIDEBAR)] as $classified) {
            $this->assertContains($classified, $files, "分類に書いた {$classified} が実在しない");
        }
    }

    /**
     * NOT_A_SIDEBAR に入れたものが、本当にどこからも読み込まれていないこと。
     *
     * ⚠ これが無いと NOT_A_SIDEBAR がただの逃げ道になる（分類しさえすれば検査を免れる）。
     */
    public function test_the_snippets_are_not_included_anywhere(): void
    {
        $sources = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $sources[$f->getPathname()] = file_get_contents($f->getPathname());
            }
        }

        $this->assertGreaterThan(100, count($sources), 'ビューの走査が空振りしている');

        foreach (array_keys(self::NOT_A_SIDEBAR) as $file) {
            $dotted = 'layouts.partials.' . str_replace('.blade.php', '', $file);

            foreach ($sources as $path => $source) {
                $this->assertStringNotContainsString(
                    $dotted,
                    $source,
                    basename($path) . " が {$dotted} を読み込んでいる。読み込むなら NOT_A_SIDEBAR から外し、"
                        . 'RENDERED_BY へ分類してドロワーを閉じる手段を課すこと'
                );
            }
        }
    }

    /** オーバーレイをタップすれば閉じる（両方の partial） */
    public function test_the_overlay_closes_the_drawer(): void
    {
        foreach (self::RENDERED_BY as $partial => $kind) {
            $html = $this->html($kind);

            $this->assertSame(
                1,
                preg_match_all('#<div\b[^>]*\bx-show="sidebarOpen"[^>]*>#', $html, $m),
                "{$partial}: モバイルのオーバーレイの <div> が 1 本に定まらない"
            );

            $this->assertStringContainsString('bg-black/50', $m[0][0], "{$partial}: オーバーレイの覆いが無い");
            $this->assertStringContainsString(
                '@click="sidebarOpen = false"',
                $m[0][0],
                "{$partial}: オーバーレイを押しても閉じない"
            );
        }
    }

    /**
     * ドロワーの中に閉じるボタンが在る（両方の partial）。
     *
     * ⚠ オーバーレイと**対で**見る。片方だけだと、もう片方を消しても緑のまま通る。
     */
    public function test_the_drawer_has_its_own_close_button(): void
    {
        foreach (self::RENDERED_BY as $partial => $kind) {
            $drawer = $this->drawer($this->html($kind), $partial);

            $this->assertMatchesRegularExpression(
                '#<button\b[^>]*@click="sidebarOpen = false"#',
                $drawer,
                "{$partial}: ドロワーの中に閉じるボタンが無い（オーバーレイのタップだけが閉じる手段になる）"
            );
        }
    }
}
