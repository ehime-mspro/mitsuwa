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
 * ⚠ 確認画面の URL は POST 専用（`/admin/tenant-import/{tab}`）。ブラウザは確認画面に載ったフォーム（ほかのタブの
 *   アップロード・インポート実行）から送るとき、リファラーにその URL を付ける。`back()` と入力チェックの既定の戻り先はリファラーを
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
     * 確認画面から、別のタブでアップロードし直して断られても、取込の画面の**送ったタブ**へ戻る。
     *
     * ⚠ 確認画面では開いているタブの枠だけが確認に置き換わり、そのタブのアップロードのフォームは無い（ほかのタブのフォームは
     *   残る）。実際に踏むのは「確認画面から別のタブで送り直す」経路なので、物件タブの確認画面を描画し、そこに載っている
     *   区画タブのフォームを分解して送る（Bug #47 の往復）。リファラーは物件タブの確認画面の URL（POST 専用）。
     * ⚠ 行き先は送ったタブ（区画）で見る。既定の物件タブでも確認画面のタブでもないので、タブを既定やリファラーから決める
     *   誤りも落ちる（同じタブの URL から同じタブへ送る形では、この 2 つを区別できなかった。2026-09-24 のレビュー）。
     */
    #[DataProvider('reuploadFailureCases')]
    public function test_a_failed_reupload_from_another_tab_on_the_preview_goes_back_to_that_tab(bool $headerOnly, string $message): void
    {
        $actor   = $this->executive();
        $preview = $this->preview('property', $this->template($actor, 'property'));
        $preview->assertOk();
        $form = $this->parseForm($preview->getContent(), 'action="' . url($this->importBasePath() . '/unit') . '"');

        $fields = $form['fields'];
        if ($headerOnly) {
            $header = strtok($this->template($actor, 'unit'), "\n");
            $fields['csv_file'] = UploadedFile::fake()->createWithContent('units.csv', "\xEF\xBB\xBF{$header}\n");
        }

        $response = $this->actingAs($actor)
            ->from(url($this->importBasePath() . '/property'))   // 物件タブの確認画面の URL（POST 専用）
            ->post($form['action'], $fields);

        $this->assertBackOnTheTab($response, $actor, 'unit', $message);
    }

    /**
     * 「インポート実行」を押して書き込みに失敗しても、取込の画面のそのタブへ戻り、何も書かれていない。
     *
     * 描画された確定のフォームを分解してそのまま送る（Bug #47 の往復）。書き込みの失敗は、`Property::creating()` で
     * 例外を投げて起こす（本番では一意制約の違反・接続の切断など。確定の `catch` はどれも同じ戻り方をする）。
     *
     * ⚠ 失敗させるのは **2 行目**。テンプレートの見本は 1 行だけなので、そのまま 1 行目で失敗させると最初の INSERT より前に
     *   落ち、巻き戻さなくても 0 件になる（`DB::rollBack()` を消す変異が緑のままだった。2026-09-24 のレビュー）。
     */
    public function test_a_failed_import_after_confirming_goes_back_to_the_same_tab(): void
    {
        $actor   = $this->executive();
        $csv     = $this->template($actor, 'property');
        [, $sample] = explode("\n", rtrim($csv, "\n"));
        $csv    .= str_replace('"サンプルビル"', '"サンプルビル2"', $sample) . "\n";   // 名前だけ変えた 2 行目

        $preview = $this->preview('property', $csv);
        $preview->assertOk();
        $form = $this->parseImportForm($preview->getContent(), 'property');

        $created = 0;
        Property::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new \RuntimeException('テスト用の失敗');
            }
        });

        $response = $this->actingAs($actor)
            ->from(url($this->importBasePath() . '/property'))   // 確定のフォームが載っている確認画面の URL
            ->post($form['action'], $form['fields']);

        $this->assertBackOnTheTab($response, $actor, 'property', 'インポートに失敗しました: テスト用の失敗');
        $this->assertSame(2, $created, '2 行目の作成まで進んでいない（1 行目が入ってから失敗する形になっていない）');
        $this->assertSame(0, Property::count(), '失敗した取込が巻き戻っていない（1 行目が残っている）');
    }
}
