<?php

namespace Tests\Feature\Tenant;

use App\Enums\InquiryStatus;
use App\Enums\RepairStatus;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 区画の表記（表示名）が画面で崩れないこと。
 *
 * 表示名 `units.display_name` は保存時点で階を含む（`Unit::generateDisplayName()`:
 * 3 階 A → `3A` ／ 地下 1 階 A → `B1A` ／ 階なし A → `A`）。
 * 2026-09-11 まで表示側の 11 か所が「表示名が数字で始まらなければ階を前に付ける」をしていて、
 * `B` で始まる地下だけ `-1B1A` になっていた（docs/RULES.md Bug #57）。
 * 本番の区画 158 件は表示名が全件 `generateDisplayName()` と一致し、前置きが働くのは地下の 3 件だけだった。
 *
 * ⚠ `assertSee('B1A')` は `-1B1A` にも部分一致して素通りする（Bug #43 の型）。
 *   必ず前後の文脈ごと見て、`assertDontSee('-1B1A')` を対で置く。
 */
class UnitLabelDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const TSUBO = '12.50';

    private Property $building;

    private Property $flat;

    /** @var array<string, Unit> 表示名 => 区画 */
    private array $units = [];

    /** @var array<string, Contract> 契約番号 => 契約 */
    private array $contracts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $customer = Customer::create(['code' => 'CU-LBL', 'name' => '表記テスト商事', 'customer_type' => 'corporation']);

        $this->building = Property::create([
            'code' => 'T-LBL-1', 'name' => 'テストビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);
        $this->flat = Property::create([
            'code' => 'T-LBL-2', 'name' => 'テスト平屋', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市',
        ]);

        // 地下 2 区画・地上 2 区画（ビル型）＋ 階なし 1 区画（平屋）
        $shapes = [
            [$this->building, -1, 'A', 'occupied'],
            [$this->building, -1, 'B', 'vacant'],
            [$this->building, 1, 'A', 'vacant'],
            [$this->building, 3, 'A', 'occupied'],
            [$this->flat, null, 'A', 'occupied'],
        ];
        foreach ($shapes as [$property, $floor, $room, $status]) {
            $unit = Unit::create([
                'property_id' => $property->id,
                'floor' => $floor,
                'room_number' => $room,
                'display_name' => Unit::generateDisplayName($floor, $room),
                'status' => $status,
                'area_tsubo' => 12.5,
            ]);
            $this->units[$unit->display_name] = $unit;
        }
        $this->assertSame(['B1A', 'B1B', '1A', '3A', 'A'], array_keys($this->units), '表示名の前提が崩れている');

        $plans = [
            ['C-LBL-B1A', 'B1A', 'active'],
            ['C-LBL-3A', '3A', 'active'],
            ['C-LBL-FLAT', 'A', 'active'],
            ['C-LBL-B1B', 'B1B', 'terminated'],
        ];
        foreach ($plans as [$number, $label, $status]) {
            $unit = $this->units[$label];
            $this->contracts[$number] = Contract::create([
                'contract_number' => $number,
                'department' => 'tenant',
                'property_id' => $unit->property_id,
                'unit_id' => $unit->id,
                'customer_id' => $customer->id,
                'status' => $status,
                'contract_date' => '2026-04-01',
                'rent_start_date' => '2026-04-01',
                'contract_end_date' => $status === 'terminated' ? '2026-06-30' : null,
                'rent' => 100000,
                'common_fee' => 10000,
                'garbage_fee' => 2000,
                'pest_control_fee' => 1000,
            ]);
        }
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function inquiryWith(string ...$labels): Inquiry
    {
        $inquiry = Inquiry::create([
            'inquiry_number' => 'INQ-LBL-001',
            'property_id' => $this->building->id,
            'contact_name' => '表記 太郎',
            'inquiry_date' => '2026-09-01',
            'status' => InquiryStatus::Follow->value,
        ]);
        $inquiry->units()->attach(array_map(fn ($label) => $this->units[$label]->id, $labels));

        return $inquiry;
    }

    // ============================================================
    // 契約の 6 画面（画面ごとに 1 本。1 か所ずつ壊しても落ちるように）
    // ============================================================

    public function test_contract_list_shows_the_display_name_as_is(): void
    {
        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.index'))
            ->assertOk()
            ->assertSee('テストビル / B1A')
            ->assertSee('テストビル / 3A')
            ->assertSee('テスト平屋 / A')
            ->assertDontSee('-1B1A');
    }

    public function test_contract_detail_shows_the_display_name_as_is(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.show', $this->contracts['C-LBL-B1A']))
            ->assertOk()
            ->assertDontSee('-1B1A')
            ->getContent();

        // 「区画」の欄の中身が表示名だけであること（坪数の span の直前まで）
        $this->assertMatchesRegularExpression(
            '#区画</div>\s*<div class="text-sm font-semibold text-gray-900">\s*B1A\s*<span#',
            $html,
            '契約詳細の「区画」に表示名がそのまま出ていない'
        );
    }

    public function test_contract_edit_shows_the_display_name_as_is(): void
    {
        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.edit', $this->contracts['C-LBL-B1A']))
            ->assertOk()
            ->assertSee('value="B1A（' . self::TSUBO . '坪）"', false)
            ->assertDontSee('-1B1A');
    }

    public function test_contract_revise_shows_the_display_name_as_is(): void
    {
        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.revise', $this->contracts['C-LBL-B1A']))
            ->assertOk()
            ->assertSee('テストビル / B1A')
            ->assertDontSee('-1B1A');
    }

    public function test_contract_terminate_shows_the_display_name_as_is(): void
    {
        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.terminate', $this->contracts['C-LBL-B1A']))
            ->assertOk()
            ->assertSee('テストビル / B1A')
            ->assertDontSee('-1B1A');
    }

    public function test_contract_delete_confirmation_shows_the_display_name_as_is(): void
    {
        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.delete', $this->contracts['C-LBL-B1A']))
            ->assertOk()
            ->assertSee('テストビル / B1A')
            ->assertDontSee('-1B1A');
    }

    // ============================================================
    // 物件詳細（契約中・解約済みの 2 表と問合せタブ）
    // ============================================================

    public function test_property_detail_contract_tabs_show_the_display_name_as_is(): void
    {
        $this->inquiryWith('B1A', '3A');

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->assertDontSee('-1B1A')
            ->assertDontSee('-1B1B')
            ->getContent();

        // ⚠ フロアマップも区画カードに表示名を出すので、素の B1A では当たり先を区別できない。
        //   契約番号のリンク → 日付のセル → 区画のセル を 1 本で辿って、その行の区画セルだけを見る
        $this->assertMatchesRegularExpression(
            $this->rowUnitCell('C-LBL-B1A', 'B1A'),
            $html,
            '契約中の表の区画セルに表示名がそのまま出ていない'
        );
        $this->assertMatchesRegularExpression(
            $this->rowUnitCell('C-LBL-B1B', 'B1B'),
            $html,
            '解約済みの表の区画セルに表示名がそのまま出ていない'
        );

        // 問合せタブ（Inquiry::unit_labels）
        $this->assertStringContainsString('>B1A, 3A</td>', $html, '問合せタブの希望区画が表示名のままでない');
    }

    /** 契約番号のリンクから、日付のセルを 1 つ挟んだ次の区画セルまで */
    private function rowUnitCell(string $contractNumber, string $label): string
    {
        return '#' . preg_quote($contractNumber, '#') . '</a>\s*</td>\s*<td[^>]*>[^<]*</td>\s*<td[^>]*>\s*'
            . preg_quote($label, '#') . '\s*</td>#';
    }

    // ============================================================
    // 問合せ（希望区画の表記・登録画面の選択肢）と投資（登録画面の選択肢）
    // ============================================================

    public function test_inquiry_unit_labels_are_the_display_names(): void
    {
        $inquiry = $this->inquiryWith('B1A', '3A');

        $this->assertSame('B1A, 3A', $inquiry->fresh()->unit_labels);

        $this->actingAs($this->executive())
            ->get(route('tenant.inquiries.show', $inquiry))
            ->assertOk()
            ->assertSee('>B1A, 3A</div>', false)
            ->assertDontSee('-1B1A');
    }

    public function test_inquiry_form_unit_options_are_the_display_names(): void
    {
        // 空室・商談中の区画だけが選択肢に出る（B1B と 1A）
        $labels = collect($this->actingAs($this->executive())
            ->get(route('tenant.inquiries.create'))
            ->assertOk()
            ->viewData('allUnits'))->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['B1B（' . self::TSUBO . '坪）', '1A（' . self::TSUBO . '坪）'], $labels);
    }

    public function test_investment_form_unit_options_are_the_display_names(): void
    {
        $labels = collect($this->actingAs($this->executive())
            ->get(route('tenant.investments.create'))
            ->assertOk()
            ->viewData('allUnits'))->pluck('label')->all();

        $this->assertEqualsCanonicalizing(
            array_map(fn ($label) => "{$label}（" . self::TSUBO . '坪）', ['B1A', 'B1B', '1A', '3A', 'A']),
            $labels
        );
    }

    // ============================================================
    // 修繕の区画選択（登録・編集。コントローラに同じ組み立てが 2 か所あるので別々に見る）
    // ⚠ こちらは条件なしで階を前に付けていたので、地下（-1B1A）だけでなく地上も 11A / 33A になっていた
    // ============================================================

    public function test_repair_create_unit_options_are_the_display_names(): void
    {
        $labels = collect($this->actingAs($this->executive())
            ->get(route('tenant.repairs.create'))
            ->assertOk()
            ->viewData('allUnits'))->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['B1A', 'B1B', '1A', '3A', 'A'], $labels);
    }

    public function test_repair_edit_unit_options_are_the_display_names(): void
    {
        $repair = Repair::create([
            'property_id' => $this->building->id,
            'status' => RepairStatus::Planned->value,
            'description' => '表記テストの修繕',
        ]);

        $labels = collect($this->actingAs($this->executive())
            ->get(route('tenant.repairs.edit', $repair))
            ->assertOk()
            ->viewData('allUnits'))->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['B1A', 'B1B', '1A', '3A', 'A'], $labels);
    }

    // ============================================================
    // フロアマップの階ラベル（地下は B1F。周辺ビル調査の AreaBuildingTenant::floorLabel() と同じ書き方）
    // ============================================================

    public function test_floor_map_labels_basement_floors_as_b1f(): void
    {
        $response = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->assertDontSee('-1F');

        $this->assertSame(['3F', '1F', 'B1F'], array_column($response->viewData('floorMap')['floors'], 'label'));

        // PC の左端の階ラベルとモバイルの緑のバーの 2 か所に出る
        $this->assertSame(2, preg_match_all('#>\s*B1F\s*</div>#', $response->getContent()), '画面に B1F の階ラベルが出ていない');
    }

    public function test_floor_map_survives_a_unit_without_a_floor_in_a_building(): void
    {
        // ⚠ groupBy('floor') は階なしを '' のキーにする。PHP 8 では '' < 0 が true なので、
        //   「負なら B を付ける」を整数に限らないと abs('') が TypeError になり物件詳細が 500 になる
        Unit::create([
            'property_id' => $this->building->id,
            'floor' => null,
            'room_number' => 'Z',
            'display_name' => Unit::generateDisplayName(null, 'Z'),
            'status' => 'vacant',
        ]);

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk();

        // 階なしの「F」は従来どおり（本番のビル型物件に階なしの区画は 0 件。今回は変えない）
        $this->assertSame(['3F', '1F', 'B1F', 'F'], array_column($response->viewData('floorMap')['floors'], 'label'));
    }
}
