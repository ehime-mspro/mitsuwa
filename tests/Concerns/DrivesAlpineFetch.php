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
 * 偽物にしているもの: document.querySelector（`meta[name="csrf-token"]` だけ。描いた画面の値を `content` と
 * getAttribute で返し、画面に無ければ null）・document.querySelectorAll（空）・confirm（$confirm を返し、文言を記録）・
 * alert（文言を記録）・setTimeout / clearTimeout（何もしない。5 秒後にメッセージを消すタイマーで node が止まらないように）・
 * XMLHttpRequest（fetch と同じく要求を記録し、$responses を onload で返す）・window.location（href への代入と reload() を記録）・
 * コンポーネントの $nextTick（すぐ呼ぶ）と $refs。
 *
 * ⚠ $refs: 画面の `<form x-ref="…">` の `submit()` を呼ぶと、**そのとき**のフォームのバインド（`x-bind:action` /
 *   `x-bind:value`・`x-model`）を JS の状態で評価して `submitted` に記録する（ブラウザが送るのと同じ値）。
 *   送られたフォームは sendSubmitted() でそのまま送る。入力が空で JS が送らなければ `submitted` は空のまま。
 * ⚠ ParsesForms と一緒に使う（フォームの属性を ParsesForms::htmlAttr() で読む）。
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
     * @param  array<int, array{status: int, body: mixed}>  $responses  fetch / XMLHttpRequest に順に返す応答。足りなければ要求は保留のまま
     * @param  array<int, string>  $evaluate  $steps のあとに JS の状態で評価する式（結果は `evaluated` に同じ順で入る）
     * @return array{requests: array<int, array{url: string, method: string, headers: array<string, string>, body: ?string}>, confirms: array<int, string>, alerts: array<int, string>, navigations: array<int, string>, submitted: array<int, array{ref: string, method: string, action: string, fields: array<string, string>}>, evaluated: array<int, mixed>, state: array<string, mixed>}
     */
    protected function driveAlpine(string $html, string $function, string $factory, string $steps, array $responses = [], bool $confirm = true, array $evaluate = []): array
    {
        return $this->runNode([
            'script'    => $this->scriptDefining($html, $function),
            'csrf'      => preg_match('/<meta name="csrf-token" content="([^"]*)"/', $html, $m) ? $m[1] : null,
            'factory'   => $factory,
            'steps'     => $steps,
            'responses' => $responses,
            'confirm'   => $confirm,
            'forms'     => (object) $this->refForms($html),
            'evaluate'  => $evaluate,
        ], self::ALPINE_HARNESS);
    }

    /**
     * driveAlpine() が取り出した要求を、そのまま送る（メソッド・URL・ヘッダー・本文）。
     * 本文が JSON（`Content-Type: application/json`）ならそのまま送り、それ以外はフォームの項目として送る。
     *
     * @param  array{url: string, method: string, headers: array<string, string>, body: ?string}  $request
     */
    protected function sendCaptured(array $request): TestResponse
    {
        $server = [];
        $json = false;
        foreach ($request['headers'] as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            if (strtolower($name) === 'content-type' && str_contains(strtolower($value), 'application/json')) {
                $json = true;
            }
        }
        if ($json) {
            $server['CONTENT_TYPE'] = 'application/json';

            return $this->call($request['method'], $request['url'], [], [], [], $server, (string) $request['body']);
        }
        parse_str((string) $request['body'], $params);

        return $this->call($request['method'], $request['url'], $params, [], [], $server);
    }

    /**
     * driveAlpine() の `submitted` の 1 件（JS が `$refs.….submit()` で送ったフォーム）を、ブラウザと同じように送る
     * （`_method` で PUT / DELETE へ化ける）。
     *
     * @param  array{ref: string, method: string, action: string, fields: array<string, string>}  $form
     */
    protected function sendSubmitted(array $form): TestResponse
    {
        return $this->call(strtoupper($form['method']) === 'GET' ? 'GET' : 'POST', $form['action'], $form['fields']);
    }

    /**
     * 画面が描いたフォーム（ParsesForms::parseForm() と同じ探し方）の項目のうち、Alpine が値を入れるもの
     * （`x-model` / `x-bind:value` / `:value`）を、$steps のあとの JS の状態で評価して入れたフォームを返す。
     * ⚠ ParsesForms と一緒に使う。ブラウザでは描いた直後に Alpine が値を入れるので、静的な `value` だけでは送る値にならない。
     *
     * @return array{method: string, action: string, fields: array<string, string>}
     */
    protected function alpineForm(string $html, string $needle, string $function, string $steps = ''): array
    {
        $form = $this->parseForm($html, $needle);

        $pos = strpos($html, $needle);
        $open = strrpos(substr($html, 0, $pos), '<form');
        $body = substr($html, $open, strpos($html, '</form>', $pos) - $open);
        $names = [];
        $exprs = [];
        preg_match_all('/<input\b[^>]*>/i', $body, $inputs);
        foreach ($inputs[0] as $tag) {
            $name = $this->htmlAttr($tag, 'name');
            $expr = $this->boundAttr($tag, 'value') ?? $this->htmlAttr($tag, 'x-model');
            if ($name !== null && $expr !== null) {
                $names[] = $name;
                $exprs[] = $expr;
            }
        }
        if ($exprs === []) {
            return $form;
        }

        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps, [], true, $exprs);
        foreach ($names as $i => $name) {
            $value = $run['evaluated'][$i];
            $form['fields'][$name] = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return $form;
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

    /**
     * `<form x-ref="…">` ごとに、送るときに要るもの（メソッド・送り先・項目）と、Alpine が値を入れる式を集める。
     * 項目の値は静的な `value`、または `x-bind:value` / `:value` / `x-model` の式（JS が submit() したときに評価する）。
     *
     * @return array<string, array{method: string, action: string, actionExpr: ?string, fields: array<int, array{name: string, value: string, expr: ?string}>}>
     */
    private function refForms(string $html): array
    {
        preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/s', $html, $forms, PREG_SET_ORDER);
        $found = [];
        foreach ($forms as $form) {
            $open = '<form' . $form[1] . '>';
            $ref = $this->htmlAttr($open, 'x-ref');
            if ($ref === null) {
                continue;
            }
            $fields = [];
            preg_match_all('/<input\b[^>]*>/i', $form[2], $inputs);
            foreach ($inputs[0] as $tag) {
                $name = $this->htmlAttr($tag, 'name');
                if ($name === null) {
                    continue;
                }
                $fields[] = [
                    'name'  => $name,
                    'value' => $this->htmlAttr($tag, 'value') ?? '',
                    'expr'  => $this->boundAttr($tag, 'value') ?? $this->htmlAttr($tag, 'x-model'),
                ];
            }
            $found[$ref] = [
                'method'     => strtoupper($this->htmlAttr($open, 'method') ?? 'GET'),
                'action'     => $this->htmlAttr($open, 'action') ?? '',
                'actionExpr' => $this->boundAttr($open, 'action'),
                'fields'     => $fields,
            ];
        }

        return $found;
    }

    /** `x-bind:name="…"` / `:name="…"` の式（無ければ null） */
    private function boundAttr(string $tag, string $name): ?string
    {
        $pattern = '/(?:x-bind:|(?<![\w:.@-]):)' . preg_quote($name, '/') . '="([^"]*)"/i';

        return preg_match($pattern, $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
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
        const alerts = [];
        const navigations = [];
        const submitted = [];
        let next = 0;
        const location = {
            _href: '',
            get href() { return this._href; },
            set href(value) { navigations.push(String(value)); this._href = String(value); },
            reload() { navigations.push('reload'); },
        };
        function XMLHttpRequest() { this.headers = {}; this.status = 0; this.responseText = ''; this.onload = null; }
        XMLHttpRequest.prototype.open = function (method, url) { this.method = String(method).toUpperCase(); this.url = String(url); };
        XMLHttpRequest.prototype.setRequestHeader = function (name, value) { this.headers[name] = String(value); };
        XMLHttpRequest.prototype.send = function (body) {
            requests.push({ url: this.url, method: this.method, headers: Object.assign({}, this.headers), body: body === undefined || body === null ? null : String(body) });
            const response = input.responses[next++];
            if (!response) {
                return;
            }
            const xhr = this;
            Promise.resolve().then(function () {
                xhr.status = response.status;
                xhr.responseText = typeof response.body === 'string' ? response.body : JSON.stringify(response.body);
                if (xhr.onload) { xhr.onload(); }
            });
        };
        const context = vm.createContext({
            URLSearchParams,
            console,
            XMLHttpRequest,
            location,
            window: { location: location },
            setTimeout() { return 0; },
            clearTimeout() {},
            confirm(message) { confirms.push(String(message)); return input.confirm; },
            alert(message) { alerts.push(String(message)); },
            document: {
                querySelector(selector) {
                    if (selector === 'meta[name="csrf-token"]' && input.csrf !== null) {
                        return { content: input.csrf, getAttribute(name) { return name === 'content' ? input.csrf : null; } };
                    }
                    return null;
                },
                querySelectorAll() { return []; },
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
        // Alpine と同じく、属性の式をコンポーネントの状態をスコープにして評価する（式はテストが描いた画面から取り出したものだけ）
        const evaluate = vm.runInContext('(function (data, expr) { return new Function("data", "with (data) { return (" + expr + "); }")(data); })', context);
        const asValue = function (value) { return value === null || value === undefined ? '' : String(value); };
        data.$nextTick = function (fn) { fn.call(data); };
        data.$refs = new Proxy({}, {
            get(target, name) {
                if (typeof name !== 'string') { return undefined; }
                return {
                    focus() {},
                    blur() {},
                    select() {},
                    submit() {
                        const form = input.forms[name];
                        if (!form) { submitted.push({ ref: name, method: '', action: '', fields: {} }); return; }
                        const fields = {};
                        for (const field of form.fields) {
                            fields[field.name] = field.expr === null ? field.value : asValue(evaluate(data, field.expr));
                        }
                        submitted.push({ ref: name, method: form.method, action: form.actionExpr === null ? form.action : asValue(evaluate(data, form.actionExpr)), fields: fields });
                    },
                };
            },
        });
        vm.runInContext('(function (data) {\n' + input.steps + '\n})', context)(data);
        (async function () {
            for (let i = 0; i < 20; i++) {
                await new Promise(function (resolve) { setImmediate(resolve); });
            }
            const evaluated = input.evaluate.map(function (expr) { return evaluate(data, expr); });
            const state = {};
            for (const key of Object.keys(data)) {
                if (key !== '$refs' && key !== '$nextTick') { state[key] = data[key]; }
            }
            process.stdout.write(JSON.stringify({ requests: requests, confirms: confirms, alerts: alerts, navigations: navigations, submitted: submitted, evaluated: JSON.parse(JSON.stringify(evaluated)), state: JSON.parse(JSON.stringify(state)) }));
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
