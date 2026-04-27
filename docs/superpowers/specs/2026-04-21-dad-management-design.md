# DAD（土木事業）管理モジュール 設計書（Phase 1 モック）

**作成日:** 2026-04-21
**対象:** 土木事業（DAD）管理モジュール フェーズ1 — HTMLモックアップ作成
**前提ドキュメント:** `docs/DAD_土木事業_要件定義書_v1.md`（v1、431行）
**実装ブランチ:** `feature/dad-management`（main から分岐）

---

## 1. スコープ

### Phase 1 で作成するもの

- `docs/mockups/dad/` 配下に **13 本の HTML モックアップ**
- 4 リソース（`projects` / `clients` / `subcontractors` / `employees`）をカバー
- Tailwind + インラインスタイル（CLAUDE.md 準拠、Bootstrap 不使用）
- Alpine.js（タブ切替・モーダル・動的リスト等）

### Phase 1 で作らないもの

| 項目 | 扱い |
|------|------|
| Laravel コード（Controller / Model / Migration / Route） | Phase 2 |
| サイドバー追記 | Phase 2-A |
| `dad_*` テーブル | Phase 2-A |
| ダッシュボード | Phase 2-H（必要なら） |
| CSVインポート | スコープ外 |

### ブランチ戦略

- モック作成直前に `main` から `feature/dad-management` を作成
- モック1本ごとに Git 個別コミット
- リソース単位でユーザーレビュー（4区切り）

---

## 2. ファイル一覧（13 本）

### projects/（4 本）— 最優先・最初に作成

| ファイル | 役割 | 主要UI |
|---------|------|--------|
| `index.html` | 工事案件一覧 | フィルターバー（ステータス/種別/担当/年度）+ 集計エリア（案件数・受注額・原価・粗利・粗利率）+ テーブル10列 |
| `show.html` | 工事案件詳細 | **3タブ構成**: ①基本情報＋Google Maps＋金額サマリー4カード ②原価管理（Ajax CRUD + 差額色分け） ③人員配置（Ajax CRUD + 配置期間）+ 協力業者発注履歴集計 |
| `create.html` | 案件登録 | 基本情報13項目 + Google Maps ピン |
| `edit.html` | 案件編集 | create.html と同構造、ID付きで既存値表示 |

### clients/（3 本）

| ファイル | 役割 | 主要UI |
|---------|------|--------|
| `index.html` | 発注者一覧 | テーブル（種別バッジ/発注者名/担当者/電話/工事件数/詳細ボタン） |
| `create.html` | 発注者登録 | 種別セレクト/発注者名/担当者/住所/電話/FAX/メール/備考 |
| `edit.html` | 発注者編集 | create.html と同構造 |

### subcontractors/（3 本）

| ファイル | 役割 | 主要UI |
|---------|------|--------|
| `index.html` | 協力業者一覧 | テーブル（会社名/専門分野/電話/発注件数/発注合計額/詳細ボタン） |
| `create.html` | 協力業者登録 | 会社名/代表者/住所/電話/FAX/メール/専門分野/備考 |
| `edit.html` | 協力業者編集 | create.html と同構造 |

### employees/（3 本）

| ファイル | 役割 | 主要UI |
|---------|------|--------|
| `index.html` | 従業員一覧 | 在籍状況フィルター + テーブル（社員番号/氏名/役職/資格抜粋/現在の配置現場/在籍状況/詳細ボタン） |
| `create.html` | 従業員登録 | 社員番号/氏名/フリガナ/連絡先/役職/資格/入社日/在籍状況 |
| `edit.html` | 従業員編集 | create.html と同構造 |

**合計: 13 本**

> **注:** マスタ3種（clients/subcontractors/employees）の show は edit 画面が代用できるため省略。必要なら Phase 2 で追加可能。

---

## 3. UI 規約（全モックで統一）

### プロジェクト固有ルール（CLAUDE.md + docs/RULES.md 由来）

| 項目 | ルール |
|------|--------|
| CSS | 既存 Vite ビルドクラスのみ。新規は `<style>` または `style=""` |
| Alpine.js | `x-data` 内で `=>` 禁止。`<script>` に分離して `x-data="myFunc()"` 方式 |
| Blade | `@if/@else` は複数行、`@json()` 内で関数呼び出し禁止 |
| 日付入力 | `<input type="date">` 禁止 → 案C datepicker（Alpine版）を流用 |
| 金額表記 | `28,500,000円`（税抜、円サフィックス、`¥` 接頭辞禁止） |
| 粗利額の色 | `color: #047857; font-weight: 700;` |
| バッジ | インラインスタイルで実装（Enum の `badgeStyle()` 方式） |
| フォーム項目間隔 | `margin-bottom: 26px` |
| ページネーション | 20件/ページ |
| フィルターバー | `onchange="document.getElementById('filter-form').submit()"` 即時絞り込み + 控えめクリアボタン |
| 担当者表示 | 苗字のみ（同姓が居る場合のみフルネーム） |
| 決算年度 | 5月始まり（2026/5/1 〜 2027/4/30 が 2026 年度） |
| 都道府県デフォルト | 愛媛県 |
| Google Maps | `DECIMAL(10,7)`、CDN は `cdn.jsdelivr.net` のみ |

### モック特有の扱い

- フォーム `action="#"` method="post"、Ajax 部分はイベントをモック（実リクエストなし）
- テーブルには 3〜5 行のダミーデータを投入
- サイドバーはモックに含めない（独立した HTML として完結）
- レイアウト共通部分（ヘッダー等）はモックでは省略可。現実の Blade では `@extends('layouts.app')` で共通化される前提

### ダミーデータの一貫性

- 案件番号: `DAD-001` 〜 `DAD-012`（2026年度想定で12件）
- 発注者: 松山市/愛媛県/個人系など愛媛ローカルの実在風
- 担当者: `users` テーブルからの名前のみサンプル
- 金額: 見積 vs 実績で差額が出るパターン（超過・以内・同額）を混ぜる

---

## 4. 工事案件詳細（projects/show.html）のタブ設計

### 3タブ構成

```
┌─ Tab 1: 基本情報 ─────────────────────────────────┐
│  ・案件番号 / 工事名 / 種別 / 発注者リンク / ステータスバッジ │
│  ・工期（開始〜終了）/ 担当者（苗字のみ）               │
│  ・金額サマリー 4 カード                              │
│    ┌──────┬──────┬──────┬──────┐                │
│    │見積額│受注額│原価合計│粗利額│                   │
│    └──────┴──────┴──────┴──────┘                │
│  ・Google Maps（現場住所のピン表示）                  │
│  ・備考                                             │
└───────────────────────────────────────────────┘

┌─ Tab 2: 原価管理（Ajax CRUD） ────────────────────┐
│  ［＋ 原価追加］ボタン                                │
│  ┌─────────┬──────┬──────┬──────┬──────┬──────┐   │
│  │費用ｶﾃｺﾞﾘ│内容  │見積額│実績額│差額  │操作  │   │
│  ├─────────┼──────┼──────┼──────┼──────┼──────┤   │
│  │材料費    │生ｺﾝ  │500K  │520K  │+20K赤│編/削│   │
│  │外注費    │土工  │2M   │1.8M │-200K緑│編/削│   │
│  └─────────┴──────┴──────┴──────┴──────┴──────┘   │
│  フッター: 見積合計 / 実績合計 / 差額合計                │
└───────────────────────────────────────────────┘

┌─ Tab 3: 人員配置（Ajax CRUD） ────────────────────┐
│  ［＋ 配置追加］ボタン                                │
│  ┌─────────┬──────┬──────────┬──────┐           │
│  │従業員    │役割  │配置期間   │操作  │           │
│  ├─────────┼──────┼──────────┼──────┤           │
│  │山田太郎  │代理人│2026/5/1〜│編/削│           │
│  │鈴木一郎  │作業員│2026/5/10〜│編/削│           │
│  └─────────┴──────┴──────────┴──────┘           │
│  協力業者発注履歴（原価Tab2の外注費から自動集計）       │
│  ┌─────────┬──────┬──────┐                       │
│  │業者名    │工種  │合計額│                       │
│  └─────────┴──────┴──────┘                       │
└───────────────────────────────────────────────┘
```

### タブ切替の実装

- Alpine.js `x-data="{ activeTab: 'basic' }"` でタブ状態管理
- `x-show="activeTab === 'basic'"` でタブパネル表示制御
- タブボタンは `@click="activeTab = 'cost'"` 等

### Ajax モーダル（モック段階）

- 「原価追加」ボタンクリックで Alpine モーダル表示
- フォーム送信はイベント抑止（`@submit.prevent`）、モック段階では表示のみ
- 実装段階（Phase 2）で Ajax リクエストに差し替え

### 差額の色分けロジック

- `実績 > 見積` → 赤色 `color: #dc2626`
- `実績 <= 見積` → 緑色 `color: #047857`
- `実績未入力` → グレー `color: #6b7280`

---

## 5. 制作ワークフロー

### 手順

1. **ブランチ作成**

   ```
   git checkout main
   git pull origin main
   git checkout -b feature/dad-management
   ```

2. **ディレクトリ作成 → projects/ 4本を作成（複雑度が最も高い）**
   - `docs/mockups/dad/projects/{index,show,create,edit}.html`
   - 1本ごとに Git コミット
   - 4本揃ったら **ユーザーレビュー**（フィードバック反映後に次リソースへ）

3. **clients/ 3本を作成 → レビュー**

4. **subcontractors/ 3本を作成 → レビュー**

5. **employees/ 3本を作成 → レビュー**

6. **Phase 1 完了 → writing-plans で Phase 2（Laravel 実装）計画策定**

### コミット粒度

- モック1本 = 1コミット
- 日本語メッセージ例: `feat(dad/mockup): projects/index.html — 案件一覧モック作成`
- リソース単位でレビュー（4 → 3 → 3 → 3 の4区切り）

### Phase 1 完了の定義

- [ ] 13 本の HTML ファイルが全て作成されている
- [ ] 全ファイルがプロジェクト固有ルール（Alpine `=>` 禁止等）に準拠
- [ ] 全ファイルが `feature/dad-management` にコミット済み
- [ ] ユーザーが 4 リソース全てに OK 出し済み
- [ ] `docs/superpowers/specs/2026-04-21-dad-management-design.md`（本spec）が存在
- [ ] Phase 2 計画 `docs/superpowers/plans/2026-04-21-dad-management.md` 作成の準備が整っている

---

## 6. 要件定義書との対応表

| 要件書 §/L | 対応モック |
|----------|----------|
| §4.1 サイドバー配置（L206） | Phase 2-A で `sidebar.blade.php` に追記 |
| §4.2 工事案件一覧（L216-248） | `projects/index.html` |
| §4.3 工事案件詳細（L250-259） | `projects/show.html` |
| §4.4 工事案件登録（L261-281） | `projects/create.html` + `projects/edit.html` |
| §4.5 原価管理 Ajax（L283-301） | `projects/show.html` Tab 2 |
| §4.6 人員配置 Ajax（L303-312） | `projects/show.html` Tab 3 |
| §4.7 発注者一覧（L314-316） | `clients/index.html` + `clients/create.html` + `clients/edit.html` |
| §4.8 協力業者一覧（L318-320） | `subcontractors/index.html` + `subcontractors/create.html` + `subcontractors/edit.html` |
| §4.9 従業員一覧（L322-328） | `employees/index.html` + `employees/create.html` + `employees/edit.html` |
| §5 ルート設計 34本 | Phase 2 のルート実装時に参照 |
| §7 確定済みUIルール | 本spec §3 に転写 |

---

## 7. 次ステップ

Phase 1 完了後、本spec をインプットに `writing-plans` スキルで Phase 2 の実装計画を `docs/superpowers/plans/2026-04-21-dad-management.md` に作成する。

Phase 2 タスク分割の目安（計画段階で確定）:

| Phase | 内容 | 成果物 |
|-------|------|-------|
| 2-A | 基盤（DB / Enum / Model / サイドバー） | `dad_*` 6テーブル、Enum 5本、Model 6本、サイドバー追記 |
| 2-B | 工事案件 CRUD | `Dad/ProjectController` + Blade |
| 2-C | 原価管理 Ajax | `Dad/CostController` + Blade |
| 2-D | 人員配置 Ajax | `Dad/AssignmentController` + Blade |
| 2-E | 発注者マスタ | `Dad/ClientController` + Blade |
| 2-F | 協力業者マスタ | `Dad/SubcontractorController` + Blade |
| 2-G | 従業員マスタ | `Dad/EmployeeController` + Blade |
| 2-H | ダッシュボード（必要なら） | `Dad/DashboardController` + Blade |
| 2-I | 30点品質監査 + PR | 全体レビュー → `feature/dad-management` → `main` マージ |

---

**承認後のアクション:** このspecを `feature/dad-management` の最初のコミットとして git に残し、writing-plans へ移行する前に BACKLOG.md を「実装中」ステータスに更新する。
