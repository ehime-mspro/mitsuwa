<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsContractStatus;
use App\Enums\MsParkingStatus;
use App\Http\Controllers\Controller;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsParkingContractRevision;
use App\Models\MsProperty;
use App\Models\MsTenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 賃貸マンション駐車場契約コントローラー。
 * 駐車場契約（MsParkingContract）は:
 *   - 部屋契約に紐付く（contract_id あり、部屋契約 store/terminate で連動管理）
 *   - 単独契約（contract_id null、parking_only 入居者向けなど）
 * の2形態がある。本コントローラーは主に単独契約の CRUD + 料金改定 + 解約処理を担当する。
 * 契約中は parking.status = occupied、解約時は vacant に連動する。
 */
class ParkingContractController extends Controller
{
    /**
     * 駐車場契約一覧（物件・リンク種別・ステータス・年度でフィルター）。
     * 年度は 5 月始まりで contract_date ベースで判定。
     */
    public function index(Request $request)
    {
        $query = MsParkingContract::with(['parking.property', 'tenant', 'contract.room']);

        // 物件フィルター（parking.property_id 経由）
        if ($request->filled('property_id')) {
            $query->whereHas('parking', fn ($q) => $q->where('property_id', $request->property_id));
        }

        // リンク種別フィルター（linked: 部屋契約紐付き / standalone: 単独契約）
        if ($request->filled('link_type')) {
            if ($request->link_type === 'linked') {
                $query->whereNotNull('contract_id');
            } elseif ($request->link_type === 'standalone') {
                $query->whereNull('contract_id');
            }
        }

        // ステータスフィルター（active / terminated）
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 年度フィルター（5 月始まり）
        if ($request->filled('fiscal_year')) {
            $start = "{$request->fiscal_year}-05-01";
            $end = ($request->fiscal_year + 1) . '-04-30';
            $query->whereBetween('contract_date', [$start, $end]);
        }

        $contracts = $query->orderByDesc('contract_date')->paginate(20)->withQueryString();

        return view('mansion.parking-contracts.index', [
            'contracts' => $contracts,
            'properties' => MsProperty::orderBy('property_code')->get(),
        ]);
    }

    /**
     * 駐車場契約詳細。駐車場・物件・入居者・部屋契約・担当者・改定履歴をまとめてロード。
     */
    public function show(MsParkingContract $parkingContract)
    {
        $parkingContract->load(['parking.property', 'tenant', 'contract.room', 'staff', 'revisions']);

        return view('mansion.parking-contracts.show', compact('parkingContract'));
    }

    /**
     * 駐車場契約登録画面。物件ドロップダウン → Ajax で空き駐車場を取得する想定。
     * 駐車場詳細から遷移した場合は preselectedParkingId を渡す。
     */
    public function create(Request $request)
    {
        return view('mansion.parking-contracts.create', [
            'properties'           => MsProperty::orderBy('property_code')->get(),
            'tenants'              => MsTenant::orderBy('name')->get(),
            'staffUsers'           => User::assignable()->orderBy('name')->get(),
            'preselectedParkingId' => $request->parking_id,
        ]);
    }

    /**
     * 駐車場契約登録処理。
     * 単独契約（contract_id = null）として作成し、駐車場ステータスを occupied に更新する。
     */
    public function store(Request $request)
    {
        $validated = $this->validateInput($request, skipParkingTenant: false);
        $validated['status']     = 'active';
        $validated['contract_id'] = null; // 単独契約
        $validated['created_by'] = Auth::id();

        $parkingContract = null;

        DB::transaction(function () use ($validated, &$parkingContract) {
            $parkingContract = MsParkingContract::create($validated);

            // 駐車場ステータスを使用中に更新
            MsParking::where('id', $validated['parking_id'])
                ->update(['status' => MsParkingStatus::Occupied->value]);
        });

        return redirect()->route('mansion.parking-contracts.show', $parkingContract)
            ->with('success', '駐車場契約を登録しました');
    }

    /**
     * 駐車場契約編集画面。解約済み契約は編集不可で詳細へリダイレクト。
     */
    public function edit(MsParkingContract $parkingContract)
    {
        if ($parkingContract->isTerminated()) {
            return redirect()->route('mansion.parking-contracts.show', $parkingContract)
                ->with('error', '解約済みの契約は編集できません');
        }

        return view('mansion.parking-contracts.edit', [
            'parkingContract' => $parkingContract,
            'tenants'         => MsTenant::orderBy('name')->get(),
            'staffUsers'      => User::assignableWith($parkingContract->staff_user_id),
        ]);
    }

    /**
     * 駐車場契約更新処理。解約済みは更新不可。
     * 駐車場の付け替えは許可しないため parking_id は必須から外す。
     */
    public function update(Request $request, MsParkingContract $parkingContract)
    {
        if ($parkingContract->isTerminated()) {
            return back()->with('error', '解約済みの契約は編集できません');
        }

        $validated = $this->validateInput($request, skipParkingTenant: true);
        $validated['updated_by'] = Auth::id();
        $parkingContract->update($validated);

        return redirect()->route('mansion.parking-contracts.show', $parkingContract)
            ->with('success', '契約を更新しました');
    }

    /**
     * 料金改定画面。解約済みは 403。
     */
    public function showRevise(MsParkingContract $parkingContract)
    {
        if ($parkingContract->isTerminated()) {
            abort(403);
        }

        return view('mansion.parking-contracts.revise', compact('parkingContract'));
    }

    /**
     * 料金改定処理。改定履歴（MsParkingContractRevision）を作成し、
     * 契約本体の monthly_fee も同時に更新する。
     */
    public function revise(Request $request, MsParkingContract $parkingContract)
    {
        if ($parkingContract->isTerminated()) {
            abort(403);
        }

        $validated = $request->validate([
            'revision_date'   => 'required|date',
            'new_monthly_fee' => 'required|integer|min:0',
            'reason'          => 'nullable|string|max:200',
        ]);

        DB::transaction(function () use ($validated, $parkingContract) {
            MsParkingContractRevision::create([
                'parking_contract_id' => $parkingContract->id,
                'revision_date'       => $validated['revision_date'],
                'new_monthly_fee'     => $validated['new_monthly_fee'],
                'reason'              => $validated['reason'] ?? null,
                'created_by'          => Auth::id(),
            ]);
            $parkingContract->update([
                'monthly_fee' => $validated['new_monthly_fee'],
                'updated_by'  => Auth::id(),
            ]);
        });

        return redirect()->route('mansion.parking-contracts.show', $parkingContract)
            ->with('success', '料金を改定しました');
    }

    /**
     * 解約画面。解約済みは 403。
     */
    public function showTerminate(MsParkingContract $parkingContract)
    {
        if ($parkingContract->isTerminated()) {
            abort(403);
        }

        return view('mansion.parking-contracts.terminate', compact('parkingContract'));
    }

    /**
     * 解約処理。契約を terminated に更新し、駐車場ステータスを vacant に戻す。
     */
    public function terminate(Request $request, MsParkingContract $parkingContract)
    {
        if ($parkingContract->isTerminated()) {
            abort(403);
        }

        $validated = $request->validate([
            'end_date' => 'required|date',
        ]);

        DB::transaction(function () use ($validated, $parkingContract) {
            $parkingContract->update([
                'status'     => MsContractStatus::Terminated->value,
                'end_date'   => $validated['end_date'],
                'updated_by' => Auth::id(),
            ]);
            $parkingContract->parking->update(['status' => MsParkingStatus::Vacant->value]);
        });

        return redirect()->route('mansion.parking-contracts.show', $parkingContract)
            ->with('success', '駐車場契約を解約しました');
    }

    /**
     * 登録・更新共通バリデーション。
     * $skipParkingTenant = true の場合は parking_id を必須から外す（更新時の駐車場付け替えを禁止）。
     * 更新時も tenant_id の変更は許可する。
     *
     * @param  \Illuminate\Http\Request $request
     * @param  bool                     $skipParkingTenant
     * @return array バリデーション済みデータ
     */
    private function validateInput(Request $request, bool $skipParkingTenant = false): array
    {
        $rules = [
            'contract_date'  => 'nullable|date',
            'start_date'     => 'nullable|date',
            'monthly_fee'    => 'required|integer|min:0',
            'deposit'        => 'nullable|integer|min:0',
            'staff_user_id'  => 'nullable|exists:users,id',
            'memo'           => 'nullable|string',
        ];

        if (!$skipParkingTenant) {
            // 新規登録時: 駐車場 ID と入居者 ID は必須
            $rules['parking_id'] = 'required|exists:ms_parkings,id';
            $rules['tenant_id']  = 'required|exists:ms_tenants,id';
        } else {
            // 更新時: 駐車場の付け替えは禁止、入居者の変更は許可
            $rules['tenant_id'] = 'required|exists:ms_tenants,id';
        }

        return $request->validate($rules, [], [
            // 画面ラベルに合わせる（既定は「開始日」）
            'start_date' => '利用開始日',
        ]);
    }
}
