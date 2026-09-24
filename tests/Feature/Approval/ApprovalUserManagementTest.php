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

    /**
     * 全件閲覧者は決裁の管理者ではないので 403（設計書 §5.17）。
     *
     * ⚠ これが「**3 状態目**」— 決裁の印の行が**在って** `is_admin` が false の人。
     *   2026-09-18 のレビューまで、この状態で画面を描いたり門番を叩いたりしたテストが
     *   アプリ全体で 1 本も無かった（棄却テストはどれも「行が無い」人だけ）。
     *   そのため門番の判定を `isApprovalAdmin()` から `(bool) $this->approvalMember` に
     *   取り違える変異が全件緑のまま通り、**全件閲覧者が利用者管理・部門管理を操作できる**
     *   ＝ 権限昇格になっても検出できなかった。
     * ⚠ `can_view_all` と `is_admin` は `Admin\UserController` が**独立に**保存するので、
     *   この人は段階1 の本番で普通に作れる。
     */
    public function test_a_view_all_member_is_rejected(): void
    {
        // 「守られる側」を作る privileged('viewer') と作るものは同じ。acting 側でも 1 度使う。
        $this->actingAs($this->privileged('viewer'))
            ->get(route('approvals.admin.users.index'))->assertStatus(403);
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

    /** 最終ログインは日本時間で出す（F6）。保存は UTC なので、そのまま整形すると 9 時間ずれる */
    public function test_the_last_login_is_shown_in_japan_time(): void
    {
        $member = $this->member(['name' => '決裁 次郎']);
        $member->forceFill(['last_login_at' => \Carbon\CarbonImmutable::parse('2026-09-18 17:30:00', 'UTC')])->save();

        $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))
            ->assertOk()
            ->assertSee('2026/09/19 02:30')
            ->assertDontSee('2026/09/18 17:30');
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

    /**
     * 決裁の管理者は自分自身も無効化できない。
     *
     * ⚠ **止めているのは `assertManageable`** のほう（自分は必ず決裁の管理者に指定されており、
     *   そもそも基幹を使う利用者でもある）。`UserController` の「自分自身」の判定は
     *   その手前で断られるので**到達しない** — この 1 本が緑でも、あちらが守られている
     *   証明にはならない（Bug #48）。
     */
    public function test_the_admin_is_refused_like_any_privileged_user(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('approvals.admin.users.toggleStatus', $admin), ['status' => UserStatus::Inactive->value])
            ->assertStatus(403);

        $this->assertTrue($admin->fresh()->isActive());
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
        $a = $this->member(['name' => '選択 甲一']);
        $b = $this->member(['name' => '選択 乙二']);
        $c = $this->member(['name' => '非選択 丙三']);

        $html = $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'selected', 'user_ids' => [$a->id, $b->id], 'guide_token' => 'token-b',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('選択 甲一', $html);
        $this->assertStringContainsString('選択 乙二', $html);
        $this->assertStringNotContainsString('非選択 丙三', $html);
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

        preg_match_all('/<input\b[^>]*name="user_ids\[\]"[^>]*>/', $html, $boxes);

        $forOrdinary = collect($boxes[0])->first(fn ($tag) => str_contains($tag, 'value="' . $ordinary->id . '"'));
        $this->assertNotNull($forOrdinary, '普通の決裁のみ利用者に選択欄が出ていない');

        // ⚠ 表を <form> で囲めない（行の操作が入れ子になる）ので、選択欄は form 属性で結び付ける。
        //   外すとチェックがどのフォームにも属さず、「選んだ N 人」が user_ids 無しで飛ぶ
        $this->assertStringContainsString('form="approvalBulkReissue"', $forOrdinary, '選択欄がまとめて再発行のフォームに結び付いていない');
        // 確認のモーダルはこの data-name から氏名を出す（設計書 §5.9）
        $this->assertStringContainsString('data-name="' . $ordinary->name . '"', $forOrdinary, '確認に出す氏名が選択欄に無い');

        $this->assertNull(
            collect($boxes[0])->first(fn ($tag) => str_contains($tag, 'value="' . $privileged->id . '"')),
            '決裁の権限を持つ人に選択欄が出ている'
        );

        // ⚠ 同じ文言が選択欄と操作欄の `title` にも出るので、**画面に見える本文**をタグごと見る。
        //   素の部分一致だと、本文の行を消しても title に当たって緑のまま通る（Bug #43 / #46）
        $this->assertMatchesRegularExpression(
            '/<span class="block[^"]*">決裁の管理者に指定されています<\/span>/',
            $html,
            '選べない理由が画面の本文に出ていない（title だけではキーボード・読み上げに届かない）'
        );
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

    // ============================================================
    // 画面の配線（Bug #47）— 描画されたフォームをそのまま送り返す
    // ============================================================

    /**
     * まとめて再発行を、**画面が描いたフォームのまま**送り返す。
     *
     * ⚠ これが無いと 2 つの重大な壊れ方が緑のまま通る:
     *   ① 絞り込みの hidden を落とす → 「絞り込んだ全員（3 人）」が上限いっぱいの人を再発行する
     *   ② `guide_token` の hidden を落とす → まとめて再発行が 1 回目から
     *      「すでに実行されました」で止まる（`OneTimeAction::claimFrom()` が `is_string(null)` を false にする）
     * ⚠ `mode` は `<button name="mode">` なので `parseForm`（`<input>` だけを見る）には入らない。
     *   ブラウザは押したボタンの名前と値を送るので、ここでも手で足す。
     */
    public function test_the_filtered_bulk_reissue_submits_the_form_the_page_rendered(): void
    {
        $hit  = $this->member(['name' => '田中 一郎']);
        $miss = $this->member(['name' => '鈴木 二郎']);
        $missPassword = $miss->password;

        $html = $this->actingAs($this->admin())
            ->get(route('approvals.admin.users.index', ['search' => '田中']))
            ->assertOk()->getContent();

        $form = $this->parseForm($html, 'action="' . route('approvals.admin.users.reissueBulk') . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');
        $this->assertNotSame('', $form['fields']['guide_token'] ?? '', '1 回限りの鍵が描画されていない');
        $this->assertSame('田中', $form['fields']['search'] ?? '', '絞り込みの条件が hidden で運ばれていない');

        $guide = $this->actingAs($this->admin())
            ->post($form['action'], $form['fields'] + ['mode' => 'filtered'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('田中 一郎', $guide);
        $this->assertStringNotContainsString('鈴木 二郎', $guide, '絞り込みに当たらない人まで再発行されている');

        $this->assertTrue($hit->fresh()->must_change_password);
        $this->assertSame($missPassword, $miss->fresh()->password, '絞り込みの外の人のパスワードが変わっている');
    }

    /**
     * まとめて再発行の**二重送信**を、画面が描いたフォームのまま確かめる（設計書 §5.12）。
     *
     * ⚠ これまでの「まとめて再発行」の POST は 8 本とも別々のトークンを発行していて、
     *   **同じトークンで 2 回送る**テストが 1 本も無かった。
     * ⚠ 素の `assertSessionHas('error')` では足りない — このコントローラは
     *   `$targets->isEmpty()`（「対象の利用者がいません」）でも同じ `'error'` キーに文言を積む
     *   ので、**文言の一致まで**見て初めて「この操作はすでに実行されました」の断り（鍵の
     *   二重送信）であることを確かめられる（Bug #44「落ちた理由の文言まで突き合わせる」と同じ流儀）。
     */
    public function test_the_filtered_bulk_reissue_rejects_a_resubmitted_token(): void
    {
        $hit = $this->member(['name' => '田中 一郎']);

        $html = $this->actingAs($this->admin())
            ->get(route('approvals.admin.users.index', ['search' => '田中']))
            ->assertOk()->getContent();

        $form   = $this->parseForm($html, 'action="' . route('approvals.admin.users.reissueBulk') . '"');
        $fields = $form['fields'] + ['mode' => 'filtered'];

        $this->actingAs($this->admin())->post($form['action'], $fields)->assertOk();

        $reissuedPassword = $hit->fresh()->password;

        $this->actingAs($this->admin())->post($form['action'], $fields)
            ->assertRedirect(route('approvals.admin.users.index'))
            ->assertSessionHas('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度再発行してください。');

        $this->assertSame($reissuedPassword, $hit->fresh()->password, '2 回目の再送信でパスワードが作り直された');
    }

    /**
     * 無効化・有効化も**描画されたフォームのまま**送り返す。
     *
     * ⚠ `@method('PATCH')` を落とすとブラウザでは 405 ＝ ボタンが無反応、`name="status"` を
     *   落とすと「状態は必須です」で差し戻る。どちらも `route()` を直に叩くテストでは緑のまま通る。
     */
    public function test_the_status_button_submits_the_form_the_page_rendered(): void
    {
        $member = $this->member(['name' => '田中 一郎']);

        // 有効 → 無効
        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('approvals.admin.users.toggleStatus', $member) . '"');

        $this->assertSame('PATCH', $form['method'], "@method('PATCH') が描画されていない");
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');
        $this->assertSame(UserStatus::Inactive->value, $form['fields']['status'] ?? null, '有効な人のボタンが「無効化」を送っていない');

        $this->actingAs($this->admin())->post($form['action'], $form['fields'])
            ->assertRedirect(route('approvals.admin.users.index'));
        $this->assertFalse($member->fresh()->isActive());

        // 無効 → 有効（同じ行のボタンが逆を送る）
        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('approvals.admin.users.toggleStatus', $member) . '"');

        $this->assertSame(UserStatus::Active->value, $form['fields']['status'] ?? null, '無効な人のボタンが「有効化」を送っていない');

        $this->actingAs($this->admin())->post($form['action'], $form['fields']);
        $this->assertTrue($member->fresh()->isActive());
    }

    /**
     * 編集モーダルが**何を送るか**を固定する。
     *
     * ⚠ 送信先は Alpine が組み立てるので `parseForm` では往復できない
     *   （`ParsesForms::htmlAttr` が `:` の直後を除外する）。代わりに、その `<form>` の中に
     *   入力欄が描かれていることを見る。`name="approval_departments[]"` が消えると
     *   **保存のたびに所属部門が全部消える**（`$validated[...] ?? []` が `sync([])` になる）のに、
     *   画面は「更新しました」と出る。
     */
    public function test_the_edit_form_carries_the_fields_it_must_send(): void
    {
        $this->member(['name' => '田中 一郎', 'employee_number' => 'A0001']);

        $form = $this->editFormMarkup(
            $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent()
        );

        $this->assertStringContainsString('name="name"', $form, '編集モーダルに氏名の入力欄が無い');
        $this->assertStringContainsString('name="employee_number"', $form, '編集モーダルに社員番号の入力欄が無い');

        // 登録済みの部門 2 つとも選べる
        $this->assertSame(2, substr_count($form, 'name="approval_departments[]"'), '所属部門の選択欄が描かれていない');

        // ⚠ メールアドレスは基幹の管理者だけが変えられる（D8）ので、入力欄そのものを置かない
        $this->assertStringNotContainsString('name="email"', $form, 'メールアドレスの入力欄が描かれている');
    }

    /** 編集モーダルの `<form>` だけを切り出す（送信先が Alpine のバインドで `parseForm` が使えないため） */
    private function editFormMarkup(string $html): string
    {
        $anchor = strpos($html, 'name="_method" value="PUT"');
        $this->assertNotFalse($anchor, "編集モーダルの @method('PUT') が描画されていない");

        $open  = strrpos(substr($html, 0, $anchor), '<form');
        $close = strpos($html, '</form>', $anchor);
        $this->assertNotFalse($open, '編集モーダルの <form> の開始タグが見つからない');
        $this->assertNotFalse($close, '編集モーダルの <form> が閉じていない');

        return substr($html, $open, $close - $open);
    }

    // ============================================================
    // 人数・氏名・絞り込み
    // ============================================================

    /**
     * 「絞り込んだ全員（N 人）」の N は**実際に再発行される人数**（`UserController::index` の ⚠）。
     *
     * ⚠ `$users->total()` に戻すと基幹を使う人・無効な人・指定されている人まで数え、押した結果と
     *   食い違う。**人数と実際の対象を 1 本で突き合わせる**（片方だけ見ると定義がずれても緑になる）。
     */
    public function test_the_filtered_button_counts_only_the_people_it_will_actually_reissue(): void
    {
        User::factory()->create(['name' => '基幹 太郎', 'must_change_password' => false]);
        $this->member(['name' => '無効 花子', 'status' => UserStatus::Inactive->value]);
        $this->privileged('admin');
        $this->member(['name' => '田中 一郎']);
        $this->member(['name' => '鈴木 二郎']);

        $response = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk();

        $this->assertSame(2, $response->viewData('reissuableCount'));
        $this->assertStringContainsString('絞り込んだ全員（2 人）を再発行', $response->getContent());

        $guide = $this->actingAs($this->admin())->post(route('approvals.admin.users.reissueBulk'), [
            'mode' => 'filtered', 'guide_token' => 'token-count',
        ])->assertOk()->getContent();

        // 画面に出した人数 == 実際に再発行された人数
        $this->assertSame(2, User::where('must_change_password', true)->count(), 'ボタンの人数と実際の対象が食い違う');
        $this->assertStringContainsString('田中 一郎', $guide);
        $this->assertStringContainsString('鈴木 二郎', $guide);
        $this->assertStringNotContainsString('基幹 太郎', $guide);
        $this->assertStringNotContainsString('無効 花子', $guide);
        $this->assertStringNotContainsString('要 保護', $guide);
    }

    /**
     * まとめて再発行の確認（設計書 §5.9「確認のモーダルに人数と氏名を出す」）。
     *
     * ⚠ 実際に開くのは Alpine なので PHP からは**形しか見られない**。人数と氏名の出どころと、
     *   確定のボタンが `name="mode"` を**サーバーが描いた値**で持つことを固定する
     *   （`:value` にすると往復テストが拾えず配線が無防備になる。Bug #47）。
     */
    public function test_the_bulk_reissue_asks_for_confirmation_with_the_count_and_the_names(): void
    {
        $this->member(['name' => '田中 一郎']);
        $this->member(['name' => '鈴木 二郎']);

        $response = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk();
        $html     = $response->getContent();

        $this->assertEqualsCanonicalizing(['田中 一郎', '鈴木 二郎'], $response->viewData('reissuableNames'));
        $this->assertStringContainsString('var APPROVAL_FILTERED_COUNT = 2;', $html, '確認に出す人数がサーバーから渡っていない');
        $this->assertStringContainsString('田中 一郎', $html);

        // 確定のボタンは 2 本（選んだ人 / 絞り込んだ全員）で、値はサーバーが描く
        $this->assertSame(1, substr_count($html, 'name="mode" value="selected"'), '「選んだ人」を送るボタンが 1 本でない');
        $this->assertSame(1, substr_count($html, 'name="mode" value="filtered"'), '「絞り込んだ全員」を送るボタンが 1 本でない');

        // ⚠ 値と出し分けの条件が対になっていること。**入れ替えても本数は 2 本のまま**なので、
        //   数えるだけでは「選んだ 2 人」が絞り込んだ全員を再発行する事故を検出できない
        foreach (['selected', 'filtered'] as $mode) {
            $this->assertMatchesRegularExpression(
                '/name="mode" value="' . $mode . '"[^>]*x-show="confirmMode === \'' . $mode . '\'"/',
                $html,
                "確定のボタンの mode と出し分けの条件が食い違っている（{$mode}）"
            );
        }

        // 開く側は submit ではない（押しただけでは送信されず、確認を経る）
        $this->assertStringContainsString("openConfirm('selected')", $html);
        $this->assertStringContainsString("openConfirm('filtered')", $html);

        // ⚠ 素の confirm() へ戻すと人数も氏名も出せない（§5.9 に反する）
        $this->assertStringNotContainsString('onclick="return confirm(', $html);
    }

    public function test_it_can_filter_by_kind(): void
    {
        $base     = User::factory()->create(['name' => '基幹 太郎', 'must_change_password' => false]);
        $approval = $this->member(['name' => '決裁 次郎']);

        $ids = $this->actingAs($this->admin())->get(route('approvals.admin.users.index', ['kind' => 'approval']))
            ->assertOk()->viewData('users')->pluck('id');
        $this->assertTrue($ids->contains($approval->id));
        $this->assertFalse($ids->contains($base->id));

        $ids = $this->actingAs($this->admin())->get(route('approvals.admin.users.index', ['kind' => 'base']))
            ->assertOk()->viewData('users')->pluck('id');
        $this->assertTrue($ids->contains($base->id));
        $this->assertFalse($ids->contains($approval->id));
    }

    public function test_it_can_filter_by_status(): void
    {
        $active   = $this->member(['name' => '有効 太郎']);
        $inactive = $this->member(['name' => '無効 花子', 'status' => UserStatus::Inactive->value]);

        $ids = $this->actingAs($this->admin())->get(route('approvals.admin.users.index', ['status' => UserStatus::Inactive->value]))
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertTrue($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($active->id));
    }

    /**
     * 検索は氏名・社員番号・メールの 3 列すべてに当たる。
     *
     * ⚠ `filteredQuery()` は一覧と「絞り込んだ全員」で**共用**なので、ここが壊れると
     *   表示だけでなく**再発行の対象が静かに広がる**。
     */
    public function test_the_search_box_looks_at_the_name_the_number_and_the_email(): void
    {
        $byName   = $this->member(['name' => '検索 太郎', 'employee_number' => 'AAA1']);
        $byNumber = $this->member(['name' => '無関係 一', 'employee_number' => 'ZZZ9']);
        $byEmail  = $this->member(['name' => '無関係 二', 'employee_number' => 'BBB2', 'email' => 'needle@example.com']);

        $find = fn (string $query) => $this->actingAs($this->admin())
            ->get(route('approvals.admin.users.index', ['search' => $query]))
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertTrue($find('検索 太郎')->contains($byName->id));
        $this->assertFalse($find('検索 太郎')->contains($byNumber->id));
        $this->assertTrue($find('ZZZ9')->contains($byNumber->id), '社員番号で検索できない');
        $this->assertTrue($find('needle@')->contains($byEmail->id), 'メールアドレスで検索できない');
    }

    /** 検索語が配列で届いても落とさない（`"%{$search}%"` が `Array to string conversion` で 500 になる） */
    public function test_an_array_shaped_filter_does_not_break_the_page(): void
    {
        $this->member(['name' => '田中 一郎']);

        $this->actingAs($this->admin())
            ->get(route('approvals.admin.users.index') . '?search[]=a&department[]=1&kind[]=x&status[]=y')
            ->assertOk();
    }

    // ============================================================
    // 値の扱いと記録
    // ============================================================

    /**
     * 社員番号 `0` は形式として有効な値（英数字 1 文字）。
     *
     * ⚠ 正規化を `?: null` で書くと PHP では `'0'` が falsy なので**この 1 文字だけ黙って消える**。
     *   メールを持つ人はログイン ID を失い、持たない人は保存すらできなくなる。
     */
    public function test_an_employee_number_of_zero_is_kept(): void
    {
        $member = $this->member(['name' => '田中 一郎', 'employee_number' => 'A0001', 'email' => 'zero@example.com']);

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $member), [
            'name' => '田中 一郎', 'employee_number' => '0', 'approval_departments' => [],
        ])->assertRedirect(route('approvals.admin.users.index'));

        $this->assertSame('0', $member->fresh()->employee_number, '社員番号「0」が黙って消えている');
    }

    /**
     * 編集できない相手でも、**所属部門の変更だけは通る**（要件 3.2・D16 の例外）。
     *
     * ⚠ `readonly` な入力欄は値を送る（送らないのは `disabled`）ので、氏名・社員番号のルールを
     *   残したままにすると、移行や直の SQL で入った古い形の社員番号を持つ人がここで弾かれ、
     *   **唯一許された操作までできなくなる**。しかもエラー文は編集できない欄を指す。
     */
    public function test_the_departments_of_a_user_with_a_legacy_number_can_still_be_edited(): void
    {
        $base = User::factory()->create(['name' => '基幹 太郎', 'must_change_password' => false]);
        // 画面からは作れない形（小文字・記号入り）を直に入れる
        $base->forceFill(['employee_number' => 'old#001'])->saveQuietly();

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $base), [
            'name' => '書き換え', 'employee_number' => 'old#001',
            'approval_departments' => [$this->dept->id],
        ])->assertRedirect(route('approvals.admin.users.index'));

        $base->refresh();
        $this->assertCount(1, $base->approvalDepartments, '所属部門の変更が通っていない');
        $this->assertSame('基幹 太郎', $base->name, '編集できない欄が書き換わっている');
        $this->assertSame('old#001', $base->employee_number, '編集できない欄が書き換わっている');
    }

    /** 所属になった日を残す（`withPivot` だけだと読む宣言にしかならず `sync()` は書かない） */
    public function test_the_moment_someone_joins_a_department_is_recorded(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $member), [
            'name' => $member->name, 'employee_number' => $member->employee_number,
            'approval_departments' => [$this->dept->id],
        ]);

        $this->assertNotNull(
            $member->fresh()->approvalDepartments->first()->pivot->created_at,
            '所属になった日時が記録されていない'
        );
    }

    /** 変更はすべて記録する（設計書 §5.9 の末尾）。氏名・社員番号の修正と状態の変更も残す */
    public function test_the_identity_and_status_changes_are_recorded(): void
    {
        $member = $this->member(['name' => '旧 名前', 'employee_number' => 'A0001']);

        $this->actingAs($this->admin())->put(route('approvals.admin.users.update', $member), [
            'name' => '新 名前', 'employee_number' => 'A0002', 'approval_departments' => [],
        ]);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.updated')->count(), '氏名・社員番号の変更が記録されていない');

        $this->actingAs($this->admin())->patch(route('approvals.admin.users.toggleStatus', $member), [
            'status' => UserStatus::Inactive->value,
        ]);
        $this->assertSame(2, ApprovalSettingLog::where('action', 'user.updated')->count(), '状態の変更が記録されていない');
    }

    // ============================================================
    // 断りの文言（設計書 §5.9 は §5.7 と同じ形を求める）
    // ============================================================

    public static function privilegeLabelCases(): array
    {
        return [
            '社長'         => ['president', '決裁の社長'],
            '決裁の管理者' => ['admin', '決裁の管理者'],
            '全件閲覧者'   => ['viewer', '決裁の全件閲覧者'],
        ];
    }

    /**
     * ⚠ 呼び名は基幹の §5.7（`Admin\UserController` の「決裁の社長に指定されています」）と
     *   そろえる。`approvalPrivilegeLabel()` が「社長」「全件閲覧者」を返していると、
     *   **同じ人の同じ状態が画面によって別の呼び方**になる。
     * ⚠ 呼ぶ側で「決裁の」を前に足すと「決裁の決裁の管理者」になるので、ラベル側で完成させる。
     */
    #[DataProvider('privilegeLabelCases')]
    public function test_the_refusal_names_the_privilege_the_way_the_base_screens_do(string $kind, string $label): void
    {
        $target = $this->privileged($kind);

        $this->assertSame($label, $target->approvalPrivilegeLabel());

        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();
        $this->assertStringContainsString("要 保護さんは{$label}に指定されています。", $html);
    }
}
