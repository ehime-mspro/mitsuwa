<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * CSV 取込の「プレビュー → 描画された『インポート実行』フォームをそのまま確定」の往復（Bug #47 / Bug #54 ②）。
 *
 * ⚠ 2026-09-14 に `tests/Feature/Admin/MansionImportTest.php` から**そのまま**切り出した
 *   （テナントの区画の取込でも同じ往復が要るため）。取込の URL だけを importBasePath() で受ける。
 *   複製すると drift するので、中身を変えるときは両方の利用者で測り直すこと。
 *
 * 使う側は `ParsesForms`（htmlAttr() を借りる）と一緒に use し、importBasePath()（取込画面の URL の前半。
 * 例: '/admin/mansion-import'）を用意する。取込を操作する経営層のユーザーは executive() が作る。
 */
trait SubmitsImportPreview
{
    abstract private function importBasePath(): string;

    /**
     * 確定フォームの送信ボタン。
     *
     * ⚠ **素の「インポート実行」で探してはいけない。** 同じ語が `<form>` の**外**にある
     *   セクション見出しにも出るため（実測: 1 ページに 2 箇所）、そこから `<form` を遡ると
     *   レイアウト先頭の**ログアウトフォーム**を掴む。必ず `<button>` ごと探す。
     */
    private const IMPORT_BUTTON_PATTERN = '/<button\b[^>]*>\s*インポート実行/u';

    /** 全行がエラーのときにプレビューが出す文言（フォームの代わりに描画される）。 */
    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** プレビューを描画させる（確定はしない）。 */
    private function preview(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())->post($this->importBasePath() . "/{$tab}", [
            'csv_file' => UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF" . $csv),
        ]);
    }

    /**
     * プレビュー → 確定の往復。
     *
     * **画面が描画した「インポート実行」フォームを分解し、その `action` へ、その hidden を
     * そのまま送り返す**（Bug #47）。送信先も送信内容も自前で組み立てないので、
     * hidden の名前が変わっても・`action` が別タブへ向いても・`@csrf` が消えても赤くなる。
     *
     * ⚠ 以前は `csv_data` だけを抜いて残りを手で組んでいた。実測で
     *   `name="confirmed"` を `name="confirmed_x"` に変えても**緑のまま**通り、
     *   本番では「インポート実行」を押すたびにプレビューが再表示されるだけで
     *   1 件も登録されない（エラーも出ない）状態を素通りさせていた。
     */
    private function confirm(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $form = $this->parseImportForm($preview->getContent(), $tab);

        return $this->actingAs($this->executive())->post($form['action'], $form['fields']);
    }

    /**
     * プレビュー画面の「インポート実行」フォームを、ブラウザと同じように分解する。
     *
     * @return array{action: string, fields: array<string, string>}
     */
    private function parseImportForm(string $html, string $tab): array
    {
        $found = preg_match(self::IMPORT_BUTTON_PATTERN, $html, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $found, "プレビュー画面に「インポート実行」ボタンが無い（tab={$tab}）");

        $buttonPos = $m[0][1];

        $open = strrpos(substr($html, 0, $buttonPos), '<form');
        $this->assertNotFalse($open, "「インポート実行」ボタンを囲む <form> の開始タグが無い（tab={$tab}）");

        $close = strpos($html, '</form>', $open);
        $this->assertNotFalse($close, "「インポート実行」ボタンを囲む <form> が閉じていない（tab={$tab}）");
        // 手前の別フォーム（レイアウトのログアウト等）を掴んでいないことの確認
        $this->assertGreaterThan(
            $buttonPos,
            $close,
            "「インポート実行」ボタンが <form> の外にある（tab={$tab}）"
        );

        $form    = substr($html, $open, $close - $open);
        $openTag = substr($form, 0, strpos($form, '>') + 1);

        // 確定フォームは「そのプレビューを描いたタブ自身」の endpoint へ戻さねばならない。
        // 別タブを指していると、押しても 1 件も登録されないまま別のタブへ飛ぶ。
        $this->assertSame(
            url($this->importBasePath() . "/{$tab}"),
            (string) $this->htmlAttr($openTag, 'action'),
            "「インポート実行」フォームの action が別の endpoint を指している（tab={$tab}）"
        );

        $fields = [];
        preg_match_all('/<input\b[^>]*>/i', $form, $inputs);
        foreach ($inputs[0] as $tag) {
            if (strtolower((string) $this->htmlAttr($tag, 'type')) !== 'hidden') {
                continue;
            }
            $name = $this->htmlAttr($tag, 'name');
            if ($name !== null) {
                $fields[$name] = $this->htmlAttr($tag, 'value') ?? '';
            }
        }

        // 確定フラグが無いと、押しても `boolean('confirmed')` が false のまま
        // プレビューが再描画されるだけで 1 件も登録されない（エラーも出ない）。
        // ⚠ 往復だけでも赤くはなるが、落ち方が「ファイル未選択の差し戻し」になり
        //   理由が読めない（実測: assertRedirect の失敗が
        //   `Call to a member function all() on array` という別物の fatal に化ける）。
        //   名前を変えるならコントローラの `boolean('confirmed')` と対で直すこと。
        $this->assertArrayHasKey('confirmed', $fields, "「インポート実行」フォームに confirmed hidden が無い（tab={$tab}）");

        // ⚠ `@csrf` の欠落は Feature テストでは**原理的に挙動から検出できない**
        //   （`VerifyCsrfToken::handle()` が `runningUnitTests()` で素通りする）。
        //   描画された `_token` hidden の存在を見るのが唯一の手。Bug #47。
        $this->assertArrayHasKey('_token', $fields, "「インポート実行」フォームに @csrf が無い（tab={$tab}）");

        return [
            'action' => (string) $this->htmlAttr($openTag, 'action'),
            'fields' => $fields,
        ];
    }

    /**
     * 全行がエラーの CSV では、プレビューが**取込の入口を 1 つも描かない**ことを固定する。
     *
     * ⚠ 確定フォームは `@if($validCount > 0)` に囲まれているので、
     *   正常行が 0 件だと**フォームごと消える**（実測: `name="confirmed"` も `name="csv_data"` も
     *   HTML に出ない）。よって「0 件を登録しました」という完了メッセージは
     *   **画面からは到達できない**。そこを `confirm()` で叩くとブラウザにできない操作を
     *   テストが勝手に作ることになるので、そういう CSV はこちらで受ける。
     */
    private function assertPreviewOffersNoImport(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $html = $preview->getContent();

        $this->assertSame(0, $preview->viewData('validCount'), "取込可能な行が残っている（tab={$tab}）");

        // コントローラが数えたエラー行が、画面にも出ていること（Bug #53: 件数と表示を突き合わせる）
        $rowErrors = $preview->viewData('rowErrors');
        $this->assertNotEmpty($rowErrors, "エラー行が 1 件も無い（tab={$tab}）");
        $this->assertStringContainsString(
            $rowErrors[0]['message'],
            $html,
            "エラー行の内容が画面に出ていない（tab={$tab}）"
        );

        $this->assertStringContainsString(
            self::NO_IMPORTABLE_ROWS,
            $html,
            "「" . self::NO_IMPORTABLE_ROWS . "」が画面に出ていない（tab={$tab}）"
        );
        $this->assertDoesNotMatchRegularExpression(
            self::IMPORT_BUTTON_PATTERN,
            $html,
            "取込できないはずのプレビューに「インポート実行」ボタンが出ている（tab={$tab}）"
        );

        return $preview;
    }
}
