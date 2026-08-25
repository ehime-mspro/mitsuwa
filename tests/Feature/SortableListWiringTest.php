<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 並び替え見出しを持つ一覧は、必ず並び順の hidden も持つこと。
 *
 * ⚠ **全件分類**（Bug #45）。「直したビューを配列に並べる」形にすると、
 *   新しい一覧が無検査のまま増えて永遠に緑になる。resources/views を機械的に
 *   走査し、<x-sortable-th を持つビューを**全部**拾って検査する。
 * ⚠ これが無いと「見出しだけ付けて hidden を忘れた」3 本目の一覧が静かに増える。
 *   壊れ方は「フィルタを変えた瞬間に並び順が既定へ戻る」で、エラーも警告も出ない。
 * ⚠ **コメントを落としてから判定する**（Bug #42 ②と同型）。`sortable-th.blade.php`
 *   自身の docblock に使い方の例として「<x-sortable-th column="area" …」という
 *   リテラル文字列があり、素の str_contains だと**コンポーネント定義ファイル自身**が
 *   「並び替え見出しを持つビュー」と誤判定されて false-fail する（実測済み）。
 */
class SortableListWiringTest extends TestCase
{
    public function test_every_sortable_list_carries_the_sort_in_its_filter_form(): void
    {
        $scanned = 0;
        $sortable = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $scanned++;
            $source = $this->withoutComments(file_get_contents($file->getPathname()));

            if (! str_contains($source, '<x-sortable-th')) {
                continue;
            }

            $sortable[] = $file->getFilename();

            $this->assertStringContainsString(
                '<x-sort-hidden',
                $source,
                "{$file->getFilename()} は並び替え見出しを持つのに、並び順を持ち回す hidden が無い"
            );
        }

        // ⚠ 走査が空振りして緑になる事故を防ぐ（Bug #45）
        $this->assertGreaterThan(100, $scanned, 'Blade の走査が空振りしている');
        $this->assertNotEmpty($sortable, '並び替え見出しを持つビューが 1 本も見つからない');
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
