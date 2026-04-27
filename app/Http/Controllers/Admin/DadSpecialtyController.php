<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DadSpecialty;
use Illuminate\Http\Request;

/**
 * DAD 専門分野マスター管理コントローラー
 * 協力業者の専門分野（土工・舗装・配管等）を管理する
 * 色設定: 協力業者一覧でバッジ表示に使用される
 */
class DadSpecialtyController extends Controller
{
    /**
     * 一覧表示（並び順カスタマイズ可能・ドラッグソート）
     */
    public function index()
    {
        $specialties = DadSpecialty::orderBy('sort_order')->orderBy('id')->get();

        // Alpine.js 用: @json() 内で関数を使わないよう事前整形
        $specialtiesForJs = [];
        foreach ($specialties as $s) {
            $specialtiesForJs[] = [
                'id' => $s->id,
                'name' => $s->name,
                'color_bg' => $s->color_bg,
                'color_text' => $s->color_text,
            ];
        }

        return view('admin.master.dad-specialties.index', compact('specialties', 'specialtiesForJs'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        return view('admin.master.dad-specialties.create');
    }

    /**
     * 新規登録処理
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:dad_specialties,name',
            'color_bg' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_text' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => '専門分野名は必須です。',
            'name.max' => '専門分野名は50文字以内で入力してください。',
            'name.unique' => '同名の専門分野が既に登録されています。',
            'color_bg.regex' => '背景色は #RRGGBB 形式で入力してください。',
            'color_text.regex' => '文字色は #RRGGBB 形式で入力してください。',
        ]);

        $maxOrder = DadSpecialty::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;
        $validated['is_active'] = true;

        DadSpecialty::create($validated);

        return redirect()
            ->route('admin.master.dad-specialties.index')
            ->with('success', '「' . $validated['name'] . '」を追加しました。');
    }

    /**
     * 編集フォーム
     */
    public function edit(DadSpecialty $dadSpecialty)
    {
        return view('admin.master.dad-specialties.edit', ['specialty' => $dadSpecialty]);
    }

    /**
     * 更新処理
     */
    public function update(Request $request, DadSpecialty $dadSpecialty)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:dad_specialties,name,' . $dadSpecialty->id,
            'color_bg' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_text' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => '専門分野名は必須です。',
            'name.max' => '専門分野名は50文字以内で入力してください。',
            'name.unique' => '同名の専門分野が既に登録されています。',
            'color_bg.regex' => '背景色は #RRGGBB 形式で入力してください。',
            'color_text.regex' => '文字色は #RRGGBB 形式で入力してください。',
        ]);

        $dadSpecialty->update($validated);

        return redirect()
            ->route('admin.master.dad-specialties.index')
            ->with('success', '「' . $dadSpecialty->name . '」を更新しました。');
    }

    /**
     * 削除処理（協力業者で参照されている場合は不可）
     */
    public function destroy(DadSpecialty $dadSpecialty)
    {
        if ($dadSpecialty->subcontractors()->exists()) {
            return redirect()
                ->route('admin.master.dad-specialties.index')
                ->with('error', '「' . $dadSpecialty->name . '」は協力業者で使用中のため削除できません。');
        }

        $name = $dadSpecialty->name;
        $dadSpecialty->delete();

        return redirect()
            ->route('admin.master.dad-specialties.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * 並替え保存（Ajax）
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|exists:dad_specialties,id',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            DadSpecialty::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }
}
