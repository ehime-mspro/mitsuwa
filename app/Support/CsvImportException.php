<?php

namespace App\Support;

/**
 * CSV の書式が受け付けられないときに投げる。
 *
 * `getMessage()` はそのまま画面に出る日本語なので、英語のメッセージを入れないこと
 * （コントローラが `back()->with('error', $e->getMessage())` で表示する）。
 */
final class CsvImportException extends \RuntimeException
{
}
