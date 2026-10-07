<?php

namespace Tests\Feature\Zeal;

/**
 * 会員の詳細（GET zeal/members/{member}）の基本情報の並び。
 *
 * ⚠ 空白の無い長い値（メールアドレス）は、折り返す場所が無いので値の欄の最小の幅になる。
 *   `150px 1fr` の行と `1fr 1fr` の 2 列は、その最小の幅まで広がり、カードの `overflow: hidden` に右端が切れる
 *   （本番の実データで、375px で 24px・641px で 2 列目の値が 18px 切れた。2026-10-07。Bug #114 の追記）。
 * ⚠ 本当に切れないかは実ブラウザで測る（ここは描いた CSS の形しか見られない）。
 */
class MemberShowLayoutTest extends MemberScreenTestCase
{
    private const LONG_EMAIL = 'chikaito.longaddress.sample@ezweb.ne.jp';

    private function styleRule(string $html, string $selector): string
    {
        $this->assertSame(1, preg_match_all('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $html, $m), "{$selector} の規則がちょうど 1 つ無い");

        return preg_replace('/\s+/', ' ', $m[1][0]);
    }

    public function test_a_long_unbroken_value_wraps_inside_its_row(): void
    {
        $this->member->update(['email' => self::LONG_EMAIL]);
        $html = $this->showHtml();

        $this->assertStringContainsString('<div class="zeal-info-value">' . self::LONG_EMAIL . '</div>', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $this->styleRule($html, '.zeal-info-value'), '値の欄が長い語を折り返さない');
        $this->assertStringContainsString('grid-template-columns: 150px minmax(0, 1fr)', $this->styleRule($html, '.zeal-info-row'), '値の列が中身の幅まで広がる');
    }

    public function test_the_two_basic_columns_do_not_grow_with_their_content(): void
    {
        $html = $this->showHtml();

        $this->assertSame(1, preg_match_all('/<div class="grid-stack-sm" style="([^"]*)"/', $html, $m), '基本情報の 2 列がちょうど 1 つ無い');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)', $m[1][0], '2 列が中身の幅まで広がる');
    }
}
