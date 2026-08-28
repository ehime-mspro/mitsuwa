<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * 並び替え見出しの意匠（設計書 2026-08-28 §5 / モック 案A）。
 *
 * ⚠ **これは「見えるか」の証明ではない。** 色とクラスが**意図した場所に載っていること**
 *   しか測れない。実際に見えるか・点線がラベルだけに掛かっているかは
 *   実ブラウザで見る（Task 11。Bug #28 / #43 / #51 と同型）。
 * ⚠ 行は要らない。見出しはデータが 0 件でも描画されるので、物件を 1 件も作らない。
 */
class SortAffordanceTest extends TestCase
{
    use ParsesSortLinks;
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 未使用の矢印は #6B7280（4.63:1）、並び替え中は #059669。
     *
     * ⚠ **列ごとに切り出して見る。** ページ全体で見ると、別の列に同じ色が残っているだけで
     *   緑になる（thStyleFor の docblock に実測済みの同型の罠）。
     */
    public function test_the_idle_arrow_is_dark_enough_to_be_seen(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'occupancy', 'dir' => 'desc']))
            ->getContent();

        $idle = $this->thInnerFor($html, '賃料収入');
        $this->assertStringContainsString('#6B7280', $idle, '未使用の矢印が薄いまま（1.41:1 の #D1D5DB に戻っている）');
        $this->assertStringNotContainsString('#D1D5DB', $idle, '未使用の矢印に旧色が残っている');

        $active = $this->thInnerFor($html, '入居率');
        $this->assertStringContainsString('#059669', $active, '並び替え中の矢印が緑でない');
    }

    /** ラベルが span で包まれていること（点線下線をラベルだけに掛けるため） */
    public function test_the_label_is_wrapped_so_the_underline_can_target_the_text_only(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index'))
            ->getContent();

        $this->assertStringContainsString(
            '<span class="sortable-th-label">入居率</span>',
            $this->thInnerFor($html, '入居率'),
            'ラベルが sortable-th-label で包まれていない（下線が矢印にも掛かる）'
        );
    }

    /**
     * 点線下線とその状態変化が app.css にあること。
     *
     * ⚠ **Tailwind クラスではないので Blade の走査では守れない。** CSS ファイルを直接見る。
     * ⚠ **コメントを落としてから測る。** 説明に色コードを書いてあるので、
     *   実体を消してもコメントに一致して緑になる（Bug #42 ②）。
     */
    public function test_the_stylesheet_underlines_the_label_and_marks_the_active_column(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));

        $this->assertMatchesRegularExpression(
            '/\.sortable-th-label\s*\{[^}]*text-decoration:\s*underline dotted #9CA3AF/s',
            $css,
            'ラベルの点線下線が無い'
        );
        $this->assertMatchesRegularExpression(
            '/\.sortable-th-label\s*\{[^}]*text-underline-offset:\s*4px/s',
            $css,
            '下線がラベルに近すぎる（オフセットが無い）'
        );
        $this->assertMatchesRegularExpression(
            '/\.sortable-th-link:hover \.sortable-th-label\s*\{[^}]*text-decoration-color:\s*#4B5563/s',
            $css,
            'ホバーで下線が濃くならない'
        );
        $this->assertMatchesRegularExpression(
            '/th\[aria-sort="ascending"\] \.sortable-th-label,\s*th\[aria-sort="descending"\] \.sortable-th-label\s*\{[^}]*text-decoration-color:\s*#059669/s',
            $css,
            '並び替え中の列で下線が緑にならない'
        );
    }

    /**
     * 並び替え中の緑は**ホバーより後**に置くこと。
     *
     * ⚠ 順序が逆だと、並び替え中の列にマウスを乗せた瞬間に下線がグレーへ落ち、
     *   「今どの列で並んでいるか」の手掛かりが消える。CSS は同じ詳細度なら後勝ち。
     */
    public function test_the_active_underline_rule_comes_after_the_hover_rule(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));

        $hover  = strpos($css, '.sortable-th-link:hover .sortable-th-label');
        $active = strpos($css, 'th[aria-sort="ascending"] .sortable-th-label');

        $this->assertNotFalse($hover);
        $this->assertNotFalse($active);
        $this->assertGreaterThan($hover, $active, '並び替え中の下線がホバーに負ける順序で書かれている');
    }
}
