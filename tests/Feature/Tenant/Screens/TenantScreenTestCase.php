<?php

namespace Tests\Feature\Tenant\Screens;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStructureTypeSchema;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsScreenForms;
use Tests\TestCase;

/**
 * テナント管理の画面のテストの土台（登録・編集・削除を、描いた画面から送る往復で見る）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。Alpine が値を入れる欄（`x-model`・`:value`・`<template x-for>` の行・
 *   JS が足す選択肢）は DrivesAlpineFetch::browserForm() が JS の状態で評価する。静的な parseForm() だけで送ると、
 *   画面から値が消えても（`x-model` を外しても）緑のままになる。
 * ⚠ 送る・着いた画面を見る・帯の文言は SubmitsScreenForms（不動産の画面のテストと共用）。
 * ⚠ 入力エラーは画面の上の箱（「入力内容にエラーがあります。」の下の `<li>`）に出る。項目名だけで見ると
 *   ラベルに一致して素通りする（Bug #49）。`<li>` の全文で見る（assertInputError()）。
 */
abstract class TenantScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use CreatesStructureTypeSchema;
    use SubmitsScreenForms;

    protected User $user;

    protected Property $building;

    protected function setUp(): void
    {
        parent::setUp();

        // 物件の登録・編集画面が構造マスターを読む（テスト用スキーマは raw SQL の鏡）
        $this->createStructureTypeSchema();

        $this->user = User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $this->building = Property::create([
            'code' => 'T-001', 'name' => '画面テストビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市大街道1-1', 'total_floors' => 5, 'operation_status' => 'active',
        ]);
    }

    /** 画面の上の入力エラーの箱に $message が出ている */
    protected function assertInputError(string $html, string $message): void
    {
        $this->assertTrue(str_contains($html, '入力内容にエラーがあります。'), '入力エラーの箱が出ていない');
        $pattern = '/<li>\s*' . preg_quote(e($message), '/') . '\s*<\/li>/u';
        $this->assertSame(1, preg_match($pattern, $html), "入力エラーに「{$message}」が出ていない");
    }

    protected function unit(?int $floor, string $room, string $status = 'vacant', ?Property $property = null): Unit
    {
        return Unit::create([
            'property_id' => ($property ?? $this->building)->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => $status,
            'area_tsubo' => 12.5,
            'rent' => 80000,
            'common_fee' => 8000,
        ]);
    }

    protected function customer(string $name = '山田商事', string $code = 'CU-0001'): Customer
    {
        return Customer::create(['code' => $code, 'name' => $name, 'customer_type' => 'corporation']);
    }

    /** 契約中の契約（区画は入居中にする） */
    protected function activeContract(Unit $unit, ?Customer $customer = null, array $overrides = []): Contract
    {
        $unit->update(['status' => 'occupied']);

        return Contract::create(array_merge([
            'contract_number' => 'C-2025-001',
            'department' => 'tenant',
            'property_id' => $unit->property_id,
            'unit_id' => $unit->id,
            'customer_id' => ($customer ?? $this->customer())->id,
            'status' => 'active',
            'contract_date' => '2025-04-01',
            'rent_start_date' => '2025-04-01',
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
            'deposit' => 300000,
            'initial_month_type' => 'full',
        ], $overrides));
    }
}
