<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\EnforcesCsrfToken;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 画面を開いたまま時間がたったか、ほかの画面でログアウトしたあとに保存したとき（送信の鍵が合わない＝419）の応答。
 *
 * 直す前（2026-11-17 の実測）は、基幹の保存の Ajax が英語の「CSRF token mismatch.」を受け取り、約 16 画面がそのまま
 * 出していた（例「保存に失敗しました: CSRF token mismatch.」）。決裁の URL だけ日本語だった（段階2 の Task 19）。
 *
 * ⚠ 419 はテストでも鍵の確かめを効かせて本物を出す（EnforcesCsrfToken）。ルートを足して例外を投げる形では、鍵の確かめから
 *   応答までの道を通らない。
 * ⚠ 画面に届くことは、サーバーの文をそのまま出す画面で、JS が組んだ要求を送り、返った応答を JS に戻して見る（Bug #47）。
 *   マスタの並び替え（InlineNameMasterTestCase）・ZEAL の店舗（StoreMasterTest）も同じ形で見る。
 */
class ExpiredSessionResponseTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use EnforcesCsrfToken;

    private const PAGE_TITLE = '画面の有効期限が切れました';

    private const PAGE_REASON = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした（何も保存していません）。';

    /** ログインしたまま 419 になった（ほかの画面でログインし直した・「ログイン状態を保持」で自動でログインし直した） */
    private const PAGE_NEXT_SIGNED_IN = '「前の画面に戻る」で戻ると、そのまま送り直せます。入力した内容が消えていたら、入れ直してください。';

    /** ログインが切れて 419 になった（セッションが切れた・ほかの画面でログアウトした） */
    private const PAGE_NEXT_SIGNED_OUT = 'ログインが切れています。「前の画面に戻る」で戻って入力した内容を控えてから、ログインし直して、もう一度入力してください（戻った画面から送ると、ログイン画面に移ります）。';

    /** 419 の画面で送り直すフォームの新しいパスワード */
    private const NEW_PASSWORD = 'NewPass1234';

    /** ログインの送信が古い鍵で断られたときに、ログイン画面の赤い帯に出す文（Bug #138） */
    private const EXPIRED_LOGIN_MESSAGE = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、ログインできませんでした。もう一度ログインしてください。';

    /**
     * ふつうのフォームの送信（JSON を求めない）は、日本語の画面（resources/views/errors/419.blade.php）を番号 419 のまま返す。
     * ⚠ 直す前は Laravel の英語の画面「419 Page Expired」だった
     * ⚠ 2 段落目と 2 つめのボタンはログインしているかで出し分ける。新しい鍵を残す script は両方にある
     *   （「前の画面に戻る」で戻った画面の鍵を差し替える。FreshCsrfTokenTest）
     */
    private function assertExpiredPage(TestResponse $response, bool $signedIn): void
    {
        $response->assertStatus(419);
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('Page Expired', $html);
        // タブ名でログイン画面と区別できる（直す前はアプリ名だけで、ログイン画面と同じだった。Bug #137 の後回し）
        $this->assertStringContainsString('<title>' . e(self::PAGE_TITLE . ' - ' . config('app.name', '経営管理システム')) . '</title>', $html, 'タブ名が「画面の有効期限が切れました - アプリ名」でない');
        $this->assertMatchesRegularExpression('/<h1\b[^>]*>\s*' . preg_quote(self::PAGE_TITLE, '/') . '\s*<\/h1>/u', $html, '見出しが無い');
        $this->assertMatchesRegularExpression('/<p\b[^>]*>\s*' . preg_quote(e(self::PAGE_REASON), '/') . '\s*<\/p>/u', $html, '理由の文が無い');
        [$next, $other] = $signedIn ? [self::PAGE_NEXT_SIGNED_IN, self::PAGE_NEXT_SIGNED_OUT] : [self::PAGE_NEXT_SIGNED_OUT, self::PAGE_NEXT_SIGNED_IN];
        $this->assertMatchesRegularExpression('/<p\b[^>]*>\s*' . preg_quote(e($next), '/') . '\s*<\/p>/u', $html, '次にすることの文が無い');
        $this->assertStringNotContainsString(e($other), $html, 'もう一方の場合の文が出ている');
        $this->assertMatchesRegularExpression('/<button\b[^>]*\bonclick="history\.back\(\)"[^>]*>\s*前の画面に戻る\s*<\/button>/u', $html, '「前の画面に戻る」が無い');
        [$href, $label] = $signedIn ? [url('/'), 'トップページへ'] : [route('login'), 'ログイン画面へ'];
        $this->assertMatchesRegularExpression('/<a\b[^>]*\bhref="' . preg_quote($href, '/') . '"[^>]*>\s*' . $label . '\s*<\/a>/u', $html, "「{$label}」が無い");
        $this->assertStringNotContainsString($signedIn ? 'ログイン画面へ' : 'トップページへ', $html, 'もう一方の場合のボタンが出ている');
        preg_match_all('/<script>(.*?)<\/script>/s', $html, $scripts);
        $this->assertNotEmpty(array_filter($scripts[1], fn (string $s) => str_contains($s, 'window.rememberFreshCsrfToken()')), '鍵を残す script が無い');
    }

    /** 画面に描かれた送信の鍵（レイアウトの <meta name="csrf-token">） */
    private function tokenOnPage(string $html): string
    {
        $this->assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]+)">/', $html, $m), '鍵のメタが無い');

        return $m[1];
    }

    /**
     * 描いたパスワードの変更の画面のフォームを、鍵 $token で送る（今のパスワードは factory の password）。
     * ⚠ call() でなく post()（withCookie で足した「ログイン状態を保持」の cookie を載せるため）
     *
     * @param  array{method: string, action: string, fields: array<string, mixed>}  $form
     */
    private function sendPasswordForm(array $form, string $token): TestResponse
    {
        return $this->post($form['action'], array_merge($form['fields'], [
            'current_password'      => 'password',
            'password'              => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            '_token'                => $token,
        ]));
    }

    /** パスワードの変更の画面を開き、描いたフォームを返す（鍵は今のセッションの鍵） */
    private function openPasswordForm(User $user): array
    {
        $html = $this->actingAs($user)->get(route('password.change'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('password.update') . '"');
        $this->assertNotSame('', $form['fields']['_token'] ?? '', 'フォームに @csrf が無い');

        return $form;
    }

    /** ログインした人が、画面を開いたまま時間がたってからフォームを送る（パスワードの変更の画面で見る） */
    public function test_a_form_sent_with_an_expired_session_shows_a_japanese_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $html = $this->actingAs($user)->get(route('password.change'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('password.update') . '"');
        $this->assertNotSame('', $form['fields']['_token'] ?? '', 'フォームに @csrf が無い');

        $this->enforceCsrfToken();
        $response = $this->call('POST', $form['action'], array_merge($form['fields'], ['_token' => 'token-of-the-old-session']));

        $this->assertExpiredPage($response, true);
    }

    /**
     * ほかの画面でログアウトしてログインし直した（ログインしたまま鍵が変わった）。419 の画面に描かれた鍵は今のセッションの鍵で、
     * 「前の画面に戻る」で戻った画面の鍵をそれに差し替えれば（FreshCsrfTokenTest）、同じフォームがそのまま通る。
     * ⚠ 直す前は戻った画面が古い鍵のままで、送るとまた 419 だった
     */
    public function test_a_signed_in_user_can_send_the_same_form_again_with_the_token_from_the_419_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $form = $this->openPasswordForm($user);
        $old = $form['fields']['_token'];
        session()->regenerateToken();
        $this->enforceCsrfToken();

        $response = $this->sendPasswordForm($form, $old);
        $this->assertExpiredPage($response, true);
        $fresh = $this->tokenOnPage((string) $response->getContent());
        $this->assertSame(session()->token(), $fresh, '419 の画面の鍵が今のセッションの鍵でない');
        $this->assertNotSame($old, $fresh);

        $this->sendPasswordForm($form, $fresh)->assertRedirect(route($user->homeRouteName()));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password), '送り直したフォームが保存されていない');
    }

    /**
     * ログインが切れた（セッションが切れた・ほかの画面でログアウトした）。419 の画面はそう伝え、戻った画面から送ると
     * 鍵は通ってログイン画面に移る（直す前は 419 の繰り返し）
     */
    public function test_a_signed_out_user_is_told_so_and_the_token_takes_the_form_to_the_login_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $form = $this->openPasswordForm($user);
        session()->invalidate();
        $this->app['auth']->forgetGuards();
        $this->enforceCsrfToken();

        $response = $this->sendPasswordForm($form, $form['fields']['_token']);
        $this->assertExpiredPage($response, false);
        $this->assertGuest();

        $this->sendPasswordForm($form, $this->tokenOnPage((string) $response->getContent()))->assertRedirect(route('login'));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password), 'ログインしていないのにパスワードが変わった');
    }

    /**
     * 「ログイン状態を保持」の人は、セッションが切れても 419 の画面を描くときに自動でログインし直し、そこで鍵が変わる。
     * ⚠ 419 の画面はログインしているかをメタ（レイアウト）より前に確かめるので、メタは変わったあとの鍵になる。
     *   確かめる位置がメタより後ろだと、メタが古い鍵になり、送り直しがまた 419 になる
     */
    public function test_a_remembered_user_is_signed_back_in_and_the_page_carries_the_new_token(): void
    {
        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $form = $this->openPasswordForm($user);
        session()->invalidate();
        $this->app['auth']->forgetGuards();
        $this->enforceCsrfToken();
        $this->withCookie(Auth::guard('web')->getRecallerName(), $user->id . '|' . $user->remember_token . '|' . $user->password);

        $response = $this->sendPasswordForm($form, $form['fields']['_token']);
        $this->assertExpiredPage($response, true);
        $this->assertAuthenticatedAs($user);
        $fresh = $this->tokenOnPage((string) $response->getContent());
        $this->assertSame(session()->token(), $fresh, '419 の画面の鍵が、自動でログインし直したあとの鍵でない');

        $this->sendPasswordForm($form, $fresh)->assertRedirect(route($user->homeRouteName()));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password), '送り直したフォームが保存されていない');
    }

    /**
     * ログイン画面を古い鍵のまま送る（ログイン画面を開いたまま時間がたった・ほかの画面でログアウトした）。描いた画面のフォームから送る。
     *
     * @param  array<string, mixed>  $fields  フォームの欄に上書きする値
     */
    private function sendLoginWithExpiredToken(array $fields): TestResponse
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('login') . '"');
        $this->assertNotSame('', $form['fields']['_token'] ?? '', 'ログインのフォームに @csrf が無い');

        $this->enforceCsrfToken();

        return $this->call('POST', $form['action'], array_merge($form['fields'], $fields, ['_token' => 'token-of-the-old-session']));
    }

    /** 赤い帯（ログイン画面の `$errors->has('login')`）に、古い鍵で断った理由が全文で出ている */
    private function assertExpiredLoginReason(string $html): void
    {
        $this->assertMatchesRegularExpression('/<span\b[^>]*>\s*' . preg_quote(self::EXPIRED_LOGIN_MESSAGE, '/') . '\s*<\/span>/u', $html, '赤い帯に理由が無い');
        $this->assertStringNotContainsString('Page Expired', $html);
    }

    /**
     * ログインの送信だけは 419 の画面を出さず、新しいログイン画面へ戻して赤い帯に理由を出す（Bug #138）。
     * ⚠ 直す前は 419 の画面で、緑のボタン「前の画面に戻る」が古い鍵のログイン画面へ戻すので、何度送っても 419 だった
     *   （2026-11-18 に本番で起きた。「トップページへ」か開き直すまでログインできない）
     * ⚠ 控えに入れるのは ID と「ログイン状態を保持」だけ。パスワードは控えない
     */
    public function test_an_expired_login_form_returns_to_a_fresh_login_page_with_the_reason(): void
    {
        $response = $this->sendLoginWithExpiredToken(['login_id' => 'yamada@example.test', 'password' => 'secret-pass', 'remember' => 'on']);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(['login_id' => 'yamada@example.test', 'remember' => 'on'], session()->getOldInput(), '控えが ID と「ログイン状態を保持」だけでない');

        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>' . e(config('app.name', '経営管理システム')) . '</title>', $html, 'ログイン画面のタブ名がアプリ名だけでなくなった');
        $this->assertExpiredLoginReason($html);
        $form = $this->parseForm($html, 'action="' . route('login') . '"');
        $this->assertSame('yamada@example.test', $form['fields']['login_id'] ?? null, 'ID が残っていない');
        $this->assertArrayHasKey('remember', $form['fields'], '「ログイン状態を保持」のチェックが残っていない');
        $this->assertSame('', $form['fields']['password'] ?? '', 'パスワードが残っている');
    }

    /** 戻った画面のフォームはそのまま送ればログインできる（新しい鍵が描かれている。直す前の行き止まりが無い） */
    public function test_the_login_page_it_returns_to_can_log_in(): void
    {
        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false, 'email' => 'yamada@example.test']);
        $this->sendLoginWithExpiredToken(['login_id' => 'yamada@example.test', 'password' => 'password'])->assertRedirect(route('login'));
        $this->assertGuest();

        $form = $this->parseForm($this->get(route('login'))->assertOk()->getContent(), 'action="' . route('login') . '"');
        $this->call('POST', $form['action'], array_merge($form['fields'], ['password' => 'password']))
            ->assertRedirect(route($user->homeRouteName()));
        $this->assertAuthenticatedAs($user);
    }

    /** 手で組んだ文字でない値は控えない（ログイン画面は old('login_id') を描くので、控えると戻った画面が 500 になる。Bug #121） */
    public function test_an_expired_login_form_with_a_non_string_id_does_not_break_the_login_page(): void
    {
        $this->sendLoginWithExpiredToken(['login_id' => ['x'], 'password' => 'x', 'remember' => ['on']])->assertRedirect(route('login'));
        $this->assertSame([], session()->getOldInput(), '文字でない値を控えている');

        $this->assertExpiredLoginReason($this->get(route('login'))->assertOk()->getContent());
    }

    /** JSON を求める古い鍵のログインは、ほかの JSON の要求と同じ日本語の JSON の 419 のまま（分岐の順番） */
    public function test_an_expired_login_that_asks_for_json_keeps_the_json_reason(): void
    {
        $this->enforceCsrfToken();

        $this->withHeaders(['X-CSRF-TOKEN' => 'token-of-the-old-session'])
            ->postJson(route('login'), ['login_id' => 'yamada@example.test', 'password' => 'x'])
            ->assertStatus(419)
            ->assertExactJson(['message' => self::EXPIRED_JSON_MESSAGE]);
        $this->assertGuest();
    }

    /** 基幹の保存の Ajax（JSON を求める要求）は、日本語の理由を JSON で受け取る */
    public function test_a_save_with_an_expired_session_gets_the_reason_in_japanese(): void
    {
        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $this->enforceCsrfToken();

        $this->actingAs($user)
            ->withHeaders(['X-CSRF-TOKEN' => 'token-of-the-old-session'])
            ->postJson(route('admin.master.usage-types.reorder'), ['ids' => [1]])
            ->assertStatus(419)
            ->assertExactJson(['message' => self::EXPIRED_JSON_MESSAGE]);
    }

    /** 添付の部品（7 画面が共用）で削除すると、部品の赤い文に理由が出て、添付は消えない */
    public function test_the_attachment_section_says_why_the_file_was_not_deleted(): void
    {
        Storage::fake('public');
        $this->seed(DepartmentSeeder::class);
        $staff = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);
        $staff->departments()->attach(Department::where('code', 'tenant')->value('id'));
        $this->actingAs($staff);

        $path = 'attachments/contracts/1/plan.pdf';
        Storage::disk('public')->put($path, 'FAKEBYTES');
        $attachment = Attachment::create([
            'attachable_type' => Contract::class,
            'attachable_id'   => 1,
            'file_name'       => 'plan.pdf',
            'file_path'       => $path,
            'file_size'       => 9,
            'mime_type'       => 'application/pdf',
            'uploaded_by'     => $staff->id,
        ]);
        $html = view('components.attachment-section', [
            'attachableType'     => 'contracts',
            'attachableId'       => 1,
            'attachments'        => collect([$attachment->fresh()]),
            'deletedAttachments' => collect(),
        ])->render();
        $steps = 'data.deleteFile(0);';

        $sent = $this->driveAlpine($html, 'attachmentSection', 'attachmentSection()', $steps);
        $this->assertCount(1, $sent['requests'], '削除の要求を送っていない');
        $this->assertSame('DELETE', $sent['requests'][0]['method']);

        $this->enforceCsrfToken();
        $response = $this->sendCaptured($this->withExpiredToken($sent['requests'][0]));
        $response->assertStatus(419);
        $after = $this->driveAlpine($html, 'attachmentSection', 'attachmentSection()', $steps, [$this->asFetchResponse($response)]);

        $this->assertSame(self::EXPIRED_JSON_MESSAGE, $after['state']['errorMessage']);
        $this->assertSame(['plan.pdf'], array_column($after['state']['attachments'], 'file_name'), '画面の一覧から消えた');
        $this->assertNotSoftDeleted($attachment);
    }
}
