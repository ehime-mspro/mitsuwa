<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * テナント・賃貸マンションの取込の画面は、開くタブを URL（`?tab=` / `?selected_tab=`）で選べる。
 * その値を書き換えても画面が壊れない（Bug #97 の形）。
 *
 * ⚠ 直す前は値を確かめずに JS の文字列に直に書いていて、末尾に `\` を付けると 200 なのに画面の JS が止まり
 *   （どのタブも開けない）、`?tab[]=1` で 500 だった（2026-10-26 に実測）。画面にあるタブのほかは、最初のタブ（物件）を開く。
 * ⚠ 断られた取込が戻るタブ（`redirect()->route('admin.tenant-import', ['tab' => …])`）は今までどおり開く。
 */
class ImportTabQueryTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
    }

    /** $url を開いて 200 であること・画面の JS を組み立てて開くタブを返す */
    private function openedTab(string $url, string $function): string
    {
        $response = $this->actingAs($this->user)->get($url);
        $this->assertSame(200, $response->getStatusCode(), "{$url} が 200 でない");
        $html = $response->getContent();

        return $this->driveAlpine($html, $function, $this->xData($html, $function), '', [], true, ['activeTab'])['evaluated'][0];
    }

    public function test_the_tenant_import_screen_opens_a_tab_from_the_url(): void
    {
        foreach ([
            [[], 'property'],
            [['tab' => 'past-contract'], 'past-contract'],
            [['tab' => 'unit'], 'unit'],
            [['tab' => 'x\\'], 'property'],
            [['tab' => 'room_contract'], 'property'],
            [['tab' => ['unit']], 'property'],
        ] as [$query, $expected]) {
            $url = route('admin.tenant-import') . ($query === [] ? '' : '?' . http_build_query($query));
            $this->assertSame($expected, $this->openedTab($url, 'tenantImportTabs'), "{$url} で開くタブが違う");
        }
    }

    public function test_the_mansion_import_screen_opens_a_tab_from_the_url(): void
    {
        foreach ([
            [[], 'property'],
            [['selected_tab' => 'room_contract'], 'room_contract'],
            [['selected_tab' => 'parking_contract'], 'parking_contract'],
            [['selected_tab' => 'x\\'], 'property'],
            [['selected_tab' => 'past-contract'], 'property'],
            [['selected_tab' => ['room']], 'property'],
        ] as [$query, $expected]) {
            $url = route('admin.mansion-import') . ($query === [] ? '' : '?' . http_build_query($query));
            $this->assertSame($expected, $this->openedTab($url, 'mansionImportTabs'), "{$url} で開くタブが違う");
        }
    }
}
