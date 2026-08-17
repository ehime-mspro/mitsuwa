<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 周辺ビル調査の Feature テスト共通土台。
 *
 * ⚠ ファイル名が *Test.php ではないので PHPUnit のテスト探索には引っかからない（意図どおり）。
 *
 * ⚠ `parseForm()` 系は 2026-08-17 に `Tests\Concerns\ParsesForms` へ移した
 *   （認証まわりでも往復テストが要るため）。呼び出し方は変わっていない。
 */
abstract class AreaBuildingTestCase extends TestCase
{
    use ParsesForms;

    private bool $departmentsSeeded = false;

    /**
     * tenant 部門所属のユーザー。department.access:tenant を通過させ、
     * 403 が role ゲート由来であることを保証する。
     */
    protected function actor(UserRole $role): User
    {
        // ⚠ DepartmentSeeder は Department::create() なので冪等ではない。1 度だけ流す。
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }

        $user = User::factory()->create([
            'role'                 => $role->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'tenant')->value('id'));

        return $user;
    }

    protected function executive(): User
    {
        return $this->actor(UserRole::Executive);
    }

    protected function manager(): User
    {
        return $this->actor(UserRole::Manager);
    }

    protected function staff(): User
    {
        return $this->actor(UserRole::Staff);
    }

    protected function makeBuilding(string $name, array $attributes = []): AreaBuilding
    {
        return AreaBuilding::create(array_merge(['name' => $name], $attributes));
    }

    protected function makeSurvey(
        AreaBuilding $building,
        string $month,
        int $operating,
        int $vacant,
        int $unknown = 0,
        array $extra = []
    ): AreaBuildingSurvey {
        return AreaBuildingSurvey::create(array_merge([
            'area_building_id' => $building->id,
            'surveyed_month'   => $month,
            'operating_count'  => $operating,
            'vacant_count'     => $vacant,
            'unknown_count'    => $unknown,
        ], $extra));
    }

    protected function makeTenant(AreaBuilding $building, array $attributes = []): AreaBuildingTenant
    {
        return AreaBuildingTenant::create(array_merge([
            'area_building_id' => $building->id,
            'status'           => 'operating',
        ], $attributes));
    }

    /** ページャに載った行のビル名（表示順のまま） */
    protected function listedNames(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->viewData('rows')->items())
            ->map(fn (array $row) => $row['building']->name)
            ->all();
    }

}
