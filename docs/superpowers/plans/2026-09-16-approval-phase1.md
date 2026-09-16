# 決裁申請 段階1（基幹の改修）実装計画

> **実行する人へ（必読）**: この計画は superpowers:subagent-driven-development（推奨）または
> superpowers:executing-plans で**タスクごとに**実装する。手順は `- [ ]` のチェックボックスで追う。
> **TDD**（失敗するテストを先に書く）と**タスクごとのコミット**を守る。

**ゴール**: 社員番号でログインでき、決裁のみ利用者を決裁以外の全画面から締め出し、
決裁の管理者が部門と利用者を設定して CSV で一括登録し、初期パスワードを紙の案内で配れる状態にする。

**設計**: 設計書 @docs/superpowers/specs/2026-09-16-approval-phase1-design.md（e52af295）。
この計画は設計書の決定（D1〜D15）を前提にする。**設計を変えたくなったら、まず設計書を直してからこの計画を直す。**

**技術**: Laravel 12.55 / PHP 8.3 / Blade + Alpine.js 3 / MySQL 8（本番）・SQLite in-memory（テスト）/
chillerlan/php-qrcode ^6.0（新規）

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase1`（ブランチ `approval-phase1`）。
**main repo では作業もテストもしない。**

**テストの流し方**（worktree で）:

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

**着手前のベースライン**: 1711 tests / 10534 assertions green（2026-09-16 に確認済み）。

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題）

設計書 §9 が「実装計画で決める」としていた 5 件は、**実測して**次のとおり決めた。
数字と出力はすべてこの worktree で実際に流した結果。

### 0.1 QR の部品 → `chillerlan/php-qrcode ^6.0`

| 候補 | php | 追加で要る拡張 | 依存 | ライセンス | 判定 |
|---|---|---|---|---|---|
| **chillerlan/php-qrcode 6.0.1** | `^8.2` | `ext-mbstring` | `chillerlan/php-settings-container` | MIT / Apache-2.0 | **採用** |
| bacon/bacon-qr-code 3.1.1 | `^8.1` | **`ext-iconv`** | `dasprid/enum` | BSD-2-Clause | 見送り |
| endroid/qr-code 6.x | **`^8.4`** | — | bacon | MIT | 不可（本番は 8.3） |

決め手は**拡張の裏取り**。`ext-mbstring` は `laravel/framework` の `require` に入っている
（`composer.lock` で確認済み）＝ **本番でアプリが動いている事実がそのまま存在の証明**になる。
`ext-iconv` はどの依存も要求していないので、本番に有ることを別途確かめる必要が出る。
Context7 は v7 のドキュメント（`php ^8.4` ／ 名前空間 `chillerlan\phpQRCode\`）を返すが、
**6.0.1 の実体は `chillerlan\QRCode\`**。v7 は PHP 8.4 必須なので `^6.0` で固定する。

実際に 6.0.1 を落として API を確かめた（`svgAddXmlHeader => false` で XML 宣言なし・
`svgUseFillAttributes => true` で `fill="#000"` が入り CSS 不要・`<path>` 1 本・`<script>` 無し）:

```
<svg xmlns="http://www.w3.org/2000/svg" class="qr-svg login-qr" viewBox="0 0 37 37" preserveAspectRatio="xMidYMid">
<path class="qr-data-dark dark login-qr" fill="#000" d="M2 2 h1 v1 h-1Z …"/>
</svg>
```

⚠ **1 枚あたり約 10KB ある。** ログイン画面の URL は全員同じなので、
**ページごとに丸ごと繰り返すと 200 人で約 1.9MB** になる（実測）。
`<symbol>` を 1 つ置いて各ページは `<use>` で参照する（§Task 9）。

### 0.2 テスト用 migration の `users.role` → **作成 migration を直接書き換える**

⚠ **`->change()` は使わない。** SQLite で実測すると、テーブルを作り直す際に
**触っていない `status` の CHECK が黙って消えた**（Bug #60 と同じ型）:

```
--- before ---
"role" varchar check ("role" in ('executive','manager','staff')) not null default 'staff',
"status" varchar check ("status" in ('active','inactive')) not null default 'active'
--- after  Schema::table(... ->enum('role',[...4つ])->change()) ---
"role" varchar check ("role" in ('executive','manager','staff','approval_only')) not null default 'staff',
"status" varchar not null default ('active')          ← CHECK が消えている
```

`users` は**テスト専用の migration**（本番は raw SQL）で、`0001_01_01_000000_create_users_table.php` には
すでに `softDeletes()` を「live DB は raw SQL で別途追加」と注記して**その場で足した前例**がある。
同じやり方で `employee_number` の追加・`email` の NULL 可・`role` の enum 追加を
**作成 migration に直接書く**。作り直しが起きないので CHECK は失われない。

⚠ そのうえで**カナリアのテスト**を置く（§Task 2）: `sqlite_master` の `CREATE TABLE` 文に
`role` の CHECK が 4 値・`status` の CHECK が 2 値あること。将来だれかが `->change()` を足したら赤くなる。

### 0.3 本番の `users` の定義（2026-09-16 に利用者の承認のうえ読み取り）

```
`email`  varchar(255) utf8mb4_unicode_ci NOT NULL,  UNIQUE KEY `users_email_unique` (`email`)
`role`   enum('executive','manager','staff') NOT NULL DEFAULT 'staff'
`status` enum('active','inactive')           NOT NULL DEFAULT 'active'
KEY `idx_users_role_status` (`role`,`status`)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
rows=6 / null_email=0 / 削除済み=3 / approval_* の表=0
```

- **索引名は Laravel の既定の形**（`users_email_unique`）。新しい一意索引も
  **`users_employee_number_unique`** にする ＝ テスト用 migration の `->unique()` が
  自動で付ける名前と一致し、本番とテストで索引名が食い違わない
- **照合順序は `utf8mb4_unicode_ci`（大文字小文字を区別しない）。** テストの SQLite は区別するので、
  **一意の検査は必ず正規化したあとの値で行う**（§Task 2 の `User::booted()`）。順番を間違えると
  テストだけ通って本番の一意索引に当たる
- `email` は NOT NULL → `MODIFY COLUMN ... NULL` が要る（既存 6 行はすべてメールアドレスあり＝移行は不要）

### 0.4 本番のパスワードの暗号化にかかる時間（同日 実測・PHP 8.3.32）

```
cost=10   0.305 秒/件   → 200 人で  61.0 秒
cost=12   1.270 秒/件   → 200 人で 254.1 秒
max_execution_time=0（CLI）/ memory_limit=128M / mbstring=yes
```

⚠ **手元（Apple Silicon・cost 10 で 0.065 秒）の 4.7 倍遅い。** 設計書 §5.10 が仮に置いていた
「1 ファイル 200 行」は、本番では 1 リクエストに **61 秒**かかる計算で、Apache / FastCGI の
待ち時間に収まらない恐れがある。

### 0.5 締め出しのテストで `where` 付きのルートに入れる値

実測（430 ルート）: `where` を持つのは **3 本だけ**。

```
attachments/{type}/{id}  {"type":"contracts|investments|repairs|procurements|projects|ms_tenants|dad_projects"}
storage/{path}           {"path":".*"}   ← web の外（auth 無し）
storage/{path}           {"path":".*"}   ← 同上
```

規則: **`where` が「| で区切られたリテラルの並び」ならその先頭を使い、無ければ `999999`**。
`storage/{path}` は web グループの外なので分類の「③ web の外」に入り、値は要らない。

`auth` の付かないルートも 5 本しかない（`GET up` / `GET login` / `POST login` / `storage` 2 本）。
`guest` を持つのは `login` の 2 本。**分類の 4 バケツはこの実測と一致する。**

### 0.6 `lang/ja/validation.php` に足す和名

```
login_id              ログインID
employee_number       社員番号
president_user_id     決裁の社長
can_view_all          全件閲覧者
is_admin              決裁の管理者
company_id            会社
fiscal_start_month    期の始まりの月
short_name            略称
code                  アルファベット
sort_order            表示順
domain                ドメイン
approval_departments  決裁の所属部門
user_ids              対象の利用者
csv_file              CSVファイル
csv_data              取り込むデータ
```

⚠ `name` `email` `status` `role` `departments` は**すでにある**（画面ごとに語が変わるキーなので、
必要な画面では `validate()` の**第 3 引数**で上書きする。Bug #37）。

### 0.7 そのほか計画時に決めたこと

| # | 決定 | 理由 |
|---|---|---|
| P1 | 回数の設定は `config/auth.php` の `login_throttle`、決裁の上限は新しい `config/approval.php` | ログインは基幹の機能なので `auth`。段階0 が `config/backup.php` を足した前例に倣う |
| P2 | 決裁のルートのパラメータ名は `{approvalCompany}` `{approvalDepartment}` `{mailDomain}` | `{department}` は基幹で意味が別（`CheckDepartmentAccess` が `$request->route('department')` を読む）。名前を分けて取り違えを構造的に防ぐ |
| P3 | ログイン案内は**独立した HTML**（レイアウトを継承せず `<style>` を直書き） | 「サイドバー・ヘッダーを出さない」が構造で保証される。`@vite` に依存しないので `withoutVite()` のテストでも本番でも同じものが出る |
| P4 | 記録の表は `const UPDATED_AT = null` ＋ `updating` / `deleting` で例外 | 追記のみ（設計書 §5.14）をモデルで強制する |
| P5 | **CSV は 1 ファイル 50 行・まとめて再発行は 1 回 50 人**（設計書の仮の 200 から下げる） | §0.4 の実測。本番は 50 人で **15.3 秒**（200 人なら 61 秒で待ち時間に収まらない恐れ）。利用者は 100〜200 人なので、稼働前の一括登録は 2〜4 ファイルに分ける。数は `config/approval.php` の 1 か所 |
| P7 | データプロバイダは **`#[DataProvider('name')]` 属性**（docblock の `@@dataProvider` は使わない） | このプロジェクトの既存 6 本がすでに属性形式。PHPUnit 11 は docblock 形式を非推奨にしていて、使うと `PHPUnit Deprecations: 1` が出る（Task 1 の実装で実測） |
| P8 | `followingRedirects()` は**中で別のリクエストを出すヘルパと組み合わせない** | 一発フラグで、`submit()` の中の `get('/login')`（フォームの取得）に消費され、本命の POST の転送が辿られない（Task 3 の実装で実測）。`submit()` → 別途 `get('/login')` の 2 段にする。⚠ しかも失敗すると Laravel の診断が `session('errors')->all()` を叩いて `Call to a member function all() on array` に化け、落ちた理由が読めなくなる（Bug #49 の関連） |
| P9 | 試行の制限に掛かったかは **`Retry-After` ヘッダーの有無**で見る（ステータスでは見分けられない） | `Limit::response()` を使うと制限超過も `redirect()`（302）になり、通常の失敗（`back()` も 302）と**同じステータス**になる。`ThrottleRequests` は通す側で `addHeaders()` に `$retryAfter` を渡さない（173 行）ので、`Retry-After` は**止まったときにしか付かない**（246 行。Task 4 の実装で実測）。⚠ `X-RateLimit-Remaining` は**両方に付く**ので使えない |
| P6 | 変異テストは Task 15 にまとめる | Bug #44 の作法（先にコミット → `git status --porcelain` が空 → `git diff --stat` が非空 → 落ちた**理由の文言**まで照合）を 1 か所で回す |

---

## 1. 触るファイル

### 新規

| ファイル | 役割 |
|---|---|
| `config/approval.php` | CSV の上限・まとめて再発行の上限・案内の鍵の有効時間 |
| `app/Support/LoginId.php` | ログイン ID の正規化（全角→半角・空白・大文字小文字・`@` での振り分け） |
| `app/Support/InitialPassword.php` | 初期パスワードの生成と暗号化（強度 10） |
| `app/Support/LoginQrCode.php` | ログイン画面の QR を SVG の `<symbol>` 用に組み立てる |
| `app/Support/OneTimeAction.php` | ログイン案内の 1 回限りの鍵 |
| `app/Support/Approval/SettingLogger.php` | 設定の変更の記録を 1 行足す |
| `app/Http/Middleware/EnsureUserIsActive.php` | 無効な利用者をログイン中でも締め出す |
| `app/Http/Middleware/RestrictApprovalOnlyUsers.php` | 決裁のみ利用者を決裁以外から締め出す |
| `app/Http/Middleware/EnsureApprovalAdmin.php` | 決裁の管理者だけを通す |
| `app/Models/ApprovalMember.php` ほか 5 本 | 決裁の表 |
| `app/Http/Controllers/Approval/HomeController.php` | 決裁のホーム（仮） |
| `app/Http/Controllers/Approval/UserController.php` | 利用者の管理・再発行 |
| `app/Http/Controllers/Approval/UserImportController.php` | CSV 一括登録 |
| `app/Http/Controllers/Approval/OrganizationController.php` | 会社・部門・許可するドメイン |
| `app/Mail/PasswordReissuedMail.php` | 再発行の通知メール |
| `routes/approval.php` | 決裁のルート（`routes/web.php` の最後で読み込む） |
| `resources/views/approvals/*` | 決裁の画面・ログイン案内 |
| `resources/views/layouts/partials/sidebar_approval.blade.php` | 決裁のみ利用者のサイドバー |
| `database/sql/2026-09-16-approval-phase1.sql` | 本番の DDL |
| `database/migrations/2026_09_16_000001_create_approval_tables.php` | テスト用スキーマ（本番の鏡） |
| `tests/Feature/Approval/*` ほか | テスト |

### 変更

| ファイル | 変更 |
|---|---|
| `database/migrations/0001_01_01_000000_create_users_table.php` | `employee_number` 追加・`email` を NULL 可・`role` の enum に `approval_only` |
| `app/Enums/UserRole.php` | `ApprovalOnly` を足す |
| `app/Models/User.php` | 正規化・スコープ・行き先・決裁のリレーション |
| `database/factories/UserFactory.php` | `approvalOnly()` の state |
| `app/Http/Controllers/Auth/AuthController.php` | `login_id` でのログイン |
| `resources/views/auth/login.blade.php` | 入力欄を「社員番号またはメールアドレス」に |
| `app/Providers/AppServiceProvider.php` | `RateLimiter::for('login', …)` |
| `routes/web.php` | `throttle:login` / `/dashboard` の振り分け / `routes/approval.php` の読み込み |
| `bootstrap/app.php` | 門番 2 つと `AuthenticateSession` を web グループへ・別名 `approval.admin` |
| `app/Http/Controllers/Admin/UserController.php` | 社員番号・メール任意・指定・案内の画面・記録 |
| `resources/views/admin/users/index.blade.php` | 同上（JS のパスワード生成を撤去） |
| `app/Http/Controllers/Admin/{Customer,Mansion}ImportController.php` | 担当者の氏名照合に `baseUsers()` |
| `resources/views/layouts/app.blade.php` | サイドバーの出し分け |
| `resources/views/layouts/partials/sidebar.blade.php` | 決裁の管理のリンク（3 か所） |
| `lang/ja/validation.php` | 和名 |
| `tests/Feature/Auth/{AuthenticationTest,LoginThrottleTest}.php` | 新しい入力欄・新しい規則で書き直す |
| `CLAUDE.md` / `docs/ARCHITECTURE.md` / `docs/BACKLOG.md` | 記述の更新 |

---
## Task 0: 本番の読み取り（✅ 2026-09-16 実施済み）

> **結果は §0.3・§0.4 に記録した。** 以下は手順の記録（もう一度測るとき用）。
> 分かったこと: 一意索引は Laravel 既定の名前 ／ 照合順序は大文字小文字を区別しない ／
> `email` は NOT NULL で既存 6 行すべて値あり（移行不要）／ `approval_*` の表は 0 ／
> **本番の暗号化は手元の 4.7 倍遅く、CSV とまとめて再発行の上限を 200 → 50 に下げた**。

## Task 0（原文）: 本番の読み取り（承認を得てから・親セッションが行う）

> ⚠ **この作業だけはサブエージェントに任せない。** 本番の ssh は利用者の承認が要る。
> 読み取りだけで、**データも設定も一切変えない**。

**Files:** なし（結果を Task 2 / Task 7 の SQL に反映する）

- [x] **Step 1: 利用者に承認を求める**

`AskUserQuestion` で「本番の `users` の定義と、パスワードの暗号化にかかる時間を、読み取りだけで確かめてよいか」を聞く。
断られたら Task 1 へ進み、**Task 17 の直前に必ずもう一度聞く**（SQL を確定できないまま反映してはいけない）。

- [x] **Step 2: `users` の定義を読む**

本番のシェルは csh なので `/bin/sh` の heredoc を ssh に流す（memory の作法）。

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$row = Illuminate\Support\Facades\DB::selectOne("SHOW CREATE TABLE `users`");
echo $row->{"Create Table"}, PHP_EOL;
echo "rows=", Illuminate\Support\Facades\DB::table("users")->count(), PHP_EOL;
echo "null_email=", Illuminate\Support\Facades\DB::table("users")->whereNull("email")->count(), PHP_EOL;
'
SH
```

記録すること: `email` の型と照合順序・一意索引の**名前**・`role` の enum の値と並び・`status` の enum・
`deleted_at` の有無・既存の索引名（`idx_users_role_status` など）・行数。

- [x] **Step 3: パスワードの暗号化にかかる時間を測る**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php -r '
foreach ([10, 12] as $cost) {
    $t = microtime(true);
    for ($i = 0; $i < 5; $i++) { password_hash("abcdefghij", PASSWORD_BCRYPT, ["cost" => $cost]); }
    printf("cost=%d  %.3f 秒/件%s", $cost, (microtime(true) - $t) / 5, PHP_EOL);
}
'
SH
```

- [x] **Step 4: 上限を決めて記録する**

実測は本番 0.305 秒/件（手元の 4.7 倍）。**200 人 = 61 秒**では Apache / FastCGI の待ち時間に
収まらない恐れがあるため、`config/approval.php` の既定を **50**（＝ 約 15 秒）にした。
根拠は §0.4・§0.7 の P5。

- [x] **Step 5: 結果をこの計画に追記してコミット**

```bash
git add docs/superpowers/plans/2026-09-16-approval-phase1.md
git commit -m "$(cat <<'EOF'
docs: 本番の users の定義と暗号化の速さを読み取って計画に記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 1: ログイン ID の正規化と初期パスワードの部品

**Files:**
- Create: `app/Support/LoginId.php`
- Create: `app/Support/InitialPassword.php`
- Create: `config/approval.php`
- Test: `tests/Unit/Support/LoginIdTest.php`
- Test: `tests/Unit/Support/InitialPasswordTest.php`

- [ ] **Step 1: 失敗するテストを書く（`LoginId`）**

`tests/Unit/Support/LoginIdTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\LoginId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ログイン ID の正規化（設計書 §5.3・D5）。
 *
 * ⚠ Laravel を起動しない Unit テストなので、`config/app.php` ではなく php.ini に支配される部分がある
 *   （Bug #54 ①）。ここは文字列処理だけで timezone に依存しないが、依存する処理を足すときは
 *   setUp() で明示的に固定すること。
 */
class LoginIdTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            // 入力, 期待
            'そのまま'                 => ['M001', 'M001'],
            '小文字は大文字へ'          => ['m001', 'M001'],
            '前後の空白を落とす'        => ['  M001  ', 'M001'],
            '全角の英数字は半角へ'      => ['Ｍ００１', 'M001'],
            '全角の空白も落とす'        => ['　M001　', 'M001'],
            'ハイフンは残す'            => ['m-001', 'M-001'],
            'メールは小文字へ'          => ['User@Example.COM', 'user@example.com'],
            '全角の＠はメール扱い'      => ['ＵＳＥＲ＠ＥＸＡＭＰＬＥ.ＣＯＭ', 'user@example.com'],
            'メールの前後の空白'        => [' user@example.com ', 'user@example.com'],
            'null は空文字'            => [null, ''],
            '空文字は空文字'            => ['', ''],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize(?string $input, string $expected): void
    {
        $this->assertSame($expected, LoginId::normalize($input));
    }

    /**
     * ⚠ **検証の前に呼ばれる経路がある**ので、文字列でない値が届いても落ちないこと。
     *   `?string` で受けていたころは `login_id[]=a` を送るだけでリミッタの中が
     *   TypeError になり、生の 500 が返っていた（しかもどちらの上限にも数えられない）。
     */
    public function test_normalize_tolerates_non_string_input(): void
    {
        $this->assertSame('', LoginId::normalize(['a', 'b']));
        $this->assertSame('', LoginId::normalize(new \stdClass()));
        $this->assertSame('M001', LoginId::normalize('M001'));
        // 数値は文字列として扱う（社員番号が数字だけのことがある）
        $this->assertSame('123', LoginId::normalize(123));
    }

    public function test_throttle_key_tolerates_non_string_input(): void
    {
        $this->assertSame('|198.51.100.1', LoginId::throttleKey(['a'], '198.51.100.1'));
    }

    public function test_is_email_looks_only_at_the_at_sign(): void
    {
        $this->assertTrue(LoginId::isEmail('a@b'));
        $this->assertFalse(LoginId::isEmail('M001'));
        // 形式が正しいかは見ない（見ると「メールとして間違っている」と分かってしまう）
        $this->assertTrue(LoginId::isEmail('@'));
    }

    public function test_column_picks_the_lookup_column(): void
    {
        $this->assertSame('email', LoginId::column('user@example.com'));
        $this->assertSame('employee_number', LoginId::column('M001'));
    }

    /**
     * ⚠ 正規化を忘れて生の値を渡されても取り違えないこと。
     *   全角の `＠` は半角にしてから見ないと、メールアドレスが社員番号として引かれる。
     */
    public function test_column_normalizes_before_deciding(): void
    {
        $this->assertSame('email', LoginId::column(' Ｕｓｅｒ＠ｅｘａｍｐｌｅ.ｃｏｍ '));
        $this->assertSame('employee_number', LoginId::column('　ｍ００１　'));
    }

    /**
     * 試行の制限の鍵。⚠ 大文字小文字・全角・前後の空白を変えても同じ鍵になること
     * （違う鍵になると、1 文字変えるだけで制限を回避できる）。
     */
    public function test_throttle_key_is_stable_across_spelling_differences(): void
    {
        $a = LoginId::throttleKey('m001', '198.51.100.1');
        $b = LoginId::throttleKey('　Ｍ００１　', '198.51.100.1');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, LoginId::throttleKey('M002', '198.51.100.1'));
        $this->assertNotSame($a, LoginId::throttleKey('M001', '198.51.100.2'));
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter LoginIdTest
```

期待: `Class "App\Support\LoginId" not found`

- [ ] **Step 3: `LoginId` を書く**

`app/Support/LoginId.php`:

```php
<?php

namespace App\Support;

/**
 * ログイン ID（社員番号またはメールアドレス）の正規化（設計書 §5.3・D5）。
 *
 * ログイン・試行の制限の鍵・利用者の保存・CSV の取込が**すべてここを通る**。
 * 別々に正規化すると、保存した値と照合する値が食い違って「正しいのに入れない」が起きる。
 *
 * ⚠ 手順の順番に意味がある: 全角→半角 → 前後の空白を落とす → `@` で振り分け → 大文字小文字。
 *   先に `@` を見ると、全角の `＠` を取りこぼす（実測: `mb_convert_kana($v, 'as')` が `＠` を `@` にする）。
 */
final class LoginId
{
    /** 社員番号として認める形（D5。`-` は文字クラスの末尾に置いて範囲にしない） */
    public const EMPLOYEE_NUMBER_PATTERN = '/\A[A-Z0-9-]{1,20}\z/';

    /**
     * ⚠ 引数は `mixed`。**検証の前に呼ばれる経路がある**ので、配列や数値がそのまま届く:
     *   試行の制限の鍵（`AppServiceProvider` のリミッタは `validate()` より前に走る）と、
     *   フォームの正規化（`$request->merge()` で検証の前に整える）。
     *   `?string` で受けると `login_id[]=a&login_id[]=b` を送るだけで TypeError の 500 になり、
     *   しかもその 500 は**どちらの上限にも数えられない**ので無制限に叩ける（実測で再現）。
     *   文字列にできない値は空として扱い、形式の誤りは呼び出し側の `validate()` に任せる。
     */
    public static function normalize(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            return '';
        }

        // 'a' = 全角の英数字と記号を半角へ / 's' = 全角の空白を半角へ
        $value = trim(mb_convert_kana((string) $value, 'as'));

        return self::isEmail($value)
            ? mb_strtolower($value, 'UTF-8')
            : mb_strtoupper($value, 'UTF-8');
    }

    /**
     * `@` を含むならメールアドレスとして扱う。
     *
     * ⚠ 形式が正しいかは見ない。見てしまうと「メールとしては壊れている」と画面に出せてしまい、
     *   どちらの種類の ID を入れたかが攻撃者に分かる（設計書 §5.3 の「形式の違いを言わない」）。
     */
    public static function isEmail(string $value): bool
    {
        return str_contains($value, '@');
    }

    /**
     * その値を引く列。
     *
     * ⚠ 中でもう一度 normalize() を通す（normalize() は冪等）。呼び出し側が正規化を
     *   忘れて生の値を渡すと、全角の `＠` を含む文字列が `employee_number` に化けて
     *   「正しいのにログインできない」になる。呼び出し側の規律に頼らない。
     */
    public static function column(mixed $value): string
    {
        return self::isEmail(self::normalize($value)) ? 'email' : 'employee_number';
    }

    /** ログイン試行を数える鍵（設計書 §5.4）。正規化してから組むので綴りの違いで回避できない */
    public static function throttleKey(mixed $loginId, string $ip): string
    {
        return self::normalize($loginId) . '|' . $ip;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter LoginIdTest
```

期待: OK (14 tests)

- [ ] **Step 5: 失敗するテストを書く（`InitialPassword`）**

`tests/Unit/Support/InitialPasswordTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\InitialPassword;
use Tests\TestCase;

/**
 * 初期パスワード（設計書 §5.11・D13）。
 *
 * ⚠ `Tests\TestCase` を継承する（Laravel を起動する）。`Hash::make()` を使うため。
 * ⚠ phpunit.xml は BCRYPT_ROUNDS=4 だが、この部品は**強度 10 を明示**する。
 *   `password_get_info()` で実際の cost を見ることでしか、その指定が効いているか分からない。
 */
class InitialPasswordTest extends TestCase
{
    public function test_generated_password_has_the_specified_shape(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $pw = InitialPassword::generate();

            $this->assertSame(10, strlen($pw), "長さが 10 でない: {$pw}");
            $this->assertMatchesRegularExpression('/\A[a-z0-9]{10}\z/', $pw, "想定外の文字がある: {$pw}");
            // 見間違えやすい文字（D13）
            $this->assertDoesNotMatchRegularExpression('/[0o1li]/', $pw, "見間違えやすい文字がある: {$pw}");
            // 本人が決めるパスワードの規則（8 文字以上・英字と数字）も満たすこと
            $this->assertMatchesRegularExpression('/[a-z]/', $pw, "英字が無い: {$pw}");
            $this->assertMatchesRegularExpression('/[0-9]/', $pw, "数字が無い: {$pw}");
        }
    }

    public function test_generated_passwords_differ(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[InitialPassword::generate()] = true;
        }

        // 31^10 通りあるので 200 回で重複が出たら乱数が壊れている
        $this->assertCount(200, $seen, '生成されたパスワードが重複している');
    }

    /**
     * ⚠ 強度は 10（D13）。`phpunit.xml` の BCRYPT_ROUNDS=4 でも
     *   `config('hashing.bcrypt.rounds')` でもなく、この部品が明示する値が効くこと。
     */
    public function test_hash_uses_cost_10(): void
    {
        $info = password_get_info(InitialPassword::hash('abcdefghij'));

        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(10, $info['options']['cost'], '初期パスワードの暗号化の強度が 10 でない');
    }

    public function test_hash_verifies(): void
    {
        $pw = InitialPassword::generate();

        $this->assertTrue(password_verify($pw, InitialPassword::hash($pw)));
    }
}
```

- [ ] **Step 6: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter InitialPasswordTest
```

期待: `Class "App\Support\InitialPassword" not found`

- [ ] **Step 7: `InitialPassword` を書く**

`app/Support/InitialPassword.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * 初期パスワードの生成と暗号化（設計書 §5.11・D13）。
 *
 * 基幹の新規登録・基幹の再発行・CSV の取込・決裁の管理者の再発行が**すべてここを通る**。
 * 以前は画面の JS（`Math.random`）とコントローラの `generatePassword()` の 2 系統があった。
 *
 * ⚠ 生成した平文は画面に 1 度出すだけ（ログイン案内）。DB・セッション・ログ・記録に残さない。
 */
final class InitialPassword
{
    public const LENGTH = 10;

    /**
     * 暗号化の強度。
     *
     * ⚠ アプリの既定（12）より低い。100〜200 人ぶんをまとめて作るため（強度 12 は手元で 1 件 0.240 秒）。
     *   初回ログインで Laravel が今の強度へ自動で掛け直す（`rehash_on_login` が既定 true）し、
     *   そもそも初回にパスワードの変更を求めるので、無作為な 10 文字を一時的に守るには十分。
     */
    public const HASH_ROUNDS = 10;

    /** 見間違えやすい o / l / i を除いた 23 文字 */
    private const LETTERS = 'abcdefghjkmnpqrstuvwxyz';

    /** 見間違えやすい 0 / 1 を除いた 8 文字 */
    private const DIGITS = '23456789';

    public static function generate(): string
    {
        $pool = self::LETTERS . self::DIGITS;

        // 英字と数字を必ず 1 文字ずつ入れる（本人が決めるパスワードの規則と同じ条件を満たすため）
        $chars = [
            self::pick(self::LETTERS),
            self::pick(self::DIGITS),
        ];

        for ($i = count($chars); $i < self::LENGTH; $i++) {
            $chars[] = self::pick($pool);
        }

        // ⚠ str_shuffle() は暗号用でない乱数を使うので使わない
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** 強度 10 で暗号化する（`Hash::make()` の既定に任せない） */
    public static function hash(string $plain): string
    {
        return Hash::make($plain, ['rounds' => self::HASH_ROUNDS]);
    }

    private static function pick(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
```

- [ ] **Step 8: `config/approval.php` を作る**

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 決裁申請の上限（設計書 §5.10 の「処理時間」）
    |--------------------------------------------------------------------------
    |
    | 新しい人ごとにパスワードを暗号化するので時間がかかる。
    |
    | 実測（2026-09-16・強度 10）: 手元（Apple Silicon）0.065 秒/件 ／ **本番 0.305 秒/件**。
    | 本番は 50 人で約 15 秒・200 人だと約 61 秒で、Apache / FastCGI の待ち時間に収まらない
    | 恐れがあるため **50** にしている（利用者は 100〜200 人なので、稼働前の一括登録は
    | 2〜4 ファイルに分ける）。増やすときは本番で測り直すこと。
    |
    */

    'csv_max_rows' => env('APPROVAL_CSV_MAX_ROWS', 50),

    'bulk_reissue_max' => env('APPROVAL_BULK_REISSUE_MAX', 50),

    /*
    |--------------------------------------------------------------------------
    | ログイン案内の 1 回限りの鍵（設計書 §5.12）
    |--------------------------------------------------------------------------
    |
    | ブラウザの「フォームを再送信しますか」で 2 回目を実行してしまうと、印刷済みの案内が
    | こっそり無効になる。処理した鍵をこの時間だけ覚えておく。
    |
    */

    'guide_token_ttl_hours' => env('APPROVAL_GUIDE_TOKEN_TTL_HOURS', 12),

];
```

- [ ] **Step 9: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'LoginIdTest|InitialPasswordTest'
```

期待: OK (18 tests)

- [ ] **Step 10: コミット**

```bash
git add app/Support/LoginId.php app/Support/InitialPassword.php config/approval.php tests/Unit/Support/LoginIdTest.php tests/Unit/Support/InitialPasswordTest.php
git commit -m "$(cat <<'EOF'
feat(approval): ログイン ID の正規化と初期パスワードの部品を足す

社員番号とメールアドレスの正規化（全角→半角・空白・大文字小文字・@ での振り分け）を
LoginId に、初期パスワードの生成と強度 10 の暗号化を InitialPassword に 1 本化する。
どちらも決裁申請 段階1 の設計書 §5.3 / §5.11 のとおり。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `users` の改修（テスト用 migration・`UserRole`・`User`）

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`
- Modify: `app/Enums/UserRole.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Http/Controllers/Admin/CustomerImportController.php:238`
- Modify: `app/Http/Controllers/Admin/MansionImportController.php:888` / `:1133`
- Test: `tests/Feature/Approval/UsersSchemaTest.php`
- Test: `tests/Feature/Approval/UserIdentityTest.php`

- [ ] **Step 1: 失敗するテストを書く（スキーマのカナリア）**

`tests/Feature/Approval/UsersSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * テスト用スキーマのカナリア（Bug #60 と同型）。
 *
 * ⚠ `users.role` の enum を増やすときに `Schema::table(...)->enum(...)->change()` を使ってはいけない。
 *   SQLite はテーブルを作り直すので、**触っていない `status` の CHECK が黙って消える**。
 *   2026-09-16 に実測:
 *     変更前 "status" varchar check ("status" in ('active','inactive')) not null default 'active'
 *     変更後 "status" varchar not null default ('active')          ← CHECK が消えている
 *   よって作成 migration（0001_01_01_000000）を直接書き換える。このテストがその方針を守る。
 */
class UsersSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createTableSql(): string
    {
        return (string) DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', 'users'])->sql;
    }

    public function test_role_check_lists_all_four_values(): void
    {
        $sql = $this->createTableSql();

        foreach (['executive', 'manager', 'staff', 'approval_only'] as $value) {
            $this->assertStringContainsString(
                "'{$value}'",
                $sql,
                "users.role の CHECK に {$value} が無い（決裁のみ利用者を保存できない）"
            );
        }
    }

    /** ⚠ `->change()` を足した瞬間にここが落ちる（本体の狙い） */
    public function test_status_check_survives(): void
    {
        $this->assertMatchesRegularExpression(
            '/check\s*\(\s*"status"\s+in\s*\(\s*\'active\'\s*,\s*\'inactive\'\s*\)/i',
            $this->createTableSql(),
            'users.status の CHECK が消えている（enum を ->change() で変えると SQLite が作り直して落とす。Bug #60）'
        );
    }

    public function test_employee_number_is_unique_and_nullable(): void
    {
        DB::table('users')->insert(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'employee_number' => null]);
        DB::table('users')->insert(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'employee_number' => null]);

        $this->assertSame(2, DB::table('users')->count(), '社員番号は NULL を重複して持てること');

        DB::table('users')->insert(['name' => 'C', 'email' => 'c@example.com', 'password' => 'x', 'employee_number' => 'M001']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->insert(['name' => 'D', 'email' => 'd@example.com', 'password' => 'x', 'employee_number' => 'M001']);
    }

    public function test_email_is_nullable(): void
    {
        DB::table('users')->insert(['name' => 'A', 'email' => null, 'password' => 'x', 'employee_number' => 'M001']);
        DB::table('users')->insert(['name' => 'B', 'email' => null, 'password' => 'x', 'employee_number' => 'M002']);

        $this->assertSame(2, DB::table('users')->whereNull('email')->count(), 'メールアドレスは NULL を重複して持てること');
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter UsersSchemaTest
```

期待: 4 本とも赤（`role` の CHECK に `approval_only` が無い／`employee_number` 列が無い／`email` が NOT NULL）。
`test_status_check_survives` だけは最初から緑（これは「壊さない」ことの見張り）。

- [ ] **Step 3: 作成 migration を直接書き換える**

`database/migrations/0001_01_01_000000_create_users_table.php` の `Schema::create('users', …)` を次にする
（**`Schema::table(...)->change()` の新しい migration を足さない**。上のテストの docblock の理由）:

```php
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // 決裁申請 段階1: 社員番号でもログインする（設計書 §5.6・D5）。live DB は raw SQL で別途追加
            $table->string('employee_number', 20)->nullable()->unique();
            // 決裁のみ利用者はメールアドレスを持たないことがある（設計書 §5.6）。社員番号との「どちらかは必須」は
            // アプリ側で担保する（DB の CHECK にすると MySQL / SQLite で書き方が割れる）
            $table->string('email', 255)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255);
            // ⚠ 値を増やすときはこの行を直す。Schema::table(...)->enum(...)->change() は使わない
            //    （SQLite がテーブルを作り直し、status の CHECK が黙って消える。Bug #60 / UsersSchemaTest）
            $table->enum('role', ['executive', 'manager', 'staff', 'approval_only'])->default('staff');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // 論理削除（担当者履歴を残すため。live DB は raw SQL で別途追加）

            // インデックス
            $table->index(['role', 'status'], 'idx_users_role_status');
        });
```

- [ ] **Step 4: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter UsersSchemaTest
```

期待: OK (4 tests)

- [ ] **Step 5: 失敗するテストを書く（`UserRole` と `User`）**

`tests/Feature/Approval/UserIdentityTest.php`:

```php
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
```

- [ ] **Step 6: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter UserIdentityTest
```

期待: `UserRole::ApprovalOnly` が無くて fatal。

- [ ] **Step 7: `UserRole` に決裁のみを足す**

`app/Enums/UserRole.php`:

```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Executive = 'executive';
    case Manager = 'manager';
    case Staff = 'staff';
    /** 決裁申請だけを使う人（設計書 D6）。基幹の画面はすべて門番が止める */
    case ApprovalOnly = 'approval_only';

    public function label(): string
    {
        return match ($this) {
            self::Executive => '経営層',
            self::Manager => '部門管理者',
            self::Staff => '一般担当者',
            self::ApprovalOnly => '決裁のみ',
        };
    }

    public function isExecutive(): bool
    {
        return $this === self::Executive;
    }

    public function isManagerOrAbove(): bool
    {
        return in_array($this, [self::Executive, self::Manager]);
    }

    public function isApprovalOnly(): bool
    {
        return $this === self::ApprovalOnly;
    }

    /** 基幹を使うロール（決裁のみを除く。登録・編集の選択肢と絞り込みで使う） */
    public static function baseCases(): array
    {
        return [self::Executive, self::Manager, self::Staff];
    }
}
```

- [ ] **Step 8: `User` を直す**

`app/Models/User.php` の `$fillable` に `employee_number` を足し、`booted()` と 3 つのメソッドを足す。
`scopeAssignable()` の条件も直す:

```php
    protected $fillable = [
        'name',
        'employee_number',
        'email',
        'password',
        'must_change_password',
        'last_login_at',
    ];
```

`casts()` の直後（リレーションの前）に:

```php
    /**
     * ログイン ID を保存する直前に正規化する（設計書 §5.6）。
     *
     * ⚠ 画面・CSV・コマンドのどの経路から来ても同じ値になるよう、モデル側で行う。
     *   一意の検査は**正規化した値**で行うこと（検証の前に正規化する。大文字小文字の違いを
     *   本番の MySQL は同じとみなし、テストの SQLite は別とみなすので、順番を間違えると
     *   テストだけ通って本番の一意索引に当たる）。
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            foreach (['employee_number', 'email'] as $column) {
                if (! $user->isDirty($column)) {
                    continue;
                }
                $value = \App\Support\LoginId::normalize($user->{$column});
                $user->{$column} = $value === '' ? null : $value;
            }
        });
    }
```

ヘルパー（`isActive()` の下）:

```php
    /** 決裁のみ利用者か（設計書 D6） */
    public function isApprovalOnly(): bool
    {
        return $this->role === UserRole::ApprovalOnly;
    }

    /**
     * ログイン直後と `/dashboard` の振り分けの行き先（設計書 §5.3）。
     *
     * ⚠ 規則をここ 1 箇所に置く。2 箇所に書くと、決裁のみ利用者が基幹のダッシュボードへ送られ
     *   門番に跳ね返されて往復する。
     */
    public function homeRouteName(): string
    {
        return $this->isApprovalOnly() ? 'approvals.home' : ($this->isExecutive() ? 'dashboard.executive' : 'dashboard.tenant');
    }
```

スコープ:

```php
    /**
     * 基幹を使う利用者（決裁のみを除く）。状態は見ない。
     *
     * 取込の担当者の氏名照合など「有効でなくても引きたい」場面で使う。
     */
    public function scopeBaseUsers($query)
    {
        return $query->where('role', '!=', UserRole::ApprovalOnly->value);
    }

    /**
     * 担当者として選択可能なユーザー = 有効かつ未削除かつ基幹を使う人。
     * 削除済みは SoftDeletes のグローバルスコープが自動的に除外する。
     *
     * ⚠ 決裁のみ利用者を除くのはここ 1 箇所で、基幹の担当者セレクト 19 か所すべてが
     *   このスコープ（と assignableWith）を通る（設計書 §5.6）。
     */
    public function scopeAssignable($query)
    {
        return $query->where('status', UserStatus::Active->value)
                     ->where('role', '!=', UserRole::ApprovalOnly->value);
    }
```

- [ ] **Step 9: 取込 3 か所に `baseUsers()` を付ける**

`app/Http/Controllers/Admin/CustomerImportController.php:238`:

```php
                            $staffUser = User::baseUsers()->where('name', 'like', '%' . $staffName . '%')->first();
```

`app/Http/Controllers/Admin/MansionImportController.php:888` と `:1133`（同じ 1 行が 2 箇所）:

```php
                $staff = User::baseUsers()->where('name', $row['staff_user_name'])->first();
```

⚠ **2 箇所とも直す。** 同じ行が 2 つあるので `sed` の一括置換で片方を取りこぼさないこと（Bug #44）。

- [ ] **Step 10: `UserFactory` に決裁のみの state を足す**

`database/factories/UserFactory.php` の `unverified()` の下に:

```php
    /**
     * 決裁のみ利用者（設計書 D6）。
     *
     * ⚠ `must_change_password` は明示する側の責任（この state では触らない。
     *   既定に頼るとメモリ上は null・DB から引くと true になり経路で結果が変わる）。
     */
    public function approvalOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'role'             => \App\Enums\UserRole::ApprovalOnly->value,
            'email'            => null,
            'employee_number'  => 'A' . fake()->unique()->numberBetween(1000, 9999),
        ]);
    }
```

- [ ] **Step 11: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'UserIdentityTest|UsersSchemaTest|UserAssignableScopeTest'
```

期待: すべて OK。

- [ ] **Step 12: 全テストを流す**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

期待: **失敗 0**（既存 1711 本 ＋ 今回の追加分がすべて緑）。

⚠ `homeRouteName()` は**ルート名の文字列を返すだけ**で `route()` を呼ばないので、
`approvals.home` がまだ無くてもこのテストは通る。ここで赤が出るなら実装に別の問題がある。

- [ ] **Step 13: コミット**

```bash
git add database/migrations/0001_01_01_000000_create_users_table.php app/Enums/UserRole.php app/Models/User.php database/factories/UserFactory.php app/Http/Controllers/Admin/CustomerImportController.php app/Http/Controllers/Admin/MansionImportController.php tests/Feature/Approval/UsersSchemaTest.php tests/Feature/Approval/UserIdentityTest.php
git commit -m "$(cat <<'EOF'
feat(approval): 利用者に社員番号と決裁のみのロールを足す

users に employee_number（一意・NULL 可）を足し、email を NULL 可にして、
role に approval_only を加える。保存時の正規化・行き先の規則・基幹の担当者の
選択肢から決裁のみ利用者を外すスコープもモデルに置く。

⚠ enum の値を増やすのに ->change() を使わず作成 migration を直接書き換えた。
  SQLite はテーブルを作り直して status の CHECK を黙って落とすため（Bug #60）。
  UsersSchemaTest がその方針を守る。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 3: 社員番号でログインする

**Files:**
- Modify: `app/Http/Controllers/Auth/AuthController.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify: `routes/web.php`（`/dashboard` のクロージャ）
- Modify: `lang/ja/validation.php`（`attributes` に `login_id`・`employee_number`）
- Modify: `tests/Feature/Auth/AuthenticationTest.php`（`email` → `login_id`）
- Test: `tests/Feature/Auth/LoginIdentifierTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Auth/LoginIdentifierTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 社員番号またはメールアドレスでのログイン（設計書 §5.3）。
 *
 * ⚠ **画面が描画したフォームを分解して送り返す**（Bug #47）。値を直接 POST すると、
 *   入力欄の `name` が変わっても・`action` が変わっても・`@csrf` が消えても緑のまま通る。
 * ⚠ 画面の文言を見るテストで `assertSessionHas*()` を呼ばない（Bug #49）。
 * ⚠ `must_change_password` は必ず明示する。
 */
class LoginIdentifierTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private function submit(string $loginId, string $password): TestResponse
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('login') . '"');

        $this->assertSame('POST', $form['method'], 'ログインフォームが POST でない');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');
        $this->assertArrayHasKey('login_id', $form['fields'], 'ログイン ID の入力欄が無い');

        return $this->post($form['action'], array_merge($form['fields'], [
            'login_id' => $loginId,
            'password' => $password,
        ]));
    }

    public function test_the_form_asks_for_an_employee_number_or_an_email(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号またはメールアドレス', $html);
        $this->assertStringContainsString('name="login_id"', $html);
        // ⚠ type="email" だとブラウザが社員番号を弾く
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="login_id"[^>]*type="email"/',
            $html,
            'ログイン ID の欄が type="email" になっている（社員番号を入力できない）'
        );
        $this->assertStringNotContainsString('name="email"', $html, '古い email の入力欄が残っている');
    }

    public static function identifierCases(): array
    {
        return [
            '社員番号 そのまま'   => ['M001'],
            '社員番号 小文字'     => ['m001'],
            '社員番号 全角'       => ['Ｍ００１'],
            '社員番号 前後の空白' => ['  M001  '],
        ];
    }

    #[DataProvider('identifierCases')]
    public function test_login_with_an_employee_number(string $typed): void
    {
        $user = User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);

        $this->submit($typed, 'password')->assertRedirect(route('dashboard.tenant'));
        $this->assertAuthenticatedAs($user);
    }

    public static function emailCases(): array
    {
        return [
            'そのまま' => ['user@example.com'],
            '大文字'   => ['User@Example.COM'],
            '全角の＠' => ['ｕｓｅｒ＠ｅｘａｍｐｌｅ.ｃｏｍ'],
        ];
    }

    #[DataProvider('emailCases')]
    public function test_login_with_an_email(string $typed): void
    {
        $user = User::factory()->create([
            'email'                => 'user@example.com',
            'employee_number'      => null,
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);

        $this->submit($typed, 'password')->assertRedirect(route('dashboard.tenant'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * `@` の有無だけで引く列を決める（設計書 §5.3）。
     *
     * ⚠ 社員番号と同じ文字列をメールに持つ別人が居ても、取り違えないこと。
     */
    public function test_the_at_sign_decides_which_column_is_used(): void
    {
        $byNumber = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);
        $byEmail  = User::factory()->create(['employee_number' => 'M002', 'email' => 'm001@example.com', 'must_change_password' => false]);

        $this->submit('M001', 'password');
        $this->assertAuthenticatedAs($byNumber);

        $this->post('/logout');

        $this->submit('m001@example.com', 'password');
        $this->assertAuthenticatedAs($byEmail);
    }

    /** どちらが違うかを言わない（設計書 §5.3） */
    public function test_failure_message_does_not_say_which_part_was_wrong(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        // ⚠ followingRedirects() は 1 回だけ効く一発フラグで、submit() 内の最初の
        //   $this->get('/login')（フォーム取得）に消費されてしまい、本命の POST の
        //   リダイレクトが辿られない（実測）。submit() → 別途 get() で確実に辿る。
        $this->submit('M001', 'wrong');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号・メールアドレスまたはパスワードが正しくありません。', $html);
    }

    public function test_unknown_identifier_gets_the_same_message(): void
    {
        $this->submit('M999', 'whatever');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号・メールアドレスまたはパスワードが正しくありません。', $html);
        $this->assertGuest();
    }

    /**
     * 配列を送っても 500 にならないこと。
     *
     * ⚠ 試行の制限のリミッタは `validate()` より**前**に走り、`login_id` を生のまま読む。
     *   `LoginId::normalize()` が文字列しか受けなかったころは、`login_id[]=a&login_id[]=b` を
     *   送るだけで TypeError の 500 になり、**しかもどちらの上限にも数えられない**ので
     *   未ログインのまま無制限に叩けた（Task 4 のコード品質レビューが実測して発見）。
     */
    public function test_an_array_identifier_does_not_crash(): void
    {
        $response = $this->post('/login', ['login_id' => ['a', 'b'], 'password' => 'whatever']);

        $this->assertSame(302, $response->getStatusCode(), '配列を送ると 500 になっている');
        $this->assertGuest();
    }

    public function test_blank_identifier_is_rejected_in_japanese(): void
    {
        $this->submit('', 'password');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('社員番号またはメールアドレスを入力してください。', $html);
    }

    /** 入力は残す（打ち直させない） */
    public function test_the_typed_identifier_is_kept_on_failure(): void
    {
        $this->submit('M001', 'wrong');
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="login_id"[^>]*value="M001"/', $html, '入力したログイン ID が残っていない');
    }

    public static function roleCases(): array
    {
        return [
            [UserRole::Executive->value,    'dashboard.executive'],
            [UserRole::Manager->value,      'dashboard.tenant'],
            [UserRole::Staff->value,        'dashboard.tenant'],
            [UserRole::ApprovalOnly->value, 'approvals.home'],
        ];
    }

    #[DataProvider('roleCases')]
    public function test_where_each_role_lands(string $role, string $expected): void
    {
        User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'role'                 => $role,
            'must_change_password' => false,
        ]);

        $this->submit('M001', 'password')->assertRedirect(route($expected));
    }

    /** 初回はロールに関係なくパスワード変更へ */
    public function test_first_login_goes_to_the_password_change_screen(): void
    {
        User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'role'                 => UserRole::ApprovalOnly->value,
            'must_change_password' => true,
        ]);

        $this->submit('M001', 'password')->assertRedirect(route('password.change'));
    }

    /**
     * パスワード変更のあとも同じ規則で振り分ける（`/dashboard` のクロージャ）。
     *
     * ⚠ **決裁のみ利用者では、このクロージャを測れない。** `/dashboard` は
     *   `RestrictApprovalOnlyUsers`（web グループの門番）に**先に**捕まり、門番が
     *   `approvals.home` を決め打ちで返すので、クロージャの `homeRouteName()` に到達しない
     *   （Task 6 の実装で実測: `homeRouteName()` を旧ロジックに戻す変異を当てても
     *   このケースは緑のまま通った。Bug #48「安全網が主機構の変異を隠す」型）。
     *   行き先は同じなので**利用者の体験は正しい**が、**測っている機構が違う**。
     *   よってクロージャ自体は門番を通らないロールで測る。
     */
    #[DataProvider('dashboardClosureCases')]
    public function test_the_dashboard_route_uses_the_same_rule(string $role, string $expected): void
    {
        $user = User::factory()->create([
            'role'                 => $role,
            'employee_number'      => 'M001',
            'email'                => null,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route($expected));
    }

    public static function dashboardClosureCases(): array
    {
        return [
            // 門番を通らないロール ＝ クロージャの homeRouteName() を実際に通る
            '経営層'       => [UserRole::Executive->value, 'dashboard.executive'],
            '部門管理者'   => [UserRole::Manager->value, 'dashboard.tenant'],
            '一般担当者'   => [UserRole::Staff->value, 'dashboard.tenant'],
        ];
    }

    /**
     * 決裁のみ利用者が `/dashboard` を開いても決裁のホームに着くこと。
     *
     * ⚠ 上の注記のとおり、これを満たしているのは**クロージャではなく門番**。
     *   行き先が同じなので、どちらの機構が効いていても利用者の体験は変わらない。
     *   「門番が先に捕まえる」こと自体は `ApprovalOnlyLockoutTest` が全件分類で守っている。
     */
    public function test_an_approval_only_user_still_lands_on_the_approval_home(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::ApprovalOnly->value,
            'employee_number'      => 'M001',
            'email'                => null,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('approvals.home'));
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter LoginIdentifierTest
```

期待: 全部赤（`name="login_id"` が無い／`approvals.home` が未定義）。
⚠ `approvals.home` を使う 2 本は **Task 6 まで赤のまま**。Task 6 の最後で緑になることを確かめる。

- [ ] **Step 3: ログイン画面の入力欄を直す**

`resources/views/auth/login.blade.php` の「メールアドレス」ブロックを差し替える（アイコンは利用者バッジに変える）:

```blade
                {{-- 社員番号またはメールアドレス --}}
                <div class="mb-4">
                    <label for="login_id" class="block text-xs font-semibold text-gray-700 mb-2 tracking-wide">社員番号またはメールアドレス</label>
                    <div class="flex items-center gap-2.5 px-3.5 h-[46px] rounded-[10px] border-[1.5px] bg-gray-50 transition-all duration-200 focus-within:border-emerald-500 focus-within:bg-white focus-within:shadow-[0_0_0_3px_rgba(16,163,127,0.08)] {{ $errors->has('login_id') ? 'border-red-300' : 'border-gray-200' }}">
                        <svg class="w-[18px] h-[18px] text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <input
                            id="login_id"
                            name="login_id"
                            type="text"
                            value="{{ old('login_id') }}"
                            placeholder="例: M001 または user@mitsuwat.co.jp"
                            autocomplete="username"
                            autocapitalize="none"
                            autocorrect="off"
                            spellcheck="false"
                            required
                            autofocus
                            class="flex-1 bg-transparent border-none outline-none text-sm text-gray-900 placeholder-gray-400"
                        >
                    </div>
                    @error('login_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
```

- [ ] **Step 4: `AuthController::login()` を直す**

```php
    /**
     * ログイン処理
     * Route: POST /login
     *
     * 社員番号またはメールアドレスで照合する（設計書 §5.3）。`@` の有無だけで引く列を決め、
     * 形式が社員番号の規則に合わなくてもそのまま照合して失敗させる（どちらの種類の ID かを画面に出さない）。
     */
    public function login(Request $request)
    {
        $request->validate([
            'login_id' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ], [
            'login_id.required' => '社員番号またはメールアドレスを入力してください。',
            'login_id.max'      => '社員番号またはメールアドレスは255文字以内で入力してください。',
            'password.required' => 'パスワードを入力してください。',
        ]);

        $loginId  = LoginId::normalize($request->input('login_id'));
        $remember = $request->boolean('remember');

        $credentials = [
            LoginId::column($loginId) => $loginId,
            'password'                => $request->input('password'),
        ];

        if (! Auth::attempt($credentials, $remember)) {
            return back()
                ->withInput($request->only('login_id', 'remember'))
                ->withErrors(['login' => '社員番号・メールアドレスまたはパスワードが正しくありません。']);
        }

        $user = Auth::user();

        // アカウントが無効の場合はログアウトしてエラー
        if (!$user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->only('login_id'))
                ->withErrors(['login' => 'このアカウントは無効になっています。管理者にお問い合わせください。']);
        }

        // セッション再生成（セッション固定攻撃対策）
        $request->session()->regenerate();

        // 最終ログイン日時を更新
        $user->update(['last_login_at' => now()]);

        // ログイン履歴を記録
        LoginHistory::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'logged_in_at' => now(),
        ]);

        // 初回ログイン時はパスワード変更画面へ
        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        // ロールに応じた行き先（規則は User::homeRouteName() の 1 箇所だけ）
        return redirect()->route($user->homeRouteName());
    }
```

ファイル冒頭に `use App\Support\LoginId;` を足す。

- [ ] **Step 5: `/dashboard` のクロージャを直す**

`routes/web.php` の該当箇所:

```php
    Route::get('/dashboard', function () {
        // 行き先の規則は User::homeRouteName() の 1 箇所（ログイン直後とここが同じものを使う）
        return redirect()->route(auth()->user()->homeRouteName());
    })->name('dashboard');
```

- [ ] **Step 6: 和名を足す**

`lang/ja/validation.php` の `attributes` の末尾（`'rows_json' => '取り込む工程',` の下）に:

```php
        // --- 決裁申請 段階1 ---
        'login_id'             => 'ログインID',
        'employee_number'      => '社員番号',
```

- [ ] **Step 7: 既存の `AuthenticationTest` を新しい入力欄に合わせる**

`tests/Feature/Auth/AuthenticationTest.php` の `submitLoginForm()` と、直接 `'email' => …` を
POST しているケースを `login_id` に置き換える。**文言のアサートも新しいものへ**
（「メールアドレスまたはパスワードが正しくありません。」→「社員番号・メールアドレスまたはパスワードが正しくありません。」）。

⚠ **`makeUser()` は `email` を持ったままでよい**（メールでのログインは今までどおり通る）。

```bash
grep -n "email\|メールアドレス" tests/Feature/Auth/AuthenticationTest.php
```

で全箇所を洗い、1 つずつ直す。

- [ ] **Step 8: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'LoginIdentifierTest|AuthenticationTest|ForcePasswordChangeTest|PasswordChangeTest'
```

期待: **`route('approvals.home')` を呼ぶ 2 本以外は緑**
（`test_where_each_role_lands` の `approval_only` のデータセットと
`test_the_dashboard_route_uses_the_same_rule`）。
落ちる理由が `Route [approvals.home] not defined.` であることを確かめる
（違う理由なら実装に別の問題がある）。

- [ ] **Step 9: 全テスト**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -20
```

期待: 失敗は上の 2 本だけ。

- [ ] **Step 10: コミット**

```bash
git add app/Http/Controllers/Auth/AuthController.php resources/views/auth/login.blade.php routes/web.php lang/ja/validation.php tests/Feature/Auth/
git commit -m "$(cat <<'EOF'
feat(auth): 社員番号でもログインできるようにする

入力欄を「社員番号またはメールアドレス」の 1 つにし、@ の有無で引く列を決める。
どちらが違うかは画面に出さない。ログイン後の行き先の規則は User::homeRouteName()
の 1 箇所にまとめ、/dashboard の振り分けも同じものを使う。

⚠ approvals.home はまだ無いので、決裁のみ利用者の行き先のテスト 3 本は
  次のタスクまで赤のまま。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: ログイン試行の制限

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/auth.php`
- Modify: `routes/web.php`（`throttle:5,1` → `throttle:login`）
- Rewrite: `tests/Feature/Auth/LoginThrottleTest.php`

- [ ] **Step 1: テストを書き直す**

`tests/Feature/Auth/LoginThrottleTest.php` を丸ごと差し替える:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ログイン試行の制限（設計書 §5.4・D3）。
 *
 * 2026-09-16 に数え方を変えた。以前は `throttle:5,1` で **IP だけ**で数え、**成功も 1 回に数えて**いたため、
 * 会社の PC のように外から同じ IP になる環境では、稼働日に 1 分 5 人しか入れず 6 人目から英語の 429 画面になった。
 * 今は「同じ ID と IP の失敗 5 回 / 分」＋「同じ IP の失敗 30 回 / 分」で、**成功は数えない**。
 *
 * ⚠ **リミッタを意図的に使い切るのでファイルを分けている。** `phpunit.xml` の `CACHE_STORE=array` と
 *   テストごとにアプリを作り直す仕組みで状態はテスト間へ漏れないが、他の認証テストで
 *   1 メソッドあたり 5 回を超えて POST しないこと。
 *
 * ⚠ **`assertStatus()` ではなく生のステータスコードで見る。** 差し戻し（302）に対して `assertStatus()` が
 *   失敗すると、Laravel がメッセージを組み立てる際にセッションの `errors` を読もうとして
 *   `Call to a member function all() on array` で落ちた理由が読めなくなる（Bug #49 の関連）。
 *
 * ⚠ **「止まったか」は HTTP ステータスでは判定できない。** `AppServiceProvider::loginThrottleResponse()` が
 *   制限超過時も `redirect()->route('login')`（302）を返すため（英語の素の 429 画面を避けるため。D3）、
 *   通常の失敗（`back()`、これも 302）と制限超過（同じく 302）は**ステータスコードが同じ**になる。
 *   見分けは `Retry-After` ヘッダーの有無で行う（`ThrottleRequests::getHeaders()` は制限超過時にしか
 *   このヘッダーを付けない。`X-RateLimit-Remaining` は両方に付くので使えない）。実測で確認済み。
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $loginId, string $password, string $ip = '198.51.100.1'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['login_id' => $loginId, 'password' => $password]);
    }

    /** まだ制限に掛かっていないこと（302 かつ Retry-After ヘッダー無し） */
    private function assertNotBlocked(\Illuminate\Testing\TestResponse $response, string $message): void
    {
        $this->assertSame(302, $response->getStatusCode(), $message);
        $this->assertFalse($response->headers->has('Retry-After'), $message);
    }

    /** 制限に掛かっていること（302 かつ Retry-After ヘッダー有り） */
    private function assertBlocked(\Illuminate\Testing\TestResponse $response, string $message): void
    {
        $this->assertSame(302, $response->getStatusCode(), $message);
        $this->assertTrue($response->headers->has('Retry-After'), $message);
    }

    public function test_five_failures_for_the_same_id_and_ip_are_allowed(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotBlocked($this->attempt('M001', 'wrong'), "{$i} 回目で止まっている（早すぎる）");
        }

        $this->assertBlocked($this->attempt('M001', 'wrong'), '6 回目が通っている');
    }

    /**
     * ⚠ 綴りを変えても同じ鍵になること。違う鍵なら 1 文字変えるだけで制限を回避できる。
     */
    public function test_the_key_ignores_case_width_and_spaces(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        foreach (['M001', 'm001', 'Ｍ００１', ' M001 ', 'm001'] as $typed) {
            $this->assertNotBlocked($this->attempt($typed, 'wrong'), "「{$typed}」で止まっている");
        }

        $this->assertBlocked($this->attempt('M001', 'wrong'), '綴りを変えると別の鍵になっている');
    }

    /**
     * **成功は数えない**（D3）。5 回入っても 6 回目が通ること。
     *
     * ⚠ これが本件の本体。`after()` を外すと、正しいパスワードでも 6 回目から 429 になる。
     */
    public function test_successful_logins_are_not_counted(): void
    {
        $user = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(302, $this->attempt('M001', 'password')->getStatusCode(), "{$i} 回目のログインが止まっている");
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    /** 無効なアカウントは「失敗」として数える（応答のあとも未ログインのまま） */
    public function test_inactive_accounts_are_counted_as_failures(): void
    {
        User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'status'               => \App\Enums\UserStatus::Inactive->value,
            'must_change_password' => false,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotBlocked($this->attempt('M001', 'password'), "{$i} 回目で止まっている（早すぎる）");
        }

        $this->assertBlocked($this->attempt('M001', 'password'), '6 回目が通っている');
    }

    /** 別々の ID なら 5 回では止まらない（同じ IP の上限 30 回までは通る） */
    public function test_different_ids_from_the_same_ip_are_not_blocked_at_five(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(302, $this->attempt("M{$i}", 'wrong')->getStatusCode(), "{$i} 人目で止まっている");
        }
    }

    /** 同じ IP から ID を変えても 30 回で止まる */
    public function test_thirty_failures_from_the_same_ip_stop_regardless_of_the_id(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertNotBlocked($this->attempt("M{$i}", 'wrong'), "{$i} 回目で止まっている（早すぎる）");
        }

        $this->assertBlocked($this->attempt('M999', 'wrong'), 'IP 全体の上限が効いていない');
    }

    /** 別の IP は巻き込まれない */
    public function test_another_ip_is_not_affected(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 6; $i++) {
            $this->attempt('M001', 'wrong', '198.51.100.1');
        }

        $this->assertSame(302, $this->attempt('M001', 'wrong', '198.51.100.2')->getStatusCode(), '別の IP まで止まっている');
    }

    /**
     * 止まったときは英語の 429 画面ではなく、ログイン画面に日本語で出す（D3）。
     *
     * ⚠ `resources/views/errors` が無いので、既定の 429 は英語の素の画面になる。
     */
    public function test_the_block_is_explained_in_japanese_on_the_login_screen(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('M001', 'wrong');
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->followingRedirects()
            ->post('/login', ['login_id' => 'M001', 'password' => 'wrong', 'remember' => '1']);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/ログインの試行回数が多すぎます。\d+秒後にもう一度お試しください。/u',
            $html,
            '日本語の案内と待ち時間が出ていない'
        );
        // 入力とチェックは残す
        $this->assertMatchesRegularExpression('/name="login_id"[^>]*value="M001"/', $html, '入力したログイン ID が消えている');
        $this->assertMatchesRegularExpression('/name="remember"[^>]*checked/', $html, '「ログイン状態を保持」が消えている');
    }

    /** 回数は設定から読む（数え方を変えずに数だけ動かせること） */
    public function test_the_limits_come_from_config(): void
    {
        $this->assertSame(5, config('auth.login_throttle.per_login_id'));
        $this->assertSame(30, config('auth.login_throttle.per_ip'));
        $this->assertNotNull(RateLimiter::limiter('login'), 'login という名前のリミッタが登録されていない');
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter LoginThrottleTest
```

期待: `test_successful_logins_are_not_counted` ほかが赤。

- [ ] **Step 3: `config/auth.php` に回数を足す**

ファイル末尾の `];` の直前に:

```php
    /*
    |--------------------------------------------------------------------------
    | ログイン試行の制限（決裁申請 段階1・設計書 §5.4）
    |--------------------------------------------------------------------------
    |
    | 失敗だけを数える。同じログイン ID と IP の組で per_login_id 回、同じ IP 全体で per_ip 回。
    | per_ip が大きいのは、会社の PC が外から同じ IP になりやすく、説明会で一斉にログインして
    | 初期パスワードの打ち間違いが重なっても止まらないようにするため。
    |
    */

    'login_throttle' => [
        'per_login_id'   => (int) env('LOGIN_THROTTLE_PER_LOGIN_ID', 5),
        'per_ip'         => (int) env('LOGIN_THROTTLE_PER_IP', 30),
        'decay_minutes'  => (int) env('LOGIN_THROTTLE_DECAY_MINUTES', 1),
    ],
```

- [ ] **Step 4: `AppServiceProvider::boot()` にリミッタを登録する**

```php
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerLoginRateLimiter();
    }

    /**
     * ログイン試行の制限（決裁申請 段階1・設計書 §5.4・D3）。
     *
     * 2 つの上限を同時に課す:
     *   ① 同じログイン ID・同じ IP の失敗 … 1 分に per_login_id 回
     *   ② 同じ IP の失敗（ID を問わない） … 1 分に per_ip 回
     *
     * ⚠ どちらも `after()` を持つ。`ThrottleRequests` は `afterCallback` があるときだけ
     *   「応答のあとに」数えるので、**成功したログインは数に入らない**（`Routing/Middleware/ThrottleRequests.php` 169 行）。
     *   `after()` を外すと、正しいパスワードでも 6 回目から 429 になる（以前の挙動）。
     *
     * ⚠ 鍵は `LoginId::throttleKey()` で正規化してから組む。生の入力で組むと、
     *   大文字小文字や全角を変えるだけで制限を回避できる。
     *
     * ⚠ 止まったときの応答は `response()` で作る。既定の 429 は英語の素の画面
     *   （`resources/views/errors` がアプリに無い）。
     */
    private function registerLoginRateLimiter(): void
    {
        $decay = (int) config('auth.login_throttle.decay_minutes');

        RateLimiter::for('login', function (Request $request) use ($decay) {
            // ⚠ 引数は `after($response)` で渡ってくるが見ない。判定は「応答のあとも未ログインか」
            //    （失敗・無効なアカウント・入力エラーはすべて未ログインのまま）
            $stillGuest = static fn ($response): bool => Auth::guest();

            return [
                Limit::perMinutes($decay, (int) config('auth.login_throttle.per_login_id'))
                    ->by(LoginId::throttleKey($request->input('login_id'), (string) $request->ip()))
                    ->after($stillGuest)
                    ->response(self::loginThrottleResponse(...)),

                Limit::perMinutes($decay, (int) config('auth.login_throttle.per_ip'))
                    ->by('ip|' . $request->ip())
                    ->after($stillGuest)
                    ->response(self::loginThrottleResponse(...)),
            ];
        });
    }

    /** 制限に掛かったときの応答（ログイン画面へ日本語で戻す） */
    private static function loginThrottleResponse(Request $request, array $headers): RedirectResponse
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return redirect()->route('login')
            ->withInput($request->only('login_id', 'remember'))
            ->withErrors(['login' => "ログインの試行回数が多すぎます。{$seconds}秒後にもう一度お試しください。"])
            ->withHeaders($headers);
    }
```

冒頭の `use` に次を足す:

```php
use App\Support\LoginId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
```

⚠ `Limit::perMinutes($decay, $max)` の引数の順番は **（分, 回数）**。逆にすると
「1 分に 5 回」が「5 分に 1 回」になり、テストの 5 回目で止まる。

- [ ] **Step 5: ルートを名前付きリミッタへ**

`routes/web.php`:

```php
// ゲスト（未認証）ユーザーのみアクセス可能
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // ブルートフォース対策（設計書 §5.4）: 失敗だけを「ID+IP」「IP」の 2 本で数える。
    // 定義は AppServiceProvider::registerLoginRateLimiter()
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
});
```

- [ ] **Step 6: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter LoginThrottleTest
```

期待: OK (9 tests)

- [ ] **Step 7: 全テスト**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -20
```

期待: 失敗は Task 3 Step 8 の 2 本だけ。

- [ ] **Step 8: コミット**

```bash
git add app/Providers/AppServiceProvider.php config/auth.php routes/web.php tests/Feature/Auth/LoginThrottleTest.php
git commit -m "$(cat <<'EOF'
feat(auth): ログイン試行の制限を失敗だけ数える形に変える

同じログイン ID と IP の失敗を 1 分 5 回、同じ IP 全体の失敗を 1 分 30 回に。
成功は数えない（Limit::after）。鍵は LoginId で正規化してから組むので、
大文字小文字や全角を変えても回避できない。止まったときはログイン画面へ戻して
日本語で待ち時間を出す（既定の 429 は英語の素の画面）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 5: 無効化した人の締め出しと、パスワード変更でほかの端末を切る

**Files:**
- Create: `app/Http/Middleware/EnsureUserIsActive.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Auth/InactiveUserLockoutTest.php`
- Test: `tests/Feature/Auth/OtherDeviceLogoutTest.php`

- [ ] **Step 1: 失敗するテストを書く（無効化）**

`tests/Feature/Auth/InactiveUserLockoutTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 無効化された人は、ログイン中でもその場で締め出す（設計書 §5.5・D7）。
 *
 * 2026-09-16 までは**ログインした瞬間しか**確かめておらず、「ログイン状態を保持」の端末では
 * 無効化のあとも最長 400 日（`SessionGuard::$rememberDuration` = 576000 分）使えた。
 *
 * ⚠ 文言はログイン時の既存のものにそろえる（D15）。
 */
class InactiveUserLockoutTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'このアカウントは無効になっています。管理者にお問い合わせください。';

    private function activeUser(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);
    }

    public function test_an_active_user_passes_through(): void
    {
        $this->actingAs($this->activeUser())->get('/dashboard/tenant')->assertOk();
    }

    /** ログイン中に無効化されたら、次の画面でログアウトさせる */
    public function test_a_user_disabled_mid_session_is_logged_out(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)->get('/dashboard/tenant')->assertOk();

        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/dashboard/tenant')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** 画面には理由を出す */
    public function test_the_reason_is_shown_on_the_login_screen(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->get('/dashboard/tenant');
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $html = $this->followingRedirects()->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString(self::MESSAGE, $html);
    }

    /** Ajax・JSON には 401（HTML の転送を返すと画面の JS が読めない） */
    public function test_ajax_requests_get_401(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->get('/dashboard/tenant');
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/dashboard/tenant', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertStatus(401);
    }

    /**
     * 「ログイン状態を保持」で入り直す端末も締め出す。
     *
     * ⚠ ここが D7 の本体。セッションを捨てても、鍵（remember_token）の Cookie があると
     *   `SessionGuard` が自動で入り直す。門番が無いとそのまま通ってしまう。
     *
     * ⚠ **鍵の Cookie を手で送り直すこと。** Laravel のテスト用の `$this->get()` は、
     *   前の応答の `Set-Cookie` を次のリクエストへ**自動では引き継がない**（BrowserKit と違う）。
     *   引き継がないまま書くと「セッションが無いからゲスト」になるだけで、**無効化してもしなくても
     *   同じ結果**になり、このテストは何も測らない（2026-09-16 に対照実験で実測。
     *   無効化を消しても緑のまま通った）。
     *
     * ⚠ **先に「鍵だけで入り直せること」を確かめる**（下の対照）。ここが通らないと、
     *   そのあとの「入り直せない」は鍵の仕組みが動いていないだけかもしれず、区別が付かない。
     */
    public function test_a_remembered_device_is_locked_out_too(): void
    {
        $user = User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);

        $login = $this->post('/login', ['login_id' => 'M001', 'password' => 'password', 'remember' => '1']);
        $this->assertAuthenticatedAs($user);

        $recallerName = Auth::guard()->getRecallerName();
        $recaller     = $login->getCookie($recallerName);
        $this->assertNotNull($recaller, '「ログイン状態を保持」の鍵が発行されていない');

        // 対照: セッションを捨てても、鍵だけで入り直せること（有効なうちは通る）
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($recallerName, $recaller->getValue())
            ->get('/dashboard/tenant')->assertOk();
        $this->assertAuthenticatedAs($user);

        // 本命: 無効化したら、同じ鍵では入り直せないこと
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->withCookie($recallerName, $recaller->getValue())
            ->get('/dashboard/tenant')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * ログアウトが鍵を作り直すので、ほかの端末の自動ログインも同時に無効になる。
     */
    public function test_the_remember_token_is_cycled(): void
    {
        $user = $this->activeUser();
        $before = $user->fresh()->remember_token;

        $this->actingAs($user)->get('/dashboard/tenant');
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();
        $this->get('/dashboard/tenant');

        $this->assertNotSame($before, $user->fresh()->remember_token, '鍵が作り直されていない（ほかの端末が入り直せてしまう）');
    }

    /** 未ログインの人は素通り（ログイン画面が 500 にならないこと） */
    public function test_guests_are_untouched(): void
    {
        $this->get('/login')->assertOk();
    }

    /**
     * 門番は**ルートモデル結合より前**で止めること（`bootstrap/app.php` の優先順）。
     *
     * ⚠ 後ろだと、無効化された人が「存在しない ID」を叩いたときだけ 404 が返り、
     *   404（無い）と転送（有る）の違いで**そのデータがあるかどうかが漏れる**。
     * ⚠ **この不変条件を守るテストがこれ 1 本**。`appendToPriorityList(...)` の呼び出しを
     *   丸ごと消しても、2026-09-16 時点の 1782 本は**すべて緑のまま**だった（実測）。
     *   優先順は `EnsureUserIsActive` の docblock だけが主張していて、誰も測っていなかった。
     */
    public function test_the_gate_runs_before_route_model_binding(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);

        // 有効なうちは、存在しない ID が 404 になる（＝このルートがモデル結合を使っている証拠）
        $this->actingAs($user)->get('/tenant/properties/999999')->assertNotFound();

        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/tenant/properties/999999')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: 失敗するテストを書く（ほかの端末）**

`tests/Feature/Auth/OtherDeviceLogoutTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * パスワードが変わったら、ほかの端末のログインを切る（設計書 §5.5・D11）。
 *
 * Laravel 標準の `Illuminate\Session\Middleware\AuthenticateSession` を web グループへ入れる。
 * セッションに控えたパスワードのハッシュと、今のハッシュを毎回突き合わせる仕組み。
 *
 * ⚠ 基幹の動きが変わる点: 本人がパスワードを変えると、ほかの端末（スマホなど）も切れる。
 * ⚠ 反映した瞬間に全員がログアウトされることはない（控えの無いセッションは初回に控えを作るだけ）。
 */
class OtherDeviceLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);
    }

    /** 反映直後は誰も切れない（控えを作るだけ） */
    public function test_an_existing_session_is_not_dropped_on_the_first_request(): void
    {
        $this->actingAs($this->user())->get('/dashboard/tenant')->assertOk();
        $this->get('/dashboard/tenant')->assertOk();
        $this->assertAuthenticated();
    }

    /**
     * 別の端末（＝管理者の再発行）でパスワードが変わったら、この端末は次の操作で落ちる。
     */
    public function test_a_password_change_elsewhere_logs_this_session_out(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/dashboard/tenant')->assertOk();

        // 別経路でパスワードだけ差し替える（管理者の再発行と同じ状態）
        $user->forceFill(['password' => Hash::make('brand-new-password')])->save();

        $this->get('/dashboard/tenant')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * 本人が変えた端末は残る。
     *
     * ⚠ `AuthenticateSession` は応答のあとに控えを作り直すので、変えた本人だけ生き残る。
     */
    public function test_the_device_that_changed_the_password_stays_signed_in(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/password/change')->assertOk();

        $this->put('/password/change', [
            'current_password'      => 'password',
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertRedirect(route('dashboard'));

        $this->get('/dashboard/tenant')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /** ミドルウェアが web グループに載っていること（順番は次のタスクで固定する） */
    public function test_the_middleware_is_registered_in_the_web_group(): void
    {
        $this->assertContains(
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'],
            'AuthenticateSession が web グループに無い'
        );
    }
}
```

- [ ] **Step 3: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'InactiveUserLockoutTest|OtherDeviceLogoutTest'
```

期待: 無効化の 5 本と、ほかの端末の 2 本が赤。

- [ ] **Step 4: `EnsureUserIsActive` を書く**

`app/Http/Middleware/EnsureUserIsActive.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 無効（status = inactive）な利用者を、ログイン中でもその場で締め出す（設計書 §5.5・D7）。
 *
 * ⚠ **web グループに置く**（ルートのミドルウェアではない）。ルート側に付ける方式だと、
 *   付け忘れた画面から入れてしまう（`/dashboard/tenant` には `role:` も `department.access` も無い）。
 *
 * ⚠ **`SubstituteBindings`（ルートモデル結合）より前**に走らせる（`bootstrap/app.php` の優先順）。
 *   後ろだと、存在しない ID で 404 が返って「そのデータがあるかどうか」が漏れる。
 *
 * ⚠ `Auth::logout()` は鍵（remember_token）を作り直すので、ほかの端末の自動ログインも
 *   同時に無効になる（`SessionGuard::cycleRememberToken`）。これが D7 の本体。
 */
class EnsureUserIsActive
{
    /** ログイン時の既存の文言にそろえる（D15） */
    public const MESSAGE = 'このアカウントは無効になっています。管理者にお問い合わせください。';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isActive()) {
            return $next($request);
        }

        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || $request->ajax()) {
            abort(401, self::MESSAGE);
        }

        return redirect()->route('login')->withErrors(['login' => self::MESSAGE]);
    }
}
```

- [ ] **Step 5: `bootstrap/app.php` に登録する**

```php
    ->withMiddleware(function (Middleware $middleware) {
        // ミドルウェアエイリアスの登録
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'department.access' => \App\Http\Middleware\CheckDepartmentAccess::class,
            'password.change' => \App\Http\Middleware\ForcePasswordChange::class,
        ]);

        // 決裁申請 段階1（設計書 §5.2・§5.5）
        //
        // ⚠ 3 本とも **web グループ**（全画面の入口）に置く。ルートごとに付ける方式は付け忘れが効かない。
        // ⚠ 順番は「パスワードの控えの確認 → 無効化 → 決裁のみの締め出し」。
        //    下の appendToPriorityList でこの順に並べ、SubstituteBindings（ルートモデル結合）より
        //    前で止める（存在しない ID でも 404 にならず、データの有無が漏れない）。
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\RestrictApprovalOnlyUsers::class,
        ]);

        // 既定の優先順は … AuthenticatesSessions → SubstituteBindings → Authorize
        // （`Foundation/Http/Kernel::$middlewarePriority`）。その間に 2 本を割り込ませる。
        $middleware->appendToPriorityList(
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        );
        $middleware->appendToPriorityList(
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\RestrictApprovalOnlyUsers::class,
        );
    })
```

⚠ **`RestrictApprovalOnlyUsers` はまだ存在しない**ので、この Step では上の 2 行（`RestrictApprovalOnlyUsers`
を指す行）を**まだ書かない**。Task 6 で足す。ここでは `AuthenticateSession` と `EnsureUserIsActive` の
2 本だけを `web(append: …)` に入れ、`appendToPriorityList` も 1 回だけにする。

- [ ] **Step 6: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'InactiveUserLockoutTest|OtherDeviceLogoutTest'
```

期待: OK (11 tests)

- [ ] **Step 7: 全テスト**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -30
```

期待: 失敗は Task 3 Step 8 の 2 本だけ。
⚠ **ここで既存テストが多数落ちたら、`AuthenticateSession` が原因**（`actingAs()` の直後に
パスワードを変えるテストがあると切れる）。落ちたテストを読み、**そのテストが本番の経路として
正しいかを判断してから**直すこと（テストを緩めて逃げない）。

- [ ] **Step 8: コミット**

```bash
git add app/Http/Middleware/EnsureUserIsActive.php bootstrap/app.php tests/Feature/Auth/InactiveUserLockoutTest.php tests/Feature/Auth/OtherDeviceLogoutTest.php
git commit -m "$(cat <<'EOF'
feat(auth): 無効な利用者をログイン中でも締め出す

web グループに EnsureUserIsActive を足し、無効になった人を次の画面でログアウトさせる
（ログアウトが鍵を作り直すので「ログイン状態を保持」の端末も入り直せなくなる）。
併せて Laravel 標準の AuthenticateSession を web グループへ入れ、パスワードが
変わったらほかの端末のログインを切る。

⚠ どちらもルートモデル結合より前に置く（存在しない ID で 404 を返さないため）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 決裁のホーム（仮）と、決裁のみ利用者の締め出し

**Files:**
- Create: `routes/approval.php`
- Create: `app/Http/Controllers/Approval/HomeController.php`
- Create: `resources/views/approvals/home.blade.php`
- Create: `app/Http/Middleware/RestrictApprovalOnlyUsers.php`
- Modify: `routes/web.php`（末尾で `routes/approval.php` を読み込む）
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Approval/ApprovalOnlyLockoutTest.php`
- Test: `tests/Feature/Approval/ApprovalHomeTest.php`

- [ ] **Step 1: 失敗するテストを書く（全ルートの締め出し・全件分類）**

`tests/Feature/Approval/ApprovalOnlyLockoutTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RestrictApprovalOnlyUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 決裁のみ利用者は決裁以外の全画面から締め出す（設計書 §5.2・§6・要件 15.6）。
 *
 * ⚠ **全件分類**（Top trap #13 / Bug #45 ①）。「直した画面を並べる」形だと、新しいルートが
 *   無検査のまま増える。`Route::getRoutes()` を機械的に 4 つに分け、どれにも入らなければ落とす。
 *
 * ⚠ **ロールだけでは守れない。** `/dashboard/tenant` には `role:` も `department.access` も
 *   付いておらず、全ロールが見られる（2026-09-15 実測）。守りの本体は web グループの門番。
 */
class ApprovalOnlyLockoutTest extends TestCase
{
    use RefreshDatabase;

    /** 門番を通す（決裁の画面・パスワード変更・ログアウト） */
    private const ALLOWED_NAMES = ['password.change', 'password.update', 'logout'];

    /**
     * web グループの外にあるルート（理由つきの固定リスト）。
     *
     * ⚠ 「web の外だから」で自動的に除外しない。**理由を書いて名指しする**
     *   （新しく web の外へ出たルートは、ここに足すまで落ちる）。
     */
    private const OUTSIDE_WEB = [
        'GET storage/{path}' => 'local ディスクの serve による署名つき URL（web グループの外）',
        'PUT storage/{path}' => '同上',
        'GET up'             => 'ヘルスチェック（withRouting の health）',
    ];

    private function approvalOnlyUser(): User
    {
        return User::factory()->approvalOnly()->create(['must_change_password' => false]);
    }

    /** `where` の条件を満たす値を作る。`|` で並んだリテラルなら先頭、それ以外は数字 */
    private function parameterValue(RoutingRoute $route, string $name): string
    {
        $where = $route->wheres[$name] ?? null;

        if (is_string($where) && preg_match('/\A[A-Za-z0-9_]+(\|[A-Za-z0-9_]+)*\z/', $where)) {
            return explode('|', $where)[0];
        }

        // 存在しない ID。ルートモデル結合より前で止まっていれば 404 にならない
        return '999999';
    }

    private function urlFor(RoutingRoute $route): string
    {
        $uri = $route->uri();

        foreach ($route->parameterNames() as $name) {
            $uri = str_replace(['{' . $name . '}', '{' . $name . '?}'], $this->parameterValue($route, $name), $uri);
        }

        return '/' . ltrim($uri, '/');
    }

    public function test_every_route_is_classified_and_blocked(): void
    {
        $user = $this->approvalOnlyUser();

        $checked = 0;
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $label  = $method . ' ' . $route->uri();
            $name   = $route->getName();
            $mw     = $route->gatherMiddleware();

            // ③ web の外
            if (isset(self::OUTSIDE_WEB[$label])) {
                continue;
            }

            if (! in_array('web', $mw, true)) {
                $problems[] = "{$label}: web グループの外なのに OUTSIDE_WEB に理由が書かれていない";
                continue;
            }

            // ② 未ログイン専用（ログイン済みの人は RedirectIfAuthenticated が追い返す）
            if (in_array('guest', $mw, true)) {
                continue;
            }

            // ① 許可リスト
            if ($name !== null && (str_starts_with($name, 'approvals.') || in_array($name, self::ALLOWED_NAMES, true))) {
                continue;
            }

            // ④ それ以外 — 実際に要求して止まることを見る
            $checked++;
            $url = $this->urlFor($route);

            // ⚠ 組み立てた URL が**そのルート自身**に当たることを確かめる。`where` の条件を
            //   満たさない値を入れると、ルーターが別のルートへ落ちるか 404 になり、
            //   「検査したつもりで別のものを見ていた」になる（Bug #45 の型）。
            //   いまは数字でない値を要求する `where` は無いが、足した人がここで気づける。
            if (! $route->matches(Request::create($url, $method), includingMethod: false)) {
                $problems[] = "{$label}: 組み立てた URL ({$url}) がこのルートに当たらない（where の条件を見直すこと）";

                continue;
            }

            $response = $this->actingAs($user)->call($method, $url);
            $status   = $response->getStatusCode();

            if ($method === 'GET') {
                if ($status !== 302 || $response->headers->get('Location') !== route('approvals.home')) {
                    $problems[] = "{$label}: 決裁のホームへ転送されない（status={$status} location=" . $response->headers->get('Location') . ')';
                }
            } elseif ($status !== 403) {
                $problems[] = "{$label}: 403 で拒否されない（status={$status}）";
            }

            if ($status === 404) {
                $problems[] = "{$label}: 404 になった（ルートモデル結合より後ろで止まっている＝データの有無が漏れる）";
            }
            if ($status >= 500) {
                $problems[] = "{$label}: {$status} になった";
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（2026-09-15 実測で全 430 本）
        $this->assertGreaterThan(400, $checked, 'ルートの走査に失敗している');
        $this->assertSame([], $problems, "決裁のみ利用者を止められていないルート:\n" . implode("\n", $problems));
    }

    /**
     * `approvals.` の名前を名乗れるのは**決裁のコントローラだけ**であること。
     *
     * ⚠ 門番（`RestrictApprovalOnlyUsers`）も上の全件分類も、「ルート名が `approvals.` で
     *   始まるか」という**同じ基準**で「安全」と判断している。だから誰かが機微なルートの名前を
     *   `approvals.` に付け替えると、**門番は通し、分類のテストも検査対象から外す**
     *   ＝ 二重に見落とす（Bug #45 の型）。名前の付け先を別の軸（コントローラの名前空間）で
     *   縛って、その共犯関係を断つ。
     */
    public function test_only_approval_controllers_may_claim_the_approvals_name(): void
    {
        $offenders = [];
        $found = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'approvals.')) {
                continue;
            }

            $found++;
            $controller = (string) $route->getAction('controller');

            if (! str_starts_with($controller, 'App\\Http\\Controllers\\Approval\\')) {
                $offenders[] = $route->getName() . ' => ' . ($controller ?: '(クロージャ)');
            }
        }

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThan(0, $found, 'approvals. のルートが 1 本も見つからない');

        $this->assertSame(
            [],
            $offenders,
            "決裁のコントローラ以外が approvals. の名前を名乗っています（門番が素通しします）:\n" . implode("\n", $offenders)
        );
    }

    /** 決裁の画面には入れる */
    public function test_the_approval_home_is_reachable(): void
    {
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.home'))->assertOk();
    }

    /** パスワード変更とログアウトも通る */
    public function test_password_change_and_logout_are_allowed(): void
    {
        $user = $this->approvalOnlyUser();

        $this->actingAs($user)->get(route('password.change'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    }

    /** 転送されたホームに理由が出る */
    public function test_the_reason_is_shown_after_the_redirect(): void
    {
        $html = $this->actingAs($this->approvalOnlyUser())
            ->followingRedirects()->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString('決裁以外の画面は使えません。', $html);
    }

    /** Ajax は転送でなく 403（画面の JS が HTML を読まされないように） */
    public function test_ajax_requests_get_403(): void
    {
        $this->actingAs($this->approvalOnlyUser())
            ->get('/dashboard/tenant', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertStatus(403);
    }

    /** 基幹を使う人は今までどおり */
    public function test_base_users_are_untouched(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $this->actingAs($staff)->get('/dashboard/tenant')->assertOk();
    }

    /**
     * 二重の守り: ロールを見るミドルウェアも決裁のみ利用者を拒む（設計書 §5.2）。
     */
    public function test_role_and_department_middleware_also_reject_approval_only_users(): void
    {
        $this->actingAs($this->approvalOnlyUser());

        $request = \Illuminate\Http\Request::create('/x');
        $request->setUserResolver(fn () => auth()->user());

        $next = fn () => response('through');

        try {
            (new \App\Http\Middleware\CheckRole())->handle($request, $next, 'executive', 'manager', 'staff');
            $this->fail('CheckRole が決裁のみ利用者を通した');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            (new \App\Http\Middleware\CheckDepartmentAccess())->handle($request, $next, 'tenant');
            $this->fail('CheckDepartmentAccess が決裁のみ利用者を通した');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * 門番の位置（設計書 §5.2）。
     *
     * ⚠ 挙動のテスト（404 にならないこと）と**対で**固定する。片方だけだと、
     *   優先順の登録を消しても「たまたま順番が合っていて緑」になりうる。
     */
    public function test_the_gates_run_before_route_model_binding(): void
    {
        // ⚠ `Router::$middlewarePriority` は既定で空。`Illuminate\Contracts\Http\Kernel` が
        //    解決されて初めて `appendToPriorityList` の内容が同期される（`ApplicationBuilder` の
        //    afterResolving フック）。このテストは HTTP を出さないので、この 1 行が無いと
        //    **実装の正誤に関係なく必ず**「優先順のリストに無い」で落ちる（実測）。
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $priority = array_values(app('router')->middlewarePriority ?? []);

        $positions = [];
        foreach ([
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            EnsureUserIsActive::class,
            RestrictApprovalOnlyUsers::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ] as $class) {
            $index = array_search($class, $priority, true);
            $this->assertNotFalse($index, "{$class} が優先順のリストに無い");
            $positions[$class] = $index;
        }

        $this->assertTrue(
            $positions[\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class]
                < $positions[EnsureUserIsActive::class]
                && $positions[EnsureUserIsActive::class] < $positions[RestrictApprovalOnlyUsers::class]
                && $positions[RestrictApprovalOnlyUsers::class] < $positions[\Illuminate\Routing\Middleware\SubstituteBindings::class],
            '門番が AuthenticatesSessions の後・SubstituteBindings の前に並んでいない'
        );
    }
}
```

- [ ] **Step 2: 失敗するテストを書く（決裁のホーム）**

`tests/Feature/Approval/ApprovalHomeTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 決裁のホーム（段階1 は仮。設計書 §5.15）。
 */
class ApprovalHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_placeholder_and_the_signed_in_person(): void
    {
        $user = User::factory()->approvalOnly()->create([
            'name'                 => '決裁 太郎',
            'employee_number'      => 'M001',
            'must_change_password' => false,
        ]);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        $this->assertStringContainsString('決裁の機能は準備中です。', $html);
        $this->assertStringContainsString('決裁 太郎', $html);
        $this->assertStringContainsString('M001', $html);
        $this->assertStringContainsString(route('password.change'), $html);
    }

    /** ログイン ID はメールアドレスのこともある */
    public function test_it_falls_back_to_the_email_as_the_login_id(): void
    {
        $user = User::factory()->approvalOnly()->create([
            'employee_number'      => null,
            'email'                => 'user@example.com',
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get(route('approvals.home'))->assertOk()
            ->assertSee('user@example.com');
    }

    /** 基幹を使う人が URL を直接開いても見られる（段階1 ではメニューから案内しない） */
    public function test_base_users_can_open_it_directly(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $this->actingAs($staff)->get(route('approvals.home'))->assertOk();
    }

    /** 未ログインはログイン画面へ */
    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get(route('approvals.home'))->assertRedirect(route('login'));
    }

    /** 初回ログインの決裁のみ利用者はパスワード変更へ送られる */
    public function test_a_first_time_user_is_sent_to_the_password_screen(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('approvals.home'))->assertRedirect(route('password.change'));
    }
}
```

- [ ] **Step 3: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalOnlyLockoutTest|ApprovalHomeTest'
```

期待: `Route [approvals.home] not defined.`

- [ ] **Step 4: `routes/approval.php` を作る**

```php
<?php

use App\Http\Controllers\Approval\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 決裁申請（段階1 で 1 ルート。段階2 以降で増える）
|--------------------------------------------------------------------------
|
| ルート名はすべて `approvals.` で始める。web グループの門番 RestrictApprovalOnlyUsers が
| この接頭辞で「決裁の画面」を判定するので、**別の接頭辞を使わないこと**。
|
| このファイルは routes/web.php の末尾から読み込む（`auth` と `password.change` の
| グループの中で読むので、ここでミドルウェアを重ねて書かない）。
|
*/

Route::get('/approvals', [HomeController::class, 'index'])->name('approvals.home');
```

- [ ] **Step 5: `routes/web.php` から読み込む**

末尾の `| 以降のルートはSTEP 12以降で追加` のコメントブロックの直前に:

```php
    /*
    |----------------------------------------------------------------------
    | 決裁申請（1ルート）
    |----------------------------------------------------------------------
    */
    require __DIR__ . '/approval.php';
```

⚠ **`Route::middleware(['auth', 'password.change'])->group(...)` の中で読む。**
外に出すと決裁のホームが未ログインでも開き、初回のパスワード変更も効かない。

- [ ] **Step 6: コントローラとビューを書く**

`app/Http/Controllers/Approval/HomeController.php`:

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 決裁のホーム（設計書 §5.15）。
 *
 * ⚠ 段階1 は**仮の画面**。段階2 で本当のホーム（要件定義書 13 章 ①）に置き換える。
 *   決裁の機能はまだ何も無いので、ここに機能を足していかないこと。
 */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('approvals.home', [
            'user'    => $user,
            'loginId' => $user->employee_number ?? $user->email,
        ]);
    }
}
```

`resources/views/approvals/home.blade.php`:

```blade
@extends('layouts.app')

@section('title', '決裁申請')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">決裁申請</span>
@endsection

@section('content')
<div class="max-w-[640px]">

    @if(session('warning'))
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            {{ session('warning') }}
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-4">決裁申請</h1>

    <div class="bg-white rounded-lg border border-gray-200 px-5 py-5">
        <p class="text-[13px] text-gray-700 leading-relaxed mb-4">
            決裁の機能は準備中です。使い始める日が決まったらお知らせします。
        </p>

        <dl class="text-[13px] text-gray-700 space-y-1.5 mb-5">
            <div class="flex gap-3">
                <dt class="w-[6em] shrink-0 text-gray-500">氏名</dt>
                <dd class="font-medium text-gray-900">{{ $user->name }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-[6em] shrink-0 text-gray-500">ログインID</dt>
                <dd class="font-mono text-gray-900">{{ $loginId }}</dd>
            </div>
        </dl>

        <div class="flex flex-wrap gap-3 text-[13px]">
            <a href="{{ route('password.change') }}" class="text-emerald-600 hover:underline">パスワードを変更する</a>
            @if($user->isApprovalAdmin())
                <a href="{{ route('approvals.admin.users.index') }}" class="text-emerald-600 hover:underline">利用者の管理</a>
                <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
            @endif
        </div>
    </div>
</div>
@endsection
```

⚠ **`isApprovalAdmin()` と 2 本のルートは Task 7 / 11 / 12 まで存在しない。**
このタスクでは `@if($user->isApprovalAdmin())` のブロックを**書かず**、Task 12 の最後で足す
（先に書くと `Route [approvals.admin.users.index] not defined.` で決裁のホームが 500 になる）。

- [ ] **Step 7: `RestrictApprovalOnlyUsers` を書く**

`app/Http/Middleware/RestrictApprovalOnlyUsers.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 決裁のみ利用者を、決裁以外の全画面から締め出す（設計書 §5.2・要件 15.6）。
 *
 * ⚠ **web グループに置く**（ルートのミドルウェアではない）。`role:` や `department.access` は
 *   付いていない画面があり（`/dashboard/tenant` は 2026-09-15 実測でどちらも無い）、
 *   ロールだけでは守れない。ここが守りの本体で、`role:` / `department.access` は二重の守り。
 *
 * ⚠ **`SubstituteBindings`（ルートモデル結合）より前**に走らせる（`bootstrap/app.php` の優先順）。
 *   後ろだと存在しない ID で 404 が返り、「そのデータがあるかどうか」が漏れる。
 *
 * 通すのは 3 種類だけ:
 *   - ルート名が `approvals.` で始まるもの（決裁の画面）
 *   - `password.change` / `password.update` / `logout`
 *   - `guest` ミドルウェアを持つルート（未ログイン専用。ログイン済みの人は RedirectIfAuthenticated が追い返す）
 *
 * 全ルートを 4 つに分類して検査する `ApprovalOnlyLockoutTest` が、新しいルートを自動で検査対象にする。
 */
class RestrictApprovalOnlyUsers
{
    public const MESSAGE = '決裁以外の画面は使えません。';

    private const ALLOWED_NAMES = ['password.change', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isApprovalOnly()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax() || ! $request->isMethod('GET')) {
            abort(403, self::MESSAGE);
        }

        return redirect()->route('approvals.home')->with('warning', self::MESSAGE);
    }

    private function isAllowed(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $name = $route->getName();

        if ($name !== null && (str_starts_with($name, 'approvals.') || in_array($name, self::ALLOWED_NAMES, true))) {
            return true;
        }

        return in_array('guest', $route->gatherMiddleware(), true);
    }
}
```

⚠ **`$request->isMethod('GET')` は HEAD を含まない。** Laravel は HEAD を GET ルートへ回すが
`Request::method()` は `HEAD` を返すので、HEAD は 403 になる。画面を開く経路ではないので問題ない
（テストは GET だけを見る）。

- [ ] **Step 8: `bootstrap/app.php` に足す**

Task 5 Step 5 の形にして、`web(append: …)` の 3 本目と `appendToPriorityList` の 2 回目を有効にする。

- [ ] **Step 9: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalOnlyLockoutTest|ApprovalHomeTest|LoginIdentifierTest|UserIdentityTest'
```

期待: すべて OK。Task 3 で赤だった 2 本もここで緑になる。

⚠ **「赤が消えた」で終わらせないこと**（Task 3 のコード品質レビューが変異 C で実測した穴）。
この 2 本は `route('approvals.home')` を**期待値の組み立て**で呼んでいるので、Task 3 の時点では
そこで例外が出て落ちており、**コントローラの実際の転送先を一度も比べていなかった**。
実測では `User::homeRouteName()` を旧ロジック（`isExecutive()` だけ）に戻す変異を当てても
新しい失敗が増えなかった。**ここで同じ変異を当て、決裁のみ利用者の行き先が
本当に検出されることを確かめること。**

- [ ] **Step 10: 全テスト**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -20
```

期待: **失敗 0**。ここでベースライン（1711）＋今までの追加分がすべて緑になる。

- [ ] **Step 11: コミット**

```bash
git add routes/approval.php routes/web.php app/Http/Controllers/Approval/ resources/views/approvals/ app/Http/Middleware/RestrictApprovalOnlyUsers.php bootstrap/app.php tests/Feature/Approval/
git commit -m "$(cat <<'EOF'
feat(approval): 決裁のみ利用者を決裁以外の画面から締め出す

web グループに RestrictApprovalOnlyUsers を足し、決裁のみ利用者が使えるのを
approvals.* とパスワード変更・ログアウトだけにする。画面を開く GET は決裁のホームへ
転送し、それ以外は 403。ルートモデル結合より前で止めるので、存在しない ID でも
404 にならずデータの有無が漏れない。

仮の決裁のホーム（/approvals）と routes/approval.php も足した。

テストは Route::getRoutes() の全件を 4 つに分類し、どれにも入らないルートを
実際に要求して止まることを見る（新しいルートが無検査のまま増えない）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 7: 決裁の表 7 つと、設定の変更の記録

**Files:**
- Create: `database/migrations/2026_09_16_000001_create_approval_tables.php`
- Create: `database/sql/2026-09-16-approval-phase1.sql`
- Create: `app/Models/ApprovalMember.php` / `ApprovalSetting.php` / `ApprovalCompany.php` / `ApprovalDepartment.php` / `ApprovalMailDomain.php` / `ApprovalSettingLog.php`
- Create: `app/Support/Approval/SettingLogger.php`
- Modify: `app/Models/User.php`（決裁のリレーションとヘルパー）
- Test: `tests/Feature/Approval/ApprovalTablesTest.php`
- Test: `tests/Feature/Approval/SettingLogTest.php`

- [ ] **Step 1: 失敗するテストを書く（表とリレーション）**

`tests/Feature/Approval/ApprovalTablesTest.php`:

```php
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
```

- [ ] **Step 2: 失敗するテストを書く（記録）**

`tests/Feature/Approval/SettingLogTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\SettingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 設定の変更の記録（設計書 §5.14・要件 14.2）。
 *
 * ⚠ 追記のみ。あとから書き換えられないことをモデルで強制する。
 * ⚠ パスワードそのものは、どこにも残さない。
 */
class SettingLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_who_what_and_the_change(): void
    {
        $actor  = User::factory()->create(['name' => '管理 花子', 'must_change_password' => false]);
        $target = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($actor)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_USER_AGENT' => 'TestAgent/1.0'])
            ->get('/dashboard/tenant');

        SettingLogger::record('user.updated', 'user', $target->id, ['name' => '旧'], ['name' => '新']);

        $log = ApprovalSettingLog::sole();

        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame('user.updated', $log->action);
        $this->assertSame('user', $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame(['name' => '旧'], $log->old_values);
        $this->assertSame(['name' => '新'], $log->new_values);
        $this->assertSame('198.51.100.9', $log->ip_address);
        $this->assertSame('TestAgent/1.0', $log->user_agent);
        $this->assertNotNull($log->created_at);
    }

    /** 変わった項目だけを残す（全項目を積むと差分が読めない） */
    public function test_it_keeps_only_the_changed_keys(): void
    {
        $actor = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($actor);

        SettingLogger::recordChange('user.updated', 'user', 1, ['name' => 'A', 'email' => 'x@example.com'], ['name' => 'B', 'email' => 'x@example.com']);

        $log = ApprovalSettingLog::sole();

        $this->assertSame(['name' => 'A'], $log->old_values);
        $this->assertSame(['name' => 'B'], $log->new_values);
    }

    /** 何も変わっていなければ 1 行も足さない */
    public function test_it_records_nothing_when_there_is_no_change(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]));

        SettingLogger::recordChange('user.updated', 'user', 1, ['name' => 'A'], ['name' => 'A']);

        $this->assertSame(0, ApprovalSettingLog::count());
    }

    /** あとから書き換えられない（設計書 §5.14） */
    public function test_a_log_cannot_be_updated(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]));
        SettingLogger::record('user.updated', 'user', 1, [], []);

        $log = ApprovalSettingLog::sole();

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_a_log_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]));
        SettingLogger::record('user.updated', 'user', 1, [], []);

        $this->expectException(\RuntimeException::class);
        ApprovalSettingLog::sole()->delete();
    }

    /** 更新日時の列を持たない（持つと「書き換えられる想定」に見える） */
    public function test_the_table_has_no_updated_at(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('approval_setting_logs', 'updated_at'),
            'approval_setting_logs に updated_at がある（追記のみの表なので持たない）'
        );
    }
}
```

- [ ] **Step 3: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalTablesTest|SettingLogTest'
```

期待: `Class "App\Models\ApprovalCompany" not found` ほか。

- [ ] **Step 4: テスト用 migration を書く**

`database/migrations/2026_09_16_000001_create_approval_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階1 の表（設計書 §5.16）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-09-16-approval-phase1.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること。**
 *
 * ⚠ 基幹の `departments` / `department_user` とは別の表（D1）。
 * ⚠ 変更前後の列は `before` / `after` にしない（MySQL の予約語 BEFORE と紛らわしい）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedTinyInteger('fiscal_start_month')->default(1)->comment('期の始まりの月（1〜12）');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('approval_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('approval_companies')->restrictOnDelete();
            $table->string('name', 50);
            $table->string('short_name', 6)->comment('データ印の上段に入るので 6 文字まで');
            $table->string('code', 3)->unique()->comment('英大文字 1〜3 文字。申請番号に使う');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'uq_approval_departments_company_name');
        });

        Schema::create('approval_department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('approval_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id', 'idx_approval_dept_user_user');
        });

        Schema::create('approval_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('can_view_all')->default(false)->comment('全件閲覧者（段階2 で使う）');
            $table->boolean('is_admin')->default(false)->comment('決裁の管理者');
            $table->timestamps();
        });

        Schema::create('approval_settings', function (Blueprint $table) {
            $table->id();
            // ⚠ 利用者は SoftDelete なので、削除で外部キーが壊れることはない
            $table->foreignId('president_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('approval_mail_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 255)->unique();
            $table->timestamps();
        });

        Schema::create('approval_setting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('action', 50);
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // ⚠ updated_at は持たない（追記のみ。SettingLogTest が固定している）
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id'], 'idx_approval_logs_target');
            $table->index('actor_user_id', 'idx_approval_logs_actor');
            $table->index('created_at', 'idx_approval_logs_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_setting_logs');
        Schema::dropIfExists('approval_mail_domains');
        Schema::dropIfExists('approval_settings');
        Schema::dropIfExists('approval_members');
        Schema::dropIfExists('approval_department_user');
        Schema::dropIfExists('approval_departments');
        Schema::dropIfExists('approval_companies');
    }
};
```

- [ ] **Step 5: モデルを書く**

`app/Models/ApprovalCompany.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 決裁の会社（設計書 §5.8）。期の始まりの月を持つ（要件 6.2）。
 */
class ApprovalCompany extends Model
{
    protected $fillable = ['name', 'fiscal_start_month', 'sort_order'];

    protected function casts(): array
    {
        return ['fiscal_start_month' => 'integer', 'sort_order' => 'integer'];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(ApprovalDepartment::class, 'company_id')->orderBy('sort_order')->orderBy('id');
    }
}
```

`app/Models/ApprovalDepartment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 決裁の部門（設計書 §5.8）。
 *
 * ⚠ 基幹の `Department` とは別物。基幹の部門はデータを見られる範囲（tenant / realestate …）で、
 *   こちらは申請の宛先と申請番号に使う（D1）。
 */
class ApprovalDepartment extends Model
{
    protected $fillable = ['company_id', 'name', 'short_name', 'code', 'sort_order'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'sort_order' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ApprovalCompany::class, 'company_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'approval_department_user', 'department_id', 'user_id')
                    ->withPivot('created_at');
    }
}
```

`app/Models/ApprovalMember.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用者ごとの決裁の印（設計書 §5.16）。
 *
 * ⚠ 印に使う文字の列は段階4 で足す（D17）。
 */
class ApprovalMember extends Model
{
    protected $fillable = ['user_id', 'can_view_all', 'is_admin'];

    protected function casts(): array
    {
        return ['can_view_all' => 'boolean', 'is_admin' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/ApprovalSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 決裁の設定（設計書 §5.16）。**常に 1 行だけ**（id = 1）。
 *
 * 本番の SQL が最初の 1 行（社長は未設定）を入れる。テストでは `current()` が無ければ作る。
 */
class ApprovalSetting extends Model
{
    public const SINGLETON_ID = 1;

    public $timestamps = true;
    public const CREATED_AT = null;

    protected $fillable = ['president_user_id'];

    protected function casts(): array
    {
        return ['president_user_id' => 'integer'];
    }

    /**
     * 唯一の設定行。無ければ作る。
     *
     * ⚠ `firstOrCreate(['id' => 1])` は使わない。`id` は `$fillable` に無いので
     *   作成時に落ち、自動採番に任せることになる（空でない表では 1 にならない）。
     */
    public static function current(): self
    {
        return static::find(self::SINGLETON_ID) ?? tap(new self(), function (self $row): void {
            $row->id = self::SINGLETON_ID;
            $row->save();
        });
    }

    /** ⚠ 社長は決裁のみ利用者のことも基幹を使う人のこともある（要件 3.1） */
    public function president(): BelongsTo
    {
        return $this->belongsTo(User::class, 'president_user_id');
    }
}
```

`app/Models/ApprovalMailDomain.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 通知メールを送ってよいドメイン（設計書 §5.8）。
 *
 * ⚠ **完全一致**で判定する（サブドメインは別に登録する）。使い道は CSV のメールアドレスの検査と、
 *   通知メールの宛先の制限（要件 8.2）の 2 つ。
 */
class ApprovalMailDomain extends Model
{
    protected $fillable = ['domain'];

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->domain = self::normalize($row->domain);
        });
    }

    /** 前後の空白を落とし、`@` が付いていたら外して小文字にする */
    public static function normalize(?string $value): string
    {
        $value = trim(mb_convert_kana((string) $value, 'as'));
        $value = ltrim($value, '@');

        return mb_strtolower($value, 'UTF-8');
    }

    /** そのメールアドレスが許可されたドメインか（1 件も登録されていなければ false） */
    public static function allows(?string $email): bool
    {
        if ($email === null || ! str_contains($email, '@')) {
            return false;
        }

        $domain = mb_strtolower(substr($email, strrpos($email, '@') + 1), 'UTF-8');

        return static::where('domain', $domain)->exists();
    }
}
```

`app/Models/ApprovalSettingLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 設定の変更の記録（設計書 §5.14・要件 14.2）。**追記のみ。**
 *
 * ⚠ 更新日時の列を持たず、更新と削除をモデルで拒む。画面にも変更・削除の手段を作らない。
 * ⚠ パスワードそのものは、どこにも残さない（`SettingLogger` が受け取らない）。
 */
class ApprovalSettingLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id', 'action', 'target_type', 'target_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('設定の変更の記録は書き換えられません。');
        });

        static::deleting(function (): void {
            throw new RuntimeException('設定の変更の記録は削除できません。');
        });
    }

    public function actor(): BelongsTo
    {
        // 記録した人が後から削除されても記録は読めること
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
```

`app/Support/Approval/SettingLogger.php`:

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalSettingLog;

/**
 * 設定の変更を 1 行記録する（設計書 §5.14）。
 *
 * 呼び出し側が「誰が・IP・端末」を毎回書かなくて済むように 1 本化する。
 *
 * ⚠ **パスワードを渡さない。** 再発行を記録するときは `['password' => '…']` のような
 *   値を入れず、`user.password_reissued` という action だけを残す。
 */
final class SettingLogger
{
    /** 変更の前後をそのまま記録する */
    public static function record(string $action, string $targetType, ?int $targetId, array $old = [], array $new = []): ApprovalSettingLog
    {
        return ApprovalSettingLog::create([
            'actor_user_id' => auth()->id(),
            'action'        => $action,
            'target_type'   => $targetType,
            'target_id'     => $targetId,
            'old_values'    => $old,
            'new_values'    => $new,
            'ip_address'    => request()->ip(),
            'user_agent'    => mb_substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /**
     * 変わった項目だけを記録する。何も変わっていなければ何も足さない。
     *
     * ⚠ 全項目を積むと、あとで差分が読めない（記録の意味が薄れる）。
     */
    public static function recordChange(string $action, string $targetType, ?int $targetId, array $old, array $new): ?ApprovalSettingLog
    {
        $changedOld = [];
        $changedNew = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old) || $old[$key] !== $value) {
                $changedOld[$key] = $old[$key] ?? null;
                $changedNew[$key] = $value;
            }
        }

        if ($changedNew === []) {
            return null;
        }

        return self::record($action, $targetType, $targetId, $changedOld, $changedNew);
    }
}
```

- [ ] **Step 6: `User` に決裁のリレーションとヘルパーを足す**

`app/Models/User.php` の `loginHistories()` の下:

```php
    /** 決裁の印（全件閲覧者・決裁の管理者） */
    public function approvalMember(): HasOne
    {
        return $this->hasOne(ApprovalMember::class);
    }

    /**
     * 決裁の所属部門（兼務可。設計書 §5.8）。
     *
     * ⚠ 基幹の `departments()` とは**別の表**（D1）。片方を変えてももう片方は変わらない。
     */
    public function approvalDepartments(): BelongsToMany
    {
        return $this->belongsToMany(ApprovalDepartment::class, 'approval_department_user', 'user_id', 'department_id')
                    ->withPivot('created_at');
    }
```

ヘルパー（`isApprovalOnly()` の下）:

```php
    /** 決裁の管理者に指定されているか（`approvals.admin.*` の門番が見る） */
    public function isApprovalAdmin(): bool
    {
        return (bool) $this->approvalMember?->is_admin;
    }

    /** 全件閲覧者か（段階2 で使う） */
    public function canViewAllApprovals(): bool
    {
        return (bool) $this->approvalMember?->can_view_all;
    }

    /** 決裁の社長に指定されているか */
    public function isApprovalPresident(): bool
    {
        return ApprovalSetting::current()->president_user_id === $this->id;
    }

    /**
     * 決裁の権限（社長・全件閲覧者・決裁の管理者）を 1 つでも持っているか。
     *
     * ⚠ D16 の判定。ここに該当する人への「再発行・無効化と有効化・氏名と社員番号の修正」は
     *   基幹の管理者だけができる（決裁の管理者が指定された人になりすますのを防ぐ）。
     */
    public function hasApprovalPrivileges(): bool
    {
        return $this->isApprovalPresident() || $this->isApprovalAdmin() || $this->canViewAllApprovals();
    }

    /** D16 に当たるとき、画面に出す理由 */
    public function approvalPrivilegeLabel(): ?string
    {
        return match (true) {
            $this->isApprovalPresident() => '社長',
            $this->isApprovalAdmin()     => '決裁の管理者',
            $this->canViewAllApprovals() => '全件閲覧者',
            default                      => null,
        };
    }
```

`use` に `Illuminate\Database\Eloquent\Relations\HasOne;` を足す。

- [ ] **Step 7: 本番の SQL を書く**

`database/sql/2026-09-16-approval-phase1.sql`（**Task 0 の読み取り結果に合わせて型と照合順序を確定させる**）:

```sql
-- 決裁申請 段階1 — 2026-09-16
--
-- 設計書: docs/superpowers/specs/2026-09-16-approval-phase1-design.md §5.16
--
-- ⚠ tests/... ではなく database/migrations/2026_09_16_000001_create_approval_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** ログインが employee_number を読むので、
--   コードを先に送るとログインが Unknown column で 500 になる。
--
-- ⚠ 型・照合順序・索引名は 2026-09-16 に本番の SHOW CREATE TABLE を読んで合わせてある
--   （`users` は utf8mb4_unicode_ci・一意索引は Laravel 既定の `users_email_unique` の形）。
--   `users_employee_number_unique` はテスト用 migration の `->unique()` が付ける名前と同じ。
--
-- 適用: php artisan tinker --execute で DB::statement() に **1 文ずつ**流す
--   （sudo mysql は非対話でパスワードを渡せない。PDO::MYSQL_ATTR_MULTI_STATEMENTS も未設定）

-- 1. 利用者に社員番号を足し、メールアドレスを任意にする
ALTER TABLE `users`
  ADD COLUMN `employee_number` VARCHAR(20) NULL COMMENT '社員番号（ログインID）' AFTER `name`,
  ADD UNIQUE KEY `users_employee_number_unique` (`employee_number`);

ALTER TABLE `users`
  MODIFY COLUMN `email` VARCHAR(255) NULL;

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('executive','manager','staff','approval_only') NOT NULL DEFAULT 'staff';

-- 2. 決裁の表
CREATE TABLE `approval_companies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `fiscal_start_month` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '期の始まりの月（1〜12）',
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_approval_companies_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_departments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `short_name` VARCHAR(6) NOT NULL COMMENT 'データ印の上段',
  `code` VARCHAR(3) NOT NULL COMMENT '英大文字 1〜3 文字',
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_departments_code` (`code`),
  UNIQUE KEY `uq_approval_departments_company_name` (`company_id`, `name`),
  CONSTRAINT `fk_approval_departments_company` FOREIGN KEY (`company_id`) REFERENCES `approval_companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_department_user` (
  `department_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`department_id`, `user_id`),
  KEY `idx_approval_dept_user_user` (`user_id`),
  CONSTRAINT `fk_approval_dept_user_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_dept_user_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `can_view_all` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '全件閲覧者',
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '決裁の管理者',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_approval_members_user` (`user_id`),
  CONSTRAINT `fk_approval_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `president_user_id` BIGINT UNSIGNED NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_settings_president` (`president_user_id`),
  CONSTRAINT `fk_approval_settings_president` FOREIGN KEY (`president_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_mail_domains` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_approval_mail_domains_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_setting_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_logs_target` (`target_type`, `target_id`),
  KEY `idx_approval_logs_actor` (`actor_user_id`),
  KEY `idx_approval_logs_created` (`created_at`),
  CONSTRAINT `fk_approval_logs_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 最初のデータ（要件 6.2 の 3 社。名前と月は画面で直せる）
INSERT INTO `approval_settings` (`id`, `president_user_id`, `updated_at`) VALUES (1, NULL, NOW());

INSERT INTO `approval_companies` (`name`, `fiscal_start_month`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('ミツワ都市開発', 5, 1, NOW(), NOW()),
  ('DAD', 6, 2, NOW(), NOW()),
  ('ZEAL', 6, 3, NOW(), NOW());
```

- [ ] **Step 8: 通ることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalTablesTest|SettingLogTest'
```

期待: OK (18 tests)

- [ ] **Step 9: 全テスト → コミット**

> ### ⚠ 実装後のレビューで直したこと（`4cd6a6b5`。上のコードはこの修正込みが正）
>
> どれも**テストが 1 本も無かった**ので、併せて 15 本足した。5 通りの変異で赤を実測済み。
>
> | # | 直したこと | なぜ |
> |---|---|---|
> | C | `ApprovalSetting::president()` に `withTrashed()` | 社長に指定された人を**論理削除**すると `president` が null になり、社長名を出す画面が `Attempt to read property "name" on null` で 500。外部キーの `ON DELETE SET NULL` は論理削除では**発火しない**（Top trap #18） |
> | I | `ApprovalMember::user()` に `withTrashed()` | 同じ理由（決裁の権限を持つ人の一覧が 500） |
> | I | `ApprovalSetting::current()` をリクエストの間 1 回だけに | `User::isApprovalPresident()` が呼ぶので、**20 人の一覧で 41 クエリ**（実測）。`forget()` も併せて用意 |
> | I | `SettingLogger` の比較を型にまたがる形に | 素の `!==` は DB の `'5'` と画面の `5` を**常に「変わった」**と数える（実測）。`null` と `''` は別物のまま |
> | I | `ApprovalMailDomain::allows()` は `@` が**ちょうど 1 つ**のときだけ | `foo@bar@mitsuwat.co.jp` が許可を通っていた（実測）。CSV の取込という未検証のデータが通る経路 |
> | M | 一意索引の名前を migration と `database/sql` でそろえた | 本番は手で SQL を流すので、migration の名前を借りて `DROP INDEX` を書くと失敗する |


```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add database/migrations/2026_09_16_000001_create_approval_tables.php database/sql/2026-09-16-approval-phase1.sql app/Models/ app/Support/Approval/ tests/Feature/Approval/
git commit -m "$(cat <<'EOF'
feat(approval): 決裁の表 7 つと設定の変更の記録を足す

会社・部門・所属部門・決裁の印・設定（社長）・許可するドメイン・変更の記録。
基幹の departments とは別の表にして、CSV で決裁の所属部門を変えても基幹の
権限が動かないようにする（要件 12.3）。

記録は追記のみ（updated_at を持たず、モデルが更新と削除を拒む）。

⚠ テスト用 migration と database/sql の DDL は対で維持すること。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 8: ログイン案内の画面（QR・1 回限りの鍵）

**Files:**
- Modify: `composer.json` / `composer.lock`（`chillerlan/php-qrcode ^6.0`）
- Create: `app/Support/LoginQrCode.php`
- Create: `app/Support/OneTimeAction.php`
- Create: `app/Support/Approval/LoginGuide.php`
- Create: `resources/views/approvals/login-guide.blade.php`
- Test: `tests/Unit/Support/LoginQrCodeTest.php`
- Test: `tests/Feature/Approval/LoginGuideTest.php`

- [ ] **Step 1: QR の部品を入れる**

```bash
composer require chillerlan/php-qrcode:^6.0
```

期待: `chillerlan/php-qrcode 6.0.1` と `chillerlan/php-settings-container` の 2 つが入る。
⚠ **`^7.0` にしない**（PHP 8.4 を要求する。本番は 8.3）。

```bash
composer show chillerlan/php-qrcode | grep -E 'versions|php '
```

- [ ] **Step 2: 失敗するテストを書く（QR）**

`tests/Unit/Support/LoginQrCodeTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\LoginQrCode;
use PHPUnit\Framework\TestCase;

/**
 * ログイン画面の QR（設計書 §5.12・D4）。
 *
 * ⚠ 1 枚あたり約 10KB あるので、案内のページでは `<symbol>` を 1 つ置いて各ページは `<use>` で
 *   参照する（200 人ぶんを丸ごと繰り返すと約 1.9MB。2026-09-16 実測）。
 *   そのため部品は `<svg>` 丸ごとではなく **viewBox と中身**を返す。
 */
class LoginQrCodeTest extends TestCase
{
    public function test_it_returns_a_viewbox_and_inner_markup(): void
    {
        $parts = LoginQrCode::symbolParts('https://example.com/system/manage/index.php/login');

        $this->assertMatchesRegularExpression('/\A0 0 \d+ \d+\z/', $parts['viewBox'], 'viewBox の形が想定と違う');
        $this->assertStringStartsWith('<path', $parts['inner'], '中身が <path> で始まらない');
        $this->assertStringNotContainsString('<svg', $parts['inner'], '外側の <svg> が残っている');
        $this->assertStringNotContainsString('<script', $parts['inner']);
        $this->assertStringNotContainsString('<?xml', $parts['inner']);
    }

    /** 同じ URL なら同じ絵（ページごとに作り直さない） */
    public function test_it_is_deterministic(): void
    {
        $a = LoginQrCode::symbolParts('https://example.com/login');
        $b = LoginQrCode::symbolParts('https://example.com/login');

        $this->assertSame($a, $b);
    }

    public function test_different_urls_produce_different_codes(): void
    {
        $a = LoginQrCode::symbolParts('https://example.com/login');
        $b = LoginQrCode::symbolParts('https://example.com/other');

        $this->assertNotSame($a['inner'], $b['inner']);
    }

    /** 長い URL でも通る（バージョンが自動で上がる） */
    public function test_a_long_url_still_works(): void
    {
        $parts = LoginQrCode::symbolParts('https://example.com/' . str_repeat('a', 200));

        $this->assertStringStartsWith('<path', $parts['inner']);
    }
}
```

- [ ] **Step 3: `LoginQrCode` を書く**

`app/Support/LoginQrCode.php`:

```php
<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

/**
 * ログイン画面の QR コードを SVG で作る（設計書 §5.12・D4）。
 *
 * ⚠ **`<svg>` 丸ごとではなく viewBox と中身を返す。** 1 枚 約 10KB あり、200 人ぶんを
 *   ページごとに繰り返すと約 1.9MB になる（2026-09-16 実測）。案内のページは
 *   `<symbol>` を 1 つ置いて各ページは `<use>` で参照する。
 *
 * ⚠ 出力の形が変わったら**黙って壊れず例外にする**（部品を上げたときに気づけるように）。
 */
final class LoginQrCode
{
    /**
     * @return array{viewBox: string, inner: string}
     */
    public static function symbolParts(string $url): array
    {
        $options = new QROptions([
            'outputInterface'      => QRMarkupSVG::class,
            'outputBase64'         => false,
            // インラインで埋めるので XML 宣言は要らない
            'svgAddXmlHeader'      => false,
            // CSS に頼らず fill="#000" を属性で入れる（印刷で色が落ちない）
            'svgUseFillAttributes' => true,
            'connectPaths'         => true,
            // 明るいマスは描かない（紙は白なので不要。ファイルも小さくなる）
            'drawLightModules'     => false,
            'eccLevel'             => EccLevel::L,
            'addQuietzone'         => true,
            'quietzoneSize'        => 2,
            'cssClass'             => 'login-qr',
        ]);

        $svg = (new QRCode($options))->render($url);

        if (! preg_match('#<svg[^>]*\sviewBox="([^"]+)"[^>]*>(.*)</svg>#s', $svg, $m)) {
            throw new RuntimeException('QR コードの SVG を解釈できませんでした（部品の出力形式が変わった可能性があります）。');
        }

        return ['viewBox' => $m[1], 'inner' => trim($m[2])];
    }
}
```

- [ ] **Step 4: 失敗するテストを書く（案内の画面）**

`tests/Feature/Approval/LoginGuideTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use App\Support\Approval\LoginGuide;
use App\Support\OneTimeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ログイン案内の画面（設計書 §5.12・D4・D9）。
 *
 * ⚠ 初期パスワードは**この画面にだけ**出る。DB（ハッシュを除く）・セッション・ログ・記録に残さない。
 * ⚠ 1 回限りの鍵で二重の実行を止める（ブラウザの「フォームを再送信しますか」で
 *   印刷済みの案内がこっそり無効になるのを防ぐ）。
 */
class LoginGuideTest extends TestCase
{
    use RefreshDatabase;

    private function guide(): LoginGuide
    {
        $a = User::factory()->create(['name' => '甲 一郎', 'employee_number' => 'M001', 'email' => null, 'must_change_password' => true]);
        $b = User::factory()->create(['name' => '乙 二郎', 'employee_number' => null, 'email' => 'b@example.com', 'must_change_password' => true]);

        return new LoginGuide([
            ['user' => $a, 'password' => 'abcde23456'],
            ['user' => $b, 'password' => 'fghij78923'],
        ], notifiedCount: 1, skippedCount: 1);
    }

    private function render(): \Illuminate\Testing\TestResponse
    {
        $admin = User::factory()->create(['must_change_password' => false]);

        return $this->actingAs($admin)->get('/_test/login-guide');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 描画だけを見るための使い捨てルート（この計画の実装では本物の POST から描画する）
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])
            ->get('/_test/login-guide', fn () => $this->guide()->toResponse(request()));
    }

    /** 保存させない（戻るボタンや履歴からパスワードが読めないように） */
    public function test_the_page_is_not_cached(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->render()->headers->get('Cache-Control'));
    }

    public function test_it_shows_one_page_per_person(): void
    {
        $html = $this->render()->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="guide-page"'), '1 人 1 ページになっていない');
        $this->assertStringContainsString('甲 一郎', $html);
        $this->assertStringContainsString('乙 二郎', $html);
        $this->assertStringContainsString('abcde23456', $html);
        $this->assertStringContainsString('fghij78923', $html);
    }

    /** ログイン ID は社員番号、無ければメールアドレス */
    public function test_the_login_id_falls_back_to_the_email(): void
    {
        $html = $this->render()->getContent();

        $this->assertStringContainsString('M001', $html);
        $this->assertStringContainsString('b@example.com', $html);
    }

    public function test_it_embeds_the_qr_once_and_uses_it_per_page(): void
    {
        $html = $this->render()->getContent();

        $this->assertSame(1, substr_count($html, '<symbol id="login-qr"'), 'QR の定義が 1 つでない');
        $this->assertSame(2, substr_count($html, 'href="#login-qr"'), '各ページが QR を参照していない');
        $this->assertStringContainsString(route('login'), $html, 'ログイン画面の URL が出ていない');
    }

    /** サイドバーもヘッダーも出さない（印刷用の独立したページ） */
    public function test_it_is_a_standalone_page(): void
    {
        $html = $this->render()->getContent();

        $this->assertStringNotContainsString('sidebarExpanded', $html, 'サイドバーが出ている');
        $this->assertStringContainsString('@page', $html, '印刷用の @page 指定が無い');
        $this->assertStringContainsString('break-after', $html, 'ページ区切りの指定が無い');
    }

    /** 画面にだけ出る帯（印刷しない） */
    public function test_the_on_screen_bar_warns_before_closing(): void
    {
        $html = $this->render()->getContent();

        $this->assertStringContainsString('この画面を閉じると初期パスワードは二度と表示されません。', $html);
        $this->assertStringContainsString('印刷する', $html);
        $this->assertStringContainsString('2 人分', $html);
        $this->assertStringContainsString('通知メール: 送る 1 人／送らない 1 人', $html);
        $this->assertStringContainsString('beforeunload', $html, '閉じる前の確認が無い');
    }

    /** セッションにパスワードが入らない（sessions テーブルに平文が残らない） */
    public function test_the_password_never_touches_the_session(): void
    {
        $this->render();

        $this->assertStringNotContainsString('abcde23456', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
    }

    /** 1 回限りの鍵 */
    public function test_a_token_can_be_claimed_only_once(): void
    {
        $token = OneTimeAction::issue();

        $this->assertTrue(OneTimeAction::claim($token));
        $this->assertFalse(OneTimeAction::claim($token), '同じ鍵で 2 回目が通った');
    }

    public function test_different_tokens_are_independent(): void
    {
        $this->assertTrue(OneTimeAction::claim(OneTimeAction::issue()));
        $this->assertTrue(OneTimeAction::claim(OneTimeAction::issue()));
    }

    /** 鍵そのものは保存しない（ハッシュだけ） */
    public function test_the_raw_token_is_not_stored(): void
    {
        $token = OneTimeAction::issue();
        OneTimeAction::claim($token);

        $this->assertFalse(Cache::has($token), '鍵がそのままキャッシュのキーになっている');
        $this->assertTrue(Cache::has(OneTimeAction::cacheKey($token)));
    }
}
```

- [ ] **Step 5: `OneTimeAction` を書く**

`app/Support/OneTimeAction.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 1 回だけ実行してよい操作の鍵（設計書 §5.12）。
 *
 * ログイン案内を出す POST（新規登録・再発行・CSV の確定）に鍵を入れておき、処理した鍵を覚えておく。
 * ブラウザの「フォームを再送信しますか」で続けてしまうと、**印刷済みの案内がこっそり無効になる**ため。
 *
 * ⚠ 鍵そのものをキャッシュのキーにしない（キャッシュの保管先に生の値が残る）。
 */
final class OneTimeAction
{
    public static function issue(): string
    {
        return Str::random(40);
    }

    /** 初めての鍵なら true。2 回目以降は false */
    public static function claim(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return Cache::add(
            self::cacheKey($token),
            true,
            now()->addHours((int) config('approval.guide_token_ttl_hours'))
        );
    }

    public static function cacheKey(string $token): string
    {
        return 'once:' . hash('sha256', $token);
    }
}
```

- [ ] **Step 6: `LoginGuide` を書く**

`app/Support/Approval/LoginGuide.php`:

```php
<?php

namespace App\Support\Approval;

use App\Models\User;
use App\Support\LoginQrCode;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン案内（1 人 1 ページ・A4・ブラウザで印刷）の応答（設計書 §5.12・D4）。
 *
 * 基幹の新規登録・基幹の再発行・CSV の確定・決裁の管理者の再発行がすべてこれを返す。
 *
 * ⚠ **リダイレクトしない。** POST の応答としてその場で描く（初期パスワードをセッションに入れないため）。
 * ⚠ `Cache-Control: no-store` を付ける（戻るボタンや履歴からパスワードを読ませない）。
 * ⚠ QR の URL は**その場のリクエストから**作る（`route('login')`）。キューや設定に頼ると
 *   本番で `/index.php` が抜ける（要件 15.1）。
 */
final class LoginGuide implements Responsable
{
    /**
     * @param  list<array{user: User, password: string}>  $entries
     * @param  int  $notifiedCount  通知メールを送る人数
     * @param  int  $skippedCount   送らない人数（メールアドレスなし・許可していないドメイン）
     */
    public function __construct(
        private readonly array $entries,
        private readonly int $notifiedCount = 0,
        private readonly int $skippedCount = 0,
    ) {}

    public function toResponse($request): Response
    {
        $loginUrl = route('login');

        return response()
            ->view('approvals.login-guide', [
                'entries'       => $this->entries,
                'loginUrl'      => $loginUrl,
                'qr'            => LoginQrCode::symbolParts($loginUrl),
                'notifiedCount' => $this->notifiedCount,
                'skippedCount'  => $this->skippedCount,
                'issuedAt'      => now()->format('Y年n月j日'),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
```

- [ ] **Step 7: 案内のビューを書く**

`resources/views/approvals/login-guide.blade.php`:

```blade
{{--
    ログイン案内（設計書 §5.12）。

    ⚠ レイアウトを継承しない独立した HTML。サイドバー・ヘッダーを出さないことを構造で保証する。
    ⚠ @vite を使わない（withoutVite() のテストでも本番でも同じものが出る）。
    ⚠ @page の余白を 0 にして、ブラウザが余白へ日時や URL を印刷しないようにする。
       実際の余白はページの中の要素で取る。
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ログインのご案内</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Hiragino Sans", "Noto Sans JP", Meiryo, sans-serif; color: #111827; background: #F3F4F6; }

        /* 画面にだけ出る帯 */
        .guide-bar { position: sticky; top: 0; z-index: 10; background: #FFFBEB; border-bottom: 1px solid #FDE68A; padding: 12px 16px; font-size: 13px; line-height: 1.7; }
        .guide-bar strong { color: #92400E; }
        .guide-bar button, .guide-bar a { font: inherit; }
        .guide-print-btn { padding: 6px 14px; background: #059669; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
        .guide-back { margin-left: 12px; color: #4B5563; }

        .guide-page { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 24mm 20mm; background: #fff; break-after: page; }
        .guide-page:last-child { break-after: auto; }

        .guide-title { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
        .guide-system { font-size: 13px; color: #6B7280; margin: 0 0 24px; }
        .guide-name { font-size: 16px; margin: 0 0 20px; }

        .guide-creds { display: flex; gap: 20mm; align-items: flex-start; border: 1px solid #D1D5DB; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; }
        .guide-creds dl { margin: 0; flex: 1; }
        .guide-creds dt { font-size: 12px; color: #6B7280; margin-bottom: 2px; }
        .guide-creds dd { margin: 0 0 14px; font-family: "SFMono-Regular", Consolas, Menlo, monospace; font-size: 20px; letter-spacing: 0.08em; word-break: break-all; }
        .guide-qr { width: 32mm; height: 32mm; flex: 0 0 auto; }
        .guide-url { font-size: 11px; color: #4B5563; word-break: break-all; text-align: center; margin-top: 4px; }

        .guide-steps { font-size: 13px; line-height: 2; padding-left: 1.4em; margin: 0 0 18px; }
        .guide-notes { font-size: 12px; color: #4B5563; line-height: 1.9; border-top: 1px solid #E5E7EB; padding-top: 12px; margin: 0; list-style: none; padding-left: 0; }
        .guide-issued { font-size: 11px; color: #9CA3AF; margin-top: 18px; }

        @media print {
            body { background: #fff; }
            .guide-bar { display: none; }
            .guide-page { margin: 0; box-shadow: none; }
        }
        @media screen {
            .guide-page { box-shadow: 0 1px 4px rgba(0,0,0,0.12); margin-bottom: 16px; }
        }
    </style>
</head>
<body>

{{-- 画面にだけ出る帯（印刷されない） --}}
<div class="guide-bar">
    <button type="button" class="guide-print-btn" onclick="window.print()">印刷する</button>
    <span style="margin-left: 12px;">{{ count($entries) }} 人分</span>
    <span style="margin-left: 12px;">通知メール: 送る {{ $notifiedCount }} 人／送らない {{ $skippedCount }} 人（メールアドレスなし・許可していないドメイン）</span>
    <a href="{{ url()->previous() }}" class="guide-back">元の画面へ戻る</a>
    <div><strong>この画面を閉じると初期パスワードは二度と表示されません。印刷してから閉じてください。</strong></div>
</div>

{{-- QR は全員同じなので 1 つだけ置き、各ページは <use> で参照する（1 枚 約 10KB あるため） --}}
<svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute">
    <symbol id="login-qr" viewBox="{{ $qr['viewBox'] }}">{!! $qr['inner'] !!}</symbol>
</svg>

@foreach($entries as $entry)
    <section class="guide-page">
        <h1 class="guide-title">ログインのご案内</h1>
        <p class="guide-system">ミツワ都市開発 経営管理システム</p>

        <p class="guide-name">{{ $entry['user']->name }} 様</p>

        <div class="guide-creds">
            <dl>
                <dt>ログインID</dt>
                <dd>{{ $entry['user']->employee_number ?? $entry['user']->email }}</dd>
                <dt>初期パスワード</dt>
                <dd>{{ $entry['password'] }}</dd>
            </dl>
            <div>
                <svg class="guide-qr" role="img" aria-label="ログイン画面のQRコード"><use href="#login-qr"></use></svg>
                <div class="guide-url">{{ $loginUrl }}</div>
            </div>
        </div>

        <ol class="guide-steps">
            <li>QRコードを読み取るか、上のURLを開きます</li>
            <li>ログインIDと初期パスワードを入力します</li>
            <li>新しいパスワードを決めます（8文字以上・英字と数字を含む）</li>
        </ol>

        <ul class="guide-notes">
            <li>初期パスワードは、初回ログインのあとは使えなくなります。</li>
            <li>この紙は本人以外に見せないでください。</li>
            <li>新しいパスワードに変えたら、この紙は破棄してください。</li>
        </ul>

        <p class="guide-issued">発行日: {{ $issuedAt }}</p>
    </section>
@endforeach

<script>
    // 印刷せずに閉じようとしたら確認する（D9）。印刷したあとは確認しない
    var guidePrinted = false;
    window.addEventListener('afterprint', function () { guidePrinted = true; });
    window.addEventListener('beforeunload', function (e) {
        if (guidePrinted) { return; }
        e.preventDefault();
        e.returnValue = '';
    });
</script>

</body>
</html>
```

- [ ] **Step 8: 通ることを確かめる → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'LoginQrCodeTest|LoginGuideTest'
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add composer.json composer.lock app/Support/ resources/views/approvals/login-guide.blade.php tests/
git commit -m "$(cat <<'EOF'
feat(approval): ログイン案内の印刷ページと 1 回限りの鍵を足す

確定・再発行の POST の応答としてその場で描く 1 人 1 ページ（A4）の案内。
初期パスワードはこの画面にだけ出し、セッションにもログにも残さない
（Cache-Control: no-store）。QR はサーバーで SVG を作り、全員同じなので
<symbol> を 1 つ置いて各ページは <use> で参照する（1 枚 約 10KB のため）。

QR の部品は chillerlan/php-qrcode ^6.0（ext-mbstring だけ・GD も Imagick も
要らない。^7.0 は PHP 8.4 が必要なので使わない）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: パスワード再発行の通知メール

**Files:**
- Create: `app/Mail/PasswordReissuedMail.php`
- Create: `resources/views/mail/password-reissued.blade.php`
- Create: `app/Support/Approval/PasswordReissuer.php`
- Test: `tests/Feature/Approval/PasswordReissueTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/PasswordReissueTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\PasswordReissuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * パスワード再発行と通知メール（設計書 §5.13・要件 12.5・8.1・8.2）。
 *
 * ⚠ 新しいパスワードは**メールに書かない**（紙で渡す）。身に覚えのない再発行に気づくための通知。
 * ⚠ 送るのは**許可するドメイン**のメールアドレスを持つ人だけ（要件 8.2）。
 */
class PasswordReissueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => '管理 花子', 'must_change_password' => false]);
    }

    public function test_it_replaces_the_password_and_requires_a_change(): void
    {
        $this->actingAs($this->admin());

        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        $old  = $user->password;

        $result = (new PasswordReissuer())->reissue(collect([$user]));

        $user->refresh();
        $this->assertNotSame($old, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($result->entries[0]['password'], $user->password));
    }

    /** 強度 10（初回ログインで自動的に掛け直される） */
    public function test_the_new_password_uses_cost_10(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        (new PasswordReissuer())->reissue(collect([$user]));

        $this->assertSame(10, password_get_info($user->fresh()->password)['options']['cost']);
    }

    public function test_it_notifies_only_allowed_domains(): void
    {
        $this->actingAs($this->admin());

        $ok      = User::factory()->create(['email' => 'ok@mitsuwat.co.jp', 'must_change_password' => false]);
        $outside = User::factory()->create(['email' => 'ng@gmail.com', 'must_change_password' => false]);
        $noMail  = User::factory()->create(['email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$ok, $outside, $noMail]));

        $this->assertSame(1, $result->notifiedCount);
        $this->assertSame(2, $result->skippedCount);

        Mail::assertQueued(PasswordReissuedMail::class, 1);
        Mail::assertQueued(PasswordReissuedMail::class, fn (PasswordReissuedMail $mail) => $mail->hasTo('ok@mitsuwat.co.jp'));
    }

    /** 本文に新しいパスワードを書かない（設計書 §5.13） */
    public function test_the_mail_does_not_contain_the_new_password(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->create(['name' => '甲 一郎', 'email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$user]));
        $password = $result->entries[0]['password'];

        Mail::assertQueued(PasswordReissuedMail::class, function (PasswordReissuedMail $mail) use ($password) {
            $body = $mail->render();

            $this->assertStringNotContainsString($password, $body, '新しいパスワードがメールに書かれている');
            $this->assertStringContainsString('甲 一郎', $body);
            $this->assertStringContainsString('管理 花子', $body, '実施した管理者の氏名が無い');
            $this->assertStringContainsString('身に覚えがない場合', $body);
            $this->assertStringContainsString(route('login'), $body, 'ログイン画面の URL が無い');

            return true;
        });
    }

    /** キューに積む（5 分おきの定期実行で送る。段階0 の土台） */
    public function test_the_mail_is_queued(): void
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new PasswordReissuedMail(
            User::factory()->create(['must_change_password' => false]),
            '管理 花子',
            now(),
            'https://example.com/login',
        ));
    }

    /** 記録が 1 人 1 行残る（パスワードそのものは残さない） */
    public function test_it_records_each_reissue_without_the_password(): void
    {
        $this->actingAs($this->admin());

        $a = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        $b = User::factory()->create(['email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$a, $b]));

        $this->assertSame(2, ApprovalSettingLog::where('action', 'user.password_reissued')->count());

        $dumped = ApprovalSettingLog::all()->toJson();
        foreach ($result->entries as $entry) {
            $this->assertStringNotContainsString($entry['password'], $dumped, 'パスワードが記録に残っている');
        }
    }

    /** ドメインが 1 件も登録されていなければ誰にも送らない */
    public function test_no_mail_is_sent_when_no_domain_is_registered(): void
    {
        ApprovalMailDomain::query()->delete();
        $this->actingAs($this->admin());

        $user = User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);

        $result = (new PasswordReissuer())->reissue(collect([$user]));

        $this->assertSame(0, $result->notifiedCount);
        Mail::assertNothingQueued();
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter PasswordReissueTest
```

- [ ] **Step 3: メールの型を書く**

`app/Mail/PasswordReissuedMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * パスワードを再発行したことの通知（設計書 §5.13・要件 12.5）。
 *
 * ⚠ **新しいパスワードは書かない。** 紙（ログイン案内）で本人に渡す。
 *   このメールは「身に覚えのない再発行に気づけるように」するためのもの。
 * ⚠ ログイン画面の URL は**呼び出し側から渡す**。キューの中で `route()` を呼ぶと
 *   `APP_URL` に頼ることになり、本番で `/index.php` が抜ける（要件 15.1）。
 */
class PasswordReissuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // 段階0 の OpsTestMail と同じ方針: 1 回の失敗でそのまま failed() を呼ばせ、laravel.log に残す
    public $tries = 1;

    public function __construct(
        public User $recipient,
        public string $actorName,
        public CarbonInterface $reissuedAt,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【経営管理システム】パスワードを再発行しました');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reissued');
    }

    public function failed(Throwable $e): void
    {
        Log::error('パスワード再発行の通知メールを送れませんでした（宛先: ' . implode('、', array_column($this->to, 'address')) . '）: ' . $e->getMessage());
    }
}
```

`resources/views/mail/password-reissued.blade.php`:

```blade
{{ $recipient->name }} 様

経営管理システムのパスワードを再発行しました。

再発行した日時: {{ $reissuedAt->format('Y年n月j日 H:i') }}
実施した管理者: {{ $actorName }}

新しい初期パスワードは、管理者から紙でお受け取りください（このメールには記載していません）。

身に覚えがない場合は、すぐに管理者に連絡してください。

ログイン画面: {{ $loginUrl }}

※ このメールはシステムから自動で送っています。
```

- [ ] **Step 4: `PasswordReissuer` を書く**

`app/Support/Approval/PasswordReissuer.php`:

```php
<?php

namespace App\Support\Approval;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\InitialPassword;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * パスワードの再発行（設計書 §5.7・§5.9・§5.13）。
 *
 * 基幹の管理者の再発行・決裁の管理者の再発行（1 人・まとめて）がすべてここを通る。
 *
 * ⚠ 平文は戻り値（画面に出すため）にだけ載せ、DB・セッション・ログ・記録には残さない。
 * ⚠ **誰を再発行してよいか**の判断はここでしない（呼び出し側の権限の問題。D8 / D16）。
 */
final class PasswordReissuer
{
    /** @param  Collection<int, User>  $users */
    public function reissue(Collection $users): ReissueResult
    {
        $actorName = auth()->user()?->name ?? '';
        $loginUrl  = route('login');
        $now       = now();

        $entries  = [];
        $notified = 0;
        $skipped  = 0;

        foreach ($users as $user) {
            $password = InitialPassword::generate();

            // ⚠ password は $fillable にあるが hashed キャストが掛かるので、強度 10 の
            //    ハッシュを直接入れるために forceFill で属性ごと差し替える
            $user->forceFill([
                'password'             => InitialPassword::hash($password),
                'must_change_password' => true,
            ])->save();

            $entries[] = ['user' => $user, 'password' => $password];

            SettingLogger::record('user.password_reissued', 'user', $user->id);

            if (ApprovalMailDomain::allows($user->email)) {
                Mail::to($user->email)->queue(new PasswordReissuedMail($user, $actorName, $now, $loginUrl));
                $notified++;
            } else {
                $skipped++;
            }
        }

        return new ReissueResult($entries, $notified, $skipped);
    }
}
```

`app/Support/Approval/ReissueResult.php`:

```php
<?php

namespace App\Support\Approval;

/**
 * 再発行の結果（設計書 §5.12 の案内の画面に渡す）。
 *
 * @property-read list<array{user: \App\Models\User, password: string}> $entries
 */
final class ReissueResult
{
    public function __construct(
        public readonly array $entries,
        public readonly int $notifiedCount,
        public readonly int $skippedCount,
    ) {}

    public function toGuide(): LoginGuide
    {
        return new LoginGuide($this->entries, $this->notifiedCount, $this->skippedCount);
    }
}
```

- [ ] **Step 5: 通ることを確かめる → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter PasswordReissueTest
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add app/Mail/PasswordReissuedMail.php resources/views/mail/password-reissued.blade.php app/Support/Approval/ tests/Feature/Approval/PasswordReissueTest.php
git commit -m "$(cat <<'EOF'
feat(approval): パスワード再発行と通知メールを 1 本化する

再発行の処理（強度 10 の初期パスワード・初回変更の要求・記録・通知メール）を
PasswordReissuer にまとめ、基幹と決裁の両方の再発行が同じものを通るようにする。

通知メールに新しいパスワードは書かない（紙で渡す）。送るのは許可するドメインの
メールアドレスを持つ人だけ。URL は呼び出し側のリクエストから渡す（キューの中で
route() を呼ぶと本番で /index.php が抜ける）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 10: 基幹の利用者管理の改修

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Modify: `resources/views/admin/users/index.blade.php`
- Modify: `routes/web.php`（社長の指定のルート 1 本）
- Test: `tests/Feature/Admin/UserManagementApprovalTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Admin/UserManagementApprovalTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApprovalMember;
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

    private function createForm(array $overrides = []): array
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.store') . '"');

        return [$form, array_merge($form['fields'], $overrides)];
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

    /** 再発行は案内の画面を返し、セッションに平文を入れない（D12） */
    public function test_reissue_renders_the_guide_and_keeps_nothing_in_the_session(): void
    {
        $target = User::factory()->create(['name' => '甲 一郎', 'email' => null, 'employee_number' => 'M001', 'must_change_password' => false]);

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.resetPassword', $target) . '"');

        $response = $this->actingAs($this->executive())->post($form['action'], $form['fields']);

        $response->assertOk();
        $this->assertStringContainsString('ログインのご案内', $response->getContent());
        $this->assertNull(session('reset_password'), '平文がセッションに入っている');
    }

    /** 一覧から「再発行結果」の表示が消えている */
    public function test_the_old_reset_password_banner_is_gone(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $this->assertStringNotContainsString("session('reset_password')", $html);
        $this->assertStringNotContainsString('新しい初期パスワード', $html);
    }

    /** 社長の指定（有効でメールアドレスのある人だけ） */
    public function test_the_president_can_be_designated(): void
    {
        $candidate = User::factory()->create(['name' => '社長 三郎', 'email' => 'p@example.com', 'must_change_password' => false]);

        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('admin.users.president') . '"');

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
    }

    /** 社長に指定された人は無効化・削除・メールアドレスを空にできない（設計書 §5.7） */
    public function test_the_president_is_protected(): void
    {
        $president = User::factory()->create(['email' => 'p@example.com', 'employee_number' => 'M001', 'must_change_password' => false]);
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

    /** 全件閲覧者・決裁の管理者の指定 */
    public function test_approval_flags_can_be_set_from_the_edit_form(): void
    {
        $target = User::factory()->create(['employee_number' => 'M001', 'email' => 'a@example.com', 'must_change_password' => false]);
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
    }

    /** 新規登録のロールの選択肢に「決裁のみ」は出さない（CSV で作る） */
    public function test_the_create_form_does_not_offer_approval_only(): void
    {
        $html = $this->actingAs($this->executive())->get('/admin/users')->assertOk()->getContent();

        $create = substr($html, strpos($html, route('admin.users.store')));
        $create = substr($create, 0, strpos($create, '</form>'));

        $this->assertStringNotContainsString(UserRole::ApprovalOnly->value, $create, '新規登録で決裁のみを選べてしまう');
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
        $target = User::factory()->create(['name' => '旧 名前', 'employee_number' => 'M001', 'email' => 'a@example.com', 'must_change_password' => false]);
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
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter UserManagementApprovalTest
```

- [ ] **Step 3: 社長の指定のルートを足す**

`routes/web.php` の `admin/users` のブロックに 1 本:

```php
        Route::post('/users/president', [\App\Http\Controllers\Admin\UserController::class, 'setPresident'])
            ->name('admin.users.president');
```

⚠ **`/users/{user}` より前に置く。** `route:list` の並びは URI の辞書順だが、**登録順**が
マッチの優先順なので、後ろに置くと `president` が `{user}` として解決される（POST の
`/users` は `store` なので衝突しないが、将来 `/users/{user}` に POST を足したときに効く）。

- [ ] **Step 4: `Admin\UserController` を直す**

主な変更点（既存の守り＝自分自身のロール変更・無効化・削除の防止、最後の有効な経営層の保護は**そのまま残す**）:

```php
    /**
     * ユーザー一覧（検索・フィルター・ページネーション）
     * Route: GET /admin/users
     */
    public function index(Request $request)
    {
        if ($request->status === 'deleted') {
            $query = User::onlyTrashed()->with(['departments', 'approvalMember']);
        } else {
            $query = User::with(['departments', 'approvalMember']);
            if (in_array($request->status, [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('department')) {
            $query->whereHas('departments', fn ($q) => $q->where('departments.id', $request->department));
        }

        // 氏名・社員番号・メール検索
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                       ->orderBy('name')
                       ->paginate(20)
                       ->withQueryString();

        $departments = Department::orderBy('display_order')->get();
        $settings    = ApprovalSetting::current()->load('president');
        // 社長の候補: 有効でメールアドレスのある人（決裁のみ利用者も含む。要件 3.1）
        $presidentCandidates = User::where('status', UserStatus::Active->value)
                                   ->whereNotNull('email')
                                   ->orderBy('name')
                                   ->get(['id', 'name', 'email']);

        return view('admin.users.index', compact('users', 'departments', 'settings', 'presidentCandidates'));
    }
```

`store()`（パスワードの入力をやめ、案内の画面を返す）:

```php
    /**
     * ユーザー登録
     * Route: POST /admin/users
     *
     * ⚠ 初期パスワードはサーバーで作り、**ログイン案内の画面**に出す（D12）。
     *   以前は画面の JS（`Math.random`）が作って読み取り専用の欄でそのまま送っていた。
     */
    public function store(Request $request)
    {
        $request->merge($this->normalizedIdentifiers($request));

        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'employee_number' => ['nullable', 'string', 'max:20', 'regex:' . LoginId::EMPLOYEE_NUMBER_PATTERN, 'unique:users,employee_number', 'required_without:email'],
            'email'           => ['nullable', 'email', 'max:255', 'unique:users,email', 'required_without:employee_number'],
            'role'            => ['required', Rule::in(array_column(UserRole::baseCases(), 'value'))],
            'departments'     => ['required', 'array', 'min:1'],
            'departments.*'   => ['exists:departments,id'],
        ], [
            'name.required' => '氏名を入力してください。',
            'name.max' => '氏名は100文字以内で入力してください。',
            'employee_number.regex' => '社員番号は英数字とハイフンで入力してください。',
            'employee_number.unique' => 'この社員番号は既に登録されています。',
            'employee_number.required_without' => '社員番号またはメールアドレスのどちらかを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.unique' => 'このメールアドレスは既に登録されています。',
            'email.required_without' => '社員番号またはメールアドレスのどちらかを入力してください。',
            'role.required' => 'ロールを選択してください。',
            'role.in' => 'ロールを選択してください。',
            'departments.required' => '所属部門を1つ以上選択してください。',
            'departments.min' => '所属部門を1つ以上選択してください。',
        ], [
            'name' => '氏名',
        ]);

        $password = InitialPassword::generate();

        // role / status は $fillable 対象外のため明示代入する（マスアサインメント対策）
        $user = new User();
        $user->name = $validated['name'];
        $user->employee_number = $validated['employee_number'] ?? null;
        $user->email = $validated['email'] ?? null;
        $user->role = $validated['role'];
        $user->status = UserStatus::Active->value;
        $user->must_change_password = true;
        $user->forceFill(['password' => InitialPassword::hash($password)]);
        $user->save();

        $user->departments()->attach($validated['departments']);

        SettingLogger::record('user.created', 'user', $user->id, [], [
            'name' => $user->name, 'employee_number' => $user->employee_number,
            'email' => $user->email, 'role' => $user->role->value,
        ]);

        // ⚠ リダイレクトしない。初期パスワードをセッションに入れないため（D12）
        return (new LoginGuide([['user' => $user, 'password' => $password]]))->toResponse($request);
    }
```

`update()` に足す判断（既存の守りの**あと**に置く）:

```php
        // 社長に指定されている人はメールアドレスを空にできない（§5.7）
        if ($user->isApprovalPresident() && ($validated['email'] ?? null) === null) {
            return redirect()->route('admin.users.index')
                ->with('error', "{$user->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。");
        }

        $before = [
            'name' => $user->name, 'employee_number' => $user->employee_number,
            'email' => $user->email, 'role' => $user->role->value, 'status' => $user->status->value,
        ];

        $user->name = $validated['name'];
        $user->employee_number = $validated['employee_number'] ?? null;
        $user->email = $validated['email'] ?? null;
        $user->role = $validated['role'];
        $user->status = $validated['status'];
        $user->save();

        // 決裁のみ利用者は基幹の所属部門を持たない（§5.7）
        if ($user->isApprovalOnly()) {
            $user->departments()->detach();
        } else {
            $user->departments()->sync($validated['departments']);
        }

        SettingLogger::recordChange('user.updated', 'user', $user->id, $before, [
            'name' => $user->name, 'employee_number' => $user->employee_number,
            'email' => $user->email, 'role' => $user->role->value, 'status' => $user->status->value,
        ]);

        $this->syncApprovalFlags($user, $request->boolean('can_view_all'), $request->boolean('is_admin'));
```

`update()` の検証（`departments` は決裁のみのときだけ任意にする）:

```php
            'role'            => ['required', Rule::enum(UserRole::class)],
            'departments'     => [Rule::requiredIf(fn () => $request->input('role') !== UserRole::ApprovalOnly->value), 'array'],
            'departments.*'   => ['exists:departments,id'],
```

新しいメソッド:

```php
    /** 画面・CSV のどこから来ても、検証の前に保存する形へそろえる（設計書 §5.6） */
    private function normalizedIdentifiers(Request $request): array
    {
        $out = [];

        foreach (['employee_number', 'email'] as $key) {
            if ($request->has($key)) {
                $value = LoginId::normalize($request->input($key));
                $out[$key] = $value === '' ? null : $value;
            }
        }

        return $out;
    }

    /** 全件閲覧者・決裁の管理者の付与と解除（設計書 §5.7） */
    private function syncApprovalFlags(User $user, bool $canViewAll, bool $isAdmin): void
    {
        $member = $user->approvalMember;
        $before = ['can_view_all' => (bool) $member?->can_view_all, 'is_admin' => (bool) $member?->is_admin];
        $after  = ['can_view_all' => $canViewAll, 'is_admin' => $isAdmin];

        if ($before === $after) {
            return;
        }

        ApprovalMember::updateOrCreate(['user_id' => $user->id], $after);

        SettingLogger::record('member.flags_changed', 'user', $user->id, $before, $after);
    }

    /**
     * 決裁の社長の指定（設計書 §5.7・要件 3.1）。
     * Route: POST /admin/users/president
     *
     * ⚠ 候補は「有効でメールアドレスのある人」。決裁のみ利用者も選べる。
     *   「未設定に戻す」は作らない（社長不在の決裁経路を作らないため）。
     */
    public function setPresident(Request $request)
    {
        $validated = $request->validate([
            'president_user_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->where('status', UserStatus::Active->value)
                    ->whereNotNull('email'),
            ],
        ], [
            'president_user_id.required' => '決裁の社長を選択してください。',
            'president_user_id.exists'   => '有効でメールアドレスのある利用者を選択してください。',
        ]);

        $settings = ApprovalSetting::current();
        $before   = ['president_user_id' => $settings->president_user_id];

        $settings->update(['president_user_id' => (int) $validated['president_user_id']]);

        SettingLogger::recordChange('president.changed', 'approval_setting', $settings->id, $before, [
            'president_user_id' => $settings->president_user_id,
        ]);

        return redirect()->route('admin.users.index')->with('success', '決裁の社長を設定しました。');
    }
```

`destroy()` の末尾（`$user->delete();` の後）と `restore()` の末尾（`$user->restore();` の後）に、
設計書 §5.14 の表の「削除と復元」を記録する:

```php
        SettingLogger::record('user.deleted', 'user', $user->id, ['deleted_at' => null], ['deleted_at' => $user->deleted_at?->toDateTimeString()]);
```

```php
        SettingLogger::record('user.restored', 'user', $user->id, [], []);
```

`toggleStatus()` と `destroy()` の先頭（既存の守りの**前**）に:

```php
        // 社長に指定されている人は無効化・削除できない（§5.7）
        if ($user->isApprovalPresident()) {
            return redirect()->route('admin.users.index')
                ->with('error', "{$user->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。");
        }
```

⚠ **`toggleStatus()` は「有効化」でも止めない**ようにする（社長を有効化するのは問題ない）。
条件は `$newStatus === UserStatus::Inactive->value` と組み合わせること。

`resetPassword()`:

```php
    /**
     * パスワードリセット（初期パスワード再発行）
     * Route: POST /admin/users/{user}/reset-password
     *
     * ⚠ 平文をセッションのフラッシュに入れない（sessions テーブルに次のリクエストまで残り、
     *   閉じたままなら夜間のバックアップにも入る）。案内の画面にその場で出す（D12）。
     */
    public function resetPassword(Request $request, User $user)
    {
        if (! OneTimeAction::claim((string) $request->input('guide_token'))) {
            return redirect()->route('admin.users.index')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度再発行してください。');
        }

        return (new PasswordReissuer())->reissue(collect([$user]))->toGuide()->toResponse($request);
    }
```

⚠ **`resetPassword` のルートは PUT → POST に変える。** 案内の画面を返すので、
ブラウザの再送信の確認が出る経路になる（1 回限りの鍵で守る）。`routes/web.php` と
ビューの `@method('PUT')` を対で直すこと。

削除する: `private function generatePassword()`（`InitialPassword` に置き換わった）。

冒頭の `use` に `App\Models\ApprovalMember` / `App\Models\ApprovalSetting` /
`App\Support\Approval\LoginGuide` / `App\Support\Approval\PasswordReissuer` /
`App\Support\Approval\SettingLogger` / `App\Support\InitialPassword` / `App\Support\LoginId` /
`App\Support\OneTimeAction` を足す。

- [ ] **Step 5: 画面を直す**

`resources/views/admin/users/index.blade.php` の変更点:

1. **「パスワードリセット結果の表示」ブロック（26〜37 行）を丸ごと削除**
2. ページヘッダーの下に**社長の欄**を足す:

```blade
    {{-- 決裁の社長（設計書 §5.7） --}}
    <div class="flex flex-wrap items-center gap-2 mb-5 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5 text-[13px]">
        <span class="text-gray-500">決裁の社長:</span>
        <span class="font-medium text-gray-900">{{ $settings->president?->name ?? '未設定' }}</span>
        <button type="button" @click="presidentModal = true" class="text-[12px] text-emerald-600 hover:underline cursor-pointer bg-transparent border-none p-0">変更</button>
    </div>
```

3. **社員番号の列**を氏名の前に足し、氏名の横に印を出す:

```blade
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap w-[1%]">社員番号</th>
```

```blade
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap font-mono text-[12px] text-gray-700">{{ $u->employee_number ?? '—' }}</td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap">
                            <span class="text-[13px] font-medium text-gray-900">{{ $u->name }}</span>
                            @if($u->isApprovalPresident())<span class="ml-1.5 inline-block px-1.5 rounded bg-purple-100 text-purple-800 text-[10px]">社長</span>@endif
                            @if($u->isApprovalAdmin())<span class="ml-1 inline-block px-1.5 rounded bg-emerald-100 text-emerald-800 text-[10px]">決裁管理</span>@endif
                            @if($u->canViewAllApprovals())<span class="ml-1 inline-block px-1.5 rounded bg-sky-100 text-sky-800 text-[10px]">全件閲覧</span>@endif
                        </td>
```

⚠ `@empty` の `colspan="6"` を **7** に直す。

4. ロールのバッジに `@case(App\Enums\UserRole::ApprovalOnly) bg-purple-100 text-purple-800 @break` を足す
5. 検索欄の `placeholder` を「氏名・社員番号・メールで検索」に
6. **新規登録モーダル**: 初期パスワードのブロックを削除し、社員番号の欄を足し、メールの `required` を外す。
   ロールの `<option>` は 3 つのまま
7. **編集モーダル**: 社員番号の欄・メールの `required` を外す・「決裁のみ」の `<option>` を足す・
   所属部門を `x-show="editRole !== 'approval_only'"` にし、全件閲覧者と決裁の管理者のチェックを足す
8. **PW 再発行はモーダルをやめ、行ごとの `<form>` にする**（`openResetModal()` も削除）:

```blade
                                    <span class="text-gray-200 mx-1">|</span>
                                    <form method="POST" action="{{ route('admin.users.resetPassword', $u) }}" class="inline"
                                          onsubmit="return confirm('{{ $u->name }}さんのパスワードを再発行します。印刷用の案内が開きます。よろしいですか。');">
                                        @csrf
                                        {{-- 1 回限りの鍵。行ごとに違う値をサーバーで描くので、ブラウザの再送信では同じ鍵になり 2 回目が止まる --}}
                                        <input type="hidden" name="guide_token" value="{{ \Illuminate\Support\Str::random(40) }}">
                                        <button type="submit" class="text-[12px] text-amber-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal">PW再発行</button>
                                    </form>
```

⚠ **`action` と `guide_token` を Alpine でバインドしない。** `:action` / `:value` にすると、
往復テスト（`ParsesForms`）が拾えず**配線が無防備になる**（Bug #47。`htmlAttr()` は
`:` `@` の付いた属性を意図的に無視する）。1 行に 1 つのフォームなら静的に書ける。

9. **社長の変更モーダル**を新設（`$presidentCandidates` から `@foreach` で静的な `<option>`。Bug #16）
10. `<script>` の `regeneratePassword()` / `generatedPassword` / `resetCreateForm()` /
    `resetModal` / `openResetModal()` / `resetUserId` / `resetUserName` を削除し、
    `presidentModal: false` を足す（`init()` は空になるので消す）

⚠ **`<option>` は `@foreach` で静的に出す**（`<template x-for>` は `x-model` の同期より後に
描画されて値がズレる。Bug #16）。

⚠ **Blade コンポーネント属性に `&quot;` を書かない**（Bug #21）。

- [ ] **Step 6: 通ることを確かめる → 全テスト → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter UserManagementApprovalTest
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -10
git add app/Http/Controllers/Admin/UserController.php resources/views/admin/users/index.blade.php routes/web.php tests/Feature/Admin/UserManagementApprovalTest.php
git commit -m "$(cat <<'EOF'
feat(admin): 利用者管理に社員番号・決裁の指定・ログイン案内を足す

一覧に社員番号の列と決裁の印（社長・決裁の管理者・全件閲覧者）を出し、検索の対象に
社員番号を加える。登録と編集で社員番号を扱い、メールアドレスを任意にする
（どちらかは必須）。決裁のみへロールを変えると基幹の所属部門が外れる。

初期パスワードは画面の JS でなくサーバーで作り、登録と再発行の応答として
ログイン案内の画面をその場で返す（セッションに平文を入れない）。

社長の指定と、社長に指定された人の無効化・削除・メールアドレスを空にする操作の
歯止めも足した。変更はすべて記録に残す。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 11: 部門の管理（決裁の管理者）

**Files:**
- Create: `app/Http/Middleware/EnsureApprovalAdmin.php`
- Create: `app/Http/Controllers/Approval/OrganizationController.php`
- Create: `resources/views/approvals/admin/organization.blade.php`
- Modify: `bootstrap/app.php`（別名 `approval.admin`）
- Modify: `routes/approval.php`
- Modify: `lang/ja/validation.php`
- Test: `tests/Feature/Approval/OrganizationManagementTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/OrganizationManagementTest.php`:

```php
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

    public function test_a_company_with_departments_cannot_be_deleted(): void
    {
        $company = $this->company();
        ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.companies.destroy', $company))
            ->assertSessionHas('error');

        $this->assertSame(1, ApprovalCompany::count());
    }

    public function test_an_empty_company_can_be_deleted(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.companies.destroy', $company))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalCompany::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.deleted')->count());
    }

    // --- 部門 ---

    public function test_a_department_code_is_normalized_to_upper_case(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.organization.departments.store'), [
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'ｒｅ', 'sort_order' => '1',
        ])->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame('RE', ApprovalDepartment::sole()->code);
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
        $dept    = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('error');

        $this->assertSame(1, ApprovalDepartment::count());
    }

    /** 一覧に人数が出る */
    public function test_the_list_shows_the_member_count(): void
    {
        $company = $this->company();
        $dept    = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))
            ->assertOk()->assertSee('1 人');
    }

    // --- 許可するドメイン ---

    public function test_a_domain_is_stored_without_the_at_sign(): void
    {
        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.mailDomains.store'), ['domain' => ' @MITSUWAT.CO.JP '])
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

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.mailDomains.destroy', $domain))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalMailDomain::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'mail_domain.deleted')->count());
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter OrganizationManagementTest
```

- [ ] **Step 3: 門番と別名を足す**

`app/Http/Middleware/EnsureApprovalAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 決裁の管理者に指定された人だけを通す（設計書 §5.2・§5.17）。
 *
 * ⚠ **経営層でも、指定されていなければ 403。** 指定は基幹の利用者管理（`/admin/users`）で行う。
 *   段階1 は「指定するまで誰にも決裁の管理の画面が出ない」状態から始まる（D2）。
 * ⚠ これは web グループの門番を通ったあとの 2 段目（決裁のみ利用者も基幹を使う人も同じ判定）。
 */
class EnsureApprovalAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isApprovalAdmin()) {
            abort(403, 'この画面を使う権限がありません。');
        }

        return $next($request);
    }
}
```

`bootstrap/app.php` の別名に 1 行:

```php
            'approval.admin' => \App\Http\Middleware\EnsureApprovalAdmin::class,
```

- [ ] **Step 4: ルートを足す**

`routes/approval.php`:

```php
Route::get('/approvals', [HomeController::class, 'index'])->name('approvals.home');

/*
|--------------------------------------------------------------------------
| 決裁の管理（決裁の管理者に指定された人だけ）
|--------------------------------------------------------------------------
|
| ⚠ パラメータ名は `{approvalCompany}` / `{approvalDepartment}` / `{mailDomain}`。
|   `{department}` は基幹で別の意味を持つ（`CheckDepartmentAccess` が `$request->route('department')`
|   を読む）ので、同じ名前を使わない。
|
*/
Route::middleware('approval.admin')->prefix('approvals/admin')->name('approvals.admin.')->group(function () {

    // 部門の管理
    Route::get('/organization', [OrganizationController::class, 'index'])->name('organization.index');

    Route::post('/organization/companies', [OrganizationController::class, 'storeCompany'])->name('organization.companies.store');
    Route::put('/organization/companies/{approvalCompany}', [OrganizationController::class, 'updateCompany'])->name('organization.companies.update');
    Route::delete('/organization/companies/{approvalCompany}', [OrganizationController::class, 'destroyCompany'])->name('organization.companies.destroy');

    Route::post('/organization/departments', [OrganizationController::class, 'storeDepartment'])->name('organization.departments.store');
    Route::put('/organization/departments/{approvalDepartment}', [OrganizationController::class, 'updateDepartment'])->name('organization.departments.update');
    Route::delete('/organization/departments/{approvalDepartment}', [OrganizationController::class, 'destroyDepartment'])->name('organization.departments.destroy');

    Route::post('/organization/mail-domains', [OrganizationController::class, 'storeMailDomain'])->name('organization.mailDomains.store');
    Route::delete('/organization/mail-domains/{mailDomain}', [OrganizationController::class, 'destroyMailDomain'])->name('organization.mailDomains.destroy');
});
```

冒頭の `use` に `App\Http\Controllers\Approval\OrganizationController;` を足す。

⚠ **暗黙のモデルバインドは型宣言の引数名と一致したときだけ働く。**
`{approvalCompany}` は `ApprovalCompany $approvalCompany` と書く（工程表で踏んだ罠）。

- [ ] **Step 5: コントローラを書く**

`app/Http/Controllers/Approval/OrganizationController.php`:

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\Approval\SettingLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 会社・部門・許可するメールのドメイン（設計書 §5.8）。
 *
 * 画面は 1 枚で 3 つの欄を並べる。追加と編集は基幹の利用者管理と同じくモーダル。
 *
 * ⚠ 決裁の所属部門（`approval_department_user`）は**この画面では編集しない**（人数を出すだけ）。
 *   編集は利用者の管理（§5.9）と CSV（§5.10）で行う。
 * ⚠ 部門長・審査担当者・今年度の開始番号は段階2 でこの画面に足す。
 */
class OrganizationController extends Controller
{
    public function index()
    {
        $companies = ApprovalCompany::with(['departments' => fn ($q) => $q->withCount('users')])
            ->orderBy('sort_order')->orderBy('id')->get();

        $mailDomains = ApprovalMailDomain::orderBy('domain')->get()
            ->map(function (ApprovalMailDomain $domain) {
                // 削除するとこの人数に通知メールが届かなくなる（§5.8）
                $domain->affected_user_count = User::whereNotNull('email')
                    ->where('email', 'like', '%@' . $domain->domain)
                    ->count();

                return $domain;
            });

        return view('approvals.admin.organization', compact('companies', 'mailDomains'));
    }

    // --- 会社 ---

    public function storeCompany(Request $request)
    {
        $validated = $this->validateCompany($request);

        $company = ApprovalCompany::create($validated);
        SettingLogger::record('company.created', 'approval_company', $company->id, [], $validated);

        return $this->back('会社を登録しました。');
    }

    public function updateCompany(Request $request, ApprovalCompany $approvalCompany)
    {
        $validated = $this->validateCompany($request, $approvalCompany);
        $before    = $approvalCompany->only(array_keys($validated));

        $approvalCompany->update($validated);
        SettingLogger::recordChange('company.updated', 'approval_company', $approvalCompany->id, $before, $validated);

        return $this->back('会社を更新しました。');
    }

    public function destroyCompany(ApprovalCompany $approvalCompany)
    {
        $count = $approvalCompany->departments()->count();

        if ($count > 0) {
            return $this->back(null, "この会社には部門が {$count} 件あるため削除できません。先に部門を削除してください。");
        }

        $before = $approvalCompany->only(['name', 'fiscal_start_month', 'sort_order']);
        $id     = $approvalCompany->id;
        $approvalCompany->delete();

        SettingLogger::record('company.deleted', 'approval_company', $id, $before, []);

        return $this->back('会社を削除しました。');
    }

    private function validateCompany(Request $request, ?ApprovalCompany $current = null): array
    {
        return $request->validate([
            'name'               => ['required', 'string', 'max:50', Rule::unique('approval_companies', 'name')->ignore($current?->id)],
            'fiscal_start_month' => ['required', 'integer', 'between:1,12'],
            'sort_order'         => ['required', 'integer', 'min:0', 'max:9999'],
        ], [
            'name.required' => '会社名を入力してください。',
            'name.max' => '会社名は50文字以内で入力してください。',
            'name.unique' => 'この会社名は既に登録されています。',
            'fiscal_start_month.required' => '期の始まりの月を選択してください。',
            'fiscal_start_month.between' => '期の始まりの月は1〜12で入力してください。',
            'sort_order.required' => '表示順を入力してください。',
        ], [
            'name' => '会社名',
        ]);
    }

    // --- 部門 ---

    public function storeDepartment(Request $request)
    {
        $validated = $this->validateDepartment($request);

        $department = ApprovalDepartment::create($validated);
        SettingLogger::record('department.created', 'approval_department', $department->id, [], $validated);

        return $this->back('部門を登録しました。');
    }

    public function updateDepartment(Request $request, ApprovalDepartment $approvalDepartment)
    {
        $validated = $this->validateDepartment($request, $approvalDepartment);
        $before    = $approvalDepartment->only(array_keys($validated));

        $approvalDepartment->update($validated);
        SettingLogger::recordChange('department.updated', 'approval_department', $approvalDepartment->id, $before, $validated);

        return $this->back('部門を更新しました。');
    }

    public function destroyDepartment(ApprovalDepartment $approvalDepartment)
    {
        $count = $approvalDepartment->users()->count();

        if ($count > 0) {
            return $this->back(null, "この部門には所属者が {$count} 人いるため削除できません。先に利用者の管理で所属部門を変えてください。");
        }

        $before = $approvalDepartment->only(['company_id', 'name', 'short_name', 'code', 'sort_order']);
        $id     = $approvalDepartment->id;
        $approvalDepartment->delete();

        SettingLogger::record('department.deleted', 'approval_department', $id, $before, []);

        return $this->back('部門を削除しました。');
    }

    private function validateDepartment(Request $request, ?ApprovalDepartment $current = null): array
    {
        // ⚠ 検証の前に正規化する（保存する値で一意を検査するため。設計書 §5.6 と同じ理由）
        $request->merge(['code' => mb_strtoupper(trim(mb_convert_kana((string) $request->input('code'), 'as')), 'UTF-8')]);

        return $request->validate([
            'company_id' => ['required', Rule::exists('approval_companies', 'id')],
            'name'       => ['required', 'string', 'max:50', Rule::unique('approval_departments', 'name')->where('company_id', $request->input('company_id'))->ignore($current?->id)],
            'short_name' => ['required', 'string', 'max:6'],
            'code'       => ['required', 'string', 'regex:/\A[A-Z]{1,3}\z/', Rule::unique('approval_departments', 'code')->ignore($current?->id)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ], [
            'company_id.required' => '会社を選択してください。',
            'name.required' => '部門名を入力してください。',
            'name.unique' => 'この会社にはその部門名が既に登録されています。',
            'short_name.required' => '略称を入力してください。',
            'short_name.max' => '略称は6文字以内で入力してください（データ印に入るため）。',
            'code.required' => 'アルファベットを入力してください。',
            'code.regex' => 'アルファベットは英大文字1〜3文字で入力してください。',
            'code.unique' => 'このアルファベットは既に使われています。',
            'sort_order.required' => '表示順を入力してください。',
        ]);
    }

    // --- 許可するドメイン ---

    public function storeMailDomain(Request $request)
    {
        $request->merge(['domain' => ApprovalMailDomain::normalize($request->input('domain'))]);

        $validated = $request->validate([
            // ⚠ ドメインだけ（`@` は normalize が外す）。完全一致で判定するのでサブドメインは別に登録する
            'domain' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', Rule::unique('approval_mail_domains', 'domain')],
        ], [
            'domain.required' => 'ドメインを入力してください。',
            'domain.regex' => 'ドメインの形式が正しくありません（例: mitsuwat.co.jp）。',
            'domain.unique' => 'このドメインは既に登録されています。',
        ]);

        $domain = ApprovalMailDomain::create($validated);
        SettingLogger::record('mail_domain.created', 'approval_mail_domain', $domain->id, [], $validated);

        return $this->back('ドメインを登録しました。');
    }

    public function destroyMailDomain(ApprovalMailDomain $mailDomain)
    {
        $before = $mailDomain->only(['domain']);
        $id     = $mailDomain->id;
        $mailDomain->delete();

        SettingLogger::record('mail_domain.deleted', 'approval_mail_domain', $id, $before, []);

        return $this->back('ドメインを削除しました。');
    }

    private function back(?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('approvals.admin.organization.index');

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
```

- [ ] **Step 6: 画面を書く**

`resources/views/approvals/admin/organization.blade.php`:

```blade
@extends('layouts.app')

@section('title', '部門の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">部門の管理</span>
@endsection

@section('content')
<div x-data="approvalOrganization()" x-cloak>

    @if(session('success'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-800">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-5">部門の管理</h1>

    {{-- 会社 --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">会社</h2>
            <button type="button" @click="openCompany(null)" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">会社を追加</button>
        </div>
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">会社名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">期の始まり</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">表示順</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies as $company)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $company->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $company->fiscal_start_month }}月</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $company->sort_order }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openCompany({{ \Illuminate\Support\Js::from($company->only(['id', 'name', 'fiscal_start_month', 'sort_order'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.organization.companies.destroy', $company) }}" class="inline" onsubmit="return confirm('この会社を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-[13px] text-gray-400">会社が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- 部門 --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">部門</h2>
            <button type="button" @click="openDepartment(null)" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer" @if($companies->isEmpty()) disabled @endif>部門を追加</button>
        </div>
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">会社</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">部門名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">略称</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">アルファベット</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">所属</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies->flatMap->departments as $dept)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $dept->company->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $dept->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $dept->short_name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700">{{ $dept->code }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $dept->users_count }} 人</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openDepartment({{ \Illuminate\Support\Js::from($dept->only(['id', 'company_id', 'name', 'short_name', 'code', 'sort_order'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.organization.departments.destroy', $dept) }}" class="inline" onsubmit="return confirm('この部門を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-gray-400">部門が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- 許可するドメイン --}}
    <section class="bg-white rounded-lg border border-gray-200">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">通知メールを送ってよいドメイン</h2>
            <p class="text-[12px] text-gray-500 mt-1">ここに登録したドメインのメールアドレスにだけ、パスワード再発行の通知を送ります。CSV 取込のメールアドレスの検査にも使います。</p>
        </div>
        <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.store') }}" class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-gray-100">
            @csrf
            <input type="text" name="domain" value="{{ old('domain') }}" placeholder="例: mitsuwat.co.jp" autocapitalize="none" spellcheck="false"
                   class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 w-full sm:w-[280px]">
            <button type="submit" class="h-8 px-3.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">追加</button>
        </form>
        <ul>
            @forelse($mailDomains as $domain)
                <li class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-gray-100 text-[13px]">
                    <span class="font-mono text-gray-900">{{ $domain->domain }}</span>
                    <span class="text-[12px] text-gray-500">このドメインのメールアドレスを持つ利用者: {{ $domain->affected_user_count }} 人（この人たちには通知メールが届かなくなります）</span>
                    <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.destroy', $domain) }}" class="ml-auto" onsubmit="return confirm('このドメインを削除しますか。');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                    </form>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-[13px] text-gray-400">ドメインが登録されていません。登録するまで通知メールは送られません。</li>
            @endforelse
        </ul>
    </section>

    {{-- 会社のモーダル --}}
    <div x-show="companyModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="companyModal = false" class="bg-white rounded-xl w-full max-w-[420px] shadow-xl mx-4">
            <form method="POST" :action="companyAction">
                @csrf
                <template x-if="companyId"><input type="hidden" name="_method" value="PUT"></template>
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900" x-text="companyId ? '会社の編集' : '会社の追加'"></div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="companyName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">期の始まりの月<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="fiscal_start_month" x-model="companyMonth" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}">{{ $month }}月</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="companySort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="companyModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 部門のモーダル --}}
    <div x-show="departmentModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="departmentModal = false" class="bg-white rounded-xl w-full max-w-[420px] shadow-xl mx-4">
            <form method="POST" :action="departmentAction">
                @csrf
                <template x-if="departmentId"><input type="hidden" name="_method" value="PUT"></template>
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900" x-text="departmentId ? '部門の編集' : '部門の追加'"></div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社<span class="text-red-600 ml-0.5">*</span></label>
                        {{-- ⚠ <option> は @@foreach で静的に出す（<template x-for> は x-model の同期より後に描画されて値がズレる。Bug #16） --}}
                        <select name="company_id" x-model="departmentCompanyId" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="departmentName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">略称<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="short_name" x-model="departmentShortName" required maxlength="6" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1">データ印の上段に入るので6文字まで</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">アルファベット<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="code" x-model="departmentCode" required maxlength="3" autocapitalize="characters" spellcheck="false" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono uppercase">
                        <p class="text-[11px] text-gray-400 mt-1">申請番号に使う英大文字1〜3文字（グループ全体で重複不可）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="departmentSort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="departmentModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function approvalOrganization() {
    return {
        companyModal: false,
        companyId: null,
        companyName: '',
        companyMonth: '1',
        companySort: '0',

        departmentModal: false,
        departmentId: null,
        departmentCompanyId: '',
        departmentName: '',
        departmentShortName: '',
        departmentCode: '',
        departmentSort: '0',

        get companyAction() {
            var base = '{{ url('approvals/admin/organization/companies') }}';
            return this.companyId ? base + '/' + this.companyId : base;
        },

        get departmentAction() {
            var base = '{{ url('approvals/admin/organization/departments') }}';
            return this.departmentId ? base + '/' + this.departmentId : base;
        },

        openCompany(row) {
            this.companyId = row ? row.id : null;
            this.companyName = row ? row.name : '';
            this.companyMonth = row ? String(row.fiscal_start_month) : '1';
            this.companySort = row ? String(row.sort_order) : '0';
            this.companyModal = true;
        },

        openDepartment(row) {
            this.departmentId = row ? row.id : null;
            this.departmentCompanyId = row ? String(row.company_id) : '{{ $companies->first()?->id }}';
            this.departmentName = row ? row.name : '';
            this.departmentShortName = row ? row.short_name : '';
            this.departmentCode = row ? row.code : '';
            this.departmentSort = row ? String(row.sort_order) : '0';
            this.departmentModal = true;
        }
    };
}
</script>
@endpush
```

⚠ **`x-data` 属性に `@json` を入れない**（属性が途中で切れて Alpine が初期化されない。Bug #23）。
行のデータは `\Illuminate\Support\Js::from()` で渡す。

- [ ] **Step 7: 和名を足す**

`lang/ja/validation.php` の `attributes`:

```php
        'fiscal_start_month'   => '期の始まりの月',
        'short_name'           => '略称',
        'sort_order'           => '表示順',
        'domain'               => 'ドメイン',
        'company_id'           => '会社',
```

⚠ `name` `code` は既にある（画面ごとに語が変わるので、必要なら `validate()` の第 3 引数で上書き）。

- [ ] **Step 8: 通ることを確かめる → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'OrganizationManagementTest|ApprovalOnlyLockoutTest|JapaneseValidationMessagesTest'
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add app/Http/Middleware/EnsureApprovalAdmin.php app/Http/Controllers/Approval/OrganizationController.php resources/views/approvals/ routes/approval.php bootstrap/app.php lang/ja/validation.php tests/Feature/Approval/OrganizationManagementTest.php
git commit -m "$(cat <<'EOF'
feat(approval): 部門の管理（会社・部門・許可するドメイン）を足す

決裁の管理者に指定された人だけが入れる 1 枚の画面。会社（期の始まりの月）・
部門（略称・アルファベット・表示順）・通知メールを送ってよいドメインを登録する。
部門がある会社と、所属者がいる部門は削除できない。ドメインの削除の確認には
通知が届かなくなる人数を出す。

基幹の departments とは別の表なので、ここでの変更は基幹の権限に影響しない。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 12: 利用者の管理（決裁の管理者）

**Files:**
- Create: `app/Http/Controllers/Approval/UserController.php`
- Create: `resources/views/approvals/admin/users/index.blade.php`
- Modify: `routes/approval.php`
- Modify: `resources/views/approvals/home.blade.php`（管理へのリンクを足す）
- Test: `tests/Feature/Approval/ApprovalUserManagementTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/ApprovalUserManagementTest.php`:

```php
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
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ApprovalUserManagementTest
```

- [ ] **Step 3: ルートを足す**

`routes/approval.php` の `approvals.admin.` グループの中（部門の管理の**前**）に:

```php
    // 利用者の管理
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggleStatus');
    Route::post('/users/{user}/reissue', [UserController::class, 'reissue'])->name('users.reissue');
    Route::post('/users/reissue-bulk', [UserController::class, 'reissueBulk'])->name('users.reissueBulk');
```

⚠ **`/users/reissue-bulk` を `/users/{user}` より前に**置く（登録順がマッチの優先順）。

`use App\Http\Controllers\Approval\UserController;` を足す。

- [ ] **Step 4: コントローラを書く**

`app/Http/Controllers/Approval/UserController.php`:

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalCompany;
use App\Models\User;
use App\Support\Approval\PasswordReissuer;
use App\Support\Approval\SettingLogger;
use App\Support\LoginId;
use App\Support\OneTimeAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * 利用者の管理（設計書 §5.9・D8・D16）。決裁の管理者だけが入れる。
 *
 * できること:
 *   - 決裁の所属部門の編集 … **全員**（基幹を使う人も。要件 3.2）
 *   - 氏名・社員番号の修正・無効化と有効化・パスワード再発行 … **決裁のみ利用者だけ**（D8）で、
 *     かつ**決裁の権限（社長・全件閲覧者・決裁の管理者）に指定されていない人だけ**（D16）
 *
 * ⚠ D16 の理由: 指定は基幹の管理者だけが行う建て付けなので、決裁の管理者が指定された人の
 *   パスワードを再発行できると、社長決裁のなりすましを止められない。
 * ⚠ メールアドレスの変更と利用者の削除は基幹の管理者だけ（この画面には手段を作らない）。
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = $this->filteredQuery($request)
            ->with(['approvalDepartments', 'approvalMember'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $companies = ApprovalCompany::with('departments')->orderBy('sort_order')->orderBy('id')->get();
        $president = \App\Models\ApprovalSetting::current()->president_user_id;

        return view('approvals.admin.users.index', compact('users', 'companies', 'president'));
    }

    /**
     * 一覧の絞り込み。**まとめて再発行の「絞り込んだ全員」も同じものを通る**
     * （画面から人の一覧を受け取らず、サーバーで引き直すため）。
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = User::query();

        if ($request->input('kind') === 'approval') {
            $query->where('role', UserRole::ApprovalOnly->value);
        } elseif ($request->input('kind') === 'base') {
            $query->baseUsers();
        }

        if ($request->input('department') === 'none') {
            $query->whereDoesntHave('approvalDepartments');
        } elseif ($request->filled('department')) {
            $query->whereHas('approvalDepartments', fn ($q) => $q->where('approval_departments.id', $request->input('department')));
        }

        if (in_array($request->input('status'), [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('never_logged_in')) {
            $query->whereNull('last_login_at');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * 所属部門は全員、氏名と社員番号は「決裁のみ かつ 指定されていない人」だけ。
     * Route: PUT /approvals/admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $editable = $this->canEditIdentity($user);

        if ($editable) {
            $request->merge([
                'employee_number' => LoginId::normalize($request->input('employee_number')) ?: null,
            ]);
        }

        $validated = $request->validate([
            'approval_departments'   => ['array'],
            'approval_departments.*' => [Rule::exists('approval_departments', 'id')],
            'name'                   => [Rule::requiredIf($editable), 'string', 'max:100'],
            'employee_number'        => [
                Rule::requiredIf(fn () => $editable && $user->email === null),
                'nullable', 'string', 'max:20',
                'regex:' . LoginId::EMPLOYEE_NUMBER_PATTERN,
                Rule::unique('users', 'employee_number')->ignore($user->id),
            ],
        ], [
            'name.required' => '氏名を入力してください。',
            'employee_number.regex' => '社員番号は英数字とハイフンで入力してください。',
            'employee_number.unique' => 'この社員番号は既に登録されています。',
            'employee_number.required' => '社員番号を入力してください。',
        ], [
            'name' => '氏名',
            'approval_departments' => '決裁の所属部門',
        ]);

        // 所属部門は全員について編集できる（要件 3.2・D16 の例外）
        $before = $user->approvalDepartments->pluck('id')->sort()->values()->all();
        $after  = collect($validated['approval_departments'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($before !== $after) {
            $user->approvalDepartments()->sync($after);
            SettingLogger::record('user.departments_changed', 'user', $user->id, ['departments' => $before], ['departments' => $after]);
        }

        if ($editable) {
            $identityBefore = ['name' => $user->name, 'employee_number' => $user->employee_number];

            $user->name = $validated['name'];
            $user->employee_number = $validated['employee_number'] ?? null;
            $user->save();

            SettingLogger::recordChange('user.updated', 'user', $user->id, $identityBefore, [
                'name' => $user->name, 'employee_number' => $user->employee_number,
            ]);
        }

        return redirect()->route('approvals.admin.users.index')->with('success', "「{$user->name}」さんの情報を更新しました。");
    }

    /** Route: PATCH /approvals/admin/users/{user}/toggle-status */
    public function toggleStatus(Request $request, User $user)
    {
        $this->assertManageable($user, '無効化・有効化');

        $validated = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        if ($user->id === auth()->id() && $validated['status'] === UserStatus::Inactive->value) {
            abort(403, '自分自身を無効化することはできません。');
        }

        $before = $user->status->value;
        $user->status = $validated['status'];
        $user->save();

        SettingLogger::recordChange('user.updated', 'user', $user->id, ['status' => $before], ['status' => $user->status->value]);

        $action = $validated['status'] === UserStatus::Active->value ? '有効化' : '無効化';

        return redirect()->route('approvals.admin.users.index')->with('success', "「{$user->name}」さんを{$action}しました。");
    }

    /** Route: POST /approvals/admin/users/{user}/reissue */
    public function reissue(Request $request, User $user)
    {
        $this->assertManageable($user, 'パスワードの再発行');

        if (! OneTimeAction::claim((string) $request->input('guide_token'))) {
            return redirect()->route('approvals.admin.users.index')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度再発行してください。');
        }

        return (new PasswordReissuer())->reissue(collect([$user]))->toGuide()->toResponse($request);
    }

    /**
     * まとめて再発行（D9）。
     * Route: POST /approvals/admin/users/reissue-bulk
     *
     * ⚠ 「絞り込んだ全員」は**確定の時にサーバーが同じ条件で引き直す**（画面が送ってきた
     *   人の一覧を信用しない）。対象は 決裁のみ・有効・未削除・**決裁の権限に指定されていない人**。
     */
    public function reissueBulk(Request $request)
    {
        $request->validate([
            'mode'       => ['required', Rule::in(['selected', 'filtered'])],
            'user_ids'   => ['required_if:mode,selected', 'array'],
            'user_ids.*' => ['integer'],
        ]);

        $targets = $request->input('mode') === 'filtered'
            ? $this->manageableQuery($this->filteredQuery($request))->orderBy('name')->get()
            : User::whereIn('id', $request->input('user_ids', []))->orderBy('name')->get();

        if ($request->input('mode') === 'selected') {
            foreach ($targets as $target) {
                $this->assertManageable($target, 'パスワードの再発行');
            }
        }

        if ($targets->isEmpty()) {
            return redirect()->route('approvals.admin.users.index')->with('error', '対象の利用者がいません。');
        }

        $max = (int) config('approval.bulk_reissue_max');
        if ($targets->count() > $max) {
            return redirect()->route('approvals.admin.users.index')
                ->with('error', "一度に再発行できるのは {$max} 人までです（今回は {$targets->count()} 人）。絞り込んでからやり直してください。");
        }

        if (! OneTimeAction::claim((string) $request->input('guide_token'))) {
            return redirect()->route('approvals.admin.users.index')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度再発行してください。');
        }

        return (new PasswordReissuer())->reissue($targets)->toGuide()->toResponse($request);
    }

    /** 決裁の管理者が手を出してよい人だけに絞る（D8・D16） */
    private function manageableQuery(Builder $query): Builder
    {
        return $query->where('role', UserRole::ApprovalOnly->value)
            ->where('status', UserStatus::Active->value)
            ->whereDoesntHave('approvalMember', fn ($q) => $q->where('is_admin', true)->orWhere('can_view_all', true))
            ->whereNotIn('id', array_filter([\App\Models\ApprovalSetting::current()->president_user_id]));
    }

    /** 氏名・社員番号を編集してよい相手か（D8・D16） */
    private function canEditIdentity(User $user): bool
    {
        return $user->isApprovalOnly() && ! $user->hasApprovalPrivileges();
    }

    /**
     * 無効化・有効化・再発行の相手として正しいか（D8・D16）。
     *
     * ⚠ 画面で選べないようにするだけでなく、サーバーでも拒む（手で組んだ送信を通さない）。
     */
    private function assertManageable(User $user, string $what): void
    {
        if (! $user->isApprovalOnly()) {
            abort(403, "基幹を使う利用者の{$what}は、基幹の管理者に依頼してください。");
        }

        if ($label = $user->approvalPrivilegeLabel()) {
            abort(403, "{$user->name}さんは決裁の{$label}に指定されています。基幹の管理者に依頼してください。");
        }
    }
}
```

- [ ] **Step 5: 画面を書く**

`resources/views/approvals/admin/users/index.blade.php` — 構成:

1. フラッシュ（success / error）とバリデーションエラーの帯（部門の管理と同じ形）
2. 絞り込みフォーム（`kind` / `department`（`none` を含む）/ `status` / `never_logged_in` / `search`）
3. まとめて再発行のフォーム（表を `<form>` で囲む）:

```blade
    <form method="POST" action="{{ route('approvals.admin.users.reissueBulk') }}" x-data="approvalBulk()"
          onsubmit="return confirm('選んだ利用者のパスワードを再発行します。印刷用の案内が開きます。よろしいですか。');">
        @csrf
        {{-- ⚠ 1 回限りの鍵はサーバーで描く（`:value` にすると往復テストが拾えず配線が無防備になる。Bug #47）。
             1 ページの読み込みにつき 1 つ ＝ ブラウザの再送信では同じ鍵になり 2 回目が止まる --}}
        <input type="hidden" name="guide_token" value="{{ \Illuminate\Support\Str::random(40) }}">
        {{-- 絞り込みの条件をそのまま送る（「絞り込んだ全員」はサーバーが同じ条件で引き直す） --}}
        @foreach(['kind', 'department', 'status', 'never_logged_in', 'search'] as $key)
            <input type="hidden" name="{{ $key }}" value="{{ request($key) }}">
        @endforeach

        <div class="flex flex-wrap items-center gap-2 mb-3">
            <button type="submit" name="mode" value="selected" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-[12px] font-semibold rounded-md cursor-pointer" :disabled="selected.length === 0">選んだ <span x-text="selected.length"></span> 人を再発行</button>
            <button type="submit" name="mode" value="filtered" class="px-3 py-1.5 bg-white border border-amber-300 text-amber-700 text-[12px] font-semibold rounded-md cursor-pointer">絞り込んだ全員（{{ $users->total() }} 人）を再発行</button>
            <span class="text-[12px] text-gray-500">一度に再発行できるのは {{ config('approval.bulk_reissue_max') }} 人までです。</span>
        </div>
        <table class="w-full border-collapse"> … </table>
    </form>
```

4. 表の列: 選択 / 社員番号 / 氏名（決裁の権限の印つき）/ 区分 / 所属部門 / メールアドレス / 最終ログイン / 状態 / 操作

行の選択欄（D16 の人には出さない）:

```blade
                        <td class="px-3.5 py-2.5 border-b border-gray-100 w-[1%]">
                            @if($u->isApprovalOnly() && ! $u->hasApprovalPrivileges() && $u->status === App\Enums\UserStatus::Active)
                                <input type="checkbox" name="user_ids[]" value="{{ $u->id }}" x-model="selected" class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                            @elseif($label = $u->approvalPrivilegeLabel())
                                <span title="{{ $u->name }}さんは決裁の{{ $label }}に指定されています。基幹の管理者に依頼してください。" class="text-[11px] text-gray-400">—</span>
                            @else
                                <span class="text-[11px] text-gray-300">—</span>
                            @endif
                        </td>
```

⚠ **理由は `<span title>` に置く**（`disabled` な要素自身の `title` はどのブラウザでも出ない。Bug #43）。
⚠ **理由は画面の本文にも出す**（`title` だけではキーボード・スクリーンリーダーに届かない）。
氏名のセルに `<span class="text-[11px] text-gray-500">決裁の{{ $label }}に指定されています</span>` を添える。

5. 操作列: 編集（モーダル）/ 再発行（**行ごとの `<form>` ＋ `confirm()`**。Task 10 と同じ形で
   `action` と `guide_token` を静的に描く）/ 無効化・有効化（**行ごとの `<form>`**）。
   D16 の人には編集だけ出し、ほかは `<span title="…">` で理由を出す（`disabled` なボタン自身の
   `title` はどのブラウザでも出ない。Bug #43）
6. 番号つきページ送り（20 件・プロジェクトの規約）
7. 編集モーダル: 所属部門のチェック（`@foreach($companies as $c) @foreach($c->departments as $d)`）+
   氏名・社員番号（編集できない相手のときは `readonly`）+ メールアドレス（常に `readonly` と
   「変更は基幹の管理者に依頼してください」）
8. `@push('scripts')` に `approvalBulk()`（`selected: []` だけ）と `approvalUsers()`（編集モーダルの状態）。
   **鍵を JS で作らない**（上の理由）

- [ ] **Step 6: 決裁のホームに管理へのリンクを足す**

Task 6 Step 6 で保留した `@if($user->isApprovalAdmin())` のブロックを `resources/views/approvals/home.blade.php` に入れる。

- [ ] **Step 7: 通ることを確かめる → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalUserManagementTest|ApprovalHomeTest|ApprovalOnlyLockoutTest'
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add app/Http/Controllers/Approval/UserController.php resources/views/approvals/ routes/approval.php tests/Feature/Approval/ApprovalUserManagementTest.php
git commit -m "$(cat <<'EOF'
feat(approval): 利用者の管理（所属部門・無効化・再発行）を足す

決裁の所属部門は全員について編集でき、氏名・社員番号の修正・無効化と有効化・
パスワードの再発行は決裁のみ利用者だけ（D8）。社長・全件閲覧者・決裁の管理者に
指定されている人は、決裁の管理者の操作の対象外（D16。指定された人のパスワードを
再発行して本人になりすますのを防ぐ）。

まとめて再発行は「選んだ人」と「絞り込んだ全員」。後者は確定の時にサーバーが
同じ条件で引き直し、画面が送ってきた人の一覧を信用しない。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 13: 社員の CSV 一括登録

**Files:**
- Create: `app/Http/Controllers/Approval/UserImportController.php`
- Create: `resources/views/approvals/admin/users/import.blade.php`
- Create: `resources/views/approvals/admin/users/_import_preview.blade.php`
- Modify: `routes/approval.php`
- Test: `tests/Feature/Approval/ApprovalUserImportTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/ApprovalUserImportTest.php`:

```php
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

        $body = $response->streamedContent() ?: $response->getContent();
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
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ApprovalUserImportTest
```

- [ ] **Step 3: ルートを足す**

`routes/approval.php` の `users.*` の**前**に（`{user}` に食われないように）:

```php
    Route::get('/users/import', [UserImportController::class, 'form'])->name('users.import');
    Route::get('/users/import/template', [UserImportController::class, 'template'])->name('users.import.template');
    Route::post('/users/import/preview', [UserImportController::class, 'preview'])->name('users.import.preview');
    Route::post('/users/import/execute', [UserImportController::class, 'execute'])->name('users.import.execute');
```

- [ ] **Step 4: コントローラを書く**

`app/Http/Controllers/Approval/UserImportController.php`:

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\Approval\LoginGuide;
use App\Support\Approval\SettingLogger;
use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use App\Support\CsvImportTemplate;
use App\Support\InitialPassword;
use App\Support\LoginId;
use App\Support\OneTimeAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 社員の CSV 一括登録（設計書 §5.10）。
 *
 * 流れ: アップロード → 確認画面 → 描画された「取り込む」フォーム（hidden の CSV と 1 回限りの鍵）で確定
 *   → **確定時にサーバーで検査をやり直す**（hidden を信用しない）→ 結果＝ログイン案内の画面
 *
 * ⚠ 行エラーの view のキーは `rowErrors`。`errors` にすると Blade の `$errors`（ViewErrorBag）を
 *   上書きして `Call to a member function any() on array` で 500 する（Bug #53）。
 * ⚠ エラー（`rowErrors`）と注意（`warnings`）は別の入れ物にする（Bug #54 ④）。
 */
class UserImportController extends Controller
{
    private const COLUMN_MAP = [
        '社員番号'       => 'employee_number',
        '氏名'           => 'name',
        'メールアドレス' => 'email',
        '所属部門'       => 'departments',
    ];

    public function form()
    {
        return view('approvals.admin.users.import');
    }

    public function template()
    {
        return CsvImportTemplate::response(
            array_keys(self::COLUMN_MAP),
            [
                ['A0001', '甲 一郎', 'ichiro@mitsuwat.co.jp', 'RE'],
                ['A0002', '乙 二郎', '', 'RE,SA'],
            ],
            'approval_users_template.csv',
        );
    }

    public function preview(Request $request)
    {
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt']], [
            'csv_file.required' => 'CSVファイルを選択してください。',
            'csv_file.mimes'    => 'CSVファイルを選択してください。',
        ], ['csv_file' => 'CSVファイル']);

        $content = CsvImportReader::decode($request->file('csv_file')->get());

        try {
            $analysis = $this->analyze($content);
        } catch (CsvImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        return view('approvals.admin.users.import', array_merge($analysis, [
            'csvData'     => base64_encode($content),
            'guideToken'  => OneTimeAction::issue(),
        ]));
    }

    public function execute(Request $request)
    {
        $request->validate(['csv_data' => ['required', 'string']]);

        $content = base64_decode((string) $request->input('csv_data'), true);

        if ($content === false) {
            return redirect()->route('approvals.admin.users.import')->with('error', '取り込むデータを読み取れませんでした。もう一度アップロードしてください。');
        }

        try {
            // ⚠ 確定でも検査をやり直す（画面が送ってきた hidden を信用しない）
            $analysis = $this->analyze($content);
        } catch (CsvImportException $e) {
            return redirect()->route('approvals.admin.users.import')->with('error', $e->getMessage());
        }

        if ($analysis['rowErrors'] !== []) {
            return redirect()->route('approvals.admin.users.import')
                ->with('error', '取り込めない行があります。もう一度アップロードして内容を確認してください。');
        }

        if ($analysis['validCount'] === 0) {
            return redirect()->route('approvals.admin.users.import')->with('error', '取り込む行がありません。');
        }

        if (! OneTimeAction::claim((string) $request->input('guide_token'))) {
            return redirect()->route('approvals.admin.users.import')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度アップロードしてください。');
        }

        $entries = DB::transaction(fn () => $this->apply($analysis['rows']));

        // 新規登録では通知メールを送らない（紙で渡す。要件 8.1 に無い）
        return (new LoginGuide($entries, notifiedCount: 0, skippedCount: 0))->toResponse($request);
    }

    /**
     * 行ごとに検査して、取り込む行・エラー・注意に分ける。
     *
     * @return array{rows: list<array>, rowErrors: list<array>, warnings: list<array>, validCount: int, createCount: int, updateCount: int, totalRows: int}
     */
    private function analyze(string $content): array
    {
        $raw = CsvImportReader::parse($content, self::COLUMN_MAP, array_values(self::COLUMN_MAP));

        $max = (int) config('approval.csv_max_rows');
        if (count($raw) > $max) {
            throw new CsvImportException("1 回に取り込めるのは {$max} 行までです（今回は " . count($raw) . ' 行）。分けてから取り込んでください。');
        }

        $departments  = ApprovalDepartment::pluck('id', 'code');
        $hasDomain    = ApprovalMailDomain::exists();
        $seenNumbers  = [];
        $seenEmails   = [];

        // 同じファイルの中の重複を先に数える（重なった行は両方エラーにする）
        foreach ($raw as $row) {
            $number = LoginId::normalize($row['employee_number']);
            $email  = LoginId::normalize($row['email']);
            if ($number !== '') { $seenNumbers[$number] = ($seenNumbers[$number] ?? 0) + 1; }
            if ($email !== '')  { $seenEmails[$email] = ($seenEmails[$email] ?? 0) + 1; }
        }

        $digitWidths = $this->numericWidths(array_keys($seenNumbers));

        $rows = $rowErrors = $warnings = [];
        $createCount = $updateCount = 0;

        foreach ($raw as $index => $row) {
            $line   = $index + 2; // 1 行目は見出し
            $number = LoginId::normalize($row['employee_number']);
            $email  = LoginId::normalize($row['email']);
            $name   = trim($row['name']);

            if ($number === '') {
                $rowErrors[] = ['row' => $line, 'message' => '社員番号が空です'];
                continue;
            }
            if (! preg_match(LoginId::EMPLOYEE_NUMBER_PATTERN, $number)) {
                $rowErrors[] = ['row' => $line, 'message' => "社員番号「{$row['employee_number']}」は英数字とハイフン 20 文字までで入力してください"];
                continue;
            }
            if ($name === '' || mb_strlen($name) > 100) {
                $rowErrors[] = ['row' => $line, 'message' => '氏名が空、または 100 文字を超えています'];
                continue;
            }
            if (($seenNumbers[$number] ?? 0) > 1) {
                $rowErrors[] = ['row' => $line, 'message' => "社員番号「{$number}」が同じファイルの中で重複しています"];
                continue;
            }
            if ($email !== '' && ($seenEmails[$email] ?? 0) > 1) {
                $rowErrors[] = ['row' => $line, 'message' => "メールアドレス「{$email}」が同じファイルの中で重複しています"];
                continue;
            }
            if ($email !== '') {
                if (! $hasDomain) {
                    $rowErrors[] = ['row' => $line, 'message' => '許可するメールのドメインが登録されていません（部門の管理で登録してください）'];
                    continue;
                }
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $rowErrors[] = ['row' => $line, 'message' => "メールアドレス「{$email}」の形式が正しくありません"];
                    continue;
                }
                if (! ApprovalMailDomain::allows($email)) {
                    $rowErrors[] = ['row' => $line, 'message' => "メールアドレス「{$email}」は許可されていないドメインです"];
                    continue;
                }
            }

            $codes = $this->splitDepartmentCodes($row['departments']);
            if ($codes === []) {
                $rowErrors[] = ['row' => $line, 'message' => '所属部門を 1 つ以上入力してください'];
                continue;
            }
            $unknown = array_values(array_diff($codes, $departments->keys()->all()));
            if ($unknown !== []) {
                $rowErrors[] = ['row' => $line, 'message' => '登録されていない部門です: ' . implode('、', $unknown)];
                continue;
            }

            // 既存との照合（削除済みも含めて引く）
            $byNumber = User::withTrashed()->where('employee_number', $number)->first();
            $byEmail  = $email === '' ? null : User::withTrashed()->where('email', $email)->first();

            if ($byNumber && $byEmail && $byNumber->id !== $byEmail->id) {
                $rowErrors[] = ['row' => $line, 'message' => "社員番号は {$byNumber->name} さん、メールアドレスは {$byEmail->name} さんと一致します"];
                continue;
            }

            $existing = $byNumber ?? $byEmail;

            if ($existing && $existing->trashed()) {
                $rowErrors[] = ['row' => $line, 'message' => "削除済みの利用者（{$existing->name}さん）と一致します。基幹の管理者に復元を依頼してください"];
                continue;
            }

            // ここから先は取り込むと決めた行。注意はこの位置でだけ積む
            if ($existing) {
                $updateCount++;

                if ($existing->employee_number !== $number) {
                    $warnings[] = ['row' => $line, 'message' => "ログインIDが {$existing->employee_number} から {$number} に変わります"];
                }
                if ($existing->name !== $name) {
                    $warnings[] = ['row' => $line, 'message' => "氏名が登録と違います（変えません）: 登録「{$existing->name}」/ CSV「{$name}」"];
                }
                if ($email !== '' && $existing->email !== $email) {
                    $warnings[] = ['row' => $line, 'message' => 'メールアドレスは変えません（変更は基幹の管理者に依頼してください）'];
                }
                if (! $existing->isActive()) {
                    $warnings[] = ['row' => $line, 'message' => '無効の利用者です（有効化は利用者の管理で行ってください）'];
                }
            } else {
                $createCount++;

                if (isset($digitWidths['max'], $digitWidths['widths'][$number]) && $digitWidths['widths'][$number] < $digitWidths['max']) {
                    $warnings[] = ['row' => $line, 'message' => "社員番号 {$number} は、ほかの行より桁が少ないです。Excel で先頭の 0 が落ちていませんか"];
                }
            }

            $rows[] = [
                'line'            => $line,
                'existing_id'     => $existing?->id,
                'employee_number' => $number,
                'name'            => $name,
                'email'           => $email === '' ? null : $email,
                'department_ids'  => $departments->only($codes)->values()->all(),
                'department_codes'=> $codes,
            ];
        }

        return [
            'rows'        => $rows,
            'rowErrors'   => $rowErrors,
            'warnings'    => $warnings,
            'validCount'  => count($rows),
            'createCount' => $createCount,
            'updateCount' => $updateCount,
            'totalRows'   => count($raw),
        ];
    }

    /** 取り込む（新規は作り、既存は社員番号と所属部門だけ書き換える） */
    private function apply(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if ($row['existing_id'] !== null) {
                $user   = User::findOrFail($row['existing_id']);
                $before = ['employee_number' => $user->employee_number];

                $user->employee_number = $row['employee_number'];
                $user->save();

                $user->approvalDepartments()->sync($row['department_ids']);

                SettingLogger::record('user.imported_updated', 'user', $user->id, $before, [
                    'employee_number' => $user->employee_number, 'departments' => $row['department_codes'],
                ]);

                continue;
            }

            $password = InitialPassword::generate();

            $user = new User();
            $user->name = $row['name'];
            $user->employee_number = $row['employee_number'];
            $user->email = $row['email'];
            $user->role = UserRole::ApprovalOnly->value;
            $user->status = UserStatus::Active->value;
            $user->must_change_password = true;
            $user->forceFill(['password' => InitialPassword::hash($password)]);
            $user->save();

            $user->approvalDepartments()->sync($row['department_ids']);

            SettingLogger::record('user.imported_created', 'user', $user->id, [], [
                'name' => $user->name, 'employee_number' => $user->employee_number,
                'email' => $user->email, 'departments' => $row['department_codes'],
            ]);

            $entries[] = ['user' => $user, 'password' => $password];
        }

        return $entries;
    }

    /**
     * 所属部門の並びを分ける。**英字以外はすべて区切りとみなす**
     * （カンマ・スラッシュ・空白・読点・中黒。全角も可）。
     */
    private function splitDepartmentCodes(string $value): array
    {
        $value = mb_strtoupper(mb_convert_kana($value, 'as'), 'UTF-8');
        $parts = preg_split('/[^A-Z]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($parts ?: []));
    }

    /**
     * 数字だけの社員番号の桁数（Excel が先頭の 0 を落とした行を見つけるため）。
     *
     * @return array{max: int|null, widths: array<string, int>}
     */
    private function numericWidths(array $numbers): array
    {
        $widths = [];
        foreach ($numbers as $number) {
            if (ctype_digit($number)) {
                $widths[$number] = strlen($number);
            }
        }

        return ['max' => $widths === [] ? null : max($widths), 'widths' => $widths];
    }
}
```

- [ ] **Step 5: 画面を書く**

`resources/views/approvals/admin/users/import.blade.php` の構成:

1. 説明（列の名前・所属部門はアルファベット・1 ファイル `config('approval.csv_max_rows')` 行まで・
   新規はすべて決裁のみ利用者になる・メールアドレスは許可するドメインだけ）
2. テンプレートのダウンロードリンク
3. アップロードのフォーム（`enctype="multipart/form-data"`・`csv_file`）
4. `@isset($rows)` のときだけプレビュー（`_import_preview.blade.php` を `@include`）:
   - 件数のチップ（新規 N / 更新 M / エラー K / 注意 W）
   - **エラーの一覧**（`rowErrors`。赤）と**注意の一覧**（`warnings`。黄）を**別々のブロック**で出す
   - 取り込む行の表（行 / 社員番号 / 氏名 / メールアドレス / 所属部門 / 区分（新規・更新））
   - `@if($validCount > 0 && $rowErrors === [])` のときだけ確定フォーム:

```blade
        <form method="POST" action="{{ route('approvals.admin.users.import.execute') }}"
              onsubmit="return confirm('{{ $validCount }} 件を取り込みます。印刷用の案内が開きます。よろしいですか。');">
            @csrf
            <input type="hidden" name="csv_data" value="{{ $csvData }}">
            <input type="hidden" name="guide_token" value="{{ $guideToken }}">
            <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">取り込む</button>
        </form>
```

⚠ **`errors` というキーを view に渡さない**（Bug #53）。行エラーは `rowErrors`。

- [ ] **Step 6: 通ることを確かめる → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalUserImportTest|ImportPreviewRenderTest'
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add app/Http/Controllers/Approval/UserImportController.php resources/views/approvals/admin/users/ routes/approval.php tests/Feature/Approval/ApprovalUserImportTest.php
git commit -m "$(cat <<'EOF'
feat(approval): 社員の CSV 一括登録を足す

社員番号・氏名・メールアドレス・所属部門の 4 列。既存の利用者に当たる行は
社員番号と決裁の所属部門だけを書き換え、氏名・メールアドレス・ロール・状態は
変えずに注意として出す。削除済みの利用者に当たる行はエラーにする（一意索引に
当たって取込全体が巻き戻るのを防ぐ。Bug #60）。

確定は 1 つのトランザクションで、確定の時にサーバーが検査をやり直す
（書き換えた hidden を信用しない）。結果はログイン案内の画面。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---
## Task 14: サイドバー・和名の確認・ドキュメント

**Files:**
- Create: `resources/views/layouts/partials/sidebar_approval.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/layouts/partials/sidebar.blade.php`
- Modify: `tests/Feature/LayoutSidebarCloakTest.php`
- Modify: `CLAUDE.md` / `docs/ARCHITECTURE.md` / `docs/BACKLOG.md`
- Test: `tests/Feature/Approval/ApprovalSidebarTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/ApprovalSidebarTest.php`:

```php
<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\ApprovalMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * サイドバーの出し分け（設計書 §5.15）。
 *
 * ⚠ 一般の利用者に見える変化は増やさない（D2）。決裁の管理へのリンクは、
 *   決裁の管理者に指定された人にだけ出す。
 * ⚠ Bug #56 の決まりを守る（展開サイドバーに `x-cloak` を付けない）。
 */
class ApprovalSidebarTest extends TestCase
{
    use RefreshDatabase;

    private function approvalAdmin(string $role = UserRole::Staff->value): User
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    /** 決裁のみ利用者には決裁用のサイドバーだけが出る */
    public function test_an_approval_only_user_gets_the_approval_sidebar(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        $this->assertStringContainsString('決裁のホーム', $html);
        $this->assertStringNotContainsString('テナントダッシュボード', $html, '基幹のサイドバーが出ている');
        $this->assertStringNotContainsString('システム管理', $html);
        // 管理者でなければ管理のリンクは出ない
        $this->assertStringNotContainsString(route('approvals.admin.users.index'), $html);
    }

    public function test_an_approval_only_admin_sees_the_management_links(): void
    {
        $user = $this->approvalAdmin(UserRole::ApprovalOnly->value);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        $this->assertStringContainsString(route('approvals.admin.users.index'), $html);
        $this->assertStringContainsString(route('approvals.admin.organization.index'), $html);
    }

    /** 基幹を使う人で決裁の管理者に指定された人は、基幹のサイドバーに「決裁の管理」が増える */
    public function test_a_base_user_with_the_flag_gets_an_extra_group(): void
    {
        $user = $this->approvalAdmin();

        $html = $this->actingAs($user)->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString('決裁の管理', $html);
        $this->assertStringContainsString(route('approvals.admin.users.index'), $html);
        $this->assertStringContainsString('テナントダッシュボード', $html, '基幹のサイドバーが消えている');
    }

    /** 指定されていない人には何も増えない（D2） */
    public function test_nothing_changes_for_everyone_else(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $html = $this->actingAs($user)->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringNotContainsString('決裁', $html, '一般の利用者の画面に決裁の文字が出ている');
    }

    /** ヘッダーのロール表示は自動で「決裁のみ」になる */
    public function test_the_header_shows_the_role_label(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $this->actingAs($user)->get(route('approvals.home'))->assertOk()->assertSee('決裁のみ');
    }

    /** 決裁のサイドバーも Bug #56 の決まりを守る */
    public function test_the_approval_sidebar_follows_the_cloak_rules(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        preg_match_all('#<aside\b[^>]*\bx-show="sidebarExpanded"[^>]*>#s', $html, $expanded);
        $this->assertCount(1, $expanded[0], 'PC 展開サイドバーの <aside> が 1 本に定まらない');
        $this->assertStringNotContainsString('x-cloak', $expanded[0][0], '展開サイドバーに x-cloak が付いている（Bug #56）');

        foreach (['!sidebarExpanded', 'sidebarOpen'] as $xShow) {
            preg_match_all('#<aside\b[^>]*\bx-show="' . preg_quote($xShow, '#') . '"[^>]*>#s', $html, $m);
            $this->assertCount(1, $m[0], "{$xShow} の <aside> が 1 本に定まらない");
            $this->assertStringContainsString('x-cloak', $m[0][0], "{$xShow} の x-cloak が無い");
        }
    }
}
```

- [ ] **Step 2: 落ちることを確かめる → 決裁用サイドバーを書く**

`resources/views/layouts/partials/sidebar_approval.blade.php`:

```blade
{{-- モバイル用オーバーレイ --}}
<div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 bg-black/50 z-20 lg:hidden"
     x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     style="display: none;"></div>

@php
    $isApprovalAdmin = Auth::user()->isApprovalAdmin();
@endphp

{{-- ========== PC用: 展開サイドバー ========== --}}
{{-- ⚠ x-cloak を付けない（Bug #56。sidebarExpanded は body の x-data で true 固定なので起動後は必ず表示される。
     x-cloak があると Alpine の起動前だけ消えて、パース中に走るスクリプトが表示領域を 220px 広く測る） --}}
<aside x-show="sidebarExpanded" class="hidden lg:flex flex-col w-[220px] min-w-[220px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6 transition-all duration-200">
    <div class="mb-1">
        <div class="flex items-center justify-between px-5 py-2">
            <span class="text-[13px] font-bold text-emerald-600 tracking-wide">決裁申請</span>
            <button @click="sidebarExpanded = false" title="サイドバーを閉じる"
                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-300 bg-gray-50 text-[10px] text-gray-500 hover:bg-gray-100 cursor-pointer">閉じる</button>
        </div>
        <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
        @if($isApprovalAdmin)
            <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
            <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
        @endif
    </div>
</aside>

{{-- ========== PC用: 折りたたみサイドバー ========== --}}
<aside x-show="!sidebarExpanded" x-cloak class="hidden lg:flex flex-col items-center w-[56px] min-w-[56px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6">
    <button @click="sidebarExpanded = true" title="サイドバーを開く" class="w-9 h-9 mb-3 rounded-lg flex items-center justify-center hover:bg-gray-100 cursor-pointer">›</button>
    <a href="{{ route('approvals.home') }}" title="決裁のホーム" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.home') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">決</a>
    @if($isApprovalAdmin)
        <a href="{{ route('approvals.admin.users.index') }}" title="利用者の管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.admin.*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">設</a>
    @endif
</aside>

{{-- ========== モバイル用: ドロワーサイドバー ========== --}}
<aside x-show="sidebarOpen" x-cloak class="fixed inset-y-0 left-0 w-[240px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6 z-30 lg:hidden"
       x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
    <div class="px-5 py-2 text-[13px] font-bold text-emerald-600 tracking-wide">決裁申請</div>
    <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
    @if($isApprovalAdmin)
        <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
        <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
    @endif
</aside>
```

- [ ] **Step 3: レイアウトで出し分ける**

`resources/views/layouts/app.blade.php` の `@include('layouts.partials.sidebar')` を:

```blade
            {{-- 決裁のみ利用者には決裁用のサイドバーを出す（設計書 §5.15） --}}
            @include(Auth::user()->isApprovalOnly() ? 'layouts.partials.sidebar_approval' : 'layouts.partials.sidebar')
```

- [ ] **Step 4: 基幹のサイドバーに「決裁の管理」を足す（3 か所）**

`sidebar.blade.php` の `@php` ブロックに `$isApprovalAdmin = $user->isApprovalAdmin();` を足し、
**展開版（146 行あたり）・折りたたみ版（297 行あたり）・ドロワー（422 行あたり）**の
「システム管理」の**前**に:

```blade
    {{-- 決裁の管理（決裁の管理者に指定された人だけ。設計書 §5.15・D2） --}}
    @if($isApprovalAdmin)
        <x-sidebar-group label="決裁の管理" section="approval">
            <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
            <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
        </x-sidebar-group>
    @endif
```

折りたたみ版は 1 つのアイコンリンクだけにする（既存の「システム管理」と同じ形）。

- [ ] **Step 5: `LayoutSidebarCloakTest` を決裁のサイドバーにも広げる**

既存の 3 本はそのまま（基幹のサイドバーを見ている）。決裁のサイドバーは
`ApprovalSidebarTest::test_the_approval_sidebar_follows_the_cloak_rules` が見るので、
`LayoutSidebarCloakTest` の docblock に**その参照を 1 行足す**:

```php
 * ⚠ 決裁のみ利用者に出る `sidebar_approval.blade.php` は
 *   `tests/Feature/Approval/ApprovalSidebarTest.php` が同じ決まりを見る。**両方を対で維持すること。**
```

- [ ] **Step 6: 和名の走査が緑であることを確かめる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter JapaneseValidationMessagesTest
```

⚠ 赤なら `lang/ja/validation.php` の `attributes` に足りない和名がある。
**メッセージで隠さず `attributes` に足す**（Bug #37）。

- [ ] **Step 7: ドキュメントを直す**

`CLAUDE.md`:
- 「Laravel-specific quirks」の **`User` モデルに `deleted_at` 列なし → … `whereNull('deleted_at')` 禁止」を削除**
  （2026-07-03 に SoftDeletes を入れたので古い）。代わりに:
  `- `User` は SoftDeletes。ログイン ID は社員番号（`employee_number`）とメールアドレスの**どちらか**（`App\Support\LoginId` で正規化）。`role` の 4 つ目は `approval_only`（決裁のみ）で、web グループの門番が決裁以外の全画面から締め出す`
- 「Completed modules」に `| 決裁申請 段階1 | `/approvals/*` | `Approval\*Controller`（門番 2 本・CSV・ログイン案内）|` を足す

`docs/ARCHITECTURE.md`:
- 「Directory Structure」の `routes/` の説明から「buyer_routes.php, housing_routes.php 等インクルード」を削り、
  **`approval.php` を読み込む**ことを書く（実測でほかのファイルは無い）
- 「Key Database Tables」に `approval_*` の 7 表を足す
- 「Authentication & Authorization」の Roles に `approval_only` を足す

`docs/BACKLOG.md`:
- 新しい節「## ✅ 決裁申請 段階1（基幹の改修）」を足し、設計書・この計画・主な決定・
  本番反映の手順・未了の項目を書く（既存の節の書き方に合わせる）

- [ ] **Step 8: 全テスト → コミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
git add resources/views/layouts/ tests/Feature/ CLAUDE.md docs/
git commit -m "$(cat <<'EOF'
feat(approval): サイドバーの出し分けとドキュメントを直す

決裁のみ利用者には決裁用のサイドバー（ホーム＋管理者ならリンク 2 本）を出し、
基幹を使う人で決裁の管理者に指定された人には基幹のサイドバーに「決裁の管理」を
足す。それ以外の人の画面は変わらない（D2）。

CLAUDE.md の「User に deleted_at 列なし」（2026-07-03 から古い）を直し、
ARCHITECTURE のルートと表の一覧、BACKLOG に段階1 の節を足した。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: 変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

- [ ] **Step 1: 作法を確かめる**

```bash
git status --porcelain   # 空であること（前の変異の残骸で測定が汚れる）
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3   # 全緑であること
```

各変異ごとに: ①`git status --porcelain` が空 → ②変異を当てる → ③`git diff --stat` が**非空**
（当たったことの確認）→ ④テストを流す → ⑤`git checkout -- <ファイル>` → ⑥`git status --porcelain` が空。

⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。**
意図と別の機構が落としているなら、その変異の測定は無効。

⚠ **カナリアを先に通す** — `resources/views/approvals/home.blade.php` に `{{ $undefinedVariable }}` を足して
`ApprovalHomeTest` が赤になることを確かめる（測定装置が worktree のコードを読んでいることの証明）。

- [ ] **Step 2: 変異を当てて表を埋める**

| # | 変異 | 期待して落ちるテスト |
|---|---|---|
| M01 | `RestrictApprovalOnlyUsers::ALLOWED_NAMES` に `'dashboard.tenant'` を足す | `ApprovalOnlyLockoutTest::test_every_route_is_classified_and_blocked` |
| M02 | `RestrictApprovalOnlyUsers::isAllowed()` の `str_starts_with($name, 'approvals.')` を `true` に | 同上 |
| M03 | `bootstrap/app.php` の `appendToPriorityList` を 2 本とも消す | `..._blocked`（404 になる）＋ `..._gates_run_before_route_model_binding` |
| M04 | `RestrictApprovalOnlyUsers` を `web(append: …)` から外す | `..._blocked` ほか多数 |
| M05 | GET の分岐を消して常に 403 | `..._blocked`（転送されない）＋ `..._reason_is_shown_after_the_redirect` |
| M06 | Ajax の分岐を消して常に転送 | `..._ajax_requests_get_403` |
| M07 | `EnsureUserIsActive` の `isActive()` を `true` に | `InactiveUserLockoutTest` の 4 本 |
| M08 | `EnsureUserIsActive` から `Auth::logout()` を外す（セッション破棄だけ） | `..._the_remember_token_is_cycled` |
| M09 | `AuthenticateSession` を `web(append: …)` から外す | `OtherDeviceLogoutTest` の 2 本 |
| M10 | `Limit::after()` を 2 本とも外す | `LoginThrottleTest::test_successful_logins_are_not_counted` |
| M11 | `LoginId::throttleKey()` の `normalize()` を外して生の入力に | `..._the_key_ignores_case_width_and_spaces` |
| M12 | `Limit::perMinutes` の引数を入れ替える | `..._five_failures_…`（5 回目で止まる） |
| M13 | `->response()` を外す | `..._the_block_is_explained_in_japanese_…`（429 の英語画面） |
| M14 | `LoginId::normalize()` の `mb_convert_kana` を外す | `LoginIdTest` ＋ `LoginIdentifierTest` の全角のケース |
| M15 | `LoginId::isEmail()` を `filter_var(..., FILTER_VALIDATE_EMAIL)` に | `LoginIdTest::test_is_email_looks_only_at_the_at_sign` |
| M16 | `User::homeRouteName()` の決裁のみの分岐を消す | `UserIdentityTest` ＋ `LoginIdentifierTest` の 2 本 |
| M17 | `scopeAssignable()` から `role != approval_only` を外す | `UserIdentityTest::test_assignable_excludes_approval_only_users` |
| M18 | 取込 3 か所の `baseUsers()` を 1 か所だけ外す | `..._import_name_lookups_are_scoped_to_base_users` |
| M19 | `users` migration の `role` の enum から `approval_only` を外す | `UsersSchemaTest` ＋ 決裁のみを作る全テスト |
| M20 | 同 migration を `->change()` 方式の別 migration に置き換える | `UsersSchemaTest::test_status_check_survives` |
| M21 | `User::booted()` の正規化を消す | `UserIdentityTest` の 3 本 |
| M22 | `InitialPassword::HASH_ROUNDS` を 12 に | `InitialPasswordTest::test_hash_uses_cost_10` ＋ `PasswordReissueTest` |
| M23 | `InitialPassword::generate()` から数字の 1 文字保証を外す | `InitialPasswordTest::test_generated_password_has_the_specified_shape`（確率的に落ちる。**200 回まわしている**） |
| M24 | `LoginGuide` の `Cache-Control` を外す | `LoginGuideTest::test_the_page_is_not_cached` ＋ `UserManagementApprovalTest` |
| M25 | `LoginGuide` の `<symbol>`／`<use>` をページごとの `<svg>` 複製に | `..._embeds_the_qr_once_and_uses_it_per_page` |
| M26 | `OneTimeAction::claim()` を `Cache::put` + `true` 返しに | `..._a_token_can_be_claimed_only_once` ＋ 再発行と取込の 2 本 |
| M27 | `Admin\UserController::store()` を `redirect()` に戻す | `..._creating_a_user_renders_the_login_guide` |
| M28 | `resetPassword()` に `->with('reset_password', …)` を戻す | `..._keeps_nothing_in_the_session` |
| M29 | 社長の守り 3 か所を 1 つずつ外す（3 通り） | `..._the_president_is_protected` |
| M30 | `setPresident()` の `whereNotNull('email')` を外す | `..._a_user_without_an_email_cannot_be_the_president` |
| M31 | ロールを決裁のみにしたときの `departments()->detach()` を外す | `..._switching_to_approval_only_drops_the_base_departments` |
| M32 | `EnsureApprovalAdmin` を `isExecutive()` 判定に | `OrganizationManagementTest` の 2 本 ＋ `ApprovalUserManagementTest` |
| M33 | 会社・部門の削除の歯止めを外す（2 通り） | `..._cannot_be_deleted` |
| M34 | `ApprovalMailDomain::allows()` を部分一致（`str_ends_with`）に | 新しく 1 本足す（`allows('a@evil-mitsuwat.co.jp')` が false）|
| M35 | `UserController::canEditIdentity()` の `hasApprovalPrivileges()` を外す | `..._a_privileged_users_name_and_number_are_read_only`（3 通り） |
| M36 | `assertManageable()` の D16 の分岐を外す | `..._cannot_be_reissued` / `..._cannot_be_disabled`（6 通り） |
| M37 | `manageableQuery()` の `whereDoesntHave('approvalMember', …)` を外す | `..._skipped_by_the_filtered_bulk_reissue` |
| M38 | `manageableQuery()` の `whereNotIn(president)` を外す | 同上（社長のケース） |
| M39 | 一覧の選択欄の `hasApprovalPrivileges()` の分岐を外す | `..._does_not_offer_a_checkbox_for_privileged_users` |
| M40 | `reissueBulk()` の `filtered` を「画面が送った `user_ids`」に | `..._filtered_mode_re_runs_the_query_on_the_server` |
| M41 | `execute()` の `analyze()` のやり直しを消して hidden を信用する | `..._the_server_revalidates_on_confirm` |
| M42 | 取込の削除済みの照合（`withTrashed()`）を外す | `..._a_row_matching_a_deleted_user_is_an_error` |
| M43 | 取込の重複の事前カウントを外す | `..._duplicates_within_the_file_fail_both_rows` |
| M44 | 取込の view のキーを `rowErrors` → `errors` に | `ApprovalUserImportTest` ＋ `ImportPreviewRenderTest` |
| M45 | 取込で既存の `name` も書き換える | `..._updates_only_the_number_and_departments` |
| M46 | 取込の注意を採用位置でなく先頭で積む | 新しく 1 本足す（エラー行に注意が並ばないこと） |
| M47 | `splitDepartmentCodes()` の区切りをカンマだけに | `..._departments_can_be_separated_in_many_ways`（5 通り） |
| M48 | サイドバーの出し分けを基幹固定に | `ApprovalSidebarTest` の 2 本 |
| M49 | `sidebar_approval` の展開サイドバーに `x-cloak` を足す | `..._follows_the_cloak_rules` |
| M50 | 基幹のサイドバーの「決裁の管理」を無条件に出す | `..._nothing_changes_for_everyone_else` |

- [ ] **Step 3: 検出できなかった変異にテストを足す**

⚠ **表を終えた時点で漏れが 0 件なら、測り方を疑う。** 同じ行の隣接する不変条件
（例: `EnsureUserIsActive` の `session()->invalidate()` だけを外す）にも当ててみる。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出 / 当初検出漏れ→追加で検出 / 等価変異 を区別して表に書き足す。

```bash
git add docs/superpowers/plans/2026-09-16-approval-phase1.md tests/
git commit -m "$(cat <<'EOF'
test(approval): 変異テストの実測結果を記録し、見つかった穴を塞ぐ

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 16: ローカルの実ブラウザ確認

**Files:** なし（測るだけ）

テストが原理的に測れない領域を見る。⚠ **`preview_start` は使わない**。
使い捨ての SQLite ＋ `artisan serve`（memory「ローカル検証の環境事実」の手順）。

- [ ] **Step 1: 使い捨ての環境を作る**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase1
DB=$(mktemp -t approval-phase1-XXXX.sqlite)
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" DB_CONNECTION=sqlite DB_DATABASE="$DB" \
  php artisan migrate --force
```

利用者・会社・部門・ドメインを入れるのは `artisan tinker --execute` で（`database/seeders` は作らない）。
⚠ **ログイン用の使い捨てルートを作らない**（作ってもコミットしない）。ログインは画面から行う。
⚠ `node_modules` / `public/build` が worktree に無いので、CSS が要る確認は
`<main repo>/node_modules/.bin/vite build` を cwd=worktree で 1 回流す。

- [ ] **Step 2: 見ること**

| # | 画面 | 見ること |
|---|---|---|
| 1 | ログイン（375px） | 「社員番号またはメールアドレス」の欄が 1 つ。社員番号でログインできる。スマホのキーボードが英数字（`type="text"` + `autocapitalize="none"`） |
| 2 | ログイン | 6 回失敗して**日本語**の「〇秒後にもう一度お試しください。」が出る。入力とチェックが残る |
| 3 | 決裁のみ利用者 | 初回ログイン → パスワード変更 → 決裁のホームに着く |
| 4 | 決裁のみ利用者 | `/dashboard/tenant` を URL で開くと決裁のホームへ転送され「決裁以外の画面は使えません。」が出る |
| 5 | 決裁のみ利用者 | サイドバー 3 か所（1440px 展開 / 折りたたみ / 375px ドロワー）に決裁の項目だけが出る |
| 6 | 基幹の利用者管理 | 新規登録 → **案内の画面**が開く。初期パスワードの欄が無い |
| 7 | **案内の印刷プレビュー（Chrome）** | **1 人 1 ページ**・QR が**全ページに出る**（`<use>` が効いている）・余白に日時や URL が印刷されない |
| 8 | **案内の印刷プレビュー（Safari）** | 同上（⚠ `<use>` の対応を Chrome だけで判断しない） |
| 9 | 案内 | 印刷せずにタブを閉じようとすると確認が出る。印刷したあとは出ない |
| 10 | 案内 | 戻るボタンでこの画面に戻れない（`no-store`） |
| 11 | 再発行 | 同じ画面で 2 回送ると「この操作はすでに実行されました。」（ブラウザの再送信で確かめる） |
| 12 | 部門の管理 | 会社 → 部門 → ドメインを登録できる。モーダルの `<select>` が正しい値で開く |
| 13 | 利用者の管理 | 決裁の権限を持つ人の行に選択欄が出ず、**ホバーで理由が出る**（Bug #43。`<span title>` に置いたか） |
| 14 | CSV 取込 | テンプレートを落として 3 行入れて上げ、プレビュー → 確定 → 案内の画面 |
| 15 | 全画面 | `main.scrollWidth === main.clientWidth` を **6 画面 × 1800 / 1200 / 375px = 18 通り**（Bug #29） |
| 16 | 全画面 | コンソールのエラー・警告が **0 件** |

- [ ] **Step 3: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:cache
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:clear
```

期待: INVALID 0 件。

- [ ] **Step 4: 片付けて結果を記録する**

```bash
rm -f "$DB"
git status --porcelain   # 空であること（使い捨てのコードが残っていないこと）
```

結果をこの計画に追記してコミットする。

---

## Task 17: 本番反映

> ⚠ **親セッションが利用者の承認を得てから行う。** サブエージェントに任せない。

- [ ] **Step 1: 承認を求める**

`AskUserQuestion` で本番反映の可否を聞く。⚠ **DB が先・`deploy.sh` が後**であることと、
**ログイン画面が変わる**ことを併せて伝える。

- [ ] **Step 2: 反映前に本番を読み取る**

Task 0 をまだ行っていなければここで行う。加えて:

```
- users の行数と、employee_number 列がまだ無いこと
- approval_* の 7 表がまだ無いこと
- role の enum に approval_only がまだ無いこと
```

- [ ] **Step 3: DB を先に変える**

`database/sql/2026-09-16-approval-phase1.sql` を **1 文ずつ** `artisan tinker --execute` の
`DB::statement()` で流す。⚠ `sudo mysql` は非対話でパスワードを渡せない。
⚠ `PDO::MYSQL_ATTR_MULTI_STATEMENTS` が未設定なので 2 文を 1 回で流せない。
⚠ 本番の既定 `php` は 7.4 なので `/usr/local/php/8.3/bin/php` を明示する。

流した後に `SHOW CREATE TABLE` で列・索引・enum を確かめる。

- [ ] **Step 4: 新しい依存を本番用に入れる**

```bash
cd /Users/masanori/site/manage
git checkout 13.x
git merge-base --is-ancestor 13.x approval-phase1 && echo "FF できる" || echo "13.x が進んでいる（ブランチへマージしてから）"
git merge --ff-only approval-phase1
composer install --no-dev
ls vendor/bin/phpunit 2>/dev/null && echo "⚠ dev が混ざっている（deploy.sh が本番へ送ってしまう）"
composer dump-autoload --no-dev --optimize
```

⚠ **`composer dump-autoload` は main repo の cwd で行う**（worktree から行うと autoloader の
`$baseDir` に worktree のパスが焼き込まれる）。新しいクラスが多いので**必須**。

- [ ] **Step 5: 反映**

```bash
./deploy.sh
```

- [ ] **Step 6: 本番で確かめる**（すべて読み取り）

| # | 見ること |
|---|---|
| 1 | **コンパイル済みビューの `php -l`**（269 → 約 275 本 / INVALID 0 件） |
| 2 | 新しいクラスの autoload（`LoginId` / `InitialPassword` / `LoginQrCode` / `chillerlan\QRCode\QRCode`） |
| 3 | ルートが登録されている（`approvals.home` ほか。`route:list` を grep） |
| 4 | ログイン画面が「社員番号またはメールアドレス」になっている |
| 5 | **メールアドレスで今までどおりログインできる**（利用者に確認してもらう。こちらはパスワードを入力しない） |
| 6 | 基幹の利用者管理が開き、社員番号の列が出る |
| 7 | **決裁の画面が誰にも見えない**（指定の前なので `/approvals/admin/*` は全員 403） |
| 8 | 一般の利用者のサイドバーに「決裁」の文字が無い |

⚠ **302 を「アプリは正常」の証明に使わない**（認証の転送はビューを描画する前に起きる）。

- [ ] **Step 7: 記録**

`docs/BACKLOG.md` の段階1 の節に、反映日・`13.x` のコミット・本番で確かめたことの表を書き足してコミットする。

⚠ **社員の一括登録とログイン案内の配布は、稼働の直前（要件 16.2 の 4）まで行わない**（D2）。
⚠ `origin/13.x` への push は利用者の明示の指示があったときだけ。

---
