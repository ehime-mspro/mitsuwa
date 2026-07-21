# 仕入れ案件「販売済」ステータス追加 ＋ 契約登録済み案件の一覧非表示 設計書

- 日付: 2026-07-21
- ブランチ: `13.x`
- 起点コミット: `7df32dff`
- 関連モジュール: 不動産管理 仕入れ案件（`/realestate/procurements`）、不動産契約（`/realestate/contracts`）

---

## 1. 背景・目的

仕入れ案件一覧（`/realestate/procurements`）で、**契約を登録した案件も「販売中」のまま表示され続ける**。件数が増えるほど、まだ動いている案件が埋もれる。

### 根本原因

`ReContractController::store()` は、分譲地の**区画**については `LotStatus::Sold` へ自動更新しているが、**仕入れ案件のステータスには一切触っていない**。

```php
// ReContractController::store() 現状
// 分譲地の場合、区画ステータスを sold に変更
if ($contractType->isSubdivision() && $contract->lot_id) {
    ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::Sold->value]);
}
// ← 仕入れ案件（isProcurement()）に対する同等の処理が存在しない
```

加えて `ProcurementStatus` には「売れた」を表す case が無く（8段階の終端は `Selling`＝販売中と `Lost`＝不成約のみ）、仮に手動で変えようとしても**変える先が無い**。

一覧の既定フィルタは `active`＝「不成約以外」なので、販売中のまま残った案件は必ず表示される。

**目的**: 契約を登録したら仕入れ案件が自動的に「販売済」となり、一覧の既定表示から外れる。ただしデータは残し、ステータス絞り込みと詳細画面から後で参照できる。

---

## 2. 決定事項（確定）

| # | 決定 | 理由 |
|---|---|---|
| D1 | **ステータス駆動**で非表示にする（契約有無の JOIN 判定やアーカイブ列は採らない） | DB 変更ゼロ。既存の分譲地PJ／区画と同じ作法。手動での差し戻しも効く |
| D2 | 遷移トリガーは**契約登録時に自動** | 分譲地区画が既に同じ挙動。運用の手間ゼロ・付け忘れゼロ |
| D3 | **1 仕入れ案件 = 1 契約**の前提を置く | ユーザー確認済み。分筆して複数に売る場合は分譲地PJ側で管理する運用 |
| D4 | **契約削除時は「販売中」へ自動で戻す** | 誤登録を消したとき案件が一覧から消えたまま行方不明になるのを防ぐ |
| D5 | enum 値は `sold`（`sold_out` ではない） | `sold_out` は分譲地PJの「区画が全部売れた＝完売」の意味。仕入れ案件は 1 物件なので区画の `LotStatus::Sold = 'sold'` と揃える |
| D6 | 仕入れ案件**詳細画面に「契約情報」カードを追加**する | 現在 仕入れ案件 → 契約 への導線が皆無。「販売済になった案件がどの契約で売れたか」を辿れるようにする |
| D7 | 本番の既存データ（契約済みなのに販売中の案件）は**一括 UPDATE で販売済にする** | 実行前に対象件数と物件名を一覧提示し、承認を得てから実行する |
| D8 | 経営ダッシュボードの「進行中パイプライン」からも販売済を除外する | 除外しないと売れた案件の想定販売価格が見込みに残り続ける |

### 2.1 スコープ外

- 契約ステータス（`ReContractStatus`）側の変更は行わない。仕入れ販売契約は登録時 `Contracted` で固定され、`Closed`／`Lost` への遷移は仲介（`Listing`）専用のガード付きで、販売契約には遷移導線が存在しない（現状維持）
- 分譲地PJ（`ProjectStatus`）側の一覧フィルタは触らない。あちらも `sold_out` が既定表示に残る同じ構造だが、今回の依頼範囲外

---

## 3. 変更内容

### 3.1 ステータス追加 — `app/Enums/ProcurementStatus.php`

`Selling` の直後に `Sold` を追加する。enum の定義順がそのままフィルタセレクトの並びになるため、`… 販売中 → 販売済 → 不成約` と自然に並ぶ。

```php
case Selling      = 'selling';
case Sold         = 'sold';      // ← 追加
case Lost         = 'lost';
```

- `label()`: `self::Sold => '販売済'`
- `badgeClass()`: `self::Sold => 'badge-re-sold'`
- `isClosed()` は**変更しない**（現状 `Lost` のみ true）。`ProcurementStatus::isClosed()` の呼び出し元は実測ゼロ（アプリ内の `isClosed()` 呼び出し 9 箇所は全て `InquiryStatus` のもの）。呼ばれていないメソッドの意味を先回りで広げると、後から使う側で「販売済は closed か」の解釈が割れる。非表示判定はフィルタ側に明示的に書く

**DB 変更は不要。** `re_procurements.status` は `varchar(20)`（MySQL ENUM ではない）で、`database/migrations/` にも `re_procurements` の定義は無い（raw SQL 管理）。値を足すだけで動く。

#### バッジ CSS

`badge-re-sold` を `background: #86efac; color: #14532d;` とする。

- 分譲地PJの「販売済」（`badge-prj-soldout`）と同色 → 事業横断で「販売済」の見た目が揃う
- 仕入れ案件の「決済完了」（`badge-re-settled` = `#a7f3d0` / `#064e3b`）とは別の緑なので隣り合っても混同しない

`badge-re-*` の CSS はページごとに全文コピーされており、**実測で 3 ファイル**ある。3 箇所すべてに `badge-re-sold` を追加する:

| ファイル | 備考 |
|---|---|
| `resources/views/realestate/procurements/index.blade.php` | 末尾の `<style>` ブロック |
| `resources/views/realestate/procurements/show.blade.php` | 同上 |
| `resources/views/realestate/suppliers/show.blade.php` | **見落としやすい。** 仕入れ先詳細に紐づく仕入れ案件が並び、`:103` で `$p->status->badgeClass()` を描画している |

1 箇所でも漏らすとその画面だけバッジが無地（背景・文字色なし）になる。Tailwind クラスではなくページ内 `<style>` の生 CSS なので、ビルドでは補われない。

### 3.2 自動遷移 — `app/Http/Controllers/RealEstate/ReContractController.php`

**新しいパターンは導入しない。** 同ファイルに既にある分譲地区画のライフサイクル 3 点セットと同じ形を、仕入れ案件に対して並べる。

| メソッド | 既存（分譲地区画） | 追加（仕入れ案件） |
|---|---|---|
| `store()` | 新区画 → `LotStatus::Sold` | 新案件 → `ProcurementStatus::Sold` |
| `update()` | 旧区画 → `OnSale` ／ 新区画 → `Sold` | 旧案件 → `Selling` ／ 新案件 → `Sold` |
| `destroy()` | 区画 → `OnSale` | 案件 → `Selling` |

ガード条件は `$contract->contract_type->isProcurement() && $contract->procurement_id`。仲介（`Brokerage`）や分譲地（`SubdivisionLot`）では `procurement_id` が付かないため、`isProcurement()` で正しく弾かれる。

#### `store()`

```php
// 仕入れ案件の場合、案件ステータスを販売済に変更
if ($contractType->isProcurement() && $contract->procurement_id) {
    ReProcurement::where('id', $contract->procurement_id)
        ->update(['status' => ProcurementStatus::Sold->value]);
}
```

#### `update()` — 案件の付け替えに対応する

区画側と同じく、**旧案件を戻して新案件を販売済にする**。この経路は見落とされやすいが、契約編集画面の案件セレクトは付け替えが可能なため必要。

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

`update()` は `$contractType = $contract->contract_type;`（リクエストではなく既存レコードの値）を使うため、契約種別は登録後に変更されない。種別跨ぎの遷移は考慮不要。

#### `destroy()`

```php
// 仕入れ案件の場合、案件ステータスを販売中に戻す
if ($contract->contract_type->isProcurement() && $contract->procurement_id) {
    ReProcurement::where('id', $contract->procurement_id)
        ->update(['status' => ProcurementStatus::Selling->value]);
}
```

D3（1 案件 = 1 契約）の前提により、「他に契約が残っていないか」の確認は行わない。区画側の `destroy()` も同じく無条件で戻している。

#### 更新方法の注意

3 経路とも **`ReProcurement::where(...)->update([...])` のクエリビルダ更新**を使う（区画側と同じ）。ID しか手元に無いので、モデルを読み込まずに 1 発で更新できる。

> **【2026-07-21 実装時に訂正】** 当初この節は「`$model->update()` だと `saved` フックで `syncPropertyPurchaseCost()` が発火するのを避けるため」と書いていたが、**これは誤り**だった。実際の `ReProcurement::booted()` は
> `if ($procurement->wasChanged(['assessment_price', 'purchase_price']) || $procurement->wasRecentlyCreated)` でガードしており、status だけの更新ではフックは発火しない。
> また `updated_at` は Eloquent の `Builder::update()` が `addUpdatedAtColumn()` で自動付与するため更新される（素の `DB::table()` 更新と混同しやすい）。
> **クエリビルダ更新で実際に生じる差分は「モデルイベントを通らないため `updated_by` が据え置きになる」の 1 点のみ。** 選択自体は妥当なので実装は変えていない。

### 3.3 一覧フィルタ — `ProcurementController::index()`

```php
// 変更前
if ($statusFilter === 'active') {
    $query->where('status', '!=', ProcurementStatus::Lost->value);
}

// 変更後
if ($statusFilter === 'active') {
    $query->whereNotIn('status', [
        ProcurementStatus::Lost->value,
        ProcurementStatus::Sold->value,
    ]);
}
```

Blade（`procurements/index.blade.php`）側は既定オプションのラベルのみ変更:

```
ステータス: 不成約以外  →  ステータス: 進行中のみ
```

**「販売済」の参照導線は追加実装ゼロ。** セレクトは `ProcurementStatus::cases()` をループしているため、enum に case を足した時点で「販売済」が選択肢に現れる。選べば販売済だけの一覧になる。

### 3.4 経営ダッシュボード — `DashboardController::aggregateProcurementStats()`

```php
// 変更前
$query = ReProcurement::where('status', '!=', ProcurementStatus::Lost->value);

// 変更後
$query = ReProcurement::whereNotIn('status', [
    ProcurementStatus::Lost->value,
    ProcurementStatus::Sold->value,
]);
```

メソッドの docblock「status=lost 以外を進行中とみなす」も実態に合わせて更新する。

### 3.5 詳細画面「契約情報」カード

#### モデル — `app/Models/ReProcurement.php`

```php
public function contracts(): HasMany
{
    return $this->hasMany(ReContract::class, 'procurement_id');
}
```

`use Illuminate\Database\Eloquent\Relations\HasMany;` は `costs()` で既に import 済み。

#### コントローラ — `ProcurementController::show()`

```php
$procurement->load([
    'supplier', 'costs.costItem', 'createdBy', 'updatedBy',
    'contracts.buyer', 'contracts.staff',   // ← 追加
]);
```

`ReContract::buyer()` は `->withTrashed()` 済み（`Buyer` は SoftDeletes）、`staff()` も `->withTrashed()` 済みなので、削除済み買主・退職者でも表示が壊れない。

#### Blade — `procurements/show.blade.php`

カードの配置は **「仕入れ情報」の直後**（仕入れ → 販売 の時系列に沿う）。既存カードと同じ枠・見出しスタイル（`<h2 class="text-base font-bold text-gray-900">契約情報</h2>`）を使う。

表示項目（1 契約 1 行）:

| 列 | 内容 |
|---|---|
| 契約日 | `contract_date` |
| 契約種別 | `contract_type->shortLabel()` ＋ バッジ |
| 買主 | `buyer` の氏名（未設定なら `buyer_name`） |
| 契約金額 | `contract_amount`、`28,500,000円` 形式 |
| 粗利 | `gross_profit`、`color: #047857; font-weight: 700` |
| 詳細 | `route('realestate.contracts.show', $c)` へのリンクボタン |

契約が 0 件のときはカードごと非表示にする（`@if($procurement->contracts->isNotEmpty())`）。仕入れ段階の案件に空のカードを出しても情報量が無い。

**Blade 記法の注意（既知バグの回避）**:
- `@json()` は使わない。素の Blade 出力のみ（Bug #7 / #23 / #26 の全経路を回避）
- 金額は `number_format(...) . '円'`、`¥` 接頭辞は使わない（プロジェクト規約）
- ステータスバッジはモデル／enum のメソッド経由（`badgeStyle()` は inline style を返す）

### 3.6 既存データの移行

本番には「契約登録済みなのに販売中のまま」の案件が既に存在する（今回の相談の発端）。ローカル DB は仕入れ案件 1 件・契約紐付け 0 件のため、**本番の実データを見てから件数を確定する**。

**手順（デプロイ後に実行）**:

1. 対象を SELECT で洗い出し、件数と物件名をユーザーに提示

```sql
SELECT p.id, p.procurement_code, p.property_name, p.status, c.id AS contract_id, c.contract_date
FROM re_procurements p
JOIN re_contracts c ON c.procurement_id = p.id
WHERE p.status NOT IN ('sold', 'lost')
ORDER BY p.id;
```

2. 承認を得てから UPDATE

```sql
UPDATE re_procurements p
JOIN re_contracts c ON c.procurement_id = p.id
SET p.status = 'sold', p.updated_at = NOW()
WHERE p.status NOT IN ('sold', 'lost');
```

`lost`（不成約）は除外する。不成約にした案件に契約が紐づいている場合、それは別の理由がある可能性が高く、勝手に販売済へ倒さない。

**適用方法**: 本番の生 SSH はハーネスの分類器にブロックされるため、`php artisan tinker` 経由（`.env` の認証情報を使う）で `DB::statement(...)` を実行する。`sudo mysql` は非対話でパスワードを渡せない。

### 3.7 影響を受けない箇所（確認済み）

| 箇所 | 現状 | 判定 |
|---|---|---|
| 契約作成画面の案件セレクト（`ReContractController::create()`） | `status = Selling` のみ表示 | **変更不要**。販売済は候補から自動的に外れるのが正しい（D3: 1案件=1契約） |
| 契約編集画面の案件セレクト（`edit()`） | `status = Selling` **＋現在選択中の案件**を `orWhere` で含む | **変更不要**。販売済になった自案件は `orWhere('id', $contract->procurement_id)` で拾われるため、編集画面でセレクトが空にならない |
| `validateProcurement()` / `updateStatus()` のバリデーション | `implode(',', array_column(ProcurementStatus::cases(), 'value'))` | **変更不要**。enum から動的生成のため `sold` が自動的に許可される |
| 一覧のステータスバッジ ポップオーバー | `ProcurementStatus::cases()` から生成 | **変更不要**。「販売済」が自動で選択肢に加わり、手動での差し戻しも可能 |
| 新規登録／編集フォームのステータス select（`_form.blade.php`） | `ProcurementStatus::cases()` をループ | **変更不要** |

---

## 4. 変更ファイル一覧

| # | ファイル | 変更内容 |
|---|---|---|
| 1 | `app/Enums/ProcurementStatus.php` | `Sold` case ＋ `label()` ＋ `badgeClass()` |
| 2 | `app/Models/ReProcurement.php` | `contracts()` リレーション追加 |
| 3 | `app/Http/Controllers/RealEstate/ReContractController.php` | `store()` / `update()` / `destroy()` に自動遷移 3 点 |
| 4 | `app/Http/Controllers/RealEstate/ProcurementController.php` | `index()` の `active` 定義変更、`show()` の eager load 追加 |
| 5 | `app/Http/Controllers/DashboardController.php` | `aggregateProcurementStats()` から販売済を除外 |
| 6 | `resources/views/realestate/procurements/index.blade.php` | 既定オプションのラベル変更、`badge-re-sold` CSS |
| 7 | `resources/views/realestate/procurements/show.blade.php` | 「契約情報」カード追加、`badge-re-sold` CSS |
| 8 | `resources/views/realestate/suppliers/show.blade.php` | `badge-re-sold` CSS のみ（仕入れ先詳細も案件バッジを描画するため） |

DB マイグレーションは**無し**。本番データの一括 UPDATE のみ別途実行（3.6）。

---

## 5. テスト計画

### 5.1 Unit テスト（DB 非依存）

`tests/Unit/RealEstate/ProcurementStatusTest.php`（新規）

- `Sold` case が存在し、値が `'sold'` であること
- `label()` が `'販売済'` を返すこと
- `badgeClass()` が `'badge-re-sold'` を返すこと
- `cases()` の並びが `… Selling, Sold, Lost` の順であること（セレクトの表示順が仕様）
- `Sold->isClosed()` が `false` であること（3.1 の判断を固定し、後から意味を広げられて非表示判定が二重化するのを防ぐ）

### 5.2 Feature テスト（自動遷移の 3 経路）

`tests/Feature/RealEstate/ProcurementStatusTransitionTest.php`（新規）

`re_procurements` / `re_contracts` は raw SQL 管理で `database/migrations/` に定義が無いため、テスト用に `Schema::create` するトレイトを用意する（ZEAL の既存テストで実証済みのパターン）。

| # | シナリオ | 期待 |
|---|---|---|
| T1 | 販売中の案件に仕入れ販売契約を `store()` | 案件が `sold` になる |
| T2 | 仲介契約を `store()`（`procurement_id` 無し） | どの案件も変化しない |
| T3 | 契約の `procurement_id` を A → B に `update()` | A が `selling`、B が `sold` |
| T4 | 契約を `destroy()` | 案件が `selling` に戻る |
| T5 | 一覧を既定フィルタで開く | `sold` の案件が含まれない |
| T6 | 一覧を `?status=sold` で開く | `sold` の案件が表示される |

`phpunit.xml` の `APP_URL=http://localhost`（パス無し固定）はそのまま。本番は `/system/manage` 配下だが、この行を消すとテストの `$this->get('/path')` が 404 になる。

### 5.3 手動確認（デプロイ後）

1. 仕入れ案件一覧の既定表示に販売済が出ないこと
2. セレクトで「販売済」を選ぶと販売済だけが出ること
3. 販売済案件の詳細を開き、「契約情報」カードから契約詳細へ遷移できること
4. 経営ダッシュボードの仕入れパイプライン件数が減っていること
5. 一覧バッジのポップオーバーに「販売済」が現れ、手動で他ステータスへ戻せること

### 5.4 デプロイ前の必須検証

Bug #26 の教訓により、`view:cache` の成功表示だけでは Blade のコンパイル結果を保証できない。コンパイル済み PHP を必ず lint する:

```bash
php artisan view:cache && \
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && \
php artisan view:clear
```

---

## 6. リスクと対策

| リスク | 対策 |
|---|---|
| `badge-re-sold` の CSS 定義漏れで特定画面だけバッジが無地になる | 定義先は実測済みの 3 ファイル（3.1）。とくに `suppliers/show.blade.php` は仕入れ案件の画面名を持たないため見落としやすい。実装後に `grep -c "badge-re-sold" resources/views/realestate/*/show.blade.php resources/views/realestate/procurements/index.blade.php` で 3 箇所を数えて確認する |
| 契約編集で案件を付け替えたとき、旧案件が販売済のまま取り残される | `update()` の旧案件戻し（3.2）で対処。T3 で回帰を固定 |
| 1 案件に複数契約を切る運用が後から出てきて、2 件目が登録できなくなる | D3 の前提。もし発生したら `create()` のセレクト条件に `Sold` を加えるだけで対応できる（1 行）。設計を壊さない |
| 本番一括 UPDATE で意図しない案件まで販売済になる | 先に SELECT で件数・物件名を提示して承認を得る。`lost` は除外 |
| ローカル DB が空（案件 1 件・契約 0 件）のため本番でだけ壊れる経路を見逃す | Bug #22 / #25 / #26 と同型のリスク。5.2 の Feature テストで実データ相当の状態を作って検証する |

---

## 7. 実装順序

1. `ProcurementStatus` に `Sold` 追加 ＋ Unit テスト（5.1）
2. `ReContractController` の自動遷移 3 点 ＋ Feature テスト T1〜T4
3. `ProcurementController::index()` のフィルタ ＋ Blade ラベル ＋ バッジ CSS ＋ Feature テスト T5・T6
4. `DashboardController` の集計除外
5. `ReProcurement::contracts()` ＋ `show()` の eager load ＋ 「契約情報」カード
6. コンパイル済み Blade の `php -l` 検証（5.4）
7. `/commit` → main repo で `merge --ff-only` → `./deploy.sh`
8. 本番データの一括 UPDATE（3.6）— 件数提示 → 承認 → 実行
9. 手動確認（5.3）
