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

    /** 周辺ビル調査の取込の画面 */
    protected const IMPORT_URL = '/tenant/area-buildings/import';

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

    // ============================================================
    // 取込（AreaBuildingImportTest と AreaBuildingTenantReimportTest で共用。2026-10-01 に AreaBuildingImportTest から移した）
    // ============================================================

    /** 取込の画面を開き、画面が描いたフォームを分解する */
    protected function importForm($user): array
    {
        $html = $this->actingAs($user)->get(self::IMPORT_URL)->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('tenant.area-buildings.import.execute') . '"');
    }

    /**
     * 取込の画面を開き、画面が描いたフォームに Alpine が入れる 3 つ（kind・surveyed_month・rows）だけを埋めて送る。
     * ⚠ 鍵（import_token）は画面が描いたものを使う。手で組んで送ると鍵が無くて断られ、断られても戻り先が
     *   取込の画面なので、戻り先だけを見るテストは緑のまま狙った経路を通らない（設計書 2026-09-28-import-double-submit-design.md §5.3）
     * ⚠ 呼ぶたびに取込の画面を開き直す（新しい鍵）。2 回呼ぶと「上げ直し」になる
     */
    protected function sendImport(array $fields)
    {
        $manager = $this->manager();
        $form    = $this->importForm($manager);

        return $this->actingAs($manager)->post($form['action'], array_merge($form['fields'], $fields));
    }

    /** テナント明細のとき、画面の surveyed_month の hidden は ''（`kind === 'buildings' ? surveyedMonth : ''`） */
    protected function importTenants(array $rows)
    {
        return $this->sendImport(['kind' => 'tenants', 'surveyed_month' => '', 'rows' => json_encode($rows)]);
    }

    /** ページャに載った行のビル名（表示順のまま） */
    protected function listedNames(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->viewData('rows')->items())
            ->map(fn (array $row) => $row['building']->name)
            ->all();
    }

}
