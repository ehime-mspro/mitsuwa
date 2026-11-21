# 区画のある土地のステータスを区画で決める Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 区画のある土地の仕入れ案件を手で「販売済」にして戻せなくなる件を直し、手でのステータス変更が区画と食い違わないようにする。

**Architecture:** 判定は `ReProcurement` の 1 か所（`statusRefusal()`）に置き、区画のある案件は区画の成約状況で、区画の無い案件は今の Bug #139 の契約の歯止めで断る文を返す。一覧の小窓（`updateStatus`）と編集画面（`update`）は今と同じ 1 つの入力チェックの規則からそれを呼ぶ。画面は変えない。

**Tech Stack:** Laravel 12 / PHP 8.3 / PHPUnit（SQLite）/ 画面の JS は node の vm で動かす既存の道具（`DrivesAlpineFetch`）

**Spec:** `docs/superpowers/specs/2026-11-21-procurement-lot-status-lock-design.md`

## Global Constraints

- 区画のある仕入れ案件（`re_procurement_lots` に 1 行以上）だけ決まりを変える。区画の無い案件は Bug #139 のまま（今のテストが守る）
- 「成約」は区画の `status === LotStatus::Sold`（`syncStatusFromLots()` と同じ数え方）
- 今と同じステータスへの変更は通す（Bug #130 の形を作らない）
- 断る文（全文・この字のまま）:
  - 成約でない区画があるのに「販売済」へ: `区画のある土地は、すべての区画が成約になると自動で「販売済」になります（成約でない区画が N 件あります）。`（N は成約でない区画の数）
  - 全区画が成約なのに「販売済」以外へ: `すべての区画が成約のため、ステータスは「販売済」にしかできません。区画を成約から戻すと、自動で「販売中」に戻ります。`
- 画面（Blade・JS）・DB・ルートは変えない。新しい PHP クラスは作らない（本番反映は `./deploy.sh` だけ）
- テストは CLAUDE.md の流し方（`APP_KEY` を環境変数・PHP 8.3 を名指し・OPcache あり）。データプロバイダは `#[DataProvider]` の属性で書く（doc-comment の `@dataProvider` は PHPUnit の非推奨の警告になる）
- `git stash` を使わない（作業場所どうしで共有される）

## Review Focus

- 今戻せなくなっている形（成約でない区画があるのに「販売済」）を「販売中」へ戻せること → Task 1 の `test_the_list_takes_a_stuck_land_back_to_selling`
- 区画と食い違った古いデータでも、ステータスを変えずに編集画面をそのまま保存できること → Task 1 の `test_the_edit_screen_saves_a_land_with_lots_as_it_is`（2 つの形）
- #21 の形（区画の無い契約がある土地に、紐付けの無い「成約」の区画）で、手で「販売中」に戻して 2 件目の契約の道を開けないこと → Task 1 の全区画が成約のテストは、契約を区画に紐づけずに作る
- 区画のある土地には Bug #139 の歯止めを使わないので、契約があっても「販売済」以外のあいだは自由に動かせること → Task 1 の `test_a_land_with_an_unsold_lot_moves_freely_between_other_statuses`
- 区画の無い案件の動きが変わらないこと → 既存の `ProcurementContractLockTest` の 11 本がそのまま緑

---

### Task 0: 作業場所を整える

- [ ] **Step 1:** 作業場所 `/Users/masanori/site/manage/.claude/worktrees/lot-status-lock`（枝 `lot-status-lock`・13.x = `410db928` から）で `cp -a /Users/masanori/site/manage/vendor ./vendor && composer install --no-scripts --no-interaction`（dev 込み。`.env` は作らない）
- [ ] **Step 2:** `ProcurementContractLockTest` を流して緑を確かめる

Run: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit tests/Feature/RealEstate/Screens/ProcurementContractLockTest.php`
Expected: `OK (11 tests, …)`

---

### Task 1: 区画のある土地のステータスを区画で決める

**Files:**
- Modify: `app/Models/ReProcurement.php`（`refusesStatus()` を `statusRefusal()` に替える・断る文の定数 2 つ）
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php`（`statusLockedByContract()` を `statusLockRule()` に改名し `statusRefusal()` の文で `$fail`。呼ぶ所 2 つ: `updateStatus` と `validateProcurement`）
- Test: `tests/Feature/RealEstate/Screens/ProcurementContractLockTest.php`（節「区画のある土地」を足す）

**Interfaces:**
- Produces: `ReProcurement::statusRefusal(string $status): ?string` — 断る文か null。`ReProcurement::STATUS_SOLD_NEEDS_ALL_LOTS`（`%d` を含む文。`sprintf` で N を入れる）・`ReProcurement::STATUS_STAYS_SOLD_WHILE_ALL_LOTS_SOLD`・既存の `STATUS_LOCKED_BY_CONTRACT` はそのまま
- 消す: `ReProcurement::refusesStatus()`（呼ぶのはコントローラの 1 か所だけ。2026-11-21 に `grep -rn refusesStatus app resources tests` で確かめた）

- [ ] **Step 1: テストを書く（`ProcurementContractLockTest` に節「区画のある土地」）**

道具の手直し: `chooseOnList(string $to, ?array $response = null, string $listedAs = 'sold')` — 一覧の絞り込みを今のステータス（`$listedAs`）にする（既定は今と同じ `sold`）。

土台（このテストクラスの private）:
- `landWithLots(string $status, array $lotStatuses, bool $contractOnLot = true): ReProcurement` — `landProcurement(['status' => $status])` に、`$lotStatuses`（例 `['sold', 'on_sale']`）の順で 1 号地から区画を作る（`procurementLot()`）。1 つ目の区画に契約を 1 件付ける（`$contractOnLot` が false なら区画に紐づけない契約＝#21 の形）。契約は `contractFor()` に `procurement_lot_id` を足した形

テスト（文は Global Constraints の全文を直に書く）:

```php
// 一覧の小窓
test_the_list_refuses_to_set_a_land_with_an_unsold_lot_to_sold
    // landWithLots('selling', ['sold', 'on_sale']) → chooseOnList('sold', null, 'selling') を送ると 422
    // alerts === ['区画のある土地は、すべての区画が成約になると自動で「販売済」になります（成約でない区画が 1 件あります）。']
    // evaluated === ['selling']・DB の status は selling のまま
test_the_list_takes_a_stuck_land_back_to_selling
    // landWithLots('sold', ['sold', 'on_sale'])（直す前は戻せなかった形）→ chooseOnList('selling') が 200・DB は selling

// 編集画面（描いた画面のフォームを送る。帯の <li> の全文で見る＝assertErrorItem）
test_the_edit_screen_refuses_to_set_a_land_with_an_unsold_lot_to_sold
    // landWithLots('selling', ['sold', 'on_sale', 'unsold']) → status=sold → 「…（成約でない区画が 2 件あります）。」・DB は selling
#[DataProvider('leavingSoldWhileAllLotsSold')]
test_the_edit_screen_keeps_a_land_whose_lots_are_all_sold_on_sold(string $from, string $to)
    // landWithLots($from, ['sold', 'sold'], contractOnLot: false) → status=$to → 「すべての区画が成約のため、…」・DB は $from のまま
    // provider: 'sold→selling' => ['sold', 'selling'], 'sold→lost' => ['sold', 'lost'], 'lost→selling' => ['lost', 'selling']
test_the_edit_screen_sets_a_land_whose_lots_are_all_sold_to_sold
    // landWithLots('settled', ['sold', 'sold']) → status=sold → 成功の帯「仕入れ案件「RE-PRC-001」を更新しました。」・DB は sold
#[DataProvider('landsOutOfStepWithTheirLots')]
test_the_edit_screen_saves_a_land_with_lots_as_it_is(string $status, array $lotStatuses)
    // そのまま送る → 成功の帯・DB の status は $status のまま
    // provider: 'sold with an unsold lot' => ['sold', ['sold', 'on_sale']], 'selling with all lots sold' => ['selling', ['sold', 'sold']]
test_a_land_with_an_unsold_lot_moves_freely_between_other_statuses
    // landWithLots('selling', ['sold', 'on_sale']) → status=negotiating → 成功の帯・DB は negotiating（Bug #139 の歯止めは区画のある土地に使わない）
```

- [ ] **Step 2: 赤を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/RealEstate/Screens/ProcurementContractLockTest.php`
Expected: 新しいテストのうち、直す前のコードで決まりが違うものが赤（`test_the_list_takes_a_stuck_land_back_to_selling` は 422・`STATUS_LOCKED_BY_CONTRACT`、「販売済」へ断るテストは 200 で通ってしまう、など）。`test_the_edit_screen_sets_a_land_whose_lots_are_all_sold_to_sold`・`…saves_a_land_with_lots_as_it_is` は直す前から緑（直しすぎを止める守り）。既存の 11 本は緑

- [ ] **Step 3: `ReProcurement::statusRefusal(string $status): ?string` を書く（`app/Models/ReProcurement.php`）**

区画の status を 1 回読み（`ReProcurementLot::where('procurement_id', $this->id)->get(['status'])`）、次の順で返す:

```
今と同じ                              → null
区画が 0                              → 契約があり、$status が「販売済」でなければ STATUS_LOCKED_BY_CONTRACT（今の Bug #139）、ほかは null
全区画が成約で $status ≠ 販売済        → STATUS_STAYS_SOLD_WHILE_ALL_LOTS_SOLD
成約でない区画があり $status = 販売済   → sprintf(STATUS_SOLD_NEEDS_ALL_LOTS, 成約でない区画の数)
それ以外                              → null
```

docblock は今の `refusesStatus()` の注記（Bug #139・Bug #130 の形）を引き継ぎ、区画のある案件の決まりと、Bug #139 を使わない理由（2 件目は `ProcurementLotClaims` と一意の索引が防ぐ・全区画が成約なら販売済に留める）を書く。`refusesStatus()` を消す。

- [ ] **Step 4: コントローラの規則を替える（`ProcurementController.php`）**

`statusLockedByContract()` → `statusLockRule(?ReProcurement $procurement): \Closure`。`$procurement !== null && is_string($value)` のとき `statusRefusal($value)` の文が null でなければ `$fail(その文)`。呼ぶ 2 か所の名前を替える（`bail` はそのまま）。docblock は「区画のある土地は区画で決める・無い案件は Bug #139」に直す。

- [ ] **Step 5: 緑を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/RealEstate/Screens/ProcurementContractLockTest.php` → `OK`
Run: `… vendor/bin/phpunit tests/Feature/RealEstate` → `OK`（区画・契約・一覧のテストが巻き込まれていない）

- [ ] **Step 6: コミット**

```bash
git add app/Models/ReProcurement.php app/Http/Controllers/RealEstate/ProcurementController.php tests/Feature/RealEstate/Screens/ProcurementContractLockTest.php
git commit -m "fix: 区画のある土地の仕入れ案件のステータスを区画で決める

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: 全件・変異・記録

**Files:**
- Modify: `docs/RULES.md`（Bug #145 の行。Bug #139 の行の末尾に「→ 区画のある土地は Bug #145」の 1 行）
- Modify: `CLAUDE.md`（「全 144 件」「Bug #1–144」を 145 に）
- Modify: `docs/BACKLOG.md`（新しい節・「土地の仕入れ案件に区画を複数登録できるようにする」の節の「利用者に伝えること」の 2 つ目に →・「バックログ完了状況」の段落）
- Modify: この計画の末尾に「実測記録」

- [ ] **Step 1: 全件を流す**

Run: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit`
Expected: `OK (5576 + 足した本数 tests, …)`・警告なし

- [ ] **Step 2: 変異を流す**（先にコミット・各変異の前後で `git status --porcelain` が空・置き換えがちょうど 1 か所・落ちたテストと理由の 1 行目を記録。流すのは `ProcurementContractLockTest` と `tests/Feature/RealEstate`）

| # | 変異 | 期待（落ちるテスト） |
|---|---|---|
| C0 | カナリア: `STATUS_SOLD_NEEDS_ALL_LOTS` の文を 1 字変える | 「販売済」へ断る 2 本 |
| L1 | 区画の分岐を外す（区画があっても今の Bug #139 だけで判定） | stuck・自由に動く・「販売済」へ断る 2 本・lost→selling |
| L2 | 「今と同じなら通す」を外す | そのまま保存の 2 データ（＋既存の Bug #139 のそのまま保存） |
| L3 | 全区画が成約の判定を「1 つでも成約」に | 「販売済」へ断る 2 本・stuck ほか |
| L4 | 全区画が成約のとき「販売済」以外を通す | all sold の 3 データ |
| L5 | 成約でない区画があっても「販売済」を通す | 「販売済」へ断る 2 本 |
| L6 | N を区画の総数にする | 「販売済」へ断る 2 本（1 件 → 2 件・2 件 → 3 件） |
| L7 | 区画のある土地にも Bug #139 の歯止めを足す | stuck・自由に動く |
| L8 | コントローラの規則を `updateStatus` から外す | 一覧の小窓の 2 本＋既存の一覧の Bug #139 |

緑のまま通った変異があれば、テストを足して赤を確かめてから記録する。

- [ ] **Step 3: 記録を書いてコミット**（RULES の Bug #145 の行は「症状・原因・直し方・テスト・変異」の形。BACKLOG の節は「直す前」「直したこと」「検証」「範囲外」「本番反映の手順（`./deploy.sh` だけ）」）

```bash
git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-11-21-procurement-lot-status-lock.md
git commit -m "docs: 区画のある土地のステータスを区画で決めた変更を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

## 範囲外（設計書のとおり）

分譲地の手でのステータス変更・画面の選択肢を隠すこと・#21 の形の最初の区画が「成約」で作られること・実ブラウザの確認（画面を変えないので行わない。一覧の小窓の JS は node の vm で動かして確かめる）

## 実測記録（2026-11-22）

- Task 0: vendor を `cp -a` ＋ `composer install --no-scripts`。`ProcurementContractLockTest` → `OK (11 tests, 328 assertions)`
- Task 1: 足したテスト（7 メソッド・10 本）を直す前のコードで流すと 7 本が赤（`Failures: 7`）。理由は一覧の小窓が 200（販売済へ断るはず）／422（戻せるはず）、編集画面は帯に新しい文が無い・成功の帯が無い。直しすぎを止める 3 本は緑。直したあと `OK (21 tests, 712 assertions)`・不動産 `OK (677 tests, 10675 assertions)`（`558007f1`）
- Task 2: 全件 `OK (5586 tests, 66440 assertions)`（3 分 58 秒・ピーク 175.50 MB・`--display-phpunit-deprecations` で警告 0）
- 変異（不動産のテストで流す・置き換えはちょうど 1 か所・前後で作業ツリーが空）:

| # | 変異 | 落ちたテスト（すべて期待どおり） |
|---|---|---|
| C0 | 「販売済」へ断る文を 1 字変える | 一覧の小窓・編集画面の「販売済」へ断る 2 本 |
| L1 | 区画の分岐を外す | 7 本（販売済へ断る 2・stuck・全区画が成約の 3 データ・自由に動く）※1 回目は置き換え先が `syncStatusFromLots()` にもあり 2 か所に当たって当てずに止まった＝無効な測定。狙う行を絞って流し直した |
| L2 | 「今と同じなら通す」を外す | そのまま保存の 2 データ＋既存の Bug #139 の `saves_an_inconsistent_old_procurement_as_it_is` |
| L3 | 全区画が成約の判定を「1 つでも成約」に | 販売済へ断る 2・stuck・自由に動く |
| L4 | 全区画が成約のとき販売済以外も通す | 全区画が成約の 3 データ |
| L5 | 成約でない区画があっても販売済を通す | 販売済へ断る 2 本 |
| L6 | N を区画の総数にする | 販売済へ断る 2 本 |
| L7 | 区画のある土地にも Bug #139 の歯止めを足す | stuck・全区画が成約の 3 データ・自由に動く |
| L8 | 一覧の小窓の規則を外す | 一覧の小窓の 2 本（Bug #139 の既存 1・新しい 1） |
| L9 | 区画の無い案件の「販売済へは通す」を外す | 既存の Bug #139 の `sets_an_inconsistent_old_procurement_back_to_sold` |

緑のまま通った変異は 0（テストは足していない）。
