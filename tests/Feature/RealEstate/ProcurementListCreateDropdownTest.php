<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use App\Models\ZoningType;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件一覧から分譲地を新規登録する導線の検証。
 *
 * - 一覧ヘッダーの「新規登録」ドロップダウンに 2 経路が出ること
 * - 分譲地 新規登録画面が ?from=procurements のときだけ仕入れ案件一覧へ戻ること
 *   （このテストは Task 3 で追加する。本コミット時点では未検証）
 *
 * ⚠ アサーションの作り方に注意（false-pass しやすい）:
 *   サイドバーが /realestate/procurements と /realestate/projects の href を
 *   それぞれ複数回描画するため、素の assertSee(route(...)) や出現回数カウントは
 *   当てにならない。パンくずは完全な <a ...>ラベル</a> 文字列で、
 *   キャンセルは「キャンセル というラベルを持つ <a> の href」を正規表現で見る。
 */
class ProcurementListCreateDropdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        // 部署マスタは migration では投入されないので seeder で入れる（staff の所属付けに要る）
        $this->seed(DepartmentSeeder::class);
        // 用途地域の <option> 生成経路を空振りさせないため 1 件だけ入れる
        ZoningType::create(['name' => '第一種住居地域', 'sort_order' => 5]);
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 一般担当。department.access:realestate を通すため realestate に所属させる */
    private function staff(): User
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'realestate')->value('id'));

        return $user;
    }

    public function test_list_shows_both_create_links(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // URL そのもので見る。?from=procurements の有無まで含めて一意に判定できる
        $response->assertSee(route('realestate.procurements.create'), false);
        $response->assertSee(route('realestate.projects.create', ['from' => 'procurements']), false);
    }

    public function test_staff_sees_no_create_links(): void
    {
        $response = $this->actingAs($this->staff())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertDontSee(route('realestate.procurements.create'), false);
        $response->assertDontSee(route('realestate.projects.create', ['from' => 'procurements']), false);
    }

    /**
     * 分譲地 新規登録画面がそもそも開けること。
     *
     * ⚠ zoning_types は本番では raw SQL DDL 管理でマイグレーションに無く、
     *    CreatesRealEstateSchema にも入っていなかった（この画面を叩く既存テストが
     *    1 本も無かったため露見していなかった）。ProjectController::create() は
     *    ZoningType を引くので、trait 側で表を作らないとここで落ちる。
     */
    public function test_project_create_page_opens(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects/create');

        $response->assertOk();
        $response->assertSee('分譲地 新規登録');
        $response->assertSee('<option value="第一種住居地域"', false);
    }
}
