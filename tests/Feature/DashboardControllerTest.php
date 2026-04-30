<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

/**
 * 経営ダッシュボード（DashboardController::executive）アクセス制御 / フィルター解決テスト。
 *
 * 注意: ms_*, hs_*, re_* テーブルはマイグレーション化されておらず（CLAUDE.md 参照）、
 * テスト DB（SQLite in-memory）には存在しないため、KPI 集計の DB クエリ確認はせず、
 * - ルート/ミドルウェアによるアクセス制御
 * - resolveFiscalYear / resolvePeriod の不正値フォールバック
 * の 2 点に絞ってテストする。
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ヘルパー: 役割を指定して User を作成する
     */
    private function createUser(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role->value,
        ]);
    }

    /** /dashboard/executive は executive ロール必須（manager は 403） */
    public function test_executive_route_blocks_manager(): void
    {
        $manager = $this->createUser(UserRole::Manager);

        $response = $this->actingAs($manager)->get('/dashboard/executive');

        $response->assertStatus(403);
    }

    /** /dashboard/executive は staff ロールも 403 */
    public function test_executive_route_blocks_staff(): void
    {
        $staff = $this->createUser(UserRole::Staff);

        $response = $this->actingAs($staff)->get('/dashboard/executive');

        $response->assertStatus(403);
    }

    /** 未認証ユーザーは /dashboard/executive アクセスでログイン画面にリダイレクト */
    public function test_executive_route_requires_login(): void
    {
        $response = $this->get('/dashboard/executive');

        $response->assertRedirect('/login');
    }

    /** /dashboard は executive ロールを /dashboard/executive にリダイレクトする */
    public function test_dashboard_root_redirects_executive(): void
    {
        $exec = $this->createUser(UserRole::Executive);

        $response = $this->actingAs($exec)->get('/dashboard');

        $response->assertRedirect(route('dashboard.executive'));
    }

    /** /dashboard は executive 以外を /dashboard/tenant にリダイレクトする */
    public function test_dashboard_root_redirects_non_executive(): void
    {
        $manager = $this->createUser(UserRole::Manager);

        $response = $this->actingAs($manager)->get('/dashboard');

        $response->assertRedirect(route('dashboard.tenant'));
    }

    /** resolveFiscalYear: 不正値 → 当年度フォールバック */
    public function test_resolve_fiscal_year_falls_back_for_invalid_input(): void
    {
        $controller = new DashboardController();
        $reflect = new ReflectionClass($controller);
        $method = $reflect->getMethod('resolveFiscalYear');
        $method->setAccessible(true);

        // 不正な fy 値はデフォルト（当年度）にフォールバック
        $req = Request::create('/dashboard/executive?fy=invalid');
        $result = $method->invoke($controller, $req);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $result);

        // fy 未指定もデフォルト
        $req = Request::create('/dashboard/executive');
        $result = $method->invoke($controller, $req);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $result);

        // fy=all はそのまま
        $req = Request::create('/dashboard/executive?fy=all');
        $this->assertSame('all', $method->invoke($controller, $req));

        // 4 桁数値はそのまま
        $req = Request::create('/dashboard/executive?fy=2024');
        $this->assertSame('2024', $method->invoke($controller, $req));
    }

    /** resolvePeriod: 不正値 → 当期フォールバック */
    public function test_resolve_period_falls_back_for_invalid_input(): void
    {
        $controller = new DashboardController();
        $reflect = new ReflectionClass($controller);
        $method = $reflect->getMethod('resolvePeriod');
        $method->setAccessible(true);

        // 不正な period は当期へフォールバック
        $req = Request::create('/dashboard/executive?period=foo');
        $result = $method->invoke($controller, $req);
        $this->assertContains($result, ['h1', 'h2']);

        // full / h1 / h2 はそのまま
        foreach (['full', 'h1', 'h2'] as $period) {
            $req = Request::create('/dashboard/executive?period=' . $period);
            $this->assertSame($period, $method->invoke($controller, $req));
        }
    }

    /** YoY 計算: 前期 0 / null は null を返す */
    public function test_calc_yoy_returns_null_for_zero_or_null_prev(): void
    {
        $controller = new DashboardController();
        $reflect = new ReflectionClass($controller);
        $method = $reflect->getMethod('calcYoy');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, 100, null));
        $this->assertNull($method->invoke($controller, 100, 0));
    }

    /** YoY 計算: 増減率 / positive フラグが正しい */
    public function test_calc_yoy_calculates_rate_correctly(): void
    {
        $controller = new DashboardController();
        $reflect = new ReflectionClass($controller);
        $method = $reflect->getMethod('calcYoy');
        $method->setAccessible(true);

        // 100 → 125 = +25%
        $result = $method->invoke($controller, 125, 100);
        $this->assertSame(25.0, $result['rate']);
        $this->assertTrue($result['positive']);

        // 100 → 80 = -20%
        $result = $method->invoke($controller, 80, 100);
        $this->assertSame(20.0, $result['rate']);
        $this->assertFalse($result['positive']);

        // 100 → 100 = 0% （neutral）
        $result = $method->invoke($controller, 100, 100);
        $this->assertSame(0.0, $result['rate']);
        $this->assertTrue($result['neutral']);
    }

    // =========================================================
    //  テナントダッシュボード（全認証ユーザーアクセス可能）
    // =========================================================

    /** /dashboard/tenant は executive ロールでアクセス可能（200） */
    public function test_tenant_route_accessible_by_executive(): void
    {
        $exec = $this->createUser(UserRole::Executive);

        $response = $this->actingAs($exec)->get('/dashboard/tenant');

        $response->assertStatus(200);
    }

    /** /dashboard/tenant は manager ロールでもアクセス可能（403 にならない） */
    public function test_tenant_route_accessible_by_manager(): void
    {
        $manager = $this->createUser(UserRole::Manager);

        $response = $this->actingAs($manager)->get('/dashboard/tenant');

        $response->assertStatus(200);
    }

    /** /dashboard/tenant は staff ロールでもアクセス可能（403 にならない） */
    public function test_tenant_route_accessible_by_staff(): void
    {
        $staff = $this->createUser(UserRole::Staff);

        $response = $this->actingAs($staff)->get('/dashboard/tenant');

        $response->assertStatus(200);
    }

    /** 未認証ユーザーは /dashboard/tenant アクセスでログイン画面にリダイレクト */
    public function test_tenant_route_requires_login(): void
    {
        $response = $this->get('/dashboard/tenant');

        $response->assertRedirect('/login');
    }

    /** calculateAnnualIncomeProjection: total = actual + projected が成立する */
    public function test_calculate_annual_income_projection_total_equals_actual_plus_projected(): void
    {
        $controller = new DashboardController();
        $reflect = new ReflectionClass($controller);
        $method = $reflect->getMethod('calculateAnnualIncomeProjection');
        $method->setAccessible(true);

        // 当年度を渡して戻り値の整合性を確認（データなしでも total = actual + projected）
        $fy = (int) date('n') >= 5 ? (int) date('Y') : (int) date('Y') - 1;
        $result = $method->invoke($controller, $fy);

        $this->assertArrayHasKey('actual', $result);
        $this->assertArrayHasKey('projected', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertIsInt($result['actual']);
        $this->assertIsInt($result['projected']);
        $this->assertIsInt($result['total']);
        $this->assertSame($result['actual'] + $result['projected'], $result['total']);
    }

    /** buildProjectionLabels: 年度途中ならば actual/projected の両ラベルが揃う */
    public function test_build_projection_labels_returns_expected_shape(): void
    {
        $controller = new DashboardController();
        $reflect = new ReflectionClass($controller);
        $method = $reflect->getMethod('buildProjectionLabels');
        $method->setAccessible(true);

        $fy = (int) date('n') >= 5 ? (int) date('Y') : (int) date('Y') - 1;
        $result = $method->invoke($controller, $fy);

        $this->assertArrayHasKey('actual', $result);
        $this->assertArrayHasKey('projected', $result);
        // 当年度の場合: actual と projected のいずれかは必ず非 null
        $this->assertTrue(
            $result['actual'] !== null || $result['projected'] !== null,
            'Either actual or projected label must be set for the current fiscal year'
        );
    }
}
