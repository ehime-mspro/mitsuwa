<?php

namespace App\Console\Commands\Concerns;

/**
 * 画面の案内に出す artisan コマンドの文字列を組み立てる（ops:mail-test と ops:backup-restore で共通）。
 *
 * さくらのレンタルサーバーで `php` とだけ打つと既定の PHP 7.4 が動いてしまい、
 * 「Your Composer dependencies require a PHP version ">= 8.3.0"」のような英語のエラーで止まる。
 * 画面の案内をそのまま打つ非エンジニアには分かりにくいため、今このコマンドを動かしている
 * PHP の場所（PHP_BINARY。本番なら /usr/local/php/8.3/bin/php）から組み立てる。
 */
trait SuggestsArtisanCommands
{
    protected function artisanCommand(string $arguments): string
    {
        return PHP_BINARY.' artisan '.$arguments;
    }
}
