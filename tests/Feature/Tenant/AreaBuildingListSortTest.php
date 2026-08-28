<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;

/**
 * 周辺ビル調査の並び替え（設計書 2026-08-28 §4）。
 *
 * ⚠ **期待順は既定順（ビル名の昇順）と必ず食い違わせる。** 揃えると式を取り違えても
 *   緑になる（前設計書で実測済みの罠）。下の seedFourBuildings() は 7 列すべての降順が
 *   互いに違う並びになるように作ってある。
 *
 * ⚠ テストは SQLite（BINARY 照合）で走る。**大文字小文字だけ／ひらがなカタカナだけが違う
 *   名前を使わないこと**（MySQL は同一視し SQLite は区別するので本番と順が変わる。設計書 §4.4）。
 */
class AreaBuildingListSortTest extends AreaBuildingTestCase
{
    use ParsesSortLinks;
    use RefreshDatabase;

    /**
     * 7 列すべてで既定順と食い違う並びになるデータ。
     *
     * | 棟 | 総階数 | 営業 | 空き | 不明 | 空室率 | 入居率 | 最終調査 |
     * |----|-------|------|------|------|--------|--------|----------|
     * | A  |   3   |  5   |  3   |  5   | 61.5%  | 38.5%  | 2026-05  |
     * | B  |   8   |  2   |  7   |  1   | 80.0%  | 20.0%  | 2026-08  |
     * | C  |  12   |  9   |  0   |  2   | 18.1%  | 81.9%  | 2026-06  |
     * | D  |   6   |  7   |  12  |  3   | 68.1%  | 31.9%  | 2026-07  |
     *
     * ⚠ 空室率 = (空き + 不明) ÷ 総数 の 1/10% 単位切り捨て（VacancyRate::percent）。
     *   入居率はその裏返しで、和は必ず 100.0%（Bug #46）。
     */
    private function seedFourBuildings(): void
    {
        $a = $this->makeBuilding('Aビル', ['total_floors' => 3]);
        $this->makeSurvey($a, '2026-05-01', 5, 3, 5);

        $b = $this->makeBuilding('Bビル', ['total_floors' => 8]);
        $this->makeSurvey($b, '2026-08-01', 2, 7, 1);

        $c = $this->makeBuilding('Cビル', ['total_floors' => 12]);
        $this->makeSurvey($c, '2026-06-01', 9, 0, 2);

        $d = $this->makeBuilding('Dビル', ['total_floors' => 6]);
        $this->makeSurvey($d, '2026-07-01', 7, 12, 3);
    }

    /** 7 列それぞれが**自分の列の値**で並ぶこと（列を取り違える変異を検出する） */
    public function test_every_sortable_column_sorts_by_its_own_values(): void
    {
        $this->seedFourBuildings();
        $staff = $this->staff();

        $expected = [
            'floors'    => ['Cビル', 'Bビル', 'Dビル', 'Aビル'],
            'operating' => ['Cビル', 'Dビル', 'Aビル', 'Bビル'],
            'vacant'    => ['Dビル', 'Bビル', 'Aビル', 'Cビル'],
            'unknown'   => ['Aビル', 'Dビル', 'Cビル', 'Bビル'],
            'vacancy'   => ['Bビル', 'Dビル', 'Aビル', 'Cビル'],
            'occupancy' => ['Cビル', 'Aビル', 'Dビル', 'Bビル'],
            'month'     => ['Bビル', 'Dビル', 'Cビル', 'Aビル'],
        ];

        // ⚠ データが「検出力のある形」であること自体を先に固定する
        $default = ['Aビル', 'Bビル', 'Cビル', 'Dビル'];
        $this->assertSame($default, $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings')), '既定順が名前の昇順でない');
        foreach ($expected as $key => $names) {
            $this->assertNotSame($default, $names, "{$key} の期待順が既定順と同じデータになっている（並べ替えを消しても緑になる）");
        }
        $signatures = array_map(fn (array $names) => implode(',', $names), $expected);
        $this->assertCount(7, array_unique($signatures), '7 列の期待順に重複がある（列の取り違えを検出できない）');

        foreach ($expected as $key => $names) {
            $desc = $this->actingAs($staff)->get("/tenant/area-buildings?sort={$key}&dir=desc");
            $desc->assertOk();
            $this->assertSame($names, $this->listedNames($desc), "{$key} の降順が違う");

            $asc = $this->actingAs($staff)->get("/tenant/area-buildings?sort={$key}&dir=asc");
            $asc->assertOk();
            $this->assertSame(array_reverse($names), $this->listedNames($asc), "{$key} の昇順が降順の逆になっていない");
        }
    }

    /**
     * 入居率の降順と空室率の昇順は**完全に同じ並び**（設計書 §4.3）。
     *
     * ⚠ 入居率を VacancyRate::occupancyPercent() で別に計算して並べると、
     *   画面に並ぶ 2 つの数字と並び順が食い違う余地ができる（Bug #46）。
     *   実装は空室率の符号を反転しているので、これは**構造として**成り立つ。
     *
     * ⚠ **調査なしの棟の名前は '0号ビル' でなければならない。** 'Eビル' だったときは、
     *   applySort() を丸ごと無効化して `return $rows->values();` にしても
     *   このテストは緑のまま通っていた（実測）。理由は 2 つ:
     *   ① `?sort=occupancy&dir=desc` と `?sort=vacancy&dir=asc` がどちらも既定順へ
     *      フォールバックするだけなので、assertSame() は実質「同じ結果を自分自身と比較」
     *      していただけだった。
     *   ② 'Eビル' は A〜E の中で名前が最後なので、既定順（ビル名の昇順）でも自然に
     *      末尾に来ており、「率が「—」の棟が末尾に来る」という本来検証したい性質を
     *      一度も運動させていなかった。
     *   '0号ビル' は SQLite の BINARY 照合で '0'（0x30）が 'A'（0x41）より前に来るため、
     *   既定順では先頭に来る。それでも実装が正しければ調査なし（rate が null）は
     *   partition() で末尾に回されるので、この棟が最終的に末尾へ来ることこそが
     *   「並び替えが効いている」ことの証拠になる。**A〜D と体裁を揃えたくて 'Eビル' へ
     *   戻すと、揃えた瞬間にこのテストは何も検出しなくなる。**
     */
    public function test_the_occupancy_order_is_exactly_the_reverse_of_the_vacancy_order(): void
    {
        $this->seedFourBuildings();
        $this->makeBuilding('0号ビル');   // 調査なし＝率は「—」

        $staff = $this->staff();

        $byOccupancyDesc = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=occupancy&dir=desc'));
        $byVacancyAsc    = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=vacancy&dir=asc'));

        $this->assertCount(5, $byOccupancyDesc);
        $this->assertSame($byVacancyAsc, $byOccupancyDesc, '入居率と空室率で並びが食い違う（片方を別計算で出している。Bug #46）');
        $this->assertSame('0号ビル', end($byOccupancyDesc), '率が「—」の棟が末尾に来ていない');
    }

    /**
     * 「—」は昇順でも降順でも末尾（前設計書 §4.3-2 / 設計書 §4.2）。
     *
     * ⚠ **「—」になる条件は列ごとに違う。** A は総階数だけ空（調査はある）、
     *   D は調査回がまるごと無い（総階数はある）。**同じ棟が列によって末尾だったり
     *   普通に並んだりする**のが正しい（設計書 §2.2）。
     * ⚠ `[null 判定, 値]` の複合キーで書くと、**向きでフラグを反転しないと末尾に行かない**。
     *   実装は partition で分けて連結している。
     */
    public function test_blank_values_stay_at_the_end_in_both_directions(): void
    {
        $a = $this->makeBuilding('Aビル');                          // 総階数 null
        $this->makeSurvey($a, '2026-08-01', 3, 1, 0);
        $b = $this->makeBuilding('Bビル', ['total_floors' => 4]);
        $this->makeSurvey($b, '2026-06-01', 4, 1, 0);
        $c = $this->makeBuilding('Cビル', ['total_floors' => 9]);
        $this->makeSurvey($c, '2026-07-01', 1, 9, 0);
        $this->makeBuilding('Dビル', ['total_floors' => 7]);          // 調査回なし

        $staff = $this->staff();
        $get = fn (string $q) => $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?' . $q));

        // 総階数: A だけが「—」
        $this->assertSame(['Cビル', 'Dビル', 'Bビル', 'Aビル'], $get('sort=floors&dir=desc'));
        $this->assertSame(['Bビル', 'Dビル', 'Cビル', 'Aビル'], $get('sort=floors&dir=asc'));

        // 営業: D だけが「—」（A は 3 で普通に並ぶ）
        $this->assertSame(['Bビル', 'Aビル', 'Cビル', 'Dビル'], $get('sort=operating&dir=desc'));
        $this->assertSame(['Cビル', 'Aビル', 'Bビル', 'Dビル'], $get('sort=operating&dir=asc'));

        // 最終調査: D だけが「—」
        $this->assertSame(['Aビル', 'Cビル', 'Bビル', 'Dビル'], $get('sort=month&dir=desc'));
        $this->assertSame(['Bビル', 'Cビル', 'Aビル', 'Dビル'], $get('sort=month&dir=asc'));
    }

    /**
     * 「—」が複数あるとき、末尾ブロックの**内部の並び**も名前の昇順（既定順）のまま。
     *
     * ⚠ 直前の test_blank_values_stay_at_the_end_in_both_directions() は列ごとに
     *   ちょうど 1 棟しか「—」を作らないため、末尾ブロックの**内部の順序**は
     *   一度も運動していない（applySort() の `concat($blank)` を
     *   `concat($blank->reverse())` に変えても、あのテストだけでは全テスト緑のまま通る）。
     * ⚠ **id 順と名前順をわざと食い違わせる**（test_tied_rows_keep_the_building_name_order
     *   と同じ理屈）。3 棟とも total_floors が null（未調査）なので、'floors' で並べると
     *   3 棟とも partition() の「—」側に落ち、「値あり」側は空になる —— つまりこのテストが
     *   確かめているのは「—」ブロック**内部**の順序だけで、「値あり」側の並べ替えとは無関係。
     */
    public function test_multiple_blank_rows_keep_the_building_name_order_among_themselves(): void
    {
        $this->makeBuilding('Zビル');   // total_floors null
        $this->makeBuilding('Aビル');   // total_floors null
        $this->makeBuilding('Mビル');   // total_floors null

        $this->assertSame(
            ['Zビル', 'Aビル', 'Mビル'],
            AreaBuilding::orderBy('id')->pluck('name')->all(),
            'id 順と名前順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $staff    = $this->staff();
        $expected = ['Aビル', 'Mビル', 'Zビル'];   // 名前の昇順（既定順）

        $desc = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc'));
        $asc  = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=asc'));

        $this->assertSame($expected, $desc, '降順での「—」ブロック内部の並びが名前の昇順になっていない');
        $this->assertSame($expected, $asc, '昇順での「—」ブロック内部の並びが名前の昇順になっていない');
    }

    /**
     * 「調査回はあるが総区画 0」の棟は、**率でだけ**末尾（設計書 §2.2 / §8.1-4）。
     *
     * ⚠ 画面表示との一致がこのテストの本体。営業・空き・不明は「0」と出るので 0 として並び、
     *   入居率・空室率は「—」と出るので末尾へ回る。**揃える相手は NULL ではなく画面の表示**。
     */
    public function test_a_surveyed_building_with_no_units_is_blank_for_the_rates_only(): void
    {
        $zero = $this->makeBuilding('Cゼロ区画', ['total_floors' => 5]);
        $this->makeSurvey($zero, '2026-08-01', 0, 0, 0);           // 総区画 0 → 率だけ null
        $low = $this->makeBuilding('Aビル', ['total_floors' => 2]);
        $this->makeSurvey($low, '2026-06-01', 1, 9, 0);            // 空室率 90.0%
        $high = $this->makeBuilding('Bビル', ['total_floors' => 3]);
        $this->makeSurvey($high, '2026-07-01', 9, 1, 0);           // 空室率 10.0%

        $staff = $this->staff();

        // 画面の表示: 営業/空き/不明は「0」、入居率・空室率は「—」
        $row = collect($this->actingAs($staff)->get('/tenant/area-buildings')->viewData('rows')->items())
            ->first(fn (array $r) => $r['building']->name === 'Cゼロ区画');
        $this->assertSame(0, $row['operating'], '営業が 0 でなく null になっている（画面は「0」と出る）');
        $this->assertSame('—', $row['occupancy_label']);
        $this->assertSame('—', $row['rate_label']);

        $get = fn (string $q) => $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?' . $q));

        // 率では末尾（画面が「—」なので）
        $this->assertSame(['Aビル', 'Bビル', 'Cゼロ区画'], $get('sort=vacancy&dir=desc'));
        // 営業では 0 として普通に並ぶ。**昇順で先頭**（末尾へ回す実装なら最後に来る）
        $this->assertSame(['Cゼロ区画', 'Aビル', 'Bビル'], $get('sort=operating&dir=asc'));
        // 最終調査は実在するので普通に並ぶ
        $this->assertSame(['Cゼロ区画', 'Bビル', 'Aビル'], $get('sort=month&dir=desc'));
    }

    /**
     * 同点の中は既定順（＝ビル名の昇順）。
     *
     * ⚠ **id 順と名前順をわざと食い違わせる。** 揃えると、既定順への安定ソート依存を壊す変異が
     *   SQLite の返す id 順に救われて素通りする（Bug #52 の「真ん中の行が落ちるデータで書く」と
     *   同じ理屈）。これが無いと同点の行がページをまたいで重複／消失する（前設計書 §4.3-3）。
     */
    public function test_tied_rows_keep_the_building_name_order(): void
    {
        $this->makeBuilding('Zビル', ['total_floors' => 5]);
        $this->makeBuilding('Aビル', ['total_floors' => 5]);
        $this->makeBuilding('Mビル', ['total_floors' => 9]);

        $this->assertSame(
            ['Zビル', 'Aビル', 'Mビル'],
            AreaBuilding::orderBy('id')->pluck('name')->all(),
            'id 順と名前順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $this->assertSame(
            ['Mビル', 'Aビル', 'Zビル'],
            $this->listedNames($this->actingAs($this->staff())->get('/tenant/area-buildings?sort=floors&dir=desc'))
        );
    }

    /**
     * 旧既定順（空室率の降順・未調査は末尾）が**失われていない**こと。
     *
     * ⚠ Task 5 の test_the_default_order_is_the_building_name_ascending と**対で**意味を持つ。
     *   片方だけだと「変えた」と「壊した」が区別できない（設計書 §8.1.1）。
     * ⚠ データは既定順テストと同一。
     */
    public function test_the_old_default_order_is_still_reachable(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);
        $this->makeSurvey($this->makeBuilding('え率0'), '2026-08-01', 8, 0);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?sort=vacancy&dir=desc');

        $this->assertSame(['う率50', 'い率10', 'え率0', 'あ未調査'], $this->listedNames($response));
    }

    /**
     * 絞り込みと並び替えが共存し、ページをまたいでも全体が降順であること。
     *
     * ⚠ **1 ページ目だけでは測れない。** 1 ページ目の 20 件が降順に並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（前設計書 §3.1）。
     * ⚠ `?page=2` を自分で組み立てない。ページャの nextPageUrl() を辿ること（Bug #31）。
     */
    public function test_sorting_survives_filters_and_paging(): void
    {
        // 総階数は作成順と同じ向きに増やす。名前は 対象01…対象25 なので
        // **総階数の降順は名前の降順**＝既定順の逆になり、並べ替えを消すと必ず落ちる
        for ($i = 1; $i <= 25; $i++) {
            $b = $this->makeBuilding(sprintf('対象%02d', $i), ['total_floors' => $i]);
            $this->makeSurvey($b, '2026-08-01', 5, 5, 0);   // 空室率 50.0% → occupancy=under75 に入る
        }
        $out = $this->makeBuilding('対象外', ['total_floors' => 99]);
        $this->makeSurvey($out, '2026-08-01', 10, 0, 0);    // 満室 → under75 では外れる

        $staff = $this->staff();
        $url   = '/tenant/area-buildings?occupancy=under75&sort=floors&dir=desc';
        $names = [];
        $guard = 0;

        while ($url !== null) {
            $response = $this->actingAs($staff)->get($url);
            $response->assertOk();
            $names = array_merge($names, $this->listedNames($response));
            $url = $response->viewData('rows')->nextPageUrl();
            $this->assertLessThan(10, ++$guard, 'ページ送りが終わらない');
        }

        $this->assertCount(25, $names, '絞り込みが効いていない、または行が消えている');
        $this->assertCount(25, array_unique($names), 'ページ送りで行が重複している');
        $this->assertNotContains('対象外', $names, '並び替えで絞り込みが外れている');
        $this->assertSame('対象25', $names[0], '総階数の降順になっていない');
        $this->assertSame('対象01', end($names), 'ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）');
    }

    /** 不正な sort / dir は 500 にせず既定順へ落ちる（Bug #31） */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $this->seedFourBuildings();
        $default = ['Aビル', 'Bビル', 'Cビル', 'Dビル'];
        $staff = $this->staff();

        foreach ([
            '?sort=name',           // 許可リストに無い（ビル名は対象外。設計書 §4.1 / §10）
            '?sort[]=floors',       // 配列で来る
            '?sort=%3Cscript%3E',   // 手入力・古いブックマーク
            '?sort=',               // 空
        ] as $queryString) {
            $response = $this->actingAs($staff)->get('/tenant/area-buildings' . $queryString);
            $response->assertOk();
            $this->assertSame($default, $this->listedNames($response), "{$queryString} で既定順に落ちていない");
        }

        // dir だけ不正なら降順（前設計書 §4.2: 1 回目は降順）
        $response = $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=up');
        $this->assertSame(['Cビル', 'Bビル', 'Dビル', 'Aビル'], $this->listedNames($response));
    }

    /**
     * 見出しを 3 回押す往復。既定 → 降順 → 昇順 → 既定（前設計書 §4.2）。
     *
     * ⚠ **URL を自分で組み立てない。** 画面が描画した href をそのまま辿ること。
     *   組み立てると、リンクが壊れていても sort が付いた状態で届くので**必ず緑**になる（Bug #31）。
     * ⚠ 入居率の昇順と既定順（名前の昇順）が**わざと食い違う**データ。
     *   一致させると 2 回目と 3 回目の結果が同じになり、片方が壊れても緑になる。
     */
    public function test_clicking_the_occupancy_header_three_times_cycles_back_to_the_default_order(): void
    {
        $this->seedFourBuildings();

        $staff   = $this->staff();
        $default = ['Aビル', 'Bビル', 'Cビル', 'Dビル'];

        $html  = $this->actingAs($staff)->get('/tenant/area-buildings')->getContent();
        $first = $this->actingAs($staff)->get($this->sortLinkFor($html, '入居率'));
        $first->assertOk();
        $this->assertSame(['Cビル', 'Aビル', 'Dビル', 'Bビル'], $this->listedNames($first), '1 回目が入居率の降順でない');
        $this->assertSame('descending', $this->ariaSortFor($first->getContent(), '入居率'));

        $second = $this->actingAs($staff)->get($this->sortLinkFor($first->getContent(), '入居率'));
        $second->assertOk();
        $this->assertSame(['Bビル', 'Dビル', 'Aビル', 'Cビル'], $this->listedNames($second), '2 回目が入居率の昇順でない');
        $this->assertSame('ascending', $this->ariaSortFor($second->getContent(), '入居率'));

        $thirdUrl = $this->sortLinkFor($second->getContent(), '入居率');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 巡目は並び替えを解除する');
        $third = $this->actingAs($staff)->get($thirdUrl);
        $third->assertOk();
        $this->assertSame($default, $this->listedNames($third));
    }

    /**
     * 7 列すべてに並び替えリンクがあり、aria-sort は**並び替え中の列だけ**に載ること。
     *
     * ⚠ ページ全体に対する assertStringContainsString('aria-sort="descending"') では
     *   **全列に descending を出す変異が緑のまま通る**（ParsesSortLinks の docblock に実測済み）。
     */
    public function test_all_seven_headers_link_and_only_the_sorted_one_is_marked(): void
    {
        $this->seedFourBuildings();

        // ⚠ dir=asc（3 回目クリック＝解除の直前）だと、最終調査自身の次クリック先は
        //   ListSort::next() の仕様により sort= を持たない解除リンクになる
        //   （test_clicking_the_occupancy_header_three_times_cycles_back_to_the_default_order の
        //   $thirdUrl と同じ挙動）。ここでは「7 列すべてがまだ次の並び替えへ進めるリンクを
        //   持つ」ことを見たいので、まだ 1 回しか押していない dir=desc を使う。
        $html = $this->actingAs($this->staff())
            ->get('/tenant/area-buildings?sort=month&dir=desc')
            ->getContent();

        foreach (['総階数', '営業', '空き', '不明', '入居率', '空室率', '最終調査'] as $label) {
            $this->assertStringContainsString('sort=', $this->sortLinkFor($html, $label), "「{$label}」の見出しが並び替えリンクになっていない");
        }

        $this->assertSame('descending', $this->ariaSortFor($html, '最終調査'));
        foreach (['総階数', '営業', '空き', '不明', '入居率', '空室率'] as $label) {
            $this->assertSame('none', $this->ariaSortFor($html, $label), "並び替えていない列「{$label}」に aria-sort が載っている");
        }
    }

    /** 並び替え対象外の列は素の <th> のまま（設計書 §4.1 / §10） */
    public function test_the_name_location_and_actions_columns_stay_plain(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();

        $this->assertStringContainsString('>ビル名</th>', $html, 'ビル名が並び替え見出しになっている（対象外）');
        $this->assertStringContainsString('>位置</th>', $html);
        $this->assertStringContainsString('>操作</th>', $html);
        $this->assertSame('none', $this->ariaSortFor($html, 'ビル名'));
    }

    /**
     * フィルタを変えても並び順が消えないこと（`x-sort-hidden`。前設計書 §4.3-4）。
     *
     * ⚠ 画面が描画したフォームを分解してそのまま送り返す（Bug #47）。
     *   hidden が無いと GET で送り直された瞬間に ?sort と ?dir が落ち、**黙って既定順へ戻る**。
     */
    public function test_changing_a_filter_keeps_the_current_sort(): void
    {
        $this->seedFourBuildings();
        $this->makeSurvey($this->makeBuilding('Eビル', ['total_floors' => 1]), '2025-08-01', 5, 5, 0);

        $staff = $this->staff();
        $html  = $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc')->getContent();
        $form  = $this->parseForm($html, 'action="' . route('tenant.area-buildings.index') . '"');

        $this->assertSame('floors', $form['fields']['sort'] ?? null, 'フィルターフォームが sort を持ち回していない');
        $this->assertSame('desc', $form['fields']['dir'] ?? null, 'フィルターフォームが dir を持ち回していない');

        // ブラウザと同じように、調査年だけ変えて送り返す
        $fields = $form['fields'];
        $fields['year'] = '2026';

        $response = $this->actingAs($staff)->get($form['action'] . '?' . http_build_query($fields));

        $response->assertOk();
        $this->assertSame(
            ['Cビル', 'Bビル', 'Dビル', 'Aビル'],
            $this->listedNames($response),
            'フィルタを変えたら並び順が既定に戻った（総階数の降順のままであるべき）'
        );
        $this->assertNotContains('Eビル', $this->listedNames($response), '調査年の絞り込みが効いていない');
    }

    /** 並び替えていないときは余計な hidden を出さない（?sort= が URL に現れて汚れる） */
    public function test_no_sort_hidden_fields_when_not_sorting(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.index') . '"');

        $this->assertArrayNotHasKey('sort', $form['fields']);
        $this->assertArrayNotHasKey('dir', $form['fields']);
    }

    /**
     * タブを切り替えても並び順が持ち回されること（設計書 §4.5）。
     *
     * ⚠ タブリンクは `request()->except(['view','page'])` なので何もしなくても付くが、
     *   **そこを触ったときに気づけないと「表へ戻ると並び順が消える」**が無音で入る。
     * ⚠ ここで sortLinkFor() を使うのは並び替え見出しではなく**タブのリンク**。
     *   要件（`<a …>` の直後にラベル）が同じなので流用できる。URL を組み立てないための流用で、
     *   「表」が別のリンク（サイドバーの『経営試算表』など）に誤マッチしないことは
     *   境界 `>ラベル<` が保証している（ParsesSortLinks の docblock に実測済み）。
     */
    public function test_the_sort_survives_a_trip_through_the_map_tab(): void
    {
        $this->seedFourBuildings();

        $staff  = $this->staff();
        $sorted = ['Cビル', 'Bビル', 'Dビル', 'Aビル'];

        $tableHtml = $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc')->getContent();
        $mapUrl    = $this->sortLinkFor($tableHtml, '地図');
        $this->assertStringContainsString('sort=floors', $mapUrl, '地図タブのリンクが並び順を落としている');

        $mapHtml = $this->actingAs($staff)->get($mapUrl)->assertOk()->getContent();
        $backUrl = $this->sortLinkFor($mapHtml, '表');
        $this->assertStringContainsString('sort=floors', $backUrl, '表タブのリンクが並び順を落としている');

        $back = $this->actingAs($staff)->get($backUrl);
        $this->assertSame($sorted, $this->listedNames($back), '表へ戻ったら並び順が消えている');
    }
}
