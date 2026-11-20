# 土地（旧「仲介土地」）の区画登録と、分譲地への変更 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 物件種別「仲介土地」を「土地」と表記し、土地の仕入れ案件に区画を 1 つ持たせ（状態は契約・建売・注文住宅に合わせて動く）、紐付けの無い自社買取の土地を中身ごと分譲地に変えられるようにする。

**Architecture:** 新しい表 `re_procurement_lots`（1 案件 1 行）とモデル `ReProcurementLot`。坪数・坪単価は保存せず `AreaConverter`・`TsuboPrice` で毎回出す。区画の登録・編集・削除は詳細画面の中の欄からふつうのフォームで送る（`ProcurementLotController`）。状態の連動は `ReProcurementLot::markSoldFor()`／`markOnSaleFor()` を、分譲地の区画を動かしている 7 か所の隣で呼ぶ。分譲地に変える処理はサービス `ProcurementToProjectConverter`（判定 `refusal()` は画面のボタンとサーバが共用。Bug #41）。

**Tech Stack:** Laravel 12 / PHP 8.3 / MySQL 8（本番・raw SQL）/ SQLite（テスト）/ Blade + Alpine.js 3 / PHPUnit

**Spec:** `docs/superpowers/specs/2026-11-20-realestate-land-lot-design.md`（2026-11-20 に利用者が承認。§7 の「私の判断」も承認に含まれる）

## 設計書から変える・設計書に書き足すこと（計画で決めた。承認のとき利用者に見てもらう）

1. **変えたあとの文**（§5.5）: 設計書の「元の番号 RE-PRC-… は備考に残しました」は §6 で専用の列にした決定と食い違うので、`分譲地「{分譲地の番号}」に変えました（元の仕入れ案件番号 {元の番号} は分譲地の詳細に残しています）。` にする
2. **変えた分譲地のステータスは、区画の状態で集約する**（§5.5 の表の写しのあとで `ReProject::syncStatusFromLots()` を呼ぶ）。分譲地の区画の登録と同じ決まりで、区画が「成約」なら分譲地は「販売済」になる。紐付けのある土地は変えられないので、成約の区画は手で成約にしたときだけ
3. **区画の保存でも、販売価格と面積から求めた坪単価が列（符号付き INT）に入るかを見る**（Bug #91 と同じ文で断る）。坪単価は保存しないが、分譲地に変えるときに `re_project_lots.selling_price_per_tsubo` へ写すので、ここで断らないと変える操作が 500 になる。分譲地の区画の登録の確かめ（`ProjectController::assertPerTsuboFits()`）を基底の `Controller` へ移して共用する
4. **仕入れ案件を消すとき、区画もコードで消す**（本番は外部キーの CASCADE だが、テスト用スキーマは外部キーを張らないので、コードに書かないとテストで確かめられない。Bug #81 の形）
5. **区画の欄の「土地でない」「2 つ目」「区画が無いのに更新・削除」は、入力エラーではなく帯の理由で詳細へ戻す**（どれも古い画面か手で組んだ送信だけで起きる）
6. **分譲地に変える途中のデータベースのエラー**は、何も変えずに詳細へ戻し、帯に理由を出して `report()` で記録する（Bug #89 と同じ形）
7. **「分譲地に変える」の二度押し**: 2 回目は仕入れ案件がもう無いので、404 ではなく一覧へ戻して理由を出す（ルートの `missing()`）
8. **一般担当**は区画の欄を見るだけ（登録・編集・分譲地に変えるは経営層・管理者、削除は経営層。§7）

## Global Constraints

- 値 `brokerage_land` は変えない。「仕入れ土地販売」「仲介」（契約種別・取引種別）の表記は変えない
- 坪数は `AreaConverter::sqmToTsubo()`、坪単価は `TsuboPrice`（丸めは 1 回だけ）。保存した坪数・坪単価から表示しない（Bug #33・#34）
- 入力の上限は分譲地の区画の登録（`ProjectController::storeLot`）と同じ: `lot_number` `required|integer|min:1|max:` + `MAX_INT_COLUMN`・`area_sqm` `required|numeric|min:0.01|max:99999999.99`・`selling_price` `nullable|integer|min:0|max:` + `MAX_INT_COLUMN`・`status` `required|in:`（`LotStatus` の 4 つ）・`notes` `nullable|string|max:200`
- 権限: 区画の登録・編集と分譲地に変えるは `role:executive,manager`、区画の削除は `role:executive`
- 本番は **DB が先・`./deploy.sh` が後**。新しい PHP クラスがあるので main repo の cwd で `composer dump-autoload --no-dev --optimize`
- 文言（コピーは逐語）:
  - 表記: `土地`
  - 区画の登録・更新・削除: `区画を登録しました。` / `区画を更新しました。` / `区画を削除しました。`
  - 2 つ目: `区画は 1 つまでです。2 つ目を足すときは「分譲地に変える」を使ってください。`
  - 土地でない: `区画を登録できるのは、物件種別が土地の仕入れ案件だけです。`
  - 区画が無い（更新・削除）: `区画は登録されていません（ほかの画面で削除された可能性があります）。`
  - 坪単価: `販売価格と面積から求めた坪単価が大きすぎます。面積を確かめてください。`（今の分譲地の文と同じ）
  - 区画の削除の確認: `区画を削除しますか？ 登録した面積・販売価格などが消えます（契約・建売・注文住宅の紐付けは消えません）。`
  - 物件種別を変える保存の断り: `区画が登録されているため、物件種別を変えられません。先に区画を削除してください。`
  - 変えられない: 土地でない `物件種別が土地の仕入れ案件だけを分譲地に変えられます。` ／ 仲介 `取引種別が仲介の土地は分譲地に変えられません（分譲地に変えられるのは自社買取の土地だけです）。` ／ 紐付け `{件数}が紐づいているため、分譲地に変えられません。`（`{件数}` は `DeletionBlockers::summarize()` と同じ形の `契約 1 件・建売物件 2 件`）
  - 変える前の確認: `分譲地に変えます。案件番号は分譲地の番号（RE-PRJ-…）に変わり、元の仕入れ案件は消えます（原価・区画・工程・添付は分譲地へ移ります）。元には戻せません。よろしいですか？`
  - 変えたあと: `分譲地「{分譲地の番号}」に変えました（元の仕入れ案件番号 {元の番号} は分譲地の詳細に残しています）。`
  - 途中の失敗: `分譲地に変えられませんでした。何も変えていません。時間をおいてやり直してください。`
  - 二度押しの 2 回目: `この仕入れ案件はもうありません（分譲地に変えたか、削除された可能性があります）。`
  - 分譲地の詳細の見出し: `元の仕入れ案件番号`
  - 仕入れ先: 見出し `関連する分譲地` ／ 削除の断り `この仕入れ先は仕入れ案件・分譲地で使用されているため削除できません。`
- テストの流し方は CLAUDE.md の「テストの流し方」。以下 `<テスト>` と書く: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations`
- データプロバイダは `#[DataProvider('…')]` の属性で書く（doc コメントの `@dataProvider` は非推奨の警告になる）。`git stash` は使わない（共有）
- 画面のテストは描いた画面のフォームを分解して送る（Bug #47。`parseForm()`・`fill()`・`submit()`・`landed()`・`assertFlash()`・`assertErrorItem()`）。入力エラーの期待文は `trans('validation.…', […])` で組む（Bug #49）
- コミットの末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. 土地面積・想定販売価格（土地）が**空**の土地で区画を開く → 欄は空で開き、面積を入れずに送ると「必須」で断られ、入れた値は残る → Task 3 `test_an_empty_land_area_opens_an_empty_field_and_is_required`
2. **小さな面積**（0.04㎡ ＝ 0.01 坪）と高い販売価格の区画 → 保存の時点で坪単価の文で断る（分譲地に変える操作が 500 にならない）→ Task 3 `test_a_tsubo_price_too_large_for_the_column_is_refused`
3. 区画のある土地の仕入れ案件の編集画面を**何も変えずに**保存 → 通る（物件種別の断りは種別を変えたときだけ）→ Task 3 `test_the_property_type_cannot_leave_land_while_a_lot_exists`
4. 査定・購入価格が**空**で、物件購入費の行を手で直してある土地を分譲地に変える → 行の金額も備考もそのまま移る（自動の同期に任せない）→ Task 6 `test_a_land_without_prices_keeps_its_purchase_cost_row`
5. 分譲地に変えた**直後に新しい仕入れ案件**を登録 → 元の番号を使い回さない → Task 6 `test_the_original_code_is_not_reused`

---

### Task 0: 準備と本番の読み取り（親の会話が行う。サブエージェントに渡さない）

- [ ] **Step 1: 作業場所の vendor を整える** — 作業場所の cwd で `cp -a /Users/masanori/site/manage/vendor ./vendor && composer install --no-scripts --no-interaction`
- [ ] **Step 2: 着手前の全件** — `<テスト>` → `OK (5389 tests, …)` 前後（Bug #144 の記録と同じ本数）であることを記録する
- [ ] **Step 3: 本番の読み取り（利用者の承認を取ってから。`ssh … /bin/sh < 手順ファイル` で `artisan tinker --execute`・php は 8.3 を名指し）** — 読むもの: `SHOW CREATE TABLE` の `re_procurements`・`re_projects`・`re_project_lots`（`re_procurements.id` の型・`procurement_code` / `project_code` の長さ・`re_project_lots.status` の型・照合順序）／件数だけ: 物件種別×取引種別ごとの仕入れ案件の数・土地のうち契約・建売・注文住宅の紐付けがある数・`MAX(procurement_code)`／`laravel.log` の日時（Nov 18 01:45 のまま）
- [ ] **Step 4: 記録** — 結果を末尾の「実測記録」に書く。型が §6 と違えば Task 2 の SQL をそれに合わせ、ここに理由を書く

---

### Task 1: 表記「仲介土地」→「土地」

**Files:**
- Modify: `app/Enums/RealEstatePropertyType.php`（`BrokerageLand` の label）
- Modify: `resources/views/realestate/procurements/_form.blade.php`（注記 3 か所・JS のコメント 1 か所）・`_price_cell.blade.php`・`_price_row.blade.php`・`resources/views/realestate/contracts/create.blade.php`（コメント）・`app/Models/ReProcurement.php`（コメント 2 か所）
- Modify: `tests/Feature/RealEstate/ContractAmountBreakdownTest.php`・`ProcurementPriceBreakdownTest.php`（注記だけ）
- Test: `tests/Feature/RealEstate/LandLabelTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_the_land_type_is_labelled_land(): void
// RealEstatePropertyType::BrokerageLand->label() === '土地'

public function test_the_registration_screen_offers_land(): void
// 経営層で /realestate/procurements/create を開き、<option value="brokerage_land">土地</option> がある（空白は \s* で許す）

public function test_no_code_or_view_says_the_old_label(): void
// app/・resources/views/・tests/ の *.php を走査し、'仲介' . '土地'（このファイル自身に当たらないよう連結で作る）を含むファイルが 0
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/LandLabelTest.php` → 3 本 FAIL
- [ ] **Step 3: 表記を直す**（上の Files のすべて。注記は `※ 物件種別が「土地」の場合は不要`。コメントは意味を変えずに「土地」へ）
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS、`<テスト> tests/Feature/RealEstate` も PASS
- [ ] **Step 5: コミット** — `feat: 物件種別「仲介土地」の表記を「土地」にする`

---

### Task 2: 土地の区画の表・モデル・判定の部品

**Files:**
- Create: `database/sql/2026-11-20-create-re-procurement-lots.sql`
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`（`re_procurement_lots` を足す・`re_projects` に `source_procurement_code`）
- Create: `app/Models/ReProcurementLot.php`
- Modify: `app/Models/ReProcurement.php`（`lot()`・`defaultLotStatus()`）・`app/Models/ReProject.php`（`$fillable` に `source_procurement_code`）
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php`（`destroy()` で区画も消す）
- Modify: `tests/Feature/RealEstate/Screens/RealEstateScreenTestCase.php`（土台の部品を 2 つ）
- Test: `tests/Feature/RealEstate/Screens/ProcurementLotModelTest.php`（新規）

**Interfaces:**
- Produces:
  - 表 `re_procurement_lots`（id・procurement_id 一意・lot_number・area_sqm・selling_price・status・notes・timestamps）と `re_projects.source_procurement_code`（varchar(20)・NULL 可・一意）
  - `App\Models\ReProcurementLot`（`$table = 're_procurement_lots'`・fillable は表の列・casts `area_sqm` decimal:2 / `selling_price` integer / `status` `LotStatus`）
    - `procurement(): BelongsTo`
    - `getAreaTsubo(): float` — `AreaConverter::sqmToTsubo($this->area_sqm)`
    - `areaLabel(): string` — `132.69㎡（40.13坪）`（面積は `number_format(…, 2)`・坪数も 2 桁）
    - `getSellingPricePerTsuboFormatted(): ?string` — 販売価格が null か 0 以下なら null。ほかは `TsuboPrice::perTsuboManLabel($price, $this->getAreaTsubo())`
    - `summaryLabel(): string` — `1号地 132.69㎡（40.13坪）`、販売価格があれば後ろに ` 販売価格 25,000,000円`
    - `static markSoldFor(?int $procurementId): void` ／ `static markOnSaleFor(?int $procurementId): void` — null なら何もしない。その仕入れ案件の区画だけを `LotStatus::Sold` ／ `OnSale` にする（クエリビルダの update。区画が無ければ何も起きない）
  - `ReProcurement::lot(): HasOne`（`procurement_id`）
  - `ReProcurement::defaultLotStatus(): LotStatus` — 契約がある・`re_procurement_id` がこの案件の建売に契約がある・この案件の注文住宅のステータスが `isContractedOrLater()` のどれかなら `Sold`、それ以外は `OnSale`
  - テストの土台: `landProcurement(array $overrides = []): ReProcurement`（`brokerage_land`・`purchase`・土地面積 132.69・想定販売価格（土地）25,000,000・`RE-PRC-001`）／ `procurementLot(ReProcurement $p, array $overrides = []): ReProcurementLot`（1 号地・132.69・25,000,000・`on_sale`）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_the_test_schema_matches_the_production_sql(): void
// SQL ファイルの CREATE TABLE `re_procurement_lots` の列名（/^\s*`(\w+)`\s/m）＝ Schema::getColumnListing('re_procurement_lots')。
// テスト用スキーマに procurement_id の一意の索引がある（Schema::getIndexes）。re_projects に source_procurement_code の列と一意の索引がある

public function test_area_and_tsubo_price_labels(): void
// 132.69㎡・25,000,000 → getAreaTsubo() === 40.13・areaLabel() === '132.69㎡（40.13坪）'・
//   getSellingPricePerTsuboFormatted() === '@62.3'・summaryLabel() === '1号地 132.69㎡（40.13坪） 販売価格 25,000,000円'
// 66.15㎡・19,990,000 → '@100.0'（Bug #34 の罠①: 円で丸めてから万円にすると '@99.9'）
// 販売価格 null → per-tsubo null・summaryLabel() === '1号地 132.69㎡（40.13坪）'

public function test_the_default_status_follows_existing_links(): void
// 紐付けなし OnSale ／ 契約 Sold ／ 契約の無い建売 OnSale ／ 契約のある建売 Sold ／
// 注文住宅 estimation OnSale ／ contracted Sold ／ construction Sold

public function test_mark_sold_and_on_sale_touch_only_that_procurements_lot(): void
// 土地 A・B に区画。markSoldFor(A) → A sold・B on_sale。markOnSaleFor(A) → A on_sale。
// markSoldFor(null)・区画の無い案件の id でも例外にならず何も変わらない

public function test_deleting_a_procurement_deletes_its_lot(): void
// 経営層が詳細画面の削除のフォームから送る → 仕入れ案件と区画の行が 0
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/Screens/ProcurementLotModelTest.php` → FAIL（クラス・表が無い）
- [ ] **Step 3: 本番の SQL を書く**（Task 0 の読み取りで型が違えば合わせる）

```sql
-- 1.
CREATE TABLE `re_procurement_lots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_id` BIGINT UNSIGNED NOT NULL COMMENT '仕入れ案件（土地。1 案件に 1 区画まで）',
  `lot_number` INT NOT NULL COMMENT '号地番号',
  `area_sqm` DECIMAL(10,2) NOT NULL COMMENT '面積（㎡）。坪数は保存しない',
  `selling_price` INT NULL COMMENT '販売価格。坪単価は保存しない',
  `status` VARCHAR(30) NOT NULL COMMENT 'LotStatus',
  `notes` VARCHAR(200) NULL COMMENT '備考',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_procurement_lots_procurement` (`procurement_id`),
  CONSTRAINT `fk_re_procurement_lots_procurement` FOREIGN KEY (`procurement_id`) REFERENCES `re_procurements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 2.
ALTER TABLE `re_projects`
  ADD COLUMN `source_procurement_code` VARCHAR(20) NULL COMMENT '土地から変えた分譲地の元の仕入れ案件番号' AFTER `project_code`,
  ADD UNIQUE KEY `uq_re_projects_source_procurement_code` (`source_procurement_code`);
```

  先頭のコメントに、設計書・「DB が先」・「1 文ずつ流す・どちらかがあれば 1 文も流さない」・テスト用スキーマと対で直すこと、を書く（`2026-10-08-approval-phase5b.sql` の形）
- [ ] **Step 4: テスト用スキーマ・モデル・土台・`destroy()` を実装する**（テスト用スキーマは外部キーを張らない今の決まりのまま。`destroy()` は `$procurement->delete()` の直前に `$procurement->lot()->delete()`）
- [ ] **Step 5: 通ることを確かめる** — 同じコマンドで PASS、`<テスト> tests/Feature/RealEstate` も PASS
- [ ] **Step 6: コミット** — `feat: 土地の仕入れ案件の区画の表とモデルを足す`

---

### Task 3: 区画の登録・編集・削除（詳細画面の欄）と、物件種別の断り

**Files:**
- Create: `app/Http/Controllers/RealEstate/ProcurementLotController.php`
- Create: `resources/views/realestate/procurements/_lot_section.blade.php`
- Modify: `resources/views/realestate/procurements/show.blade.php`（「仕入れ情報」と「契約情報」のあいだで、土地のときだけ include・`badge-lot-*` の CSS を `projects/show` と同じ値で足す）
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php`（`show()` が `lot`・`lotDefaultStatus` を渡す・`validateProcurement()` に物件種別の断り）・`app/Models/ReProcurement.php`（定数 `TYPE_LOCKED_BY_LOT`）
- Modify: `app/Http/Controllers/Controller.php`（`protected function assertPerTsuboFits(?int $perTsubo): void` を `ProjectController` から移す）・`app/Http/Controllers/RealEstate/ProjectController.php`（private の方を消す）
- Modify: `routes/web.php`（仕入れ案件の節に 3 本）
- Test: `tests/Feature/RealEstate/Screens/ProcurementLotScreensTest.php`（新規）

**Interfaces:**
- Consumes: Task 2 の `ReProcurementLot`・`lot()`・`defaultLotStatus()`・土台の部品
- Produces:
  - ルート: `POST /realestate/procurements/{procurement}/lot` `realestate.procurements.lot.store`・`PUT …/lot` `realestate.procurements.lot.update`（ともに executive,manager）・`DELETE …/lot` `realestate.procurements.lot.destroy`（executive）
  - `ProcurementLotController::{store, update, destroy}`。定数 `ONLY_ONE`・`LAND_ONLY`・`NOT_FOUND`（文言は Global Constraints）。成功は `route('realestate.procurements.show', $p) . '#lot'` へ帯つきで戻す。入力エラーは既定の戻り先（詳細画面）
  - フォームの `id`: 登録 `lot-create-form`・編集 `lot-edit-form`・削除 `lot-delete-form`（3 つとも送り先の URL が同じなので、テストは `parseForm($html, 'id="lot-edit-form"')` のように `id` で掴む。Bug #47 の「別のフォームを掴む」を避ける）。並びは `grid grid-cols-1 sm:grid-cols-2 gap-3`（`customers/_form` の形）
  - `_lot_section.blade.php`: 外側 `<div id="lot">`。区画が無く経営層・管理者なら登録のフォーム（初期値 `old(…, 既定)`: 号地 1・面積 = 土地面積・販売価格 = 想定販売価格（土地）・ステータス = `$lotDefaultStatus`）／区画があれば表示（号地・面積・坪数・販売価格・坪単価・ステータスのバッジ・備考〈`wrap-anywhere`〉）＋経営層・管理者に編集のフォーム（`<details>`。入力エラーのあとは `open`）＋経営層に削除のフォーム（確認つき）／一般担当は表示だけ（区画が無ければ `区画は登録されていません。`）。入力エラーの箱は欄の中で `<li>` を 1 件ずつ。ステータスの `<option>` は `@foreach(LotStatus::cases())` の静的な描き方（Top trap #3）。数の欄は `type="number"`（販売価格・号地は `min` だけ、面積は `step="0.01" min="0.01"`）
  - 物件種別の断り: `property_type` の規則に `bail` と、`$current` に区画があり送られた値が `brokerage_land` でなければ `ReProcurement::TYPE_LOCKED_BY_LOT` で断るクロージャ（`statusLockedByContract()` と同じ書き方）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_a_lot_is_registered_from_the_detail_screen_with_the_land_values(): void
// 経営層で landProcurement() の詳細を開く。登録のフォーム（id="lot-create-form"）の初期値が lot_number '1'・area_sqm '132.69'・
//   selling_price '25000000'・status 'on_sale'。そのまま送る → 区画 1 行・landed で帯「区画を登録しました。」・
//   詳細に '132.69㎡（40.13坪）'・'@62.3'・販売中のバッジ

public function test_the_registration_form_starts_sold_when_the_land_is_already_sold(): void
// 契約のある土地 → 登録のフォームの status が 'sold'

public function test_an_empty_land_area_opens_an_empty_field_and_is_required(): void
// 土地面積・想定販売価格が null → area_sqm ''・selling_price ''。そのまま送る → assertErrorItem(trans('validation.required', ['attribute' => '面積（㎡）']))・
//   区画 0 行・戻った画面の lot_number は送った値

#[DataProvider('limits')]
public function test_the_limits_are_those_of_project_lots(string $field, string $value, string $message): void
// lot_number '0'（min.numeric）・lot_number MAX_INT+1（max.numeric）・area_sqm '0'（min.numeric）・
// selling_price '-1'（min.numeric）・notes 201 文字（max.string）・status 'x'（in）→ それぞれ trans() で組んだ 1 件の全文・区画 0 行

public function test_a_tsubo_price_too_large_for_the_column_is_refused(): void
// area_sqm '0.04'・selling_price '30000000' → 坪単価の文・区画 0 行

public function test_a_second_lot_is_refused(): void
// 登録のフォームを描いたあとで区画を作り、描いたフォームを送る → 帯 ONLY_ONE・区画 1 行のまま

public function test_a_lot_cannot_be_added_to_a_procurement_that_is_not_land(): void
// used_house の詳細に id="lot" が無い。手で組んだ POST …/lot → 帯 LAND_ONLY・区画 0 行

public function test_the_lot_is_edited_from_the_detail_screen(): void
// 区画あり。編集のフォームの初期値が保存値。面積を 150.00 にして送る → 保存・帯「区画を更新しました。」

public function test_saving_the_edit_form_unchanged_keeps_everything(): void
// 何も変えずに送る → 値が同じ。assertBrowserSubmits() でブラウザが送信を止めない（Bug #130・#131）

public function test_an_input_error_reopens_the_edit_form_with_the_input(): void
// 面積を空にして送る → 戻った画面の <details …open> と、ほかの欄に送った値

public function test_an_executive_deletes_the_lot_even_with_links(): void
// 区画＋契約。削除のフォーム（onsubmit の確認の文が逐語）を送る → 区画 0・契約は残る・帯「区画を削除しました。」

public function test_a_manager_edits_but_cannot_delete(): void
// userWithRole(Manager): 編集のフォームはある・削除のフォーム（_method DELETE で …/lot）は無い・手で組んだ DELETE は 403

public function test_staff_only_sees_the_lot(): void
// userWithRole(Staff): 表示はある・フォームは 1 つも無い・手で組んだ POST は 403

public function test_the_property_type_cannot_leave_land_while_a_lot_exists(): void
// 区画あり: 編集画面のフォームを何も変えずに送る → 通る。property_type を used_house にして送る → assertErrorItem(TYPE_LOCKED_BY_LOT)・
//   種別は brokerage_land のまま。区画なし: used_house に変えて送る → 通る
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/Screens/ProcurementLotScreensTest.php` → FAIL（ルート・欄が無い）
- [ ] **Step 3: 実装する**（上の Interfaces どおり。`ProcurementLotController` は入力チェックのあと、販売価格 > 0 なら `TsuboPrice::perTsuboYen($price, AreaConverter::sqmToTsubo($area))` を `assertPerTsuboFits()` に通す。`store` の順: 土地でない → `LAND_ONLY`、区画がある → `ONLY_ONE`、入力チェック）
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS。`<テスト> tests/Feature/RealEstate tests/Feature/OldInputRuleCoverageTest.php tests/Feature/JapaneseValidationMessagesTest.php tests/Feature/LongUnbrokenValueWrapTest.php tests/Feature/MobileLayoutTest.php` も PASS（詳細画面の新しい `old()` は、同じ画面の送り先が区画の登録・更新だけなので規則がそろう。削除と「分譲地に変える」は入力チェックを持たないので控えを作らない送り先として外れる）
- [ ] **Step 5: コミット** — `feat: 土地の仕入れ案件の詳細画面で区画を登録・編集・削除できるようにする`

---

### Task 4: 区画の状態を契約・建売・注文住宅に合わせて動かす

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php`（`store`・`update`・`destroy` の `isProcurement()` の枝で、仕入れ案件のステータスを動かしている行の隣）
- Modify: `app/Http/Controllers/Housing/ContractController.php`（`updateLotStatusOnSold()`・`updateLotStatusOnUnsold()` で `$property->re_procurement_id` も）
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`（`syncLotStatus()`・`releaseLot()`。今の「分譲地の区画が無ければ return」を、契約をまたいだかの判定のあとで、分譲地の区画と土地の区画をそれぞれ動かす形に組み直す。分譲地の区画の動きは変えない）
- Test: `tests/Feature/RealEstate/ProcurementLotStatusSyncTest.php`（新規。`RealEstateScreenTestCase` を継承。送り方は `ProcurementStatusTransitionTest`・`ProjectSoldStatusTransitionTest` の直接の送信を写す）

**Interfaces:**
- Consumes: `ReProcurementLot::markSoldFor()`・`markOnSaleFor()`・`landProcurement()`・`procurementLot()`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_a_contract_marks_the_lot_sold_and_deleting_it_puts_it_back(): void
// 仕入れ土地販売の契約を POST → 区画 sold。契約を DELETE → on_sale
public function test_switching_the_contracts_procurement_moves_sold(): void
// 土地 A（区画）に契約 → A sold。PUT で procurement_id を土地 B（区画）へ → A on_sale・B sold
public function test_a_land_without_a_lot_is_untouched(): void
// 区画の無い土地に契約・削除 → 500 にならず、区画の行は 0 のまま
public function test_a_building_contract_on_the_land_marks_the_lot_sold_and_deleting_it_puts_it_back(): void
// land_source_type procurement の建売に POST /housing/properties/{id}/contract → sold。DELETE → on_sale
public function test_a_custom_order_crossing_contracted_moves_the_lot(): void
// estimation の注文住宅を PATCH status contracted → sold。design → on_sale。
// 新規登録（POST /housing/custom-orders）を contracted で → sold
public function test_deleting_an_order_puts_the_lot_back_only_after_contract(): void
// contracted の注文住宅を削除 → on_sale。estimation で sold にしておいた区画の注文住宅を削除 → sold のまま
public function test_project_lots_still_move_as_before(): void
// 分譲地の区画の建売に契約 → 分譲地の区画 sold（組み直しで分譲地の側を壊していない）
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/ProcurementLotStatusSyncTest.php` → 区画が on_sale のままで FAIL（最後の 2 本は PASS してよい）
- [ ] **Step 3: 実装する**（契約の `update` は旧の仕入れ案件に `markOnSaleFor`・新に `markSoldFor`。種別ではなく仕入れ案件の id で動かす）
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS、`<テスト> tests/Feature/RealEstate tests/Feature/Housing` も PASS
- [ ] **Step 5: コミット** — `feat: 土地の区画の状態を契約・建売・注文住宅に合わせて動かす`

---

### Task 5: 一覧・契約・住宅事業の画面に区画を出す

**Files:**
- Modify: `app/Services/RealEstate/ProcurementListRow.php`（`fromProcurement()`: 土地なら `lotCount` = 区画があれば 1・なければ 0、`soldLotCount` = 成約なら 1、`lotsUrl` = 詳細 + `#lot`。土地でなければ今どおり null）・`ProcurementListService.php`（`with('costs', 'lot')`）
- Modify: `resources/views/realestate/procurements/index.blade.php`（`@if($row->isProject())` → `@if($row->lotCount !== null)`。コメントも）
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php`（`create`・`edit` の仕入れ案件の取得に `with('lot')`・`landOnlyMap()` の隣に `lotLabelMap()`・`show()` の load に `procurement.lot`）
- Modify: `resources/views/realestate/contracts/create.blade.php`・`edit.blade.php`（`procurementLotLabels: @json($procurementLotLabels)`・`procurementLotLabel()`・選択欄の下に `x-show`（初期は `style="display: none;"`）で `'区画: ' + procurementLotLabel()`）・`show.blade.php`（仕入れ案件の行に ` / 区画 {summaryLabel}`）
- Modify: `resources/views/housing/properties/show.blade.php`・`housing/custom-orders/show.blade.php`（土地元が仕入れ案件で区画があれば、リンクの下に `区画 {号地}号地 {areaLabel}`）
- Test: `tests/Feature/RealEstate/Screens/LandLotDisplayTest.php`（新規）

**Interfaces:**
- Produces: `ReContractController::lotLabelMap($procurements): array<string, string>`（仕入れ案件 id の文字 => `summaryLabel()`。区画の無い案件は載せない）／ Alpine の `procurementLotLabel(): string`（無ければ `''`）

- [ ] **Step 1: 失敗するテストを書く**

```php
#[DataProvider('landRows')]
public function test_a_land_row_shows_lot_counts_and_the_lot_button(?string $lotStatus, string $sold, string $total): void
// 区画なし 0/0 ・ on_sale 0/1 ・ sold 1/1。data-lot-count の正規表現（ProcurementListWithProjectsTest と同じ形）と、
//   区画ボタンの href が /realestate/procurements/{id}#lot
public function test_only_land_and_project_rows_have_the_lot_subline(): void
// 土地・中古戸建・分譲地の 3 行 → data-lot-count がちょうど 2
public function test_the_contract_registration_shows_the_lot_of_the_chosen_land(): void
// 契約の登録画面: driveAlpine(…, 'contractForm', 'contractForm()', "data.procurementId = '{id}';", evaluate: ['data.procurementLotLabel()'])
//   → '1号地 132.69㎡（40.13坪） 販売価格 25,000,000円'。区画の無い案件の id では ''
public function test_the_contract_edit_and_detail_show_the_lot(): void
// 編集画面の contractEditForm() で同じ。詳細に '区画 1号地 132.69㎡（40.13坪）'
public function test_the_housing_screens_show_the_lot_area_of_the_land(): void
// 土地元が仕入れ案件の建売・注文住宅の詳細に '区画 1号地 132.69㎡（40.13坪）'。区画の無い土地では '区画 ' の行が無い
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/Screens/LandLotDisplayTest.php` → FAIL
- [ ] **Step 3: 実装する**（上の Files どおり）
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS、`<テスト> tests/Feature/RealEstate tests/Feature/Housing tests/Feature/JsEchoClassificationTest.php tests/Feature/JsStringEchoScanTest.php` も PASS
- [ ] **Step 5: コミット** — `feat: 一覧・契約・住宅事業の画面に土地の区画を出す`

---

### Task 6: 分譲地に変える処理（サービス）と番号の振り方

**Files:**
- Create: `app/Services/RealEstate/ProcurementToProjectConverter.php`・`app/Services/RealEstate/ProcurementConversionRefused.php`（`extends \RuntimeException`。理由は `getMessage()`）
- Modify: `app/Enums/ProcurementStatus.php`（`toProjectStatus(): ProjectStatus`）
- Modify: `app/Support/DeletionBlockers.php`（`countsLabel(array $blockers): string` を足し、`summarize()` はそれ＋今の文末）
- Modify: `app/Models/ReProcurement.php`（`static nextProcurementCode(): string`）・`app/Models/ReProject.php`（`static nextProjectCode(): string`）
- Modify: `ProcurementController::store`・`ProjectController::store`（それぞれ新しい静的メソッドを使い、private の `generate…Code()` を消す）
- Test: `tests/Feature/RealEstate/Screens/ProcurementConversionTest.php`（新規。この Task ではサービスを直接呼ぶ）

**Interfaces:**
- Produces:
  - `ProcurementToProjectConverter::REFUSE_NOT_LAND`・`REFUSE_BROKERAGE`・`LINKED_SUFFIX`（`'が紐づいているため、分譲地に変えられません。'`）・`GONE`（二度押しの 2 回目の文。ルートの `missing()` と共用）
  - `static refusal(ReProcurement $p): ?string` — 土地でない → `REFUSE_NOT_LAND`、仲介 → `REFUSE_BROKERAGE`、`$p->deletionBlockers()` が空でない → `DeletionBlockers::countsLabel(…) . LINKED_SUFFIX`、ほかは null（この順）
  - `convert(ReProcurement $p, User $by): ReProject` — `DB::transaction` の中で: 仕入れ案件の行を `whereKey()->lockForUpdate()->first()` で読み直し（無ければ `GONE` で、`refusal()` が null でなければその理由で `ProcurementConversionRefused`） → `ReProject::create`（設計書 §5.5 の表どおりに写す。`project_code` = `nextProjectCode()`・`status` = `toProjectStatus()`・`source_procurement_code` = 元の番号・`created_by` = 元の登録者・`updated_by` = `$by`）→ 分譲地の原価の行を全部消す（作成のフックが作った物件購入費の行）→ 仕入れ案件の原価を 1 行ずつ写す（費用項目・見込み額・確定額・備考）→ 区画があれば `ReProjectLot` を作る（`area_tsubo` = `AreaConverter::sqmToTsubo()`・`selling_price_per_tsubo` = 販売価格 > 0 なら `TsuboPrice::perTsuboYen()`・`is_price_manual` true・状態と備考はそのまま）→ `syncStatusFromLots()` → 工程（`ScheduleStep` の `schedulable_type`・`schedulable_id`）と添付（`Attachment::withTrashed()` の `attachable_type`・`attachable_id`）を分譲地へ付け替える → 仕入れ案件の原価・区画・仕入れ案件を消す
  - `ProcurementStatus::toProjectStatus()`: InfoObtained→InfoObtained・SiteSurvey→Assessment・Assessment→Assessment・Negotiating→Negotiating・Contracted→Contracted・Settled→Settled・Selling→Selling・Sold→SoldOut・Lost→Lost
  - `ReProcurement::nextProcurementCode()`: `RE-PRC-` の連番。今の決め方（`procurement_code` の降順の先頭）と、`re_projects.source_procurement_code`（`RE-PRC-%`）の降順の先頭の、連番の大きい方 + 1（3 桁にそろえる）
  - `ReProject::nextProjectCode()`: 今の `generateProjectCode()` をそのまま移す

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_everything_moves_to_the_new_project(): void
// 土地（自社買取・settled・全項目・備考 5,000 文字・登録者は別の人）＋原価（物件購入費〈備考を手で入れる〉・測量費を 2 行）＋
//   区画（66.15㎡・19,990,000・negotiating・備考）＋工程 2 行＋添付 2 行（1 行は論理削除）。convert($p, $manager)
// → 分譲地の各項目が §5.5 の表どおり・source_procurement_code・created_by / updated_by・
//   原価の (費用項目, 見込み, 確定, 備考) の組が元と同じ（行の数も）・区画 area_tsubo 20.01・selling_price_per_tsubo 999001・
//   is_price_manual true・negotiating・工程と添付（論理削除のものも）の持ち主が分譲地・仕入れ案件・その原価・区画の行が 0
public function test_a_land_without_prices_keeps_its_purchase_cost_row(): void
// 3 つの価格が null・物件購入費の行（見込み 1,000,000・確定 900,000・備考）→ 分譲地に同じ 1 行
#[DataProvider('statuses')]
public function test_the_status_maps_to_the_project_status(string $from, string $to): void
// 9 通り（toProjectStatus()）
public function test_a_sold_lot_makes_the_project_sold_out(): void
// selling の土地・sold の区画 → 分譲地は sold_out（計画で決めたこと 2）
#[DataProvider('refusals')]
public function test_conversion_is_refused_with_the_reason(string $case, string $message): void
// used_house → REFUSE_NOT_LAND ／ 仲介 → REFUSE_BROKERAGE ／ 契約 → '契約 1 件が紐づいているため、分譲地に変えられません。' ／
// 建売 → '建売物件 1 件が…' ／ 注文住宅 → '注文住宅 1 件が…'。refusal() と convert() の例外の文が同じ・何も変わらない
public function test_the_original_code_is_not_reused(): void
// RE-PRC-001〜003 のうち 003（土地）を変える → 分譲地の source が RE-PRC-003・nextProcurementCode() === 'RE-PRC-004'
public function test_project_codes_still_count_up(): void
// RE-PRJ-002 がある → 変えた分譲地は RE-PRJ-003
public function test_the_conversion_rechecks_under_a_lock(): void
// ロックの中の確かめ直しは順に流すテストでは見えない（Bug #48 の形）ので字句で見る: convert() の本体（コメントを落とす）に
//   `->lockForUpdate()` があり、その後ろに `refusal(` がある
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/Screens/ProcurementConversionTest.php` → FAIL（クラスが無い）
- [ ] **Step 3: 実装する**（上の Interfaces どおり）
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS、`<テスト> tests/Feature/RealEstate tests/Feature/Housing` も PASS（`summarize()` の文は今と同じ）
- [ ] **Step 5: コミット** — `feat: 土地の仕入れ案件を中身ごと分譲地に変える処理を足す`

---

### Task 7: 「分譲地に変える」の画面とルート・分譲地の詳細の元の番号

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php`（`convertToProject()`・`show()` が `conversionRefusal` を渡す）
- Modify: `routes/web.php`（`POST /realestate/procurements/{procurement}/convert-to-project` `realestate.procurements.convert`・executive,manager・`->missing()` で一覧へ `ProcurementToProjectConverter::GONE`）
- Modify: `resources/views/realestate/procurements/_lot_section.blade.php`（経営層・管理者に「分譲地に変える」。断る理由があれば押せないボタンを `<span title="理由">` で包み、理由を `<p id="convert-refusal">` に出して `aria-describedby`（Top trap #12）。無ければ確認つきのフォーム `id="convert-form"`）
- Modify: `resources/views/realestate/projects/show.blade.php`（`source_procurement_code` があれば基本情報の最後に `元の仕入れ案件番号` の行。`grid-column: span 3`）
- Test: `tests/Feature/RealEstate/Screens/ProcurementConversionTest.php`（足す）

**Interfaces:**
- Consumes: Task 6 の `refusal()`・`convert()`・`ProcurementConversionRefused`
- Produces: `ProcurementController::convertToProject(Request $request, ReProcurement $procurement, ProcurementToProjectConverter $converter): RedirectResponse` — 入力チェックを持たない。成功 → 分譲地の詳細へ変えたあとの文。`ProcurementConversionRefused` → 文が `GONE` なら一覧へ、ほかは詳細 + `#lot` へ理由。ほかの `\Exception` → `report()`・詳細へ途中の失敗の文

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_a_manager_converts_from_the_detail_screen(): void
// userWithRole(Manager) で詳細の convert のフォーム（onsubmit の確認の文が逐語）を送る → 分譲地の詳細に着き、帯が逐語・
//   基本情報に '元の仕入れ案件番号' と RE-PRC-001
public function test_the_list_shows_one_project_row_after_conversion(): void
// 変えたあとの一覧: rows の total 1・kind project・物件名が分譲地の行に 1 回
public function test_a_refused_land_shows_a_disabled_button_and_the_reason(): void
// 契約のある土地: 押せるフォームが無く、<span title="契約 1 件が…"> と <p id="convert-refusal"> に同じ文。
//   手で組んだ POST → 詳細へ戻り帯に同じ文・何も変わらない。used_house の詳細に convert のフォームもボタンも無い
public function test_a_failure_midway_changes_nothing(): void
// Exceptions::fake()・ReProjectLot::creating で RuntimeException を投げる → 帯に途中の失敗の文・assertReported・
//   分譲地 0・仕入れ案件と原価・区画・工程・添付の持ち主はそのまま
public function test_sending_the_conversion_twice_lands_on_the_list(): void
// 同じフォームを 2 回送る → 2 回目は一覧に着き、帯に二度押しの文
public function test_staff_cannot_convert(): void
// userWithRole(Staff) の POST → 403・何も変わらない
public function test_editing_the_project_keeps_the_original_code(): void
// 変えた分譲地の編集画面を何も変えずに送る → source_procurement_code はそのまま
```

- [ ] **Step 2: 落ちることを確かめる** — 同じテストファイルで、新しい 7 本が FAIL
- [ ] **Step 3: 実装する**（上の Files・Interfaces どおり。断る理由は `ProcurementToProjectConverter::refusal()` を `show()` で 1 回だけ求めて渡す）
- [ ] **Step 4: 通ることを確かめる** — `<テスト> tests/Feature/RealEstate tests/Feature/OldInputRuleCoverageTest.php tests/Feature/MobileLayoutTest.php tests/Feature/LongUnbrokenValueWrapTest.php` が PASS
- [ ] **Step 5: コミット** — `feat: 詳細画面から土地の仕入れ案件を分譲地に変えられるようにする`

---

### Task 8: 仕入れ先の詳細と削除の歯止めに分譲地を足す

**Files:**
- Modify: `app/Models/ReSupplier.php`（`projects(): HasMany`・`hasProcurements()` を `isInUse(): bool` に替える・定数 `IN_USE`）
- Modify: `app/Http/Controllers/RealEstate/SupplierController.php`（`show()` が `projects` も渡す・`destroy()` が `isInUse()` と `IN_USE`）
- Modify: `resources/views/realestate/suppliers/show.blade.php`（`関連する分譲地` の表: 分譲地の番号〈詳細へのリンク〉・ステータスのバッジ・分譲地名・情報入手日。`badge-prj-*` の CSS を `projects/index` と同じ値で足す）
- Modify: `tests/Feature/RealEstate/Screens/SupplierScreensTest.php`（`test_a_supplier_used_by_a_procurement_is_not_deleted` の期待文を `IN_USE` に。意味は同じ）
- Test: 同じファイルに足す

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_the_detail_screen_lists_the_suppliers_projects(): void
// 仕入れ先の分譲地 → '関連する分譲地'・番号のリンク（/realestate/projects/{id}）・分譲地名・バッジ。分譲地が無ければ見出しが無い
public function test_a_supplier_used_only_by_a_project_is_not_deleted(): void
// 分譲地だけで使われる仕入れ先を詳細の削除のフォームから送る → 帯 IN_USE・論理削除されていない
public function test_a_converted_land_keeps_its_supplier_in_use(): void
// 土地を分譲地に変えたあと、仕入れ先の詳細に分譲地として出て、削除は断られる
```

- [ ] **Step 2: 落ちることを確かめる** — `<テスト> tests/Feature/RealEstate/Screens/SupplierScreensTest.php` → FAIL
- [ ] **Step 3: 実装する**
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS
- [ ] **Step 5: コミット** — `feat: 仕入れ先の詳細と削除の歯止めで分譲地も数える`

---

### Task 9: 全件・変異・実ブラウザ（親の会話が行う）

- [ ] **Step 1: 全件** — `<テスト>` → OK・警告なし。本数を記録する。コンパイル済みビューの lint（`php artisan view:cache` → `storage/framework/views/*.php` を `php -l` → `view:clear`。⚠ `.env` が無いので `APP_KEY` を渡す）→ INVALID 0
- [ ] **Step 2: 変異**（docs/RULES.md Bug #44 の作法: 先にコミット・各変異の前に `git status --porcelain` が空・`git diff --stat` が非空・落ちたテストの組と理由の文まで突き合わせる）。実行役は main repo の `.claude/route-coverage-tools/mutate/run.py` を作業場所の `.claude/route-coverage-tools/mutate/` へ写して `WT` を作業場所にし、表 `specs.land-lot.py` をそこへ置く（作業場所から main の `.claude/` へは書けない）。流す前に期待を書く:

| # | 変異 | 落ちるはずのテスト |
|---|---|---|
| C0 | `ONLY_ONE` の文を変える（カナリア） | `test_a_second_lot_is_refused` |
| L1 | label を `仲介土地` に戻す | `LandLabelTest` の 3 本 |
| M1 | `getAreaTsubo()` を `round($sqm * 0.3025, 2)` に | `test_area_and_tsubo_price_labels`（40.14）ほか表示のテスト |
| M2 | 坪単価を円で `round` してから万円に切り上げる | `test_area_and_tsubo_price_labels`（@99.9） |
| M3 | `defaultLotStatus()` が建売の契約を見ない | `test_the_default_status_follows_existing_links` |
| M4 | 注文住宅を `contracted` だけで見る | 同上（construction） |
| M5 | `markSoldFor()` の `where` を外す | `test_mark_sold_and_on_sale_touch_only_that_procurements_lot` |
| M6 | `destroy()` で区画を消さない | `test_deleting_a_procurement_deletes_its_lot` |
| M7 | `store` の「2 つ目」の確かめを外す | `test_a_second_lot_is_refused` |
| M8 | `store` の「土地でない」の確かめを外す | `test_a_lot_cannot_be_added_to_a_procurement_that_is_not_land` |
| M9 | 坪単価の確かめを外す | `test_a_tsubo_price_too_large_for_the_column_is_refused` |
| M10 | `area_sqm` を `min:0` に | `test_the_limits_are_those_of_project_lots`（area 0） |
| M11 | 登録のフォームの面積の初期値を空に | `test_a_lot_is_registered_from_the_detail_screen_with_the_land_values` |
| M12 | 初期のステータスを常に on_sale に | `test_the_registration_form_starts_sold_when_the_land_is_already_sold` |
| M13 | 物件種別の断りを外す | `test_the_property_type_cannot_leave_land_while_a_lot_exists` |
| M14 | 物件種別の断りを「区画があれば常に」に | 同上（そのままの保存） |
| M15〜M17 | 契約の `store`・`update`（旧）・`destroy` の呼び出しを 1 つずつ外す | Task 4 の該当の 1 本 |
| M18 | 建売の契約の `markSoldFor` を外す | `test_a_building_contract_on_the_land_…` |
| M19 | `syncLotStatus()` の土地の区画の戻し（on_sale）を外す | `test_a_custom_order_crossing_contracted_moves_the_lot` |
| M20 | `releaseLot()` の土地の区画を外す | `test_deleting_an_order_puts_the_lot_back_only_after_contract` |
| M21 | 一覧の区画の数を土地以外の仕入れ案件にも出す | `test_only_land_and_project_rows_have_the_lot_subline`・`ProcurementListWithProjectsTest` の 2 本 |
| M22 | 区画ボタンの `#lot` を外す | `test_a_land_row_shows_lot_counts_and_the_lot_button` |
| M23 | 契約の編集画面に `procurementLotLabels` を渡さない | `test_the_contract_edit_and_detail_show_the_lot` |
| M24 | 変換で分譲地の原価を消さずに写す | `test_everything_moves_to_the_new_project` |
| M25 | 添付を `withTrashed()` なしで付け替える | 同上 |
| M26 | `SiteSurvey` を `InfoObtained` に写す | `test_the_status_maps_to_the_project_status` |
| M27 | 仲介を断らない | `test_conversion_is_refused_with_the_reason` |
| M28 | `DB::transaction` を外す | `test_a_failure_midway_changes_nothing` |
| M29 | `nextProcurementCode()` が元の番号を見ない | `test_the_original_code_is_not_reused` |
| M30 | 分譲地の詳細の元の番号の行を外す | `test_a_manager_converts_from_the_detail_screen` |
| M31 | ルートの `missing()` を外す | `test_sending_the_conversion_twice_lands_on_the_list` |
| M32 | 仕入れ先の `isInUse()` が分譲地を見ない | `test_a_supplier_used_only_by_a_project_is_not_deleted`・`test_a_converted_land_keeps_its_supplier_in_use` |

| M33 | `convert()` の `->lockForUpdate()` を外す | `test_the_conversion_rechecks_under_a_lock` |

  ⚠ ロックの中の確かめ直しは順に流すテストでは見えない（Bug #48 の形）。M33 は字句のテストだけが落とす（見込みどおり）
- [ ] **Step 3: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright。手順は main repo の `.claude/route-coverage-tools/browser/README.md`）: 土地の詳細で区画を登録 → 編集 → 一覧の区画ボタンで `#lot` に着く → 契約の登録画面で土地を選ぶと区画の行が出る → 分譲地に変える（確認 → 分譲地の詳細・元の番号・区画と原価と添付が移った）→ 押せないときの理由（ホバーと本文）。詳細・一覧・分譲地の詳細・仕入れ先の詳細を 1440px と 375px で `main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 4: 記録** — 末尾の「実測記録」に全件・変異（落ちたテストと理由）・実ブラウザの結果を書き、表と結果を main repo の `.claude/route-coverage-tools/mutate/` へ写す（ExitWorktree のあとで）。コミット `docs: 土地の区画の計画に全件・変異・実ブラウザの結果を記録する`

---

### Task 10: 文書

- [ ] **Step 1:** `docs/BACKLOG.md` に節「✅ 土地（旧「仲介土地」）の区画登録と、分譲地への変更 — 本番反映前」（直す前・決めたこと・直したことの表・検証・範囲外〈設計書 §8〉）と「バックログ完了状況」に 1 段落
- [ ] **Step 2:** `docs/ARCHITECTURE.md` の表に `re_procurement_lots`・`re_projects.source_procurement_code`、Models に `ReProcurementLot`。`CLAUDE.md` の Completed modules の「不動産 仕入れ案件」の行に「土地の区画・分譲地への変更」
- [ ] **Step 3: コミット** — `docs: 土地の区画登録と分譲地への変更を記録する`

---

### Task 11: 本番反映（親の会話が行う。段ごとに利用者の承認を取る）

- [ ] **Step 1:** `13.x` が進んでいれば作業場所へ取り込み（rebase しない）、全件を流し直す。ほかの会話が 13.x を動かしていないことを `ListAgents` で確かめる
- [ ] **Step 2: 本番の読み取り（承認）** — 変わるファイルの md5 が `13.x` と一致・`re_procurement_lots` が無い・`source_procurement_code` が無い・設計書 §9 の件数（Task 0 と比べる）・`laravel.log`
- [ ] **Step 3: DB が先（承認）** — SQL を送り、どちらかがあれば 1 文も流さない確かめのうえで 1 文ずつ（`DB::statement()`）。流したあと `SHOW CREATE TABLE` で列・索引・外部キー
- [ ] **Step 4: 反映（承認）** — main repo で `13.x` を早送り → main repo の cwd で `composer dump-autoload --no-dev --optimize`（新しいクラスが表に入る・作業場所のパス 0・`vendor/bin/phpunit` が無い）→ `./deploy.sh`
- [ ] **Step 5: 反映の後の読み取り** — 送ったファイルの md5・コンパイル済みビューの lint・ルート（4 本増える）・`laravel.log` が Nov 18 01:45 のまま
- [ ] **Step 6: ログインした画面（承認・見るだけ・送らない）** — 土地の仕入れ案件の詳細に区画の欄と「分譲地に変える」（押せるか、押せない理由）・一覧の表記「土地」と区画の数・1440px で横スクロール 0・コンソール 0
- [ ] **Step 7:** BACKLOG の節を「本番反映済み」にして記録。push は利用者の指示があってから

---

## 実測記録

（Task 11 の本番反映はそのときに書き足す）

### 進め方（2026-11-20）

- subagent-driven（Task ごとに実装の担当 → 点検の担当。Task 6 は実装・点検とも opus、ほかは sonnet）
- 台帳の決めごと（`.superpowers/sdd/…/progress.md`）: Ruling 1 = Task 4 の状態の連動は直接の送信で見る ／ Ruling 2 = Task 0 の本番の読み取りは Task 11 Step 2 にまとめる ／ Ruling 3 = 変異と実ブラウザは担当に任せ、親が結果を確かめる ／ Ruling 4・5 = 下の穴と欠陥の直し方

### Task 0

- 着手前の全件 OK (5389 tests, 59423 assertions)・4:31・175.50 MB（PHP 8.3）。本番の読み取りは Task 11 Step 2 へ回した

### Task 1〜8 の点検で直したこと

- Task 5: 住宅事業の 2 つのコントローラの eager load（`procurement.lot`）が詳細画面（`show()`）でなく編集画面（`edit()`）に入っていた → `show()` へ移し `edit()` を元に戻した（`1f635c13`）
- そのほかの Task は点検で直すものなし（Minor は台帳に記録して最後の点検へ回した）

### Task 9 Step 1 全件（de5eb22d）

- OK (5472 tests, 60076 assertions)・警告 0・4:29・177.50 MB ／ コンパイル済みビュー 327 本 / INVALID 0

### Task 9 Step 2 変異（35 通り・関係するテスト 1016 本で・約 37 分）

- 表は作業場所の `.claude/route-coverage-tools/mutate/specs_land_lot.py`、実行役は `run_ll.py`（main の `run_cob.py` の写し）、結果は `results-land-lot.json` / `.txt`
- MATCH 34（C0・L1・M1〜M29・M31〜M33）。表に無いテストは、どの変異でも 1 本も落ちなかった（落ちたテストの組と理由の文まで突き合わせた）
- 流す前にテストを読んで計画より広げたもの: M5・M15・M21・M28 は 1 本ずつ多く落ちる見込みで、そのとおりだった（M5: 付け替えのテストも元の区画まで成約になる ／ M15: 付け替えのテストも最初の登録のあと元の区画を見る ／ M21: `ProcurementListWithProjectsTest` の DTO のテスト ／ M28: 字句のテストが「トランザクションで包まれていない」で落ちる）。M1 は 6 本に絞った
- M23 は書き方を変えた（compact から外すと契約の編集画面のテストがどれも 500 で落ちるので、渡す値を空の配列にした）
- M31: 1 回目の期待の誤り（理由の文字を「[404]」と書いたが、実際は「received 404」）。直して流し直し MATCH
- **M30 は穴だった**: 分譲地の詳細の「元の仕入れ案件番号」の行を消しても全部緑。`test_a_manager_converts_from_the_detail_screen` が見る 2 語は、着いた画面の成功の帯の文にも入っている（Bug #43 の型）→ 開き直した詳細で dt と dd を 1 本の正規表現で見るテストを足し（`818d8bcb`）、M30 を流し直してそのテストだけが落ちることを確かめた（C0 も同時に流して MATCH・1017 本）

### Task 9 Step 3 実ブラウザ（使い捨ての SQLite ＋ artisan serve ＋ Playwright・818d8bcb）

- 9 項目すべて OK: 区画の登録（初期値 号地 1・面積 132.69・販売価格 25000000・販売中 → `132.69㎡（40.13坪）`）／ 編集と入力エラーで開いたままの編集の欄 ／ 一覧の区画の数と区画ボタン（`#lot` に着く）・表記「土地」／ 契約の登録画面の区画の行（区画の無い土地では出ない）／ 契約のある土地の押せないボタン（包む span の title と `#convert-refusal` が同じ文・`elementFromPoint` がボタン自身で最寄りの title の祖先が span＝Top trap #12）／ 変える前の確認の文が逐語・変えたあとの帯・元の番号の行・区画と原価が移る ／ 管理者は区画の削除のフォームが無い・一般担当はフォーム 0 ／ 仕入れ先の「関連する分譲地」／ 4 画面 × 1440・375px で `main` の横スクロール 0・切れている要素 0・コンソールのエラー 0（Google Maps の警告だけ。手元に API キーが無い）
- 見つけた欠陥 2 つ（直した・`800ac904`）: ①分譲地の詳細の元の番号の行だけ `border-t` で、前の行の `border-b` と 2px の二重線 → ほかの行と同じ `border-b` にそろえた（ラベルが 130px の列で 2 行に折れるのは設計の文言どおりなので残した）②区画の入力エラーの戻り先に `#lot` が無く画面の先頭に戻る → 入力チェックと坪単価の確かめの `ValidationException` を詳細 + `#lot` へ投げ直す（Bug #64 の形）。どちらも直す前に赤のテストを足した
- ⚠ 契約の登録画面の仕入れ案件の選択肢は「販売中」の案件だけなので、確かめるときは土地のステータスを販売中にする
- ⚠ 直しのコミットが git 管理外の作業メモ（`.superpowers/…`）を強制で入れていた → 履歴を書き換えず `git rm --cached` のコミットで外した（`f6d68244`）
- 直したあとの全件 OK (5473 tests, 60090 assertions)・警告なし

### Task 10 と最後の点検（2026-11-20）

- Task 10 の文書（`fc934126`）は点検で直すものなし
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ）: 「直してからマージ」・Critical 0・Important 1・Minor 6
  - Important: 区画の欄（`#lot`）へ戻すと、上の帯が画面の外になる（とくに「分譲地に変える」の断りと途中の失敗）。Ruling 7 で、区画の欄の見出しの下に専用のフラッシュ（`lot_notice` / `lot_error`）で結果を出し、上の帯には出さない形にした
  - Minor のうち直したもの: 建蔽率・容積率の小数（生の値を写す）・二度押し止め（`submitOnce()`）・説明の文の「自社買取」・途中の失敗のテストの位置・SQL の注記。直さなかったもの: 詳細画面の問い合わせの増加（Ruling 6）
  - 直し（`bbecfcc9`）: 全件 OK (5476 tests, 60164 assertions)・警告なし・ビュー 327 本 / INVALID 0・実ブラウザで区画の欄の結果が 1440・375px とも画面の中（top 113）・二度押しで POST 1 回
  - 範囲を絞った点検: 6 件すべて直った。新しく出た Minor 2 件（区画が無い土地でない案件への古い画面の送信で理由が出ない・場所のテストが欄の終わりを見ない）は残した（台帳の parked）
