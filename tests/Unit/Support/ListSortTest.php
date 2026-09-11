<?php

namespace Tests\Unit\Support;

use App\Support\ListSort;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 並び替えパラメータの解釈（設計書 §4.1 / §4.2 / §5.2）。
 *
 * ⚠ Request::create() は**ミドルウェアを通らない**ので、実 HTTP なら
 *   ConvertEmptyStringsToNull が null にする値がここでは '' のまま届く（Bug #31）。
 *   両方を通すこと。null は query->set() で明示注入する。
 */
class ListSortTest extends TestCase
{
    private const ALLOWED = ['area', 'rent', 'monthly'];

    private function request(string $uri): Request
    {
        return Request::create($uri);
    }

    public function test_unknown_key_falls_back_to_default_order(): void
    {
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort=name'), self::ALLOWED));
    }

    public function test_array_sort_does_not_explode(): void
    {
        // ?sort[]=a は query('sort') が配列を返す。is_string() のガードが要る
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort[]=area'), self::ALLOWED));
    }

    public function test_script_like_sort_falls_back_to_default_order(): void
    {
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort=<script>'), self::ALLOWED));
    }

    public function test_empty_string_and_null_both_fall_back_to_default_order(): void
    {
        // Request::create() 経由（'' が届く）
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort='), self::ALLOWED));

        // 実 HTTP 経由（ミドルウェアが null にしたもの）を明示注入
        $request = $this->request('/x');
        $request->query->set('sort', null);
        $this->assertNull(ListSort::fromRequest($request, self::ALLOWED));
    }

    public function test_direction_defaults_to_descending(): void
    {
        $this->assertSame(ListSort::DESC, ListSort::fromRequest($this->request('/x?sort=rent'), self::ALLOWED)->direction);
        $this->assertSame(ListSort::DESC, ListSort::fromRequest($this->request('/x?sort=rent&dir=up'), self::ALLOWED)->direction);
        $this->assertSame(ListSort::ASC, ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED)->direction);
    }

    public function test_key_is_kept(): void
    {
        $sort = ListSort::fromRequest($this->request('/x?sort=monthly&dir=asc'), self::ALLOWED);

        $this->assertSame('monthly', $sort->key);
        $this->assertTrue($sort->isAscending());
    }

    public function test_next_cycles_default_then_desc_then_asc_then_default(): void
    {
        $none = null;
        $this->assertSame(ListSort::DESC, ListSort::next($none, 'rent'));

        $desc = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $this->assertSame(ListSort::ASC, ListSort::next($desc, 'rent'));

        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);
        $this->assertNull(ListSort::next($asc, 'rent'), '3 巡目は並び替え解除');
    }

    public function test_next_on_another_column_starts_at_desc(): void
    {
        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);

        $this->assertSame(ListSort::DESC, ListSort::next($asc, 'area'));
    }

    public function test_state_of_only_reports_the_active_column(): void
    {
        $desc = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);

        $this->assertSame(ListSort::DESC, ListSort::stateOf($desc, 'rent'));
        $this->assertNull(ListSort::stateOf($desc, 'area'));
        $this->assertNull(ListSort::stateOf(null, 'rent'));
    }

    public function test_url_drops_the_page_parameter(): void
    {
        $request = $this->request('/tenant/units?page=5');

        $url = ListSort::url($request, 'rent', null);

        $this->assertStringNotContainsString('page=', $url, '並べ替えたら 1 ページ目に戻す');
        $this->assertStringContainsString('sort=rent', $url);
        $this->assertStringContainsString('dir=desc', $url);
    }

    public function test_url_keeps_the_existing_filters(): void
    {
        $request = $this->request('/tenant/units?status=vacant&keyword=%E6%9C%AC%E7%94%BA');

        $url = ListSort::url($request, 'area', null);

        $this->assertStringContainsString('status=vacant', $url);
        $this->assertStringContainsString('keyword=', $url);
    }

    /**
     * 配列の絞り込み（部屋一覧の物件チップ `property_ids[]`。設計書 §4.1 の URL 例）が
     * url() を通しても往復すること。
     *
     * ⚠ **これは特性テスト（現状の固定）であって、検出力があるという主張はしない。**
     *   2026-08-25 に 2 通りの変異を当てて実測したが、**どちらも緑のまま**だった:
     *   ① `array_map(fn ($v) => $v ?? '', $query)` を外す
     *      → `property_ids` に null が無いので無影響（落ちたのは null 正規化のテストのほう）
     *   ② `Arr::query(...)` を素の `http_build_query(...)` に替える
     *      → `Arr::query()` は `http_build_query($array, '', '&', PHP_QUERY_RFC3986)` の
     *        薄いラッパー（`Arr.php:939-942`）で、配列の展開ロジックが同一だから
     *   それでも置くのは、**配列の往復がどこにも書かれていない暗黙の前提**だったため。
     *   値を平坦化する・空要素を捨てるといった壊し方をされたときには落ちる。
     */
    public function test_url_keeps_an_array_filter(): void
    {
        $request = $this->request('/tenant/units?property_ids%5B%5D=3&property_ids%5B%5D=5');

        $url = ListSort::url($request, 'rent', null);

        $this->assertSame(
            ['3', '5'],
            Request::create($url)->query('property_ids'),
            '配列の絞り込みがリンクから消えている'
        );
    }

    public function test_url_keeps_a_null_filter_by_normalising_it_to_an_empty_string(): void
    {
        // 実 HTTP では ?operation_status= がミドルウェアで null になる。
        // 正規化しないと Arr::query() がキーごと捨てて絞り込みがリンクから消える（Bug #31）
        $request = $this->request('/tenant/properties');
        $request->query->set('operation_status', null);

        $url = ListSort::url($request, 'occupancy', null);

        $this->assertStringContainsString('operation_status=', $url);
    }

    public function test_url_removes_sort_on_the_third_click(): void
    {
        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);
        $request = $this->request('/tenant/units?sort=rent&dir=asc&status=vacant');

        $url = ListSort::url($request, 'rent', $asc);

        $this->assertStringNotContainsString('sort=', $url, '3 巡目は既定順へ戻す');
        $this->assertStringNotContainsString('dir=', $url);
        $this->assertStringContainsString('status=vacant', $url, '絞り込みは残す');
    }

    /**
     * 「解除」は**並び順だけ**を消し、絞り込みは残す（設計書 §6）。
     *
     * ⚠ フィルタごと初期化する「クリア」ボタンとは役割が違う。両方が同じ結果になるなら
     *   バーに解除を出す意味が無い。**フィルタが残ることを必ず対で見る。**
     */
    public function test_clear_url_removes_only_the_sort(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=occupancy&dir=desc&occupancy=under75&year=2026');

        $url = ListSort::clearUrl($request);

        $this->assertStringNotContainsString('sort=', $url, '並び順が消えていない');
        $this->assertStringNotContainsString('dir=', $url, '向きが消えていない');
        $this->assertStringContainsString('occupancy=under75', $url, '絞り込みまで消えている（「クリア」と区別が無くなる）');
        $this->assertStringContainsString('year=2026', $url, '絞り込みまで消えている');
    }

    /** 並べ替えを解除したら 1 ページ目へ戻す（url() と同じ規約。前設計書 §4.3-5） */
    public function test_clear_url_drops_the_page_parameter(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=vacancy&dir=asc&page=3');

        $this->assertStringNotContainsString('page=', ListSort::clearUrl($request));
    }

    /**
     * null の絞り込みを '' へ正規化してから組み立てる（Bug #31）。
     *
     * ⚠ 怠ると `?occupancy=` のような空の絞り込みが**解除リンクから丸ごと消える**
     *   （実測: Arr::query(['a'=>null,'b'=>'','c'=>'x']) === 'b=&c=x'）。
     */
    public function test_clear_url_keeps_a_null_filter_by_normalising_it_to_an_empty_string(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=vacancy');
        $request->query->set('occupancy', null);

        $this->assertStringContainsString('occupancy=', ListSort::clearUrl($request));
    }

    /** 絞り込みが 1 つも無ければ素の URL（`?` を付けない） */
    public function test_clear_url_returns_a_bare_url_when_nothing_else_remains(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=vacancy&dir=desc');

        $this->assertSame('http://localhost/tenant/area-buildings', ListSort::clearUrl($request));
    }

    // ================================================================
    // 列ごとの周期（設計書 2026-09-11 §5）
    // ⚠ ここより上の既存テストは 1 本も書き換えない。省略時の挙動が
    //   従来と同一であることの証明になる（設計書 §7.2）。
    // ================================================================

    /** 1 回目が昇順の列: 既定 → 昇順 → 降順 → 既定（契約一覧の「物件 / 区画」） */
    public function test_a_column_whose_first_click_is_ascending_cycles_asc_desc_then_default(): void
    {
        $this->assertSame(ListSort::ASC, ListSort::next(null, 'rent', ListSort::ASC), '1 回目が昇順になっていない');

        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);
        $this->assertSame(ListSort::DESC, ListSort::next($asc, 'rent', ListSort::ASC), '2 回目が降順になっていない');

        $desc = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $this->assertNull(ListSort::next($desc, 'rent', ListSort::ASC), '3 回目は並び替え解除');

        // 別の列で並び替え中に押しても、1 回目は昇順から
        $this->assertSame(ListSort::ASC, ListSort::next($desc, 'area', ListSort::ASC));

        // first は「押したときの向き」であって「今の状態」ではない。並び替えていなければ消灯のまま
        $this->assertNull(ListSort::stateOf(null, 'rent', ListSort::ASC));
    }

    /**
     * 既定順の列: 既定（＝その列の first 向きが点灯）→ 逆向き → 既定（契約一覧の「契約日」）。
     *
     * ⚠ 並び替え指定が無いときに null を返すと、契約日で並んでいるのに見出しは ⇅ のまま・
     *   aria-sort="none" になり、読み上げにも嘘をつく（設計書 §3.1）。
     */
    public function test_the_default_column_is_lit_without_a_sort_and_steps_to_the_opposite_then_back(): void
    {
        $this->assertSame(ListSort::DESC, ListSort::stateOf(null, 'area', ListSort::DESC, isDefault: true), '既定順の列が初期表示で点灯していない');
        $this->assertSame(ListSort::ASC, ListSort::next(null, 'area', ListSort::DESC, isDefault: true), '既定から押すと逆向きへ進むべき');

        $asc = ListSort::fromRequest($this->request('/x?sort=area&dir=asc'), self::ALLOWED);
        $this->assertSame(ListSort::ASC, ListSort::stateOf($asc, 'area', ListSort::DESC, isDefault: true));
        $this->assertNull(ListSort::next($asc, 'area', ListSort::DESC, isDefault: true), '逆向きの次は既定へ戻す');

        // 別の列で並び替え中 → この列は消灯し、押すと既定へ戻す（sort を載せない）
        $rent = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $this->assertNull(ListSort::stateOf($rent, 'area', ListSort::DESC, isDefault: true), '別の列で並び替え中なのに既定順の列が点灯している');
        $this->assertNull(ListSort::next($rent, 'area', ListSort::DESC, isDefault: true), '別の列から押したら既定へ戻す（既定と同じ並びを別の状態として作らない）');

        // 手入力の「既定と同じ向き」は正規化しない。押すと逆向きへ進む（設計書 §4.3）
        $desc = ListSort::fromRequest($this->request('/x?sort=area&dir=desc'), self::ALLOWED);
        $this->assertSame(ListSort::DESC, ListSort::stateOf($desc, 'area', ListSort::DESC, isDefault: true));
        $this->assertSame(ListSort::ASC, ListSort::next($desc, 'area', ListSort::DESC, isDefault: true));
    }

    /** url() が first / isDefault を反映すること（見出しの href はここから出る） */
    public function test_url_follows_the_first_direction_and_the_default_column(): void
    {
        // 1 回目が昇順の列: 並び替え無しから押すと dir=asc（page は落とす）
        $url = ListSort::url($this->request('/tenant/contracts?page=2'), 'rent', null, ListSort::ASC);
        $this->assertStringContainsString('sort=rent', $url);
        $this->assertStringContainsString('dir=asc', $url, '1 回目が昇順の列なのに dir=asc になっていない');
        $this->assertStringNotContainsString('page=', $url);

        // 既定順の列: 並び替え無しから押すと逆向き
        $url = ListSort::url($this->request('/tenant/contracts'), 'area', null, ListSort::DESC, isDefault: true);
        $this->assertStringContainsString('sort=area', $url);
        $this->assertStringContainsString('dir=asc', $url, '既定順の列を押したら逆向きへ進むべき');

        // 既定順の列: 別の列で並び替え中に押すと既定へ（sort も dir も載せない・絞り込みは残す）
        $rent = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $url = ListSort::url($this->request('/tenant/contracts?sort=rent&dir=desc&status=all'), 'area', $rent, ListSort::DESC, isDefault: true);
        $this->assertStringNotContainsString('sort=', $url, '既定順の列を押したのに並び替えが残っている');
        $this->assertStringNotContainsString('dir=', $url);
        $this->assertStringContainsString('status=all', $url, '絞り込みまで消えている');
    }

    /**
     * $first が asc / desc 以外なら例外（設計書 §5）。
     *
     * ⚠ 黙って変な周期で回るより、配線テストで 500 として見つかるほうが良い
     *   （x-sortable-th の column 打ち間違いと同じ方針）。
     * ⚠ **3 つの入口をそれぞれ叩く。** 検査は stateOf() に 1 箇所だけ置き、next() と url() は
     *   そこを経由する作りだが、誰かが経由をやめても落ちるように入口ごとに固定する。
     * ⚠ 'ASC'（大文字）も不正。ListSort::ASC は小文字の 'asc'。
     */
    public function test_an_unknown_first_direction_is_rejected(): void
    {
        $calls = [
            'stateOf' => fn (string $first) => ListSort::stateOf(null, 'rent', $first),
            'next'    => fn (string $first) => ListSort::next(null, 'rent', $first),
            'url'     => fn (string $first) => ListSort::url($this->request('/x'), 'rent', null, $first),
        ];

        foreach ($calls as $name => $call) {
            foreach (['ASC', 'up', ''] as $bad) {
                try {
                    $call($bad);
                    $this->fail("{$name}() が first='{$bad}' を黙って受け入れた");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString("'{$bad}'", $e->getMessage(), '例外の文言に不正な値が出ていない');
                }
            }
        }
    }
}
