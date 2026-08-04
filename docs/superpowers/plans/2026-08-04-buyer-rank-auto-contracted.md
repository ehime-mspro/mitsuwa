# 契約登録時に買主の顧客ランクを自動で「成約」にする — 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 不動産契約・建売契約・注文住宅の登録／編集で買主が紐づいたとき、その部署の顧客ランク（`buyer_departments.rank`）を自動で `contracted`（成約）にする。

**Architecture:** コントローラ 6 箇所ではなく**契約モデル 3 つの `saved` イベント**に置く（1 箇所書き忘れても画面は正常に動いて無音で漏れるため。Bug #41 / #44）。共通処理は `Buyer::markContracted()` に集約し、既存の `getDepartmentPivot()` / `addToDepartment()` の上に組む。注文住宅だけは「登録時」ではなく `CustomOrderStatus::isContractedOrLater()` が真になった時に発火する。

**Tech Stack:** Laravel 12 / PHP 8.3 / Eloquent モデルイベント / PHPUnit 11（SQLite in-memory）

**設計書:** `docs/superpowers/specs/2026-08-04-buyer-rank-auto-contracted-design.md`（決定事項 4 点は確定済み・変更不可）

---

## File Structure

| 区分 | パス | 責務 |
|---|---|---|
| Modify | `app/Models/Buyer.php` | `markContracted()` を追加（部署ランク更新規則の**唯一の実装**） |
| Modify | `app/Models/ReContract.php` | 既存 `booted()` に `saved` フックを 1 つ追加 |
| Modify | `app/Models/HsContract.php` | `booted()` を新設し `saved` フックを追加 |
| Modify | `app/Models/HsCustomOrder.php` | `booted()` を新設し `saved` フックを追加（契約以降のみ） |
| Modify | `tests/Concerns/CreatesRealEstateSchema.php` | `buyer_departments` テーブルを追加 |
| Create | `tests/Feature/BuyerMarkContractedTest.php` | `markContracted()` の規則そのもの（4 本） |
| Create | `tests/Feature/RealEstate/ReContractBuyerRankTest.php` | 不動産契約の入口（10 本） |
| Create | `tests/Feature/Housing/HousingContractBuyerRankTest.php` | 建売・注文住宅の入口（9 本） |
| Create | `tests/Feature/ContractModelEventPathTest.php` | 契約 3 テーブルがクエリビルダ更新されていないことの走査（1 本） |

**DB スキーマ変更は無い。** `buyer_departments` は既存テーブル、`contracted` は既存の ENUM 値。

**⚠ `Buyer::markContracted()` を「3 モデルから呼ぶ 1 メソッド」に保つこと。** 各モデルに規則をコピーすると
Bug #41（同じ処理の経路が複数あり片方だけ直す）を自分で作り込むことになる。

---

## Task 0: テスト実行環境の用意

**Files:**
- Create: `.env`（worktree 内・`.gitignore` 済みなのでコミットされない）

worktree には `vendor` が無く、main repo の `vendor` は `--no-dev`（`phpunit` が入っていない）。
`phpunit.xml` が `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` を強制するので、
worktree に MySQL 認証情報が無くても Feature テストは走る（むしろ実 DB に触れないので安全）。

- [ ] **Step 1: テスト専用のダミー `.env` を置く**

`.env` は `.gitignore:3` に入っているのでコミットされない。**APP_KEY 以外は書かない**
（DB 認証情報を書くと実 MySQL に到達しうる。`phpunit.xml` の sqlite 指定が効くとはいえ、
到達不能にしておくほうが安全）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" > .env
```

- [ ] **Step 2: dev 依存込みで composer install**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && composer install
```

- [ ] **Step 3: 既存テストが全部緑であることを確認（着手前のベースライン）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit
```

期待: `OK` または `Tests: N, Assertions: M`（失敗 0）。
**⚠ ここで赤があるなら先に原因を潰す。** 着手後に赤が出たとき「元からか自分のせいか」が分からなくなる。
テスト総数をメモしておく（Task 8 で「増えた分だけ増えた」ことを確認する）。

---

## Task 1: テスト用スキーマに `buyer_departments` を追加

**Files:**
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`（`buyers` の直後、47 行目付近に挿入）

`buyer_departments` は本番では raw SQL 管理で Laravel マイグレーションに無い（`database/migrations/` に
`buyer` を含むファイルは 0 件・実測済み）。テスト（SQLite in-memory）に存在しないと、
Task 3 以降のフックが `no such table` で落ちる。

**⚠ このトレイトは既存 22 本のテストが使っている。** テーブルを足すのは加算的なので既存には無害だが、
`buyer_id` を持つ `re_contracts` を作る既存テスト（`ProcurementStatusTransitionTest` ほか）は
Task 3 以降で**新しいフックを通るようになる**。テーブルが無いとそれらが巻き添えで落ちるので、
スキーマ追加を先に済ませる。

- [ ] **Step 1: `buyers` の `Schema::create` 直後に `buyer_departments` を追加**

`tests/Concerns/CreatesRealEstateSchema.php` の 45 行目 `});`（`buyers` の閉じ）と
47 行目 `Schema::create('re_suppliers', ...)` の間に挿入する:

```php
        // 買主×部署の紐付け（ランク・取得日）。本番も raw SQL 管理でマイグレーションに無い。
        // 実 DB（実測）:
        //   id / buyer_id / department enum('housing','realestate') / acquired_date date NOT NULL
        //   / rank enum('A','B','C','D','lost','unreachable','contracted') default 'C'
        //   / created_at timestamp（CURRENT_TIMESTAMP 既定値）
        //   + UNIQUE (buyer_id, department)
        Schema::create('buyer_departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('buyer_id');
            // MySQL の enum は SQLite に無いので string で代替（re_contracts.department と同じ方針）
            $t->string('department', 20);
            $t->date('acquired_date');
            $t->string('rank', 20)->default('C');
            // ⚠ 本番は CURRENT_TIMESTAMP の DB 既定値を持つが SQLite に無いので nullable にする。
            //    BuyerDepartmentPivot は $timestamps = false なので Laravel 側からは書き込まれない。
            $t->timestamp('created_at')->nullable();
            $t->unique(['buyer_id', 'department'], 'uq_buyer_department');
        });
```

- [ ] **Step 2: 既存テストが引き続き全部緑であることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit
```

期待: Step 0-3 と同じテスト数・失敗 0。

- [ ] **Step 3: コミット**

`/commit` を使う（commit-commands プラグイン）。手動なら:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git add tests/Concerns/CreatesRealEstateSchema.php && git commit -m "test(buyers): テスト用スキーマに buyer_departments を追加"
```

---

## Task 2: `Buyer::markContracted()` — 部署ランク更新規則

**Files:**
- Modify: `app/Models/Buyer.php`（`use` に `App\Enums\BuyerRank` 追加 / `getDepartmentPivot()` の直後にメソッド追加）
- Test: `tests/Feature/BuyerMarkContractedTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/BuyerMarkContractedTest.php` を新規作成:

```php
<?php

namespace Tests\Feature;

use App\Enums\BuyerRank;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * Buyer::markContracted() の規則そのものを固定する（設計書 §4）。
 *
 * ここは「規則が正しいか」だけを見る。**その規則に実際に到達するか**は入口ごとに別テストで測る
 * （ReContractBuyerRankTest / HousingContractBuyerRankTest）。片方だけでは足りない — Bug #44。
 */
class BuyerMarkContractedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function makeBuyer(string $lastName = '山田'): Buyer
    {
        return Buyer::create(['last_name' => $lastName, 'first_name' => '太郎']);
    }

    private function pivot(Buyer $buyer, string $department): ?BuyerDepartmentPivot
    {
        return BuyerDepartmentPivot::where('buyer_id', $buyer->id)
            ->where('department', $department)
            ->first();
    }

    /** 決定1: 対象部署の行が無い顧客は、その部署を成約ランクで自動追加する（取得日＝渡した日付）。 */
    public function test_missing_department_row_is_created_as_contracted_with_given_date(): void
    {
        $buyer = $this->makeBuyer();

        $buyer->markContracted('realestate', '2026-07-19');

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertNotNull($pivot, '部署行が自動作成されていない');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-07-19', $pivot->acquired_date->toDateString());
    }

    /**
     * 取得日を渡さない場合は当日日付を使う（acquired_date は NOT NULL）。
     *
     * ⚠ 凍結日は「実際の今日」と違う日にすること。今日と同じ日付を選ぶと、
     *    フォールバックが壊れていても緑になり、テストが何も証明しない。
     *
     * ⚠ travelTo() の引数は DateTimeInterface 型なので文字列は渡せない（TypeError になる）。
     */
    public function test_missing_date_falls_back_to_today(): void
    {
        $this->travelTo(Carbon::parse('2030-03-15'));
        $buyer = $this->makeBuyer();

        $buyer->markContracted('realestate', null);

        $this->assertSame('2030-03-15', $this->pivot($buyer, 'realestate')->acquired_date->toDateString());
    }

    /**
     * 既存行は rank だけ上書きし、acquired_date は書き換えない。
     *
     * ⚠ 取得日は「いつ獲得した顧客か」という独立した実データで、契約日で潰すと
     *    獲得経路の履歴が失われる（設計書 §4）。
     */
    public function test_existing_row_keeps_its_acquired_date(): void
    {
        $buyer = $this->makeBuyer();
        BuyerDepartmentPivot::create([
            'buyer_id'      => $buyer->id,
            'department'    => 'realestate',
            'acquired_date' => '2026-01-05',
            'rank'          => BuyerRank::B->value,
        ]);

        $buyer->markContracted('realestate', '2026-07-19');

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-01-05', $pivot->acquired_date->toDateString(), '取得日が契約日で上書きされている');
    }

    /** 他決・追客不可も無条件で成約に上書きする（契約したという事実が最も強い。設計書 §4）。 */
    public function test_lost_and_unreachable_ranks_are_overwritten(): void
    {
        foreach ([BuyerRank::Lost, BuyerRank::Unreachable] as $i => $rank) {
            $buyer = $this->makeBuyer('上書き' . $i);
            BuyerDepartmentPivot::create([
                'buyer_id'      => $buyer->id,
                'department'    => 'housing',
                'acquired_date' => '2026-01-05',
                'rank'          => $rank->value,
            ]);

            $buyer->markContracted('housing', '2026-07-19');

            $this->assertSame(
                BuyerRank::Contracted,
                $this->pivot($buyer, 'housing')->rank,
                $rank->value . ' が成約に上書きされていない',
            );
        }
    }
}
```

- [ ] **Step 2: 落ちることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/BuyerMarkContractedTest.php
```

期待: 4 本とも FAIL（`Call to undefined method App\Models\Buyer::markContracted()`）。

- [ ] **Step 3: `Buyer.php` に `use` を追加**

`app/Models/Buyer.php` の 3〜6 行目を差し替える:

```php
namespace App\Models;

use App\Enums\BuyerRank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
```

- [ ] **Step 4: `markContracted()` を実装**

`app/Models/Buyer.php` の `getDepartmentPivot()`（182-185 行）の直後、クラス閉じ括弧の前に追加:

```php

    /**
     * 指定部署の顧客ランクを「成約」にする。
     *
     * - 対象部署の行が無ければ、その部署を成約ランクで追加する
     *   （取得日は $acquiredDate、無ければ当日。buyer_departments.acquired_date は NOT NULL）。
     * - 既にある行は rank だけ上書きする。A〜D だけでなく**他決・追客不可も対象**
     *   （契約したという事実が最も強いため。設計書 §4）。
     *
     * ⚠ 既存行の acquired_date は書き換えない。取得日は「いつ獲得した顧客か」という
     *    独立した実データで、契約日で潰すと獲得経路の履歴が失われる。
     *
     * ⚠ 既に contracted の行は UPDATE を出さずに抜ける。これは無駄な UPDATE を避けるだけで
     *    **挙動は同じ**なので、この早期 return を消しても回帰テストは緑のまま
     *    （＝テストで守られていない。消さないこと）。
     */
    public function markContracted(string $department, ?string $acquiredDate = null): void
    {
        $pivot = $this->getDepartmentPivot($department);

        if ($pivot === null) {
            $this->addToDepartment(
                $department,
                $acquiredDate ?: now()->toDateString(),
                BuyerRank::Contracted->value,
            );
            return;
        }

        if ($pivot->rank === BuyerRank::Contracted) {
            return;
        }

        $pivot->update(['rank' => BuyerRank::Contracted->value]);
    }
```

- [ ] **Step 5: 緑になることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/BuyerMarkContractedTest.php
```

期待: `OK (4 tests, ...)`。

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git add app/Models/Buyer.php tests/Feature/BuyerMarkContractedTest.php && git commit -m "feat(buyers): 部署ランクを成約にする Buyer::markContracted を追加"
```

---

## Task 3: `ReContract` の `saved` フック（不動産契約）

**Files:**
- Modify: `app/Models/ReContract.php`（`use` に `App\Enums\BuyerDepartment` 追加 / `booted()` 内に `saved` 追加）
- Test: `tests/Feature/RealEstate/ReContractBuyerRankTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/RealEstate/ReContractBuyerRankTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\BuyerRank;
use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 不動産契約の入口で買主ランクが「成約」になることを固定する（設計書 §7.1 #1-5, #11-15）。
 *
 * ⚠ 実装はモデルイベント 1 箇所だが、**その入口が実際にイベントを通るか**は入口ごとに
 *    確かめないと分からない（クエリビルダ経由の update はイベントを通らない）。Bug #44。
 *
 * ⚠ 各テストは必ず「リクエストが成功したこと」を assert する。バリデーションで弾かれた場合、
 *    「ランクが変わらない」系のテストは**壊れていても緑**になる（false-pass）。
 *
 * ⚠ 編集系は HTTP 経由なのでルートモデルバインディングで DB から取り直したインスタンスが渡る。
 *    直接 $model->update() を書くと wasRecentlyCreated が true のまま残り、
 *    wasChanged ガードの変異を隠してしまう（Bug #39）。HTTP を通すこと。
 */
class ReContractBuyerRankTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    // ---------------- ヘルパー ----------------

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 買主を作る。$departments は ['realestate' => ['rank' => 'B', 'acquired_date' => '2026-01-05']] の形。
     */
    private function makeBuyer(string $lastName, array $departments = []): Buyer
    {
        $buyer = Buyer::create(['last_name' => $lastName, 'first_name' => '太郎']);

        foreach ($departments as $dept => $spec) {
            BuyerDepartmentPivot::create([
                'buyer_id'      => $buyer->id,
                'department'    => $dept,
                'acquired_date' => $spec['acquired_date'] ?? '2026-01-05',
                'rank'          => $spec['rank'] ?? BuyerRank::C->value,
            ]);
        }

        return $buyer;
    }

    private function pivot(Buyer $buyer, string $department): ?BuyerDepartmentPivot
    {
        return BuyerDepartmentPivot::where('buyer_id', $buyer->id)
            ->where('department', $department)
            ->first();
    }

    private function rankOf(Buyer $buyer, string $department): ?BuyerRank
    {
        return $this->pivot($buyer, $department)?->rank;
    }

    /** 査定・購入価格は入れない（saved フックの syncPropertyPurchaseCost を無効化して素の状態に保つ）。 */
    private function makeProcurement(string $code = 'PRC-RANK-001'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => $code,
            'property_type'    => RealEstatePropertyType::UsedHouse->value,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => 'ランク検証物件',
            'address'          => '愛媛県松山市山西町53-18',
            'created_by'       => 1,
        ]);
    }

    private function makeLot(): ReProjectLot
    {
        $project = ReProject::create([
            'project_code' => 'PRJ-RANK-001',
            'project_name' => 'ランク検証分譲地',
            'status'       => ProjectStatus::Selling->value,
            'address'      => '愛媛県松山市石井町1-2-3',
            'created_by'   => 1,
        ]);

        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => 1,
            'area_sqm'   => 132.69,
            'area_tsubo' => 40.13,
        ]);
    }

    private function procurementPayload(ReProcurement $proc, Buyer $buyer, array $overrides = []): array
    {
        return array_merge([
            'contract_type'        => ReContractType::ProcurementHouse->value,
            'procurement_id'       => $proc->id,
            'contract_date'        => '2026-07-19',
            'buyer_id'             => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'          => 25000000,
            'property_name'        => 'ランク検証物件',
        ], $overrides);
    }

    // ---------------- #1 / #2 / #3: 登録の入口 ----------------

    /** #1 仕入れ系の契約登録で買主が成約になる。 */
    public function test_procurement_contract_store_marks_buyer_contracted(): void
    {
        $buyer = $this->makeBuyer('仕入', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc  = $this->makeProcurement();

        $response = $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'realestate'));
    }

    /** #2 分譲地の契約登録で買主が成約になる。 */
    public function test_subdivision_contract_store_marks_buyer_contracted(): void
    {
        $buyer = $this->makeBuyer('分譲', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $lot   = $this->makeLot();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'        => ReContractType::SubdivisionLot->value,
            'project_id'           => $lot->project_id,
            'lot_id'               => $lot->id,
            'contract_date'        => '2026-07-19',
            'buyer_id'             => $buyer->id,
            'contract_amount_land' => 20000000,
            'cost_amount'          => 15000000,
            'property_name'        => 'ランク検証分譲地 1号地',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'realestate'));
    }

    /**
     * #3 仲介登録では誰のランクも変わらない。
     *
     * ⚠ これは「仲介が対象外である」という仕様の記録であって、特定の変異を捕まえるテストではない
     *    （仲介は buyer_id を持たないので、buyer_id ガードを外しても挙動は変わらない）。
     *    将来 仲介に買主マスタを繋ぐなら、この前提から見直すこと（設計書 §3.1）。
     */
    public function test_brokerage_contract_store_changes_no_rank(): void
    {
        $buyer = $this->makeBuyer('仲介', ['realestate' => ['rank' => BuyerRank::C->value]]);

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'           => ReContractType::Brokerage->value,
            'property_name'           => '仲介物件',
            'brokerage_selling_price' => 18000000,
            'brokerage_fee'           => 600000,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertDatabaseCount('re_contracts', 1);
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'realestate'));
    }

    // ---------------- #4 / #5: 編集で買主を差し替え ----------------

    /** #4 契約編集で買主を差し替えると、新しい買主が成約になる。 */
    public function test_swapping_buyer_on_update_marks_new_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('旧', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('新', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc     = $this->makeProcurement();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/realestate/contracts', $this->procurementPayload($proc, $oldBuyer))
            ->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($user)->put(
            '/realestate/contracts/' . $contract->id,
            $this->procurementPayload($proc, $newBuyer),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($newBuyer, 'realestate'));
    }

    /** #5 決定2: 買主を差し替えても、元の買主は成約のまま（差し戻さない）。 */
    public function test_swapping_buyer_leaves_previous_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('旧', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('新', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc     = $this->makeProcurement();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/realestate/contracts', $this->procurementPayload($proc, $oldBuyer))
            ->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $this->actingAs($user)
            ->put('/realestate/contracts/' . $contract->id, $this->procurementPayload($proc, $newBuyer))
            ->assertSessionHasNoErrors();

        $this->assertSame(BuyerRank::Contracted, $this->rankOf($oldBuyer, 'realestate'), '元の買主が差し戻されている');
    }

    // ---------------- #11 / #12 / #14: 部署ランクの更新規則（HTTP 経路） ----------------

    /** #11 決定1: 部署行が無い顧客は、取得日＝契約日・ランク成約でその部署が自動作成される。 */
    public function test_buyer_without_realestate_row_gets_one_with_contract_date(): void
    {
        // 住宅事業にだけ登録された顧客を不動産契約の買主に選ぶ
        $buyer = $this->makeBuyer('住宅のみ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $proc  = $this->makeProcurement();

        $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertNotNull($pivot, '不動産の部署行が自動作成されていない');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-07-19', $pivot->acquired_date->toDateString(), '取得日が契約日になっていない');
    }

    /** #12 既存行の acquired_date は書き換わらない。 */
    public function test_existing_acquired_date_is_not_overwritten_through_http(): void
    {
        $buyer = $this->makeBuyer('取得日', [
            'realestate' => ['rank' => BuyerRank::B->value, 'acquired_date' => '2026-01-05'],
        ]);
        $proc = $this->makeProcurement();

        $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-01-05', $pivot->acquired_date->toDateString(), '取得日が契約日で上書きされている');
    }

    /** #14 もう一方の部署のランクは変わらない。 */
    public function test_other_department_rank_is_untouched(): void
    {
        $buyer = $this->makeBuyer('両部署', [
            'realestate' => ['rank' => BuyerRank::C->value],
            'housing'    => ['rank' => BuyerRank::C->value],
        ]);
        $proc = $this->makeProcurement();

        $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'realestate'));
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '住宅事業のランクまで変わっている');
    }

    // ---------------- #15: 再発火の抑制 ----------------

    /**
     * #15 契約のメモだけを編集してもランクは書き戻らない（設計書 §3.3）。
     *
     * 利用者が意図的にランクを手で戻した後、無関係な編集で成約へ書き戻るのは不可解な挙動になる。
     */
    public function test_editing_memo_only_does_not_rewrite_rank(): void
    {
        $buyer = $this->makeBuyer('メモ', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc  = $this->makeProcurement();
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        // 利用者が手でランクを A に戻した
        $this->pivot($buyer, 'realestate')->update(['rank' => BuyerRank::A->value]);

        // 買主はそのままでメモだけ編集
        $response = $this->actingAs($user)->put(
            '/realestate/contracts/' . $contract->id,
            $this->procurementPayload($proc, $buyer, ['memo' => '駐車場の件を確認']),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('駐車場の件を確認', $contract->fresh()->memo, 'メモが保存されていない（編集自体が失敗）');
        $this->assertSame(BuyerRank::A, $this->rankOf($buyer, 'realestate'), '手で戻したランクが成約へ書き戻っている');
    }

    // ---------------- #18（追加）: department が enum 範囲外 ----------------

    /**
     * re_contracts.department が BuyerDepartment（housing / realestate）の範囲外なら何もしない。
     *
     * buyer_departments.department は enum('housing','realestate') なので、範囲外を書くと
     * 本番 MySQL で DB エラーになる。department はハードコードせず $contract->department を
     * 使う設計（設計書 §5.3）なので、そのガードを固定する。
     *
     * ⚠ コントローラは 'realestate' を固定で入れるため、この経路は HTTP からは作れない。
     *    モデルを直接叩いて確かめる。
     */
    public function test_unknown_department_writes_nothing(): void
    {
        $buyer = $this->makeBuyer('範囲外');
        $proc  = $this->makeProcurement();

        ReContract::create([
            'department'           => 'tenant',
            'contract_type'        => ReContractType::ProcurementHouse->value,
            'status'               => ReContractStatus::Contracted->value,
            'contract_date'        => '2026-07-19',
            'property_name'        => 'ランク検証物件',
            'procurement_id'       => $proc->id,
            'buyer_id'             => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'          => 25000000,
            'created_by'           => 1,
        ]);

        $this->assertSame(
            0,
            BuyerDepartmentPivot::where('buyer_id', $buyer->id)->count(),
            'BuyerDepartment の範囲外の部署が書き込まれている',
        );
    }
}
```

- [ ] **Step 2: 落ちることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: **10 本中 7 本 FAIL**。`#3 brokerage` / `#15 メモ編集` / `#18 unknown_department` の 3 本は
実装前でも緑になる（どれも「何も起きないこと」を見るテストで、フックが無ければ当然何も起きないため）。
**この 3 本が最初から緑なのは想定どおり**で、これらが load-bearing かどうかは Task 7 の変異
（M7 / M8）で別途確かめる。

- [ ] **Step 3: `ReContract.php` に `use` を追加**

`app/Models/ReContract.php` の 5 行目 `use App\Enums\ReContractStatus;` の**前**に追加:

```php
use App\Enums\BuyerDepartment;
```

結果（3-11 行目）:

```php
namespace App\Models;

use App\Enums\BuyerDepartment;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Support\ConsumptionTax;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

- [ ] **Step 4: `booted()` に `saved` フックを追加**

`app/Models/ReContract.php` の `booted()` 内、既存 `static::saving(...)` の閉じ（105 行目 `});`）と
`booted()` の閉じ括弧（106 行目 `}`）の間に挿入する:

```php

        /**
         * 買主が紐づいたら、その部署の顧客ランクを「成約」にする。
         *
         * ⚠ コントローラではなくモデルに置く。買主が契約に紐づく入口は登録・編集の 2 経路あり、
         *    片方に書き忘れても画面は正常に動いて無音で漏れる（Bug #41 / #44 と同型）。
         *
         * ⚠ 仲介は buyer_id を持たない（買主欄が :disabled で validate にもルートが無く、
         *    成約処理も自由入力の buyer_name を受け取るだけ）。「buyer_id があるときだけ」
         *    という条件で自然に対象外になる（設計書 §3.1）。
         *
         * ⚠ wasRecentlyCreated / wasChanged のガードを外さないこと。外すと契約のメモを
         *    直しただけで、利用者が手で戻したランクが成約へ書き戻る（設計書 §3.3）。
         *
         * ⚠ department はハードコードしない。re_contracts は住宅事業へ拡張可能な設計で
         *    department カラムを持つ。ただし buyer_departments.department は
         *    enum('housing','realestate') なので、範囲外なら何もしない（設計書 §5.3）。
         */
        static::saved(function (ReContract $contract): void {
            if ($contract->buyer_id === null) {
                return;
            }

            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged('buyer_id')) {
                return;
            }

            $department = BuyerDepartment::tryFrom((string) $contract->department);
            if ($department === null) {
                return;
            }

            Buyer::withTrashed()->find($contract->buyer_id)?->markContracted(
                $department->value,
                $contract->contract_date?->toDateString(),
            );
        });
```

- [ ] **Step 5: 緑になることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: `OK (10 tests, ...)`。

- [ ] **Step 6: 既存テストへの巻き添えが無いことを確認**

`buyer_id` を持つ `re_contracts` を作る既存テストが 5 ファイルある（新フックを通るようになる）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit
```

期待: 失敗 0。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git add app/Models/ReContract.php tests/Feature/RealEstate/ReContractBuyerRankTest.php && git commit -m "feat(realestate): 契約登録時に買主ランクを成約へ自動変更"
```

---

## Task 4: `HsContract` の `saved` フック（建売契約）

**Files:**
- Modify: `app/Models/HsContract.php`（`use` 追加 / `booted()` 新設）
- Test: `tests/Feature/Housing/HousingContractBuyerRankTest.php`（Task 5 と同じファイル。ここでは建売分だけ書く）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Housing/HousingContractBuyerRankTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Housing;

use App\Enums\BuyerRank;
use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 住宅事業（建売契約・注文住宅）の入口で買主ランクが「成約」になることを固定する
 * （設計書 §7.1 #6-10 ＋ 入口の取りこぼしを埋める追加 3 本）。
 *
 * ⚠ 注文住宅は「登録時」ではなく「ステータスが契約以降になった時」に発火する。
 *    hs_custom_orders は商談段階から登録できる案件レコードなので、登録＝契約ではない（設計書 §3.2）。
 *
 * ⚠ 各テストは必ずリクエストの成功を assert する。バリデーションで弾かれると
 *    「ランクが変わらない」系のテストが壊れていても緑になる（false-pass）。
 */
class HousingContractBuyerRankTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    // ---------------- ヘルパー ----------------

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeBuyer(string $lastName, array $departments = []): Buyer
    {
        $buyer = Buyer::create(['last_name' => $lastName, 'first_name' => '太郎']);

        foreach ($departments as $dept => $spec) {
            BuyerDepartmentPivot::create([
                'buyer_id'      => $buyer->id,
                'department'    => $dept,
                'acquired_date' => $spec['acquired_date'] ?? '2026-01-05',
                'rank'          => $spec['rank'] ?? BuyerRank::C->value,
            ]);
        }

        return $buyer;
    }

    private function pivot(Buyer $buyer, string $department): ?BuyerDepartmentPivot
    {
        return BuyerDepartmentPivot::where('buyer_id', $buyer->id)
            ->where('department', $department)
            ->first();
    }

    private function rankOf(Buyer $buyer, string $department): ?BuyerRank
    {
        return $this->pivot($buyer, $department)?->rank;
    }

    private function makeProperty(string $code = 'HS-RANK-001'): HsProperty
    {
        return HsProperty::create([
            'property_code' => $code,
            'property_name' => 'ランク検証A号地',
            'status'        => 'construction',
            'address'       => '松山市石井町1-2-3',
            'building_cost' => 21300000,
            'land_cost'     => 9600000,
            'created_by'    => 1,
        ]);
    }

    private function tateuriStorePayload(Buyer $buyer): array
    {
        return [
            'customer_id'            => $buyer->id,
            'customer_name'          => '上書きされる名前',
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-19',
        ];
    }

    private function tateuriUpdatePayload(Buyer $buyer, array $overrides = []): array
    {
        return array_merge([
            'customer_id'            => $buyer->id,
            'customer_name'          => '上書きされる名前',
            'contract_date'          => '2026-07-19',
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'building_cost'          => 21300000,
        ], $overrides);
    }

    // ---------------- #6 / #7 / 追加: 建売契約 ----------------

    /** #6 建売の契約登録で買主が成約になる。 */
    public function test_tateuri_contract_store_marks_buyer_contracted(): void
    {
        $buyer    = $this->makeBuyer('建売', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();

        $response = $this->actingAs($this->executive())
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($buyer));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /** #7 契約一覧からの建売契約編集で買主を差し替えると、新しい買主が成約になる。 */
    public function test_tateuri_contract_update_from_list_marks_new_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('建売旧', ['housing' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('建売新', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($oldBuyer))
            ->assertSessionHasNoErrors();

        $contract = HsContract::firstOrFail();

        $response = $this->actingAs($user)->put(
            '/housing/contracts/building/' . $contract->id,
            $this->tateuriUpdatePayload($newBuyer),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($newBuyer, 'housing'));
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($oldBuyer, 'housing'), '元の買主が差し戻されている');
    }

    /**
     * 追加（#15 の建売版）: 買主を変えずに備考だけ編集してもランクは書き戻らない。
     *
     * ReContract 側と同じガードが HsContract にも要る。ここが無いと HsContract の
     * wasChanged ガードを消す変異を誰も検出しない（Bug #44）。
     */
    public function test_tateuri_editing_notes_only_does_not_rewrite_rank(): void
    {
        $buyer    = $this->makeBuyer('建売メモ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($buyer))
            ->assertSessionHasNoErrors();

        $contract = HsContract::firstOrFail();

        // 利用者が手でランクを A に戻した
        $this->pivot($buyer, 'housing')->update(['rank' => BuyerRank::A->value]);

        $response = $this->actingAs($user)->put(
            '/housing/contracts/building/' . $contract->id,
            $this->tateuriUpdatePayload($buyer, ['notes' => '外構の件を確認']),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('外構の件を確認', $contract->fresh()->notes, '備考が保存されていない（編集自体が失敗）');
        $this->assertSame(BuyerRank::A, $this->rankOf($buyer, 'housing'), '手で戻したランクが成約へ書き戻っている');
    }
}
```

- [ ] **Step 2: 落ちることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: 3 本中 2 本 FAIL（`editing_notes_only` は現状の実装でも緑 = 何も起きないため）。

- [ ] **Step 3: `HsContract.php` に `use` を追加**

`app/Models/HsContract.php` の 5 行目 `use App\Support\ConsumptionTax;` の**前**に追加:

```php
use App\Enums\BuyerDepartment;
```

結果（3-8 行目）:

```php
namespace App\Models;

use App\Enums\BuyerDepartment;
use App\Support\ConsumptionTax;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

- [ ] **Step 4: `booted()` を新設**

`app/Models/HsContract.php` の `casts()` メソッドの閉じ（39 行目 `}`）と、
`// ============ リレーション ============` のコメントブロック（41-43 行目）の間に挿入:

```php

    // ============================================================
    // ライフサイクルフック
    // ============================================================

    /**
     * 買主が紐づいたら、住宅事業の顧客ランクを「成約」にする。
     *
     * ⚠ コントローラではなくモデルに置く。買主が契約に紐づく入口は
     *    Housing\ContractController::store と HsContractListController::updateBuilding の
     *    2 経路あり、片方に書き忘れても画面は正常に動いて無音で漏れる（Bug #41 / #44）。
     *
     * ⚠ wasRecentlyCreated / wasChanged のガードを外さないこと。外すと備考を直しただけで
     *    利用者が手で戻したランクが成約へ書き戻る（設計書 §3.3）。
     *
     * 部署は hs_contracts が住宅事業固有のテーブルなので housing 固定（設計書 §5.3）。
     */
    protected static function booted(): void
    {
        static::saved(function (HsContract $contract): void {
            if ($contract->customer_id === null) {
                return;
            }

            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged('customer_id')) {
                return;
            }

            Buyer::withTrashed()->find($contract->customer_id)?->markContracted(
                BuyerDepartment::Housing->value,
                $contract->contract_date?->toDateString(),
            );
        });
    }
```

- [ ] **Step 5: 緑になることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: `OK (3 tests, ...)`。

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git add app/Models/HsContract.php tests/Feature/Housing/HousingContractBuyerRankTest.php && git commit -m "feat(housing): 建売契約登録時に買主ランクを成約へ自動変更"
```

---

## Task 5: `HsCustomOrder` の `saved` フック（注文住宅）

**Files:**
- Modify: `app/Models/HsCustomOrder.php`（`use` 追加 / `booted()` 新設）
- Test: `tests/Feature/Housing/HousingContractBuyerRankTest.php`（Task 4 のファイルにテストを追記）

- [ ] **Step 1: 失敗するテストを追記**

`tests/Feature/Housing/HousingContractBuyerRankTest.php` の
`test_tateuri_editing_notes_only_does_not_rewrite_rank()` の閉じ括弧の後、
クラス閉じ括弧の前に追記:

```php

    // ---------------- 注文住宅のヘルパー ----------------

    private function customOrderStorePayload(Buyer $buyer, CustomOrderStatus $status): array
    {
        return [
            'order_name'          => 'ランク検証A邸',
            'status'              => $status->value,
            'customer_id'         => $buyer->id,
            'customer_name'       => '上書きされる名前',
            'address'             => '松山市石井町1-2-3',
            'is_land_cost_manual' => 0,
            'tax_rate'            => 10.00,
            'contract_date'       => '2026-07-19',
        ];
    }

    /** 契約一覧からの注文住宅契約編集（HsContractListController::updateCustomOrder）の payload。 */
    private function customOrderListUpdatePayload(Buyer $buyer, array $overrides = []): array
    {
        return array_merge([
            'customer_id'             => $buyer->id,
            'customer_name'           => '上書きされる名前',
            'contract_date'           => '2026-07-19',
            'land_source_type'        => HousingLandSourceType::CustomerLand->value,
            'building_contract_price' => 32000000,
            'tax_rate'                => 10.00,
            'building_cost'           => 24800000,
        ], $overrides);
    }

    // ---------------- #8 / #9 / #10 / 追加: 注文住宅 ----------------

    /**
     * #8 商談ステータスで登録してもランクは変わらない（設計書 §3.2）。
     *
     * hs_custom_orders は商談段階から登録できる案件レコード。登録＝契約ではないので、
     * まだ商談中の見込み客を成約扱いにしてはいけない。
     */
    public function test_custom_order_stored_as_consultation_changes_no_rank(): void
    {
        $buyer = $this->makeBuyer('商談', ['housing' => ['rank' => BuyerRank::C->value]]);

        $response = $this->actingAs($this->executive())->post(
            '/housing/custom-orders',
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertDatabaseCount('hs_custom_orders', 1);
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '商談段階の見込み客が成約になっている');
    }

    /** #9 契約ステータスで登録すると成約になる。 */
    public function test_custom_order_stored_as_contracted_marks_buyer_contracted(): void
    {
        $buyer = $this->makeBuyer('注文契約', ['housing' => ['rank' => BuyerRank::C->value]]);

        $response = $this->actingAs($this->executive())->post(
            '/housing/custom-orders',
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /** #10 一覧のステップバーで 商談 → 契約 に進めると成約になる。 */
    public function test_custom_order_step_bar_advance_to_contracted_marks_buyer(): void
    {
        $buyer = $this->makeBuyer('ステップ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '前提: 登録時点ではまだ C');

        $response = $this->actingAs($user)->patch(
            '/housing/custom-orders/' . $order->id . '/status',
            ['status' => CustomOrderStatus::Contracted->value],
        );

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /**
     * 追加（入口の取りこぼし）: 編集フォーム（PUT /housing/custom-orders/{id}）で
     * 商談 → 契約 に進めた場合も成約になる。
     *
     * ステップバー（PATCH .../status）とは別のコントローラメソッドなので、
     * 片方だけ測ると一方の入口が一度も実行されない（Bug #44）。
     */
    public function test_custom_order_edit_form_advance_to_contracted_marks_buyer(): void
    {
        $buyer = $this->makeBuyer('注文編集', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '前提: 登録時点ではまだ C');

        $response = $this->actingAs($user)->put(
            '/housing/custom-orders/' . $order->id,
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /**
     * 追加（入口の取りこぼし）: 契約一覧からの注文住宅契約編集で買主を差し替えると
     * 新しい買主が成約になる（HsContractListController::updateCustomOrder）。
     */
    public function test_custom_order_update_from_contract_list_marks_new_buyer(): void
    {
        $oldBuyer = $this->makeBuyer('注文旧', ['housing' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('注文新', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($oldBuyer, CustomOrderStatus::Contracted))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();

        $response = $this->actingAs($user)->put(
            '/housing/contracts/custom-order/' . $order->id,
            $this->customOrderListUpdatePayload($newBuyer),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($newBuyer, 'housing'));
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($oldBuyer, 'housing'), '元の買主が差し戻されている');
    }

    /**
     * 追加（#15 の注文住宅版）: 買主・ステータスを変えずに備考だけ編集しても
     * ランクは書き戻らない。
     */
    public function test_custom_order_editing_notes_only_does_not_rewrite_rank(): void
    {
        $buyer = $this->makeBuyer('注文メモ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();

        // 利用者が手でランクを A に戻した
        $this->pivot($buyer, 'housing')->update(['rank' => BuyerRank::A->value]);

        $response = $this->actingAs($user)->put(
            '/housing/contracts/custom-order/' . $order->id,
            $this->customOrderListUpdatePayload($buyer, ['notes' => '仕様変更の件を確認']),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('仕様変更の件を確認', $order->fresh()->notes, '備考が保存されていない（編集自体が失敗）');
        $this->assertSame(BuyerRank::A, $this->rankOf($buyer, 'housing'), '手で戻したランクが成約へ書き戻っている');
    }
```

- [ ] **Step 2: 落ちることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: 9 本中 4 本 FAIL（`#9` `#10` `edit_form_advance` `update_from_contract_list`）。
`#8`（商談）と 2 本のメモ編集テストは現状でも緑（どれも「何も起きないこと」を見るため）。

- [ ] **Step 3: `HsCustomOrder.php` に `use` を追加**

`app/Models/HsCustomOrder.php` の 5 行目 `use App\Enums\CustomOrderStatus;` の**前**に追加:

```php
use App\Enums\BuyerDepartment;
```

結果（3-12 行目）:

```php
namespace App\Models;

use App\Enums\BuyerDepartment;
use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Support\AreaConverter;
use App\Support\ConsumptionTax;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
```

- [ ] **Step 4: `booted()` を新設**

`app/Models/HsCustomOrder.php` の `casts()` メソッドの閉じ（68 行目 `}`）と、
`// ============ リレーション ============` のコメントブロック（70-72 行目）の間に挿入:

```php

    // ============================================================
    // ライフサイクルフック
    // ============================================================

    /**
     * ステータスが「契約」以降になったとき、住宅事業の顧客ランクを「成約」にする。
     *
     * ⚠ **登録時ではない。** hs_custom_orders は 商談 → 設計 → 見積り → 契約 → 着工 →
     *    完成 → 引渡し と進む案件レコードで、商談段階でも登録できる。登録＝契約ではないので、
     *    登録時に成約へ変えるとまだ商談中の見込み客が成約扱いになる（設計書 §3.2）。
     *
     * ⚠ 判定は CustomOrderStatus::isContractedOrLater()。分譲地区画のステータス連動
     *    （CustomOrderController::syncLotStatus）が使っている判定と同一で、
     *    「契約以降なら区画は販売済」と足並みが揃う。別の閾値を書かないこと。
     *
     * ⚠ wasRecentlyCreated / wasChanged のガードを外さないこと。外すと備考を直しただけで
     *    利用者が手で戻したランクが成約へ書き戻る（設計書 §3.3）。
     *
     * 部署は hs_custom_orders が住宅事業固有のテーブルなので housing 固定（設計書 §5.3）。
     */
    protected static function booted(): void
    {
        static::saved(function (HsCustomOrder $order): void {
            if ($order->customer_id === null) {
                return;
            }

            if ($order->status?->isContractedOrLater() !== true) {
                return;
            }

            if (! $order->wasRecentlyCreated && ! $order->wasChanged(['customer_id', 'status'])) {
                return;
            }

            Buyer::withTrashed()->find($order->customer_id)?->markContracted(
                BuyerDepartment::Housing->value,
                $order->contract_date?->toDateString(),
            );
        });
    }
```

- [ ] **Step 5: 緑になることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: `OK (9 tests, ...)`。

- [ ] **Step 6: 全テストが緑であることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit
```

期待: 失敗 0。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git add app/Models/HsCustomOrder.php tests/Feature/Housing/HousingContractBuyerRankTest.php && git commit -m "feat(housing): 注文住宅が契約以降になったとき買主ランクを成約へ自動変更"
```

---

## Task 6: モデルイベントを通らない書き込みが無いことの走査テスト

**Files:**
- Create: `tests/Feature/ContractModelEventPathTest.php`

設計書 §7.4 の再確認。`Model::where(...)->update(...)` のようなクエリビルダ経由の更新は
`saved` イベントを通らないので、そういう書き込みが 1 つでも増えるとその経路だけ無音で漏れる。

- [ ] **Step 1: 現状を実測する（テストを書く前に）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && grep -rn "ReContract::\|HsContract::\|HsCustomOrder::\|DB::table('re_contracts'\|DB::table('hs_contracts'\|DB::table('hs_custom_orders'" app | grep -v "^app/Models/"
```

2026-08-04 実測では、これらはすべて**読み取り**（`::where(...)->get()` / `->min()` / `->value()`）で、
`->update(` を含む文は 0 件だった。数字が違ったら、テストの下限値（Step 2 の `assertGreaterThanOrEqual`）を
実測値に合わせて調整すること。

- [ ] **Step 2: 走査テストを書く**

`tests/Feature/ContractModelEventPathTest.php` を新規作成:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 契約 3 テーブルへの**更新**がクエリビルダ経由になっていないことを固定する（設計書 §7.4）。
 *
 * ⚠ Eloquent の saved イベントは `Model::where(...)->update(...)` /
 *    `DB::table('...')->update(...)` を**通らない**。買主ランクの自動成約はこのイベントに
 *    乗っているので、そういう書き込みが 1 つ増えるとその経路だけ無音で漏れる（Bug #41 / #44）。
 *
 * ⚠ **このテストが見ているのは app/ のソース文字列だけ。** 動的に組み立てたテーブル名や
 *    リレーション経由の update（`$parent->relation()->update(...)`）は拾えない。
 *    「これで全部」とは言えない（Bug #45 ①）。対象を広げるときは必ず先に実測すること。
 *
 * ⚠ `::where(...)` 自体は読み取りで正当（一覧・年度算出・採番が使っている）。
 *    判定は「同一文の中に ->update( があるか」で行う。
 */
class ContractModelEventPathTest extends TestCase
{
    /** 契約モデル／テーブルを根に持つ文を検出するためのパターン */
    private const ROOT_PATTERNS = [
        'ReContract::',
        'HsContract::',
        'HsCustomOrder::',
        "DB::table('re_contracts')",
        "DB::table('hs_contracts')",
        "DB::table('hs_custom_orders')",
    ];

    public function test_contract_models_are_never_updated_through_the_query_builder(): void
    {
        $offenders    = [];
        $rootStatements = 0;
        $scannedFiles = 0;

        foreach ($this->phpFilesUnderApp() as $path) {
            // app/Models/ 自身はモデル定義（self 参照）なので対象外
            if (str_contains($path, '/app/Models/')) {
                continue;
            }

            $scannedFiles++;
            $source = $this->sourceWithoutComments($path);

            // PHP の文は ';' で終わる。文単位に切って「根」と '->update(' の同居を見る。
            foreach (explode(';', $source) as $statement) {
                $hasRoot = false;
                foreach (self::ROOT_PATTERNS as $pattern) {
                    if (str_contains($statement, $pattern)) {
                        $hasRoot = true;
                        break;
                    }
                }
                if (! $hasRoot) {
                    continue;
                }

                $rootStatements++;

                if (str_contains($statement, '->update(')) {
                    $offenders[] = $path . ' :: ' . trim(preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（Bug #32 / #35 と同じ流儀）
        $this->assertGreaterThanOrEqual(50, $scannedFiles, 'app/ の走査が空振りしている');
        $this->assertGreaterThanOrEqual(
            5,
            $rootStatements,
            '契約モデルを参照する文が見つからない。クラス名が変わったならこのテストのパターンも直すこと',
        );

        $this->assertSame(
            [],
            $offenders,
            "契約モデルをクエリビルダで更新している箇所がある。saved イベントを通らないため\n"
            . "買主ランクの自動成約が漏れる。モデル経由（\$model->update(...)）に直すこと:\n"
            . implode("\n", $offenders),
        );
    }

    /** @return list<string> */
    private function phpFilesUnderApp(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * コメントと docblock を落としたソースを返す。
     *
     * ⚠ 落とさないと、自分が書いた注意書き（「⚠ ->update( は使わない」等）に一致して
     *    false-pass / false-fail する（Bug #42 ②で実際に踏んだ）。
     */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
```

- [ ] **Step 3: 緑になることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/ContractModelEventPathTest.php
```

期待: `OK (1 test, ...)`。

- [ ] **Step 4: 走査が本当に効いていることを確認（変異）**

`app/Http/Controllers/Housing/CustomOrderController.php` の `generateOrderCode()` 内（410 行目付近）、

```php
        $lastCode = HsCustomOrder::where('order_code', 'like', "{$prefix}%")
            ->orderByDesc('order_code')
            ->value('order_code');
```

を**手で**次に差し替える（`sed` / `perl` の一括置換は使わない。同じ文字列がファイル内に複数あると
意図しない行を書き換え、テストが正しく緑のまま残って「検出しない」と誤読する — Bug #44）:

```php
        HsCustomOrder::where('order_code', 'like', "{$prefix}%")->update(['notes' => 'x']);
        $lastCode = HsCustomOrder::where('order_code', 'like', "{$prefix}%")
            ->orderByDesc('order_code')
            ->value('order_code');
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git diff --stat
```

**`git diff --stat` が空でないことを必ず確認する。** 空なら変異が当たっていないので、
「検出しない」と誤読する事故になる（Bug #44 / #46）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/ContractModelEventPathTest.php
```

期待: FAIL（offenders に 1 件出る）。

- [ ] **Step 5: 変異を戻す**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git checkout -- app/Http/Controllers/Housing/CustomOrderController.php && git diff --stat && vendor/bin/phpunit tests/Feature/ContractModelEventPathTest.php
```

期待: `git diff --stat` が空・テストは `OK (1 test, ...)`。

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git add tests/Feature/ContractModelEventPathTest.php && git commit -m "test(buyers): 契約モデルがクエリビルダ更新されていないことを走査で固定"
```

---

## Task 7: 変異テスト（必須）

**「テストが緑」は検証にならない。** Bug #39 / #42 / #44 / #45 / #46 で繰り返し実測している。
11 通りの変異それぞれで**実際に赤になることを確認する**。

**Files:** （一時的に変更 → 必ず `git checkout --` で戻す）
- `app/Models/Buyer.php`
- `app/Models/ReContract.php`
- `app/Models/HsContract.php`
- `app/Models/HsCustomOrder.php`

### 手順（全変異に共通）

各変異について、この 4 ステップを必ずこの順で行う:

1. 変異を当てる（狙う行を明示。同じ文字列が複数あるファイルでの一括置換は禁止 — Bug #44）
2. **`git diff --stat` が空でないことを確認する**（当たっていない変異を「検出しない」と誤読する事故を防ぐ）
3. 該当テストを走らせ、赤/緑を記録する
4. `git checkout -- <file>` で戻し、**`git diff --stat` が空**になったことを確認する

### 変異マトリクス

| # | 変異 | 対象 | 赤になるはずのテスト |
|---|---|---|---|
| M1 | `ReContract` の `saved` フック本体を丸ごと削除 | `ReContract.php` | ReContract 側 7 本（#3・#18 以外） |
| M2 | `HsContract` の `saved` フック本体を丸ごと削除 | `HsContract.php` | 建売 2 本 |
| M3 | `HsCustomOrder` の `saved` フック本体を丸ごと削除 | `HsCustomOrder.php` | 注文住宅 4 本 |
| M4 | 注文住宅の `isContractedOrLater()` 判定を削除 | `HsCustomOrder.php` | `#8` ＋ ステップバー / 編集フォームの「前提」assert 計 3 本 |
| M5 | `markContracted()` の「部署行が無ければ作る」分岐を `return` に潰す | `Buyer.php` | T2-a / T2-d / `#11` |
| M6 | `markContracted()` の `update()` に `acquired_date` も入れる | `Buyer.php` | T2-b / `#12` |
| M7 | `ReContract` の `wasRecentlyCreated` / `wasChanged` ガードを削除 | `ReContract.php` | `#15`（メモ編集） |
| M8 | `ReContract` の `BuyerDepartment::tryFrom` ガードを削除 | `ReContract.php` | `#18`（範囲外 department） |
| M9 | `markContracted()` の上書き対象を A〜D 限定にする | `Buyer.php` | T2-c（他決・追客不可） |
| M10 | `HsContract` の `wasChanged` ガードを削除 | `HsContract.php` | 建売の備考編集 |
| M11 | `HsCustomOrder` の `wasChanged` ガードを削除 | `HsCustomOrder.php` | 注文住宅の備考編集 |

- [ ] **Step 1: M1 — `ReContract` のフックを削除**

`app/Models/ReContract.php` の `static::saved(function (ReContract $contract): void {` から
対応する `});` までを手で削除する（docblock は残してよい）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git diff --stat
```

空でないことを確認してから:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: **7 本 FAIL**（`brokerage` と `unknown_department` だけ緑）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git checkout -- app/Models/ReContract.php && git diff --stat
```

- [ ] **Step 2: M2 — `HsContract` のフックを削除**

`app/Models/HsContract.php` の `static::saved(function (HsContract $contract): void {` から
対応する `});` までを削除 → `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: **建売 2 本 FAIL**（`store` / `update_from_list`。備考編集は緑のまま）。

→ `git checkout -- app/Models/HsContract.php` → `git diff --stat` が空。

- [ ] **Step 3: M3 — `HsCustomOrder` のフックを削除**

`app/Models/HsCustomOrder.php` の `static::saved(function (HsCustomOrder $order): void {` から
対応する `});` までを削除 → `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: **注文住宅 4 本 FAIL**（`stored_as_contracted` / `step_bar` / `edit_form` / `update_from_contract_list`）。

→ `git checkout -- app/Models/HsCustomOrder.php` → `git diff --stat` が空。

- [ ] **Step 4: M4 — 注文住宅のステータス判定を削除**

`app/Models/HsCustomOrder.php` の次の 3 行を削除する:

```php
            if ($order->status?->isContractedOrLater() !== true) {
                return;
            }
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: **3 本 FAIL** — `test_custom_order_stored_as_consultation_changes_no_rank`（商談で登録しても
成約になる）に加え、`test_custom_order_step_bar_advance_to_contracted_marks_buyer` と
`test_custom_order_edit_form_advance_to_contracted_marks_buyer` の**「前提: 登録時点ではまだ C」の
assert** も落ちる（どちらも商談で登録してから進める形なので）。

→ `git checkout -- app/Models/HsCustomOrder.php` → `git diff --stat` が空。

- [ ] **Step 5: M5 — 部署行の自動作成を潰す**

`app/Models/Buyer.php` の `markContracted()` 内、

```php
        if ($pivot === null) {
            $this->addToDepartment(
                $department,
                $acquiredDate ?: now()->toDateString(),
                BuyerRank::Contracted->value,
            );
            return;
        }
```

を次に差し替える:

```php
        if ($pivot === null) {
            return;
        }
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/BuyerMarkContractedTest.php tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: **3 本 FAIL**（`missing_department_row_is_created...` / `missing_date_falls_back_to_today` /
`buyer_without_realestate_row_gets_one_with_contract_date`）。

→ `git checkout -- app/Models/Buyer.php` → `git diff --stat` が空。

- [ ] **Step 6: M6 — 既存行の acquired_date も上書きする**

`app/Models/Buyer.php` の `markContracted()` 末尾、

```php
        $pivot->update(['rank' => BuyerRank::Contracted->value]);
```

を次に差し替える:

```php
        $pivot->update([
            'rank'          => BuyerRank::Contracted->value,
            'acquired_date' => $acquiredDate ?: now()->toDateString(),
        ]);
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/BuyerMarkContractedTest.php tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: **2 本 FAIL**（`existing_row_keeps_its_acquired_date` / `existing_acquired_date_is_not_overwritten_through_http`）。

→ `git checkout -- app/Models/Buyer.php` → `git diff --stat` が空。

- [ ] **Step 7: M7 — `ReContract` の再発火ガードを削除**

`app/Models/ReContract.php` の `saved` フック内、次の 3 行を削除する:

```php
            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged('buyer_id')) {
                return;
            }
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: **`test_editing_memo_only_does_not_rewrite_rank` が FAIL**。

**⚠ これが緑のままなら、テストが HTTP を通っていない疑いがある**（Bug #39: `create()` した同じ
インスタンスで `update()` すると `wasRecentlyCreated` が `true` のまま残り、ガードの変異が隠れる）。
その場合はテストがルートモデルバインディング経由になっているかを確認すること。

→ `git checkout -- app/Models/ReContract.php` → `git diff --stat` が空。

- [ ] **Step 8: M8 — `BuyerDepartment::tryFrom` ガードを削除**

`app/Models/ReContract.php` の `saved` フック内、

```php
            $department = BuyerDepartment::tryFrom((string) $contract->department);
            if ($department === null) {
                return;
            }

            Buyer::withTrashed()->find($contract->buyer_id)?->markContracted(
                $department->value,
                $contract->contract_date?->toDateString(),
            );
```

を次に差し替える:

```php
            Buyer::withTrashed()->find($contract->buyer_id)?->markContracted(
                (string) $contract->department,
                $contract->contract_date?->toDateString(),
            );
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/RealEstate/ReContractBuyerRankTest.php
```

期待: **`test_unknown_department_writes_nothing` が FAIL**。

→ `git checkout -- app/Models/ReContract.php` → `git diff --stat` が空。

- [ ] **Step 9: M9 — 上書き対象を A〜D 限定にする**

`app/Models/Buyer.php` の `markContracted()` 内、

```php
        if ($pivot->rank === BuyerRank::Contracted) {
            return;
        }
```

を次に差し替える:

```php
        if (! in_array($pivot->rank, BuyerRank::activeRanks(), true)) {
            return;
        }
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/BuyerMarkContractedTest.php
```

期待: **`test_lost_and_unreachable_ranks_are_overwritten` が FAIL**。

→ `git checkout -- app/Models/Buyer.php` → `git diff --stat` が空。

- [ ] **Step 10: M10 — `HsContract` の再発火ガードを削除**

`app/Models/HsContract.php` の `saved` フック内、次の 3 行を削除する:

```php
            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged('customer_id')) {
                return;
            }
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: **`test_tateuri_editing_notes_only_does_not_rewrite_rank` が FAIL**。

→ `git checkout -- app/Models/HsContract.php` → `git diff --stat` が空。

- [ ] **Step 11: M11 — `HsCustomOrder` の再発火ガードを削除**

`app/Models/HsCustomOrder.php` の `saved` フック内、次の 3 行を削除する:

```php
            if (! $order->wasRecentlyCreated && ! $order->wasChanged(['customer_id', 'status'])) {
                return;
            }
```

→ `git diff --stat` 確認 →

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit tests/Feature/Housing/HousingContractBuyerRankTest.php
```

期待: **`test_custom_order_editing_notes_only_does_not_rewrite_rank` が FAIL**。

→ `git checkout -- app/Models/HsCustomOrder.php` → `git diff --stat` が空。

- [ ] **Step 12: 結果を設計書に追記してコミット**

`docs/superpowers/specs/2026-08-04-buyer-rank-auto-contracted-design.md` の §7.2 の末尾に、
実測した変異マトリクス（M1〜M11 と赤になったテスト名）を追記する。

**⚠ 期待どおりに赤にならなかった変異があれば、それは「テストが守れていない」ということ。**
テストを直してから先へ進む（変異を消して先へ進むのは禁止）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && git status --short && git add docs/superpowers/specs/2026-08-04-buyer-rank-auto-contracted-design.md && git commit -m "docs(spec): 買主ランク自動成約の変異テスト結果を記録"
```

**⚠ `git status --short` で `app/` `tests/` に未コミットの差分が残っていないことを確認する。**
残っていたら変異の戻し忘れ。

---

## Task 8: 仕上げ（全体確認・セルフレビュー・本番反映準備）

- [ ] **Step 1: 全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && vendor/bin/phpunit
```

期待: 失敗 0。テスト数は Task 0 Step 3 のベースライン **+ 24 本**
（`BuyerMarkContractedTest` 4 ＋ `ReContractBuyerRankTest` 10 ＋ `HousingContractBuyerRankTest` 9
＋ `ContractModelEventPathTest` 1）。合わなければ書き忘れたテストが無いか確認する。

- [ ] **Step 2: `view:cache` とコンパイル済みビューの lint（Blade は触っていないが習慣として）**

本タスクは Blade を 1 行も変更していないので必須ではないが、`booted()` 追加でモデルの
オートロードが変わっていないことの確認になる:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/buyer-rank-contracted && php -l app/Models/Buyer.php && php -l app/Models/ReContract.php && php -l app/Models/HsContract.php && php -l app/Models/HsCustomOrder.php
```

期待: 4 本とも `No syntax errors detected`。

- [ ] **Step 3: セルフレビュー**

`/review`（code-review プラグイン）を実行する。特に次を見てもらう:

- `markContracted()` が 3 モデルから同じ形で呼ばれているか（規則のコピーが無いか。Bug #41）
- `wasChanged` の監視対象カラム名が正しいか（`ReContract` は `buyer_id`、住宅事業は `customer_id`。
  綴り違いは例外を出さず無音で効かなくなる）
- 買主が論理削除済みでも更新されるか（`Buyer::withTrashed()` を使っているか）

- [ ] **Step 4: main repo へ FF-merge**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only buyer-rank-contracted && git log --oneline -8
```

**⚠ 新規 PHP クラスは追加していない**（既存クラスへのメソッド追加のみ）ので
`composer dump-autoload` は不要。

- [ ] **Step 5: 本番反映はユーザーに確認してから**

`./deploy.sh` は**ユーザーの明示的な承認が無いと自動モード分類器にブロックされる**。
`AskUserQuestion` で本番デプロイの可否を確認し、承認を得てから実行する。

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

DB スキーマの変更は無い（`buyer_departments` は既存テーブル、`contracted` は既存の ENUM 値）。
コントローラは変更していないが、モデルは `rsync` 対象なので `./deploy.sh` だけで反映される。

- [ ] **Step 6: 本番ブラウザ確認（デプロイした場合）**

実 Chrome（claude-in-chrome）で既存セッションを使い、URL は `/index.php/` 前置が要る。

1. `/realestate/contracts/create` で契約を 1 件登録する（買主は既存の A〜D ランクの顧客）
2. `/buyers?department=realestate&rank[]=contracted` でその顧客が「成約」で出ることを確認
3. 既定の一覧（A〜D）からは外れることも確認（設計書 §6 の想定どおりの挙動）

**⚠ 決定3 により既存の契約済みデータは変わらない。** 過去の契約済み顧客が A/B/C/D のままなのは
仕様であって不具合ではない。

- [ ] **Step 7: ドキュメント更新**

`docs/BACKLOG.md` に本件を追記する必要は無い（バックログ項目ではなく単発の仕様追加）。
`CLAUDE.md` の Top traps に追加すべき新しい罠も出ていない（既存の Bug #39 / #41 / #44 の適用例）。
**変異テストで想定外の挙動を踏んだ場合のみ** `docs/RULES.md` に Bug #47 として追記する。

---

## 実装後に残る既知の未カバー

正直に記録しておく（Bug #45 の「測っていない範囲を『守られている』と言わない」）:

| 項目 | 理由 |
|---|---|
| `markContracted()` の「既に contracted なら UPDATE を出さない」早期 return | 挙動が同じなので回帰テストで守れない。消しても緑のまま |
| 仲介契約の顧客マスタ紐付け | 構造的に不可能（`buyer_id` を持たない）。設計書 §8 でスコープ外 |
| 既存の契約済みデータの一括更新 | 決定3 でスコープ外 |
| 契約削除・買主差し替え時のランク差し戻し | 決定2 でスコープ外 |
| `ContractModelEventPathTest` の走査範囲 | `app/` のソース文字列のみ。動的テーブル名・リレーション経由の `update()` は拾えない |
| テナント管理・賃貸マンション・ZEAL の契約 | 顧客マスタ（`buyers`）と別系統。設計書 §8 でスコープ外 |
