<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Validator;

/**
 * 入力チェック全体で「配列の形」を見る（Bug #121。`AppServiceProvider::boot()` が `Validator::resolver()` に登録する）。
 *
 * 手で組んだ送信（`x[]=1`・配列のつもりの欄に `x`）で 500 になる形を 2 つ止める:
 * - 保存の 500: `exists` / `required` / `max` だけの欄は配列を素通りさせる（全件テストの記録で 71 か所）
 * - 戻った画面の 500: 断ると `Handler::invalid()` が送られた入力をそのまま控え、画面が `old()` を文字のつもりで描く。
 *   断ったときは形が合わない欄を request から外してから控えさせる（`forgetMismatchedInput()`。`bootstrap/app.php` の render が呼ぶ）
 *
 * 判定: その欄が「配列を求める」のは、自分の規則に `array` / `list` / `required_array_keys` があるか、その欄の下に規則がある
 * （`details.*`・`costs.*.name` など。`*` は 1 段ぶん）とき。求めないのに配列・求めるのに配列でない、を「形が合わない」とする。
 * 見る欄は規則のキーとその親（`*` はデータの今のキーで開く）。規則の無い欄（アンケートの `survey` など）は見ない。
 *
 * ⚠ 形はほかの規則より**先に**見て、合わない欄があれば、ほかの欄の規則も after のフックも動かさずに断る。
 *   自作の規則（クロージャ）が request の値を文字のつもりで読み、配列で落ちるため（買主の元号の確かめ・Sheet の URL の確かめ。2026-10-28 に測った）。
 *   形が合わない欄の文言は、その欄の型の規則（`TYPE_RULES`。配列を受けても落ちない組み込みの規則だけ）があればその規則の今の文、
 *   無ければ `MESSAGE`。手で組んだ送信だけの場面なので、ほかの欄の誤りは直して送り直すと出る
 * ⚠ 初めの規則（`initialRules`）と展開したあとの規則（`rules`）の両方を見る。展開したあとだけだと、データの無いワイルドカードの規則
 *   （`ids.*` で `ids` が文字）が消えていて、親が配列を求めることが分からない（実測。規則が消えるので入力チェックは黙って通す）。
 *   初めだけだと `sometimes()` で足した規則が見えない
 * ⚠ 値が null（送られていない・空欄）は見ない。アップロードしたファイルは配列でない値として扱う。
 *   `exclude*` の規則を持つ欄は断らない（保存しない欄）が、断ったときの控えからは外す（画面は old() で描く）
 * ⚠ 点・`*` を含むキー（置き換え文字 `__dot__…`・`__asterisk__…`）は見ない
 * ⚠ 入力チェックの前に input を読むコードは止まらない（そのコントローラで直す）
 */
class ShapeCheckingValidator extends Validator
{
    /** 足す誤りの文（`lang/ja/validation.php` はフレームワークのキーと 1 対 1 なので、ここに置く） */
    public const MESSAGE = ':attributeの形が正しくありません。画面を開き直して、もう一度入力してください。';

    /** 配列を求める規則 */
    private const ARRAY_RULES = ['array', 'list', 'required_array_keys'];

    /** 形が合わない欄でも動かす型の規則（配列を受けても落ちずに false を返す組み込みの規則だけ。文言を今のままにするため） */
    private const TYPE_RULES = ['string', 'integer', 'numeric', 'decimal', 'boolean', 'date', 'date_format', 'email', 'url', 'in', 'regex', 'array', 'list'];

    public function passes()
    {
        $mismatches = $this->mismatchesIn(fn (string $key) => $this->getValue($key));
        if ($mismatches === []) {
            return parent::passes();
        }

        $this->messages = new MessageBag;
        [$this->distinctValues, $this->failedRules] = [[], []];

        foreach ($mismatches as $key) {
            foreach ($this->rules[$key] ?? [] as $rule) {
                if (is_string($rule) && in_array($this->ruleName($rule), self::TYPE_RULES, true)) {
                    $this->validateAttribute($key, $rule);
                    if ($this->messages->has($key)) {
                        break;
                    }
                }
            }
            if (! $this->messages->has($key)) {
                $this->failedRules[$key]['Shape'] = [];
                $this->messages->add($key, $this->makeReplacements(self::MESSAGE, $this->labelledKey($key), 'Shape', []));
            }
        }

        return false;
    }

    /**
     * 和名のある欄の名前（その欄に和名が無ければ、和名のある親をさかのぼる）。
     * 行の欄（`details.0`）は規則にも和名にも出てこないので、そのままだと英字の名前が文に出る（Bug #37 の形）
     */
    private function labelledKey(string $key): string
    {
        for ($name = $key; ; $name = substr($name, 0, (int) strrpos($name, '.'))) {
            foreach (array_unique([$name, $this->getPrimaryAttribute($name)]) as $candidate) {
                if ($this->getAttributeFromLocalArray($candidate) || $this->getAttributeFromTranslations($candidate)) {
                    return $name;
                }
            }
            if (! str_contains($name, '.')) {
                return $key;
            }
        }
    }

    /** @return list<string> この入力チェックのデータで、形が合わない欄 */
    public function shapeMismatches(): array
    {
        return $this->mismatchesIn(fn (string $key) => $this->getValue($key));
    }

    /**
     * 形が合わない欄を request の入力から外す（断ったときの控えに入れない）。
     * 判定は request の値で行う（CSV の行など request でないデータの入力チェックが、同じ名前の欄を巻き込まないように）
     */
    public function forgetMismatchedInput(Request $request): void
    {
        // exclude の欄も外す（保存しない欄でも、画面は old() で描く）
        $keys = $this->mismatchesIn(fn (string $key) => $request->input($key), skipExcluded: false);
        if ($keys === []) {
            return;
        }
        foreach ([$request->request, $request->query] as $bag) {
            $all = $bag->all();
            Arr::forget($all, $keys);
            $bag->replace($all);
        }
    }

    /** @return list<string> */
    private function mismatchesIn(callable $valueOf, bool $skipExcluded = true): array
    {
        $patterns = array_values(array_filter(
            array_unique(array_merge(array_map('strval', array_keys($this->initialRules)), array_map('strval', array_keys($this->rules)))),
            fn (string $key) => ! $this->hasPlaceholder($key),
        ));

        $candidates = [];
        foreach ($patterns as $pattern) {
            $segments = explode('.', $pattern);
            for ($n = 1; $n <= count($segments); $n++) {
                foreach ($this->expand(array_slice($segments, 0, $n), $valueOf) as $path) {
                    $candidates[$path] = true;
                }
            }
        }

        $out = [];
        foreach (array_keys($candidates) as $path) {
            $path = (string) $path;
            if ($skipExcluded && ($this->shouldBeExcluded($path) || $this->hasExcludeRule($this->rules[$path] ?? []))) {
                continue;
            }
            $value = $valueOf($path);
            if ($value === null) {
                continue;
            }
            if (is_array($value) !== $this->wantsArray($path, $patterns)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /** `*` をデータの今のキーで開いた、実際の欄の名前 @return list<string> */
    private function expand(array $segments, callable $valueOf): array
    {
        $paths = [''];
        foreach ($segments as $segment) {
            $next = [];
            foreach ($paths as $path) {
                if ($segment !== '*') {
                    $next[] = $path === '' ? $segment : "{$path}.{$segment}";

                    continue;
                }
                $value = $path === '' ? null : $valueOf($path);
                if (is_array($value)) {
                    foreach (array_keys($value) as $key) {
                        $next[] = "{$path}.{$key}";
                    }
                }
            }
            $paths = $next;
        }

        return $paths;
    }

    private function wantsArray(string $path, array $patterns): bool
    {
        $segments = explode('.', $path);
        foreach ($patterns as $pattern) {
            $patternSegments = explode('.', $pattern);
            if (count($patternSegments) < count($segments) || ! $this->matchesPrefix($patternSegments, $segments)) {
                continue;
            }
            if (count($patternSegments) > count($segments)) {
                return true; // その欄の下に規則がある
            }
            if ($this->hasArrayRule($this->rules[$path] ?? $this->initialRules[$pattern] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /** 規則の名前の先頭の段が、欄の名前と合うか（規則の `*` は何にでも合う） */
    private function matchesPrefix(array $patternSegments, array $segments): bool
    {
        foreach ($segments as $i => $segment) {
            if ($patternSegments[$i] !== '*' && $patternSegments[$i] !== (string) $segment) {
                return false;
            }
        }

        return true;
    }

    private function hasArrayRule(mixed $rules): bool
    {
        foreach ($this->ruleList($rules) as $rule) {
            if ($rule instanceof ArrayRule || (is_string($rule) && in_array($this->ruleName($rule), self::ARRAY_RULES, true))) {
                return true;
            }
        }

        return false;
    }

    private function hasExcludeRule(mixed $rules): bool
    {
        foreach ($this->ruleList($rules) as $rule) {
            if (is_string($rule) && str_starts_with($this->ruleName($rule), 'exclude')) {
                return true;
            }
        }

        return false;
    }

    /** 規則の書き方（`a|b`・配列・物）をそろえる */
    private function ruleList(mixed $rules): array
    {
        return is_string($rules) ? explode('|', $rules) : (is_array($rules) ? $rules : [$rules]);
    }

    private function ruleName(string $rule): string
    {
        return strtolower(trim(explode(':', $rule, 2)[0]));
    }

    private function hasPlaceholder(string $key): bool
    {
        return str_contains($key, '__dot__' . static::$placeholderHash) || str_contains($key, '__asterisk__' . static::$placeholderHash);
    }
}
