<?php

namespace App\Http\Controllers\Housing;

use App\Enums\CustomOrderFileCategory;
use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\HsCustomOrder;
use App\Models\HsCustomOrderFile;
use App\Models\ReProcurementLot;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Support\AttachmentDelivery;
use App\Support\HousingLandCost;
use App\Support\HousingProcurementLots;
use App\Support\JapanTime;
use App\Support\ProcurementLotClaims;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustomOrderController extends Controller
{
    // ================================================================
    // 注文住宅案件 CRUD（7ルート）
    // ================================================================

    /**
     * 一覧
     * GET /housing/custom-orders
     */
    public function index(Request $request)
    {
        $query = HsCustomOrder::with(['projectLot.project', 'procurement']);

        // フィルター: ステータス（デフォルトは全て）
        $statusFilter = $request->input('status', '');

        // 「全て」= status='' は ConvertEmptyStringsToNull で null 化されるため
        // filled() で弾き、フィルタ無し（＝全件）に落とす。'' 比較では null が
        // 素通りして where('status', null) となり 0 件になる（Bug 回避）。
        if (filled($statusFilter)) {
            $query->where('status', $statusFilter);
        }

        // フィルター: キーワード
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('order_code', 'like', "%{$keyword}%")
                  ->orWhere('order_name', 'like', "%{$keyword}%")
                  ->orWhere('customer_name', 'like', "%{$keyword}%")
                  ->orWhere('address', 'like', "%{$keyword}%");
            });
        }

        $orders = $query->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('housing.custom-orders.index', compact('orders'));
    }

    /**
     * 登録フォーム
     * GET /housing/custom-orders/create
     */
    public function create()
    {
        $projectsForJs = $this->getProjectsForJs();
        // 仕入れ案件一覧（決済完了以降。全区画がほかに取られた土地は出さない。Ruling 8）
        $procurementsForJs = HousingProcurementLots::formOptions(null);
        $defaultTaxRate = $this->getDefaultTaxRate();
        $buyers = $this->selectableBuyers(null);

        // 入力エラーで戻った画面は、選んでいた区画の分譲地と区画の一覧をサーバで描く（H2）
        $lotSelection = $this->initialLotSelection(old('re_project_lot_id'));
        // 土地の区画も同じ（決めたこと 8）。一覧に無くなった区画（取られた区画で断られた）は選ばず、その按分も出さない（Ruling 11）
        $procurementLotSelection = HousingProcurementLots::initialSelection(old('re_procurement_id'), old('re_procurement_lot_id'), null);
        $computedLandCost = $this->computedLandCost(null, $procurementLotSelection['lotId']);

        return view('housing.custom-orders.create', compact('projectsForJs', 'procurementsForJs', 'defaultTaxRate', 'buyers', 'lotSelection', 'procurementLotSelection', 'computedLandCost'));
    }

    /**
     * 保存
     * POST /housing/custom-orders
     */
    public function store(Request $request)
    {
        // フェーズ2: 買主マスタ紐付けを必須化し、customer_name を買主名で上書き（連携漏れ防止。customer_id は validateOrder() が見る）
        $validated = $this->validateOrder($request);
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;

        $validated['order_code'] = $this->generateOrderCode();
        $validated['created_by'] = auth()->id();

        $this->setLandSourceForeignKeys($validated);
        $this->clearLandFieldsForCustomerLand($validated);
        $this->applyAutomaticLandCost($validated, $request, null);

        $order = HsCustomOrder::create($validated);

        // 区画ステータス連動
        $this->syncLotStatus($order, null);

        return redirect()
            ->route('housing.custom-orders.show', $order)
            ->with('success', "注文住宅案件「{$order->order_code}」を登録しました。");
    }

    /**
     * 詳細
     * GET /housing/custom-orders/{customOrder}
     */
    public function show(Request $request, HsCustomOrder $customOrder)
    {
        $customOrder->load([
            'projectLot.project',
            'procurement',
            'procurementLot',
            'files.uploadedByUser',
            'createdBy',
            'updatedBy',
        ]);

        // ファイルを種別ごとに分類（@json用に事前整形）
        $filesByCategory = [];
        foreach (CustomOrderFileCategory::cases() as $cat) {
            $filesByCategory[$cat->value] = [];
        }
        foreach ($customOrder->files as $file) {
            $filesByCategory[$file->category->value][] = [
                'id'          => $file->id,
                'file_name'   => $file->file_name,
                // 本番ではシンボリックリンクが効かないため、Laravel ルート経由で配信
                'file_path'   => route('housing.custom-orders.files.show', ['customOrder' => $customOrder->id, 'file' => $file->id]),
                'file_size'   => $file->getFileSizeFormatted(),
                'mime_type'   => $file->mime_type,
                'is_image'    => $file->isImage(),
                'uploaded_by' => $file->uploadedByUser->name ?? '',
                'created_at'  => JapanTime::format($file->created_at, 'Y/m/d'),
            ];
        }

                // 工程表（設計書 §4.1）
        $schedule        = app(\App\Services\ScheduleCardService::class)->build($customOrder);
        $scheduleCanEdit = $request->user()->role->isManagerOrAbove();

        return view('housing.custom-orders.show', compact('customOrder', 'filesByCategory',
            'schedule', 'scheduleCanEdit'));
    }

    /**
     * 編集フォーム
     * GET /housing/custom-orders/{customOrder}/edit
     */
    public function edit(HsCustomOrder $customOrder)
    {
        $customOrder->load(['projectLot.project', 'procurement', 'procurementLot']);

        $projectsForJs = $this->getProjectsForJs();
        $procurementsForJs = HousingProcurementLots::formOptions($customOrder);

        // 今の区画（入力エラーで戻ったら選んでいた区画）の分譲地と区画の一覧をサーバで描く（H1: 空から始めると保存で紐付けが消えていた）
        $lotSelection = $this->initialLotSelection(old('re_project_lot_id', $customOrder->re_project_lot_id));
        // 土地の区画も同じ（今の区画は、あとでほかに取られていても残す。決めたこと 4・8。一覧に無い区画は選ばない。Ruling 11）
        $procurementLotSelection = HousingProcurementLots::initialSelection(
            old('re_procurement_id', $customOrder->re_procurement_id), old('re_procurement_lot_id', $customOrder->re_procurement_lot_id), $customOrder);
        $computedLandCost = $this->computedLandCost($customOrder, $procurementLotSelection['lotId']);

        $buyers = $this->selectableBuyers($customOrder);

        return view('housing.custom-orders.edit', compact('customOrder', 'projectsForJs', 'procurementsForJs', 'lotSelection', 'procurementLotSelection', 'computedLandCost', 'buyers'));
    }

    /**
     * 更新
     * PUT /housing/custom-orders/{customOrder}
     */
    public function update(Request $request, HsCustomOrder $customOrder)
    {
        $oldStatus = $customOrder->status;

        $validated = $this->validateOrder($request, current: $customOrder);
        // 登録と同じく買主を必須にし、customer_name を買主名で上書き（判断待ち ⑥。直す前は顧客名だけ変わり買主と食い違った）
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;
        $validated['updated_by'] = auth()->id();

        $this->setLandSourceForeignKeys($validated);
        $this->clearLandFieldsForCustomerLand($validated);
        $this->applyAutomaticLandCost($validated, $request, $customOrder);

        $customOrder->update($validated);

        // 区画ステータス連動
        $this->syncLotStatus($customOrder, $oldStatus);

        return redirect()
            ->route('housing.custom-orders.show', $customOrder)
            ->with('success', "注文住宅案件「{$customOrder->order_code}」を更新しました。");
    }

    /**
     * 削除
     * DELETE /housing/custom-orders/{customOrder}
     */
    public function destroy(HsCustomOrder $customOrder)
    {
        $code = $customOrder->order_code;

        // ファイルの物理削除
        foreach ($customOrder->files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        // 区画ステータスを戻す
        $this->releaseLot($customOrder);

        // 工程は親に完全従属するので一緒に消す（設計書 §3.5）。
        // ⚠ DeletionBlockers には足さない（足すと工程を書いた案件が二度と消せなくなる）。
        // ⚠ morphMany 経由で消すこと。schedulable_id だけで消すと、別テーブルの
        //   同じ id の工程まで巻き添えになる。
        // ⚠ 削除ブロックの判定より**後**に置くこと（ブロックされたのに工程だけ消える事故を防ぐ）。
        $customOrder->scheduleSteps()->delete();

        // ファイルはcascadeOnDeleteで自動削除
        $customOrder->delete();

        return redirect()->route('housing.custom-orders.index')
            ->with('success', "注文住宅案件「{$code}」を削除しました。");
    }

    // ================================================================
    // ステータス変更 Ajax（1ルート）
    // ================================================================

    /**
     * ステータス更新（ステップバーから）
     * PATCH /housing/custom-orders/{customOrder}/status
     */
    public function updateStatus(Request $request, HsCustomOrder $customOrder)
    {
        $statuses = implode(',', array_column(CustomOrderStatus::cases(), 'value'));

        $request->validate([
            'status' => "required|in:{$statuses}",
        ]);

        $oldStatus = $customOrder->status;
        $newStatus = CustomOrderStatus::from($request->status);

        // 「契約」をまたいで進めると土地の区画を取る。区画がほかの契約・建売・契約以降の注文住宅に取られていれば断る（設計書 §5.3）。
        // 「契約」以降どうしの変更（契約 → 着工 など）は区画を取り直さないので見ない（区画の連動 syncLotStatus() と同じ「またぐ」とき）。
        // ⚠ 一覧の小窓は 422 の message をそのまま alert に出す。errors を付けない（付けると同じ文が 2 回出る。Review Focus 2）
        if ($newStatus->isContractedOrLater() && ! $oldStatus->isContractedOrLater()) {
            // 区画のある土地で区画を選んでいない注文住宅は進めない（小窓では区画を選べないので編集画面へ案内する。
            // 設計書 2026-11-22-lot-link-on-add の決まり 7）
            if (ProcurementLotClaims::lotMissing($customOrder)) {
                return response()->json(['success' => false, 'message' => HsCustomOrder::CONTRACT_NEEDS_LOT], 422);
            }
            $refusal = ProcurementLotClaims::refusal($customOrder->re_procurement_lot_id, $customOrder, true);
            if ($refusal !== null) {
                return response()->json(['success' => false, 'message' => $refusal], 422);
            }
        }

        $customOrder->update([
            'status'     => $newStatus->value,
            'updated_by' => auth()->id(),
        ]);

        // 区画ステータス連動
        $this->syncLotStatus($customOrder, $oldStatus);

        return response()->json([
            'success' => true,
            'status'  => $newStatus->value,
            'label'   => $newStatus->label(),
        ]);
    }

    // ================================================================
    // ファイル管理 Ajax（2ルート）
    // ================================================================

    /**
     * ファイルアップロード
     * POST /housing/custom-orders/{customOrder}/files
     */
    public function storeFile(Request $request, HsCustomOrder $customOrder)
    {
        $categories = implode(',', array_column(CustomOrderFileCategory::cases(), 'value'));

        $request->validate([
            'category' => "required|in:{$categories}",
            'file'     => 'required|file|max:7168|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx',
        ]);

        $file = $request->file('file');
        $path = $file->store('custom-order-files/' . $customOrder->id, 'public');

        $record = HsCustomOrderFile::create([
            'custom_order_id' => $customOrder->id,
            'category'        => $request->category,
            'file_name'       => $file->getClientOriginalName(),
            'file_path'       => $path,
            'file_size'       => $file->getSize(),
            'mime_type'       => $file->getClientMimeType(),
            'uploaded_by'     => auth()->id(),
        ]);

        $record->load('uploadedByUser');

        return response()->json([
            'success' => true,
            'file'    => [
                'id'          => $record->id,
                'file_name'   => $record->file_name,
                // 本番ではシンボリックリンクが効かないため、Laravel ルート経由で配信
                'file_path'   => route('housing.custom-orders.files.show', ['customOrder' => $customOrder->id, 'file' => $record->id]),
                'file_size'   => $record->getFileSizeFormatted(),
                'mime_type'   => $record->mime_type,
                'is_image'    => $record->isImage(),
                'uploaded_by' => $record->uploadedByUser->name ?? '',
                'created_at'  => JapanTime::format($record->created_at, 'Y/m/d'),
            ],
        ]);
    }

    /**
     * ファイル削除
     * DELETE /housing/custom-orders/{customOrder}/documents/{file}
     */
    public function destroyFile(HsCustomOrder $customOrder, HsCustomOrderFile $file)
    {
        // 多重防御: ルートは role:executive 限定だが、コントローラ側でも経営層のみに限定する
        abort_unless(auth()->user()->role->isExecutive(), 403);

        if ($file->custom_order_id !== $customOrder->id) {
            return response()->json(['message' => '不正なリクエストです。'], 403);
        }

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return response()->json(['success' => true]);
    }

    /**
     * ファイル閲覧（Laravel 経由でストリーム配信・?download=1 で強制ダウンロード）
     * GET /housing/custom-orders/{customOrder}/documents/{file}
     *
     * 配信方法（inline / 強制DL）の判断は AttachmentDelivery に集約している。
     */
    public function showFile(Request $request, HsCustomOrder $customOrder, HsCustomOrderFile $file)
    {
        if ($file->custom_order_id !== $customOrder->id) {
            abort(403);
        }
        if (! Storage::disk('public')->exists($file->file_path)) {
            abort(404);
        }

        return AttachmentDelivery::make(
            $file->file_path,
            $file->file_name,
            $file->mime_type,
            $request->boolean('download'),
        );
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 買主の選択肢（住宅事業の買主。編集は今の買主も、削除済み・住宅事業にいなくても足す＝保存で黙って別の人に変えない。判断待ち ⑥）。
     * 画面の選択肢と編集の保存の入力チェックが同じ集合を使う（選択肢に出ていない買主を手で送っても通さない）。
     *
     * @return \Illuminate\Support\Collection<int, Buyer>
     */
    private function selectableBuyers(?HsCustomOrder $order): \Illuminate\Support\Collection
    {
        $buyers = Buyer::ofDepartment('housing')->orderBy('last_name_kana')->get();
        if ($order?->customer_id) {
            $current = Buyer::withTrashed()->find($order->customer_id);
            if ($current && !$buyers->contains('id', $current->id)) {
                $buyers->push($current);
            }
        }

        return $buyers->toBase();
    }

    /**
     * バリデーション
     *
     * $current は編集する注文住宅（登録は null）。契約済み（保存済みのステータスが「契約」以降）の土地の紐付けは
     * 変えさせない（判断待ち ④・案 B。区画の販売済はステータスが「契約」をまたぐときしか動かないので、
     * 契約のあとで区画を変えると前の区画が販売済のまま・新しい区画が販売中のまま残る）。
     * ⚠ after のフックで見る（ほかの誤りと一度に出す。Bug #112）。画面は契約済みなら土地を読み取り専用で出す。
     */
    private function validateOrder(Request $request, ?HsCustomOrder $current = null): array
    {
        $statuses = implode(',', array_column(CustomOrderStatus::cases(), 'value'));
        $sourceTypes = implode(',', array_column(HousingLandSourceType::cases(), 'value'));

        // 土地の区画（設計書 §5.3）。土地元が仕入れ案件のときだけ見る（ほかの種別では保存しない＝setLandSourceForeignKeys() が空にする）。
        // ⚠ 入力チェックの前に読むので、数字の文字か整数のときだけ使う（配列などは 0＝区画の無い案件として扱い、入力チェックが断る。Bug #121）
        $isProcurement = $request->input('land_source_type') === HousingLandSourceType::Procurement->value;
        $chosen = $request->input('re_procurement_id');
        $chosenId = is_int($chosen) || (is_string($chosen) && $chosen !== '' && ctype_digit($chosen)) ? (int) $chosen : 0;
        // この保存で区画を取る＝「契約」をまたいで進める（保存済みが「契約」以降なら、今の区画はもう持っている。
        // そのまま保存するだけなら断らない。Bug #130。土地はそのとき読み取り専用で、変えると refusesLandChange() が断る）
        $status = $request->input('status');
        $selfTakes = is_string($status)
            && (CustomOrderStatus::tryFrom($status)?->isContractedOrLater() ?? false)
            && ! ($current?->isLandLockedByContract() ?? false);
        // 区画のある土地では必須（区画の無い保存済みの注文住宅＝#21 の形は、同じ仕入れ案件のままなら選ばずに保存できる。requiresLot()）。
        // ただし「契約」へ進める保存には、その例外を効かせない（区画を選んでいない契約以降の注文住宅を新しく作らない。
        // 設計書 2026-11-22-lot-link-on-add の決まり 7）
        $lotRequired = $isProcurement && ProcurementLotClaims::requiresLot($chosen, $selfTakes ? null : $current);
        $lotNeededToEnterContract = $lotRequired && ! ProcurementLotClaims::requiresLot($chosen, $current);

        return Validator::make($request->all(), [
            // 買主（登録・編集とも必須。判断待ち ⑥）。入力チェックを 1 回にまとめる（2 回に分けると、1 回目で断ったときに 2 回目の欄の配列が控えに残り、戻った画面が 500 になる。Bug #121）
            // 編集は画面の選択肢（住宅事業の買主＋今の買主）だけを受け付ける（手で番号を書き換えた送信で、ほかの部署の買主を紐付けさせない）。
            // ⚠ 登録は前の設計（買主ランクの自動成約 §7.1 #11）で広く受け付けている（HousingContractBuyerRankTest が固定）。変えるなら別の判断
            'customer_id'               => $current === null
                ? ['required', 'integer', 'exists:buyers,id']
                : ['required', 'integer', Rule::in($this->selectableBuyers($current)->pluck('id')->all())],
            'order_name'                => 'required|string|max:100',
            'status'                    => "required|in:{$statuses}",
            'customer_name'             => 'required|string|max:100',
            'land_source_type'          => "nullable|in:{$sourceTypes}",
            // 土地の種別を選んだのに紐付け先が空の保存は断る（D1。画面の選択が壊れても空で保存されないように）
            're_project_lot_id'         => 'nullable|required_if:land_source_type,project_lot|exists:re_project_lots,id',
            're_procurement_id'         => 'nullable|required_if:land_source_type,procurement|exists:re_procurements,id',
            // 区画は選んだ仕入れ案件の区画だけ（別の土地の区画・無い区画は断る。Review Focus 1）
            're_procurement_lot_id'     => $isProcurement
                ? ['nullable', 'integer', Rule::requiredIf($lotRequired), Rule::exists('re_procurement_lots', 'id')->where('procurement_id', $chosenId)]
                : 'exclude',
            'postal_code'               => 'nullable|string|max:10',
            'address'                   => 'required|string|max:200',
            'land_area_sqm'             => 'nullable|numeric|min:0|max:99999999.99',
            'building_area_sqm'         => 'nullable|numeric|min:0|max:99999999.99',
            'structure'                 => 'nullable|string|max:50',
            'floors'                    => 'nullable|integer|min:1|max:99',
            // 金額の列は符号付き INT・消費税率は DECIMAL(4,2)（本番の MySQL は範囲外で 500。H4 / Bug #73）
            'building_contract_price'   => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'building_cost'             => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'land_selling_price'        => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'land_cost'                 => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'is_land_cost_manual'       => 'required|in:0,1',
            // 「今の計算値にする」（保存しない欄。applyAutomaticLandCost() が読んで外す）
            'refresh_land_cost'         => 'nullable|boolean',
            'tax_rate'                  => 'required|numeric|min:0|max:99.99',
            'contract_date'             => 'nullable|date',
            'construction_start_date'   => 'nullable|date',
            'scheduled_completion_date' => 'nullable|date',
            'delivery_date'             => 'nullable|date',
            'notes'                     => 'nullable|string|max:5000',
        ], [
            're_project_lot_id.required_if' => '土地種別が分譲地区画のときは、区画を選んでください。',
            're_procurement_id.required_if' => '土地種別が仕入れ案件のときは、仕入れ案件を選んでください。',
            're_procurement_lot_id.required' => $lotNeededToEnterContract ? HsCustomOrder::CONTRACT_NEEDS_LOT_HERE : '区画を選んでください。',
        ], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「住所」）
            'address' => '所在地',
        ])->after(function ($validator) use ($current, $request, $isProcurement, $selfTakes) {
            if ($current?->refusesLandChange($request->all())) {
                $validator->errors()->add('land_source_type', HsCustomOrder::LAND_LOCKED_BY_CONTRACT);
            }

            // 1 つの区画に付けられる建売・契約・契約以降の注文住宅は 1 件だけ（設計書 §5.3）。注文住宅は、保存するステータスが
            // 「契約」以降のときに区画を取る（見積り以前は取らないが、ほかに取られた区画を新しく選ぶ・変えることはできない。決めたこと 4）。
            // 区画や仕入れ案件にほかの誤りがあれば見ない
            if (! $isProcurement
                || $validator->errors()->has('re_procurement_lot_id')
                || $validator->errors()->has('re_procurement_id')) {
                return;
            }
            // この保存で区画を取るか（$selfTakes）は入力チェックの前に求めた
            $refusal = ProcurementLotClaims::refusal($request->input('re_procurement_lot_id'), $current, $selfTakes);
            if ($refusal !== null) {
                $validator->errors()->add('re_procurement_lot_id', $refusal);
            }
        })->validate();
    }

    /**
     * 紐づけ種別に応じてFKを設定/クリア。
     * ⚠ 土地の区画は、種別が仕入れ案件でも送られないことがある（区画の無い土地では選択欄が隠れて送られない）。
     *   そのときも前の区画を残さない（validated() は送られないキーを含めない。Bug #38）
     */
    private function setLandSourceForeignKeys(array &$validated): void
    {
        $sourceType = $validated['land_source_type'] ?? null;

        if ($sourceType === HousingLandSourceType::ProjectLot->value) {
            $validated['re_procurement_id'] = null;
            $validated['re_procurement_lot_id'] = null;
        } elseif ($sourceType === HousingLandSourceType::Procurement->value) {
            $validated['re_project_lot_id'] = null;
            $validated['re_procurement_lot_id'] = isset($validated['re_procurement_lot_id']) ? (int) $validated['re_procurement_lot_id'] : null;
        } else {
            // customer_land or null
            $validated['re_project_lot_id'] = null;
            $validated['re_procurement_id'] = null;
            $validated['re_procurement_lot_id'] = null;
        }
    }

    /**
     * お客様所有土地の場合、土地金額フィールドをクリア
     */
    private function clearLandFieldsForCustomerLand(array &$validated): void
    {
        $sourceType = $validated['land_source_type'] ?? null;

        if ($sourceType === HousingLandSourceType::CustomerLand->value || $sourceType === null) {
            $validated['land_selling_price'] = null;
            $validated['land_cost'] = null;
            $validated['is_land_cost_manual'] = 0;
        }
    }

    /**
     * 自社の土地（分譲地区画・仕入れ案件）で手動 OFF なら、土地原価をサーバーが決める（送られた値は使わない。判断待ち ⑤。
     * App\Support\HousingLandCost::whenAutomatic()）。お客様の土地・種別が空は clearLandFieldsForCustomerLand() が空にしたまま。
     * setLandSourceForeignKeys()・clearLandFieldsForCustomerLand() のあとに呼ぶ。$current は登録では null。
     */
    private function applyAutomaticLandCost(array &$validated, Request $request, ?HsCustomOrder $current): void
    {
        unset($validated['refresh_land_cost']);
        $ownLand = in_array($validated['land_source_type'] ?? null, [HousingLandSourceType::ProjectLot->value, HousingLandSourceType::Procurement->value], true);
        if (! $ownLand || (bool) $validated['is_land_cost_manual']) {
            return;
        }

        $validated['land_cost'] = HousingLandCost::whenAutomatic($current, $validated, $request->boolean('refresh_land_cost'));
    }

    /**
     * 画面に出す「今の紐付け先から計算した土地原価」（入力エラーで戻った画面は選んでいた紐付け先。判断待ち ⑤）。
     * $procurementLotId は区画の選択の初めの値（HousingProcurementLots::initialSelection() の lotId。区画の選択肢に無くなった区画は
     * 渡さない＝その区画の按分を出さない。Ruling 11）
     */
    private function computedLandCost(?HsCustomOrder $order, ?int $procurementLotId): ?int
    {
        $type = old('land_source_type', $order?->land_source_type?->value);

        return HousingLandCost::forLink(is_string($type) ? $type : null,
            old('re_project_lot_id', $order?->re_project_lot_id), old('re_procurement_id', $order?->re_procurement_id), $procurementLotId);
    }

    /**
     * 案件番号の自動採番: CO-NNN
     */
    private function generateOrderCode(): string
    {
        $prefix = 'CO-';

        $lastCode = HsCustomOrder::where('order_code', 'like', "{$prefix}%")
            ->orderByDesc('order_code')
            ->value('order_code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * デフォルト消費税率を取得
     */
    private function getDefaultTaxRate(): string
    {
        // Settings ヘルパー経由で取得。テーブル不在 / 取得失敗時は内部で 10.0 を返す。
        // view 側は '10.00' のような小数2桁文字列を想定しているため number_format で整形。
        return number_format(Settings::taxRate(), 2, '.', '');
    }

    /**
     * 分譲地一覧（JS用）
     */
    private function getProjectsForJs(): array
    {
        $projects = ReProject::orderByDesc('id')->get(['id', 'project_code', 'project_name']);
        $result = [];
        foreach ($projects as $pj) {
            $result[] = [
                'id'   => $pj->id,
                'code' => $pj->project_code,
                'name' => $pj->project_name,
            ];
        }
        return $result;
    }

    /**
     * 区画の選択の初期値。選んでいる区画の分譲地と、その分譲地の区画の一覧（API と同じ形）をサーバで作る。
     * ⚠ 区画の `<option>` は `<template x-for>` なので、一覧を空から始めると今の区画が選べず、空のまま送られる（Top trap #3）。
     *
     * @return array{projectId: ?int, lots: array<int, array<string, mixed>>}
     */
    private function initialLotSelection(mixed $lotId): array
    {
        $lot = is_numeric($lotId) ? ReProjectLot::find($lotId) : null;
        $project = $lot ? ReProject::with('lots', 'costs')->find($lot->project_id) : null;
        if (! $project) {
            return ['projectId' => null, 'lots' => []];
        }

        return ['projectId' => $project->id, 'lots' => $project->housingLotOptions()];
    }

    /**
     * 区画ステータス連動（ステータス変更時共通ロジック）
     *
     * - 「契約」以降に進んだ → 紐づく区画を sold に更新
     * - 「契約」より前に戻した → 紐づく区画を on_sale に戻す
     */
    private function syncLotStatus(HsCustomOrder $order, ?CustomOrderStatus $oldStatus): void
    {
        $newIsContracted = $order->status->isContractedOrLater();
        $oldIsContracted = $oldStatus ? $oldStatus->isContractedOrLater() : false;

        if ($newIsContracted === $oldIsContracted) {
            return;
        }

        // 土地の仕入れ案件の区画: その注文住宅の区画だけを動かし、仕入れ案件のステータスを区画から集約する（設計書 §5.4）。
        // 区画の無い注文住宅（区画の無い土地・#21 の形）は何もしない
        $procurementLotId = $order->re_procurement_lot_id ? (int) $order->re_procurement_lot_id : null;
        if ($newIsContracted) {
            ReProcurementLot::markSold($procurementLotId);
        } else {
            ReProcurementLot::markOnSale($procurementLotId);
        }

        // 分譲地の区画
        $lot = $order->re_project_lot_id ? $order->projectLot : null;
        if (!$lot) {
            return;
        }

        $lot->update(['status' => ($newIsContracted ? LotStatus::Sold : LotStatus::OnSale)->value]);
        $lot->project?->syncStatusFromLots();
    }

    /**
     * 削除時: 区画ステータスを on_sale に戻す
     */
    private function releaseLot(HsCustomOrder $order): void
    {
        // 契約以降のステータスで削除された場合のみ戻す
        if (!$order->status->isContractedOrLater()) {
            return;
        }

        // 土地の仕入れ案件の区画: その注文住宅の区画だけを販売中にする（区画の無い注文住宅は何もしない）
        ReProcurementLot::markOnSale($order->re_procurement_lot_id ? (int) $order->re_procurement_lot_id : null);

        if (!$order->re_project_lot_id) {
            return;
        }

        $lot = $order->projectLot;
        if ($lot) {
            $lot->update(['status' => LotStatus::OnSale->value]);
            $lot->project?->syncStatusFromLots();
        }
    }
}
