<?php

namespace Tests\Feature\Approval;

use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\SettingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 設定の変更の記録（設計書 §5.14・要件 14.2）。
 *
 * ⚠ 追記のみ。あとから書き換えられないことをモデルで強制する。
 * ⚠ パスワードそのものは、どこにも残さない。
 */
class SettingLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_who_what_and_the_change(): void
    {
        $actor  = User::factory()->create(['name' => '管理 花子', 'must_change_password' => false]);
        $target = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($actor)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_USER_AGENT' => 'TestAgent/1.0'])
            ->get('/dashboard/tenant');

        SettingLogger::record('user.updated', 'user', $target->id, ['name' => '旧'], ['name' => '新']);

        $log = ApprovalSettingLog::sole();

        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame('user.updated', $log->action);
        $this->assertSame('user', $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame(['name' => '旧'], $log->old_values);
        $this->assertSame(['name' => '新'], $log->new_values);
        $this->assertSame('198.51.100.9', $log->ip_address);
        $this->assertSame('TestAgent/1.0', $log->user_agent);
        $this->assertNotNull($log->created_at);
    }

    /** 変わった項目だけを残す（全項目を積むと差分が読めない） */
    public function test_it_keeps_only_the_changed_keys(): void
    {
        $actor = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($actor);

        SettingLogger::recordChange('user.updated', 'user', 1, ['name' => 'A', 'email' => 'x@example.com'], ['name' => 'B', 'email' => 'x@example.com']);

        $log = ApprovalSettingLog::sole();

        $this->assertSame(['name' => 'A'], $log->old_values);
        $this->assertSame(['name' => 'B'], $log->new_values);
    }

    /** 何も変わっていなければ 1 行も足さない */
    public function test_it_records_nothing_when_there_is_no_change(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]));

        SettingLogger::recordChange('user.updated', 'user', 1, ['name' => 'A'], ['name' => 'A']);

        $this->assertSame(0, ApprovalSettingLog::count());
    }

    /** あとから書き換えられない（設計書 §5.14） */
    public function test_a_log_cannot_be_updated(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]));
        SettingLogger::record('user.updated', 'user', 1, [], []);

        $log = ApprovalSettingLog::sole();

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_a_log_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]));
        SettingLogger::record('user.updated', 'user', 1, [], []);

        $this->expectException(\RuntimeException::class);
        ApprovalSettingLog::sole()->delete();
    }

    /** 更新日時の列を持たない（持つと「書き換えられる想定」に見える） */
    public function test_the_table_has_no_updated_at(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('approval_setting_logs', 'updated_at'),
            'approval_setting_logs に updated_at がある（追記のみの表なので持たない）'
        );
    }
}
