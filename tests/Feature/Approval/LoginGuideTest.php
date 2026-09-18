<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use App\Support\Approval\LoginGuide;
use App\Support\OneTimeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ログイン案内の画面（設計書 §5.12・D4・D9）。
 *
 * ⚠ 初期パスワードは**この画面にだけ**出る。DB（ハッシュを除く）・セッション・ログ・記録に残さない。
 * ⚠ 1 回限りの鍵で二重の実行を止める（ブラウザの「フォームを再送信しますか」で
 *   印刷済みの案内がこっそり無効になるのを防ぐ）。
 */
class LoginGuideTest extends TestCase
{
    use RefreshDatabase;

    private function guide(): LoginGuide
    {
        $a = User::factory()->create(['name' => '甲 一郎', 'employee_number' => 'M001', 'email' => null, 'must_change_password' => true]);
        $b = User::factory()->create(['name' => '乙 二郎', 'employee_number' => null, 'email' => 'b@example.com', 'must_change_password' => true]);

        return new LoginGuide([
            ['user' => $a, 'password' => 'abcde23456'],
            ['user' => $b, 'password' => 'fghij78923'],
            // ⚠ **わざと非対称**にしてある（人数は載せる値であって行数とは別物）。
            //    1 対 1 にすると、帯の 2 つの数字が入れ替わっても原理的に見えない。
        ], notifiedCount: 2, skippedCount: 1);
    }

    private function render(): \Illuminate\Testing\TestResponse
    {
        $admin = User::factory()->create(['must_change_password' => false]);

        return $this->actingAs($admin)->get('/_test/login-guide');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 描画だけを見るための使い捨てルート（この計画の実装では本物の POST から描画する）
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])
            ->get('/_test/login-guide', fn () => $this->guide()->toResponse(request()));
    }

    /** 保存させない（戻るボタンや履歴からパスワードが読めないように） */
    public function test_the_page_is_not_cached(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->render()->headers->get('Cache-Control'));
    }

    public function test_it_shows_one_page_per_person(): void
    {
        $html = $this->render()->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="guide-page"'), '1 人 1 ページになっていない');
        $this->assertStringContainsString('甲 一郎', $html);
        $this->assertStringContainsString('乙 二郎', $html);
        $this->assertStringContainsString('abcde23456', $html);
        $this->assertStringContainsString('fghij78923', $html);
    }

    /** ログイン ID は社員番号、無ければメールアドレス */
    public function test_the_login_id_falls_back_to_the_email(): void
    {
        $html = $this->render()->getContent();

        $this->assertStringContainsString('M001', $html);
        $this->assertStringContainsString('b@example.com', $html);
    }

    public function test_it_embeds_the_qr_once_and_uses_it_per_page(): void
    {
        $html = $this->render()->getContent();

        $this->assertSame(1, substr_count($html, '<symbol id="login-qr"'), 'QR の定義が 1 つでない');
        $this->assertSame(2, substr_count($html, 'href="#login-qr"'), '各ページが QR を参照していない');
        $this->assertStringContainsString(route('login'), $html, 'ログイン画面の URL が出ていない');
    }

    /** サイドバーもヘッダーも出さない（印刷用の独立したページ） */
    public function test_it_is_a_standalone_page(): void
    {
        $html = $this->render()->getContent();

        $this->assertStringNotContainsString('sidebarExpanded', $html, 'サイドバーが出ている');
        $this->assertStringContainsString('@page', $html, '印刷用の @page 指定が無い');
        $this->assertStringContainsString('break-after', $html, 'ページ区切りの指定が無い');
    }

    /** 画面にだけ出る帯（印刷しない） */
    public function test_the_on_screen_bar_warns_before_closing(): void
    {
        $html = $this->render()->getContent();

        $this->assertStringContainsString('この画面を閉じると初期パスワードは二度と表示されません。', $html);
        $this->assertStringContainsString('印刷する', $html);
        $this->assertStringContainsString('2 人分', $html);
        $this->assertStringContainsString('通知メール: 送る 2 人／送らない 1 人', $html);
        $this->assertStringContainsString('beforeunload', $html, '閉じる前の確認が無い');
    }

    /**
     * 閉じる前の確認が、**印刷したあとも外れない**こと。
     *
     * ⚠ `afterprint` は「印刷ダイアログを閉じたとき」に発火し、実際に印刷したのか
     *   キャンセルしたのかを JS から区別できない（主要ブラウザ共通）。
     *   「印刷したら確認しない」にすると、プリンタの不調で一度キャンセルしただけで
     *   確認が外れ、そのまま閉じて初期パスワードが二度と表示されなくなる（実駆動で確認）。
     */
    public function test_the_close_confirmation_is_never_disabled(): void
    {
        $html = $this->render()->getContent();

        $this->assertMatchesRegularExpression(
            "/addEventListener\(\s*'beforeunload'/",
            $html,
            '閉じる前の確認が無い'
        );

        // ⚠ **語ではなく「登録しているか」で見る。** ビューの注意書き自身に `afterprint` と
        //    書いてあるので、素の `assertStringNotContainsString('afterprint', …)` は
        //    実体を消しても赤のまま通らない（Bug #42 ② / #30 と同型。実際に踏んだ）。
        $this->assertDoesNotMatchRegularExpression(
            "/addEventListener\(\s*'afterprint'/",
            $html,
            '印刷したら確認を外す作りになっている（キャンセルでも外れるので紙を配り直す事故になる）'
        );
    }

    /** 空白の無い長い氏名が用紙の外へ切れないこと（実測: 160 文字で完全に切れた） */
    public function test_a_long_name_is_allowed_to_wrap(): void
    {
        $html = $this->render()->getContent();

        $this->assertMatchesRegularExpression(
            '/\.guide-name\s*\{[^}]*overflow-wrap:\s*anywhere/',
            $html,
            '氏名の折り返し指定が無い（空白の無い長い名前が紙からはみ出して切れる）'
        );
    }

    /**
     * 1 回限りの鍵は「無ければ入れる」を**一度に**行うこと（`Cache::add`）。
     *
     * ⚠ **これは構造テスト。** 排他性そのものは 1 プロセスの逐次のテストでは原理的に測れず、
     *   しかも `phpunit.xml` の `CACHE_STORE=array` は `add()` の独自実装を持たず
     *   「`get()` して null なら `put()`」という**非排他的な**既定に落ちる。
     *   実測: `Cache::add` を `has()` ＋ `put()` に書き換えても 14 本すべて緑のまま通った。
     *   本番の `database` ドライバは `key` の主キー制約で本当に排他的なので、
     *   「`add` を使っていること」だけを構造で固定する（Bug #41 / #42 と同じ流儀）。
     */
    public function test_the_token_is_claimed_atomically(): void
    {
        $source = $this->sourceWithoutComments(app_path('Support/OneTimeAction.php'));

        $this->assertStringContainsString('Cache::add(', $source, '1 回限りの鍵が Cache::add を使っていない');
        $this->assertStringNotContainsString('Cache::has(', $source, 'has() してから put() する形は二重送信が両方通る余地がある');
        $this->assertStringNotContainsString('Cache::put(', $source);
    }

    /**
     * 有効時間が 0 でも「1 回目から false」にならないこと。
     *
     * ⚠ `Repository::add()` は秒数が 0 以下だとキーの存在も見ずに false を返すので、
     *   `.env` の書き間違いで**新規登録も再発行も無言で止まる**（実測）。
     */
    public function test_a_zero_ttl_does_not_break_everything(): void
    {
        config(['approval.guide_token_ttl_hours' => 0]);

        $token = OneTimeAction::issue();

        $this->assertTrue(OneTimeAction::claim($token), '有効時間 0 で 1 回目から弾かれている');
        $this->assertFalse(OneTimeAction::claim($token), '2 回目が通っている');
    }

    /**
     * `OneTimeAction::claimFrom()` はリクエストの `guide_token` を防御的に読む（設計書 §5.12）。
     *
     * ⚠ 配列で送られても例外にしない・無い/空文字なら false・文字列トークンなら 1 回目だけ true、
     *   という `claim()` の既存の振る舞いを `Request` から読む経路でも保つことを確かめる。
     */
    public function test_claim_from_accepts_only_a_string_token(): void
    {
        $arrayToken = Request::create('/x', 'POST', ['guide_token' => ['a', 'b']]);
        $this->assertFalse(OneTimeAction::claimFrom($arrayToken), '配列のトークンで例外にならず false を返すべき');

        $missing = Request::create('/x', 'POST', []);
        $this->assertFalse(OneTimeAction::claimFrom($missing), 'トークンが無いのに通っている');

        $empty = Request::create('/x', 'POST', ['guide_token' => '']);
        $this->assertFalse(OneTimeAction::claimFrom($empty), '空文字のトークンが通っている');

        $token  = OneTimeAction::issue();
        $first  = Request::create('/x', 'POST', ['guide_token' => $token]);
        $second = Request::create('/x', 'POST', ['guide_token' => $token]);
        $this->assertTrue(OneTimeAction::claimFrom($first), '正しい文字列トークンの 1 回目が通っていない');
        $this->assertFalse(OneTimeAction::claimFrom($second), '同じトークンの 2 回目が通っている');
    }

    /**
     * 鍵の受け取りの入口はすべて `OneTimeAction::claimFrom()` を経由する（全件分類。Top trap #13 / Bug #45 ①）。
     *
     * ⚠ **列挙リスト方式にしない** — 「直したファイル」を配列で並べる形だと、未修正・将来追加の
     *   入口が検査対象に入らず永遠に緑になる（Bug #45 ①）。`app/` と `routes/`（ルートのクロージャも
     *   入口になりうる）配下の全 PHP ファイルを機械的に列挙し、生の `OneTimeAction::claim(`
     *   （`claimFrom(` は対象外）を直接呼ぶファイルと、`OneTimeAction` をエイリアスして
     *   走査から逃れられる書き方をしているファイルを全部拾う。
     * ⚠ **PHP のクラス名・メソッド名は大小文字を区別しない**ため、`onetimeaction::CLAIM(` や
     *   `OneTimeAction :: claim (` のような書き方でも同じメソッドを呼べる。素の部分一致
     *   （`str_contains`）はこれを見逃すので、大小文字を無視し空白の揺れも許す正規表現で見る
     *   （`/\bOneTimeAction\s*::\s*claim\s*\(/i` は `claim` の直後が `F`（`claimFrom(`）だと
     *   `\s*\(` が続かず一致しない）。
     * ⚠ コメントを落としてから走査する（Bug #42 ②）。**理由はこの docblock 自身の話ではない**
     *   —— この試験ファイルは `tests/` にあり、走査対象（`app/` + `routes/`）にそもそも入らない。
     *   本当の理由は `app/Http/Controllers/Approval/UserImportController.php:123` の**コメント**に
     *   `OneTimeAction::claimFrom()` という文字列があること。コメントを落とさずに数えると、
     *   実測で呼び出し数が **6**（本物の呼び出し 5 ＋ このコメント 1）になる。もし本物の呼び出しを
     *   1 か所消す変異が起きても、コメントの 1 件が残るので合計は 5 のまま —— `>= 5` の下限が
     *   すり抜けに気づけない（実測で確認済み）。コメントを落として初めて実測が **5** になり、
     *   下限が本物の検出力を持つ。
     * ⚠ **これでも捕まえられない書き方がある** —— `[OneTimeAction::class, 'claim']` や
     *   `call_user_func` 経由の動的呼び出し、`app/` `routes/` の外（Blade・DB に保存された文字列など）
     *   から呼ぶ経路は、この正規表現走査では検出できない。
     * ⚠ 走査が空振りして緑になる事故を防ぐため、拾えたファイル数・`claimFrom(` 呼び出し数の
     *   下限も併せて固定する（Bug #45 ①・Bug #32 と同じ流儀。2026-09-18 実測で app/ 273 + routes/ 3 = 276 件）。
     */
    public function test_every_entry_point_uses_claim_from_not_the_raw_claim(): void
    {
        $files = array_merge($this->phpFilesUnder(app_path()), $this->phpFilesUnder(base_path('routes')));

        $oneTimeActionPath = realpath(app_path('Support/OneTimeAction.php'));
        $problems = [];
        $claimFromCallSites = 0;

        foreach ($files as $file) {
            $source = $this->sourceWithoutComments($file);

            $claimFromCallSites += preg_match_all('/\bOneTimeAction\s*::\s*claimFrom\s*\(/i', $source);

            if (realpath($file) === $oneTimeActionPath) {
                continue; // 正本自身（claim() の定義）は対象外
            }

            if (preg_match('/\bOneTimeAction\s*::\s*claim\s*\(/i', $source)) {
                $problems[] = "{$file}（生の OneTimeAction::claim() を直接呼んでいる）";
            }
            if (preg_match('/\bOneTimeAction\s+as\s/i', $source)) {
                $problems[] = "{$file}（OneTimeAction をエイリアスしていて走査から逃れられる）";
            }
        }

        $this->assertSame(
            [],
            $problems,
            "鍵の受け取りが OneTimeAction::claimFrom() を経由していない箇所がある:\n" . implode("\n", $problems)
        );

        $this->assertGreaterThanOrEqual(
            270,
            count($files),
            '走査が app/ + routes/ 配下の PHP ファイルを十分に拾えていない（空振りして緑になる事故を防ぐ下限。2026-09-18 実測 276 件）'
        );

        $this->assertGreaterThanOrEqual(
            5,
            $claimFromCallSites,
            'claimFrom() の呼び出しが減っている（store / resetPassword / execute / reissue / reissueBulk の 5 箇所が既定）'
        );
    }

    /** `$dir` 配下の `*.php` を再帰的に列挙する（全件分類の対象を機械的に決めるため） */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    /** コメントと docblock を落としたソース（注意書きに反応しないように。Bug #42 ②） */
    private function sourceWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** セッションにパスワードが入らない（sessions テーブルに平文が残らない） */
    public function test_the_password_never_touches_the_session(): void
    {
        $this->render();

        $this->assertStringNotContainsString('abcde23456', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
    }

    /** 1 回限りの鍵 */
    public function test_a_token_can_be_claimed_only_once(): void
    {
        $token = OneTimeAction::issue();

        $this->assertTrue(OneTimeAction::claim($token));
        $this->assertFalse(OneTimeAction::claim($token), '同じ鍵で 2 回目が通った');
    }

    public function test_different_tokens_are_independent(): void
    {
        $this->assertTrue(OneTimeAction::claim(OneTimeAction::issue()));
        $this->assertTrue(OneTimeAction::claim(OneTimeAction::issue()));
    }

    /** 鍵そのものは保存しない（ハッシュだけ） */
    public function test_the_raw_token_is_not_stored(): void
    {
        $token = OneTimeAction::issue();
        OneTimeAction::claim($token);

        $this->assertFalse(Cache::has($token), '鍵がそのままキャッシュのキーになっている');
        $this->assertTrue(Cache::has(OneTimeAction::cacheKey($token)));
    }
}
