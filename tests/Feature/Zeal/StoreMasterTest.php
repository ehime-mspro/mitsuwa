<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealStore;
use Tests\Concerns\DrivesAlpineFetch;

/**
 * 店舗マスタ（Zeal\StoreController。追加・更新・削除は Ajax）。
 *
 * 画面の JS（zealStoreManager）を node で動かし、JS が組んだ要求をそのまま送る。返った応答を JS に戻して、
 * 画面の一覧とメッセージまで見る（DrivesAlpineFetch）。
 */
class StoreMasterTest extends MemberScreenTestCase
{
    use DrivesAlpineFetch;

    private function indexHtml(): string
    {
        return $this->actingAs($this->user)->get(route('zeal.stores.index'))->assertOk()->getContent();
    }

    /** 画面の JS を $steps で操り、組まれた要求（ちょうど 1 つ）を送って、応答を JS に戻した後の状態を返す */
    private function roundTrip(string $steps): array
    {
        $html = $this->indexHtml();
        $factory = $this->xData($html, 'zealStoreManager');

        $sent = $this->driveAlpine($html, 'zealStoreManager', $factory, $steps);
        $this->assertCount(1, $sent['requests'], '画面の JS が要求を 1 つ組まなかった');
        $request = $sent['requests'][0];
        $this->assertSame('application/json', $request['headers']['Accept'] ?? null);
        $this->assertSame('XMLHttpRequest', $request['headers']['X-Requested-With'] ?? null);

        $response = $this->actingAs($this->user)->sendCaptured($request);
        $after = $this->driveAlpine($html, 'zealStoreManager', $factory, $steps, [$this->asFetchResponse($response)]);

        return ['request' => $request, 'response' => $response, 'state' => $after['state'], 'confirms' => $after['confirms']];
    }

    public function test_the_list_hands_the_stores_to_the_screen(): void
    {
        $html = $this->indexHtml();

        $state = $this->driveAlpine($html, 'zealStoreManager', $this->xData($html, 'zealStoreManager'), '')['state'];
        $this->assertSame(['松山市駅前店'], array_column($state['stores'], 'name'));
        $this->assertSame(2, $state['newOrder'], '新規の表示順が「今の最大 + 1」でない');
    }

    public function test_a_store_is_added_from_the_screen(): void
    {
        $trip = $this->roundTrip("data.startAdd(); data.newName = '  大街道店 '; data.newAddress = '松山市大街道1-1';"
            . " data.newPhone = '089-000-0000'; data.newOpenDate = '2026-11-01'; data.submitAdd();");

        $this->assertSame('POST', $trip['request']['method']);
        $this->assertSame(route('zeal.stores.store'), $trip['request']['url']);
        $trip['response']->assertOk();
        $store = ZealStore::where('name', '大街道店')->firstOrFail();
        $this->assertSame('松山市大街道1-1', $store->address);
        $this->assertSame('089-000-0000', $store->phone);
        $this->assertSame('2026-11-01', $store->open_date->format('Y-m-d'));
        $this->assertSame(2, $store->display_order);
        $this->assertTrue($store->active);

        $this->assertSame(['松山市駅前店', '大街道店'], array_column($trip['state']['stores'], 'name'));
        $this->assertSame('「大街道店」を追加しました。', $trip['state']['message']);
        $this->assertSame('success', $trip['state']['messageType']);
        $this->assertFalse($trip['state']['adding'], '追加のあとも入力欄が開いたまま');
    }

    /** 住所・電話・開店日を空にした追加は、空のまま入る（JS は空文字を送る） */
    public function test_a_store_with_only_a_name_is_added(): void
    {
        $trip = $this->roundTrip("data.startAdd(); data.newName = '名前だけ店'; data.submitAdd();");

        $trip['response']->assertOk();
        $store = ZealStore::where('name', '名前だけ店')->firstOrFail();
        $this->assertNull($store->address);
        $this->assertNull($store->phone);
        $this->assertNull($store->open_date);
    }

    public function test_a_store_is_updated_from_the_screen(): void
    {
        $id = ZealStore::where('name', '松山市駅前店')->value('id');

        $trip = $this->roundTrip("data.startEdit(data.stores[0]); data.editingName = '松山市駅前本店'; data.editingPhone = '089-111-2222';"
            . " data.editingOrder = 7; data.editingActive = false; data.submitEdit();");

        $this->assertSame(route('zeal.stores.update', $id), $trip['request']['url']);
        $this->assertStringContainsString('_method=PUT', (string) $trip['request']['body']);
        $trip['response']->assertOk();
        $store = ZealStore::findOrFail($id);
        $this->assertSame('松山市駅前本店', $store->name);
        $this->assertSame('089-111-2222', $store->phone);
        $this->assertSame(7, $store->display_order);
        $this->assertFalse($store->active);

        $this->assertSame('松山市駅前本店', $trip['state']['stores'][0]['name']);
        $this->assertFalse($trip['state']['stores'][0]['active']);
        $this->assertSame('「松山市駅前本店」を更新しました。', $trip['state']['message']);
        $this->assertNull($trip['state']['editingId'], '更新のあとも編集中のまま');
    }

    public function test_a_store_without_members_is_deleted_after_confirmation(): void
    {
        $spare = ZealStore::create(['name' => '閉店予定店', 'display_order' => 2, 'active' => true]);

        $trip = $this->roundTrip("data.deleteStore(data.stores.find(function (s) { return s.id === {$spare->id}; }));");

        $this->assertSame(["「閉店予定店」を削除しますか？\n所属会員がいる場合は削除できません。"], $trip['confirms']);
        $this->assertSame(route('zeal.stores.destroy', $spare), $trip['request']['url']);
        $this->assertStringContainsString('_method=DELETE', (string) $trip['request']['body']);
        $trip['response']->assertOk();
        $this->assertNull(ZealStore::find($spare->id));
        $this->assertSame(['松山市駅前店'], array_column($trip['state']['stores'], 'name'));
        $this->assertSame('「閉店予定店」を削除しました。', $trip['state']['message']);
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        $html = $this->indexHtml();

        $run = $this->driveAlpine($html, 'zealStoreManager', $this->xData($html, 'zealStoreManager'), 'data.deleteStore(data.stores[0]);', [], false);

        $this->assertSame([], $run['requests']);
    }

    /** 所属会員がいる店舗は消さず、サーバの理由が画面に出る */
    public function test_a_store_with_members_is_not_deleted_and_the_reason_is_shown(): void
    {
        $trip = $this->roundTrip('data.deleteStore(data.stores[0]);');

        $trip['response']->assertStatus(422);
        $this->assertNotNull(ZealStore::where('name', '松山市駅前店')->first());
        $this->assertSame('「松山市駅前店」には所属会員がいるため削除できません。「無効」に変更してご利用ください。', $trip['state']['message']);
        $this->assertSame('error', $trip['state']['messageType']);
        $this->assertSame(['松山市駅前店'], array_column($trip['state']['stores'], 'name'));
    }

    /** 入力チェックで断られたときは、項目ごとの理由が画面に出る */
    public function test_a_rejected_store_shows_the_reasons(): void
    {
        $trip = $this->roundTrip("data.startAdd(); data.newName = '電話が長い店'; data.newPhone = '0'.repeat(21); data.submitAdd();");

        $trip['response']->assertStatus(422);
        $this->assertNull(ZealStore::where('name', '電話が長い店')->first());
        $this->assertSame('error', $trip['state']['messageType']);
        $this->assertStringContainsString(trans('validation.max.string', ['attribute' => trans('validation.attributes.phone'), 'max' => 20]), $trip['state']['message']);
    }
}
