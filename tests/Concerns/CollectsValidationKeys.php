<?php

namespace Tests\Concerns;

use LogicException;
use PhpToken;
use UnexpectedValueException;

/**
 * 入力チェックに出てくる項目（キー）を、コントローラなどのソースから集める（JapaneseValidationMessagesTest が使う）。
 *
 * 以前の走査は `validate([ … ])` のリテラルしか読まなかったので、変数に組む規則（`$rules = [...]` と `$rules['x'] = …`）・
 * `$this->rules()` のようなメソッド・`Validator::make()`・規則を引数で受け取って渡すだけの部品を原理的に見ていなかった
 * （2026-10-07。不動産の契約の 5 項目と DAD の原価の 3 項目が和名なしのまま残っていた。Bug #110）。
 *
 * 読み方: PHP の字句（PhpToken）で読み、入力チェックの呼び出しの「規則」の式をたどってキーを集める。
 * - 入力チェックの呼び出し: `->validate(`（規則は第 1 引数・名前は第 3）・`->validateWithBag(`（第 2・第 4）・
 *   `Validator::make(` / `Validator::validate(`（第 2・第 4）・FormRequest の `rules()`（名前は `attributes()`）・
 *   規則を自分の引数のまま渡すだけのメソッド（「渡すだけの部品」。例 `validateForDetail()`）の呼び出し
 * - たどれる式: 配列のリテラル（`...` の展開を含む）・`array_merge(...)`・括弧・そのメソッドの中の変数
 *   （`$x = …`・`$x['key'] = …`・`$x += …`）・`$this->メソッド(…)` の `return`（トレイトのメソッドを含む）・`self::定数` / `static::定数`
 * - それ以外の書き方（文字列でないキー・`$rules[$k] = …`・ほかのクラスの呼び出しなど）は推測せず「読めない」として返す（全件分類。Top trap #13）
 *
 * ⚠ 文字列の中の `(` などは文字の字句ではない（`"({$expression} IS NULL)"` の `(` は T_ENCAPSED_AND_WHITESPACE）。数えると括弧の対応が崩れる。
 * ⚠ 属性の `#[` は開き括弧として数える。
 * ⚠ クラスはファイルごとに分けて持つ（同じ短い名前のクラスが別の名前空間にある。ContractController は 3 つ。
 *   短い名前で持つと後のファイルが前を上書きし、黙って 3 割のキーが消えた）。トレイトだけは短い名前で引く（同じ名前が 2 つあれば引かない）。
 * ⚠ 見えないもの: `validate()` の外で組んだ規則をプロパティ（`$this->rules`）で渡す形・ループで組むキー（読めないとして返す）・
 *   別のファイルの関数・`app/Http` の外の入力チェック（今は 0 件）。
 */
trait CollectsValidationKeys
{
    /** @var array<string, array{file: string, name: string, uses: list<string>, methods: array<string, array{params: list<string>, s: int, e: int}>, consts: array<string, array{0: int, 1: int}>, formRequest: bool, t: list<PhpToken>}> */
    private array $vkClasses = [];

    /** @var array<string, list<string>> トレイトの短い名前 => クラスのキー */
    private array $vkTraits = [];

    /** app/Http の PHP のソース（相対パス => 中身） */
    protected function appHttpSources(): array
    {
        $sources = [];
        $root = base_path() . '/';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http'))) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[str_replace($root, '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }
        ksort($sources);

        return $sources;
    }

    /**
     * @param  array<string, string>  $sources  相対パス => PHP のソース
     * @return array{sites: list<array{where: string, keys: list<string>, named: list<string>}>, unreadable: list<string>}
     */
    protected function collectValidationKeys(array $sources): array
    {
        $this->vkClasses = [];
        $this->vkTraits = [];
        foreach ($sources as $path => $src) {
            $this->vkReadClasses($path, $src);
        }

        $sites = [];
        $unreadable = [];
        $forwarders = []; // メソッドのキー => [規則の引数の位置, 名前の引数の位置|null]
        $calls = [];      // [クラスのキー, メソッド名, 開き括弧の位置, 呼ぶメソッド名|null, 規則の位置, 名前の位置|null]

        foreach ($this->vkClasses as $key => $class) {
            $t = $class['t'];
            foreach ($class['methods'] as $methodName => $method) {
                if ($class['formRequest'] && $methodName === 'rules') {
                    $this->vkFormRequestSite($key, $sites, $unreadable);
                }
                for ($q = $method['s']; $q < $method['e']; $q++) {
                    if ($t[$q]->id === T_OBJECT_OPERATOR && ($t[$q + 2]->text ?? '') === '(') {
                        $name = $t[$q + 1]->text;
                        if ($name === 'validate') {
                            $calls[] = [$key, $methodName, $q + 2, null, 0, 2];
                        } elseif ($name === 'validateWithBag') {
                            $calls[] = [$key, $methodName, $q + 2, null, 1, 3];
                        } elseif ($t[$q - 1]->text === '$this') {
                            $calls[] = [$key, $methodName, $q + 2, $name, null, null];
                        }
                    }
                    if (in_array($t[$q]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                        && basename(str_replace('\\', '/', $t[$q]->text)) === 'Validator'
                        && $t[$q + 1]->id === T_DOUBLE_COLON
                        && in_array($t[$q + 2]->text, ['make', 'validate'], true)
                        && $t[$q + 3]->text === '(') {
                        $calls[] = [$key, $methodName, $q + 3, null, 1, 3];
                    }
                }
            }
        }

        // 直接の呼び出し。規則が自分の引数そのままなら「渡すだけの部品」として覚える
        foreach ($calls as $call) {
            if ($call[3] === null) {
                $this->vkSite($call, $call[4], $call[5], $forwarders, $sites, $unreadable);
            }
        }
        // 渡すだけの部品の呼び出し（部品をさらに渡すだけの部品にも届くよう、増えなくなるまで回す）
        $done = [];
        do {
            $before = count($forwarders);
            foreach ($calls as $i => $call) {
                if ($call[3] === null || isset($done[$i])) {
                    continue;
                }
                $resolved = $this->vkMethodOf($call[0], $call[3]);
                if ($resolved !== null && isset($forwarders[$resolved[0] . '::' . $call[3]])) {
                    [$rulesAt, $namesAt] = $forwarders[$resolved[0] . '::' . $call[3]];
                    $done[$i] = true;
                    $this->vkSite($call, $rulesAt, $namesAt, $forwarders, $sites, $unreadable);
                }
            }
        } while (count($forwarders) !== $before);

        return ['sites' => $sites, 'unreadable' => $unreadable];
    }

    // ---------- 字句 ----------

    /** @return list<PhpToken> 空白とコメントを落とした字句 */
    private function vkTokens(string $src): array
    {
        $out = [];
        foreach (PhpToken::tokenize($src) as $tok) {
            if ($tok->isIgnorable()) {
                continue;
            }
            // 文字列の中の ( や [ は文字の字句ではない（id が 256 以上）。記号と取り違えないよう印を付ける
            if ($tok->id >= 256 && strlen($tok->text) === 1) {
                $tok->text = '§' . $tok->text;
            }
            $out[] = $tok;
        }

        return $out;
    }

    private function vkIsOpen(PhpToken $tok): bool
    {
        return in_array($tok->text, ['(', '[', '{'], true)
            || in_array($tok->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true);
    }

    private function vkIsClose(PhpToken $tok): bool
    {
        return in_array($tok->text, [')', ']', '}'], true);
    }

    /** 開き括弧 $i に対応する閉じ括弧の位置 */
    private function vkClose(array $t, int $i): int
    {
        if (! $this->vkIsOpen($t[$i])) {
            throw new LogicException("括弧ではない字句から対応を探した（{$t[$i]->text}）");
        }
        $depth = 0;
        for ($j = $i, $n = count($t); $j < $n; $j++) {
            if ($this->vkIsOpen($t[$j])) {
                $depth++;
            } elseif ($this->vkIsClose($t[$j]) && --$depth === 0) {
                return $j;
            }
        }
        throw new UnexpectedValueException('括弧の対応が取れない');
    }

    /** 括弧 $open..$close の中を深さ 1 のカンマで分ける → [[始め, 終わり（含まない）], …] */
    private function vkSplit(array $t, int $open, int $close): array
    {
        $out = [];
        $start = $open + 1;
        $depth = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            if ($this->vkIsOpen($t[$j])) {
                $depth++;
            } elseif ($this->vkIsClose($t[$j])) {
                $depth--;
            } elseif ($t[$j]->text === ',' && $depth === 0) {
                $out[] = [$start, $j];
                $start = $j + 1;
            }
        }
        $out[] = [$start, $close];

        return array_values(array_filter($out, fn ($range) => $range[0] < $range[1]));
    }

    /** 文の終わり（深さ 0 の `;`）の位置 */
    private function vkStatementEnd(array $t, int $from): int
    {
        for ($j = $from, $n = count($t); $j < $n; $j++) {
            if ($this->vkIsOpen($t[$j])) {
                $j = $this->vkClose($t, $j);
            } elseif ($t[$j]->text === ';') {
                return $j;
            }
        }
        throw new UnexpectedValueException('文の終わりが見つからない');
    }

    private function vkString(PhpToken $tok): ?string
    {
        if ($tok->id !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $body = substr($tok->text, 1, -1);

        return $tok->text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }

    private function vkText(array $t, int $s, int $e): string
    {
        return implode(' ', array_map(fn ($tok) => $tok->text, array_slice($t, $s, min(10, $e - $s))));
    }

    // ---------- クラスとメソッド ----------

    private function vkReadClasses(string $path, string $src): void
    {
        $t = $this->vkTokens($src);
        for ($i = 0, $n = count($t); $i < $n; $i++) {
            if (! in_array($t[$i]->id, [T_CLASS, T_TRAIT], true) || ($t[$i + 1]->id ?? null) !== T_STRING || ($t[$i - 1]->id ?? null) === T_DOUBLE_COLON) {
                continue;
            }
            $name = $t[$i + 1]->text;
            $open = $i;
            $formRequest = false;
            while ($t[$open]->text !== '{') {
                if ($t[$open]->id === T_EXTENDS && basename(str_replace('\\', '/', $t[$open + 1]->text)) === 'FormRequest') {
                    $formRequest = true;
                }
                $open++;
            }
            $end = $this->vkClose($t, $open);
            $class = ['file' => $path, 'name' => $name, 'uses' => [], 'methods' => [], 'consts' => [], 'formRequest' => $formRequest, 't' => $t];
            for ($k = $open + 1; $k < $end; $k++) {
                if ($t[$k]->id === T_USE) {
                    for ($m = $k + 1; ! in_array($t[$m]->text, [';', '{'], true); $m++) {
                        if (in_array($t[$m]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            $class['uses'][] = basename(str_replace('\\', '/', $t[$m]->text));
                        }
                    }
                } elseif ($t[$k]->id === T_CONST) {
                    $eq = $k;
                    while ($t[$eq]->text !== '=') {
                        $eq++;
                    }
                    $class['consts'][$t[$eq - 1]->text] = [$eq + 1, $this->vkStatementEnd($t, $eq + 1)];
                } elseif ($t[$k]->id === T_FUNCTION) {
                    $at = ($t[$k + 1]->text === '&') ? $k + 2 : $k + 1;
                    if ($t[$at]->id !== T_STRING || $t[$at + 1]->text !== '(') {
                        continue;
                    }
                    $paramsClose = $this->vkClose($t, $at + 1);
                    $params = [];
                    foreach ($this->vkSplit($t, $at + 1, $paramsClose) as [$s, $e]) {
                        for ($q = $s; $q < $e; $q++) {
                            if ($t[$q]->id === T_VARIABLE) {
                                $params[] = $t[$q]->text;
                                break;
                            }
                        }
                    }
                    $body = $paramsClose;
                    while (! in_array($t[$body]->text, ['{', ';'], true)) {
                        $body++;
                    }
                    if ($t[$body]->text === '{') {
                        $bodyEnd = $this->vkClose($t, $body);
                        $class['methods'][$t[$at]->text] = ['params' => $params, 's' => $body, 'e' => $bodyEnd];
                        $k = $bodyEnd;
                    }
                }
            }
            $key = $path . '#' . $name;
            $this->vkClasses[$key] = $class;
            if ($t[$i]->id === T_TRAIT) {
                $this->vkTraits[$name][] = $key;
            }
            $i = $end;
        }
    }

    /** @return array{0: string, 1: array}|null [そのメソッドを持つクラス（トレイト）のキー, メソッド] */
    private function vkMethodOf(string $classKey, string $method): ?array
    {
        $class = $this->vkClasses[$classKey] ?? null;
        if ($class === null) {
            return null;
        }
        if (isset($class['methods'][$method])) {
            return [$classKey, $class['methods'][$method]];
        }
        foreach ($class['uses'] as $trait) {
            $keys = $this->vkTraits[$trait] ?? [];
            if (count($keys) === 1 && ($found = $this->vkMethodOf($keys[0], $method)) !== null) {
                return $found;
            }
        }

        return null;
    }

    // ---------- 入力チェックの呼び出し ----------

    private function vkSite(array $call, int $rulesAt, ?int $namesAt, array &$forwarders, array &$sites, array &$unreadable): void
    {
        [$classKey, $methodName, $open] = $call;
        $class = $this->vkClasses[$classKey];
        $t = $class['t'];
        $method = $class['methods'][$methodName];
        $where = $class['file'] . ':' . $t[$open]->line;
        try {
            $args = $this->vkSplit($t, $open, $this->vkClose($t, $open));
            if (! isset($args[$rulesAt])) {
                if ($rulesAt === 0 && $args === []) {
                    return; // Validator::make(...)->validate() の引数なしの validate
                }
                throw new UnexpectedValueException('規則の引数が無い');
            }
            $rules = $this->vkResolve($classKey, $method, $args[$rulesAt][0], $args[$rulesAt][1]);
            $names = ['keys' => [], 'param' => null];
            if ($namesAt !== null && isset($args[$namesAt])) {
                $names = $this->vkResolve($classKey, $method, $args[$namesAt][0], $args[$namesAt][1]);
            }
            if ($rules['param'] !== null) {
                $forwarders[$this->vkMethodOwner($classKey, $methodName) . '::' . $methodName] = [$rules['param'], $names['param']];

                return;
            }
            $sites[] = ['where' => $where, 'keys' => array_values(array_unique($rules['keys'])), 'named' => array_values(array_unique($names['keys']))];
        } catch (UnexpectedValueException $e) {
            $unreadable[] = "{$where}: {$e->getMessage()}";
        }
    }

    private function vkMethodOwner(string $classKey, string $methodName): string
    {
        return $this->vkMethodOf($classKey, $methodName)[0] ?? $classKey;
    }

    private function vkFormRequestSite(string $classKey, array &$sites, array &$unreadable): void
    {
        $class = $this->vkClasses[$classKey];
        $where = $class['file'] . ':' . $class['t'][$class['methods']['rules']['s']]->line;
        try {
            $keys = $this->vkReturns($classKey, $class['methods']['rules']);
            $named = isset($class['methods']['attributes']) ? $this->vkReturns($classKey, $class['methods']['attributes']) : [];
            $sites[] = ['where' => $where, 'keys' => array_values(array_unique($keys)), 'named' => array_values(array_unique($named))];
        } catch (UnexpectedValueException $e) {
            $unreadable[] = "{$where}: {$e->getMessage()}";
        }
    }

    // ---------- 式をたどる ----------

    /**
     * 式 [s, e) のキー。param はその式がメソッドの引数そのもののときの位置
     *
     * @param  array<string, true>  $resolving  たどっている途中の変数・メソッド（自分を参照する代入で回り続けないように）
     * @return array{keys: list<string>, param: ?int}
     */
    private function vkResolve(string $classKey, array $method, int $s, int $e, array $resolving = []): array
    {
        $t = $this->vkClasses[$classKey]['t'];
        if ($s >= $e) {
            throw new UnexpectedValueException('空の式');
        }
        // 括弧
        if ($t[$s]->text === '(' && $this->vkClose($t, $s) === $e - 1) {
            return $this->vkResolve($classKey, $method, $s + 1, $e - 1, $resolving);
        }
        // 配列のリテラル
        if ($t[$s]->text === '[' && $this->vkClose($t, $s) === $e - 1) {
            return ['keys' => $this->vkArrayKeys($classKey, $method, $s, $e - 1, $resolving), 'param' => null];
        }
        // array_merge(...)
        if ($t[$s]->id === T_STRING && strtolower($t[$s]->text) === 'array_merge' && $t[$s + 1]->text === '(' && $this->vkClose($t, $s + 1) === $e - 1) {
            $keys = [];
            foreach ($this->vkSplit($t, $s + 1, $e - 1) as [$a, $b]) {
                $keys = array_merge($keys, $this->vkResolve($classKey, $method, $a, $b, $resolving)['keys']);
            }

            return ['keys' => $keys, 'param' => null];
        }
        // $this->メソッド(...)
        if ($t[$s]->text === '$this' && $t[$s + 1]->id === T_OBJECT_OPERATOR && $t[$s + 2]->id === T_STRING
            && $t[$s + 3]->text === '(' && $this->vkClose($t, $s + 3) === $e - 1) {
            $found = $this->vkMethodOf($classKey, $t[$s + 2]->text);
            if ($found === null) {
                throw new UnexpectedValueException("メソッドが見つからない（{$t[$s + 2]->text}）");
            }
            $guard = $found[0] . '::' . $t[$s + 2]->text;
            if (isset($resolving[$guard])) {
                return ['keys' => [], 'param' => null];
            }

            return ['keys' => $this->vkReturns($found[0], $found[1], $resolving + [$guard => true]), 'param' => null];
        }
        // self::定数 / static::定数
        if (in_array($t[$s]->text, ['self', 'static'], true) && $t[$s + 1]->id === T_DOUBLE_COLON && $t[$s + 2]->id === T_STRING && $e === $s + 3) {
            $range = $this->vkClasses[$classKey]['consts'][$t[$s + 2]->text] ?? null;
            if ($range === null) {
                throw new UnexpectedValueException("定数が見つからない（{$t[$s + 2]->text}）");
            }

            return $this->vkResolve($classKey, $method, $range[0], $range[1], $resolving);
        }
        // $変数
        if ($t[$s]->id === T_VARIABLE && $t[$s]->text !== '$this' && $e === $s + 1) {
            return $this->vkVariable($classKey, $method, $t[$s]->text, $resolving);
        }
        throw new UnexpectedValueException('たどれない式（' . $this->vkText($t, $s, $e) . '）');
    }

    /** 配列のリテラル [open..close] の深さ 1 のキー */
    private function vkArrayKeys(string $classKey, array $method, int $open, int $close, array $resolving): array
    {
        $t = $this->vkClasses[$classKey]['t'];
        $keys = [];
        foreach ($this->vkSplit($t, $open, $close) as [$a, $b]) {
            if ($t[$a]->id === T_ELLIPSIS) {
                $keys = array_merge($keys, $this->vkResolve($classKey, $method, $a + 1, $b, $resolving)['keys']);

                continue;
            }
            $arrow = null;
            for ($q = $a; $q < $b; $q++) {
                if ($this->vkIsOpen($t[$q])) {
                    $q = $this->vkClose($t, $q);
                } elseif ($t[$q]->id === T_DOUBLE_ARROW) {
                    $arrow = $q;
                    break;
                }
            }
            if ($arrow === null) {
                throw new UnexpectedValueException('キーの無い要素（' . $this->vkText($t, $a, $b) . '）');
            }
            $key = $arrow === $a + 1 ? $this->vkString($t[$a]) : null;
            if ($key === null) {
                throw new UnexpectedValueException('文字列でないキー（' . $this->vkText($t, $a, $arrow) . '）');
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** メソッドの中の変数のキー（代入・`$x['key'] = …`・`$x += …`）。代入が無くメソッドの引数なら param を返す */
    private function vkVariable(string $classKey, array $method, string $name, array $resolving): array
    {
        $t = $this->vkClasses[$classKey]['t'];
        if (isset($resolving[$name])) {
            return ['keys' => [], 'param' => null]; // $rules = array_merge($rules, [...]) の右辺の $rules（ほかの代入で集める）
        }
        $resolving[$name] = true;
        $keys = [];
        $assigned = false;
        for ($q = $method['s']; $q < $method['e']; $q++) {
            if ($t[$q]->id !== T_VARIABLE || $t[$q]->text !== $name) {
                continue;
            }
            $next = $t[$q + 1];
            if ($next->text === '=' || $next->id === T_PLUS_EQUAL) {
                $assigned = true;
                $keys = array_merge($keys, $this->vkResolve($classKey, $method, $q + 2, $this->vkStatementEnd($t, $q + 2), $resolving)['keys']);
            } elseif ($next->text === '[') {
                $bracketEnd = $this->vkClose($t, $q + 1);
                if (($t[$bracketEnd + 1]->text ?? '') === '=') {
                    $assigned = true;
                    $key = $bracketEnd === $q + 3 ? $this->vkString($t[$q + 2]) : null;
                    if ($key === null) {
                        throw new UnexpectedValueException("{$name}[…] に文字列でないキーで代入している");
                    }
                    $keys[] = $key;
                }
            }
        }
        if (! $assigned) {
            $param = array_search($name, $method['params'], true);
            if ($param !== false) {
                return ['keys' => [], 'param' => $param];
            }
            throw new UnexpectedValueException("{$name} の代入が見つからない");
        }

        return ['keys' => $keys, 'param' => null];
    }

    /** メソッドの return の式すべてのキー */
    private function vkReturns(string $classKey, array $method, array $resolving = []): array
    {
        $t = $this->vkClasses[$classKey]['t'];
        $keys = [];
        $found = false;
        for ($q = $method['s']; $q < $method['e']; $q++) {
            if ($t[$q]->id === T_FUNCTION) {
                // 無名関数の中の return は、このメソッドの戻り値ではない（アロー関数 fn には return が無い）
                $body = $q;
                while ($t[$body]->text !== '{') {
                    $body++;
                }
                $q = $this->vkClose($t, $body);

                continue;
            }
            if ($t[$q]->id === T_RETURN) {
                $found = true;
                $keys = array_merge($keys, $this->vkResolve($classKey, $method, $q + 1, $this->vkStatementEnd($t, $q + 1), $resolving)['keys']);
            }
        }
        if (! $found) {
            throw new UnexpectedValueException('return が無い');
        }

        return $keys;
    }
}
