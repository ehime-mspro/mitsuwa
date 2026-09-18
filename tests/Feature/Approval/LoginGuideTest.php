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

    private const NEW_LOGIN_GUIDE_PATTERN = '/\bnew\s+LoginGuide\s*\(/i';

    private const TO_GUIDE_CALL_PATTERN = '/(?:->|\?->)\s*toGuide\s*\(/i';

    private const NEW_PASSWORD_REISSUER_PATTERN = '/\bnew\s+PasswordReissuer\b/i';

    /**
     * 案内を描く入口を**拾う**ための広い判定（`LoginGuide` / `PasswordReissuer` の単語か
     * `->toGuide(` のいずれか）。「拾う」専用——実際にその場で生成しているかまでは
     * 見ない（それは上の `NEW_LOGIN_GUIDE_PATTERN` 等 ＋ 下の副作用 3 定数が個別に見る）。
     *
     * ⚠ 以前は `new LoginGuide(` のような狭い形しか拾っていなかったため、FQCN 経由の生成
     *   （`new \App\Support\Approval\LoginGuide(`）・import のエイリアス（`use … as Guide`）・
     *   コンテナ経由の解決（`app(PasswordReissuer::class)`）を素通りさせる余地があった。
     * ⚠ それでも拾えない書き方がある —— 変数に入れたクラス名からの動的生成（`new $class(...)`）は
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

    /** 副作用の起点 3 種（`NEW_LOGIN_GUIDE_PATTERN` 等と合わせ claimFrom() の防御より後にあってはいけない） */
    private const DB_TRANSACTION_PATTERN = '/\bDB\s*::\s*transaction\s*\(/i';

    private const INITIAL_PASSWORD_STATIC_PATTERN = '/\bInitialPassword\s*::/i';

    private const SET_INITIAL_PASSWORD_CALL_PATTERN = '/(?:->|\?->)\s*setInitialPassword\s*\(/i';

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
     *   Bug #59 の教訓は「**空振りの走査結果を、的外れな理由（個別の名前が見つからない等）で
     *   誤診断させない**」こと——個別の名前の有無を見るアサートが先にあると、走査が空でも
     *   「名前 X が分類されていない」という**別の・紛らわしい**理由の失敗に化ける。この走査には
     *   その形の個別チェックが無いので、問題リスト（`$problems`）を先に置いても空振りは
     *   紛らわしい理由では報告されない——空振り（0 件）でも `$problems` はただ空になるだけ
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
     * 全件分類の走査そのものが健全であること（Reflection 抽出の自己テスト。Bug #57 と同じ流儀）。
     *
     * ⚠ ここが壊れていると、次のテスト（入口が先に鍵を使うか）は**何かを見ているつもりで
     *   何も見ていない**——以前の手書きトークナイザは `X::class` を宣言と誤認し・予約語の
     *   メソッド名（`list` / `empty` / `print`）を落とし・`enum case Function` を誤認し・
     *   文字列展開の波括弧（`"${x}"` / `"$x{"`）で対応が崩れていたのに、たまたま対象の
     *   6 メソッドは生き残っていたため、読み取り専用の Reflection プロトタイプで実測するまで
     *   気づかれなかった（誤カウントされた偽クラス 320/1599 件・無音の key 衝突 71 件）。
     * ⚠ 件数はこのテストの環境で実測した値（app/ 配下の PHP ファイルすべてが
     *   PSR-4（`App\` ⇒ `app/`）で class / interface / trait / enum のいずれかに解決でき、
     *   同じ `"Class::method"` キーが 2 か所以上から生成されることも無い ＝ 非クラスファイル
     *   0 件・メソッド 1602 件・キー衝突 0 件）。ファイルが増減すれば数は動くので、
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
     *   ② その防御が、案内を描く 3 パターン（`new LoginGuide(` / `->toGuide(` /
     *   `new PasswordReissuer`）**と**副作用の 3 パターン（`DB::transaction(` /
     *   `InitialPassword::` / `->setInitialPassword(`）の**最初に現れる位置**より**前**にあること。
     *   ⚠ **副作用の 3 パターンを足したのは「防御が案内を描く式の直前にあれば十分」という思い込みを
     *   崩すため** —— `resetPassword()` / `reissue()` / `reissueBulk()` は自分の本体に副作用を
     *   持たず `PasswordReissuer::reissue()` に委ねているので影響しないが、`execute()` は自分の
     *   本体で直接 `DB::transaction(` を呼ぶ。副作用パターンを見ていなければ、`execute()` が
     *   `DB::transaction(...)` を `claimFrom()` より前へ動かしても（案内を描く `new LoginGuide(`
     *   は末尾のままなので）この走査は気づけなかった。
     * ⚠ **この順序が守るのは §5.12「起きなかった処理に鍵を焼かない」ではない**（それは逆方向の
     *   話——検証エラーで**なにも起きなかった**ときに鍵を無駄に使わないための順序で、
     *   `Admin\UserController::store()` のコメントが説明している）。**このテストが守るのは、
     *   鍵の防御が副作用・描画より後にあると、二重送信が再発行／新規登録を実際に実行してしまって
     *   から初めて拒否される**——**その時点で印刷済みの案内はもう無効**になっている、という危険
     *   （Bug #42 ②。誤った理由の注記は次の読み手を誤らせるので、以前ここに書いていた
     *   「起きなかった処理に鍵を焼く危険」は誤りだった）。
     * ⚠ **問題リストのアサートを `assertSame(5, …)` より先に置く**（stale-exclusion のチェックは
     *   それよりさらに先・**走査したメソッド本体の件数の下限はそれよりもさらに先**）。Bug #59 の
     *   教訓（前のテストの docblock 参照）と同じ理由で、件数の下限をこの一連のアサートの先頭に
     *   置く——`$problems` や stale-exclusion のチェックは、走査対象が空振り（0 件）でもそれぞれ
     *   紛らわしくない空のまま素通りしてしまい、その形で落ちるのは最後の `assertSame(5, …)` だけ
     *   になる。件数の下限を先頭に置けば、空振りは「1500 件のはずが 0 件だった」と**正しい理由**で
     *   最初に報告される。
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
        $this->assertDoesNotMatchRegularExpression(
            self::NEW_PASSWORD_REISSUER_PATTERN,
            'new PasswordReissuedMail($user)',
            '別クラス（PasswordReissuedMail）を誤検出している'
        );

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
     *   無音の key 衝突 71 件）。**6 つの対象メソッドはたまたま生き残っていたため、
     *   このテストは壊れた走査のまま緑を返し続けていた**——Top trap #13 / Bug #45 ① /
     *   Bug #59 が繰り返し警告する「走査テストが自分自身の健全性を守っていない」型そのもの。
     * ⚠ 対象は「その `$file` が実際に定義しているメソッドだけ」——`getFileName()` が
     *   `$file` と一致するものに絞る。これにより、trait を using するクラス側では trait の
     *   メソッドを二重に数えず（trait 自身のファイルを処理するときに 1 回だけ数える）、
     *   親クラスのメソッドも子クラスの側では数えない。
     * ⚠ 抽象メソッド（本体を持たない）は `getFileName()` が `false` になるので自然に除外される。
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
            $methodFile = $method->getFileName();
            if ($methodFile === false || realpath($methodFile) !== $realFile) {
                continue; // 抽象メソッド・継承・trait 由来（定義元のファイルを処理する時にだけ数える）
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
