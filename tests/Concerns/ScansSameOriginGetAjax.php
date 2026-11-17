<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * 画面（resources/views の Blade）の、自社へ GET する fetch・XMLHttpRequest を拾う走査の部品。
 * AjaxFetchSessionGuardTest（X-Requested-With を送るか）と GetAjaxFailureScanTest（断られたときに部品を通すか）が使う。
 *
 * 決まり: fetch は `://` を含む（外部 API。zipcloud など）ものと、`method:` が文字の GET でないものを除く
 * （`method:` を書かないものは GET。変数の `method: method` は GET 以外とみなす）。
 * XMLHttpRequest は `.open('GET', …)` のうち、同じ行に `://` を含まないもの。
 */
trait ScansSameOriginGetAjax
{
    /**
     * `fetch(` の呼び出し全体を括弧の対応で切り出す。
     *
     * ⚠ 「最初の `.then(` まで」で切ってはいけない。`Promise.all` 内の `await fetch(…)`
     *    には近くに `.then(` が無く、固定長のフォールバックに落ちて**別のハンドラの**
     *    `X-Requested-With` を拾い、**誤って緑になる**（mansion/contracts/create が該当）。
     */
    protected static function fetchCall(string $source, int $pos): string
    {
        $depth = 0;

        for ($i = $pos + 5, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $pos, $i - $pos + 1);
                }
            }
        }

        return substr($source, $pos, 500);
    }

    /**
     * `$pos` の直前 `$n` 行を返す。
     *
     * ⚠ `var url = '{{ url("/api/…") }}';` と組み立ててから `fetch(url, …)` する形は、
     *    呼び出し本体だけを見ても URL が分からず**原理的に拾えない**
     *    （housing/properties/_form と mansion の計 4 箇所が該当）。
     *    URL の判定材料としてのみ使い、ヘッダーの有無は呼び出し本体だけで判定する。
     */
    protected static function precedingLines(string $source, int $pos, int $n): string
    {
        $start = $pos;

        for ($i = 0; $i <= $n; $i++) {
            $prev = strrpos(substr($source, 0, $start), "\n");
            if ($prev === false) {
                return substr($source, 0, $pos);
            }
            $start = $prev;
        }

        return substr($source, $start, $pos - $start);
    }

    /**
     * `$source`（Blade 1 本）の、自社へ GET する fetch。
     *
     * @return array<int, array{pos: int, line: int, call: string}>
     */
    protected static function sameOriginGetFetchesIn(string $source): array
    {
        $found = [];
        $offset = 0;

        while (($pos = strpos($source, 'fetch(', $offset)) !== false) {
            $offset = $pos + 6;

            $call = self::fetchCall($source, $pos);
            if (str_contains(self::precedingLines($source, $pos, 5) . $call, '://')) {
                continue;   // 外部 API（zipcloud）
            }
            if (preg_match('/method:\s*[\'"](?!GET)/', $call)) {
                continue;   // GET 以外は storeCurrentUrl の対象外
            }

            $found[] = ['pos' => $pos, 'line' => substr_count(substr($source, 0, $pos), "\n") + 1, 'call' => $call];
        }

        return $found;
    }

    /**
     * `$source`（Blade 1 本）の、自社へ GET する XMLHttpRequest。`block` は `.open(` から次の `.send(` まで。
     *
     * @return array<int, array{pos: int, line: int, block: string}>
     */
    protected static function sameOriginGetXhrsIn(string $source): array
    {
        $found = [];
        preg_match_all('/\.open\(\s*([\'"])GET\1\s*,([^\n]*)/i', $source, $opens, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($opens as $open) {
            if (str_contains($open[2][0], '://')) {
                continue;   // 外部 API（zipcloud）
            }
            $pos = $open[0][1];
            $send = strpos($source, '.send(', $pos);
            $found[] = [
                'pos'   => $pos,
                'line'  => substr_count(substr($source, 0, $pos), "\n") + 1,
                'block' => $send === false ? substr($source, $pos) : substr($source, $pos, $send - $pos),
            ];
        }

        return $found;
    }

    /**
     * 全ビューの、自社へ GET する fetch（`file` は resources/views からの相対パス）。
     *
     * @return array<int, array{file: string, line: int, pos: int, source: string, call: string}>
     */
    protected static function sameOriginGetFetches(): array
    {
        $found = [];
        foreach (self::bladeSources() as $file => $source) {
            foreach (self::sameOriginGetFetchesIn($source) as $fetch) {
                $found[] = ['file' => $file, 'source' => $source] + $fetch;
            }
        }

        return $found;
    }

    /**
     * 全ビューの、自社へ GET する XMLHttpRequest。
     *
     * @return array<int, array{file: string, line: int, pos: int, source: string, block: string}>
     */
    protected static function sameOriginGetXhrOpens(): array
    {
        $found = [];
        foreach (self::bladeSources() as $file => $source) {
            foreach (self::sameOriginGetXhrsIn($source) as $xhr) {
                $found[] = ['file' => $file, 'source' => $source] + $xhr;
            }
        }

        return $found;
    }

    /** @return array<string, string> resources/views からの相対パス => 中身 */
    protected static function bladeSources(): array
    {
        $sources = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $sources[$file->getRelativePathname()] = file_get_contents($file->getPathname());
            }
        }
        ksort($sources);

        return $sources;
    }
}
