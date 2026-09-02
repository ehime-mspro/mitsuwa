<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use App\Models\HsProperty;
use App\Models\ScheduleStep;
use App\Support\ScheduleImportSheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 建売物件の工程表の取込（設計書 §5）。
 *
 * ⚠ **入口を物件詳細に限る。** ファイルの現場名から物件を自動で特定してはいけない
 *   ——実測で現場名「JG保免中3号地」に当たる建売物件は本番に 0 件だった（設計書 §2.5）。
 *   画面で物件を特定した状態で上げさせれば、取り違えが原理的に起きない。
 *
 * ⚠ **親は型宣言してよい。** ScheduleStepController は 4 親を 1 本で受けるため
 *   暗黙のモデルバインドが使えないが、こちらは建売専用なので普通にバインドできる。
 */
class ScheduleImportController extends Controller
{
    /** `schedule_steps.source`。手入力（NULL）と区別して入れ替える（設計書 §3.1 D） */
    public const SOURCE = 'import';

    /**
     * 受け付ける最大サイズ（KB）。
     *
     * ⚠ **本番の `upload_max_filesize` が 5M**（実測）なので、それを超える案内をしない。
     *   大きく書くと PHP 側で先に切られて「押しても何も起きない」になる。
     *   画面の案内もこの定数から出す。
     */
    public const MAX_UPLOAD_KB = 5120;

    /** GET /housing/properties/{property}/schedule-import */
    public function form(HsProperty $property)
    {
        return view('housing.properties.schedule-import', $this->base($property));
    }

    /** POST /housing/properties/{property}/schedule-import/preview */
    public function preview(Request $request, HsProperty $property)
    {
        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
        //   JapaneseValidationMessagesTest の走査正規表現にマッチせず、和名チェックから外れる。
        $request->validate([
            'file' => 'required|file|mimes:xlsx|max:5120',
        ], [], ['file' => '工程表の書き出しファイル']);

        $result = ScheduleImportSheet::read($request->file('file')->getRealPath());

        // ⚠ **黙って 0 件で成功に見せない**（設計書 §6 が名指しで警戒している失敗）。
        //   ガント形式は見出しが揃わないのでここへ落ちる。
        if ($result['format'] !== ScheduleImportSheet::FORMAT_LIST) {
            return back()->withErrors([
                'file' => 'このファイルは取り込めません。「一覧」形式で書き出したファイルを選んでください'
                    . '（工程表（ガント）形式には施工完了日が入っていないため取り込めません）。',
            ]);
        }

        return view('housing.properties.schedule-import', $this->base($property) + [
            'result'    => $result,
            // ⚠ **キー名は rowErrors。`errors` にすると Blade の $errors（ViewErrorBag）を
            //   壊して、そのビューが $errors->any() を呼んだ瞬間に 500 する（Bug #53）。
            'rowErrors' => $result['rowErrors'],
            'warnings'  => $result['warnings'],
            // 取り込むと親の日付がどうなるか（設計書 §7.2）。⚠ **変わらない項目は出さない**
            'dateChanges' => $this->dateChanges($property, $result['rows']),
        ]);
    }

    /** POST /housing/properties/{property}/schedule-import */
    public function execute(Request $request, HsProperty $property)
    {
        $validated = $request->validate([
            'rows_json' => 'required|string',
        ], [], ['rows_json' => '取り込む工程']);

        $decoded = json_decode($validated['rows_json'], true);

        if (! is_array($decoded) || $decoded === []) {
            return back()->withErrors(['rows_json' => '取り込む工程を読み取れませんでした。もう一度ファイルを選んでください。']);
        }

        // ⚠ **プレビューが返した値をそのまま信じない。** hidden は書き換えられるので、
        //   日付・桁・分類をサーバ側で引き直す（ScheduleImportSheet が読み取りと同じ規則を使う）。
        $sanitized = ScheduleImportSheet::sanitizeSubmittedRows($decoded);

        // ⚠ **1 行でも壊れていたら何も消さない。** 入れ替えは「消してから入れる」ので、
        //   一部だけ取り込むと工程表が中途半端な状態で残る。プレビューが弾いた後なので、
        //   ここでエラーが出るのは改竄か不具合であって、通常の運用では起きない。
        if ($sanitized['rowErrors'] !== [] || $sanitized['rows'] === []) {
            return back()->withErrors([
                'rows_json' => '取り込めない行があります: '
                    . implode(' / ', array_slice($sanitized['rowErrors'], 0, 3)),
            ]);
        }

        $userId = $request->user()->id;
        $replaced = $this->importedCount($property);

        // ⚠ **ここは 1 トランザクションで囲む。** AreaBuildingImportController はあえて
        //   囲んでいない（2000 行の途中失敗で全部巻き戻ると原因行を特定できないため）が、
        //   こちらは 65 行と小さく、かつ**消してから入れる**ので、途中で落ちると
        //   工程が消えたままになる。方針が違う理由を残しておく（Bug #48）。
        DB::transaction(function () use ($property, $sanitized, $userId) {
            // ⚠ scheduleSteps() は MorphMany なので schedulable_type と _id の両方で絞る。
            //   4 親は別テーブルで **id が衝突する**ため、type を落とすと他部署の工程が消える。
            $property->scheduleSteps()->where('source', self::SOURCE)->delete();

            // 手で足した工程の後ろへ続ける（削除後に測るので取込由来は数に入らない）
            $base = (int) $property->scheduleSteps()->max('sort_order');

            foreach ($sanitized['rows'] as $row) {
                $step = new ScheduleStep([
                    'name'          => $row['name'],
                    'category'      => $row['category'],
                    'planned_start' => $row['planned_start'],
                    'planned_end'   => $row['planned_end'],
                    'notes'         => $row['notes'],
                    // ⚠ **実績（actual_*）は触らない**（設計書 §3.1 A）。取り込む日付は
                    //   予定であって「実際にその日にやった」記録ではない。
                    'source'        => self::SOURCE,
                ]);
                $step->schedulable()->associate($property);
                $step->sort_order = $base + $row['sort_order'];
                $step->created_by = $userId;
                $step->updated_by = $userId;
                $step->save();
            }

            // ⚠ **工程の入れ替えと同じトランザクションで**（設計書 §7.3）。
            //   片方だけ通ると、工程とヘッダーの数字が食い違ったまま残る。
            $dates = array_filter(self::derivedDates($sanitized['rows']), fn ($v) => $v !== null);

            if ($dates !== []) {
                // ⚠ 日付 2 列しか fill しないので updated_by が据え置きのままになる
                //   （工程には上で打っているのに、ここだけ規約から漏れていた）。
                $dates['updated_by'] = $userId;
                $property->fill($dates)->save();
            }
        });

        $count = count($sanitized['rows']);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', $replaced > 0
                ? "工程表を取り込みました（既存の {$replaced} 件を入れ替えて {$count} 件を登録）。"
                : "工程を {$count} 件取り込みました。");
    }

    // ============================================================
    // 内部
    // ============================================================

    /**
     * 取り込む工程から親に入れる日付を出す（設計書 §7.1）。
     *
     * ⚠ **ファイルのヘッダーの「工事期間」は使わない。** 実測で実データの範囲と一致しない
     *   （固定資産では D1 が 07/28 開始なのに実データの最小は 07/23）。ガントの棒の両端と
     *   基本情報の数字が食い違うのを防ぐため、**画面に出るのと同じソース**から出す。
     *
     * ⚠ **2 つは独立に決める。** 片方の日付が 1 つも無ければ、その項目は null を返して
     *   現在値を保つ。「両方そろわなければ何もしない」にはしない（片方だけ入ることはありうる）。
     *
     * ⚠ **前提条件: `planned_start` / `planned_end` はゼロ埋め `YYYY-MM-DD` であること。**
     *   `min()`/`max()` は文字列比較なので、ゼロ埋めが崩れると誤答する
     *   （実測: `min(['2026-10-01', '2026-9-01'])` は `'2026-10-01'` を返す＝誤り。
     *   本来 9 月 1 日のほうが早い）。呼び出し元（`ScheduleImportSheet` の読み取り・確定の
     *   両方）は `CsvDate::normalize()` を通すのでゼロ埋め済みだが、`public static` で
     *   外から呼べる以上、この前提を満たさない入力を渡さないこと。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{construction_start_date: ?string, scheduled_completion_date: ?string}
     */
    public static function derivedDates(array $rows): array
    {
        $starts = array_filter(array_column($rows, 'planned_start'));
        $ends   = array_filter(array_column($rows, 'planned_end'));

        return [
            'construction_start_date'   => $starts === [] ? null : min($starts),
            'scheduled_completion_date' => $ends === [] ? null : max($ends),
        ];
    }

    /**
     * プレビューに出す「日付がこう変わる」の行（設計書 §7.2）。
     *
     * ⚠ **値が変わらない項目は返さない。**「2026/09/27 → 2026/09/27」というノイズを出さない。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label: string, from: string, to: string}>
     */
    private function dateChanges(HsProperty $property, array $rows): array
    {
        $labels = [
            'construction_start_date'   => '着工予定日',
            'scheduled_completion_date' => '完成予定日',
        ];

        $changes = [];

        foreach (self::derivedDates($rows) as $column => $to) {
            if ($to === null) {
                continue;
            }

            $current = $property->{$column}?->toDateString();

            if ($current === $to) {
                continue;
            }

            $changes[] = [
                'label' => $labels[$column],
                // ⚠ $current（比較用）は toDateString() のまま残す。表示用はここで
                //   $property->{$column} を直接 format() すれば足り、文字列を
                //   パースし直す往復が要らない。
                'from'  => $current === null ? '—' : $property->{$column}->format('Y/m/d'),
                'to'    => CarbonImmutable::parse($to)->format('Y/m/d'),
            ];
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function base(HsProperty $property): array
    {
        $total = $property->scheduleSteps()->count();
        $imported = $this->importedCount($property);

        return [
            'property'    => $property,
            'importedCount' => $imported,
            // ⚠ 「手入力」は総数から取込由来を引いて出す。`whereNull('source')` だと
            //   将来 source が増えたときに、その分が予告からも削除対象からも漏れる。
            'manualCount' => $total - $imported,
            'maxUploadMb' => (int) (self::MAX_UPLOAD_KB / 1024),
        ];
    }

    private function importedCount(HsProperty $property): int
    {
        return $property->scheduleSteps()->where('source', self::SOURCE)->count();
    }
}
