<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MsContractStatus;
use App\Enums\MsOwnershipType;
use App\Enums\MsParkingStatus;
use App\Enums\MsRoomStatus;
use App\Enums\MsTenantType;
use App\Http\Controllers\Controller;
use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use App\Models\User;
use App\Support\CsvDate;
use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use App\Support\CsvImportTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 賃貸マンション CSVインポートコントローラ
 *
 * 6種類の個別CSVインポート機能を提供:
 * ① 物件 (ms_properties) ② 部屋 (ms_rooms) ③ 駐車場 (ms_parkings)
 * ④ 入居者 (ms_tenants) ⑤ 部屋契約 (ms_contracts) ⑥ 駐車場契約 (ms_parking_contracts)
 *
 * テナントCSVインポートと同じ UX パターン（プレビュー → 確認実行）。
 * 各エンティティは1トランザクションでコミット。エラー行はスキップ、
 * 正常行のみインポート続行。Enum は日本語ラベル → DB 値で受付。
 */
class MansionImportController extends Controller
{
    // ================================================================
    // Enum 日本語 → DB値マッピング
    // ================================================================

    /** 所有区分: 自社所有 / 管理受託 */
    private array $ownershipTypeMap = [
        '自社所有' => 'self_owned',
        '管理受託' => 'managed',
    ];

    /** 部屋ステータス */
    private array $roomStatusMap = [
        '空室'           => 'vacant',
        '入居中'         => 'occupied',
        '申込み・仮押え' => 'negotiating',
        '退去予定'       => 'move_out_planned',
    ];

    /** 駐車場ステータス */
    private array $parkingStatusMap = [
        '空き'   => 'vacant',
        '使用中' => 'occupied',
    ];

    /** 入居者区分 */
    private array $tenantTypeMap = [
        '入居者'           => 'resident',
        '駐車場利用のみ'   => 'parking_only',
    ];

    /**
     * 屋根あり判定マップ。
     * 「有」「あり」「1」「true」などを true、それ以外（未入力含む）を false 扱い。
     */
    private array $hasRoofMap = [
        '有'    => true,
        'あり'  => true,
        '1'     => true,
        'true'  => true,
        'TRUE'  => true,
        '無'    => false,
        'なし'  => false,
        '0'     => false,
        'false' => false,
        'FALSE' => false,
    ];

    // ================================================================
    // 各種別のカラムマッピング（日本語ヘッダー → 内部キー）
    // ================================================================

    /** ① 物件CSVカラム */
    private array $propertyColumnMap = [
        '物件名'     => 'name',
        '所有区分'   => 'ownership_type',
        'オーナー名' => 'owner_name',
        '郵便番号'   => 'postal_code',
        '住所'       => 'address',
        '総戸数'     => 'total_units',
        '階数'       => 'total_floors',
        '構造'       => 'structure',
        '築年月'     => 'built_year_month',
        '備考'       => 'notes',
    ];

    /** ② 部屋CSVカラム */
    private array $roomColumnMap = [
        '物件名'   => 'property_name',
        '部屋番号' => 'room_number',
        '階'       => 'floor',
        '間取り'   => 'room_type',
        '面積(㎡)' => 'area_sqm',
        '状態'     => 'status',
        '家賃'     => 'rent',
        '共益費'   => 'common_fee',
        '敷金'     => 'deposit',
        '礼金'     => 'key_money',
        '備考'     => 'notes',
    ];

    /** ③ 駐車場CSVカラム */
    private array $parkingColumnMap = [
        '物件名'     => 'property_name',
        '駐車場番号' => 'parking_number',
        '月額料金'   => 'monthly_fee',
        '状態'       => 'status',
        '屋根あり'   => 'has_roof',
        '備考'       => 'notes',
    ];

    /** ④ 入居者CSVカラム */
    private array $tenantColumnMap = [
        '区分'             => 'tenant_type',
        '氏名'             => 'name',
        '電話番号'         => 'phone',
        'メールアドレス'   => 'email',
        '勤務先'           => 'workplace',
        '緊急連絡先氏名'   => 'emergency_contact_name',
        '緊急連絡先電話'   => 'emergency_contact_phone',
        '続柄'             => 'emergency_contact_relation',
        '備考'             => 'notes',
    ];

    /** ⑤ 部屋契約CSVカラム */
    private array $roomContractColumnMap = [
        '物件名'             => 'property_name',
        '部屋番号'           => 'room_number',
        '入居者名'           => 'tenant_name',
        '契約日'             => 'contract_date',
        '入居日'             => 'move_in_date',
        '退去日'             => 'move_out_date',
        '家賃'               => 'rent',
        '共益費'             => 'common_fee',
        '敷金'               => 'deposit',
        '礼金'               => 'key_money',
        '担当者ユーザー名'   => 'staff_user_name',
        'メモ'               => 'memo',
    ];

    /** ⑥ 駐車場契約CSVカラム */
    private array $parkingContractColumnMap = [
        '物件名'             => 'property_name',
        '駐車場番号'         => 'parking_number',
        '入居者名'           => 'tenant_name',
        '紐付部屋番号'       => 'linked_room_number',
        '契約日'             => 'contract_date',
        '開始日'             => 'start_date',
        '終了日'             => 'end_date',
        '月額料金'           => 'monthly_fee',
        '敷金'               => 'deposit',
        '担当者ユーザー名'   => 'staff_user_name',
        'メモ'               => 'memo',
    ];

    // ================================================================
    // 画面表示
    // ================================================================

    /**
     * インポート画面表示。
     * `selected_tab` クエリでタブの初期選択を切り替える。
     */
    public function showForm(Request $request)
    {
        return view('admin.mansion-import.index', [
            'activeTab' => $request->query('selected_tab', 'property'),
        ]);
    }

    // ================================================================
    // ① 物件インポート
    // ================================================================

    /**
     * 物件CSVインポート実行（プレビュー＋確認の2段階）。
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

            // 文字数チェック
            if (mb_strlen($row['name']) > 100) {
                $errors[] = ['row' => $rowNum, 'message' => '物件名は100文字以内で入力してください'];
                continue;
            }
            if (mb_strlen($row['address']) > 200) {
                $errors[] = ['row' => $rowNum, 'message' => '住所は200文字以内で入力してください'];
                continue;
            }

            // CSV内重複チェック
            if (isset($nameTracker[$row['name']])) {
                $errors[] = ['row' => $rowNum, 'message' => "物件名「{$row['name']}」がCSV内で重複しています（行{$nameTracker[$row['name']]})"];
                continue;
            }
            $nameTracker[$row['name']] = $rowNum;

            // DB既存チェック
            $existing = MsProperty::where('property_name', $row['name'])->first();
            if ($existing) {
                $skippedRows[] = ['row' => $rowNum, 'message' => "物件「{$row['name']}」は既に登録済みのためスキップ"];
                continue;
            }

            // Enum値チェック（所有区分）
            if ($row['ownership_type'] !== '' && !isset($this->ownershipTypeMap[$row['ownership_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "所有区分「{$row['ownership_type']}」は不正な値です（自社所有/管理受託）"];
                continue;
            }

            // 築年月チェック（YYYY-MM形式）
            if ($row['built_year_month'] !== '') {
                $val = str_replace('/', '-', $row['built_year_month']);
                if (!preg_match('/^\d{4}-\d{1,2}$/', $val)) {
                    $errors[] = ['row' => $rowNum, 'message' => "築年月「{$row['built_year_month']}」の形式が不正です（YYYY-MM）"];
                    continue;
                }
                // ゼロパディング
                $parts = explode('-', $val);
                $row['built_year_month'] = sprintf('%04d-%02d', $parts[0], $parts[1]);
            }

            // 整数チェック（総戸数）
            if ($row['total_units'] !== '') {
                if (!ctype_digit($row['total_units']) || (int) $row['total_units'] < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "総戸数「{$row['total_units']}」は0以上の整数で入力してください"];
                    continue;
                }
                $row['total_units'] = (int) $row['total_units'];
            }
            // 整数チェック（階数）
            if ($row['total_floors'] !== '') {
                if (!ctype_digit($row['total_floors']) || (int) $row['total_floors'] < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "階数「{$row['total_floors']}」は0以上の整数で入力してください"];
                    continue;
                }
                $row['total_floors'] = (int) $row['total_floors'];
            }

            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.mansion-import.index', [
                'activeTab'   => $tab,
                'preview'     => $tab,
                'totalRows'   => count($rows),
                'validCount'  => count($validRows),
                'rowErrors'   => $errors,
                'skippedRows' => $skippedRows,
                'summary'     => '物件 ' . count($validRows) . '件を新規作成',
                'csvData'     => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $propertyCodeNum = $this->getNextPropertyCodeNum();
            $created = 0;

            foreach ($validRows as $row) {
                $code = sprintf('MS-%03d', $propertyCodeNum);
                $propertyCodeNum++;

                $ownershipType = $row['ownership_type'] !== ''
                    ? $this->ownershipTypeMap[$row['ownership_type']]
                    : MsOwnershipType::SelfOwned->value;

                MsProperty::create([
                    'property_code'    => $code,
                    'property_name'    => $row['name'],
                    'ownership_type'   => $ownershipType,
                    'owner_name'       => $ownershipType === 'managed' ? ($row['owner_name'] ?: null) : null,
                    'postal_code'      => $row['postal_code'] ?: null,
                    'address'          => $row['address'],
                    'total_units'      => $row['total_units'] !== '' ? $row['total_units'] : null,
                    'total_floors'     => $row['total_floors'] !== '' ? $row['total_floors'] : null,
                    'structure'        => $row['structure'] ?: null,
                    'built_year_month' => $row['built_year_month'] ?: null,
                    'notes'            => $row['notes'] ?: null,
                    'created_by'       => Auth::id(),
                ]);
                $created++;
            }

            DB::commit();

            return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                ->with('success', "物件インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ② 部屋インポート
    // ================================================================

    /**
     * 部屋CSVインポート実行。
     * インポート後、対象 property_id ごとに total_units を再集計。
     */
    public function executeRoom(Request $request)
    {
        $columnMap = $this->roomColumnMap;
        $requiredKeys = ['property_name', 'room_number'];
        $tab = 'room';

        // CSV読み込み
        $result = $this->loadCsv($request, $columnMap, $requiredKeys);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // 行バリデーション
        $errors = [];
        $validRows = [];
        $roomTracker = [];
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
                $propertyCache[$propName] = MsProperty::where('property_name', $propName)->first();
            }
            if (!$propertyCache[$propName]) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」がシステムに登録されていません。先に物件インポートを実行してください"];
                continue;
            }

            // CSV内重複チェック（物件名+部屋番号）
            $roomKey = $propName . '|' . $row['room_number'];
            if (isset($roomTracker[$roomKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の部屋番号「{$row['room_number']}」がCSV内で重複しています（行{$roomTracker[$roomKey]}）"];
                continue;
            }
            $roomTracker[$roomKey] = $rowNum;

            // DB重複チェック（同一物件+部屋番号 UNIQUE）
            $property = $propertyCache[$propName];
            $existingRoom = MsRoom::where('property_id', $property->id)
                ->where('room_number', $row['room_number'])
                ->first();
            if ($existingRoom) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の部屋「{$row['room_number']}」は既に登録されています"];
                continue;
            }

            // 部屋番号文字数
            if (mb_strlen($row['room_number']) > 20) {
                $errors[] = ['row' => $rowNum, 'message' => '部屋番号は20文字以内で入力してください'];
                continue;
            }

            // ステータス
            if ($row['status'] !== '' && !isset($this->roomStatusMap[$row['status']])) {
                $errors[] = ['row' => $rowNum, 'message' => "状態「{$row['status']}」は不正な値です（空室/入居中/申込み・仮押え/退去予定）"];
                continue;
            }

            // 階チェック
            if ($row['floor'] !== '') {
                if (!ctype_digit($row['floor']) || (int) $row['floor'] < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$row['floor']}」は0以上の整数で入力してください"];
                    continue;
                }
                $row['floor'] = (int) $row['floor'];
            }

            // 面積チェック
            if ($row['area_sqm'] !== '') {
                $val = str_replace(',', '', $row['area_sqm']);
                if (!is_numeric($val) || (float) $val < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "面積「{$row['area_sqm']}」は不正な値です"];
                    continue;
                }
                $row['area_sqm'] = (float) $val;
            }

            // 金額フィールドチェック
            $numericFields = [
                'rent'       => '家賃',
                'common_fee' => '共益費',
                'deposit'    => '敷金',
                'key_money'  => '礼金',
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
            return view('admin.mansion-import.index', [
                'activeTab'   => $tab,
                'preview'     => $tab,
                'totalRows'   => count($rows),
                'validCount'  => count($validRows),
                'rowErrors'   => $errors,
                'skippedRows' => [],
                'summary'     => '部屋 ' . count($validRows) . '件を新規作成',
                'csvData'     => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $created = 0;
            $touchedPropertyIds = [];

            foreach ($validRows as $row) {
                MsRoom::create([
                    'property_id' => $row['_property_id'],
                    'room_number' => $row['room_number'],
                    'floor'       => $row['floor'] !== '' ? $row['floor'] : null,
                    'room_type'   => $row['room_type'] ?: null,
                    'area_sqm'    => $row['area_sqm'] !== '' ? $row['area_sqm'] : null,
                    'status'      => $row['status'] !== ''
                        ? $this->roomStatusMap[$row['status']]
                        : MsRoomStatus::Vacant->value,
                    'rent'        => $row['rent'] !== '' ? $row['rent'] : null,
                    'common_fee'  => $row['common_fee'] !== '' ? $row['common_fee'] : null,
                    'deposit'     => $row['deposit'] !== '' ? $row['deposit'] : null,
                    'key_money'   => $row['key_money'] !== '' ? $row['key_money'] : null,
                    'notes'       => $row['notes'] ?: null,
                ]);
                $created++;
                $touchedPropertyIds[$row['_property_id']] = true;
            }

            // total_units 再集計
            foreach (array_keys($touchedPropertyIds) as $propId) {
                $count = MsRoom::where('property_id', $propId)->count();
                MsProperty::where('id', $propId)->update(['total_units' => $count]);
            }

            DB::commit();

            return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                ->with('success', "部屋インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ③ 駐車場インポート
    // ================================================================

    /**
     * 駐車場CSVインポート実行。
     * monthly_fee は NOT NULL カラムのため必須。
     */
    public function executeParking(Request $request)
    {
        $columnMap = $this->parkingColumnMap;
        $requiredKeys = ['property_name', 'parking_number', 'monthly_fee'];
        $tab = 'parking';

        // CSV読み込み
        $result = $this->loadCsv($request, $columnMap, $requiredKeys);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // 行バリデーション
        $errors = [];
        $validRows = [];
        $parkingTracker = [];
        $propertyCache = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            // 必須チェック
            if ($row['property_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];
                continue;
            }
            if ($row['parking_number'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '駐車場番号が未入力です'];
                continue;
            }
            if ($row['monthly_fee'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '月額料金が未入力です'];
                continue;
            }

            // 物件の存在チェック
            $propName = $row['property_name'];
            if (!isset($propertyCache[$propName])) {
                $propertyCache[$propName] = MsProperty::where('property_name', $propName)->first();
            }
            if (!$propertyCache[$propName]) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」がシステムに登録されていません。先に物件インポートを実行してください"];
                continue;
            }

            // CSV内重複チェック（物件名+駐車場番号）
            $parkingKey = $propName . '|' . $row['parking_number'];
            if (isset($parkingTracker[$parkingKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の駐車場番号「{$row['parking_number']}」がCSV内で重複しています（行{$parkingTracker[$parkingKey]}）"];
                continue;
            }
            $parkingTracker[$parkingKey] = $rowNum;

            // DB重複チェック（同一物件+駐車場番号 UNIQUE）
            $property = $propertyCache[$propName];
            $existing = MsParking::where('property_id', $property->id)
                ->where('parking_number', $row['parking_number'])
                ->first();
            if ($existing) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の駐車場「{$row['parking_number']}」は既に登録されています"];
                continue;
            }

            // 駐車場番号文字数
            if (mb_strlen($row['parking_number']) > 20) {
                $errors[] = ['row' => $rowNum, 'message' => '駐車場番号は20文字以内で入力してください'];
                continue;
            }

            // 月額料金チェック
            $monthlyFeeVal = str_replace(',', '', $row['monthly_fee']);
            if (!is_numeric($monthlyFeeVal) || (int) $monthlyFeeVal < 0) {
                $errors[] = ['row' => $rowNum, 'message' => "月額料金「{$row['monthly_fee']}」は0以上の整数で入力してください"];
                continue;
            }
            $row['monthly_fee'] = (int) $monthlyFeeVal;

            // ステータス
            if ($row['status'] !== '' && !isset($this->parkingStatusMap[$row['status']])) {
                $errors[] = ['row' => $rowNum, 'message' => "状態「{$row['status']}」は不正な値です（空き/使用中）"];
                continue;
            }

            // 屋根あり判定
            if ($row['has_roof'] !== '' && !array_key_exists($row['has_roof'], $this->hasRoofMap)) {
                $errors[] = ['row' => $rowNum, 'message' => "屋根あり「{$row['has_roof']}」は不正な値です（有/無/あり/なし/1/0/true/false）"];
                continue;
            }

            $row['_property_id'] = $property->id;
            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.mansion-import.index', [
                'activeTab'   => $tab,
                'preview'     => $tab,
                'totalRows'   => count($rows),
                'validCount'  => count($validRows),
                'rowErrors'   => $errors,
                'skippedRows' => [],
                'summary'     => '駐車場 ' . count($validRows) . '件を新規作成',
                'csvData'     => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $created = 0;

            foreach ($validRows as $row) {
                MsParking::create([
                    'property_id'    => $row['_property_id'],
                    'parking_number' => $row['parking_number'],
                    'monthly_fee'    => $row['monthly_fee'],
                    'status'         => $row['status'] !== ''
                        ? $this->parkingStatusMap[$row['status']]
                        : MsParkingStatus::Vacant->value,
                    'has_roof'       => $row['has_roof'] !== ''
                        ? $this->hasRoofMap[$row['has_roof']]
                        : false,
                    'notes'          => $row['notes'] ?: null,
                ]);
                $created++;
            }

            DB::commit();

            return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                ->with('success', "駐車場インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ④ 入居者インポート
    // ================================================================

    /**
     * 入居者CSVインポート実行。
     * 同名既存はスキップ（同名複数登録は許可しない）。
     */
    public function executeTenant(Request $request)
    {
        $columnMap = $this->tenantColumnMap;
        $requiredKeys = ['tenant_type', 'name'];
        $tab = 'tenant';

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
            if ($row['tenant_type'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '区分が未入力です'];
                continue;
            }
            if ($row['name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '氏名が未入力です'];
                continue;
            }

            // 区分チェック
            if (!isset($this->tenantTypeMap[$row['tenant_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "区分「{$row['tenant_type']}」は不正な値です（入居者/駐車場利用のみ）"];
                continue;
            }

            // 文字数チェック
            if (mb_strlen($row['name']) > 100) {
                $errors[] = ['row' => $rowNum, 'message' => '氏名は100文字以内で入力してください'];
                continue;
            }

            // CSV内重複チェック
            if (isset($nameTracker[$row['name']])) {
                $errors[] = ['row' => $rowNum, 'message' => "氏名「{$row['name']}」がCSV内で重複しています（行{$nameTracker[$row['name']]})"];
                continue;
            }
            $nameTracker[$row['name']] = $rowNum;

            // DB既存チェック
            $existing = MsTenant::where('name', $row['name'])->first();
            if ($existing) {
                $skippedRows[] = ['row' => $rowNum, 'message' => "入居者「{$row['name']}」は既に登録済みのためスキップ"];
                continue;
            }

            // メールアドレスチェック
            if ($row['email'] !== '') {
                if (mb_strlen($row['email']) > 255) {
                    $errors[] = ['row' => $rowNum, 'message' => 'メールアドレスは255文字以内で入力してください'];
                    continue;
                }
                if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ['row' => $rowNum, 'message' => "メールアドレス「{$row['email']}」の形式が不正です"];
                    continue;
                }
            }

            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.mansion-import.index', [
                'activeTab'   => $tab,
                'preview'     => $tab,
                'totalRows'   => count($rows),
                'validCount'  => count($validRows),
                'rowErrors'   => $errors,
                'skippedRows' => $skippedRows,
                'summary'     => '入居者 ' . count($validRows) . '件を新規作成',
                'csvData'     => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $created = 0;

            foreach ($validRows as $row) {
                MsTenant::create([
                    'tenant_type'                => $this->tenantTypeMap[$row['tenant_type']],
                    'name'                       => $row['name'],
                    'phone'                      => $row['phone'] ?: null,
                    'email'                      => $row['email'] ?: null,
                    'workplace'                  => $row['workplace'] ?: null,
                    'emergency_contact_name'     => $row['emergency_contact_name'] ?: null,
                    'emergency_contact_phone'    => $row['emergency_contact_phone'] ?: null,
                    'emergency_contact_relation' => $row['emergency_contact_relation'] ?: null,
                    'notes'                      => $row['notes'] ?: null,
                ]);
                $created++;
            }

            DB::commit();

            return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                ->with('success', "入居者インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ⑤ 部屋契約インポート
    // ================================================================

    /**
     * 部屋契約CSVインポート実行。
     * - status は move_out_date があれば terminated、なければ active
     * - active 契約は room.status を occupied に更新
     * - 既存 active 契約がある room には警告（エラーではない）
     */
    public function executeRoomContract(Request $request)
    {
        $columnMap = $this->roomContractColumnMap;
        $requiredKeys = ['property_name', 'room_number', 'tenant_name'];
        $tab = 'room_contract';

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
            if ($row['tenant_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '入居者名が未入力です'];
                continue;
            }

            // 物件の存在チェック
            $propName = $row['property_name'];
            if (!isset($propertyCache[$propName])) {
                $propertyCache[$propName] = MsProperty::where('property_name', $propName)->first();
            }
            if (!$propertyCache[$propName]) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」がシステムに登録されていません。先に物件インポートを実行してください"];
                continue;
            }
            $property = $propertyCache[$propName];

            // 部屋の存在チェック
            $room = MsRoom::where('property_id', $property->id)
                ->where('room_number', $row['room_number'])
                ->first();
            if (!$room) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」に部屋番号「{$row['room_number']}」が見つかりません。先に部屋インポートを実行してください"];
                continue;
            }

            // 入居者の存在チェック（同名複数はエラー）
            $tenantName = $row['tenant_name'];
            $tenants = MsTenant::where('name', $tenantName)->get();
            if ($tenants->isEmpty()) {
                $errors[] = ['row' => $rowNum, 'message' => "入居者「{$tenantName}」がシステムに登録されていません。先に入居者インポートを実行してください"];
                continue;
            }
            if ($tenants->count() > 1) {
                $errors[] = ['row' => $rowNum, 'message' => "入居者「{$tenantName}」が複数件登録されているため特定できません"];
                continue;
            }
            $tenant = $tenants->first();

            // 担当者解決（不一致は null + 警告）
            $staffUserId = null;
            if ($row['staff_user_name'] !== '') {
                $staff = User::where('name', $row['staff_user_name'])->first();
                if ($staff) {
                    $staffUserId = $staff->id;
                } else {
                    $warnings[] = ['row' => $rowNum, 'message' => "担当者ユーザー名「{$row['staff_user_name']}」がシステムに見つからないため、担当者は未設定でインポートします"];
                }
            }

            // 日付チェック
            $contractDate = null;
            if ($row['contract_date'] !== '') {
                $contractDate = CsvDate::normalize($row['contract_date']);
                if (!$contractDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                    continue;
                }
            }
            $moveInDate = null;
            if ($row['move_in_date'] !== '') {
                $moveInDate = CsvDate::normalize($row['move_in_date']);
                if (!$moveInDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "入居日「{$row['move_in_date']}」の形式が不正です"];
                    continue;
                }
            }
            $moveOutDate = null;
            if ($row['move_out_date'] !== '') {
                $moveOutDate = CsvDate::normalize($row['move_out_date']);
                if (!$moveOutDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "退去日「{$row['move_out_date']}」の形式が不正です"];
                    continue;
                }
            }

            // ステータス自動決定
            $status = $moveOutDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;

            // 二重契約チェック（active 契約のみ警告。terminated 契約は無視）
            if ($status === MsContractStatus::Active->value) {
                $existingActive = MsContract::where('room_id', $room->id)
                    ->where('status', MsContractStatus::Active->value)
                    ->first();
                if ($existingActive) {
                    $warnings[] = ['row' => $rowNum, 'message' => "部屋「{$propName} {$room->room_number}」には既に契約中の入居者がいます（既存契約 ID: {$existingActive->id}）"];
                }
            }

            // 金額フィールドチェック
            $numericFields = [
                'rent'       => '家賃',
                'common_fee' => '共益費',
                'deposit'    => '敷金',
                'key_money'  => '礼金',
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

            $row['_room_id']      = $room->id;
            $row['_tenant_id']    = $tenant->id;
            $row['_staff_id']     = $staffUserId;
            $row['_status']       = $status;
            $row['_contract_dt']  = $contractDate;
            $row['_move_in_dt']   = $moveInDate;
            $row['_move_out_dt']  = $moveOutDate;
            $row['_row']          = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.mansion-import.index', [
                'activeTab'   => $tab,
                'preview'     => $tab,
                'totalRows'   => count($rows),
                'validCount'  => count($validRows),
                'rowErrors'   => $errors,
                'warnings'    => $warnings,
                'skippedRows' => [],
                'summary'     => '部屋契約 ' . count($validRows) . '件を新規作成',
                'csvData'     => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $created = 0;

            foreach ($validRows as $row) {
                MsContract::create([
                    'room_id'       => $row['_room_id'],
                    'tenant_id'     => $row['_tenant_id'],
                    'status'        => $row['_status'],
                    'contract_date' => $row['_contract_dt'],
                    'move_in_date'  => $row['_move_in_dt'],
                    'move_out_date' => $row['_move_out_dt'],
                    'rent'          => $row['rent'] !== '' ? $row['rent'] : null,
                    'common_fee'    => $row['common_fee'] !== '' ? $row['common_fee'] : null,
                    'deposit'       => $row['deposit'] !== '' ? $row['deposit'] : null,
                    'key_money'     => $row['key_money'] !== '' ? $row['key_money'] : null,
                    'staff_user_id' => $row['_staff_id'],
                    'memo'          => $row['memo'] ?: null,
                    'created_by'    => Auth::id(),
                ]);

                // active 契約は room.status を occupied に更新
                if ($row['_status'] === MsContractStatus::Active->value) {
                    MsRoom::where('id', $row['_room_id'])
                        ->update(['status' => MsRoomStatus::Occupied->value]);
                }

                $created++;
            }

            DB::commit();

            return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                ->with('success', "部屋契約インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // ⑥ 駐車場契約インポート
    // ================================================================

    /**
     * 駐車場契約CSVインポート実行。
     * - status は end_date があれば terminated、なければ active
     * - active 契約は parking.status を occupied に更新
     * - linked_room_number 指定時は該当物件の active 部屋契約 ID を contract_id に格納
     * - monthly_fee は NOT NULL 必須
     */
    public function executeParkingContract(Request $request)
    {
        $columnMap = $this->parkingContractColumnMap;
        $requiredKeys = ['property_name', 'parking_number', 'tenant_name', 'monthly_fee'];
        $tab = 'parking_contract';

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

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            // 必須チェック
            if ($row['property_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];
                continue;
            }
            if ($row['parking_number'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '駐車場番号が未入力です'];
                continue;
            }
            if ($row['tenant_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '入居者名が未入力です'];
                continue;
            }
            if ($row['monthly_fee'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '月額料金が未入力です'];
                continue;
            }

            // 物件の存在チェック
            $propName = $row['property_name'];
            if (!isset($propertyCache[$propName])) {
                $propertyCache[$propName] = MsProperty::where('property_name', $propName)->first();
            }
            if (!$propertyCache[$propName]) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」がシステムに登録されていません。先に物件インポートを実行してください"];
                continue;
            }
            $property = $propertyCache[$propName];

            // 駐車場の存在チェック
            $parking = MsParking::where('property_id', $property->id)
                ->where('parking_number', $row['parking_number'])
                ->first();
            if (!$parking) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$propName}」に駐車場番号「{$row['parking_number']}」が見つかりません。先に駐車場インポートを実行してください"];
                continue;
            }

            // 入居者の存在チェック（同名複数はエラー）
            $tenantName = $row['tenant_name'];
            $tenants = MsTenant::where('name', $tenantName)->get();
            if ($tenants->isEmpty()) {
                $errors[] = ['row' => $rowNum, 'message' => "入居者「{$tenantName}」がシステムに登録されていません。先に入居者インポートを実行してください"];
                continue;
            }
            if ($tenants->count() > 1) {
                $errors[] = ['row' => $rowNum, 'message' => "入居者「{$tenantName}」が複数件登録されているため特定できません"];
                continue;
            }
            $tenant = $tenants->first();

            // 紐付部屋番号（任意）
            $linkedContractId = null;
            if ($row['linked_room_number'] !== '') {
                $linkedRoom = MsRoom::where('property_id', $property->id)
                    ->where('room_number', $row['linked_room_number'])
                    ->first();
                if (!$linkedRoom) {
                    $warnings[] = ['row' => $rowNum, 'message' => "紐付部屋番号「{$row['linked_room_number']}」が物件「{$propName}」に見つからないため、部屋契約との紐付けはスキップします"];
                } else {
                    $linkedActive = MsContract::where('room_id', $linkedRoom->id)
                        ->where('status', MsContractStatus::Active->value)
                        ->first();
                    if ($linkedActive) {
                        $linkedContractId = $linkedActive->id;
                    } else {
                        $warnings[] = ['row' => $rowNum, 'message' => "紐付部屋番号「{$row['linked_room_number']}」に有効な部屋契約が見つからないため、部屋契約との紐付けはスキップします"];
                    }
                }
            }

            // 担当者解決（不一致は null + 警告）
            $staffUserId = null;
            if ($row['staff_user_name'] !== '') {
                $staff = User::where('name', $row['staff_user_name'])->first();
                if ($staff) {
                    $staffUserId = $staff->id;
                } else {
                    $warnings[] = ['row' => $rowNum, 'message' => "担当者ユーザー名「{$row['staff_user_name']}」がシステムに見つからないため、担当者は未設定でインポートします"];
                }
            }

            // 月額料金チェック
            $monthlyFeeVal = str_replace(',', '', $row['monthly_fee']);
            if (!is_numeric($monthlyFeeVal) || (int) $monthlyFeeVal < 0) {
                $errors[] = ['row' => $rowNum, 'message' => "月額料金「{$row['monthly_fee']}」は0以上の整数で入力してください"];
                continue;
            }
            $row['monthly_fee'] = (int) $monthlyFeeVal;

            // 敷金チェック
            if ($row['deposit'] !== '') {
                $val = str_replace(',', '', $row['deposit']);
                if (!is_numeric($val) || (int) $val < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "敷金「{$row['deposit']}」は不正な値です"];
                    continue;
                }
                $row['deposit'] = (int) $val;
            }

            // 日付チェック
            $contractDate = null;
            if ($row['contract_date'] !== '') {
                $contractDate = CsvDate::normalize($row['contract_date']);
                if (!$contractDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                    continue;
                }
            }
            $startDate = null;
            if ($row['start_date'] !== '') {
                $startDate = CsvDate::normalize($row['start_date']);
                if (!$startDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "開始日「{$row['start_date']}」の形式が不正です"];
                    continue;
                }
            }
            $endDate = null;
            if ($row['end_date'] !== '') {
                $endDate = CsvDate::normalize($row['end_date']);
                if (!$endDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "終了日「{$row['end_date']}」の形式が不正です"];
                    continue;
                }
            }

            // ステータス自動決定
            $status = $endDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;

            // 二重契約チェック（active 契約のみ警告）
            if ($status === MsContractStatus::Active->value) {
                $existingActive = MsParkingContract::where('parking_id', $parking->id)
                    ->where('status', MsContractStatus::Active->value)
                    ->first();
                if ($existingActive) {
                    $warnings[] = ['row' => $rowNum, 'message' => "駐車場「{$propName} {$parking->parking_number}」には既に使用中の契約があります（既存契約 ID: {$existingActive->id}）"];
                }
            }

            $row['_parking_id']     = $parking->id;
            $row['_tenant_id']      = $tenant->id;
            $row['_contract_id']    = $linkedContractId;
            $row['_staff_id']       = $staffUserId;
            $row['_status']         = $status;
            $row['_contract_dt']    = $contractDate;
            $row['_start_dt']       = $startDate;
            $row['_end_dt']         = $endDate;
            $row['_row']            = $rowNum;
            $validRows[] = $row;
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.mansion-import.index', [
                'activeTab'   => $tab,
                'preview'     => $tab,
                'totalRows'   => count($rows),
                'validCount'  => count($validRows),
                'rowErrors'   => $errors,
                'warnings'    => $warnings,
                'skippedRows' => [],
                'summary'     => '駐車場契約 ' . count($validRows) . '件を新規作成',
                'csvData'     => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            $created = 0;

            foreach ($validRows as $row) {
                MsParkingContract::create([
                    'parking_id'    => $row['_parking_id'],
                    'tenant_id'     => $row['_tenant_id'],
                    'contract_id'   => $row['_contract_id'],
                    'status'        => $row['_status'],
                    'contract_date' => $row['_contract_dt'],
                    'start_date'    => $row['_start_dt'],
                    'end_date'      => $row['_end_dt'],
                    'monthly_fee'   => $row['monthly_fee'],
                    'deposit'       => $row['deposit'] !== '' ? $row['deposit'] : null,
                    'staff_user_id' => $row['_staff_id'],
                    'memo'          => $row['memo'] ?: null,
                    'created_by'    => Auth::id(),
                ]);

                // active 契約は parking.status を occupied に更新
                if ($row['_status'] === MsContractStatus::Active->value) {
                    MsParking::where('id', $row['_parking_id'])
                        ->update(['status' => MsParkingStatus::Occupied->value]);
                }

                $created++;
            }

            DB::commit();

            return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                ->with('success', "駐車場契約インポート完了: {$created}件を登録しました");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    // ================================================================
    // テンプレートCSVダウンロード
    // ================================================================

    /**
     * 物件テンプレートCSVダウンロード。
     */
    public function downloadPropertyTemplate()
    {
        $headers = array_keys($this->propertyColumnMap);

        $sample = [
            'サンプルマンション', '自社所有', '', '790-0001', '愛媛県松山市一番町1-1',
            '20', '5', 'RC造', '2010-04', '',
        ];

        return CsvImportTemplate::response($headers, [$sample], 'マンション物件インポートテンプレート.csv');
    }

    /**
     * 部屋テンプレートCSVダウンロード。
     */
    public function downloadRoomTemplate()
    {
        $headers = array_keys($this->roomColumnMap);

        $sample1 = ['サンプルマンション', '101', '1', '1K',  '25.50', '空室', '55000', '3000', '55000', '55000', ''];
        $sample2 = ['サンプルマンション', '201', '2', '2LDK', '52.30', '空室', '85000', '5000', '85000', '85000', ''];

        return CsvImportTemplate::response($headers, [$sample1, $sample2], 'マンション部屋インポートテンプレート.csv');
    }

    /**
     * 駐車場テンプレートCSVダウンロード。
     */
    public function downloadParkingTemplate()
    {
        $headers = array_keys($this->parkingColumnMap);

        $sample1 = ['サンプルマンション', 'P-1', '8000', '空き', '無', ''];
        $sample2 = ['サンプルマンション', 'P-2', '10000', '空き', '有', ''];

        return CsvImportTemplate::response($headers, [$sample1, $sample2], 'マンション駐車場インポートテンプレート.csv');
    }

    /**
     * 入居者テンプレートCSVダウンロード。
     */
    public function downloadTenantTemplate()
    {
        $headers = array_keys($this->tenantColumnMap);

        $sample = [
            '入居者', '山田太郎', '090-1234-5678', 'taro@example.com', '株式会社サンプル',
            '山田花子', '090-9876-5432', '配偶者', '',
        ];

        return CsvImportTemplate::response($headers, [$sample], 'マンション入居者インポートテンプレート.csv');
    }

    /**
     * 部屋契約テンプレートCSVダウンロード。
     */
    public function downloadRoomContractTemplate()
    {
        $headers = array_keys($this->roomContractColumnMap);

        $sample = [
            'サンプルマンション', '101', '山田太郎',
            '2024-04-01', '2024-04-15', '',
            '55000', '3000', '55000', '55000',
            '担当者名', '',
        ];

        return CsvImportTemplate::response($headers, [$sample], 'マンション部屋契約インポートテンプレート.csv');
    }

    /**
     * 駐車場契約テンプレートCSVダウンロード。
     */
    public function downloadParkingContractTemplate()
    {
        $headers = array_keys($this->parkingContractColumnMap);

        $sample = [
            'サンプルマンション', 'P-1', '山田太郎', '101',
            '2024-04-01', '2024-04-15', '',
            '8000', '8000',
            '担当者名', '',
        ];

        return CsvImportTemplate::response($headers, [$sample], 'マンション駐車場契約インポートテンプレート.csv');
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
     * 物件コード（MS-NNN）の次の番号を取得。
     * MsProperty コントローラの generateNextCode() と同じく、
     * 既存最大 ID のレコードのコード末尾数値 + 1 を採用する。
     */
    private function getNextPropertyCodeNum(): int
    {
        $last = MsProperty::orderByDesc('id')->first();
        if ($last && preg_match('/^MS-(\d+)$/', $last->property_code, $m)) {
            return ((int) $m[1]) + 1;
        }
        return 1;
    }
}
