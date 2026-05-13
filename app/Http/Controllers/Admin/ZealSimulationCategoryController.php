<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ZealSimulationCalcType;
use App\Enums\ZealSimulationGroup;
use App\Http\Controllers\Controller;
use App\Models\ZealSimulationCategory;
use App\Models\ZealSimulationValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ZEAL 試算表 項目マスター管理
 *
 * 賃料・委託費・売上等の縦軸項目を管理する。
 * is_system=1 の項目（経費計・営業利益・累計利益）は削除・グループ変更不可。
 */
class ZealSimulationCategoryController extends Controller
{
    /**
     * 一覧表示
     */
    public function index()
    {
        $categories = ZealSimulationCategory::orderBy('sort_order')->orderBy('id')->get();

        return view('admin.master.zeal-simulation-categories.index', compact('categories'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        $groups    = ZealSimulationGroup::cases();
        $calcTypes = ZealSimulationCalcType::cases();

        return view('admin.master.zeal-simulation-categories.create', compact('groups', 'calcTypes'));
    }

    /**
     * 新規登録処理
     */
    public function store(Request $request)
    {
        $validated = $this->validateInput($request);

        // sort_order 自動採番（末尾）
        $maxOrder = ZealSimulationCategory::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 10;
        $validated['is_system']  = false;
        $validated['is_active']  = $request->boolean('is_active', true);

        ZealSimulationCategory::create($validated);

        return redirect()
            ->route('admin.master.zeal-simulation-categories.index')
            ->with('success', '「' . $validated['name'] . '」を追加しました。');
    }

    /**
     * 編集フォーム
     */
    public function edit(ZealSimulationCategory $zealSimulationCategory)
    {
        $category  = $zealSimulationCategory;
        $groups    = ZealSimulationGroup::cases();
        $calcTypes = ZealSimulationCalcType::cases();

        return view('admin.master.zeal-simulation-categories.edit', compact('category', 'groups', 'calcTypes'));
    }

    /**
     * 更新処理
     *
     * 固定費（calc_type=fixed）の default_amount を変更したとき、
     * 既存試算表の「apply_from_month 以降」かつ「手動上書きされていない」セルを新金額に自動反映する。
     * 過去月（apply_from_month より前）のセルは焼き付け済み金額をそのまま保持する。
     */
    public function update(Request $request, ZealSimulationCategory $zealSimulationCategory)
    {
        $category  = $zealSimulationCategory;
        $validated = $this->validateInput($request, $category);

        // システム固定項目はグループと計算タイプを変更不可
        if ($category->is_system) {
            unset($validated['group_type'], $validated['calc_type']);
        }

        $validated['is_active'] = $request->boolean('is_active', true);

        // 更新前の状態を保持（差分判定用）
        $wasFixed         = $category->calc_type === ZealSimulationCalcType::Fixed;
        $oldDefaultAmount = $category->default_amount;
        $applyFromMonth   = trim((string) $request->input('apply_from_month', ''));

        // 適用開始月の形式チェック（YYYY-MM）
        if ($applyFromMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $applyFromMonth)) {
            return back()
                ->withInput()
                ->withErrors(['apply_from_month' => '適用開始月は YYYY-MM 形式で入力してください。']);
        }

        $appliedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use (
            $category, $validated, $wasFixed, $oldDefaultAmount, $applyFromMonth,
            &$appliedCount, &$skippedCount
        ) {
            $category->update($validated);

            // 固定費 & default_amount 変更 & 適用開始月の指定がある場合のみ反映
            $isFixedNow = $category->calc_type === ZealSimulationCalcType::Fixed;
            $newAmount  = $category->default_amount;
            $changed    = $oldDefaultAmount !== $newAmount;

            if ($wasFixed && $isFixedNow && $changed && $applyFromMonth !== '' && $newAmount !== null) {
                // 該当セルを取得し、is_manual_override=false のものだけ更新
                $cells = ZealSimulationValue::where('category_id', $category->id)
                    ->where('year_month', '>=', $applyFromMonth)
                    ->get();

                foreach ($cells as $cell) {
                    if ($cell->is_manual_override) {
                        $skippedCount++;
                        continue;
                    }
                    $cell->amount = (int) $newAmount;
                    $cell->save();
                    $appliedCount++;
                }
            }
        });

        // 反映状況をメッセージに含める
        $message = '「' . $category->name . '」を更新しました。';
        if ($appliedCount > 0 || $skippedCount > 0) {
            $message .= sprintf(
                '既存試算表セルへの反映: %d セル更新／%d セル手動上書き保持。',
                $appliedCount,
                $skippedCount
            );
        }

        return redirect()
            ->route('admin.master.zeal-simulation-categories.index')
            ->with('success', $message);
    }

    /**
     * 削除処理
     */
    public function destroy(ZealSimulationCategory $zealSimulationCategory)
    {
        if ($zealSimulationCategory->is_system) {
            return back()->with('error', 'システム固定項目（' . $zealSimulationCategory->name . '）は削除できません。');
        }

        // 既存試算表で使用されているセル値もカスケード削除される（FK ON DELETE CASCADE）
        $name = $zealSimulationCategory->name;
        $zealSimulationCategory->delete();

        return redirect()
            ->route('admin.master.zeal-simulation-categories.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * 入力バリデーション（store/update 共通）
     */
    private function validateInput(Request $request, ?ZealSimulationCategory $category = null): array
    {
        $codeUnique = 'unique:zeal_simulation_categories,code';
        if ($category) {
            $codeUnique .= ',' . $category->id;
        }

        return $request->validate([
            'code'           => ['required', 'string', 'max:50', $codeUnique, 'regex:/^[a-z][a-z0-9_]*$/'],
            'name'           => 'required|string|max:100',
            'group_type'     => 'required|in:revenue,member,expense,summary',
            'calc_type'      => 'required|in:manual,fixed,revenue_linked,calculated',
            'default_amount' => 'nullable|integer',
            'rate_percent'   => 'nullable|numeric|min:0|max:100',
        ], [
            'code.required' => 'コードは必須です。',
            'code.regex'    => 'コードは半角小文字英数字とアンダースコアで入力してください（例: rent, web_operation）。',
            'code.unique'   => '同じコードが既に登録されています。',
            'name.required' => '項目名は必須です。',
            'rate_percent.max' => '率は 0〜100 の範囲で入力してください。',
        ]);
    }
}
