<?php

namespace Tests\Concerns;

/**
 * 顧客（買主マスタ）の登録・編集画面の JS が、送信の瞬間に元号・年・月・日の欄から組む hidden の `birth_date` を求める。
 * 不動産と住宅事業の顧客の画面のテストが使う（同じビューを共用するので、組み立ても 1 か所で動かす）。
 *
 * ⚠ 使う側は buyerFormUrl()（script を読む画面）と htmlOf()（SubmitsScreenForms）を持つ。
 */
trait RunsBuyerFormScript
{
    abstract protected function buyerFormUrl(): string;

    /**
     * 顧客の画面の buyerForm() の init() が登録する submit の処理を node で動かし、hidden の birth_date に入る値を返す。
     * 欄の値は $form（送るフォームの項目）から取る。
     */
    protected function jsBirthDate(array $form): string
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので生年月日を組む JavaScript を動かせない');
        }
        $found = preg_match('/<script>\s*(function buyerForm\(\) \{.*?)<\/script>/su', $this->htmlOf($this->buyerFormUrl()), $m);
        $this->assertSame(1, $found, 'function buyerForm() の script が描かれていない');

        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const els = {
                'select[name="birth_era"]': { value: input.era },
                'input[name="birth_year"]': { value: input.year },
                'input[name="birth_month"]': { value: input.month },
                'input[name="birth_day"]': { value: input.day },
                'input[name="birth_date"]': { value: '' },
            };
            let onSubmit = null;
            const context = vm.createContext({ document: { querySelector(s) { return els[s] || null; } } });
            vm.runInContext(input.script, context);
            const data = vm.runInContext('buyerForm()', context);
            data.$el = { closest() { return { addEventListener(type, fn) { if (type === 'submit') { onSubmit = fn; } } }; } };
            data.init();
            if (onSubmit) { onSubmit(); }
            process.stdout.write(JSON.stringify({ birthDate: els['input[name="birth_date"]'].value, listened: onSubmit !== null }));
            JS;
        $file = tempnam(sys_get_temp_dir(), 'buyer-form-');
        try {
            file_put_contents($file, json_encode([
                'script' => $m[1], 'era' => $form['fields']['birth_era'] ?? '', 'year' => $form['fields']['birth_year'] ?? '',
                'month' => $form['fields']['birth_month'] ?? '', 'day' => $form['fields']['birth_day'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }
        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で生年月日を組む JavaScript を動かせなかった:\n" . $output);
        $this->assertTrue($result['listened'], '送信の瞬間に生年月日を組む処理が登録されていない');

        return $result['birthDate'];
    }
}
