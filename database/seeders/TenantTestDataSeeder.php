<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\Investment;
use App\Models\InvestmentDetail;
use App\Models\Property;
use App\Models\RentRevision;
use App\Models\Repair;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantTestDataSeeder extends Seeder
{
    /**
     * テナント部門の動作確認用サンプルデータを投入する。
     */
    public function run(): void
    {
        // 担当者取得
        $admin   = User::where('email', 'admin@example.com')->first();
        $manager = User::where('email', 'manager@example.com')->first();
        $staff   = User::where('email', 'staff@example.com')->first();

        // =============================================
        // 1. 顧客マスター（10件）
        // =============================================
        $customers = [];

        $customerData = [
            ['code' => 'CU-001', 'name' => '㈱ABC商事',     'name_kana' => 'エービーシーショウジ',   'customer_type' => 'corporation',      'representative' => '佐藤健一',   'phone' => '03-1234-5678', 'postal_code' => '105-0001', 'address' => '東京都港区虎ノ門1-1-1'],
            ['code' => 'CU-002', 'name' => '△△薬局',        'name_kana' => 'サンカクサンカクヤッキョク', 'customer_type' => 'sole_proprietor', 'representative' => '中村明',     'phone' => '03-2345-6789', 'postal_code' => '105-0002', 'address' => '東京都港区虎ノ門2-2-2'],
            ['code' => 'CU-003', 'name' => '○○コンビニ',    'name_kana' => 'マルマルコンビニ',       'customer_type' => 'corporation',      'representative' => '高橋誠',     'phone' => '03-3456-7890', 'postal_code' => '105-0003', 'address' => '東京都港区芝公園3-3-3'],
            ['code' => 'CU-004', 'name' => '□□クリニック',  'name_kana' => 'シカクシカククリニック', 'customer_type' => 'individual',       'representative' => '山本直子',   'phone' => '03-4567-8901', 'postal_code' => '150-0001', 'address' => '東京都渋谷区神宮前4-4-4'],
            ['code' => 'CU-005', 'name' => '㈱XYZ',          'name_kana' => 'エックスワイゼット',     'customer_type' => 'corporation',      'representative' => '伊藤大輔',   'phone' => '03-5678-9012', 'postal_code' => '150-0002', 'address' => '東京都渋谷区道玄坂5-5-5'],
            ['code' => 'CU-006', 'name' => '○○商店',        'name_kana' => 'マルマルショウテン',     'customer_type' => 'sole_proprietor',  'representative' => '渡辺太郎',   'phone' => '06-1234-5678', 'postal_code' => '530-0001', 'address' => '大阪府大阪市北区梅田1-1-1'],
            ['code' => 'CU-007', 'name' => '□□工房',        'name_kana' => 'シカクシカクコウボウ',   'customer_type' => 'individual',       'representative' => '加藤美咲',   'phone' => '06-2345-6789', 'postal_code' => '530-0002', 'address' => '大阪府大阪市北区梅田2-2-2'],
            ['code' => 'CU-008', 'name' => '㈱田中商事',     'name_kana' => 'タナカショウジ',         'customer_type' => 'corporation',      'representative' => '田中一郎',   'phone' => '03-6789-0123', 'postal_code' => '100-0001', 'address' => '東京都千代田区丸の内1-1-1'],
            ['code' => 'CU-009', 'name' => '㈱山田商事',     'name_kana' => 'ヤマダショウジ',         'customer_type' => 'corporation',      'representative' => '山田次郎',   'phone' => '03-7890-1234', 'postal_code' => '100-0002', 'address' => '東京都千代田区丸の内2-2-2'],
            ['code' => 'CU-010', 'name' => '佐藤花子',       'name_kana' => 'サトウハナコ',           'customer_type' => 'individual',       'representative' => null,          'phone' => '090-1234-5678', 'postal_code' => '160-0001', 'address' => '東京都新宿区四谷1-1-1'],
        ];

        foreach ($customerData as $data) {
            $customers[] = Customer::create($data);
        }

        // =============================================
        // 2. 物件マスター（5件: 稼働3件 + 非稼働1件 + 平屋型1件）
        // =============================================
        $properties = [];

        // P1: ○○ビル（稼働・ビル型・3階6区画・自社所有）
        $properties['marumaru'] = Property::create([
            'code' => 'T-001', 'name' => '○○ビル', 'property_type' => 'tenant',
            'department' => 'tenant', 'operation_status' => 'active',
            'postal_code' => '105-0001', 'address' => '東京都港区虎ノ門1-1-1',
            'structure' => 'RC造', 'built_date' => '2005-03', 'total_floors' => 3,
            'total_units' => 6, 'total_area' => 450.00,
            'owner_type' => 'self_owned', 'owner_name' => null,
        ]);

        // P2: △△ビル（稼働・ビル型・2階4区画・オーナー所有）
        $properties['sankaku'] = Property::create([
            'code' => 'T-002', 'name' => '△△ビル', 'property_type' => 'tenant',
            'department' => 'tenant', 'operation_status' => 'active',
            'postal_code' => '150-0001', 'address' => '東京都渋谷区神宮前2-2-2',
            'structure' => 'S造', 'built_date' => '2010-06', 'total_floors' => 2,
            'total_units' => 4, 'total_area' => 280.00,
            'owner_type' => 'owner', 'owner_name' => '㈱山田不動産',
        ]);

        // P3: □□ビル（稼働・ビル型・2階4区画・自社所有）
        $properties['shikaku'] = Property::create([
            'code' => 'T-003', 'name' => '□□ビル', 'property_type' => 'tenant',
            'department' => 'tenant', 'operation_status' => 'active',
            'postal_code' => '530-0001', 'address' => '大阪府大阪市北区梅田3-3-3',
            'structure' => 'RC造', 'built_date' => '2015-09', 'total_floors' => 2,
            'total_units' => 4, 'total_area' => 200.00,
            'owner_type' => 'self_owned', 'owner_name' => null,
        ]);

        // P4: ☆☆ビル（非稼働・ビル型・建替え検討中）
        $properties['hoshi'] = Property::create([
            'code' => 'T-004', 'name' => '☆☆ビル', 'property_type' => 'tenant',
            'department' => 'tenant', 'operation_status' => 'inactive',
            'postal_code' => '105-0011', 'address' => '東京都港区芝公園4-4-4',
            'structure' => '木造', 'built_date' => '1985-01', 'total_floors' => 2,
            'total_units' => 4, 'total_area' => 150.00,
            'owner_type' => 'self_owned', 'owner_name' => null,
            'notes' => '建替え検討中。2026年度内に方針決定予定。',
        ]);

        // P5: ◇◇テナント（稼働・平屋型・3区画・自社所有）
        $properties['hira'] = Property::create([
            'code' => 'T-005', 'name' => '◇◇テナント', 'property_type' => 'tenant',
            'department' => 'tenant', 'operation_status' => 'active',
            'postal_code' => '530-0011', 'address' => '大阪府大阪市北区大淀中5-5-5',
            'structure' => 'S造', 'built_date' => '2018-12', 'total_floors' => null,
            'total_units' => 3, 'total_area' => 120.00,
            'owner_type' => 'self_owned', 'owner_name' => null,
        ]);

        // =============================================
        // 3. 区画（計21区画）
        // =============================================

        // --- ○○ビル（3階×2区画 = 6区画）---
        $u_m_3a = Unit::create(['property_id' => $properties['marumaru']->id, 'floor' => 3, 'room_number' => 'A', 'display_name' => '3A', 'area_tsubo' => 15.3, 'usage_type' => 'office',    'status' => 'occupied',     'rent' => 190000, 'common_fee' => 15000, 'deposit' => 380000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $u_m_3b = Unit::create(['property_id' => $properties['marumaru']->id, 'floor' => 3, 'room_number' => 'B', 'display_name' => '3B', 'area_tsubo' => 14.8, 'usage_type' => 'office',    'status' => 'vacant',       'rent' => 190000, 'common_fee' => 15000, 'deposit' => 380000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $u_m_2a = Unit::create(['property_id' => $properties['marumaru']->id, 'floor' => 2, 'room_number' => 'A', 'display_name' => '2A', 'area_tsubo' => 16.0, 'usage_type' => 'shop',      'status' => 'occupied',     'rent' => 160000, 'common_fee' => 12000, 'deposit' => 320000, 'garbage_fee' => 0,    'pest_control_fee' => 2000]);
        $u_m_2b = Unit::create(['property_id' => $properties['marumaru']->id, 'floor' => 2, 'room_number' => 'B', 'display_name' => '2B', 'area_tsubo' => 15.5, 'usage_type' => 'shop',      'status' => 'negotiating',  'rent' => 160000, 'common_fee' => 12000, 'deposit' => 320000, 'garbage_fee' => 0,    'pest_control_fee' => 2000]);
        $u_m_1a = Unit::create(['property_id' => $properties['marumaru']->id, 'floor' => 1, 'room_number' => 'A', 'display_name' => '1A', 'area_tsubo' => 25.0, 'usage_type' => 'shop',      'status' => 'occupied',     'rent' => 300000, 'common_fee' => 20000, 'deposit' => 600000, 'garbage_fee' => 5000, 'pest_control_fee' => 2000]);
        $u_m_1b = Unit::create(['property_id' => $properties['marumaru']->id, 'floor' => 1, 'room_number' => 'B', 'display_name' => '1B', 'area_tsubo' => 22.0, 'usage_type' => 'shop',      'status' => 'occupied',     'rent' => 280000, 'common_fee' => 18000, 'deposit' => 560000, 'garbage_fee' => 4000, 'pest_control_fee' => 0]);

        // --- △△ビル（2階×2区画 = 4区画）---
        $u_s_2a = Unit::create(['property_id' => $properties['sankaku']->id, 'floor' => 2, 'room_number' => 'A', 'display_name' => '2A', 'area_tsubo' => 18.0, 'usage_type' => 'office', 'status' => 'occupied', 'rent' => 170000, 'common_fee' => 13000, 'deposit' => 340000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $u_s_2b = Unit::create(['property_id' => $properties['sankaku']->id, 'floor' => 2, 'room_number' => 'B', 'display_name' => '2B', 'area_tsubo' => 17.5, 'usage_type' => 'office', 'status' => 'vacant',   'rent' => 165000, 'common_fee' => 13000, 'deposit' => 330000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $u_s_1a = Unit::create(['property_id' => $properties['sankaku']->id, 'floor' => 1, 'room_number' => 'A', 'display_name' => '1A', 'area_tsubo' => 20.0, 'usage_type' => 'shop',   'status' => 'occupied', 'rent' => 220000, 'common_fee' => 15000, 'deposit' => 440000, 'garbage_fee' => 4000, 'pest_control_fee' => 2000]);
        $u_s_1b = Unit::create(['property_id' => $properties['sankaku']->id, 'floor' => 1, 'room_number' => 'B', 'display_name' => '1B', 'area_tsubo' => 19.0, 'usage_type' => 'shop',   'status' => 'occupied', 'rent' => 200000, 'common_fee' => 15000, 'deposit' => 400000, 'garbage_fee' => 4000, 'pest_control_fee' => 0]);

        // --- □□ビル（2階×2区画 = 4区画）---
        $u_q_2a = Unit::create(['property_id' => $properties['shikaku']->id, 'floor' => 2, 'room_number' => 'A', 'display_name' => '2A', 'area_tsubo' => 12.0, 'usage_type' => 'warehouse', 'status' => 'occupied', 'rent' => 100000, 'common_fee' => 8000, 'deposit' => 200000, 'garbage_fee' => 2000, 'pest_control_fee' => 0]);
        $u_q_2b = Unit::create(['property_id' => $properties['shikaku']->id, 'floor' => 2, 'room_number' => 'B', 'display_name' => '2B', 'area_tsubo' => 12.0, 'usage_type' => 'warehouse', 'status' => 'vacant',   'rent' => 100000, 'common_fee' => 8000, 'deposit' => 200000, 'garbage_fee' => 2000, 'pest_control_fee' => 0]);
        $u_q_1a = Unit::create(['property_id' => $properties['shikaku']->id, 'floor' => 1, 'room_number' => 'A', 'display_name' => '1A', 'area_tsubo' => 14.0, 'usage_type' => 'shop',      'status' => 'occupied', 'rent' => 130000, 'common_fee' => 10000, 'deposit' => 260000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $u_q_1b = Unit::create(['property_id' => $properties['shikaku']->id, 'floor' => 1, 'room_number' => 'B', 'display_name' => '1B', 'area_tsubo' => 13.5, 'usage_type' => 'shop',      'status' => 'vacant',   'rent' => 125000, 'common_fee' => 10000, 'deposit' => 250000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);

        // --- ☆☆ビル（非稼働・4区画すべて空室）---
        Unit::create(['property_id' => $properties['hoshi']->id, 'floor' => 2, 'room_number' => 'A', 'display_name' => '2A', 'area_tsubo' => 10.0, 'usage_type' => 'shop', 'status' => 'vacant', 'rent' => 0, 'common_fee' => 0, 'deposit' => 0, 'garbage_fee' => 0, 'pest_control_fee' => 0]);
        Unit::create(['property_id' => $properties['hoshi']->id, 'floor' => 2, 'room_number' => 'B', 'display_name' => '2B', 'area_tsubo' => 10.0, 'usage_type' => 'shop', 'status' => 'vacant', 'rent' => 0, 'common_fee' => 0, 'deposit' => 0, 'garbage_fee' => 0, 'pest_control_fee' => 0]);
        Unit::create(['property_id' => $properties['hoshi']->id, 'floor' => 1, 'room_number' => 'A', 'display_name' => '1A', 'area_tsubo' => 10.0, 'usage_type' => 'shop', 'status' => 'vacant', 'rent' => 0, 'common_fee' => 0, 'deposit' => 0, 'garbage_fee' => 0, 'pest_control_fee' => 0]);
        Unit::create(['property_id' => $properties['hoshi']->id, 'floor' => 1, 'room_number' => 'B', 'display_name' => '1B', 'area_tsubo' => 10.0, 'usage_type' => 'shop', 'status' => 'vacant', 'rent' => 0, 'common_fee' => 0, 'deposit' => 0, 'garbage_fee' => 0, 'pest_control_fee' => 0]);

        // --- ◇◇テナント（平屋型・3区画）---
        $u_h_a = Unit::create(['property_id' => $properties['hira']->id, 'floor' => null, 'room_number' => 'A', 'display_name' => 'A', 'area_tsubo' => 13.0, 'usage_type' => 'shop', 'status' => 'occupied', 'rent' => 120000, 'common_fee' => 8000, 'deposit' => 240000, 'garbage_fee' => 2000, 'pest_control_fee' => 0]);
        $u_h_b = Unit::create(['property_id' => $properties['hira']->id, 'floor' => null, 'room_number' => 'B', 'display_name' => 'B', 'area_tsubo' => 12.0, 'usage_type' => 'shop', 'status' => 'vacant',   'rent' => 100000, 'common_fee' => 8000, 'deposit' => 200000, 'garbage_fee' => 0,    'pest_control_fee' => 0]);
        $u_h_c = Unit::create(['property_id' => $properties['hira']->id, 'floor' => null, 'room_number' => 'C', 'display_name' => 'C', 'area_tsubo' => 14.0, 'usage_type' => 'shop', 'status' => 'occupied', 'rent' => 130000, 'common_fee' => 8000, 'deposit' => 260000, 'garbage_fee' => 2000, 'pest_control_fee' => 0]);

        // =============================================
        // 4. 契約（12件: 契約中10件 + 解約済み2件）
        // =============================================

        // --- ○○ビル ---
        $c1 = Contract::create(['contract_number' => 'C-2024-001', 'department' => 'tenant', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_3a->id, 'customer_id' => $customers[0]->id, 'status' => 'active', 'contract_date' => '2024-04-01', 'rent_start_date' => '2024-05-01', 'rent' => 180000, 'common_fee' => 15000, 'deposit' => 360000, 'garbage_fee' => 3000, 'pest_control_fee' => 0, 'assigned_to' => $manager->id]);

        $c2 = Contract::create(['contract_number' => 'C-2023-005', 'department' => 'tenant', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_2a->id, 'customer_id' => $customers[1]->id, 'status' => 'active', 'contract_date' => '2023-01-15', 'rent_start_date' => '2023-02-01', 'rent' => 150000, 'common_fee' => 12000, 'deposit' => 300000, 'garbage_fee' => 0, 'pest_control_fee' => 2000, 'assigned_to' => $manager->id]);

        $c3 = Contract::create(['contract_number' => 'C-2022-010', 'department' => 'tenant', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_1a->id, 'customer_id' => $customers[2]->id, 'status' => 'active', 'contract_date' => '2022-06-01', 'rent_start_date' => '2022-07-01', 'rent' => 300000, 'common_fee' => 20000, 'deposit' => 600000, 'garbage_fee' => 5000, 'pest_control_fee' => 2000, 'assigned_to' => $staff->id]);

        $c4 = Contract::create(['contract_number' => 'C-2023-008', 'department' => 'tenant', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_1b->id, 'customer_id' => $customers[3]->id, 'status' => 'active', 'contract_date' => '2023-03-01', 'rent_start_date' => '2023-04-01', 'rent' => 280000, 'common_fee' => 18000, 'deposit' => 560000, 'garbage_fee' => 4000, 'pest_control_fee' => 0, 'assigned_to' => $staff->id]);

        // --- △△ビル ---
        $c5 = Contract::create(['contract_number' => 'C-2024-003', 'department' => 'tenant', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_2a->id, 'customer_id' => $customers[4]->id, 'status' => 'active', 'contract_date' => '2024-02-01', 'rent_start_date' => '2024-03-01', 'rent' => 165000, 'common_fee' => 13000, 'deposit' => 330000, 'garbage_fee' => 3000, 'pest_control_fee' => 0, 'assigned_to' => $manager->id]);

        $c6 = Contract::create(['contract_number' => 'C-2022-015', 'department' => 'tenant', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_1a->id, 'customer_id' => $customers[8]->id, 'status' => 'active', 'contract_date' => '2022-09-01', 'rent_start_date' => '2022-10-01', 'rent' => 220000, 'common_fee' => 15000, 'deposit' => 440000, 'garbage_fee' => 4000, 'pest_control_fee' => 2000, 'assigned_to' => $staff->id]);

        $c7 = Contract::create(['contract_number' => 'C-2023-012', 'department' => 'tenant', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_1b->id, 'customer_id' => $customers[7]->id, 'status' => 'active', 'contract_date' => '2023-07-01', 'rent_start_date' => '2023-08-01', 'rent' => 195000, 'common_fee' => 15000, 'deposit' => 390000, 'garbage_fee' => 4000, 'pest_control_fee' => 0, 'assigned_to' => $manager->id]);

        // --- □□ビル ---
        $c8 = Contract::create(['contract_number' => 'C-2024-005', 'department' => 'tenant', 'property_id' => $properties['shikaku']->id, 'unit_id' => $u_q_2a->id, 'customer_id' => $customers[5]->id, 'status' => 'active', 'contract_date' => '2024-01-01', 'rent_start_date' => '2024-02-01', 'rent' => 95000, 'common_fee' => 8000, 'deposit' => 190000, 'garbage_fee' => 2000, 'pest_control_fee' => 0, 'assigned_to' => $staff->id]);

        $c9 = Contract::create(['contract_number' => 'C-2023-020', 'department' => 'tenant', 'property_id' => $properties['shikaku']->id, 'unit_id' => $u_q_1a->id, 'customer_id' => $customers[6]->id, 'status' => 'active', 'contract_date' => '2023-10-01', 'rent_start_date' => '2023-11-01', 'rent' => 125000, 'common_fee' => 10000, 'deposit' => 250000, 'garbage_fee' => 3000, 'pest_control_fee' => 0, 'assigned_to' => $staff->id]);

        // --- ◇◇テナント（平屋型）---
        $c10 = Contract::create(['contract_number' => 'C-2025-001', 'department' => 'tenant', 'property_id' => $properties['hira']->id, 'unit_id' => $u_h_a->id, 'customer_id' => $customers[5]->id, 'status' => 'active', 'contract_date' => '2025-01-15', 'rent_start_date' => '2025-02-01', 'rent' => 115000, 'common_fee' => 8000, 'deposit' => 230000, 'garbage_fee' => 2000, 'pest_control_fee' => 0, 'assigned_to' => $manager->id]);

        $c11 = Contract::create(['contract_number' => 'C-2024-010', 'department' => 'tenant', 'property_id' => $properties['hira']->id, 'unit_id' => $u_h_c->id, 'customer_id' => $customers[6]->id, 'status' => 'active', 'contract_date' => '2024-08-01', 'rent_start_date' => '2024-09-01', 'rent' => 128000, 'common_fee' => 8000, 'deposit' => 256000, 'garbage_fee' => 2000, 'pest_control_fee' => 0, 'assigned_to' => $staff->id]);

        // --- 解約済み契約 ---
        Contract::create(['contract_number' => 'C-2021-008', 'department' => 'tenant', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_3b->id, 'customer_id' => $customers[9]->id, 'status' => 'terminated', 'contract_date' => '2021-04-01', 'rent_start_date' => '2021-05-01', 'contract_end_date' => '2024-03-31', 'termination_reason' => '事業撤退のため', 'rent' => 185000, 'common_fee' => 15000, 'deposit' => 370000, 'garbage_fee' => 3000, 'pest_control_fee' => 0, 'deposit_deduction' => 50000, 'deposit_deduction_reason' => '原状回復費用（壁クロス張替え）', 'deposit_refund_amount' => 320000, 'deposit_refund_date' => '2024-04-30', 'assigned_to' => $manager->id]);

        // =============================================
        // 5. 賃料改定履歴（2件）
        // =============================================
        RentRevision::create(['contract_id' => $c3->id, 'revision_date' => '2024-07-01', 'old_rent' => 280000, 'new_rent' => 300000, 'old_common_fee' => 18000, 'new_common_fee' => 20000, 'reason' => '契約更新に伴う改定', 'revised_by' => $admin->id]);

        RentRevision::create(['contract_id' => $c6->id, 'revision_date' => '2025-10-01', 'old_rent' => 210000, 'new_rent' => 220000, 'old_common_fee' => 15000, 'new_common_fee' => 15000, 'reason' => '物価上昇に伴う改定', 'revised_by' => $admin->id]);

        // =============================================
        // 6. 投資案件（4件: 各パターン + 各ステータス）
        // =============================================

        // 投資1: ○○ビル1A - 新装（回収中）→ 契約c3と紐づけ
        $inv1 = Investment::create([
            'investment_number' => 'INV-2022-001', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_1a->id,
            'pattern' => 'new_build', 'status' => 'recovering', 'description' => '1Fコンビニ新装工事。スケルトンから店舗を新設。',
            'start_date' => '2022-03-01', 'end_date' => '2022-05-31', 'total_amount' => 8500000,
            'contract_id' => $c3->id, 'monthly_rent' => 300000, 'recovery_start_date' => '2022-07-01',
            'estimated_recovery_months' => 29, 'estimated_recovery_date' => '2024-12-01',
            'total_recovered' => 13200000, 'recovery_rate' => 100.00, 'contractor_name' => '㈱内装工事センター',
        ]);
        // 明細
        InvestmentDetail::create(['investment_id' => $inv1->id, 'cost_item' => '内装工事',   'contractor_name' => '㈱内装工事センター',  'amount' => 5000000, 'executed_at' => '2022-04-15']);
        InvestmentDetail::create(['investment_id' => $inv1->id, 'cost_item' => '電気工事',   'contractor_name' => '△△電工',             'amount' => 1500000, 'executed_at' => '2022-04-20']);
        InvestmentDetail::create(['investment_id' => $inv1->id, 'cost_item' => '設備工事',   'contractor_name' => '□□設備',             'amount' => 2000000, 'executed_at' => '2022-05-10']);

        // 投資2: △△ビル1A - 居抜き改修（回収中）
        $inv2 = Investment::create([
            'investment_number' => 'INV-2022-005', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_1a->id,
            'pattern' => 'renovation', 'status' => 'recovering', 'description' => '前テナント退去後の居抜き改修。',
            'start_date' => '2022-07-01', 'end_date' => '2022-08-31', 'total_amount' => 3200000,
            'contract_id' => $c6->id, 'monthly_rent' => 220000, 'recovery_start_date' => '2022-10-01',
            'estimated_recovery_months' => 15, 'estimated_recovery_date' => '2024-01-01',
            'total_recovered' => 3200000, 'recovery_rate' => 100.00, 'contractor_name' => '○○リフォーム',
        ]);
        InvestmentDetail::create(['investment_id' => $inv2->id, 'cost_item' => '内装工事', 'contractor_name' => '○○リフォーム', 'amount' => 2200000, 'executed_at' => '2022-07-20']);
        InvestmentDetail::create(['investment_id' => $inv2->id, 'cost_item' => '設備工事', 'contractor_name' => '○○リフォーム', 'amount' => 1000000, 'executed_at' => '2022-08-10']);

        // 投資3: □□ビル1B - 解体新装（工事中）
        $inv3 = Investment::create([
            'investment_number' => 'INV-2026-001', 'property_id' => $properties['shikaku']->id, 'unit_id' => $u_q_1b->id,
            'pattern' => 'demolish_rebuild', 'status' => 'in_progress', 'description' => '既存内装を解体し店舗新装。2026年5月完了予定。',
            'start_date' => '2026-02-01', 'end_date' => null, 'total_amount' => 6000000,
            'contractor_name' => '㈱建設プロ',
        ]);
        InvestmentDetail::create(['investment_id' => $inv3->id, 'cost_item' => '解体費',   'contractor_name' => '㈱建設プロ', 'amount' => 1200000, 'executed_at' => '2026-02-15']);
        InvestmentDetail::create(['investment_id' => $inv3->id, 'cost_item' => '内装工事', 'contractor_name' => '㈱建設プロ', 'amount' => 3500000, 'executed_at' => null]);
        InvestmentDetail::create(['investment_id' => $inv3->id, 'cost_item' => '設計費',   'contractor_name' => '○○設計事務所', 'amount' => 1300000, 'executed_at' => '2026-01-20']);

        // 投資4: ◇◇テナントB - 新装（計画中）
        $inv4 = Investment::create([
            'investment_number' => 'INV-2026-002', 'property_id' => $properties['hira']->id, 'unit_id' => $u_h_b->id,
            'pattern' => 'new_build', 'status' => 'planning', 'description' => 'B区画のスケルトンから店舗新装計画。',
            'start_date' => null, 'end_date' => null, 'total_amount' => 4000000,
            'contractor_name' => null, 'notes' => '2026年度予算で実施予定。業者選定中。',
        ]);

        // =============================================
        // 7. 一般修繕（6件）
        // =============================================
        Repair::create(['property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_2a->id, 'status' => 'completed', 'category' => 'エアコン', 'description' => '2A号室エアコン交換（業務用）', 'contractor_name' => '△△空調サービス', 'started_at' => '2025-08-10', 'completed_at' => '2025-08-12', 'cost' => 350000]);

        Repair::create(['property_id' => $properties['marumaru']->id, 'unit_id' => null, 'status' => 'completed', 'category' => '給排水', 'description' => '共用部トイレの排水管修理', 'contractor_name' => '○○水道工事', 'started_at' => '2025-11-01', 'completed_at' => '2025-11-03', 'cost' => 180000]);

        Repair::create(['property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_2b->id, 'status' => 'completed', 'category' => '内装', 'description' => '2B号室退去後のクロス張替え', 'contractor_name' => '□□内装', 'started_at' => '2026-01-10', 'completed_at' => '2026-01-15', 'cost' => 120000]);

        Repair::create(['property_id' => $properties['shikaku']->id, 'unit_id' => null, 'status' => 'in_progress', 'category' => '外壁・屋根', 'description' => '外壁のひび割れ補修', 'contractor_name' => '㈱外壁塗装', 'started_at' => '2026-03-01', 'completed_at' => null, 'cost' => 500000]);

        Repair::create(['property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_1b->id, 'status' => 'planned', 'category' => '電気', 'description' => '1B号室の照明器具交換（LED化）', 'contractor_name' => null, 'started_at' => null, 'completed_at' => null, 'cost' => 80000, 'notes' => '次回の空室期間に実施予定']);

        Repair::create(['property_id' => $properties['hira']->id, 'unit_id' => $u_h_a->id, 'status' => 'completed', 'category' => 'エアコン', 'description' => 'A区画エアコンフィルター清掃・ガス補充', 'contractor_name' => '△△空調サービス', 'started_at' => '2025-12-20', 'completed_at' => '2025-12-20', 'cost' => 25000]);

        // =============================================
        // 8. 問合せ（6件 + 履歴）
        // =============================================

        // INQ-1: 対応中（3回の履歴）
        $inq1 = Inquiry::create(['inquiry_number' => 'INQ-2026-008', 'contact_name' => '田中一郎', 'contact_phone' => '090-1234-5678', 'contact_email' => 'tanaka@xxx.com', 'status' => 'active', 'initial_property_id' => $properties['marumaru']->id, 'initial_unit_id' => $u_m_2b->id, 'source' => '電話', 'assigned_to' => $manager->id, 'first_contact_date' => '2026-03-10']);

        InquiryHistory::create(['inquiry_id' => $inq1->id, 'contact_date' => '2026-03-10', 'contact_method' => '電話', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_2b->id, 'content' => '2F B号室の空き状況について電話あり。面積・賃料を伝えたところ、内覧希望。', 'next_action' => '内覧日調整（3/12までに連絡）', 'next_action_date' => '2026-03-12', 'recorded_by' => $manager->id]);
        InquiryHistory::create(['inquiry_id' => $inq1->id, 'contact_date' => '2026-03-15', 'contact_method' => '電話', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_2b->id, 'content' => '内覧日を3/18に決定。13:00に現地集合。', 'next_action' => '内覧対応（3/18）', 'next_action_date' => '2026-03-18', 'recorded_by' => $manager->id]);
        InquiryHistory::create(['inquiry_id' => $inq1->id, 'contact_date' => '2026-03-20', 'contact_method' => '電話', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_2b->id, 'content' => '別の物件（△△ビル 2B）も見たいと連絡あり。内覧は3/25に調整中。', 'next_action' => '△△ビル内覧（3/25予定）', 'next_action_date' => '2026-03-25', 'recorded_by' => $manager->id]);

        // INQ-2: 対応中
        $inq2 = Inquiry::create(['inquiry_number' => 'INQ-2026-007', 'contact_name' => '㈱山田商事', 'contact_phone' => '03-7890-1234', 'contact_email' => 'yamada@yyy.com', 'contact_company' => '㈱山田商事', 'status' => 'active', 'initial_property_id' => $properties['sankaku']->id, 'initial_unit_id' => $u_s_2b->id, 'source' => 'メール', 'assigned_to' => $staff->id, 'first_contact_date' => '2026-03-08']);
        InquiryHistory::create(['inquiry_id' => $inq2->id, 'contact_date' => '2026-03-08', 'contact_method' => 'メール', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_2b->id, 'content' => 'Webサイト経由で事務所の空き物件についてメールあり。△△ビル2Bを紹介。', 'next_action' => '資料送付', 'next_action_date' => '2026-03-10', 'recorded_by' => $staff->id]);
        InquiryHistory::create(['inquiry_id' => $inq2->id, 'contact_date' => '2026-03-12', 'contact_method' => '電話', 'property_id' => $properties['sankaku']->id, 'unit_id' => $u_s_2b->id, 'content' => '資料を確認いただき、内覧の日程を調整中。', 'next_action' => '内覧日確定', 'next_action_date' => '2026-03-15', 'recorded_by' => $staff->id]);

        // INQ-3: 成約（顧客・契約と紐づけ）
        $inq3 = Inquiry::create(['inquiry_number' => 'INQ-2026-006', 'contact_name' => '佐藤花子', 'contact_phone' => '090-1234-5678', 'status' => 'converted', 'initial_property_id' => $properties['marumaru']->id, 'initial_unit_id' => $u_m_3a->id, 'source' => '紹介', 'assigned_to' => $manager->id, 'first_contact_date' => '2024-02-15', 'converted_customer_id' => $customers[0]->id, 'converted_contract_id' => $c1->id]);
        InquiryHistory::create(['inquiry_id' => $inq3->id, 'contact_date' => '2024-02-15', 'contact_method' => '電話', 'property_id' => $properties['marumaru']->id, 'unit_id' => $u_m_3a->id, 'content' => '知人からの紹介で問合せ。○○ビル3Aの事務所を希望。', 'next_action' => '内覧手配', 'recorded_by' => $manager->id]);

        // INQ-4: 不成約
        $inq4 = Inquiry::create(['inquiry_number' => 'INQ-2026-005', 'contact_name' => '鈴木太郎', 'contact_phone' => '080-9876-5432', 'status' => 'lost', 'initial_property_id' => $properties['shikaku']->id, 'initial_unit_id' => $u_q_2b->id, 'source' => '看板', 'assigned_to' => $staff->id, 'first_contact_date' => '2026-03-01']);
        InquiryHistory::create(['inquiry_id' => $inq4->id, 'contact_date' => '2026-03-01', 'contact_method' => '電話', 'property_id' => $properties['shikaku']->id, 'unit_id' => $u_q_2b->id, 'content' => '看板を見て倉庫の空きについて問合せ。', 'next_action' => '内覧日調整', 'recorded_by' => $staff->id]);
        InquiryHistory::create(['inquiry_id' => $inq4->id, 'contact_date' => '2026-03-05', 'contact_method' => '電話', 'property_id' => $properties['shikaku']->id, 'unit_id' => $u_q_2b->id, 'content' => '予算が合わないとのことで見送り。', 'next_action' => null, 'recorded_by' => $staff->id]);

        // INQ-5: 保留
        $inq5 = Inquiry::create(['inquiry_number' => 'INQ-2026-004', 'contact_name' => '木村洋子', 'contact_phone' => '070-1111-2222', 'contact_company' => '㈱木村フーズ', 'status' => 'on_hold', 'initial_property_id' => $properties['hira']->id, 'initial_unit_id' => $u_h_b->id, 'source' => 'Web', 'assigned_to' => $manager->id, 'first_contact_date' => '2026-02-20', 'notes' => '現在工事中のため、完成後に再連絡予定']);
        InquiryHistory::create(['inquiry_id' => $inq5->id, 'contact_date' => '2026-02-20', 'contact_method' => 'メール', 'property_id' => $properties['hira']->id, 'unit_id' => $u_h_b->id, 'content' => 'Web問合せ。◇◇テナントB区画の飲食店利用について。', 'next_action' => '工事完了後に連絡', 'next_action_date' => '2026-06-01', 'recorded_by' => $manager->id]);

        // INQ-6: 対応中
        $inq6 = Inquiry::create(['inquiry_number' => 'INQ-2026-009', 'contact_name' => '高橋健太', 'contact_phone' => '090-5555-6666', 'contact_email' => 'takahashi@zzz.com', 'status' => 'active', 'initial_property_id' => $properties['shikaku']->id, 'initial_unit_id' => $u_q_2b->id, 'source' => '電話', 'assigned_to' => $staff->id, 'first_contact_date' => '2026-03-15']);
        InquiryHistory::create(['inquiry_id' => $inq6->id, 'contact_date' => '2026-03-15', 'contact_method' => '電話', 'property_id' => $properties['shikaku']->id, 'unit_id' => $u_q_2b->id, 'content' => '□□ビル2Bの倉庫利用について問合せ。資料郵送を依頼された。', 'next_action' => '資料郵送', 'next_action_date' => '2026-03-18', 'recorded_by' => $staff->id]);

        // =============================================
        // 9. 収支データ（25件: 家賃収入を中心に）
        // =============================================
        $baseDate = '2026-03';

        // 家賃収入（各契約中の契約分 — 2026年3月分）
        $activeContracts = [$c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10, $c11];
        foreach ($activeContracts as $contract) {
            Transaction::create([
                'department' => 'tenant', 'transaction_type' => 'income',
                'transaction_date' => '2026-03-01', 'accounting_ym' => $baseDate,
                'category' => '家賃', 'amount_excl_tax' => $contract->rent, 'tax_amount' => 0, 'amount_incl_tax' => $contract->rent,
                'property_id' => $contract->property_id, 'customer_id' => $contract->customer_id, 'contract_id' => $contract->id,
                'summary' => $contract->contract_number . ' 家賃（2026年3月分）', 'registered_by' => $admin->id,
            ]);
        }

        // 共益費収入（2026年3月分・まとめて数件）
        foreach ([$c1, $c3, $c5, $c6] as $contract) {
            if ($contract->common_fee > 0) {
                Transaction::create([
                    'department' => 'tenant', 'transaction_type' => 'income',
                    'transaction_date' => '2026-03-01', 'accounting_ym' => $baseDate,
                    'category' => '共益費', 'amount_excl_tax' => $contract->common_fee, 'tax_amount' => 0, 'amount_incl_tax' => $contract->common_fee,
                    'property_id' => $contract->property_id, 'customer_id' => $contract->customer_id, 'contract_id' => $contract->id,
                    'summary' => $contract->contract_number . ' 共益費（2026年3月分）', 'registered_by' => $admin->id,
                ]);
            }
        }

        // 修繕費用の支出
        Transaction::create([
            'department' => 'tenant', 'transaction_type' => 'expense',
            'transaction_date' => '2025-08-12', 'accounting_ym' => '2025-08',
            'category' => '修繕費', 'amount_excl_tax' => 318182, 'tax_amount' => 31818, 'amount_incl_tax' => 350000,
            'property_id' => $properties['marumaru']->id, 'customer_id' => null, 'contract_id' => null,
            'summary' => '2A号室エアコン交換費用', 'registered_by' => $manager->id,
        ]);

        // 投資費用の支出
        Transaction::create([
            'department' => 'tenant', 'transaction_type' => 'expense',
            'transaction_date' => '2022-05-31', 'accounting_ym' => '2022-05',
            'category' => '投資費用', 'amount_excl_tax' => 7727273, 'tax_amount' => 772727, 'amount_incl_tax' => 8500000,
            'property_id' => $properties['marumaru']->id, 'customer_id' => null, 'contract_id' => null,
            'summary' => 'INV-2022-001 1Fコンビニ新装工事費用', 'registered_by' => $admin->id,
        ]);
    }
}
