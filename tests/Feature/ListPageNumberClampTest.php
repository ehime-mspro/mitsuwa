<?php

namespace Tests\Feature;

use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\AreaBuilding;
use App\Models\Property;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 並べ替え済みのコレクションを手でページングする一覧で、`?page=` が範囲の外のとき。
 *
 * ⚠ `LengthAwarePaginator::resolveCurrentPage()` は 1 以上の整数なら何でも通す。そのまま `forPage()` に渡すと、
 *   手で打った大きな番号（9223372036854775807）で `($page - 1) * 件数` が float になり、`array_slice()` が TypeError で 500。
 *   行の無いページは、件数は出るのに表が空になる。→ 最後のページまでに抑える（決裁台帳と同じ。2026-10-06）。
 * ⚠ 同じ種類の 500 が残っている所（未対応・別の課題。2026-10-07 の最後の点検が確かめた。docs/RULES.md の Bug #100）:
 *   Housing の `HsContractListController`（`?page=abc`・大きな番号）・`HousingDashboardController`（大きな番号）と、
 *   決裁の ⑥ `NoticeController`・⑩ `AdminRequestController`（SQL の `paginate()` でも `PageNumbers::around()` が TypeError）。
 */
class ListPageNumberClampTest extends TestCase
{
    use CreatesRealEstateSchema;
    use RefreshDatabase;

    /** 範囲の外のページ番号（2 ページ目が無い・大きな番号・PHP の整数の最大） */
    private const PAGES = ['2', '99999', '9223372036854775807'];

    /** 経営層（部門の門番を素通り）。must_change_password の既定 true は password.change へ送るので false */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function assertLastPage(string $url, string $viewKey, string $page): void
    {
        $response = $this->actingAs($this->executive())->get($url . '?page=' . $page);

        $response->assertOk();
        /** @var LengthAwarePaginator $paginator */
        $paginator = $response->viewData($viewKey);
        $this->assertSame([1, 1], [$paginator->currentPage(), $paginator->count()], "page={$page} でも最後のページ（1 ページ目）の行を出す");
    }

    public function test_the_tenant_property_list_shows_the_last_page(): void
    {
        Property::create([
            'code' => 'T-P001', 'name' => 'ページのビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'operation_status' => 'active', 'address' => '愛媛県松山市本町1-1',
        ]);

        foreach (self::PAGES as $page) {
            $this->assertLastPage('/tenant/properties', 'properties', $page);
        }
    }

    public function test_the_real_estate_procurement_list_shows_the_last_page(): void
    {
        $this->createRealEstateSchema();
        ReProcurement::create([
            'procurement_code' => 'P-001', 'property_type' => RealEstatePropertyType::UsedHouse->value,
            'transaction_type' => RealEstateTransactionType::Purchase->value, 'status' => 'selling',
            'property_name' => '物件P-001', 'address' => '愛媛県松山市1-1-1', 'info_obtained_date' => '2026-06-01', 'created_by' => 1,
        ]);

        foreach (self::PAGES as $page) {
            $this->assertLastPage('/realestate/procurements', 'rows', $page);
        }
    }

    public function test_the_area_building_list_shows_the_last_page(): void
    {
        AreaBuilding::create(['name' => 'ページの周辺ビル']);

        foreach (self::PAGES as $page) {
            $this->assertLastPage('/tenant/area-buildings', 'rows', $page);
        }
    }
}
