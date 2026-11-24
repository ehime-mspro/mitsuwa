<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Services\RealEstate\ProcurementToProjectConverter;
use App\Support\AreaConverter;
use App\Support\DeletionBlockers;
use App\Support\ProcurementLotLinks;
use App\Support\TsuboPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
 * ⚠ 区画を足すとき、その土地に区画を選んでいない契約・建売・契約以降の注文住宅があれば、足す区画にそのうち 1 つをつなぐ
 *   （設計書 2026-11-22-lot-link-on-add。ProcurementLotLinks）。区画のステータスは、つないだものに合わせてサーバーが決める（決まり 2）。
 * ⚠ 追加のフォームは開いたときの候補を hidden の lot_link_candidates に持つ。今の候補と違えば、何も保存せず入力エラーで断る
 *   （決まり 5。入力エラーにすると打った値が残り、戻った画面には今の候補が出る）。
 * ⚠ 区画の作成・つなぐ書き込み・仕入れ案件のステータスの集約は 1 つのトランザクションで行う。区画の行の編集（更新）ではつながない。
 * ⚠ 区画の更新・削除も、集約と 1 つのトランザクション（途中で落ちたら区画も変えない。docs/RULES.md Bug #148 の追記）。
 */
class ProcurementLotController extends Controller
{
    public const LAND_ONLY = '区画を登録できるのは、物件種別が土地の仕入れ案件だけです。';

    public const NOT_FOUND = '区画は登録されていません（ほかの画面で削除された可能性があります）。';

    /** 同じ土地の中で号地番号が重なる登録・更新の断り（入力エラー） */
    public const DUPLICATE_NUMBER = 'この号地番号はこの土地にすでにあります。別の番号にしてください。';

    /** 紐づいているもののある区画の削除の断り（%s は DeletionBlockers::countsLabel()。例 契約 1 件・注文住宅 2 件） */
    public const LINKED = '%sが紐づいているため、この区画は削除できません。';

    /** 区画を足してつないだときの帯（%s は ProcurementLotLinks の名前。例 契約「勝山の土地（山田 太郎 様）」） */
    public const LINKED_ON_ADD = '区画を登録しました（%sをこの区画に紐づけました）。';

    /** 画面を開いたあとで、区画を選んでいないものの顔ぶれが変わったときの断り（入力エラー。決まり 5） */
    public const LINK_STALE = '区画を選んでいない契約・建売物件・注文住宅が、画面を開いたあとで変わりました。紐づけるものを確かめて、もう一度登録してください。';

    /** POST /realestate/procurements/{procurement}/lots */
    public function store(Request $request, ReProcurement $procurement): RedirectResponse
    {
        if (! $procurement->property_type?->isLandOnly()) {
            return redirect()->route('realestate.procurements.show', $procurement)->with('error', self::LAND_ONLY);
        }

        $links = ProcurementLotLinks::candidates($procurement);
        $validated = $this->validated($request, $procurement, null, $links);

        // 選んだ（1 件なら hidden で送られた）候補。候補が無い土地では null
        $link = collect($links)->firstWhere('key', $validated['lot_link'] ?? null);
        unset($validated['lot_link'], $validated['lot_link_candidates']);
        if ($link !== null) {
            $validated['status'] = $link['status']->value;
        }

        DB::transaction(function () use ($procurement, $validated, $link) {
            $lot = $procurement->lots()->create($validated);
            // ほかの保存が先につないだ・付け替えたら 0 行。投げて区画の作成ごと取り消し、顔ぶれが変わった文で断る
            // （区画を「成約・紐付けなし」で残さない。設計書 2026-11-23-lot-link-followups の B1）
            if ($link !== null && ProcurementLotLinks::link($lot, $link['owner']) !== 1) {
                throw ValidationException::withMessages(['lot_link' => self::LINK_STALE])
                    ->redirectTo(self::back($procurement)->getTargetUrl());
            }
            $procurement->syncStatusFromLots();
        });

        return self::back($procurement)->with('lot_notice', $link === null
            ? '区画を登録しました。'
            : sprintf(self::LINKED_ON_ADD, $link['label']));
    }

    /** PUT /realestate/procurements/{procurement}/lots/{lot} */
    public function update(Request $request, ReProcurement $procurement, ReProcurementLot $lot): RedirectResponse
    {
        $validated = $this->validated($request, $procurement, $lot, null);

        // 区画の保存と仕入れ案件のステータスの集約は 1 つのトランザクション（集約の途中で落ちたら区画も変えない。docs/RULES.md Bug #148 の追記）
        DB::transaction(function () use ($procurement, $lot, $validated) {
            $lot->update($validated);
            $procurement->syncStatusFromLots();
        });

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

        // 区画の削除と集約は 1 つのトランザクション（集約の途中で落ちたら区画も消さない。Bug #148 の追記）
        DB::transaction(function () use ($procurement, $lot) {
            $lot->delete();
            $procurement->syncStatusFromLots();
        });

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
    private function validated(Request $request, ReProcurement $procurement, ?ReProcurementLot $lot, ?array $links): array
    {
        $statuses = implode(',', array_column(LotStatus::cases(), 'value'));

        // 断られたら区画の欄（#lot）へ戻す（既定の戻り先だと詳細の先頭へ戻り、欄の中の理由が画面の外になる。Bug #64 の形）
        try {
            $validated = $this->check($request, $statuses, $procurement, $lot, $links);
        } catch (ValidationException $e) {
            throw $e->redirectTo(self::back($procurement)->getTargetUrl());
        }

        // lot_form は画面へ伝えるだけで保存しない
        unset($validated['lot_form']);

        return $validated;
    }

    /**
     * $links は登録では ProcurementLotLinks::candidates()、更新では null（区画の行の編集はつながない）。
     * 候補があるときはステータスの欄を出さないので status を受け取らず、紐づけるもの（lot_link）を候補のキーから選ばせる。
     */
    private function check(Request $request, string $statuses, ReProcurement $procurement, ?ReProcurementLot $lot, ?array $links): array
    {
        $linking = $links !== null && $links !== [];

        $validated = Validator::make($request->all(), [
            'lot_form'      => 'nullable|string|max:20',
            // 同じ土地の中で号地番号が重ならない（ほかの土地の号地・更新する区画自身は数えない）。
            // ⚠ 上限の走査（AmountLimitTest）が文字の規則を 1 つずつ読むので、integer と max: を同じ文字に書き、unique は文字にして足す
            'lot_number'    => 'bail|required|integer|min:1|max:' . self::MAX_INT_COLUMN . '|'
                . Rule::unique('re_procurement_lots', 'lot_number')->where('procurement_id', $procurement->id)->ignore($lot?->id),
            'area_sqm'      => 'required|numeric|min:0.01|max:99999999.99',
            'selling_price' => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'status'        => $linking ? 'exclude' : "required|in:{$statuses}",
            'notes'         => 'nullable|string|max:200',
            // 区画を選んでいないものの中から、この区画につなぐもの（決まり 1）。保存はしない
            'lot_link'      => $linking ? ['bail', 'required', 'string', Rule::in(array_column($links, 'key'))] : 'exclude',
            // 開いたときの候補（ProcurementLotLinks::fingerprint()）。保存はしない
            'lot_link_candidates' => $links === null ? 'exclude' : 'nullable|string',
        ], [
            'lot_number.unique' => self::DUPLICATE_NUMBER,
            'lot_link.required' => 'この区画に紐づけるものを選んでください。',
        ])->after(function ($validator) use ($request, $links) {
            // 画面を開いたあとで顔ぶれが変わったら、紐づけるものの誤りをこの 1 件にする（決まり 5）。
            // ステータスの誤りも出さない（候補のあった画面にはステータスの欄が無い。設計書 2026-11-23-lot-link-followups の B4）
            if ($links !== null && ProcurementLotLinks::fingerprint($links) !== (string) ($request->input('lot_link_candidates') ?? '')) {
                $validator->errors()->forget('lot_link');
                $validator->errors()->forget('status');
                $validator->errors()->add('lot_link', self::LINK_STALE);
            }
        })->validate();

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
