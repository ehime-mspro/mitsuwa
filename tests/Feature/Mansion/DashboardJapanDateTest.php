<?php

namespace Tests\Feature\Mansion;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesMansionSchema;
use Tests\TestCase;

/** 賃貸マンションのダッシュボードの「〇年〇月〇日 時点」を日本の日付で出す（Bug #61） */
class DashboardJapanDateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
        $this->seed(DepartmentSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_snapshot_date_is_the_japanese_date(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'mansion')->value('id'));

        Carbon::setTestNow(Carbon::parse('2026-09-18 16:00:00', 'UTC')); // 日本時間 9/19 1:00

        $this->actingAs($user)->get(route('mansion.dashboard'))
            ->assertOk()
            ->assertSee('2026年9月19日 時点のスナップショット')
            ->assertDontSee('2026年9月18日 時点のスナップショット');
    }
}
