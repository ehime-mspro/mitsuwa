# テナント 賃料収入履歴（STEP 7）設計書

- 作成日: 2026-06-10
- 対象: テナント管理 — 区画詳細「収支履歴」タブ／物件詳細「物件別収支」タブ
- ステータス: 設計承認済み（**収入のみ版**）
- ブランチ: `step7-rental-income-history`（HEAD: a2135bde から分岐）

## 1. 背景・目的

区画詳細・物件詳細にある「収支履歴」「物件別収支」タブは `STEP 7で実装` のプレースホルダのまま未実装だった。`transactions` テーブルは存在するが **0 行・完全未使用**（コード上の "Transaction" は全て `DB::beginTransaction()` で、モデル `App\Models\Transaction` はどこからも呼ばれていない）。

本機能では**新規データ入力を伴わず**、既存の契約データから **賃料収入の月次履歴** を自動集計して表示する。ユーザー判断により **支出（修繕費・投資費）は対象外**。

## 2. スコープ

### 対象
- 区画詳細（`resources/views/tenant/units/show.blade.php`）の「収支履歴」タブ
- 物件詳細（`resources/views/tenant/properties/show.blade.php`）の「物件別収支」タブ
- 月次の賃料収入サマリー（**収入のみ**）

### 非対象（YAGNI）
- 支出（修繕費・投資費）の集計・表示
- `transactions` テーブルの実装・手入力・CSV 取込（将来の実台帳化用に温存）
- 賃料改定（`rent_revisions`）の月次反映（現状 0 行・将来拡張）
- 新規ルート・DB スキーマ変更

## 3. データソースと算出ロジック

### 収入源
- `contracts` テーブルのみ（status: active＋terminated、soft-deleted は除外）
- 月額 = 賃料 `rent` ＋ 共益費 `common_fee` ＋ ゴミ代 `garbage_fee` ＋ 駆除代 `pest_control_fee`
  （= 既存 `Contract::getMonthlyTotalAttribute`）
- 敷金（`deposit`）は対象外

### 月次展開（契約ごと）
- **開始月** = `rent_start_date` の年月（null の場合は `contract_date` の年月）
- **終了月** = `min(contract_end_date ?? 当月, 当月)` の年月
  - 解約済み・期間満了で過去日があればその月で打ち切り
  - 契約中・終了日未来なら当月まで（**未来の収入は計上しない**）
- 開始月〜終了月の各月に月額を計上
- **初月調整**: `initial_month_type` が設定されていれば初月は `initial_month_amount` を採用（フリーレント=0 も反映）、未設定ならフル月額
- **最終月調整**: `final_month_type` が設定されていれば最終月は `final_month_amount` を採用、未設定ならフル月額
- 開始月＝終了月（単月）の場合は初月調整を優先

### 集計
- 全契約の月次収入を `accounting_ym`（`YYYY-MM`）で合算
- 各月の `cumulative`（累計収入）を古い月 → 新しい月の順で算出
- `total_income`（累計賃料収入）、`current_monthly`（status=active 契約の月額合計）を算出

### 区画 vs 物件
- `forUnit(Unit)`: `Contract::where('unit_id', $unit->id)`
- `forProperty(Property)`: `Contract::where('property_id', $property->id)`（配下全区画を含む）

## 4. アーキテクチャ

### 新規サービス
`app/Services/Tenant/RentalIncomeService.php`

```php
public function forUnit(Unit $unit): array
public function forProperty(Property $property): array
```

戻り値（共通フォーマット）:

```php
[
  'rows' => [
    ['ym' => '2026-05', 'income' => 285000, 'cumulative' => 3420000],
    ['ym' => '2026-04', 'income' => 285000, 'cumulative' => 3135000],
    // ... 新しい月が先頭
  ],
  'total_income'    => 3420000, // 累計賃料収入
  'current_monthly' => 285000,  // 現在の月額（active 契約合計）
]
```

- 月次展開ロジックは private ヘルパー（例: `expandContractMonths(Contract): array<string,int>` ＝ ym => amount）に集約し、`forUnit`/`forProperty` から共用
- コントローラは薄く保つ（サービス呼び出し → view へ渡すのみ）

### コントローラ変更
- `app/Http/Controllers/Tenant/UnitController@show`:
  `$rentalIncome = app(RentalIncomeService::class)->forUnit($unit);` を追加し `compact(... , 'rentalIncome')`
- `app/Http/Controllers/Tenant/PropertyController@show`:
  同様に `forProperty($property)` を `$rentalIncome` として追加

### ビュー
- 新規共通 partial `resources/views/tenant/partials/_rental-income.blade.php`（`$rentalIncome` を受け取る）
- `units/show.blade.php`（279 行）・`properties/show.blade.php`（510 行）のプレースホルダ `<p>...</p>` を
  `@include('tenant.partials._rental-income', ['rentalIncome' => $rentalIncome])` に置換

## 5. 表示仕様

- **上部カード 2 枚**: 「累計賃料収入」「現在の月額」
- **月次表**（新しい月が先頭）:

| 計上年月 | 賃料収入 | 累計 |
|---|--:|--:|
| 2026-05 | 285,000円 | 3,420,000円 |
| 2026-04 | 285,000円 | 3,135,000円 |

- 金額: 税抜・末尾「円」・`number_format`（既存コンベンション、`¥` 接頭辞 NG）
- 空状態: 「賃料収入の履歴がありません。」
- スタイル: 既存「修繕履歴」table の `scroll-hint` ラッパーを踏襲。Tailwind はビルド済みクラス＋ inline style（Bug #19）。任意値クラス（`min-w-[...]` 等）禁止
- タブ名「収支履歴」「物件別収支」は据え置き（中身は賃料収入。改称は将来検討）

## 6. テスト

`tests/Unit/Tenant/RentalIncomeServiceTest.php`（Pest）

- 契約中契約: `rent_start_date` 〜当月まで毎月計上、`cumulative` が正しい
- 解約済み契約: `contract_end_date` の月で打ち切り（未来月を含まない）
- 複数契約（テナント交代）: 月次合算が正しい
- フリーレント: `initial_month_type=free`, `initial_month_amount=0` の初月が 0 計上
- 契約なし区画: `rows` 空・`total_income=0`・`current_monthly=0`
- 物件合算: 複数区画の `forProperty` 合計が各 `forUnit` の和に一致

実行方針（既存メモリ準拠）:
- worktree では `vendor` が `--no-dev` symlink のため PHPUnit 不可 → 静的検証（`php -l` / `php artisan view:cache` / `route:list`）
- main repo へ FF-merge 後に `php vendor/bin/pest --filter=RentalIncome`

## 7. 影響範囲・リスク

- 読み取り専用・新規ルートなし・DB 変更なしのため副作用は最小
- N+1 回避: サービス内で contracts を一括 `get()`（単純 where、eager load 不要）
- パフォーマンス: 契約期間 × 契約数の月展開。現状契約 10 件・数十ヶ月規模で無視できる
- Blade コンパイル安全性: 本番 `view:cache` で全 Blade precompile を確認（Bug #21 対策）
- 既存タブ（現在の契約／修繕履歴／投資案件等）への影響なし（プレースホルダ置換のみ）

## 8. デプロイ手順

1. worktree で実装・静的検証（`php -l` / `view:cache` / `route:list`）
2. `/commit`（commit-commands）でコミット
3. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only step7-rental-income-history`
4. **新規クラス追加のため** main repo の cwd で `composer dump-autoload`
5. `./deploy.sh`（rsync + 本番 `config:cache && route:cache && view:cache`）
6. main repo で `php vendor/bin/pest --filter=RentalIncome`
7. （任意）本番で区画詳細・物件詳細の該当タブを目視確認

## 9. 確認済みの既定（ブレストで合意）

| # | 論点 | 既定 |
|---|---|---|
| A | フリーレント等の初月/最終月 | `initial_month_type`/`final_month_type` が設定済みならその月だけ該当 amount を採用、無ければフル月額 |
| D | 賃料改定 | 現状 0 件のため v1 は現行月額を全月適用（改定反映は将来拡張） |
| E | タブ名 | 「収支履歴」「物件別収支」のまま据え置き |
