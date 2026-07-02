<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDepartmentAccess;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 部署アクセス制御ミドルウェア（CheckDepartmentAccess / C-2）の単体テスト。
 *
 * ルート経由だと事業コントローラが migration 管理外テーブル（re_*, hs_*, ms_* 等）を
 * 参照して 500 になり「許可=200」を確認できないため、ミドルウェアを直接呼び出して
 * 通過(next 実行) / 403(abort) を検証する。users / departments / department_user は migration 済み。
 */
class DepartmentAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 各テストで一度だけ部署マスタ（zeal 含む7件）を投入する。
        // ヘルパーを複数回呼ぶテストでの二重シードによる UNIQUE 制約違反を防ぐ。
        $this->seed(DepartmentSeeder::class);
    }

    /** 指定ロール・所属部署のユーザーを作成する */
    private function userInDepartments(UserRole $role, array $codes = []): User
    {
        $user = User::factory()->create(['role' => $role->value]);
        foreach ($codes as $code) {
            $user->departments()->attach(Department::where('code', $code)->value('id'));
        }

        return $user;
    }

    /** ミドルウェアを実行し、通過時は next の返り値（'PASSED'）を返す。abort 時は HttpException */
    private function runMiddleware(User $user, string ...$codes): string
    {
        $request = Request::create('/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = (new CheckDepartmentAccess())->handle(
            $request,
            fn ($req) => new Response('PASSED'),
            ...$codes
        );

        return $response->getContent();
    }

    /** 通過時に HttpException が出ないことを確認するヘルパー */
    private function assertForbidden(User $user, string ...$codes): void
    {
        try {
            $this->runMiddleware($user, ...$codes);
            $this->fail('403 (HttpException) が発生すべき');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /** 引数なし（no codes）でミドルウェアを実行。body に 'department' を任意付与する */
    private function runNoCodes(User $user, ?string $bodyDepartment = null): string
    {
        $params  = $bodyDepartment !== null ? ['department' => $bodyDepartment] : [];
        $request = Request::create('/dummy', 'POST', $params);
        $request->setUserResolver(fn () => $user);

        $response = (new CheckDepartmentAccess())->handle(
            $request,
            fn ($req) => new Response('PASSED')
        );

        return $response->getContent();
    }

    /** 引数なし実行で 403 になることを確認するヘルパー */
    private function assertForbiddenNoCodes(User $user, ?string $bodyDepartment = null): void
    {
        try {
            $this->runNoCodes($user, $bodyDepartment);
            $this->fail('403 (HttpException) が発生すべき');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /** 所属部署のコードは通過する */
    public function test_member_passes(): void
    {
        $user = $this->userInDepartments(UserRole::Staff, ['tenant']);
        $this->assertSame('PASSED', $this->runMiddleware($user, 'tenant'));
    }

    /** 非所属部署は 403 */
    public function test_non_member_is_forbidden(): void
    {
        $user = $this->userInDepartments(UserRole::Staff, ['tenant']);
        $this->assertForbidden($user, 'realestate');
    }

    /** executive は非所属でも全通過 */
    public function test_executive_bypasses(): void
    {
        $user = $this->userInDepartments(UserRole::Executive, []);
        $this->assertSame('PASSED', $this->runMiddleware($user, 'zeal'));
    }

    /** 複数コード指定時はいずれかに所属していれば通過 */
    public function test_passes_if_member_of_any_code(): void
    {
        $user = $this->userInDepartments(UserRole::Manager, ['housing']);
        $this->assertSame('PASSED', $this->runMiddleware($user, 'realestate', 'housing'));
    }

    /** 部署未割当の非経営層は 403（ロックアウト＝設計どおり） */
    public function test_unassigned_non_executive_is_forbidden(): void
    {
        $user = $this->userInDepartments(UserRole::Staff, []);
        $this->assertForbidden($user, 'tenant');
    }

    /** zeal 部署が DepartmentSeeder に追加され belongsToDepartment('zeal') が機能する */
    public function test_zeal_department_is_seeded_and_scoped(): void
    {
        $zealUser = $this->userInDepartments(UserRole::Staff, ['zeal']);
        $this->assertSame('PASSED', $this->runMiddleware($zealUser, 'zeal'));

        $tenantUser = $this->userInDepartments(UserRole::Staff, ['tenant']);
        $this->assertForbidden($tenantUser, 'zeal');
    }

    /**
     * fail-closed: 引数なし かつ department 未指定の非経営層は 403。
     * （旧実装は素通り＝fail-open。この回帰テストが退行を防ぐ）
     */
    public function test_no_codes_without_department_is_forbidden(): void
    {
        $user = $this->userInDepartments(UserRole::Manager, ['housing']);
        $this->assertForbiddenNoCodes($user, null);
    }

    /** 引数なし・body の department が所属部署なら通過する */
    public function test_no_codes_with_own_department_passes(): void
    {
        $user = $this->userInDepartments(UserRole::Manager, ['housing']);
        $this->assertSame('PASSED', $this->runNoCodes($user, 'housing'));
    }

    /** 引数なし・body の department が非所属部署なら 403 */
    public function test_no_codes_with_other_department_is_forbidden(): void
    {
        $user = $this->userInDepartments(UserRole::Manager, ['housing']);
        $this->assertForbiddenNoCodes($user, 'realestate');
    }

    /** 引数なし・department 未指定でも経営層は通過する（bypass は維持） */
    public function test_no_codes_executive_without_department_passes(): void
    {
        $user = $this->userInDepartments(UserRole::Executive, []);
        $this->assertSame('PASSED', $this->runNoCodes($user, null));
    }
}
