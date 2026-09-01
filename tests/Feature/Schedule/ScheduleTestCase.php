<?php

namespace Tests\Feature\Schedule;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 工程表の Feature テスト共通土台。
 *
 * ⚠ 4 親を対称に作れるようにしておく。「代表 1 種だけ」でテストを書くと、
 *   残り 3 種の経路が一度も実行されないまま緑になる（Bug #44 で 3 回続けて踏んでいる）。
 */
abstract class ScheduleTestCase extends TestCase
{
    use ParsesForms;

    private bool $departmentsSeeded = false;

    /** 4 親のラベル => [モデルのクラス, ルート名の接頭辞, 部署] */
    protected const PARENTS = [
        'procurement' => [ReProcurement::class, 'realestate.procurements', 'realestate'],
        'project'     => [ReProject::class,     'realestate.projects',     'realestate'],
        'property'    => [HsProperty::class,    'housing.properties',      'housing'],
        'customOrder' => [HsCustomOrder::class, 'housing.custom-orders',   'housing'],
    ];

    /**
     * ⚠ DepartmentSeeder は Department::create() なので冪等ではない。1 度だけ流す。
     *
     * @param  list<string>  $departments  所属させる部署コード
     */
    protected function actor(UserRole $role, array $departments = ['realestate', 'housing']): User
    {
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }

        $user = User::factory()->create([
            'role'                 => $role->value,
            'must_change_password' => false,
        ]);

        foreach ($departments as $code) {
            $user->departments()->attach(Department::where('code', $code)->value('id'));
        }

        return $user;
    }

    protected function manager(array $departments = ['realestate', 'housing']): User
    {
        return $this->actor(UserRole::Manager, $departments);
    }

    protected function staff(array $departments = ['realestate', 'housing']): User
    {
        return $this->actor(UserRole::Staff, $departments);
    }

    /** 親をラベルで作る。列名が親ごとに違うのでここで吸収する。 */
    protected function makeParent(string $label, array $attrs = []): \Illuminate\Database\Eloquent\Model
    {
        $base = match ($label) {
            'procurement' => [
                'procurement_code' => 'PRC-001',
                'property_type'    => 'used_house',
                'transaction_type' => 'purchase',
                'status'           => 'contracted',
                'property_name'    => '井門町 更地',
                'address'          => '愛媛県松山市1-1-1',
                'created_by'       => 1,
            ],
            'project' => [
                'project_code' => 'PRJ-001',
                'project_name' => '余戸南 分譲地',
                'status'       => 'selling',
                'address'      => '愛媛県松山市2-2-2',
                'created_by'   => 1,
            ],
            'property' => [
                'property_code' => 'HS-001',
                'property_name' => '余戸南 3号地',
                'status'        => 'construction',
                'address'       => '愛媛県松山市3-3-3',
                'created_by'    => 1,
            ],
            'customOrder' => [
                'order_code'    => 'CO-001',
                'order_name'    => '松山市 T様邸',
                'status'        => 'construction',
                'customer_name' => 'T様',
                'address'       => '愛媛県松山市4-4-4',
                'created_by'    => 1,
            ],
        };

        [$class] = self::PARENTS[$label];

        return $class::create(array_merge($base, $attrs));
    }

    /** 工程の妥当な入力一式 */
    protected function stepInput(array $overrides = []): array
    {
        return array_merge([
            'name'          => '建築確認申請',
            'category'      => 'permit',
            'planned_start' => '2026-05-11',
            'planned_end'   => '2026-06-05',
            'actual_start'  => null,
            'actual_end'    => null,
            'notes'         => '',
        ], $overrides);
    }
}
