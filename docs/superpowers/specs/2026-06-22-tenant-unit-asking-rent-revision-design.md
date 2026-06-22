# 区画 募集家賃の改定（履歴付き）設計書

- 作成日: 2026-06-22
- 対象: テナント区画の **募集家賃を「賃料改定」として履歴付きで変更** できるようにし、空室・商談中でも改定可能にする。区画詳細で「区画の家賃推移（募集＋契約）」を確認できるようにする。
- 種別: 機能追加（**新規テーブル追加＝スキーマ変更あり**）。既存の契約改定（`rent_revisions` / `ContractController::revise`）は**一切変更しない**。
- 前提: [2026-06-22-tenant-unit-rent-revision-design.md](2026-06-22-tenant-unit-rent-revision-design.md)（区画詳細→契約改定の入口追加・本番稼働中）の後続。

## 1. 背景・問題

家賃改定の履歴 `rent_revisions` は **`contract_id`（NOT NULL）に紐づく＝契約専用**。空室区画の募集家賃は `units.rent / common_fee / garbage_fee / pest_control_fee` にあり、現状は「編集」で書き換えるだけで**履歴が残らない**。
→ 空室・商談中でも募集家賃を改定として履歴付きで変更し、区画詳細でその区画の家賃推移を一望できるようにする。

## 2. 決定事項（要件Q&Aで確定）

1. **改定項目**: 家賃・共益費・ゴミ代・駆除代の **4項目**（契約改定と同一。敷金は対象外）。
2. **履歴の範囲**: 区画詳細に「賃料改定履歴」タブを新設し、**この区画の募集家賃改定＋この区画の全契約の契約家賃改定を統合**して時系列表示。
3. **ボタン表示**: **空室・商談中**で「賃料改定」ボタンを表示。**入居中は従来の契約改定**（前回実装）のまま。
4. **編集画面の金額ロック**: 区画「編集」画面では4項目を**変更不可（表示のみ＋「変更は賃料改定から」注記）**。金額変更は必ず改定フロー経由。**新規登録時は初期値として入力可**。
5. **データモデル**: 既存 `rent_revisions` を触らず、**新規テーブル `unit_rent_revisions`（追加のみ）** に記録（A案）。統合表示は2ソースをマージで実現。

## 3. 設計

### 3.1 スキーマ（追加のみ）

新規テーブル `unit_rent_revisions`（`rent_revisions` のミラー、`contract_id` の代わりに `unit_id`）:

| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| unit_id | bigint FK→units | restrictOnDelete・index |
| revision_date | date | 改定適用日 |
| old_rent / new_rent | int | 必須 |
| old_common_fee / new_common_fee | int nullable | |
| old_garbage_fee / new_garbage_fee | int nullable | |
| old_pest_control_fee / new_pest_control_fee | int nullable | |
| reason | text nullable | 改定理由 |
| revised_by | bigint FK→users | restrictOnDelete |
| created_at | timestamp useCurrent | `UPDATED_AT = null` |

- **Laravel migration**（テスト用・RefreshDatabase で利用）: `database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php`。
- **本番適用 raw SQL**: `database/sql/2026-06-22-create-unit-rent-revisions.sql`（`CREATE TABLE` のみ・追加のみで安全）。本番は `sudo mysql manage < database/sql/2026-06-22-create-unit-rent-revisions.sql` で適用（CLAUDE.md のスキーマ運用）。`deploy.sh` は `database/` を rsync 除外しないため、デプロイで本番にファイルが届く→別途SQL実行。

### 3.2 モデル

- `app/Models/UnitRentRevision.php`（`RentRevision` のミラー）: `const UPDATED_AT = null;`、`$fillable`（unit_id, revision_date, old/new × 4, reason, revised_by）、`casts`（revision_date=date, 各金額=integer）、リレーション `unit()`（BelongsTo Unit）/ `revisedByUser()`（BelongsTo User, 'revised_by'）。
- `app/Models/Unit.php` に `rentRevisions(): HasMany`（→ UnitRentRevision）を追加。

### 3.3 ルート / コントローラ（`role:executive`）

`routes/web.php` のテナント区画ルート群（`role:executive` グループ）に2本追加:
- `GET  tenant/units/{unit}/revise` → `UnitController::showReviseRent`（name: `tenant.units.revise`）
- `POST tenant/units/{unit}/revise` → `UnitController::reviseRent`（name: `tenant.units.revise.execute`）

`UnitController`:
- **`showReviseRent(Unit $unit)`**: ステータスが入居中なら区画詳細へリダイレクト（`with('error', '入居中の区画は契約から賃料改定してください。')`）。それ以外は `tenant.units.revise`（新ビュー）を表示（`$unit` と現在の募集条件を渡す）。
- **`reviseRent(Request $request, Unit $unit)`**: 入居中ガード（同上）。バリデーション（`revision_date` required date / `new_rent` required int min:0 / `new_common_fee`・`new_garbage_fee`・`new_pest_control_fee` nullable int min:0 / `reason` nullable string max:5000）。`DB::transaction` で `UnitRentRevision::create`（old=現在の `units.*`、new=入力値、revised_by=Auth::id）＋ `units.rent/common_fee/garbage_fee/pest_control_fee` を更新。→ `tenant.units.show` へ `with('success', "区画「{$unit->display_name}」の募集家賃を改定しました。")`。
- **`show(Unit $unit)` を拡張**: 統合履歴 `$rentHistory` を構築（§3.5）してビューへ渡す。
- **`update()` を変更（金額ロック）**: バリデーションと更新対象から `rent / common_fee / garbage_fee / pest_control_fee` を**除外**（編集では金額を変えない＝現値を保持）。`deposit` は従来どおり編集可。`store()`（新規）は4項目を従来どおり保存（初期値）。

### 3.4 ビュー

- **新規 `resources/views/tenant/units/revise.blade.php`**: パンくず（テナント管理 › {物件} › 区画:{display_name} › 賃料改定）、区画詳細への戻る/キャンセル、対象区画カード、現在の募集条件カード（4項目）、改定フォーム（改定適用日* / 新・募集家賃* / 新・共益費 / 新・ゴミ代 / 新・駆除代 / 改定理由）。契約版 `contracts/revise.blade.php` の体裁を踏襲。
- **`resources/views/tenant/units/show.blade.php` 変更**:
  - 「募集条件」カードのタイトル行を flex 化し、**右に「賃料改定」ボタン**（`@if(($unit->status===Vacant || $unit->status===Negotiating) && auth()->user()->role->isExecutive())` → `route('tenant.units.revise', $unit)`）。入居中の「現在の契約条件」カードの契約改定ボタン（前回実装）はそのまま。
  - タブに **「賃料改定履歴」を追加**（現在の契約 / 収支履歴 / 修繕履歴 に並べて）。`$rentHistory` を契約詳細の改定履歴と同じ列構成＋**「区分」列**で表示。
- **`resources/views/tenant/units/edit.blade.php` 変更**: 募集家賃・共益費・ゴミ代・駆除代の4 input を**読み取り専用の表示**（値＋「変更は賃料改定から」注記）に置換し、`name` 送信をやめる。敷金（deposit）は従来どおり編集可。

### 3.5 統合履歴（区画の家賃推移）

`UnitController::show` で次をマージして降順に整列し `$rentHistory`（配列）を作る:
- この区画の `unit_rent_revisions`（`$unit->rentRevisions`、区分=**募集**）
- この区画の全契約（解約済み含む）の `rent_revisions`（`$unit->contracts` → `rentRevisions`、区分=**契約（{contract_number} / {テナント名}）**）

各行を共通形に正規化: `revision_date` / `kind`('asking'|'contract') / `context_label` / `old_*`,`new_*`（4項目）/ `revised_by_name`。`revision_date` 降順（同日は `created_at` 降順）でソート。ビューは契約改定履歴と同じ列＋「区分」列で表示（空なら「賃料改定の履歴はありません。」）。

## 4. 安全性 / エッジケース

- `reviseRent` / `showReviseRent` は**入居中ガード**（入居中は契約改定へ誘導）。ルートは `role:executive`、ボタンは `isExecutive()` の二重認可。
- スキーマは**追加のみ**（`CREATE TABLE`）。既存 `rent_revisions` / 契約改定フロー / 既存データに影響なし。バックフィル不要。
- ルートモデル束縛で `unit` を解決（ユーザー入力ID不使用＝IDOR無し）。
- 編集の金額ロックは update から4項目を除外して**現値保持**（disabled input が未送信→0埋めされる事故を防ぐため、update 側でも明示的に対象外にする）。
- 統合履歴の契約改定は `unit->contracts`（解約済み含む）経由で取得。契約が無い区画は募集分のみ。

## 5. 非対象（YAGNI）

- 改定履歴の編集・削除。
- 敷金の改定（対象は月額4項目のみ）。
- 入居中区画の募集家賃改定（入居中は契約改定を使用）。
- 既存 `rent_revisions` テーブル・契約改定フローの変更。

## 6. テスト方針（main repo で `vendor/bin/phpunit`）

新規 `tests/Feature/Tenant/UnitRentRevisionTest.php`:
- 空室区画で改定実行 → `unit_rent_revisions` に1件作成（old=旧値・new=新値）＋ `units.*` 更新 ＋ `tenant.units.show` へリダイレクト。
- 入居中区画で `tenant.units.revise`(GET/POST) → 区画詳細へリダイレクト（改定不可ガード）。
- 非経営層（manager）が `tenant.units.revise` にアクセス → 403。
- `UnitController::update`（編集）に金額を含めて送信しても **`units.*` の4項目が変わらない**こと（ロックの担保）。`deposit` は更新できること。
- 統合履歴: 区画に募集改定＋契約改定の両方がある場合、`show` の `$rentHistory` に両方が日付降順で含まれる（区分ラベル付き）。
- 静的検証: 変更/新規 Blade を `view:cache` 後にコンパイル `php -l`（Bug #26 ガード）。

## 7. 変更/新規ファイル一覧

| 区分 | パス | 内容 |
|---|---|---|
| 新規(migration) | `database/migrations/2026_06_22_000001_create_unit_rent_revisions_table.php` | テスト用スキーマ |
| 新規(SQL) | `database/sql/2026-06-22-create-unit-rent-revisions.sql` | 本番適用用 raw SQL |
| 新規(model) | `app/Models/UnitRentRevision.php` | 募集家賃改定履歴 |
| 変更(model) | `app/Models/Unit.php` | `rentRevisions()` 追加 |
| 変更(controller) | `app/Http/Controllers/Tenant/UnitController.php` | `showReviseRent`/`reviseRent` 追加・`show` 拡張・`update` 金額ロック |
| 変更(routes) | `routes/web.php` | `tenant.units.revise`(GET/POST) を `role:executive` に追加 |
| 新規(view) | `resources/views/tenant/units/revise.blade.php` | 募集家賃改定フォーム |
| 変更(view) | `resources/views/tenant/units/show.blade.php` | 募集条件カードにボタン＋「賃料改定履歴」タブ |
| 変更(view) | `resources/views/tenant/units/edit.blade.php` | 金額4項目を表示のみにロック |
| 新規(test) | `tests/Feature/Tenant/UnitRentRevisionTest.php` | 上記テスト |

## 8. 本番反映手順（概要）

1. コード: `./deploy.sh`（rsync＋`config/route/view:cache`）。
2. スキーマ: 本番で `sudo mysql manage < database/sql/2026-06-22-create-unit-rent-revisions.sql` を実行（追加のみで安全。実行はユーザー承認のもと）。
3. 新規 PHP クラス（`UnitRentRevision`）を追加するため、main repo の cwd で `composer dump-autoload`（worktree からは実行しない）。
4. Playwright 等で本番確認。
