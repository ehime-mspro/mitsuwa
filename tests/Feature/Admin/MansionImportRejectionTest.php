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

    /**
     * 確認画面から、別のタブでアップロードし直して断られても、取込の画面の**送ったタブ**へ戻る。
     *
     * ⚠ 確認画面では開いているタブの枠だけが確認に置き換わり、ほかのタブのアップロードのフォームが残る（テナントと同じ）。
     *   物件タブの確認画面を描画し、そこに載っている部屋タブのフォームを分解して送る（Bug #47 の往復）。
     * ⚠ 行き先は送ったタブ（部屋）で見る。既定の物件タブでも確認画面のタブでもないので、タブを既定やリファラーから決める
     *   誤りも落ちる。
     */
    #[DataProvider('reuploadFailureCases')]
    public function test_a_failed_reupload_from_another_tab_on_the_preview_goes_back_to_that_tab(bool $headerOnly, string $message): void
    {
        $actor   = $this->executive();
        $preview = $this->preview('property', $this->template($actor, 'property'));
        $preview->assertOk();
        $form = $this->parseForm($preview->getContent(), 'action="' . url($this->importBasePath() . '/room') . '"');

        $fields = $form['fields'];
        if ($headerOnly) {
            $header = strtok($this->template($actor, 'room'), "\n");
            $fields['csv_file'] = UploadedFile::fake()->createWithContent('rooms.csv', "\xEF\xBB\xBF{$header}\n");
        }

        $response = $this->actingAs($actor)
            ->from(url($this->importBasePath() . '/property'))   // 物件タブの確認画面の URL（POST 専用）
            ->post($form['action'], $fields);

        $this->assertBackOnTheTab($response, $actor, 'room', $message);
    }

    /**
     * 「インポート実行」を押して書き込みに失敗しても、取込の画面のそのタブへ戻り、何も書かれていない。
     * 書き込みの失敗は `MsProperty::creating()` で例外を投げて起こす。
     *
     * ⚠ 失敗させるのは **2 行目**（テンプレートの見本は 1 行だけ。1 行目で失敗させると最初の INSERT より前に落ち、
     *   巻き戻さなくても 0 件になる。テナントと同じ）。
     */
    public function test_a_failed_import_after_confirming_goes_back_to_the_same_tab(): void
    {
        $actor   = $this->executive();
        $csv     = $this->template($actor, 'property');
        [, $sample] = explode("\n", rtrim($csv, "\n"));
        $csv    .= str_replace('"サンプルマンション"', '"サンプルマンション2"', $sample) . "\n";   // 名前だけ変えた 2 行目

        $preview = $this->preview('property', $csv);
        $preview->assertOk();
        $form = $this->parseImportForm($preview->getContent(), 'property');

        $created = 0;
        MsProperty::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new \RuntimeException('テスト用の失敗');
            }
        });

        $response = $this->actingAs($actor)
            ->from(url($this->importBasePath() . '/property'))   // 確定のフォームが載っている確認画面の URL
            ->post($form['action'], $form['fields']);

        $this->assertBackOnTheTab($response, $actor, 'property', 'インポートに失敗しました: テスト用の失敗');
        $this->assertSame(2, $created, '2 行目の作成まで進んでいない（1 行目が入ってから失敗する形になっていない）');
        $this->assertSame(0, MsProperty::count(), '失敗した取込が巻き戻っていない（1 行目が残っている）');
    }
}
