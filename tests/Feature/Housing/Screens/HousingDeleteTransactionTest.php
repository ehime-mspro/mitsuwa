<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsCustomOrderFile;
use App\Models\HsProperty;
use App\Models\HsPropertyFile;
use App\Models\ScheduleStep;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FailsImportWrites;

/**
 * 住宅事業の削除（建売の契約・注文住宅・建売）は、区画を戻すことと削除を 1 つのトランザクションで行い、
 * ファイルの中身は確定したあとで消す（docs/RULES.md Bug #147 の追記）。
 *
 * 直す前は、建売の契約の削除と注文住宅の削除がトランザクションの外で、途中で落ちると
 * 「契約は消えたのに区画が成約のまま」「注文住宅は残るのに区画は販売中・工程とファイルの中身は消えた」になった。
 * 建売の削除はトランザクションの中だったが、ファイルの中身をその前に消していた。
 *
 * ⚠ 削除は詳細画面が描いた削除のフォーム（DELETE）を送る（Bug #47）。
 * ⚠ 失敗は 2 通りで起こす: モデルの deleting で例外（削除そのものが落ちる）／ 削除のあとの SQL を
 *   DB::beforeExecuting で断る（FailsImportWrites::failQuery()。区画を戻した「あと」で落ちる形を作る）。
 */
class HousingDeleteTransactionTest extends HousingScreenTestCase
{
    use FailsImportWrites;

    /** 詳細画面が描いた削除のフォームを送る（$action はフォームの送り先） */
    private function deleteFrom(string $show, string $action): TestResponse
    {
        $form = $this->parseForm($this->htmlOf($show), 'action="' . $action . '"');
        $this->assertSame('DELETE', $form['method']);

        return $this->submit($form, $show);
    }

    /** 削除のフォームを送り、途中の例外がそのまま投げられることを確かめる（今どおり 500 のまま） */
    private function assertDeleteFails(string $show, string $action, string $exception): void
    {
        $this->withoutExceptionHandling();
        try {
            $this->deleteFrom($show, $action);
            $this->fail('削除の途中の例外が投げられていない');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
    }

    /** 公開のディスクに置いたファイルと、その行（建売） */
    private function propertyFile(HsProperty $property): string
    {
        $path = 'housing-files/' . $property->id . '/a.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4');
        HsPropertyFile::create([
            'property_id' => $property->id, 'category' => 'other', 'file_name' => '図面.pdf', 'file_path' => $path,
            'file_size' => 8, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->user->id,
        ]);

        return $path;
    }

    /** 公開のディスクに置いたファイルと、その行（注文住宅） */
    private function customOrderFile(HsCustomOrder $order): string
    {
        $path = 'custom-order-files/' . $order->id . '/a.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4');
        HsCustomOrderFile::create([
            'custom_order_id' => $order->id, 'category' => 'estimate', 'file_name' => '見積書.pdf', 'file_path' => $path,
            'file_size' => 8, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->user->id,
        ]);

        return $path;
    }

    // ============================================================
    // 建売の契約の削除（Housing\ContractController::destroy）
    // ============================================================

    /** 区画を販売中に戻したあと、土地の仕入れ案件のステータスの集約で落ちたら、契約も区画も元のまま */
    public function test_a_contract_delete_that_fails_after_releasing_the_land_lot_keeps_everything(): void
    {
        $land = $this->landProcurement(['status' => 'sold']);
        $lot = $this->procurementLot($land, ['status' => 'sold']);
        $property = $this->property(['land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id]);
        $contract = $this->contract($property, $this->buyer());
        $this->failQuery('/^update "re_procurements" /i');

        $this->assertDeleteFails(route('housing.properties.show', $property), route('housing.contracts.destroy', $property), QueryException::class);

        $this->assertNotNull(HsContract::find($contract->id), '区画を戻す途中で落ちたのに契約が消えた');
        $this->assertSame(['sold', 'sold'], [$lot->fresh()->status->value, $land->fresh()->status->value], '区画を戻す途中で落ちたのに区画が販売中になった');
    }

    /** 分譲地の区画を販売中に戻したあと、分譲地のステータスの集約で落ちたら、契約も区画も元のまま */
    public function test_a_contract_delete_that_fails_after_releasing_the_project_lot_keeps_everything(): void
    {
        $project = $this->project(['status' => 'sold_out']);
        $lot = $this->lot($project, 1, 12000000, 'sold');
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id]);
        $contract = $this->contract($property, $this->buyer());
        $this->failQuery('/^update "re_projects" /i');

        $this->assertDeleteFails(route('housing.properties.show', $property), route('housing.contracts.destroy', $property), QueryException::class);

        $this->assertNotNull(HsContract::find($contract->id), '区画を戻す途中で落ちたのに契約が消えた');
        $this->assertSame(['sold', 'sold_out'], [$lot->fresh()->status->value, $project->fresh()->status->value], '区画を戻す途中で落ちたのに区画が販売中になった');
    }

    // ============================================================
    // 注文住宅の削除（Housing\CustomOrderController::destroy）
    // ============================================================

    /** 契約済みの注文住宅（土地の区画 1 つ・工程 1 つ・ファイル 1 つ） */
    private function contractedOrder(): array
    {
        Storage::fake('public');
        $land = $this->landProcurement(['status' => 'sold']);
        $lot = $this->procurementLot($land, ['status' => 'sold']);
        $order = $this->customOrder([
            'status' => 'contracted', 'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id,
        ]);
        $order->scheduleSteps()->create(['name' => '地鎮祭', 'sort_order' => 1]);
        $path = $this->customOrderFile($order);

        return [$land, $lot, $order, $path];
    }

    public function test_a_custom_order_delete_that_fails_keeps_the_lot_the_steps_and_the_file(): void
    {
        [$land, $lot, $order, $path] = $this->contractedOrder();
        HsCustomOrder::deleting(fn () => throw new \RuntimeException('削除の途中で落ちた'));

        $this->assertDeleteFails(route('housing.custom-orders.show', $order), route('housing.custom-orders.destroy', $order), \RuntimeException::class);

        $this->assertNotNull(HsCustomOrder::find($order->id));
        $this->assertSame(['sold', 'sold'], [$lot->fresh()->status->value, $land->fresh()->status->value], '削除が落ちたのに区画が販売中になった');
        $this->assertSame(1, ScheduleStep::where('schedulable_type', HsCustomOrder::class)->where('schedulable_id', $order->id)->count(), '削除が落ちたのに工程が消えた');
        Storage::disk('public')->assertExists($path, '%PDF-1.4');
    }

    /** 削除が確定したら、ファイルの中身も消える（確定のあとで消すのを書き忘れない） */
    public function test_a_custom_order_delete_removes_the_file(): void
    {
        [$land, $lot, $order, $path] = $this->contractedOrder();

        $this->deleteFrom(route('housing.custom-orders.show', $order), route('housing.custom-orders.destroy', $order))
            ->assertRedirect(route('housing.custom-orders.index'));

        $this->assertNull(HsCustomOrder::find($order->id));
        $this->assertSame(['on_sale', 'selling'], [$lot->fresh()->status->value, $land->fresh()->status->value]);
        Storage::disk('public')->assertMissing($path);
    }

    // ============================================================
    // 建売の削除（Housing\PropertyController::destroy）
    // ============================================================

    public function test_a_property_delete_that_fails_keeps_the_file(): void
    {
        Storage::fake('public');
        $property = $this->property();
        $path = $this->propertyFile($property);
        HsProperty::deleting(fn () => throw new \RuntimeException('削除の途中で落ちた'));

        $this->assertDeleteFails(route('housing.properties.show', $property), route('housing.properties.destroy', $property), \RuntimeException::class);

        $this->assertNotNull(HsProperty::find($property->id));
        Storage::disk('public')->assertExists($path, '%PDF-1.4');
    }

    /** 削除が確定したら、ファイルの中身も消える（確定のあとで消すのを書き忘れない） */
    public function test_a_property_delete_removes_the_file(): void
    {
        Storage::fake('public');
        $property = $this->property();
        $path = $this->propertyFile($property);

        $this->deleteFrom(route('housing.properties.show', $property), route('housing.properties.destroy', $property))
            ->assertRedirect(route('housing.properties.index'));

        $this->assertNull(HsProperty::find($property->id));
        Storage::disk('public')->assertMissing($path);
    }
}
