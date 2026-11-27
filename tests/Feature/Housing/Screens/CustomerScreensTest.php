<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksBuyerScreenButtons;
use Tests\Concerns\ChecksRankMenu;
use Tests\Concerns\RunsBuyerFormScript;
use Tests\Concerns\SignsOutElsewhere;

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
    use ChecksBuyerScreenButtons;
    use ChecksRankMenu;
    use RunsBuyerFormScript;
    use SignsOutElsewhere;

    /** 断られた XMLHttpRequest の文を作る関数を持つ <script>（部品 buyers/_xhr_failure_message。2 画面が読む） */
    private const XHR_FAILURE_SCRIPT = 'function xhrFailureMessage';

    /** 部品 _ajax_save_failure の script の目印（断られた応答を画面に戻すときに一緒に読む） */
    private const SAVE_FAILURE_SCRIPT = 'function ajaxSaveFailureMessage(';

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

    /** 小窓を開いて必須の欄を入れ、登録（$op = submitModal）か重複の確認（$op = checkDup）を押す */
    private function modalSteps(string $op): string
    {
        return 'data.openModal(); data.f.last_name = "高橋"; data.f.first_name = "花子"; data.f.acquired_date = "2026-10-01"; data.' . $op . '();';
    }

    /**
     * 小窓の登録（$op = submitModal）か重複の確認（$op = checkDup）を、要求を組んだあとで $before を走らせてから送り、応答を小窓に戻す。
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, mixed>} [応答, [error, modalOpen]]
     */
    private function modalAfterFailure(string $op, callable $before): array
    {
        $property = $this->property();
        $html = $this->htmlOf(route('housing.contracts.create', $property));
        $steps = $this->modalSteps($op);
        $factory = $this->xData($html, 'buyerSelect');

        $request = $this->driveAlpine($html, 'buyerSelect', $factory, $steps)['requests'][0];
        $response = $this->sendCaptured($before($request));
        $after = $this->driveAlpine($html, 'buyerSelect', $factory, $steps, [$this->asFetchResponse($response)], true, ['error', 'modalOpen'], [self::SAVE_FAILURE_SCRIPT]);

        return [$response, $after['evaluated']];
    }

    /** 画面を開いたまま時間がたったか、ほかの画面でログアウトしたあとに小窓で登録・重複の確認をすると、理由が日本語で出る（Bug #137） */
    public function test_the_buyer_modal_says_why_after_the_session_expired(): void
    {
        foreach (['submitModal', 'checkDup'] as $op) {
            [$response, $state] = $this->modalAfterFailure($op, function (array $request) {
                $this->enforceCsrfToken();

                return $this->withExpiredToken($request);
            });

            $response->assertStatus(419);
            $this->assertSame([self::EXPIRED_JSON_MESSAGE, true], $state, "{$op}: 理由が小窓に出ないか、小窓が閉じた");
        }
        $this->assertSame(0, Buyer::count());
    }

    /** ほかの画面でパスワードを変えたあとに小窓で登録・重複の確認をすると、ログインが切れて 401 になる。理由が日本語で出る（直す前は「…（401）」） */
    public function test_the_buyer_modal_says_why_after_the_password_changed_elsewhere(): void
    {
        foreach (['submitModal', 'checkDup'] as $op) {
            $this->actingAs($this->user);   // 1 回目でログインが切れたので、入り直してから画面を開く
            [$response, $state] = $this->modalAfterFailure($op, fn (array $request) => tap($request, fn () => $this->changePasswordElsewhere()));

            $response->assertUnauthorized();
            $this->assertSame([self::SIGNED_OUT_JSON_MESSAGE, true], $state, "{$op}: 理由が小窓に出ないか、小窓が閉じた");
        }
        $this->assertSame(0, Buyer::count());
    }

    /**
     * 画面を開いたあとで役割が一般担当に変わってから小窓で登録・重複の確認をすると、403 の理由が出る
     * （直す前は「登録に失敗しました（403）」「重複チェックに失敗しました（403）」）
     */
    public function test_the_buyer_modal_says_why_when_the_permission_was_lost(): void
    {
        $expected = [
            'submitModal' => '登録できませんでした。このページへのアクセス権限がありません。',
            'checkDup' => '重複を確認できませんでした。このページへのアクセス権限がありません。',
        ];
        foreach ($expected as $op => $message) {
            [$response, $state] = $this->modalAfterFailure($op, function (array $request) {
                $this->user->role = UserRole::Staff;
                $this->user->save();

                return $request;
            });

            $response->assertForbidden();
            $this->assertSame([$message, true], $state, "{$op}: 403 の理由が小窓に出ないか、小窓が閉じた");
            $this->user->role = UserRole::Executive;
            $this->user->save();
        }
    }

    /** 419・401 でも本文が JSON でない（HTML で返った）ときは、番号つきの今の文（読めない本文を理由として出さない） */
    public function test_the_buyer_modal_keeps_the_numbered_message_when_the_body_is_not_json(): void
    {
        $html = $this->htmlOf(route('housing.contracts.create', $this->property()));
        $factory = $this->xData($html, 'buyerSelect');

        foreach (['submitModal' => '登録に失敗しました', 'checkDup' => '重複チェックに失敗しました'] as $op => $message) {
            foreach ([419, 401, 403] as $status) {
                $after = $this->driveAlpine($html, 'buyerSelect', $factory, $this->modalSteps($op), [$this->htmlResponse($status)], true, ['error', 'modalOpen'], [self::SAVE_FAILURE_SCRIPT]);

                $this->assertSame(["{$message}（{$status}）", true], $after['evaluated'], "{$op} {$status}: 番号つきの今の文でないか、小窓が閉じた");
            }
        }
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

    /**
     * 一覧のランクの変更が断られたら、理由を出す（直す前は何も出さず、ランクが変わらないだけだった。Bug #137）。
     * 419（ログインが切れた・ほかの画面でログアウトした）はサーバーの日本語の理由、ほかは番号つきの文。
     */
    public function test_a_refused_rank_change_says_why(): void
    {
        $steps = fn (Buyer $buyer) => 'openRankDropdown({ style: {}, getBoundingClientRect: function () { return { bottom: 0, left: 0 }; } }, ' . $buyer->id . ', "housing"); selectRank("A");';

        $buyer = $this->buyer();
        $html = $this->htmlOf(route('housing.customers.index'));
        $request = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer))['requests'][0];
        $this->enforceCsrfToken();
        $response = $this->sendCaptured($this->withExpiredToken($request))->assertStatus(419);
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [$this->asFetchResponse($response)], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame([self::EXPIRED_JSON_MESSAGE], $after['alerts']);
        $this->assertNotSame('A', $buyer->getDepartmentPivot('housing')->rank->value);

        // 開いたあとで顧客が消えた（404）
        $request = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer))['requests'][0];
        $buyer->delete();
        $this->app->forgetInstance(ValidateCsrfToken::class); // 鍵の確かめを、テストの既定（素通り）に戻す
        $response = $this->sendCaptured($request)->assertNotFound();
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [$this->asFetchResponse($response)], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame(['ランクを変更できませんでした（404）'], $after['alerts']);

        // 419 でも本文が JSON でない（HTML の画面が返った）なら、番号つきの文
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [['status' => 419, 'body' => '<!DOCTYPE html><title>x</title>']], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame(['ランクを変更できませんでした（419）'], $after['alerts']);
    }

    /**
     * ほかの画面でパスワードを変えた・無効にされたあとにランクを変えると、401 の理由（サーバーの日本語の文）を出す
     * （直す前は「ランクを変更できませんでした（401）」）。401 でも本文が JSON でなければ番号つきの文。
     */
    public function test_a_rank_change_after_the_password_changed_elsewhere_says_why(): void
    {
        $steps = fn (Buyer $buyer) => 'openRankDropdown({ style: {}, getBoundingClientRect: function () { return { bottom: 0, left: 0 }; } }, ' . $buyer->id . ', "housing"); selectRank("A");';
        $buyer = $this->buyer();

        // ① ほかの画面でパスワードを変えた
        $html = $this->htmlOf(route('housing.customers.index'));
        $request = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer))['requests'][0];
        $this->changePasswordElsewhere();
        $response = $this->sendCaptured($request)->assertUnauthorized();
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [$this->asFetchResponse($response)], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame([self::SIGNED_OUT_JSON_MESSAGE], $after['alerts']);

        // ② 入り直して画面を開いたあとで、無効にされた
        $html = $this->htmlOf(route('housing.customers.index'));
        $request = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer))['requests'][0];
        $this->deactivateSignedInUser();
        $response = $this->sendCaptured($request)->assertUnauthorized();
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [$this->asFetchResponse($response)], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame([self::INACTIVE_JSON_MESSAGE], $after['alerts']);

        // ③ 401 でも本文が JSON でない（HTML の画面が返った）なら、番号つきの文
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [['status' => 401, 'body' => '<!DOCTYPE html><title>x</title>']], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame(['ランクを変更できませんでした（401）'], $after['alerts']);

        $this->assertNotSame('A', $buyer->getDepartmentPivot('housing')->rank->value);
    }

    /**
     * 一覧を開いたあとで役割が一般担当に変わってからランクを変えると、403 の理由（サーバーの日本語の文）を
     * 「ランクを変更できませんでした。」に続けて出す（直す前は「ランクを変更できませんでした（403）」）。
     * 403 でも本文が JSON でなければ番号つきの文。
     */
    public function test_a_rank_change_by_a_user_who_lost_the_permission_says_why(): void
    {
        $steps = fn (Buyer $buyer) => 'openRankDropdown({ style: {}, getBoundingClientRect: function () { return { bottom: 0, left: 0 }; } }, ' . $buyer->id . ', "housing"); selectRank("A");';
        $buyer = $this->buyer();

        $html = $this->htmlOf(route('housing.customers.index'));
        $request = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer))['requests'][0];
        $this->user->role = UserRole::Staff;
        $this->user->save();
        $response = $this->sendCaptured($request)->assertForbidden();
        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [$this->asFetchResponse($response)], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame(['ランクを変更できませんでした。このページへのアクセス権限がありません。'], $after['alerts']);

        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps($buyer), [$this->htmlResponse(403)], withScripts: [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT]);
        $this->assertSame(['ランクを変更できませんでした（403）'], $after['alerts']);

        $this->assertNotSame('A', $buyer->getDepartmentPivot('housing')->rank->value);
    }

    /**
     * 買主一覧のランクの小窓は、ランクを変えられる人（経営層・管理者）にだけ描く。一般担当にはランクを見せるだけで、
     * 押せる形にも小窓の要素と script にもしない（直す前は一般担当も押せて、選ぶと必ず 403 になっていた。Bug #96 と同じ形）。
     */
    #[DataProvider('rankMenuRoles')]
    public function test_only_those_who_can_change_the_rank_can_open_the_rank_menu(UserRole $role, bool $canChange): void
    {
        $this->user = $this->member($role);
        $buyer = $this->buyer();

        $this->assertRankMenu($this->htmlOf(route('housing.customers.index')), $buyer, 'housing', $canChange);
    }

    public static function rankMenuRoles(): array
    {
        return [
            '経営層' => [UserRole::Executive, true],
            '管理者' => [UserRole::Manager, true],
            '一般担当' => [UserRole::Staff, false],
        ];
    }

    /**
     * 断られた XMLHttpRequest の理由を読むのは部品（buyers/_xhr_failure_message）の 1 か所だけで、その部品は
     * 保存の Ajax の部品（_partials/_ajax_save_failure の ajaxSaveFailureMessage）に決まりを任せる（設計メモ 2026-11-26-save-ajax-403-design.md）。
     * 直す前は buyers/index と buyers/_form に 419 の読み取りが 2 回書いてあり（Bug #137 の後回し）、そのあとは部品の中に 419・401 だけの読み取りがあった。
     * ⚠ 見るのは字面だけ（`xhr.status === 419` を変数の名前を変えて書き足したものは見えない）。
     */
    public function test_the_reason_of_a_refused_xhr_is_read_in_one_place(): void
    {
        $reads = [];
        $definitions = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (str_contains($source, 'xhr.status === 419')) {
                $reads[] = $file->getRelativePathname();
            }
            if (str_contains($source, self::XHR_FAILURE_SCRIPT)) {
                $definitions[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $reads, 'XMLHttpRequest の 419 を自分で読んでいる所がある（部品 _ajax_save_failure に任せる）');
        $this->assertSame(['buyers/_xhr_failure_message.blade.php'], $definitions, 'xhrFailureMessage の定義が部品のほかにもある');

        $partial = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/buyers/_xhr_failure_message.blade.php')));
        $this->assertStringContainsString('ajaxSaveFailureMessage(xhr.status', $partial, 'XMLHttpRequest の部品が ajaxSaveFailureMessage に決まりを任せていない');
        $this->assertStringContainsString("@include('_partials._ajax_save_failure')", $partial, 'XMLHttpRequest の部品が保存の Ajax の部品を読み込んでいない');
    }

    /**
     * 登録画面の「この部署に取り込む」が断られたら、理由を出す（直す前は何も出さなかった。Bug #137）。
     * 419 はサーバーの日本語の理由、ほかは番号つきの文。
     */
    public function test_a_refused_add_to_department_says_why(): void
    {
        $other = Buyer::create(['last_name' => '佐藤', 'first_name' => '一郎', 'prefecture' => '愛媛県', 'city' => '松山市']);
        $other->addToDepartment('realestate', '2026-08-01');
        $html = $this->htmlOf(route('housing.customers.create'));
        $elements = ['input[name="last_name"]' => '佐藤', 'input[name="first_name"]' => '一郎', 'input[name="acquired_date"]' => '2026-10-01'];
        $steps = 'data.addToDepartment(' . $other->id . ');';

        $request = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [], true, [], [], $elements)['requests'][0];
        $this->assertSame(route('api.customers.add-department', $other), $request['url']);
        $this->enforceCsrfToken();
        $response = $this->sendCaptured($this->withExpiredToken($request))->assertStatus(419);
        $after = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [$this->asFetchResponse($response)], true, [], [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT], $elements);
        $this->assertSame([self::EXPIRED_JSON_MESSAGE], $after['alerts']);
        $this->assertSame([], $after['navigations']);
        $this->assertFalse($other->fresh()->belongsToDepartment('housing'));

        // 開いたあとで顧客が消えた（404）
        $other->delete();
        $this->app->forgetInstance(ValidateCsrfToken::class); // 鍵の確かめを、テストの既定（素通り）に戻す
        $response = $this->sendCaptured($request)->assertNotFound();
        $after = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [$this->asFetchResponse($response)], true, [], [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT], $elements);
        $this->assertSame(['この部署に取り込めませんでした（404）'], $after['alerts']);
    }

    /** ほかの画面でパスワードを変えたあとに「この部署に取り込む」を押すと、401 の理由が出て画面は移らない（直す前は「…（401）」） */
    public function test_an_add_to_department_after_the_password_changed_elsewhere_says_why(): void
    {
        $other = Buyer::create(['last_name' => '佐藤', 'first_name' => '一郎', 'prefecture' => '愛媛県', 'city' => '松山市']);
        $other->addToDepartment('realestate', '2026-08-01');
        $html = $this->htmlOf(route('housing.customers.create'));
        $elements = ['input[name="last_name"]' => '佐藤', 'input[name="first_name"]' => '一郎', 'input[name="acquired_date"]' => '2026-10-01'];
        $steps = 'data.addToDepartment(' . $other->id . ');';

        $request = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [], true, [], [], $elements)['requests'][0];
        $this->changePasswordElsewhere();
        $response = $this->sendCaptured($request)->assertUnauthorized();
        $after = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [$this->asFetchResponse($response)], true, [], [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT], $elements);

        $this->assertSame([self::SIGNED_OUT_JSON_MESSAGE], $after['alerts']);
        $this->assertSame([], $after['navigations']);
        $this->assertFalse($other->fresh()->belongsToDepartment('housing'));
    }

    /**
     * 登録画面を開いたあとで役割が一般担当に変わってから「この部署に取り込む」を押すと、403 の理由を
     * 「この部署に取り込めませんでした。」に続けて出し、画面は移らない（直す前は「…（403）」）。
     */
    public function test_an_add_to_department_by_a_user_who_lost_the_permission_says_why(): void
    {
        $other = Buyer::create(['last_name' => '佐藤', 'first_name' => '一郎', 'prefecture' => '愛媛県', 'city' => '松山市']);
        $other->addToDepartment('realestate', '2026-08-01');
        $html = $this->htmlOf(route('housing.customers.create'));
        $elements = ['input[name="last_name"]' => '佐藤', 'input[name="first_name"]' => '一郎', 'input[name="acquired_date"]' => '2026-10-01'];
        $steps = 'data.addToDepartment(' . $other->id . ');';

        $request = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [], true, [], [], $elements)['requests'][0];
        $this->user->role = UserRole::Staff;
        $this->user->save();
        $response = $this->sendCaptured($request)->assertForbidden();
        $after = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', $steps, [$this->asFetchResponse($response)], true, [], [self::XHR_FAILURE_SCRIPT, self::SAVE_FAILURE_SCRIPT], $elements);

        $this->assertSame(['この部署に取り込めませんでした。このページへのアクセス権限がありません。'], $after['alerts']);
        $this->assertSame([], $after['navigations']);
        $this->assertFalse($other->fresh()->belongsToDepartment('housing'));
    }

    /**
     * 一覧のランクの変更で通信そのものが失敗したら（接続が切れた など）、理由を出す（直す前は何も出なかった。Bug #137 の後回し）。
     * ⚠ サーバーに届いて変わった可能性もあるので「変わっていません」とは言わず、画面を開き直して確かめるよう案内する。
     */
    public function test_a_rank_change_that_cannot_reach_the_server_says_so(): void
    {
        $buyer = $this->buyer();
        $html = $this->htmlOf(route('housing.customers.index'));
        $steps = 'openRankDropdown({ style: {}, getBoundingClientRect: function () { return { bottom: 0, left: 0 }; } }, ' . $buyer->id . ', "housing"); selectRank("A");';

        $after = $this->driveAlpine($html, 'selectRank', '({})', $steps, [$this->networkFailure()], withScripts: [self::XHR_FAILURE_SCRIPT]);

        $this->assertCount(1, $after['requests']);
        $this->assertSame(['通信エラーが発生しました。画面を開き直して、ランクが変わったか確かめてください。'], $after['alerts']);
    }

    /**
     * 登録画面の「この部署に取り込む」で通信そのものが失敗したら、理由を出す（直す前は何も出なかった。Bug #137 の後回し）。
     * ⚠ 取り込まれた可能性もあるので、顧客の一覧で確かめるよう案内する。画面は移らない。
     */
    public function test_an_add_to_department_that_cannot_reach_the_server_says_so(): void
    {
        $other = Buyer::create(['last_name' => '佐藤', 'first_name' => '一郎', 'prefecture' => '愛媛県', 'city' => '松山市']);
        $other->addToDepartment('realestate', '2026-08-01');
        $html = $this->htmlOf(route('housing.customers.create'));
        $elements = ['input[name="last_name"]' => '佐藤', 'input[name="first_name"]' => '一郎', 'input[name="acquired_date"]' => '2026-10-01'];

        $after = $this->driveAlpine($html, 'buyerForm', 'buyerForm()', 'data.addToDepartment(' . $other->id . ');', [$this->networkFailure()], true, [], [self::XHR_FAILURE_SCRIPT], $elements);

        $this->assertCount(1, $after['requests']);
        $this->assertSame(['通信エラーが発生しました。顧客の一覧で、この部署に取り込まれたか確かめてください。'], $after['alerts']);
        $this->assertSame([], $after['navigations']);
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

    // ============================================================
    // 押せる人にだけボタンを出す（設計メモ 2026-11-27-buyer-screen-buttons-design.md・Bug #96 と同じ形）
    // ============================================================

    /**
     * 一覧の「新規登録」と詳細の「編集」「削除」「アンケートを追加」、アンケートの行の「詳細」「編集」「削除」は、
     * 送り先のルートが通す人にだけ出す（直す前は全員に出ていて、押せない人が押すと 403 になっていた）。
     * 出たリンクは描いた href をたどって開ける。ボタンが 1 つも無いアンケートの行も、日付はそのまま出る。
     */
    #[DataProvider('buttonRoles')]
    public function test_each_role_sees_only_the_buttons_it_can_use(UserRole $role): void
    {
        $this->user = $this->member($role);
        $buyer = $this->buyer();
        $survey = BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'housing', 'survey_date' => '2026-10-01']);
        $buttons = $this->buyerScreenButtons('housing', $buyer, $survey);
        $show = $this->htmlOf(route('housing.customers.show', $buyer));

        $this->assertBuyerScreenButtons($this->htmlOf(route('housing.customers.index')), $show, $buttons, $role);
        $this->assertUsableLinksOpen($buttons, $role);
        $this->assertStringContainsString('2026/10/01', $show, 'アンケートの行の日付が出ていない');
    }

    public static function buttonRoles(): array
    {
        return [
            '経営層' => [UserRole::Executive],
            '管理者' => [UserRole::Manager],
            '一般担当' => [UserRole::Staff],
        ];
    }
}
