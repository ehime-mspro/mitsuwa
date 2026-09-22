<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 添付の登録・削除の日時を日本時間で出す（Bug #61）。
 * 共通部品 components/attachment-section は 7 画面が @include している（一覧は @json で JS へ渡す）。
 * 準備は AttachmentDeliveryTest と同じ（attachable_type に Contract::class。親の行は作らない）。
 */
class AttachmentTimestampDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DepartmentSeeder::class);

        $this->staff = User::factory()->create(['role' => UserRole::Staff->value]);
        $this->staff->departments()->attach(Department::where('code', 'tenant')->value('id'));
        $this->actingAs($this->staff);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAttachment(string $fileName): Attachment
    {
        $path = 'attachments/contracts/1/' . $fileName;
        Storage::disk('public')->put($path, 'FAKEBYTES');

        return Attachment::create([
            'attachable_type' => Contract::class,
            'attachable_id'   => 1,
            'file_name'       => $fileName,
            'file_path'       => $path,
            'file_size'       => 9,
            'mime_type'       => 'application/pdf',
            'uploaded_by'     => $this->staff->id,
        ]);
    }

    public function test_the_section_shows_upload_and_deletion_times_in_japan_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC')); // 日本時間 9/19 2:12
        $live = $this->makeAttachment('live.pdf');
        $gone = $this->makeAttachment('gone.pdf');

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59
        $gone->delete();

        $html = view('components.attachment-section', [
            'attachableType'     => 'contracts',
            'attachableId'       => 1,
            'attachments'        => collect([$live->fresh()]),
            'deletedAttachments' => Attachment::onlyTrashed()->get(),
        ])->render();

        // @json は "/" を "\/" に書く
        $this->assertStringContainsString('"uploaded_at":"2026\/09\/19 02:12"', $html, '登録の日時が日本時間になっていない');
        $this->assertStringContainsString('"deleted_at":"2026\/09\/19 08:59"', $html, '削除の日時が日本時間になっていない');
        $this->assertStringNotContainsString('2026\/09\/18', $html, 'UTC の日付が残っている');
    }

    public function test_the_delete_response_carries_the_japan_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC'));
        $attachment = $this->makeAttachment('x.pdf');

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59

        $this->deleteJson(route('attachments.destroy', $attachment))
            ->assertOk()
            ->assertJsonPath('deleted.deleted_at', '2026/09/19 08:59');
    }
}
