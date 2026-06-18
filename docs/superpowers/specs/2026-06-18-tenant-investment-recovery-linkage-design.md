# テナント投資案件 回収紐付け 設計書

- 作成日: 2026-06-18
- 対象: テナント管理の投資案件と契約（区画の賃料収入）の紐付け、および回収計算の「完成日起点」化
- 種別: 機能改修（既存の回収管理基盤を「実際に使える状態」にする ＋ 回収計算仕様の修正）

## 1. 背景と目的

投資案件（リフォーム・設備投資）に投じた金額を、その区画の賃料収入でどれだけ回収できたかを管理したい。

回収管理の基盤（回収計算 `calculateRecovery`、回収情報の表示、ステータス自動遷移 `recovering`→`recovered`、区画の未紐付け投資案件を返す `forUnit` API）は既に実装済み。しかし **投資案件を契約（賃料収入源）に紐付ける手段が欠落** しており、回収が一度も始められない状態にある。

直接の原因は、契約登録画面の「関連投資案件」セレクトが `{{-- STEP 8完了後に投資案件の選択肢が表示される --}}` というプレースホルダーのまま **未結線** で残っていること（投資案件管理＝STEP 8 が後から実装された際に結線が漏れた）。

本改修では「紐付ける手段」を 3 つの導線で提供し、あわせて回収計算の起点を要件に合わせて「**投資の完成日**」基準へ修正する。

## 2. 現状の実装（既存資産・再発明しない）

| 機能 | 状態 | 場所 |
|---|---|---|
| 投資案件 ↔ 物件・区画 の紐付け | 完成 | `investments/create`・`edit`（`property_id` / `unit_id`）|
| 契約紐付け時の回収開始処理 | 完成（ただし private） | `ContractController::linkInvestment` |
| 回収額・回収率の自動計算 | 完成（**要修正**） | `Investment::calculateRecovery` |
| 回収情報の表示（累計/回収率/残額/残月数）| 完成 | `investments/show` |
| 区画の未紐付け投資案件 API | 完成 | `InvestmentController::forUnit`（`/api/tenant/units/{unit}/investments`）|
| ステータス自動遷移 `recovering`→`recovered` | 完成 | `investments` index/show |
| 契約登録画面の紐付けセレクト | **未結線** | `contracts/create.blade.php:434-439` |
| 契約編集での紐付け | **無し**（`investment_id` を `unset`）| `ContractController::update:350` |

## 3. 回収計算の仕様（確定）

### 3.1 集計起点 = 投資の完成日

`calculateRecovery()` の集計起点を、現状の `recovery_start_date`（紐付けた契約の賃料開始日）から **投資案件の完成日 `end_date`（工事完了日）** に変更する。

各契約について、`max(契約の rent_start_date, 投資の end_date)` の月から賃料を積む。

### 3.2 シナリオ別の挙動

| シナリオ | 挙動 |
|---|---|
| 完成日以降の賃料 | カウント（起点 = `max(賃料開始日, 完成日)` の月から積む）|
| 完成日より前の期間 | 除外 |
| 完成日前から継続している既存入居者 | 完成日以降の期間分のみカウント |
| 解約 | その契約の `contract_end_date` の月で積み止め（＝**回収ストップ**）|
| 空室期間（契約なし） | 契約が存在しないので積まれない |
| 再契約 | 次の契約の賃料を積む（＝**回収再開**）|
| `end_date` 未入力 | 回収対象外（回収額 0・回収率 0）。詳細画面で「工事完了日を設定してください」と案内 |

解約・空室・再契約は、既存の「区画の契約群を時系列で積む」方式（`calculateRecovery` は `unit_id` ベースで契約を取得）が自然に表現する。**本質的な変更は集計起点を完成日にする 1 点**。

### 3.3 表示の整合

- `investments/show` の「回収開始日」は、実際に賃料を積み始める日（＝完成日以降で最初に契約賃料が発生する月）を表示する。完成日のみ設定で契約がまだ無ければ「—」。
- `recovery_start_date` カラムは「紐付けた契約の記録」として残すが、`calculateRecovery` の計算には `end_date` を使い、`recovery_start_date` には依存しない。

## 4. 紐付けの 3 導線

### 4.1 ロジック共通化（土台）

`ContractController` の private `linkInvestment()` を `Investment` モデルへ移し、再利用可能にする。

- `Investment::linkToContract(Contract $contract)` — `contract_id` / `monthly_rent` / `recovery_start_date` / `recovery_months` / `recovery_end_date` をセットし、status が `planning`/`in_progress`/`completed` なら `recovering` に変更。
- `Investment::unlinkFromContract()` — `contract_id`=null、`recovery_start_date`/`recovery_months`/`recovery_end_date`=null、status を `completed` に戻す（誤紐付けの訂正用）。
- 初月家賃相当額の計算 `getInitialMonthRent` は `Contract` モデルのメソッドへ移し、`linkToContract` から契約経由で呼べるようにする。

`ContractController` の `store` / `revise` は新メソッドへ委譲する（**挙動は不変**）。

### 4.2 導線①：新規契約フォームの結線（どの案でも共通・確定）

`contracts/create.blade.php` の「関連投資案件」セレクトを結線する。

- 区画選択時に `forUnit` API（`/api/tenant/units/{unit}/investments`）を fetch し、未紐付け投資案件を `<option>` に流す（同画面が既に使う `vacant-units` / `active-inquiries` と同じ Alpine fetch パターンに合わせる）。
- プレースホルダーのコメントと「STEP 8 完了後に選択肢が表示されます」案内文を削除。
- `store` 側は `investment_id` 受領・紐付けを実装済みのため、フロント結線と 4.1 の委譲のみで動作する。

### 4.3 導線②：投資案件詳細から紐付け／解除（案1）

`investments/show.blade.php`：

- `contract_id` が未設定の場合、「**回収を開始する**」セクションを表示。その区画（`unit`）の契約をセレクトで選び「紐付けて回収開始」ボタン。区画に契約が無ければ「この区画にはまだ契約がありません」。
- 完成日（`end_date`）が未設定なら、紐付け前に「工事完了日を設定してください（回収計算の起点になります）」と警告。
- 紐付け済みなら、回収情報セクションに「**紐付けを解除**」リンク（誤操作の訂正用、確認ダイアログ付き）。

`InvestmentController`：

- `show` でその区画の契約一覧（紐付け候補）を渡す。
- `linkContract(Investment $investment, Request $request)` — `contract_id` を受け、区画一致を検証し `Investment::linkToContract` を呼ぶ。
- `unlinkContract(Investment $investment)` — `Investment::unlinkFromContract` を呼ぶ。

ルート（`routes/web.php`、middleware `role:executive,manager`）：

- `POST /tenant/investments/{investment}/link-contract` → `tenant.investments.link-contract`
- `DELETE /tenant/investments/{investment}/unlink-contract` → `tenant.investments.unlink-contract`

### 4.4 導線③：契約編集から紐付け（案2）

`contracts/edit.blade.php`：

- `create` と同じ「関連投資案件」欄を追加。`forUnit` API で結線（編集対象契約の区画を使用）。その契約に現在紐付いている投資案件があれば選択済みで表示。
- `ContractController::update`：`investment_id` の `unset` をやめ、選択値に応じて紐付け／解除（`Investment::linkToContract` / `unlinkFromContract`）。

1 区画に複数投資があるレアケースは導線②で個別に対応する位置づけ（契約編集は「主たる 1 件」を扱う単一セレクト）。

## 5. データの流れ

```
投資案件(total_amount, end_date) ──紐付け──▶ 契約(rent_start_date, rent)
                                                  │
                                                  ▼
        calculateRecovery: 区画の全契約を max(賃料開始日, 完成日) 以降で積む
                                                  │
                                                  ▼
                          回収率(recovery_rate) ─100%─▶ recovered
```

## 6. 1 対多・整合性

- `investment.contract_id` は単一（1 投資案件 → 1 契約）。複数の投資案件が同一契約を指すのは可。
- 紐付け時、投資の `unit_id` と契約の `unit_id` の一致を検証する。
- 紐付け候補の契約は、その区画に属するもののみを提示する。

## 7. テスト方針

- `Investment::calculateRecovery` のユニットテスト：完成日起点 / 解約ストップ / 再契約再開 / 完成日未設定（回収 0）/ 既存入居者の完成日またぎ。
- `Investment::linkToContract` / `unlinkFromContract` のユニットテスト。
- 既存の `linkInvestment` 委譲後も挙動が不変であることの確認（`store` 経由）。
- 注意: worktree には `vendor` が無いためテスト不可。テストは **main repo** で `composer install`（dev 込み）→ `vendor/bin/phpunit` → 完了後 `composer install --no-dev`。

## 8. 非対象（YAGNI）

- 1 契約に複数投資案件を同時選択する UI（契約編集は単一セレクト。複数は導線②で個別に紐付け）。
- 回収額の手動上書き・調整。
- 投資以外（修繕等）の回収管理。
- DB スキーマ変更（`contract_id` / `recovery_start_date` / `recovery_months` / `recovery_end_date` / `monthly_rent` は既存カラム）。

## 9. 本番反映

- 実装は git worktree（`.claude/worktrees/<name>`）で行い、`13.x` へ FF-merge。
- 新規 PHP クラスは追加しない（既存モデル/コントローラへのメソッド追加のみ）見込みのため `composer dump-autoload` は不要。ただしルート追加があるため本番反映は `./deploy.sh`（`route:cache` / `view:cache` 再生成）必須。
