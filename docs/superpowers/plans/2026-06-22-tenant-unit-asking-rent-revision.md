# 区画 募集家賃の改定（履歴付き）実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント区画の「募集家賃（家賃/共益費/ゴミ代/駆除代の4項目）」を“賃料改定”として履歴付きで変更できるようにし（空室・商談中のみ・経営層限定）、区画詳細でその区画の家賃推移（募集＋契約）を時系列で確認できるようにする。

**Architecture:** 既存の契約改定（`rent_revisions` / `ContractController::revise`）を**一切変更せず**、新規テーブル `unit_rent_revisions`（追加のみ）と新規モデル `UnitRentRevision` を `RentRevision` のミラーとして作る。`UnitController` に改定フォーム/実行（`showReviseRent`/`reviseRent`）を追加し、`show` で募集改定＋全契約の契約改定を1つの配列にマージして区画詳細の新タブに時系列表示。区画「編集」では金額4項目を表示専用にロックして改定フロー経由に一本化する（`update` からも4項目を除外して現値保持）。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade / Eloquent / PHPUnit（SQLite in-memory, `RefreshDatabase`）。本番 MySQL 8 はスキーマを raw SQL で適用。

**設計書:** [docs/superpowers/specs/2026-06-22-tenant-unit-asking-rent-revision-design.md](../specs/2026-06-22-tenant-unit-asking-rent-revision-design.md)

---

## 作業環境（重要 — 必読）

- 本プランは worktree ではなく **main repo（`/Users/masanori/site/manage`）の feature ブランチ**で実施する。
  理由: DB を伴う Feature テスト（`vendor/bin/phpunit`）には dev 依存（phpunit）が必要だが、worktree には vendor が一切無くテストが走らない。main repo は本番同期のため現在 `--no-dev` 状態で phpunit 未インストール。
- 手順の骨子:
  1. main repo（branch `13.x`・作業ツリー clean・spec/plan は既に 13.x にコミット済み）で feature ブランチを切る。
  2. `composer install`（dev 依存を入れて phpunit を使えるようにする）。
  3. TDD で実装（`vendor/bin/phpunit`）。`unit_rent_revisions` 等は Laravel migration を RefreshDatabase（SQLite in-memory）で利用。
  4. 全テスト green を確認したら `composer install --no-dev`（本番同期用 vendor に戻す）。
  5. `13.x` に `--ff-only` マージ → **新規 PHP クラス（`UnitRentRevision`）があるので main repo の cwd で `composer dump-autoload`** → `./deploy.sh`。
- **スキーマは二重管理**: ①テスト用 Laravel migration（`database/migrations/…`、RefreshDatabase で使用）と ②本番/ローカル MySQL 用 raw SQL（`database/sql/…`）の両方を用意する。本番 DB への適用は **コードより先**に行う（新 `show()` が `unit_rent_revisions` を必ず参照するため、テーブル不在のままコードを公開すると区画詳細が全件 500 になる。Task 10 で順序を厳守）。
- `tests/` は deploy.sh の rsync 除外対象なので本番には送られない。`database/` は除外されないため SQL ファイルは本番に届く（実行は別手順・要ユーザー承認）。

---

## ファイル構成

| 区分 | パス | 責務 |
|---|---|---|
| Create | `database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php` | テスト用スキーマ（RefreshDatabase で SQLite に作成） |
| Create | `database/sql/2026-06-22-create-unit-rent-revisions.sql` | 本番/ローカル MySQL 適用用 raw DDL（追加のみ・`CREATE TABLE`） |
| Create | `app/Models/UnitRentRevision.php` | 募集家賃改定履歴モデル（`RentRevision` のミラー、`unit_id`） |
| Modify | `app/Models/Unit.php:99-145` | `rentRevisions(): HasMany`（→ `UnitRentRevision`）を追加 |
| Modify | `app/Http/Controllers/Tenant/UnitController.php` | imports 追加 / `showReviseRent`・`reviseRent` 追加 / `show` で統合履歴 `$rentHistory` 構築 / `update` で金額4項目をロック |
| Modify | `routes/web.php:229` 直後 | `tenant.units.revise`（GET）/ `tenant.units.revise.execute`（POST）を `role:executive` グループで追加 |
| Create | `resources/views/tenant/units/revise.blade.php` | 募集家賃改定フォーム（`contracts/revise.blade.php` の体裁を踏襲） |
| Modify | `resources/views/tenant/units/show.blade.php:132,263-267,358` | 募集条件カードに「賃料改定」ボタン＋「賃料改定履歴」タブ（区分列付き統合表示） |
| Modify | `resources/views/tenant/units/edit.blade.php:128-178` | 募集条件の金額4項目を表示専用にロック（敷金は編集可・name 送信は維持） |
| Create | `tests/Feature/Tenant/UnitRentRevisionTest.php` | 改定実行 / 入居中ガード / manager 403 / 編集ロック / 統合履歴 |

**変更しないもの（YAGNI / 既存資産保護）**: `rent_revisions` テーブル・`RentRevision` モデル・`ContractController`・`contracts/revise.blade.php`・`units/create.blade.php`・`UnitController::store`（新規登録は従来どおり金額入力可）。

---

## Task 0: ブランチ作成と dev 依存インストール

**Files:** （コード変更なし・環境準備）

- [ ] **Step 1: main repo で clean / branch を確認**

Run: `cd /Users/masanori/site/manage && git status --short && git branch --show-current`
Expected: 出力なし（clean）＋ `13.x`

- [ ] **Step 2: feature ブランチを作成**

Run: `cd /Users/masanori/site/manage && git checkout -b feature/tenant-unit-asking-rent-revision`
Expected: `Switched to a new branch 'feature/tenant-unit-asking-rent-revision'`

- [ ] **Step 3: dev 依存（phpunit 等）をインストール**

Run: `cd /Users/masanori/site/manage && composer install 2>&1 | tail -5`
Expected: 完了後 `vendor/bin/phpunit` が存在する（`ls vendor/bin/phpunit`）。

- [ ] **Step 4: ベースラインのテストが green か確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit 2>&1 | tail -20`
Expected: 既存テストがすべて PASS。1件でも赤いなら本実装前に原因を確認する（環境問題の切り分け）。

---

## Task 1: スキーマ（Laravel migration ＋ 本番 raw SQL）

**Files:**
- Create: `database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php`
- Create: `database/sql/2026-06-22-create-unit-rent-revisions.sql`

> このタスクは「テストが赤→緑」型ではなく前提インフラ。検証はマイグレーションの構文・SQLite 実行成功（既存スイートが RefreshDatabase 経由で新マイグレーションを実行しても green のまま）で行う。

- [ ] **Step 1: Laravel migration を作成（テスト用スキーマ）**

Create `database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 募集家賃の賃料改定履歴テーブル。
     * rent_revisions（契約専用）のミラーで、contract_id の代わりに unit_id を持つ。
     * 既存テーブルには一切手を入れない（追加のみ）。
     */
    public function up(): void
    {
        Schema::create('unit_rent_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->date('revision_date')->comment('改定適用日');
            $table->integer('old_rent')->comment('旧募集家賃（円）');
            $table->integer('new_rent')->comment('新募集家賃（円）');
            $table->integer('old_common_fee')->nullable()->comment('旧共益費（円）');
            $table->integer('new_common_fee')->nullable()->comment('新共益費（円）');
            $table->integer('old_garbage_fee')->nullable()->comment('旧ゴミ代（円）');
            $table->integer('new_garbage_fee')->nullable()->comment('新ゴミ代（円）');
            $table->integer('old_pest_control_fee')->nullable()->comment('旧駆除代（円）');
            $table->integer('new_pest_control_fee')->nullable()->comment('新駆除代（円）');
            $table->text('reason')->nullable()->comment('改定理由');
            $table->foreignId('revised_by')->constrained('users')->restrictOnDelete()->comment('改定実行者（経営層）');
            $table->timestamp('created_at')->useCurrent();

            $table->index('unit_id', 'idx_unit_revisions_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_rent_revisions');
    }
};
```

- [ ] **Step 2: 本番/ローカル MySQL 用 raw SQL を作成**

Create `database/sql/2026-06-22-create-unit-rent-revisions.sql`:

```sql
-- 募集家賃の賃料改定履歴テーブル（rent_revisions のミラー、unit_id 版）
-- 追加のみ・既存テーブルへの変更なし。
-- 適用（ローカル）: sudo mysql manage < database/sql/2026-06-22-create-unit-rent-revisions.sql
-- 適用（本番）    : 本番DBに対し同SQLを実行（要ユーザー承認・Task 10 参照）
CREATE TABLE `unit_rent_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `revision_date` DATE NOT NULL COMMENT '改定適用日',
  `old_rent` INT NOT NULL COMMENT '旧募集家賃（円）',
  `new_rent` INT NOT NULL COMMENT '新募集家賃（円）',
  `old_common_fee` INT NULL COMMENT '旧共益費（円）',
  `new_common_fee` INT NULL COMMENT '新共益費（円）',
  `old_garbage_fee` INT NULL COMMENT '旧ゴミ代（円）',
  `new_garbage_fee` INT NULL COMMENT '新ゴミ代（円）',
  `old_pest_control_fee` INT NULL COMMENT '旧駆除代（円）',
  `new_pest_control_fee` INT NULL COMMENT '新駆除代（円）',
  `reason` TEXT NULL COMMENT '改定理由',
  `revised_by` BIGINT UNSIGNED NOT NULL COMMENT '改定実行者（経営層）',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_unit_revisions_unit` (`unit_id`),
  CONSTRAINT `fk_unit_revisions_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_unit_revisions_revised_by` FOREIGN KEY (`revised_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> 列型・FK の参照型（`units.id` / `users.id` = `BIGINT UNSIGNED`）・null 可否は migration と完全一致させること。整数列のため照合順序（collation）の FK 不一致は発生しない。

- [ ] **Step 3: migration の構文チェック**

Run: `cd /Users/masanori/site/manage && php -l database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: SQLite で migration が実行できることをスイートで確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit 2>&1 | tail -15`
Expected: 既存スイートが引き続き全 PASS（各テストの RefreshDatabase が新 migration を実行しても落ちないこと＝SQLite DDL として妥当）。赤くなったら migration を見直す。

- [ ] **Step 5: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php database/sql/2026-06-22-create-unit-rent-revisions.sql
git commit -m "feat(tenant): 募集家賃改定履歴テーブル unit_rent_revisions を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `UnitRentRevision` モデル ＋ `Unit::rentRevisions()` リレーション

**Files:**
- Create: `app/Models/UnitRentRevision.php`
- Modify: `app/Models/Unit.php`（リレーション群の末尾＝`repairs()` の直後・約97行に追加）

- [ ] **Step 1: `UnitRentRevision` モデルを作成（`RentRevision` のミラー）**

Create `app/Models/UnitRentRevision.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitRentRevision extends Model
{
    use HasFactory;

    /**
     * updated_atは不要（created_atのみ）
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'unit_id',
        'revision_date',
        'old_rent',
        'new_rent',
        'old_common_fee',
        'new_common_fee',
        'old_garbage_fee',
        'new_garbage_fee',
        'old_pest_control_fee',
        'new_pest_control_fee',
        'reason',
        'revised_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'old_rent' => 'integer',
            'new_rent' => 'integer',
            'old_common_fee' => 'integer',
            'new_common_fee' => 'integer',
            'old_garbage_fee' => 'integer',
            'new_garbage_fee' => 'integer',
            'old_pest_control_fee' => 'integer',
            'new_pest_control_fee' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 対象区画
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * 改定実行者（経営層）
     */
    public function revisedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
```

- [ ] **Step 2: `Unit` モデルに `rentRevisions()` リレーションを追加**

`app/Models/Unit.php` の `repairs()` メソッド（約93-97行）の直後に、以下を追加する。現状:

```php
    /**
     * この区画の一般修繕
     */
    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }
```

の直後（`// アクセサ / ヘルパー` コメントの前）に挿入:

```php

    /**
     * この区画の募集家賃改定履歴
     */
    public function rentRevisions(): HasMany
    {
        return $this->hasMany(UnitRentRevision::class);
    }
```

> `Unit.php` は既に `use Illuminate\Database\Eloquent\Relations\HasMany;` を import 済み（10行目相当）。`UnitRentRevision` は同名前空間 `App\Models` なので import 不要。

- [ ] **Step 3: 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l app/Models/UnitRentRevision.php && php -l app/Models/Unit.php`
Expected: 両方 `No syntax errors detected`

- [ ] **Step 4: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add app/Models/UnitRentRevision.php app/Models/Unit.php
git commit -m "feat(tenant): UnitRentRevision モデルと Unit::rentRevisions リレーションを追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: ルート追加（`tenant.units.revise` GET/POST・経営層のみ）

**Files:**
- Modify: `routes/web.php`（区画ステータス変更ルート＝約227-229行の直後、テナント契約セクション＝約231行の前）

- [ ] **Step 1: 区画の賃料改定ルートを追加**

`routes/web.php` の以下（約226-229行）:

```php
        // 区画ステータス変更（経営層+管理者）— vacant↔negotiating
        Route::patch('/units/{unit}/status', [\App\Http\Controllers\Tenant\UnitController::class, 'updateStatus'])
            ->middleware('role:executive,manager')
            ->name('tenant.units.updateStatus');
```

の直後に、以下を挿入する（テナント契約セクションのコメント `| テナント契約管理` の前）:

```php

        // 募集家賃の賃料改定（経営層のみ）— 空室・商談中の区画
        Route::middleware('role:executive')->group(function () {
            Route::get('/units/{unit}/revise', [\App\Http\Controllers\Tenant\UnitController::class, 'showReviseRent'])
                ->name('tenant.units.revise');
            Route::post('/units/{unit}/revise', [\App\Http\Controllers\Tenant\UnitController::class, 'reviseRent'])
                ->name('tenant.units.revise.execute');
        });
```

> これは `Route::prefix('tenant')->middleware('department.access:tenant')` グループ内（=URL は `/tenant/units/{unit}/revise`）。経営層は `CheckDepartmentAccess` を素通り（`isExecutive()` で即通過）するため、`role:executive` ゲートのみが実効的な認可になる。

- [ ] **Step 2: 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l routes/web.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: ルートが2本登録されたことを確認**

Run: `cd /Users/masanori/site/manage && php artisan route:list 2>/dev/null | grep "units/{unit}/revise"`
Expected: 2行（`GET|HEAD tenant/units/{unit}/revise … tenant.units.revise` と `POST tenant/units/{unit}/revise … tenant.units.revise.execute`）。

- [ ] **Step 4: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add routes/web.php
git commit -m "feat(tenant): 区画の賃料改定ルート(revise/revise.execute)を経営層限定で追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `UnitController` — 改定フォーム/実行（`showReviseRent`/`reviseRent`）+ テスト（TDD）

**Files:**
- Create: `tests/Feature/Tenant/UnitRentRevisionTest.php`
- Modify: `app/Http/Controllers/Tenant/UnitController.php`（imports 約5-15行 / `updateStatus` の直後＝約324行にメソッド追加）

- [ ] **Step 1: 失敗するテストを書く（改定実行・入居中ガード・manager 403）**

Create `tests/Feature/Tenant/UnitRentRevisionTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Property;
use App\Models\RentRevision;
use App\Models\Unit;
use App\Models\UnitRentRevision;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 区画 募集家賃の改定（履歴付き）の検証。
 *
 * 対象テーブル（users/departments/customers/properties/units/contracts/rent_revisions/
 * unit_rent_revisions）は Laravel マイグレーション管理のため SQLite in-memory +
 * RefreshDatabase で利用可能。POST 改定はリダイレクトを返すので Blade 全体描画に依存しない。
 * 統合履歴のみ show を GET して viewData('rentHistory') を検証する（描画は Playwright で別途確認）。
 */
class UnitRentRevisionTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 物件＋区画を1つ作って返す（金額は既定値入り） */
    private function makeUnit(string $status = 'vacant', array $attrs = []): Unit
    {
        $property = Property::create([
            'code'          => 'PROP-UR-001',
            'name'          => 'テストビル',
            'property_type' => 'tenant',
            'department'    => 'tenant',
            'address'       => '愛媛県松山市本町1-1',
        ]);

        return Unit::create(array_merge([
            'property_id'      => $property->id,
            'room_number'      => 'A',
            'display_name'     => 'A',
            'status'           => $status,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 50000,
        ], $attrs));
    }

    /** 空室区画の改定 → unit_rent_revisions に1件・units.* 更新・区画詳細へリダイレクト */
    public function test_revise_creates_history_and_updates_unit(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('vacant');

        $response = $this->actingAs($exec)->post(
            route('tenant.units.revise.execute', $unit),
            [
                'revision_date'        => '2026-07-01',
                'new_rent'             => 120000,
                'new_common_fee'       => 12000,
                'new_garbage_fee'      => 2500,
                'new_pest_control_fee' => 1200,
                'reason'               => '近隣相場の上昇',
            ]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));

        $this->assertDatabaseHas('unit_rent_revisions', [
            'unit_id'        => $unit->id,
            'old_rent'       => 100000,
            'new_rent'       => 120000,
            'old_common_fee' => 10000,
            'new_common_fee' => 12000,
            'revised_by'     => $exec->id,
        ]);

        $unit->refresh();
        $this->assertSame(120000, $unit->rent);
        $this->assertSame(12000, $unit->common_fee);
        $this->assertSame(2500, $unit->garbage_fee);
        $this->assertSame(1200, $unit->pest_control_fee);
    }

    /** 入居中区画は GET 改定フォームで区画詳細へリダイレクト（改定不可ガード） */
    public function test_show_revise_redirects_when_occupied(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('occupied');

        $response = $this->actingAs($exec)->get(route('tenant.units.revise', $unit));

        $response->assertRedirect(route('tenant.units.show', $unit));
    }

    /** 入居中区画は POST 改定でも実行されず区画詳細へリダイレクト（履歴0件・現値維持） */
    public function test_revise_blocked_when_occupied(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('occupied');

        $response = $this->actingAs($exec)->post(
            route('tenant.units.revise.execute', $unit),
            ['revision_date' => '2026-07-01', 'new_rent' => 120000]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));
        $this->assertDatabaseCount('unit_rent_revisions', 0);
        $unit->refresh();
        $this->assertSame(100000, $unit->rent);
    }

    /** 非経営層（manager）は賃料改定フォームにアクセスできない（403） */
    public function test_revise_route_blocks_manager(): void
    {
        $unit = $this->makeUnit('vacant');

        // department.access:tenant を通すため tenant 部門に所属させ、403 が role ゲート由来であることを保証
        $this->seed(DepartmentSeeder::class);
        $manager = User::factory()->create([
            'role' => UserRole::Manager->value,
            'must_change_password' => false,
        ]);
        $manager->departments()->attach(Department::where('code', 'tenant')->value('id'));

        $response = $this->actingAs($manager)->get(route('tenant.units.revise', $unit));

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/UnitRentRevisionTest.php 2>&1 | tail -25`
Expected: ルート/メソッド未定義により全4件が FAIL（`Route [tenant.units.revise.execute] not defined` 等。Task 3 でルートは追加済みなら `Method … showReviseRent does not exist` 系の 500/エラー）。緑にするのはこの後の実装。

- [ ] **Step 3: `UnitController` に import を追加**

`app/Http/Controllers/Tenant/UnitController.php` の現状の import 群（5-15行）:

```php
use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use App\Services\Tenant\RentalIncomeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
```

を次に置き換える（`UnitRentRevision` / `Auth` / `DB` を追加）:

```php
use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use App\Models\UnitRentRevision;
use App\Services\Tenant\RentalIncomeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
```

- [ ] **Step 4: `showReviseRent` / `reviseRent` メソッドを追加**

`UnitController` の `updateStatus()` メソッドの閉じ括弧（約324行）と `// プライベートメソッド` コメント（約326行）の間に、以下を挿入する:

```php

    /**
     * 募集家賃の賃料改定フォーム
     * Route: GET /tenant/units/{unit}/revise
     */
    public function showReviseRent(Unit $unit)
    {
        // 入居中は契約改定へ誘導（募集家賃の改定対象は空室・商談中のみ）
        if ($unit->status === UnitStatus::Occupied) {
            return redirect()
                ->route('tenant.units.show', $unit)
                ->with('error', '入居中の区画は契約から賃料改定してください。');
        }

        $unit->load('property');

        return view('tenant.units.revise', compact('unit'));
    }

    /**
     * 募集家賃の賃料改定実行
     * Route: POST /tenant/units/{unit}/revise
     */
    public function reviseRent(Request $request, Unit $unit)
    {
        // 入居中ガード（同上）
        if ($unit->status === UnitStatus::Occupied) {
            return redirect()
                ->route('tenant.units.show', $unit)
                ->with('error', '入居中の区画は契約から賃料改定してください。');
        }

        $validated = $request->validate([
            'revision_date'        => 'required|date',
            'new_rent'             => 'required|integer|min:0',
            'new_common_fee'       => 'nullable|integer|min:0',
            'new_garbage_fee'      => 'nullable|integer|min:0',
            'new_pest_control_fee' => 'nullable|integer|min:0',
            'reason'               => 'nullable|string|max:5000',
        ]);

        DB::transaction(function () use ($unit, $validated) {
            // 改定履歴を記録（old=現在の募集条件、new=入力値）
            UnitRentRevision::create([
                'unit_id'              => $unit->id,
                'revision_date'        => $validated['revision_date'],
                'old_rent'             => $unit->rent,
                'new_rent'             => $validated['new_rent'],
                'old_common_fee'       => $unit->common_fee,
                'new_common_fee'       => $validated['new_common_fee'] ?? 0,
                'old_garbage_fee'      => $unit->garbage_fee,
                'new_garbage_fee'      => $validated['new_garbage_fee'] ?? 0,
                'old_pest_control_fee' => $unit->pest_control_fee,
                'new_pest_control_fee' => $validated['new_pest_control_fee'] ?? 0,
                'reason'               => $validated['reason'] ?? null,
                'revised_by'           => Auth::id(),
            ]);

            // 区画の募集条件を更新
            $unit->update([
                'rent'             => $validated['new_rent'],
                'common_fee'       => $validated['new_common_fee'] ?? 0,
                'garbage_fee'      => $validated['new_garbage_fee'] ?? 0,
                'pest_control_fee' => $validated['new_pest_control_fee'] ?? 0,
            ]);
        });

        return redirect()
            ->route('tenant.units.show', $unit)
            ->with('success', "区画「{$unit->display_name}」の募集家賃を改定しました。");
    }
```

- [ ] **Step 5: テストを実行して PASS を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/UnitRentRevisionTest.php 2>&1 | tail -15`
Expected: 4件すべて PASS（OK (4 tests)）。

- [ ] **Step 6: 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l app/Http/Controllers/Tenant/UnitController.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add app/Http/Controllers/Tenant/UnitController.php tests/Feature/Tenant/UnitRentRevisionTest.php
git commit -m "feat(tenant): 区画の募集家賃改定 showReviseRent/reviseRent を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `UnitController::update` — 金額4項目をロック（TDD）

**Files:**
- Modify: `tests/Feature/Tenant/UnitRentRevisionTest.php`（テストメソッド追加）
- Modify: `app/Http/Controllers/Tenant/UnitController.php`（`update` の validation 約223-238行 / null→0 ループ 約263-266行）

- [ ] **Step 1: 失敗するテストを追加（編集では金額が変わらない・敷金は変わる）**

`tests/Feature/Tenant/UnitRentRevisionTest.php` の `test_revise_route_blocks_manager` メソッドの直後（クラス閉じ括弧の前）に追加:

```php

    /** 編集(update)で金額4項目を送っても無視され（現値維持）、敷金だけ更新できる */
    public function test_update_locks_amount_fields_but_allows_deposit(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('vacant'); // rent10万/共益1万/ゴミ2千/駆除1千/敷金5万

        $response = $this->actingAs($exec)->put(
            route('tenant.units.update', $unit),
            [
                'room_number'      => 'A',
                'status'           => 'vacant',
                // 金額4項目は送っても update から除外され無視される
                'rent'             => 999999,
                'common_fee'       => 888888,
                'garbage_fee'      => 777777,
                'pest_control_fee' => 666666,
                // 敷金は従来どおり更新可
                'deposit'          => 60000,
            ]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));

        $unit->refresh();
        // 金額4項目は現値維持
        $this->assertSame(100000, $unit->rent);
        $this->assertSame(10000, $unit->common_fee);
        $this->assertSame(2000, $unit->garbage_fee);
        $this->assertSame(1000, $unit->pest_control_fee);
        // 敷金は更新
        $this->assertSame(60000, $unit->deposit);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/UnitRentRevisionTest.php --filter test_update_locks_amount_fields_but_allows_deposit 2>&1 | tail -20`
Expected: FAIL（現状 `update` は金額を validation に含み更新するため、`rent` が 999999 になり assertSame(100000,…) で失敗）。

- [ ] **Step 3: `update` の validation から金額4項目を除外**

`app/Http/Controllers/Tenant/UnitController.php` の `update()` 内 validation（約223-238行）:

```php
        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:-3|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type_id'    => 'nullable|exists:inquiry_usage_types,id',
            'status'           => [
                'required',
                $isOccupied ? Rule::in([$unit->status->value]) : Rule::in(['vacant', 'negotiating']),
            ],
            'rent'             => 'nullable|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ]);
```

を次に置き換える（`rent`/`common_fee`/`garbage_fee`/`pest_control_fee` を削除。`deposit` は残す）:

```php
        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:-3|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type_id'    => 'nullable|exists:inquiry_usage_types,id',
            'status'           => [
                'required',
                $isOccupied ? Rule::in([$unit->status->value]) : Rule::in(['vacant', 'negotiating']),
            ],
            // 募集家賃の4項目は「賃料改定」フローでのみ変更可能。編集では現値を保持するため
            // validation から除外する（送られても $validated に入らず update で無視される）。
            'deposit'          => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ]);
```

- [ ] **Step 4: null→0 変換ループを `deposit` のみに変更**

同 `update()` 内の null→0 ループ（約263-266行）:

```php
        // null → 0 変換（費用フィールド）
        foreach (['rent', 'common_fee', 'deposit', 'garbage_fee', 'pest_control_fee'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }
```

を次に置き換える（`deposit` のみ。除外した4項目は `$validated` に存在しないため触らない＝現値維持）:

```php
        // null → 0 変換（敷金のみ。募集家賃4項目は除外済みで現値を保持する）
        $validated['deposit'] = $validated['deposit'] ?? 0;
```

> ⚠ ここが Bug 防止の要。Task 9 で編集ビューの金額 input の `name` を外すと未送信になる。もし `update` 側で4項目を `?? 0` し続けると **未送信→null→0 で募集家賃がゼロ埋めされる事故**になる。ビューとコントローラの両方を必ず揃えること（設計書 §4）。

- [ ] **Step 5: テストを実行して PASS を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/UnitRentRevisionTest.php 2>&1 | tail -15`
Expected: 5件すべて PASS（OK (5 tests)）。

- [ ] **Step 6: 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l app/Http/Controllers/Tenant/UnitController.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add app/Http/Controllers/Tenant/UnitController.php tests/Feature/Tenant/UnitRentRevisionTest.php
git commit -m "feat(tenant): 区画編集で募集家賃4項目をロック(update から除外し現値維持)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `UnitController::show` — 統合履歴 `$rentHistory` 構築（TDD）

**Files:**
- Modify: `tests/Feature/Tenant/UnitRentRevisionTest.php`（テストメソッド追加）
- Modify: `app/Http/Controllers/Tenant/UnitController.php`（`show` の eager load 約154-160行 / compact 約196行 / 末尾に private `buildRentHistory` 追加）

- [ ] **Step 1: 失敗するテストを追加（募集＋契約の改定が日付降順でマージされる）**

`tests/Feature/Tenant/UnitRentRevisionTest.php` の `test_update_locks_amount_fields_but_allows_deposit` の直後（クラス閉じ括弧の前）に追加:

```php

    /** show が「募集家賃改定」と「その区画の契約家賃改定」を日付降順でマージして渡す */
    public function test_show_merges_asking_and_contract_revisions_desc(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('vacant');

        // 募集家賃改定（古い日付）
        UnitRentRevision::create([
            'unit_id'       => $unit->id,
            'revision_date' => '2026-05-01',
            'old_rent'      => 90000,
            'new_rent'      => 100000,
            'revised_by'    => $exec->id,
        ]);

        // この区画の契約（解約済み）＋ 契約家賃改定（新しい日付）
        $customer = Customer::create([
            'code'          => 'CUST-UR-001',
            'name'          => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
        $contract = Contract::create([
            'contract_number'  => 'C-UR-001',
            'department'       => 'tenant',
            'property_id'      => $unit->property_id,
            'unit_id'          => $unit->id,
            'customer_id'      => $customer->id,
            'status'           => 'terminated',
            'contract_date'    => '2025-04-01',
            'rent_start_date'  => '2025-04-01',
            'rent'             => 110000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
        ]);
        RentRevision::create([
            'contract_id'   => $contract->id,
            'revision_date' => '2026-06-01',
            'old_rent'      => 100000,
            'new_rent'      => 110000,
            'revised_by'    => $exec->id,
        ]);

        $response = $this->actingAs($exec)->get(route('tenant.units.show', $unit));
        $response->assertOk();

        $history = $response->viewData('rentHistory');
        $this->assertCount(2, $history);

        // 降順: 先頭=契約改定(2026-06-01), 次=募集改定(2026-05-01)
        $this->assertSame('contract', $history[0]['kind']);
        $this->assertSame(110000, $history[0]['new_rent']);
        $this->assertStringContainsString('C-UR-001', $history[0]['context_label']);

        $this->assertSame('asking', $history[1]['kind']);
        $this->assertSame(100000, $history[1]['new_rent']);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/UnitRentRevisionTest.php --filter test_show_merges_asking_and_contract_revisions_desc 2>&1 | tail -20`
Expected: FAIL（`show` がまだ `rentHistory` を渡さないため `viewData('rentHistory')` が null → `assertCount(2, null)` で失敗）。

- [ ] **Step 3: `show` の eager load に改定リレーションを追加**

`app/Http/Controllers/Tenant/UnitController.php` の `show()` 冒頭の load（約154-160行）:

```php
        $unit->load([
            'property',
            'activeContract.customer',
            'investments' => function ($q) {
                $q->orderByDesc('created_at')->orderByDesc('id');
            },
        ]);
```

を次に置き換える（募集改定＋全契約の契約改定を eager load）:

```php
        $unit->load([
            'property',
            'activeContract.customer',
            'investments' => function ($q) {
                $q->orderByDesc('created_at')->orderByDesc('id');
            },
            'rentRevisions.revisedByUser',
            'contracts.rentRevisions.revisedByUser',
            'contracts.customer',
        ]);
```

- [ ] **Step 4: `$rentHistory` を構築して view へ渡す**

同 `show()` の最後の `return view(...)`（約196行）:

```php
        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs', 'rentalIncome', 'unitInvestments'));
```

を次に置き換える:

```php
        // 募集家賃改定＋この区画の全契約の契約家賃改定を統合した履歴（日付降順）
        $rentHistory = $this->buildRentHistory($unit);

        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs', 'rentalIncome', 'unitInvestments', 'rentHistory'));
```

- [ ] **Step 5: private `buildRentHistory` メソッドを追加**

`UnitController` の `// プライベートメソッド` コメント直後・`generateDisplayName` の前に、以下を挿入する:

```php

    /**
     * 区画の家賃推移を統合した履歴を返す（募集家賃改定＋その区画の全契約の契約家賃改定）。
     * 各行を共通形に正規化し、改定日降順（同日は登録時刻降順）で並べる。
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildRentHistory(Unit $unit): \Illuminate\Support\Collection
    {
        // 募集家賃改定（区分: 募集）
        $asking = $unit->rentRevisions->map(function ($r) {
            return [
                'revision_date'        => $r->revision_date,
                'created_at'           => $r->created_at,
                'kind'                 => 'asking',
                'context_label'        => '募集家賃',
                'old_rent'             => $r->old_rent,
                'new_rent'             => $r->new_rent,
                'old_common_fee'       => $r->old_common_fee,
                'new_common_fee'       => $r->new_common_fee,
                'old_garbage_fee'      => $r->old_garbage_fee,
                'new_garbage_fee'      => $r->new_garbage_fee,
                'old_pest_control_fee' => $r->old_pest_control_fee,
                'new_pest_control_fee' => $r->new_pest_control_fee,
                'revised_by_name'      => $r->revisedByUser->name ?? '—',
            ];
        });

        // この区画の全契約（解約済み含む）の契約家賃改定（区分: 契約）
        $contractRevisions = $unit->contracts->flatMap(function ($contract) {
            return $contract->rentRevisions->map(function ($r) use ($contract) {
                return [
                    'revision_date'        => $r->revision_date,
                    'created_at'           => $r->created_at,
                    'kind'                 => 'contract',
                    'context_label'        => $contract->contract_number . ' / ' . ($contract->customer->name ?? '—'),
                    'old_rent'             => $r->old_rent,
                    'new_rent'             => $r->new_rent,
                    'old_common_fee'       => $r->old_common_fee,
                    'new_common_fee'       => $r->new_common_fee,
                    'old_garbage_fee'      => $r->old_garbage_fee,
                    'new_garbage_fee'      => $r->new_garbage_fee,
                    'old_pest_control_fee' => $r->old_pest_control_fee,
                    'new_pest_control_fee' => $r->new_pest_control_fee,
                    'revised_by_name'      => $r->revisedByUser->name ?? '—',
                ];
            });
        });

        // 2ソースをマージし、改定日降順（同日は created_at 降順）で整列
        return $asking->concat($contractRevisions)
            ->sortByDesc(function ($row) {
                return $row['revision_date']->format('Y-m-d') . ' ' . $row['created_at']->format('H:i:s');
            })
            ->values();
    }
```

> `revision_date`（Carbon・date キャスト）と `created_at`（Carbon・timestamp）を `Y-m-d H:i:s` の文字列キーにして `sortByDesc` する。辞書順＝時系列降順で一致する。`customer` は SoftDeletes だが `Contract::customer()` が `withTrashed()` 済みのため解約済み契約でも名前が取れる（無い場合のみ `—`）。

- [ ] **Step 6: テストを実行して PASS を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/UnitRentRevisionTest.php 2>&1 | tail -15`
Expected: 6件すべて PASS（OK (6 tests)）。

- [ ] **Step 7: 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l app/Http/Controllers/Tenant/UnitController.php`
Expected: `No syntax errors detected`

- [ ] **Step 8: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add app/Http/Controllers/Tenant/UnitController.php tests/Feature/Tenant/UnitRentRevisionTest.php
git commit -m "feat(tenant): 区画詳細に募集＋契約の家賃改定を統合した rentHistory を構築

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: `units/revise.blade.php` — 募集家賃改定フォーム（新規）

**Files:**
- Create: `resources/views/tenant/units/revise.blade.php`

> `contracts/revise.blade.php` の体裁を踏襲。違いは「対象が区画」「ソースが `$unit` の募集条件」「`return_to` 不要（常に区画詳細へ戻る）」の3点。`@json` や属性内 `&quot;` は使わない（Bug #21/#23/#26 回避）。

- [ ] **Step 1: 改定フォームのビューを作成**

Create `resources/views/tenant/units/revise.blade.php`:

```blade
@extends('layouts.app')

@section('title', '募集家賃の改定: ' . $unit->display_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.show', $unit->property) }}" class="hover:text-emerald-600 transition-colors">{{ $unit->property->name }}</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.units.show', $unit) }}" class="hover:text-emerald-600 transition-colors">区画: {{ $unit->display_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">賃料改定</span>
@endsection

@section('content')

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.units.show', $unit) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        区画詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">募集家賃の改定: {{ $unit->display_name }}</h1>

    {{-- 経営層のみの告知 --}}
    <div class="flex items-start gap-2 mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3.5">
        <svg class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <div class="text-sm text-blue-800">この操作は<strong>経営層のみ</strong>実行できます。改定内容は履歴に記録され、区画の募集条件が更新されます。</div>
    </div>

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 対象区画情報（読み取り専用） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">対象区画</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件 / 区画</div>
                <div class="text-sm font-medium text-gray-900">{{ $unit->property->name }} / {{ $unit->display_name }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="text-sm font-medium text-gray-900">{{ $unit->status->label() }}</div>
            </div>
        </div>
    </div>

    {{-- 現在の募集条件（読み取り専用） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">現在の募集条件</div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">募集家賃</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->rent) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">共益費</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->common_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ゴミ代</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->garbage_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">駆除代</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->pest_control_fee) }}円</div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('tenant.units.revise.execute', $unit) }}">
        @csrf

        {{-- 改定内容 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">改定内容</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">改定適用日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="revision_date" value="{{ old('revision_date') }}"
                           class="form-input w-full sm:max-w-[240px] h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・募集家賃<span class="text-red-600 ml-0.5">*</span></label>
                    <div class="relative">
                        <input type="number" name="new_rent" value="{{ old('new_rent', $unit->rent) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->rent) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・共益費</label>
                    <div class="relative">
                        <input type="number" name="new_common_fee" value="{{ old('new_common_fee', $unit->common_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->common_fee) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・ゴミ代</label>
                    <div class="relative">
                        <input type="number" name="new_garbage_fee" value="{{ old('new_garbage_fee', $unit->garbage_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->garbage_fee) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・駆除代</label>
                    <div class="relative">
                        <input type="number" name="new_pest_control_fee" value="{{ old('new_pest_control_fee', $unit->pest_control_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->pest_control_fee) }}円</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">改定理由</label>
                    <textarea name="reason" rows="3"
                              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                              placeholder="改定の理由を入力（任意）">{{ old('reason') }}</textarea>
                </div>
            </div>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.units.show', $unit) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                賃料改定を実行する
            </button>
        </div>
    </form>
@endsection
```

> 使用クラスはすべて `contracts/revise.blade.php`（本番稼働中）で実証済みの組み合わせ。`sm:max-w-[240px]` / `min-h-[80px]` / `h-[40px]` 等の任意値も同ファイルで使用＝ビルド済み。新規任意値クラスは追加しない。

- [ ] **Step 2: Blade 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l resources/views/tenant/units/revise.blade.php`
Expected: `No syntax errors detected`（本格検証は Task 10 の view:cache 後コンパイル lint）

- [ ] **Step 3: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add resources/views/tenant/units/revise.blade.php
git commit -m "feat(tenant): 募集家賃の賃料改定フォーム(units/revise)を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: `units/show.blade.php` — 「賃料改定」ボタン ＋「賃料改定履歴」タブ

**Files:**
- Modify: `resources/views/tenant/units/show.blade.php`（募集条件カードのタイトル 約132行 / タブ配列 約263-267行 / 修繕履歴タブの直後 約358行）

- [ ] **Step 1: 募集条件カードのタイトル行に「賃料改定」ボタンを追加**

`resources/views/tenant/units/show.blade.php` の現状（約132行）:

```blade
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">募集条件</div>
```

を次に置き換える（空室・商談中 ＋ 経営層のときのみボタン表示）:

```blade
        <div class="flex items-center justify-between pb-2 mb-3 border-b border-gray-200">
            <span class="text-sm font-bold text-gray-800">募集条件</span>
            @if(($unit->status === \App\Enums\UnitStatus::Vacant || $unit->status === \App\Enums\UnitStatus::Negotiating) && auth()->user()->role->isExecutive())
                <a href="{{ route('tenant.units.revise', $unit) }}"
                   style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; font-size:12px; font-weight:600; color:#b45309; border:1px solid #fde68a; border-radius:6px; text-decoration:none; background:#fff;">
                    <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    賃料改定
                </a>
            @endif
        </div>
```

> 入居中（Occupied）のときはこのボタンは出ない。入居中は「現在の契約条件」カードの契約改定ボタン（前機能・約178-184行）がそのまま使われる。スタイルは units/show の既存アンバー系ボタン（契約改定ボタン）と同一の inline style。

- [ ] **Step 2: タブ配列に「賃料改定履歴」を追加**

同ファイルのタブ配列（約263-267行）:

```blade
            @php
                $tabs = [
                    'contract' => '現在の契約',
                    'transactions' => '収支履歴',
                    'repairs' => '修繕履歴',
                ];
            @endphp
```

を次に置き換える:

```blade
            @php
                $tabs = [
                    'contract' => '現在の契約',
                    'transactions' => '収支履歴',
                    'repairs' => '修繕履歴',
                    'revisions' => '賃料改定履歴',
                ];
            @endphp
```

- [ ] **Step 3: 「賃料改定履歴」タブの中身を追加**

同ファイルの修繕履歴タブの閉じ `</div>`（約358行）と、タブコンテンツ全体を閉じる `</div>`（約360行）の間に、以下を挿入する。挿入位置の現状:

```blade
                @else
                    <p class="text-gray-400 text-center py-6">修繕履歴がありません。</p>
                @endif
            </div>

        </div>
    </div>
```

の「`@endif` 直後の `</div>`（修繕タブ閉じ）」と「空行」の後、`</div>`（`p-4` コンテナ閉じ）の前に挿入:

```blade

            {{-- 賃料改定履歴タブ（募集＋契約 統合） --}}
            <div x-show="activeTab === 'revisions'" x-cloak>
                @if($rentHistory->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-[13px]" style="min-width:820px">
                                <thead>
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">区分</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">改定日</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧家賃</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新家賃</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧共益費</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新共益費</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧ゴミ代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新ゴミ代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧駆除代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新駆除代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">改定者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rentHistory as $row)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap">
                                                @if($row['kind'] === 'asking')
                                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#fef3c7;color:#92400e;">募集</span>
                                                @else
                                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;">契約</span>
                                                    <span class="text-[11px] text-gray-500 ml-1">{{ $row['context_label'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ $row['revision_date']->format('Y/m/d') }}</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_rent']) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_rent']) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_common_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_common_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_garbage_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_garbage_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_pest_control_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_pest_control_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ $row['revised_by_name'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">賃料改定の履歴はありません。</p>
                @endif
            </div>
```

> 列構成は契約詳細の改定履歴（`contracts/show.blade.php` 約307-338行）に「区分」列を先頭追加したもの。`text-[13px]` / `text-[11px]` は同ファイル＆ units/show で既出＝ビルド済み。区分バッジは inline style（任意 Tailwind を増やさない）。`scroll-hint` 系は既存の横スクロールヒント（修繕履歴タブで使用中）を流用。

- [ ] **Step 4: Blade 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l resources/views/tenant/units/show.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add resources/views/tenant/units/show.blade.php
git commit -m "feat(tenant): 区画詳細に募集家賃改定ボタンと賃料改定履歴タブを追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: `units/edit.blade.php` — 金額4項目を表示専用にロック

**Files:**
- Modify: `resources/views/tenant/units/edit.blade.php`（募集条件セクション 約128-178行）

- [ ] **Step 1: 募集条件セクションを「金額4項目=表示専用 / 敷金=編集可」に置換**

`resources/views/tenant/units/edit.blade.php` の募集条件セクション全体（約128-178行）:

```blade
        {{-- 募集条件 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">募集条件</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">家賃（月額）</label>
                    <div class="relative">
                        <input type="number" name="rent" value="{{ old('rent', $unit->rent) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">共益費（月額）</label>
                    <div class="relative">
                        <input type="number" name="common_fee" value="{{ old('common_fee', $unit->common_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ゴミ代（月額）</label>
                    <div class="relative">
                        <input type="number" name="garbage_fee" value="{{ old('garbage_fee', $unit->garbage_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">駆除代（月額）</label>
                    <div class="relative">
                        <input type="number" name="pest_control_fee" value="{{ old('pest_control_fee', $unit->pest_control_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">敷金</label>
                    <div class="relative">
                        <input type="number" name="deposit" value="{{ old('deposit', $unit->deposit) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
            </div>
        </div>
```

を次に置き換える（家賃/共益費/ゴミ代/駆除代は `name` を持たない表示専用 div に、敷金は従来どおり編集可、注記＋経営層向けの改定導線を追加）:

```blade
        {{-- 募集条件（金額4項目は「賃料改定」からのみ変更可） --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-gray-200">
                <span class="text-sm font-bold text-gray-800">募集条件</span>
                @if(auth()->user()->role->isExecutive() && $unit->status !== \App\Enums\UnitStatus::Occupied)
                    <a href="{{ route('tenant.units.revise', $unit) }}" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">賃料改定で変更する →</a>
                @endif
            </div>
            <div class="flex items-start gap-2 mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                <svg class="w-4 h-4 text-amber-500 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <div class="text-xs text-amber-800">家賃・共益費・ゴミ代・駆除代は履歴管理のため<strong>「賃料改定」からのみ変更</strong>できます（ここでは変更できません）。</div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">家賃（月額）</label>
                    <div class="h-[40px] px-3 flex items-center bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ number_format($unit->rent) }}円</div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">共益費（月額）</label>
                    <div class="h-[40px] px-3 flex items-center bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ number_format($unit->common_fee) }}円</div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ゴミ代（月額）</label>
                    <div class="h-[40px] px-3 flex items-center bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ number_format($unit->garbage_fee) }}円</div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">駆除代（月額）</label>
                    <div class="h-[40px] px-3 flex items-center bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ number_format($unit->pest_control_fee) }}円</div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">敷金</label>
                    <div class="relative">
                        <input type="number" name="deposit" value="{{ old('deposit', $unit->deposit) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
            </div>
        </div>
```

> 金額4項目の input から `name` を外したので **送信されない** → Task 5 で `update` から4項目を除外済みのため現値が維持される（両者が揃って初めて安全。設計書 §4）。`amber-50/200/500/800` は前機能でビルド済み確認済み、`h-[40px]` は本ファイル他所で既出＝ビルド済み。`bg-gray-50` / `border-gray-200` / `flex items-center` は RULES.md の working classes。

- [ ] **Step 2: Blade 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l resources/views/tenant/units/edit.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add resources/views/tenant/units/edit.blade.php
git commit -m "feat(tenant): 区画編集で募集家賃4項目を表示専用にロック(変更は賃料改定経由)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: 総合検証 → main 反映 → 本番反映（スキーマ順序厳守）→ Playwright

**Files:** （検証・マージ・デプロイ・本番 SQL のみ）

- [ ] **Step 1: 全テストを実行（green 確認）**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit 2>&1 | tail -20`
Expected: 既存テスト＋新規 `UnitRentRevisionTest`（6件）すべて PASS。

- [ ] **Step 2: view:cache でコンパイルし、コンパイル済み PHP を `php -l`（Bug #26 ガード）**

Run:
```bash
cd /Users/masanori/site/manage && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear && echo "view lint done"
```
Expected: `INVALID:` の行が出ないこと＋末尾 `view lint done`。（本件は `@json`/属性内 `&quot;` を使わないが、Blade 追加のため全コンパイル後 lint で必ず担保する。`view:cache` の「成功」表示だけでは不十分＝Bug #26。）
> もし view:cache が vendor 由来で落ちる場合は [[project_local_vendor_corruption_viewcache]] を参照（framework 再インストールで切り分け）。

- [ ] **Step 3: 本番同期用に dev 依存を外す（デプロイ前に必須）**

Run: `cd /Users/masanori/site/manage && composer install --no-dev 2>&1 | tail -5`
Expected: 完了（vendor から dev パッケージが除去され本番同等に戻る。autoloader も再生成され `UnitRentRevision` を含む）。
> ⚠ この後はテスト実行不可（phpunit が消える）。テストを再実行したくなったら再度 `composer install` する。

- [ ] **Step 4: feature ブランチを `13.x` に fast-forward マージ**

Run: `cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only feature/tenant-unit-asking-rent-revision && git log --oneline -9`
Expected: fast-forward 成功。`13.x` の先頭に Task1〜9 の9コミットが乗る。

- [ ] **Step 5: 新規 PHP クラスのため autoloader を再生成（main repo の cwd で）**

Run: `cd /Users/masanori/site/manage && composer dump-autoload 2>&1 | tail -3`
Expected: `Generated optimized autoload files`。
> ⚠ 必ず main repo（`/Users/masanori/site/manage`）の cwd で実行する。worktree から実行すると `$baseDir` に worktree パスが焼き込まれ本番事故になる（CLAUDE.md）。

- [ ] **Step 6: ローカル MySQL にテーブルを作成（ローカル動作確認用・任意だが推奨）**

Run: `cd /Users/masanori/site/manage && sudo mysql manage < database/sql/2026-06-22-create-unit-rent-revisions.sql && echo "local schema applied"`
Expected: エラーなく `local schema applied`。確認: `sudo mysql manage -e "SHOW TABLES LIKE 'unit_rent_revisions';"` で1行返る。
> `sudo` 実行のためユーザー承認が要る。ローカルで区画詳細・改定を手元確認しない場合はスキップ可（本番確認は Step 8-9）。

- [ ] **Step 7: 本番 DB にテーブルを作成（⚠ デプロイより先・要ユーザー承認）**

> **順序厳守**: 新 `show()` は `unit_rent_revisions` を必ず参照する。テーブル不在のまま Step 8 のデプロイでコードを公開すると **区画詳細が全件 500**（Bug #22/#26 と同型の「本番だけ顕在化」）。よって**本番テーブル作成をデプロイより先に**行う。
>
> 本番 DB への適用方法はさくらレンタルの DB 接続情報（本番 `.env`）に依存し、過去の本番 DB 操作は**セッションごとの明示承認**が必要だった（[[project_prod_ssh_csh_diagnostics]] / [[project_zeal_hacomono_migration]]）。`.env` は読まない方針のため、以下のいずれかをユーザー承認のもとで実施する:
>   - 案A（推奨・無停止）: 本番 DB に対し `database/sql/2026-06-22-create-unit-rent-revisions.sql` の内容を先に流す（ローカルから本番 MySQL ホストへ、またはサーバー上の mysql クライアントで）。本番に SQL ファイルが未配置でも内容を流せばよい。
>   - 案B: 先に Step 8 の `./deploy.sh` で SQL ファイルを本番へ配送 → **直後に間髪入れず**本番で SQL を実行（区画詳細に短時間の 500 ウィンドウが出る可能性。案A を推奨）。
>
> 適用後、本番で `unit_rent_revisions` テーブルが存在することを確認してから Step 8 へ進む。

- [ ] **Step 8: 本番へデプロイ**

Run: `cd /Users/masanori/site/manage && ./deploy.sh`
Expected: rsync 転送 → 本番で `config:cache && route:cache && view:cache` 成功。
> `route:cache` 再生成で `tenant.units.revise(.execute)` が本番に反映される（git push だけでは不十分。Bug #20/#25 と同様）。

- [ ] **Step 9: 本番動作確認（Playwright または手動・経営層アカウント）**

確認シナリオ（本番 `https://www.mitsuwat.co.jp/system/manage`）:
1. 空室（または商談中）の区画詳細を開く → 「募集条件」カード右に「賃料改定」ボタンが表示される（経営層）。
2. ボタン押下 → 改定フォーム表示。パンくず・戻る・キャンセルが区画詳細を指す。現在の募集条件が4項目表示される。
3. 改定適用日＋新・募集家賃（＋共益費/ゴミ代/駆除代）を入力 →「賃料改定を実行する」→ 区画詳細に戻り成功メッセージ。募集条件カードの金額が更新されている。
4. 「賃料改定履歴」タブを開く → たった今の改定が「募集」区分で最上段に出る。過去に契約改定がある区画では「契約」区分の行も日付降順で混在表示される。
5. 区画の「編集」を開く → 家賃/共益費/ゴミ代/駆除代がグレーの表示専用＋「賃料改定からのみ変更」注記。敷金は入力可。更新しても金額4項目は変わらない（敷金・備考等のみ反映）。
6. 入居中の区画詳細では「募集条件」カードに賃料改定ボタンが出ず、「現在の契約条件」カードの契約改定ボタン（前機能）が従来どおり動く。
7. （回帰）契約一覧→契約詳細→「賃料改定」→ 実行が従来どおり動く（既存 `rent_revisions` フローに影響なし）。
8. （任意）一般担当/管理者アカウントで区画詳細・編集を開くと賃料改定ボタン/導線が出ない。

- [ ] **Step 10: feature ブランチを削除（任意・後片付け）**

Run: `cd /Users/masanori/site/manage && git branch -d feature/tenant-unit-asking-rent-revision`
Expected: `Deleted branch feature/tenant-unit-asking-rent-revision`
> `origin/13.x` への push はユーザーの明示指示があった時のみ。

---

## セルフレビュー結果（spec 突合）

- **spec §3.1 スキーマ（migration + 本番 raw SQL・追加のみ）** → Task 1（両ファイル作成・列/FK/null を rent_revisions ミラーで一致）✅
- **spec §3.2 モデル（UnitRentRevision ミラー + Unit::rentRevisions）** → Task 2 ✅
- **spec §3.3 ルート/コントローラ（revise GET/POST=role:executive・showReviseRent/reviseRent・show 拡張・update 金額ロック）** → Task 3（ルート）/ Task 4（revise）/ Task 6（show）/ Task 5（update ロック）✅
- **spec §3.4 ビュー（revise 新規・show ボタン＋タブ・edit ロック）** → Task 7 / Task 8 / Task 9 ✅
- **spec §3.5 統合履歴（2ソース正規化・revision_date 降順・同日 created_at 降順・区分列・空表示）** → Task 6 `buildRentHistory`（asking/contract を共通形へ・`Y-m-d H:i:s` キーで sortByDesc）＋ Task 8 タブ（区分列・空時「賃料改定の履歴はありません。」）✅
- **spec §4 安全性（入居中ガード・二重認可・追加のみ・ルートモデル束縛・編集ロックの0埋め事故防止）** → 入居中ガード=Task4（GET/POST 両方・test 2件）、認可=route `role:executive`＋ボタン `isExecutive()`＋ manager 403 test、0埋め防止=Task5（view の name 除去と update の除外を両方実施・コメントで明記）✅
- **spec §5 非対象（履歴編集削除/敷金改定/入居中の募集改定/既存フロー変更）** → いずれも未実装（store/create/契約フローは不変）✅
- **spec §6 テスト方針（改定実行/入居中ガード/manager403/編集ロック/統合履歴/コンパイル後 php -l）** → Task4-6 の test 6件＋ Task10 Step2 の view:cache 後 lint ✅
- **spec §7 ファイル一覧（10ファイル）** → ファイル構成表と一致（migration / sql / model新 / model変 / controller / routes / view新 / view変×2 / test）✅
- **spec §8 本番反映（deploy / 本番 SQL / dump-autoload / 確認）** → Task10（順序: テスト→view lint→--no-dev→ff merge→dump-autoload→**本番SQL(先)**→deploy→Playwright）✅

**プレースホルダ無し**: 全 Step に実コード・実コマンド・期待出力を記載。`TBD`/「適宜」等なし ✅
**型・名称整合**:
- ルート名 `tenant.units.revise` / `tenant.units.revise.execute` / `tenant.units.show` / `tenant.units.update` を全タスクで一貫使用 ✅
- メソッド名 `showReviseRent` / `reviseRent` / `buildRentHistory`、リレーション `rentRevisions`、区分値 `'asking'` / `'contract'`、配列キー（`kind` / `context_label` / `revised_by_name` / `old_*` / `new_*`）を Controller（Task6）と View（Task8）と Test（Task6）で一致 ✅
- import 追加（`UnitRentRevision` / `Auth` / `DB`）は Task4 で明示 ✅

**補足（実装者への注意）**:
- 本変更で「管理者（manager）は区画編集から金額4項目を変更できなくなる」（改定は経営層限定のため）。これは spec §2.4「金額変更は必ず改定フロー経由」＋ §3.3「ルートは role:executive」の意図どおりの仕様変更。
- `composer install --no-dev`（Step3）後に autoloader は再生成されるが、CLAUDE.md 規約に従い ff マージ後の main repo で `composer dump-autoload`（Step5）も実施する（新規クラスの確実な反映と baseDir 正常化）。
