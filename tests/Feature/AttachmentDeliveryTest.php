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

    /** 保存先の名前。store() と同じ形（ランダムな 40 文字＋中身から推測した拡張子）で、名前に拡張子が無くても拡張子がある */
    private const STORED_PDF = 'Zr8w1Kq0hN5mJ2xY7cV4bT9aL3sD6fG1hP0kQ2uE.pdf';

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

    /** 指定の名前・MIME で添付を1件作る（ファイル実体も fake ディスクに置く。保存先の名前は省略すると表示名と同じ） */
    private function makeAttachment(string $fileName, string $mimeType, string $body = 'FAKEBYTES', ?string $storedName = null): Attachment
    {
        $path = 'attachments/contracts/1/' . ($storedName ?? $fileName);
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

    /**
     * T10: 日本語だけで拡張子の無い名前も開ける（以前は 500）。
     *
     * 古いブラウザ用の ASCII の代わりの名前（filename=）を Laravel に任せると、Str::ascii() が仮名・漢字・絵文字・
     * 全角の英数字を消して空になり、Symfony の makeDisposition() が例外を投げていた。
     * 代わりの名前は「attachment.保存先の拡張子」にし、実名は filename* が運ぶ。
     */
    public function test_name_without_ascii_letters_opens_inline(): void
    {
        foreach (['見積書', '😀', 'Ａ４図面'] as $name) {
            $attachment = $this->makeAttachment($name, 'application/pdf', 'FAKEBYTES', self::STORED_PDF);

            $response = $this->get(route('attachments.show', $attachment->id));

            $this->assertSame(200, $response->getStatusCode(), $name);
            $this->assertSame(
                "inline; filename=attachment.pdf; filename*=utf-8''" . rawurlencode($name),
                $response->headers->get('Content-Disposition'),
                $name
            );
        }
    }

    /** T11: 同じ名前をダウンロード（?download=1）しても 500 にならない（以前は 500） */
    public function test_name_without_ascii_letters_downloads(): void
    {
        foreach (['見積書', '😀', 'Ａ４図面'] as $name) {
            $attachment = $this->makeAttachment($name, 'application/pdf', 'FAKEBYTES', self::STORED_PDF);

            $response = $this->get(route('attachments.show', ['attachment' => $attachment->id, 'download' => 1]));

            $this->assertSame(200, $response->getStatusCode(), $name);
            $this->assertSame(
                "attachment; filename=attachment.pdf; filename*=utf-8''" . rawurlencode($name),
                $response->headers->get('Content-Disposition'),
                $name
            );
        }
    }

    /**
     * T12: 今まで開けていた名前（拡張子のある日本語・ASCII）の見出しは 1 文字も変えない。
     *
     * 期待値は直す前のコード（Laravel の fallbackName()）で実測した値。代わりの名前の前後の空白も残す
     * （「見積書 (1).pdf」を trim すると ` (1).pdf` が `(1).pdf` に変わってしまう）。
     */
    public function test_names_that_already_opened_keep_the_same_header(): void
    {
        $cases = [
            '見積書.pdf'            => "filename=.pdf; filename*=utf-8''%E8%A6%8B%E7%A9%8D%E6%9B%B8.pdf",
            '見積書 (1).pdf'        => "filename=\" (1).pdf\"; filename*=utf-8''%E8%A6%8B%E7%A9%8D%E6%9B%B8%20%281%29.pdf",
            'estimate.pdf'          => 'filename=estimate.pdf',
            'my report (final).pdf' => 'filename="my report (final).pdf"',
        ];

        foreach ($cases as $name => $params) {
            $attachment = $this->makeAttachment($name, 'application/pdf', 'FAKEBYTES', self::STORED_PDF);

            $inline   = $this->get(route('attachments.show', $attachment->id));
            $download = $this->get(route('attachments.show', ['attachment' => $attachment->id, 'download' => 1]));

            $this->assertSame('inline; ' . $params, $inline->headers->get('Content-Disposition'), $name);
            $this->assertSame('attachment; ' . $params, $download->headers->get('Content-Disposition'), $name);
        }
    }

    /** T13: 保存先に拡張子が無ければ、代わりの名前は「attachment」だけにする（「attachment.」にしない） */
    public function test_fallback_has_no_extension_when_the_stored_path_has_none(): void
    {
        $attachment = $this->makeAttachment('見積書', 'application/pdf');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertSame(
            "inline; filename=attachment; filename*=utf-8''" . rawurlencode('見積書'),
            $response->headers->get('Content-Disposition')
        );
    }

    /**
     * T14: 代わりの名前が空白だけになる名前（拡張子の無い「見積書 のコピー」）も attachment.拡張子 にする。
     *
     * 直す前も 200 で開けてはいたが、代わりの名前が `filename=" "` と空白だけで、古いブラウザでは名前にならなかった。
     */
    public function test_fallback_of_only_spaces_is_replaced(): void
    {
        $attachment = $this->makeAttachment('見積書 のコピー', 'application/pdf', 'FAKEBYTES', self::STORED_PDF);

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertSame(
            "inline; filename=attachment.pdf; filename*=utf-8''" . rawurlencode('見積書 のコピー'),
            $response->headers->get('Content-Disposition')
        );
    }

    /**
     * T15: 印字できない文字が残る名前も 500 にしない（以前は 500）。
     *
     * Str::ascii() は制御文字のうち \x10 と \x13 を消さずに残す（実測）ので、拡張子があっても代わりの名前に使えない。
     */
    public function test_fallback_with_a_control_character_is_replaced(): void
    {
        $name       = "見積\x10書.pdf";
        $attachment = $this->makeAttachment($name, 'application/pdf', 'FAKEBYTES', self::STORED_PDF);

        $response = $this->get(route('attachments.show', $attachment->id));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            "inline; filename=attachment.pdf; filename*=utf-8''" . rawurlencode($name),
            $response->headers->get('Content-Disposition')
        );
    }
}
