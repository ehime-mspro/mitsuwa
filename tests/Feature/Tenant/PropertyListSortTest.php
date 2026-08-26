<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * 物件一覧の並び替え（設計書 §5.3 / §6）。
 *
 * ⚠ 入居率・賃料収入は DB 列ではなく PropertyController::calculatePropertyStats が
 *   入れる派生値。**非稼働のときだけ null**（稼働は坪数 0 でも 0）なので、
 *   「値を持つ行＝稼働中・「—」の行＝非稼働」にきれいに分かれる。
 */
class PropertyListSortTest extends TestCase
{
    use ParsesForms;
    use ParsesSortLinks;
    use RefreshDatabase;

    private ?Customer $customer = null;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 契約に要る顧客（1 件を使い回す） */
    private function customer(): Customer
    {
        return $this->customer ??= Customer::create([
            'code' => 'CUST-S001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
    }

    /**
     * 物件を 1 つ作る。入居率 = $contracted / $units、賃料収入 = $contracted × $rentEach。
     *
     * @param  int  $n  連番（code は T-S00n。**既定順は code 昇順**）
     */
    private function makeProperty(
        int $n,
        int $units = 1,
        int $contracted = 0,
        int $rentEach = 0,
        string $operationStatus = 'active',
    ): Property {
        $property = Property::create([
            'code' => sprintf('T-S%03d', $n),
            'name' => sprintf('並び替えビル%02d', $n),
            'property_type' => 'tenant',
            'department' => 'tenant',
            'operation_status' => $operationStatus,
            'address' => '愛媛県松山市本町1-1',
        ]);

        for ($u = 1; $u <= $units; $u++) {
            $unit = Unit::create([
                'property_id' => $property->id,
                'floor' => $u,
                'room_number' => sprintf('%d01', $u),
                'display_name' => sprintf('%d01', $u),
                'status' => $u <= $contracted ? 'occupied' : 'vacant',
                'area_tsubo' => 10.00,
                'rent' => 0,
                'common_fee' => 0,
                'garbage_fee' => 0,
                'pest_control_fee' => 0,
                'deposit' => 0,
            ]);

            if ($u <= $contracted) {
                Contract::create([
                    'contract_number' => sprintf('C-S%03d-%d', $n, $u),
                    'department' => 'tenant',
                    'property_id' => $property->id,
                    'unit_id' => $unit->id,
                    'customer_id' => $this->customer()->id,
                    'status' => 'active',
                    'contract_date' => '2026-04-01',
                    'rent_start_date' => '2026-04-01',
                    'rent' => $rentEach,
                    'common_fee' => 0,
                    'garbage_fee' => 0,
                    'pest_control_fee' => 0,
                ]);
            }
        }

        return $property;
    }

    /**
     * ページ送りのリンクを実際に辿って、全ページの物件 ID を順に集める。
     *
     * ⚠ **`?page=2` を自分で組み立ててはいけない。** リンクが壊れていても sort が付いた
     *   状態で届くので**必ず緑**になる（Bug #31）。$paginator->nextPageUrl() を辿ること。
     */
    private function collectIdsAcrossPages(User $user, string $url): array
    {
        $ids = [];
        $guard = 0;

        while ($url !== null) {
            $response = $this->actingAs($user)->get($url);
            $response->assertOk();

            $paginator = $response->viewData('properties');
            foreach ($paginator as $property) {
                $ids[] = $property->id;
            }

            $url = $paginator->nextPageUrl();

            $this->assertLessThan(20, ++$guard, 'ページ送りが終わらない');
        }

        return $ids;
    }

    /** 並び替え指定が無ければ、今までと同じ順（稼働が先 → code 昇順） */
    public function test_the_default_order_is_unchanged(): void
    {
        $inactive = $this->makeProperty(3, operationStatus: 'inactive');
        $b = $this->makeProperty(2);
        $a = $this->makeProperty(1);

        $response = $this->actingAs($this->executive())->get(route('tenant.properties.index'));

        $response->assertOk();
        $this->assertSame(
            [$a->id, $b->id, $inactive->id],
            $response->viewData('properties')->pluck('id')->all()
        );
    }

    /** 不正な sort / dir は 500 にせず既定順へ落ちる */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 200000);
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 100000);

        $expected = [$a->id, $b->id];
        $user = $this->executive();

        foreach (['?sort=name', '?sort[]=income', '?sort=%3Cscript%3E', '?sort=', '?dir=up'] as $queryString) {
            $response = $this->actingAs($user)->get(route('tenant.properties.index') . $queryString);
            $response->assertOk();
            $this->assertSame(
                $expected,
                $response->viewData('properties')->pluck('id')->all(),
                "{$queryString} で既定順に落ちていない"
            );
        }
    }

    /**
     * 非稼働（画面では「—」）は昇順でも降順でも末尾。
     *
     * ⚠ **稼働を 3 棟にしてあるのは意図。** 2 棟だと既定順（code 昇順）が
     *   昇順か降順のどちらかと必ず一致してしまい、その向きが飾りになる（実測済み）。
     *   3 棟なら 既定 [1,2,3] / 降順 [2,1,3] / 昇順 [3,1,2] で全部食い違う。
     */
    public function test_inactive_properties_sort_last_in_both_directions(): void
    {
        $mid = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $high = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $low = $this->makeProperty(3, units: 1, contracted: 1, rentEach: 100000);
        $inactive = $this->makeProperty(4, operationStatus: 'inactive');

        $user = $this->executive();

        $desc = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame(
            [$high->id, $mid->id, $low->id, $inactive->id],
            $desc->viewData('properties')->pluck('id')->all()
        );
        $this->assertNull(
            $desc->viewData('properties')->firstWhere('id', $inactive->id)->rental_income,
            '非稼働物件に賃料収入が入っている（「—」にならない）'
        );

        $asc = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'asc']));
        $this->assertSame(
            [$low->id, $mid->id, $high->id, $inactive->id],
            $asc->viewData('properties')->pluck('id')->all()
        );
    }

    /**
     * 稼働していて値が 0 の物件は「—」ではない。末尾へ飛ばさず 0 として並べる。
     *
     * ⚠ calculatePropertyStats() は**非稼働のときだけ null** を入れる（稼働なら坪数 0 でも 0）。
     *   ビューも `@if($property->occupancy_rate !== null)` で判定しているので、
     *   **稼働で 0 の物件は画面に `0.0%` `0円` と出る**。
     * ⚠ 判定を truthy（`(bool) $p->{$field}`）に変えると、その物件が「—」扱いされて末尾へ飛ぶ。
     *   画面は `0.0%` なのに並び順だけ末尾 ＝ 避けたい食い違いそのもの（Bug #41 / #46）。
     *   実測: このテストが無いと 987 テスト全部が緑のまま通った。
     * ⚠ 設計書 §2.1 のとおり**実データは 16 件中 14 件がこの形**なので、本番で確実に目に見える。
     * ⚠ 既定 [1,2,3] / 降順 [1,3,2] / 昇順 [2,3,1] で 3 つとも食い違わせてある（設計書 §6.2-0）。
     */
    public function test_an_active_property_with_zero_income_is_not_pushed_to_the_end(): void
    {
        $high = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $zero = $this->makeProperty(2);                                            // 稼働・契約なし ＝ 0円
        $low = $this->makeProperty(3, units: 1, contracted: 1, rentEach: 100000);

        $user = $this->executive();

        // まず「null ではなく 0」であることを固定する（ここが崩れると検査が空振りする）
        $rows = $this->actingAs($user)->get(route('tenant.properties.index'))->viewData('properties');
        $this->assertSame(0, $rows->firstWhere('id', $zero->id)->rental_income, '稼働物件の賃料収入が 0 になっていない');
        $this->assertSame(0.0, (float) $rows->firstWhere('id', $zero->id)->occupancy_rate, '稼働物件の入居率が 0 になっていない');

        $asc = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'asc']));
        $this->assertSame(
            [$zero->id, $low->id, $high->id],
            $asc->viewData('properties')->pluck('id')->all(),
            '0 円の稼働物件が末尾へ飛んでいる（「—」と取り違えている）'
        );

        $desc = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame(
            [$high->id, $low->id, $zero->id],
            $desc->viewData('properties')->pluck('id')->all()
        );
    }

    /**
     * 入居率と賃料収入は別々の列として並ぶ（列の取り違えを検出する）。
     *
     * ⚠ **3 つの並びを全部食い違わせてある。** 既定 [1,2,3] / 入居率降順 [2,1,3] /
     *   賃料収入降順 [3,1,2]。どれか 2 つが揃うと、列を取り違えた実装でも緑になる。
     *   実測: 賃料収入降順を既定順と同じにすると、その半分が飾りになった。
     */
    public function test_occupancy_and_income_sort_by_different_columns(): void
    {
        // 入居率 50% / 賃料収入 200,000円（どちらも真ん中）
        $mid = $this->makeProperty(1, units: 2, contracted: 1, rentEach: 200000);
        // 入居率 100%（最大） / 賃料収入 20,000円（最小）
        $fullOccupancy = $this->makeProperty(2, units: 2, contracted: 2, rentEach: 10000);
        // 入居率 25%（最小） / 賃料収入 300,000円（最大）
        $topIncome = $this->makeProperty(3, units: 4, contracted: 1, rentEach: 300000);

        $user = $this->executive();

        $byOccupancy = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'occupancy', 'dir' => 'desc']));
        $this->assertSame(
            [$fullOccupancy->id, $mid->id, $topIncome->id],
            $byOccupancy->viewData('properties')->pluck('id')->all(),
            '入居率の降順になっていない'
        );

        $byIncome = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame(
            [$topIncome->id, $mid->id, $fullOccupancy->id],
            $byIncome->viewData('properties')->pluck('id')->all(),
            '賃料収入の降順になっていない（入居率と同じ並びなら列を取り違えている）'
        );
    }

    /**
     * 同点の中は既定順（code 昇順）。
     *
     * ⚠ **作成順（id 昇順）と code 順がわざと食い違うようにする。**
     *   揃えると第 2 キーを消しても同じ並びになり、変異が素通りする。
     * ⚠ 実データでも 16 件中 14 件が入居率 0.0% / 賃料収入 0 円で同点（設計書 §2.1）。
     */
    public function test_tied_properties_keep_the_default_order(): void
    {
        $c = $this->makeProperty(3);
        $a = $this->makeProperty(1);
        $b = $this->makeProperty(2);

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            Property::orderBy('id')->pluck('id')->all(),
            'id 順と既定順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));

        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $response->viewData('properties')->pluck('id')->all(),
            '同点の中が既定順（code 昇順）になっていない'
        );
    }

    /**
     * ページをまたいでも行が重複せず・消えず、後のページに大きい値が現れない。
     *
     * ⚠ **これが本件の中心。** 1 ページ目の 20 件が降順に並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（設計書 §3.1）。
     */
    public function test_paging_through_a_sorted_list_never_shows_a_larger_value_on_a_later_page(): void
    {
        $incomeById = [];

        // code の昇順（＝既定順）と賃料収入の降順がわざと逆向きになるようにする
        for ($i = 1; $i <= 25; $i++) {
            $property = $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
            $incomeById[$property->id] = $i * 10000;
        }

        $ids = $this->collectIdsAcrossPages(
            $this->executive(),
            route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc'])
        );

        $this->assertCount(25, $ids, 'ページ送りで行が消えている');
        $this->assertCount(25, array_unique($ids), 'ページ送りで行が重複している');
        $this->assertEqualsCanonicalizing(Property::pluck('id')->all(), $ids);

        $incomes = array_map(fn ($id) => $incomeById[$id], $ids);
        $sorted = $incomes;
        rsort($sorted);

        $this->assertSame($sorted, $incomes, 'ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）');
        $this->assertSame(250000, $incomes[0], '最大の賃料収入が 1 ページ目の先頭に来ていない');
        $this->assertSame(50000, $incomes[20], '2 ページ目の先頭が 1 ページ目の最小を下回っていない');
    }

    /**
     * 全件取得にしても物件 1 件あたりのクエリが増えないこと（N+1 の検出）。
     *
     * ⚠ 絶対本数ではなく「件数を増やしても本数が変わらないこと」を見る。
     *   本数を決め打ちすると、無関係な変更で落ちる脆いテストになる。
     * ⚠ **定数分の増加（常に +5 本など）は原理的に検出しない。** 意図的なトレードオフで、
     *   絶対本数を決め打ちすると無関係な変更で落ちる脆いテストになるため。
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_properties(): void
    {
        $user = $this->executive();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        for ($i = 1; $i <= 5; $i++) {
            $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
        }
        $queries = 0;
        $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))->assertOk();
        $withFive = $queries;

        for ($i = 6; $i <= 25; $i++) {
            $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
        }
        $queries = 0;
        $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))->assertOk();
        $withTwentyFive = $queries;

        $this->assertSame(
            $withFive,
            $withTwentyFive,
            "物件が増えるとクエリが増える（N+1）: 5 件で {$withFive} 本 / 25 件で {$withTwentyFive} 本"
        );
    }

    /**
     * 見出しを 3 回押す往復。既定 → 降順 → 昇順 → 既定。
     *
     * ⚠ 賃料収入の昇順と既定順（code 昇順）が**わざと食い違う**データにしてある。
     *   一致させると 2 回目と 3 回目の結果が同じになり、片方が壊れても緑になる。
     */
    public function test_clicking_the_income_header_three_times_cycles_back_to_the_default_order(): void
    {
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $c = $this->makeProperty(3, units: 1, contracted: 1, rentEach: 100000);

        $user = $this->executive();
        $default = [$a->id, $b->id, $c->id];

        // 1 回目: 既定 → 降順
        $html = $this->actingAs($user)->get(route('tenant.properties.index'))->getContent();
        $first = $this->actingAs($user)->get($this->sortLinkFor($html, '賃料収入'));
        $first->assertOk();
        $this->assertSame([$b->id, $a->id, $c->id], $first->viewData('properties')->pluck('id')->all());
        $this->assertSame('descending', $this->ariaSortFor($first->getContent(), '賃料収入'));

        // 2 回目: 降順 → 昇順
        $second = $this->actingAs($user)->get($this->sortLinkFor($first->getContent(), '賃料収入'));
        $second->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], $second->viewData('properties')->pluck('id')->all());
        $this->assertSame('ascending', $this->ariaSortFor($second->getContent(), '賃料収入'));

        // 3 回目: 昇順 → 既定順
        $thirdUrl = $this->sortLinkFor($second->getContent(), '賃料収入');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 巡目は並び替えを解除する');
        $third = $this->actingAs($user)->get($thirdUrl);
        $third->assertOk();
        $this->assertSame($default, $third->viewData('properties')->pluck('id')->all());
    }

    /** 入居率の見出しも自分の列で並び替わる */
    public function test_the_occupancy_header_sorts_by_occupancy(): void
    {
        $half = $this->makeProperty(1, units: 2, contracted: 1, rentEach: 100000);  // 50%
        $full = $this->makeProperty(2, units: 2, contracted: 2, rentEach: 10000);   // 100%
        $none = $this->makeProperty(3, units: 2, contracted: 0);                    // 0%

        $user = $this->executive();
        $html = $this->actingAs($user)->get(route('tenant.properties.index'))->getContent();

        $response = $this->actingAs($user)->get($this->sortLinkFor($html, '入居率'));

        $response->assertOk();
        $this->assertSame([$full->id, $half->id, $none->id], $response->viewData('properties')->pluck('id')->all());
    }

    /** 並び替え不可の列には矢印もリンクも出さない */
    public function test_non_sortable_headers_stay_plain(): void
    {
        $this->makeProperty(1);

        $html = $this->actingAs($this->executive())->get(route('tenant.properties.index'))->getContent();

        $this->assertStringContainsString('>所有者</th>', $html, '所有者の見出しが素の <th> でなくなっている');
        $this->assertStringContainsString('>稼働</th>', $html);
    }

    /**
     * aria-sort が**並び替え中の列だけ**に載ること、パディングが <a> 側にあること。
     *
     * ⚠ Task 4（部屋一覧）で、ページ全体を見る assertStringContainsString だけだと
     *   **3 列全部に descending を出す変異が緑のまま通る**ことを実測した。物件一覧にも同じ網を張る。
     * ⚠ **物件一覧は `link-class` を使う最初の画面**（部屋一覧は `link-style`）。
     *   この経路が黙って効かないと <th> も <a> も padding 0 で見出しが潰れるので、
     *   レスポンシブなパディングが本当に <a> へ載ったかを見る。
     */
    public function test_only_the_sorted_column_is_marked_and_the_padding_sits_on_the_link(): void
    {
        $this->makeProperty(1, units: 1, contracted: 1, rentEach: 100000);

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))
            ->getContent();

        $this->assertSame('descending', $this->ariaSortFor($html, '賃料収入'));
        $this->assertSame('none', $this->ariaSortFor($html, '入居率'), '並び替えていない列に aria-sort が載っている');
        $this->assertSame('none', $this->ariaSortFor($html, '所有者'), '並び替え不可の列に aria-sort が載っている');

        // ⚠ link-class は物件一覧が最初の利用者。効いていなければ見出しが潰れる
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="[^"]*px-4 py-3 lg:px-5 lg:py-3\.5/u',
            $html,
            'link-class のレスポンシブなパディングが <a> に載っていない'
        );
        // ⚠ **列ごとに見る。** ページ全体を見る正規表現だと、同じ値の <th> が 1 つでも
        //   残っていれば一致するので「一覧あたり 1 列」しか守れない（実測済み）。
        $this->assertSame('padding: 0; text-align: center;', $this->thStyleFor($html, '入居率'));
        $this->assertSame('padding: 0; text-align: center;', $this->thStyleFor($html, '賃料収入'));
    }

    /**
     * 並び替え中にフィルタを変えても並び順が消えない（設計書 §4.3-4）。
     *
     * ⚠ hidden があることを見るだけでは足りない。**画面が描画したフォームを解析して
     *   そのまま送り返す**（Bug #47）。フィルターフォームは GET なので
     *   fields をクエリ文字列に組み直して送る。
     */
    public function test_changing_a_filter_keeps_the_current_sort(): void
    {
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $inactive = $this->makeProperty(3, operationStatus: 'inactive');

        $user = $this->executive();

        $html = $this->actingAs($user)
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))
            ->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.properties.index') . '"');

        $this->assertSame('income', $form['fields']['sort'] ?? null, 'フィルターフォームが sort を持ち回していない');
        $this->assertSame('desc', $form['fields']['dir'] ?? null, 'フィルターフォームが dir を持ち回していない');

        // ブラウザと同じように、稼働状態だけ変えて送り返す
        $fields = $form['fields'];
        $fields['operation_status'] = 'active';

        $response = $this->actingAs($user)->get($form['action'] . '?' . http_build_query($fields));

        $response->assertOk();
        $this->assertSame(
            [$b->id, $a->id],
            $response->viewData('properties')->pluck('id')->all(),
            'フィルタを変えたら並び順が既定に戻った（賃料収入の降順のままであるべき）'
        );
        $this->assertNotContains($inactive->id, $response->viewData('properties')->pluck('id')->all());
    }

    /** 並び替えていないときは余計な hidden を出さない */
    public function test_no_sort_hidden_fields_when_not_sorting(): void
    {
        $this->makeProperty(1);

        $html = $this->actingAs($this->executive())->get(route('tenant.properties.index'))->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.properties.index') . '"');

        $this->assertArrayNotHasKey('sort', $form['fields']);
        $this->assertArrayNotHasKey('dir', $form['fields']);
    }

    /**
     * 「クリア」は並び順も初期化する（設計書 §4.3-4）。
     *
     * ⚠ サイドバー（layouts/partials/sidebar.blade.php の 62 / 225 / 339 行）が
     *   同じ bare URL を 3 箇所で出すので、素の href 一致は**確実に** false-pass する。
     *   ラベルで絞る sortLinkFor() を使うこと（Bug #47）。
     */
    public function test_the_clear_link_drops_the_sort(): void
    {
        $this->makeProperty(1);

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))
            ->getContent();

        $this->assertSame(
            route('tenant.properties.index'),
            $this->sortLinkFor($html, 'クリア'),
            'クリアがクエリ付きのリンクになっている（並び順が残ってしまう）'
        );
    }

    /**
     * 2 ページ目で見出しを押したら 1 ページ目へ戻る（設計書 §4.3-5）。
     *
     * ⚠ **部屋一覧の同名テストでは代替できない。** 物件一覧のページャは手組みで、
     *   'query' に page を含んだまま渡している。正しく動くのは LengthAwarePaginator::url() が
     *   array_merge($this->query, ['page' => $n]) と**正しい page を後ろに置く**からで、
     *   フレームワークの実装詳細に依存している。部屋一覧は withQueryString() なのでこの経路を通らない。
     */
    public function test_clicking_a_header_from_page_two_returns_to_page_one(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
        }

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get(route('tenant.properties.index'));
        $page2 = $this->actingAs($user)->get($page1->viewData('properties')->nextPageUrl());
        $page2->assertOk();
        $this->assertSame(2, $page2->viewData('properties')->currentPage());

        $url = $this->sortLinkFor($page2->getContent(), '賃料収入');

        $this->assertStringNotContainsString('page=', $url, '見出しリンクが page を持ち越している');
        $this->assertSame(1, $this->actingAs($user)->get($url)->viewData('properties')->currentPage());
    }

    /**
     * 絞り込み中に見出しを押しても、絞り込みが残る。
     *
     * ⚠ ListSortTest は Request::create() 経由なので **ConvertEmptyStringsToNull を通らない**
     *   （Bug #31 / #35 が名指しで警告している経路差）。実 HTTP の往復はここだけ。
     * ⚠ 既定 [1,2,3] / 賃料収入降順 [2,1,3] で食い違わせてある。
     */
    public function test_clicking_a_header_keeps_the_current_filter(): void
    {
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $c = $this->makeProperty(3, units: 1, contracted: 1, rentEach: 100000);
        $inactive = $this->makeProperty(4, operationStatus: 'inactive');

        $user = $this->executive();

        $html = $this->actingAs($user)
            ->get(route('tenant.properties.index', ['operation_status' => 'active']))
            ->getContent();

        $url = $this->sortLinkFor($html, '賃料収入');

        $this->assertStringContainsString('operation_status=active', $url, '見出しリンクが絞り込みを落としている');

        $response = $this->actingAs($user)->get($url);

        $response->assertOk();
        $ids = $response->viewData('properties')->pluck('id')->all();
        $this->assertSame([$b->id, $a->id, $c->id], $ids, '絞り込みを保ったまま賃料収入の降順になっていない');
        $this->assertNotContains($inactive->id, $ids, '絞り込みが効いていない');
    }
}
