<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 決裁のみ利用者（D6）と、社員番号・メールアドレスの正規化（設計書 §5.6）。
 *
 * ⚠ `must_change_password` はファクトリーが設定しないので、メモリ上のインスタンスでは null。
 *   経路によって結果が変わるため必ず明示する（`AuthenticationTest` の docblock と同じ理由）。
 */
class UserIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_only_is_the_fourth_role(): void
    {
        $this->assertSame('approval_only', UserRole::ApprovalOnly->value);
        $this->assertSame('決裁のみ', UserRole::ApprovalOnly->label());
        $this->assertFalse(UserRole::ApprovalOnly->isExecutive());
        $this->assertFalse(UserRole::ApprovalOnly->isManagerOrAbove());
        $this->assertTrue(UserRole::ApprovalOnly->isApprovalOnly());

        // 既存 3 種は決裁のみでない
        foreach ([UserRole::Executive, UserRole::Manager, UserRole::Staff] as $role) {
            $this->assertFalse($role->isApprovalOnly());
        }
    }

    public function test_employee_number_is_normalized_on_save(): void
    {
        $user = User::factory()->create(['employee_number' => '　ｍ－００１　', 'must_change_password' => false]);

        $this->assertSame('M-001', $user->fresh()->employee_number);
    }

    public function test_email_is_normalized_on_save(): void
    {
        $user = User::factory()->create(['email' => '  User@Example.COM ', 'must_change_password' => false]);

        $this->assertSame('user@example.com', $user->fresh()->email);
    }

    /** 空文字は NULL にする（一意索引に空文字が並ぶのを防ぐ） */
    public function test_blank_identifiers_become_null(): void
    {
        $user = User::factory()->create(['employee_number' => '   ', 'email' => 'a@example.com', 'must_change_password' => false]);
        $this->assertNull($user->fresh()->employee_number);

        $user2 = User::factory()->create(['employee_number' => 'M002', 'email' => '  ', 'must_change_password' => false]);
        $this->assertNull($user2->fresh()->email);
    }

    public function test_home_route_name_depends_on_the_role(): void
    {
        $cases = [
            UserRole::ApprovalOnly->value => 'approvals.home',
            UserRole::Executive->value    => 'dashboard.executive',
            UserRole::Manager->value      => 'dashboard.tenant',
            UserRole::Staff->value        => 'dashboard.tenant',
        ];

        foreach ($cases as $role => $expected) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);
            $this->assertSame($expected, $user->homeRouteName(), "{$role} の行き先が違う");
        }
    }

    /**
     * 決裁のみ利用者は基幹の担当者の選択肢に出ない（設計書 §5.6）。
     *
     * ⚠ これが効かないと、100 人以上の決裁のみ利用者が 19 か所の担当者セレクトに並ぶ。
     */
    public function test_assignable_excludes_approval_only_users(): void
    {
        $staff    = User::factory()->create(['role' => UserRole::Staff->value, 'status' => UserStatus::Active->value]);
        $approval = User::factory()->create(['role' => UserRole::ApprovalOnly->value, 'status' => UserStatus::Active->value]);

        $ids = User::assignable()->pluck('id');

        $this->assertTrue($ids->contains($staff->id));
        $this->assertFalse($ids->contains($approval->id), '決裁のみ利用者が担当者の選択肢に出ている');
    }

    /** `baseUsers()` は状態を見ない（無効な基幹利用者も入る）が、決裁のみは外す */
    public function test_base_users_excludes_only_approval_only(): void
    {
        $inactive = User::factory()->create(['role' => UserRole::Staff->value, 'status' => UserStatus::Inactive->value]);
        $approval = User::factory()->create(['role' => UserRole::ApprovalOnly->value, 'status' => UserStatus::Active->value]);

        $ids = User::baseUsers()->pluck('id');

        $this->assertTrue($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($approval->id));
    }

    /**
     * 氏名で担当者を引く箇所は、すべて `baseUsers()` を通ること（設計書 §5.6）。
     *
     * ⚠ **全件分類**（Top trap #13 / Bug #45 ①）。「直した 2 ファイルを並べる」形だと、
     *   新しいコントローラに素の `User::where('name', …)` を書いても検査対象に入らず永遠に緑。
     *   `app/Http/Controllers` 全体を走査し、`User::` から次の `;` までの文が氏名で引いていたら
     *   `baseUsers()` を通っていることを要求する。
     * ⚠ **コメントを落としてから走査する**（注意書きの中の `User::where('name'` に反応しないように。Bug #42 ②）。
     */
    public function test_every_name_lookup_on_users_is_scoped_to_base_users(): void
    {
        $offenders = [];
        $found = 0;

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $this->sourceWithoutComments($file->getPathname());

            preg_match_all('/\bUser::.*?;/s', $source, $matches);

            foreach ($matches[0] as $statement) {
                if (! preg_match("/->where\(\s*'name'|User::where\(\s*'name'/", $statement)) {
                    continue;
                }

                $found++;

                if (! str_contains($statement, 'baseUsers()')) {
                    $offenders[] = $file->getRelativePathname() . ': ' . trim(preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（2026-09-16 時点で 3 箇所）
        $this->assertGreaterThanOrEqual(3, $found, '担当者の氏名照合の走査に失敗している');

        $this->assertSame(
            [],
            $offenders,
            "氏名で担当者を引くのに baseUsers() を通っていない箇所があります（決裁のみ利用者を拾ってしまいます）:\n" . implode("\n", $offenders)
        );
    }

    /** コメントと docblock を落としたソース（注意書きに反応しないように。Bug #42 ②） */
    private function sourceWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
