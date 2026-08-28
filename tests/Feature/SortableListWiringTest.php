<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Tenant\PropertyController;
use App\Http\Controllers\Tenant\UnitController;
use App\Models\AreaBuilding;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Tenant\AreaBuildingListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 並び替え見出しを持つ一覧の配線（設計書 §7.1）。
 *
 * ⚠ **全件分類**（Bug #45）。「直したビューを配列に並べる」形にすると、
 *   新しい一覧が無検査のまま増えて永遠に緑になる。resources/views を機械的に
 *   走査し、<x-sortable-th を持つビューを**全部**拾って検査する。
 * ⚠ **コメントを落としてから判定する**（Bug #42 ②と同型）。`sortable-th.blade.php`
 *   自身の docblock に使い方の例として「<x-sortable-th column="area" …」という
 *   リテラル文字列があり、素の str_contains だと**コンポーネント定義ファイル自身**が
 *   「並び替え見出しを持つビュー」と誤判定されて false-fail する（実測済み）。
 */
class SortableListWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 見出しの column を照合するラベル表と、その画面の見出しの本数。
     *
     * ⚠ **ここに無いビューが走査で見つかったら落とす**（列挙ではなく分類。Bug #45 ①）。
     * ⚠ 本数も固定する。走査が空振りして緑になる事故を防ぐ（Bug #45）。
     */
    private const SORT_COLUMN_SOURCES = [
        'tenant/area-buildings/index.blade.php' => [AreaBuildingListService::class, 7],
        'tenant/properties/index.blade.php'     => [PropertyController::class, 2],
        'tenant/units/index.blade.php'          => [UnitController::class, 3],
    ];

    /**
     * 並び替えを実行するクラス → [一覧の URL, SORT_COLUMNS の列数]。
     *
     * ⚠ SORT_COLUMN_SOURCES とは軸が違う（あちらは「ビュー → 見出しの列数」、
     *   こちらは「SORT_COLUMNS を定義するクラス → 一覧 URL」）。ビューがまだ
     *   <x-sortable-th> を持たない画面（周辺ビル調査。Task 7 待ち）でも、
     *   コントローラ／サービスが SORT_COLUMNS さえ持っていればここに登録できる。
     * ⚠ **ここに無ければ test_every_sort_column_can_be_requested_without_erroring() が
     *   落ちる**（classesDefiningSortColumns() が app/ 全体を機械的に走査して
     *   突き合わせるため。Bug #45 ①「全件分類」）。
     */
    private const SORT_ENDPOINTS = [
        AreaBuildingListService::class => ['/tenant/area-buildings', 7],
        UnitController::class          => ['/tenant/units', 3],
        PropertyController::class      => ['/tenant/properties', 2],
    ];

    public function test_every_sortable_list_carries_the_sort_in_its_filter_form(): void
    {
        $scanned = 0;
        $sortable = [];

        foreach ($this->bladeFiles() as $relative => $source) {
            $scanned++;

            if (! str_contains($source, '<x-sortable-th')) {
                continue;
            }

            $sortable[] = $relative;

            $this->assertStringContainsString(
                '<x-sort-hidden',
                $source,
                "{$relative} は並び替え見出しを持つのに、並び順を持ち回す hidden が無い"
            );
        }

        // ⚠ 走査が空振りして緑になる事故を防ぐ（Bug #45）
        $this->assertGreaterThan(100, $scanned, 'Blade の走査が空振りしている');
        $this->assertNotEmpty($sortable, '並び替え見出しを持つビューが 1 本も見つからない');
    }

    /**
     * 見出しの column が、その画面のラベル表に全部載っていること。
     *
     * ⚠ `column` を打ち間違えると `sortable-th` が `$columns[$column]['label']` の
     *   未定義キーを引き、Laravel が警告を ErrorException に変えて**その画面が 500 になる**。
     *   これはその静的な防波堤（設計書 §7.1）。
     * ⚠ `label="…"` の書き残しも落とす。プロップを廃止したので、残っていても
     *   **属性バッグへ素通りして黙って無視される**（`<th label="面積">` が出るだけ）。
     */
    public function test_every_sortable_header_names_a_column_that_exists_in_its_label_map(): void
    {
        $found = [];

        foreach ($this->bladeFiles() as $relative => $source) {
            if (! str_contains($source, '<x-sortable-th')) {
                continue;
            }

            $found[] = $relative;

            $this->assertArrayHasKey(
                $relative,
                self::SORT_COLUMN_SOURCES,
                "{$relative} は並び替え見出しを持つのに、ラベル表との対応が登録されていない"
            );

            [$class, $expectedCount] = self::SORT_COLUMN_SOURCES[$relative];
            $keys = array_keys($class::SORT_COLUMNS);

            // ⚠ label は描画されるので壊せば落ちるが、desc / asc は Task 8 の x-sort-bar が
            //   最初の読み手になるまで**誰も読まない**＝消しても全テストが緑のまま通る（実測）。
            //   表示文字列を 1 箇所に集約した意味が無くなるので、ここで形を固定する（Bug #41 / #46）。
            foreach ($class::SORT_COLUMNS as $key => $spec) {
                foreach (['label', 'desc', 'asc'] as $required) {
                    $this->assertArrayHasKey($required, $spec, "{$class}::SORT_COLUMNS['{$key}'] に {$required} が無い");
                    $this->assertNotSame('', trim($spec[$required]), "{$class}::SORT_COLUMNS['{$key}']['{$required}'] が空");
                }
            }

            preg_match_all('/<x-sortable-th\b[^>]*>/s', $source, $tags);
            $this->assertCount($expectedCount, $tags[0], "{$relative} の並び替え見出しの本数が変わっている");

            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression('/\bcolumn="([a-z_]+)"/', $tag, "column が無い見出しがある: {$tag}");
                preg_match('/\bcolumn="([a-z_]+)"/', $tag, $matches);

                $this->assertContains(
                    $matches[1],
                    $keys,
                    "{$relative} の column=\"{$matches[1]}\" が {$class} の SORT_COLUMNS に無い（この画面は 500 になる）"
                );
                $this->assertStringContainsString(':columns="', $tag, "{$relative} の見出しがラベル表を受け取っていない: {$tag}");
                $this->assertStringNotContainsString(' label="', $tag, "{$relative} に label プロップの書き残しがある（黙って無視される）: {$tag}");
            }
        }

        $this->assertEqualsCanonicalizing(
            array_keys(self::SORT_COLUMN_SOURCES),
            $found,
            'ラベル表に登録済みのビューが走査で見つからない（ビューを消したか、表が古い）'
        );
    }

    /**
     * SORT_COLUMNS の全キーが、実際に並べ替えを最後まで実行できること（設計 §7.1 の逆方向）。
     *
     * ⚠ **test_every_sortable_header_names_a_column_that_exists_in_its_label_map() は
     *   一方向しか見ていない。** あちらは「見出しの column が SORT_COLUMNS に載っているか」
     *   （ビュー → 定義）だけを確認しており、「SORT_COLUMNS の全キーが実際に並べ替えを
     *   最後まで実行できるか」（定義 → 実行）は誰も見ていなかった。SORT_COLUMNS（許可リスト・
     *   ラベル）と、実際に並べ替えを行うコード（match 式・SQL 式・attribute 参照）は
     *   **別の場所に書かれている**ため、キーを足して片方を書き忘れても許可リストは
     *   通ってしまい、その列を押した瞬間だけ 500 になる。壊れ方は画面ごとに違う
     *   （周辺ビル調査は AreaBuildingListService::sortValue() の match に default アームが
     *   無く UnhandledMatchError、部屋一覧は SORT_COLUMNS 自身の 'expr' キー欠落で
     *   ErrorException、物件一覧は 'attribute' キー欠落で同種の未定義キーアクセス）が、
     *   「許可リストは通るのに実行だけ落ちる」という構図は共通している。
     *
     * ⚠ **対象クラスは grep で全件分類する**（Bug #45 ①）。「知っている 3 画面」を
     *   手で列挙すると、4 画面目が SORT_COLUMNS を持って追加されても検査対象に入らず
     *   永遠に緑になる。`public const SORT_COLUMNS =` を定義するクラスを app/ 全体から
     *   機械的に拾い、SORT_ENDPOINTS に登録されていなければ落とす。
     *
     * ⚠ 経営層ユーザーは department.access ミドルウェアを素通りする
     *   （CheckDepartmentAccess::handle()）ので、3 画面とも部門の紐付けは要らない
     *   （UnitListSortTest / PropertyListSortTest と同じ流儀）。
     *
     * ⚠ **各画面に最低 1 行のデータが要る**（Bug #22/#25/#26/#27 と同型の実測済みの罠）。
     *   AreaBuildingListService::applySort() の欠落検出は Collection::partition() の
     *   コールバック（＝ sortValue()）が実行されて初めて起きるが、partition() は
     *   空コレクションではコールバックを 1 度も呼ばない。0 件のまま `?sort=<未知キー>` を
     *   叩いても UnhandledMatchError は発火せず静かに 200 が返る（実測: 8 番目のキーを
     *   match アーム無しで追加する変異が、行を作らないままだと検出できなかった）。
     *   部屋一覧・物件一覧の欠落検出はクエリ組み立て時（SORT_COLUMNS[$key] の配列アクセス）
     *   に起きるため行数に依存しないが、**3 画面とも同じ流儀で最低 1 行作る**
     *   （どの画面が将来 per-row 化されても素通りしないため）。
     */
    public function test_every_sort_column_can_be_requested_without_erroring(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $this->seedOneRowPerScreen();

        $definingClasses = $this->classesDefiningSortColumns();

        // ⚠ 走査が空振りして緑になる事故を防ぐ（Bug #45）
        $this->assertCount(
            3,
            $definingClasses,
            'SORT_COLUMNS を定義するクラスの数が変わった（走査漏れ、または画面の増減）'
        );

        $this->assertEqualsCanonicalizing(
            array_keys(self::SORT_ENDPOINTS),
            $definingClasses,
            'SORT_COLUMNS を定義するクラスが SORT_ENDPOINTS に登録されていない（新しい並び替え画面を追加したら、ここにも登録すること）'
        );

        foreach (self::SORT_ENDPOINTS as $class => [$url, $expectedCount]) {
            $keys = array_keys($class::SORT_COLUMNS);
            $this->assertCount($expectedCount, $keys, "{$class}::SORT_COLUMNS の列数が変わっている");

            foreach ($keys as $key) {
                foreach (['asc', 'desc'] as $dir) {
                    $this->actingAs($user)
                        ->get("{$url}?sort={$key}&dir={$dir}")
                        ->assertOk();
                }
            }
        }
    }

    /**
     * 3 画面それぞれに最低 1 行を作る。
     *
     * ⚠ **これが無いと周辺ビル調査の match アーム欠落を検出できない**（実測。
     *   test_every_sort_column_can_be_requested_without_erroring() の docblock を参照）。
     *   部屋一覧・物件一覧は行数に依存しない欠落検出だが、3 画面とも同じ流儀で揃える。
     */
    private function seedOneRowPerScreen(): void
    {
        AreaBuilding::create(['name' => '配線検査用ビル']);

        $property = Property::create([
            'code'             => 'T-WIRE01',
            'name'             => '配線検査用物件',
            'property_type'    => 'tenant',
            'department'       => 'tenant',
            'operation_status' => 'active',
            'address'          => '愛媛県松山市本町1-1',
        ]);

        Unit::create([
            'property_id'      => $property->id,
            'floor'            => 1,
            'room_number'      => '101',
            'display_name'     => '101',
            'status'           => 'vacant',
            'area_tsubo'       => 20.00,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 200000,
        ]);
    }

    /**
     * `public const SORT_COLUMNS =` を定義しているクラスを app/ 全体から機械的に拾う。
     *
     * ⚠ PSR-4（`App\` => `app/`）でファイルパスから直接クラス名を導く。
     *   namespace / class 宣言を別途パースする必要が無く、取りこぼしにくい。
     *
     * @return list<class-string>
     */
    private function classesDefiningSortColumns(): array
    {
        $found = [];
        $root  = app_path() . DIRECTORY_SEPARATOR;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            if (! preg_match('/public const SORT_COLUMNS\s*=/', file_get_contents($file->getPathname()))) {
                continue;
            }

            $relative = str_replace($root, '', $file->getPathname());
            $found[] = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
        }

        sort($found);

        return $found;
    }

    /**
     * resources/views の Blade を「相対パス => コメント除去済みソース」で返す。
     *
     * ⚠ ファイル名だけをキーにしないこと。3 一覧とも `index.blade.php` で衝突する。
     *
     * @return array<string, string>
     */
    private function bladeFiles(): array
    {
        $root = resource_path('views') . DIRECTORY_SEPARATOR;
        $out = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace($root, '', $file->getPathname());
            $out[$relative] = $this->withoutComments(file_get_contents($file->getPathname()));
        }

        return $out;
    }

    /**
     * Blade コメント・JS ブロックコメント・JS 行コメントを落とす
     * （`tests/Feature/Tenant/AreaBuildingMapTabTest.php::withoutComments()` と同じ実装。
     *   ⚠ 行頭アンカーを外すと `https://` まで消える）。
     */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^[ \t]*//.*$#m', '', $source);
    }
}
