<?php

namespace Tests;

use Carbon\AbstractTranslator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI では npm run build を行わず Vite manifest が存在しないため、
        // @vite ディレクティブを no-op 化する
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // ⚠ Carbon の Laravel 用のプロバイダ（Carbon\Laravel\ServiceProvider）は、アプリを起動するたびに
        //   setFallbackLocale() を呼び、日本語の文の全部（約 13 KB）を共有の翻訳に addResource() で 6 回足す。
        //   捨てる仕組みが無いので、1 リクエスト 1 回の起動の本番では害が無いが、テストは 1 本ごとに起動するので
        //   1 本あたり約 65 KB ずつ増え続けていた（2026-10-01 の実測: 全件 3144 本のピーク 505.50 MB → 307.50 MB）。
        //   resetMessages() は足した文を最初の 1 つまで戻す（次の起動で読み直す）。CarbonTranslatorTrimTest が守る
        $translator = Carbon::getTranslator();
        if ($translator instanceof AbstractTranslator) {
            $translator->resetMessages();
        }
    }
}
