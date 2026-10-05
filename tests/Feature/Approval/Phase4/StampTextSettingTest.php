<?php

namespace Tests\Feature\Approval\Phase4;

use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 利用者の管理（⑦）の「印に使う文字」（要件 3.2・9.1・段階4 設計書 §5.5・D9〜D11・D13） */
class StampTextSettingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));   // 日本時間 10/5 10:00
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saveStamp(User $admin, User $target, ?string $stamp): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->put(route('approvals.admin.users.update', $target), [
            'name'                 => $target->name,
            'employee_number'      => $target->employee_number,
            'approval_departments' => $target->approvalDepartments->pluck('id')->all(),
            'stamp_text'           => $stamp,
        ]);
    }

    /** @return list<array{mixed, mixed}> */
    private function stampLogs(User $target): array
    {
        return ApprovalSettingLog::where('action', 'user.stamp_changed')->where('target_id', $target->id)->orderBy('id')->get()
            ->map(fn (ApprovalSettingLog $log) => [$log->old_values['stamp_text'], $log->new_values['stamp_text']])->all();
    }

    public function test_the_admin_sets_the_stamp_text_before_launch(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);

        $this->saveStamp($admin, $member, '山田')->assertRedirect(route('approvals.admin.users.index'));

        $this->assertSame('山田', $member->fresh()->approvalMember->stamp_text);
        $this->assertSame([[null, '山田']], $this->stampLogs($member), '前と後を設定の記録に残す');
    }

    /** 氏名を直せない人（基幹を使う人・社長に指定された人）でも、印の文字は直せる（D10） */
    public function test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited(): void
    {
        $admin     = $this->approvalAdmin();
        $base      = $this->baseUser(['name' => '基幹 太郎']);
        $president = $this->makePresident();

        $this->saveStamp($admin, $base, '基幹')->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('approvals.admin.users.update', $president), ['stamp_text' => '佐藤'])->assertSessionHasNoErrors();

        $this->assertSame('基幹', $base->fresh()->approvalMember->stamp_text);
        $this->assertSame('佐藤', $president->fresh()->approvalMember->stamp_text);
        $this->assertSame('社長 太郎', $president->fresh()->name, '氏名は変わらない');
    }

    public function test_clearing_the_text_goes_back_to_the_name(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田 太郎', 'employee_number' => 'E001']);
        ApprovalMember::create(['user_id' => $member->id, 'stamp_text' => '山']);

        $this->saveStamp($admin, $member->fresh(), '')->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->approvalMember->stamp_text);
        $this->assertSame([['山', null]], $this->stampLogs($member));
    }

    public function test_spaces_around_the_text_are_removed_including_full_width_ones(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);

        $this->saveStamp($admin, $member, '　山田　')->assertSessionHasNoErrors();

        $this->assertSame('山田', $member->fresh()->approvalMember->stamp_text);
    }

    public function test_more_than_four_characters_are_refused(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);

        $this->saveStamp($admin, $member, '勅使河原三')->assertSessionHasErrors(['stamp_text' => '印に使う文字は4文字以下で入力してください。']);

        $this->assertNull($member->fresh()->approvalMember);
        $this->assertSame([], $this->stampLogs($member));
    }

    public function test_saving_the_same_text_again_records_nothing(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);
        ApprovalMember::create(['user_id' => $member->id, 'stamp_text' => '山田']);

        $this->saveStamp($admin, $member->fresh(), '山田')->assertSessionHasNoErrors();

        $this->assertSame([], $this->stampLogs($member));
    }

    public function test_an_update_without_the_field_keeps_the_stamp_text(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);
        ApprovalMember::create(['user_id' => $member->id, 'stamp_text' => '山田']);

        $this->actingAs($admin)->put(route('approvals.admin.users.update', $member), [
            'name' => '山田太郎', 'employee_number' => 'E001', 'approval_departments' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('山田', $member->fresh()->approvalMember->stamp_text);
        $this->assertSame([], $this->stampLogs($member));
    }

    public function test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        ApprovalMember::create(['user_id' => $w['applicant']->id, 'stamp_text' => '花子']);

        $html = $this->actingAs($admin)->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        // 上段は最初の所属部門の略称・日付は今日（日本時間）・下段は印に使う文字
        $this->assertStringContainsString('aria-label="住宅 R8.10.5 花子 の印"', $html);
        $this->assertStringContainsString('aria-label="R8.10.5 部門 の印"', $html, '所属の無い人は上段が空・下段は氏名の空白より前');
        $this->assertStringContainsString('name="stamp_text"', $html);
        $this->assertStringContainsString('決裁の所属部門と印に使う文字だけ変えられます。', $html);
        $this->assertStringContainsString('決裁の所属部門と印に使う文字は全員について変えられます。', $html, '画面の説明');

        // 編集の小窓へ渡る値にも今の印に使う文字が入っている（空で開くと、そのまま保存して印に使う文字が消える）
        $rows = $this->editRows($html);
        $this->assertSame('花子', $rows[$w['applicant']->id]['stamp'], '編集の小窓は今の印に使う文字を入れて開く');
        $this->assertSame('', $rows[$w['head']->id]['stamp'], '印に使う文字を決めていない人は空');
    }

    /** 狭い画面で氏名の列が 1 文字幅に削られる（印の見本・氏名・「〜に指定されています」が縦に 1 文字ずつ並ぶ）のを、列の幅の下限で防ぐ（画面の幅は測れないので、下限の書き方を見る） */
    public function test_the_table_keeps_a_minimum_width_for_the_name_and_department_columns(): void
    {
        $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $html = $this->actingAs($admin)->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-stamp-preview', $html);
        $this->assertSame(substr_count($html, 'data-stamp-preview'), substr_count($html, '<div class="min-w-[6rem]">'), '氏名のセルは 1 行ごとに幅の下限（min-w-0 だと狭い画面で 1 文字幅になる）');
        $this->assertSame(1, preg_match('#<th class="[^"]*\bmin-w-\[5\.125rem\]">決裁の所属部門</th>#', $html), '見出しの「決裁の所属部門」も縦に並ばない幅の下限');
    }

    /** 一覧の「編集」が小窓へ渡す値（openEdit の引数）を、利用者の id ごとに取り出す */
    private function editRows(string $html): array
    {
        preg_match_all("/openEdit\\(JSON\\.parse\\('(.*?)'\\)\\)/s", $html, $matches);

        $rows = [];
        foreach ($matches[1] as $literal) {
            // Js::from() は JSON を JS の文字列にして渡す（引用符は \u0022・バックスラッシュは二重）
            $row = json_decode(str_replace(['\\u0022', '\\\\'], ['"', '\\'], $literal), true, 512, JSON_THROW_ON_ERROR);
            $rows[$row['id']] = $row;
        }

        return $rows;
    }
}
