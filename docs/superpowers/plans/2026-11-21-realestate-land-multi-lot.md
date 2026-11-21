# 土地の仕入れ案件に区画を複数登録する Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 土地の仕入れ案件に区画を何個でも登録でき、区画ごとに不動産の契約・建売・注文住宅を 1 件だけ紐づけ（区画の状態と仕入れ案件の状態はそれに合わせて動く）、区画の契約の原価欄と住宅事業の土地原価に販売価格の比率で分けた額が入るようにする。

**Architecture:** `re_procurement_lots` の「1 案件 1 行」をやめ、契約に `procurement_lot_id`、建売・注文住宅に `re_procurement_lot_id` を足す（設計書 §6）。判定と式は 1 か所ずつに置く: 按分の式 `App\Support\LandCostAllocation`（分譲地と共用）／区画の取られ方・必須・断り `App\Support\ProcurementLotClaims`／区画の状態の連動 `ReProcurementLot::markSold()`・`markOnSale()`（中で `ReProcurement::syncStatusFromLots()`）。区画の欄は区画ごとのルート `/procurements/{procurement}/lots/{lot}` にする。

**Tech Stack:** Laravel 12 / PHP 8.3 / MySQL 8（本番・raw SQL）/ SQLite（テスト）/ Blade + Alpine.js 3 / PHPUnit（画面の JS は node の vm で動かす既存の道具）

**Spec:** `docs/superpowers/specs/2026-11-20-realestate-land-multi-lot-design.md`（2026-11-21 に利用者が承認。§7 の「私の判断」も承認に含まれる）

## 決めたこと（設計書に無い・設計書の任せた所。承認のとき利用者に見てもらう）

利用者が決めたこと（2026-11-21・どちらも A）:

1. **区画を選んだら、区画の値を入れる**: 不動産の契約の「土地の契約金額」に区画の販売価格、住宅事業の「土地面積」に区画の面積、建売の契約の「土地販売価格」の初期値に区画の販売価格。区画の販売価格が空なら金額は入れない（今の値のまま）
2. **建売の編集画面の土地は今どおり読み取り専用**（区画の無い建売に、あとから区画をつなぐ画面は作らない。本番の該当は 0 件）

計画で決めたこと（設計書 §8 の任せた所）:

3. **区画の決まりは「選んだ仕入れ案件に区画があるか」で決める**（契約種別では決めない。区画を持てるのは土地だけなので、中古戸建・中古マンションの契約は今どおり）
4. **見積り以前の注文住宅も、選べるのは「取られていない区画」と「今持っている区画」だけ**。サーバーも同じ集合で断る（区画を新しく選ぶ・変えるときだけ。今持っている区画は、あとでほかに取られていても、そのまま保存できる）
5. **区画の表の「紐づいているもの」は、見積りの注文住宅も含めて全部出す**（削除を断る理由と同じもの。`DeletionBlockers` の形: 契約は「物件名（買主 様）」、建売・注文住宅は「番号 名前」で詳細へのリンク）
6. **区画のルート**: `POST /realestate/procurements/{procurement}/lots`（`realestate.procurements.lots.store`）・`PUT …/lots/{lot}`（`.lots.update`）・`DELETE …/lots/{lot}`（`.lots.destroy`。経営層だけ）。`{lot}` はその仕入れ案件の区画に絞る（`scopeBindings()`）。見つからなければ 404 ではなく詳細の区画の欄へ戻して `NOT_FOUND`（仕入れ案件も無ければ一覧へ `ProcurementToProjectConverter::GONE`）。今の `…/lot` の 3 本は消す（ルートの数は変わらない）
7. **区画の行の編集は、その行のすぐ下に開くフォーム**（行ごとに `id="lot-edit-form-{id}"`。入力エラーで戻ったら、送った行のフォームを開いたまま描く。どの行かは hidden `lot_form`＝区画の id か `new`）
8. **区画の一覧の渡し方**: 不動産の契約の画面は API を足さず、サーバーが「仕入れ案件 id → 選べる区画の一覧（ラベル・販売価格・按分した原価）」を最初から渡す（今の `lotLabelMap()` の置き換え。Top trap #3 も同時に満たす）。住宅事業の画面は今の `GET /api/housing/procurement-info/{procurement}` に `lots` を足し（`?property_id=` / `?custom_order_id=` で今の区画を残す）、編集画面・入力エラーで戻った画面はサーバーが同じ一覧を最初から渡す
9. **区画の選択欄に HTML の `required` を付けない**（区画の無い保存済みのレコード＝#21 の形を、何も変えずに保存できるように。必須はサーバーの `requiresLot()` が決める）。その形のレコードの編集画面では、選択欄の下に「区画を選ばずに保存すると、今のまま（区画なし）で保存します。」を出す
10. **同時の保存にロックは足さない**（設計書 §8）。契約どうしは `procurement_lot_id` の一意の索引が止める（2 件目は本番で 500 になりうる。順に届けば入力チェックが先に断る。Bug #139 の範囲外と同じ扱い）

## Global Constraints

- 本番の表は raw SQL。本番は **DB が先・`./deploy.sh` が後**。新しい PHP クラスがあるので main repo の cwd で `composer dump-autoload --no-dev --optimize`
- ⚠ 「先に `procurement_id` にふつうの索引を足してから、一意の索引 `uq_re_procurement_lots_procurement` を外す」（外部キー `fk_re_procurement_lots_procurement` が使っている。順が逆だと MySQL の error 1553）
- 新しい列: `re_contracts.procurement_lot_id`・`hs_properties.re_procurement_lot_id`・`hs_custom_orders.re_procurement_lot_id`（どれも `BIGINT UNSIGNED NULL`・外部キー → `re_procurement_lots.id`・`ON DELETE RESTRICT`）。契約の列だけ一意の索引
- 「1 つの区画に付けられるのは、不動産の契約・建売・契約以降の注文住宅のうち 1 件だけ」（見積り以前の注文住宅は数えない。建売は契約の前から数える）
- 取られているかは紐付けで決め、区画のステータスでは決めない（設計書 §5.3）
- 按分: 区画 0〜1 の土地は全額（区画が 1 つなら販売価格が空でも全額）／ 2 つ以上は `(int) round($costTotal * ($lotPrice / $priceTotal))`（分譲地と同じ式）。全区画に販売価格が無い・合計が 0 なら空。もとの額は `ReProcurement::getEffectiveCostTotal()`
- 区画のある土地では、契約の登録で仕入れ案件を「販売済」にしない（区画から集約する）。区画の無い土地は今どおり
- `ReProcurement::refusesStatus()`（Bug #139）は変えない
- 分譲地の区画・分譲地の契約・住宅事業の分譲地区画の動きは変えない（分譲地の契約の原価の「均等割り」も）
- 坪数は `AreaConverter::sqmToTsubo()`、坪単価は `TsuboPrice`（Bug #33・#34）
- 区画の入力の上限は今の `ProcurementLotController` のまま（`lot_number` `required|integer|min:1|max:` + `MAX_INT_COLUMN` ほか）
- 文言（逐語）:
  - 号地の重なり: `この号地番号はこの土地にすでにあります。別の番号にしてください。`
  - 区画の削除の断り: `{件数}が紐づいているため、この区画は削除できません。`（`{件数}` は `DeletionBlockers::countsLabel()`。例 `契約 1 件・注文住宅 2 件`）
  - 区画が取られている: `この区画（{号地}号地）は{種類}「{名前}」に紐づいています。ほかの区画を選んでください。`（`{種類}` は `契約`・`建売物件`・`注文住宅`。`{名前}` は `DeletionBlockers` の項目の名前と同じ形）
  - 区画が要る: `区画を選んでください。`
  - 区画の無い保存済みのレコードの案内: `区画を選ばずに保存すると、今のまま（区画なし）で保存します。`
  - 区画の選択肢: `{号地}号地 {面積}（{坪}坪） 販売価格 {金額}円 {ステータス}`（`ReProcurementLot::summaryLabel()` ＋ 半角空白 ＋ ステータスの表記。販売価格が空なら販売価格の部分は無い）
  - 契約の詳細の按分の行: `この区画の按分: 販売価格 {X}円 ÷ 全区画の販売価格の合計 {Y}円` と按分した額 ／ 按分できない: `全区画に販売価格が入っていないため、按分できません`
  - 契約の登録の注記: `※ ステータスが「販売中」で、契約の無い案件と、空いている区画のある土地を表示`
  - 今の文言はそのまま: `区画を登録しました。` / `区画を更新しました。` / `区画を削除しました。` / `区画を登録できるのは、物件種別が土地の仕入れ案件だけです。` / `区画は登録されていません（ほかの画面で削除された可能性があります）。`
  - 消す文言: `区画は 1 つまでです。2 つ目を足すときは「分譲地に変える」を使ってください。`（`ProcurementLotController::ONLY_ONE`）
- 作業は git worktree の中で行う。worktree に .env を作らない
- テストの流し方は CLAUDE.md の「テストの流し方」。以下 `<テスト>` と書く: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations`
- データプロバイダは `#[DataProvider('…')]` の属性で書く。`git stash` は使わない（共有）
- 画面のテストは描いた画面のフォームを分解して送る（Bug #47。`parseForm()`・`browserForm()`・`composedForm()`・`submit()`）。Ajax の画面は `driveAlpine()` で JS が組んだ要求を送り、応答を JS に戻す。入力エラーは 1 件の全文で見る（`assertErrorItem()`。Bug #49）
- 次のことは、そのたびに利用者の承認を取る: 本番の読み取り（ssh）／SQL／13.x の早送り／./deploy.sh／push。push は、利用者が「push して」と言ったときだけ
- コミットの末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. 手で組んだ送信で**別の土地の区画・存在しない区画・配列**を送る → 入力エラーで断り、何も保存しない → Task 4 `test_a_lot_of_another_land_is_refused`・Task 6 `test_a_building_with_a_lot_of_another_land_is_refused`
2. 区画を持つ**見積りの注文住宅**が、その区画をほかの契約に取られたあとで**一覧の小窓から「契約」へ**進められる → 422 で理由を出し、ステータスも区画も変わらない → Task 7 `test_the_popup_refuses_contracted_on_a_taken_lot`
3. **見積りの注文住宅だけ**が付いた区画を削除する → 件数の文で断る（外部キーで 500 にしない）→ Task 3 `test_a_lot_held_only_by_an_estimate_order_cannot_be_deleted`
4. 区画の無い保存済みのレコード（#21 の形）を、区画を足した土地のまま**何も変えずに**保存する → 通る（契約・建売・注文住宅とも）→ Task 4 `test_a_legacy_contract_without_a_lot_saves_as_is`・Task 6 `test_a_legacy_building_without_a_lot_saves_as_is`・Task 7 `test_a_legacy_order_without_a_lot_saves_as_is`
5. 2 区画以上の土地で**区画の 1 つに販売価格が無い** → 契約の原価欄は入らない・住宅の土地原価の自動の値は空・契約の詳細は「按分できません」で食い違いの警告なし → Task 4 `test_allocation_is_unavailable_when_a_lot_has_no_price`・Task 6 `test_auto_land_cost_is_empty_when_a_lot_has_no_price`

---

### Task 0: 準備（親の会話が行う。サブエージェントに渡さない）

- [ ] **Step 1: 13.x の確かめ** — `git merge-base --is-ancestor 13.x HEAD`。進んでいたら `git merge 13.x`（rebase はしない）
- [ ] **Step 2: 作業場所の vendor を整える** — 作業場所の cwd で `cp -a /Users/masanori/site/manage/vendor ./vendor && composer install --no-scripts --no-interaction`（`vendor/` が無いときだけ）
- [ ] **Step 3: 着手前の全件** — `<テスト>` → `OK (5476 tests, 60164 assertions)` 前後であることを末尾の「実測記録」に書く
- [ ] **Step 4: 単一区画を前提にしているテストを数える** — `grep -rln -e "->lot\b" -e "lot\.store\|lot\.update\|lot\.destroy" -e "markSoldFor\|markOnSaleFor\|ONLY_ONE" tests app resources` の結果を実測記録に書く（各 Task で直す範囲の見落とし止め）

---

### Task 1: 表と列（SQL・テスト用スキーマ・モデルの列とリレーション）

**Files:**
- Create: `database/sql/2026-11-21-re-procurement-lots-multi.sql`
- Modify: `tests/Concerns/CreatesRealEstateSchema.php:167-176`（区画）・`:188-213`（契約）・`:284-`（建売）・`:326-`（注文住宅）
- Modify: `app/Models/ReProcurement.php`・`app/Models/ReProcurementLot.php`・`app/Models/ReContract.php`・`app/Models/HsProperty.php`・`app/Models/HsCustomOrder.php`
- Test: `tests/Feature/RealEstate/Screens/ProcurementLotModelTest.php`

**Interfaces:**
- Produces: `ReProcurement::lots(): HasMany`（`lot_number` の昇順）・`ReProcurementLot::contract(): HasOne`（`procurement_lot_id`）・`properties(): HasMany`・`customOrders(): HasMany`（どちらも `re_procurement_lot_id`）・`ReContract::procurementLot(): BelongsTo`・`HsProperty::procurementLot(): BelongsTo`・`HsCustomOrder::procurementLot(): BelongsTo`。3 つのモデルの `$fillable` に新しい列
- ⚠ `ReProcurement::lot()` はこの Task では残す（Task 8 で消す。消すまでの Task のテストを緑に保つため）

- [ ] **Step 1: 失敗するテストを書く** — `ProcurementLotModelTest` に `test_the_test_schema_matches_the_multi_lot_sql`: 新しい SQL を読み、①`re_procurement_lots` に `procurement_id` の一意の索引が**無く**、ふつうの索引が**ある** ②3 つの表に新しい列がある（`Schema::hasColumn`）③`re_contracts` に `procurement_lot_id` の一意の索引がある ④SQL の `ADD COLUMN` の列名とテスト用スキーマの列名が一致。今の `test_the_test_schema_matches_the_production_sql` の「`procurement_id` の一意の索引」のアサートは消す（設計の変更）。`test_lots_are_ordered_and_linked`: 号地 3・1・2 で作ると `$p->lots->pluck('lot_number')` が `[1, 2, 3]`、契約・建売・注文住宅を区画に付けると各リレーションで引ける
- [ ] **Step 2: 赤を確かめる** — `<テスト> tests/Feature/RealEstate/Screens/ProcurementLotModelTest.php` → 一意の索引が残っている・列が無い で落ちる
- [ ] **Step 3: SQL を書く** — 先頭の注記は前の SQL（`2026-11-20-create-re-procurement-lots.sql`）と同じ形（テスト用スキーマと対・DB が先・1 文ずつ `DB::statement()`・先頭で「`re_contracts.procurement_lot_id` があれば 1 文も流さずに止まる」）。文の順:
  1. `ALTER TABLE re_procurement_lots ADD INDEX idx_re_procurement_lots_procurement (procurement_id)`
  2. `ALTER TABLE re_procurement_lots DROP INDEX uq_re_procurement_lots_procurement`
  3. `ALTER TABLE re_procurement_lots MODIFY procurement_id BIGINT UNSIGNED NOT NULL COMMENT '仕入れ案件（土地。1 案件に何区画でも）'`
  4. `ALTER TABLE re_contracts ADD COLUMN procurement_lot_id BIGINT UNSIGNED NULL COMMENT '土地の仕入れ案件の区画' AFTER lot_id, ADD UNIQUE KEY uq_re_contracts_procurement_lot (procurement_lot_id), ADD CONSTRAINT fk_re_contracts_procurement_lot FOREIGN KEY (procurement_lot_id) REFERENCES re_procurement_lots (id) ON DELETE RESTRICT`
  5. `hs_properties`・6. `hs_custom_orders`: `ADD COLUMN re_procurement_lot_id … AFTER re_procurement_id, ADD KEY idx_…_re_procurement_lot (re_procurement_lot_id), ADD CONSTRAINT fk_…_re_procurement_lot … ON DELETE RESTRICT`
  - 1 文目だけ入って止まったときの続け方を注記に書く（前の SQL と同じ書き方）
- [ ] **Step 4: テスト用スキーマとモデル** — 区画の `procurement_id` を `->index()` に、3 つの表に列（契約は `->unique()`）。モデルに上の Interfaces。`ReProcurementLot` と `ReProcurement::lots()` の docblock の「1 案件に 1 区画まで」を直す
- [ ] **Step 5: 緑を確かめる** — 同じファイル → OK。`<テスト> tests/Feature/RealEstate tests/Feature/Housing` → 今までのテストも緑
- [ ] **Step 6: Commit**

```bash
git add database/sql/2026-11-21-re-procurement-lots-multi.sql tests/Concerns/CreatesRealEstateSchema.php app/Models tests/Feature/RealEstate/Screens/ProcurementLotModelTest.php
git commit -m "$(cat <<'EOF'
feat: 土地の区画を 1 案件に何区画でも持てる表と、契約・建売・注文住宅の区画の列を足す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: 部品（按分の式・取られ方の判定・状態の集約）

**Files:**
- Create: `app/Support/LandCostAllocation.php`・`app/Support/ProcurementLotClaims.php`
- Modify: `app/Models/ReProject.php:247-254`（`allocatedLandCost()` の中身を `LandCostAllocation::share()` に）・`app/Models/ReProcurement.php`・`app/Models/ReProcurementLot.php`・`app/Support/DeletionBlockers.php`
- Test: Create `tests/Unit/Support/LandCostAllocationTest.php`・`tests/Feature/RealEstate/ProcurementLotClaimsTest.php`・`tests/Feature/RealEstate/ProcurementLotPartsTest.php`

**Interfaces:**
- Consumes: Task 1 のリレーション
- Produces:
  - `LandCostAllocation::share(int $costTotal, ?int $lotPrice, int $priceTotal, bool $allPriced): ?int` — `! $allPriced || $priceTotal <= 0 || $lotPrice === null` なら null、それ以外は `(int) round($costTotal * ($lotPrice / $priceTotal))`
  - `ReProcurement::getLotSellingPriceTotal(): int`・`allLotsHaveSellingPrice(): bool`（`ReProject` と同じ名前）
  - `ReProcurement::landCostForLot(?int $lotId): ?int` — 区画 0〜1: `$lotId` が null かこの案件の区画なら `getEffectiveCostTotal()`、ほかの案件の区画なら null ／ 2 つ以上: この案件の区画なら `share()`、null・ほかの区画なら null
  - `ReProcurement::syncStatusFromLots(): void` — `ReProject::syncStatusFromLots()` と同じ決まり（区画 0 は触らない・全区画成約で `Sold`／`Lost` 以外なら `Sold`・成約でない区画があって `Sold` なら `Selling`。クエリビルダで更新し、手元の `status` も揃える）
  - `ReProcurementLot::markSold(?int $lotId): void`・`markOnSale(?int $lotId): void` — その区画だけを動かし、その仕入れ案件の `syncStatusFromLots()` を呼ぶ（null・無い id なら何もしない）
  - `ReProcurementLot::optionLabel(): string` — `summaryLabel() . ' ' . status->label()`
  - `ProcurementLotClaims`（`ReContract|HsProperty|HsCustomOrder` を「持ち主」と呼ぶ。列名の違い `procurement_id`/`procurement_lot_id` と `re_procurement_id`/`re_procurement_lot_id` はこのクラスの中で吸収する）:
    - `takenLotIds(array $procurementIds, ReContract|HsProperty|HsCustomOrder|null $except = null): array<int, int>` — 契約の区画・建売の区画・契約以降の注文住宅の区画（`$except` 自身は除く）
    - `takerLabel(int $lotId, ReContract|HsProperty|HsCustomOrder|null $except = null): ?string` — 例 `契約「勝山の土地（山田 太郎 様）」`
    - `selectableLots(ReProcurement $procurement, ReContract|HsProperty|HsCustomOrder|null $current): Collection<int, ReProcurementLot>` — 取られていない区画 ＋ `$current` が今持っている区画（号地の順）
    - `requiresLot(mixed $procurementId, ReContract|HsProperty|HsCustomOrder|null $current): bool` — 数字の id で、その案件に区画があるとき true。ただし `$current` の保存済みの仕入れ案件が同じで保存済みの区画が空なら false（#21 の形）
    - `refusal(mixed $lotId, ReContract|HsProperty|HsCustomOrder|null $current, bool $selfTakes): ?string` — 区画がほかに取られていて、かつ（新しく選んだ・変えた、または `$selfTakes`）なら上の「区画が取られている」の文。それ以外は null
  - `DeletionBlockers::forProcurementLotIds(array $lotIds): array`・`forEachProcurementLotId(array $lotIds): array<int, array>` — `forLotIds()`・`forEachLotId()` と同じ形で、契約は `procurement_lot_id`、建売・注文住宅は `re_procurement_lot_id`（注文住宅は全部のステータス）
- ⚠ 今の `ReProcurementLot::markSoldFor()`・`markOnSaleFor()` はこの Task では残す（呼ぶ側を Task 4・6・7 で移し、Task 8 で消す）

- [ ] **Step 1: 失敗するテストを書く**
  - `LandCostAllocationTest`（`#[DataProvider]`）: `(30000000, 10000000, 30000000, true) → 10000000`・`(10000000, 1, 3, true) → 3333333`・`(10000000, 2, 3, true) → 6666667`・`(5, 1, 0, true) → null`・`(5, 1, 2, false) → null`・`(5, null, 2, true) → null`
  - `ProcurementLotClaimsTest`: `test_taken_lots_follow_links_not_status`（区画 1 に契約・区画 2 に建売（契約なし）・区画 3 に見積りの注文住宅・区画 4 に契約の注文住宅 → `takenLotIds([$p->id])` は 1・2・4。区画 5 は成約のステータスだけで取られていない）／ `test_the_current_owner_keeps_its_lot`（`selectableLots($p, 区画 1 の契約)` に区画 1 が入る）／ `test_requires_lot`（区画の無い案件 false・ある案件 true・数字でない id false・`$current` が同じ案件で区画が空なら false・違う案件なら true）／ `test_refusal`（取られた区画を新しく選ぶ → 文の全文・今持っている区画を `selfTakes=false` で保存 → null・`selfTakes=true` で保存 → 文）／ `test_taker_label_names_each_kind`
  - `ProcurementLotPartsTest`: `test_mark_sold_moves_only_that_lot_and_syncs`（2 区画のうち 1 つ → その区画だけ成約・案件は販売中のまま／もう 1 つ → 案件が販売済／`markOnSale` で 1 つ戻す → 案件が販売中）／ `test_sync_does_not_touch_lost_or_lotless`（区画 0 → 触らない・不成約 → 全区画成約でも触らない）／ `test_land_cost_for_lot`（原価の行 30,000,000：区画 0 → 30,000,000・区画 1 つ（販売価格なし）→ 30,000,000・区画 2 つ 20,000,000/10,000,000 → 20,000,000 と 10,000,000・片方の販売価格なし → null・ほかの案件の区画 → null）／ `test_project_allocation_is_unchanged`（分譲地の `housingLotLandCost()` の値が直す前と同じ）／ `test_deletion_blockers_for_procurement_lots`（見積りの注文住宅も数える・`forEach` の形）
- [ ] **Step 2: 赤を確かめる** — 3 ファイル → クラス・メソッドが無い で落ちる
- [ ] **Step 3: 実装** — 上の Interfaces どおり。`ReProject::allocatedLandCost()` は `share()` を呼ぶだけにする（式を 2 か所に置かない。Bug #41）
- [ ] **Step 4: 緑を確かめる** — 3 ファイル → OK。`<テスト> tests/Feature/RealEstate tests/Feature/Housing` → 緑
- [ ] **Step 5: Commit** — `feat: 土地の区画の按分・取られ方の判定・状態の集約を 1 か所ずつに置く`（末尾に Co-Authored-By）

---

### Task 3: 区画の欄（何区画でも・号地の重なり・削除の歯止め・紐づいているもの）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementLotController.php`・`routes/web.php:837-846`・`app/Http/Controllers/RealEstate/ProcurementController.php:139-212`（show）・`:329`（destroy）・`:446-451`（`typeLockedByLot`）
- Modify: `resources/views/realestate/procurements/_lot_section.blade.php`
- Test: `tests/Feature/RealEstate/Screens/ProcurementLotScreensTest.php`（今のテストを新しいルートとフォームの id に移し、下のテストを足す）

**Interfaces:**
- Consumes: Task 2 の `syncStatusFromLots()`・`optionLabel()`・`DeletionBlockers::forEachProcurementLotId()`・`ReProcurement::defaultLotStatus()`
- Produces: ルート `realestate.procurements.lots.store` / `.lots.update` / `.lots.destroy`。`ProcurementController::show()` が渡す `$lots`（号地の順）・`$lotBlockers`（区画 id → ブロッカー）・`$lotCreateDefaults`（`lot_number`・`area_sqm`・`selling_price`・`status`）

- [ ] **Step 1: 失敗するテストを書く**（どれも描いた詳細画面のフォームを送る往復）
  - `test_a_second_and_third_lot_can_be_added`（3 区画が表に号地の順で出る・`区画を登録しました。` が区画の欄に出る）
  - `test_the_create_form_defaults`（区画 0: 号地 1・土地面積・想定販売価格（土地）・`defaultLotStatus()`／区画 2 つ（号地 1・5）: 号地 6・面積と販売価格は空・販売中。土地に契約があっても 2 つ目からは販売中）
  - `test_a_duplicate_lot_number_is_refused_on_create_and_update`（文の全文・入力が残り、送った行のフォームが開いている）
  - `test_a_linked_lot_cannot_be_deleted`（契約 → `契約 1 件が紐づいているため、この区画は削除できません。`・区画は残る）
  - `test_a_lot_held_only_by_an_estimate_order_cannot_be_deleted`（Review Focus 3）
  - `test_the_table_shows_what_is_linked`（契約の詳細・建売の詳細・注文住宅の詳細へのリンクが、その区画の行の中にある。Bug #43: 行の中まで見る）
  - `test_lot_changes_resync_the_procurement_status`（全区画を成約に更新 → 販売済・1 つを販売中に → 販売中・成約でない区画を削除 → 販売済）
  - `test_a_lot_of_another_procurement_is_not_found`（ほかの案件の区画の id で更新・削除 → 区画の欄へ戻り `NOT_FOUND`・何も変わらない）
  - `test_the_only_one_message_is_gone`（`ONLY_ONE` の文が画面にもクラスにも無い）
  - 今の `ProcurementLotScreensTest` のうち、設計の変更で意味が変わる 3 本: `test_a_second_lot_is_refused` は消す／`test_an_executive_deletes_the_lot_even_with_links` は「紐付けがあれば断る」に置き換わるので消す（上の 2 本が代わりに見る）／`test_updating_or_deleting_without_a_lot_says_why` は上の `test_a_lot_of_another_procurement_is_not_found` に置き換える。残りの 14 本（区画の登録・初期値・上限・坪単価・土地でない・編集・何も変えずに保存・入力エラーで開き直す・権限・物件種別の固定）は、新しいルートとフォームの id に直して残す
- [ ] **Step 2: 赤を確かめる** — 同じファイル → ルートが無い・2 つ目が断られる で落ちる
- [ ] **Step 3: ルートとコントローラ** — 3 本を `…/lots`・`…/lots/{lot}` に置き換え、`scopeBindings()` と `missing()`（決めたこと 6）。`store`: `ONLY_ONE` の確かめを消す。`lot_number` に `Rule::unique('re_procurement_lots', 'lot_number')->where('procurement_id', $procurement->id)->ignore($lot?->id)`（文は Global Constraints）。保存・削除のあとで `$procurement->syncStatusFromLots()`。`destroy`: ブロッカーがあれば `lot_error` に断りの文。入力エラーで戻すときの hidden `lot_form` は検査に `nullable|string|max:20` で足す（Bug #121。保存しない欄は `exclude` でもよい）。`ProcurementController`: `typeLockedByLot` と削除を `lots()` に、show に Interfaces の 3 つ
- [ ] **Step 4: 区画の欄の画面** — 区画が 1 つ以上なら表（号地・面積（坪）・販売価格（坪単価）・ステータス・備考・紐づいているもの・操作）を `overflow-x-auto` の枠の中に（`MobileLayoutTest`）。各行に「編集」（行の下にフォームを開く・決めたこと 7）と「削除」（経営層だけ・確認の文は今のまま）。表の下に追加のフォーム（`id="lot-create-form"`・初期値は `$lotCreateDefaults`）。二度押し止めは今の部品（`assertLotSubmitOnce()` が見る形）。「分譲地に変える」は今の場所のまま。「1 つまで」「2 つ目は分譲地に変える」に当たる文を消す（`grep -rn "1 つまで\|2 つ目" app resources` → 0 件）
- [ ] **Step 5: 緑を確かめる** — `<テスト> tests/Feature/RealEstate` → OK
- [ ] **Step 6: Commit** — `feat: 土地の仕入れ案件に区画を何個でも登録・編集・削除できるようにする`（末尾に Co-Authored-By）

---

### Task 4: 不動産の契約（区画を選ぶ・1 区画 1 件・状態・按分した原価・詳細）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php`（create・store・show・edit・update・destroy・`validateContract()`・`lotLabelMap()` の置き換え）
- Modify: `resources/views/realestate/contracts/create.blade.php`・`edit.blade.php`・`show.blade.php:187-243`
- Modify: `lang/ja/validation.php`（attributes に `procurement_lot_id` => `区画`）
- Test: Create `tests/Feature/RealEstate/Screens/ContractProcurementLotTest.php`。Modify `tests/Feature/RealEstate/ProcurementLotStatusSyncTest.php`・`tests/Feature/RealEstate/Screens/LandLotDisplayTest.php`

**Interfaces:**
- Consumes: Task 2 の `ProcurementLotClaims::*`・`landCostForLot()`・`markSold()`/`markOnSale()`・`syncStatusFromLots()`・`optionLabel()`・`getLotSellingPriceTotal()`・`allLotsHaveSellingPrice()`
- Produces: 画面へ渡す `procurementLotOptions`（`array<string 仕入れ案件 id, list<array{id: int, label: string, selling_price: ?int, cost_amount: ?int}>>`。`cost_amount` は `landCostForLot()`）。`ReContractController::settleProcurement(int $procurementId, bool $sold): void`（private。区画の無い案件は今どおり `Sold`/`Selling` にし、区画のある案件は `syncStatusFromLots()`）

- [ ] **Step 1: 失敗するテストを書く**（`ContractProcurementLotTest`。2 区画 20,000,000/10,000,000・原価の行 30,000,000 の土地を土台にする）
  - `test_the_create_screen_offers_the_lots_of_the_chosen_land`（区画の選択欄に 2 つの選択肢・文は `optionLabel()`・注記の新しい文）
  - `test_choosing_a_lot_fills_the_land_amount_and_the_allocated_cost`（`browserForm()` で仕入れ案件と区画 2 を選ぶ → `contract_amount_land` 10,000,000・`cost_amount` 10,000,000 を送り、保存された `procurement_lot_id` が区画 2）
  - `test_a_single_selectable_lot_is_preselected`（選べる区画が 1 つの土地を選ぶと、その区画が選ばれる）
  - `test_two_contracts_on_one_land_need_different_lots`（区画 1 の契約のあと区画 2 は登録できる・区画 1 は「区画が取られている」の全文で断る）
  - `test_taken_lots_and_full_lands_are_not_offered`（区画 1 は選択肢から消える・全区画が取られた土地は仕入れ案件の選択肢から消える・区画の無い土地は契約があれば今どおり消える＝Bug #139）
  - `test_a_lot_is_required_on_a_land_with_lots`（`区画を選んでください。`）
  - `test_a_lot_of_another_land_is_refused`（Review Focus 1。別の土地の区画・存在しない id・`['1']` の配列 → それぞれ断り、契約は増えない）
  - `test_a_legacy_contract_without_a_lot_saves_as_is`（Review Focus 4。区画の無い土地に契約 → 区画を 1 つ足す（成約で作られる）→ 契約の編集画面に区画の選択欄（何も選ばれていない）と案内の文 → そのまま保存できる・区画は空のまま → 次に区画 1 を選んで保存できる）
  - `test_lot_contracts_drive_lot_and_procurement_status`（区画 1 の契約 → 区画 1 成約・案件は販売中／区画 2 の契約 → 案件が販売済／区画 2 の契約を削除 → 区画 2 販売中・案件販売中／3 区画の土地で区画 1 の契約を区画 3 へ付け替える → 区画 1 販売中・区画 3 成約）
  - `test_lotless_lands_keep_todays_status_rules`（区画の無い土地の契約の登録で販売済・削除で販売中＝今のまま）
  - `test_the_show_screen_compares_with_the_allocated_cost`（区画 2 の契約の原価を 9,000,000 で保存 → 詳細に区画の行・按分の行の全文・食い違いの警告は 10,000,000 との差 1,000,000）
  - `test_allocation_is_unavailable_when_a_lot_has_no_price`（Review Focus 5。区画 2 の販売価格を空に → 区画を選んでも原価欄は入らない・詳細は「按分できません」の文・食い違いの警告なし）
  - `test_one_lot_lands_use_the_full_cost`（区画 1 つ（販売価格なし）→ 原価欄に 30,000,000）
  - `ProcurementLotStatusSyncTest`: 契約の送信に `procurement_lot_id` を足す（区画が 1 つの土地も区画を選ぶ作りになった。設計書 §5.3）。`test_switching_the_contracts_procurement_moves_sold` は区画を付け替える形に直す
  - `LandLotDisplayTest`: 契約の詳細の区画を `procurementLot` で見る形に直す（仕入れ案件の 1 区画ではなく契約の区画）
- [ ] **Step 2: 赤を確かめる** — 2 ファイル → 区画の選択欄が無い・断らない で落ちる
- [ ] **Step 3: 入力チェック** — `validateContract()` の仕入れ系に: `procurement_lot_id` = `['nullable', 'integer', Rule::requiredIf(fn () => ProcurementLotClaims::requiresLot($request->input('procurement_id'), $current)), Rule::exists('re_procurement_lots', 'id')->where('procurement_id', 数字なら選んだ案件 id・それ以外は 0)]`。`Rule::unique('re_contracts', 'procurement_id')` は**選んだ案件に区画が無いときだけ**付ける。`after` のフックで `ProcurementLotClaims::refusal($lot, $current, true)` を `procurement_lot_id` のエラーに。仕入れ系でない種別では `procurement_lot_id` を `exclude`。区画の無い案件を選んだときは保存する値を null にする。messages に `procurement_lot_id.required` => `区画を選んでください。`
  - ⚠ 入力チェックの前に `procurement_id` を読むときは、数字の文字か整数のときだけ使う（Bug #121）
- [ ] **Step 4: 状態の連動** — store: 区画があれば `markSold($lot)`、無ければ今どおり（`settleProcurement($id, true)`）。update: 仕入れ案件か区画が変わったら、旧の区画を `markOnSale`・新の区画を `markSold`、区画の無い案件は `settleProcurement()`（旧 `false`・新 `true`）。destroy: 区画があれば `markOnSale`、無ければ `settleProcurement($id, false)`（区画のある土地の区画の無い契約＝#21 は、区画を動かさず集約するだけ）
- [ ] **Step 5: 画面** — create/edit: 仕入れ案件の選択肢を「販売中で、区画が無く契約も無い」と「販売中で、`selectableLots()` が空でない」（edit は今の案件も）に。`procurementLotOptions` を `@json` で単一変数として渡す（Bug #26）。区画の選択欄（`name="procurement_lot_id"`・`<template x-for>`＋`:selected`・`required` は付けない・選んだ案件に区画が無ければ `x-show` で隠して `:disabled`）。仕入れ案件を選んだら区画をクリアし、選べる区画が 1 つならそれを選ぶ。区画を選んだら `amountLand`（販売価格が null でなければ）と `costAmount`（`cost_amount` が null でなければ）を入れて `calcProfit()`。原価の API の応答のあとで区画が選ばれていれば区画の原価を入れ直す。区画の無い保存済みの契約の編集画面では案内の文。今の「区画: …」の注記（`procurementLotLabel()`）は消す。show: 区画を `$contract->procurementLot->summaryLabel()` で出し、区画が 2 つ以上の土地の区画の契約は、`$liveCost` を `landCostForLot()` にして按分の行を出す（按分できないときは `$liveCost`・`$costDivergence` を null にする）
- [ ] **Step 6: 緑を確かめる** — `<テスト> tests/Feature/RealEstate tests/Feature/JapaneseValidationMessagesTest.php tests/Feature/OldInputRuleCoverageTest.php` → OK
- [ ] **Step 7: Commit** — `feat: 不動産の契約で土地の区画を選び、1 区画 1 件と按分した原価にする`（末尾に Co-Authored-By）

---

### Task 5: 住宅事業の土台（土地原価・紐付けの比べ方・区画の一覧の API）

**Files:**
- Modify: `app/Support/HousingLandCost.php`・`app/Models/Concerns/LocksLandAfterContract.php`・`resources/views/housing/_land_cost_functions.blade.php`
- Modify: `app/Models/ReProcurement.php`（`housingLotOptions()` を足す）・`app/Http/Controllers/Housing/PropertyController.php:428-442`（`procurementInfo()`）
- Test: `tests/Feature/Housing/HousingLandCostTest.php`・`tests/Feature/Housing/Screens/LandCostAutoTest.php`（API）・`tests/Feature/Housing/Screens/LandCostViewParityTest.php`・`tests/Feature/Housing/Screens/LandLockTest.php`

**Interfaces:**
- Consumes: Task 2 の `landCostForLot()`・`ProcurementLotClaims::takenLotIds()`/`selectableLots()`・`optionLabel()`
- Produces:
  - `HousingLandCost::forLink(?string $sourceType, mixed $lotId, mixed $procurementId, mixed $procurementLotId = null): ?int` — 仕入れ案件: 案件が無い・原価の行が無いなら null（今どおり）、それ以外は `landCostForLot(数字なら $procurementLotId・それ以外は null)`。`whenAutomatic()` は `$link['re_procurement_lot_id']` も渡す
  - `LocksLandAfterContract::landLink()` の比べる形に 4 つ目「種別が仕入れ案件のときだけ `re_procurement_lot_id`」（区画だけを変えるのも「紐付けが違う」）
  - JS `housingLandLinkChanged(saved, now)` が `procurementLot` も比べる（`savedLink` に `procurementLot` を足すのは Task 6・7）
  - `ReProcurement::housingLotOptions(array $excludeLotIds = []): array<int, array{id: int, lot_number: int, label: string, area_sqm: float, selling_price: ?int, land_cost: ?int, status: string, status_label: string}>`（`ReProject::housingLotOptions()` と同じ形 ＋ `label`。`land_cost` は `HousingLandCost::forLink()` と同じ値）
  - `GET /api/housing/procurement-info/{procurement}` の応答に `lots`（`?property_id=`・`?custom_order_id=` の持ち主の区画を残し、ほかに取られた区画を外す。数字でない値は無視）

- [ ] **Step 1: 失敗するテストを書く**
  - `forLink()`: 2 区画の土地で区画ごとの按分・区画なし（2 区画以上）→ null・1 区画で区画なし → 全額・原価の行なし → null
  - `LandLockTest`: `test_changing_only_the_procurement_lot_is_a_link_change`（契約のある建売で区画だけを変える送信 → `LAND_LOCKED_BY_CONTRACT` で断る）
  - `LandCostViewParityTest`: `test_the_link_comparison_matches_between_php_and_js`（種別・分譲地区画・仕入れ案件・土地の区画の組み合わせ 12 場面以上で、PHP の `landLinkDiffers()` と JS の `housingLandLinkChanged()` が同じ真偽を返す。区画だけ違う・区画が空と null・先頭の 0 を含める）
  - `test_procurement_info_lists_selectable_lots`（区画 1 に建売 A → `?property_id=A` なら区画 1 も入る・`?property_id=` なし・ほかの建売なら区画 1 は入らない・`land_cost` は按分の値・`?property_id[]=1` は無視して 200）
- [ ] **Step 2: 赤を確かめる**
- [ ] **Step 3: 実装** — 上の Interfaces どおり
- [ ] **Step 4: 緑を確かめる** — `<テスト> tests/Feature/Housing tests/Unit` → OK
- [ ] **Step 5: Commit** — `feat: 住宅事業の土地原価と紐付けの比べ方に土地の区画を含め、区画の一覧を API で返す`（末尾に Co-Authored-By）

---

### Task 6: 建売（登録・編集・建売の契約・詳細）

**Files:**
- Modify: `app/Http/Controllers/Housing/PropertyController.php`（create・store・edit・update・`validateProperty()`・`setLandSourceForeignKeys()`・`computedLandCost()`）・`app/Http/Controllers/Housing/ContractController.php:228-255`・`app/Http/Controllers/Housing/HsContractListController.php:235-305`（`updateBuilding()` の土地原価の紐付けに区画）
- Modify: `app/Models/HsProperty.php:405-433`（`getReferenceLandSellingPrice()`・`getLandSourceDisplay()`）
- Modify: `resources/views/housing/properties/_form.blade.php`・`resources/views/housing/properties/show.blade.php:72-76`
- Modify: `lang/ja/validation.php`（attributes に `re_procurement_lot_id` => `区画`）
- Test: Create `tests/Feature/Housing/Screens/ProcurementLotPropertyTest.php`。Modify `tests/Feature/RealEstate/ProcurementLotStatusSyncTest.php`（建売の分）・`tests/Feature/RealEstate/Screens/LandLotDisplayTest.php`（建売の詳細）

**Interfaces:**
- Consumes: Task 2・Task 5
- Produces: 建売の画面へ渡す `procurementLotSelection`（`array{procurementId: ?int, lots: list<…housingLotOptions の形…>}`。入力エラーで戻った画面は `old()`、編集は保存済み）

- [ ] **Step 1: 失敗するテストを書く**
  - `test_the_create_screen_shows_the_lot_select_for_a_land_with_lots`（API の応答を JS に戻すと区画の選択欄に 2 つ・選べる区画が 1 つなら選ばれている）
  - `test_choosing_a_lot_fills_the_land_area_and_the_land_cost`（区画を選ぶ → 土地面積の欄に区画の面積・土地原価の自動の値に按分の値 → 登録で `re_procurement_lot_id` と `land_cost` が保存される）
  - `test_a_taken_lot_is_refused_for_a_new_building`（区画 1 に建売 A（契約なし）→ 建売 B で区画 1 は「区画が取られている」の全文・区画 2 は通る）
  - `test_a_building_with_a_lot_of_another_land_is_refused`（Review Focus 1）
  - `test_a_lot_is_required_on_a_land_with_lots`
  - `test_the_edit_screen_keeps_the_lot_read_only`（決めたこと 2: 区画のラベルと hidden。何も変えずに保存できる）
  - `test_a_legacy_building_without_a_lot_saves_as_is`（Review Focus 4。区画の無い建売の土地に区画を足しても、編集画面をそのまま保存できる）
  - `test_a_building_contract_moves_only_its_lot`（建売の契約の登録 → その区画だけ成約・案件の集約／削除 → 販売中）
  - `test_the_contract_default_land_price_comes_from_the_lot`（決めたこと 1。区画の販売価格が建売の契約の登録画面の土地販売価格の初期値・区画の販売価格が空なら空）
  - `test_auto_land_cost_is_empty_when_a_lot_has_no_price`（Review Focus 5。「計算できません」の文が出て、保存すると空）
  - `test_the_show_screen_shows_the_lot`（`区画 2号地 …㎡（…坪）`）
  - `ProcurementLotStatusSyncTest` の建売のテストを `re_procurement_lot_id` を持つ形に直す
- [ ] **Step 2: 赤を確かめる**
- [ ] **Step 3: 入力チェックと保存** — `re_procurement_lot_id` = `['nullable', 'integer', Rule::requiredIf(土地元が仕入れ案件で requiresLot()), Rule::exists('re_procurement_lots', 'id')->where('procurement_id', 選んだ案件)]`。`after` のフックで `refusal($lot, $current, true)`（建売は契約の前から取る）。`setLandSourceForeignKeys()` は種別が仕入れ案件でなければ区画も null に。`applyAutomaticLandCost()`・`computedLandCost()`・`updateBuilding()` の紐付けに区画を足す。`ContractController` の `updateLotStatusOnSold/Unsold` は `markSold/markOnSale($property->re_procurement_lot_id)` に
- [ ] **Step 4: 画面** — 仕入れ案件の選択欄の下に区画の選択欄（分譲地区画の選択欄と同じ作り・`:selected`・`required` は付けない・区画の無い土地なら隠して `:disabled`）。仕入れ案件を選んだら API（`property_id` つき）から `lots` を受け取り、選べる区画が 1 つならそれを選ぶ。区画を選んだら土地面積と土地原価の自動の値を区画のものに。`savedLink` と `housingLandLinkChanged()` の呼び出しに `procurementLot`。編集画面の読み取り専用の土地の表示に区画のラベルと hidden。show は `procurementLot`
- [ ] **Step 5: 緑を確かめる** — `<テスト> tests/Feature/Housing tests/Feature/RealEstate` → OK
- [ ] **Step 6: Commit** — `feat: 建売で土地の区画を選び、区画ごとの土地原価と 1 区画 1 件にする`（末尾に Co-Authored-By）

---

### Task 7: 注文住宅（登録・編集・ステータスの変更・契約の編集・詳細）

**Files:**
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`（create・store・edit・update・updateStatus・`validateOrder()`・`setLandSourceForeignKeys()`・`computedLandCost()`・`syncLotStatus()`・`releaseLot()`）・`app/Http/Controllers/Housing/HsContractListController.php:312-470`（editCustomOrder・updateCustomOrder）
- Modify: `app/Models/HsCustomOrder.php`（表示の区画）
- Modify: `resources/views/housing/custom-orders/_form.blade.php`・`resources/views/housing/contracts/edit-custom-order.blade.php`・`resources/views/housing/custom-orders/show.blade.php:77-78`
- Test: Create `tests/Feature/Housing/Screens/ProcurementLotCustomOrderTest.php`。Modify `tests/Feature/RealEstate/ProcurementLotStatusSyncTest.php`（注文住宅の分）

**Interfaces:**
- Consumes: Task 2・Task 5・Task 6 の画面の作り
- Produces: なし（最後の持ち主）

- [ ] **Step 1: 失敗するテストを書く**
  - `test_estimate_orders_can_share_an_untaken_lot`（見積りの注文住宅 2 件が区画 1 を持てる）
  - `test_a_contracted_order_takes_the_lot`（契約で登録 → 区画 1 成約・ほかの建売・契約に区画 1 は選べない）
  - `test_an_estimate_order_cannot_newly_choose_a_taken_lot`（決めたこと 4。区画 1 に契約 → 見積りの注文住宅で区画 1 を選ぶ送信は断る）
  - `test_an_estimate_order_keeps_its_lot_after_it_is_taken`（区画 1 を持つ見積りの注文住宅 → 区画 1 に契約 → 注文住宅の編集画面をそのまま保存できる・ステータスを「契約」にする保存は断る）
  - `test_the_popup_refuses_contracted_on_a_taken_lot`（Review Focus 2。一覧の小窓の JS が組んだ PATCH を送る → 422・`{success: false, message: 断りの文}`・alert に文 1 つ（Laravel の errors と二重にしない）・ステータスと区画は変わらない）
  - `test_crossing_contracted_moves_only_its_lot`（小窓で契約へ → その区画だけ成約・設計へ戻す → 販売中・契約以降で削除 → 販売中）
  - `test_the_contract_edit_screen_offers_lots_before_contract`（契約一覧から開く契約の編集: 契約前は区画の選択欄・契約以降は読み取り専用＋hidden（Bug #142））
  - `test_a_legacy_order_without_a_lot_saves_as_is`（Review Focus 4）
  - `test_the_show_screen_shows_the_lot`
  - `ProcurementLotStatusSyncTest` の注文住宅のテストを `re_procurement_lot_id` を持つ形に直す
- [ ] **Step 2: 赤を確かめる**
- [ ] **Step 3: 入力チェックと保存** — `validateOrder()` と `updateCustomOrder()` に Task 6 と同じ規則。`refusal()` の `selfTakes` は「保存するステータスが契約以降か」。`updateStatus()`: 契約以降へ進めるときに `refusal($order->re_procurement_lot_id, $order, true)` が文を返したら、更新せず `response()->json(['success' => false, 'message' => $文], 422)`。`syncLotStatus()`・`releaseLot()` は `markSold/markOnSale($order->re_procurement_lot_id)` に（区画の無い注文住宅は動かさない）
  - ⚠ `updateStatus()` を呼ぶ画面を `grep -rn "custom-orders/' \+\|/status'" resources/views/housing` で全部探し、どれも 422 の `message` を alert に出すことを確かめる（一覧の小窓は今のままで出る）
- [ ] **Step 4: 画面** — Task 6 と同じ作りを `_form` と `edit-custom-order` に（API は `custom_order_id` つき）。`edit-custom-order` の `savedLink` に `procurementLot`。show は `procurementLot`
- [ ] **Step 5: 緑を確かめる** — `<テスト> tests/Feature/Housing tests/Feature/RealEstate` → OK
- [ ] **Step 6: Commit** — `feat: 注文住宅で土地の区画を選び、契約以降は 1 区画 1 件にする`（末尾に Co-Authored-By）

---

### Task 8: 一覧の区画の数・分譲地に変える・1 区画の名残を消す

**Files:**
- Modify: `app/Services/RealEstate/ProcurementListRow.php:50-75`・`app/Services/RealEstate/ProcurementListService.php:72`・`app/Services/RealEstate/ProcurementToProjectConverter.php:118-135`
- Modify: `app/Models/ReProcurement.php`（`lot()` を消す）・`app/Models/ReProcurementLot.php`（`markSoldFor()`・`markOnSaleFor()` を消す）・`app/Http/Controllers/RealEstate/ProcurementLotController.php`（`ONLY_ONE` が残っていれば消す）
- Modify: `docs/ARCHITECTURE.md`（`re_procurement_lots` の説明）
- Test: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php`・`tests/Feature/RealEstate/Screens/ProcurementConversionTest.php`

**Interfaces:**
- Consumes: Task 1 の `lots()`

- [ ] **Step 1: 失敗するテストを書く** — `test_the_list_counts_all_lots`（3 区画のうち 2 成約 → 一覧に `2 / 3`）・`test_conversion_copies_every_lot`（3 区画 → 分譲地の区画 3 つ・号地・面積・坪数・坪単価・ステータス・備考が号地の順で写る・元の区画は消える）
- [ ] **Step 2: 赤を確かめる**
- [ ] **Step 3: 実装** — 一覧は `lots` を読み込んで数える。変える処理は `$locked->lots` を全部写す（写し方は今のまま）。`lot()`・`markSoldFor()`・`markOnSaleFor()` を消す
- [ ] **Step 4: 名残が無いことを確かめる** — `grep -rn -e "->lot\b" -e "->lot()" -e "markSoldFor\|markOnSaleFor\|ONLY_ONE\|1 区画まで\|1 つまで" app resources routes tests` → 0 件（分譲地の `ReContract::lot()`・`'lot'` の読み込みは分譲地の区画なので残る。残るものは 1 件ずつ分譲地かを確かめて実測記録に書く）
- [ ] **Step 5: 緑を確かめる** — `<テスト>`（全件）→ OK。コンパイル済みビューの lint: `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`（PHP 8.3 を名指し）→ INVALID 0
- [ ] **Step 6: Commit** — `feat: 一覧の区画の数と分譲地に変える処理を区画の数だけにし、1 区画の名残を消す`（末尾に Co-Authored-By）

---

### Task 9: 確かめ（変異・実ブラウザ・最後の点検）と記録

- [ ] **Step 1: 変異テスト** — main repo の `.claude/route-coverage-tools/mutate/run_ll.py` と `specs_land_lot.py` を写して `run_lml.py`・`specs_land_multi_lot.py` を作る（作業場所の `.claude/route-coverage-tools/mutate/` に置く。⚠ 作業場所の会話からは main repo の `.claude/` へ書けないので、作業場所を消す前に親の会話が main repo へ写す）。docs/RULES.md Bug #44 の作法（先にコミット・各変異の前に `git status --porcelain` が空・`git diff --stat` で着弾・落ちたテストと理由の 1 行目まで流す前に書く）。当てるもの（カナリアを含む 25 通り前後）:
  - 按分: `share()` の丸めを `floor` に／`$allPriced` の条件を外す／区画 1 つのとき全額でなく按分にする
  - 取られ方: 見積りの注文住宅も取るとみなす／建売を契約の前は取らないとみなす／`$except` を外す（今の区画が選べない）／ステータスで決める
  - 必須: `requiresLot()` の #21 の例外を外す／区画のある案件でも `Rule::unique('re_contracts', 'procurement_id')` を付ける
  - 断り: `refusal()` の `selfTakes` を無視する（小窓で契約へ進める）／区画の所属の確かめ（`exists` の where）を外す
  - 状態: `markSold()` が案件の全区画を動かす／`syncStatusFromLots()` の `Lost` の除外を外す／契約の登録で区画のある案件も販売済にする／契約の削除で区画を戻さない
  - 区画の欄: 号地の重なりの確かめを外す／削除の歯止めを外す／2 つ目からの初期ステータスを `defaultLotStatus()` にする
  - 住宅: `landLink()` に区画を足さない／JS の `housingLandLinkChanged()` で区画を比べない／API で今の持ち主の区画を残さない
  - 画面: 区画の選択欄の `:selected` を外す（構造のテストが拾うか）／区画を選んだときの原価の入れ直しを外す
  - 一覧・変える: 区画の数を 1 区画の前提に戻す／変える処理で最初の区画だけ写す
- [ ] **Step 2: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態）: 区画 3 つの土地で、1 区画を不動産の契約・1 区画を建売・1 区画を注文住宅にする（区画の表・選択欄の選択肢の減り方・原価欄と土地原価の按分・区画と案件の状態）。#21 の形（区画の無い契約がある土地に区画を足し、契約の編集画面をそのまま保存）。375px と 1440px で `main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 3: 最後の点検** — 別の担当（いちばん強いモデル・読み取りだけ）に枝全体を見てもらい、指摘を直してから範囲を絞って再点検
- [ ] **Step 4: 記録** — `docs/BACKLOG.md` に節（直したこと・決めたこと・検証・範囲外）、`CLAUDE.md` の「不動産 仕入れ案件」の行と `docs/ARCHITECTURE.md` の表の説明、この計画の「実測記録」。欠陥を見つけて直したときだけ `docs/RULES.md` に Bug の行を足す（番号は書く直前に最新の 13.x で数える）
- [ ] **Step 5: Commit** — `docs: 土地の区画を複数にした変更の確かめを記録する`（末尾に Co-Authored-By）

---

### Task 10: 本番反映（親の会話が行う。各段で利用者の承認を取る）

- [ ] **Step 1: 13.x の確かめ** — `git merge-base --is-ancestor 13.x HEAD`。進んでいたら枝へ取り込んで全件を流し直す。ほかの会話（ListAgents）に 13.x の早送りと deploy を待ってもらう
- [ ] **Step 2: 本番の読み取り（承認）** — `ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh < 手順ファイル` で `artisan tinker --execute`（php は 8.3 を名指し）: `SHOW CREATE TABLE` の `re_procurement_lots`・`re_contracts`・`hs_properties`・`hs_custom_orders`（索引と外部キーの名前・`re_procurement_lots.id` の型・新しい列が無いこと）／件数だけ: 土地の区画の数・区画のある土地の数・土地に紐づく契約・建売・注文住宅の数（#21 の 1 件を含めて設計書 §5.7 と同じか）／変わるファイルの md5 が今の 13.x と一致／`laravel.log` の日時
- [ ] **Step 3: SQL（承認）** — `database/sql/2026-11-21-re-procurement-lots-multi.sql` を 1 文ずつ（先頭で `re_contracts.procurement_lot_id` があれば止まる）→ 流したあとの `SHOW CREATE TABLE` が SQL のとおりか確かめる
- [ ] **Step 4: 早送り（承認）** — main repo で `git checkout 13.x && git merge --ff-only land-multi-lot`
- [ ] **Step 5: 読み込みの表** — main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3。新しいクラス `LandCostAllocation`・`ProcurementLotClaims` が表に入り、作業場所のパスが 0・`vendor/bin/phpunit` が無い）
- [ ] **Step 6: `./deploy.sh`（承認）**
- [ ] **Step 7: 反映の後の読み取り（承認）** — 送ったファイルの md5 が手元と一致・コンパイル済みビューの lint INVALID 0・ルート `realestate.procurements.lots.*` が 3 本で `….lot.*` が 0 本・`laravel.log` に反映のあとのエラーが無い
- [ ] **Step 8: ログインした画面（承認・見るだけ・送信しない）** — 仕入れ案件の一覧・土地の詳細（区画の表と追加のフォーム）・#21 の契約の編集（区画の選択欄・何も選ばれていない・案内の文）・契約の登録（仕入れ案件の選択肢と注記）・建売と注文住宅の登録（区画の選択欄が区画の無い土地では出ない）。`main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 9: 記録** — BACKLOG の節に「本番反映」の表、計画の実測記録。push は利用者が「push して」と言ったときだけ

---

## 範囲外（設計書 §2 の目標でないこと・§8 と、計画で気づいたもの）

- 区画の表に区画ごとの原価額・粗利額の列を出すこと・土地の区画の図面・分譲地から土地へ戻すこと
- 区画の無い土地の今の動き（建売で売れた区画の無い土地にも不動産の契約を登録できる、など）
- ほぼ同時の 2 件の保存（決めたこと 10）
- 契約一覧（`/realestate/contracts`）に区画を出すこと

## 実測記録

（Task 10 の本番反映はそのときに書き足す。台帳は `.superpowers/sdd/2026-11-21-realestate-land-multi-lot/progress.md`）

### 進め方（2026-11-21）

- subagent-driven（利用者の選択）。Task ごとに実装の担当 → 別の点検の担当。Task 2〜4・6・7 は実装・点検とも opus、Task 1・5・8 は sonnet
- Task 9 は 1 つの担当にせず、変異 → 実ブラウザ → 最後の点検（直しの波・範囲を絞った点検）→ 記録の順に別々に出した（Ruling 13。変異は作業ツリーを書き換えるので実ブラウザと同時に動かさない）

### Task 0

- 13.x（`37a96107`）は HEAD（`00995aeb`）の祖先 → 取り込み不要
- vendor: main repo から `cp -a` → `composer install --no-scripts`（PHP 8.3）→ `vendor/bin/phpunit` あり・作業ツリーは空
- 着手前の全件: OK (5476 tests, 60164 assertions)・5:11・ピーク 175.50 MB（PHP 8.3・OPcache あり）
- 単一区画を前提にしているファイル（Step 4 の grep）: app 8 本（`Housing/ContractController`・`Housing/CustomOrderController`・`RealEstate/ProcurementController`・`ProcurementLotController`・`ReContractController`・`ReProcurementLot`・`ProcurementListRow`・`ProcurementToProjectConverter`）／ views 6 本（`housing/custom-orders/show`・`housing/properties/show`・`realestate/contracts/{create,edit,show}`・`realestate/procurements/_lot_section`）／ tests 13 本（一部は分譲地の `$this->lot()` に当たっているだけ）

### Task 1〜8（コミット・テスト・点検）

| Task | コミット | テスト | 点検 |
|---|---|---|---|
| 1 表と列 | `bd4e9608` | `ProcurementLotModelTest` OK (13 tests, 55 assertions)・RealEstate＋Housing OK (926 tests, 15252 assertions) | Approved（Minor 2）。⚠ 本番の `re_contracts`・`hs_properties`・`hs_custom_orders` の DDL がリポジトリに無く、SQL の `AFTER` の列名を確かめられない → Task 10 Step 2 で確かめる |
| 2 部品 | `35a7bc4f`・直し `65c9647d` | RealEstate＋Housing＋Unit OK (1946 tests, 21108 assertions)・全件 OK (5495 tests, 60287 assertions) | Needs fixes（Important 1: `refusal()` の「保存済みの持ち主が区画を変える・selfTakes=false」の枝をテストが通っていない）→ テストを足し、変異（`$changed` を縮める）で赤を確かめた → clean |
| 3 区画の欄 | `5a81bcc2` | 全件 OK (5503 tests, 60568 assertions)・ビュー 327 本 / INVALID 0 | Approved（Critical・Important 0） |
| 4 不動産の契約 | `2c2c1995` | 全件 OK (5516 tests, 61260 assertions)・ビュー 327 本 / INVALID 0 | Approved（Ruling 6 は妥当） |
| 5 住宅事業の土台 | `d9fff13c` | Housing＋RealEstate＋Unit OK (1972 tests, 22153 assertions) | Approved |
| 6 建売 | `049a93fd` | 全件 OK (5536 tests, 62337 assertions)・ビュー 327 本 / INVALID 0 | Approved（担当の自前の変異 18 通り＋カナリアで緑 2 → テストを足して赤） |
| 7 注文住宅 | `bbf6cad5`・`95660006`・`964aa6fe`・直し `88d279f5`・`66a5f9a8` | 全件 OK (5557 tests, 64733 assertions)・直しのあと Housing＋RealEstate OK (1002 tests, 20380 assertions) | Needs fixes（Important 1: 土地が売り切れて仕入れ案件が販売済になると、見積りの注文住宅〈と区画の無い古い注文住宅〉を編集画面からそのまま保存できない。テストは `ReContract::create` だけで `markSold` を通らず見逃した）→ `formOptions()` が今の仕入れ案件をステータスに関わらず残す・実際の契約の画面で売り切れにするテスト → clean。担当の自前の変異 26 通り＋カナリアで緑 4 → テストを足して赤 |
| 8 一覧・変える・名残 | `7c3b79e1` | 全件 OK (5562 tests, 65330 assertions)・ビュー 327 本 / INVALID 0 | Approved |

- Task 8 Step 4 の grep の残り（どれも区画の 1 区画の名残ではない）: `realestate/contracts/show.blade.php:204-205` の `$contract->lot`（分譲地の区画 `ReContract::lot()`）／ テストの `$this->lot(…)`（テストのヘルパーのメソッド）／ `ParsesSortLinksTest.php:70` の「1 つまで」（関係のないコメント）／ `ProcurementLotScreensTest` の 2 か所（`ONLY_ONE` と「1 つまで」の文が**無いこと**を固定するテスト）。app の `->lot`・`markSoldFor`・`markOnSaleFor`・`ONLY_ONE` は 0 件
- Task 3 の grep（「1 つまで」「2 つ目」）の残り 2 件は `app/Support/SurveyQuestionForm.php` の「2 つ目以降」（アンケートの選択肢の話）

### 台帳の決めごと（Ruling）

| # | 中身 |
|---|---|
| 1 | Task 3 の hidden `lot_form` に和名「区画のフォーム」（和名の走査が求めた） |
| 2 | 区画が見つからない（ほかの案件の区画を含む）ときは詳細 `#lot` へ `NOT_FOUND`、案件も無ければ一覧へ `GONE`（決めたこと 6） |
| 3 | 区画の削除の確認の文を「区画を削除しますか？ 登録した面積・販売価格などが消えます。」に（Task 8 で直した） |
| 4 | 区画のある土地の区画の無い契約（#21）の登録・更新・削除は区画を動かさず `syncStatusFromLots()` で集約 |
| 5 | 区画の自動の選択は `onProcurementChange`（利用者の操作）のときだけ。区画の無い契約の編集画面では自動で選ばない |
| 6 | 区画のある土地で原価の API が返ったあとの原価欄は「選んだ区画の按分した原価・区画が無ければ空」（全額を残さない） |
| 7 | #21 の形の契約を消す・付け替えると、成約で作られた区画が成約のまま残りうる。設計書 §5.4 のとおりとし、直し方（区画の欄で販売中にする）を利用者に伝える |
| 8 | 建売（と注文住宅）の登録画面の仕入れ案件の選択肢から、全区画が取られた土地を外す |
| 9 | 区画が要るのにまだ選んでいない間は「計算できません」を出さない |
| 10 | 「計算できません」の文の理由を「分譲地・土地の全区画に販売価格が入っていない／仕入れ案件に原価が無い」に |
| 11 | 「取られている」で断られて戻った画面に、取られた区画を選んだまま描かない・その区画の土地原価を出さない（建売・注文住宅） |
| 12 | 注文住宅の「取られている」の断りは「契約」へ進むときと、区画を新しく選ぶ・変えるときだけ |
| 13 | Task 9 を変異 → 実ブラウザ → 最後の点検 → 記録に分けて出す |
| 14 | 不動産の契約の登録画面の `init` にも Ruling 11 と同じ直し（最後の点検の I2 で編集画面にも） |
| 15 | 契約と建売の `refusal(…, true)` を `false` に（何も変えない保存は断らない。新しく選ぶ・付け替えるときは今どおり断る）。計画の指定から変えた |
| 16 | 最後の点検の直しの波の範囲 = I1・I2・M1・M2（Ruling 15）・M5 ＋テスト（V1c・V2b/V2d・付け替えのステータス・Ruling 4 の建売・231 行目の `?? null`）。M3・M4・M7 は先送りして利用者に伝える。M6 は記録で |
| 17 | 直しの波の JS は node の道具でしか見ていないので、契約の登録・編集画面だけ実ブラウザで確かめ直す |
| 18 | `docs/RULES.md` に Bug の行は足さない（見つけて直した欠陥はどれもこの枝の新しいコードの中で、本番の欠陥ではない） |

### Task 9 Step 1 変異（`7c3b79e1`）

- 道具: 作業場所の `.claude/route-coverage-tools/mutate/run_lml.py`（main の `run_ll.py` の写し。SURVIVED・DIFF・MATCH(at_least) の区別・`--dry`・変異ごとの秒数・`finally` で戻す）・表 `specs_land_multi_lot.py`・結果 `results-land-multi-lot.{json,txt}`（1 回目 `.round1.*`・Step 1 の最終 `.round2.*`・直しの波のあと `.round3.*`）。main repo の同じ場所へ写した（JUnit の `lml-*.xml` は写していない）
- 流したテスト: `tests/Feature/RealEstate`・`tests/Feature/Housing`・`tests/Unit` ＋ 走査 8 本（OldInputRuleCoverage・JapaneseValidationMessages・LongUnbrokenValueWrap・MobileLayout・NumberInputStepScan・JsEchoClassification・JsStringEchoScan・AjaxErrorFeedback）。基準 OK (2117 tests, 27190 assertions)・99 秒
- 33 通り（計画の 32 ＋ V2d）: C0（カナリア。号地の重なりのテスト 1 本だけが落ちた＝作業場所のコードを読んでいる）・按分 A1〜A3・取られ方 T1〜T4・必須 R1・R2・断り D1・D2・D2b・状態 S1〜S4・区画の欄 L1〜L3・住宅 H1〜H3・画面 V1a〜V1d・V2a〜V2d・一覧と変える G1・G2
- 結果: MATCH 30（そのまま 21・at_least 9）／ DIFF 0（1 回目の T1・T4 は期待の理由の書き違い → 直して流し直し MATCH。T4 はテストの 231 行目が `?? null` なしで読む脆さで別の文で落ちていた）／ **SURVIVED 3** ／ 等価 0。時間は計 約 46 分
- 生き残った変異: V1c（契約の編集画面の区画の選択肢の `:selected` を消す。構造のアサートは登録画面だけ・実駆動の道具は x-model の値をそのまま選ぶ）／ V2b・V2d（契約の編集画面の `applyLot()` で原価・土地の契約金額を入れ替えない。編集画面で区画を変えるテストが同じ販売価格の区画どうしだった）→ テストを足さずに記録し、最後の点検の直しの波にまとめた

### Task 9 Step 2 実ブラウザ（`7c3b79e1`）

- 環境: 使い捨ての SQLite（接続先を確かめてから migrate）・`artisan serve`（127.0.0.1:8091・PHP 8.3）・作業場所で `vite build`（CSS の名前は本番と同じ `app-o1yvrcrl.css`＝新しい Tailwind のクラスは無い）・ログイン画面からログイン・Playwright（画面が見えている状態）
- 土地 A: 原価合計 30,000,000・区画 1号地 132.69㎡ 20,000,000 / 2号地 100.00㎡ 10,000,000 / 3号地 110.50㎡ 10,000,000。土地 B: 区画の無い不動産の契約 1 件（#21 の形）
- 結果はどれも OK:
  - 1a 不動産の契約（1号地）: 選択肢 3 つ・1号地を選ぶと土地の契約金額 20,000,000・原価 15,000,000。詳細に「この区画の按分: 販売価格 20,000,000円 ÷ 全区画の販売価格の合計 40,000,000円 → 15,000,000円」・食い違いの警告なし。区画の表の紐づいているものにリンク
  - 1b 建売（2号地）: 選択肢は 2・3号地。2号地で土地面積 100・土地原価 7,500,000
  - 1c 注文住宅（3号地・契約）: 土地面積 110.5・土地原価 7,500,000。一覧「区画 2 / 3」・仕入れ案件は販売中
  - 1d 全区画が取られると 3 つの登録画面の仕入れ案件の選択肢から土地 A が消える
  - 1e 建売の契約: 土地販売価格の初期値 10,000,000（2号地の販売価格）→ 2号地が成約で仕入れ案件が**販売済**・一覧「区画 3 / 3 販売済」
  - 2 #21 の形: 1 つ目の区画の初期値は号地 1・面積 300.00・販売価格 18,000,000・成約、2 つ目は号地 2・空・販売中。区画を 2 つ足すと仕入れ案件は販売済 → 販売中。契約の編集画面は未選択で案内が出て、何も変えずに保存できる（区画は空のまま）
  - 3 V1c・V2b/V2d は画面では正しく動いた（保存済みの区画が選ばれる・選び直すと金額と原価が入れ替わる・付け替えで区画の状態が付いてくる）
  - 4 古い画面から取られた区画を送ると全文で断る（契約・建売）
  - 5 16 画面 × 1440・375px の 32 通りで `main.scrollWidth === main.clientWidth`・自社への 4xx/5xx 0・コンソールのエラー 0（警告は Google Maps の API キーが無いことだけ）
- 懸念 1 つ（表示だけ・誤保存なし）: 不動産の契約の登録で「取られている」で断られて戻ると、Alpine の `procurementLotId` は取られた区画のまま・原価と土地の契約金額もその区画の値のまま → Ruling 14。最後の空き区画が取られると仕入れ案件の選択欄も空（この枝の前の選択肢の形）→ 後回し
- 片付け済み（サーバ停止・symlink・セッション・コンパイル済みビュー・scratchpad）・`git status --porcelain` 空

### Task 9 Step 3 最後の点検と直しの波

- 最後の点検（いちばん強いモデル・読み取りだけ・`37a96107..7c3b79e1`。台帳の minor・survivor・concern の振り分けも頼んだ）: 「直してからマージ」・Critical 0・Important 2・Minor 7
  - I1 土地を選び直すと、前の区画が入れた「土地の契約金額」が残る（登録・編集）
  - I2 Ruling 14 の直しは編集画面の `init` にも要る（#21 の契約の編集では、案内が出たまま取られた区画の金額と原価で保存できうる）
  - M1 `procurementChoices()` に「今の区画は残す」が無い ／ M2 契約と建売の `refusal(…, true)`（→ Ruling 15）／ M3 区画が 2 つ以上の土地を手で販売済にすると戻せない ／ M4 登録画面の原価の箱が全額 ／ M5 SQL の頭の注記 ／ M6 記録 ／ M7 #21 の土地の成約で作られた区画（Ruling 7）
- 直しの波（Ruling 16・FIX_BASE `7c3b79e1`・5 コミット）: `a901b2d3`（I1・I2。`autoLandAmount`・`autoCostAmount`・`clearAutoAmounts()`・初めの値はサーバの `procurementLotAuto()`）・`acb0942c`（M1・M2）・`63444716`（V1c〈`:selected` の走査を不動産の契約の登録・編集と注文住宅の契約の編集へ。拾う選択欄 8 → 13〉・V2b/V2d・区画の無い土地 ↔ 区画のある土地の付け替えのステータス・Ruling 4 の建売・231 行目）・`4e5669f2`（M5）・`ac5af1b4`（土地を替えた直後に前の区画の金額が残らないこと＝変異 I1b を拾う）
  - 直した項目は直す前に赤を確かめた（I1: 登録 '25000000'・編集 '20000000' が残った ／ I2: x-model の値が選択肢に無い ／ M1: 選択肢 [2]〈期待 [1, 2]〉）。直す前から緑のテストの穴は手で変異を当てて赤を確かめて戻した
  - 全件 OK (5576 tests, 66056 assertions)（PHP 8.3.36・4 分 13 秒・ピーク 179.50 MB・警告なし。+14）・ビュー 327 本 / INVALID 0
  - 変異 round 3（14 通り: C0・V1c・V2b・V2d・I1a・I1b・I1c・I2a・I2b・M1a・M2a・M2b・M2g・D1）すべて MATCH（M2g は MATCH(at_least)。try 1 は M2g の理由の文だけ DIFF＝契約どうしは入力チェックを素通りして一意の索引で 500〈決めたこと 10 の形〉→ 理由を直して MATCH）。V1c・V2b・V2d は SURVIVED → MATCH
- 範囲を絞った点検: I1・I2・M1・M2・M5・テストの穴すべて addressed・新しい Critical・Important なし。新しく出た Minor 3 つ（区画を「— 区画を選択 —」に戻すと金額・原価が空・自動の値かは値が同じかで決める・`procurementLotAuto()` で問い合わせが 3 本増える）は残した
- 自動のセキュリティ点検（コミットの点検・MEDIUM）: `CustomOrderController::updateStatus` の「取られているか」の確かめと保存のあいだの隙間（ほぼ同時の 2 つの保存で 1 区画に 2 件）→ 決めたこと 10 の範囲。直さず利用者に伝える
- 直しのあとの実ブラウザ（`ac5af1b4`・Ruling 17・不動産の契約の登録と編集だけ・127.0.0.1:8092）: 5 項目すべて OK — I1 登録（1 区画の土地で 25,000,000 / 30,000,000 → 2 区画の土地に替えると区画・金額・原価とも空・手で打った 21,000,000 は残り原価だけ空）／ I2 登録（断られて戻ると区画 ''・金額と原価は空・選択肢は区画 2・3 だけ）／ I2 編集（#21: 区画 2 を選び建売に取られて断られる → 戻った画面は区画・金額・原価とも空・案内は出たまま・そのまま送ると「契約額（土地）は必須です。」「原価は必須です。」で止まり DB は区画なし・20,000,000 / 20,000,000 のまま）／ 編集をそのまま（区画 1 → 2 → 1 で 20,000,000 / 15,000,000 → 10,000,000 / 7,500,000 → 20,000,000 / 15,000,000・保存で DB は変わらない）／ 2 画面 × 1440・375px で横スクロール 0・コンソールのエラー 0。片付け済み

### Task 9 Step 4 記録

- `docs/BACKLOG.md` に節（本番未反映）と完了状況の 1 行・`CLAUDE.md` の「不動産 仕入れ案件」の行を「土地の区画〈複数〉」に・`docs/ARCHITECTURE.md` の `re_procurement_lots` の行に区画のステータスと削除の決まり（Task 8 で直した説明に足した）。`docs/RULES.md` は足さない（Ruling 18）
