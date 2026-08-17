<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CSV 取込画面で、ファイルが不正なときに**理由が画面に出る**こと。
 *
 * ⚠ **2026-08-17 に 3 画面が無音だった**（顧客 / テナント / 賃貸マンション）。
 *   拡張子違いのファイルを上げると `mimes:csv,txt` に落ちて差し戻されるのに、
 *   どの画面にもエラー表示が無く**何も起きないように見えた**。
 *
 * ⚠ **「取込には独自の警告 UI があるから大丈夫」は誤り。** 行単位の警告（プレビュー）と
 *   `validate()` の失敗は別物で、後者は `$errors` に入る。`layouts/app.blade.php` は
 *   `session('error')` は描画するが **`$errors` は描画しない**ので、
 *   `back()->with('error', ...)` 経由の失敗は見えても、`validate()` の失敗は見えなかった。
 *
 * ⚠ **`assertSessionHasErrors()` を呼んではいけない**（Bug #49）。呼ぶと、そのあと
 *   描画した画面からエラー表示が丸ごと消える。
 */
class ImportValidationFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 取込の入口ごとに「不正なファイル → 差し戻し先で理由が見える」ことを見る。
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function importEndpoints(): array
    {
        return [
            '顧客CSV'            => ['/admin/customers/import',        '/admin/customers/import', ['department' => 'housing']],
            'テナントCSV'        => ['/admin/tenant-import/property',   '/admin/tenant-import',    []],
            '賃貸マンションCSV'  => ['/admin/mansion-import/property',  '/admin/mansion-import',   []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importEndpoints')]
    public function test_an_invalid_file_shows_the_reason_on_screen(string $post, string $back, array $extra): void
    {
        $actor = $this->executive();

        // mimes:csv,txt に落ちるファイル
        $bad = UploadedFile::fake()->create('sample.pdf', 10, 'application/pdf');

        $this->actingAs($actor)
            ->from($back)
            ->post($post, ['csv_file' => $bad] + $extra)
            ->assertRedirect($back);

        // 差し戻された画面を実際に描画して見る（セッションには触らない。Bug #49）
        $html = $this->actingAs($actor)->get($back)->getContent();

        $this->assertStringContainsString(
            '入力内容にエラーがあります',
            $html,
            "{$post} に不正なファイルを投げたのに、差し戻し先 {$back} に理由が出ていない"
        );

        // 具体的な理由まで出ていること（見出しだけ出して中身が空なら意味が無い）
        $this->assertMatchesRegularExpression(
            '/<li>[^<]*(ファイル|csv)[^<]*<\/li>/ui',
            $html,
            'サマリの見出しだけで、具体的な理由が並んでいない'
        );
    }
}
