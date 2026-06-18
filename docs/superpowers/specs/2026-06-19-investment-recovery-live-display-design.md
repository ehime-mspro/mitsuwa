# 投資回収 表示の鮮度（ライブ計算）設計書

- 作成日: 2026-06-19
- 対象: 投資一覧・物件詳細の回収表示が古い（保存値 `recovery_rate` を読む遅延更新）問題を、ライブ計算で解消する
- 種別: 機能改修（表示鮮度）。回収計算ロジック（区画ベース・家賃のみ）は不変。
- 関連: [2026-06-18-tenant-investment-recovery-unit-based-design.md](2026-06-18-tenant-investment-recovery-unit-based-design.md)（区画ベース自動回収）の後続。

## 1. 背景・問題

回収率 `recovery_rate`（と `total_recovered`/`status`）は**保存カラム**で、**投資詳細(`InvestmentController::show`)を開いた時だけ**再計算・保存される。一方:
- **投資一覧(`InvestmentController::index`)**: バッジは保存値 `recovery_rate` を `recoveryLabel()` に渡す。
- **物件詳細(`PropertyController::show`)**: フロアマップ区画カード(`_unit_card`)が `floorMapBadge($inv->recovery_rate)`、投資タブ(`properties/show.blade`)が `$inv->recovery_rate` を表示。

→ 個別に投資詳細を開いていない投資は、これらの画面で**古い値（回収待ち/0%/工事完了）**のまま。区画詳細(`UnitController::show`)だけは U4 で都度ライブ計算済みなので正しい（＝「区画を開けば表示される」）。

## 2. 方針（ライブ計算）

一覧・物件詳細でも、表示のたびに `calculateRecovery()`（既存・区画ベース・家賃のみ）で**その場再計算**し、最新の回収率・状態で表示する。手動更新は不要。**計算ロジックは一切変更しない**。

## 3. 設計

### 3.1 `Investment` に共通メソッドを追加

- `applyRecoverySnapshot(array $recovery): void`（純粋・DB保存なし）
  - 引数は `calculateRecovery()`/`computeRecovery()` の戻り値。
  - `$this->total_recovered = $recovery['total_recovered']; $this->recovery_rate = $recovery['recovery_rate'];`
  - **前進方向の status 反映（完成日あり前提）**: `end_date` があり `rate >= 100` → `recovered`、`rate > 0 && status !== recovered` → `recovering`。それ以外は status 不変（回収待ちは `completed` のまま）。
- `refreshRecovery(): array`
  - `$r = $this->calculateRecovery(); $this->applyRecoverySnapshot($r); return $r;`
  - モデルの属性を**メモリ上で**最新化し回収配列を返す（保存はしない）。

### 3.2 各表示パスで呼ぶ

- **`InvestmentController::index`**: 既存の「100%自動更新ループ」を、ページ内各投資への `$inv->refreshRecovery();` に置換（メモリ上のみ・**一覧表示での DB 書き込みなし**）。`index.blade` は現状のまま `recoveryLabel((float)$inv->recovery_rate)` を読む＝ライブ値。
- **`InvestmentController::show`**: 既存のインライン再計算＋保存ロジックを `$recovery = $investment->refreshRecovery(); $investment->save();` に置換（**詳細表示時は保存**＝保存値を温存）。`$investment->refresh()` 後続の整合を確認。
- **`PropertyController::show`**:
  - フロアマップが「完成のみ＝実は回収中」の投資も表示できるよう、`units.investments` の load 条件を `whereIn('status', ['in_progress', 'recovering'])` から `['in_progress', 'completed', 'recovering', 'recovered']` に**拡張**。
  - `$property->units` 各区画の `investments` と、投資タブ用 `$investments` の各投資に `refreshRecovery();`（メモリ上のみ）。`_unit_card`(floorMapBadge) と投資タブ（`recovery_rate`）は現状のまま＝ライブ値。
- **`UnitController::show`**: 既にライブ計算（U4の `$unitInvestments`）のため**変更なし**。

### 3.3 ビュー

変更なし（`recovery_rate`/`status` を読むが、コントローラがライブ値をメモリにセット済み）。`floorMapBadge` は recovering で「投資回収中XX%」、completed で「工事完了」、recovered で null（バッジ無し）を返す既存挙動のまま。

## 4. パフォーマンス

- 一覧: ページネーション10件 → 10×`calculateRecovery()`（各 `Contract::where(unit_id)` 1クエリ）。
- 物件: 区画数＋投資タブ件数分（同一投資が両系統に出る場合は二重計算だが規模的に許容）。
- いずれも書き込みは詳細表示時のみ。この規模では問題なし。

## 5. 非対象（YAGNI）

- 一括再計算ボタン/コマンド（B案）・イベント連動（C案）。
- 経営ダッシュボード等の他の回収集計（本件は一覧・物件・区画の表示鮮度に限定）。
- スキーマ変更（`recovery_rate` 等は既存カラム）。

## 6. テスト方針

- `applyRecoverySnapshot()` のユニットテスト（DB非依存・インメモリ）: 完成日あり×率0→status不変(completed)・rate/total反映 / 率>0→recovering / 率≧100→recovered / recovered からは降格しない / 完成日なし→status不変。
- `computeRecovery`/`calculateRecovery` は既存テストで担保（不変）。
- 本番 Playwright: 投資一覧・物件詳細（フロアマップ/投資タブ）で、未閲覧投資も最新の回収率/ラベルが出ることを確認。

## 7. 本番反映

- worktree 実装 → `13.x` へ FF-merge → `./deploy.sh`（view:cache 再生成。ルート変更なし）。新規クラスなし → `composer dump-autoload` 不要。Bug #26 該当なし（@json 追加なし）。
