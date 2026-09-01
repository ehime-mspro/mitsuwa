<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use App\Models\HsProperty;
use App\Models\ScheduleStep;
use App\Support\AndpadScheduleSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 建売物件の ANDPAD 工程表取込（設計書 §5）。
 *
 * ⚠ **入口を物件詳細に限る。** ANDPAD の現場名から物件を自動で特定してはいけない
 *   ——実測で現場名「JG保免中3号地」に当たる建売物件は本番に 0 件だった（設計書 §2.5）。
 *   画面で物件を特定した状態で上げさせれば、取り違えが原理的に起きない。
 *
 * ⚠ **親は型宣言してよい。** ScheduleStepController は 4 親を 1 本で受けるため
 *   暗黙のモデルバインドが使えないが、こちらは建売専用なので普通にバインドできる。
 */
class ScheduleImportController extends Controller
{
    /** `schedule_steps.source`。手入力（NULL）と区別して入れ替える（設計書 §3.1 D） */
    public const SOURCE = 'andpad';

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
        ], [], ['file' => 'ANDPAD の書き出しファイル']);

        $result = AndpadScheduleSheet::read($request->file('file')->getRealPath());

        // ⚠ **黙って 0 件で成功に見せない**（設計書 §6 が名指しで警戒している失敗）。
        //   ガント形式は見出しが揃わないのでここへ落ちる。
        if ($result['format'] !== AndpadScheduleSheet::FORMAT_LIST) {
            return back()->withErrors([
                'file' => 'このファイルは取り込めません。ANDPAD の「一覧」形式で書き出したファイルを選んでください'
                    . '（工程表（ガント）形式には施工完了日が入っていないため取り込めません）。',
            ]);
        }

        return view('housing.properties.schedule-import', $this->base($property) + [
            'result'    => $result,
            // ⚠ **キー名は rowErrors。`errors` にすると Blade の $errors（ViewErrorBag）を
            //   壊して、そのビューが $errors->any() を呼んだ瞬間に 500 する（Bug #53）。
            'rowErrors' => $result['rowErrors'],
            'warnings'  => $result['warnings'],
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
        //   日付・桁・分類をサーバ側で引き直す（AndpadScheduleSheet が読み取りと同じ規則を使う）。
        $sanitized = AndpadScheduleSheet::sanitizeSubmittedRows($decoded);

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
        $replaced = $this->andpadCount($property);

        // ⚠ **ここは 1 トランザクションで囲む。** AreaBuildingImportController はあえて
        //   囲んでいない（2000 行の途中失敗で全部巻き戻ると原因行を特定できないため）が、
        //   こちらは 65 行と小さく、かつ**消してから入れる**ので、途中で落ちると
        //   工程が消えたままになる。方針が違う理由を残しておく（Bug #48）。
        DB::transaction(function () use ($property, $sanitized, $userId) {
            // ⚠ scheduleSteps() は MorphMany なので schedulable_type と _id の両方で絞る。
            //   4 親は別テーブルで **id が衝突する**ため、type を落とすと他部署の工程が消える。
            $property->scheduleSteps()->where('source', self::SOURCE)->delete();

            // 手で足した工程の後ろへ続ける（削除後に測るので ANDPAD 由来は数に入らない）
            $base = (int) $property->scheduleSteps()->max('sort_order');

            foreach ($sanitized['rows'] as $row) {
                $step = new ScheduleStep([
                    'name'          => $row['name'],
                    'category'      => $row['category'],
                    'planned_start' => $row['planned_start'],
                    'planned_end'   => $row['planned_end'],
                    'notes'         => $row['notes'],
                    // ⚠ **実績（actual_*）は触らない**（設計書 §3.1 A）。ANDPAD の日付は
                    //   予定であって「実際にその日にやった」記録ではない。
                    'source'        => self::SOURCE,
                ]);
                $step->schedulable()->associate($property);
                $step->sort_order = $base + $row['sort_order'];
                $step->created_by = $userId;
                $step->updated_by = $userId;
                $step->save();
            }
        });

        $count = count($sanitized['rows']);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', $replaced > 0
                ? "ANDPAD の工程を取り込みました（既存の {$replaced} 件を入れ替えて {$count} 件を登録）。"
                : "ANDPAD の工程を {$count} 件取り込みました。");
    }

    // ============================================================
    // 内部
    // ============================================================

    /** @return array<string, mixed> */
    private function base(HsProperty $property): array
    {
        $total = $property->scheduleSteps()->count();
        $andpad = $this->andpadCount($property);

        return [
            'property'    => $property,
            'andpadCount' => $andpad,
            // ⚠ 「手入力」は総数から ANDPAD 由来を引いて出す。`whereNull('source')` だと
            //   将来 source が増えたときに、その分が予告からも削除対象からも漏れる。
            'manualCount' => $total - $andpad,
            'maxUploadMb' => (int) (self::MAX_UPLOAD_KB / 1024),
        ];
    }

    private function andpadCount(HsProperty $property): int
    {
        return $property->scheduleSteps()->where('source', self::SOURCE)->count();
    }
}
