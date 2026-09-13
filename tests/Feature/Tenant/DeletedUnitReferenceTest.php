<?php

namespace Tests\Feature\Tenant;

use App\Enums\InquiryStatus;
use App\Enums\RepairStatus;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Investment;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 論理削除した区画を参照する画面が落ちず、削除済みと分かる形で出ること。
 *
 * 区画は論理削除で、削除の歯止めは「契約中の契約がある」だけ。投資・修繕・問合せが付いた区画も削除できるのに、
 * それらから区画へのリレーションが削除済みを読まなかった（2026-09-13 まで）。区画を 1 つ削除した瞬間に
 * 投資の一覧・詳細・物件詳細が 500、修繕は「共用部」と誤表示し保存すると共用部に書き換わり、
 * 問合せは希望区画が黙って消え保存すると中間テーブルからも消えていた（docs/RULES.md Bug #58）。
 *
 * 表示は `Unit::display_label`（表示名 ＋ 削除済みなら「（削除済み）」）。区画ページは削除済みだと 404 なのでリンクを張らない。
 *
 * ⚠ `（削除済み）` は担当者の選択肢にも出る。必ず区画名とセットで見る。
 * ⚠ `B1A（削除済み）` は `1A（削除済み）` を部分文字列に含む。生きている区画 `1A` に印が付いていないことは
 *   前後の文脈ごと見る（Bug #43 の型）。
 */
class DeletedUnitReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Property $building;

    /** 論理削除する区画（投資・修繕・問合せ・解約済み契約が参照する） */
    private Unit $deleted;

    /** 生きている区画 */
    private Unit $live;

    /** どこからも参照されていない削除済みの区画（編集画面の選択肢に出てはいけない） */
    private Unit $otherDeleted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->building = Property::create([
            'code' => 'T-DEL-1', 'name' => 'テストビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);

        $this->deleted = $this->makeUnit(-1, 'A');
        $this->live = $this->makeUnit(1, 'A');
        $this->otherDeleted = $this->makeUnit(3, 'A');
        $this->otherDeleted->delete();
        $this->assertSame(
            ['B1A', '1A', '3A'],
            [$this->deleted->display_name, $this->live->display_name, $this->otherDeleted->display_name],
            '表示名の前提が崩れている'
        );
    }

    private function makeUnit(?int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $this->building->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => 'vacant',
            'area_tsubo' => 12.5,
        ]);
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 参照を作ってから区画を削除する（実運用の順序） */
    private function deleteUnit(): void
    {
        $this->deleted->delete();
        $this->assertSoftDeleted($this->deleted);
    }

    private function investmentOn(Unit $unit, string $number): Investment
    {
        return Investment::create([
            'investment_number' => $number,
            'property_id' => $this->building->id,
            'unit_id' => $unit->id,
            'pattern' => 'renovation',
            'status' => 'planning',
            'description' => '削除済み区画のテスト',
            'total_amount' => 1000000,
        ]);
    }

    /** 区画ページへのリンク（閉じ引用符まで含める。`/units/1` が `/units/12` に部分一致するため） */
    private function unitLink(Unit $unit): string
    {
        return 'href="' . route('tenant.units.show', $unit) . '"';
    }

    // ============================================================
    // 投資
    // ============================================================

    public function test_investment_list_shows_a_deleted_unit_with_the_marker(): void
    {
        $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->investmentOn($this->live, 'INV-DEL-1A');
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.investments.index'))
            ->assertOk()
            ->assertSee('テストビル / B1A（削除済み）')
            ->getContent();

        // 生きている区画には印が付かない
        $this->assertMatchesRegularExpression('#テストビル / 1A\s*</td>#u', $html, '生きている区画に印が付いている');
    }

    public function test_investment_detail_shows_a_deleted_unit_without_a_link(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.investments.show', $investment))
            ->assertOk()
            ->assertSee('B1A（削除済み）')
            // 区画ページは削除済みだと 404 なのでリンクを張らない
            ->assertDontSee($this->unitLink($this->deleted), false)
            // 削除確認モーダルの対象の表記
            ->assertSee('INV-DEL-B1A — テストビル / B1A（削除済み）');
    }

    public function test_investment_detail_keeps_the_link_for_a_live_unit(): void
    {
        $investment = $this->investmentOn($this->live, 'INV-DEL-1A');
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.investments.show', $investment))
            ->assertOk()
            ->assertSee($this->unitLink($this->live) . ' class="text-emerald-600 hover:underline">1A</a>', false)
            ->assertSee('INV-DEL-1A — テストビル / 1A<', false);
    }

    public function test_property_detail_investment_tab_shows_a_deleted_unit(): void
    {
        $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->getContent();

        // 投資タブの行: 投資番号のリンク → 区画のセル
        $this->assertMatchesRegularExpression(
            '#INV-DEL-B1A</a>\s*</td>\s*<td[^>]*>B1A（削除済み）</td>#u',
            $html,
            '物件詳細の投資タブに削除済みの区画が出ていない'
        );
    }

    // ------------------------------------------------------------
    // 投資の登録・編集（選択肢は Alpine が描くので HTML でなく viewData で見る。@json は日本語を \uXXXX にする）
    // ------------------------------------------------------------

    /** @return list<string> この物件の区画の選択肢のラベル */
    private function optionLabels(array|\Illuminate\Support\Collection $options): array
    {
        return collect($options)->where('property_id', $this->building->id)->pluck('label')->values()->all();
    }

    /** 画面が送る投資のフォーム値（区画・物件・明細は画面のデータから組む。Bug #54 ②） */
    private function investmentFormFrom(\Illuminate\Testing\TestResponse $edit, array $overrides = []): array
    {
        $investment = $edit->viewData('investment');
        $option = collect($edit->viewData('allUnits'))
            ->where('property_id', $investment->property_id)
            ->firstWhere('id', $investment->unit_id);
        $this->assertNotNull($option, '編集画面の区画の選択肢に今の区画が無い（ブラウザでは選択が外れて「区画は必須」になる）');

        return array_merge([
            'property_id' => $investment->property_id,
            'unit_id' => $option['id'],
            'pattern' => $investment->pattern->value,
            'status' => 'in_progress',
            'description' => $investment->description,
            'details' => $edit->viewData('investmentDetails')->all(),
        ], $overrides);
    }

    public function test_investment_create_form_does_not_offer_deleted_units(): void
    {
        $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.investments.create'))
            ->assertOk()
            ->viewData('allUnits');

        $this->assertSame(['1A（12.50坪）'], $this->optionLabels($options));
    }

    public function test_investment_edit_form_offers_only_its_own_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.investments.edit', $investment))
            ->assertOk()
            ->viewData('allUnits');

        // 今の区画は削除済みでも残り、ほかの削除済み（3A）は出ない
        $this->assertEqualsCanonicalizing(['B1A（削除済み）（12.50坪）', '1A（12.50坪）'], $this->optionLabels($options));
    }

    public function test_investment_edit_round_trip_keeps_the_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 1000000]);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.investments.edit', $investment))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.investments.update', $investment), $this->investmentFormFrom($edit))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tenant.investments.show', $investment));

        $investment->refresh();
        $this->assertSame($this->deleted->id, $investment->unit_id, '削除済みの区画が保存で外れた');
        $this->assertSame('in_progress', $investment->status->value, 'ほかの項目の変更が保存されていない');
    }

    public function test_investment_update_can_move_off_the_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 1000000]);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.investments.edit', $investment))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.investments.update', $investment), $this->investmentFormFrom($edit, ['unit_id' => $this->live->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->live->id, $investment->refresh()->unit_id);
    }

    public function test_investment_update_rejects_another_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 1000000]);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.investments.edit', $investment))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.investments.update', $investment), $this->investmentFormFrom($edit, ['unit_id' => $this->otherDeleted->id]))
            ->assertSessionHasErrors(['unit_id' => '選択された区画は存在しません。']);

        $this->assertSame($this->deleted->id, $investment->refresh()->unit_id);
    }

    public function test_investment_store_rejects_a_deleted_unit(): void
    {
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.investments.store'), [
                'property_id' => $this->building->id,
                'unit_id' => $this->deleted->id,
                'pattern' => 'renovation',
                'status' => 'planning',
                'details' => [['cost_item' => 'interior', 'amount' => 1000000]],
            ])
            ->assertSessionHasErrors(['unit_id' => '選択された区画は存在しません。']);

        $this->assertSame(0, Investment::count());
    }

    // ============================================================
    // 修繕（区画は任意。空＝共用部）
    // ============================================================

    private function repairOn(?Unit $unit): Repair
    {
        return Repair::create([
            'property_id' => $this->building->id,
            'unit_id' => $unit?->id,
            'status' => RepairStatus::Planned->value,
            'description' => '削除済み区画の修繕',
        ]);
    }

    /** 画面が送る修繕のフォーム値（区画・物件は画面のデータから組む。Bug #54 ②） */
    private function repairFormFrom(\Illuminate\Testing\TestResponse $edit, array $overrides = []): array
    {
        $repair = $edit->viewData('repair');
        $option = collect($edit->viewData('allUnits'))
            ->where('property_id', $repair->property_id)
            ->firstWhere('id', $repair->unit_id);
        $this->assertNotNull($option, '編集画面の区画の選択肢に今の区画が無い（ブラウザでは先頭の「共用部」が選ばれ、保存で共用部に書き換わる）');

        return array_merge([
            'property_id' => $repair->property_id,
            'unit_id' => $option['id'],
            'status' => RepairStatus::InProgress->value,
            'description' => $repair->description,
        ], $overrides);
    }

    public function test_repair_detail_shows_a_deleted_unit_without_a_link(): void
    {
        $repair = $this->repairOn($this->deleted);
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.repairs.show', $repair))
            ->assertOk()
            ->assertSee('B1A（削除済み）')
            ->assertDontSee($this->unitLink($this->deleted), false)
            // 区画が読めないと「共用部」と誤表示していた
            ->assertDontSee('共用部')
            // 削除確認モーダルの対象の表記
            ->assertSee('テストビル / B1A（削除済み） — 削除済み区画の修繕');
    }

    public function test_repair_detail_keeps_the_link_for_a_live_unit(): void
    {
        $repair = $this->repairOn($this->live);
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.repairs.show', $repair))
            ->assertOk()
            ->assertSee($this->unitLink($this->live) . ' class="text-emerald-600 hover:underline">1A</a>', false);
    }

    public function test_repair_detail_still_shows_the_common_area(): void
    {
        $repair = $this->repairOn(null);
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.repairs.show', $repair))
            ->assertOk()
            ->assertSee('共用部')
            ->assertSee('テストビル / 共用部 — 削除済み区画の修繕');
    }

    public function test_repair_list_shows_a_deleted_unit_with_the_marker(): void
    {
        $this->repairOn($this->deleted);
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.repairs.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#<td[^>]*>\s*B1A（削除済み）\s*</td>#u', $html, '修繕一覧に削除済みの区画が出ていない');
        $this->assertStringNotContainsString('共用部', $html, '削除済みの区画を共用部と誤表示している');
    }

    public function test_property_detail_repair_tab_shows_a_deleted_unit(): void
    {
        $this->repairOn($this->deleted);
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->getContent();

        // 修繕タブの行: 区画のセル → 分類のセル → 内容のリンク
        $this->assertMatchesRegularExpression(
            '#>B1A（削除済み）</td>\s*<td[^>]*>[^<]*</td>\s*<td[^>]*>\s*<a [^>]*>削除済み区画の修繕</a>#u',
            $html,
            '物件詳細の修繕タブに削除済みの区画が出ていない'
        );
        $this->assertStringNotContainsString('共用部', $html, '削除済みの区画を共用部と誤表示している');
    }

    public function test_repair_create_form_does_not_offer_deleted_units(): void
    {
        $this->repairOn($this->deleted);
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.repairs.create'))
            ->assertOk()
            ->viewData('allUnits');

        $this->assertSame(['1A'], $this->optionLabels($options));
    }

    public function test_repair_edit_form_offers_only_its_own_deleted_unit(): void
    {
        $repair = $this->repairOn($this->deleted);
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.repairs.edit', $repair))
            ->assertOk()
            ->viewData('allUnits');

        // 今の区画は削除済みでも残り、ほかの削除済み（3A）は出ない
        $this->assertEqualsCanonicalizing(['B1A（削除済み）', '1A'], $this->optionLabels($options));
    }

    public function test_repair_edit_round_trip_keeps_the_deleted_unit(): void
    {
        $repair = $this->repairOn($this->deleted);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.repairs.edit', $repair))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.repairs.update', $repair), $this->repairFormFrom($edit))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tenant.repairs.show', $repair));

        $repair->refresh();
        $this->assertSame($this->deleted->id, $repair->unit_id, '保存で区画が共用部に書き換わった');
        $this->assertSame(RepairStatus::InProgress, $repair->status, 'ほかの項目の変更が保存されていない');
    }

    public function test_repair_update_can_move_to_the_common_area(): void
    {
        $repair = $this->repairOn($this->deleted);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.repairs.edit', $repair))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.repairs.update', $repair), $this->repairFormFrom($edit, ['unit_id' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($repair->refresh()->unit_id);
    }

    public function test_repair_update_rejects_another_deleted_unit(): void
    {
        $repair = $this->repairOn($this->deleted);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.repairs.edit', $repair))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.repairs.update', $repair), $this->repairFormFrom($edit, ['unit_id' => $this->otherDeleted->id]))
            ->assertSessionHasErrors(['unit_id' => '選択された区画は存在しません。']);

        $this->assertSame($this->deleted->id, $repair->refresh()->unit_id);
    }

    public function test_repair_store_rejects_a_deleted_unit(): void
    {
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.repairs.store'), [
                'property_id' => $this->building->id,
                'unit_id' => $this->deleted->id,
                'status' => RepairStatus::Planned->value,
                'description' => '削除済み区画の修繕',
            ])
            ->assertSessionHasErrors(['unit_id' => '選択された区画は存在しません。']);

        $this->assertSame(0, Repair::count());
    }

    // ============================================================
    // 問合せ（希望区画は複数。中間テーブル inquiry_units）
    // ============================================================

    /** 希望区画を付けた順に並ぶ（既存の UnitLabelDisplayTest と同じ前提） */
    private function inquiryOn(Unit ...$units): Inquiry
    {
        $inquiry = Inquiry::create([
            'inquiry_number' => 'INQ-DEL-001',
            'property_id' => $this->building->id,
            'contact_name' => '削除 太郎',
            'inquiry_date' => '2026-09-01',
            'status' => InquiryStatus::Follow->value,
        ]);
        $inquiry->units()->attach(array_map(fn (Unit $unit) => $unit->id, $units));

        return $inquiry;
    }

    /** @return list<int> 中間テーブルに残っている区画（リレーションを通さず直接見る） */
    private function pivotUnitIds(Inquiry $inquiry): array
    {
        return DB::table('inquiry_units')->where('inquiry_id', $inquiry->id)
            ->orderBy('unit_id')->pluck('unit_id')->map(fn ($id) => (int) $id)->all();
    }

    /** 画面が送る問合せのフォーム値（希望区画は画面が選択済みとして hidden で送るもの。Bug #54 ②） */
    private function inquiryFormFrom(\Illuminate\Testing\TestResponse $edit, array $overrides = []): array
    {
        $inquiry = $edit->viewData('inquiry');

        return array_merge([
            'property_id' => $inquiry->property_id,
            'unit_ids' => $edit->viewData('selectedUnitIds'),
            'inquiry_date' => $inquiry->inquiry_date->format('Y-m-d'),
            'contact_name' => $inquiry->contact_name,
        ], $overrides);
    }

    public function test_inquiry_list_shows_a_deleted_unit_with_the_marker(): void
    {
        $this->inquiryOn($this->deleted, $this->live);
        $this->deleteUnit();

        // 以前は削除済みの区画が黙って消え「1A」だけになっていた（全部消えると「未定」）
        $this->actingAs($this->executive())
            ->get(route('tenant.inquiries.index'))
            ->assertOk()
            ->assertSee('<span class="text-gray-700">B1A（削除済み）, 1A</span>', false);
    }

    public function test_inquiry_detail_shows_a_deleted_unit(): void
    {
        $inquiry = $this->inquiryOn($this->deleted, $this->live);
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.inquiries.show', $inquiry))
            ->assertOk()
            ->assertSee('<div class="text-sm font-semibold text-gray-900">B1A（削除済み）, 1A</div>', false);
    }

    public function test_property_detail_inquiry_tab_shows_a_deleted_unit(): void
    {
        $this->inquiryOn($this->deleted, $this->live);
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->getContent();

        // 問合せタブの行: 問合せ番号のリンク → 日付のセル → 希望区画のセル
        $this->assertMatchesRegularExpression(
            '#INQ-DEL-001</a>\s*</td>\s*<td[^>]*>[^<]*</td>\s*<td[^>]*>B1A（削除済み）, 1A</td>#u',
            $html,
            '物件詳細の問合せタブに削除済みの区画が出ていない'
        );
    }

    public function test_inquiry_create_form_does_not_offer_deleted_units(): void
    {
        $this->inquiryOn($this->deleted);
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.inquiries.create'))
            ->assertOk()
            ->viewData('allUnits');

        $this->assertSame(['1A（12.50坪）'], $this->optionLabels($options));
    }

    public function test_inquiry_edit_form_offers_only_its_own_deleted_unit(): void
    {
        // 商談中のまま削除された区画にも「商談中」は付けない（生きている商談中の区画には付く）
        $this->deleted->update(['status' => 'negotiating']);
        $this->live->update(['status' => 'negotiating']);
        $inquiry = $this->inquiryOn($this->deleted);
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.inquiries.edit', $inquiry))
            ->assertOk()
            ->viewData('allUnits');

        // 今の希望区画は削除済みでも残り、ほかの削除済み（3A）は出ない
        $actual = collect($options)->where('property_id', $this->building->id)
            ->mapWithKeys(fn ($option) => [$option['label'] => $option['status']])->all();
        ksort($actual);
        $expected = ['B1A（削除済み）（12.50坪）' => '', '1A（12.50坪）' => '商談中'];
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public function test_inquiry_edit_round_trip_keeps_the_deleted_unit(): void
    {
        $inquiry = $this->inquiryOn($this->deleted, $this->live);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.inquiries.edit', $inquiry))->assertOk();

        // 画面は今の希望区画を選択済みとして持ち（hidden で送る）、チップにも出す（出ないと画面で外せない）
        $this->assertEqualsCanonicalizing(
            [$this->deleted->id, $this->live->id],
            $edit->viewData('selectedUnitIds'),
            '編集画面が削除済みの希望区画を選択済みとして持っていない（保存で中間テーブルから消える）'
        );
        $this->assertNotNull(
            collect($edit->viewData('allUnits'))->where('property_id', $this->building->id)->firstWhere('id', $this->deleted->id),
            '編集画面のチップに削除済みの希望区画が無い（画面で外せない）'
        );

        $this->actingAs($user)
            ->put(route('tenant.inquiries.update', $inquiry), $this->inquiryFormFrom($edit))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tenant.inquiries.show', $inquiry));

        $this->assertSame([$this->deleted->id, $this->live->id], $this->pivotUnitIds($inquiry), '保存で削除済みの希望区画が消えた');
    }

    public function test_inquiry_edit_can_remove_the_deleted_unit(): void
    {
        $inquiry = $this->inquiryOn($this->deleted, $this->live);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.inquiries.edit', $inquiry))->assertOk();

        // 削除済みの区画のチップだけを外して送る
        $remaining = array_values(array_diff($edit->viewData('selectedUnitIds'), [$this->deleted->id]));
        $this->actingAs($user)
            ->put(route('tenant.inquiries.update', $inquiry), $this->inquiryFormFrom($edit, ['unit_ids' => $remaining]))
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->live->id], $this->pivotUnitIds($inquiry));
    }

    public function test_inquiry_update_rejects_another_deleted_unit(): void
    {
        $inquiry = $this->inquiryOn($this->deleted);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.inquiries.edit', $inquiry))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.inquiries.update', $inquiry), $this->inquiryFormFrom($edit, [
                'unit_ids' => [$this->deleted->id, $this->otherDeleted->id],
            ]))
            // 今の希望区画（0 番目）は削除済みでも通り、ほかの削除済み（1 番目）は通らない
            ->assertSessionDoesntHaveErrors('unit_ids.0')
            ->assertSessionHasErrors(['unit_ids.1' => '選択された区画は存在しません。']);

        $this->assertSame([$this->deleted->id], $this->pivotUnitIds($inquiry));
    }

    public function test_inquiry_update_rejects_the_deleted_unit_of_the_previous_property(): void
    {
        $other = Property::create([
            'code' => 'T-DEL-2', 'name' => '別のビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 2,
        ]);
        $inquiry = $this->inquiryOn($this->deleted);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.inquiries.edit', $inquiry))->assertOk();

        // 画面では物件を変えると選択が空になる。所属チェックが削除済みを読まないと、
        // 「今の区画なら削除済みでも通す」入力チェックと組み合わさって別の物件に旧物件の区画が付く
        $this->actingAs($user)
            ->put(route('tenant.inquiries.update', $inquiry), $this->inquiryFormFrom($edit, [
                'property_id' => $other->id,
                'unit_ids' => [$this->deleted->id],
            ]))
            ->assertSessionHasErrors(['unit_ids' => '選択された区画に指定物件に属さないものがあります。']);

        $this->assertSame($this->building->id, $inquiry->refresh()->property_id);
        $this->assertSame([$this->deleted->id], $this->pivotUnitIds($inquiry));
    }

    public function test_inquiry_store_rejects_a_deleted_unit(): void
    {
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.inquiries.store'), [
                'property_id' => $this->building->id,
                'unit_ids' => [$this->deleted->id],
                'inquiry_date' => '2026-09-01',
                'contact_name' => '削除 太郎',
            ])
            ->assertSessionHasErrors(['unit_ids.0' => '選択された区画は存在しません。']);

        $this->assertSame(0, Inquiry::count());
    }

    // ============================================================
    // 契約（Contract::unit は元から削除済みを読む。印だけ付ける）
    // 契約中の契約がある区画は削除できないので、削除済みの区画の契約は必ず解約済み。
    // 編集・賃料改定・解約の画面は解約済みの契約を詳細へ戻すので、開ける画面だけを見る。
    // ============================================================

    private function terminatedContractOn(Unit $unit): Contract
    {
        $customer = Customer::create(['code' => 'CU-DEL', 'name' => '削除テスト商事', 'customer_type' => 'corporation']);

        return Contract::create([
            'contract_number' => 'C-DEL-001',
            'department' => 'tenant',
            'property_id' => $unit->property_id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => 'terminated',
            'contract_date' => '2025-04-01',
            'rent_start_date' => '2025-04-01',
            'contract_end_date' => '2026-06-30',
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
        ]);
    }

    public function test_contract_list_shows_a_deleted_unit_with_the_marker(): void
    {
        $this->terminatedContractOn($this->deleted);
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('テストビル / B1A（削除済み）');
    }

    public function test_contract_detail_shows_a_deleted_unit_with_the_marker(): void
    {
        $contract = $this->terminatedContractOn($this->deleted);
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.show', $contract))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#区画</div>\s*<div class="text-sm font-semibold text-gray-900">\s*B1A（削除済み）\s*<span class="font-normal text-gray-600">（12\.50坪）</span>#u',
            $html,
            '契約詳細の区画に削除済みの印が無い'
        );
    }

    public function test_contract_delete_confirmation_shows_a_deleted_unit_with_the_marker(): void
    {
        $contract = $this->terminatedContractOn($this->deleted);
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.contracts.delete', $contract))
            ->assertOk()
            ->assertSee('テストビル / B1A（削除済み）');
    }

    public function test_property_detail_terminated_tab_shows_a_deleted_unit(): void
    {
        $this->terminatedContractOn($this->deleted);
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->getContent();

        // 解約タブの行: 契約番号のリンク → 解約日のセル → 区画のセル
        $this->assertMatchesRegularExpression(
            '#C-DEL-001</a>\s*</td>\s*<td[^>]*>[^<]*</td>\s*<td[^>]*>\s*B1A（削除済み）\s*</td>#u',
            $html,
            '物件詳細の解約タブに削除済みの印が無い'
        );
    }

    public function test_customer_detail_shows_a_deleted_unit_with_the_marker(): void
    {
        $contract = $this->terminatedContractOn($this->deleted);
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.customers.show', $contract->customer_id))
            ->assertOk()
            ->getContent();

        // 解約済みの表の行: 契約番号のリンク → 物件のセル → 区画のセル
        $this->assertMatchesRegularExpression(
            '#C-DEL-001</a>\s*</td>\s*<td[^>]*>テストビル</td>\s*<td[^>]*>B1A（削除済み）</td>#u',
            $html,
            '顧客詳細の解約済みの表に削除済みの印が無い'
        );
    }

    // ============================================================
    // 構造: 区画を指すリレーションを全件分類する（Top trap #13。直した分を並べる形にしない）
    // ============================================================

    /**
     * app/Models のリレーションで区画（Unit::class）を指すものを機械的に拾い、種類ごとに方針を課す。
     * - 子から区画を読む（belongsTo / belongsToMany）→ 削除済みも読む（読まないと区画を消した瞬間に 500・誤表示・保存で消える）
     * - 物件から区画を並べる（hasMany / hasOne）→ 削除済みを読まない（フロアマップ・区画数・入居率に混ざる）
     * 新しいリレーションが増えたら自動で検査対象に入る。種類が分からないものは落とす。
     */
    public function test_every_relation_to_units_is_classified(): void
    {
        $found = [];
        foreach ($this->modelClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $file = $method->getFileName();
                if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0
                    || $file === false || ! str_starts_with($file, app_path('Models'))) {
                    continue;
                }
                $source = $this->methodSourceWithoutComments($method);
                if (! preg_match('/(?:\\\\App\\\\Models\\\\|(?<![\w\\\\]))Unit::class/', $source)) {
                    continue;
                }
                $key = $class . '::' . $method->getName();
                $this->assertMatchesRegularExpression(
                    '/\$this->(belongsTo|belongsToMany|hasMany|hasOne)\(\s*(?:\\\\?App\\\\Models\\\\)?Unit::class/',
                    $source,
                    "{$key} は区画を指しているが、どの種類のリレーションか分類できない（このテストに方針を足すこと）"
                );
                preg_match('/\$this->(belongsTo|belongsToMany|hasMany|hasOne)\(/', $source, $m);
                $found[$key] = $m[1];
            }
        }

        foreach ($found as $key => $kind) {
            [$class, $method] = explode('::', $key);
            $readsTrashed = in_array(SoftDeletingScope::class, (new $class)->{$method}()->getQuery()->removedScopes(), true);
            if (in_array($kind, ['belongsTo', 'belongsToMany'], true)) {
                $this->assertTrue($readsTrashed, "{$key}（{$kind}）が削除済みの区画を読まない（withTrashed() が要る）");
            } else {
                $this->assertFalse($readsTrashed, "{$key}（{$kind}）が削除済みの区画まで並べる");
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（2026-09-13 時点で子から読む 5 本 ＋ 物件から並べる 1 本）
        $childRelations = array_keys(array_filter($found, fn ($kind) => in_array($kind, ['belongsTo', 'belongsToMany'], true)));
        $this->assertGreaterThanOrEqual(5, count($childRelations), '区画を読むリレーションを拾えていない: ' . implode(', ', $childRelations));
        $this->assertSame('hasMany', $found[Property::class . '::units'] ?? null, 'Property::units を拾えていない');
    }

    public function test_floor_map_does_not_show_deleted_units(): void
    {
        $this->terminatedContractOn($this->deleted);
        $this->deleteUnit();

        $floorMap = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->viewData('floorMap');

        // 削除済みの B1A・3A は並ばない（Property::units に withTrashed() を付けていないことの固定）
        $ids = collect($floorMap['floors'])->flatMap(fn ($floor) => $floor['units']->pluck('id'))->all();
        $this->assertSame([$this->live->id], $ids);
    }

    /** @return list<class-string<Model>> */
    private function modelClasses(): array
    {
        $classes = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** メソッドの本体（docblock を含まない）からコメントを落とした文字列（注意書きの Unit::class に反応しないように。Bug #42 ②） */
    private function methodSourceWithoutComments(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $code = '';
        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
