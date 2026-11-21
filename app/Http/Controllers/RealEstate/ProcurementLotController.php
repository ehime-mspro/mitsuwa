<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Services\RealEstate\ProcurementToProjectConverter;
use App\Support\AreaConverter;
use App\Support\DeletionBlockers;
use App\Support\TsuboPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 土地の仕入れ案件の区画（取引種別を問わない。1 案件に何区画でも）。詳細画面の区画の欄から登録・編集・削除する。設計書 §5.1・§5.2。
 *
 * ⚠ 「土地でない」「区画が見つからない（ほかの土地の区画・消された区画）」「紐づいているものがあって削除できない」は、
 *   入力エラーでなく帯の理由で詳細へ戻す（どれも古い画面か手で組んだ送信、または紐付けのある区画の削除で起きる）。
 * ⚠ この欄の操作の結果は専用のフラッシュ lot_notice（成功）・lot_error（断り）で #lot へ戻し、区画の欄の見出しの下に出す
 *   （上の帯だと、#lot まで下がった画面の外に隠れる）。土地でない案件には区画の欄が無いので、その断りだけは上の帯（error）。
 * ⚠ 区画の登録・更新・削除のあとは、仕入れ案件のステータスを区画から集約する（ReProcurement::syncStatusFromLots()。設計書 §5.4）。
 * ⚠ {lot} はルートの scopeBindings() でその仕入れ案件の区画に絞る。見つからないときの戻し方は missing()（404 にしない）。
 * ⚠ 入力エラーで戻すとき、どのフォームから送ったかを hidden の lot_form（区画の id か new）で画面へ伝える
 *   （画面はそのフォームだけを前の入力で描き、行の編集なら開いたまま描く）。保存はしないが、配列で送られたら断るよう規則に入れる（Bug #121）。
 */
class ProcurementLotController extends Controller
{
    public const LAND_ONLY = '区画を登録できるのは、物件種別が土地の仕入れ案件だけです。';

    public const NOT_FOUND = '区画は登録されていません（ほかの画面で削除された可能性があります）。';

    /** 同じ土地の中で号地番号が重なる登録・更新の断り（入力エラー） */
    public const DUPLICATE_NUMBER = 'この号地番号はこの土地にすでにあります。別の番号にしてください。';

    /** 紐づいているもののある区画の削除の断り（%s は DeletionBlockers::countsLabel()。例 契約 1 件・注文住宅 2 件） */
    public const LINKED = '%sが紐づいているため、この区画は削除できません。';

    /** POST /realestate/procurements/{procurement}/lots */
    public function store(Request $request, ReProcurement $procurement): RedirectResponse
    {
        if (! $procurement->property_type?->isLandOnly()) {
            return redirect()->route('realestate.procurements.show', $procurement)->with('error', self::LAND_ONLY);
        }

        $procurement->lots()->create($this->validated($request, $procurement, null));
        $procurement->syncStatusFromLots();

        return self::back($procurement)->with('lot_notice', '区画を登録しました。');
    }

    /** PUT /realestate/procurements/{procurement}/lots/{lot} */
    public function update(Request $request, ReProcurement $procurement, ReProcurementLot $lot): RedirectResponse
    {
        $lot->update($this->validated($request, $procurement, $lot));
        $procurement->syncStatusFromLots();

        return self::back($procurement)->with('lot_notice', '区画を更新しました。');
    }

    /** DELETE /realestate/procurements/{procurement}/lots/{lot}（経営層だけ） */
    public function destroy(ReProcurement $procurement, ReProcurementLot $lot): RedirectResponse
    {
        // 契約・建売・注文住宅（見積りも）が指している区画は消させない（設計書 §5.2。本番の外部キーは ON DELETE RESTRICT）
        $blockers = DeletionBlockers::forProcurementLotIds([$lot->id]);
        if ($blockers !== []) {
            return self::back($procurement)->with('lot_error', sprintf(self::LINKED, DeletionBlockers::countsLabel($blockers)));
        }

        $lot->delete();
        $procurement->syncStatusFromLots();

        return self::back($procurement)->with('lot_notice', '区画を削除しました。');
    }

    /**
     * 仕入れ案件か区画が見つからないとき（ルートの missing()）。404 にせず理由を出す（決めたこと 6）。
     * - 仕入れ案件が無い → 一覧へ（ProcurementToProjectConverter::GONE。分譲地に変えた・削除した）
     * - 区画が無い（ほかの土地の区画・消された区画・数でない id）→ 詳細の区画の欄へ（NOT_FOUND）。土地でない案件は区画の欄が無いので上の帯へ
     *
     * ⚠ 仕入れ案件が解決できていればルートの値はモデル、解決できなければ送られた生の文字（数でないこともある）。
     */
    public static function missing(Request $request): RedirectResponse
    {
        $value = $request->route('procurement');
        $procurement = match (true) {
            $value instanceof ReProcurement             => $value,
            is_string($value) && ctype_digit($value)    => ReProcurement::find((int) $value),
            default                                     => null,
        };

        if ($procurement === null) {
            return redirect()->route('realestate.procurements.index')->with('error', ProcurementToProjectConverter::GONE);
        }
        if (! $procurement->property_type?->isLandOnly()) {
            return redirect()->route('realestate.procurements.show', $procurement)->with('error', self::NOT_FOUND);
        }

        return self::back($procurement)->with('lot_error', self::NOT_FOUND);
    }

    /**
     * 入力チェック（分譲地の区画の登録 `ProjectController::storeLot` と同じ上限 ＋ 同じ土地の中で号地番号が重ならないこと）。
     * ⚠ 販売価格と面積から求めた坪単価が列（符号付き INT）に入るかも見る。坪単価は保存しないが、
     *   分譲地に変えるときに区画へ写すので、ここで断らないと変える操作が 500 になる（Bug #91）。
     */
    private function validated(Request $request, ReProcurement $procurement, ?ReProcurementLot $lot): array
    {
        $statuses = implode(',', array_column(LotStatus::cases(), 'value'));

        // 断られたら区画の欄（#lot）へ戻す（既定の戻り先だと詳細の先頭へ戻り、欄の中の理由が画面の外になる。Bug #64 の形）
        try {
            $validated = $this->check($request, $statuses, $procurement, $lot);
        } catch (ValidationException $e) {
            throw $e->redirectTo(self::back($procurement)->getTargetUrl());
        }

        // lot_form は画面へ伝えるだけで保存しない
        unset($validated['lot_form']);

        return $validated;
    }

    private function check(Request $request, string $statuses, ReProcurement $procurement, ?ReProcurementLot $lot): array
    {
        $validated = $request->validate([
            'lot_form'      => 'nullable|string|max:20',
            // 同じ土地の中で号地番号が重ならない（ほかの土地の号地・更新する区画自身は数えない）。
            // ⚠ 上限の走査（AmountLimitTest）が文字の規則を 1 つずつ読むので、integer と max: を同じ文字に書き、unique は文字にして足す
            'lot_number'    => 'bail|required|integer|min:1|max:' . self::MAX_INT_COLUMN . '|'
                . Rule::unique('re_procurement_lots', 'lot_number')->where('procurement_id', $procurement->id)->ignore($lot?->id),
            'area_sqm'      => 'required|numeric|min:0.01|max:99999999.99',
            'selling_price' => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'status'        => "required|in:{$statuses}",
            'notes'         => 'nullable|string|max:200',
        ], [
            'lot_number.unique' => self::DUPLICATE_NUMBER,
        ]);

        if (! empty($validated['selling_price'])) {
            $tsubo = AreaConverter::sqmToTsubo((float) $validated['area_sqm']);
            $this->assertPerTsuboFits(TsuboPrice::perTsuboYen((int) $validated['selling_price'], $tsubo));
        }

        return $validated;
    }

    private static function back(ReProcurement $procurement): RedirectResponse
    {
        return redirect(route('realestate.procurements.show', $procurement) . '#lot');
    }
}
