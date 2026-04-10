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

class TenantImportController extends Controller
{
    /**
     * Enum日本語→DB値マッピング
     */
    private array $ownerTypeMap = ['自社' => 'self_owned', 'オーナー' => 'owner'];
    private array $operationStatusMap = ['稼働中' => 'active', '停止中' => 'inactive'];
    private array $usageTypeMap = ['店舗' => 'shop', '倉庫' => 'warehouse', '事務所' => 'office', 'その他' => 'other'];
    private array $customerTypeMap = ['法人' => 'corporation', '個人事業主' => 'sole_proprietor', '個人' => 'individual'];

    /**
     * CSVカラムマッピング（日本語ヘッダー → 内部キー）
     */
    private array $columnMap = [
        // 物件
        '物件名'     => 'prop_name',
        '郵便番号'   => 'prop_postal_code',
        '住所'       => 'prop_address',
        '構造'       => 'prop_structure',
        '築年月'     => 'prop_built_date',
        '階数'       => 'prop_total_floors',
        '所有区分'   => 'prop_owner_type',
        'オーナー名' => 'prop_owner_name',
        '稼働状態'   => 'prop_operation_status',
        // 区画
        '階'         => 'unit_floor',
        '部屋番号'   => 'unit_room_number',
        '面積(��)'   => 'unit_area_tsubo',
        '用途'       => 'unit_usage_type',
        '募集家賃'   => 'unit_rent',
        '募集共益費'  => 'unit_common_fee',
        '募集敷金'   => 'unit_deposit',
        '募集ゴミ代'  => 'unit_garbage_fee',
        '募集駆除代'  => 'unit_pest_control_fee',
        // 顧客
        'テナント名'     => 'cust_name',
        'テナントカナ'   => 'cust_name_kana',
        '種別'           => 'cust_customer_type',
        '代表者名'       => 'cust_representative',
        'テナント担当者' => 'cust_contact_person',
        'テナント電話'   => 'cust_phone',
        'テナントメール' => 'cust_email',
        'テナント郵便番号' => 'cust_postal_code',
        'テナント住所'   => 'cust_address',
        // 契約
        '契約日'     => 'con_contract_date',
        '賃料開始日' => 'con_rent_start_date',
        '家賃'       => 'con_rent',
        '共益費'     => 'con_common_fee',
        '敷金'       => 'con_deposit',
        'ゴミ代'     => 'con_garbage_fee',
        '駆除代'     => 'con_pest_control_fee',
        '屋号'       => 'con_store_name',
        '備考'       => 'con_notes',
    ];

    /**
     * インポート画面表示
     */
    public function showForm()
    {
        return view('admin.tenant-import.index');
    }

    /**
     * CSVインポート実行
     */
    public function execute(Request $request)
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

        $lines = array_filter(explode("\n", $content), function ($line) {
            return trim($line) !== '';
        });

        if (count($lines) < 2) {
            return back()->with('error', 'CSVファイルにデータがありません。');
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);

        // ヘッダー→内部キーのインデックスマッピング
        $colIndex = [];
        foreach ($header as $idx => $headerName) {
            if (isset($this->columnMap[$headerName])) {
                $colIndex[$this->columnMap[$headerName]] = $idx;
            }
        }

        // 必須ヘッダーチェック
        $requiredHeaders = ['prop_name', 'prop_address', 'unit_room_number'];
        foreach ($requiredHeaders as $key) {
            if (!isset($colIndex[$key])) {
                $jpName = array_search($key, $this->columnMap);
                return back()->with('error', "必須ヘッダー「{$jpName}」がCSVに見つかりません。");
            }
        }

        // 行バリデーション
        $errors = [];
        $validRows = [];
        $unitTracker = []; // 物件名+部屋番号の重複チェック
        $rowNum = 1;

        foreach ($lines as $line) {
            $rowNum++;
            $cols = str_getcsv($line);
            $row = $this->extractRow($cols, $colIndex);

            // 必須チェック
            if ($row['prop_name'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];
                continue;
            }
            if ($row['prop_address'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '住所が未入力です'];
                continue;
            }
            if ($row['unit_room_number'] === '') {
                $errors[] = ['row' => $rowNum, 'message' => '部屋番号が未入力です'];
                continue;
            }

            // 行タイプ判定
            $hasTenant = $row['cust_name'] !== '';
            $hasRent = $row['con_rent'] !== '';
            $hasContractDate = $row['con_contract_date'] !== '';

            if ($hasTenant && !$hasRent) {
                $errors[] = ['row' => $rowNum, 'message' => 'テナント名がありますが家賃が未入力です'];
                continue;
            }
            if ($hasTenant && !$hasContractDate) {
                $errors[] = ['row' => $rowNum, 'message' => 'テナント名がありますが契約日が未入力です'];
                continue;
            }
            if (!$hasTenant && ($hasRent || $hasContractDate)) {
                $errors[] = ['row' => $rowNum, 'message' => '家賃/契約日がありますがテナント名が未入力です'];
                continue;
            }

            $row['is_occupied'] = $hasTenant;

            // Enum値チェック
            if ($row['prop_owner_type'] !== '' && !isset($this->ownerTypeMap[$row['prop_owner_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "所有区分「{$row['prop_owner_type']}」は不正な値です（自社/オーナー）"];
                continue;
            }
            if ($row['prop_operation_status'] !== '' && !isset($this->operationStatusMap[$row['prop_operation_status']])) {
                $errors[] = ['row' => $rowNum, 'message' => "稼働状態「{$row['prop_operation_status']}」は不正な値です（稼働中/停止中）"];
                continue;
            }
            if ($row['unit_usage_type'] !== '' && !isset($this->usageTypeMap[$row['unit_usage_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "用途「{$row['unit_usage_type']}」は不正な値です（店舗/倉庫/事務所/その他）"];
                continue;
            }
            if ($row['cust_customer_type'] !== '' && !isset($this->customerTypeMap[$row['cust_customer_type']])) {
                $errors[] = ['row' => $rowNum, 'message' => "種別「{$row['cust_customer_type']}」は不正な値です（法人/個人事業主/個人）"];
                continue;
            }

            // 日付チェック
            if ($hasContractDate) {
                $date = $this->normalizeDate($row['con_contract_date']);
                if (!$date) {
                    $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['con_contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                    continue;
                }
                $row['con_contract_date'] = $date;
            }
            if ($row['con_rent_start_date'] !== '') {
                $date = $this->normalizeDate($row['con_rent_start_date']);
                if (!$date) {
                    $errors[] = ['row' => $rowNum, 'message' => "賃料開始日「{$row['con_rent_start_date']}」の形式が不正です"];
                    continue;
                }
                $row['con_rent_start_date'] = $date;
            }

            // 数値チェック（金額フィールド）
            $numericFields = [
                'con_rent' => '家賃', 'con_common_fee' => '共益費', 'con_deposit' => '敷金',
                'con_garbage_fee' => 'ゴミ代', 'con_pest_control_fee' => '駆除代',
                'unit_rent' => '募集家賃', 'unit_common_fee' => '募集共益費',
                'unit_deposit' => '募集敷金', 'unit_garbage_fee' => '募集ゴミ代',
                'unit_pest_control_fee' => '募集駆除代',
            ];
            $numericError = false;
            foreach ($numericFields as $field => $label) {
                if ($row[$field] !== '') {
                    // カンマ除去
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

            // 面積チェック
            if ($row['unit_area_tsubo'] !== '') {
                $val = str_replace(',', '', $row['unit_area_tsubo']);
                if (!is_numeric($val) || (float) $val < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "面積「{$row['unit_area_tsubo']}」は不正な値です"];
                    continue;
                }
                $row['unit_area_tsubo'] = (float) $val;
            }

            // 階数チェック
            if ($row['prop_total_floors'] !== '') {
                $val = $row['prop_total_floors'];
                if (!ctype_digit($val) || (int) $val < 1) {
                    $errors[] = ['row' => $rowNum, 'message' => "階数「{$val}」は不正な値です"];
                    continue;
                }
                $row['prop_total_floors'] = (int) $val;
            }

            // 階チェック
            if ($row['unit_floor'] !== '') {
                $val = $row['unit_floor'];
                if (!ctype_digit($val)) {
                    $errors[] = ['row' => $rowNum, 'message' => "階「{$val}」は不正な値です"];
                    continue;
                }
                $row['unit_floor'] = (int) $val;
            }

            // CSV内の部屋番号重複チェック
            $unitKey = $row['prop_name'] . '|' . $row['unit_room_number'];
            if (isset($unitTracker[$unitKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "物件「{$row['prop_name']}」の部屋番号「{$row['unit_room_number']}」が重複しています（行{$unitTracker[$unitKey]}）"];
                continue;
            }
            $unitTracker[$unitKey] = $rowNum;

            $row['_row'] = $rowNum;
            $validRows[] = $row;
        }

        // 集計
        $propertyNames = [];
        $customerNames = [];
        $occupiedCount = 0;
        $vacantCount = 0;
        foreach ($validRows as $r) {
            $propertyNames[$r['prop_name']] = true;
            if ($r['is_occupied']) {
                $customerNames[$r['cust_name']] = true;
                $occupiedCount++;
            } else {
                $vacantCount++;
            }
        }

        // プレビューモード
        if (!$request->boolean('confirmed')) {
            return view('admin.tenant-import.index', [
                'preview'       => true,
                'totalRows'     => count($lines),
                'validCount'    => count($validRows),
                'errors'        => $errors,
                'propertyCount' => count($propertyNames),
                'unitCount'     => count($validRows),
                'customerCount' => count($customerNames),
                'contractCount' => $occupiedCount,
                'vacantCount'   => $vacantCount,
                'csvData'       => base64_encode($content),
            ]);
        }

        // ===== インポート実行 =====
        DB::beginTransaction();
        try {
            // 物件作成（名前で重複排除）
            $propertyMap = []; // name => Property
            $propertyCodeNum = $this->getNextPropertyCodeNum();

            foreach ($validRows as $row) {
                $propName = $row['prop_name'];
                if (isset($propertyMap[$propName])) {
                    continue;
                }

                // DB内の既存チェック
                $existing = Property::where('name', $propName)
                    ->where('department', DepartmentCode::Tenant->value)
                    ->first();

                if ($existing) {
                    $propertyMap[$propName] = $existing;
                    continue;
                }

                $code = 'T-' . str_pad($propertyCodeNum, 3, '0', STR_PAD_LEFT);
                $propertyCodeNum++;

                $ownerType = $row['prop_owner_type'] !== ''
                    ? $this->ownerTypeMap[$row['prop_owner_type']]
                    : null;

                $property = Property::create([
                    'code'             => $code,
                    'name'             => $propName,
                    'property_type'    => PropertyType::Tenant->value,
                    'department'       => DepartmentCode::Tenant->value,
                    'operation_status' => $row['prop_operation_status'] !== ''
                        ? $this->operationStatusMap[$row['prop_operation_status']]
                        : 'active',
                    'postal_code'      => $row['prop_postal_code'] ?: null,
                    'address'          => $row['prop_address'],
                    'structure'        => $row['prop_structure'] ?: null,
                    'built_date'       => $row['prop_built_date'] ?: null,
                    'total_floors'     => $row['prop_total_floors'] !== '' ? $row['prop_total_floors'] : null,
                    'owner_type'       => $ownerType,
                    'owner_name'       => $ownerType === 'owner' ? ($row['prop_owner_name'] ?: null) : null,
                ]);

                $propertyMap[$propName] = $property;
            }

            // 顧客作成（名前で重複排除）
            $customerMap = []; // name => Customer
            $customerCodeNum = $this->getNextCustomerCodeNum();

            foreach ($validRows as $row) {
                if (!$row['is_occupied']) {
                    continue;
                }
                $custName = $row['cust_name'];
                if (isset($customerMap[$custName])) {
                    continue;
                }

                // DB内の既存チェック
                $existing = Customer::where('name', $custName)->first();

                if ($existing) {
                    $customerMap[$custName] = $existing;
                    continue;
                }

                $code = 'CUS-' . str_pad($customerCodeNum, 3, '0', STR_PAD_LEFT);
                $customerCodeNum++;

                $customer = Customer::create([
                    'code'           => $code,
                    'name'           => $custName,
                    'name_kana'      => $row['cust_name_kana'] ?: null,
                    'customer_type'  => $row['cust_customer_type'] !== ''
                        ? $this->customerTypeMap[$row['cust_customer_type']]
                        : 'corporation',
                    'representative' => $row['cust_representative'] ?: null,
                    'contact_person' => $row['cust_contact_person'] ?: null,
                    'phone'          => $row['cust_phone'] ?: null,
                    'email'          => $row['cust_email'] ?: null,
                    'postal_code'    => $row['cust_postal_code'] ?: null,
                    'address'        => $row['cust_address'] ?: null,
                ]);

                $customerMap[$custName] = $customer;
            }

            // 区画＋契約作成
            $contractCodeNum = $this->getNextContractCodeNum();
            $importedUnits = 0;
            $importedContracts = 0;

            foreach ($validRows as $row) {
                $property = $propertyMap[$row['prop_name']];

                $floor = $row['unit_floor'] !== '' ? $row['unit_floor'] : null;
                $roomNumber = $row['unit_room_number'];
                $displayName = Unit::generateDisplayName($floor, $roomNumber);

                $unit = Unit::create([
                    'property_id'    => $property->id,
                    'floor'          => $floor,
                    'room_number'    => $roomNumber,
                    'display_name'   => $displayName,
                    'area_tsubo'     => $row['unit_area_tsubo'] !== '' ? $row['unit_area_tsubo'] : null,
                    'usage_type'     => $row['unit_usage_type'] !== ''
                        ? $this->usageTypeMap[$row['unit_usage_type']]
                        : null,
                    'status'         => $row['is_occupied']
                        ? UnitStatus::Occupied->value
                        : UnitStatus::Vacant->value,
                    'rent'           => $row['unit_rent'] !== '' ? $row['unit_rent'] : null,
                    'common_fee'     => $row['unit_common_fee'] !== '' ? $row['unit_common_fee'] : null,
                    'deposit'        => $row['unit_deposit'] !== '' ? $row['unit_deposit'] : null,
                    'garbage_fee'    => $row['unit_garbage_fee'] !== '' ? $row['unit_garbage_fee'] : null,
                    'pest_control_fee' => $row['unit_pest_control_fee'] !== '' ? $row['unit_pest_control_fee'] : null,
                ]);
                $importedUnits++;

                // 契約作成（入居行のみ）
                if ($row['is_occupied']) {
                    $customer = $customerMap[$row['cust_name']];
                    $year = now()->year;
                    $contractNumber = "C-{$year}-" . str_pad($contractCodeNum, 3, '0', STR_PAD_LEFT);
                    $contractCodeNum++;

                    Contract::create([
                        'contract_number'  => $contractNumber,
                        'department'       => DepartmentCode::Tenant->value,
                        'property_id'      => $property->id,
                        'unit_id'          => $unit->id,
                        'customer_id'      => $customer->id,
                        'status'           => ContractStatus::Active->value,
                        'contract_date'    => $row['con_contract_date'],
                        'rent_start_date'  => $row['con_rent_start_date'] ?: null,
                        'rent'             => $row['con_rent'],
                        'common_fee'       => $row['con_common_fee'] !== '' ? $row['con_common_fee'] : 0,
                        'deposit'          => $row['con_deposit'] !== '' ? $row['con_deposit'] : 0,
                        'garbage_fee'      => $row['con_garbage_fee'] !== '' ? $row['con_garbage_fee'] : 0,
                        'pest_control_fee' => $row['con_pest_control_fee'] !== '' ? $row['con_pest_control_fee'] : 0,
                        'store_name'       => $row['con_store_name'] ?: null,
                        'notes'            => $row['con_notes'] ?: null,
                        'initial_month_type' => 'full',
                        'final_month_type'   => 'full',
                    ]);
                    $importedContracts++;
                }
            }

            // 物件のtotal_unitsを更新
            foreach ($propertyMap as $property) {
                $count = Unit::where('property_id', $property->id)->count();
                $property->update(['total_units' => $count]);
            }

            DB::commit();

            $propCount = count(array_filter($propertyMap, function ($p) use ($validRows) {
                // 今回作成された物件のみカウント（簡易判定）
                return true;
            }));

            return redirect()->route('admin.tenant-import')
                ->with('success', "インポート完了: 物件 " . count($propertyMap) . "件、区画 {$importedUnits}件、顧客 " . count($customerMap) . "件、契約 {$importedContracts}件");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * テンプレートCSVダウンロード
     */
    public function downloadTemplate()
    {
        $headers = array_keys($this->columnMap);

        // サンプルデータ（空室例）
        $sampleVacant = [
            'サンプルビル', '790-0001', '愛媛県松山市一番町1-1', 'RC造', '2000-03', '3',
            '自社', '', '稼働中',
            '1', 'A', '15.5', '店舗',
            '80000', '5000', '160000', '1000', '500',
            '', '', '', '', '', '', '', '', '',
            '', '', '', '', '', '', '', '', '',
        ];

        // サンプルデータ（入居例）
        $sampleOccupied = [
            'サンプルビル', '790-0001', '愛媛県松山市一番町1-1', 'RC造', '2000-03', '3',
            '自社', '', '稼働中',
            '1', 'B', '20.0', '事務所',
            '100000', '8000', '200000', '1500', '500',
            'サンプル商事', 'サンプルショウジ', '法人', '山田太郎', '鈴木花子', '089-999-9999', 'info@sample.co.jp', '790-0002', '愛媛県松山市二番町2-2',
            '2024-04-01', '2024-04-01', '95000', '8000', '190000', '1500', '500', 'サンプル商事 松山支店', '',
        ];

        $bom = "\xEF\xBB\xBF";
        $csv = $bom;
        $csv .= $this->toCsvLine($headers);
        $csv .= $this->toCsvLine($sampleVacant);
        $csv .= $this->toCsvLine($sampleOccupied);

        $filename = 'テナントCSVインポートテンプレート.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * CSV行から内部キーでデータを抽出
     */
    private function extractRow(array $cols, array $colIndex): array
    {
        $row = [];
        foreach ($this->columnMap as $jpName => $key) {
            $idx = $colIndex[$key] ?? -1;
            $row[$key] = ($idx >= 0 && isset($cols[$idx])) ? trim($cols[$idx]) : '';
        }
        return $row;
    }

    /**
     * 日付文字列を正規化（YYYY-MM-DD）
     */
    private function normalizeDate(string $value): ?string
    {
        $value = str_replace('/', '-', $value);
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) && strtotime($value)) {
            // ゼロ埋め
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
            // ダブルクォートで囲む
            $escaped[] = '"' . str_replace('"', '""', $f) . '"';
        }
        return implode(',', $escaped) . "\n";
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
