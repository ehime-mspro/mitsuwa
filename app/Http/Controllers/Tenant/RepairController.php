<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\DepartmentCode;
use App\Enums\OperationStatus;
use App\Enums\RepairStatus;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairController extends Controller
{
    /**
     * カテゴリの選択肢
     */
    const CATEGORIES = [
        'aircon'     => 'エアコン',
        'plumbing'   => '給排水',
        'electrical' => '電気',
        'exterior'   => '外壁・屋根',
        'interior'   => '内装',
        'other'      => 'その他',
    ];

    /**
     * 修繕一覧
     */
    public function index(Request $request)
    {
        $query = Repair::with(['property', 'unit'])
            ->whereHas('property', fn ($q) => $q->where('department', DepartmentCode::Tenant));

        // フィルター: 物件
        if ($request->filled('property_id')) {
            $query->where('property_id', $request->property_id);
        }

        // フィルター: ステータス
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // フィルター: カテゴリ
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // フィルター: キーワード（修繕内容・業者名）
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('description', 'like', "%{$keyword}%")
                  ->orWhere('contractor_name', 'like', "%{$keyword}%");
            });
        }

        $repairs = $query->orderByDesc('created_at')
                         ->orderByDesc('id')
                         ->paginate(10)
                         ->withQueryString();

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'operation_status']);

        return view('tenant.repairs.index', compact('repairs', 'properties'));
    }

    /**
     * 修繕登録フォーム
     */
    public function create()
    {
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        $allUnits = Unit::whereIn('property_id', $properties->pluck('id'))
            ->orderBy('property_id')->orderBy('floor')->orderBy('display_name')
            ->get(['id', 'property_id', 'display_name', 'floor'])
            ->map(function ($u) {
                return [
                    'id'          => $u->id,
                    'property_id' => $u->property_id,
                    'label'       => ($u->floor ?? '') . $u->display_name,
                ];
            })
            ->values();

        return view('tenant.repairs.create', compact('properties', 'allUnits'));
    }

    /**
     * 修繕保存
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'unit_id'          => 'nullable|exists:units,id',
            'status'           => 'required|in:' . implode(',', array_column(RepairStatus::cases(), 'value')),
            'category'         => 'nullable|string|max:50',
            'description'      => 'required|string|max:5000',
            'contractor_name'  => 'nullable|string|max:200',
            'cost'             => 'nullable|integer|min:0',
            'started_at'       => 'nullable|date',
            'completed_at'     => 'nullable|date|after_or_equal:started_at',
            'notes'            => 'nullable|string|max:5000',
            'attachments'      => 'nullable|array',
            'attachments.*'    => 'file|max:10240',
        ]);

        // 区画が指定物件に属しているか（区画指定時のみ）
        if ($validated['unit_id']) {
            $unit = Unit::findOrFail($validated['unit_id']);
            if ($unit->property_id !== (int) $validated['property_id']) {
                return back()->withInput()->withErrors(['unit_id' => '選択された区画は指定物件に属していません。']);
            }
        }

        unset($validated['attachments']);
        $repair = Repair::create($validated);

        // 添付ファイルの保存
        $this->saveAttachments($request, $repair, 'repairs');

        return redirect()->route('tenant.repairs.show', $repair)
            ->with('success', '修繕を登録しました。');
    }

    /**
     * 修繕詳細
     */
    public function show(Repair $repair)
    {
        $repair->load(['property', 'unit', 'attachments.uploadedByUser']);

        // 削除済み添付ファイル（削除履歴表示用）
        $deletedAttachments = Attachment::onlyTrashed()
            ->where('attachable_type', $repair->getMorphClass())
            ->where('attachable_id', $repair->id)
            ->with('deletedByUser')
            ->orderByDesc('deleted_at')
            ->get();

        return view('tenant.repairs.show', compact('repair', 'deletedAttachments'));
    }

    /**
     * 修繕編集フォーム
     */
    public function edit(Repair $repair)
    {
        $repair->load(['property', 'unit']);

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        $allUnits = Unit::whereIn('property_id', $properties->pluck('id'))
            ->orderBy('property_id')->orderBy('floor')->orderBy('display_name')
            ->get(['id', 'property_id', 'display_name', 'floor'])
            ->map(function ($u) {
                return [
                    'id'          => $u->id,
                    'property_id' => $u->property_id,
                    'label'       => ($u->floor ?? '') . $u->display_name,
                ];
            })
            ->values();

        return view('tenant.repairs.edit', compact('repair', 'properties', 'allUnits'));
    }

    /**
     * 修繕更新
     */
    public function update(Request $request, Repair $repair)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'unit_id'          => 'nullable|exists:units,id',
            'status'           => 'required|in:' . implode(',', array_column(RepairStatus::cases(), 'value')),
            'category'         => 'nullable|string|max:50',
            'description'      => 'required|string|max:5000',
            'contractor_name'  => 'nullable|string|max:200',
            'cost'             => 'nullable|integer|min:0',
            'started_at'       => 'nullable|date',
            'completed_at'     => 'nullable|date|after_or_equal:started_at',
            'notes'            => 'nullable|string|max:5000',
            'attachments'      => 'nullable|array',
            'attachments.*'    => 'file|max:10240',
        ]);

        if ($validated['unit_id']) {
            $unit = Unit::findOrFail($validated['unit_id']);
            if ($unit->property_id !== (int) $validated['property_id']) {
                return back()->withInput()->withErrors(['unit_id' => '選択された区画は指定物件に属していません。']);
            }
        }

        unset($validated['attachments']);
        $repair->update($validated);

        // 添付ファイルの保存
        $this->saveAttachments($request, $repair, 'repairs');

        return redirect()->route('tenant.repairs.show', $repair)
            ->with('success', '修繕を更新しました。');
    }

    /**
     * 修繕削除（ソフトデリート）
     */
    public function destroy(Repair $repair)
    {
        $repair->delete();

        return redirect()->route('tenant.repairs.index')
            ->with('success', '修繕を削除しました。');
    }

    /**
     * 添付ファイルを保存する
     */
    private function saveAttachments(Request $request, Repair $repair, string $type): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            $path = $file->store('attachments/' . $type . '/' . $repair->id, 'public');

            $repair->attachments()->create([
                'file_name'   => $file->getClientOriginalName(),
                'file_path'   => $path,
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getMimeType(),
                'uploaded_by' => Auth::id(),
            ]);
        }
    }
}
