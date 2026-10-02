<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 催促の設定（画面⑫・段階3 設計書 §5.9・§6 の「⑫」）。
 *
 * ⚠ 時計は 2026-10-02（金）10:00（日本時間）に止める。今日の 9:05 を過ぎているので、次に送る日は 10/5（月）。
 */
class HolidaySettingsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    /** 終了日が開始日より前のときの文（年をまたぐ期間の直し方も添える。利用者の決定 2026-10-02） */
    private const END_BEFORE_START = '終了日は開始日より前にできません（年をまたぐ期間は、終了日を次の年の日付にしてください。例: 2026/12/29〜2027/1/3）。';

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠ UTC にしてから渡す（日本時間の Carbon を渡すと、保存した日時をその時刻帯で読む。ApprovalRemindCommandTest と同じ）
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'Asia/Tokyo')->utc());
    }

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.holidays.index'))->assertOk()->getContent();
    }

    /** 表の行ごとのセルの文字（タグを除いて空白を詰めたもの） @return list<list<string>> */
    private function tableRows(string $html): array
    {
        preg_match_all('/<tr class="hover:bg-gray-50">(.*?)<\/tr>/s', $html, $rows);

        return array_map(function (string $row): array {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);

            return array_map(fn (string $c): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'))), $cells[1]);
        }, $rows[1]);
    }

    /** 画面の文字（タグを除いて空白を詰めたもの） */
    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    /** 項目の並びを問わずに比べるため、キーの順に並べる（設定の変更の記録は JSON の列で、MySQL は項目の並びを入れ替える） */
    private function byKey(?array $values): ?array
    {
        if ($values !== null) {
            ksort($values);
        }

        return $values;
    }

    private function holiday(string $from, string $to, bool $yearly, string $description): ApprovalHoliday
    {
        return ApprovalHoliday::create(['start_date' => $from, 'end_date' => $to, 'repeats_yearly' => $yearly, 'description' => $description]);
    }

    /** @param array<string, string> $fields */
    private function store(User $admin, array $fields)
    {
        return $this->actingAs($admin)->from(route('approvals.admin.holidays.index'))->post(route('approvals.admin.holidays.store'), $fields);
    }

    public function test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off(): void
    {
        $admin = $this->approvalAdmin();
        $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $this->holiday('2026-08-13', '2026-08-14', false, '夏季休暇');
        $this->holiday('2026-11-02', '2026-11-02', false, '創立記念日');

        $html = $this->indexHtml($admin);
        $text = $this->text($html);
        $this->assertStringContainsString('次に催促を送る日 10/5（月） 9 時', $text);
        $this->assertStringContainsString('決裁を使い始める前なので、まだ送りません。', $text);
        $this->assertStringContainsString('前回の催促 まだありません', $text);
        // 開始日の順
        $this->assertSame([
            ['2026/08/13〜2026/08/14', 'その年だけ', '夏季休暇', '編集 | 削除'],
            ['2026/11/02', 'その年だけ', '創立記念日', '編集 | 削除'],
            ['12/29〜1/3', '毎年', '年末年始', '編集 | 削除'],
        ], $this->tableRows($html));
        $this->assertStringContainsString('function approvalHolidays()', $html);
        // 説明文の 2 文のあいだはソースで改行しない（改行は画面で半角の空白 1 つになる）。タグを除かずに HTML のまま見る
        $this->assertStringContainsString('1 通送ります。土曜・日曜と祝日', $html);

        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 3, 'item_count' => 5]);
        $this->launchApprovals();
        $text = $this->text($this->indexHtml($admin));
        $this->assertStringContainsString('前回の催促 10/1（木）（3 人・5 件）', $text);
        $this->assertStringNotContainsString('使い始める前', $text);
    }

    /** 送る相手がいなかった朝は人数の代わりにそう出す（利用者の決定 2026-10-02。申請が待っていても、メールを受け取れる人がいない朝がある） */
    public function test_the_last_run_says_so_when_there_was_nobody_to_remind(): void
    {
        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 0, 'item_count' => 0]);

        $text = $this->text($this->indexHtml($this->approvalAdmin()));
        $this->assertStringContainsString('前回の催促 10/1（木）送る相手はいませんでした', $text);
        $this->assertStringNotContainsString('0 人・0 件', $text);
    }

    public function test_the_screen_says_so_when_no_send_day_comes_within_a_year(): void
    {
        $this->holiday('2026-10-01', '2028-12-31', false, '長い休み');

        $this->assertStringContainsString('次に催促を送る日 1 年以内にありません（送らない日の登録を確かめてください）', $this->text($this->indexHtml($this->approvalAdmin())));
    }

    /** 描いた追加のフォームをそのまま送り返す。登録すると次に送る日が動き、設定の変更の記録が残る */
    public function test_a_day_off_can_be_added_from_the_rendered_form(): void
    {
        $admin = $this->approvalAdmin();
        $form  = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.holidays.store') . '"');
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertArrayNotHasKey('repeats_yearly', $form['fields'], '毎年繰り返すは、はじめは外れている');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'description' => '秋休み',
        ]))->assertRedirect(route('approvals.admin.holidays.index'))->assertSessionHas('success', '送らない日を登録しました。');

        $holiday = ApprovalHoliday::sole();
        $this->assertSame(['start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'repeats_yearly' => false, 'description' => '秋休み'], $holiday->formValues());
        $this->assertSame(
            $this->byKey(['start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'repeats_yearly' => false, 'description' => '秋休み']),
            $this->byKey(ApprovalSettingLog::where('action', 'holiday.created')->sole()->new_values),
        );
        // 10/5〜10/9 を止めたので、10/12（スポーツの日）も飛ばして 10/13
        $this->assertStringContainsString('次に催促を送る日 10/13（火） 9 時', $this->text($this->indexHtml($admin)));
    }

    public function test_the_dates_and_the_description_are_checked(): void
    {
        $admin = $this->approvalAdmin();

        $this->store($admin, ['start_date' => '2026-10-09', 'end_date' => '2026-10-08', 'description' => '休み'])
            ->assertSessionHasErrors(['end_date' => self::END_BEFORE_START]);
        // 年をまたぐ毎年の期間を、終了日を同じ年のまま打った（年末年始でいちばん起きやすい打ち間違い）
        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2026-01-03', 'repeats_yearly' => '1', 'description' => '年末年始'])
            ->assertSessionHasErrors(['end_date' => self::END_BEFORE_START]);
        // 存在しない日付は繰り上げずに断る（Top trap #15）
        $this->store($admin, ['start_date' => '2026-02-30', 'end_date' => '2026-03-02', 'description' => '休み'])
            ->assertSessionHasErrors(['start_date' => '開始日は正しい日付で入力してください。']);
        // 毎年繰り返すにチェックがあっても、日付の形が崩れていれば 500 にせず同じ文で断る
        $this->store($admin, ['start_date' => '2026/12/29', 'end_date' => '2027/01/03', 'repeats_yearly' => '1', 'description' => '年末年始'])
            ->assertSessionHasErrors([
                'start_date' => '開始日は正しい日付で入力してください。',
                'end_date' => '終了日は正しい日付で入力してください。',
            ]);
        $this->store($admin, ['start_date' => 'abc', 'end_date' => '2027-01-03', 'repeats_yearly' => '1', 'description' => '年末年始'])
            ->assertSessionHasErrors(['start_date' => '開始日は正しい日付で入力してください。']);
        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => 'abc', 'repeats_yearly' => '1', 'description' => '年末年始'])
            ->assertSessionHasErrors(['end_date' => '終了日は正しい日付で入力してください。']);
        $this->store($admin, ['start_date' => '', 'end_date' => '', 'description' => ''])
            ->assertSessionHasErrors([
                'start_date' => '開始日を入力してください。',
                'end_date' => '終了日を入力してください。',
                'description' => '説明を入力してください（例: 年末年始）。',
            ]);
        $this->store($admin, ['start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'description' => str_repeat('あ', 51)])
            ->assertSessionHasErrors(['description' => '説明は50文字以内で入力してください。']);

        $this->assertSame(0, ApprovalHoliday::count());
    }

    /** 毎年繰り返す期間は 1 年より短く（利用者の決定 2026-10-02）。その年だけの期間は長くてよい */
    public function test_a_yearly_period_must_be_shorter_than_a_year(): void
    {
        $admin  = $this->approvalAdmin();
        $refuse = ['end_date' => '毎年繰り返す期間は 1 年より短くしてください。'];

        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2027-12-29', 'repeats_yearly' => '1', 'description' => '休み'])->assertSessionHasErrors($refuse);
        // 2/29 の 1 年後は翌年の 2/28
        $this->store($admin, ['start_date' => '2028-02-29', 'end_date' => '2029-02-28', 'repeats_yearly' => '1', 'description' => '休み'])->assertSessionHasErrors($refuse);
        $this->assertSame(0, ApprovalHoliday::count());

        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2027-12-28', 'repeats_yearly' => '1', 'description' => 'ほぼ 1 年'])->assertSessionHasNoErrors();
        $this->store($admin, ['start_date' => '2028-02-29', 'end_date' => '2029-02-27', 'repeats_yearly' => '1', 'description' => 'うるう年から'])->assertSessionHasNoErrors();
        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2027-12-29', 'description' => 'その年だけの長い休み'])->assertSessionHasNoErrors();
        $this->assertSame(3, ApprovalHoliday::count());
    }

    /** 修正は変わった項目だけを記録し、何も変えなければ記録しない。削除は前の値を記録する */
    public function test_changes_and_deletions_are_recorded(): void
    {
        $admin   = $this->approvalAdmin();
        $holiday = $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $same    = ['start_date' => '2026-12-29', 'end_date' => '2027-01-03', 'repeats_yearly' => '1'];

        $this->actingAs($admin)->put(route('approvals.admin.holidays.update', $holiday), $same + ['description' => '年末年始の休業'])
            ->assertRedirect(route('approvals.admin.holidays.index'))->assertSessionHas('success', '送らない日を更新しました。');
        $log = ApprovalSettingLog::where('action', 'holiday.updated')->sole();
        $this->assertSame([['description' => '年末年始'], ['description' => '年末年始の休業']], [$log->old_values, $log->new_values]);

        $this->actingAs($admin)->put(route('approvals.admin.holidays.update', $holiday), $same + ['description' => '年末年始の休業']);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'holiday.updated')->count());

        $this->actingAs($admin)->delete(route('approvals.admin.holidays.destroy', $holiday))
            ->assertRedirect(route('approvals.admin.holidays.index'))->assertSessionHas('success', '送らない日を削除しました。');
        $this->assertSame(0, ApprovalHoliday::count());
        $this->assertSame(
            $this->byKey(['start_date' => '2026-12-29', 'end_date' => '2027-01-03', 'repeats_yearly' => true, 'description' => '年末年始の休業']),
            $this->byKey(ApprovalSettingLog::where('action', 'holiday.deleted')->sole()->old_values),
        );
    }

    /** 断られた修正は、同じ送らない日の小窓を、打った中身で開き直す（申請種類の管理と同じ形） */
    public function test_a_refused_edit_reopens_the_same_dialog(): void
    {
        $admin   = $this->approvalAdmin();
        $holiday = $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');

        $html = $this->actingAs($admin)->from(route('approvals.admin.holidays.index'))
            ->followingRedirects()
            ->put(route('approvals.admin.holidays.update', $holiday), [
                'edit_id' => (string) $holiday->id, 'start_date' => '2027-01-03', 'end_date' => '2026-12-29', 'repeats_yearly' => '1', 'description' => '打った説明',
            ])->assertOk()->getContent();

        $this->assertStringContainsString('x-show="editId === ' . $holiday->id . '"', $html);
        $this->assertStringContainsString(e(self::END_BEFORE_START), $html);
        // 開き直す中身（Js::from は日本語を \u で符号化するので、HTML の文字列では探さない。TypeManagementTest と同じ読み方）
        $this->assertSame(1, preg_match("/var refused = JSON\\.parse\\('([^']*)'\\);/", $html, $m));
        $this->assertSame(
            ['id' => $holiday->id, 'start_date' => '2027-01-03', 'end_date' => '2026-12-29', 'repeats_yearly' => true, 'description' => '打った説明'],
            json_decode(json_decode('"' . $m[1] . '"'), true),
        );
        $this->assertSame('年末年始', $holiday->fresh()->description);
    }

    public function test_people_other_than_the_approval_admin_cannot_open_it(): void
    {
        $this->actingAs($this->baseUser())->get(route('approvals.admin.holidays.index'))->assertForbidden();
    }
}
