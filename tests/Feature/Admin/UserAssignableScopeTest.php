<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAssignableScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignable_excludes_inactive_and_deleted_users(): void
    {
        $active   = User::factory()->create(['status' => UserStatus::Active->value]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive->value]);
        $deleted  = User::factory()->create(['status' => UserStatus::Active->value]);
        $deleted->delete();

        $ids = User::assignable()->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($deleted->id));
    }

    public function test_assignable_with_null_returns_only_assignable(): void
    {
        $active   = User::factory()->create(['status' => UserStatus::Active->value]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive->value]);

        $ids = User::assignableWith(null)->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_assignable_with_includes_current_inactive_user(): void
    {
        $active   = User::factory()->create(['status' => UserStatus::Active->value]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive->value]);

        $ids = User::assignableWith($inactive->id)->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertTrue($ids->contains($inactive->id));
    }

    public function test_assignable_with_includes_current_deleted_user(): void
    {
        $deleted = User::factory()->create(['status' => UserStatus::Active->value]);
        $deleted->delete();

        $ids = User::assignableWith($deleted->id)->pluck('id');

        $this->assertTrue($ids->contains($deleted->id));
    }
}
