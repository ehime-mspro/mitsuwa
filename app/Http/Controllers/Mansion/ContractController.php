<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsContractStatus;
use App\Enums\MsParkingStatus;
use App\Enums\MsRoomStatus;
use App\Http\Controllers\Controller;
use App\Models\MsContract;
use App\Models\MsContractRevision;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 賃貸マンション部屋契約コントローラー。
 * 部屋契約（MsContract）は入居者（resident）と部屋（MsRoom）を紐付け、
 * 任意で駐車場契約（MsParkingContract）を一括作成/解約する。
 * 契約中は room.status = occupied、解約時は vacant に連動する。
 */
class ContractController extends Controller
{
    /**
     * 契約一覧（物件・ステータス・年度でフィルター）。
     * 年度は 5 月始まりで contract_date ベースで判定。
     */
    public function index(Request $request)
    {
        $query = MsContract::with(['room.property', 'tenant', 'parkingContracts']);

        // 物件フィルター（room.property_id 経由）
        if ($request->filled('property_id')) {
            $query->whereHas('room', fn ($q) => $q->where('property_id', $request->property_id));
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

        return view('mansion.contracts.index', [
            'contracts' => $contracts,
            'properties' => MsProperty::orderBy('property_code')->get(),
        ]);
    }

    /**
     * 契約詳細。部屋・入居者・担当者・駐車場契約・改定履歴をまとめてロード。
     */
    public function show(MsContract $contract)
    {
        $contract->load(['room.property', 'tenant', 'staff', 'parkingContracts.parking', 'revisions']);

        return view('mansion.contracts.show', compact('contract'));
    }

    /**
     * 契約登録画面。物件ドロップダウン → Ajax で空室・空き駐車場を取得する想定。
     * 部屋詳細から遷移した場合は preselectedRoomId を渡す。
     */
    public function create(Request $request)
    {
        return view('mansion.contracts.create', [
            'properties' => MsProperty::orderBy('property_code')->get(),
            'tenants' => MsTenant::where('tenant_type', 'resident')->orderBy('name')->get(),
            'staffUsers' => User::assignable()->orderBy('name')->get(),
            'preselectedRoomId' => $request->room_id,
        ]);
    }

    /**
     * 契約登録処理。部屋を occupied に更新し、チェックされた駐車場は
     * 駐車場契約を自動作成 + 該当駐車場を occupied に連動させる。
     */
    public function store(Request $request)
    {
        $validated = $this->validateInput($request);
        $validated['status'] = 'active';
        $validated['created_by'] = Auth::id();

        $parkingIds = $request->input('parking_ids', []);
        $contract = null;

        DB::transaction(function () use ($validated, $parkingIds, &$contract) {
            $contract = MsContract::create($validated);

            // 部屋ステータスを入居中に更新
            MsRoom::where('id', $validated['room_id'])
                ->update(['status' => MsRoomStatus::Occupied->value]);

            // 駐車場紐付け（選択分のみ契約作成 + 使用中に更新）
            foreach ($parkingIds as $parkingId) {
                $parking = MsParking::findOrFail($parkingId);
                MsParkingContract::create([
                    'parking_id' => $parking->id,
                    'tenant_id' => $contract->tenant_id,
                    'contract_id' => $contract->id,
                    'status' => 'active',
                    'contract_date' => $contract->contract_date,
                    'start_date' => $contract->move_in_date,
                    'monthly_fee' => $parking->monthly_fee,
                    'staff_user_id' => $contract->staff_user_id,
                    'created_by' => Auth::id(),
                ]);
                $parking->update(['status' => MsParkingStatus::Occupied->value]);
            }
        });

        return redirect()->route('mansion.contracts.show', $contract)
            ->with('success', '部屋契約を登録しました');
    }

    /**
     * 契約編集画面。解約済み契約は編集不可で詳細へリダイレクト。
     */
    public function edit(MsContract $contract)
    {
        if ($contract->isTerminated()) {
            return redirect()->route('mansion.contracts.show', $contract)
                ->with('error', '解約済みの契約は編集できません');
        }

        return view('mansion.contracts.edit', [
            'contract' => $contract,
            'tenants' => MsTenant::where('tenant_type', 'resident')->orderBy('name')->get(),
            'staffUsers' => User::orderBy('name')->get(),
        ]);
    }

    /**
     * 契約更新処理。解約済みは更新不可。
     * 部屋・入居者の切り替えは想定しないため room_id は必須から外す。
     */
    public function update(Request $request, MsContract $contract)
    {
        if ($contract->isTerminated()) {
            return back()->with('error', '解約済みの契約は編集できません');
        }

        $validated = $this->validateInput($request, true);
        $validated['updated_by'] = Auth::id();
        $contract->update($validated);

        return redirect()->route('mansion.contracts.show', $contract)
            ->with('success', '契約を更新しました');
    }

    /**
     * 賃料改定画面。解約済みは 403。
     */
    public function showRevise(MsContract $contract)
    {
        if ($contract->isTerminated()) {
            abort(403);
        }

        return view('mansion.contracts.revise', compact('contract'));
    }

    /**
     * 賃料改定処理。改定履歴（MsContractRevision）を作成し、
     * 契約本体の rent / common_fee も同時に更新。未入力は現行値を維持。
     */
    public function revise(Request $request, MsContract $contract)
    {
        if ($contract->isTerminated()) {
            abort(403);
        }

        $validated = $request->validate([
            'revision_date' => 'required|date',
            'new_rent' => 'nullable|integer|min:0',
            'new_common_fee' => 'nullable|integer|min:0',
            'reason' => 'nullable|string|max:200',
        ]);

        DB::transaction(function () use ($validated, $contract) {
            MsContractRevision::create([
                'contract_id' => $contract->id,
                'revision_date' => $validated['revision_date'],
                'new_rent' => $validated['new_rent'] ?? $contract->rent,
                'new_common_fee' => $validated['new_common_fee'] ?? $contract->common_fee,
                'reason' => $validated['reason'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $contract->update([
                'rent' => $validated['new_rent'] ?? $contract->rent,
                'common_fee' => $validated['new_common_fee'] ?? $contract->common_fee,
                'updated_by' => Auth::id(),
            ]);
        });

        return redirect()->route('mansion.contracts.show', $contract)
            ->with('success', '賃料を改定しました');
    }

    /**
     * 解約画面。紐付く駐車場契約を一括解約対象として選択させる。
     */
    public function showTerminate(MsContract $contract)
    {
        if ($contract->isTerminated()) {
            abort(403);
        }
        $contract->load('parkingContracts.parking');

        return view('mansion.contracts.terminate', compact('contract'));
    }

    /**
     * 解約処理。契約を terminated + move_out_date 設定、
     * 部屋は vacant に戻す。チェックされた駐車場契約のみ一括解約 + 駐車場を空きへ。
     */
    public function terminate(Request $request, MsContract $contract)
    {
        if ($contract->isTerminated()) {
            abort(403);
        }

        $validated = $request->validate([
            'move_out_date' => 'required|date',
            'terminate_parkings' => 'nullable|array',
        ]);

        DB::transaction(function () use ($validated, $contract) {
            $contract->update([
                'status' => MsContractStatus::Terminated->value,
                'move_out_date' => $validated['move_out_date'],
                'updated_by' => Auth::id(),
            ]);
            $contract->room->update(['status' => MsRoomStatus::Vacant->value]);

            // 紐付く駐車場契約の一括解約（チェックされたもののみ）
            $parkingIdsToTerminate = $validated['terminate_parkings'] ?? [];
            foreach ($contract->parkingContracts as $pc) {
                if (in_array($pc->id, $parkingIdsToTerminate)) {
                    $pc->update([
                        'status' => MsContractStatus::Terminated->value,
                        'end_date' => $validated['move_out_date'],
                        'updated_by' => Auth::id(),
                    ]);
                    $pc->parking->update(['status' => MsParkingStatus::Vacant->value]);
                }
            }
        });

        return redirect()->route('mansion.contracts.show', $contract)
            ->with('success', '契約を解約しました');
    }

    /**
     * Ajax: 指定物件の空室・申込み中の部屋を返す。
     * 契約登録画面の物件セレクト onchange で呼び出される想定。
     */
    public function vacantRooms(MsProperty $property)
    {
        $rooms = $property->rooms()
            ->whereIn('status', ['vacant', 'negotiating'])
            ->orderBy('room_number')
            ->get(['id', 'room_number', 'room_type', 'rent', 'common_fee', 'deposit', 'key_money', 'status']);

        return response()->json($rooms);
    }

    /**
     * Ajax: 指定物件の空き駐車場を返す。
     * 契約登録画面の駐車場一括紐付けチェックボックス描画に利用。
     */
    public function vacantParkings(MsProperty $property)
    {
        $parkings = $property->parkings()
            ->where('status', 'vacant')
            ->orderBy('parking_number')
            ->get(['id', 'parking_number', 'monthly_fee', 'has_roof']);

        return response()->json($parkings);
    }

    /**
     * 登録・更新共通バリデーション。
     * $skipRoomTenant = true の場合は部屋 ID を必須から外す（更新時の部屋付け替えを許可しない）。
     */
    private function validateInput(Request $request, bool $skipRoomTenant = false): array
    {
        $rules = [
            'contract_date' => 'nullable|date',
            'move_in_date' => 'nullable|date',
            'rent' => 'nullable|integer|min:0',
            'common_fee' => 'nullable|integer|min:0',
            'deposit' => 'nullable|integer|min:0',
            'key_money' => 'nullable|integer|min:0',
            'staff_user_id' => 'nullable|exists:users,id',
            'memo' => 'nullable|string',
        ];
        if (!$skipRoomTenant) {
            $rules['room_id'] = 'required|exists:ms_rooms,id';
            $rules['tenant_id'] = 'required|exists:ms_tenants,id';
        } else {
            $rules['tenant_id'] = 'required|exists:ms_tenants,id';
        }

        return $request->validate($rules);
    }
}
