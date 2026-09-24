<?php

namespace Tests\Feature\Admin;

use App\Models\MsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの CSV 取込で、確認画面（POST の応答）から送って断られても、取込の画面（そのタブ）へ戻る（docs/RULES.md Bug #64）。
 *
 * テナントの取込（`TenantImportRejectionTest`）と同じ形。違いはタブの指定のクエリが `selected_tab` であること。
 * ⚠ 確認画面の URL は POST 専用（`/admin/mansion-import/{tab}`）で、`back()` と入力チェックの既定の戻り先は
 *   リファラー＝その URL へ GET で戻って 405 だった（2026-09-24 実測: ファイルなし・見出しだけ・確定時の DB エラーの 3 経路とも）。
 * ⚠ 行き先は `Location` を assertSame で見て、たどって理由が画面に出ることまで見る（セッションには触らない。Bug #49）。
 */
class MansionImportRejectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
    }

    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    /** そのタブのテンプレート CSV（BOM を落とす。送るときは SubmitsImportPreview が付け直す） */
    private function template(User $actor, string $tab): string
    {
        $csv = $this->actingAs($actor)->get(route("admin.mansion-import.template-{$tab}"))->assertOk()->getContent();

        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    }

    /** 断られたあと、取込の画面の $tab へ戻り、理由が画面に出ること */
    private function assertBackOnTheTab(\Illuminate\Testing\TestResponse $response, User $actor, string $tab, string $message): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            route('admin.mansion-import', ['selected_tab' => $tab]),
            $response->headers->get('Location'),
            "取込の画面（{$tab} タブ）へ戻っていない（確認画面の URL へ戻ると GET で 405）"
        );

        $screen = $this->actingAs($actor)->get($response->headers->get('Location'))->assertOk();

        $this->assertSame($tab, $screen->viewData('activeTab'), '戻った画面で開いているタブが違う');
        $this->assertStringContainsString($message, $screen->getContent(), '断られた理由が画面に出ていない');
    }

    /** @return array<string, array{0: bool, 1: string}> 見出しだけの CSV を送るか（false はファイルなし）／ 画面に出るはずの文言 */
    public static function reuploadFailureCases(): array
    {
        return [
            'ファイルを選ばずに送った' => [false, '<li>CSVファイルは必須です。</li>'],
            '見出しだけで行が無い'     => [true, 'CSVファイルにデータがありません。'],
        ];
    }

    /** 確認画面からアップロードし直して断られても、取込の画面のそのタブへ戻る（既定でない「部屋」タブで見る） */
    #[DataProvider('reuploadFailureCases')]
    public function test_a_failed_reupload_from_the_preview_goes_back_to_the_same_tab(bool $headerOnly, string $message): void
    {
        $actor   = $this->executive();
        $preview = url($this->importBasePath() . '/room');   // 部屋タブの確認画面の URL（POST 専用）

        $fields = [];
        if ($headerOnly) {
            $header = strtok($this->template($actor, 'room'), "\n");
            $fields = ['csv_file' => UploadedFile::fake()->createWithContent('rooms.csv', "\xEF\xBB\xBF{$header}\n")];
        }

        $response = $this->actingAs($actor)->from($preview)->post($preview, $fields);

        $this->assertBackOnTheTab($response, $actor, 'room', $message);
    }

    /**
     * 「インポート実行」を押して書き込みに失敗しても、取込の画面のそのタブへ戻り、何も書かれていない。
     * 書き込みの失敗は `MsProperty::creating()` で例外を投げて起こす。
     */
    public function test_a_failed_import_after_confirming_goes_back_to_the_same_tab(): void
    {
        $actor   = $this->executive();
        $preview = $this->preview('property', $this->template($actor, 'property'));
        $preview->assertOk();
        $form = $this->parseImportForm($preview->getContent(), 'property');

        MsProperty::creating(function (): void {
            throw new \RuntimeException('テスト用の失敗');
        });

        $response = $this->actingAs($actor)
            ->from(url($this->importBasePath() . '/property'))   // 確定のフォームが載っている確認画面の URL
            ->post($form['action'], $form['fields']);

        $this->assertBackOnTheTab($response, $actor, 'property', 'インポートに失敗しました: テスト用の失敗');
        $this->assertSame(0, MsProperty::count(), '失敗した取込が巻き戻っていない');
    }
}
