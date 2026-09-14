<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * `app/Models` のリレーションを機械的に拾う走査の部品（全件分類の構造テスト用。Top trap #13）。
 *
 * ⚠ 2026-09-14 に `tests/Feature/Tenant/DeletedUnitReferenceTest.php` から**そのまま**切り出した
 *   （区画を指すリレーションの分類と、物件のリレーションの分類で同じ走査が要るため）。
 *   複製すると drift するので、中身を変えるときは両方の利用者で測り直すこと。
 */
trait ScansModelRelations
{
    /** @return list<class-string<Model>> */
    private function modelClasses(): array
    {
        $classes = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** メソッドの本体（docblock を含まない）からコメントを落とした文字列（注意書きの Unit::class に反応しないように。Bug #42 ②） */
    private function methodSourceWithoutComments(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $code = '';
        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
