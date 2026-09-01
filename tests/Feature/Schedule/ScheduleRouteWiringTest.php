<?php

namespace Tests\Feature\Schedule;

use App\Http\Controllers\ScheduleStepController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 工程 CRUD のルート配線（設計書 §6.1 / §6.3）。
 *
 * ⚠ **4 親 × 4 本を対称に定義する。** 片側が足りないと、共通 partial が route() を
 *   呼んだ瞬間に**その画面だけ本番で 500** になる（Bug #25。realestate に surveys ルートが
 *   無くて起きた前例と同型で、空データのローカルでは再現しない）。
 *
 * ⚠ **OWNER_PARAMS の網羅は「全件分類」で見る。** 「直したものを並べる」形にすると、
 *   新しく足したルートが検査対象に入らず永遠に緑になる（Bug #45）。
 */
class ScheduleRouteWiringTest extends TestCase
{
    use RefreshDatabase;

    /** ルート名の接頭辞 => 親のルートパラメータ名 => URI の接頭辞 */
    private const OWNERS = [
        'realestate.procurements' => ['procurement', 'realestate/procurements', 'realestate'],
        'realestate.projects'     => ['project',     'realestate/projects',     'realestate'],
        'housing.properties'      => ['property',    'housing/properties',      'housing'],
        'housing.custom-orders'   => ['customOrder', 'housing/custom-orders',   'housing'],
    ];

    private const ACTIONS = ['store', 'reorder', 'update', 'destroy'];

    public function test_all_sixteen_routes_are_defined_symmetrically(): void
    {
        $missing = [];

        foreach (self::OWNERS as $prefix => $_) {
            foreach (self::ACTIONS as $action) {
                $name = "{$prefix}.schedule-steps.{$action}";
                if (! Route::has($name)) {
                    $missing[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "工程ルートが対称に定義されていません（欠けた側の詳細画面だけが本番で 500 します）:\n"
            . implode("\n", $missing)
        );
    }

    /**
     * 全件分類（Bug #45）: `*.schedule-steps.*` という名前のルートすべてについて、
     * 親のパラメータ名が OWNER_PARAMS に入っていること。
     *
     * ⚠ これが無いと、ルートのパラメータ名を打ち間違えたときに**無音で 404** になる。
     */
    public function test_every_schedule_step_route_uses_a_known_owner_param(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), '.schedule-steps.'));

        $this->assertCount(16, $routes, '工程ルートの本数が 16 でない（走査の空振り防止）');

        $offenders = [];

        foreach ($routes as $route) {
            $owners = array_values(array_diff($route->parameterNames(), ['step']));

            if (count($owners) !== 1 || ! array_key_exists($owners[0], ScheduleStepController::OWNER_PARAMS)) {
                $offenders[] = $route->getName() . ' => {' . implode(',', $owners) . '}';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ScheduleStepController::OWNER_PARAMS に無い親パラメータがあります（親を解決できず落ちます）:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * ⚠ **`reorder` が `{step}` より先にマッチすること。**
     *   ⚠ `route:list` の並びは登録順ではなく URI 辞書順なので、それを見て確かめてはいけない。
     *     ルータに実マッチさせて測る。
     */
    public function test_reorder_wins_over_the_step_parameter(): void
    {
        foreach (self::OWNERS as $prefix => [$_param, $uriPrefix, $_dept]) {
            $matched = Route::getRoutes()->match(
                Request::create("/{$uriPrefix}/1/schedule-steps/reorder", 'PATCH')
            );

            $this->assertSame(
                'reorder',
                $matched->getActionMethod(),
                "{$prefix}: /schedule-steps/reorder が {step} に食われている（登録順を直すこと）"
            );
        }
    }

    /**
     * ⚠ **モデルの `scheduleRoutePrefix()` から導いた工程ルート名が実在すること。**
     *   接頭辞を打ち間違えると `Route [...] not defined` で**その画面だけ本番 500** になる（Bug #25）。
     *   ⚠ この 1 本があるので、サービス側に「クラス => 接頭辞」の対応表を持たなくてよい。
     */
    public function test_the_route_prefix_of_every_parent_resolves_to_real_step_routes(): void
    {
        $models = [new \App\Models\ReProcurement, new \App\Models\ReProject, new \App\Models\HsProperty, new \App\Models\HsCustomOrder];

        foreach ($models as $model) {
            foreach (self::ACTIONS as $action) {
                $name = $model->scheduleStepRoute($action);

                $this->assertTrue(Route::has($name), $model::class . ": ルート {$name} が定義されていない");
            }
        }
    }

    /** 権限（設計書 §6）: 全 16 本が role:executive,manager と部署ガードの中にあること */
    public function test_every_route_is_gated_by_role_and_department(): void
    {
        foreach (self::OWNERS as $prefix => [$_param, $_uri, $department]) {
            foreach (self::ACTIONS as $action) {
                $middleware = Route::getRoutes()->getByName("{$prefix}.schedule-steps.{$action}")->gatherMiddleware();

                $this->assertContains('role:executive,manager', $middleware, "{$prefix}.{$action}: ロールガードが無い");
                $this->assertContains("department.access:{$department}", $middleware, "{$prefix}.{$action}: 部署ガードが無い");
            }
        }
    }
    /**
     * ⚠ **OWNER_PARAMS が指すクラスが、そのルートの部署・親と一致すること。**
     *
     *   `{property}` は**アプリ全体で曖昧**で、テナント物件（App\Models\Property）でも
     *   使われている（実測 8 本以上）。`{project}` も同様。マップに別部署のクラスを
     *   書いてしまうと、工程が**別テーブルの同じ id の行**にぶら下がり、
     *   しかも 200 が返るので**画面上は成功に見える**。
     *
     *   照合は `scheduleRoutePrefix()` で行う（マップとルート名を突き合わせる）ので、
     *   期待クラス名をここに二重に書かずに済む。
     */
    public function test_every_owner_param_maps_to_the_model_of_that_route(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), '.schedule-steps.'));

        $this->assertCount(16, $routes, '工程ルートの本数が 16 でない（走査の空振り防止）');

        $offenders = [];

        foreach ($routes as $route) {
            $name   = (string) $route->getName();
            $owners = array_values(array_diff($route->parameterNames(), ['step']));
            $class  = ScheduleStepController::OWNER_PARAMS[$owners[0]] ?? null;

            if ($class === null) {
                $offenders[] = "{$name}: OWNER_PARAMS に {$owners[0]} が無い";

                continue;
            }

            $expected = str_replace('.schedule-steps.' . $route->getActionMethod(), '', $name);
            $actual   = (new $class)->scheduleRoutePrefix();

            if ($actual !== $expected) {
                $offenders[] = "{$name}: {$class} の接頭辞は {$actual}（期待 {$expected}）";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "OWNER_PARAMS のクラスがルートと食い違っています（別部署の同じ id にぶら下がり、"
            . "しかも 200 が返るので画面では気づけません）:\n" . implode("\n", $offenders)
        );
    }
}
