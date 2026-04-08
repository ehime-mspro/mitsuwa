<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * 開発・動作確認用のテストユーザーを作成する。
     */
    public function run(): void
    {
        // =============================================
        // 1. 経営層（全部門所属）
        // =============================================
        $admin = User::create([
            'name' => '山田太郎',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'executive',
            'status' => 'active',
            'must_change_password' => false, // 開発用のためfalse
        ]);

        // 全6部門に所属
        $allDepartments = Department::pluck('id')->toArray();
        $admin->departments()->attach($allDepartments);

        // =============================================
        // 2. 部門管理者（テナント部門）
        // =============================================
        $manager = User::create([
            'name' => '鈴木一郎',
            'email' => 'manager@example.com',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $tenantDept = Department::where('code', 'tenant')->first();
        $manager->departments()->attach($tenantDept->id);

        // =============================================
        // 3. 一般担当者（テナント部門）
        // =============================================
        $staff = User::create([
            'name' => '田中花子',
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $staff->departments()->attach($tenantDept->id);
    }
}
