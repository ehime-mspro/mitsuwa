<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\CustomerType;
use App\Enums\DepartmentCode;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Unit;
use App\Support\CsvDate;
use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use App\Support\CsvImportTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * テナントCSVインポートコントローラ
 *
 * 5種類の個別CSVインポート機能を提供:
 * ① 物件インポート ② 区画インポート ③ 顧客インポート ④ 契約インポート（現契約）
 * ⑤ 過去契約インポート（解約済み契約の一括取込）
 */
class TenantImportController extends Controller
{
    // ================================================================
    // Enum日本語→DB値マッピング
    // ================================================================

    private array $ownerTypeMap = ['自社' => 'self_owned', 'オーナー' => 'owner'];
    private array $operationStatusMap = ['稼働中' => 'active', '停止中' => 'inactive'];
    // usageTypeMap は廃止 → マスターテーブルから動的取得
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
        '階'         => 'floor',           // 任意カラム。同一物件で同じ部屋番号が複数階に存在する場合に必須
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

    /**
     * 過去契約 CSV カラムマップ（解約済み契約の一括取込用）
     * - 解約日 が必須。これがあるので status=terminated として登録
     * - テナント名 が必須。マスタになければ自動作成（注意: 同名顧客があれば再利用）
     * - 既存「契約」と異なり、Unit.status は更新しない（過去契約なので現状に影響なし）
     */
    private array $pastContractColumnMap = [
        '物件名'     => 'property_name',
        '階'         => 'floor',
        '部屋番号'   => 'room_number',
        'テナント名' => 'customer_name',
        '契約日'     => 'contract_date',
        '賃料開始日' => 'rent_start_date',
        '解約日'     => 'contract_end_date',
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
                'rowErrors'    => $errors,
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

        // 用途マスターのname→idマッピングを取得
        $usageTypeMap = InquiryUsageType::pluck('id', 'name')->toArray();

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

            // 用途マスターチェック
            if ($row['usage_type'] !== '' && !isset($usageTypeMap[$row['usage_type']])) {
                $validNames = implode('/', array_keys($usageTypeMap));
                $errors[] = ['row' => $rowNum, 'message' => "用途「{$row['usage_type']}」は不正な値です（{$validNames}）"];
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

            // 階チェック（地下-3〜-1、地上1〜99。0は不可）
            if ($row['floor'] !== '') {
                $floorVal = $row['floor'];
                if (!preg_match('/^-?\d+$/', $floorVal)) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$floorVal}」は不正な値です"];
                    continue;
                }
                $floorInt = (int) $floorVal;
                if ($floorInt === 0 || $floorInt < -3 || $floorInt > 99) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$floorVal}」は-3〜-1または1〜99の範囲で入力してください"];
                    continue;
                }
                $row['floor'] = $floorInt;
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
                'rowErrors'    => $errors,
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
                    'usage_type_id'    => $row['usage_type'] !== '' ? $usageTypeMap[$row['usage_type']] : null,
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
                'rowErrors'    => $errors,
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
        // customer_name は任意（新規登録画面の customer_id が nullable のため）
        $requiredKeys = ['property_name', 'room_number', 'contract_date', 'rent'];
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
            // テナント名は任意（新規登録画面と同じ仕様）
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

            // 階数バリデーション（空欄 OK、整数なら可、負数 = 地下も許可）
            $floor = null;
            if ($row['floor'] !== '') {
                if (!preg_match('/^-?\d+$/', $row['floor'])) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$row['floor']}」は整数で入力してください"];
                    continue;
                }
                $floor = (int) $row['floor'];
            }

            // 区画の存在チェック（display_name で完全一致。区画インポートと同じ規約）
            $displayName = Unit::generateDisplayName($floor, $row['room_number']);
            $unit = Unit::where('property_id', $property->id)
                ->where('display_name', $displayName)
                ->first();
            if (!$unit) {
                $floorLabel = $floor !== null ? "{$floor}階の" : '';
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」に{$floorLabel}部屋番号「{$row['room_number']}」（区画名「{$displayName}」）が見つかりません。先に区画インポートを実行してください"];
                continue;
            }

            // 顧客の存在チェック（テナント名が空欄なら customer なしで契約作成）
            $customer = null;
            if ($row['customer_name'] !== '') {
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
            }

            // 二重契約チェック（警告のみ、インポートは許可）
            $activeContract = Contract::where('unit_id', $unit->id)
                ->where('status', ContractStatus::Active->value)
                ->first();
            if ($activeContract) {
                $warnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」には既にアクティブな契約（{$activeContract->contract_number}）が存在します"];
            }

            // 日付チェック
            $contractDate = CsvDate::normalize($row['contract_date']);
            if (!$contractDate) {
                $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                continue;
            }
            $row['contract_date'] = $contractDate;

            if ($row['rent_start_date'] !== '') {
                $rentStartDate = CsvDate::normalize($row['rent_start_date']);
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
            $row['_customer_id'] = $customer?->id;  // customer_name 空欄なら null
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
                'rowErrors'    => $errors,
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

    /**
     * 過去契約のインポート実行（プレビュー / 確定）
     *
     * 既存「契約」インポートとの違い:
     * - 解約日が必須（status=terminated として登録）
     * - テナント名が必須。マスタになければ自動作成（同名顧客は再利用）
     * - Unit.status は更新しない（過去契約なので現状に影響なし）
     * - 契約番号は「契約日の年」で採番（C-{契約日年}-XXX）
     * - 同一区画に期間が重なる契約があっても警告のみで取込実行
     */
    public function executePastContract(Request $request)
    {
        $columnMap = $this->pastContractColumnMap;
        $requiredKeys = ['property_name', 'room_number', 'customer_name', 'contract_date', 'contract_end_date'];
        $tab = 'past-contract';

        // CSV 読み込み
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
        $customerCache = [];        // 既存顧客のキャッシュ
        $customerCreateList = [];   // 自動作成予定の顧客名リスト

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
                $errors[] = ['row' => $rowNum, 'message' => 'テナント名が未入力です（過去契約はテナント名必須）'];
                continue;
            }
            if ($row['contract_date'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '契約日が未入力です'];
                continue;
            }
            if ($row['contract_end_date'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '解約日が未入力です（過去契約は解約日必須）'];
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

            // 階数バリデーション（空欄 OK、整数なら可、負数 = 地下も許可）
            $floor = null;
            if ($row['floor'] !== '') {
                if (!preg_match('/^-?\d+$/', $row['floor'])) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$row['floor']}」は整数で入力してください"];
                    continue;
                }
                $floor = (int) $row['floor'];
            }

            // 区画の存在チェック
            $displayName = Unit::generateDisplayName($floor, $row['room_number']);
            $unit = Unit::where('property_id', $property->id)
                ->where('display_name', $displayName)
                ->first();
            if (!$unit) {
                $floorLabel = $floor !== null ? "{$floor}階の" : '';
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」に{$floorLabel}部屋番号「{$row['room_number']}」（区画名「{$displayName}」）が見つかりません。先に区画インポートを実行してください"];
                continue;
            }

            // 日付チェック
            $contractDate = CsvDate::normalize($row['contract_date']);
            if (!$contractDate) {
                $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                continue;
            }
            $row['contract_date'] = $contractDate;

            $endDate = CsvDate::normalize($row['contract_end_date']);
            if (!$endDate) {
                $errors[] = ['row' => $rowNum, 'message' => "解約日「{$row['contract_end_date']}」の形式が不正です（YYYY-MM-DD）"];
                continue;
            }
            $row['contract_end_date'] = $endDate;

            // 解約日 >= 契約日
            if ($endDate < $contractDate) {
                $errors[] = ['row' => $rowNum, 'message' => "解約日（{$endDate}）が契約日（{$contractDate}）より前です"];
                continue;
            }

            // 解約日が今日より未来 → 警告（過去契約のはず）
            if ($endDate > now()->format('Y-m-d')) {
                $warnings[] = ['row' => $rowNum, 'message' => "解約日（{$endDate}）が今日より未来です（過去契約として登録します）"];
            }

            // 賃料開始日チェック（契約日 〜 解約日 の範囲内）
            if ($row['rent_start_date'] !== '') {
                $rentStartDate = CsvDate::normalize($row['rent_start_date']);
                if (!$rentStartDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "賃料開始日「{$row['rent_start_date']}」の形式が不正です"];
                    continue;
                }
                if ($rentStartDate < $contractDate || $rentStartDate > $endDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "賃料開始日（{$rentStartDate}）は契約日〜解約日の範囲内である必要があります"];
                    continue;
                }
                $row['rent_start_date'] = $rentStartDate;
            }

            // 顧客の存在チェック（なければ自動作成予定リストに追加）
            $custName = $row['customer_name'];
            if (!isset($customerCache[$custName])) {
                $cust = Customer::where('name', $custName)->first();
                $customerCache[$custName] = $cust;
            }
            $customerWillBeCreated = false;
            if (!$customerCache[$custName]) {
                $customerWillBeCreated = true;
                if (!in_array($custName, $customerCreateList, true)) {
                    $customerCreateList[] = $custName;
                }
            }

            // 期間重なりチェック（active 契約のみ対象、警告のみで取込は実行）
            // 過去契約同士の重なりは検出しない（データ移行時にノイズになるため）。
            // 同じ区画に「現在も使われている契約」がある場合のみ管理者に通知する。
            $hasActiveOverlap = Contract::where('unit_id', $unit->id)
                ->where('status', ContractStatus::Active->value)
                ->where('contract_date', '<=', $endDate)
                ->where(function ($q) use ($contractDate) {
                    $q->whereNull('contract_end_date')
                      ->orWhere('contract_end_date', '>=', $contractDate);
                })
                ->exists();
            if ($hasActiveOverlap) {
                $warnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」に期間が重なるアクティブ契約があります（取込は実行）"];
            }

            // 金額フィールドチェック（過去契約は家賃も任意。データ移行で空欄ありえる）
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
            $row['_customer_id'] = $customerCache[$custName]?->id; // null なら実行時に作成
            $row['_customer_will_be_created'] = $customerWillBeCreated;
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
                'rowErrors'    => $errors,
                'warnings'     => $warnings,
                'skippedRows'  => [],
                'summary'      => '過去契約 ' . count($validRows) . '件を新規作成'
                    . (count($customerCreateList) > 0 ? '（顧客 ' . count($customerCreateList) . '件を自動作成: ' . implode('、', array_slice($customerCreateList, 0, 5)) . (count($customerCreateList) > 5 ? ' ...' : '') . '）' : ''),
                'csvData'      => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $customerCodeNum = $this->getNextCustomerCodeNum();
            $createdContracts = 0;
            $createdCustomers = 0;

            // 顧客の自動作成（行をまたいで同名は再利用するためキャッシュを使う）
            $customerIdByName = [];
            foreach ($customerCache as $name => $cust) {
                if ($cust) {
                    $customerIdByName[$name] = $cust->id;
                }
            }

            // 年ごとの契約番号採番カウンター
            $contractCodeNumByYear = [];

            foreach ($validRows as $row) {
                $custName = $row['customer_name'];

                // 顧客が未作成なら作成
                if (!isset($customerIdByName[$custName])) {
                    $code = 'CUS-' . str_pad($customerCodeNum, 3, '0', STR_PAD_LEFT);
                    $customerCodeNum++;
                    $newCust = Customer::create([
                        'code'           => $code,
                        'name'           => $custName,
                        'customer_type'  => CustomerType::Individual->value,
                        'notes'          => 'CSV一括取込（過去契約）で自動作成',
                    ]);
                    $customerIdByName[$custName] = $newCust->id;
                    $createdCustomers++;
                }

                // 契約番号（契約日の年で採番）
                $year = substr($row['contract_date'], 0, 4);
                if (!isset($contractCodeNumByYear[$year])) {
                    $contractCodeNumByYear[$year] = $this->getNextContractCodeNumForYear((int) $year);
                }
                $contractNumber = "C-{$year}-" . str_pad($contractCodeNumByYear[$year], 3, '0', STR_PAD_LEFT);
                $contractCodeNumByYear[$year]++;

                Contract::create([
                    'contract_number'    => $contractNumber,
                    'department'         => DepartmentCode::Tenant->value,
                    'property_id'        => $row['_property_id'],
                    'unit_id'            => $row['_unit_id'],
                    'customer_id'        => $customerIdByName[$custName],
                    'status'             => ContractStatus::Terminated->value,
                    'contract_date'      => $row['contract_date'],
                    'rent_start_date'    => $row['rent_start_date'] ?: null,
                    'contract_end_date'  => $row['contract_end_date'],
                    'rent'               => $row['rent'] !== '' ? $row['rent'] : 0,
                    'common_fee'         => $row['common_fee'] !== '' ? $row['common_fee'] : 0,
                    'deposit'            => $row['deposit'] !== '' ? $row['deposit'] : 0,
                    'garbage_fee'        => $row['garbage_fee'] !== '' ? $row['garbage_fee'] : 0,
                    'pest_control_fee'   => $row['pest_control_fee'] !== '' ? $row['pest_control_fee'] : 0,
                    'store_name'         => $row['store_name'] ?: null,
                    'notes'              => $row['notes'] ?: null,
                    'initial_month_type' => 'full',
                    'final_month_type'   => 'full',
                ]);

                // 過去契約: Unit.status は更新しない（現状の入居状態に影響させない）
                $createdContracts++;
            }

            DB::commit();

            $msg = "過去契約インポート完了: 契約 {$createdContracts}件を登録";
            if ($createdCustomers > 0) {
                $msg .= "、顧客 {$createdCustomers}件を自動作成";
            }
            return redirect()->route('admin.tenant-import', ['tab' => $tab])
                ->with('success', $msg);

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

        return CsvImportTemplate::response($headers, [$sample], 'テナント物件インポートテンプレート.csv');
    }

    /**
     * 区画テンプレートCSVダウンロード
     */
    public function downloadUnitTemplate()
    {
        $headers = array_keys($this->unitColumnMap);

        $sample1 = ['サンプルビル', '1', 'A', '15.5', '店舗', '空室', '80000', '5000', '160000', '1000', '500'];
        $sample2 = ['サンプルビル', '2', 'B', '20.0', '事務所', '空室', '100000', '8000', '200000', '1500', '500'];

        return CsvImportTemplate::response($headers, [$sample1, $sample2], 'テナント区画インポートテンプレート.csv');
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

        return CsvImportTemplate::response($headers, [$sample], 'テナント顧客インポートテンプレート.csv');
    }

    /**
     * 契約テンプレートCSVダウンロード
     */
    public function downloadContractTemplate()
    {
        $headers = array_keys($this->contractColumnMap);

        // 13 要素: 物件名, 階, 部屋番号, テナント名, 契約日, 賃料開始日, 家賃, 共益費, 敷金, ゴミ代, 駆除代, 屋号, 備考
        $sample = [
            'サンプルビル', '2', 'A', 'サンプル商事',
            '2024-04-01', '2024-04-01',
            '95000', '8000', '190000', '1500', '500',
            'サンプル商事 松山支店', '',
        ];

        return CsvImportTemplate::response($headers, [$sample], 'テナント契約インポートテンプレート.csv');
    }

    /**
     * 過去契約テンプレートCSVダウンロード（解約済み契約の一括取込用）
     */
    public function downloadPastContractTemplate()
    {
        $headers = array_keys($this->pastContractColumnMap);

        // 14 要素: 物件名, 階, 部屋番号, テナント名, 契約日, 賃料開始日, 解約日, 家賃, 共益費, 敷金, ゴミ代, 駆除代, 屋号, 備考
        $sample = [
            'サンプルビル', '2', 'A', '過去商事',
            '2020-04-01', '2020-04-01', '2023-03-31',
            '95000', '8000', '190000', '1500', '500',
            '過去商事 松山支店', '期間満了で解約',
        ];

        return CsvImportTemplate::response($headers, [$sample], 'テナント過去契約インポートテンプレート.csv');
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * CSV を読み込んで行配列にする。
     *
     * 純粋な読み取りは [[\App\Support\CsvImportReader]] にある。ここに残るのは
     * HTTP 依存の 3 つだけ: ファイル取得 / 確定時の base64 復元 / 差し戻し。
     *
     * @return array{0: list<array<string, string>>, 1: string}|\Illuminate\Http\RedirectResponse
     */
    private function loadCsv(Request $request, array $columnMap, array $requiredKeys)
    {
        if ($request->boolean('confirmed')) {
            // 確認画面が持ち回った base64 から復元（既に UTF-8・BOM 除去済み）
            $content = base64_decode($request->input('csv_data', ''));
        } else {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);

            $content = CsvImportReader::decode(
                file_get_contents($request->file('csv_file')->getRealPath())
            );
        }

        try {
            $rows = CsvImportReader::parse($content, $columnMap, $requiredKeys);
        } catch (CsvImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        return [$rows, $content];
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
        return $this->getNextContractCodeNumForYear((int) now()->year);
    }

    /**
     * 指定年の契約番号の次の番号を取得
     *
     * 文字列ソート（"C-2020-1000" < "C-2020-999"）の罠を避けるため、
     * LENGTH 優先のソート + prefix 後の全桁を数値化する。
     *
     * 注意: 並行取込で同じ番号を取得するレースコンディションあり。
     * CSV 一括取込は管理者操作で並行発生は想定しないが、複数管理者が
     * 同時に「契約タブ」と「過去契約タブ」を実行すると contract_number_unique
     * 制約違反になる可能性がある。実運用上は注意喚起のみで対応。
     */
    private function getNextContractCodeNumForYear(int $year): int
    {
        $prefix = "C-{$year}-";

        // 4桁以上の連番でも壊れないように LENGTH 優先ソート
        $lastNumber = Contract::withTrashed()
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(contract_number) DESC, contract_number DESC')
            ->value('contract_number');

        if ($lastNumber) {
            // prefix 後の連番部分（"C-2020-999" → "999"、"C-2020-1000" → "1000"）
            $tail = substr($lastNumber, strlen($prefix));
            return ((int) $tail) + 1;
        }
        return 1;
    }
}
