<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 決裁の管理（`approvals.admin.*`）の門番を、全ルート × 権限の無い 3 人 × ID の有無で守る。
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
 * ⚠ **権限の無い 3 人を見る**（設計書 §5.17）。
 *   ① 指定の無い経営層 — 基幹の最上位ロールでも、決裁の管理者に指定されなければ通らない
 *   ② 全件閲覧者（`can_view_all=true` かつ `is_admin=false`）—「3 状態目」。`can_view_all` と
 *      `is_admin` は `Admin\UserController` が独立に保存するので、決裁の印の行が**在る**が
 *      管理者ではない人が実在する。2026-09-18 のレビューまでこの状態を叩くテストが 1 本も
 *      無く、門番の判定を「行の有無」に取り違える変異（権限昇格）が検出できなかった
 *   ③ 決裁のみ利用者（管理者に指定されていない）— 1 段目の門番 `RestrictApprovalOnlyUsers` は
 *      ルート名が `approvals.` で始まるものを無条件で通すので、この人を止めるのは
 *      **この 2 段目だけ**
 *
 * ⚠ **組み立てた URL がそのルート自身に当たることを確認する**（`$route->matches()`）。
 *   確認しないと、パラメータの並びや見落とした `where` のせいで別のルートを検査していても
 *   気づけない（`ApprovalOnlyLockoutTest` と同じ流儀）。
 */
class ApprovalAdminGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `approvals.admin.` 以外で `approvals.` を名乗ってよいルート（名前 => 理由）。
     *
     * ⚠ 「web の外だから」のような自動判定はしない。**理由を書いて名指しする**
     *   （新しく増えたルートは、ここに足すまで分類漏れとして落ちる）。
     */
    private const OPEN_TO_EVERY_USER = [
        'approvals.home' => '決裁のホーム（全ログイン利用者が入れる。設計書 §5.3）',
    ];

    /**
     * ルートパラメータ名 => 実在する ID の作り方が分かっている名前の一覧。
     *
     * ここに無い名前を持つルートは検査せず、分類漏れとして問題に積む
     * （新しいパラメータを足したら、ここと実在値の両方に追加すること）。
     */
    private const KNOWN_PARAMETER_NAMES = ['user', 'approvalCompany', 'approvalDepartment', 'mailDomain'];

    /** `where` の条件は今のところ無いので `{name}` を渡された値へそのまま置き換える */
    private function urlFor(RoutingRoute $route, array $values): string
    {
        $uri = $route->uri();

        foreach ($route->parameterNames() as $name) {
            $uri = str_replace('{' . $name . '}', (string) $values[$name], $uri);
        }

        return '/' . ltrim($uri, '/');
    }

    public function test_every_approvals_route_is_classified(): void
    {
        $found         = 0;
        $seenOpenNames = [];
        $problems      = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'approvals.')) {
                continue;
            }

            $found++;
            $label = $name . ' (' . $route->uri() . ')';

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

        // 走査が空振りして緑になる事故を防ぐ（実測 19 本 = 決裁の管理 18 本 + ホーム 1 本）
        $this->assertGreaterThanOrEqual(19, $found, 'approvals. のルートの走査に失敗している');
        $this->assertSame([], $problems, "分類漏れ・門番の欠落:\n" . implode("\n", $problems));
    }

    /**
     * 権限の無い 3 人（設計書 §5.17）。すべて `must_change_password` を false にする
     * （true だと `ForcePasswordChange` が転送し、門番より手前で 302 を観測してしまう）。
     *
     * @return array<string, User>
     */
    private function outsiders(): array
    {
        $executive = User::factory()->create([
            'role' => UserRole::Executive->value, 'must_change_password' => false,
        ]);

        // 「3 状態目」— 決裁の印の行は在るが is_admin は false（既定値）
        $viewAllMember = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $viewAllMember->id, 'can_view_all' => true]);

        $approvalOnly = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        return [
            '指定の無い経営層' => $executive,
            '全件閲覧者（3状態目）' => $viewAllMember->fresh(),
            '決裁のみ利用者（管理者でない）' => $approvalOnly,
        ];
    }

    /** 変異が本物のデータへ触れていないかを見るための行数一覧 */
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

        // 管理者が実際に管理できる相手（管理者自身の ID にはしない。自分自身を操作対象にすると
        // 「本人だから通る」という別の抜け道が紛れ込みうるため、別人の行を用意する）
        $manageableUser = User::factory()->approvalOnly()->create(['must_change_password' => false]);

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

            $method         = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $label          = $method . ' ' . $route->uri();
            $parameterNames = $route->parameterNames();

            $unclassified = array_diff($parameterNames, self::KNOWN_PARAMETER_NAMES);
            if ($unclassified !== []) {
                foreach ($unclassified as $paramName) {
                    $problems[] = "{$label}: パラメータ {$paramName} の実在する ID の作り方が分類されていない（KNOWN_PARAMETER_NAMES と実在値に追加すること）";
                }

                continue;
            }

            $variants = ['実在する ID' => $existingValues];
            if ($parameterNames !== []) {
                $variants['実在しない ID'] = array_fill_keys($parameterNames, '999999');
            }

            foreach ($variants as $variantLabel => $paramValues) {
                $url = $this->urlFor($route, $paramValues);

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

        // 走査が空振りして緑になる事故を防ぐ（実測 18 ルート・8 本にパラメータあり ＝ (18+8)×3=78 件）
        $this->assertGreaterThanOrEqual(18, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
        $this->assertGreaterThanOrEqual(78, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');

        $this->assertSame([], $problems, "権限の無い利用者を止められていないルート:\n" . implode("\n", $problems));

        // 権限の無い要求で DB の行数が 1 件も変化していないこと
        $this->assertSame($before, $this->tableCounts(), '権限の無い要求で DB の行数が変化した');
    }
}
