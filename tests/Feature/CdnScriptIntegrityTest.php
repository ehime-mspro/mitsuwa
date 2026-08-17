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
 * ⚠ **2026-08-17 に legacy は空になった**（SheetJS 4 本 → Chart.js 5 本の順で潰した）。
 *   例外がゼロになったので、jsDelivr のスクリプトには
 *   **① SRI が付いている ② ハッシュが `PINNED_SRI` に実測値として載っている**
 *   の両方を課している。ここに**追記してはいけない** — 追記できてしまうと分類器の意味が無くなる。
 *
 * ⚠ **分類器は `integrity` の有無しか見ない。値の正しさは `PINNED_SRI` 側で固定する**
 *   （値が違うとブラウザだけが黙ってスクリプトを実行しなくなる。Bug #28 / #43 と同型）。
 *
 * ⚠ **jsDelivr の「動的生成ファイル」に SRI を付けてはいけない。**
 *   `chart.umd.min.js` は npm に実在せず（unpkg で 404）jsDelivr がその場で作る物で、
 *   生成物の先頭バナー自身が `Do NOT use SRI with dynamically generated files!` と警告する。
 *   publisher が実際に配っているファイル（`chart.umd.js`）を使うこと。
 *   実ファイルなら **jsdelivr と unpkg でハッシュ一致を確認できる**＝ CDN 片側の改竄でない
 *   裏取りが取れるが、生成物ではそれが原理的に取れない。
 *
 * ⚠ 外部ホストは `cdn.jsdelivr.net`（バンドル）と `maps.googleapis.com`（Maps ローダー）だけ許可。
 *   `cdnjs.cloudflare.com` は本番でブロックされる。Maps ローダーは**内容が動的**で
 *   ハッシュが固定できないため SRI の対象外（isGoogleMapsLoader の docblock 参照）。
 */
class CdnScriptIntegrityTest extends TestCase
{
    /**
     * SRI 導入（2026-08-17）より前からあり、まだ SRI を付けていない CDN スクリプト。
     *
     * **2026-08-17 に全件を潰して空になった**（SheetJS 4 本 → Chart.js 5 本）。
     * **空のまま維持すること。ここへ追記してはいけない** — 追記できてしまうと
     * 「SRI が無くても通る」抜け道が復活し、分類器の意味が無くなる。
     *
     * ⚠ キーは `ビュー|src` の組（ビュー名だけで凍結すると、そのビューは将来ぶんも含めて
     * 永久に無検査になる。実測でそうなった）。件数まで見るため多重集合として突き合わせる。
     *
     * @var list<string>
     */
    private const LEGACY_WITHOUT_SRI = [];

    /**
     * 実測済みのハッシュ。**src ごとに 1 つだけ正解がある。**
     *
     * ⚠ **分類器（`test_every_cdn_script_is_classified`）は `integrity` の "有無" しか見ない。**
     *   値が間違っていてもそこは緑のまま通り、ブラウザだけが黙ってスクリプトを実行しなくなる
     *   （Bug #28 / #43 と同じ「HTML には出るが実行時に効かない」型）。同じ URL を複数ビューへ
     *   手で貼る以上「1 箇所だけ打ち間違える」は現実的な事故なので、値を固定する。
     *
     * ⚠ **legacy が空になったので、jsDelivr のスクリプトは全件ここに載っていることを要求する**
     *   （`test_every_jsdelivr_script_is_pinned`）。「SRI は付けたがハッシュを実測していない」
     *   状態を原理的に作れなくするため。バージョンを上げるときは必ず実測してから貼る。
     *
     * 実測手順（2026-08-17 に全エントリで実施）:
     *   curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A
     *
     * ⚠ **`unpkg` の同版とも突き合わせること**（CDN 片側の改竄でないことの裏取り）。
     *   裏取りが取れない URL ＝ jsDelivr の動的生成物なので、そもそも使ってはいけない
     *   （クラス冒頭の警告を参照）。
     */
    private const PINNED_SRI = [
        'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js'
            => 'sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js'
            => 'sha384-dug+JxfBvklEQdJ4AYuBBAIScUz0bVN73xpy273gcAwHjb3qI0fXmuYNaNfdyYJG',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js'
            => 'sha384-zYPBGXwO4633CABX/5Spf6emCKUJCfoOkhOMYyxMsatqQZPnDblmmOewfjsIVWCM',
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
                // ⚠ 二重引用符＋絶対 URL だけを見ると、単引用符や protocol-relative（//host/…）が
                //   素通りする（Bug #45 ③ と同型）。実測では **単引用符の cdnjs** が
                //   ホスト検査ごとすり抜けていた。
                if (! preg_match('/\bsrc=["\']((?:https?:)?\/\/[^"\']+)["\']/i', $tag, $srcMatch)) {
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

        // ⚠ 現値ちょうど（19 / 10）にしない。正当な掃除で誤って赤になる
        //   （house style: AjaxErrorFeedbackTest は実数 33 に対し下限 30）
        $this->assertGreaterThanOrEqual(15, count($tags), '外部 <script src> の走査が機能していない（空振り防止）');

        $jsDelivr = array_filter($tags, fn (array $t) => $this->isJsDelivr($t['src']));
        $this->assertGreaterThanOrEqual(8, count($jsDelivr), 'jsDelivr のスクリプトを拾えていない（空振り防止）');
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

    /** protocol-relative（`//host/…`）も同じホストとして扱う */
    private function host(string $src): string
    {
        return preg_match('#^(?:https?:)?//([^/?\#]+)#i', $src, $m) ? strtolower($m[1]) : '';
    }

    private function isJsDelivr(string $src): bool
    {
        return $this->host($src) === 'cdn.jsdelivr.net';
    }

    /**
     * Google Maps の bootstrap ローダー。
     * ⚠ **SRI を付けられない** — 返る JS は API キー・言語・呼び出し時点で内容が変わるため
     *   ハッシュが固定できない。SRI を要求できるのは jsDelivr のようなバージョン固定の
     *   静的ファイルだけ（設計 §7 / プラン §1-13 の判断）。
     */
    private function isGoogleMapsLoader(string $src): bool
    {
        return $this->host($src) === 'maps.googleapis.com' && str_contains($src, '/maps/api/js');
    }

    /**
     * 全件分類: legacy に載っているか、SRI を持っているか、のどちらか。
     *
     * ⚠ `integrity` は `crossorigin="anonymous"` とセットでないと機能しない
     *   （CORS 無しのレスポンスは opaque でハッシュを検証できない）ので両方を要求する。
     */
    public function test_every_cdn_script_is_classified(): void
    {
        $unclassified  = [];
        $legacyWithSri = [];

        // 多重集合として消し込む（同じ URL が 2 本あるケースも数える）
        $remaining = array_count_values(self::LEGACY_WITHOUT_SRI);

        foreach ($this->externalScriptTags() as $tag) {
            // ハッシュを固定できないローダーは対象外（理由は isGoogleMapsLoader の docblock）
            if ($this->isGoogleMapsLoader($tag['src'])) {
                continue;
            }

            $hasSri = str_contains($tag['tag'], 'integrity="sha')
                && str_contains($tag['tag'], 'crossorigin="anonymous"');

            $key      = $tag['view'] . '|' . $tag['src'];
            $isLegacy = ($remaining[$key] ?? 0) > 0;

            if ($isLegacy) {
                $remaining[$key]--;
            }

            if ($hasSri && $isLegacy) {
                $legacyWithSri[] = $key;
                continue;
            }

            if (! $hasSri && ! $isLegacy) {
                $unclassified[] = $tag['view'] . ':' . $tag['line'] . ' → ' . $tag['src'];
            }
        }

        $this->assertSame(
            [],
            array_keys(array_filter($remaining)),
            'legacy リストに実在しない行が残っている（消したビューの行を削ること）'
        );

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

    /**
     * SRI の**値**が正しいこと。分類器（有無だけ）では原理的に検出できない穴を塞ぐ。
     *
     * 2 段で見る:
     *   ① PINNED_SRI に載っている src は、その値と**完全一致**すること
     *   ② 同じ src が複数ビューにあるなら、**全部が同じ値**であること
     *      （②は PINNED_SRI に無い src でも効く。1 箇所だけ打ち間違える事故を拾う）
     */
    public function test_pinned_integrity_values_match(): void
    {
        $wrong   = [];
        $bySrc   = [];
        $checked = 0;

        foreach ($this->externalScriptTags() as $tag) {
            if (! preg_match('/\bintegrity=["\']([^"\']+)["\']/i', $tag['tag'], $m)) {
                continue;
            }

            $actual = $m[1];
            $where  = $tag['view'] . ':' . $tag['line'];
            $checked++;

            $bySrc[$tag['src']][$actual][] = $where;

            $expected = self::PINNED_SRI[$tag['src']] ?? null;
            if ($expected !== null && $actual !== $expected) {
                $wrong[] = "{$where} → 期待 {$expected} / 実際 {$actual}";
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（SheetJS 5 本ぶんは必ずある）
        $this->assertGreaterThanOrEqual(
            5,
            $checked,
            'integrity を持つスクリプトを 1 つも拾えていない（走査が壊れている）'
        );

        $this->assertSame(
            [],
            $wrong,
            "SRI の値が実測値と違う。ブラウザはこのスクリプトを黙って実行しない。\n"
            . "打ち直さず PINNED_SRI からコピー&ペーストすること。\n"
            . implode("\n", $wrong)
        );

        $split = [];
        foreach ($bySrc as $src => $byValue) {
            if (count($byValue) > 1) {
                $split[] = $src . ' → ' . implode(' / ', array_map(
                    fn (string $v, array $w) => $v . '(' . implode(',', $w) . ')',
                    array_keys($byValue),
                    $byValue
                ));
            }
        }

        $this->assertSame(
            [],
            $split,
            "同じ URL なのに integrity の値が食い違っている（どれかが打ち間違い）:\n" . implode("\n", $split)
        );
    }

    /**
     * legacy リストの行が実在すること（消えたビューが残ると分類器が緩む）。
     *
     * ⚠ **`foreach` + `assertContains` で書かない。** legacy が空になった今、
     *   ループが 1 周も回らず**アサーション 0 本の risky テスト**に縮退してしまう
     *   （「緑」に見えるが何も測っていない）。差集合を取って**必ず 1 本**アサートする。
     */
    public function test_the_legacy_list_has_no_stale_entries(): void
    {
        $keys = [];
        foreach ($this->externalScriptTags() as $tag) {
            if ($this->isJsDelivr($tag['src'])) {
                $keys[] = $tag['view'] . '|' . $tag['src'];
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff(self::LEGACY_WITHOUT_SRI, $keys)),
            'legacy リストに実在しない行が残っている（消したビューの行を削ること）'
        );
    }

    /**
     * jsDelivr のスクリプトは全件 `PINNED_SRI` に載っていること。
     *
     * legacy が空になり例外がゼロになったので課せるようになった不変条件。
     * これで「SRI は付いているが**ハッシュを実測していない**」状態が原理的に作れない。
     *
     * ⚠ jsDelivr の**動的生成 URL**（`chart.umd.min.js` 等）は実測の裏取りが取れないので
     *   `PINNED_SRI` に載せられない＝このテストが自動的に弾く。
     *   「min のほうが速そう」と URL を戻す変更を止めるのが主目的（クラス冒頭の警告を参照）。
     */
    public function test_every_jsdelivr_script_is_pinned(): void
    {
        $unpinned = [];
        $seen     = 0;

        foreach ($this->externalScriptTags() as $tag) {
            if (! $this->isJsDelivr($tag['src'])) {
                continue;
            }
            $seen++;
            if (! array_key_exists($tag['src'], self::PINNED_SRI)) {
                $unpinned[] = $tag['view'] . ':' . $tag['line'] . ' → ' . $tag['src'];
            }
        }

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThanOrEqual(8, $seen, 'jsDelivr のスクリプトを拾えていない（空振り防止）');

        $this->assertSame(
            [],
            $unpinned,
            "jsDelivr のスクリプトが PINNED_SRI に載っていない。\n"
            . "ハッシュを実測し、unpkg の同版とも突き合わせてから PINNED_SRI へ追加すること:\n"
            . "  curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A\n"
            . "⚠ unpkg で 404 になる URL は jsDelivr の動的生成物。SRI を付けてはいけない\n"
            . '該当: ' . implode(', ', $unpinned)
        );
    }
}
