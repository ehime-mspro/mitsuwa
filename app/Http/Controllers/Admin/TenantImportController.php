<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * テナントCSVインポートコントローラ
 *
 * 4種類の個別CSVインポート機能を提供:
 * ① 物件インポート ② 区画インポート ③ 顧客インポート ④ 契約インポート
 */
class TenantImportController extends Controller
{
    // ================================================================
    // Enum日本語→DB値マッピング
    // ================================================================

    private array $ownerTypeMap = ['自社' => 'self_owned', 'オーナー' => 'owner'];
    private array $operationStatusMap = ['稼働中' => 'active', '停止中' => 'inactive'];
    private array $usageTypeMap = ['店舗' => 'shop', '倉庫' => 'warehouse', '事務所' => 'office', 'その他' => 'other'];
    private array $unitStatusMap = ['空室' => 'vacant', '入居中' => 'occupied', '商談中' => 'negotiating'];
    private array $customerTypeMap = ['法人' => 'corporation', '個人事業主' => 'sole_proprietor', '個人' => 'individual'];

    // ================================================================
    // 各種別のカラムマッピング（日本語ヘッダー → 内部キー）
    // ================================================================

    /** 物件CSVカラム */
    private array $propertyColumnMap = [
        '物件名'     => 'name',
        '郵便番号'   => 'postal_code',
        '住所'       => 'address',
        '構造'       => 'structure',
        '築年月'     => 'built_date',
        '階数'       => 'total_floors',
        '所有区分'   => 'owner_type',
        'オーナー名' => 'owner_name',
        '稼働状態'   => 'operation_status',
    ];

    /** 区画CSVカラム */
    private array $unitColumnMap = [
        '物件名'     => 'property_name',
        '階'         => 'floor',
        '部屋番号'   => 'room_number',
        '面積(坪)'   => 'area_tsubo',
        '用途'       => 'usage_type',
        '状態'       => 'status',
        '募集家賃'   => 'rent',
        '募集共益費'  => 'common_fee',
        '募集敷金'   => 'deposit',
        '募集ゴミ代'  => 'garbage_fee',
        '募集駆除代'  => 'pest_control_fee',
    ];

    /** 顧客CSVカラム */
    private array $customerColumnMap = [
        'テナント名'     => 'name',
        'テナントカナ'   => 'name_kana',
        '種別'           => 'customer_type',
        '代表者名'       => 'representative',
        '担当者'         => 'contact_person',
        '電話番号'       => 'phone',
        'メールアドレス' => 'email',
        '郵便番号'       => 'postal_code',
        '住所'           => 'address',
    ];

    /** 契約CSVカラム */
    private array $contractColumnMap = [
        '物件名'     => 'property_name',
        '部屋番号'   => 'room_number',
        'テナント名' => 'customer_name',
        '契約日'     => 'contract_date',
        '賃料開始日' => 'rent_start_date',
        '家賃'       => 'rent',
        '共益費'     => 'common_fee',
        '敷金'       => 'deposit',
        'ゴミ代'     => 'garbage_fee',
        '駆除代'     => 'pest_control_fee',
        '屋号'       => 'store_name',
        '備考'       => 'notes',
    ];

    // ================================================================
    // 画面表示
    // ================================================================

    /**
     * インポート画面表示
     */
    public function showForm(Request $request)
    {
        return view('admin.tenant-import.index', [
            'activeTab' => $request->query('tab', 'property'),
        ]);
    }

    // ================================================================
    // ① 物件インポート
    // ================================================================

    /**
     * 物件CSVインポート実行（プレビュー＋確認の2段階）
     */
    public function executeProperty(Request $request)
    {
        $columnMap = $this->propertyColumnMap;
        $requiredKeys = ['name', 'address'];
        $tab = 'property';

        // CSV読み込み
        $result = $this->loadCsv($request, $columnMap, $requiredKeys);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // 行バリデーション
        $errors = [];
        $validRows = [];
        $skippedRows = [];
        $nameTracker = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // 1行目=ヘッダー

            // 必須チェック
            if ($row['name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];
                continue;
            }
            if ($row['address'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '住所が未入力です'];
                continue;
            }

            // CSV内重複チェック
            if (isset($nameTracker[$row['name']])) {
                $errors[] = ['row' => $rowNum, 'message' => "物件名「{$row['name']}」がCSV内で重複しています（行{$nameTracker[$row['name']]})"];
                continue;
            }
            $nameTracker[$row['name']] = $rowNum;

            // DB既存チェック
            $existing = Property::where('name', $row['name'])
                ->where('department', DepartmentCode::Tenant->value)
                ->first();
            if ($existing) {
                $skippedRows[] = ['row' => $rowNum, 'message' => "物件「{$row['name']}」は既に登録済みのためスキップ"];
                continue;
            }

            // Enum値チェック
            if ($row['owner_type'] !== '' && !isset($this->ownerTypeMap[$row['owner_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "所有区分「{$row['owner_type']}」は不正な値です（自社/オーナー）"];
                continue;
            }
            if ($row['operation_status'] !== '' && !isset($this->operationStatusMap[$row['operation_status']])) {
                $errors[] = ['row' => $rowNum, 'message' => "稼働状態「{$row['operation_status']}」は不正な値です（稼働中/停止中）"];
                continue;
            }

            // 築年月チェック（YYYY-MM形式）
            if ($row['built_date'] !== '') {
                $val = str_replace('/', '-', $row['built_date']);
                if (!preg_match('/^\d{4}-\d{1,2}$/', $val)) {
                    $errors[] = ['row' => $rowNum, 'message' => "築年月「{$row['built_date']}」の形式が不正です（YYYY-MM）"];
                    continue;
                }
                $row['built_date'] = $val;
            }

            // 階数チェック
            if ($row['total_floors'] !== '') {
                if (!ctype_digit($row['total_floors']) || (int) $row['total_floors'] < 1) {
                    $errors[] = ['row' => $rowNum, 'message' => "階数「{$row['total_floors']}」は不正な値です"];
                    continue;
                }
                $row['total_floors'] = (int) $row['total_floors'];
            }

            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.tenant-import.index', [
                'activeTab'    => $tab,
                'preview'      => $tab,
                'totalRows'    => count($rows),
                'validCount'   => count($validRows),
                'errors'       => $errors,
                'skippedRows'  => $skippedRows,
                'summary'      => '物件 ' . count($validRows) . '件を新規作成',
                'csvData'      => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $propertyCodeNum = $this->getNextPropertyCodeNum();
            $created = 0;

            foreach ($validRows as $row) {
                $code = 'T-' . str_pad($propertyCodeNum, 3, '0', STR_PAD_LEFT);
                $propertyCodeNum++;

                $ownerType = $row['owner_type'] !== ''
                    ? $this->ownerTypeMap[$row['owner_type']]
                    : null;

                Property::create([
                    'code'             => $code,
                    'name'             => $row['name'],
                    'property_type'    => PropertyType::Tenant->value,
                    'department'       => DepartmentCode::Tenant->value,
                    'operation_status' => $row['operation_status'] !== ''
                        ? $this->operationStatusMap[$row['operation_status']]
                        : 'active',
                    'postal_code'      => $row['postal_code'] ?: null,
                    'address'          => $row['address'],
                    'structure'        => $row['structure'] ?: null,
                    'built_date'       => $row['built_date'] ?: null,
                    'total_floors'     => $row['total_floors'] !== '' ? $row['total_floors'] : null,
                    'owner_type'       => $ownerType,
                    'owner_name'       => $ownerType === 'owner' ? ($row['owner_name'] ?: null) : null,
                ]);
                $created++;
            }

            DB::commit();

            return redirect()->route('admin.tenant-import', ['tab' => $tab])
                ->with('success', "物件インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ② 区画インポート
    // ================================================================

    /**
     * 区画CSVインポート実行
     */
    public function executeUnit(Request $request)
    {
        $columnMap = $this->unitColumnMap;
        $requiredKeys = ['property_name', 'room_number'];
        $tab = 'unit';

        // CSV読み込み
        $result = $this->loadCsv($request, $columnMap, $requiredKeys);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // 行バリデーション
        $errors = [];
        $validRows = [];
        $unitTracker = [];

        // 物件名→Propertyキャッシュ
        $propertyCache = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            // 必須チェック
            if ($row['property_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];
                continue;
            }
            if ($row['room_number'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '部屋番号が未入力です'];
                continue;
            }

            // 物件の存在チェック
            $propName = $row['property_name'];
            if (!isset($propertyCache[$propName])) {
                $prop = Property::where('name', $propName)
                    ->where('department', DepartmentCode::Tenant->value)
                    ->first();
                $propertyCache[$propName] = $prop;
            }
            if (!$propertyCache[$propName]) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」がシステムに登録されていません。先に物件インポートを実行してください"];
                continue;
            }

            // CSV内重複チェック（物件名+階数+部屋番号）
            $unitKey = $propName . '|' . $row['floor'] . '|' . $row['room_number'];
            if (isset($unitTracker[$unitKey])) {
                $floorLabel = $row['floor'] !== '' ? "（{$row['floor']}階）" : '';
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の部屋番号「{$row['room_number']}」{$floorLabel}がCSV内で重複しています（行{$unitTracker[$unitKey]}）"];
                continue;
            }
            $unitTracker[$unitKey] = $rowNum;

            // DB重複チェック（同一物件+部屋番号）
            $property = $propertyCache[$propName];
            $floor = $row['floor'] !== '' ? (int) $row['floor'] : null;
            $displayName = Unit::generateDisplayName($floor, $row['room_number']);
            $existingUnit = Unit::where('property_id', $property->id)
                ->where('display_name', $displayName)
                ->first();
            if ($existingUnit) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の区画「{$displayName}」は既に登録されています"];
                continue;
            }

            // Enum値チェック
            if ($row['usage_type'] !== '' && !isset($this->usageTypeMap[$row['usage_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "用途「{$row['usage_type']}」は不正な値です（店舗/倉庫/事務所/その他）"];
                continue;
            }
            if ($row['status'] !== '' && !isset($this->unitStatusMap[$row['status']])) {
                $errors[] = ['row' => $rowNum, 'message' => "状態「{$row['status']}」は不正な値です（空室/入居中/商談中）"];
                continue;
            }

            // 面積チェック
            if ($row['area_tsubo'] !== '') {
                $val = str_replace(',', '', $row['area_tsubo']);
                if (!is_numeric($val) || (float) $val < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "面積「{$row['area_tsubo']}」は不正な値です"];
                    continue;
                }
                $row['area_tsubo'] = (float) $val;
            }

            // 階チェック
            if ($row['floor'] !== '') {
                if (!ctype_digit($row['floor'])) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$row['floor']}」は不正な値です"];
                    continue;
                }
                $row['floor'] = (int) $row['floor'];
            }

            // 金額フィールドチェック
            $numericFields = [
                'rent' => '募集家賃', 'common_fee' => '募集共益費', 'deposit' => '募集敷金',
                'garbage_fee' => '募集ゴミ代', 'pest_control_fee' => '募集駆除代',
            ];
            $numericError = false;
            foreach ($numericFields as $field => $label) {
                if ($row[$field] !== '') {
                    $val = str_replace(',', '', $row[$field]);
                    if (!is_numeric($val) || (int) $val < 0) {
                        $errors[] = ['row' => $rowNum, 'message' => "{$label}「{$row[$field]}」は不正な値です"];
                        $numericError = true;
                        break;
                    }
                    $row[$field] = (int) $val;
                }
            }
            if ($numericError) {
                continue;
            }

            $row['_property_id'] = $property->id;
            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.tenant-import.index', [
                'activeTab'    => $tab,
                'preview'      => $tab,
                'totalRows'    => count($rows),
                'validCount'   => count($validRows),
                'errors'       => $errors,
                'skippedRows'  => [],
                'summary'      => '区画 ' . count($validRows) . '件を新規作成',
                'csvData'      => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $created = 0;
            $updatedProperties = [];

            foreach ($validRows as $row) {
                $floor = $row['floor'] !== '' ? $row['floor'] : null;
                $displayName = Unit::generateDisplayName($floor, $row['room_number']);

                Unit::create([
                    'property_id'      => $row['_property_id'],
                    'floor'            => $floor,
                    'room_number'      => $row['room_number'],
                    'display_name'     => $displayName,
                    'area_tsubo'       => $row['area_tsubo'] !== '' ? $row['area_tsubo'] : null,
                    'usage_type'       => $row['usage_type'] !== '' ? $this->usageTypeMap[$row['usage_type']] : null,
                    'status'           => $row['status'] !== '' ? $this->unitStatusMap[$row['status']] : UnitStatus::Vacant->value,
                    'rent'             => $row['rent'] !== '' ? $row['rent'] : null,
                    'common_fee'       => $row['common_fee'] !== '' ? $row['common_fee'] : null,
                    'deposit'          => $row['deposit'] !== '' ? $row['deposit'] : null,
                    'garbage_fee'      => $row['garbage_fee'] !== '' ? $row['garbage_fee'] : null,
                    'pest_control_fee' => $row['pest_control_fee'] !== '' ? $row['pest_control_fee'] : null,
                ]);
                $created++;
                $updatedProperties[$row['_property_id']] = true;
            }

            // 物件のtotal_unitsを更新
            foreach (array_keys($updatedProperties) as $propId) {
                $count = Unit::where('property_id', $propId)->count();
                Property::where('id', $propId)->update(['total_units' => $count]);
            }

            DB::commit();

            return redirect()->route('admin.tenant-import', ['tab' => $tab])
                ->with('success', "区画インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ③ 顧客インポート
    // ================================================================

    /**
     * 顧客CSVインポート実行
     */
    public function executeCustomer(Request $request)
    {
        $columnMap = $this->customerColumnMap;
        $requiredKeys = ['name'];
        $tab = 'customer';

        // CSV読み込み
        $result = $this->loadCsv($request, $columnMap, $requiredKeys);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // 行バリデーション
        $errors = [];
        $validRows = [];
        $skippedRows = [];
        $nameTracker = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            // 必須チェック
            if ($row['name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => 'テナント名が未入力です'];
                continue;
            }

            // CSV内重複チェック
            if (isset($nameTracker[$row['name']])) {
                $errors[] = ['row' => $rowNum, 'message' => "テナント名「{$row['name']}」がCSV内で重複しています（行{$nameTracker[$row['name']]})"];
                continue;
            }
            $nameTracker[$row['name']] = $rowNum;

            // DB既存チェック
            $existing = Customer::where('name', $row['name'])->first();
            if ($existing) {
                $skippedRows[] = ['row' => $rowNum, 'message' => "顧客「{$row['name']}」は既に登録済みのためスキップ"];
                continue;
            }

            // 種別チェック
            if ($row['customer_type'] !== '' && !isset($this->customerTypeMap[$row['customer_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "種別「{$row['customer_type']}」は不正な値です（法人/個人事業主/個人）"];
                continue;
            }

            // メールアドレスチェック
            if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowNum, 'message' => "メールアドレス「{$row['email']}」の形式が不正です"];
                continue;
            }

            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.tenant-import.index', [
                'activeTab'    => $tab,
                'preview'      => $tab,
                'totalRows'    => count($rows),
                'validCount'   => count($validRows),
                'errors'       => $errors,
                'skippedRows'  => $skippedRows,
                'summary'      => '顧客 ' . count($validRows) . '件を新規作成',
                'csvData'      => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $customerCodeNum = $this->getNextCustomerCodeNum();
            $created = 0;

            foreach ($validRows as $row) {
                $code = 'CUS-' . str_pad($customerCodeNum, 3, '0', STR_PAD_LEFT);
                $customerCodeNum++;

                Customer::create([
                    'code'           => $code,
                    'name'           => $row['name'],
                    'name_kana'      => $row['name_kana'] ?: null,
                    'customer_type'  => $row['customer_type'] !== ''
                        ? $this->customerTypeMap[$row['customer_type']]
                        : 'corporation',
                    'representative' => $row['representative'] ?: null,
                    'contact_person' => $row['contact_person'] ?: null,
                    'phone'          => $row['phone'] ?: null,
                    'email'          => $row['email'] ?: null,
                    'postal_code'    => $row['postal_code'] ?: null,
                    'address'        => $row['address'] ?: null,
                ]);
                $created++;
            }

            DB::commit();

            return redirect()->route('admin.tenant-import', ['tab' => $tab])
                ->with('success', "顧客インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ④ 契約インポート
    // ================================================================

    /**
     * 契約CSVインポート実行
     */
    public function executeContract(Request $request)
    {
        $columnMap = $this->contractColumnMap;
        $requiredKeys = ['property_name', 'room_number', 'customer_name', 'contract_date', 'rent'];
        $tab = 'contract';

        // CSV読み込み
        $result = $this->loadCsv($request, $columnMap, $requiredKeys);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // 行バリデーション
        $errors = [];
        $warnings = [];
        $validRows = [];
        $propertyCache = [];
        $customerCache = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            // 必須チェック
            if ($row['property_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];
                continue;
            }
            if ($row['room_number'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '部屋番号が未入力です'];
                continue;
            }
            if ($row['customer_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => 'テナント名が未入力です'];
                continue;
            }
            if ($row['contract_date'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '契約日が未入力です'];
                continue;
            }
            if ($row['rent'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '家賃が未入力です'];
                continue;
            }

            // 物件の存在チェック
            $propName = $row['property_name'];
            if (!isset($propertyCache[$propName])) {
                $prop = Property::where('name', $propName)
                    ->where('department', DepartmentCode::Tenant->value)
                    ->first();
                $propertyCache[$propName] = $prop;
            }
            if (!$propertyCache[$propName]) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」がシステムに登録されていません。先に物件インポートを実行してください"];
                continue;
            }
            $property = $propertyCache[$propName];

            // 区画の存在チェック（物件内で部屋番号を検索）
            $unit = Unit::where('property_id', $property->id)
                ->where('room_number', $row['room_number'])
                ->first();
            if (!$unit) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」に部屋番号「{$row['room_number']}」が見つかりません。先に区画インポートを実行してください"];
                continue;
            }

            // 顧客の存在チェック
            $custName = $row['customer_name'];
            if (!isset($customerCache[$custName])) {
                $cust = Customer::where('name', $custName)->first();
                $customerCache[$custName] = $cust;
            }
            if (!$customerCache[$custName]) {
                $errors[] = ['row' => $rowNum, 'message' => "顧客「{$custName}」がシステムに登録されていません。先に顧客インポートを実行してください"];
                continue;
            }
            $customer = $customerCache[$custName];

            // 二重契約チェック（警告のみ、インポートは許可）
            $activeContract = Contract::where('unit_id', $unit->id)
                ->where('status', ContractStatus::Active->value)
                ->first();
            if ($activeContract) {
                $warnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」には既にアクティブな契約（{$activeContract->contract_number}）が存在します"];
            }

            // 日付チェック
            $contractDate = $this->normalizeDate($row['contract_date']);
            if (!$contractDate) {
                $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                continue;
            }
            $row['contract_date'] = $contractDate;

            if ($row['rent_start_date'] !== '') {
                $rentStartDate = $this->normalizeDate($row['rent_start_date']);
                if (!$rentStartDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "賃料開始日「{$row['rent_start_date']}」の形式が不正です"];
                    continue;
                }
                $row['rent_start_date'] = $rentStartDate;
            }

            // 金額フィールドチェック
            $numericFields = [
                'rent' => '家賃', 'common_fee' => '共益費', 'deposit' => '敷金',
                'garbage_fee' => 'ゴミ代', 'pest_control_fee' => '駆除代',
            ];
            $numericError = false;
            foreach ($numericFields as $field => $label) {
                if ($row[$field] !== '') {
                    $val = str_replace(',', '', $row[$field]);
                    if (!is_numeric($val) || (int) $val < 0) {
                        $errors[] = ['row' => $rowNum, 'message' => "{$label}「{$row[$field]}」は不正な値です"];
                        $numericError = true;
                        break;
                    }
                    $row[$field] = (int) $val;
                }
            }
            if ($numericError) {
                continue;
            }

            $row['_property_id'] = $property->id;
            $row['_unit_id'] = $unit->id;
            $row['_customer_id'] = $customer->id;
            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.tenant-import.index', [
                'activeTab'    => $tab,
                'preview'      => $tab,
                'totalRows'    => count($rows),
                'validCount'   => count($validRows),
                'errors'       => $errors,
                'warnings'     => $warnings,
                'skippedRows'  => [],
                'summary'      => '契約 ' . count($validRows) . '件を新規作成',
                'csvData'      => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $contractCodeNum = $this->getNextContractCodeNum();
            $created = 0;

            foreach ($validRows as $row) {
                $year = now()->year;
                $contractNumber = "C-{$year}-" . str_pad($contractCodeNum, 3, '0', STR_PAD_LEFT);
                $contractCodeNum++;

                Contract::create([
                    'contract_number'    => $contractNumber,
                    'department'         => DepartmentCode::Tenant->value,
                    'property_id'        => $row['_property_id'],
                    'unit_id'            => $row['_unit_id'],
                    'customer_id'        => $row['_customer_id'],
                    'status'             => ContractStatus::Active->value,
                    'contract_date'      => $row['contract_date'],
                    'rent_start_date'    => $row['rent_start_date'] ?: null,
                    'rent'               => $row['rent'],
                    'common_fee'         => $row['common_fee'] !== '' ? $row['common_fee'] : 0,
                    'deposit'            => $row['deposit'] !== '' ? $row['deposit'] : 0,
                    'garbage_fee'        => $row['garbage_fee'] !== '' ? $row['garbage_fee'] : 0,
                    'pest_control_fee'   => $row['pest_control_fee'] !== '' ? $row['pest_control_fee'] : 0,
                    'store_name'         => $row['store_name'] ?: null,
                    'notes'              => $row['notes'] ?: null,
                    'initial_month_type' => 'full',
                    'final_month_type'   => 'full',
                ]);

                // 区画のステータスを入居中に更新
                Unit::where('id', $row['_unit_id'])->update(['status' => UnitStatus::Occupied->value]);

                $created++;
            }

            DB::commit();

            return redirect()->route('admin.tenant-import', ['tab' => $tab])
                ->with('success', "契約インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // テンプレートCSVダウンロード
    // ================================================================

    /**
     * 物件テンプレートCSVダウンロード
     */
    public function downloadPropertyTemplate()
    {
        $headers = array_keys($this->propertyColumnMap);

        $sample = [
            'サンプルビル', '790-0001', '愛媛県松山市一番町1-1', 'RC造', '2000-03', '3',
            '自社', '', '稼働中',
        ];

        return $this->buildCsvResponse($headers, [$sample], 'テナント物件インポートテンプレート.csv');
    }

    /**
     * 区画テンプレートCSVダウンロード
     */
    public function downloadUnitTemplate()
    {
        $headers = array_keys($this->unitColumnMap);

        $sample1 = ['サンプルビル', '1', 'A', '15.5', '店舗', '空室', '80000', '5000', '160000', '1000', '500'];
        $sample2 = ['サンプルビル', '2', 'B', '20.0', '事務所', '空室', '100000', '8000', '200000', '1500', '500'];

        return $this->buildCsvResponse($headers, [$sample1, $sample2], 'テナント区画インポートテンプレート.csv');
    }

    /**
     * 顧客テンプレートCSVダウンロード
     */
    public function downloadCustomerTemplate()
    {
        $headers = array_keys($this->customerColumnMap);

        $sample = [
            'サンプル商事', 'サンプルショウジ', '法人', '山田太郎', '鈴木花子',
            '089-999-9999', 'info@sample.co.jp', '790-0002', '愛媛県松山市二番町2-2',
        ];

        return $this->buildCsvResponse($headers, [$sample], 'テナント顧客インポートテンプレート.csv');
    }

    /**
     * 契約テンプレートCSVダウンロード
     */
    public function downloadContractTemplate()
    {
        $headers = array_keys($this->contractColumnMap);

        $sample = [
            'サンプルビル', 'A', 'サンプル商事',
            '2024-04-01', '2024-04-01',
            '95000', '8000', '190000', '1500', '500',
            'サンプル商事 松山支店', '',
        ];

        return $this->buildCsvResponse($headers, [$sample], 'テナント契約インポートテンプレート.csv');
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * CSVファイルの読み込み共通処理
     *
     * @return array|RedirectResponse [rows配列, content文字列] またはリダイレクト
     */
    private function loadCsv(Request $request, array $columnMap, array $requiredKeys)
    {
        // 確認済みの場合はbase64からCSVを復元
        if ($request->boolean('confirmed')) {
            $content = base64_decode($request->input('csv_data', ''));
        } else {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $file = $request->file('csv_file');
            $content = file_get_contents($file->getRealPath());

            // Shift_JIS自動判定→UTF-8変換
            $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            // BOM除去
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        }

        $lines = array_values(array_filter(explode("\n", $content), function ($line) {
            return trim($line) !== '';
        }));

        if (count($lines) < 2) {
            return back()->with('error', 'CSVファイルにデータがありません。');
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);

        // ヘッダー→内部キーのインデックスマッピング
        $colIndex = [];
        foreach ($header as $idx => $headerName) {
            if (isset($columnMap[$headerName])) {
                $colIndex[$columnMap[$headerName]] = $idx;
            }
        }

        // 必須ヘッダーチェック
        foreach ($requiredKeys as $key) {
            if (!isset($colIndex[$key])) {
                $jpName = array_search($key, $columnMap);
                return back()->with('error', "必須ヘッダー「{$jpName}」がCSVに見つかりません。");
            }
        }

        // 行データ抽出
        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row = [];
            foreach ($columnMap as $jpName => $key) {
                $idx = $colIndex[$key] ?? -1;
                $row[$key] = ($idx >= 0 && isset($cols[$idx])) ? trim($cols[$idx]) : '';
            }
            $rows[] = $row;
        }

        return [$rows, $content];
    }

    /**
     * 日付文字列を正規化（YYYY-MM-DD）
     */
    private function normalizeDate(string $value): ?string
    {
        $value = str_replace('/', '-', $value);
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) && strtotime($value)) {
            $parts = explode('-', $value);
            return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
        }
        return null;
    }

    /**
     * 配列をCSV行に変換
     */
    private function toCsvLine(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $f) {
            $escaped[] = '"' . str_replace('"', '""', $f) . '"';
        }
        return implode(',', $escaped) . "\n";
    }

    /**
     * CSVレスポンスを生成
     */
    private function buildCsvResponse(array $headers, array $sampleRows, string $filename): \Illuminate\Http\Response
    {
        $bom = "\xEF\xBB\xBF";
        $csv = $bom;
        $csv .= $this->toCsvLine($headers);
        foreach ($sampleRows as $sample) {
            $csv .= $this->toCsvLine($sample);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * 物件コードの次の番号を取得
     */
    private function getNextPropertyCodeNum(): int
    {
        $lastCode = Property::withTrashed()
            ->where('department', DepartmentCode::Tenant->value)
            ->where('code', 'like', 'T-%')
            ->orderByDesc('code')
            ->value('code');

        if ($lastCode) {
            return (int) substr($lastCode, 2) + 1;
        }
        return 1;
    }

    /**
     * 顧客コードの次の番号を取得
     */
    private function getNextCustomerCodeNum(): int
    {
        $lastCode = Customer::withTrashed()
            ->where('code', 'like', 'CUS-%')
            ->orderByDesc('code')
            ->value('code');

        if ($lastCode) {
            return (int) substr($lastCode, 4) + 1;
        }
        return 1;
    }

    /**
     * 契約番号の次の番号を取得（年ベース）
     */
    private function getNextContractCodeNum(): int
    {
        $year = now()->year;
        $prefix = "C-{$year}-";

        $lastNumber = Contract::withTrashed()
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByDesc('contract_number')
            ->value('contract_number');

        if ($lastNumber) {
            return (int) substr($lastNumber, -3) + 1;
        }
        return 1;
    }
}
