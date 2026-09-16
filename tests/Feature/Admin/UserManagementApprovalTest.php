<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 基幹の利用者管理（`/admin/users`・経営層のみ。設計書 §5.7）。
 *
 * ⚠ **画面が描画したフォームを分解して送り返す**（Bug #47）。
 * ⚠ 初期パスワードは画面の JS（`Math.random`）で作るのをやめ、サーバーで作って案内の画面に出す（D12）。
 * ⚠ 再発行の平文をセッションのフラッシュに入れない（sessions テーブルに残る）。
 */
class UserManagementApprovalTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['name' => 'テナント', 'code' => 'tenant', 'display_order' => 1]);
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value, 'status' => UserStatus::Active->value, 'must_change_password' => false,
        ]);
    }

    /**
     * 新規登録フォームを指す needle。
     *
     * ⚠ **`method="POST"` まで含める。** `route('admin.users.store')` と
     *   `route('admin.users.index')` は**同じ URL**（`POST /admin/users` と `GET /admin/users`）なので、
     *   `action="…"` だけで探すと**先に出てくる絞り込みフォーム**（GET）を掴む。
     *   実測: それでも POST は届いてしまうので**テストは緑のまま通り**、新規登録フォームの
     *   配線は一切測られない（Bug #47 の型をテスト自身が踏む）。
     */
    private function createFormNeedle(): string
    {
        return 'method="POST" action="' . route('admin.users.store') . '"';
    }

    /** @return array{0: array{method: string, action: string, fields: array<string, string>}, 1: array<string, mixed>} */
    private function createForm(array $overrides = []): array
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, $this->createFormNeedle());

        $this->assertSame('POST', $form['method'], '新規登録フォームが POST でない');
        $this->assertArrayHasKey('_token', $form['fields'], '新規登録フォームに @csrf が無い');

        return [$form, array_merge($form['fields'], $overrides)];
    }

    /** コメントを落としたソース（自分が書いた注意書きに一致して false-pass するのを防ぐ。Bug #42 ②） */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** 一覧に社員番号の列がある */
    public function test_the_list_shows_the_employee_number(): void
    {
        User::factory()->create(['name' => '甲 一郎', 'employee_number' => 'M001', 'must_change_password' => false]);

        $this->actingAs($this->executive())->get('/admin/users')->assertOk()->assertSee('M001');
    }

    /** 検索は氏名・社員番号・メールを見る */
    public function test_search_covers_the_employee_number(): void
    {
        $hit  = User::factory()->create(['name' => '甲 一郎', 'employee_number' => 'M001', 'must_change_password' => false]);
        $miss = User::factory()->create(['name' => '乙 二郎', 'employee_number' => 'M999', 'must_change_password' => false]);

        $ids = $this->actingAs($this->executive())->get('/admin/users?search=M001')
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertTrue($ids->contains($hit->id));
        $this->assertFalse($ids->contains($miss->id));
    }

    /** ロールの絞り込みに「決裁のみ」が出る */
    public function test_the_role_filter_includes_approval_only(): void
    {
        $this->actingAs($this->executive())->get('/admin/users')->assertOk()->assertSee('決裁のみ');
    }

    /** 新規登録: 初期パスワードの入力欄と JS の生成をやめる（D12） */
    public function test_the_create_form_no_longer_asks_for_a_password(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="password"', $html, '初期パスワードの入力欄が残っている');
        $this->assertStringNotContainsString('Math.random', $html, '画面の JS がパスワードを作っている');
        $this->assertStringContainsString('name="employee_number"', $html, '社員番号の入力欄が無い');
    }

    /** 新規登録すると案内の画面がその場で返る */
    public function test_creating_a_user_renders_the_login_guide(): void
    {
        [$form, $fields] = $this->createForm([
            'name'            => '甲 一郎',
            'employee_number' => 'M001',
            'email'           => 'a@example.com',
            'role'            => UserRole::Staff->value,
            'departments'     => [$this->department->id],
        ]);

        $response = $this->actingAs($this->executive())->post($form['action'], $fields);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('ログインのご案内', $html);
        $this->assertStringContainsString('甲 一郎', $html);
        $this->assertStringContainsString('M001', $html);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $user = User::where('employee_number', 'M001')->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertSame(10, password_get_info($user->password)['options']['cost']);

        // 平文はどこにも残さない（D12）
        $this->assertNull(session('reset_password'));
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.created')->count());
    }

    /** メールアドレスは任意。ただし社員番号と両方空は拒む */
    public function test_one_of_the_two_identifiers_is_required(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '甲 一郎', 'employee_number' => '', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasErrors('employee_number');

        $this->assertSame(0, User::where('name', '甲 一郎')->count());
    }

    public function test_an_email_only_user_can_be_created(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '乙 二郎', 'employee_number' => '', 'email' => 'b@example.com',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)->assertOk();

        $this->assertNull(User::where('email', 'b@example.com')->sole()->employee_number);
    }

    /** 一意の検査は正規化した値で行う（大文字小文字の違いで本番の索引に当たらないように） */
    public function test_duplicate_detection_uses_the_normalized_value(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        [$form, $fields] = $this->createForm([
            'name' => '乙 二郎', 'employee_number' => ' m001 ', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasErrors('employee_number');
    }

    /** 新規登録のロールの選択肢に「決裁のみ」は出さない（CSV で作る） */
    public function test_the_create_form_does_not_offer_approval_only(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $pos    = strpos($html, $this->createFormNeedle());
        $this->assertNotFalse($pos, '新規登録フォームが見つからない');
        $create = substr($html, $pos, strpos($html, '</form>', $pos) - $pos);

        $this->assertStringNotContainsString(UserRole::ApprovalOnly->value, $create, '新規登録で決裁のみを選べてしまう');
        foreach (UserRole::baseCases() as $role) {
            $this->assertStringContainsString('value="' . $role->value . '"', $create, "{$role->value} が選べない");
        }
    }

    /** 再発行は案内の画面を返し、セッションに平文を入れない（D12） */
    public function test_reissue_renders_the_guide_and_keeps_nothing_in_the_session(): void
    {
        $target = User::factory()->create(['name' => '甲 一郎', 'email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);
        $before = $target->password;

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.resetPassword', $target) . '"');

        // 案内の画面を返すので POST（旧実装は PUT だった）
        $this->assertSame('POST', $form['method'], '再発行が POST で送られていない');
        $this->assertArrayHasKey('_token', $form['fields'], '再発行フォームに @csrf が無い');
        $this->assertArrayHasKey('guide_token', $form['fields'], '1 回限りの鍵が描かれていない');

        $response = $this->actingAs($this->executive())->post($form['action'], $form['fields']);

        $response->assertOk();
        $this->assertStringContainsString('ログインのご案内', $response->getContent());
        $this->assertNull(session('reset_password'), '平文がセッションに入っている');

        $target->refresh();
        $this->assertNotSame($before, $target->password);
        $this->assertTrue($target->must_change_password);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.password_reissued')->count());
    }

    /**
     * 同じ鍵で 2 回目は通さない（ブラウザの「フォームを再送信しますか」対策。設計書 §5.12）。
     *
     * ⚠ これが無いと、印刷済みの案内がこっそり無効になる。
     */
    public function test_the_reissue_form_can_only_be_submitted_once(): void
    {
        $target = User::factory()->create(['email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.resetPassword', $target) . '"');

        $this->actingAs($this->executive())->post($form['action'], $form['fields'])->assertOk();

        $after = $target->fresh()->password;

        $this->actingAs($this->executive())->post($form['action'], $form['fields'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->assertSame($after, $target->fresh()->password, '2 回目の再送信でパスワードが作り直された');
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.password_reissued')->count());
    }

    /** 一覧から「再発行結果」の表示が消えている */
    public function test_the_old_reset_password_banner_is_gone(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $this->assertStringNotContainsString("session('reset_password')", $html);
        $this->assertStringNotContainsString('新しい初期パスワード', $html);
    }

    /**
     * 平文をセッションに入れる経路と、暗号用でない乱数が残っていないこと。
     *
     * ⚠ **挙動では測れない部分がある** — `str_shuffle()` は結果が「それらしい」ので、
     *   出来たパスワードを見ても分からない（Bug #42 ② と同じで、構造で測るしかない）。
     */
    public function test_the_controller_keeps_no_plain_password_and_uses_no_weak_randomness(): void
    {
        $src = $this->sourceWithoutComments(app_path('Http/Controllers/Admin/UserController.php'));

        $this->assertStringNotContainsString('reset_password', $src, '平文をセッションへ流す経路が残っている');
        $this->assertStringNotContainsString('str_shuffle', $src, '暗号用でない乱数が残っている');
        $this->assertStringContainsString('InitialPassword::generate', $src, '初期パスワードの生成が 1 本化されていない');
    }

    /** 社長の指定（有効でメールアドレスのある人だけ） */
    public function test_the_president_can_be_designated(): void
    {
        $candidate = User::factory()->create(['name' => '社長 三郎', 'email' => 'p@example.com', 'must_change_password' => false]);

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.president') . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '社長の指定フォームに @csrf が無い');
        $this->assertStringContainsString(
            'value="' . $candidate->id . '"',
            $html,
            '有効でメールアドレスのある人が候補に出ていない'
        );

        $this->actingAs($this->executive())->post($form['action'], array_merge($form['fields'], [
            'president_user_id' => $candidate->id,
        ]))->assertRedirect(route('admin.users.index'));

        $this->assertSame($candidate->id, ApprovalSetting::current()->president_user_id);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'president.changed')->count());
    }

    public function test_a_user_without_an_email_cannot_be_the_president(): void
    {
        $candidate = User::factory()->create(['email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);

        $this->actingAs($this->executive())
            ->post(route('admin.users.president'), ['president_user_id' => $candidate->id])
            ->assertSessionHasErrors('president_user_id');

        $this->assertNull(ApprovalSetting::current()->president_user_id);
    }

    public function test_an_inactive_user_cannot_be_the_president(): void
    {
        $candidate = User::factory()->create([
            'email' => 'p@example.com', 'status' => UserStatus::Inactive->value, 'must_change_password' => false,
        ]);

        $this->actingAs($this->executive())
            ->post(route('admin.users.president'), ['president_user_id' => $candidate->id])
            ->assertSessionHasErrors('president_user_id');

        $this->assertNull(ApprovalSetting::current()->president_user_id);
    }

    /** 社長に指定された人は無効化・削除・メールアドレスを空にできない（設計書 §5.7） */
    public function test_the_president_is_protected(): void
    {
        $president = User::factory()->create([
            'role' => UserRole::Staff->value, 'email' => 'p@example.com', 'employee_number' => 'M001', 'must_change_password' => false,
        ]);
        ApprovalSetting::current()->update(['president_user_id' => $president->id]);

        $this->actingAs($this->executive())
            ->patch(route('admin.users.toggleStatus', $president), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error');
        $this->assertTrue($president->fresh()->isActive());

        $this->actingAs($this->executive())->delete(route('admin.users.destroy', $president))->assertSessionHas('error');
        $this->assertFalse($president->fresh()->trashed());

        $this->actingAs($this->executive())->put(route('admin.users.update', $president), [
            'name' => $president->name, 'employee_number' => 'M001', 'email' => '',
            'role' => $president->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ])->assertSessionHas('error');
        $this->assertSame('p@example.com', $president->fresh()->email);
    }

    /** 社長でも「有効化」は止めない（無効化だけを止める） */
    public function test_the_president_can_still_be_re_activated(): void
    {
        $president = User::factory()->create([
            'role' => UserRole::Staff->value, 'email' => 'p@example.com', 'status' => UserStatus::Inactive->value,
            'must_change_password' => false,
        ]);
        ApprovalSetting::current()->update(['president_user_id' => $president->id]);

        $this->actingAs($this->executive())
            ->patch(route('admin.users.toggleStatus', $president), ['status' => UserStatus::Active->value])
            ->assertSessionHas('success');

        $this->assertTrue($president->fresh()->isActive());
    }

    /** 全件閲覧者・決裁の管理者の指定 */
    public function test_approval_flags_can_be_set_from_the_edit_form(): void
    {
        $target = User::factory()->create([
            'role' => UserRole::Staff->value, 'employee_number' => 'M001', 'email' => 'a@example.com', 'must_change_password' => false,
        ]);
        $target->departments()->attach($this->department->id);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => 'M001', 'email' => 'a@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
            'is_admin' => '1', 'can_view_all' => '1',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue($target->fresh()->isApprovalAdmin());
        $this->assertTrue($target->fresh()->canViewAllApprovals());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'member.flags_changed')->count());
    }

    /** 外すほうも動く（付けるだけのテストでは「解除できない」に気づけない） */
    public function test_approval_flags_can_be_cleared_again(): void
    {
        $target = User::factory()->create([
            'role' => UserRole::Staff->value, 'employee_number' => 'M001', 'email' => 'a@example.com', 'must_change_password' => false,
        ]);
        $target->departments()->attach($this->department->id);
        $target->approvalMember()->create(['can_view_all' => true, 'is_admin' => true]);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => 'M001', 'email' => 'a@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ])->assertRedirect(route('admin.users.index'));

        $fresh = $target->fresh();
        $this->assertFalse($fresh->isApprovalAdmin());
        $this->assertFalse($fresh->canViewAllApprovals());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'member.flags_changed')->count());
    }

    /** 何も変わらなければ記録を増やさない */
    public function test_unchanged_approval_flags_are_not_recorded(): void
    {
        $target = User::factory()->create([
            'role' => UserRole::Staff->value, 'employee_number' => 'M001', 'email' => 'a@example.com', 'must_change_password' => false,
        ]);
        $target->departments()->attach($this->department->id);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => 'M001', 'email' => 'a@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertSame(0, ApprovalSettingLog::where('action', 'member.flags_changed')->count());
    }

    /**
     * 編集画面が決裁の指定と社員番号を実際に描いていること。
     *
     * ⚠ 上の 3 本は値を直接 POST しているので、**画面からその欄が消えても緑のまま通る**（Bug #47）。
     *   編集モーダルは Alpine が `:action` を組み立てるため `parseForm` では拾えない
     *   （`htmlAttr()` が `:` 付きの属性を意図的に無視する）ので、生 HTML で対にして固定する。
     */
    public function test_the_edit_form_offers_the_approval_flags_and_the_approval_only_role(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $edit = $this->editModalHtml($html);

        $this->assertStringContainsString('name="is_admin"', $edit, '決裁の管理者のチェックが無い');
        $this->assertStringContainsString('name="can_view_all"', $edit, '全件閲覧者のチェックが無い');
        $this->assertStringContainsString('name="employee_number"', $edit, '編集に社員番号の欄が無い');
        $this->assertStringContainsString('value="' . UserRole::ApprovalOnly->value . '"', $edit, '編集で決裁のみを選べない');
        $this->assertStringContainsString('name="_method" value="PUT"', $edit, '編集が PUT で送られない');
    }

    /** 編集モーダルの HTML（`x-model="editName"` を持つフォーム） */
    private function editModalHtml(string $html): string
    {
        $pos = strpos($html, 'x-model="editName"');
        $this->assertNotFalse($pos, '編集モーダルが見つからない');

        $open  = strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);

        return substr($html, $open, $close - $open);
    }

    /** 決裁のみへ変えると基幹の所属部門が外れる（設計書 §5.7） */
    public function test_switching_to_approval_only_drops_the_base_departments(): void
    {
        $target = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);
        $target->departments()->attach($this->department->id);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::ApprovalOnly->value, 'status' => UserStatus::Active->value,
        ])->assertRedirect(route('admin.users.index'));

        $this->assertCount(0, $target->fresh()->departments);
        $this->assertTrue($target->fresh()->isApprovalOnly());
    }

    /** 決裁のみから基幹のロールへ戻すときは所属部門が必須 */
    public function test_switching_back_requires_a_base_department(): void
    {
        $target = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => $target->employee_number, 'email' => '',
            'role' => UserRole::Staff->value, 'status' => UserStatus::Active->value,
        ])->assertSessionHasErrors('departments');

        $this->assertTrue($target->fresh()->isApprovalOnly(), 'エラーなのにロールが変わっている');
    }

    /** 削除と復元も記録に残る（設計書 §5.14 の表） */
    public function test_deletion_and_restore_are_recorded(): void
    {
        $target = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($this->executive())->delete(route('admin.users.destroy', $target));
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.deleted')->count());

        $this->actingAs($this->executive())->patch(route('admin.users.restore', $target->id));
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.restored')->count());
    }

    /** 変更は記録に残る */
    public function test_changes_are_recorded(): void
    {
        $target = User::factory()->create([
            'role' => UserRole::Staff->value, 'name' => '旧 名前', 'employee_number' => 'M001',
            'email' => 'a@example.com', 'must_change_password' => false,
        ]);
        $target->departments()->attach($this->department->id);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => '新 名前', 'employee_number' => 'M001', 'email' => 'a@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ]);

        $log = ApprovalSettingLog::where('action', 'user.updated')->sole();
        $this->assertSame(['name' => '旧 名前'], $log->old_values);
        $this->assertSame(['name' => '新 名前'], $log->new_values);
    }
}
