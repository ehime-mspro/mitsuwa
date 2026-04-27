<?php

namespace App\Http\Controllers\Dad;

use App\Enums\DadEmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\DadEmployee;
use Illuminate\Http\Request;

/**
 * DAD 従業員管理コントローラー
 * 在籍状況・現場配置・保有資格で従業員をマスター管理する
 */
class EmployeeController extends Controller
{
    /**
     * 一覧（在籍状況フィルター + 名称検索）
     * デフォルトは status=active のみ表示
     */
    public function index(Request $request)
    {
        $statusFilter = $request->input('status', 'active');

        $query = DadEmployee::query()
            ->with(['assignments' => function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now()->toDateString());
            }, 'assignments.project']);

        if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'retired'], true)) {
            $query->where('status', $statusFilter);
        }

        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', '%' . $kw . '%')
                  ->orWhere('name_kana', 'like', '%' . $kw . '%')
                  ->orWhere('employee_code', 'like', '%' . $kw . '%');
            });
        }

        $employees = $query->orderBy('employee_code')->paginate(20)->withQueryString();

        // 集計
        $countActive = DadEmployee::where('status', 'active')->count();
        $countAssigned = DadEmployee::whereHas('assignments', function ($q) {
            $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
        })->where('status', 'active')->count();
        $countQualified = DadEmployee::where('status', 'active')
            ->whereNotNull('qualifications')
            ->where('qualifications', '!=', '')
            ->count();

        return view('dad.employees.index', compact('employees', 'statusFilter', 'countActive', 'countAssigned', 'countQualified'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        return view('dad.employees.create');
    }

    /**
     * 新規登録処理
     */
    public function store(Request $request)
    {
        $validated = $this->validateEmployee($request);
        $employee = DadEmployee::create($validated);

        return redirect()
            ->route('dad.employees.index')
            ->with('success', '「' . $employee->name . '」を登録しました。');
    }

    /**
     * 編集フォーム
     */
    public function edit(DadEmployee $employee)
    {
        return view('dad.employees.edit', compact('employee'));
    }

    /**
     * 更新処理
     */
    public function update(Request $request, DadEmployee $employee)
    {
        $validated = $this->validateEmployee($request, $employee->id);
        $employee->update($validated);

        return redirect()
            ->route('dad.employees.index')
            ->with('success', '「' . $employee->name . '」を更新しました。');
    }

    /**
     * 削除（工事配置がある場合は不可）
     */
    public function destroy(DadEmployee $employee)
    {
        if ($employee->assignments()->exists()) {
            return redirect()
                ->route('dad.employees.index')
                ->with('error', '「' . $employee->name . '」は工事配置で参照中のため削除できません。退職に切り替えてください。');
        }

        $name = $employee->name;
        $employee->delete();

        return redirect()
            ->route('dad.employees.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    private function validateEmployee(Request $request, ?int $ignoreId = null): array
    {
        $codeUnique = 'unique:dad_employees,employee_code' . ($ignoreId ? ',' . $ignoreId : '');

        return $request->validate([
            'employee_code' => ['required', 'string', 'max:20', $codeUnique],
            'name' => ['required', 'string', 'max:50'],
            'name_kana' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:50'],
            'qualifications' => ['nullable', 'string'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,retired'],
            'notes' => ['nullable', 'string'],
        ], [
            'employee_code.required' => '社員番号は必須です。',
            'employee_code.unique' => '同じ社員番号が既に登録されています。',
            'name.required' => '氏名は必須です。',
            'status.required' => '在籍状況を選択してください。',
        ]);
    }
}
