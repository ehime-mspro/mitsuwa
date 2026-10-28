<?php

namespace Tests\Feature;

use Tests\Concerns\CollectsValidationKeys;
use Tests\TestCase;

/**
 * 画面が `old('x')` で描く欄は、どこかの入力チェックに規則がある（Bug #121）。
 *
 * 配列の形は ShapeCheckingValidator が規則で見る。規則の無い欄は形を見られないので、手で組んだ配列が控えに残り、
 * 戻った画面が `old()` を文字のつもりで描いて 500 になる（2026-10-28 に `survey_memo`・`return_to`・`apply_from_month`・
 * `birth_year` などで実測）。規則を持たない欄は WITHOUT_RULE に理由つきで載せる（全件分類。Top trap #13）。
 *
 * ⚠ 決裁の画面（approvals/）は「社内決裁申請」の会話の担当なので見ない
 * ⚠ 死角: 「どこかの入力チェックに同じ名前の規則がある」で見るので、別の画面の同じ名前の規則に当たると素通りする。
 *   `old($変数)` の形・部品の中で組み立てる名前も見えない
 */
class OldInputRuleCoverageTest extends TestCase
{
    use CollectsValidationKeys;

    /** 規則を持たない欄 => 理由 */
    private const WITHOUT_RULE = [
        'keep_adding'   => 'old() を checked の真偽にしか使わない（配列でも 500 にならない）',
        'remember'      => 'old() を checked の真偽にしか使わない（配列でも 500 にならない）',
        'survey'        => 'アンケートの回答。規則を持たず、after のフックで SurveyAnswers が形を見る（Bug #120）',
        'survey.{$qId}' => 'アンケートの回答の 1 問。同上',
    ];

    /** 拾えた数の下限（走査が空振りして緑になるのを止める。2026-10-28 の実測は キー 239・行 611） */
    private const MIN_KEYS = 200;

    private const MIN_LINES = 550;

    public function test_every_old_input_key_has_a_rule_or_a_reason(): void
    {
        $collected = $this->collectValidationKeys($this->appHttpSources());
        $this->assertSame([], $collected['unreadable'], '入力チェックを読めない書き方がある');
        $ruleKeys = [];
        foreach ($collected['sites'] as $site) {
            foreach ($site['keys'] as $key) {
                $ruleKeys[$key] = true;
            }
        }

        $uses = $this->oldInputKeys();
        $this->assertGreaterThanOrEqual(self::MIN_KEYS, count($uses), '画面の old() のキーを拾えていない');
        $this->assertGreaterThanOrEqual(self::MIN_LINES, array_sum(array_map('count', $uses)), '画面の old() を拾えていない');

        $missing = [];
        foreach ($uses as $key => $where) {
            if (! $this->hasRule($key, $ruleKeys) && ! isset(self::WITHOUT_RULE[$key])) {
                $missing[] = "{$key}（" . implode(', ', array_slice($where, 0, 3)) . '）';
            }
        }
        $this->assertSame([], $missing, '画面が old() で描くのに、どの入力チェックにも規則が無い欄がある（規則を足すか、WITHOUT_RULE に理由つきで載せる）');

        foreach (array_keys(self::WITHOUT_RULE) as $key) {
            $this->assertArrayHasKey($key, $uses, "WITHOUT_RULE の「{$key}」がどの画面にも無い（古い分類）");
            $this->assertFalse($this->hasRule($key, $ruleKeys), "WITHOUT_RULE の「{$key}」に規則がある（分類から外す）");
        }
    }

    /** @return array<string, list<string>> キー => 「ビュー:行」 */
    private function oldInputKeys(): array
    {
        $root = resource_path('views') . '/';
        $uses = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = str_replace($root, '', $file->getPathname());
            if (str_starts_with($relative, 'approvals/')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (! preg_match_all('/\bold\(\s*([\'"])([^\'"]+)\1/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[2] as [$key, $offset]) {
                $uses[$key][] = $relative . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
            }
        }
        ksort($uses);

        return $uses;
    }

    /** 規則のキー（`costs.*.name` の `*` は 1 段ぶん）に当たるか */
    private function hasRule(string $key, array $ruleKeys): bool
    {
        if (isset($ruleKeys[$key])) {
            return true;
        }
        foreach (array_keys($ruleKeys) as $ruleKey) {
            if (preg_match('/^' . str_replace('\*', '[^.]+', preg_quote((string) $ruleKey, '/')) . '$/', $key)) {
                return true;
            }
        }

        return false;
    }
}
