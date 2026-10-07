<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\Department;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ComposesScreenForms;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsScreenForms;
use Tests\TestCase;

/**
 * 住宅事業の画面のテストの土台（建売・注文住宅・契約・顧客の登録・編集・削除を、描いた画面から送る往復で見る）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。Alpine が値を入れる欄は DrivesAlpineFetch::browserForm()、
 *   Ajax（区画の一覧・ステータスの小窓・ファイルの削除・買主の簡易登録）は driveAlpine() で JS が組んだ要求をそのまま送り、
 *   応答を JS に戻す。
 * ⚠ 入力エラーは各画面の上の赤い箱に 1 件ずつ出る（`<li>`）。項目名だけで見るとラベルに一致して素通りするので、
 *   1 件の全文で見る（Bug #49。assertErrorItem()）。
 * ⚠ 帯の文言・送り方は SubmitsScreenForms（テナント・不動産の画面のテストと共用）。
 */
abstract class HousingScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;
    use SubmitsScreenForms;
    use ComposesScreenForms;

    protected const INT_MAX = 2147483647;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        // 顧客の登録・編集画面が設問マスタを読む（テスト用スキーマは raw SQL の鏡）
        $this->createSurveyQuestionSchema();

        $this->user = $this->member(UserRole::Executive);
    }

    private bool $departmentsSeeded = false;

    /**
     * 住宅事業の部署に属する利用者（経営層以外は部署の紐付けが無いと住宅事業の画面に 403）。
     * ⚠ DepartmentSeeder は Department::create() なので冪等ではない。1 度だけ流す。
     */
    protected function member(UserRole $role, string $name = '確認 太郎'): User
    {
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }
        $user = User::factory()->create([
            'name' => $name,
            'role' => $role->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'housing')->value('id'));

        return $user;
    }

    /** 画面の上の入力エラーの箱に $message が出ている（1 件の全文） */
    protected function assertErrorItem(string $html, string $message): void
    {
        $text = preg_quote(e($message), '/');
        $this->assertSame(1, preg_match('/<li>\s*' . $text . '\s*<\/li>/u', $html), "入力エラーに「{$message}」が出ていない");
    }

    /** 買主の選択欄で $buyer を選ぶ（画面の onSelect() が顧客名を入れる）。composedForm() の入れ子に渡す JS */
    protected function chooseBuyer(?Buyer $buyer): string
    {
        return $buyer === null ? '' : 'data.$refs.sel.value = "' . $buyer->id . '"; data.onSelect();';
    }

    protected function project(array $overrides = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code' => 'RE-PRJ-001',
            'project_name' => '平井分譲地',
            'status' => 'selling',
            'address' => '愛媛県松山市平井町1',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function lot(ReProject $project, int $number, int $price = 12000000, string $status = 'on_sale'): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $number,
            'area_sqm' => 200,
            'area_tsubo' => 60.5,
            'selling_price' => $price,
            'status' => $status,
        ]);
    }

    protected function procurement(array $overrides = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => 'RE-PRC-001',
            'property_type' => 'used_house',
            'transaction_type' => 'purchase',
            'status' => 'settled',
            'property_name' => '勝山の土地',
            'address' => '愛媛県松山市勝山町1-1',
            'land_area_sqm' => 150,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /** 住宅事業の部署に属する買主（顧客の画面は部署の紐付けが無いと 404） */
    protected function buyer(string $last = '山田', string $first = '太郎'): Buyer
    {
        $buyer = Buyer::create(['last_name' => $last, 'first_name' => $first, 'prefecture' => '愛媛県', 'city' => '松山市']);
        $buyer->addToDepartment('housing', '2026-09-01');

        return $buyer;
    }

    protected function property(array $overrides = []): HsProperty
    {
        return HsProperty::create(array_merge([
            'property_code' => 'HS-001',
            'property_name' => '平井 建売 1',
            'status' => 'design',
            'address' => '愛媛県松山市平井町1-3',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function customOrder(array $overrides = []): HsCustomOrder
    {
        return HsCustomOrder::create(array_merge([
            'order_code' => 'CO-001',
            'order_name' => '山田邸 新築工事',
            'status' => 'consultation',
            'customer_name' => '山田 太郎',
            'address' => '愛媛県松山市平井町1-2',
            'tax_rate' => 10,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function contract(HsProperty $property, Buyer $buyer, array $overrides = []): HsContract
    {
        return HsContract::create(array_merge([
            'property_id' => $property->id,
            'customer_id' => $buyer->id,
            'customer_name' => $buyer->full_name,
            'selling_price_land' => 10000000,
            'selling_price_building' => 20000000,
            'tax_rate' => 10,
            'contract_date' => '2026-09-01',
            'created_by' => $this->user->id,
        ], $overrides));
    }
}
