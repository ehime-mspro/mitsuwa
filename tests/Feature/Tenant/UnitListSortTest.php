<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 部屋一覧の並び替え（設計書 §4.4 / §5.4 / §6）。
 *
 * ⚠ 「—」を末尾へ回すのは**画面に「—」と出る列だけ**。
 *   面積は末尾へ、家賃は「0円」と出るので 0 として並べる。**別々のテストで固定する**
 *   （期待する位置が正反対なので、1 本にまとめると片方の変異が素通りする）。
 */
class UnitListSortTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProperty(string $code = 'T-S001'): Property
    {
        return Property::create([
            'code'             => $code,
            'name'             => '並び替えビル',
            'property_type'    => 'tenant',
            'department'       => 'tenant',
            'operation_status' => 'active',
            'address'          => '愛媛県松山市本町1-1',
        ]);
    }

    /** 部屋を 1 つ作る。金額・面積は指定が無ければ既定値。 */
    private function makeUnit(Property $property, string $room, array $attrs = []): Unit
    {
        return Unit::create(array_merge([
            'property_id'      => $property->id,
            'floor'            => 1,
            'room_number'      => $room,
            'display_name'     => $room,
            'status'           => 'vacant',
            'area_tsubo'       => 20.00,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 200000,
        ], $attrs));
    }

    /** 月額合計の SQL 式と PHP アクセサが同じ値を出すこと（Bug #41） */
    public function test_the_monthly_total_sql_agrees_with_the_php_accessor(): void
    {
        $property = $this->makeProperty();
        $this->makeUnit($property, '101', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000]);
        $this->makeUnit($property, '102', ['rent' => 180000, 'common_fee' => 18000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $this->makeUnit($property, '103', ['rent' => 95000,  'common_fee' => 9000,  'garbage_fee' => 1500, 'pest_control_fee' => 700]);
        $this->makeUnit($property, '104', ['rent' => null,   'common_fee' => null,  'garbage_fee' => null, 'pest_control_fee' => null]);

        $fromSql = Unit::selectRaw('id, ' . Unit::MONTHLY_TOTAL_SQL . ' as total')->pluck('total', 'id')->all();

        $values = [];
        foreach (Unit::orderBy('id')->get() as $unit) {
            $this->assertSame(
                $unit->monthly_total,
                (int) $fromSql[$unit->id],
                "部屋 {$unit->room_number} の月額合計が SQL 式と PHP アクセサで食い違う"
            );
            $values[] = $unit->monthly_total;
        }

        // ⚠ SQLite は綴りを間違えたカラム参照を**例外なく 0 で返す**（Bug #40）。
        //   値に分散が無いと「SQL を全部壊しても PHP と一致」で false-pass しうるので固定する。
        $this->assertGreaterThan(1, count(array_unique($values)), '月額合計に分散が無いデータでは検出力が出ない');
        $this->assertContains(315000, $values, '4 項目すべてを足していない（285000+25000+3000+2000）');
        $this->assertSame(0, $values[3], 'NULL ばかりの部屋は COALESCE で 0 になる');
    }
}
