<?php

namespace Tests\Feature\Tenant;

use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;

/**
 * 現在の並び順バー（設計書 2026-08-28 §6 / モック 案C のバー）。
 *
 * ⚠ **ヒント文とピルは役割が違うので別々にアサートする。** まとめて見ると片方が消えても
 *   緑になる（Bug #43 / #46 / #49 と同型）。
 * ⚠ 経営層は department.access を素通りするので、1 人で 3 画面とも見られる。
 * ⚠ 行は要らない画面が多いが、周辺ビル調査・物件一覧・部屋一覧は**文言と実際の並びを対で**見るので
 *   データを作る（バーの文言だけを固定すると、defaultLabel が実際の既定順と食い違っていても
 *   検出できない。設計書 §6 が最も嫌う形の嘘）。
 */
class SortBarTest extends AreaBuildingTestCase
{
    use ParsesSortLinks;
    use RefreshDatabase;

    /**
     * 物件を 1 つ作る（`UnitListSortTest::makeProperty()` / `PropertyListSortTest::makeProperty()` と
     * 同じ最小フィールド構成）。
     *
     * @param  int  $n  連番（code は T-BARnnn。**物件一覧の既定順は operation_status asc → code asc**）
     */
    private function makeProperty(int $n, string $operationStatus = 'active'): Property
    {
        return Property::create([
            'code'             => sprintf('T-BAR%03d', $n),
            'name'             => sprintf('並び替えバー物件%02d', $n),
            'property_type'    => 'tenant',
            'department'       => 'tenant',
            'operation_status' => $operationStatus,
            'address'          => '愛媛県松山市本町1-1',
        ]);
    }

    /**
     * 部屋を 1 つ作る（`UnitListSortTest::makeUnit()` と同じ最小フィールド構成）。
     */
    private function makeUnit(Property $property, string $room, int $floor): Unit
    {
        return Unit::create([
            'property_id'      => $property->id,
            'floor'            => $floor,
            'room_number'      => $room,
            'display_name'     => $room,
            'status'           => 'vacant',
            'area_tsubo'       => 20.00,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 200000,
        ]);
    }

    /** 3 画面それぞれが**自分の**既定順を名乗ること */
    public function test_each_list_names_its_own_default_order(): void
    {
        $user = $this->executive();

        // ⚠ 3 画面を 1 つの表から回す。個別に書くと**片側だけの除外**になり、
        //   「別の画面のラベルが漏れて出ている」が無音で通る（2026-08-28 実測: units へ
        //   properties のラベルを持つバーをもう 1 本足しても 1039 本すべて緑だった）。
        //   4 画面目を足したらこの表を直さないと網羅が崩れる形にしてある。
        $screens = [
            '/tenant/area-buildings'              => 'ビル名順',
            route('tenant.properties.index')      => '稼働中が先・コード順',
            route('tenant.units.index')           => '物件・階・部屋番号順',
        ];

        foreach ($screens as $url => $own) {
            $html = $this->actingAs($user)->get($url)->getContent();

            $this->assertStringContainsString("並び替え: 既定（{$own}）", $html, "{$url} が自分の既定順を名乗っていない");

            // ⚠ バーが 2 本出ると、片方が別の画面の既定順を語っていても
            //   「含まれている」系のアサートは全部通ってしまう
            $this->assertSame(1, substr_count($html, '並び替え: 既定（'), "{$url} にバーが 2 本出ている");

            foreach (array_diff(array_values($screens), [$own]) as $foreign) {
                $this->assertStringNotContainsString($foreign, $html, "{$url} に別の画面の既定順（{$foreign}）が出ている");
            }
        }
    }

    /**
     * 周辺ビル調査の既定順の**文言と実際の並びが揃っている**こと。
     *
     * ⚠ 片方だけ直すと「既定（空室率が高い順）」と書いてあるのに名前順で並ぶ、という
     *   本設計が最も嫌う形の嘘になる（設計書 §6 の ⚠）。**必ず対で見る。**
     */
    public function test_the_area_building_bar_names_the_real_default_order(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $this->assertStringContainsString('並び替え: 既定（ビル名順）', $response->getContent());
        $this->assertSame(['あ未調査', 'い率10', 'う率50'], $this->listedNames($response), 'バーの文言と実際の並びが食い違っている');
    }

    /**
     * 物件一覧の既定順の**文言と実際の並びが揃っている**こと。
     *
     * ⚠ **GAP チェック**: `PropertyController::index()` の既定順（$sort が null のときだけ通る経路）は
     *   `orderBy('operation_status', 'asc')->orderBy('code', 'asc')`。OperationStatus は
     *   `enum('active','inactive')`（migration）で asc なら active が先 ＝ 「稼働中が先」。
     *
     * ⚠ **フィクスチャは 2 つの orderBy キーそれぞれが単独で検出力を持つように組んである**
     *   （2026-08-28 コード品質レビューで指摘・実測）。**旧形**（非稼働の code を最大にし、
     *   稼働 2 件を作成順のまま並べる）は `orderBy('operation_status', …)` を落としても
     *   **GREEN のまま**だった（`OK (1 test, 2 assertions)`）——非稼働の code がたまたま最大で、
     *   code 単独の並びが既定順と一致してしまうため。「稼働中が先」の半分が未検証だった。
     *   今回は非稼働の code を**最小**にし、稼働 2 件を**コード順の逆に作成**する:
     *
     *   | 変数                | 稼働状態 | code | 作成順 |
     *   |----------------------|----------|------|--------|
     *   | $inactive            | 非稼働   | 001  | 1 番目 |
     *   | $activeHigherCode    | 稼働     | 003  | 2 番目 |
     *   | $activeLowerCode     | 稼働     | 002  | 3 番目 |
     *
     *   - 実際の既定順: [$activeLowerCode(002), $activeHigherCode(003), $inactive(001)]
     *   - `operation_status` を落とすと: code 昇順のみ →
     *     [$inactive(001), $activeLowerCode(002), $activeHigherCode(003)]
     *     （**非稼働が先頭に来て実際の既定順と食い違う**）
     *   - `code` を落とすと: 稼働 2 件は作成順のまま →
     *     [$activeHigherCode, $activeLowerCode, $inactive]
     *     （**稼働 2 件の順が実際の既定順と逆転する**）
     *   のいずれも、変異を当てて実測で赤になることを確認済み（両キーが独立に load-bearing）。
     */
    public function test_the_properties_bar_names_the_real_default_order(): void
    {
        $inactive         = $this->makeProperty(1, 'inactive');
        $activeHigherCode = $this->makeProperty(3);
        $activeLowerCode  = $this->makeProperty(2);

        // 作成順が id 順であることを固定する（崩れると上の予測が成立しない）
        $this->assertSame(
            [$inactive->id, $activeHigherCode->id, $activeLowerCode->id],
            Property::orderBy('id')->pluck('id')->all(),
            'id 順と作成順が食い違うデータになっていない（変異の検出力の前提が崩れる）'
        );

        $response = $this->actingAs($this->staff())->get(route('tenant.properties.index'));

        $this->assertStringContainsString('並び替え: 既定（稼働中が先・コード順）', $response->getContent());
        $this->assertSame(
            [$activeLowerCode->id, $activeHigherCode->id, $inactive->id],
            $response->viewData('properties')->pluck('id')->all(),
            'バーの文言と実際の並びが食い違っている'
        );
    }

    /**
     * 部屋一覧の既定順の**文言と実際の並びが揃っている**こと。
     *
     * ⚠ **GAP チェック**: `UnitController::applySort()` が無条件に付ける既定順は
     *   `orderBy('units.property_id')->orderBy('units.floor')->orderBy('units.room_number')`。
     *
     * ⚠ **フィクスチャは 3 つの orderBy キーそれぞれが単独で検出力を持つように組んである**
     *   （2026-08-28 コード品質レビューで指摘・実測）。**旧形**（物件 1 件・階数だけを作成順と
     *   食い違わせる）は、`property_id` を落としても物件が 1 件しかないので**GREEN のまま**、
     *   `room_number` を落としても階数だけで一意に決まるので**GREEN のまま**だった
     *   （「物件・階」の 2 キーが未検証だった）。今回は物件を 2 件にし、
     *   階の跨ぎ方・同一階の部屋番号を意図的に食い違わせる:
     *
     *   | 変数 | 物件 | 階 | 部屋番号 | 作成順 |
     *   |------|------|----|----------|--------|
     *   | $u1  | P1   | 5  | "301"    | 1 番目 |
     *   | $u2  | P1   | 5  | "105"    | 2 番目 |
     *   | $u3  | P1   | 2  | "902"    | 3 番目 |
     *   | $v1  | P2   | 1  | "101"    | 4 番目 |
     *
     *   - $u1/$u2 は**同一物件・同一階**を部屋番号の逆順に作成（room_number を落とすと検出）
     *   - $u3 は**部屋番号の大小が階の大小と逆**（"902" は文字列として最大だが階は最小の 2。
     *     floor を落とすと検出）
     *   - $v1 は P1 のどの階よりも小さい階 1 を、作成順で id が大きい P2 に置く
     *     （property_id を落とすと検出）
     *
     *   実際の既定順: [$u3, $u2, $u1, $v1]
     *   - `property_id` を落とすと: 階昇順のみ（物件を跨いで比較）→
     *     [$v1(1), $u3(2), $u2(5,"105"), $u1(5,"301")]（**$v1 が先頭に来て食い違う**）
     *   - `floor` を落とすと: 物件→部屋番号のみ → P1 内は "105"<"301"<"902" なので
     *     [$u2, $u1, $u3, $v1]（**P1 内の順がまるごと変わる**）
     *   - `room_number` を落とすと: 物件→階のみ、同一階内は作成順（$u1→$u2）→
     *     [$u3, $u1, $u2, $v1]（**$u1/$u2 が実際の既定順と逆転する**）
     *   のいずれも、変異を当てて実測で赤になることを確認済み（3 キーとも独立に load-bearing）。
     *   ⚠ room_number は文字列比較（`'101' < '105' < '202'` の桁ごと比較）。本フィクスチャは
     *   全て 3 桁の部屋番号にそろえ、数値表記との混同を避けている。
     */
    public function test_the_units_bar_names_the_real_default_order(): void
    {
        $p1 = $this->makeProperty(1);
        $u1 = $this->makeUnit($p1, '301', 5);
        $u2 = $this->makeUnit($p1, '105', 5);
        $u3 = $this->makeUnit($p1, '902', 2);

        $p2 = $this->makeProperty(2);
        $v1 = $this->makeUnit($p2, '101', 1);

        // 作成順が id 順であることを固定する（崩れると上の予測が成立しない）
        $this->assertSame(
            [$u1->id, $u2->id, $u3->id, $v1->id],
            Unit::orderBy('id')->pluck('id')->all(),
            'id 順と作成順が食い違うデータになっていない（変異の検出力の前提が崩れる）'
        );

        $response = $this->actingAs($this->staff())->get(route('tenant.units.index'));

        $this->assertStringContainsString('並び替え: 既定（物件・階・部屋番号順）', $response->getContent());
        $this->assertSame(
            [$u3->id, $u2->id, $u1->id, $v1->id],
            $response->viewData('units')->pluck('id')->all(),
            'バーの文言と実際の並びが食い違っている'
        );
    }

    /**
     * 並び替え中は列名と**向きの言い方**が出ること。
     *
     * ⚠ 率は「高い/低い」、件数は「多い/少ない」、日付は「新しい/古い」。
     *   ここを 1 語に統一すると日本語として不自然になる（設計書 §4.1）。
     */
    public function test_the_bar_names_the_column_and_the_direction(): void
    {
        $this->makeBuilding('Aビル');
        $staff = $this->staff();

        $get = fn (string $q) => $this->actingAs($staff)->get('/tenant/area-buildings?' . $q)->getContent();

        $this->assertStringContainsString('並び替え: 入居率 高い順', $get('sort=occupancy&dir=desc'));
        $this->assertStringContainsString('並び替え: 入居率 低い順', $get('sort=occupancy&dir=asc'));
        $this->assertStringContainsString('並び替え: 総階数 多い順', $get('sort=floors&dir=desc'));
        $this->assertStringContainsString('並び替え: 総階数 少ない順', $get('sort=floors&dir=asc'));
        $this->assertStringContainsString('並び替え: 最終調査 新しい順', $get('sort=month&dir=desc'));
        $this->assertStringContainsString('並び替え: 最終調査 古い順', $get('sort=month&dir=asc'));
    }

    /**
     * 「解除」は並び順だけを消し、**絞り込みは残す**（設計書 §6）。
     *
     * ⚠ フィルタも消える実装だと、フィルタごと初期化する「クリア」と区別が無くなる。
     *   **フィルタ付きで踏んで確認する。**
     */
    public function test_the_clear_link_removes_only_the_sort(): void
    {
        $this->makeSurvey($this->makeBuilding('Aビル'), '2026-08-01', 5, 5);   // 空室率 50% → under75
        $this->makeSurvey($this->makeBuilding('Bビル'), '2026-08-01', 10, 0);  // 満室 → under75 では外れる

        $staff = $this->staff();
        $html  = $this->actingAs($staff)
            ->get('/tenant/area-buildings?sort=occupancy&dir=desc&occupancy=under75')
            ->getContent();

        $clearUrl = $this->sortLinkFor($html, '解除');
        $this->assertStringNotContainsString('sort=', $clearUrl, '解除リンクが並び順を残している');
        $this->assertStringNotContainsString('dir=', $clearUrl);
        $this->assertStringContainsString('occupancy=under75', $clearUrl, '解除リンクが絞り込みまで消している（「クリア」と区別が無い）');

        $cleared = $this->actingAs($staff)->get($clearUrl);
        $cleared->assertOk();
        $this->assertStringContainsString('並び替え: 既定（ビル名順）', $cleared->getContent());
        $this->assertSame(['Aビル'], $this->listedNames($cleared), '解除したら絞り込みまで外れている');
    }

    /** 並び替えていないときは解除リンクを出さない（消すものが無い） */
    public function test_the_clear_link_is_absent_when_nothing_is_sorted(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();

        $this->assertStringNotContainsString('>解除</a>', $html);
    }

    /**
     * ヒント文は並び替えの有無にかかわらず出ること。
     *
     * ⚠ **ピルとは別々にアサートする。** 1 本にまとめると、ヒント文だけを消す変異が
     *   ピルのアサートに救われて緑になる（Bug #43 / #46 / #49）。
     */
    public function test_the_hint_is_shown_whether_or_not_the_list_is_sorted(): void
    {
        $this->makeBuilding('Aビル');
        $staff = $this->staff();

        $this->assertStringContainsString(
            '見出しをクリックすると並び替えできます',
            $this->actingAs($staff)->get('/tenant/area-buildings')->getContent()
        );
        $this->assertStringContainsString(
            '見出しをクリックすると並び替えできます',
            $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc')->getContent()
        );
    }

    /**
     * 地図タブにはバーを出さない（設計書 §4.5）。
     *
     * ⚠ 未登録リストは常にビル名の昇順で固定なので、並び替えは地図タブの見た目を一切変えない。
     *   出すと「並び替え中」と書いてあるのに何も変わらない画面になる。
     */
    public function test_the_map_tab_has_no_sort_bar(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map&sort=floors&dir=desc')->getContent();

        $this->assertStringNotContainsString('並び替え:', $html, '地図タブにバーが出ている');
        $this->assertStringNotContainsString('見出しをクリックすると並び替えできます', $html);
    }
}
