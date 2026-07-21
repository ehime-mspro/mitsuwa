# 仕入れ案件「販売済」ステータス追加 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 契約を登録したら仕入れ案件が自動的に「販売済」になり、一覧の既定表示・経営ダッシュボードの進行中パイプラインから外れる。データは残し、ステータス絞り込みと詳細画面の「契約情報」カードから辿れる。

**Architecture:** DB 変更ゼロ。`ProcurementStatus` enum に `Sold` case を足し（`re_procurements.status` は `varchar(20)` なので値を増やすだけで動く）、`ReContractController` の `store()`/`update()`/`destroy()` に、既存の分譲地区画ライフサイクルと**同じ形**の自動遷移を並べる。非表示は enum の `isClosed()` ではなくフィルタ側に明示的に書く。

**Tech Stack:** Laravel 12 / PHP 8.3 (CLI・本番とも) / Blade / PHPUnit 11.5 (SQLite in-memory)

**元設計書:** `docs/superpowers/specs/2026-07-21-procurement-sold-status-design.md`

---

## 前提: テスト環境（検証済み・再実行不要）

worktree に `vendor` を用意済み（`composer install` 実行済み、`vendor/bin/phpunit` 存在確認済み）。

**worktree には `.env` を置かない。** `phpunit.xml` が `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` を与えるので、
`APP_KEY` だけをインラインで渡せばブートする。`.env` を作ると実 MySQL 認証情報が入り込む事故の余地を作るため、作らない。

**テスト実行コマンド（本プラン内で「テスト実行」と書いたら常にこれ）:**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-sold-status
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit <path>
```

実証済み（2026-07-21）:
- `tests/Unit/ExampleTest.php` → `OK (1 test, 1 assertion)`
- `tests/Feature/DashboardControllerTest.php` → `OK (15 tests, 39 assertions)`

**⚠ `phpunit.xml` の `<env name="APP_URL" value="http://localhost"/>` を消さないこと。**
本番は `/system/manage` 配下だが、この行を消すとテストの `$this->get('/path')` が 404 になる。

---

## ファイル構成

| # | ファイル | 新規/変更 | 責務 |
|---|---|---|---|
| 1 | `app/Enums/ProcurementStatus.php` | 変更 | `Sold` case ＋ `label()` ＋ `badgeClass()` |
| 2 | `tests/Unit/RealEstate/ProcurementStatusTest.php` | 新規 | enum の値・ラベル・並び順・`isClosed()` を仕様として固定 |
| 3 | `tests/Concerns/CreatesRealEstateSchema.php` | 新規 | `re_*` / `buyers` は raw SQL 管理で migration が無いため、テスト用に実 DB 準拠の最小スキーマを構築 |
| 4 | `app/Http/Controllers/RealEstate/ReContractController.php` | 変更 | `store()`/`update()`/`destroy()` の自動遷移 3 点 |
| 5 | `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` | 新規 | 遷移 3 経路（T1〜T4）＋ 一覧フィルタ（T5・T6）＋ 詳細カード（T7） |
| 6 | `app/Http/Controllers/RealEstate/ProcurementController.php` | 変更 | `index()` の `active` 定義、`show()` の eager load |
| 7 | `resources/views/realestate/procurements/index.blade.php` | 変更 | 既定オプションのラベル、`badge-re-sold` CSS |
| 8 | `resources/views/realestate/procurements/show.blade.php` | 変更 | 「契約情報」カード、`badge-re-sold` CSS |
| 9 | `resources/views/realestate/suppliers/show.blade.php` | 変更 | `badge-re-sold` CSS のみ |
| 10 | `app/Http/Controllers/DashboardController.php` | 変更 | `aggregateProcurementStats()` から販売済を除外 |
| 11 | `app/Models/ReProcurement.php` | 変更 | `contracts()` リレーション |

DB マイグレーションは**無し**。本番データの一括 UPDATE のみ別途（Task 8）。

---

## Task 1: ProcurementStatus に Sold を追加

**Files:**
- Test: `tests/Unit/RealEstate/ProcurementStatusTest.php` (create)
- Modify: `app/Enums/ProcurementStatus.php:13-14`, `:25-26`, `:39-40`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/RealEstate/ProcurementStatusTest.php` を新規作成:

```php
<?php

namespace Tests\Unit\RealEstate;

use App\Enums\ProcurementStatus;
use PHPUnit\Framework\TestCase;

/**
 * 仕入れ案件ステータス enum の仕様を固定する（DB 非依存）。
 * 「販売済」は契約登録時に自動で入り、一覧の既定フィルタから外れる。
 */
class ProcurementStatusTest extends TestCase
{
    public function test_sold_case_exists_with_expected_value(): void
    {
        $this->assertSame('sold', ProcurementStatus::Sold->value);
    }

    public function test_sold_label(): void
    {
        $this->assertSame('販売済', ProcurementStatus::Sold->label());
    }

    public function test_sold_badge_class(): void
    {
        $this->assertSame('badge-re-sold', ProcurementStatus::Sold->badgeClass());
    }

    /**
     * enum の定義順がそのままフィルタセレクト・編集フォームの表示順になるため、
     * 「販売中 → 販売済 → 不成約」の並びを仕様として固定する。
     */
    public function test_cases_are_ordered_with_sold_between_selling_and_lost(): void
    {
        $values = array_column(ProcurementStatus::cases(), 'value');

        $this->assertSame([
            'info_obtained',
            'site_survey',
            'assessment',
            'negotiating',
            'contracted',
            'settled',
            'selling',
            'sold',
            'lost',
        ], $values);
    }

    /**
     * isClosed() は「不成約」だけを指す。販売済まで closed 扱いに広げると
     * 一覧の非表示判定が enum 側とフィルタ側で二重化し、解釈が割れるため意図的に false。
     */
    public function test_sold_is_not_closed(): void
    {
        $this->assertFalse(ProcurementStatus::Sold->isClosed());
        $this->assertTrue(ProcurementStatus::Lost->isClosed());
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Unit/RealEstate/ProcurementStatusTest.php
```

期待: FAIL。`Error: Undefined constant App\Enums\ProcurementStatus::Sold`

- [ ] **Step 3: enum に Sold を実装**

`app/Enums/ProcurementStatus.php` — case 定義（`Selling` の直後に挿入）:

```php
    case Selling      = 'selling';
    case Sold         = 'sold';
    case Lost         = 'lost';
```

`label()` の match 内（`self::Selling` の直後）:

```php
            self::Selling      => '販売中',
            self::Sold         => '販売済',
            self::Lost         => '不成約',
```

`badgeClass()` の match 内（`self::Selling` の直後）:

```php
            self::Selling      => 'badge-re-selling',
            self::Sold         => 'badge-re-sold',
            self::Lost         => 'badge-re-lost',
```

`isClosed()` は**変更しない**（`return $this === self::Lost;` のまま）。

- [ ] **Step 4: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Unit/RealEstate/ProcurementStatusTest.php
```

期待: `OK (5 tests, 6 assertions)`

- [ ] **Step 5: コミット**

```bash
git add app/Enums/ProcurementStatus.php tests/Unit/RealEstate/ProcurementStatusTest.php
git commit -m "feat(realestate): 仕入れ案件ステータスに販売済を追加"
```

---

## Task 2: テスト用スキーマ trait を用意

`re_procurements` / `re_contracts` / `re_suppliers` / `re_procurement_costs` / `re_cost_items` / `buyers` は
本番で raw SQL 管理されており `database/migrations/` に定義が無い。SQLite in-memory のテストで使うため、
実 DB（`php artisan db:table <table>` で実測済み）に準拠した最小スキーマを構築する。

**Files:**
- Create: `tests/Concerns/CreatesRealEstateSchema.php`
- Test: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` (create, スモークのみ)

- [ ] **Step 1: trait を作成**

`tests/Concerns/CreatesRealEstateSchema.php` を新規作成:

```php
<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * re_* テーブルと buyers は本番では raw SQL DDL で管理され、Laravel マイグレーションに無い。
 * テスト（SQLite in-memory）でこれらを使うため、実 DB に準拠した最小スキーマを構築する。
 *
 * - 列名・型・NULL 可否は `php artisan db:table <table>` の実測に合わせる。
 * - FK 制約は SQLite の挙動差・作成順依存を避けるため張らない（挙動テストには不要）。
 * - MySQL の enum 列（re_contracts.department）は SQLite に無いので string で代替する。
 *
 * 既存の CreatesZealSchema と同じ方式。
 */
trait CreatesRealEstateSchema
{
    protected function createRealEstateSchema(): void
    {
        Schema::create('buyers', function (Blueprint $t) {
            $t->id();
            $t->string('last_name', 50);
            $t->string('first_name', 50);
            $t->string('last_name_kana', 50)->nullable();
            $t->string('first_name_kana', 50)->nullable();
            $t->date('birth_date')->nullable();
            $t->string('birth_era', 10)->nullable();
            $t->unsignedTinyInteger('family_adults')->nullable();
            $t->unsignedTinyInteger('family_children')->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('prefecture', 10)->nullable();
            $t->string('city', 50)->nullable();
            $t->string('address_detail', 255)->nullable();
            $t->string('building_name', 255)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('occupation', 50)->nullable();
            $t->string('employer', 100)->nullable();
            $t->unsignedSmallInteger('years_employed')->nullable();
            $t->text('memo')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('re_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('supplier_code', 20);
            $t->string('type', 20);
            $t->string('name', 100);
            $t->string('contact_person', 50)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 100)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('re_cost_items', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50);
            $t->integer('sort_order');
            $t->boolean('is_active');
            $t->timestamps();
        });

        Schema::create('re_procurements', function (Blueprint $t) {
            $t->id();
            $t->string('procurement_code', 20)->unique();
            $t->string('property_type', 20);
            $t->string('transaction_type', 20);
            $t->string('status', 20);
            $t->string('property_name', 100);
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('land_area_sqm', 10, 2)->nullable();
            $t->decimal('building_area_sqm', 10, 2)->nullable();
            $t->string('structure', 50)->nullable();
            $t->string('built_year_month', 7)->nullable();
            $t->string('zoning', 50)->nullable();
            $t->decimal('building_coverage', 5, 2)->nullable();
            $t->decimal('floor_area_ratio', 5, 2)->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->date('info_obtained_date')->nullable();
            $t->integer('assessment_price')->nullable();
            $t->integer('purchase_price')->nullable();
            $t->integer('target_selling_price')->nullable();
            $t->date('contract_date')->nullable();
            $t->date('settlement_date')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('re_procurement_costs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('procurement_id');
            $t->unsignedBigInteger('cost_item_id');
            $t->integer('estimated_amount');
            $t->integer('actual_amount')->nullable();
            $t->string('notes', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('re_contracts', function (Blueprint $t) {
            $t->id();
            // 実 DB は enum('housing','realestate')。SQLite に enum は無いので string で代替。
            $t->string('department', 20);
            $t->string('contract_type', 30);
            $t->string('status', 20);
            $t->date('contract_date')->nullable();
            $t->string('property_name', 200);
            $t->string('address', 300)->nullable();
            $t->unsignedBigInteger('procurement_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('lot_id')->nullable();
            $t->unsignedBigInteger('buyer_id')->nullable();
            $t->string('buyer_name', 100)->nullable();
            $t->integer('contract_amount')->nullable();
            $t->integer('cost_amount')->nullable();
            $t->integer('gross_profit')->nullable();
            $t->integer('brokerage_selling_price')->nullable();
            $t->integer('brokerage_fee')->nullable();
            $t->unsignedBigInteger('staff_user_id')->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }
}
```

- [ ] **Step 2: スモークテストを書く**

`tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` を新規作成（この時点ではスモーク 1 件だけ。Task 3〜5 で追記していく）:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約の登録・更新・削除に連動した仕入れ案件ステータスの自動遷移と、
 * 一覧フィルタからの販売済除外を検証する。
 *
 * re_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementStatusTransitionTest extends TestCase
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
     * - 契約の削除（role:executive）まで到達できる
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * ⚠ 一覧画面が描画するのは procurement_code ではなく property_name（実測）。
     * フィルタのアサーションは property_name（= "物件{$code}"）で行うこと。
     */
    private function makeProcurement(string $code, string $status = 'selling'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code'  => $code,
            'property_type'     => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'  => RealEstateTransactionType::Purchase->value,
            'status'            => $status,
            'property_name'     => "物件{$code}",
            'address'           => '愛媛県松山市1-1-1',
            'created_by'        => 1,
        ]);
    }

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['last_name' => '山田', 'first_name' => '太郎']);
    }

    public function test_schema_is_built_and_models_are_persistable(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
        $this->assertSame('山田', $buyer->fresh()->last_name);
    }
}
```

- [ ] **Step 3: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: `OK (1 test, 2 assertions)`

失敗したら列名・型のズレなので、`cd /Users/masanori/site/manage && php artisan db:table <table>` で実 DB と突き合わせて trait を直す。

- [ ] **Step 4: コミット**

```bash
git add tests/Concerns/CreatesRealEstateSchema.php tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
git commit -m "test(realestate): re_* テーブルのテスト用スキーマ trait を追加"
```

---

## Task 3: 契約登録時に案件を販売済へ（store）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php:161-164`
- Test: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` (追記)

- [ ] **Step 1: 失敗するテスト T1・T2 を書く**

`ProcurementStatusTransitionTest` の `test_schema_is_built_and_models_are_persistable()` の**後ろ**に追記:

```php
    /** T1: 仕入れ販売契約を登録すると、その案件が販売済になる */
    public function test_storing_procurement_contract_marks_procurement_as_sold(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);
    }

    /** T2: 仲介契約（procurement_id を持たない）はどの案件のステータスも変えない */
    public function test_storing_brokerage_contract_leaves_procurements_untouched(): void
    {
        $procurement = $this->makeProcurement('P-001');

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'           => 'brokerage',
            'property_name'           => '仲介物件B',
            'brokerage_selling_price' => 20000000,
            'brokerage_fee'           => 660000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: T1 が FAIL（`Failed asserting that ProcurementStatus::Selling is identical to ProcurementStatus::Sold`）、T2 は PASS。

- [ ] **Step 3: store() に自動遷移を実装**

`ReContractController::store()` の分譲地区画ブロック（`// 分譲地の場合、区画ステータスを sold に変更` の 4 行）の**直後**に追加:

```php
        // 分譲地の場合、区画ステータスを sold に変更
        if ($contractType->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::Sold->value]);
        }

        // 仕入れ案件の場合、案件ステータスを販売済に変更
        // （クエリビルダ更新: $model->update() だと saved フックで
        //   syncPropertyPurchaseCost() が走るが、ステータス変更に原価の再同期は不要）
        if ($contractType->isProcurement() && $contract->procurement_id) {
            ReProcurement::where('id', $contract->procurement_id)
                ->update(['status' => ProcurementStatus::Sold->value]);
        }
```

`ReProcurement` と `ProcurementStatus` は同ファイル冒頭で import 済み（`:8`, `:13`）。追加 import 不要。

- [ ] **Step 4: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: `OK (3 tests, ...)`（assertion 数は実測値。テスト数 3 が合っていればよい）

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/RealEstate/ReContractController.php tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
git commit -m "feat(realestate): 契約登録時に仕入れ案件を販売済へ自動遷移"
```

---

## Task 4: 契約の付け替え・削除に追従（update / destroy）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php:294-304` (update), `:316-321` (destroy)
- Test: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` (追記)

- [ ] **Step 1: 失敗するテスト T3・T4 を書く**

`ProcurementStatusTransitionTest` に追記:

```php
    /** T3: 契約の案件を A→B に付け替えると、A が販売中に戻り B が販売済になる */
    public function test_updating_procurement_id_reverts_old_and_marks_new(): void
    {
        $procurementA = $this->makeProcurement('P-001');
        $procurementB = $this->makeProcurement('P-002');
        $buyer        = $this->makeBuyer();
        $executive    = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurementA->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->put("/realestate/contracts/{$contract->id}", [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurementB->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市B土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Selling, $procurementA->fresh()->status);
        $this->assertSame(ProcurementStatus::Sold, $procurementB->fresh()->status);
    }

    /** T4: 契約を削除すると案件が販売中に戻る（誤登録を消したとき行方不明にしない） */
    public function test_destroying_contract_reverts_procurement_to_selling(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();
        $executive   = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->delete("/realestate/contracts/{$contract->id}");

        $response->assertRedirect();
        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: T3・T4 が FAIL（どちらも案件 A / 案件が `Sold` のままで `Selling` に戻らない）。

- [ ] **Step 3: update() に付け替え追従を実装**

`ReContractController::update()` の分譲地ブロック（`// 分譲地: 区画変更の場合、…` の 10 行）の**直後**に追加:

```php
        // 仕入れ案件: 案件変更の場合、旧案件を販売中に戻して新案件を販売済に
        if ($contractType->isProcurement()) {
            $oldProcurementId = $contract->procurement_id;
            $newProcurementId = $validated['procurement_id'] ?? null;
            if ($oldProcurementId && $oldProcurementId != $newProcurementId) {
                ReProcurement::where('id', $oldProcurementId)
                    ->update(['status' => ProcurementStatus::Selling->value]);
            }
            if ($newProcurementId && $newProcurementId != $oldProcurementId) {
                ReProcurement::where('id', $newProcurementId)
                    ->update(['status' => ProcurementStatus::Sold->value]);
            }
        }
```

⚠ この位置は `$contract->update($validated);` の**前**でなければならない（`$contract->procurement_id` が旧値である必要がある）。

- [ ] **Step 4: destroy() に差し戻しを実装**

`ReContractController::destroy()` の分譲地ブロックの**直後**に追加:

```php
        // 分譲地の場合、区画ステータスを販売中に戻す
        if ($contract->contract_type->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::OnSale->value]);
        }

        // 仕入れ案件の場合、案件ステータスを販売中に戻す
        // （1 仕入れ案件 = 1 契約 の前提のため、他契約の有無は確認しない。区画側も同様）
        if ($contract->contract_type->isProcurement() && $contract->procurement_id) {
            ReProcurement::where('id', $contract->procurement_id)
                ->update(['status' => ProcurementStatus::Selling->value]);
        }
```

- [ ] **Step 5: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: `OK (5 tests, ...)`（assertion 数は実測値。テスト数 5 が合っていればよい）

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/RealEstate/ReContractController.php tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
git commit -m "feat(realestate): 契約の付け替え・削除に仕入れ案件ステータスを追従"
```

---

## Task 5: 一覧の既定フィルタから販売済を除外

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php:28-30`
- Modify: `resources/views/realestate/procurements/index.blade.php:44`, `:194-195`
- Modify: `resources/views/realestate/procurements/show.blade.php:401-402`
- Modify: `resources/views/realestate/suppliers/show.blade.php:123-124`
- Test: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` (追記)

- [ ] **Step 1: 失敗するテスト T5・T6 を書く**

`ProcurementStatusTransitionTest` に追記:

```php
    /** T5: 一覧の既定フィルタ（進行中のみ）に販売済は含まれない */
    public function test_index_default_filter_excludes_sold(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $sold    = $this->makeProcurement('P-002', 'sold');
        $lost    = $this->makeProcurement('P-003', 'lost');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 一覧が描画するのは property_name（procurement_code は出ない）
        $response->assertSee($selling->property_name);
        $response->assertDontSee($sold->property_name);
        $response->assertDontSee($lost->property_name);
    }

    /** T6: ?status=sold なら販売済だけが出る（enum に case を足した時点でセレクトに現れる） */
    public function test_index_status_sold_shows_only_sold(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $sold    = $this->makeProcurement('P-002', 'sold');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements?status=sold');

        $response->assertOk();
        $response->assertSee($sold->property_name);
        $response->assertDontSee($selling->property_name);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: T5 が FAIL（販売済の `P-002` が一覧に出てしまう）。T6 は PASS（`elseif` の素通し経路で既に動く）。

- [ ] **Step 3: index() のフィルタを実装**

`ProcurementController::index()` — `// フィルター: ステータス（デフォルトは不成約以外）` のブロックを置換:

```php
        // フィルター: ステータス（デフォルトは進行中のみ = 不成約・販売済を除く）
        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'active') {
            $query->whereNotIn('status', [
                ProcurementStatus::Lost->value,
                ProcurementStatus::Sold->value,
            ]);
        } elseif ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }
```

- [ ] **Step 4: 一覧の既定オプションのラベルを変更**

`resources/views/realestate/procurements/index.blade.php:44` — `不成約以外` → `進行中のみ`:

```blade
            <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>ステータス: 進行中のみ</option>
```

- [ ] **Step 5: バッジ CSS を 3 ファイルすべてに追加**

3 ファイルとも `<style>` ブロック内の同一 2 行が対象。`.badge-re-selling` と `.badge-re-lost` の**間**に挿入する。

対象:
- `resources/views/realestate/procurements/index.blade.php:194-195`
- `resources/views/realestate/procurements/show.blade.php:401-402`
- `resources/views/realestate/suppliers/show.blade.php:123-124`

変更前（3 ファイル共通）:

```css
.badge-re-selling { background: #c7d2fe; color: #3730a3; }
.badge-re-lost { background: #e5e7eb; color: #374151; }
```

変更後（3 ファイル共通）:

```css
.badge-re-selling { background: #c7d2fe; color: #3730a3; }
.badge-re-sold { background: #86efac; color: #14532d; }
.badge-re-lost { background: #e5e7eb; color: #374151; }
```

⚠ `suppliers/show.blade.php` は画面名から連想しにくいが、仕入れ先詳細に紐づく仕入れ案件のバッジ（`:103`）を描画するので**必須**。
これは Tailwind クラスではなくページ内 `<style>` の生 CSS なので、ビルドでは補われない。1 箇所でも漏らすとその画面だけバッジが無地になる。

- [ ] **Step 6: 3 ファイルに入ったことを数える**

```bash
grep -c "badge-re-sold" \
  resources/views/realestate/procurements/index.blade.php \
  resources/views/realestate/procurements/show.blade.php \
  resources/views/realestate/suppliers/show.blade.php
```

期待: 3 ファイルとも `:1`

- [ ] **Step 7: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: `OK (7 tests, ...)`（assertion 数は実測値。テスト数 7 が合っていればよい）

- [ ] **Step 8: コミット**

```bash
git add app/Http/Controllers/RealEstate/ProcurementController.php \
        resources/views/realestate/procurements/index.blade.php \
        resources/views/realestate/procurements/show.blade.php \
        resources/views/realestate/suppliers/show.blade.php \
        tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
git commit -m "feat(realestate): 仕入れ案件一覧の既定表示から販売済を除外"
```

---

## Task 6: 経営ダッシュボードの進行中パイプラインから除外

除外しないと、売れた案件の想定販売価格が見込みに残り続ける。

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php:714-719`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` の import に 1 行足す:

```php
use App\Http\Controllers\DashboardController;
```

そのうえでテストを追記:

```php
    /** 経営ダッシュボードの仕入れパイプラインからも販売済を除外する */
    public function test_executive_dashboard_pipeline_excludes_sold(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $selling->update(['target_selling_price' => 10000000]);

        $sold = $this->makeProcurement('P-002', 'sold');
        $sold->update(['target_selling_price' => 99000000]);

        // aggregateProcurementStats() は private。/dashboard/executive を丸ごと叩くと
        // 5 事業分のテーブルが要るため、対象メソッドだけを Reflection で呼ぶ
        // （CustomerSurveyAuthorizationTest と同じ既存パターン）。
        $method = new \ReflectionMethod(DashboardController::class, 'aggregateProcurementStats');
        $result = $method->invoke(new DashboardController());

        $this->assertSame(1, $result['in_progress_count']);
        $this->assertSame(10000000, $result['target_total']);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php --filter test_executive_dashboard_pipeline_excludes_sold
```

期待: FAIL（`Failed asserting that 2 is identical to 1`）

- [ ] **Step 3: aggregateProcurementStats() を実装**

`app/Http/Controllers/DashboardController.php` — docblock も実態に合わせて更新:

```php
    /**
     * 仕入れパイプライン（進行中件数・予定金額合計）。
     * status=lost（不成約）と status=sold（販売済＝契約済み）を除いたものを進行中とみなす。
     */
    private function aggregateProcurementStats(): array
    {
        $query = ReProcurement::whereNotIn('status', [
            ProcurementStatus::Lost->value,
            ProcurementStatus::Sold->value,
        ]);
```

以降（`$count` 〜 `return`）は変更しない。

- [ ] **Step 4: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: `OK (8 tests, ...)`（assertion 数は実測値。テスト数 8 が合っていればよい）

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
git commit -m "feat(dashboard): 仕入れパイプラインから販売済案件を除外"
```

---

## Task 7: 仕入れ案件詳細に「契約情報」カードを追加

現在 仕入れ案件 → 契約 への導線が皆無で、「販売済になった案件がどの契約で売れたか」を辿れない。

**Files:**
- Modify: `app/Models/ReProcurement.php:84` 付近（`costs()` の直後）
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php:146`
- Modify: `resources/views/realestate/procurements/show.blade.php:153` の直後
- Test: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` (追記)

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` に追記:

```php
    /** 契約がある案件の詳細に「契約情報」カードが出て、契約詳細へ辿れる */
    public function test_show_renders_contract_card_when_contract_exists(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();
        $executive   = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->get("/realestate/procurements/{$procurement->id}");

        $response->assertOk();
        $response->assertSee('契約情報');
        $response->assertSee('山田 太郎');
        $response->assertSee('30,000,000円');
        $response->assertSee('5,000,000円');   // 粗利 = 契約金額 - 原価
        $response->assertSee("/realestate/contracts/{$contract->id}");
    }

    /** 契約が無い案件では「契約情報」カードごと出さない（空カードは情報量が無い） */
    public function test_show_hides_contract_card_when_no_contract(): void
    {
        $procurement = $this->makeProcurement('P-001');

        $response = $this->actingAs($this->executive())->get("/realestate/procurements/{$procurement->id}");

        $response->assertOk();
        $response->assertDontSee('契約情報');
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php --filter test_show_
```

期待: `test_show_renders_contract_card_when_contract_exists` が FAIL（`契約情報` が見つからない）、`test_show_hides_contract_card_when_no_contract` は PASS。

- [ ] **Step 3: contracts() リレーションを追加**

`app/Models/ReProcurement.php` — `costs()` の**直後**に追加:

```php
    public function costs(): HasMany
    {
        return $this->hasMany(ReProcurementCost::class, 'procurement_id');
    }

    /**
     * この仕入れ案件を対象にした販売契約。
     * 運用上 1 案件 = 1 契約だが、データ構造としては複数を許す。
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(ReContract::class, 'procurement_id');
    }
```

`HasMany` は `:13` で import 済み。`ReContract` は同じ `App\Models` 名前空間なので import 不要。

- [ ] **Step 4: show() の eager load を追加**

`app/Http/Controllers/RealEstate/ProcurementController.php:146`:

```php
        $procurement->load([
            'supplier', 'costs.costItem', 'createdBy', 'updatedBy',
            'contracts.buyer', 'contracts.staff',
        ]);
```

`ReContract::buyer()` / `staff()` はどちらも `->withTrashed()` 済みなので、削除済み買主・退職者でも表示が壊れない。

- [ ] **Step 5: 「契約情報」カードを Blade に追加**

`resources/views/realestate/procurements/show.blade.php` — 「仕入れ情報」カードの閉じ `</div>`（`:153`）と `{{-- 原価管理 --}}`（`:155`）の**間**に挿入:

```blade
    {{-- 契約情報（契約が無いときはカードごと出さない） --}}
    @if($procurement->contracts->isNotEmpty())
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">契約情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="overflow-x: auto;">
            <table class="w-full" style="border-collapse: collapse;">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-3 border-b border-gray-200 text-sm text-gray-600 font-medium text-left whitespace-nowrap">契約日</th>
                        <th class="px-4 py-3 border-b border-gray-200 text-sm text-gray-600 font-medium text-left whitespace-nowrap">種別</th>
                        <th class="px-4 py-3 border-b border-gray-200 text-sm text-gray-600 font-medium text-left whitespace-nowrap">買主</th>
                        <th class="px-4 py-3 border-b border-gray-200 text-sm text-gray-600 font-medium text-center whitespace-nowrap">契約金額</th>
                        <th class="px-4 py-3 border-b border-gray-200 text-sm text-gray-600 font-medium text-center whitespace-nowrap">粗利</th>
                        <th class="px-4 py-3 border-b border-gray-200 text-sm text-gray-600 font-medium text-center whitespace-nowrap">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($procurement->contracts as $c)
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">{{ $c->contract_date?->format('Y/m/d') ?? '—' }}</td>
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                            <span class="badge" style="{{ $c->contract_type->badgeStyle() }}">{{ $c->contract_type->shortLabel() }}</span>
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">{{ $c->buyer_display_name ?: '—' }}</td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-center whitespace-nowrap" style="font-variant-numeric: tabular-nums; font-weight: 600;">
                            {{ $c->contract_amount !== null ? number_format($c->contract_amount) . '円' : '—' }}
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-center whitespace-nowrap" style="font-variant-numeric: tabular-nums; color: #047857; font-weight: 700;">
                            {{ $c->gross_profit !== null ? number_format($c->gross_profit) . '円' : '—' }}
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            <a href="{{ route('realestate.contracts.show', $c) }}"
                               class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100">詳細</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
```

**Blade 記法の注意（既知バグの回避）**:
- `@json()` は使わない（Bug #7 / #23 / #26 の全経路を回避）
- 金額は `number_format(...) . '円'`。`¥` 接頭辞は使わない（プロジェクト規約）
- 契約種別バッジは `badgeStyle()`（inline style を返す）経由。Tailwind クラス直書き NG
- 金額列は th・td とも `text-center`（2026-07-17 の案C統一に合わせる）。粗利は `#047857` / `700`

- [ ] **Step 6: テストを実行して成功を確認**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
```

期待: `OK (10 tests, ...)`（assertion 数は実測値。テスト数 10 が合っていればよい）

- [ ] **Step 7: コミット**

```bash
git add app/Models/ReProcurement.php \
        app/Http/Controllers/RealEstate/ProcurementController.php \
        resources/views/realestate/procurements/show.blade.php \
        tests/Feature/RealEstate/ProcurementStatusTransitionTest.php
git commit -m "feat(realestate): 仕入れ案件詳細に契約情報カードを追加"
```

---

## Task 8: デプロイ前の総合検証

- [ ] **Step 1: 全テストを実行**

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit
```

期待: 全テスト PASS（既存テストの回帰なし）。失敗が出たら止めて原因を調べる（既存テストの削除・スキップは禁止）。

- [ ] **Step 2: コンパイル済み Blade を lint する（Bug #26）**

`view:cache` は「Blade templates cached successfully」と**成功表示するのにコンパイル結果が壊れている**ことがある。
成功表示だけでは不十分なので、生成された PHP を必ず `php -l` する:

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' php artisan view:cache && \
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && \
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' php artisan view:clear
```

期待: `INVALID:` の行が 1 つも出ない。

実証済みのベースライン（変更前・2026-07-21 実測）: `checked=239 invalid=0`。
新規 Blade ファイルは追加しないので、変更後も 239 のままのはず。

- [ ] **Step 3: バッジ CSS の 3 箇所を数える**

```bash
grep -c "badge-re-sold" \
  resources/views/realestate/procurements/index.blade.php \
  resources/views/realestate/procurements/show.blade.php \
  resources/views/realestate/suppliers/show.blade.php
```

期待: 3 ファイルとも `:1`

- [ ] **Step 4: 変更ファイルを確認**

```bash
git diff --stat 13.x..HEAD
```

期待: 設計書 §4 の 8 ファイル + テスト 3 ファイル + プラン 1 ファイル。

---

## Task 9: 本番反映（ユーザーの明示承認が必要）

⚠ **`./deploy.sh` はユーザーが本番デプロイを明示承認していないと自動モード分類器にブロックされる。**
Task 8 まで完了したら、承認を得てから進む。回避のための rsync / ssh 直叩きはしない。

- [ ] **Step 1: main repo へ FF-merge**

```bash
cd /Users/masanori/site/manage
git checkout 13.x && git merge --ff-only procurement-sold-status
```

新規 PHP クラスは追加していない（trait はテスト用で rsync 除外）ため `composer dump-autoload` は不要。

- [ ] **Step 2: 本番デプロイ**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

`npm run build` → rsync → 本番で `config:cache && route:cache && view:cache`。

- [ ] **Step 3: 本番既存データの一括 UPDATE（先に SELECT で承認を得る）**

本番には「契約登録済みなのに販売中のまま」の案件が既に存在する（今回の相談の発端）。
ローカル DB は事実上空なので、本番の実データを見てから件数を確定する。

まず対象を洗い出して**件数と物件名をユーザーに提示**する。本番の生 SSH はハーネスにブロックされるため
`php artisan tinker` 経由で実行する（`sudo mysql` は非対話でパスワードを渡せない）:

```sql
SELECT p.id, p.procurement_code, p.property_name, p.status, c.id AS contract_id, c.contract_date
FROM re_procurements p
JOIN re_contracts c ON c.procurement_id = p.id
WHERE p.status NOT IN ('sold', 'lost')
ORDER BY p.id;
```

承認を得てから UPDATE:

```sql
UPDATE re_procurements p
JOIN re_contracts c ON c.procurement_id = p.id
SET p.status = 'sold', p.updated_at = NOW()
WHERE p.status NOT IN ('sold', 'lost');
```

`lost`（不成約）は除外する。不成約にした案件に契約が紐づいている場合は別の理由がある可能性が高く、勝手に販売済へ倒さない。

- [ ] **Step 4: 手動確認（設計書 §5.3）**

1. 仕入れ案件一覧の既定表示に販売済が出ないこと
2. セレクトで「販売済」を選ぶと販売済だけが出ること
3. 販売済案件の詳細を開き、「契約情報」カードから契約詳細へ遷移できること
4. 経営ダッシュボードの仕入れパイプライン件数が減っていること
5. 一覧バッジのポップオーバーに「販売済」が現れ、手動で他ステータスへ戻せること
6. 仕入れ先詳細（`/realestate/suppliers/{id}`）で販売済案件のバッジが**無地でない**こと

- [ ] **Step 5: origin への push はユーザーの明示指示があった時のみ**

---

## 追加実装なしで動くもの（確認済み・触らない）

| 箇所 | 理由 |
|---|---|
| 契約作成画面の案件セレクト（`ReContractController::create()`） | `status = Selling` のみ表示 → 販売済は候補から自動的に外れるのが正しい（1案件=1契約） |
| 契約編集画面の案件セレクト（`edit()`） | `orWhere('id', $contract->procurement_id)` で現在の案件を必ず含むため、販売済でもセレクトが空にならない |
| `validateProcurement()` / `updateStatus()` のバリデーション | `implode(',', array_column(ProcurementStatus::cases(), 'value'))` で enum から動的生成 → `sold` が自動的に許可される |
| 一覧のステータスバッジ ポップオーバー | `ProcurementStatus::cases()` から生成 → 「販売済」が自動で選択肢に加わり手動差し戻しも可能 |
| 新規登録／編集フォームのステータス select（`_form.blade.php`） | 同上 |
| 一覧フィルタの「販売済」選択肢 | `@foreach(ProcurementStatus::cases())` なので enum に足した時点で現れる |

## スコープ外

- 契約ステータス（`ReContractStatus`）側の変更。仕入れ販売契約は登録時 `Contracted` 固定で、`Closed`/`Lost` への遷移は仲介専用のガード付き（現状維持）
- 分譲地PJ（`ProjectStatus`）の一覧フィルタ。あちらも `sold_out` が既定表示に残る同じ構造だが今回の範囲外（横展開の候補）
