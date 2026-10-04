<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealTrainer;
use Tests\Concerns\DrivesAlpineFetch;

/**
 * トレーナーマスタ（Zeal\TrainerController。追加・更新・削除は Ajax）。
 *
 * 画面の JS（zealTrainerManager）を node で動かし、JS が組んだ要求をそのまま送る。返った応答を JS に戻して、
 * 画面の一覧とメッセージまで見る（DrivesAlpineFetch。StoreMasterTest と同じ形）。
 */
class TrainerMasterTest extends MemberScreenTestCase
{
    use DrivesAlpineFetch;

    private ZealTrainer $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trainer = ZealTrainer::create(['name' => '佐藤 健', 'display_order' => 1, 'active' => true]);
    }

    private function indexHtml(): string
    {
        return $this->actingAs($this->user)->get(route('zeal.trainers.index'))->assertOk()->getContent();
    }

    /** 画面の JS を $steps で操り、組まれた要求（ちょうど 1 つ）を送って、応答を JS に戻した後の状態を返す */
    private function roundTrip(string $steps): array
    {
        $html = $this->indexHtml();
        $factory = $this->xData($html, 'zealTrainerManager');

        $sent = $this->driveAlpine($html, 'zealTrainerManager', $factory, $steps);
        $this->assertCount(1, $sent['requests'], '画面の JS が要求を 1 つ組まなかった');
        $request = $sent['requests'][0];
        $this->assertSame('application/json', $request['headers']['Accept'] ?? null);
        $this->assertSame('XMLHttpRequest', $request['headers']['X-Requested-With'] ?? null);

        $response = $this->actingAs($this->user)->sendCaptured($request);
        $after = $this->driveAlpine($html, 'zealTrainerManager', $factory, $steps, [$this->asFetchResponse($response)]);

        return ['request' => $request, 'response' => $response, 'state' => $after['state'], 'confirms' => $after['confirms']];
    }

    public function test_the_list_hands_the_trainers_to_the_screen(): void
    {
        $html = $this->indexHtml();

        $state = $this->driveAlpine($html, 'zealTrainerManager', $this->xData($html, 'zealTrainerManager'), '')['state'];
        $this->assertSame(['佐藤 健'], array_column($state['trainers'], 'name'));
        $this->assertSame(2, $state['newOrder'], '新規の表示順が「今の最大 + 1」でない');
    }

    public function test_a_trainer_is_added_from_the_screen(): void
    {
        $trip = $this->roundTrip("data.startAdd(); data.newName = ' 田中 美咲 '; data.submitAdd();");

        $this->assertSame('POST', $trip['request']['method']);
        $this->assertSame(route('zeal.trainers.store'), $trip['request']['url']);
        $trip['response']->assertOk();
        $trainer = ZealTrainer::where('name', '田中 美咲')->firstOrFail();
        $this->assertSame(2, $trainer->display_order);
        $this->assertTrue($trainer->active);

        $this->assertSame(['佐藤 健', '田中 美咲'], array_column($trip['state']['trainers'], 'name'));
        $this->assertSame('「田中 美咲」を追加しました。', $trip['state']['message']);
        $this->assertSame('success', $trip['state']['messageType']);
        $this->assertFalse($trip['state']['adding'], '追加のあとも入力欄が開いたまま');
    }

    public function test_a_trainer_is_updated_from_the_screen(): void
    {
        $trip = $this->roundTrip("data.startEdit(data.trainers[0]); data.editingName = '佐藤 健太'; data.editingOrder = 9;"
            . ' data.editingActive = false; data.submitEdit();');

        $this->assertSame(route('zeal.trainers.update', $this->trainer), $trip['request']['url']);
        $this->assertStringContainsString('_method=PUT', (string) $trip['request']['body']);
        $trip['response']->assertOk();
        $trainer = $this->trainer->fresh();
        $this->assertSame('佐藤 健太', $trainer->name);
        $this->assertSame(9, $trainer->display_order);
        $this->assertFalse($trainer->active);

        $this->assertSame('佐藤 健太', $trip['state']['trainers'][0]['name']);
        $this->assertFalse($trip['state']['trainers'][0]['active']);
        $this->assertSame('「佐藤 健太」を更新しました。', $trip['state']['message']);
        $this->assertNull($trip['state']['editingId'], '更新のあとも編集中のまま');
    }

    public function test_a_trainer_without_members_is_deleted_after_confirmation(): void
    {
        $trip = $this->roundTrip('data.deleteTrainer(data.trainers[0]);');

        $this->assertCount(1, $trip['confirms']);
        $this->assertStringContainsString('「佐藤 健」を削除しますか？', $trip['confirms'][0]);
        $this->assertSame(route('zeal.trainers.destroy', $this->trainer), $trip['request']['url']);
        $this->assertStringContainsString('_method=DELETE', (string) $trip['request']['body']);
        $trip['response']->assertOk();
        $this->assertNull(ZealTrainer::find($this->trainer->id));
        $this->assertSame([], $trip['state']['trainers']);
        $this->assertSame('「佐藤 健」を削除しました。', $trip['state']['message']);
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        $html = $this->indexHtml();

        $run = $this->driveAlpine($html, 'zealTrainerManager', $this->xData($html, 'zealTrainerManager'), 'data.deleteTrainer(data.trainers[0]);', [], false);

        $this->assertSame([], $run['requests']);
    }

    /** 担当会員がいるトレーナーは消さず、サーバの理由が画面に出る */
    public function test_a_trainer_with_members_is_not_deleted_and_the_reason_is_shown(): void
    {
        $this->member->update(['trainer_id' => $this->trainer->id]);

        $trip = $this->roundTrip('data.deleteTrainer(data.trainers[0]);');

        $trip['response']->assertStatus(422);
        $this->assertNotNull(ZealTrainer::find($this->trainer->id));
        $this->assertSame('「佐藤 健」には担当会員がいるため削除できません。「無効」に変更してご利用ください。', $trip['state']['message']);
        $this->assertSame('error', $trip['state']['messageType']);
    }
}
