<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsOutElsewhere;
use Tests\TestCase;

/**
 * 基幹の保存（GET 以外）の Ajax がログインの切れた 401 になったときに、日本語の理由を返す（Bug #137 の追記・2026-11-18）。
 *
 * 直す前は、ほかの画面・端末でパスワードを変えた・管理者が再発行した・利用者が消された、のあとに最初に保存すると、
 * Laravel の既定の英語「Unauthenticated.」が返り、画面には「保存に失敗しました: Unauthenticated.」や
 * 「ステータスの更新に失敗しました（401）」と出ていた（決裁の URL だけが日本語だった）。
 *
 * ⚠ 本物の道で起こす: このログインで 1 回画面を開いてパスワードの控えを作り、パスワードを変えてから送る
 *   （AuthenticateSession がログインを切り、認証の確かめが 401 にする）。例外を投げる見本のルートでは、
 *   ミドルウェアの並びが変わったときに見えない。
 * ⚠ GET の JSON の 401 は英語のまま（_partials/_ajax_get_failure が英語の 401 で「ログインが切れた」を見分け、
 *   「入力中の内容があれば控えて」の案内を出している）。
 */
class SignedOutSaveResponseTest extends TestCase
{
    use RefreshDatabase;
    use SignsOutElsewhere;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->match(['get', 'post', 'put', 'patch', 'delete'], '/_probe_save', fn () => response()->json(['ok' => true]));

        $this->user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** ほかの画面でパスワードを変えたあとの保存（POST・PUT・PATCH・DELETE）は、日本語の理由の 401。ログインは切れる */
    public function test_a_save_after_the_password_changed_elsewhere_gets_the_reason_in_japanese(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->actingAs($this->user)->getJson('/_probe_save')->assertOk();   // パスワードの控えを作る
            $this->changePasswordElsewhere();

            $this->json($method, '/_probe_save')
                ->assertUnauthorized()
                ->assertExactJson(['message' => self::SIGNED_OUT_JSON_MESSAGE]);
            $this->assertGuest();
        }
    }

    /** ログインの無い保存（利用者が消された・ログアウトした）も同じ文 */
    public function test_a_save_without_a_login_gets_the_same_reason(): void
    {
        $this->postJson('/_probe_save')
            ->assertUnauthorized()
            ->assertExactJson(['message' => self::SIGNED_OUT_JSON_MESSAGE]);
    }

    /** 読み込み（GET）の JSON の 401 は英語のまま（GET の部品が見分けに使う） */
    public function test_a_read_keeps_the_english_401(): void
    {
        $this->actingAs($this->user)->getJson('/_probe_save')->assertOk();
        $this->changePasswordElsewhere();

        $this->getJson('/_probe_save')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /** ふつうのフォームの送信（JSON を求めない）は今どおりログイン画面へ */
    public function test_a_form_save_still_goes_to_the_login_page(): void
    {
        $this->actingAs($this->user)->get('/_probe_save')->assertOk();
        $this->changePasswordElsewhere();

        $this->post('/_probe_save')->assertRedirect(route('login'));
    }

    /** 無効にされた人は今どおり「無効」の理由（EnsureUserIsActive の日本語の 401） */
    public function test_a_deactivated_user_keeps_the_inactive_reason(): void
    {
        $this->actingAs($this->user)->getJson('/_probe_save')->assertOk();
        $this->deactivateSignedInUser();

        $this->postJson('/_probe_save')
            ->assertUnauthorized()
            ->assertExactJson(['message' => self::INACTIVE_JSON_MESSAGE]);
    }
}
