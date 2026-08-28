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
     * ⚠ **矢印自身の <span style="…"> にまで絞って見る。** セル全体（<a> の color を含む）を
     *   検索すると、矢印を無効な色（#E5E7EB ＝ 1.18:1。この修正が消そうとした 1.41:1 の
     *   欠陥より悪い）にしつつ <a> 側の $labelColor にテストが探す文字列を紛れ込ませる変異
     *   （$iconColor と $labelColor の値を交換する）が全 1013 テスト green のまま通った（実測）。
     *   矢印の色は矢印を包む <span style="…"> にしか出ない設計なので、そこだけを見る。
     */
    public function test_the_idle_arrow_is_dark_enough_to_be_seen(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'occupancy', 'dir' => 'desc']))
            ->getContent();

        $idle = $this->thInnerFor($html, '賃料収入');
        $this->assertStringContainsString('#6B7280', $this->iconSpanStyleFor($idle), '未使用の矢印が薄いまま（1.41:1 の #D1D5DB に戻っている）');
        $this->assertStringNotContainsString('#D1D5DB', $idle, '未使用の矢印に旧色が残っている');

        $active = $this->thInnerFor($html, '入居率');
        $this->assertStringContainsString('#059669', $this->iconSpanStyleFor($active), '並び替え中の矢印が緑でない');
    }

    /** ラベルが span で包まれ、かつ <a> が下線 CSS の掛かり先クラスを持っていること */
    public function test_the_label_is_wrapped_so_the_underline_can_target_the_text_only(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index'))
            ->getContent();

        $inner = $this->thInnerFor($html, '入居率');

        $this->assertStringContainsString(
            '<span class="sortable-th-label">入居率</span>',
            $inner,
            'ラベルが sortable-th-label で包まれていない（下線が矢印にも掛かる）'
        );

        // ⚠ app.css の 3 ルール（点線・ホバー・並び替え中の緑）は全部 .sortable-th-link に
        //   ぶら下がっている。描画済み HTML で見ないと、Blade 側でこのクラス名をリネーム
        //   しても（ラベルの span 化とは無関係に）3 ルールが丸ごと無効化されるのを検出
        //   できない（実測: sortable-header-link へ改名しても全 1013 テスト green のまま）。
        $this->assertStringContainsString(
            'class="sortable-th-link',
            $inner,
            'リンクが sortable-th-link クラスを持っていない（ホバー/フォーカス/並び替え中の下線 CSS が丸ごと効かなくなる）'
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
            '/th\[aria-sort="ascending"\] \.sortable-th-link \.sortable-th-label,\s*th\[aria-sort="descending"\] \.sortable-th-link \.sortable-th-label\s*\{[^}]*text-decoration-color:\s*#059669/s',
            $css,
            '並び替え中の列で下線が緑にならない、またはホバーに詳細度で勝てない形（.sortable-th-link 抜き）になっている'
        );
    }

    /**
     * 並び替え中の下線セレクタが、ホバーに詳細度で勝てる**形**で書かれていること。
     *
     * ⚠ **カスケードの決着そのものは PHP では証明できない。** 実ブラウザで実測（Chrome）:
     *     修正前: ホバー中 rgb(75,85,99) グレー ＝ 並び替え中でも手掛かりが消えていた
     *     修正後: ホバー中 rgb(5,150,105) 緑    ＝ 手掛かりが残る
     *   ここで測れるのは「その形になっているか」だけで、実際に勝つかは実ブラウザで
     *   見ること（Task 11。Bug #28 / #43 / #51 と同型）。
     * ⚠ **「選択子の綴りを固定する」だけでは詳細度勝負を保証できない。** 実測で 2 通りの
     *   反例が見つかり、どちらも「綴りの固定 + ホバーの存在確認」だけの旧テストは green の
     *   ままブラウザではホバー中に灰色へ落ちた:
     *     ① 並び替え中の緑ルールの**後ろ**に同じ詳細度 (0,3,1) の
     *        `table .sortable-th-link:hover .sortable-th-label { … }` を追加（後勝ち）
     *     ② 既存のホバールールに `!important` を追加
     *   ①は「ホバーが 1 回しか宣言されていない」、②は「!important が無い」でそれぞれ潰す。
     */
    public function test_the_active_underline_selector_carries_the_extra_specificity_component(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));

        $this->assertMatchesRegularExpression(
            '/\.sortable-th-link:hover \.sortable-th-label\s*\{/',
            $css,
            'ホバーの下線ルールが見つからない'
        );

        // ⚠ [aria-sort] を足しただけの (0,2,1) は (0,3,0) のホバーに常に負ける。
        //   .sortable-th-link と .sortable-th-label の両方（ホバー側と同じクラス数）を
        //   selector に持って初めて (0,3,1) でホバーに勝てる形になる。
        $this->assertMatchesRegularExpression(
            '/th\[aria-sort="ascending"\] \.sortable-th-link \.sortable-th-label\s*,\s*'
            . 'th\[aria-sort="descending"\] \.sortable-th-link \.sortable-th-label\s*\{/',
            $css,
            '並び替え中の下線セレクタがホバーに詳細度で勝てる形になっていない（.sortable-th-link が抜けている）'
        );

        // ⚠ ホバー宣言が同詳細度のまま 2 本目（別セレクタを前置しただけの複製など）で
        //   後から出てくると、後に書かれたほうが勝つ。app.css 全体で 1 回しか無いことを見る。
        $this->assertSame(
            1,
            substr_count($css, '.sortable-th-link:hover .sortable-th-label'),
            'ホバーの下線ルールが複数回宣言されている（後から追加された同詳細度の宣言に負ける）'
        );

        // ⚠ !important は詳細度を無視してその宣言を勝たせる。ホバー側に付くと、
        //   .sortable-th-link を足して詳細度を上げても意味が無くなる。
        $sectionStart = strpos($css, '.sortable-th-label');
        $sectionEnd = strpos($css, '.scroll-hint {');
        $this->assertNotFalse($sectionStart, '.sortable-th-label セクションの開始が見つからない');
        $this->assertNotFalse($sectionEnd, '.sortable-th-label セクションの終端（.scroll-hint）が見つからない');
        $this->assertStringNotContainsString(
            '!important',
            substr($css, $sectionStart, $sectionEnd - $sectionStart),
            '並び替え見出しの下線ルールに !important が使われている（詳細度で勝たせる設計と矛盾する）'
        );

        // 位置は副次的なチェック（詳細度で決着が付くので実害は無いが、読む順の慣例として残す）
        $hover  = strpos($css, '.sortable-th-link:hover .sortable-th-label');
        $active = strpos($css, 'th[aria-sort="ascending"] .sortable-th-link .sortable-th-label');

        $this->assertNotFalse($hover);
        $this->assertNotFalse($active);
        $this->assertGreaterThan($hover, $active, '並び替え中の下線ルールがホバーより前に書かれている');
    }

    /**
     * 矢印を包む <span style="…"> の style 属性だけを取り出す。
     *
     * ⚠ ラベルの <span class="sortable-th-label"> や、その外側の <a style="…"> が持つ
     *   $labelColor とは別物。矢印の色は「style 属性を持つ <span>」にしか出ない設計を
     *   前提にしている（現状 <th> セル内で style= を持つ span は矢印のものだけ）。
     */
    private function iconSpanStyleFor(string $thInner): string
    {
        $this->assertMatchesRegularExpression('/<span style="([^"]*)"/', $thInner, '矢印の <span style="…"> が見つからない');
        preg_match('/<span style="([^"]*)"/', $thInner, $matches);

        return $matches[1];
    }
}
