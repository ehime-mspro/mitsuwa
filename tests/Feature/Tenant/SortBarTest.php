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

        $area = $this->actingAs($user)->get('/tenant/area-buildings')->getContent();
        $this->assertStringContainsString('並び替え: 既定（ビル名順）', $area);

        $properties = $this->actingAs($user)->get(route('tenant.properties.index'))->getContent();
        $this->assertStringContainsString('並び替え: 既定（稼働中が先・コード順）', $properties);
        $this->assertStringNotContainsString('ビル名順', $properties, '別の画面の既定順が出ている');

        $units = $this->actingAs($user)->get(route('tenant.units.index'))->getContent();
        $this->assertStringContainsString('並び替え: 既定（物件・階・部屋番号順）', $units);
        $this->assertStringNotContainsString('ビル名順', $units, '別の画面の既定順が出ている');
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
     *   非稼働を最初に作り、code の昇順が作成順と食い違うようにしてある
     *   （`PropertyListSortTest::test_the_default_order_is_unchanged` と同じ作り方）。
     */
    public function test_the_properties_bar_names_the_real_default_order(): void
    {
        $inactive = $this->makeProperty(3, 'inactive');
        $b = $this->makeProperty(2);
        $a = $this->makeProperty(1);

        $response = $this->actingAs($this->staff())->get(route('tenant.properties.index'));

        $this->assertStringContainsString('並び替え: 既定（稼働中が先・コード順）', $response->getContent());
        $this->assertSame(
            [$a->id, $b->id, $inactive->id],
            $response->viewData('properties')->pluck('id')->all(),
            'バーの文言と実際の並びが食い違っている'
        );
    }

    /**
     * 部屋一覧の既定順の**文言と実際の並びが揃っている**こと。
     *
     * ⚠ **GAP チェック**: `UnitController::applySort()` が無条件に末尾へ付ける既定順は
     *   `orderBy('units.property_id')->orderBy('units.floor')->orderBy('units.room_number')`。
     *   階数の昇順が作成順と食い違うようにしてある
     *   （`UnitListSortTest::test_the_default_order_is_unchanged` と同じ作り方）。
     */
    public function test_the_units_bar_names_the_real_default_order(): void
    {
        $property = $this->makeProperty(1);
        $c = $this->makeUnit($property, '301', 3);
        $a = $this->makeUnit($property, '101', 1);
        $b = $this->makeUnit($property, '201', 2);

        $response = $this->actingAs($this->staff())->get(route('tenant.units.index'));

        $this->assertStringContainsString('並び替え: 既定（物件・階・部屋番号順）', $response->getContent());
        $this->assertSame(
            [$a->id, $b->id, $c->id],
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
