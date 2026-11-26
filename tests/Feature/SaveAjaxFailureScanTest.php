<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 断られた保存の Ajax の文を作る部品（_partials/_ajax_save_failure の ajaxSaveFailureMessage）を呼ぶ画面が、
 * 自分のファイルで部品を読み込んでいることを全件で固定する（設計メモ 2026-11-26-save-ajax-403-design.md）。
 * 部品を読み込まないと、ブラウザでは関数が無くて止まるのに、HTML の上では何も壊れて見えない（Bug #28。
 * 取得の部品の GetAjaxFailureScanTest::test_every_caller_includes_the_helper と同じ形）。
 *
 * XMLHttpRequest の部品（buyers/_xhr_failure_message の xhrFailureMessage）を呼ぶ画面も同じく見る。
 *
 * ⚠ Blade コメント（`{{-- --}}`）は落としてから数える（説明に関数の名前を書いても呼び出しに数えない・
 *   コメントの中の include を読み込みに数えない）。
 * ⚠ 死角: 呼ぶ画面のファイル自身に include があるかを見る（親の画面が読み込んでいても数えない）。JS の行のコメントは落とさない。
 */
class SaveAjaxFailureScanTest extends TestCase
{
    /**
     * 部品ごとの [定義を持つファイル, 定義の目印, 呼び出しの目印, 読み込みの字, 呼ぶファイルの下限]。
     * 下限は 2026-11-26 の実数（保存の Ajax の部品 6: 3 一覧・_buyer-select・supplier-picker・buyers/_xhr_failure_message ／
     * XMLHttpRequest の部品 2: buyers/index・buyers/_form）。走査が空振りして緑になるのを防ぐ。
     */
    private const PARTIALS = [
        'save' => [
            '_partials/_ajax_save_failure.blade.php',
            'function ajaxSaveFailureMessage(',
            'ajaxSaveFailureMessage(',
            "@include('_partials._ajax_save_failure')",
            6,
        ],
        'xhr' => [
            'buyers/_xhr_failure_message.blade.php',
            'function xhrFailureMessage(',
            'xhrFailureMessage(',
            "@include('buyers._xhr_failure_message')",
            2,
        ],
    ];

    /** @return array<string, string> ビューの相対パス => Blade コメントを落としたソース */
    private static function sources(): array
    {
        $sources = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $sources[$file->getRelativePathname()] = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));
            }
        }
        ksort($sources);

        return $sources;
    }

    public function test_every_caller_includes_the_helper(): void
    {
        $sources = self::sources();
        foreach (self::PARTIALS as $name => [$partial, $definition, $call, $include, $minCallers]) {
            $definitions = [];
            $callers = [];
            $missing = [];
            foreach ($sources as $file => $source) {
                if (str_contains($source, $definition)) {
                    $definitions[] = $file;
                }
                if ($file === $partial || ! str_contains($source, $call)) {
                    continue;
                }
                $callers[] = $file;
                if (! str_contains($source, $include)) {
                    $missing[] = $file;
                }
            }

            $this->assertSame([$partial], $definitions, "部品（{$name}）の関数の定義が部品のほかにもある（または部品に無い）");
            $this->assertGreaterThanOrEqual($minCallers, count($callers), "部品（{$name}）の関数を呼ぶ画面の走査が機能していない（拾えた画面が少なすぎる）");
            $this->assertSame([], $missing,
                "部品（{$name}）の関数を呼ぶのに部品を読み込んでいない画面があります（ブラウザでは関数が無くて止まります）:\n" . implode("\n", $missing));
        }
    }
}
