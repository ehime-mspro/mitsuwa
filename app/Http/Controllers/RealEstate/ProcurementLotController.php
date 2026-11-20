<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Models\ReProcurement;
use App\Support\AreaConverter;
use App\Support\TsuboPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 土地（自社買取）の仕入れ案件の区画（1 案件に 1 区画まで）。詳細画面の欄から登録・編集・削除する。設計書 §5。
 *
 * ⚠ 「土地でない」「2 つ目」「区画が無いのに更新・削除」は入力エラーでなく帯の理由で詳細へ戻す
 *   （どれも古い画面か手で組んだ送信だけで起きる）。入力エラーは既定の戻り先（詳細画面）。
 */
class ProcurementLotController extends Controller
{
    public const ONLY_ONE = '区画は 1 つまでです。2 つ目を足すときは「分譲地に変える」を使ってください。';

    public const LAND_ONLY = '区画を登録できるのは、物件種別が土地の仕入れ案件だけです。';

    public const NOT_FOUND = '区画は登録されていません（ほかの画面で削除された可能性があります）。';

    /** POST /realestate/procurements/{procurement}/lot */
    public function store(Request $request, ReProcurement $procurement): RedirectResponse
    {
        if (! $procurement->property_type?->isLandOnly()) {
            return $this->back($procurement)->with('error', self::LAND_ONLY);
        }
        if ($procurement->lot()->exists()) {
            return $this->back($procurement)->with('error', self::ONLY_ONE);
        }

        $procurement->lot()->create($this->validated($request, $procurement));

        return $this->back($procurement)->with('success', '区画を登録しました。');
    }

    /** PUT /realestate/procurements/{procurement}/lot */
    public function update(Request $request, ReProcurement $procurement): RedirectResponse
    {
        $lot = $procurement->lot;
        if ($lot === null) {
            return $this->back($procurement)->with('error', self::NOT_FOUND);
        }

        $lot->update($this->validated($request, $procurement));

        return $this->back($procurement)->with('success', '区画を更新しました。');
    }

    /** DELETE /realestate/procurements/{procurement}/lot */
    public function destroy(ReProcurement $procurement): RedirectResponse
    {
        $lot = $procurement->lot;
        if ($lot === null) {
            return $this->back($procurement)->with('error', self::NOT_FOUND);
        }

        $lot->delete();

        return $this->back($procurement)->with('success', '区画を削除しました。');
    }

    /**
     * 入力チェック（分譲地の区画の登録 `ProjectController::storeLot` と同じ上限）。
     * ⚠ 販売価格と面積から求めた坪単価が列（符号付き INT）に入るかも見る。坪単価は保存しないが、
     *   分譲地に変えるときに区画へ写すので、ここで断らないと変える操作が 500 になる（Bug #91）。
     */
    private function validated(Request $request, ReProcurement $procurement): array
    {
        $statuses = implode(',', array_column(LotStatus::cases(), 'value'));

        // 断られたら区画の欄（#lot）へ戻す（既定の戻り先だと詳細の先頭へ戻り、欄の中の理由が画面の外になる。Bug #64 の形）
        try {
            return $this->check($request, $statuses);
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->back($procurement)->getTargetUrl());
        }
    }

    private function check(Request $request, string $statuses): array
    {
        $validated = $request->validate([
            'lot_number'    => 'required|integer|min:1|max:' . self::MAX_INT_COLUMN,
            'area_sqm'      => 'required|numeric|min:0.01|max:99999999.99',
            'selling_price' => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'status'        => "required|in:{$statuses}",
            'notes'         => 'nullable|string|max:200',
        ]);

        if (! empty($validated['selling_price'])) {
            $tsubo = AreaConverter::sqmToTsubo((float) $validated['area_sqm']);
            $this->assertPerTsuboFits(TsuboPrice::perTsuboYen((int) $validated['selling_price'], $tsubo));
        }

        return $validated;
    }

    private function back(ReProcurement $procurement): RedirectResponse
    {
        return redirect(route('realestate.procurements.show', $procurement) . '#lot');
    }
}
