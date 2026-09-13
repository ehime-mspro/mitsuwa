<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PC 展開サイドバーに `x-cloak` を付けない（docs/RULES.md Bug #56）。
 *
 * 展開サイドバーは `body` の `x-data` で `sidebarExpanded: true` 固定（永続化なし）＝ 起動後は必ず表示される。
 * そこに `x-cloak` があると、Alpine の起動前だけ `display: none` になり、パース中に走るスクリプトが表示領域を
 * 220px 広く測っていた（工程表ボードの初期スクロールが 220px 手前で止まる・一覧の横スクロールのヒントが出ない）。
 * 2026-09-13 に外した。起動前から表示されるので、読み込み時にサイドバーが後から出てくるガタつきも消える。
 *
 * ⚠ 外してよいのは展開サイドバーの外枠だけ。起動前に隠れている必要がある要素（折りたたみ版・モバイルのドロワー・
 *   グループの中身）は `x-cloak` を残す。外枠の `x-cloak` が無くなったので、グループの中身は自前の `x-cloak` だけが頼り
 *   （無いと Alpine の起動前に全グループが開いた状態で描かれる）。
 * ⚠ 描画した HTML で見る（コンポーネント `x-sidebar-group` の中身はビューのソースに現れない）。
 */
class LayoutSidebarCloakTest extends TestCase
{
    use RefreshDatabase;

    private function layoutHtml(): string
    {
        $executive = User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        return $this->actingAs($executive)->get(route('tenant.contracts.index'))->assertOk()->getContent();
    }

    /** `x-show="…"` を持つ開始タグ（属性が複数行にまたがってもよい） */
    private function openingTags(string $html, string $element, string $xShow): array
    {
        preg_match_all('#<' . $element . '\b[^>]*\bx-show="' . preg_quote($xShow, '#') . '"[^>]*>#s', $html, $m);

        return $m[0];
    }

    public function test_expanded_sidebar_is_not_cloaked(): void
    {
        $tags = $this->openingTags($this->layoutHtml(), 'aside', 'sidebarExpanded');

        $this->assertCount(1, $tags, 'PC 展開サイドバーの <aside> が 1 本に定まらない');
        $this->assertStringNotContainsString(
            'x-cloak',
            $tags[0],
            'PC 展開サイドバーに x-cloak が付いている（Alpine の起動前だけ隠れ、パース中のスクリプトが表示領域を 220px 広く測る）'
        );
    }

    public function test_elements_hidden_before_alpine_keep_x_cloak(): void
    {
        $html = $this->layoutHtml();

        $rail = $this->openingTags($html, 'aside', '!sidebarExpanded');
        $this->assertCount(1, $rail, '折りたたみ版サイドバーの <aside> が 1 本に定まらない');
        $this->assertStringContainsString('x-cloak', $rail[0], '折りたたみ版サイドバーの x-cloak が無い（起動前に展開版と並んで出る）');

        $drawer = $this->openingTags($html, 'aside', 'sidebarOpen');
        $this->assertCount(1, $drawer, 'モバイルのドロワーの <aside> が 1 本に定まらない');
        $this->assertStringContainsString('x-cloak', $drawer[0], 'モバイルのドロワーの x-cloak が無い（起動前に開いた状態で出る）');

        $groups = $this->openingTags($html, 'div', 'open');
        // 経営層はすべてのグループが見える（2026-09-13 時点で 17。PC 展開版とモバイルのドロワーの両方に出る）
        $this->assertGreaterThan(10, count($groups), 'サイドバーのグループの中身が見つからない（走査が壊れていないか）');
        foreach ($groups as $tag) {
            $this->assertStringContainsString(
                'x-cloak',
                $tag,
                'サイドバーのグループの中身に x-cloak が無い（外枠の x-cloak を外したので、起動前に全グループが開いて出る）'
            );
        }
    }

    /**
     * サイドバーの開閉は window の resize を起こさないので、開閉して描き換えたあとに resize を送って幅を測り直させる。
     * 送らないと「1100px で右端までスクロール → 閉じる → 開く」で、スクロールできるのに横スクロールのヒントが消えたままになる
     * （閉じたときにスクロール位置が丸められて scroll が起き、広い状態でヒントを消す → 開いても何も起きない）。
     *
     * ⚠ 構造しか見られない（Alpine が評価する属性なので PHP からは実行できない）。実際の動きはブラウザで確かめる。
     * ⚠ `$nextTick` を外さないこと。x-show が表示を切り替える前に測ると、開閉前の幅で判定する。
     */
    public function test_toggling_the_sidebar_makes_pages_measure_again(): void
    {
        $this->assertSame(1, preg_match('#<body\b[^>]*>#s', $this->layoutHtml(), $body), '<body> が見つからない');

        $this->assertMatchesRegularExpression(
            '#\bx-init="[^"]*\$watch\(\s*\'sidebarExpanded\'[^"]*\$nextTick\([^"]*window\.dispatchEvent\(\s*new Event\(\s*\'resize\'\s*\)\s*\)#',
            $body[0],
            'サイドバーの開閉のあとに resize を送っていない（開閉で幅が変わっても横スクロールのヒントが測り直されない）'
        );
    }
}
