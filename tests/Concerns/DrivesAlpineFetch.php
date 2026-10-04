<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * 描いた画面の JavaScript（Alpine のコンポーネント）を node の vm で動かし、**JS が実際に組んだ要求**を
 * 取り出して PHP から送る（Ajax の画面の往復。Bug #47 の Ajax 版）。
 *
 * ⚠ PHP のテストからブラウザの JS は動かせない。要求を手で組むと、画面の JS からヘッダー
 *   （X-Requested-With。Top trap #9）や本文の項目が消えても緑のままになる。ここでは画面に描かれた
 *   `<script>` をそのまま読み込み、`fetch` だけを差し替えて、URL・メソッド・ヘッダー・本文を記録する。
 * ⚠ 返ってきた応答は、もう一度 JS に渡して画面の状態（一覧の行・メッセージ）まで見る
 *   （driveAlpine() の $responses）。要求ごとに 2 回動かす: 1 回目で要求を取り出し、2 回目で応答を渡す。
 *
 * 偽物にしているもの: document.querySelector（`meta[name="csrf-token"]` だけ。描いた画面の値を返し、
 * 画面に無ければ null）・confirm（$confirm を返し、文言を記録）・setTimeout / clearTimeout（何もしない。
 * 5 秒後にメッセージを消すタイマーで node が止まらないように）・コンポーネントの $nextTick（すぐ呼ぶ）と $refs（空）。
 * 前例: SubmitOnceTest::runInNode()（node が無ければ飛ばす）。
 */
trait DrivesAlpineFetch
{
    /**
     * 画面の x-data の式（例 `zealStoreManager()`）を取り出す。引数つきの式もそのまま返す（属性の実体参照は戻す）。
     */
    protected function xData(string $html, string $function): string
    {
        $found = preg_match_all('/\bx-data="(' . preg_quote($function, '/') . '\([^"]*\))"/u', $html, $m);
        $this->assertSame(1, $found, "x-data=\"{$function}(…)\" がちょうど 1 つ描かれていない");

        return html_entity_decode($m[1][0], ENT_QUOTES, 'UTF-8');
    }

    /**
     * $html に描かれた `<script>` のうち `function $function(` を定義するもの（ちょうど 1 つ）を node で読み込み、
     * $factory でコンポーネントを作って $steps を走らせる。
     *
     * @param  string  $steps  `data`（コンポーネント）を操る JS。例 `data.startAdd(); data.newName = '新店'; data.submitAdd();`
     * @param  array<int, array{status: int, body: mixed}>  $responses  fetch に順に返す応答。足りなければ要求は保留のまま
     * @return array{requests: array<int, array{url: string, method: string, headers: array<string, string>, body: ?string}>, confirms: array<int, string>, state: array<string, mixed>}
     */
    protected function driveAlpine(string $html, string $function, string $factory, string $steps, array $responses = [], bool $confirm = true): array
    {
        return $this->runNode([
            'script'    => $this->scriptDefining($html, $function),
            'csrf'      => preg_match('/<meta name="csrf-token" content="([^"]*)"/', $html, $m) ? $m[1] : null,
            'factory'   => $factory,
            'steps'     => $steps,
            'responses' => $responses,
            'confirm'   => $confirm,
        ], self::ALPINE_HARNESS);
    }

    /**
     * driveAlpine() が取り出した要求を、そのまま送る（メソッド・URL・ヘッダー・本文）。
     *
     * @param  array{url: string, method: string, headers: array<string, string>, body: ?string}  $request
     */
    protected function sendCaptured(array $request): TestResponse
    {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        parse_str((string) $request['body'], $params);

        return $this->call($request['method'], $request['url'], $params, [], [], $server);
    }

    /** 応答を driveAlpine() の $responses の 1 件にする */
    protected function asFetchResponse(TestResponse $response): array
    {
        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    /**
     * インラインのイベント属性（onsubmit など）の中身を、ブラウザと同じく関数の本体として組み立てて呼ぶ。
     * $code は属性の値を実体参照を戻したもの（ブラウザが JS に渡す文字列）。
     *
     * @return array{compiled: bool, error: ?string, returned: mixed, confirms: array<int, string>}
     */
    protected function runInlineHandler(string $code, bool $confirm): array
    {
        return $this->runNode(['code' => $code, 'confirm' => $confirm], self::INLINE_HARNESS);
    }

    private function scriptDefining(string $html, string $function): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $scripts);
        $hits = array_values(array_filter($scripts[1], fn (string $s) => str_contains($s, "function {$function}(")));
        $this->assertCount(1, $hits, "function {$function}( を定義する <script> がちょうど 1 つ描かれていない");

        return $hits[0];
    }

    private function runNode(array $input, string $harness): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので画面の JavaScript の実駆動を飛ばす');
        }

        $file = tempnam(sys_get_temp_dir(), 'alpine-fetch-');
        try {
            file_put_contents($file, json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で画面の JavaScript を動かせなかった:\n" . $output);

        return $result;
    }

    private const ALPINE_HARNESS = <<<'JS'
        const fs = require('fs');
        const vm = require('vm');
        const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
        const requests = [];
        const confirms = [];
        let next = 0;
        const context = vm.createContext({
            URLSearchParams,
            console,
            setTimeout() { return 0; },
            clearTimeout() {},
            confirm(message) { confirms.push(String(message)); return input.confirm; },
            document: {
                querySelector(selector) {
                    if (selector === 'meta[name="csrf-token"]' && input.csrf !== null) {
                        return { getAttribute(name) { return name === 'content' ? input.csrf : null; } };
                    }
                    return null;
                },
            },
            fetch(url, options) {
                options = options || {};
                const body = options.body;
                requests.push({
                    url: String(url),
                    method: (options.method || 'GET').toUpperCase(),
                    headers: Object.assign({}, options.headers || {}),
                    body: body === undefined || body === null ? null : String(body),
                });
                const response = input.responses[next++];
                if (!response) {
                    return new Promise(function () {});
                }
                return Promise.resolve({
                    ok: response.status >= 200 && response.status < 300,
                    status: response.status,
                    json() { return Promise.resolve(response.body); },
                });
            },
        });
        vm.runInContext(input.script, context, { filename: 'page-script.js' });
        const data = vm.runInContext(input.factory, context);
        data.$nextTick = function (fn) { fn.call(data); };
        data.$refs = {};
        vm.runInContext('(function (data) {\n' + input.steps + '\n})', context)(data);
        (async function () {
            for (let i = 0; i < 20; i++) {
                await new Promise(function (resolve) { setImmediate(resolve); });
            }
            process.stdout.write(JSON.stringify({ requests: requests, confirms: confirms, state: JSON.parse(JSON.stringify(data)) }));
        })();
        JS;

    private const INLINE_HARNESS = <<<'JS'
        const fs = require('fs');
        const vm = require('vm');
        const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
        const confirms = [];
        const context = vm.createContext({
            confirm(message) { confirms.push(String(message)); return input.confirm; },
        });
        let handler;
        try {
            handler = vm.runInContext('(function (event) {\n' + input.code + '\n})', context);
        } catch (e) {
            process.stdout.write(JSON.stringify({ compiled: false, error: String(e), returned: null, confirms: confirms }));
            process.exit(0);
        }
        const returned = handler.call({}, {});
        process.stdout.write(JSON.stringify({ compiled: true, error: null, returned: returned === undefined ? null : returned, confirms: confirms }));
        JS;
}
