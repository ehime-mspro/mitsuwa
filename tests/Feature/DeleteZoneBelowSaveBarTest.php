<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 画面の下に固定する保存のバー（`<x-form-actions>`。高さ約 74px）を描くビューで、バーより後ろ（ページの一番下）に置いた削除のフォームは、
 * その下にバーの高さの余白を持つ（Bug #108。無いと削除のボタンがバーの後ろに隠れ、1440px では上の端しか、375px では押せない）。
 *
 * ⚠ ビューごとに並べる形にしない（Top trap #13）。全ビューを走査し、バーを自分か `@include` した部品で描くビューを全件見る。
 * ⚠ 本当に押せるかは実ブラウザで測る（2026-10-07: 賃貸マンションの 4 画面・DAD の 5 画面で、ボタンの上・中・下の 3 点を `elementFromPoint()`）。
 * ⚠ Blade コメント（`{{-- --}}`）は落としてから測る（注意書きに書いた語で緑にならないように。Bug #28 の型）。
 */
class DeleteZoneBelowSaveBarTest extends TestCase
{
    private const SPACER = '<div style="height: 80px;" aria-hidden="true"></div>';

    /** @return array<string, string> ビューの名前（`dad.projects.edit`）=> Blade コメントを落とした中身 */
    private function views(): array
    {
        $root = resource_path('views');
        $views = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $name = str_replace('/', '.', substr($file->getPathname(), strlen($root) + 1, -strlen('.blade.php')));
                $views[$name] = (string) preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));
            }
        }

        return $views;
    }

    public function test_a_delete_form_after_the_fixed_save_bar_has_room_below_it(): void
    {
        $views = $this->views();
        $this->assertGreaterThan(200, count($views), 'Blade の走査が機能していない');

        $checked = [];
        $missing = [];
        foreach ($views as $name => $src) {
            $bar = strpos($src, '<x-form-actions');
            if ($bar === false) {
                // 保存のバーを @include した部品で描くビュー（賃貸マンションの編集画面など）
                preg_match_all("/@include\\(\\s*'([\\w.-]+)'/", $src, $includes, PREG_OFFSET_CAPTURE);
                foreach ($includes[1] as [$partial, $offset]) {
                    if (str_contains($views[$partial] ?? '', '<x-form-actions')) {
                        $bar = $offset;
                        break;
                    }
                }
            }
            $delete = strrpos($src, "@method('DELETE')");
            if ($bar === false || $delete === false || $delete < $bar) {
                continue;
            }
            $checked[] = $name;
            if (! str_contains(substr($src, $delete), self::SPACER)) {
                $missing[] = $name;
            }
        }

        // 走査が部品の @include まで辿れていること（辿れないと賃貸マンションの 4 画面が対象から黙って落ちる）
        $this->assertContains('mansion.rooms.edit', $checked, '保存のバーを部品で描くビューを拾えていない');
        $this->assertContains('dad.projects.edit', $checked);
        $this->assertGreaterThanOrEqual(9, count($checked), '保存のバーの後ろに削除のフォームを置くビューを拾えていない');
        $this->assertSame([], $missing, '保存のバーの後ろに置いた削除の欄の下に余白が無い（削除のボタンがバーの後ろに隠れる）');
    }
}
