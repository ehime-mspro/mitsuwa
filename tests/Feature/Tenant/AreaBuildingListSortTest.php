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
     */
    public function test_the_occupancy_order_is_exactly_the_reverse_of_the_vacancy_order(): void
    {
        $this->seedFourBuildings();
        $this->makeBuilding('Eビル');   // 調査なし＝率は「—」

        $staff = $this->staff();

        $byOccupancyDesc = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=occupancy&dir=desc'));
        $byVacancyAsc    = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=vacancy&dir=asc'));

        $this->assertCount(5, $byOccupancyDesc);
        $this->assertSame($byVacancyAsc, $byOccupancyDesc, '入居率と空室率で並びが食い違う（片方を別計算で出している。Bug #46）');
        $this->assertSame('Eビル', end($byOccupancyDesc), '率が「—」の棟が末尾に来ていない');
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
}
