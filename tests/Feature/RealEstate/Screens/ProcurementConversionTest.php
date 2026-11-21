<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\LotStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProcurementLot;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectLot;
use App\Models\ReSupplier;
use App\Models\ScheduleStep;
use App\Models\User;
use App\Services\RealEstate\ProcurementConversionRefused;
use App\Services\RealEstate\ProcurementToProjectConverter;
use App\Support\DeletionBlockers;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * 土地（自社買取）の仕入れ案件を中身ごと分譲地に変える処理（設計書 §5.5）。この Task ではサービスを直接呼ぶ
 * （画面とルートは別の Task）。
 */
class ProcurementConversionTest extends RealEstateScreenTestCase
{
    private function converter(): ProcurementToProjectConverter
    {
        return app(ProcurementToProjectConverter::class);
    }

    private function manager(): User
    {
        return $this->userWithRole(UserRole::Manager);
    }

    private function step(ReProcurement|ReProject $owner, string $name): ScheduleStep
    {
        return $owner->scheduleSteps()->create(['name' => $name, 'category' => 'work', 'sort_order' => 1]);
    }

    private function attachment(string $type, int $id, string $name): Attachment
    {
        return Attachment::create([
            'attachable_type' => $type,
            'attachable_id'   => $id,
            'file_name'       => $name,
            'file_path'       => 'attachments/procurements/' . $id . '/' . $name,
            'file_size'       => 10,
            'mime_type'       => 'application/pdf',
            'uploaded_by'     => $this->user->id,
        ]);
    }

    /** 原価の行を (費用項目, 見込み額, 確定額, 備考) の組の並びにする（id の順） */
    private function costTuples(iterable $costs): array
    {
        $tuples = [];
        foreach ($costs as $c) {
            $tuples[] = [(int) $c->cost_item_id, (int) $c->estimated_amount, $c->actual_amount === null ? null : (int) $c->actual_amount, $c->notes];
        }
        sort($tuples);

        return $tuples;
    }

    public function test_everything_moves_to_the_new_project(): void
    {
        $creator = User::factory()->create(['role' => UserRole::Manager->value, 'must_change_password' => false]);
        $supplier = ReSupplier::create(['supplier_code' => 'SUP-001', 'type' => 'corporation', 'name' => '松山不動産']);
        $notes = str_repeat('備', 5000);

        $p = $this->landProcurement([
            'status' => 'settled',
            'property_name' => '勝山の土地',
            'postal_code' => '790-0001',
            'address' => '愛媛県松山市勝山町1-1',
            'latitude' => 33.8391570,
            'longitude' => 132.7655480,
            'land_area_sqm' => 132.69,
            'zoning' => '第一種住居地域',
            'building_coverage' => 60,
            'floor_area_ratio' => 200,
            'supplier_id' => $supplier->id,
            'info_obtained_date' => '2026-04-01',
            'assessment_price_land' => 18000000,
            'purchase_price_land' => 17500000,
            'target_selling_price_land' => 25000000,
            'contract_date' => '2026-05-10',
            'settlement_date' => '2026-06-20',
            'notes' => $notes,
            'created_by' => $creator->id,
        ]);
        // 元の仕入れ案件と同じ id の別の分譲地（型を見ずに id だけで付け替えると、こちらの工程・添付まで動く）
        $other = $this->project();
        $this->assertSame($other->id, $p->id, 'テストの前提: 別の分譲地と仕入れ案件の id が同じ');
        $otherStep = $this->step($other, '別の分譲地の工程');
        $otherAttachment = $this->attachment(ReProject::class, $other->id, 'other.pdf');
        // 別の仕入れ案件（型だけで付け替える誤りを見る）
        $otherProcurement = $this->procurement(['procurement_code' => 'RE-PRC-009']);
        $otherProcurementStep = $this->step($otherProcurement, '別の仕入れ案件の工程');
        $otherProcurementAttachment = $this->attachment(ReProcurement::class, $otherProcurement->id, 'other-procurement.pdf');

        // 物件購入費の行は保存のフックが作る。備考を手で入れ、自動で作る行と区別できるようにする
        $purchaseRow = ReProcurementCost::where('procurement_id', $p->id)->where('cost_item_id', $this->purchaseItem->id)->firstOrFail();
        $purchaseRow->update(['notes' => '手付金あり']);
        ReProcurementCost::create(['procurement_id' => $p->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 300000, 'actual_amount' => 280000, 'notes' => '確定測量']);
        ReProcurementCost::create(['procurement_id' => $p->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 120000, 'actual_amount' => null, 'notes' => null]);
        $before = $this->costTuples(ReProcurementCost::where('procurement_id', $p->id)->orderBy('id')->get());
        $this->assertCount(3, $before);

        $this->procurementLot($p, ['lot_number' => 3, 'area_sqm' => 66.15, 'selling_price' => 19990000, 'status' => 'negotiating', 'notes' => '角地']);
        $steps = [$this->step($p, '造成'), $this->step($p, '販売')];
        $live = $this->attachment(ReProcurement::class, $p->id, 'plan.pdf');
        $trashed = $this->attachment(ReProcurement::class, $p->id, 'old.pdf');
        $trashed->delete();

        $manager = $this->manager();
        $returned = $this->converter()->convert($p, $manager);

        $project = ReProject::where('source_procurement_code', 'RE-PRC-001')->firstOrFail();
        $this->assertSame($project->id, $returned->id);
        $this->assertSame('RE-PRJ-002', $project->project_code);
        $this->assertSame('勝山の土地', $project->project_name);
        $this->assertSame(ProjectStatus::Settled, $project->status);
        $this->assertSame('790-0001', $project->postal_code);
        $this->assertSame('愛媛県松山市勝山町1-1', $project->address);
        $this->assertSame('33.8391570', $project->latitude);
        $this->assertSame('132.7655480', $project->longitude);
        $this->assertSame('132.69', $project->land_area_sqm);
        $this->assertSame('第一種住居地域', $project->zoning);
        $this->assertSame(60, $project->building_coverage);
        $this->assertSame(200, $project->floor_area_ratio);
        $this->assertSame($supplier->id, (int) $project->supplier_id);
        $this->assertSame('2026-04-01', $project->info_obtained_date->toDateString());
        $this->assertSame(18000000, $project->assessment_price);
        $this->assertSame(17500000, $project->purchase_price);
        $this->assertSame(25000000, $project->target_selling_price);
        $this->assertSame('2026-05-10', $project->contract_date->toDateString());
        $this->assertSame('2026-06-20', $project->settlement_date->toDateString());
        $this->assertSame($notes, $project->notes);
        $this->assertSame($creator->id, (int) $project->created_by);
        $this->assertSame($manager->id, (int) $project->updated_by);

        // 原価は 1 行ずつそのまま（物件購入費の手で入れた備考・同じ費用項目の 2 行も）
        $this->assertSame($before, $this->costTuples(ReProjectCost::where('project_id', $project->id)->orderBy('id')->get()));

        $lots = ReProjectLot::where('project_id', $project->id)->get();
        $this->assertCount(1, $lots);
        $lot = $lots->first();
        $this->assertSame(3, (int) $lot->lot_number);
        $this->assertSame('66.15', $lot->area_sqm);
        $this->assertSame('20.01', $lot->area_tsubo);
        $this->assertSame(19990000, $lot->selling_price);
        $this->assertSame(999001, $lot->selling_price_per_tsubo);
        $this->assertTrue($lot->is_price_manual);
        $this->assertSame(LotStatus::Negotiating, $lot->status);
        $this->assertSame('角地', $lot->notes);

        foreach ($steps as $s) {
            $s->refresh();
            $this->assertSame([ReProject::class, $project->id], [$s->schedulable_type, (int) $s->schedulable_id]);
        }
        foreach ([$live, $trashed] as $a) {
            $a = Attachment::withTrashed()->findOrFail($a->id);
            $this->assertSame([ReProject::class, $project->id], [$a->attachable_type, (int) $a->attachable_id]);
        }
        $this->assertTrue(Attachment::withTrashed()->findOrFail($trashed->id)->trashed(), '論理削除の印はそのまま');

        // 持ち主の違うものは動かない
        $this->assertSame([ReProject::class, $other->id], [$otherStep->fresh()->schedulable_type, (int) $otherStep->fresh()->schedulable_id]);
        $this->assertSame([ReProject::class, $other->id], [$otherAttachment->fresh()->attachable_type, (int) $otherAttachment->fresh()->attachable_id]);
        $this->assertSame([ReProcurement::class, $otherProcurement->id], [$otherProcurementStep->fresh()->schedulable_type, (int) $otherProcurementStep->fresh()->schedulable_id]);
        $this->assertSame([ReProcurement::class, $otherProcurement->id], [$otherProcurementAttachment->fresh()->attachable_type, (int) $otherProcurementAttachment->fresh()->attachable_id]);

        // 元の仕入れ案件・その原価・区画は残らない
        $this->assertNull(ReProcurement::find($p->id));
        $this->assertSame(0, ReProcurementCost::where('procurement_id', $p->id)->count());
        $this->assertSame(0, ReProcurementLot::where('procurement_id', $p->id)->count());
        $this->assertNotNull(ReProcurement::find($otherProcurement->id));
    }

    public function test_conversion_copies_every_lot(): void
    {
        $p = $this->landProcurement();
        $this->procurementLot($p, ['lot_number' => 3, 'area_sqm' => 66.15, 'selling_price' => 19990000, 'status' => 'negotiating', 'notes' => '角地']);
        $this->procurementLot($p, ['lot_number' => 1, 'area_sqm' => 100.00, 'selling_price' => 10000000, 'status' => 'on_sale', 'notes' => null]);
        $this->procurementLot($p, ['lot_number' => 2, 'area_sqm' => 50.50, 'selling_price' => null, 'status' => 'sold', 'notes' => '南向き']);

        $project = $this->converter()->convert($p, $this->manager());

        $lots = ReProjectLot::where('project_id', $project->id)->orderBy('lot_number')->get();
        $this->assertSame([1, 2, 3], $lots->pluck('lot_number')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['100.00', '50.50', '66.15'], $lots->pluck('area_sqm')->all());
        $this->assertSame(['30.25', '15.27', '20.01'], $lots->pluck('area_tsubo')->all());
        $this->assertSame([10000000, null, 19990000], $lots->pluck('selling_price')->all());
        $this->assertSame([330579, null, 999001], $lots->pluck('selling_price_per_tsubo')->all());
        $this->assertSame([LotStatus::OnSale, LotStatus::Sold, LotStatus::Negotiating], $lots->pluck('status')->all());
        $this->assertSame([null, '南向き', '角地'], $lots->pluck('notes')->all());
        $this->assertSame(0, ReProcurementLot::where('procurement_id', $p->id)->count());
    }

    public function test_a_land_without_prices_keeps_its_purchase_cost_row(): void
    {
        $p = $this->landProcurement(['assessment_price_land' => null, 'purchase_price_land' => null, 'target_selling_price_land' => null]);
        ReProcurementCost::create(['procurement_id' => $p->id, 'cost_item_id' => $this->purchaseItem->id, 'estimated_amount' => 1000000, 'actual_amount' => 900000, 'notes' => '前払い']);

        $project = $this->converter()->convert($p, $this->manager());

        $this->assertNull($project->assessment_price);
        $this->assertNull($project->purchase_price);
        $this->assertNull($project->target_selling_price);
        $this->assertSame(
            [[$this->purchaseItem->id, 1000000, 900000, '前払い']],
            $this->costTuples(ReProjectCost::where('project_id', $project->id)->get()),
        );
    }

    public static function statuses(): array
    {
        return [
            '情報入手' => ['info_obtained', 'info_obtained'],
            '現地調査' => ['site_survey', 'assessment'],
            '査定・検討' => ['assessment', 'assessment'],
            '交渉中' => ['negotiating', 'negotiating'],
            '契約' => ['contracted', 'contracted'],
            '決済完了' => ['settled', 'settled'],
            '販売中' => ['selling', 'selling'],
            '販売済' => ['sold', 'sold_out'],
            '不成約' => ['lost', 'lost'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_status_maps_to_the_project_status(string $from, string $to): void
    {
        $p = $this->landProcurement(['status' => $from]);

        $project = $this->converter()->convert($p, $this->manager());

        $this->assertSame($to, $project->fresh()->status->value);
    }

    public function test_a_sold_lot_makes_the_project_sold_out(): void
    {
        $p = $this->landProcurement(['status' => 'selling']);
        $this->procurementLot($p, ['status' => 'sold']);

        $project = $this->converter()->convert($p, $this->manager());

        $this->assertSame(ProjectStatus::SoldOut, $project->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    public static function refusals(): array
    {
        return [
            '土地でない' => ['used_house', ProcurementToProjectConverter::REFUSE_NOT_LAND],
            '仲介' => ['brokerage', ProcurementToProjectConverter::REFUSE_BROKERAGE],
            '契約' => ['contract', '契約 1 件が紐づいているため、分譲地に変えられません。'],
            '建売' => ['hs_property', '建売物件 1 件が紐づいているため、分譲地に変えられません。'],
            '注文住宅' => ['custom_order', '注文住宅 1 件が紐づいているため、分譲地に変えられません。'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_conversion_is_refused_with_the_reason(string $case, string $message): void
    {
        $p = match ($case) {
            'used_house' => $this->procurement(),
            'brokerage' => $this->landProcurement(['transaction_type' => 'brokerage']),
            default => $this->landProcurement(),
        };
        if ($case === 'contract') {
            ReContract::create([
                'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted',
                'property_name' => '契約物件', 'procurement_id' => $p->id, 'created_by' => 1,
            ]);
        }
        if ($case === 'hs_property') {
            HsProperty::create([
                'property_code' => 'HS-001', 'property_name' => '建売', 'status' => 'construction',
                'land_source_type' => 'procurement', 're_procurement_id' => $p->id,
                'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
            ]);
        }
        if ($case === 'custom_order') {
            HsCustomOrder::create([
                'order_code' => 'CO-001', 'order_name' => '注文', 'customer_name' => '山田',
                'status' => 'estimation', 'land_source_type' => 'procurement', 're_procurement_id' => $p->id,
                'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
            ]);
        }
        ReProcurementCost::create(['procurement_id' => $p->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 300000]);
        $this->step($p, '造成');
        $p = $p->fresh();
        $costsBefore = ReProcurementCost::where('procurement_id', $p->id)->count();

        $this->assertSame($message, ProcurementToProjectConverter::refusal($p));

        try {
            $this->converter()->convert($p, $this->manager());
            $this->fail('断られなかった');
        } catch (ProcurementConversionRefused $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertNotNull(ReProcurement::find($p->id));
        $this->assertSame($costsBefore, ReProcurementCost::where('procurement_id', $p->id)->count());
        $this->assertSame(1, ScheduleStep::where('schedulable_type', ReProcurement::class)->where('schedulable_id', $p->id)->count());
        $this->assertSame(0, ReProject::count());
        $this->assertSame(0, ReProjectCost::count());
    }

    public function test_the_linked_reason_uses_the_same_counts_as_the_delete_guard(): void
    {
        $p = $this->landProcurement();
        foreach ([1, 2] as $i) {
            ReContract::create([
                'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted',
                'property_name' => '契約物件' . $i, 'procurement_id' => $p->id, 'created_by' => 1,
            ]);
        }
        HsProperty::create([
            'property_code' => 'HS-001', 'property_name' => '建売', 'status' => 'construction',
            'land_source_type' => 'procurement', 're_procurement_id' => $p->id,
            'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
        ]);

        $blockers = $p->deletionBlockers();
        $this->assertSame('契約 2 件・建売物件 1 件', DeletionBlockers::countsLabel($blockers));
        $this->assertSame('契約 2 件・建売物件 1 件が参照しているため削除できません。', DeletionBlockers::summarize($blockers));
        $this->assertSame('', DeletionBlockers::summarize([]));
        $this->assertSame('契約 2 件・建売物件 1 件が紐づいているため、分譲地に変えられません。', ProcurementToProjectConverter::refusal($p));
    }

    public function test_a_procurement_that_is_already_gone_is_refused(): void
    {
        $p = $this->landProcurement();
        $stale = $p->fresh();
        $p->delete();

        try {
            $this->converter()->convert($stale, $this->manager());
            $this->fail('断られなかった');
        } catch (ProcurementConversionRefused $e) {
            $this->assertSame(ProcurementToProjectConverter::GONE, $e->getMessage());
        }
        $this->assertSame(0, ReProject::count());
    }

    public function test_the_original_code_is_not_reused(): void
    {
        $this->procurement(['procurement_code' => 'RE-PRC-001']);
        $this->procurement(['procurement_code' => 'RE-PRC-002']);
        $p = $this->landProcurement(['procurement_code' => 'RE-PRC-003']);
        $this->assertSame('RE-PRC-004', ReProcurement::nextProcurementCode());

        $project = $this->converter()->convert($p, $this->manager());

        $this->assertSame('RE-PRC-003', $project->source_procurement_code);
        $this->assertSame('RE-PRC-004', ReProcurement::nextProcurementCode());
    }

    public function test_project_codes_still_count_up(): void
    {
        $this->project(['project_code' => 'RE-PRJ-002']);
        $this->assertSame('RE-PRJ-003', ReProject::nextProjectCode());

        $project = $this->converter()->convert($this->landProcurement(), $this->manager());

        $this->assertSame('RE-PRJ-003', $project->project_code);
        $this->assertSame('RE-PRJ-004', ReProject::nextProjectCode());
    }

    public function test_the_conversion_rechecks_under_a_lock(): void
    {
        // ロックの中の確かめ直しは、順に流すテストでは見えない（入口の確かめが先に止める。Bug #48 の形）ので字句で見る。
        // コメントを落としてから探す（説明の文に同じ語があると、本物を消しても通ってしまう。Bug #42 ②）。
        $reflection = new ReflectionMethod(ProcurementToProjectConverter::class, 'convert');
        $lines = array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1);
        $code = '';
        foreach (token_get_all('<?php ' . implode('', $lines)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $transaction = strpos($code, 'DB::transaction(');
        $lock = strpos($code, '->lockForUpdate()');
        $this->assertNotFalse($transaction, '変える処理がトランザクションで包まれていない');
        $this->assertNotFalse($lock, '変える処理が仕入れ案件の行をロックしていない');
        $this->assertTrue($transaction < $lock, 'ロックはトランザクションの中で取る');
        $recheck = strpos($code, 'refusal(', $lock);
        $this->assertNotFalse($recheck, 'ロックのあとで条件を確かめ直していない');
        $create = strpos($code, 'ReProject::create(');
        $this->assertNotFalse($create, '分譲地を作る所を見つけられない');
        $this->assertTrue($recheck < $create, '確かめ直しは分譲地を作る前に行う');
    }

    // ================================================================
    // 画面とルート（Task 7）
    // ================================================================

    private const CONVERT = 'id="convert-form"';

    private const CONFIRM = '分譲地に変えます。案件番号は分譲地の番号（RE-PRJ-…）に変わり、元の仕入れ案件は消えます（原価・区画・工程・添付は分譲地へ移ります）。元には戻せません。よろしいですか？';

    private function detailUrl(ReProcurement $procurement): string
    {
        return route('realestate.procurements.show', $procurement);
    }

    public function test_a_manager_converts_from_the_detail_screen(): void
    {
        $this->user = $this->manager();
        $p = $this->landProcurement(['procurement_code' => 'RE-PRC-001']);
        $url = $this->detailUrl($p);

        $detail = $this->htmlOf($url);
        $this->assertStringContainsString(e(self::CONFIRM), $detail);
        $form = $this->parseForm($detail, self::CONVERT);
        $this->assertSame(route('realestate.procurements.convert', $p), $form['action']);
        $this->assertLotSubmitOnce($detail, self::CONVERT, '変えています…');
        // 確認でキャンセルしたとき（onsubmit が送信を止める）は、押せないままにしない
        $this->assertStringContainsString('x-on:submit="$event.defaultPrevented || onSubmit($event)"', $detail);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '分譲地「RE-PRJ-001」に変えました（元の仕入れ案件番号 RE-PRC-001 は分譲地の詳細に残しています）。');
        $this->assertStringContainsString('元の仕入れ案件番号', $html);
        $this->assertStringContainsString('RE-PRC-001', $html);
        $this->assertSame(0, ReProcurement::count());
        $this->assertSame(1, ReProject::count());
    }

    public function test_the_list_shows_one_project_row_after_conversion(): void
    {
        $this->user = $this->manager();
        $p = $this->landProcurement(['property_name' => '勝山の土地']);
        $this->converter()->convert($p, $this->user);

        $response = $this->actingAs($this->user)->get(route('realestate.procurements.index', ['status' => '']))->assertOk();
        $rows = $response->viewData('rows');

        $this->assertSame(1, $rows->total());
        $this->assertSame('project', $rows->items()[0]->kind);
        $this->assertSame(1, substr_count($response->getContent(), '勝山の土地'));
    }

    public function test_a_refused_land_shows_a_disabled_button_and_the_reason(): void
    {
        $p = $this->landProcurement();
        $this->procurementLot($p);
        ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $p->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 0, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
        $reason = '契約 1 件' . ProcurementToProjectConverter::LINKED_SUFFIX;
        $url = $this->detailUrl($p);

        $html = $this->htmlOf($url);

        $this->assertStringNotContainsString(self::CONVERT, $html);
        $this->assertStringContainsString('<span title="' . e($reason) . '"', $html);
        $this->assertSame(1, preg_match('/<p id="convert-refusal"[^>]*>\s*' . preg_quote(e($reason), '/') . '\s*<\/p>/u', $html));
        $this->assertStringContainsString('aria-describedby="convert-refusal"', $html);

        // 手で組んだ送信も、詳細へ戻って同じ理由が出て、何も変わらない
        $landed = $this->landed($this->actingAs($this->user)->from($url)->post(route('realestate.procurements.convert', $p)));
        $this->assertLotNotice($landed, 'error', $reason);
        $this->assertSame(1, ReProcurement::count());
        $this->assertSame(0, ReProject::count());

        // 土地でない案件の詳細には、フォームもボタンも出ない
        $house = $this->procurement(['procurement_code' => 'RE-PRC-009']);
        $houseHtml = $this->htmlOf($this->detailUrl($house));
        $this->assertStringNotContainsString(self::CONVERT, $houseHtml);
        $this->assertStringNotContainsString('分譲地に変える', $houseHtml);
    }

    public function test_a_failure_midway_changes_nothing(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->user = $this->manager();
        $p = $this->landProcurement();
        $this->procurementLot($p);
        $this->step($p, '造成');
        $this->attachment(ReProcurement::class, $p->id, '図面.pdf');
        ReProcurementCost::create(['procurement_id' => $p->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 100000]);
        ReProjectLot::creating(fn () => throw new \RuntimeException('boom'));
        $url = $this->detailUrl($p);

        $html = $this->landed($this->submit($this->parseForm($this->htmlOf($url), self::CONVERT), $url));

        $this->assertLotNotice($html, 'error', '分譲地に変えられませんでした。何も変えていません。時間をおいてやり直してください。');
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'boom');
        $this->assertSame(0, ReProject::count());
        $this->assertSame(0, ReProjectLot::count());
        $this->assertSame(1, ReProcurement::count());
        $this->assertSame(1, ReProcurementLot::where('procurement_id', $p->id)->count());
        $this->assertSame(1, ReProcurementCost::where('procurement_id', $p->id)->count());
        $this->assertSame(1, $p->scheduleSteps()->count());
        $this->assertSame(1, Attachment::where('attachable_id', $p->id)->count());
    }

    /** 失敗が工程・添付の付け替えと原価・区画の削除のあと（最後の削除）で起きても、全部が元のまま戻る */
    public function test_a_failure_after_moving_steps_and_attachments_changes_nothing(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->user = $this->manager();
        $p = $this->landProcurement();
        $this->procurementLot($p);
        $step = $this->step($p, '造成');
        $attachment = $this->attachment(ReProcurement::class, $p->id, '図面.pdf');
        ReProcurementCost::create(['procurement_id' => $p->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 100000]);
        ReProcurement::deleting(fn () => throw new \RuntimeException('late boom'));
        $url = $this->detailUrl($p);

        $html = $this->landed($this->submit($this->parseForm($this->htmlOf($url), self::CONVERT), $url));

        $this->assertLotNotice($html, 'error', '分譲地に変えられませんでした。何も変えていません。時間をおいてやり直してください。');
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'late boom');
        $this->assertSame(0, ReProject::count());
        $this->assertSame(1, ReProcurement::count());
        $this->assertSame(1, ReProcurementLot::where('procurement_id', $p->id)->count());
        $this->assertSame(1, ReProcurementCost::where('procurement_id', $p->id)->count());
        $this->assertSame([ReProcurement::class, $p->id], [$step->fresh()->schedulable_type, (int) $step->fresh()->schedulable_id]);
        $this->assertSame([ReProcurement::class, $p->id], [$attachment->fresh()->attachable_type, (int) $attachment->fresh()->attachable_id]);
    }

    /** 建蔽率・容積率の小数は変えるときに落ちない（モデルの整数のキャストを通さず生の値を写す） */
    public function test_building_coverage_and_floor_area_ratio_keep_their_decimals(): void
    {
        $p = $this->landProcurement();
        \Illuminate\Support\Facades\DB::table('re_procurements')->where('id', $p->id)
            ->update(['building_coverage' => 59.50, 'floor_area_ratio' => 199.50]);

        $project = $this->converter()->convert($p->fresh(), $this->manager());

        $raw = \Illuminate\Support\Facades\DB::table('re_projects')->where('id', $project->id)->first();
        $this->assertEquals(59.5, (float) $raw->building_coverage);
        $this->assertEquals(199.5, (float) $raw->floor_area_ratio);
    }

    /** 土地でない案件は区画の欄が無いので、断りは区画の欄でなく上の帯に出る */
    public function test_a_non_land_refusal_stays_in_the_top_banner(): void
    {
        $p = $this->procurement();
        $url = $this->detailUrl($p);

        $response = $this->actingAs($this->user)->from($url)->post(route('realestate.procurements.convert', $p));

        $response->assertRedirect($url);
        $this->assertFlash($this->landed($response), 'error', ProcurementToProjectConverter::REFUSE_NOT_LAND);
    }

    public function test_sending_the_conversion_twice_lands_on_the_list(): void
    {
        $this->user = $this->manager();
        $p = $this->landProcurement();
        $url = $this->detailUrl($p);
        $form = $this->parseForm($this->htmlOf($url), self::CONVERT);

        $this->landed($this->submit($form, $url));
        $second = $this->submit($form, $url);

        $second->assertRedirect(route('realestate.procurements.index'));
        $this->assertFlash($this->landed($second), 'error', ProcurementToProjectConverter::GONE);
        $this->assertSame(1, ReProject::count());
    }

    public function test_staff_cannot_convert(): void
    {
        $p = $this->landProcurement();

        $this->actingAs($this->userWithRole(UserRole::Staff))->post(route('realestate.procurements.convert', $p))->assertForbidden();

        $this->assertSame(1, ReProcurement::count());
        $this->assertSame(0, ReProject::count());
    }

    public function test_the_project_detail_shows_the_original_code_row(): void
    {
        $this->user = $this->manager();
        $project = $this->converter()->convert($this->landProcurement(['procurement_code' => 'RE-PRC-001']), $this->user);

        // 開き直した画面（成功の帯なし）の dt と、すぐ後ろの dd を 1 つの正規表現で結ぶ
        $html = $this->htmlOf(route('realestate.projects.show', $project));
        $this->assertDoesNotMatchRegularExpression('/元の仕入れ案件番号 RE-PRC/u', $html);
        $this->assertMatchesRegularExpression('#<dt[^>]*>\s*元の仕入れ案件番号\s*</dt>\s*<dd[^>]*>\s*RE-PRC-001\s*</dd>#u', $html);

        // 線は隣の行と同じ border-b（border-t だと上の行の border-b と重なって 2px になる）
        $this->assertSame(1, preg_match('#<dt([^>]*)>\s*元の仕入れ案件番号\s*</dt>\s*<dd([^>]*)>#u', $html, $m));
        foreach ([$m[1], $m[2]] as $attrs) {
            $this->assertMatchesRegularExpression('/\bborder-b\b/', $attrs);
            $this->assertDoesNotMatchRegularExpression('/\bborder-t\b/', $attrs);
        }

        $plain = $this->project(['project_code' => 'RE-PRJ-900']);
        $this->assertStringNotContainsString('元の仕入れ案件番号', $this->htmlOf(route('realestate.projects.show', $plain)));
    }

    public function test_editing_the_project_keeps_the_original_code(): void
    {
        $this->user = $this->manager();
        $project = $this->converter()->convert($this->landProcurement(['procurement_code' => 'RE-PRC-007']), $this->user);
        $url = route('realestate.projects.edit', $project);

        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.projects.update', $project) . '"');
        $this->landed($this->submit($form, $url));

        $this->assertSame('RE-PRC-007', $project->fresh()->source_procurement_code);
    }
}
