<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;

/**
 * 不動産の顧客（買主マスタ。住宅事業と共用のコントローラ・画面）の一覧・登録・詳細・編集・削除と、
 * アンケートの登録・編集・削除を、描いた画面から送る往復で見る。
 *
 * ⚠ 顧客の画面は Alpine の値を持たない素のフォーム（parseForm()）。生年月日だけは、送信の瞬間に JS が
 *   元号・年・月・日の欄から hidden の `birth_date` を組む。その組み立ては jsBirthDate() で画面の script を
 *   node で動かして求め、送る値にする。
 */
class CustomerScreensTest extends RealEstateScreenTestCase
{
    private function question(string $label = 'ご希望のエリア'): SurveyQuestion
    {
        return SurveyQuestion::create(['department' => 'realestate', 'label' => $label, 'question_type' => 'text', 'sort_order' => 1, 'is_active' => true]);
    }

    public function test_the_list_shows_the_customers_of_the_department(): void
    {
        $buyer = $this->buyer();
        Buyer::create(['last_name' => '住宅', 'first_name' => 'だけ'])->addToDepartment('housing', '2026-09-01');

        $html = $this->htmlOf(route('realestate.customers.index'));

        $this->assertSame(1, preg_match('/href="' . preg_quote(route('realestate.customers.show', $buyer), '/') . '"/', $html), '一覧に不動産の顧客が出ていない');
        $this->assertFalse(str_contains($html, '住宅 だけ'), '住宅事業だけの顧客が不動産の一覧に出ている');
    }

    public function test_a_customer_is_registered_from_the_screen_with_a_survey_answer(): void
    {
        $question = $this->question();
        $url = route('realestate.customers.create');
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.store') . '"');
        $form = $this->fill($form, [
            'acquired_date' => '2026-10-01', 'last_name' => '佐藤', 'first_name' => '一郎', 'last_name_kana' => 'サトウ', 'first_name_kana' => 'イチロウ',
            'birth_era' => 'S', 'birth_year' => '55', 'birth_month' => '1', 'birth_day' => '2',
            'family_adults' => '2', 'family_children' => '1', 'prefecture' => '愛媛県', 'city' => '松山市', 'phone' => '089-123-4567',
            'survey[' . $question->id . ']' => '道後周辺',
        ]);
        $form['fields']['birth_date'] = $this->jsBirthDate($form);
        $this->assertSame('1980-01-02', $form['fields']['birth_date'], '画面の JS が元号の年を西暦に直していない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '顧客を登録しました。');
        $buyer = Buyer::where('last_name', '佐藤')->firstOrFail();
        $this->assertSame(['1980-01-02', 'S', 2, 1, '089-123-4567'], [$buyer->birth_date->toDateString(), $buyer->birth_era, $buyer->family_adults, $buyer->family_children, $buyer->phone]);
        $this->assertTrue($buyer->belongsToDepartment('realestate'));
        $survey = BuyerSurvey::where('buyer_id', $buyer->id)->firstOrFail();
        $this->assertSame(['realestate', '道後周辺'], [$survey->department, $survey->answers()->value('answer_value')]);
    }

    public function test_a_customer_without_a_name_is_refused(): void
    {
        $url = route('realestate.customers.create');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.store') . '"'), ['acquired_date' => '2026-10-01', 'last_name' => '', 'first_name' => '一郎']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '姓']));
        $this->assertSame(0, Buyer::count());
    }

    public function test_the_detail_and_edit_screens_save_what_they_show(): void
    {
        $buyer = $this->buyer();
        $buyer->update(['birth_date' => '1990-05-06', 'birth_era' => 'H', 'phone' => '090-0000-0000']);
        $this->htmlOf(route('realestate.customers.show', $buyer));
        $url = route('realestate.customers.edit', $buyer);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.update', $buyer) . '"');
        $this->assertSame(['PUT', 'H', '2', '5', '6', '2026-09-01'],
            [$form['fields']['_method'], $form['fields']['birth_era'], $form['fields']['birth_year'], $form['fields']['birth_month'], $form['fields']['birth_day'], $form['fields']['acquired_date']],
            '編集画面に今の値が入っていない');
        $form = $this->fill($form, ['employer' => '松山商事']);
        $form['fields']['birth_date'] = $this->jsBirthDate($form);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '顧客情報を更新しました。');
        $buyer->refresh();
        $this->assertSame(['松山商事', '1990-05-06', '090-0000-0000'], [$buyer->employer, $buyer->birth_date->toDateString(), $buyer->phone]);
    }

    public function test_a_customer_is_removed_from_the_department_from_the_detail_screen(): void
    {
        $buyer = $this->buyer();
        $url = route('realestate.customers.show', $buyer);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.destroy', $buyer) . '"');
        $this->assertSame('DELETE', $form['fields']['_method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '顧客を削除しました。');
        $this->assertTrue($buyer->fresh()->trashed(), 'どの部署にも属さなくなった顧客が削除されていない');
    }

    // ============================================================
    // アンケート
    // ============================================================

    public function test_a_survey_is_registered_edited_and_deleted_from_the_screens(): void
    {
        $question = $this->question();
        $buyer = $this->buyer();
        $show = route('realestate.customers.show', $buyer);

        $url = route('realestate.customers.surveys.create', $buyer);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.surveys.store', $buyer) . '"'),
            ['survey_date' => '2026-10-03', 'survey_memo' => '来店', 'survey[' . $question->id . ']' => '石井地区']);
        $html = $this->landed($this->submit($form, $url));
        $this->assertFlash($html, 'success', 'アンケートを登録しました。');
        $survey = BuyerSurvey::where('buyer_id', $buyer->id)->firstOrFail();
        $this->assertSame(['2026-10-03', '来店', '石井地区'], [$survey->survey_date->toDateString(), $survey->memo, $survey->answers()->value('answer_value')]);

        $url = route('realestate.customers.surveys.edit', [$buyer, $survey]);
        // ⚠ 削除のフォームも同じ送り先（`action="…" onsubmit=…`）を持つので、`>` で閉じる更新のフォームを掴む（Bug #47）
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.surveys.update', [$buyer, $survey]) . '">');
        $this->assertSame(['PUT', '石井地区'], [$form['fields']['_method'], $form['fields']['survey[' . $question->id . ']']], '編集画面に今の回答が入っていない');
        $form = $this->fill($form, ['survey[' . $question->id . ']' => '久米地区']);
        $html = $this->landed($this->submit($form, $url));
        $this->assertFlash($html, 'success', 'アンケートを更新しました。');
        $this->assertSame('久米地区', $survey->answers()->value('answer_value'));

        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('realestate.customers.surveys.destroy', [$buyer, $survey]) . '"');
        $this->assertSame('DELETE', $form['fields']['_method']);
        $html = $this->landed($this->submit($form, $show));
        $this->assertFlash($html, 'success', 'アンケートを削除しました。');
        $this->assertNull($survey->fresh());
    }

    // ============================================================
    // 画面の JS（送信の瞬間に生年月日を組む）
    // ============================================================

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
        $found = preg_match('/<script>\s*(function buyerForm\(\) \{.*?)<\/script>/su', $this->htmlOf(route('realestate.customers.create')), $m);
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
