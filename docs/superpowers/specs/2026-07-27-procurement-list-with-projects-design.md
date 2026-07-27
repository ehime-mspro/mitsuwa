# 仕入れ案件一覧に分譲地を統合 — 設計書

- 日付: 2026-07-27
- 対象画面: `/realestate/procurements`（仕入れ案件一覧）
- モック: `docs/mockups/realestate/procurements-with-projects.html`
- ブランチ: `feature/procurement-list-projects`

---

## 1. 背景と目的

不動産管理は「仕入れ案件」（`re_procurements`）と「分譲地」（`re_projects`）が
別々の一覧画面に分かれている。どちらも「土地・建物を仕入れて売る案件」であり、
進行中の案件を横断して眺めたいという運用要求がある。

仕入れ案件一覧を **不動産案件の横断ビュー** に格上げし、分譲地も同じ表に出す。
分譲地一覧ページは分譲地だけを見たいとき用に**そのまま残す**。

**DB 変更は不要。** 表示する値はすべて既存カラム・既存モデルメソッドから得られる。

---

## 2. 決定事項（ユーザー承認済み）

| # | 決定 | 内容 |
|---|------|------|
| 1 | 一覧の対象 | 仕入れ案件 ＋ 分譲地を混在表示。**情報入手日の降順**で1本にマージ、20件/ページ |
| 2 | 物件種別 | 分譲地の行は**素のテキストで「分譲地」**。囲みバッジにしない |
| 3 | 物件種別セレクト | 「物件種別: 全て」の直下＝**実種別の先頭**に「分譲地」を置く |
| 4 | 取引種別 | 分譲地は「—」。取引種別で絞ると分譲地は結果から外れる |
| 5 | 区画数 | **専用の列は設けない**。分譲地の行の**物件名の下**に「区画 成約数 / 総数」を小さく添える |
| 6 | 区画ボタン | 分譲地の行のみ表示（**区画0件でも表示**）。仕入れ案件は「—」 |
| 7 | 詳細ボタン | **全行とも緑に統一**（分譲地一覧ページ側の琥珀色はそのまま） |
| 8 | 行の背景色 | 分譲地の行に背景色は**付けない** |
| 9 | ステータスバッジ | **各モデル固有のラベル・色のまま**。クリック即時変更も既存 API へ送り挙動を変えない |
| 10 | 新規登録ボタン | **現状のまま**（仕入れ案件の登録へ）。分譲地の登録は分譲地一覧ページから |
| 11 | 分譲地一覧ページ | **残す**（サイドバーの「分譲地」も維持） |

---

## 3. 画面仕様

### 3.1 列定義（全 10 列）

| # | 見出し | 仕入れ案件 | 分譲地 |
|---|--------|-----------|--------|
| 1 | 物件名 | `property_name` | `project_name` ＋ 下に区画サブ行 |
| 2 | ステータス | `ProcurementStatus` バッジ | `ProjectStatus` バッジ |
| 3 | 物件種別 | `RealEstatePropertyType::label()` | 固定文字列「分譲地」 |
| 4 | 取引種別 | `RealEstateTransactionType::label()` | 「—」 |
| 5 | 購入価格 | `purchase_price` | `purchase_price` |
| 6 | 想定販売価格 | `target_selling_price` | `target_selling_price` |
| 7 | 粗利見込み | `getExpectedProfit()` | `getExpectedProfit()` |
| 8 | マップ | 緯度経度があればボタン | 同左 |
| 9 | 区画 | 「—」 | 区画一覧へのリンクボタン（**常に表示**） |
| 10 | 詳細 | 詳細へのリンク（緑） | 詳細へのリンク（**緑**） |

金額は既存どおり税抜・末尾「円」・3桁区切り。未入力は「—」。
粗利見込みは `color: #047857; font-weight: 700`。

`min-width` は現行 `1000px` → **`1080px`**（列が1本増えるため）。

### 3.2 物件名セルの区画サブ行（分譲地のみ）

```
六軒家町 分譲地
区画 3 / 5          ← font-size: 11px; color: #6b7280; margin-top: 3px
                       成約数のみ color: #047857; font-weight: 700
```

- 成約数 = `getSoldLotCount()`（`lots` のうち `status === LotStatus::Sold`）
- 総数 = `lots->count()`
- 区画0件でも `区画 0 / 0` を出す
- **仕入れ案件の行にはサブ行の要素自体を出さない**（空の `<div>` も置かない）

### 3.3 フィルターバー

#### 物件種別セレクト

```
物件種別: 全て          value=""
分譲地                  value="project"     ← 新規・実種別の先頭
中古マンション          value="used_mansion"
中古戸建                value="used_house"
仲介土地                value="brokerage_land"
一棟売りマンション      value="mansion_bldg"
テナントビル            value="tenant_bldg"
アパート                value="apartment"
```

> ⚠ **`RealEstatePropertyType` enum に `Project` ケースを追加してはならない。**
> このセレクトは `RealEstatePropertyType::cases()` をループして描画しているが、
> 同じ enum を仕入れ案件の登録フォーム `resources/views/realestate/procurements/_form.blade.php:16`
> も参照している。enum に足すと登録フォームの選択肢にも自動で出てしまい、
> 「物件種別＝分譲地の仕入れ案件」という実体のないデータを作れてしまう
> （`ProcurementController::rules()` のバリデーションも `RealEstatePropertyType::cases()` から
> 生成しているため素通りする）。
>
> **「分譲地」は一覧フィルタ専用の擬似値 `'project'` として、Blade に静的な `<option>` を
> 1本手書きで追加する。** enum には触らない。

#### ステータスセレクト

選択肢は現行どおり `ProcurementStatus::cases()` を使う（仕入れ案件側のラベルを正とする）。
分譲地には内部でマッピングして適用する。

| セレクトの値 | 仕入れ案件への条件 | 分譲地への条件 |
|---|---|---|
| `active`（既定・進行中のみ） | `NOT IN (lost, sold)` | `NOT IN (lost, sold_out)` |
| `''`（全て） | 条件なし | 条件なし |
| `info_obtained` | `= info_obtained` | `= info_obtained` |
| `site_survey` | `= site_survey` | **該当なし → 分譲地は0件** |
| `assessment` | `= assessment` | `= assessment` |
| `negotiating` | `= negotiating` | `= negotiating` |
| `contracted` | `= contracted` | `= contracted` |
| `settled` | `= settled` | `= settled` |
| `selling` | `= selling` | `= selling` |
| `sold`（販売済） | `= sold` | `= sold_out` |
| `lost`（不成約） | `= lost` | `= lost`（分譲地のラベルは「不成立」） |

「現地調査」の選択肢のラベルだけ **「現地調査（仕入れ案件のみ）」** と補記する。

ラベルの差（仕入れ「査定・検討」/ 分譲地「検討」、仕入れ「不成約」/ 分譲地「不成立」）は
**行のバッジ側では各モデル固有のまま**にする。セレクトのラベルと行のバッジのラベルが
一致しないケースが出るが、値としては同じものを指しており、既存画面の表示を変えない方を優先する。

> ⚠ 「全て」= `?status=` は `ConvertEmptyStringsToNull` で null 化され、Blade の
> `request()` ヘルパは null を既定値に読み替える（`helpers.php` の `is_null($value) ? $default`）。
> よって **`status` キーの有無は `has()` で見る**（現行実装のコメントどおり。この挙動を壊さない）。
> コントローラ側も `filled()` で判定する。

#### 取引種別セレクト

現行のまま。値が入っているとき（`filled()`）は**分譲地を結果に含めない**
（分譲地に `transaction_type` カラムが無く、該当し得ないため）。

#### キーワード

| 対象 | 検索列 |
|---|---|
| 仕入れ案件 | `procurement_code` / `property_name` / `address` |
| 分譲地 | `project_code` / `project_name` / `address` |

プレースホルダは現行の「物件名・所在地・案件番号」のまま。

### 3.4 並び順

`info_obtained_date` の**降順**（NULL は末尾）→ `id` 降順 → 種別（仕入れ案件が先）。

現行の両画面がともに `orderByDesc('info_obtained_date')->orderByDesc('id')` で
揃っているため、マージ後もその順序を踏襲する。MySQL の `ORDER BY ... DESC` は
NULL を末尾に置くので、PHP 側のソートでも **NULL 末尾**を再現する。

種別を最終タイブレークに入れるのは、日付も id も一致する行が
**別テーブル間では起こりうる**ため（ページ間で行が重複・欠落しないよう順序を確定させる）。

### 3.5 件数表示・ページネーション

- 「全 N 件」は**両種別の合算**
- ページネーションは既存のインライン番号付きマークアップをそのまま使う
  （`->links()` は使わない。プロジェクト規約）
- フィルタはページ送り後も保持する

### 3.6 空表示

`colspan` を 9 → **10** に変更し、文言を
「仕入れ案件データがありません。」→ **「該当するデータがありません。」** に変える。

---

## 4. 実装設計

### 4.1 構成

| ファイル | 区分 | 内容 |
|---|---|---|
| `app/Services/RealEstate/ProcurementListRow.php` | 新規 | 1行分の正規化済み表示データ（readonly DTO） |
| `app/Services/RealEstate/ProcurementListService.php` | 新規 | フィルタ→マージ→ソート→ページング |
| `app/Http/Controllers/RealEstate/ProcurementController.php` | 変更 | `index()` をサービス呼び出しに置換 |
| `resources/views/realestate/procurements/index.blade.php` | 変更 | 列・フィルタ・ステータスセルの多型化 |

既存の `app/Services/Tenant/`（`RentalIncomeService` 等）と同じ配置・命名に合わせる。

### 4.2 `ProcurementListRow`（DTO）

Blade からモデルの差異（`property_name` / `project_name` など）を隠すための
readonly な値オブジェクト。Blade が `instanceof` で分岐せずに済む。

```php
final class ProcurementListRow
{
    public function __construct(
        public readonly string $kind,               // 'procurement' | 'project'
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
        public readonly ?int $soldLotCount,   // 分譲地のみ。仕入れ案件は null
        public readonly ?int $lotCount,       // 分譲地のみ。仕入れ案件は null
        public readonly string $showUrl,
        public readonly ?string $lotsUrl,     // 分譲地のみ。仕入れ案件は null
    ) {}

    public static function fromProcurement(ReProcurement $p): self { ... }
    public static function fromProject(ReProject $pj): self { ... }
}
```

- `$status` は enum インスタンスをそのまま持つ。Blade は `$row->status->label()` /
  `->badgeClass()` を呼ぶ。
  ⚠ **`tryFrom()` を通さない**（キャスト済み属性に `tryFrom()` を渡すと TypeError。Bug #22）
- 分譲地専用の値は `null` を入れて「無い」ことを型で表す
- ステータス選択肢と更新エンドポイントは行ごとに持たせず、`kind` をキーにした
  マップを Blade 側で1回だけ組む（後述 4.5）

### 4.3 `ProcurementListService`

```php
public function paginate(Request $request, int $perPage = 20): LengthAwarePaginator
```

**2段構えにする。** 全件をモデルとして読み込んでからマージすると、`costs` / `lots` の
イーガーロードが全行分走る。表示するのは1ページ 20 行なので、
**キーだけでソートしてから、そのページ分だけモデルを読む。**

#### 第1段: 並び順キーの構築

両テーブルからフィルタ適用済みの `id` と `info_obtained_date` だけを引く。

`procurementQuery()` / `projectQuery()` は **`?Builder` を返す**。
その種別が該当し得ないフィルタのときは **`null`** を返し、呼び出し側で空コレクションに落とす
（`whereRaw('1 = 0')` のような打ち消し条件を組むより、該当なしであることが型で読める）。

```php
$procKeys = $this->keysFrom($this->procurementQuery($request), 'procurement');
$projKeys = $this->keysFrom($this->projectQuery($request), 'project');

// private function keysFrom(?Builder $query, string $kind): Collection
// {
//     if ($query === null) {
//         return collect();                       // 該当なし（base collection）
//     }
//     return $query->get(['id', 'info_obtained_date'])
//         ->map(fn ($r) => ['kind' => $kind, 'id' => $r->id, 'date' => $r->info_obtained_date])
//         ->toBase();                             // ← 必須（下記 ⚠）
// }

$keys = $procKeys->merge($projKeys)->sortByDesc(fn ($k) => [
    $k['date'] === null ? 0 : 1,          // NULL を末尾へ
    $k['date']?->getTimestamp() ?? 0,     // 情報入手日 降順
    $k['id'],                             // id 降順
    $k['kind'] === 'procurement' ? 1 : 0, // 完全同着時の確定タイブレーク
])->values();
```

> ⚠ **`->toBase()` は必須。省くと「片方が0件」のときだけ 500 になる（Bug #27 と同型）。**
>
> `Eloquent\Collection::map()` は
> `$result->contains(fn ($item) => ! $item instanceof Model) ? $result->toBase() : $result`
> で base 化を判定する（`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Collection.php:423`、
> Laravel 12.55.0 で実測確認）。**空コレクションでは `contains()` が false を返すため
> base に落ちず `Eloquent\Collection` のまま残る。**
> その状態で配列を要素に持つコレクションを `merge()` すると、
> `EloquentCollection::merge()` が引数側の全要素に `getKey()` を呼び
> `Call to a member function getKey() on array` で落ちる。
>
> 分譲地が0件・仕入れ案件が0件はどちらも**実際に起こる**（新しい絞り込み条件では特に）。
> 回帰テストで固定する（6.1）。

#### 第2段: ページ分のモデルを読む

```php
$page  = LengthAwarePaginator::resolveCurrentPage();
$slice = $keys->forPage($page, $perPage);

$procs = ReProcurement::with('costs')
    ->whereIn('id', $slice->where('kind', 'procurement')->pluck('id'))->get()->keyBy('id');
$projs = ReProject::with('costs', 'lots')
    ->whereIn('id', $slice->where('kind', 'project')->pluck('id'))->get()->keyBy('id');

$rows = $slice->map(fn ($k) => $k['kind'] === 'procurement'
    ? ProcurementListRow::fromProcurement($procs[$k['id']])
    : ProcurementListRow::fromProject($projs[$k['id']]))->values();

return new LengthAwarePaginator($rows, $keys->count(), $perPage, $page, [
    'path'  => Paginator::resolveCurrentPath(),
    'query' => $request->query(),          // ← withQueryString() 相当。フィルタ保持
]);
```

- `costs` は `getExpectedProfit()` が、`lots` は区画数が使う。
  イーガーロードしないと N+1 になる
- `'query' => $request->query()` を渡さないとページ送りでフィルタが飛ぶ

**スケール特性**: 第1段は2テーブルの id スキャン（フィルタ後の全行）、
第2段は最大20行分のモデル読み込み。行数が数千を超えるようなら SQL `UNION` へ
移す余地があるが、現在の規模（本番でも数十〜百程度）では過剰。

#### フィルタの適用（`procurementQuery` / `projectQuery`）

3.3 の表のとおり。「その種別が該当し得ない」条件のときは、
クエリを組まずに**空を返す**（`$query->whereRaw('1 = 0')` ではなく、
サービス内で早期に空コレクションを返す方が意図が明確）。

| 条件 | 仕入れ案件 | 分譲地 |
|---|---|---|
| `property_type` = `'project'` | **空** | 全件（他条件は適用） |
| `property_type` = enum 値 | `where('property_type', $v)` | **空** |
| `transaction_type` が `filled()` | `where('transaction_type', $v)` | **空** |

### 4.4 コントローラ

```php
public function index(Request $request, ProcurementListService $listService)
{
    $rows = $listService->paginate($request);

    // ステータスポップオーバー用の選択肢を種別ごとに組む（4.5）。
    // 配列リテラルを Blade の @json() に直接書かず、ここで組んで変数1本で渡す（Bug #26）
    $statusOptionsByKind = [
        'procurement' => $this->statusOptions(ProcurementStatus::cases()),
        'project'     => $this->statusOptions(ProjectStatus::cases()),
    ];

    return view('realestate.procurements.index', compact('rows', 'statusOptionsByKind'));
}

// private function statusOptions(array $cases): array
//   → [['value' => ..., 'label' => ..., 'badge_class' => ...], ...]
```

既存の `$procurements` は `$rows` に置き換える。
現在 Blade の `@php` ブロックで組んでいる `$statusOptions` は**コントローラへ移す**。

### 4.5 Blade — ステータスセルの多型化

現行の `procurementStatusCell()` は更新先 URL と選択肢を仕入れ案件に決め打ちしている。
`kind` を受け取って引く形に変える。

```blade
{{-- ⚠ @json を x-data 属性の中に入れない（Bug #23）。<script> 側で組む --}}
<td x-data="realestateStatusCell('{{ $row->kind }}', {{ $row->id }},
        '{{ $row->status->value }}', '{{ $row->status->label() }}', '{{ $row->status->badgeClass() }}')">
```

```html
<script>
window.__reStatusOptions = @json($statusOptionsByKind);   // {procurement: [...], project: [...]}
window.__reStatusEndpoint = {
    procurement: '{{ url("/realestate/procurements") }}',
    project:     '{{ url("/realestate/projects") }}'
};

function realestateStatusCell(kind, id, value, label, badgeClass) {
    return {
        kind: kind,
        options: (window.__reStatusOptions || {})[kind] || [],
        // ... 以下 toggle/select は現行と同一。fetch 先だけ
        //     window.__reStatusEndpoint[this.kind] + '/' + this.id + '/status'
    };
}
</script>
```

- `$statusOptionsByKind` は**コントローラで組んで**Blade へ渡す
  （Blade の `@php` ブロックで組んでもよいが、配列リテラルを `@json()` に
  直接書かない。多行配列＋メソッド呼び出しは Blade の引数パーサを壊す。Bug #26）
- 両エンドポイントとも `{success, status: {value, label, badge_class}}` の
  **同一 JSON 形状**を返すことを確認済み
  （`ProcurementController::updateStatus` / `ProjectController::updateStatus`）
- 権限も対称（どちらも `role:executive,manager`）。`$canEditStatus` の判定は現行のまま使える

### 4.6 Blade — バッジ CSS

現行の `index.blade.php` の `<style>` には `badge-re-*`（9種）しか無い。
**`badge-prj-*`（8種）を追加する**（分譲地一覧の `<style>` からそのまま持ってくる）。
これを忘れると分譲地のステータスバッジが**無色で描画される**。

```css
.badge-prj-info { background: #dbeafe; color: #1e40af; }
.badge-prj-assess { background: #fce7f3; color: #9d174d; }
.badge-prj-negotiate { background: #fed7aa; color: #9a3412; }
.badge-prj-contracted { background: #fef3c7; color: #92400e; }
.badge-prj-settled { background: #a7f3d0; color: #064e3b; }
.badge-prj-selling { background: #c7d2fe; color: #3730a3; }
.badge-prj-soldout { background: #86efac; color: #14532d; }
.badge-prj-lost { background: #e5e7eb; color: #374151; }
```

---

## 5. 認可

**追加の認可制御は不要。** 両一覧のルートは同一のミドルウェア構成になっている。

| | 一覧 | ステータス更新 |
|---|---|---|
| `/realestate/procurements` | `department.access:realestate` | `role:executive,manager` |
| `/realestate/projects` | `department.access:realestate` | `role:executive,manager` |

仕入れ案件一覧を見られるユーザーは、分譲地一覧も既に見られる。
分譲地を混ぜても新たに露出する情報は無い。

---

## 6. テスト

`tests/Feature/RealEstate/ProcurementListWithProjectsTest.php` を新設する。

### 6.1 空ケース（Bug #27 の回帰。最優先）

| テスト | 内容 |
|---|---|
| `test_projects_only_does_not_500` | 仕入れ案件0件・分譲地のみで 200 |
| `test_procurements_only_does_not_500` | 分譲地0件・仕入れ案件のみで 200 |
| `test_both_empty_does_not_500` | 両方0件で 200＋空表示メッセージ |

> ⚠ **空ケースを必ず入れる。** この不具合は「片方が0件のときだけ」発火し、
> 両方にデータがあるローカル・本番では素通りする（Bug #22 / #25 / #26 / #27 と同型）。

### 6.2 フィルタ

| テスト | 内容 |
|---|---|
| `test_property_type_project_shows_only_projects` | `?property_type=project` で分譲地のみ |
| `test_property_type_enum_excludes_projects` | `?property_type=used_house` で分譲地が消える |
| `test_transaction_type_excludes_projects` | `?transaction_type=purchase` で分譲地が消える |
| `test_status_sold_matches_both_sold_and_sold_out` | `?status=sold` で仕入れ `sold` と分譲地 `sold_out` の両方 |
| `test_status_site_survey_excludes_projects` | `?status=site_survey` で分譲地が消える |
| `test_default_active_excludes_closed_of_both` | 既定で `lost` / `sold` / `sold_out` が消える |
| `test_status_all_includes_closed_of_both` | `?status=` で終了状態も出る |
| `test_keyword_searches_both_tables` | 分譲地の `project_code` / 仕入れ案件の `procurement_code` の両方でヒット |

> ⚠ `?status=` （全て）の検証では **`assertSee()` に頼らない**。
> 導入文などに同じ文字列が含まれて false-pass する。行の実データ（案件名）で判定する。

### 6.3 表示・ページング

| テスト | 内容 |
|---|---|
| `test_project_row_shows_lot_counts` | 分譲地の行に「区画 n / m」が出る |
| `test_project_with_zero_lots_shows_zero` | 区画0件の分譲地で「区画 0 / 0」＋区画ボタンが出る |
| `test_procurement_row_has_no_lot_subline` | 仕入れ案件の行に区画サブ行が無い |
| `test_pagination_spans_both_types` | 合計21件で2ページに割れ、1ページ目20件・2ページ目1件 |
| `test_pagination_keeps_filters` | 2ページ目のリンクにフィルタのクエリが載る |
| `test_sorted_by_info_obtained_date_desc` | 両種別が混ざった状態で日付降順（NULL 末尾） |

### 6.4 テスト環境の注意

- 実行は **main repo** で `composer install`（dev込み）→ `vendor/bin/phpunit`
  （worktree には vendor が無い。`artisan test` も `pest` も無い）
- `re_projects` / `re_project_lots` / `re_procurements` の migration が
  SQLite テスト DB に存在することを確認する。raw SQL でカラムを足していて
  migration が追随していない場合、テストだけ落ちる（migration drift）

---

## 7. 変更しないもの

| 対象 | 理由 |
|---|---|
| `/realestate/projects`（分譲地一覧ページ） | 分譲地だけを見たいときの導線。フィルタも独自 |
| サイドバーの「分譲地」項目 | 同上 |
| 分譲地一覧の詳細ボタンの色（琥珀） | その画面の意匠は既存のまま |
| `RealEstatePropertyType` enum | 3.3 の ⚠ のとおり。登録フォームを汚染するため |
| `ProjectController` / `ReProject` / `ReProcurement` | 既存メソッドで足りる |
| ステータス更新 API（両方） | 形状も権限も既に対称 |
| DB スキーマ | 追加カラム不要 |

---

## 8. デプロイ

1. worktree で `/commit`
2. main repo で `git checkout 13.x && git merge --ff-only feature/procurement-list-projects`
3. **新規 PHP クラスを2本追加するため**、main repo の cwd で `composer dump-autoload`
   （worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれる）
4. `./deploy.sh`
5. 本番ブラウザで確認（下記）

### 本番での確認項目

- 分譲地の行が出る／ステータスバッジに**色が付いている**（4.6 を忘れていないか）
- 分譲地のバッジをクリック → ステータスが実際に変わる
  （⚠ HTML に出ているかだけでは不十分。Bug #28 と同型で、
  スクリプトが一度も実行されていないケースを取り逃す）
- 仕入れ案件のバッジクリックが従来どおり動く（エンドポイント切り替えの回帰）
- 物件種別セレクトで「分譲地」を選ぶ → 分譲地だけになる
- ページ送りしてもフィルタが保持される
- 横スクロールが出ていないか **DOM で実測**する
  （`main.scrollWidth === main.clientWidth`。スクリーンショットでは判定できない。Bug #29）
