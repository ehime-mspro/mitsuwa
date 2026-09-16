<?php

namespace Tests\Unit\Support;

use App\Support\LoginQrCode;
use PHPUnit\Framework\TestCase;

/**
 * ログイン画面の QR（設計書 §5.12・D4）。
 *
 * ⚠ 1 枚あたり約 10KB あるので、案内のページでは `<symbol>` を 1 つ置いて各ページは `<use>` で
 *   参照する（200 人ぶんを丸ごと繰り返すと約 1.9MB。2026-09-16 実測）。
 *   そのため部品は `<svg>` 丸ごとではなく **viewBox と中身**を返す。
 */
class LoginQrCodeTest extends TestCase
{
    public function test_it_returns_a_viewbox_and_inner_markup(): void
    {
        $parts = LoginQrCode::symbolParts('https://example.com/system/manage/index.php/login');

        $this->assertMatchesRegularExpression('/\A0 0 \d+ \d+\z/', $parts['viewBox'], 'viewBox の形が想定と違う');
        $this->assertStringStartsWith('<path', $parts['inner'], '中身が <path> で始まらない');
        $this->assertStringNotContainsString('<svg', $parts['inner'], '外側の <svg> が残っている');
        $this->assertStringNotContainsString('<script', $parts['inner']);
        $this->assertStringNotContainsString('<?xml', $parts['inner']);
    }

    /** 同じ URL なら同じ絵（ページごとに作り直さない） */
    public function test_it_is_deterministic(): void
    {
        $a = LoginQrCode::symbolParts('https://example.com/login');
        $b = LoginQrCode::symbolParts('https://example.com/login');

        $this->assertSame($a, $b);
    }

    public function test_different_urls_produce_different_codes(): void
    {
        $a = LoginQrCode::symbolParts('https://example.com/login');
        $b = LoginQrCode::symbolParts('https://example.com/other');

        $this->assertNotSame($a['inner'], $b['inner']);
    }

    /**
     * 誤り訂正は M（15%）。
     *
     * ⚠ `<symbol>` ＋ `<use>` にしたので QR の実体はページ全体で 1 回しか出ない
     *   ＝ 訂正レベルを上げても 200 人ぶんの大きさはほとんど変わらない。紙は折れる。
     */
    public function test_it_uses_medium_error_correction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Support/LoginQrCode.php');

        $this->assertStringContainsString('EccLevel::M', $source, '訂正レベルが M でない（紙のかすれ・折れに弱くなる）');
    }

    /** 長い URL でも通る（バージョンが自動で上がる） */
    public function test_a_long_url_still_works(): void
    {
        $parts = LoginQrCode::symbolParts('https://example.com/' . str_repeat('a', 200));

        $this->assertStringStartsWith('<path', $parts['inner']);
    }
}
