<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureApprovalAdmin;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\BuildsRouteUrls;
use Tests\TestCase;

/**
 * 決裁の管理（`approvals.admin.*`）の門番を、全ルート × 権限の無い 6 人 × ID の有無で守る。
 *
 * ⚠ **全件分類**（Top trap #13 / Bug #45 ①）。`OrganizationManagementTest` に残っていた
 *   `test_a_missing_id_is_rejected_the_same_way_as_an_existing_one` は、削除系 3 ルート ×
 *   実在／しない ID の**手書き 6 URL**だけを見る列挙リスト方式だった。新しいルート
 *   （例えば `users.update`）が増えても検査対象にそもそも入らないので、門番の付け忘れが
 *   永遠に緑になる。ここでは `Route::getRoutes()` を機械的に「`approvals.admin.*`」と
 *   「それ以外の `approvals.*`」（`OPEN_TO_EVERY_USER`）に分け、どちらにも分類できない
 *   ルートを走査が拾った時点で落とす（`test_every_approvals_route_is_classified`）。
 *
 * ⚠ **実在する ID としない ID の両方を見る。** `bootstrap/app.php` が `EnsureApprovalAdmin` を
 *   `appendToPriorityList` で `SubstituteBindings`（ルートモデル結合）より前に出しているのは、
 *   そうしないと権限の無い人が実在しない ID にだけ 404 を受け取り、403/404 の違いから
 *   「その会社・部門・ドメイン・利用者が実在するか」を数えられてしまうため
 *   （`RestrictApprovalOnlyUsers` の docblock と同じ性質）。この優先順が崩れていないかを、
 *   実在する ID・しない ID の両方でいつも同じ 403 が返ることで確かめる。
 *
 * ⚠ **権限の無い 6 人を見る**（設計書 §5.17）。`UserRole::cases()` の 4 ロール（決裁の印を
 *   持たない経営層・部門管理者・一般担当者・決裁のみ利用者）＋ 全件閲覧者（「3 状態目」）＋ 社長。
 *   - **全件閲覧者**（`can_view_all=true` かつ `is_admin=false`）— `can_view_all` と `is_admin`
 *     は `Admin\UserController` が独立に保存するので、決裁の印の行が**在る**が管理者ではない
 *     人が実在する。2026-09-18 のレビューまでこの状態を叩くテストが 1 本も無く、門番の判定を
 *     「行の有無」に取り違える変異（権限昇格）が検出できなかった
 *   - **社長** — `User::hasApprovalPrivileges()` は「社長 || 決裁の管理者 || 全件閲覧者」を
 *     数えるが、この門番（`isApprovalAdmin()`）を通れるのは**決裁の管理者だけ**。社長を
 *     見ていないと `isApprovalAdmin() || isApprovalPresident()` のような取り違え（社長も
 *     通してしまう変異）を検出できない
 *   - **決裁のみ利用者**は 1 段目の門番 `RestrictApprovalOnlyUsers` がルート名 `approvals.` を
 *     無条件で通すので、この人を止めるのは**この 2 段目だけ**
 *
 * ⚠ **組み立てた URL が実際にこのルートへ配送されることを確認する**（`Route::getRoutes()->match()`）。
 *   `$route->matches()`（このルート自身の条件しか見ない）ではなく、全ルートの中から実際に
 *   選ばれるものを見る。登録順で別のルートに先取りされていても気づく
 *   （`routes/approval.php:41-43` が名指しする罠。`ApprovalOnlyLockoutTest` と同じ流儀の強化版）。
 *
 * ⚠ **(a)（構造）と (b)（挙動）は対で維持する。** (a) は `app('router')->gatherRouteMiddleware()`
 *   （alias 解決・`withoutMiddleware()` の除外・優先順の並び替えをすべて行う。
 *   `vendor/laravel/framework/src/Illuminate/Routing/Router.php` のソースで確認済み）で
 *   「`EnsureApprovalAdmin` が居るか・`SubstituteBindings` より前か」を**HTTP を出さずに**
 *   確かめる。だが (a) は配線（どのミドルウェアが・どの順で立つか）しか見ないので、
 *   `isApprovalAdmin()` の判定ロジックそのもの（社長も通してしまうような取り違え）は見えない。
 *   (b) はその判定ロジックまで実際に叩いて確かめるが、(b) だけだと優先順の登録を消しても
 *   「たまたま別の理由で 403 になって緑」になりうる（`ApprovalOnlyLockoutTest.php` の
 *   「門番の位置」テストと同じ構図）。**どちらか片方では足りない。**
 */
class ApprovalAdminGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRouteUrls;

    /**
     * `approvals.admin.` 以外で `approvals.` を名乗ってよいルート（名前 => 理由）。
     *
     * ⚠ ルート名が `approvals.` で始まるという条件だけで「安全」と自動判定しない。
     *   **理由を書いて名指しする**（新しく増えたルートは、ここに足すまで分類漏れとして落ちる）。
     */
    private const OPEN_TO_EVERY_USER = [
        'approvals.home' => '決裁のホーム（全ログイン利用者が入れる。設計書 §5.1・§5.15）',
    ];

    /** ラベル用: HEAD を除いた先頭の HTTP メソッド（1 つで十分な場所） */
    private function httpMethodOf(RoutingRoute $route): string
    {
        return $this->nonHeadMethods($route)[0];
    }

    /** HEAD を除いた全 HTTP メソッド（同じ URI に複数メソッドが束ねられていても全部叩く） */
    private function nonHeadMethods(RoutingRoute $route): array
    {
        return array_values(array_filter($route->methods(), fn ($m) => $m !== 'HEAD'));
    }

    public function test_every_approvals_route_is_classified(): void
    {
        // 優先順のリストを同期させる（HTTP kernel が解決されて初めて appendToPriorityList
        // の内容が効く。`ApprovalOnlyLockoutTest::test_the_gates_run_before_route_model_binding`
        // と同じ理由。この 1 行が無いと gatherRouteMiddleware() の並びが必ず崩れて見える）
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $found         = 0;
        $seenOpenNames = [];
        $problems      = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri  = $route->uri();

            // 逆方向の分類（名前 → 何であるべきか、ではなく URI・コントローラ → 名前）。
            // ⚠ 分類が名前だけを鍵にしていると、`approvals/...` の URI や Approval 名前空間の
            //   コントローラを持つのに `approvals.` を名乗っていない（あるいは名前が無い）
            //   ルートは、どちらの走査にも入らず無検査のまま増える（Bug #45 と同型の見落とし）。
            $reasons = [];
            if ($uri === 'approvals' || str_starts_with($uri, 'approvals/')) {
                $reasons[] = 'URI が approvals 配下';
            }
            if (str_starts_with((string) $route->getAction('controller'), 'App\\Http\\Controllers\\Approval\\')) {
                $reasons[] = 'コントローラが Approval 名前空間';
            }
            if ($reasons !== [] && ($name === null || ! str_starts_with($name, 'approvals.'))) {
                $problems[] = $this->httpMethodOf($route) . " {$uri}: " . implode('・', $reasons)
                    . 'なのに名前が approvals. で始まらない（実際の名前: ' . ($name ?? '(名前なし)') . '）';
            }

            if ($name === null || ! str_starts_with($name, 'approvals.')) {
                continue;
            }

            $found++;
            $label = $name . " ({$uri})";

            if (str_starts_with($name, 'approvals.admin.')) {
                $problems = [...$problems, ...$this->gateWiringProblems($route, $label)];

                continue;
            }

            if (array_key_exists($name, self::OPEN_TO_EVERY_USER)) {
                $seenOpenNames[$name] = true;

                continue;
            }

            $problems[] = "{$label}: approvals.admin. でも OPEN_TO_EVERY_USER でもない（分類されていない）";
        }

        foreach (self::OPEN_TO_EVERY_USER as $name => $reason) {
            if (! isset($seenOpenNames[$name])) {
                $problems[] = "{$name}: OPEN_TO_EVERY_USER に載っているが、そのルートがもう存在しない（記述を削ること）";
            }
        }

        // ⚠ 問題の中身を先に見る。下限だけを先に見ると、パラメータ名の変更や走査条件の
        //   ずれで件数が減ったときに「走査に失敗している」としか分からず、$problems に
        //   出ている本当の理由（分類漏れ・門番の欠落・逆方向の見落とし）が隠れる。
        $this->assertSame([], $problems, "分類漏れ・門番の欠落・逆方向の見落とし:\n" . implode("\n", $problems));

        // 走査が空振りして緑になる事故を防ぐ（実測 19 本 = 決裁の管理 18 本 + ホーム 1 本）
        $this->assertGreaterThanOrEqual(19, $found, 'approvals. のルートの走査に失敗している');
    }

    /**
     * `approvals.admin.*` の 1 ルートについて、解決・除外・優先順の並び替えを終えた
     * ミドルウェアの中に `EnsureApprovalAdmin` が有り、`SubstituteBindings` より前にあるかを見る。
     *
     * `app('router')->gatherRouteMiddleware($route)` は `Router::resolveMiddleware()` を経由し、
     * alias を実クラス名へ解決し、`$route->excludedMiddleware()`（`withoutMiddleware()`）を
     * 差し引き、`$this->middlewarePriority` で並べ替えた**最終的な**配列を返す
     * （`Router.php` の `resolveMiddleware()` が最後に `sortMiddleware()` を呼ぶ。ソースで確認済み）。
     * よってこれ 1 本で「門番が外れている」「別名に付け替えられている」「優先順が崩れている」の
     * 3 通りを HTTP を出さずに検出できる。
     *
     * @return list<string>
     */
    private function gateWiringProblems(RoutingRoute $route, string $label): array
    {
        $resolved = app('router')->gatherRouteMiddleware($route);

        $adminAt      = array_search(EnsureApprovalAdmin::class, $resolved, true);
        $substituteAt = array_search(\Illuminate\Routing\Middleware\SubstituteBindings::class, $resolved, true);

        if ($adminAt === false) {
            return ["{$label}: EnsureApprovalAdmin が解決後のミドルウェアに無い（withoutMiddleware で外れた・alias の付け替えの可能性）"];
        }

        if ($substituteAt !== false && $adminAt > $substituteAt) {
            return ["{$label}: EnsureApprovalAdmin が SubstituteBindings より後ろにある（優先順が崩れている＝ID の有無が漏れる）"];
        }

        return [];
    }

    /**
     * 実在する会社・部門・メールドメインを 1 回だけ作る。
     *
     * ⚠ **部門に所属者を付けない。** 付けると `OrganizationController::destroyDepartment()`
     *   自身の「所属者がいれば削除できない」というガードが先に立ち、門番の判定を `$next()`
     *   の後ろへ動かす変異（クライアントには 403 のまま返るが、コントローラの副作用は
     *   既に実行済み）があっても部門はどちらの理由でも消えず、DB の行数比較
     *   （`test_every_admin_route_refuses_outsiders_without_revealing_ids` 末尾）が
     *   その変異を検出できなくなる（Bug #48 と同じ「安全網が主機構の変異を隠す」型）。
     *
     * @return array{0: ApprovalCompany, 1: ApprovalDepartment, 2: ApprovalMailDomain}
     */
    private function makeOrganizationFixtures(): array
    {
        $company    = ApprovalCompany::create(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1]);
        $department = ApprovalDepartment::create([
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1,
        ]);
        $mailDomain = ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        return [$company, $department, $mailDomain];
    }

    /**
     * 実在する ID に使う「利用者」の相手（`{user}` パラメータ）。決裁のみ・かつ決裁の権限を
     * 持たない人にする（このテストに決裁の管理者は登場しない）。
     *
     * ⚠ 理由は「本人だから通る」ではない —— `Approval\UserController::assertManageable()` が
     *   `toggleStatus` / `reissue` で「基幹を使う人」「決裁の権限を持つ人」を独自に 403 で
     *   断る（D8・D16）。相手がそちらに当たると、`approval.admin` の門番を丸ごと外しても
     *   assertManageable() の 403 に紛れて「守られている」ように見え、門番自身の欠落を
     *   検出できなくなる（Bug #48 と同じ「安全網が検出力を奪う」型。実測で確認 ——
     *   決裁の管理者が決裁のみ・無権限の相手に `toggleStatus` すると成功（302）、基幹を使う
     *   相手だと assertManageable() の 403（文言が門番と違う）になった）。この人を実在する ID
     *   に使うことで、既存 ID の要求が「門番」由来の 403 になることを
     *   `assertRefusedByGate()`（下記）で例外の文言まで見て保証する。
     */
    private function makeManageableUser(): User
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $this->assertTrue($user->isApprovalOnly(), '実在する ID に使う相手が決裁のみ利用者になっていない');
        $this->assertFalse(
            $user->hasApprovalPrivileges(),
            '実在する ID に使う相手が決裁の権限を持っている（assertManageable() の 403 と門番の 403 が区別できなくなる）'
        );

        return $user;
    }

    /**
     * 権限の無い 6 人（設計書 §5.17）。すべて `must_change_password` を false にする。
     *
     * ⚠ **理由は「true だと転送されて 403 を測れなくなるから」ではない。** `ForcePasswordChange`
     *   （`password.change`）は優先順のリストに載っていない。`EnsureApprovalAdmin` が
     *   `SubstituteBindings` より前で実行されるのは、① `appendToPriorityList` で
     *   `SubstituteBindings` より前に置かれているため、② 優先順に無いミドルウェアは
     *   `SortedMiddleware` が相対順を変えないため、宣言順で `SubstituteBindings`（web
     *   グループ既定）が `password.change`（`routes/web.php` の個別グループ。`routes/approval.php`
     *   はその中で読まれる）より先に積まれているため。実測（このテスト作成時）:
     *   `approvals.admin.*` は `must_change_password` の値に関わらず 403 のまま
     *   （factory はこの属性を明示しない限り in-memory では null になり、`fresh()` した
     *   モデルで初めて真偽値が確定する。ここでは全員に明示的に false を渡す）。false にするのは、
     *   この 6 人を**素のユーザー**にして、このテストが測るものを「門番自身の 403 判定」だけに
     *   絞るため。
     *
     * ⚠ 各人の状態をここで assert する（fixture の壊れを防ぐ。例えば `can_view_all` が
     *   将来 `$fillable` から外れたら、「全件閲覧者」は「印の行はあるが両方 false」の
     *   別人になり、本来検査したい「3 状態目」を検査していないことになる）。
     *
     * @return array<string, User>
     */
    private function outsiders(): array
    {
        $outsiders = [];

        // ロールごとに 1 人、決裁の印を持たない人（設計書 §5.17 の一般化）
        foreach (UserRole::cases() as $role) {
            $user = $role === UserRole::ApprovalOnly
                ? User::factory()->approvalOnly()->create(['must_change_password' => false])
                : User::factory()->create(['role' => $role->value, 'must_change_password' => false]);

            $this->assertSame($role, $user->role, "fixture: {$role->value} の role が違う");
            $this->assertNull($user->approvalMember, "fixture: {$role->value} に決裁の印が付いている");
            $this->assertFalse($user->isApprovalAdmin(), "fixture: {$role->value} が決裁の管理者になっている");

            $label             = $role === UserRole::ApprovalOnly ? '決裁のみ利用者（管理者でない）' : "指定の無い{$role->label()}";
            $outsiders[$label] = $user;
        }

        // 「3 状態目」— 決裁の印の行は在るが is_admin は false（既定値）
        $viewAllMember = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);
        ApprovalMember::create(['user_id' => $viewAllMember->id, 'can_view_all' => true]);
        $viewAllMember = $viewAllMember->fresh();
        $this->assertNotNull($viewAllMember->approvalMember, 'fixture: 全件閲覧者に決裁の印が無い');
        $this->assertTrue($viewAllMember->canViewAllApprovals(), 'fixture: 全件閲覧者の can_view_all が立っていない');
        $this->assertFalse($viewAllMember->isApprovalAdmin(), 'fixture: 全件閲覧者が決裁の管理者になっている');
        $outsiders['全件閲覧者（3状態目）'] = $viewAllMember;

        // 社長（D16）。指定は ApprovalSetting の 1 行だけで、決裁の管理者とは無関係
        $president = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);
        $setting   = ApprovalSetting::current();
        $setting->president_user_id = $president->id;
        $setting->save();
        $president = $president->fresh();
        $this->assertTrue($president->isApprovalPresident(), 'fixture: 社長の指定が反映されていない');
        $this->assertFalse($president->isApprovalAdmin(), 'fixture: 社長が決裁の管理者になっている');
        $outsiders['社長（管理者でない）'] = $president;

        return $outsiders;
    }

    /** 権限の無い要求で行数が変わっていないことを見るための行数一覧 */
    private function tableCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'approval_companies' => DB::table('approval_companies')->count(),
            'approval_departments' => DB::table('approval_departments')->count(),
            'approval_mail_domains' => DB::table('approval_mail_domains')->count(),
            'approval_members' => DB::table('approval_members')->count(),
            'approval_department_user' => DB::table('approval_department_user')->count(),
            'approval_setting_logs' => DB::table('approval_setting_logs')->count(),
            'approval_settings' => DB::table('approval_settings')->count(),
        ];
    }

    /**
     * 組み立てた URL が「このルート自身」に実際に配送されることを確かめる（Bug #45 の型）。
     *
     * `$route->matches()` はこのルート**自身**の条件しか見ないので、登録順で別のルートに
     * 先取りされていても気づけない（`routes/approval.php:41-43` が名指しする罠）。
     * `Route::getRoutes()->match()` で全ルートの中から実際に選ばれるものを見る。
     */
    private function matchedRouteProblem(RoutingRoute $route, string $url, string $method, string $label): ?string
    {
        try {
            $matched = Route::getRoutes()->match(Request::create($url, $method));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException $e) {
            return "{$label}: 組み立てた URL ({$url}) がどのルートにも配送されない（" . $e::class . '）';
        }

        if ($matched !== $route) {
            return "{$label}: 組み立てた URL ({$url}) が別のルート（" . ($matched->getName() ?? $matched->uri()) . '）に先取りされている';
        }

        return null;
    }

    /**
     * 403 が「この門番」由来であることを見る。`assertManageable()`（`Approval\UserController`）
     * も別の理由で 403 を返すため、状態コードだけでは区別できない。
     *
     * `TestResponse::__get()` が未知のプロパティを `baseResponse` へ委譲し、
     * `Illuminate\Foundation\Exceptions\Handler::render()` が `$response->withException($e)` で
     * 積んだ例外を返す（`Illuminate\Http\ResponseTrait::withException()`。実測で確認 ——
     * 門番の 403 は `$response->exception->getMessage() === EnsureApprovalAdmin::MESSAGE`、
     * assertManageable() の 403 は別の文言になった）。
     */
    private function assertRefusedByGate(TestResponse $response, string $context): ?string
    {
        $status = $response->getStatusCode();

        if ($status === 404) {
            return "{$context}: 404 になった（門番が無いか、ルートモデル結合より後ろにある。ID の有無が漏れる）";
        }
        if ($status >= 500) {
            return "{$context}: {$status} になった";
        }
        if ($status !== 403) {
            return "{$context}: 403 で拒否されない（status={$status}）";
        }

        $exceptionMessage = $response->exception?->getMessage();
        if ($exceptionMessage !== EnsureApprovalAdmin::MESSAGE) {
            return "{$context}: 403 だが門番の文言でない（実際: " . ($exceptionMessage ?? '(例外なし)') . '）';
        }

        return null;
    }

    /**
     * 1 ルートを、全 HTTP メソッド × 全変種（実在する ID / しない ID） × 全権限の無い人で叩く。
     *
     * @param  array<string, string>  $existingValues
     * @param  array<string, User>  $outsiders
     * @return array{problems: list<string>, requests: int}
     */
    private function checkAdminRoute(RoutingRoute $route, array $existingValues, array $outsiders): array
    {
        $problems = [];
        $requests = 0;

        $parameterNames = $route->parameterNames();
        $unclassified   = array_diff($parameterNames, array_keys($existingValues));

        if ($unclassified !== []) {
            foreach ($unclassified as $paramName) {
                $problems[] = $route->uri() . ": パラメータ {$paramName} の実在する ID の作り方が分類されていない（既存値のマップに追加すること）";
            }

            return ['problems' => $problems, 'requests' => 0];
        }

        // パラメータの無いルートは「実在する ID」という言い方が実体と合わない
        $variants = $parameterNames === []
            ? ['パラメータなし' => $existingValues]
            : ['実在する ID' => $existingValues, '実在しない ID' => array_fill_keys($parameterNames, '999999')];

        foreach ($this->nonHeadMethods($route) as $method) {
            foreach ($variants as $variantLabel => $paramValues) {
                $label = "{$method} {$route->uri()} [{$variantLabel}]";
                $url   = $this->urlForRoute($route, fn (string $name): string => $paramValues[$name]);

                $matchProblem = $this->matchedRouteProblem($route, $url, $method, $label);
                if ($matchProblem !== null) {
                    $problems[] = $matchProblem;

                    continue;
                }

                foreach ($outsiders as $outsiderLabel => $outsider) {
                    $requests++;

                    // セッションが前の相手のログイン状態を持ち越さないようにする（下記 outsiders() の
                    // docblock と対。全員 factory の同じハッシュ済みパスワードを共有しているので
                    // 今は無くても通るが、それに依存しない）
                    $this->flushSession();

                    $response = $this->actingAs($outsider)->call($method, $url);
                    $problem  = $this->assertRefusedByGate($response, "{$label} ({$outsiderLabel})");

                    if ($problem !== null) {
                        $problems[] = $problem;
                    }
                }
            }
        }

        return ['problems' => $problems, 'requests' => $requests];
    }

    public function test_every_admin_route_refuses_outsiders_without_revealing_ids(): void
    {
        [$company, $department, $mailDomain] = $this->makeOrganizationFixtures();
        $manageableUser = $this->makeManageableUser();

        $existingValues = [
            'user' => (string) $manageableUser->id,
            'approvalCompany' => (string) $company->id,
            'approvalDepartment' => (string) $department->id,
            'mailDomain' => (string) $mailDomain->id,
        ];

        $outsiders = $this->outsiders();

        $before = $this->tableCounts();

        $adminRoutesChecked = 0;
        $requestsMade       = 0;
        $problems           = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'approvals.admin.')) {
                continue;
            }

            $adminRoutesChecked++;

            $result       = $this->checkAdminRoute($route, $existingValues, $outsiders);
            $problems     = [...$problems, ...$result['problems']];
            $requestsMade += $result['requests'];
        }

        // ⚠ 問題の中身を先に見る。下限だけを先に見ると、パラメータ名の変更や `where` に
        //   よる拒否で件数が減ったときに「走査が空振りしている」としか分からず、$problems
        //   に出ている本当の理由（どのルート・どの変種・どの相手で止まらなかったか）が隠れる。
        $this->assertSame([], $problems, "権限の無い利用者を止められていないルート:\n" . implode("\n", $problems));

        // 走査が空振りして緑になる事故を防ぐ（実測 18 ルート。パラメータなし 10 本 × 1 変種
        // ＋ パラメータあり 8 本 × 2 変種 ＝ 26 通り、現状はいずれもメソッド 1 つずつ、× 6 人 ＝ 156 件）
        $this->assertGreaterThanOrEqual(18, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
        $this->assertGreaterThanOrEqual(156, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');

        // ⚠ これが唯一、門番の判定を $next() の後ろへ動かす変異（クライアントには 403 の
        //   まま返るが、コントローラの副作用は既に実行済み）を検出できる（会社・部門・
        //   ドメインが実際に消え、利用者が実際に更新される）。この歯止めが働くのは
        //   `makeOrganizationFixtures()` が部門に所属者を付けていないから（同メソッドの docblock）。
        $this->assertSame($before, $this->tableCounts(), '権限の無い要求で DB の行数が変化した');
    }
}
