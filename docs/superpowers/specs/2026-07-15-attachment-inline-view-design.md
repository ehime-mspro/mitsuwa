# 添付ファイルのブラウザ表示（inline）＋ダウンロード導線併設 設計書

- 日付: 2026-07-15
- ブランチ: `worktree-attachment-inline-view`
- 起点コミット: `387aaaee`
- 直接の関連コミット: `c91dfdca`（2026-06-03「添付ファイルの保存型XSS対策（mimes制限+download配信+nosniff）」）

---

## 1. 背景・目的

不動産管理の仕入れ案件・分譲地PJ 詳細画面で、添付ファイル名をクリックすると必ずダウンロードされ、ブラウザで開けない。住宅事業（建売・注文住宅）など他部署でも同様。

**フロント側は既に全 5 箇所が `target="_blank"` 済み**であり、原因はサーバー側が `Content-Disposition: attachment`（強制ダウンロード）を返していること。ブラウザは新規タブを開いた直後に閉じてダウンロードへ切り替えるため、`target="_blank"` が無効化されている。

この挙動は commit `c91dfdca` で `Storage::disk('public')->response()`（inline）→ `->download()`（強制DL）へ変更したことによる**意図的なもの**。ただし同じコミットで `.svg` / `.html` のアップロードを `mimes:` 制限で禁止済みであり、**入口を塞いだ上での出口の強制DLは過剰防衛**になっている。実際、分譲地PJの区画図面（`showDrawing`）だけは同コミット内でも「画像は inline のまま」という判断で残されている。

**目的**: `c91dfdca` の保存型XSS対策の意図を保ったまま、画像・PDF を別タブでブラウザ表示できるようにする。あわせて明示的なダウンロード導線を用意する。

---

## 2. 決定事項（確定）

| # | 決定 | 理由 |
|---|---|---|
| D1 | inline 対象は **画像（jpeg / png / gif / webp）と PDF のみ** | Word/Excel はブラウザが表示できず、inline 指定しても結局ダウンロードになるため指定する意味がない |
| D2 | **heic / heif は inline 対象外**（強制DL・現状維持） | Safari 以外のブラウザで表示できない |
| D3 | **txt / csv は inline 対象外**（強制DL・現状維持） | CSV は Excel で開くのが実務。文字化けリスクも回避 |
| D4 | `Content-Type` は **DB の `mime_type` をそのまま使わず、許可リストの正規化値**を使う | DB 値への信用を断ち、`image/svg+xml` 等の混入経路を遮断する |
| D5 | ダウンロードは **`?download=1` クエリ**で表現し、新規ルートは追加しない | 既存 4 ルートを再利用でき差分が最小。サーバー側で決まるので挙動が確定的 |
| D6 | **ファイル名リンク = そのファイルにとって自然な挙動**（画像/PDF→表示、Word/Excel→DL）、**⬇ ボタン = 常にDL**。⬇ は全行に表示する | 行ごとにボタンが出没するより統一されている方が分かりやすい（ユーザー承認済み） |
| D7 | admin の CSV テンプレート配信 3 本は**対象外** | 記入して再アップロードするものなので強制DLが正しい |

### 2.1 用語（将来の「修正」防止）

- **inline 配信** = `Content-Disposition: inline`。ブラウザが表示できる形式なら表示し、できなければブラウザ判断でDLになる。「必ず表示される」という意味ではない。
- **強制DL** = `Content-Disposition: attachment`。形式によらず常にダウンロード。

---

## 3. スコープ

### 3.1 変更対象ファイル

**新規（2本）**

| ファイル | 役割 |
|---|---|
| `app/Support/AttachmentDelivery.php` | 許可リストを持つ配信ヘルパー（唯一の判断箇所） |
| `tests/Feature/AttachmentDeliveryTest.php` | ルート経由の Feature テスト |

**改修: Controller（4本）**

| ファイル | メソッド | 現状 |
|---|---|---|
| `app/Http/Controllers/AttachmentController.php` | `show` | 常に強制DL |
| `app/Http/Controllers/RealEstate/ProjectController.php` | `showDrawing` | 画像のみ inline |
| `app/Http/Controllers/Housing/PropertyController.php` | `showFile` | 常に強制DL |
| `app/Http/Controllers/Housing/CustomOrderController.php` | `showFile` | 常に強制DL |

**改修: Blade（5本）** — ⬇ ボタン追加のみ

| ファイル | 箇所 |
|---|---|
| `resources/views/components/attachment-section.blade.php` | 共通添付セクション（不動産2 + テナント3 + マンション + DAD が一括で直る） |
| `resources/views/tenant/contracts/show.blade.php` | 精算書の単独リンク |
| `resources/views/realestate/projects/lots.blade.php` | 区画図面カード |
| `resources/views/housing/properties/show.blade.php` | 建売物件ファイル行 |
| `resources/views/housing/custom-orders/show.blade.php` | 注文住宅ファイル行 |

### 3.2 変更しないファイル（意図的）

- `routes/web.php` — `?download=1` はクエリパラメータなのでルート定義の変更は不要
- 各 `store` メソッドの `mimes:` 制限 — 入口の防御は `c91dfdca` のまま維持する
- `canAccessDepartmentOf()` / 部署ベース認可（IDOR 対策）— 現状維持
- `X-Content-Type-Options: nosniff` — 全配信で維持

### 3.3 スコープ外

- admin の CSV テンプレート配信 3 本（`CustomerImportController` / `TenantImportController` / `MansionImportController`）
- 添付テーブル・カラムのスキーマ変更
- `tests/Feature/Security/AttachmentAuthorizationTest.php` の古い注記（「attachments テーブルはテストDBに存在しない」）の修正 — 本件では触らない（別件・4.5 参照）

---

## 4. 実装設計

### 4.1 `app/Support/AttachmentDelivery.php`（新規）

`app/Support/` 配下の既存クラス（`Settings.php` / `ZealFiscalYear.php`）に倣い、素の静的メソッドクラスとする。

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
     * ブラウザの別タブで表示できるファイルか（UI のアイコン出し分けにも使う）。
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
            'Content-Type'           => self::INLINE_MIME_TYPES[strtolower($mimeType)],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

**検証済みの前提**（`vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php:327`）:
- `response()` の第4引数 `$disposition` は既定で `'inline'`
- `$headers['Content-Type'] ??= $this->mimeType($path);` — `??=` なので**明示指定した Content-Type が優先される**（D4 が実際に効くことの根拠）
- `makeDisposition()` が RFC 5987 の `filename*` を付けるため日本語ファイル名も維持される

### 4.2 呼び出し側（Controller 4本）

いずれも既存の認可チェック・存在チェックはそのまま残し、返却部分のみ差し替える。`Request` を第1引数に追加してもルートモデルバインディングは従来どおり機能する。

**`AttachmentController::show`**

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

**`RealEstate\ProjectController::showDrawing`** — 既存の `if ($drawing->isImage())` 分岐を丸ごと `AttachmentDelivery::make()` に置換する。これにより `isImage()` の `str_starts_with($mime, 'image/')` 判定で `image/svg+xml` が inline 配信され得る**潜在的な穴が閉じる**（現状はアップロード側の `mimes:` で防いでいるだけ）。

**`Housing\PropertyController::showFile`** / **`Housing\CustomOrderController::showFile`** — 同様に置換。

各コントローラで `use App\Support\AttachmentDelivery;` を追加。`Request` が未 import の場合は追加する（実装時に確認）。

### 4.3 Blade（5本）— ⬇ ボタン追加

**共通添付セクション** `components/attachment-section.blade.php`

最終列（現在 `style="width:60px;"`・削除ボタンのみ）を約 90px に広げ、⬇ を削除ボタンの左に置く。

```html
<td class="px-3 py-2.5 border-b border-gray-100 text-center">
    <div class="flex items-center justify-center gap-2">
        <a :href="file.file_path + '?download=1'"
           class="text-gray-400 transition-colors" title="ダウンロード">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
        </a>
        {{-- 既存の削除ボタン --}}
    </div>
</td>
```

**他 4 本**も同じ SVG・同じ `?download=1` 付与で統一する。図面カード（`lots.blade.php`）はカード全体が `<a>` になっているため、`<a>` の外側にある削除ボタンの領域に ⬇ を置く（削除ボタンは `x-show="showDrawingDel"` で出没するので、⬇ は常時表示の独立要素とする）。

### 4.4 Tailwind の制約（Bug #19 / 罠 #7）

新規クラスは持ち込まない。使用予定クラス（`flex` / `items-center` / `justify-center` / `gap-2` / `text-gray-400` / `transition-colors` / `w-4` / `h-4`）は同ファイル内の既存マークアップで使用実績があるため通る見込みだが、**実装時に必ず実測する**:

```bash
grep -oE "\.(hover\\\\:)?text-emerald-600" public/build/assets/app-*.css
```

ホバー色（`hover:text-emerald-600` 等）はコンパイル済み CSS に無い可能性が高い。無ければ Bug #19 のパターンどおり `onmouseover` / `onmouseout` のインラインハンドラか inline style に置き換える。任意値クラス（`w-[18px]` 等）は使わない。

### 4.5 テスト実行環境に関する発見（実装時の前提）

`tests/Feature/Security/AttachmentAuthorizationTest.php` の注記は「attachments テーブルは Laravel マイグレーション管理外でテストDBに存在しない」と述べているが、**`attachments` については誤り**である:

- `database/migrations/0001_01_01_000015_create_attachments_table.php` が存在する（初期コミット `2046289d` 由来）
- `2026_03_30_100001_add_softdelete_to_attachments_table.php` も存在する
- `AttachmentController::show()` は **親モデルを一切ロードしない**（`attachable_type` の文字列しか読まない）

したがって `re_*` / `hs_*` / `ms_*` テーブルが無くても、`attachable_type = Contract::class`（tenant 部署）の Attachment 行 + `Storage::fake('public')` だけで**ルート経由の Feature テストが書ける**。注記が指す `re_*` / `hs_*` / `ms_*` が migration 管理外であることは事実なので、注記自体の修正は本件のスコープ外とする（3.3）。

---

## 5. テスト計画 — `tests/Feature/AttachmentDeliveryTest.php`（新規）

`Storage::fake('public')` + `RefreshDatabase`。`attachable_type` は `Contract::class` を使い、tenant 部署所属の staff で認証する（`AttachmentAuthorizationTest` の `actingAsStaffInDepartment()` パターンを踏襲）。

| # | ケース | 期待 |
|---|---|---|
| T1 | `image/jpeg` の添付を GET | `Content-Disposition` が `inline` で始まる／`Content-Type: image/jpeg`／`X-Content-Type-Options: nosniff` |
| T2 | `application/pdf` の添付を GET | `inline` で始まる |
| T3 | xlsx（`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`）を GET | `attachment` で始まる |
| T4 | `image/jpeg` の添付に `?download=1` | `attachment` で始まる |
| T5 | **`image/svg+xml` を DB に直接入れて GET** | `attachment` で始まる（＝ inline 配信されない。D4 の許可リスト検証） |
| T6 | `text/csv` を GET | `attachment` で始まる（D3） |
| T7 | 日本語ファイル名の画像を GET | `inline` で始まる（ファイル名の表現は実測して確定） |

**アサーション値は必ず実測してから確定する。** ヘッダ文字列（特に `makeDisposition()` が生成する `filename` / `filename*` の並びとクォート）を推測で書かない。まず `dump($response->headers->get('Content-Disposition'))` で実際の値を確認し、完全一致ではなく `assertStringStartsWith('inline;', ...)` を使う。

**T5 で `Content-Type` をアサートしてはいけない。** 許可リストに無い MIME は `download()` にフォールバックするが、`download()` は DB の値ではなく**ファイル実体**から Content-Type を判定するため、svg 実体を置けば `image/svg+xml` がヘッダに出ることはあり得る。防御が成立している根拠は `Content-Disposition: attachment` + `nosniff` であって Content-Type の書き換えではない。検証すべきは**配信方法（disposition）**であり、Content-Type ではない。

**実行方法**（worktree・`composer install` 済み・`.env` は作らない）:

```bash
cd .claude/worktrees/attachment-inline-view
APP_KEY=base64:$(openssl rand -base64 32) vendor/bin/phpunit tests/Feature/AttachmentDeliveryTest.php
```

既存テストの回帰確認として `tests/Feature/Security/AttachmentAuthorizationTest.php` も同時に緑であることを確認する。

---

## 6. タスク分割（壊れない中間コミット）

| # | 内容 | コミット種別 |
|---|---|---|
| T-1 | `AttachmentDelivery` 新規作成 + `AttachmentDeliveryTest`（`AttachmentController::show` の改修まで含む。テストが通る最小単位） | `feat(attachment)` |
| T-2 | 残り Controller 3本（図面 / 建売 / 注文住宅）を `AttachmentDelivery` に統一 | `refactor(attachment)` |
| T-3 | Blade 5本に ⬇ ダウンロードボタン追加 | `feat(attachment)` |
| T-4 | 設計書・実装プラン | `docs(attachment)` |

T-1 と T-2 の間でも既存挙動は壊れない（T-1 時点で他 3 本は従来のまま動作する）。

---

## 7. デプロイ・検証手順

1. worktree で実装 → `/commit`
2. main repo で `git checkout 13.x && git merge --ff-only worktree-attachment-inline-view`
3. **`composer dump-autoload` を main repo の cwd で実行**（`app/Support/AttachmentDelivery.php` が新規 PHP クラスのため必須。worktree から実行すると autoloader に worktree パスが焼き込まれる事故になる）
4. デプロイ前検証: `php artisan view:cache` → コンパイル済みビューを `php -l`（Bug #26 の手順）→ `php artisan view:clear`
5. **`./deploy.sh`（本番反映はユーザーの明示承認を得てから）**
6. 本番確認: 不動産 仕入れ案件詳細で画像・PDF が別タブ表示されること、xlsx がDLされること、⬇ でDLされること

DB スキーマ変更は無いため SQL 実行は不要。

---

## 8. リスク・留意点

| リスク | 対策 |
|---|---|
| PDF の inline 配信は同一オリジンでのレンダリングになる | ブラウザの PDF ビューアはサンドボックス済み。加えて入口の `mimes:` 制限と出口の許可リストで PDF 以外が PDF として配信されることはない |
| DB に既存の不正な `mime_type` が入っている可能性 | 許可リストに無い MIME は全て強制DLにフォールバックするため、最悪でも現状（＝全部DL）と同じ挙動にしかならない |
| Tailwind の未コンパイルクラスで ⬇ が無反応・無装飾になる | 4.4 のとおり実装時に実測。任意値クラスは使わない |
| `Request` 引数追加でルートモデルバインディングが壊る懸念 | Laravel はコンテナ経由で `Request` を先に注入するため既存の挙動は変わらない。Feature テストで実証する |
| 本番の view:cache 由来の 500（Bug #21 / #23 / #26） | Blade 変更は属性内 `@json` も `&quot;` も使わない単純な要素追加のみ。7-4 の `php -l` 検証を実施 |
| 図面カードの `<a>` 内に ⬇ を入れ子にするとリンクが二重になる | ⬇ は `<a>` の外側（削除ボタンと同じ領域）に置く |
