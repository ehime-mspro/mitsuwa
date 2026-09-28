<?php

namespace Tests\Feature\Approval\Phase2;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁の URL の JSON の 419（画面を開いたまま時間がたち、セッションが切れた）と 401（ログアウトした）を日本語にする
 * （bootstrap/app.php。Task 19 の B5）。申請書の添付の欄は、断られた理由としてこの文をそのまま出す。
 *
 * ⚠ テストでは CSRF の確かめ（ValidateCsrfToken）が素通りするので、419 は例外そのものを投げる見本のルートで測る
 *   （送信の上限の 413 と同じ見方。RequestAttachmentTest）
 */
class ApprovalJsonExpiredSessionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const EXPIRED = '画面を開いてから時間がたったため、送れませんでした。画面を開き直して、もう一度やり直してください。';

    private const SIGNED_OUT = 'ログインが切れました。ログインし直してから、もう一度やり直してください。';

    public function test_an_expired_page_gets_a_japanese_419_on_approval_urls(): void
    {
        Route::post('/approvals/_probe_expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->postJson('/approvals/_probe_expired')
            ->assertStatus(419)
            ->assertExactJson(['message' => self::EXPIRED]);
    }

    /** 候補の検索（GET）・添付を足す（POST）・外す（DELETE）。ログインの確かめはルートの結合より前なので、無い ID でも 401 */
    public function test_a_signed_out_user_gets_a_japanese_401_on_approval_urls(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->getJson(route('approvals.numbers.search', ['q' => 'R8']))->assertUnauthorized()->assertExactJson(['message' => self::SIGNED_OUT]);
        $this->postJson(route('approvals.requests.attachments.store', $draft))->assertUnauthorized()->assertExactJson(['message' => self::SIGNED_OUT]);
        $this->deleteJson('/approvals/attachments/999999')->assertUnauthorized()->assertExactJson(['message' => self::SIGNED_OUT]);
    }

    /** 基幹の URL と、JSON を求めない要求（画面の送信・画面を開く）は今までどおり */
    public function test_other_urls_and_pages_keep_the_default(): void
    {
        Route::post('/_probe_expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
        Route::get('/_probe_signed_out', fn () => throw new AuthenticationException());
        Route::post('/approvals/_probe_expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->postJson('/_probe_expired')->assertStatus(419)->assertJsonPath('message', 'CSRF token mismatch.');
        $this->getJson('/_probe_signed_out')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');

        // ⚠ JSON の中の日本語は \uXXXX になるので、assertDontSee() では見分けられない。種類（Content-Type）で見る
        $page = $this->post('/approvals/_probe_expired')->assertStatus(419);
        $this->assertStringStartsWith('text/html', (string) $page->headers->get('Content-Type'));
        $this->get(route('approvals.requests.index'))->assertRedirect(route('login'));
    }

    /** 決裁の JSON でも、419 でない HttpException（403 など）は番号も文も今までどおり（Task 19 の B5 の点検。419 の文は番号を見て出す） */
    public function test_other_http_errors_on_approval_urls_keep_their_status(): void
    {
        Route::post('/approvals/_probe_forbidden', fn () => abort(403, '見本の断り'));
        Route::post('/approvals/_probe_conflict', fn () => abort(409, '見本の食い違い'));

        $this->postJson('/approvals/_probe_forbidden')->assertForbidden()->assertJsonPath('message', '見本の断り');
        $this->postJson('/approvals/_probe_conflict')->assertStatus(409)->assertJsonPath('message', '見本の食い違い');
    }
}
