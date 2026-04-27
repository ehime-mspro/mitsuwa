<?php

namespace Database\Seeders;

use App\Models\Contract;
use Illuminate\Database\Seeder;

class AddStoreNameSeeder extends Seeder
{
    /**
     * 既存の契約データにstore_name（店舗名）を追加する。
     * STEP 4: フロアマップで店舗名を表示するため。
     */
    public function run(): void
    {
        $storeNames = [
            'C-2024-001' => 'ABCカフェ',                   // c1: ○○ビル 3A ← ㈱ABC商事
            'C-2023-005' => '△△薬局',                     // c2: ○○ビル 2A ← △△薬局
            'C-2022-010' => '○○コンビニ虎ノ門店',          // c3: ○○ビル 1A ← ○○コンビニ
            'C-2023-008' => '□□クリニック',                // c4: ○○ビル 1B ← □□クリニック
            'C-2024-003' => '㈱XYZ事務所',                 // c5: △△ビル 2A ← ㈱XYZ
            'C-2022-015' => '㈱山田商事 神宮前店',          // c6: △△ビル 1A ← ㈱山田商事
            'C-2023-012' => '㈱田中商事 渋谷支店',          // c7: △△ビル 1B ← ㈱田中商事
            'C-2024-005' => '○○商店 梅田店',               // c8: □□ビル 2A ← ○○商店
            'C-2023-020' => '□□工房',                     // c9: □□ビル 1A ← □□工房
            'C-2025-001' => '○○商店 大淀店',               // c10: ◇◇テナント A ← ○○商店
            'C-2024-010' => '□□工房 大淀店',               // c11: ◇◇テナント C ← □□工房
            'C-2021-008' => '佐藤花子事務所',               // 解約済: ○○ビル 3B ← 佐藤花子
        ];

        foreach ($storeNames as $contractNumber => $storeName) {
            Contract::where('contract_number', $contractNumber)
                ->update(['store_name' => $storeName]);
        }
    }
}
