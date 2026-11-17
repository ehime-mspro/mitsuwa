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
