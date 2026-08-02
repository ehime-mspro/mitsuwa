# 仕入れ案件・分譲地PJ・区画の削除ガード 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 契約・建売物件・注文住宅が参照している仕入れ案件 / 分譲地PJ / 区画を削除できないようにし、画面でも削除前に理由を見せる。

**Architecture:** 判定は新規クラス `App\Support\DeletionBlockers` に一本化する（画面のパネルとサーバのガードが別々に判定すると Bug #41 の形になるため）。3 モデルは薄いラッパー `deletionBlockers()` を持つだけ。コントローラは `if ($blockers = $model->deletionBlockers())` で弾き、要約文も同クラスの `summarize()` が組む。DB 変更・ルート追加は無い。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）/ Blade + Alpine.js 3 / PHPUnit（SQLite in-memory）

**設計書:** `docs/superpowers/specs/2026-08-02-realestate-deletion-guard-design.md`（承認済み。設計の再検討は不要）

---

## 設計書からの逸脱 2 点（実装時の判断・理由つき）

| 設計書 | このプラン | 理由 |
|---|---|---|
| `final class DeletionBlockers` | `class DeletionBlockers` | `app/Support/` の既存 10 クラスは全て `final` 無し（実測）。既存のスタイルに合わせる |
| 「契約は id で uniq する」 | グループ化した OR の **1 クエリ**にして構造的に 1 行 1 回にする | 後から `unique('id')` を書き忘れる余地を残さない。保証は同じ（テスト ⑤ で固定する） |

設計書に無い追加が 1 つある: 区画一覧用に **`forEachLotId()`**（区画 id => ブロッカーの連想配列）を足す。
§5.2 は区画ごとに `delete_blocked` を出すが、区画ループの中で `forLotIds([$lot->id])` を呼ぶと
区画数 × 3 クエリの N+1 になる。バルククエリ 3 本で全区画分を組む。

### テストを置かない項目（意図的な省略）

**`buyer_surveys` をブロックしないこと**（設計書 §2.2）に回帰テストを置かない。
`buyer_surveys` は Laravel マイグレーション管理外で**テスト DB に存在しない**（実測。
`tests/Concerns/CreatesRealEstateSchema.php` にも無く、`CustomerSurveyAuthorizationTest` は
15-20 行のコメントでその旨を明記して Reflection での検証に逃がしている）。
テストのために共有 trait へテーブルを足すと、他テストへの影響を負う割に得るものが少ない
——`DeletionBlockers` は `buyer_surveys` を**そもそも一度も引かない**ので、
この決定を壊すには新しいクエリを書き足す必要があり、事故で破れる類のものではない。

---

## 前提: 実測して確認済みの事実

プラン作成時に実際にコードを読んで確認した。**推測ではない。**

| 確認したこと | 実測結果 |
|---|---|
| `lots.blade.php` の Ajax エラー表示 | 461 行が `err.message` を読む（`error` ではない） |
| `destroyLot()` の既存 403 | 603 行が `['error' => '不正なリクエストです。']` → JS には理由が出ない |
| テストスキーマの FK | `CreatesRealEstateSchema` は列だけ。13 行目に「FK 制約は張らない」と明記済み |
| `attachments` テーブル | `database/migrations/0001_01_01_000015_create_attachments_table.php` にあり `RefreshDatabase` で作られる → show の描画テストが書ける |
| 2 つの show の削除ボタン | `procurements/show.blade.php:30-37` と `projects/show.blade.php:30-37` が**同一構造**（route と confirm 文だけ違う） |
| `disabled` の既存出現数 | 両 show とも **0 件** → `disabled` を含む `<button>` の正規表現アサートが一意に効く |
| `procurements/show` の契約カード | 183-224 行が契約を既に一覧表示している（買主名・契約詳細リンクを出す）。**契約の `property_name` は出さない** |
| `projects/show` の依存表示 | 契約・建売・注文住宅を出す箇所は **0 件** |
| `ReContract::buyer()` | 既に `->withTrashed()` 付き（130 行）→ eager load して安全 |
| 赤バナー | `layouts/app.blade.php:56-62` が `session('error')` を描画 |
| 既存ガードの作法 | `SupplierController:134-136` が `if (...) { return back()->with('error', '…'); }` |
| `data-testid` の使用 | `resources/views/` に 0 件 → 新しい流儀を持ち込まない |

⚠ **`procurements/show` の契約カードは ⑥ のテストで false-pass の元になる**（Bug #40 と同型）。
買主名は既存カードにも出るので、パネルの検証には**契約の `property_name`** を使う。

---

## File Structure

| ファイル | 責務 | 行数目安 |
|---|---|---|
| `app/Support/DeletionBlockers.php` | **新規**。3 経路の唯一の判定元 + 要約文 | 約 150 |
| `app/Models/ReProcurement.php` | `deletionBlockers()` ラッパー | +8 |
| `app/Models/ReProject.php` | 同上 | +8 |
| `app/Models/ReProjectLot.php` | 同上 | +8 |
| `app/Http/Controllers/RealEstate/ProcurementController.php` | `destroy()` ガード / `show()` の view 変数 | +12 |
| `app/Http/Controllers/RealEstate/ProjectController.php` | `destroy()` / `destroyLot()` ガード / `show()` / `lots()` | +20 |
| `resources/views/realestate/_partials/_deletion_blockers.blade.php` | **新規**。amber パネル（2 つの show が共有） | 約 30 |
| `resources/views/realestate/procurements/show.blade.php` | パネル include + 削除ボタン差し替え | ±14 |
| `resources/views/realestate/projects/show.blade.php` | 同上 | ±14 |
| `resources/views/realestate/projects/lots.blade.php` | 区画削除ボタンを `:disabled` 化・`style` を `:style` へ | ±4 |
| `tests/Feature/RealEstate/DeletionGuardTest.php` | **新規**。①〜⑦ | 約 300 |

パネルを `_partials/` に置くのは既存の流儀に従うため（`realestate/_partials/` に 4 本ある。
`@include('realestate._partials.supplier-picker')` の形で使われている）。

---

## Task 0: worktree のセットアップ

**Files:** なし（環境準備のみ）

- [ ] **Step 1: worktree に vendor を入れる**

worktree には `vendor` が無い（実測）。テストを動かすために入れる。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= composer install
```

Expected: `Generating optimized autoload files` で終了。

- [ ] **Step 2: 既存テストが緑であることを確認（ベースライン）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit
```

Expected: `OK (426 tests, 2409 assertions)`（件数は前回セッションの実測値。多少違っても**赤が 0 本**であればよい）

⚠ ここで赤があるなら、それは本プランと無関係の既存問題。先に報告すること。

---

## Task 1: `DeletionBlockers::forLotIds()` — 区画からの逆引き

**Files:**
- Create: `app/Support/DeletionBlockers.php`
- Create: `tests/Feature/RealEstate/DeletionGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/RealEstate/DeletionGuardTest.php` を新規作成する。
（この 1 本のファイルに Task 1〜9 のテストを足していく。ここではヘルパー群 + 最初の 2 本）

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Support\DeletionBlockers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件 / 分譲地PJ / 区画の削除ガード。
 *
 * ⚠ tests/Concerns/CreatesRealEstateSchema.php は列だけ作り FK 制約を張らない（13 行目に明記）。
 *    よって SQLite では ON DELETE SET NULL も CASCADE も起きず、
 *    「ガードを外すとデータが壊れる」ことは**テストでは原理的に再現できない**（Bug #40 と同型）。
 *    ここで固定するのは FK の副作用ではなく **ガードの挙動そのもの**。
 *
 * ⚠ 削除系ルートは全て middleware('role:executive')。executive() で actingAs すること。
 */
class DeletionGuardTest extends TestCase
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
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProcurement(string $code = 'P-001'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => $code,
            'property_type'    => 'used_house',
            'transaction_type' => 'purchase',
            'status'           => 'info_obtained',
            'property_name'    => "仕入れ{$code}",
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ]);
    }

    private function makeProject(string $code = 'PJ-001'): ReProject
    {
        return ReProject::create([
            'project_code' => $code,
            'project_name' => "分譲地{$code}",
            'status'       => 'selling',
            'address'      => '愛媛県松山市2-2-2',
            'created_by'   => 1,
        ]);
    }

    private function makeLot(ReProject $project, int $lotNumber = 1): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => 'on_sale',
        ]);
    }

    /**
     * @param  array<string, mixed>  $links  procurement_id / project_id / lot_id のいずれか
     */
    private function makeContract(array $links, string $propertyName = '契約物件A'): ReContract
    {
        return ReContract::create(array_merge([
            'department'    => 'realestate',
            'contract_type' => 'procurement_land',
            'status'        => 'contracted',
            'property_name' => $propertyName,
            'buyer_name'    => '山西 太郎',
            'created_by'    => 1,
        ], $links));
    }

    private function makeProperty(ReProjectLot $lot, string $code = 'HS-0007'): HsProperty
    {
        return HsProperty::create([
            'property_code'     => $code,
            'property_name'     => '建売テスト邸',
            'status'            => 'construction',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);
    }

    private function makeCustomOrder(ReProjectLot $lot, string $code = 'CO-0003'): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'        => $code,
            'order_name'        => '注文住宅テスト邸',
            'status'            => 'contracted',
            'customer_name'     => '大西 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市4-4-4',
            'created_by'        => 1,
        ]);
    }

    // ============================================================
    // Task 1: forLotIds()
    // ============================================================

    /** 参照が無ければ空配列（＝削除可能）。空配列を「削除可能」の合図に使うので形を固定する */
    public function test_lot_without_references_has_no_blockers(): void
    {
        $lot = $this->makeLot($this->makeProject());

        $this->assertSame([], DeletionBlockers::forLotIds([$lot->id]));
        $this->assertSame([], DeletionBlockers::forLotIds([]));
    }

    /** 建売・注文住宅・契約が区画を参照していたら 3 種とも拾う */
    public function test_lot_blockers_collect_all_three_kinds(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract(['lot_id' => $lot->id], '区画契約');
        $this->makeProperty($lot);
        $this->makeCustomOrder($lot);

        $blockers = DeletionBlockers::forLotIds([$lot->id]);

        $this->assertCount(3, $blockers);
        $this->assertSame(['契約', '建売物件', '注文住宅'], array_column($blockers, 'label'));
        $this->assertSame('区画契約（山西 太郎 様）', $blockers[0]['items'][0]['name']);
        $this->assertSame('HS-0007 建売テスト邸', $blockers[1]['items'][0]['name']);
        $this->assertSame('CO-0003 注文住宅テスト邸', $blockers[2]['items'][0]['name']);
        $this->assertStringContainsString('/housing/properties/', $blockers[1]['items'][0]['url']);
        // ルート名の取り違え（例: ...show → ...index）を検出するため、契約・注文住宅の url も固定する
        $this->assertStringContainsString('/realestate/contracts/', $blockers[0]['items'][0]['url']);
        $this->assertStringContainsString('/housing/custom-orders/', $blockers[2]['items'][0]['url']);
    }

    /** 他の区画の参照を巻き込まない（whereIn の取り違えを検出する） */
    public function test_lot_blockers_do_not_leak_across_lots(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);

        $this->makeProperty($lotA);

        $this->assertCount(1, DeletionBlockers::forLotIds([$lotA->id]));
        $this->assertSame([], DeletionBlockers::forLotIds([$lotB->id]));
    }
}
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL — `Class "App\Support\DeletionBlockers" not found`

- [ ] **Step 3: `DeletionBlockers` を作る**

`app/Support/DeletionBlockers.php` を新規作成:

```php
<?php

namespace App\Support;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProject;
use Illuminate\Support\Collection;

/**
 * 削除ブロッカー — 仕入れ案件 / 分譲地PJ / 区画を参照していて、
 * 消すと他モジュールのレコードが壊れるデータを集める。
 *
 * ブロック対象は 契約(re_contracts) / 建売物件(hs_properties) / 注文住宅(hs_custom_orders) の 3 種のみ。
 * 判定基準は「**SET NULL で他モジュールのレコードが壊れるか**」:
 * 本番の FK は ON DELETE SET NULL だが、参照が NULL になっても hs_* の land_source_type は
 * 'project_lot' のまま残るため、「土地元が分譲地区画」と名乗りながら参照先が無い矛盾状態になる。
 * その状態では HsProperty::getReferenceLandSellingPrice() / getLandSourceDisplay() が
 * どちらも null を返し、土地価格・土地原価の参照が黙って消える。
 *
 * 対象外:
 *   - buyer_surveys        … project_id は任意の紐づけ。NULL でも「分譲地未指定」で成立し矛盾しない
 *   - re_*_costs / lots / drawings … CASCADE の自前の子データ。既存の confirm() で予告済み
 *   - attachments          … ポリモーフィックで FK 無し（孤児行は別件）
 *
 * ⚠ 画面のパネルとサーバのガードが別々に判定すると、片方だけ直したときに
 *    「パネルは削除可と言うのにサーバが拒否する」食い違いが生まれる（Bug #41）。
 *    3 経路（ProcurementController::destroy / ProjectController::destroy / destroyLot）と
 *    詳細画面・区画一覧が**すべてこのクラスを通る**こと。
 *
 * 戻り値は「空配列 = 削除可能」。件数は count($items) で数え、件数と名称を別々に持たない。
 */
class DeletionBlockers
{
    /**
     * 指定した区画群を参照しているデータ。
     * 区画 1 件でも複数でも同じ whereIn のバルククエリで引く（N+1 を避ける）。
     * PJ 配下の全区画をまとめて見るときは forProject() を使う（forProject() は本メソッドを呼ばない）。
     *
     * @param  array<int>  $lotIds
     * @return array<int, array{label: string, items: array<int, array{name: string, url: string}>}>
     */
    public static function forLotIds(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        [$properties, $orders] = self::lotScopedHousing($lotIds);

        return self::assemble(self::lotScopedContracts($lotIds), $properties, $orders);
    }

    /**
     * 区画群を参照している住宅事業のレコード。
     * 「どのテーブルのどの列が区画を指しているか」の定義は**ここ 1 箇所だけ**にする
     *（3 つの入口が同じ whereIn を各自書くと、列名変更で 1 つ直し忘れたときに
     *  パネルとサーバの判定が割れる。Bug #41 / #42）。
     *
     * @param  array<int>  $lotIds
     * @return array{0: Collection, 1: Collection} [建売物件, 注文住宅]
     */
    private static function lotScopedHousing(array $lotIds): array
    {
        if ($lotIds === []) {
            return [collect(), collect()];
        }

        return [
            HsProperty::whereIn('re_project_lot_id', $lotIds)->get(),
            HsCustomOrder::whereIn('re_project_lot_id', $lotIds)->get(),
        ];
    }

    /**
     * 区画群を参照している契約。
     *
     * ⚠ forProject() はこれを使わない。PJ 直参照と区画参照を「グループ化した OR の 1 クエリ」に
     *    まとめる必要があり（両方に紐づく契約の二重計上を防ぐ。設計書 §3.4）、
     *    ここの lot_id 単独クエリとは形が違う。
     */
    private static function lotScopedContracts(array $lotIds): Collection
    {
        if ($lotIds === []) {
            return collect();
        }

        return ReContract::with('buyer')->whereIn('lot_id', $lotIds)->get();
    }

    /**
     * 種別ごとにまとめる。items が空の種別はエントリごと含めない。
     *
     * @return array<int, array{label: string, items: array<int, array{name: string, url: string}>}>
     */
    private static function assemble(Collection $contracts, Collection $properties, Collection $orders): array
    {
        // 呼び出し元の with('buyer') 忘れを静かな N+1 にしない（読み込み済みなら no-op）。
        // ⚠ 空の $contracts は forEachLotId() の get($key, collect()) フォールバックで
        //    base Collection（Eloquent Collection ではない）になることがあり、
        //    loadMissing() は Eloquent Collection にしか存在しないため、空なら呼ばない
        //    （空である以上ロード対象も無いので、意味的にも no-op で正しい）。
        if ($contracts->isNotEmpty()) {
            $contracts->loadMissing('buyer');
        }

        $blockers = [];

        if ($contracts->isNotEmpty()) {
            $blockers[] = [
                'label' => '契約',
                'items' => $contracts->map(fn (ReContract $c) => [
                    'name' => $c->buyer_display_name
                        ? $c->property_name . '（' . $c->buyer_display_name . ' 様）'
                        : $c->property_name,
                    'url'  => route('realestate.contracts.show', $c),
                ])->values()->all(),
            ];
        }

        if ($properties->isNotEmpty()) {
            $blockers[] = [
                'label' => '建売物件',
                'items' => $properties->map(fn (HsProperty $p) => [
                    'name' => $p->property_code . ' ' . $p->property_name,
                    'url'  => route('housing.properties.show', $p),
                ])->values()->all(),
            ];
        }

        if ($orders->isNotEmpty()) {
            $blockers[] = [
                'label' => '注文住宅',
                'items' => $orders->map(fn (HsCustomOrder $o) => [
                    'name' => $o->order_code . ' ' . $o->order_name,
                    'url'  => route('housing.custom-orders.show', $o),
                ])->values()->all(),
            ];
        }

        return $blockers;
    }
}
```

- [ ] **Step 4: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (3 tests, ...)`

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Support/DeletionBlockers.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 区画を参照しているデータを集める DeletionBlockers を追加"
```

---

## Task 2: `forProcurementId()` と `forProject()`（契約の二重計上を潰す）

**Files:**
- Modify: `app/Support/DeletionBlockers.php`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾（クラスの閉じ `}` の直前）に追加:

```php
    // ============================================================
    // Task 2: forProcurementId() / forProject()
    // ============================================================

    /**
     * 仕入れ案件を参照している契約・建売・注文住宅を拾う。
     *
     * ⚠ 3 種そろえないと forProcurementId() の HsCustomOrder クエリを削除しても
     *    このテストは緑のまま通る（設計書 §2.2 が要求する 3 種の 1 つが無防備になる）。
     */
    public function test_procurement_blockers_collect_all_three_kinds(): void
    {
        $procurement = $this->makeProcurement();

        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');
        HsProperty::create([
            'property_code'     => 'HS-0011',
            'property_name'     => '仕入れ土地の建売',
            'status'            => 'construction',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市5-5-5',
            'created_by'        => 1,
        ]);
        HsCustomOrder::create([
            'order_code'        => 'CO-0012',
            'order_name'        => '仕入れ土地の注文住宅',
            'status'            => 'contracted',
            'customer_name'     => '西野 一郎',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市6-6-6',
            'created_by'        => 1,
        ]);

        $blockers = DeletionBlockers::forProcurementId($procurement->id);

        $this->assertSame(['契約', '建売物件', '注文住宅'], array_column($blockers, 'label'));
        $this->assertSame('仕入れ契約（山西 太郎 様）', $blockers[0]['items'][0]['name']);
        $this->assertSame('CO-0012 仕入れ土地の注文住宅', $blockers[2]['items'][0]['name']);
        // summarize() の区切り文字（・）と件数・語順を複数エントリで固定する（1 エントリだけだと implode の区切り文字が見えない）
        $this->assertSame(
            '契約 1 件・建売物件 1 件・注文住宅 1 件が参照しているため削除できません。',
            DeletionBlockers::summarize($blockers)
        );
    }

    /** 参照が無ければ空配列。summarize([]) が空文字であることも合わせて固定する（区画一覧の delete_blocked_reason で load-bearing） */
    public function test_procurement_without_references_has_no_blockers(): void
    {
        $procurement = $this->makeProcurement();

        $this->assertSame([], DeletionBlockers::forProcurementId($procurement->id));
        $this->assertSame('', DeletionBlockers::summarize([]));
    }

    /** PJ 直参照の契約と、配下区画を参照する建売の両方を拾う */
    public function test_project_blockers_collect_direct_and_via_lot(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract(['project_id' => $project->id], 'PJ直参照の契約');
        $this->makeProperty($lot);

        $blockers = DeletionBlockers::forProject($project);

        $this->assertSame(['契約', '建売物件'], array_column($blockers, 'label'));
        $this->assertCount(1, $blockers[0]['items']);
        $this->assertCount(1, $blockers[1]['items']);
    }

    /**
     * ⑤ 契約が project_id と lot_id の**両方**で紐づくとき、件数は 1。
     *
     * ⚠ 2 本のクエリに分けて足すと 2 件に見える（設計書 §3.4）。
     *    forProject() をグループ化 OR の 1 クエリから 2 クエリ + concat に戻すと**このテストが赤になる**。
     */
    public function test_contract_linked_by_both_project_and_lot_is_counted_once(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract([
            'project_id' => $project->id,
            'lot_id'     => $lot->id,
        ], '二重紐づけ契約');

        $blockers = DeletionBlockers::forProject($project);

        $this->assertCount(1, $blockers, '契約以外の種別が混ざっていない');
        $this->assertCount(1, $blockers[0]['items'], '同じ契約が 2 件に増えていない');
        $this->assertSame('契約 1 件が参照しているため削除できません。', DeletionBlockers::summarize($blockers));
    }

    /** 区画も参照も無い PJ は空配列（lotIds が空のときにクエリが暴走しないことも兼ねる） */
    public function test_project_without_lots_and_references_has_no_blockers(): void
    {
        $this->assertSame([], DeletionBlockers::forProject($this->makeProject()));
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL — `Call to undefined method App\Support\DeletionBlockers::forProcurementId()`

- [ ] **Step 3: 2 メソッドを追加**

`app/Support/DeletionBlockers.php` の `forLotIds()` の直後（`lotScopedHousing()` の前）に挿入。
`lotScopedHousing()` / `lotScopedContracts()` は Task 1 で既に定義済みなのでここでは触らず、
`forProject()` の契約以外（建売・注文住宅）はそれを再利用する:

```php
    /**
     * 指定した仕入れ案件を参照しているデータ。
     *
     * @return array<int, array{label: string, items: array<int, array{name: string, url: string}>}>
     */
    public static function forProcurementId(int $procurementId): array
    {
        return self::assemble(
            ReContract::with('buyer')->where('procurement_id', $procurementId)->get(),
            HsProperty::where('re_procurement_id', $procurementId)->get(),
            HsCustomOrder::where('re_procurement_id', $procurementId)->get(),
        );
    }

    /**
     * 分譲地PJ を参照しているデータ（PJ 直参照 ＋ 配下区画経由）。
     *
     * ⚠ 契約は project_id（PJ 直参照）と lot_id（区画参照）を**両方持ちうる**。
     *    2 本のクエリに分けて足すと、両方に該当する契約が「2 件」と二重に出る（設計書 §3.4）。
     *    グループ化した OR の 1 クエリにして、1 行が 1 回しか返らないようにしてある
     *    （後から unique('id') を書き忘れる余地を作らないため）。
     *    この OR クエリは lotScopedContracts() では表せない形なので、契約だけはここで自前に組む。
     *
     * @return array<int, array{label: string, items: array<int, array{name: string, url: string}>}>
     */
    public static function forProject(ReProject $project): array
    {
        $lotIds = $project->lots()->pluck('id')->all();

        $contracts = ReContract::with('buyer')
            ->where(function ($q) use ($project, $lotIds) {
                $q->where('project_id', $project->id);
                if ($lotIds !== []) {
                    $q->orWhereIn('lot_id', $lotIds);
                }
            })
            ->get();

        [$properties, $orders] = self::lotScopedHousing($lotIds);

        return self::assemble($contracts, $properties, $orders);
    }
```

- [ ] **Step 4: 実行 — `summarize()` がまだ無いので 1 本だけ落ちる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL 1 本のみ — `test_contract_linked_by_both_project_and_lot_is_counted_once` が
`Call to undefined method App\Support\DeletionBlockers::summarize()`。他 7 本は PASS。

- [ ] **Step 5: `summarize()` を追加**

`forProject()` の直後（`assemble()` の前）に挿入:

```php
    /**
     * 赤バナー・削除ボタンの title・区画の delete_blocked_reason が共有する 1 文を組む。
     *   例: 契約 1 件・建売物件 7 件が参照しているため削除できません。
     *
     * 名称は入れない（レイアウトの赤バナーは 1 行の <span> なので名称を並べると収まらない）。
     * 名称は詳細画面のパネルで見せる。
     *
     * 空配列なら空文字（呼び出し側は空配列のときは呼ばない前提だが、
     * 区画一覧の delete_blocked_reason は全区画分を組むので空文字が要る）。
     */
    public static function summarize(array $blockers): string
    {
        if ($blockers === []) {
            return '';
        }

        $parts = array_map(
            fn (array $b) => $b['label'] . ' ' . count($b['items']) . ' 件',
            $blockers
        );

        return implode('・', $parts) . 'が参照しているため削除できません。';
    }
```

- [ ] **Step 6: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (8 tests, ...)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Support/DeletionBlockers.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 仕入れ案件・分譲地PJ の参照集計と要約文を DeletionBlockers に追加"
```

---

## Task 3: `forEachLotId()` — 区画一覧用のバルク版

**Files:**
- Modify: `app/Support/DeletionBlockers.php`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

区画一覧は区画ごとに `delete_blocked` を出す。区画ループの中で `forLotIds([$id])` を呼ぶと
区画数 × 3 クエリの N+1 になるので、バルククエリ 3 本で全区画分を組む。

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加:

```php
    // ============================================================
    // Task 3: forEachLotId()
    // ============================================================

    /** 区画ごとに分かれること・参照の無い区画は空配列であること */
    public function test_for_each_lot_id_groups_blockers_per_lot(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);
        $lotC    = $this->makeLot($project, 3);

        $this->makeProperty($lotA, 'HS-0001');
        $this->makeCustomOrder($lotB, 'CO-0001');

        $grouped = DeletionBlockers::forEachLotId([$lotA->id, $lotB->id, $lotC->id]);

        $this->assertSame(['建売物件'], array_column($grouped[$lotA->id], 'label'));
        $this->assertSame(['注文住宅'], array_column($grouped[$lotB->id], 'label'));
        $this->assertSame([], $grouped[$lotC->id], '参照の無い区画は空配列');

        // 渡した区画は全てキーとして返る（?? [] のフォールバック頼みにしない）
        $this->assertSame([$lotA->id, $lotB->id, $lotC->id], array_keys($grouped));
    }

    /** 空配列を渡しても壊れない */
    public function test_for_each_lot_id_with_empty_input(): void
    {
        $this->assertSame([], DeletionBlockers::forEachLotId([]));
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL — `Call to undefined method App\Support\DeletionBlockers::forEachLotId()`

- [ ] **Step 3: `forEachLotId()` を追加**

`forLotIds()` の直後に挿入。ここも Task 1 で定義済みの `lotScopedHousing()` / `lotScopedContracts()`
を再利用し、それぞれを区画 id で `groupBy` する:

```php
    /**
     * 区画ごとのブロッカー（区画一覧の delete_blocked 用）。
     * 区画数によらずバルククエリ 3 本だけで全区画分を組む（ループ内で forLotIds() を呼ぶと N+1）。
     *
     * @param  array<int>  $lotIds
     * @return array<int, array<int, array{label: string, items: array<int, array{name: string, url: string}>}>> 区画 id => ブロッカー配列（空配列 = 削除可能）
     */
    public static function forEachLotId(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        // groupBy のキーは (int) に揃える（ドライバによって属性が string で返ることがあるため）
        [$propertiesAll, $ordersAll] = self::lotScopedHousing($lotIds);
        $contracts  = self::lotScopedContracts($lotIds)->groupBy(fn (ReContract $c) => (int) $c->lot_id);
        $properties = $propertiesAll->groupBy(fn (HsProperty $p) => (int) $p->re_project_lot_id);
        $orders     = $ordersAll->groupBy(fn (HsCustomOrder $o) => (int) $o->re_project_lot_id);

        $result = [];
        foreach ($lotIds as $lotId) {
            $result[(int) $lotId] = self::assemble(
                $contracts->get((int) $lotId, collect()),
                $properties->get((int) $lotId, collect()),
                $orders->get((int) $lotId, collect()),
            );
        }

        return $result;
    }
```

- [ ] **Step 4: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (10 tests, ...)`

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Support/DeletionBlockers.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 区画ごとのブロッカーをバルク取得する forEachLotId を追加"
```

---

## Task 4: 3 モデルの `deletionBlockers()` ラッパー

**Files:**
- Modify: `app/Models/ReProcurement.php`
- Modify: `app/Models/ReProject.php`
- Modify: `app/Models/ReProjectLot.php`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

呼び出し側が `$model->deletionBlockers()` と書けるようにするための薄いラッパー。
判定の実体は `DeletionBlockers` にあり、モデルにロジックは置かない。

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加:

```php
    // ============================================================
    // Task 4: モデルのラッパー
    // ============================================================

    /** 3 モデルのラッパーが DeletionBlockers と同じ結果を返す（配線の確認） */
    public function test_model_wrappers_delegate_to_deletion_blockers(): void
    {
        $procurement = $this->makeProcurement();
        $project     = $this->makeProject();
        $lot         = $this->makeLot($project);

        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');
        $this->makeProperty($lot);

        $this->assertSame(
            DeletionBlockers::forProcurementId($procurement->id),
            $procurement->deletionBlockers()
        );
        $this->assertSame(
            DeletionBlockers::forProject($project),
            $project->deletionBlockers()
        );
        $this->assertSame(
            DeletionBlockers::forLotIds([$lot->id]),
            $lot->deletionBlockers()
        );
    }

    /** 参照が無ければ 3 モデルとも空配列（＝削除可能） */
    public function test_model_wrappers_return_empty_when_free(): void
    {
        $project = $this->makeProject();

        $this->assertSame([], $this->makeProcurement()->deletionBlockers());
        $this->assertSame([], $project->deletionBlockers());
        $this->assertSame([], $this->makeLot($project)->deletionBlockers());
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL — `Call to undefined method App\Models\ReProcurement::deletionBlockers()`

- [ ] **Step 3: `ReProcurement` にラッパーを足す**

`app/Models/ReProcurement.php` の import に 1 行追加（`use App\Support\ConsumptionTax;` の直後）:

```php
use App\Support\DeletionBlockers;
```

そしてファイル末尾、`syncPropertyPurchaseCost()` の閉じ `}` とクラスの閉じ `}` の間に追加:

```php

    /**
     * この仕入れ案件を参照していて、消えると壊れるデータ（契約・建売物件・注文住宅）。
     * 空配列なら削除可能。判定の実体は DeletionBlockers（画面とサーバで共有する）。
     */
    public function deletionBlockers(): array
    {
        return DeletionBlockers::forProcurementId($this->id);
    }
```

- [ ] **Step 4: `ReProject` にラッパーを足す**

`app/Models/ReProject.php` の import に 1 行追加（`use App\Support\AreaConverter;` の直後）:

```php
use App\Support\DeletionBlockers;
```

ファイル末尾、`syncPropertyPurchaseCost()` の閉じ `}` とクラスの閉じ `}` の間に追加:

```php

    /**
     * この分譲地PJ を参照していて、消えると壊れるデータ（PJ 直参照 ＋ 配下区画経由）。
     * 空配列なら削除可能。判定の実体は DeletionBlockers（画面とサーバで共有する）。
     */
    public function deletionBlockers(): array
    {
        return DeletionBlockers::forProject($this);
    }
```

- [ ] **Step 5: `ReProjectLot` にラッパーを足す**

`app/Models/ReProjectLot.php` の import に 1 行追加（`use App\Support\AreaConverter;` の直後）:

```php
use App\Support\DeletionBlockers;
```

ファイル末尾、`sqmToTsubo()` の閉じ `}` とクラスの閉じ `}` の間に追加:

```php

    /**
     * この区画を参照していて、消えると壊れるデータ（契約・建売物件・注文住宅）。
     * 空配列なら削除可能。判定の実体は DeletionBlockers（画面とサーバで共有する）。
     */
    public function deletionBlockers(): array
    {
        return DeletionBlockers::forLotIds([$this->id]);
    }
```

- [ ] **Step 6: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (12 tests, ...)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Models/ReProcurement.php app/Models/ReProject.php app/Models/ReProjectLot.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 3 モデルに deletionBlockers() ラッパーを追加"
```

---

## Task 5: 仕入れ案件の削除ガード（テスト ①）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php:252-261`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加:

```php
    // ============================================================
    // Task 5: ① 仕入れ案件の削除ガード（HTTP）
    // ============================================================

    /** ① 契約が紐づく仕入れ案件は削除できず、レコードが残る */
    public function test_procurement_with_contract_cannot_be_deleted(): void
    {
        $procurement = $this->makeProcurement();
        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');

        $response = $this->actingAs($this->executive())
            ->from("/realestate/procurements/{$procurement->id}")
            ->delete("/realestate/procurements/{$procurement->id}");

        $response->assertRedirect("/realestate/procurements/{$procurement->id}");
        $response->assertSessionHas('error', '契約 1 件が参照しているため削除できません。');
        $this->assertDatabaseHas('re_procurements', ['id' => $procurement->id]);
    }

    /** 建売物件が紐づく仕入れ案件も削除できない */
    public function test_procurement_with_housing_property_cannot_be_deleted(): void
    {
        $procurement = $this->makeProcurement();
        HsProperty::create([
            'property_code'     => 'HS-0011',
            'property_name'     => '仕入れ土地の建売',
            'status'            => 'construction',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市5-5-5',
            'created_by'        => 1,
        ]);

        $this->actingAs($this->executive())
            ->delete("/realestate/procurements/{$procurement->id}")
            ->assertSessionHas('error', '建売物件 1 件が参照しているため削除できません。');

        $this->assertDatabaseHas('re_procurements', ['id' => $procurement->id]);
    }

    /** ④ 依存が無ければ従来どおり削除できる（ガードが常時ブロック化していないことの確認） */
    public function test_procurement_without_references_can_still_be_deleted(): void
    {
        $procurement = $this->makeProcurement();

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/procurements/{$procurement->id}");

        $response->assertRedirect('/realestate/procurements');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('re_procurements', ['id' => $procurement->id]);
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL 2 本 — `test_procurement_with_contract_cannot_be_deleted` と
`test_procurement_with_housing_property_cannot_be_deleted`（ガードが無いので削除が通り、
`assertSessionHas('error', ...)` で落ちる）。④ は PASS。

- [ ] **Step 3: import を足す**

`app/Http/Controllers/RealEstate/ProcurementController.php` の
`use App\Support\Settings;` の**前**（アルファベット順を保つ）に 1 行:

```php
use App\Support\DeletionBlockers;
```

- [ ] **Step 4: `destroy()` にガードを入れる**

`app/Http/Controllers/RealEstate/ProcurementController.php:252-261` を差し替える。

置換前:

```php
    public function destroy(ReProcurement $procurement)
    {
        $code = $procurement->procurement_code;

        // 原価明細も一緒に削除（cascadeOnDelete）
        $procurement->delete();
```

置換後:

```php
    public function destroy(ReProcurement $procurement)
    {
        // 契約・建売物件・注文住宅が参照している間は消させない。
        // 本番の FK は ON DELETE SET NULL なので、消すと参照側が「土地元が仕入れ案件」と
        // 名乗ったまま参照先を失う矛盾状態になる（判定は DeletionBlockers に一本化）。
        if ($blockers = $procurement->deletionBlockers()) {
            return back()->with('error', DeletionBlockers::summarize($blockers));
        }

        $code = $procurement->procurement_code;

        // 原価明細も一緒に削除（cascadeOnDelete）
        $procurement->delete();
```

- [ ] **Step 5: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (15 tests, ...)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Http/Controllers/RealEstate/ProcurementController.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 参照中の仕入れ案件の削除をサーバ側で禁止"
```

---

## Task 6: 分譲地PJ の削除ガード（テスト ②）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php:278-292`
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`（`re_project_drawings` テーブルを追加。下記 Step 2 の注記参照）
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加:

```php
    // ============================================================
    // Task 6: ② 分譲地PJ の削除ガード（HTTP）
    // ============================================================

    /** ② 建売が紐づく区画を持つ分譲地は削除できず、PJ・区画とも残る */
    public function test_project_with_housing_property_on_a_lot_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);
        $this->makeProperty($lot);

        $response = $this->actingAs($this->executive())
            ->from("/realestate/projects/{$project->id}")
            ->delete("/realestate/projects/{$project->id}");

        $response->assertRedirect("/realestate/projects/{$project->id}");
        $response->assertSessionHas('error', '建売物件 1 件が参照しているため削除できません。');
        $this->assertDatabaseHas('re_projects', ['id' => $project->id]);
        $this->assertDatabaseHas('re_project_lots', ['id' => $lot->id]);
    }

    /** PJ を直接参照する契約でもブロックされる（区画経由だけではない） */
    public function test_project_with_direct_contract_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $this->makeContract(['project_id' => $project->id], 'PJ直参照の契約');

        $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}")
            ->assertSessionHas('error', '契約 1 件が参照しているため削除できません。');

        $this->assertDatabaseHas('re_projects', ['id' => $project->id]);
    }

    /** ④ 区画があっても参照が無ければ従来どおり削除できる */
    public function test_project_without_references_can_still_be_deleted(): void
    {
        $project = $this->makeProject();
        $this->makeLot($project);

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}");

        $response->assertRedirect('/realestate/projects');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('re_projects', ['id' => $project->id]);
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL 2 本 — `test_project_with_housing_property_on_a_lot_cannot_be_deleted` と
`test_project_with_direct_contract_cannot_be_deleted`。

⚠ **実測ではここで FAIL 3 本になる可能性がある。** `tests/Concerns/CreatesRealEstateSchema.php`
（Task 1 で作成）に `re_project_drawings` テーブルが無いと、`ProjectController::destroy()` の
`foreach ($project->drawings as $drawing)` が `SQLSTATE[HY000]: no such column` 相当で 500 になり、
`test_project_without_references_can_still_be_deleted`（④、本来 destroy() の成功パスを見るだけの
テスト）まで巻き込まれて落ちる。これはガードの設計とは無関係な**テストインフラの欠落**なので、
`re_project_drawings` テーブルをスキーマに追加してから改めて FAIL 2 本になることを確認すること
（列定義は `ReProjectDrawing::$fillable` と `storeDrawing()` の書き込み内容に合わせ、
`project_id` は同ファイル内の他 FK に倣い `unsignedBigInteger`、`uploaded_by` は
`created_by`/`updated_by` の慣例に倣い `unsignedInteger` nullable）。

- [ ] **Step 3: import を足す**

`app/Http/Controllers/RealEstate/ProjectController.php` の
`use App\Support\AttachmentDelivery;` の**後**（アルファベット順で `AttachmentDelivery` < `DeletionBlockers` < `TsuboPrice`）に 1 行:

```php
use App\Support\DeletionBlockers;
```

- [ ] **Step 4: `destroy()` にガードを入れる**

`app/Http/Controllers/RealEstate/ProjectController.php:278-285` を差し替える。

⚠ **ガードは図面ファイルの物理削除より前に置く**（ブロックされたのにファイルだけ消える事故を防ぐ）。

置換前:

```php
    public function destroy(ReProject $project)
    {
        $code = $project->project_code;

        // 図面ファイルの物理削除
        foreach ($project->drawings as $drawing) {
            Storage::disk('public')->delete($drawing->file_path);
        }
```

置換後:

```php
    public function destroy(ReProject $project)
    {
        // 契約・建売物件・注文住宅が参照している間は消させない。
        // ⚠ 図面ファイルの物理削除より**前**に判定する
        //   （ブロックされたのにファイルだけ消える事故を防ぐ）。
        if ($blockers = $project->deletionBlockers()) {
            return back()->with('error', DeletionBlockers::summarize($blockers));
        }

        $code = $project->project_code;

        // 図面ファイルの物理削除
        foreach ($project->drawings as $drawing) {
            Storage::disk('public')->delete($drawing->file_path);
        }
```

- [ ] **Step 5: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (18 tests, ...)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Http/Controllers/RealEstate/ProjectController.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 参照中の分譲地PJ の削除をサーバ側で禁止"
```

- [ ] **Step 7（Task 6 完了後のコードレビューで追記）: 図面ファイルの物理削除順序を固定するテスト**

`ProjectController::destroy()` のガードは「図面ファイルの物理削除より**前**に判定する」と
コメントで宣言しているが、Step 1〜6 のどのテストもその順序を実際には検証していなかった
（`$project->drawings` を走査するテストが一本も無く、ループが常に空振りしていた）。
コードレビュー（2 本のレビュー、独立に同一指摘）で発見され、別コミットで追加した。

`DeletionGuardTest` の `test_project_with_direct_contract_cannot_be_deleted` の直後に追加:

```php
    /** ブロックされたとき図面ファイルは消えない（ガードが物理削除より前にあることを固定） */
    public function test_blocked_project_deletion_does_not_touch_drawing_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('re_drawings/plan.pdf', 'dummy');

        $project = $this->makeProject();
        $lot     = $this->makeLot($project);
        $this->makeProperty($lot);
        ReProjectDrawing::create([
            'project_id' => $project->id,
            'file_name'  => 'plan.pdf',
            'file_path'  => 're_drawings/plan.pdf',
            'file_size'  => 5,
            'mime_type'  => 'application/pdf',
        ]);

        $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}")
            ->assertSessionHas('error');

        Storage::disk('public')->assertExists('re_drawings/plan.pdf');
        $this->assertDatabaseHas('re_project_drawings', ['project_id' => $project->id]);
    }
```

既存の `test_project_without_references_can_still_be_deleted`（④）は成功パスの物理削除
（`Storage::delete()`）が一度も実行されたことが無かったので、同じ観点で強化する:

```php
    /** ④ 区画があっても参照が無ければ従来どおり削除できる（成功パスの図面物理削除も固定） */
    public function test_project_without_references_can_still_be_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('re_drawings/plan.pdf', 'dummy');

        $project = $this->makeProject();
        $this->makeLot($project);
        ReProjectDrawing::create([
            'project_id' => $project->id,
            'file_name'  => 'plan.pdf',
            'file_path'  => 're_drawings/plan.pdf',
            'file_size'  => 5,
            'mime_type'  => 'application/pdf',
        ]);

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}");

        $response->assertRedirect('/realestate/projects');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('re_projects', ['id' => $project->id]);
        Storage::disk('public')->assertMissing('re_drawings/plan.pdf');
    }
```

import を追加（アルファベット順）: `use App\Models\ReProjectDrawing;`（`ReProjectLot` の前）、
`use Illuminate\Support\Facades\Storage;`（`RefreshDatabase` の後）。

⚠ **`Storage::fake('public')` は必須。** 付けないと実 `storage/app/public` を触る。

検証は Task 10 の変異 11 を参照（ガードを物理削除の後ろへ動かす変異で実際に赤になることを確認済み）。
このステップはコード自体を変更しない（既にガードは正しい位置にある）。テストのみの追加。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "test(realestate): ガードが図面削除より前にあることと message キーの受け手を固定"
```

（Task 7 の Step 7 と合わせて 1 コミット。下記参照。）

---

## Task 7: 区画 1 件の削除ガード + JSON キーを `message` に揃える（テスト ③）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php:600-610`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

⚠ **JSON のキーは `error` ではなく `message`。** 呼び出し元の JS（`lots.blade.php:461`）は
`err.message || 'エラーが発生しました。'` を読む。既存の 403 が `['error' => ...]` を返していて
理由が画面に出ない粗があるので、同じコミットで揃える。

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加:

```php
    // ============================================================
    // Task 7: ③ 区画 1 件の削除ガード（Ajax）
    // ============================================================

    /** ③ 注文住宅が紐づく区画は 422 + message で拒否され、区画が残る */
    public function test_lot_with_custom_order_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);
        $this->makeCustomOrder($lot);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$project->id}/lots/{$lot->id}");

        $response->assertStatus(422);
        // ⚠ キーは message。lots.blade.php の JS が err.message を読む（error だと理由が出ない）
        $response->assertExactJson(['message' => '注文住宅 1 件が参照しているため削除できません。']);
        $this->assertDatabaseHas('re_project_lots', ['id' => $lot->id]);
    }

    /** ④ 参照の無い区画は従来どおり削除できる */
    public function test_lot_without_references_can_still_be_deleted(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$project->id}/lots/{$lot->id}");

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('re_project_lots', ['id' => $lot->id]);
    }

    /** 所属違いの 403 も message キーで返す（JS が理由を出せるように揃える） */
    public function test_lot_from_another_project_is_rejected_with_message_key(): void
    {
        $projectA = $this->makeProject('PJ-001');
        $projectB = $this->makeProject('PJ-002');
        $lot      = $this->makeLot($projectB);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$projectA->id}/lots/{$lot->id}");

        $response->assertStatus(403);
        $response->assertExactJson(['message' => '不正なリクエストです。']);
        $this->assertDatabaseHas('re_project_lots', ['id' => $lot->id]);
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL 2 本 — `test_lot_with_custom_order_cannot_be_deleted`（200 が返る）と
`test_lot_from_another_project_is_rejected_with_message_key`（`error` キーで返る）。

- [ ] **Step 3: `destroyLot()` を直す**

`app/Http/Controllers/RealEstate/ProjectController.php:600-606` を差し替える。

置換前:

```php
    public function destroyLot(ReProject $project, ReProjectLot $lot)
    {
        if ($lot->project_id !== $project->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        $lot->delete();
```

置換後:

```php
    public function destroyLot(ReProject $project, ReProjectLot $lot)
    {
        // ⚠ JSON のキーは message。呼び出し元 JS（lots.blade.php の deleteLot）は
        //   err.message を読むので、error で返すと「エラーが発生しました。」としか出ない。
        if ($lot->project_id !== $project->id) {
            return response()->json(['message' => '不正なリクエストです。'], 403);
        }

        // 契約・建売物件・注文住宅が参照している間は消させない
        //（PJ 削除だけ塞ぐと「PJ は消せないのに区画は消せる」抜け道が残る）
        if ($blockers = $lot->deletionBlockers()) {
            return response()->json(['message' => DeletionBlockers::summarize($blockers)], 422);
        }

        $lot->delete();
```

- [ ] **Step 4: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (21 tests, ...)`

- [ ] **Step 5: 全テストを走らせて既存への影響を確認**

`error` → `message` のキー変更は既存テストに影響しうるので、ここで全体を回す。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit
```

Expected: 赤 0 本。もし `['error' => '不正なリクエストです。']` を期待する既存テストが落ちたら、
そのテストの期待値を `message` に更新する（JS が読むキーが正しい側なので）。

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Http/Controllers/RealEstate/ProjectController.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 参照中の区画の削除を禁止し Ajax の JSON キーを message に統一"
```

- [ ] **Step 7（Task 7 完了後のコードレビューで追記）: `message` キーの受け手を固定する走査テスト**

サーバが `message` を返すことは Step 1〜6 で固定したが、**読む側の Blade（`lots.blade.php`）は
何も固定していなかった**。Unit 4 が `lots.blade.php` を触る際に JS のエラー分岐（`err.message`）が
壊れても、サーバ側テストは全部緑のまま理由が画面に出なくなる。呼び出し側と定義側を対で検証する
規約（Bug #28 / #35）に合わせ、走査テストを 1 本追加した。

`DeletionGuardTest` の末尾（`test_lot_from_another_project_is_rejected_with_message_key` の後）に追加:

```php
    /**
     * 呼び出し側（Blade）と定義側（コントローラ）を対で固定する。
     * サーバが message を返しても、読む側が err.message を見なくなれば
     * 理由は画面に出ない。サーバ側テストは全部緑のまま通るので、ここで固定する（Bug #28 / #35）。
     *
     * ⚠ ファイル全体に対する assertStringContainsString だと false-pass する。
     *    同じ lots.blade.php 内の storeLot / saveLot（区画の追加・更新）にも
     *    "err.message || 'エラーが発生しました。'" が別途あり、deleteLot だけを
     *    err.error に変異させても消えないため（実測で確認済み）。
     *    deleteLot 関数の本体だけを正規表現で切り出してから見る。
     */
    public function test_lots_view_reads_message_key_from_delete_error(): void
    {
        $blade = file_get_contents(resource_path('views/realestate/projects/lots.blade.php'));

        $matched = preg_match(
            '/deleteLot:\s*function\s*\([^)]*\)\s*\{(.*?)\n        \},/s',
            $blade,
            $m
        );
        $this->assertSame(1, $matched, 'deleteLot 関数本体を抽出できなかった（走査の空振り防止）');

        $this->assertStringContainsString("err.message || 'エラーが発生しました。'", $m[1]);
    }
```

⚠ **レビューが提示した最初のテスト案は `file_get_contents()` した文字列全体に対して
`assertStringContainsString` するだけだった。実際に `err.message` → `err.error` の変異を入れて
確かめたところ、それでは赤にならなかった**（`lots.blade.php` には `storeLot`/`saveLot`
（区画の追加・更新）用の同一文字列が別途 2 箇所あり、`deleteLot` だけを変異させても
ファイル全体としては文字列が残るため）。`deleteLot` 関数の本体だけを正規表現で切り出してから
判定する形に直して、変異で赤になることを確認した（Task 10 の変異 12 参照）。

このステップはコード自体を変更しない（既に Blade は正しい）。テストのみの追加。
Task 6 の Step 7 と合わせて 1 コミット:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "test(realestate): ガードが図面削除より前にあることと message キーの受け手を固定"
```

---

## Task 8: 詳細画面のパネルと削除ボタン無効化（テスト ⑥）

**Files:**
- Create: `resources/views/realestate/_partials/_deletion_blockers.blade.php`
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php:186-190`（`show()` の return）
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php:212-216`（`show()` の return）
- Modify: `resources/views/realestate/procurements/show.blade.php:30-37, 40`
- Modify: `resources/views/realestate/projects/show.blade.php:30-37, 40`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加。

⚠ **⑥ は Bug #28 の教訓（呼び出し側と定義側を対で検証する）。**
パネルの文字列だけ見ると「ボタンが有効なまま」を見逃す。**名前とボタンの両方**を見る。

⚠ **`procurements/show` は契約カード（183-224 行）で買主名を既に出している。**
買主名でアサートすると既存カードに一致して false-pass する（Bug #40 と同型）。
**契約の `property_name`** はそのカードに出ないので、それでパネルを見分ける。

⚠ **【2026-08-03 レビューで発覚】`assertSee('建売物件 1 件')` は false-pass する。**
無効化ボタンの `title`（＝`summarize()` の出力 `"建売物件 1 件が参照しているため削除できません。"`）にも
部分文字列として一致してしまうため、**パネルの件数行を丸ごと消してもこのアサートは緑のまま通る**
（Bug #40 と同型）。パネルの件数行はタグに囲まれているので、タグ込みの正規表現で見て区別すること。
併せて、依存が常に 1 件の fixture では `count()` の実装ミス（常に 1 固定で返す等）を検出できないため、
パネル系テストのどれか 1 本は**依存 2 件**にして「2 件」の表示を固定する。

⚠ **採用①（2026-08-03）: `title` は `disabled` なボタン自身に置いても表示されない。**
`disabled` な要素はホバーイベントを発火しないため、ネイティブ tooltip がどのブラウザでも出ない
（Firefox はかつて例外的に出していたが Bugzilla 274626 で他ブラウザに挙動を揃えた）。
`title` はホバーを受けられる `<span>` へ移し、`disabled` なボタンには
`aria-describedby="deletion-blockers"` を持たせてパネル本文（`id="deletion-blockers"`）と紐づける。
テストは `<span title="...">` の存在と、ブロック時に destroy へ送信する `<form>` が
跡形もないこと（残っていると confirm() を経ずに直接 POST できる導線が残る）も併せて固定する。

```php
    // ============================================================
    // Task 8: ⑥ 詳細画面のパネルと削除ボタン
    // ============================================================

    /** 依存ありの詳細画面: 削除ボタンが disabled になっている */
    private function assertDeleteButtonDisabled(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<button[^>]*\sdisabled[^>]*>\s*削除\s*<\/button>/u',
            $html,
            '削除ボタンが disabled で描画されていない'
        );
    }

    /** 依存なしの詳細画面: 従来どおり submit の削除ボタンが出ている */
    private function assertDeleteButtonEnabled(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<button\s+type="submit"[^>]*>\s*削除\s*<\/button>/u',
            $html,
            '通常の削除ボタンが描画されていない'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*\sdisabled[^>]*>\s*削除\s*<\/button>/u',
            $html,
            '依存が無いのに削除ボタンが無効化されている'
        );
    }

    /**
     * (b) ブロック時、destroy へ送信する <form> が跡形もないこと。
     * 残っていると confirm() を経ずに直接 POST できる導線が HTML に残ってしまう。
     * destroy と show は同じ URI（動詞違いだけ）なので action 属性の文字列一致で判定できる
     * （実測: routes/web.php でこの URI を action に使う <form> は該当 show.blade.php にしか無い）。
     */
    private function assertNoDestroyFormPresent(string $html, string $destroyUrl): void
    {
        $this->assertStringNotContainsString(
            'action="' . $destroyUrl . '"',
            $html,
            'ブロック時なのに削除 form の action が残っている'
        );
    }

    /**
     * 採用①: title は disabled なボタン自身に置いても表示されない（disabled 要素はホバーイベントを
     * 発火しないため。Firefox もかつては例外的に出していたが Bugzilla 274626 で他ブラウザに揃えた）。
     * ホバーを受けられる <span> 側に載っていることを固定する。
     */
    private function assertDeleteReasonShownOnHoverableSpan(string $html, string $expectedSummary): void
    {
        $this->assertStringContainsString(
            '<span title="' . e($expectedSummary) . '"',
            $html,
            '<span> に理由の title が無い（disabled なボタン自身に title を置いても表示されない）'
        );
    }

    /** ⑥ 仕入れ案件詳細: パネルに依存名が出て、かつ削除ボタンが無効 */
    public function test_procurement_show_lists_blockers_and_disables_delete(): void
    {
        $procurement = $this->makeProcurement();
        // ⚠ 買主名は既存の契約カードにも出るので、パネルの検証には property_name を使う
        $this->makeContract(['procurement_id' => $procurement->id], 'パネル検証用契約名');

        $response = $this->actingAs($this->executive())
            ->get("/realestate/procurements/{$procurement->id}");

        $response->assertOk();
        $response->assertSee('このデータを参照しているため削除できません');
        $response->assertSee('パネル検証用契約名（山西 太郎 様）');
        $this->assertDeleteButtonDisabled($response->getContent());
        $this->assertNoDestroyFormPresent(
            $response->getContent(),
            route('realestate.procurements.destroy', $procurement)
        );
        $this->assertDeleteReasonShownOnHoverableSpan(
            $response->getContent(),
            '契約 1 件が参照しているため削除できません。'
        );
    }

    /** ⑥ 分譲地詳細: パネルに依存名が出て、かつ削除ボタンが無効 */
    public function test_project_show_lists_blockers_and_disables_delete(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);
        $this->makeProperty($lotA, 'HS-0042');
        // ⚠ (d) 依存を複数件にする。常に 1 件だけの fixture では count() の実装ミス
        //    （例: 常に 1 固定で返す）を原理的に検出できない。
        $this->makeProperty($lotB, 'HS-0043');

        $response = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}");
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('このデータを参照しているため削除できません');
        $response->assertSee('HS-0042 建売テスト邸');
        $response->assertSee('HS-0043 建売テスト邸');
        // ⚠ (a) assertSee('建売物件 2 件') だけでは false-pass する — 無効ボタンの title 要約文
        //    「建売物件 2 件が参照しているため削除できません。」にも部分文字列として一致するため
        //    （Bug #40 と同型）。パネルの件数行はタグに囲まれているので、タグ込みで見て区別する。
        //    実測: 件数行だけを削っても、この確認が無ければ緑のまま通ってしまう。
        $this->assertMatchesRegularExpression(
            '/<div class="text-xs font-semibold text-amber-800 mb-1">建売物件 2 件<\/div>/u',
            $html,
            'パネルの件数行が正しく描画されていない（title 属性への false-pass 対策）'
        );
        // (c) パネルの各行が <a href> リンクになっていること（設計書 §5.1: 該当詳細画面へのリンク）
        $this->assertMatchesRegularExpression(
            '#<a\s+href="[^"]*/housing/properties/\d+"[^>]*>HS-0042 建売テスト邸</a>#u',
            $html,
            'パネル行が <a href> リンクになっていない（素のテキストに後退している）'
        );
        $this->assertDeleteButtonDisabled($html);
        $this->assertNoDestroyFormPresent($html, route('realestate.projects.destroy', $project));
        $this->assertDeleteReasonShownOnHoverableSpan(
            $html,
            '建売物件 2 件が参照しているため削除できません。'
        );
    }

    /** 依存 0 件のときはパネルを描かない（空枠を全画面に増やさない）+ ボタンは有効のまま */
    public function test_show_pages_without_blockers_render_no_panel_and_keep_delete(): void
    {
        $procurement = $this->makeProcurement();
        $project     = $this->makeProject();

        $procurementResponse = $this->actingAs($this->executive())
            ->get("/realestate/procurements/{$procurement->id}");
        $procurementResponse->assertOk();
        $procurementResponse->assertDontSee('このデータを参照しているため削除できません');
        $this->assertDeleteButtonEnabled($procurementResponse->getContent());

        $projectResponse = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}");
        $projectResponse->assertOk();
        $projectResponse->assertDontSee('このデータを参照しているため削除できません');
        $this->assertDeleteButtonEnabled($projectResponse->getContent());
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL 2 本 — `test_procurement_show_lists_blockers_and_disables_delete` と
`test_project_show_lists_blockers_and_disables_delete`（パネルが無い）。
3 本目（依存なし）は既に PASS。

- [ ] **Step 3: パネルの partial を作る**

`resources/views/realestate/_partials/_deletion_blockers.blade.php` を新規作成:

⚠ **採用①: ルート `<div>` に `id="deletion-blockers"` を付ける。**
無効化ボタンの `aria-describedby` がここを指す（キーボードだけの利用者向け。`disabled` はフォーカス不能
なので `aria-describedby` はスクリーンリーダーの文脈読み上げ用。パネルと無効ボタンは必ず同時に出るので
id はページに 1 つで足りる）。

```blade
{{-- 削除ブロッカー パネル（依存が 1 件以上あるときだけ描画する。0 件なら空枠も出さない） --}}
{{-- 呼び出し: @include('realestate._partials._deletion_blockers', ['blockers' => $deletionBlockers]) --}}
@if($blockers)
    <div id="deletion-blockers" class="bg-amber-50 border border-amber-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-4 h-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <h2 class="text-sm font-bold text-amber-900">このデータを参照しているため削除できません</h2>
        </div>
        @foreach($blockers as $blocker)
            <div class="mb-2">
                <div class="text-xs font-semibold text-amber-800 mb-1">{{ $blocker['label'] }} {{ count($blocker['items']) }} 件</div>
                @foreach($blocker['items'] as $item)
                    <div class="text-sm text-amber-900 mb-0.5">
                        ・<a href="{{ $item['url'] }}" class="font-medium text-emerald-700 hover:text-emerald-800">{{ $item['name'] }}</a>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
```

- [ ] **Step 4: 2 つのコントローラの `show()` から変数を渡す**

`app/Http/Controllers/RealEstate/ProcurementController.php` の `show()`（186-190 行の return）を差し替える。

置換前:

```php
        return view('realestate.procurements.show', compact(
            'procurement', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws'
        ));
```

置換後:

```php
        // 削除ブロッカー（パネル + 削除ボタンの無効化。判定はサーバのガードと同じ 1 本を通す）
        // 要約文は Blade で組まずここで作る（Blade には整形済みの値だけ渡す）
        $deletionBlockers = $procurement->deletionBlockers();
        $deletionBlockersSummary = DeletionBlockers::summarize($deletionBlockers);

        return view('realestate.procurements.show', compact(
            'procurement', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws',
            'deletionBlockers', 'deletionBlockersSummary'
        ));
```

`app/Http/Controllers/RealEstate/ProjectController.php` の `show()`（212-216 行の return）を差し替える。

置換前:

```php
        return view('realestate.projects.show', compact(
            'project', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws'
        ));
```

置換後:

```php
        // 削除ブロッカー（パネル + 削除ボタンの無効化。判定はサーバのガードと同じ 1 本を通す）
        // 要約文は Blade で組まずここで作る（Blade には整形済みの値だけ渡す）
        $deletionBlockers = $project->deletionBlockers();
        $deletionBlockersSummary = DeletionBlockers::summarize($deletionBlockers);

        return view('realestate.projects.show', compact(
            'project', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws',
            'deletionBlockers', 'deletionBlockersSummary'
        ));
```

- [ ] **Step 5: `procurements/show.blade.php` のヘッダーを差し替える**

30-37 行を差し替える。

⚠ **`@if` / `@else` / `@endif` は必ず複数行で書く**（`@else` の直後に `<` や英数字が続くと
Blade のコンパイルが壊れる。Bug #6）。

置換前:

```blade
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('realestate.procurements.destroy', $procurement) }}"
                      onsubmit="return confirm('この仕入れ案件を削除しますか？ 原価データも全て削除されます。')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
```

置換後（**2026-08-03 レビューで修正 — `title` を `<span>` へ移す。理由は Step 3 の注記参照**）:

```blade
            @if(auth()->user()->role->isExecutive())
                @if($deletionBlockers)
                    {{-- ⚠ title は disabled なボタン自身に置いても表示されない（ホバーイベントが発火しない）。
                         ホバーを受けられるラッパーに載せる。キーボードだけの利用者には
                         aria-describedby でパネル本文を紐づける（disabled はフォーカス不能なため）。 --}}
                    <span title="{{ $deletionBlockersSummary }}" style="display: inline-flex;">
                        <button type="button" disabled aria-describedby="deletion-blockers"
                                style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #9ca3af; border: 1px solid #d1d5db; border-radius: 6px; background: #f9fafb; cursor: not-allowed;">削除</button>
                    </span>
                @else
                    <form method="POST" action="{{ route('realestate.procurements.destroy', $procurement) }}"
                          onsubmit="return confirm('この仕入れ案件を削除しますか？ 原価データも全て削除されます。')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                    </form>
                @endif
            @endif
```

- [ ] **Step 6: `procurements/show.blade.php` にパネルを差し込む**

ヘッダーの閉じ `</div>`（39 行）の直後、空行 2 つを挟んだ `{{-- 基本情報 --}}` の**前**に 1 行入れる。

置換前:

```blade
    </div>


    {{-- 基本情報 --}}
```

置換後:

```blade
    </div>

    @include('realestate._partials._deletion_blockers', ['blockers' => $deletionBlockers])

    {{-- 基本情報 --}}
```

- [ ] **Step 7: `projects/show.blade.php` に同じ 2 つの変更を入れる**

30-37 行を差し替える（route と confirm 文だけが上と違う）:

置換前:

```blade
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('realestate.projects.destroy', $project) }}"
                      onsubmit="return confirm('この分譲地を削除しますか？ 原価・区画・図面データも全て削除されます。')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
```

置換後（**2026-08-03 レビューで修正 — `title` を `<span>` へ移す。理由は Step 3 の注記参照**）:

```blade
            @if(auth()->user()->role->isExecutive())
                @if($deletionBlockers)
                    {{-- ⚠ title は disabled なボタン自身に置いても表示されない（ホバーイベントが発火しない）。
                         ホバーを受けられるラッパーに載せる。キーボードだけの利用者には
                         aria-describedby でパネル本文を紐づける（disabled はフォーカス不能なため）。 --}}
                    <span title="{{ $deletionBlockersSummary }}" style="display: inline-flex;">
                        <button type="button" disabled aria-describedby="deletion-blockers"
                                style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #9ca3af; border: 1px solid #d1d5db; border-radius: 6px; background: #f9fafb; cursor: not-allowed;">削除</button>
                    </span>
                @else
                    <form method="POST" action="{{ route('realestate.projects.destroy', $project) }}"
                          onsubmit="return confirm('この分譲地を削除しますか？ 原価・区画・図面データも全て削除されます。')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                    </form>
                @endif
            @endif
```

そしてパネルを差し込む:

置換前:

```blade
    </div>


    {{-- 基本情報 --}}
```

置換後:

```blade
    </div>

    @include('realestate._partials._deletion_blockers', ['blockers' => $deletionBlockers])

    {{-- 基本情報 --}}
```

- [ ] **Step 8: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (26 tests, ...)`（**2026-08-03 訂正** — 当初案の「24」は誤記。実測は
23（Task 7 完了時点の総数）+ Task 8 で追加した 3 本 = 26）。

⚠ **2026-08-03 レビューで ① title 表示・② テストの穴 4 件を追加修正した**（`<span>` +
`aria-describedby` への変更、`assertNoDestroyFormPresent` / `assertDeleteReasonShownOnHoverableSpan`
の追加、件数アサートの false-pass 修正、依存 2 件での件数固定、`<a href>` の固定）。
その状態でも **テスト本数は 26 のまま変わらない**（新しい `public function test_*` は増やさず、
既存 3 本のアサーションを強化 + 非 test の private helper を追加しただけのため）。
アサーション数は 89 → 95 に増える。

- [ ] **Step 9: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Http/Controllers/RealEstate/ProcurementController.php app/Http/Controllers/RealEstate/ProjectController.php resources/views/realestate/_partials/_deletion_blockers.blade.php resources/views/realestate/procurements/show.blade.php resources/views/realestate/projects/show.blade.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 詳細画面に参照データのパネルを出し削除ボタンを無効化"
```

---

## Task 9: 区画一覧の削除ボタン無効化（テスト ⑦）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProjectController.php:302-335`（`lots()`）
- Modify: `resources/views/realestate/projects/lots.blade.php:140`
- Test: `tests/Feature/RealEstate/DeletionGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`DeletionGuardTest` の末尾に追加:

```php
    // ============================================================
    // Task 9: ⑦ 区画一覧の delete_blocked
    // ============================================================

    /** ⑦ 参照のある区画だけ delete_blocked = true、他は false */
    public function test_lots_page_marks_only_blocked_lots(): void
    {
        $project = $this->makeProject();
        $blocked = $this->makeLot($project, 1);
        $free    = $this->makeLot($project, 2);

        $this->makeProperty($blocked);

        $response = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}/lots");

        $response->assertOk();

        $lots = collect($response->viewData('lotsForJs'))->keyBy('id');

        $this->assertTrue($lots[$blocked->id]['delete_blocked']);
        $this->assertSame(
            '建売物件 1 件が参照しているため削除できません。',
            $lots[$blocked->id]['delete_blocked_reason']
        );

        $this->assertFalse($lots[$free->id]['delete_blocked']);
        $this->assertSame('', $lots[$free->id]['delete_blocked_reason']);
    }

    /**
     * 呼び出し側（Blade）と定義側（コントローラ）を対で固定する。
     *
     * ⚠ Bug #28 / #35 と同じ構図 — viewData だけ見ていると、Blade からバインドが消えても緑になる。
     * ⚠ Bug #32 — x-show は display を自分のものとして扱うので、この要素は 1 要素のまま
     *    :disabled で出し分ける。静的 style= を残すと Alpine に上書きされる（Bug #2 / #5）。
     */
    public function test_lots_blade_binds_delete_blocked_without_style_conflict(): void
    {
        $blade = file_get_contents(resource_path('views/realestate/projects/lots.blade.php'));

        // 削除ボタンの開始タグを全部拾う。
        // ⚠ preg_match（単数）だと「1 個以上見つかった」しか分からず、
        //    バインドの無い 2 個目のボタンが増えても緑のまま通る（走査テストの空振り防止）。
        $found = preg_match_all('/<button[^>]*deleteLot\(lot\)[^>]*>/u', $blade, $m);
        $this->assertSame(1, $found, '区画削除ボタンはちょうど 1 つ（2026-08-02 実測）');
        $button = $m[0][0];

        $this->assertStringContainsString(':disabled="lot.delete_blocked"', $button);
        $this->assertStringContainsString('lot.delete_blocked_reason', $button);
        $this->assertStringNotContainsString(' style="', $button, '静的 style= は :style へ寄せること（Bug #2 / #5）');
        $this->assertStringNotContainsString('x-show', $button, 'x-show は display を奪う（Bug #32）');
    }
```

- [ ] **Step 2: 実行して失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: FAIL 2 本 — `test_lots_page_marks_only_blocked_lots`（`delete_blocked` キーが無い）と
`test_lots_blade_binds_delete_blocked_without_style_conflict`。

- [ ] **Step 3: `lots()` にバルク判定を足す**

`app/Http/Controllers/RealEstate/ProjectController.php:307-310` を差し替える。

置換前:

```php
        // 区画データを事前整形（@json用）
        $lotsForJs = [];
        $effectiveCostTotal = $project->getEffectiveCostTotal();
        $lotSellingTotal = $project->getLotSellingPriceTotal();
        $allHavePrice = $project->allLotsHaveSellingPrice();
```

置換後:

```php
        // 区画データを事前整形（@json用）
        $lotsForJs = [];
        $effectiveCostTotal = $project->getEffectiveCostTotal();
        $lotSellingTotal = $project->getLotSellingPriceTotal();
        $allHavePrice = $project->allLotsHaveSellingPrice();

        // 削除ブロッカー（区画ごと）。区画数によらずバルククエリ 3 本で組む
        //（ループ内で 1 件ずつ問い合わせると N+1 になる）
        $blockersByLotId = DeletionBlockers::forEachLotId($project->lots->pluck('id')->all());
```

- [ ] **Step 4: `$lotsForJs` に 2 キーを足す**

`app/Http/Controllers/RealEstate/ProjectController.php` の `foreach ($project->lots as $lot)` の中、
`$lotsForJs[] = [` の**直前**に 1 行足し、配列の末尾に 2 キーを足す。

置換前:

```php
            $lotsForJs[] = [
                'id'                    => $lot->id,
```

置換後:

```php
            $lotBlockers = $blockersByLotId[$lot->id] ?? [];

            $lotsForJs[] = [
                'id'                    => $lot->id,
```

置換前:

```php
                'tsubo_price_formatted' => $lot->getSellingPricePerTsuboFormatted(),
            ];
```

置換後:

```php
                'tsubo_price_formatted' => $lot->getSellingPricePerTsuboFormatted(),
                // 削除ボタンの :disabled / :title 用（サーバのガードと同じ判定を通す）
                'delete_blocked'        => $lotBlockers !== [],
                'delete_blocked_reason' => DeletionBlockers::summarize($lotBlockers),
            ];
```

- [ ] **Step 5: 区画削除ボタンを差し替える**

`resources/views/realestate/projects/lots.blade.php:140` を差し替える。

⚠ **`x-show` / `x-if` で出し分けない**（`x-show` は `display` を自分のものとして扱い、
同じ要素の `style` / `:style` の `display` を奪う。Bug #32）。**1 要素のまま `:disabled`。**
⚠ **静的 `style=` を残さない**（Alpine が `:style` で上書きして競合する。Bug #2 / #5）。

置換前:

```blade
                                        <button type="button" @click="deleteLot(lot)" style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff; margin-left: 4px;">削除</button>
```

置換後:

```blade
                                        <button type="button" @click="deleteLot(lot)"
                                                :disabled="lot.delete_blocked"
                                                :title="lot.delete_blocked ? lot.delete_blocked_reason : ''"
                                                :style="'display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; border-radius: 4px; background: #fff; margin-left: 4px; ' + (lot.delete_blocked ? 'color: #9ca3af; border: 1px solid #d1d5db; cursor: not-allowed;' : 'color: #dc2626; border: 1px solid #dc2626; cursor: pointer;')">削除</button>
```

- [ ] **Step 6: 実行して緑を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `OK (26 tests, ...)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git add app/Http/Controllers/RealEstate/ProjectController.php resources/views/realestate/projects/lots.blade.php tests/Feature/RealEstate/DeletionGuardTest.php && git commit -m "feat(realestate): 区画一覧で参照中の区画の削除ボタンを無効化"
```

---

## Task 10: 変異テスト — 実際に赤になることを確かめる

**Files:** 一時的に編集して戻すだけ（コミットしない）

⚠ **「テストが緑」は検証にならない**（Bug #39 / #42）。
設計書 §6.3 の 5 通り + このプランで足した 2 通り + コードレビューで見つかったテスト検出力の穴 3 通り
（`test(realestate): 仕入れ案件の注文住宅分岐と要約文の書式を回帰テストで固定` で Task 1 / Task 2 のテストに追加）
+ Task 6/7 完了後のコードレビューで足した 2 通り（`test(realestate): ガードが図面削除より前に
あることと message キーの受け手を固定`）+ Task 8 完了後のコードレビューで足した 4 通り
（`fix(realestate): 無効化ボタンの理由表示を実際に出るようにしテストの盲点を塞ぐ`。Step 13〜16）
の**計 16 通り**を**実際にコードへ入れて赤を確認する**。

各変異は「壊す → 走らせる → 赤を確認 → `git checkout` で戻す」の 4 手。
**戻し忘れると次の変異の結果が読めなくなる**ので、毎回 `git status` がクリーンなことを確認する。

- [ ] **Step 1: 変異 1 — 仕入れ案件のガードを消す**

`ProcurementController::destroy()` の `if ($blockers = ...) { return back()... }` の 3 行を削除して実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: **FAIL** — `test_procurement_with_contract_cannot_be_deleted` と
`test_procurement_with_housing_property_cannot_be_deleted` が赤。

戻す:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git checkout app/Http/Controllers/RealEstate/ProcurementController.php && git status --short
```

Expected: 出力が空。

- [ ] **Step 2: 変異 2 — 分譲地PJ のガードを消す**

`ProjectController::destroy()` のガードを削除して実行。

Expected: **FAIL** — `test_project_with_housing_property_on_a_lot_cannot_be_deleted` と
`test_project_with_direct_contract_cannot_be_deleted` が赤。

戻す:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git checkout app/Http/Controllers/RealEstate/ProjectController.php && git status --short
```

- [ ] **Step 3: 変異 3 — 区画のガードを消す**

`ProjectController::destroyLot()` の `if ($blockers = ...)` を削除して実行。

Expected: **FAIL** — `test_lot_with_custom_order_cannot_be_deleted` が赤。

戻す（`git checkout app/Http/Controllers/RealEstate/ProjectController.php`）。

- [ ] **Step 4: 変異 4 — 契約の二重計上を復活させる**

`DeletionBlockers::forProject()` の契約クエリを 2 本に割って足す形へ変異させる:

```php
        $contracts = ReContract::with('buyer')->where('project_id', $project->id)->get()
            ->concat($lotIds === [] ? collect() : ReContract::with('buyer')->whereIn('lot_id', $lotIds)->get());
```

Expected: **FAIL** — `test_contract_linked_by_both_project_and_lot_is_counted_once` が赤
（`契約 2 件が参照しているため削除できません。` になる）。

戻す（`git checkout app/Support/DeletionBlockers.php`）。

- [ ] **Step 5: 変異 5 — 詳細画面の `disabled` を外す**

`projects/show.blade.php` の `<button type="button" disabled title=...>` から `disabled` を削除して実行。

Expected: **FAIL** — `test_project_show_lists_blockers_and_disables_delete` が赤
（パネルは出ているのにボタンが有効 ＝ Bug #28 の構図を検出できている）。

戻す（`git checkout resources/views/realestate/projects/show.blade.php`）。

- [ ] **Step 6: 変異 6 — 区画 Blade の `:disabled` を外す**

`lots.blade.php` の削除ボタンから `:disabled="lot.delete_blocked"` を削除して実行。

Expected: **FAIL** — `test_lots_blade_binds_delete_blocked_without_style_conflict` が赤。

⚠ **`test_lots_page_marks_only_blocked_lots` は緑のまま**であることも確認する。
これが Bug #28 / #35 の構図そのもの（コントローラ側だけ見ていると Blade の欠落を見逃す）で、
**2 本を対で置いている理由**。

戻す（`git checkout resources/views/realestate/projects/lots.blade.php`）。

- [ ] **Step 7: 変異 7 — Ajax の JSON キーを `error` に戻す**

`destroyLot()` の 422 を `['error' => ...]` に変異させて実行。

Expected: **FAIL** — `test_lot_with_custom_order_cannot_be_deleted` が赤
（`assertExactJson(['message' => ...])` が一致しない）。

戻す（`git checkout app/Http/Controllers/RealEstate/ProjectController.php`）。

- [ ] **Step 8: 変異 8 — `forProcurementId()` から注文住宅の行を消す**

`DeletionBlockers::forProcurementId()` の `HsCustomOrder::where('re_procurement_id', $procurementId)->get()` を
`collect()` に変異させて実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: **FAIL** — `test_procurement_blockers_collect_all_three_kinds` が赤（注文住宅が消える）。

戻す（`git checkout app/Support/DeletionBlockers.php`）。

- [ ] **Step 9: 変異 9 — `summarize()` の区切り文字を変える**

`summarize()` の `implode('・', $parts)` を `implode('、', $parts)` に変異させて実行。

Expected: **FAIL** — `test_procurement_blockers_collect_all_three_kinds` の要約文アサートが赤。

⚠ **`test_contract_linked_by_both_project_and_lot_is_counted_once`（1 エントリ）は緑のまま**であることも確認する。
1 エントリでは `implode` の区切り文字が結果に現れないため、複数エントリのテストでしか検出できない
（これがコードレビューで見つかった元々の穴）。

戻す（`git checkout app/Support/DeletionBlockers.php`）。

- [ ] **Step 10: 変異 10 — `summarize([])` の空文字を変える**

`summarize()` の `if ($blockers === []) { return ''; }` を `return 'なし';` に変異させて実行。

Expected: **FAIL** — `test_procurement_without_references_has_no_blockers` が赤。

戻す（`git checkout app/Support/DeletionBlockers.php`）。

- [ ] **Step 11: 変異 11 — ガードを図面の物理削除より後ろへ動かす**

`ProjectController::destroy()` を、ガードの `if` ブロックを `foreach ($project->drawings as $drawing)`
の**後ろ**へ動かす形へ変異させて実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter test_blocked_project_deletion_does_not_touch_drawing_files
```

Expected: **FAIL** — `test_blocked_project_deletion_does_not_touch_drawing_files` が赤
（`Unable to find a file or directory at path [re_drawings/plan.pdf]` — ガードより先に
物理削除が走り、ブロックされたはずの図面ファイルが消える）。実測で確認済み。

戻す（`git checkout app/Http/Controllers/RealEstate/ProjectController.php`）。

- [ ] **Step 12: 変異 12 — `lots.blade.php` の `deleteLot` が読むキーを変える**

`lots.blade.php` の `deleteLot` 内 `alert(err.message || 'エラーが発生しました。');` を
`alert(err.error || 'エラーが発生しました。');` に変異させて実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter test_lots_view_reads_message_key_from_delete_error
```

Expected: **FAIL** — `test_lots_view_reads_message_key_from_delete_error` が赤。

⚠ **最初に書いたテスト案（ファイル全体に `assertStringContainsString`）ではこの変異が検出できず、
実測で緑のまま通った。** `lots.blade.php` には `storeLot`/`saveLot`（区画の追加・更新）用の
同一文字列 `err.message || 'エラーが発生しました。'` が別途 2 箇所（365 行目・425 行目）にあり、
`deleteLot`（461 行目）だけを変異させてもファイル全体としては文字列が残るため。
`deleteLot` 関数の本体を正規表現で切り出してから判定する形に直して、初めてこの変異を検出できた。
**「テストが緑」だけでは検証にならないことを、このテスト自身が実例で示している**（Bug #39-42 と同型）。

戻す（`git checkout resources/views/realestate/projects/lots.blade.php`）。

- [ ] **Step 13: 変異 13 — パネルの件数行を消す（Task 8 完了後のコードレビューで追加）**

`_deletion_blockers.blade.php` の `<div class="text-xs font-semibold text-amber-800 mb-1">{{ $blocker['label'] }} {{ count($blocker['items']) }} 件</div>` を削除して実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: **FAIL** — `test_project_show_lists_blockers_and_disables_delete` が赤。

⚠ **`assertSee('建売物件 2 件')` のような素朴なアサートではこの変異を検出できない。**
無効化ボタンの `title`（＝`summarize()` の出力）に同じ文字列が部分一致で残るため false-pass する
（Bug #40 と同型）。タグ込みの正規表現（`<div class="...">建売物件 2 件</div>`）に直して、
初めてこの変異を検出できた。実測済み。

戻す（`git checkout resources/views/realestate/_partials/_deletion_blockers.blade.php`）。

- [ ] **Step 14: 変異 14 — ブロック時に destroy へ送信する `<form>` を残す**

`procurements/show.blade.php` の `@if($deletionBlockers)` 分岐（`<span>` の直後）に
`<form method="POST" action="{{ route('realestate.procurements.destroy', $procurement) }}">@csrf @method('DELETE')</form>`
を追加して実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: **FAIL** — `test_procurement_show_lists_blockers_and_disables_delete` が赤
（`assertNoDestroyFormPresent` が検出。残っていると confirm() を経ずに直接 POST できる導線が
HTML に残ってしまう）。実測済み。

戻す（`git checkout resources/views/realestate/procurements/show.blade.php`）。

- [ ] **Step 15: 変異 15 — パネル行の `<a href>` を外して素のテキストにする**

`_deletion_blockers.blade.php` の `・<a href="{{ $item['url'] }}" ...>{{ $item['name'] }}</a>` を
`・{{ $item['name'] }}` に変異させて実行。

Expected: **FAIL** — `test_project_show_lists_blockers_and_disables_delete` が赤
（`<a href="…/housing/properties/…">` を要求する正規表現アサートが不一致。設計書 §5.1 の
「各行は該当詳細画面へのリンク」が壊れたことを検出）。実測済み。

戻す（`git checkout resources/views/realestate/_partials/_deletion_blockers.blade.php`）。

- [ ] **Step 16: 変異 16 — `<span>` の `title` を消す（採用①の検証）**

`projects/show.blade.php` の `<span title="{{ $deletionBlockersSummary }}" ...>` から
`title="{{ $deletionBlockersSummary }}"` を削除して実行。

Expected: **FAIL** — `test_project_show_lists_blockers_and_disables_delete` が赤
（`assertDeleteReasonShownOnHoverableSpan` が検出。`title` が `disabled` なボタン自身に
残っていても、どのブラウザでもホバー表示されないため無意味 — 採用①の本旨）。実測済み。

戻す（`git checkout resources/views/realestate/projects/show.blade.php`）。

- [ ] **Step 17: 全部戻っていることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git status --short && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter DeletionGuardTest
```

Expected: `git status` が空、`OK (28 tests, ...)`（Task 5-9 全完了後の総数。26 + Task 6/7 完了後の
コードレビュー追記分 2 本 = 28。Task 8 完了後のレビュー分（Step 13-16）はテスト本数を増やさず
既存 3 本のアサーションを強化しただけなので、この総数には影響しない。Task 10 はこの時点、
つまり Task 5〜9 が全て終わってから実施する）。

⚠ **16 通りすべてで赤を実測できていない場合、そのテストは再発を検出できない。**
先に進まず、テストを直すこと。

---

## Task 11: 仕上げの検証

**Files:** なし（検証のみ）

- [ ] **Step 1: 全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit
```

Expected: 赤 0 本（Task 0 のベースライン 426 件 + 本プランの 26 件 ≒ 452 件）。

- [ ] **Step 2: コンパイル済み Blade を lint する**

⚠ Blade を 4 本触っているので必須。**`view:cache` の成功表示だけでは不十分**
（コンパイル済み PHP を lint しないため。Bug #26 / #30）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" > /dev/null || echo "INVALID: $f"; done && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= php artisan view:clear
```

Expected: `Blade templates cached successfully.` → `INVALID:` の行が **0 件** → `Compiled views cleared successfully.`

- [ ] **Step 3: 3 経路すべてが DeletionBlockers を通っていることを確認**

判定が 1 本に集約されている（Bug #41 の「経路が 2 つ」になっていない）ことの目視確認:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && grep -rn "deletionBlockers()\|DeletionBlockers::" app/Http/Controllers/RealEstate/
```

Expected: 7 箇所 — Procurement の `destroy` / `show`（2 行）、Project の `destroy` / `destroyLot` / `show`（2 行）/ `lots`（2 行）。
**独自に契約や建売を数えている箇所が他に無いこと。**

- [ ] **Step 4: `git status` がクリーンであることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/deletion-guard && git status --short && git log --oneline 121d1642..HEAD
```

Expected: `git status` が空、コミットが 9 本（Task 1〜9）。

---

## Task 12: main へのマージとデプロイ

⚠ **`./deploy.sh` はユーザーの明示承認が必要。** 承認が無い場合は Step 1〜3 で止めて報告する。
DB 変更は無い。

- [ ] **Step 1: main repo へ FF-merge**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only feature/deletion-guard
```

Expected: `Fast-forward`。もし `Not possible to fast-forward` なら、worktree で `git rebase 13.x` してから再試行。

- [ ] **Step 2: main repo の cwd で `composer dump-autoload`**

⚠ **`App\Support\DeletionBlockers` が新規クラスなので必須。**
⚠ **worktree から実行してはいけない**（autoloader の `$baseDir` に worktree パスが焼き込まれ、
main repo の Apache が worktree を参照する事故になる）。

```bash
cd /Users/masanori/site/manage && composer dump-autoload
```

Expected: `Generated optimized autoload files containing N classes`

- [ ] **Step 3: vendor が `--no-dev` に戻っていることを確認**

worktree 側に dev 依存を入れたが、main repo の vendor は触っていないはず。念のため確認:

```bash
cd /Users/masanori/site/manage && ls vendor/bin/phpunit 2>/dev/null && echo "⚠ dev 依存が入っている（composer install --no-dev が要る）" || echo "OK: --no-dev のまま"
```

Expected: `OK: --no-dev のまま`

- [ ] **Step 4: 本番デプロイ（ユーザーの明示承認後）**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: `npm run build` 成功 → rsync → 本番で `config:cache && route:cache && view:cache`。

- [ ] **Step 5: 本番ブラウザで確認**

⚠ URL に **`/index.php/` が必要**（付けないと 302 でダッシュボードへ流れる）。
⚠ **HTML に出るかだけでは不十分**（Bug #28 / #32）。**実際に押して（押せないことを）確認する。**

1. 建売が紐づく分譲地の詳細 `https://www.mitsuwat.co.jp/system/manage/index.php/realestate/projects/{id}`
   → amber のパネルが出て、削除ボタンがグレーで押せないこと（`title` にも理由が出ること）
2. 区画管理 `.../index.php/realestate/projects/{id}/lots`
   → 建売が紐づく区画の削除ボタン**だけ**が無効。他の区画は赤いまま押せること
3. 依存の無い区画を実際に削除して、従来どおり消せること
4. ブラウザの devtools で `getComputedStyle($0).cursor` を見て、無効ボタンが `not-allowed` になっていること
   （Bug #32 の「属性は正しいのに Alpine が実行時に書き換える」を実測で潰す）

---

## 検証チェックリスト（完了報告の前に）

- [ ] 全テスト緑（Task 11 Step 1 の出力を貼る）
- [ ] コンパイル済み Blade の `php -l` が 0 件（Task 11 Step 2）
- [ ] **変異 10 通りすべてで赤を実測した**（Task 10）
- [ ] `git status` がクリーンで、変異が残っていない
- [ ] main repo で `composer dump-autoload` を実行した（Task 12 Step 2）
- [ ] 本番ブラウザで**実際に押して**確認した（Task 12 Step 5）

⚠ 上のどれかが未実施なら「完了」と言わない。特に**変異テストの実測**は省略しない
（Bug #39 / #42 はどちらも「テストが正しく書けているように見えるのに検出できない」事故だった）。
