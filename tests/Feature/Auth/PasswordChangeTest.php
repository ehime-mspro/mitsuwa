<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Middleware\RestrictApprovalOnlyUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * パスワード変更（`PasswordController`）。
 *
 * ⚠ **2026-08-17 まで、この 70 行のコントローラにテストが 1 本も無かった。**
 *   現在のパスワード照合・使い回し禁止・複雑さ要件・`must_change_password` の解除が
 *   すべて無防備で、初回ログインの必須フローがここを通る。
 *
 * ⚠ **画面の文言を見るテストで `assertSessionHas*()` を呼ばない**（Bug #49）。
 *   期待文言はセッション経由ではなく、コントローラが返す実際の文字列で突き合わせる。
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    /**
     * ⚠ **factory 既定の `'password'` は使えない。** 複雑さ要件（数字必須）を満たさないため、
     *   「現在と同じパスワードは使えない」の判定に**到達する前にバリデーションで弾かれる**
     *   （実測でこれを踏み、期待した文言が出なかった）。要件を満たす値を明示的に設定する。
     */
    private const CURRENT = 'password1';

    private function actor(bool $mustChange = false): User
    {
        return User::factory()->create([
            'email'                => 'user@example.com',
            'role'                 => UserRole::Staff->value,
            'must_change_password' => $mustChange,
            // `hashed` キャストが自動でハッシュ化する
            'password'             => self::CURRENT,
        ]);
    }

    /**
     * 画面が描画した変更フォームを分解して送り返す（Bug #47）。
     *
     * ⚠ 直接 `$this->put(...)` すると、`@method('PUT')` を消しても緑のまま通る。
     *   実際には 405 になって「更新する」が無反応になる。
     */
    private function submitChangeForm(User $user, array $values): TestResponse
    {
        $html = $this->actingAs($user)
            ->get(route('password.change'))
            ->assertOk()
            ->getContent();

        $form = $this->parseForm($html, 'action="' . route('password.update') . '"');

        $this->assertSame('PUT', $form['method'], '変更フォームが PUT で送られない（@method(\'PUT\') が無い）');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');

        return $this->actingAs($user)->post($form['action'], array_merge($form['fields'], $values));
    }

    // ============================================================
    // 画面の配線（Bug #47）
    // ============================================================

    /** 変更画面が 3 つの入力欄を描画していること（サーバだけ直しても入力手段が要る） */
    public function test_the_change_screen_renders_the_form(): void
    {
        $html = $this->actingAs($this->actor())
            ->get(route('password.change'))
            ->assertOk()
            ->getContent();

        foreach (['name="current_password"', 'name="password"', 'name="password_confirmation"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "変更画面に {$needle} が無い");
        }
    }

    // ============================================================
    // 拒否されるべき入力
    // ============================================================

    /**
     * 現在のパスワードが違えば拒否。
     *
     * ⚠ **リダイレクトだけを見ない** — パスワードが実際に変わっていないことまで見る。
     */
    public function test_a_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        $user = $this->actor();

        $this->submitChangeForm($user, [
            'current_password'      => 'not-my-password',
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]);

        $this->assertTrue(
            Hash::check(self::CURRENT, $user->fresh()->password),
            '現在のパスワードが違うのに変更されている'
        );

        $this->get(route('password.change'))
            ->assertSee('現在のパスワードが正しくありません。');
    }

    /** 現在と同じパスワードは使い回せない */
    public function test_reusing_the_current_password_is_rejected(): void
    {
        $user = $this->actor();

        $this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => self::CURRENT,
            'password_confirmation' => self::CURRENT,
        ]);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));

        $this->get(route('password.change'))
            ->assertSee('現在のパスワードと異なるパスワードを設定してください。');
    }

    /**
     * 複雑さ要件（`Password::min(8)->letters()->numbers()`）を 3 通りとも個別に見る。
     *
     * ⚠ まとめて 1 本にすると、`min(8)` だけ残して `letters()` を落とす変異が緑になる。
     */
    public static function weakPasswords(): array
    {
        return [
            '8 文字未満' => ['abc123', 'パスワードは8文字以上で入力してください。'],
            '英字なし'   => ['12345678', 'パスワードには英字を含めてください。'],
            '数字なし'   => ['abcdefgh', 'パスワードには数字を含めてください。'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weakPasswords')]
    public function test_a_weak_password_is_rejected(string $candidate, string $expected): void
    {
        $user = $this->actor();

        $this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => $candidate,
            'password_confirmation' => $candidate,
        ]);

        $this->assertTrue(
            Hash::check(self::CURRENT, $user->fresh()->password),
            "弱いパスワード（{$candidate}）が通ってしまった"
        );

        $this->get(route('password.change'))->assertSee($expected);
    }

    /** 確認用が一致しなければ拒否 */
    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $user = $this->actor();

        $this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword2',
        ]);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));

        $this->get(route('password.change'))->assertSee('新しいパスワードが一致しません。');
    }

    // ============================================================
    // 変更の成功
    // ============================================================

    /**
     * 成功したら ①実際に新パスワードでログインできる ②強制フラグが下りる ③その人のホーム（一般担当はテナントのダッシュボード）へ。
     *
     * ⚠ ハッシュの比較だけでなく**実ログインまで通す** — 保存経路（`hashed` キャスト）が
     *   壊れて二重ハッシュ等になっても、`Hash::check` だけでは気づけない場合がある。
     */
    public function test_a_successful_change_updates_the_password_and_clears_the_flag(): void
    {
        $user = $this->actor(mustChange: true);

        $this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertRedirect(route('dashboard.tenant'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpassword1', $fresh->password), 'パスワードが更新されていない');
        $this->assertFalse((bool) $fresh->must_change_password, '強制変更フラグが下りていない');

        // 実際に新パスワードでログインできる（旧パスワードでは入れない）
        $this->post('/logout');
        $this->post('/login', ['login_id' => $user->email, 'password' => self::CURRENT]);
        $this->assertGuest();   // ⚠ 第1引数は guard 名。メッセージを渡すと guard 未定義エラーになる

        $this->post('/login', ['login_id' => $user->email, 'password' => 'newpassword1']);
        $this->assertAuthenticatedAs($user->fresh());
    }

    // ============================================================
    // 変更のあとの行き先（F1）
    // ============================================================

    /** @return array<string, array{UserRole, string}> */
    public static function homeCases(): array
    {
        return [
            '経営層'   => [UserRole::Executive, 'dashboard.executive'],
            '管理者'   => [UserRole::Manager, 'dashboard.tenant'],
            '一般担当' => [UserRole::Staff, 'dashboard.tenant'],
            '決裁のみ' => [UserRole::ApprovalOnly, 'approvals.home'],
        ];
    }

    /** 変更のあとは、その人のホームへ**直接**戻る（F1。`/dashboard` を経由させない） */
    #[DataProvider('homeCases')]
    public function test_a_successful_change_goes_straight_to_the_users_home(UserRole $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role->value, 'must_change_password' => true, 'password' => self::CURRENT]);

        $this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertRedirect(route($home));
    }

    /**
     * 決裁のみ利用者は、決裁のホームで「パスワードを変更しました。」を見て、門番の警告は見ない（F1）。
     *
     * ⚠ 転送をたどって**着いた画面**で見る。行き先の URL だけでは、フラッシュが途中で消えるのを捕まえられない
     *   （旧実装は 302 → 302 → 200 で、着いた先に警告だけが出ていた）。
     * ⚠ `followingRedirects()` は**次の 1 回の要求**にしか効かない（`submitChangeForm()` は先に画面を GET するので、
     *   POST の転送をたどらないまま 302 が返った。実測）。POST の応答を `followRedirects()` に渡してたどる。
     */
    public function test_an_approval_only_user_sees_the_success_message_on_the_approval_home(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => true, 'password' => self::CURRENT]);

        $html = $this->followRedirects($this->submitChangeForm($user, [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('決裁の機能は準備中です。', $html, '決裁のホームに着いていない');
        $this->assertStringContainsString('パスワードを変更しました。', $html, '変更の完了が出ていない（転送の途中で消えた）');
        $this->assertStringNotContainsString(RestrictApprovalOnlyUsers::MESSAGE, $html, '門番に跳ね返されている');
    }

    /**
     * キャンセルの戻り先は、直前の画面が無いとき（＝検証エラーで差し戻された直後）もその人のホーム（F1 と同じ形）。
     *
     * ⚠ 旧実装は `route('dashboard')` へ戻し、決裁のみ利用者は門番に跳ね返されて警告を見た。
     * ⚠ リンクを要素ごと切り出して見る。`approvals.home` はパンくずとサイドバーにも出るので、ページ全体では false-pass する。
     */
    #[DataProvider('homeCases')]
    public function test_the_cancel_link_falls_back_to_the_users_home(UserRole $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role->value, 'must_change_password' => false]);

        // 差し戻された直後と同じ（直前の画面＝この画面自身）
        $html = $this->actingAs($user)->from(route('password.change'))->get(route('password.change'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a\s+href="([^"]*)"[^>]*>\s*キャンセル\s*<\/a>/u', $html, $m), 'キャンセルのリンクが見つからない');
        $this->assertSame(route($home), html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }

    /** 基幹の人も、着いた画面で「パスワードを変更しました。」を見る（以前は 2 回の転送で消えていた） */
    public function test_a_base_user_sees_the_success_message_on_their_dashboard(): void
    {
        $html = $this->followRedirects($this->submitChangeForm($this->actor(mustChange: true), [
            'current_password'      => self::CURRENT,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('パスワードを変更しました。', $html);
    }
}
