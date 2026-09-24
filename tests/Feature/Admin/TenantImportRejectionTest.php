<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの CSV 取込で、確認画面（POST の応答）から送って断られても、取込の画面（そのタブ）へ戻る（docs/RULES.md Bug #64）。
 *
 * ⚠ 確認画面の URL は POST 専用（`/admin/tenant-import/{tab}`）。ブラウザは確認画面に載ったフォーム（アップロードし直し・
 *   インポート実行）から送るとき、リファラーにその URL を付ける。`back()` と入力チェックの既定の戻り先はリファラーを
 *   優先するので、そこへ GET で戻って 405 だった（2026-09-24 実測: ファイルなし・見出しだけ・確定時の DB エラーの 3 経路とも）。
 * ⚠ テストの HTTP クライアントはリファラーを送らないので、`from(確認画面の URL)` を付けないと原理的に見えない。
 * ⚠ 行き先は `Location` を assertSame で見る（assertRedirect() は、検証エラーを持つ応答で外れると失敗文を組み立てる途中で
 *   fatal になり理由が読めない。決裁の取込のテストで実測）。そのうえでたどって、理由が画面に出ることまで見る
 *   （セッションには触らない。Bug #49）。
 */
class TenantImportRejectionTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    /** そのタブのテンプレート CSV（BOM を落とす。送るときは SubmitsImportPreview が付け直す） */
    private function template(User $actor, string $tab): string
    {
        $csv = $this->actingAs($actor)->get(route("admin.tenant-import.template.{$tab}"))->assertOk()->getContent();

        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    }

    /** 断られたあと、取込の画面の $tab へ戻り、理由が画面に出ること */
    private function assertBackOnTheTab(\Illuminate\Testing\TestResponse $response, User $actor, string $tab, string $message): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            route('admin.tenant-import', ['tab' => $tab]),
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

    /**
     * 確認画面からアップロードし直して断られても、取込の画面のそのタブへ戻る。
     *
     * ⚠ 既定の「物件」タブでなく「区画」タブで見る（戻り先にタブの指定が要ることも同時に見るため）。
     */
    #[DataProvider('reuploadFailureCases')]
    public function test_a_failed_reupload_from_the_preview_goes_back_to_the_same_tab(bool $headerOnly, string $message): void
    {
        $actor   = $this->executive();
        $preview = url($this->importBasePath() . '/unit');   // 区画タブの確認画面の URL（POST 専用）

        $fields = [];
        if ($headerOnly) {
            $header = strtok($this->template($actor, 'unit'), "\n");
            $fields = ['csv_file' => UploadedFile::fake()->createWithContent('units.csv', "\xEF\xBB\xBF{$header}\n")];
        }

        $response = $this->actingAs($actor)->from($preview)->post($preview, $fields);

        $this->assertBackOnTheTab($response, $actor, 'unit', $message);
    }

    /**
     * 「インポート実行」を押して書き込みに失敗しても、取込の画面のそのタブへ戻り、何も書かれていない。
     *
     * 描画された確定のフォームを分解してそのまま送る（Bug #47 の往復）。書き込みの失敗は、`Property::creating()` で
     * 例外を投げて起こす（本番では一意制約の違反・接続の切断など。確定の `catch` はどれも同じ戻り方をする）。
     */
    public function test_a_failed_import_after_confirming_goes_back_to_the_same_tab(): void
    {
        $actor   = $this->executive();
        $preview = $this->preview('property', $this->template($actor, 'property'));
        $preview->assertOk();
        $form = $this->parseImportForm($preview->getContent(), 'property');

        Property::creating(function (): void {
            throw new \RuntimeException('テスト用の失敗');
        });

        $response = $this->actingAs($actor)
            ->from(url($this->importBasePath() . '/property'))   // 確定のフォームが載っている確認画面の URL
            ->post($form['action'], $form['fields']);

        $this->assertBackOnTheTab($response, $actor, 'property', 'インポートに失敗しました: テスト用の失敗');
        $this->assertSame(0, Property::count(), '失敗した取込が巻き戻っていない');
    }
}
