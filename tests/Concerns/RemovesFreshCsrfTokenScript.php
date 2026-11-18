<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Blade;

/**
 * 画面の HTML から、レイアウトの head にある鍵の差し替えの部品（_partials/_fresh_csrf_token。419 の画面から「戻る」で戻った画面の
 * 鍵を差し替える）の script を除く。部品は全画面に 1 つずつあり pageshow のリスナーを持つので、画面ごとの pageshow を数える
 * テスト（決裁の利用者の管理・台帳の絞り込みのフォームを戻す Bug #65）は、これを除いてから数える。
 * ⚠ 部品がちょうど 1 回描かれていることも確かめる（除けなかったのに数え直して、黙って通らないように）
 */
trait RemovesFreshCsrfTokenScript
{
    protected function withoutFreshCsrfTokenScript(string $html): string
    {
        $partial = Blade::render("@include('_partials._fresh_csrf_token')");
        $this->assertSame(1, substr_count($html, $partial), 'レイアウトの鍵の差し替えの部品がちょうど 1 回描かれていない');

        return str_replace($partial, '', $html);
    }
}
