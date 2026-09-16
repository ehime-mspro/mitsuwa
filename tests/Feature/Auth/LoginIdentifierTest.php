<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 社員番号またはメールアドレスでのログイン（設計書 §5.3）。
 *
 * ⚠ **画面が描画したフォームを分解して送り返す**（Bug #47）。値を直接 POST すると、
 *   入力欄の `name` が変わっても・`action` が変わっても・`@csrf` が消えても緑のまま通る。
 * ⚠ 画面の文言を見るテストで `assertSessionHas*()` を呼ばない（Bug #49）。
 * ⚠ `must_change_password` は必ず明示する。
 */
class LoginIdentifierTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private function submit(string $loginId, string $password): TestResponse
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('login') . '"');

        $this->assertSame('POST', $form['method'], 'ログインフォームが POST でない');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');
        $this->assertArrayHasKey('login_id', $form['fields'], 'ログイン ID の入力欄が無い');

        return $this->post($form['action'], array_merge($form['fields'], [
            'login_id' => $loginId,
            'password' => $password,
        ]));
    }

    public function test_the_form_asks_for_an_employee_number_or_an_email(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号またはメールアドレス', $html);
        $this->assertStringContainsString('name="login_id"', $html);
        // ⚠ type="email" だとブラウザが社員番号を弾く
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="login_id"[^>]*type="email"/',
            $html,
            'ログイン ID の欄が type="email" になっている（社員番号を入力できない）'
        );
        $this->assertStringNotContainsString('name="email"', $html, '古い email の入力欄が残っている');
    }

    public static function identifierCases(): array
    {
        return [
            '社員番号 そのまま'   => ['M001'],
            '社員番号 小文字'     => ['m001'],
            '社員番号 全角'       => ['Ｍ００１'],
            '社員番号 前後の空白' => ['  M001  '],
        ];
    }

    #[DataProvider('identifierCases')]
    public function test_login_with_an_employee_number(string $typed): void
    {
        $user = User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);

        $this->submit($typed, 'password')->assertRedirect(route('dashboard.tenant'));
        $this->assertAuthenticatedAs($user);
    }

    public static function emailCases(): array
    {
        return [
            'そのまま' => ['user@example.com'],
            '大文字'   => ['User@Example.COM'],
            '全角の＠' => ['ｕｓｅｒ＠ｅｘａｍｐｌｅ.ｃｏｍ'],
        ];
    }

    #[DataProvider('emailCases')]
    public function test_login_with_an_email(string $typed): void
    {
        $user = User::factory()->create([
            'email'                => 'user@example.com',
            'employee_number'      => null,
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);

        $this->submit($typed, 'password')->assertRedirect(route('dashboard.tenant'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * `@` の有無だけで引く列を決める（設計書 §5.3）。
     *
     * ⚠ 社員番号と同じ文字列をメールに持つ別人が居ても、取り違えないこと。
     */
    public function test_the_at_sign_decides_which_column_is_used(): void
    {
        $byNumber = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);
        $byEmail  = User::factory()->create(['employee_number' => 'M002', 'email' => 'm001@example.com', 'must_change_password' => false]);

        $this->submit('M001', 'password');
        $this->assertAuthenticatedAs($byNumber);

        $this->post('/logout');

        $this->submit('m001@example.com', 'password');
        $this->assertAuthenticatedAs($byEmail);
    }

    /** どちらが違うかを言わない（設計書 §5.3） */
    public function test_failure_message_does_not_say_which_part_was_wrong(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        // ⚠ followingRedirects() は 1 回だけ効く一発フラグで、submit() 内の最初の
        //   $this->get('/login')（フォーム取得）に消費されてしまい、本命の POST の
        //   リダイレクトが辿られない（実測）。submit() → 別途 get() で確実に辿る。
        $this->submit('M001', 'wrong');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号・メールアドレスまたはパスワードが正しくありません。', $html);
    }

    public function test_unknown_identifier_gets_the_same_message(): void
    {
        $this->submit('M999', 'whatever');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号・メールアドレスまたはパスワードが正しくありません。', $html);
        $this->assertGuest();
    }

    public function test_blank_identifier_is_rejected_in_japanese(): void
    {
        $this->submit('', 'password');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号またはメールアドレスを入力してください。', $html);
    }

    /** 入力は残す（打ち直させない） */
    public function test_the_typed_identifier_is_kept_on_failure(): void
    {
        $this->submit('M001', 'wrong');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="login_id"[^>]*value="M001"/', $html, '入力したログイン ID が残っていない');
    }

    public static function roleCases(): array
    {
        return [
            [UserRole::Executive->value,    'dashboard.executive'],
            [UserRole::Manager->value,      'dashboard.tenant'],
            [UserRole::Staff->value,        'dashboard.tenant'],
            [UserRole::ApprovalOnly->value, 'approvals.home'],
        ];
    }

    #[DataProvider('roleCases')]
    public function test_where_each_role_lands(string $role, string $expected): void
    {
        User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'role'                 => $role,
            'must_change_password' => false,
        ]);

        $this->submit('M001', 'password')->assertRedirect(route($expected));
    }

    /** 初回はロールに関係なくパスワード変更へ */
    public function test_first_login_goes_to_the_password_change_screen(): void
    {
        User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'role'                 => UserRole::ApprovalOnly->value,
            'must_change_password' => true,
        ]);

        $this->submit('M001', 'password')->assertRedirect(route('password.change'));
    }

    /** パスワード変更のあとも同じ規則で振り分ける（`/dashboard` のクロージャ） */
    public function test_the_dashboard_route_uses_the_same_rule(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::ApprovalOnly->value,
            'employee_number'      => 'M001',
            'email'                => null,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('approvals.home'));
    }
}
