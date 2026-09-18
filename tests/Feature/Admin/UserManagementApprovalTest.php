<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLog;
use App\Models\Department;
use App\Models\User;
use App\Support\InitialPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        // ⚠ 下の各テストは値を上書きして送るので、**画面の欄の name が変わっても緑のまま通る**（Bug #47）。
        //    上書きの前に、画面が実際にその名前で描いていることを対で見る
        $this->assertArrayHasKey('role', $form['fields'], '新規登録にロールの選択肢が無い');
        $this->assertArrayHasKey('guide_token', $form['fields'], '新規登録に 1 回限りの鍵が描かれていない');
        $this->assertStringContainsString('name="employee_number"', $this->createFormHtml($html), '新規登録に社員番号の欄が無い');
        $this->assertStringContainsString('name="email"', $this->createFormHtml($html), '新規登録にメールアドレスの欄が無い');
        $this->assertStringContainsString('name="departments[]"', $this->createFormHtml($html), '新規登録に所属部門の欄が無い');

        return [$form, array_merge($form['fields'], $overrides)];
    }

    /** 新規登録フォームの HTML（編集モーダルの同名の欄に一致して false-pass するのを防ぐ） */
    private function createFormHtml(string $html): string
    {
        return $this->formHtml($html, $this->createFormNeedle());
    }

    /** needle を含むフォームの HTML だけを切り出す（ページ全体で見ると別のフォームに一致する） */
    private function formHtml(string $html, string $needle): string
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "フォームが見つからない: {$needle}");

        $open  = strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);
        $this->assertNotFalse($open, "{$needle} を含む <form> の開始タグが見つからない");
        $this->assertNotFalse($close, "{$needle} を含む <form> が閉じていない");

        return substr($html, $open, $close - $open);
    }

    /** フォームの中の `<select name="…">` だけを切り出す（同じ語がページの別の場所に出るため。Bug #43） */
    private function selectHtml(string $formHtml, string $name): string
    {
        $pos = strpos($formHtml, '<select name="' . $name . '"');
        $this->assertNotFalse($pos, "選択肢が見つからない: {$name}");

        $close = strpos($formHtml, '</select>', $pos);
        $this->assertNotFalse($close, "{$name} の <select> が閉じていない");

        return substr($formHtml, $pos, $close - $pos);
    }

    /** 絞り込みフォームの HTML（新規登録フォームと **action が同じ URL** なので method まで含めて探す） */
    private function filterFormHtml(string $html): string
    {
        return $this->formHtml($html, 'method="GET" action="' . route('admin.users.index') . '"');
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

    /**
     * 一覧に社員番号の列がある。
     *
     * ⚠ 素の `assertSee('M001')` は false-pass する（Bug #43）— 編集ボタンの
     *   `openEditModal(…, {{ Js::from($u->employee_number) }}, …)` にも同じ文字列が出るので、
     *   列を丸ごと消しても緑のまま通る（実測）。**セルごと**見る。
     */
    public function test_the_list_shows_the_employee_number(): void
    {
        User::factory()->create(['name' => '甲 一郎', 'employee_number' => 'M001', 'must_change_password' => false]);

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $this->assertStringContainsString('>社員番号</th>', $html, '社員番号の見出しが無い');
        $this->assertStringContainsString('>M001</td>', $html, '社員番号のセルが無い');
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

    /**
     * ロールの絞り込みに「決裁のみ」が出る。
     *
     * ⚠ 素の `assertSee('決裁のみ')` は false-pass する（Bug #43）— **編集モーダルの `<option>`**
     *   と、その下の説明文（「『決裁のみ』にすると、基幹の所属部門は外れます。」）にも一致するので、
     *   絞り込みから決裁のみを消しても緑のまま通る（実測）。**絞り込みの `<select>` の中だけ**を見る。
     */
    public function test_the_role_filter_includes_approval_only(): void
    {
        $html   = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $select = $this->selectHtml($this->filterFormHtml($html), 'role');

        foreach (UserRole::cases() as $role) {
            $this->assertStringContainsString('value="' . $role->value . '"', $select, "{$role->value} が絞り込みに無い");
            $this->assertStringContainsString($role->label(), $select, "{$role->label()} が絞り込みに無い");
        }
    }

    /** ロールの絞り込みが実際に絞る（I7: この diff が触ったブロック） */
    public function test_the_role_filter_narrows_the_list(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager->value, 'must_change_password' => false]);
        $staff   = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $ids = $this->actingAs($this->executive())->get('/admin/users?role=' . UserRole::Manager->value)
            ->assertOk()->viewData('users')->pluck('id');

        $this->assertTrue($ids->contains($manager->id));
        $this->assertFalse($ids->contains($staff->id), 'ロールで絞り込めていない');
    }

    /** 検索は氏名とメールアドレスも見る（placeholder が 3 つ約束している） */
    public function test_search_covers_the_name_and_the_email(): void
    {
        $byName  = User::factory()->create(['name' => '検索 太郎', 'email' => 'zzz@example.com', 'must_change_password' => false]);
        $byMail  = User::factory()->create(['name' => '別 次郎', 'email' => 'needle@example.com', 'must_change_password' => false]);

        $names = $this->actingAs($this->executive())->get('/admin/users?search=' . urlencode('検索 太郎'))
            ->assertOk()->viewData('users')->pluck('id');
        $this->assertTrue($names->contains($byName->id), '氏名で検索できない');
        $this->assertFalse($names->contains($byMail->id));

        $mails = $this->actingAs($this->executive())->get('/admin/users?search=needle@example.com')
            ->assertOk()->viewData('users')->pluck('id');
        $this->assertTrue($mails->contains($byMail->id), 'メールアドレスで検索できない');
        $this->assertFalse($mails->contains($byName->id));
    }

    /** 新規登録: 初期パスワードの入力欄と JS の生成をやめる（D12） */
    public function test_the_create_form_no_longer_asks_for_a_password(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="password"', $html, '初期パスワードの入力欄が残っている');
        $this->assertStringNotContainsString('Math.random', $html, '画面の JS がパスワードを作っている');
        // ⚠ ページ全体で見ると**編集モーダル**の欄に一致するので、新規登録フォームの中だけを見る
        $this->assertStringContainsString('name="employee_number"', $this->createFormHtml($html), '社員番号の入力欄が無い');
    }

    /**
     * 「決裁のみ」は画面に出さないだけでなく、サーバーでも拒む。
     *
     * ⚠ 選択肢が無いことだけを見ると、手で組んだ送信が素通りする（Bug #47 の受け側）。
     */
    public function test_creating_an_approval_only_user_is_rejected(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '丙 三郎', 'employee_number' => 'M900', 'email' => '',
            'role' => UserRole::ApprovalOnly->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasErrors('role');

        $this->assertSame(0, User::where('employee_number', 'M900')->count());
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

        // ⚠ 先にこれを通す。`assertOk()` だけだと検証エラーのとき Laravel が
        //    `Call to a member function all() on array` という読めない理由で落ちる（実測）
        $response->assertSessionHasNoErrors();
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

        $log = ApprovalSettingLog::where('action', 'user.created')->sole();
        $this->assertSame('user', $log->target_type);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame([], $log->old_values);
        $this->assertSame([
            'name' => '甲 一郎', 'employee_number' => 'M001',
            'email' => 'a@example.com', 'role' => UserRole::Staff->value,
        ], $log->new_values);
    }

    /**
     * **案内の紙に出た初期パスワードで、実際にログインできる。**
     *
     * ⚠ `store()` は平文とハッシュを**手で組にしている唯一の経路**（再発行は `PasswordReissuer` を通り、
     *   `PasswordReissueTest` の `Hash::check` が組を守っている）。ここが無いと、
     *   **別の値をハッシュしても・新規利用者を無効で作っても全部緑**のまま通り、
     *   「印刷して渡した紙でログインできないアカウント」が本番に出る。
     */
    public function test_the_printed_password_actually_logs_in(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '甲 一郎', 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $guide = $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasNoErrors()->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<dt>初期パスワード<\/dt>\s*<dd[^>]*>([^<]+)<\/dd>/u', $guide, $m),
            '案内に初期パスワードが出ていない'
        );
        $password = trim($m[1]);
        $this->assertSame(InitialPassword::LENGTH, mb_strlen($password));

        // 管理者からログアウトして、紙のとおりに入る
        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login'), ['login_id' => 'M001', 'password' => $password])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs(User::where('employee_number', 'M001')->sole());
    }

    /**
     * 同じ鍵で 2 回目は通さない（ブラウザの「フォームを再送信しますか」対策。設計書 §5.12）。
     *
     * ⚠ 「社員番号もメールも一意だから `unique` で差し戻る」では**塞ぎきれない** —
     *   登録したあとにその人の社員番号やメールアドレスを編集で変えると `unique` が当たらず、
     *   古いタブの再送信で**同じ人の重複アカウントが黙って 1 つ増える**。
     */
    public function test_the_create_form_can_only_be_submitted_once(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '甲 一郎', 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasNoErrors()->assertOk();

        // 登録後に社員番号を変える（unique では止まらなくなる状態を作る）
        $created = User::where('name', '甲 一郎')->sole();
        $created->forceFill(['employee_number' => 'M900'])->save();

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, User::where('name', '甲 一郎')->count(), '重複アカウントができた');
    }

    /**
     * 入力エラーで差し戻されたら、同じ鍵でもう一度送れる
     *   ＝ **起きなかった処理で鍵を焼かない**（設計書 §5.12）。
     *
     * ⚠ **このテストが守っているのは順序の規則そのものであって、画面の筋書きではない。**
     *   実ブラウザでは同じ鍵を 2 回送る形にならない — hidden は描画のたびに
     *   `OneTimeAction::issue()` を呼び、検証エラーは `back()`（＝一覧を GET し直す）なので
     *   **毎回あたらしい鍵**になる（実測。`index.blade.php` に `old()` は 0 件）。
     *   ⚠ **順序を誤っても「送り直せなくなる」画面はどこにも無い**（design doc §5.12・
     *   2026-09-17 Task 13 実測 —— **CSV の確定でさえ**同じ鍵を再掲しない。`preview()` が
     *   毎回 `issue()` し直し、確定を断られた差し戻し先の `GET /users/import` には
     *   `guide_token` も `csv_data` も無い）。誤った順序で焼けるのは、どのみち**捨てられる側の
     *   鍵が 1 つ増えるだけ**。それでも順序を守るのは、**起きなかった処理に鍵を焼いては
     *   いけない**という §5.12 の決まりそのもののため。
     *   ⚠ ここに「差し戻したフォームは同じ鍵を持ったまま再描画される」と書いていたのは**誤り**で、
     *   その後「順序が本当に効くのは CSV の確定のような経路」と書き直したのも**別の誤り**だった
     *   （design doc の実測はまさにその経路も「効かない」と言っている）。誤った理由の注記は
     *   次の読み手を誤らせる（Bug #42 ②）。
     */
    public function test_a_validation_error_does_not_burn_the_guide_token(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '', 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasErrors('name');

        // 氏名だけ直して、同じ鍵のまま送り直す（＝ claim が検証の前なら、ここで焼けた鍵に当たって落ちる）
        $this->actingAs($this->executive())->post($form['action'], array_merge($fields, ['name' => '甲 一郎']))
            ->assertSessionHasNoErrors()->assertOk();

        $this->assertSame(1, User::where('name', '甲 一郎')->count());
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

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasNoErrors()->assertOk();

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

    /**
     * メールアドレスの重複は大文字小文字を畳んで見る。
     *
     * ⚠ **社員番号だけで測ると、検証の前の正規化が load-bearing でなくなる**（実測）—
     *   社員番号は `regex` が大文字と半角しか通さないので、正規化を外しても
     *   「書式が違う」という別の理由で赤になり、テストが緑のままにならない。
     *   メールアドレスには書式の縛りが無いので、正規化を外すと**検証を素通りする**。
     *   実測: そのあと `User` の `saving` フックが小文字に正規化して INSERT するので、
     *   一意索引に当たって **PDOException（本番では 500）**になる — 画面には
     *   「このメールアドレスは既に登録されています。」が出るべき場面。
     */
    public function test_duplicate_email_detection_ignores_letter_case(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'must_change_password' => false]);

        [$form, $fields] = $this->createForm([
            'name' => '乙 二郎', 'employee_number' => '', 'email' => 'A@Example.com',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::where('name', '乙 二郎')->count());
    }

    /** 正規化は「拒む」だけでなく「通す」側にも効く（小文字・全角で入力しても登録できる） */
    public function test_the_employee_number_is_normalized_before_validation(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '丁 四郎', 'employee_number' => ' m002 ', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        $this->actingAs($this->executive())->post($form['action'], $fields)
            ->assertSessionHasNoErrors()->assertOk();

        $this->assertSame('M002', User::where('name', '丁 四郎')->sole()->employee_number);
    }

    /** 新規登録のロールの選択肢に「決裁のみ」は出さない（CSV で作る） */
    public function test_the_create_form_does_not_offer_approval_only(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $create = $this->createFormHtml($html);

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

    /**
     * 画面から再発行すると、許可するドメインの人には通知メールがキューに積まれる（設計書 §5.13）。
     *
     * ⚠ 部品の層の `PasswordReissueTest` は**画面を通らない**ので、`resetPassword()` が
     *   `PasswordReissuer` を通らない形（自前でハッシュを書く等）に変わっても緑のまま通る。
     *   ほかの再発行のテストは `email => null` の人で測っているのでメールが 0 通になり、
     *   ここが唯一の守り手になる。
     */
    public function test_reissue_from_the_screen_queues_the_notification_mail(): void
    {
        Mail::fake();
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $target = User::factory()->create([
            'name' => '甲 一郎', 'email' => 'a@mitsuwat.co.jp', 'employee_number' => 'M001', 'must_change_password' => false,
        ]);

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.resetPassword', $target) . '"');

        $response = $this->actingAs($this->executive())->post($form['action'], $form['fields']);
        $response->assertOk();

        Mail::assertQueued(PasswordReissuedMail::class, 1);
        Mail::assertQueued(
            PasswordReissuedMail::class,
            fn (PasswordReissuedMail $mail) => $mail->hasTo('a@mitsuwat.co.jp')
        );

        // 案内の帯の件数も画面から出ている（`ReissueResult::toGuide()` の配線）
        $this->assertStringContainsString('通知メール: 送る 1 人／送らない 0 人', $response->getContent());
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
        // ⚠ 下で値を上書きするので、**欄の name が変わっても緑のまま通る**（Bug #47）。先に対で見る
        $this->assertArrayHasKey('president_user_id', $form['fields'], '社長の候補の選択肢が無い');
        $this->assertStringContainsString(
            '<option value="' . $candidate->id . '"',
            $this->formHtml($html, 'action="' . route('admin.users.president') . '"'),
            '有効でメールアドレスのある人が候補に出ていない'
        );

        $this->actingAs($this->executive())->post($form['action'], array_merge($form['fields'], [
            'president_user_id' => $candidate->id,
        ]))->assertRedirect(route('admin.users.index'));

        $this->assertSame($candidate->id, ApprovalSetting::current()->president_user_id);

        $log = ApprovalSettingLog::where('action', 'president.changed')->sole();
        $this->assertSame('approval_setting', $log->target_type);
        $this->assertSame(['president_user_id' => null], $log->old_values, '変更前が読めない記録になっている');
        $this->assertSame(['president_user_id' => $candidate->id], $log->new_values);

        // 指定した人が一覧の「決裁の社長」に出る（印も付く）
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/決裁の社長:.{0,200}社長 三郎/su', $html, '一覧に社長の氏名が出ていない');
        $this->assertStringContainsString('>社長</span>', $html, '一覧の行に社長の印が無い');
    }

    /**
     * 社長の候補は「有効でメールアドレスのある人」だけ（選択肢そのものを固定する）。
     *
     * ⚠ 受け側（`Rule::exists`）だけを見ると、**画面が候補を広げても**緑のまま通る。
     */
    public function test_the_president_candidates_exclude_inactive_and_mailless_users(): void
    {
        $ok       = User::factory()->create(['name' => 'アア 適格', 'email' => 'ok@example.com', 'must_change_password' => false]);
        $noMail   = User::factory()->create(['name' => 'イイ 無メール', 'email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);
        $inactive = User::factory()->create(['name' => 'ウウ 無効', 'email' => 'ng@example.com', 'status' => UserStatus::Inactive->value, 'must_change_password' => false]);

        $candidates = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->viewData('presidentCandidates');

        $this->assertTrue($candidates->contains('id', $ok->id));
        $this->assertFalse($candidates->contains('id', $noMail->id), 'メールアドレスの無い人が候補に出ている');
        $this->assertFalse($candidates->contains('id', $inactive->id), '無効な人が候補に出ている');
    }

    /** 社長の指定は経営層だけ（ルートの門番。I3） */
    public function test_only_executives_can_designate_the_president(): void
    {
        $candidate = User::factory()->create(['email' => 'p@example.com', 'must_change_password' => false]);

        foreach ([UserRole::Manager, UserRole::Staff] as $role) {
            $actor = User::factory()->create(['role' => $role->value, 'must_change_password' => false]);

            $this->actingAs($actor)
                ->post(route('admin.users.president'), ['president_user_id' => $candidate->id])
                ->assertForbidden();
        }

        $this->assertNull(ApprovalSetting::current()->president_user_id);
    }

    /**
     * 削除済みの利用者は社長にできない。
     *
     * ⚠ `Rule::exists` は SoftDeletes の全域スコープを見ないので、`whereNull('deleted_at')` の
     *   1 行だけがこれを止めている（この人は状態も有効・メールアドレスもあるため、ほかの条件は通る）。
     */
    public function test_a_deleted_user_cannot_be_the_president(): void
    {
        $candidate = User::factory()->create(['email' => 'p@example.com', 'must_change_password' => false]);
        $candidate->delete();

        $this->actingAs($this->executive())
            ->post(route('admin.users.president'), ['president_user_id' => $candidate->id])
            ->assertSessionHasErrors('president_user_id');

        $this->assertNull(ApprovalSetting::current()->president_user_id);
    }

    /**
     * 候補が 1 人もいないときは、選択肢ゼロの `<select required>` を出さない（行き止まりになる）。
     *
     * 社員番号だけで運用していて、メールアドレスが 1 件も無い組織で起こりうる。
     */
    public function test_the_president_modal_says_so_when_there_are_no_candidates(): void
    {
        // 操作者自身も候補にならないよう、メールアドレスを持たない経営層で開く
        $actor = User::factory()->create([
            'role' => UserRole::Executive->value, 'email' => null, 'employee_number' => 'E001',
            'must_change_password' => false,
        ]);

        $html = $this->actingAs($actor)->get('/admin/users')->assertOk()->getContent();
        $form = $this->formHtml($html, 'action="' . route('admin.users.president') . '"');

        $this->assertStringContainsString('候補がいません。先に利用者にメールアドレスを登録してください。', $form);
        $this->assertStringNotContainsString('name="president_user_id"', $form, '選択肢ゼロの欄が出ている');
        $this->assertStringNotContainsString('>設定する<', $form, '押しても何も起きないボタンが出ている');
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

        // ⚠ **4 つの入口すべてで文言まで見る。** `assertSessionHas('error')` だけだと、
        //    その入口の文言を変える変異が緑のまま通る（実測で ②③ が素通りしていた）。
        //    入口ごとに案内が違うと、利用者は別の不具合だと思う。
        $expected = "{$president->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。";

        // ① 行の無効化
        $this->actingAs($this->executive())
            ->patch(route('admin.users.toggleStatus', $president), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', $expected);
        $this->assertTrue($president->fresh()->isActive());

        // ② 削除
        $this->actingAs($this->executive())->delete(route('admin.users.destroy', $president))
            ->assertSessionHas('error', $expected);
        $this->assertFalse($president->fresh()->trashed());

        // ③ 編集画面でメールアドレスを空に
        $this->actingAs($this->executive())->put(route('admin.users.update', $president), [
            'name' => $president->name, 'employee_number' => 'M001', 'email' => '',
            'role' => $president->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ])->assertSessionHas('error', $expected);
        $this->assertSame('p@example.com', $president->fresh()->email);

        // ④ **編集画面からの無効化**（入口が 4 つあるのに守りが 3 つしか無かった。Bug #44）
        $this->actingAs($this->executive())->put(route('admin.users.update', $president), [
            'name' => $president->name, 'employee_number' => 'M001', 'email' => 'p@example.com',
            'role' => $president->role->value, 'status' => UserStatus::Inactive->value,
            'departments' => [$this->department->id],
        ])->assertSessionHas('error', $expected);

        $this->assertTrue($president->fresh()->isActive(), '編集画面から社長を無効化できてしまう');

    }

    /**
     * 途中で落ちたら**丸ごと巻き戻る**（新規登録）。
     *
     * ⚠ 囲わないと、利用者は作られたのに `attach()` が落ちて**所属部門ゼロの利用者**が残り、
     *   部門で絞った一覧から消えるのにログインはできる状態になる。案内の紙だけが出ている。
     * ⚠ 落とす位置は**利用者の保存と所属部門の attach のあと**（記録を書く瞬間）。
     *   ここより前で落とすと、囲っていなくても何も残らないので検査が成立しない。
     */
    public function test_a_failure_partway_through_creating_rolls_everything_back(): void
    {
        [$form, $fields] = $this->createForm([
            'name' => '甲 一郎', 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::Staff->value, 'departments' => [$this->department->id],
        ]);

        ApprovalSettingLog::creating(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $this->actingAs($this->executive())->withoutExceptionHandling()->post($form['action'], $fields);
            $this->fail('例外が出ていない（この検査そのものが成立していない）');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, User::where('name', '甲 一郎')->count(), '所属部門ゼロの利用者が残った');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('department_user')->count(), '所属部門の行が残った');
    }

    /** 途中で落ちたら**丸ごと巻き戻る**（編集）。所属部門の付け替えだけが残らないこと */
    public function test_a_failure_partway_through_updating_rolls_everything_back(): void
    {
        $user = User::factory()->create([
            'name' => '旧 名前', 'role' => UserRole::Staff->value, 'employee_number' => 'M001',
            'email' => 'a@example.com', 'must_change_password' => false,
        ]);
        $user->departments()->attach($this->department->id);

        $other = Department::create(['name' => '不動産', 'code' => 'realestate', 'display_order' => 2]);

        ApprovalSettingLog::creating(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $this->actingAs($this->executive())->withoutExceptionHandling()
                ->put(route('admin.users.update', $user), [
                    'name' => '新 名前', 'employee_number' => 'M001', 'email' => 'a@example.com',
                    'role' => UserRole::Staff->value, 'status' => UserStatus::Active->value,
                    'departments' => [$other->id],
                ]);
            $this->fail('例外が出ていない（この検査そのものが成立していない）');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('旧 名前', $user->fresh()->name, '氏名だけ変わって残った');
        $this->assertSame([$this->department->id], $user->fresh()->departments->pluck('id')->all(), '所属部門が付け替わったまま残った');
    }

    /**
     * 編集画面が実際に「無効」を選べること（上の ④ の**呼び出し側**）。
     *
     * ⚠ 上は値を直接 PUT するので、画面から状態の欄が消えても緑のまま通る（Bug #47）。
     *   編集モーダルは Alpine が `:action` を組み立てるので `parseForm` では拾えない。
     */
    public function test_the_edit_form_offers_the_inactive_status(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $select = $this->selectHtml($this->editModalHtml($html), 'status');

        foreach (UserStatus::cases() as $status) {
            $this->assertStringContainsString('value="' . $status->value . '"', $select, "{$status->value} が選べない");
        }
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

    /**
     * 状態の変更も記録に残る（設計書 §5.14 の表「ロール・状態…の変更」）。
     *
     * ⚠ 編集モーダル経由（`user.updated`）とは別の入口なので、そちらのテストでは守れない。
     */
    public function test_status_changes_from_the_row_action_are_recorded(): void
    {
        $target = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $this->actingAs($this->executive())
            ->patch(route('admin.users.toggleStatus', $target), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('success');

        $log = ApprovalSettingLog::where('action', 'user.status_changed')->sole();
        $this->assertSame(['status' => 'active'], $log->old_values);
        $this->assertSame(['status' => 'inactive'], $log->new_values);
        $this->assertSame($target->id, $log->target_id);
    }

    /** 同じ状態を送り直しても記録を増やさない */
    public function test_an_unchanged_status_is_not_recorded(): void
    {
        $target = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $this->actingAs($this->executive())
            ->patch(route('admin.users.toggleStatus', $target), ['status' => UserStatus::Active->value])
            ->assertSessionHas('success');

        $this->assertSame(0, ApprovalSettingLog::where('action', 'user.status_changed')->count());
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

        $log = ApprovalSettingLog::where('action', 'member.flags_changed')->sole();
        $this->assertSame('user', $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame(['can_view_all' => false, 'is_admin' => false], $log->old_values);
        $this->assertSame(['can_view_all' => true, 'is_admin' => true], $log->new_values);
    }

    /**
     * **片方だけ**付ける（2 本）。
     *
     * ⚠ 両方 ON / 両方 OFF しか作らないと、`syncApprovalFlags($user, can_view_all, is_admin)` の
     *   **引数を入れ替えても緑**のまま通る。入れ替わると「全件閲覧者」のつもりが
     *   **決裁の管理者**（会社・部門・利用者を管理でき、パスワードも再発行できる）になる。
     */
    public function test_only_the_approval_admin_flag_is_granted(): void
    {
        $target = $this->memberTarget();

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), $this->memberPayload($target, [
            'is_admin' => '1',
        ]))->assertRedirect(route('admin.users.index'));

        $fresh = $target->fresh();
        $this->assertTrue($fresh->isApprovalAdmin(), '決裁の管理者が付いていない');
        $this->assertFalse($fresh->canViewAllApprovals(), '頼んでいない全件閲覧者が付いた');

        $this->assertSame(
            ['can_view_all' => false, 'is_admin' => true],
            ApprovalSettingLog::where('action', 'member.flags_changed')->sole()->new_values
        );
    }

    public function test_only_the_view_all_flag_is_granted(): void
    {
        $target = $this->memberTarget();

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), $this->memberPayload($target, [
            'can_view_all' => '1',
        ]))->assertRedirect(route('admin.users.index'));

        $fresh = $target->fresh();
        $this->assertTrue($fresh->canViewAllApprovals(), '全件閲覧者が付いていない');
        $this->assertFalse($fresh->isApprovalAdmin(), '頼んでいない決裁の管理者が付いた');

        $this->assertSame(
            ['can_view_all' => true, 'is_admin' => false],
            ApprovalSettingLog::where('action', 'member.flags_changed')->sole()->new_values
        );
    }

    /** 決裁の指定を試す相手（所属部門を 1 つ持つ一般担当者） */
    private function memberTarget(): User
    {
        $target = User::factory()->create([
            'role' => UserRole::Staff->value, 'employee_number' => 'M001', 'email' => 'a@example.com',
            'must_change_password' => false,
        ]);
        $target->departments()->attach($this->department->id);

        return $target;
    }

    /** 編集画面がそのまま送る形（変えたい項目だけ上書きする） */
    private function memberPayload(User $target, array $overrides = []): array
    {
        return array_merge([
            'name' => $target->name, 'employee_number' => 'M001', 'email' => 'a@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ], $overrides);
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

    /** 編集モーダルの HTML（Alpine が `:action` を組み立てるので氏名の `x-model` を目印にする） */
    private function editModalHtml(string $html): string
    {
        return $this->formHtml($html, 'x-model="editName"');
    }

    /**
     * 決裁のみへ変えると基幹の所属部門が外れる（設計書 §5.7）。
     *
     * ⚠ **チェックの付いた所属部門も一緒に送る。** 編集モーダルは `x-show` で欄を隠すだけなので、
     *   実際のブラウザは隠れたチェックボックスも送ってくる（Top trap「同一 name ＋ x-show は
     *   片方が hidden でも送信される」）。送らない形で測ると、`sync($validated['departments'] ?? [])`
     *   に変えた変異が**緑のまま通る**（実測）— 何も送られなければ結果が同じになるため。
     */
    public function test_switching_to_approval_only_drops_the_base_departments(): void
    {
        $target = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);
        $target->departments()->attach($this->department->id);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::ApprovalOnly->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->department->id],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertCount(0, $target->fresh()->departments);
        $this->assertTrue($target->fresh()->isApprovalOnly());
    }

    /** 所属部門を送らずに決裁のみへ変えるときも外れる（チェックが 1 つも無い状態の送信） */
    public function test_switching_to_approval_only_without_any_department_field(): void
    {
        $target = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);
        $target->departments()->attach($this->department->id);

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), [
            'name' => $target->name, 'employee_number' => 'M001', 'email' => '',
            'role' => UserRole::ApprovalOnly->value, 'status' => UserStatus::Active->value,
        ])->assertRedirect(route('admin.users.index'));

        $this->assertCount(0, $target->fresh()->departments);
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
        $actor  = $this->executive();

        $this->actingAs($actor)->delete(route('admin.users.destroy', $target));

        $deleted = ApprovalSettingLog::where('action', 'user.deleted')->sole();
        $this->assertSame('user', $deleted->target_type);
        $this->assertSame($target->id, $deleted->target_id);
        $this->assertSame($actor->id, $deleted->actor_user_id, '誰が消したのか読めない記録になっている');
        $this->assertSame(['deleted_at' => null], $deleted->old_values);
        // ⚠ old と new が入れ替わると「消えていたものが消えた」と逆向きに読める
        $this->assertSame(
            ['deleted_at' => $target->fresh()->deleted_at->toDateTimeString()],
            $deleted->new_values
        );

        $this->actingAs($actor)->patch(route('admin.users.restore', $target->id));

        $restored = ApprovalSettingLog::where('action', 'user.restored')->sole();
        $this->assertSame('user', $restored->target_type);
        $this->assertSame($target->id, $restored->target_id);
        $this->assertSame($actor->id, $restored->actor_user_id);
    }

    /** 社員番号を空に戻せる（メールアドレスが残っていれば） */
    public function test_the_employee_number_can_be_cleared(): void
    {
        $target = $this->memberTarget();

        $this->actingAs($this->executive())->put(route('admin.users.update', $target), $this->memberPayload($target, [
            'employee_number' => '',
        ]))->assertRedirect(route('admin.users.index'));

        $this->assertNull($target->fresh()->employee_number, '社員番号を空に戻せない');
        $this->assertSame('a@example.com', $target->fresh()->email);
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
