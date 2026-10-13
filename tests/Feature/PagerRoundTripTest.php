<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDadSchema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\ReadsPager;
use Tests\TestCase;

/**
 * 一覧の画面からページ送りをたどる（Bug #117・#47・#31）。外側の箱の違う代表 4 本:
 * テナントの顧客（px-4 py-3 border-t）・DAD の発注者（mt-4）・不動産の仕入れ先（px-4 py-3 border-t。前は番号に transition-colors が無かった）・
 * 基幹の利用者管理（py-3 border-t）。
 *
 * 221 件＝20 件ずつで 12 ページ。6 ページ目を開くと `< 1 … 5 6 7 … 12 >`。描いた「>」をそのまま GET して 7 ページ目に着く
 * （?page= を自分で組み立てると、リンクが壊れていても緑になる。Bug #31）。?page= に整数の最大を入れても落ちない（Bug #100）。
 * ⚠ 部品そのものの並び・端・行の無いページは PagerComponentTest。全ビューの書き方は PagerUsageScanTest。
 */
class PagerRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use ReadsPager;
    use CreatesDadSchema;
    use CreatesRealEstateSchema;

    private const ROWS = 221;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(callable $row): array
    {
        $now = now();

        return array_map(fn (int $i) => $row(sprintf('%03d', $i)) + ['created_at' => $now, 'updated_at' => $now], range(1, self::ROWS));
    }

    /** $class は外側の箱（置き換える前の箱と同じ余白・線になること） */
    private function assertRoundTrip(string $route, string $class): void
    {
        $page6 = $this->actingAs($this->user)->get(route($route, ['page' => 6]));
        $page6->assertOk();
        $pager = $this->pager($page6->getContent());
        $this->assertSame(['<', '1', '…', '5', '6', '7', '…', '12', '>'], $pager['items'], "{$route} の 6 ページ目");
        $this->assertSame(['6'], $pager['current'], "{$route} の 6 ページ目");
        $this->assertSame("flex justify-center gap-0.5 {$class}", $pager['class'], "{$route} の外側の箱");

        $page7 = $this->actingAs($this->user)->get($pager['links']['>']);
        $page7->assertOk();
        $after = $this->pager($page7->getContent());
        $this->assertSame(['7'], $after['current'], "{$route}: 描いた「>」をたどると 7 ページ目");
        $this->assertSame(['<', '1', '…', '6', '7', '8', '…', '12', '>'], $after['items'], "{$route} の 7 ページ目");

        $max = $this->actingAs($this->user)->get(route($route, ['page' => PHP_INT_MAX]));
        $max->assertOk();
        $this->assertSame(['<', '1', '…', '11', '12', '>'], $this->pager($max->getContent())['items'], "{$route}: 整数の最大のページ");

        // 数字でないページ番号は 1 ページ目（Laravel が 1 に読み替える）
        $text = $this->actingAs($this->user)->get(route($route, ['page' => 'abc']));
        $text->assertOk();
        $this->assertSame(['1'], $this->pager($text->getContent())['current'], "{$route}: ?page=abc");
    }

    public function test_tenant_customers(): void
    {
        $this->insert('customers', $this->rows(fn (string $n) => ['code' => "CU-{$n}", 'name' => "顧客{$n}", 'customer_type' => 'corporation']));

        $this->assertRoundTrip('tenant.customers.index', 'px-4 py-3 border-t border-gray-200');
    }

    public function test_dad_clients(): void
    {
        $this->createDadSchema();
        $this->insert('dad_clients', $this->rows(fn (string $n) => ['client_type' => 'municipality', 'name' => "発注者{$n}", 'created_by' => $this->user->id]));

        $this->assertRoundTrip('dad.clients.index', 'mt-4');
    }

    public function test_realestate_suppliers(): void
    {
        $this->createRealEstateSchema();
        $this->insert('re_suppliers', $this->rows(fn (string $n) => ['supplier_code' => "SUP-{$n}", 'type' => 'corporation', 'name' => "仕入れ先{$n}"]));

        $this->assertRoundTrip('realestate.suppliers.index', 'px-4 py-3 border-t border-gray-200');
    }

    public function test_admin_users(): void
    {
        User::factory()->count(self::ROWS - 1)->create(['must_change_password' => false]);

        $this->assertRoundTrip('admin.users.index', 'py-3 border-t border-gray-200');
    }
}
