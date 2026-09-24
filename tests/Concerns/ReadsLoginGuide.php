<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * ログイン案内の「元の画面へ戻る」を、ブラウザと同じようにたどる（F2・Bug #47）。
 *
 * ⚠ テストの HTTP クライアントは**リファラーを送らない**（`from()` を使ったときだけ送る）。
 *   `url()->previous()` はリファラーを優先するので、リファラー無しのテストでは F2（CSV の確定で 405）が
 *   原理的に見えなかった。案内を出す POST は必ず `from(フォームが載っていた画面の URL)` で送ること。
 */
trait ReadsLoginGuide
{
    protected function guideBackHref(string $html): string
    {
        $this->assertSame(
            1,
            preg_match_all('/<a\b[^>]*\bclass="guide-back"[^>]*>元の画面へ戻る<\/a>/u', $html, $links),
            '「元の画面へ戻る」がちょうど 1 つでない'
        );
        $this->assertSame(1, preg_match('/\bhref="([^"]*)"/', $links[0][0], $href), '「元の画面へ戻る」に href が無い');

        return html_entity_decode($href[1], ENT_QUOTES, 'UTF-8');
    }

    /** 行き先が $expected で、その画面が実際に開く（405 などでない）こと */
    protected function assertGuideGoesBackTo(TestResponse $guide, string $expected, User $as): void
    {
        $href = $this->guideBackHref($guide->getContent());

        $this->assertSame($expected, $href, '「元の画面へ戻る」の行き先が違う');
        $this->actingAs($as)->get($href)->assertOk();
    }
}
