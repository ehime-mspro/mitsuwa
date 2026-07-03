# ユーザー論理削除 & 担当者 assignable 統一 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 無効・削除ユーザーを担当者選択/絞り込みから除外し、ユーザー論理削除（SoftDeletes）・復元機能を新設する。過去レコードの担当者名は残す。

**Architecture:** `User` に `SoftDeletes` と 2 つの担当者クエリ（`scopeAssignable` = 有効かつ未削除 / `assignableWith($id)` = assignable ∪ 現在担当）を集約。全部署の担当者選択（新規=assignable / 編集=assignableWith / 一覧フィルタ=assignable）をこの 2 つに統一する。表示に使う `belongsTo(User)` リレーションへ `->withTrashed()` を付け履歴表示を守る（Bug #12 と同型）。削除/復元は既存 `toggleStatus` のガードと対称に実装。

**Tech Stack:** Laravel 12 / PHP 8.3(prod)・8.5(local) / MySQL 8(本番) + SQLite in-memory(テスト) / Blade + Alpine.js 3 / PHPUnit（RefreshDatabase）

**設計書:** `docs/superpowers/specs/2026-07-03-user-soft-delete-and-assignable-filtering-design.md`

---

## 実行前提（worktree セットアップ）

この計画は git worktree で実装する（`superpowers:using-git-worktrees`）。worktree には `vendor/` が無いためテスト実行前に一度だけ:

```bash
# worktree 作成後、worktree ルートで:
composer install                     # dev 依存込み（phpunit を含む）
[ -f .env ] || cp .env.example .env  # テストは phpunit.xml で sqlite :memory: を使うため DB 設定は不要
php artisan key:generate             # APP_KEY のみ必要
ls vendor/bin/phpunit                # 存在確認（引継ぎが --no-dev でも実際に入っているか）
```

テスト実行: `vendor/bin/phpunit --filter <TestClassOrMethod>`（全体は `vendor/bin/phpunit`）。

⚠ `composer dump-autoload` を **worktree で実行しない**（autoloader の baseDir が worktree パスに焼き付く）。新規クラス追加時のみ main repo cwd で実行するが、本計画は新規 PHP クラスを追加しない（メソッド/スコープ追加のみ）。

---

## ファイル構成マップ

| 区分 | ファイル | 責務 |
|---|---|---|
| データ層 | `database/sql/2026-07-03-add-deleted-at-to-users.sql`（新規） | live DB（ローカル/本番）へ `deleted_at` 追加 |
| データ層 | `database/migrations/0001_01_01_000000_create_users_table.php`（改修） | テスト/新規 migrate 用に `softDeletes()` 追加 |
| モデル | `app/Models/User.php`（改修） | `SoftDeletes` trait・`scopeAssignable`・`assignableWith` |
| 表示履歴 | 担当 `belongsTo(User)` を持つ 9 モデル（改修） | 削除済み担当を `->withTrashed()` で表示継続 |
| 表示履歴 | `mansion/contracts/revise.blade.php` / `parking-contracts/revise.blade.php`（改修） | `User::find` → `withTrashed()->find` |
| 担当選択 | 担当者選択 8 コントローラ（改修） | create/index=assignable、edit=assignableWith |
| 担当選択 | edit 系 7 Blade（改修） | 現在担当が無効/削除時に「（無効）（削除済み）」注記 |
| 削除機能 | `routes/web.php`（改修） | `admin.users.destroy` / `admin.users.restore` |
| 削除機能 | `app/Http/Controllers/Admin/UserController.php`（改修） | `destroy` / `restore` / `index` の削除済みフィルタ |
| 削除機能 | `resources/views/admin/users/index.blade.php`（改修） | 削除ボタン・復元ボタン・削除確認モーダル・「削除済み」フィルタ |
| テスト | `tests/Feature/Admin/UserAssignableScopeTest.php`（新規） | scope / assignableWith |
| テスト | `tests/Feature/Admin/UserSoftDeleteTest.php`（新規） | 表示リレーション / 削除・復元 / ガード / フィルタ |

---

## Task 1: データ層 + User モデル基盤

`users.deleted_at` を **2 系統**（live DB 用 raw SQL ＋ テスト DB 用 migration）に追加し、`User` へ SoftDeletes・`scopeAssignable`・`assignableWith` を実装する。以降の全タスクの土台。

**Files:**
- Create: `database/sql/2026-07-03-add-deleted-at-to-users.sql`
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`（`$table->timestamps();` 直後）
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Admin/UserAssignableScopeTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Admin/UserAssignableScopeTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAssignableScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignable_excludes_inactive_and_deleted_users(): void
    {
        $active   = User::factory()->create(['status' => UserStatus::Active->value]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive->value]);
        $deleted  = User::factory()->create(['status' => UserStatus::Active->value]);
        $deleted->delete();

        $ids = User::assignable()->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($deleted->id));
    }

    public function test_assignable_with_null_returns_only_assignable(): void
    {
        $active   = User::factory()->create(['status' => UserStatus::Active->value]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive->value]);

        $ids = User::assignableWith(null)->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_assignable_with_includes_current_inactive_user(): void
    {
        $active   = User::factory()->create(['status' => UserStatus::Active->value]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive->value]);

        $ids = User::assignableWith($inactive->id)->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertTrue($ids->contains($inactive->id));
    }

    public function test_assignable_with_includes_current_deleted_user(): void
    {
        $deleted = User::factory()->create(['status' => UserStatus::Active->value]);
        $deleted->delete();

        $ids = User::assignableWith($deleted->id)->pluck('id');

        $this->assertTrue($ids->contains($deleted->id));
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter UserAssignableScopeTest`
Expected: FAIL（`Call to undefined method ... assignable()`。migration に deleted_at が無ければ先に `no such column: deleted_at` になる場合もある。どちらも「未実装による失敗」）

- [ ] **Step 3: migration に softDeletes を追加**

`database/migrations/0001_01_01_000000_create_users_table.php` の `$table->timestamps();` の直後に 1 行追加:

```php
            $table->timestamps();
            $table->softDeletes(); // 論理削除（担当者履歴を残すため。live DB は raw SQL で別途追加）
```

- [ ] **Step 4: live DB 用 raw SQL を作成**

`database/sql/2026-07-03-add-deleted-at-to-users.sql` を新規作成:

```sql
-- users に deleted_at を追加（論理削除 / SoftDeletes）
-- テスト DB は migration 側（create_users_table の softDeletes）で構築されるため、この SQL は既存 live DB 専用。
-- 適用（ローカル）: sudo mysql masa8787kanri63732 < database/sql/2026-07-03-add-deleted-at-to-users.sql
-- 適用（本番）    : 本番 DB に同 SQL を実行（要ユーザー明示承認・csh）
ALTER TABLE `users`
  ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
```

> このタスクでは SQL ファイルの**作成のみ**。ローカル/本番 DB への適用は Task 7（検証）で行う。テストは migration から SQLite を構築するので、この時点でテストは通る。

- [ ] **Step 5: User モデルに SoftDeletes・scope・assignableWith を実装**

`app/Models/User.php` を編集。

(a) import 追加（`use Illuminate\Notifications\Notifiable;` の下）:

```php
use Illuminate\Database\Eloquent\SoftDeletes;
```

(b) trait に追加:

```php
    use HasFactory, Notifiable, SoftDeletes;
```

(c) `casts()` の戻り配列に 1 行追加:

```php
            'deleted_at' => 'datetime',
```

(d) `belongsToDepartment()` メソッドの後（クラス末尾の `}` の直前）にスコープとヘルパーを追加:

```php
    // ============================================================
    // スコープ / 担当者候補
    // ============================================================

    /**
     * 担当者として選択可能なユーザー = 有効かつ未削除。
     * 削除済みは SoftDeletes のグローバルスコープが自動的に除外する。
     */
    public function scopeAssignable($query)
    {
        return $query->where('status', UserStatus::Active->value);
    }

    /**
     * 担当者候補 = assignable ∪ 指定した現在担当者（無効/削除済みでも必ず含める）。
     * 編集フォームで現在の担当が候補から消えて担当が飛ぶのを防ぐ（Bug #12 対策）。
     * $currentId が null のときは assignable のみを返す。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public static function assignableWith(?int $currentId = null): \Illuminate\Database\Eloquent\Collection
    {
        $assignable = static::assignable()->orderBy('name')->get();

        if ($currentId !== null && ! $assignable->contains('id', $currentId)) {
            $current = static::withTrashed()->find($currentId);
            if ($current !== null) {
                $assignable->push($current);
                $assignable = $assignable->sortBy('name')->values();
            }
        }

        return $assignable;
    }
```

- [ ] **Step 6: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter UserAssignableScopeTest`
Expected: PASS（4 tests）

- [ ] **Step 7: コミット**

```bash
git add database/sql/2026-07-03-add-deleted-at-to-users.sql \
        database/migrations/0001_01_01_000000_create_users_table.php \
        app/Models/User.php \
        tests/Feature/Admin/UserAssignableScopeTest.php
git commit -m "feat(admin): User に SoftDeletes と assignable/assignableWith を追加

users に deleted_at を追加（テスト用 migration + live DB 用 raw SQL）。
担当者選択の母集団を scopeAssignable（有効かつ未削除）と
assignableWith（assignable ∪ 現在担当）に集約する土台。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: 過去レコードの担当者名表示（withTrashed）

削除済み担当者を「担当者」として表示している 9 リレーションと Blade 直参照 2 箇所に `->withTrashed()` を付け、論理削除後も名前が消えないようにする。

**確定 9 リレーション（担当として表示）:**

| モデル:行 | メソッド | 外部キー |
|---|---|---|
| `app/Models/ReContract.php:80` | `staff()` | `staff_user_id` |
| `app/Models/MsContract.php:45` | `staff()` | `staff_user_id` |
| `app/Models/MsParkingContract.php:47` | `staff()` | `staff_user_id` |
| `app/Models/DadProject.php:66` | `staffUser()` | `staff_user_id` |
| `app/Models/BuyerSurvey.php:42` | `staff()` | `staff_user_id` |
| `app/Models/Inquiry.php:82` | `assignedUser` | `assigned_to` |
| `app/Models/Contract.php:115` | `assignedUser` | `assigned_to` |
| `app/Models/HsContract.php:57` | `createdBy`（住宅の担当=登録者） | `created_by` |
| `app/Models/HsCustomOrder.php:89` | `createdBy`（住宅の担当=登録者） | `created_by` |

**Files:**
- Modify: 上記 9 モデル
- Modify: `resources/views/mansion/contracts/revise.blade.php:558`
- Modify: `resources/views/mansion/parking-contracts/revise.blade.php:476`
- Test: `tests/Feature/Admin/UserSoftDeleteTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Admin/UserSoftDeleteTest.php` を新規作成（migration 管理の `Contract::assignedUser` を代表に検証。`re_contracts` 等は raw SQL 管理でテスト DB に無いため使わない）:

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** assigned_to 付きの tenant 契約を 1 件作成（既存 ContractReviseEntryTest の seeding を踏襲） */
    private function makeContractAssignedTo(int $userId): Contract
    {
        $customer = Customer::create([
            'code' => 'CUST-SD-001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
        $property = Property::create([
            'code' => 'PROP-SD-001',
            'name' => 'テストビル',
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);
        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A',
            'display_name' => '1A',
            'status' => 'occupied',
        ]);

        return Contract::create([
            'contract_number' => 'C-SD-001',
            'department' => 'tenant',
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => 'active',
            'contract_date' => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'rent' => 100000,
            'assigned_to' => $userId,
        ]);
    }

    public function test_assigned_user_relation_returns_soft_deleted_user(): void
    {
        $staff = User::factory()->create([
            'name' => '田中太郎',
            'status' => UserStatus::Active->value,
        ]);
        $contract = $this->makeContractAssignedTo($staff->id);

        $staff->delete(); // 論理削除

        $contract->refresh()->load('assignedUser');

        $this->assertNotNull(
            $contract->assignedUser,
            '削除済み担当者は withTrashed で解決され null にならないこと'
        );
        $this->assertSame('田中太郎', $contract->assignedUser->name);
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest::test_assigned_user_relation_returns_soft_deleted_user`
Expected: FAIL（`assignedUser` が null → `assertNotNull` 失敗。SoftDeletes のグローバルスコープが削除済みを除外するため）

- [ ] **Step 3: 9 リレーションに withTrashed を付与**

各モデルの担当リレーションに `->withTrashed()` を追加する。例（`Contract.php:115`）:

```php
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }
```

同様に以下すべての `return $this->belongsTo(User::class, '<fk>');` を `->withTrashed()` 付きに変更する:
- `ReContract.php:80`（staff_user_id）
- `MsContract.php:45`（staff_user_id）
- `MsParkingContract.php:47`（staff_user_id）
- `DadProject.php:66`（staff_user_id）
- `BuyerSurvey.php:42`（staff_user_id）
- `Inquiry.php:82`（assigned_to）
- `Contract.php:115`（assigned_to）
- `HsContract.php:57`（created_by）
- `HsCustomOrder.php:89`（created_by）

> 各ファイルの当該行だけを `->withTrashed()` 付きにする。`updated_by` 等の他リレーションは Task 3 で扱うので触らない。

- [ ] **Step 4: Blade 直参照 2 箇所を修正**

`resources/views/mansion/contracts/revise.blade.php:558` と `resources/views/mansion/parking-contracts/revise.blade.php:476` の
`\App\Models\User::find($rev->created_by)` を以下に変更（削除済みを null 返ししないため）:

```php
\App\Models\User::withTrashed()->find($rev->created_by)
```

- [ ] **Step 5: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest::test_assigned_user_relation_returns_soft_deleted_user`
Expected: PASS

- [ ] **Step 6: コミット**

```bash
git add app/Models/ReContract.php app/Models/MsContract.php app/Models/MsParkingContract.php \
        app/Models/DadProject.php app/Models/BuyerSurvey.php app/Models/Inquiry.php \
        app/Models/Contract.php app/Models/HsContract.php app/Models/HsCustomOrder.php \
        resources/views/mansion/contracts/revise.blade.php \
        resources/views/mansion/parking-contracts/revise.blade.php \
        tests/Feature/Admin/UserSoftDeleteTest.php
git commit -m "fix(admin): 削除済み担当者を過去レコードで表示継続（withTrashed）

担当として表示する belongsTo(User) 9 本と mansion 改定履歴の
User::find 直参照に withTrashed を付与。論理削除後も担当者名が
消えないようにする（Bug #12 と同型）。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: 監査カラム（created_by/updated_by 系）の表示リレーション監査

Task 2 で扱った 9 本以外の `belongsTo(User)`（`updated_by` / `uploaded_by` / `revised_by` / `registered_by` / `imported_by` / `changed_by` / `deleted_by` 等）のうち、**画面に名前を表示しているものだけ**に `->withTrashed()` を付ける。表示していない純粋な監査カラムは対象外。

**Files:**
- Modify: 監査で「表示あり」と判明した各モデル（0〜N 本）

- [ ] **Step 1: 候補リレーションを列挙**

Run:
```bash
grep -rn "belongsTo(User" app/Models/ | grep -vE "staff_user_id|assigned_to|'created_by'.*HsContract|'created_by'.*HsCustomOrder"
```
Expected: `ReProject` / `ReProcurement` / `ReProjectDrawing` / `ZealSimulation` / `Transaction` / `InquiryHistory` / `HsPropertyFile` / `HsCustomOrderFile` / `RentRevision` / `UnitRentRevision` / `ZealSheetImport` / `PropertyChangeLog` / `Attachment` ほかの `created_by`/`updated_by` 系が並ぶ（Task 2 の 9 本は含まれない想定）。

- [ ] **Step 2: 各リレーションが Blade で表示されているか確認**

各リレーションのメソッド名（例: `updatedBy`, `uploadedBy`, `revisedBy`）について、名前を描画しているかを調べる:
```bash
# 例: updatedBy を表示している Blade を探す
grep -rn "->updatedBy\|->uploadedBy\|->revisedBy\|->registeredBy\|->importedBy\|->changedBy\|->createdBy" resources/views/
```
判定基準:
- **表示している**（`{{ $x->updatedBy?->name }}` 等、名前を出す）→ `->withTrashed()` を付ける。
- **クエリ制約に使っている**（`whereHas`、`->updatedBy()->where(...)` 等ロジック用）→ **付けない**（削除済みを含めると挙動が変わるため）。
- **どこにも使っていない** → 付けない（YAGNI）。

- [ ] **Step 3: 「表示あり」のリレーションにのみ withTrashed を付与**

該当した各モデルの当該リレーションの return 文にチェーンを 1 つ足す:
```php
// before
return $this->belongsTo(User::class, 'updated_by');
// after
return $this->belongsTo(User::class, 'updated_by')->withTrashed();
```

> このタスクは 0 本になる可能性もある（表示が全て Task 2 でカバー済みの場合）。その場合は「監査の結果、追加対象なし」をコミットメッセージに記し、コード変更なしでスキップしてよい。

- [ ] **Step 4: 構文チェック**

Run: `php -l` を変更した各モデルに対して実行、または `git diff --name-only | grep '\.php$' | xargs -I{} php -l {}`
Expected: `No syntax errors detected` が各ファイルで出る

- [ ] **Step 5: コミット（変更があった場合のみ）**

```bash
git add app/Models/
git commit -m "fix(admin): 監査履歴で表示する登録者/更新者にも withTrashed を付与

created_by/updated_by 系のうち画面に名前を表示しているリレーションのみ
削除済みユーザーを解決するよう withTrashed を追加（表示なし・クエリ用は除外）。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: 担当者選択の assignable 統一（新規/一覧フィルタ）

新規登録フォームと一覧の絞り込みフィルタが参照する 8 サイトを `User::assignable()` に統一する（無効・削除済みを除外）。**編集サイトは Task 5 で扱う**ため、ここでは触らない。

**Files（create / index-filter サイトのみ）:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php:92`(index), `:129`(create)
- Modify: `app/Http/Controllers/Housing/HsContractListController.php:127`(index)
- Modify: `app/Http/Controllers/Dad/ProjectController.php:67`(index), `:146`(create)
- Modify: `app/Http/Controllers/Mansion/ContractController.php:79`(create)
- Modify: `app/Http/Controllers/Mansion/ParkingContractController.php:89`(create)
- Modify: `app/Http/Controllers/Tenant/InquiryController.php:107`(create)
- Modify: `app/Http/Controllers/CustomerController.php:681`(getStaffList — create/housing 専用)

- [ ] **Step 1: index / create サイトを assignable に置換**

以下の各行を機械的に置換する。

`User::orderBy('name')->get()` 形式（ReContract:92, HsContractList:127, Dad:67, Dad:146, Mansion/Contract:79, Mansion/Parking:89）:
```php
// before
$staffUsers = User::orderBy('name')->get();
// after
$staffUsers = User::assignable()->orderBy('name')->get();
```

`User::orderBy('name')->get(['id', 'name'])` 形式（ReContract:129）:
```php
// after
$staffUsers = User::assignable()->orderBy('name')->get(['id', 'name']);
```

`Tenant/InquiryController.php:107`（既存の where を assignable へ表現統一）:
```php
// before
$users = User::where('status', 'active')->orderBy('name')->get(['id', 'name']);
// after
$users = User::assignable()->orderBy('name')->get(['id', 'name']);
```

`CustomerController.php:681`（getStaffList 本体・create/housing 専用なので assignable のみ）:
```php
// before
$users = User::orderBy('name')
    ->get(['id', 'name']);
// after
$users = User::assignable()->orderBy('name')
    ->get(['id', 'name']);
```

> ⚠ 各コントローラの **create/index メソッド内のみ**を変更する。edit/editBuilding/editCustomOrder は Task 5。行番号は Task 5 分の変更前なので、置換は「メソッド名 + 該当式」で照合して確実に create/index 側を選ぶこと（分類表は設計書 §2.2 と本計画 Task 5 を参照）。

- [ ] **Step 2: 構文チェック**

Run: `git diff --name-only | grep '\.php$' | xargs -I{} php -l {}`
Expected: 各ファイル `No syntax errors detected`

- [ ] **Step 3: 回帰テスト（既存 scope テストで意味を担保）**

Run: `vendor/bin/phpunit --filter UserAssignableScopeTest`
Expected: PASS（scope の意味は Task 1 で担保済み。ここではコントローラ配線が壊れていないことを構文＋既存テストで確認）

- [ ] **Step 4: コミット**

```bash
git add app/Http/Controllers/RealEstate/ReContractController.php \
        app/Http/Controllers/Housing/HsContractListController.php \
        app/Http/Controllers/Dad/ProjectController.php \
        app/Http/Controllers/Mansion/ContractController.php \
        app/Http/Controllers/Mansion/ParkingContractController.php \
        app/Http/Controllers/Tenant/InquiryController.php \
        app/Http/Controllers/CustomerController.php
git commit -m "feat(admin): 新規登録・一覧フィルタの担当者選択を assignable に統一

不動産/住宅/DAD/賃貸マンション/テナント問合せ/買主の新規・絞り込みで
無効・削除ユーザーを除外（User::assignable()）。編集画面は別コミット。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: 編集フォームの現在担当保持（assignableWith）＋注記

編集サイトを `User::assignableWith($currentId)` に統一し、現在担当が無効/削除でも候補に残す。edit 系 Blade の `<option>` に「（無効）（削除済み）」注記を付ける（Bug #12 の本丸）。

**Files（edit サイト）:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php:271`(edit / staff_user_id)
- Modify: `app/Http/Controllers/Housing/HsContractListController.php:200`(editBuilding / created_by), `:299`(editCustomOrder / created_by)
- Modify: `app/Http/Controllers/Dad/ProjectController.php:185`(edit / staff_user_id)
- Modify: `app/Http/Controllers/Mansion/ContractController.php:139`(edit / staff_user_id)
- Modify: `app/Http/Controllers/Mansion/ParkingContractController.php:132`(edit / staff_user_id)
- Modify: `app/Http/Controllers/Tenant/InquiryController.php:247`(edit / assigned_to)
- Modify: `app/Http/Controllers/CustomerSurveyController.php:273`(getStaffList — create+edit 共有)
- Modify（Blade 注記）: `realestate/contracts/edit.blade.php`, `housing/contracts/edit-building.blade.php`, `housing/contracts/edit-custom-order.blade.php`, `dad/projects/_form.blade.php`, `mansion/contracts/_form.blade.php`, `mansion/parking-contracts/_form.blade.php`, `tenant/inquiries/edit.blade.php`
- Test: `tests/Feature/Admin/UserSoftDeleteTest.php`（追記）

- [ ] **Step 1: 失敗する統合テストを追記**

`tests/Feature/Admin/UserSoftDeleteTest.php` に、tenant 問合せ編集で無効な現在担当が候補に残ることを検証するテストを追加（`inquiries` は migration 管理）。まず Inquiry の必須列を確認:
```bash
grep -nE "nullable|->(string|integer|date|enum|foreignId|boolean|text)" database/migrations/2026_03_28_000002_create_inquiries_table.php
```
確認した必須列に合わせて `makeInquiryAssignedTo()` を組み、以下を追記する（`assigned_to` と必須列のみ埋める。下記は代表列。実スキーマの NOT NULL に合わせて過不足を調整）:

```php
    public function test_inquiry_edit_keeps_inactive_current_assignee_in_candidates(): void
    {
        $exec = User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $inactive = User::factory()->create([
            'name'   => '退職花子',
            'status' => UserStatus::Inactive->value,
        ]);

        $inquiry = $this->makeInquiryAssignedTo($inactive->id);

        $response = $this->actingAs($exec)->get(route('tenant.inquiries.edit', $inquiry));

        $response->assertOk();
        $users = collect($response->viewData('users'));
        $this->assertTrue(
            $users->contains('id', $inactive->id),
            '無効な現在担当者が編集候補に残ること（担当が飛ばない）'
        );
    }
```

`makeInquiryAssignedTo(int $userId): Inquiry` ヘルパーも同ファイルに追加する（`Inquiry::create([...必須列..., 'assigned_to' => $userId])`。Property/Unit/Customer が FK 必須なら `makeContractAssignedTo` と同じ要領で親を作る）。

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest::test_inquiry_edit_keeps_inactive_current_assignee_in_candidates`
Expected: FAIL（現状 edit は `where('status','active')` で無効担当を除外 → 候補に含まれず `assertTrue` 失敗）

- [ ] **Step 3: edit コントローラを assignableWith に置換**

各 edit サイトを現在担当 FK 付きの `assignableWith` に変更する。

`ReContractController.php:271`（edit は `edit(ReContract $contract)` = `$contract`。担当は staff_user_id）:
```php
$staffUsers = User::assignableWith($contract->staff_user_id);
```

`HsContractListController.php:200`（editBuilding / $hsContract->created_by）:
```php
$staffUsers = User::assignableWith($hsContract->created_by);
```
`HsContractListController.php:299`（editCustomOrder / $hsCustomOrder->created_by）:
```php
$staffUsers = User::assignableWith($hsCustomOrder->created_by);
```
`DadProjectController.php:185`（$project->staff_user_id）:
```php
$staffUsers = User::assignableWith($project->staff_user_id);
```
`Mansion/ContractController.php:139`（$contract->staff_user_id）:
```php
'staffUsers' => User::assignableWith($contract->staff_user_id),
```
`Mansion/ParkingContractController.php:132`（$parkingContract->staff_user_id）:
```php
'staffUsers' => User::assignableWith($parkingContract->staff_user_id),
```
`Tenant/InquiryController.php:247`（$inquiry->assigned_to）:
```php
$users = User::assignableWith($inquiry->assigned_to);
```

> `assignableWith` は全カラムの User モデルを返す（Blade で `$su->status`/`$su->trashed()` を参照するため必須）。旧 `->get(['id','name'])` の列制限は使わない。

- [ ] **Step 4: getStaffList をパラメータ化（CustomerSurveyController）**

`CustomerSurveyController.php:271` の `getStaffList` を編集し、現在担当を含められるようにする。

シグネチャと母集団取得を変更:
```php
    private function getStaffList(?int $currentId = null): array
    {
        $users = User::assignableWith($currentId);
        // ... 既存の $lastNames 集計ループはそのまま（$users を走査）...
```
表示ラベル生成部（`$result[$u->id] = ...` の箇所）で、苗字/フルネーム決定後に注記を付ける:
```php
        // 既存の苗字 or フルネーム決定ロジックの直後、$result への代入前に:
        if ($u->trashed()) {
            $displayName .= '（削除済み）';
        } elseif ($u->status === \App\Enums\UserStatus::Inactive) {
            $displayName .= '（無効）';
        }
        $result[$u->id] = $displayName;
```
呼び出し側:
- `CustomerSurveyController.php:53`（create）: `$this->getStaffList()` のまま（現在担当なし）。
- `CustomerSurveyController.php:149`（edit）: `$this->getStaffList($survey->staff_user_id)` に変更。

> `UserStatus` を未 import なら `use App\Enums\UserStatus;` を追加（`\App\Enums\UserStatus` 完全修飾でも可）。

- [ ] **Step 5: edit 系 Blade に注記を追加**

各 edit 系 Blade の担当 `<option>` ラベル（`{{ $su->name }}` 等）に注記を付ける。まず対象行を洗い出す:
```bash
grep -rn "}}</option>" resources/views/realestate/contracts/edit.blade.php \
    resources/views/housing/contracts/edit-building.blade.php \
    resources/views/housing/contracts/edit-custom-order.blade.php \
    resources/views/dad/projects/_form.blade.php \
    resources/views/mansion/contracts/_form.blade.php \
    resources/views/mansion/parking-contracts/_form.blade.php \
    resources/views/tenant/inquiries/edit.blade.php
```
担当者 `<option>` の名前出力を、ループ変数に合わせて次のように変更する（`realestate/contracts/edit.blade.php` は `$su`、他は各ループ変数に合わせる。例は `$su`）:
```blade
{{-- before --}}
>{{ $su->name }}</option>
{{-- after --}}
>{{ $su->name }}@if($su->trashed())（削除済み）@elseif($su->status === \App\Enums\UserStatus::Inactive)（無効）@endif</option>
```
対象:
- `realestate/contracts/edit.blade.php`（担当 option。2 箇所ある `$su->name` の担当セレクト。物件/契約以外の無関係 select は対象外）
- `housing/contracts/edit-building.blade.php` / `edit-custom-order.blade.php`（担当=登録者 select。`selected` 判定は `created_by` 基準）
- `dad/projects/_form.blade.php`（共有 partial。create では全員 active なので @if は不発火）
- `mansion/contracts/_form.blade.php` / `mansion/parking-contracts/_form.blade.php`（共有 partial）
- `tenant/inquiries/edit.blade.php`（`$users` ループの担当 option）

> surveys（`buyers/surveys/edit.blade.php`）は getStaffList のラベルに注記済みのため Blade 変更不要。

- [ ] **Step 6: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest`
Expected: PASS（リレーション表示 + 問合せ編集の現在担当保持）

- [ ] **Step 7: 構文チェック**

Run: `git diff --name-only | grep '\.php$' | xargs -I{} php -l {}`
Expected: 各ファイル `No syntax errors detected`

- [ ] **Step 8: コミット**

```bash
git add app/Http/Controllers/ resources/views/ tests/Feature/Admin/UserSoftDeleteTest.php
git commit -m "fix(admin): 編集画面で無効/削除の現在担当を候補に保持し注記表示

編集フォームの担当者候補を assignableWith（assignable ∪ 現在担当）にし、
現在担当が無効/削除でも option を残す（Bug #12）。option ラベルに
「（無効）（削除済み）」を注記。CustomerSurvey の getStaffList を
現在担当対応にパラメータ化。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: ユーザー削除・復元機能

`UserController` に `destroy`（論理削除）/`restore`（復元）を追加し、一覧に「削除済み」フィルタと削除/復元 UI を実装する。ガードは既存 `toggleStatus` と対称。

**Files:**
- Modify: `routes/web.php`（`admin.users.toggleStatus`(86 行) の直後）
- Modify: `app/Http/Controllers/Admin/UserController.php`（`index` 改修 + `destroy`/`restore` 追加）
- Modify: `resources/views/admin/users/index.blade.php`
- Test: `tests/Feature/Admin/UserSoftDeleteTest.php`（追記）

- [ ] **Step 1: 失敗するテストを追記**

`tests/Feature/Admin/UserSoftDeleteTest.php` に削除/復元/ガード/フィルタのテストを追加:

```php
    private function executive(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => \App\Enums\UserRole::Executive->value,
            'status' => UserStatus::Active->value,
            'must_change_password' => false,
        ], $overrides));
    }

    public function test_destroy_soft_deletes_user(): void
    {
        $exec = $this->executive();
        $exec2 = $this->executive(); // 最後の経営層ガードに引っかからないよう 2 人目
        $target = User::factory()->create(['status' => UserStatus::Active->value]);

        $this->actingAs($exec)
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSoftDeleted($target);
    }

    public function test_cannot_delete_self(): void
    {
        $exec = $this->executive();
        $exec2 = $this->executive();

        $this->actingAs($exec)->delete(route('admin.users.destroy', $exec));

        $this->assertNotSoftDeleted($exec);
    }

    public function test_can_delete_executive_when_another_active_exists(): void
    {
        $actor  = $this->executive();
        $target = $this->executive(); // 2 人目の有効経営層

        $this->actingAs($actor)->delete(route('admin.users.destroy', $target));

        $this->assertSoftDeleted($target); // 最後の 1 人ではないので削除可
    }

    public function test_cannot_delete_last_active_executive(): void
    {
        // CheckRole は role のみ判定（status 不問）なので、操作者を「無効な経営層」にしても
        // role:executive を通過する。これで対象を唯一の有効経営層にでき、ガードを純粋に検証できる。
        $actor = User::factory()->create([
            'role'   => \App\Enums\UserRole::Executive->value,
            'status' => UserStatus::Inactive->value,
            'must_change_password' => false,
        ]);
        $soleActiveExec = $this->executive(); // 唯一の有効経営層

        $this->actingAs($actor)->delete(route('admin.users.destroy', $soleActiveExec));

        $this->assertNotSoftDeleted($soleActiveExec);
    }

    public function test_restore_brings_back_user(): void
    {
        $exec = $this->executive();
        $exec2 = $this->executive();
        $target = User::factory()->create(['status' => UserStatus::Active->value]);
        $target->delete();

        $this->actingAs($exec)
            ->patch(route('admin.users.restore', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertNotSoftDeleted($target->fresh());
    }

    public function test_index_deleted_filter_shows_only_trashed(): void
    {
        $exec = $this->executive();
        $active = User::factory()->create(['name' => 'ザイセキ一郎', 'status' => UserStatus::Active->value]);
        $trashed = User::factory()->create(['name' => 'サクジョ二郎', 'status' => UserStatus::Active->value]);
        $trashed->delete();

        // 既定（未削除のみ）
        $default = $this->actingAs($exec)->get(route('admin.users.index'));
        $default->assertOk();
        $this->assertTrue(collect($default->viewData('users')->items())->contains('id', $active->id));
        $this->assertFalse(collect($default->viewData('users')->items())->contains('id', $trashed->id));

        // status=deleted（削除済みのみ）
        $deleted = $this->actingAs($exec)->get(route('admin.users.index', ['status' => 'deleted']));
        $deleted->assertOk();
        $this->assertTrue(collect($deleted->viewData('users')->items())->contains('id', $trashed->id));
        $this->assertFalse(collect($deleted->viewData('users')->items())->contains('id', $active->id));
    }
```

> `CheckRole`（`app/Http/Middleware/CheckRole.php`）は `role` のみを判定し status を見ないため、`actingAs` で無効な経営層を操作者にすれば `role:executive` を通過でき、最後の有効経営層ガードを素直に検証できる。`self` ガードは 2 人目の経営層を置くことで最後の経営層ガードと分離して検証する。

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest`
Expected: FAIL（`Route [admin.users.destroy] not defined` 等）

- [ ] **Step 3: ルートを追加**

`routes/web.php` の `admin.users.toggleStatus`（86 行）の直後に追加:

```php
        Route::delete('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])
            ->name('admin.users.destroy');
        Route::patch('/users/{user}/restore', [\App\Http\Controllers\Admin\UserController::class, 'restore'])
            ->name('admin.users.restore')
            ->withTrashed();
```

> `restore` の `->withTrashed()` は必須（無いと削除済み `{user}` が binding で解決されず常に 404）。
> グループ冒頭コメント（72 行付近）「システム管理（10ルート）」の件数を実数に更新する。

- [ ] **Step 4: UserController::index を削除済みフィルタ対応に改修**

`index()` の冒頭 `$query = User::with('departments');`（21 行）を、status フィルタ分岐に置き換える:

```php
        // status フィルタ: 'deleted' は SoftDeletes の onlyTrashed、それ以外は未削除（既定スコープ）
        if ($request->status === 'deleted') {
            $query = User::onlyTrashed()->with('departments');
        } else {
            $query = User::with('departments');
            if (in_array($request->status, [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
                $query->where('status', $request->status);
            }
        }
```
そして既存の「状態絞り込み」ブロック（36〜39 行の `if ($request->filled('status')) { $query->where('status', ...); }`）を**削除**する（上の分岐に統合したため）。role/department/search フィルタ・`orderByRaw`・`paginate` はそのまま。

- [ ] **Step 5: UserController に destroy / restore を追加**

`resetPassword()` メソッドの後（`generatePassword()` の前）に追加:

```php
    /**
     * ユーザー削除（論理削除）
     * Route: DELETE /admin/users/{user}
     */
    public function destroy(User $user)
    {
        // 自分自身は削除不可
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', '自分自身を削除することはできません。');
        }

        // 最後の有効な経営層は削除不可（無効化ガードと対称）
        if ($user->role === UserRole::Executive && $user->status === UserStatus::Active) {
            $otherActiveExecutives = User::where('id', '!=', $user->id)
                ->where('role', UserRole::Executive->value)
                ->where('status', UserStatus::Active->value)
                ->count();

            if ($otherActiveExecutives === 0) {
                return redirect()->route('admin.users.index')
                    ->with('error', '唯一の有効な経営層ユーザーは削除できません。');
            }
        }

        $user->delete(); // SoftDeletes → deleted_at をセット

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を削除しました。「削除済み」から復元できます。");
    }

    /**
     * ユーザー復元
     * Route: PATCH /admin/users/{user}/restore（ルートで withTrashed 解決済み）
     */
    public function restore(User $user)
    {
        $user->restore(); // deleted_at を null 化（status は削除前の値を維持）

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を復元しました。");
    }
```

- [ ] **Step 6: テストが通ることを確認（コントローラ層）**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest`
Expected: PASS（Blade はまだ削除/復元ボタン未実装だが、テストは HTTP 経由の DB 状態と index の viewData を見るため通る）

- [ ] **Step 7: 一覧 Blade に「削除済み」フィルタ・削除/復元 UI・削除モーダルを追加**

`resources/views/admin/users/index.blade.php` を編集。

(a) status フィルタ `<select>`（79〜83 行付近）の enum ループ `@foreach` の直後・`</select>` の前に「削除済み」option を追加:
```blade
                <option value="deleted" {{ request('status') === 'deleted' ? 'selected' : '' }}>削除済み</option>
```

(b) 状態バッジ（127〜131 行）を trashed 対応に変更:
```blade
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap text-center">
                            @if($u->trashed())
                                <span class="inline-block px-2 rounded text-[11px] font-medium bg-gray-200 text-gray-600" style="padding-top:2px; padding-bottom:2px;">削除済み</span>
                            @else
                                <span class="inline-block px-2 rounded text-[11px] font-medium
                                    {{ $u->status === App\Enums\UserStatus::Active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}
                                " style="padding-top:2px; padding-bottom:2px;">{{ $u->status->label() }}</span>
                            @endif
                        </td>
```

(c) 操作セル（135〜166 行）を trashed 分岐に変更。削除済み行は「復元」のみ、通常行は既存 + 「削除」ボタン:
```blade
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 text-right whitespace-nowrap">
                            @if($u->trashed())
                                {{-- 削除済み行: 復元のみ --}}
                                <form action="{{ url('admin/users/'.$u->id.'/restore') }}" method="POST" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="text-[12px] text-emerald-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal">復元</button>
                                </form>
                            @else
                                {{-- 編集 --}}
                                <button
                                    @click="openEditModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }}, {{ \Illuminate\Support\Js::from($u->email) }}, '{{ $u->role->value }}', {{ \Illuminate\Support\Js::from($u->departments->pluck('id')->values()) }}, '{{ $u->status->value }}')"
                                    class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                >編集</button>

                                @if($u->id !== auth()->id())
                                    {{-- PW再発行 --}}
                                    <span class="text-gray-200 mx-1">|</span>
                                    <button
                                        @click="openResetModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                        class="text-[12px] text-amber-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                    >PW再発行</button>

                                    {{-- 無効化/有効化 --}}
                                    <span class="text-gray-200 mx-1">|</span>
                                    @if($u->status === App\Enums\UserStatus::Active)
                                        <button
                                            @click="openDisableModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                            class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                        >無効化</button>
                                    @else
                                        <button
                                            @click="openEnableModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                            class="text-[12px] text-emerald-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                        >有効化</button>
                                    @endif

                                    {{-- 削除 --}}
                                    <span class="text-gray-200 mx-1">|</span>
                                    <button
                                        @click="openDeleteModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                        class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                    >削除</button>
                                @endif
                            @endif
                        </td>
```

(d) 有効化モーダル（395〜419 行）の後（`</div>` の直後・`<script>` の前）に削除確認モーダルを追加:
```blade
    {{-- ========== 削除確認モーダル ========== --}}
    <div x-show="deleteModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="deleteModal = false" class="bg-white rounded-xl w-full max-w-[400px] shadow-xl mx-4">
            <form :action="'{{ url('admin/users') }}/' + deleteUserId" method="POST">
                @csrf
                @method('DELETE')
                <div class="px-6 py-6 text-center">
                    <div class="w-11 h-11 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-[22px] h-[22px] text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </div>
                    <p class="text-[14px] text-gray-700 mb-1"><strong x-text="deleteUserName"></strong> さんを削除しますか？</p>
                    <p class="text-[12px] text-gray-400 mb-4 leading-relaxed">削除するとログイン・担当者選択に表示されなくなります。過去の担当履歴は残り、「削除済み」から復元できます。</p>
                    <div class="flex justify-center gap-2">
                        <button type="button" @click="deleteModal = false"
                                class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                        <button type="submit"
                                class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">削除する</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
```

(e) Alpine data（`userManagement()` の return オブジェクト）に状態とメソッドを追加。`enableModal: false,`（431 行）の下に:
```javascript
        deleteModal: false,
```
`toggleUserName: '',`（450 行）の下に:
```javascript

        // 削除
        deleteUserId: null,
        deleteUserName: '',
```
`openEnableModal(...)`（503〜507 行）の後にメソッドを追加（直前メソッドの `}` の後にカンマを付けるのを忘れない）:
```javascript
,

        // 削除モーダル
        openDeleteModal(id, name) {
            this.deleteUserId = id;
            this.deleteUserName = name;
            this.deleteModal = true;
        }
```

- [ ] **Step 8: Blade をコンパイルして構文検証（Bug #26 対策）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` が 1 件も出ない（`Blade templates cached successfully` の表示だけでは不十分）

- [ ] **Step 9: 全テストが通ることを確認**

Run: `vendor/bin/phpunit --filter UserSoftDeleteTest`
Expected: PASS（削除/復元/ガード/フィルタ）

- [ ] **Step 10: コミット**

```bash
git add routes/web.php app/Http/Controllers/Admin/UserController.php \
        resources/views/admin/users/index.blade.php \
        tests/Feature/Admin/UserSoftDeleteTest.php
git commit -m "feat(admin): ユーザー論理削除・復元機能を追加

destroy（論理削除）/restore（復元）とガード（自分自身・最後の有効経営層は
削除不可）を実装。一覧に status=deleted フィルタ（onlyTrashed）、行に削除/復元
ボタンと削除確認モーダルを追加。restore ルートは withTrashed で解決。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: 統合検証 & デプロイ準備

横断的な完全性確認・Blade コンパイル検証・全テスト・DB 適用手順の確定。

- [ ] **Step 1: 未フィルタ担当者選択の残存ゼロを確認**

Run:
```bash
grep -rn "User::orderBy('name')" app/Http/Controllers/
grep -rn "User::where('status', 'active')" app/Http/Controllers/
```
Expected: いずれも **0 件**（全て `assignable()` / `assignableWith()` に置換済み）。残っていれば見落とし → 該当メソッドを分類して修正。

- [ ] **Step 2: User::find の削除済み除外リスクを横展開確認**

Run: `grep -rn "User::find(" app/ resources/`
Expected: 担当者名を表示する箇所は `withTrashed()->find()` になっている。表示以外（存在チェック等）はそのままで可。表示用に素の `find` が残っていれば `withTrashed()->find` に修正。

- [ ] **Step 3: Blade コンパイル全体検証（Bug #26）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` が 0 件

- [ ] **Step 4: 全テストスイート**

Run: `vendor/bin/phpunit`
Expected: 既存テスト含め全 PASS（回帰なし）

- [ ] **Step 5: ローカル DB に deleted_at を適用**

> テストは SQLite なので通るが、ローカルの実 DB（`masa8787kanri63732`）でアプリを動かすには raw SQL の適用が必要。

Run: `sudo mysql masa8787kanri63732 < database/sql/2026-07-03-add-deleted-at-to-users.sql`
Expected: エラーなし（既に適用済みなら `Duplicate column` は無視可）。確認: `sudo mysql masa8787kanri63732 -e "SHOW COLUMNS FROM users LIKE 'deleted_at';"` で 1 行返る。

- [ ] **Step 6: ローカル動作確認（任意・実データがあれば）**

無効/削除担当の付いた契約の詳細・編集をブラウザで開き、担当者名が「田中（削除済み）」等で表示され、編集で担当が飛ばないことを確認（空ローカルで素通りする本番 500 = Bug #22/#25 を避ける）。

- [ ] **Step 7: main への FF-merge とデプロイ（ユーザー承認後）**

```bash
# main repo (/Users/masanori/site/manage) で:
git checkout 13.x && git merge --ff-only <worktree-branch>
# 新規 PHP クラスは無いので composer dump-autoload は不要
./deploy.sh
```
> **本番 DB へ deleted_at を別途適用する必要がある**（`./deploy.sh` は SQL を流さない）。本番 ssh は csh・要ユーザー明示承認で `database/sql/2026-07-03-add-deleted-at-to-users.sql` を実行。順序は「SQL 適用 → deploy（route:cache/view:cache 再生成）」が安全。

- [ ] **Step 8: origin/13.x への push（ユーザー明示指示時のみ）**

---

## Self-Review 記録

- **Spec 6 セクション網羅**: §4.1 データ層→Task 1 / §4.2 assignable→Task 1(scope)+Task 4 / §4.3 編集保持→Task 1(assignableWith)+Task 5 / §4.4 withTrashed→Task 2+Task 3 / §4.5 削除復元→Task 6 / §5 テスト→各 Task の TDD + Task 7。
- **型整合**: `scopeAssignable($query)`・`assignableWith(?int): EloquentCollection`・`getStaffList(?int $currentId = null): array` を全タスクで一貫使用。
- **既知の注意**: `restore` ルートの `->withTrashed()`（Task 6 Step 3）、`deleted_at` の 2 系統書き込み（Task 1 Step 3-4）、住宅の担当=`created_by`（Task 2/5）を明示済み。
- **edit 変数名を実シグネチャで確定**: ReContract=`$contract`（`$reContract` ではない）/ Dad=`$project` / Mansion契約=`$contract` / Mansion駐車場=`$parkingContract` / 問合せ=`$inquiry` / 住宅=`$hsContract`・`$hsCustomOrder`。
- **最後の経営層ガードのテスト**: `CheckRole` が status 不問（role のみ）のため、操作者を無効な経営層にして唯一の有効経営層を削除しようとするケースで純粋に検証可能（Task 6 Step 1）。
- **プレースホルダ排除**: 全 code ステップに実コード。監査タスク（Task 3）は変更 0 本もあり得る点を明示し、その場合スキップ可とした。
