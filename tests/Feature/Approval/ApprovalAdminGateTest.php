<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
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
use Tests\Concerns\BuildsRouteUrls;
use Tests\TestCase;

/**
 * 決裁の管理（`approvals.admin.*`）の門番を、全ルート × 権限の無い 4 人 × ID の有無で守る。
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
 * ⚠ **権限の無い 4 人を見る**（設計書 §5.17）。
 *   ① 指定の無い経営層 — 基幹の最上位ロールでも、決裁の管理者に指定されなければ通らない
 *   ② 全件閲覧者（`can_view_all=true` かつ `is_admin=false`）—「3 状態目」。`can_view_all` と
 *      `is_admin` は `Admin\UserController` が独立に保存するので、決裁の印の行が**在る**が
 *      管理者ではない人が実在する。2026-09-18 のレビューまでこの状態を叩くテストが 1 本も
 *      無く、門番の判定を「行の有無」に取り違える変異（権限昇格）が検出できなかった
 *   ③ 決裁のみ利用者（管理者に指定されていない）— 1 段目の門番 `RestrictApprovalOnlyUsers` は
 *      ルート名が `approvals.` で始まるものを無条件で通すので、この人を止めるのは
 *      **この 2 段目だけ**
 *   ④ 社長（管理者に指定されていない）— `User::hasApprovalPrivileges()` は
 *      「社長 || 決裁の管理者 || 全件閲覧者」を数えるが、この門番（`isApprovalAdmin()`）を
 *      通れるのは**決裁の管理者だけ**。①〜③だけでは `isApprovalAdmin() || isApprovalPresident()`
 *      のような取り違え（社長も通してしまう変異）を検出できない
 *
 * ⚠ **組み立てた URL がそのルート自身に当たることを確認する**（`$route->matches()`）。
 *   確認しないと、パラメータの並びや見落とした `where` のせいで別のルートを検査していても
 *   気づけない（`ApprovalOnlyLockoutTest` と同じ流儀）。
 *
 * ⚠ **(a)（この後の分類テスト）だけでは、外しても静的には分からない型を見逃す。**
 *   `Route::gatherMiddleware()` は `$this->middleware()` と `$this->controllerMiddleware()` の
 *   和集合を返すだけで、`withoutMiddleware()` / `excludedMiddleware()` を差し引かない
 *   （`vendor/laravel/framework/src/Illuminate/Routing/Route.php` の `gatherMiddleware()` の実装。
 *   ソースで確認済み）。だから誰かがルートに `->withoutMiddleware('approval.admin')` を付けても、
 *   (a) の走査は「approval.admin が付いている」と誤って報告し続ける。実際に外れたことを
 *   検出できるのは (b)（挙動を実際に叩くテスト）だけなので、**(a) があるからと言って (b) を
 *   削らない**（この 2 本は対で維持する）。
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

    /**
     * 実在する ID の作り方が分かっているルートパラメータ名の一覧。
     *
     * ここに無い名前を持つルートは検査せず、分類漏れとして問題に積む
     * （新しいパラメータを足したら、ここと実在値の両方に追加すること）。
     */
    private const KNOWN_PARAMETER_NAMES = ['user', 'approvalCompany', 'approvalDepartment', 'mailDomain'];

    /** HEAD を除いた先頭の HTTP メソッド（ラベル・実要求の両方で使う） */
    private function httpMethodOf(RoutingRoute $route): string
    {
        return collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
    }

    public function test_every_approvals_route_is_classified(): void
    {
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
                if (! in_array('approval.admin', $route->gatherMiddleware(), true)) {
                    $problems[] = "{$label}: approval.admin 門番が付いていない";
                }

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
     * 権限の無い 4 人（設計書 §5.17）。すべて `must_change_password` を false にする。
     *
     * ⚠ **理由は「true だと転送されて 403 を測れなくなるから」ではない。** 実測（2026-09-18、
     *   このテストで直接叩いて確認）: `EnsureApprovalAdmin` は優先順で `password.change`
     *   （`ForcePasswordChange`）より前に立つので、`approvals.admin.*` は true でも 403 の
     *   まま。302（`/password/change` へ転送）になるのは `approval.admin` を持たない
     *   `approvals.home` だけ。false にするのは、この 4 人を**素のユーザー**にして、
     *   このテストが測るものを「門番自身の 403 判定」だけに絞るため——true のままだと、
     *   将来 `password.change` 側の優先順が変わって 302 が混ざり込んだとき、それが
     *   「門番が壊れた」のか「別の経路に流れた」のかをこのテストの失敗だけでは区別できない。
     *
     * ⚠ 各人の状態をここで assert する（fixture の壊れを防ぐ。例えば `can_view_all` が
     *   将来 `$fillable` から外れたら、②の「全件閲覧者」は「印の行はあるが両方 false」の
     *   別人になり、本来検査したい「3 状態目」を検査していないことになる）。
     *
     * @return array<string, User>
     */
    private function outsiders(): array
    {
        $executive = User::factory()->create([
            'role' => UserRole::Executive->value, 'must_change_password' => false,
        ]);
        $this->assertSame(UserRole::Executive, $executive->role, 'fixture: 経営層の role が違う');
        $this->assertNull($executive->approvalMember, 'fixture: 経営層に決裁の印が付いている');
        $this->assertFalse($executive->isApprovalAdmin(), 'fixture: 経営層が決裁の管理者になっている');

        // 「3 状態目」— 決裁の印の行は在るが is_admin は false（既定値）
        $viewAllMember = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $viewAllMember->id, 'can_view_all' => true]);
        $viewAllMember = $viewAllMember->fresh();
        $this->assertNotNull($viewAllMember->approvalMember, 'fixture: 全件閲覧者に決裁の印が無い');
        $this->assertTrue($viewAllMember->canViewAllApprovals(), 'fixture: 全件閲覧者の can_view_all が立っていない');
        $this->assertFalse($viewAllMember->isApprovalAdmin(), 'fixture: 全件閲覧者が決裁の管理者になっている');

        $approvalOnly = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $this->assertTrue($approvalOnly->isApprovalOnly(), 'fixture: 決裁のみ利用者の role が違う');
        $this->assertNull($approvalOnly->approvalMember, 'fixture: 決裁のみ利用者に決裁の印が付いている');

        // 社長（D16）。指定は ApprovalSetting の 1 行だけで、決裁の管理者とは無関係
        $president = User::factory()->create(['must_change_password' => false]);
        $setting   = ApprovalSetting::current();
        $setting->president_user_id = $president->id;
        $setting->save();
        $this->assertTrue($president->isApprovalPresident(), 'fixture: 社長の指定が反映されていない');
        $this->assertFalse($president->isApprovalAdmin(), 'fixture: 社長が決裁の管理者になっている');

        return [
            '指定の無い経営層' => $executive,
            '全件閲覧者（3状態目）' => $viewAllMember,
            '決裁のみ利用者（管理者でない）' => $approvalOnly,
            '社長（管理者でない）' => $president,
        ];
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

    public function test_every_admin_route_refuses_outsiders_without_revealing_ids(): void
    {
        // --- 実在する ID を 1 回だけ作る ---
        $company    = ApprovalCompany::create(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1]);
        $department = ApprovalDepartment::create([
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1,
        ]);
        $mailDomain = ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        // 実在する ID に使う相手は「決裁のみ・かつ決裁の権限を持たない人」にする（この
        // テストに決裁の管理者は登場しない）。
        // ⚠ 理由は「本人だから通る」ではない —— `Approval\UserController::assertManageable()`
        //   が `toggleStatus` / `reissue` で「基幹を使う人」「決裁の権限を持つ人」を
        //   独自に 403 で断る（D8・D16）。相手がそちらに当たると、`approval.admin` の
        //   門番を丸ごと外しても assertManageable() の 403 に紛れて「守られている」ように
        //   見え、門番自身の欠落を検出できなくなる（Bug #48 と同じ「安全網が検出力を奪う」型）。
        //   決裁のみ・無権限の相手にすることで、門番が無ければ実際に通ってしまう状態を作り、
        //   403 が門番由来であることを保証する。
        $manageableUser = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $this->assertTrue($manageableUser->isApprovalOnly(), '実在する ID に使う相手が決裁のみ利用者になっていない');
        $this->assertFalse(
            $manageableUser->hasApprovalPrivileges(),
            '実在する ID に使う相手が決裁の権限を持っている（assertManageable() の 403 と門番の 403 が区別できなくなる）'
        );

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

            $method         = $this->httpMethodOf($route);
            $label          = $method . ' ' . $route->uri();
            $parameterNames = $route->parameterNames();

            $unclassified = array_diff($parameterNames, self::KNOWN_PARAMETER_NAMES);
            if ($unclassified !== []) {
                foreach ($unclassified as $paramName) {
                    $problems[] = "{$label}: パラメータ {$paramName} の実在する ID の作り方が分類されていない（KNOWN_PARAMETER_NAMES と実在値に追加すること）";
                }

                continue;
            }

            // パラメータの無いルートは「実在する ID」という言い方が実体と合わない
            $variants = $parameterNames === []
                ? ['パラメータなし' => $existingValues]
                : ['実在する ID' => $existingValues, '実在しない ID' => array_fill_keys($parameterNames, '999999')];

            foreach ($variants as $variantLabel => $paramValues) {
                $url = $this->urlForRoute($route, fn (string $name): string => $paramValues[$name]);

                // 組み立てた URL がこのルート自身に当たることを確かめる（Bug #45 の型）
                if (! $route->matches(Request::create($url, $method), includingMethod: false)) {
                    $problems[] = "{$label} [{$variantLabel}]: 組み立てた URL ({$url}) がこのルートに当たらない";

                    continue;
                }

                foreach ($outsiders as $outsiderLabel => $outsider) {
                    $requestsMade++;

                    $status = $this->actingAs($outsider)->call($method, $url)->getStatusCode();

                    if ($status === 403) {
                        continue;
                    }

                    if ($status === 404) {
                        $problems[] = "{$label} [{$variantLabel}] ({$outsiderLabel}): 404 になった（ルートモデル結合より後ろで止まっている＝ID の実在が漏れる）";
                    } elseif ($status >= 500) {
                        $problems[] = "{$label} [{$variantLabel}] ({$outsiderLabel}): {$status} になった";
                    } else {
                        $problems[] = "{$label} [{$variantLabel}] ({$outsiderLabel}): 403 で拒否されない（status={$status}）";
                    }
                }
            }
        }

        // ⚠ 問題の中身を先に見る。下限だけを先に見ると、パラメータ名の変更や `where` に
        //   よる拒否で件数が減ったときに「走査が空振りしている」としか分からず、$problems
        //   に出ている本当の理由（どのルート・どの変種・どの相手で止まらなかったか）が隠れる。
        $this->assertSame([], $problems, "権限の無い利用者を止められていないルート:\n" . implode("\n", $problems));

        // 走査が空振りして緑になる事故を防ぐ（実測 18 ルート・パラメータなし 10 本 × 1 変種
        // ＋ パラメータあり 8 本 × 2 変種 ＝ 26 通り、× 4 人 ＝ 104 件）
        $this->assertGreaterThanOrEqual(18, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
        $this->assertGreaterThanOrEqual(104, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');

        // 権限の無い要求で DB の行数が 1 件も変化していないこと
        $this->assertSame($before, $this->tableCounts(), '権限の無い要求で DB の行数が変化した');
    }
}
