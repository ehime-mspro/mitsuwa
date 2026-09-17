<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 部門の管理（設計書 §5.8）。決裁の管理者だけが入れる。
 */
class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private function approvalAdmin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    private function company(array $attributes = []): ApprovalCompany
    {
        return ApprovalCompany::create(array_merge(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1], $attributes));
    }

    private function department(ApprovalCompany $company, array $attributes = []): ApprovalDepartment
    {
        return ApprovalDepartment::create(array_merge(
            ['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1],
            $attributes
        ));
    }

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
    }

    /**
     * 画面が描画した削除フォームを、ブラウザと同じように送り返す（Bug #47）。
     *
     * ⚠ 値を手で組み立てて POST すると、`@method('DELETE')` が消えて本番では 405 で
     *   無反応になっても緑のまま通る。`@csrf` の欠落は Feature テストでは原理的に
     *   挙動から検出できないので、描画された `_token` の存在を見るのが唯一の手。
     */
    private function submitDeleteForm(User $admin, string $action): \Illuminate\Testing\TestResponse
    {
        $form = $this->parseForm($this->indexHtml($admin), 'action="' . $action . '"');

        $this->assertSame('DELETE', $form['method'], "削除フォームが DELETE で送られない: {$action}");
        $this->assertArrayHasKey('_token', $form['fields'], "削除フォームに @csrf が無い: {$action}");

        return $this->actingAs($admin)->post($form['action'], $form['fields']);
    }

    /** レイアウトの赤帯の要素の中に、ちょうど 1 回だけ出ていること（Bug #43 / #46） */
    private function assertErrorBanner(string $html, string $message): void
    {
        $banner = '<span class="text-sm text-red-800">' . e($message) . '</span>';

        $this->assertSame(1, substr_count($html, $banner), "赤帯に理由が出ていない: {$message}");
    }

    /** 指定されていない経営層は 403（設計書 §5.17） */
    public function test_an_executive_without_the_flag_is_rejected(): void
    {
        $executive = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);

        $this->actingAs($executive)->get(route('approvals.admin.organization.index'))->assertStatus(403);
    }

    /** 指定された決裁のみ利用者は入れる */
    public function test_an_approval_only_admin_can_enter(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        $this->actingAs($user->fresh())->get(route('approvals.admin.organization.index'))->assertOk();
    }

    public function test_a_plain_user_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]))
            ->get(route('approvals.admin.organization.index'))->assertStatus(403);
    }

    // --- 会社 ---

    public function test_a_company_can_be_created_from_the_rendered_form(): void
    {
        $admin = $this->approvalAdmin();

        $html = $this->actingAs($admin)->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('approvals.admin.organization.companies.store') . '"');

        // 追加のフォームは素の POST（`_method` を持たない）。追加と編集を 1 つのモーダルに
        // まとめると、隠れた `_method=PUT` が登録の送信に混ざって 405 になる
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い（Feature テストでは挙動から検出できない）');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'name' => 'DAD', 'fiscal_start_month' => '6', 'sort_order' => '2',
        ]))->assertRedirect(route('approvals.admin.organization.index'));

        $company = ApprovalCompany::where('name', 'DAD')->sole();
        $this->assertSame(6, $company->fiscal_start_month);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.created')->count());
    }

    public function test_a_duplicate_company_name_is_rejected_in_japanese(): void
    {
        $this->company();

        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.companies.store'), ['name' => 'ミツワ都市開発', 'fiscal_start_month' => '5', 'sort_order' => '1'])
            ->assertSessionHasErrors(['name' => 'この会社名は既に登録されています。']);
    }

    public function test_the_fiscal_month_must_be_between_1_and_12(): void
    {
        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.companies.store'), ['name' => 'X', 'fiscal_start_month' => '13', 'sort_order' => '1'])
            ->assertSessionHasErrors('fiscal_start_month');
    }

    /**
     * 更新（`{approvalCompany}` の暗黙のモデル結合と、自分自身を除いた重複の検査）。
     *
     * ⚠ 引数名がパラメータ名と一致していないとモデルが解決されず落ちる（工程表で踏んだ罠）。
     * ⚠ 会社名を変えずに保存できること＝`ignore()` が効いていること。
     */
    public function test_a_company_can_be_updated_without_colliding_with_itself(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())
            ->put(route('approvals.admin.organization.companies.update', $company), [
                'name' => 'ミツワ都市開発', 'fiscal_start_month' => '6', 'sort_order' => '3',
            ])->assertRedirect(route('approvals.admin.organization.index'));

        $company->refresh();
        $this->assertSame(6, $company->fiscal_start_month);
        $this->assertSame(3, $company->sort_order);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.updated')->count());
    }

    public function test_a_department_can_be_updated_without_colliding_with_itself(): void
    {
        $company = $this->company();
        $dept    = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $this->actingAs($this->approvalAdmin())
            ->put(route('approvals.admin.organization.departments.update', $dept), [
                'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産部', 'code' => 'RE', 'sort_order' => '2',
            ])->assertRedirect(route('approvals.admin.organization.index'));

        $dept->refresh();
        $this->assertSame('不動産部', $dept->short_name);
        $this->assertSame(2, $dept->sort_order);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.updated')->count());
    }

    /**
     * 編集モーダルの送信先は Alpine が組み立てるので、PHP からは**形しか見られない**
     * （実際に送られるかはブラウザでしか測れない）。ルートの土台がずれたら落ちるように
     * 組み立ての式そのものを固定する（Bug #28 / #47 と同じ「呼び出し側と受け側の対」）。
     */
    public function test_the_edit_forms_point_at_the_update_routes(): void
    {
        $company = $this->company();
        ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $html = $this->actingAs($this->approvalAdmin())
            ->get(route('approvals.admin.organization.index'))->assertOk()->getContent();

        $companyBase    = Str::beforeLast(route('approvals.admin.organization.companies.update', 1), '/1');
        $departmentBase = Str::beforeLast(route('approvals.admin.organization.departments.update', 1), '/1');

        $this->assertStringContainsString(':action="\'' . $companyBase . '/\' + editCompanyId"', $html);
        $this->assertStringContainsString(':action="\'' . $departmentBase . '/\' + editDepartmentId"', $html);

        // 編集の 2 フォームが PUT へ化けること（@method('PUT') が消えると 405 で無反応になる）
        $this->assertSame(2, substr_count($html, 'name="_method" value="PUT"'));
    }

    public function test_a_company_with_departments_cannot_be_deleted(): void
    {
        $company = $this->company();
        $this->department($company);

        $this->submitDeleteForm($this->approvalAdmin(), route('approvals.admin.organization.companies.destroy', $company))
            ->assertSessionHas('error');

        $this->assertSame(1, ApprovalCompany::count());
    }

    /**
     * 断る理由が画面に出ること。
     *
     * ⚠ 役割（セッション）と表示（赤帯）は**別々に**見る。片方だけ消えても緑になる（Bug #46）。
     * ⚠ このテストではセッションに触らない。`assertSessionHas*()` を 1 行挟むと
     *   フラッシュが消費され、そのあと描画した画面から帯が丸ごと消える（Bug #49）。
     */
    public function test_the_reason_a_company_cannot_be_deleted_is_shown_in_the_red_banner(): void
    {
        $admin   = $this->approvalAdmin();
        $company = $this->company();
        $this->department($company);

        $this->submitDeleteForm($admin, route('approvals.admin.organization.companies.destroy', $company))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertErrorBanner($this->indexHtml($admin), 'この会社には部門が 1 件あるため削除できません。先に部門を削除してください。');
    }

    public function test_an_empty_company_can_be_deleted(): void
    {
        $company = $this->company();

        $this->submitDeleteForm($this->approvalAdmin(), route('approvals.admin.organization.companies.destroy', $company))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalCompany::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.deleted')->count());
    }

    // --- 部門 ---

    public function test_a_department_can_be_created_from_the_rendered_form_and_the_code_is_normalized(): void
    {
        $company = $this->company();
        $admin   = $this->approvalAdmin();

        $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.organization.departments.store') . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い（Feature テストでは挙動から検出できない）');

        // ⚠ company_id は上書きしない。画面が描画した <option> から届いた値をそのまま送り返すので、
        //   選択肢のループが消えると（会社を選べず部門を 1 つも作れなくなる）ここが落ちる
        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'name' => '不動産部', 'short_name' => '不動産', 'code' => 'ｒｅ', 'sort_order' => '1',
        ]))->assertRedirect(route('approvals.admin.organization.index'));

        $department = ApprovalDepartment::sole();
        $this->assertSame('RE', $department->code);
        $this->assertSame($company->id, $department->company_id, '会社が画面の選択肢から届いていない');
    }

    public function test_a_duplicate_department_code_is_rejected_across_companies(): void
    {
        $a = $this->company();
        $b = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);
        ApprovalDepartment::create(['company_id' => $a->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.organization.departments.store'), [
            'company_id' => $b->id, 'name' => '土木部', 'short_name' => '土木', 'code' => 'RE', 'sort_order' => 1,
        ])->assertSessionHasErrors('code');
    }

    public function test_the_short_name_is_limited_to_six_characters(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.organization.departments.store'), [
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => 'あいうえおかき', 'code' => 'RE', 'sort_order' => 1,
        ])->assertSessionHasErrors('short_name');
    }

    public function test_a_department_with_members_cannot_be_deleted(): void
    {
        $company = $this->company();
        $dept    = $this->department($company);
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->submitDeleteForm($this->approvalAdmin(), route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('error');

        $this->assertSame(1, ApprovalDepartment::count());
    }

    /** ⚠ セッションに触らない（Bug #49）。役割は上のテストが見る */
    public function test_the_reason_a_department_cannot_be_deleted_is_shown_in_the_red_banner(): void
    {
        $admin   = $this->approvalAdmin();
        $company = $this->company();
        $dept    = $this->department($company);
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->submitDeleteForm($admin, route('approvals.admin.organization.departments.destroy', $dept))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertErrorBanner($this->indexHtml($admin), 'この部門には所属者が 1 人いるため削除できません。先に利用者の管理で所属部門を変えてください。');
    }

    public function test_an_empty_department_can_be_deleted(): void
    {
        $dept = $this->department($this->company());

        $this->submitDeleteForm($this->approvalAdmin(), route('approvals.admin.organization.departments.destroy', $dept))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalDepartment::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.deleted')->count());
    }

    /** 一覧に人数が出る */
    public function test_the_list_shows_the_member_count(): void
    {
        $dept = $this->department($this->company());
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))
            ->assertOk()->assertSee('1 人');
    }

    // --- 許可するドメイン ---

    public function test_a_domain_is_stored_without_the_at_sign(): void
    {
        $admin = $this->approvalAdmin();

        $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.organization.mailDomains.store') . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い（Feature テストでは挙動から検出できない）');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['domain' => ' @MITSUWAT.CO.JP ']))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame('mitsuwat.co.jp', ApprovalMailDomain::sole()->domain);
    }

    public function test_a_duplicate_domain_is_rejected(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.mailDomains.store'), ['domain' => 'mitsuwat.co.jp'])
            ->assertSessionHasErrors('domain');
    }

    public function test_a_malformed_domain_is_rejected(): void
    {
        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.mailDomains.store'), ['domain' => 'not a domain'])
            ->assertSessionHasErrors('domain');
    }

    /** 削除の確認に、影響する人数を出す（設計書 §5.8） */
    public function test_deleting_a_domain_shows_how_many_people_lose_notifications(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        User::factory()->create(['email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);
        User::factory()->create(['email' => 'c@example.com', 'must_change_password' => false]);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))
            ->assertOk()
            ->assertSee('このドメインのメールアドレスを持つ利用者: 2 人');
    }

    public function test_a_domain_can_be_deleted(): void
    {
        $domain = ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->submitDeleteForm($this->approvalAdmin(), route('approvals.admin.organization.mailDomains.destroy', $domain))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalMailDomain::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'mail_domain.deleted')->count());
    }

    // --- 権限と画面の作り ---

    /**
     * 権限の無い人には、その ID が実在するかを教えない（実在しない ID でも 403）。
     *
     * ⚠ ルートに付けた別名は既定では `SubstituteBindings`（ルートモデル結合）の**後ろ**に並ぶ。
     *   そのままだと実在しない ID だけ **404** が返り、権限の無い誰でも「その会社・部門・
     *   ドメインがあるか」を数えられる（実測）。`bootstrap/app.php` の `appendToPriorityList` で
     *   `EnsureApprovalAdmin` を前へ出して塞いでいる。`RestrictApprovalOnlyUsers` の
     *   docblock が名指ししているのと同じ性質。
     */
    public function test_a_missing_id_is_rejected_the_same_way_as_an_existing_one(): void
    {
        $stranger = User::factory()->create(['must_change_password' => false]);

        $company = $this->company();
        $dept    = $this->department($company);
        $domain  = ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $urls = [
            route('approvals.admin.organization.companies.destroy', $company),
            route('approvals.admin.organization.companies.destroy', 999999),
            route('approvals.admin.organization.departments.destroy', $dept),
            route('approvals.admin.organization.departments.destroy', 999999),
            route('approvals.admin.organization.mailDomains.destroy', $domain),
            route('approvals.admin.organization.mailDomains.destroy', 999999),
        ];

        $actual = [];
        foreach ($urls as $url) {
            $actual[$url] = $this->actingAs($stranger)->delete($url)->getStatusCode();
        }

        $this->assertSame(
            array_fill_keys($urls, 403),
            $actual,
            '実在しない ID だけ 404 が返ると、ID が実在するかを数えられる'
        );

        // 権限の無い要求で何も消えていないこと
        $this->assertSame([1, 1, 1], [ApprovalCompany::count(), ApprovalDepartment::count(), ApprovalMailDomain::count()]);
    }

    /**
     * 押せない理由は、ボタン自身ではなくホバーを受けられるラッパーの span に載せる（Bug #43）。
     *
     * ⚠ `disabled` な要素はマウスイベントを発火しないので、ボタン自身の `title` は
     *   どのブラウザでも出ない。HTML には出るのでテストも `view:cache` も素通りする。
     *   **ボタンが持たないこと**と**ラッパーが持つこと**を対で固定する。
     */
    public function test_the_reason_the_add_button_is_disabled_sits_on_the_wrapper(): void
    {
        $admin   = $this->approvalAdmin();
        $pattern = '/<span([^>]*)>\s*<button([^>]*)>部門を追加<\/button>/u';
        $bare    = '/(?<![\w:-])disabled(?![\w:-])/';

        // 会社が 0 件 ＝ 押せない
        $this->assertSame(1, preg_match($pattern, $this->indexHtml($admin), $m), 'span に包まれた「部門を追加」が見つからない');
        $this->assertStringContainsString('title="先に会社を登録してください。"', $m[1], 'ラッパーに押せない理由が無い');
        $this->assertSame(1, preg_match($bare, $m[2]), 'ボタンが disabled になっていない');
        $this->assertStringNotContainsString('title=', $m[2], 'disabled なボタン自身の title は表示されない（Bug #43）');

        // 会社があれば押せる ＝ 理由も出さない
        $this->company();
        $this->assertSame(1, preg_match($pattern, $this->indexHtml($admin), $m));
        $this->assertStringNotContainsString('title=', $m[1], '押せるのに理由が残っている');
        $this->assertSame(0, preg_match($bare, $m[2]), '会社があるのに押せない');
    }

    /**
     * モーダルの中身が長くても「保存する」に届くこと。
     *
     * ⚠ **構造しか見られない**（実際に届くかはブラウザでしか測れない。Bug #29 / #43）。
     *   覆いは `fixed inset-0` ＋ 中央寄せでそれ自体はスクロールしないので、panel 側に
     *   高さの上限と `overflow-y-auto` が無いと、画面が低いとき（横向きのスマホ・
     *   1024×600・拡大表示）下端のボタンに到達できない。
     */
    public function test_every_modal_panel_can_scroll_on_a_short_screen(): void
    {
        $company = $this->company();
        $this->department($company);

        $html  = $this->indexHtml($this->approvalAdmin());
        $found = preg_match_all('/<div @click\.outside="[^"]*" class="([^"]*)"/', $html, $m);

        $this->assertSame(4, $found, 'モーダルの panel が 4 つ見つからない');

        foreach ($m[1] as $class) {
            $this->assertStringContainsString('max-h-[90vh]', $class);
            $this->assertStringContainsString('overflow-y-auto', $class);
        }
    }

    /**
     * 成功・失敗の帯はレイアウトが出す（ビューでも出すと画面に 2 回出る）。
     *
     * ⚠ **成功と失敗を両方数える。** 片方だけ見ると、もう片方の帯がビューへ戻っても緑のまま通る
     *   （Bug #43 / #46 / #49 の「役割ごとに分けてアサートする」の鏡像。実測で `error` 側が
     *   無防備だった）。⚠ **文言そのものを数える**（`assertErrorBanner` はレイアウトの markup を
     *   数えるので、別の markup で戻された重複は見えない）。
     * ⚠ セッションに触らない（`assertSessionHas*()` を挟むと次の描画から帯が消える。Bug #49）。
     */
    public function test_the_flash_banner_is_not_rendered_twice(): void
    {
        $admin = $this->approvalAdmin();

        // 成功（空の会社は消せる）
        $this->submitDeleteForm($admin, route('approvals.admin.organization.companies.destroy', $this->company()))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(
            1,
            substr_count($this->indexHtml($admin), e('会社を削除しました。')),
            '成功の文言が 2 回出ている'
        );

        // 失敗（部門を持つ会社は断られる）
        $blocked = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $this->department($blocked);

        $this->submitDeleteForm($admin, route('approvals.admin.organization.companies.destroy', $blocked))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(
            1,
            substr_count($this->indexHtml($admin), e('この会社には部門が 1 件あるため削除できません。先に部門を削除してください。')),
            '失敗の文言が 2 回出ている'
        );
    }
}
