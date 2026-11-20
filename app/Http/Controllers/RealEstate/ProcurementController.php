<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Http\Controllers\Controller;
use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ZoningType;
use App\Services\RealEstate\ProcurementConversionRefused;
use App\Services\RealEstate\ProcurementToProjectConverter;
use App\Services\RealEstate\ProcurementListRow;
use App\Services\RealEstate\ProcurementListService;
use App\Support\DeletionBlockers;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementController extends Controller
{
    use \App\Http\Controllers\RealEstate\Concerns\ValidatesCostGrid;

    /**
     * 仕入れ案件一覧（分譲地を統合した不動産案件の横断ビュー）
     * Route: GET /realestate/procurements
     */
    public function index(Request $request, ProcurementListService $listService)
    {
        [$rows, $kindTotals] = $listService->paginateWithKindTotals($request);

        // ステータスポップオーバー用の選択肢を種別ごとに組む。
        // ⚠ 配列リテラルを Blade の @json() へ直接書かず、ここで組んで変数 1 本で渡す
        //   （多行の配列リテラル + メソッド呼び出しは Blade の引数パーサを壊す。Bug #26）
        $statusOptionsByKind = [
            ProcurementListRow::KIND_PROCUREMENT => $this->statusOptions(ProcurementStatus::cases()),
            ProcurementListRow::KIND_PROJECT     => $this->statusOptions(ProjectStatus::cases()),
        ];

        return view('realestate.procurements.index', compact('rows', 'kindTotals', 'statusOptionsByKind'));
    }

    /**
     * ステータス enum を一覧のポップオーバー用配列へ整形する
     *
     * @param  array<int, ProcurementStatus|ProjectStatus>  $cases
     * @return array<int, array{value: string, label: string, badge_class: string}>
     */
    private function statusOptions(array $cases): array
    {
        return array_map(fn ($case) => [
            'value'       => $case->value,
            'label'       => $case->label(),
            'badge_class' => $case->badgeClass(),
        ], $cases);
    }

    /**
     * 仕入れ案件登録フォーム
     * Route: GET /realestate/procurements/create
     */
    public function create()
    {
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        // 原価管理セクション用データ — show() と同じ構成。
        // 新規登録フォームの「原価管理」カードが Excel 取込 + 見込み額・確定額の表でこれらを使う。
        $costItems = ReCostItem::active()->ordered()->get();
        $costItemsForJs = [];
        foreach ($costItems as $item) {
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name];
        }
        $costAliasMap    = config('realestate_cost_import.aliases', []);
        $costSkipList    = config('realestate_cost_import.skip', []);
        $costSubtotalKws = config('realestate_cost_import.subtotal_keywords', []);
        // 原価の入力の表（見込み額・確定額）の項目（停止中も含めた全件。新規登録の表は物件購入費を出さない）
        $costGridItems   = ReCostItem::forCostGrid();

        return view('realestate.procurements.create', compact(
            'zoningTypes', 'costItemsForJs', 'costAliasMap', 'costSkipList', 'costSubtotalKws', 'costGridItems'
        ));
    }

    /**
     * 仕入れ案件保存
     * Route: POST /realestate/procurements
     */
    public function store(Request $request)
    {
        // 新規登録フォームの原価管理セクションから送信された costs 配列も一緒に見る（入力チェックを 1 回にまとめる（2 回に分けると、1 回目で断ったときに 2 回目の欄の配列が控えに残り、戻った画面が 500 になる。Bug #121））
        $validated = $this->validateProcurement($request, withCosts: true);
        $costsData = $validated['costs'] ?? [];
        unset($validated['costs']);
        $validated['created_by'] = auth()->id();

        // 「物件購入費」は ReProcurement::syncPropertyPurchaseCost() で booted() hook により
        // 自動生成されるため、フォーム経由で送られた場合は除外する（二重登録防止）
        $propertyPurchaseId = ReCostItem::where('name', '物件購入費')->value('id');
        if ($propertyPurchaseId !== null) {
            $costsData = array_values(array_filter(
                $costsData,
                fn ($r) => (int) $r['cost_item_id'] !== (int) $propertyPurchaseId
            ));
        }

        $procurement = DB::transaction(function () use ($validated, $costsData) {
            // 採番はトランザクション内で行い、同時 INSERT による procurement_code 衝突を防ぐ（DAD と同じパターン）
            $validated['procurement_code'] = ReProcurement::nextProcurementCode();
            $proc = ReProcurement::create($validated);
            foreach ($costsData as $row) {
                ReProcurementCost::create([
                    'procurement_id'   => $proc->id,
                    'cost_item_id'     => $row['cost_item_id'],
                    'estimated_amount' => $row['estimated_amount'],
                    'actual_amount'    => $row['actual_amount'] ?? null,
                    'notes'            => $row['notes'] ?? null,
                ]);
            }
            return $proc;
        });

        $msg = count($costsData) > 0
            ? "仕入れ案件「{$procurement->procurement_code}」を登録しました（原価 " . count($costsData) . " 件を含む）。"
            : "仕入れ案件「{$procurement->procurement_code}」を登録しました。";

        return redirect()
            ->route('realestate.procurements.show', $procurement)
            ->with('success', $msg);
    }

    /**
     * 仕入れ案件詳細
     * Route: GET /realestate/procurements/{procurement}
     */
    public function show(Request $request, ReProcurement $procurement)
    {
        $procurement->load([
            'supplier', 'costs.costItem', 'createdBy', 'updatedBy',
            'contracts.buyer', 'contracts.staff',
        ]);

        // 原価項目マスタ（費用追加のセレクト用）
        $costItems = ReCostItem::active()->ordered()->get();
        $costItemsForJs = [];
        foreach ($costItems as $item) {
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name];
        }

        // 原価データを事前整形（@json用）
        $costsForJs = [];
        foreach ($procurement->costs as $cost) {
            $costsForJs[] = [
                'id'                   => $cost->id,
                'cost_item_id'         => $cost->cost_item_id,
                'cost_item_name'       => $cost->costItem ? $cost->costItem->name : '（削除済み）',
                'estimated_amount'     => $cost->estimated_amount,
                'actual_amount'        => $cost->actual_amount,
                'notes'                => $cost->notes ?? '',
                // 「物件購入費」は ReProcurement::syncPropertyPurchaseCost() による自動同期行
                // → 編集・削除UIを抑止するためのフラグ
                'is_property_purchase' => $cost->costItem && $cost->costItem->name === '物件購入費',
            ];
        }

        // 添付ファイル
        $attachments = $procurement->attachments()
            ->whereNull('deleted_at')
            ->with('uploadedByUser')
            ->orderByDesc('created_at')
            ->get();

        $deletedAttachments = $procurement->attachments()
            ->onlyTrashed()
            ->with(['uploadedByUser', 'deletedByUser'])
            ->orderByDesc('deleted_at')
            ->get();

        // 原価の入力の表（見込み額・確定額）の項目（停止中も含めた全件）と、保存済みの行を消せるか（経営層だけ）
        $costGridItems     = ReCostItem::forCostGrid();
        $costGridCanDelete = $request->user()->role->isExecutive();

        // 試算表 Excel/CSV 取込のマッピング辞書（クライアント側 matchCostItem で使用）
        $costAliasMap     = config('realestate_cost_import.aliases', []);
        $costSkipList     = config('realestate_cost_import.skip', []);
        $costSubtotalKws  = config('realestate_cost_import.subtotal_keywords', []);

        // 削除ブロッカー（パネル + 削除ボタンの無効化。判定はサーバのガードと同じ 1 本を通す）
        // 要約文は Blade で組まずここで作る（Blade には整形済みの値だけ渡す）
        $deletionBlockers = $procurement->deletionBlockers();
        $deletionBlockersSummary = DeletionBlockers::summarize($deletionBlockers);

                // 工程表（設計書 §4.1）
        $schedule        = app(\App\Services\ScheduleCardService::class)->build($procurement);
        $scheduleCanEdit = $request->user()->role->isManagerOrAbove();

        // 土地の区画（土地のときだけ欄を出す。登録のフォームの既定のステータスは契約などから決める）
        $lot              = $procurement->property_type?->isLandOnly() ? $procurement->lot : null;
        $lotDefaultStatus = $procurement->defaultLotStatus();
        // 「分譲地に変える」を押せない理由（変えられるなら null）。画面はこの値だけを見る（サービスを自分では呼ばない）
        $conversionRefusal = ProcurementToProjectConverter::refusal($procurement);

        return view('realestate.procurements.show', compact(
            'procurement', 'costItemsForJs', 'costsForJs', 'costGridItems', 'costGridCanDelete',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws',
            'deletionBlockers', 'deletionBlockersSummary',
            'schedule', 'scheduleCanEdit',
            'lot', 'lotDefaultStatus', 'conversionRefusal'
        ));
    }

    /**
     * 仕入れ案件編集フォーム
     * Route: GET /realestate/procurements/{procurement}/edit
     */
    public function edit(ReProcurement $procurement)
    {
        $procurement->load('supplier');
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        return view('realestate.procurements.edit', compact('procurement', 'zoningTypes'));
    }

    /**
     * 仕入れ案件更新
     * Route: PUT /realestate/procurements/{procurement}
     */
    public function update(Request $request, ReProcurement $procurement)
    {
        $validated = $this->validateProcurement($request, current: $procurement);
        $validated['updated_by'] = auth()->id();

        $procurement->update($validated);

        return redirect()
            ->route('realestate.procurements.show', $procurement)
            ->with('success', "仕入れ案件「{$procurement->procurement_code}」を更新しました。");
    }

    /**
     * 仕入れ案件のステータスのみ Ajax 更新（一覧バッジ クリック → ポップオーバー選択用）
     * Route: PATCH /realestate/procurements/{procurement}/status
     */
    public function updateStatus(Request $request, ReProcurement $procurement)
    {
        $statuses = implode(',', array_column(ProcurementStatus::cases(), 'value'));

        $validated = $request->validate([
            'status' => ["bail", "required", "in:{$statuses}", $this->statusLockedByContract($procurement)],
        ]);

        $procurement->update([
            'status'     => $validated['status'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'status'  => [
                'value'       => $procurement->status->value,
                'label'       => $procurement->status->label(),
                'badge_class' => $procurement->status->badgeClass(),
            ],
        ]);
    }

    /**
     * 土地の仕入れ案件を中身ごと分譲地に変える（経営層・管理者）
     * Route: POST /realestate/procurements/{procurement}/convert-to-project
     *
     * 入力チェックは無い（送るのは確認だけ）。成功は分譲地の詳細へ直接戻す（2 段の転送でフラッシュが消える。Bug #63）。
     * 二度押しの 2 回目は仕入れ案件がもう無いので、ルートの missing() が一覧へ戻す。
     */
    public function convertToProject(Request $request, ReProcurement $procurement, ProcurementToProjectConverter $converter): RedirectResponse
    {
        $code = $procurement->procurement_code;

        try {
            $project = $converter->convert($procurement, $request->user());
        } catch (ProcurementConversionRefused $e) {
            if ($e->getMessage() === ProcurementToProjectConverter::GONE) {
                return redirect()->route('realestate.procurements.index')->with('error', $e->getMessage());
            }

            return redirect(route('realestate.procurements.show', $procurement) . '#lot')->with('error', $e->getMessage());
        } catch (\Exception $e) {
            report($e);

            return redirect(route('realestate.procurements.show', $procurement) . '#lot')
                ->with('error', '分譲地に変えられませんでした。何も変えていません。時間をおいてやり直してください。');
        }

        return redirect()->route('realestate.projects.show', $project)
            ->with('success', "分譲地「{$project->project_code}」に変えました（元の仕入れ案件番号 {$code} は分譲地の詳細に残しています）。");
    }

    /**
     * 仕入れ案件削除
     * Route: DELETE /realestate/procurements/{procurement}
     */
    public function destroy(ReProcurement $procurement)
    {
        // 契約・建売物件・注文住宅が参照している間は消させない。
        // 本番の FK は ON DELETE SET NULL なので、消すと参照側が「土地元が仕入れ案件」と
        // 名乗ったまま参照先を失う矛盾状態になる（判定は DeletionBlockers に一本化）。
        if ($blockers = $procurement->deletionBlockers()) {
            return back()->with('error', DeletionBlockers::summarize($blockers));
        }

        $code = $procurement->procurement_code;

        // 工程は親に完全従属するので一緒に消す（設計書 §3.5）。
        // ⚠ DeletionBlockers には足さない（足すと工程を書いた案件が二度と消せなくなる）。
        // ⚠ morphMany 経由で消すこと。schedulable_id だけで消すと、別テーブルの
        //   同じ id の工程まで巻き添えになる。
        // ⚠ 削除ブロックの判定より**後**に置くこと（ブロックされたのに工程だけ消える事故を防ぐ）。
        $procurement->scheduleSteps()->delete();

        // 土地の区画も一緒に消す（本番は外部キーの CASCADE。テスト用スキーマは外部キーを張らないのでコードにも書く）
        $procurement->lot()->delete();

        // 原価明細も一緒に削除（cascadeOnDelete）
        $procurement->delete();

        return redirect()->route('realestate.procurements.index')
            ->with('success', "仕入れ案件「{$code}」を削除しました。");
    }

    // ================================================================
    // 原価管理 Ajax（3ルート: 表の保存・試算表の取込・1 行の削除。1 行ずつの追加・編集は 2026-11-16 に表の保存へ置き換えた）
    // ================================================================

    /**
     * 原価の表をまとめて保存する（見込み額の表・確定額の表）。1 行でも断るところがあれば何も保存しない。
     * Route: PUT /realestate/procurements/{procurement}/costs/batch
     */
    public function saveCostGrid(Request $request, ReProcurement $procurement): \Illuminate\Http\JsonResponse
    {
        $v = $this->validateCostGrid($request);

        $result = app(\App\Support\RealEstateCostGrid::class)
            ->save($procurement, $v['mode'], $v['rows'], $request->user()->role->isExecutive());

        return response()->json(['success' => true] + $result);
    }

    /**
     * 費用削除
     * Route: DELETE /realestate/procurements/{procurement}/costs/{cost}
     */
    public function destroyCost(ReProcurement $procurement, ReProcurementCost $cost)
    {
        if ($cost->procurement_id !== $procurement->id) {
            return response()->json(['message' => '不正なリクエストです。'], 403);
        }

        // 物件購入費は仕入れ情報から自動同期されるため手動削除を禁止
        $cost->load('costItem');
        if ($cost->costItem && $cost->costItem->name === '物件購入費') {
            return response()->json([
                'success' => false,
                'message' => '物件購入費は仕入れ情報から自動同期されるため、手動で削除できません。',
            ], 403);
        }

        $cost->delete();

        return response()->json(['success' => true]);
    }

    /**
     * 試算表 Excel/CSV 取込（バルク投入）
     * Route: POST /realestate/procurements/{procurement}/costs/bulk-import
     */
    public function bulkImportCosts(Request $request, ReProcurement $procurement)
    {
        $validated = $request->validate([
            'mode'                    => 'required|in:overwrite,append',
            'rows'                    => 'required|array|min:1|max:500',
            'rows.*.cost_item_id'     => 'required|integer|exists:re_cost_items,id',
            'rows.*.estimated_amount' => 'required|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'rows.*.actual_amount'    => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'rows.*.notes'            => 'nullable|string|max:200',
        ]);

        // 上書きは経営層だけ（何もせずに断る。画面は理由＋「何も取り込んでいません。」を出す。Bug #140）
        if (\App\Support\RealEstateCostImportService::refusesOverwrite($validated['mode'], $request->user())) {
            return response()->json([
                'success' => false,
                'message' => \App\Support\RealEstateCostImportService::OVERWRITE_FOR_EXECUTIVES_ONLY,
            ], 403);
        }

        // 物件購入費はサーバー側でも二重防御（クライアントで弾けていても二重登録を防ぐ）
        $purchaseId = ReCostItem::where('name', '物件購入費')->value('id');
        if ($purchaseId) {
            $validated['rows'] = array_values(array_filter(
                $validated['rows'],
                fn ($r) => (int) $r['cost_item_id'] !== (int) $purchaseId
            ));
        }

        if (empty($validated['rows'])) {
            return response()->json([
                'success' => false,
                'message' => '取込対象の行がありません（物件購入費は自動同期されるため取込対象外）。',
            ], 422);
        }

        $result = (new \App\Support\RealEstateCostImportService())
            ->importToProcurement($procurement, $validated['rows'], $validated['mode']);

        return response()->json(array_merge(['success' => true], $result));
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 契約のある案件を「販売済」から戻させない規則（一覧の小窓と編集画面。Bug #139）。登録（$procurement が null）では何もしない。
     * ⚠ 呼ぶ側は bail を付ける（in: で断られた値をここへ通さない＝ここに来るのは正しいステータスの文字だけ）。
     */
    private function statusLockedByContract(?ReProcurement $procurement): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($procurement) {
            if ($procurement !== null && is_string($value) && $procurement->refusesStatus($value)) {
                $fail(ReProcurement::STATUS_LOCKED_BY_CONTRACT);
            }
        };
    }

    /**
     * 区画のある土地の物件種別を土地以外へ変えさせない規則（編集画面）。登録（$procurement が null）では何もしない。
     * ⚠ 呼ぶ側は bail を付ける。今と同じ値（土地のまま）なら通す＝編集画面をそのまま保存できる。
     */
    private function typeLockedByLot(?ReProcurement $procurement): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($procurement) {
            if ($procurement !== null && is_string($value)
                && $value !== RealEstatePropertyType::BrokerageLand->value
                && $procurement->lot()->exists()) {
                $fail(ReProcurement::TYPE_LOCKED_BY_LOT);
            }
        };
    }

    private function validateProcurement(Request $request, bool $withCosts = false, ?ReProcurement $current = null): array
    {
        $propertyTypes = implode(',', array_column(RealEstatePropertyType::cases(), 'value'));
        $transactionTypes = implode(',', array_column(RealEstateTransactionType::cases(), 'value'));
        $statuses = implode(',', array_column(ProcurementStatus::cases(), 'value'));

        $validated = $request->validate([
            // 原価（新規登録の画面だけが送る）。件数上限(max:500)・notes上限(max:200)は詳細の bulkImportCosts と揃える。
            // 編集では見ない（exclude）
            'costs'                    => $withCosts ? 'nullable|array|max:500' : 'exclude',
            'costs.*.cost_item_id'     => 'required|integer|exists:re_cost_items,id',
            'costs.*.estimated_amount' => 'required|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'costs.*.actual_amount'    => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'costs.*.notes'            => 'nullable|string|max:200',
            'property_type'       => ["bail", "required", "in:{$propertyTypes}", $this->typeLockedByLot($current)],
            'transaction_type'    => "required|in:{$transactionTypes}",
            'status'              => ["bail", "required", "in:{$statuses}", $this->statusLockedByContract($current)],
            'property_name'       => 'required|string|max:100',
            'postal_code'         => 'nullable|string|max:10',
            'address'             => 'required|string|max:200',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
            'land_area_sqm'       => 'nullable|numeric|min:0|max:99999999.99',
            'building_area_sqm'   => 'nullable|numeric|min:0|max:99999999.99',
            'structure'           => 'nullable|string|max:50',
            'built_year_month'    => 'nullable|string|max:7',
            'zoning'              => 'nullable|string|max:50',
            'building_coverage'   => 'nullable|numeric|min:0|max:100',
            'floor_area_ratio'    => 'nullable|numeric|min:0|max:999.99',
            'supplier_id'         => 'nullable|exists:re_suppliers,id',
            'info_obtained_date'  => 'nullable|date',
            'assessment_price_land'         => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'assessment_price_building'     => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'purchase_price_land'           => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'purchase_price_building'       => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'target_selling_price_land'     => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'target_selling_price_building' => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'tax_rate'                      => 'nullable|numeric|min:0|max:99.99',
            'contract_date'       => 'nullable|date',
            'settlement_date'     => 'nullable|date',
            'notes'               => 'nullable|string|max:5000',
        ], [], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「住所」）
            'address' => '所在地',
            // グローバルの target_selling_price_building は建売の「建物予定販売価格」。
            // attributes はアプリ全体で 1 つのマップしか持てないので、
            // 仕入れ案件だけ第 3 引数で上書きする（Bug #37。第 2 引数は messages）
            'target_selling_price_building' => '想定販売価格（建物）',
        ]);

        // tax_rate は NOT NULL DEFAULT 10.00。欄が空でも必ず値を入れる
        $validated['tax_rate'] = $validated['tax_rate'] ?? Settings::taxRate();

        return $validated;
    }
}
