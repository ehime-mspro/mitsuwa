<?php

namespace Tests\Concerns;

use App\Models\Buyer;

/**
 * 買主一覧（buyers/index。住宅事業・不動産で共用）のランクの小窓が、ランクを変えられる人（経営層・管理者＝
 * `PATCH api/customers/{buyer}/rank` の role:executive,manager と同じ）にだけ描かれていること
 * （設計メモ 2026-11-26-save-ajax-403-design.md・Bug #96 と同じ形）。
 * 押せない人（一般担当）には、同じ文字のバッジを押せない形（cursor: default・onclick なし）で見せ、小窓の要素と script を描かない。
 */
trait ChecksRankMenu
{
    protected function assertRankMenu(string $html, Buyer $buyer, string $department, bool $canChange): void
    {
        $label = $buyer->getDepartmentPivot($department)->rank->label();
        $found = preg_match_all('/<span\b[^>]*\bstyle="([^"]*)"[^>]*>\s*' . preg_quote(e($label), '/') . '\s*<\/span>/u', $html, $m);
        $this->assertSame(1, $found, "ランク「{$label}」のバッジがちょうど 1 つ描かれていない");

        $menu = [
            "onclick=\"openRankDropdown(this, {$buyer->id}, '{$department}')\"",
            'id="rank-dropdown-global"',
            'function selectRank(',
            'function xhrFailureMessage(',
        ];
        foreach ($menu as $needle) {
            $canChange
                ? $this->assertStringContainsString($needle, $html, "ランクを変えられる人の画面に {$needle} が無い")
                : $this->assertStringNotContainsString($needle, $html, "ランクを変えられない人の画面に {$needle} がある");
        }

        $this->assertStringContainsString($canChange ? 'cursor: pointer' : 'cursor: default', $m[1][0], 'バッジの形（押せる・押せない）が違う');
        $this->assertStringNotContainsString($canChange ? 'cursor: default' : 'cursor: pointer', $m[1][0], 'バッジの形（押せる・押せない）が違う');
    }
}
