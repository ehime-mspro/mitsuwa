# 契約登録時に買主の顧客ランクを自動で「成約」にする — 設計書

作成日: 2026-08-04
対象: 不動産管理の契約 + 住宅事業の契約（建売・注文住宅）

## 1. 背景

顧客ランク（`buyer_departments.rank`）に `contracted`（成約）という値は最初から用意されているが、
**契約を登録してもランクは一切変わらない**。`ReContractController::store()` は区画を「販売済」に、
仕入れ案件を「販売済」に自動遷移させているのに、買主のランクだけ手つかずだった。
バグではなく未実装（初期実装以来ずっと）。

住宅事業（建売契約・注文住宅）も同じく顧客マスタ（`buyers`）と紐づいているが、
同様にランクは変わらない。ユーザー判断により今回まとめて対応する。

## 2. 決定事項（ユーザー確認済み）

| # | 論点 | 決定 |
|---|---|---|
| 1 | 対象部署にランク行が無い顧客（例: 住宅事業だけに登録された顧客を不動産契約の買主に選んだ） | **その部署を自動追加して成約にする**（取得日＝契約日） |
| 2 | 契約の買主を差し替えた／契約を削除した時の「元の買主」 | **成約のままにする**（手動で戻せる） |
| 3 | 既に登録済みの契約（過去分） | **一括更新はしない。今後の登録分のみ** |
| 4 | 住宅事業も同じ仕様にするか | **する**（建売契約・注文住宅とも対象） |

## 3. 発火条件

| 対象モデル | 部署 | 発火タイミング |
|---|---|---|
| `ReContract`（不動産契約） | `re_contracts.department`（現状は常に `realestate`） | `buyer_id` が入っている状態で保存されたとき |
| `HsContract`（建売契約） | `housing` | `customer_id` が入っている状態で保存されたとき |
| `HsCustomOrder`（注文住宅） | `housing` | `customer_id` があり、**かつ status が「契約」以降**のとき |

いずれも「保存されたら毎回」ではなく、3.3 の再発火抑制条件を併せて満たしたときだけ発火する。

### 3.1 仲介は対象外

`ReContractType::Brokerage` は契約フォームで買主欄が `:disabled`、`validateContract()` にも
`buyer_id` ルールが無く、成約処理（`close()`）も `buyer_name`（自由入力の文字列）を受け取るだけ。
**顧客マスタと構造的に繋がっていないため自動化できない。**
「`buyer_id` が入っているときだけ発火する」という条件により、仲介は自然に対象外になる。

### 3.2 ⚠ 注文住宅だけ「登録時」ではない

`hs_custom_orders` は `商談 → 設計 → 見積り → 契約 → 着工 → 完成 → 引渡し` と進む**案件レコード**で、
**商談段階でも登録できる**。登録＝契約ではないので、登録時に成約へ変えると
まだ商談中の見込み客が成約扱いになる。

判定には既存の `CustomOrderStatus::isContractedOrLater()` を使う。
これは分譲地区画のステータス連動（`CustomOrderController::syncLotStatus()`）が
既に使っている判定と同一で、**「契約以降なら区画は販売済」と足並みが揃う**。

結果として、注文住宅は**一覧のステップバーでステータスを「契約」に進めた時**にも発火する。

### 3.3 再発火の抑制

条件は「保存された」だけでなく `wasRecentlyCreated || wasChanged(...)` を併せて見る。

- `ReContract` / `HsContract`: `wasRecentlyCreated || wasChanged('buyer_id' | 'customer_id')`
- `HsCustomOrder`: `wasRecentlyCreated || wasChanged(['customer_id', 'status'])`

**理由**: これを付けないと、契約のメモを直しただけでランクが成約へ戻る。
利用者が意図的にランクを手で変えていた場合、無関係な編集で書き戻されるのは不可解な挙動になる。

## 4. 部署ランクの更新規則

```
対象部署のランク行がある場合:
    既に contracted → 何もしない（無駄な UPDATE を出さない）
    それ以外        → rank を contracted に上書き（A/B/C/D/他決/追客不可 すべて対象）
    ⚠ acquired_date（取得日）は書き換えない

対象部署のランク行が無い場合:
    その部署の行を新規作成（rank = contracted, acquired_date = 契約日）
```

- **他決・追客不可も無条件に上書きする。** 契約したという事実が最も強いため。
- **取得日を保持する理由**: 取得日は「いつ獲得した顧客か」という独立した意味を持つ実データで、
  契約日で上書きすると獲得経路の履歴が失われる。新規作成時のみ契約日を初期値に使う。
- `buyer_departments` には `uq_buyer_department (buyer_id, department)` の
  UNIQUE 制約があるため、1顧客1部署につき行は最大1つ（実測確認済み）。
- `acquired_date` は NOT NULL。契約日が取れない場合は当日日付を使う。
- 買主が論理削除済みでも更新する（`Buyer::withTrashed()` で引く）。
  契約リレーションは既に `withTrashed()` を使っており、復元時に整合が取れる。

## 5. 実装場所 — コントローラではなくモデルの保存イベント

### 5.1 なぜコントローラに書かないか

買主が契約に紐づく入口は実測で **6箇所**ある:

| # | 入口 | メソッド |
|---|---|---|
| 1 | 不動産 契約登録 | `RealEstate\ReContractController::store` |
| 2 | 不動産 契約編集 | `RealEstate\ReContractController::update` |
| 3 | 建売 契約登録 | `Housing\ContractController::store` |
| 4 | 建売 契約編集（契約一覧から） | `Housing\HsContractListController::updateBuilding` |
| 5 | 注文住宅 登録 / 編集 | `Housing\CustomOrderController::store` / `update` |
| 6 | 注文住宅 ステータス変更 / 契約編集 | `Housing\CustomOrderController::updateStatus` / `HsContractListController::updateCustomOrder` |

各コントローラに書き足す方式は、**1箇所書き忘れても画面は正常に動き、無音で漏れる**。
過去に Bug #41（同じ処理の経路が複数あり片方だけ直す）・Bug #44（入口×参照元の組み合わせが
テストで一度も実行されない）で実際に踏んだ形。

### 5.2 採用する形

契約モデル 3つの `booted()` に `saved` フックを 1つずつ置く。
`ReContract` / `ReProject` / `ReProcurement` は既に `booted()` を持っており、既存の流儀に沿う。

共通処理は `Buyer` モデルの新メソッドに集約する:

```php
/**
 * 指定部署のランクを「成約」にする。部署未登録なら成約ランクで登録する。
 *
 * ⚠ 既存行の acquired_date は書き換えない（獲得日は契約日とは別の実データ）
 */
public function markContracted(string $department, ?string $acquiredDate = null): void
```

`getDepartmentPivot()` / `addToDepartment()` という既存メソッドの上に組む。

### 5.3 部署値の決め方

- `ReContract` は `department` カラムを持つ（ARCHITECTURE.md 記載どおり住宅事業へ拡張可能な設計）。
  ハードコードせず `$contract->department` を使う。ただし `BuyerDepartment` enum
  （`housing` / `realestate`）に無い値なら何もしない（`buyer_departments.department` は
  この2値の ENUM なので、範囲外を書くと DB エラーになる）。
- `HsContract` / `HsCustomOrder` は住宅事業固有のテーブルなので `housing` 固定。

## 6. 影響範囲

- 顧客一覧（`/buyers`）のランクバッジ・ランク絞り込み: 既定表示は A〜D なので、
  **成約になった顧客は既定の一覧から外れる**（「成約」で絞れば見える）。これは既存仕様どおりの挙動。
- 決定2により、契約の買主差し替え・契約削除でランクは戻らない。
- 決定3により、既存データは変わらない。過去の契約済み顧客は A/B/C/D のまま残る。

## 7. テスト方針

### 7.1 入口 × 挙動の全組み合わせを測る

Bug #44 の教訓（入口が複数あると、ある入口のある挙動だけ一度も実行されない）に従い、
**入口ごとに**テストを置く。モデルイベント方式なのでロジック自体は1箇所だが、
「その入口が実際にモデルイベントを通るか」は入口ごとに確かめないと分からない
（例: クエリビルダ経由の `update()` はモデルイベントを通らない）。

| # | 検証内容 |
|---|---|
| 1 | 不動産 契約登録（仕入れ系）で買主が成約になる |
| 2 | 不動産 契約登録（分譲地）で買主が成約になる |
| 3 | 不動産 **仲介**登録では誰のランクも変わらない |
| 4 | 不動産 契約編集で買主を差し替えると、新しい買主が成約になる |
| 5 | 不動産 契約編集で買主を差し替えても、**元の買主は成約のまま**（決定2） |
| 6 | 建売 契約登録で買主が成約になる |
| 7 | 建売 契約編集（契約一覧から）で買主を差し替えると新しい買主が成約になる |
| 8 | 注文住宅を**商談**ステータスで登録してもランクは変わらない |
| 9 | 注文住宅を**契約**ステータスで登録すると成約になる |
| 10 | 注文住宅のステップバーで 商談 → 契約 に進めると成約になる |
| 11 | 部署行が無い顧客は、その部署が取得日＝契約日・ランク成約で自動作成される |
| 12 | 既存の部署行の **acquired_date が書き換わらない** |
| 13 | 他決／追客不可のランクも成約に上書きされる |
| 14 | もう一方の部署のランクは変わらない（不動産の契約で住宅事業のランクは不変） |
| 15 | 契約のメモだけを編集してもランクは書き戻らない（3.3 の再発火抑制） |

### 7.2 変異テストは必須

「テストが緑」は検証にならない（Bug #39 / #42 / #44 / #45 / #46 で繰り返し実測）。
最低限、次の変異それぞれで**実際に赤になることを確認する**:

- `markContracted()` の呼び出しを 3モデルそれぞれから外す（3通り）
- 注文住宅の `isContractedOrLater()` 判定を外す（→ 商談で登録しても成約になる = #8 が赤）
- 部署行が無い時の自動作成を外す（→ #11 が赤）
- 既存行の `acquired_date` も上書きするように変える（→ #12 が赤）
- `wasChanged` ガードを外す（→ #15 が赤）

⚠ 変異が実際に当たったか（`git diff` が非空か）を毎回確認する。
当たっていない変異を「検出しない」と誤読する事故を過去に踏んでいる（Bug #44）。

**実測結果マトリクス（2026-08-04 追記）**

実測日: 2026-08-04。ベースラインはテスト総数 506 tests, 2801 assertions（全 green）。

- 変異はすべて `Edit`（構造化置換）で手当てし、`sed` / `perl` の一括置換は使っていない
  （同じ文字列がファイル内に複数あると意図しない行を書き換え、テストが正しく緑のまま残って
  「検出しない」と誤読する。Bug #44）。各変異の適用後は毎回 `git diff --stat` が空でないことを
  確認してから対象テストを実行し、確認後は `git checkout --` で戻して `git status --short` が
  空に戻ることを都度確認した。
- **M15 だけは他と種類が異なる測定。** 「正しい実装では緑・変異後は赤」という通常の1方向の
  変異テストではなく、**旧ロジック（`explode(';', ...)` 方式）と新ロジックを実際に差し替えて
  「旧では見逃す（緑のまま）・新では検出する（赤になる）」の両方を確認**した。これは改善そのものが
  load-bearing であることの証明になっている。
- 下表の「実測区分」列は、**このタスク（Task 7）で実際にコマンドを実行して確認したもの**
  （新規測定・抜き取り確認）と、**実装・レビュー時の記録をそのまま引き写したもの**
  （このタスクでは再実行していない）を区別する。守られていない範囲を「守られている」と
  書かないため（Bug #45 の教訓）。

| # | 変異内容 | 対象ファイル | 赤になったテスト | 実測区分 |
|---|---|---|---|---|
| M1 | `saved` フック本体を丸ごと削除 | `app/Models/ReContract.php` | ReContractBuyerRankTest **11 本中 8 本**赤（brokerage / memo / unknown_department 以外）※1 | 今回実測（抜き取り確認・数値の食い違いを検出） |
| M2 | `saved` フック本体を丸ごと削除 | `app/Models/HsContract.php` | 建売 2 本赤（store / update_from_list） | 実装/レビュー時の記録を引き写し（今回未実行） |
| M3 | `saved` フック本体を丸ごと削除 | `app/Models/HsCustomOrder.php` | 注文住宅 5 本赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M4 | `isContractedOrLater()` 判定を削除 | `app/Models/HsCustomOrder.php` | 3 本赤（consultation ＋ step_bar / edit_form の「前提」assert） | 実装/レビュー時の記録を引き写し（今回未実行） |
| M5 | `markContracted()` の「部署行が無ければ作る」分岐を潰す | `app/Models/Buyer.php` | 3 本赤 — `test_missing_department_row_is_created_as_contracted_with_given_date` / `test_missing_date_falls_back_to_today` / `test_buyer_without_realestate_row_gets_one_with_contract_date` | **今回実測（新規）** |
| M6 | 既存行の `acquired_date` も上書きするようにする | `app/Models/Buyer.php` | 2 本赤 — `test_existing_row_keeps_its_acquired_date` / `test_existing_acquired_date_is_not_overwritten_through_http` | **今回実測（新規）** |
| M7 | `wasRecentlyCreated`/`wasChanged` ガード削除 | `app/Models/ReContract.php` | `test_editing_memo_only_does_not_rewrite_rank` のみ赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M8 | `BuyerDepartment::tryFrom` ガード削除 | `app/Models/ReContract.php` | `test_unknown_department_writes_nothing` のみ赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M9 | 上書き対象を A〜D 限定にする（他決・追客不可を除外） | `app/Models/Buyer.php` | `test_lost_and_unreachable_ranks_are_overwritten` のみ赤 | **今回実測（新規）** |
| M10 | 再発火ガード削除 | `app/Models/HsContract.php` | `test_tateuri_editing_notes_only_does_not_rewrite_rank` のみ赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M11 | 再発火ガード削除 | `app/Models/HsCustomOrder.php` | `test_custom_order_editing_notes_only_does_not_rewrite_rank` のみ赤 | 今回実測（抜き取り確認・表と一致） |
| M12 | `Buyer::withTrashed()->find(` → `Buyer::find(` | `app/Models/ReContract.php` | `test_soft_deleted_buyer_is_still_marked_contracted` のみ赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M13 | 同上 | `app/Models/HsContract.php` | `test_tateuri_soft_deleted_buyer_is_still_marked_contracted` のみ赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M14 | 同上 | `app/Models/HsCustomOrder.php` | `test_custom_order_soft_deleted_buyer_is_still_marked_contracted` のみ赤 | 実装/レビュー時の記録を引き写し（今回未実行） |
| M15 | 走査テストの文切り出しを旧 `explode(';', ...)` 方式に戻す | `tests/Feature/ContractModelEventPathTest.php` | `DeletionBlockers::forProject()` へのクエリビルダ更新を**見逃す**（新方式では赤）＝改善が load-bearing | 実装/レビュー時の記録を引き写し（新旧比較・今回未実行） |
| M16 | `ROOT_PATTERNS` を存在しないクラス名 1 つに差し替え | 同上 | `rootStatements` の下限で赤（`scannedFiles` の下限では落ちない） | 実装/レビュー時の記録を引き写し（今回未実行） |

※1 **M1 は表の初出記録（7 本赤）と今回の再測定（8 本赤）が食い違った。** 2026-08-04 に
`app/Models/ReContract.php` の `saved` フック（買主ランク自動更新の本体）を丸ごと削除して
`vendor/bin/phpunit tests/Feature/RealEstate/ReContractBuyerRankTest.php` を実行したところ、
11 本中 8 本が赤になった（`test_procurement_contract_store_marks_buyer_contracted` /
`test_subdivision_contract_store_marks_buyer_contracted` /
`test_swapping_buyer_on_update_marks_new_buyer_contracted` /
`test_swapping_buyer_leaves_previous_buyer_contracted` /
`test_buyer_without_realestate_row_gets_one_with_contract_date` /
`test_existing_acquired_date_is_not_overwritten_through_http` /
`test_other_department_rank_is_untouched` /
`test_soft_deleted_buyer_is_still_marked_contracted`）。緑のまま残った3本
（`test_brokerage_contract_store_changes_no_rank` / `test_editing_memo_only_does_not_rewrite_rank` /
`test_unknown_department_writes_nothing`）は表の記載「brokerage / memo / unknown_department 以外」と
一致しており、**「どのテストが赤くなるか」の一覧は表と食い違っていない**。ズレているのは合計数
（7 → 8）だけで、最有力の説明は「表の初出時点では `test_buyer_without_realestate_row_gets_one_with_contract_date`
`test_existing_acquired_date_is_not_overwritten_through_http`（M5/M6 の HTTP 経路カバレッジとして
後日追加された2本）のうち少なくとも1本がまだ存在せず、対象ファイルのテスト総数が 11 本より
少なかった」というもの。実装の欠陥ではなくテスト総数の増加によるズレと考えられるが、
**辻褄を合わせず実測どおり「8 本」をここに記録する**。

### 7.3 テスト用スキーマ

`tests/Concerns/CreatesRealEstateSchema.php` は `buyers` を作るが
**`buyer_departments` を作っていない**ため、追加が必要。
本番の実スキーマ（実測）に合わせる:

```
id / buyer_id / department enum('housing','realestate') / acquired_date date NOT NULL
/ rank enum('A','B','C','D','lost','unreachable','contracted') default 'C'
/ created_at timestamp nullable
+ unique (buyer_id, department)
```

⚠ 本番は `created_at` に `CURRENT_TIMESTAMP` の DB 既定値があるが SQLite には無いので
テスト側は nullable にする（`BuyerDepartmentPivot` は `$timestamps = false`）。

### 7.4 モデルイベントを通らない書き込みが無いか確認する

`HsContract::where(...)->update(...)` のような**クエリビルダ経由の更新**はモデルイベントを通らない。
2026-08-04 実測で契約3テーブルには該当が 0 件で、すべて `create()` / `$model->update()` 経由と確認済み
（区画・仕入れ案件のステータス更新はクエリビルダ経由だが、それらは対象モデルではない）。
実装時に再確認し、将来増えた場合は個別対応が要る。

## 8. スコープ外

- 既存データの一括更新（決定3）
- 仲介契約の顧客マスタ紐付け（3.1。構造的に不可能。必要なら別案件）
- 契約削除・買主差し替え時のランク差し戻し（決定2）
- テナント管理・賃貸マンション・ZEAL の契約（顧客マスタ `buyers` と別系統）

## 9. 本番反映

`./deploy.sh`（コントローラ変更を含むため `route:cache` 再生成が要る）。
DB スキーマの変更は無い（`buyer_departments` は既存テーブル、`contracted` は既存の ENUM 値）。
