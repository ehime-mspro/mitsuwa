<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,       // 1. 6部門マスター（常に必要）
            UserSeeder::class,             // 2. テストユーザー3名（常に必要）
            TenantTestDataSeeder::class,   // 3. テナント部門テストデータ（開発環境用）
        ]);
    }
}
