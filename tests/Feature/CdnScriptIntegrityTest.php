<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CDN から読み込む `<script src>` は SRI（`integrity` + `crossorigin`）を持つこと。
 *
 * ⚠ **「直したファイルを並べる」形にしない**（Bug #45 ①）。それだと次に増える取込画面が
 *   無 SRI でも無音で通る。**全件を分類**し、legacy リストにも載らず integrity も無い
 *   `<script src>` があれば落とす。
 *
 * ⚠ **legacy は凍結リスト。** 設計 §7 の判断で「新しく増やす分にだけ SRI を付ける」ことに
 *   なっており、既存 9 本への後付けは別件（フォローアップ）。ここに**追記してはいけない**
 *   — 追記できてしまうと分類器の意味が無くなる。既存分に SRI を付けたら**行を消す**。
 *
 * ⚠ 外部ホストは `cdn.jsdelivr.net`（バンドル）と `maps.googleapis.com`（Maps ローダー）だけ許可。
 *   `cdnjs.cloudflare.com` は本番でブロックされる。Maps ローダーは**内容が動的**で
 *   ハッシュが固定できないため SRI の対象外（isGoogleMapsLoader の docblock 参照）。
 */
class CdnScriptIntegrityTest extends TestCase
{
    /**
     * SRI 導入（2026-08-17）より前からある CDN スクリプト。**凍結。追加しないこと。**
     */
    private const LEGACY_WITHOUT_SRI = [
        'dad/projects/_form.blade.php',
        'dashboard/_executive_charts.blade.php',
        'housing/_dashboard_chart.blade.php',
        'realestate/_partials/_cost_section_form.blade.php',
        'realestate/procurements/show.blade.php',
        'realestate/projects/show.blade.php',
        'tenant/analysis/index.blade.php',
        'transactions/summary.blade.php',
        'zeal/dashboard.blade.php',
    ];

    /**
     * `<script ... src="http…">` タグを全部集める。
     *
     * @return array<int, array{view: string, line: int, tag: string, src: string}>
     */
    private function externalScriptTags(): array
    {
        $found = [];
        $root  = resource_path('views');
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            if (! preg_match_all('/<script\b[^>]*>/is', $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$tag, $offset]) {
                if (! preg_match('/\bsrc="(https?:\/\/[^"]+)"/i', $tag, $srcMatch)) {
                    continue;
                }

                $found[] = [
                    'view' => str_replace($root . '/', '', $file->getPathname()),
                    'line' => substr_count(substr($src, 0, $offset), "\n") + 1,
                    'tag'  => $tag,
                    'src'  => $srcMatch[1],
                ];
            }
        }

        usort($found, fn ($a, $b) => [$a['view'], $a['line']] <=> [$b['view'], $b['line']]);

        return $found;
    }

    /** 走査が空振りして緑になる事故を防ぐ */
    public function test_the_scanner_finds_the_cdn_scripts(): void
    {
        $tags = $this->externalScriptTags();

        $this->assertGreaterThanOrEqual(19, count($tags), '外部 <script src> の走査が機能していない（空振り防止）');

        $jsDelivr = array_filter($tags, fn (array $t) => $this->isJsDelivr($t['src']));
        $this->assertGreaterThanOrEqual(10, count($jsDelivr), 'jsDelivr のスクリプトを拾えていない（空振り防止）');
    }

    public function test_only_allowed_hosts_are_used(): void
    {
        $offenders = [];

        foreach ($this->externalScriptTags() as $tag) {
            if (! $this->isJsDelivr($tag['src']) && ! $this->isGoogleMapsLoader($tag['src'])) {
                $offenders[] = $tag['view'] . ':' . $tag['line'] . ' → ' . $tag['src'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "許可されていないホストからスクリプトを読み込んでいる（cdnjs 等は本番でブロックされる）。\n"
            . implode("\n", $offenders)
        );
    }

    private function isJsDelivr(string $src): bool
    {
        return str_starts_with($src, 'https://cdn.jsdelivr.net/');
    }

    /**
     * Google Maps の bootstrap ローダー。
     * ⚠ **SRI を付けられない** — 返る JS は API キー・言語・呼び出し時点で内容が変わるため
     *   ハッシュが固定できない。SRI を要求できるのは jsDelivr のようなバージョン固定の
     *   静的ファイルだけ（設計 §7 / プラン §1-13 の判断）。
     */
    private function isGoogleMapsLoader(string $src): bool
    {
        return str_starts_with($src, 'https://maps.googleapis.com/maps/api/js');
    }

    /**
     * 全件分類: legacy に載っているか、SRI を持っているか、のどちらか。
     *
     * ⚠ `integrity` は `crossorigin="anonymous"` とセットでないと機能しない
     *   （CORS 無しのレスポンスは opaque でハッシュを検証できない）ので両方を要求する。
     */
    public function test_every_cdn_script_is_classified(): void
    {
        $unclassified = [];
        $legacyWithSri = [];

        foreach ($this->externalScriptTags() as $tag) {
            // ハッシュを固定できないローダーは対象外（理由は isGoogleMapsLoader の docblock）
            if ($this->isGoogleMapsLoader($tag['src'])) {
                continue;
            }

            $hasSri = str_contains($tag['tag'], 'integrity="sha')
                && str_contains($tag['tag'], 'crossorigin="anonymous"');

            $isLegacy = in_array($tag['view'], self::LEGACY_WITHOUT_SRI, true);

            if ($hasSri && $isLegacy) {
                $legacyWithSri[] = $tag['view'];
                continue;
            }

            if (! $hasSri && ! $isLegacy) {
                $unclassified[] = $tag['view'] . ':' . $tag['line'];
            }
        }

        $this->assertSame(
            [],
            $unclassified,
            "SRI の無い CDN スクリプトが増えている。\n"
            . "integrity=\"sha384-…\" crossorigin=\"anonymous\" を付けること。\n"
            . "ハッシュは推測せず実測する:\n"
            . "  curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A\n"
            . '該当: ' . implode(', ', $unclassified)
        );

        $this->assertSame(
            [],
            $legacyWithSri,
            'legacy リストのビューに SRI が付いた。リストから行を消すこと（凍結リストなので放置しない）: '
            . implode(', ', $legacyWithSri)
        );
    }

    /** legacy リストの行が実在すること（消えたビューが残ると分類器が緩む） */
    public function test_the_legacy_list_has_no_stale_entries(): void
    {
        $views = [];
        foreach ($this->externalScriptTags() as $tag) {
            if ($this->isJsDelivr($tag['src'])) {
                $views[] = $tag['view'];
            }
        }

        foreach (self::LEGACY_WITHOUT_SRI as $view) {
            $this->assertContains(
                $view,
                $views,
                "legacy リストの {$view} に CDN スクリプトが無い（行を消すこと）"
            );
        }
    }
}
