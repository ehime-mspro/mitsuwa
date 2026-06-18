# テナント投資回収 — 区画ベース自動回収 設計書

- 作成日: 2026-06-18
- 対象: テナント投資案件の回収を「契約への手動紐付け」から「区画(unit)ベースの自動回収」へ転換し、回収計算を「家賃のみ・実発生家賃」に精緻化する
- 種別: 機能改修（モデル/UX の簡素化 ＋ 回収計算の精緻化 ＋ 契約紐付け導線の撤去）
- 関連: 本日朝の [2026-06-18-tenant-investment-recovery-linkage-design.md](2026-06-18-tenant-investment-recovery-linkage-design.md)（契約ベース紐付け）を**置き換える**。実装済みの契約紐付け3導線（commit a103928d 系）は本改修で撤去する。

## 1. 背景

朝のリリースで「投資案件↔契約」を手動で紐付けて回収を可視化する3導線を実装した。しかし回収計算 `Investment::computeRecovery()` は実際には **投資の `unit_id` に紐づく全契約の家賃合計（完成日以降）** で動いており、`contract_id` は計算に使われず status を「回収中」に切り替えるだけだった。つまり「契約への紐付け」は概念的に冗長で分かりにくい。

オーナー要件:
1. 契約ベースではなく **物件の区画(unit)** に紐付ける。区画に契約があればその家賃収入が回収に計上される。
2. **回収の基本は家賃のみ**。共益費・ゴミ代・駆除代は回収に含めない。

要件1の計算（unit_id ベース）と要件2（rent のみ）は**計算エンジンとしては既に実現済み**。本改修は「契約中心の UX/モデルを区画中心へ簡素化」し、「初月/最終月の家賃計上を実発生額へ精緻化」し、「不要になった契約紐付け導線を撤去」する。

## 2. 回収モデル（区画ベース自動回収）

- 投資案件は作成時に持つ **物件＋区画(unit_id)** に紐づく（既存）。
- 投資に **完成日(工事完了日 `end_date`)** を設定すると、その区画の契約家賃で**自動的に**回収が進む。**手動の契約紐付けは不要**。
- 回収額・回収状態は常に動的計算（紐付け操作・トリガ不要）。

## 3. 回収状態の自動判定（A案・最小変更）

既存 `InvestmentStatus` enum（planning/in_progress/completed/recovering/recovered）を活かす。**ユーザーが手で設定できるのは 計画中/工事中/工事完了 の3つのみ**。回収中/回収完了は自動。「回収待ち」は UI 派生ラベル。

表示ラベルの導出（ユーザーに見える真実 = 回収率から動的に決める）:

| 条件 | 表示ラベル | 永続 status |
|---|---|---|
| `end_date` 未設定 | 計画中 / 工事中 / 工事完了（workflow） | planning / in_progress / completed |
| `end_date` あり・回収率 = 0（区画に有効契約なし=空室 等） | **回収待ち** | completed のまま |
| `end_date` あり・0 < 回収率 < 100 | **回収中** | recovering（自動遷移・永続） |
| 回収率 ≧ 100 | **回収完了** | recovered（自動遷移・永続） |

- 永続 status の自動遷移は現状の遅延更新方式（index/show 表示時に `update()`）を流用。`recovering`/`recovered` は前進方向に永続化（一覧フィルタ用）。表示ラベルは回収率から動的に出すため、契約解約等で率が0に戻った場合も「回収待ち」を正しく表示できる。
- フォーム（create/edit）の status 選択肢は **計画中/工事中/工事完了** のみに限定（`update` のバリデーションも recovering/recovered を不可に。自動遷移は別経路で行う）。

## 4. 回収計算（家賃のみ・実発生家賃）

`Investment::computeRecovery(Collection $contracts)` を精緻化する。集計起点・上限・unit_id ベースは既存通り。**各月の計上額は家賃(rent)のみ**（共益費・ゴミ代・駆除代は不算入＝既存通り）。

### 4.1 各契約の月次計上（精緻化）

各契約（`rent_start_date` あり・`rent > 0`）について:

```
rentStartMonth = rent_start_date->startOfMonth()
pivotMonth     = end_date->startOfMonth()
startMonth     = max(rentStartMonth, pivotMonth)
endDate        = isTerminated ? contract_end_date : now()
endMonth       = endDate->startOfMonth()
if (startMonth > endMonth) skip   // 完成日より前に終了 / 起点が未来

isContractFirstMonth = startMonth == rentStartMonth   // 契約の実初月から数えるか（完成日が家賃発生日以前）
```

月次計上:
- **単月（startMonth == endMonth）**:
  - 解約済み → `Contract::finalMonthRent()`（解約月の日割り家賃）
  - else if `isContractFirstMonth` → `Contract::initialMonthRent()`（初月の日割り/フリーレント/半月の家賃）
  - else → 満額 `rent`
- **複数月**:
  - 初月: `isContractFirstMonth ? Contract::initialMonthRent() : rent`
  - 中間月（初月翌月〜最終月前月）: 月数 × `rent`
  - 最終月: 解約済み → `Contract::finalMonthRent()` / 継続中（当月）→ 満額 `rent`
- 合計を `total_amount` で上限（既存）。
- 表示用 `recovery_started_at` = 計上対象になった最早の `startMonth`（既存キー）。

**`first_month_recovery` / `last_month_recovery`（常に null の未使用列）への依存は撤去**し、上記の `initialMonthRent()` / `finalMonthRent()` に置き換える。

### 4.2 `Contract::finalMonthRent(): int`（新規）

`initialMonthRent()` と対になる、解約月の家賃相当額。`final_month_type` と `contract_end_date` で算出（家賃ベース）:
- `full` または `contract_end_date` なし → `rent`
- `free` → `0`
- `prorated` → `round(rent * 終了日.day / 終了日.daysInMonth)`（最終月は 1日〜契約終了日）
- `half` → `round(rent / 2)`
- `manual` → `round(final_month_amount * rent / 月額合計)`（月額合計=rent+common+garbage+pest、按分で家賃相当を抽出。月額合計0なら0）

注: `initialMonthRent()` の prorated は `daysInMonth - 家賃発生日.day + 1`（家賃発生日〜月末）。`finalMonthRent()` の prorated は `終了日.day`（1日〜終了日）。`ContractController::calculateMonthAmount` の initial/final 日割りロジックと一致させる。

## 5. 区画詳細(unit/show)への集約（UX）

### 5.1 表示
`UnitController::show` は既に investments を load 済み（現状 status in_progress/recovering のみ）。これを**表示用途に調整**（completed/recovered も含める）し、`tenant/units/show.blade.php` に「投資・回収状況」セクションを新設:
- この区画の投資案件一覧（投資番号・パターン・投資総額・**回収状況ラベル（回収待ち/回収中●%/回収完了）**・累計回収額/残額）。
- 各投資詳細へのリンク。

### 5.2 登録導線
- 区画詳細に「**この区画に投資を登録**」ボタンを追加。
- `InvestmentController::create(Request)` が `property_id` / `unit_id` クエリパラメータを受け取り、投資作成フォームの物件・区画を**プリセット**（選択済み表示）する。`create.blade.php` の物件/区画セレクトを preset 値で初期選択。
- 認可は既存通り（作成は `role:executive,manager`）。区画詳細ページ自体は全ロール閲覧可のため、ボタンは `isManagerOrAbove()` でガード。

## 6. 撤去するもの（契約紐付けの廃止）

| 区分 | 撤去内容 |
|---|---|
| Blade | `contracts/create.blade.php`・`contracts/edit.blade.php` の「関連投資案件」セクション＋Alpine（investmentId/investments/loadingInvestments/currentInvestment/fetchInvestments/renderInvestments/init・onUnitChange等のフック）。`investments/show.blade.php` の「回収を開始する」「紐付けを解除」カード |
| Controller | `InvestmentController`: `linkContract` / `unlinkContract` / `forUnit` / `show` の `linkableContracts`。`ContractController`: `store`/`update` の investment_id 処理、`revise` の `linkToContract` 呼び出し、`edit` の `currentInvestment` 構築、private `linkInvestment` / `syncContractInvestment` |
| Model | `Investment`: `linkToContract` / `unlinkFromContract` / `applyContractLinkage` / `clearContractLinkage`。`Contract::initialMonthRent()` は**回収計算で使うため残す** |
| Route | `tenant.investments.link-contract`（POST）/ `tenant.investments.unlink-contract`（DELETE）/ `api.tenant.unit-investments`（forUnit GET） |

撤去後の横展開検査: `grep -rn "link-contract\|unlink-contract\|forUnit\|linkToContract\|syncContractInvestment\|currentInvestment\|linkableContracts" app/ routes/ resources/` がヒット0。

## 7. データ / スキーマ

- **スキーマ変更なし**。`investments.contract_id` / `recovery_start_date` / `monthly_rent` / `estimated_recovery_months` / `estimated_recovery_date` は未使用化（列は残置）。`contracts.first_month_recovery` / `last_month_recovery` も未使用のまま残置。
- 移行データなし（本番に契約紐付け済みの投資は0件・確認済み）。

## 8. 非対象（YAGNI）

- 物件詳細(properties/show)の区画カードへの回収バッジ（今回は区画詳細のみ）。
- 回収額の手動上書き・調整。
- 未使用列（contract_id 等）の物理 DROP。
- 修繕等・投資以外の回収管理。

## 9. テスト方針（DB 非依存インメモリ・既存方式）

`Investment::computeRecovery` のユニットテストに追加:
- 完成日起点 / 解約ストップ / 再契約再開 / 完成日未設定（0）/ 既存入居者の完成日またぎ（満額）/ 投資総額上限（既存）。
- **初月日割り**（`initial_month_type=prorated` → 家賃の日割り分）、**フリーレント**（→ 0）、**半月**（→ 家賃半額）。
- **解約月日割り**（`final_month_type=prorated` → 終了日までの家賃日割り）。
- **空室（契約なし）→ 回収率0 = 回収待ち**。

`Contract::finalMonthRent()` の単体テスト（full/free/prorated/half/manual）。

回収状態ラベル導出（回収待ち/回収中/回収完了）のユニットテスト（end_date と回収率の組合せ）。

## 10. 本番反映

- worktree で実装 → `13.x` へ FF-merge → `./deploy.sh`（route 削除があるため `route:cache` 再生成必須、Blade 変更のため `view:cache` 再生成必須）。
- **Bug #26 厳守**: Blade の `@json()` に多行配列リテラルを渡さない。デプロイ前検証はコンパイル済みビューを `php -l`（`view:cache` 成功では不十分）＋実データ相当でのレンダリング確認。
- 新規 PHP クラス追加なし → `composer dump-autoload` 不要。
