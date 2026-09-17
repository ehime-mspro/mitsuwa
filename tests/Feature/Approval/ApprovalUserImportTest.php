<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 社員の CSV 一括登録（設計書 §5.10）。
 *
 * ⚠ **プレビュー → 描画された「取り込む」フォームをそのまま確定**の往復で測る（Bug #47・#54 ②）。
 *   手で組んだ POST は、hidden の名前や `action` が壊れても緑のまま通る。
 * ⚠ エラーと注意は**役割（`viewData`）と表示を別々に**見る（Bug #54 ④）。
 * ⚠ 確定の時にサーバーが検査をやり直す（書き換えた hidden を信用しない）。
 */
class ApprovalUserImportTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private const HEADER = "社員番号,氏名,メールアドレス,所属部門\n";

    protected function setUp(): void
    {
        parent::setUp();

        $company = ApprovalCompany::create(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1]);
        ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
        ApprovalDepartment::create(['company_id' => $company->id, 'name' => '営業部', 'short_name' => '営業', 'code' => 'SA', 'sort_order' => 2]);
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['name' => '決裁 管理者', 'must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    private function preview(string $csv): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('approvals.admin.users.import.preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('users.csv', "\xEF\xBB\xBF" . self::HEADER . $csv),
        ]);
    }

    /** プレビューが描いた確定フォームを、ブラウザと同じように送り返す */
    private function confirm(string $csv): TestResponse
    {
        $preview = $this->preview($csv)->assertOk();
        $form    = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.users.import.execute') . '"');

        $this->assertArrayHasKey('csv_data', $form['fields'], '確定フォームに csv_data が無い');
        $this->assertArrayHasKey('guide_token', $form['fields'], '確定フォームに 1 回限りの鍵が無い');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');

        return $this->actingAs($this->admin())->post($form['action'], $form['fields']);
    }

    // --- 入口 ---

    public function test_only_an_approval_admin_can_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]))
            ->get(route('approvals.admin.users.import'))->assertStatus(403);

        $this->actingAs($this->admin())->get(route('approvals.admin.users.import'))->assertOk();
    }

    public function test_the_template_can_be_downloaded(): void
    {
        $response = $this->actingAs($this->admin())->get(route('approvals.admin.users.import.template'))->assertOk();

        // ⚠ `streamedContent()` は「streamed でない」ときに **falsy を返すのではなく落ちる**
        //    （`TestResponse::streamedContent()` の中で assert する）ので `?:` の左に置けない。
        //    `CsvImportTemplate::response()` は `response($csv, ...)` ＝ streamed ではない
        //    （`ImportPreviewRenderTest` も同じ理由で `getContent()` を使っている）。
        $body = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'BOM が無い（Excel で文字化けする）');
        $this->assertStringContainsString('社員番号', $body);
        $this->assertStringContainsString('所属部門', $body);
    }

    // --- 見出し ---

    public function test_a_missing_header_rejects_the_whole_file(): void
    {
        $this->actingAs($this->admin())->post(route('approvals.admin.users.import.preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('x.csv', "\xEF\xBB\xBF社員番号,氏名\nM001,甲\n"),
        ])->assertRedirect()->assertSessionHas('error');
    }

    /** 列の順番は問わない */
    public function test_the_column_order_does_not_matter(): void
    {
        $this->actingAs($this->admin())->post(route('approvals.admin.users.import.preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('x.csv', "\xEF\xBB\xBF所属部門,氏名,社員番号,メールアドレス\nRE,甲 一郎,A0001,a@mitsuwat.co.jp\n"),
        ])->assertOk()->assertViewHas('validCount', 1);
    }

    // --- 新規 ---

    public function test_a_new_user_is_created_as_an_approval_only_user(): void
    {
        $response = $this->confirm("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $user = User::where('employee_number', 'A0001')->sole();
        $this->assertSame(UserRole::ApprovalOnly, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(['RE'], $user->approvalDepartments->pluck('code')->all());

        // 結果はログイン案内の画面
        $this->assertStringContainsString('ログインのご案内', $response->getContent());
        $this->assertStringContainsString('甲 一郎', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** 所属部門の区切りは何でもよい（設計書 §5.10） */
    public static function separatorCases(): array
    {
        return [['RE,SA'], ['RE/SA'], ['RE SA'], ['RE、SA'], ['RE・SA'], ['RE　SA']];
    }

    #[DataProvider('separatorCases')]
    public function test_departments_can_be_separated_in_many_ways(string $value): void
    {
        // ⚠ CSV のカンマと衝突するので、値は引用符で囲む
        $this->confirm('A0001,甲 一郎,a@mitsuwat.co.jp,"' . $value . "\"\n")->assertOk();

        $this->assertEqualsCanonicalizing(
            ['RE', 'SA'],
            User::where('employee_number', 'A0001')->sole()->approvalDepartments->pluck('code')->all()
        );
    }

    public function test_the_email_is_optional(): void
    {
        $this->confirm("A0001,甲 一郎,,RE\n")->assertOk();

        $this->assertNull(User::where('employee_number', 'A0001')->sole()->email);
    }

    // --- 行の検査 ---

    public function test_an_email_outside_the_allowed_domains_is_an_error(): void
    {
        $preview = $this->preview("A0001,甲 一郎,a@gmail.com,RE\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertNotEmpty($preview->viewData('rowErrors'));
        $this->assertStringContainsString('許可されていないドメイン', $preview->getContent());
    }

    public function test_emails_are_rejected_when_no_domain_is_registered(): void
    {
        ApprovalMailDomain::query()->delete();

        $preview = $this->preview("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertStringContainsString('許可するメールのドメインが登録されていません', $preview->getContent());
    }

    public function test_an_unknown_department_is_an_error(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,XX\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertStringContainsString('XX', $preview->getContent());
    }

    public function test_a_row_without_a_department_is_an_error(): void
    {
        $this->assertSame(0, $this->preview("A0001,甲 一郎,,\n")->assertOk()->viewData('validCount'));
    }

    public function test_a_malformed_employee_number_is_an_error(): void
    {
        $this->assertSame(0, $this->preview("A@001,甲 一郎,,RE\n")->assertOk()->viewData('validCount'));
    }

    /** 同じファイルの中で重なる行は、どちらもエラー（設計書 §5.10） */
    public function test_duplicates_within_the_file_fail_both_rows(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\nA0001,乙 二郎,,SA\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'), '重複した 2 行のどちらかが取り込まれる');
        $this->assertCount(2, $preview->viewData('rowErrors'));
    }

    // --- 既存の利用者との照合 ---

    public function test_matching_an_existing_user_updates_only_the_number_and_departments(): void
    {
        $existing = User::factory()->create([
            'name' => '登録済み 太郎', 'employee_number' => 'A0001', 'email' => 'a@mitsuwat.co.jp',
            'role' => UserRole::Staff->value, 'must_change_password' => false,
        ]);

        $this->confirm("A0001,CSV の氏名,a@mitsuwat.co.jp,RE\n")->assertOk();

        $existing->refresh();
        $this->assertSame('登録済み 太郎', $existing->name, '氏名が書き換わっている');
        $this->assertSame(UserRole::Staff, $existing->role, '基幹のロールが変わっている');
        $this->assertSame(['RE'], $existing->approvalDepartments->pluck('code')->all());
    }

    public function test_a_name_mismatch_is_a_warning_not_an_error(): void
    {
        User::factory()->create(['employee_number' => 'A0001', 'email' => null, 'name' => '登録済み 太郎', 'must_change_password' => false]);

        $preview = $this->preview("A0001,CSV の氏名,,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertNotEmpty($preview->viewData('warnings'));
        $this->assertStringContainsString('氏名が登録と違います', $preview->getContent());
    }

    /** 社員番号が変わるときは予告する */
    public function test_a_changed_login_id_is_announced(): void
    {
        User::factory()->create(['employee_number' => 'OLD1', 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $preview = $this->preview("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertStringContainsString('ログインIDが OLD1 から A0001 に変わります', $preview->getContent());
    }

    /** 社員番号とメールアドレスが別人に当たる行はエラー */
    public function test_a_row_matching_two_different_people_is_an_error(): void
    {
        User::factory()->create(['name' => '甲', 'employee_number' => 'A0001', 'email' => null, 'must_change_password' => false]);
        User::factory()->create(['name' => '乙', 'employee_number' => 'A0002', 'email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);

        $preview = $this->preview("A0001,丙,b@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertStringContainsString('社員番号は 甲 さん、メールアドレスは 乙 さんと一致します', $preview->getContent());
    }

    /** 削除済みの利用者に当たる行はエラー（一意索引に当たって取込全体が巻き戻るのを防ぐ。Bug #60） */
    public function test_a_row_matching_a_deleted_user_is_an_error(): void
    {
        $deleted = User::factory()->create(['name' => '退職 太郎', 'employee_number' => 'A0001', 'email' => null, 'must_change_password' => false]);
        $deleted->delete();

        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertStringContainsString('削除済みの利用者（退職 太郎さん）と一致します', $preview->getContent());
        $this->assertStringContainsString('基幹の管理者に復元を依頼してください', $preview->getContent());
    }

    public function test_an_inactive_match_is_a_warning(): void
    {
        User::factory()->create(['employee_number' => 'A0001', 'email' => null, 'status' => UserStatus::Inactive->value, 'must_change_password' => false]);

        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertStringContainsString('無効の利用者です', $preview->getContent());
    }

    // --- 件数と上限 ---

    public function test_the_preview_shows_the_counts(): void
    {
        User::factory()->create(['employee_number' => 'A0002', 'email' => null, 'must_change_password' => false]);

        $preview = $this->preview("A0001,甲,,RE\nA0002,乙,,SA\nA@003,丙,,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('createCount'));
        $this->assertSame(1, $preview->viewData('updateCount'));
        $this->assertCount(1, $preview->viewData('rowErrors'));
    }

    public function test_the_file_is_capped(): void
    {
        config(['approval.csv_max_rows' => 2]);

        $csv = '';
        for ($i = 1; $i <= 3; $i++) {
            $csv .= sprintf("A%04d,甲 %d,,RE\n", $i, $i);
        }

        $this->preview($csv)->assertRedirect()->assertSessionHas('error');
    }

    /** 全行エラーなら取込の入口を描かない（画面が送らない POST を作らない。Bug #54 ③） */
    public function test_a_file_with_only_errors_offers_no_import(): void
    {
        $html = $this->preview("A@001,甲,,RE\n")->assertOk()->getContent();

        $this->assertStringNotContainsString('name="csv_data"', $html);
    }

    // --- 確定 ---

    public function test_the_server_revalidates_on_confirm(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();
        $form    = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.users.import.execute') . '"');

        // hidden を書き換えて送る（許可していないドメインのメールに差し替える）
        $tampered = $form['fields'];
        $tampered['csv_data'] = base64_encode(self::HEADER . "A0001,甲 一郎,evil@gmail.com,RE\n");

        $this->actingAs($this->admin())->post($form['action'], $tampered)
            ->assertRedirect(route('approvals.admin.users.import'))
            ->assertSessionHas('error');

        $this->assertSame(0, User::where('employee_number', 'A0001')->count());
    }

    public function test_the_same_token_cannot_confirm_twice(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();
        $form    = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.users.import.execute') . '"');

        $this->actingAs($this->admin())->post($form['action'], $form['fields'])->assertOk();
        $password = User::where('employee_number', 'A0001')->sole()->password;

        $this->actingAs($this->admin())->post($form['action'], $form['fields'])
            ->assertRedirect(route('approvals.admin.users.import'))->assertSessionHas('error');

        $this->assertSame($password, User::where('employee_number', 'A0001')->sole()->password);
    }

    /**
     * 1 回限りの鍵を配列で送られても 500 にしない。
     *
     * ⚠ `(string) ['a']` は `Array to string conversion` の ErrorException になり **500** で落ちる
     *   （実測。`Approval\UserController::claimGuideToken()` が同じ理由で `is_string` を挟んでいる）。
     */
    public function test_an_array_guide_token_is_refused_without_a_500(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();
        $form    = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.users.import.execute') . '"');

        $tampered = $form['fields'];
        $tampered['guide_token'] = ['a', 'b'];

        $this->actingAs($this->admin())->post($form['action'], $tampered)
            ->assertRedirect(route('approvals.admin.users.import'))
            ->assertSessionHas('error');

        $this->assertSame(0, User::where('employee_number', 'A0001')->count());
    }

    /** 新規登録では通知メールを送らない（設計書 §5.10） */
    public function test_no_mail_is_sent_for_new_users(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->confirm("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }

    /** 1 人 1 行を記録する */
    public function test_each_row_is_recorded(): void
    {
        User::factory()->create(['employee_number' => 'A0002', 'email' => null, 'must_change_password' => false]);

        $this->confirm("A0001,甲,,RE\nA0002,乙,,SA\n")->assertOk();

        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.imported_created')->count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'user.imported_updated')->count());
    }

    /**
     * 数字だけの社員番号で桁が揃っていない行に注意を出す。
     *
     * ⚠ 利用者の社員番号は「数字だけ・先頭に 0 あり」と「英字と数字」が混在する（2026-09-16 確認）。
     *   Excel が先頭の 0 を落とした CSV を、人の目でも見つけられるようにする。
     */
    public function test_a_shorter_numeric_number_is_warned_about(): void
    {
        $preview = $this->preview("00123,甲,,RE\n00124,乙,,RE\n125,丙,,RE\n")->assertOk();

        $this->assertSame(3, $preview->viewData('validCount'), '注意であってエラーではない');
        $this->assertStringContainsString('ほかの行より桁が少ないです', $preview->getContent());
        $this->assertStringContainsString('先頭の 0 が落ちていませんか', $preview->getContent());
    }
}
