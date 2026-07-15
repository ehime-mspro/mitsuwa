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
use Tests\TestCase;

/**
 * 添付ファイルの配信方法（inline 表示 / 強制ダウンロード）のテスト。
 *
 * attachable_type には Contract::class（tenant 部署）を使う。
 * AttachmentController::show() は親モデルをロードせず attachable_type の文字列しか
 * 読まないため、contracts テーブルの行が無くてもルート経由で検証できる。
 *
 * 検証するのは「配信方法（Content-Disposition）」であって Content-Type ではない。
 * 強制DL 時の Content-Type はファイル実体から判定されるため、
 * 許可リスト外の MIME に対して Content-Type をアサートしてはいけない。
 */
class AttachmentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DepartmentSeeder::class);

        $this->staff = User::factory()->create(['role' => UserRole::Staff->value]);
        $this->staff->departments()->attach(Department::where('code', 'tenant')->value('id'));
        $this->actingAs($this->staff);
    }

    /** 指定の名前・MIME で添付を1件作る（ファイル実体も fake ディスクに置く） */
    private function makeAttachment(string $fileName, string $mimeType, string $body = 'FAKEBYTES'): Attachment
    {
        $path = 'attachments/contracts/1/' . $fileName;
        Storage::disk('public')->put($path, $body);

        return Attachment::create([
            'attachable_type' => Contract::class,
            'attachable_id'   => 1,
            'file_name'       => $fileName,
            'file_path'       => $path,
            'file_size'       => strlen($body),
            'mime_type'       => $mimeType,
            'uploaded_by'     => $this->staff->id,
        ]);
    }

    /** T1: 画像（jpeg）は inline 配信され、Content-Type は許可リストの値・nosniff 付き */
    public function test_jpeg_is_served_inline(): void
    {
        $attachment = $this->makeAttachment('invoice.jpg', 'image/jpeg');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** T2: PDF は inline 配信される */
    public function test_pdf_is_served_inline(): void
    {
        $attachment = $this->makeAttachment('spec.pdf', 'application/pdf');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /** T3: xlsx は許可リスト外なので強制ダウンロード */
    public function test_xlsx_is_force_downloaded(): void
    {
        $attachment = $this->makeAttachment(
            'costs.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /** T4: ?download=1 なら inline 対象の画像でも強制ダウンロード（⬇ ボタンの挙動） */
    public function test_download_query_forces_attachment_for_image(): void
    {
        $attachment = $this->makeAttachment('invoice.jpg', 'image/jpeg');

        $response = $this->get(route('attachments.show', ['attachment' => $attachment->id, 'download' => 1]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * T5: DB に image/svg+xml が入っていても inline 配信しない（D4 の許可リスト検証）。
     *
     * Content-Type はアサートしない。download() はファイル実体から MIME を判定するため
     * svg 実体を置けば image/svg+xml がヘッダに出るのが正常。防御が成立している根拠は
     * Content-Disposition: attachment + nosniff であって Content-Type の書き換えではない。
     */
    public function test_svg_is_never_served_inline(): void
    {
        $attachment = $this->makeAttachment(
            'evil.svg',
            'image/svg+xml',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** T6: csv は Excel で開く運用なので強制ダウンロード（D3） */
    public function test_csv_is_force_downloaded(): void
    {
        $attachment = $this->makeAttachment('members.csv', 'text/csv');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /** T7: 日本語ファイル名でも inline 配信され、RFC 5987 の filename* で実名が保たれる */
    public function test_japanese_file_name_is_served_inline_with_rfc5987_name(): void
    {
        $attachment = $this->makeAttachment('見積書サンプル.jpg', 'image/jpeg');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        // Str::ascii() が日本語を落とすため filename= は '.jpg' だけになり、実名は filename* が運ぶ
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('見積書サンプル.jpg'), $disposition);
    }

    /**
     * T8: Content-Type は DB の mime_type ではなく許可リストの正規化値が使われる（D4 の核心）。
     *
     * image/pjpeg（古いブラウザが送る jpeg の別名）を DB に入れても、
     * 配信されるのは正規化後の image/jpeg でなければならない。
     * このテストが無いと「DB 値をそのまま渡す」実装に退行しても T1 は緑のまま気づけない。
     */
    public function test_content_type_is_normalized_from_allowlist_not_db_value(): void
    {
        $attachment = $this->makeAttachment('legacy.jpg', 'image/pjpeg');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    /**
     * T9: 大文字の MIME でも正規化されて inline 配信される（strtolower の防護）。
     *
     * このテストが無いと strtolower() を消しても全テストが緑のままになる（変異注入で確認済み）。
     */
    public function test_uppercase_mime_is_normalized_and_served_inline(): void
    {
        $attachment = $this->makeAttachment('scan.jpg', 'IMAGE/JPEG');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }
}
