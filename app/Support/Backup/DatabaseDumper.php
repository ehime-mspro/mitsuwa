<?php

namespace App\Support\Backup;

interface DatabaseDumper
{
    /**
     * データベース全体（テーブル定義とデータ）を SQL ファイルに書き出す。
     */
    public function dumpTo(string $path): void;
}
