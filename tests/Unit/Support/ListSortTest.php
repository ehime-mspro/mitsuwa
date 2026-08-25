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
     * リンクから消えないこと。
     *
     * ⚠ 上の null 正規化と**同じ 1 行**が担っている。将来その行を
     *   再帰的な正規化や素の http_build_query に書き換えたときに、
     *   スカラーのテストだけでは緑のまま配列が壊れる（Bug #31 と同じ壊れ方）。
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
}
