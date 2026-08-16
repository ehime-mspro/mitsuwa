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
            // 分譲地の画面ラベルは「所在地」。グローバルの既定「住所」ではなく
            // ProjectController::validateProject() の第3引数が効いていることを見ている
            'address'      => '所在地は必須です。',
        ]);
    }

    /**
     * 画面ごとの項目名がコントローラの第3引数で上書きされていること。
     *
     * ⚠ 「グローバルを所在地に変えた」では通らないように、グローバル既定が「住所」のままで
     *    あることも同時に確認する。片方だけ見ると、既定を書き換えただけで緑になってしまい、
     *    住所ラベルの画面（仕入れ先・テナント顧客・DAD 発注者など）の退行を見逃す。
     */
    public function test_screen_specific_attribute_overrides_the_global_default(): void
    {
        $this->assertSame(
            '住所',
            Lang::get('validation.attributes.address'),
            'グローバル既定が変わっています。画面ごとの差は第3引数で表現すること'
        );

        $this->actingAs($this->executive())
            ->post('/realestate/projects', [])
            ->assertSessionHasErrors(['address' => '所在地は必須です。']);
    }

    /**
     * validate() に出てくる項目が全て和名を持つこと。
     *
     * 和名が無いキーは Laravel が snake_case を単語に開いてそのまま出すため、
     * 画面に `guarantor1 name` `started at` のような英字が出る（2026-07-30 に 86 件あった）。
     * 実際にエラーを起こさないと見えないので、コントローラ側を走査して静的に押さえる。
     */
    public function test_every_validated_field_has_a_japanese_attribute_label(): void
    {
        $attributes = Lang::get('validation.attributes');
        $this->assertIsArray($attributes);

        $missing = [];
        $seen    = 0;

        foreach ($this->validatedKeysByController() as $controller => $keys) {
            foreach ($keys as $key) {
                $seen++;
                if (! $this->hasAttributeLabel($key, $attributes)) {
                    $missing[] = "{$key}  ({$controller})";
                }
            }
        }

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThan(300, $seen, 'コントローラの走査に失敗している');

        $this->assertSame(
            [],
            $missing,
            "和名が無い項目があります（画面に英字が出ます）:\n" . implode("\n", $missing)
        );
    }

    /**
     * app/Http/Controllers 配下の validate() に渡されるルールキーを集める。
     *
     * @return array<string, list<string>> コントローラ相対パス => キー一覧
     */
    private function validatedKeysByController(): array
    {
        $out = [];
        $dir = app_path('Http/Controllers');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (! preg_match_all('/validate\(\s*\[(.*?)\n\s*\]\s*[,)]/s', $src, $blocks)) {
                continue;
            }
            $keys = [];
            foreach ($blocks[1] as $block) {
                // ルール定義の行だけを拾う（配列値の中の 'key' => ... は行頭ではない）
                if (preg_match_all("/^\s*'([a-z0-9_.*]+)'\s*=>/im", $block, $m)) {
                    foreach ($m[1] as $key) {
                        $keys[$key] = true;
                    }
                }
            }
            if ($keys) {
                $out[str_replace($dir . '/', '', $file->getPathname())] = array_keys($keys);
            }
        }

        return $out;
    }

    /** attributes に完全一致 or ワイルドカード一致のエントリがあるか */
    private function hasAttributeLabel(string $key, array $attributes): bool
    {
        if (isset($attributes[$key])) {
            return true;
        }

        // Laravel は costs.0.notes を costs.*.notes で解決する
        $probe = str_replace('*', '0', $key);
        foreach (array_keys($attributes) as $candidate) {
            if (! str_contains($candidate, '*')) {
                continue;
            }
            $pattern = '#^' . str_replace('\*', '([^.]*)', preg_quote($candidate, '#')) . '\z#u';
            if (preg_match($pattern, $probe) === 1) {
                return true;
            }
        }

        return false;
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

    /**
     * 周辺ビル調査で追加した項目名がグローバルに載っていること。
     *
     * ⚠ 第3引数で上書きするキー（name / address / room_number / floor / notes / rows）も
     *   グローバルに存在していないと test_every_validated_field_has_a_japanese_attribute_label が
     *   落ちる。上書きは「語を変える」だけで「登録する」ものではない。
     */
    public function test_area_building_survey_attributes_are_registered(): void
    {
        $attributes = Lang::get('validation.attributes');

        $expected = [
            'industry'        => '業種',
            'surveyed_month'  => '調査年月',
            'surveyed_by'     => '調査者',
            'operating_count' => '営業',
            'vacant_count'    => '空き',
            'unknown_count'   => '不明',
            'confirmed_on'    => '最終確認日',
            'moved_out_on'    => '退去日',
            'survey_notes'    => '所見',
            'kind'            => '取込種別',
            'coordinates'     => '取得した座標',
        ];

        foreach ($expected as $key => $label) {
            $this->assertArrayHasKey($key, $attributes, "attributes に {$key} が無い");
            $this->assertSame($label, $attributes[$key], "{$key} の和名が想定と違う");
        }
    }

    /**
     * 括弧の注記は項目名に含めない方針（Bug #37）。
     * 「営業（そのビルのテナント部屋数）」ではなく「営業」。
     */
    public function test_area_building_attributes_have_no_parenthetical_notes(): void
    {
        $attributes = Lang::get('validation.attributes');

        foreach (['operating_count', 'vacant_count', 'unknown_count', 'surveyed_month'] as $key) {
            $this->assertStringNotContainsString('（', $attributes[$key], "{$key} に括弧の注記が入っている");
        }
    }
}
