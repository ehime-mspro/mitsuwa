<?php

namespace App\Http\Controllers\Dad;

use App\Enums\DadProjectStatus;
use App\Enums\DadProjectType;
use App\Http\Controllers\Controller;
use App\Models\DadClient;
use App\Models\DadEmployee;
use App\Models\DadProject;
use App\Models\DadSubcontractor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DAD 工事案件コントローラー
 * 案件の CRUD、見積〜入金ライフサイクル、3モードハイブリッド原価表示を提供
 */
class ProjectController extends Controller
{
    /**
     * 一覧（種別タブ + 担当 + 年度 + キーワード）
     */
    public function index(Request $request)
    {
        $type = $request->input('project_type');
        $staffId = $request->input('staff_user_id');
        $fiscalYear = $request->input('fiscal_year');

        $query = DadProject::query()
            ->with(['client', 'staffUser']);

        if ($type && in_array($type, ['public', 'private'], true)) {
            $query->where('project_type', $type);
        }
        if ($staffId) {
            $query->where('staff_user_id', $staffId);
        }
        if ($fiscalYear) {
            // 会計年度: 5/1 〜 4/30
            $start = $fiscalYear . '-05-01';
            $end = ($fiscalYear + 1) . '-04-30';
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('order_date', [$start, $end])
                  ->orWhereBetween('estimate_date', [$start, $end]);
            });
        }
        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where(function ($q) use ($kw) {
                $q->where('project_name', 'like', '%' . $kw . '%')
                  ->orWhere('project_code', 'like', '%' . $kw . '%');
            });
        }

        $projects = $query->orderBy('order_date', 'desc')->orderBy('id', 'desc')
            ->paginate(20)->withQueryString();

        // 集計（種別ごと）
        $countPublic = DadProject::where('project_type', 'public')->count();
        $countPrivate = DadProject::where('project_type', 'private')->count();

        $staffUsers = User::orderBy('name')->get();
        $currentFiscalYear = $this->currentFiscalYear();

        return view('dad.projects.index', compact(
            'projects', 'countPublic', 'countPrivate', 'staffUsers', 'currentFiscalYear'
        ));
    }

    /**
     * 案件詳細（3モードハイブリッド原価表示）
     */
    public function show(DadProject $project)
    {
        $project->load([
            'client',
            'staffUser',
            'costs' => function ($q) {
                $q->orderBy('cost_category')->orderBy('id');
            },
            'costs.subcontractor',
            'assignments.employee',
        ]);

        // Alpine.js 用にコスト行を JSON 化（@json 内関数禁止のため事前整形）
        $costRowsForJs = [];
        foreach ($project->costs as $c) {
            $costRowsForJs[] = [
                'id' => $c->id,
                'cost_category' => $c->cost_category->value,
                'cost_category_label' => $c->cost_category->label(),
                'description' => $c->description,
                'estimateAmount' => $c->estimated_amount,
                'actualAmount' => $c->actual_amount,
                'subcontractor_name' => optional($c->subcontractor)->company_name,
                'notes' => $c->notes,
            ];
        }

        return view('dad.projects.show', compact('project', 'costRowsForJs'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        $clients = DadClient::orderBy('client_type')->orderBy('name')->get();
        $subcontractors = DadSubcontractor::orderBy('company_name')->get();
        $staffUsers = User::orderBy('name')->get();

        return view('dad.projects.create', compact('clients', 'subcontractors', 'staffUsers'));
    }

    /**
     * 新規登録（案件 + 原価明細を 1 トランザクションで保存）
     */
    public function store(Request $request)
    {
        $validated = $this->validateProject($request);
        $costs = $this->validateCosts($request);

        $validated['created_by'] = $request->user()->id;

        $project = DB::transaction(function () use ($validated, $costs) {
            // 採番はトランザクション内で行い、同時 INSERT による project_code 衝突を防ぐ
            $validated['project_code'] = $this->generateProjectCode();
            $project = DadProject::create($validated);
            foreach ($costs as $costData) {
                $project->costs()->create($costData);
            }
            return $project;
        });

        return redirect()
            ->route('dad.projects.show', $project)
            ->with('success', '工事案件「' . $project->project_name . '」を登録しました。');
    }

    /**
     * 編集フォーム
     */
    public function edit(DadProject $project)
    {
        $project->load(['costs.subcontractor', 'assignments.employee']);

        $clients = DadClient::orderBy('client_type')->orderBy('name')->get();
        $subcontractors = DadSubcontractor::orderBy('company_name')->get();
        $staffUsers = User::orderBy('name')->get();
        $employees = DadEmployee::where('status', 'active')->orderBy('employee_code')->get();

        return view('dad.projects.edit', compact(
            'project', 'clients', 'subcontractors', 'staffUsers', 'employees'
        ));
    }

    /**
     * 更新（案件 + 原価明細を全置換）
     */
    public function update(Request $request, DadProject $project)
    {
        $validated = $this->validateProject($request);
        $costs = $this->validateCosts($request);
        $assignments = $this->validateAssignments($request);

        $validated['updated_by'] = $request->user()->id;

        DB::transaction(function () use ($project, $validated, $costs, $assignments) {
            $project->update($validated);
            // 原価明細: 全削除して挿入し直し（シンプル運用）
            $project->costs()->delete();
            foreach ($costs as $costData) {
                $project->costs()->create($costData);
            }
            // 人員配置: 全削除して挿入し直し
            $project->assignments()->delete();
            foreach ($assignments as $a) {
                $project->assignments()->create($a);
            }
        });

        return redirect()
            ->route('dad.projects.show', $project)
            ->with('success', '工事案件「' . $project->project_name . '」を更新しました。');
    }

    /**
     * 削除
     */
    public function destroy(DadProject $project)
    {
        $name = $project->project_name;
        $project->delete();

        return redirect()
            ->route('dad.projects.index')
            ->with('success', '工事案件「' . $name . '」を削除しました。');
    }

    /**
     * 案件本体のバリデーション
     */
    private function validateProject(Request $request): array
    {
        return $request->validate([
            'project_name' => ['required', 'string', 'max:200'],
            'project_type' => ['required', 'in:public,private'],
            'status' => ['required', 'in:estimate,ordered,in_progress,completed,paid,lost'],
            'client_id' => ['nullable', 'integer', 'exists:dad_clients,id'],
            'site_address' => ['nullable', 'string', 'max:300'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'estimate_amount' => ['nullable', 'integer', 'min:0'],
            'contract_amount' => ['nullable', 'integer', 'min:0'],
            'estimate_date' => ['nullable', 'date'],
            'order_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'completion_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'staff_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'memo' => ['nullable', 'string'],
        ], [
            'project_name.required' => '工事名は必須です。',
            'project_type.required' => '工事種別を選択してください。',
            'status.required' => 'ステータスを選択してください。',
        ]);
    }

    /**
     * 原価明細のバリデーション + 整形
     */
    private function validateCosts(Request $request): array
    {
        $costs = $request->input('costs', []);
        $result = [];

        foreach ($costs as $row) {
            // カテゴリーが空 = 空行とみなしスキップ
            if (empty($row['cost_category'])) continue;

            // 数値正規化（カンマ・全角・通貨記号除去は app.blade.php のグローバルリスナーで処理済み想定）
            $estimated = isset($row['estimated_amount']) && $row['estimated_amount'] !== '' ? (int) $row['estimated_amount'] : null;
            $actual = isset($row['actual_amount']) && $row['actual_amount'] !== '' ? (int) $row['actual_amount'] : null;

            $result[] = [
                'cost_category' => $row['cost_category'],
                'description' => $row['description'] ?? null,
                'estimated_amount' => $estimated,
                'actual_amount' => $actual,
                'subcontractor_id' => !empty($row['subcontractor_id']) ? (int) $row['subcontractor_id'] : null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * 人員配置のバリデーション + 整形
     */
    private function validateAssignments(Request $request): array
    {
        $assignments = $request->input('assignments', []);
        $result = [];

        foreach ($assignments as $a) {
            if (empty($a['employee_id'])) continue;

            $result[] = [
                'employee_id' => (int) $a['employee_id'],
                'role' => $a['role'] ?? null,
                'start_date' => !empty($a['start_date']) ? $a['start_date'] : null,
                'end_date' => !empty($a['end_date']) ? $a['end_date'] : null,
                'notes' => $a['notes'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * 案件番号自動採番（DAD-NNN）
     *
     * 必ず DB::transaction() の内側から呼ぶこと。lockForUpdate() で対象行（および空テーブルの場合は
     * gap lock）を取得し、同時実行による採番衝突を防ぐ。最後の防衛線として DB の UNIQUE 制約
     * (uk_dad_projects_code) があるため、二重防御となる。
     */
    private function generateProjectCode(): string
    {
        $last = DadProject::where('project_code', 'like', 'DAD-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (!$last) return 'DAD-001';

        $num = (int) substr($last->project_code, 4);
        return 'DAD-' . str_pad((string) ($num + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * 現在の会計年度（5月始まり）
     */
    private function currentFiscalYear(): int
    {
        $now = now();
        return $now->month >= 5 ? $now->year : $now->year - 1;
    }
}
