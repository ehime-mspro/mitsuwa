<?php

namespace Tests\Feature;

use App\Http\Controllers\Tenant\PropertyController;
use App\Http\Controllers\Tenant\UnitController;
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
    /**
     * 見出しの column を照合するラベル表と、その画面の見出しの本数。
     *
     * ⚠ **ここに無いビューが走査で見つかったら落とす**（列挙ではなく分類。Bug #45 ①）。
     * ⚠ 本数も固定する。走査が空振りして緑になる事故を防ぐ（Bug #45）。
     */
    private const SORT_COLUMN_SOURCES = [
        'tenant/properties/index.blade.php' => [PropertyController::class, 2],
        'tenant/units/index.blade.php'      => [UnitController::class, 3],
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
