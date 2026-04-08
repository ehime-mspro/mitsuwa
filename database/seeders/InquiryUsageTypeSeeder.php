<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InquiryUsageTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => '飲食店',           'sort_order' => 1],
            ['name' => '物販店',           'sort_order' => 2],
            ['name' => '美容室・理容室',    'sort_order' => 3],
            ['name' => 'クリニック',        'sort_order' => 4],
            ['name' => '事務所',           'sort_order' => 5],
            ['name' => '学習塾・教室',      'sort_order' => 6],
            ['name' => '倉庫',             'sort_order' => 7],
            ['name' => 'フィットネス・ジム', 'sort_order' => 8],
            ['name' => 'その他',           'sort_order' => 9],
        ];

        $now = now();
        foreach ($types as &$type) {
            $type['created_at'] = $now;
            $type['updated_at'] = $now;
        }

        DB::table('inquiry_usage_types')->insertOrIgnore($types);
    }
}
