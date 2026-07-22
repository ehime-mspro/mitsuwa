# 分譲地PJ「販売済(sold_out)」自動遷移 ＋ 完売PJの一覧非表示 設計書

- 日付: 2026-07-22
- ブランチ: `project-sold-status`（起点 `13.x` = `7ac09e2e`）
- 関連モジュール: 不動産 分譲地PJ（`/realestate/projects`）、不動産契約（`/realestate/contracts`）、住宅事業 建売契約 / 注文住宅（`/housing/*`）
- 横展開の雛形: `docs/superpowers/specs/2026-07-21-procurement-sold-status-design.md`（仕入れ案件版）

---

## 1. 背景・目的

分譲地PJ一覧（`/realestate/projects`）で、**全区画が成約済みのPJも「販売中」等のまま既定表示に残り続ける**。仕入れ案件と同じ症状だが、構造が根本的に異なる。

### 根本原因

1. **集約遷移が存在しない** — 契約登録・住宅契約は分譲地区画（`re_project_lots`）を `LotStatus::Sold` へ更新するが、**PJ全体のステータス（`ProjectStatus`）を「全区画成約→販売済」に集約する処理がどこにも無い**。区画は売れても PJ は `Selling`（販売中）のまま。
2. **一覧の既定フィルタが `Lost` しか除外しない** — `ProjectController::index()` の既定 `active` は `where('status','!=',Lost)` のみ。`SoldOut` は残る。

### 仕入れ案件版との構造差（実測済み）

| 論点 | 仕入れ案件（実装済み） | 分譲地PJ（本設計） |
|---|---|---|
| enum | `Sold` case を追加した | **`ProjectStatus::SoldOut='sold_out'`（label「販売済」）は既存**。追加不要 |
| 単位 | 1案件 = 1契約。契約登録で即 `Sold` | **1 PJ = 複数区画**。「販売済」= **全区画完売の集約判定** |
| 区画を Sold にする経路 | （区画概念なし） | **4系統**。契約(分譲地)・**建売契約**・**注文住宅**・区画編集。本番の住宅土地元 9/9 が分譲地区画＝**住宅経由が主経路** |
| ダッシュボード | `aggregateProcurementStats()` で集計→除外した | **分譲地PJは集計されていない**（`DashboardController` に import すら無い）→ **除外対象が存在しない** |

---

## 2. 決定事項（確定）

- **方式B: 全4経路で自動遷移**（ユーザー承認済み）。手動運用のみ（方式A）や分譲地契約のみ（方式C）は不採用。
- **Approach 2: 明示呼び出し**（ユーザー承認済み）。集約ロジックは `ReProject::syncStatusFromLots()` 1メソッドに集約し、各区画変更経路から**明示的に**呼ぶ。`ReProjectLot` へのモデル observer は不採用（このコードベースは暗黙挙動由来のバグが多く、`grep 'syncStatusFromLots('` で全トリガーを追える明示方式を優先。前回出荷した区画クエリビルダ更新も書き換えない）。
- **昇格の発火条件は「緩め」**（ユーザー承認済み）: 「販売済＝全区画完売」を**派生的な完了状態**として扱い、`Lost`・`SoldOut` 以外のどのステージからでも昇格する。全区画が実際に売れるのは終盤だけなので「ライフサイクル飛ばし」は実務上起きず、PJステータスを手動で「販売中」に上げていない住宅主経路でも確実に発火する。

### 2.1 スコープ外

- `ProjectStatus` enum の変更（`SoldOut` は既存）
- バッジ CSS（`badge-prj-soldout` 緑は既存: `background:#86efac; color:#14532d;`）
- 経営ダッシュボード（分譲地PJを集計していないため無関係）
- 一覧のインライン Ajax 状態セル（`projectStatusCell` / `updateStatus`）— 手動の任意遷移として**現状維持**。自動遷移は `Selling ↔ SoldOut` の境界のみを管理し共存する
- 既存データ移行 SQL（本番 `re_projects` 16件中 `sold_out` は 0件＝矛盾レコード無し。手動移行は不要。デプロイ後は各区画変更時に自然に集約される）

---

## 3. 変更内容

### 3.1 一覧フィルタ — `ProjectController::index()`

既定 `active` を `Lost` に加えて `SoldOut` も除外する。procurement 版と同形・同文言。

```php
// 変更前
$statusFilter = $request->input('status', 'active');
if ($statusFilter === 'active') {
    $query->where('status', '!=', ProjectStatus::Lost->value);
} elseif ($statusFilter !== '') {
    $query->where('status', $statusFilter);
}

// 変更後
// フィルター: ステータス（デフォルトは進行中のみ = 不成立・販売済を除く）
$statusFilter = $request->input('status', 'active');
if ($statusFilter === 'active') {
    $query->whereNotIn('status', [
        ProjectStatus::Lost->value,
        ProjectStatus::SoldOut->value,
    ]);
} elseif (filled($statusFilter)) {
    $query->where('status', $statusFilter);
}
```

⚠ **`elseif` は `!== ''` ではなく `filled()`**（実装時に判明した既存バグ回避）。
「全て」オプションは `status=''` を送るが、Laravel 12 既定の `ConvertEmptyStringsToNull`
グローバルミドルウェアが**クエリ文字列の空文字も null 化**する。`$request->input('status','active')`
はキーが存在するため既定値 `'active'` を返さず **null** を返し、`null !== ''` は true なので
`where('status', null)` = `WHERE status IS NULL` で **0 件**になる。`filled(null)` は false
なので `filled()` ガードなら null/空を弾いてフィルタ無し（＝全件）に落ちる。本機能で sold_out
を既定表示から外したことで「全て」が販売済PJを見る主導線になるため、この修正は必須。
テスト F2（`?status=` で全件）がこの挙動を固定する。
（**同型の潜在バグが `ProcurementController::index` にもある**が、本タスクのスコープ外＝別途対応。）

Blade（`resources/views/realestate/projects/index.blade.php`）のフィルタ option ラベル:

```blade
{{-- 変更前 --}}
<option value="active" ...>ステータス: 不成立以外</option>
{{-- 変更後（procurement と同文言）--}}
<option value="active" ...>ステータス: 進行中のみ</option>
```

`status=''`（全て）と個別ステータス選択（`sold_out` 単独指定を含む）は現状のまま機能する。

### 3.2 集約ロジック — `app/Models/ReProject.php`（新規メソッド）

区画の成約状況から PJ ステータスを集約する唯一の入口。

```php
/**
 * 区画の成約状況から PJ ステータスを集約する。
 *
 * - 全区画成約（区画1件以上 かつ 全て LotStatus::Sold）→ SoldOut へ昇格
 *   （昇格元は「販売済・不成立 以外」の任意ステータス。「販売済＝完売」を
 *     派生的な完了状態として扱うため。区画が全て売れるのは実務上終盤のみ）
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
 *   既知の呼び出し箇所は §3.3 を参照。新経路を足すときは必ず呼び出しを追加。
 */
public function syncStatusFromLots(): void
{
    $lots  = ReProjectLot::where('project_id', $this->id)->get(['status']);
    $total = $lots->count();
    if ($total === 0) {
        return; // 区画0件は無干渉
    }

    $allSold = $lots->every(fn ($lot) => $lot->status === LotStatus::Sold);
    $current = $this->status;

    if ($allSold && ! in_array($current, [ProjectStatus::SoldOut, ProjectStatus::Lost], true)) {
        // 昇格: 全区画成約 → 販売済
        ReProject::where('id', $this->id)->update(['status' => ProjectStatus::SoldOut->value]);
        $this->status = ProjectStatus::SoldOut;
    } elseif (! $allSold && $current === ProjectStatus::SoldOut) {
        // 降格: 未成約区画が復活 → 販売中
        ReProject::where('id', $this->id)->update(['status' => ProjectStatus::Selling->value]);
        $this->status = ProjectStatus::Selling;
    }
}
```

補助: 可読性・テスト用に `allLotsSold(): bool` を切り出してもよい（任意。`getSoldLotCount()` 等の既存ヘルパーと並置）。

**設計上の注意:**
- `$lots->every()` は空コレクションに対し `true` を返すため、`$total === 0` の早期 return が必須（0件PJを誤って昇格させない二重防御）。
- `LotStatus` は enum キャスト済みなので比較は enum 同士（`=== LotStatus::Sold`）。生文字列 `'sold'` との比較は避ける。
- 昇格条件に `Selling` を含める必要はない（`Selling` も「SoldOut・Lost 以外」に含まれる）。

### 3.3 呼び出し箇所（10箇所・全て1行）

いずれも「区画の status を変えた直後」に、影響を受ける PJ に対して `syncStatusFromLots()` を呼ぶ。

| # | ファイル / メソッド | 契機 | 呼び出し |
|---|---|---|---|
| 1 | `RealEstate\ReContractController::store` | 分譲地契約 成約（区画→Sold, L163付近） | `ReProject::find($contract->project_id)?->syncStatusFromLots();` |
| 2 | `RealEstate\ReContractController::update` | 契約の付け替え（区画/PJ変更, L304-313付近） | 旧区画・新区画の**distinct な project_id 全て**を sync（後述） |
| 3 | `RealEstate\ReContractController::destroy` | 契約解除（区画→OnSale, L344付近） | `ReProject::find($contract->project_id)?->syncStatusFromLots();` |
| 4 | `RealEstate\ProjectController::storeLot` | 区画追加（L520付近） | `$project->syncStatusFromLots();`（route model binding の `$project` を利用） |
| 5 | `RealEstate\ProjectController::updateLot` | 区画編集（status直接変更, L556付近） | `$project->syncStatusFromLots();` |
| 6 | `RealEstate\ProjectController::destroyLot` | 区画削除（L574付近） | `$project->syncStatusFromLots();` |
| 7 | `Housing\ContractController::updateLotStatusOnSold` | **建売契約 成約（主経路）** | `$lot->project?->syncStatusFromLots();` |
| 8 | `Housing\ContractController::updateLotStatusOnUnsold` | 建売契約 解除 | `$lot->project?->syncStatusFromLots();` |
| 9 | `Housing\CustomOrderController::syncLotStatus` | **注文住宅 契約以降/以前 遷移（主経路）** | `$lot->project?->syncStatusFromLots();` |
| 10 | `Housing\CustomOrderController::releaseLot` | 注文住宅 削除 | `$lot->project?->syncStatusFromLots();` |

**#2（付け替え）の詳細:** 契約更新では区画（さらにPJ）が変わりうる。旧区画は `OnSale` に戻り新区画は `Sold` になるため、**旧PJと新PJの両方**が完売状態変化の対象。distinct な project_id を集めて各々 sync する:

```php
// 区画ステータス更新ブロック（L304-313）の直後に置く。
// この時点で旧区画=OnSale・新区画=Sold が既に反映済み。
// PJ の特定は $contract->project_id ではなく「区画レコードの project_id」から行う
// （付け替えで PJ 自体が変わっても旧新両方を正しく拾えるため）。
$affected = [];
if ($oldLotId) { $affected[] = ReProjectLot::find($oldLotId)?->project_id; }
if ($newLotId) { $affected[] = ReProjectLot::find($newLotId)?->project_id; }
foreach (array_unique(array_filter($affected)) as $pid) {
    ReProject::find($pid)?->syncStatusFromLots();
}
```
（`re_project_lots` は SoftDeletes 非対応。旧区画は status 変更のみで存在し続けるので素の `find()` で取得できる。）

**#7-10（住宅経路）:** `$lot` は `$property->projectLot` / `$order->projectLot`（= `ReProjectLot`）。`$lot->project`（lazy-load, 現在ステータスを都度取得）に対し sync する。`$lot` が null のガード（`if ($lot)`）は既存コードにあるので、その内側で呼ぶ。

### 3.4 影響を受けない箇所（確認済み）

- `ProjectStatus` enum / `badge-prj-soldout` CSS（既存）
- `DashboardController`（分譲地PJ未集計）
- インライン Ajax 状態セル `updateStatus`（手動遷移として現状維持）
- 契約 create/edit の「販売中のPJ」ドロップダウン（`where('status', Selling)`）— 完売PJが `SoldOut` になると自動的に選択肢から外れる。これは正しい挙動（完売PJに新規契約を付けられない）

---

## 4. 変更ファイル一覧

| ファイル | 変更 |
|---|---|
| `app/Models/ReProject.php` | `syncStatusFromLots()` 追加（+ 任意で `allLotsSold()`）。`LotStatus` の use 追加 |
| `app/Http/Controllers/RealEstate/ProjectController.php` | index フィルタに `SoldOut` 追加、storeLot/updateLot/destroyLot に sync 呼び出し（3箇所） |
| `app/Http/Controllers/RealEstate/ReContractController.php` | store/update/destroy に sync 呼び出し（3箇所） |
| `app/Http/Controllers/Housing/ContractController.php` | updateLotStatusOnSold/OnUnsold に sync 呼び出し（2箇所） |
| `app/Http/Controllers/Housing/CustomOrderController.php` | syncLotStatus/releaseLot に sync 呼び出し（2箇所） |
| `resources/views/realestate/projects/index.blade.php` | フィルタ option ラベル「不成立以外」→「進行中のみ」 |
| `tests/Concerns/CreatesRealEstateSchema.php` | `re_projects` / `re_project_lots` テーブル追加（+ 住宅経路テスト用に `hs_properties` / `hs_contracts` / `hs_custom_orders`） |
| `tests/Feature/RealEstate/ProjectSoldStatusTest.php` 等 | 新規テスト |

---

## 5. テスト計画（TDD）

### 5.1 スキーマ trait 拡張

`CreatesRealEstateSchema` に現状未収録の `re_projects` / `re_project_lots` を追加。住宅経路（本番主経路）を end-to-end で担保するため `hs_properties`（`re_project_lot_id` 列を含む）/ `hs_contracts` / `hs_custom_orders` も追加（`php artisan db:table <table>` で実スキーマを実測して定義）。SQLite 非対応の MySQL enum 列は string で代替（既存 trait の方針を踏襲）。

### 5.2 集約ロジック単体（`syncStatusFromLots()` 直呼び）

`re_projects` + `re_project_lots` のみで完結。ルールの全分岐を担保:

- 最終区画が Sold → 全区画成約 → `Selling` → `SoldOut` に昇格
- 一部のみ Sold → 昇格しない（現状維持）
- 全区画 Sold の PJ で1区画が `OnSale` に復活 → `SoldOut` → `Selling` に降格
- 区画0件PJ → 無変化
- `Lost` のPJは全区画成約でも触らない
- **昇格元が Selling 以外**（例: `Settled` / `Contracted`）でも全区画成約なら昇格する（緩め条件の担保）
- `SoldOut`・`Lost` からは昇格ロジックで再更新しない（冪等）

### 5.3 経路別 Feature テスト（呼び出し配線の担保）

- **分譲地契約**: store で最終区画成約 → PJ `SoldOut` / destroy で契約解除 → PJ `Selling` / update で区画付け替え時の整合
- **建売契約（主経路）**: 分譲地区画を土地元にした `HsProperty` の契約 store → 当該 PJ が `SoldOut`、契約 destroy → `Selling`
- **注文住宅（主経路）**: `HsCustomOrder` が契約以降ステータスへ → 区画 Sold → PJ `SoldOut`、契約以前へ戻す/削除 → `Selling`
- **区画操作**: updateLot で最終区画を Sold に → `SoldOut` / storeLot で `OnSale` 区画追加 → `SoldOut` から `Selling` / destroyLot で最後の未成約区画削除 → `SoldOut`

### 5.4 一覧フィルタ Feature テスト

- 既定（status 無し）で `SoldOut` と `Lost` のPJが出ない
- `status=''`（全て）で全ステータス出る
- `status=sold_out` で `sold_out` のみ出る

### 5.5 デプロイ前の必須検証（Bug #26）

`view:cache` 成功だけでは不十分。コンパイル済みビューを必ず `php -l`:

```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' php artisan view:cache && \
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && \
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' php artisan view:clear
```

（今回 Blade 変更は option ラベル1語のみだが、規約として実施。）

### 5.6 手動確認（デプロイ後）

- 全区画成約済みの分譲地が一覧の既定表示から消える
- 「ステータス: 全て」で表示され緑「販売済」バッジが付く
- 住宅（建売/注文住宅）で最後の区画を成約させると当該分譲地が `SoldOut` になる

---

## 6. リスクと対策

| リスク | 対策 |
|---|---|
| 呼び出し漏れ（明示方式の弱点） | 全10経路をテストで担保。メソッド docblock に「全経路から呼ぶ」明記。§3.3 を唯一の台帳とする |
| `every()` の空コレクション true で0件PJ誤昇格 | `$total === 0` 早期 return で二重防御。テストで担保 |
| 手動インライン状態セルとの競合 | 自動は `Selling ↔ SoldOut` 境界のみ管理。手動で `SoldOut` にした未完売PJは次の区画イベントで `Selling` に戻る（＝未完売で販売済は不整合なので正しい挙動）。仕様として docblock/spec に明記 |
| 住宅スキーマ追加でテストが重い | 主経路なので end-to-end で担保する価値がある。`db:table` で実測しコピー |
| クエリビルダ更新で in-memory status が stale | メソッド内で `$this->status` も更新。呼び出し元は PJ オブジェクトを sync 後に使わない（redirect/JSON 返却）ため実害は元々小さいが二重に安全化 |

---

## 7. 実装順序

1. `CreatesRealEstateSchema` に `re_projects` / `re_project_lots`（+ 住宅3テーブル）を追加 → 失敗するテストを先に書く（TDD Red）
2. `ReProject::syncStatusFromLots()` 実装 → 単体テスト Green
3. 分譲地契約経路（ReContractController 3メソッド）配線 → Feature Green
4. 区画操作経路（ProjectController 3メソッド）配線 → Feature Green
5. 住宅経路（ContractController / CustomOrderController 各2メソッド）配線 → Feature Green
6. 一覧フィルタ（ProjectController::index + Blade ラベル）→ Feature Green
7. `view:cache` → `php -l` 検証（§5.5）
8. `/commit` → main repo で FF-merge → `./deploy.sh`（**ユーザー明示承認後**）
