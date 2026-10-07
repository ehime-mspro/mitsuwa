<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Tests\Concerns\RunsBuyerFormScript;

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
    use RunsBuyerFormScript;

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

    /**
     * 生年月日・ご家族の行は 1 行の flex で、375px では 229px 横にはみ出した（2026-10-07 に実ブラウザで実測）。折り返す。
     * ⚠ 本当に収まるかは実ブラウザで測る（住宅事業と不動産で共用の画面）。
     */
    public function test_the_birth_and_family_row_wraps_on_narrow_screens(): void
    {
        $html = $this->htmlOf(route('realestate.customers.create'));

        $this->assertMatchesRegularExpression('/<div style="display: flex;[^"]*flex-wrap: wrap;[^"]*">\s*<div>\s*<label[^>]*>生年月日<\/label>/u', $html);
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
    // 本番の列に入らない値・存在しない生年月日を入力エラーで断る（R3・R4）
    // ============================================================

    /** 登録画面のフォーム（姓名・取得日は埋める） */
    private function storeForm(array $values = []): array
    {
        $form = $this->parseForm($this->htmlOf(route('realestate.customers.create')), 'action="' . route('realestate.customers.store') . '"');

        return $this->fill($form, array_merge(['acquired_date' => '2026-10-01', 'last_name' => '佐藤', 'first_name' => '一郎'], $values));
    }

    private function register(array $form): string
    {
        $form['fields']['birth_date'] = $this->jsBirthDate($form);

        return $this->landed($this->submit($form, route('realestate.customers.create')));
    }

    public function test_values_that_fit_the_columns_are_registered(): void
    {
        $html = $this->register($this->storeForm([
            'phone' => str_repeat('0', 20), 'city' => str_repeat('松', 50), 'employer' => str_repeat('商', 100),
            'family_adults' => '255', 'family_children' => '0', 'years_employed' => '65535',
        ]));

        $this->assertFlash($html, 'success', '顧客を登録しました。');
        $buyer = Buyer::firstOrFail();
        $this->assertSame([20, 50, 100, 255, 0, 65535], [mb_strlen($buyer->phone), mb_strlen($buyer->city), mb_strlen($buyer->employer), $buyer->family_adults, $buyer->family_children, $buyer->years_employed]);
    }

    public static function tooLarge(): array
    {
        return [
            '電話番号' => ['phone', str_repeat('0', 21), 'max.string', '電話番号', 20],
            // 都道府県は画面では選択欄だが、送信は列（10 文字）で断る（2026-10-06 の住宅事業の変異で、上限を緩めても緑だった）
            '都道府県' => ['prefecture', str_repeat('愛', 11), 'max.string', '都道府県', 10],
            '市区町村' => ['city', str_repeat('松', 51), 'max.string', '市区町村', 50],
            '勤務先' => ['employer', str_repeat('商', 101), 'max.string', '勤務先', 100],
            '大人の人数' => ['family_adults', '256', 'max.numeric', '大人の人数', 255],
            '子供の人数' => ['family_children', '256', 'max.numeric', '子供の人数', 255],
            '勤続年数' => ['years_employed', '65536', 'max.numeric', '勤続年数', 65535],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tooLarge')]
    public function test_a_value_that_does_not_fit_the_column_is_refused(string $field, string $value, string $rule, string $attribute, int $max): void
    {
        $html = $this->register($this->storeForm([$field => $value]));

        $this->assertErrorItem($html, trans('validation.' . $rule, ['attribute' => $attribute, 'max' => $max]));
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_value_that_does_not_fit_the_column_is_refused_when_editing(): void
    {
        $buyer = $this->buyer();
        $url = route('realestate.customers.edit', $buyer);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.customers.update', $buyer) . '"'), ['phone' => '089-123-4567 / 090-1234-5678']);
        $form['fields']['birth_date'] = $this->jsBirthDate($form);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.max.string', ['attribute' => '電話番号', 'max' => 20]));
        $this->assertNull($buyer->fresh()->phone);
    }

    public function test_a_birth_date_that_does_not_exist_is_refused(): void
    {
        $form = $this->storeForm(['birth_era' => 'S', 'birth_year' => '55', 'birth_month' => '2', 'birth_day' => '30']);
        $this->assertSame('1980-02-30', $this->jsBirthDate($form));

        $html = $this->register($form);

        $this->assertErrorItem($html, trans('validation.date', ['attribute' => '生年月日']));
        $this->assertSame(0, Buyer::count(), '2 月 30 日が 3 月 1 日として保存された');
    }

    public function test_a_birth_year_outside_the_era_is_refused(): void
    {
        // 元号を選んだまま西暦（1980）を打つと、画面の JS は昭和 1980 年＝3905 年にする
        $form = $this->storeForm(['birth_era' => 'S', 'birth_year' => '1980', 'birth_month' => '1', 'birth_day' => '2']);
        $this->assertSame('3905-01-02', $this->jsBirthDate($form));

        $html = $this->register($form);

        $this->assertErrorItem($html, '元号（昭和）と生年月日（3905-01-02）が合いません（昭和は1926〜1989年）');
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_birth_date_after_the_era_ended_is_refused(): void
    {
        $html = $this->register($this->storeForm(['birth_era' => 'S', 'birth_year' => '70', 'birth_month' => '4', 'birth_day' => '1']));

        $this->assertErrorItem($html, '元号（昭和）と生年月日（1995-04-01）が合いません（昭和は1926〜1989年）');
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_future_birth_date_is_refused(): void
    {
        // 日本の今日 2026-10-05（UTC 2026-10-04 15:00 ＝ 日本時間 10-05 0:00。Bug #61 の境目）
        Carbon::setTestNow('2026-10-04 15:00:00');
        $html = $this->register($this->storeForm(['birth_year' => '2026', 'birth_month' => '10', 'birth_day' => '6']));

        $this->assertErrorItem($html, trans('validation.before_or_equal', ['attribute' => '生年月日', 'date' => '2026-10-05']));
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_birth_date_of_today_in_japan_is_accepted(): void
    {
        Carbon::setTestNow('2026-10-04 15:00:00');
        $html = $this->register($this->storeForm(['birth_year' => '2026', 'birth_month' => '10', 'birth_day' => '5']));

        $this->assertFlash($html, 'success', '顧客を登録しました。');
        $this->assertSame('2026-10-05', Buyer::firstOrFail()->birth_date->toDateString());
    }

    public function test_the_last_year_of_showa_is_accepted(): void
    {
        $html = $this->register($this->storeForm(['birth_era' => 'S', 'birth_year' => '64', 'birth_month' => '1', 'birth_day' => '7']));

        $this->assertFlash($html, 'success', '顧客を登録しました。');
        $this->assertSame('1989-01-07', Buyer::firstOrFail()->birth_date->toDateString());
    }

    public function test_the_first_year_of_heisei_is_accepted(): void
    {
        $html = $this->register($this->storeForm(['birth_era' => 'H', 'birth_year' => '1', 'birth_month' => '1', 'birth_day' => '8']));

        $this->assertFlash($html, 'success', '顧客を登録しました。');
        $this->assertSame('1989-01-08', Buyer::firstOrFail()->birth_date->toDateString());
    }

    public function test_a_database_error_is_reported_without_showing_the_sql(): void
    {
        Exceptions::fake();
        Buyer::creating(function () {
            throw new QueryException('mysql', 'insert into `buyers` (`phone`) values (?)', ['089'], new \PDOException('Data too long for column'));
        });

        $html = $this->register($this->storeForm(['phone' => '089']));

        $this->assertFlash($html, 'error', '登録に失敗しました。時間をおいてやり直してください。');
        $this->assertFalse(str_contains($html, 'insert into'), '画面に SQL が出ている');
        Exceptions::assertReported(QueryException::class);
    }

    /** 生年月日を組む JS を読む画面（RunsBuyerFormScript） */
    protected function buyerFormUrl(): string
    {
        return route('realestate.customers.create');
    }
}
