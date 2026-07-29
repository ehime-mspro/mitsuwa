# 仕入れ案件一覧から分譲地を新規登録する導線 — 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 仕入れ案件一覧のヘッダー「新規登録」をドロップダウン化し、「仕入れ案件を登録」「分譲地を登録」の 2 経路にする。分譲地側から入ったときだけパンくずとキャンセルの戻り先を仕入れ案件一覧にする。

**Architecture:** 新画面・新ルート・DB 変更なし。既存の 2 つの create 画面へ振り分けるだけ。戻り先の分岐は `ProjectController::create()` が `?from=procurements` をホワイトリスト 1 語で判定し、`$backUrl` / `$backLabel` の 2 変数をビューへ渡す（Blade の `@php` を使わない＝`@section('breadcrumb')` との実行順依存を作らない）。

**Tech Stack:** Laravel 12 / Blade / Alpine.js 3（`x-data` + `x-show` + `@click.outside`）/ Tailwind v4 / PHPUnit（SQLite in-memory）

**設計書:** `docs/superpowers/specs/2026-07-29-procurement-list-create-dropdown-design.md`

**作業ディレクトリ:** `/Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown`（branch `feature/procurement-create-dropdown`）

---

## ファイル構成

| ファイル | 区分 | 責務 |
|---|---|---|
| `resources/views/realestate/procurements/index.blade.php` | 変更（19-29 行） | ヘッダーのボタンをドロップダウン化 |
| `app/Http/Controllers/RealEstate/ProjectController.php` | 変更（70-88 行の `create()`） | `?from` を判定し `$backUrl` / `$backLabel` を組む |
| `resources/views/realestate/projects/create.blade.php` | 変更（5-12 行・30 行） | パンくずとキャンセル URL を変数参照にする |
| `tests/Concerns/CreatesRealEstateSchema.php` | 変更（追記） | テスト DB に `zoning_types` を追加（Task 2 で理由を後述） |
| `tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php` | 新規 | 回帰テスト 6 本 |

**新規 PHP クラスは追加しないので `composer dump-autoload` は不要。**

---

## 実測で確認済みの前提（推測ではなく実行して確かめた事実）

このプランを書く前に worktree 上で実測した。実装時に前提が変わっていたら止まって報告すること。

| # | 事実 | 確認方法 |
|---|---|---|
| 1 | `zoning_types` は **migration にもテスト用 trait にも無い**。`database/sql/create_zoning_types.sql` の raw SQL のみ | `grep -rln "zoning_types" database/migrations/ tests/` が空 |
| 2 | `/realestate/projects/create` を叩く既存テストは **1 本も無い** | `grep -rln "projects/create" tests/` が空 |
| 3 | `ProjectController::create()` は `ZoningType::orderBy('sort_order')->get()` を呼ぶ | `ProjectController.php:72` |
| 4 | サイドバーは `/realestate/procurements` を 3 箇所、`/realestate/projects` を 2 箇所 href に出す | `sidebar.blade.php:100,101,251,374,375` |
| 5 | `x-form-actions` は `$cancelUrl` を `<a href="{{ $cancelUrl }}">…キャンセル</a>` にそのまま流すだけ | `components/form-actions.blade.php:48-57` |
| 6 | `projects/create.blade.php` を描画するのは `ProjectController::create()` だけ | `grep -rn "view('realestate.projects.create'" app/` が 1 件 |
| 7 | `_form.blade.php` / `supplier-picker` / `_cost_section_form` / `sidebar` に Blade 内 DB アクセスは無い | 各ファイルを `::get(` `::all(` 等で grep して 0 件 |
| 8 | `departments` テーブルは migration が作るが **行は seed しない**。`DepartmentSeeder` が 7 件投入し `realestate` を含む | `0001_01_01_000001_create_departments_table.php` / `DepartmentSeeder.php:19` |
| 9 | `users.must_change_password` の既定は **true**、`users.status` の既定は `active` | `0001_01_01_000000_create_users_table.php:21-22` |
| 10 | `UserRole::isManagerOrAbove()` は Executive または Manager | `app/Enums/UserRole.php:25-28` |
| 11 | `/realestate/procurements`（index）は `department.access:realestate` のみ。`role:executive,manager` は create/store 側のグループ | `routes/web.php:711-719` |
| 12 | `AlpineXShowDisplayConflictTest` は `x-show` と **`:style`** を同一タグに持つ要素だけを走査する | `tests/Feature/AlpineXShowDisplayConflictTest.php:78-84` |
| 13 | `x-cloak` の `display:none` は `resources/css/app.css:19` に定義済み | 同ファイル |
| 14 | worktree に `vendor/` は無い（`.gitignore:21`）。`.env` も無い（`.gitignore:3`） | `ls -d vendor` が No such file |

---

## 踏んではいけない罠（設計書 §6 / docs/RULES.md 由来）

実装中にこの 5 つを必ず意識すること。**どれも「テストも `view:cache` も `php -l` も通るのに壊れる」型。**

1. **Bug #21** — `<x-form-actions :cancel-url="…">` の属性式に `route(&quot;…&quot;)` を書くと、本番（PHP 8.3 + `view:cache` で全 Blade を precompile）でだけ `syntax error, unexpected token "&"` の 500 になる。ローカルの lazy compile では再現しない。→ **属性には PHP 変数 1 本だけを渡す**（`:cancel-url="$backUrl"`）。三項演算子も `route()` 呼び出しも属性内に書かない。
2. **Bug #32** — `x-show` は `display` を自分のものとして扱うので、同じ要素の `:style` に `display` を書いても Alpine に奪われる。→ 今回は `:style` を一切使わず**静的 Tailwind クラスだけ**にする。
3. **Bug #28** — HTML にリンクが出ることと Alpine が実際に動くことは別問題。`@push('scripts')` が捨てられていて JS が一度も実行されていなかった前例がある。→ **デプロイ後に本番ブラウザで実際にドロップダウンを開く**（Task 6）。
4. **Bug #30** — Blade のディレクティブコンパイラは JS の `//` コメントも解釈する。コメント中に `@json` `@if` 等を書くなら `@@json` とエスケープする。→ 今回追加するコメントにディレクティブ名を書かない。
5. **false-pass するアサーション** — `assertSee('分譲地一覧')` はパンくず以外にも一致する。さらに実測事実 #4 のとおり**サイドバーが同じ URL を何度も出す**ので、素の `assertSee(route(…))` や単純な出現回数カウントも当てにならない。→ **パンくずは完全な `<a …>ラベル</a>` 文字列で、キャンセルは「`キャンセル` というラベルを持つ `<a>` の href」を正規表現で**見る。

---

## Task 0: worktree にテスト実行環境を用意する

**Files:**
- Create: `.env`（gitignore 済み・コミットしない）
- Create: `vendor/`（`composer install` の生成物・gitignore 済み）

実測事実 #14 のとおり worktree には `vendor` も `.env` も無い。PHPUnit の bootstrap は `vendor/autoload.php`（`phpunit.xml:4`）なので、これが無いとテストが 1 本も走らない。

⚠ **main repo の `vendor` を symlink してはいけない。** composer が dump する `vendor/composer/autoload_psr4.php` の `$baseDir` は**dump 時のパスが literal で焼き込まれる**ため、symlink すると PSR-4 のルートが main repo の `app/` を指し、**worktree の変更ではなく main repo のコードをテストしてしまう**。必ず worktree 内で `composer install` する。

- [ ] **Step 1: worktree に依存を入れる（dev 込み）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && composer install
```

期待: 完了後に `vendor/bin/phpunit` が存在する。

- [ ] **Step 2: phpunit の存在を確認**

```bash
ls -l /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown/vendor/bin/phpunit
```

期待: ファイルが 1 件表示される。`No such file` なら Step 1 が `--no-dev` で走っている。`composer install` を引数無しでやり直す。

- [ ] **Step 3: テスト用のダミー `.env` を作る**

`phpunit.xml` は `APP_ENV` / DB 等を上書きするが `APP_KEY` は与えないので、`.env` に鍵だけ用意する。**DB 認証情報はわざと書かない**（worktree から実 MySQL に到達できない＝実 DB を壊しようがない状態を保つ）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && printf 'APP_NAME=manage\nAPP_ENV=testing\nAPP_DEBUG=true\nAPP_URL=http://localhost\nAPP_KEY=base64:%s\n' "$(php -r 'echo base64_encode(random_bytes(32));')" > .env
```

- [ ] **Step 4: 既存テストが 1 本通ることを確認（環境の健全性チェック）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
```

期待: `OK (nn tests, nnn assertions)`。ここが赤なら環境の問題なので、実装に進まず原因を潰す。

- [ ] **Step 5: `.env` と `vendor` が git に入らないことを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && git status --porcelain
```

期待: 出力が空（`.env` も `vendor/` も `.gitignore` 済み）。**何か出たらコミットせず報告する。**

コミットは無し（生成物のみのため）。

---

## Task 1: 一覧ヘッダーを「新規登録 ▾」ドロップダウンにする

**Files:**
- Create: `tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php`
- Modify: `resources/views/realestate/procurements/index.blade.php:19-29`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php` を新規作成する。

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件一覧から分譲地を新規登録する導線の検証。
 *
 * - 一覧ヘッダーの「新規登録」ドロップダウンに 2 経路が出ること
 * - 分譲地 新規登録画面が ?from=procurements のときだけ仕入れ案件一覧へ戻ること
 *
 * ⚠ アサーションの作り方に注意（false-pass しやすい）:
 *   サイドバーが /realestate/procurements と /realestate/projects の href を
 *   それぞれ複数回描画するため、素の assertSee(route(...)) や出現回数カウントは
 *   当てにならない。パンくずは完全な <a ...>ラベル</a> 文字列で、
 *   キャンセルは「キャンセル というラベルを持つ <a> の href」を正規表現で見る。
 */
class ProcurementListCreateDropdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        // 部署マスタは migration では投入されないので seeder で入れる（staff の所属付けに要る）
        $this->seed(DepartmentSeeder::class);
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 一般担当。department.access:realestate を通すため realestate に所属させる */
    private function staff(): User
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);
        $user->departments()->attach(Department::where('code', 'realestate')->value('id'));

        return $user;
    }

    public function test_list_shows_both_create_links(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // URL そのもので見る。?from=procurements の有無まで含めて一意に判定できる
        $response->assertSee(route('realestate.procurements.create'), false);
        $response->assertSee(route('realestate.projects.create', ['from' => 'procurements']), false);
    }

    public function test_staff_sees_no_create_links(): void
    {
        $response = $this->actingAs($this->staff())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertDontSee(route('realestate.procurements.create'), false);
        $response->assertDontSee(route('realestate.projects.create', ['from' => 'procurements']), false);
    }
}
```

- [ ] **Step 2: テストを走らせて失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php
```

期待: `test_list_shows_both_create_links` が **FAIL**（分譲地の create リンクがまだ無いため `Failed asserting that '…' contains "http://localhost/realestate/projects/create?from=procurements"`）。
`test_staff_sees_no_create_links` は現状でも PASS してよい（staff にはもともとボタンが出ないため）。**この 1 本が最初から緑なのは正しい**（ガードの回帰テストとして置く）。

- [ ] **Step 3: ヘッダーをドロップダウンに差し替える**

`resources/views/realestate/procurements/index.blade.php` の 19-29 行を、以下で置き換える。

置き換え前（現状）:

```blade
    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">仕入れ案件一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('realestate.procurements.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規登録
            </a>
        @endif
    </div>
```

置き換え後:

```blade
    {{-- ページヘッダー（+ 新規登録ドロップダウン） --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">仕入れ案件一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            {{-- 一覧に分譲地の行も並ぶため、登録先を種別ごとに選ばせる。
                 /housing/contracts（建売 / 注文住宅）と同じパターン。
                 display は Alpine の x-show が持つので :style を使わず静的クラスだけで組む --}}
            <div x-data="{ open: false }" class="relative w-full sm:w-auto">
                <button type="button" @click="open = !open"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    新規登録
                    <svg class="w-3 h-3 ml-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak
                     class="absolute right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg min-w-[200px] z-10 overflow-hidden">
                    <a href="{{ route('realestate.procurements.create') }}"
                       class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-emerald-600 border-b border-gray-100 transition-colors">
                        仕入れ案件を登録
                    </a>
                    <a href="{{ route('realestate.projects.create', ['from' => 'procurements']) }}"
                       class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-emerald-600 transition-colors">
                        分譲地を登録
                    </a>
                </div>
            </div>
        @endif
    </div>
```

注意点:

- `:style` は使わない（罠 2 / Bug #32）。`display` は `x-show` に任せ、レイアウトは Tailwind クラスで作る
- Tailwind クラスは普通に書いてよい。`./deploy.sh` が `npm run build` するので本番は必ず最新（Bug #19 は解決済み）
- `x-cloak` は `resources/css/app.css:19` に定義済みなので初期表示のちらつきは出ない
- `@click` は Alpine の on ディレクティブ。Blade のディレクティブではないので `@@` エスケープは不要（`@click` という Blade ディレクティブは存在しない）

- [ ] **Step 4: テストを走らせて通ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php
```

期待: `OK (2 tests, …)`

- [ ] **Step 5: 一覧の既存テストが壊れていないことを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ tests/Feature/AlpineXShowDisplayConflictTest.php
```

期待: 全部 `OK`。`AlpineXShowDisplayConflictTest` は `x-show` + `:style` の組を走査するので、今回の静的クラス実装なら素通りする（実測事実 #12）。

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && git add resources/views/realestate/procurements/index.blade.php tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php && git commit -m "feat(realestate): 仕入れ案件一覧の新規登録をドロップダウン化し分譲地登録を追加"
```

---

## Task 2: テストから分譲地 新規登録画面を開けるようにする

**Files:**
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`
- Modify: `tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php`

**なぜ独立したタスクなのか。** 実測事実 #1〜#3 のとおり、`zoning_types` は本番では raw SQL DDL 管理でテスト DB に存在せず、`/realestate/projects/create` を叩く既存テストは 1 本も無い。つまり **Task 3 のテストは書いた瞬間に「no such table: zoning_types」で落ちる**。それを「自分の実装のせい」と誤読しないよう、先に切り分けて潰しておく。

- [ ] **Step 1: 失敗するテストを書く**

`ProcurementListCreateDropdownTest.php` の `test_staff_sees_no_create_links()` の直後に追記する。

```php
    /**
     * 分譲地 新規登録画面がそもそも開けること。
     *
     * ⚠ zoning_types は本番では raw SQL DDL 管理でマイグレーションに無く、
     *    CreatesRealEstateSchema にも入っていなかった（この画面を叩く既存テストが
     *    1 本も無かったため露見していなかった）。ProjectController::create() は
     *    ZoningType を引くので、trait 側で表を作らないとここで落ちる。
     */
    public function test_project_create_page_opens(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects/create');

        $response->assertOk();
        $response->assertSee('分譲地 新規登録');
    }
```

- [ ] **Step 2: テストを走らせて失敗の内容を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit --filter test_project_create_page_opens tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php
```

期待: **FAIL**。メッセージに `no such table: zoning_types`（`Illuminate\Database\QueryException`）が含まれること。
⚠ **別の失敗理由（403 や 500 の別例外）が出たらここで止まって報告する。** 前提が変わっている。

- [ ] **Step 3: trait に `zoning_types` を追加する**

`tests/Concerns/CreatesRealEstateSchema.php` の `createRealEstateSchema()` 内、`Schema::create('re_cost_items', …)` ブロック（62-68 行）の直後に挿入する。

```php
        // 用途地域マスタ。database/sql/create_zoning_types.sql に準拠（本番も raw SQL 管理）。
        // 仕入れ案件・分譲地の登録/編集フォームが <option> をここから作る。
        Schema::create('zoning_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
```

あわせて trait 冒頭の docblock（8-17 行）の 1 行目を、対象が `re_*` と `buyers` だけでない旨に更新する。

置き換え前:

```php
 * re_* テーブルと buyers は本番では raw SQL DDL で管理され、Laravel マイグレーションに無い。
```

置き換え後:

```php
 * re_* テーブル・buyers・zoning_types は本番では raw SQL DDL で管理され、Laravel マイグレーションに無い。
```

- [ ] **Step 4: テスト側に用途地域を 1 件入れる**

空テーブルだと `@foreach($zoningTypes …)` のループが 0 周で素通りし、`<option>` 生成の経路を一度も踏まない（Bug #22 / #25 / #27 と同じ「空データが本番の欠陥を隠す」型）。`setUp()` の末尾に 1 件だけ投入する。

`ProcurementListCreateDropdownTest.php` の `setUp()` を以下に差し替える。

```php
    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        // 部署マスタは migration では投入されないので seeder で入れる（staff の所属付けに要る）
        $this->seed(DepartmentSeeder::class);
        // 用途地域の <option> 生成経路を空振りさせないため 1 件だけ入れる
        ZoningType::create(['name' => '第一種住居地域', 'sort_order' => 5]);
    }
```

ファイル冒頭の `use` に追加する（`use App\Models\User;` の直後）。

```php
use App\Models\ZoningType;
```

`test_project_create_page_opens()` にも用途地域が描画されることのアサーションを足す。

```php
        $response->assertSee('分譲地 新規登録');
        $response->assertSee('<option value="第一種住居地域"', false);
```

- [ ] **Step 5: テストを走らせて通ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php
```

期待: `OK (3 tests, …)`

- [ ] **Step 6: trait を共有する既存 3 ファイルの回帰を確認**

`CreatesRealEstateSchema` は 3 本のテストが使っている。表の追加は加算的だが、実際に走らせて確かめる。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/
```

期待: 全部 `OK`。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && git add tests/Concerns/CreatesRealEstateSchema.php tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php && git commit -m "test(realestate): テストスキーマに zoning_types を追加し分譲地登録画面を検証可能にする"
```

---

## Task 3: 戻り先をコントローラで組み、分譲地登録画面へ反映する

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php:70-88`
- Modify: `resources/views/realestate/projects/create.blade.php:5-12, 30`
- Modify: `tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`ProcurementListCreateDropdownTest.php` の `test_project_create_page_opens()` の直後に、3 本まとめて追記する。

```php
    /**
     * ?from=procurements のとき、パンくずとキャンセルの両方が仕入れ案件一覧を指すこと。
     *
     * ⚠ 単に assertSee(route('realestate.procurements.index')) では駄目。
     *    サイドバーが同じ URL を 3 箇所描画しているので、実装が何もされていなくても緑になる。
     *    パンくずは完全な <a ...>ラベル</a>、キャンセルは「キャンセル というラベルを持つ
     *    <a> の href」を正規表現で見る。
     */
    public function test_project_create_from_procurements_points_back_to_procurement_list(): void
    {
        $response = $this->actingAs($this->executive())
            ->get('/realestate/projects/create?from=procurements');

        $response->assertOk();

        // コントローラの判定結果そのものを固定する
        $response->assertViewHas('backUrl', route('realestate.procurements.index'));
        $response->assertViewHas('backLabel', '仕入れ案件一覧');

        // パンくずの中間リンク（URL とラベルを同時に固定）
        $response->assertSee(
            '<a href="' . route('realestate.procurements.index') . '" class="hover:text-emerald-600 transition-colors">仕入れ案件一覧</a>',
            false
        );

        // キャンセルボタン（x-form-actions が描画する「キャンセル」ラベルの <a>）
        $this->assertMatchesRegularExpression(
            $this->cancelLinkPattern(route('realestate.procurements.index')),
            $response->getContent(),
            'キャンセルボタンが仕入れ案件一覧を指していること'
        );
        $this->assertDoesNotMatchRegularExpression(
            $this->cancelLinkPattern(route('realestate.projects.index')),
            $response->getContent(),
            'キャンセルボタンが分譲地一覧を指したまま残っていないこと'
        );
    }

    /** パラメータ無しなら従来どおり分譲地一覧（既存挙動の回帰） */
    public function test_project_create_without_from_points_back_to_project_list(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects/create');

        $response->assertOk();
        $response->assertViewHas('backUrl', route('realestate.projects.index'));
        $response->assertViewHas('backLabel', '分譲地一覧');
        $response->assertSee(
            '<a href="' . route('realestate.projects.index') . '" class="hover:text-emerald-600 transition-colors">分譲地一覧</a>',
            false
        );
        $this->assertMatchesRegularExpression(
            $this->cancelLinkPattern(route('realestate.projects.index')),
            $response->getContent()
        );
    }

    /** 未知の from はホワイトリストに落ちて分譲地一覧に戻る */
    public function test_unknown_from_value_falls_back_to_project_list(): void
    {
        $response = $this->actingAs($this->executive())
            ->get('/realestate/projects/create?from=housing');

        $response->assertOk();
        $response->assertViewHas('backUrl', route('realestate.projects.index'));
        $response->assertViewHas('backLabel', '分譲地一覧');
        $this->assertMatchesRegularExpression(
            $this->cancelLinkPattern(route('realestate.projects.index')),
            $response->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            $this->cancelLinkPattern(route('realestate.procurements.index')),
            $response->getContent()
        );
    }

    /**
     * 「キャンセル」というラベルを持つ <a> の href が $url であることを見る正規表現。
     *
     * x-form-actions は href の直後に改行 + style/onmouseover 属性を並べるので、
     * 属性部分は [^>]* で読み飛ばす（属性値に > は含まれないことを実測済み）。
     * 意匠（inline style）に依存しないので、ボタンを再スタイルしても壊れない。
     */
    private function cancelLinkPattern(string $url): string
    {
        return '/<a href="' . preg_quote($url, '/') . '"[^>]*>\s*キャンセル\s*<\/a>/u';
    }
```

- [ ] **Step 2: テストを走らせて失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php
```

期待: 追加した 3 本が **FAIL**。`test_project_create_from_procurements_points_back_to_procurement_list` は `assertViewHas('backUrl', …)` で「view に backUrl が無い」旨で落ちる。

- [ ] **Step 3: `ProjectController::create()` に戻り先の計算を足す**

`app/Http/Controllers/RealEstate/ProjectController.php` の `create()`（70-88 行）を以下で置き換える。

置き換え前:

```php
    public function create()
    {
        $zoningTypes = ZoningType::orderBy('sort_order')->get();
```

置き換え後:

```php
    public function create(Request $request)
    {
        $zoningTypes = ZoningType::orderBy('sort_order')->get();
```

さらに同メソッド末尾の `return view(...)`（85-87 行）を以下で置き換える。

置き換え前:

```php
        return view('realestate.projects.create', compact(
            'zoningTypes', 'costItemsForJs', 'costAliasMap', 'costSkipList', 'costSubtotalKws'
        ));
```

置き換え後:

```php
        // 戻り先（パンくずの中間リンク + キャンセルボタン）。
        // 仕入れ案件一覧から入ったときだけそちらへ戻す。
        // 受け付けるのは 'procurements' の 1 語だけで、URL は route() から自前で組む。
        // リクエストの文字列を href へ素通しさせないのでオープンリダイレクトにならない。
        // ⚠ Blade の @php ではなくここで組む。@section('breadcrumb') は子ビューの実行順に
        //    キャプチャされるため、@php で作ると宣言位置を動かしただけで未定義変数になる。
        $fromProcurements = $request->query('from') === 'procurements';
        $backUrl   = $fromProcurements
            ? route('realestate.procurements.index')
            : route('realestate.projects.index');
        $backLabel = $fromProcurements ? '仕入れ案件一覧' : '分譲地一覧';

        return view('realestate.projects.create', compact(
            'zoningTypes', 'costItemsForJs', 'costAliasMap', 'costSkipList', 'costSubtotalKws',
            'backUrl', 'backLabel'
        ));
```

⚠ `Illuminate\Http\Request` は同ファイルで import 済み（`store()` / `update()` が使っている）。`use` の追加は不要。

- [ ] **Step 4: `projects/create.blade.php` を変数参照にする**

`resources/views/realestate/projects/create.blade.php` の 9 行目を置き換える。

置き換え前:

```blade
    <a href="{{ route('realestate.projects.index') }}" class="hover:text-emerald-600 transition-colors">分譲地一覧</a>
```

置き換え後:

```blade
    <a href="{{ $backUrl }}" class="hover:text-emerald-600 transition-colors">{{ $backLabel }}</a>
```

続いて 30 行目を置き換える。

置き換え前:

```blade
        <x-form-actions submit-label="登録する" :cancel-url="route('realestate.projects.index')" />
```

置き換え後:

```blade
        <x-form-actions submit-label="登録する" :cancel-url="$backUrl" />
```

⚠ **ここが Bug #21 の要点**（罠 1）。属性式には **PHP 変数 1 本**だけを渡す。`route(&quot;…&quot;)` のような HTML エンティティや三項演算子を属性内に書くと、本番の `view:cache` 経由でだけデコードが漏れて 500 になり、ローカルでは再現しない。

- [ ] **Step 5: テストを走らせて通ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php
```

期待: `OK (6 tests, …)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && git add app/Http/Controllers/RealEstate/ProjectController.php resources/views/realestate/projects/create.blade.php tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php && git commit -m "feat(realestate): 分譲地登録の戻り先を入口に応じて切り替える"
```

---

## Task 4: 本番と同じ経路で壊れていないことを確認する

**Files:** なし（検証のみ）

このプロジェクトの過去 500 は「テストも `view:cache` の成功表示も通るのに本番で落ちる」型が大半（Bug #21 / #26 / #30）。**コンパイル済みビューを直接 lint する**のが唯一の確実な検出手段。

- [ ] **Step 1: テスト全体を走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && vendor/bin/phpunit
```

期待: 全 suite が `OK`。落ちたら止めて原因を潰す（既存テストの失敗を「元から赤だった」で流さない）。

- [ ] **Step 2: コンパイル済みビューを lint する（Bug #21 / #26 / #30 の検出）**

`view:cache` は「成功」と表示してもコンパイル結果を lint しないので、生成物を自分で `php -l` にかける。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" > /dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

期待: `Blade templates cached successfully.` が出て、`INVALID:` が **1 行も出ない**こと。

- [ ] **Step 3: Bug #21 の横展開検査（`&quot;` の残存）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && grep -rn "&quot;" resources/views/realestate/
```

期待: 出力が空。

- [ ] **Step 4: Bug #30 の横展開検査（JS コメント中のディレクティブ名）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && grep -rnE '^[[:space:]]*//.*@(json|if|foreach|php|include|section|yield|stack|push)' resources/views/realestate/ | grep -v '@@'
```

期待: 出力が空。

- [ ] **Step 5: ローカルで見た目を確認したい場合のみ CSS をビルドする**

ローカルの `public/build` はデプロイ時まで更新されないので、ブラウザで見るなら手でビルドする（本番は `./deploy.sh` が自動でやるので必須ではない）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-create-dropdown && npm run build
```

---

## Task 5: main へ FF-merge する

**Files:** なし（git 操作）

- [ ] **Step 1: main repo が worktree branch の祖先であることを確認**

```bash
cd /Users/masanori/site/manage && git merge-base --is-ancestor 13.x feature/procurement-create-dropdown && echo "FF-merge 可能"
```

期待: `FF-merge 可能` と表示される。出なければ 13.x が先行しているので、worktree 側で `git rebase 13.x` してから Task 4 のテストをやり直す。

- [ ] **Step 2: FF-merge する**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only feature/procurement-create-dropdown
```

⚠ `composer dump-autoload` は**不要**（新規 PHP クラスを追加していない）。

- [ ] **Step 3: main repo 側でもう一度テストを走らせる**

main repo には dev 依存が入っていない可能性があるので確認してから走らせる。

```bash
cd /Users/masanori/site/manage && ls vendor/bin/phpunit && vendor/bin/phpunit tests/Feature/RealEstate/
```

`No such file` なら先に `composer install`（dev 込み）を実行してから再実行する。期待: `OK`。

---

## Task 6: 本番デプロイと本番ブラウザ確認

**Files:** なし

⚠ **`./deploy.sh` はユーザーの明示承認が必要。** 承認が無い文脈では自動モードの分類器がブロックする。実行前に AskUserQuestion で本番デプロイの可否を確認すること。

- [ ] **Step 1: 本番デプロイの可否をユーザーに確認する**

- [ ] **Step 2: デプロイする**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

`npm run build` → rsync → 本番で `config:cache && route:cache && view:cache` が走る。ビルド失敗時は本番へ何も転送せず中断する。

- [ ] **Step 3: 本番ブラウザで確認する（Bug #28 対策 — ここは省略しない）**

⚠ **HTML にリンクが出ていることと Alpine が実際に動くことは別問題。** `@push('scripts')` が捨てられていて JS が一度も実行されていなかった前例があり、テストは「属性がある」ことしか見ていなかったので緑のままだった。**実際にクリックして開くところまで見る。**

対象: https://www.mitsuwat.co.jp/system/manage/realestate/procurements

| # | 確認項目 | 判定基準 |
|---|---|---|
| 1 | 「新規登録 ▾」をクリックしてパネルが開く | 2 項目が表示される（HTML にあるだけでは不可） |
| 2 | パネルが**縦に 2 項目**並ぶ（Bug #32 と同型の確認） | 横 1 列に潰れていない。`getComputedStyle($0).display` が `block` で子が縦に積まれている |
| 3 | 外側クリックで閉じる | `@click.outside` が効いている |
| 4 | 「分譲地を登録」→ 分譲地の登録画面が開く | URL に `?from=procurements` が付く |
| 5 | その画面のパンくずが「仕入れ案件一覧」 | 「分譲地一覧」になっていない |
| 6 | その画面で**キャンセル → 仕入れ案件一覧へ戻る** | 分譲地一覧に行かない |
| 7 | 必須項目を空のまま登録 → エラーで戻ったあとも**キャンセル先が仕入れ案件一覧のまま** | `back()` がセッションの直前 GET URL（`?from=procurements` 付き）へ戻すことの確認 |
| 8 | 「仕入れ案件を登録」が従来どおり動く（回帰） | 仕入れ案件の登録画面が開く |
| 9 | 分譲地一覧から登録した場合はキャンセル先が分譲地一覧のまま（回帰） | `/realestate/projects` → 新規登録 → キャンセル |
| 10 | スマホ幅でヘッダーが崩れていない | 横スクロールが出ない |
| 11 | staff ロールにドロップダウンが出ない | 「新規登録」ボタンごと非表示 |

- [ ] **Step 4: origin/13.x への push**

⚠ **ユーザーの明示指示があった時のみ。** 指示が無ければ push しない。

---

## 自己レビュー結果

**設計書との突き合わせ（`2026-07-29-procurement-list-create-dropdown-design.md`）**

| 設計書の節 | 対応タスク |
|---|---|
| §4 決定 1（ドロップダウン化） | Task 1 |
| §4 決定 2（既存 create へ遷移・新画面なし） | Task 1（リンク先が既存ルート） |
| §4 決定 3（キャンセルは仕入れ案件一覧へ） | Task 3 |
| §4 決定 4（登録成功時は現状のまま） | 変更しない＝`store()` に手を入れないことで担保（Task 3 は `create()` のみ変更） |
| §4 決定 5（分譲地一覧を残す） | Task 3 の `test_project_create_without_from_points_back_to_project_list` で回帰固定 |
| §4 決定 6（モックを作らない） | Task 6 の本番ブラウザ確認で代替 |
| §5.1 画面仕様（ヘッダー） | Task 1 Step 3 |
| §5.2 画面仕様（パンくず・キャンセル） | Task 3 Step 3-4 |
| §6.2 一覧ヘッダー | Task 1 Step 3 |
| §6.3 `create()` | Task 3 Step 3 |
| §6.4 `create.blade.php` | Task 3 Step 4 |
| §6.5 仕入れ案件側は無変更 | 触らない（Task 1 のリンク先が既存ルートのまま） |
| §7 テスト 6 本 | Task 1（2 本）+ Task 3（3 本）+ Task 2（1 本）= 6 本 |
| §9 デプロイ | Task 5 / Task 6 |

**設計書からの意図的な逸脱 2 点**（どちらも実測に基づく）:

1. **`zoning_types` の追加（Task 2）が設計書に無い。** 設計書 §6.1 の変更ファイルは 4 本だが、実測すると `zoning_types` がテスト DB に存在せず、§7 のテストのうち分譲地 create を叩く 4 本が実装の正否に関係なく落ちる。`tests/Concerns/CreatesRealEstateSchema.php` の変更を 1 本追加した（テスト専用ファイルなので本番挙動への影響はゼロ）。
2. **テストのアサーション方式を設計書 §7 より厳しくした。** 設計書は「`assertSee(route(...))` で URL そのものを見る」としているが、実測するとサイドバーが `/realestate/procurements` を 3 箇所・`/realestate/projects` を 2 箇所 href に出すため、**分譲地 create 画面側の 4 本はこの方式では実装が空でも緑になる**。`assertViewHas` + パンくずの完全一致 + キャンセルリンクの正規表現に変更した。一覧側の 2 本は URL が一意（`…/create` / `…/create?from=procurements`）なので設計書どおり `assertSee` のままでよい。
