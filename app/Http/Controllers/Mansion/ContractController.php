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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 賃貸マンション部屋契約コントローラー。
 * 部屋契約（MsContract）は入居者（resident）と部屋（MsRoom）を紐付け、
 * 任意で駐車場契約（MsParkingContract）を一括作成/解約する。
 * 契約中は room.status = occupied、解約時は vacant に連動する。
 */
class ContractController extends Controller
{
    /** 登録で選んだ部屋が、もう空室・申込み中でない（二重の送信・ほかの人が先に契約した） */
    private const ROOM_TAKEN = '選んだ部屋は空室・申込み中ではありません（すでに契約されている可能性があります）。';

    /** 登録で選んだ駐車場が、もう空きでないか、部屋と別の物件の駐車場 */
    private const PARKING_TAKEN = '選んだ駐車場は空きではないか、部屋と別の物件の駐車場です。';

    /**
     * 契約一覧（物件・ステータス・年度でフィルター）。
     * 年度は 5 月始まりで contract_date ベースで判定。
     */
    public function index(Request $request)
    {
        $this->ignoreMalformedQuery($request, ['status'], ['property_id', 'fiscal_year']);
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
        $this->ignoreMalformedQuery($request, [], ['room_id']);

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
     *
     * ⚠ 部屋が空室・申込み中か、駐車場が空きで部屋と同じ物件かを確かめる。確かめないと、登録を 2 回送る
     *   （ダブルクリック・戻って押し直し）だけで同じ部屋に契約中の契約が 2 件できる。入力チェックのあと、
     *   トランザクションの中で部屋と駐車場の行をロックして確かめ直す（ほぼ同時の 2 回は、どちらも入力チェックを通るため）。
     */
    public function store(Request $request)
    {
        $validated = $this->validateInput($request);
        $parkingIds = array_map('intval', $validated['parking_ids'] ?? []);
        unset($validated['parking_ids']);
        $validated['status'] = 'active';
        $validated['created_by'] = Auth::id();

        $contract = null;

        DB::transaction(function () use ($validated, $parkingIds, &$contract) {
            $room = MsRoom::whereKey($validated['room_id'])->lockForUpdate()->first();
            if (! $room || ! in_array($room->status, [MsRoomStatus::Vacant, MsRoomStatus::Negotiating], true)) {
                throw ValidationException::withMessages(['room_id' => self::ROOM_TAKEN]);
            }
            $parkings = MsParking::whereIn('id', $parkingIds)->lockForUpdate()->get();
            $usable = $parkings->filter(fn (MsParking $p) => $p->status === MsParkingStatus::Vacant && (int) $p->property_id === (int) $room->property_id);
            if ($usable->count() !== count(array_unique($parkingIds))) {
                throw ValidationException::withMessages(['parking_ids' => self::PARKING_TAKEN]);
            }

            $contract = MsContract::create($validated);

            // 部屋ステータスを入居中に更新
            $room->update(['status' => MsRoomStatus::Occupied->value]);

            // 駐車場紐付け（選択分のみ契約作成 + 使用中に更新）
            foreach ($usable as $parking) {
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
            'staffUsers' => User::assignableWith($contract->staff_user_id),
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
        // 解約済み（ダブルクリックの 2 回目・開いたままの古い画面）は詳細へ戻して理由を出す（403 の英語の画面にしない）
        if ($contract->isTerminated()) {
            return redirect()->route('mansion.contracts.show', $contract)->with('error', 'この契約は解約済みのため、賃料を改定できません。');
        }

        $validated = $request->validate([
            'revision_date' => 'required|date',
            'new_rent' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'new_common_fee' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'reason' => 'nullable|string|max:200',
        ], [], [
            // 画面ラベルに合わせる（既定は「改定適用日」「新・月額家賃」「新・共益費」）
            'revision_date' => '改定日',
            'new_rent' => '新賃料',
            'new_common_fee' => '新共益費',
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
        // 解約済み（ダブルクリックの 2 回目・開いたままの古い画面）は詳細へ戻して理由を出す（403 の英語の画面にしない）
        if ($contract->isTerminated()) {
            return redirect()->route('mansion.contracts.show', $contract)->with('error', 'この契約はすでに解約済みです。');
        }

        // 退去日は入居日（無ければ契約日）より前にしない（Bug #85 と同じ）
        $since = $contract->move_in_date ?? $contract->contract_date;
        $sinceLabel = $contract->move_in_date ? '入居日' : '契約日';

        $validated = $request->validate([
            'move_out_date' => $since ? 'required|date|after_or_equal:' . $since->format('Y-m-d') : 'required|date',
            'terminate_parkings' => 'nullable|array',
            // 敷金精算。⚠ 画面には以前からこの入力欄があったが、ここで受けていなかったため
            //   入力が丸ごと捨てられていた（2026-08-17 に発見・修正）
            'termination_reason' => 'nullable|string|max:200',
            'restoration_cost' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'cleaning_cost' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'other_deduction_name' => 'nullable|array',
            'other_deduction_name.*' => 'nullable|string|max:100',
            'other_deduction_amount' => 'nullable|array',
            'other_deduction_amount.*' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
        ], [
            'move_out_date.after_or_equal' => $since ? "退去日は{$sinceLabel}（{$since->format('Y/m/d')}）以降の日付を指定してください。" : '',
        ], [
            // 画面ラベルに合わせる（既定は「退去理由」）
            'termination_reason' => '解約理由',
        ]);

        $deductions = $this->pairDeductions($request);

        DB::transaction(function () use ($validated, $contract, $deductions) {
            $contract->update([
                'status' => MsContractStatus::Terminated->value,
                'move_out_date' => $validated['move_out_date'],
                // ⚠ `?? 0` にしない。空欄は null のまま保存して「未入力」と「0 円」を区別する
                //   （ConvertEmptyStringsToNull により空欄は null で届く）
                'termination_reason' => $validated['termination_reason'] ?? null,
                'restoration_cost' => $validated['restoration_cost'] ?? null,
                'cleaning_cost' => $validated['cleaning_cost'] ?? null,
                // ⚠ 精算時点の敷金をスナップショットする。deposit は解約後も編集でき、
                //   書き換えられると返金額の根拠が動いてしまう
                'deposit_at_settlement' => $contract->deposit,
                'updated_by' => Auth::id(),
            ]);

            foreach ($deductions as $i => $d) {
                $contract->deductions()->create([
                    'name' => $d['name'],
                    'amount' => $d['amount'],
                    'sort_order' => $i,
                ]);
            }

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
     * 敷金精算の「その他差引項目」を、名称と金額の**並行配列**から組にする。
     *
     * 画面は `other_deduction_name[]` と `other_deduction_amount[]` を別々に送る。
     *
     * ⚠ **`array_values()` で詰め直してはいけない。** Alpine 側は
     *   `otherDeductions.splice(idx, 1)` で行を消すので、送られてくる添字は
     *   name 側と amount 側で**同じ位置が同じ行**を指す。片方だけ詰め直すと
     *   名称と金額が別の行どうしで組になり、無音で取り違える。
     *
     * ⚠ 名称と金額の**両方が揃った行だけ**保存する（入力途中の空行を弾く）。
     *   捨てたことは画面に出さない — 利用者は空行を「無い行」と認識しているため。
     *
     * @return list<array{name: string, amount: int}>
     */
    private function pairDeductions(Request $request): array
    {
        $names   = $request->input('other_deduction_name', []);
        $amounts = $request->input('other_deduction_amount', []);

        if (! is_array($names) || ! is_array($amounts)) {
            return [];
        }

        $rows = [];
        foreach ($names as $i => $name) {
            $name   = is_string($name) ? trim($name) : '';
            $amount = $amounts[$i] ?? null;

            if ($name === '' || $amount === null || $amount === '') {
                continue;
            }

            $rows[] = ['name' => $name, 'amount' => (int) $amount];
        }

        return $rows;
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
            'rent' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'common_fee' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'deposit' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'key_money' => 'nullable|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'staff_user_id' => 'nullable|exists:users,id',
            'memo' => 'nullable|string',
        ];
        $messages = [];
        if (!$skipRoomTenant) {
            // 選べるのは空室・申込み中の部屋と空きの駐車場だけ（登録画面の API と同じ）。部屋と同じ物件かは store() のロックの中で見る
            $rules['room_id'] = ['required', Rule::exists('ms_rooms', 'id')->whereIn('status', [MsRoomStatus::Vacant->value, MsRoomStatus::Negotiating->value])];
            $rules['tenant_id'] = 'required|exists:ms_tenants,id';
            $rules['parking_ids'] = 'nullable|array';
            $rules['parking_ids.*'] = ['integer', Rule::exists('ms_parkings', 'id')->where('status', MsParkingStatus::Vacant->value)];
            $messages = ['room_id.exists' => self::ROOM_TAKEN, 'parking_ids.*.exists' => self::PARKING_TAKEN, 'parking_ids.*.integer' => self::PARKING_TAKEN];
        } else {
            $rules['tenant_id'] = 'required|exists:ms_tenants,id';
        }

        return $request->validate($rules, $messages);
    }
}
