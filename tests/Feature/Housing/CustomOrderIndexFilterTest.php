<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 注文住宅一覧のステータスフィルタを検証する。
 *
 * 既定は「全て」なので無指定では素通りするが、ユーザーが明示的に
 * 「ステータス: 全て」を選ぶと ?status= が飛び、ConvertEmptyStringsToNull で
 * null 化された値が `!== ''` を素通りして where('status', null) → 0 件になる。
 *
 * hs_* は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class CustomOrderIndexFilterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access:housing を無条件通過する） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 一覧が描画するのは order_name（案件名）。アサーションはこれで行う */
    private function makeOrder(string $code, string $status = 'estimation'): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'    => $code,
            'order_name'    => "注文住宅{$code}",
            'status'        => $status,
            'customer_name' => '佐藤 花子',
            'address'       => '愛媛県松山市3-3-3',
            'created_by'    => 1,
        ]);
    }

    /** 無指定（既定＝全て）では全ステータスが出る */
    public function test_index_without_status_shows_every_status(): void
    {
        $estimation = $this->makeOrder('CO-001', 'estimation');
        $contracted = $this->makeOrder('CO-002', 'contracted');
        $delivered  = $this->makeOrder('CO-003', 'delivered');

        $response = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $response->assertOk();
        $response->assertSee($estimation->order_name);
        $response->assertSee($contracted->order_name);
        $response->assertSee($delivered->order_name);
    }

    /**
     * 「ステータス: 全て」（?status=）でも全ステータスが出る。
     *
     * `$request->input('status', '')` はキーが存在するため既定値ではなく null を返し、
     * `!== ''` 比較では null が素通りして where('status', null) となり 0 件になる。
     */
    public function test_index_status_all_shows_every_status(): void
    {
        $estimation = $this->makeOrder('CO-001', 'estimation');
        $contracted = $this->makeOrder('CO-002', 'contracted');
        $delivered  = $this->makeOrder('CO-003', 'delivered');

        $response = $this->actingAs($this->executive())->get('/housing/custom-orders?status=');

        $response->assertOk();
        $response->assertSee($estimation->order_name);
        $response->assertSee($contracted->order_name);
        $response->assertSee($delivered->order_name);
    }

    /** ?status=contracted は契約済だけを出す（フィルタ自体は壊さない） */
    public function test_index_status_contracted_shows_only_contracted(): void
    {
        $estimation = $this->makeOrder('CO-001', 'estimation');
        $contracted = $this->makeOrder('CO-002', 'contracted');

        $response = $this->actingAs($this->executive())->get('/housing/custom-orders?status=contracted');

        $response->assertOk();
        $response->assertSee($contracted->order_name);
        $response->assertDontSee($estimation->order_name);
    }

    /**
     * 「全て」選択時はセレクトも「全て」を選択状態で描画する。
     *
     * assertSee は導入文に一致して false-pass しやすいので option の生 HTML で見る。
     */
    public function test_index_status_all_marks_all_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/housing/custom-orders?status=');

        $response->assertOk();
        $response->assertSee('<option value="" selected>', false);
    }

    /** 無指定でも「全て」が選択状態（既定が全てなので表示と一致させる） */
    public function test_index_without_status_marks_all_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $response->assertOk();
        $response->assertSee('<option value="" selected>', false);
    }

    /** ?status=contracted 選択時は「全て」を選択状態にしない */
    public function test_index_status_contracted_does_not_mark_all_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/housing/custom-orders?status=contracted');

        $response->assertOk();
        $response->assertDontSee('<option value="" selected>', false);
        $response->assertSee('<option value="contracted" selected>', false);
    }
}
