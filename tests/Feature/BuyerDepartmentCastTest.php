<?php

namespace Tests\Feature;

use App\Enums\BuyerDepartment;
use App\Enums\BuyerRank;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * buyer_departments.department が enum としてキャストされていることを固定する。
 *
 * 本番 MySQL は enum('housing','realestate') で範囲外を弾けるが、テストの SQLite は
 * string(20) なので**黙って通る**（docs/RULES.md Bug #40 と同じ「テストだけ静かに通る」型）。
 * キャストがあれば Laravel が PHP レベルで弾くので、**DB エンジンに依存せず**守られる
 * （vendor の HasAttributes::getEnumCaseFromValue() が $enumClass::from() を呼ぶ）。
 *
 * ⚠ 2 本は**対で**必要。
 *   - cast だけ入れて buyers/show.blade.php を直さないと、enum インスタンスが
 *     BuyerDepartment::from() に渡って TypeError になり顧客詳細が全件 500（Bug #22 と同型）
 *   - 画面だけ直して cast を入れないと、範囲外の値が素通りしたままになる
 *   片方だけだとどちらかを見逃す。
 */
class BuyerDepartmentCastTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeBuyer(string $department = 'realestate'): Buyer
    {
        $buyer = Buyer::create(['last_name' => '部署', 'first_name' => '太郎']);

        BuyerDepartmentPivot::create([
            'buyer_id'      => $buyer->id,
            'department'    => $department,
            'acquired_date' => '2026-01-05',
            'rank'          => BuyerRank::C->value,
        ]);

        return $buyer;
    }

    /**
     * enum の範囲外の部署は書き込めない。
     *
     * ⚠ SQLite の string 列は何でも受けるので、この防御は**キャストだけ**が担っている。
     *   $casts から 'department' を外すとこのテストが赤になる。
     */
    public function test_out_of_range_department_is_rejected(): void
    {
        $buyer = Buyer::create(['last_name' => '範囲外', 'first_name' => '太郎']);

        $this->expectException(\ValueError::class);

        BuyerDepartmentPivot::create([
            'buyer_id'      => $buyer->id,
            'department'    => 'tenant', // enum('housing','realestate') の範囲外
            'acquired_date' => '2026-01-05',
            'rank'          => BuyerRank::C->value,
        ]);
    }

    /** 有効な値は enum インスタンスとして読み出せる（キャストが実際に効いていること）。 */
    public function test_valid_department_reads_back_as_enum(): void
    {
        $buyer = $this->makeBuyer('realestate');

        $this->assertSame(
            BuyerDepartment::RealEstate,
            $buyer->getDepartmentPivot('realestate')->department,
        );
    }

    /**
     * 顧客詳細が 200 を返し、部署バッジを描画する。
     *
     * ⚠ `assertSee('不動産事業')` では false-pass する — パンくずの $deptLabel も同じ文字列。
     *   バッジ固有の inline style（BuyerDepartment::RealEstate->badgeStyle()）で見る（Bug #43）。
     */
    public function test_customer_detail_renders_department_badge(): void
    {
        $buyer = $this->makeBuyer('realestate');

        $res = $this->actingAs($this->executive())->get('/realestate/customers/' . $buyer->id);

        $res->assertOk();
        $res->assertSee(BuyerDepartment::RealEstate->badgeStyle(), false);
    }
}
