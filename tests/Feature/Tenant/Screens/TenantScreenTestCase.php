<?php

namespace Tests\Feature\Tenant\Screens;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesStructureTypeSchema;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * テナント管理の画面のテストの土台（登録・編集・削除を、描いた画面から送る往復で見る）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。Alpine が値を入れる欄（`x-model`・`:value`・`<template x-for>` の行・
 *   JS が足す選択肢）は DrivesAlpineFetch::browserForm() が JS の状態で評価する。静的な parseForm() だけで送ると、
 *   画面から値が消えても（`x-model` を外しても）緑のままになる。
 * ⚠ 成功・失敗の文はレイアウトの帯（`text-emerald-800` / `text-red-800` の span）に出る。文言だけで見ると、
 *   同じ名前が画面の別の場所にも出ているので取り違える（Bug #43）。帯の要素ごと見る（assertFlash()）。
 * ⚠ 入力エラーは画面の上の箱（「入力内容にエラーがあります。」の下の `<li>`）に出る。項目名だけで見ると
 *   ラベルに一致して素通りする（Bug #49）。`<li>` の全文で見る（assertInputError()）。
 */
abstract class TenantScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use CreatesStructureTypeSchema;

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

    protected function htmlOf(string $url): string
    {
        return $this->actingAs($this->user)->get($url)->assertOk()->getContent();
    }

    /**
     * 利用者が欄に打ち込む（Alpine の値を持たない素の欄）。画面に無い項目は足さない（足すと、画面から欄が消えても緑になる）。
     */
    protected function fill(array $form, array $values): array
    {
        foreach ($values as $name => $value) {
            $this->assertArrayHasKey($name, $form['fields'], "画面のフォームに「{$name}」の欄が無い");
            $form['fields'][$name] = $value;
        }

        return $form;
    }

    /** 画面から送る（$from は送った画面＝入力エラーで戻る先） */
    protected function submit(array $form, string $from): TestResponse
    {
        return $this->actingAs($this->user)->from($from)
            ->call($form['method'] === 'GET' ? 'GET' : 'POST', $form['action'], $form['fields']);
    }

    /** 転送をたどって着いた画面の HTML（Bug #63: 行き先の URL だけでなく、着いた画面で文言を見る） */
    protected function landed(TestResponse $response): string
    {
        return $this->followRedirects($response)->assertOk()->getContent();
    }

    /** レイアウトの帯に $message が出ている（$type は success / error） */
    protected function assertFlash(string $html, string $type, string $message): void
    {
        $class = $type === 'success' ? 'text-emerald-800' : 'text-red-800';
        $this->assertMatchesRegularExpression(
            '/<span class="text-sm ' . $class . '">\s*' . preg_quote(e($message), '/') . '\s*<\/span>/u',
            $html,
            "帯（{$type}）に「{$message}」が出ていない"
        );
    }

    /** 画面の上の入力エラーの箱に $message が出ている */
    protected function assertInputError(string $html, string $message): void
    {
        $this->assertStringContainsString('入力内容にエラーがあります。', $html, '入力エラーの箱が出ていない');
        $this->assertMatchesRegularExpression(
            '/<li>\s*' . preg_quote(e($message), '/') . '\s*<\/li>/u',
            $html,
            "入力エラーに「{$message}」が出ていない"
        );
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
