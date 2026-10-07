<?php

namespace App\Http\Controllers\Dad;

use App\Enums\DadCostCategory;
use App\Enums\DadEmployeeStatus;
use App\Enums\DadProjectStatus;
use App\Enums\DadProjectType;
use App\Http\Controllers\Controller;
use App\Models\DadClient;
use App\Models\DadEmployee;
use App\Models\DadProject;
use App\Models\DadSubcontractor;
use App\Models\User;
use App\Support\JapanTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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

        $staffUsers = User::assignable()->orderBy('name')->get();
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

        // 協力業者発注履歴：外注費を業者ごとに集計（subcontractor_id null は除外）
        // 論理削除済み業者も会社名表示できるよう leftJoin（不在時は company_name=null → '—' 表示）
        $subcontractorOrders = DB::table('dad_project_costs as c')
            ->leftJoin('dad_subcontractors as s', 'c.subcontractor_id', '=', 's.id')
            ->select(
                'c.subcontractor_id',
                's.company_name',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(c.estimated_amount) as estimate_total'),
                DB::raw('SUM(c.actual_amount) as actual_total')
            )
            ->where('c.project_id', $project->id)
            ->where('c.cost_category', 'subcontract')
            ->whereNotNull('c.subcontractor_id')
            ->groupBy('c.subcontractor_id', 's.company_name')
            ->orderBy('s.company_name')
            ->get();

        // 添付ファイル（有効分・削除履歴）
        $attachments = $project->attachments()
            ->whereNull('deleted_at')
            ->with('uploadedByUser')
            ->orderByDesc('created_at')
            ->get();

        $deletedAttachments = $project->attachments()
            ->onlyTrashed()
            ->with(['uploadedByUser', 'deletedByUser'])
            ->orderByDesc('deleted_at')
            ->get();

        return view('dad.projects.show', compact('project', 'costRowsForJs', 'subcontractorOrders', 'attachments', 'deletedAttachments'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        $clients = DadClient::orderBy('client_type')->orderBy('name')->get();
        $subcontractors = DadSubcontractor::orderBy('company_name')->get();
        $staffUsers = User::assignable()->orderBy('name')->get();

        return view('dad.projects.create', compact('clients', 'subcontractors', 'staffUsers'));
    }

    /**
     * 新規登録（案件 + 原価明細を 1 トランザクションで保存）
     */
    public function store(Request $request)
    {
        [$validated, $costs] = $this->validateInput($request, null);

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
        $staffUsers = User::assignableWith($project->staff_user_id);
        $employees = $this->selectableEmployees($project);

        // 現在紐付く発注者が論理削除済みなら、編集画面で選択肢が消えないよう
        // そのレコードのみドロップダウンに追加で含める
        if ($project->client_id && !$clients->contains('id', $project->client_id)) {
            $deletedClient = DadClient::withTrashed()->find($project->client_id);
            if ($deletedClient) {
                $clients->push($deletedClient);
            }
        }

        // 原価明細で参照されている協力業者が論理削除済みなら、同様にドロップダウンへ追加
        $referencedSubIds = $project->costs->pluck('subcontractor_id')->filter()->unique();
        $missingSubIds = $referencedSubIds->diff($subcontractors->pluck('id'))->all();
        if (!empty($missingSubIds)) {
            $deletedSubs = DadSubcontractor::withTrashed()
                ->whereIn('id', $missingSubIds)
                ->get();
            $subcontractors = $subcontractors->concat($deletedSubs);
        }

        return view('dad.projects.edit', compact(
            'project', 'clients', 'subcontractors', 'staffUsers', 'employees'
        ));
    }

    /**
     * 更新（案件 + 原価明細を全置換）
     */
    public function update(Request $request, DadProject $project)
    {
        [$validated, $costs, $assignments] = $this->validateInput($request, $project);

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

    /** 原価明細の 1 行の欄（すべて空の行は空の行として捨てる） */
    private const COST_FIELDS = ['cost_category', 'description', 'estimated_amount', 'actual_amount', 'subcontractor_id', 'notes'];

    /** 人員配置の 1 行の欄 */
    private const ASSIGNMENT_FIELDS = ['employee_id', 'role', 'start_date', 'end_date', 'notes'];

    /**
     * 案件・原価明細・人員配置を 1 回で検査し、誤りを一度に出す（3 段に分けると、前の段で止まって後ろの誤りが次に送るまで出ない）。
     *
     * 原価・人員配置の行は、すべての欄が空の行だけを空の行として捨てる（カテゴリや従業員が空でも、ほかの欄に値があれば断る。
     * 「カテゴリが空なら空の行」とみなすと、金額を入れた行や Excel 取込でカテゴリを割り当て忘れた行が黙って消えた）。
     * 捨てたあとも**元の番号のまま**検査する（詰め直すと、入力エラーの「原価明細 N 行目」が画面の行とずれる）。
     * ⚠ `list` の規則は付けない（番号が飛んだ時点で落ちる）。
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function validateInput(Request $request, ?DadProject $project): array
    {
        $data = $request->all();
        $data['costs'] = $this->filledRows($request->input('costs'), self::COST_FIELDS);
        if ($project !== null) {
            $data['assignments'] = $this->filledRows($request->input('assignments'), self::ASSIGNMENT_FIELDS);
        }

        $validated = Validator::make($data, [
            'project_name' => ['required', 'string', 'max:200'],
            'project_type' => ['required', 'in:public,private'],
            'status' => ['required', 'in:estimate,ordered,in_progress,completed,paid,lost'],
            'client_id' => ['nullable', 'integer', 'exists:dad_clients,id'],
            'site_address' => ['nullable', 'string', 'max:300'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'estimate_amount' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_UNSIGNED_INT_COLUMN],
            'contract_amount' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_UNSIGNED_INT_COLUMN],
            'estimate_date' => ['nullable', 'date'],
            'order_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'completion_date' => ['nullable', 'date'],
            'payment_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'staff_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'memo' => ['nullable', 'string'],
            'costs' => ['nullable', 'array'],
            'costs.*' => ['array'],
            'costs.*.cost_category' => ['required', Rule::enum(DadCostCategory::class)],
            // 列（dad_project_costs.description）は 200 文字。入力欄は maxlength="200" だが、Excel 取込は長さを切らずに行へ入れる
            'costs.*.description' => ['nullable', 'string', 'max:200'],
            'costs.*.estimated_amount' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_UNSIGNED_INT_COLUMN],
            'costs.*.actual_amount' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_UNSIGNED_INT_COLUMN],
            'costs.*.subcontractor_id' => ['nullable', 'integer', 'exists:dad_subcontractors,id'],
            'costs.*.notes' => ['nullable', 'string'],
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['array'],
            // 新しく選べるのは在籍者だけ。今この工事案件に配置されている人は退職していても残せる（選択肢と同じ集合）
            'assignments.*.employee_id' => ['required', 'integer', Rule::in($project === null ? [] : $this->selectableEmployees($project)->pluck('id')->all()), 'distinct'],
            // 列（dad_project_assignments）は役割 50・備考 200 文字
            'assignments.*.role' => ['nullable', 'string', 'max:50'],
            'assignments.*.start_date' => ['nullable', 'date'],
            // 配置開始が空なら比べない（比べる相手が無いと Laravel は通す）
            'assignments.*.end_date' => ['nullable', 'date', 'after_or_equal:assignments.*.start_date'],
            'assignments.*.notes' => ['nullable', 'string', 'max:200'],
        ], [
            'project_name.required' => '工事名は必須です。',
            'project_type.required' => '工事種別を選択してください。',
            'status.required' => 'ステータスを選択してください。',
            // :position は画面の行の番号（1 から）
            'costs.*.cost_category.required' => '原価明細 :position 行目のカテゴリを選択してください。',
            'costs.*.cost_category.enum' => '原価明細 :position 行目のカテゴリが正しくありません。',
            'costs.*.subcontractor_id.exists' => '原価明細 :position 行目の協力業者が見つかりません。',
            'assignments.*.employee_id.required' => '人員配置 :position 行目の従業員を選択してください。',
            // 既定の文の :date には :position が効かない（和名を入れる順が最後）ので、文ごと書く
            'assignments.*.end_date.after_or_equal' => '人員配置 :position 行目の配置終了は、配置開始以降の日付を指定してください。',
        ], [
            // 画面ラベルに合わせる（既定は「プロジェクト名」「契約額」「開始日」、原価の金額は不動産の「見込み額」「確定額」）
            'project_name'    => '工事名',
            'contract_amount' => '受注金額',
            'start_date'      => '着工日',
            'costs' => '原価明細',
            'costs.*' => '原価明細 :position 行目',
            'costs.*.cost_category' => '原価明細 :position 行目のカテゴリ',
            'costs.*.description' => '原価明細 :position 行目の内容',
            'costs.*.estimated_amount' => '原価明細 :position 行目の見積額',
            'costs.*.actual_amount' => '原価明細 :position 行目の実績額',
            'costs.*.subcontractor_id' => '原価明細 :position 行目の協力業者',
            'costs.*.notes' => '原価明細 :position 行目の備考',
            'assignments' => '人員配置',
            'assignments.*' => '人員配置 :position 行目',
            'assignments.*.employee_id' => '人員配置 :position 行目の従業員',
            'assignments.*.role' => '人員配置 :position 行目の役割',
            'assignments.*.start_date' => '人員配置 :position 行目の配置開始',
            'assignments.*.end_date' => '人員配置 :position 行目の配置終了',
            'assignments.*.notes' => '人員配置 :position 行目の備考',
        ])->validate();

        $costs = collect($validated['costs'] ?? [])->map(fn ($row) => [
            'cost_category'    => $row['cost_category'],
            'description'      => $row['description'] ?? null,
            'estimated_amount' => isset($row['estimated_amount']) ? (int) $row['estimated_amount'] : null,
            'actual_amount'    => isset($row['actual_amount']) ? (int) $row['actual_amount'] : null,
            'subcontractor_id' => isset($row['subcontractor_id']) ? (int) $row['subcontractor_id'] : null,
            'notes'            => $row['notes'] ?? null,
        ])->values()->all();

        $assignments = collect($validated['assignments'] ?? [])->map(fn ($row) => [
            'employee_id' => (int) $row['employee_id'],
            'role'        => $row['role'] ?? null,
            'start_date'  => $row['start_date'] ?? null,
            'end_date'    => $row['end_date'] ?? null,
            'notes'       => $row['notes'] ?? null,
        ])->values()->all();

        $project = collect($validated)->except(['costs', 'assignments'])->all();

        return [$project, $costs, $assignments];
    }

    /**
     * 人員配置で選べる従業員: 在籍者 ＋ この工事案件に今配置されている人（退職していても。編集画面で「（退職）」つきで出す）。
     * 在籍者だけにすると、退職した人の配置は選択肢に無く、編集画面をそのまま保存するだけで従業員が空で送られて配置が消えた。
     * ⚠ 配置は更新前の DB から読む（保存は消して入れ直すので、検査は必ず保存の前に走る）。
     */
    private function selectableEmployees(DadProject $project): \Illuminate\Database\Eloquent\Collection
    {
        $assigned = $project->assignments()->pluck('employee_id')->all();

        return DadEmployee::query()
            ->where(fn ($q) => $q->where('status', DadEmployeeStatus::Active->value)->orWhereIn('id', $assigned))
            ->orderBy('employee_code')
            ->get();
    }

    /**
     * 行の配列から、すべての欄が空の行を捨てる（番号はそのまま）。配列でない値は検査で断るためにそのまま返す。
     *
     * @param  array<int, string>  $fields
     */
    private function filledRows(mixed $rows, array $fields): mixed
    {
        if (! is_array($rows)) {
            return $rows;
        }

        return array_filter($rows, function ($row) use ($fields) {
            if (! is_array($row)) {
                return true;
            }
            foreach ($fields as $field) {
                if (($row[$field] ?? null) !== null && $row[$field] !== '') {
                    return true;
                }
            }

            return false;
        });
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
        $today = JapanTime::today();
        return $today->month >= 5 ? $today->year : $today->year - 1;
    }
}
