# 未実装バックログ — 優先順位付き

## ✅ 優先度1: 賃貸マンション管理（実装完了）

詳細仕様: @docs/賃貸マンション管理_要件定義書_v1.md
実装計画: @docs/superpowers/plans/2026-04-20-mansion-management.md

### フェーズ1: モック作成（完了）

モック配置先: `docs/mockups/mansion/`

| モジュール | ディレクトリ | index | show | create | edit | 状態 |
|-----------|-------------|:-----:|:----:|:------:|:----:|------|
| 物件管理 | `properties/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 部屋マスタ | `rooms/` | — | — | ✅ | ✅ | create/edit のみ（一覧は物件詳細に内蔵） |
| 駐車場マスタ | `parkings/` | — | — | ✅ | — | 物件詳細に内蔵。create のみモック作成済み |
| 入居者管理 | `tenants/` | ✅ | ✅ | ✅ | ✅ | 完了（resident / parking_only 2区分対応） |
| 部屋契約 | `contracts/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 駐車場契約 | `parking-contracts/` | ✅ | ✅ | ✅ | ✅ | 完了（案C datepicker 適用済み） |

**賃料改定モック**（部屋契約・駐車場契約 共通パターン）:
- `contracts/revise.html` — 賃料＋共益費の改定（差分バッジ・改定理由付き）
- `parking-contracts/revise.html` — 月額料金の改定（差分バッジ・改定理由付き）

**解約処理モック**（部屋契約・駐車場契約 共通パターン）:
- `contracts/terminate.html` — 退去日・敷金精算（差引項目動的追加）・紐付く駐車場一括解約
- `parking-contracts/terminate.html` — 利用終了日・敷金精算・駐車場ステータス連動（使用中→空き）

**ダッシュボード**:
- `dashboard.html` — 「賃貸マンションダッシュボード」完了。部屋KPI5枚 → 物件別稼働状況テーブル → 空室カード + 空き駐車場カード（2カラム）。駐車場稼働情報は空き駐車場ヘッダーにインラインテキスト統合

**入居申込書**:
- `tenants/application.html` — 入居申込書アップロード画面完了。申込者情報 / ドラッグ&ドロップ + ファイル選択 / 推奨書類ヒント / アップロード済みファイル一覧（削除確認 + 削除履歴）

**モックはすべて完了**。次フェーズは Phase 2 Laravel 実装（ms_* テーブル / Enum / Controller / Blade / ルート約30本）。

### フェーズ2: Laravel 実装（完了）

全 9 Phase（A〜I）で実装完了:

| Phase | 内容 | 主な成果物 |
|-------|------|-----------|
| A | 基盤（DB / Enum / Model / サイドバー） | `ms_*` テーブル 8本、Enum 5本、Model 8本、サイドバー 3 パターン追記 |
| B | 物件管理 | `Mansion/PropertyController`、`properties/` Blade 5本 |
| C | 部屋マスタ | `Mansion/RoomController`、`rooms/` Blade 3本 |
| D | 駐車場マスタ | `Mansion/ParkingController`、`parkings/` Blade 3本 |
| E | 入居者管理 | `Mansion/TenantController`、`tenants/` Blade 5本（入居申込書アップロード含む） |
| F | 部屋契約 | `Mansion/ContractController`（賃料改定・解約・Ajax）、`contracts/` Blade 7本 |
| G | 駐車場契約 | `Mansion/ParkingContractController`（料金改定・解約）、`parking-contracts/` Blade 7本 |
| H | ダッシュボード | `Mansion/DashboardController`、`dashboard.blade.php` |
| I | 30 点品質監査 + PR | CLAUDE.md 準拠確認、@json 内関数呼び出し 1 件修正 |

**合計**: Controller 7本 / Blade 約 35 本 / ルート約 43 本 / Model 8本 / Enum 5本

---

## ✅ 優先度2: DAD（土木事業）管理（実装完了）

詳細仕様: @docs/DAD_土木事業_要件定義書_v1.md

### フェーズ1: モック作成（完了）

モック配置先: `docs/mockups/dad/`

| モジュール | ディレクトリ | index | show | create | edit | 状態 |
|-----------|-------------|:-----:|:----:|:------:|:----:|------|
| 工事案件 | `projects/` | ✅ | ✅ | ✅ | ✅ | 完了（原価管理カード + Excel取込 内蔵） |
| 発注者 | `clients/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 協力業者 | `subcontractors/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 従業員 | `employees/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 専門分野マスタ | `specialties/` | ✅ | — | ✅ | ✅ | create/edit + 一覧（show は無し） |

### フェーズ2: Laravel 実装（完了 — 本番稼働中）

| 区分 | 実装内容 |
|------|---------|
| Controllers | `Dad/{Project,Client,Subcontractor,Employee}Controller.php` + `Admin/DadSpecialtyController.php` |
| Models | `DadProject` / `DadProjectCost` / `DadProjectAssignment` / `DadClient` / `DadSubcontractor` / `DadEmployee` / `DadSpecialty` |
| Enums | `DadProjectStatus` / `DadProjectType` / `DadCostCategory` / `DadClientType` / `DadEmployeeStatus` |
| Blade | 23本（4 モジュール × index/show/create/edit/_form + projects の partial 3本: `_excel_import` / `_date-picker` / `_date-picker-row`） |
| ルート | 28本（リソース × 4）+ 7本（admin/dad-specialties） |
| サイドバー | 「DAD」グループに 4 項目登録済み |

**原価管理カード**: クライアント側 SheetJS で Excel 取込、サーバー側は ProjectController 内に preview/execute ロジック内蔵。カテゴリエイリアス自動変換（材料→材料費、外注/下請→外注費 等）、プレビューで カテゴリ不一致・金額NG を警告。

---

## ✅ 優先度3: 住宅事業 横断ダッシュボード（実装完了）

詳細仕様: @docs/superpowers/specs/2026-04-27-housing-cross-list-design.md
実装計画: @docs/superpowers/plans/2026-04-27-housing-cross-list.md

### 概要

`/housing` ルートに住宅事業ダッシュボードを新設。建売物件 + 注文住宅の **成約フォーカス** で KPI / 成約一覧 / 月次グラフを構成。

### 実装内容

- Controller: `Housing/HousingDashboardController` (1本)
- Blade: `housing/dashboard.blade.php` + 3 partial（KPI / 成約一覧 / グラフ）
- ルート: `/housing` (housing.dashboard)
- サイドバー: 住宅事業グループ先頭にダッシュボード項目追加
- フィルター: 年度（過去2年〜来年度+1 + 全期間）+ 期（全期/上期/下期）

---

## ✅ 優先度4: ZEAL（フィットネス事業）（実装完了）

詳細仕様: @docs/ZEAL_フィットネス事業_要件定義書_v2.md

### フェーズ1: モック作成（完了）

モック配置先: `docs/mockups/zeal/`

| ファイル | 役割 | 状態 |
|---------|------|------|
| `dashboard.html` | ZEAL ダッシュボード（KPI + 月会費売上 + Chart.js 月次グラフ） | ✅ |
| `members/index.html` | 会員一覧 | ✅ |
| `members/show.html` | 会員詳細（4 タブ + プラン変更モーダル） | ✅ |
| `members/create.html` | 会員登録 | ✅ |
| `simulations/` | 経営シミュレーション（追加要件） | ✅ |

### フェーズ2: Laravel 実装（完了 — 本番稼働中）

| 区分 | 実装内容 |
|------|---------|
| Controllers | `Zeal/{Dashboard,Inquiry,Member,Plan,Trainer,Store,Simulation}Controller.php` (7本) + `Admin/{ZealMemberImport,ZealSimulationCategory}Controller.php` (2本) |
| Models | `GymInquiry` / `ZealMember` / `ZealMemberContract` (SCD Type-2) / `ZealPlan` / `ZealStore` / `ZealTrainer` / `ZealSimulation` / `ZealSimulationCategory` / `ZealSimulationValue` (9本) |
| Enums | `ZealAcquisitionSource` / `ZealContractChangeReason` / `ZealGender` / `ZealGymInquiryStatus` / `ZealPurpose` / `ZealSimulationCalcType` / `ZealSimulationGroup` / `ZealWithdrawReason` (8本) |
| Blade | 19 本（`zeal/dashboard.blade.php` + `zeal/{inquiries,members,plans,trainers,stores,simulations}/`） |
| ルート | 約 70 本（プレフィックス `/zeal/*` + 経営試算表項目マスタ `admin/master/zeal-simulation-categories/*`） |
| サイドバー | 「ZEAL」グループに体験予約 / 会員 / プラン / トレーナー / 店舗 / 経営試算表 / ダッシュボードを登録 |

### Phase 別実装ステータス

| Phase | 内容 | 状態 |
|-------|------|------|
| 3-A | 基盤（DDL / Model 9本 / Enum 8本 / サイドバー / `config/database.php` の `zeal` 接続 / 外部 DB の `GymInquiry`） | ✅ |
| 3-B | 体験予約閲覧: `Zeal\InquiryController` index/show（read-only） | ✅ |
| 3-C | プランマスタ: `Zeal\PlanController` フル CRUD | ✅ |
| 3-D | トレーナーマスタ: `Zeal\TrainerController` Ajax CRUD | ✅ |
| 3-E | 会員管理: `Zeal\MemberController` フル CRUD + `changePlan` / `withdraw` | ✅ |
| 3-F | 会員 CSV インポート: `Admin\ZealMemberImportController` | ✅ |
| 3-G | ダッシュボード: `Zeal\DashboardController` + Chart.js | ✅ |
| 3-H | 30 点品質監査 + デプロイ | ✅ |
| 3-I | 店舗マスタ Ajax CRUD（追加要件）: `Zeal\StoreController` | ✅ |

### 追加実装（要件定義書 v2 範囲外で実施）

経営シミュレーション（経営試算表）を実装段階で追加し、本番稼働中:

- `Zeal\SimulationController` — CRUD + 実績連動 `syncActuals` / `syncActualsPreview`
- `Admin\ZealSimulationCategoryController` — 項目マスタ（ドラッグ&ドロップ並び替え対応）
- Phase 1〜7 + 予算機能 + 未確定月の予測表示まで稼働済み

---

## ✅ 優先度5: STEP 12 ダッシュボード（実装完了）

### 経営ダッシュボード（経営層のみ）

- Controller: `DashboardController::executive`
- ルート: `/dashboard/executive`（middleware: `role:executive`）
- Blade: `dashboard/executive.blade.php` + 5 partial（`_executive_filter` + `_executive_charts` + `_executive_housing` / `_executive_realestate` / `_executive_mansion` / `_executive_tenant`）
- 構成: 5 事業横断 KPI（テナント / 不動産 / 住宅事業 / 賃貸マンション / ZEAL）+ 月次推移グラフ（Chart.js、`cdn.jsdelivr.net` のみ）

### テナントダッシュボード（全ロール）

- Controller: `DashboardController::tenant`
- ルート: `/dashboard/tenant`
- Blade: `dashboard/tenant.blade.php` + 2 partial（`_tenant_summary_main` + `_tenant_buildings`）
- 構成: 空室一覧 / 契約満了間近 / 未対応問合せ / 直近の修繕・投資案件

### 自動ロール振り分け

`/dashboard` ルートが `role:executive` ユーザーであれば `dashboard.executive` に、それ以外は `dashboard.tenant` に自動リダイレクト（`DashboardController` 内のクロージャ）。

### 並行実装された事業別ダッシュボード（優先度1〜4 内で個別に実装済み）

- 住宅事業: `Housing\HousingDashboardController` → `/housing`（優先度3）
- 賃貸マンション: `Mansion\DashboardController` → `/mansion/dashboard`（優先度1）
- ZEAL: `Zeal\DashboardController` → `/zeal`（優先度4）

---

## ✅ 周辺ビル調査 第1段（テナント管理）— 本番稼働中

詳細仕様: @docs/superpowers/specs/2026-08-12-tenant-area-building-survey-design.md
実装計画: @docs/superpowers/plans/2026-08-13-tenant-area-building-survey-phase1.md

自社物件の周辺にあるビルの空室状況を定点観測し、テナント需給の肌感を数字で持つための機能。
**2026-08-17 に本番反映（`13.x` = `c8a445da`）。**

| 区分 | 実装内容 |
|------|---------|
| Controllers | `Tenant/{AreaBuilding,AreaBuildingSurvey,AreaBuildingTenant,AreaBuildingImport}Controller.php`（4本）|
| Models | `AreaBuilding`（SoftDeletes）/ `AreaBuildingSurvey`（月初正規化）/ `AreaBuildingTenant`（3本）|
| Enum | `AreaTenantStatus`（operating / vacant / unknown。状態エイリアスは順序非依存）|
| Support | `VacancyRate`（空室率を 1 箇所に集約）/ `FloorNumber`（B1 = -1 の相互変換）|
| Service | `Tenant/AreaBuildingListService` |
| Blade | 13本（`tenant/area-buildings/` + `surveys/` + `tenants/`）|
| ルート | **20本**（設計 §5.1 の 19 本 ＋ 座標一括取得 1 本）|
| DB | `area_buildings` / `area_building_surveys` / `area_building_tenants`（raw SQL ＋ migration の両方を維持）|
| テスト | 本機能で約 105 本追加（全体 781 tests / 4201 assertions green）|

### 主な機能

- 一覧: 入居率フィルタ（満室（100%）/ 空きあり（99% 以下）/ **入居率 75% 以下 / 50% 以下**）・
  調査年フィルタ・キーワード検索
  （ビル名 / **在籍中のテナント名**。退去済みは引っかからない）・インライン番号付きページネーション
  ⚠ 閾値と検索対象は第2段（下記）で変わった。第1段は 20 / 40 で所在地も検索していた
  ⚠ 表記は 2026-08-20 に入居率主体へ（下記）。**閾値そのものは動いていない**
- 詳細: 最新調査の KPI（**入居率 → 空室率**の順）・**調査時の実測とテナント明細の乖離警告**（両方を並べて出す。Bug #46 の教訓）・
  調査履歴・入居テナント一覧・Google マップリンク
- 登録編集: 地図でピン配置（**Street View は出さない＝課金方針**。押したときだけ地図を生成）・初回調査の同時作成
- Excel / CSV 取込: ビル＋調査 / テナント明細の 2 種。SheetJS は **SRI 付き**で読み込む
- 座標の一括取得: 住所から Geocoding。**1 棟 1 回・上限 200・取得済みは対象外**。
  取得できなかった棟は一覧の「位置」列に **未取得**と出し、再課金を防ぐ

### 第2段の着手条件

実データがある程度たまってから、**集計の粒度**（エリア別 / 用途別 / 前年同月比）を決める。
データを見る前に決めない。**色分けの閾値は 2026-08-19 の実データ 187 棟で確定した**（下記）。

⚠ 運用開始前に **Google Cloud Console 側の設定**が要る（コードでは対処できない）:
HTTP リファラー制限 / API 制限（Maps JavaScript API と Geocoding API のみ。Places は有効にしない）/ 予算アラート。
詳細は実装計画の Task 13 Step 13〜14。

---

## ✅ 周辺ビル調査 第2段の一部（一覧の地図タブと位置登録）— 本番稼働中

詳細仕様: @docs/superpowers/specs/2026-08-19-area-building-map-tab-design.md
実装計画: @docs/superpowers/plans/2026-08-19-area-building-map-tab.md

利用者の要件は「**所在地は分かりませんので所在地の入力・表示は必要ありません。すべてマップ上で表示します。**」。
住所が今後も入らない以上、住所からの座標一括取得は使えないので、**地図上をクリックして
187 棟の位置を上から順に登録できる**ようにした。
**2026-08-20 に本番反映（`13.x` = `4d3de70a`）。DB 変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| ルート | **2本追加**（`POST` / `DELETE /tenant/area-buildings/{building}/coordinates`。どちらも `role:executive,manager`。POST は**上書き可**、DELETE は**冪等**）。地図タブは既存 index に `?view=map` で統合し新ルートを作らない。周辺ビル調査は計 **22ルート**（`routes/web.php` の見出しと揃える）|
| Controller | `AreaBuildingController` に `storeCoordinate()` / `clearCoordinate()` / `mapPins()` / `mapUnlocated()` ＋ `index()` の `$isMap` 分岐 |
| Support | `VacancyRate` に `BAND_MID` / `BAND_HIGH` / `LEVEL_*` / `LEVELS` / `level()` を追加（**閾値はここ 1 箇所だけ**）|
| Service | `AreaBuildingListService::paginateRows()` を切り出し（地図タブは全件とページャの両方が要る）|
| Blade | `_map.blade.php` を新設（`?view=map` のときだけ include）＋ index / show / _form を改修 |
| テスト | 781 → **909 tests / 5457 assertions green** |

### 主な変更

- **空室率の帯を 0 / 25 / 50 に統一**（実データ 187 棟で 24:18:26:31 とほぼ四等分。20 / 40 だと赤が 4 割で差が見えない）。
  一覧フィルタも地図の凡例も `VacancyRate::BAND_MID` / `BAND_HIGH` を見る
- **地図タブ**（`?view=map`）: 空室率の帯で色分けしたピン・吹き出し・`fitBounds`（ピン 0 件なら松山市中心）。
  **ページングしない**（絞り込み後の全件）
- **登録モード**（経営層＋管理者のみ）: 未登録の棟のリストを出し、地図クリックで即保存 → 自動で次の棟へ。
  スキップ・置き直し（上書き）可。**保存しても地図の中心とズームを動かさない**
- **置いたピンを直せる（2026-08-21 追加）**: 登録モード中だけ、吹き出しに
  「**この棟に置き直す**」（その棟を今の棟にする。次の地図クリックが上書き）と
  「**位置を消す**」（confirm → DELETE → ピンを地図から外し作業リストへ戻す）を出す。
  ⚠ **ピンを押しただけでは「今の棟」を入れ替えない** —— 黙って入れ替えると次の地図クリックが
  意図しない棟に入る＝直そうとしている事故を作り直す。
  ⚠ **消したあとはその棟に留まる**（次へ送らない）。消す理由の大半は「棟を間違えた」
  「うっかり置いた」で、直後に置き直したいのが自然な流れ。
  ⚠ `AREA_MAP_UNLOCATED` は**ページを開いた時点で座標が無かった棟**しか持たないので、直したい棟は
  入っていない。`ensureInLocateList()` が Blade と同じ形の行を足す（`data-locate-index` と
  `onclick` の引数を必ず揃える）。
  ⚠ **`$canLocate`（＝ manager 以上 かつ 未登録が 1 棟以上）に丸ごと従うので、
  187 棟すべて登録し終えると登録モードごと消えて、この直し方も使えなくなる。**
  そのときの直し方は登録編集フォーム（緯度・経度欄）のまま。
- **所在地を画面から消した**（一覧の列 / 詳細 / 登録編集フォーム / キーワード検索）。
  ⚠ **DB 列・Excel 取込の「所在地」マッピング・住所からの座標一括取得は温存**
- **マーカーの形（2026-08-20 追加）**: 直径 14px の丸だと背景の白い街路に沈むという指摘を受け、
  モック 3 案から「**しずく型のピンを既定にし、拡大したときだけ数字つきの丸へ切り替える**」を採用。
  境目は `AREA_MAP_LABEL_ZOOM = 18` の 1 箇所（松山で 0.50m/px ＝ 30m 離れた棟が 60px 離れる）。
  ⚠ 数字は整数。四捨五入だと境界をまたぐ表示になり数字と色が矛盾する。
- **入居率を主に見せる（2026-08-20）**: 一覧の表に**入居率を空室率の前**に足し、詳細の KPI にも足し、
  地図の丸の中も入居率にした。フィルタのクエリキーは `vacancy` → **`occupancy`**、
  凡例のラベルも入居率の言い方（`満室（100%）` / `76〜99%` / `51〜75%` / `50% 以下`）。
  ⚠ **閾値（`BAND_MID` / `BAND_HIGH`）も `level()` も空室率のまま。** 同じ帯を反対側から言い直しただけ。
  ⚠ `入居率 = 100 − 空室率` を `VacancyRate` の中で整数演算のまま出す。
  **「営業 ÷ 総数」で独立に切り捨てると和が 99.9% になる行が出る**（Bug #46）。
  掃引テストが 39,710 通りの内訳で和 100.0% を固定している。
  ⚠ **丸の整数も同じ規則**で「100 − 空室率の整数」（private な `compactPercent()` が返す
  1 つの整数から空室率側・入居率側の両方を作る＝和が 100 になることが構造として保証される）。
  独立に切り捨てると和が 99 になり、帯（色）からもはみ出す（実測 290 件 → 100 件へ低減）。
  残る 100 件は「空室率 1% 未満」＝総区画 101 以上のみで、`compactLabel()` 側の既知の穴と同じ入力。
  ⚠ 既知の穴: 率が **1% 未満**だと `0%` と出るのに帯は low（黄）。到達には 1 棟 101 区画以上が必要で
  実データには無いため、純粋な切り捨てを優先して直していない（テストで件数 **100** を固定して名指し）。
  モック: `docs/mockups/tenant/area-building-map-markers.html`

### ⚠ 課金方針（設計書 §7）

**地図を生成するのは 2 箇所だけ**: 登録編集フォームの「地図で位置を指定」を押したとき / 一覧の地図タブを開いたとき。
**既定が「表」タブであることが load-bearing** で、表タブでは `maps.googleapis.com` を 1 行も読み込まない
（本番実測: `typeof google === "undefined"`）。Geocoding は本件で **0 回**（ビル名検索をしない決定）。
`streetViewControl: false`。⚠ **一括取得のボタンは表タブにだけ出す** — 地図タブにも出すと
Maps ローダーが同一ページに 2 本並び、Google が "included multiple times" を投げて
**どちらの callback も走らない**（実測）。

### 2026-08-20 本番確認で分かったこと

- 表タブ: 所在地の列なし / 9 列・幅合計 100% / `typeof google === "undefined"`（課金ゼロ）
- 地図タブ: ピン 5・マーカー 5・帯が high/high/mid/none/none に正しく割当・`fitBounds` で zoom 18 へ
- 登録モード OFF のまま地図クリック → **fetch 0 回**（ゲートが実挙動で効く）
- 保存の往復（POST + CSRF）が成功し、**`setCenter` / `setZoom` / `fitBounds` / `panTo` を一度も呼ばない**
- ⚠ **本番の座標は 5 棟だけ入っており、残り 182 棟が未登録**（第1段で手入力された分）
- ⚠ **地図タイルの見た目は未確認。** 自動操作したタブが `document.hidden === true`（バックグラウンド）で、
  Google Maps はその間タイル描画を止める（素の Map も `StaticMapService.GetMapImage` に落ちた）。
  **前面のタブで人が目視すること。** コンソールにエラーは 0 件で、リファラー制限も通っている
- ⚠ ローカルでは `RefererNotAllowedMapError`（API キーの HTTP リファラー制限に `localhost` が無い）。
  ローカルで地図の見た目を確認したいなら Google Cloud Console で `localhost` を許可する必要がある

---

## ✅ テナント管理 一覧の並び替え（物件一覧・部屋一覧）— 本番稼働中

詳細仕様: @docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md
実装計画: @docs/superpowers/plans/2026-08-25-tenant-list-sorting.md
モック: @docs/mockups/tenant/list-sorting.html

列見出しを押して並び替えられるようにした。**JavaScript は 1 行も足していない**（見出しは素の `<a href>`）。
最終コミットは 2026-08-26。⚠ **反映日そのものは記録が無い**が、本番の
`build/assets/app-cUmeSh4E.css` に `.sortable-th-link:focus-visible` が在ることで転移を裏取り済み（2026-08-29 実測）。

| 区分 | 実装内容 |
|------|---------|
| Support | `App\Support\ListSort`（`?sort=` / `?dir=` の解釈・3 状態の遷移・リンク URL 生成。**並べ替えはしない**）|
| Blade | `components/sortable-th.blade.php` / `components/sort-hidden.blade.php` を新設 |
| 対象列 | 物件一覧＝入居率・賃料収入（PHP で並べる）／部屋一覧＝面積・家賃・月額合計（SQL で並べる）|
| ルート / DB | **どちらも変更なし** |

### 設計の要点（次に触る人向け）

- **並び替えはサーバ側で全件に対して行い、そのあとページを切る**（1 ページ目の中だけで並ぶ壊れ方を原理的に排除）
- **3 状態**: 既定 → 降順 → 昇順 → 既定（1 回目が降順なのは金額・率なので「多い順」を先に見たいため）
- **「—」は昇順でも降順でも末尾。** ただし対象は「画面に `—` と出る列」だけ
  （`units.rent` は nullable だが画面は `0円` なので `COALESCE(units.rent, 0)` で 0 として並べる）
- **同点は既定順のまま**（PHP のソートは 8.0 以降 stable / SQL は既定順の列をタイブレークに残す）
- 見出しを押したら **1 ページ目へ戻す**（`ListSort::url()` が `page` を落とす）
- ⚠ `Arr::query()` は **null のキーを丸ごと捨てる**ので、リンク生成前に `''` へ正規化する（Bug #31）

---

## ✅ 周辺ビル調査 第2段の一部（一覧の並び替えと見出しの視認性）— 本番稼働中

詳細仕様: @docs/superpowers/specs/2026-08-28-area-building-sorting-design.md
実装計画: @docs/superpowers/plans/2026-08-28-area-building-sorting.md
モック: @docs/mockups/tenant/sortable-header-affordance.html（見出しの手掛かり 3 案の比較）
並び替えの操作感: @docs/mockups/tenant/list-sorting.html

利用者の依頼は 2 つ —— ①**周辺ビル調査の一覧も並び替えたい** ②**どの列が並び替えできるのか分からない**。
併せて**既定の並び順をビル名の昇順**に変えた（従来は空室率の降順）。
**2026-08-30 に本番反映（`13.x` = 64c33eec ＋ 本番確認の追記）。DB 変更・ルート追加は無し。**

| 区分 | 実装内容 |
|------|---------|
| Support | `ListSort::clearUrl()` を追加（**並び順だけ**を解除し絞り込みは残す）|
| Service | `AreaBuildingListService` に `SORT_COLUMNS`（7 列のラベルと「向きの言い方」）/ `filteredRows()` / `applySort()` / `sortValue()` |
| Blade | `components/sort-bar.blade.php` を新設（現在の並び順バー）＋ `sortable-th` に点線下線と濃い矢印 |
| CSS | `resources/css/app.css` に `.sortable-th-label` の点線下線（状態は `<th>` の `aria-sort` を見る）|
| 対象列 | 総階数 / 営業 / 空き / 不明 / 入居率 / 空室率 / 最終調査 の **7 列**（ビル名・位置・操作は並び替えない）|
| ルート / DB | **どちらも変更なし** |
| テスト | 997 → **1043 tests / 6549 assertions green** |

### 主な変更

- **既定の並び順がビル名の昇順**（`baseQuery()` の `ORDER BY area_buildings.name, id`）。
  ⚠ **PHP 側では一切並べ替えない** —— `filteredRows()` は `map()` / `filter()` だけで順序を保つ。
  構造テスト `AreaBuildingListTest::test_filtered_rows_does_not_sort_in_php` が固定している
- ⚠ **漢字は読み順（あいうえお順）にならない**（読みがな列が無いので符号位置順。仕様）
- **入居率の並びは空室率の符号を反転して出す**（別々に計算すると画面の 2 つの数字と並び順が食い違う。Bug #46）
- **「—」は昇順でも降順でも末尾**（`partition` で分けて連結）。
  ⚠ 「調査回はあるが総区画 0」の行は率だけが `—` なので、**列によって末尾かどうかが変わる**のが正しい
- **見出しの手掛かり**: 未使用の列にも `#6B7280` の ⇅（見出し背景 `#F9FAFB` に対し 4.63:1）＋
  ラベルにだけ点線下線。⚠ **点線そのものは 2.43:1 で 3:1 に届かない**のは承知のうえの選択で、
  手掛かりの本体は矢印のほう
- **現在の並び順バー**（表の上）: 「並び替え: 既定（ビル名順）」/「並び替え: 入居率 高い順」＋**解除**。
  ⚠ 解除は**並び順だけ**を消す（フィルタごと初期化する「クリア」とは役割が違う）
- **地図タブの登録モードの作業リストは、表の並び替えに追従しない**（常にビル名の昇順）。
  登録作業の途中で順番が変わると事故になるため。`index()` が並び替え**前**の行を渡す

### ⚠ 実装中に見つけた本番級バグ（この 1 件だけ実ブラウザでしか見えなかった）

**並び替え中の列にマウスを乗せると、緑の下線がグレーに落ちていた。**
`.sortable-th-link:hover .sortable-th-label` が (0,3,0)、当時の
`th[aria-sort="…"] .sortable-th-label` が (0,2,1) で、**CSS の順序に関係なくホバーが勝つ**
（詳細度が違う 2 本には「同じ詳細度なら後勝ち」は当てはまらない）。
セレクタに `.sortable-th-link` を挟んで (0,3,1) にして決着。
⚠ **PHP も `view:cache` も 1013 本のテストも全部素通りした。** 実ブラウザで測ること。

### 検証

- **変異テスト 17 通りすべてで赤を実測**（プラン Task 10）。うち 1 通りは測って初めて穴が見つかり、
  テストを足してから赤になった（バーのピルが解除リンクの `aria-label` に部分一致していた）
- **ブラウザ確認 7 点**（プラン Task 11）。使い捨て SQLite ＋ `artisan serve` で 3 画面とも目視
- **本番確認 6 点**（プラン Task 12。2026-08-30）。⚠ 本番の並びは `utf8mb4_unicode_ci`
  （全角数字とアルファベットの大小が畳まれる）で、PHP のバイト順とは別物。
  Task 9 の refactor で PHP 側の名前比較を 0 本にしてあるので食い違いは起きない。
  ⚠ **地図タイルの見た目だけ未確認**（自動操作のタブは `document.hidden` でタイルが描かれない）。
  ⚠ 本番の座標登録は **31 棟**まで進んでいた（2026-08-20 の記録は 5 棟）

---

## ✅ 周辺ビル調査 第2段の一部（地図から店舗・駅のピンを消す）— 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-08-30-area-building-map-poi-design.md
実装計画: @docs/superpowers/plans/2026-08-31-area-building-map-poi.md

利用者の依頼は「地図で見るときに店舗の表示があり、設置しているビルのピンが被って見にくい。
これは店舗や場所のピンを表示しないようにすることは可能ですか？」。
Google Maps の POI（店舗・施設）と駅・バス停の**ラベルだけ**を消した。道路名・地名・行政区画は残る。
**2026-08-31 に本番反映（`13.x` = `bf9aecf1`）。DB 変更・ルート追加は無し。**

| 区分 | 実装内容 |
|------|---------|
| Blade | `_map_style.blade.php` を新設（`AREA_MAP_STYLES` の**唯一の定義**）＋ `_map` / `_form` が `@include` |
| 地図オプション | 両方の `new google.maps.Map(` に `styles: AREA_MAP_STYLES` と `clickableIcons: false` |
| テスト | 1043 → **1050 tests / 6680 assertions**（`AreaBuildingMapPoiTest` 7 本 ＋ 実駆動ハーネスの改修）|
| ルート / DB | **どちらも変更なし**（地図を生成する箇所は増えも減りもしない ＝ 課金方針 不変）|

### 要点

- **適用は周辺ビル調査の 2 箇所だけ。** 仕入れ案件・分譲地・DAD の地図（10 箇所）は
  「周辺に何があるか」を見る用途なので触らない。`test_the_other_maps_in_the_app_are_left_alone`
  が広げる変更を自動で止める（アプリ全体 12 箇所を走査し、スタイルが乗った集合を完全一致で固定）
- ⚠ **`styles` が効くのは地図が Map ID を持たないから**（本番実測 `get('mapId') === null`）。
  `mapId` が付くと Google は `styles` を**丸ごと無視する**ので、引数に `mapId` が無いことも対で固定した。
  ⚠ 両ビューは deprecated な `new google.maps.Marker` を使っており、後継の
  `AdvancedMarkerElement` は **Map ID を要求する** —— マーカー移行の日にこれを踏む
- ⚠ **`clickableIcons: false` は未測定の二重防御**（設計書 §3.3）
- ⚠ **航空写真 / ハイブリッドでは POI が戻る。** JSON の `styles` が効くのは roadmap の基図だけ

### テストの作り方で実測した穴（同型を書くとき用）

**変異 24 通りを実測**（23 赤 / 1 緑）。うち **6 件は「変異を当てても全テスト緑」だった穴**で、
どれも実装コードではなく**テスト設計**の欠落だった:

| 穴 | 症状 |
|---|---|
| 定義の**唯一性**を見ていない | `@include` をインラインの複製に置換 → 303 テスト緑（partial を作った理由そのもの。Bug #41）|
| 定義の**位置**を見ていない | `@include` を `async` な Maps ローダーの後ろへ移動 → 全テスト緑（callback が定義前に走る余地。Bug #28「位置まで固定すること」）|
| **実行されるか**を見ていない | `<script type="module">` / `type="text/template"` / 囲いを外す → いずれも全テスト緑（`var` が global に出ない・そもそも実行されない）|
| 正規表現が**狭い** | `window.AREA_MAP_STYLES = […'on'…]` を足す → 実行時はそちらが勝って POI が戻るのに全テスト緑 |
| **部分一致**で見ていた | `styles: AREA_MAP_STYLES` は `AREA_MAP_STYLES_V2` に前方一致 → **`_form` だけ全 1049 緑**。`_map` は実駆動ハーネスが拾うので、**`_map` に変異を当てると「守られている」と誤読する**（Bug #44「被覆されているはずの場所へ当てる」）|
| ハーネスの**抽出範囲**を見ていない | partial の開始タグを消すと 19 本が `SyntaxError` という原因を名指ししない理由で落ちる |

⚠ **`AreaBuildingMapTabTest` は画面の `<script>` を node の `vm` で実駆動する。**
`styles:` を渡した瞬間 `AREA_MAP_STYLES` 未定義で 19 本が赤化した ——
**これは事故ではなくハーネスが正しく仕事をした結果**。サンドボックスに配列を直書きせず、
**同じ HTML から partial のスクリプトも切り出して前置き**するよう直した。おかげで
`@include` を消すと**構造 2 本 ＋ 振る舞い 19 本 = 21 本**が落ちる。

### ⚠ テストで測れないこと（デプロイ後の目視が最終検証）

1. **POI が画面から実際に消えるか**（Google が描くもの）
2. **`_form.blade.php` の JS を実行するものが何も無い** —— `_map` は node vm で実駆動されるが
   `/create` `/edit` は構造テストだけ。**今回いちばん大きい穴**
3. `clickableIcons: false` が効いているか
4. コンストラクタ引数**以外**の経路（`setOptions()` で後から足す経路は双方向とも不可視）
5. **本番の `view:cache` コンパイル**（Bug #21 / #26 が「本番だけ壊れる」前例）

⚠ **スコープガードは定数参照だけを見る** —— 配列リテラルを直書きして他の地図へ広げる経路は
捕まえられない（実測で緑）。塞がない理由: 他部署の画面に `AREA_MAP_STYLES` は未定義なので
コピペだけでは漏れず、持って行くには `@include` も要り、そうすれば自然に書くのは
コンストラクタ形（＝ガードが捕まえる）。リテラル直書きは「その地図を意図的にスタイルする」
という別の決定であって、こちらの変更の漏出ではない。

### 本番確認の手順（⚠ 変数名が画面ごとに違う）

⚠ **前面のタブで、roadmap（航空写真でない）で見る。** 自動操作したタブは
`document.hidden === true` で Google がタイルを描かない。

1. `?view=map` で店舗・駅のアイコンと名前が出ていないこと
2. 道路名・地名は出ていること（全部消えていたら消し過ぎ）
3. ビルピンが読めること
4. 登録モードで**元は店舗アイコンがあった場所**をクリックして座標が保存されること
   （`clickableIcons: false` を唯一観察できる手段）
5. `/create` `/edit` の「地図で位置を指定」でも POI が出ないこと
6. コンソール — 地図タブ `areaMapInstance.get('styles')` / **新規登録・編集 `areaMap.get('styles')`**
   （`_form` は `var areaMap`。「地図で位置を指定」を押すまで生成されない）。
   ⚠ 返るのは**渡した値**であって Google が適用した結果ではない
7. `/create` `/edit` でコンソールにエラーが 0 件（この 2 画面の JS はテストが一度も実行していない）

⚠ **課金方針（設計書 §7）は目視不要** —— `AreaBuildingMapTabTest` が
「既定の表タブに `maps.googleapis.com` が無いこと」をテストで固定済み。

### 本番確認の結果（2026-09-01 実測）— 7 点中 6 点 ✅ / 1 点は意図的に未実施

⚠ **本番の URL は `/index.php/` を挟む。** 素の `.../manage/tenant/area-buildings?view=map` は
302 で `/index.php/dashboard/executive` へ流れる（memory の記録どおり）。

| # | 結果 | 実測した内容 |
|---|:--:|---|
| 1 | ✅ | **zoom 18 の大街道・二番町**（松山で最も店舗が密な区画）で店舗アイコン・店舗名が **1 つも無い** |
| 2 | ✅ | 「三番町通り」「二番町通り」「大街道」＋「2丁目」「二番町2丁目」＋国道番号 56 / 11 が出ている |
| 3 | ✅ | 108 棟のピンの入居率（100% / 84% / 48% / 0% …）が色帯つきで判読できる |
| 4 | — | **意図的に未実施**（下記）|
| 5 | ✅ | `/create` で「地図で位置を指定」→ zoom 18 の松山市駅前でも POI 無し。道路名・地名は残る |
| 6 | ✅ | 地図タブ `areaMapInstance.get('styles')` / `/create` `areaMap.get('styles')` とも `poi/labels: off` ＋ `transit/labels: off` の 2 件 |
| 7 | ✅ | `/create`・地図タブとも**コンソールエラー 0 件** |

**⚠ `mapId` が両方の地図で `null` であることを実測した。** これが非 null だと Google は
`styles` を**丸ごと無視する**ので、「渡した値が返る」だけでは証明にならない。
併せて `clickableIcons: false` / `mapTypeId: 'roadmap'` も両方で確認済み。

**⚠ 4 番（登録モードで元 POI 位置をクリック）は測る対象がもう存在しない。**
`elementType: 'labels'` は文字だけでなく**アイコンも含む**ため、上記のとおり店舗アイコン自体が
描画されていない ＝ クリックを横取りする POI が無い。一方コストは実在し、
**未登録の棟に一度は誤った座標を書き込む**ことになる。利用者が位置登録を進めるときに
正しい場所をクリックするので、そこで自然に確認できる。`clickableIcons: false` は
設計書 §3.3 のとおり**未測定の二重防御**のまま残す。

**ブラウザ不要の確認**（デプロイ済みの `90d98312` で実測）:

- `view:cache` → 全 **300 ビューを `php -l`** → **構文エラー 0 件**。
  Bug #21 / #26 の「本番だけ 500」を排除した（⚠ `view:cache` の成功表示だけでは足りない）
- コンパイル済み PHP を走査し、`styles: AREA_MAP_STYLES` が**アプリ全体の地図 12 箇所中 2 箇所だけ**
  （`tenant/area-buildings/_map` と `_form`）に載っていることを確認。仕入れ案件・分譲地・DAD の
  10 箇所は素通り ＝ スコープどおり

### 2026-09-01 に併せて分かったこと

- **座標登録が進んでいる**: **108 棟登録済み / 79 棟未登録**（8/20 は 5 棟、8/30 は 31 棟）。
  187 棟すべて登録し終えると登録モードごと消える点は上記の注意書きのとおり
- **`/create` は開いた時点で Maps API の JS を読み込むが、地図は作らない**
  （`areaMap === null`。ボタンを押して初めて `new google.maps.Map`）。
  Google の課金単位は map load なので設計書 §7 の方針どおり。
  ⚠ **`typeof areaMap` は `"object"` を返す**（`null` の typeof）ので、
  これを「もう地図がある」と誤読しないこと。`areaMap === null` で見る
- ⚠ **背景タブでボタンを押すと空振りする。** `areaMapsReady` が立つ前のクリックでは
  地図が生成されない（前面で押し直せば正常）。**バグではない**が、自動操作で確認するときは
  `areaMapsReady === true` を待ってから押すこと
- 地図タブの `maps.googleapis.com` のスクリプトタグは 6 本あるが、
  **ブートストラップローダ（`/maps/api/js`）は 1 本だけ**で残り 5 本は Google 自身が挿す
  モジュール（`common.js` / `util.js` / `map.js` / `marker.js` / `infowindow.js`）。
  設計書 §7 の「ローダーが 2 本並ぶと callback が走らない」には該当しない

---

## ✅ 工程表（ガント表示）— 不動産 / 住宅事業

詳細仕様: @docs/superpowers/specs/2026-08-31-realestate-schedule-gantt-design.md
実装計画: @docs/superpowers/plans/2026-09-01-realestate-housing-schedule-gantt.md
モック: @docs/mockups/realestate/schedule-gantt-proposals.html / @docs/mockups/realestate/schedule-board.html

契約や着工のあとに走る工程（造成・開発許可・確定測量・建築確認・上棟・販売など）を
Excel の工程表のように横棒で見る機能。**JS ライブラリ・外部 CDN は 1 本も足していない**
（日付 → 位置(%) は PHP の `GanttScale` が出し、Blade が inline style で置く）。

| 区分 | 実装内容 |
|------|---------|
| DB | `schedule_steps` **1 本**（ポリモーフィック。`re_` / `hs_` の接頭辞は付けない）|
| 親 | `ReProcurement` / `ReProject` / `HsProperty` / `HsCustomOrder` の **4 種**（建売契約は対象外＝工期は物件に属する）|
| Enum | `ScheduleStepCategory`（5 分類。**色分け以外の意味を持たない**）|
| Support | `GanttScale`（日付→%）/ `ScheduleStepStatus`（遅延・進捗・◆ の塗り分け）/ `LanePacker`（段の振り分け）|
| Model | `ScheduleStep` ＋ `Concerns\HasScheduleSteps`（親が実装するのは 4 メソッドだけ）|
| Service | `ScheduleCardService`（詳細カード）/ `ScheduleBoardService`（横断ボード）|
| Controller | `ScheduleStepController`（4 親共通の CRUD）＋ `RealEstate\ScheduleBoardController` / `Housing\ScheduleBoardController` |
| Blade | `_partials/_schedule_section` / `_schedule_gantt` / `_schedule_board` ＋ ボード 2 画面 |
| ルート | **18 本**（工程 CRUD 4 親 × 4 ＋ ボード 2）|
| テスト | 1050 → **1152 tests / 7118 assertions green** |

### 要点

- **画面の棒は 1 本だけ**（実績があれば実績、無ければ予定）。DB には予定・実績の 4 日付が入る。
  遅れは横断ボードのバッジと KPI で見る
- **工程名は案件ごとに自由入力**（マスタ無し）。並べ替えは **↑↓ ボタン**（ドラッグではない）
- **既存の日付列から ◆ を自動で描く**（工程行として作らない）。
  ⚠ **完成は 1 つだけ** —— `scheduled_completion_date` と `actual_completion_date` は同じ節目
- **詳細カードの partial は 1 本**を 4 画面が `@include` する（`resources/views/_partials/`。
  部署ディレクトリに置かない）
- **保存後のガントはサーバで描き直して返す**（`gantt_html`）。位置(%) の計算を JS 側に
  持たせないため（Bug #41）。日付を動かすと軸の範囲ごと変わるので部分的な再計算では足りない
- **ボードは部署ごとに 2 つ**で、対象クラスは各コントローラが**明示的に**渡す
  （サービス側に既定値を置くと、新しい部署のボードを足した人が引数を省略した瞬間に全部署が漏れる）
- **工程が 0 件の案件はボードに出さない**（件数だけ KPI の下に出す）
- **ページングしない**（絞り込み後の全件）。1 部署 200 件を超えたら見直す

### ⚠ 実装中に見つけた設計の欠陥（プランどおりでは動かなかった 3 件）

| # | 症状 | 原因と直し方 |
|---|---|---|
| 1 | 工程 CRUD が**全経路で `LogicException`** | **Laravel の暗黙のモデルバインドはメソッド引数の型宣言でしか働かない。** 4 親を 1 本のコントローラで受ける以上、親は型宣言できず `$request->route('procurement')` は**生の文字列**のまま届く。`OWNER_PARAMS` を「パラメータ名 => モデルクラス」にして自分で引く。⚠ **`Route::model()` によるグローバルな明示バインドは使えない** —— `{property}` はテナント物件（`App\Models\Property`）でも 8 本以上使われており、束縛すると**既存の別部署のルートが壊れる**。この曖昧さがあるので「マップのクラスがそのルート名の接頭辞と一致すること」を対で固定した |
| 2 | `update` / `destroy` が **500（`TypeError`）** | **ルートパラメータはコントローラへ位置順に渡される。** 未解決の親（文字列）が第 2 引数に入り `ScheduleStep $step` と食い違う（実測の呼び出し: `update(Request, '1', ScheduleStep)`）。`{step}` も名前で解決し、所有権チェックを解決メソッドに畳み込んだ（呼び出し側で忘れられないように）|
| 3 | 軸の月が **1 ヶ月ずれる** | **Carbon の `subMonths()` / `addMonths()` は月末日で溢れる**（実測: 2026-08-31 の 6 ヶ月前が **2026-03-03**。そのあと `startOfMonth()` を通しても 3/1）。**月初へ正規化してから加減算する。** 詳細カードの余白計算にも同じ欠陥があり、月末開始の工程で**前の余白が丸ごと消えて棒が左端に貼り付く**状態だった |

⚠ 併せて、テスト用スキーマ trait に **`hs_property_files` / `hs_custom_order_files`** を足した。
どちらも**リポジトリに正本の DDL が無く**（migration にも `database/sql/` にも無く本番で直接作られたまま。
`survey_questions` と同じ状況）、無いと**住宅の詳細 2 画面が `files` の読み込みで 500** する。
工程表のカードを 4 画面で開くまで、これを踏むテストが 1 本も無かった。

### やらないこと（設計書 §9）

工程間の依存関係 / ドラッグで期間を変える / 進捗% / 通知メール / **担当者フィルタ**
（4 親のどれにも担当者カラムが無い）/ 建売契約への工程 / DAD・賃貸マンション・ZEAL への展開 /
部署をまたぐ「全部入り」ボード / Excel 出力 / 工程テンプレート

### 検証

- **変異 17 通りを実測**（16 検出 / 1 は equivalent mutant）。穴は 1 件（実績優先の描画区間）で、
  テストを足して赤になることまで確認した。全結果はプランの「変異テストの実測結果」
- **コンパイル済みビュー 265 本を `php -l`** → 構文エラー **0 件**（Bug #21 / #26 の「本番だけ 500」を排除）
- **実ブラウザで 6 画面**（使い捨て SQLite ＋ 開発サーバ）。⚠ 下記はテストが原理的に測れない領域:
  - Ajax 保存で**ページを再読み込みせずに**ガントが描き直される（ノード差し替え・棒 7→8・「保存しました。」）。
    日付を入れると棒が引かれ、↑↓ の並べ替えが**編集表とガントの両方**に反映される
  - **`main.scrollWidth === main.clientWidth` を 6 画面 × 1800 / 1200 / 375px = 18 通りで実測**
    （Bug #29 は超過幅が一定なので片方の幅だけでは判定できない）
  - 375px で KPI が 2×2・フィルタが縦積み・ガントは横スクローラ内。**切り落としなし**
  - **6 画面ともコンソール出力ゼロ**

⚠ **デプロイ後に本番でも目視すること。** 特に ①月グリッドと棒の位置が視覚的に合っているか
②**本番の `view:cache` コンパイル**（Bug #21 / #26 が「本番だけ壊れる」前例）。
⚠ **302 を「アプリは正常」の証明に使わない** —— 認証リダイレクトはビューを描画する前に起きる。

---

---

## ✅ 工程表の取込（建売物件）— 本番稼働中

詳細仕様: @docs/superpowers/specs/2026-09-01-schedule-import-design.md
実装計画: @docs/superpowers/plans/2026-09-01-schedule-import.md

建売の工程管理は外部サービス（**ANDPAD**）で行っているので、その書き出しを取り込んで
工程表を自動で作る。**2026-09-01 実装・同日 本番反映（`13.x` = `24520a4d`）。**

⚠ **サービス名はアプリのどこにも出さない**（利用者の指示。2026-09-01）。書き出し元にすぎず
システム上に必要がないため、画面・コード・DB の値から取り除いてある
（`app` / `resources` / `routes` / `database` / `tests` に `ANDPAD` は **0 件**）。
名称が残るのは**この BACKLOG と設計書・プランだけ**で、これは「なぜサーバ側で xlsx を
解析しているのか」の記録として要るため（いずれも `deploy.sh` の rsync 対象外）。
画面の言い方は「工程表の取込」/ ボタンは「工程表を取り込む」。

| 区分 | 実装内容 |
|------|---------|
| 依存 | **`phpoffice/phpspreadsheet` を新規導入**（vendor 78M → 85M / phpoffice 本体 6.3M）|
| DB | `schedule_steps` に **`source` 列**（NULL=手入力 / `import`=取込）＋ 複合インデックス 1 本 |
| Support | `ScheduleImportSheet`（xlsx → 工程の配列）/ `ScheduleImportCategory`（大工程名 → 5 分類）|
| Controller | `Housing\ScheduleImportController`（form / preview / execute）|
| Blade | `housing/properties/schedule-import.blade.php` ＋ 共有 partial にボタン 1 個 |
| ルート | **3 本**（`role:executive,manager`）。工程表は計 **21 本**に |
| 固定資産 | `tests/fixtures/schedule-import/`（加工版 xlsx ＋ 加工スクリプト ＋ README）|
| テスト | 1152 → **1202 tests / 8065 assertions green**（+50）|

### 要点

- **クライアント側の SheetJS では読めない。** この xlsx はロゴ画像に拡張子が無く、
  SheetJS 0.18.5 がバイナリを文字列展開しようとして落ちる（原本・加工版とも実測）。
  よってアプリで唯一**サーバ側で Excel を解析する**取込になっている
- **ガント形式は取り込めない**（施工完了日が存在しない）。上げたら差し戻す。
  ⚠ 「次の工程の直前まで」で補うのは禁止 —— `足場組立 9/3` → `足場解体 10/26` が
  53 日になるが実際はどちらも 1 日
- **入口は建売物件の詳細だけ。** 現場名から物件を自動で特定しない
  （実測で現場名「JG保免中3号地」に当たる建売物件は本番に 0 件）
- **再取込は `source='import'` の工程だけ入れ替える。** 手で足した工程は残る
- **実績（`actual_*`）は取り込まない。** 取り込む日付は予定
- 大工程名は工程名に含める（`電気工事 / 器具取付`）—— 実データで `器具取付` が
  電気工事と給排水設備工事の 2 件ある

### ⚠ 設計書の誤り 3 件（実装時に実測で訂正）

| 設計書 | 実測 |
|---|---|
| §2.2「20 大工程」 | **21 種**（工程数 65 は正しい）|
| §2.3「`styles.xml` に規格外の `<u val="">`」「openpyxl は読めない」 | **`<u>` 要素は 0 件。openpyxl は普通に読める** |
| §2.3「ZIP64」 | 正確には**混成** —— ローカル・中央とも 23/23 が `0xFFFFFFFF` ＋ ZIP64 extra なのに **EOCD は通常形式** |

### ⚠ 設計書に無かった実装上の罠 4 件

1. **各シート末尾にページ番号だけの行**（A 列に `10`）—— 「大工程名が非空なら工程」で採ると 65 → **67** 件
2. **2 枚目も 1〜3 行目に見出しを持つ** —— 見出し飛ばしはシートごとに行う
3. **日付は Excel シリアルでなく文字列 `Y/m/d`**（セルは `t="str"`）→ `CsvDate::normalize()` を流用
4. **ヘッダーの工事期間と実データの範囲が一致しない**（D1 は 07/28 開始・実データ最小は 07/23）→ 検算に使わない

### 検証

- 全テスト **OK (1202 tests, 8065 assertions)**（ベースライン 1152 から +50）
- **変異 20 通りで赤を実測**（初回 19/20 → テストを 1 本足して 20/20）。詳細はプラン
- コンパイル済みビュー **266 本を `php -l`** → 構文エラー 0 件
- ✅ **ローカル実ブラウザで 7 点中 6 点を確認**（2026-09-01。使い捨て SQLite ＋ `artisan serve`）——
  実ファイルを上げてプレビュー **65 行**（現場名・住所・工事期間つき）→ 現場名と物件名の
  食い違い警告が出たうえで**確定できる** → ガントに **65 本**の棒（緑 55 / 青 6 / 灰 4。
  `getComputedStyle` の実測）→ **再取込で手入力 3 件が id ごと残る**（ANDPAD だけ総入れ替え）。
  `main.scrollWidth === main.clientWidth` を **4 画面 × 1800 / 1200 / 375px = 12 通り**で実測（Bug #29）。
  ボードは 68 span を **8 段**に詰めて崩れなし。**コンソール出力 0 件**。
  詳細はプランの「10-2 の実測結果」
- ⚠ **残る 1 点はガント形式の実ファイル（未取得）。** 見出しが揃わない xlsx を上げると
  赤帯つきでフォームへ差し戻り、DB も無変化であることはブラウザで確認したが、
  **ANDPAD のガント書き出しそのものでは未確認**
- ⚠ **本番ブラウザでの目視はデプロイ後に別途要る**（Bug #21 / #26 の「本番だけ壊れる」前例）

### 本番反映（2026-09-01 実施）

⚠ **DB が先・`deploy.sh` が後**（列が無い DB に列を使うコードを乗せると取込画面が 500）。
実際にこの順で流した:

1. **ALTER を先に実行** —— 流す前に本番を実測（`schedule_steps` あり / `source` **なし** / **0 行**）。
   実行後 `SHOW CREATE TABLE` で列と `idx_sched_source` を確認。**0 行なので移行は不要だった**
2. main repo で `composer install --no-dev`（**phpspreadsheet 5.9.0 ほか 5 パッケージを新規導入**）
   → `composer dump-autoload --no-dev --optimize`。⚠ `vendor/bin/phpunit` が入っていないこと
   （dev 混入なら `deploy.sh` が本番へ送ってしまう）を確認してから進める
3. `./deploy.sh`（exit 0。config / route / view の 3 キャッシュとも成功）
4. 本番で検証

**本番での確認（2026-09-01 実測）**:

| 見たこと | 結果 |
|---|---|
| PhpSpreadsheet と新クラスの autoload | `IOFactory` / `ScheduleImportSheet` / `ScheduleImportCategory` とも **OK** |
| ルート 3 本 | `GET` / `POST` / `POST …/preview` すべて登録済み |
| **コンパイル済みビューの `php -l`** | **266 本 / INVALID 0 件**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26）|
| アプリ側の `ANDPAD` 残存 | **0 件**（`app` / `resources` / `routes` / `database` を走査）|
| 建売物件の詳細 | 「工程表を取り込む」ボタンが出る。`main` の横スクロールなし |
| 取込画面 | タイトル・見出し・パンくず・ラベルとも新しい言い方。コンソール出力 0 件 |
| **解析器の疎通**（PHP 8.3.32）| 一時 xlsx を書いて `ScheduleImportSheet::read()` に通し **`format=list` / 2 行 / 分類も正**。<br>⚠ **DB には触れていない**（`schedule_steps` は 0 行のまま）。一時ファイルは削除済み |

⚠ **実運用の書き出しでの取込（確定まで）は未実施。** 実データを作る操作なので、
利用者が実ファイルを持って対象の物件で行う。

## ✅ 工程表を「現状の工程」に寄せる（住宅事業）— 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-02-housing-schedule-current-state-design.md
実装計画: @docs/superpowers/plans/2026-09-02-housing-schedule-current-state.md
モック: @docs/mockups/housing/schedule-current-state.html

工程表の取込を本番で 1 件流したら、**64 工程のうち 57 本に赤い「遅延」バッジ**が出た。
実績を取り込まない仕様と「予定終了が過ぎて実績が無ければ遅延」の判定が噛み合った結果で、
**44 件は実際には終わっている**のに全部「遅れている」と出ていた。

利用者の判断（2026-09-02）は「**予定・実績の概念は必要ない。工程表は現状の工程を確認するもの。
予定の管理は物件の基本情報で行う**」。そこで住宅事業（建売 / 注文住宅）から予定・実績の区別を外した。
**2026-09-02 に本番反映（`13.x` = `de7b7c28`）。⚠ 不動産（仕入れ案件 / 分譲地PJ）は一切変えていない。**

| 区分 | 実装内容 |
|------|---------|
| DB | `hs_properties` / `hs_custom_orders` の `actual_completion_date` → **`construction_start_date`**（着工予定日）。`schedule_steps` は**変更なし**（`actual_*` は不動産が使う）|
| Model | `HasScheduleSteps` に **`scheduleTracksActuals(): bool` を abstract で追加**（不動産 `true` / 住宅 `false`）＋ `ScheduleStep` の `saving` フックで住宅の `actual_*` を null 化 |
| Support | `ScheduleStepStatus::dateState()`（**これから / 進行中 / 済 / 未定**を日付だけで決める）＋ `STATE_LABELS` |
| Service | `ScheduleCardService` が行に `state` / `stateLabel` / `ring` を載せる。`ScheduleBoardService` が親に応じて絞り込み・案件ステータス・KPI カードの並びを返す |
| Controller | `ScheduleStepController::rules(Model $owner)` が住宅では `actual_*` をルートごと落とす。`Housing\ScheduleImportController` が取込時に着工予定日・完成予定日を入れる |
| Blade | `_schedule_gantt`（状態チップ・進行中の輪郭・ラベル欄の `min-width: 0`）/ `_schedule_section`（実績 2 列の出し分け）/ `_schedule_board`（KPI をループ描画）ほか住宅 6 本 |
| ルート | **変更なし** |
| テスト | 1202 → **1283 tests / 8351 assertions green**（+81）|

### 要点

- **状態は棒の濃さではなくラベル欄の「状態チップ」で出す**（案B′）。棒は分類色のまま、
  **進行中だけ `box-shadow: 0 0 0 1.5px #111827` の輪郭**。**赤は使わない**（遅延の概念が無い）
- ⚠ **濃淡（`opacity`）と枠線で状態を出す案はモックの採寸で破綻**した。
  ① 1 日の工程は幅 2.46px しかなく枠線 2 本（3px）が勝って**塗りに化ける**（実測 `clientWidth` 0 ＝
  未着手が進行中より濃く 22% 太く見え意味が反転。実データは 65 工程中 **26 件が 1 日**）
  ② `opacity: 0.4` は「済」を **1.6:1** に落とす（3:1 に届かせるには 0.76〜0.87 が必要）
- ⚠ **ラベル欄に `min-width: 0; overflow: hidden;` が要る**（両部署とも）。flex の `min-width` は
  既定 `auto` なので、チップで押し広げられた行は 262px を超え**その行の棒だけ最大 31.1px
  ＝ 軸 275 日で約 12.6 日ぶんずれる**（モックで実測。Bug #29 と同型）
- **住宅ボードは 状態 3 種 / KPI 3 枚**（3 枚とも数えるのは**工程**であって案件ではない）/ 遅延なし。
  **1 枚のボードに実績を持つ親と持たない親が混ざったら `LogicException`**
- **ガントの ◆ は 着工 と 完成 の 2 つ**（以前の「完成は 1 つだけ」は `scheduled` と `actual` が
  **同じ節目の予定と実績**だったから。付け替えた今は別の節目）
- **取込が 着工予定日 = `planned_start` の最小 / 完成予定日 = `planned_end` の最大**を常に上書きし、
  確定前のプレビューで予告する（**値が変わらない項目は出さない**）。
  ⚠ **ファイルのヘッダーの「工事期間」は使わない** —— 実測で実データの範囲と一致しない
  （固定資産は D1 が 07/28 開始なのに実データの最小は 07/23）。**画面に出るのと同じソースから出す**（Bug #46）
- **基本情報の並びは 着工予定日 → 完成予定日**。**新規登録画面にも出す**（旧実装は編集画面だけだった）

### ⚠ 既知の制約（設計書 §6 が触れていない相互作用。このブランチでは直していない）

実ブラウザ確認 14 点のうち 12 点は問題なし。残る 2 点:

1. **着工＝完成が同日**だと 2 つの ◆ とラベルが**完全に重なって判読不能**
   （実測: 両ラベルが x=827 / y=765 で座標完全一致。2 人が独立に再現）。
   ⚠ ただし**ラベル付き ◆ の衝突は今回の改修が持ち込んだものではない** —— 注文住宅
   （契約 / 着工 / 完成 / 引渡し）と不動産（契約 / 決済）は以前から同じ性質を持ち、
   `_schedule_gantt` の絶対配置は今回**未変更**。直すならラベル配置という別の設計判断が要る
2. **着工日が工程より大幅に早い**と軸が伸びて棒が圧縮される（1 日の工程が 2.82px → 2.05px）。
   ⚠ 軸はデータの範囲を張る必要があるので**挙動としては正しい**。しかも取込後は
   **着工予定日 = 工程の最小開始日**なので、食い違うのは手で編集した場合だけ

### 検証

- 全テスト **OK (1283 tests, 8351 assertions)**（ベースライン 1202 から +81）
- **変異は累計 60 通り以上を実測**。プランの表 1〜15（＋ 15 を住宅向き / 不動産向きに分けて 16）は
  **全通り検出・未検出 0 件**。16〜29 は各タスクのコードレビューで追加したもので個別に実測済み
- **コンパイル済みビュー 266 本を `php -l`** → INVALID 0 件（ローカル・本番とも）
- 実ブラウザ 14 点（使い捨て SQLite ＋ `artisan serve`）。
  `main.scrollWidth === main.clientWidth` を **4 画面 × 1800 / 1200 / 375px = 12 通り**で実測（Bug #29）。
  ラベル欄 **67 個すべて 262px**。コンソール出力 **0 件**

### ⚠ レビューが見つけた「テストは緑なのに守れていない」型（次に同型を書く人向け）

サブエージェント方式で各タスクに spec 適合 ＋ コード品質の 2 段レビューを回し、**20 件以上**が出た。
どれも実装ではなく**テスト設計**か**プランの取りこぼし**だった:

| 見つかったもの | 症状 |
|---|---|
| **設計書 §8 の「棒の色」がプランの Files 一覧ごと落ちていた** | 詳細カードは輪郭・ボードは薄塗り（`opacity: 0.45`）という**食い違いが本番に出るところだった** |
| **状態チップを固定するテストが 1 本も無い** | **凡例が行チップと同じ `>これから</span>` を出す**ため、行チップを丸ごと消しても全テスト緑 |
| **`bars[].late` / `steps[].delayDays` が不動産方向に無防備** | `false` / `0` に潰しても **1283 本全緑**。`border: 2px solid #DC2626` を肯定的に見るテストがアプリ全体に 1 本も無かった |
| **取込が親の `updated_by` を打刻していない** | 物件詳細の「更新: 〇〇」が**前回編集した別人の名前に取込の時刻**を貼り付ける（無いより悪い監査行）|
| **`2026/12/25` のアサートが空振り** | xlsx の工事期間セル（`2026/07/28〜2026/12/25`）に部分一致していた（Bug #43）|
| **詳細画面が一度もテストされていない** | `show.blade.php` のラベルだけを旧文言に戻す変異が全テスト緑 |
| **プランのテストが原理的に成立しない** | `planned_start` の無い行は `sanitizeSubmittedRows()` が弾き取込全体が差し戻されるので `derivedDates()` に届かない。テストは `null->toDateString()` で fatal になるはずだった |
| **トランザクションのテストが false-green** | 工程作成中の例外は `execute()` を**丸ごと中断**するので、日付更新を「外へ出した」変異でも到達せず結果が変わらない。注入点を `HsProperty::saving()` へ移して解決 |
| **`getDeclaringClass()` は trait メソッドの判別に使えない** | override の有無に関わらず**使用側クラス名**を返す。`getFileName()` なら判別できる |
| **安全網が離れたテストの検出力を奪う**（Bug #48 がテスト間で発生）| `saving` フックを入れた結果、取込の「実績は触らない」アサートが**何を書いても緑**になった |

⚠ **変異は「赤になった」だけでは足りない。落ちた理由の文言まで突き合わせる。**
実例: ある変異の赤は意図した正規表現アサートではなく **`$chipStyle[null]` による 500** が原因で、
`?? ''` を一時的に足して当て直して初めて網が本物だと分かった。

### 本番反映（2026-09-02 実施）

⚠ **DB が先・`deploy.sh` が後**（列が無い DB に新しいコードを乗せると住宅の画面が 500）。

1. **流す前に本番を read-only で実測**（`SELECT COUNT` のみ）:
   `hs_properties` 7 行 / `hs_custom_orders` 2 行、**`actual_completion_date` は両方とも全行 NULL**、
   `schedule_steps` は **64 行すべて建売で残存する実績 0 件** ＝ **データ移行も掃除も不要**と確定
2. **`ALTER` を 2 本実行**（前提を満たさなければ中断する安全装置を PHP 側に入れて実行）。
   事後に `information_schema` で `date` / nullable / default NULL と行数保持を確認
3. `./deploy.sh`（`npm run build` → rsync → `config:cache` / `route:cache` / `view:cache` すべて成功）
4. 本番で検証

⚠ **実行環境の注意（実測で判明。次に DDL を流す人向け）**

- **本番の既定 `php` は 7.4.33** で composer の要求（>= 8.3）を満たさない。
  **`/usr/local/php/8.3/bin/php` を明示する**
- **`sudo mysql` は非対話でパスワードを渡せない。** DDL は `artisan tinker --execute` から
  `DB::statement()` を **1 文ずつ**。⚠ **`PDO::MYSQL_ATTR_MULTI_STATEMENTS` が未設定**なので
  2 文を 1 回では流せない（`DB::unprepared(file_get_contents(...))` も不可）
- **`SHOW COLUMNS ... LIKE ?` はバインドを受け付けない**（`SQLSTATE[42000] 1064`）。
  列の有無は `information_schema.columns` を bind 付きで引く

**本番での確認（2026-09-02 実測）**

| 見たこと | 結果 |
|---|---|
| **コンパイル済みビューの `php -l`** | **266 本 / INVALID 0 件**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26）|
| 旧列名の残存 | `HsProperty.php` の**歴史的経緯の docblock 1 行のみ**（機能参照は 0）|
| 建売詳細（工程 64 件）| **200**。着工予定日 → 完成予定日の順 / 実際の完成日 0 / 状態チップ 済 58・これから 6 / **実績列 0** / ラベル欄 65 個 |
| 住宅ボード / 建売 新規登録 / 取込フォーム | いずれも **200**。新規登録にも着工予定日が出る |
| **仕入れ案件・分譲地PJ の詳細** | **200**。**実績開始・実績終了の列が残り**、状態チップ 0 / 輪郭 0 ＝ **住宅専用機能が漏れていない** |
| 注文住宅 詳細 | **200**。実績列 0 / 着工予定日あり |

⚠ **実運用の書き出しでの取込（確定まで）は未実施。** 本番の `hs_properties` は
`construction_start_date` / `scheduled_completion_date` とも全行 NULL なので、
**64 工程を持つ物件も ◆ は 0 個**のまま。取込をやり直すか手で入れるまで節目は描かれない
（欠陥ではなく値がまだ無いだけ）。

### ✅ 本番の実ブラウザ確認（2026-09-02。ログイン済みの実 Chrome で実施）

⚠ **URL は `/system/manage/index.php/...` を挟む**（素のパスは 302 で流れる）。

| 見たこと | 実測値 |
|---|---|
| 建売詳細 HS-008（工程 64 件）| **行チップ 済 58 ＋ これから 6 = 64**（凡例チップ 3 個とは `flex: 0 0 auto` で切り分けて計数）|
| **1 日の工程の棒** | 幅 **2.79px** / `backgroundColor: rgb(5,150,105)` の**塗り** / `boxShadow: none` ＝ **枠線に化けていない**（案B′を選んだ理由がそのまま裏付けられた）|
| 遅延バッジ | **0 件**（`+N日` の span が 0）。赤い要素は**行削除の × ボタンのみ**で遅延バッジではない |
| ラベル欄 | **65 個すべて 262px**（Bug #29 の押し広げが起きていない）|
| ◆ | **0 個**（着工予定日・完成予定日とも未入力のため。値が入れば描かれる）|
| 住宅ボード | **KPI 3 枚**（進行中の工程 / 30日以内に始まる工程 / 30日以内に終わる工程）/ 絞り込み **進行中・すべて・これから・済**（**遅延が無い**）/ 棒の赤枠 0 |
| **仕入れ案件 詳細** | **実績開始・実績終了の列が残る** / 状態チップ 0 / 輪郭 0 / 着工予定日なし ＝ **住宅専用機能が漏れていない** |
| **不動産ボード** | **KPI 4 枚**（進行中の案件 / 遅れている案件 / …）/ 絞り込み **進行中・すべて・遅延・完了**（従来どおり）|
| 建売 新規登録 | 着工予定日欄**あり**（`@if($isEdit)` 撤去が効いている）/ 旧列欄なし / **着工が先** / 「実際の完成日」の文字なし |
| 注文住宅 詳細 | 実績列 0 / 着工予定日あり / 実際の完成日なし |
| `main` の横スクロール | 6 画面とも `scrollWidth === clientWidth`（1220 = 1220）|
| コンソールエラー | **0 件** |

⚠ **取込のプレビュー予告だけは本番で未確認**（実データを作る操作なので、利用者が実ファイルで行う）。
併せて**本番のコントローラ＋ビューを実データでレンダリング**して 200 と中身も確認済み
（Bug #21 / #26 / #22 / #25 の「本番だけ 500」は retire）。

---

## ✅ 工程表ボードのガントを読めるようにする — 本番未反映

詳細仕様: @docs/superpowers/specs/2026-09-03-schedule-board-gantt-design.md
実装計画: @docs/superpowers/plans/2026-09-03-schedule-board-gantt.md
モック: @docs/mockups/housing/schedule-board-gantt.html

利用者の依頼（2026-09-03）は 3 つ —— ①**KPI カードは不要** ②**ガントの初期表示を 4 ヶ月に**
（横に広がりすぎて月の間隔が狭く非常に見にくい）③**横スクロールできるように**。
**DB 変更・ルート変更・新規 composer 依存はいずれも無し。**

「見にくい」の実体は 2 つで、①軸が **19 ヶ月**（今日の 6 ヶ月前〜12 ヶ月後）
②**そのうち 12 ヶ月が完全な空白**（データは 2026-09-27 で終わるのに軸は 2027-09 まで）＝ 幅の約 2/3 が無駄。

| 区分 | 実装内容 |
|------|---------|
| Support | `GanttScale` に `MONTH_WIDTH_PX = 150` / `monthCount()` / `trackWidthPx()` を追加（**位置(%) の計算は 1 行も変えない**）|
| Service | `ScheduleBoardService::build()` を **3 パス化**（絞り込み → 軸 → 位置）。`row()` を `meta()` と `position()` に分割。KPI・ズームを削除 ／ `ScheduleCardService` の force-today を削除 |
| Blade | `_schedule_gantt_style.blade.php` を**新設**（CSS の唯一の定義）＋ ボード / カードの 2 partial を改修 |
| ルート / DB | **どちらも変更なし** |
| テスト | 1283 → **1304 tests / 8498 assertions green**（+21）|

### 主な変更

- **KPI カードを両ボードとも削除**（D1）。「工程が未登録の案件が N 件」の行は残す（D2）
- **軸をデータの範囲に**（D3。案B）。本番のデータで **19 ヶ月 → 8 ヶ月**、空白ゼロ。
  ⚠ **絞り込みを変えると軸の幅も変わる**（案B のトレードオフ。承知のうえの選択でテストで固定）
- **1 ヶ月 = 150px の固定値**（D4）。⚠ 「4 ヶ月」を **JS で画面幅から算出しない** ——
  固定にすることで **1 日の工程の太さが画面幅に依存しなくなる**
  （実測: ボード 1〜1.5px → **4.95px**、カード 2.79px → **4.93px**。375px のスマホでも同じ）
- **案件名（カードは工程名）の列を固定表示**（D5）。幅は PC ボード 320px / カード 262px /
  **640px 未満は 140px**（D6）。⚠ **px は CSS 変数だけが持ち PHP は知らない**
- **ズームセレクタ「表示: 月 / 週 / 四半期」を削除**（D7）。既定の `month` が見にくさの原因だった。
  ⚠ 既存の `?zoom=` は無視されるだけ（リダイレクトしない）
- **今日が軸の外なら今日線を描かない**（D8）。軸は伸ばさない。
  **カードの「今日まで伸ばす」処理も外して規則を揃えた**
- **ボードは開いた直後に今日が見える位置までスクロール**（D9。実測 `scrollLeft: 386`）。
  ⚠ **カードには入れない**（D11。Ajax 保存のたびにガントを差し替えるので毎回今日へ跳ぶ）
- **詳細カードも同じ幅の規則**（D10）。⚠ 共有 CSS の `background: #fff` が
  カードの縞模様（`$loop->odd` の `#FCFCFD`）を白く抜くので、**ラベル欄にも縞模様を足した**
  （`background: inherit` は不可 —— 行の背景は既定 transparent なので**棒が透けて sticky の意味が消える**）

### ⚠ 実装中に見つけた「テストが緑でも守られていない」型（次に同型を書く人向け）

**変異を 46 通り実測**（Task 9 に全表）。実装コードの欠陥は **0 件**で、出た指摘 **36 件はすべて
テスト設計とドキュメント**だった。とくに次の 3 つは、**コードが「まさにこの理由でこう書く」と
⚠ 付きで名指ししている当の不変条件に守り手が 1 人もいなかった**:

| 見つかったもの | 症状 |
|---|---|
| **設計書 §6.1 が ⚠ 付きで要求した `drawEnd($today)`** | `planned_end` に替えても **188 本すべて緑**。§6.1 の他の 2 つの決定（◆ を入れる / フォールバック）には専用テストがあるのに、この決定だけ守り手がいなかった |
| **コードが 2 箇所で引用している Bug #29 の `min-width: 0`** | ボードのヘッダ側・行側のどちらから落としても **1292 本すべて緑**。カードの**節目行**は ◆ が出るフィクスチャが無く**構造的に未検査**だった |
| **`class="gantt-scroll"`（CSS 変数のスコープそのもの）** | 落とすと `--gantt-label-w` が未定義になり `calc()` が**丸ごと無効**になって固定幅も sticky も崩れるのに、ボード・カードとも**全テスト緑**。既存テストは「文字列がページのどこかに在るか」しか見ておらず、**特定の要素に付いているか**を見ていなかった |

⚠ **さらに自己参照的な失敗**: `@media` の順序を固定するテストが `strpos` で素の文字列を探しており、
**設計書が「そう書け」と指示した警告コメント自身**が同じ文字列を含むため、
本物のルールだけを動かす変異が **41 本すべて緑**で素通りした。
Bug #42 ② / Bug #30 と同じ構造で、**再発防止のために書いた文が、その再発を検出する仕組みを壊す**。
→ needle を**宣言の形**（`セレクタ {`）に限定して解決（設計書 §9.5 に記録）。

⚠ **`headers()` の出力 3 フィールド**（label / strong / widthPct）も**すべて無防備**だった
（どれを潰しても全緑）。⚠ **`@push('scripts')` を `styles` に押し間違える**変異は
**内容が消えず場所だけ変わる**ので文字列の存在を見るテストでは検出できない（位置の比較で解決）。

### 検証

- 全テスト **1283 → 1304 tests / 8498 assertions green**
- **変異 46 通りを実測**（プランの表 25 通り＋追加 21 通り）。検出 / 当初検出漏れ→追加で検出 / 等価変異 を区別して記録
- **コンパイル済みビュー 267 本を `php -l`** → INVALID 0 件（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）
- **ローカル実ブラウザ**（使い捨て SQLite ＋ 開発サーバ。テストが原理的に測れない領域）:
  - **4 画面 × 1800 / 1200 / 375px = 12 通り**で `main.scrollWidth === main.clientWidth`（Bug #29）
  - **1 日の工程の実測幅**: ボード **4.95px** / カード **4.93px**
  - **固定表示を実際にスクロールさせて実測**（`stuck: true`。⚠ HTML に出ていても効かないことがある）。
    `elementFromPoint()` で**ラベルが棒の上に来る**ことも確認
  - 375px で ラベル 140px / 軸 201px
  - **Ajax 差し替えが動く**（棒 13 → 14、`保存しました。`）／**保存後にスクロールが今日へ跳ばない**
  - **4 画面ともコンソール出力 0 件**
- ⚠ **本番反映後の目視は別途必要**（Bug #21 / #26 が「本番だけ壊れる」前例）

### 追補（2026-09-04）— 軸ヘッダの年表示と初期スクロール

詳細仕様: 設計書の **§12**（同じファイルに追補した。新しい設計書は作っていない）
実装計画: @docs/superpowers/plans/2026-09-04-schedule-board-year-header.md
モック: @docs/mockups/housing/schedule-board-gantt-year-header.html（年の出し方 4 案）
／ @docs/mockups/housing/schedule-board-gantt-year-initial-view.html（初期表示で年が見えるか）

Codex レビュー **Minor 4**（軸が 12 ヶ月を超えると同じ月名が複数出て年が識別できない）と、
利用者の追加依頼（**初期表示を現在月の 1 ヶ月前から**）を同時に片づけた。
**DB 変更・ルート変更・新規 composer 依存はいずれも無し。** 上の節と同じブランチ塊に入る（本番未反映）。

| # | 決定 |
|---|---|
| D13 | 年は**毎月**、月名の**前に 1 行で**置く（9.5px / `#9CA3AF` / `margin-right: 3px`）。ヘッダは 42px・1 行のまま |
| D14 | **詳細カードも同じ形に揃える**（2 段 → 1 行。年は今までどおり毎月出る） |
| D15 | 初期スクロールは「今日の**前月の 1 日**」を軸の左端に置く。**今日が軸の外でも常にスクロールする**（0% / 100% で止まる） |

| 区分 | 実装内容 |
|------|---------|
| Service | `ScheduleBoardService::headers()` の各要素に `year` ／ `axis` に `initialPct`（私有 `initialScrollPct()`）|
| Blade | 共有 CSS partial に `.gantt-year` を**唯一の定義**として追加 ／ ボード・カードの月セルを同じ 1 行の形に ／ スクロールの関数を改名・改式 |
| ルート / DB | **どちらも変更なし**。`ScheduleCardService` は**無変更**（`months()` は元から `year` を返す）|
| テスト | 1307 → **1315 tests / 8681 assertions green** |

⚠ **D9（今日を中央）は D15 で置き換えた。** スクロール関数も
`scheduleBoardScrollToToday` → **`scheduleBoardSetInitialScroll`** に改名（アプリ＋テストの 8 箇所が追従）。
**`--gantt-label-w` を読む必要が無くなった** —— 案件名の列は `position: sticky; left: 0` なので
`scrollLeft = S` のとき軸の左端はちょうど `S`。左端に置くだけなら引き算が要らない（設計書 §12.4）。

⚠ **今日が軸より後（工程が全部終わっている）のとき挙動が変わる。**
従来は左端＝一番古い月だったのが、**右端＝一番新しい月**になる。
工程表は「現状の工程を確認するもの」（2026-09-02 の利用者判断）なので直近が見えるほうが妥当で、
**利用者に提示して承認を得た**（2026-09-04）。

⚠ **「毎月は冗長だから境目（先頭セルと 1 月セル）だけにしよう」と考え直さないこと。**
一度その案（承認済みモック準拠）で進めたが、**現在の本番データ（軸 2026-02〜09）ですら
年が画面外になる**ことをモックで実測して棄却した。経緯は設計書 §12.3。

⚠ **Carbon の月末溢れを 2 回目に踏みかけた。** 「前月の 1 日」は
**`startOfMonth()` を先に通してから `subMonth()`**。逆順だと**前月ではなく当月**が返る
（実測: 2026-03-31 → 正 2026-02-01 / 誤 2026-03-01）。設計書 §6.1 の軸のずれとまったく同じ罠。
⚠ **テストの「今日」を月末以外にすると、この変異は素通りする**（2026-08-31 や 2026-09-04 では
どちらの順序でも同じ値）。回帰テストは `2026-03-31` を使い、**その日付が 2 通りの順序で
異なる値になることをテスト自身がアサートしている**（日付を差し替えた瞬間に落ちる）。

#### ⚠ レビューと変異テストが見つけた「テストは緑なのに守れていない」型（次に同型を書く人向け）

各タスクに spec 適合 ＋ コード品質の 2 段レビューを回し、**実装コードの欠陥は 0 件**。
出た指摘は**すべてテスト設計とドキュメント**で、うち **10 件は実測でフルスイート全緑だった穴**:

| 見つかったもの | 症状（いずれも実測で全緑） |
|---|---|
| テストの「今日」の差し替え | `2026-03-31` → `2026-03-15` にするだけで Carbon の順序ミスが素通りする。**軸は「今日」に依存しないので期待値は正しいまま残り、検出力だけが無音で消える** |
| needle を `セレクタ + {` にした | `.gantt-year, .x { … }` の**セレクタリスト形**で複製すると `\{` アンカーが拾わず、しかも body 側の `<style>` が**後勝ちで実行時に勝つ**（`docs/RULES.md`「Tailwind 監査の落とし穴 3」）|
| `overflow: hidden` が無防備 | 年を足して min-content が **12px → 40.6px** に増えた直後なのに、月セルから外しても全 1314 本緑（Bug #29）|
| カードのフィクスチャが**単年** | 年を `2026` の定数に固定してもフルスイート緑。ボードは年またぎなので落ちる ＝ 設計書 §12.6 が名指しした非対称 |
| `<script>` の**実行**を見ていない | `type="text/template"` にするだけでスクロールが丸ごと不活性になるのに全 1315 本緑（**2026-08-31 の POI 改修で名指しして塞いだのと同一の型**）|
| `initialPct = 0` の**描画**を見ていない | `{{ … ?: 100 }}` が全 216 本を素通り。100 側は描画まで固定してあるのに 0 側だけサービス層止まりだった |
| `headers()` の `strong` / `widthPct` | **サービスの値は固定されているのに、Blade がその値を使っているかを誰も見ていなかった**（Bug #47）。2026-09-03 の振り返りが名指しした穴の**残り** |
| `months()` の `quarterStart` / `widthPct` | 同上（カード側）|

⚠ **Blade コメントの理由が実測と食い違う指摘が 2 件出た。**
①「改行を挟むと間隔が広がる」→ **flex では広がらない**（実ブラウザで改行あり／なしとも 3.000px 一致。
落ちるのはテストの隣接チェックだけ）②「`overflow: hidden` が無いとカードのヘッダが広がる」→
**カードでは構造的に到達しない**（`months()` は `daysInMonth` をクランプしないので収縮後のセルは
常に約 138〜153px。床に当たり得るのはクランプ済みの `headers()` を持つボードのほう）。
**誤った理由の注記は次の読み手を誤らせる**（Bug #42②）ので両方とも実測に合わせて訂正した。

⚠ **変異の残骸を戻し忘れる事故が実際に起きた。** レビューエージェントが
「`git checkout --` で戻して `git status --porcelain` 空を確認した」と報告したにもかかわらず、
カードの Blade に `.gantt-year` の複製定義が残っていた。**Bug #44 が名指しする「前の変異の残骸が
測定を汚す」状態そのもの**で、気づかず次を測れば赤/緑どちらにも化ける。
以降は `git checkout --` の**直後**に `git status --porcelain` を再実行して空でなければ
非ゼロ終了する定型をシェルに組み込み、30 回の測定すべてで前後の空を確認した。

### 検証

- 全テスト **1307 → 1315 tests / 8681 assertions green**
- **変異 30 通りを実測**（表 27 通り ＋ 表に無い 2 通り ＋ M25 をボード/カードに分けた 1 通り）。
  **検出 26 / 当初検出漏れ→追加で検出 4 / 等価変異 0**。全表はプランの §7.3
  ⚠ **表を終えた時点で漏れが 2 件しか無かったので「測り方が甘いのでは」と疑い、
  同じ行の隣接不変条件を追加で当てて 2 件見つけた**（`widthPct` の 2 通り）
- **コンパイル済みビュー 267 本を `php -l`** → INVALID 0 件
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`。⚠ `preview_start` は使わない）:
  - **4 画面 × 1800 / 1200 / 375px = 12 通り**で `main.scrollWidth === main.clientWidth`（Bug #29）
  - **ボード・カードとも** 月セルが `flex-direction: row` / `align-items: center` /
    `justify-content: center` / `overflow-x: hidden`、年が **9.5px / `rgb(156,163,175)` / margin-right 3px**、
    **ヘッダ 42px**（D13 / D14 を `getComputedStyle` で実測）
  - 不動産ボードで `.gantt-year` が **14 個（2025 × 7 / 2026 × 7）**。
    画面で **`2025 12月` → `2026 1月`** の切り替わりが読める ＝ **Minor 4 の解消を目視**
  - **カードにスクロールのスクリプトは無い**（D11）／ 375px でラベル欄 140px（D6）
  - 初期スクロールは**式そのものが正しい** —— 375px の住宅ボードで
    `scrollLeft = 897.5` ＝ `trackPx × pct / 100` と厳密に一致、900px の不動産ボードで
    `pct = 100` が右端に止まる
  - **対象 4 画面ともコンソールエラー 0 件**
- ⚠ **未確定が 1 件ある（本番反映後の目視で確認すること）** —— 幅 1024px 以上で
  目標が右端に届く場合だけ**着地が 220px 手前**になった（ズレ幅はサイドバーと一致）。
  呼び出し時点で `document.hidden: true` / `readyState: loading` のまま aside が `display: none`
  と出ており（`innerWidth: 1800` / `mqLg: true` / CSS ロード済み / inline style 無し）、
  **隠れたペインで Chrome がスタイルとレイアウトを遅延するため**の可能性が高く、
  この環境からは実ブラウザの挙動と区別できない。⚠ **この改修が持ち込んだものではない**
  （旧 D9 の式は同じ瞬間に `clientWidth` を直接読んでおり、同じ露出を常時持っていた）。
  詳細と判断材料はプランの §8.1

### ⚠ 本番反映の手順

**DB 変更なし・ルート変更なし・新規 PHP クラスなし**（新規ファイルは Blade partial 1 本とテストのみ）
なので `composer dump-autoload` は不要。

```
git checkout 13.x && git merge --ff-only schedule-board-gantt
./deploy.sh
```

⚠ **`resources/css/app.css` は変更していない**ので、この改修に `npm run build` は要らない
（CSS はビューの `@push('styles')` に入る）。`deploy.sh` は従来どおりビルドを走らせる。

---

## バックログ完了状況

優先度 1〜5 のすべてのバックログ項目が本番稼働中。周辺ビル調査は第1段（2026-08-17）・
第2段の一部（一覧の地図タブと位置登録、2026-08-20）・同（一覧の並び替えと見出しの視認性、2026-08-30）・
**同（地図から店舗・駅のピンを消す、2026-08-31）**がいずれも本番反映済み。
テナント管理の一覧の並び替え（物件・部屋）も本番稼働中。

**工程表の取込（建売）も 2026-09-01 に本番反映済み**（上記の節を参照）。
DB の ALTER → `composer install --no-dev` → `./deploy.sh` の順で流し、本番で
コンパイル済みビュー 266 本の lint（INVALID 0）と解析器の疎通まで確認した。
⚠ **実運用の書き出しでの取込（確定まで）と、ガント形式の実ファイルでの拒否確認は未了。**

**工程表を「現状の工程」に寄せる（住宅事業）も 2026-09-02 に本番反映済み**（上記の節を参照）。
本番を read-only で実測（両テーブルとも旧列は全行 NULL・`schedule_steps` の残存実績 0 件）→
`ALTER` 2 本 → `./deploy.sh` の順で流し、本番でコンパイル済みビュー 266 本の lint（INVALID 0）と、
**コントローラ＋ビューを実データでレンダリングして 200 と中身**まで確認した。
✅ **本番の実ブラウザ確認も同日に実施済み**（上記の表。ログイン済みの実 Chrome で 6 画面）。
1 日の工程の棒が塗りのまま・遅延バッジ 0・ラベル欄 65 個すべて 262px・不動産は実績 2 列が残る、まで実測。
⚠ 本番の建売は着工予定日・完成予定日とも全行 NULL なので、**64 工程を持つ物件も ◆ は 0 個**のまま。
⚠ **取込のプレビュー予告だけ本番で未確認**（実データを作る操作なので利用者が実ファイルで行う）。

⚠ **未反映のものが 1 件ある** —— 「工程表ボードのガントを読めるようにする」（2026-09-03。上記の節）。
実装・テスト・変異 46 通り・ローカル実ブラウザ確認まで完了しているが、**`./deploy.sh` は未実施**。
DB 変更・ルート変更・新規 composer 依存はいずれも無いので、`13.x` へ FF マージして `deploy.sh` を流すだけでよい。

その他の新規要件は別途追記する。

✅ **2026-09-01 に本番目視を実施した**（上記「本番確認の結果」）。**7 点中 6 点が確認済み**で、
残る 1 点（登録モードで元 POI 位置をクリック）は**測る対象が存在しないため意図的に見送った**。

⚠ 8/31 の反映直後は「全ルートが 302」までしか見ておらず、**302 は認証リダイレクトで
ビューを描画する前に起きる**ので、あの時点では本番のレンダリングを一度も確認できていなかった。
**302 を「アプリは正常」の証明に使わないこと。**
