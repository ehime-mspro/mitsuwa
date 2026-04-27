<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * 6部門の初期データを投入する。
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'テナント',   'code' => 'tenant',     'display_order' => 1],
            ['name' => 'マンション', 'code' => 'mansion',    'display_order' => 2],
            ['name' => '住宅',       'code' => 'housing',    'display_order' => 3],
            ['name' => '不動産',     'code' => 'realestate', 'display_order' => 4],
            ['name' => '福祉',       'code' => 'welfare',    'display_order' => 5],
            ['name' => 'DAD',        'code' => 'dad',        'display_order' => 6],
        ];

        foreach ($departments as $dept) {
            Department::create($dept);
        }
    }
}
