<?php

namespace Tests\Feature;

use App\Enums\CustomOrderFileCategory;
use App\Enums\HousingFileCategory;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\HsCustomOrder;
use App\Models\HsCustomOrderFile;
use App\Models\HsProperty;
use App\Models\HsPropertyFile;
use App\Models\ReProject;
use App\Models\ReProjectDrawing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 日本語だけで拡張子の無い名前（「見積書」）のファイルを、AttachmentDelivery::make() を呼ぶ 4 か所
 * （添付・分譲地の図面・建売のファイル・注文住宅のファイル）の画面と同じ入口から保存し、そのまま開けること。
 *
 * どの入口も保存で名前の拡張子を見ない（mimes は中身から推測した拡張子を見る）ので、この名前を受け付ける。
 * 以前は開く・ダウンロードのどちらも 500 だった（代わりの名前の作り方は AttachmentDelivery の docblock）。
 *
 * ⚠ UploadedFile::fake() を使わない。偽のファイルは MIME を中身でなく**名前**から決める
 *   （Illuminate\Http\Testing\File::getMimeType()）ので、拡張子の無い名前は application/octet-stream
 *   （推測する拡張子は bin）になり mimes に断られる（422。実測）。本番とは逆の結果で「保存で断られるので起きない」と
 *   誤って読める。本物の UploadedFile に本物の PDF の中身を渡し、本番と同じく中身から判定させる。
 * ⚠ ブラウザは拡張子の無いファイルの種類を application/octet-stream と送る（ここでも送らない＝同じ値になる）。
 *   図面・建売・注文住宅はその値（getClientMimeType()）を保存するので「開く」でもダウンロードになる。
 *   ここでは開く・ダウンロードの別は見ず、200 で開けて名前が正しく運ばれることを見る。
 */
class AttachmentNameRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const NAME = '見積書';

    /** finfo が application/pdf と判定する最小の中身 */
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRealEstateSchema();
        Storage::fake('public');
        $this->actingAs(User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]));
    }

    /** 本物の一時ファイルに PDF の中身を書き、ブラウザから届いたのと同じ形で渡す（種類は送らない） */
    private function pdfNamed(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, self::PDF);
        $this->beforeApplicationDestroyed(fn () => unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_a_name_without_ascii_letters_is_saved_and_opens_from_every_entrance(): void
    {
        $project  = ReProject::create(['project_code' => 'PRJ-001', 'project_name' => '余戸南 分譲地', 'status' => 'selling', 'address' => '愛媛県松山市2-2-2', 'created_by' => 1]);
        $property = HsProperty::create(['property_code' => 'HS-001', 'property_name' => '余戸南 3号地', 'status' => 'construction', 'address' => '愛媛県松山市3-3-3', 'created_by' => 1]);
        $order    = HsCustomOrder::create(['order_code' => 'CO-001', 'order_name' => '松山市 T様邸', 'status' => 'construction', 'customer_name' => 'T様', 'address' => '愛媛県松山市4-4-4', 'created_by' => 1]);

        $this->postJson(route('attachments.store', ['type' => 'projects', 'id' => $project->id]), ['files' => [$this->pdfNamed(self::NAME)]])->assertOk();
        $this->postJson(route('realestate.projects.drawings.store', $project), ['file' => $this->pdfNamed(self::NAME)])->assertOk();
        $this->postJson(route('housing.properties.files.store', $property), ['file' => $this->pdfNamed(self::NAME), 'category' => HousingFileCategory::Other->value])->assertOk();
        $this->postJson(route('housing.custom-orders.files.store', $order), ['file' => $this->pdfNamed(self::NAME), 'category' => CustomOrderFileCategory::Estimate->value])->assertOk();

        // 保存は名前をそのまま持つ（sole() が 1 件ずつ在ることも確かめる）
        $entrances = [
            '添付'     => ['attachments.show', ['attachment' => Attachment::where('file_name', self::NAME)->sole()]],
            '図面'     => ['realestate.projects.drawings.show', ['project' => $project, 'drawing' => ReProjectDrawing::where('file_name', self::NAME)->sole()]],
            '建売'     => ['housing.properties.files.show', ['property' => $property, 'file' => HsPropertyFile::where('file_name', self::NAME)->sole()]],
            '注文住宅' => ['housing.custom-orders.files.show', ['customOrder' => $order, 'file' => HsCustomOrderFile::where('file_name', self::NAME)->sole()]],
        ];

        foreach ($entrances as $label => [$routeName, $params]) {
            foreach (['開く' => [], 'ダウンロード' => ['download' => 1]] as $mode => $query) {
                $response = $this->get(route($routeName, $params + $query));

                $this->assertSame(200, $response->getStatusCode(), "{$label}・{$mode}");
                $disposition = (string) $response->headers->get('Content-Disposition');
                // 保存先は store() が「ランダムな名前＋中身から推測した拡張子（pdf）」で付ける
                $this->assertStringContainsString("; filename=attachment.pdf; filename*=utf-8''" . rawurlencode(self::NAME), $disposition, "{$label}・{$mode}");
                if ($query !== []) {
                    $this->assertStringStartsWith('attachment;', $disposition, "{$label}・{$mode}");
                }
            }
        }
    }
}
