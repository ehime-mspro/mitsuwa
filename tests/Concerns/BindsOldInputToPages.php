<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use PhpToken;
use ReflectionClass;
use ReflectionNamedType;

/**
 * 画面の old() の欄を、その画面のフォームの送り先の入力チェックの規則と結び付ける（OldInputRuleCoverageTest が使う。Bug #121）。
 *
 * - 画面: `@include('…')` と部品（`<x-…>`）でたどった木の、いちばん上の親のビュー
 * - 画面の欄: 木の中の old()（Blade のコメントは位置を保って落とす）。old($引数) を包む関数
 *   （`$val = function ($key, …) { … old($key …) }`）の呼び出し `$val('x')` も読む
 * - 画面の送り先: 木の中のフォームの送り先（GET のフォームも。GET の入力チェックで断られても、送った値は控えに入る）。
 *   名前のルートの **URL** と、フォームの送る方式（method と `@method`）が合うルートの `Class@method`
 *   （名前だけで引くと `route('login')` が GET のルートを指し、POST のルートに届かない）
 * - 送り先の規則: そのメソッドと、そこから `$this->名前(` で呼ぶメソッドの中の入力チェック（CollectsValidationKeys の sites を、
 *   ファイルと行の両方で当てる）
 *
 * 読めない書き方は推測せず unreadable として返す（呼ぶ側が落とす。全件分類。Top trap #13）。
 * ⚠ `{{ … }}` の中は属性の区切りとして読まない（`action="{{ route("{$department}.customers.store") }}"` の中の `"`）
 * ⚠ 名前を変数で組む送り先（`route("{$department}.…")`）は、その画面を描くコントローラのルートだけに絞る
 *   （絞らないと `tenant.customers.store` という別の画面のルートまで拾う）
 */
trait BindsOldInputToPages
{
    /** Blade のコメントを空白にする（位置と行は保つ） */
    protected static function blankBladeComments(string $source): string
    {
        return preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]), $source);
    }

    /**
     * old() で読む欄（コメントを落としたソースから）。
     *
     * @return array{uses: list<array{key: string, line: int, via: ?string}>, unreadable: list<string>}
     */
    protected static function oldInputUses(string $source): array
    {
        $uses = [];
        $unreadable = [];
        preg_match_all('/\bold\(\s*([\'"])([^\'"]+)\1/', $source, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[2] as [$key, $offset]) {
            $uses[] = ['key' => $key, 'line' => self::lineAt($source, $offset), 'via' => null];
        }

        // old($引数) を包む関数: `$name = function ($param …) … {` の本体で old($param を読むもの
        $wrappers = [];
        preg_match_all('/\$(\w+)\s*=\s*function\s*\(\s*\$(\w+)[^)]*\)(?:\s*use\s*\([^)]*\))?\s*\{/', $source, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($found as $f) {
            $open = $f[0][1] + strlen($f[0][0]) - 1;
            $close = self::matchingClose($source, $open);
            if ($close !== null && preg_match('/\bold\(\s*\$' . $f[2][0] . '\b/', substr($source, $open, $close - $open))) {
                $wrappers[$f[1][0]] = [$open, $close, $f[2][0]];
            }
        }

        // ほかの old(…)（引数が文字でないもの）は、包む関数の中で自分の引数を渡すものだけ通す。
        // 引数の無い「old()」は数えない（コメントの文中に多い。描くなら `old()` は配列を返す）
        preg_match_all('/\bold\(\s*(?![\'")])/', $source, $others, PREG_OFFSET_CAPTURE);
        foreach ($others[0] as [, $offset]) {
            $wrapped = false;
            foreach ($wrappers as [$open, $close, $param]) {
                if ($offset > $open && $offset < $close && preg_match('/\Gold\(\s*\$' . $param . '\b/', $source, $unused, 0, $offset)) {
                    $wrapped = true;
                }
            }
            if (! $wrapped) {
                $unreadable[] = 'old() の引数が文字でない（' . self::lineAt($source, $offset) . ' 行）';
            }
        }

        foreach (array_keys($wrappers) as $name) {
            preg_match_all('/\$' . $name . '\(\s*([\'"])([^\'"]+)\1/', $source, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[2] as [$key, $offset]) {
                $uses[] = ['key' => $key, 'line' => self::lineAt($source, $offset), 'via' => '$' . $name];
            }
        }

        return ['uses' => $uses, 'unreadable' => $unreadable];
    }

    /**
     * フォーム（コメントを落としたソースから）。action は `action` 属性の値（Alpine の `:action` なら null）。
     * 閉じていない・入れ子・対応の無い閉じタグは unreadable として返す。
     *
     * @return array{forms: list<array{line: int, action: ?string, methods: list<string>}>, unreadable: list<string>}
     */
    protected static function bladeForms(string $source): array
    {
        $forms = [];
        $unreadable = [];
        $open = null;
        preg_match_all('/<form\b|<\/form\s*>/i', $source, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$text, $offset]) {
            $line = self::lineAt($source, $offset);
            if ($text[1] !== '/') {
                if ($open !== null) {
                    $unreadable[] = "フォームの中にフォームがある（{$line} 行）";
                }
                $open = $offset;

                continue;
            }
            if ($open === null) {
                $unreadable[] = "対応する <form> の無い </form>（{$line} 行）";

                continue;
            }
            $tag = self::readTag($source, $open);
            $attributes = self::tagAttributes($tag);
            $method = strtoupper($attributes['method'] ?? 'GET');
            preg_match_all('/@method\(\s*[\'"](\w+)[\'"]\s*\)/', substr($source, $open, $offset - $open), $overrides);
            $alpine = isset($attributes[':action']) || isset($attributes['x-bind:action']);
            $forms[] = [
                'line' => self::lineAt($source, $open),
                'action' => $alpine ? null : ($attributes['action'] ?? null),
                'methods' => array_values(array_unique(array_merge([$method], array_map('strtoupper', $overrides[1])))),
            ];
            $open = null;
        }
        if ($open !== null) {
            $unreadable[] = '閉じていない <form>（' . self::lineAt($source, $open) . ' 行）';
        }

        return ['forms' => $forms, 'unreadable' => $unreadable];
    }

    /**
     * 読み込む部品（`@include('a.b')` と `<x-a.b …>`）。部品の属性は、部品の中のフォームの送り先（`{{ $action }}`）を読むのに使う。
     *
     * @return list<array{view: string, line: int, attributes: array<string, string>}>
     */
    protected static function bladeIncludes(string $source): array
    {
        $out = [];
        preg_match_all('/@include\s*\(\s*([\'"])([\w.\-\/]+)\1/', $source, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[2] as [$name, $offset]) {
            $out[] = ['view' => str_replace('.', '/', $name) . '.blade.php', 'line' => self::lineAt($source, $offset), 'attributes' => []];
        }
        preg_match_all('/<x-([\w.\-]+)/', $source, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$name, $offset]) {
            $tag = self::readTag($source, $offset - 3);
            $out[] = ['view' => 'components/' . str_replace('.', '/', $name) . '.blade.php', 'line' => self::lineAt($source, $offset), 'attributes' => self::tagAttributes($tag)];
        }

        return $out;
    }

    /**
     * フォームの送り先のルートの名前。読めない書き方なら null。
     * 読める形: `{{ route('名前', …) }}`・`{{ 条件 ? route('a') : route('b') }}`・`{{ route("{$変数}.名前") }}`（dynamic）・
     * 部品の `{{ $action }}`（呼び出し側の `:action="route(…)"` か `action="{{ route(…) }}"`）
     *
     * @param  array<string, string>  $attributes  部品の呼び出し側の属性
     * @return list<array{name: string, dynamic: bool}>|null
     */
    protected static function actionRouteNames(?string $action, array $attributes = []): ?array
    {
        if ($action === null || ! preg_match('/^\s*\{\{(.*)\}\}\s*$/s', $action, $m)) {
            return null;
        }
        $expr = trim($m[1]);
        if (preg_match('/^\$(\w+)$/', $expr, $variable)) {
            if (isset($attributes[':' . $variable[1]])) {
                return self::actionRouteNames('{{ ' . $attributes[':' . $variable[1]] . ' }}');
            }

            return isset($attributes[$variable[1]]) ? self::actionRouteNames($attributes[$variable[1]]) : null;
        }

        $names = [];
        $rest = '';
        $at = 0;
        while (preg_match('/\broute\(\s*(\'[^\']*\'|"[^"]*")/', $expr, $r, PREG_OFFSET_CAPTURE, $at)) {
            $start = $r[0][1];
            $close = self::matchingClose($expr, strpos($expr, '(', $start));
            if ($close === null) {
                return null;
            }
            $rest .= substr($expr, $at, $start - $at) . 'R';
            $literal = $r[1][0];
            $names[] = ['name' => substr($literal, 1, -1), 'dynamic' => $literal[0] === '"' && str_contains($literal, '$')];
            $at = $close + 1;
        }
        $rest .= substr($expr, $at);
        if ($names === [] || ! preg_match('/^\s*(?:R|[^?:]+\?\s*R\s*:\s*R)\s*$/', $rest)) {
            return null;
        }

        return $names;
    }

    /**
     * ルートの名前と送る方式から、送り先の `Class@method`。見つからない名前があれば null。
     *
     * @param  list<array{name: string, dynamic: bool}>  $names
     * @param  list<string>  $methods  フォームの送る方式（method と `@method`）
     * @param  list<array{name: ?string, uri: string, methods: list<string>, action: string}>  $routes
     * @param  list<string>  $renderers  その画面を描くコントローラのクラス（名前を変数で組む送り先を絞る）
     * @return list<string>|null
     */
    protected static function routeActions(array $names, array $methods, array $routes, array $renderers): ?array
    {
        $actions = [];
        foreach ($names as $name) {
            $pattern = $name['dynamic']
                ? '/^' . preg_replace('/\\\\\{\\\\\$\w+\\\\\}|\\\\\$\w+/', '[^.]+', preg_quote($name['name'], '/')) . '$/'
                : null;
            $found = [];
            foreach ($routes as $named) {
                if ($named['name'] === null || ($pattern === null ? $named['name'] !== $name['name'] : ! preg_match($pattern, $named['name']))) {
                    continue;
                }
                foreach ($routes as $route) {
                    if ($route['uri'] !== $named['uri'] || array_intersect($methods, $route['methods']) === [] || ! str_contains($route['action'], '@')) {
                        continue;
                    }
                    if ($name['dynamic'] && ! in_array(strstr($route['action'], '@', true), $renderers, true)) {
                        continue;
                    }
                    $found[] = $route['action'];
                }
            }
            if ($found === []) {
                return null;
            }
            $actions = array_merge($actions, $found);
        }

        return array_values(array_unique($actions));
    }

    /**
     * 送り先のメソッドに届く入力チェックの規則。`$this->名前(` で呼ぶメソッドを $files（読んだソース）の中だけたどる。
     * flashes = 控えを作りうるか（入力チェック・`withInput(`・`ValidationException` のどれかが届く）。
     *
     * @param  array<string, list<array{0: int, 1: list<string>}>>  $siteIndex  ファイル => [入力チェックの行, キー]
     * @param  array<string, true>  $files  たどってよいファイル（base_path からの相対パス）
     * @return array{keys: array<string, true>, sites: list<string>, flashes: bool, unreadable: list<string>}
     */
    protected static function ruleKeysOfAction(string $action, array $siteIndex, array $files): array
    {
        [$class, $method] = explode('@', $action);
        $reached = [];
        $unreadable = [];
        $signals = false;
        self::reachMethods(new ReflectionClass($class), $method, $files, $reached, $unreadable, $signals);

        $keys = [];
        $sites = [];
        foreach ($reached as [$file, $start, $end]) {
            foreach ($siteIndex[$file] ?? [] as [$line, $siteKeys]) {
                if ($line >= $start && $line <= $end) {
                    $sites[] = "{$file}:{$line}";
                    $keys += array_fill_keys($siteKeys, true);
                }
            }
        }
        $sites = array_values(array_unique($sites));
        if (count($sites) > 1) {
            // 断ったときに控えから外れるのは、先に断った入力チェックの規則の欄だけ（Bug #121。入力チェックを 2 回に分けない）
            $unreadable[] = "{$action}: 入力チェックが 2 つ以上届く（" . implode(', ', $sites) . '）';
        }

        return ['keys' => $keys, 'sites' => $sites, 'flashes' => $sites !== [] || $signals, 'unreadable' => $unreadable];
    }

    private static function reachMethods(ReflectionClass $class, string $method, array $files, array &$reached, array &$unreadable, bool &$signals): void
    {
        if (! $class->hasMethod($method)) {
            return;
        }
        $reflection = $class->getMethod($method);
        $file = str_replace(base_path() . '/', '', (string) $reflection->getFileName());
        $id = "{$file}::{$method}";
        if (isset($reached[$id]) || ! isset($files[$file])) {
            return;
        }
        $reached[$id] = [$file, $reflection->getStartLine(), $reflection->getEndLine()];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                $unreadable[] = "{$class->getName()}@{$method}: FormRequest を受ける（この走査は FormRequest の規則を読まない）";
            }
        }

        $lines = array_slice(file(base_path($file)), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
        $tokens = array_values(array_filter(PhpToken::tokenize('<?php ' . implode('', $lines)), fn (PhpToken $t) => ! $t->isIgnorable()));
        foreach ($tokens as $i => $token) {
            if (str_contains($token->text, 'ValidationException')
                || ($token->text === 'withInput' && ($tokens[$i + 1]->text ?? '') === '(')) {
                $signals = true;
            }
            if ($token->text === '$this' && ($tokens[$i + 1]->id ?? null) === T_OBJECT_OPERATOR
                && ($tokens[$i + 2]->id ?? null) === T_STRING && ($tokens[$i + 3]->text ?? '') === '(') {
                self::reachMethods($class, $tokens[$i + 2]->text, $files, $reached, $unreadable, $signals);
            }
        }
    }

    // ---------- 字句 ----------

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /** 開き括弧 $open（`(` か `{`）に対応する閉じ括弧の位置（引用符の中は数えない） */
    private static function matchingClose(string $s, int $open): ?int
    {
        $pairs = ['(' => ')', '{' => '}'];
        $openChar = $s[$open];
        $closeChar = $pairs[$openChar] ?? null;
        if ($closeChar === null) {
            return null;
        }
        $depth = 0;
        $quote = null;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === $openChar) {
                $depth++;
            } elseif ($c === $closeChar && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** $start（`<`）から始まるタグ（引用符と `{{ … }}` の中の `>` で切らない） */
    private static function readTag(string $source, int $start): string
    {
        $quote = null;
        for ($i = $start, $n = strlen($source); $i < $n; $i++) {
            if (substr($source, $i, 2) === '{{') {
                $end = strpos($source, '}}', $i);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;

                continue;
            }
            $c = $source[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '>') {
                return substr($source, $start, $i - $start + 1);
            }
        }

        return substr($source, $start);
    }

    /** @return array<string, string> タグの属性（名前は小文字。`{{ … }}` の中の引用符で値を切らない） */
    private static function tagAttributes(string $tag): array
    {
        $out = [];
        preg_match('/^<[\w.\-:]+/', $tag, $name);
        $i = strlen($name[0] ?? '');
        $n = strlen($tag);
        while ($i < $n) {
            // 属性のあいだにじかに書いた `{{ … }}`（`{{ $attributes->merge(…) }}`）は飛ばす
            if (preg_match('/\G\s*\{\{/', $tag, $blade, 0, $i)) {
                $end = strpos($tag, '}}', $i);
                $i = $end === false ? $n : $end + 2;

                continue;
            }
            if (! preg_match('/\G\s*([^\s=>"\'\/]+)/', $tag, $m, 0, $i)) {
                break;
            }
            $attribute = strtolower($m[1]);
            $i += strlen($m[0]);
            if (! preg_match('/\G\s*=\s*/', $tag, $eq, 0, $i)) {
                $out[$attribute] = '';

                continue;
            }
            $i += strlen($eq[0]);
            $quote = $tag[$i] ?? '';
            if ($quote !== '"' && $quote !== "'") {
                preg_match('/\G[^\s>]*/', $tag, $bare, 0, $i);
                $out[$attribute] = $bare[0];
                $i += strlen($bare[0]);

                continue;
            }
            $j = $i + 1;
            while ($j < $n && $tag[$j] !== $quote) {
                if (substr($tag, $j, 2) === '{{') {
                    $end = strpos($tag, '}}', $j);
                    $j = $end === false ? $n : $end + 2;

                    continue;
                }
                $j++;
            }
            $out[$attribute] = substr($tag, $i + 1, $j - $i - 1);
            $i = $j + 1;
        }

        return $out;
    }
}
