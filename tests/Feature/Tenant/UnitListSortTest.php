<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * 部屋一覧の並び替え（設計書 §4.4 / §5.4 / §6）。
 *
 * ⚠ 「—」を末尾へ回すのは**画面に「—」と出る列だけ**。
 *   面積は末尾へ、家賃は「0円」と出るので 0 として並べる。**別々のテストで固定する**
 *   （期待する位置が正反対なので、1 本にまとめると片方の変異が素通りする）。
 */
class UnitListSortTest extends TestCase
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
     * ⚠ `properties.code` は UNIQUE。同じテストで 2 棟以上作るなら `$code` を必ず変えること
     *   （既定のまま 2 回呼ぶと、意味の分かりにくい制約違反で落ちる）。
     */
    private function makeProperty(string $code = 'T-S001'): Property
    {
        return Property::create([
            'code'             => $code,
            'name'             => '並び替えビル',
            'property_type'    => 'tenant',
            'department'       => 'tenant',
            'operation_status' => 'active',
            'address'          => '愛媛県松山市本町1-1',
        ]);
    }

    /**
     * 部屋を 1 つ作る。金額・面積は指定が無ければ既定値。
     *
     * ⚠ `display_name` は本番と形が違う（本番は `UnitController::generateDisplayName()` を
     *   通すので floor 3 / '301' は '3301' になる）。並び替えのテストは display_name を
     *   見ないので今は無害だが、**キーワード検索は display_name を like する**ので、
     *   検索を扱うテストを足すときはここを本番の形に合わせること。
     */
    private function makeUnit(Property $property, string $room, array $attrs = []): Unit
    {
        return Unit::create(array_merge([
            'property_id'      => $property->id,
            'floor'            => 1,
            'room_number'      => $room,
            'display_name'     => $room,
            'status'           => 'vacant',
            'area_tsubo'       => 20.00,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 200000,
        ], $attrs));
    }

    /** 月額合計の SQL 式と PHP アクセサが同じ値を出すこと（Bug #41） */
    public function test_the_monthly_total_sql_agrees_with_the_php_accessor(): void
    {
        $property = $this->makeProperty();
        $this->makeUnit($property, '101', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000]);
        $this->makeUnit($property, '102', ['rent' => 180000, 'common_fee' => 18000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $this->makeUnit($property, '103', ['rent' => 95000,  'common_fee' => 9000,  'garbage_fee' => 1500, 'pest_control_fee' => 700]);
        $this->makeUnit($property, '104', ['rent' => null,   'common_fee' => null,  'garbage_fee' => null, 'pest_control_fee' => null]);

        $fromSql = Unit::selectRaw('id, ' . Unit::MONTHLY_TOTAL_SQL . ' as total')->pluck('total', 'id')->all();

        $values = [];
        foreach (Unit::orderBy('id')->get() as $unit) {
            // ⚠ (int) キャストが NULL を 0 に潰すので、キャストの前に NULL でないことを見る。
            //   これが無いと COALESCE を全部外す変異が緑のまま通る（実測済み）。
            //   定数の docblock が「この式は NULL にならない」と断言しており、
            //   Task 3 はそれを根拠に null 判定句を書かないので、断言のほうを固定する。
            $this->assertNotNull($fromSql[$unit->id], 'COALESCE が外れて式が NULL になっている');
            $this->assertSame(
                $unit->monthly_total,
                (int) $fromSql[$unit->id],
                "部屋 {$unit->room_number} の月額合計が SQL 式と PHP アクセサで食い違う"
            );
            $values[] = $unit->monthly_total;
        }

        // ⚠ SQLite は綴りを間違えたカラム参照を**例外なく 0 で返す**（Bug #40）。
        //   値に分散が無いと「SQL を全部壊しても PHP と一致」で false-pass しうるので固定する。
        $this->assertGreaterThan(1, count(array_unique($values)), '月額合計に分散が無いデータでは検出力が出ない');
        $this->assertContains(315000, $values, '4 項目すべてを足していない（285000+25000+3000+2000）');
        $this->assertSame(0, $values[3], 'NULL ばかりの部屋は COALESCE で 0 になる');
    }

    /**
     * ページ送りのリンクを実際に辿って、全ページの部屋 ID を順に集める。
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

            $paginator = $response->viewData('units');
            foreach ($paginator as $unit) {
                $ids[] = $unit->id;
            }

            $url = $paginator->nextPageUrl();

            $this->assertLessThan(20, ++$guard, 'ページ送りが終わらない');
        }

        return $ids;
    }

    /** 並び替え指定が無ければ、今までと同じ順（property_id → floor → room_number） */
    public function test_the_default_order_is_unchanged(): void
    {
        $property = $this->makeProperty();
        $c = $this->makeUnit($property, '301', ['floor' => 3]);
        $a = $this->makeUnit($property, '101', ['floor' => 1]);
        $b = $this->makeUnit($property, '201', ['floor' => 2]);

        $response = $this->actingAs($this->executive())->get(route('tenant.units.index'));

        $response->assertOk();
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $response->viewData('units')->pluck('id')->all()
        );
    }

    /** 不正な sort / dir は 500 にせず既定順へ落ちる */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $property = $this->makeProperty();
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 300000]);
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 100000]);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => 200000]);

        $expected = [$a->id, $b->id, $c->id];
        $user = $this->executive();

        foreach ([
            '?sort=name',            // 許可リストに無い
            '?sort[]=rent',          // 配列で来る
            '?sort=%3Cscript%3E',    // 手入力・古いブックマーク
            '?sort=',                // 空
            '?sort=rent&dir=up',     // dir だけ不正 → 既定の降順になる（下で別途確認）
        ] as $queryString) {
            $response = $this->actingAs($user)->get(route('tenant.units.index') . $queryString);
            $response->assertOk();

            if ($queryString === '?sort=rent&dir=up') {
                continue;   // sort は妥当なので既定順ではなく「降順」になる
            }

            $this->assertSame(
                $expected,
                $response->viewData('units')->pluck('id')->all(),
                "{$queryString} で既定順に落ちていない"
            );
        }

        // dir だけ不正なら降順（設計書 §4.2: 1 回目は降順）
        $response = $this->actingAs($user)->get(route('tenant.units.index') . '?sort=rent&dir=up');
        $this->assertSame([$c->id, $b->id, $a->id], $response->viewData('units')->pluck('id')->all());
    }

    /** 面積は画面で「—」と出るので、昇順でも降順でも末尾（設計書 §4.4） */
    public function test_units_without_an_area_sort_last_in_both_directions(): void
    {
        $property = $this->makeProperty();
        $small   = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 10.00]);
        $noArea  = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => null]);
        $large   = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 30.00]);

        $user = $this->executive();

        $desc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'desc']));
        $this->assertSame([$large->id, $small->id, $noArea->id], $desc->viewData('units')->pluck('id')->all());

        $asc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'asc']));
        $this->assertSame([$small->id, $large->id, $noArea->id], $asc->viewData('units')->pluck('id')->all());
    }

    /**
     * 家賃 NULL は画面に「0円」と出るので **0 として並べる**（末尾へ飛ばさない）。
     *
     * ⚠ 上の面積のテストと**期待する位置が正反対**。1 本にまとめてはいけない。
     */
    public function test_units_with_a_null_rent_sort_as_zero_not_last(): void
    {
        $property = $this->makeProperty();
        $high    = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 300000]);
        $nullish = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => null]);
        $low     = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 100000]);

        $user = $this->executive();

        // ⚠ 0 ではなく NULL が入っていることを先に固定する。
        //   0 で作ると NULL の経路を一度も通らず、この検査が空振りして緑になる（設計書 §6.2-5）
        $this->assertNull($nullish->fresh()->rent, '家賃が NULL のデータになっていない');

        $asc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'rent', 'dir' => 'asc']));
        $this->assertSame(
            [$nullish->id, $low->id, $high->id],
            $asc->viewData('units')->pluck('id')->all(),
            'NULL の家賃が 0 として先頭に来ていない（末尾へ飛ばしている）'
        );

        $desc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'rent', 'dir' => 'desc']));
        $this->assertSame([$high->id, $low->id, $nullish->id], $desc->viewData('units')->pluck('id')->all());
    }

    /**
     * 面積 0 は「—」ではないので、末尾へ飛ばさず 0 として並ぶ（設計書 §4.4）。
     *
     * ⚠ **これは「表示と食い違う既知の穴」ではない。** area_tsubo は decimal:2 キャストなので
     *   0 は**文字列 '0.00' で返り、PHP では truthy**（falsy な文字列は '' と '0' だけ）。
     *   実測: area_tsubo = '0.00' (string) truthy=YES → 画面には「0.00坪」と出る。
     *   つまり `(units.area_tsubo IS NULL)` はビューの `@if($unit->area_tsubo)` と完全に一致する。
     * ⚠ ここを「0 も末尾へ」に変えると、**逆に表示と食い違う**。それを止めるためのテスト。
     */
    public function test_a_zero_area_is_not_pushed_to_the_end(): void
    {
        $property = $this->makeProperty();
        $zero   = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 0]);
        $noArea = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => null]);
        $large  = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 30.00]);

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'asc']));

        $this->assertSame(
            [$zero->id, $large->id, $noArea->id],
            $response->viewData('units')->pluck('id')->all(),
            '0 の面積は末尾へ回さない（NULL だけを回す）'
        );
    }

    /**
     * 同点の中は既定順。
     *
     * ⚠ **id の昇順と既定順（floor 昇順）がわざと食い違う順で作る。**
     *   同じ順で作ると、第 2 キーを消しても SQLite が id 順＝既定順で返してしまい
     *   変異が素通りする（Bug #52 の「真ん中の行が落ちるデータで書く」と同じ理屈）。
     */
    public function test_tied_rows_keep_the_default_order(): void
    {
        $property = $this->makeProperty();
        $third  = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 100000]);
        $first  = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 100000]);
        $second = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => 100000]);

        $this->assertSame(
            [$third->id, $first->id, $second->id],
            Unit::orderBy('id')->pluck('id')->all(),
            'id 順と既定順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.units.index', ['sort' => 'rent', 'dir' => 'desc']));

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $response->viewData('units')->pluck('id')->all(),
            '同点の中が既定順になっていない'
        );
    }

    /**
     * ページをまたいでも行が重複せず・消えず・全体を通して降順であること。
     *
     * ⚠ **1 ページ目だけでは測れない。** 1 ページ目の 20 件が降順に並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（設計書 §3.1）。
     */
    public function test_paging_through_a_sorted_list_yields_every_unit_exactly_once(): void
    {
        $property = $this->makeProperty();
        $rentById = [];

        // 25 件（＝ 2 ページ）。家賃は作成順と逆向きに増やすので、
        // 「ページを切ってから並べ替える」実装では 2 ページ目に大きい値が現れる
        for ($i = 1; $i <= 25; $i++) {
            $unit = $this->makeUnit($property, sprintf('%03d', $i), [
                'floor' => $i,
                'rent'  => $i * 10000,
            ]);
            $rentById[$unit->id] = $i * 10000;
        }

        $ids = $this->collectIdsAcrossPages(
            $this->executive(),
            route('tenant.units.index', ['sort' => 'rent', 'dir' => 'desc'])
        );

        $this->assertCount(25, $ids, 'ページ送りで行が消えている');
        $this->assertCount(25, array_unique($ids), 'ページ送りで行が重複している');
        $this->assertEqualsCanonicalizing(Unit::pluck('id')->all(), $ids);

        $rents = array_map(fn ($id) => $rentById[$id], $ids);
        $sorted = $rents;
        rsort($sorted);
        $this->assertSame($sorted, $rents, 'ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）');
        $this->assertSame(250000, $rents[0], '最大の家賃が 1 ページ目の先頭に来ていない');
    }

    /**
     * 見出しを 3 回押す往復。既定 → 降順 → 昇順 → 既定。
     *
     * ⚠ 面積の昇順と既定順（floor 昇順）が**わざと食い違う**データにしてある。
     *   一致させると 2 回目と 3 回目の結果が同じになり、片方が壊れても緑になる。
     */
    public function test_clicking_the_area_header_three_times_cycles_back_to_the_default_order(): void
    {
        $property = $this->makeProperty();
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 20.00]);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => 30.00]);
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 10.00]);

        $user    = $this->executive();
        $default = [$a->id, $b->id, $c->id];

        $this->assertNotSame($default, [$c->id, $a->id, $b->id], '既定順と面積昇順が同じデータになっている');

        // 1 回目: 既定 → 降順
        $html  = $this->actingAs($user)->get(route('tenant.units.index'))->getContent();
        $first = $this->actingAs($user)->get($this->sortLinkFor($html, '面積'));
        $first->assertOk();
        $this->assertSame([$b->id, $a->id, $c->id], $first->viewData('units')->pluck('id')->all());
        $this->assertStringContainsString('aria-sort="descending"', $first->getContent());

        // 2 回目: 降順 → 昇順
        $second = $this->actingAs($user)->get($this->sortLinkFor($first->getContent(), '面積'));
        $second->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], $second->viewData('units')->pluck('id')->all());
        $this->assertStringContainsString('aria-sort="ascending"', $second->getContent());

        // 3 回目: 昇順 → 既定順
        $thirdUrl = $this->sortLinkFor($second->getContent(), '面積');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 巡目は並び替えを解除する');
        $third = $this->actingAs($user)->get($thirdUrl);
        $third->assertOk();
        $this->assertSame($default, $third->viewData('units')->pluck('id')->all());
    }

    /** 家賃と月額合計の見出しもそれぞれの列で並び替わる */
    public function test_the_rent_and_monthly_headers_sort_their_own_columns(): void
    {
        $property = $this->makeProperty();
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 100000, 'common_fee' => 50000]);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => 300000, 'common_fee' => 0]);
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 200000, 'common_fee' => 150000]);

        $user = $this->executive();
        $html = $this->actingAs($user)->get(route('tenant.units.index'))->getContent();

        // 家賃の降順: 300000 > 200000 > 100000
        $byRent = $this->actingAs($user)->get($this->sortLinkFor($html, '家賃'));
        $this->assertSame([$b->id, $c->id, $a->id], $byRent->viewData('units')->pluck('id')->all());

        // 月額合計の降順: c(353000) > b(303000) > a(153000)
        // ⚠ **家賃の順（b, c, a）とわざと食い違わせてある。**
        //   揃えると 'monthly' の式を 'rent' の式に変えても同じ順になり、変異が素通りする。
        //   実測: c の common_fee を 100000（= 合計 303000、b と同点）にすると
        //   monthly を units.rent に差し替えても順が変わらず、このテストは緑のまま通った。
        $byMonthly = $this->actingAs($user)->get($this->sortLinkFor($html, '月額合計'));
        $this->assertSame(
            [$c->id, $b->id, $a->id],
            $byMonthly->viewData('units')->pluck('id')->all(),
            '月額合計の降順になっていない（家賃の順と同じなら式を取り違えている）'
        );
        $this->assertSame(
            [353000, 303000, 153000],
            $byMonthly->viewData('units')->map(fn ($unit) => $unit->monthly_total)->all(),
            '月額合計の実値が想定と違う'
        );
    }

    /** 並び替え不可の列には矢印もリンクも出さない */
    public function test_non_sortable_headers_stay_plain(): void
    {
        $this->makeUnit($this->makeProperty(), '101');

        $html = $this->actingAs($this->executive())->get(route('tenant.units.index'))->getContent();

        $this->assertStringContainsString('>敷金</th>', $html, '敷金の見出しが素の <th> でなくなっている');
        $this->assertStringContainsString('>共益費</th>', $html);
    }

    /**
     * aria-sort が**並び替え中の列だけ**に載ること、パディングが <a> 側にあること。
     *
     * ⚠ どちらも実測で「緑のまま通る変異」があったので足した:
     *   ① aria-sort を $sort?->direction 由来にすると 3 列全部が descending になるが、
     *      ページ全体を見る assertStringContainsString では通ってしまう
     *   ② パディングを <th> に戻すと「文字の上しか押せない」（Bug #43）が、
     *      当たり判定そのものは HTML では測れない。**どちらに載っているか**なら測れる
     */
    public function test_only_the_sorted_column_is_marked_and_the_padding_sits_on_the_link(): void
    {
        $this->makeUnit($this->makeProperty(), '101');

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'desc']))
            ->getContent();

        $this->assertSame('descending', $this->ariaSortFor($html, '面積'));
        $this->assertSame('none', $this->ariaSortFor($html, '家賃'), '並び替えていない列に aria-sort が載っている');
        $this->assertSame('none', $this->ariaSortFor($html, '月額合計'));
        $this->assertSame('none', $this->ariaSortFor($html, '敷金'), '並び替え不可の列に aria-sort が載っている');

        // パディングは <th> ではなく中の <a> に載る（<th> 側に残すと文字の上しか押せない）
        $this->assertMatchesRegularExpression(
            '/<th\b[^>]*style="padding: 0;/u',
            $html,
            '見出しセルのパディングが 0 になっていない（<a> 側へ移していない）'
        );
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*style="[^"]*padding: 14px 20px;/u',
            $html,
            '見出しリンクにパディングが載っていない'
        );
    }

    /** 2 ページ目で見出しを押したら 1 ページ目へ戻る（設計書 §4.3-5） */
    public function test_clicking_a_header_from_page_two_returns_to_page_one(): void
    {
        $property = $this->makeProperty();
        for ($i = 1; $i <= 25; $i++) {
            $this->makeUnit($property, sprintf('%03d', $i), ['floor' => $i, 'rent' => $i * 10000]);
        }

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get(route('tenant.units.index'));
        $page2 = $this->actingAs($user)->get($page1->viewData('units')->nextPageUrl());
        $page2->assertOk();
        $this->assertSame(2, $page2->viewData('units')->currentPage());

        $url = $this->sortLinkFor($page2->getContent(), '家賃');

        $this->assertStringNotContainsString('page=', $url, '見出しリンクが page を持ち越している');
        $this->assertSame(1, $this->actingAs($user)->get($url)->viewData('units')->currentPage());
    }
}
