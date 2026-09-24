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

    /**
     * 走査に使う正規表現をここへ集約する。
     *
     * ⚠ PHP はクラス名・メソッド名の大小文字を区別しないので、素の部分一致（`str_contains`）
     *   ではなく大小文字・空白の揺れを許す正規表現で見る。`test_the_scan_regexes_match_only_what_they_should`
     *   が must-match / must-not-match の両方を実測で固定する（Bug #57 と同じ流儀）。
     */
    private const RAW_CLAIM_PATTERN = '/\bOneTimeAction\s*::\s*claim\s*\(/i';

    private const CLAIM_FROM_PATTERN = '/\bOneTimeAction\s*::\s*claimFrom\s*\(/i';

    private const ALIAS_PATTERN = '/\bOneTimeAction\s+as\s/i';

    /**
     * ⚠ **FQCN 経由の生成も拾う**（2026-09-18 の再レビューで指摘）—— `\\?(?:[A-Za-z_]\w*\\)*`
     *   が任意個の名前空間セグメントを許すので、`new LoginGuide(` だけでなく
     *   `new \App\Support\Approval\LoginGuide(` も拾える。それでも import のエイリアス
     *   （`use … as Guide;` の後の `new Guide(`）は**拾えない**——別名になった後はメソッド本体に
     *   「LoginGuide」という文字列が一度も現れないため（`use` の行には現れる）。これは検出せず**禁止する**
     *   （`ENTRY_POINT_ALIAS_PATTERN` と `test_login_guide_and_friends_are_not_aliased()` 参照）。
     */
    private const NEW_LOGIN_GUIDE_PATTERN = '/\bnew\s+\\\\?(?:[A-Za-z_]\w*\\\\)*LoginGuide\s*\(/i';

    private const TO_GUIDE_CALL_PATTERN = '/(?:->|\?->)\s*toGuide\s*\(/i';

    /** ⚠ 上と同じ理由で FQCN 経由の生成も拾う（`NEW_LOGIN_GUIDE_PATTERN` の docblock 参照） */
    private const NEW_PASSWORD_REISSUER_PATTERN = '/\bnew\s+\\\\?(?:[A-Za-z_]\w*\\\\)*PasswordReissuer\b/i';

    /**
     * `PasswordReissuer` を `new` で作らず、コンテナ解決や DI で受け取って `->reissue(` を
     * 呼ぶ経路も「再発行を実行した」印として拾う。`new PasswordReissuer` だけを見ていると、
     * `app(PasswordReissuer::class)->reissue($targets)` や、注入された
     * `PasswordReissuer $reissuer` からの呼び出しが `claimFrom()` より前に動かされても
     * 気づけない（2026-09-18 の再レビューで指摘）。
     */
    private const REISSUE_CALL_PATTERN = '/(?:->|\?->)\s*reissue\s*\(/i';

    /**
     * 案内を描く入口を**拾う**ための広い判定（`LoginGuide` / `PasswordReissuer` の単語か
     * `->toGuide(` のいずれか）。「拾う」専用——実際にその場で生成しているかまでは
     * 見ない（それは上の `NEW_LOGIN_GUIDE_PATTERN` 等 ＋ 下の副作用 3 定数が個別に見る）。
     *
     * ⚠ 以前は `new LoginGuide(` のような狭い形しか拾っていなかったため、FQCN 経由の生成
     *   （`new \App\Support\Approval\LoginGuide(`）・コンテナ経由の解決
     *   （`app(PasswordReissuer::class)`）を素通りさせる余地があった。
     * ⚠ それでも拾えない書き方がある —— 変数に入れたクラス名からの動的生成（`new $class(...)`）・
     *   import のエイリアス（後者は検出でなく**禁止**する。`ENTRY_POINT_ALIAS_PATTERN` 参照）は
     *   この正規表現走査では検出できない。
     */
    private const ENTRY_POINT_PICK_PATTERN = '/\bLoginGuide\b|\bPasswordReissuer\b|->\s*toGuide\s*\(/i';

    /**
     * 「呼んでいる」ではなく「守っている」ことを見る —— `if (! OneTimeAction::claimFrom($x)) { return …`
     * という**防御の形そのもの**。`claimFrom(` を呼ぶだけで戻り値を捨てる・早期 return が無い、
     * という書き方は「呼んでいるが守っていない」ので、`CLAIM_FROM_PATTERN` の単純な有無では
     * 区別できない。
     */
    private const CLAIM_GUARD_SHAPE_PATTERN = '/\bif\s*\(\s*!\s*OneTimeAction\s*::\s*claimFrom\s*\(\s*\$\w+\s*\)\s*\)\s*\{\s*return\b/i';

    /**
     * 副作用の起点 3 種。上の 4 パターン（render / 再発行の実行）と合わせて、
     * **claimFrom() の防御より前にあってはいけない**——防御は必ずこれらより先に来る
     * （2026-09-18 の再レビューで訂正: 以前この docblock は主語が逆で
     * 「防御より後にあってはいけない」と書いていた）。
     */
    private const DB_TRANSACTION_PATTERN = '/\bDB\s*::\s*transaction\s*\(/i';

    private const INITIAL_PASSWORD_STATIC_PATTERN = '/\bInitialPassword\s*::/i';

    private const SET_INITIAL_PASSWORD_CALL_PATTERN = '/(?:->|\?->)\s*setInitialPassword\s*\(/i';

    /**
     * `LoginGuide` / `PasswordReissuer` / `ReissueResult` を import でエイリアスすると、
     * `ENTRY_POINT_PICK_PATTERN` のバレワード判定を素通りできる（`use … as Guide;` の後は
     * メソッド本体に「LoginGuide」という文字列が一度も現れなくなる。`use` の行には現れるので、
     * エイリアスの禁止はそこを見る）。**検出するのではなく
     * 禁止する**——`test_login_guide_and_friends_are_not_aliased()` が app/ + routes/ を
     * 全件分類で走査し、エイリアスそのものを許さない（`ALIAS_PATTERN` が `OneTimeAction` に
     * しているのと同じ流儀）。
     */
    private const ENTRY_POINT_ALIAS_PATTERN = '/\b(?:LoginGuide|PasswordReissuer|ReissueResult)\s+as\s/i';

    private function guide(): LoginGuide
    {
        $a = User::factory()->create(['name' => '甲 一郎', 'employee_number' => 'M001', 'email' => null, 'must_change_password' => true]);
        $b = User::factory()->create(['name' => '乙 二郎', 'employee_number' => null, 'email' => 'b@example.com', 'must_change_password' => true]);

        return new LoginGuide([
            ['user' => $a, 'password' => 'abcde23456'],
            ['user' => $b, 'password' => 'fghij78923'],
            // ⚠ **わざと非対称**にしてある（人数は載せる値であって行数とは別物）。
            //    1 対 1 にすると、帯の 2 つの数字が入れ替わっても原理的に見えない。
        ], backUrl: 'http://localhost/_test/back', mailCounts: ['notified' => 2, 'skipped' => 1]);
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

    /** 通知メールの件数は、渡されたとき（＝再発行）だけ出す（F7。新規登録と CSV の確定では送らない） */
    public function test_the_mail_counts_are_left_out_when_no_mail_is_sent(): void
    {
        $user = User::factory()->create(['name' => '丙 三郎', 'employee_number' => 'M003', 'must_change_password' => true]);
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->get('/_test/login-guide-new', fn () =>
            (new LoginGuide([['user' => $user, 'password' => 'klmno45678']], backUrl: url('/_test/back')))->toResponse(request()));

        $html = $this->actingAs(User::factory()->create(['must_change_password' => false]))
            ->get('/_test/login-guide-new')->assertOk()->getContent();

        $this->assertStringContainsString('1 人分', $html);
        $this->assertStringNotContainsString('通知メール: 送る', $html, '通知メールを送らないのに件数の帯が出ている');
    }

    /**
     * 発行日は**日本の今日**（F5）。アプリの時刻は UTC なので、日本時間の 0:00〜8:59 は UTC ではまだ前日。
     *
     * ⚠ 期待値は決め打ちで書く（JapanTime::today() で組み立てると同義反復になる。Bug #61 ②）。
     */
    public function test_the_issue_date_is_the_date_in_japan(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-18 17:30:00', 'UTC'));   // 日本時間 9/19 2:30

        $html = $this->render()->assertOk()->getContent();

        $this->assertStringContainsString('発行日: 2026年9月19日', $html);
        $this->assertStringNotContainsString('2026年9月18日', $html, 'UTC の日付のまま出ている');
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

        $this->assertTrue($this->claimToken($token), '有効時間 0 で 1 回目から弾かれている');
        $this->assertFalse($this->claimToken($token), '2 回目が通っている');
    }

    /**
     * `claim()` は private なので、テストからも `claimFrom()` を経由して呼ぶ。
     * 「hidden `guide_token` に文字列トークンを 1 つ乗せた POST」を組み立てるだけの薄いラッパー。
     */
    private function claimToken(string $token): bool
    {
        return OneTimeAction::claimFrom(Request::create('/x', 'POST', ['guide_token' => $token]));
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
     * `OneTimeAction` の外は生の `claim()` を直接呼べない・エイリアスで走査から逃げられない
     * （全件分類。Top trap #13 / Bug #45 ①）。
     *
     * ⚠ **列挙リスト方式にしない** — 「直したファイル」を配列で並べる形だと、未修正・将来追加の
     *   ファイルが検査対象に入らず永遠に緑になる（Bug #45 ①）。`app/` と `routes/`（ルートの
     *   クロージャも入口になりうる）配下の全 PHP ファイルを機械的に列挙する。
     * ⚠ **`claim()` は private 化済み** —— 外から `OneTimeAction::claim(...)` と
     *   書けば PHP の `Error`（private method へのアクセス）になるので、この走査は**もう
     *   唯一の防御ではない**。それでも残すのは、**実行して初めて分かる private 違反より前**に
     *   静的に気づける早期警告として（`phpunit` を走らせるだけで分かる。**通常の呼び出し**——
     *   静的呼び出し・`call_user_func` のような動的呼び出し・エイリアス経由——は private なら
     *   同じクラスの外から書けないので、ここが守るのはその形の誤用が**書かれていないこと**の
     *   確認。`ReflectionMethod::invoke()` のような迂回まではこの走査も private 化も防がない
     *   ——`OneTimeAction.php` の `claim()` docblock 参照）。
     * ⚠ コメントを落としてから走査する（Bug #42 ②）。**理由はこの docblock 自身の話ではない**
     *   —— この試験ファイルは `tests/` にあり、走査対象（`app/` + `routes/`）にそもそも入らない。
     *   本当の理由は `Approval\UserImportController::execute()` の**コメント**に
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
     *   **ここでは問題リストのアサートを下限より先に置く**（Bug #59 は逆に下限を先に置いた）。
     *   Bug #59 の教訓（RULES.md の原文）は「下限を後ろに置くと、空振りが**『実在しない名前』**
     *   という別の理由で報告される」こと——個別の名前の有無を見るアサートが先にあると、
     *   走査が空でもその名前が「実在しない」という**別の・紛らわしい**理由の失敗に化ける。
     *   この走査には**その形の個別チェックが無い**ので、問題リスト（`$problems`）を先に置いても
     *   空振りは紛らわしい理由では報告されない——空振り（0 件）でも `$problems` はただ空になるだけ
     *   （0 件を「問題無し」と読んでもそのとおりの状態）で、すぐ後ろの件数の下限アサートが
     *   「0 件しか拾えていない」と**正しい理由**で落ちる。
     * ⚠ **ファイルの列挙順は `sort()` で固定する** —— OS やファイルシステムの列挙順に頼ると、
     *   失敗メッセージに出るファイルの並びが実行ごとに変わる。
     * ⚠ **`OneTimeAction.php` 自身の除外は、今のコードでは何も除外していない** —— `claimFrom()`
     *   は `self::claim(` を使っているので、素の `OneTimeAction::claim(` パターンにはそもそも
     *   当たらない。それでも除外を残すのは、将来 `self::` をクラス名直書き
     *   （`OneTimeAction::claim(`）に書き換えても、**それは正本自身の呼び出しであって
     *   外部からの誤用ではない**ため。
     */
    public function test_nothing_outside_one_time_action_calls_the_raw_claim(): void
    {
        $files = array_merge($this->phpFilesUnder(app_path()), $this->phpFilesUnder(base_path('routes')));
        sort($files);

        $oneTimeActionPath = realpath(app_path('Support/OneTimeAction.php'));
        $problems = [];
        $claimFromCallSites = 0;

        foreach ($files as $file) {
            $source = $this->sourceWithoutComments($file);

            $claimFromCallSites += preg_match_all(self::CLAIM_FROM_PATTERN, $source);

            if (realpath($file) === $oneTimeActionPath) {
                continue; // 正本自身（今は何も除外していない。上の docblock 参照）
            }

            if (preg_match(self::RAW_CLAIM_PATTERN, $source)) {
                $problems[] = "{$file}（生の OneTimeAction::claim() を直接呼んでいる）";
            }
            if (preg_match(self::ALIAS_PATTERN, $source)) {
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

    /**
     * `LoginGuide` / `PasswordReissuer` / `ReissueResult` を import でエイリアスしていない
     * （全件分類。Top trap #13 / Bug #45 ①）。
     *
     * ⚠ **検出でなく禁止。** `ENTRY_POINT_PICK_PATTERN`（バレワード判定）は
     *   `new \App\Support\Approval\LoginGuide(` のような FQCN 経由の生成は拾えるが、
     *   `use App\Support\Approval\LoginGuide as Guide;` のようにエイリアスされた後は
     *   メソッド本体に「LoginGuide」という文字列が一度も現れなくなるため、原理的に拾えない
     *   （2026-09-18 の再レビューで指摘。それまでの docblock は「素通りさせる余地があった」
     *   と書いていたが、実際は「今も素通りしうる」が正確だった）。ここでは「拾えない
     *   ケースを検出する」のではなく、**そのケース自体を書かせない**ことで全件分類を保つ
     *   （`OneTimeAction` のエイリアス禁止と同じ流儀。直前のテスト参照）。
     * ⚠ コメントを落としてから走査する（Bug #42 ②）。
     * ⚠ **ファイルの列挙順は `sort()` で固定する** —— 失敗メッセージの並びを決定的にする。
     * ⚠ 走査が空振りして緑になる事故を防ぐため、拾えたファイル数の下限も併せて固定する
     *   （Bug #45 ①・Bug #32 と同じ流儀）。**ここでは問題リストのアサートを下限より先に置く**
     *   ——直前のテストと同じ理由（この走査にも個別の名前チェックが無いので、空振りは
     *   RULES.md の言う「実在しない名前」という紛らわしい理由では報告されない）。
     */
    public function test_login_guide_and_friends_are_not_aliased(): void
    {
        $files = array_merge($this->phpFilesUnder(app_path()), $this->phpFilesUnder(base_path('routes')));
        sort($files);

        $problems = [];
        foreach ($files as $file) {
            $source = $this->sourceWithoutComments($file);

            if (preg_match(self::ENTRY_POINT_ALIAS_PATTERN, $source, $m)) {
                $problems[] = "{$file}（`{$m[0]}` のようにエイリアスしていて全件分類の走査から逃れられる）";
            }
        }

        $this->assertSame(
            [],
            $problems,
            "LoginGuide / PasswordReissuer / ReissueResult をエイリアスしている箇所がある:\n" . implode("\n", $problems)
        );

        $this->assertGreaterThanOrEqual(
            270,
            count($files),
            '走査が app/ + routes/ 配下の PHP ファイルを十分に拾えていない（空振りして緑になる事故を防ぐ下限）'
        );
    }

    /**
     * 全件分類の走査そのものが健全であること（Reflection 抽出の自己テスト。Bug #57 と同じ流儀）。
     *
     * ⚠ ここが壊れていると、次のテスト（入口が先に鍵を使うか）は**何かを見ているつもりで
     *   何も見ていない**——以前の手書きトークナイザは `X::class` を宣言と誤認し・予約語の
     *   メソッド名（`list` / `empty` / `print`）を落とし・`enum case Function` を誤認し・
     *   文字列展開の波括弧（`"${x}"` / `"$x{"`）で対応が崩れていたのに、たまたま対象の
     *   6 メソッドは生き残っていたため、読み取り専用の Reflection プロトタイプで実測するまで
     *   気づかれなかった（誤カウントされた偽クラス 320/1599 件・**衝突したキー 34 個による
     *   上書き 71 件**）。
     * ⚠ **もう 1 つの前提**: `app/` は 1 ファイル 1 クラス（PSR-4）——2 つ目の class-like が
     *   ファイルに紛れ込むと、`methodBodiesIn()` は PSR-4 の名前で解決した**1 つだけ**を
     *   reflect するので、その 2 つ目は**無音で**走査から消える（`nonClassFiles` にも載らない
     *   ——`fqcnForAppFile()` は解決できる 1 つの名前を返すだけで、他に何かあるかは見ない。
     *   2026-09-18 の再レビューで指摘）。ここでは `app/` の全ファイルについて「宣言されている
     *   class-like がちょうど 1 つ」「その名前が PSR-4 の名前と一致する」の両方を確かめる。
     * ⚠ 件数はこのテストの環境で実測した値（app/ 配下の PHP ファイルすべてが
     *   PSR-4（`App\` ⇒ `app/`）で class / interface / trait / enum のいずれかに解決でき、
     *   同じ `"Class::method"` キーが 2 か所以上から生成されることも無い ＝ 非クラスファイル
     *   0 件・メソッド 1602 件・キー衝突 0 件・宣言が 1 つでないファイル 0 件・PSR-4 と
     *   名前が食い違うファイル 0 件）。ファイルが増減すれば数は動くので、
     *   ここでは「0 件であること」だけを固定し、メソッド総数の下限は次のテストに任せる。
     */
    public function test_the_app_method_body_scan_has_no_gaps_or_collisions(): void
    {
        $scan = $this->scanAppMethodBodies();

        $this->assertSame(
            [],
            $scan['nonClassFiles'],
            "app/ 配下に PSR-4 で class / interface / trait / enum のいずれにも解決できないファイルがある:\n"
                . implode("\n", $scan['nonClassFiles'])
        );

        $multiClassFiles = [];
        $mismatchedNames = [];
        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $fqcn = $this->fqcnForAppFile($file);
            if ($fqcn === null) {
                continue; // 上のアサートがすでに報告している
            }

            $declared = $this->declaredClassLikeNamesIn($file);
            if (count($declared) !== 1) {
                $multiClassFiles[] = "{$file}（宣言されている class-like が " . count($declared) . ' 件）';
                continue;
            }

            $expectedShortName = substr($fqcn, strrpos($fqcn, '\\') + 1);
            if ($declared[0] !== $expectedShortName) {
                $mismatchedNames[] = "{$file}（宣言名 {$declared[0]} が PSR-4 の名前 {$expectedShortName} と一致しない）";
            }
        }

        $this->assertSame(
            [],
            $multiClassFiles,
            "app/ のファイルに class-like の宣言がちょうど 1 つでない（2 つ目が methodBodiesIn() から無音で漂う）:\n"
                . implode("\n", $multiClassFiles)
        );

        $this->assertSame(
            [],
            $mismatchedNames,
            "宣言されている名前が PSR-4 のファイル名と一致しない:\n" . implode("\n", $mismatchedNames)
        );

        $this->assertSame(
            [],
            $scan['duplicateKeys'],
            '同じ "Class::method" キーが複数ファイルから生成されている（走査の前提が崩れている）: '
                . implode(', ', $scan['duplicateKeys'])
        );
    }

    /**
     * ログイン案内を描く／再発行する入口は、すべて**先に** `OneTimeAction::claimFrom()` を
     * 使う（全件分類。設計書 §5.12。Top trap #13 / Bug #45 ①）。
     *
     * ⚠ 直前のテストは「生の `claim()` を誰も呼んでいないか」しか見ておらず、**入口そのものを
     *   列挙していない**。`claimFrom(` の呼び出し数の下限（`>= 5`）を満たしているだけなら、
     *   新しい入口が鍵を一度も使わずに案内を描いても・鍵を使った結果を捨てても・
     *   案内を描いた**あとで**鍵を使っても、この下限は気づかず素通りする（Bug #45 ①と同型の
     *   「対象を全件分類していない」欠陥）。
     * ⚠ 対象の見つけ方: `app/` 配下の全 PHP ファイルを `ReflectionClass` で読み、**そのファイルが
     *   実際に定義しているメソッド**の本体（コメント除去済み。Bug #42 ②）を 1 つずつ切り出す
     *   （`scanAppMethodBodies()` → `methodBodiesIn()`）。走査そのものの健全性は
     *   `test_the_app_method_body_scan_has_no_gaps_or_collisions()` が別に守るので、ここでは
     *   その結果を使うだけ。本体が `ENTRY_POINT_PICK_PATTERN`（`LoginGuide` / `PasswordReissuer`
     *   の単語か `->toGuide(` のいずれか）を含むメソッドを「拾う」。
     * ⚠ 拾ったメソッドのうち、**案内を作る仕組みそのもの**（`ReissueResult::toGuide()` ——
     *   `PasswordReissuer::reissue()` の結果から `LoginGuide` を組み立てるだけの部品で、
     *   `$request` も鍵も持たない）は入口ではないので、理由つきの除外リストに明示する。
     *   **除外名は実在チェックする** —— 拾った集合に無い名前が除外リストに残っていたら
     *   （リファクタで消えた・typo 等）、それ自体を stale entry として報告する。
     * ⚠ 除外されなかった拾いものは**すべて入口**で、**次の 2 つ**を要求する。
     *   ① `if (! OneTimeAction::claimFrom($x)) { return …` という**防御の形そのもの**
     *   （`CLAIM_GUARD_SHAPE_PATTERN`）を持つこと —— `claimFrom(` を呼ぶだけで戻り値を捨てる・
     *   早期 return が無い、という書き方は「呼んでいるが守っていない」ので弾く。
     *   ② その防御が、案内を描く／再発行を実行する 4 パターン（`new LoginGuide(` / `->toGuide(` /
     *   `new PasswordReissuer` / `->reissue(`）**と**副作用の 3 パターン（`DB::transaction(` /
     *   `InitialPassword::` / `->setInitialPassword(`）の**最初に現れる位置**より**前**にあること。
     *   ⚠ **`new LoginGuide` / `new PasswordReissuer` は FQCN を許す**
     *   （`\bnew\s+\\?(?:[A-Za-z_]\w*\\)*ClassName`）——`new \App\Support\Approval\LoginGuide(`
     *   のような完全修飾での生成も拾う（2026-09-18 の再レビューで指摘。以前は `new LoginGuide(`
     *   のような狭い形しか拾えなかった）。**`->reissue(` を足したのは** `PasswordReissuer` を
     *   `new` で作らず、コンテナ解決や DI で受け取って呼ぶ経路（`app(PasswordReissuer::class)
     *   ->reissue($targets)` や、注入された `PasswordReissuer $reissuer` からの呼び出し）が
     *   `new PasswordReissuer` だけでは拾えなかったため。
     *   ⚠ **副作用の 3 パターンを足したのは「防御が案内を描く式の直前にあれば十分」という思い込みを
     *   崩すため** —— `resetPassword()` / `reissue()` / `reissueBulk()` は自分の本体に副作用を
     *   持たず `PasswordReissuer::reissue()` に委ねているので影響しないが、`execute()` は自分の
     *   本体で直接 `DB::transaction(` を呼ぶ。副作用パターンを見ていなければ、`execute()` が
     *   `DB::transaction(...)` を `claimFrom()` より前へ動かしても（案内を描く `new LoginGuide(`
     *   は末尾のままなので）この走査は気づけなかった。
     * ⚠ **それでも拾えない書き方が残る**（2026-09-18 の再レビューで指摘）——
     *   ① 副作用を持つが名前の付いた 7 パターンのいずれにも当たらない**汎用の書き込み**
     *   （例: `$user->save();` を防御より前に書く）、
     *   ② `if` の**片方の分岐にだけ**防御があり、もう片方の分岐から副作用に到達する形
     *   （この走査は本体全体の中でのオフセットしか見ないので、分岐構造は認識できない）、
     *   ③ `new $class(...)` のような**変数に入れたクラス名からの動的生成**。
     *   import のエイリアス（`use … as Guide;`）は以前ここに「拾えない」と書いていたが、
     *   `test_login_guide_and_friends_are_not_aliased()` が禁止するので**もう起こらない**。
     * ⚠ **この順序が守るのは §5.12「起きなかった処理に鍵を焼かない」ではない**（それは逆方向の
     *   話——検証エラーで**なにも起きなかった**ときに鍵を無駄に使わないための順序で、
     *   `Admin\UserController::store()` のコメントが説明している）。**このテストが守るのは、
     *   鍵の防御が副作用・描画より後にあると、二重送信が処理を実際に実行してしまってから
     *   初めて拒否される、という危険——起きる実害は入口ごとに違う**:
     *   `resetPassword` / `reissue` / `reissueBulk`（再発行系）は**既に印刷済みの案内が
     *   無効になる**（新しいパスワードが古いものを上書きする）。`store`（新規登録）は
     *   **重複したアカウントが作られる**。`execute`（CSV の確定）は**既存行を再更新し、
     *   記録（ログ）が重複する**。
     *   （Bug #42 ②。誤った理由の注記は次の読み手を誤らせるので、以前ここに書いていた
     *   「起きなかった処理に鍵を焼く危険」「その時点で印刷済みの案内はもう無効になっている」
     *   という**入口を区別しない**説明は不正確だった）。
     * ⚠ **問題リストのアサートを `assertSame(5, …)` より先に置く**（stale-exclusion のチェックは
     *   それよりさらに先・**走査したメソッド本体の件数の下限はそれよりもさらに先**）。
     *   ⚠ **stale-exclusion のチェックは、走査が空振りでも紛らわしくなく素通りするわけではない**
     *   ——以前ここにそう書いていたのは誤り（2026-09-18 の再レビューで指摘）。`$excluded` は
     *   `$picked` から動的に作る集合ではなく**ハードコードされた配列**なので、走査が空振りで
     *   `$picked === []` になっても `array_diff(array_keys($excluded), [])` は
     *   `['App\Support\Approval\ReissueResult::toGuide']` のまま——空にならず、
     *   「除外リストに、走査で拾えなくなった名前が残っている」という**まさに RULES.md の言う
     *   『実在しない名前』の紛らわしい理由**でこの assert が先に落ちる。だからこそ件数の下限を
     *   さらに先へ置き、空振りは「1500 件のはずが 0 件だった」と**正しい理由**で最初に報告させる
     *   （`$problems` のほうは `$entryPoints`（`array_diff_key($picked, …)`）が空なら
     *   ループが 1 回も回らず紛らわしくなく空になるので、この心配は無い）。
     */
    public function test_every_guide_rendering_entry_point_claims_the_token_first(): void
    {
        $bodies = $this->scanAppMethodBodies()['bodies'];

        $this->assertGreaterThanOrEqual(
            1500,
            count($bodies),
            '走査が app/ 配下のメソッド本体を十分に拾えていない（空振りして緑になる事故を防ぐ下限。2026-09-18 実測 1602 件）'
        );

        $picked = [];
        foreach ($bodies as $key => $body) {
            if (preg_match(self::ENTRY_POINT_PICK_PATTERN, $body)) {
                $picked[$key] = $body;
            }
        }

        // ⚠ ビルディングブロックそのもの（$request も鍵も持たない）。理由は上の docblock。
        $excluded = [
            'App\Support\Approval\ReissueResult::toGuide' => 'toGuide() の定義自体。PasswordReissuer::reissue()'
                . ' の結果から LoginGuide を組み立てるだけで $request も鍵も持たない。'
                . '呼び出し側（resetPassword / reissue / reissueBulk）がすでに claimFrom() を済ませてから呼ぶ'
                . '（execute() は new LoginGuide( を直接使い toGuide() を経由しない）。',
        ];

        $staleExclusions = array_diff(array_keys($excluded), array_keys($picked));
        $this->assertSame(
            [],
            $staleExclusions,
            '除外リストに、走査で拾えなくなった名前が残っている（リファクタ・typo を確認）: '
                . implode(', ', $staleExclusions)
        );

        $entryPoints = array_diff_key($picked, $excluded);

        $triggerPatterns = [
            self::NEW_LOGIN_GUIDE_PATTERN,
            self::TO_GUIDE_CALL_PATTERN,
            self::NEW_PASSWORD_REISSUER_PATTERN,
            self::REISSUE_CALL_PATTERN,
            self::DB_TRANSACTION_PATTERN,
            self::INITIAL_PASSWORD_STATIC_PATTERN,
            self::SET_INITIAL_PASSWORD_CALL_PATTERN,
        ];

        $problems = [];
        foreach ($entryPoints as $key => $body) {
            if (! preg_match(self::CLAIM_GUARD_SHAPE_PATTERN, $body, $claimMatch, PREG_OFFSET_CAPTURE)) {
                $problems[] = "{$key}（`if (! OneTimeAction::claimFrom(\$x)) { return …` の防御の形を"
                    . '持たない——呼んでいても結果を捨てている・早期 return が無い等の可能性）';
                continue;
            }

            $triggerOffsets = [];
            foreach ($triggerPatterns as $pattern) {
                if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE)) {
                    $triggerOffsets[] = $m[0][1];
                }
            }

            if ($triggerOffsets === []) {
                $problems[] = "{$key}（LoginGuide / PasswordReissuer / toGuide を含むと判定されたのに、"
                    . '副作用・案内を描く起点を 1 つも検出できない。除外リストへの追加か判定パターンの見直しが必要）';
                continue;
            }

            if ($claimMatch[0][1] > min($triggerOffsets)) {
                $problems[] = "{$key}（claimFrom() の防御が、副作用・案内を描く式より後にある。"
                    . '二重送信が処理を実行してから初めて拒否される危険）';
            }
        }

        $this->assertSame(
            [],
            $problems,
            "鍵を先に使っていない入口がある:\n" . implode("\n", $problems)
        );

        $this->assertSame(
            5,
            count($entryPoints),
            '入口の数が想定と違う（既定 5: Admin\UserController::store / resetPassword, '
                . 'Approval\UserController::reissue / reissueBulk, Approval\UserImportController::execute）。'
                . '実際の入口: ' . implode(', ', array_keys($entryPoints))
        );
    }

    /**
     * 走査に使う正規表現そのものが、意図どおり一致し・一致しないことを確かめる
     * （Bug #57 と同じ流儀の自己テスト）。
     *
     * ⚠ これが無いと、正規表現を直したときに「拾えなくなった」ことに気づけない
     *   ——走査対象の実ファイルがたまたま変わらない限り、正規表現の後退は無音になる。
     */
    public function test_the_scan_regexes_match_only_what_they_should(): void
    {
        foreach ([
            'OneTimeAction::claim($t)',
            'onetimeaction :: CLAIM ($t)',
            '\App\Support\OneTimeAction::claim($t)',
            'OneTimeAction::claim(...)',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::RAW_CLAIM_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            'OneTimeAction::claimFrom($r)',
            'MyOneTimeAction::claim($t)',
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::RAW_CLAIM_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            'use App\Support\OneTimeAction as Once;',
            'use App\Support\{OneTimeAction as O};',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::ALIAS_PATTERN, $sample, "見逃している: {$sample}");
        }
        $this->assertDoesNotMatchRegularExpression(
            self::ALIAS_PATTERN,
            'OneTimeAction::claim($t)',
            'claim 呼び出しをエイリアスと誤検出している'
        );

        $this->assertMatchesRegularExpression(
            self::CLAIM_FROM_PATTERN,
            'OneTimeAction :: claimFrom ($request)',
            'claimFrom の空白・大小文字の揺れを見逃している'
        );
        $this->assertDoesNotMatchRegularExpression(
            self::CLAIM_FROM_PATTERN,
            'OneTimeAction::claim($t)',
            'claim を claimFrom と誤検出している'
        );

        $this->assertMatchesRegularExpression(self::NEW_LOGIN_GUIDE_PATTERN, 'new LoginGuide($entries)');
        $this->assertMatchesRegularExpression(self::NEW_LOGIN_GUIDE_PATTERN, 'NEW   loginguide(...)');
        // ⚠ 2026-09-18 に FQCN 経由の生成も拾えるよう widen した——docblock 自身が挙げている
        //    例をそのまま自己テストにする（Bug #57 と同じ流儀）。
        $this->assertMatchesRegularExpression(
            self::NEW_LOGIN_GUIDE_PATTERN,
            'new \App\Support\Approval\LoginGuide($entries)',
            'FQCN 経由の生成を見逃している'
        );
        $this->assertMatchesRegularExpression(
            self::NEW_LOGIN_GUIDE_PATTERN,
            'new App\Support\Approval\LoginGuide($entries)',
            '先頭 \ 無しの FQCN を見逃している'
        );
        $this->assertDoesNotMatchRegularExpression(
            self::NEW_LOGIN_GUIDE_PATTERN,
            'new MyLoginGuide()',
            '別クラスを誤検出している'
        );

        $this->assertMatchesRegularExpression(self::TO_GUIDE_CALL_PATTERN, '->toGuide()');
        $this->assertMatchesRegularExpression(self::TO_GUIDE_CALL_PATTERN, '?->toGuide()');
        $this->assertDoesNotMatchRegularExpression(
            self::TO_GUIDE_CALL_PATTERN,
            '->toGuideline()',
            '別メソッド名を誤検出している'
        );

        $this->assertMatchesRegularExpression(self::NEW_PASSWORD_REISSUER_PATTERN, 'new PasswordReissuer()');
        $this->assertMatchesRegularExpression(
            self::NEW_PASSWORD_REISSUER_PATTERN,
            'new \App\Support\Approval\PasswordReissuer()',
            'FQCN 経由の生成を見逃している'
        );
        $this->assertDoesNotMatchRegularExpression(
            self::NEW_PASSWORD_REISSUER_PATTERN,
            'new PasswordReissuedMail($user)',
            '別クラス（PasswordReissuedMail）を誤検出している'
        );

        foreach ([
            '$reissuer->reissue($targets)',
            '$reissuer?->reissue($targets)',
            'app(PasswordReissuer::class)->reissue($targets)',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::REISSUE_CALL_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            '$this->reissueBulk($request)',
            '$x->reissue;',
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::REISSUE_CALL_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            'use App\Support\Approval\LoginGuide as Guide;',
            'use App\Support\Approval\{PasswordReissuer as Reissuer};',
            'use App\Support\Approval\ReissueResult as Result;',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::ENTRY_POINT_ALIAS_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            'use App\Support\OneTimeAction as Once;', // 別のクラス（OneTimeAction は別パターンで守る）
            'use App\Support\Approval\LoginGuideHelper as X;', // 語の一部が LoginGuide なだけの別クラス
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::ENTRY_POINT_ALIAS_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            'new LoginGuide($x)',
            'PasswordReissuer::class',
            '$x->toGuide()',
            '$x?->toGuide()',
            'LOGINGUIDE',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::ENTRY_POINT_PICK_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            'new LoginGuideline()',
            '->toGuideline()',
            '$x->save();',
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::ENTRY_POINT_PICK_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            "if (! OneTimeAction::claimFrom(\$request)) { return redirect()->route('x'); }",
            'if(!OneTimeAction::claimFrom($r)){return null;}',
            'IF ( ! OneTimeAction :: CLAIMFROM ( $req ) ) { RETURN foo(); }',
            "if (! OneTimeAction::claimFrom(\$request)) {\n    return \$this->refuseRepeatedGuide();\n}",
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::CLAIM_GUARD_SHAPE_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            'if (! OneTimeAction::claimFrom($request)) { $x = 1; return $x; }', // { の直後が return でない
            'if (OneTimeAction::claimFrom($request)) { return $x; }', // ! が無い（逆の分岐）
            'OneTimeAction::claimFrom($request);', // 防御の形を持たず呼ぶだけ
            'if (! OneTimeAction::claim($token)) { return null; }', // claimFrom でなく生の claim
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::CLAIM_GUARD_SHAPE_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            'DB::transaction(function () {',
            'DB :: TRANSACTION ( fn () =>',
            '\Illuminate\Support\Facades\DB::transaction(fn () =>',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::DB_TRANSACTION_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            'MyDB::transaction($x)',
            'DB::transactionally($x)',
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::DB_TRANSACTION_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            'InitialPassword::generate()',
            'InitialPassword :: generate()',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::INITIAL_PASSWORD_STATIC_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            'MyInitialPassword::generate()',
            'InitialPasswordHelper::generate()',
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::INITIAL_PASSWORD_STATIC_PATTERN, $sample, "誤検出している: {$sample}");
        }

        foreach ([
            '$user->setInitialPassword($password)',
            '$user?->setInitialPassword($password)',
            '$user -> SETINITIALPASSWORD ( $x )',
        ] as $sample) {
            $this->assertMatchesRegularExpression(self::SET_INITIAL_PASSWORD_CALL_PATTERN, $sample, "見逃している: {$sample}");
        }
        foreach ([
            '$user->setInitialPasswordHash($x)',
            '$user->initialPassword()',
        ] as $sample) {
            $this->assertDoesNotMatchRegularExpression(self::SET_INITIAL_PASSWORD_CALL_PATTERN, $sample, "誤検出している: {$sample}");
        }
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

    /**
     * `app/` 配下の全 PHP ファイルを `methodBodiesIn()` で読み、1 つの本体マップに束ねながら
     * 「class-like にマッピングできないファイル」と「キーの重複（2 か所以上から同じ
     * `"Class::method"` が出た）」を集計する。走査そのものの健全性は
     * `test_the_app_method_body_scan_has_no_gaps_or_collisions()` が守り、
     * `test_every_guide_rendering_entry_point_claims_the_token_first()` は
     * `['bodies']` を使うだけ（同じ走査を二重に作らないため、この 1 か所に集約する）。
     *
     * @return array{bodies: array<string, string>, nonClassFiles: array<string>, duplicateKeys: array<string>}
     */
    private function scanAppMethodBodies(): array
    {
        $bodies = [];
        $nonClassFiles = [];
        $duplicateKeys = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $found = $this->methodBodiesIn($file);

            if ($found === null) {
                $nonClassFiles[] = $file;
                continue;
            }

            foreach ($found as $key => $body) {
                if (array_key_exists($key, $bodies)) {
                    $duplicateKeys[] = $key;
                }
                $bodies[$key] = $body;
            }
        }

        sort($nonClassFiles);
        sort($duplicateKeys);

        return ['bodies' => $bodies, 'nonClassFiles' => $nonClassFiles, 'duplicateKeys' => $duplicateKeys];
    }

    /**
     * `$file` の中の「メソッド名 → 本体（コメント除去済みソース文字列）」を、
     * `"Namespace\Class::method"` 形式のキーで返す。`$file` が PSR-4（`App\` ⇒ `app/`）で
     * class / interface / trait / enum のいずれにも解決できないときは **null** を返す
     * （呼び出し側の `scanAppMethodBodies()` が「非クラスファイル」として集計する。ここで
     * 例外を投げると 1 件目で止まり、全件分類にならない。Bug #45 ①）。
     *
     * ⚠ 2026-09-18 に手書きの `token_get_all()` ＋ 波括弧対応（このメソッドの旧実装）から
     *   `ReflectionClass` へ置き換えた。旧実装は `X::class` を宣言と誤認する・予約語の
     *   メソッド名（`list` / `empty` / `print`）を落とす・`enum case Function` を誤認する・
     *   文字列展開の波括弧（`"${x}"` / `"$x{"`）で対応が崩れる、という壊れ方を実測で確認した
     *   （読み取り専用の Reflection プロトタイプで実測: 誤カウントされた偽クラス 320/1599 件・
     *   衝突したキー 34 個による上書き 71 件）。**6 つの対象メソッドはたまたま生き残っていたため、
     *   このテストは壊れた走査のまま緑を返し続けていた**——Top trap #13 / Bug #45 ① /
     *   Bug #59 が繰り返し警告する「走査テストが自分自身の健全性を守っていない」型そのもの。
     * ⚠ 対象は「その `$file` が実際に定義しているメソッドだけ」——`getFileName()` が
     *   `$file` と一致するものに絞る。これにより、trait を using するクラス側では trait の
     *   メソッドを二重に数えず（trait 自身のファイルを処理するときに 1 回だけ数える）、
     *   親クラスのメソッドも子クラスの側では数えない。
     * ⚠ **抽象メソッド（本体を持たない）は `isAbstract()` で明示的に除外する。**
     *   以前ここに「`getFileName()` が `false` になるので自然に除外される」と書いていたのは
     *   **誤り**（2026-09-18 の再レビューで指摘・実測で確認）——`getFileName()` が `false` に
     *   なるのは**内部（拡張が提供する）メソッドだけ**で、抽象メソッドは自分の宣言元の
     *   ファイルを正しく返す。実際にこの `isAbstract()` チェックを外して測ると、
     *   `HasScheduleSteps` の抽象メソッド 5 件・`BackupStorage` の 4 件・
     *   `DatabaseDumper::dumpTo` の計 10 件が `$bodies` に紛れ込み、
     *   件数が 1602 → 1612 になった（実測）。
     *
     * @return array<string, string>|null
     */
    private function methodBodiesIn(string $file): ?array
    {
        $fqcn = $this->fqcnForAppFile($file);

        if ($fqcn === null
            || ! (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn))
        ) {
            return null;
        }

        $reflection = new \ReflectionClass($fqcn);
        $realFile = realpath($file);
        $lines = file($file);

        $bodies = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->isAbstract()) {
                continue; // 本体を持たない。getFileName() は抽象メソッドでも宣言元のファイルを
                //     正しく返すので、下の「定義元のファイル」チェックでは除外できない
            }

            $methodFile = $method->getFileName();
            if ($methodFile === false || realpath($methodFile) !== $realFile) {
                continue; // 継承・trait 由来（定義元のファイルを処理する時にだけ数える）
            }

            $start = $method->getStartLine();
            $end = $method->getEndLine();
            if ($start === false || $end === false) {
                continue;
            }

            $slice = implode('', array_slice($lines, $start - 1, $end - $start + 1));
            $key = "{$reflection->getName()}::{$method->getName()}";
            $bodies[$key] = $this->stripCommentsFromSnippet($slice);
        }

        return $bodies;
    }

    /**
     * `$file` の中で**実際に宣言されている** class / interface / trait / enum の短い名前を
     * すべて返す（匿名クラス `new class {...}` と `X::class` は除く）。
     * `test_the_app_method_body_scan_has_no_gaps_or_collisions()` が、この結果が
     * 「ちょうど 1 つ」で「PSR-4 の名前と一致する」ことを確かめる（2026-09-18 の
     * 再レビューで指摘: 2 つ目の class-like があると `methodBodiesIn()` は PSR-4 の名前で
     * 解決した 1 つだけしか reflect せず、2 つ目のメソッドが無音で走査から消える）。
     *
     * ⚠ トークンを先頭から平らに数えるので、**メソッド本体の中の条件付きの宣言も数える**
     *   （PHP は関数・メソッドの中でのクラス宣言を許す。「トップレベルだけ見れば足りる」と
     *   直すと、そこに書かれた 2 つ目のクラスが無音で抜ける穴が戻る）。波括弧の深さは
     *   追わない——宣言の数と名前だけを見るので要らない。
     *
     * @return list<string>
     */
    private function declaredClassLikeNamesIn(string $file): array
    {
        $tokens = array_values(token_get_all($this->sourceWithoutComments($file)));
        $count = count($tokens);
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;

            if ($id === null || ! in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            // 直前の非空白トークンを見て、`new class`（匿名クラス）と `X::class` を除外する
            $prevId = null;
            for ($p = $i - 1; $p >= 0; $p--) {
                $pt = $tokens[$p];
                if (is_array($pt) && $pt[0] === T_WHITESPACE) {
                    continue;
                }
                $prevId = is_array($pt) ? $pt[0] : null;
                break;
            }
            if ($prevId === T_NEW || $prevId === T_DOUBLE_COLON) {
                continue;
            }

            // 次に現れる T_STRING を名前として採る（`{` に先に当たったら該当しない）
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_STRING) {
                    $names[] = $t[1];
                    break;
                }
                if ($t === '{') {
                    break;
                }
            }
        }

        return $names;
    }

    /** `$file`（`app_path()` 配下）を PSR-4（`App\` ⇒ `app/`）で FQCN に変換する。解決できなければ null */
    private function fqcnForAppFile(string $file): ?string
    {
        $real = realpath($file);
        $base = realpath(app_path());

        if ($real === false || $base === false || ! str_starts_with($real, $base . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = substr($real, strlen($base) + 1);
        if (! str_ends_with($relative, '.php')) {
            return null;
        }

        return 'App\\' . str_replace(\DIRECTORY_SEPARATOR, '\\', substr($relative, 0, -4));
    }

    /**
     * 行スライスで切り出した断片（`<?php` を持たない）からコメントを落とす。
     * `sourceWithoutComments()` はファイル全体（`<?php` から始まる）が対象なのでここでは
     * 使えない——先頭に `<?php\n` を補ってから `token_get_all()` に通す。
     */
    private function stripCommentsFromSnippet(string $snippet): string
    {
        $code = '';

        foreach (token_get_all("<?php\n" . $snippet) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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

        $this->assertTrue($this->claimToken($token));
        $this->assertFalse($this->claimToken($token), '同じ鍵で 2 回目が通った');
    }

    public function test_different_tokens_are_independent(): void
    {
        $this->assertTrue($this->claimToken(OneTimeAction::issue()));
        $this->assertTrue($this->claimToken(OneTimeAction::issue()));
    }

    /** 鍵そのものは保存しない（ハッシュだけ） */
    public function test_the_raw_token_is_not_stored(): void
    {
        $token = OneTimeAction::issue();
        $this->claimToken($token);

        $this->assertFalse(Cache::has($token), '鍵がそのままキャッシュのキーになっている');
        $this->assertTrue(Cache::has(OneTimeAction::cacheKey($token)));
    }
}
