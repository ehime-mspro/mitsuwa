<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Http\Controllers\AttachmentController;
use App\Models\Contract;
use App\Models\DadProject;
use App\Models\Department;
use App\Models\Investment;
use App\Models\MsTenant;
use App\Models\Repair;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 添付ファイルの部署ベース認可（H-1 / H-2 IDOR 対策）テスト。
 *
 * 注意: attachments / re_* / hs_* / ms_* テーブルは Laravel マイグレーション管理外で
 * テスト DB（SQLite in-memory）に存在しない（DashboardControllerTest 参照）。
 * そのためルート経由ではなく、認可の中核ロジックである
 * AttachmentController::canAccessDepartmentOf() を Reflection で直接検証する。
 * users / departments / department_user は migration 済みのため利用可能。
 */
class AttachmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** 指定部署コードに所属する staff を作成して認証する */
    private function actingAsStaffInDepartment(string $code): User
    {
        $this->seed(DepartmentSeeder::class);
        $staff = User::factory()->create(['role' => UserRole::Staff->value]);
        $staff->departments()->attach(Department::where('code', $code)->value('id'));
        $this->actingAs($staff);

        return $staff;
    }

    /** private な canAccessDepartmentOf() を Reflection 経由で評価する */
    private function canAccess(string $modelClass): bool
    {
        $controller = new AttachmentController();
        $method = new ReflectionMethod($controller, 'canAccessDepartmentOf');
        $method->setAccessible(true);

        return $method->invoke($controller, $modelClass);
    }

    /** realestate 所属 staff は realestate 系クラスのみアクセス可 */
    public function test_realestate_staff_scoped_to_realestate_classes(): void
    {
        $this->actingAsStaffInDepartment('realestate');

        $this->assertTrue($this->canAccess(ReProject::class));
        $this->assertTrue($this->canAccess(ReProcurement::class));

        $this->assertFalse($this->canAccess(Contract::class));   // tenant
        $this->assertFalse($this->canAccess(MsTenant::class));   // mansion
        $this->assertFalse($this->canAccess(DadProject::class)); // dad
    }

    /** tenant 所属 staff は tenant 系クラスのみ */
    public function test_tenant_staff_scoped_to_tenant_classes(): void
    {
        $this->actingAsStaffInDepartment('tenant');

        $this->assertTrue($this->canAccess(Contract::class));
        $this->assertTrue($this->canAccess(Investment::class));
        $this->assertTrue($this->canAccess(Repair::class));

        $this->assertFalse($this->canAccess(ReProject::class));
    }

    /** executive は全部署クラスにアクセス可 */
    public function test_executive_can_access_all_classes(): void
    {
        $this->seed(DepartmentSeeder::class);
        $exec = User::factory()->create(['role' => UserRole::Executive->value]);
        $this->actingAs($exec);

        foreach ([Contract::class, ReProject::class, MsTenant::class, DadProject::class] as $class) {
            $this->assertTrue($this->canAccess($class));
        }
    }

    /** マップに無いクラスは安全側に倒して不許可（executive 以外） */
    public function test_unmapped_class_is_denied_for_non_executive(): void
    {
        $this->actingAsStaffInDepartment('tenant');

        $this->assertFalse($this->canAccess(User::class));
    }
}
