<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Approval\UserImportController;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
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

    /**
     * 取込の **4 ルートすべて**が決裁の管理者の門番の中にあること。
     *
     * ⚠ 上のテストは `GET users/import` しか見ていないので、`routes/approval.php` の 4 行の
     *   どれかが `approval.admin` のグループの**外へ出た事故が無音**になる。とりわけ
     *   `execute` は**利用者を作る**経路なので、漏れると誰でも社員を一括登録できる。
     * ⚠ 列挙は**コントローラのクラス名**で機械的に行う（新しいルートが増えたら自動で
     *   検査対象に入る＝全件分類。Bug #45 ①）。件数の下限ではなく**ちょうど 4 本**で見るのは、
     *   ルートを 1 本消す変異も同時に捕まえるため。
     * ⚠ 列挙を**データプロバイダに置いてはいけない** — プロバイダは Laravel 起動前に
     *   評価されるので `Route::getRoutes()` が `A facade root has not been set.` で落ちる
     *   （`ImportPreviewRenderTest` で実測済み）。
     *
     * ⚠ **`approvals.admin.*` 全ルートの門番は `ApprovalAdminGateTest` が見る**（2026-09-18〜。
     *   全ルート × 権限の無い 6 人 × ID の有無）。このテストはそれとは役割が違い、
     *   「取込のルートがちょうど 4 本であること」自体を守る。⚠ **増えたこと**（5 本目が紛れ込む
     *   変異）**を検出するのはこのテストだけ**（`assertCount(4, ...)` が働く）。減ったこと
     *   （4 本のどれかが消える変異）は `ApprovalAdminGateTest` の下限のアサートでも、
     *   このファイル自身に散らばる残り 17 箇所の `route('approvals.admin.users.import…')`
     *   呼び出し（ルートが無ければ即座に例外）でも拾える。
     */
    public function test_every_import_route_is_behind_the_approval_admin_gate(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), UserImportController::class . '@')) {
                continue;
            }

            $method = in_array('GET', $route->methods(), true) ? 'GET' : 'POST';
            $routes[$method . ' /' . $route->uri()] = [$method, '/' . $route->uri()];
        }

        $this->assertCount(4, $routes, '取込のルートの列挙が痩せている（form / template / preview / execute の 4 本）');

        // 決裁の管理者に指定されていない人（基幹の経営層でも通さない）
        $outsider = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $results  = [];

        foreach ($routes as $label => [$method, $uri]) {
            $results[$label] = $this->actingAs($outsider)->call($method, $uri)->getStatusCode();
        }

        $this->assertSame(
            array_fill_keys(array_keys($results), 403),
            $results,
            '決裁の管理者でない人が通れる取込のルートがある'
        );
    }

    /**
     * 利用者の管理から取込画面へ行ける（Bug #47 — 入口と受け側を対で固定する）。
     *
     * ⚠ これが無いと、URL を直打ちする人にしか届かない機能になる（実測でアプリ内の
     *   リンクは 0 件だった）。⚠ リンクは**アプリ内で 1 つだけ**にしてある。同じ URL を
     *   指す要素が 2 つあると、片方を消しても このアサートが素通りする（Bug #43 / #47）。
     */
    public function test_the_user_list_links_to_the_import_screen(): void
    {
        $html = $this->actingAs($this->admin())->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('approvals.admin.users.import') . '"', $html);
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

    /**
     * 社員番号はログイン ID なので必須（設計書 §5.10）。
     *
     * ⚠ **件数では測れない。文言で見るしかない。** この分岐を消しても行は
     *   次の書式の判定に落ちるので `validCount` は 0・`rowErrors` は 1 件のまま変わらず、
     *   画面の文言だけが「社員番号「」は英数字とハイフン 20 文字までで入力してください」
     *   という**意味の通らないもの**に化ける（2026-09-17 に変異で実測）。
     */
    public function test_a_row_without_an_employee_number_is_an_error(): void
    {
        $preview = $this->preview(",甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('rowErrors'));
        $this->assertStringContainsString('エラー 行2: 社員番号が空です', $preview->getContent());
    }

    /**
     * 氏名は必須で 100 文字まで（`users.name` は `varchar(100)`）。
     *
     * ⚠ **この分岐が唯一の歯止め。** テストの SQLite は長さを切り詰めも拒否もしないので
     *   （Bug #40 と同型）、消しても DB は守ってくれず、**本番 MySQL でだけ**
     *   `Data too long` で取込全体が巻き戻る。
     * ⚠ **上限ちょうど（100 文字）が通ることも対で見る** — 「常にエラー」に潰す変異と
     *   区別できない形にしないため。
     */
    public function test_a_blank_or_too_long_name_is_an_error(): void
    {
        $tooLong = str_repeat('あ', 101);
        $atLimit = str_repeat('い', 100);

        $preview = $this->preview("A0001,,,RE\nA0002,{$tooLong},,RE\nA0003,{$atLimit},,SA\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'), '100 文字ちょうどの氏名が取り込めない');
        $this->assertSame([2, 3], array_column($preview->viewData('rowErrors'), 'row'));

        $html = $preview->getContent();
        $this->assertStringContainsString('エラー 行2: 氏名が空、または 100 文字を超えています', $html);
        $this->assertStringContainsString('エラー 行3: 氏名が空、または 100 文字を超えています', $html);
    }

    /**
     * メールアドレスの形式が壊れている行はエラー。
     *
     * ⚠ 値は**許可したドメインのまま**にする（`a..b@mitsuwat.co.jp` は `filter_var` が
     *   落とすが `ApprovalMailDomain::allows()` は通す）。ドメイン違いの値で書くと、
     *   形式の判定を消しても**ドメインの判定が代わりに落として緑のまま通る**
     *   （Bug #48 の「安全網が主機構の変異を隠す」型）。実測（2026-09-17）: この値なら
     *   判定を消すと `validCount` が 0 → **1** になる ＝ ドメインの判定は肩代わりしない。
     */
    public function test_a_malformed_email_is_an_error(): void
    {
        $preview = $this->preview("A0001,甲 一郎,a..b@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('rowErrors'));
        $this->assertStringContainsString(
            'エラー 行2: メールアドレス「a..b@mitsuwat.co.jp」の形式が正しくありません',
            $preview->getContent()
        );
    }

    /** 同じファイルの中で重なる行は、どちらもエラー（設計書 §5.10） */
    public function test_duplicates_within_the_file_fail_both_rows(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\nA0001,乙 二郎,,SA\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'), '重複した 2 行のどちらかが取り込まれる');
        $this->assertCount(2, $preview->viewData('rowErrors'));
    }

    /**
     * 同じメールアドレスが 2 行にあるファイルは、どちらもエラー（設計書 §5.10）。
     *
     * ⚠ **`$seenEmails` の判定はこの形の唯一の歯止め。** 消すと `$byEmail` はどちらの行でも
     *   null（＝新規）なので両方 valid になり、`apply()` の 2 件目の INSERT が
     *   `users.email` の UNIQUE 索引に当たって `DB::transaction` が巻き戻る
     *   ＝ **1 行のコピペミスで 50 行の取込が丸ごと消える**（Bug #54 / #60 の型）。
     * ⚠ **社員番号はわざと別にする** — 同じにすると社員番号側の重複判定が先に当たり、
     *   メール側を消しても緑のまま通る（Bug #48 の型）。
     * ⚠ 「確定が落ちること」ではなく「取り込む行が 0 で、両方がエラーに出ること」を見る
     *   （画面が決して送らない POST を作らない。Bug #54 ③）。
     */
    public function test_the_same_email_twice_in_the_file_fails_both_rows(): void
    {
        $preview = $this->preview("A0001,甲,dup@mitsuwat.co.jp,RE\nA0002,乙,dup@mitsuwat.co.jp,SA\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'), '重複したメールアドレスの行が取り込まれる');
        $this->assertSame(0, $preview->viewData('createCount'));
        $this->assertCount(2, $preview->viewData('rowErrors'));
        $this->assertSame([2, 3], array_column($preview->viewData('rowErrors'), 'row'));

        $html = $preview->getContent();
        $this->assertStringContainsString('エラー 行2: メールアドレス「dup@mitsuwat.co.jp」が同じファイルの中で重複しています', $html);
        $this->assertStringContainsString('エラー 行3: メールアドレス「dup@mitsuwat.co.jp」が同じファイルの中で重複しています', $html);
    }

    /** ⚠ 対で固定する — メールアドレスは任意なので、空欄は何行あっても重複ではない */
    public function test_blank_emails_are_not_treated_as_duplicates(): void
    {
        $preview = $this->preview("A0001,甲,,RE\nA0002,乙,,SA\n")->assertOk();

        $this->assertSame(2, $preview->viewData('validCount'));
        $this->assertSame([], $preview->viewData('rowErrors'));
    }

    /**
     * 別のキーで**同じ既存利用者**に当たる 2 行は、どちらもエラー（1 行 1 人）。
     *
     * ⚠ 社員番号どうし・メールアドレスどうしの重複しか見ていないと、この形は両方 valid に
     *   なって `apply()` が順に適用し、**後の行が前の行を無音で上書きする**（更新の件数も
     *   実人数と食い違う）。⚠ **一意の索引には当たらない**ので DB も止めてくれない
     *   ＝「確定が落ちること」ではなく「取り込む行が 0 で、両方がエラーに出ること」を見る。
     */
    public function test_two_rows_matching_the_same_existing_user_both_fail(): void
    {
        User::factory()->create([
            'name' => '登録済み 太郎', 'employee_number' => 'A0001', 'email' => 'x@mitsuwat.co.jp',
            'must_change_password' => false,
        ]);

        $preview = $this->preview("A0001,甲,,RE\nA0002,乙,x@mitsuwat.co.jp,SA\n")->assertOk();

        $this->assertSame(0, $preview->viewData('validCount'), 'どちらか一方が取り込まれる（後の行が前の行を上書きする）');
        $this->assertSame(0, $preview->viewData('updateCount'));
        $this->assertCount(2, $preview->viewData('rowErrors'));
        $this->assertSame([2, 3], array_column($preview->viewData('rowErrors'), 'row'));

        $html = $preview->getContent();
        $this->assertStringContainsString('エラー 行2: ほかの行と同じ利用者（登録済み 太郎さん）に一致します', $html);
        $this->assertStringContainsString('エラー 行3: ほかの行と同じ利用者（登録済み 太郎さん）に一致します', $html);
    }

    // --- 既存の利用者との照合 ---

    public function test_matching_an_existing_user_updates_only_the_number_and_departments(): void
    {
        $existing = User::factory()->create([
            'name' => '登録済み 太郎', 'employee_number' => 'A0001', 'email' => 'a@mitsuwat.co.jp',
            'role' => UserRole::Staff->value, 'must_change_password' => false,
        ]);

        // ⚠ 新規が 0 人なので案内は返らず取込画面へ戻る（下の 2 本が役割を分けて固定している）
        $this->confirm("A0001,CSV の氏名,a@mitsuwat.co.jp,RE\n")->assertRedirect(route('approvals.admin.users.import'));

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

    /**
     * 社員番号が変わるときは予告する。
     *
     * ⚠ **役割（`viewData`）と表示を別々に見る。** ビューはエラーも注意も同じ `['message']` を
     *   出すので、`assertStringContainsString` だけだと `$warnings[] =` を `$rowErrors[] =` に
     *   変える変異が緑のまま通る（この push には `continue` が無いので件数も動かない。Bug #54 ④）。
     */
    public function test_a_changed_login_id_is_announced(): void
    {
        User::factory()->create(['employee_number' => 'OLD1', 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $preview = $this->preview("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertNotEmpty($preview->viewData('warnings'), '注意ではなくエラーに積まれている');
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertStringContainsString('ログインIDが OLD1 から A0001 に変わります', $preview->getContent());
    }

    /**
     * 社員番号がまだ無い人（メールアドレスでログインしていた人）に当たったときの予告。
     *
     * ⚠ 「X から Y に変わります」の形を使うと `{$existing->employee_number}` が null で
     *   **「ログインIDが  から A0001 に変わります」**（空白）になる。
     */
    public function test_setting_a_first_login_id_is_announced_without_a_blank(): void
    {
        User::factory()->create(['employee_number' => null, 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $preview = $this->preview("A0001,甲 一郎,a@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertNotEmpty($preview->viewData('warnings'));
        $this->assertSame([], $preview->viewData('rowErrors'));

        $html = $preview->getContent();
        $this->assertStringContainsString('ログインIDに社員番号 A0001 を設定します', $html);
        $this->assertStringNotContainsString('ログインIDが  から', $html);
    }

    /**
     * CSV に別のメールアドレスを書いても**書き換えない**ことを注意で伝える（設計書 §5.10）。
     *
     * ⚠ **管理者が入力した値が黙って捨てられることを伝える唯一の告知。** 無いと、
     *   CSV で連絡先を直したつもりの管理者が「変わった」と思い込む。
     * ⚠ 役割（`viewData`）と表示を別々に見る（Bug #54 ④）。
     * ⚠ **告知と実際の振る舞いを対で固定する** — 確定しても旧アドレスのままであることまで見る
     *   （告知だけ残して実は書き換える、の逆も止まる）。
     */
    public function test_a_different_email_is_announced_as_not_changed(): void
    {
        $existing = User::factory()->create([
            'name' => '甲 一郎', 'employee_number' => 'A0001', 'email' => 'old@mitsuwat.co.jp',
            'must_change_password' => false,
        ]);

        // ⚠ 氏名は登録と揃える（揃えないと氏名の不一致の注意が混ざり、件数で役割を見られない）
        $preview = $this->preview("A0001,甲 一郎,new@mitsuwat.co.jp,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('warnings'), '注意ではなくエラーに積まれている');
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertStringContainsString(
            '⚠ 行2: メールアドレスは変えません（変更は基幹の管理者に依頼してください）',
            $preview->getContent()
        );

        // 告知どおり、確定してもメールアドレスは変わらない
        $this->confirm("A0001,甲 一郎,new@mitsuwat.co.jp,RE\n")->assertRedirect(route('approvals.admin.users.import'));
        $this->assertSame('old@mitsuwat.co.jp', $existing->fresh()->email);
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

    /** ⚠ 役割と表示を別々に見る（同上。Bug #54 ④）*/
    public function test_an_inactive_match_is_a_warning(): void
    {
        User::factory()->create(['employee_number' => 'A0001', 'email' => null, 'status' => UserStatus::Inactive->value, 'must_change_password' => false]);

        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertNotEmpty($preview->viewData('warnings'), '注意ではなくエラーに積まれている');
        $this->assertSame([], $preview->viewData('rowErrors'));
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

    /**
     * 取り込む行の表は横スクロールする（`min-w-[720px]`）ので、共通のヒント文を持つ。
     *
     * ⚠ `resources/js/app.js` の共通ヒントは `.scroll-hint` / `.scroll-hint-inner` /
     *   `.scroll-hint-text` の 3 つが揃って初めて成立する。`-text` が無いと
     *   「スクロールできます」が永久に出ない（Bug #56 と同じ「HTML は妥当なのに実行時に無音」型）。
     */
    public function test_the_preview_table_carries_the_scroll_hint_text(): void
    {
        $html = $this->preview("A0001,甲 一郎,,RE\n")->assertOk()->getContent();

        $this->assertStringContainsString('<div class="scroll-hint-text">← スクロールできます →</div>', $html);
    }

    // --- 確定 ---

    /**
     * 新規が 1 人もいないファイルはログイン案内を返さず、取込画面へ戻して成功を知らせる。
     *
     * ⚠ 案内を返すと「0 人分」＋「この画面を閉じると初期パスワードは二度と表示されません」
     *   だけが出る。印刷するパスワードが 1 つも無いので誤解を招く。
     * ⚠ 成功の帯はレイアウトが出す（画面側で二重に出さない）ので、ここではフラッシュを見る。
     */
    public function test_an_update_only_file_returns_to_the_form_instead_of_the_guide(): void
    {
        $existing = User::factory()->create(['employee_number' => 'A0001', 'email' => null, 'must_change_password' => false]);

        $this->confirm("A0001,甲 一郎,,RE\n")
            ->assertRedirect(route('approvals.admin.users.import'))
            ->assertSessionHas('success', '1 件の社員番号と所属部門を更新しました。新しく登録した人がいないため、ログイン案内はありません。');

        // 取り込み自体は済んでいる
        $this->assertSame(['RE'], $existing->fresh()->approvalDepartments->pluck('code')->all());
    }

    /**
     * ⚠ 対で固定する — 新規が 1 人でもいれば従来どおり案内を返す（更新と混ざっていても）。
     *
     * ⚠ **更新した人が案内に混ざっていないことも見る。** `apply()` の `continue` の位置が
     *   ずれて更新行が `$entries` に入ると、**パスワードを一度も変えていない人の
     *   「初期パスワード」を印刷して手渡す**ことになる（本人はその紙ではログインできず、
     *   管理者はどの紙が嘘なのか分からない）。
     * ⚠ 見るのは **DB 側の氏名**（`登録済み 太郎`）であって CSV の氏名（`甲`）ではない ——
     *   更新では氏名を書き換えないので、案内に漏れたときに出るのは登録側の氏名。
     * ⚠ 人数（`N 人分`）も**別のアサートで**見る（氏名は将来の文言変更で当たらなくなりうるが、
     *   件数は `count($entries)` を直接写す）。役割ごとに分ける（Bug #43 / #46 / #49）。
     */
    public function test_a_file_with_at_least_one_new_user_still_returns_the_guide(): void
    {
        User::factory()->create([
            'name' => '登録済み 太郎', 'employee_number' => 'A0001', 'email' => null,
            'must_change_password' => false,
        ]);

        $response = $this->confirm("A0001,甲,,RE\nA0002,乙 二郎,,SA\n")->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('ログインのご案内', $html);
        $this->assertStringContainsString('乙 二郎 様', $html);

        $this->assertStringNotContainsString(
            '登録済み 太郎',
            $html,
            '更新しただけの人が案内に出ている（嘘の初期パスワードを印刷して手渡すことになる）'
        );
        $this->assertStringContainsString('>1 人分</span>', $html, '案内の人数が新規の人数（1 人）と合っていない');
    }

    /**
     * ⚠ Task 15 の変異表の M41（`execute()` の
     *   `if ($analysis['rowErrors'] !== []) { return ...->with('error', '取り込めない行が…'); }` を
     *   消す変異）が塞ぐ穴（その1・文言）—— この行 1 本だけの改ざんでは `rowErrors` が 1 件・
     *   `rows`（＝取り込む行）が 0 件になるため、直後の「到達しないはずの」歯止め
     *   `if ($analysis['validCount'] === 0) { return ...->with('error', '取り込む行がありません。'); }`
     *   が代わりに発火し、**別の文言で**同じ「`error` セッションへリダイレクト」が返る。
     *   旧テストは `assertSessionHas('error')`（値を見ない）だけだったので、文言が入れ替わっても
     *   検出できなかった。ここで**正確な文言**を固定し、この置き換わりを検出できるようにする。
     * ⚠ **ただしこの検出は文言の違いに頼っている** —— 2 つの歯止めの文言をたまたま同じにする
     *   ような編集が入れば、M41 自体は直っていないのにこのテストは緑に戻ってしまう。文言に
     *   依存しない検出は下の `test_the_server_refuses_a_mixed_file_and_imports_neither_row` が担う。
     */
    public function test_the_server_revalidates_on_confirm(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();
        $form    = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.users.import.execute') . '"');

        // hidden を書き換えて送る（許可していないドメインのメールに差し替える）
        $tampered = $form['fields'];
        $tampered['csv_data'] = base64_encode(self::HEADER . "A0001,甲 一郎,evil@gmail.com,RE\n");

        $this->actingAs($this->admin())->post($form['action'], $tampered)
            ->assertRedirect(route('approvals.admin.users.import'))
            ->assertSessionHas('error', '取り込めない行があります。もう一度アップロードして内容を確認してください。');

        $this->assertSame(0, User::where('employee_number', 'A0001')->count());
    }

    /**
     * ⚠ Task 15 の変異表の M41（同上）が塞ぐ穴（その2・文言に依存しない検出、本体）。
     *
     *   上のテスト（改ざん後の CSV が 1 行だけ）は、文言を厳密化した今は M41 を検出できている。
     *   ただしそれは「歯止めが無いと `validCount === 0` の別の歯止めが代わりに発火し、
     *   **文言が変わる**」という間接的な効果に頼った検出でしかない。もし将来 2 つの歯止めの
     *   文言をそろえる編集が入れば、M41 自体は直っていないのに上のテストは緑に戻ってしまう。
     *
     *   このテストは文言に頼らず、**実際の実害**（rowErrors の歯止めが無いと正当な行が黙って
     *   登録される）を DB で確かめる。改ざん後の CSV に正当な行 1 つ（A0001）と不正な行 1 つ
     *   （A0002。許可していないドメイン）を混ぜると、`rowErrors !== []`（A0002 がエラー）かつ
     *   `validCount === 1`（A0001 は正当）になるため、`validCount === 0` の歯止めは
     *   （2 つの文言をどうそろえても）発火しない。この状態で `rowErrors` を見る歯止めを消すと
     *   ① 検査が最後まで通り `OneTimeAction::claimFrom()` を素通りしてトランザクションへ進み、
     *     A0001 が**黙って登録され**、A0002 だけが（`$analysis['rows']` に入っていないので）
     *     無言で捨てられる。つまり歯止めが削られると「一部だけ取り込まれて成功扱いになる」。
     */
    public function test_the_server_refuses_a_mixed_file_and_imports_neither_row(): void
    {
        $preview = $this->preview("A0001,甲 一郎,,RE\n")->assertOk();
        $form    = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.users.import.execute') . '"');

        // hidden を、正当な行（A0001）と不正な行（A0002。許可していないドメイン）が
        // 混在する CSV に差し替える
        $tampered = $form['fields'];
        $tampered['csv_data'] = base64_encode(self::HEADER . "A0001,甲 一郎,,RE\nA0002,乙 二郎,evil@gmail.com,RE\n");

        $this->actingAs($this->admin())->post($form['action'], $tampered)
            ->assertRedirect(route('approvals.admin.users.import'))
            ->assertSessionHas('error', '取り込めない行があります。もう一度アップロードして内容を確認してください。');

        // 正当な行（A0001）も、不正な行（A0002）も、どちらも取り込まれていない
        $this->assertSame(0, User::where('employee_number', 'A0001')->count(), '正当な行が黙って取り込まれている');
        $this->assertSame(0, User::where('employee_number', 'A0002')->count());
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
     *   （実測。`OneTimeAction::claimFrom()` が同じ理由で `is_string` を挟んでいる）。
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

        // ⚠ 役割と表示を別々に見る。`validCount` は `continue` の無い push では動かないので、
        //   これだけだと `$rowErrors[] =` への変異が緑のまま通る（Bug #54 ④）。
        $this->assertSame(3, $preview->viewData('validCount'), '注意であってエラーではない');
        $this->assertNotEmpty($preview->viewData('warnings'), '注意ではなくエラーに積まれている');
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertStringContainsString('ほかの行より桁が少ないです', $preview->getContent());
        $this->assertStringContainsString('先頭の 0 が落ちていませんか', $preview->getContent());
    }

    /**
     * 桁数の注意は、エラーになる行では出さない。
     *
     * ⚠ Task 15 の変異表の M46（`analyze()` の docblock「ここから先は取り込むと決めた行。
     *   注意はこの位置でだけ積む」のとおり、桁数の注意（`$warnings[] への push`）は全エラー
     *   判定を通り抜けた `else` 節（＝既存利用者に当たらない新規行）の中でだけ評価されている
     *   のを、ループの先頭（全エラー判定より前）へ移す変異）が塞ぐ穴 —— 移すと、エラーになる
     *   行でも桁数の条件（この社員番号が、ファイル内の最大桁数より少ない）を満たせば
     *   `$warnings` に積まれてから `$rowErrors` にも積まれる——つまり同じ行が両方の入れ物に
     *   入る。既存のテストは「エラーになる行」と「桁数の注意の対象になりうる行」が
     *   同時に成立するデータを 1 つも持っていなかったため、この変異はどのテストにも
     *   引っかからず全緑で通る。
     * ⚠ 役割（`viewData`）と表示を別々に見る（Bug #54 ④）。表示は部分一致
     *   （`assertStringContainsString('桁が少ない', ...)`）ではなく、エラー行・注意行それぞれの
     *   `_import_preview.blade.php` の描画そのもの（`エラー 行N: …` / `⚠ 行N: …`）を全文一致で見る。
     *   ⚠ **全文一致の needle にも別の弱点がある** —— 文言や書式（`⚠ 行N: …` の形そのもの）が
     *   将来変わると、`assertStringNotContainsString` は「その文字列が無い」ことを何の意味も
     *   なく証明し続け、検出力が無音で消える（needle がドリフトして空振りし続ける型）。
     *   これを防ぐため、同じ CSV に**陽性対照**（行4。基準より桁が少ないが、部門は正しく
     *   エラーにはならない行）を混ぜ、その行では同じ形の文言が実際に描画されることを先に
     *   確かめてから、エラー行（行3）にはそれが出ていないことを見る。
     */
    public function test_the_digit_width_warning_does_not_fire_for_an_error_row(): void
    {
        // 行2: 社員番号 10001（5 桁）で桁数の基準（ファイル内の最大幅）を作る正当な行
        // 行3: 社員番号 123（3 桁。基準より少ない＝桁数の注意の対象になりうる）だが
        //      所属部門が未登録（ZZ）のため必ずエラーになる行
        // 行4: 社員番号 456（3 桁。行3 と同じく基準より少ない）で所属部門は正しい RE
        //      ＝桁数の注意が実際に付くはずの陽性対照
        $preview = $this->preview("10001,甲,,RE\n123,乙,,ZZ\n456,丙 三郎,,RE\n")->assertOk();

        $this->assertSame(2, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('rowErrors'), 'エラー行が想定と異なる');
        $this->assertSame(3, $preview->viewData('rowErrors')[0]['row']);

        // 完全一致（contains ではない）で見ているので、行4の注意が抜けている・
        // 行3に注意が紛れ込んでいる、のどちらも検出できる
        $this->assertSame(
            [4],
            array_column($preview->viewData('warnings'), 'row'),
            '桁数の注意が想定の行に付いていない（エラー行に付く／正当な行に付かない、のどちらも異常）'
        );

        $html = $preview->getContent();
        $this->assertStringContainsString('エラー 行3: 登録されていない部門です: ZZ', $html);

        // 陽性対照: この文言の形が実際に描画されることを、「行3には出ていない」と
        // 主張する直前に確かめる（上記の「全文一致の needle の弱点」への対策）
        $this->assertStringContainsString(
            '⚠ 行4: 社員番号 456 は、ほかの行より桁が少ないです。Excel で先頭の 0 が落ちていませんか',
            $html,
            '陽性対照が失敗している（この文言の形はそもそも描画されていない）'
        );
        $this->assertStringNotContainsString(
            '⚠ 行3: 社員番号 123 は、ほかの行より桁が少ないです。Excel で先頭の 0 が落ちていませんか',
            $html,
            'エラー行にまで桁数の注意が表示されている'
        );
    }
}
