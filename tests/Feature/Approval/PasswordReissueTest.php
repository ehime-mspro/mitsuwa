<?php

namespace Tests\Feature\Approval;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\PasswordReissuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * パスワード再発行と通知メール（設計書 §5.13・要件 12.5・8.1・8.2）。
 *
 * ⚠ 新しいパスワードは**メールに書かない**（紙で渡す）。身に覚えのない再発行に気づくための通知。
 * ⚠ 送るのは**許可するドメイン**のメールアドレスを持つ人だけ（要件 8.2）。
 */
class PasswordReissueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => '管理 花子', 'must_change_password' => false]);
    }

    public function test_it_replaces_the_password_and_requires_a_change(): void
    {
        $this->actingAs($this->admin());

        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        $old  = $user->password;

        $result = (new PasswordReissuer())->reissue(collect([$user]));

        $user->refresh();
        $this->assertNotSame($old, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($result->entries[0]['password'], $user->password));
    }

    /** 強度 10（初回ログインで自動的に掛け直される） */
    public function test_the_new_password_uses_cost_10(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        (new PasswordReissuer())->reissue(collect([$user]));

        $this->assertSame(10, password_get_info($user->fresh()->password)['options']['cost']);
    }

    /**
     * 初期パスワードを入れたあと、**`hashed` キャストが戻っている**こと。
     *
     * ⚠ 強度 10 の済ハッシュを入れるには、その 1 回だけキャストを外す必要がある
     *   （`hashed` は「今の設定より高いコストの済ハッシュ」を弾き、テストは
     *   `BCRYPT_ROUNDS=4` なので 10 > 4 で例外になる）。戻し忘れると、そのインスタンスに
     *   あとから平文を代入したとき**ハッシュされずにそのまま保存される**。
     */
    public function test_the_hashed_cast_is_restored_afterwards(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        (new PasswordReissuer())->reissue(collect([$user]));

        // 同じインスタンスに平文を代入する（本人のパスワード変更と同じ経路）
        $user->password = 'plain-text-password';
        $user->save();

        $stored = $user->fresh()->password;

        $this->assertNotSame('plain-text-password', $stored, 'パスワードが平文のまま保存されている');
        $this->assertTrue(Hash::check('plain-text-password', $stored));
    }

    public function test_it_notifies_only_allowed_domains(): void
    {
        $this->actingAs($this->admin());

        $ok      = User::factory()->create(['email' => 'ok@mitsuwat.co.jp', 'must_change_password' => false]);
        $outside = User::factory()->create(['email' => 'ng@gmail.com', 'must_change_password' => false]);
        $noMail  = User::factory()->create(['email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$ok, $outside, $noMail]));

        $this->assertSame(1, $result->notifiedCount);
        $this->assertSame(2, $result->skippedCount);

        Mail::assertQueued(PasswordReissuedMail::class, 1);
        Mail::assertQueued(PasswordReissuedMail::class, fn (PasswordReissuedMail $mail) => $mail->hasTo('ok@mitsuwat.co.jp'));
    }

    /** 本文に新しいパスワードを書かない（設計書 §5.13） */
    public function test_the_mail_does_not_contain_the_new_password(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['name' => '甲 一郎', 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$user]));
        $password = $result->entries[0]['password'];

        Mail::assertQueued(PasswordReissuedMail::class, function (PasswordReissuedMail $mail) use ($password) {
            $body = $mail->render();

            $this->assertStringNotContainsString($password, $body, '新しいパスワードがメールに書かれている');
            $this->assertStringContainsString('甲 一郎', $body);
            $this->assertStringContainsString('管理 花子', $body, '実施した管理者の氏名が無い');
            $this->assertStringContainsString('身に覚えがない場合', $body);
            $this->assertStringContainsString(route('login'), $body, 'ログイン画面の URL が無い');

            return true;
        });
    }

    /**
     * 日時は**日本時間**で出す（段階0 のメール 2 本と同じ）。
     *
     * ⚠ `config/app.php` の `timezone` は **`'UTC'` の直書き**で、`env()` を通さないので
     *   `.env` の `APP_TIMEZONE` では変わらない（実測）。`now()` をそのまま整形すると
     *   **9 時間前**の時刻が本文に出る。このメールは「身に覚えのない再発行に気づく」ための
     *   ものなので（要件 12.5）、本人が自分のその日と突き合わせられないと目的を果たさない。
     *
     * ⚠ **期待値は直書きする。** 実装と同じ式で組み立てると、実装が UTC のままでも緑になる。
     * ⚠ **JST にすると日付も変わる時刻**を選ぶ（UTC のままなら「2026年9月16日 15:30」と出て
     *   はっきり区別できる。同じ日の中で時だけずれる時刻を選ぶと読み違えやすい）。
     */
    public function test_the_mail_shows_the_time_in_japan_time(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-16 15:30:00', 'UTC'));

        $this->actingAs($this->admin());
        $user = User::factory()->create(['name' => '甲 一郎', 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        (new PasswordReissuer())->reissue(collect([$user]));

        Mail::assertQueued(PasswordReissuedMail::class, function (PasswordReissuedMail $mail) {
            $body = $mail->render();

            $this->assertStringContainsString('2026年9月17日 00:30', $body, '再発行の日時が日本時間で出ていない');
            $this->assertStringNotContainsString('2026年9月16日 15:30', $body, 'UTC のまま出ている');
            $this->assertStringContainsString('（日本時間）', $body, 'どの時間帯か書かれていない');

            return true;
        });
    }

    /** キューに積む（5 分おきの定期実行で送る。段階0 の土台） */
    public function test_the_mail_is_queued(): void
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new PasswordReissuedMail(
            User::factory()->create(['must_change_password' => false]),
            '管理 花子',
            now(),
            'https://example.com/login',
        ));
    }

    /**
     * 案内の画面へ渡すときに、**送る人数と送らない人数が入れ替わらない**こと。
     *
     * ⚠ `ReissueResult::toGuide()` は `LoginGuide` の**位置引数**に詰め替えるので、
     *   2 つを取り違えても型では気づけない。**必ず非対称な値**（ここでは 2 と 1）で見ること。
     *   1 対 1 だと入れ替えが原理的に見えない。
     */
    public function test_the_guide_does_not_swap_the_notified_and_skipped_counts(): void
    {
        $this->actingAs($this->admin());

        $users = collect([
            User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]),
            User::factory()->create(['email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]),
            User::factory()->create(['email' => null, 'employee_number' => 'M001', 'must_change_password' => false]),
        ]);

        $result = (new PasswordReissuer())->reissue($users);

        $this->assertSame(2, $result->notifiedCount);
        $this->assertSame(1, $result->skippedCount);

        $html = $result->toGuide('http://localhost/_test/back')->toResponse(request())->getContent();

        $this->assertStringContainsString('通知メール: 送る 2 人／送らない 1 人', $html, '送る人数と送らない人数が入れ替わっている');
    }

    /**
     * 1 回の失敗でそのまま `failed()` を呼ばせる（`$tries = 1`）。
     *
     * ⚠ これは**実挙動**を決めている。`routes/console.php` の worker は
     *   `--tries=3 --backoff=60` なので、この行が消えると 3 回・60 秒待ちに変わる
     *   （`SendQueuedMailable` が `property_exists($mailable, 'tries')` でペイロードに載せ、
     *   `Job::maxTries()` が worker の指定を上書きする。vendor で確認済み）。
     */
    public function test_it_gives_up_after_one_attempt(): void
    {
        $mail = new PasswordReissuedMail(
            User::factory()->create(['must_change_password' => false]),
            '管理 花子',
            now(),
            'https://example.com/login',
        );

        // ⚠ 先に存在を見る（消されたときの赤が「Undefined property」になって理由が読めないため）
        $this->assertTrue(property_exists($mail, 'tries'), '$tries が無い（worker の --tries=3 に従うようになる）');
        $this->assertSame(1, $mail->tries);
    }

    /**
     * 送れなかったら**宛先つきで** `laravel.log` に残す。
     *
     * ⚠ 本番の `LOG_LEVEL` は `error` なので、この `Log::error` が唯一の手がかり。
     *   運用手順書が「5 分たっても届かないときは laravel.log の行に理由が出ている」と
     *   案内しているので、中身が空になると**無音で死ぬ**。
     */
    public function test_a_failure_is_logged_with_the_recipient(): void
    {
        Log::spy();

        $mail = new PasswordReissuedMail(
            User::factory()->create(['must_change_password' => false]),
            '管理 花子',
            now(),
            'https://example.com/login',
        );
        $mail->to('a@mitsuwat.co.jp');

        $mail->failed(new \RuntimeException('SMTP がつながりません'));

        Log::shouldHaveReceived('error')->withArgs(function (string $message) {
            $this->assertStringContainsString('a@mitsuwat.co.jp', $message, '宛先が記録に無い');
            $this->assertStringContainsString('SMTP がつながりません', $message, '理由が記録に無い');

            return true;
        })->once();
    }

    /** 件名（利用者が受信箱で見分ける唯一の手がかり） */
    public function test_the_subject_says_what_happened(): void
    {
        $mail = new PasswordReissuedMail(
            User::factory()->create(['must_change_password' => false]),
            '管理 花子',
            now(),
            'https://example.com/login',
        );

        $this->assertSame('【経営管理システム】パスワードを再発行しました', $mail->envelope()->subject);
    }

    /**
     * ログイン画面の URL は**呼び出し側から渡す**（Mailable の中で `route()` を呼ばない）。
     *
     * ⚠ **振る舞いでは測れない。** テストの `APP_URL` は `http://localhost` なので、
     *   `route('login')` でも `config('app.url').'/login'` でも同じ文字列になる（実測で全緑）。
     *   本番は `/index.php` を挟むので、キューの中で組み立てると壊れる（要件 15.1）。
     *   よって**ソースの構造**で固定する。
     * ⚠ コメントを落としてから測る（docblock 自身が `route(` と書いているので、
     *   そのままだと実体を消しても緑のまま通る。Bug #42 ②）。
     * ⚠ **両側を見る。** Mailable が `route()` を呼ばないことだけを固定しても、
     *   呼び出し側が `config('app.url')` で組み立てたら同じ壊れ方をする
     *   （最初この 1 本を Mailable だけに当てていて、呼び出し側への変異が緑のまま通った。
     *   Bug #44 の「当たり先が正しいか」）。
     */
    public function test_the_login_url_comes_from_the_request_not_from_config(): void
    {
        // 受け取る側: 自分では組み立てない（キューの中で走るため）
        $mailable = $this->sourceWithoutComments(app_path('Mail/PasswordReissuedMail.php'));

        $this->assertStringNotContainsString('route(', $mailable, 'Mailable の中で URL を組み立てている（キューの中では APP_URL に頼ることになる）');
        $this->assertDoesNotMatchRegularExpression('/config\(\s*[\'"]app\.url/', $mailable);

        // 渡す側: その場のリクエストから作る（`config('app.url')` は本番の `/index.php` を含まない）
        $caller = $this->sourceWithoutComments(app_path('Support/Approval/PasswordReissuer.php'));

        $this->assertStringContainsString("route('login')", $caller, 'ログイン画面の URL をリクエストから作っていない');
        // ⚠ 素の文字列一致だと `config("app.url")` が素通りする
        $this->assertDoesNotMatchRegularExpression('/config\(\s*[\'"]app\.url/', $caller, '設定から組み立てている（本番で /index.php が抜ける）');
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

    /** 記録が 1 人 1 行残る（パスワードそのものは残さない） */
    public function test_it_records_each_reissue_without_the_password(): void
    {
        $this->actingAs($this->admin());

        $a = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        $b = User::factory()->create(['email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$a, $b]));

        // ⚠ 件数だけ見ると、対象が誰か（target_type / target_id）が消えても緑のまま通る（実測）
        $logs = ApprovalSettingLog::where('action', 'user.password_reissued')->get();

        $this->assertSame(2, $logs->count());
        $this->assertSame(['user', 'user'], $logs->pluck('target_type')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $logs->pluck('target_id')->all(), '誰の再発行かが記録に残っていない');

        $dumped = ApprovalSettingLog::all()->toJson();
        foreach ($result->entries as $entry) {
            $this->assertStringNotContainsString($entry['password'], $dumped, 'パスワードが記録に残っている');
        }
    }

    /**
     * 途中で落ちたら**丸ごと巻き戻る**（1 回のトランザクション）。
     *
     * ⚠ 平文は戻り値にしか無く DB には残さないので、囲わないと
     *   「もう変わっているのに誰も新しいパスワードを知らない人」が残る。その人はログインできず、
     *   管理者は誰が該当するかも画面から分からない。
     *   本番の bcrypt は 0.305 秒/件でまとめて 50 人 ＝ 約 15 秒なので、実行時間切れは絵空事ではない。
     *
     * ⚠ 応答ではなく **DB に何が書かれたか**を見る（例外は呼び出し側まで上がるので、
     *   「例外が出たこと」だけを見ても巻き戻ったかは分からない）。
     */
    public function test_a_failure_partway_through_rolls_everything_back(): void
    {
        $this->actingAs($this->admin());

        $first = User::factory()->create(['name' => '甲 一郎', 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        $boom  = User::factory()->create(['name' => '爆 二郎', 'email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);

        $before = $first->password;

        User::saving(function (User $user) {
            if ($user->name === '爆 二郎') {
                throw new \RuntimeException('boom');
            }
        });

        try {
            (new PasswordReissuer())->reissue(collect([$first, $boom]));
            $this->fail('例外が出ていない（この検査そのものが成立していない）');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($before, $first->fresh()->password, '1 人目のパスワードが変わったまま残っている（誰も新しい値を知らない）');
        $this->assertFalse($first->fresh()->must_change_password);
        $this->assertSame(0, ApprovalSettingLog::where('action', 'user.password_reissued')->count(), '起きなかった再発行の記録が残っている');
    }

    /** ドメインが 1 件も登録されていなければ誰にも送らない */
    public function test_no_mail_is_sent_when_no_domain_is_registered(): void
    {
        ApprovalMailDomain::query()->delete();
        $this->actingAs($this->admin());

        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$user]));

        $this->assertSame(0, $result->notifiedCount);
        Mail::assertNothingQueued();
    }
}
