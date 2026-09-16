<?php

namespace Tests\Feature\Approval;

use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 決裁の表（設計書 §5.16）。
 *
 * ⚠ 基幹の `departments` / `department_user` とは**別の表**（D1）。CSV で決裁の所属部門を
 *   更新したときに基幹のデータを見られる範囲まで変わるのを防ぐ（要件 12.3）。
 */
class ApprovalTablesTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attributes = []): ApprovalCompany
    {
        return ApprovalCompany::create(array_merge([
            'name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1,
        ], $attributes));
    }

    private function department(ApprovalCompany $company, array $attributes = []): ApprovalDepartment
    {
        return ApprovalDepartment::create(array_merge([
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1,
        ], $attributes));
    }

    public function test_company_name_is_unique(): void
    {
        $this->company();

        $this->expectException(QueryException::class);
        $this->company();
    }

    public function test_department_code_is_unique_across_companies(): void
    {
        $a = $this->company();
        $b = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);

        $this->department($a);

        $this->expectException(QueryException::class);
        $this->department($b, ['name' => '土木部']);
    }

    public function test_department_name_is_unique_within_a_company(): void
    {
        $a = $this->company();
        $b = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);

        $this->department($a);
        // 別の会社なら同じ部門名を使える
        $this->department($b, ['code' => 'DD']);

        $this->expectException(QueryException::class);
        $this->department($a, ['code' => 'RE2']);
    }

    public function test_a_department_belongs_to_a_company(): void
    {
        $company = $this->company();
        $dept    = $this->department($company);

        $this->assertSame($company->id, $dept->company->id);
        $this->assertTrue($company->departments->contains('id', $dept->id));
    }

    public function test_users_belong_to_many_approval_departments(): void
    {
        $company = $this->company();
        $re      = $this->department($company);
        $sales   = $this->department($company, ['name' => '営業部', 'short_name' => '営業', 'code' => 'SA', 'sort_order' => 2]);

        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $user->approvalDepartments()->sync([$re->id, $sales->id]);

        $this->assertEqualsCanonicalizing([$re->id, $sales->id], $user->fresh()->approvalDepartments->pluck('id')->all());
        $this->assertTrue($re->fresh()->users->contains('id', $user->id));
    }

    public function test_mail_domain_is_unique(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->expectException(QueryException::class);
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
    }

    public function test_member_flags_default_to_false(): void
    {
        $user   = User::factory()->create(['must_change_password' => false]);
        $member = ApprovalMember::create(['user_id' => $user->id]);

        $this->assertFalse($member->fresh()->can_view_all);
        $this->assertFalse($member->fresh()->is_admin);
    }

    public function test_user_helpers_read_the_member_row(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->assertFalse($user->isApprovalAdmin());
        $this->assertFalse($user->canViewAllApprovals());

        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true, 'can_view_all' => true]);

        $this->assertTrue($user->fresh()->isApprovalAdmin());
        $this->assertTrue($user->fresh()->canViewAllApprovals());
    }

    public function test_president_is_stored_in_a_single_settings_row(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $settings = ApprovalSetting::current();
        $this->assertSame(1, $settings->id, '設定は常に id=1 の 1 行');
        $this->assertNull($settings->president_user_id);
        $this->assertFalse($user->isApprovalPresident());

        $settings->update(['president_user_id' => $user->id]);

        $this->assertTrue($user->fresh()->isApprovalPresident());
        $this->assertSame($user->id, ApprovalSetting::current()->president->id);
    }

    /**
     * 設定の行は**必ず id=1**（表が空でなくても）。
     *
     * ⚠ これが無いと `firstOrCreate(['id' => 1])` に戻す変異を検出できない（実測で緑のまま通った）。
     *   `RefreshDatabase` は毎回空の表から始まるので、`id` が `$fillable` に無くて作成時に
     *   落ちても、自動採番の 1 件目がたまたま id=1 になり区別が付かない。
     *   先に別の id の行を入れて、自動採番に頼っていたら 1 にならない状況を作る。
     */
    public function test_the_settings_row_is_always_id_one_even_when_the_table_is_not_empty(): void
    {
        DB::table('approval_settings')->insert(['id' => 5, 'president_user_id' => null]);

        $this->assertSame(1, ApprovalSetting::current()->id, '設定の行が id=1 で作られていない（自動採番に頼っている）');
    }

    /**
     * 社長に指定された人を削除しても、社長の欄が読めること（Top trap #18）。
     *
     * ⚠ 利用者は SoftDeletes なので、外部キーの `ON DELETE SET NULL` は**発火しない**
     *   （`deleted_at` を立てる UPDATE だから）。`withTrashed()` が無いと `president` が
     *   null になり、社長名を出す画面が `Attempt to read property "name" on null` で 500 になる。
     */
    public function test_a_deleted_president_is_still_readable(): void
    {
        $user = User::factory()->create(['name' => '社長 三郎', 'must_change_password' => false]);
        ApprovalSetting::current()->update(['president_user_id' => $user->id]);

        $user->delete();
        ApprovalSetting::forget();

        $president = ApprovalSetting::current()->president;

        $this->assertNotNull($president, '削除した社長が読めない（画面が 500 になる）');
        $this->assertSame('社長 三郎', $president->name);
        $this->assertTrue($president->trashed());
    }

    /** 決裁の印の相手を削除しても読めること（同じ理由） */
    public function test_a_deleted_member_is_still_readable(): void
    {
        $user = User::factory()->create(['name' => '管理 花子', 'must_change_password' => false]);
        $member = ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        $user->delete();

        $this->assertSame('管理 花子', $member->fresh()->user?->name, '削除した利用者が読めない');
    }

    /**
     * 設定はリクエストの間に 1 回しか引かないこと。
     *
     * ⚠ `User::isApprovalPresident()` がこれを呼ぶので、覚えておかないと一覧で
     *   1 人につき 1 回ずつクエリが増える（20 人で 41 クエリになることを実測）。
     */
    public function test_the_settings_are_read_once_per_request(): void
    {
        ApprovalSetting::current();   // 1 回目で読み込ませる

        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });

        $users = User::factory()->count(20)->create(['must_change_password' => false]);
        $before = $queries;
        foreach ($users as $user) {
            $user->isApprovalPresident();
        }

        $this->assertSame(0, $queries - $before, '社長の判定のたびに設定を引き直している（一覧で N+1 になる）');
    }

    /** 許可するドメインの判定は `@` がちょうど 1 つのときだけ通す */
    #[DataProvider('mailDomainCases')]
    public function test_allows_only_accepts_a_single_at_sign(?string $email, bool $expected): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->assertSame($expected, ApprovalMailDomain::allows($email), "判定が違う: " . var_export($email, true));
    }

    public static function mailDomainCases(): array
    {
        return [
            '正しいアドレス'     => ['taro@mitsuwat.co.jp', true],
            '大文字'             => ['Taro@MITSUWAT.CO.JP', true],
            '別のドメイン'       => ['taro@example.com', false],
            'サブドメインは別物' => ['taro@mail.mitsuwat.co.jp', false],
            // ⚠ `strrpos` で最後の `@` を取ると、これが通ってしまう（実測）
            '＠ が 2 つ'         => ['foo@bar@mitsuwat.co.jp', false],
            '＠ が無い'          => ['mitsuwat.co.jp', false],
            '＠ で終わる'        => ['taro@', false],
            '空文字'             => ['', false],
            'null'               => [null, false],
        ];
    }

    /** 決裁の権限を 1 つでも持っているか（D16 の判定） */
    public function test_has_approval_privileges_covers_all_three(): void
    {
        $plain = User::factory()->create(['must_change_password' => false]);
        $this->assertFalse($plain->hasApprovalPrivileges());

        $admin = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $admin->id, 'is_admin' => true]);
        $this->assertTrue($admin->fresh()->hasApprovalPrivileges());

        $viewer = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $viewer->id, 'can_view_all' => true]);
        $this->assertTrue($viewer->fresh()->hasApprovalPrivileges());

        $president = User::factory()->create(['must_change_password' => false]);
        ApprovalSetting::current()->update(['president_user_id' => $president->id]);
        $this->assertTrue($president->fresh()->hasApprovalPrivileges());
    }

    /** 決裁の所属部門は基幹の部門と混ざらない（D1） */
    public function test_approval_departments_are_separate_from_base_departments(): void
    {
        $company = $this->company();
        $dept    = $this->department($company);

        $user = User::factory()->create(['must_change_password' => false]);
        $user->approvalDepartments()->sync([$dept->id]);

        $this->assertCount(0, $user->fresh()->departments, '基幹の所属部門まで変わっている');
    }
}
