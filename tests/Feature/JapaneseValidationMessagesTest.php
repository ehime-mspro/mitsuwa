<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\ZoningType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * バリデーションメッセージが日本語で出ることの検証。
 *
 * このアプリは APP_LOCALE=ja / APP_FALLBACK_LOCALE=ja で、日本語 UI はすべて Blade に
 * ハードコードされているため lang/ 配下がほぼ空だった。その結果
 * $request->validate() のエラー文が `validation.required` という生の翻訳キーで
 * 画面に出ていた（fallback も ja なので en の組み込み文にも落ちない）。
 * 2026-07-29 に本番で実測して発覚し lang/ja/validation.php を追加した。
 *
 * ⚠ 「ファイルが在ること」だけを見るテストにしない。翻訳が解決されるかは
 *    ロケール設定・キー構造・プレースホルダ名すべてが揃って初めて成り立つので、
 *    実際に HTTP でバリデーションを起こして出てきた文言を検証する。
 */
class JapaneseValidationMessagesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        ZoningType::create(['name' => '第一種住居地域', 'sort_order' => 5]);
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * validation.php のルールキーを "between.string" のような平坦な一覧にする。
     * attributes / custom はルールではないので除く。
     */
    private function flattenValidationKeys(array $lines): array
    {
        $keys = [];
        foreach ($lines as $key => $value) {
            if ($key === 'attributes' || $key === 'custom') {
                continue;
            }
            if (is_array($value)) {
                foreach (array_keys($value) as $sub) {
                    $keys[] = "{$key}.{$sub}";
                }
            } else {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** 必須エラーが和名付きの日本語で出ること */
    public function test_required_message_is_japanese_with_attribute_label(): void
    {
        $response = $this->actingAs($this->executive())
            ->post('/realestate/projects', []);

        $response->assertSessionHasErrors([
            'project_name' => 'プロジェクト名は必須です。',
            'address'      => '住所は必須です。',
        ]);
    }

    /**
     * 全ルールの翻訳が解決されること（1 つでも欠けるとそのルールだけ生キーで表示される）。
     *
     * ⚠ これが本命のガード。HTTP でいくつかのルールを踏んで確かめる形にすると、
     *    踏まなかったルールの欠落を見逃す。全キーを直接引いて自分自身が返らないことを見る。
     */
    public function test_every_validation_rule_resolves_to_a_message(): void
    {
        $keys = $this->flattenValidationKeys(require lang_path('ja/validation.php'));

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThan(100, count($keys), 'キーの取得に失敗している');

        $leaked = [];
        foreach ($keys as $key) {
            $line = Lang::get("validation.{$key}");
            if ($line === "validation.{$key}" || str_contains($line, 'validation.')) {
                $leaked[] = $key;
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "翻訳が解決できないルールがあります（生の翻訳キーが画面に出ます）:\n" . implode("\n", $leaked)
        );
    }

    /** 複数エラーが同時に出ても全部日本語になること（HTTP 経路での確認） */
    public function test_multiple_errors_are_all_japanese_over_http(): void
    {
        $response = $this->actingAs($this->executive())
            ->post('/realestate/projects', [
                'project_name'      => str_repeat('あ', 200),  // max:100 超過
                'status'            => 'not_a_real_status',    // in: 違反
                'land_area_sqm'     => 'abc',                  // numeric 違反
                'building_coverage' => 999,                    // max:100 超過
                'contract_date'     => 'not-a-date',           // date 違反
            ]);

        $response->assertSessionHasErrors([
            'project_name'      => 'プロジェクト名は100文字以下で入力してください。',
            'status'            => '選択されたステータスは正しくありません。',
            'land_area_sqm'     => '土地面積（㎡）は数値で入力してください。',
            'building_coverage' => '建ぺい率は100以下の値にしてください。',
            'contract_date'     => '契約日は正しい日付形式で入力してください。',
        ]);
    }

    /** プレースホルダ（:max 等）が置換されて数字が出ること */
    public function test_placeholders_are_substituted(): void
    {
        $response = $this->actingAs($this->executive())
            ->post('/realestate/projects', [
                'project_name' => str_repeat('あ', 200),
                'address'      => '愛媛県松山市1-1-1',
                'status'       => 'info_obtained',
            ]);

        $response->assertSessionHasErrors([
            'project_name' => 'プロジェクト名は100文字以下で入力してください。',
        ]);
    }

    /**
     * lang/ja/validation.php が Laravel 本体のキーを全部持っていること。
     *
     * ⚠ Laravel をアップグレードしてルールが増えると、そのルールだけ生キーに戻る。
     *    バージョンを上げた時点で気づけるようにキー集合を突き合わせる。
     */
    public function test_ja_file_covers_every_framework_validation_key(): void
    {
        $frameworkFile = base_path(
            'vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'
        );
        $this->assertFileExists($frameworkFile);

        $expected = $this->flattenValidationKeys(require $frameworkFile);
        $actual   = $this->flattenValidationKeys(require lang_path('ja/validation.php'));

        // 走査が空振りして緑になる事故を防ぐ（Laravel のルール数は 100 を大きく超える）
        $this->assertGreaterThan(100, count($expected), 'フレームワーク側のキー取得に失敗している');

        $this->assertSame(
            [],
            array_values(array_diff($expected, $actual)),
            'lang/ja/validation.php に無いルールがあります（そのルールは生キーで表示されます）'
        );
    }

    /** ロケール自体が ja に解決されていること（設定が変わると全部英語キーに戻る） */
    public function test_locale_resolves_to_japanese(): void
    {
        $this->assertSame('ja', app()->getLocale());
        $this->assertNotSame(
            'validation.required',
            Lang::get('validation.required'),
            'validation.required が自分自身を返している = 翻訳が解決できていない'
        );
    }
}
