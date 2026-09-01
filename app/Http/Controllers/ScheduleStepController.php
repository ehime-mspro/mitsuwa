<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleStepCategory;
use App\Models\ScheduleStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 工程の CRUD（設計書 §4.4 / §6）。4 親で 1 本のコントローラを共有する。
 *
 * ⚠ **`{type}` のようなルートパラメータを持たない。** AttachmentController の TYPE_MAP 方式は、
 *   ルートの where() 正規表現とマップの同期漏れで 404 になる事故がある（Bug #20）。
 *   代わりに「バインド済みのルートパラメータのうちどれが来たか」を見る。
 *
 * ⚠ **OWNER_PARAMS とルートのパラメータ名がずれると無音で落ちる。**
 *   ScheduleRouteWiringTest が全件分類で固定している（Bug #45）。
 */
class ScheduleStepController extends Controller
{
    /**
     * 親を指すルートパラメータ名 => その親のモデルクラス。
     * `routes/web.php` の `{procurement}` / `{project}` / `{property}` / `{customOrder}` に対応。
     *
     * ⚠ **クラスまで持つ理由**（2026-09-01 実測）: Laravel の**暗黙のモデルバインドは
     *   コントローラのメソッド引数の型宣言でしか働かない**。このコントローラは 4 親を
     *   1 本で受けるので親を型宣言できず、`$request->route('procurement')` は
     *   **生の文字列のまま**届く。よってここで自分で引く。
     *
     * ⚠ **`Route::model()` によるグローバルな明示バインドで代用してはいけない。**
     *   `{property}` は**テナント物件**（`App\Models\Property`）でも使われており（実測 8 本以上）、
     *   `{project}` も同様。グローバルに束縛すると**既存の別部署のルートが壊れる**。
     *
     * ⚠ これは `AttachmentController::TYPE_MAP` とは別物。あちらの事故（Bug #20）は
     *   ルートの `where()` 正規表現とマップの同期漏れだが、こちらに `where()` は無く、
     *   `ScheduleRouteWiringTest` が**全ルートを全件分類**して
     *   「このマップに無い親パラメータが 1 つも無い」ことと
     *   「マップのクラスがそのルート名の接頭辞と一致する」ことを固定している。
     */
    public const OWNER_PARAMS = [
        'procurement' => \App\Models\ReProcurement::class,
        'project'     => \App\Models\ReProject::class,
        'property'    => \App\Models\HsProperty::class,
        'customOrder' => \App\Models\HsCustomOrder::class,
    ];

    /**
     * 工程を追加する。
     * Route: POST /<部署>/<親>/{parent}/schedule-steps
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $data  = $request->validate($this->rules(), [], $this->attributes());

        $step = new ScheduleStep($data);
        $step->schedulable()->associate($owner);
        // 末尾に足す。max が null（0 件目）でも 1 になる
        $step->sort_order = ((int) $owner->scheduleSteps()->max('sort_order')) + 1;
        $step->created_by = $request->user()->id;
        $step->updated_by = $request->user()->id;
        $step->save();

        return response()->json(['success' => true, 'step' => $this->payload($step)]);
    }

    /**
     * 工程を更新する。
     * Route: PATCH /<部署>/<親>/{parent}/schedule-steps/{step}
     *
     * ⚠ **親も工程も引数で受け取らない。** 理由は owner() / step() の注意書きを見ること
     *   （型宣言できない親があると、Laravel の位置引数渡しで `$step` に親の id がずれ込む）。
     */
    public function update(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $step  = $this->step($request, $owner);

        $data = $request->validate($this->rules(), [], $this->attributes());

        $step->fill($data);
        $step->updated_by = $request->user()->id;
        $step->save();

        return response()->json(['success' => true, 'step' => $this->payload($step->fresh())]);
    }

    /**
     * 工程を削除する。
     * Route: DELETE /<部署>/<親>/{parent}/schedule-steps/{step}
     */
    public function destroy(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $step  = $this->step($request, $owner);

        $step->delete();

        return response()->json(['success' => true, 'id' => $step->id]);
    }

    /**
     * 並べ替え（↑↓ ボタン。設計書 §4.4 — ドラッグにはしない）。
     * Route: PATCH /<部署>/<親>/{parent}/schedule-steps/reorder
     *
     * ⚠ **その親の工程を過不足なく全部渡すこと**を要求する。部分的な並びを許すと、
     *   渡されなかった行の sort_order が取り残されて順序が壊れる。
     */
    public function reorder(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ], [], ['ids' => '並び順']);

        $ids   = array_map('intval', $data['ids']);
        $owned = $owner->scheduleSteps()->pluck('id')->map('intval')->all();

        // ⚠ 他人の工程 id を混ぜられないこと（§6.2 と同じ理由）＋ 取りこぼしが無いこと
        abort_unless(
            count($ids) === count($owned) && array_diff($ids, $owned) === [] && array_diff($owned, $ids) === [],
            404
        );

        foreach ($ids as $i => $id) {
            ScheduleStep::whereKey($id)->update([
                'sort_order' => $i + 1,
                'updated_by' => $request->user()->id,
            ]);
        }

        return response()->json(['success' => true, 'ids' => $ids]);
    }

    // ============================================================
    // 内部
    // ============================================================

    /**
     * バインド済みのルートパラメータから親を取り出す（設計書 §6.1）。
     *
     * ⚠ 見つからないときは**黙って 404 にしない**。無音の 404 はまさに避けたい失敗なので、
     *   配線ミスとして大きく落とす（ScheduleRouteWiringTest が本来ここへ到達させない）。
     */
    private function owner(Request $request): Model
    {
        foreach (self::OWNER_PARAMS as $param => $class) {
            $value = $request->route($param);

            // 将来どこかで明示バインドが入っても壊れないように、既に Model ならそのまま使う
            if ($value instanceof Model) {
                return $value;
            }

            if ($value !== null) {
                // 存在しない id は ModelNotFoundException → 404（暗黙バインドと同じ振る舞い）
                return $class::findOrFail($value);
            }
        }

        throw new \LogicException(
            '工程の親をルートパラメータから解決できません。ルートのパラメータ名を '
            . 'ScheduleStepController::OWNER_PARAMS と揃えてください。'
        );
    }

    /**
     * `{step}` から工程を解決し、所有権も確かめる（設計書 §6.2）。
     *
     * ⚠ **メソッド引数で `ScheduleStep $step` を受け取ってはいけない**（2026-09-01 実測）。
     *   Laravel はコントローラへ**ルートパラメータを位置順に**渡す。このコントローラは
     *   親を型宣言できず（4 クラスある）親が生の文字列のまま残るので、
     *   `update(Request $request, ScheduleStep $step)` と書くと第 2 引数に
     *   **親の id 文字列**が入り `TypeError` で 500 になる
     *   （実測の呼び出し: `update(Request, '1', ScheduleStep)`）。
     *   位置に依存せず、名前で取り出すこと。
     *
     * ⚠ **所有権の確認をこのメソッドに畳み込んである。** 呼び出し側で忘れられないようにするため
     *   （忘れると他案件の工程を書き換えられる ＝ 過去に実際に踏んだ IDOR）。
     */
    private function step(Request $request, Model $owner): ScheduleStep
    {
        $value = $request->route('step');
        $step  = $value instanceof ScheduleStep ? $value : ScheduleStep::findOrFail($value);

        $this->assertOwned($step, $owner);

        return $step;
    }

    /**
     * その工程が本当にこの親のものか（設計書 §6.2）。
     *
     * ⚠ **`schedulable_id` だけでは足りない。** 4 親は別テーブルなので **id が衝突する**
     *   （仕入れ案件 #12 と建売物件 #12 が両方存在しうる）。**型も必ず突き合わせる。**
     *
     * ⚠ **int にキャストしてから比較する。** 片方が文字列だと `===` が常に false になり、
     *   正しいリクエストまで 404 になる（そして「なぜか動かない」に見える）。
     */
    private function assertOwned(ScheduleStep $step, Model $owner): void
    {
        abort_unless(
            (int) $step->schedulable_id === (int) $owner->getKey()
                && $step->schedulable_type === $owner::class,
            404
        );
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name'          => 'required|string|max:100',
            'category'      => ['required', Rule::in(ScheduleStepCategory::values())],
            'planned_start' => 'nullable|date',
            'planned_end'   => 'nullable|date|after_or_equal:planned_start',
            // ⚠ 実績終了だけが入って実績開始が空、という状態を許さない（設計書 §4.5）。
            //   許すと描画が「実績開始が無い」側へ落ち、**実績終了を入れたのに予定の棒が出る**
            //   という無音の食い違いになる。逆（実績開始だけ）は「進行中」なので正当。
            'actual_start'  => 'nullable|date|required_with:actual_end',
            'actual_end'    => 'nullable|date|after_or_equal:actual_start',
            'notes'         => 'nullable|string|max:255',
        ];
    }

    /**
     * ⚠ **`validate()` の第 3 引数**（第 2 引数は messages）。
     *   `name` は画面ごとに意味が変わる語なので、グローバルの `attributes` を書き換えず
     *   ここで上書きする（Bug #37）。
     *
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['name' => '工程名'];
    }

    /** @return array<string, mixed> */
    private function payload(ScheduleStep $step): array
    {
        return [
            'id'            => $step->id,
            'name'          => $step->name,
            'category'      => $step->category->value,
            'planned_start' => $step->planned_start?->toDateString(),
            'planned_end'   => $step->planned_end?->toDateString(),
            'actual_start'  => $step->actual_start?->toDateString(),
            'actual_end'    => $step->actual_end?->toDateString(),
            'sort_order'    => $step->sort_order,
            'notes'         => $step->notes ?? '',
        ];
    }
}
