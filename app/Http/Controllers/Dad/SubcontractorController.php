<?php

namespace App\Http\Controllers\Dad;

use App\Http\Controllers\Controller;
use App\Models\DadSpecialty;
use App\Models\DadSubcontractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DAD 協力業者管理コントローラー
 * 専門分野（土工・舗装・配管等）と発注実績で管理する
 */
class SubcontractorController extends Controller
{
    /**
     * 一覧（専門分野フィルター + 名称検索 + 発注額集計）
     */
    public function index(Request $request)
    {
        $query = DadSubcontractor::query()
            ->with('specialty')
            ->withCount('projectCosts')
            ->withSum('projectCosts as total_actual_amount', 'actual_amount');

        if ($request->filled('specialty_id')) {
            $query->where('specialty_id', $request->input('specialty_id'));
        }

        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where(function ($q) use ($kw) {
                $q->where('company_name', 'like', '%' . $kw . '%')
                  ->orWhere('representative', 'like', '%' . $kw . '%');
            });
        }

        $subcontractors = $query->orderBy('specialty_id')->orderBy('company_name')
            ->paginate(20)->withQueryString();

        // 集計
        $totalCount = DadSubcontractor::count();
        $totalAmount = (int) DadSubcontractor::query()
            ->withSum('projectCosts as total', 'actual_amount')
            ->get()
            ->sum('total');

        $specialties = DadSpecialty::where('is_active', true)->orderBy('sort_order')->get();

        return view('dad.subcontractors.index', compact('subcontractors', 'totalCount', 'totalAmount', 'specialties'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        $specialties = DadSpecialty::where('is_active', true)->orderBy('sort_order')->get();
        return view('dad.subcontractors.create', compact('specialties'));
    }

    /**
     * 新規登録処理
     */
    public function store(Request $request)
    {
        $validated = $this->validateSubcontractor($request);
        $validated['created_by'] = $request->user()->id;

        $subcontractor = DadSubcontractor::create($validated);

        return redirect()
            ->route('dad.subcontractors.index')
            ->with('success', '「' . $subcontractor->company_name . '」を登録しました。');
    }

    /**
     * 詳細表示（基本情報 + 工事案件別の発注履歴）
     */
    public function show(DadSubcontractor $subcontractor)
    {
        $subcontractor->load('specialty');

        // 工事案件別に発注履歴を集約（同じ案件で複数明細がある場合は合算）
        $projectOrders = DB::table('dad_project_costs as c')
            ->join('dad_projects as p', 'c.project_id', '=', 'p.id')
            ->select(
                'c.project_id',
                'p.project_code',
                'p.project_name',
                'p.status as project_status',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(c.estimated_amount) as estimate_total'),
                DB::raw('SUM(c.actual_amount) as actual_total')
            )
            ->where('c.subcontractor_id', $subcontractor->id)
            ->groupBy('c.project_id', 'p.project_code', 'p.project_name', 'p.status')
            ->orderByDesc('p.id')
            ->get();

        return view('dad.subcontractors.show', compact('subcontractor', 'projectOrders'));
    }

    /**
     * 編集フォーム
     */
    public function edit(DadSubcontractor $subcontractor)
    {
        $specialties = DadSpecialty::where('is_active', true)->orderBy('sort_order')->get();
        return view('dad.subcontractors.edit', compact('subcontractor', 'specialties'));
    }

    /**
     * 更新処理
     */
    public function update(Request $request, DadSubcontractor $subcontractor)
    {
        $validated = $this->validateSubcontractor($request);
        $subcontractor->update($validated);

        return redirect()
            ->route('dad.subcontractors.index')
            ->with('success', '「' . $subcontractor->company_name . '」を更新しました。');
    }

    /**
     * 削除処理（発注実績がある場合は不可）
     */
    public function destroy(DadSubcontractor $subcontractor)
    {
        if ($subcontractor->hasProjectCosts()) {
            return redirect()
                ->route('dad.subcontractors.index')
                ->with('error', '「' . $subcontractor->company_name . '」は工事原価明細で参照中のため削除できません。');
        }

        $name = $subcontractor->company_name;
        $subcontractor->delete();

        return redirect()
            ->route('dad.subcontractors.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * バリデーションを共通化
     */
    private function validateSubcontractor(Request $request): array
    {
        return $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'representative' => ['nullable', 'string', 'max:50'],
            'specialty_id' => ['nullable', 'integer', 'exists:dad_specialties,id'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:20'],
            'fax' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [
            'company_name.required' => '会社名は必須です。',
            'company_name.max' => '会社名は100文字以内で入力してください。',
            'specialty_id.exists' => '選択された専門分野が存在しません。',
            'email.email' => 'メールアドレスの形式が正しくありません。',
        ]);
    }
}
