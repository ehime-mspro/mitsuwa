<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use Tests\Concerns\RunsBuyerFormScript;

/**
 * 住宅事業の顧客（買主マスタ。不動産と共用のコントローラ・画面）の一覧・登録・詳細・編集・削除と、
 * アンケート（来場分譲地・担当者つき）の登録・編集・削除、契約フォームの「新規顧客を登録」の小窓、
 * 登録画面の重複の確認と他部署の取り込み、一覧のランクの小窓を、描いた画面から送る往復で見る。
 *
 * ⚠ 不動産の顧客の画面のテスト（RealEstate\Screens\CustomerScreensTest）が入力の上限・生年月日を見ている。
 *   ここは住宅事業の URL と、住宅事業だけの欄（来場分譲地・担当者）と Ajax を見る。
 */
class CustomerScreensTest extends HousingScreenTestCase
{
    use RunsBuyerFormScript;

    protected function buyerFormUrl(): string
    {
        return route('housing.customers.create');
    }

    private function question(string $label = 'ご希望の間取り'): SurveyQuestion
    {
        return SurveyQuestion::create(['department' => 'housing', 'label' => $label, 'question_type' => 'text', 'sort_order' => 1, 'is_active' => true]);
    }

    private function storeForm(array $values): array
    {
        $form = $this->parseForm($this->htmlOf(route('housing.customers.create')), 'action="' . route('housing.customers.store') . '"');
        $form = $this->fill($form, array_merge(['acquired_date' => '2026-10-01', 'last_name' => '佐藤', 'first_name' => '一郎'], $values));
        $form['fields']['birth_date'] = $this->jsBirthDate($form);

        return $form;
    }

    public function test_the_list_shows_the_customers_of_the_department(): void
    {
        $buyer = $this->buyer();
        Buyer::create(['last_name' => '不動産', 'first_name' => 'だけ'])->addToDepartment('realestate', '2026-09-01');

        $html = $this->htmlOf(route('housing.customers.index'));

        $this->assertSame(1, preg_match('/href="' . preg_quote(route('housing.customers.show', $buyer), '/') . '"/', $html), '一覧に住宅事業の顧客が出ていない');
        $this->assertFalse(str_contains($html, '不動産 だけ'), '不動産だけの顧客が住宅事業の一覧に出ている');
    }

    public function test_a_customer_is_registered_with_a_visit_survey(): void
    {
        $question = $this->question();
        $project = $this->project();
        $staff = $this->member(\App\Enums\UserRole::Staff, '担当 四郎');
        $url = route('housing.customers.create');

        $html = $this->landed($this->submit($this->storeForm([
            'project_id' => (string) $project->id, 'staff_user_id' => (string) $staff->id, 'prefecture' => '愛媛県', 'city' => '松山市',
            'survey[' . $question->id . ']' => '4LDK',
        ]), $url));

        $this->assertFlash($html, 'success', '顧客を登録しました。');
        $buyer = Buyer::where('last_name', '佐藤')->firstOrFail();
        $this->assertTrue($buyer->belongsToDepartment('housing'));
        $survey = BuyerSurvey::where('buyer_id', $buyer->id)->firstOrFail();
        $this->assertSame(['housing', $project->id, $staff->id, '担当 四郎', '4LDK'],
            [$survey->department, $survey->project_id, $survey->staff_user_id, $survey->staff_name, $survey->answers()->value('answer_value')]);
    }

    public function test_a_visit_survey_with_a_missing_project_or_staff_is_refused(): void
    {
        $question = $this->question();
        $url = route('housing.customers.create');
        $form = $this->storeForm(['survey[' . $question->id . ']' => '4LDK']);
        // 画面を開いたあとで分譲地・担当者が消えた（手で組んだ送信と同じ）
        $form['fields']['project_id'] = '999999';
        $form['fields']['staff_user_id'] = '999999';

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.exists', ['attribute' => '分譲地']));
        $this->assertErrorItem($html, trans('validation.exists', ['attribute' => '担当者']));
        $this->assertSame(0, Buyer::count());
    }

    public function test_the_detail_and_edit_screens_save_what_they_show(): void
    {
        $buyer = $this->buyer();
        $this->htmlOf(route('housing.customers.show', $buyer));
        $url = route('housing.customers.edit', $buyer);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('housing.customers.update', $buyer) . '"');
        $this->assertSame(['PUT', '2026-09-01', '愛媛県', '松山市'],
            [$form['fields']['_method'], $form['fields']['acquired_date'], $form['fields']['prefecture'], $form['fields']['city']], '編集画面に今の値が入っていない');
        $form = $this->fill($form, ['employer' => '松山商事']);
        $form['fields']['birth_date'] = $this->jsBirthDate($form);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '顧客情報を更新しました。');
        $this->assertSame('松山商事', $buyer->fresh()->employer);
    }

    public function test_a_customer_is_removed_from_the_department_from_the_detail_screen(): void
    {
        $buyer = $this->buyer();
        $buyer->addToDepartment('realestate', '2026-09-02');
        $url = route('housing.customers.show', $buyer);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('housing.customers.destroy', $buyer) . '"');
        $this->assertSame('DELETE', $form['fields']['_method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '顧客を削除しました。');
        $buyer->refresh();
        $this->assertFalse($buyer->trashed(), '不動産にも属する顧客が削除された');
        $this->assertSame([false, true], [$buyer->belongsToDepartment('housing'), $buyer->belongsToDepartment('realestate')]);
    }

    public function test_a_survey_is_registered_edited_and_deleted_from_the_screens(): void
    {
        $question = $this->question();
        $project = $this->project();
        $other = $this->project(['project_code' => 'RE-PRJ-002', 'project_name' => '北条分譲地']);
        $buyer = $this->buyer();
        $show = route('housing.customers.show', $buyer);

        $url = route('housing.customers.surveys.create', $buyer);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('housing.customers.surveys.store', $buyer) . '"'),
            ['survey_date' => '2026-10-03', 'project_id' => (string) $project->id, 'staff_user_id' => (string) $this->user->id, 'survey[' . $question->id . ']' => '3LDK']);
        $html = $this->landed($this->submit($form, $url));
        $this->assertFlash($html, 'success', 'アンケートを登録しました。');
        $survey = BuyerSurvey::where('buyer_id', $buyer->id)->firstOrFail();
        $this->assertSame([$project->id, $this->user->id, '3LDK'], [$survey->project_id, $survey->staff_user_id, $survey->answers()->value('answer_value')]);

        $url = route('housing.customers.surveys.edit', [$buyer, $survey]);
        // ⚠ 削除のフォームも同じ送り先を持つので、`>` で閉じる更新のフォームを掴む（Bug #47）
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('housing.customers.surveys.update', [$buyer, $survey]) . '">');
        $this->assertSame(['PUT', (string) $project->id, (string) $this->user->id, '3LDK'],
            [$form['fields']['_method'], $form['fields']['project_id'], $form['fields']['staff_user_id'], $form['fields']['survey[' . $question->id . ']']], '編集画面に今の値が入っていない');
        $form = $this->fill($form, ['project_id' => (string) $other->id, 'survey[' . $question->id . ']' => '4LDK']);
        $html = $this->landed($this->submit($form, $url));
        $this->assertFlash($html, 'success', 'アンケートを更新しました。');
        $survey->refresh();
        $this->assertSame([$other->id, '4LDK'], [$survey->project_id, $survey->answers()->value('answer_value')]);

        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('housing.customers.surveys.destroy', [$buyer, $survey]) . '"');
        $html = $this->landed($this->submit($form, $show));
        $this->assertFlash($html, 'success', 'アンケートを削除しました。');
        $this->assertNull($survey->fresh());
    }

    public function test_a_survey_with_a_missing_staff_is_refused_when_editing(): void
    {
        $buyer = $this->buyer();
        $survey = BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'housing', 'survey_date' => '2026-10-01']);
        $url = route('housing.customers.surveys.edit', [$buyer, $survey]);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('housing.customers.surveys.update', [$buyer, $survey]) . '">');
        $form['fields']['staff_user_id'] = '999999';

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.exists', ['attribute' => '担当者']));
        $this->assertNull($survey->fresh()->staff_user_id);
    }

    // ============================================================
    // 契約フォームの「新規顧客を登録」の小窓（Ajax）
    // ============================================================

    /** 小窓に入力して登録を押す（JS が組んだ要求を送り、応答を JS に戻す） */
    private function quickStore(array $values): array
    {
        $property = $this->property();
        $html = $this->htmlOf(route('housing.contracts.create', $property));
        $steps = 'data.openModal();';
        foreach ($values as $key => $value) {
            $steps .= ' data.f.' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE) . ';';
        }
        $steps .= ' data.submitModal();';
        $factory = $this->xData($html, 'buyerSelect');

        $run = $this->driveAlpine($html, 'buyerSelect', $factory, $steps);
        $this->assertSame(['POST', route('housing.customers.quick-store')], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $response = $this->sendCaptured($run['requests'][0]);

        $after = $this->driveAlpine($html, 'buyerSelect', $factory, $steps, [$this->asFetchResponse($response)], true,
            ['customerName', 'modalOpen', 'error', '$refs.sel.value']);

        return [$response, $after['evaluated']];
    }

    public function test_a_buyer_is_registered_from_the_contract_screen(): void
    {
        [$response, $state] = $this->quickStore(['last_name' => '高橋', 'first_name' => '花子', 'prefecture' => '愛媛県', 'city' => '松山市']);

        $response->assertOk();
        $buyer = Buyer::where('last_name', '高橋')->firstOrFail();
        $this->assertTrue($buyer->belongsToDepartment('housing'));
        $this->assertSame(['高橋 花子', false, '', (string) $buyer->id], $state, '登録した買主が選ばれて小窓が閉じていない');
    }

    public function test_the_quick_registration_shows_the_reason_it_was_refused(): void
    {
        [$response, $state] = $this->quickStore(['last_name' => '高橋', 'first_name' => '花子', 'prefecture' => '愛媛県松山市平井町一丁目']);

        $response->assertStatus(422);
        $this->assertSame(0, Buyer::count(), '列に入らない都道府県で登録された（本番の MySQL では 500）');
        $this->assertTrue($state[1], '入力エラーなのに小窓が閉じた');
        $this->assertTrue(str_contains($state[2], trans('validation.max.string', ['attribute' => '都道府県', 'max' => 10])), '断られた理由が小窓に出ない: ' . $state[2]);
    }

    // ============================================================
    // 登録画面の重複の確認と他部署の取り込み・一覧のランク（Ajax）
    // ============================================================

    public function test_a_customer_of_another_department_is_found_and_added_from_the_registration_screen(): void
    {
        $other = Buyer::create(['last_name' => '佐藤', 'first_name' => '一郎', 'prefecture' => '愛媛県', 'city' => '松山市']);
        $other->addToDepartment('realestate', '2026-08-01');
        $html = $this->htmlOf(route('housing.customers.create'));
        $elements = ['input[name="last_name"]' => '佐藤', 'input[name="first_name"]' => '一郎', 'input[name="acquired_date"]' => '2026-10-01'];
        $steps = 'data.$refs.prefecture.value = "愛媛県"; data.$refs.city.value = "松山市"; data.checkDuplicate();';

        $run = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [], true, [], [], $elements);
        $this->assertSame(['POST', route('api.customers.check-duplicate')], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $found = $this->sendCaptured($run['requests'][0]);
        $found->assertOk();
        $after = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [$this->asFetchResponse($found)], true, ['duplicateInfo'], [], $elements);
        $this->assertSame([['id' => $other->id, 'full_name' => '佐藤 一郎', 'same_dept' => false, 'other_dept' => ['realestate']]], $after['evaluated'][0]);

        $add = $steps . ' data.addToDepartment(' . $other->id . ');';
        $run = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $add, [$this->asFetchResponse($found)], true, [], [], $elements);
        $this->assertSame(route('api.customers.add-department', $other), $run['requests'][1]['url']);
        $added = $this->sendCaptured($run['requests'][1]);
        $added->assertOk();
        $this->assertTrue($other->fresh()->belongsToDepartment('housing'));
        $moved = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $add, [$this->asFetchResponse($found), $this->asFetchResponse($added)], true, [], [], $elements);
        $this->assertSame([route('housing.customers.show', $other)], $moved['navigations'], '取り込んだ顧客の詳細へ移らない');
    }

    public function test_the_rank_is_changed_from_the_list(): void
    {
        $buyer = $this->buyer();
        $html = $this->htmlOf(route('housing.customers.index'));
        $steps = 'openRankDropdown({ style: {}, getBoundingClientRect: function () { return { bottom: 0, left: 0 }; } }, ' . $buyer->id . ', "housing"); selectRank("A");';

        $run = $this->driveAlpine($html, 'selectRank', '({})', $steps);
        $this->assertSame(['PATCH', route('api.customers.rank.update', $buyer)], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame('A', $buyer->getDepartmentPivot('housing')->rank->value);
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps, [$this->asFetchResponse($response)], true, ['_currentBadge.textContent']);
        $this->assertSame([\App\Enums\BuyerRank::A->label()], $after['evaluated']);
    }
}
