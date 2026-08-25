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
}
