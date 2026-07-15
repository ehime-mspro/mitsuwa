# 添付ファイルのブラウザ表示（inline）＋DL導線併設 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 添付ファイルの画像（jpeg/png/gif/webp）と PDF を別タブでブラウザ表示できるようにし、あわせて全行に「常にダウンロード」する ⬇ ボタンを併設する。

**Architecture:** 配信方法（inline / 強制DL）の判断を **`App\Support\AttachmentDelivery` の 1 クラスに集約**し、4 つの Controller はそこへ委譲するだけにする。inline 対象は**許可リスト（ホワイトリスト）**で決め、`Content-Type` は DB の `mime_type` ではなく**許可リストの正規化値**を使う（DB 値への信用を断つ）。ダウンロードは `?download=1` クエリで表現し、**新規ルートは追加しない**。

**Tech Stack:** Laravel 12 / PHP 8.3（ローカル CLI・本番とも）/ Blade + Alpine.js 3 / Tailwind v4（Vite ビルド済）/ PHPUnit 11.5（SQLite in-memory）

**設計の正:** `docs/superpowers/specs/2026-07-15-attachment-inline-view-design.md`（`83455ce6`・承認済み）

**作業ブランチ:** `worktree-attachment-inline-view`（`.claude/worktrees/attachment-inline-view`・起点 `83455ce6`）

---

## 実測で確定した事実（推測しないこと）

このプランを書く前に**実際に計測した**結果。ここに書かれた値は測定済みなので、そのまま使ってよい。

| # | 事実 | 測定方法 |
|---|---|---|
| F1 | **ルート経由の Feature テストは成立する（200 が返る）**。`attachments` テーブルは migration 管理下（`0001_01_01_000015_create_attachments_table.php`）にあり、SQLite テスト DB に存在する。`AttachmentController::show()` は親モデルをロードせず `attachable_type` の**文字列しか読まない**ため、`contracts` テーブルの行が無くても `attachable_type = Contract::class` の Attachment 行だけでテストできる | 使い捨てプローブテストを実行し 200 を確認 |
| F2 | **改修前の現状値は `'attachment; filename=probe.jpg'`**（jpeg でも強制DL＝これが直す対象のバグ） | 同上 |
| F3 | **`re_*` / `hs_*` / `ms_*` は migration が存在しない**（raw SQL 管理）。よって **Task 2 の 3 本はルート経由テストが書けない**。既存テストの注記は `re_*`/`hs_*`/`ms_*` については**正しく**、`attachments` についてだけ誤り | `ls database/migrations/ \| grep -iE "re_project\|hs_propert\|ms_"` → 0 件 |
| F4 | `Content-Disposition` は **クォート無し**: `'inline; filename=invoice.jpg'` / `'attachment; filename=invoice.jpg'`。`filename="..."` と**クォート付きで書くと不一致になる** | 実測 |
| F5 | 日本語ファイル名は **`'inline; filename=.jpg; filename*=utf-8\'\'%E8%A6%8B...'`**。`fallbackName()` = `str_replace('%','',Str::ascii($name))` が日本語を**全部落として `.jpg` だけ**にし、実名は `filename*` が運ぶ | 実測 |
| F6 | **`download()` は svg 実体に対し `Content-Type: image/svg+xml` を返す**（ファイル実体から判定するため）。**T5 で Content-Type をアサートしてはいけない** | 実測 |
| F7 | **明示した `Content-Type` は優先される**（`FilesystemAdapter::response()` の `$headers['Content-Type'] ??= $this->mimeType($path);`）。`.svg` 実体に `image/png` を明示 → `image/png` が出た＝**D4 は実際に効く** | 実測＋vendor ソース確認 |
| F8 | **⬇ ボタンに使う Tailwind クラスは全てコンパイル済み**。`hover:text-emerald-600` も**存在する**（設計書 §4.4 は「無い可能性が高い」と書いたが**誤り**）: 実体は `.hover\:text-emerald-600:hover{color:var(--color-emerald-600)}`。既に `tenant/analysis/index.blade.php` 等 3 本以上が使用しているため JIT がコンパイルしている | 後述 F9 の方法で実測 |
| F9 | **`public/build` は `.gitignore` 済み（`.gitignore:16`）＝ worktree に存在しない**。Tailwind の実測は**必ず main repo（`/Users/masanori/site/manage`）で**行う。worktree で grep すると全クラスが「MISSING」に見えて誤判断する | `git check-ignore -v public/build` |
| F10 | **`Illuminate\Http\Request` は 4 本すべての Controller で import 済み**。`use` 追加は不要（設計書 §4.2 の「未 import なら追加」は実測の結果**不要**と確定） | `grep -c '^use Illuminate\\Http\\Request;'` → 全て 1 |
| F11 | `download()` は `response($path, $name, $headers, 'attachment')` に委譲する。よって **inline / 強制DL とも戻り値は `StreamedResponse`** | vendor ソース確認 |
| F12 | Alpine に渡る `file_path` は**すべてクエリ文字列を持たない素のルート URL**（`route('attachments.show', ...)` 等）。よって `file.file_path + '?download=1'` の文字列連結は安全 | 4 箇所の Controller を確認 |

---

## File Structure

| ファイル | 責務 | 本プランでの変更 |
|---|---|---|
| `app/Support/AttachmentDelivery.php` | **新規**。配信方法の判断を持つ唯一の箇所（許可リスト＋正規化） | 新規作成。`app/Support/` の既存クラス（`Settings.php` / `ZealFiscalYear.php`）に倣い素の静的メソッドクラス |
| `tests/Feature/AttachmentDeliveryTest.php` | **新規**。ルート経由で disposition を検証 | 新規作成（8 ケース） |
| `app/Http/Controllers/AttachmentController.php` | 汎用添付（テナント3 + 不動産2 + マンション + DAD） | `show()` のみ差し替え（`:118-133`） |
| `app/Http/Controllers/RealEstate/ProjectController.php` | 分譲地PJ・区画図面 | `showDrawing()` のみ差し替え（`:590-612`） |
| `app/Http/Controllers/Housing/PropertyController.php` | 建売物件ファイル | `showFile()` のみ差し替え（`:338-351`） |
| `app/Http/Controllers/Housing/CustomOrderController.php` | 注文住宅ファイル | `showFile()` のみ差し替え（`:306-319`） |
| `resources/views/components/attachment-section.blade.php` | 共通添付セクション（**7 画面が一括で直る**） | 最終列に ⬇ 追加（`:97` `:113-119`） |
| `resources/views/tenant/contracts/show.blade.php` | 解約精算書の単独リンク | ⬇ ボタン併設（`:252-258`） |
| `resources/views/realestate/projects/lots.blade.php` | 区画図面カード | ⬇ 追加（`:228-231`） |
| `resources/views/housing/properties/show.blade.php` | 建売物件ファイル行 | ⬇ 追加（`:276-282`） |
| `resources/views/housing/custom-orders/show.blade.php` | 注文住宅ファイル行 | ⬇ 追加（`:284-290`） |

**変更しないもの（意図的）:**
- `routes/web.php` — `?download=1` はクエリなのでルート定義は不要（**D5**）
- 各 `store()` の `mimes:` 制限 — 入口の防御は `c91dfdca` のまま維持
- `canAccessDepartmentOf()` / 部署ベース認可（IDOR 対策）
- `ReProjectDrawing::isImage()` — `showDrawing()` からは使わなくなるが、`lots.blade.php` のサムネイル出し分け（`is_image`）で**引き続き使用中**。削除しないこと
- admin の CSV テンプレート配信 3 本（**D7**・記入して再アップロードするものなので強制DLが正しい）
- `tests/Feature/Security/AttachmentAuthorizationTest.php` の注記 — F3 のとおり `re_*`/`hs_*`/`ms_*` については正しいので触らない

**タスク境界の根拠（壊れない中間コミット）:**
Task 1 は `AttachmentDelivery` 新規＋`AttachmentController::show` の差し替えまでを 1 単位にする（**テストが緑になる最小単位**。クラスだけ作っても誰も呼ばず検証できない）。Task 1 完了時点で他 3 本の Controller は**従来どおり動く**（強制DL のまま）ので壊れない。Task 2 は挙動が変わるが Task 1 で実証済みの共通クラスへ委譲するだけ。Task 3 は Blade のみで、サーバー挙動に影響しない。

---

## Task 1: `AttachmentDelivery` 新規作成 ＋ `AttachmentController::show` の切り替え

**Files:**
- Create: `app/Support/AttachmentDelivery.php`
- Create: `tests/Feature/AttachmentDeliveryTest.php`
- Modify: `app/Http/Controllers/AttachmentController.php:118-133`

**作業ディレクトリ:** `/Users/masanori/site/manage/.claude/worktrees/attachment-inline-view`（以下すべて worktree 内）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/AttachmentDeliveryTest.php` を新規作成:

```php
<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 添付ファイルの配信方法（inline 表示 / 強制ダウンロード）のテスト。
 *
 * attachable_type には Contract::class（tenant 部署）を使う。
 * AttachmentController::show() は親モデルをロードせず attachable_type の文字列しか
 * 読まないため、contracts テーブルの行が無くてもルート経由で検証できる。
 *
 * 検証するのは「配信方法（Content-Disposition）」であって Content-Type ではない。
 * 強制DL 時の Content-Type はファイル実体から判定されるため、
 * 許可リスト外の MIME に対して Content-Type をアサートしてはいけない。
 */
class AttachmentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DepartmentSeeder::class);

        $this->staff = User::factory()->create(['role' => UserRole::Staff->value]);
        $this->staff->departments()->attach(Department::where('code', 'tenant')->value('id'));
        $this->actingAs($this->staff);
    }

    /** 指定の名前・MIME で添付を1件作る（ファイル実体も fake ディスクに置く） */
    private function makeAttachment(string $fileName, string $mimeType, string $body = 'FAKEBYTES'): Attachment
    {
        $path = 'attachments/contracts/1/' . $fileName;
        Storage::disk('public')->put($path, $body);

        return Attachment::create([
            'attachable_type' => Contract::class,
            'attachable_id'   => 1,
            'file_name'       => $fileName,
            'file_path'       => $path,
            'file_size'       => strlen($body),
            'mime_type'       => $mimeType,
            'uploaded_by'     => $this->staff->id,
        ]);
    }

    /** T1: 画像（jpeg）は inline 配信され、Content-Type は許可リストの値・nosniff 付き */
    public function test_jpeg_is_served_inline(): void
    {
        $attachment = $this->makeAttachment('invoice.jpg', 'image/jpeg');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** T2: PDF は inline 配信される */
    public function test_pdf_is_served_inline(): void
    {
        $attachment = $this->makeAttachment('spec.pdf', 'application/pdf');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /** T3: xlsx は許可リスト外なので強制ダウンロード */
    public function test_xlsx_is_force_downloaded(): void
    {
        $attachment = $this->makeAttachment(
            'costs.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /** T4: ?download=1 なら inline 対象の画像でも強制ダウンロード（⬇ ボタンの挙動） */
    public function test_download_query_forces_attachment_for_image(): void
    {
        $attachment = $this->makeAttachment('invoice.jpg', 'image/jpeg');

        $response = $this->get(route('attachments.show', ['attachment' => $attachment->id, 'download' => 1]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * T5: DB に image/svg+xml が入っていても inline 配信しない（D4 の許可リスト検証）。
     *
     * Content-Type はアサートしない。download() はファイル実体から MIME を判定するため
     * svg 実体を置けば image/svg+xml がヘッダに出るのが正常。防御が成立している根拠は
     * Content-Disposition: attachment + nosniff であって Content-Type の書き換えではない。
     */
    public function test_svg_is_never_served_inline(): void
    {
        $attachment = $this->makeAttachment(
            'evil.svg',
            'image/svg+xml',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** T6: csv は Excel で開く運用なので強制ダウンロード（D3） */
    public function test_csv_is_force_downloaded(): void
    {
        $attachment = $this->makeAttachment('members.csv', 'text/csv');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /** T7: 日本語ファイル名でも inline 配信され、RFC 5987 の filename* で実名が保たれる */
    public function test_japanese_file_name_is_served_inline_with_rfc5987_name(): void
    {
        $attachment = $this->makeAttachment('見積書サンプル.jpg', 'image/jpeg');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        // Str::ascii() が日本語を落とすため filename= は '.jpg' だけになり、実名は filename* が運ぶ
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('見積書サンプル.jpg'), $disposition);
    }

    /**
     * T8: Content-Type は DB の mime_type ではなく許可リストの正規化値が使われる（D4 の核心）。
     *
     * image/pjpeg（古いブラウザが送る jpeg の別名）を DB に入れても、
     * 配信されるのは正規化後の image/jpeg でなければならない。
     * このテストが無いと「DB 値をそのまま渡す」実装に退行しても T1 は緑のまま気づけない。
     */
    public function test_content_type_is_normalized_from_allowlist_not_db_value(): void
    {
        $attachment = $this->makeAttachment('legacy.jpg', 'image/pjpeg');

        $response = $this->get(route('attachments.show', $attachment->id));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }
}
```

> **T8 は設計書 §5 の 7 ケースに対する追加。** 理由: D4（Content-Type は DB 値でなく許可リストの正規化値）を**直接検証するテストが §5 に無い**。T1 は `image/jpeg` → `image/jpeg` なので「DB 値をそのまま使った」場合でも緑になり、D4 を守っているか判別できない。`image/pjpeg` → `image/jpeg` だけがこれを区別できる。

- [ ] **Step 2: テストを実行して失敗を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit tests/Feature/AttachmentDeliveryTest.php
```

期待する結果: **`Tests: 4 failed, 4 passed`**（`Errors: 0`）

内訳（この内訳どおりでなければ前提が崩れているので止まって調査すること）:

| テスト | 結果 | 理由 |
|---|---|---|
| T1 `test_jpeg_is_served_inline` | **FAIL** | 現状は強制DL。`Failed asserting that 'attachment; filename=invoice.jpg' starts with "inline;"` |
| T2 `test_pdf_is_served_inline` | **FAIL** | 同上 |
| T3 `test_xlsx_is_force_downloaded` | PASS | 現状も強制DL（**回帰ガード**として先に緑） |
| T4 `test_download_query_forces_attachment_for_image` | PASS | 現状は `?download=1` を無視して常に強制DL |
| T5 `test_svg_is_never_served_inline` | PASS | 現状も強制DL（回帰ガード） |
| T6 `test_csv_is_force_downloaded` | PASS | 同上 |
| T7 `test_japanese_file_name_...` | **FAIL** | 現状は強制DL |
| T8 `test_content_type_is_normalized_...` | **FAIL** | 現状は強制DL |

「4 failed」は**正しい TDD の状態**。先に緑の 4 本は「強制DL を壊していないこと」を守る回帰ガードで、実装後も緑のままでなければならない。

- [ ] **Step 3: `AttachmentDelivery` を実装する**

`app/Support/AttachmentDelivery.php` を新規作成:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 添付ファイルの配信（inline 表示 / 強制ダウンロード）の判断を一箇所に集約する。
 *
 * 画像・PDF はブラウザの別タブで表示し、それ以外は強制ダウンロードする。
 * 保存型 XSS 対策として、Content-Type は DB の mime_type ではなく
 * 許可リストの正規化値のみを使う（アップロード時の mimes 制限と併せて二重防御）。
 */
class AttachmentDelivery
{
    /**
     * ブラウザで安全に inline 表示できる MIME → 配信に使う正規化済み Content-Type。
     *
     * heic/heif は Safari 以外で表示できないため、txt/csv は Excel で開く運用のため除外。
     * svg は script 実行が可能なため絶対に含めない。
     */
    private const INLINE_MIME_TYPES = [
        'image/jpeg'      => 'image/jpeg',
        'image/pjpeg'     => 'image/jpeg',
        'image/png'       => 'image/png',
        'image/gif'       => 'image/gif',
        'image/webp'      => 'image/webp',
        'application/pdf' => 'application/pdf',
    ];

    /**
     * ブラウザの別タブで表示できるファイルか。
     */
    public static function isInlineViewable(?string $mimeType): bool
    {
        return isset(self::INLINE_MIME_TYPES[strtolower((string) $mimeType)]);
    }

    /**
     * 添付ファイルのレスポンスを生成する。
     * $forceDownload = true（?download=1）または inline 非対応 MIME の場合は強制ダウンロード。
     */
    public static function make(
        string $path,
        string $fileName,
        ?string $mimeType,
        bool $forceDownload = false,
        string $disk = 'public',
    ): StreamedResponse {
        $storage = Storage::disk($disk);

        if ($forceDownload || ! self::isInlineViewable($mimeType)) {
            return $storage->download($path, $fileName, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $storage->response($path, $fileName, [
            // DB の mime_type をそのまま渡さない。許可リストの正規化値のみを使う。
            'Content-Type'           => self::INLINE_MIME_TYPES[strtolower((string) $mimeType)],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

- [ ] **Step 4: `AttachmentController::show` を差し替える**

`app/Http/Controllers/AttachmentController.php`。まず import を追加（`use App\Models\Attachment;` の直後、アルファベット順を保つ位置）:

```php
use App\Support\AttachmentDelivery;
```

> `use Illuminate\Http\Request;` は**既に import 済み**なので追加不要（F10）。`Storage` も存在チェックで引き続き使うので残す。

次に `show()`（`:118-133`）を以下で置き換える:

```php
    public function show(Request $request, Attachment $attachment)
    {
        // 部署ベースの認可（IDOR 対策）: 連番 ID 総当たりでの他部署添付の閲覧を防ぐ。
        // ファイル存在チェックより前に評価する。
        abort_unless($this->canAccessDepartmentOf($attachment->attachable_type), 403);

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404);
        }

        return AttachmentDelivery::make(
            $attachment->file_path,
            $attachment->file_name,
            $attachment->mime_type,
            $request->boolean('download'),
        );
    }
```

メソッド上の docblock（`:110-117`）は残しつつ、末尾の説明を実態に合わせて差し替える:

```php
    /**
     * ファイル表示・ダウンロード
     * GET /attachments/{attachment}（?download=1 で強制ダウンロード）
     *
     * 本番のディレクトリ構造（アプリ本体と Web 公開ディレクトリが別パス）では
     * public/storage シンボリックリンクが壊れるため、Apache 直配信ではなく
     * Laravel 経由で storage/app/public からストリーミング配信する。
     *
     * 配信方法（inline / 強制DL）の判断は AttachmentDelivery に集約している。
     */
```

- [ ] **Step 5: テストを実行して全て緑になることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit tests/Feature/AttachmentDeliveryTest.php
```

期待する結果: **`OK (8 tests, ...)`** — **8 tests すべてが緑**であること。

> アサーション**数**はここでは指定しない（未実測の数字を書くと、本質と無関係な不一致で手が止まるため）。判定基準は「8 tests が緑」。先に緑だった T3/T4/T5/T6 が赤に変わっていたら強制DL を壊しているので必ず止まって調査すること。

- [ ] **Step 6: 既存の添付認可テストが緑のままであることを確認する**

```bash
APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit tests/Feature/Security/AttachmentAuthorizationTest.php
```

期待する結果: **`OK (4 tests, 14 assertions)`**（`Request` 引数追加で認可が壊れていないことの確認）

> この 14 は**実測値**（改修前に実行して確認済み）。本タスクは認可に触らないので、この数字は改修後も変わらないはず。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
git add app/Support/AttachmentDelivery.php tests/Feature/AttachmentDeliveryTest.php app/Http/Controllers/AttachmentController.php
git commit -m "feat(attachment): 画像・PDF を inline 配信する AttachmentDelivery を追加"
```

---

## Task 2: 残り Controller 3 本を `AttachmentDelivery` に統一

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php:590-612`
- Modify: `app/Http/Controllers/Housing/PropertyController.php:338-351`
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php:306-319`

> **このタスクにルート経由テストは書けない。** `re_project_drawings` / `hs_property_files` / `hs_custom_order_files` は raw SQL 管理で migration が無く、SQLite テスト DB に存在しないため（F3）。配信ロジック本体は Task 1 の 8 テストで実証済みで、この 3 本は**同じ `AttachmentDelivery::make()` に委譲するだけ**なので、検証は `php -l` ＋ Task 4 の本番同等レンダリングで行う。

- [ ] **Step 1: `RealEstate\ProjectController::showDrawing` を差し替える**

import を追加（既存の `use App\...` 群のアルファベット順を保つ位置）:

```php
use App\Support\AttachmentDelivery;
```

`showDrawing()`（`:590-612`）を以下で置き換える:

```php
    public function showDrawing(Request $request, ReProject $project, ReProjectDrawing $drawing)
    {
        if ($drawing->project_id !== $project->id) {
            abort(403);
        }
        if (! Storage::disk('public')->exists($drawing->file_path)) {
            abort(404);
        }

        return AttachmentDelivery::make(
            $drawing->file_path,
            $drawing->file_name,
            $drawing->mime_type,
            $request->boolean('download'),
        );
    }
```

> **これは単なる統一ではなくセキュリティ修正でもある。** 旧コードの `if ($drawing->isImage())` は `str_starts_with($this->mime_type, 'image/')` なので **`image/svg+xml` が inline 配信され得た**（現状はアップロード側の `mimes:` だけで防いでいた）。許可リストに置き換えることでこの穴が閉じる。
>
> **`ReProjectDrawing::isImage()` は削除しないこと。** `lots.blade.php` のサムネイル出し分け（`$drawingsForJs` の `is_image`）で引き続き使用中。

- [ ] **Step 2: `Housing\PropertyController::showFile` を差し替える**

import を追加:

```php
use App\Support\AttachmentDelivery;
```

`showFile()`（`:338-351`）を以下で置き換える:

```php
    public function showFile(Request $request, HsProperty $property, HsPropertyFile $file)
    {
        if ($file->property_id !== $property->id) {
            abort(403);
        }
        if (! Storage::disk('public')->exists($file->file_path)) {
            abort(404);
        }

        return AttachmentDelivery::make(
            $file->file_path,
            $file->file_name,
            $file->mime_type,
            $request->boolean('download'),
        );
    }
```

- [ ] **Step 3: `Housing\CustomOrderController::showFile` を差し替える**

import を追加:

```php
use App\Support\AttachmentDelivery;
```

`showFile()`（`:306-319`）を以下で置き換える:

```php
    public function showFile(Request $request, HsCustomOrder $customOrder, HsCustomOrderFile $file)
    {
        if ($file->custom_order_id !== $customOrder->id) {
            abort(403);
        }
        if (! Storage::disk('public')->exists($file->file_path)) {
            abort(404);
        }

        return AttachmentDelivery::make(
            $file->file_path,
            $file->file_name,
            $file->mime_type,
            $request->boolean('download'),
        );
    }
```

- [ ] **Step 4: 構文チェックと横断確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
php -l app/Http/Controllers/RealEstate/ProjectController.php
php -l app/Http/Controllers/Housing/PropertyController.php
php -l app/Http/Controllers/Housing/CustomOrderController.php
```

期待する結果: 3 本とも `No syntax errors detected in ...`

配信箇所が 4 本すべて `AttachmentDelivery` 経由になったことを確認:

```bash
grep -rn "Storage::disk('public')->download\|Storage::disk('public')->response" app/Http/Controllers/
```

期待する結果: **出力ゼロ**

> 改修前にこの grep を実行すると**ちょうど 5 行**（`AttachmentController:130` / `ProjectController:603,609` / `PropertyController:348` / `CustomOrderController:316`）が出ることを実測済み。すべて本プランの改修対象なので、Task 1・2 完了後はゼロになる。
> admin の CSV テンプレート配信 3 本（`CustomerImportController` / `TenantImportController` / `MansionImportController`）は `Storage` を使わず `response($csv, 200, ['Content-Disposition' => 'attachment; ...'])` でメモリ上の文字列を返しているため、**元からこの grep には出ない**。D7 によりスコープ外なので**触らない**（既に強制DL で正しい）。

```bash
grep -rn "AttachmentDelivery::make" app/Http/Controllers/ | wc -l
```

期待する結果: **`4`**

- [ ] **Step 5: 既存テストが緑のままであることを確認する**

```bash
APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit tests/Feature/AttachmentDeliveryTest.php tests/Feature/Security/AttachmentAuthorizationTest.php
```

期待する結果: **`OK (12 tests, ...)`**

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/RealEstate/ProjectController.php app/Http/Controllers/Housing/PropertyController.php app/Http/Controllers/Housing/CustomOrderController.php
git commit -m "refactor(attachment): 図面・建売・注文住宅の配信を AttachmentDelivery に統一"
```

---

## Task 3: Blade 5 本に ⬇ ダウンロードボタンを追加

**Files:**
- Modify: `resources/views/components/attachment-section.blade.php:97,113-119`
- Modify: `resources/views/tenant/contracts/show.blade.php:252-258`
- Modify: `resources/views/realestate/projects/lots.blade.php:228-231`
- Modify: `resources/views/housing/properties/show.blade.php:276-282`
- Modify: `resources/views/housing/custom-orders/show.blade.php:284-290`

**全 5 本で共通して使う ⬇ アイコン**（既存のアップロードアイコンと対になる下向き矢印。`attachment-section.blade.php:52-56` の上向きと同じ意匠）:

```html
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
```

> **Tailwind について（F8/F9）:** ⬇ に使うクラス（`flex` `items-center` `justify-center` `gap-2` `text-gray-400` `hover:text-emerald-600` `transition-colors` `w-4` `h-4`）は**すべてコンパイル済みであることを実測済み**。inline style へ書き換える必要は**ない**。設計書 §4.4 は `hover:text-emerald-600` が無い可能性を疑ったが、実際には存在する（既に 3 本以上の Blade が使用しており JIT がコンパイルしている）。
> **ただし `lots.blade.php` / housing の 2 本は元から inline style 主体のファイル**なので、そちらは**周囲に合わせて inline style で書く**（新しい流儀を持ち込まない）。

- [ ] **Step 1: `components/attachment-section.blade.php`（共通・7 画面が一括で直る）**

まずヘッダ列の幅を広げる（`:97`）:

```html
                        <th class="px-3 py-2 border-b border-gray-200" style="width:90px;"></th>
```

次に最終列のセル（`:113-119`）を以下で置き換える:

```html
                            <td class="px-3 py-2.5 border-b border-gray-100 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a :href="file.file_path + '?download=1'"
                                       class="text-gray-400 hover:text-emerald-600 transition-colors" title="ダウンロード">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    </a>
                                    <button x-show="file.can_delete && !file.confirming"
                                            @click="file.confirming = true"
                                            class="text-gray-400 hover:text-red-500 transition-colors cursor-pointer" title="削除">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    </button>
                                </div>
                            </td>
```

**注意点:**
- ⬇ は `x-show` で囲まない。**全行に常時表示**する（**D6**）
- 削除確認行の `colspan="5"`（`:122`）は**変更しない**。列は増えず、既存の最終列の中に ⬇ を入れるだけ
- `target="_blank"` は付けない。強制DL なのでタブは開かず、付けると空タブが残るブラウザがある

- [ ] **Step 2: `tenant/contracts/show.blade.php`（解約精算書の単独リンク）**

`:252-258` を以下で置き換える:

```html
            @if($settlementFile)
                <div class="flex items-center gap-2">
                    <a href="{{ route('attachments.show', $settlementFile->id) }}" target="_blank"
                       class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        解約精算書を開く
                    </a>
                    <a href="{{ route('attachments.show', ['attachment' => $settlementFile->id, 'download' => 1]) }}"
                       class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors" title="ダウンロード">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        ダウンロード
                    </a>
                </div>
            @endif
```

**注意点:**
- ここだけ ⬇ に「ダウンロード」の**文言を付ける**。隣が文言付きの大きなボタンなので、アイコンのみだと不揃いに見えるため（D6 は「⬇ を全行に出す」ことを決めているだけで、アイコンのみを強制していない）
- URL は文字列連結でなく `route('attachments.show', ['attachment' => ..., 'download' => 1])` で組む（ルート定義が `/attachments/{attachment}` なので第2キー以降はクエリになる）。**Blade 側は `&quot;` を使わない**（Bug #21）

- [ ] **Step 3: `realestate/projects/lots.blade.php`（区画図面カード）**

`:228-231` を以下で置き換える:

```html
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0 12px 10px;">
                        <a :href="drawing.file_path + '?download=1'" title="ダウンロード"
                           style="display: inline-flex; align-items: center; color: #9ca3af; text-decoration: none;">
                            <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </a>
                        <div x-show="showDrawingDel">
                            <button type="button" @click="deleteDrawing(drawing)"
                                    style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff;">削除</button>
                        </div>
                    </div>
```

**注意点:**
- ⬇ は**カード全体を包む `<a>` の外側**に置く（`<a>` の入れ子は不正で、リンクが二重になる）。上の差し替え位置は元から `</a>` の後ろ
- **`x-show="showDrawingDel"` は `<button>` に直接付けず、ラッパー `<div>` に残す**。`<button>` の inline style には `display: inline-block` が入っており、Alpine の `x-show` は表示時に `style.removeProperty('display')` するため**静的 `style` の display が消える**（トラップ #5「`style=` と Alpine の display 制御の衝突」の同型）。`display` を持たないラッパーに `x-show` を置けばこの衝突は起きない（元コードもそうなっている）
- 削除ボタンが非表示のとき、`justify-content: space-between` でも子が 1 つなら ⬇ は左端に留まる

- [ ] **Step 4: `housing/properties/show.blade.php`（建売物件ファイル行）**

`:276-282` を以下で置き換える:

```html
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6;">
                            <div>
                                <a :href="file.file_path" target="_blank" class="text-sm" style="color: #1d4ed8; text-decoration: underline;" x-text="file.file_name"></a>
                                <span class="text-xs text-gray-500" style="margin-left: 12px;" x-text="file.file_size + ' ' + file.uploaded_by + ' ' + file.created_at"></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <a :href="file.file_path + '?download=1'" title="ダウンロード"
                                   style="display: inline-flex; align-items: center; color: #9ca3af; text-decoration: none;">
                                    <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                </a>
                                <button @click="deleteFile(file.id, '{{ $cat->value }}')" style="background: none; border: none; color: #9ca3af; font-size: 12px; cursor: pointer; padding: 2px 6px;" title="削除">✕</button>
                            </div>
                        </div>
```

- [ ] **Step 5: `housing/custom-orders/show.blade.php`（注文住宅ファイル行）**

`:284-290` を Step 4 と**同一の構造**で置き換える（この 2 ファイルは元から同じマークアップ）:

```html
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6;">
                            <div>
                                <a :href="file.file_path" target="_blank" class="text-sm" style="color: #1d4ed8; text-decoration: underline;" x-text="file.file_name"></a>
                                <span class="text-xs text-gray-500" style="margin-left: 12px;" x-text="file.file_size + ' ' + file.uploaded_by + ' ' + file.created_at"></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <a :href="file.file_path + '?download=1'" title="ダウンロード"
                                   style="display: inline-flex; align-items: center; color: #9ca3af; text-decoration: none;">
                                    <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                </a>
                                <button @click="deleteFile(file.id, '{{ $cat->value }}')" style="background: none; border: none; color: #9ca3af; font-size: 12px; cursor: pointer; padding: 2px 6px;" title="削除">✕</button>
                            </div>
                        </div>
```

- [ ] **Step 6: Blade の危険パターンが混入していないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
# Bug #21: コンポーネント属性内の &quot;
grep -n "&quot;" resources/views/components/attachment-section.blade.php resources/views/tenant/contracts/show.blade.php resources/views/realestate/projects/lots.blade.php resources/views/housing/properties/show.blade.php resources/views/housing/custom-orders/show.blade.php
# Bug #23/#26: 今回の変更行に @json を持ち込んでいないこと
git diff -- resources/views/ | grep "^+" | grep "@json"
```

期待する結果: **両方とも出力ゼロ**

- [ ] **Step 7: コミット**

```bash
git add resources/views/components/attachment-section.blade.php resources/views/tenant/contracts/show.blade.php resources/views/realestate/projects/lots.blade.php resources/views/housing/properties/show.blade.php resources/views/housing/custom-orders/show.blade.php
git commit -m "feat(attachment): 添付一覧にダウンロードボタンを追加"
```

---

## Task 4: デプロイ前の最終検証

**Files:** なし（検証のみ）

- [ ] **Step 1: テスト全体を実行する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit
```

期待する結果: **`OK`**（既存テスト＋新規 8 本すべて緑。`Failures: 0` `Errors: 0`）

> 既存テストが元から赤い場合は本件と無関係な可能性があるので、`git stash` して元の赤さを確認してから判断すること。

- [ ] **Step 2: コンパイル済みビューを `php -l` で検証する（Bug #26 の手順）**

`view:cache` は「Blade templates cached successfully」と**成功表示しても壊れた PHP を吐くことがある**。成功表示を信用せず、必ずコンパイル結果を lint する:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view
export APP_KEY=base64:$(openssl rand -base64 32)
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
unset APP_KEY
```

期待する結果: `Blade templates cached successfully.` → **`INVALID:` が 1 件も出ない** → `Compiled views cleared successfully.`

> worktree に `.env` は無いが、`APP_KEY` を環境変数で渡せば artisan は起動する。**`.env` を作らないこと**（実 MySQL への到達経路を worktree に持ち込まないための安全策）。
> `view:cache` が vendor 由来のエラーで落ちる場合は本件の変更とは無関係な既知事象の可能性がある（過去に `laravel/framework` のファイル欠落で発生）。その場合は main repo 側で同じ検証を行う。

- [ ] **Step 3: 変更ファイルの一覧を確認する**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/attachment-inline-view diff --stat 83455ce6..HEAD
```

期待する結果: **11 ファイル**（新規 2 + Controller 4 + Blade 5）＋ 本プラン文書

---

## 完了後の統合手順（実装タスクではない・順序が重要）

1. **main repo で FF-merge**
   ```bash
   cd /Users/masanori/site/manage
   git checkout 13.x && git merge --ff-only worktree-attachment-inline-view
   ```
   > FF できない場合は 13.x が先行している。`git rebase 13.x` を worktree 側で行ってから再試行する。

2. **`composer dump-autoload` を main repo の cwd で実行する（必須）**
   ```bash
   cd /Users/masanori/site/manage
   composer dump-autoload
   ```
   > `app/Support/AttachmentDelivery.php` が**新規 PHP クラス**のため必須。**worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれ、main repo の Apache が worktree を参照する事故になる。**

3. **本番デプロイは必ずユーザーの明示承認を得てから**
   ```bash
   ./deploy.sh
   ```
   > DB スキーマ変更は無いため SQL 実行は不要。`deploy.sh` が rsync → 本番で `config:cache && route:cache && view:cache` を実行する。

4. **本番確認**（Playwright または手動）
   - 不動産 仕入れ案件詳細: 画像・PDF がファイル名クリックで**別タブ表示**される
   - 同画面: xlsx / csv はファイル名クリックで**ダウンロード**される
   - 同画面: ⬇ ボタンは**画像でもダウンロード**になる
   - 分譲地PJ 区画図面カード / 建売物件 / 注文住宅でも同様

5. **origin への push はユーザーの明示指示があった時のみ**（現在 origin/13.x はローカルより 7 コミット遅れ）

---

## リスクと対策

| リスク | 対策 |
|---|---|
| `Request` 引数追加でルートモデルバインディングが壊れる | Laravel はコンテナ経由で `Request` を先に注入するため既存挙動は変わらない。**T4（`?download=1`）が実際にこれを実証する**（`$request->boolean('download')` とルートモデルバインディングが同時に効いていないと緑にならない） |
| DB に既存の不正な `mime_type` が入っている | 許可リストに無い MIME は全て強制DLへフォールバックするため、最悪でも現状（＝全部DL）と同じ挙動にしかならない |
| PDF の inline がオリジン上でレンダリングされる | ブラウザの PDF ビューアはサンドボックス済み。加えて入口の `mimes:` 制限と出口の許可リストで PDF 以外が PDF として配信されることはない |
| Task 2 の 3 本にテストが無い | F3 のとおりテーブルが無く不可能。配信ロジック本体は Task 1 の 8 テストで実証済みで、3 本は同じ関数へ委譲するだけ。Task 4 Step 2 の本番同等レンダリング＋本番確認で担保 |
| 本番の view:cache 由来 500（Bug #21/#23/#26） | Blade 変更は属性内 `@json` も `&quot;` も使わない単純な要素追加のみ。Task 3 Step 6 と Task 4 Step 2 で検査 |
| Tailwind 未コンパイルクラスで ⬇ が無装飾になる | F8 で実測済み（`hover:text-emerald-600` を含め全て存在）。**再確認する場合は必ず main repo で**（F9） |
