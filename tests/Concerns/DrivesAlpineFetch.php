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
     * sendCaptured() で送り、転送（3xx）が返ったら**ブラウザの fetch / XMLHttpRequest と同じ決まりで**追いかける。
     * 返るのは最後の応答（XHR の onload が見る status と本文）。
     *
     * ⚠ 302 を GET に変えるのは POST のときだけ（301 も同じ）。DELETE や PUT は **同じメソッドのまま**転送先へ送る。
     *   303 は GET に変える。Laravel の followRedirects() は常に GET で追うので、それを使うと XHR の DELETE が
     *   転送先（一覧の URL）で 405 になるのを見逃す（2026-10-04 にアンケート設問の削除で実ブラウザで踏んだ）。
     *
     * @param  array{url: string, method: string, headers: array<string, string>, body: ?string}  $request
     */
    protected function sendCapturedLikeBrowser(array $request): TestResponse
    {
        $response = $this->sendCaptured($request);
        for ($i = 0; $i < 5 && $response->isRedirect(); $i++) {
            $status = $response->getStatusCode();
            $toGet = (in_array($status, [301, 302], true) && $request['method'] === 'POST')
                || ($status === 303 && ! in_array($request['method'], ['GET', 'HEAD'], true));
            $request['url'] = (string) $response->headers->get('Location');
            if ($toGet) {
                $request['method'] = 'GET';
                $request['body'] = null;
                $request['headers'] = array_filter($request['headers'], fn ($name) => strtolower($name) !== 'content-type', ARRAY_FILTER_USE_KEY);
            }
            $response = $this->sendCaptured($request);
        }

        return $response;
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

    /**
     * ブラウザがこのフォームから送る項目を、Alpine が値を入れたあと（$steps のあと）の JS の状態で評価し、
     * PHP が受け取る形（`details[0][amount]` は入れ子の配列）で返す。
     * alpineForm() と違い、`<select>` / `<textarea>` / ラジオ / チェックボックスの `x-model`（`.number` などの修飾つきも）、
     * `:name` / `:value` / `:disabled`、`<template x-for>` の中の入力（行ごとに展開）、`$refs` の選択欄に JS が足した選択肢まで、
     * ブラウザと同じ決まりで評価する。$responses は fetch に順に返す応答（driveAlpine() と同じ）。
     *
     * ⚠ `<template>` の中身はブラウザでは描かれず、送られない。parseForm() は中身の入力も拾うので、ここでは外してから数える。
     * ⚠ `x-model` の選択欄は、JS の値が選択肢に無いと落とす（ブラウザでは何も選ばれず、画面で選べない値になる）。
     * ⚠ `:disabled` が真の項目は送らない（ブラウザと同じ。同じ name の欄を出し分ける画面の約束。CLAUDE.md「Form」）。
     * ⚠ コンポーネントの init() は Alpine と同じく 1 回呼ぶ（`x-init="init()"` を重ねて書いた画面でも 1 回）。
     * ⚠ $function が null のときは画面の script を読まず、$factory（インラインの x-data の式）でコンポーネントを作る。
     *
     * @param  array<int, array{status: int, body: mixed}>  $responses
     * @return array{method: string, action: string, fields: array<string, mixed>, run: array}
     */
    protected function browserForm(string $html, string $needle, ?string $function, string $steps = '', array $responses = [], ?string $factory = null): array
    {
        $form = $this->parseForm($html, $needle);

        $pos = strpos($html, $needle);
        $open = strrpos(substr($html, 0, $pos), '<form');
        $body = substr($html, $open, strpos($html, '</form>', $pos) - $open);
        $model = $this->formModel($body);

        $run = $this->runNode([
            'script'     => $function === null ? '' : $this->scriptDefining($html, $function),
            'csrf'       => preg_match('/<meta name="csrf-token" content="([^"]*)"/', $html, $m) ? $m[1] : null,
            'factory'    => $factory ?? $this->xData($html, (string) $function),
            'steps'      => $steps,
            'responses'  => $responses,
            'confirm'    => true,
            'forms'      => (object) $this->refForms($html),
            'evaluate'   => [],
            'refOptions' => (object) $this->refSelectOptions($html),
            'init'       => true,
            'formModel'  => $model,
        ], self::ALPINE_HARNESS);

        $this->assertSame([], $run['formErrors'], 'ブラウザが送るフォームを組めなかった');
        $query = [];
        foreach ($run['formPairs'] as [$name, $value]) {
            $this->assertDoesNotMatchRegularExpression('/[. ]/', $name, "項目名「{$name}」は parse_str で書き換わる");
            $query[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        parse_str(implode('&', $query), $fields);

        return ['method' => $form['method'], 'action' => $form['action'], 'fields' => $fields, 'run' => $run];
    }

    /**
     * 画面のインラインの x-data（`x-data="{ … }"`）のうち、$needle のフォームを囲む直近のものの式を返す（実体参照は戻す）。
     */
    protected function inlineXDataAround(string $html, string $needle): string
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "フォームが見つからない: {$needle}");
        $found = preg_match_all('/\bx-data="(\{[^"]*\})"/', substr($html, 0, $pos), $m);
        $this->assertGreaterThan(0, $found, "{$needle} の手前にインラインの x-data が無い");

        return html_entity_decode(end($m[1]), ENT_QUOTES, 'UTF-8');
    }

    /**
     * フォームの中の入力を、ブラウザが送る単位で集める（`<template>` の中身は外し、x-for のものは行の型として返す）。
     *
     * @return array{controls: array<int, array<string, mixed>>, loops: array<int, array{for: string, controls: array<int, array<string, mixed>>}>}
     */
    private function formModel(string $html): array
    {
        [$plain, $templates] = $this->cutTemplates($html);
        $controls = $this->formControls($plain, $templates, $usedBySelects);
        $loops = [];
        foreach ($templates as $id => $template) {
            if (in_array($id, $usedBySelects, true)) {
                continue;
            }
            $inner = $this->formModel($template['body']);
            if ($inner['controls'] === [] && $inner['loops'] === []) {
                continue;
            }
            $this->assertNotNull($template['for'], 'x-for でない <template> の中の入力は扱えない: ' . $template['open']);
            $this->assertSame([], $inner['loops'], '入れ子の x-for の中の入力は扱えない: ' . $template['open']);
            $loops[] = ['for' => $template['for'], 'controls' => $inner['controls']];
        }

        return ['controls' => $controls, 'loops' => $loops];
    }

    /**
     * 一番外側の `<template>` を `<!--tpl:N-->` に置き換え、中身を返す（入れ子は中身ごと残す）。
     *
     * @return array{0: string, 1: array<int, array{open: string, for: ?string, body: string}>}
     */
    private function cutTemplates(string $html): array
    {
        preg_match_all('/<template\b[^>]*>|<\/template>/i', $html, $tokens, PREG_OFFSET_CAPTURE);
        $templates = [];
        $plain = '';
        $cursor = 0;
        $depth = 0;
        $start = 0;
        $openTag = '';
        foreach ($tokens[0] as [$token, $offset]) {
            if (! str_starts_with($token, '</')) {
                if ($depth === 0) {
                    $start = $offset;
                    $openTag = $token;
                }
                $depth++;

                continue;
            }
            $depth--;
            $this->assertGreaterThanOrEqual(0, $depth, '<template> の対応が取れない');
            if ($depth === 0) {
                $id = count($templates);
                $bodyStart = $start + strlen($openTag);
                $templates[$id] = [
                    'open' => $openTag,
                    'for'  => $this->htmlAttr($openTag, 'x-for'),
                    'body' => substr($html, $bodyStart, $offset - $bodyStart),
                ];
                $plain .= substr($html, $cursor, $start - $cursor) . "<!--tpl:{$id}-->";
                $cursor = $offset + strlen($token);
            }
        }
        $this->assertSame(0, $depth, '<template> が閉じていない');

        return [$plain . substr($html, $cursor), $templates];
    }

    /**
     * 入力・選択欄・テキストエリアを文書の順に集める。選択欄の中の `<template x-for>` の選択肢は、その選択欄の選択肢として持つ。
     *
     * @param  array<int, array{open: string, for: ?string, body: string}>  $templates
     * @param  array<int, int>|null  $usedBySelects  選択欄の選択肢として使った <template> の番号（出力）
     * @return array<int, array<string, mixed>>
     */
    private function formControls(string $plain, array $templates, ?array &$usedBySelects = null): array
    {
        $usedBySelects = [];
        $controls = [];
        preg_match_all('/<input\b[^>]*>|<textarea\b[^>]*>.*?<\/textarea>|<select\b[^>]*>.*?<\/select>/is', $plain, $matches);
        foreach ($matches[0] as $element) {
            $openTag = substr($element, 0, strpos($element, '>') + 1);
            $kind = strtolower(substr($openTag, 1, strcspn($openTag, " \t\r\n>", 1)));
            $name = $this->htmlAttr($openTag, 'name');
            $nameExpr = $this->boundAttr($openTag, 'name');
            if ($name === null && $nameExpr === null) {
                continue;
            }
            $type = $kind === 'input' ? strtolower($this->htmlAttr($openTag, 'type') ?? 'text') : $kind;
            if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                continue;
            }
            $control = [
                'kind'         => $kind,
                'type'         => $type,
                'name'         => $name,
                'nameExpr'     => $nameExpr,
                'model'        => preg_match('/(?<![\w:.@-])x-model(?:\.[\w-]+)*="([^"]*)"/i', $openTag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null,
                'valueExpr'    => $this->boundAttr($openTag, 'value'),
                'value'        => $this->htmlAttr($openTag, 'value') ?? (in_array($type, ['checkbox', 'radio'], true) ? 'on' : ''),
                'checked'      => (bool) preg_match('/(?<![\w:.@-])checked(?=[\s>=\/])/i', $openTag),
                'disabled'     => (bool) preg_match('/(?<![\w:.@-])disabled(?=[\s>=\/])/i', $openTag),
                'disabledExpr' => $this->boundAttr($openTag, 'disabled'),
                'ref'          => $this->htmlAttr($openTag, 'x-ref'),
                'options'      => [],
            ];
            if ($kind === 'textarea') {
                $control['value'] = html_entity_decode((string) preg_replace('/^<textarea\b[^>]*>|<\/textarea>$/i', '', $element), ENT_QUOTES, 'UTF-8');
            }
            if ($kind === 'select') {
                preg_match_all('/<option\b[^>]*>|<!--tpl:(\d+)-->/i', $element, $parts, PREG_SET_ORDER);
                foreach ($parts as $part) {
                    if (isset($part[1]) && $part[1] !== '') {
                        $template = $templates[(int) $part[1]];
                        $usedBySelects[] = (int) $part[1];
                        $this->assertNotNull($template['for'], '選択欄の中の x-for でない <template> は扱えない');
                        preg_match_all('/<option\b[^>]*>/i', $template['body'], $loopOptions);
                        foreach ($loopOptions[0] as $option) {
                            $control['options'][] = $this->optionModel($option) + ['for' => $template['for']];
                        }

                        continue;
                    }
                    $control['options'][] = $this->optionModel($part[0]) + ['for' => null];
                }
            }
            $controls[] = $control;
        }

        return $controls;
    }

    /** @return array{value: string, valueExpr: ?string, selected: bool, selectedExpr: ?string} */
    private function optionModel(string $option): array
    {
        return [
            'value'        => $this->htmlAttr($option, 'value') ?? '',
            'valueExpr'    => $this->boundAttr($option, 'value'),
            'selected'     => (bool) preg_match('/(?<![\w:.@-])selected(?=[\s>=\/])/i', $option),
            'selectedExpr' => $this->boundAttr($option, 'selected'),
        ];
    }

    /**
     * `x-ref` の付いた選択欄の、描かれた選択肢（JS が `$refs.….options` を足し引きする出発点）。
     *
     * @return array<string, array<int, array{text: string, value: string, selected: bool}>>
     */
    private function refSelectOptions(string $html): array
    {
        $found = [];
        preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is', $html, $selects, PREG_SET_ORDER);
        foreach ($selects as $select) {
            $ref = $this->htmlAttr('<select' . $select[1] . '>', 'x-ref');
            if ($ref === null) {
                continue;
            }
            [$plain] = $this->cutTemplates($select[2]);
            preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/is', $plain, $options, PREG_SET_ORDER);
            $found[$ref] = array_map(fn (array $option) => [
                'text'     => html_entity_decode(trim(strip_tags($option[2])), ENT_QUOTES, 'UTF-8'),
                'value'    => $this->htmlAttr('<option' . $option[1] . '>', 'value') ?? '',
                'selected' => (bool) preg_match('/(?<![\w:.@-])selected(?=[\s>=\/])/i', $option[1]),
            ], $options);
        }

        return $found;
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
        // `new Option(text, value)`（選択肢を JS で足す画面。契約の登録の区画）
        function Option(text, value, defaultSelected, selected) {
            return { text: String(text), value: value === undefined ? String(text) : String(value), selected: !!selected };
        }
        const context = vm.createContext({
            URLSearchParams,
            console,
            XMLHttpRequest,
            Option,
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
        // 括弧で包む: インラインの x-data（`{ … }`）がブロック文として読まれないように
        const data = vm.runInContext('(' + input.factory + ')', context);
        // Alpine と同じく、属性の式をコンポーネントの状態をスコープにして評価する（式はテストが描いた画面から取り出したものだけ）
        const evaluate = vm.runInContext('(function (data, expr) { return new Function("data", "with (data) { return (" + expr + "); }")(data); })', context);
        // x-for の行の変数（row・idx など）を状態より優先して見る
        const evaluateIn = vm.runInContext('(function (data, scope, expr) { return new Function("data", "scope", "with (data) { with (scope) { return (" + expr + "); } }")(data, scope); })', context);
        const asValue = function (value) { return value === null || value === undefined ? '' : String(value); };
        data.$nextTick = function (fn) { fn.call(data); };
        data.$watch = function () {};
        data.$dispatch = function () {};
        const refStore = {};
        const refOptions = input.refOptions || {};
        data.$refs = new Proxy({}, {
            get(target, name) {
                if (typeof name !== 'string') { return undefined; }
                if (refStore[name]) { return refStore[name]; }
                return refStore[name] = {
                    // 選択欄の ref: 描かれた選択肢から始め、JS の add / remove をそのまま反映する
                    options: (refOptions[name] || []).map(function (o) { return Object.assign({}, o); }),
                    add(option) { this.options.push(option); },
                    remove(index) { this.options.splice(index, 1); },
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
        // Alpine はコンポーネントを作ったあと init() を 1 回呼ぶ（browserForm() だけ。既存の呼び出しは今までどおり呼ばない）
        if (input.init && typeof data.init === 'function') { data.init(); }
        vm.runInContext('(function (data) {\n' + input.steps + '\n})', context)(data);

        // ブラウザがフォームから送る項目（browserForm()）
        const eachRow = function (forExpr, scope, fn) {
            const m = String(forExpr).match(/^\s*(?:\(\s*([\w$]+)\s*(?:,\s*([\w$]+)\s*)?\)|([\w$]+))\s+(?:in|of)\s+([\s\S]+)$/);
            if (!m) { throw new Error('x-for を読めない: ' + forExpr); }
            let list = evaluateIn(data, scope, m[4]);
            if (typeof list === 'number') { list = Array.from({ length: list }, function (v, i) { return i + 1; }); }
            Array.from(list || []).forEach(function (item, i) {
                const rowScope = Object.assign({}, scope);
                rowScope[m[1] || m[3]] = item;
                if (m[2]) { rowScope[m[2]] = i; }
                fn(rowScope);
            });
        };
        const selectOptions = function (control, scope) {
            if (control.ref !== null && refStore[control.ref]) {
                return refStore[control.ref].options.map(function (o) { return { value: String(o.value), selected: !!o.selected }; });
            }
            const list = [];
            const push = function (option, s) {
                list.push({
                    value: option.valueExpr !== null ? asValue(evaluateIn(data, s, option.valueExpr)) : option.value,
                    selected: option.selectedExpr !== null ? !!evaluateIn(data, s, option.selectedExpr) : option.selected,
                });
            };
            for (const option of control.options) {
                if (option.for !== null) { eachRow(option.for, scope, function (s) { push(option, s); }); } else { push(option, scope); }
            }
            return list;
        };
        const controlPairs = function (control, scope, out, errors) {
            if (control.disabled || (control.disabledExpr !== null && evaluateIn(data, scope, control.disabledExpr))) { return; }
            const name = control.nameExpr !== null ? asValue(evaluateIn(data, scope, control.nameExpr)) : control.name;
            if (control.type === 'radio' || control.type === 'checkbox') {
                const own = control.valueExpr !== null ? asValue(evaluateIn(data, scope, control.valueExpr)) : control.value;
                let on = control.checked;
                if (control.model !== null) {
                    const bound = evaluateIn(data, scope, control.model);
                    on = control.type === 'radio' ? asValue(bound) === own
                        : (Array.isArray(bound) ? bound.map(String).indexOf(own) !== -1 : !!bound);
                }
                if (on) { out.push([name, own]); }
                return;
            }
            if (control.kind === 'select') {
                const options = selectOptions(control, scope);
                let value = options.length > 0 ? options[0].value : '';
                if (control.model !== null) {
                    value = asValue(evaluateIn(data, scope, control.model));
                    if (!options.some(function (o) { return o.value === value; })) {
                        errors.push('選択欄「' + name + '」の x-model の値「' + value + '」が選択肢に無い（' + options.map(function (o) { return o.value; }).join(', ') + '）');
                        return;
                    }
                } else {
                    for (const o of options) { if (o.selected) { value = o.value; } }
                }
                out.push([name, value]);
                return;
            }
            const value = control.model !== null ? evaluateIn(data, scope, control.model)
                : (control.valueExpr !== null ? evaluateIn(data, scope, control.valueExpr) : control.value);
            out.push([name, asValue(value)]);
        };

        (async function () {
            for (let i = 0; i < 20; i++) {
                await new Promise(function (resolve) { setImmediate(resolve); });
            }
            const evaluated = input.evaluate.map(function (expr) { return evaluate(data, expr); });
            const formPairs = [];
            const formErrors = [];
            if (input.formModel) {
                for (const control of input.formModel.controls) { controlPairs(control, {}, formPairs, formErrors); }
                for (const loop of input.formModel.loops) {
                    eachRow(loop.for, {}, function (s) { for (const control of loop.controls) { controlPairs(control, s, formPairs, formErrors); } });
                }
            }
            const state = {};
            for (const key of Object.keys(data)) {
                if (key !== '$refs' && key !== '$nextTick' && key !== '$watch' && key !== '$dispatch') { state[key] = data[key]; }
            }
            process.stdout.write(JSON.stringify({ requests: requests, confirms: confirms, alerts: alerts, navigations: navigations, submitted: submitted, evaluated: JSON.parse(JSON.stringify(evaluated)), state: JSON.parse(JSON.stringify(state)), formPairs: formPairs, formErrors: formErrors }));
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
