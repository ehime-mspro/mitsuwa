<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 取込のコントローラ（`*ImportController.php`）は、どれも確定で 1 回限りの鍵を使うこと
 * （`OneTimeAction::claimFrom(`。設計書 2026-09-28-import-double-submit-design.md §5.5）。
 *
 * ⚠ 全件分類（Top trap #13）。列挙は ImportControllerReturnPathScanTest・ImportControllerValidationRedirectScanTest と
 *   同じ `ScansImportControllers` を共用する（別々に列挙すると、片方だけ範囲が変わって見落としが生まれる）。
 *   新しい取込を足したら、鍵を使うか、下の WITHOUT_KEY に理由つきで足す。
 * ⚠ コメントを落としてから数える（docblock に `OneTimeAction::claimFrom()` と書いてあると、実体を消しても緑になる。Bug #42 ②）。
 * ⚠ 見るのは呼び出しがあることだけ。確定の入口で、ほかの検査より先に使っていることは、取込ごとの 2 回送るテストが見る
 *   （2 回目がほかの案内に着かない・書き込みが 0）。
 */
class ImportControllerOneTimeKeyScanTest extends TestCase
{
    use ScansImportControllers;

    /** 鍵を使わない取込 => 理由（2026-09-28 時点で 0 件） */
    private const WITHOUT_KEY = [];

    public function test_every_import_controller_claims_a_one_time_key(): void
    {
        $missing = [];

        foreach ($this->importControllerFiles() as $relative => $path) {
            if (array_key_exists($relative, self::WITHOUT_KEY)) {
                continue;
            }
            if (! str_contains($this->withoutComments($path), 'OneTimeAction::claimFrom(')) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing, "確定で 1 回限りの鍵を使っていない取込がある:\n" . implode("\n", $missing));
    }

    public function test_the_exceptions_are_real_files(): void
    {
        $files = $this->importControllerFiles();

        foreach (array_keys(self::WITHOUT_KEY) as $relative) {
            $this->assertArrayHasKey($relative, $files, "WITHOUT_KEY の {$relative} が無い（分類が古い）");
        }
    }

    private function withoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(File::get($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
