<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 利用者の管理（設計書 §5.9・D8・D16）。
 *
 * ⚠ **D16**: 社長・全件閲覧者・決裁の管理者に**指定されている人**への
 *   再発行・無効化と有効化・氏名と社員番号の修正は、決裁の管理者にはできない
 *   （指定された人のパスワードを再発行して本人になりすますのを防ぐ）。
 *   決裁の所属部門の編集だけは全員について行える。
 */
class ApprovalUserManagementTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private ApprovalDepartment $dept;
    private ApprovalDepartment $other;

    protected function setUp(): void
    {
        parent::setUp();

        $company     = ApprovalCompany::create(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1]);
        $this->dept  = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
        $this->other = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '営業部', 'short_name' => '営業', 'code' => 'SA', 'sort_order' => 2]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['name' => '決裁 管理者', 'must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->approvalOnly()->create(array_merge(['must_change_password' => false], $attributes));
    }

    // --- 入れる人 ---

    public function test_an_executive_without_the_flag_is_rejected(): void
    {
        $executive = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);

        $this->actingAs($executive)->get(route('approvals.admin.users.index'))->assertStatus(403);
    }

    // --- 一覧 ---

    public function test_the_list_shows_base_users_and_approval_only_users(): void
    {
        $base     = User::factory()->create(['name' => '基幹 太郎', 'must_change_password' => false]);
        $approval = $this->member(['name' => '決裁 次郎']);

        $ids = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertTrue($ids->contains($base->id));
        $this->assertTrue($ids->contains($approval->id));
    }

    public function test_it_can_filter_people_without_a_department(): void
    {
        $with    = $this->member(['name' => '所属 あり']);
        $without = $this->member(['name' => '所属 なし']);
        $with->approvalDepartments()->sync([$this->dept->id]);

        $ids = $this->actingAs($this->admin())->get(route('approvals.admin.users.index', ['department' => 'none']))
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertFalse($ids->contains($with->id));
        $this->assertTrue($ids->contains($without->id));
    }

    public function test_it_can_filter_people_who_never_logged_in(): void
    {
        $never = $this->member();
        $once  = $this->member();
        $once->forceFill(['last_login_at' => now()])->save();

        $ids = $this->actingAs($this->admin())->get(route('approvals.admin.users.index', ['never_logged_in' => '1']))
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertTrue($ids->contains($never->id));
        $this->assertFalse($ids->contains($once->id));
    }

    // --- 編集 ---

    public function test_the_departments_of_anyone_can_be_edited(): void
    {
        $base = User::factory()->create(['name' => '基幹 太郎', 'must_change_password' => false]);

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $base), [
            'approval_departments' => [$this->dept->id, $this->other->id],
        ])->assertRedirect(route('approvals.admin.users.index'));

        $this->assertEqualsCanonicalizing(
            [$this->dept->id, $this->other->id],
            $base->fresh()->approvalDepartments->pluck('id')->all()
        );
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.departments_changed')->count());
    }

    public function test_the_name_and_number_of_an_approval_only_user_can_be_edited(): void
    {
        $member = $this->member(['name' => '旧 名前', 'employee_number' => 'A0001']);

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $member), [
            'name' => '新 名前', 'employee_number' => 'a0002', 'approval_departments' => [$this->dept->id],
        ])->assertRedirect(route('approvals.admin.users.index'));

        $member->refresh();
        $this->assertSame('新 名前', $member->name);
        $this->assertSame('A0002', $member->employee_number, '社員番号が正規化されていない');
    }

    /** 基幹を使う人の氏名・社員番号は変えられない（D8） */
    public function test_a_base_user_keeps_their_name_and_number(): void
    {
        $base = User::factory()->create(['name' => '基幹 太郎', 'employee_number' => 'M001', 'must_change_password' => false]);

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $base), [
            'name' => '書き換え', 'employee_number' => 'X999', 'approval_departments' => [$this->dept->id],
        ])->assertRedirect(route('approvals.admin.users.index'));

        $base->refresh();
        $this->assertSame('基幹 太郎', $base->name, '基幹を使う人の氏名が変わっている');
        $this->assertSame('M001', $base->employee_number, '基幹を使う人の社員番号が変わっている');
        // 所属部門だけは変わる
        $this->assertCount(1, $base->approvalDepartments);
    }

    /** メールアドレスは決裁の管理者からは変えられない（D8） */
    public function test_the_email_can_never_be_changed_here(): void
    {
        $member = $this->member(['email' => 'a@example.com', 'employee_number' => 'A0001']);

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $member), [
            'name' => $member->name, 'employee_number' => 'A0001', 'email' => 'evil@example.com',
            'approval_departments' => [$this->dept->id],
        ]);

        $this->assertSame('a@example.com', $member->fresh()->email);
    }

    /**
     * 編集モーダルの送信先は Alpine が組み立てるので、PHP からは**形しか見られない**
     * （実際に送られるかはブラウザでしか測れない）。ルートの土台がずれたら落ちるように
     * 組み立ての式そのものを固定する（Bug #28 / #47 と同じ「呼び出し側と受け側の対」）。
     */
    public function test_the_edit_form_points_at_the_update_route(): void
    {
        $this->member(['name' => '決裁 次郎']);

        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        $base = Str::beforeLast(route('approvals.admin.users.update', 1), '/1');

        $this->assertStringContainsString(':action="\'' . $base . '/\' + editUserId"', $html);
        // @method('PUT') が消えると本番では 405 で無反応になる
        $this->assertSame(1, substr_count($html, 'name="_method" value="PUT"'));
    }

    /**
     * ⚠ **`<form>` を入れ子にしない。** まとめて再発行のフォームで表を囲むと、行ごとの
     *   再発行・無効化のフォームが入れ子になる。HTML のパーサは内側の `<form>` を捨て、
     *   最初の `</form>` で外側を閉じるので、行のボタンが「まとめて再発行」を送ったり、
     *   以降の行がどのフォームにも属さなくなる。
     *
     * ⚠ **`ParsesForms` は文字列を見るので入れ子でも緑になる**（生 HTML では正しく見える）。
     *   開始・終了タグの釣り合いで測るのが唯一の手。
     */
    public function test_the_page_never_nests_forms(): void
    {
        $this->member(['name' => '決裁 次郎']);

        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        preg_match_all('/<form\b|<\/form>/i', $html, $tags, PREG_OFFSET_CAPTURE);

        $depth = 0;
        $max   = 0;
        foreach ($tags[0] as $tag) {
            $depth += str_starts_with(strtolower($tag[0]), '</') ? -1 : 1;
            $max    = max($max, $depth);
            $this->assertGreaterThanOrEqual(0, $depth, '閉じていない </form> がある（位置 ' . $tag[1] . '）');
        }

        $this->assertSame(0, $depth, '<form> と </form> の数が合わない');
        $this->assertSame(1, $max, '<form> が入れ子になっている（ブラウザは内側を捨てる）');
        // 走査が空振りして緑になる事故を防ぐ（一覧・絞り込み・行の操作で最低 4 本ある）
        $this->assertGreaterThanOrEqual(4, count($tags[0]) / 2, 'フォームの走査に失敗している');
    }

    // --- 無効化・有効化 ---

    public function test_an_approval_only_user_can_be_disabled_and_enabled(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->patch(route('approvals.admin.users.toggleStatus', $member), ['status' => UserStatus::Inactive->value])
            ->assertRedirect(route('approvals.admin.users.index'));
        $this->assertFalse($member->fresh()->isActive());

        $this->actingAs($this->admin())->patch(route('approvals.admin.users.toggleStatus', $member), ['status' => UserStatus::Active->value]);
        $this->assertTrue($member->fresh()->isActive());
    }

    public function test_a_base_user_cannot_be_disabled_here(): void
    {
        $base = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($this->admin())->patch(route('approvals.admin.users.toggleStatus', $base), ['status' => UserStatus::Inactive->value])
            ->assertStatus(403);

        $this->assertTrue($base->fresh()->isActive());
    }

    public function test_the_admin_cannot_disable_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('approvals.admin.users.toggleStatus', $admin), ['status' => UserStatus::Inactive->value])
            ->assertStatus(403);
    }

    // --- 再発行 ---

    /**
     * ⚠ **画面が描画したフォームをそのまま送り返す**（Bug #47）。`action` や `guide_token` が
     *   Alpine のバインドになっていると `ParsesForms` が拾えないので、ここで気づける。
     */
    public function test_reissue_renders_the_guide(): void
    {
        $member = $this->member(['name' => '決裁 次郎']);

        $list = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();
        $form = $this->parseForm($list, 'action="' . route('approvals.admin.users.reissue', $member) . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');
        $this->assertNotSame('', $form['fields']['guide_token'] ?? '', '1 回限りの鍵が描画されていない');

        $html = $this->actingAs($this->admin())->post($form['action'], $form['fields'])->assertOk()->getContent();

        $this->assertStringContainsString('ログインのご案内', $html);
        $this->assertStringContainsString('決裁 次郎', $html);
        $this->assertTrue($member->fresh()->must_change_password);
    }

    public function test_a_base_user_cannot_be_reissued_here(): void
    {
        $base = User::factory()->create(['must_change_password' => false]);
        $old  = $base->password;

        $this->actingAs($this->admin())->post(route('approvals.admin.users.reissue', $base), ['guide_token' => 'token-a'])
            ->assertStatus(403);

        $this->assertSame($old, $base->fresh()->password);
    }

    /** 同じ鍵の 2 回目は処理しない（設計書 §5.12） */
    public function test_the_same_token_cannot_be_used_twice(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->post(route('approvals.admin.users.reissue', $member), ['guide_token' => 'token-a'])->assertOk();
        $after = $member->fresh()->password;

        $this->actingAs($this->admin())->post(route('approvals.admin.users.reissue', $member), ['guide_token' => 'token-a'])
            ->assertRedirect(route('approvals.admin.users.index'))
            ->assertSessionHas('error');

        $this->assertSame($after, $member->fresh()->password, '2 回目でパスワードが変わっている');
    }

    // --- まとめて再発行（D9） ---

    public function test_selected_users_can_be_reissued_together(): void
    {
        $a = $this->member(['name' => '甲']);
        $b = $this->member(['name' => '乙']);
        $c = $this->member(['name' => '丙']);

        $html = $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'selected', 'user_ids' => [$a->id, $b->id], 'guide_token' => 'token-b',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('甲', $html);
        $this->assertStringContainsString('乙', $html);
        $this->assertStringNotContainsString('丙', $html);
        $this->assertTrue($a->fresh()->must_change_password);
        $this->assertFalse($c->fresh()->must_change_password);
    }

    /** 「絞り込んだ全員」は確定の時にサーバーが同じ条件で引き直す（設計書 §5.9） */
    public function test_filtered_mode_re_runs_the_query_on_the_server(): void
    {
        $inDept  = $this->member(['name' => '対象 甲']);
        $outside = $this->member(['name' => '対象外 乙']);
        $inDept->approvalDepartments()->sync([$this->dept->id]);

        $html = $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'filtered', 'department' => $this->dept->id, 'guide_token' => 'token-c',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('対象 甲', $html);
        $this->assertStringNotContainsString('対象外 乙', $html);
    }

    /** 決裁のみ・有効・未削除だけが対象（設計書 §5.9） */
    public function test_filtered_mode_never_touches_base_or_inactive_users(): void
    {
        $base     = User::factory()->create(['name' => '基幹 太郎', 'must_change_password' => false]);
        $inactive = $this->member(['name' => '無効 花子', 'status' => UserStatus::Inactive->value]);
        $active   = $this->member(['name' => '有効 次郎']);

        $html = $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'filtered', 'guide_token' => 'token-d',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('有効 次郎', $html);
        $this->assertStringNotContainsString('基幹 太郎', $html);
        $this->assertStringNotContainsString('無効 花子', $html);
        $this->assertSame($base->password, $base->fresh()->password);
    }

    public function test_bulk_reissue_is_capped(): void
    {
        config(['approval.bulk_reissue_max' => 2]);

        $ids = collect(range(1, 3))->map(fn () => $this->member()->id)->all();

        $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'selected', 'user_ids' => $ids, 'guide_token' => 'token-e',
        ])->assertRedirect(route('approvals.admin.users.index'))->assertSessionHas('error');
    }

    // --- D16: 決裁の権限を持つ人への操作 ---

    public static function privilegedCases(): array
    {
        return [
            '社長'         => ['president'],
            '決裁の管理者' => ['admin'],
            '全件閲覧者'   => ['viewer'],
        ];
    }

    private function privileged(string $kind): User
    {
        $user = $this->member(['name' => '要 保護']);

        match ($kind) {
            'president' => ApprovalSetting::current()->update(['president_user_id' => $user->id]),
            'admin'     => ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]),
            'viewer'    => ApprovalMember::create(['user_id' => $user->id, 'can_view_all' => true]),
        };

        return $user->fresh();
    }

    #[DataProvider('privilegedCases')]
    public function test_a_privileged_user_cannot_be_reissued(string $kind): void
    {
        $target = $this->privileged($kind);
        $old    = $target->password;

        $this->actingAs($this->admin())->post(route('approvals.admin.users.reissue', $target), ['guide_token' => 'token-f'])
            ->assertStatus(403);

        $this->assertSame($old, $target->fresh()->password);
    }

    #[DataProvider('privilegedCases')]
    public function test_a_privileged_user_cannot_be_disabled(string $kind): void
    {
        $target = $this->privileged($kind);

        $this->actingAs($this->admin())->patch(route('approvals.admin.users.toggleStatus', $target), ['status' => UserStatus::Inactive->value])
            ->assertStatus(403);

        $this->assertTrue($target->fresh()->isActive());
    }

    #[DataProvider('privilegedCases')]
    public function test_a_privileged_users_name_and_number_are_read_only(string $kind): void
    {
        $target = $this->privileged($kind);
        $number = $target->employee_number;

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $target), [
            'name' => '書き換え', 'employee_number' => 'X999', 'approval_departments' => [$this->dept->id],
        ])->assertRedirect(route('approvals.admin.users.index'));

        $target->refresh();
        $this->assertSame('要 保護', $target->name);
        $this->assertSame($number, $target->employee_number);
        // 所属部門は変えられる（D16 の例外）
        $this->assertCount(1, $target->approvalDepartments);
    }

    #[DataProvider('privilegedCases')]
    public function test_a_privileged_user_is_skipped_by_the_filtered_bulk_reissue(string $kind): void
    {
        $target  = $this->privileged($kind);
        $ordinary = $this->member(['name' => '普通 花子']);
        $old     = $target->password;

        $html = $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'filtered', 'guide_token' => 'token-g',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('普通 花子', $html);
        $this->assertStringNotContainsString('要 保護', $html);
        $this->assertSame($old, $target->fresh()->password);
    }

    /** ⚠ 選んで送っても 403（画面で選べないだけでなくサーバーでも拒む） */
    #[DataProvider('privilegedCases')]
    public function test_a_privileged_user_cannot_be_selected_for_a_bulk_reissue(string $kind): void
    {
        $target = $this->privileged($kind);

        $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'selected', 'user_ids' => [$target->id], 'guide_token' => 'token-h',
        ])->assertStatus(403);
    }

    /** 一覧で、指定されている人の行には選択欄を出さない（静かに外すのではなく選べないようにする） */
    public function test_the_list_does_not_offer_a_checkbox_for_privileged_users(): void
    {
        $privileged = $this->privileged('admin');
        $ordinary   = $this->member(['name' => '普通 花子']);

        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="user_ids\[\]"[^>]*value="' . $ordinary->id . '"/',
            $html,
            '普通の決裁のみ利用者に選択欄が出ていない'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="user_ids\[\]"[^>]*value="' . $privileged->id . '"/',
            $html,
            '決裁の権限を持つ人に選択欄が出ている'
        );
        $this->assertStringContainsString('決裁の管理者に指定されています', $html, '選べない理由が画面に無い');
    }

    /**
     * 行の操作も出さない（D16 を画面の側でも守る）。
     *
     * ⚠ サーバーの 403 だけでは「押せるのに断られる」画面になる。逆に画面で隠すだけでは
     *   手で組んだ送信が通る。**両方**を対で固定する（上の 403 のテストと対）。
     */
    public function test_the_list_offers_no_reissue_or_status_buttons_for_privileged_users(): void
    {
        $privileged = $this->privileged('viewer');
        $ordinary   = $this->member(['name' => '普通 花子']);

        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('action="' . route('approvals.admin.users.reissue', $ordinary) . '"', $html);
        $this->assertStringContainsString('action="' . route('approvals.admin.users.toggleStatus', $ordinary) . '"', $html);

        $this->assertStringNotContainsString('action="' . route('approvals.admin.users.reissue', $privileged) . '"', $html);
        $this->assertStringNotContainsString('action="' . route('approvals.admin.users.toggleStatus', $privileged) . '"', $html);
    }

    /** 決裁のホームから管理の 2 画面へ行ける（Task 6 で保留していたブロック） */
    public function test_the_approval_home_links_to_the_admin_screens(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($this->member())->get(route('approvals.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('approvals.admin.users.index'), $html, '管理者でない人にリンクが出ている');

        $html = $this->actingAs($admin)->get(route('approvals.home'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('approvals.admin.users.index') . '"', $html);
        $this->assertStringContainsString('href="' . route('approvals.admin.organization.index') . '"', $html);
    }
}
