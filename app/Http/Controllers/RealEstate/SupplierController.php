<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\SupplierType;
use App\Http\Controllers\Controller;
use App\Models\ReSupplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * 仕入れ先一覧
     * Route: GET /realestate/suppliers
     */
    public function index(Request $request)
    {
        $query = ReSupplier::query();

        // フィルター: 区分
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // フィルター: キーワード（名前・担当者名）
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('contact_person', 'like', "%{$keyword}%")
                  ->orWhere('supplier_code', 'like', "%{$keyword}%");
            });
        }

        $suppliers = $query->orderBy('supplier_code')
            ->paginate(20)
            ->withQueryString();

        return view('realestate.suppliers.index', compact('suppliers'));
    }

    /**
     * 仕入れ先登録フォーム
     * Route: GET /realestate/suppliers/create
     */
    public function create()
    {
        return view('realestate.suppliers.create');
    }

    /**
     * 仕入れ先保存
     * Route: POST /realestate/suppliers
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'           => 'required|in:individual,corporation,realtor',
            'name'           => 'required|string|max:100',
            'contact_person' => 'nullable|string|max:50',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:100',
            'postal_code'    => 'nullable|string|max:10',
            'address'        => 'nullable|string|max:200',
            'notes'          => 'nullable|string|max:5000',
        ]);

        $validated['supplier_code'] = $this->generateSupplierCode();

        $supplier = ReSupplier::create($validated);

        return redirect()
            ->route('realestate.suppliers.show', $supplier)
            ->with('success', "仕入れ先「{$supplier->supplier_code} {$supplier->name}」を登録しました。");
    }

    /**
     * 仕入れ先詳細
     * Route: GET /realestate/suppliers/{supplier}
     */
    public function show(ReSupplier $supplier)
    {
        $procurements = $supplier->procurements()
            ->orderByDesc('info_obtained_date')
            ->get();

        return view('realestate.suppliers.show', compact('supplier', 'procurements'));
    }

    /**
     * 仕入れ先編集フォーム
     * Route: GET /realestate/suppliers/{supplier}/edit
     */
    public function edit(ReSupplier $supplier)
    {
        return view('realestate.suppliers.edit', compact('supplier'));
    }

    /**
     * 仕入れ先更新
     * Route: PUT /realestate/suppliers/{supplier}
     */
    public function update(Request $request, ReSupplier $supplier)
    {
        $validated = $request->validate([
            'type'           => 'required|in:individual,corporation,realtor',
            'name'           => 'required|string|max:100',
            'contact_person' => 'nullable|string|max:50',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:100',
            'postal_code'    => 'nullable|string|max:10',
            'address'        => 'nullable|string|max:200',
            'notes'          => 'nullable|string|max:5000',
        ]);

        $supplier->update($validated);

        return redirect()
            ->route('realestate.suppliers.show', $supplier)
            ->with('success', "仕入れ先「{$supplier->supplier_code} {$supplier->name}」を更新しました。");
    }

    /**
     * 仕入れ先削除（ソフトデリート）
     * Route: DELETE /realestate/suppliers/{supplier}
     */
    public function destroy(ReSupplier $supplier)
    {
        if ($supplier->hasProcurements()) {
            return back()->with('error', 'この仕入れ先は仕入れ案件で使用されているため削除できません。');
        }

        $supplier->delete();

        return redirect()->route('realestate.suppliers.index')
            ->with('success', "仕入れ先「{$supplier->supplier_code} {$supplier->name}」を削除しました。");
    }

    /**
     * 仕入れ先 簡易登録（フォーム内モーダルから Ajax 呼び出し）
     * Route: POST /api/realestate/suppliers/quick
     *
     * 完全一致名が既存にある場合（force=false 時）は 200 + duplicates[] を返却し
     * 呼び出し側で確認を促す。force=true なら同名でも新規作成する。
     */
    public function quickStore(Request $request)
    {
        $validated = $request->validate([
            'type'  => 'required|in:individual,corporation,realtor',
            'name'  => 'required|string|max:100',
            'force' => 'nullable|boolean',
        ]);

        $force = $request->boolean('force');

        // force=false の場合、name の完全一致をチェック
        if (! $force) {
            $duplicates = ReSupplier::where('name', $validated['name'])
                ->orderBy('supplier_code')
                ->get(['id', 'supplier_code', 'name', 'type']);

            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'duplicates' => $duplicates->map(function ($s) {
                        return [
                            'id'         => $s->id,
                            'code'       => $s->supplier_code,
                            'name'       => $s->name,
                            'type_label' => $s->type->label(),
                        ];
                    }),
                ], 200);
            }
        }

        // 新規作成
        $supplier = ReSupplier::create([
            'supplier_code' => $this->generateSupplierCode(),
            'type'          => $validated['type'],
            'name'          => $validated['name'],
        ]);

        return response()->json([
            'id'         => $supplier->id,
            'code'       => $supplier->supplier_code,
            'name'       => $supplier->name,
            'type_label' => $supplier->type->label(),
        ], 201);
    }

    /**
     * 仕入れ先Ajax検索
     * Route: GET /api/realestate/suppliers/search
     */
    public function search(Request $request)
    {
        $q = $request->input('q', '');

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $suppliers = ReSupplier::where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('contact_person', 'like', "%{$q}%")
                      ->orWhere('supplier_code', 'like', "%{$q}%");
            })
            ->orderBy('supplier_code')
            ->limit(20)
            ->get(['id', 'supplier_code', 'name', 'type']);

        return response()->json(
            $suppliers->map(function ($s) {
                return [
                    'id'         => $s->id,
                    'code'       => $s->supplier_code,
                    'name'       => $s->name,
                    'type_label' => $s->type->label(),
                ];
            })
        );
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 仕入れ先コードの自動採番: SUP-NNN
     */
    private function generateSupplierCode(): string
    {
        $prefix = 'SUP-';

        $lastCode = ReSupplier::withTrashed()
            ->where('supplier_code', 'like', "{$prefix}%")
            ->orderByDesc('supplier_code')
            ->value('supplier_code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }
}
