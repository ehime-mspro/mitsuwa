<?php

namespace Tests\Feature\Mansion;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\MsContract;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesMansionSchema;
use Tests\TestCase;

/**
 * 賃貸マンション 部屋契約の解約時に、敷金精算が保存され詳細に出ること。
 *
 * ⚠ **2026-08-17 まで、この精算は保存されていなかった。** 解約画面には原状回復費・
 *   清掃費・差引項目・解約理由の入力欄があり Alpine が返金額を計算して表示するのに、
 *   `ContractController::terminate()` は `move_out_date` と `terminate_parkings` しか
 *   受けておらず、DB にも受け皿が無かった。入力は必ず失われ、しかも
 *   「契約を解約しました」と成功表示が出ていた（Bug #38 と同型の「画面にあるのに
 *   サーバへ届かない」型）。
 *
 * ⚠ **差引合計・返金額は DB に保存しない。** 内訳から毎回積み上げる（Bug #46）。
 *   このテストは「合計だけ別ソースに差し替える」変異で赤くなる必要がある。
 */
class ContractTerminationSettlementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
        $this->seed(DepartmentSeeder::class);
    }

    private function actor(): User
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'mansion')->value('id'));

        return $user;
    }

    private function makeContract(int $deposit = 200000): MsContract
    {
        $propertyId = DB::table('ms_properties')->insertGetId([
            'property_code' => 'MS-001', 'property_name' => 'ミツワレジデンス',
            'ownership_type' => 'self_owned', 'address' => '愛媛県松山市一番町1-1',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roomId = DB::table('ms_rooms')->insertGetId([
            'property_id' => $propertyId, 'room_number' => '101', 'status' => 'occupied',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantId = DB::table('ms_tenants')->insertGetId([
            'tenant_type' => 'resident', 'name' => '山田 太郎',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return MsContract::create([
            'room_id' => $roomId, 'tenant_id' => $tenantId, 'status' => 'active',
            'move_in_date' => '2024-04-01', 'rent' => 80000, 'deposit' => $deposit,
            'created_by' => 1,
        ]);
    }

    /**
     * 解約画面が精算の入力欄を実際に描画していること。
     *
     * ⚠ **これが無いと、下の保存テストは「値を直接 POST しているだけ」になり、
     *   画面から入力欄を消しても緑のまま通る**（Bug #47）。呼び出し側（画面）と
     *   受け側（コントローラ）を対で固定する。
     */
    public function test_the_termination_screen_renders_the_settlement_inputs(): void
    {
        $contract = $this->makeContract();

        $html = $this->actingAs($this->actor())
            ->get(route('mansion.contracts.terminate.show', $contract))
            ->assertOk()
            ->getContent();

        foreach ([
            'name="restoration_cost"',
            'name="cleaning_cost"',
            'name="termination_reason"',
            'name="other_deduction_name[]"',
            'name="other_deduction_amount[]"',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $html,
                "解約画面に {$needle} が無い。サーバ側だけ実装しても入力手段が消えている"
            );
        }
    }

    /**
     * 精算値が保存され、詳細画面に内訳から積み上げて表示されること。
     *
     * ⚠ 差引項目の行は Alpine が動的生成するため描画済み HTML には出ない。
     *   ここはブラウザが送る形（並行配列）を組み立てて送っている。
     */
    public function test_settlement_is_saved_and_shown_on_the_detail_screen(): void
    {
        $contract = $this->makeContract(deposit: 200000);
        $actor    = $this->actor();

        $this->actingAs($actor)
            ->put(route('mansion.contracts.terminate', $contract), [
                'move_out_date'          => '2026-08-31',
                'termination_reason'     => '転勤のため',
                'restoration_cost'       => 50000,
                'cleaning_cost'          => 30000,
                'other_deduction_name'   => ['鍵交換費', 'エアコン清掃'],
                'other_deduction_amount' => [15000, 12000],
            ])
            ->assertRedirect(route('mansion.contracts.show', $contract));

        $contract->refresh()->load('deductions');

        $this->assertSame('転勤のため', $contract->termination_reason);
        $this->assertSame(50000, $contract->restoration_cost);
        $this->assertSame(30000, $contract->cleaning_cost);
        // 精算時点の敷金がスナップショットされている
        $this->assertSame(200000, $contract->deposit_at_settlement);

        $this->assertCount(2, $contract->deductions);
        $this->assertSame('鍵交換費', $contract->deductions[0]->name);
        $this->assertSame(15000, $contract->deductions[0]->amount);
        $this->assertSame('エアコン清掃', $contract->deductions[1]->name);
        $this->assertSame(12000, $contract->deductions[1]->amount);

        // 50000 + 30000 + 15000 + 12000 = 107,000 / 200,000 - 107,000 = 93,000
        $this->assertSame(107000, $contract->totalDeduction());
        $this->assertSame(93000, $contract->refundAmount());

        $html = $this->actingAs($actor)
            ->get(route('mansion.contracts.show', $contract))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('敷金精算', $html);
        $this->assertStringContainsString('鍵交換費', $html);
        $this->assertStringContainsString('107,000円', $html, '差引合計が内訳から積み上がっていない');
        $this->assertStringContainsString('93,000円', $html, '返金額が出ていない');
        $this->assertStringContainsString('転勤のため', $html);
    }

    /**
     * 名称と金額の**両方が揃った行だけ**保存されること。
     *
     * ⚠ 並行配列を `array_values()` で詰め直すと、名称と金額が別の行どうしで組になる。
     *   ここは「1 行目だけ完全、2 行目は名称のみ、3 行目は金額のみ」を送り、
     *   **1 行目だけがそのままの組で保存される**ことを見る。
     */
    public function test_incomplete_deduction_rows_are_dropped_and_pairs_stay_aligned(): void
    {
        $contract = $this->makeContract();

        $this->actingAs($this->actor())
            ->put(route('mansion.contracts.terminate', $contract), [
                'move_out_date'          => '2026-08-31',
                'other_deduction_name'   => ['鍵交換費', '名前だけ', ''],
                'other_deduction_amount' => [15000, '', 9999],
            ]);

        $contract->refresh()->load('deductions');

        $this->assertCount(1, $contract->deductions, '不完全な行が保存されている');
        $this->assertSame('鍵交換費', $contract->deductions[0]->name);
        $this->assertSame(15000, $contract->deductions[0]->amount, '名称と金額の組がずれている');
    }

    /**
     * 空欄は `null` で保存され、`0` と区別されること。
     *
     * ⚠ `?? 0` で潰すと「未入力」と「0 円」が同じになる。空欄は
     *   `ConvertEmptyStringsToNull` により null で届く。
     */
    public function test_blank_amounts_are_stored_as_null_not_zero(): void
    {
        $contract = $this->makeContract();

        $this->actingAs($this->actor())
            ->put(route('mansion.contracts.terminate', $contract), [
                'move_out_date'    => '2026-08-31',
                'restoration_cost' => 0,   // 明示的に 0 円
                // cleaning_cost は送らない（＝未入力）
            ]);

        $contract->refresh();

        $this->assertSame(0, $contract->restoration_cost, '明示的な 0 が消えている');
        $this->assertNull($contract->cleaning_cost, '未入力が 0 に化けている');
    }

    /** 精算の記録が無ければ詳細にカードを出さない／未解約でも出さない */
    public function test_the_settlement_card_is_hidden_when_there_is_nothing_to_show(): void
    {
        $contract = $this->makeContract();
        $actor    = $this->actor();

        // 未解約
        $html = $this->actingAs($actor)->get(route('mansion.contracts.show', $contract))->getContent();
        $this->assertStringNotContainsString('敷金精算', $html, '未解約なのに精算カードが出ている');

        // 解約したが精算は未入力
        $this->actingAs($actor)->put(route('mansion.contracts.terminate', $contract), [
            'move_out_date' => '2026-08-31',
        ]);

        $html = $this->actingAs($actor)->get(route('mansion.contracts.show', $contract))->getContent();
        $this->assertStringContainsString(
            '敷金精算',
            $html,
            '敷金のスナップショットは常に入るのでカードは出る（内訳が空でも「返金額 = 敷金」を示す）'
        );
    }

    /** 既存の解約挙動を壊していないこと（部屋が空きに戻る） */
    public function test_terminating_still_frees_the_room(): void
    {
        $contract = $this->makeContract();

        $this->actingAs($this->actor())
            ->put(route('mansion.contracts.terminate', $contract), [
                'move_out_date'    => '2026-08-31',
                'restoration_cost' => 50000,
            ]);

        $contract->refresh();

        $this->assertTrue($contract->isTerminated());
        $this->assertSame('2026-08-31', $contract->move_out_date->format('Y-m-d'));
        $this->assertSame('vacant', DB::table('ms_rooms')->where('id', $contract->room_id)->value('status'));
    }
}
