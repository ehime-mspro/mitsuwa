<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsTenantType;
use App\Http\Controllers\Controller;
use App\Models\MsTenant;
use Illuminate\Http\Request;

/**
 * 賃貸マンション入居者管理コントローラー。
 * 入居者は resident（居住＋駐車場）/ parking_only（駐車場のみ）の 2 区分で、
 * 部屋契約（MsContract）・駐車場契約（MsParkingContract）から参照される。
 * 入居申込書は Attachment ポリモーフィックで attachable_type='App\Models\MsTenant' として保持。
 */
class TenantController extends Controller
{
    /**
     * 入居者一覧（区分・キーワードでフィルター可能）。
     */
    public function index(Request $request)
    {
        $query = MsTenant::query();

        // 利用者区分フィルター
        if ($request->filled('tenant_type')) {
            $query->where('tenant_type', $request->tenant_type);
        }
        // キーワード検索（氏名部分一致）
        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }

        // 一覧で紐付け先（部屋・駐車場）を表示するため有効契約を eager load
        $query->with([
            'activeContract.room.property',
            'activeParkingContracts.parking.property',
        ]);

        $tenants = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('mansion.tenants.index', [
            'tenants' => $tenants,
            'tenantTypes' => MsTenantType::cases(),
        ]);
    }

    /**
     * 入居者登録画面。
     */
    public function create()
    {
        return view('mansion.tenants.create', [
            'tenantTypes' => MsTenantType::cases(),
        ]);
    }

    /**
     * 入居者登録処理。登録後は詳細画面へ遷移。
     */
    public function store(Request $request)
    {
        $validated = $this->validateInput($request);
        $tenant = MsTenant::create($validated);

        return redirect()->route('mansion.tenants.show', $tenant)
            ->with('success', '入居者を登録しました');
    }

    /**
     * 入居者詳細。現契約の部屋・駐車場をまとめて表示。
     * 添付ファイルは showApplication 画面で別途ロード。
     */
    public function show(MsTenant $tenant)
    {
        $tenant->load([
            'activeContract.room.property',
            'activeParkingContracts.parking.property',
            'activeParkingContracts.contract.room',
        ]);

        return view('mansion.tenants.show', compact('tenant'));
    }

    /**
     * 入居者編集画面。
     */
    public function edit(MsTenant $tenant)
    {
        return view('mansion.tenants.edit', [
            'tenant' => $tenant,
            'tenantTypes' => MsTenantType::cases(),
        ]);
    }

    /**
     * 入居者更新処理。
     */
    public function update(Request $request, MsTenant $tenant)
    {
        $tenant->update($this->validateInput($request));

        return redirect()->route('mansion.tenants.show', $tenant)
            ->with('success', '入居者を更新しました');
    }

    /**
     * 入居者削除。有効契約が残っていれば FK RESTRICT で失敗する。
     */
    public function destroy(MsTenant $tenant)
    {
        $tenant->delete();

        return redirect()->route('mansion.tenants.index')
            ->with('success', '入居者を削除しました');
    }

    /**
     * 入居申込書アップロード画面。
     * 実際のアップロード・削除は /attachments/ms_tenants/{id} Ajax エンドポイントを利用。
     */
    public function showApplication(MsTenant $tenant)
    {
        $tenant->load(['attachments.uploadedByUser', 'deletedAttachments.deletedByUser']);

        return view('mansion.tenants.application', compact('tenant'));
    }

    /**
     * 登録・更新共通バリデーション。
     * tenant_type は必須、氏名は必須、連絡先・緊急連絡先は任意。
     */
    private function validateInput(Request $request): array
    {
        return $request->validate([
            'tenant_type' => 'required|in:resident,parking_only',
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'workplace' => 'nullable|string|max:100',
            'emergency_contact_name' => 'nullable|string|max:100',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'emergency_contact_relation' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);
    }
}
