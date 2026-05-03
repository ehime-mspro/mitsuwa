<?php

namespace App\Http\Controllers\Zeal;

use App\Http\Controllers\Controller;
use App\Models\ZealPlan;
use App\Support\Settings;
use Illuminate\Http\Request;

/**
 * ZEAL プランマスタ CRUD コントローラー
 */
class PlanController extends Controller
{
    /**
     * プラン一覧
     */
    public function index()
    {
        $plans   = ZealPlan::orderBy('display_order')->orderBy('id')->get();
        $taxRate = Settings::taxRate();

        return view('zeal.plans.index', compact('plans', 'taxRate'));
    }

    /**
     * プラン登録フォーム
     */
    public function create()
    {
        $taxRate = Settings::taxRate();

        return view('zeal.plans.create', compact('taxRate'));
    }

    /**
     * プラン登録処理
     */
    public function store(Request $request)
    {
        $validated = $this->validatePlan($request);

        ZealPlan::create($validated);

        return redirect()
            ->route('zeal.plans.index')
            ->with('success', '「' . $validated['name'] . '」を登録しました。');
    }

    /**
     * プラン編集フォーム
     */
    public function edit(ZealPlan $plan)
    {
        $taxRate = Settings::taxRate();

        return view('zeal.plans.edit', compact('plan', 'taxRate'));
    }

    /**
     * プラン更新処理
     */
    public function update(Request $request, ZealPlan $plan)
    {
        $validated = $this->validatePlan($request, $plan->id);

        $plan->update($validated);

        return redirect()
            ->route('zeal.plans.index')
            ->with('success', '「' . $plan->name . '」を更新しました。');
    }

    /**
     * プラン削除
     * 契約履歴に使用されているプランは削除不可（active を無効化してください）
     */
    public function destroy(ZealPlan $plan)
    {
        // 契約履歴で使用中か確認（ON DELETE RESTRICT のため DB エラー回避）
        if ($plan->memberContracts()->exists()) {
            return back()->with('error', '「' . $plan->name . '」は契約履歴で使用されているため削除できません。「有効」を無効にしてご利用ください。');
        }

        $name = $plan->name;
        $plan->delete();

        return redirect()
            ->route('zeal.plans.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * プランフォームのバリデーション（create / update 共通）
     *
     * @param  int|null  $ignoreId  更新時: unique チェックから除外する plan_id
     */
    private function validatePlan(Request $request, ?int $ignoreId = null): array
    {
        $uniqueRule = 'unique:zeal_plans,name';
        if ($ignoreId !== null) {
            $uniqueRule .= ',' . $ignoreId;
        }

        $validated = $request->validate([
            'name'                         => ['required', 'string', 'max:100', $uniqueRule],
            'regular_price_excl'           => 'required|integer|min:0|max:9999999',
            'campaign_price_excl'          => 'nullable|integer|min:0|max:9999999',
            'campaign_starts_on'           => 'nullable|date',
            'campaign_ends_on'             => 'nullable|date|after_or_equal:campaign_starts_on',
            'max_concurrent_reservations'  => 'nullable|integer|min:1|max:99',
            'includes_personal'            => 'boolean',
            'includes_semi_personal'       => 'boolean',
            'monthly_session_limit'        => 'nullable|integer|min:1|max:999',
            'is_pair_plan'                 => 'boolean',
            'display_order'                => 'required|integer|min:0|max:9999',
            'active'                       => 'boolean',
        ], [
            'name.required'            => 'プラン名は必須です。',
            'name.max'                 => 'プラン名は100文字以内で入力してください。',
            'name.unique'              => '同じプラン名がすでに存在します。',
            'regular_price_excl.required' => '通常価格（税抜）は必須です。',
            'regular_price_excl.integer'  => '通常価格は整数で入力してください。',
            'campaign_ends_on.after_or_equal' => 'キャンペーン終了日は開始日以降の日付を入力してください。',
        ]);

        // チェックボックスは未チェック時にリクエストに含まれないため false に変換
        $validated['includes_personal']   = $request->boolean('includes_personal');
        $validated['includes_semi_personal'] = $request->boolean('includes_semi_personal');
        $validated['is_pair_plan']        = $request->boolean('is_pair_plan');
        $validated['active']              = $request->boolean('active');

        return $validated;
    }
}
