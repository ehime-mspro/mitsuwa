<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalSetting;
use App\Support\Approval\ReminderCalendar;
use App\Support\Approval\SettingLogger;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;

/**
 * 催促の設定（画面⑫・段階3 設計書 §5.9・要件 8.3）。催促を送らない日（会社の休み）の登録と、次に催促を送る日・前回の催促。
 *
 * ⚠ 使い始める前から使える（準備の画面。門番は approval.admin だけ）。土日と日本の祝日は ReminderCalendar が自動で見る。
 * ⚠ 毎年繰り返す期間は 1 年より短く（利用者の決定 2026-10-02）。月と日だけで比べるので、1 年まるごとの期間は始まりと終わりの
 *   月日が重なり、どの日が入るかが分からなくなる。説明は必須・50 文字まで（同）。
 * ⚠ 追加・修正・削除は設定の変更の記録（SettingLogger）に残す。
 */
class HolidayController extends Controller
{
    public function index()
    {
        $holidays = ApprovalHoliday::ordered()->get();
        $nextDay  = (new ReminderCalendar())->nextSendDay();
        $lastRun  = ApprovalReminderRun::latestRun();
        // ⚠ 行を作らずに読む（管理の画面は使い始める前から開く）
        $launched = ApprovalSetting::launchedForMenu();

        return view('approvals.admin.holidays', compact('holidays', 'nextDay', 'lastRun', 'launched'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateHoliday($request);

        $holiday = ApprovalHoliday::create($validated);
        SettingLogger::record('holiday.created', 'approval_holiday', $holiday->id, [], $validated);

        return $this->back('送らない日を登録しました。');
    }

    public function update(Request $request, ApprovalHoliday $approvalHoliday)
    {
        $validated = $this->validateHoliday($request);
        // ⚠ 日付は 'Y-m-d' の文字列で比べる（Carbon のままだと、変えていなくても「変わった」と記録される）
        $before    = $approvalHoliday->formValues();

        $approvalHoliday->update($validated);
        SettingLogger::recordChange('holiday.updated', 'approval_holiday', $approvalHoliday->id, $before, $validated);

        return $this->back('送らない日を更新しました。');
    }

    public function destroy(ApprovalHoliday $approvalHoliday)
    {
        $before = $approvalHoliday->formValues();
        $id     = $approvalHoliday->id;
        $approvalHoliday->delete();

        SettingLogger::record('holiday.deleted', 'approval_holiday', $id, $before, []);

        return $this->back('送らない日を削除しました。');
    }

    private function validateHoliday(Request $request): array
    {
        // チェックボックスは外すと送られない（送られなければ繰り返さない）
        $request->merge(['repeats_yearly' => $request->boolean('repeats_yearly')]);

        return $request->validate([
            // ⚠ date_format で存在しない日付（2026-02-30）を断る（strtotime は繰り上げて通す。Top trap #15）
            'start_date'     => ['required', 'date_format:Y-m-d'],
            'end_date'       => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                if (! $request->boolean('repeats_yearly') || ! is_string($value) || ! is_string($request->input('start_date'))) {
                    return;
                }

                // 日付の形が崩れていれば何もしない（断るのは date_format。Carbon 3 の createFromFormat は false を返さず例外を投げる）
                try {
                    $start = CarbonImmutable::createFromFormat('!Y-m-d', $request->input('start_date'));
                    $end   = CarbonImmutable::createFromFormat('!Y-m-d', $value);
                } catch (InvalidFormatException) {
                    return;
                }

                // 開始日の 1 年後（2/29 は翌年の 2/28）と同じ日か後なら断る
                if ($end->gte($start->addYearNoOverflow())) {
                    $fail('毎年繰り返す期間は 1 年より短くしてください。');
                }
            }],
            'repeats_yearly' => ['boolean'],
            'description'    => ['required', 'string', 'max:50'],
        ], [
            'start_date.required'     => '開始日を入力してください。',
            'start_date.date_format'  => '開始日は正しい日付で入力してください。',
            'end_date.required'       => '終了日を入力してください。',
            'end_date.date_format'    => '終了日は正しい日付で入力してください。',
            // 年をまたぐ毎年の期間（12/29〜1/3）を、終了日を同じ年のまま打ったときにも直し方が分かる文にする（利用者の決定 2026-10-02）
            'end_date.after_or_equal' => '終了日は開始日より前にできません（年をまたぐ期間は、終了日を次の年の日付にしてください。例: 2026/12/29〜2027/1/3）。',
            'description.required'    => '説明を入力してください（例: 年末年始）。',
            'description.max'         => '説明は50文字以内で入力してください。',
        ], [
            'description' => '説明',
        ]);
    }

    private function back(string $success)
    {
        return redirect()->route('approvals.admin.holidays.index')->with('success', $success);
    }
}
