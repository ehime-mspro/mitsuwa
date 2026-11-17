<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private const PAGE_NEXT = 'ブラウザの「戻る」で前の画面に戻ると、入力した内容が残っていることがあります。控えてから画面を開き直して、もう一度やり直してください。';

    /**
     * ふつうのフォームの送信（JSON を求めない）は、日本語の画面（resources/views/errors/419.blade.php）を番号 419 のまま返す。
     * ⚠ 直す前は Laravel の英語の画面「419 Page Expired」だった
     */
    private function assertExpiredPage(TestResponse $response): void
    {
        $response->assertStatus(419);
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('Page Expired', $html);
        // タブ名でログイン画面と区別できる（直す前はアプリ名だけで、ログイン画面と同じだった。Bug #137 の後回し）
        $this->assertStringContainsString('<title>' . e(self::PAGE_TITLE . ' - ' . config('app.name', '経営管理システム')) . '</title>', $html, 'タブ名が「画面の有効期限が切れました - アプリ名」でない');
        $this->assertMatchesRegularExpression('/<h1\b[^>]*>\s*' . preg_quote(self::PAGE_TITLE, '/') . '\s*<\/h1>/u', $html, '見出しが無い');
        $this->assertMatchesRegularExpression('/<p\b[^>]*>\s*' . preg_quote(e(self::PAGE_REASON), '/') . '\s*<\/p>/u', $html, '理由の文が無い');
        $this->assertMatchesRegularExpression('/<p\b[^>]*>\s*' . preg_quote(e(self::PAGE_NEXT), '/') . '\s*<\/p>/u', $html, '次にすることの文が無い');
        $this->assertMatchesRegularExpression('/<button\b[^>]*\bonclick="history\.back\(\)"[^>]*>\s*前の画面に戻る\s*<\/button>/u', $html, '「前の画面に戻る」が無い');
        $this->assertMatchesRegularExpression('/<a\b[^>]*\bhref="' . preg_quote(url('/'), '/') . '"[^>]*>\s*トップページへ\s*<\/a>/u', $html, '「トップページへ」が無い');
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

        $this->assertExpiredPage($response);
    }

    /** ログイン画面を開いたまま時間がたってからログインしても、同じ日本語の画面（ログインしていない人にも出る） */
    public function test_the_login_form_with_an_expired_page_shows_the_same_page(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>' . e(config('app.name', '経営管理システム')) . '</title>', $html, 'ログイン画面のタブ名がアプリ名だけでなくなった');
        $form = $this->parseForm($html, 'action="' . route('login') . '"');

        $this->enforceCsrfToken();
        $response = $this->call('POST', $form['action'], array_merge($form['fields'], ['login_id' => 'A001', 'password' => 'x', '_token' => 'token-of-the-old-session']));

        $this->assertExpiredPage($response);
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
