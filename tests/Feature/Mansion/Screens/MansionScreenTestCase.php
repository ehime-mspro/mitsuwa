<?php

namespace Tests\Feature\Mansion\Screens;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ComposesScreenForms;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsScreenForms;
use Tests\TestCase;

/**
 * 賃貸マンションの画面のテストの土台（物件・部屋・駐車場・入居者・契約の登録・編集・削除を、描いた画面から送る往復で見る）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。Alpine が値を入れる欄は DrivesAlpineFetch::browserForm()、
 *   契約の登録画面が API から取る部屋・駐車場は、JS が組んだ要求を送ってその応答を JS に返す（apiResponse()）。
 * ⚠ `ms_*` は raw SQL 管理でテスト用スキーマ（CreatesMansionSchema）に外部キーが無い。本番は契約→部屋・駐車場・入居者が
 *   ON DELETE RESTRICT（2026-10-07 に読み取りで確認）なので、本番で 500 になる削除もテストでは黙って通る。
 *   削除の歯止めは「行が残ること」で見る（外部キーという安全網が無いので、残っていれば歯止めが効いている）。
 * ⚠ 入力エラーは各画面の上の赤い箱に 1 件ずつ出る（`<li>`）。1 件の全文で見る（Bug #49。assertErrorItem()）。
 */
abstract class MansionScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use CreatesMansionSchema;
    use SubmitsScreenForms;
    use ComposesScreenForms;

    /** 本番の金額の列（INT UNSIGNED）の上限 */
    protected const UINT_MAX = 4294967295;

    protected User $user;

    protected MsProperty $building;

    private bool $departmentsSeeded = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();

        $this->user = $this->member(UserRole::Executive);
        $this->building = $this->property();
    }

    /**
     * 賃貸マンションの部署に属する利用者（経営層以外は部署の紐付けが無いと 403）。
     * ⚠ DepartmentSeeder は冪等ではないので 1 度だけ流す。
     */
    protected function member(UserRole $role, string $name = '確認 太郎'): User
    {
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }
        $user = User::factory()->create(['name' => $name, 'role' => $role->value, 'must_change_password' => false]);
        $user->departments()->attach(Department::where('code', 'mansion')->value('id'));

        return $user;
    }

    /** 画面の上の入力エラーの箱に $message が出ている（1 件の全文） */
    protected function assertErrorItem(string $html, string $message): void
    {
        $this->assertTrue(str_contains($html, '入力内容にエラーがあります。'), '入力エラーの箱が出ていない');
        $this->assertSame(1, preg_match('/<li>\s*' . preg_quote(e($message), '/') . '\s*<\/li>/u', $html), "入力エラーに「{$message}」が出ていない");
    }

    protected function property(array $overrides = []): MsProperty
    {
        return MsProperty::create(array_merge([
            'property_code' => 'MS-001',
            'property_name' => 'ミツワレジデンス',
            'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市一番町1-1',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function room(string $number = '101', string $status = 'vacant', array $overrides = []): MsRoom
    {
        return MsRoom::create(array_merge([
            'property_id' => $this->building->id,
            'room_number' => $number,
            'floor' => 1,
            'room_type' => '1K',
            'status' => $status,
            'rent' => 70000,
            'common_fee' => 5000,
        ], $overrides));
    }

    protected function parking(string $number = 'A-1', string $status = 'vacant', array $overrides = []): MsParking
    {
        return MsParking::create(array_merge([
            'property_id' => $this->building->id,
            'parking_number' => $number,
            'monthly_fee' => 5000,
            'status' => $status,
        ], $overrides));
    }

    protected function tenant(string $name = '山田 太郎', string $type = 'resident'): MsTenant
    {
        return MsTenant::create(['tenant_type' => $type, 'name' => $name]);
    }

    /** 契約中の部屋契約（部屋は入居中にする） */
    protected function contract(MsRoom $room, MsTenant $tenant, array $overrides = []): MsContract
    {
        $room->update(['status' => 'occupied']);

        return MsContract::create(array_merge([
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'contract_date' => '2025-03-20',
            'move_in_date' => '2025-04-01',
            'rent' => 70000,
            'common_fee' => 5000,
            'deposit' => 140000,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /** 契約中の駐車場契約（駐車場は使用中にする） */
    protected function parkingContract(MsParking $parking, MsTenant $tenant, ?MsContract $contract = null, array $overrides = []): MsParkingContract
    {
        $parking->update(['status' => 'occupied']);

        return MsParkingContract::create(array_merge([
            'parking_id' => $parking->id,
            'tenant_id' => $tenant->id,
            'contract_id' => $contract?->id,
            'status' => 'active',
            'contract_date' => '2025-03-20',
            'start_date' => '2025-04-01',
            'monthly_fee' => 5000,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /**
     * 削除フォーム（`@method('DELETE')`）を描いた画面から取る。
     * ⚠ 編集画面は更新のフォームと削除のフォームが同じ URL を送り先にする（PUT と DELETE）。送り先だけで探すと更新のフォームを掴むので、
     *   送り先が $action のフォームのうち DELETE を送るものを選ぶ（ちょうど 1 つ）。
     */
    protected function deleteForm(string $html, string $action): array
    {
        preg_match_all('/<form\b([^>]*\baction="' . preg_quote($action, '/') . '"[^>]*)>/', $html, $tags);
        $found = [];
        foreach ($tags[1] as $attributes) {
            $form = $this->parseForm($html, $attributes);
            if (($form['fields']['_method'] ?? null) === 'DELETE') {
                $found[] = $form;
            }
        }
        $this->assertCount(1, $found, "送り先が {$action} の削除フォームがちょうど 1 つ描かれていない");

        return $found[0];
    }
}
