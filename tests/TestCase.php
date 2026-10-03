<?php

namespace Tests;

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
}
