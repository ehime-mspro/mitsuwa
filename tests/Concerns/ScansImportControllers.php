<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * 取込のコントローラ（`app/Http/Controllers` 配下の `*ImportController.php`）を機械的に列挙する（全件分類の走査用。Top trap #13）。
 *
 * ⚠ 2026-09-25 に `tests/Feature/ImportControllerReturnPathScanTest.php` から**そのまま**切り出した。
 *   入力チェックの包み方を見る `ImportControllerValidationRedirectScanTest` も同じ集合を見る必要があるため
 *   （2 本が別々に列挙すると、片方だけ範囲が変わって見落としが生まれる）。
 *   中身を変えるときは両方の利用者で測り直すこと。
 */
trait ScansImportControllers
{
    /** `*ImportController.php` の本数の下限（2026-09-25 実測 8 本）。下回ったら列挙が空振りしている */
    private const MIN_IMPORT_CONTROLLERS = 8;

    /**
     * 取込のコントローラ。本数が下限を下回ったら、その場で落とす（どの利用者も空振りに気づけるように）。
     *
     * @return array<string, string> プロジェクトルートからの相対パス => 絶対パス（相対パスの順）
     */
    private function importControllerFiles(): array
    {
        $files = [];

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            if (str_ends_with($file->getFilename(), 'ImportController.php')) {
                $files[str_replace(base_path() . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }

        ksort($files);

        $this->assertGreaterThanOrEqual(
            self::MIN_IMPORT_CONTROLLERS,
            count($files),
            '走査が空振りしている（*ImportController.php が少なすぎる）'
        );

        return $files;
    }
}
