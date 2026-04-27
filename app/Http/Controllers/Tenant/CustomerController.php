<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\CustomerType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * 顧客一覧
     * Route: GET /tenant/customers
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        // フィルター: 顧客種別
        if ($request->filled('customer_type')) {
            $query->where('customer_type', $request->customer_type);
        }

        // フィルター: 契約状況
        $contractStatus = $request->input('contract_status', '');
        if ($contractStatus === 'active') {
            $query->whereHas('activeContracts');
        } elseif ($contractStatus === 'none') {
            $query->whereDoesntHave('activeContracts');
        }

        // フィルター: キーワード（コード・顧客名・フリガナ・代表者名）
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                  ->orWhere('name', 'like', "%{$keyword}%")
                  ->orWhere('name_kana', 'like', "%{$keyword}%")
                  ->orWhere('representative', 'like', "%{$keyword}%");
            });
        }

        $customers = $query->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('tenant.customers.index', compact('customers'));
    }

    /**
     * 顧客登録フォーム
     * Route: GET /tenant/customers/create
     */
    public function create()
    {
        $nextCode = $this->generateCustomerCode();

        return view('tenant.customers.create', compact('nextCode'));
    }

    /**
     * 顧客保存
     * Route: POST /tenant/customers
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:200',
            'name_kana'         => 'nullable|string|max:200',
            'customer_type'     => 'required|in:corporation,sole_proprietor,individual',
            'representative'    => 'nullable|string|max:100',
            'contact_person'    => 'nullable|string|max:100',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:255',
            'postal_code'       => 'nullable|string|max:10',
            'address'           => 'nullable|string|max:500',
            'notes'             => 'nullable|string|max:5000',
        ]);

        $validated['code'] = $this->generateCustomerCode();

        $customer = Customer::create($validated);

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('success', "顧客「{$customer->code} {$customer->name}」を登録しました。");
    }

    /**
     * 顧客詳細
     * Route: GET /tenant/customers/{customer}
     */
    public function show(Customer $customer)
    {
        // 契約中
        $activeContracts = $customer->contracts()
            ->where('status', ContractStatus::Active)
            ->with(['property', 'unit'])
            ->orderByDesc('contract_date')
            ->get();

        // 解約済み
        $terminatedContracts = $customer->contracts()
            ->where('status', ContractStatus::Terminated)
            ->with(['property', 'unit'])
            ->orderByDesc('contract_end_date')
            ->get();

        // 問合せ履歴
        $inquiries = $customer->inquiries()
            ->with('property')
            ->orderByDesc('inquiry_date')
            ->orderByDesc('id')
            ->get();

        return view('tenant.customers.show', compact(
            'customer', 'activeContracts', 'terminatedContracts', 'inquiries'
        ));
    }

    /**
     * 顧客編集フォーム
     * Route: GET /tenant/customers/{customer}/edit
     */
    public function edit(Customer $customer)
    {
        return view('tenant.customers.edit', compact('customer'));
    }

    /**
     * 顧客更新
     * Route: PUT /tenant/customers/{customer}
     */
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:200',
            'name_kana'         => 'nullable|string|max:200',
            'customer_type'     => 'required|in:corporation,sole_proprietor,individual',
            'representative'    => 'nullable|string|max:100',
            'contact_person'    => 'nullable|string|max:100',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:255',
            'postal_code'       => 'nullable|string|max:10',
            'address'           => 'nullable|string|max:500',
            'notes'             => 'nullable|string|max:5000',
        ]);

        $customer->update($validated);

        return redirect()
            ->route('tenant.customers.show', $customer)
            ->with('success', "顧客「{$customer->code} {$customer->name}」を更新しました。");
    }

    /**
     * 顧客削除（ソフトデリート）
     * Route: DELETE /tenant/customers/{customer}
     */
    public function destroy(Customer $customer)
    {
        // 契約が紐づいている場合は削除拒否
        if ($customer->hasContracts()) {
            return back()->with('error', 'この顧客には契約履歴があるため削除できません。');
        }

        $customer->delete();

        return redirect()->route('tenant.customers.index')
            ->with('success', "顧客「{$customer->code} {$customer->name}」を削除しました。");
    }

    /**
     * 顧客Ajax検索（契約登録・問合せ登録画面用）
     * Route: GET /api/tenant/customers/search
     */
    public function search(Request $request)
    {
        $q = $request->input('q', '');

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $customers = Customer::where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('name_kana', 'like', "%{$q}%")
                      ->orWhere('code', 'like', "%{$q}%");
            })
            ->orderBy('code')
            ->limit(20)
            ->get(['id', 'code', 'name', 'customer_type']);

        return response()->json(
            $customers->map(function ($c) {
                return [
                    'id'         => $c->id,
                    'code'       => $c->code,
                    'name'       => $c->name,
                    'type'       => $c->customer_type->value,
                    'type_label' => $c->customer_type->label(),
                ];
            })
        );
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 顧客コードの自動採番: CUS-NNN
     * withTrashed() でソフトデリート済みも含めて最大番号を取得
     */
    private function generateCustomerCode(): string
    {
        $prefix = 'CUS-';

        $lastCode = Customer::withTrashed()
            ->where('code', 'like', "{$prefix}%")
            ->orderByDesc('code')
            ->value('code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }
}
