# フェーズ1: 注文住宅の契約時点ダッシュボード計上 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 住宅事業ダッシュボードの注文住宅を「引渡し済みのみ」から「契約以降・契約日基準」に変更し、建売と同じ契約時点計上に揃える。

**Architecture:** `HousingDashboardController` の `collectContractedItems()`（注文住宅抽出クエリ）と `mapOrderToDto()`（DTO 変換）の2メソッドのみ変更。KPI・月次グラフは `contracted_date` を基準に集計済みのため自動で契約日基準になる。建売ロジックは不変。

**Tech Stack:** Laravel 12 / PHP 8.3 / Eloquent / Carbon

**設計書:** `docs/superpowers/specs/2026-06-08-housing-contract-customer-linkage-design.md` の §4

---

## テスト/検証の前提（重要）

- **`hs_*` テーブルはテストDB（SQLite in-memory）に存在しない**（CLAUDE.md: DB は raw SQL 管理、Laravel migration 未整備）。`DashboardControllerTest.php:13-20` 参照。
  - → `collectContractedItems()`（`HsCustomOrder::...->get()` を含む）は **PHPUnit では検証不可**。tinker（ローカル実DB、`hs_custom_orders` あり）で検証する。
  - → `mapOrderToDto()` は **DB に触れない純粋変換**（モデルの計算ヘルパーのみ）なので、未保存モデルを ReflectionClass で渡して検証できる。
- **worktree ではテスト実行不可**（vendor が main repo への --no-dev symlink）。worktree では `php -l` と tinker で静的/疑似検証し、**マージ後に main repo で `php artisan test`** を回す。
- 実装は `superpowers:using-git-worktrees` で作成した worktree 内で行う前提。

---

## File Structure

| ファイル | 役割 | 変更 |
|---|---|---|
| `app/Http/Controllers/Housing/HousingDashboardController.php` | ダッシュボード集計 | `collectContractedItems()` 注文住宅クエリ、`mapOrderToDto()` を変更 |
| `tests/Feature/Housing/HousingDashboardMapOrderTest.php` | mapOrderToDto の回帰テスト | 新規（reflection・DB不要）|

---

## Task 1: 注文住宅を契約時点でダッシュボードに集計

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`（`collectContractedItems()` の注文住宅ブロック 69-76 行、`mapOrderToDto()` 131-152 行）
- Test: `tests/Feature/Housing/HousingDashboardMapOrderTest.php`（新規）

- [ ] **Step 1: mapOrderToDto の回帰テストを書く（DB不要・reflection）**

Create `tests/Feature/Housing/HousingDashboardMapOrderTest.php`:

```php
<?php

namespace Tests\Feature\Housing;

use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Http\Controllers\Housing\HousingDashboardController;
use App\Models\HsCustomOrder;
use ReflectionClass;
use Tests\TestCase;

/**
 * 注文住宅 DTO 変換が「契約日基準・実ステータス表示」になっていること。
 * hs_* テーブルはテストDBに無いため DB 保存はせず、未保存モデルを reflection で変換する。
 */
class HousingDashboardMapOrderTest extends TestCase
{
    public function test_map_order_to_dto_uses_contract_date_and_real_status(): void
    {
        $controller = new HousingDashboardController();
        $method = (new ReflectionClass($controller))->getMethod('mapOrderToDto');
        $method->setAccessible(true);

        $order = new HsCustomOrder([
            'order_code'              => 'CO-T',
            'order_name'              => 'テスト邸',
            'status'                  => CustomOrderStatus::Contracted->value,
            'customer_name'           => 'テスト顧客',
            'land_source_type'        => HousingLandSourceType::CustomerLand->value,
            'building_contract_price' => 20000000,
            'building_cost'           => 15000000,
            'tax_rate'                => 10,
            'contract_date'           => '2026-06-01',
        ]);
        $order->id = 1; // route('housing.custom-orders.show', $order) 生成のため

        $dto = $method->invoke($controller, $order);

        $this->assertSame('custom-order', $dto['type']);
        $this->assertSame('契約', $dto['status_label']);              // 「引渡し済み」固定でなく実ステータス
        $this->assertNotNull($dto['contracted_date']);
        $this->assertSame('2026-06-01', $dto['contracted_date']->format('Y-m-d')); // 契約日基準
        $this->assertSame(20000000, $dto['selling_price']);
        $this->assertSame(5000000, $dto['gross_profit']);
    }
}
```

- [ ] **Step 2: （マージ後 main repo で）テスト失敗を確認**

worktree では実行不可。マージ後 main repo で:
Run: `php artisan test --filter=test_map_order_to_dto_uses_contract_date_and_real_status`
Expected: FAIL（現状 `status_label` は「引渡し済み」固定、`contracted_date` は delivery_date 基準のため）

- [ ] **Step 3: `collectContractedItems()` の注文住宅クエリを変更**

`HousingDashboardController.php` の現状（69-76行付近）:

```php
        // 注文: 引渡し済み（status=delivered, delivery_date あり）のみ
        $orders = HsCustomOrder::with(['createdBy'])
            ->where('status', CustomOrderStatus::Delivered->value)
            ->whereNotNull('delivery_date');
        if ($range) {
            $orders = $orders->whereBetween('delivery_date', [$range[0], $range[1]]);
        }
        $orders = $orders->get();
```

を次に置き換える:

```php
        // 注文: 契約以降（契約一覧と同条件）。契約日基準で集計し建売と揃える
        $contractedStatuses = [
            CustomOrderStatus::Contracted->value,
            CustomOrderStatus::Construction->value,
            CustomOrderStatus::Completed->value,
            CustomOrderStatus::Delivered->value,
        ];
        $orders = HsCustomOrder::with(['createdBy'])
            ->whereIn('status', $contractedStatuses)
            ->whereNotNull('contract_date');
        if ($range) {
            $orders = $orders->whereBetween('contract_date', [$range[0], $range[1]]);
        }
        $orders = $orders->get();
```

- [ ] **Step 4: `mapOrderToDto()` を契約日基準・実ステータスに変更**

`HousingDashboardController.php` の現状（131-152行）:

```php
    protected function mapOrderToDto(HsCustomOrder $o): array
    {
        $deliveryDate = $o->delivery_date ? Carbon::parse($o->delivery_date) : null;

        return [
            'type'              => 'custom-order',
            'id'                => $o->id,
            'code'              => $o->order_code,
            'name'              => $o->order_name,
            'address'           => $o->address,
            'status_label'      => '引渡し済み',
            'status_style'      => 'background: #a7f3d0; color: #064e3b;',
            'staff_name'        => $this->lastNameOnly($o->createdBy?->name),
            'staff_id'          => $o->created_by,
            'contracted_date'   => $deliveryDate,
            'selling_price'     => $o->getTotalSellingPrice(),
            'total_cost'        => $o->getTotalCost(),
            'gross_profit'      => $o->getTotalProfit(),
            'gross_profit_rate' => $o->getTotalProfitRate(),
            'detail_url'        => route('housing.custom-orders.show', $o),
        ];
    }
```

を次に置き換える（`contracted_date` を契約日に、ラベル/スタイルを実ステータスに）:

```php
    protected function mapOrderToDto(HsCustomOrder $o): array
    {
        $contractDate = $o->contract_date ? Carbon::parse($o->contract_date) : null;
        // status は CustomOrderStatus にキャスト済み。tryFrom は使わない（Bug #22）
        $statusEnum = $o->status;

        return [
            'type'              => 'custom-order',
            'id'                => $o->id,
            'code'              => $o->order_code,
            'name'              => $o->order_name,
            'address'           => $o->address,
            'status_label'      => $statusEnum ? $statusEnum->label() : '—',
            'status_style'      => $statusEnum ? $statusEnum->badgeStyle() : '',
            'staff_name'        => $this->lastNameOnly($o->createdBy?->name),
            'staff_id'          => $o->created_by,
            'contracted_date'   => $contractDate,
            'selling_price'     => $o->getTotalSellingPrice(),
            'total_cost'        => $o->getTotalCost(),
            'gross_profit'      => $o->getTotalProfit(),
            'gross_profit_rate' => $o->getTotalProfitRate(),
            'detail_url'        => route('housing.custom-orders.show', $o),
        ];
    }
```

注意: ファイル冒頭のコメント「注文: 引渡し済み…」も「注文: 契約以降…」へ整合させる。クラス docblock（成約フォーカス）はそのままで可。

- [ ] **Step 5: 構文確認**

Run: `php -l app/Http/Controllers/Housing/HousingDashboardController.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: tinker でローカル実DB検証（契約段階の注文住宅が成約一覧に出るか）**

Run:
```bash
php artisan tinker --execute='
$o = App\Models\HsCustomOrder::create([
  "order_code" => "CO-TMP", "order_name" => "検証邸",
  "status" => App\Enums\CustomOrderStatus::Contracted->value,
  "customer_name" => "検証", "land_source_type" => App\Enums\HousingLandSourceType::CustomerLand->value,
  "address" => "愛媛県松山市", "building_contract_price" => 20000000, "building_cost" => 15000000,
  "is_land_cost_manual" => 0, "tax_rate" => 10, "contract_date" => date("Y-m-d"),
]);
$c = new App\Http\Controllers\Housing\HousingDashboardController();
$m = (new ReflectionClass($c))->getMethod("collectContractedItems"); $m->setAccessible(true);
$fy = (string)((int)date("n") >= 5 ? (int)date("Y") : (int)date("Y")-1);
$items = $m->invoke($c, $fy, "all");
echo "custom-order count in dashboard: ".$items->where("type","custom-order")->count()."\n";
echo "label: ".optional($items->firstWhere("type","custom-order"))["status_label"]."\n";
$o->delete();
'
```
Expected: `custom-order count in dashboard: 1` / `label: 契約`（クリーンアップで一時データは削除される）

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php tests/Feature/Housing/HousingDashboardMapOrderTest.php
git commit -m "$(cat <<'EOF'
feat(housing): 注文住宅を契約時点でダッシュボードに計上

引渡し済みのみ→契約以降(契約日基準)に変更し建売と統一。
mapOrderToDtoは実ステータス表示・契約日基準に。回帰テスト追加

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## マージ後（main repo）の手順

- [ ] main repo で FF-merge: `git checkout 13.x && git merge --ff-only <worktree-branch>`
- [ ] `php artisan test --filter=HousingDashboardMapOrderTest` → PASS を確認
- [ ] `./deploy.sh`（config/route/view キャッシュ再生成込み）
- [ ] 本番 `https://www.mitsuwat.co.jp/system/manage/housing` で、注文住宅「高木豊」（契約段階）が成約一覧に出ること、KPI 件数が +1 されることを確認

---

## Self-Review

- **Spec coverage:** §4.1（クエリ変更）= Step 3、§4.2（mapOrderToDto）= Step 4、§4.3（KPI/グラフ自動対応）= 変更不要を本文に明記、§4.4（仕様変更の明示）= コミットメッセージ+本番確認。カバー済み。
- **Placeholder scan:** TBD/TODO なし。全ステップに実コードまたは実コマンド。
- **Type consistency:** `collectContractedItems(string $fiscalYear, string $period)` の引数順は既存シグネチャ通り（tinker 検証で `($fy, "all")`）。`mapOrderToDto` の戻り key は既存と同一（`contracted_date` 等）。`CustomOrderStatus::label()/badgeStyle()` は Enum に存在（確認済み）。
- **制約整合:** hs_* テーブル無し制約に対し、DB依存の検証は tinker、純粋変換は reflection テストに割当て済み。
