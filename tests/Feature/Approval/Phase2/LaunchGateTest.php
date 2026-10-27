<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalLaunchStage;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureApprovalAdmin;
use App\Http\Middleware\EnsureApprovalLaunched;
use App\Models\ApprovalRequest;
use App\Support\Approval\LaunchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsRouteUrls;
use Tests\TestCase;

/**
 * 使い始める前は、申請を回す画面を誰にも見せない（設計書 §5.2・D1・計画 §0.6）。
 *
 * ⚠ 分類は**全件**で見る（Top trap #13）。新しい `approvals.` のルートは、準備中も開く一覧
 *   （OPEN_BEFORE_LAUNCH）に入れるか、`approval.launched` を付けるかのどちらか。
 */
class LaunchGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsRouteUrls;

    /** 準備中も開いてよいルート（名前が完全一致か、`.` で終わるものは先頭一致） */
    private const OPEN_BEFORE_LAUNCH = [
        'approvals.home'                => '準備中の画面を出す（設計書 §5.2）',
        'approvals.admin.users.'        => '利用者の管理（段階1。準備の画面）',
        'approvals.admin.organization.' => '部門の管理（準備の画面。本番で先に登録する。D1）',
        'approvals.admin.types.'        => '申請種類の管理（準備の画面。D1）',
        'approvals.admin.holidays.'     => '催促の設定（準備の画面。使い始める前から送らない日を登録できる。段階3 設計書 §5.9）',
        'approvals.admin.papers.'       => '過去の紙の決裁の取り込み（準備の画面。使い始める日までに取り込む。段階5 設計書 D11）',
    ];

    /**
     * 門番の付いたルートの数の下限（空振りで緑にならないように）。
     * 実数 27 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2a）＋ 進行中の申請の管理 4（2b。管理の門番も持つ）
     * ＋ お知らせ 3（3a）＋ PDF 1・台帳と Excel 2（4b）＋ 紙の決裁の詳細 1（5b）。
     */
    private const MIN_GATED = 27;

    private function isOpenBeforeLaunch(string $name): bool
    {
        foreach (array_keys(self::OPEN_BEFORE_LAUNCH) as $pattern) {
            if (str_ends_with($pattern, '.') ? str_starts_with($name, $pattern) : $name === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * `bootstrap/app.php` の別名・グループ・優先順は、HTTP の Kernel が解決されて初めて Router に同期される
     * （ApplicationBuilder の afterResolving フック。Router は既定でどれも空）。HTTP を出さずに
     * `gatherRouteMiddleware()` を呼ぶテストは、その前に必ずこれを呼ぶ。呼ばないと別名 `approval.launched` が
     * 解決されず、門番が 1 本も見つからない（実装の正誤に関係なく、分類は空振りし、並びのテストは落ちる。実測。
     * `ApprovalAdminGateTest::test_every_approvals_route_is_classified` と同じ理由・同じ直し方）。
     */
    private function resolveHttpKernel(): void
    {
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $this->assertArrayHasKey('approval.launched', app('router')->getMiddleware(), '門番の別名が解決されていない（Kernel を解決していない）');
    }

    public function test_every_approval_route_is_classified(): void
    {
        $this->resolveHttpKernel();

        $problems = [];
        $gated    = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'approvals.')) {
                continue;
            }

            $hasGate = in_array(EnsureApprovalLaunched::class, app('router')->gatherRouteMiddleware($route), true);
            $open    = $this->isOpenBeforeLaunch($name);

            if ($open && $hasGate) {
                $problems[] = "{$name}: 準備中も開く画面なのに approval.launched が付いている";
            }
            if (! $open && ! $hasGate) {
                $problems[] = "{$name}: 申請を回す画面なのに approval.launched が無い（準備中に誰でも開ける）";
            }
            if ($hasGate) {
                $gated++;
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
        $this->assertGreaterThanOrEqual(self::MIN_GATED, $gated, '門番の付いたルートが少なすぎる（分類が空振りしていないか）');
    }

    /** 門番だけを試す見本のルート（このテストの中だけで在る。型宣言でルートモデル結合を起こす） */
    private function probeRoutes(): void
    {
        Route::middleware(['web', 'auth', 'approval.launched'])->group(function (): void {
            Route::get('/approvals/_probe/{approvalRequest}', fn (ApprovalRequest $approvalRequest) => 'ok')->name('approvals._probe.show');
            Route::post('/approvals/_probe', fn () => 'ok')->name('approvals._probe.store');
        });
    }

    /** @return array<string, \App\Models\User> 決裁のみ利用者・決裁の管理者・基幹の利用者（D1「誰にも見せない。決裁の管理者にも」） */
    private function everyKindOfUser(): array
    {
        return [
            '決裁のみ利用者' => $this->approvalOnlyUser(),
            '決裁の管理者'   => $this->approvalAdmin(),
            '基幹の利用者'   => $this->baseUser(),
        ];
    }

    /** 準備中に画面を開くと、ホーム（「準備中」を出す）へ送る。誰が開いても同じ（D1）。存在しない ID でも 404 にしない */
    public function test_before_launch_a_page_goes_to_the_home(): void
    {
        $this->probeRoutes();

        foreach ($this->everyKindOfUser() as $label => $user) {
            $response = $this->actingAs($user)->get('/approvals/_probe/999999');

            $this->assertSame(302, $response->getStatusCode(), "{$label}: 転送されていない");
            $this->assertSame(route('approvals.home'), $response->headers->get('Location'), "{$label}: ホームへ送られていない");
        }
    }

    public function test_before_launch_a_post_is_not_found(): void
    {
        $this->probeRoutes();

        foreach ($this->everyKindOfUser() as $label => $user) {
            $this->assertSame(404, $this->actingAs($user)->post('/approvals/_probe')->getStatusCode(), "{$label}: 404 になっていない");
        }
    }

    /**
     * Ajax・JSON は転送せず 404。合図は 1 つずつ分けて送る（X-Requested-With だけ・Accept: application/json だけ）。
     * ⚠ 実在する ID でも試す（存在しない ID だけだと、門番が素通しでもルートモデル結合が 404 を返して緑になる）。
     */
    public function test_before_launch_an_ajax_request_is_not_found(): void
    {
        $this->probeRoutes();
        $request = $this->draftFor($this->approvalWorld());   // 結合が通る ID。404 を返せるのは門番だけ
        $user    = $this->approvalOnlyUser();

        foreach ([$request->id, 999999] as $id) {
            $this->actingAs($user)->get("/approvals/_probe/{$id}", ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])->assertNotFound();
            $this->actingAs($user)->get("/approvals/_probe/{$id}", ['Accept' => 'application/json'])->assertNotFound();
        }
    }

    public function test_after_launch_the_page_opens(): void
    {
        $this->probeRoutes();
        $request = $this->draftFor($this->approvalWorld());
        $this->launchApprovals();

        $this->actingAs($this->approvalOnlyUser())->get('/approvals/_probe/' . $request->id)->assertOk()->assertSee('ok');
    }

    public function test_in_trial_a_tester_passes_the_gate(): void
    {
        $this->probeRoutes();
        $request = $this->draftFor($this->approvalWorld());
        $tester  = $this->approvalOnlyUser();
        $this->makeTester($tester);

        $this->actingAs($tester)->get('/approvals/_probe/' . $request->id)->assertOk()->assertSee('ok');
    }

    /**
     * 試す期間に試す人でない人は、止める側の**すべての**ルートで準備中と同じ応答（画面を開く GET はホームへ・それ以外は 404。
     * 存在しない ID でも同じ）。決裁の管理者でも同じ（段階6 設計書 §5.2・§6）
     */
    public function test_in_trial_a_non_tester_gets_the_same_answers_as_before_launch(): void
    {
        $this->resolveHttpKernel();
        $this->makeTester($this->approvalOnlyUser(['name' => '試す 人']));    // 試す期間にする（試すのは別の人）
        $this->assertSame(ApprovalLaunchStage::Trial, LaunchState::stage(), '試す期間になっていない（準備中のテストとして空振りしていないか）');
        $users   = ['決裁の管理者' => $this->approvalAdmin(), '決裁のみ利用者' => $this->approvalOnlyUser(), '基幹の管理者' => $this->baseUser(['role' => UserRole::Executive->value])];   // 設計書 §6 の人（UserRole を use）
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);
            if (! str_starts_with((string) $route->getName(), 'approvals.') || ! in_array(EnsureApprovalLaunched::class, $middleware, true)) {
                continue;
            }
            $url = $this->urlForRoute($route, fn (string $name) => $name === 'notice' ? (string) Str::uuid() : '999999');

            foreach ($users as $label => $user) {
                if (! $user->isApprovalAdmin() && in_array(EnsureApprovalAdmin::class, $middleware, true)) {
                    continue;   // 管理の門番が先に 403（ここで見るのは稼働の門番）
                }
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $response = $this->actingAs($user)->call($method, $url);
                    $where    = "{$label} {$method} {$route->getName()}";
                    $method === 'GET'
                        ? $this->assertSame(route('approvals.home'), $response->headers->get('Location'), $where)
                        : $this->assertSame(404, $response->getStatusCode(), $where);
                    $checked++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_GATED, $checked, '空振りしていないか');
    }

    public function test_in_trial_a_non_tester_gets_the_same_answers_for_a_real_request(): void
    {
        $w        = $this->trialWorld();
        $request  = $this->submittedFor($w);
        $outsider = $this->approvalAdmin();     // 試す人でない決裁の管理者

        $this->actingAs($outsider)->get(route('approvals.requests.show', $request))->assertRedirect(route('approvals.home'));
        $this->actingAs($outsider)->get(route('approvals.requests.pdf', $request))->assertRedirect(route('approvals.home'));
        $this->actingAs($outsider)->post(route('approvals.requests.withdraw', $request))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('approvals.requests.show', $request))->assertNotFound();
        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk();
    }

    /** 門番はルートモデル結合より前（データの有無を漏らさない）・決裁の管理の門番より後（管理の画面は 403 が先） */
    public function test_the_gate_runs_after_the_admin_gate_and_before_bindings(): void
    {
        $this->resolveHttpKernel();

        $route  = Route::middleware(['web', 'approval.admin', 'approval.launched'])->get('/approvals/_probe_order', fn () => 'ok');
        $sorted = array_values(app('router')->gatherRouteMiddleware($route));

        $admin    = array_search(EnsureApprovalAdmin::class, $sorted, true);
        $launched = array_search(EnsureApprovalLaunched::class, $sorted, true);
        $bindings = array_search(SubstituteBindings::class, $sorted, true);

        $this->assertIsInt($admin);
        $this->assertIsInt($launched);
        $this->assertIsInt($bindings);
        $this->assertLessThan($launched, $admin, '決裁の管理の門番が先に走っていない');
        $this->assertLessThan($bindings, $launched, 'ルートモデル結合より後に走っている（存在しない ID が 404 になる）');
    }
}
