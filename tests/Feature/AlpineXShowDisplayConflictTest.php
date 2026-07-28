<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * x-show を持つ要素の :style に display を書いていないことを検証する。
 *
 * 【背景】2026-07-28 に本番ブラウザで発見。ステータスバッジのポップオーバーが
 * 縦積みにならず横 1 列（実測 466px × 43px）で開いていた。
 *
 * Alpine の x-show は display プロパティを自分のものとして扱う。実測すると:
 *   非表示: style="... display: none; flex-direction: column; ..."  ← flex を none で上書き
 *   表示:   style="... flex-direction: column; ..."                 ← display を丸ごと削除
 * 結果 computed display が block になり、残った flex-direction が効かない。
 * docs/RULES.md Bug #2（style= と :style= の競合）と同族だが、相手が x-show という別パターン
 * （Bug #32）。
 *
 * ⚠ display を :style に書いても Blade は通るしテストも通る。壊れるのはブラウザだけ。
 *   だからソースを直接見る形で固定する。
 *
 * ⚠ x-show + :style を使うビューを新設したら、このテストが自動で拾う
 *   （resources/views/ 全体を走査するため個別追加は不要）。
 */
class AlpineXShowDisplayConflictTest extends TestCase
{
    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $dir   = resource_path('views');
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * ビューを 1 本も拾えていないと、以下のテストが「対象ゼロで緑」になってしまう。
     * 走査そのものが機能していることを先に固定する。
     */
    public function test_scanner_actually_finds_blade_files(): void
    {
        $files = $this->bladeFiles();

        $this->assertGreaterThan(100, count($files), 'Blade ファイルの走査が機能していない');
    }

    /**
     * x-show と :style を同じ要素に持つタグの :style に display が無いこと。
     *
     * 検出したら、display / flex-direction / gap を内側のラッパー div へ移すこと:
     *   <div x-show="open" :style="'position: fixed; ...'">
     *       <div style="display: flex; flex-direction: column; gap: 4px;">
     *           <template x-for="...">...</template>
     *       </div>
     *   </div>
     */
    public function test_no_display_inside_style_binding_of_x_show_elements(): void
    {
        $violations = [];

        foreach ($this->bladeFiles() as $path) {
            $source = file_get_contents($path);

            // 開始タグ単位で見る（x-show と :style が同一タグにあるものだけが対象）
            preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $tags, PREG_OFFSET_CAPTURE);

            foreach ($tags[0] as [$tag, $offset]) {
                if (! str_contains($tag, 'x-show')) {
                    continue;
                }
                if (! preg_match('/:style\s*=\s*(["\'])(.*?)\1/s', $tag, $m)) {
                    continue;
                }
                if (! preg_match('/(^|[;\s\'"+])display\s*:/i', $m[2])) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $violations[] = str_replace(base_path() . '/', '', $path) . ':' . $line;
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['x-show を持つ要素の :style に display があります（Alpine が display を奪うため効きません）:'],
            $violations,
        )));
    }
}
