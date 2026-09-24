<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RestrictApprovalOnlyUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRouteUrls;
use Tests\TestCase;

/**
 * 決裁のみ利用者は決裁以外の全画面から締め出す（設計書 §5.2・§6・要件 15.6）。
 *
 * ⚠ **全件分類**（Top trap #13 / Bug #45 ①）。「直した画面を並べる」形だと、新しいルートが
 *   無検査のまま増える。`Route::getRoutes()` を機械的に 4 つに分け、どれにも入らなければ落とす。
 *
 * ⚠ **ロールだけでは守れない。** `/dashboard/tenant` には `role:` も `department.access` も
 *   付いておらず、全ロールが見られる（2026-09-15 実測）。守りの本体は web グループの門番。
 */
class ApprovalOnlyLockoutTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRouteUrls;

    /** 門番を通す（決裁の画面・パスワード変更・ログアウト） */
    private const ALLOWED_NAMES = ['password.change', 'password.update', 'logout'];

    /**
     * web グループの外にあるルート（理由つきの固定リスト）。
     *
     * ⚠ 「web の外だから」で自動的に除外しない。**理由を書いて名指しする**
     *   （新しく web の外へ出たルートは、ここに足すまで落ちる）。
     */
    private const OUTSIDE_WEB = [
        'GET storage/{path}' => 'local ディスクの serve による署名つき URL（web グループの外）',
        'PUT storage/{path}' => '同上',
        'GET up'             => 'ヘルスチェック（withRouting の health）',
    ];

    private function approvalOnlyUser(): User
    {
        return User::factory()->approvalOnly()->create(['must_change_password' => false]);
    }

    /** `where` の条件を満たす値を作る。`|` で並んだリテラルなら先頭、それ以外は数字 */
    private function parameterValue(RoutingRoute $route, string $name): string
    {
        $where = $route->wheres[$name] ?? null;

        if (is_string($where) && preg_match('/\A[A-Za-z0-9_]+(\|[A-Za-z0-9_]+)*\z/', $where)) {
            return explode('|', $where)[0];
        }

        // 存在しない ID。ルートモデル結合より前で止まっていれば 404 にならない
        return '999999';
    }

    public function test_every_route_is_classified_and_blocked(): void
    {
        $user = $this->approvalOnlyUser();

        $checked = 0;
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $label  = $method . ' ' . $route->uri();
            $name   = $route->getName();
            $mw     = $route->gatherMiddleware();

            // ③ web の外
            if (isset(self::OUTSIDE_WEB[$label])) {
                continue;
            }

            if (! in_array('web', $mw, true)) {
                $problems[] = "{$label}: web グループの外なのに OUTSIDE_WEB に理由が書かれていない";
                continue;
            }

            // ② 未ログイン専用（ログイン済みの人は RedirectIfAuthenticated が追い返す）
            if (in_array('guest', $mw, true)) {
                continue;
            }

            // ① 許可リスト
            if ($name !== null && (str_starts_with($name, 'approvals.') || in_array($name, self::ALLOWED_NAMES, true))) {
                continue;
            }

            // ④ それ以外 — 実際に要求して止まることを見る
            $checked++;
            $url = $this->urlForRoute($route, fn (string $param): string => $this->parameterValue($route, $param));

            // ⚠ 組み立てた URL が**そのルート自身**に当たることを確かめる。`where` の条件を
            //   満たさない値を入れると、ルーターが別のルートへ落ちるか 404 になり、
            //   「検査したつもりで別のものを見ていた」になる（Bug #45 の型）。
            //   いまは数字でない値を要求する `where` は無いが、足した人がここで気づける。
            if (! $route->matches(Request::create($url, $method), includingMethod: false)) {
                $problems[] = "{$label}: 組み立てた URL ({$url}) がこのルートに当たらない（where の条件を見直すこと）";

                continue;
            }

            $response = $this->actingAs($user)->call($method, $url);
            $status   = $response->getStatusCode();

            if ($method === 'GET') {
                if ($status !== 302 || $response->headers->get('Location') !== route('approvals.home')) {
                    $problems[] = "{$label}: 決裁のホームへ転送されない（status={$status} location=" . $response->headers->get('Location') . ')';
                }
            } elseif ($status !== 403) {
                $problems[] = "{$label}: 403 で拒否されない（status={$status}）";
            }

            if ($status === 404) {
                $problems[] = "{$label}: 404 になった（ルートモデル結合より後ろで止まっている＝データの有無が漏れる）";
            }
            if ($status >= 500) {
                $problems[] = "{$label}: {$status} になった";
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（2026-09-15 実測で全 430 本）
        $this->assertGreaterThan(400, $checked, 'ルートの走査に失敗している');
        $this->assertSame([], $problems, "決裁のみ利用者を止められていないルート:\n" . implode("\n", $problems));
    }

    /**
     * `approvals.` の名前を名乗れるのは**決裁のコントローラだけ**であること。
     *
     * ⚠ 門番（`RestrictApprovalOnlyUsers`）も上の全件分類も、「ルート名が `approvals.` で
     *   始まるか」という**同じ基準**で「安全」と判断している。だから誰かが機微なルートの名前を
     *   `approvals.` に付け替えると、**門番は通し、分類のテストも検査対象から外す**
     *   ＝ 二重に見落とす（Bug #45 の型）。名前の付け先を別の軸（コントローラの名前空間）で
     *   縛って、その共犯関係を断つ。
     */
    public function test_only_approval_controllers_may_claim_the_approvals_name(): void
    {
        $offenders = [];
        $found = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'approvals.')) {
                continue;
            }

            $found++;
            $controller = (string) $route->getAction('controller');

            if (! str_starts_with($controller, 'App\\Http\\Controllers\\Approval\\')) {
                $offenders[] = $route->getName() . ' => ' . ($controller ?: '(クロージャ)');
            }
        }

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThan(0, $found, 'approvals. のルートが 1 本も見つからない');

        $this->assertSame(
            [],
            $offenders,
            "決裁のコントローラ以外が approvals. の名前を名乗っています（門番が素通しします）:\n" . implode("\n", $offenders)
        );
    }

    /** 決裁の画面には入れる */
    public function test_the_approval_home_is_reachable(): void
    {
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.home'))->assertOk();
    }

    /** パスワード変更とログアウトも通る */
    public function test_password_change_and_logout_are_allowed(): void
    {
        $user = $this->approvalOnlyUser();

        $this->actingAs($user)->get(route('password.change'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    }

    /** 転送されたホームに理由が出る */
    public function test_the_reason_is_shown_after_the_redirect(): void
    {
        $html = $this->actingAs($this->approvalOnlyUser())
            ->followingRedirects()->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString('決裁以外の画面は使えません。', $html);
    }

    /** @return array<string, array{string}> */
    public static function homeAliasCases(): array
    {
        return [
            'サイトの入口 /'           => ['/'],
            'ダッシュボード /dashboard' => ['/dashboard'],
            'ログイン中のログイン画面'   => ['/login'],
        ];
    }

    /**
     * 「ホーム」の意味の入口は、警告なしで決裁のホームへ（F1 と同じ形）。
     *
     * ⚠ 案内の QR はログイン画面の URL。ログインしたまま QR やブックマークから開くと
     *   `/login` → `/dashboard` → 門番、と転送される。旧実装はそのたびに警告を出していた。
     * ⚠ 本物の基幹の画面では今までどおり警告が出る（`test_the_reason_is_shown_after_the_redirect` が固定する）。
     */
    #[DataProvider('homeAliasCases')]
    public function test_home_aliases_land_on_the_approval_home_without_the_warning(string $uri): void
    {
        $html = $this->actingAs($this->approvalOnlyUser())
            ->followingRedirects()->get($uri)->assertOk()->getContent();

        $this->assertStringContainsString('決裁の機能は準備中です。', $html, '決裁のホームに着いていない');
        $this->assertStringNotContainsString(RestrictApprovalOnlyUsers::MESSAGE, $html, 'ホームを開いただけなのに警告が出ている');
    }

    /** Ajax は転送でなく 403（画面の JS が HTML を読まされないように） */
    public function test_ajax_requests_get_403(): void
    {
        $this->actingAs($this->approvalOnlyUser())
            ->get('/dashboard/tenant', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertStatus(403);
    }

    /** 基幹を使う人は今までどおり */
    public function test_base_users_are_untouched(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $this->actingAs($staff)->get('/dashboard/tenant')->assertOk();
    }

    /**
     * 二重の守り: ロールを見るミドルウェアも決裁のみ利用者を拒む（設計書 §5.2）。
     */
    public function test_role_and_department_middleware_also_reject_approval_only_users(): void
    {
        $this->actingAs($this->approvalOnlyUser());

        $request = \Illuminate\Http\Request::create('/x');
        $request->setUserResolver(fn () => auth()->user());

        $next = fn () => response('through');

        try {
            (new \App\Http\Middleware\CheckRole())->handle($request, $next, 'executive', 'manager', 'staff');
            $this->fail('CheckRole が決裁のみ利用者を通した');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            (new \App\Http\Middleware\CheckDepartmentAccess())->handle($request, $next, 'tenant');
            $this->fail('CheckDepartmentAccess が決裁のみ利用者を通した');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * 門番の位置（設計書 §5.2）。
     *
     * ⚠ 挙動のテスト（404 にならないこと）と**対で**固定する。片方だけだと、
     *   優先順の登録を消しても「たまたま順番が合っていて緑」になりうる。
     */
    public function test_the_gates_run_before_route_model_binding(): void
    {
        // ⚠ `Router::$middlewarePriority` は既定で空。`Illuminate\Contracts\Http\Kernel` が
        //    解決されて初めて `appendToPriorityList` の内容が同期される（`ApplicationBuilder` の
        //    afterResolving フック）。このテストは HTTP を出さないので、この 1 行が無いと
        //    **実装の正誤に関係なく必ず**「優先順のリストに無い」で落ちる（実測）。
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $priority = array_values(app('router')->middlewarePriority ?? []);

        $positions = [];
        foreach ([
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            EnsureUserIsActive::class,
            RestrictApprovalOnlyUsers::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ] as $class) {
            $index = array_search($class, $priority, true);
            $this->assertNotFalse($index, "{$class} が優先順のリストに無い");
            $positions[$class] = $index;
        }

        $this->assertTrue(
            $positions[\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class]
                < $positions[EnsureUserIsActive::class]
                && $positions[EnsureUserIsActive::class] < $positions[RestrictApprovalOnlyUsers::class]
                && $positions[RestrictApprovalOnlyUsers::class] < $positions[\Illuminate\Routing\Middleware\SubstituteBindings::class],
            '門番が AuthenticatesSessions の後・SubstituteBindings の前に並んでいない'
        );
    }
}
