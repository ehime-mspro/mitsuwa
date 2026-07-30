<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\DepartmentCode;
use App\Enums\InvestmentPattern;
use App\Enums\OperationStatus;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Investment;
use App\Models\InvestmentDetail;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class InvestmentController extends Controller
{
    /**
     * 費用項目のラベルマッピング
     */
    const COST_ITEM_LABELS = [
        'demolition'  => '解体費',
        'interior'    => '内装工事',
        'equipment'   => '設備工事',
        'electrical'  => '電気工事',
        'design'      => '設計費',
        'other'       => 'その他',
    ];

    /**
     * 投資案件一覧
     */
    public function index(Request $request)
    {
        $query = Investment::with(['property', 'unit'])
            ->whereHas('property', fn ($q) => $q->where('department', DepartmentCode::Tenant));

        // フィルター: 物件
        if ($request->filled('property_id')) {
            $query->where('property_id', $request->property_id);
        }

        // フィルター: ステータス
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // フィルター: パターン
        if ($request->filled('pattern')) {
            $query->where('pattern', $request->pattern);
        }

        // フィルター: キーワード（投資番号・業者名・工事概要）
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('investment_number', 'like', "%{$keyword}%")
                  ->orWhere('contractor_name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        $investments = $query->orderByDesc('created_at')
                             ->orderByDesc('id')
                             ->paginate(10)
                             ->withQueryString();

        // 一覧表示時: 各投資の回収率・状態をライブ計算（メモリ上のみ・DB 書き込みなし）
        foreach ($investments as $inv) {
            $inv->refreshRecovery();
        }

        // フィルター用データ
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'operation_status']);

        return view('tenant.investments.index', compact('investments', 'properties'));
    }

    /**
     * 投資案件登録フォーム
     */
    public function create(Request $request)
    {
        $nextNumber = $this->generateInvestmentNumber();

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        // 全区画（物件ごと）— ラベルをController側で整形
        $allUnits = $this->buildUnitOptions($properties);

        // 区画詳細からの「この区画に投資を登録」プリセット
        $presetPropertyId = null;
        $presetUnitId = null;
        if ($request->filled('unit_id')) {
            $unit = Unit::find($request->query('unit_id'));
            if ($unit) {
                $presetUnitId = $unit->id;
                $presetPropertyId = $unit->property_id;
            }
        }

        return view('tenant.investments.create', compact(
            'nextNumber', 'properties', 'allUnits', 'presetPropertyId', 'presetUnitId'
        ));
    }

    /**
     * 投資案件保存
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'unit_id'          => 'required|exists:units,id',
            'pattern'          => 'required|in:' . implode(',', array_column(InvestmentPattern::cases(), 'value')),
            'status'           => 'required|in:planning,in_progress,completed',
            'description'      => 'nullable|string|max:5000',
            'contractor_name'  => 'nullable|string|max:200',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'notes'            => 'nullable|string|max:5000',
            'details'          => 'required|array|min:1',
            'details.*.cost_item'        => 'required|string|max:100',
            'details.*.contractor_name'  => 'nullable|string|max:200',
            'details.*.amount'           => 'required|integer|min:0',
            'details.*.executed_at'      => 'nullable|date',
            'details.*.notes'            => 'nullable|string|max:1000',
            'attachments'                => 'nullable|array',
            'attachments.*'              => 'file|max:10240',
        ], [], [
            // 画面ラベルに合わせる（既定は「内容」「開始日」「終了日」）
            'description' => '工事概要',
            'start_date'  => '工事開始日',
            'end_date'    => '工事完了日',
        ]);

        // 区画が指定物件に属しているか
        $unit = Unit::findOrFail($validated['unit_id']);
        if ($unit->property_id !== (int) $validated['property_id']) {
            return back()->withInput()->withErrors(['unit_id' => '選択された区画は指定物件に属していません。']);
        }

        DB::beginTransaction();
        try {
            // 投資総額 = 明細の合計
            $totalAmount = collect($validated['details'])->sum('amount');

            $investment = Investment::create([
                'investment_number' => $this->generateInvestmentNumber(),
                'property_id'      => $validated['property_id'],
                'unit_id'          => $validated['unit_id'],
                'pattern'          => $validated['pattern'],
                'status'           => $validated['status'],
                'description'      => $validated['description'] ?? '',
                'contractor_name'  => $validated['contractor_name'] ?? null,
                'start_date'       => $validated['start_date'] ?? null,
                'end_date'         => $validated['end_date'] ?? null,
                'total_amount'     => $totalAmount,
                'notes'            => $validated['notes'] ?? null,
            ]);

            // 明細保存
            foreach ($validated['details'] as $detail) {
                $investment->details()->create([
                    'cost_item'        => $detail['cost_item'],
                    'contractor_name'  => $detail['contractor_name'] ?? null,
                    'amount'           => $detail['amount'],
                    'executed_at'      => $detail['executed_at'] ?? null,
                    'notes'            => $detail['notes'] ?? null,
                ]);
            }

            DB::commit();

            // 添付ファイルの保存（トランザクション外）
            $this->saveAttachments($request, $investment, 'investments');

            return redirect()->route('tenant.investments.show', $investment)
                ->with('success', '投資案件を登録しました。');

        } catch (QueryException $e) {
            DB::rollBack();
            return back()->withInput()->withErrors(['error' => '登録に失敗しました。再度お試しください。']);
        }
    }

    /**
     * 投資案件詳細
     */
    public function show(Investment $investment)
    {
        $investment->load(['property', 'unit', 'details', 'attachments.uploadedByUser']);

        // 回収情報をライブ計算しメモリに反映（詳細表示時のみ、実際に変化した場合に永続化）
        $recovery = $investment->refreshRecovery();
        if ($investment->isDirty()) {
            $investment->save();
        }

        // 削除済み添付ファイル（削除履歴表示用）
        $deletedAttachments = Attachment::onlyTrashed()
            ->where('attachable_type', $investment->getMorphClass())
            ->where('attachable_id', $investment->id)
            ->with('deletedByUser')
            ->orderByDesc('deleted_at')
            ->get();

        return view('tenant.investments.show', compact('investment', 'recovery', 'deletedAttachments'));
    }

    /**
     * 投資案件編集フォーム
     */
    public function edit(Investment $investment)
    {
        $investment->load(['property', 'unit', 'details']);

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        $allUnits = $this->buildUnitOptions($properties);

        // 明細データをJS用に整形
        $investmentDetails = $investment->details->map(function ($d) {
            return [
                'cost_item'       => $d->cost_item,
                'contractor_name' => $d->contractor_name ?? '',
                'amount'          => $d->amount,
                'executed_at'     => $d->executed_at ? $d->executed_at->format('Y-m-d') : '',
                'notes'           => $d->notes ?? '',
            ];
        })->values();

        return view('tenant.investments.edit', compact('investment', 'properties', 'allUnits', 'investmentDetails'));
    }

    /**
     * 投資案件更新
     */
    public function update(Request $request, Investment $investment)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'unit_id'          => 'required|exists:units,id',
            'pattern'          => 'required|in:' . implode(',', array_column(InvestmentPattern::cases(), 'value')),
            'status'           => 'required|in:planning,in_progress,completed',
            'description'      => 'nullable|string|max:5000',
            'contractor_name'  => 'nullable|string|max:200',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'notes'            => 'nullable|string|max:5000',
            'details'          => 'required|array|min:1',
            'details.*.cost_item'        => 'required|string|max:100',
            'details.*.contractor_name'  => 'nullable|string|max:200',
            'details.*.amount'           => 'required|integer|min:0',
            'details.*.executed_at'      => 'nullable|date',
            'details.*.notes'            => 'nullable|string|max:1000',
            'attachments'                => 'nullable|array',
            'attachments.*'              => 'file|max:10240',
        ], [], [
            // 画面ラベルに合わせる（既定は「内容」「開始日」「終了日」）
            'description' => '工事概要',
            'start_date'  => '工事開始日',
            'end_date'    => '工事完了日',
        ]);

        // 区画が指定物件に属しているか
        $unit = Unit::findOrFail($validated['unit_id']);
        if ($unit->property_id !== (int) $validated['property_id']) {
            return back()->withInput()->withErrors(['unit_id' => '選択された区画は指定物件に属していません。']);
        }

        DB::beginTransaction();
        try {
            $totalAmount = collect($validated['details'])->sum('amount');

            $investment->update([
                'property_id'     => $validated['property_id'],
                'unit_id'         => $validated['unit_id'],
                'pattern'         => $validated['pattern'],
                'status'          => $validated['status'],
                'description'     => $validated['description'] ?? '',
                'contractor_name' => $validated['contractor_name'] ?? null,
                'start_date'      => $validated['start_date'] ?? null,
                'end_date'        => $validated['end_date'] ?? null,
                'total_amount'    => $totalAmount,
                'notes'           => $validated['notes'] ?? null,
            ]);

            // 明細: sync方式（全削除→再挿入）
            $investment->details()->delete();
            foreach ($validated['details'] as $detail) {
                $investment->details()->create([
                    'cost_item'        => $detail['cost_item'],
                    'contractor_name'  => $detail['contractor_name'] ?? null,
                    'amount'           => $detail['amount'],
                    'executed_at'      => $detail['executed_at'] ?? null,
                    'notes'            => $detail['notes'] ?? null,
                ]);
            }

            DB::commit();

            // 添付ファイルの保存（トランザクション外）
            $this->saveAttachments($request, $investment, 'investments');

            return redirect()->route('tenant.investments.show', $investment)
                ->with('success', '投資案件を更新しました。');

        } catch (QueryException $e) {
            DB::rollBack();
            return back()->withInput()->withErrors(['error' => '更新に失敗しました。再度お試しください。']);
        }
    }

    /**
     * 投資案件削除（ソフトデリート）
     */
    public function destroy(Investment $investment)
    {
        $investment->delete();

        return redirect()->route('tenant.investments.index')
            ->with('success', '投資案件を削除しました。');
    }

    /**
     * 添付ファイルを保存する
     */
    private function saveAttachments(Request $request, Investment $investment, string $type): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            $path = $file->store('attachments/' . $type . '/' . $investment->id, 'public');

            $investment->attachments()->create([
                'file_name'   => $file->getClientOriginalName(),
                'file_path'   => $path,
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getMimeType(),
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    /**
     * 投資番号の自動採番: INV-YYYY-NNN
     */
    private function generateInvestmentNumber(): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";

        $lastNumber = Investment::withTrashed()
            ->where('investment_number', 'like', "{$prefix}%")
            ->orderByDesc('investment_number')
            ->value('investment_number');

        if ($lastNumber) {
            $seq = (int) substr($lastNumber, -3) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * 区画セレクト用の選択肢を構築する（floor + display_name の重複防止）
     */
    private function buildUnitOptions($properties)
    {
        return Unit::whereIn('property_id', $properties->pluck('id'))
            ->orderBy('property_id')->orderBy('floor')->orderBy('display_name')
            ->get(['id', 'property_id', 'display_name', 'floor', 'area_tsubo'])
            ->map(function ($u) {
                $tsubo = $u->area_tsubo ? number_format((float) $u->area_tsubo, 2) . '坪' : '';
                $displayName = $u->display_name;
                // floor と display_name の重複防止（display_nameが数字始まりなら階数を付与しない）
                $label = ($u->floor !== null && ! preg_match('/^\d/', $displayName))
                    ? $u->floor . $displayName
                    : $displayName;
                $label .= $tsubo ? "（{$tsubo}）" : '';
                return [
                    'id'          => $u->id,
                    'property_id' => $u->property_id,
                    'label'       => $label,
                ];
            })
            ->values();
    }
}
