# DAD（土木事業）管理 要件定義書 v1.2

## 変更履歴

| 版 | 日付 | 変更内容 |
|----|------|---------|
| v1 | 2026/04/17 | 初版作成 |
| v1.1 | 2026/04/24 | 工事案件登録画面に原価管理（内訳）カード追加、Excel実行予算アップロード機能追加、全入力欄 全角→半角自動変換ルール追加 |
| v1.2 | 2026/04/26 | 金額サマリー4カードに「3モードハイブリッド原価表示」仕様を明文化（空 / 見積 / 見込 / 実績の 4 状態を実績入力率で自動判定し、原価合計・粗利額・粗利率のラベルと値を同期）。差額の色分け規則と粗利率カードの追加を反映 |

---

## 1. 概要

DAD（土木子会社）の工事案件を一元管理するモジュール。見積から入金までのライフサイクル管理、見積vs実績の原価対比、協力業者管理、従業員の現場配置管理を行う。

### 1.1 管理対象

| 項目 | 内容 |
|------|------|
| 年間工事件数 | 30件以上 |
| 工事種別 | 公共工事（道路・河川・下水道等）＋ 民間工事（宅地造成・外構等） |
| 案件ライフサイクル | 見積 → 受注 → 施工中 → 完工 → 入金済み |

### 1.2 開発スコープ

| # | 機能 | 内容 |
|---|------|------|
| 1 | 工事案件管理 | 案件のCRUD・ステータス管理・見積〜入金のライフサイクル |
| 2 | 原価管理 | 費用項目別の見積額・実績額の対比管理 |
| 3 | 発注者管理 | 元請け・発注者のマスタ管理 |
| 4 | 協力業者管理 | 外注先の会社情報＋工事ごとの発注履歴 |
| 5 | 従業員管理 | 従業員名簿（資格含む）＋工事ごとの人員配置 |
| 6 | 収支集計 | 年度別・工事種別別の売上・原価・粗利集計 |

---

## 2. DB設計

### 2.1 `dad_projects`（工事案件）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| project_code | varchar(20) | NO | 案件番号（自動採番: DAD-NNN） |
| project_name | varchar(200) | NO | 工事名 |
| project_type | varchar(20) | NO | 工事種別（DadProjectType Enum） |
| status | varchar(20) | NO | ステータス（DadProjectStatus Enum） |
| client_id | bigint FK → dad_clients | YES | 発注者 |
| site_address | varchar(300) | YES | 工事現場住所 |
| latitude | decimal(10,7) | YES | 緯度（Google Maps） |
| longitude | decimal(10,7) | YES | 経度（Google Maps） |
| estimate_amount | int | YES | 見積金額（税抜） |
| contract_amount | int | YES | 受注金額（税抜） |
| estimate_date | date | YES | 見積日 |
| order_date | date | YES | 受注日 |
| start_date | date | YES | 着工日 |
| completion_date | date | YES | 完工日 |
| payment_date | date | YES | 入金日 |
| period_start | date | YES | 工期開始 |
| period_end | date | YES | 工期終了 |
| staff_user_id | bigint FK → users | YES | 担当者（現場代理人） |
| memo | text | YES | 備考 |
| created_by | int unsigned | NO | 登録者 |
| updated_by | int unsigned | YES | 更新者 |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### 2.2 `dad_project_costs`（工事原価明細）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| project_id | bigint FK → dad_projects | NO | 工事案件 |
| cost_category | varchar(30) | NO | 費用カテゴリ（DadCostCategory Enum） |
| description | varchar(200) | YES | 内容・摘要 |
| estimated_amount | int | YES | 見積額 |
| actual_amount | int | YES | 実績額 |
| subcontractor_id | bigint FK → dad_subcontractors | YES | 協力業者（外注費の場合） |
| notes | text | YES | 備考 |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### 2.3 `dad_clients`（発注者・元請け）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| client_type | varchar(20) | NO | 種別（DadClientType Enum） |
| name | varchar(100) | NO | 発注者名 |
| representative | varchar(50) | YES | 代表者名・担当者名 |
| postal_code | varchar(10) | YES | 郵便番号 |
| address | varchar(200) | YES | 住所 |
| phone | varchar(20) | YES | 電話番号 |
| fax | varchar(20) | YES | FAX番号 |
| email | varchar(255) | YES | メールアドレス |
| notes | text | YES | 備考 |
| created_by | int unsigned | NO | 登録者 |
| created_at | timestamp | | |
| updated_at | timestamp | | |
| deleted_at | timestamp | YES | ソフトデリート |

### 2.4 `dad_subcontractors`（協力業者）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| company_name | varchar(100) | NO | 会社名 |
| representative | varchar(50) | YES | 代表者名 |
| postal_code | varchar(10) | YES | 郵便番号 |
| address | varchar(200) | YES | 住所 |
| phone | varchar(20) | YES | 電話番号 |
| fax | varchar(20) | YES | FAX番号 |
| email | varchar(255) | YES | メールアドレス |
| specialty | varchar(100) | YES | 専門分野（土工・舗装・配管等） |
| notes | text | YES | 備考 |
| created_by | int unsigned | NO | 登録者 |
| created_at | timestamp | | |
| updated_at | timestamp | | |
| deleted_at | timestamp | YES | ソフトデリート |

### 2.5 `dad_employees`（従業員）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| employee_code | varchar(20) | NO | 社員番号 |
| name | varchar(50) | NO | 氏名 |
| name_kana | varchar(50) | YES | フリガナ |
| phone | varchar(20) | YES | 連絡先 |
| position | varchar(50) | YES | 役職 |
| qualifications | text | YES | 保有資格（テキスト） |
| hire_date | date | YES | 入社日 |
| status | varchar(20) | NO | 在籍状況（DadEmployeeStatus Enum） |
| notes | text | YES | 備考 |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### 2.6 `dad_project_assignments`（工事人員配置）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| project_id | bigint FK → dad_projects | NO | 工事案件 |
| employee_id | bigint FK → dad_employees | NO | 従業員 |
| role | varchar(50) | YES | 役割（現場代理人・主任技術者・作業員等） |
| start_date | date | YES | 配置開始日 |
| end_date | date | YES | 配置終了日 |
| notes | varchar(200) | YES | 備考 |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**UNIQUE制約:** (`project_id`, `employee_id`)

---

## 3. Enum定義

### 3.1 DadProjectType（工事種別）

| enum値 | 表示名 | バッジスタイル |
|--------|--------|-------------|
| `public` | 公共工事 | `background: #dbeafe; color: #1e40af;` |
| `private` | 民間工事 | `background: #fef3c7; color: #92400e;` |

### 3.2 DadProjectStatus（案件ステータス）

| enum値 | 表示名 | バッジスタイル |
|--------|--------|-------------|
| `estimate` | 見積 | `background: #f3f4f6; color: #374151;` |
| `ordered` | 受注 | `background: #dbeafe; color: #1e40af;` |
| `in_progress` | 施工中 | `background: #fef3c7; color: #92400e;` |
| `completed` | 完工 | `background: #d1fae5; color: #065f46;` |
| `paid` | 入金済み | `background: #a7f3d0; color: #064e3b;` |
| `lost` | 失注 | `background: #e5e7eb; color: #6b7280;` |

### 3.3 DadCostCategory（原価費用カテゴリ）

| enum値 | 表示名 |
|--------|--------|
| `material` | 材料費 |
| `subcontract` | 外注費 |
| `labor` | 人件費 |
| `equipment` | 機械経費 |
| `overhead` | 諸経費 |
| `other` | その他 |

### 3.4 DadClientType（発注者種別）

| enum値 | 表示名 |
|--------|--------|
| `government` | 官公庁 |
| `municipality` | 地方自治体 |
| `company` | 民間企業 |
| `individual` | 個人 |

### 3.5 DadEmployeeStatus（従業員在籍状況）

| enum値 | 表示名 |
|--------|--------|
| `active` | 在籍 |
| `retired` | 退職 |

---

## 4. 画面設計

### 4.1 サイドバー配置

```
DAD                     ← 独立グループ（新規追加）
  ├── 工事案件
  ├── 発注者管理
  ├── 協力業者
  └── 従業員管理
```

### 4.2 工事案件一覧（/dad/projects）

**フィルターバー:**
- ステータス（デフォルト: 失注以外 / 全て / 各ステータス）
- 工事種別（全て / 公共工事 / 民間工事）
- 担当者（全て / 各担当者）
- 年度（5月始まり、デフォルト: 当年度）

**集計エリア（一覧上部）:**

```
┌──────────────────────────────────────────────────┐
│  2026年度                                DAD      │
│                                                    │
│  案件数    受注額合計      原価合計      粗利額合計    粗利率  │
│  12件    180,000,000円  145,000,000円  35,000,000円  19.4% │
└──────────────────────────────────────────────────┘
```

**テーブル列:**

| # | 列 | 内容 |
|---|-----|------|
| 1 | 案件番号 | DAD-001（詳細リンク） |
| 2 | ステータス | バッジ |
| 3 | 種別 | 公共/民間 バッジ |
| 4 | 工事名 | 太字 |
| 5 | 発注者 | 発注者名 |
| 6 | 受注額 | 円表記、右寄せ |
| 7 | 原価合計 | 実績額合計、右寄せ |
| 8 | 粗利額 | 色: #047857、右寄せ |
| 9 | 担当 | 苗字 |
| 10 | 詳細 | ボタン |

### 4.3 工事案件詳細（/dad/projects/{project}）

**表示内容:**
1. 基本情報（案件番号、工事名、種別、発注者リンク、現場住所、工期、担当者）
2. Google Maps（現場地図）
3. 金額サマリー4カード（受注額 / 原価合計 / 粗利額 / 粗利率）— **3モードハイブリッド原価表示**（§4.5.1 参照）。原価管理テーブルの実績入力率に応じてラベルと値が「見積 / 見込 / 実績」へ自動切替
4. 原価管理テーブル（費用カテゴリ別の見積額・実績額・差額）— Ajax CRUD
5. 人員配置テーブル（従業員名・役割・配置期間）— Ajax CRUD
6. 協力業者発注履歴（原価明細の外注費から自動集計）
7. 備考

### 4.4 工事案件登録

**セクション1: 基本情報**

| # | 項目 | 入力形式 | 必須 |
|---|------|---------|------|
| 1 | 工事名 | テキスト | ○ |
| 2 | 工事種別 | セレクト（公共/民間） | ○ |
| 3 | ステータス | セレクト | ○ |
| 4 | 発注者 | セレクト（発注者マスタ） | — |
| 5 | 現場住所 | テキスト | — |
| 6 | 見積金額 | 数値 | — |
| 7 | 受注金額 | 数値 | — |
| 8 | 見積日 | 日付 | — |
| 9 | 受注日 | 日付 | — |
| 10 | 工期開始 | 日付 | — |
| 11 | 工期終了 | 日付 | — |
| 12 | 担当者 | セレクト（users） | — |
| 13 | 備考 | テキストエリア | — |

**Google Maps:** 現場住所入力 → ジオコーディング → ピン表示・ドラッグ調整（仕入れ管理と同じパターン）

**金額サマリー4カード（見積モード固定）:** 金額情報カード直下に「受注額 / 原価合計（見積） / 粗利額（見積） / 粗利率（見積）」の 4 カードを表示。登録時点では実績額カラムが存在しないため、§4.5.1 の 3 モードハイブリッドのうち「見積モード」固定で動作する（`costItems` が 0 件なら `—` 表示）。受注額は `order_amount` を入力中の値とリアルタイム連動。

**セクション2: 原価管理（内訳）**

登録時点で原価内訳を一括投入できるように、案件詳細画面の原価管理テーブルと同じカードを登録フォームに内蔵する。

| # | 列 | 入力形式 |
|---|-----|---------|
| 1 | 費用カテゴリ | セレクト（Enum: 材料費 / 外注費 / 人件費 / 機械経費 / 諸経費 / その他） |
| 2 | 内容 | テキスト |
| 3 | 見積額 | 数値、右寄せ |
| 4 | 協力業者 | セレクト（外注費の場合） |
| 5 | 備考 | テキスト |
| 6 | 操作 | 削除 |

**カード右上ボタン:**
- `Excel取込` … セクション3 を同カード内にインライン展開
- `＋ 行追加` … 空行をテーブル末尾に追加して直接入力

**フッター:** 合計見積額（見積額カラムの自動合計）

**セクション3: Excel実行予算アップロード（インライン展開）**

工事会社ユーザーが既に Excel で作成している実行予算書を、手入力なしで取り込めるようにする。3ステップフロー。

| STEP | 内容 |
|------|------|
| 1. ファイル選択 | ドロップ領域 + ファイル選択ボタン（`.xlsx` / `.xls` / `.csv` 対応） |
| 2. 列マッピング | シート選択・ヘッダー行選択後、検出列ごとに「費用カテゴリ / 内容 / 見積額 / 協力業者 / 備考 / 無視」をセレクトでマッピング。ヘッダー名から自動推定 + サンプル3行表示 |
| 3. プレビュー | 警告サマリ（カテゴリ候補不一致件数・金額NG件数）+ 全行編集可テーブル。カテゴリ不一致行は黄ハイライト + 元値表示、金額NG行は赤ハイライト |

**取込時挙動:** `commitImport()` で `costItems` 末尾にアペンド（既存行は保持）。合計見積額が再計算される。

**カテゴリエイリアス自動変換:** `材料/材料代/資材 → 材料費`、`外注/下請/下請費 → 外注費`、`人件/労務/労務費 → 人件費`、`機械/重機/機材 → 機械経費`、`経費 → 諸経費`。

**モック段階:** クライアントサイド SheetJS（`cdn.jsdelivr.net/npm/xlsx@0.18.5`）で完結。サーバーアップロード無し。
**本番実装段階:** PhpSpreadsheet + `ProjectImportController@preview` / `@execute` に差し替え予定。UI パネルはそのまま流用。

### 4.5 原価管理（案件詳細画面内 — Ajax）

不動産の仕入れ管理・分譲地PJと同じAjax CRUDパターン。

**Excel取込:** 同機能は登録画面に実装（§4.4 セクション3 参照）。詳細画面で追加取込が必要になった場合は同じUIパネルを再利用する前提。

**テーブル列:**

| # | 列 | 内容 |
|---|-----|------|
| 1 | 費用カテゴリ | セレクト（Enum） |
| 2 | 内容 | テキスト |
| 3 | 見積額 | 数値、右寄せ |
| 4 | 実績額 | 数値、右寄せ |
| 5 | 差額 | 実績−見積（自動計算）。超過は赤、以内は緑 |
| 6 | 協力業者 | 外注費の場合のみ選択可 |
| 7 | 操作 | 編集・削除 |

**フッター合計行:**
- 見積合計 / 実績合計 / 差額合計（差額は超過=赤 `#dc2626` / 同額・以内=緑 `#047857`、`±0円` または `+/-` 符号付き）

#### 4.5.1 3モードハイブリッド原価表示（金額サマリー4カード）

**目的:** 案件のライフサイクル（見積中 → 施工中 → 完工）に応じて、入力済みの実績データを優先しつつ、未入力行は見積で補完して常に「現時点の見込原価」を表示する。

**判定ロジック:** 原価管理テーブルの行ごとの実績額（`actual_amount`）入力状態を集計してモードを決定する。

| モード | 判定条件 | 原価合計ラベル | 原価合計の計算式 |
|---|---|---|---|
| 🟦 見積 | 全行 実績未入力（実績入力数 = 0） | 原価合計（見積） | Σ 見積額 |
| 🟨 見込 | 一部のみ実績入力済（0 < 実績入力数 < 行数） | 原価合計（見込） | Σ（その行に実績があれば実績額、無ければ見積額で補完） |
| 🟩 実績 | 全行 実績入力済（実績入力数 = 行数） | 原価合計（実績） | Σ 実績額 |
| ⬜ 空 | 行 0 件 | 原価合計 | `—`（ダッシュ表示） |

**4カード構成（左→右）:**

| # | カード | 値 | 備考 |
|---|--------|----|------|
| 1 | 受注額 | `dad_projects.order_amount`（固定） | モード非連動 |
| 2 | 原価合計 | 上表のモード別計算式 | ラベルもモード連動 |
| 3 | 粗利額 | 受注額 − 原価合計 | ラベル: 「粗利額（見積）/ 粗利額（見込）/ 粗利額（実績）」、空モードは `—` |
| 4 | 粗利率 | 粗利額 ÷ 受注額 × 100（小数点1桁、`%` 後置） | ラベル: 「粗利率（見積）/ 粗利率（見込）/ 粗利率（実績）」、受注額 0 円・空モードは `—` |

**色設計:**
- 粗利額・粗利率カードは emerald 背景（`background: #ecfdf5; border-color: #a7f3d0;`）+ 文字色 `#047857`
- 行差額・差額合計の色分け: 赤 `#dc2626` = 超過 / 緑 `#047857` = 以内・同額 / 灰 `#9ca3af` = 実績未入力（行のみ）

**画面ごとの適用範囲:**

| 画面 | 4カード | モード切替 | 備考 |
|------|---------|-----------|------|
| 工事案件詳細（show） | ○ | 全モード自動切替 | 原価管理タブ内の `costRows` を集計。フル機能版 |
| 工事案件編集（edit） | ○ | 全モード自動切替 | `costItems` を文字列入力対応で sanitize して集計 |
| 工事案件登録（create） | ○ | 見積モード固定（簡略版） | 実績欄なし。`原価合計（見積）/ 粗利額（見積）/ 粗利率（見積）` 固定ラベル。`costItems` が 0 件なら `—` |

**実装根拠（モック）:**
- `docs/mockups/dad/projects/show.html` `projectShow()` — Helper 7本（`formatYen` / `hasActual` / `rowEffective` / `rowDiff` / `rowDiffDisplay` / `rowDiffColor` / `rowActualDisplay`）+ Computed 13本（`costMode` / `estimateTotal` / `actualTotal` / `diffTotal` / `costTotal` / `costLabel` / `costDisplay` / `grossProfit` / `grossProfitLabel` / `grossProfitDisplay` / `grossProfitRateValue` / `grossProfitRateLabel` / `grossProfitRateDisplay` / `diffTotalDisplay` / `diffTotalColor`）
- `docs/mockups/dad/projects/edit.html` `projectEdit()` — show 版と同等。`_num()` で文字列入力を sanitize（`String(v).replace(/[^0-9-]/g, '')`）
- `docs/mockups/dad/projects/create.html` `projectCreate()` — 簡略版（`costMode` getter 不要、`estimateTotal` のみで `costDisplay` / `grossProfit` / `grossProfitRateDisplay` を算出）

**Phase 2 Laravel 実装方針:**
- 集計ロジックは Blade 側の Alpine.js でフロント計算（モックの実装そのまま流用）。サーバー側で都度集計する必要はない
- DB スキーマ追加なし（既存 `dad_project_costs.estimate_amount` / `actual_amount` で完結）
- Excel 取込時に `actual_amount` は空のまま（実績は施工中〜完工で随時入力）

### 4.6 人員配置（案件詳細画面内 — Ajax）

**テーブル列:**

| # | 列 | 内容 |
|---|-----|------|
| 1 | 従業員 | セレクト（従業員マスタ） |
| 2 | 役割 | テキスト（現場代理人・主任技術者等） |
| 3 | 配置期間 | 開始日〜終了日 |
| 4 | 操作 | 編集・削除 |

### 4.7 発注者一覧（/dad/clients）

**テーブル列:** 種別バッジ、発注者名、担当者名、電話番号、工事件数、詳細ボタン

### 4.8 協力業者一覧（/dad/subcontractors）

**テーブル列:** 会社名、専門分野、電話番号、発注件数、発注合計額、詳細ボタン

### 4.9 従業員一覧（/dad/employees）

**フィルター:** 在籍状況（デフォルト: 在籍 / 全て / 退職）

**テーブル列:** 社員番号、氏名、役職、保有資格（抜粋）、現在の配置現場、在籍状況、詳細ボタン

---

## 5. ルート設計（概算）

### 5.1 工事案件（7ルート）

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| 1 | GET | `/dad/projects` | 案件一覧 |
| 2 | GET | `/dad/projects/create` | 案件登録 |
| 3 | POST | `/dad/projects` | 案件保存 |
| 4 | GET | `/dad/projects/{project}` | 案件詳細 |
| 5 | GET | `/dad/projects/{project}/edit` | 案件編集 |
| 6 | PUT | `/dad/projects/{project}` | 案件更新 |
| 7 | DELETE | `/dad/projects/{project}` | 案件削除 |

### 5.2 原価管理 Ajax（3ルート）

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| 8 | POST | `/dad/projects/{project}/costs` | 原価追加 |
| 9 | PUT | `/dad/projects/{project}/costs/{cost}` | 原価更新 |
| 10 | DELETE | `/dad/projects/{project}/costs/{cost}` | 原価削除 |

**Excel実行予算取込 追加ルート（本番実装時に追加予定）:**

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| — | POST | `/dad/projects/costs/import/preview` | アップロードファイル解析 + プレビューデータ返却 |
| — | POST | `/dad/projects/{project}/costs/import` | プレビュー確定 → 複数行を `dad_project_costs` に一括 INSERT |

※ モック段階ではクライアント JS（SheetJS）で完結。本番実装時に PhpSpreadsheet + これらのルートで差し替え。

### 5.3 人員配置 Ajax（3ルート）

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| 11 | POST | `/dad/projects/{project}/assignments` | 配置追加 |
| 12 | PUT | `/dad/projects/{project}/assignments/{assignment}` | 配置更新 |
| 13 | DELETE | `/dad/projects/{project}/assignments/{assignment}` | 配置削除 |

### 5.4 発注者管理（7ルート）

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| 14 | GET | `/dad/clients` | 発注者一覧 |
| 15 | GET | `/dad/clients/create` | 発注者登録 |
| 16 | POST | `/dad/clients` | 発注者保存 |
| 17 | GET | `/dad/clients/{client}` | 発注者詳細 |
| 18 | GET | `/dad/clients/{client}/edit` | 発注者編集 |
| 19 | PUT | `/dad/clients/{client}` | 発注者更新 |
| 20 | DELETE | `/dad/clients/{client}` | 発注者削除 |

### 5.5 協力業者管理（7ルート）

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| 21 | GET | `/dad/subcontractors` | 協力業者一覧 |
| 22 | GET | `/dad/subcontractors/create` | 協力業者登録 |
| 23 | POST | `/dad/subcontractors` | 協力業者保存 |
| 24 | GET | `/dad/subcontractors/{subcontractor}` | 協力業者詳細 |
| 25 | GET | `/dad/subcontractors/{subcontractor}/edit` | 協力業者編集 |
| 26 | PUT | `/dad/subcontractors/{subcontractor}` | 協力業者更新 |
| 27 | DELETE | `/dad/subcontractors/{subcontractor}` | 協力業者削除 |

### 5.6 従業員管理（7ルート）

| # | メソッド | パス | 内容 |
|---|---------|------|------|
| 28 | GET | `/dad/employees` | 従業員一覧 |
| 29 | GET | `/dad/employees/create` | 従業員登録 |
| 30 | POST | `/dad/employees` | 従業員保存 |
| 31 | GET | `/dad/employees/{employee}` | 従業員詳細 |
| 32 | GET | `/dad/employees/{employee}/edit` | 従業員編集 |
| 33 | PUT | `/dad/employees/{employee}` | 従業員更新 |
| 34 | DELETE | `/dad/employees/{employee}` | 従業員削除 |

**合計: 約34ルート追加**

---

## 6. 既存モジュールとの共通パターン

| パターン | 参照元 | DADでの適用 |
|---------|--------|-----------|
| 原価管理 Ajax | 不動産 仕入れ管理・分譲地PJ | 工事案件の原価明細 |
| Google Maps | 不動産 仕入れ管理 | 工事現場の地図表示 |
| ソフトデリート | 仕入れ先管理 | 発注者・協力業者 |
| 集計エリア | 不動産 契約管理 | 工事案件一覧の年度集計 |
| フィルターバー統一 | 全一覧画面 | 案件一覧・各マスタ一覧 |
| 見積vs実績対比 | **新規** | 原価テーブルの差額列 |
| 人員配置 | **新規** | 工事×従業員のピボット管理 |
| Excel一括取込 | **新規** | モック: SheetJS（client-side）/ 本番: PhpSpreadsheet。原価管理の実行予算書取込に適用 |

---

## 7. 確定済みUIルール（本モジュールにも適用）

| ルール | 内容 |
|--------|------|
| フィルターバー | `onchange` 即時絞り込み + 控えめクリアボタン |
| 金額表示 | 円後置（例: `45,000,000円`）、税抜 |
| 全角→半角変換 | 数値入力欄（`inputmode="numeric"` / `type="number"`）に全角数字を入力すると自動で半角に変換。`layouts/app.blade.php` の capture phase `input` リスナで全事業共通として実装済み |
| 粗利額の表示色 | `color: #047857; font-weight: 700;` |
| 3モードハイブリッド原価表示 | 金額サマリー4カードと原価テーブル tfoot の合計値・ラベルが、原価明細行の実績入力率に応じて「空 / 見積 / 見込 / 実績」の 4 状態を自動切替（§4.5.1 参照）。差額の色分け: 赤=超過 / 緑=以内・同額 / 灰=未入力 |
| CSS | 既存Viteビルドクラスのみ。新規はインラインスタイル or `<style>` |
| Alpine.js | `>` をHTML属性に書かない。scriptタグに分離 |
| Blade | `@if/@else` は複数行形式 |
| バッジ | インラインスタイルで実装 |
| フォーム項目間隔 | 26px |
| 担当者表示 | 苗字のみ（同姓の場合のみフルネーム） |
| 決算年度 | 5月始まり |
| ページネーション | 20件/ページ |
| 都道府県デフォルト | 愛媛県 |
| Google Maps | `DECIMAL(10,7)` 、ジオコーディング＋ピンドラッグ |
