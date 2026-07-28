# 仕入れ案件一覧に分譲地を統合 — 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/realestate/procurements`（仕入れ案件一覧）に分譲地（`re_projects`）を混在表示し、不動産案件の横断ビューに格上げする。

**Architecture:** 表示用 readonly DTO（`ProcurementListRow`）で仕入れ案件と分譲地のカラム差を吸収し、サービス（`ProcurementListService`）が「①両テーブルから並び順キーだけ引いて PHP でマージ・ソート → ②現在ページの 20 件分だけモデルを読む」の 2 段構えでページングする。コントローラはサービスを呼ぶだけ、Blade は DTO のプロパティを読むだけにして `instanceof` 分岐を無くす。**DB 変更・enum 変更は無し。**

**Tech Stack:** Laravel 12.55.0 / PHP 8.3(prod) / Blade + Alpine.js 3 / PHPUnit（SQLite in-memory）

**設計書:** `docs/superpowers/specs/2026-07-27-procurement-list-with-projects-design.md`
**モック:** `docs/mockups/realestate/procurements-with-projects.html`

---

## 事前に頭に入れること（踏むと確実に事故る）

| # | 罠 | 対処 |
|---|---|---|
| 1 | `RealEstatePropertyType` enum に `Project` ケースを足す | **絶対に足さない。** 同じ enum を登録フォーム `resources/views/realestate/procurements/_form.blade.php:16` と `ProcurementController::validateProcurement()` が参照しており、「物件種別＝分譲地の仕入れ案件」が作れてしまう。一覧フィルタ専用の擬似値 `'project'` を Blade に静的 `<option>` で 1 本書く |
| 2 | キーのマージで `->toBase()` を省く | **片方が 0 件のときだけ 500**（`Call to a member function getKey() on array`）。`Eloquent\Collection::map()` は `contains()` で base 化を判定するため、**空コレクションでは base に落ちない**（`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Collection.php:423`、Laravel 12.55.0 で実測確認済み）。docs/RULES.md Bug #27 と同型 |
| 3 | `badge-prj-*` CSS の追加漏れ | 分譲地のステータスバッジが**無色**で描画される。`resources/views/realestate/projects/index.blade.php:318-325` から 8 種そのまま持ってくる |
| 4 | `@json()` を `x-data="..."` 属性の中に入れる | Alpine が初期化されず**バッジのクリックが無反応**になる（Bug #23）。`@json` は `<script>` の中だけ。`x-data` には文字列・数値リテラルしか入れない |
| 5 | `@json()` に多行の配列リテラルを渡す | Blade の引数パーサが壊れコンパイル済み PHP が ParseError（Bug #26）。`$statusOptionsByKind` は**コントローラで組んで変数 1 本**で渡す |
| 6 | キャスト済み属性に `tryFrom()` | `TypeError`（Bug #22）。`$model->status` は既に enum。`tryFrom()` を使ってよいのは**リクエストの生文字列**を変換するときだけ（Task 2 の `mapStatusForProject` がまさにそれ） |
| 7 | ページ送りでフィルタが飛ぶ | `ConvertEmptyStringsToNull` で `?status=` は **null** で届き、`Arr::query()`（= `http_build_query`）が **null のキーを捨てる**（実測確認済み）。null を `''` に戻してからページャへ渡す（Task 6） |
| 8 | 「HTML に出ている」で検証を止める | Bug #28 と同型で、スクリプトが一度も実行されていないケースを取り逃す。**呼び出し側（属性）と定義側（`<script>`）を対で**テストし、最後は本番ブラウザで実クリックする |

---

## ファイル構成

| ファイル | 区分 | 責務 |
|---|---|---|
| `app/Services/RealEstate/ProcurementListRow.php` | 新規 | 1 行分の正規化済み表示データ（readonly DTO）。Blade からモデル差異を隠す |
| `app/Services/RealEstate/ProcurementListService.php` | 新規 | フィルタ → マージ → ソート → ページング |
| `app/Http/Controllers/RealEstate/ProcurementController.php` | 変更 | `index()` をサービス呼び出しに置換 + `statusOptions()` を追加 |
| `resources/views/realestate/procurements/index.blade.php` | 変更 | 10 列化・フィルタバー・ステータスセルの多型化・`badge-prj-*` CSS |
| `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php` | 新規 | 全テスト |

既存の `app/Services/Tenant/`（`RentalIncomeService` 等）と同じ配置・命名に揃える。

> ⚠ **プラン中の行番号は「着手前の元ファイル」基準の目安。** 編集を進めると行数がずれるので、
> **必ず各ステップに載せた「置き換え前のコード」の文字列で検索して当てること。**
> 行番号だけを頼りに置き換えないこと。

---

## テスト環境（着手前に 1 回だけ / 実行済みなら飛ばす）

**worktree 内に独立した `vendor` を作り、テストは worktree で走らせる。**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && composer install
```

`.env` が無いと `APP_KEY` 不在でブートしないので、テスト専用のダミーを置く
（`.env` は `.gitignore` 済みでコミットに混ざらない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && printf 'APP_NAME=manage\nAPP_ENV=testing\nAPP_KEY=%s\nAPP_DEBUG=true\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n' "base64:$(openssl rand -base64 32)" > .env
```

実行コマンド（以降のタスクで使う）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

### なぜ main repo の vendor を symlink しないのか（実測で確認した罠）

`ln -s /Users/masanori/site/manage/vendor vendor` は**動かない**。
PHP の `__DIR__` は **symlink を実体パスに解決する**（実測確認済み）ため、
`vendor/composer/autoload_psr4.php` の

```php
$vendorDir = dirname(__DIR__);
$baseDir   = dirname($vendorDir);   // ← main repo になってしまう
```

が main repo を指し、**`App\*` が worktree ではなく main repo の `app/` から読まれる**。
新規クラス 2 本は main repo に無いので「クラスが見つからない」が永久に解消しない。
phpunit 自体は worktree の `phpunit.xml` を読んで worktree の `tests/` を発見するため、
**「テストは見つかるのにクラスだけ古い」という気づきにくい壊れ方**になる。

### その他の注意

- **main repo の `vendor` は `--no-dev` のまま触らない。** `deploy.sh` が vendor を本番へ rsync するため、
  dev 依存を入れたまま忘れると本番に開発用パッケージが載る（過去に踏んでいる）。
  worktree 側に閉じ込めればこの事故が起きない
- ⚠ **`composer dump-autoload` は worktree で絶対に実行しない。** autoloader の `$baseDir` に
  worktree パスが焼き込まれ、main repo の Apache が worktree を参照する事故になる。
  `composer install` が生成する autoloader は worktree 内で完結するので問題ない
  （main repo の `vendor/` を書き換えない）。本番反映用の `composer dump-autoload` は
  **main repo の cwd で** Task 7 で実行する
- テスト DB は SQLite in-memory（`phpunit.xml` の `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`）。
  worktree の `.env` にも MySQL 認証情報を入れないので、実 DB には**到達できない**
- ⚠ **`bootstrap/cache/config.php` が在ると `phpunit.xml` の env が無視される。** worktree では
  `composer install` 直後は不在だが、`artisan config:cache` を打ったら消すこと:

```bash
ls bootstrap/cache/config.php 2>/dev/null && php artisan config:clear
```

- `re_*` / `hs_*` / `buyers` は migration 管理外。`Tests\Concerns\CreatesRealEstateSchema` trait が
  SQLite にテーブルを作る（`re_procurements` / `re_projects` / `re_project_lots` /
  `re_project_costs` / `re_procurement_costs` すべて含まれることを実測確認済み）

---

## Task 1: `ProcurementListRow` DTO

**Files:**
- Create: `app/Services/RealEstate/ProcurementListRow.php`
- Create: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`

- [ ] **Step 1: テストファイルを作り、DTO のマッピングを検証する失敗テストを書く**

`tests/Feature/RealEstate/ProcurementListWithProjectsTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Services\RealEstate\ProcurementListRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件一覧（分譲地統合版）の検証。
 *
 * re_* / hs_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementListWithProjectsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
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

    /** ⚠ 一覧が描画するのは procurement_code ではなく property_name（既存テストの実測メモ） */
    private function makeProcurement(string $code, array $attrs = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code'   => $code,
            'property_type'      => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'   => RealEstateTransactionType::Purchase->value,
            'status'             => 'selling',
            'property_name'      => "物件{$code}",
            'address'            => '愛媛県松山市1-1-1',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => 1,
        ], $attrs));
    }

    /** 既定では assessment/purchase price を入れない（ReProject の saved フックを no-op に保つ） */
    private function makeProject(string $code, array $attrs = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code'       => $code,
            'project_name'       => "分譲地{$code}",
            'status'             => 'selling',
            'address'            => '愛媛県松山市2-2-2',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => 1,
        ], $attrs));
    }

    private function makeLot(ReProject $project, int $lotNumber, string $status = 'on_sale'): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => $status,
        ]);
    }

    // ================================================================
    // Task 1: DTO
    // ================================================================

    public function test_row_from_procurement_maps_fields(): void
    {
        $p = $this->makeProcurement('PRC-001', [
            'purchase_price'       => 30000000,
            'target_selling_price' => 40000000,
            'latitude'             => 33.8416,
            'longitude'            => 132.7657,
        ]);

        $row = ProcurementListRow::fromProcurement($p->fresh()->load('costs'));

        $this->assertSame('procurement', $row->kind);
        $this->assertSame($p->id, $row->id);
        $this->assertSame('物件PRC-001', $row->name);
        $this->assertSame(ProcurementStatus::Selling, $row->status);
        $this->assertSame('中古戸建', $row->propertyTypeLabel);
        $this->assertSame('自社買取', $row->transactionTypeLabel);
        $this->assertSame(30000000, $row->purchasePrice);
        $this->assertSame(40000000, $row->targetSellingPrice);
        // purchase_price を入れたので syncPropertyPurchaseCost() が原価 1 行を作る
        // → 粗利 = 40,000,000 − 30,000,000
        $this->assertSame(10000000, $row->expectedProfit);
        $this->assertEqualsWithDelta(33.8416, $row->latitude, 0.0001);
        $this->assertNull($row->soldLotCount);
        $this->assertNull($row->lotCount);
        $this->assertNull($row->lotsUrl);
        $this->assertStringContainsString("/realestate/procurements/{$p->id}", $row->showUrl);
    }

    public function test_row_from_project_maps_lot_counts(): void
    {
        $pj = $this->makeProject('PJ-001', ['target_selling_price' => 50000000]);
        $this->makeLot($pj, 1, 'sold');
        $this->makeLot($pj, 2, 'sold');
        $this->makeLot($pj, 3, 'on_sale');

        $row = ProcurementListRow::fromProject($pj->fresh()->load('costs', 'lots'));

        $this->assertSame('project', $row->kind);
        $this->assertSame('分譲地PJ-001', $row->name);
        $this->assertSame(ProjectStatus::Selling, $row->status);
        $this->assertSame('分譲地', $row->propertyTypeLabel);
        $this->assertNull($row->transactionTypeLabel);
        $this->assertSame(2, $row->soldLotCount);
        $this->assertSame(3, $row->lotCount);
        $this->assertStringContainsString("/realestate/projects/{$pj->id}", $row->showUrl);
        $this->assertStringContainsString("/realestate/projects/{$pj->id}/lots", $row->lotsUrl);
    }

    /** 区画 0 件でも「区画 0 / 0」と区画ボタンを出すため、null ではなく 0 と URL を返す */
    public function test_row_from_project_with_zero_lots(): void
    {
        $pj = $this->makeProject('PJ-002');

        $row = ProcurementListRow::fromProject($pj->fresh()->load('costs', 'lots'));

        $this->assertSame(0, $row->soldLotCount);
        $this->assertSame(0, $row->lotCount);
        $this->assertNotNull($row->lotsUrl);
    }
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: FAIL — `Class "App\Services\RealEstate\ProcurementListRow" not found`

- [ ] **Step 3: DTO を実装する**

`app/Services/RealEstate/ProcurementListRow.php` を新規作成:

```php
<?php

namespace App\Services\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Models\ReProcurement;
use App\Models\ReProject;

/**
 * 仕入れ案件一覧（分譲地統合版）の 1 行分の表示データ。
 *
 * 仕入れ案件（re_procurements）と分譲地（re_projects）のカラム差
 * （property_name / project_name など）を吸収し、Blade が instanceof で
 * 分岐せずに済むようにするための readonly 値オブジェクト。
 *
 * 分譲地にしか無い値（区画数・区画URL）は仕入れ案件では null にして、
 * 「無い」ことを型で表す。
 */
final class ProcurementListRow
{
    public const KIND_PROCUREMENT = 'procurement';
    public const KIND_PROJECT     = 'project';

    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $name,
        public readonly ProcurementStatus|ProjectStatus $status,
        public readonly string $propertyTypeLabel,
        public readonly ?string $transactionTypeLabel,
        public readonly ?int $purchasePrice,
        public readonly ?int $targetSellingPrice,
        public readonly ?int $expectedProfit,
        public readonly ?string $address,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?int $soldLotCount,
        public readonly ?int $lotCount,
        public readonly string $showUrl,
        public readonly ?string $lotsUrl,
    ) {
    }

    /**
     * ⚠ costs をイーガーロードしたモデルを渡すこと（getExpectedProfit が使う）
     */
    public static function fromProcurement(ReProcurement $p): self
    {
        return new self(
            kind: self::KIND_PROCUREMENT,
            id: (int) $p->id,
            name: $p->property_name,
            // ⚠ status は casts() で enum 済み。tryFrom() を通すと TypeError（Bug #22）
            status: $p->status,
            propertyTypeLabel: $p->property_type->label(),
            transactionTypeLabel: $p->transaction_type->label(),
            purchasePrice: $p->purchase_price,
            targetSellingPrice: $p->target_selling_price,
            expectedProfit: $p->getExpectedProfit(),
            address: $p->address,
            latitude: $p->latitude !== null ? (float) $p->latitude : null,
            longitude: $p->longitude !== null ? (float) $p->longitude : null,
            soldLotCount: null,
            lotCount: null,
            showUrl: route('realestate.procurements.show', $p->id),
            lotsUrl: null,
        );
    }

    /**
     * ⚠ costs と lots をイーガーロードしたモデルを渡すこと
     *   （getExpectedProfit / getSoldLotCount が使う）
     */
    public static function fromProject(ReProject $pj): self
    {
        return new self(
            kind: self::KIND_PROJECT,
            id: (int) $pj->id,
            name: $pj->project_name,
            status: $pj->status,
            // 分譲地は一覧上「素のテキストで 分譲地」。enum は持たない
            propertyTypeLabel: '分譲地',
            // 分譲地に取引種別カラムは無い
            transactionTypeLabel: null,
            purchasePrice: $pj->purchase_price,
            targetSellingPrice: $pj->target_selling_price,
            expectedProfit: $pj->getExpectedProfit(),
            address: $pj->address,
            latitude: $pj->latitude !== null ? (float) $pj->latitude : null,
            longitude: $pj->longitude !== null ? (float) $pj->longitude : null,
            soldLotCount: $pj->getSoldLotCount(),
            lotCount: $pj->lots->count(),
            showUrl: route('realestate.projects.show', $pj->id),
            // 区画 0 件でも区画一覧へのリンクは出す（そこから登録するため）
            lotsUrl: route('realestate.projects.lots', $pj->id),
        );
    }

    public function isProject(): bool
    {
        return $this->kind === self::KIND_PROJECT;
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: PASS（3 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Services/RealEstate/ProcurementListRow.php tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
git commit -m "feat(realestate): 仕入れ案件一覧の行 DTO ProcurementListRow を追加"
```

---

## Task 2: `ProcurementListService` — マージ・ソート・ページング・ステータスフィルタ

**Files:**
- Create: `app/Services/RealEstate/ProcurementListService.php`
- Modify: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`（テスト追加）

- [ ] **Step 1: 失敗テストを書く（空ケース 3 本を最優先）**

テストファイルの `use` に以下を追加:

```php
use App\Services\RealEstate\ProcurementListService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
```

クラス末尾（`test_row_from_project_with_zero_lots` の後）に追記:

```php
    // ================================================================
    // Task 2: サービス（マージ・ソート・ページング・ステータスフィルタ）
    // ================================================================

    /**
     * サービスを直接叩く。
     *
     * ⚠ Paginator::resolveCurrentPage() / resolveCurrentPath() は
     *   コンテナの 'request' を見る（PaginationServiceProvider がそう束ねている）ので、
     *   作った Request をコンテナへも差し込む。
     *
     * ⚠ クエリは文字列で渡す。ConvertEmptyStringsToNull は HTTP ミドルウェアなので
     *   Request::create では効かないが、`?status=` は '' で届き、サービス側の
     *   filled() 判定は '' でも null でも同じ経路に落ちるため実挙動と一致する
     *   （実測確認済み）。
     */
    private function paginateVia(string $queryString = ''): LengthAwarePaginator
    {
        $uri     = '/realestate/procurements' . ($queryString !== '' ? '?' . $queryString : '');
        $request = Request::create($uri, 'GET');
        $this->app->instance('request', $request);

        return app(ProcurementListService::class)->paginate($request);
    }

    /** @return array<int, string> */
    private function namesOf(LengthAwarePaginator $rows): array
    {
        return collect($rows->items())->pluck('name')->all();
    }

    /**
     * 空ケース1: 仕入れ案件 0 件・分譲地のみ。
     * ⚠ Bug #27 回帰。空の Eloquent\Collection に配列要素のコレクションを merge すると
     *   getKey() が呼ばれて 500 になる。keysFrom() の ->toBase() が効いていることの検証。
     */
    public function test_projects_only_does_not_error(): void
    {
        $this->makeProject('PJ-001');

        $rows = $this->paginateVia();

        $this->assertSame(1, $rows->total());
        $this->assertSame('project', $rows->items()[0]->kind);
    }

    /** 空ケース2: 分譲地 0 件・仕入れ案件のみ（Bug #27 回帰） */
    public function test_procurements_only_does_not_error(): void
    {
        $this->makeProcurement('PRC-001');

        $rows = $this->paginateVia();

        $this->assertSame(1, $rows->total());
        $this->assertSame('procurement', $rows->items()[0]->kind);
    }

    /** 空ケース3: 両方 0 件 */
    public function test_both_empty_does_not_error(): void
    {
        $rows = $this->paginateVia();

        $this->assertSame(0, $rows->total());
        $this->assertCount(0, $rows->items());
    }

    /** 情報入手日の降順で両種別が 1 本に混ざる（NULL は末尾） */
    public function test_sorted_by_info_obtained_date_desc_with_nulls_last(): void
    {
        $this->makeProcurement('PRC-OLD', ['info_obtained_date' => '2026-01-01']);
        $this->makeProject('PJ-NEW',      ['info_obtained_date' => '2026-07-01']);
        $this->makeProcurement('PRC-MID', ['info_obtained_date' => '2026-04-01']);
        $this->makeProject('PJ-NULL',     ['info_obtained_date' => null]);

        $this->assertSame(
            ['分譲地PJ-NEW', '物件PRC-MID', '物件PRC-OLD', '分譲地PJ-NULL'],
            $this->namesOf($this->paginateVia())
        );
    }

    /** 既定（進行中のみ）は 仕入れ sold/lost と 分譲地 sold_out/lost を除外する */
    public function test_default_active_excludes_closed_of_both_types(): void
    {
        $this->makeProcurement('PRC-OK',   ['status' => 'selling']);
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProcurement('PRC-LOST', ['status' => 'lost']);
        $this->makeProject('PJ-OK',        ['status' => 'selling']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);
        $this->makeProject('PJ-LOST',      ['status' => 'lost']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-OK', '分譲地PJ-OK'],
            $this->namesOf($this->paginateVia())
        );
    }

    /** ?status=sold は 仕入れ sold と 分譲地 sold_out の両方にヒットする */
    public function test_status_sold_matches_both_sold_and_sold_out(): void
    {
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);
        $this->makeProcurement('PRC-SELL', ['status' => 'selling']);
        $this->makeProject('PJ-SELL',      ['status' => 'selling']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-SOLD', '分譲地PJ-SOLDOUT'],
            $this->namesOf($this->paginateVia('status=sold'))
        );
    }

    /** ?status=site_survey は分譲地に該当ステータスが無いので分譲地が消える */
    public function test_status_site_survey_excludes_projects(): void
    {
        $this->makeProcurement('PRC-SURVEY', ['status' => 'site_survey']);
        $this->makeProject('PJ-001',         ['status' => 'selling']);

        $this->assertSame(
            ['物件PRC-SURVEY'],
            $this->namesOf($this->paginateVia('status=site_survey'))
        );
    }

    /** ?status=（全て）は終了状態も含めて両種別を出す */
    public function test_status_all_includes_closed_of_both_types(): void
    {
        $this->makeProcurement('PRC-SOLD', ['status' => 'sold']);
        $this->makeProject('PJ-SOLDOUT',   ['status' => 'sold_out']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-SOLD', '分譲地PJ-SOLDOUT'],
            $this->namesOf($this->paginateVia('status='))
        );
    }
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: FAIL — `Class "App\Services\RealEstate\ProcurementListService" not found`（新規 8 本）

- [ ] **Step 3: サービスを実装する**

`app/Services/RealEstate/ProcurementListService.php` を新規作成:

```php
<?php

namespace App\Services\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * 仕入れ案件（re_procurements）と分譲地（re_projects）を 1 本の一覧にマージする。
 *
 * 2 段構え:
 *   第1段 — 両テーブルからフィルタ適用済みの id と info_obtained_date だけを引き、
 *            PHP 側でマージ・ソートして「並び順キー」を作る
 *   第2段 — 現在ページの 20 件分だけモデルを読み込み、DTO へ変換する
 *
 * 全件をモデルとして読み込むと costs / lots のイーガーロードが全行分走るため。
 * 行数が数千を超えるようなら SQL UNION へ移す余地があるが、現在の規模
 * （本番でも数十〜百程度）では過剰。
 */
class ProcurementListService
{
    /**
     * 一覧フィルタ専用の物件種別 擬似値。
     *
     * ⚠ RealEstatePropertyType enum には追加しないこと。
     *   同じ enum を仕入れ案件の登録フォーム（_form.blade.php）と
     *   ProcurementController::validateProcurement() が参照しており、
     *   追加すると「物件種別＝分譲地の仕入れ案件」が作れてしまう。
     */
    public const PROPERTY_TYPE_PROJECT = 'project';

    /** 分譲地に該当ステータスが存在しないことを表す番兵 */
    private const STATUS_NO_MATCH = '__no_match__';

    public function paginate(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $keys  = $this->sortedKeys($request);
        $page  = LengthAwarePaginator::resolveCurrentPage();
        $slice = $keys->forPage($page, $perPage);

        // 現在ページ分だけモデルを読む。costs は getExpectedProfit()、
        // lots は区画数が使うのでイーガーロード必須（無いと N+1）
        $procs = ReProcurement::with('costs')
            ->whereIn('id', $slice->where('kind', ProcurementListRow::KIND_PROCUREMENT)->pluck('id'))
            ->get()
            ->keyBy('id');

        $projs = ReProject::with('costs', 'lots')
            ->whereIn('id', $slice->where('kind', ProcurementListRow::KIND_PROJECT)->pluck('id'))
            ->get()
            ->keyBy('id');

        $rows = $slice->map(function (array $k) use ($procs, $projs): ?ProcurementListRow {
            if ($k['kind'] === ProcurementListRow::KIND_PROCUREMENT) {
                $model = $procs->get($k['id']);

                return $model ? ProcurementListRow::fromProcurement($model) : null;
            }

            $model = $projs->get($k['id']);

            return $model ? ProcurementListRow::fromProject($model) : null;
        })->filter()->values();

        return new LengthAwarePaginator($rows, $keys->count(), $perPage, $page, [
            'path'  => Paginator::resolveCurrentPath(),
            'query' => $this->paginationQuery($request),
        ]);
    }

    /**
     * ページ送りリンクに載せるクエリ。
     *
     * ⚠ ConvertEmptyStringsToNull により ?status= は null で届く。null のまま渡すと
     *   Arr::query()（= http_build_query）が null のキーを丸ごと捨てるため、
     *   2 ページ目で「ステータス: 全て」が既定の「進行中のみ」へ戻ってしまう（実測確認済み）。
     *   空文字へ戻して保持する。
     *   page キーは AbstractPaginator::url() 側で array_merge の後勝ちにより
     *   正しいページ番号に上書きされるので、除去しなくてよい（実測確認済み）。
     *
     * @return array<string, mixed>
     */
    private function paginationQuery(Request $request): array
    {
        return array_map(fn ($v) => $v ?? '', $request->query());
    }

    /**
     * 両テーブルの並び順キーをマージしてソートする。
     *
     * 並び順: 情報入手日 降順（NULL 末尾）→ id 降順 → 種別（仕入れ案件が先）
     * 種別を最終タイブレークに入れるのは、日付も id も一致する行が別テーブル間では
     * 起こりうるため（ページ間で行が重複・欠落しないよう順序を確定させる）。
     *
     * @return Collection<int, array{kind: string, id: int, date: \Illuminate\Support\Carbon|null}>
     */
    private function sortedKeys(Request $request): Collection
    {
        $procKeys = $this->keysFrom(
            $this->procurementQuery($request),
            ProcurementListRow::KIND_PROCUREMENT
        );
        $projKeys = $this->keysFrom(
            $this->projectQuery($request),
            ProcurementListRow::KIND_PROJECT
        );

        return $procKeys->merge($projKeys)->sortByDesc(fn (array $k) => [
            $k['date'] === null ? 0 : 1,                                  // NULL を末尾へ
            $k['date']?->getTimestamp() ?? 0,                             // 情報入手日 降順
            $k['id'],                                                     // id 降順
            $k['kind'] === ProcurementListRow::KIND_PROCUREMENT ? 1 : 0,  // 完全同着時の確定タイブレーク
        ])->values();
    }

    /**
     * @param  Builder<ReProcurement>|Builder<ReProject>|null  $query
     * @return Collection<int, array{kind: string, id: int, date: \Illuminate\Support\Carbon|null}>
     */
    private function keysFrom(?Builder $query, string $kind): Collection
    {
        if ($query === null) {
            return collect();   // その種別は該当なし
        }

        return $query->get(['id', 'info_obtained_date'])
            ->map(fn ($r) => [
                'kind' => $kind,
                'id'   => (int) $r->id,
                'date' => $r->info_obtained_date,
            ])
            // ⚠ toBase() は必須。Eloquent\Collection::map() は contains() で base 化を
            //   判定するため、空コレクションでは Eloquent\Collection のまま残る
            //   （Laravel 12.55.0 の Eloquent/Collection.php:423 で実測確認）。
            //   そこへ配列要素のコレクションを merge すると getKey() が呼ばれて 500
            //   （docs/RULES.md Bug #27 と同型）。片方 0 件は実際に起こる。
            ->toBase();
    }

    /**
     * 仕入れ案件側のクエリ。該当し得ないフィルタのときは null を返す
     * （whereRaw('1 = 0') のような打ち消し条件より「該当なし」が型で読める）。
     *
     * @return Builder<ReProcurement>|null
     */
    private function procurementQuery(Request $request): ?Builder
    {
        // 物件種別に擬似値「分譲地」が選ばれていたら仕入れ案件は該当なし
        if ($request->input('property_type') === self::PROPERTY_TYPE_PROJECT) {
            return null;
        }

        $query = ReProcurement::query();

        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'active') {
            $query->whereNotIn('status', [
                ProcurementStatus::Lost->value,
                ProcurementStatus::Sold->value,
            ]);
        } elseif (filled($statusFilter)) {
            // 「全て」= ?status= は ConvertEmptyStringsToNull で null 化されるため
            // filled() で弾き、フィルタ無し（＝全件）に落とす。'' 比較では null が
            // 素通りして where('status', null) となり 0 件になる
            $query->where('status', $statusFilter);
        }

        if ($request->filled('property_type')) {
            $query->where('property_type', $request->input('property_type'));
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->input('transaction_type'));
        }

        $this->applyKeyword($query, $request, ['procurement_code', 'property_name', 'address']);

        return $query;
    }

    /**
     * 分譲地側のクエリ。該当し得ないフィルタのときは null を返す。
     *
     * @return Builder<ReProject>|null
     */
    private function projectQuery(Request $request): ?Builder
    {
        $propertyType = $request->input('property_type');

        // 実在の物件種別で絞られていたら分譲地は該当なし（分譲地は擬似値 'project' のみ）
        if (filled($propertyType) && $propertyType !== self::PROPERTY_TYPE_PROJECT) {
            return null;
        }

        // 取引種別は分譲地にカラム自体が無いので、指定されたら該当なし
        if ($request->filled('transaction_type')) {
            return null;
        }

        $status = $this->mapStatusForProject($request);
        if ($status === self::STATUS_NO_MATCH) {
            return null;   // 「現地調査」など分譲地に存在しないステータス
        }

        $query = ReProject::query();

        if ($status === 'active') {
            $query->whereNotIn('status', [
                ProjectStatus::Lost->value,
                ProjectStatus::SoldOut->value,
            ]);
        } elseif ($status !== null) {
            $query->where('status', $status);
        }

        $this->applyKeyword($query, $request, ['project_code', 'project_name', 'address']);

        return $query;
    }

    /**
     * 仕入れ案件のステータス値を分譲地のステータス値へ写す。
     *
     * 戻り値:
     *   'active'         — 既定（進行中のみ）
     *   null             — 全て（フィルタ無し）
     *   STATUS_NO_MATCH  — 分譲地に該当なし（＝分譲地を結果から外す）
     *   その他            — re_projects.status に直接当てる値
     */
    private function mapStatusForProject(Request $request): ?string
    {
        $statusFilter = $request->input('status', 'active');

        if ($statusFilter === 'active') {
            return 'active';
        }

        if (! filled($statusFilter)) {
            return null;   // 「全て」
        }

        // 「販売済」だけラベルが同じで値が違う: 仕入れ sold ⇔ 分譲地 sold_out
        if ($statusFilter === ProcurementStatus::Sold->value) {
            return ProjectStatus::SoldOut->value;
        }

        // 同名で存在するものはそのまま。存在しない（site_survey）は該当なし。
        // ⚠ ここの tryFrom() はリクエストの生文字列に対する変換なので正しい用法。
        //   キャスト済み属性へ渡すと TypeError になる（Bug #22）のとは別物。
        return ProjectStatus::tryFrom($statusFilter)?->value ?? self::STATUS_NO_MATCH;
    }

    /**
     * @param  Builder<ReProcurement>|Builder<ReProject>  $query
     * @param  array<int, string>  $columns
     */
    private function applyKeyword(Builder $query, Request $request, array $columns): void
    {
        if (! $request->filled('keyword')) {
            return;
        }

        $keyword = $request->input('keyword');

        $query->where(function (Builder $q) use ($keyword, $columns): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$keyword}%");
            }
        });
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: PASS（11 tests）

- [ ] **Step 5: `->toBase()` を外すと本当に落ちることを確認する（罠が生きている証明）**

`keysFrom()` の `->toBase()` を一時的に削除して空ケースだけ走らせる:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter test_projects_only_does_not_error
```

Expected: FAIL — `Call to a member function getKey() on array`
確認できたら **`->toBase()` を必ず元に戻して**、再度 PASS になることを確認する。

- [ ] **Step 6: コミット**

```bash
git add app/Services/RealEstate/ProcurementListService.php tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
git commit -m "feat(realestate): 仕入れ案件と分譲地をマージする ProcurementListService を追加"
```

---

## Task 3: サービス — 物件種別・取引種別・キーワード フィルタ

Task 2 でコードは書けているので、**このタスクはフィルタ挙動を回帰テストで固定するのが目的**。
テストが落ちたらサービスを直す。

**Files:**
- Modify: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`
- Modify（必要な場合のみ）: `app/Services/RealEstate/ProcurementListService.php`

- [ ] **Step 1: フィルタのテストを追加する**

クラス末尾に追記:

```php
    // ================================================================
    // Task 3: 物件種別・取引種別・キーワード フィルタ
    // ================================================================

    /**
     * ?property_type=project は分譲地だけを出す（擬似値。enum には無い値）。
     *
     * ⚠ これは観測可能な振る舞い（分譲地だけが出ること）の検証。
     *   procurementQuery() 冒頭の早期 return を消しても、後続の
     *   where('property_type', 'project') が実在しない値を課すので結果は 0 件のままで
     *   このテストは PASS する（実測）。早期 return は「意図を明示し無駄なクエリを避ける」ためのもの。
     */
    public function test_property_type_project_shows_only_projects(): void
    {
        $this->makeProcurement('PRC-001');
        $this->makeProject('PJ-001');

        $this->assertSame(
            ['分譲地PJ-001'],
            $this->namesOf($this->paginateVia('property_type=project'))
        );
    }

    /** 実在の物件種別で絞ると分譲地が消える */
    public function test_property_type_enum_excludes_projects(): void
    {
        $this->makeProcurement('PRC-HOUSE', ['property_type' => 'used_house']);
        $this->makeProcurement('PRC-MANSION', ['property_type' => 'used_mansion']);
        $this->makeProject('PJ-001');

        $this->assertSame(
            ['物件PRC-HOUSE'],
            $this->namesOf($this->paginateVia('property_type=used_house'))
        );
    }

    /** 取引種別で絞ると分譲地が消える（分譲地に transaction_type カラムが無いため） */
    public function test_transaction_type_excludes_projects(): void
    {
        $this->makeProcurement('PRC-BUY',    ['transaction_type' => 'purchase']);
        $this->makeProcurement('PRC-BROKER', ['transaction_type' => 'brokerage']);
        $this->makeProject('PJ-001');

        $this->assertSame(
            ['物件PRC-BUY'],
            $this->namesOf($this->paginateVia('transaction_type=purchase'))
        );
    }

    /** キーワードは 仕入れ案件（案件番号/物件名/所在地）と分譲地（PJ番号/PJ名/所在地）を横断する */
    public function test_keyword_searches_both_tables(): void
    {
        $this->makeProcurement('PRC-777');
        $this->makeProject('PJ-777');
        $this->makeProcurement('PRC-100');
        $this->makeProject('PJ-100');

        // 案件番号 / PJ番号 の両方でヒットする
        $this->assertEqualsCanonicalizing(
            ['物件PRC-777', '分譲地PJ-777'],
            $this->namesOf($this->paginateVia('keyword=777'))
        );

        // 仕入れ案件だけに一致する語（分譲地が混ざらないこと）
        // ⚠ makeProcurement の既定は property_name = "物件{$code}" なので "PRC-100" は
        //   procurement_code と property_name の両方に含まれる。ここで検証しているのは
        //   「テーブルをまたいで漏れないこと」であって、どのカラムでヒットしたかではない。
        //   カラム個別の検証は test_keyword_matches_each_column_independently が担う。
        $this->assertSame(
            ['物件PRC-100'],
            $this->namesOf($this->paginateVia('keyword=PRC-100'))
        );

        // 分譲地だけに一致する語（仕入れ案件が混ざらないこと）
        $this->assertSame(
            ['分譲地PJ-100'],
            $this->namesOf($this->paginateVia('keyword=PJ-100'))
        );
    }

    /**
     * キーワードが 4 つの検索カラムそれぞれで独立にヒットする。
     *
     * ⚠ ヘルパーの既定値（property_name = "物件{$code}" / project_name = "分譲地{$code}"）を
     *   そのまま使うと、コードが名前にも含まれてしまい「どのカラムでヒットしたか」を
     *   区別できない。検索カラムを 1 本外してもテストが緑のまま通る（実測）。
     *   そのため、ここではコードと名前が重ならないデータを明示的に作る。
     */
    public function test_keyword_matches_each_column_independently(): void
    {
        // 案件番号だけに 'ALPHA' を含む（物件名・所在地には無い）
        $this->makeProcurement('PRC-ALPHA', ['property_name' => '三番町ハイツ']);
        // 物件名だけに '道後' を含む（案件番号・所在地には無い）
        $this->makeProcurement('PRC-002',   ['property_name' => '道後温泉ビル']);
        // PJ番号だけに 'BETA' を含む
        $this->makeProject('PJ-BETA', ['project_name' => '六軒家町分譲地']);
        // PJ名だけに '星岡' を含む
        $this->makeProject('PJ-002',  ['project_name' => '星岡ガーデン']);

        $this->assertSame(['三番町ハイツ'],   $this->namesOf($this->paginateVia('keyword=ALPHA')));
        $this->assertSame(['道後温泉ビル'],   $this->namesOf($this->paginateVia('keyword=' . urlencode('道後'))));
        $this->assertSame(['六軒家町分譲地'], $this->namesOf($this->paginateVia('keyword=BETA')));
        $this->assertSame(['星岡ガーデン'],   $this->namesOf($this->paginateVia('keyword=' . urlencode('星岡'))));
    }

    /**
     * 日本語キーワードで所在地を横断検索できる（本番の検索対象は日本語）。
     *
     * ⚠ `Request::create()` にエンコードしていない日本語をそのまま渡すと値が文字化けし
     *   （実測: '松山' → "\xef\xbf\xbd_\xef\xbf\xbd山"）、ヒット 0 件になって
     *   「実装が悪い」と誤診する。**必ず urlencode() を通すこと。**
     */
    public function test_keyword_matches_japanese_address_in_both_tables(): void
    {
        $this->makeProcurement('PRC-001', ['address' => '愛媛県松山市水泥町1-1']);
        $this->makeProject('PJ-001',      ['address' => '愛媛県松山市水泥町2-2']);
        $this->makeProcurement('PRC-002', ['address' => '愛媛県今治市別宮町3-3']);

        $this->assertEqualsCanonicalizing(
            ['物件PRC-001', '分譲地PJ-001'],
            $this->namesOf($this->paginateVia('keyword=' . urlencode('水泥町')))
        );
    }
```

- [ ] **Step 2: テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: PASS（20 tests）。
落ちた場合は `procurementQuery()` / `projectQuery()` / `applyKeyword()` の該当分岐を直す
（Task 2 の実装で通る想定だが、通らなければ**テストではなく実装を直す**）。

- [ ] **Step 3: コミット**

```bash
git add tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
git commit -m "test(realestate): 一覧の物件種別・取引種別・キーワードフィルタを回帰テストで固定"
```

---

## Task 4: コントローラ差し替え + Blade テーブル本体 + ステータスセルの多型化

> ⚠ **`<script>` の書き換えをこのタスクから切り離さないこと。** Step 4 で Blade の
> `@php` ブロックから `$statusOptions` を消すため、`<script>` の `@json($statusOptions)` を
> 同じタスクで置き換えないと未定義変数で 500 になる。

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php:22-66`（`index()`）
- Modify: `resources/views/realestate/procurements/index.blade.php`
- Modify: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`

- [ ] **Step 1: 画面描画の失敗テストを書く**

クラス末尾に追記:

```php
    // ================================================================
    // Task 4: 画面描画
    // ================================================================

    /** 一覧に両種別が並ぶ */
    public function test_index_renders_both_types(): void
    {
        $this->makeProcurement('PRC-001');
        $this->makeProject('PJ-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('物件PRC-001');
        $response->assertSee('分譲地PJ-001');
        $this->assertSame(2, $response->viewData('rows')->total());

        // 分譲地の物件種別は素のテキスト「分譲地」。
        // ⚠ assertSee('分譲地') は PJ 名にも一致して false-pass するので DTO の値で見る
        $this->assertSame(
            ['分譲地'],
            collect($response->viewData('rows')->items())
                ->where('kind', 'project')->pluck('propertyTypeLabel')->all()
        );
    }

    /** 分譲地の行には物件名の下に「区画 成約数 / 総数」が出る */
    public function test_project_row_shows_lot_counts(): void
    {
        $pj = $this->makeProject('PJ-001');
        $this->makeLot($pj, 1, 'sold');
        $this->makeLot($pj, 2, 'sold');
        $this->makeLot($pj, 3, 'on_sale');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 意匠（inline style）の変更に耐えるよう data 属性 + 数値で見る
        $this->assertMatchesRegularExpression(
            '/data-lot-count[^>]*>\s*区画\s*<span[^>]*>2<\/span>\s*\/\s*3/u',
            $response->getContent()
        );
    }

    /** 区画 0 件の分譲地でも「区画 0 / 0」と区画ボタンを出す */
    public function test_project_with_zero_lots_shows_zero_and_lots_button(): void
    {
        $pj = $this->makeProject('PJ-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/data-lot-count[^>]*>\s*区画\s*<span[^>]*>0<\/span>\s*\/\s*0/u',
            $response->getContent()
        );
        $response->assertSee("/realestate/projects/{$pj->id}/lots", false);
    }

    /** 仕入れ案件の行には区画サブ行の要素自体を出さない */
    public function test_procurement_row_has_no_lot_subline(): void
    {
        $this->makeProcurement('PRC-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('物件PRC-001');
        $response->assertDontSee('data-lot-count', false);
    }

    /** 0 件のときは colspan=10 で「該当するデータがありません。」 */
    public function test_empty_list_shows_updated_message(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('colspan="10"', false);
        $response->assertSee('該当するデータがありません。');
    }

    /** 分譲地のステータスバッジ CSS が同梱されている（忘れると無色で描画される） */
    public function test_project_badge_css_is_present(): void
    {
        $this->makeProject('PJ-001', ['status' => 'selling']);

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('badge-prj-selling', false);
        $response->assertSee('.badge-prj-selling {', false);
    }

    /**
     * ステータスセルの「呼び出し側（属性）」と「定義側（<script>）」が対で存在する。
     *
     * ⚠ Bug #28 の教訓: 属性だけ見ると定義が無くても緑になる。必ず対で検証する。
     */
    public function test_status_cell_caller_and_definition_are_both_present(): void
    {
        $this->makeProcurement('PRC-001');
        $this->makeProject('PJ-001');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 呼び出し側 — 種別ごとに別の kind が渡る
        $response->assertSee("realestateStatusCell('procurement'", false);
        $response->assertSee("realestateStatusCell('project'", false);
        // 定義側
        $response->assertSee('function realestateStatusCell(', false);
        // 種別ごとの選択肢マップと更新先エンドポイント
        $response->assertSee('__reStatusOptions', false);
        $response->assertSee('__reStatusEndpoint', false);
        $response->assertSee('/realestate/projects', false);
        // 旧関数名が残っていない
        $response->assertDontSee('procurementStatusCell', false);
    }
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: FAIL — `rows` が view data に無い / `data-lot-count` が無い / `colspan="9"` のまま

- [ ] **Step 3: コントローラの `index()` を差し替える**

`app/Http/Controllers/RealEstate/ProcurementController.php` の `use` に追加:

```php
use App\Enums\ProjectStatus;
use App\Services\RealEstate\ProcurementListRow;
use App\Services\RealEstate\ProcurementListService;
```

`index()`（22〜66 行目）を丸ごと以下で置き換える:

```php
    /**
     * 仕入れ案件一覧（分譲地を統合した不動産案件の横断ビュー）
     * Route: GET /realestate/procurements
     */
    public function index(Request $request, ProcurementListService $listService)
    {
        $rows = $listService->paginate($request);

        // ステータスポップオーバー用の選択肢を種別ごとに組む。
        // ⚠ 配列リテラルを Blade の @json() へ直接書かず、ここで組んで変数 1 本で渡す
        //   （多行の配列リテラル + メソッド呼び出しは Blade の引数パーサを壊す。Bug #26）
        $statusOptionsByKind = [
            ProcurementListRow::KIND_PROCUREMENT => $this->statusOptions(ProcurementStatus::cases()),
            ProcurementListRow::KIND_PROJECT     => $this->statusOptions(ProjectStatus::cases()),
        ];

        return view('realestate.procurements.index', compact('rows', 'statusOptionsByKind'));
    }

    /**
     * ステータス enum を一覧のポップオーバー用配列へ整形する
     *
     * @param  array<int, ProcurementStatus|ProjectStatus>  $cases
     * @return array<int, array{value: string, label: string, badge_class: string}>
     */
    private function statusOptions(array $cases): array
    {
        return array_map(fn ($case) => [
            'value'       => $case->value,
            'label'       => $case->label(),
            'badge_class' => $case->badgeClass(),
        ], $cases);
    }
```

- [ ] **Step 4: Blade の `@php` ブロックからステータス選択肢を除く**

`resources/views/realestate/procurements/index.blade.php` の 14〜24 行目を以下で置き換える:

```blade
    @php
        // ステータス選択肢は種別ごとにコントローラで組んで $statusOptionsByKind で受け取る
        $canEditStatus = auth()->user()->role->isManagerOrAbove();
    @endphp
```

- [ ] **Step 5: テーブルの `min-width` と見出しに「区画」列を足す**

80 行目の `style="min-width: 1000px;"` を `style="min-width: 1080px;"` に変更する。

90 行目「マップ」の `<th>` と 91 行目「詳細」の `<th>` の**間**に 1 行挿入する:

```blade
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">区画</th>
```

- [ ] **Step 6: `<tbody>` を丸ごと置き換える**

94〜161 行目（`<tbody>` 〜 `</tbody>`）を以下で置き換える:

```blade
                <tbody>
                    @forelse($rows as $row)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-3 border-b border-gray-100 text-sm font-medium whitespace-nowrap" style="padding-left: 16px;">
                                {{ $row->name }}
                                @if($row->isProject())
                                    {{-- 区画数は専用列を設けず物件名の下にインライン表示。区画0件でも出す --}}
                                    <div data-lot-count style="font-size: 11px; color: #6b7280; font-weight: 400; margin-top: 3px;">区画 <span style="color: #047857; font-weight: 700;">{{ $row->soldLotCount }}</span> / {{ $row->lotCount }}</div>
                                @endif
                            </td>
                            @if($canEditStatus)
                                <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap"
                                    x-data="realestateStatusCell('{{ $row->kind }}', {{ $row->id }}, '{{ $row->status->value }}', '{{ $row->status->label() }}', '{{ $row->status->badgeClass() }}')">
                                    <span @click="toggle($event)" :class="'badge ' + badgeClass" x-text="label"
                                          style="cursor: pointer;" title="クリックでステータス変更"></span>
                                    <div x-show="open" x-cloak @click.outside="open = false"
                                         :style="'position: fixed; top: ' + popoverTop + 'px; left: ' + popoverLeft + 'px; transform: translateX(-50%); z-index: 9999; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 130px; display: flex; flex-direction: column; gap: 4px;'">
                                        <template x-for="opt in options" :key="opt.value">
                                            <span @click="select(opt)" :class="'badge ' + opt.badge_class" x-text="opt.label"
                                                  :style="(opt.value === value) ? 'opacity: 0.45; cursor: default; text-align: center;' : 'cursor: pointer; text-align: center;'"></span>
                                        </template>
                                    </div>
                                </td>
                            @else
                                <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                    <span class="badge {{ $row->status->badgeClass() }}">{{ $row->status->label() }}</span>
                                </td>
                            @endif
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $row->propertyTypeLabel }}</td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">
                                @if($row->transactionTypeLabel !== null)
                                    {{ $row->transactionTypeLabel }}
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($row->purchasePrice)
                                    {{ number_format($row->purchasePrice) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($row->targetSellingPrice)
                                    {{ number_format($row->targetSellingPrice) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($row->expectedProfit !== null)
                                    <span class="text-emerald-600 font-semibold">{{ number_format($row->expectedProfit) }}円</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            {{-- マップボタン（青）--}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if($row->latitude && $row->longitude)
                                    <button type="button" onclick="openMapModal({{ \Illuminate\Support\Js::from($row->name) }}, {{ \Illuminate\Support\Js::from($row->address) }}, {{ $row->latitude }}, {{ $row->longitude }})"
                                            style="background: #fff; color: #2563eb; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; border: 1px solid #2563eb; cursor: pointer; white-space: nowrap;">マップ</button>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            {{-- 区画ボタン（緑・分譲地のみ。区画0件でも表示＝そこから登録するため）--}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if($row->lotsUrl !== null)
                                    <a href="{{ $row->lotsUrl }}"
                                       style="display: inline-block; background: #fff; color: #059669; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; border: 1px solid #059669; text-decoration: none; white-space: nowrap;">区画</a>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            {{-- 詳細ボタン（全行とも緑に統一）--}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ $row->showUrl }}"
                                   class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-5 py-10 text-center text-sm text-gray-400">該当するデータがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
```

- [ ] **Step 7: 件数表示とページネーションの変数名を `$rows` に直す**

165〜187 行目の `$procurements` を **すべて** `$rows` に置き換える。置き換え後はこうなる:

```blade
        <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">全 {{ $rows->total() }} 件</div>

        @if($rows->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($rows->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $rows->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                @endif
                @foreach($rows->getUrlRange(1, $rows->lastPage()) as $page => $url)
                    @if($page == $rows->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach
                @if($rows->hasMorePages())
                    <a href="{{ $rows->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
```

置き残しが無いことを確認する:

```bash
grep -n 'procurements\->\|\$procurements' resources/views/realestate/procurements/index.blade.php
```

Expected: 出力なし

- [ ] **Step 8: `badge-prj-*` CSS を追加する**

`<style>` ブロック（191〜201 行目付近）の `badge-re-lost` の次の行に追加する:

```css
/* 分譲地ステータスバッジ（realestate/projects/index.blade.php と同じ定義）*/
.badge-prj-info { background: #dbeafe; color: #1e40af; }
.badge-prj-assess { background: #fce7f3; color: #9d174d; }
.badge-prj-negotiate { background: #fed7aa; color: #9a3412; }
.badge-prj-contracted { background: #fef3c7; color: #92400e; }
.badge-prj-settled { background: #a7f3d0; color: #064e3b; }
.badge-prj-selling { background: #c7d2fe; color: #3730a3; }
.badge-prj-soldout { background: #86efac; color: #14532d; }
.badge-prj-lost { background: #e5e7eb; color: #374151; }
```

- [ ] **Step 9: `<script>` のステータスセルを多型化する**

⚠ Step 4 で `$statusOptions` を消しているので、このステップを飛ばすと未定義変数で 500 になる。

218〜222 行目の以下 3 行:

```blade
<script>
// ステータスポップオーバー: バッジクリックで全ステータスをバッジ風に表示し、選択で Ajax 即更新
window.__procurementStatusOptions = @json($statusOptions);

function procurementStatusCell(id, initialValue, initialLabel, initialBadgeClass) {
```

を以下で置き換える:

```blade
<script>
// ステータスポップオーバー: バッジクリックで全ステータスをバッジ風に表示し、選択で Ajax 即更新。
// 選択肢と更新先エンドポイントは種別（procurement / project）ごとに引く。
// ⚠ @json は <script> の中だけ。x-data 属性に入れると Alpine が初期化されない（Bug #23）
window.__reStatusOptions = @json($statusOptionsByKind);
window.__reStatusEndpoint = {
    procurement: '{{ url("/realestate/procurements") }}',
    project: '{{ url("/realestate/projects") }}'
};

function realestateStatusCell(kind, id, initialValue, initialLabel, initialBadgeClass) {
```

続けて、返すオブジェクトの先頭に `kind` を足し、`options` を種別引きに変える。
223〜233 行目の:

```js
    return {
        id: id,
        value: initialValue,
        label: initialLabel,
        badgeClass: initialBadgeClass,
        open: false,
        submitting: false,
        // ポップオーバーは position:fixed で viewport 基準描画（親コンテナ overflow-hidden 回避）
        popoverTop: 0,
        popoverLeft: 0,
        options: window.__procurementStatusOptions || [],
```

を以下で置き換える:

```js
    return {
        kind: kind,
        id: id,
        value: initialValue,
        label: initialLabel,
        badgeClass: initialBadgeClass,
        open: false,
        submitting: false,
        // ポップオーバーは position:fixed で viewport 基準描画（親コンテナ overflow-hidden 回避）
        popoverTop: 0,
        popoverLeft: 0,
        options: (window.__reStatusOptions || {})[kind] || [],
```

最後に `select()` の fetch 先を種別で切り替える。256 行目の:

```js
            fetch('{{ url("/realestate/procurements") }}/' + self.id + '/status', {
```

を以下で置き換える:

```js
            var endpoint = (window.__reStatusEndpoint || {})[self.kind];

            fetch(endpoint + '/' + self.id + '/status', {
```

> 両エンドポイントとも `{success, status: {value, label, badge_class}}` の同一 JSON 形状で、
> ミドルウェアも `role:executive,manager` で対称であることを確認済み
> （`ProcurementController::updateStatus` / `ProjectController::updateStatus`）。
> `toggle()` と `then()` 以降のロジックは一切変更しない。

- [ ] **Step 10: 旧識別子が残っていないことを確認する**

```bash
grep -n 'procurementStatusCell\|__procurementStatusOptions\|\$statusOptions\b\|\$procurements' resources/views/realestate/procurements/index.blade.php
```

Expected: 出力なし

- [ ] **Step 11: テストが通ることを確認する（既存テストの回帰も見る）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter 'ProcurementListWithProjectsTest|ProcurementStatusTransitionTest'
```

Expected: PASS（`ProcurementListWithProjectsTest` は 27 本）

- [ ] **Step 12: コミット**

```bash
git add app/Http/Controllers/RealEstate/ProcurementController.php resources/views/realestate/procurements/index.blade.php tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
git commit -m "feat(realestate): 仕入れ案件一覧に分譲地の行を表示する"
```

---

## Task 5: フィルタバー（分譲地の選択肢・現地調査の補記）

**Files:**
- Modify: `resources/views/realestate/procurements/index.blade.php:42-60`
- Modify: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`

- [ ] **Step 1: 失敗テストを書く**

クラス末尾に追記:

```php
    // ================================================================
    // Task 5: フィルタバー
    // ================================================================

    /** 物件種別セレクトの「全て」直下＝実種別の先頭に「分譲地」がある */
    public function test_property_type_select_has_project_option_right_after_all(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // ⚠ assertSee('分譲地') は他所にも一致して false-pass するので option の生 HTML で見る
        //   （Blade コメントはコンパイル時に消え、間に残るのは空白のみ）
        $this->assertMatchesRegularExpression(
            '/<option value="">物件種別: 全て<\/option>\s*<option value="project"[^>]*>分譲地<\/option>/u',
            $response->getContent()
        );
    }

    /** 「分譲地」を選択したら selected が付く */
    public function test_property_type_project_option_is_marked_selected(): void
    {
        $response = $this->actingAs($this->executive())
            ->get('/realestate/procurements?property_type=project');

        $response->assertOk();
        $response->assertSee('<option value="project" selected>分譲地</option>', false);
    }

    /** 「現地調査」は分譲地に無いステータスなので選択肢に補記が付く */
    public function test_site_survey_option_is_annotated(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('現地調査（仕入れ案件のみ）');
    }

    /** 分譲地の選択肢には分譲地のラベルが入る（仕入れ案件のラベルを流用しない） */
    public function test_status_options_are_per_kind(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $options = $response->viewData('statusOptionsByKind');

        $this->assertSame(
            ['badge-re-survey'],
            collect($options['procurement'])->where('value', 'site_survey')->pluck('badge_class')->all()
        );
        // 分譲地に site_survey は無い
        $this->assertCount(0, collect($options['project'])->where('value', 'site_survey'));
        // 「販売済」は分譲地では sold_out
        $this->assertSame(
            ['販売済'],
            collect($options['project'])->where('value', 'sold_out')->pluck('label')->all()
        );
    }
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: FAIL — `<option value="project"` が無い / 「現地調査（仕入れ案件のみ）」が無い

- [ ] **Step 3: ステータスセレクトに「現地調査」の補記を入れる**

`resources/views/realestate/procurements/index.blade.php` の 50〜52 行目（`@foreach(\App\Enums\ProcurementStatus::cases() as $st)` のブロック）を置き換える:

```blade
            @foreach(\App\Enums\ProcurementStatus::cases() as $st)
                @php
                    // 「現地調査」は分譲地に存在しないステータス。選ぶと分譲地が結果から外れる旨を補記する
                    $stLabel = $st === \App\Enums\ProcurementStatus::SiteSurvey
                        ? $st->label() . '（仕入れ案件のみ）'
                        : $st->label();
                @endphp
                <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $stLabel }}</option>
            @endforeach
```

- [ ] **Step 4: 物件種別セレクトに「分譲地」を追加する**

54〜60 行目の `property_type` セレクトを置き換える:

```blade
        <select name="property_type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">物件種別: 全て</option>
            {{-- 「分譲地」は一覧フィルタ専用の擬似値。RealEstatePropertyType enum には追加しない
                 （追加すると仕入れ案件の登録フォームの選択肢にも出て、実体のないデータが作れてしまう）--}}
            <option value="project" {{ request('property_type') === 'project' ? 'selected' : '' }}>分譲地</option>
            @foreach(\App\Enums\RealEstatePropertyType::cases() as $pt)
                <option value="{{ $pt->value }}" {{ request('property_type') === $pt->value ? 'selected' : '' }}>{{ $pt->label() }}</option>
            @endforeach
        </select>
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter 'ProcurementListWithProjectsTest|ProcurementStatusTransitionTest'
```

Expected: PASS（`ProcurementListWithProjectsTest` は 31 本）。
⚠ 既存の `ProcurementStatusTransitionTest::test_index_status_all_marks_all_option_selected` は
`<option value="" selected>` を assertSee / `<option value="active" selected>` を assertDontSee する。
新設した `<option value="project">` は selected を持たないので影響しないが、必ず一緒に走らせて確認する。

- [ ] **Step 6: コミット**

```bash
git add resources/views/realestate/procurements/index.blade.php tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
git commit -m "feat(realestate): 一覧の物件種別フィルタに分譲地を追加する"
```

---

## Task 6: ページネーション（両種別合算 + フィルタ保持）

**Files:**
- Modify: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`
- Modify（テストが落ちた場合のみ）: `app/Services/RealEstate/ProcurementListService.php`

- [ ] **Step 1: 失敗テストを書く**

クラス末尾に追記:

```php
    // ================================================================
    // Task 6: ページネーション
    // ================================================================

    /** 21 件（両種別混在）で 2 ページに割れ、1 ページ目 20 件・2 ページ目 1 件 */
    public function test_pagination_spans_both_types(): void
    {
        // 情報入手日をずらして順序を確定させる（同着タイブレークに依存しない）
        for ($i = 1; $i <= 11; $i++) {
            $this->makeProcurement(sprintf('PRC-%03d', $i), [
                'info_obtained_date' => sprintf('2026-06-%02d', $i),
            ]);
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get('/realestate/procurements');
        $page1->assertOk();
        $this->assertSame(21, $page1->viewData('rows')->total());
        $this->assertCount(20, $page1->viewData('rows')->items());

        $page2 = $this->actingAs($user)->get('/realestate/procurements?page=2');
        $page2->assertOk();
        $this->assertCount(1, $page2->viewData('rows')->items());
        // 最も古い＝ PRC-001（2026-06-01）が最後のページに来る
        $this->assertSame('物件PRC-001', $page2->viewData('rows')->items()[0]->name);
    }

    /** ページ送りリンクに絞り込みクエリが載る */
    public function test_pagination_keeps_filters(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }
        // ⚠ 仕入れ案件も 1 件混ぜる。分譲地だけだと「property_type=project の絞り込みで
        //   仕入れ案件が漏れないこと」を同時に検証できない（データが無いので当然 0 件になる）
        $this->makeProcurement('PRC-001', ['info_obtained_date' => '2026-07-31']);

        $response = $this->actingAs($this->executive())
            ->get('/realestate/procurements?property_type=project&keyword=PJ');

        $response->assertOk();
        $rows = $response->viewData('rows');

        // 絞り込みが効いていること（仕入れ案件が混ざらない）
        $this->assertSame(21, $rows->total());
        $this->assertNotContains('物件PRC-001', collect($rows->items())->pluck('name')->all());

        parse_str(parse_url($rows->url(2), PHP_URL_QUERY) ?? '', $q);

        $this->assertSame('project', $q['property_type']);
        $this->assertSame('PJ', $q['keyword']);
        $this->assertSame('2', $q['page']);
    }

    /**
     * 「ステータス: 全て」がページ送りで保持される。
     *
     * ⚠ ConvertEmptyStringsToNull で ?status= は null になり、そのまま
     *   LengthAwarePaginator へ渡すと http_build_query が null のキーを捨てる。
     *   すると 2 ページ目で既定の「進行中のみ」に戻り、終了状態の行が消える。
     */
    public function test_pagination_keeps_status_all_filter(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProject(sprintf('PJ-%03d', $i), [
                'status'             => 'sold_out',
                'info_obtained_date' => sprintf('2026-07-%02d', $i),
            ]);
        }

        $response = $this->actingAs($this->executive())
            ->get('/realestate/procurements?status=');

        $response->assertOk();
        $this->assertSame(21, $response->viewData('rows')->total());

        parse_str(parse_url($response->viewData('rows')->url(2), PHP_URL_QUERY) ?? '', $q);

        $this->assertArrayHasKey('status', $q);
        $this->assertSame('', $q['status']);
        $this->assertSame('2', $q['page']);
    }
```

- [ ] **Step 2: テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter ProcurementListWithProjectsTest
```

Expected: PASS（34 本）。Task 2 の `paginationQuery()` が効いていれば通る。
`test_pagination_keeps_status_all_filter` が落ちる場合は `paginationQuery()` の
`array_map(fn ($v) => $v ?? '', ...)` が入っているか確認する。

- [ ] **Step 3: `paginationQuery()` の null 正規化を外すと落ちることを確認する（罠が生きている証明）**

`paginationQuery()` の本体を一時的に `return $request->query();` に変えて:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit --filter test_pagination_keeps_status_all_filter
```

Expected: FAIL — `Failed asserting that an array has the key 'status'`
確認できたら **必ず元に戻して** 再度 PASS を確認する。

- [ ] **Step 4: コミット**

```bash
git add tests/Feature/RealEstate/ProcurementListWithProjectsTest.php
git commit -m "test(realestate): 一覧のページング（両種別合算・フィルタ保持）を回帰テストで固定"
```

---

## Task 7: 全体検証

コード変更はしない（問題が見つかったら直す）。

- [ ] **Step 1: 全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects && vendor/bin/phpunit
```

Expected: 全 PASS、Failures: 0 / Errors: 0。
特に以下の既存テストが緑のままであることを確認する（一覧の Blade を書き換えたため）:

- `ProcurementStatusTransitionTest::test_index_default_filter_excludes_sold`
- `ProcurementStatusTransitionTest::test_index_status_sold_shows_only_sold`
- `ProcurementStatusTransitionTest::test_index_status_all_shows_every_status`
- `ProcurementStatusTransitionTest::test_index_status_all_marks_all_option_selected`
- `ProcurementStatusTransitionTest::test_index_default_marks_active_option_selected`
- `ProjectSoldStatusTransitionTest`（分譲地一覧ページは無変更なので全件）

- [ ] **Step 2: コンパイル済みビューを `php -l` する（Bug #26 対策）**

⚠ **`view:cache` の「成功」表示だけでは不十分**（コンパイル済み PHP を lint しないため）。
必ず生成物を lint する。まず worktree で（速い）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-list-projects
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない。

通ったら main repo へ FF-merge して、本番と同じツリーでもう一度 lint する:

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only feature/procurement-list-projects
cd /Users/masanori/site/manage && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

> ⚠ `git merge --ff-only` が失敗する場合は 13.x が先行している。その場合は worktree で
> `git rebase 13.x` してから再度 merge する。

- [ ] **Step 3: 横展開検査（この変更で新たに罠を持ち込んでいないか）**

```bash
cd /Users/masanori/site/manage

# Bug #23: x-data 属性の中に @json が無いこと
grep -rn -A3 'x-data="' resources/views/realestate/procurements/index.blade.php | grep '@json' || echo "OK: x-data 内 @json なし"

# Bug #26: @json に配列リテラルを渡していないこと
grep -n '@json(' resources/views/realestate/procurements/index.blade.php

# Bug #22: キャスト済み属性への tryFrom 誤用が無いこと（サービスの1箇所は生文字列で正用法）
grep -rn '::tryFrom(\$' app/Services/RealEstate/

# enum を汚していないこと
grep -n 'Project' app/Enums/RealEstatePropertyType.php || echo "OK: enum に Project ケースなし"

# ->merge( の左辺が base 化されていること（Bug #27）
grep -rn '\->merge(' app/Services/RealEstate/
```

Expected:
- `x-data` 内 `@json` は無し
- `@json(` は `@json($statusOptionsByKind)` の 1 行のみ（配列リテラルを含まない）
- `tryFrom` は `mapStatusForProject` の 1 箇所のみ（引数はリクエストの生文字列）
- `RealEstatePropertyType.php` に `Project` ケース無し
- `->merge(` は `$procKeys->merge($projKeys)`（両辺とも `keysFrom()` 経由で base 化済み）

- [ ] **Step 4: 本番へ反映する**

⚠ **`./deploy.sh` はユーザーの明示承認が必要**（自動モードの分類器がブロックする）。
承認を得てから実行すること。

新規 PHP クラスを 2 本追加したので、**main repo の cwd で** `composer dump-autoload` を先に実行する
（worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれ、
main repo の Apache が worktree を参照する事故になる）:

```bash
cd /Users/masanori/site/manage && composer dump-autoload
```

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

- [ ] **Step 5: 本番ブラウザで確認する**

`https://www.mitsuwat.co.jp/system/manage/realestate/procurements` を開いて確認する:

1. 分譲地の行が仕入れ案件と混ざって出ている（情報入手日の降順）
2. 分譲地のステータスバッジに**色が付いている**（無色なら `badge-prj-*` CSS の漏れ）
3. 分譲地の物件名の下に「区画 n / m」が出ている。成約数が緑・太字
4. 分譲地のバッジを**実際にクリックして**ステータスが変わる
   （⚠ HTML に出ているかだけでは不十分。Bug #28 と同型で、スクリプトが一度も
   実行されていないケースを取り逃す）
5. 仕入れ案件のバッジクリックが従来どおり動く（エンドポイント切り替えの回帰）
6. 物件種別セレクトで「分譲地」→ 分譲地だけになる
7. ステータス「全て」でページ送り → 2 ページ目でも終了状態の行が残っている
8. 横スクロールが出ていないことを **DOM で実測**する（Bug #29）:

```js
// ブラウザのコンソールで実行。広い幅・狭い幅の両方で測ること
var m = document.querySelector('main');
console.log(m.scrollWidth, m.clientWidth, m.scrollWidth === m.clientWidth);
```

Expected: `true`。
⚠ `false` の場合は Bug #29 と同型。`min-width: 1080px` を持つ `<table>` は
`overflow-x: auto` の `<div>` に包まれているので本来は出ないが、
**スクリーンショットでは判定できない**ので必ず DOM で測る。

---

## 変更しないもの（設計書 §7 の再掲。触ったら差し戻し）

| 対象 | 理由 |
|---|---|
| `/realestate/projects`（分譲地一覧ページ） | 分譲地だけを見たいときの導線。フィルタも独自 |
| サイドバーの「分譲地」項目 | 同上 |
| 分譲地一覧の詳細ボタンの色（琥珀） | その画面の意匠は既存のまま |
| `RealEstatePropertyType` enum | 登録フォームを汚染するため |
| `ProjectController` / `ReProject` / `ReProcurement` | 既存メソッドで足りる |
| ステータス更新 API（両方） | 形状も権限も既に対称 |
| 新規登録ボタン | 現状のまま（仕入れ案件の登録へ） |
| DB スキーマ | 追加カラム不要 |

---

## 設計書とのカバレッジ対応

| 設計書 | 実装タスク |
|---|---|
| §3.1 列定義（10 列） | Task 4 Step 5–6 |
| §3.2 区画サブ行 | Task 4 Step 6（`data-lot-count`）+ テスト 3 本 |
| §3.3 物件種別セレクト（擬似値 `project`） | Task 5 Step 4 + Task 3 テスト |
| §3.3 ステータスセレクト（マッピング表 11 行） | Task 2 `mapStatusForProject()` + テスト 4 本 |
| §3.3 「現地調査（仕入れ案件のみ）」 | Task 5 Step 3 |
| §3.3 取引種別 | Task 2 `projectQuery()` + Task 3 テスト |
| §3.3 キーワード | Task 2 `applyKeyword()` + Task 3 テスト |
| §3.4 並び順（NULL 末尾・種別タイブレーク） | Task 2 `sortedKeys()` + テスト |
| §3.5 件数・ページネーション・フィルタ保持 | Task 4 Step 7 + Task 6 |
| §3.6 空表示（colspan 10・文言変更） | Task 4 Step 6 + テスト |
| §4.2 DTO | Task 1 |
| §4.3 サービス（2 段構え・`toBase()`） | Task 2 |
| §4.4 コントローラ | Task 4 Step 3 |
| §4.5 ステータスセル多型化 | Task 4 Step 9（⚠ Step 4 で `$statusOptions` を消すので同一タスク内で行う） |
| §4.6 `badge-prj-*` CSS | Task 4 Step 8 |
| §5 認可（追加不要） | 変更なし。既存ミドルウェア構成のまま |
| §6.1 空ケース 3 本 | Task 2 |
| §6.2 フィルタ 8 本 | Task 2（4 本）+ Task 3（4 本） |
| §6.3 表示・ページング 6 本 | Task 4（4 本）+ Task 6（2 本） |
| §8 デプロイ | Task 7 Step 4–5 |

### テスト本数の積み上げ（各タスク終了時点の `ProcurementListWithProjectsTest`）

| タスク | 追加 | 累計 |
|---|---:|---:|
| Task 1 DTO | 3 | 3 |
| Task 2 サービス（マージ/ソート/ステータス） | 8 | 11 |
| Task 2 レビュー修正（配列ステータス 2 + 同着順序 1） | 3 | 14 |
| Task 3 フィルタ（種別/取引/キーワード/日本語） | 5 | 19 |
| Task 3 レビュー修正（カラム個別のキーワード検索 1） | 1 | 20 |
| Task 4 描画 + ステータスセル | 7 | 27 |
| Task 5 フィルタバー | 4 | 31 |
| Task 6 ページネーション | 3 | 34 |
