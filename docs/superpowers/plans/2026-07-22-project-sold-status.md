# 分譲地PJ「販売済」自動遷移 ＋ 完売PJ一覧非表示 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 分譲地PJの全区画が成約したら PJ ステータスを自動で `sold_out` にし（区画が復活したら `selling` に戻し）、完売PJを一覧の既定表示から外す。

**Architecture:** 集約判定を `ReProject::syncStatusFromLots()` 1メソッドに集約し、区画の status を変えうる全4系統（分譲地契約・建売契約・注文住宅・区画編集）から明示的に呼び出す（Approach 2）。昇格は「販売済・不成立 以外」の任意ステータスから（緩め条件）、降格は `sold_out → selling` のみ。ステータス更新はクエリビルダ（procurement 遷移と同形、`updated_by` 据え置き）。

**Tech Stack:** Laravel 12 / PHP 8.3 / Eloquent / PHPUnit（SQLite in-memory）/ Blade。設計書: `docs/superpowers/specs/2026-07-22-project-sold-status-design.md`

---

## テスト実行環境（worktree）

全タスク共通。worktree には `vendor` が無いので初回だけ `composer install`（dev 込み）。`.env` は置かず `APP_KEY` をインライン指定（phpunit.xml が SQLite :memory: を使う）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/project-sold-status
# 初回のみ（vendor が無ければ）
composer install
# テスト実行例
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
```

- `re_*` / `hs_*` / `buyers` は migration 管理外 → `CreatesRealEstateSchema` trait で構築（Task 1 で拡張）。
- `users` / `customers` 等は migration 管理（`RefreshDatabase` が作る）。
- 全コミットは worktree 内。`git -C` か明示 `cd` で cwd ドリフトを避ける。

---

## File Structure

| ファイル | 責務 | タスク |
|---|---|---|
| `tests/Concerns/CreatesRealEstateSchema.php` | テスト用スキーマ。`re_projects` / `re_project_lots` / `re_project_costs` / `hs_properties` / `hs_contracts` / `hs_custom_orders` を追加 | 1 |
| `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php` | 全経路の遷移＋一覧フィルタの Feature テスト | 1,2,4-8 |
| `app/Models/ReProject.php` | `syncStatusFromLots()` 集約ロジック（唯一の入口）＋ `LotStatus` use 追加 | 3 |
| `app/Http/Controllers/RealEstate/ReContractController.php` | 分譲地契約 store/update/destroy から sync 呼び出し | 4 |
| `app/Http/Controllers/RealEstate/ProjectController.php` | index フィルタに sold_out 追加 ＋ storeLot/updateLot/destroyLot から sync | 5,8 |
| `app/Http/Controllers/Housing/ContractController.php` | updateLotStatusOnSold/OnUnsold から sync | 6 |
| `app/Http/Controllers/Housing/CustomOrderController.php` | syncLotStatus/releaseLot から sync | 7 |
| `resources/views/realestate/projects/index.blade.php` | フィルタ option ラベル変更 | 8 |

---

## Task 1: テスト用スキーマ拡張 ＋ テストの土台

**Files:**
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`（`createRealEstateSchema()` の末尾に6テーブル追加）
- Create: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`

- [ ] **Step 1: スキーマ trait に6テーブルを追加**

`tests/Concerns/CreatesRealEstateSchema.php` の `createRealEstateSchema()` メソッド内、最後の `Schema::create('re_contracts', ...)` ブロックの直後（メソッドの `}` の前）に以下を貼る:

```php
        Schema::create('re_projects', function (Blueprint $t) {
            $t->id();
            $t->string('project_code', 20);
            $t->string('project_name', 100);
            $t->string('status', 30);
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200);
            $t->decimal('land_area_sqm', 10, 2)->nullable();
            $t->string('zoning', 50)->nullable();
            $t->decimal('building_coverage', 5, 2)->nullable();
            $t->decimal('floor_area_ratio', 5, 2)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
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

        Schema::create('re_project_lots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->integer('lot_number');
            $t->decimal('area_sqm', 10, 2);
            $t->decimal('area_tsubo', 10, 2);
            $t->integer('selling_price_per_tsubo')->nullable();
            $t->integer('selling_price')->nullable();
            $t->boolean('is_price_manual')->default(false);
            $t->string('status', 30)->default('unsold');
            $t->string('notes', 200)->nullable();
            $t->timestamps();
        });

        // ReProject::booted() の saved フック（syncPropertyPurchaseCost）が
        // ReProjectCost::updateOrCreate() を呼ぶため、PJ 作成テストで必要。
        Schema::create('re_project_costs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('cost_item_id');
            $t->integer('estimated_amount')->default(0);
            $t->integer('actual_amount')->nullable();
            $t->string('notes', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('hs_properties', function (Blueprint $t) {
            $t->id();
            $t->string('property_code', 20);
            $t->string('property_name', 100);
            $t->string('status', 30);
            $t->string('land_source_type', 20)->nullable();
            $t->unsignedBigInteger('re_project_lot_id')->nullable();
            $t->unsignedBigInteger('re_procurement_id')->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200);
            $t->decimal('land_area_sqm', 10, 2)->nullable();
            $t->decimal('building_area_sqm', 10, 2)->nullable();
            $t->string('structure', 50)->nullable();
            $t->unsignedTinyInteger('floors')->nullable();
            $t->date('scheduled_completion_date')->nullable();
            $t->date('actual_completion_date')->nullable();
            $t->integer('building_cost')->nullable();
            $t->integer('land_cost')->nullable();
            $t->boolean('is_land_cost_manual')->default(false);
            $t->integer('target_selling_price_building')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('hs_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name', 100);
            $t->integer('selling_price_land');
            $t->integer('selling_price_building');
            $t->decimal('tax_rate', 4, 2)->default(10.00);
            $t->date('contract_date');
            $t->date('settlement_date')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('hs_custom_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_code', 20);
            $t->string('order_name', 100);
            $t->string('status', 30);
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name', 100);
            $t->string('land_source_type', 20)->nullable();
            $t->unsignedBigInteger('re_project_lot_id')->nullable();
            $t->unsignedBigInteger('re_procurement_id')->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200);
            $t->decimal('land_area_sqm', 10, 2)->nullable();
            $t->decimal('building_area_sqm', 10, 2)->nullable();
            $t->string('structure', 50)->nullable();
            $t->unsignedTinyInteger('floors')->nullable();
            $t->integer('building_contract_price')->nullable();
            $t->integer('building_cost')->nullable();
            $t->integer('land_selling_price')->nullable();
            $t->integer('land_cost')->nullable();
            $t->boolean('is_land_cost_manual')->default(false);
            $t->decimal('tax_rate', 4, 2)->default(10.00);
            $t->date('contract_date')->nullable();
            $t->date('scheduled_completion_date')->nullable();
            $t->date('actual_completion_date')->nullable();
            $t->date('delivery_date')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });
```

- [ ] **Step 2: テストファイルを作成（スキーマ健全性テスト＋共通ヘルパー）**

Create `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\LotStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 区画の成約状況に連動した分譲地PJステータスの自動遷移（全区画成約→sold_out /
 * 区画復活→selling）と、一覧フィルタからの販売済除外を検証する。
 *
 * re_* / hs_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProjectSoldStatusTransitionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access を無条件通過し、削除系 role:executive も届く） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** assessment/purchase price は入れない（ReProject の saved フックを no-op に保つ） */
    private function makeProject(string $code, string $status = 'selling'): ReProject
    {
        return ReProject::create([
            'project_code' => $code,
            'project_name' => "分譲地{$code}",
            'status'       => $status,
            'address'      => '愛媛県松山市1-1-1',
            'created_by'   => 1,
        ]);
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

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['last_name' => '山田', 'first_name' => '太郎']);
    }

    public function test_schema_is_built_and_models_are_persistable(): void
    {
        $project = $this->makeProject('PJ-001');
        $lot     = $this->makeLot($project, 1);

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
        $this->assertSame(LotStatus::OnSale, $lot->fresh()->status);
    }
}
```

- [ ] **Step 3: 健全性テストを実行**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（1 test, スキーマが正しく構築されモデルが永続化できる）

- [ ] **Step 4: コミット**

```bash
git add tests/Concerns/CreatesRealEstateSchema.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
test: 分譲地PJ販売済テストの土台（re_/hs_ スキーマ trait 拡張）

CreatesRealEstateSchema に re_projects/re_project_lots/re_project_costs と
住宅3テーブルを追加し、ProjectSoldStatusTransitionTest の土台を用意する。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: 集約ロジックの失敗テスト（syncStatusFromLots の仕様固定）

**Files:**
- Test: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`（メソッド追加）

- [ ] **Step 1: 集約ロジックの全分岐テストを追加**

`ProjectSoldStatusTransitionTest` クラスに以下のメソッドを追加:

```php
    /** L1: 全区画成約 → 販売中PJが販売済へ昇格 */
    public function test_all_lots_sold_promotes_selling_to_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $this->makeLot($project, 1, 'sold');
        $this->makeLot($project, 2, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** L2: 一部だけ成約なら昇格しない */
    public function test_partial_sold_stays_selling(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $this->makeLot($project, 1, 'sold');
        $this->makeLot($project, 2, 'on_sale');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** L3: 販売済PJで区画が復活 → 販売中へ降格 */
    public function test_freed_lot_demotes_sold_out_to_selling(): void
    {
        $project = $this->makeProject('PJ-001', 'sold_out');
        $this->makeLot($project, 1, 'sold');
        $this->makeLot($project, 2, 'on_sale');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** L4: 区画0件PJは触らない（販売中のまま／販売済のまま） */
    public function test_zero_lot_project_is_untouched(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');

        $selling->syncStatusFromLots();
        $soldOut->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Selling, $selling->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $soldOut->fresh()->status);
    }

    /** L5: 不成立PJは全区画成約でも触らない */
    public function test_lost_project_is_never_touched(): void
    {
        $project = $this->makeProject('PJ-001', 'lost');
        $this->makeLot($project, 1, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Lost, $project->fresh()->status);
    }

    /** L6: 昇格元は Selling に限らない（緩め条件）。決済完了でも全区画成約なら販売済へ */
    public function test_promotes_from_non_selling_status(): void
    {
        $project = $this->makeProject('PJ-001', 'settled');
        $this->makeLot($project, 1, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** L7: 既に販売済で全区画成約なら冪等（再更新もエラーも無し） */
    public function test_sold_out_with_all_sold_is_idempotent(): void
    {
        $project = $this->makeProject('PJ-001', 'sold_out');
        $this->makeLot($project, 1, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: FAIL（`Call to undefined method App\Models\ReProject::syncStatusFromLots()`）

（実装は Task 3。ここではコミットしない — Red のまま次へ）

---

## Task 3: syncStatusFromLots() 実装（Green）

**Files:**
- Modify: `app/Models/ReProject.php`

- [ ] **Step 1: `LotStatus` を use に追加**

`app/Models/ReProject.php` の `use App\Enums\ProjectStatus;`（5行目付近）の直後に追加:

```php
use App\Enums\LotStatus;
```

- [ ] **Step 2: `syncStatusFromLots()` を実装**

`app/Models/ReProject.php` の「ヘルパー」節、`allLotsHaveSellingPrice()` メソッド（189行目付近の `}`）の直後に追加:

```php
    /**
     * 区画の成約状況から PJ ステータスを集約する。
     *
     * - 全区画成約（区画1件以上 かつ 全て LotStatus::Sold）→ SoldOut へ昇格
     *   （昇格元は「販売済・不成立 以外」の任意ステータス。「販売済＝完売」を
     *     派生的な完了状態として扱う。区画が全て売れるのは実務上終盤のみ）
     * - 販売済なのに未成約区画が復活 → Selling へ降格
     * - 区画0件のPJ・上記いずれにも該当しないPJは一切触らない
     *
     * ステータス更新はクエリビルダで行う（procurement の案件遷移と同形）。
     * updated_at は Builder::update() が自動付与するが、モデルイベントを
     * 通らないため updated_by は据え置き（＝ユーザー操作ではなくシステム反応）。
     * booted() の saved フック（物件購入費同期）も発火しない。
     * in-memory の status も揃えて呼び出し元の齟齬を防ぐ。
     *
     * ⚠ 本メソッドは「区画の status が変わりうる全経路」から明示的に呼ぶこと。
     *   既知の呼び出し箇所は docs/superpowers/specs/2026-07-22-project-sold-status-design.md §3.3。
     *   新経路を足すときは必ず呼び出しを追加すること。
     */
    public function syncStatusFromLots(): void
    {
        $lots  = ReProjectLot::where('project_id', $this->id)->get(['status']);
        $total = $lots->count();
        if ($total === 0) {
            return; // 区画0件は無干渉（every() が空で true を返す事故も防ぐ）
        }

        $allSold = $lots->every(fn (ReProjectLot $lot) => $lot->status === LotStatus::Sold);
        $current = $this->status;

        if ($allSold && ! in_array($current, [ProjectStatus::SoldOut, ProjectStatus::Lost], true)) {
            ReProject::where('id', $this->id)->update(['status' => ProjectStatus::SoldOut->value]);
            $this->status = ProjectStatus::SoldOut;
        } elseif (! $allSold && $current === ProjectStatus::SoldOut) {
            ReProject::where('id', $this->id)->update(['status' => ProjectStatus::Selling->value]);
            $this->status = ProjectStatus::Selling;
        }
    }
```

- [ ] **Step 3: テストが通ることを確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（L1-L7 ＋ 健全性 = 8 tests green）

- [ ] **Step 4: コミット**

```bash
git add app/Models/ReProject.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat(realestate): 分譲地PJの全区画成約→販売済 集約判定を追加

ReProject::syncStatusFromLots() を追加。全区画成約で sold_out へ昇格、
未成約区画の復活で selling へ降格。昇格元は販売済・不成立以外の任意
ステータス、区画0件は無干渉。ステータス更新はクエリビルダ（procurement
遷移と同形）。全経路からの明示呼び出しは後続タスクで配線する。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 分譲地契約経路の配線（ReContractController）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php`（store / update / destroy）
- Test: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`

- [ ] **Step 1: 失敗テストを追加（分譲地契約 3経路）**

```php
    /** C1: 最終区画を分譲地契約で成約 → PJ が販売済 */
    public function test_subdivision_contract_marks_project_sold_out_on_last_lot(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot     = $this->makeLot($project, 1, 'on_sale');
        $buyer   = $this->makeBuyer();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'subdivision_lot',
            'project_id'      => $project->id,
            'lot_id'          => $lot->id,
            'contract_date'   => '2026-07-22',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 20000000,
            'cost_amount'     => 15000000,
            'property_name'   => '分譲地PJ-001 1区画',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** C2: 他に未成約区画が残るなら PJ は販売中のまま */
    public function test_subdivision_contract_keeps_selling_when_other_lots_remain(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot1    = $this->makeLot($project, 1, 'on_sale');
        $this->makeLot($project, 2, 'on_sale');
        $buyer   = $this->makeBuyer();

        $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'subdivision_lot',
            'project_id'      => $project->id,
            'lot_id'          => $lot1->id,
            'contract_date'   => '2026-07-22',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 20000000,
            'cost_amount'     => 15000000,
            'property_name'   => '分譲地PJ-001 1区画',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** C3: 契約を削除すると区画が販売中に戻り、PJ も販売中へ降格 */
    public function test_destroying_subdivision_contract_reverts_project_to_selling(): void
    {
        $project   = $this->makeProject('PJ-001', 'selling');
        $lot       = $this->makeLot($project, 1, 'on_sale');
        $buyer     = $this->makeBuyer();
        $executive = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'subdivision_lot',
            'project_id'      => $project->id,
            'lot_id'          => $lot->id,
            'contract_date'   => '2026-07-22',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 20000000,
            'cost_amount'     => 15000000,
            'property_name'   => '分譲地PJ-001 1区画',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);

        $contract = \App\Models\ReContract::firstOrFail();
        $response = $this->actingAs($executive)->delete("/realestate/contracts/{$contract->id}");

        $response->assertRedirect();
        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php --filter subdivision`
Expected: FAIL（C1: sold_out にならず selling のまま、等）

- [ ] **Step 3: store() を配線**

`app/Http/Controllers/RealEstate/ReContractController.php` の `store()`、区画を Sold にする if ブロック（163行目付近）を次に置き換え:

```php
        // 分譲地の場合、区画ステータスを sold に変更し PJ を集約
        if ($contractType->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::Sold->value]);
            ReProject::find($contract->project_id)?->syncStatusFromLots();
        }
```

- [ ] **Step 4: update() を配線**

同ファイル `update()` の分譲地 if ブロック（304-313行目付近）を次に置き換え:

```php
        // 分譲地: 区画変更の場合、旧区画を販売中に戻して新区画をsoldに
        if ($contractType->isSubdivision()) {
            $oldLotId = $contract->lot_id;
            $newLotId = $validated['lot_id'] ?? null;
            if ($oldLotId && $oldLotId != $newLotId) {
                ReProjectLot::where('id', $oldLotId)->update(['status' => LotStatus::OnSale->value]);
            }
            if ($newLotId && $newLotId != $oldLotId) {
                ReProjectLot::where('id', $newLotId)->update(['status' => LotStatus::Sold->value]);
            }
            // 影響を受ける PJ（旧区画・新区画それぞれの所属）を集約
            $affectedProjectIds = [];
            if ($oldLotId) { $affectedProjectIds[] = ReProjectLot::find($oldLotId)?->project_id; }
            if ($newLotId) { $affectedProjectIds[] = ReProjectLot::find($newLotId)?->project_id; }
            foreach (array_unique(array_filter($affectedProjectIds)) as $pid) {
                ReProject::find($pid)?->syncStatusFromLots();
            }
        }
```

- [ ] **Step 5: destroy() を配線**

同ファイル `destroy()` の分譲地 if ブロック（343-345行目付近）を次に置き換え:

```php
        // 分譲地の場合、区画ステータスを販売中に戻し PJ を集約
        if ($contract->contract_type->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::OnSale->value]);
            ReProject::find($contract->project_id)?->syncStatusFromLots();
        }
```

- [ ] **Step 6: テストが通ることを確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（C1-C3 含め全 green）

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/RealEstate/ReContractController.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat(realestate): 分譲地契約の成約/解除にPJ販売済遷移を追従

ReContractController の store/update/destroy で区画ステータス更新の直後に
ReProject::syncStatusFromLots() を呼び、全区画成約でPJを sold_out に、
契約解除で selling に戻す。付け替え時は旧新両PJを集約する。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 区画操作経路の配線（ProjectController storeLot/updateLot/destroyLot）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php`
- Test: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`

- [ ] **Step 1: 失敗テストを追加（区画 追加/編集/削除）**

```php
    /** P1: 区画編集で最終区画を成約にすると PJ が販売済 */
    public function test_update_lot_to_sold_marks_project_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot     = $this->makeLot($project, 1, 'on_sale');

        $response = $this->actingAs($this->executive())
            ->put("/realestate/projects/{$project->id}/lots/{$lot->id}", [
                'lot_number' => 1,
                'area_sqm'   => 100.00,
                'status'     => 'sold',
            ]);

        $response->assertOk();
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** P2: 販売済PJに販売中区画を追加すると 販売中へ降格 */
    public function test_store_lot_on_sold_out_project_demotes_to_selling(): void
    {
        $project = $this->makeProject('PJ-001', 'sold_out');
        $this->makeLot($project, 1, 'sold');

        $response = $this->actingAs($this->executive())
            ->post("/realestate/projects/{$project->id}/lots", [
                'lot_number' => 2,
                'area_sqm'   => 120.00,
                'status'     => 'on_sale',
            ]);

        $response->assertOk();
        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** P3: 最後の未成約区画を削除して残りが全成約なら 販売済へ昇格 */
    public function test_destroy_last_unsold_lot_promotes_to_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $this->makeLot($project, 1, 'sold');
        $lot2 = $this->makeLot($project, 2, 'on_sale');

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}/lots/{$lot2->id}");

        $response->assertOk();
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php --filter _lot_`
Expected: FAIL（P1-P3 とも遷移せず）

- [ ] **Step 3: storeLot() を配線**

`app/Http/Controllers/RealEstate/ProjectController.php` の `storeLot()`、`$lot = ReProjectLot::create($validated);`（520行目付近）の直後に1行追加:

```php
        $lot = ReProjectLot::create($validated);
        $project->syncStatusFromLots();
```

- [ ] **Step 4: updateLot() を配線**

同ファイル `updateLot()`、`$lot->update($validated);`（556行目付近、`return response()->json(...)` の直前）の直後に1行追加:

```php
        $lot->update($validated);
        $project->syncStatusFromLots();
```

- [ ] **Step 5: destroyLot() を配線**

同ファイル `destroyLot()`、`$lot->delete();`（574行目付近）の直後に1行追加:

```php
        $lot->delete();
        $project->syncStatusFromLots();
```

- [ ] **Step 6: テストが通ることを確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（P1-P3 含め全 green）

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/RealEstate/ProjectController.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat(realestate): 区画の追加/編集/削除にPJ販売済遷移を追従

ProjectController の storeLot/updateLot/destroyLot で区画変更の直後に
syncStatusFromLots() を呼び、区画編集での完売や区画増減にも集約を追従させる。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 建売契約経路の配線（Housing\ContractController・本番主経路）

**Files:**
- Modify: `app/Http/Controllers/Housing/ContractController.php`
- Test: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`

- [ ] **Step 1: ヘルパーと失敗テストを追加**

テストクラスに「建売物件を作る」ヘルパーとテストを追加:

```php
    private function makeHousingProperty(ReProjectLot $lot, string $code = 'HS-001'): \App\Models\HsProperty
    {
        return \App\Models\HsProperty::create([
            'property_code'     => $code,
            'property_name'     => "建売{$code}",
            'status'            => 'construction',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市2-2-2',
            'created_by'        => 1,
        ]);
    }

    /** H1: 建売契約で最終区画を成約 → 当該分譲地PJが販売済（本番主経路） */
    public function test_housing_building_contract_marks_project_sold_out(): void
    {
        $project  = $this->makeProject('PJ-001', 'selling');
        $lot      = $this->makeLot($project, 1, 'on_sale');
        $property = $this->makeHousingProperty($lot);
        $buyer    = $this->makeBuyer();

        $response = $this->actingAs($this->executive())
            ->post("/housing/properties/{$property->id}/contract", [
                'customer_id'            => $buyer->id,
                'customer_name'          => '山田 太郎',
                'selling_price_land'     => 12000000,
                'selling_price_building' => 18000000,
                'tax_rate'               => 10.00,
                'contract_date'          => '2026-07-22',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** H2: 建売契約を削除 → 区画が販売中に戻り PJ も販売中へ降格 */
    public function test_deleting_housing_building_contract_reverts_project(): void
    {
        $project   = $this->makeProject('PJ-001', 'selling');
        $lot       = $this->makeLot($project, 1, 'on_sale');
        $property  = $this->makeHousingProperty($lot);
        $buyer     = $this->makeBuyer();
        $executive = $this->executive();

        $this->actingAs($executive)
            ->post("/housing/properties/{$property->id}/contract", [
                'customer_id'            => $buyer->id,
                'customer_name'          => '山田 太郎',
                'selling_price_land'     => 12000000,
                'selling_price_building' => 18000000,
                'tax_rate'               => 10.00,
                'contract_date'          => '2026-07-22',
            ])->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);

        $response = $this->actingAs($executive)
            ->delete("/housing/properties/{$property->id}/contract");

        $response->assertRedirect();
        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php --filter housing_building`
Expected: FAIL（区画は Sold になるが PJ が sold_out にならない）

- [ ] **Step 3: 2つのヘルパーメソッドを配線**

`app/Http/Controllers/Housing/ContractController.php` の `updateLotStatusOnSold()` と `updateLotStatusOnUnsold()` に sync 呼び出しを1行ずつ追加:

```php
    private function updateLotStatusOnSold(HsProperty $property): void
    {
        if ($property->re_project_lot_id) {
            $lot = $property->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::Sold->value]);
                $lot->project?->syncStatusFromLots();
            }
        }
    }

    private function updateLotStatusOnUnsold(HsProperty $property): void
    {
        if ($property->re_project_lot_id) {
            $lot = $property->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::OnSale->value]);
                $lot->project?->syncStatusFromLots();
            }
        }
    }
```

- [ ] **Step 4: テストが通ることを確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（H1-H2 含め全 green）

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Housing/ContractController.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat(housing): 建売契約の成約/解除にPJ販売済遷移を追従

分譲地区画を土地元にした建売の契約 store/destroy（updateLotStatusOnSold/
OnUnsold）で区画更新の直後に syncStatusFromLots() を呼ぶ。本番の主経路
（住宅土地元の大半が分譲地区画）をカバーする。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 注文住宅経路の配線（Housing\CustomOrderController・本番主経路）

**Files:**
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`
- Test: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`

- [ ] **Step 1: 失敗テストを追加**

```php
    /** O1: 注文住宅を契約以降ステータスへ → 区画成約 → PJ が販売済（本番主経路） */
    public function test_custom_order_contracted_marks_project_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot     = $this->makeLot($project, 1, 'on_sale');

        $order = \App\Models\HsCustomOrder::create([
            'order_code'        => 'CO-001',
            'order_name'        => '注文住宅CO-001',
            'status'            => 'estimation',
            'customer_name'     => '佐藤 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);

        $response = $this->actingAs($this->executive())
            ->patch("/housing/custom-orders/{$order->id}/status", [
                'status' => 'contracted',
            ]);

        $response->assertOk();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** O2: 契約以降 → 契約以前へ戻すと 区画が販売中に戻り PJ も販売中へ降格 */
    public function test_custom_order_back_to_pre_contract_reverts_project(): void
    {
        $project   = $this->makeProject('PJ-001', 'selling');
        $lot       = $this->makeLot($project, 1, 'on_sale');
        $executive = $this->executive();

        $order = \App\Models\HsCustomOrder::create([
            'order_code'        => 'CO-001',
            'order_name'        => '注文住宅CO-001',
            'status'            => 'estimation',
            'customer_name'     => '佐藤 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);

        $this->actingAs($executive)
            ->patch("/housing/custom-orders/{$order->id}/status", ['status' => 'contracted'])
            ->assertOk();
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);

        $this->actingAs($executive)
            ->patch("/housing/custom-orders/{$order->id}/status", ['status' => 'design'])
            ->assertOk();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php --filter custom_order`
Expected: FAIL（区画は Sold/OnSale になるが PJ が追従しない）

- [ ] **Step 3: syncLotStatus() を配線**

`app/Http/Controllers/Housing/CustomOrderController.php` の `syncLotStatus()`（470行目付近）を次に置き換え（区画が変わった時だけ集約を呼ぶ）:

```php
    private function syncLotStatus(HsCustomOrder $order, ?CustomOrderStatus $oldStatus): void
    {
        if (!$order->re_project_lot_id) {
            return;
        }

        $lot = $order->projectLot;
        if (!$lot) {
            return;
        }

        $newIsContracted = $order->status->isContractedOrLater();
        $oldIsContracted = $oldStatus ? $oldStatus->isContractedOrLater() : false;

        $changed = false;

        // 契約以前 → 契約以降: sold に更新
        if ($newIsContracted && !$oldIsContracted) {
            $lot->update(['status' => LotStatus::Sold->value]);
            $changed = true;
        }

        // 契約以降 → 契約以前: on_sale に戻す
        if (!$newIsContracted && $oldIsContracted) {
            $lot->update(['status' => LotStatus::OnSale->value]);
            $changed = true;
        }

        if ($changed) {
            $lot->project?->syncStatusFromLots();
        }
    }
```

- [ ] **Step 4: releaseLot() を配線**

同ファイル `releaseLot()`（498行目付近）に sync 呼び出しを1行追加:

```php
    private function releaseLot(HsCustomOrder $order): void
    {
        if (!$order->re_project_lot_id) {
            return;
        }

        // 契約以降のステータスで削除された場合のみ戻す
        if ($order->status->isContractedOrLater()) {
            $lot = $order->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::OnSale->value]);
                $lot->project?->syncStatusFromLots();
            }
        }
    }
```

- [ ] **Step 5: テストが通ることを確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（O1-O2 含め全 green）

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/CustomOrderController.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat(housing): 注文住宅の契約遷移にPJ販売済遷移を追従

CustomOrderController の syncLotStatus/releaseLot で区画ステータスが
変わった時に syncStatusFromLots() を呼ぶ。契約以降/以前の往復・削除で
PJの sold_out↔selling を追従させる（本番主経路）。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: 一覧フィルタの販売済除外（index ＋ Blade ラベル）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php`（index）
- Modify: `resources/views/realestate/projects/index.blade.php`（option ラベル）
- Test: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`

- [ ] **Step 1: 失敗テストを追加（一覧フィルタ）**

一覧が描画するのは `project_name`（= "分譲地{$code}"）。

```php
    /** F1: 既定フィルタ（進行中のみ）は sold_out と lost を出さない */
    public function test_index_default_filter_excludes_sold_out_and_lost(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');
        $lost    = $this->makeProject('PJ-003', 'lost');

        $response = $this->actingAs($this->executive())->get('/realestate/projects');

        $response->assertOk();
        $response->assertSee($selling->project_name);
        $response->assertDontSee($soldOut->project_name);
        $response->assertDontSee($lost->project_name);
    }

    /** F2: ?status=（空＝全て）は全ステータスを出す */
    public function test_index_status_all_shows_everything(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');

        $response = $this->actingAs($this->executive())->get('/realestate/projects?status=');

        $response->assertOk();
        $response->assertSee($selling->project_name);
        $response->assertSee($soldOut->project_name);
    }

    /** F3: ?status=sold_out は販売済だけを出す */
    public function test_index_status_sold_out_shows_only_sold_out(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');

        $response = $this->actingAs($this->executive())->get('/realestate/projects?status=sold_out');

        $response->assertOk();
        $response->assertSee($soldOut->project_name);
        $response->assertDontSee($selling->project_name);
    }
```

- [ ] **Step 2: 失敗を確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php --filter test_index`
Expected: FAIL（F1: 既定で sold_out が見えてしまう）

- [ ] **Step 3: index() のフィルタを修正**

`app/Http/Controllers/RealEstate/ProjectController.php` の `index()`、ステータスフィルタ部（33-39行目付近）を次に置き換え:

```php
        // フィルター: ステータス（デフォルトは進行中のみ = 不成立・販売済を除く）
        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'active') {
            $query->whereNotIn('status', [
                ProjectStatus::Lost->value,
                ProjectStatus::SoldOut->value,
            ]);
        } elseif ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }
```

- [ ] **Step 4: Blade の option ラベルを変更**

`resources/views/realestate/projects/index.blade.php` の44行目付近:

```blade
<option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>ステータス: 進行中のみ</option>
```

（`不成立以外` → `進行中のみ`。procurement 一覧と同文言）

- [ ] **Step 5: テストが通ることを確認**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（全 test green）

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/RealEstate/ProjectController.php resources/views/realestate/projects/index.blade.php tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat(realestate): 分譲地PJ一覧の既定表示から販売済を除外

index の active フィルタを whereNotIn([lost, sold_out]) に変更し、
option ラベルを「進行中のみ」に統一（procurement と同形）。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: デプロイ前の必須検証（Bug #26 + 全スイート）

**Files:** なし（検証のみ）

- [ ] **Step 1: 対象テストファイル全体が green**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php`
Expected: PASS（21 tests, 0 failures）

- [ ] **Step 2: 既存スイート全体に退行が無い**

Run: `APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' vendor/bin/phpunit`
Expected: PASS（全テスト green。特に既存 `ProcurementStatusTransitionTest` が trait 変更で壊れていないこと）

- [ ] **Step 3: コンパイル済み Blade を php -l 検証（Bug #26）**

Blade 変更は option ラベル1語のみだが、規約として実施:

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' php artisan view:cache && \
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && \
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' php artisan view:clear
```
Expected: `INVALID:` 行が1つも出ない

- [ ] **Step 4: 呼び出し漏れが無いことを目視（明示方式の台帳確認）**

Run: `grep -rn "syncStatusFromLots(" app/`
Expected: 定義1（ReProject）＋呼び出し10（ReContractController×3相当、ProjectController×3、Housing ContractController×2、CustomOrderController×2）が全て存在

---

## 本番反映（ユーザー明示承認後）

テスト・検証が全て green になったら:

1. main repo で FF-merge:
   ```bash
   cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only project-sold-status
   ```
   （新規 PHP クラスは追加していないので `composer dump-autoload` は不要）
2. `./deploy.sh`（**ユーザーの明示承認が必要**。承認なしは分類器ブロック）
3. デプロイ後スモーク: 全区画成約済みの分譲地が一覧既定から消える／「全て」で緑「販売済」バッジ表示／住宅で最後の区画成約→当該分譲地 sold_out
4. origin push はユーザー明示指示時のみ

---

## Self-Review

**Spec coverage（§ = 設計書の節）:**
- §3.1 一覧フィルタ → Task 8 ✅
- §3.2 syncStatusFromLots → Task 3 ✅（全分岐 Task 2 で固定）
- §3.3 呼び出し10箇所 → 分譲地契約 Task 4／区画操作 Task 5／建売 Task 6／注文住宅 Task 7 ✅（Task 9 Step 4 で台帳確認）
- §3.4 影響を受けない箇所（enum/CSS/ダッシュボード）→ 変更なし ✅
- §5.1 スキーマ trait 拡張 → Task 1 ✅
- §5.2 集約単体 → Task 2（L1-L7）✅
- §5.3 経路別 → Task 4-7 ✅
- §5.4 一覧フィルタ → Task 8（F1-F3）✅
- §5.5 view:cache php -l → Task 9 Step 3 ✅

**型・シグネチャ整合:** `syncStatusFromLots(): void`（Task 2 で呼び、Task 3 で定義）。`$lot->project?->syncStatusFromLots()`（Housing 経路、`project()` は ReProjectLot→ReProject belongsTo）。`ReProject::find($id)?->syncStatusFromLots()`（ReContractController、ReProject は既存 import）。全経路で同一シグネチャ。

**プレースホルダ:** 無し（全コード・全コマンド・全ペイロードを明記）。

**既知の割り切り（silent cap 回避のため明記）:** ReContractController::update の「PJ付け替え」edge（旧新PJが異なる稀ケース）は §3.3 のループで両PJを集約するが、UI 上 sold_out PJ の区画は全 Sold で edit セレクトに現れないため実運用では到達困難。テストは同一PJ内の代表ケース（Task 4 C1-C3）で担保し、異PJ付け替えの専用テストは置かない。
