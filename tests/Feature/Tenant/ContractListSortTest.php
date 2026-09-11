<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * テナント契約一覧の既定順と見出しの並び替え（設計書 2026-09-11）。
 *
 * ⚠ 既定の絞り込みは「契約中」。解約済みを含めて並びを見るときは ?status=all を付ける。
 * ⚠ 物件名は A館 / B館 / C館（先頭が ASCII 大文字）。SQLite のバイト順と本番 MySQL の
 *   utf8mb4_unicode_ci で順序が一致する（設計書 §4.4）。漢字で始まる名前にすると
 *   本番とテストで順が変わりうる。
 * ⚠ **作成順（＝ id 順）を、既定順・並び替えた順のどれとも食い違わせる。** SQLite は同点の行を
 *   id の昇順で返す（2026-09-11 実測）ので、揃えるとキーを 1 つ消す変異が「たまたま同じ順」で
 *   素通りする（UnitListSortTest::test_tied_rows_keep_the_default_order と同じ理屈）。
 *   各テストは作成順を assertSame で先に固定してから並びを見る。
 * ⚠ 経営層は department.access を素通りする（部門の紐付けは要らない）。
 */
class ContractListSortTest extends TestCase
{
    use ParsesForms;
    use ParsesSortLinks;
    use RefreshDatabase;

    private ?Customer $customer = null;

    private int $seq = 0;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 契約に要る顧客（テストの SQLite では customer_id が NOT NULL。1 件を使い回す） */
    private function customer(): Customer
    {
        return $this->customer ??= Customer::create([
            'code' => 'CUST-CS001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
    }

    /** ⚠ $name は SQLite と MySQL で順序が一致するもの（クラスの docblock） */
    private function makeProperty(string $name): Property
    {
        return Property::create([
            'code' => sprintf('T-CS%03d', ++$this->seq),
            'name' => $name,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'operation_status' => 'active',
            'address' => '愛媛県松山市本町1-1',
        ]);
    }

    /** display_name は本番と同じ Unit::generateDisplayName() で作る（UNIQUE(property_id, display_name)） */
    private function makeUnit(Property $property, ?int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => 'occupied',
        ]);
    }

    /**
     * 契約を 1 件作る。家賃発生日は契約日と同じ（テストの SQLite では NOT NULL）。
     *
     * @param  array<string, mixed>  $attrs  rent / common_fee / status / store_name などの上書き
     */
    private function makeContract(Unit $unit, string $contractDate, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'contract_number'  => sprintf('C-CS-%03d', ++$this->seq),
            'department'       => 'tenant',
            'property_id'      => $unit->property_id,
            'unit_id'          => $unit->id,
            'customer_id'      => $this->customer()->id,
            'status'           => 'active',
            'contract_date'    => $contractDate,
            'rent_start_date'  => $contractDate,
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 作成順が id 順であることを固定する（崩れると各テストの「変異の検出力」の前提が成立しない） */
    private function assertCreatedInOrder(array $contracts): void
    {
        $this->assertSame(
            array_map(fn (Contract $c) => $c->id, $contracts),
            Contract::orderBy('id')->pluck('id')->all(),
            '作成順が id 順になっていない（テストの前提が崩れている）'
        );
    }

    /** 1 ページ目の契約 ID（表示順のまま） */
    private function listedIds(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('contracts')->pluck('id')->all();
    }

    /** 契約の ID の並び */
    private function ids(Contract ...$contracts): array
    {
        return array_map(fn (Contract $c) => $c->id, $contracts);
    }

    /**
     * 賃料収入の SQL 式と PHP アクセサが同じ値を出すこと（Bug #41。設計書 §7.1）。
     *
     * ⚠ 片方だけ直すと、画面の数字は正しいのに並び順だけが別の値で並ぶ。画面から気づけない。
     * ⚠ 共益費・ゴミ代・駆除代が NULL の行を必ず含める。0 で作ると COALESCE の経路を
     *   一度も通らず、COALESCE を外す変異が素通りする。
     * ⚠ (int) キャストは NULL を 0 に潰すので、キャストの前に NULL でないことを見る
     *   （UnitListSortTest と同じ。COALESCE を全部外す変異が緑のまま通った前例がある）。
     */
    public function test_the_income_sql_agrees_with_the_php_accessor(): void
    {
        $property = $this->makeProperty('A館');
        $this->makeContract($this->makeUnit($property, 1, 'A'), '2026-04-01', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000]);
        $this->makeContract($this->makeUnit($property, 1, 'B'), '2026-04-01', ['rent' => 180000, 'common_fee' => 18000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $nulls = $this->makeContract($this->makeUnit($property, 2, 'A'), '2026-04-01', ['rent' => 95000, 'common_fee' => null, 'garbage_fee' => null, 'pest_control_fee' => null]);
        $this->makeContract($this->makeUnit($property, 2, 'B'), '2026-04-01', ['rent' => 120000, 'common_fee' => 0, 'garbage_fee' => null, 'pest_control_fee' => 700]);

        $fresh = $nulls->fresh();
        $this->assertNull($fresh->common_fee, '共益費が NULL のデータになっていない（COALESCE の経路を通らない）');
        $this->assertNull($fresh->garbage_fee);
        $this->assertNull($fresh->pest_control_fee);

        $fromSql = Contract::selectRaw('id, ' . Contract::MONTHLY_TOTAL_SQL . ' as total')->pluck('total', 'id')->all();

        $values = [];
        foreach (Contract::orderBy('id')->get() as $contract) {
            $this->assertNotNull($fromSql[$contract->id], 'COALESCE が外れて式が NULL になっている');
            $this->assertSame(
                $contract->monthly_total,
                (int) $fromSql[$contract->id],
                "契約 {$contract->contract_number} の月額合計が SQL 式と PHP アクセサで食い違う"
            );
            $values[] = $contract->monthly_total;
        }

        // ⚠ 値に分散が無いと「SQL を壊しても PHP と一致」で false-pass しうる（Bug #40）
        $this->assertGreaterThan(1, count(array_unique($values)), '月額合計に分散が無いデータでは検出力が出ない');
        $this->assertContains(315000, $values, '4 項目すべてを足していない（285000+25000+3000+2000）');
        $this->assertContains(95000, $values, 'NULL の項目を 0 として足していない');
        $this->assertContains(120700, $values, '駆除代を足していない（120000+0+NULL+700）');
    }
}
