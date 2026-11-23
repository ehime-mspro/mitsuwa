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

## ✅ 工程表ボードのガントを読めるようにする — 本番反映済み

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
- ✅ **（2026-09-11 に修正済み。下の「初期スクロールのズレの修正」）** かつての既知の未修正 —— 幅 1024px 以上で
  目標が右端に届く場合だけ**着地が 220px 手前**になる。**原因は `x-cloak` と Alpine の起動順**:
  `@stack('scripts')` のインライン script は**同期実行で Alpine より前**に走り、その瞬間
  サイドバー（`sidebar.blade.php:30` の `x-cloak`）は `app.css:19` の `[x-cloak]{display:none}` で
  **幅 0px** ＝ スクローラーの `clientWidth` が **220px 過大**。ブラウザのクランプ上限が
  220px 小さくなり、Alpine が `x-cloak` を外しても **`scrollLeft` は上方向へ再クランプされない**。
  ⚠ **実ブラウザでも起きる**（当初「隠れたペインのアーティファクト」と診断したのは誤りで、
  最終レビューが `x-cloak` を見つけて訂正した）。
  ⚠ **この改修が持ち込んだものではない** —— 旧 D9 の式は同じ瞬間に `clientWidth` を直接読んでおり、
  **露出は常時だった**（110px ぶん常に左へずれる）。新実装は右端のケースだけ露出する。
  **本番確認**: `initialPct = 100` になる案件（工程が全部過去）を 1200px 以上で開き
  `scrollLeft === scrollWidth - clientWidth` を測る。
  ⚠ ここに「ズレたら呼び出しを `requestAnimationFrame(...)` で包むだけでよい」と書いていたのは**測っていない推測で、誤りだった**
  （非表示のタブでは止まり、Alpine より後に走る保証も無い）。実際の修正は **DOMContentLoaded まで待つ**（下の節）。
  詳細はプランの §8.1

### ⚠ 本番反映の手順

**DB 変更なし・ルート変更なし・新規 PHP クラスなし**（新規ファイルは Blade partial 1 本とテストのみ）
なので `composer dump-autoload` は不要。

```
git checkout 13.x && git merge --ff-only schedule-board-gantt
./deploy.sh
```

⚠ **`resources/css/app.css` は変更していない**ので、この改修に `npm run build` は要らない
（CSS はビューの `@push('styles')` に入る）。`deploy.sh` は従来どおりビルドを走らせる。

### 本番反映（2026-09-11 実施）

「テナント契約一覧 契約日の既定順と見出しの並び替え」（下の節）と同じ `13.x` に乗っていたので、一緒に反映した
（`./deploy.sh` を 2 回。最終 `13.x` = `e9a899d7`）。本番のコンパイル済みビュー **267 本 / INVALID 0 件**。
ログイン済みの実 Chrome（1440px 幅）で:

| 画面 | 実測 |
|---|---|
| 住宅の工程表ボード | KPI カード無し ／ 年ラベル 8 個（軸 2026-02〜09）／ `main` の横スクロール 0 ／ コンソールエラー 0 |
| 不動産の工程表ボード | KPI カード無し ／ `main` の横スクロール 0 ／ コンソールエラー 0。工程が登録された案件が **0 件**（32 件すべて未登録）でガントは空 |

⚠ **上の「既知の未修正」（初期スクロールが 220px 手前に着地する）が本番で出ている。** 住宅の工程表ボードで
`initialPct` 74.8%（軸 1200px の 897px 地点。右端の 366 で止まるはず）に対し、実際の `scrollLeft` は **146 ＝ 366 − 220**
（サイドバーの幅ちょうど）。初期表示で当月（9 月）が右にはみ出して見えない。→ **同日に修正した**（下の節）。

### 初期スクロールのズレの修正（2026-09-11）— 本番反映済み

**呼び出しを `DOMContentLoaded` まで待たせた**（`36b86b80`。`resources/views/_partials/_schedule_board.blade.php`）。
関数 `scheduleBoardSetInitialScroll` とスクロール量の式（`el.scrollLeft = trackPx * pct / 100;`）は変えていない。
**DB 変更・ルート変更・新規 PHP クラスは無し。**

原因は上の注記のとおりで、一時的な計測で確定させた（1440px・住宅ボード。計測コードは元に戻した）:

| 時点 | readyState | Alpine | PC サイドバー | スクローラー幅 | 右端 | scrollLeft |
|---|---|---|---|---|---|---|
| スクリプト実行時 | loading | 未起動 | `x-cloak` で非表示 | 1374 | 146 | **146 に丸められる** |
| DOMContentLoaded | interactive | 起動済み | 表示（220px） | 1154 | 366 | 146 のまま（修正前）|

- DOMContentLoaded は defer / module のスクリプト（Alpine）と、そのあとのマイクロタスク（`x-cloak` の除去）が
  済んでから発火する。`resources/js/app.js` のスクロールヒントも同じ理由で DOMContentLoaded で幅を測っている
- ⚠ **`requestAnimationFrame` は採らなかった**（上の注記と前回計画 §8.1 が挙げていた直し方）。
  非表示のタブでは止まり（実測: 読み込みから **31 秒後**に初めて発火）、defer の Alpine より後に走る保証も仕様上無い
- ⚠ 理由の説明は JS コメントでなく Blade コメントに書いた（既存テストが関数の定義から呼び出しまでの間を検査している）
- 回帰テスト `ScheduleBoardTest::test_the_initial_scroll_waits_for_dom_content_loaded` —— 呼び出しが
  DOMContentLoaded のリスナーの本体の中に**だけ**あること（JS コメントを落とし、波括弧の対応で本体を切り出して測る）。
  ⚠ **構造しか見られない**（実際に右端で止まるかはブラウザでしか測れない）
- 全テスト 1346 → **1347 tests / 9021 assertions green**
- **変異 5 通りすべて赤**（トップレベルへ戻す＝元の不具合 ／ トップレベルとリスナーの両方 ／ `requestAnimationFrame` ／
  `load` ／ イベント名の打ち間違い）。落ちた理由の文言まで照合済み。等価の `window.addEventListener('DOMContentLoaded', …)` は緑（許容）
- コンパイル済みビュー **267 本 / INVALID 0 件**
- **ローカル実ブラウザで 2 ボード × 4 幅のズレ 0**（使い捨て SQLite ＋ `artisan serve`。本番と同じ形の軸で再現してから直した）:

| 幅 | 住宅ボード（目標が右端に届く） | 不動産ボード（`initialPct` 100） |
|---|---|---|
| 1440px | **366 / 366**（修正前 146）| **1266 / 1266**（修正前 1046）|
| 1200px | 606 / 606 | 1506 / 1506 |
| 1800px | 6 / 6 | 906 / 906 |
| 375px | 897.5（右端に届かない＝式どおり）| 1899 / 1899 |

  `main` の横スクロール 0・コンソール出力 0 件
- ✅ **2026-09-11 に本番反映**（`13.x` = `60ea80cb`。FF マージ → `./deploy.sh`。DB 変更なし）。
  本番のコンパイル済みビュー **267 本 / INVALID 0 件**（ssh・読み取りのみ）。ログイン済みの実 Chrome（1440px 幅・前面のタブ）で
  住宅の工程表ボードが **`scrollLeft` 366 ＝ 右端 366**（修正前 146。目標 897.5 が右端で止まる）、
  「今日 9/11」の印が初期表示の見える範囲に入り、見えている月は 2026 年 4〜9 月（修正前は 9 月が右にはみ出していた）。
  `main` の横スクロール 0・コンソールエラー 0 件

---

## ✅ テナント契約一覧 契約日の既定順と見出しの並び替え — 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-11-tenant-contract-list-sorting-design.md
実装計画: @docs/superpowers/plans/2026-09-11-tenant-contract-list-sorting.md

利用者の依頼（2026-09-11）は「テナント管理の『契約一覧』画面ですが契約日順に初期表示を変更して下さい。
そしてテーブルにも契約日を追加して下さい。」。対話の中で「列見出しでも並び替えたい」に広がった。
**DB 変更・ルート変更・新規 PHP クラスはいずれも無し。**

| 区分 | 実装内容 |
|------|---------|
| Support | `ListSort::stateOf()` / `next()` / `url()` に省略可能な `$first`（1 回目の向き）/ `$isDefault`（既定順の列か）。**省略時は従来と同一** |
| Model | `Contract::MONTHLY_TOTAL_SQL`（`getMonthlyTotalAttribute()` と同じ計算） |
| Controller | `Tenant\ContractController` に `SORT_COLUMNS`（3 列）＋ `applySort()` |
| Blade | `x-sortable-th` が `SORT_COLUMNS` の `first` / `default` を読む ／ 契約一覧に契約日の列・見出し 3 本・hidden・並び順バー |
| ルート / DB | **どちらも変更なし** |
| テスト | 1315 → **1346 tests / 9015 assertions green**（+31） |

### 要点

- **既定順は 契約日の新しい順 → 物件名 → 階数 → 号室 → 契約 ID の新しい順。** 最後の契約 ID で並びが一意になり、
  同じ区画の旧契約と新契約が「ステータス: すべて」でページをまたいで重複・欠落しなくなった（従来の並びは一意でなかった）
- 見出しで並び替える列は **契約日・物件 / 区画・賃料収入**（店舗名・状態・操作は並び替えない）
  - 契約日: 既定（▼ が点灯）→ 古い順 → 既定。**他の列で並び替え中に押すと既定へ戻る**
  - 物件 / 区画: **1 回目は昇順**（2026-09-11 までの既定順）→ 降順 → 既定。降順は 3 キーとも逆
  - 賃料収入: 多い順 → 少ない順 → 既定
- ⚠ **`ListSort` の周期を列ごとに指定できるようにした**（`SORT_COLUMNS` の `'first'` / `'default'`）。
  物件一覧・部屋一覧・周辺ビル調査は指定を持たないので**挙動は不変**（既存テストは 1 本も書き換えていない）
- 表は 6 列。**列幅は実ブラウザで測って決めた**（計画の Task 8）。最小幅 900px は据え置き、割合を各列の
  「中身に要る幅」に比例させた（**13.7 / 22.0 / 21.4 / 15.8 / 11.2 / 15.9 %**）。方針は利用者の判断
  （最小幅を上げて表を横スクロールさせる案・余白を詰める案は採らなかった）。
  ⚠ 必要幅は**本番の実データ 127 件の最大**と 7 桁の賃料＋⚠ の大きいほう（132 / 212 / 207 / 152 / 108 / 154px、計 965px）。
  1200px 幅の表（914px）には収まらないので、その幅では最悪ケースの行の文字が余白に最大 10.7px、
  スマホ・タブレット（表 900px・余白 16px）では最大 5.7px 食い込む。**どの幅でも隣の列へははみ出さない**。
  約 1255px 以上の画面なら食い込みなし
- ⚠ **最初は検証用データの幅（計 926px）で 14.3 / 21.6 / 19.4 / 16.4 / 11.7 / 16.6 % にして反映したが、
  本番に検証用データより長い名前が実在した**（物件 / 区画 212px・店舗名 207px ＝ 12 字）。そのままだと 1200px 幅と
  スマホ・タブレットで店舗名が隣の列へ最大 9px はみ出していたので、本番で測り直して当て直した（`a804fd7b`）
- ⚠ 地下の区画（表示名 `B1A`）が一覧で `-1B1A` と出る**既存の表示の癖**を見つけた（今回は直していない）
  → **2026-09-12 に直した**（下の「地下区画の表記と横スクロールのヒント」の節。Bug #57）

### ⚠ 実装中に見つけた「測っているつもりで測れていない」型

- **`td` の `scrollWidth` は余白の内側への食い込みを検出しない。** 設計書 §4.7 と計画 Step 7 の判定式
  `scrollWidth <= clientWidth` は、文字がセルの外枠（パディングの外）まで出たときしか反応しない。
  実際に 1200px で 3 列が食い込んでいた（最大 17.2px）のに「0 件」と報告した。
  文字の範囲を `Range` で取り、セルの内容幅と外枠の両方と比べる検出器に差し替えた（全文は計画の「Task 8 の実測結果」）
- ⚠ `--font-sans` 先頭の **Noto Sans JP は Web フォントとして読み込まれていない**。Mac の実測は Hiragino Sans の字幅で、
  Windows では Meiryo に落ちる（ここでは測れない）
- 変異 #3〜#6（既定順の 物件名 / 階 / 号室 / 契約 ID）は**それぞれ 1 本のテスト**（同じ契約日の中の並び）だけが検出する。
  そのテストのデータを簡単にすると、4 キーの検出力が無音で消える
- **検証用データの「収まるべき長さ」（物件名 7 字・店舗名 10 字）は本番より短かった。** 列幅は本番の実データで測ること。
  「すべて」の全ページを `fetch`（GET・`X-Requested-With` 付き）して 1 つの表に並べれば、
  ログイン済みの実 Chrome から読み取りだけで測れる（測り終えたら読み込み直して元に戻す）

### 検証

- 全テスト **1346 tests / 9015 assertions green**（ベースライン 1315 から +31）
- **変異 28 通りすべてで赤**（Bug #44 の作法。先にカナリアで測定装置が worktree のコードを読んでいることを確認。
  落ちた理由まで照合し、想定と文言が違ったのは #28 の 1 通りだけで、意図した機構そのものだった）
- **コンパイル済みビュー 267 本を `php -l`** → INVALID 0 件
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`）:
  - **375 / 1200 / 1800px × 3 画面 = 9 通り**で `main.scrollWidth === main.clientWidth`・隣の列への越境 0
  - 見出しを**実際にクリック**して 3 列とも周期どおり（セルの端＝文字の無い余白を押し、セル全体が押せることも確認）
  - 初期表示で契約日が緑の ▼・他は灰色の ⇅ ／ Tab のフォーカスリングが上下で切れない ／
    ホバー中も並び替え中の列は緑の下線のまま ／ コンソール出力 0 件

### 本番反映（2026-09-11 実施）

`13.x` へ FF マージ → `./deploy.sh`（ガント改修も一緒に出た）→ 本番の実データで列幅を測り直して当て直し →
もう一度 FF マージ → `./deploy.sh`。最終 `13.x` = `e9a899d7`。**DB 変更・ルート変更・新規 PHP クラスは無し**。

| 見たこと | 結果 |
|---|---|
| **コンパイル済みビューの `php -l`**（ssh・読み取りのみ） | **267 本 / INVALID 0 件**（2 回のデプロイの後それぞれ）|
| 表の先頭列 | 契約日（`Y/m/d`）で**新しい順**（2026/09/10 → 09/07 → 08/06 …）|
| バーと見出し | 「並び替え: 既定（契約日の新しい順）」／ 契約日だけ緑の ▼（`aria-sort="descending"`）・他は灰色の ⇅ |
| 「物件 / 区画」を実クリック | 物件名 → 階 → 号室の昇順。バー「物件 / 区画 昇順」、絞り込みフォームに `sort` / `dir` の hidden |
| そのままステータスを「すべて」 | 並び順が保たれる。**全 13 ページが 200**・ページ送りのリンクはすべて並び替えと「すべて」を保つ・127 行に重複 / 欠落なし・契約日が空の行 0 |
| 列幅（本番の実データ 127 件） | 1440px 幅で食い込み 0 ／ 1200px 相当で最大 10.7px・はみ出し 0 ／ 表 900px・余白 16px 相当で最大 5.7px・はみ出し 0 |
| `main` の横スクロール | 0（1440px 幅。1200px はローカルで確認済み）|
| コンソール | 出力 0 件 |

---

## ✅ 地下区画の表記と横スクロールのヒント — 本番反映済み

実装計画（調査結果・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-11-basement-label-and-scroll-hint.md

利用者の依頼（2026-09-11）は 2 つ —— ①**地下の区画（表示名 `B1A`）が画面で「-1B1A」と表示される**
②**賃貸マンション契約一覧・ZEAL 体験予約一覧・ZEAL 会員一覧の「スクロールできます」表示（右端のフェード）が、
画面幅によっては最初に出ない**。対話の中で①に、修繕の区画選択（`11A` / `33A`）とフロアマップの階ラベル（`-1F`）を加えた。
**2026-09-13 のセルフレビューで②の直し方に退行が見つかり**、利用者の判断で直し方を改めた（根本の `x-cloak` を外す・
4 画面目の入居者一覧もあわせて直す）。サイドバーの開閉でも測り直すようにした。
**DB 変更・ルート変更・新規 PHP クラス・新規依存はいずれも無し。**

| 区分 | 実装内容 |
|------|---------|
| 依頼 1 | 表示側 11 か所（契約 6 画面・物件詳細の契約／解約タブ・`Inquiry::unit_labels`・問合せ／投資の区画選択）の「数字で始まらなければ階を前に付ける」を外し、**表示名をそのまま出す** ／ 修繕の登録・編集の区画選択（**条件なしで**階を付けていた 2 か所）も同じ ／ フロアマップの地下の階ラベルを **`B1F`** に |
| 依頼 2 | **PC 展開サイドバーの `x-cloak` を外す**（根本。起動前だけ表示領域が 220px 広かった）／ 4 一覧（部屋契約・入居者・体験予約・会員）は**パース中に判定し DOMContentLoaded でも測り直す** ／ 入居者一覧に判定の script と抜けていた閉じタグを足す ／ サイドバーの開閉のあとに `resize` を送る（`$nextTick` → `requestAnimationFrame`）|
| テスト | `tests/Feature/Tenant/UnitLabelDisplayTest.php`（15 本）／ `tests/Feature/LayoutMeasuringScriptTest.php`（4 本）／ `tests/Feature/LayoutSidebarCloakTest.php`（3 本）。1347 → **1369 tests / 9337 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 要点

- **本番の区画 158 件（うち削除済み 7）を読み取りで数えてから直し方を決めた**（利用者の承認のうえ・書き込みなし）。
  表示名は**全件 `generateDisplayName(階, 号室)` と一致**し、前置きが働くのは**地下の 3 件だけ**
  （物件 8 の `B1A`、物件 13 の `B1A` `B1B`）＝ 正しいデータには一度も役立っていなかった。そこで前置きを「直す」でなく**消した**（Bug #57）
- 前置きは**初期コミットから**あり、**2026-04-13 に地下（階 -1〜-3）を足したとき表示側が直されなかった**
- **修繕の区画選択は本番の削除されていない 151 区画すべてが崩れていた**（地上 148 区画は `11A` 型・地下 3 区画は `-1B1A`）
- ⚠ フロアマップの `B` の判定は**整数に限る** —— 階なしの区画は `groupBy('floor')` で `''` のキーになり、
  PHP 8 では `'' < 0` が true・`abs('')` は TypeError ＝ 物件詳細が 500 になる。階なしの「F」は従来どおり
- 依頼 2 は**工程表ボード（36b86b80）と同じ x-cloak の件**（Bug #56）。3 画面とも実ブラウザで再現してから直した
  （1100px の部屋契約一覧 `880 > 814`・1280px の体験予約一覧 `1109 > 994` なのにヒントなし）。
  一時計測で「唯一の初回呼び出しが readyState loading・Alpine 未起動・x-cloak 3 本の瞬間に走り、領域を 1034px と測る」ことも確かめた
- ⚠ **最初は初回の判定を DOMContentLoaded だけへ移した（14e400a1）が、レビューで退行と分かった。** `app.js` の取得を 300ms 遅らせる
  中継プロキシで測ると、表が収まる 1440px でも DCL（370ms）までの 19 描画すべてにヒントとフェードが出て、そのあと消えた（FCP 88ms）。
  当時書いた「最初の描画は DOMContentLoaded より後（FCP 76〜96ms / DCL 48〜57ms）なので一瞬出て消えることは起きない」は
  **遅延なしの手元条件でしか成り立たない誤り**だった → パース中にも判定し、DCL でも測り直す形にした（646c1245）
- **根本はサイドバーの `x-cloak`**（`sidebarExpanded: true` 固定で起動後は必ず表示されるのに、起動前だけ隠していた）。外したので
  パース中の計測も起動後と同じ幅になり、読み込み時にサイドバーが後から出るガタつきも消えた（f11229c2）。
  折りたたみ版・モバイルのドロワー・グループの中身は起動前に隠れている必要があるので残した
- **4 画面目の入居者一覧**は判定の script 自体が無く、表が収まる幅でもヒントとフェードが常に出ていた。`</table>` の後の閉じタグも無く、
  ページ送りと注記が表の枠の中に入っていた（25bfe0c6）
- **サイドバーの開閉でも測り直す**（開閉は window の resize を起こさない）。⚠ 最初の `$nextTick` だけの形（c400511b）は、
  **画面が見えているブラウザで測ると退行だった** —— Alpine 3.15 の x-show は起動後の切り替えを requestAnimationFrame まで遅らせ、
  `$nextTick`（setTimeout）が先に走るので開閉前の幅で測る。閉じる → 開くだけでスクロールできるのにヒントが消えた。
  構造テストは緑、**非表示のブラウザペインでも正しく動いて見えた**（非表示のタブでは x-show が setTimeout 経由になり順序が入れ替わる）。
  `$nextTick` の中で `requestAnimationFrame` を挟んで直した（e0b2140f）
- **幅・スクロール位置に触るインライン script（12 本）を全件分類するテスト**を置き、方針を持たせた（`DCL_ONLY` ＝ 工程表ボード・区画一覧 ／
  `PARSE_AND_DCL` ＝ 4 一覧 ／ `null` ＝ 操作の後だけ）。右端のフェードを持つビューは `PARSE_AND_DCL` でないと落ちる
  （入居者一覧のように制御の script が無い画面を拾う）。新しいビューで幅を測る script が増えると落ちるので、方針を決めてから分類して足す
  （Top trap #13 / Bug #45 ①）
- 区画の表示名の走査も**右辺の変数名に依存しない形**に直した（2026-09-12 の版は `$dn` / `$displayName` 決め打ちで、
  `?->`・Blade の並べ書き・文字列展開を素通りさせた）

### 範囲外（気づいたが直していない）

- 既定が「表示」なので、この script より前（表の途中など）で描画が挟まると、その 1 回だけヒントとフェードが写ることがある
  （`app.js` を 300ms 遅らせて 20 回中 1 回。修正前の 3 画面も同じ構造）
- 工程表ボードは初期スクロールを DOMContentLoaded まで待つ（今回は変えていない）。サイドバーの `x-cloak` を外したのでパース中の幅も
  正しくなったが、JS の取得が遅いと左端で描かれてから当月へ動く可能性がある（未計測）
- `UnitController::generateDisplayName()` は `Unit::generateDisplayName()` の複製（中身は同一。今は表示に影響なし）
- テナント CSV 取込の重複エラー文の「（-1階）」（利用者が CSV に書いた値そのままの表記）
- 階なしの区画を持つビル型物件でフロアマップの階ラベルが「F」だけになる（本番 0 件）
- 別タスクとして案内したもの（2026-09-13）: ①論理削除した区画を参照する投資・修繕・問合せ（500・区画が黙って消える）→ **2026-09-14 に対応**（下の「論理削除した区画を参照する画面」の節。修繕の選択肢の組み立ての重複もそこでまとめた）
  ②表示名の生成の複製と地下の階表記の手書きコピー・修繕の選択肢の組み立ての重複 ③4 一覧のピルの class 名 `.scroll-hint` が
  共通 CSS の `::after` とぶつかる件・ページごとの判定を `resources/js/app.js` の共通ヒントへ寄せる件

### 検証

- 変異テスト（Bug #44 の作法。落ちたテストの集合と理由の文言まで照合）:
  - 1 回目（2026-09-12）**34 通り**すべて期待どおり（カナリア 2 ＋ 依頼 1 の 19 ＋ 依頼 2 の 13。等価変異 2 通りは緑）。
    13 か所を旧い書き方に戻す変異は「その画面のテスト＋走査」だけが落ち（`Inquiry::unit_labels` は問合せと物件詳細の 2 画面に出るので 3 本）、
    走査に見えない壊し方 2 通りは画面のテストだけが落ちた
  - 2 回目（2026-09-13。最終コードで流し直し）**25 通り**すべて期待どおり（カナリア 2 ＋ 方針 13 ＋ サイドバー 4 ＋ 走査 6。等価変異 3 通りは緑）
  - 3 回目（開閉の修正）**10 通り**すべて期待どおり（カナリア 1 ＋ 入れ子 7 ＋ 既存 2。等価変異 1 通りは緑）。
    加えてブラウザで「`requestAnimationFrame` だけ」「`$nextTick` だけ」の 2 形を当て、どちらもヒントが食い違うことを実測した
- コンパイル済みビュー **267 本**（本番と同じ既定の環境。`APP_ENV=local` では開発用のビューも含めて 307 本）を `php -l` → INVALID 0 件
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`。zeal 接続も環境変数 `ZEAL_DB_URL` だけで SQLite へ向けた）:
  - 4 画面 × **1024 / 1100 / 1180 / 1280 / 1366 / 1440 / 1800 / 375px** で「スクロールできる ＝ ヒントが見える ＝ フェードが見える」**32/32**・`main` の横スクロール 0。375px ではサイドバー 3 本とも非表示
  - `app.js` を 300ms 遅らせる中継プロキシ（Playwright・画面が見えている状態）で 4 画面 × 1440 / 1100px を計 20 回: サイドバーは**最初の描画から 220px**・グループの中身は 0 個・DCL より前の描画でヒントが最終状態と食い違ったのは 1 回だけ（範囲外の 1 件目）
  - 開閉: 4 画面 × 1024 / 1100 / 1280px で「右端までスクロール → 閉じる → 開く」（1100px は 2 往復）の各段階で一致。区画一覧（表 1500px）もヒントバーが出たまま崩れない
  - 入居者一覧は横スクロールの領域の中身が表だけになり、注記が表の枠の外に出た
  - 依頼 1: 契約 6 画面・物件詳細（契約タブ `3A 1A B1A` ／ 問合せタブ `B1A, 1A` ／ フロアマップ `3F 1F B1F`）・問合せ一覧／詳細・投資登録（`B1A（12.50坪）`）・修繕登録（`B1A 1A 3A A`）で `-1B1A` 0 件
  - コンソールのエラー・警告 0 件（Playwright のセッション全体）
  - ⚠ 非表示のタブではスクロールイベントが配られない（`scrollLeft` は右端まで動くのにハンドラが走らない）。スクロールや Alpine のタイミングは画面が見えているブラウザで測る

### 本番反映（2026-09-13 実施）

作業中に `13.x` へ段階0（定期実行・メール送信の土台・暗号化バックアップ）が取り込まれていたので、このブランチへ `13.x` をマージし（`ea1c9850`。競合なし。リベースしないのはドキュメントに書いたコミット番号を変えないため）、合わせたコードで **1634 tests / 10083 assertions green**・コンパイル済みビュー 269 本 INVALID 0 件を確かめてから FF マージ → `./deploy.sh`（exit 0）。段階0 は本番反映済みと利用者に確認した。**DB 変更・新規 PHP クラス・新規依存（このブランチ分）は無し**。

| 見たこと | 結果 |
|---|---|
| 転送されたファイル | コントローラ 4・モデル 2・ビュー 13 だけ（`vendor`・設定・ルートは 0 件。段階0 のファイルは本番と同一で送られていない）|
| **コンパイル済みビューの `php -l`**（ssh・読み取りのみ）| **269 本 / INVALID 0 件** |
| 本番に置かれたファイル | レイアウトに開閉の修正（`requestAnimationFrame`）・展開サイドバーに `x-cloak` なし・入居者一覧に DOMContentLoaded の登録 |
| 4 一覧 × 1024 / 1100 / 1280 / 1440 / 1600 / 1800 / 375px（本番の実データ・同じ幅の iframe）| **28/28** で「スクロールできる ＝ ヒント ＝ フェード」・`main` の横スクロール 0・サイドバー 220px（375px では 3 本とも幅 0）。実データでは会員一覧が 951px・体験予約一覧が 1398px あり、旧症状が出ていた幅（会員一覧 1024・1100px ／ 体験予約一覧 1600px）でもヒントが出る |
| 修繕の登録画面の区画選択肢 | **151 件すべてが区画一覧の表示名と一致**・`-` で始まるもの 0 件・地下は `B1A`（物件 8）／ `B1A` `B1B`（物件 13）|
| 物件 8・13 のフロアマップ | `5F … 1F` と **`B1F`**（`-1F` 0 件）・区画カードは `B1A`（物件 13 は `B1A` `B1B`）・`-1B1A` 0 件 |
| 契約一覧（すべて・全 13 ページ・127 行）／ 問合せ一覧 | `-1B1` 0 件（地下区画の契約は本番に 0 件なので、一覧での地下表記は実データでは現れない）|
| コンソール | エラー 0 件（4 一覧＋物件 13 の詳細）|

⚠ **サイドバーの開閉の動きは本番では測っていない。** 自動操作したタブが裏（`visibility: hidden`）で、Alpine の x-show の切り替えが setTimeout 経由になり順序が変わるため（Bug #56）。本番に置かれたコードが修正後であることだけ確かめた（動きはローカルの Playwright で確認済み）。⚠ `origin/13.x` への push はしていない（利用者の指示があったときに行う）。→ 2026-09-16 に push 済み（`e17718e0` に含まれる）。

---

## ✅ 論理削除した区画を参照する画面 — 本番反映済み

実装計画（調査結果・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-13-deleted-unit-references.md

利用者の依頼（2026-09-13）は「論理削除した区画を参照する画面の不具合の修正」（前の節の範囲外①）。
利用者の判断: 削除済みの区画は「B1A（削除済み）」と表示し区画ページ（404）へのリンクを外す ／ **契約の画面にも同じ印を付ける** ／ 区画の削除の歯止めは足さない。
**DB 変更・ルート変更・新規 PHP クラス・新規依存はいずれも無し。**

| 区分 | 実装内容 |
|------|---------|
| Model | `Unit::display_label`（表示名 ＋ 削除済みなら「（削除済み）」）／ `Unit::includingTrashed($keepIds)` ／ `Investment::unit`・`Repair::unit`・`Inquiry::units`・`UnitRentRevision::unit` に `withTrashed()`（`Contract::unit` は元から）／ `Repair::unit_label`・`Inquiry::unit_labels` は `display_label` |
| Controller | 投資・修繕・問合せの編集画面の選択肢に今の区画を削除済みでも残す ／ 登録は削除済みの区画を入力エラー、更新は今の区画だけ削除済みでも通す ／ 所属チェックも削除済みを読む ／ 修繕の選択肢の組み立ての重複をまとめた |
| Blade | 投資の一覧・詳細、修繕の詳細、物件詳細（契約・解約・投資タブ）、契約 6 画面、顧客詳細の区画名を `display_label` に。投資・修繕の詳細は削除済みの区画にリンクを張らない |
| テスト | `tests/Feature/Tenant/DeletedUnitReferenceTest.php`（38 本）。1634 → **1672 tests / 10292 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 要点

- 症状（コードで確認。本番では発火前）: 投資の一覧・詳細・物件詳細が 500 ／ 修繕が「共用部」と誤表示し保存で共用部に書き換わる ／
  問合せの希望区画が黙って消え保存で中間テーブルからも消える ／ 契約は削除済みと分からない
- 本番の読み取り確認（2026-09-13・利用者の承認のうえ）: 削除済み区画 7 件（物件 12 の 2A 3A 4A 4B 5A 5B 5C）。
  参照は解約済み契約 1 件（C-1991-001・5A）だけで、投資・修繕・問合せは 0 件
- 子から区画を読むリレーション（belongsTo / belongsToMany）は削除済みも読む。物件から区画を並べる `Property::units` は読まない
  （フロアマップ・区画数・入居率に混ざる）。構造テストが `app/Models` で `Unit::class` を指すリレーションを**全件分類**して守る
- ⚠ 列を絞る `get([...])` に `deleted_at` を入れないと印が黙って消える（変異で実測）
- ⚠ 問合せは所属チェックが削除済みを読まないと、物件を変えた問合せに旧物件の区画が付く（「今の区画なら通す」入力チェックと組み合わさるため）
- ⚠ 契約の編集・賃料改定・解約の画面は、削除済み区画の契約（必ず解約済み）では開けないので表記の置き換えだけ
  （賃料改定画面の区画ページへのリンクも到達しないので触っていない）

### 範囲外（気づいたが直していない）

- **物件の論理削除も同じ型**: 投資・修繕・問合せ・区画・取引・物件の変更履歴の `property()` に `withTrashed()` が無く、
  物件の削除も契約中の契約しか止めない。削除した物件の投資・修繕の詳細は 500 になりうる → **2026-09-14 に「削除を止める」方式で対応**（下の「削除した物件を参照する画面」の節）
- **区画の CSV 取込**: 重複チェック（`TenantImportController`）は削除済みを見ないのに、一意制約（物件＋表示名。`idx_units_property_display`）は
  削除済みも含む → 削除済みと同じ名前の行があると取込全体が巻き戻る可能性が高い（画面からの登録は `UnitController::store` が復元するので安全）
  → **2026-09-14 に対応**（再現で全行が巻き戻ることを確かめ、削除済みの同名区画を復元して上書きする方式にした。下の「区画の CSV 取込で削除済みの同名区画を扱う」の節）
- 契約の登録で、画面を開いたあとに区画が削除されると 404（`ContractController::store` の `findOrFail`）— 通常の操作ではまず起きない
- 区画の削除の歯止めを増やすこと（利用者の判断で行わない）

### 検証

- 変異テスト（Bug #44 の作法。落ちたテストの集合と理由の文言まで照合）: 1 回目 **44 通り**（カナリア 1 を含む）のうち 42 通りが期待どおり。
  2 通り（投資・修繕の詳細で区画の欄から印を消す）が緑のまま通った — 同じ「B1A（削除済み）」が削除確認モーダルの対象にも出るため
  （Bug #43 / #46 と同型）→ 欄の見出しから辿る形に直し（`3458462c`）、当て直した 9 通りはすべて期待どおり。
  緑が正しい 3 通り（登録側の所属チェック＝等価 ／ 契約中タブ＝到達しない ／ コメント中の `Unit::class`）も緑を確認
- コンパイル済みビュー **269 本**を `php -l` → INVALID 0 件
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`・Playwright。使い捨てのログイン用ルートは戻した）:
  - 投資の一覧・詳細、修繕の詳細、問合せの一覧・詳細、物件詳細（投資・修繕・問合せ・解約の 4 タブ）、契約一覧（すべて）・詳細・削除確認、
    顧客詳細で「B1A（削除済み）」。投資・修繕の詳細は区画のリンクなし。物件詳細のフロアマップは 2A・1A だけ
  - 投資・修繕の編集画面で区画が「B1A（削除済み）」のまま選ばれ、ステータスだけ変えて保存しても区画が残る（DB の `unit_id` も 1 のまま。修繕は共用部にならない）
  - 問合せの編集画面でチップ「B1A（削除済み）」がチェック済みで出て、そのまま保存で残り、外して保存で消える
  - 登録画面 3 つ（投資・修繕・問合せ）の選択肢に削除済みの区画（B1A・3A）は出ない
  - コンソールのエラー・警告 0 件（セッション全体）

### 本番反映（2026-09-14 実施）

利用者の承認のあと、`13.x` へ FF マージ（`2dad69ee`）→ `./deploy.sh`（exit 0）。**DB 変更・ルート変更・新規 PHP クラス・新規依存は無し**
（`composer dump-autoload` も不要）。本番での確認はすべて読み取りだけで、本番のデータには触れていない。

| 見たこと | 結果 |
|---|---|
| 転送されたアプリのファイル | この変更の 19 本だけ（コントローラ 3・モデル 5・ビュー 11。`vendor`・設定・ルートは 0 件）|
| **コンパイル済みビューの `php -l`**（ssh・読み取りのみ）| **269 本 / INVALID 0 件** |
| 本番に置かれたファイル | `Unit.php` に `display_label` と `includingTrashed` ／ 投資・修繕・賃料改定履歴の `belongsTo(Unit::class)->withTrashed()` と問合せの `belongsToMany(…)->withTrashed()` ／ 契約詳細・物件詳細・顧客詳細のビューに `display_label` |
| 解約済み契約 C-1991-001 の契約詳細 | 区画が **「5A（削除済み） （13.76坪）」** |
| 契約一覧（ステータス: すべて・物件 12 で絞り込み）| その行の「物件 / 区画」が **「No.25ミツワビル / 5A（削除済み）」** |
| 物件 12 の詳細 | 解約タブのその行が **「5A（削除済み）」** ／ フロアマップは削除されていない **1A・1B だけ**（削除済みの 7 区画は出ない）|
| 顧客詳細（その契約の顧客）| 「解約済み（1件）」タブを開くとその行が **「5A（削除済み）」** |
| コンソール | 上の 4 画面ともエラー 0 件 |

⚠ 投資・修繕・問合せの画面は本番では見ていない（本番に削除済みの区画を参照するデータが 0 件で、症状が出る画面が無いため。動きはローカルのテストとブラウザで確認済み）。
⚠ `origin/13.x` への push はしていない（利用者の指示があったときに行う）。→ 2026-09-16 に push 済み（`e17718e0` に含まれる）。

---

## ✅ 削除した物件を参照する画面（物件の削除の歯止め）— 本番反映済み

実装計画（調査結果・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-14-deleted-property-guard.md

利用者の依頼（2026-09-14）は「削除した物件を参照する画面の修正」（前の節の範囲外）。使い捨ての再現テストで 30 画面を開き、静的調査と合わせて
**11 か所の 500**（投資・修繕・問合せの詳細、問合せの編集、区画の詳細・編集・賃料改定、区画の更新・削除、顧客詳細）・一覧から黙って消える・
編集画面に今の物件が無い・`exists:properties,id` が削除済みを通す、を確かめた。本番（読み取り・利用者の承認のうえ）は物件 17 件中 削除済み 1 件で関連データ 0 件＝発火前。
利用者の判断: **関連データが残る物件は削除させず「非稼働」を案内する**（区画のように印を付けて残す方式は採らない）。**DB 変更・ルート変更・新規 PHP クラス（アプリ側）・新規依存はいずれも無し。**

| 区分 | 実装内容 |
|------|---------|
| Model | `Property::DELETION_BLOCKING_RELATIONS`（区画・契約・投資・修繕・問合せ）／ `DELETION_IGNORED_RELATIONS`（変更履歴・取引・添付と理由）／ `deletionBlockers()` ／ docblock に不変条件と「子→物件のリレーションに `withTrashed()` を付けない理由」 |
| Controller | `Tenant\PropertyController::destroy()` の歯止めを置き換え（文言は `DeletionBlockers::summarize()` の形）／ 保存 7 か所の `property_id` を `Rule::exists('properties', 'id')->withoutTrashed()` |
| テスト | `tests/Feature/Tenant/PropertyDeletionGuardTest.php`（20 本）＋ `tests/Concerns/ScansModelRelations.php`（区画の構造テストから切り出し）。1672 → **1692 tests / 10373 assertions green** |
| ルート / DB / Blade | **どれも変更なし** |

### 要点

- 守る不変条件は「**削除済みの物件は、生きている区画・契約・投資・修繕・問合せを持たない**」。削除の歯止めと保存の入力チェックの 2 つで守り、画面の 11 か所には手を入れない（到達しない）
- ⚠ **子→物件のリレーションに `withTrashed()` を足さない**（Top trap #18 の区画の流儀とは逆）。足すと `whereHas('property')` が削除済みの物件まで拾い、一覧・ダッシュボードの意味が変わる
- 物件にリレーションを足したら「止める」「止めない」のどちらかに分類する（構造テストが両側から全件分類で守る）
- ロックはかけない（理由は `destroy()` のコメント）

### 範囲外（気づいたが直していない）

- `StructureTypeController` が削除済みの物件の構造も数える（削除済み物件だけが使う構造種別を消せない）
- 経営ダッシュボードの `aggregateTenantStats` は物件で絞らずに区画を数える（不変条件のもとでは影響なし）
- `TransactionController` にルートが無い（死んだコード）／`exists:properties` は部署を見ない

### 検証

- 変異テスト（Bug #44 の作法）26 通り（カナリア 1・対照 2 を含む）すべて期待どおりの集合と文言。対照の実測で、テストの注記（再現状態が効く理由）を直した
- コンパイル済みビュー **269 本**を `php -l` → INVALID 0 件
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`・Playwright。使い捨てのログイン用ルートは戻した）: 区画と投資のある物件を詳細から削除 →
  赤帯に「この物件には区画 1 件・投資 1 件があるため削除できません。…」が出て物件は残る ／ 関連データの無い物件は削除され、一覧から消えて成功メッセージ ／ コンソールのエラー・警告 0 件

### 本番反映（2026-09-14 実施）

下の「区画の CSV 取込で削除済みの同名区画を扱う」と一緒に反映した（記録はその節の「本番反映」）。反映の直前にも、削除済みの物件 T-009 に生きている関連データが 0 件であること（守る不変条件の前提）を読み取りで確かめた。

---

## ✅ 区画の CSV 取込で削除済みの同名区画を扱う — 本番反映済み

実装計画（調査結果・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-14-unit-import-restore-trashed.md

上の「論理削除した区画を参照する画面」の節の範囲外として残した件（Bug #60）。区画の CSV 取込の重複チェックが削除済みの区画を見ず、
削除済みと同じ名前の行はプレビューで「取込可能」になるのに、確定すると一意制約（物件＋表示名。削除済みの行も含む）に当たって
**正しい行も含めて全行が巻き戻る**（使い捨ての再現テストで確認）。契約・過去契約の取込は、削除済みの区画に「先に区画インポートを」と案内する行き止まりだった。
本番（読み取り・利用者の承認のうえ）は一意制約が実在し、削除済み区画 7 件（物件 12）＝発火前。
利用者の判断: **区画の取込は削除済みの同名区画を復元して上書きする**（画面の登録と同じ）／**過去契約は削除済みの区画のまま取り込む**／契約中の契約は削除済みの区画に入れず理由を出す。
**ルート変更・新規 PHP クラス（アプリ側）・新規依存は無し。本番の DB 変更も無し**（migration 2 本は SQLite のテスト用スキーマを本番に揃えるためのもの）。

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\TenantImportController` — 区画の取込（同名を `Unit::withTrashed()` で引く・削除済みは確定で `fill()` → `restore()`・予告は採用時にだけ積む・要約と完了メッセージに復元の件数・CSV 内の重複を表示名で見る）／ 契約の取込（削除済みの区画は理由つきのエラー行）／ 過去契約の取込（削除済みの区画のまま紐づけ、注意を出す）|
| Blade | `admin/tenant-import/index.blade.php` の区画タブの説明に 1 行 |
| テスト用スキーマ | `database/migrations/2026_09_14_000001_…`（`units.usage_type_id`。外部キーなし）／ `2026_09_14_000002_…`（契約の初月・最終月の 4 列。反映前の読み取りで本番の定義に合わせた）|
| テスト | `tests/Feature/Admin/TenantUnitImportTest.php`（19 本）＋ `tests/Concerns/SubmitsImportPreview.php`（`MansionImportTest` の往復ヘルパを切り出して共用）。物件の削除の歯止めと 13.x を取り込んだ状態で **1711 tests / 10534 assertions green** |
| ルート / 本番の DB | **どちらも変更なし** |

### 要点

- 復元は同じ id のまま戻す（過去の契約・投資もその区画に戻る）。CSV に無い `notes` は残る。状態は CSV の値（空欄なら空室）
- 予告・注意は、その行を**取り込むと決めた位置でだけ**積む（途中で積むと、同じ行に予告とエラーが並ぶ）
- 復元する id はブラウザから受け取らない（確定でも検査をやり直してサーバで決める）
- ⚠ CSV 内の重複は表示名で比べるので、**階の検査を重複チェックより先に**置く（戻すと「B1」のような階でプレビューごと 500。テストで固定）
- ⚠ **区画・契約の取込の確定は、これまでテストで一度も通っていなかった**（テスト用スキーマに本番の列が無く `no column named …` で落ちていた）。migration で揃えた

### 範囲外（気づいたが直していない）

- テスト用スキーマの `contracts.customer_id` / `rent_start_date` は NOT NULL だが、本番はどちらも NULL 可（反映前の読み取りで確認）＝テスト用スキーマの漂流（→ 2026-09-29〜30 に `customer_id` だけ空欄可にした。`rent_start_date` は 2026-10-29 に空欄可にした。下の「契約の上げ直しで二重にしない」「テスト用スキーマの契約の家賃発生日を本番どおり空欄可にする」の節）。
  アプリはどちらも空のまま契約を作る経路を持つ（テナント名が空の契約の取込・賃料開始日が空の過去契約）ので、その経路は確定までテストで通せない。テストは両方を埋めている
  （SQLite で NOT NULL を外すとテーブルの作り直しになり CHECK が消えるので、直すなら別の作業で測りながら）
- 過去契約の取込の「解約日が今日より未来です」の警告は途中で積むので、その後の検査でエラーになる行にも出る（今回の注意は採用時にだけ積む形にした）（→ 2026-09-29〜30 に直した。契約系の 4 経路の警告を行ごとに貯め、取り込む行の分だけ出す。下の「契約の上げ直しで二重にしない」の節）
- 区画タブの説明に足した 1 行はテストしていない（静的な文言。ブラウザで表示を確認した）

### 検証

- 変異テスト（Bug #44 の作法）**33 通り**と、スキーマを本番の定義に揃えたあとの **3 通り**すべて、期待どおりの集合のテストが期待どおりの文言で落ちた。
  初回は 1 通り（階の検査を重複チェックの後ろへ戻す）が緑のまま通った → 不正な階の行のテストを足して赤（TypeError → 500）を確認。
  確定が差し戻しで落ちる 4 通りは、フラッシュの `error` を使い捨ての探りで出して、一意制約違反・行が見つからない・列が無い、と理由まで確かめた
- コンパイル済みビュー **269 本**を `php -l` → INVALID 0 件
- **ローカル実ブラウザ**（使い捨て SQLite ＋ `artisan serve`・Playwright。使い捨てのログイン用ルートは戻した）:
  削除済みの 2A を含む区画の CSV → プレビューに予告の行と「区画 1件を新規作成・1件を削除済みから復元」→ 確定で
  「区画インポート完了: 2件を登録しました（うち削除済みから復元 1件）」・2A が同じ id で復元されメモも残る・物件詳細のフロアマップに 1A 2A 3A ／
  削除済みの 5A への契約の取込は理由つきのエラー行で取込ボタンなし ／ 過去契約は注意のうえ取り込まれ、契約一覧で「取込ビル / 5A（削除済み）」／
  コンソールのエラー・警告 0 件

### 本番反映（2026-09-14 実施。物件の削除の歯止めと一緒に）

利用者の承認のあと、反映前に本番を読み取りだけで確かめた（削除済みの物件 T-009 に生きている関連データ 0 件 ／ 契約の列の定義 ／ 削除済みの区画 7 件）。
その結果でテスト用スキーマの契約の列が本番と違うと分かり、本番の定義に合わせてから（`52b66dcc`。アプリのコードは変えていない）、
`13.x` を `unit-import-trashed-name` へ FF マージ（`3e9647f2`。物件の削除の歯止めと、別の作業の `471ce08f` deploy.sh の umask・`e105d517` 手順書の追記を含む）→ `./deploy.sh`（exit 0）。
**DB 変更・ルート変更・新規 PHP クラス（アプリ側）・新規依存は無し**（`composer dump-autoload` も不要）。本番での確認はすべて読み取りだけで、削除・取込はしていない。

| 見たこと | 結果 |
|---|---|
| 変わったアプリのファイル | コントローラ 6（区画の取込・物件・契約・投資・修繕・問合せ）・モデル 1（`Property`）・ビュー 1（取込画面）・migration 2（テスト用スキーマの鏡。本番では流さない）|
| **コンパイル済みビューの `php -l`**（ssh・読み取りのみ）| **269 本 / INVALID 0 件** |
| 本番に置かれたファイル | `Property::deletionBlockers()` ／ 保存 7 か所の `Rule::exists('properties', 'id')->withoutTrashed()`（投資 2・修繕 2・問合せ 2・契約 1）／ 区画の取込の復元（`_restore_unit_id`）／ 区画タブの説明 |
| 設定キャッシュ（別の作業の deploy.sh の変更）| `bootstrap/cache/config.php` が `-rw-------`（600）で作り直された |
| 取込画面（区画タブ）| 説明「削除済みの区画と同じ区画（階＋部屋番号）の行は、その区画を復元してCSVの内容で上書きします（プレビューで予告します）」が出る |
| 物件 12 の詳細 | 開ける。削除フォームは DELETE・CSRF つき（押していない）|
| 契約一覧（すべて・物件 12）| 1A・1B と「No.25ミツワビル / 5A（削除済み）」（Bug #58 の表示を保つ）|
| 物件一覧 | 16 件（17 件から削除済み 1 件を除く）|
| コンソール ／ `main` の横スクロール | 上の画面ともエラー 0 件 ／ 0 |

⚠ 物件の削除の歯止め（断る文言）・区画の取込の復元・契約／過去契約の取込は、本番では動かしていない（データを変える操作のため。動きはローカルのテストとブラウザで確認済み）。
⚠ `origin/13.x` への push はしていない（利用者の指示があったときに行う）。→ 2026-09-16 に push 済み（`e17718e0` に含まれる）。

---

## ✅ 日時の表示と「今日」を日本時間にそろえる — 本番反映済み

実装計画（調査結果・変異テストの実測記録つき）: @docs/superpowers/plans/2026-09-19-japan-time.md

利用者の依頼（2026-09-19）は、決裁 段階1 の実ブラウザ確認（Task 16 の F5・F6）で見つかった 2 つ —
①**保存された日時が一日中 9 時間ずれて出る**（登録・更新日時・最終ログイン・変更履歴・添付・ファイルの登録日）
②**「今日」「今月」「今年度」が日本時間の 0:00〜8:59 に前日になる**（画面の「〇年〇月〇日 時点」・フォームの既定の日付・
自動の対応履歴の日付・問合せ番号の年・5/1 と 6/1 の年度の切り替え・工程表の今日・年齢・キャンペーン期間）。
原因は `config/app.php` の `timezone` が `'UTC'` の直書き（最初のコミットから）で、保存は UTC の壁時計で正しいのに
**表示が変換していない**こと。②は再現するのが 1 日のうち 9 時間だけなので、日中に画面を開いても分からない。

利用者の判断は **案 (a)** ＝ **アプリ全体の timezone は UTC のまま**にして、「利用者に見せる日時」と「日本の今日」だけを
日本時間にする（段階0 の決定と `tests/Feature/Ops/ScheduleTest.php::test_application_timezone_stays_utc` はそのまま）。
**DB 変更・ルート変更・新規依存はいずれも無し。**

| 区分 | 実装内容 |
|---|---|
| Support | `App\Support\JapanTime` を新設（**このブランチで唯一の新規 PHP クラス**）。`format()` ＝ 保存された日時（UTC）→ 日本時間の文字列 ／ `today()` ＝ 日本の今日 |
| 保存された日時の表示 | **24 か所 / 13 ファイル**（ビュー 16・コントローラ 8）を `JapanTime::format()` へ |
| 「今日」の表示・既定値・保存 | **33 行**（ビュー 19 ファイル 20 行・PHP 8 ファイル 13 行）を `JapanTime::today()` へ |
| 判定・集計 | **31 か所 / 21 ファイル**（年度・月次の集計・工程表の今日・年齢・キャンペーン期間）|
| 走査テスト | `StoredTimestampDisplayScanTest`（表示）/ `ClockReadScanTest`（時計の読み取り）＋ `JapanBusinessDayTest`（5 月始まりの年度の式を全件分類）|
| 変えていないもの | アプリの timezone（UTC）・TIMESTAMP 列への保存・期限・ログ・定期実行・`config/app.php` |
| ルート / DB | **どちらも変更なし** |
| テスト | 1711 → **1752 tests / 10775 assertions green**（+41）|

### 要点

- **部品は 2 メソッドだけ**。⚠ **`today()` は日本の日付の「UTC の 0:00」を返す** — 日本時間の 0:00（UTC では前日 15:00）で
  返すと date キャストの属性・`Carbon::create()` / `createFromFormat('Y-m-d', …)` と 9 時間ずれ、
  `ZealFiscalYear::isFutureMonth()` が当月を来月と判定する（変異 M03 で 13 本が赤）
- ⚠ **`today()` は可変の `Carbon`** を返す（`now()` / `today()` のドロップイン置換のため。不変にすると
  `$x->startOfMonth()` のような**戻り値を捨てる書き方が無音の no-op** になる）。`CarbonImmutable` を要求する
  2 つのサービスだけ `->toImmutable()` を付ける（落とすと TypeError）
- ⚠ **`format()` を date キャストの列に既定の書式で当てない** — UTC の 0:00 が日本時間の 9:00 になり
  **存在しない時刻**が出る（実測 `2026/05/01 09:00`）。走査は仕様上 date キャストの `_at` を対象から外すので
  **原理的に止まらない**。日付だけの列は第 2 引数で `'Y/m/d'` を渡す
- ⚠ **TIMESTAMP 列への保存・期限・運用は `now()` のまま**（`JapanTime::today()` を使わない）
- 走査の規模は PHP **267 本** / Blade **249 本**。**ビューの時計の読み取りは 0 件**、PHP の時計の読み取りは
  **11 件 / 8 ファイル**で、すべて理由つきで ALLOWED に分類してある
- ⚠ **走査の死角**（docblock に列挙済み）: 表示は 変数に入れる ／ 配列の添字 ／ `{{ $x->created_at }}` の素出し ／
  `->setTimezone()` `->copy()` `->addHours(9)` `->startOfDay()` を挟む形 → 捕まえるのは
  **件数の下限 `MIN_FORMAT_CALLS = 24` だけ**（変異 M26 で実測）。**下げる前に「消した」のか「化けた」のか確かめる**。
  時計側は 先頭 `\` 付きの呼び出し ／ `Carbon::parse('now')` ／ `new DateTime('+1 day')` ／ 一覧に無い判定
- ⚠ **ビューの注意書きは `{{-- --}}` に書く**（行中の `//` はあえて落とさない ＝ 落とすようにすると
  `<p>x</p> // {{ now() }}` の形で本物を隠す）

### 振る舞いが変わった 2 か所（計画は「唯一」と書いていたが実測で 2 つ）

**① `ZealPlan::isCampaignActive()`**（9/1〜9/30 の例。実測）

| 時点 | 旧 | 新 |
|---|---|---|
| JST 8/31 23:59 | 期間外 | 期間外 |
| **JST 9/1 0:00** | 期間外 | **適用中**（開始が 9 時間早まる）|
| **JST 9/30 23:59** | **期間外** | **適用中**（終了日がほぼ丸一日使えなかったのが使える）|
| JST 10/1 0:00 | 期間外 | 期間外 |

本番の呼び出しは `zeal/plans/index.blade.php:103` の「適用中」バッジ **1 か所だけ**で、保存される金額には影響しない
（`effectivePriceExcl()` は呼び出し 0 件の死にコード）。

**② `ZealMember::age()`** — 688,128 通りの掃引で、**未来の誕生日（入力ミス）の 243 件**だけが旧実装と 1 違う
（全件「差がちょうど整数年」。うち 24 件は Carbon が 2/29 → 3/1 を 1 年ちょうどと数えるもの）。
**過去の誕生日の食い違い 378 件は、すべて日本時間 0:00〜8:59 の帯 ＝ この改修が直した時差そのもの**。
⚠ `birthday` の入力チェックは `nullable|date` だけで、`before_or_equal` はアプリ全体で 0 件（足すかは別の判断）。

### 範囲外（気づいたが直していない）

- **月末の溢れ** → 2026-09-23 に直した（下の「✅ 月末の溢れを直す」の節。実際は 4 か所・5 行だった）
- `Zeal/SimulationController` の残る冗長さ ／ `ZealPlan::effectivePriceExcl()`（呼び出し 0 件の死にコード）
- **DAD の年度が 2 つある**: `Dad/ProjectController::currentFiscalYear()` は 5 月始まりだが、
  CLAUDE.md と `ZealFiscalYear::START_MONTH` は「ZEAL/DAD は 6/1 始まり」。**業務判断が要る**（→ 2026-11-19 に利用者の決定で 6 月始まりにそろえた。下の「利用者の判断を待っていた 1〜3 を直す」の節・Bug #141）
- `birthday` に `before_or_equal:today` を足すか
- `app/Mail/BackupFailedMail.php:31` は `JapanTime::format()` と逐語で同値（既定の書式まで一致）だが、
  段階0 のメール経路なので保留
- `Tenant/UnitController.php:529` の並び替えキーが `created_at` の `H:i:s` だけを見ており、
  同じ改定日に UTC の日をまたいだ 2 行で新旧が逆に並ぶ（**timezone とは独立した既存のバグ**）
- 日付ピッカー 8 ビュー（`datePicker()` の 7 本＋`mansion/properties/_form` の年月ピッカー 1 本。2026-09-23 に数え直し。以前は 6 と書いていた）の `var now = new Date();` は**ブラウザのローカル日**に従う（サーバの日本の今日ではない）
- `withoutComments()` が走査テスト 2 本に逐語で複製されている（`tests/Concerns/` へ切り出す候補）

### ✅（2026-09-23 に解決）月末の溢れ 3 か所

→ 下の「✅ 月末の溢れを直す」の節で直した。⚠ この節の数字は 2 つ誤っていた（2026-09-23 の実測）:
①「3 か所」は実際には **4 か所・5 行**（`admin/master/zeal-simulation-categories/_form.blade.php:112` の「来月」が漏れていた）
②「3/31・5/31・7/31・10/31・12/31 の 5 日」は実際には **3/29**・5/31・7/31・10/31・12/31（3/30・3/31 は日本時間化の前から終日溢れていた）。

「今日」の基準を UTC の暦日 → 日本の暦日に変えた結果、**既存の月末の溢れバグの露出が広がった**
（3/31・5/31・7/31・10/31・12/31 の 5 日について **15/24 時間 → 24/24 時間**）。**我々が作ったバグではない。**

| 場所 | 症状（実測）|
|---|---|
| `DashboardController.php:106`（前月ラベル）と同 `:855`（前月の集計）| 3/31 の朝に「**3月実績**」と出て、当月の未確定データを前月として集計する。**ラベルとデータが自己矛盾しないまま両方間違う**ので画面から気づけない |
| `Zeal/DashboardController.php:42` の `$lastMonth` | 前月の入会・退会が当月と同じ値になり差分が 0 |
| `Zeal/InquiryController.php:56` の `subMonths($i)` | 2026-05-31 で 18 か月のうち**重複 7 件・欠落 7 件**（`2026-04` `2026-02` `2025-11` …）|

直し方は既存の規約どおり（月初へ正規化してから加減算。同じファイルに前例とコメントがある）:
`JapanTime::today()->startOfMonth()->subMonth()`。

### 検証

- 全テスト **1711 → 1752 tests / 10775 assertions green**（+41）
- **変異 32 通りを実測**（docs/RULES.md Bug #44 の作法。① `git status --porcelain` が空 → ②変異は出現がちょうど 1 回のときだけ当てる →
  ③着弾を確認 → ④全件を `--log-junit` で流す → ⑤落ちたテストと**理由の 1 行目**を記録 → ⑥復元 → ⑦空を再確認）。
  **期待と違ったのは M15 の 1 件だけ**で、調べた結果 Laravel の `SoftDeletes::initializeSoftDeletes()` が
  `deleted_at` を `datetime` キャストへ足すための**真の等価変異**だった（4 モデルで実測し、3 行に理由を書き添えた）。
  **意図して緑にしたもの 3 通り**（書式の第 2 引数を落とす＝走査は形しか見ない ／ 引数 2 つの `date()` を足す＝過剰に拾わない証明 ／
  `CarbonImmutable::instance` → `Carbon::instance` ＝ どちらも clone するので等価）。全表は実装計画の「実測記録」
- **コンパイル済みビュー 269 本を `php -l`** → INVALID 0 件（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）
- ✅ **ローカル実ブラウザで確認済み（2026-09-23 01:58）。たまたま日本時間 0:00〜8:59 の窓に当たり、
  症状そのものが再現する状態で測れた**（UTC の今日 2026-09-22 / 日本の今日 2026-09-23）。
  使い捨ての SQLite ＋ `artisan serve`（⚠ worktree には `public/build` が無いので main repo の
  node_modules で `vite build` してから。確認後に消した）:

| 見たこと | 実測 |
|---|---|
| 建売物件の詳細・不動産の契約の詳細の登録・更新日時 | **2026/09/19 02:12**（UTC のままなら 2026/09/18 17:12。その文字列は 0 件）|
| 基幹の利用者一覧の最終ログイン | **09/23 01:57**（UTC のままなら 09/22 16:57）|
| 問合せ登録の既定の問合せ日 | **2026-09-23** ＝ 日本の今日（UTC のままなら 2026-09-22）|
| 周辺ビル調査の取込の `surveyedMonth`（JS に埋める値）| ソースも Alpine が束ねた input の値も **2026-09**（JS の埋め込みが壊れていない）|
| コンソール | 対象 4 画面ともエラー 0 件 |

  ⚠ `/dashboard/executive` だけ 500 になるが、理由は `no such table: ms_rooms` ＝
  **`ms_*` / `zeal_*` は raw SQL 管理でテスト用 trait にしか無く、この使い捨て DB に作っていない**ため。
  この改修とは無関係（本番には実在する）

### 本番反映（2026-09-23 実施）

⚠ **DB 変更・ルート変更は無い**ので SQL は流していない。⚠ ただし**新規 PHP クラスが 1 本ある**
（`app/Support/JapanTime.php`）ので、CLAUDE.md の手順どおり **main repo の cwd で `composer dump-autoload`** を挟んだ
（worktree から実行すると autoloader の `$baseDir` に worktree のパスが焼き込まれる）。

```
git checkout 13.x && git merge --ff-only japan-time
composer dump-autoload          # main repo の cwd で（新規 PHP クラスがあるため）
./deploy.sh
```

反映後の手元の状態: `13.x` = **`3dfad648`**（FF マージ済み）／ `JapanTime` が
`vendor/composer/autoload_classmap.php` に載っている（＝ 新規クラスの autoload が通る）／
両 worktree とも作業ツリー清浄。⚠ `origin/13.x` への push はしていない。→ 2026-09-24 に push 済み（`4fa0911e` に含まれる）。

### ✅ 本番の実ブラウザ確認（2026-09-23 08:52〜08:56。ログイン済みの実 Chrome・読み取りのみ）

⚠ **URL は `/system/manage/index.php/…` を挟む**（素のパスは 302 で流れる）。

**⚠ たまたま「今日」のずれが再現する窓（日本時間 0:00〜8:59）に当たったので、
その場でしか測れない 2 つを先に確かめた**（このとき UTC の今日 2026-09-22 / 日本の今日 2026-09-23）:

| 見たこと | 実測 | 旧コードなら |
|---|---|---|
| 問合せ登録の既定の問合せ日 | **2026-09-23** | 2026-09-22 |
| 賃貸マンションのダッシュボードの「〇年〇月〇日 時点」 | **2026年9月23日** | 2026年9月22日 |

**この 2 つが通ること自体が「新しいコードが本番で動いている」証明**になる（旧コードでは原理的に前日が出る）。
⚠ 逆に、**保存された日時の表示は過去の値を見ても新旧を区別できない**（同じ UTC の値が +9 時間で描かれるだけで、
基準にできる独立した時刻が画面に無い）。表示側はテスト 1752 本とローカルの実ブラウザで固定済みなので、
本番では「落ちないこと」を確かめる方針にした。

| 見たこと | 実測 |
|---|---|
| 画面の掃き出し（一覧・詳細・ダッシュボード・ボード・取込など **33 画面**）| **33/33 が 200**。500 は 0 件 |
| 建売物件の詳細（HS-008）| 登録 `2026/05/11 11:40` ／ 更新 `2026/05/11 13:51` と日時が描かれる |
| 不動産の契約の詳細 | `2026/09/17 14:19` |
| 基幹の利用者一覧の最終ログイン | `07/03 09:21` ／ `08/12 17:26`（`m/d H:i` の書式で描かれる）|
| ZEAL のプランマスタ | 200。**いま「適用中」のキャンペーンは 0 件**なので、振る舞いが変わった判定は画面に出ていない |
| コンソール | 4 画面（建売詳細・問合せ登録・賃貸マンションのダッシュボード・経営ダッシュボード）で**出力 0 件** |
| 経営ダッシュボードの `main` の横スクロール | 無し（Bug #29）|

⚠ **コンパイル済みビューの `php -l` は本番では未実施**（ssh が要るため）。
ローカルでは **269 本 / INVALID 0 件**を確認済みで、`deploy.sh` の `view:cache` も成功している。
Bug #21 / #26 の「本番だけ 500」に当たる画面は、上の 33 画面の掃き出しで 500 が 0 件だったことで代替した。

---

## ✅ 月末の溢れを直す（前月・来月・過去 18 か月）— 本番反映済み

実装計画（走査・掃引・変異テストの記録つき）: @docs/superpowers/plans/2026-09-23-month-end-overflow.md

上の「日時の表示と『今日』を日本時間にそろえる」で宿題にした月末の溢れ（docs/RULES.md Bug #62）。
Carbon の `subMonth()` / `addMonth()` / `subMonths($i)` は、移った先の月にその日が無いと翌月へ溢れる（3/31 の 1 か月前 = 2/31 → 3/3）。
「今日」から直接足し引きしていた 5 行を、既存の規約どおり「月初へ寄せてから足し引き」に直した。
**DB 変更・ルート変更・新規 PHP クラス（アプリ側）・新規依存はいずれも無し。**

| # | 場所 | 症状（今日 = 2026-03-31） |
|---|---|---|
| 1・2 | `DashboardController::tenant()`（ラベル）と `aggregateBuildingStats()`（集計）| テナントダッシュボードのビル別カードが「3月実績」と出て、3 月の未確定の契約を前月として集計する |
| 3 | `Zeal\DashboardController` の `$lastMonth` | 先月比が 0（先月も 3 月を数える）|
| 4 | `admin/master/zeal-simulation-categories/_form.blade.php` の適用開始月 | 既定が 2026-05（正は 2026-04）。既定のまま保存すると 4 月の既存セルに新しい金額が反映されない |
| 5 | `Zeal\InquiryController` の月の選択肢 | 18 か月のうち重複 7・欠落 7（出る月は 11 種）|

| 区分 | 実装内容 |
|---|---|
| 直し方 | 5 行とも `->startOfMonth()` を先に通す。#5 は起点をループの外で 1 回作り `->copy()->subMonths($i)` |
| テスト | `tests/Feature/MonthEndOverflowTest.php`（4 本・32 アサーション）。1752 → **1756 tests / 10807 assertions green** |
| ルート / DB | どちらも変更なし |

### 要点

- **走査**: PHP（Carbon）の月の足し引きは 22 件（JS の `setMonth` 7 件は別）。直したのは 5 件、残り 17 件は起点が `startOfMonth()` か `Carbon::create(年, 月, 1)`（1 件ずつ起点まで遡った）
- **溢れる日**（2026 年の全日を掃引）: 前月 7 日（3/29・3/30・3/31・5/31・7/31・10/31・12/31）／ 来月 7 日（1/29・1/30・1/31・3/31・5/31・8/31・10/31）／ 過去 18 か月の一覧 29 日。閏年の 2028 年は 6 / 6 / 25 日
- **Bug #61 との関係**（2026 年を 1 時間ごとに掃引）: 前月側で日本時間の丸一日溢れるようになったのは 3/29・5/31・7/31・10/31・12/31 の 5 日（それまでは 15 時間）。7 つの月の 1 日（2/1・3/1・5/1・7/1・9/1・10/1・12/1）の朝 9 時間の誤り（計 63 h）は Bug #61 で消えていたので、前月の誤りの時間は年間 **186 h → 168 h → 今回 0 h**
- **回帰テストの「今日」は 2026-03-31 に固定**し、その日が前月・来月とも溢れる日であることをテスト自身が確かめる（`assertTodayOverflows()`）。同じ画面の 3 つの「前月」（ビル別カードのラベル・集計・全体カードの実績期間）を 1 本で見る（Bug #41）
- 体験予約（外部 DB の `'zeal'` 接続）はテストで SQLite のメモリへ向け直し、ダッシュボードの件数が 0 であることで向け直しが効いたと確かめる

### 範囲外（気づいたが直していない）

- 日付ピッカーの「1ヶ月前」ボタン（JS の `setMonth(getMonth() - 1)`。定義 7 か所・ボタン 8 か所）— 同じ形に溢れる（3/31 に押すと 3/3）。ブラウザのローカル日なので Bug #61 の影響は受けておらず、直し方も別（前月の末日で止めるか）→ 2026-09-24 に直した（下の「✅ 日付ピッカーの『1ヶ月前』を前月の末日で止める」の節）
- 新しく足した月の足し引きを止める走査テスト（22 件の全件分類）— 別の作業の候補
- `DashboardController::aggregateBuildingStats()` の入居率は、前月末日に終わる契約を入居に数えない（`endOfMonth()` の 23:59:59 と、日付だけの解約日の 0:00 を比べているため。実測 `false`）。同じメソッドのコメント「前月末以降」と食い違い、収入には入るが入居率からは外れる。どちらに合わせるかは業務判断

### 検証

- 全テスト **1752 → 1756 tests / 10807 assertions green**
- **変異 17 通りすべて期待どおり**（docs/RULES.md Bug #44 の作法。カナリア 1・等価 1 を含む）— 5 行それぞれを元の順序へ戻すと、狙ったテストだけが狙ったアサートの文言で落ちる。自己検査は「今日」を 3/15 にすると前月側、7/31 にすると来月側で 4 本とも落ち、自己検査を外して 3/15 にすると #1 を戻してもテスト 1 が緑になる（＝自己検査が効いている）。全表は計画の「実測記録」
- コンパイル済みビュー **269 本を `php -l`** → INVALID 0 件
- 実装 → 仕様適合レビュー → コード品質レビュー（Minor 3 点を取り込み、再レビューで承認）

### 本番反映（2026-09-24 実施）

下の「日付ピッカーの『1ヶ月前』を前月の末日で止める」と一緒に反映した。そのブランチはこのブランチの先頭から切ってあるので、`date-picker-month-ago` を 1 回早送りするだけで両方入った。記録と本番確認の結果はその節の「本番反映」を参照。

⚠ 画面の見た目が変わるのは溢れる日（月の 29〜31 日）だけ。反映した 9/24 は新旧の式が同じ値になる日なので、本番では「落ちないこと」と普段どおりに動くことを確かめた
（テナントダッシュボード・ZEAL ダッシュボード・体験予約一覧・項目マスタの編集画面がすべて 200、コンソールのエラー 0 件）。

---

## ✅ 日付ピッカーの「1ヶ月前」を前月の末日で止める — 本番反映済み

実装計画（変異テスト・実ブラウザの記録つき）: @docs/superpowers/plans/2026-09-24-date-picker-month-ago.md

上の月末の溢れの修正中に見つけた JS 版（docs/RULES.md Bug #62 の追記）。7 か所の日付ピッカーの「1ヶ月前」が、前月にその日が無い日（3/31 など）に押すと当月の日付を選んでいた（3/31 → 3/3、5/31 → 5/1、12/31 → 12/1。カレンダーも当月のまま）。2026 年なら 3/29・3/30・3/31・5/31・7/31・10/31・12/31 の 7 日で起きる。
**利用者の決定（2026-09-24）: 前月の末日で止める**（Excel の EDATE と同じ。ふつうの日は今と同じ）。
**DB 変更・ルート変更・アプリの PHP の変更・新規依存はいずれも無し**（Blade の中の JS だけ）。

| 区分 | 実装内容 |
|---|---|
| 対象 | 定義 7 か所・画面 8（DAD 工事案件の登録・編集／住宅の建売・注文住宅の契約の編集／賃貸マンションの部屋・駐車場の改定と解約）|
| 直し方 | `setMonthAgo` の 2 行を「前月の末日（`new Date(年, 月, 0).getDate()`。月は JS の 0 始まり）と今日の日の小さいほう」に。7 か所とも同じ |
| テスト | `tests/Feature/DatePickerMonthAgoTest.php`（5 本）。1756 → **1761 tests / 10979 assertions green** |

### 要点

- 画面と同じ関数を node の vm で実際に動かす（Blade のソースから切り出し、中に Blade 構文と `</script` が無いことも確かめる）
- 7 コピーを機械的に列挙（下限 7）・リテラル 8 通り（初期値 2020-06-15 で開いても基準は今日）・2026〜2028 の全 1,096 日を独立した暦と突き合わせる
- ビューの `.setMonth(` / `.setUTCMonth(` の字面は 0 件に保つ（ラチェット）
- 検出器 4 つに全部の枝を通す自己テスト。偽の Date は `new` なしの呼び出しで文字列を返す（ブラウザと同じ）

### 範囲外

- 7 つの複製を 1 つの共通スクリプトにまとめること（別の作業の候補）
- テストの死角（docblock に列挙）: `<script>` が描画・実行されるか／走査（ラチェット）は、定義の外に新しく書いた `new Date(y, m - 1, d)` のようにコンストラクタで月を足し引きする形を見ない（7 つのコピーの中なら振る舞いのテストと掃引が止める）

### 検証

- 全テスト **1756 → 1761 tests / 10979 assertions green**
- **変異 29 通りすべて期待どおり**（7 コピーのどれを元に戻してもそのファイルを名指し・`new` を落とす 3 通り・検出器を弱める 4 通り・`</script>`・選択中の日付を基準にする・自己検査の日付・掃引の範囲など。緑は死角の 2 通りと等価 1 通りだけ）。全表は計画の「実測記録」
- コンパイル済みビュー **269 本 / INVALID 0 件**
- **実ブラウザ**（Playwright で時計を 2026-03-31 12:00 に固定）: マンションの部屋契約の賃料改定・住宅の建売契約の編集の 2 画面で「1ヶ月前」→ 表示 2026/02/28・送信欄 2026-02-28・表示月 2026-02・押した直後に閉じる・コンソールのエラー 0 件
- 実装 → 仕様適合レビュー → コード品質レビュー（Important 3 件と Minor を取り込み、再レビューで承認）

### 本番反映（2026-09-24 実施。月末の溢れと一緒に）

ブランチ `date-picker-month-ago` は `month-end-overflow` の先頭から切ってある（記録が衝突しないように）ので、早送り 1 回で両方入った。
**DB 変更・ルート変更・新規 PHP クラス（アプリ側）・新規依存は無い**ので、SQL も `composer dump-autoload` も行っていない。

```
git checkout 13.x && git merge --ff-only date-picker-month-ago
./deploy.sh
```

- 反映前: main repo の作業ツリーが空・13.x が `e6787c20` のまま（別の作業で進んでいない）・早送りできる・本番へ送る `vendor` に dev 依存（`vendor/bin/phpunit`）が無いことを確かめ、worktree で全件を流して **OK (1761 tests, 10979 assertions)**
- 早送りで `13.x` = **`999729f2`**（テストを流したコミットと同じ中身）。`./deploy.sh` は exit 0 で、本番の config / route / view のキャッシュ作り直しも 3 つとも成功
- 本番へ送られたアプリのファイルは、コントローラ 3 本（`DashboardController` / `Zeal\DashboardController` / `Zeal\InquiryController`）・ビュー 8 本とビルド成果物だけ。CSS / JS のバンドル名は本番と同じで、旧バンドルの掃除で消えたファイルは 0（CSS の元ファイルは変えていない）。ビルドが出す警告 1 件（Google Fonts の `@import` の位置）は以前からある
- ⚠ `origin/13.x` への push はしていない → 2026-09-24 に push 済み（`4fa0911e` に含まれる）

### ✅ 本番の確認（2026-09-24 13:44〜13:50。ログイン済みの実 Chrome・読み取りのみ）

⚠ **URL は `/system/manage/index.php/…` を挟む**。ブラウザの今日は 2026-09-24（日本時間）。

| 画面 | 実測 |
|---|---|
| テナントダッシュボード | 200。ビル別カードのラベルが「**8月実績**」・`main` の横スクロール 0 |
| ZEAL ダッシュボード | 200。「2026年9月24日 時点」（`$now` を書き換えていない）・先月比「-4名」「±0名」 |
| ZEAL 体験予約一覧 | 200。月の選択肢が **2026-09〜2025-04 の 18 か月で連続・重複 0**（value とラベルの月も一致）|
| 経営試算表の項目マスタの編集（固定額の「賃料」#3）| 200。適用開始月の既定が **2026-10**（来月）|
| DAD 工事案件の新規登録 | 200。日付ピッカー 7 個のうち 3 個（見積日・受注日・入金日）で「1ヶ月前」→ **2026-08-24**（表示 2026/08/24）|
| 住宅 建売契約の編集（#4）| 200。契約日 2026-09-23 →「1ヶ月前」で **2026-08-24** |
| 住宅 注文住宅契約の編集（#3）| 200。契約日 2026-09-12 →「1ヶ月前」で **2026-08-24**。**マウスでも**「今日」→ 2026-09-24、開き直して「1ヶ月前」→ 2026-08-24・カレンダーは 2026年8月・押した直後に閉じる |
| コンソール | 上の 7 画面ともエラー 0 件 |

- ⚠ **月末のずれそのものは溢れる日にしか見えない**（9/24 は新旧の式が同じ値になる日）。本番では「落ちないこと」と普段どおりに動くことを確かめた。溢れる日の振る舞いは `MonthEndOverflowTest` / `DatePickerMonthAgoTest` と、ローカルの実ブラウザ（時計を 2026-03-31 に固定）で確認済み
- ⚠ **フォームは 1 つも送信していない**（本番のデータは変えていない）。「1ヶ月前」は、DAD と建売の画面では JS の click イベントで押し、注文住宅の画面ではマウスでも押した
- ⚠ **賃貸マンションの部屋契約・駐車場契約は本番に 0 件**なので、改定・解約の 4 画面（`mansion/contracts/*/revise` `terminate`、`mansion/parking-contracts/*/revise` `terminate`）は本番で開けていない。この 4 本は `DatePickerMonthAgoTest`（node で関数を動かす）とコンパイル済みビューの lint、ローカルの実ブラウザ（部屋契約の賃料改定の 1 本）で確認済み
- ⚠ **コンパイル済みビューの `php -l` は本番では未実施**（ssh が要るため）。ローカルでは 269 本 / INVALID 0 件で、`deploy.sh` の `view:cache` も成功している。変更したビュー 8 本のうち本番で開ける 4 本（項目マスタ・DAD・住宅 2）はすべて 200

---

## ✅ 決裁申請 段階1（基幹の改修）— 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-16-approval-phase1-design.md
実装計画: @docs/superpowers/plans/2026-09-16-approval-phase1.md
要件定義書: @docs/決裁申請_要件定義書_v1.md（16.1 の段階1）
前段: 段階0（定期実行・メール送信の土台・暗号化バックアップ）は本番稼働中 — @docs/運用_バックアップとメール.md

社員番号でログインでき、決裁のみ利用者を決裁以外の全画面から締め出し、決裁の管理者が部門と利用者を
設定して CSV で一括登録し、初期パスワードを紙の案内で配れる状態にする。
**決裁の機能そのもの（申請・承認）は段階2 以降。** 段階1 の決裁のホームは「準備中です」と出すだけの仮。

| 区分 | 実装内容 |
|------|---------|
| 依存 | **`chillerlan/php-qrcode ^6.0` を新規導入**（ログイン案内の QR。⚠ `ext-mbstring` だけで動く版を選んだ。v7 は PHP 8.4 必須で本番の 8.3 に乗らない）|
| DB | `users` の改修（`employee_number`・`role` に `approval_only`・SoftDeletes ほか）＋ **`approval_*` の 7 表**。本番は `database/sql/2026-09-16-approval-phase1.sql`、テストは同じ構造の migration |
| Enum | `UserRole` に 4 つ目の値 `approval_only`（決裁のみ。D6。1 人 1 ロールなので矛盾した状態を作れない）|
| Middleware | `RestrictApprovalOnlyUsers`（決裁以外の全画面から締め出す）／ `EnsureApprovalAdmin`（`approval.admin`。管理系の 2 段目）。⚠ どちらも **`SubstituteBindings` より前** |
| Support | `LoginId`（社員番号とメールの正規化）／ `InitialPassword`／ `LoginQrCode`／ `Support\Approval\*` |
| Model | `ApprovalCompany` / `ApprovalDepartment` / `ApprovalMember` / `ApprovalSetting` / `ApprovalMailDomain` / `ApprovalSettingLog` |
| Controller | `Approval\{Home,User,UserImport,Organization}Controller` |
| Blade | `approvals/home`・`login-guide`・`admin/organization`・`admin/users/{index,import,_import_preview}` ＋ **`layouts/partials/sidebar_approval.blade.php`** を新設 |
| ルート | **19 本**（`routes/approval.php`。`web.php` の末尾が require する）|
| テスト | 1711 → **2068 tests / 14499 assertions green**（+357。Task 15 までの値）|

### 要点

- **ログイン ID は社員番号とメールアドレスのどちらか**（基幹を使う人も同じ）。社員番号は英数字とハイフン 20 文字まで・
  文字列で保存（先頭の 0 を保つ）・全角→半角・英字を大文字にそろえてから照合。`@` は使えない（メールと区別するため。D5）
- **決裁のみ利用者はロールの 4 つ目の値**（D6）。`approval_only` は決裁以外の全画面から締め出す
- ⚠ **決裁の管理者はロールとは独立**（`approval_members.is_admin`）。基幹を使う人（経営層・管理者・一般担当）も
  指定されれば決裁の管理者になれるし、決裁のみ利用者が管理者になることもある
- **サイドバーは 3 か所ある**（PC 展開版・PC 折りたたみ版・モバイルのドロワー）。決裁のみ利用者には
  決裁用の partial を `layouts/app.blade.php` が出し分け、基幹を使う決裁の管理者には基幹のサイドバーに
  「決裁の管理」グループが増える。⚠ **1 か所でも漏れるとその画面幅でだけリンクが消える**（Bug #41 の型）
- ⚠ **決裁用サイドバーも Bug #56 の決まりを守る** — 展開版に `x-cloak` を付けず、折りたたみ版とドロワーには付ける。
  `LayoutSidebarCloakTest` は基幹のサイドバーしか描画しないので、決裁側は `ApprovalSidebarTest` が見る。**両方を対で維持する**
- **一般の利用者に見える変化は、ログイン画面の「社員番号またはメールアドレス」だけ**（D2）。
  部門の管理・利用者の管理は、決裁の管理者に指定するまで誰にも出ない
- CSV 一括登録の導線は**利用者の管理の画面のリンク**（サイドバーには出さない。取込はその下位の画面）
- 初期パスワードは**確定・再発行の直後の画面にだけ**出す。どこにも保存しない（D4）

### 範囲外（気づいたが直していない）

- **サイドバーが毎リクエスト `approval_members` を 1 本引く**（`isApprovalAdmin()` の遅延ロード）。
  索引つきの 1 件引きで、認証済みの全画面（約 200 ルート）に定数で +1 本乗る。機能に必要な問い合わせなので許容した。
  ⚠ `PropertyListSortTest` の N+1 計測は、同じ User オブジェクトを使い回すと 1 回目にだけこの +1 が乗って
  「増えた」ように見えるので、2 回とも `fresh()` した別インスタンスで測る（Bug #39 と同じ理由）
- 設定の変更の記録（`approval_setting_logs`）を**見る画面**は作っていない（D14。必要になったら）
- 段階2 以降へ回すもの（設計書 §8）: 部門長・審査担当者・今年度の開始番号 ／ 社長の交代で社長決裁待ちを移す ／
  基幹の左メニューの「決裁」と対応待ちの件数・ベルマーク ／ 印に使う文字（段階4）

### 検証

- 全テスト **2068 tests / 14499 assertions green**（2026-09-18・Task 15 の後）
- コンパイル済みビュー **277 本**を `php -l` → INVALID 0 件（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）
- **Task 15（変異テスト本体・2026-09-18）**: 最終コミット（`4528a17f`）で **91 通りを全件で流し、すべて検出**
  （表の 74 通り ＋ 隣の不変条件 1 ＋ 宿題で足したテスト用 16）。記録は実装計画の末尾の「Task 15 の実測記録」。
  ⚠ **1 回目の測定で 3 件が全件緑だった**（テストを足して塞いだ）: ①確定時の `rowErrors` の差し戻しを消す —
  1 行だけの改ざんでは到達しないはずの `validCount === 0` の歯止めが別の文言で代わりに断っていた ②桁数の注意を
  全エラー判定の前へ動かす — エラーと注意が同じ行で成り立つデータが無かった ③ロックアウト時のセッションの破棄だけを消す
  — セッションの中身を見るテストが無かった。
  併せて Task 12 / 13 の宿題 2 件も片づけた: **門番の全件分類**（`ApprovalAdminGateTest`。`approvals.admin.` の全ルート ×
  権限の無い 6 人 × 実在する / しない ID で 403・門番の文言・解決後のミドルウェアの位置）と
  **1 回限りの鍵の受け取りの 1 本化**（`OneTimeAction::claimFrom()`・`claim()` は private・案内を描く入口の
  全件分類は Reflection で `app/` の全メソッドを切り出す）。
  ⚠ 入口の分類は最初、手書きのトークン解析で書かれ、**今の `app/` ですでに無音で壊れていた**
  （`X::class` をクラス宣言と誤認して 320 本が偽の名前・71 本が消える）。レビューが見つけて Reflection に直した
- 変異テストは各タスクで実測（**記録は実装計画**。末尾に「Task 12 の実測記録」「Task 14 の実測記録」）。
  サイドバー（Task 14）は最終コードに対して **17 通り ＋ カナリア 1** を全件（2050 本）で流し、
  **検出 17 / 未検出 0 / 等価変異 0**。⚠ ただし**テストを書いている最中は 4 つの穴が緑のまま通っていた**:
  ①**3 か所のうち 1 か所だけ消す**（6 通り）— ページ全体を 1 回見る形ではドロワーの塊を丸ごと消しても緑（Bug #41）
  ②**ラベルの改名**（「決裁のホームZZZ」）— 素の部分一致は素通り（Bug #43）
  ③**管理リンクをページ全体で見る** — 決裁のホームの本文自身が同じ 2 本のリンクを持つので、
  サイドバーが 1 本も出さなくても緑（Bug #46）④**②の対策が見出し 2 つにしか入っていなかった** —
  「利用者の管理」「部門の管理」は `route()` の一致だけで、改名しても緑（2026-09-17 のレビューで発覚・`3bb393ce`。
  **テストだけを 1 つ前へ戻して当て直し、旧テストでは緑・今のテストでは赤**まで確かめた）
- **2026-09-18 のコード品質レビュー**で Major 3 件・Minor 2 件を直した（記録は実装計画の
  「Task 14 コード品質レビューの実測記録」）。**実装の欠陥は 1 件**（決裁のドロワーに閉じるボタンが無く、
  閉じる手段がオーバーレイのタップだけだった）で、残りは**テスト設計の穴**:
  ①**どちらのサイドバーが出たかを誰も見ていない** — 決裁のみ利用者の**管理者**にだけ基幹サイドバーを出す変異が
  全件緑（基幹側も「決裁の管理」グループで同じ 2 本を出すため。その人には `/approvals` へ戻るリンクが無くなる）
  ②**オーバーレイと閉じるボタンを見るテストがアプリ全体で 0 件** — どちらを消しても全件緑
  ③**決裁の権限の「3 状態目」**（行は在るが `is_admin` が false ＝ 全件閲覧者）が一度も `actingAs` されていない —
  門番の判定を「行の有無」に取り違える変異が全件緑 ＝ **全件閲覧者が利用者管理・部門管理を操作できる権限昇格の形**
  ④`assertHasLink()` が href と文字を独立に見ており、**リンク 2 本の行き先を入れ替えても緑**。
  修正後に **5 通り ＋ 反例 1 を実測し検出 5 / 未検出 0**（落ちたテストの集合と理由の文言まで照合）
- ⚠ **ドロワーの閉じるボタンは「全件分類」のテストで守る**（`LayoutSidebarDrawerTest`）。
  `partials/sidebar*.blade.php` を機械的に走査するので**3 本目の partial が無検査のまま増えない**。
  ⚠ この分類は**初回の実行でいきなり `sidebar_*_snippet.blade.php` 3 本を拾った**（過去のセッションが
  人間向けに残した作業手順で、どこからも `@include` されていない死にファイル）。削除は別タスク
- **Task 16（ローカルの実ブラウザ確認・2026-09-19）**: 表の 16 項目のうち**期待どおり 14（うち 1 つは設計書 D9 に合わせて読み替え）・期待と違った 1・未了 1**
  （Safari の印刷。自動操作できないので利用者の手で）。決裁のドロワーの × とオーバーレイのタップ（375px）・
  サイドバー 3 か所・案内の印刷（Chrome の `--print-to-pdf` で 3 人 → A4 3 ページ・全ページに QR・日時と URL なし。
  余白 0 を外した対照には日時と URL が出る）・再送信の断り・6 画面 × 3 幅の横スクロール 0 まで実測。
  ⚠ **欠陥を 8 件見つけ、どれも未修正・判断待ち**（記録は実装計画の末尾の「Task 16 の実測記録」）。
  重いのは 2 件: **F1** 決裁のみの人がパスワードを変えると決裁のホームに「決裁以外の画面は使えません。」が出て
  「パスワードを変更しました。」が消える（初回ログインで全員が踏む。`PasswordController` が `/dashboard` へ戻すため）／
  **F2** CSV 取込の案内の「元の画面へ戻る」が 405（戻り先がリファラー＝ POST 専用の確認画面の URL）。
  残りは見た目と文言（略称の縦の折り返し・ドメインの注意文の置き場所・発行日と最終ログインが UTC・
  新規登録の帯の「送る 0 人／送らない 0 人」・検索を押す前の絞り込みでのまとめて再発行）

### 後続（`approval-followups`。`approval-phase1` から分岐・2026-09-18）

コード品質レビューで**範囲外として切り出した 2 件**。⚠ **どちらも `13.x` 単独では実装できない**
（`homeRouteName()` も門番も `LayoutSidebarDrawerTest` も段階1 で入るもの）ので、
`approval-phase1` の上に積んで**その後ろにマージする**。

| # | 内容 |
|---|---|
| 1 | **パンくずの「ホーム」を利用者ごとの行き先にする**（`layouts/app.blade.php`）。`route('dashboard')` 固定だったので、決裁のみ利用者が押すと門番に跳ね返され**行き先は正しいのに「決裁以外の画面は使えません。」が毎回出て**いた（決裁 4 画面すべて）。`/dashboard` 自身が `User::homeRouteName()` へ転送するだけなので**基幹を使う人の行き先は不変**（転送が 1 回減るだけ）。⚠ 門番の `ALLOWED_NAMES` を広げる案は採らない |
| 2 | **サイドバーの死にファイル 3 本を削除**（`sidebar_{buyer,contract,housing}_snippet.blade.php`）。中身は Blade ではなく過去のセッションが人間向けに残した作業手順で、どこからも `@include` されていなかった。`resources/views/` なので `deploy.sh` が本番へ配り、Tailwind の走査対象にも入っていた |

- 着手前の実測: **パンくずの `/dashboard` を見ている既存テストは 0 件**、`app.blade.php` の
  `route('dashboard')` は**ちょうど 1 箇所**
- 削除の前後で `vite build` した CSS は**ハッシュまで同一**（`app-wzJ6Tjji.css` / 51,168 bytes）
  ＝ スニペットが持っていた Tailwind クラスはすべて実サイドバーでも使われていた
- 全テスト **2061 tests / 13575 assertions green** ／ コンパイル済みビュー **274 本**を `php -l` → INVALID 0 件
- **変異 5 通りすべて RED**（検出 5 / 未検出 0。落ちたテストの集合と理由の文言まで照合）:

| # | 変異 | 落ちたテストと理由 |
|---|---|---|
| F01 | パンくずを `route('dashboard')` に戻す（元の不具合）| **4 本**。`..._goes_to_the_approval_home`（`approvals.home を指していない`）／ `test_following_the_rendered_link_does_not_warn`（`Expected [200] but received 302.` ＝ **門番に跳ね返されている**）／ 経営層・それ以外の 2 本も行き先違いで落ちる |
| F02 | パンくずを `route('dashboard.tenant')` 固定に | **3 本**（決裁のみ利用者 2 本 ＋ 経営層）|
| F03 | 新しい `sidebar_dummy.blade.php` を置く | `test_every_sidebar_partial_is_classified`（`…が分類されていない`）＝ **無検査の partial が増えない** |
| F04 | `NOT_A_SIDEBAR` に実在しない名前を足す | 同上（`分類に書いた … が実在しない`）＝ 逆向きも効く |
| F05 | `NOT_A_SIDEBAR` に**本物のサイドバー**を逃がす | **2 本**（`両方のリストに入っている` ＋ `app.blade.php が … を読み込んでいる`）＝ **分類が逃げ道になっていない** |

⚠ **書いた直後の見直しで 1 本直した** — リンクを辿るはずのテストが `approvals.home` を**手で叩いて**
おり、画面のリンクが壊れても緑のままだった（Bug #47）。**描画された href を取り出してそのまま GET する**
往復に直した（`aa5783cc`）。F01 の `302` はその往復が拾っている。

⚠ **本番側に残る死にファイル 3 本は rsync では消えない**（`deploy.sh` は `public/build/` 以外に
`--delete` を付けない）。本番の掃除が要るかは利用者に確認する。

⚠ **実ブラウザでの目視は未了**（決裁のパンくずを実際に押して警告が出ないこと ／
基幹の画面のパンくずが従来どおり動くこと）。Task 16 でまとめて行う。
→ ✅ **2026-09-24 に確認**（下の「F1〜F8 の修正」のブラウザ確認）。決裁のみの人がパンくずの「ホーム」を押すと
`/approvals` に着き警告なし ／ 経営層は `/dashboard/executive` に着く。

### F1〜F8 の修正（2026-09-24）

計画と実測記録: @docs/superpowers/plans/2026-09-24-approval-phase1-fixes.md

利用者の判断（2026-09-24）は「直してから本番へ出す」。案が 2 つあった F4・F7・F8 は推薦どおりに決めた。
直す前に `approval-followups`（上の節）と `13.x`（日本時間化・月末の溢れ・日付ピッカー）を**マージで**取り込んだ
（リベースしない＝記録のコミット番号を保つ）。**ルート・DB（段階1 の SQL 以外）・依存の変更は無し。**

| # | 直し方 |
|---|---|
| F1 | パスワードを変えたあとは、その人のホームへ直接戻す（`route($user->homeRouteName())`）。同じ形の「キャンセル」の代わりの行き先もその人のホーム。門番は「ホームの別名」（`/`・`/dashboard`。ログイン中の `/login` を含む）だけ、警告なしで決裁のホームへ送る（通すのではない。Bug #63）|
| F2 | 案内の「元の画面へ戻る」は入口が決めて渡す（GET の画面から送る 4 入口はリファラー＝絞り込みが残る・CSV の確定は取込の画面）。CSV 取込の確認画面から送り直して断られたときも取込の画面へ（レビューの Important 1）。「キャンセル」はリファラーでなく直前の GET の画面へ（Bug #64）|
| F3 | 部門の管理の略称とアルファベットのセルに `whitespace-nowrap` |
| F4 | ドメインの行には中立な人数だけ。人数と「届かなくなります」は削除の確認へ（0 人なら質問だけ）|
| F5 | 案内の発行日は `JapanTime::today()`（13.x の Bug #61 の部品）|
| F6 | 最終ログインは `JapanTime::format()` |
| F7 | 通知メールの件数の帯は再発行の案内にだけ出す |
| F8 | 絞り込みのプルダウンとチェックは変えた瞬間に送る。ブラウザの「戻る」で戻ったときは `pageshow` で絞り込みのフォームを `reset()` する（レビューの Minor 6 を実ブラウザで再現して直した。Bug #65）|

- 13.x の走査テスト（Bug #61）が決裁のコードで拾った残り 3 か所（再発行の瞬間・1 回限りの鍵の期限・削除の日時の記録）は、
  理由をつけて分類した。再発行の通知メールの日本時間の整形も `JapanTime::format()` へ寄せた。
  `StoredTimestampDisplayScanTest::MIN_FORMAT_CALLS` は 24 → **26**
- 全件テスト **2068 → 2150 tests / 14379 assertions green**（着手前 → `695dbc78`）
- **独立レビュー 1 回**（Important 1 件・Minor 6 件。すべて対応）。Important 1 は F2 と同じ形の取りこぼし
  （CSV 取込の確認画面から送り直して断られると 405）で、通常の操作で踏むものだった
- **変異 48 通り**（本体 41・「戻る」の修正 7。ほかにカナリア 2 回）で、すべて狙ったテストが狙った文言で落ちた（緑 0 件）。
  ⚠ M24g（利用者の管理の script を実行されない型にする）は、まとめて再発行の確認と編集のモーダルも止める変異だが、
  止めるのは今回足した「戻る」のテストだけだった
- **ローカルの実ブラウザ 12 項目＋戻る操作**（Playwright・画面が見えている状態）がすべて期待どおり。
  ⚠ 「戻る」は、Playwright の Chromium でもアプリ内のブラウザでも bfcache を使わず、**読み込み直して入力欄の値を戻す**経路だった。
  レビューが挙げた「`event.persisted` のときに戻す」ではこの経路が直らない
- コンパイル済みビュー **274 本 / INVALID 0 件**
- 範囲外（計画書に記録）: 基幹の取込（テナント・賃貸マンションなど 6 本）にも同じ 405 の形がありうる（未確認・別の作業として提案）→ **2026-09-24 に直した**（下の「CSV 取込の確認画面から断られると 405 になる件」の節。4 本で再現）／
  ログイン案内は 375px で横にはみ出す（A4 の用紙をそのまま出す印刷用の作り。以前から）

### 本番反映の手順（設計書 §7）

**DB が先・`deploy.sh` が後。** 新しい列をログインが読むので、コードを先に送るとログインが 500 になる。

1. **反映前の読み取り**（利用者の承認をいただいてから）: 本番の `users` の定義（索引名・照合順序・`role` の enum・`email` の型）
2. `database/sql/2026-09-16-approval-phase1.sql` を流す（`users` の ALTER ＋ 7 表の作成 ＋ 会社と社長の設定行）
3. main repo で `composer install --no-dev`（**chillerlan/php-qrcode が新規**）→ **main repo の cwd で** `composer dump-autoload`
   （⚠ worktree から実行すると autoloader に worktree パスが焼き込まれる）
4. `./deploy.sh`
5. 本番で確かめる: **コンパイル済みビューの `php -l`**（Bug #21 / #26）／ ログイン画面 ／ メールアドレスでログインできること ／
   基幹の利用者管理が開けること ／ **決裁の画面が誰にも見えないこと**（管理者を指定する前）
6. ⚠ **社員の一括登録とログイン案内の配布は、稼働の直前まで行わない**（D2・要件定義書 16.2 の 4）

### 本番反映（2026-09-25 実施）

利用者の承認（①本番の読み取り ②DB の変更 ③`13.x` の早送り〜`./deploy.sh` ④本番の死にファイル 3 本の削除）のあと、
上の手順どおり **DB が先・`deploy.sh` が後**で流した。直前に `approval-phase1`（`6e9b59fc`）で全件テスト
**OK (2150 tests, 14379 assertions)**。`13.x` = **`6e9b59fc`**（早送り）。本番のデータは ② のほかは変えていない。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 反映前の読み取り | `users` の定義 | `employee_number` なし ／ `role` は `executive`・`manager`・`staff` ／ `email` は `varchar(255) NOT NULL`・一意の索引 `users_email_unique` ／ `utf8mb4_unicode_ci` ＝ SQL の前提どおり |
| | 表とデータ | 決裁の 7 表なし ／ `cache`・`cache_locks`・`sessions`・`jobs` あり ／ 利用者 6 人（うち削除済み 3）／ MySQL 8.0.40 |
| | 死にファイル | 3 本とも在った（日付 2026-05-01）|
| ② DB | 12 文を 1 文ずつ | `OK 1`〜`OK 12`（すでに流した形跡があれば 1 文も流さずに止まる安全装置つき）|
| | 流した後の読み取り | `employee_number varchar(20)` NULL 可・一意の索引 `users_employee_number_unique` ／ `email` が NULL 可（一意の索引は残る）／ `role` に `approval_only` ／ 7 表あり ／ 会社 3 行（ミツワ都市開発 5 月・DAD 6 月・ZEAL 6 月）／ 設定 1 行（社長は未指定）／ 利用者 6 人のまま |
| ④ 死にファイル | `rm` の後 | `layouts/partials/` に残るのは `header`・`sidebar` の 2 本（`sidebar_approval` は ③ で届いた）|
| ③ 依存 | `composer install --no-dev` | `chillerlan/php-qrcode 6.0.1`・`php-settings-container` を新規 ／ `vendor/bin/phpunit` なし ／ `composer.lock` 不変 |
| | `composer dump-autoload --no-dev --optimize`（main repo の cwd）| `$baseDir = dirname($vendorDir)` ／ 新しいクラス 7 つが classmap に載った |
| | `./deploy.sh` | exit 0・6 段すべて・3 つのキャッシュとも成功。アプリ 81 件・`vendor/chillerlan` 92 件を送り、CSS が変わったので旧バンドル `app-SuDexAlR.css` を掃除（新しいのは `app-wzJ6Tjji.css`）|
| 反映後の読み取り | コンパイル済みビューの `php -l` | **274 本 / INVALID 0 件** |
| | クラスと設定 | `LoginId`・`InitialPassword`・`LoginQrCode`・`OneTimeAction`・`chillerlan\QRCode\QRCode` すべて読める ／ `auth.login_throttle` は既定値（1 分あたりログイン ID ごと 5 回・IP ごと 30 回）|
| | ルート | `approvals.` が **19 本**（`--json` で数えた。手元と名前まで一致）|
| ログイン画面（未ログイン・`curl`）| | 200 ／「社員番号またはメールアドレス」 |
| ログイン済みの実 Chrome（経営層・フォームは送らない）| 基幹の利用者管理 | 開く ／ 先頭の列が「社員番号」／ 3 行（削除済みを除く）|
| | `/approvals/admin/users`・`/approvals/admin/organization` | どちらも **403**「この画面を使う権限がありません。」|
| | `/approvals` | 「決裁の機能は準備中です。…」|
| | サイドバー | 3 か所（展開・折りたたみ・ドロワー）とも「決裁」なし ＝ **決裁の画面は誰にも見えない**（管理者の指定の前）|
| | 基幹の画面 | 25 画面（ダッシュボード 2・各部署の一覧・工程表ボード 2・顧客一覧 3・取込・パスワード変更など）がすべて 200・門番の警告なし ／ パンくずの「ホーム」は `/dashboard/executive` |
| | コンソール ／ 横スクロール | 3 画面を読み込み直してエラー 0 件 ／ `main` の横スクロール 0 |
| | ログインのセッション | この Chrome のログインは反映の後も切れなかった（`AuthenticateSession` を足した後も）|

⚠ **計画と違ったこと 2 つ**（どちらも反映を止める理由ではなかった）:

1. **本番のキャッシュは `file`**（セッションも `file`）。計画（段階1 の Task 17 と、F1〜F8 の計画の Task 16）は `database` を前提に
   「`cache` 表が無いと 1 回限りの鍵とログイン制限が止まる」と書いていた。`Illuminate\Cache\FileStore::add()` はファイルの排他ロックを
   取ってから書くので、**1 回限りの鍵は `file` でも二重に通らない**（本番は 1 台）。ログイン制限も以前から同じ `file` で動いていた。
   `OneTimeAction` のコメントの「本番の database ドライバは」を直した（`e5d59dc1`。コメントだけなので本番へは次のデプロイで）
2. **`route:list --name=approvals. | grep -c` は 15 と数える**（計画の期待は 19）。テキストの出力は長い行で名前を省略するので、
   手元でも同じく 15。`--json` で数えると本番・手元とも 19 で、名前まで一致した。**ルートの本数は `--json` で数える**

⚠ `origin/13.x` への push はしていない。→ 2026-09-25 に push 済み（`9fffc965` に含まれる）。

### ⚠ 未了

- ~~Task 16 で見つけた欠陥 8 件（F1〜F8）の扱い~~ → **2026-09-24 に直した**（上の「F1〜F8 の修正」）
- **Safari での案内の印刷**（1 人 1 ページ・全ページに QR・「ヘッダとフッタをプリント」を入れても余白に日時と URL が出ないか）。
  ⚠ Chrome だけで `<use>` の対応を判断しない。稼働の前でよい
- **本番でメールアドレスでログインできること**（利用者にお願いする。こちらはパスワードを入力しない）
- ~~本番の実ブラウザ確認~~ → **2026-09-25 に実施**（上の「本番反映」。ログイン済みの実 Chrome・フォームは送らない）
- ~~本番反映そのもの~~ → **2026-09-25 に実施**

---

## 🚧 決裁申請 段階2（決裁の本体）— 2a・2b 本番反映済み・使い始める前

要件定義書: @docs/決裁申請_要件定義書_v1.md（16.1 の段階2）
設計書: @docs/superpowers/specs/2026-09-25-approval-phase2-design.md（設計の 4 節は 2026-09-25〜26 に利用者が 1 節ずつ承認）
実装計画（2a）: @docs/superpowers/plans/2026-09-26-approval-phase2a.md（Task 0〜21）。2b の計画は下の「2b」の節

2026-09-25 に利用者の指示で、「社内決裁申請」の会話が設計を始めた。**二重作業を防ぐため、ほかの会話は段階2 の設計・
実装計画・実装に手を付けない**（2026-09-16 に段階1 の設計を 2 つの会話で二重に作り、決め直しになったことがある）。
実装をどの会話で行うかは、設計書ができてから利用者が決める。

- 作り方: 2 回に分ける（**2a** 申請を出して社長の決裁まで一通り回る ／ **2b** 出し直しの変更点と履歴・進行中の申請の管理・基幹のメニューの件数）。
  どちらもできたら本番へ出すが、**申請を回す画面は使い始める日まで誰にも見せない**（`approval_settings.launched_at` が空のあいだ。D1）
- 要件定義書からの変更（v1.9 に反映する）: 社長決裁の付け替えは作らない（D2）・社長は申請できない（D4）ほか。設計書の §3

### 2a（申請して社長の決裁まで一通り回る）

実装計画: @docs/superpowers/plans/2026-09-26-approval-phase2a.md（Task 0〜21）。worktree `.claude/worktrees/approval-phase2a`（ブランチ `approval-phase2a`）。

- 計画で決めた設計書からの細部（計画 §0.8）: `completed_at` → **`finished_at`**（走査テストの予約語）／提出の別ルートは作らず保存のフォームに `intent=submit` ／添付の消し方は `round` で分ける（計画 §0.5）
- 本番反映の手順（設計書 §7）: **DB が先・`deploy.sh` が後**（新しいコードが `approval_departments.head_user_id` と `approval_settings.launched_at` を読む）。本番の `launched_at` は空のまま（使い始めるのは段階6）
- 実装と点検のあいだに決めたこと: 差戻し中は、申請者以外には最後に提出した中身を見せる（利用者の決定 D26。設計書 §2）。計画から変えたことは計画 §0.12、受け入れた隙間は計画 §0.13
- Task 19 で利用者が画面の写真のページで 13 件を決めた（2026-09-28。C1〜C12 と、D26 を要件定義書に書き足す時期。設計書のあてはまる節に書いた）

#### 本番反映（2026-09-28 実施）

利用者の承認のあと、計画の Task 21 の手順どおりに流した（本番を変える 2 つの段＝DB と `./deploy.sh` の前に、もう一度ずつ承認を取った）。直前に worktree で `13.x`（`9e2df5b8`）を取り込み（`b28881d8`・rebase はしない）、全件 **OK (2765 tests, 19311 assertions)**。`13.x` = **`b28881d8`**（`9e2df5b8` から早送り）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | 表の形・9 表・会社の月・MySQL | `approval_departments` に `code` あり・`head_user_id` なし ／ `approval_settings` に `president_user_id` あり・`launched_at` なし ／ どちらも InnoDB・`utf8mb4_unicode_ci` ／ 9 表とも「ない」／ 会社の期の始まりの月はミツワ 5・DAD 6・ZEAL 6（正しい）／ 部門 0・設定 1 ／ MySQL 8.0.40 |
| ② 早送り | `13.x` | `9e2df5b8` → `b28881d8`・作業ツリーは空・main repo に `vendor/bin/phpunit` は無い |
| ③ DB を先に | SQL 11 文（md5 が手元と本番で一致） | 流した形跡なし → **OK 1〜11**（MySQL 8.0.40 でも通った）／ 流したあと: 部門長・使い始めた日時の列と索引・外部キーが SQL どおり・9 表とも 0 行・部門と設定の行数は同じ |
| ④ 読み込みの表 | `composer dump-autoload --no-dev --optimize`（main repo） | 6,695 クラス・`git status` は空 |
| ⑤ `./deploy.sh` | 2026-09-28 18:00:38〜18:00:47（日本時間） | exit 0・6 段すべて。アプリのファイル 62 本とビルド。CSS の名前が変わり、旧 CSS `app-D-wd4D2y.css` を 2 か所で削除 |
| ⑥ 本番の読み取り | 見本の 6 本（`Workflow`・`bootstrap/app.php`・`routes/approval.php`・詳細のビュー・`AttachmentDelivery`・classmap） | 手元と **md5 が一致** |
| | コンパイル済みビューの `php -l` | **282 本 / INVALID 0 件** |
| | ルートと設定 | `approvals.` のルート **39 本**・`launched_at=NULL`・新しいクラス 4 つとも読める・`laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件・`bootstrap/cache` は 600／700 |
| | ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 決裁のホームは「決裁の機能は準備中です。」のまま ／ `/approvals/requests/create` はホーム（準備中）へ戻る ／ サイドバーに「新しい申請」「自分の申請」は無い ／ 決裁の管理者でない利用者の `/approvals/admin/types` は 403 ／ 基幹のテナントダッシュボードは開き、`main` のはみ出し 0・読み込み直したときのコンソールのエラー 0 |

⚠ 申請を回す画面は、使い始める日（段階6）まで誰にも見えない（`launched_at` は空）。決裁の管理者は、部門長・審査担当者・今年度の開始番号・申請の種類を先に登録できる（開始番号は会社の期の始まりの月を確かめたあとに入れる。今の本番の月は正しい）。

⚠ `origin/13.x` への push はしていない。→ 2026-09-28 に push 済み（`dfdf95c4` に含まれる）。

### 2b（出し直しの変更点と履歴・進行中の申請の管理・基幹のメニューの件数）

実装計画: @docs/superpowers/plans/2026-09-28-approval-phase2b.md（Task 0〜11）。worktree `.claude/worktrees/approval-phase2b`（ブランチ `approval-phase2b`）。

- 表の変更なし（2a の表に 2b の列がある）。本番反映は `composer dump-autoload` と `./deploy.sh` だけ
- 計画で決めた細部: 取り消しの探し方と戻し方（計画 §0.4）・取り消された判断をした人も見続けられる（§0.5）・管理者の操作は詳細に置き、⑩ は「進行中」「決裁済み・否決」の 2 つのタブ（§0.8）・断られた小窓は送り先で決めて開き直す（§0.9。2a の m-6）・2a の軽微 m-2・m-6・m-7・保存の版・N-1 を片付けた（§0.11）
- 点検の手直しで計画から変えたこと: 取り消しの後ろの段階を主キーだけで書く（MySQL のデッドロック）・関連する決裁No の候補で取り下げも控えの件名にする（D26）・各 Task のテストの穴を変異で見つけて塞いだ（計画の Task 8 の実測記録）
- 使い始める前は、基幹のメニューとダッシュボードに何も足さない（件数とカードは `launched_at` が入ってから）
- 後回し（別の機会にまとめて直す）: 小窓をキーボードで開いたとき、フォーカスが小窓へ移らない・Esc で閉じない・`role="dialog"` が無い。2a の判断・取り下げの小窓と 2b の管理者の小窓がどれも同じ既存の作りなので、2b だけ直さず、決裁の小窓をまとめて直す（計画の Task 9 の実測記録の B6）
- 後回し（別の機会にまとめて直す）: ページ番号を全部並べるほかの一覧（`getUrlRange(1, …->lastPage())` の 31 か所。基幹の 29 か所と、決裁の「自分の申請」「利用者の管理」）も、スマホで多ページのときはみ出す（同じ既存の作り）。2b では ⑩ の「決裁済み・否決」だけを、今のページの前後だけにした（計画の Task 9 の実測記録の B1・利用者の決定 C5） → **2026-10-13 に 31 本とも部品 `<x-pager>` へ置き換えた**（下の「一覧のページ送りを部品 `<x-pager>` にそろえる」の節。Bug #117）

#### 本番反映（2026-09-30 実施）

利用者の了承のあと、計画の Task 11 の手順どおりに流した（本番の読み取り・`./deploy.sh`・反映のあとの確かめのそれぞれで了承を取った）。`13.x` は計画の親 `071f028c` のあと別の会話の 22 コミット（取込の二重送信を止める改修など。`134523fb`）進んでいたので、worktree で取り込み（`3d18815d`・rebase はしない）、全件 **OK (2965 tests, 21303 assertions)**（2b の 2892 本＋`13.x` で増えた 73 本）。`13.x` = **`3d18815d`**（`134523fb` から早送り）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | ルート・設定・表の行数・記録・ファイル | `approvals.` のルート 39 本・設定 1 行で `launched_at=NULL`・申請／段階／記録／控えの 4 表とも 0 行・部門 2・種類 0 ／ `laravel.log` は 6/18 から更新なし ／ 本番のアプリのファイル（`app`・`config`・`database`・`lang`・`resources`・`routes` など）が `134523fb` と **md5 まで全部一致**（本番だけにあるのは `.DS_Store` 27 個と、git で消した 2 本〈`LoginRequest.php`・`inquiries` の重複マイグレーション〉。`rsync` が消さない以前からの残り） |
| ② 取り込みと早送り | worktree で `git merge 13.x` → 全件 → main repo で `--ff-only` | 衝突なし（`CLAUDE.md`・`docs/BACKLOG.md` は自動で併合し、両側の変更が残る）・全件 OK (2965 tests, 21303 assertions)・`134523fb` → `3d18815d`・作業ツリーは空 |
| ③ 読み込みの表 | `composer dump-autoload --no-dev --optimize`（main repo） | 6,701 クラス（2a の 6,695＋新しい 6）・`vendor/bin/phpunit` は無い・`git status` は空 |
| ④ `./deploy.sh` | 2026-09-30 10:52:05〜10:52:15（日本時間） | exit 0・6 段すべて。アプリのファイル 34 本（新 11・変更 23・削除 0）とビルドと読み込みの表 2 本。CSS の名前が変わり、旧 CSS `app-DW1DvPK7.css` を 2 か所で削除（ビルドの警告 1 件は `resources/css/app.css` の `@import` の位置で、以前から出ているもの） |
| ⑤ 本番の読み取り | ファイル | 手元の `3d18815d` の 685 本が **md5 まで全部一致** |
| | コンパイル済みビューの `php -l` | **288 本 / INVALID 0 件**（2b の新しいビュー 5 本の分増えた） |
| | ルートと設定 | `approvals.` のルート **43 本**・`launched_at=NULL`・新しいクラス 6 つとも読める・4 表とも 0 行のまま・`laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件・`bootstrap/cache` は 600／700 |
| | ログインした画面（利用者の Chrome・見るだけ・フォームは送らない。利用者は決裁の管理者） | 経営・テナントのダッシュボードに決裁のカードが無い ／ サイドバーに「決裁」の項目も件数も「進行中の申請の管理」も無く、「決裁の管理」は利用者・部門・申請種類の 3 つ ／ 決裁のホームは「決裁の機能は準備中です。」のまま ／ `/approvals/admin/requests` はホーム（準備中）へ送られる ／ 部門の管理と申請種類の管理が開く ／ `main` のはみ出し 0・コンソールのエラー 0 |

⚠ `/approvals/admin/requests` を最初に開いたときだけ、送られた先のホームの表示が途中の中継（プロキシ）の「Inactivity Timeout」（データが届かない）になった。2 回目と 3 回目は 1.4〜1.5 秒で正しく出て、`laravel.log` も増えていない（一時的なものの可能性が高い）。

✅ ① で見つけた本番だけにある残りは、2026-10-02 夜に片付けた（利用者の了承のあと読み取りで中身を確かめ、消すのは利用者がターミナルで流した）。
`LoginRequest.php` と `inquiries` の重複マイグレーション（`0001_01_01_000013_create_inquiries_table.php`）は、git で消す直前の版と md5 が一致し、本番のコードとクラスの一覧のどこからも参照が無かった → md5 が一致するときだけ消し、空になった `app/Http/Requests/Auth` も外した。
`.DS_Store` は **31 個**（27 個は `app` などのコードの置き場だけの数。`bootstrap`・`public` の 3 個と公開フォルダの `images` の 1 個を足した。どれも 3〜4 月の日付）→ 残り 0。
消したあと: `approvals.` のルート 50 本・`laravel.log` は 6/18 のまま。`migrations` 表の `0001_01_01_000013_create_inquiries_table` の行（batch 5）は残した（本番は `migrate` を使わず SQL で表を作る）。

⚠ 使い始める前なので、利用者に見える変化はほぼ無い（変わるのは、申請種類の一覧の「審査担当者がいません」が無効の人を数えなくなることだけ）。⑩・件数・カード・詳細の管理者の操作は `launched_at` が入ってから出る。

⚠ `origin/13.x` への push はしていない。→ 2026-09-30 に push 済み（`a9732184` に含まれる）。

---

## 🚧 決裁申請 段階3（通知）— 3a・3b 本番反映済み・使い始める前

要件定義書: @docs/決裁申請_要件定義書_v1.md（v1.11。8 章・13 章の ⑥⑫・15.3・15.5）
設計書: @docs/superpowers/specs/2026-09-30-approval-phase3-design.md（設計の 5 節は 2026-09-30 に利用者が 1 節ずつ承認）

- 作り方: 2 回に分ける（**3a** 操作のたびのお知らせとメール・ベル・お知らせ一覧・ホームのお知らせ・送れないときの表示 ／ **3b** 毎朝の催促・祝日・催促の設定 ⑫）。
  どちらも使い始めるまで何も見えず何も届かない（`approval_settings.launched_at` が空のあいだ）

### 3a（操作のたびのお知らせとメール・ベル・⑥・ホームのお知らせ・送れないときの帯）

実装計画: @docs/superpowers/plans/2026-09-30-approval-phase3a.md（Task 0〜12）。worktree `.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`）。

- 表: `notifications`（新）と `approval_settings` の 3 列。本番反映は **DB が先・`./deploy.sh` が後**（新しいコードが `approval_settings.mail_last_*` を読む）
- 計画で決めた細部（計画 §0.10）: お知らせの表に申請の番号の列（`approval_request_id`）を足した・対応の要らない知らせの文を「対応は要りません（お知らせです）」にそろえた・⑥ のページ送りは ⑩ と同じ部品・取り消しで番が戻った人への文
- 実装中に決めたこと（計画の末尾）: 審査担当者の追加のデッドロック（1213）を MySQL で再現して順序を直した・帯の文を「決裁のお知らせは画面にも届いています。」に（利用者の決定）・変異 76 通りは等価 1 を除き検出
- 既存のテストで意味が変わったもの 2 本（計画 §0.9）: 部門長のホームの見る範囲（「新しいお知らせ」に提出のときの知らせが残る）・再発行のメールの送り直し（1 回 → 3 回。D6）
- 受け入れた隙間（計画 §0.11）: 社長の交代・審査担当者の追加は待っている申請の版を進めない・外れた審査担当者の未読は残る（押すと ⑥ に戻して知らせる）ほか
- 後回し（最後の点検の Minor）: `MailDelivery::recordSent()` が握りつぶした失敗は `Log::warning` なので、本番（`LOG_LEVEL=error`）の `laravel.log` に残らない。メールは送れていて、影響は帯が消えずに残るだけ。後で `Log::error` に上げる候補（1 語とテスト 2 行）→ 2026-10-02 に `Log::error` へ上げた（`MailFailureBannerTest` が error 1 回・warning 0 回を見る。本番には 2026-10-03 18:13 の ZEAL 会員の直しの反映で一緒に入った・md5 一致）

#### 本番反映（2026-10-02 実施）

利用者の了承のあと、計画の Task 12 の手順どおりに流した（本番の読み取り・`13.x` への早送りと DB・`./deploy.sh`・ログインした画面のそれぞれで了承を取った）。`13.x` は `66a378f2`（テストのメモリの直し）のまま進んでいなかったので、取り込みは早送りだけ。`13.x` = **`832d5dc3`**（`66a378f2` から早送り・27 コミット）。全件は 832d5dc3 で **OK (3213 tests, 22877 assertions)**（2026-10-01）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | ルート・表の形・キュー・表の行数・記録 | `approvals.` のルート 43 本 ／ `approval_settings` に `launched_at` あり・`mail_last_*` なし・InnoDB・`utf8mb4_unicode_ci` ／ `notifications` なし・`jobs` と `failed_jobs` あり ／ `launched_at=NULL` ／ `queue=database`・`queue_db_connection=NULL`（お知らせとメールが操作と一緒に巻き戻る前提） ／ 申請・段階・記録・控えの 4 表とも 0 行・`jobs` 0・`failed_jobs` 0 ／ MySQL 8.0.40 ／ `laravel.log` は 6/18 から更新なし |
| ② 早送り | `13.x` | `66a378f2` → `832d5dc3`・作業ツリーは空 |
| ③ DB を先に | SQL 2 文（`ALTER TABLE approval_settings`・`CREATE TABLE notifications`） | 流した形跡なし → **OK 1・OK 2** ／ 流したあと: 設定の表に 3 列（`launched_at` の後・コメントが日本語で読める）・`notifications` の列・索引 2 つ・外部キー（`approval_requests` へ・`ON DELETE RESTRICT`）が SQL どおり・`notifications` 0 行・設定 1 行のまま |
| ④ 読み込みの表 | `composer dump-autoload --no-dev --optimize`（main repo） | 6,712 クラス（2b の 6,701＋新しい 11）・`vendor/bin/phpunit` は無い・`git status` は空 |
| ⑤ `./deploy.sh` | 2026-10-02 8:57 ごろ終了（日本時間） | exit 0・6 段すべて。アプリのファイル 42 本（新 19・変更 23・削除 0）とビルドと読み込みの表 2 本。CSS の名前が変わり、旧 CSS `app-Cz5Vm3yg.css` を 2 か所で削除（ビルドの警告 1 件は以前から出ている `@import` の位置のもの） |
| ⑥ 本番の読み取り | コンパイル済みビューの `php -l` | **294 本 / INVALID 0 件** |
| | ルートと設定 | `approvals.` のルート **46 本**・`launched_at=NULL`・新しいクラス 8 つとも読める・`MailDelivery::pendingFailure()` は NULL・`notifications` 0 行・`laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ ログイン画面が開く |
| | ログインした画面（利用者の Chrome・見るだけ・フォームは送らない。利用者は決裁の管理者） | 基幹の画面（経営ダッシュボード）と決裁の画面のヘッダーにベルが無い ／ 決裁のホームは「決裁の機能は準備中です。」のままで黄色の帯が無い ／ `/approvals/notices` はホーム（準備中）へ送られる ／ 利用者・部門・申請種類の管理が開き、帯が無い（利用者の管理の黄色の箱は、以前からある編集の小窓の注意書き） ／ `main` のはみ出し 0・コンソールのエラー 0 |

⚠ 使い始める前なので、お知らせも決裁の通知メールも作られず、ベルも出ない（`launched_at` が空のあいだ）。今から変わるのはパスワード再発行のメールだけ（送り直しが 1 回から 3 回に。3 回とも送れなかったときは決裁の管理者のホームと管理の画面に黄色の帯）。

⚠ `origin/13.x` への push はしていない。→ 2026-10-02 に push 済み（`7ae25fa3` に含まれる）。

### 3b（毎朝の催促・祝日の判定・催促の設定 ⑫）

実装計画: @docs/superpowers/plans/2026-10-02-approval-phase3b.md（Task 0〜9）。worktree `.claude/worktrees/approval-phase3`（3a と同じ）。

- 表: `approval_holidays`・`approval_reminder_runs`（新）。本番反映は **DB が先・`./deploy.sh` が後**（新しいコードが 2 表を読む）。新しい部品 `azuyalabs/yasumi`（main repo で `composer install --no-dev` してから `./deploy.sh` が `vendor` ごと送る）
- 毎朝 9:00〜9:04 の 1 回で `approvals:remind`。使い始める前は何も送らない（画面の出力は `storage/logs/approval-reminder.log` に毎朝 1 行）
- 利用者の決定（2026-10-02）: 本番の `APP_URL` を読み取る（`/index.php` が無い → リンクの元は `APP_URL` に `/index.php` を足したもの）・毎年繰り返す送らない日は 1 年より短く・説明は必須・年をまたぐ期間を同じ年で打ったときは直し方を添えて断る・2/29 は毎年の期間にも使える。3b は 2 つの会話が並行して作り始め、この計画の会話が続けた（もう一方の枝 `approval-phase3b`・`approval-phase3b-proto` は残してある）
- 計画で決めた細部（計画 §0.10）: まとめメールの「必要な対応」と件名はホームの対応待ちと同じ・「今日の分を送ったか」は今日の行を入れられるかで決める・送る相手がいない日も 0 人・0 件で記録し、⑫ には「送る相手はいませんでした」と出す（利用者の決定）
- 受け入れた隙間（計画 §0.11）: 無効の人・メールの無い人の対応待ちは催促が誰にも届かない（⑩ で見る）・祝日の法改正は部品の更新が要る ほか
- 実装中に直したこと（計画の末尾の Task 6・7 の記録）: ⑫ で「毎年繰り返す」にチェックがあり日付の形が崩れていると 500 になっていた → 断る文を出す（`78540468`）・最後の点検の手直し 3 つ（まとめメールで件名・申請部門が空の申請をホームと同じ「（件名なし）」「—」で出す `51b18bf4`／⑫ の説明文の文のあいだの空白 `f547bfa2`／祝日の表を作り終えてから覚える `3cb8034b`）・変異 63 通り（C09「昨日の行があっても今朝 9:05 前なら次に送る日は今日」を足し、テストを 1 ケース足して塞いだ）は等価 1（P06）を除き検出
- 後回し（点検の軽微）: まとめメールの 10 件目以降は字下げが 1 字ずれる（テキストメール・読むには支障なし）→ 2026-10-02 に字下げを番号の幅に合わせた（本番には 2026-10-03 18:13 の ZEAL 会員の直しの反映で一緒に入った・md5 一致）／本番の SQL の注意書き「朝の催促も Unknown table で止まる」は使い始めたあとにだけ当たる（正しい順へ導く向きの書き過ぎ）／日付に NUL バイトを入れた手組みの送信は `after_or_equal` の検証で 500（画面からは起きない・フレームワーク側でほかの画面にもありうる）
- 使い始めるとき: 最初の送る日の朝に、`storage/logs/approval-reminder.log` の 1 行と ⑫ の「前回の催促」を読み取りで見る（最後の点検の勧め）

#### 本番反映（2026-10-02 実施）

利用者の了承のあと、計画の Task 9 の手順どおりに流した（本番の読み取り・`13.x` への早送りと DB・部品と `./deploy.sh` と読み取り・ログインした画面のそれぞれで了承を取った）。`13.x` は `91fe7969` のまま進んでいなかったので、取り込みは早送りだけ。`13.x` = **`dde93832`**（`91fe7969` から早送り・15 コミット）。全件は 3cb8034b＋C09 のテストで **OK (3296 tests, 23512 assertions)**（2026-10-02）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | ルート・予定・表・設定・部品・記録 | `approvals.` のルート 46 本 ／ 予定は `ops:backup`（0-4 3）と `queue:work` の 2 つ ／ `approval_holidays`・`approval_reminder_runs` なし ／ `launched_at=NULL` ／ `queue=database`・`queue_db_connection=NULL`（今日の行とまとめメールが一緒に巻き戻る前提） ／ `app.url=https://www.mitsuwat.co.jp/system/manage`（計画を書いたときと同じ） ／ `jobs` 0・`failed_jobs` 0・`notifications` 0 ／ `vendor/azuyalabs` なし ／ `approval-reminder.log` なし ／ `laravel.log` は 6/18 から更新なし |
| ② 早送り | `13.x` | `91fe7969` → `dde93832`・作業ツリーは空 |
| ③ DB を先に | SQL 2 文（`CREATE TABLE approval_holidays`・`CREATE TABLE approval_reminder_runs`） | 流した形跡なし → **OK 1・OK 2** ／ 流したあと: 2 表とも計画の `SHOW CREATE TABLE` と同じ（コメントが日本語で読める・`uq_approval_reminder_runs_sent_on`・InnoDB・`utf8mb4_unicode_ci`）・どちらも 0 行 |
| ④ 部品と読み込みの表 | main repo で `composer install --no-dev` → `composer dump-autoload --no-dev --optimize` | `1 install, 0 updates, 0 removals`（`azuyalabs/yasumi` 2.12.0）・6,902 クラス・`vendor/bin/phpunit` は無い・`git status` は空 |
| ⑤ `./deploy.sh` | 2026-10-02 21:40 ごろ終了（日本時間） | exit 0・6 段すべて。アプリのファイル 24 本・ビルド 3 本・読み込みの表 5 本・`vendor/azuyalabs/yasumi` 294 本（削除 0）。旧 CSS `app-C3HUCZpZ.css` を 2 か所で削除（ビルドの警告 1 件は以前から出ている `@import` の位置のもの） |
| ⑥ 本番の読み取り | コンパイル済みビューの `php -l` | **296 本 / INVALID 0 件** |
| | ルート・予定・催促のコマンド・設定 | `approvals.` のルート **50 本** ／ 予定に `approvals:remind`（0-4 9・バックアップとキュー処理のあいだ） ／ 手で 1 回流した `approvals:remind` は「2026-10-02 使い始める前なので送りません。」 ／ `launched_at=NULL` ／ 新しいクラス 6 つと `Yasumi\Yasumi` が読める ／ 2026-10-12 は「祝日（スポーツの日）」・次に送る日 10/5（月） ／ `mail_link_root=https://www.mitsuwat.co.jp/system/manage/index.php` ／ `approval_reminder_runs` 0 行・`jobs` 0 ／ `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ ログイン画面が開く |
| | ログインした画面（利用者の Chrome・見るだけ・フォームは送らない。利用者は決裁の管理者） | ⑫ が開き「次に催促を送る日 10/5（月） 9 時」「決裁を使い始める前なので、まだ送りません。」「前回の催促 まだありません」・送らない日の表は空・黄色の帯が無い ／ 決裁のホーム（準備中）のリンクの行と、経営ダッシュボードのサイドバーの「決裁の管理」に「催促の設定」 ／ `main` のはみ出し 0・コンソールのメッセージ 0 |

⚠ 使い始める前なので、催促のメールは 1 通も送られない（毎朝 9 時に `storage/logs/approval-reminder.log` へ「使い始める前なので送りません。」が 1 行増えるだけ。翌朝 9:05 以降に読み取りで確かめる＝定期実行が催促を呼んでいることの確かめ → 2026-10-03 に利用者の了承のあと読み取りで確かめた: 書かれたのは 09:00:01・中身は「2026-10-03 使い始める前なので送りません。」の 1 行だけ（土曜でも「使い始める前」の判定が先に来る）・`laravel.log` は 6/18 のまま）。決裁の管理者には ⑫ とその入口が見える（使い始める前から送らない日を登録できる）。

⚠ `origin/13.x` への push はしていない。→ 2026-10-02 に push 済み（`fdfe6aee` に含まれる）。

---

## 🚧 決裁申請 段階4（電子印・PDF・決裁台帳と Excel）— 4a・4b 本番反映済み・使い始める前

要件定義書: @docs/決裁申請_要件定義書_v1.md（v1.13。3.2・9 章・13 章の ③⑤⑦・14.2・14.4・14.5・15.4・15.5・15.7）
設計書: @docs/superpowers/specs/2026-10-03-approval-phase4-design.md（設計の 5 節は 2026-10-03 に利用者が 1 節ずつ承認）

- 作り方: 2 回に分ける（**4a** 電子印・PDF・⑦ の「印に使う文字」・PDF の出力の記録 ／ **4b** 決裁台帳 ⑤・Excel 出力・Excel の出力の記録）。
  どちらも使い始めるまで、申請の画面・PDF・台帳は誰にも見えない（`approval_settings.launched_at` が空のあいだ）。⑦ の「印に使う文字」だけは使い始める前から直せる（D13）
- 金額の明細表・坪数・契約予定日・紙の決裁（取り込んだ過去分）と、それに関わる台帳と PDF の列は**段階5 で足す**（D1）

### 4a（電子印・PDF・⑦ の印に使う文字・PDF の出力の記録）

実装計画: @docs/superpowers/plans/2026-10-03-approval-phase4a.md（Task 0〜10）。worktree `.claude/worktrees/approval-phase3`（段階3 と同じ）。

- 表: `approval_members.stamp_text`（印に使う文字・VARCHAR(4)）・`approval_steps.stamp_label`（VARCHAR(6)）・`approval_steps.stamp_text`（VARCHAR(100)。この 2 列が判断したときの印の控え）。本番の SQL は `database/sql/2026-10-03-approval-phase4a.sql`（ALTER 2 文）。本番反映は **DB が先・`./deploy.sh` が後**（新しいコードが 3 列を読み書きする）。本番の 4 表は 0 行なので、控えを埋め直す SQL は要らない
- 印: 判断を書く 1 か所（`Workflow::finishStep()`）で上段（部門の略称か「社長」）と下段（印に使う文字。空なら氏名の最初の空白より前）を控え、取り消し（`reopenStep()`）で消す。判断した印（申請の詳細・PDF）の表示は `Stamp::forStep()` を通す（今の設定から描かない）。⑦ の見本だけは判断していないので `Stamp::preview()`（上段は最初の所属部門の略称・日付は今日）。描くのはどれも `StampSvg`（同じ部品）。中段は判断の日の和暦（日本時間。R8.10.5）
- 新しい部品: **`mpdf/mpdf` 8.3.1（GPL-2.0-only。利用者の決定 2026-10-03: 社外へ配らない社内のシステムなので mPDF のまま使う）**。`composer.lock` に増えるのは mpdf と `mpdf/psr-http-message-shim`・`mpdf/psr-log-aware-trait`・`setasign/fpdi`・`paragonie/random_compat` の 5 つだけで、既にある部品の版は変わらない。開発用だった `myclabs/deep-copy` が本番用の側へ移るので、本番用（`--no-dev`）の vendor には 6 つ入る。`ext-gd` と `ext-mbstring` が要る
- 同梱のフォント: IPAex ゴシック・明朝 004.01（IPA フォントライセンス v1.0）を `resources/fonts/ipaex/` に置く（ttf 2 本で約 14MB・ライセンスの文書・Readme 2 本）。ライセンスの文書は BOM 付き・CRLF なので、`.gitattributes` の `/resources/fonts/** -text` で配布元のまま保存する（消さない・改行を変えない）
- ⚠ `vendor/mpdf/mpdf/ttfonts`（mPDF が同梱するフォント）が 87MB。`./deploy.sh` は `vendor` ごと送るので、**初回の反映で約 93MB 増えて数分長くなる**（本番の空きは 1.9TB）。`deploy.sh` は変えない（除くと、mPDF が既定の書体を読みに行ったときに壊れる恐れがある）。本番で `composer install` はしない（手元の main repo で入れて `./deploy.sh` が送る）
- PDF の作り方: mPDF は表の 1 行をページの途中で切れず、長い文を 1 つの枠に入れると縮めて 1 ページに押し込むので、本文は罫線の 1 行＝表の 1 行で組む（`PdfSheet::bodyRows()`）。「可・条可・差戻・否」の欄は小さな表で組む（文中の `<span>` の枠は描き損なうことがある）。実測（手元・重い申請＝本文 2 万字・添付 20 件・コメント 2 千字）: Laravel を除く試しで 12 ページ・0.52 秒・ピーク 30MB／Laravel 込みのテストで 20 ページ・0.43 秒・ピーク 68.5MB（PHPUnit の分を含む）。要件 14.5 の 5 秒・128M に収まる
  - 落とし穴 4 つ（点検で見つけて直した。**段階5 で PDF に明細表などを足すときに戻さない**。詳しくは `resources/views/approvals/requests/pdf.blade.php` の先頭の注記と `StampSvg` の説明）:
    - ① 判断の欄の小さな表を、印・コメントと同じセルに入れ子にしない — mPDF がセルの高さを少なく見積もり、社長のコメントが 8 行ほどで印が枠からはみ出す。決裁の欄は上の段（判断の欄・申請部門・承認）と下の段（コメント・印）の 2 段にし、`page-break-inside: avoid` を付ける
    - ② 人が打つ文字の入る表には `class="wrap"` を付ける（mPDF の表の CSS `overflow: wrap`）— 無いと、空白の無い長い語（URL・ファイル名）が収まるまで表ごと文字が縮む。`word-wrap`・`overflow-wrap` は効かない。決裁No・日付の表と入れ子の判断の欄は短い決まった文字だけなので付けない
    - ③ 判断した欄の朱の枠は `td.mark.on` で書く — `.on` だけだと、詳細度の高い `td.mark` の黒の細い枠が勝ち、文字だけ朱になる
    - ④ PDF の SVG は viewBox を描く大きさ（px）にして、中身を `scale()` で縮める — mPDF は `text-anchor="middle"` の文字の幅を「描く大きさの px」で測るので、viewBox が描く大きさと違うと縮尺の分だけ左へのずらしが足りず、文字が円の中心より右に寄る。`StampSvg::render()` が書体 `PDF_FONT` のときだけこの形にする（画面の SVG は変えない）
- 計画で決めた細部（計画 §0.10）: 本文は罫線の 1 行＝表の 1 行・判断の欄は小さな表（設計書 §5.6 の見本と見た目は同じ）／申請の詳細の印は「回る順番」の各行の右端に 48px／⑦ の印の見本は氏名の欄の左に 48px（34px では下段の文字が読めなかった）／PDF のファイル名の ASCII の代わりは「approval-番号.pdf」に決め打ち（名前の元が利用者の打った文字ではない）
- 既存のテストで意味が変わったもの 1 本（計画 §0.9）: `RequestActionTest::test_comments_and_names_are_escaped`（エスケープした氏名の数 3 → 5。空白の無い氏名は氏名全体が印の下段になり、印の文字と読み上げの名前の 2 か所に増える。エスケープされていることの確かめは変わらない）
- 受け入れた隙間（計画 §0.11 の 5 つと、点検で見つけたもの）:
  - 画面の印の書体は端末の明朝体で、PDF の IPAex 明朝と字形が少し違う — 起きたとき: 同じ印でも画面と PDF で字の形が少し違って見える ／ 塞ぐなら: 画面にもフォントを配る（Web フォント）
  - ⑦ の見本の上段は「最初の所属部門の略称」で、実際に押す印の上段（判断する段階の部門）と違うことがある — 起きたとき: 兼務の人や、所属と違う部門の部門長の見本の上段が実際と違う ／ 塞ぐなら: 見本を部門ごとに出す
  - `vendor/mpdf/mpdf/ttfonts`（87MB）を本番へ送る — 起きたとき: 初回の反映が数分長い・本番の容量が 93MB 増える ／ 塞ぐなら: `deploy.sh` で除く（mPDF の既定の書体の読み込みを確かめてから）
  - 本番の Web の PHP のメモリの上限は、CLI の php.ini と `~/www/php.ini` から 128M と見たが、Web の実際の値は読んでいない（`phpinfo` を本番に置かない）— 起きたとき: 128M より小さければ大きな申請の PDF で作れないことがある（「PDF を作れませんでした。」と laravel.log）／ 塞ぐなら: **使い始める前の受け入れ確認（段階6）で、重い申請の PDF を本番で 1 回出して確かめる**
  - 印に使う文字の 4 文字は、ブラウザの `maxlength` が UTF-16 の数で数える（𠮷 などは 2 と数える）が、サーバーは文字で数える — 起きたとき: 4 文字の中に 𠮷 などがあると、ブラウザで 4 文字目が打てない ／ 塞ぐなら: 小窓の `maxlength` を外して、サーバーの検証だけにする
  - 印の下段の文字の大きさは下限 8 なので、空白の無い 8 文字以上の氏名（印に使う文字が空のとき）は印の円の線にかかる — 起きたとき: 見た目だけ。8 文字以上で文字が円の線にかかり（8 文字は触れる程度・9 文字から重なる。PDF でも文字の中心は円の中心にそろう）、15 文字前後からは印の箱の端で切れる（2026-10-05 に 6〜16 文字で描いて確かめた） ／ 避け方: 決裁の管理者が ⑦ で印に使う文字（4 文字まで）を入れる ／ 塞ぐなら: 空白の無い長い氏名は先頭の数文字にする
  - PDF で長いコメントを書くと、そのコメントの入る表ごと文字が縮む（最後の点検で測った。文字は欠けず、紙面に全部残る）— 起きるとき: 部門長・社長・審査のコメントが 800 文字前後を超える（上限は 2,000 文字。数行のふつうのコメントでは起きない。改行の多いコメント〈40 行・約 600 文字〉は縮まない）／ どうなるか: 判断の表・審査の表の文字が小さくなり、列の幅も組み替わる。測った例（日本語の文・元の文字の大きさに対する割合）: 部門長のコメント（幅 20% の欄）800 文字で 93%・1,200 文字で 77%・2,000 文字で 60%（8.5pt → 5.1pt。判断の欄と社長の印が左に小さく寄る）／社長のコメント 1,200 文字で 95%・2,000 文字で 69%／審査のコメント 800 文字で 90%・1,200 文字で 76% ／ 塞ぐなら: コメントを本文と同じく、罫線の 1 行＝表の 1 行に分けて組む
  - 空白の無い 2,000 文字の社長のコメントだと PDF が 2 ページになる — 起きるとき: 社長のコメントに空白の無い長い文字列を 2,000 文字（現実にはほぼ無い）／ どうなるか: 1 ページ目の決裁の欄の文字が約 5% 縮む（読める・印は枠の中）／ 塞ぐなら: 上の行と同じ（コメントを行の表に分ける）
  - PDF の本文の半角の空白がつぶれる — 起きるとき: 本文に半角の空白で字下げや桁そろえをする（全角の空白は残る。申請の詳細の画面は `whitespace-pre-wrap` で残す）／ どうなるか: 字下げが消え、連続する半角の空白は 1 つになる。紙面だけ崩れる／ 塞ぐなら: 紙面で本文の半角の空白を残す書き方にする（mPDF での効き方を確かめてから）
  - IPAex に無い文字は PDF で消える — 起きるとき: 氏名・件名・本文・印の文字に、IPAex の字形が無い文字（𠮷・絵文字など。髙・﨑・①・㈱ はある）／ どうなるか: その文字だけ出ない（例: 「髙﨑 𠮷子」は「髙﨑 子」）／ 塞ぐなら: 字形のあるフォントを同梱に足す（容量が増える）
  - PDF のページの下の「出力者」の氏名に `{PAGENO}`・`{nbpg}`・`{DATE …}` と入っていると、mPDF がページ番号などに置き換える — 起きるとき: 氏名にそうした文字列が入っている（氏名は管理者が登録するので、起きる見込みはごく小さい）／ どうなるか: 出力者の氏名の一部がページ番号などに置き換わる。件名・本文に入れても置き換わらない（点検で描いて確かめた）／ 塞ぐなら: ページの下に入れる前に、氏名の `{`・`}` を取り除く
  - ⑦（利用者の管理）の表が、約 1,425px 未満の画面で横スクロールになる — 起きるとき: 画面の幅が約 1,425px 未満（1,366px のノート PC で約 58px 足りない・1,280px で約 144px。1,440px でも余りは 15px しか無く、端末の書体でメールなどが広いと出うる）／ どうなるか: 表が横にスクロールする（「← スクロールできます →」の案内は出る。右端の「無効化」が隠れる程度で、「編集」はほぼ見える）。スマホの幅（375px）で氏名の列が 1 文字幅に潰れるのを、氏名・所属部門の列に最小の幅を持たせて直した代わりの受け入れ ／ 塞ぐなら: 1,366px の管理者から声が出たら、メールの列を `[overflow-wrap:anywhere]` で折り返せるようにする・操作の列の案内を 2 行に折り返せるようにする案を、1,440px の見え方も測ってから試す
  - 出力の記録の書き方が添付の出力と同じ形の写しで、記録の種類が `'pdf'` の直書き — 起きるとき: 4b で Excel の出力の記録が 3 つ目になる ／ どうなるか: 書き方が 3 か所に散らばり、`user_agent` を 255 文字で切る所なども 3 か所になる ／ 塞ぐなら: 4b のときに 1 か所にまとめ、`kind` を `KIND_*` の定数にそろえる（下の 4b の節にも書いた）→ **4b で済んだ**（`DownloadLogger`・`KIND_*`）
- 実装のコミット（計画 `1b238886` の次から。古い順）:
  - 実装（計画の Task 1〜6）: `8d1ad360` 表／`6eec7c7f` 判断したときの控え／`b6f716bd` 申請の詳細の印／`7330b316` ⑦ の印に使う文字／`6588157c` PDF の紙面と mPDF・フォント／`bf58e570` PDF の出力と記録
  - 文書（Task 9）: `8216429e` 作り方と注意を BACKLOG と CLAUDE.md に書く／`79607b1f` SQL の説明が指す計画の番号を直す
  - 点検の手直し: `a7220226` 印の上段のエスケープのテスト／`2a3f941b` 社長の印が欄の枠からはみ出さない／`100bf0c1` 本文が長い URL で縮まない／`3a308425` 判断した欄の朱の枠／`013dfc42` 部門長と審査のコメントのエスケープのテスト／`b94ece7d` 件名・コメント・添付・審査の表も長い語を折り返す／`b5c68f20` 編集の小窓が今の印に使う文字を入れて開くテスト（⚠ 本文と Co-Authored-By の行の間に空行が無く trailer にならない。履歴を書き換えない決まりでそのまま。push の前に利用者に一言）
  - 記録（計画書に書き足したもの）: `fc3be999` 変異テストの結果（Task 7）／`3212477b` 画面と PDF の確認の結果（Task 8）
  - Task 8 の確認で見つけた 2 件の手直し: `93615843` PDF の印の文字を円の中心にそろえる／`8891e101` スマホの幅の ⑦ で氏名の列が潰れない
  - 最後の点検の手直し: `732d7943` PDF のエスケープのテスト（添付の名前・部門名・氏名）。文書の 2 本（この節と CLAUDE.md の手直し・計画書の記録）は `git log` で見る
  - 全件・変異・手元のブラウザの確認（計画の Task 7・8）の結果は、計画書の「Task 7 の実測記録」「Task 8 の実測記録」に残した

#### 本番反映（2026-10-05 実施）

利用者の了承のあと、計画の Task 10 の手順どおりに流した（本番の読み取り・DB・早送りと部品と `./deploy.sh` と読み取り・ログインした画面のそれぞれで了承を取った）。`13.x` は別の会話の作業で `732b9ef1` まで進んでいたので、台帳の決まりどおり先に worktree へ取り込んで（`169d41c9`）全件を流し直した。反映のあいだは別の会話に `13.x` の早送りと `./deploy.sh` を待ってもらった。DB は早送りより先に変えた（SQL は worktree の同じコミットのファイルを送った。早送りから反映までのあいだに、列の無い DB へ新しいコードが出ないように）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 取り込み | worktree に `git merge 13.x`（`732b9ef1`・51 コミット） | 衝突なし（`composer`・`package`・`database`・`routes` の変更なし）→ `169d41c9`・全件 **OK (3565 tests, 26897 assertions)** |
| ② 本番の読み取り | ルート・列・設定・件数・PHP | `approvals.` のルート 50 本 ／ 3 列とも無い ／ `launched_at=NULL` ／ 申請 0・段階 0・利用者の行 1・出力の記録 0 ／ `memory_limit=128M` ／ `gd mbstring zlib` あり ／ `vendor/mpdf`・`resources/fonts` 無し ／ `laravel.log` は Jun 18 17:41 |
| ③ DB を先に | SQL 2 文（`ALTER TABLE approval_members`・`ALTER TABLE approval_steps`） | 流した形跡なし → **OK 1・OK 2** ／ 流したあと: `approval_members.stamp_text varchar(4)`・`approval_steps.stamp_label varchar(6)`・`approval_steps.stamp_text varchar(100)`（どれも NULL 可・`utf8mb4_unicode_ci`・日本語の説明つき）／ 行の数は変わらない |
| ④ 早送り | `13.x` | `732b9ef1` → `169d41c9`・ttf 2 本（6,099,900・7,835,672 バイト） |
| ⑤ 部品と読み込みの表 | main repo で `composer install --no-dev` → `composer dump-autoload --no-dev --optimize` | `6 installs, 0 updates, 0 removals`（`mpdf/mpdf` 8.3.1・`setasign/fpdi` 2.6.8・`paragonie/random_compat` 9.99.100・`myclabs/deep-copy` 1.13.4・`mpdf/psr-log-aware-trait` 3.0.0・`mpdf/psr-http-message-shim` 2.0.1）・7,291 クラス・`vendor/bin/phpunit` は無い・`git status` は空 |
| ⑥ `./deploy.sh` | 2026-10-05 12:29 ごろ終了（日本時間） | exit 0・6 段すべて。アプリのファイル 31 本（`resources/fonts/ipaex` を含む）・`vendor` 652 本（送ったのは圧縮で約 58MB）。旧 CSS `app-B-wGZyIE.css` を削除 |
| ⑦ 本番の読み取り | コンパイル済みビューの `php -l` | **298 本 / INVALID 0 件** |
| | ルート・設定・クラス・フォント・PDF | `approvals.` のルート **51 本**（PDF の 1 本が増えた）／ `launched_at=NULL` ／ 7 クラスとも読み込める ／ `fonts=ipaexg.ttf,ipaexm.ttf` ／ 試しの PDF `%PDF-`・明朝とゴシックが入る・0.58 秒・ピーク 36MB ／ 印の SVG の PDF `%PDF-`・明朝が入る・0.2 秒 ／ 出力の記録 0 のまま（試しは記録しない）／ `laravel.log` は Jun 18 17:41 のまま（反映のあとのエラー 0 件）／ `storage/framework/cache/mpdf` ができた |
| | ログインした画面（利用者の Chrome・見るだけ・保存しない。利用者は決裁の管理者） | ⑦ に 3 人とも印の見本（所属部門が無いので上段は空・中段 R8.10.5・下段は氏名の空白より前）／ 編集の小窓に「印に使う文字」の欄と案内「決裁の所属部門と印に使う文字だけ変えられます。」（キャンセルで閉じた）／ `main` のはみ出し 0（1,220 = 1,220）／ コンソールのエラー・警告 0 件 ／ 決裁のホームは「決裁の機能は準備中です。」のまま |

⚠ 使い始める前なので、利用者に見える変化は ⑦ の「印に使う文字」の欄と印の見本だけ（申請の画面・印・PDF は `launched_at` が入ってから出る）。

⚠ `origin/13.x` への push はしていない（利用者の指示を待つ）。→ 2026-10-05 に利用者の指示で push 済み（`c47306b4..c26be125`。別の会話の `732b9ef1` も含む）。⚠ `b5c68f20` だけ本文と Co-Authored-By の行のあいだの空行が無く、git の trailer として読まれない（中身は正しい。履歴は書き換えない決まりなのでそのまま）。

### 4b（決裁台帳 ⑤・Excel 出力・Excel の出力の記録）

実装計画: @docs/superpowers/plans/2026-10-05-approval-phase4b.md（Task 0〜8）。worktree `.claude/worktrees/approval-phase3`（4a と同じ）。

- 表: `approval_download_logs.request_id` を NULL 可に（Excel の行は申請が無い）・`filters`（JSON。絞り込みの条件）・`request_count`（件数）を足した。本番の SQL は `database/sql/2026-10-05-approval-phase4b.sql`（ALTER 1 文）。本番反映は **DB が先・`./deploy.sh` が後**。本番の表は 0 行
- 出力の記録は、添付・PDF・Excel のどれも `DownloadLogger` だけが書く（`kind` は `ApprovalDownloadLog::KIND_*`。4a からの持ち越しを片付けた）。ほかの場所が直接書かないことを `DownloadLoggerTest` が走査で見る
- 台帳の問い合わせは `Ledger` の 1 か所（画面と Excel が同じものを使う）。見られる範囲は `RequestVisibility::apply`・下書きは出さない。**中身（部門・種類・件名・本文）は申請者本人以外には最後に提出した控えで出し・当てる**（D19。他人の差戻し中・取り下げの申請だけを `approval_revisions` の今の回の JSON で当てる。この 2 つの状態は `ApprovalStatus::mayDifferFromSubmission()` の 1 か所に置き、関連する決裁No の候補〈`RelatedNumberController`〉も使う）。1 行の中身は `LedgerRow`（`ApprovalRequest::lastRevision()` を先に読む）
- 並び（D22）は日本の暦の日で比べるので SQL で並べず、`Ledger::sortedIds()` が軽い列を読んで PHP で並べる（同じ日は番号のあるものを部門のアルファベット・連番の順、番号の無いものは発信の新しい順）。5,000 件で画面 0.12 秒
- 絞り込みは GET。読めない値は断らずに外して画面で知らせる（`LedgerFilter`。`validate()` で前の画面へ戻すと同じ URL を開き直し続ける）。キーワードと申請者は空白で分けた語のどれも含むもの（申請者は登録名の半角・全角の空白を無視して当てる。「申請 花子」を「申請花子」で探せる）。プルダウンは変えた瞬間に送り、`pageshow` で絞り込みのフォームを元に戻す（Bug #65）
- Excel は `LedgerExcel`（PhpSpreadsheet の書き出しの初めての例）。文字の欄はすべて `setCellValueExplicit(…, TYPE_STRING)`（式にしない）・日付は日本の暦の日の Excel の日付・金額は数・見出しを固定して絞り込みのボタン。**件数の上限は 1,000**（`config('approval.ledger.excel_limit')`。メモリをいちばん使うのは審査のコメントと条件の長さで、入力の上限の中身〈コメント 2,000 文字〉の 1,000 件は Laravel 込みで 95MB ほど・2,000 件は本番の上限 128M を超える）。超えたら作らず、記録もしない。選択肢に無い年度・部門・種類と壊れた文字（不正な UTF-8）の条件は、画面と Excel の両方で外し、知らせるのは画面だけ（Excel はファイルなので外すだけ。記録の `filters` には外した項目が null で残る）
- ✅（段階5a で `LedgerExcel::columns()` の 1 つの定義から作る形に直した）⚠ **段階5 で Excel の列を足すときの注意**: `LedgerExcel` の列の並びと列記号が 5 か所の手書きで、別々にある（見出しの `COLUMNS`・`write()` の `$texts` と日付・金額の列記号・`DATE_COLUMNS`・`AMOUNT_COLUMN`・`WRAPPED_COLUMNS`）。今は合っているが、工事原価などを途中に足すと記号がずれる。列を足すときは 1 つの定義から作る形に直す
- 計画で決めた細部（計画 §0.9）: ホームの入口は上の「新しい申請」「自分の申請」の行／Excel は作れなかったときの知らせを作らない（失敗はメモリの不足くらいで捕まえられない）／設計書 §4.1 の「拡張が無ければ起動時に止まる」は誤り（起動時の確かめは PHP の版だけ。本番を読み取って拡張がそろっていることを確かめた）
- 受け入れた隙間（計画 §0.10）:
  - キーワードの探し方が本番の MySQL では当てる列で少し違う（申請の行の列は大文字小文字・かなの違いを区別せず、控えの JSON の中〈他人の差戻し中・取り下げ〉は区別する）— 起きたとき: 他人の差戻し中・取り下げの申請を、大文字小文字やかなを変えた言葉で探すと当たらないことがある ／ 塞ぐなら: 控えの側にも照合順序を付ける
  - 台帳を開くたびに、当たる申請の軽い列をすべて読んで並べる — 起きたとき: 数万件で遅くなる（5,000 件で 0.12 秒・申請は年に数百件）／ 塞ぐなら: 日本の日付の列を表に持つ
  - 本番の Web の PHP のメモリの上限は CLI と同じ 128M と見ている（4a と同じ）— 起きたとき: 小さければ、コメントの長い 1,000 件の Excel を作れない（500 と laravel.log）／ 塞ぐなら: 段階6 の受け入れ確認で多めの台帳の Excel を本番で 1 回出す
  - 番号のあと取り下げた欠番は決裁日が空で、並びは発信日の日・決裁日の期間で絞ると出ない ／ 塞ぐなら: 取り下げた日時を欠番の日にする
  - 年度の選択肢の和暦は期の始まりの月がいちばん早い会社で数える（今の会社はどれも R1 で同じ）
  - キーワード・申請者の `%` と `_` を逃がさない（アプリのほかの検索と同じ）
- 実装のコミット（計画のコミットの次から。古い順）: Task 1〜4 の 4 本と記録（Task 5・6）。`git log` で見る

#### 本番反映（2026-10-07 実施）

利用者の了承のあと、計画の Task 8 の手順どおりに流した（本番の読み取り・DB・`./deploy.sh`・反映のあとの読み取りのそれぞれで了承を取った）。別件の枝 `page-clamp`（Bug #100。テナントの物件・不動産の仕入れ案件・周辺ビル調査の一覧の大きなページ番号の 500）も、利用者の決定で一緒に出した。反映のあいだは別の会話に `13.x` の早送りと `./deploy.sh` を待ってもらった。最後の点検（opus）の指摘で、申請者の絞り込みが登録名の空白を無視するように直してから出した（`4fff1289`）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 取り込み | worktree に `git merge 13.x`（`c50a6b3c`・住宅事業のテスト）→ `git merge page-clamp`（`f6a34369`） | 衝突なし（`composer` の変更なし）→ `f46095c6`・`e04c80d2`・全件を **PHP 8.3.35 を名指しして** 流して **OK (3768 tests, 30311 assertions)**（この Mac の `php` は 8.5 なので `/opt/homebrew/opt/php@8.3/bin/php` を使った） |
| ② 本番の読み取り | ルート・列・設定・件数・PHP | `approvals.` のルート 51 本 ／ `filters`・`request_count` は無い・`request_id` は NULL 不可 ／ `launched_at=NULL` ／ 申請 0・出力の記録 0 ／ `memory_limit=128M` ／ `gd xmlwriter zip` あり ／ `laravel.log` は Jun 18 17:41 |
| ③ 早送り | `13.x` | `c50a6b3c` → `e04c80d2` |
| ④ DB を先に | SQL 1 文（`ALTER TABLE approval_download_logs`） | 流した形跡なし → **OK 1** ／ 流したあと: `request_id bigint unsigned`（NULL 可）・`filters json`（NULL 可）・`request_count int unsigned`（NULL 可）・日本語の説明つき ／ 外部キー 3 つ（`attachment`・`request`・`user`）が残る ／ 行は 0 のまま |
| ⑤ 読み込みの表 | main repo で `composer dump-autoload --no-dev --optimize`（部品の追加は無いので `composer install` は打たない） | 7,297 クラス・台帳の 4 クラスが表に入った・`vendor/bin/phpunit` は無い・`git status` は空 |
| ⑥ `./deploy.sh` | 2026-10-07 9:58 ごろ終了（日本時間） | exit 0・6 段すべて。アプリのファイル 23 本・`vendor` は読み込みの表の 2 本。旧 CSS `app-F3f4AS97.css` を削除 |
| ⑦ 本番の読み取り | コンパイル済みビューの `php -l` | **299 本 / INVALID 0 件** |
| | ルート・設定・クラス・台帳と Excel | `approvals.` のルート **53 本**（台帳と Excel の 2 本が増えた）／ `launched_at=NULL` ／ 6 クラスとも読み込める ／ `excel_limit=1000` ／ 申請 0 件の台帳から試しの Excel（ZIP の頭 `504b0304`・6,719 バイト・0.22 秒・ピーク 32MB）／ 申請者「申請花子」・キーワード・状態「すべて」の問い合わせが本番の MySQL 8.0.40 で通る ／ 出力の記録 0 のまま（試しは記録しない）／ `laravel.log` は Jun 18 17:41 のまま（反映のあとのエラー 0 件） |
| | ログインした画面（利用者の Chrome・見るだけ。利用者は決裁の管理者） | 決裁のホームは「決裁の機能は準備中です。」のまま・台帳への入口は無い ／ `/approvals/ledger` と `/approvals/ledger/excel` は決裁のホームへ戻る ／ 横のはみ出し 0・コンソールのエラー 0 件 ／ Bug #100 の 3 つの一覧は `?page=9223372036854775807` と `?page=99999` で 200 |

⚠ 使い始める前なので、利用者に見える変化は Bug #100 の 3 つの一覧だけ（台帳・Excel・入口は `launched_at` が入ってから出る）。

⚠ `origin/13.x` への push はしていない（利用者の指示を待つ）。→ 2026-10-07 に利用者の指示で push 済み（`c50a6b3c..cd6956cb`。4b・`page-clamp`〈Bug #100〉・Bug #100 の残りの 4 か所の直しを含む）。

⚠ 同じ種類の 500 が残っていた 4 か所（住宅の契約一覧・住宅のダッシュボード・決裁の ⑥・⑩。`docs/RULES.md` の Bug #100）は、利用者の指示で 2026-10-07 に直して本番に出した（`98b9cc2b`〜`255c5764`。ページ番号を最後のページまでに抑える。⑥ の範囲の外のページは、空の一覧ではなく最後のページを出すように変わった）。全件は PHP 8.3.35 を名指しして OK (3777 tests, 30357 assertions)・別の担当の点検は Ready（小さな指摘は手直し済み）。反映は DB・部品の変更なしで `13.x` を `255c5764` へ早送り → `./deploy.sh` exit 0（アプリのファイル 5 本）→ 本番のコンパイル済みビュー 299 本 INVALID 0・直した 5 ファイルの `php -l` OK・`launched_at=NULL`・`laravel.log` は Jun 18 17:41 のまま（反映のあとのエラー 0 件）。⑥・⑩ は使い始める前なので本番の画面では開けない（決裁のホームへ戻る）。

---

## 🚧 決裁申請 段階5（申請の種類ごとの作り込み・過去の決裁の取り込み）— 5a・5b 本番反映済み（使い始める前）

設計書: @docs/superpowers/specs/2026-10-07-approval-phase5-design.md（D1〜D25）。2 回に分ける（5a: 申請の種類ごとの作り込み ／ 5b: 過去の取り込み）。

### 5a（申請の種類ごとの作り込み）

実装計画: @docs/superpowers/plans/2026-10-07-approval-phase5a.md（Task 0〜13）。worktree `.claude/worktrees/approval-phase3`（4b と同じ）。

- 表: `approval_types` に本文の形（`body_form`）・明細表の行（`table_layout`。JSON）・件名の決まり文句・追加の欄の使う／使わない 4 つ・定型文、`approval_requests` に明細表（`amount_table`。JSON）・坪数・坪単価・担当者・契約予定日・定型文、使える部門の表 `approval_type_department`。本番の SQL は `database/sql/2026-10-07-approval-phase5a.sql`（3 文）。本番反映は **DB が先・`./deploy.sh` が後**
- 明細表の形と計算は `AmountTable` の 1 か所（画面の JS も同じ式。**粗利率は整数で計算**＝浮動小数の丸めと PHP の版で答えが変わらない）。追加の欄は `RequestExtras`、保存する列は `RequestFields`（明細表の種類の金額はサーバーが合計金額の販売金額から計算・使わない欄は空・定型文は種類から写す）、提出の条件は `SubmitChecker`（合計金額・担当者・契約予定日・名前のない行の項目名・決まり文句だけの件名・使える部門〈差戻し中は種類と部門を変えていなければ従わない〉）
- 申請者以外に見せる中身は最後に提出した控えから（`ApprovalRequest::submittedRevision()` が 1 つの入口。詳細・PDF・台帳・Excel・メールの件名・差戻しの取り消し・提出の条件）。変更点の明細表は行の鍵（名前を設定した行か・項目名）で合わせる
- 数の入力のそろえ方と前後の空白の落とし方は `FormInput::digits()`・`FormInput::trim()`。⑦ と台帳の氏名の探し方は `NameSearch`
- MySQL は JSON のキーを並べ替えて返す: テストで JSON の列を丸ごと比べるときは `assertSameIgnoringKeyOrder()`（`ComparesJsonColumns`。`assertEquals` は 0 と null を同じとみなす）
- 計画で決めた細部（計画 §0.14）: 5W2H の種類でも契約予定日を使えば Excel に入る（設計書 §5.8 から変えた・利用者に確認済み）／控えに本文の形は入れない（明細表の有無で分かる）／決まり文句だけの件名は提出で断る／種類の選び直しは、保存で消えるものを知らせて「元の種類に戻す」
- 受け入れた隙間（計画 §0.15）: 申請の行は各 30 行まで（⑨ で名前を全部付け替えると超えることがある）／項目名を直した自由行は変更点で「消えた行＋増えた行」／明細表の行の名前では探せない／Web のメモリの上限は CLI と同じ 128M と見ている／中間表の外部キーの名前が SQL と migration で違う
- 実装のコミット（計画のコミットの次から。古い順）: Task 1〜9 の 9 本と、点検での手直し 4 本（Task 6・8・9）、まとめの点検のあとの手直し 5 本、記録（Task 10・11）。`git log` で見る

#### 実装（2026-10-08・会話 d95f3f84・Subagent-driven）

- 計画の 9 Task を差分のファイルから入れ、Task ごとに点検した（sonnet／Task 6〜8 は opus）。点検で直した 3 つ:
  - Task 6: 種類を選び直すと明細表の名前の行が二重になる → `AmountTable::forForm()`・画面の `mergeRows`・`fromInput()` に「同じ名前の名前の行が無ければ同じ名前の自由行を取り込む」を足し、「元の種類に戻す」は選び直す前の行をそのまま戻すようにした。5W2H の種類で断られて戻ると保存した明細表が画面から消える件も同じ手直しで直した
  - Task 8: 追加の欄と定型文の 1 行だけの表がページの下の端で縮む → `autosize="1"`（`pdf.blade.php`）
  - Task 9: ほかの人の契約予定日が控えから来ることのテストを足した
- まとめの点検（opus）: Ready to merge（Critical・Important なし）。使い始める前に直すと仕分けた 3 つ（その部門だけで使える申請の種類がある部門は削除できない `5bc1d38d`・1 つの欄にエラー文 1 つ〈`bail`〉`78ef3685`・補足の案内「行数の制限はありません」`372d6483`）とテストの穴 10 か所（`974e0b07`）を直し（`72dd304f` は `bail` のテストの空振りを直したコミット）、別の担当の再点検でも新しい問題は無かった
- 全件 OK (4049 tests, 38114 assertions)。使い捨ての MySQL 8.4 で本番の SQL と migration を突き合わせ、外部キーの名前以外は一致。決裁のテストを MySQL でも流し、赤は古い 4 本だけ（`UsersSchemaTest` 2 本は `sqlite_master` を読む・`MailFailureBannerTest` 1 本・`TypeManagementTest::test_every_field_is_saved_and_logged_by_the_update` は記録の JSON のキーの並びに頼る。計画の段の試作と同じ 4 本）

#### 残したもの（使い始めたあとでよい。まとめの点検の仕分けで BACKLOG としたもの）

- 元の種類が使えない申請（停止・部門が合わない）をコピーし、種類を選ばずに下書きを保存すると、写した明細表と追加の欄が消える（`RequestFields.php` は種類が無いと空にする。種類は必須の最初の欄なのでまず起きない）／ 直すなら「コピー元の明細表があります。種類を選ぶと出ます」を出す
- 差戻し中に管理者が種類の定型文か「計」の有無を変え、申請者が何も変えずに保存して差戻しを取り消すと、本人の詳細と PDF だけが控えと食い違う／ 直すなら `RequestSnapshot` の指紋に `fixed_text` と `amount_table.subtotal` を足す（「直した」に数えて取り消しを断る安全側）
- 古い画面・手組みの入力で、同じ名前の名前の行が 2 つ送られると 2 つ目の名前が落ちる（金額は残り、提出で「項目名を」と断られる）／ `AmountTable::fromInput()` の自由行側の名前を `self::name($row['name'] ?? null) ?? self::name($row['fixed'])` にする 1 行で直る
- 控えの契約予定日が壊れていると台帳と Excel 全体が 500 になる（`LedgerRow.php:85` の `createFromFormat(...) ?: null` は、形が合わないと例外を投げる Carbon には効かない。今は控えが必ず `Y-m-d` で作られる）／ **5b で台帳に紙の決裁など別の日付の出どころを入れる前に直す**
- 未使用の定数（`RequestExtras::TSUBO_MAX`・`TSUBO_PRICE_MAX`・`STAFF_MAX`。コントローラが数を直書き）を使うか消す（変えても上限が変わらないのに、変えれば変わると読める）／ `uses_*` の 4 つの名前が `TypeController` の中の 4 か所に手書き（5 つ目の欄を足すと 500）／ `RequestController::validated()`・`TypeController::validateType()` が約 110 行／ 本文の形の切り替えの「申請があるか」の確認と保存が同じ排他の中に無い（管理者の操作と申請者の操作が重なったときだけ。確認と保存のあいだに申請者がその種類の申請を作る場合。TOCTOU）／ `refuseLayout` の `$request` が使われていない／ 「計」（前半の合計）は 12 桁の検査の外（int のあふれは無い）／ `ApprovalType::find` を 2 回呼んでいる／ 停止した種類が使えない部門でもあると、同じ対処の理由が 2 つ並ぶ／ テストの穴（後半の 30 行・坪数の下限・坪単価の上限・販売金額の負の側の 12 桁超）
- 種類の選び直しの細かい場面（さらに選び直すと知らせが消える・手で元の種類に戻すと件名が組み立て直されない・A→B→A で空の自由行が 3 つ残る〈詳細と PDF には出ない〉・5W2H どうしで戻すと本文の入れ替えの確かめがまた出る・5W2H の種類にいる間に直した件名が、元の種類に戻すときに前半として使われる）
- 20 桁の金額は「数で入力してください」だけが出る（`bail` のため。13〜18 桁は「12 桁まで」）。直すなら `min`・`max` を `integer` の前に並べる（`RequestTableSaveTest` の文の比べも合わせる）
- 申請の種類の管理: 一覧の使える部門が部門名だけで、会社をまたぐ同名の部門を区別できない（小窓は「会社・部門」で選ぶので設定は誤らない）・`departments()` に `orderBy` がない／ 追加の小窓で明細表を選ぶと行が 0 行から始まる（足さないと保存で断られる）／ 行の名前欄で Enter を押すと保存される（既存の種類名と同じ）／ ヒント文のコントラスト
- 申請の画面（`form.blade.php`）: 珍しい空白 3 つ（U+FEFF・U+0085・U+180E）だけ画面とサーバーで判断が割れる／ `mergeRows` の `var fixed = {}` は名前が `constructor` などだと誤動作する／ 追加の欄を使う 5W2H の種類で Tab の順と見た目の順が違う／ 30 行で「＋ 行を足す」が押せなくなっても理由が出ない／ 913 行になった（Task 6 の手直しなどで増えた）
- PDF: 金額の列 30mm・粗利率 18mm では、10 桁以上や −100% 以下の数が折り返す（住宅の契約の 8〜9 桁では起きない）／ 担当者に空白の無い 50 文字を入れると契約予定日が折れる
- 変更点の表の先頭の列の見出しが空の `<th>`（読み上げ）／ 部門の削除の確認と削除が同じトランザクションでない（既存の断りも同じ作り。管理者が同じ瞬間に操作したときだけ）

#### 利用者に伝えること（受け入れ確認のとき）

- 坪単価の欄は、欄を離れてもカンマ付きに整わない（明細表の金額だけ整える。計画 §0.6 のとおり）
- Excel の見出しの右端のフィルターのボタンが「契約予定日」の最後の字を少し隠すかもしれない（4b の「審査の意見」と同じ幅の決め方。段階6 の受け入れで本番の Excel を開いて確かめる）
- 1 ページより背の高い定型文（1 文字ずつ改行したような極端なもの）は PDF で縮む

#### 本番反映（2026-10-08 実施）

利用者の了承のあと、計画の Task 13 の手順どおりに流した（本番の読み取り・DB・`./deploy.sh` のそれぞれで了承を取った）。反映のあいだは別の会話（manage）に `13.x` の更新と `./deploy.sh` を待ってもらった（manage の枝 `zeal-email-wrap` は反映のあとで新しい `13.x` を取り込む）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | ルート・列・設定・件数・メモリ | `approvals.` のルート 53 本 ／ `body_form`・`amount_table`・`approval_type_department` は無い ／ `launched_at=NULL` ／ 種類 0・申請 0・控え 0 ／ `memory_limit=128M` ／ `laravel.log` は Jun 18 17:41 |
| ② 早送り | `13.x` | `a2f43bbc` → `d35d8846`（push はしていない） |
| ③ DB を先に | SQL 3 文（`ALTER TABLE approval_types`・`CREATE TABLE approval_type_department`・`ALTER TABLE approval_requests`）。送った SQL の sha1 は手元と同じ | 流した形跡なし → **OK 1・OK 2・OK 3** ／ 流したあと: `body_form varchar(20)` NULL 不可・既定 `points`・`uses_*` は `tinyint(1)` NULL 不可・既定 0・`table_layout`／`amount_table` は `json`・`tsubo decimal(7,2)`・`tsubo_price bigint unsigned`・`staff varchar(50)`・`contract_date date`・`fixed_text text`（ほかは NULL 可）＝計画 §0.2 のとおり ／ 制約 `PRIMARY`・`fk_approval_type_department_dept`・`fk_approval_type_department_type` ／ 種類 0・申請 0 のまま |
| ④ 読み込みの表 | main repo で `composer dump-autoload --no-dev --optimize`（PHP 8.3 を名指し。部品の追加は無い） | 7,302 クラス・5a の新しい 5 クラスが表に入った・`vendor/bin/phpunit` は無い・`git status` は空 |
| ⑤ `./deploy.sh` | 2026-10-08 16:3x ごろ終了（日本時間） | exit 0・6 段すべて。アプリのファイル 38 本（うち `vendor` は読み込みの表の 2 本）。旧 CSS `app-CuFJhCCA.css` を削除 |
| ⑥ 本番の読み取り | コンパイル済みビューの `php -l` | **305 本 / INVALID 0 件** |
| | ルート・設定・クラス・計算 | `approvals.` のルート 53 本（5a はルートを足さない）／ `launched_at=NULL` ／ 5 クラスとも読み込める ／ 粗利率の見本（販売 8,000,000・粗利益 5,100,000）は本番の PHP 8.3 でも **63.8%** ／ 種類は 0 件 ／ `laravel.log` は Jun 18 17:41 のまま（反映のあとのエラー 0 件） |
| | ログインした画面（利用者の Chrome・見るだけ。利用者は決裁の管理者） | 申請種類の管理の一覧に「本文の形・使える部門」の列（種類は 0 件で空）／「種類を追加」の小窓に本文の形のラジオ・「計」の行・件名の決まり文句・坪数／坪単価／担当者／契約予定日・定型文・使える部門（本番の部門は「本部」「住宅事業部」）が出る（**保存は押さず**キャンセルで閉じた）／ 決裁のホームは「決裁の機能は準備中です。」のまま ／ コンソールのエラー 0 件 |

⚠ 使い始める前なので、利用者に見える変化は申請種類の管理（⑨）の新しい欄だけ（申請の画面・詳細・PDF・台帳・Excel は `launched_at` が入ってから出る）。

⚠ `origin/13.x` への push はしていない（利用者の指示を待つ）。

### 5b（過去の決裁の取り込み）

実装計画: @docs/superpowers/plans/2026-10-08-approval-phase5b.md（Task 0〜12）。worktree `.claude/worktrees/approval-phase3`（5a と同じ）。
運用手順書（本物の台帳は、使い始める直前に利用者が ⑪ で取り込む。2026-10-26 の利用者の決定）: @docs/運用_過去の決裁の取り込み.md

- 表: `approval_paper_records`（紙の決裁。`number` は一意・(部門, 年度, ＮＯ) も一意）・`approval_paper_imports`（取り込み 1 回分。取り消しても残す）。本番の SQL は `database/sql/2026-10-08-approval-phase5b.sql`（2 文）。本番反映は **DB が先・`./deploy.sh` が後**
- 読み取りは `PaperLedgerFile`（読むだけ）→ `PaperImportAnalysis`（確認と確定が同じものを作る）→ `PaperImporter`（ロック → システムの番号 → 保存）。日付は `PaperDate`（年は月で決める・先頭の年は年度かその日の和暦と合うこと）。**本物の台帳はリポジトリに入れない**（テストは `BuildsPaperLedgers`）
- 次の番号は `ApprovalNumber::advancePast()`（今年度だけ・戻さない）。開始番号は今年度の紙の番号以下を断る
- 見られる範囲は `RequestVisibility::constrainPaper()` の 1 か所。台帳は `Ledger::sortedKeys()`（申請 `r`・紙 `p` の鍵）と `paperQuery()`、紙が当たる条件は `LedgerFilter::includesPapers()`、紙の行は `LedgerRow::forPaper()`
- 紙の決裁日の期間の終わりは「次の日より前」で比べる（SQLite は日付の列に時刻の付いた文字で入る）
- 計画で決めた細部（計画 §0.14）と受け入れた隙間（計画 §0.15）は計画のとおり
- 実装のコミット（計画のコミットの次から。古い順）: Task 1〜8 の 8 本と記録（Task 9・10）。`git log` で見る
- **Bug #118（5a からのテストの欠陥・2026-10-13 に直した）**: PDF の文字の大きさを読むテスト（`PdfTableTest`・`PdfSheetTest`・`StampDisplayTest`）の正規表現 `stream\r?\n(.*?)\r?\nendstream` が、PDF のフッターの出力時刻で圧縮の最後のバイトが 0x0D になる分（1 日の約 2.6%）に 1 バイト切り落とし、`PdfTableTest` が時々赤になっていた（アプリの PDF は正しい）。mPDF は `\n` でしか書かないので `\nendstream` にした（`18718723`）。計画の段の変異の表で F05・U03 を「検出」としていたのは、この赤の読み違いだった（2 つを見分けるテストを足した `abcbe9ad`）

#### 本番反映（2026-10-13 実施）

計画の Task 12 の手順どおりに流した。反映のあいだは別の会話（manage）に `13.x` の更新と `./deploy.sh` を待ってもらった。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り（反映前） | ルート・表・設定・件数・ログ | `approvals.` のルート 53 本 ／ `approval_paper_records`・`approval_paper_imports` は無い ／ `launched_at=NULL` ／ 部門 2・申請 0・連番 0 ／ `memory_limit=128M`・`upload_max_filesize=5M`・`post_max_size=8M` ／ `laravel.log` は Jun 18 のまま |
| ② 早送り | `13.x`（手元。manage の会話に待ってもらった） | `2d0d54f9` → `e0fe3a05` |
| ③ DB を先に | `database/sql/2026-10-08-approval-phase5b.sql` を scp → 文 2 つ（どちらも `CREATE TABLE`）を確かめて流した | **OK 1・OK 2** ／ 流したあと: 列の型・NULL・制約（`PRIMARY`・`uq_approval_paper_records_number`・`uq_approval_paper_records_seq`・`fk_approval_paper_*` 6 つ）が計画 §0.2 のとおり ／ 件数はどちらも 0 |
| ④ 読み込みの表 | main repo で `composer dump-autoload --no-dev --optimize`（PHP 8.3 を名指し） | 読み込みの表に新しいクラス 10 が入った ／ `vendor/bin/phpunit` は無い |
| ⑤ `./deploy.sh` | | exit 0・6 段すべて成功 |
| ⑥ 本番の読み取り | コンパイル済みビューの `php -l` | **311 本 / INVALID 0 件** |
| | ルート・設定・クラス・日付 | `approvals.` のルート 64 本（+11）／ `launched_at=NULL` ／ 新しいクラス 6 つ（`PaperDate`・`PaperLedgerFile`・`PaperImportAnalysis`・`PaperImporter`・`ApprovalPaperRecord`・`ApprovalPaperImport`）はすべて ok ／ Xls の読み手 ok ／ `PaperDate::read('8.05.13', 2026, 5)` = 2026-05-13 ／ `laravel.log` は Jun 18 のまま（反映のあとのエラー 0 件） |
| | ログインした画面（利用者の Chrome・Claude in Chrome・見るだけ。送信や登録はしない） | 過去の決裁の取り込みの一覧に「紙の決裁はありません」「まだ取り込んでいません」／ 取り込みの画面の年度は R8〜H1 ／ 1 件ずつ登録の画面が開く ／ 決裁のホームは「準備中」のまま＋管理者の入口 ／ コンソールのエラー 0 件 |
| 残したこと | push・本物の台帳 | `origin/13.x` への push はしていない（利用者の指示を待つ）／ 本物の台帳の取り込みはしていない（使い始める日までに、決裁の管理者が ⑪ で行う） |

⚠ 本番の `upload_max_filesize` は 5M・`post_max_size` は 8M。⑪ の画面は「10MB まで」と書いてあるが、約 5MB を超える台帳はアップロードできず、確定は台帳を base64 にして送るので約 6MB を超えると送れない（08 年度の台帳は約 1.3MB で問題ない）。大きな台帳を取り込むときは php.ini の調整（要件 16.3 の 15）か、計画 §0.15 の 2 の一時ファイルの形へ。
- （2026-10-26 追記）上の 5M・8M は ssh で読んだサーバーの既定の値（CLI）で、Web の画面の値ではない可能性が高い。2026-09-27 の読み取り（下の「顧客CSVインポートの確定を通す」の節の「`post_max_size`（読み取りだけ）」）では、公開フォルダの上の `~/www/php.ini` が `upload_max_filesize = 512M`・`post_max_size = 512M` で、Web には 512M が効く見込み（Web から直接は測っていない）。運用手順書は 5MB と言い切らず、画面の 10MB と「アップロードに失敗したら相談」で書いた（@docs/運用_過去の決裁の取り込み.md の 2.3）

#### 5b の残したもの（本番反映のあとでよい。SDD の点検の記録から）
1. 横に広い Excel（例: 200 列 × 5,900 行・8.3MB）は、行の数の上限（6,000）の中でも本番の 128M を使い切る（捕まえられない 500）。列の数も見る上限は、本物の台帳の列の数を手元で測ってから決める（最後の点検 M-1。決裁の管理者だけの画面・中身は壊れない）
2. 同じ台帳を取り込み直すと、金額を補ってある記録すべてに、金額が同じでも確認の画面で注意が出る（M-2）
3. 契約金額だけ書いて工事原価が空の金額の控えの行も結び付き、あとで工事原価を書き足しても補えない（設計書 D6 の「金額がすべて空の記録にだけ補う」のとおり。補う条件を広げるかは利用者が決める。M-3）
4. 金額の控えの日付が Excel の日付の数のセルだと、確認の画面に「45793」のように数のまま出る（M-4）
5. 確認の画面から確定のあいだに、システムの申請の番号以外の理由（部門の削除・アルファベットや期の始まりの月の変更）で足す行が消えると、黙って落ちて残りを取り込む（管理者が確認の画面を開いたまま部門や会社を直すときだけ。システムの申請の番号は断る＝`d9cff2a3`）
6. 金額の控えの行は、申請者が空だと読まれない（受付簿は申請者が空でも取り込む）。実物の台帳にありうるかを利用者に確かめる
7. ファイル名の「2026年度」から 2014 年度を先に選ぶ（年度を選ぶ JS の正規表現。台帳の名前の「08年度」の形なら起きない。違う年度で読むと全部の行が「年度と合いません」になり、黙っては取り込まない）
8. 画面の見た目（手元のブラウザの確認で）: 375px で確認の画面の「シート」列と一覧の取り込みの記録の「ファイル」列が細くつぶれて 1〜2 文字ずつ折り返す／1440px でも確認の画面の「足す記録」「金額の控え」の表が枠より広く、台帳の場所と結び付け先の選択の右側が隠れる（横にずらせば見える）／台帳の絞り込みが 9 欄になり、スマホで「申請者」の右が空く・PC は 3 段目が「キーワード」だけ
9. 文言・小さな見た目: 1 件ずつの登録で断られて戻ると金額のカンマが消える（値は同じ）／金額を補っただけの取り込み（足した 0 件）の取り消しの欄が「この回に足した記録は残っていません」／紙の決裁の詳細でサイドバーのどの項目も選ばれた色にならない／区分「紙」で 0 件でも「該当する申請はありません」
10. 紙の決裁の削除と取り込みの取り消しのフォームに二度押し止めが無い（`confirm()` はある。削除の 2 回目は 404）／変えずに「直す」を押すと「手で直した」に数えられる（取り消しの確かめが多めに言うだけ）
11. 紙の決裁が 1,000 件を超えると、既定の条件（全年度）では台帳の Excel の出力が「絞り込んでから出してください」で断られる（設計どおり。5 年分ほど取り込むと届く）
12. 細かいテストの穴（`LedgerRow::day()` の行末の改行・ＮＯ の桁あふれの数・結び付けの候補の数の上限・`paperRecord()` が呼ぶたびに利用者を作る など）

---

## 🚧 決裁申請 段階6（本番への先行公開と受け入れ確認）— 6a 本番反映済み（試す人 0 人＝準備中のまま）

要件定義書: @docs/決裁申請_要件定義書_v1.md（v1.16。8.1・8.3・9.2・13 章の⑬・14.6・15.5・15.6・16.1・16.2）
設計書: @docs/superpowers/specs/2026-10-26-approval-phase6-design.md（D1〜D15。2026-10-26 に流れの図と ⑬ の画面の見本を見て、利用者が選択式で決めた）
受け入れ確認の手引き: @docs/運用_受け入れ確認の手引き.md（試す人に渡す。写真付きの読みやすい版の元は `~/site/approval/acceptance-guide/acceptance-guide.html`＋`img/`。⑬ の画面の文言や断りを変えたら両方を直す）

- 作り方: 2 回に分ける（利用者の決定 2026-10-27）。**6a** 試す人と試す期間 ／ **6b** 試しの申請の片付け・使い始める（6a を本番に出し、試し始めてから計画を書く）

### 6a（試す人と試す期間）

実装計画: @docs/superpowers/plans/2026-10-27-approval-phase6a.md（Task 0〜10）。worktree `.claude/worktrees/approval-phase3`（5b と同じ）。

- 3 つの状態（準備中・試す期間・使い始めた。`ApprovalLaunchStage`）と「この人に申請を回す画面を見せるか」は `App\Support\Approval\LaunchState` の 1 か所。門番・ホーム・2 つのサイドバー・基幹のメニューとベル・⑫・Notifier・催促のコマンド・試しの印・提出の確かめがすべてそれを読む。`ApprovalSetting::isLaunched()`・`launchedForMenu()`・`launched_at` をほかで読まないことを `LaunchStateScanTest` が止める（コメントにも当たる）
- 試す人は `approval_members.is_tester`（有効で削除していない人だけを試す人として扱う。`ApprovalMember::testers()`・`LaunchState::isTester()`）。出し入れは ⑬「使い始め」（`LaunchController`・`approvals.admin.launch.*`。決裁の管理者だけ・準備中も開ける）で 1 人ずつ。設定変更の記録は `member.tester_added`・`member.tester_removed`
- 試す期間は、申請を回す画面・お知らせ・メール・催促が試す人にだけ届く（`Notifier::receives()`・`ApprovalRemindCommand`）。試す人でない人は準備中と同じ応答（画面はホームへ・それ以外は 404。実在する ID でも同じ）
- 試しの印: 帯（`approvals/_trial_banner`。試す人と決裁の管理者の決裁の画面）・メールの件名の【試し】（`ApprovalMailable::subjectLine()` の 1 か所。付けるかは積むときに決めてメールに持たせる）・PDF の上の余白の「試し」（`PdfSheet::$trial`・`_pdf_trial_header`。本文と印の位置は変わらない）・Excel の名前の「試し_」（`LedgerExcel::fileNames()`）
- 提出の確かめ: 試す期間は回る先の人（部門長・審査の担当の 1 人以上・社長）がみな試す人のときだけ提出できる（`TrialCoverage::missingForSubmit()` を `SubmitChecker` が読む）。⑬ の欄 2「試せる部門と種類」も同じ `TrialCoverage::overview()`
- 「試しの採番だけを除いた次の番号」`approval_number_sequences.next_without_trials`（6b の片付けが使う。画面には出ない）は `ApprovalNumber` だけが動かす（開始番号と紙の番号では動き、社長の判断の採番では動かない）
- 本番の SQL は `database/sql/2026-10-27-approval-phase6a.sql`（3 文。列 2 つと、今の連番の行の `next_without_trials` を `next_number` にそろえる。番号を付けた申請が 0 件のときだけ正しい）。本番反映は **DB が先・`./deploy.sh` が後**。部品の追加は無い
- **段階3 設計書 §5.2 の申し送り（Notifier と催促の「使い始めたか」の判定の見直し）は、これで閉じた**（判定を `LaunchState` に置き換え、試す期間は試す人に絞る）
- 実装のコミットは `5eaf8d8c` まで（`git log` で見る）。画面の確認（Task 8）と全件・変異（Task 7）の記録は計画の末尾。写真 54 枚は `/Users/masanori/site/approval/screenshots-6a/`（リポジトリの外）

#### 本番反映（2026-10-27・会話 ab2e5ae8）

| 段 | 結果 |
|---|---|
| 反映前の読み取り | 決裁のルート 64 本・`is_tester`／`next_without_trials` は無い・`launched_at` は空・部門 2・申請 0（番号の付いた申請 0）・連番の行 0・`approval_members` 1 行・`laravel.log` は 6 月 18 日 |
| `13.x` | `69c43b0c` → `40f27fd2` へ早送り（manage の会話に先に知らせ、反映が済むまで早送りと deploy を待ってもらった） |
| DB が先 | `2026-10-27-approval-phase6a.sql` を送り、3 文（`OK 1`・`OK 2`・`OK 3 rows=0`＝連番の行が無いのでそろえた行も 0）。2 つの列の型・NULL・既定・コメント・並び（`stamp_text` の後・`last_issued` の後）は設計どおり |
| 読み込みの表 | main repo で `dump-autoload --no-dev --optimize`（7316 classes・dev の部品なし・新しい 4 クラスが入った） |
| `./deploy.sh` | 6 段すべて |
| 反映後の読み取り | ビュー 315 本の lint INVALID 0・決裁のルート 67 本（⑬ の 3 本が増えた）・`launched_at` は空・4 クラスとも読める・`LaunchState::stage()` = `preparing`・試す人 0 人・`next_without_trials` と `next_number` のずれ 0・`laravel.log` は 6 月 18 日のまま |
| 画面（利用者の Chrome・見るだけ） | ⑬ に「準備中」「試す人 0 人」・役のある人（部門長 1・決裁の管理者 1）に「試す人にする」・欄 2 は社長が未設定・部門長がまだ試す人でないので ×・種類はまだ無い・帯は出ない。サイドバー（展開と引き出し）と決裁のホームに「使い始め」。決裁のホームは「準備中です」のまま。コンソールのエラー 0 |

- 公開した読みやすい版: 受け入れ確認の手引き https://claude.ai/artifact/U4zNyE4hDcCEsM24HYtTzB （新規）・紙の台帳の取り込み手順 https://claude.ai/artifact/HCZ6nEX7aKBmomYGiC3WkR （手引きへのリンクを足した）・要件定義書 v1.16 https://claude.ai/artifact/A5GPFxxjsuDRAfLfCzcufY
- 試し始めるのは、利用者が ⑬ で試す人を選んだとき。その前に、要件 16.3 の準備（社長の指定〈基幹の管理者〉・申請の種類と審査部門・部門長と審査の担当の登録）が要る（2026-10-27 の本番は、社長が未設定・申請の種類が 0）
- push はまだ（利用者の指示があってから）

#### 6b に回したもの

- ⑬ の欄 3「試しの申請の片付け」（申請と関わるものを消し、決裁No の次の番号を `next_without_trials` に戻す。消えるものの件数を出す確認の画面）・欄 4「使い始める」（試しの申請が 0 件のときだけ・戻せない）
- 使い始めたあとの ⑬ の表示（6a は「使い始めました（日時）。」だけ。押した人は 6b）・使い始めるときに試す人の印を外すこと
- 要件定義書 4.5・14.2・14.5 の例外（片付けだけは申請・記録・添付・お知らせを消す）と、段階3 の D16「お知らせは消さない」の注記（v1.17）
- 受け入れ確認の手引きの片付けと使い始めるの部分（設計書 §1.1 の 3〜6）
- 走査のテスト（`LaunchStateScanTest`）の範囲を広げる: 走査の対象に `routes/` を加える／ `is_tester` と `testers(` を `ApprovalMember`・`LaunchState`・`LaunchController`（と `TrialCoverage`）の外で書くことを禁じる（今は `app/` と `resources/views/` の「使い始めた日時」の読み方だけを止めている）
- `LaunchGateTest::OPEN_BEFORE_LAUNCH` の `'approvals.admin.launch.'` は先頭一致なので、6b で足す片付け・使い始めるのルートも自動で「準備中も開ける」に入ってしまう。⑬ の変更のルートについて「使い始めたあとは断る」を全件で確かめるテストを足すか、名前を完全一致にする
- ⑬ の欄 2「試せる部門と種類」は審査の担当に申請者本人も数える（提出の確かめは数えない）。審査部門で試す人なのが申請者本人だけのとき、⑬ では ○ なのに、その人が出すと断られる（断りの文は正しい）。⑬ に注記か注意を足す
- 部門長・社長が無効や削除済みでも、今は名前を出して「試す人に入っていません」と断る（⑬ では無効の人を試す人にできない）。「無効の人に回る」という別の断りを考える（今の `SubmitChecker` にもある穴）
- `LaunchController` の戻り先の関数 `back()` は Laravel の `back()` と紛らわしい（`toLaunch()` などに改める）
- 使い始めたあとの ⑬ の「使い始めました（…）」が日本時間で出ることのテスト

#### 利用者に伝えること（6a を本番に出すとき・試し始めるとき）

- 6a を本番に出しただけでは何も変わらない（試す人 0 人＝準備中のまま。変わるのは決裁の管理者のサイドバーの「使い始め」と ⑬ だけ）。試し始めるのは、利用者が ⑬ で試す人を選んだとき
- 6a の期間に出した試しの申請と、試しで進んだ決裁No は、6b が届いて片付けるまで残る
- 試す期間には本物の大事な書類を添付しない（片付けても夜間バックアップの保管先に暗号化した形で残る。D15・要件 14.6）
- 試す期間に、試す人でない決裁の管理者のホームは「いまは試す期間です。申請の画面は試す人にだけ見えています（試す人は「使い始め」で選べます）。」と出る（試す人でも管理者でもない人は今の「決裁の機能は準備中です。…」のまま）
- ⑬ の「外す」に確かめの小窓は出ない（外してもまた足せる）。欄 2 の 2 つの表はスマホの幅でも 2 列（会社名と「…だけ」は名前の下に小さく）。使い始めたあとの ⑬ は「使い始めました（日時）。」だけで、見出しの横に試す人の数は出ない
- 試しの提出の確かめは、申請部門に所属していないときは部門長を、停止した種類の下書きでは審査を見ない（今の理由だけが出て、選び直したあとで試す人の確かめが出る）
- 決裁の管理者も試す人にしておく（試す人でないと「進行中の申請の管理」が出ず、取り消しや付け替えを試せない）。付け替え先・交代した社長や部門長も先に試す人にしておく（試す人でない人に回ると、知らせが出ずその申請はそこで止まる）
- 段階5a の「利用者に伝えること（受け入れ確認のとき）」の 3 つと、本番でしか確かめられないこと（設計書 §5.10 の 6 つ・php.ini を直す手順）は、受け入れ確認の手引きの 5・6 に入れた

---

## ✅ CSV 取込の確認画面から断られると 405 になる件（基幹の取込）— 本番反映済み

実装計画（実測・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-24-import-preview-back.md

決裁 段階1 の F2（上の節）のレビューで「基幹の取込にも同じ形がありうる」と指摘され、別の作業として切り出した（2026-09-24）。
確認画面は POST の応答で、その URL は POST 専用。そこに載ったフォームから送って断られると、`back()` と、try で包まない入力チェックの
既定の戻り先がリファラー＝その URL へ GET で戻り **405**（本番の `APP_DEBUG=false` では英語の素のエラー画面）になっていた（docs/RULES.md Bug #64）。
**DB 変更・ルート変更・新規 PHP クラス・新規依存はいずれも無し。**
⚠ ブランチ `import-preview-back` は `approval-phase1` の上に積んである（RULES の Bug #64 がそこにしか無いため）。**approval-phase1 を先に出してから早送りする。**

| 取込 | 確認画面の URL | 405 になっていた経路（実測） | 直し方 |
|---|---|---|---|
| テナント（5 タブ）| `/admin/tenant-import/{tab}` | 送り直し: ファイルなし・見出しだけ ／ 確定の書き込みの失敗 | 取込の画面のそのタブ（`?tab=`）へ |
| 賃貸マンション（6 タブ）| `/admin/mansion-import/{tab}` | 同上 | 同上（`?selected_tab=`）|
| ZEAL 会員 | `…/zeal/member-import/preview` | 確定: データが空・必須の列が無い・有効な店舗が無い・`confirmed` が抜けた | 取込の画面へ |
| 工程表 | `…/schedule-import/preview` | 送り直し: ファイルなし・**ガント形式**（選び間違いで普通に踏む）／ 確定: `rows_json` なし・空・壊れた行 | 取込の画面へ |
| 顧客 | `/admin/customers/import`（GET と同じ URL）| —（実測 200）| 直さない（→ 2026-09-26 に戻り先を取込の画面に固定した。下の「顧客CSVインポートの確定を通す」の節）|
| 周辺ビル | 確認は画面の中（SheetJS）・送信元は GET の取込画面 | —（実測 200）| 直さない |

### 要点

- 入力チェックは `try { $request->validate([...]); } catch (ValidationException $e) { throw $e->redirectTo(…); }`
  （`validate([` の形が残るので `JapaneseValidationMessagesTest` の走査もそのまま通る）、そのほかは `redirect()->route(…)`
- ⚠ テナント・賃貸マンションの確認画面は**開いているタブの枠だけ**が確認に置き換わり、ほかのタブのアップロードのフォームは残る。
  実際の画面で踏むのは「確認画面から**別のタブ**でアップロードし直して断られる」経路（実ブラウザで、リファラーが確認画面の URL になることまで確認）
- ⚠ 計画に無かった経路を 1 つ直した: ZEAL 会員の確定の送信から hidden の `confirmed` が抜けると、確定なのにファイル必須の入力チェックに落ち、
  その既定の戻り先も確認画面の URL になる（テストで再現してから直した）
- 走査テスト `ImportControllerReturnPathScanTest`: `*ImportController.php`（8 本）を全件分類する。許すのは顧客・周辺ビルの 2 本だけ（件数と理由つき）。
  ⚠ try で包まない入力チェックは字面に `back(` が現れないので**走査に見えない** → 挙動のテスト（`from(確認画面の URL)` で送る）が守る（→ 2026-09-25 から `ImportControllerValidationRedirectScanTest` が包み方を全件分類で見る。下の「取込の入力チェックの包み忘れを走査テストで止める」の節）

### 検証

- 全件テスト 2150 → **2169 tests / 14567 assertions green**（`ad673f7b`。approval-phase1 の 2150 本に、この作業の 19 本を足した）
- 足したテストは、4 本のコントローラだけを直す前の版（`6e9b59fc`）に戻すと**すべて赤**（18 件: 新しい 15 ケース・`ImportValidationFeedbackTest` 2・走査 1。理由はどれも「確認画面の URL へ戻っている」。走査は 4 本を `back(` 19 か所ごと名指しした）
- 変異テスト（隔離コピー 3 つ・どれも最初にカナリア・Bug #44 の作法）: 1 回目 36 通りはすべて期待どおり（期待したテストの集合が、
  期待した理由の文言で落ちた。走査の失敗文は、変異を当てたファイルと行を名指しした）。レビューを受けてテストを直した後の 2 回目 19 通りも
  すべて期待どおりで、直す前のテストでは緑だった 3 つの変異（ガント形式の断りの文言の差し替え・理由の一覧を消す・`DB::rollBack()` を消す）が
  赤になった（`DB::rollBack()` を消す変異は、開いたままのトランザクションに後続のテストが巻き込まれる＝変異の性質）
- 実ブラウザ（使い捨て SQLite ＋ `artisan serve` ＋ Playwright）: テナントは物件タブの確認画面から区画タブで見出しだけの CSV を送り、
  工程表は確認画面からガント形式を送った。どちらもリファラーは確認画面の URL（POST 専用）で、`302 → 取込の画面 200` に着き理由の帯が出た。
  `main` の横スクロール 0・コンソールのエラーは今回と無関係の 1 件（使い捨て DB に `ms_rooms` が無い経営ダッシュボード）だけ
- コンパイル済みビュー **274 本 / INVALID 0 件**
- 独立レビュー 1 回: Critical・Important 0 件、Minor 7 件（うち 6 件はテストの穴で、すべて直した。1 件は範囲外へ）
- ⚠ **テストが緑でも守っていなかったもの**（どれも直した）:
  - 工程表のガント形式のケース — 期待する文言が取込の画面の案内文に常に出ているので、理由の帯が消えても緑（Bug #43）
  - `ImportValidationFeedbackTest` の理由の正規表現 — 案内文の `<li>CSVの「物件名」…</li>` に一致していた（今回より前からの穴）
  - 確定時の書き込みの失敗のテスト — 見本が 1 行だけで、最初の INSERT より前に落ちるので `DB::rollBack()` を消しても 0 件
  - 送り直しのテスト — 画面からは起こせない「同じタブの確認画面から同じタブへ」を送っており、タブを既定やリファラーから決める誤りを区別できなかった

### 範囲外（気づいたが直していない）

- 入力チェック（`validate()`・`Validator::make()`・`ValidationException::withMessages()`）を try で包んで `redirectTo()` を渡しているかを、走査で機械的に見る形（新しい取込の入力チェックには守り手がいない。レビューの提案） → **2026-09-25 に対応**（下の「取込の入力チェックの包み忘れを走査テストで止める」の節）
- ZEAL 会員の確定で DB の例外が出ると 500（`DB::transaction()` の外で受け止めていない。戻り先の問題ではない）（→ 2026-10-02 に直した。下の「ZEAL 会員の取込の確定で DB の例外が出ると 500 になる件」の節・Bug #72）
- 顧客の取込は、断られると確認画面の内容が消えて取込の画面に戻る（今と同じ）

### 本番反映の手順

1. approval-phase1 を先に出す（上の節の手順）
2. main repo で `git checkout 13.x && git merge --ff-only import-preview-back` → `./deploy.sh`
   （DB 変更・新規 PHP クラス・依存の変更は無い＝ SQL も `composer dump-autoload` も要らない）
3. 本番の確認は読み取りだけ（取込の画面が開くこと。本番のデータは作らない）

### 本番反映（2026-09-25 実施。決裁 段階1 の直後に）

利用者の承認のあと、決裁 段階1 を出して確かめてから早送りした。直前に `import-preview-back`（`b06f89ac`）で全件テスト
**OK (2169 tests, 14567 assertions)**。`13.x` = **`b06f89ac`**。

| 見たこと | 結果 |
|---|---|
| 早送りで変わるもの | コントローラ 4 本だけ（依存・DB・設定・ルート・ビュー・CSS は変わらない）＝ `composer` の手順は不要 |
| `./deploy.sh` | exit 0・3 つのキャッシュとも成功。送ったアプリのファイルはコントローラ 4 本だけ（`vendor` 0 件・旧バンドルの削除 0 件）|
| 本番の 4 本 | 手元と **md5 が一致** ／ `php -l` で構文エラーなし |
| コンパイル済みビューの `php -l` | **274 本 / INVALID 0 件** |
| 取込の画面（ログイン済みの実 Chrome・GET だけ）| テナント・賃貸マンション・ZEAL 会員・顧客・周辺ビル・工程表（建売の物件 9）の 6 画面がすべて 200・エラーなし |

⚠ 断られたあと取込の画面へ戻ることそのものは、本番ではフォームを送らないと確かめられないので見ていない（上の「検証」のテストと
ローカルの実ブラウザで確認済み）。⚠ `origin/13.x` への push はしていない。→ 2026-09-25 に push 済み（`9fffc965` に含まれる）。

---

## ✅ テストのメモリが 1 本ごとに増え続ける件 — テストだけ（本番への反映は不要）

全件のテストのメモリのピークが 505.50 MB（3144 本・`phpunit.xml` の上限 512M の 98%）になり、あと数十本で
`Allowed memory size … exhausted` で全件が途中で止まる状態だった（2026-10-01。決裁 段階3a のテストを足すと実際に止まった）。
**`app/`・`resources/`・`routes/`・DB・依存・`phpunit.xml` の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| テスト（変更）| `tests/TestCase.php`（`tearDown()` で Carbon の共有の翻訳の `resetMessages()`）|
| テスト（新規）| `tests/Feature/CarbonTranslatorTrimTest.php`（2 本。片付けを消すと 2 本目が赤）|
| 全件テスト | 3144 → **3146 tests / 22423 assertions green**・ピーク **505.50 MB → 307.50 MB**・約 3 分 |

### 調べて分かったこと（2026-10-01 の実測）

- 増えるのはアプリを起動するテストだけで、1 本あたり約 125 KB（純粋な Unit テストは増えない）。テストごとの `Application` は解放されている（弱い参照で確認）。ゴミ集め（`gc_collect_cycles()`）を毎回呼んでも減らない＝まだ使われている扱いのメモリ
- **約 65 KB/本: Carbon の Laravel 用のプロバイダ**（`Carbon\Laravel\ServiceProvider::boot()`）。起動のたびに `setFallbackLocale()` が日本語の文の全部（約 13 KB）を共有の翻訳（プロセスに 1 つ）へ `addResource()` で 6 回足し、捨てる仕組みが無い。本番は 1 リクエスト 1 回の起動なので害は無い → テストの `tearDown()` で `resetMessages()`（足した文を最初の 1 つに戻す）
- **残りの約 44 KB/本: OPcache の無い CLI** が、クロージャを含むファイル（`bootstrap/app.php`・設定・ルート）を起動のたびに読み直して残す分。`php -d opcache.enable_cli=1 ./vendor/bin/phpunit` で流すと 0 になり、全件のピークは **98.50 MB**・約 3 分（測定のみ。テストの流し方を変えるかは未決）
- 静的な変数・クラスの数・読み込んだファイルの数は増え続けていない（増えるのは上の 2 つだけ）

→ 2026-10-03: Carbon 3.14（下の「既存の部品の既知の弱点を直す」で上げた）が、起動のたびに文を積み増す不具合を直した。前提のテストが赤になったので、`tests/TestCase.php` の片付けと `CarbonTranslatorTrimTest` を外した（外しても全件 3334 本のピークは 317.50 MB で、片付けありと同じ）。

→ 2026-10-28: テストの流し方を OPcache ありの形に決め、`CLAUDE.md` の「テストの流し方」に書いた（`php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit`）。**アプリ・テストの中身・`memory_limit` は変えていない**（`phpunit.xml` は注記だけ直した）。

| 流し方（4495 本・PHP 8.3.35） | 時間 | PHP のメモリのピーク | プロセスの最大の使用量（RSS） |
|---|---|---|---|
| 今までの流し方 | 4 分 07 秒 | 464.50 MB | 544 MiB |
| OPcache あり | 2 分 56 秒 | 163.50 MB | 374 MiB（OPcache の共有メモリを含む） |

- ⚠ `phpunit.xml` の上限は **2026-10-01 から 1G**（`16c1c386`）。それ以降の記録（ページ送り・JS の直し・アンケートの節など）にある「上限 512M」は古い数字で、2026-10-28 の時点では上限の 45% だった
- OPcache は php を起動するときにしか有効にできない。`phpunit.xml` の `<ini>` と `phpunit -d` は実行中の `ini_set` なので効かない（`ini_set('opcache.enable_cli', '1')` は false・テストの中で `opcache_get_status()` が null を返すことを実測）
- OPcache の器（既定）: ファイル数は 3,612／16,229 で余裕、メモリは 123.0MB／128MB で残り 5MB だった → 256MB に広げる形にした。広げたあと（決めた流し方）: OK (4495 tests, 44044 assertions)・3 分 18 秒（裏で別の作業をしていたので少し延びた）・163.50 MB・器は 123.0MB 使用で残り 133.0MB・あふれは無し
- OPcache ありでも 1 本あたり約 37KB は増える（全件の最後の値が最大の値と同じ）。上限 1G まで約 23,000 本の余裕があるので、原因は追っていない（OPcache なしなら約 5,000 本）
- `composer test`・`php artisan test` は動くが、`.env` を読みに行くので `.env` の無い worktree では全部のテストに警告が付く → 使わない
- git の外の道具（`.claude/route-coverage-tools/` の変異の `mutate/run.py` と測定の `measure.sh`）も同じ流し方にそろえた

## ✅ 既存の部品の既知の弱点を直す（今の大きな版の中で更新）— 本番反映済み

決裁 段階4a の計画づくりの途中で `composer audit` を流したら、すでに入っている 12 の部品に既知の弱点の知らせが **42 件**（重いもの 14 件）出た（2026-10-03）。
`composer.lock` は最初の構築のころのまま（部品を足したときだけ変わっていた）。**`composer.json`・`app/`・`resources/`・`routes/`・DB の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| 依存 | `composer.lock` の 53 の部品（直す 12 と付いてくる部品）。`laravel/framework` 12.55.0 → **12.69.3**・`guzzlehttp/guzzle` 7.10.0 → 7.15.5・`guzzlehttp/psr7` 2.9.0 → 2.13.1・`league/commonmark` 2.8.1 → 2.10.3・`league/flysystem` 3.32.0 → 3.36.0・Symfony 7.4 系の最新（`http-foundation`・`http-kernel`・`routing` 7.4.20・`mailer`・`mime` 7.4.19 ほか）・`nesbot/carbon` 3.11.3 → 3.14.2 ほか |
| テスト（変更・削除）| `tests/TestCase.php` の `tearDown()`（Carbon の片付け）を外し、`tests/Feature/CarbonTranslatorTrimTest.php` を削除（上の節の → のとおり） |
| 全件テスト | 3336 → **3334 tests / 23750 assertions green**・ピーク 317.50 MB・`composer audit` は 0 件 |

- 更新のコマンド（worktree `deps-security` の cwd）: `composer update laravel/framework guzzlehttp/guzzle guzzlehttp/psr7 league/commonmark league/flysystem symfony/http-foundation symfony/http-kernel symfony/mailer symfony/mime symfony/polyfill-intl-idn symfony/routing symfony/yaml --with-dependencies`（大きな版は上げない。Laravel 13・Guzzle 8 は別の機会）
- **当たる可能性が高い 2 つ**: ①改行を混ぜたメールアドレスでメールの見出しに細工される（Laravel の `email` の検査〈12.60.0 で直った〉と `symfony/mime` の `Address`。利用者の管理・プロフィール・顧客・問い合わせでアドレスを入れられ、決裁の通知・パスワード再発行のメールに使う）②外へのつなぎ（Guzzle・psr7 のホストの取り違え・クッキー・プロキシ。ZEAL の Google スプレッドシートの取込 `ZealSheetClient` とバックアップの送り先 `S3BackupStorage` で使う）
- 直接は使っていないもの: `league/commonmark`（Markdown を描く所が無い）・`symfony/http-kernel` の `#[IsGranted]` などの属性・`symfony/mailer` の sendmail（本番は SMTP）・`symfony/yaml`（開発用だけ）・`symfony/routing` の UrlGenerator（Laravel は自前の URL 作り）
- 4a の計画（`approval-phase3`）の試作も `composer.lock` を変える（mPDF を足す）。この更新を先に `13.x` へ入れ、4a の試作の lock の差分は新しい土台の上で作り直す（利用者の決定 2026-10-03）

### 本番反映（2026-10-03 実施）

利用者の了承のあと（本番の読み取り・早送りと部品と `./deploy.sh` と読み取り・Chrome の画面のそれぞれで了承を取った）、`13.x` を `27635bb0` から `e451262f` へ早送りした。表の変更は無い。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | 版・ルート・部品・記録 | `Laravel Framework 12.55.0` ／ ルート 481 本（`approvals.` 50 本）／ `guzzlehttp/guzzle` 7.10.0・`nesbot/carbon` 3.11.3・`symfony/mime` v7.4.7 ／ `laravel.log` は 6/18 から更新なし ／ 本番に `vendor/bin/phpunit` は無い ／ ログイン画面 200 |
| ② 早送り | `13.x` | `27635bb0` → `e451262f`（2 コミット） |
| ③ 部品 | main repo で `composer install --no-dev` → `composer dump-autoload --no-dev --optimize` | `52 updates`（53 のうち `symfony/yaml` は開発用なので入らない）・6,962 クラス・`vendor/bin/phpunit` は無いまま・`git status` は空 |
| ④ `./deploy.sh` | 2026-10-03 22:02 ごろ終了（日本時間） | exit 0・6 段すべて（アプリの転送で `vendor` の部品が送られた。ビルドの警告 1 件は以前から出ている `@import` の位置のもの） |
| ⑤ 本番の読み取り | ビュー・版・ルート・部品・予定・記録 | コンパイル済みビュー 296 本・INVALID 0 ／ `Laravel Framework 12.69.3` ／ ルート 481 本（`approvals.` 50 本）で同じ ／ `guzzlehttp/guzzle` 7.15.5・`league/commonmark` 2.10.3・`nesbot/carbon` 3.14.2・`symfony/mime` v7.4.19 ／ 予定（バックアップ・朝の催促・キュー処理）はそのまま ／ `laravel.log` は 6/18 のまま＝反映のあとのエラーは 0 件 ／ ログイン画面 200・`/approvals` はログインへ 302 |
| ⑥ ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 経営ダッシュボード・決裁のホーム・利用者の管理 | 3 つとも開く・決裁のホームは「準備中」のまま・`main` のはみ出し 0・コンソールのエラー 0 |

⚠ 戻すときは、`27635bb0` で main repo の `composer install --no-dev` をし直して `./deploy.sh` する（表の変更が無いのでそのまま戻せる）。

⚠ `origin/13.x` への push はしていない。→ 2026-10-03 に push 済み（`f6891312` に含まれる）。

## ✅ 取込の入力チェックの包み忘れを走査テストで止める — テストと文書だけ（本番への反映は不要）

詳細仕様: @docs/superpowers/specs/2026-09-25-import-validation-scan-design.md
実装計画（試作・変異テスト・レビューの記録つき）: @docs/superpowers/plans/2026-09-25-import-validation-scan.md

上の「CSV 取込の確認画面から断られると 405 になる件（基幹の取込）」の範囲外として残した件（2026-09-24 のレビューの M4 の提案）。
入力チェックの既定の戻り先はリファラーで、コードに `back(` が現れないので `ImportControllerReturnPathScanTest` に見えず、
新しい取込には守り手がいなかった（今の 4 本は挙動のテストが守る）。
利用者の判断（2026-09-25）: **取込のコントローラだけをテストで守る**（本番のコードは 1 行も変えない。アプリ全体の安全網は別の作業）・
既存の走査の穴（今の URL へ戻す形）も塞ぐ・確かめ方は**ソースを読む**（実際に送って確かめる案は採らない）。
**`app/`・`resources/`・`routes/`・DB・依存の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| テスト（新規）| `tests/Feature/ImportControllerValidationRedirectScanTest.php`（94 本）／ `tests/Concerns/ScansImportControllers.php`（`*ImportController.php` の列挙と下限 8 本。2 本の走査で共用）|
| テスト（変更）| `tests/Feature/ImportControllerReturnPathScanTest.php`（列挙をトレイトへ・今の URL を作る書き方と `previousPath()` の検出）|
| 全件テスト | 2169 → **2263 tests / 14765 assertions green** |

### 要点

- 入力チェックの例外を投げる呼び出し（`->validate(`・`?->validate(`・`->validateWithBag(`・`->validated(`・`->safe(`・`->validateWith(`・`::validate(`・`::withMessages(`・`::validateWithBag(`・`new ValidationException`・`ValidationException::class`）を `token_get_all()` のトークンで探し、1 つずつ判定する: ①囲む try の catch のうち ValidationException を受け止める最初のもの（内側の try から）が ValidationException を名指しし（`\Exception`・`\Throwable` が先なら不合格）、本体が `throw $e->redirectTo(…);` の 1 文だけ ②例外を作る呼び出し（`::withMessages(`・`new ValidationException`）が throw の直後にあり、その後ろのメソッドの連鎖に `->redirectTo(` がある
- 実物: 10 か所（包んである 7・包んでいない 3＝顧客 `:28`・周辺ビル `:85`・経営試算表の URL 保存 `updateUrls` `:58`。3 か所は例外リストに件数と理由つきで載せた）
- FormRequest（既定の戻り先がリファラー）を受ける public メソッドは Reflection で探して落とす（今は 0 件。交差型・DNF 型も見る）
- まとめた use（`use A\{B, C};` と `use A\B, C\D;`）・名前の続かない冒頭の use・namespace が 2 つ・`namespace X { … }`・波括弧の対応が取れない書き方は、推測せずに「解析できない」として落とす
- 既存の走査に、今の URL を作る書き方（`url()->current()`・`url()->full()`・`URL::current()`・`URL::full()`・`app('url')->current()`・ファサードの `Request::url()` など・`$request->getUri()`・`getRequestUri()`・`path()`・`decodedPath()`・`getPathInfo()`）と、リファラーから作る `url()->previousPath()` の検出を足した（実物は 0 件）。`throw $e->redirectTo(url()->current())` は、包み方だけを見る新しい走査ではなく、こちらが拾う（分担）
- ⚠ 内側の try の catch で判定を決める（外側の catch が投げ直しを受け止める形も不合格にする厳しめの規則）
- ⚠ 見えないもの・拾いすぎるものは 2 本の走査の docblock に列挙した（`redirectTo()` に渡す値が null になりうる形・外側の総称の catch が投げ直しを受け止める形・コンテナから作る FormRequest・例外リストを件数で見ること・投げ直しを括弧で括った形 など）

### 検証

- 計画のコードは先に scratchpad で試作して実物に当て、段階ごとの赤と緑を測ってから書いた。**試作にテスト側の変異を当てたら 1 通り（catch の本体が `throw` で始まるかの判定を外す）が緑のまま通った**（見本が `return $e->redirectTo(…)` を持っていなかった）→ 同じ目で分かれ目を洗い直し、見本を 12 足した
- 変異（1 回目・レビュー前）: 65 通り（本番のコード 18＋カナリア 1 を全件で・テスト 46 を走査の 2 本で）がすべて期待どおり
- 独立レビュー 1 回: Important 1・Minor 8。**2 本の走査とも緑のまま 405 になる形が 1 つ残っていた**（`url()->previousPath()`。リファラーから作る）ほか、投げる式の判定が緩い・`->safe(` など 3 形の検出漏れ・見本の無い分かれ目・理由の文言 など。すべて実測で再現してから、直すか docblock に書いた（直す前と後に同じ探りを当てた表は計画書）
- 変異（2 回目・レビュー後の最後のコード）: 本番のコード 18 通り＋カナリア（全件）・テスト 73 通り＋カナリア（走査の 2 本）がすべて期待どおり。変異を組み直す途中で、見本の無い分かれ目をもう 1 つ見つけた（例外を作らない `::validate(` に続く `->redirectTo(` を投げる式として数えない）→ 見本を足す前のコミットでは緑のまま通ることを実測し、見本を足して赤にした

### 範囲外（気づいたが直していない）

- アプリ全体の安全網（入力チェックで断られたとき、リファラーが POST 専用の URL なら直前の GET の画面へ戻す）／実際に送って確かめる走査／包んでいない 3 か所を包み直すこと（→ 顧客の 1 か所は 2026-09-26 に包み直した。下の「顧客CSVインポートの確定を通す」の節）
- 例外リストをメソッド名で固定する形（レビューの提案。今は件数で見る）／既存の走査の `$request` 決め打ち・大文字小文字・別名・`REQUEST_URI`・今のルート名へ戻す形（レビューの指摘。docblock に書いた）
- BACKLOG の範囲外にあるほかの 2 件（ZEAL 会員の確定で DB の例外が出ると 500・顧客の取込は断られると確認画面の内容が消える）（→ ZEAL 会員の件は 2026-10-02 に直した。Bug #72）

---

## ✅ 本番の部品の名簿をデプロイのたびに作り直す（deploy.sh）— 本番反映済み

2026-09-16 に `bootstrap/cache/` を rsync の対象から外したあと、本番の部品の名簿（`packages.php`・`services.php`）は
9/13 に手元から送られた 755 のまま固まっていた。Laravel は `packages.php` を**無いときだけ**作る（`PackageManifest::getManifest()`）ため。
中身は今の部品と同じ 3 つ（laravel/tinker・nesbot/carbon・nunomaduro/termwind）で実害は無かった。ただし名簿に載った部品を vendor から外すと、
画面も artisan も起動の時点で止まる（`package:discover` 自身も同じところで止まる。手元で実測）。

- 直し方（利用者の選択）: `deploy.sh` の [5/6] で名簿 2 つを消してから `package:discover` で作り直し、そのあとで 3 つのキャッシュを作る（`462e7740`）。
  CLAUDE.md と deploy.sh の「本番が最初のアクセス時に自分で作り直す」という誤った説明も直した
- 手元の確かめ: 作業用の枝に「もう無い部品が載った古い名簿」を置き、本番と同じ csh で [5/6] を流した。直す前は `Class "…" not found` で止まり、
  直した後は最後まで通った（名簿は 700・秘密入りのキャッシュは 600）
- 本番反映（2026-09-25 23:04・利用者の了承のうえ）: 送ったアプリのファイルは `app/Support/OneTimeAction.php`（コメントだけ）の 1 本。
  反映のあと名簿 2 つが 700 で作り直され（部品は同じ 3 つ）、ログイン画面は 200、`laravel.log` に反映のあとのエラーは無かった
- ⚠ [2/6] で vendor を送ってから [5/6] で作り直すまでの数秒は、古い名簿のまま動く（部品を外した回は、この間だけ画面が止まりうる）

⚠ `origin/13.x` への push はしていない。→ 2026-09-27 に push 済み（`f98dc15e` に含まれる）。

---

## ✅ 顧客CSVインポートの確定を通す — 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-26-customer-import-confirm.md

顧客CSVインポート（サイドバーの「顧客CSVインポート」、`/admin/customers/import`、経営層のみ）の「インポート実行」（確定）は、
最初のコミット 2046289d（2026-04-08）から一度も通っていなかった（docs/RULES.md Bug #66）。確定のフォームが送るのは
`csv_data`（base64）なのに、コントローラが `csv_file` を常に必須にしていた。運び方だけ直すと、一度も動いたことのない
書き込みの問題（黙った誤保存・1 行の誤りで全行の巻き戻し）が本番で動き出すので、利用者の判断（2026-09-25・案 1）で
6 つをまとめて直した。**DB 変更・ルート変更・新規依存は無し。新規 PHP クラスは `App\Support\BuyerCsvRow` の 1 本。**

| 区分 | 実装内容 |
|------|---------|
| Support | `App\Support\BuyerCsvRow`（1 行の検査と変換。DB に触らない。`HacomonoMemberMapper` → `MappedMember` の形）|
| Controller | `Admin\CustomerImportController` — 確定は `csv_data` から読み直す（`loadCsv()`）・戻り先を取込の画面に固定・1 行の検査を `BuyerCsvRow` へ・0 件の確定を断る |
| Blade | `admin/customers/import.blade.php` の確定の欄（0 件は確定のフォームを出さない・重複候補だけならチェックを入れるまでボタンを隠す・ボタンの件数はチェックに合わせて変わる）|
| テスト | `tests/Unit/Support/BuyerCsvRowTest.php`（63 本）／ `tests/Feature/Admin/CustomerImportTest.php`（20 本）／ テスト用スキーマに `buyer_survey_answers` ／ 走査テスト 2 本の例外リストから顧客を外した。2263 → **2346 tests / 15196 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 直したこと（設計書の候補 1〜6）

| # | 直す前 | 直した後 |
|---|---|---|
| 1 確定の運び方 | 押すと「CSVファイルは必須です。」で戻り、1 件も入らない | `csv_data` から読み直し、部署・行の検査・重複の確認を最初からやり直す |
| 2 取得日 | `2026-02-30` が 3/2 に繰り上がって入る・`2026/9/1` と `1970-01-01` は理由なく誤り | `CsvDate::normalize()`（`checkdate()`）。`2026/9/1`・`1970-01-01` は通る |
| 3 生年月日 | `19800102` が 1970-08-18 に・`昭和55年1月2日` の 1 行で全行が巻き戻る | 取得日と同じ検査。読めなければその行だけ誤り |
| 4 元号 | 書かれた文字のまま入り、「昭和」は詳細画面で「昭和.1980年」と崩れる | `S`・`H`・`R`（全角・小文字・「昭和」なども可）にそろえ、生年月日の年と合わなければ誤り |
| 5 人数・勤続年数・文字数 | `2人` は本番の MySQL（strict）で全行を巻き戻す可能性が高い | 本番の列の範囲の整数（全角の数字も可）・本番の列の大きさの文字数 |
| 6 0 件のときの確定 | 「インポート実行（0件）」が出て「0件のインポートが完了しました。」に着く | 確定のフォームを出さず理由を出す。サーバも 0 件の確定を断る |

### 要点

- 1 行の誤りはすべて集め、CSV の列の順に「／」でつないで 1 件のエラーとして出す（直して上げ直すたびに次の誤りが出る、を避ける）。件数は今までどおり行の数（全 N 件＝正常＋エラー＋重複候補）
- 確定でもブラウザから届いた値を信用しない（`csv_data` と `department` を書き換えても、プレビューで通らないものは入らない）
- 押せないボタンは `disabled` にせず隠す（Top trap #12）。最初の状態はサーバが描く（V＝0 ならボタンを包む要素を `display: none` で描く）ので、開いた直後に一瞬出て消えない
- ⚠ テストの穴: 設計書の場面だけで書くと、チェックの `x-model`・理由の `x-show`・件数の JS を壊しても全部緑だった（試作の先測りで実測）→ 構造のアサートと、画面の `<script>` を node の vm で動かすテストを足した
- ⚠ 部署の検査をファイルの検査より先に行うので、両方が誤りなら部署の誤りだけが出る（直す前は 2 つとも出ていた）
- ⚠ セルの前後は `Str::trim()` で除く（全角スペースも。画面の登録の `TrimStrings` と同じ扱いに、最終レビューのあとで揃えた。ユニットテストを 2 本追加し 63 本に）
- 重複候補だけのときの案内文の文字色は `#b45309`（白地で 5.02:1）にした（最終レビュー前は `#d97706` で 3.19:1 ＝ AA の 4.5:1 未満だった）

### 範囲外（気づいたが直していない。設計書 §6 ＋ 試作で気づいたもの）

- CSV の中の同じ人の 2 行（両方とも登録される）
- 重複の確認で、都道府県・市区町村が空欄の行は既存の顧客と照合されない可能性が高い（空欄は NULL で保存されるのに、確認は空文字と比べる。コードからの推測で未実測）（→ 2026-11-03 に再現して直した。下の「顧客の取込で住所が空欄の行も重複候補にする」の節・Bug #123）
- 複数選択の回答の保存形式（画面は JSON の配列・取込は `A,B`）
- 分譲地名（前方一致）・担当者名（部分一致）のあいまいな照合
- 設問の列（Q1:…）が並び順で設問と結びつくこと
- 同じ設問番号の列が 2 つある CSV（`Q1:…` を 2 列など）は、2 列とも同じ設問の回答になり、本番では回答の一意制約（`uq_survey_answer`）に当たって取込全体が巻き戻る可能性が高い（テンプレートどおりなら起きない。コードと本番の定義からの推測で未実測。Task 1 で気づいた）
- 引用符の中の改行（行を `explode("\n")` で分けるので壊れる）
- テンプレートに記入例が無いこと
- 断られると確認画面の内容が消えて取込の画面に戻ること（今と同じ）
- 確定で例外が出たときの文言が英語のまま（`$e->getMessage()`）
- 取込のコントローラ（顧客・テナント・賃貸マンションなど）の `catch (\Exception $e)` は PHP の `Error`（型の誤りなど）を受け止めず、500 になってトランザクションが開いたまま残る（本番では接続が閉じて巻き戻る。書き込みに届く値を検査で守っているので通常の操作では起きない）
- 二重送信: 「インポート実行」を 2 回送ると、2 回目は 「…「重複候補もインポートする」にチェックを入れてください。」 に着き（探りで実測）、案内どおりチェックを入れると全員が重複して入る。ほぼ同時に届くと両方が重複の確認を通る可能性（未実測）。1 回だけ送らせる仕組み（決裁の取込の `OneTimeAction` が前例）と文言の見直しは別の作業。兄弟の取込も同じ形（→ 2026-09-27 に顧客の取込は直した。下の「顧客CSVインポートの二重送信を止める」の節）
- 設問の回答の長さを見ていない（`answer_value` は text＝65,535 バイト。日本語で約 21,846 文字を超える回答が 1 つあると本番の strict でだけ全行が巻き戻る。現実にはまず起きない。未実測）
- 上限近いファイル（10,240 KB）の確定の送信の大きさ: 確定で送る `csv_data` は、アップロードしたファイルではなく UTF-8 に読み直した中身の base64 で、フォームは URL エンコードで送る。Shift_JIS の日本語は UTF-8 で 1.5 倍（半角カナは 3 倍）になるので、日本語が多いと約 13.3 MiB ではなく 20 MiB を超えうる。本番の Web の `post_max_size` は 512M の可能性が高く（`~/www/php.ini`。2026-09-27 に読み取りで確認。下の「本番反映」）、落ちない見込み。⚠ `~/www/php.ini` が消えるか値が下がると既定値（`post_max_size=8M`・`upload_max_filesize=5M`）に戻り、5MB を超えるファイルはプレビューのアップロードで、それ以下でも日本語が多いファイルは確定の送信で落ちうる（兄弟の取込も同じ形）
- コントローラの残りの `trim()`（空行の判定・見出し・設問の回答）は ASCII の空白しか落とさない（全角スペースの入った見出しは列に結びつかず、行のエラーとして画面に出る＝黙った誤保存ではない）

### 検証

- 全件テスト 2263 → **2346 tests / 15196 assertions green** ／ コンパイル済みビュー **274 本 / INVALID 0 件**
- 変異 58 通り＋カナリア（計画の Task 6。隔離した worktree 3 つ）: 59 通りすべて MATCH（落ちたテストの集合・データセット名・理由の 1 行目とも計画の期待表と一致）。GREEN（検出漏れ）は 0 件。M09（`DB::rollBack()` を消す）・M15（確定で行の検査を飛ばす）は主因のテストが期待どおりの理由で落ち、残り（M09: 1555 件・M15: 1570 件）はすべて `PDOException: There is already an active transaction` という単一の連鎖効果だった。最終レビューの修正（F1: `Str::trim`、F2: 色）のあとにも隔離コピーで再実測し、`B24`（前後の空白を除かない）は改めた old 文字列で 4 件・新設の `B28`（`Str::trim` → `trim` に戻す）は追加した 2 本のテストだけが検出（新テストが load-bearing であることを確認）。カナリアは 3 コピーとも同一の 16 本が落ち、隔離が効いていることも確認
- ローカルの実ブラウザ（計画の Task 7。使い捨て SQLite ＋ `artisan serve` ＋ Playwright）: 10 項目すべて期待どおり —— 取込画面のフォーム表示／正常・エラー・重複候補が混在する CSV のプレビュー（件数・エラー文・重複の案内・ボタンの初期件数）／チェックの有無でボタンの件数が変わる／確定して DB に正しい値が入る（元号 S・生年月日・部署の紐付け）／重複候補だけの CSV でボタンが隠れチェックで現れる／エラーだけの CSV でボタン・チェックボックス・`csv_data` とも出ず赤字の案内が出る／`main.scrollWidth === main.clientWidth`（1440px・375px の計 4 通り）／コンソールのエラー・警告 0 件。⚠ 「ファイルを選択」の文字リンクではファイル選択が開かず `<label>` を押して開いた（既知の作法）。⚠ 375px への画面サイズ変更で 1 回ページがリセットされたが、再ログインしてやり直しコンソール 0/0 を再確認した
- 独立レビュー（計画の Task 8。opus）: 判定「**With fixes**」（マージ可）。Critical・Important は 0 件、Minor 6 件（M1 全角スペースが `trim()` を素通りする／M2 新しい案内文の色が AA 未達／M3 0 件の歯止めの説明が起きない場面を書いている／M4 `loadCsv()` の docblock が non-strict `base64_decode()` の挙動と食い違う／M5 docblock の出典（設計書 §2.6）が古い数字を指している／M6 設問の回答の文字数を検査していない）。M1〜M5 を 1 波でまとめて修正（`66d1faf9`／`1de3efdf`／`e135ffb3`）し、範囲を絞った再レビューで全 5 件 ADDRESSED・新しい壊れ無しを確認。M6・二重送信の仕組み・本番の `post_max_size`・コントローラの残りの ASCII のみの `trim()` は範囲外（上の「範囲外」に記載）。Task 3 で保留していた軽微指摘 2 件は不採用、`MIN_THROWING_CALLS` は設計書 §4.5 どおり 10 のまま

### 本番反映（2026-09-27 実施）

利用者の承認のあと、計画の Task 10 の手順どおりに流した。直前に worktree（`a7335c27`）で全件テスト **OK (2346 tests, 15196 assertions)**（⚠ worktree には `.env` が無いので、使い捨ての `APP_KEY` を環境変数で渡した。渡さずに流すと 1190 件が `MissingAppKeyException` で落ちる）。`13.x` = **`a7335c27`**（`a162957b` から早送り）。**DB 変更・ルート変更・依存の変更は無し。**

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | `13.x` と本番の差 | 本番反映済みの `462e7740` からの `13.x` の変更は `docs/` の 4 ファイルだけ ／ `approval-phase2a`（DB の SQL を含む）は `13.x` にもこのブランチにも入っていない ／ main repo に `vendor/bin/phpunit` は無い |
| ① 早送り | `13.x` | `a162957b` → `a7335c27`・作業ツリーは空 |
| ② autoload（main repo の cwd）| `composer dump-autoload --no-dev --optimize` | 6660 クラス ／ `BuyerCsvRow` が 1 件 ／ `$baseDir = dirname($vendorDir);` ／ `Tests\` の項目は 0 |
| ③ `./deploy.sh` | 2026-09-27 0:25:52〜0:26:02（日本時間）| exit 0・6 段すべて。送ったアプリのファイルはコントローラ・`BuyerCsvRow.php`・ビューの 3 本と `vendor/composer` の autoload 2 本 ／ CSS・JS の名前は変わらず（`app-wzJ6Tjji.css`・`app-NiVQbl_Q.js`）、旧バンドルの削除 0 件 ／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ④ 本番の読み取り | 3 ファイル | 手元と **md5 が一致** ／ `BuyerCsvRow` を読める（`class OK`）|
| | コンパイル済みビューの `php -l` | **274 本 / INVALID 0 件** |
| | `bootstrap/cache` と記録 | 部品の名簿 2 つが作り直され（700）、`config.php` は 600 ／ 反映のあとの `laravel.log` の記録は 0 件（Chrome で見た後も 0 件）|
| | 取込画面（ログイン済みの実 Chrome・1440px・見るだけ）| 初期フォーム（部署のラジオ「住宅事業」「不動産事業」・ファイル欄・「アップロードしてプレビュー」）が出る ／ 画面に今回のスクリプト（`importCount`）が載っている ／ `main` の横スクロール 0 ／ 開き直してコンソールの出力 0 件 |
| | `post_max_size` | 下の段落 |

**`post_max_size`（読み取りだけ）**: CLI（`/usr/local/php/8.3/etc/php.ini`）と php-cgi の既定値は `post_max_size=8M`・`upload_max_filesize=5M`。公開フォルダの一番上の `~/www/php.ini`（さくらのコントロールパネルの「php.ini設定」の保存先で、`/www` の下に効く）は `post_max_size = 512M`・`upload_max_filesize = 512M`。アプリの公開フォルダ（`~/www/mitsuwa-t/system/manage`）とその上の階層には php.ini も `.user.ini` も無い → **Web の値は 512M の可能性が高い**（Web から直接は測っていない）。確定で送る `csv_data` は日本語が多いと 20 MiB を超えうる（上の「範囲外」）が、512M には十分収まる。

⚠ 本番で実際に取り込む（確定まで）のは利用者（こちらはファイルを上げていない・フォームを送っていない）。確定まで通ることは、テスト（`CustomerImportTest` 20 本）とローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-09-27 に push 済み（`f98dc15e` に含まれる）。

---

## ✅ 顧客CSVインポートの二重送信を止める — 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-27-customer-import-double-submit-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-27-customer-import-double-submit.md

上の「顧客CSVインポートの確定を通す」の範囲外に残した「二重送信を 1 回だけにする仕組みと、2 回目に出る文言」を直した（docs/RULES.md Bug #67）。
利用者がこれから本番で実際の顧客 CSV を取り込むので、その操作そのものの安全を先に固めた（利用者の判断: 2026-09-27・案 A＝サーバの 1 回限りの鍵 ＋ 画面の二度押し止め）。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` は要らない）。

| 区分 | 実装内容 |
|------|---------|
| Support | `OneTimeAction::claimFrom()` に hidden の名前の引数（既定は `guide_token` のまま＝決裁の 5 か所は変えない）|
| Controller | `Admin\CustomerImportController` — プレビューで鍵を発行・確定では部署の入力チェックの直後に鍵を使う・0 件の案内を直す |
| Blade | `admin/customers/import.blade.php` — hidden の `import_token`・二度押し止め（`submitting`・`:disabled`・`role="status"`・`pageshow`）|
| テスト | `tests/Feature/Admin/CustomerImportTest.php`（20 → 29 本）／ `tests/Feature/Approval/LoginGuideTest.php`（`claimFrom()` の件数の下限 5 → 6。レビューの指摘）。2346 → **2355 tests / 15346 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 直したこと

| 場面 | 直す前（試作で実測）| 直した後 |
|---|---|---|
| チェック済みの確定を同じ確認画面から 2 回 | 2 回目も「2件のインポートが完了しました。」が出て全員がもう一度入る（山田 3 人・佐藤 2 人）| 2 回目は「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。…」。取り込みは 1 回 |
| チェックなしの確定を 2 回 | 2 回目は「…「重複候補もインポートする」にチェックを入れてください。」に着き、従うと全員がもう一度入る | 同上（0 件の案内には着かない）|
| プレビューのあとに同じ人が登録された | 「…チェックを入れてください。」| 「取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。」|
| ダブルクリック | —（画面の歯止めが無かった）| 確定の POST は 1 回（実ブラウザ）|
| 送信中 | 何も変わらない | ボタンが押せなくなり（不透明度 0.6・カーソル not-allowed）、横に「取り込んでいます…」 |
| Chrome の「戻る」で出る確認画面から押す | 2 回目と同じ | 鍵の案内・件数は増えない（実ブラウザ）|

### 要点

- 鍵は部署の入力チェックの直後・CSV を読み直す前に使う。**0 件の歯止めより後ろに置くと**、チェックなしの 2 回目は 1 回目で入った人を重複候補と数えて 0 件の案内に着く（決裁の取込は書き込みの直前に置いているが、顧客の取込では通用しない）
- 「確認画面 1 つにつき、送信は 1 回」。断られたあとは同じ確認画面から送り直せないので、CSV をアップロードし直す（断られると取込の画面に戻るので、画面の上ではふつうの操作と同じ）。デプロイの前に開いた確認画面（鍵が無い）も断られる。書き込みに失敗したあとも、同じ CSV を上げ直せば新しい鍵で取り込める（テストで固定）
- 画面の歯止め（Alpine）が効かないとき（JS が動かない・Alpine の起動前）も、サーバの鍵が止める
- ⚠ Chromium は POST の確認画面を bfcache に入れない。「戻る」は控えから描き直し（POST は送り直さない）、使用済みの鍵のまま押せる → サーバが断る。`pageshow` で印を下ろすのは bfcache に入れるブラウザのため（合成の `pageshow` で確かめた）
- 本番のキャッシュは file ドライバで、`FileStore::add()` が排他ロックを取ってから書くので、ほぼ同時の 2 回でも片方しか通らない（2026-09-25 に本番で確かめた。`OneTimeAction` の docblock）。同じ file キャッシュで同じ鍵を 24 プロセスが一斉に使うと、通るのは 1 本だった（3 回とも。2026-09-27 のレビューで実測）

### 範囲外（気づいたが直していない。設計書 §6 ＋ 試作・レビューで気づいたもの）

- ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信（決裁の取込は対策済み。ほかは未実測）（→ 2026-09-28 に直した。ZEAL の本部 Sheet 取込も含む。下の「ほかの取込の二重送信を止める」の節）
- 都道府県か市区町村が空欄の行が重複候補にならない件（前回からの範囲外・未実測）（→ 2026-11-03 に直した。Bug #123）
- 書き込みに失敗したあと、同じ確認画面から送り直すこと（アップロードし直す）
- 12 時間（`config('approval.guide_token_ttl_hours')` を共用）より古い確認画面からの送り直し（鍵の記録が消えたあとは重複の確認に頼る）
- 別々のプレビュー（2 つのタブ）で同じ CSV をほぼ同時に確定すると、鍵が別なので両方通り、同じ人が 2 回入りうる（案 A が守るのは同じ確認画面の 2 回目。閉じるのは設計書 §3 の D 案＝DB の一意制約だけ。順に届けば 2 回目は 0 件の案内に着く。2026-09-27 のレビューで気づいた・未実測）
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 画面の歯止めが効かないとき（JS が動かない・Alpine の起動前）の 2 回目の応答で、1 回目の完了の表示が見えないこと
- 送信を中止（Esc など）すると、押せないボタンと「取り込んでいます…」が残る（試作で実測。「← やり直す」で抜けられる。ページを開き直すか、アップロードし直す）
- 送信中に「重複候補もインポートする」のチェックを外すと、包む要素ごと「取り込んでいます…」が消える（見た目だけ。送信は始まっている）
- `LoginGuideTest::test_the_token_is_claimed_atomically` の docblock が本番のキャッシュを database と書いている（本番は file。`OneTimeAction` の docblock は 2026-09-25 に直してある）（→ 2026-09-28 に直した。下の「ほかの取込の二重送信を止める」の節）
- 実ブラウザの確認は Chromium（Playwright）だけ。POST の確認画面を bfcache に入れるブラウザ（Safari・Firefox かどうかは未確認）での「戻る」は、合成の `pageshow` で代わりに確かめた。Firefox・Safari で読み込みが終わる前に押したとき、初回の `pageshow` で印が下りるかも未確認（Chromium では起きないことをレビューで実測。起きても 2 回目はサーバの鍵が断る）
- 375px でプレビューの件数の帯が「重複候 / 補:」のように文字の途中で折れる（以前からの見た目。今回は変えていない）

### 検証

- 全件テスト 2346 → **2355 tests / 15346 assertions green** ／ コンパイル済みビュー **274 本 / INVALID 0 件**（レビューの対応のあとにも流し直して同じ）
- 変異 29 通り＋カナリア（計画の Task 4。隔離した worktree 3 つ・全件で流す）: **すべて計画の表の期待どおり**（落ちたテストの集合・本数・理由の 1 行目まで。K04 は等価で 0 本）。カナリアは 3 コピーとも同じ 23 本。期待と違ったものは無く、テストは足していない。レビューのあと T3 を直したので、T3 が検出役の K05・K06 を当て直し、表と同じ（K05 は T3 の 1 本・K06 は 18 本）
- ローカルの実ブラウザ（計画の Task 5。使い捨て SQLite ＋ `artisan serve`（`CACHE_STORE=file`）＋ Playwright の Chromium）: 10 項目すべて期待どおり —— ダブルクリックで確定の POST は 1 回・「2件のインポートが完了しました。」・DB は 2 行 ／ 確定の応答を 3 秒遅らせると、押した直後にボタンが `disabled`・カーソル `not-allowed`・不透明度 0.6・「取り込んでいます…」 ／ 完了のあと「戻る」は `back_forward` で、戻った時刻にサーバへの要求は favicon だけ（控えから描き直し）・押すと鍵の案内が出て DB は増えない ／ 合成の `pageshow` で印が下りて、また押せる ／ 1440px・375px とも `main` の横スクロール 0・375px で「取り込んでいます…」は割れずに 1 行でボタンの下 ／ コンソールのエラー・警告 0 件
- 独立レビュー（計画の Task 6。fable）: 判定「**はい（マージしてよい）**」。Critical・Important は 0 件、Minor 3 件（①`LoginGuideTest` の `claimFrom()` の件数の下限が 5 のままで、決裁の 1 か所を消しても緑 ②T3 が別の CSV で 2 回プレビューしていて、鍵を CSV の中身から作る書き換えを見逃す ③文書の誤り 3 か所）。3 件とも実測で再現してから直した（①②は、直す前は変異が緑・直した後は赤になることを確かめた。②はレビュー役の直し方の案だと 2 回目が重複候補になるので、書き込みに失敗したあと同じ CSV を上げ直す形にした）。判断を控えた 5 件は、範囲外（上）と計画の実測記録に書いた

### 本番反映（2026-09-28 実施）

利用者の承認のあと、計画の Task 8 の手順どおりに流した。直前に worktree（`df213c7b`）で全件テスト **OK (2355 tests, 15346 assertions)**。`13.x` = **`df213c7b`**（`f61290be` から早送り）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | `13.x` と本番の差 | 本番のコード `a7335c27` から変わるアプリのファイルは 3 本だけ（`a7335c27..f61290be` は docs だけ）／ `approval-phase2a` は `13.x` にもこのブランチにも入っていない ／ main repo に `vendor/bin/phpunit` と `.superpowers/sdd` は無い ／ 無視設定のファイルの一覧（220 行）は `deploy.sh` の直前にも同じ |
| ① 早送り | `13.x` | `f61290be` → `df213c7b`・作業ツリーは空 |
| ② `./deploy.sh` | 2026-09-28 9:00:39〜9:00:50（日本時間）| exit 0・6 段すべて。送ったアプリのファイルはコントローラ・`OneTimeAction.php`・ビューの 3 本とビルドだけ ／ CSS の名前が変わり（`app-D-wd4D2y.css`）、旧 CSS `app-wzJ6Tjji.css` を 2 か所で削除 ／ JS は名前そのまま（`app-NiVQbl_Q.js`）／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 本番の読み取り | 3 ファイル | 手元と **md5 が一致** |
| | コンパイル済みビューの `php -l` | **274 本 / INVALID 0 件** |
| | `bootstrap/cache` と記録 | 部品の名簿 2 つが作り直され（700）、`config.php` は 600 ／ 記録（`single`・`error` 以上）の `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 |
| | 本番の CSS（ログイン不要）| `200 text/css`・手元のビルドとバイト一致 ／ `.disabled\:opacity-60`・`.disabled\:cursor-not-allowed` のルールがある ／ 旧 CSS は `302 text/html`（`text/css` では返らない）|
| | 取込画面（ログイン済みの実 Chrome・1440px・見るだけ）| 初期フォーム（部署のラジオ「住宅事業」「不動産事業」・ファイル欄・「アップロードしてプレビュー」）が出る ／ 読み込んだ CSS に新しい 2 つのルールがある ／ 画面の `<script>` に今回の `onSubmit`・`submitting` が載っている ／ `main` の横スクロール 0 ／ 開き直してコンソールの出力 0 件 |

⚠ 確定の欄（hidden の `import_token`・二度押し止め・「取り込んでいます…」）はプレビューのあとにしか描かれないので、本番では見ていない（ファイルを上げていない・フォームを送っていない）。二度押し止めと鍵は、テスト（`CustomerImportTest` 29 本）とローカルの実ブラウザで確かめてある。本番で実際に取り込む（確定まで）のは利用者。

⚠ `origin/13.x` への push はしていない。→ 2026-09-28 に push 済み（`3e8a43aa` に含まれる）。

---

## ✅ 日本語だけで拡張子の無い名前の添付を開くと 500 になる件（基幹の添付）— 本番反映済み

- きっかけ: 決裁申請 段階2a の添付の点検（2026-09-28）で同じ 500 を再現し、基幹の添付も同じ作り（`App\Support\AttachmentDelivery` が Content-Disposition を Laravel に任せる）なので調べた。docs/RULES.md の Bug #68・CLAUDE.md の Top traps #21
- 症状: 「見積書」「😀」「Ａ４図面」のように、半角の英数字も拡張子も無い名前の添付は、保存できるのに、開くのもダウンロードも 500（添付・分譲地の図面・建売・注文住宅の 4 か所）
- 直し: `ed1f2f7f`（`AttachmentDelivery::make()` で古いブラウザ用の代わりの名前を自分で作り、作れなければ「attachment.保存先の拡張子」。今まで開けていた名前の見出しは 1 文字も変えない）・記録 `e4e48188`
- テスト: `AttachmentDeliveryTest` の T10〜T15・`AttachmentNameRoundTripTest`（4 か所の入口から本物のファイルで保存して開く）。worktree `attachment-filename-fallback` で全件 **OK (2362 tests, 15396 assertions)**。変異 12 通りで、緑は等価な 2 通りだけ

### 本番反映（2026-09-28 実施）

利用者の承認のあと、`13.x` を `ccd687fe` → **`e4e48188`** に早送りして `./deploy.sh` を流した。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**。

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | 本番（`df213c7b`）との差 | 変わるアプリのファイルは `app/Support/AttachmentDelivery.php` の 1 本だけ ／ main repo に `vendor/bin/phpunit` は無い ／ 無視設定のファイルの一覧は前回の反映と同じ 220 行 |
| ① 早送り | `13.x` | `ccd687fe` → `e4e48188`・作業ツリーは空 |
| ② `./deploy.sh` | 2026-09-28 11:53:00〜11:53:09（日本時間）| exit 0・6 段すべて。送ったアプリのファイルは `AttachmentDelivery.php` とビルドだけ（CSS・JS の名前は前と同じ）／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 本番の読み取り | `AttachmentDelivery.php` | 手元と **md5 が一致** |
| | コンパイル済みビューの `php -l` | **274 本 / INVALID 0 件** |
| | 記録と `bootstrap/cache` | `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ `config.php` は 600・部品の名簿 2 つは 700 |
| | 本番の添付（読むだけ・件数だけ）| 添付 52・図面 4・建売 1・注文住宅 0 件のうち、直す前は開けなかった名前は **0 件** |

⚠ 本番で実際に「見積書」のような名前のファイルを上げて開くことはしていない（本番のデータを増やさない）。テストと、ローカルの本物のファイルで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-09-27 に push 済み（`f98dc15e` に含まれる）。

⚠ 範囲外で気づいたこと（直していない）: ① 図面・建売・注文住宅はブラウザが申告した種類を保存するので、拡張子の無い PDF は「開く」でもダウンロードになる ② テナントの契約・修繕・投資の登録・編集の添付は種類を検査していない（`file|max:10240` だけ。配信側の許可リストと `nosniff` で守られている）③ `deploy.sh` は main repo の `.superpowers/`（作業のメモ）を除外していないので、本番の `~/apps/manage` に送られる（Web からは見えない）

---

## ✅ ほかの取込の二重送信を止める — 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-28-import-double-submit-design.md
実装計画（試作・カナリア・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-28-import-double-submit.md

上の「顧客CSVインポートの二重送信を止める」の範囲外に残した「ほかの取込の二重送信」を直した（docs/RULES.md Bug #69）。
利用者の判断（2026-09-28）: 16 経路すべてを顧客の取込と同じ形にする（案 A）・シート取込は確認画面で見せた内容だけを書く（案 a）・画面の二度押し止めは共通の部品 1 つ（案 1）・同じファイルの上げ直しは範囲外（案 C）・`LoginGuideTest` の docblock の誤りはこの作業の別のコミットで直す（案 A）。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` は要らない。CSS も変わらない）。

| 区分 | 実装内容 |
|------|---------|
| Controller | テナント・賃貸マンション（`loadCsv()` の確定の分岐の最初で鍵・タブごとの確かめる画面の表）・ZEAL 会員・工程表・周辺ビル（鍵は取込の画面の `form()` で出す）・シート取込（鍵のあとで指紋 `planDigest()`・合わなかったときの理由は `planChangedMessage()`）|
| Blade | 新しい部品 `_partials/_submit_once`（`submitOnce()` の唯一の定義）＋ 6 つの確定の画面に hidden の `import_token`（シート取込は `plan_digest` も）と二度押し止め |
| Support | `OneTimeAction` は docblock だけ（処理は変えない）|
| テスト | 新規 `TenantImportDoubleSubmitTest`・`MansionImportDoubleSubmitTest`・`SheetImportTest`・`SubmitOnceTest`・`ImportControllerOneTimeKeyScanTest`・`Tests\Concerns\ChecksDoubleSubmit` ／ ZEAL 会員・工程表・周辺ビルの既存のテストに追加・手で組んだ確定に鍵 ／ テスト用スキーマを本部 Sheet 取込の DDL に合わせた。2765 → **2838 tests / 20265 assertions green**（2765 は `13.x`（決裁 段階2a）を取り込んだあとの本数）|
| ルート / DB | **どちらも変更なし** |

### 直したこと

| 取込 | 直す前（2026-09-28 に使い捨てのテストで実測）| 直した後 |
|---|---|---|
| テナントの契約・過去契約／賃貸マンションの部屋契約・駐車場契約 | 同じ確認画面から 2 回送ると 2 件が 4 件 | 2 回目は「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは〈タブごとの画面〉で確かめられます。…」。取り込みは 1 回 |
| 周辺ビルのテナント明細 | 3 件が 6 件 | 2 回目は「この取込画面からは取り込めません（…）」。送ったあと「戻る」で戻った画面は読み込み直して新しい鍵になる |
| シート取込 | 履歴が二重・プレビューのあとで本部 Sheet が変わると見せていない値を書く | 2 回目は鍵の案内・内容が変わっていたら「プレビューのあとで反映する内容が変わりました（…）。もう一度プレビューしてください。」・反映のときに本部 Sheet を読み直せなかったら「プレビューのあとで売上 Sheet を読み直せませんでした（理由）。時間をおいて、もう一度プレビューしてください。」 |
| そのほかの 10 経路 | 二重にはならないが、2 回目にも成功の帯（「0件を登録しました」など）| 2 回目は鍵の案内 |
| 送信中 | 何も変わらない | ボタンが押せなくなり（不透明度 0.6・周辺ビルは 0.5・カーソル not-allowed）、横に「取り込んでいます…」（シート取込は「反映しています…」）|

### 要点

- 鍵は確定の入口で、CSV の読み直し・入力チェック・行の検査より前に使う（2 回目がどの検査にも別の文言にも届かない）。シート取込は鍵を先に使ってから指紋を比べる
- 周辺ビルは確認画面が無いので、取込の画面を開いたときに鍵を出す
- 戻り先と文言は取込ごと（テナントの過去契約は「契約一覧」でステータスを「解約済み」にして確かめる、と案内する）
- 画面の歯止め（Alpine）が効かないとき（JS が動かない・Alpine の起動前）も、サーバの鍵が 2 回目を断る。本番のキャッシュは file ドライバで、`FileStore::add()` が排他ロックを取ってから書く
- シート取込で反映のときに本部 Sheet を読み直せない（一時的な障害）と、指紋が合わず断る。そのときは読み直せなかった Sheet の名前と理由を出す（独立レビューの指摘。それまでは「内容が変わりました（…値が変わっています）」と事実と違う理由だった）。⚠ 読めないこと自体では断らない — プレビューのときも読めなかった Sheet は、見せた内容と同じなので通す

### 範囲外（設計書 §6・§7 ＋ 試作・レビューで気づいたもの）

- 同じファイルを上げ直したときの二重（契約系の 4 経路。テナントの過去契約は注意すら出ない。案 C）。確認画面（POST の応答）を読み込み直してプレビューを送り直したときも同じ形（新しい鍵が出る）（→ 2026-09-29〜30 に直した。登録済みの同じ契約の行をスキップする。下の「契約の上げ直しで二重にしない」の節）
- まだ送っていない古い確認画面で、取り込める行が 0 件なのに「0件を登録しました」と成功の帯が出ること（テナント・賃貸マンション）
- 2 つのタブで別々にプレビューした同じファイルを、ほぼ同時に確定すること（鍵が別なので両方通る。シート取込は、順に届けば指紋で止まる）
- 画面の歯止めが効かないときの 2 回目の応答で、1 回目の完了の表示が見えないこと
- 送信を中止（Esc など）すると、押せないボタンと送信中の文字が残ること（取込の画面を開き直せば抜けられる）
- 12 時間より古い確認画面（鍵の記録が消えたあとは、それぞれの重複の確認に頼る）
- シート取込で一時的な障害のために断られたときも、鍵は使ったことになる（もう一度プレビューする。設計書 §4.2）
- 顧客の取込の画面の書き方を、共通の部品にそろえること。決裁の画面にも別の二度押し止めの部品がある（`approvals/_submit_once`・`approvalSubmitOnce()`。決裁 段階2a が足した）
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 設計書 §7 の推測（テナントで同じ区画に契約中が 2 本並ぶと区画の収支が 2 倍・ほぼ同時の 2 回・ZEAL 会員の同姓同名＋同じ入会日・工程表の修正の消失）と、ZEAL 会員のテストのスキーマに `settings` 表が無いこと
- 周辺ビルで、Chromium は「戻る」で bfcache を使わず画面をサーバから取り直すので、部品の読み込み直しは 1 回余計になる（害は無い。HTTP のキャッシュから描くブラウザのための備え）

### 検証

- 全件テスト 2765 → **2838 tests / 20265 assertions green**（2765 は `13.x`（決裁 段階2a）を取り込んだあと。この作業で +73 本＝計画の 69 本＋独立レビューの修正の 4 本）／ コンパイル済みビュー **283 本 / INVALID 0 件**（計画は 275 本。決裁 段階2a で本番と同じ 282 本になり、この作業の部品で +1）
- 確定の入口で必ず断る一時変更（計画の Task 11）: 入口に来た 136 本がすべて赤・緑のまま 0 本・入口に来ずに赤 0 本（計画の期待どおり）。独立レビューの修正のあとの最終のコミットでも、入口に来た 140 本（本部 Sheet のテストが 4 本増えた）がすべて赤・緑のまま 0 本
- 変異 71 通り＋カナリア（計画の Task 12。隔離した worktree 3 つ・全件で流す）: カナリアは 3 つとも表どおりの 12 本が赤・71 通りすべて表どおり（落ちたテストの集合・本数・理由）。緑は等価な 3 通り（K20・D07・T02）と PHP から見えない 1 通り（UZ04＝ブラウザで見た）だけ。J11 は突き合わせの道具が理由の 1 行目しか持っていなかっただけで、全文で表どおり。独立レビューの修正のあとで、その場所に当たる 20 通りと新しい 5 通り（レビュー役の案の「読めない Sheet があれば断る」・URL の条件を外す・文言を元に戻す・`:disabled="!submitting"`・`@include` を `@if(false)` で包む）を当て直し、25 通りすべて期待どおり
- ローカルの実ブラウザ（計画の Task 13。使い捨て SQLite ＋ `artisan serve` ＋ Playwright）: 9 項目すべて期待どおり（テナントのダブルクリックで確定の POST は 1 回・押した直後に押せない＋「取り込んでいます…」・「戻る」はサーバへ送り直さず、押すと鍵の案内 ／ 周辺ビルは開いた直後は押せず、プレビューで押せる・「戻る」で読み込み直して新しい鍵 ／ 賃貸マンションの入れ子 ／ 375px で 6 画面とも横スクロール無し・送信中の文字は割れずに 1 行 ／ ZEAL 会員の `flex-wrap`（外すと 2 行に割れる）／ 1440px ／ コンソールの警告・エラー 0 件）
- 独立レビュー（計画の Task 14。fable）: 判定「マージしてよい」・Critical 0・Important 0・Minor 3。3 件とも実測で再現してから直した（シート取込の読み直せなかったときの文言・部品の script が画面に描かれているかを見るテスト・`:disabled` の式を先頭で見るテスト。docs/RULES.md Bug #69）。判断を控えた 7 件は範囲外（上）と計画の実測記録に書いた

### 本番反映（2026-09-29 実施）

利用者の承認のあと、計画の Task 16 の手順どおりに流した（案 A）。最後の全件テストは `420079bf`（コードは `b5576d7e` と同じ。そのあとは文書だけ）で **OK (2838 tests, 20265 assertions)**。`13.x` = **`b5576d7e`**（`071f028c` から早送り）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | `13.x` と本番の差 | 本番（`b28881d8`＝決裁 段階2a）から変わるアプリのファイルは 14 本だけ（`b28881d8..071f028c` は文書と `deploy.sh` の除外 1 行で、`deploy.sh` 自身は rsync の対象外）／ main repo に `vendor/bin/phpunit` は無い ／ 無視設定のファイルの一覧は前回の反映と同じ 220 行 |
| ① 早送り | `13.x` | `071f028c` → `b5576d7e`・作業ツリーは空 |
| ② `./deploy.sh` | 2026-09-29 8:48:40〜8:48:52（日本時間）| exit 0・6 段すべて。送ったアプリのファイルはコントローラ 6・`OneTimeAction.php`・ビュー 7 の 14 本とビルドだけ ／ `.superpowers` の除外（`dfdf95c4`）を足してから初めて流した `deploy.sh` で、`.superpowers` は送られていない ／ CSS・JS の名前は前と同じ（`app-DW1DvPK7.css`・`app-NiVQbl_Q.js`）で旧バンドルの削除 0 件 ／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 本番の読み取り | 14 本 | 手元と **md5 が一致**（14/14）|
| | コンパイル済みビューの `php -l` | **283 本 / INVALID 0 件** |
| | 記録と `bootstrap/cache` | `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ `config.php`・`routes-v7.php` は 600・部品の名簿 2 つは 700（8:48 に作り直し）／ 本番の `~/apps/manage` に `.superpowers` は無い |
| | 取込の画面 6 つ（ログイン済みの実 Chrome・見るだけ）| テナント（アップロードのフォーム 5）・賃貸マンション（6）・ZEAL 会員（1）・工程表（建売物件 9 の詳細の「工程表を取り込む」から）・試算表（2026 年度）・周辺ビルがすべて 200・初期フォームどおり・`main` の横スクロール 0・コンソールのエラー 0 件 ／ 周辺ビルは確定のフォームの `x-data` が `submitOnce({ reloadOnReturn: true })`・hidden の `import_token` が 40 文字・部品の `submitOnce` が定義済み・ボタンは押せない状態（`not-allowed`・`0.5`・包む span の `title` が「取り込める行がありません。」）|

⚠ 確認画面の二度押し止めと鍵（テナント・賃貸マンション・ZEAL 会員・工程表・シート取込）はプレビューのあとにしか描かれないので、本番では見ていない（ファイルを上げていない・フォームを送っていない）。テストとローカルの実ブラウザで確かめてある。本番で実際に取り込む（確定まで）のは利用者。

⚠ 試算表（2026 年度）は本部 Sheet の URL が未設定なので、「本部 Sheet を取り込む」のボタンは出ない（URL があるときだけ出る作り）。シート取込を本番で使うのは、「Sheet URL 設定」で URL を入れてから。

⚠ `origin/13.x` への push はしていない。→ 2026-09-29 に push 済み（`134523fb` に含まれる）。

---

## ✅ 契約の上げ直しで二重にしない — 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-09-29-contract-reimport-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-29-contract-reimport.md

上の「ほかの取込の二重送信を止める」の範囲外に残した「同じファイルを上げ直したときの二重」（案 C）を直した（docs/RULES.md Bug #70）。
利用者の判断（2026-09-29）: 登録済みと同じ契約の行は自動でスキップ（質問 1 の案 1）・見分けは「どこ＋誰＋開始日」（案 A）・各経路の行の検査に照合を足す（案 1）。
**本番の DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` は要らない。CSS も変わらない。migration はテスト用で本番では流さない）。

| 区分 | 実装内容 |
|------|---------|
| Controller | テナント（契約・過去契約。照合は `findRegisteredContract()` を共用）・賃貸マンション（部屋契約・駐車場契約。`findRegisteredRoomContract()`・`findRegisteredParkingContract()`）に CSV 内の重複・照合・行ごとの警告。駐車場契約は日付の検査を金額の前へ |
| Blade | 確認画面の共通部品 2 つに灰色の文 ／ 取込の画面 2 つの契約の 4 タブに説明を 1 行ずつ |
| テスト用スキーマ | `contracts.customer_id` を本番どおり空欄可（作成の migration の行を直した）|
| テスト | 新規 `TenantContractReimportTest`（63 本）・`MansionContractReimportTest`（70 本）・`ImportControllerContractMatchScanTest`（33 本＝検出器の見本 30 を含む）／ `TenantUnitImportTest` にスキーマのカナリア 3 本 ／ `SubmitsImportPreview` に `assertPreviewSkipsEveryRow()`。2838 → **3007 tests / 21309 assertions green**（PHP 8.3.35。最終レビューの修正で +39 本）|
| ルート / 本番の DB | **どちらも変更なし** |

### 直したこと

| 場面 | 直す前 | 直した後 |
|---|---|---|
| 同じ CSV を上げ直す | 契約が二重に入る（テナントの過去契約と賃貸マンションの退去済みの行は注意も出ない）| 全部の行が「…は既に登録済み（契約番号 または 既存契約 ID）のためスキップ」・灰色の「すべての行が登録済みです（スキップ N 件）。取り込む行はありません。」・確定のボタンは出ない |
| 行を足した CSV を上げ直す | 全部の行がもう一度入る | 新しい行だけが入る（「※ 既存データ（N件）はスキップされます」）|
| 賃料を改定・解約したあとで古い CSV を上げ直す | 二重に入る | スキップ（賃料・状態・解約日は見分けに使わない。登録済みの契約は書き換えない）|
| CSV の中に同じ契約が 2 行 | 両方入る | 2 行目が「CSV内で行N と同じ契約が重複しています」のエラー |
| スキップ・エラーの行の警告 | 画面の警告の一覧に残る | 取り込む行の警告だけ出す |

### 要点

- 照合は日付の検査のあと・金額の検査の前（登録済みの行は金額に誤りがあってもスキップ・日付に誤りがあればエラー）。確定でも同じ検査をやり直す（プレビューのあとで同じ契約が登録されていれば、確定でスキップされる）
- 日付は正規化したあとの `Y-m-d` を `whereDate` で比べる（`2026/4/1` と `2026-04-01` は同じ）。賃貸マンションの空欄の日付は空欄どうしだけが同じ
- 過去契約で顧客がまだ無い行は照合しない（自動作成の予定の顧客）。2 回目の上げ直しでは、1 回目が作った顧客で照合されてスキップされる
- 削除した契約とは突き合わせない（取り込み直せば入る）

### 範囲外（設計書 §6・§7 ＋ 試作・最終レビューで気づいたもの）

- 周辺ビルのテナント明細の上げ直しの二重（見分ける手がかりが弱く、作りも別）（→ 2026-10-02 に直した。下の「周辺ビルのテナント明細の上げ直しで二重にしない」の節）
- 登録済みの契約を CSV の値で書き換えること（スキップするだけ）
- 同じキーの契約がすでに 2 件以上あるとき（以前の二重送信の名残）の片づけ（照合は id のいちばん小さいものを理由に出すだけ。本番は反映の前に数えて、テナントの契約に 1 組あった〈削除していない契約 127 件のうち。2026-09-30〉。その 1 組は利用者が同日に片づけた〈下の「本番反映」〉。今は 0 組）
- 画面から契約を登録するときの重複の確かめ
- 2 つのタブで別々にプレビューした同じ CSV を**ほぼ同時に**確定すること（照合は読むだけで一意制約が無い。順に届けば 2 つ目はスキップ）
- 確定のときに全部の行がスキップになると「…インポート完了: 0件を登録しました」と成功の帯が出ること（Bug #69 §6 と同じ形）
- 削除した顧客の名前の行（契約タブは今までどおりエラー・過去契約タブは新しい顧客を作って取り込む）・まだ無い顧客の名前の表記ゆれ
- **同名の顧客が 2 件あるとき**（最終レビューの M3）: 契約タブ・過去契約タブは `Customer::where('name', …)->first()`（並び順の指定なし）で最初の 1 件を黙って選ぶので、2 件目の顧客に紐づく登録済みの契約は照合されず、上げ直すと二重に入る（探りで再現）。今回より前からの引き方で、取込自身は 2 件目の顧客の契約を作れない（食い違うのは画面で 2 件目の顧客に紐づけた契約だけ）。賃貸マンションは同名の入居者を「特定できません」のエラーにしている → 別の作業でそろえる（少なくとも `orderBy('id')` で決定的にする）（→ 2026-09-30 に照合を直した。下の「同名の顧客の契約も上げ直しで二重にしない」の節）
- 過去契約の「顧客 N件を自動作成」の数え方（金額の誤りでエラーになった行の顧客まで数える）（→ 2026-11-16 に直した。下の「過去契約の取込の「顧客 N件を自動作成」を取り込む行から数える」の節）
- テスト用スキーマの `rent_start_date` の食い違い（→ 2026-10-29 に空欄可にした。下の「テスト用スキーマの契約の家賃発生日を本番どおり空欄可にする」の節）
- 行ごとに警告を貯める形を、ほかのタブへ広げること
- 完了の文にスキップの件数を足すこと
- 設計書 §7 の読み取り（物件・顧客・入居者の重複の文の閉じ括弧が半角・賃貸マンションの取込の画面の説明が実際と違う 3 か所・「※ 警告のある行（N件）」は行の数でなく警告の数・過去契約の期間の重なりの確かめはテストの SQLite では境目で本番と結果が違いうる）
- テナントの照合の `orderBy('id')` は SQLite では外しても結果が変わらない（変異 F25 は等価）。本番の MySQL での順序を決めるために残す
- 最終レビューのあとに残した軽い指摘（直さない）: 走査の正規表現の空白の量が固定されていないこと（変異 S16・S17 が緑。見本が 0〜1 個だけで害は小さい）／ 注記の言い過ぎ 2 件（`TenantContractReimportTest.php:132`・`MansionContractReimportTest.php:135` の「灰色の一覧に全文…」はアサートが画面全体を見ている。`ImportControllerContractMatchScanTest.php:30` の注記は、照合のメソッドの名前が文字列の中にあるだけで呼んだことになると書いているが、実際に見るのは `$this->findRegistered…(` の形）
- 環境: 手元の既定の `php`（`/opt/homebrew/bin/php`）は 2026-09-30 の時点で PHP 8.5.11 を指しており、全件テストが `OK, but there were issues!`（Deprecations: 2）になる。既存の `app/Support/CsvImportReader.php` の `str_getcsv()` の `$escape`（8.4 以降の警告）で、今回の変更とは無関係・8.3 では出ない。最終レビューの修正のあとの全件と 2 回目の変異テストは 8.3（`PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"`）で流した（9/29 の実行は 8.3 だった可能性が高い＝Task 0・5 の全件の最終行に Deprecation が出ていない）。本番は 8.3。8.4 以降へ上げる日に `$escape` を明示する直しが要る

### 検証

- 全件テスト 2838 → **3007 tests / 21309 assertions green**（PHP 8.3.35。修正の前は 2968 / 21234 で、最終レビューの修正で +39 本・+75）／ コンパイル済みビュー **283 本 / INVALID 0 件**（修正の前の測定。修正はテストだけでビューは変えていない）
- 変異（計画の Task 6。隔離した worktree 3 つ・docs/RULES.md Bug #44 / #50 の作法。2 回測った）:
  - 1 回目（`3b563cf4`・全件テスト）: カナリア C0 は 3 つとも同じ 13 本が赤（隔離が効いている）。100 通りを 34・33・33 に分けて流し、**101 通り（100 通り＋カナリア）すべてが表と一致**した（落ちたテストの集合・データセット・理由の 1 行目まで。表に無いクラスのテストは 1 本も落ちなかった）。緑は F25（等価）と S04（対照）だけ
  - 2 回目（`4491f130`＝最終レビューの修正のあと・関係するテスト 20 本＝`--narrow`）: 修正でテストを足したので取り直した。元の 101 通りは、94 通り（C0 を含む）が前と同じ・7 通り（D08・F15・K04・O06・R04・S05・W09）は足したテストの行が加わって落ちる本数が増えただけ（増えた 17 件はすべて修正で足したテスト）・食い違い 0。実行役に 22 通り（D21〜D23・O09・O10・S07〜S17・K05〜K08・Z03・Z04）を足し、122 通り＋カナリアを 41・41・40 に分けて流した: 20 通りは狙ったテストが狙った理由で赤、S16・S17（走査の正規表現の空白の量）は緑が正しい情報用（死角）。緑は F25・S04・S16・S17 のちょうど 4 つ。20 本に絞った理由は、1 回目の全件の結果が 20 本に絞った表と完全に一致し（20 本の外で落ちるテストは無かった）、修正は 20 本の中のテストファイルと、注記だけを直した 2 ファイル（`PropertyListSortTest.php`〔20 本の外〕・`SubmitsImportPreview.php`〔道具。使うテストはすべて 20 本の中〕）しか変えていないから
- ローカルの実ブラウザ（計画の Task 7。使い捨て SQLite ＋ `artisan serve`（`CACHE_STORE=file`）＋ Playwright）: 9 項目すべて期待どおり — テナントの契約タブで 2 件を取り込み、同じ CSV の上げ直しは「全 2 件」「正常: 0 件」「スキップ: 2 件」・理由の文が全文で出る・灰色（`rgb(107, 114, 128)`）の「すべての行が登録済みです（スキップ 2 件）。取り込む行はありません。」・赤字の文もボタンも無い ／ 行を足した CSV は「インポート実行（1件）」と「※ 既存データ（2件）はスキップされます」→ 1 件だけ登録（契約一覧で 1A・2A・3A 各 1 件。使い捨て DB の `contracts` は 3 行＝二重なし）／ 賃貸マンションの部屋契約も同じ（1 回目は「警告: 1 件」、上げ直しでは警告の件数も一覧も出ない。`ms_contracts` は 3 行）／ 契約の 4 タブすべてに足した説明の 1 行が出る ／ 375px で `main` の横スクロール無し ／ コンソールの警告・エラー 0 件。⚠ 計画の表の URL が誤りだった（`?selected_tab=room-contract` ではタブが 1 つも選ばれない。正しくは `room_contract`。計画を直した）。⚠ 375px でスキップの一覧の行は文字の途中（「C-2026-」と「001）」の間など）で折れ、一覧は最大の高さ 150px（`max-height: 150px`）のスクロール枠なので 2 行目が枠で切れて見える（枠はテナントの確認画面が 2026-04-13 の `99f281502` から、賃貸マンションの確認画面が 2026-04-21 の `63490f1c` からある。今回の変更ではない）
- 独立レビュー（計画の Task 8。fable）: 判定「**With fixes**」（マージ可）。アプリのコード（コントローラ 2・ビュー 4・migration 1）に Critical・Important は 0 件、Minor 9 件。M3（同名の顧客。上の範囲外）を除く 8 件 — テストの穴 2 つ（M1・M2。Bug #44 の型で、変異で緑を実測）・スキーマのカナリアの注記（M4）と文言（M6）・検出器の枝ごとの見本（M5）・駐車場契約のメモ・担当者の 2 行（M8）・警告の数の癖を固定している注記（M9）・テストの注記 2 か所（M7）— を 1 回の修正で直した（8 コミット・テストだけ・+39 本。アプリ・ビュー・DB・ルート・設定・文書は 0 差分）。範囲を絞った再レビューで 8 件すべて ADDRESSED・新しい重い問題なし。M7 の文書の部分（計画 Task 7 の URL・RULES の Bug #60 と BACKLOG の `customer_id` の記述・記録の数字）はこの記録で直した

### 本番反映（2026-09-30 実施）

利用者の承認のあと、計画の Task 10 の手順どおりに流した（決裁 段階2b が先に本番に出ていたので、CSS の名前とビューの本数の期待値は 2b のあとの値に読み替えた）。最後の全件テストは `205ff597`（コードは `e942596d` と同じ。そのあとは文書だけ）で **OK (3134 tests, 22347 assertions)**（PHP 8.3.35。`13.x` だけの 2965 / 21303 との差 +169 本・+1044 が今回の分）。`13.x` = **`e942596d`**（`a9732184` から早送り）。**本番の DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` も SQL も流していない。migration はテスト用で本番では流さない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前の本番の読み取り（Step 2）| 見分けのキーが同じ契約が 2 件以上ある組の数（2026-09-30 11:23）| **テナントの契約に 1 組**（削除していない契約 127 件のうち）・部屋契約 0 組・駐車場契約 0 組（賃貸マンションの契約は部屋・駐車場とも本番に 0 件＝確かめる契約が無い）。反映のあとで中身を読み取ると（11:58）、No.18ミツワビル 3A・契約日 2023-09-19 の同じ入居が 2 回登録されていた: id 46 `C-2026-046`（2026-05-12 に契約タブか画面で登録・6/16 に更新）と id 108 `C-2023-005`（6/17 に過去契約の取込で登録＝契約日の年の番号・更新なし）。どちらも解約済みで、40 列のうち違うのは初月・最終月の金額だけ（46 にだけ入っている）・関連データ（賃料改定・取引・投資・問合せ・添付）はどちらにも無い。**利用者が画面から id 108 を削除した**（13:43。論理削除）→ 読み取りで 0 組・削除していない契約 126 件・id 46 と区画 3A の状態はそのまま・エラー 0 件を確かめた（13:45）|
| 着手前の手元の読み取り | 本番（`3d18815d`）との差 | 変わるアプリのファイルは 7 本だけ（コントローラ 2・ビュー 4・テスト用の migration 1）／ main repo に `vendor/bin/phpunit` は無い ／ 無視設定のファイルの一覧は前回の反映と同じ 220 行 ／ worktree でビルドした CSS・JS は、今の本番（2b のビルド）とバイトまで同じ |
| ① 早送り | `13.x` | `a9732184` → `e942596d`・作業ツリーは空 |
| ② `./deploy.sh` | 2026-09-30 11:29:04〜11:29:13（日本時間）| exit 0・6 段すべて。送ったアプリのファイルはコントローラ 2・ビュー 4・migration 1 の 7 本とビルドだけ ／ CSS・JS の名前は前と同じ（`app-Cz5Vm3yg.css`・`app-NiVQbl_Q.js`）で旧バンドルの削除 0 件 ／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 本番の読み取り | 6 本（コントローラ 2・ビュー 4）| 手元と **md5 が一致**（6/6）|
| | コンパイル済みビューの `php -l` | **288 本 / INVALID 0 件** |
| | 記録と `bootstrap/cache` | `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ `config.php`・`routes-v7.php` は 600・部品の名簿 2 つは 700（11:29 に作り直し）／ 本番の build（2 か所）は `app-Cz5Vm3yg.css`・`app-NiVQbl_Q.js` の 2 本だけ |
| | 取込の画面 2 つ（ログイン済みの実 Chrome・見るだけ）| テナント・賃貸マンションとも 200 ／ 契約の 4 タブ（④ 契約・⑤ 過去契約・⑤ 部屋契約・⑥ 駐車場契約）を開くと、足した説明の 1 行が出る ／ `main` の横スクロール 0 ／ 読み込み直したときのコンソールのエラー 0 件 |

⚠ 確認画面（スキップの理由の一覧・灰色の「すべての行が登録済みです…」）はプレビューのあとにしか描かれないので、本番では見ていない（ファイルを上げていない・フォームを送っていない）。テストとローカルの実ブラウザで確かめてある。本番で実際に取り込む（確定まで）のは利用者。

---

## ✅ 同名の顧客の契約も上げ直しで二重にしない — 本番反映済み

上の「契約の上げ直しで二重にしない」の範囲外に残した「同名の顧客が 2 件あるとき」を直した（docs/RULES.md Bug #70 の追記）。
**利用者の決定（2026-09-30）**: 同名の顧客がいても、重複とみなすのは同じ区画・同じ契約日のときだけ。解約したあとで同じ人が再契約することがあるので、完全一致だけにする。
「特定できません」のエラーにする案（賃貸マンションの形）は採らなかった。
**DB 変更・ルート変更・画面の変更・新しい PHP クラス・依存の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\TenantImportController::findRegisteredContract()` — 顧客を id でなく名前で比べる（`whereIn('customer_id', Customer::where('name', …)->select('id'))`）。契約タブ・過去契約タブの呼び出しを名前渡しに |
| テスト | `TenantContractReimportTest` に 6 本（15 の節）。3134 → **3140 tests / 22385 assertions green**（PHP 8.3.35）|

### 直したこと（山田商事が 2 人いて、登録済みは「1A・2 人目・2026-04-01」）

| CSV の行 | 直す前 | 直した後 |
|---|---|---|
| 1A・山田商事・2026-04-01 | 取り込む（二重になる）| スキップ |
| 1A・山田商事・2026-05-01（解約したあとの再契約）| 取り込む | 取り込む |
| 2A・山田商事・2026-04-01 | 取り込む | 取り込む |

### 要点

- CSV のテナント名は `Customer::where('name', …)->first()` で同名の 1 人目に引き当たる。顧客の id で照合していたので 2 人目の契約を見落としていた
- 名前は、取込が顧客を引き当てるのと同じ比べ方（データベースの照合順序。本番の `utf8mb4_unicode_ci` は全角数字とアルファベットの大小を同じとみなす）
- 削除した顧客は数えない・削除した契約とは突き合わせない・テナント名が空欄なら「顧客の無い契約」と比べる・過去契約でまだ無い顧客の行は照合しない（どれも今までどおり）
- テストの前提（CSV のテナント名が同名の 1 人目に引き当たること）はテストの中で確かめる。崩れて 2 人目に引き当たると、直す前のコードでも緑になる

### 範囲外（変えていないこと）

- 新しく取り込む契約をどの顧客に紐づけるか（今どおり同名の最初の 1 人。並び順の指定なし）（→ 2026-10-01 に並び順を id に固定した。下の「同名の顧客の紐づけ先を最初に登録された顧客に固定する」の節）
- 賃貸マンション（同名の入居者は今どおり「特定できません」のエラー）
- 削除した顧客に紐づく登録済みの契約とは突き合わせない

### 検証

- 足したスキップのテスト 2 本（契約・過去契約）は、直す前のコードで「取り込む行が残っている」の理由で赤を確認してから直した
- 変異 4 通り（照合を顧客の id に戻す・名前の条件を外す・契約日の条件を外す・区画の条件を外す）で、狙ったテストが狙った理由で赤。名前の条件を外す変異は既存の「顧客が違う」のテストが捕まえる（新しいテストは「入る」側なので捕まえない）
- 全件 **OK (3140 tests, 22385 assertions)**（PHP 8.3.35）

### 本番反映（2026-09-30 実施）

利用者の承認のあと、main repo で `13.x` を `d28e36d0` → **`f5902b27`** に早送りして `./deploy.sh` を流した。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | main repo と本番との差 | 作業ツリーは空・`vendor/bin/phpunit` は無い・無視設定のファイルの一覧は前回の反映と同じ 220 行 ／ 本番（`e942596d`）から変わるアプリのファイルは `TenantImportController.php` の 1 本だけ |
| ① 早送り | `13.x` | `d28e36d0` → `f5902b27`・作業ツリーは空 |
| ② `./deploy.sh` | 2026-09-30 16:42:17〜16:42:39（日本時間）| exit 0・6 段すべて。送ったアプリのファイルは `TenantImportController.php` とビルドだけ ／ CSS・JS の名前は前と同じ（`app-Cz5Vm3yg.css`・`app-NiVQbl_Q.js`）で旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 本番の読み取り | `TenantImportController.php` | 手元と **md5 が一致**（`f8c4a6cc…`）・照合の 3 か所（呼び出し 2・`whereIn`）が新しいコード |
| | コンパイル済みビューの `php -l` | **288 本 / INVALID 0 件** |
| | 記録と `bootstrap/cache` | `laravel.log` は 6/18 から更新なし＝反映のあと（画面を開いたあとも）エラーは 0 件 ／ `config.php`・`routes-v7.php` は 600・部品の名簿 2 つは 700（16:42 に作り直し）|
| | 取込の画面（ログイン済みの実 Chrome・見るだけ）| テナントの取込の画面が契約タブ・過去契約タブとも 200 ／ 2 タブそれぞれに説明の 1 行（「同じ区画・テナント名・契約日の契約が登録済みなら…」）／ アップロードのフォーム 5 ／ `main` の横スクロール 0 ／ コンソールのエラー 0 件 |

⚠ 照合の違いはプレビューのあとにしか出ないので、本番では見ていない（ファイルを上げていない・フォームを送っていない）。テストで確かめてある。本番で実際に取り込む（確定まで）のは利用者。

⚠ 本番に同名の顧客が何組あるかは数えていない。（→ 2026-10-01 に読み取りで数えた: 0 組）

⚠ `origin/13.x` への push はしていない。→ 2026-10-01 に push 済み（`78999fb7` に含まれる）。

---

## ✅ 同名の顧客の紐づけ先を最初に登録された顧客に固定する — 本番反映済み

上の「同名の顧客の契約も上げ直しで二重にしない」の範囲外に残した「新しく取り込む契約をどの顧客に紐づけるか」を直した（docs/RULES.md Bug #70 の追記）。
**利用者の決定（2026-10-01）**: 同名の顧客が 2 人以上いても、新しい契約は最初に登録された顧客（id のいちばん小さい顧客）に紐づける。警告は出さない。
プレビューに警告を出す案・賃貸マンションと同じ「特定できません」のエラーにする案（再契約の行が取り込めなくなる）・記録だけにする案は採らなかった。
**DB 変更・ルート変更・画面の変更・新しい PHP クラス・依存の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\TenantImportController` — 契約タブ・過去契約タブで顧客を名前で引く 2 か所に `->orderBy('id')`（顧客タブは顧客がいるかどうかしか見ないので触らない。照合の `findRegisteredContract()` も変えない）|
| テスト | `TenantContractReimportTest` に 4 本（16 の節）。3140 → **3144 tests / 22421 assertions green**（PHP 8.3.35）|

### 要点

- 本番を読み取りで数えた（2026-10-01・利用者の承認のうえ）: 削除していない顧客 109 件のうち同名の組は **0 組**（削除済みの顧客も含めると 1 組。取込は削除済みの顧客を見ないので今は影響なし。復元すると同名の組になる）
- 同名の顧客ができるのは画面から登録したときだけ（顧客の取込は同名がいる行をスキップする）
- `customers.name` に索引が無いので、直す前の `first()` も MySQL では実際には id の順に返していた可能性が高い ＝ 画面の動きは変わらない見込み。今回は SQL として保証した
- ⚠ テストの SQLite は並び順を指定しなくても id の順に返すので、紐づけ先を見るテストは `orderBy` を消しても緑（等価変異）。流れた SQL を記録し、顧客を名前で引く問い合わせが 1 本以上あり、すべて `order by "id" asc limit 1` で終わることを見るテストを対で置いた

### 検証

- 足した SQL のテストは、直す前のコードで 2 タブとも「同名の顧客を引く並び順が id の順に決まっていない」で赤、紐づけ先のテストは緑（等価変異のとおり）を確かめてから直した
- 変異 4 通り＋カナリア（テナントの取込に触れるテスト 9 ファイル・155 本）: `orderBy` を消すと SQL のテストのそのタブだけが赤 ／ `orderByDesc` にすると紐づけ先と SQL のテストのそのタブが赤 ／ カナリア（契約タブで顧客が見つからない）は 36 本が赤。変異のたびに作業ツリーが空であることを確かめた
- 全件 **OK (3144 tests, 22421 assertions)**（PHP 8.3.35）

### 範囲外

- 賃貸マンション（同名の入居者は今どおり「特定できません」のエラー）
- 顧客の取込（同名がいればスキップ。並び順は関係ない）

### 本番反映（2026-10-01 実施）

利用者の承認のあと、main repo で `13.x` を `78999fb7` → **`1e52e132`** に早送りして `./deploy.sh` を流した。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | main repo | 作業ツリーは空・`vendor/bin/phpunit` は無い・無視設定のファイルの一覧は前回の反映と同じ 220 行 ／ 13.x と origin/13.x はどちらも `78999fb7` ／ 本番（`f5902b27`）から変わるアプリのファイルは `TenantImportController.php` の 1 本だけ |
| ① 早送り | `13.x` | `78999fb7` → `1e52e132`・作業ツリーは空 |
| ② `./deploy.sh` | 2026-10-01 13:47:01〜13:47:15（日本時間）| exit 0・6 段すべて。送ったアプリのファイルは `TenantImportController.php` とビルドだけ ／ CSS・JS の名前は前と同じ（`app-Cz5Vm3yg.css`・`app-NiVQbl_Q.js`）で旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）／ 部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 本番の読み取り | `TenantImportController.php` | 手元と **md5 が一致**（`7a9cb26e…`）・顧客を名前で引く 2 か所（776 行・1065 行）が `orderBy('id')->first()` |
| | コンパイル済みビューの `php -l` | **288 本 / INVALID 0 件** |
| | 記録と `bootstrap/cache` | `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ `config.php`・`routes-v7.php` は 600・部品の名簿 2 つは 700（13:47 に作り直し）|

⚠ 紐づけ先の違いは同名の顧客がいるときにしか出ず、本番は 0 組なので、画面では見ていない（ファイルを上げていない・フォームを送っていない）。テストで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-01 に push 済み（`b6e0fa49` に含まれる）。

---

## ✅ 周辺ビルのテナント明細の上げ直しで二重にしない — 本番反映済み

詳細仕様: @docs/superpowers/specs/2026-10-01-area-tenant-reimport-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-10-01-area-tenant-reimport.md

上の「契約の上げ直しで二重にしない」の範囲外に残した「周辺ビルのテナント明細の上げ直しの二重」を直した（docs/RULES.md Bug #71）。
取込は行を 1 行ずつ作るだけで登録済みの行と突き合わせず、同じファイルを上げ直すと行が二重に入っていた（3 件が 6 件に）。Bug #69 の鍵は同じ取込の画面からの 2 回目しか止めない。
**利用者の決定（2026-10-01）**: 上げ直す場面は ①同じファイル／一部が重なるファイル ②別のビルのファイルの追加 の 2 つ（③調査し直して現況を置き換える同期は範囲外）・同じ行はビル・階・部屋番号・テナント名が同じ行（業種・状態は見ない）・登録済みの行は書き換えない・退去済みの行とも突き合わせる・判定はサーバだけで完了のメッセージで知らせる・数で扱う。
**利用者の決定（2026-10-02・独立レビューの指摘を受けて）**: テナント名が空欄の退去済みの行（閉じた空き区画・不明）は照合に数えない。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し。CSS も変わらない。**

| 区分 | 実装内容 |
|------|---------|
| Controller | `Tenant\AreaBuildingImportController` — `buildingIdsInRows()`（ファイルに出るビル）→ `registeredTenantCounts()`（登録済みの行を 1 本の問い合わせで読み、キーごとに数える）・`tenantKey()`（`json_encode([ビル id, 階, normalizeName(部屋番号), normalizeName(テナント名)])`）・スキップ・完了のメッセージ・現況テナント数 |
| Blade | `tenant/area-buildings/import.blade.php` の注意書き（「二重になります」の強調を外し、登録済みと同じ行はスキップすることを書く）|
| テスト | 新規 `AreaBuildingTenantReimportTest`（27 メソッド・39 本）・`AreaBuildingImportTest` の 2 本の書き換え・送る部品を `AreaBuildingTestCase` へ移した。3213 → **3252 tests / 23239 assertions green**（PHP 8.3。3213 は 13.x〈決裁 3a〉を取り込んだあと）|
| ルート / DB | **どちらも変更なし** |

### 直したこと

| 場面 | 直す前 | 直した後 |
|---|---|---|
| 同じファイルを上げ直す（取込の画面は開き直す）| 行が二重に入る | 「テナント登録 0 件 / 登録済みのためスキップ N 件 / …」・行は増えない・現況テナント数も出る |
| 一部が重なるファイル | 全部の行がもう一度入る | 新しい行だけが入る |
| 別のビルのファイルを追加 | 入る | 入る（階・部屋番号・名前が同じでもビルが違えば別の行）|
| 退去済みの行と同じ行（名前あり）| 現況として入る | スキップ（退去したテナントが現況に戻らない）|
| 業種・状態だけが違う行 | 入る | スキップし、登録済みの行は書き換えない |
| 退去日を入れて閉じた空き区画（名前なし）と同じ階・部屋が、あとの調査でまた空いた | 入る | 入る（名前が空欄の退去済みの行は数えない）|

### 要点

- 行の検査の順番: ビル名が空 → 台帳に無いビル → 階が読めない（「値が不正」）→ 登録済みの照合 → 登録
- 数で扱う: キーごとに登録済みの行の数まではスキップし、超えた分は入れる（空き区画はファイルの中で並ぶのが普通）。この取込で入れた行は残りに足さない
- ⚠ キーは JSON（`|` などでつなぐと値の中の区切り文字で別の行が同じキーになる・階の null と 0 を区別する）・ファイル側の値は列の長さに切ってから比べる
- ⚠ 名前が空欄の退去済みの行を数えない判定は**名前で**行う（部屋番号で決めると名前のある退去済みの行が現況に戻る）。列を絞る `get([...])` に `moved_out_on` を入れる（落とすと黙って null になる。Bug #58 と同じ型）
- 問い合わせは先読みの 1 本だけ増える（20 棟 × 1 行で `area_building` 系 23 本。上限 25 のテストは変えていない）

### 範囲外（設計書 §6・§7 ＋ 計画の「範囲外」＋ 独立レビューの Minor）

- 調査し直して現況を置き換える同期（ファイルに無い行を退去にする・状態を更新する）。空き区画に入居したあとの新しい調査のファイルは、名前が違うので新しい行として入り、空き区画の行は残る（画面から退去日を入れるか削除する）
- プレビューにスキップの見込みを出すこと／スキップした行がどれかをメッセージに出すこと（件数だけ）
- 本番に今ある二重の行の片づけ（数で扱うので照合は壊れない）
- 画面から 1 件ずつ登録するときの重複の確かめ
- 2 つのタブでほぼ同時に取り込むこと（照合は読むだけで一意制約が無い。順に届けば 2 つ目はスキップ）
- 同じ区画に同じテナントが入り直したとき（名前のある退去済みの行と同じキーなのでスキップする。画面から追加する）
- 英数字の全角・半角や英字の大文字・小文字の表記ゆれを同じとみなすこと（階は全角数字を読むが、部屋番号は読まない）
- 同じ名前のビルが 2 棟とも削除されずにあるとき（`buildingMapByNormalizedName()` が id の小さいほうへ畳む＝ Bug #55 からの振る舞い）、2 棟目に登録済みの行が 1 棟目へ入る
- 設計書 §7 の読み取り: `nullableString()` は半角の空白だけを落とすので、全角空白だけのセルは `'　'` のまま保存される（照合は `normalizeName` が空欄と同じに扱う）／取込のプレビュー（JS）はサーバの登録済みの行を見ない
- 独立レビューの Minor（直していない）: 「画面で登録した行」のテストは HTTP の登録でなくモデルから作っている（レビューの探りで HTTP の経路でも照合することは確かめた）／「ビル名が空・台帳に無いビル」の判定が `buildingIdsInRows()` と `importTenants()` に逐語で 2 回ある（今は一致）／変異 F08（`tenantKey()` の引数の型を外す）は Feature テストの SQLite では測れないが、Reflection で文字列の数を渡す Unit テストなら測れる

### 検証

- 計画のコードは書く前に試作して測ってあり、新しいテストは直す前に全部赤（理由は「行が二重になった」か「完了のメッセージに `登録済みのためスキップ` が無い」）
- 全件 **OK (3252 tests, 23239 assertions)**（PHP 8.3）／ コンパイル済みビュー **294 本 / INVALID 0 件**（13.x の決裁 3a で 288 本から 6 本増えた）／ `vite build` の CSS は 13.x と同じ `app-C3HUCZpZ.css`（注意書きを元に戻してビルドしても同じ＝この作業は CSS を変えていない）
- 変異（docs/RULES.md Bug #44 の作法・実行役 `atr_mutate.py`）: 1 回目 23 通り（カナリアを含む）がすべて計画の表どおり・全件のカナリアも関係する 11 ファイルと同じ 73 本。独立レビューの修正のあとの 2 回目 28 通り（P05〜P09 を足した）がすべて流す前に書いた期待どおり。緑は等価の F08 だけ
- ローカルの実ブラウザ（使い捨て SQLite ＋ `artisan serve`〈`CACHE_STORE=file`〉＋ Playwright）: 6 項目すべて期待どおり（注意書き・1 回目「登録 3 件」・開き直して同じファイル「登録 0 件 / スキップ 3 件」で詳細画面も 3 行のまま・追加ビルの 1 行を足したファイル「登録 1 件 / スキップ 3 件」・375px で横スクロール無し・コンソールの警告とエラー 0 件）
- 独立レビュー（fable）: 判定「With fixes」・Critical 0・Important 1・Minor 4。Important（退去日を入れた名前なしの行が、あとの調査の同じ階・部屋の空き区画を吸い込む）を探りで再現し、利用者の決定（案 B）で直した（直す前に赤のテスト 4 本＋名前で判定することを守るテスト 1 本。注意書きにも例外を書いた）

### 本番反映（2026-10-02 実施）

利用者の承認（案 A＝早送り・反映の前の読み取り・`./deploy.sh`・読み取りだけの確認）のあと、計画の Task 9 の手順どおりに流した。直前の全件は worktree で **OK (3252 tests, 23239 assertions)**（PHP 8.3）。`13.x` = **`a32a766b`**（`7ae25fa3` から早送り）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 着手前の読み取り | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `7ae25fa3`・`vendor/bin/phpunit` は無い ／ 本番（`832d5dc3`＝決裁 3a まで）から変わるアプリのファイルはコントローラ 1 本とビュー 1 本だけ |
| ① 早送り | `13.x` | `7ae25fa3` → `a32a766b`・作業ツリーは空 |
| ② 反映の前の本番の読み取り | テナント明細（id と件数だけ）| `rows=0 current=0 moved_out=0`・`duplicate_groups=0`・`moved_out_blank_name=0`（確かめとしてビル 187 棟・`area_building_tenants` 0 行）＝本番にはまだテナント明細が無く、今ある二重の片づけも不要 |
| ③ `./deploy.sh` | 2026-10-02 10:15:18〜10:15:29（日本時間）| exit 0・6 段すべて。送ったアプリのファイルはコントローラ 1 本・ビュー 1 本とビルド（名前は前と同じ `app-C3HUCZpZ.css`・`app-NiVQbl_Q.js`）。旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ④ 本番の読み取り | 2 本 | 手元と **md5 が一致**（コントローラ `16e9274b…`・ビュー `4fbb5094…`）|
| | コンパイル済みビューの `php -l` | **294 本 / INVALID 0 件** |
| | 記録と `bootstrap/cache` | `laravel.log` は 6/18 から更新なし＝反映のあとのエラーは 0 件 ／ `config.php`・`routes-v7.php` は 600・部品の名簿 2 つは 700（10:15 に作り直し）|
| | 取込の画面（ログイン済みの実 Chrome・見るだけ）| 200 ／ 取込種別を「テナント明細」にすると新しい注意書きが全文どおり出る（強調なし・「二重になります」なし）／ `main` の横スクロール 0 ／ 開き直したときのコンソールのエラー 0 件 |

⚠ 実際に取り込む（スキップの件数が出る）のは利用者（こちらはファイルを上げていない・フォームを送っていない）。照合はテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-02 に push 済み（`91fe7969` に含まれる）。

---

## ✅ ZEAL 会員の取込の確定で DB の例外が出ると 500 になる件 — 本番反映済み

実装計画: `~/.claude/plans/starry-zooming-falcon.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #72）

上の「CSV 取込の確認画面から断られると 405 になる件」の範囲外に残した件（docs/RULES.md Bug #72）。
ZEAL 会員の取込（`/admin/zeal/member-import`）の確定は、書き込みを `DB::transaction()` で包むだけで例外を受け止めていなかった。使い捨てのテストで、2 人目の会員の登録で DB の例外を起こすと、画面は **500**、1 人目も含めて**全員が取り消される**（会員 0 件）ことを確かめた。
普段の操作で例外が起きる原因は**本番の列に入らない値**（行の変換が長さを確かめない・マイナスの月会費も通す）。23 桁の月会費はプレビューの時点で TypeError（500）だった。
**利用者の決定（2026-10-02）: A＋B**。**DB 変更・ルート変更・画面の変更・新しい PHP クラス・依存の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| B（行の変換）| `App\Support\Zeal\HacomonoMemberMapper` — 名前・名前カナ・メール 100 文字、電話番号 20 文字、郵便番号 8 文字、住所 300 文字を超える行と、契約の月会費が 0〜4294967295 に入らない行を、その行のエラーにする（プレビューのエラー行に出て、確定ではその行だけ飛ばす）。範囲外の金額は換算しない（23 桁の TypeError を止める）|
| A（確定）| `Admin\ZealMemberImportController::execute()` — DB の例外を受け止め、全員を取り消したうえで取込の画面へ戻し、「取り込めませんでした（「氏名」まで進んだところでデータベースのエラーが起きました）。1件も登録していません。…」を出す。詳しい理由は `report()` でログへ（生の SQL の文は画面に出さない）|
| テスト | `HacomonoMemberMapperTest`（20 本追加）・`ZealMemberImportControllerTest`（4 本追加）。3296 → **3320 tests / 23584 assertions green**（PHP 8.3）|

### 要点

- 上限は `database/sql/create_zeal_tables.sql` と同じ。本番の `zeal_members` / `zeal_member_contracts` の定義を読み取りで確かめ、一致した（2026-10-02・利用者の承認のうえ。`SHOW CREATE TABLE` だけ）
- 長さは `mb_strlen`（MySQL の utf8mb4 の VARCHAR は文字数で数える）。テストは全角の文字で埋める
- A のテストは氏名で例外を起こす（B を外しても A の結果が変わらない＝Bug #48 を避ける）。B の端から端のテストは、SQLite では長い値が入ってしまうことを利用して件数で落とす
- 出す会員は行の変換の直後に覚える（最初は登録の直前で、重複の確認で止まると 1 つ前の会員が出た。見直しで気づいて直した）。「1件も登録していません」は、確定のあとで例外を出す仕組みが両モデルに無いことが前提

### 範囲外（気づいたが直していない）

- 会員の画面（`Zeal\MemberController`）はメールを `max:200` で通すが、列は 100 文字（101〜200 文字で画面の登録も 500 になる可能性が高い・コードからの推測で未実測）（→ 2026-10-02 に直した。下の「ZEAL 会員の画面の入力チェックを列に合わせる」の節・Bug #73）
- CLI の `zeal:import-members --commit` は A を入れていない（B は同じ変換なので効く）
- `memo`（TEXT・65,535 バイト）の長さは見ない（取込が組み立てる短い文）
- 工程表の取込も `DB::transaction()` を受け止めていない（行は検査済み）

### 検証

- 全件 **OK (3320 tests, 23584 assertions)**（PHP 8.3）
- 変異（docs/RULES.md Bug #44 の作法・コミットしてから 1 つずつ・戻したあと作業ツリーが空）: カナリア 1 つと 19 通りが、すべて狙ったテストで落ちた（例外を受け止めない／`report()` を消す／文から氏名を落とす／会員を覚えない・覚えるのが重複の確認の後・会員の登録の後／上限の `>=`／6 列それぞれの上限を外す／`strlen`／金額の下限・上限・`>=`／換算の前の守り／長さの検査を丸ごと外す）。1 回目に期待と違ったのは M12（`strlen`）だけで、上限を超える側の文の文字数もバイト数（63 文字）になって 6 本多く落ちた＝期待の表が狭かっただけ（2 回目は表を直してすべて一致）
- 画面（ビュー・CSS・JS）は変えていないので実ブラウザの確認は行っていない。断ったあとの取込の画面とプレビューのエラー行は、Feature テストが描いた HTML で確かめた

本番へは、下の節（Bug #73・#74）と一緒に 2026-10-03 に出した（記録は下の節の「本番反映」）。

⚠ `origin/13.x` への push はしていない。→ 2026-10-03 に push 済み（`57274b85` に含まれる）。

---

## ✅ ZEAL 会員の画面の入力チェックを列に合わせる（とプラン変更の 500）— 本番反映済み

実装計画: `~/.claude/plans/starry-zooming-falcon.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #73・#74）

上の節（Bug #72）の範囲外で見つけた件。会員の画面（`Zeal\MemberController`。編集・プラン変更・退会）の入力チェックを、本番の列（2026-10-02 に読み取りで確認）と突き合わせて直した。**利用者の決定（2026-10-02）: 案 A＝見つけた 4 件すべて直す**。テストを書く途中で、**プラン変更がどの値でも 500 になる**ことが分かり、利用者の決定（同日）で一緒に直した。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し。**

| # | 画面と項目 | 直す前 | 直した後 |
|---|---|---|---|
| 1 | 会員の編集・メール | `max:200`・入力欄 200 文字（列は 100） | `max:100`・入力欄 100 文字 |
| 2 | プラン変更・備考 | `max:500`・入力欄に上限なし（列は 200） | `max:200`・入力欄 200 文字 |
| 3 | プラン変更・適用価格 | 上限なし（列は `int unsigned`） | `max:4294967295` |
| 4 | 編集の「当店を知ったきっかけ」「入会目的」・退会の「退会理由」 | 文字列なら何でも通す（選択肢に無い値で 500） | `Rule::enum` |
| — | プラン変更そのもの（Bug #74） | `DB::transaction()` の無名関数に `$request` を渡しておらず、どの値でも 500（最初の実装から） | `use ($request, …)` |

1・2 は普通の操作で起きる。3・4 は手で組んだ送信だけ。

| 区分 | 実装内容 |
|------|---------|
| Controller | `Zeal\MemberController` の `update()`・`changePlan()`・`withdraw()` の入力チェック ／ `changePlan()` の `use ($request, …)` |
| Blade | `zeal/members/_form.blade.php`（メール欄の `maxlength`）・`zeal/members/show.blade.php`（プラン変更の備考欄の `maxlength`）|
| テスト | 新規 `tests/Feature/Zeal/MemberScreenTestCase.php`（土台）・`MemberPlanChangeTest.php`（2 本）・`MemberInputLimitsTest.php`（14 本）。会員の画面を通るテストは、それまで 1 本も無かった |

### 要点

- 列ちょうどは保存・1 つ超えは断る、を対で見る。送る値は、描いた画面のフォームを分解して差し替える（Bug #47）。入力欄の `maxlength` も描いた HTML で見る
- 詳細画面は体験予約（外部の `'zeal'` 接続）を読むので、テストでは SQLite のメモリへ向け直す（`MonthEndOverflowTest` と同じ）
- 画面の選択肢は `Enum::cases()` から作っているので、正しい選択は `Rule::enum` で断られない（テストで固定）

### 範囲外（気づいたが直していない）

- 氏名 `max:50`・住所 `max:200` は列（100・300）より狭いだけ（例外にはならない）。取込で 51 文字以上の氏名・201 文字以上の住所が入った会員は、編集画面から保存できない（→ 2026-11-13 に直した。下の「ZEAL 会員の編集で、保存済みの値をそのまま保存できるようにする」の節・Bug #130）
- 取込はメールの形式を見ないので、形式の崩れたメールの会員も編集画面から保存できない（→ 2026-11-13 に直した。Bug #130）
- 編集画面はフリガナが必須だが、取込はフリガナが空でも入れる（→ 2026-11-13 に直した。本番は在籍 40 人のうち 17 人が該当していた。Bug #130）

### 検証

- 全件 **OK (3336 tests, 23752 assertions)**（PHP 8.3。13.x〈`1e0950ea`〉をマージしたあと）／ コンパイル済みビュー **296 本 / INVALID 0 件**
- 変異（docs/RULES.md Bug #44 の作法）: カナリア 1 つと 15 通り（上限の ±1・上限を外す・3 つの enum を外す・別の enum で検査する・2 つの入力欄を戻す・`$request` を渡さない・キャンペーンを常に false／true）が、すべて狙ったテストで落ちた
- 実ブラウザの確認は行っていない（変えたのは入力欄の属性 1 つずつと入力チェック）

### 本番反映（2026-10-03 実施。Bug #72 と一緒に）

利用者の承認のあと、反映の前に本番を読み取りで突き合わせた（`app` `config` `database` `lang` `resources` `routes` の md5）。13.x（`1e0950ea`）と本番は 707 本中 705 本が一致し、違うのは「社内決裁申請」の会話の直し 2 本（`9bfe255c` の `MailDelivery.php`・`ffc1640e` の `approval-reminder.blade.php`。BACKLOG に「本番は次の反映から」とあり、まだ出ていなかった）だけだった。この 2 つも一緒に出すことを利用者に確かめてから、13.x を `zeal-import-db-error` へ早送りして（`1e0950ea` → **`5edebe4c`**）`./deploy.sh` を流した。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前 | main repo | 作業ツリーは空・`vendor/bin/phpunit` は無い・無視設定のファイルの一覧は前回の反映と同じ 220 行・依存／DB の SQL／CSS／JS の変更なし・新しいファイルはテストだけ |
| `./deploy.sh` | 2026-10-03 18:12:48〜18:13:03（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 7 本（ZEAL の 5 本＋決裁の 2 本）・vendor は 0 本。CSS・JS の名前は本番と同じで、旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 7 本 | 手元の 13.x と **md5 が一致** |
| | コンパイル済みビューの `php -l` | **296 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は 6/18 から更新なし（画面を開いたあとも）・`bootstrap/cache` は 600／700（18:13 に作り直し）・変えた 4 つのクラスが読める |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 会員の取込・会員 #73 の編集と詳細 | 3 画面とも 200・`main` のはみ出し 0。編集画面のメール欄は `maxlength="100"`、詳細画面のプラン変更の備考欄は `maxlength="200"`。選択肢は一覧の数＋「未設定」（きっかけ 11・入会目的 10・退会理由 6）。詳細画面を開き直したときのコンソールの出力 0 件 |

⚠ 取込の確定・会員の編集・プラン変更・退会は、本番では送っていない（本番のデータを変える操作のため）。動きはテストで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-03 に push 済み（`57274b85` に含まれる）。

---

## ✅ ZEAL のテストで一度も通らないルートをなくす（25 本）— 本番反映済み

実装計画: `~/.claude/plans/joyful-mapping-hellman.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #75〜#77）

2026-10-03 の測定で、ルート 478 本中 231 本がテストで一度も通らなかった（きっかけは Bug #74。測り方はメモリ `project_route_coverage_probe.md`）。**利用者の決定（2026-10-04）: 案 A＝ZEAL から**。ZEAL の 25 本（保存・削除 14・画面 11）に、描いた画面のフォーム（Ajax は画面の JS）から送って成功まで通るテストを足し、落ちたものの原因を直した。作業場所 `.claude/worktrees/route-coverage`（枝 `route-coverage`）。**DB 変更・ルート変更・依存・CSS の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。

| # | 見つけたこと | 直したこと |
|---|---|---|
| F1（Bug #75） | 試算表の「実績を反映」の確認（GET の JSON）に `X-Requested-With` が無く、そのあとパスワード変更の「キャンセル」が生の JSON を指す。走査テストは URL に `/api/` を含むものしか見ていなかった | ヘッダーを足す・走査を自社への GET すべてへ |
| F2（Bug #76） | 会員の削除の確認に名前を生で埋め込んでいて、`'` を含む名前だと確認が出ずに削除される（実ブラウザで再現） | `Js::from` で渡す |
| F3（欠陥ではない） | 会員一覧が MySQL 専用の `DATE_FORMAT` を使っていて、テストの SQLite で通せなかった | 体験予約一覧と同じ `whereYear` / `whereMonth`。入会月の選択肢は PHP で作る。形の崩れた値は今までどおり 0 件 |
| F4（Bug #77） | 今年度の試算表があるとき、一覧から過去の年度を削除すると今年度の詳細に着き「削除しました」が消える（2 段の転送） | 戻り先を一覧（`?list=1`）に |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `Zeal\MemberController::index()`（F3）・`Zeal\SimulationController::destroy()`（F4）・`zeal/simulations/show.blade.php`（F1）・`zeal/members/show.blade.php`（F2） |
| テスト（新規） | `tests/Concerns/DrivesAlpineFetch.php`（画面の JS を node の vm で動かし、fetch が組んだ要求を取り出して送る・応答を JS に戻す・インラインのイベント属性を関数として組み立てる）／ `tests/Feature/Zeal/` に `InquiryShowTest`・`MemberListAndDeleteTest`・`PlanCrudTest`・`StoreMasterTest`・`TrainerMasterTest`・`SimulationScreensTest`・`SimulationSyncActualsTest` の 7 本（43 本） |
| テスト（変更） | `MemberScreenTestCase`（ZEAL の画面全体の土台に。体験予約の表に画面が読む列を足す）・`AjaxFetchSessionGuardTest`（F1 の走査を広げる） |

### 要点

- Ajax の画面（店舗・トレーナー・試算表の確認）は、要求を手で組まない。画面に描かれた `<script>` を node で読み込み、`fetch` だけを差し替えて、JS が実際に組んだ URL・メソッド・ヘッダー・本文を送る（手で組むと、画面からヘッダーや項目が消えても緑のまま。Bug #35 / #47）
- Alpine のバインド（`:action` / `:value`）で値が決まるフォームは、描いた式を JS の状態で評価して送る（試算表の反映フォーム）
- 試算表のテストは今日を 2026-10-04（日本時間）に固定する（2025 年度は全部が過去の月・2026 年度は 10 月が当月）

### 範囲外（気づいたが直していない）

- `admin/master/zeal-simulation-categories/index.blade.php:130` の削除の確認も名前を生で埋め込んでいる（F2 と同じ形。「各マスタ」の作業で直す）
- `InquiryController::show(int $id)` に数字でない id を送ると 404 でなく 500・`InquiryController::index()` の `month` に `-` の無い値を送ると 500（どちらも手で組んだ URL だけ）
- テスト用スキーマに外部キーが無いので、試算表の削除で月別のセルが消えること（本番の `ON DELETE CASCADE`）はテストで見られない
- 役割ごとの出し分け（管理者に削除ボタンが出ない等）は見ていない
- 試算表の作成画面は、入力チェックで断られたあと年度の選択を今年度に戻す（`old('fiscal_year')` を見ない）

### 検証

- 全件 **OK (3377 tests, 24351 assertions)**（PHP 8.3。着手前 3334 本から +43）／ コンパイル済みビュー **296 本 / INVALID 0 件**
- 変異（docs/RULES.md Bug #44 の作法。行を指定して当て、前後で作業ツリーが空・着弾を確かめ、理由の 1 行目まで記録）: **カナリア 1 つと 30 通りがすべて狙ったテストで落ちた**（緑のまま 0・当てられなかったもの 0）。カナリア（店舗の画面の関数名を変える）は店舗の 8 本だけを「関数が描かれていない」で落とした＝道具が作業場所のコードを読んでいる
- 測り直し（同じ仕掛け）: ZEAL の「一度も通らない」は **25 → 0**（「断られるだけ」も 0）。全体は **231 → 209**（ルートは部品の更新で 478 → 481。増えた分は `storage.local.upload` などフレームワークのルートの可能性が高い）
- 実ブラウザ（Playwright・使い捨ての SQLite ＋ `artisan serve`）: F2 は直す前に `O'Brien 太郎` の「削除」で確認が出ず削除（`SyntaxError`）→ 直した後は確認が出て「キャンセル」で残る。F1 は直す前に「キャンセル」が JSON を指し押すと生の JSON → 直した後は試算表の詳細へ戻る。ビルドした CSS は本番の最新（`app-B-wGZyIE.css`）とバイトまで同じ

### 本番反映（2026-10-04 実施）

利用者の承認のあと（本番の読み取り・早送りと `./deploy.sh`・Chrome の確認のそれぞれで承認を取った）、`13.x` を `f6891312` から **`2deef25f`** へ早送りした。直前に作業場所で全件 **OK (3377 tests, 24351 assertions)**（PHP 8.3）。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo・作業場所 | 早送りできる・作業ツリーは空・`vendor/bin/phpunit` は無い・本番に出るアプリのファイルは 4 本だけ ／ 無視設定のファイルの一覧は 223 行（前回 220。rsync に乗りうるもの〈`public/build`・`database/database.sqlite`・`.phpunit.result.cache`〉は日付が 10/3 以前で、新しいのは deploy.sh が除外する `storage/logs`・`bootstrap/cache`・`.playwright-mcp`） ／ 「社内決裁申請」の会話は idle |
| 反映の前（本番の読み取り） | 4 本・記録・build | 4 本とも 13.x（`f6891312`）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は 6/18 から更新なし ／ build は `app-B-wGZyIE.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-04 21:12 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 4 本・vendor は 0 本。CSS・JS の名前は前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前から出ている Google Fonts の `@import` の位置のもの）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 4 本 | 手元（`2deef25f`）と **md5 が一致**（4/4） |
| | コンパイル済みビューの `php -l` | **296 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は 6/18 のまま（Chrome で画面を開いたあとも）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 21:12 に作り直し・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700 ／ CSS はアプリ側・公開側とも手元のビルドと md5 が一致 |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 会員一覧 | 200・入会月の選択肢 12 か月（2026-09 から）＋「すべて」・`main` のはみ出し 0 ／ `?status=all&joined_month=2026-05` で 2 件（入会日 2026-05-31・2026-05-02＝月末の日も入る） |
| | 会員の詳細（#73） | 削除フォームの `onsubmit` が `confirm('「' + '\u…' + '…')` の `Js::from` の形・関数として組み立てても構文エラーにならない（削除は押していない） |
| | 試算表の詳細（2026 年度） | 「実績を反映」でプレビューが開き実績の表が出る（要求は `sync-actuals/preview` の GET 1 回・200）→ × で閉じた（「実績を反映する」は押していない）→ パスワード変更の「キャンセル」が `/zeal/simulations/2` を指す（JSON の URL ではない） |
| | 試算表の一覧（`?list=1`） | 200・`main` のはみ出し 0 ／ 4 画面とも開き直したときのコンソールのエラー 0 件 |

⚠ 会員の削除・試算表の削除（F4 の戻り先）・反映は、本番では送っていない（本番のデータを変える操作のため）。動きはテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-04 に push 済み（`f954ae48` に含まれる）。

---

## ✅ 各マスタのテストで一度も通らないルートをなくす（38 本）— 本番反映済み

実装計画: `~/.claude/plans/squishy-percolating-wirth.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #78〜#82）

上の ZEAL の節に続く 2 つ目（2026-10-04・案 A）。システム管理のマスタ 7 つ（用途・構造・用途地域・原価項目・DAD 専門分野・ZEAL 試算表の項目・アンケート設問）の **38 本**に、描いた画面から送って成功まで通るテストを足し、見つけた欠陥を直した。作業場所 `.claude/worktrees/route-coverage`（枝 `master-coverage`）。**DB 変更・ルート変更・依存・CSS の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。

着手前に本番を読み取りで確かめた（2026-10-04・承認のうえ・`SELECT` と `SHOW CREATE TABLE` だけ）: `re_project_costs.cost_item_id` に外部キー（ON DELETE の指定なし）・`re_cost_items.is_active` は `DEFAULT 1`・物件の構造は全件空・用途地域は全部マスタの値・「物件購入費」（id 1）は仕入れの原価 20 行・分譲地の原価 16 行で使われている・アンケート設問は住宅事業に 6 問・回答 12 件。

| # | 見つけたこと | 直したこと |
|---|---|---|
| G1（Bug #78） | ZEAL 試算表の項目マスターの**新規登録画面が 500**（最初の実装 2026-05-12 から） | 共通フォームの先頭で `$category = $category ?? null;` |
| G2（Bug #79） | アンケート設問の**削除ボタンが効かない**（Alpine 2 の `__x`）。直したら今度は**削除されても画面が変わらない**（XHR が 302 を DELETE のまま追って 405。実ブラウザでだけ出た） | `x-on:click` で呼ぶ ＋ 削除の XHR に `X-Requested-With` |
| G3（Bug #76 の残り） | ZEAL 試算表の項目の削除の確認に名前を生で埋め込んでいた（`'` を含む名前で確認が出ずに送られる） | `Js::from` |
| G4（Bug #80） | 原価項目「物件購入費」の名前を変えると、物件購入費の原価行が二重になる | 名前の変更・削除を断り、ほかの項目にその名前を使わせない。一覧は「自動計上」と注記 |
| G5（Bug #81） | 分譲地の原価だけで使っている原価項目を削除でき、本番では外部キーに断られ 500 | 使用中の確かめに分譲地の原価を足す |
| G6（Bug #82） | 構造・用途地域の名前を変えると、物件・仕入れ案件・分譲地の編集で値が選ばれず、保存すると空になる | 名前を変えたら使っている行も新しい名前に変え件数を出す ＋ 編集画面はマスタに無い値を「（マスタに無い値）」で残す |
| G7（欠陥ではない） | テスト用スキーマの `re_cost_items.is_active` に既定値が無く、原価項目の追加がテストでだけ 500 | テスト用スキーマを本番の定義に合わせた（アンケート設問の `question_type` も 30 文字に） |
| G8（Bug #73 と同じ形） | ZEAL 試算表の項目のデフォルト額に上限が無い（列は `INT`。範囲外は本番だけ 500） | `min:-2147483648\|max:2147483647` |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `Admin\{ReCostItem,StructureType,ZoningType,ZealSimulationCategory}Controller`・`ReCostItem`（定数）・マスタの画面 5 本（原価項目の一覧・ZEAL 項目の共通フォームと一覧・アンケート設問の一覧と部品）・物件の編集・仕入れ案件と分譲地の共通フォーム |
| テスト（道具） | `DrivesAlpineFetch` を広げた: `$refs.….submit()` を記録してそのときのバインド（`x-bind:action` / `x-bind:value` / `x-model`）を評価する（`submitted` → `sendSubmitted()`）・`alpineForm()`（`x-model` の値を入れたフォーム）・XMLHttpRequest / `window.location` / `alert` の偽物・JSON の本文・**転送をブラウザと同じ決まりで追う `sendCapturedLikeBrowser()`** |
| テスト（新規） | `tests/Feature/Admin/Master/` に土台 `MasterScreenTestCase`・4 マスタ共通の `InlineNameMasterTestCase` と各マスタ 7 本・`MasterNameReferenceTest` ／ スキーマ `CreatesStructureTypeSchema`・`CreatesDadSchema` |
| 全件テスト | 3377 → **3466 tests / 25454 assertions green**（PHP 8.3）／ コンパイル済みビュー **296 本 / INVALID 0** |

### 検証

- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いてから）: **43 通り（カナリアを含む）のうち 42 通りが期待どおり**（M40＝アンケートの並び替えの番号を 0 始まりにする、は相対順が同じなので等価の見込みどおり緑・M39＝テスト用スキーマの既定値を外す、は準備の投入も落ちて期待より広い 13 本）。**1 通り（M37＝構造のドロップ先を 1 つずらす）が緑のまま通った** — 並び替えのテストが「先頭を末尾へ落とす」だけで、配列の長さを超えた位置は末尾になるので同じ結果だった。「末尾を先頭へ落とす」テストを足し、M37 と DAD 側の同じ変異（M37b）が赤になることを確かめた。G4 の表示を直したあと M24・M25 も当て直して赤
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ アプリ内のブラウザ）: 直す前を一時的に戻して G1（500）・G2（`TypeError`・何も起きない）・G3（`SyntaxError`・確認が出ずに送られる）を確認し、直したあとを確認 ／ 用途マスターで追加・名前の変更・削除・ドラッグ（実際の DragEvent）／ G4 の一覧（375px・1280px で「自動計上」が 1 行・`main` のはみ出し 0）／ G6 の名前の書き換えと、マスタに無い構造を編集画面から保存して残る
- ⚠ **実ブラウザでだけ出た欠陥が 2 つ**: G2 の 2 つ目（XHR の転送）と、G4 の「自動で計上（変更不可）」が操作の列で 2 行に折れる表示。どちらもテストは緑だった
- 測り直し（`TestCase::call()` の一時上書き・メモリ project_route_coverage_probe.md）: 各マスタの「一度も通らない」は **38 → 0**、全体は **209 → 165**（G6 のテストが物件・仕入れ案件・分譲地の編集と保存の 6 本も通した）。残りは 賃貸マンション 43・不動産 31・DAD 28・住宅事業 27・テナント 19・API 14 ほか

### 範囲外（気づいたが直していない）

- アンケート設問の**編集・並び替えの画面が無い**（サーバの約束だけテストした）／追加のあと「設問を追加しました。」が出ない・断られたときの理由が出ない／追加の小窓の `style` と `x-show` が `display` を取り合う（Bug #32 の形）／`?department=` に `\` を入れると JS が壊れる
- 構造・用途地域のマスタは 100 文字まで通すが、物件・仕入れ案件・分譲地は 50 文字まで
- 「物件購入費」の文字列がアプリに 15 か所ほど直書きされている
- 協力業者を論理削除すると、その専門分野は削除できる（外部キーで null になる）

### 本番反映（2026-10-05 実施）

利用者の承認のあと（本番の読み取り・早送りと `./deploy.sh`・Chrome の確認のそれぞれで承認を取った）、`13.x` を `f954ae48` から **`3390b5d7`** へ早送りした（18 コミット）。全件は作業場所で **OK (3466 tests, 25454 assertions)**（PHP 8.3。2026-10-04）。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `f954ae48`（決裁の会話は 13.x を進めていない）・早送りできる・`vendor/bin/phpunit` は無い ／ 本番に出るアプリのファイルは 13 本だけ（ほかの差分は `tests/`・`docs/`・`CLAUDE.md`）／ 無視設定のファイルの一覧は 223 行（前回と同じ） |
| 反映の前（本番の読み取り） | 13 本・記録・build | 13 本とも 13.x（`f954ae48`）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は 6/18 から更新なし ／ build はアプリ側・公開側とも `app-B-wGZyIE.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-05 08:46:08〜08:46:17（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 13 本とビルド（manifest・CSS・JS。名前も中身も前と同じ）・vendor は 0 本。旧バンドルの削除 0 件（ビルドの警告 1 件は以前から出ている Google Fonts の `@import` の位置のもの）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 13 本 | 手元（`3390b5d7`）と **md5 が一致**（13/13） |
| | コンパイル済みビューの `php -l` | **296 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は 6/18 のまま（Chrome で画面を開いたあとも）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 08:46 に作り直し・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700 ／ CSS・JS・manifest はアプリ側・公開側とも手元のビルドと md5 が一致 |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | ZEAL 試算表の項目の新規登録（G1） | **200**（直す前は 500）・登録のフォームは POST で一覧の URL へ（登録は押していない） |
| | ZEAL 試算表の項目の一覧（G3） | 削除フォーム 16 本すべての `onsubmit` が `confirm('「' + '\u…' + '」を削除しますか？…')` の `Js::from` の形・16 本とも関数として組み立てられる（削除は押していない） |
| | アンケート設問（G2） | 削除ボタン 6 個すべてが `type="button"`・フォームの外・`x-on:click="deleteQuestion(…)"` ／ 古い `__x` の `onclick` は 0 ／ 画面の `deleteQuestion` は `DELETE` の XHR に `X-Requested-With` を付ける（削除は押していない） |
| | 原価項目の一覧（G4） | 17 行のうち「物件購入費」だけ編集・削除のボタンが無く「自動計上」（操作の列に収まる）・ほかの 16 行はボタンがある・一覧の下に注記 |
| | 構造マスター・用途地域マスター | どちらも 200（6 行・13 行） |
| | 物件（テナントの #1）・仕入れ案件（#19）・分譲地（#19）の編集（G6） | 3 画面とも 200。構造・用途地域の選択は今の値のまま（物件 #1 は構造が空・仕入れ案件と分譲地は「第一種住居地域」）・「（マスタに無い値）」の選択肢は 0（本番の値は全部マスタにある） |
| | はみ出しとコンソール | 9 画面とも `main` のはみ出し 0 ／ コンソールのエラー 0 件（警告は仕入れ案件・分譲地の編集の Google Maps の 3 件〈`loading=async`・`Marker` の非推奨〉で、以前からあるもの） |

⚠ 項目・設問の登録・削除、原価項目と構造・用途地域の名前の変更、編集画面の保存は、本番では送っていない（本番のデータを変える操作のため）。動きはテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-05 に push 済み（`bcebb9b5` に含まれる）。

---

## ✅ テナントのテストで一度も通らないルートをなくす（23 本）— 本番反映済み

実装計画: `~/.claude/plans/snappy-stirring-dolphin.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #83〜#86・#73 の追記）

上の各マスタの節に続く 3 つ目（2026-10-05）。作業場所 `.claude/worktrees/route-coverage`（枝 `tenant-coverage`）。**DB 変更・ルート変更・依存・CSS の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。

測定の仕掛け（`TestCase::call()` の一時上書き。メモリ project_route_coverage_probe.md）は前回の作業用フォルダと一緒に消えていたので作り直した。作り直した仕掛けでは、着手前に 481 本中 **175 本**が一度も成功まで通らず、テナントは **23 本**（前回の記録は全体 165・テナント 19。前回の仕掛けが残っておらず、数え方の差かは突き合わせられない）。

| 区分 | 本数 | ルート |
|---|---|---|
| 物件 | 2 | create・store |
| 区画 | 5 | create・store・edit・updateStatus・destroy |
| 契約 | 4 | create・store・update・terminate.execute |
| 顧客 | 5 | create・store・edit・update・destroy |
| 問合せ | 3 | store・destroy・storeHistory |
| 投資 | 2 | store・destroy |
| 修繕 | 2 | store・destroy |

着手前に本番を読み取りで確かめた（2026-10-05・計画の承認のうえ・`SHOW CREATE TABLE` と件数だけ）: 金額の列はどれも符号付きの `INT`・問合せの希望面積は `DECIMAL(8,2)`・0 階の区画 0 件・家賃や共益費などが空の契約 0 件（契約中 89・解約済み 37）・契約終了日が契約日より前の契約 0 件・削除済みの顧客を指す問合せ 0 件。

| # | 見つけたこと | 直したこと |
|---|---|---|
| T1（Bug #83） | 区画の登録・編集で **0 階が通る**（表示名「0Z」で保存） | 階を整数にしてから 0 と比べる |
| T2（Bug #84） | 契約の**編集画面の JS が、入力エラーで戻ると構文エラーで壊れる**（家賃を空にして保存すると、共益費・ゴミ代の欄と初月の選択まで空になる。実ブラウザでコンソールのエラー 42 件） | 登録画面と同じく `@json(old(…))` |
| T3（Bug #85） | 契約終了日が**契約日より前でも解約できた** | `after_or_equal:<契約日>` と日本語の文言 |
| T4（Bug #73 と同じ形） | 金額の整数 35 か所と希望面積に**上限が無い**（本番の MySQL だけで 500） | 基底の `Controller::MAX_INT_COLUMN` に 1 か所で置く。投資は明細の合計も見る。上限の無い整数・数値の入力は走査テストで止める |
| T5（直していない） | 投資の登録の区画の選択（`x-model` と `<template x-for>` の選択肢＝Top trap #3 の形） | 実ブラウザで、入力エラーのあとも選ばれたままを確認した（`:selected` も付いている） |
| T6（Bug #86。計画に無かった） | 投資の**登録・編集で、入力エラーで戻ると明細の行が消える**（登録は既定の 1 行、編集は保存済みの内容に戻る） | `old('details')` があればそれで描く |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `Controller`（定数）・`Tenant\{Unit,Contract,Inquiry,Investment,Repair}Controller`・契約の編集画面・投資の登録と編集の画面 |
| テスト（道具） | `DrivesAlpineFetch::browserForm()` を足した: ブラウザがフォームから送る項目を、Alpine が値を入れたあとの JS の状態で組む（`<select>`・ラジオ・チェックボックスの `x-model`、`:name`・`:value`・`:disabled`、`<template x-for>` の行、`$refs` の選択欄に JS が `new Option()` で足した選択肢、`init()`）。`inlineXDataAround()`（インラインの x-data）。ハーネスに `setImmediate`（「fetch の応答が届いたあとで選ぶ」を書くため）。既存の `driveAlpine()`・`alpineForm()` の振る舞いは変えていない |
| テスト（新規） | `tests/Feature/Tenant/Screens/` に土台 `TenantScreenTestCase` と、区分ごとの 7 本（`PropertyCreateScreenTest`・`UnitScreensTest`・`ContractScreensTest`・`CustomerScreensTest`・`InquiryScreensTest`・`InvestmentScreensTest`・`RepairScreensTest`）・`AmountLimitTest` |
| 全件テスト | 3466 → **3513 tests / 26666 assertions green**（PHP 8.3）／ コンパイル済みビュー **296 本 / INVALID 0** |

### 検証

- 使い捨てのテストで 23 本を素朴に叩くと全部成功した（壊れていたのは、素朴な送り方では通らない「入力エラーのあと」「上限の外」「境目」だった）。T1〜T4・T6 は、テストで直す前の赤を確かめてから直した
- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いた）: **35 通り（カナリアを含む）すべて狙ったテストで落ちた**。期待の表と違った 3 通りは、表が狭かっただけ（M09 上限を 1 小さくする＝テストが文言の数値を全文で見ているので上限のテスト 12 本すべてが落ちる／M29 オーナー所有のラジオの `x-model` を外す＝所有区分が送られず期待の文言より手前で落ちる／T02 道具の x-for の番号を入れない＝JS の評価そのものが止まる）。T03（道具が `:disabled` を見ない）は予定どおり緑＝今回の画面に `:disabled` の送る欄が無い（道具の死角）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright）: T2 は直す前に戻すと構文エラーで共益費などが空になり、直した後は家賃だけが空で残りは残る ／ T5 は再現しない ／ T6 は明細 2 行が残る ／ 0 階は日本語の入力エラー ／ 契約の登録で物件を選ぶと空き区画が出る ／ 主な 13 画面が 1440px・375px とも 200・`main` のはみ出し 0・コンソールのエラー 0
- 測り直し: テナントの「一度も通らない」は **23 → 0**、全体は **175 → 149**（契約の登録の JS が叩く API の 3 本も通った）。残りは 賃貸マンション 43・不動産 34・DAD 28・住宅事業 27・API 11 ほか

### 範囲外（気づいたが直していない）

- 契約の初月・最終月の金額は 4 項目の合計なので、1 項目ずつは上限の内側でも合計が `INT` を超えうる（2 項目が約 11 億円を超える打ち間違いが重ならないと起きない）
- 顧客を削除しても、その顧客の問合せの編集画面では顧客の表示だけが消える（紐づけは残る）
- 修繕の登録に `unit_id` の無い手組みの送信をすると 500（画面からは起きない）（→ 2026-10-29 に直した。下の「欄そのものが無い送信で 500 にならないようにする」の節）
- 削除済みの区画への契約の登録は 404（既知）
- 契約の登録画面は `x-init="init()"` と Alpine の自動の `init()` で 2 回呼ばれる（物件を選んだ状態で開くと空き区画・問合せを 2 回取りに行く。害は無い）
- ほかの「行を JS で足す」画面（DAD・不動産の原価など）で入力エラーのあと行が残るかは未確認

### 本番反映（2026-10-05 実施）

利用者の承認のあと（反映・早送りと `./deploy.sh`・Chrome の確認のそれぞれで承認を取った）、`13.x` を `c5d3fdd5` から **`3813a309`** へ早送りした（14 コミット）。全件は作業場所で **OK (3513 tests, 26666 assertions)**（PHP 8.3）。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `c5d3fdd5`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番に出るアプリのファイルは 9 本だけ（ほかの差分は `tests/`・`docs/`・`CLAUDE.md`）／ 無視設定のファイルの一覧は 223 行（前回と同じ） |
| 反映の前（本番の読み取り） | 9 本・記録・build | 9 本とも 13.x（`c5d3fdd5`）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は 6/18 から更新なし ／ build はアプリ側・公開側とも `app-B-wGZyIE.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-05 10:35:08〜10:35:16（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 9 本とビルド（manifest・CSS・JS。名前も中身も前と同じ）・vendor は 0 本。旧バンドルの削除 0 件（ビルドの警告 1 件は以前から出ている Google Fonts の `@import` の位置のもの）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 9 本 | 手元（`3813a309`）と **md5 が一致**（9/9） |
| | コンパイル済みビューの `php -l` | **296 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は 6/18 のまま（Chrome で画面を開いたあとも）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 10:35 に作り直し・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700 ／ CSS・JS・manifest はアプリ側・公開側とも手元のビルドと md5 が一致 |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 契約の編集（C-2026-103） | 200・JS が組み立てられ（`rent: 180000,` などの数値の形）、家賃・共益費の欄に値・初月の請求方法が選ばれている・`main` のはみ出し 0 |
| | 投資の登録・編集（INV-2026-029） | どちらも 200・JS が組み立てられる・入力エラーのあとの明細の行を描く新しい書き方が配られ、今は既定の 1 行（編集は保存済みの 1 行）で描かれる |
| | 区画の登録・編集・解約・契約の登録・問合せ・修繕・顧客・物件の登録 | どれも 200・画面の中の JS はすべて組み立てられる（区画の編集は今の階「1」が入る）・`main` のはみ出し 0 ／ 契約の編集を開き直したときのコンソールのエラー 0 件 |

⚠ 登録・更新・削除・解約・0 階や上限を超える値の送信は、本番では送っていない（本番のデータを変える操作のため）。動きはテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-05 に push 済み（`c47306b4` に含まれる）。

---

## ✅ 不動産のテストで一度も通らないルートをなくす（34 本）— 本番反映済み

実装計画: `~/.claude/plans/piped-prancing-mochi.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #87〜#92・#73 と #76 の追記）

上のテナントの節に続く 4 つ目（2026-10-05）。作業場所 `.claude/worktrees/route-coverage`（枝 `realestate-coverage`）。**DB 変更・ルート変更・依存・CSS の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。

測定（道具 `.claude/route-coverage-tools/measure.sh`。全件 3513 本・OK）で、481 本中 149 本が一度も成功まで通らず、不動産（`realestate/`）は **34 本**。不動産の API 3 本（`api/realestate/project-lots`・`project-lot-cost`・`suppliers/quick`）も一緒に通した。

| 区分 | 本数 | ルート |
|---|---|---|
| 顧客（不動産側。住宅事業と共用のコントローラ・画面） | 11 | index・create・store・edit・update・destroy・surveys の create・store・edit・update・destroy |
| 仕入れ先 | 7 | index・create・store・show・edit・update・destroy |
| 分譲地 | 7 | store・原価の store・update・destroy・bulk-import・図面の destroy・update-status |
| 仕入れ案件 | 6 | store・原価の store・update・destroy・bulk-import・update-status |
| 契約 | 3 | edit・close・lost |

着手前に本番を読み取りで確かめた（2026-10-05・承認のうえ・`SHOW CREATE TABLE` と集計だけ）: 不動産の金額・号地番号・坪単価の列はどれも符号付きの `int`・`buyers` は phone 20・city 50・employer 100・email 255・人数 `tinyint unsigned`・勤続年数 `smallint unsigned`。候補が本番で起きた形跡は 0 件（区画 44 件の最小坪数 34.58・号地の重複 0・区画と分譲地の食い違い 0・顧客 15 件で長すぎる値・崩れたメール・未来の生年月日・元号の食い違い 0・原価の確定額 0 は 0 件）。

| # | 見つけたこと | 直したこと |
|---|---|---|
| R1（Bug #87） | 分譲地の契約で入力エラーになると、**戻った画面の区画の選択肢が空**（分譲地を選び直すまで区画を選べない）。編集で分譲地を変えてからのエラーも同じ | 区画の一覧を `old('project_id')` の分譲地からサーバで作って渡し、登録画面の `<option>` に `:selected` |
| R2（Bug #88） | 詳細の原価の**見込み額 0 は追加しても何も起きず、確定額 0 は未定で保存**。編集で見込み額を空にすると 0 で保存 | 登録画面の原価欄と同じく 0 は金額として送り、空なら知らせる（2 画面） |
| R3（Bug #89） | 顧客の登録・編集が**姓・名・取得日しか検査しない**（本番では列に入らない値で英語の SQL が入力値つきで赤帯に出る） | 本番の列の大きさで検査（CSV 取込と同じ値）・例外は SQL を出さずに `report()` |
| R4（Bug #90） | 生年月日「2 月 30 日」が**黙って 3 月 1 日**で保存・元号を選んだまま西暦を打つと 3905 年 | 存在する日・今日まで・元号の範囲で断る |
| R5（Bug #73 と同じ形） | 金額・号地番号の入力 38 か所に**上限が無い** | `max:` `MAX_INT_COLUMN`。不動産・顧客のコントローラの整数・数値の入力は走査で固定 |
| R6（Bug #91） | 小さい面積（0.04㎡）の区画で**坪単価が 30 億円**になり本番の INT に入らない | 求めた坪単価が上限を超えたら区画の追加・更新を断る |
| R7（Bug #92） | 成約で買主名の欄が無い送信を受けると 500（手で組んだ送信だけ） | `?? null` |
| R8（Bug #76 と同じ形・計画に無かった） | 仕入れ案件・分譲地の編集画面の**選んでいる仕入れ先の名前を Blade の書き換えだけで JS に書いていた**（`'` が `&#039;` のまま出る・名前が `\` で終わると画面の JS が丸ごと止まる・入力エラーで戻った仕入れ先の id でも止まる） | `Js::from` で書く |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `RealEstate\{Procurement,Project,ReContract}Controller`・`CustomerController`・`CustomerSurveyController`・`App\Support\BuyerCsvRow`（定数を public に）・`lang/ja/validation.php`（顧客の 7 項目の和名）・画面 4 本（契約の登録・仕入れ案件と分譲地の詳細・仕入れ先の選択） |
| テスト（道具） | `DrivesAlpineFetch`: `driveAlpine()`・`browserForm()` に「一緒に読む `<script>`」（詳細は `costExcelImporterFactory()`、一覧の小窓は `window.__reStatus*` を使う）・`browserForm()` はフォームの中の `<script>` の文字列を要素として数えない（コメントの `<template x-for>` で数えが狂った）。新規 `SubmitsScreenForms`（テナントの土台の送り方を移して共用。描いたままの `survey[3]` を入れ子の配列に直して送る） |
| テスト（新規） | `tests/Feature/RealEstate/Screens/` に土台 `RealEstateScreenTestCase`（登録画面の原価欄＝入れ子のコンポーネントを親と別に評価して足す `formWithCostSection()`）と `SupplierScreensTest`・`ProcurementScreensTest`・`ProjectScreensTest`・`ContractScreensTest`・`CustomerScreensTest`・`DetailCostEntryTest`・`AmountLimitTest` |
| 全件テスト | 3513 → **3643 tests / 28332 assertions green**（PHP 8.3。うち 56 本は 13.x から取り込んだ決裁の段階4a の分）／ コンパイル済みビュー **298 本 / INVALID 0** |

### 検証

- 全件 **OK (3643 tests, 28332 assertions)**（PHP 8.3。13.x〈決裁の段階4a まで・`169d41c9`〉をこの枝へ取り込んだあと）／ コンパイル済みビュー **298 本 / INVALID 0**（取り込む前は 296 本 / 0）
- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いた・`.claude/route-coverage-tools/mutate/run.py`）: **43 通り（カナリアを含む）**。期待どおり 39 通り。⚠ **緑のまま通った穴 2 つ**（今日までの条件を外す＝元号なしの未来の生年月日のテストが無い／元号の終わりの年を含めない＝昭和 64 年のテストが無い）→ テストを足して当て直し、赤を確かめた。表の期待の誤り 1 つ（T01: 一覧のステータスの小窓の値は関数と同じ `<script>` にあるので「一緒に読む」を外しても落ちない）、表が狭かったもの 1 つ（M13b: 「今日」を UTC の今日にすると、日本の今日生まれのテストに加えて未来の生年月日のテストも同じ理由で落ちた）
- ⚠ **PHP のテストでは見えない死角 2 つ**（期待どおり緑）: 登録画面の区画の `<option>` の `:selected` を外す／区画の選択欄の `x-model` を外す（道具は x-model の値をそのまま選び、`:selected` が代わりに選ぶ）。実ブラウザで `:selected` を一時的に外すと、入力エラーで戻った画面の区画が空になることを確かめた（Top trap #3。`:selected` が必要）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright）: R1（分譲地の契約で買主を選ばずに登録 → 戻った画面で区画 2 が選ばれたまま → 買主を選んで「契約を登録しました。」）・R2（仕入れ案件の詳細で見込み額 0・確定額 0 の原価を追加 →「測量費 0円 0円」）・R4（顧客の登録で S55 年 2 月 30 日と電話番号 27 文字 → 2 つとも日本語の理由・入力は残る）・R8（仕入れ先「O'Neil 不動産」がそのまま出る）・主な 22 画面が 1440px・375px とも 200・コンソールのエラーは既知の経営ダッシュボード（使い捨ての DB に `ms_*` が無い）だけ
- ⚠ 375px で横にはみ出す画面が 2 つあった（**今回触っていない画面で、もとからある**）: 顧客の登録・編集（229px。生年月日・家族の行が折り返さない横並び）・分譲地の区画の画面（207px。表に横スクロールの枠が無い）→ 範囲外
- 測り直し（13.x を取り込んだあと）: 不動産の「一度も通らない」**34 → 0**、不動産の API 3 本も **0**。全体は 149 → **112**（ルートは決裁の段階4a で 481 → 482 本）。残りは 賃貸マンション 43・DAD 28・住宅事業 27・API 8 ほか

### 範囲外（気づいたが直していない）

- 金額の**合計**が `int` を超える場合（仕入れ案件の土地＋建物を「物件購入費」の原価へ写す・契約の粗利＝土地＋建物−原価。各欄が約 10 億円を超える打ち間違いが重ならないと起きない）
- 契約の登録で、分譲地に属さない区画・販売済みの区画を指す手組みの送信が通る（本番 0 件）
- 一覧のステータスの小窓で販売済みの仕入れ案件を「販売中」に戻すと、契約の登録画面に再び出て二重に契約できる（業務の判断が要る）（→ 2026-11-19 に直した。契約のある案件は販売済から戻せず、2 件目の契約も断る。下の「利用者の判断を待っていた 1〜3 を直す」の節・Bug #139）
- 契約の登録画面で入力エラーのあと「原価の参照」の箱が出ない（原価の値は残る）
- 仕入れ案件の登録・編集画面の消費税率は文字の欄で、JS に `'{{ old("tax_rate") }}'` と書く。`\` で終わる値を打って入力エラーになると、戻った画面の JS が止まる（ほかの金額の欄は `type="number"` で起きない）（→ 2026-10-26 に直した。下の「仕入れ案件の登録・編集で入力エラーのあと画面の JS が止まる件」の節）
- 区画の追加は画面の JS が欄を `document.getElementById` で読む（道具では動かせないので、テストは JS が送る形の本文を組んで送る）
- 375px で顧客の登録・編集（生年月日・家族の行）と区画の画面（表）が横にはみ出す（今回触っていない。上の検証）
- 顧客の画面は住宅事業と共用なので、R3・R4 の直しは住宅事業にも効く。住宅事業の顧客のルートの測り直しは住宅の回で

### 本番反映（2026-10-05 実施）

利用者の承認のあと（本番の読み取り・早送りと `./deploy.sh`・反映後の読み取り・Chrome の確認のそれぞれで承認を取った）、`13.x` を `4ae494a6` から **`e5a2406d`** へ早送りした（13 コミット）。`13.x` は決裁の会話の文書のコミット 2 つ（`c26be125`・`4ae494a6`。`docs/BACKLOG.md` だけ）で進んでいたので、先に作業場所へ取り込み（`e5a2406d`・衝突なし）、全件を流し直して **OK (3643 tests, 28332 assertions)**（PHP 8.3）。反映のあいだは決裁の会話に `13.x` の早送り・コミットと `./deploy.sh` を待ってもらい、終わってから知らせた。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存・CSS の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 11 本・記録・キャッシュ・build | 11 本とも `169d41c9`（＝ `13.x`。決裁の 2 コミットは文書だけ）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 から更新なし ／ `bootstrap/cache` は 600／700（10/5 12:29 に作り直し）／ build は `app-F3f4AS97.css`・`app-NiVQbl_Q.js` |
| 反映の前（手元） | main repo・作業場所 | 13.x と枝の差はアプリのファイル 11 本だけ（ほかは `tests/`・`docs/`・`CLAUDE.md`）・依存／DB／ルート／設定／CSS／JS の差なし ／ 作業場所でビルドした名前は本番と同じ `app-F3f4AS97.css`・`app-NiVQbl_Q.js`（確かめたあと消した）／ main repo の作業ツリーは空・`vendor/bin/phpunit` は無い・決裁の部品（`vendor/mpdf`）は残っている ／ 無視設定のファイルの一覧は 220 行 |
| `./deploy.sh` | 2026-10-05 14:49:53〜14:50:01（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 11 本とビルド（manifest・CSS・JS。名前は前と同じ）・vendor は 0 本。旧バンドルの削除 0 件（ビルドの警告 1 件は以前から出ている Google Fonts の `@import` の位置のもの）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 11 本 | 手元（`e5a2406d`）と **md5 が一致**（11/11） |
| | コンパイル済みビューの `php -l` | **298 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 14:49 に作り直し・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700 ／ build はアプリ側・公開側とも `app-F3f4AS97.css`・`app-NiVQbl_Q.js` だけ |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 契約の登録 | 200・`contractForm()` が組み立てられ区画の初期値は `[]`・区画の `<option>` に `:selected`（R1）・`main` のはみ出し 0 |
| | 仕入れ案件の詳細（RE-PRC-019）・分譲地の詳細（RE-PRJ-019） | どちらも 200・`hasAmount()` が載り、仕入れ案件では 0・`'0'`・空欄・null・`'abc'`・`'1200'` が true・true・false・false・false・true（R2）・追加の断りの文言あり |
| | 仕入れ案件の編集（RE-PRC-019） | 200・選んでいる仕入れ先の名前が実体参照なしの文字列で渡り、Alpine の状態・画面の表示・hidden の `supplier_id` が一致（R8） |
| | 顧客の登録（不動産） | 200・画面の script はすべて組み立てられる・入力欄 24 個（R3・R4 はサーバの入力チェックなので、画面で確かめられるのは開くことまで） |
| | コンソール | 5 画面とも開き直してエラー 0 件（警告は仕入れ案件の詳細・編集の Google Maps の 2 件〈`loading=async`・`Marker` の非推奨〉で、以前からあるもの） |

⚠ 登録・更新・削除・原価の追加・上限を超える値の送信は、本番では送っていない（本番のデータを変える操作のため）。動きはテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。→ 2026-10-06 に利用者の指示で push 済み（`c26be125..b77a2707`）。

---

## ✅ 住宅事業のテストで一度も通らないルートをなくす（27 本）— 本番反映済み

実装計画: `~/.claude/plans/golden-cooking-scott.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #93〜#99・#73 と #76 の追記）

上の不動産の節に続く 5 つ目（2026-10-06）。作業場所 `.claude/worktrees/route-coverage`（枝 `housing-coverage`）。**DB 変更・ルート変更・依存・CSS の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。

測定（道具 `.claude/route-coverage-tools/`）で、住宅事業（`housing/`）の一度も成功まで通らないルートは **27 本**。住宅事業の画面が使う API 5 本（`api/customers/check-duplicate`・`{buyer}/add-department`・`{buyer}/rank`・`api/housing/project-lots`・`procurement-info/{procurement}`）も一緒に通した。範囲外: `api/reverse-zip`（外部の HeartRails を呼ぶ）・`api/mansion/*`（賃貸マンションの回で）。

| 区分 | 本数 | ルート |
|---|---|---|
| 顧客（不動産と共用）＋アンケート | 13 | customers の index・create・store・quick-store・show・edit・update・destroy ／ surveys の create・store・edit・update・destroy |
| 建売物件 | 6 | store・edit・update・update-status・files.destroy ／ contracts.create |
| 契約（横断画面） | 5 | select-building-property・show-building・edit-building・show-custom-order・edit-custom-order |
| 注文住宅 | 2 | edit・files.destroy |
| ダッシュボード | 1 | `/housing`（既存のテストはコントローラのメソッドを直接呼んでいた）|

着手前に本番を読み取りで確かめた（2026-10-06・承認のうえ・`SHOW CREATE TABLE` と件数だけ）: 住宅事業の金額の列はどれも符号付き `int`・消費税率は `decimal(4,2)`・アンケートの分譲地と担当者には外部キー。建売 9 件（すべて分譲地区画・区画が空 0）・注文住宅 4 件（分譲地区画 2・お客様の土地 2・区画が空 0）＝ H1 の被害はまだ無い。所在地・顧客名の `&` `'` `\` と実体参照は 0 件・消費税率は最大 10.00。ファイルの 2 表はテスト用の写しより厳しい（種類 20 文字・保存先 500 文字・大きさ／MIME 型／登録者は必須）→ テスト用スキーマを合わせた。

| # | 見つけたこと | 直したこと |
|---|---|---|
| H1（Bug #93） | 注文住宅を**編集画面から保存すると、区画に触らなくても分譲地区画の紐付けが空になる**（実ブラウザで再現） | 区画・分譲地・仕入れ案件の `<option>` に `:selected`。今の区画の分譲地と区画の一覧をサーバで作って最初から描く（`ReProject::housingLotOptions()` を API と共用） |
| H2（Bug #93） | 建売・注文住宅の**登録画面**で入力エラーになると、選んだ分譲地・区画が消える | 同上（選んでいた区画から作る） |
| D1（Bug #93） | 土地の種別が分譲地区画・仕入れ案件なのに紐付け先が空でも保存できる | `required_if` で断る（建売・注文住宅・注文住宅の契約の編集） |
| H3（Bug #76 と同じ形） | 所在地・顧客名などを引用符の中に直に JS へ書いていた。`M&M'ビル` が実体参照の文字のまま保存され、末尾の `\` で画面の JS が止まる（実ブラウザで再現）。契約の編集 2 画面の属性の中も同じ | `Js::from` で書く |
| H4・D2（Bug #73 と同じ形） | 金額の入力 17 か所に上限が無い・消費税率は `max:100`（列は `decimal(4,2)`） | `MAX_INT_COLUMN`・`max:99.99`。住宅事業のコントローラの整数・数値の入力は走査で固定 |
| H5（Bug #94） | 8 画面の入力エラーの帯に理由が出ない | 帯の中に理由を 1 件ずつ並べる |
| H6（Bug #95） | 契約フォームの「新規顧客を登録」の小窓が都道府県を 20 文字まで通す（列は 10）・422 の理由が出ない | 上限を顧客の画面と同じにし、理由を小窓に出す |
| H7（Bug #96） | 建売・注文住宅の詳細で権限の無い操作のボタンが出ていて、押すと 403 | ルートの役割と同じ判定で出し分ける |
| H8（Bug #97） | `/housing?fiscal_year=`・`?fiscal_year[]=` と `api/housing/project-lots?project_id[]=` が 500（手で組んだ URL） | 年度は数字か全期間以外を今年度に、API は数字でなければ空 |
| H9（Bug #98） | アンケートの来場分譲地・担当者を検査していない（消えたものは「登録に失敗しました」だけ） | `nullable\|integer\|exists` |
| H10（Bug #99） | 建売の一覧のステータスの変更で更新者が残らない | `updated_by` も書く |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `Housing\{Property,CustomOrder,Contract,HsContractList,HousingDashboard}Controller`・`CustomerController`・`CustomerSurveyController`・`ReProject`（`housingLotOptions()`）・画面 14 本（建売と注文住宅の `_form`・登録と編集・詳細、契約の登録と編集 3 本、買主の選択の部品） |
| テスト（道具） | `DrivesAlpineFetch`: `x-ref` の選択欄の `value` / `selectedIndex` / `appendChild` と `data-*`、`document.createElement` / `getElementById` / `addEventListener`、`document.querySelector` で読む欄の値（`driveAlpine()` の `$elements`）。選択肢の無い ref に代入した値は今までどおり持つ（既存の呼び出しは変えていない）。`balancedElement()` を `ParsesForms` へ、生年月日を組む JS を新規 `RunsBuyerFormScript` へ切り出して不動産と共用 |
| テスト（新規） | `tests/Feature/Housing/Screens/` に土台 `HousingScreenTestCase`（入れ子のコンポーネント〈日付・買主の選択〉を別に評価して足す `composedForm()`）と `PropertyScreensTest`・`CustomOrderScreensTest`・`ContractScreensTest`・`CustomerScreensTest`・`DashboardScreenTest`・`AmountLimitTest`・`JsEmbeddingTest` |
| 全件テスト | 3643 → **3693 tests / 29985 assertions green**（PHP 8.3）／ コンパイル済みビュー **298 本 / INVALID 0** |

### 検証

- 全件 **OK**（下の「測り直し」の数字と同じ実行）／ コンパイル済みビュー **298 本 / INVALID 0**
- 新しいテストは直す前に 27 本が赤（どれも直すべき所。既存のテストは全部緑）を確かめてから直した
- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いた・`.claude/route-coverage-tools/mutate/`）: **41 通り＋カナリア**。すべて検出（緑のまま残った穴は 0）。表と違った 7 通りは、どれも表の書き方の誤り（走査のテストの理由は 1 行目しか記録しない＝本文に当該の行が名指しされていることを JUnit の XML で確認〈M15・M16・M19〉／名前を変えると必須の検査が同じ文言で断るので 1 本は等価〈W01〉／`x-model` を外すと構造のテストの数え上げが落ちる＝死角の見込みより強かった〈W02〉／道具の変異は 4 本が落ちた〈T01〉）と当て先の取り違え（M26）
  - ⚠ **M26 は当て先を取り違えていた**: 同じ行が 2 か所あり、2 つ目は簡易登録でなく顧客の画面の都道府県だった。**そちらは上限を緩めても全部緑**＝不動産の回のテストの穴 → 不動産の顧客の画面の入力の上限のテストに都道府県を足して赤を確かめ（M26b）、M26 を簡易登録へ当て直して赤（Bug #44 の「当たり先が正しいか」）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright）: H1（注文住宅の編集画面をそのまま保存しても区画 2 のまま・所在地は `M&M'ビル` のまま）・H2 と H5（登録画面で物件名を空にして送ると帯に理由が出て、区画が選ばれたまま。そのまま名前を入れて登録できる）・H5（消費税率 100・建物請負金額の上限超えの理由が日本語で並ぶ）・H6（小窓に「都道府県は10文字以下で入力してください。」、直すと買主が選ばれて閉じる）・主な 11 画面が 1440px・375px とも 200・コンソールのエラー 0
  - ⚠ 375px で顧客の登録画面が 229px はみ出す（不動産の回に範囲外として記録した既存のもの。共用の画面で今回は触っていない）
  - ⚠ 使い捨ての環境に `APP_LOCALE=ja` を入れ忘れると理由が英語で出る（本番とテストは ja）
- 測り直し（全件 OK (3693 tests, 29985 assertions) と同じ実行）: 住宅事業の「一度も通らない」**27 → 0**、住宅事業の画面が使う API 5 本も **0**。全体は 112 → **80**（API は 8 → 3。残りは賃貸マンションの 2 本と外部を呼ぶ `api/reverse-zip`）。残りは 賃貸マンション 43・DAD 28 ほか

### 範囲外（気づいたが直していない）

- D3: 注文住宅の編集画面の顧客名が自由入力で、テナントの顧客マスタを検索する（住宅事業だけの人は 403）・保存しても `customer_id` と食い違う（業務の判断が要る）（→ ⑥ は 2026-11-20 に案 A で直した。下の「注文住宅の編集画面でも買主を選ぶ」の節・Bug #144）
- D4: 契約のあとで区画を変える・お客様の土地に変えると、区画の状態（販売済み）が連動しない／横断画面の編集も連動しない（業務の判断が要る）
- D5: 土地原価の手入力を外しても、前の手入力の値が残る（業務の判断が要る）（→ 2026-11-20 に案 A＋b で直した。下の「土地原価の手動入力をやめたら紐付け先から計算し直す」の節・Bug #143）
- 買主の姓・名がともに 50 文字だと顧客名（100 文字）を超えて 500（現実には起きない）／採番の文字列の並び（HS-999 の次）／顧客の検査が 2 段に分かれて出る／契約一覧の `?keyword[]=`・`?page=abc` の 500（手で組んだ URL）
- 建売の編集画面で区画の一覧の API を叩くときの `current_property_id`（自分の区画を残す）を見るテストは無い（編集画面は区画を hidden で送り、一覧を使わない）
- 375px の顧客の登録画面のはみ出し（上）

### 本番反映（2026-10-06 実施）

利用者の承認のあと（本番の読み取り・早送りと `./deploy.sh`・反映後の読み取り・Chrome の確認のそれぞれで承認を取った）、`13.x` を `99d5f1ed` から **`97da2c87`** へ早送りした（12 コミット）。`13.x` は作業の途中から進んでいなかったので、取り込みは要らなかった。全件は作業場所で **OK (3693 tests, 29985 assertions)**（PHP 8.3）。反映のあいだは決裁の会話に `13.x` の早送り・コミットと `./deploy.sh` を待ってもらい、終わってから知らせた。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存・CSS の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo・作業場所 | 13.x は `99d5f1ed` のまま・作業枝 `97da2c87` へ早送りできる ／ 差はアプリのファイル 21 本（app 8・views 13）だけ（ほかは `tests/`・`docs/`・`CLAUDE.md`）・依存／DB／ルート／設定／CSS／JS／lang の差なし ／ main repo の作業ツリーは空・`vendor/bin/phpunit` は無い |
| 反映の前（本番の読み取り） | 21 本・記録・キャッシュ・build | 21 本とも 13.x（`99d5f1ed`）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 から更新なし ／ `bootstrap/cache` は 600／700（10/5 14:49 に作り直し）／ build はアプリ側・公開側とも `app-F3f4AS97.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-06 21:01 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 21 本（一覧と突き合わせて一致）とビルド（manifest・CSS・JS。名前は前と同じ）・vendor は 0 本。旧バンドルの削除 0 件（ビルドの警告 1 件は以前から出ている Google Fonts の `@import` の位置のもの）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 21 本 | 手元（`97da2c87`）と **md5 が一致**（21/21） |
| | コンパイル済みビューの `php -l` | **298 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 21:01 に作り直し・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700 ／ build はアプリ側・公開側とも `app-F3f4AS97.css`・`app-NiVQbl_Q.js` だけ |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 注文住宅の編集（CO-001・CO-002。分譲地区画が土地元の 2 件） | どちらも 200・区画の選択欄に今の区画が選ばれている（6号地・2号地。詳細画面の区画と一致）（H1） |
| | 建売の新規登録・編集（HS-013） | どちらも 200。新規は空の初期値（`landSourceType: ''`・`lots: []`）、編集は `project_lot`・区画 16・区画の一覧 4 件で組み立てられ、所在地は `Js::from` の形（`\u…`）（H2・H3） |
| | 建売の詳細（HS-013）・注文住宅の詳細（CO-001） | どちらも 200・経営層なので「編集」「削除」「アップロード」が出る（H7） |
| | 契約の編集（建売の契約 4・注文住宅 CO-001） | どちらも 200・`datePicker('2026-09-23')`・`customOrderEditForm({ landSourceType: 'project_lot', …, reProjectLotId: '38', … })` の `Js::from` の形で、契約日・区画の欄も同じ値（H3） |
| | 住宅事業ダッシュボード・顧客の登録・注文住宅の新規登録 | どれも 200（ダッシュボードは `?fiscal_year=&period=` も 200 ＝ H8）・画面の script はすべて構文エラーなし |
| | はみ出しとコンソール | 11 画面とも 1440px 幅で `main` のはみ出し 0・コンソールのメッセージ 0 件 |

⚠ 登録・更新・削除・上限を超える値の送信は、本番では送っていない（本番のデータを変える操作のため）。動きはテストとローカルの実ブラウザで確かめてある。

⚠ 注文住宅の契約の編集（CO-001）の買主の欄は「— 買主を選択してください —」のままだった。選択欄を描く処理は今回変えていないので、元からこのデータに買主が紐づいていない可能性が高い（範囲外の D3 と近い）。

⚠ `origin/13.x` への push はしていない。→ 2026-10-06 に利用者の指示で push 済み（`c26be125..b77a2707`）。

---

## ✅ 賃貸マンションのテストで一度も通らないルートをなくす（43 本）— 本番反映済み

実装計画: `~/.claude/plans/glistening-sparking-waterfall.md`（リポジトリの外。要点はこの節と docs/RULES.md Bug #101〜#109・#29・#37・#73・#76・#85・#93・#96・#97 の追記）

上の住宅事業の節に続く 6 つ目（2026-10-07）。作業場所 `.claude/worktrees/route-coverage`（枝 `mansion-coverage`）。**DB 変更・ルート変更・依存・CSS の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。

測定（道具 `.claude/route-coverage-tools/`。全件 OK 3693 本）で、482 本中 80 本が一度も成功まで通らず、賃貸マンション（`mansion/`）は **43 本**。契約の登録画面の JS が叩く API 2 本（`api/mansion/properties/{property}/vacant-rooms`・`vacant-parkings`）も一緒に通した。範囲外: `api/reverse-zip`（外部の HeartRails を cURL で呼ぶ。ヘッダーだけ見た＝Bug #106）。

| 区分 | 本数 | ルート |
|---|---|---|
| 物件 | 7 | index・create・store・show・edit・update・destroy |
| 部屋 | 6 | create・store・edit・update・destroy・updateStatus（画面から呼ばれていない。サーバの約束だけ見た） |
| 駐車場 | 5 | create・store・edit・update・destroy |
| 入居者 | 8 | index・create・store・show・edit・update・destroy・application |
| 部屋契約 | 7 | index・create・store・edit・update・revise.show・revise |
| 駐車場契約 | 10 | index・create・store・show・edit・update・revise.show・revise・terminate.show・terminate |

着手前に本番を読み取りで確かめた（2026-10-07・承認のうえ・`SHOW CREATE TABLE` と件数だけ）: 物件 17 件・部屋／駐車場／入居者／契約／駐車場契約／改定／差引は**すべて 0 件**＝下の欠陥はどれも本番では発火前。表の定義（9 表）はテスト用スキーマと一致。金額はすべて `INT UNSIGNED`・総戸数 `SMALLINT UNSIGNED`・階 `TINYINT UNSIGNED`・専有面積 `DECIMAL(8,2)`。外部キーは契約→部屋・駐車場・入居者が `ON DELETE RESTRICT`、部屋・駐車場→物件が CASCADE（テスト用スキーマには外部キーが無い）。築年月の形の崩れ・オーナー名の食い違い・状態と契約の食い違いはどれも 0 件。

| # | 見つけたこと | 直したこと |
|---|---|---|
| M1（Bug #101） | 削除の画面は「契約がある場合は削除できません」と書くのにコードは消すだけ（本番は外部キーで 500・テストは黙って消える） | 契約（解約済みを含む）と部屋・駐車場の状態を数えて、理由を帯に出して戻す（部屋・駐車場・入居者・物件） |
| M2（Bug #102） | **部屋契約を 2 回送ると同じ部屋に契約中が 2 件**（駐車場契約も同じ） | 入力チェックで空室・空き・同じ物件を見て、ロックの中で確かめ直す |
| M3（Bug #103） | 契約中の部屋を編集画面で「空室」に変えられ、空室の一覧に出て二重に契約できる（駐車場も同じ） | 契約中は「空室」「申込み・仮押え」（駐車場は「空き」）に変えさせない |
| M4・M5（Bug #104） | 解約画面で入力エラーのあと外した駐車場のチェックが全部付き直る（残すつもりの駐車場契約まで解約される）・差引の行が消える。改定で空にした欄が 0 円で描かれる | 前の入力どおりに描く・空欄は今の値で描く |
| M6（Bug #73 の形） | 金額・数の入力 17 か所と総戸数・階数・部屋の階・専有面積に上限が無い | 列の上限（基底 `Controller` に `MAX_UNSIGNED_INT_COLUMN` を足した）。走査で固定 |
| M7（Bug #85 の形） | 退去日が入居日より前・利用終了日が利用開始日より前でも解約できる | `after_or_equal:` 入居日（無ければ契約日）／利用開始日（無ければ契約日） |
| M8（Bug #96 の形） | ダッシュボードの「新規契約」「契約登録」が一般担当にも出て 403 | 経営層・管理者だけに出す |
| M9（Bug #97 の形） | 手で組んだ URL（`?fiscal_year=abc`・`?property_id[]=1`・`?keyword[]=a`・`?room_id[]=1` など）で 500 | 基底 `Controller::ignoreMalformedQuery()` |
| M10（Bug #76 の形） | 物件フォームが所有形態と築年月を引用符で直に JS へ書く（`2020\` で編集画面の JS が止まる）。築年月はどんな 7 文字でも保存できた | `Js::from`・築年月は `date_format:Y-m` |
| M11（Bug #105） | 部屋の登録画面の「内容をコピーして追加」が効かない | 号室番号のほかを入れて登録画面へ戻す |
| M12（Bug #106） | 物件と買主（住宅事業・不動産で共用）の「住所→〒」が XHR で `X-Requested-With` なしに GET | ヘッダーを足し、走査テストを XHR の GET へ広げた |
| M13（Bug #93 の形） | 部屋契約・駐車場契約の登録で入力エラーのあと、選んだ部屋・駐車場が消える | `:selected` |
| M14（Bug #107） | 解約・改定のダブルクリックの 2 回目が 403 の英語の画面 | 詳細へ戻して理由を出す |
| M15（Bug #109） | 管理受託のオーナー名に「*」が付くのに必須でない・自社所有でも隠れた欄のオーナー名が保存される | 管理受託のときは必須・自社所有のときは保存しない |
| M16（Bug #37 の形） | 部屋を選ばずに送ると「room id は必須です」と英字 | 和名を足し、画面と違う項目名は第 3 引数で上書き |
| M17（Bug #108） | **実ブラウザで見つけた**: 編集画面の削除の欄が保存のバーの後ろに隠れ、ボタンの上の端しか押せない | 削除の欄の下にバーの高さの余白 |
| M18（Bug #29 の形） | **実ブラウザで見つけた**: ダッシュボードの 2 列が表の幅で広がり、1440px でも 196px はみ出す（本番は部屋 0 件で未発火） | `minmax(0, 1fr)` |

| 区分 | 実装内容 |
|------|---------|
| アプリ | 基底 `Controller`（`MAX_UNSIGNED_INT_COLUMN`・`ignoreMalformedQuery()`）・`Mansion\{Property,Room,Parking,Tenant,Contract,ParkingContract}Controller`・`lang/ja/validation.php`（4 項目）・画面 11 本（物件の `_form`・物件／部屋／駐車場／入居者の編集・契約と駐車場契約の登録・解約・賃料改定・料金改定・ダッシュボード）・買主の `_form`（「住所→〒」の 1 行） |
| テスト（道具） | `tests/Concerns/ComposesScreenForms.php`（住宅事業の土台にあった `composedForm()`・`apiResponse()` を共用へ移した） |
| テスト（新規） | `tests/Feature/Mansion/Screens/` に土台 `MansionScreenTestCase`（削除フォームは DELETE を送るものを選ぶ `deleteForm()`）と `PropertyScreensTest`・`RoomScreensTest`・`ParkingScreensTest`・`TenantScreensTest`・`ContractScreensTest`・`ParkingContractScreensTest`・`DashboardScreenTest`・`ListQueryTest`・`AmountLimitTest`・`ScreenLayoutTest`（79 本）／ `AjaxFetchSessionGuardTest` に XHR の走査 |
| 全件テスト | 3693 → **3848 tests / 32538 assertions green**（PHP 8.3。13.x〈決裁の段階4b・ページ送り〉を取り込んだあと。うち賃貸マンションの分は 80 本＝新しい 79 本と XHR の走査 1 本）／ コンパイル済みビュー **299 本 / INVALID 0** |

### 検証

- 新しいテストは直す前に 39 本が赤（どれも直すべき所。既存のテストは全部緑）を確かめてから直した。直し方ごとに、直しを一時的に外して赤の理由を確かめた（解約・改定の画面の 3 本は、画面の直しを外して「付き直る」「0 円で描かれている」で落ちることを見た）
- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いた・`.claude/route-coverage-tools/mutate/`。表は `specs.py`、住宅事業の表は `specs.housing-example.py` に残した）: **58 通り＋カナリア**。57 通りが期待どおりの集合と理由で落ち（等価の M13〈駐車場契約の入力チェックの状態の条件だけ外す＝ロックの中が同じ文言で止める〉は期待どおり緑）、1 通り（W04: 部屋の選択欄の `x-model` を外す）は「PHP の死角で緑」と書いたのに構造のテストが拾える選択欄の数で落とした＝見込みより強かった（住宅事業の W02 と同じ）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright。使い捨てのログイン用ルートは戻した）: M13（入力エラーのあと部屋 101・駐車場が選ばれたまま→入居者を選んで登録できる。`:selected` を一時的に外すと Alpine の値は `1` のまま選択欄は空＝PHP の死角を確かめた）・M4／M7（退去日を入居日より前にして送ると理由が出て、外した 2 台のチェックは外れたまま・差引の行は残り返金額も計算し直される→退去日を直して解約すると駐車場契約は 2 件とも契約中のまま）・M11・M3（理由が出る）・M1（帯に理由・部屋は残る）・M10（引用符と円記号の入った古い築年月でも編集画面の JS が止まらない）・M8（一般担当には登録のリンクが出ない）・26 画面が 1440px・375px とも 200・コンソールのエラー 0
  - そこで M17・M18 を見つけた（M17 は Playwright のクリックが「保存のバーがクリックを奪う」で止まって気づいた）。直したあと 1440px・375px とも、削除のボタンの上・中・下の 3 点が押せ、ダッシュボードの横スクロール 0
  - ⚠ 375px で、部屋契約・駐車場契約の詳細・改定・解約の画面（49〜122px）と部屋の登録・編集（21px）・入居申込書（1px）が横にはみ出す（今回触っていない既存のもの。範囲外）
- 測り直し（全件 OK (3848 tests, 32538 assertions) と同じ実行）: 賃貸マンションの「一度も通らない」**43 → 0**、契約の登録画面が叩く API 2 本も **0**。全体は 80 → **35**（ルートは決裁の段階4b で 482 → 484 本）。残りは DAD 28・`storage` 2・`api/reverse-zip`（外部を呼ぶ）・ログアウトなど

### 範囲外（気づいたが直していない）

- D5: 部屋契約の入居者を「居住者」に限るか（画面は居住者だけを出すが、サーバは駐車場のみ利用者も通す。手で組んだ送信だけ。業務の判断が要る）
- `mansion.rooms.updateStatus` は画面から呼ばれていない（消すかは業務の判断）
- 改定のダブルクリックで同じ改定履歴が 2 行できる（値は同じ）
- 入居者を削除しても入居申込書の添付が残る（入居者 0 件）
- 採番 `generateNextCode()` の脆さ（Bug #54 ⑦）
- 375px の横のはみ出し（上）
- 不動産の契約 `ReContractController` の入力チェックで和名の無い 5 項目（`contract_type` ほか。変数に組む書き方で `JapaneseValidationMessagesTest` の走査に見えない。Bug #37 の追記）
- DAD の編集画面にも M17 と同じ形がある見込み（DAD の回で）

### 本番反映（2026-10-07 実施。下の和名の直しと一緒に）

利用者の承認のあと（本番の読み取り・早送りと `./deploy.sh`・反映後の読み取り・Chrome の確認・記録のそれぞれで承認を取った）、`13.x` を `d0e3fb29` から **`a64bdba2`**（枝 `attribute-name-scan`。この節の枝 `mansion-coverage` = `7f6e882e` を含む）へ早送りした（31 コミット）。`13.x` は作業の途中で決裁の会話の作業（段階4b・Bug #100）で進んでいたので、作業場所へ取り込んでから（`24435a78`・`7f6e882e`・`a64bdba2`。`7f6e882e` では `docs/RULES.md` の衝突を直し、Bug #100 の行と #101〜#109 の両方を残した）全件を流し直して **OK (3886 tests, 32795 assertions)**（PHP 8.3・`70a144ac`。`a64bdba2` との差は `docs/BACKLOG.md` の 1 行だけ）。反映のあいだは決裁の会話に `13.x` の早送り・コミットと `./deploy.sh` を待ってもらい、終わってから知らせた。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存・CSS の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo・作業場所 | 13.x は `d0e3fb29`（`cd6956cb` ＋ `docs/BACKLOG.md` の 1 行）のまま・枝 `a64bdba2` へ早送りできる ／ 差はアプリのファイル 21 本（app 8・lang 1・views 12）だけ（ほかは `tests/`・`docs/`・`CLAUDE.md`）・依存／DB／ルート／設定の差なし ／ main repo の作業ツリーは空・`vendor/bin/phpunit` は無い ／ 作業場所も `a64bdba2` で作業ツリーは空 |
| 反映の前（本番の読み取り） | 21 本・記録・キャッシュ・build | 21 本とも 13.x（`d0e3fb29`）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 から更新なし ／ `bootstrap/cache` は 600／700（10/7 13:23 に作り直し。決裁の会話の反映）／ コンパイル済みビュー 299 本 ／ build はアプリ側・公開側とも `app-CuFJhCCA.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-07 13:41 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 21 本（一覧と突き合わせて一致）とビルド（manifest・CSS・JS。名前は前と同じ）・vendor は 0 本。旧バンドルの削除 0 件（ビルドの警告 1 件は以前から出ている Google Fonts の `@import` の位置のもの）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 本番の読み取り | 21 本 | 手元（`a64bdba2`）と **md5 が一致**（21/21） |
| | コンパイル済みビューの `php -l` | **299 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとの 14:05 にも読み直した）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 13:41 に作り直し・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700 ／ build はアプリ側・公開側とも `app-CuFJhCCA.css`・`app-NiVQbl_Q.js` だけ |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない） | 賃貸マンションのダッシュボード | 200・1440px で `main` のはみ出し 0（1220 = 1220）・空室と空き駐車場の 2 列は 568px ＋ 568px（M18）・経営層なので「新規契約」が出る（M8） |
| | 物件の編集（物件 2「ドミールS」） | 200・`propertyForm('self_owned')` が組み立てられる（M10）・一番下までスクロールすると削除の欄の下端 636px／保存バーの上端 674px で、削除ボタンの中央の一番上の要素が削除ボタン（M17） |
| | 部屋の登録（物件 2） | 200・「内容をコピーして追加」（`name="continue" value="1"` の submit）がある（M11。押すと部屋ができるので押していない） |
| | 賃貸マンションの契約の登録・不動産の契約の登録と編集（契約 6） | どれも 200・`contractForm()`・`contractEditForm()` が組み立てられる |
| | はみ出しとコンソール | 5 画面とも 1440px 幅で `main` のはみ出し 0・コンソールのエラー 0 件（読み込み直して測った） |

⚠ 登録・更新・削除・解約・改定・上限を超える値の送信は、本番では送っていない（本番のデータを変える操作のため）。本番の部屋・契約は 0 件なので、解約・改定の画面（M4・M5・M7・M14）と契約の登録の入力エラーのあと（M13）は本番では開けない。動きはテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。

---

## ✅ 入力エラーの項目名（和名）の漏れを直し、走査を広げる — 本番反映済み

実装計画: `~/.claude/plans/glistening-sparking-waterfall.md` の前半（リポジトリの外。後半は上の賃貸マンションの計画）。要点はこの節と docs/RULES.md の Bug #110

上の賃貸マンションの作業中に気づいた件（2026-10-07）。作業場所 `.claude/worktrees/route-coverage`・枝 `attribute-name-scan`（まだ出していない `mansion-coverage` の上に積んだ。13.x から切ると `lang/ja/validation.php`・RULES の Bug #37 の行・CLAUDE.md の件数が賃貸マンションの枝とぶつかるため。賃貸マンションだけ先に出すこともできる）。**DB 変更・ルート変更・依存・画面の変更は無し。新しい PHP クラスはテストだけ**。

| # | 見つけたこと | 直したこと |
|---|---|---|
| N1 | 不動産の契約の入力エラーに**英字の項目名**（「procurement idは必須です。」「cost amountは必須です。」「brokerage selling priceは整数で…」）。契約種別・区画も同じ | 和名を足した（契約種別・仕入れ案件・区画・原価・販売金額） |
| N2 | 仲介の物件の所在地の入力エラーが「住所は…」・成約の小窓の成約日が「契約日は必須です。」（画面のラベルと違う語） | 呼び出しの第 3 引数で上書き（全体の和名は住所・契約日のまま） |
| N3 | DAD の工事案件の原価の行（`Validator::make`）のカテゴリ・内容・協力業者に和名が無い | 和名を足した |
| N4 | **走査テストが緑だった** — `validate([ … ])` のリテラルしか読まず、変数で組む規則・`$this->rules()`・`Validator::make`・渡すだけの部品を見ていなかった | 字句で読む集め方（`tests/Concerns/CollectsValidationKeys.php`）に替え、読めない書き方は推測せず落とす。下限は呼び出し 140・キー 800（実測 146・848） |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `lang/ja/validation.php`（8 つ）・`RealEstate\ReContractController`（第 3 引数 2 か所） |
| テスト | 新規 `tests/Concerns/CollectsValidationKeys.php`・`tests/Feature/ValidationKeyScannerTest.php`（見本 23 本）／ `JapaneseValidationMessagesTest`（走査を新しい集め方に）／ `RealEstate/Screens/ContractScreensTest`（画面から送る 6 本） |
| 全件テスト | 3848 → **3877 tests / 32749 assertions green**（PHP 8.3） |

### 検証

- 新しいテストは直す前に赤（走査は 8 キーを名指し・画面のテストは「procurement id」「cost amount」「住所」「契約日」で落ちた）を確かめてから直した
- 新しい走査が今までの走査より狭くないこと: 今までの走査が集めたキー 569 が全部、新しい走査にも入ることを一度だけ突き合わせた（試作では同じ名前のクラスを 1 つに畳んで 151 キーを落としていたのを、これで見つけた）
- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いた・`.claude/route-coverage-tools/mutate/specs.py`。賃貸マンションの表は `specs.mansion-example.py`）: **40 通り（カナリアを含む）すべて期待どおり**（和名を 1 つずつ消す 8・上書きを外す・第 2 引数に書く・全体の住所を書き換える・走査の分かれ目 24・下限 2・呼び出しの場所の名前を認めない）。⚠ 分かれ目の多くは見本だけが捕まえる（実物のコードに出てこない書き方は、走査の本番が緑のまま。Bug #61 ③）
- 画面は変えていないので、ビューの lint と実ブラウザの確認は省いた

### 範囲外（気づいたが直していない）

- DAD の原価の見込み額・確定額は DAD の画面では「見積額」「実績額」（全体の和名は不動産の語。DAD の画面のテストを作るときに第 4 引数で上書きする）
- 走査が黙って見落とす書き方（`?->validate(`・`validator()` ヘルパ・別名の Validator・ValidatesRequests の `$this->validate($request, …)`・プロパティに持った規則）。今の実物には 0 件で、注記に書いた
- `app/Http/Requests/ProfileUpdateRequest.php` はどこからも使われていない（最初の Breeze の名残の可能性が高い）

### 本番反映（2026-10-07 実施。上の賃貸マンションと一緒に）

上の賃貸マンションの節の「本番反映」と同じ反映（`13.x` = `a64bdba2`）。この節の分は `lang/ja/validation.php` と `RealEstate\ReContractController` の 2 本で、どちらも本番の md5 が手元と一致した。不動産の契約の登録・編集（契約 6）はログインした Chrome で 200・コンソールのエラー 0 件。

⚠ 和名は入力エラーのときにしか出ないので、本番では見ていない（フォームを送らない）。テストで確かめてある。

---

## ✅ DAD のテストで一度も通らないルートをなくす（28 本）＋ 375px で横にはみ出す画面 — 本番反映済み

実装計画: `~/.claude/plans/majestic-sleeping-prism.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #111〜#114・#29・#73・#76・#82・#86・#96・#97・#105・#108・#110 の追記）

上の賃貸マンションの節に続く 7 つ目（2026-10-07）。あわせて、これまでの回で範囲外として記録してきた「375px で横にはみ出す画面」を直した。作業場所 `.claude/worktrees/route-coverage`（枝 `dad-coverage`。13.x = `3d5cad69` から作成）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスはテストだけ**（本番で `composer dump-autoload` は要らない）。途中で一度止め、同じ日に再開した。

測定（道具 `.claude/route-coverage-tools/`。全件 OK 3886 本）で、484 本中 35 本が一度も成功まで通らず、DAD（`dad/`）は **28 本**＝4 リソース × 7（index・create・store・show・edit・update・destroy）。工事案件・発注者・協力業者・従業員（専門分野マスタは各マスタの回で済み）。

着手前に本番を読み取りで確かめた（2026-10-07・承認のうえ・`SHOW CREATE TABLE` と件数だけ）: DAD の 6 表は **すべて 0 件**（専門分野マスタだけ 8 件・無効 0）＝下の欠陥はどれも本番では発火前。表の定義は `database/sql/create_dad_tables.sql` と一致（原価の内容 `VARCHAR(200)`・人員配置の役割 50・備考 200・金額 4 列 `INT UNSIGNED`・人員配置は (工事案件, 従業員) が一意）。DAD の部署の利用者は経営層 1・管理者 1。

| # | 見つけたこと | 直したこと |
|---|---|---|
| A1（Bug #110 の範囲外・#112） | 原価の行の入力エラーが不動産の語（「見込み額」「確定額」）で、何行目かも出ない | 第 4 引数で「原価明細 2 行目の見積額」の形（`:position`） |
| A2（Bug #73 の形） | 原価の内容を 500 文字まで通す（列は 200。Excel 取込は長さを切らない） | `max:200` |
| A3（Bug #73 の形） | 金額 4 か所に上限が無い | `MAX_UNSIGNED_INT_COLUMN`。DAD のコントローラの整数・数値の入力は走査で固定 |
| A4（Bug #112） | **人員配置をまったく検査していない**（日付 `abc`・同じ従業員の 2 行で 500 など） | 従業員・役割・備考・日付・配置終了は配置開始以降 |
| A5（Bug #86 の形） | 入力エラーのあと原価明細と人員配置の行が消える | 前の入力から行を組む（空の行も含めて同じ並び） |
| A6（Bug #113） | **退職した従業員の配置が、編集画面をそのまま保存するだけで消える**（実ブラウザで再現） | 選択肢と入力チェックを「在籍者 ＋ 今の配置」・「（退職）」 |
| A7（Bug #82 の形） | 協力業者の編集で、無効にした専門分野が保存で空になる | 「有効な分野 ＋ 今の分野」・「（無効）」 |
| A8（Bug #97 の形） | `?fiscal_year=abc`・`?keyword[]=` などで 500 | `ignoreMalformedQuery()` |
| A9（Bug #96 の形） | 押せない人に登録・編集・削除のボタン（本番の DAD の管理者に削除が出ていた） | ルートの役割で出し分け |
| A10（Bug #108 の形） | 編集画面 4 つと専門分野マスタの編集で「削除する」が保存のバーの後ろに隠れる | 余白。走査 `DeleteZoneBelowSaveBarTest` |
| A11（Bug #76 の形） | 日付の部品が値を引用符の中に直に JS へ書く | `Js::from` |
| A12（Bug #111） | **工事案件の詳細の地図の吹き出しに、工事名と現場住所が HTML のまま入る**（`<img onerror>` が実行される。計画の点検で見つけた） | 文字を逃がしてから組む |
| A13・A14（Bug #112） | カテゴリ・従業員を選んでいない行を黙って捨てる／検査が 3 段で誤りが一度に出ない | すべての欄が空の行だけ捨てる・1 回の検査 |
| A15（Bug #105 の形） | 発注者・協力業者のフォームの「住所→〒」が「本番実装で対応します」の alert だけ | 利用者の判断で外した |
| W1〜W5（Bug #114・#29） | 375px で 1〜229px はみ出す（賃貸マンションの契約の画面・入居申込書・部屋の登録と編集・買主の登録と編集・分譲地の区画・ZEAL の会員の詳細は 2 列目を黙って切り落とし・DAD 専門分野マスタの色は実ブラウザでだけ見つかった） | `grid-stack-sm`・`@media`・`min-width: 0`・`flex-wrap`。走査 3 本（`MobileLayoutTest`） |

| 区分 | 実装内容 |
|------|---------|
| アプリ | `Dad\{Project,Client,Subcontractor,Employee}Controller`・DAD の画面 14 本（工事案件の `_form`・日付の部品・詳細・一覧・編集、発注者・協力業者・従業員の一覧と編集と `_form`）・専門分野マスタの登録と編集・賃貸マンションの画面 11 本・ZEAL の会員の詳細と `zeal/plans/_form`・住宅事業の買主の選択の部品・`buyers/_form`・分譲地の区画 |
| テスト（道具） | `CreatesDadSchema` を本番の 7 表に広げた（一意制約は本番どおり）・`ComposesScreenForms::composedForm()` をフォームの中の同じ名前の入れ子の部品（日付の部品 7 つ）を全部組めるように広げた（フォームの外のものは数えない） |
| テスト（新規） | `tests/Feature/Dad/Screens/` に土台 `DadScreenTestCase`（人員配置の行の日付の部品は「行の値を送る hidden」に置き換えて組む `projectForm()`）と `ProjectScreensTest`・`MasterScreensTest`・`ListAndPermissionTest`・`AmountLimitTest` ／ `tests/Feature/DeleteZoneBelowSaveBarTest.php` ／ `MobileLayoutTest` に走査 3 本 ／ 不動産の顧客と区画・専門分野マスタに構造のアサート |
| 全件テスト | 3886 → **3934 tests / 37440 assertions green**（PHP 8.3） |

### 検証

- 新しいテストは直す前に 22 本が赤（どれも直すべき所。既存のテストは全部緑）を確かめてから直した。A15 と専門分野マスタの色も、テストを先に書いて赤を確かめた
- 変異（docs/RULES.md Bug #44 の作法・流す前に期待を書いた・`.claude/route-coverage-tools/mutate/specs.py`。和名の回の表は `specs.attribute-example.py`）: **56 通り（カナリアを含む）すべて期待どおり**。途中で一度止めたので、止めたときの途中の 1 通りは流し直し、それまでの 42 通りは各変異の JUnit から結果を復元した（止めると `finally` が走らず変異のファイルが残る → `git diff` で確かめて戻した）。等価の T03（道具がフォームの外の部品も数える）は見込みどおり緑
- コンパイル済みビュー **299 本 / INVALID 0**
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright。手順は `.claude/route-coverage-tools/browser/README.md`。使い捨てのログイン用ルートは戻した）:
  - A10: 5 画面 × 1440・375px で「削除する」の上・中・下の 3 点が押せる（`document.elementFromPoint()`）
  - A6: 「D-002 伊藤 次郎（作業員）（退職）」が選ばれたまま保存でき、配置が 2 件のまま残る
  - A5・A13・A1: 2 行目の見積額 `abc` と 3 行目のカテゴリ忘れで「原価明細 3 行目のカテゴリを選択してください。」「原価明細 2 行目の見積額は整数で入力してください。」が出て、行が残り、直して登録できる（⚠ 工事名を空にするとブラウザの必須の検査で送信自体が止まり、サーバに届かない）
  - A12: 吹き出しに渡る content を DOM に入れて `<img>`・`<b>` が 0、`onerror` が走らない
  - 375px: 33 画面（DAD の 16 画面を含む）× 375・1440px で `main` の横スクロール 0・黙って切れている所 0。ZEAL の会員の詳細は、直す前（クラスを外して測った）は 2 列目がカードの `overflow: hidden` に切り落とされていた
  - コンソールのエラーは、手元に地図の API キーが無いことによる Google Maps の 1 件だけ
- 測り直し: DAD の「一度も通らない」**28 → 0**。全体は 35 → **7**（残りは storage 2・`/`・`api/reverse-zip`〈外部を呼ぶ〉・決裁 1・logout・up）

### 範囲外（気づいたが直していない）

- D5: DAD の年度の始まり（工事案件の一覧の年度の絞り込みは 5 月始まり。CLAUDE.md は「ZEAL/DAD は 6/1 始まり」）— 業務の判断が要る（日本時間化の回にも記録済み）（→ 2026-11-19 に 6 月始まりにそろえた。下の「利用者の判断を待っていた 1〜3 を直す」の節・Bug #141）
- 工事案件を消しても添付は残る（本番の DAD の添付 0 件）／原価の協力業者が論理削除されていると詳細画面で会社名が空になる（削除の歯止めがあるので手で組んだ送信だけ）／人員配置は保存のたびに消して入れ直すので id と登録日時が変わる（今もそう）
- 不動産の仕入れ案件・分譲地の詳細と一覧の地図の吹き出しは名前を `{{ }}` で JS の文字列に書く（HTML としては逃がされているので A12 の形ではないが、`\` で終わる名前で JS が止まる Bug #76 の形の可能性。未確認）（→ 2026-10-26 に確かめて直した。「マップで確認」の小窓は A12 そのものの形だった。下の「仕入れ案件・分譲地の地図の吹き出しが名前を HTML のまま描く件」の節・Bug #119）
- 375px の走査の死角: 2 列の中の固定幅がインラインの `width: …px` の入力・折り返さない flex の行（Bug #114）
- ZEAL の会員の詳細で、空白の無い長いメールアドレスが 375px で 24px 切れ、641px 前後では 2 列目の値が 18px 切れる（本番の実データで見つけた。下の「本番反映」）。賃貸マンションの契約の画面の基本情報も同じ形の可能性がある（本番の契約 0 件で未確認）（→ 2026-10-08 に直した。同じ形の詳細画面 27 本もまとめて直した。下の「長いメールアドレス・URL・ファイル名が狭い画面で切れる件」の節。賃貸マンションの契約の画面の基本情報はメールを出さないので対象外＝走査で確認）

### 本番反映（2026-10-07 実施）

利用者の承認のあと（反映の前の読み取り・早送りと `./deploy.sh`・反映後の読み取りを続けて流した）、`13.x` を `3d5cad69` から **`2b3a2907`** へ早送りした（24 コミット）。`13.x` は作業のあいだ進んでいなかったので、取り込みは要らなかった。全件は作業場所で **OK (3934 tests, 37440 assertions)**（PHP 8.3）。反映のあいだは決裁の会話に `13.x` の早送り・コミットと `./deploy.sh` を待ってもらい、終わってから知らせた。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 34 本・記録・build | 本番へ行く 34 本（app 4・views 30）とも 13.x（`3d5cad69`）と **md5 が一致**＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 から更新なし |
| `./deploy.sh` | 2026-10-07 21:25 ごろ終了（日本時間） | exit 0。送ったアプリのファイルは 34 本（一覧と突き合わせて一致）とビルド・vendor は 0 本。CSS・JS の名前は前と同じ（`app-CuFJhCCA.css`・`app-NiVQbl_Q.js`）。3 つのキャッシュとも成功 |
| 本番の読み取り | 34 本 | 手元（`2b3a2907`）と **md5 が一致**（34/34） |
| | コンパイル済みビューの `php -l` | **299 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 21:25 に作り直し・`config.php`・`routes-v7.php` は 600 |
| ログインした画面（利用者の Chrome・見るだけ・フォームは送らない。同じ画面を幅 1440px・375px の iframe で開いて測った） | DAD の一覧 4 つ・登録 4 つ | どれも 200・`main` のはみ出し 0・切れている所 0・Alpine が組み立てられない部品 0 ／ 工事案件の登録の日付の部品 7 つが `datePicker(null)`（A11）／ 発注者・協力業者の登録に「住所→〒」が無く「〒→住所」はある（A15）／ `?fiscal_year=abc`・`?project_type[]=`・3 つの一覧の `?keyword[]=` が 200（A8） |
| | 専門分野マスタの編集（1）・登録 | 1440px・375px とも「削除」と、開いたあとの「削除する」の上・中・下の 3 点が押せる（A10。「削除」は表示を切り替えるだけのボタン。「削除する」は押していない）／ 色の 2 列は 375px で 1 列（W） |
| | 部屋契約の一覧・買主の登録（不動産）・分譲地の区画（RE-PRJ-019）・ZEAL の会員の詳細（#73）・部屋の登録（物件 2）| どれも 200・`main` のはみ出し 0 ／ 買主の生年月日の行に `flex-wrap`（W4）／ 部屋の登録の `flex: 1` の入力欄 6 つすべてに `min-width`（W3）／ ZEAL の会員の詳細の 2 列は 375px で 1 列（W1）。ただし下の ⚠ |
| | コンソール | 5 画面（工事案件の登録・専門分野マスタの編集・会員の詳細・買主の登録・部屋契約の一覧）を開き直してエラー 0 件（工事案件の登録で、以前からある Google Maps の `loading=async` の警告が 1 件） |

⚠ **本番の実データで直し残しが 1 つ見つかった**: ZEAL の会員の詳細（#73）で、空白の無い長いメールアドレス（約 25 字）が折り返さず、375px でカードの `overflow: hidden` に右端 24px 切れる。641px（1 列になる幅のすぐ上）では、そのメールが 1 列目を押し広げ（342px）、2 列目の値 5 つが 18px ずつ切れる（768px・1024px・1280px は切れない）。ローカルの検証用データはメールが短く、気づけなかった（Bug #114 の死角と同じ「中身で列が広がる」形。直す前は 375px で 2 列目が丸ごと切れていたので、悪くはなっていない）。直すなら `.zeal-info-value` に `overflow-wrap: anywhere` と、2 列を `minmax(0, 1fr)` にする（範囲外に書いた）。

⚠ 本番の DAD の 6 表は 0 件なので、工事案件の詳細・編集（A5・A6・A12）と、登録・更新・削除は本番では確かめられない。フォームは送っていない。動きはテストとローカルの実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。

---

## ✅ 長いメールアドレス・URL・ファイル名が狭い画面で切れる件（＋一覧の絞り込みの行）— 本番反映済み

上の DAD の節の本番の Chrome 確認で見つけた「ZEAL の会員の詳細の長いメールアドレスが切れる」直し残しから、同じ形を全ビューへ広げた（docs/RULES.md の Bug #115）。
**利用者の決定（2026-10-08・案 1）**: メールとファイル名に加えて、備考などの自由入力（URL を貼られうる）もまとめて直す。決裁の画面は「社内決裁申請」の会話の担当なので触らず、走査テストの例外にする。
作業場所 `.claude/worktrees/route-coverage`（枝 `zeal-email-wrap`。13.x = `a2f43bbc` から）。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（CSS は `wrap-anywhere` が入るので名前が変わる）。

### 数え直し（2026-10-08・Blade 279 本を走査し、使い捨ての環境で実測）

引き継ぎでは「固定幅のラベルの行があり、メールを出すビュー」8 画面としていたが、測り直すと次のとおりだった。

| 種類 | 画面 | 直す前の実測（使い捨ての環境） |
|---|---|---|
| メール（39 字） | 引き継ぎの 8 画面（ZEAL の体験予約・買主・仕入れ先・DAD の発注者と協力業者・賃貸マンションの入居者と申込書）＋ **テナントの顧客の詳細・問合せの詳細** | 375px で最大 109px はみ出す・641px で最大 70px 切れる（テナントの 2 画面は 641px で 15px・32px はみ出す） |
| ファイル名（49 字） | **住宅事業の建売・注文住宅のファイル一覧** | 375px で 85px 切れる（添付の部品は横スクロールの枠の中の表なので切れない） |
| 備考などに貼った URL（95 字） | 詳細画面の自由入力の欄（備考・メモ・内容・理由など） | 375px で `main` が 253〜413px はみ出す・641px でも 24px（Chrome は `/` でも折り返さない） |
| `table-layout: fixed` の表の中（独立の点検で追加） | 原価の備考・改定の理由・周辺ビルの調査の所見（8）と修繕の一覧の説明（1） | セルからあふれて隣の列（操作のボタンなど）に重なる。修繕の一覧はセルが nowrap で、日本語の説明でも重なっていた |
| 削除の確認の小窓（独立の点検で追加） | 部品の属性 `$target`（修繕の詳細は説明の先頭 30 字を渡す） | 375px で赤い枠から 32px はみ出す |

### 実装

| 区分 | 内容 |
|------|------|
| 画面 | 33 ビュー・57 か所で、値を直に包む要素に `overflow-wrap: anywhere`（`<style>` のクラスの画面はそのクラス・Tailwind の画面は `wrap-anywhere`・インラインの画面は style）。修繕の一覧の説明は `block truncate`。注文住宅の「備考:」のラベルに `flex-shrink-0`（値が折り返せるとラベルが縦に割れた）。ZEAL の会員の詳細は先に `minmax(0, 1fr)` も入れた（`1b9f4a4a`） |
| テスト | 新規 `tests/Feature/LongUnbrokenValueWrapTest.php`（11 本: 本番の走査 5 本＋見本 6 本）。メール・ファイル名・TEXT 型の自由入力の名前と、部品が属性で受け取る値を、文字として出す箇所を全ビューから拾い、列の幅を中身で決める表の外なら、値を直に包む要素が折り返しか省略表示を持つことを全件分類で見る。ZEAL の会員の詳細は `tests/Feature/Zeal/MemberShowLayoutTest.php`（2 本） |
| 全件テスト | 3934 → **3947 tests / 37499 assertions green**（PHP 8.3。下の絞り込みの行のあとは 3949 / 37511） |

- 列の幅を中身で決める表（既定）のセルには付けない（幅の足りない表で列が 1 文字幅に潰れるため）。その表の長い値は表を広げてスクロールするだけ（`MobileLayoutTest` がどの表も横スクロールの枠の中にあることを守る）。**`table-layout: fixed` の表のセルには付ける**
- `break-words`（`overflow-wrap: break-word`）は値の最小の幅を縮めないので、`140px 1fr` の行や `1fr 1fr` の 2 列が広がるのを止められない（anywhere は縮める）
- 引き継ぎの走査の案（固定 px の後ろの素の `1fr` を全件で見る）は採らなかった。その形は 44 か所あり、入力欄のフォームは `minmax(0, 1fr)` にすると入力欄が枠からはみ出しうる。長い値の側で止めれば列が広がる原因そのものが止まる
- 決裁の画面の例外は `approvals/` の先頭で外し、件数では持たない（件数で持つと、決裁の会話が値の出し方を変えるたびにそちらの作業でこのテストが赤くなる）

### 検証

- 走査テストは直す前に赤を確かめてから直した（1 回目 27 ビュー・47 か所／fixed の表 9 か所／削除の確認の小窓と「備考:」のラベル 2 本）
- コンパイル済みビュー **299 本 / INVALID 0**
- 変異（`.claude/route-coverage-tools/mutate/specs.py`・DAD の回の表は `specs.dad-example.py`）: 1 回目 38 通りのうち 37 通りが期待どおり・1 通り（S15）は検出したが落ちるテストの集合を書き誤った。点検を受けて書き直した最後のコードで **48 通り（カナリアを含む）すべて期待どおり**（落ちたテストの集合・理由の 1 行目・名指しされたファイルと行まで）。実行役に、理由の 2 行目以降を突き合わせる `contains` を足した
- 書いた直後の見本の 1 つ（`<style>` のコメントの中の規則）は、コメントを落とす処理を外しても緑だった → 見本を直して（`b3fea33e`）から変異を流した
- 独立の点検（2026-10-08・サブエージェント。読み取りだけ・実測なし）: Important 2（fixed の表の重なり・決裁の例外の件数）と Minor（`@php(...)` の短縮形・削除の確認の小窓・「備考:」のラベル・文書の食い違い）を、実ブラウザで確かめてから直した。見た目の副作用（768〜1024px でふつうの長さのメールも 2 行になる・Safari 15.4 未満は anywhere を無視して直す前の見た目に戻る）は受け入れた
- 実ブラウザ（使い捨ての環境・作業場所で CSS をビルド・Playwright）: 直した 25 画面 × 375・641・768・1024・1440px で、値が枠・セルから出る量 0・`main` の横スクロール 0・コンソールのエラー 0（直す前は同じ測り方で 375px で最大 413px）。⚠ 768〜1024px では長いメールが 2 行に折り返す（直す前は列が広がって 1 行だった）

### 範囲外（気づいたが直していない）

- 決裁の画面（申請の詳細・回覧の履歴・差戻しの画面などの 9 か所が `break-words`・送れなかった通知メールの帯の宛先 `$mailFailure['to']`）— 「社内決裁申請」の会話へ知らせる
- 一覧の表のメール・備考（横スクロールの枠の中で表が広がる）
- 走査の死角: `FIELDS` に無い名前の項目（新しい自由入力は名前を足す）・属性（`:title` など）・JS で入れる値・`COMPONENT_VALUES` に無い部品の属性・`<style>` の子孫セレクタ

### 一覧の絞り込みの行のはみ出し（同じ枝で続けて直した・Bug #116）

上の実ブラウザ確認で、修繕の一覧が 641px で `main` を 56px はみ出していた（絞り込みの行。以前から）。利用者がこの作業をこのセッションで行うよう指示（2026-10-08）。
- 一覧 42 本を 375〜1440px（13 幅）で測ると、駐車場契約・仕入れ案件・テナントの投資・修繕・部屋契約が 640〜1024px ではみ出した（最大 164px）。原因は全部同じで、絞り込みの行（`flex flex-col sm:flex-row`）が折り返さない
- 横に並べる絞り込みの行 21 本に `sm:flex-wrap`（本番の長い選択肢でははみ出しうるので、使い捨てのデータではみ出さなかった一覧も同じ形で直した）
- 走査テスト `MobileLayoutTest::test_filter_forms_laid_out_in_a_row_can_wrap`（全件分類・下限 20〈実測 26〉）と見本 1 本。直す前は 21 本が赤
- 変異 10 通り（カナリアを含む）すべて期待どおり（見本の穴 2 つを変異で見つけて直した）。全件 **OK (3949 tests, 37511 assertions)**・lint 299 本 / INVALID 0
- 実ブラウザ: 一覧 39 本 × 13 幅で `main` と絞り込みの行のはみ出し 0（使い捨ての DB に表が無い 3 本〈構造マスター・ZEAL の試算表とその項目マスター〉は開けないので除外）

### 本番反映（2026-10-08 実施。Bug #115・#116 を一緒に）

利用者の承認を段ごとにとって流した（①本番の読み取り ②早送りと `./deploy.sh` ③反映後の読み取り ④Chrome の確認）。途中で「社内決裁申請」の会話が決裁 段階5a を本番へ出したので、その反映が済むまで待ち、新しい 13.x（`caea86f8`）を枝へ merge して（`1fb16ef6`・衝突なし）全件 **OK (4064 tests, 38185 assertions)**・lint 305 本 / INVALID 0 を確かめてから進めた。13.x の早送りと deploy の前後は決裁の会話に知らせて待ってもらった。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 本番の読み取り | 変わるビュー 54 本の md5・記録・ビルド | 54 / 54 が 13.x（`caea86f8`）と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ CSS は `app-DYWPo_Ow.css`（5a のビルド） |
| ② 早送り | main repo の `13.x` | `caea86f8` → `1fb16ef6`（作業ツリーは空・`vendor/bin/phpunit` は無い） |
| | `./deploy.sh` | 2026-10-08 17:00 に exit 0・6 段すべて。送ったアプリのファイルはビュー 54 本（一覧と一致）とビルドだけ。CSS が `app-DLvcnZfi.css` に変わり、旧 CSS `app-DYWPo_Ow.css` を 2 か所で削除。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ③ 反映後の読み取り | ビュー 54 本の md5 | 手元（`1fb16ef6`）と **54 / 54 一致** |
| | コンパイル済みビューの `php -l` | **305 本 / INVALID 0** |
| | 記録・キャッシュ・ビルド | `laravel.log` は Jun 18 17:41 のまま（④ のあとにも読み直した）＝反映のあとのエラー 0 件 ／ `config.php`・`routes-v7.php` は 600・`packages.php` は 700 ／ ⚠ `services.php` が 755（前回までは 700。秘密は入っていない。deploy.sh が名簿を消してから作り直すまでの間に、画面へのアクセス〈Web の PHP は umask 022〉が先に作った可能性が高い。本番は書き換えていない） ／ CSS はアプリ側・公開側とも `app-DLvcnZfi.css` だけで md5 が手元のビルドと一致 |
| ④ Chrome（利用者の Chrome・見るだけ・同じ幅の iframe で測った） | ZEAL の会員の詳細（#73・本番の長いメール） | 375・641・768・1024・1440px で、メールが枠の内側に収まる（直す前は 375px で 24px・641px で 2 列目が 18px 切れていた）・`main` のはみ出し 0 ／ 読み込んだ CSS は `app-DLvcnZfi.css`・値の欄の `overflow-wrap` は `anywhere` ／ 開き直したときのコンソールのエラー 0 件 |
| | 駐車場契約・仕入れ案件・修繕・テナントの投資・部屋契約の一覧 | 641px・1024px で `main` のはみ出し 0・絞り込みの行の中に収まる |

⚠ 登録・更新・削除は本番では送っていない。⚠ `origin/13.x` への push はしていない（origin/13.x = `caea86f8`）。

---

## ✅ 一覧のページ送りを部品 `<x-pager>` にそろえる — 本番反映済み

設計書: @docs/superpowers/specs/2026-10-13-list-pager-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-10-13-list-pager.md

決裁 段階2b の「後回し」に残した「ページ番号を全部並べるほかの一覧（31 か所）」を直した（docs/RULES.md の Bug #117）。
利用者の依頼（2026-10-08 の引き継ぎ）・設計の承認（2026-10-13）。決裁の会話と合意したこと: `PageNumbers` を `App\Support` へ移す・
`approvals/_pager` は部品を呼ぶ包みとして残す・決裁の 2 本（自分の申請・利用者の管理）はこちらで置き換える・決裁の 3 つのコントローラから `pages` を消す。
13.x（決裁 段階5b）を取り込んだときに、紙の決裁の一覧（`PaperRecordController`・`approvals/admin/papers/index`）も同じ形に直した（2026-10-22）。
**DB 変更・ルート変更・依存の変更は無し。PHP クラスの置き場所が 1 つ変わる**（`App\Support\Approval\PageNumbers` → `App\Support\PageNumbers`）ので、
本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る。

| 区分 | 実装内容 |
|------|---------|
| 部品 | `resources/views/components/pager.blade.php`（`<x-pager :paginator="$x" class="…" />`。番号は先頭・最後・今のページの前後だけ・今のページは最後のページまでに抑える） |
| Support | `App\Support\PageNumbers`（`App\Support\Approval\PageNumbers` を移しただけ） |
| 画面 | 31 本（基幹 29・決裁 2）のページ送りの塊を 1 行に。外側の箱は 3 通り（`px-4 py-3 border-t border-gray-200` 21 本・`mt-4` 7 本・`py-3 border-t border-gray-200` 3 本）。決裁の `approvals/_pager` は包み |
| 決裁のコントローラ | `AdminRequestController`・`NoticeController`・`LedgerController` と、5b を取り込んだあと `PaperRecordController` から `pages` を消した（ページ番号を抑える処理は残す） |
| テスト | 新規 `PageNumbersTest`・`PagerComponentTest`・`PagerUsageScanTest`・`PagerRoundTripTest`・`Tests\Concerns\ReadsPager` ／ `HomeAndListTest`・`PageNumberClampTest` に 1 本ずつ。4064 → **4105 tests**（13.x の 5b を取り込んだあとは 4223） |
| ルート / DB | **どちらも変更なし** |

### 見た目の変化（設計書 §3.5）

- 番号が先頭・最後・今のページの前後だけになる（間は「…」）
- 不動産・住宅事業の 6 本の番号に `transition-colors`（ホバーの色の変化）が付く
- 買主の一覧の枠線の色が、インラインの `#e5e7eb` から Tailwind の `gray-200` になる（ほぼ同じ色）
- 外側が `<div>` から `<nav aria-label="ページ送り">` になり、今のページに `aria-current="page"` が付く

### 範囲外（設計書 §4）

- コントローラでページ番号を最後のページまでに抑えること（基幹の SQL の一覧は、行の無いページで表が空になるまま。部品は落ちない）
- ページ番号を直接打って移る入力欄・「最初へ」「最後へ」のボタン・1 ページの件数・ボタンの見た目

### 本番反映（2026-10-26 実施）

利用者の承認を段ごとにとって流した（計画の Task 8）。決裁の会話（社内決裁申請）が 5b を先に出したので、こちらが 13.x（`53b00db9`）を取り込んで合わせた（`52a75844`・RULES の当たりは #117 → #118 で両方残す）。取り込むと 5b の紙の決裁の一覧（`PaperRecordController`）が消えたクラスを読んで開けなかったので、`<x-pager>` の形に直した（`9fc2bb3b`）。全件 **OK (4223 tests, 40130 assertions)**（PHP 8.3）・コンパイル済みビュー 312 本 / INVALID 0。`13.x` = **`24207618`**（`53b00db9` から早送り）。**DB 変更・ルート変更・依存の変更は無し**（SQL は流していない。クラスの置き場所が変わるので main repo の cwd で `composer dump-autoload --no-dev --optimize`）。決裁の会話は 10/13 から止まっていたので、13.x の早送りと deploy の知らせは送れなかった（同時に 13.x を動かす会話は無いことを確かめた）。

| 段 | 見たこと | 結果 |
|---|---|---|
| ① 早送り | main repo の `13.x` | `53b00db9` → `24207618`（作業ツリーは空・`vendor/bin/phpunit` は無い）|
| ② 読み込みの表 | `composer dump-autoload --no-dev --optimize`（main repo の cwd） | 7,312 クラス・`'App\\Support\\PageNumbers'` が 1 行・古い名前は 0・作業場所のパスは 0 |
| ③ 本番の読み取り（反映の前） | 変わる 41 本（古いパス）の md5・新しい 2 本・記録 | 41 本とも `53b00db9` と一致・新しい 2 本は無い ／ `laravel.log` は Jun 18 17:41 ／ build は `app-BGXzsqXz.css`・`app-NiVQbl_Q.js` |
| ④ `./deploy.sh` | 2026-10-26 08:57:08〜08:57:19（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは期待の 42 本と完全一致（ほかは読み込みの表 2 本とビルド 3 本）。CSS・JS の名前は前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| ⑤ 本番の読み取り（反映の後） | 42 本の md5・classmap・ビュー・記録 | 42 本とも手元（`24207618`）と一致 ／ classmap は `App\Support\PageNumbers` 1 行・古い名前 0 ／ コンパイル済みビュー **312 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま ／ `bootstrap/cache` は 08:57 に作り直し（`config.php`・`routes-v7.php` 600・名簿 2 つ 700）|
| ⑥ 古い `app/Support/Approval/PageNumbers.php` | rsync が消さないので本番に残っていた | 利用者の決定で消した（古い名前を参照するファイル 0 本・md5 が `53b00db9` の版 `aa10b3c3…` と一致するときだけ消す形）。消したあともログイン画面 200・`laravel.log` は Jun 18 17:41 のまま。⚠ 最初にお送りしたコマンドは、本番の csh が二重引用符の中の `\"` を読めずに止まった（何も実行されていない）→ `ssh … /bin/sh < 手順ファイル` の形で流した |
| ⑦ Chrome（利用者の Chrome・見るだけ・同じオリジンの iframe） | テナント契約一覧（すべて） | 本体・375px・1440px とも `nav aria-label="ページ送り"`・ボタン 6 個すべて **32px**（前は 18.7px）・切れ 0・`main` の横スクロール 0・1 ページ目 `< 1 2 … 13 >` ／ 7 ページ目（375px）`< 1 … 6 7 8 … 13 >`・「<」「>」は 6・8 ページでどちらも「すべて」を保つ ／ 読み込み直したあとのコンソールの出力 0 件 |

⚠ `origin/13.x` への push はしていない（origin/13.x = `53b00db9`）。→ 2026-10-26 に利用者の指示で push 済み（`53b00db9..3887e71f`）。

### 後回しにした小さな指摘の直し（2026-10-26）— テストとコメントだけ

本番反映の最後の点検で「今は直さない」とした 4 件を直した（作業場所 `route-coverage`・枝 `pager-minors`）。**本番の動きは変わらない**（1・2・4 はテストだけ、3 はコメントだけ）。

| # | 見つけたこと | 直したこと |
|---|---|---|
| 1 | 部品のボタンの class（32px の正方形 `w-8 h-8`・文字を真ん中に・今のページだけ緑）を固定するテストが無かった。直す前に変異で測ると、ボタンの class を変える 14 通りのうち 13 通りはどのテストも落ちなかった（今のページの緑を消す 1 通りだけ `ProcurementListWithProjectsTest` が拾った） | `PagerComponentTest::test_every_button_is_a_32px_square`（220 件の 1・6・11 ページ目で部品の 7 種類のボタンを全部通る。どのボタンも `w-8` `h-8` `flex` `items-center` `justify-center` `text-xs`・大きさの class は `w-8 h-8` だけ〈`sm:w-10` なども数える〉・今のページだけ `bg-emerald-600` `text-white`・「…」のほかは `rounded` `border`）。`ReadsPager` にボタンごとの class（`classes`）を足した |
| 2 | 走査の印に `lastPage` が無く、`@for` で番号を並べ `->url($i)` でリンクを組む手作りのページ送りが見えなかった（直す前に顧客一覧へ差し込んでも緑） | `PagerUsageScanTest` の印に `->lastPage(`・`->currentPage(`・`->perPage(` を足した（足した時点で部品の外のビューに 0 件）。`->url(` は台帳の `$row->url()`（ページ送りでない）があるので印にしない。見本で、`@for` の形が当たることと `$row->url()` が当たらないことを固定 |
| 3 | `Tenant\PropertyController` のコメントが「既存ビューはインライン番号付きページネーション（hasPages / getUrlRange / nextPageUrl）を使っている」のままだった。同じ古い説明が `ProcurementListWithProjectsTest` の注記にも 1 か所あった | 「ページ送りは部品 `<x-pager>` で描く（Bug #117）」に直した。アプリのファイルはコメント 1 か所だけで、本番へは次の `./deploy.sh` で送られる（動きは変わらない） |
| 4 | 決裁の `AdminRequestsTest` が `ReadsPager` と同じ読み方の私有の `pager()` を持っていた | `ReadsPager` を使うようにした（こちらはページ送りがちょうど 1 つあることも確かめる）。直す前は、ページ送りを 2 つ描いても通っていた（変異 A02） |

- 全件 **OK (4227 tests, 40406 assertions)**（PHP 8.3.35。4223 本に 4 本＝データセット 3 と見本 1）・メモリのピーク 444.50 MB（変わらず・上限 512M）。ビューは変えていない
- 変異 22 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.pager-minors.py`）を直した後に流し、すべて期待どおり。直す前の HEAD（13.x = `09e087f8`）でも同じ表の 18 通りを流し、期待どおり緑（穴）・赤だった。記録は計画書の実測記録
- 決裁の会話（社内決裁申請）は 2026-10-13 から止まっていることを確かめてから、4 の決裁のテストに触れた
- 2026-10-26 に利用者の指示で 13.x へ早送りし、origin へ push した（`09e087f8..22fcf6c8`）。本番への反映はしていない（本番のコードは `24207618` のまま。アプリで変わったのはコメント 1 か所だけなので、次の `./deploy.sh` で一緒に送られる）

---

## ✅ 仕入れ案件・分譲地の地図の吹き出しが名前を HTML のまま描く件（Bug #119）— 本番反映済み

実装計画: `~/.claude/plans/hazy-sparking-rabin.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #119）

DAD の回（2026-10-07）に「未確認」として残した件を、2026-10-26 に確かめて直した。作業場所 `route-coverage`・枝 `map-infowindow-escape`（13.x = `d948a58c` から）。
**DB 変更・ルート変更・コントローラ・依存・CSS の変更は無し。新しい PHP クラスはテストだけ。**

| # | 見つけたこと | 直したこと |
|---|---|---|
| 1 | 仕入れ案件・分譲地の一覧と詳細（4 画面）の「マップで確認」の小窓（`openMapModal()`）が、名前と所在地を `setContent('<strong>' + name + …)` と HTML に連結していた。名前の `<img src=x onerror=…>` が地図を開いた人のブラウザで実行される形（DAD の Bug #111 と同じ）。2026-06-03 の XSS 対策（`a8007345`）は onclick の引数だけを `Js::from` に直していた | 関数の中の `escapeHtml()`（DAD と同じ・引用符は `\x22` `\x27`）で逃がしてから組む |
| 2 | 詳細 2 画面の最初の地図（`initDetailMap()`）が名前を引用符の中に直に書いていて、`\u` を含む名前（`C:\user\`）で script ごと構文エラーになり地図が出なかった。マーカーの title は `&lt;…&gt;` と実体参照のまま出ていた（Bug #76 の形） | 名前と所在地を `@json` で受け取り、title は元の名前・吹き出しは逃がしてから組む |

- 回帰テスト `tests/Feature/RealEstate/Screens/MapInfoWindowTest.php`（8 本: 小窓 5 データ〈仕入れ案件の一覧に出る分譲地の行を含む〉・詳細の地図 2 データ・吹き出しを作るビュー 6 本の全件分類）。画面の script を node で動かし、吹き出しに渡る文字列を全文で見る。直す前の画面で 7 データが赤を確かめてから直した
- ⚠ 全件テストで `LayoutMeasuringScriptTest` が落ちた — 最初は逃がす関数を DAD と同じ `/"/g` `/'/g` で書いたが、この走査は正規表現リテラルを解釈せず、中の引用符を文字列の始まりと読んで仕入れ案件の一覧の script を「波括弧が閉じていない」と判定した（分譲地の一覧は偶然つり合って緑）。引用符を `\x22` `\x27` で書く形にして 6 か所そろえた（変異 L01 で固定）
- 全件 **OK (4235 tests, 40464 assertions)**（PHP 8.3.35。4227 本に 8 本）・メモリのピーク 444.50 MB（変わらず）・コンパイル済みビュー 312 本 / INVALID 0
- 変異 29 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.map-infowindow.py`）すべて期待どおり。不動産のテスト全体で流し、表に無いテストは 1 本も落ちなかった（＝直す前は、この JS を守るテストが無かった）
- 実ブラウザ（使い捨ての環境・Playwright・本物の Google Maps の script。手元は API キーが無いので地図はエラーの表示だが、吹き出しは描かれる）: 4 画面とも、吹き出しが `&lt;img…&gt;` の文字として描かれ `<img src=x>` 要素は 0・`onerror` は走らない・マーカーの title は元の名前・`C:\user\` の名前で詳細の地図が動く。コンソールのエラーは API キーが無いことの 1〜2 件だけ
- 範囲外: 4 つの小窓のマークアップと script が逐語の写しのまま（共通の部品にまとめるのは別の作業）／本番のデータに `<` や `\` を含む名前があるかは読んでいない

### 本番反映（2026-10-26 実施）

利用者の指示で、`13.x` を `d948a58c` から **`3dc7a6f8`** へ早送りして `./deploy.sh` を流した。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。manage を触っているほかの会話は動いていなかった（「社内決裁申請」は 2026-10-13 から止まっている）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 4 本の md5・記録・ビルド | 4 本とも `d948a58c` と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-BGXzsqXz.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-26 11:11 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはビュー 4 本と `Tenant\PropertyController`（ページ送りの直しのコメント 1 か所。前回の反映のあとに 13.x で変わっていた）だけ。CSS・JS の名前は前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 5 本の md5・ビュー・記録 | 5 本とも手元（`3dc7a6f8`）と一致 ／ コンパイル済みビュー **312 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）／ `bootstrap/cache` は 11:11 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700） |
| Chrome（利用者の Chrome・見るだけ・フォームは送らない） | 仕入れ案件の一覧・詳細（#26）、分譲地の一覧・詳細（#16） | 4 画面とも新しい関数（`escapeHtml(name)` を使う形）が載っている ／ 本番の Google Maps で、小窓と詳細の地図の吹き出しが名前と所在地の文字として描かれる（「土居田町」「JG松前筒井」と住所・吹き出しの中の `<img>` 0）／ 詳細のマーカーの title は元の名前 ／ `main` のはみ出し 0 ／ 読み込み直したときのコンソールのエラー 0 件 |

⚠ `origin/13.x` への push はしていない（origin/13.x = `d948a58c`）。→ 2026-10-26 に利用者の指示で push 済み（`d948a58c..59323a94`）。

---

## ✅ 仕入れ案件の登録・編集で入力エラーのあと画面の JS が止まる件（Bug #76 の形）— 本番反映済み

実装計画: `~/.claude/plans/stateful-wobbling-plum.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #76 の追記）

不動産の回（2026-10-05）に範囲外として残した件を、利用者の選択（2026-10-26）で直した。作業場所 `route-coverage`・枝 `procurement-tax-rate-js`（13.x = `52c42f4c` から）。
**DB 変更・ルート変更・コントローラ・依存・CSS の変更は無し。新しい PHP クラスはテストだけ。**

- 症状: 仕入れ案件の登録・編集の共通フォーム（`realestate/procurements/_form.blade.php`）が `procurementForm()` の初期値を `taxRate: '{{ old("tax_rate", …) }}'` と JS の文字列の中に直に書いていた。消費税率は文字の欄なので、`10\` と打つと入力エラー（数値で入力）で戻り、戻った画面の JS が構文エラーで止まる → **消費税率・金額・物件種別の欄が空で描かれ、送り直すと金額が消える**（直す前の画面を実ブラウザで再現: 編集画面で `10\` を送ると 4 つの欄とも空・`procurementForm is not defined`・コンソールのエラー 66 件）。`'` `"` `&` `<` は `10&#039;` のように実体参照の文字のまま入力欄に出た
- 直し方: 初期値 8 行（物件種別・消費税率・金額 6 つ）を `{{ \Illuminate\Support\Js::from((string) old(…, … ?? '')) }}` に（住宅事業の `_form` と同じ形・値は今までどおり文字列）。金額と物件種別は画面からは数・選択肢しか送れない（手で組んだ送信だけ）が、同じ関数の同じ形なので一緒に直した
- 回帰テスト `tests/Feature/RealEstate/Screens/ProcurementFormJsTest.php`（3 本）: 登録・編集の画面で消費税率に `10'"&<\` を打って入力エラー → 戻った画面の JS を node で組み立て、消費税率・物件種別・金額が元の文字のまま ／ 手で組んだ送信で物件種別と金額 6 つに欄ごとに違う値を戻し、JS の 7 つの値が元の文字のまま（どの行を戻しても落ちる）。直す前は 3 本とも `SyntaxError: Invalid or unexpected token` で赤
- 全件 **OK (4238 tests, 40745 assertions)**（PHP 8.3.35・メモリのピーク 444.50 MB で前と同じ）・コンパイル済みビュー 312 本 / INVALID 0
- 変異 12 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.py`。前の表は `specs.map-infowindow.py`）がすべて期待どおり: 8 行を 1 行ずつ元の形に戻すと、消費税率は登録・編集の 2 本・物件種別と金額は手で組んだ送信の 1 本が落ちる／`old()` のキーを取り違えると登録・編集の 2 本が落ちる／`(string)` と `?? ''` を外すのは等価（緑）
- 実ブラウザ（使い捨ての環境・Playwright）: 登録画面で消費税率に `10\` と金額を打って送る → 「消費税率は数値で入力してください。」が出て、消費税率 `10\`・金額・物件種別が残る → 8 にして登録できた。編集画面で `10'"&<\` → 同じく元の文字のまま残り、10 にして更新できた。コンソールのエラー 0（警告は手元に地図の API キーが無いことの 3 件だけ）
- 範囲外: 同じ書き方（`'{{ old(…) }}'` を JS の文字列に）はほかに 13 ビュー・43 か所（不動産の契約の登録・編集 16・テナントの契約・問合せ・投資・修繕・物件 27）。選択欄・数・日付・hidden の欄と見ているが、画面から打てる欄が無いかは 1 つずつ確かめていない（→ 2026-10-26 に直した。数え直すと 41 か所。下の「画面の JS に `old()` を引用符で直に書く 41 か所」の節）

### 本番反映（2026-10-26 実施）

利用者の指示で、`13.x` を `52c42f4c` から **`887f3a77`** へ早送りして `./deploy.sh` を流した。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（`composer dump-autoload` も SQL も流していない）。manage を触っているほかの会話は動いていなかった（「社内決裁申請」は 2026-10-13 から止まっている）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | フォームの md5・記録・ビルド | `_form.blade.php` は `52c42f4c` と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-BGXzsqXz.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-26 11:58 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは `realestate/procurements/_form.blade.php` の 1 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | フォームの md5・ビュー・記録 | 手元（`887f3a77`）と一致 ／ コンパイル済みビュー **312 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）／ `bootstrap/cache` は 11:58 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700） |
| Chrome（利用者の Chrome・見るだけ・フォームは送らない） | 仕入れ案件の新規登録・編集（#26） | どちらも新しい注記の載った `procurementForm()` が組み立てられる（`typeof procurementForm` が function）／ 新規は消費税率 10.00、編集は保存済みの値が入力欄に入る（物件種別・消費税率・査定価格〈土地〉・想定販売価格〈土地〉。空の 4 欄は script の初期値も `''`）／ `main` のはみ出し 0 ／ 読み込み直したときのコンソールのエラー 0 件 |

⚠ 入力エラーのあとの画面は、本番ではフォームを送らないと出ないので見ていない（テストとローカルの実ブラウザで確かめてある）。⚠ `origin/13.x` への push はしていない（origin/13.x = `52c42f4c`）。→ 2026-10-26 に利用者の指示で push 済み（`52c42f4c..1ce70672`）。

---

## ✅ 画面の JS に `old()` を引用符で直に書く 41 か所を直す（Bug #76 の形の残り）— 本番反映済み

実装計画: `~/.claude/plans/abundant-twirling-starfish.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #76・#97 の追記）

上の仕入れ案件の節の範囲外に残した件を、利用者の承認（2026-10-26。決める 4 つは推薦どおり＝走査は `old()`・`request()` だけ／URL だけで崩れる 4 つも直す／13 画面に 1 本ずつ振る舞いのテスト／配列の送信は範囲外）で直した。
作業場所 `route-coverage`・枝 `js-old-embedding-audit`（13.x = `c602d399` から）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスはテストだけ。**

| 区分 | どこ | 直す前（2026-10-26 に実測） | 直したこと |
|---|---|---|---|
| A（3 か所） | テナント契約の登録の物件・問合せ（`?property_id=` `?inquiry_id=`）、問合せの登録の物件（`?property_id=`） | **URL だけで**崩れる。末尾に `\` を付けると 200 なのに画面の JS が止まる・`[]` で 500 | ビューは `Js::from`・コントローラで `ignoreMalformedQuery()` |
| B（7 か所） | 物件の登録・編集の所有区分と修繕の登録・編集の物件（`x-data` の中）、修繕・投資の区画の `:selected` | 属性の中なのでブラウザが実体参照を戻し、手で組んだ送信の値が JS のコードとして動く（本人の画面だけ。実ブラウザで再現） | `Js::from`（投資の登録の `:selected` は編集画面と同じ `u.id == unitId`） |
| C（31 か所） | 不動産の契約の登録・編集（16）、テナントの契約・解約・問合せ・投資の残り（15） | 手で組んだ送信で末尾に `\` を送ると、戻った画面の JS が止まる（本人の画面だけ） | `Js::from` |
| URL（追加 4 つ） | アンケート設問の管理 `?department=`、テナントの取込 `?tab=`、賃貸マンションの取込 `?selected_tab=`、投資の登録 `?unit_id[]=1` | 末尾の `\` で JS が止まる・`[]` で 500 | 部署とタブは決まった値だけ通す（取込は `WHERE_TO_CHECK` のキー）・投資は `ignoreMalformedQuery()` |

- 引き継ぎの「43 か所」は数え違いで、実際は 41（A 3・B 7・C 31）。URL だけで崩れる所は、レビュー役の確認でさらに 3 つ（取込 2・投資の `[]`）見つかった
- 書き方は `{{ \Illuminate\Support\Js::from((string) old('x', 既定)) }}`（前例は住宅事業の `_form`・仕入れ案件の `_form`）。`(string)` は外さない（外すと null が `null`・整数が数になり `===` の比較が変わる）。普通の値では表示も動きも今と同じ
- ⚠ Laravel の `$request->query('x')` は値が配列でもそのまま返す。例外を投げるのは Symfony の `$request->query->get('x')`（基底 `Controller` の注記がこれを取り違えていたので正した。変異 C06 が等価だったことで分かった）

| 区分 | 実装内容 |
|------|---------|
| アプリ | ビュー 13 本（`realestate/contracts/{create,edit}`・`tenant/contracts/{create,edit,terminate}`・`tenant/inquiries/{create,edit}`・`tenant/investments/{create,edit}`・`tenant/properties/{create,edit}`・`tenant/repairs/{create,edit}`）／ コントローラ 7 本（`Tenant\{Contract,Inquiry,Investment}Controller`・`Admin\{SurveyQuestion,TenantImport,MansionImport}Controller`・基底 `Controller` の注記） |
| テスト（道具） | `DrivesAlpineFetch::driveAlpine()` がインラインの `x-data`（関数名なし）でも動く ／ 新規 `Tests\Concerns\SendsValuesBack`（描いた画面のフォームの欄を書き換えて送り、戻った画面の JS の値を読む。テナントと不動産で共用） |
| テスト（新規） | `tests/Feature/Tenant/Screens/OldValueJsTest.php`（14 本）・`QueryPresetJsTest.php`（3 本）・`tests/Feature/RealEstate/Screens/ContractFormJsTest.php`（3 本）・`tests/Feature/Admin/ImportTabQueryTest.php`（2 本）・`SurveyQuestionMasterTest` に 1 本・走査 `tests/Feature/JsStringEchoScanTest.php`（見本 31 を含む 36 本） |
| 全件テスト | 4238 → **4297 tests / 41505 assertions green**（PHP 8.3・`443d9355`。メモリのピーク 450.50 MB / 上限 512M） |

### 走査（`JsStringEchoScanTest`）

- 全ビューの JS の場所（`<script>` の中と、`x-*` `:` `@` `on*` の属性の中）で、`old(` / `request(` を読む `{{ }}`・`{!! !!}` を、`Js::from` の 1 つの呼び出し・`(int)` `(float)` の 1 つの呼び出し・文字だけを返す三項演算のほかは止める。引用符で囲まないもの（Bug #84 の形）も止める。`@json()` は `{{ }}` を通らないので見ない
- 先に Blade のコメント・`@php`・ディレクティブの引数（`@selected(…)` など）を位置を保って空白にし、`{{ }}` の中身を伏せてからタグを読む（中身の `"` や `>` で属性の区切りを読み違えないため）。括弧が閉じないディレクティブは推測せず「読めない」として落とす。`src=` のある script は数えない。JSON の script は数える
- 下限: `<script>` 100（実測 108）・JS の属性 3,000（実測 3,649）・安全な書き方 65（実測 71。直す前は 31）。決裁の画面も見る（数で持たない）
- 直す前のコードでは、ちょうど 41 か所を名指しした
- ⚠ 見えないもの: `old()` を通らない値（コントローラが渡した変数・DB の値）・`@php` で変数に入れてから書く値・部品の中で組み立てる値・JS の側で `innerHTML` などに入れる値

### 範囲外（気づいたが直していない）

- JS の中の `'{{ $変数 }}'`（`old()` を通らない値）約 56 か所の分類。危ない可能性が高いのはアンケートの回答（`buyers/_survey_form.blade.php:160・174・194`。取込で入った回答や管理者が決めた選択肢に `'` や `\` があると、買主の編集画面の JS が止まる見込み・未実測）（→ 2026-10-26 に続けて分類した。下の「画面の JS に `old()` 以外の値を直に書く所を全件分類する」の節）
- 手で組んだ `x[]=` の送信で戻った画面が 500 になる件（今も 500・本人の送信だけ・住宅事業と仕入れ案件の直しも同じ）
- `realestate/contracts/edit.blade.php:264` の契約種別（DB の区分の値で害が無い）
- 全件テストのメモリのピークが 450.50 MB（上限 512M。前回 444.50 MB）

### 検証

- 足したテストはどれも、直す前のコードで狙った理由（`node で画面の JavaScript を動かせなかった`・`x1` に変わる＝コードとして動いた・500）で赤を確かめてから直した
- コンパイル済みビュー **312 本 / INVALID 0**
- 変異（`.claude/route-coverage-tools/mutate/specs.js-old-embedding.py`・結果は `results-js-old-embedding.txt`）: **78 通りすべて期待どおり**（カナリア 1・ビューの各行を元の形に戻す 41・等価 1・コントローラ 9・走査の分かれ目 24・道具 2）。ビューの各行を戻すと、走査がその「ファイル:行」を名指しし、その画面のテストが狙った理由で落ちる。A の 3 行は、ビューを戻しても URL のテストは緑（コントローラが外す）・コントローラを外しても入力エラーのテストは緑で、2 つの層を別々に守っている
  - 1 回目に期待と違った 6 通りの扱い: C06（`query()` で読む）は等価だった → 外した ／ S04 は私の変異の作り方の誤り（`:` の分かれ目まで消した）→ 作り直して期待どおり ／ S14 は見本が弱かった（`{{ }}` の中身を伏せなくても通った）→ 見本を替えて期待どおり ／ S16 は本物のビューの `$prop->isSold(` の中の `old(` にも当たった（後読みが本物でも効いている）→ 期待を直した ／ S20 は弱い見本の三項演算にも当たった → 見本を替えて期待どおり ／ T01 は等価（物件・修繕の画面に `function (` を含む script がたまたま 1 つ）→ 道具を元の型に戻す T02 を足し、6 本が落ちた
- 実ブラウザ（使い捨ての環境・Playwright。DB の写しに契約・投資・不動産の契約と構造マスターを足した）: 16 画面と URL を書き換えた 13 通りがすべて 200・コンソールのエラー 0・`main` のはみ出し 0、編集画面は保存済みの値が JS に入る。`?property_id=1\`・`?unit_id[]=1`・`?department=x\`・`?tab=x\`・`?selected_tab=x\` で値が外れて既定になる。物件の登録・編集と修繕の編集で、フォームの値を `x' + (globalThis.__ran = 1) + '` に書き換えて送ると、戻った画面で文字のまま入りコードは動かない。直す前の版を一時的に書き戻すと、同じ操作で `ownerType` が `x1` になり `__ran` が立った（書き戻した版は `git checkout --` で戻した）

本番へは次の節（`old()` 以外の値の分類）と一緒に 2026-10-26 に出した（記録は次の節の「本番反映」）。

---

## ✅ 画面の JS に `old()` 以外の値を直に書く所を全件分類する（Bug #76 の形の続き）— 本番反映済み

実装計画: `~/.claude/plans/abundant-twirling-starfish.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #76 の追記）

上の節の範囲外に残した「`old()` を通らない値を JS に直に書く所」を、利用者の指示（2026-10-26。この会話で行う）と承認（決める 4 つは推薦どおり＝危ない所だけ直して残りは分類表／囲まない書き方も分類する／配列で保存された回答は空として描く／決裁の画面は分類の外）で行った。
作業場所 `route-coverage`・枝 `js-db-value-embedding`（前の作業の枝 `js-old-embedding-audit` = `de6018e2` の上に積んだ）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスはテストだけ。**

### 調べた結果（走査テストと同じ読み方で全ビューを書き出した）

JS の場所（`<script>` の中と `x-*` `:` `@` `on*` の属性の中）にある `{{ }}` は 457 か所。壊れない書き方（`Js::from`・`(int)`・文字だけの三項演算・`route()`・`csrf_token()` など）が 238、決裁の画面が 60（分類の外）、残り 150 か所（引用符で囲む 83・囲まない 67）を 1 つずつ読んだ。

| 中身 | 例 | 危ないか |
|---|---|---|
| 区分（enum）の値・ラベル・バッジの色 | `$row->status->value`・`$pj->status->label()` | 安全 |
| 番号 | `$u->id`・`$qId` | 安全 |
| サーバ・呼び出し側がコードで決めた名前 | タブ名・欄の接頭辞・部署（決まった値だけ） | 安全（出どころを分類表に注記） |
| 部品に渡す JS の式 | 削除の確認の小窓の `$show`・DAD の日付の部品の `$valueExpr` | 安全（呼び出し側がコードに書いた固定の文字） |
| 数 | `(int)` でそろえた値・NOT NULL の列 `contracts.rent`・計算した数 | 安全 |
| 緯度・経度 | `$procurement->latitude` | 安全（`@if` で値があるときだけ・decimal） |
| 日付・システムの番号・URL | `JapanTime::today()->format(…)`・問合せ番号（入力欄なし）・route で作った URL | 安全 |
| **DB に保存された自由な文字** | **アンケートの「条件分岐付き選択」の 3 か所** | **危ない** |

### 直したこと（`resources/views/buyers/_survey_form.blade.php`。住宅事業・不動産の買主とアンケートの画面で共用）

| 行 | 直す前 | 起きていたこと（実ブラウザで再現） | 直したこと |
|---|---|---|---|
| 164 | `x-on:change="selectedOption = '{{ $optVal }}'"` | 選択肢（経営層が設問の管理で入れる）に `'` があると `Unexpected identifier` で式が動かない。細工した選択肢は開いた人全員の画面でコードとして動く形 | `Js::from((string) $optVal)` |
| 178 | `x-show="selectedOption === '{{ $optVal }}'"` | 同上。付随の欄の開け閉めが効かず、最初から開いたまま | 同上 |
| 198 | `selectedOption: '{{ $selectedValue }}'`（`<script>` の中） | 保存された回答（送られた値がそのまま JSON で残る）の末尾が `\` だと、その買主のアンケートの編集画面が**開いた人全員**で止まる（`condSelect1 is not defined`） | `Js::from($selectedValue)` |
| `@php` | 回答・付随の欄をそのまま描く | 配列で保存された回答（手で組んだ送信）で、編集画面が誰にとっても **500**（テストで実測） | 文字・数でなければ空として描く |

### テスト

| 区分 | 実装内容 |
|------|---------|
| 道具 | 走査の読み方を `Tests\Concerns\ScansBladeJsEchoes` に移し、`JsStringEchoScanTest`（見本 31 はそのまま）と共用にした |
| 分類のテスト（新規） | `tests/Feature/JsEchoClassificationTest.php`（18 本・見本 14 を含む）。決裁の画面を除く全ビューの JS の場所の `{{ }}`・`{!! !!}` が、壊れない書き方（`Js::from`・`(int)`/`(float)`・文字だけの三項演算・`route()` `url()` `asset()` `csrf_token()` `count()` `number_format()` の 1 つの呼び出し）でなければ、分類表（ビュー => 式 => 理由。101 組）に載っていること。載っていない値は「ファイル:行」を名指しして落ちる。載っているのに画面に無い式（古い分類）も落とす。下限: 分類表に当たった所 140（実測 148）・壊れない書き方 200（実測 215） |
| 振る舞いのテスト（新規） | `tests/Feature/RealEstate/Screens/SurveyAnswerJsTest.php`（3 本）。`'` と `\` を含む選択肢と `x' + (globalThis.__ran = 1) + '` の選択肢で、画面の JS を node で組み立てて `x-on:change`・`x-show` の式を評価する／保存された回答が文字のまま入る／配列で保存された回答で 200 |
| 全件テスト | 4297 → **4318 tests / 41801 assertions green**（PHP 8.3・`89351251`。メモリのピーク 452.50 MB / 上限 512M） |

- 新しく JS に値を書くときは、`{{ \Illuminate\Support\Js::from(…) }}` か `@json(…)` で書くか、値の出どころを読んで分類表に理由つきで足す（DB に保存された自由な文字は載せずに `Js::from` にする）
- 分類は書き方の分類で、値が本当に安全かは載せる人が決める。「名前」と「JS の式」は出どころをビューごとに注記した

### 範囲外（気づいたが直していない）

- 決裁の画面の 60 か所（「社内決裁申請」の会話の担当）
- アンケートの回答を保存するときの検査（選択肢に無い値・配列を断るか。CSV 取込の保存の形とも関わる）（→ 2026-10-27 に直した。保存した回答を編集画面で読み戻せない件と一緒に。下の「アンケートの回答の検査と編集画面での読み戻し」の節・Bug #120）
- `route()` を `<script>` の中の引用符に入れたとき、URL の `&` が `&amp;` になる件（働きの問題。当てはまる所があるかは未確認）

### 検証

- 足した振る舞いのテストは、直す前のコードで狙った理由（選択肢の `'` で構文エラー・回答の `\` で JS が止まる・配列で 500）で赤を確かめてから直した。分類のテストは、アンケートの部品を直す前の版に戻すと、ちょうど 3 か所を名指しして落ちた
- コンパイル済みビュー **312 本 / INVALID 0**
- 変異（`.claude/route-coverage-tools/mutate/specs.js-db-value-embedding.py`・結果は `results-js-db-value-embedding.txt`）: **20 通りすべて期待どおり**（カナリア 1・アンケートの部品 6・等価 1・分類のテスト 8・共用にした読み方 4）。1 回目は V01〜V03 の 3 通りで、私が期待に書いた行番号（直す前の 160・174・194）が、直したあとの行（164・178・198）とずれていただけだった（落ちたテストと理由は期待どおり）→ 期待を直して流し直した
- 実ブラウザ（使い捨ての環境・Playwright。DB の写しに条件分岐付き選択の設問と末尾が `\` の回答を足した）: アンケートの登録画面で「O'Neil 店」をクリックすると付随の欄が開き（ほかの選択肢では閉じたまま）、編集画面で保存済みの回答が文字のまま入る。どちらもコンソールのエラー 0。直す前の版を一時的に書き戻すと、登録画面は式のエラーで付随の欄が最初から開いたまま、編集画面は `Invalid or unexpected token` と `condSelect1 is not defined` で部品が動かなかった（書き戻した版は `git checkout --` で戻した）

### 本番反映（2026-10-26 実施。上の節と一緒に）

利用者の指示（「2つの枝をまとめて本番へ反映してください」）で、`13.x` を `018dfd95` から **`178ce803`** へ早送りして `./deploy.sh` を流した。直前に作業場所で 13.x（`018dfd95`。決裁 5b の文書のコミットだけ進んでいた）を取り込み、全件 **OK (4318 tests, 41801 assertions)**（PHP 8.3）・コンパイル済みビュー 312 本 / INVALID 0。反映のあいだは「社内決裁申請」の会話に 13.x の早送りと `./deploy.sh` を待ってもらった。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存・CSS の変更は無し**（`composer dump-autoload` も SQL も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 21 本（コントローラ 7・ビュー 14）の md5・記録・ビルド | 21 本とも 13.x（`018dfd95`）と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build はアプリ側・公開側とも `app-BGXzsqXz.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-26 15:48 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 21 本（一覧と一致）とビルドだけ。CSS・JS の名前は前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 21 本の md5・ビュー・記録・キャッシュ | 21 本とも手元（`178ce803`）と一致 ／ コンパイル済みビュー **312 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）／ `bootstrap/cache` は 15:48 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700） |
| Chrome（利用者の Chrome・見るだけ・フォームは送らない） | 直した画面と URL を書き換えた 21 通り（テナントの契約の登録〈`?property_id=abc`・`?property_id[]=1` も〉・編集・解約、問合せ・投資・物件・修繕の登録、投資と物件の編集、不動産の契約の登録・編集、住宅事業の顧客の登録とアンケートの登録、設問の管理〈`?department[]=x`〉、テナント・賃貸マンションの取込〈`?tab[]=x`・`?selected_tab[]=x`〉） | 21 通りとも 200・画面の `<script>` と JS の属性（ページごとに 110〜178 個）がすべて組み立てられる ／ アンケートの登録の選択肢の `x-on:change` が `Js::from` の形（`'\u5efa…'`）で、選ぶと `selectedOption` が「建売住宅の購入」になる ／ テナントの契約の編集は保存済みの家賃（180000）と初月の請求方法が Alpine の状態に入る ／ 2 画面とも開き直したときのコンソールのエラー 0 件・`main` のはみ出し 0 |

⚠ 問合せ・修繕は本番の一覧から詳細の番号を拾えなかったので（件数 0 の可能性が高い）、編集画面は見ていない。保存済みの回答を持つアンケートの編集画面も、開いた買主（id 3）にアンケートが無く見ていない。どちらもテストとローカルの実ブラウザで確かめてある。⚠ 登録・更新・入力エラーの送信は本番では送っていない。⚠ `origin/13.x` への push はしていない。→ 2026-10-26 に利用者の指示で push 済み（`018dfd95..94702f33`）。

---

## ✅ アンケートの回答の検査と編集画面での読み戻し — 本番反映済み

設計書: @docs/superpowers/specs/2026-10-27-survey-answer-validation-design.md
実装計画（直す前の実測・変異テスト・実ブラウザの記録つき）: @docs/superpowers/plans/2026-10-27-survey-answer-validation.md

上の「画面の JS に `old()` 以外の値を直に書く所を全件分類する」の範囲外に残した「アンケートの回答を保存するときの検査」を、利用者の決定（2026-10-27）で、保存した回答を編集画面で読み戻せない件と一緒に直した（docs/RULES.md の Bug #120）。
買主のご来場アンケート（住宅事業・不動産で共用）は、回答を保存するときに何も検査されず、保存した回答は編集画面で数・テキスト・数字の単一選択が空に、スライダーが真ん中の値に戻った。編集画面をそのまま保存すると、その回答が消える・書き換わる。
利用者の決定: ①選択肢に無い値は入力エラーで断る（ただし、その回答にすでに保存されている値は通し、編集画面にも「（選択肢に無い値）」として選んだまま描く）②スライダーに「回答しない」のチェックを足す（今ある 5 件は値をそのまま残す）③CSV 取込のアンケートの列は画面と同じ形で保存し、選択肢の名前は「、」か「,」で区切る（付随の文字・付随の欄は CSV では入れない。選択肢に無い値・範囲の外の数はその行のエラー）。範囲は、保存のときの検査だけでなく編集画面での読み戻しも一緒に。
作業場所 `route-coverage`・枝 `survey-answer-validation`（13.x = `69c43b0c` から）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスが 1 つ増える**（`App\Support\SurveyAnswers`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。

### 直す前に測ったこと

使い捨てのテスト（描いた画面のフォームを組んで送る）と実ブラウザで測った。設計書 1.2 の見込みは 6 つとも当たった（計画の実測記録 Task 0）。

| 設問の種類 | 保存した値 | 編集画面を開くと（直す前） |
|---|---|---|
| 数値入力 | `3500` | 空欄 |
| スライダー（1000〜5000） | `3500` | 3000（真ん中の値） |
| テキスト入力 | `123` | 空欄 |
| 単一選択（選択肢 1・2・3） | `3` | どれも選ばれていない |
| 複数選択 | `A` と選択肢に無い `ZZZ` | `ZZZ` がそのまま保存された（検査なし） |
| 複数選択（CSV 取込の形 `A,B`） | — | どれも選ばれていない（保存で消える） |

ほかに、無効にした設問の回答と、選択肢の名前を変えた単一選択の回答が編集画面の保存で消える・スライダーは触らなくても真ん中の値（4000）が保存される・顧客の新規登録は何も答えなくてもアンケートが 1 件できる（`filled()` が配列を常に「入っている」とみなす）・条件分岐付き選択は付随の欄が選択肢をまたいで同じ名前で、「建売住宅の購入」に松山市・道後・40 を入れても全部 null で保存される（実ブラウザで再現）・入力エラーで戻ると回答が消える、を確かめた。
本番（読み取りだけ・2026-10-27）は住宅事業の 6 問・アンケート 5 件・回答 15 件。問 6（予算）の 5 件は、どれも保存された値が 4000（2026-10-28 の読み取り）。直す前の部品がスライダーを触らずに送ると入れた真ん中の値と同じなので、本当の予算かは分からない（→ 2026-10-28 に担当の方に確かめ、5 件とも聞いていなかったので画面から消した。下の「本番の問 6（予算）の 5 件を消した」の節）。CSV 取込の形の回答・無効な設問への回答は 0 件。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Support | `App\Support\SurveyAnswers`（新規）— 検査 `check()`・画面全体 `checkScreen()`・読み戻し `decode()`・部品が描く状態 `formState()`・CSV `fromCsv()` の 1 か所。保存の形は今までと同じ（本番の回答をそのまま読める） |
| Controller | `CustomerSurveyController`（登録・編集・更新）・`CustomerController`（新規登録）・`Admin\CustomerImportController`（プレビュー・確定）が `SurveyAnswers` を使う。2 つのコントローラにあった `normalizeAnswerValue()` と `BuyerSurveyAnswer::decoded_value` は消した |
| Blade | `buyers/_survey_form.blade.php`（アンケートの登録・編集と顧客の新規登録が共用）— 保存済みの値と入力エラーの値を描く・「（選択肢に無い値）」・条件分岐付き選択の付随の欄に `:disabled`・スライダーは範囲の入力欄が `name` を持たず hidden 1 つで送り「回答しない」のチェックを足した |
| テスト | 新規 `tests/Unit/Support/SurveyAnswersTest.php`・`tests/Feature/RealEstate/Screens/SurveyScreensTest.php` ／ 追加 `SurveyAnswerJsTest`・`tests/Feature/Admin/CustomerImportTest.php` ／ 道具 `tests/Concerns/ComposesScreenForms.php`（`composedForm()` が部品ごとの欄を深く合わせる） |
| ルート / DB | **どちらも変更なし**（`answer_value` は TEXT のまま・本番の回答は書き換えない） |
| 全件テスト | 4318 → **4437 tests / 43655 assertions green**（PHP 8.3.35・メモリのピーク 458.50 MB）／ コンパイル済みビュー **312 本 / INVALID 0** |

| 種類 | 保存の形（`answer_value`） | 回答なし | 検査 |
|---|---|---|---|
| 単一選択 | 選択肢の文字そのまま | 何も選ばない | 今の選択肢（＋保存済みの値）のどれか |
| 複数選択 | JSON の配列 `["A","B"]` | 何も選ばない | 全部が今の選択肢（＋保存済み）の中。重なりは 1 つに |
| テキスト入力 | 文字そのまま | 空欄 | 255 文字まで |
| 数値入力 | 数の文字 | 空欄 | 数。設定の下限・上限の中（数の文字は 20 文字まで） |
| スライダー | 数の文字 | 「回答しない」 | 整数。設定の下限〜上限の中で、下限から刻みの倍数 |
| 選択肢＋付随テキスト | `[{"value":"ご紹介","text":"山田様"}]` | 何も選ばない | 選んだ値が選択肢の中。付随の文字は 100 文字まで・文字を持てない選択肢の文字は保存しない |
| 条件分岐付き選択 | `{"value":"建売住宅の購入","sub":{"city":"松山市"}}` | 何も選ばない | 選んだ値が選択肢の中。付随の欄は選んだ選択肢のものだけ（文字 100 文字まで・数は 0〜99999 で小数 2 桁まで） |

### 要点

- 入力エラーは、今の項目（来場日など）の誤りと一緒に**一度に**出る。項目名は設問の文で、英字の `survey.6` は出ない
- **保存済みと同じ値は、どの検査も通す**（選択肢・範囲・刻み・文字数）。設問の管理で選択肢の名前や設定を変えても、触っていない回答は断らず変えない（Bug #82・#113 と同じ考え方）
- `update()` が作り直すのは、画面に出した設問（その部署の有効な設問）の回答だけ。無効にした設問の回答は残る
- 本番の空の形の回答（問 1 の `[]` 4 件・問 2 の付随の欄がすべて空の形 5 件）は「回答なし」と同じ中身なので、編集画面で保存すると行が消える（答えが消えるのではない）
- 新しいアンケート（登録・顧客の新規登録）では、スライダーの「回答しない」が最初からチェックされている。スライダーを動かすと外れる。保存済みの回答がある編集画面では外れた状態
- 顧客の新規登録は、回答が 1 つも無ければアンケートを作らない
- ⚠ 範囲の入力欄（`<input type="range">`）に `name` を持たせない。保存済みの値が今の下限〜上限の外だと、ブラウザが丸めて送る値が変わる

### 範囲外（気づいたが直していない）

- CSV で付随の文字・付随の欄を入れること
- 回答の集計・顧客の詳細画面に回答を出すこと
- 設問の管理で設問の種類を変えたとき（前の形の回答は読めず、編集画面の保存で消える。今は種類を変える画面が無い）
- CSV 取込の古い形（`A,B`）で保存された回答の読み戻し（本番は 0 件）
- 保存済みの数が今の min / max の外だと、ブラウザが送信を止める（本番に数値入力の設問は無い）
- スライダーの設定が小数のときの `(int)` と `(float)` の食い違いと、真ん中の値が刻みに乗らない設定（本番の 2000〜6000・100 刻みでは起きない）
- 今の設問に無い保存済みの欄の誤りの文の項目名がキーのまま（その欄を書き換えて 100 文字を超えたときだけ出る）
- CSV の同じ設問の 2 列が両方埋まると後ろの列が勝つ（テストで固定していない。テンプレートどおりなら起きない）
- 単一選択の保存済みとの比べ方は前後の空白を落とさない（単一選択の値に空白が入る経路が無い）
- 顧客の編集（`update()`）も、手で組んだ `survey=abc` を「回答の形が正しくありません。」で断るようになった（編集画面はアンケートを出さず、画面からは送られない。以前は黙って無視していた）
- `SurveyAnswers.php` が約 1,000 行で、長いメソッド（約 60〜100 行）がある／`SurveyScreensTest.php` が約 700 行

### 検証

- 足したテストは、ほとんど直す前のコードで赤を確かめてから直した（例外: 保存の形を固定する `test_a_new_customer_survey_is_stored_in_the_saved_form` と、範囲の外の保存済みスライダー `test_a_saved_slider_outside_the_current_range_is_kept` は直す前も緑〈道具は範囲の入力欄を丸めない〉。最後の点検の直しのうち、本番の形の fixture を実測の null の形に直したことと、`check()` の `match` の `default` を外したことは、赤を確かめていない）。Task 1〜5（クラス・登録と編集・部品・顧客の新規登録・CSV）ごとに独立の点検を回し、Critical・Important は 0 件だった
- 変異（`.claude/route-coverage-tools/mutate/specs.survey-answer.py`）: 1 回目 35 通り（カナリアを含む）すべて期待どおり。最後の点検の直しで 2 通り（F01 null の欄を飛ばさない・F02 確定を見出しの列ごとに回す）を足した 37 通りも期待どおり（25 通りは前回と同じ組・10 通りは落ちるテストが直しで足したテストの分だけ増えた・新しい F01・F02 は狙ったテストが落ちた）。ラジオの `x-model` を外す変異は構造のアサート 1 つだけが止める
- 実ブラウザ（使い捨ての環境・Playwright・画面が見えている状態）: 8 項目＋顧客の新規登録の入力エラーが、すべて期待どおり — スライダーの最初の状態と動かしたとき／保存済み 2500 を設定 3000〜6000 に変えたあとの編集（触らず保存で 2500 のまま）／「回答しない」の付け外し／条件分岐（選んだ選択肢の欄だけ送られ、建売 → 土地 → 建売と切り替えても打った値が残る）／選択肢の名前を直したあとの回答（単一選択・条件分岐の保存済みの欄）が「（選択肢に無い値）」で選ばれたまま出て、そのまま更新しても 4 行が 1 字も変わらないこと／来場日を空にして送ると回答が戻る／顧客の新規登録／375px で `main` のはみ出し 0・コンソールのエラー 0
- 最後の点検（fable・読み取りだけ）は「直してからマージ」。Important 2（読み戻しが null を含む形を捨てる・CSV の同じ見出しの 2 列）と Minor を直して、範囲を絞った再点検で 8 件すべて直ったことを確かめた（新しい Critical・Important なし）
- コミット（`69c43b0c` の次から）: 設計書 `27ad66f9`・計画 `ff499114`・直す前の実測 `a904a51c` ／ `SurveyAnswers` `b0a03b76` ／ 道具の直し `4296f5b9`・`40a7c40f` ／ 登録・編集 `efa1bbcd` ／ 部品 `91723081` ／ 顧客の新規登録 `e8a80532` ／ CSV `0d0483bb` ／ 変異・実ブラウザの記録 `9706a27e`・`d891265b` ／ 最後の点検の直し `454539e0`・`ce1185c7`・`3dd48ddf`・`a633c983` ／ 点検の記録 `30c47901`

### 本番反映（2026-10-28 実施）

利用者の指示で反映した。「社内決裁申請」の会話に先に知らせ、反映のあいだ `13.x` の早送りと `./deploy.sh` を待ってもらった。`13.x` は段階6a（`a40044b4`）を取り込んでから **`6eb55ab3`** へ早送りしてあり、全件 **OK (4437 tests, 43655 assertions)**（PHP 8.3）。**DB 変更・ルート変更・依存の変更は無し**（SQL は流していない）。新しいクラスがあるので、main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3）を流した（表に `SurveyAnswers` が 1 行・worktree のパス 0・`vendor/bin/phpunit` は無い）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 5 本の md5・新しいクラス・記録・ビルド・スライダーの回答 | 5 本とも `40f27fd2`（段階6a を出した時点）と一致・`SurveyAnswers.php` は無い ／ `laravel.log` は Jun 18 17:41 ／ build は `app-DernpbB7.css`・`app-NiVQbl_Q.js` ／ 問 6 の回答は 5 件とも `4000`（アンケート 5 件・回答 15 件） |
| `./deploy.sh` | 2026-10-28 9:06 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 6 本（コントローラ 3・モデル・新しいクラス・ビュー）と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じで旧バンドルの削除 0 件。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 8 本の md5・ビュー・クラス・記録・キャッシュ | 8 本とも手元（`6eb55ab3`）と一致 ／ コンパイル済みビュー **315 本 / INVALID 0** ／ `SurveyAnswers` が読め、`decoded_value` は消えている・`decode(問 6, "4000")` は文字の `"4000"` ／ 回答は 15 件のまま ／ `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）／ `bootstrap/cache` は 9:06 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700） |
| Chrome（利用者の Chrome・見るだけ・保存しない） | 住宅事業のアンケートの編集（アンケート 1）・登録・顧客の登録 | 3 画面とも 200・`main` のはみ出し 0・コンソールのエラー 0 ／ 編集: スライダーは保存された 4000 のまま「回答しない」は外れている・範囲の入力欄に `name` が無く、送る hidden `survey[6]` は 1 つ ／ 問 1 は保存された選択（HP）がチェックされて出る（直す前の部品でも読めた形）／ 登録と顧客の登録: 「回答しない」にチェック・値の表示は「—」・hidden は送らない（`disabled`）／ 条件分岐（問 2）の付随の欄 12 個はすべて `disabled`（選ぶまで送らない） |

⚠ 本番の問 6 の回答は 5 件とも 4000 なので、「保存された 4000 以外の値が編集画面で真ん中に戻らない」ことは本番では見分けられない（直す前も 4000 が出ていた）。この点はテストと使い捨ての環境の実ブラウザで確かめてある。⚠ 登録・更新・入力エラーの送信は本番では送っていない。⚠ `origin/13.x` への push はしていない（origin/13.x = `a40044b4`）。→ 2026-10-28 に push 済み（`fb56f087` に含まれる）。

### 本番の問 6（予算）の 5 件を消した（2026-10-28）

上の反映のあとに残っていた「問 6 の 5 件の 4000 が本当の予算か分からない」件を、利用者の指示で片付けた。

- 本番の読み取り（利用者の承認のうえ・読み取りだけ）: 問 6 は 2000〜6000万円・100万円刻みのスライダーで、4000 は真ん中の値。5 件とも顧客の新規登録の画面で買主と同じ瞬間に作られ、そのあと一度も直されていなかった（作った日時と更新した日時が同じ）。担当はどれも同じ 1 人。10/27 の読み取りでは、問 1 は 5 件中 4 件が空・問 2 は 5 件とも空で、アンケート 2〜5 は予算のほかに答えが無かった
- 担当の方に確かめた結果、5 件とも予算は聞いていなかった（利用者から）
- 直し方（利用者の決定）: ログイン済みの Chrome で 5 件の編集画面を開き、「回答しない」にチェックを入れて「更新する」を押した。押す前に毎回、画面の買主名・送り先が合っていることと、予算の hidden が `disabled`（送られない）になっていることを確かめた。5 件とも「アンケートを更新しました。」
- 直したあとの本番の読み取り: 問 6 の回答は 0 件・回答の全件は 15 件 → 1 件（アンケート 1 の問 1「HP」だけ）。空だった問 1・問 2 の行は保存で一緒に消えた（中身は「回答なし」と同じ）。アンケートの来場日・分譲地・担当者は変わらない。`laravel.log` は Jun 18 17:41 のまま
- ⚠ アンケートの `updated_at` は 5 件とも変わっていない（アンケートの項目を変えていないので Eloquent が書かない。作り直すのは回答の行だけ）

---

## ✅ 手で組んだ配列の送信で 500 にならないようにする（Bug #121）— 本番反映済み

実装計画（直す前と後の測定・変異テストの記録つき）: @docs/superpowers/plans/2026-10-28-array-input-shape.md

上の「画面の JS に `old()` を引用符で直に書く 41 か所」の範囲外に残した「手で組んだ `x[]=` の送信で、戻った画面が 500 になる件」を、利用者の依頼（2026-10-28。戻った画面の 500 と、配列が入力チェックを素通りして保存で 500 になる件の両方）で直した。
作業場所 `route-coverage`・枝 `array-input-500`（13.x = `2353d14c` から）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスが 1 つ**（`App\Support\ShapeCheckingValidator`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。

| 区分 | 実装内容 |
|------|---------|
| 判定 | `App\Support\ShapeCheckingValidator`（新規）— 配列を求める欄 ＝ 自分の規則に `array` / `list` / `required_array_keys` があるか、その欄の下に規則がある。形が合わない欄があれば、ほかの規則より先に断る（型の規則の文があればその文、無ければ「:attributeの形が正しくありません。画面を開き直して、もう一度入力してください。」）|
| 登録 | `AppServiceProvider::boot()` の `Validator::resolver()`（全部の入力チェックがこのクラスになる）・`bootstrap/app.php` の render（断ったときの控えから形の合わない欄を外す）|
| ログイン | 制限の応答（`loginThrottleResponse()`）は配列のログイン ID を控えない |
| 入力チェックの前に値を読む所 | 不動産の契約の種別・ZEAL の Sheet の取込の対象月（プレビューと反映）を文字のときだけ読む |
| 入力チェックを 1 回にまとめた | 仕入れ案件・分譲地の登録の原価・住宅の契約と注文住宅の買主・ZEAL 試算表の項目の適用開始月 |
| 規則を足した欄 | アンケートの備考 `survey_memo`・賃料改定の戻り先 `return_to`・買主の生年月日の年月日と備考 `memo`・駐車場契約の物件 `property_id`（exclude）・不動産の契約の種別で使わない欄（exclude）。和名も足した |
| テスト | 新規 `tests/Unit/Support/ShapeCheckingValidatorTest.php`・各部署の `Screens/ArrayInputTest.php`（テナント・不動産・住宅事業・賃貸マンション・各マスタ）・`tests/Feature/OldInputRuleCoverageTest.php`・`Tests\Concerns\TampersForms` ／ 追加 `LoginIdentifierTest`（戻った画面まで見る形に直した・制限の場合）・`SheetImportTest`・`SimulationScreensTest`。4495 → **4560 tests green（PHP 8.3）** |

### 測った数（全件テストの送信を形を変えて送り直す使い捨ての測定）

| | 直す前 | 直したあと |
|---|---|---|
| 保存の 500 | 11 経路 | 3 経路（すべて決裁） |
| 戻った画面の 500 | 79 経路 | 1 経路（決裁） |

「社内決裁申請」の会話は 2026-10-28 の時点で動いていなかったので、起こさずに記録だけ残した（入力チェック全体に効くので、決裁の画面でも配列を送ると断られる。決裁のテストは全件で緑）。決裁の残り（その会話の担当。直していない）: 部門の登録・更新のアルファベット `code[]`（入力チェックの前に `(string)` で読む）・メールのドメインの登録 `domain[]`（入力チェックの前に `ApprovalMailDomain::normalize()`）・申請の更新（全部の欄を配列にした送信で、戻った画面が 500。入力チェックの外で入力を控える経路の可能性が高い）。

### 範囲外（気づいたが直していない）

- 入力チェックの前に値を読むコード・2 回に分けた入力チェック・規則の無い欄・入力チェックが通ったあとの手作りの `back()->withInput()` は、この仕組みでは止まらない（新しく書くときは避ける。走査 `OldInputRuleCoverageTest` は「どこかの規則」で見るので、別の画面の同じ名前の規則に当たると素通りする）（→ 2026-10-30 に走査を画面のフォームの送り先と突き合わせる形にした。下の「`old()` の走査を画面のフォームの送り先の規則と突き合わせる」の節）
- 修繕の登録で `unit_id` の欄そのものが無い送信の 500（`$validated['unit_id']` を直接読む＝Bug #92 の形）（→ 2026-10-29 に直した。下の「欄そのものが無い送信で 500 にならないようにする」の節）
- 一覧の絞り込みなど GET のクエリの配列（`ignoreMalformedQuery()` がある）
- 形が合わない欄が 1 つでもある送信では、ほかの欄の誤りは出ない（直して送り直すと出る。手で組んだ送信だけの場面）
- `survey_memo`・`memo` の上限 5000 文字は、ほかの備考の欄に合わせた（本番の列の定義は読んでいない。テスト用スキーマは TEXT）

### 本番反映（2026-10-28 実施）

利用者の指示で、`13.x` を `2353d14c` から **`f78071b8`** へ早送りして `./deploy.sh` を流した。全件は作業場所で **OK (4560 tests, 44411 assertions)**（PHP 8.3.35）・コンパイル済みビュー 315 本 / INVALID 0。「社内決裁申請」の会話は早送りの前にも deploy の前にも ListAgents に出ていなかったので、起こさずに記録だけ残した。**DB 変更・ルート変更・依存の変更は無し**（SQL は流していない）。新しいクラスがあるので、main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3）を流した（7,318 クラス・`ShapeCheckingValidator` が 1 行・作業場所のパス 0・`vendor/bin/phpunit` は無い）。

| 段 | 見たこと | 結果 |
|---|---|---|
| `./deploy.sh` | 2026-10-28 14:59 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 15 本（コントローラ 11・`AppServiceProvider`・新しいクラス・`bootstrap/app.php`・`lang/ja/validation.php`）と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じ（`app-DernpbB7.css`・`app-NiVQbl_Q.js`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 17 本の md5・ビュー・記録・キャッシュ・入力チェックのクラス | 17 本とも手元（`f78071b8`）と一致 ／ コンパイル済みビュー **315 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとの 16:54 にも読み直した）／ `bootstrap/cache` は 14:59 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ アプリが作る入力チェックは `App\Support\ShapeCheckingValidator` |
| Chrome（利用者の Chrome・見るだけ・フォームは送らない。同じオリジンの iframe で 1440px） | 直した入力チェックの画面 13 通り（テナント契約の登録・賃料改定、不動産の契約の登録・編集、仕入れ案件と分譲地の登録、注文住宅の登録、住宅事業と不動産の顧客の登録、アンケートの登録、駐車場契約の登録、ZEAL 試算表の項目の編集、ZEAL 試算表の詳細） | 13 通りとも 200 ／ Alpine のコンポーネントがすべて組み立てられる（`x-data` の数と組み立てた数が同じ）／ 画面の `<script>` の構文エラー 0 ／ `main` のはみ出し 0 ／ テナント契約の登録を開き直したときのコンソールのエラー 0 件 |

⚠ 配列の送信（入力エラーになる手組みの送信）は、本番では送っていない（テストと使い捨ての環境の実ブラウザで確かめてある）。⚠ `origin/13.x` への push はしていない（origin/13.x = `2353d14c`）。→ 2026-10-28 に利用者の指示で push 済み（`2353d14c..6ee90b01`）。

---

## ✅ 欄そのものが無い送信で 500 にならないようにする（Bug #92 の形）— 本番反映済み

上の Bug #121 の節の範囲外に残した「修繕の登録で `unit_id` の欄そのものが無い送信の 500」を、利用者の選択（2026-10-29）で直した（docs/RULES.md の Bug #92 の追記）。同じ形がほかに無いかを機械的に測り、ZEAL 会員の更新でもう 1 つ見つけて直した。
作業場所 `route-coverage`・枝 `repair-unit-id-missing`（13.x = `c5235e11` から）。**DB 変更・ルート変更・画面の変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

| 経路 | 直す前 | 直したあと |
|---|---|---|
| 修繕の登録・更新（`tenant.repairs.store` / `update`） | 区画の欄（`unit_id`）そのものが無いと `$validated['unit_id']` を読んで 500 | `present` で断る（「区画が送信されていません。」・何も書かない） |
| ZEAL 会員の更新（`zeal.members.update`） | 「当店を知ったきっかけ」「入会目的」の欄そのものが無いと `$validated[…] ?: null` を読んで 500 | `?? null`（同じメソッドのほかの任意の項目と同じく未設定で保存） |

- どれも手で組んだ送信だけ（画面の選択欄は必ず送られ、共用部・未設定は空で届く）
- 修繕は欄が無いことを「共用部」とは扱わずに断る — 更新で黙って通すと今の区画が残ったまま物件だけが変わり、別の物件の区画が付いたまま残るため（変異 R3 で、通す形にするとテストが「入力エラーの箱が出ていない」で落ちることを確かめた）
- 回帰テスト: `tests/Feature/Tenant/Screens/RepairScreensTest.php` に 2 本（登録・更新。描いた画面のフォームから欄を抜いて送る）・`tests/Feature/Zeal/MemberInputLimitsTest.php` に 1 本（2 つのデータ。`MemberScreenTestCase::sendEdit()` に抜く欄の引数を足した）。どれも直す前に `Undefined array key` の 500 で赤を確かめてから直した
- 全件 **OK (4564 tests, 44486 assertions)**（PHP 8.3）

### 同じ形を探した測定

全件テストの書き込み（POST / PUT / PATCH）の送信を、経路ごとに「送り直しても通る元の送信」を 1 つ選び、一番上の欄を 1 つずつ抜いて送り直した（使い捨て。道具は `.claude/route-coverage-tools/array-input/probe-drop.php`・README つき・git の管理外）。

- 元の送信の後に送る回と前に送る回（登録の経路は、後だと 2 回目が重複で断られて測れない）の 2 回で、書き込みの経路 207 本のうち **201 本**を測った。残り 6 本は欄を読まない（区画の状態の切り替え・不成約・利用者の復元・お知らせの既読）か必須のファイルだけ（図面の追加）と logout
- 修繕の直しを一時的に戻したカナリアで、修繕の 2 経路を拾えることを 2 回とも確かめた
- 500 になったのは上の 3 経路と、テナント契約の登録の `rent_start_date`（テスト用スキーマの `contracts.rent_start_date` が NOT NULL のせい。本番は NULL 可で保存は `?? null`。Bug #60 の範囲外に書いたテスト用スキーマの漂流で、本番の欠陥ではない。→ 2026-10-29 に直した。下の節）
- ⚠ 見ないもの: 入れ子の欄（`costs.0.x`・`survey.3` など）・GET のクエリ・テストが通る送信を 1 本も持たない分岐
- → 入れ子の欄は 2026-10-29 に別の道具（`probe-drop-nested.php`。配列の中の欄と行を 1 つずつ抜く・同じ形は最初の 1 つだけ・あとの送信に出る新しい形も抜く）で測った。元の送信の後と前の 2 回で、入れ子の欄を送る経路 40 本・135 形（原価の行・投資の明細・DAD の人員配置・アンケートの複数選択と条件分岐の付随の欄・決裁の明細表など）を抜き、**500 は 0**。直すものは無かった（カナリア＝仕入れ案件の原価の備考を `?? null` なしで読む形を拾えることは確かめた）。⚠ マンションの解約の `terminate_parkings.*` は通る元の送信に出ず測れていない（番号の並びを `?? []` で読むだけ）

### 変異（8 通り・カナリアを含む）

すべて狙ったテストだけが狙った理由で落ちた（関係するテストの集まり＝テナント・ZEAL の Feature テストで流した）。

| # | 変異 | 落ちたテストと理由 |
|---|---|---|
| C0 | 修繕の登録の成功の文を変える（カナリア） | 登録の往復のテスト 1 本 |
| R1 | 登録の `present` を外す | 登録の欄なしのテスト（`Undefined array key`） |
| R2 | 更新の `present` を外す | 更新の欄なしのテスト（`Undefined array key`） |
| R3 | 更新は欄が無ければ今の区画を残して通す | 更新の欄なしのテスト（入力エラーの箱が出ていない） |
| R4 | 登録は欄が無ければ共用部にして通す | 登録の欄なしのテスト（入力エラーの箱が出ていない） |
| Z1・Z2 | ZEAL のきっかけ・入会目的を `?: null` に戻す | それぞれのデータ（`Undefined array key`） |
| Z3 | きっかけの欄が無ければ今の値を残す | きっかけのデータ（`'word_of_mouth' is null` が成り立たない） |

### 本番反映（2026-10-29 実施）

利用者の指示で、`13.x` を `c5235e11` から **`8e65e88f`** へ早送りして `./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 2 本の md5・記録・ビルド | 2 本とも `c5235e11` と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-DernpbB7.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-10-29 13:42 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはコントローラ 2 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 2 本の md5・ビュー・記録・キャッシュ | 2 本とも手元（`8e65e88f`）と一致 ／ コンパイル済みビュー **315 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま ／ `bootstrap/cache` は 13:42 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 変わったのは保存（POST / PUT）の経路だけで、画面は変わっていないので、Chrome での確認はしていない。欄を抜いた送信は本番では送っていない（テストで確かめてある）。⚠ `origin/13.x` への push はしていない（origin/13.x = `c5235e11`）。→ 2026-10-29 に利用者の指示で push 済み（`c5235e11..80b92b42`）。

---

## ✅ テスト用スキーマの契約の家賃発生日を本番どおり空欄可にする — テストだけ（本番への反映は不要）

上の「欄そのものが無い送信で 500 にならないようにする」と Bug #60 の範囲外に残した件を、利用者の選択（2026-10-29）で直した。
作業場所 `route-coverage`・枝 `contract-rent-start-nullable`（13.x = `72bce46b` から）。**`app/`・`resources/`・`routes/`・本番の DB・依存の変更は無し**（変えたのは SQLite のテスト用スキーマ＝作成の migration の 1 行とテスト）。

- 本番の `contracts.rent_start_date` は NULL 可（2026-09-14 に読み取りで確認・Bug #60）なのに、テスト用スキーマは NOT NULL だった。家賃発生日を空欄にした契約の登録・編集と、賃料開始日が空欄の契約・過去契約の取込は、アプリでは null で保存する経路なのに、テストで確定まで通せなかった（ほかのテストは日付を埋めて避けていた）。欄を抜く測定でもこの列だけ 500（測定の雑音）が出ていた
- 直し方: 作成の migration の行を `->nullable()` にした（`customer_id` と同じやり方。`Schema::table(...)->nullable()->change()` は SQLite がテーブルを作り直し CHECK が黙って消えるので使わない＝既存のカナリアが止める）
- テスト（+6 本）: スキーマのカナリア（`TenantUnitImportTest`）／ 画面の登録（家賃発生日が空欄・欄そのものが無い送信）と編集（家賃発生日を空にする）（`ContractScreensTest`）／ 取込の 2 タブ（新規 `TenantContractRentStartImportTest`）。どれも直す前は `NOT NULL constraint failed: contracts.rent_start_date` で落ちることを確かめてから直した（取込の 2 本は帯の error の文まで見た）。NOT NULL を前提にした注記 6 か所も直した
- テストで固定した今の振る舞い: 家賃発生日が空の契約は、詳細に「家賃発生日が未設定です。」・一覧に ⚠ が出る ／ 日割りでも家賃発生日が無ければ初月は 1 か月分
- 全件 **OK (4570 tests, 44673 assertions)**（PHP 8.3.35。4564 本に 6 本）
- 変異 9 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.rent-start-nullable.py`）すべて、狙ったテストが狙った理由で落ちた（作成の行を NOT NULL に戻す・取込の 2 タブで空欄を契約日で埋める・登録と更新で必須にする・詳細と一覧の印を消す・日割りの 1 か月分を 0 にする）。1 回目は 3 通り（R01〜R03）で、落ちたテストの組は合っていたが期待の理由を書き違えていた（画面の 3 本は 1 行目が 500 で NOT NULL は後ろの行・日付の比べは Carbon を複数行で出す）→ 全文で見る形に直して流し直した
- 範囲外: 本番のデータに家賃発生日が空の契約が何件あるかは読んでいない
- 2026-10-29 に利用者の指示で 13.x へ早送りし、origin へ push した（このコミットまで。push の前の origin/13.x = `72bce46b`）

---

## ✅ `old()` の走査を画面のフォームの送り先の規則と突き合わせる（Bug #121 の死角）— 本番反映済み

計画（2026-10-30 に承認）: `~/.claude/plans/staged-stargazing-lollipop.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #121 の行）

上の「手で組んだ配列の送信で 500 にならないようにする」の節の死角（走査が「どこかの入力チェックに同じ名前の規則がある」で見ていた）を、利用者の依頼（2026-10-30）で塞いだ。
作業場所 `route-coverage`・枝 `old-input-rule-scope`（13.x = `27330d0e` から）。**DB 変更・ルート変更・画面の変更・依存の変更は無し。アプリで変わったのはコントローラ 2 本の規則だけ**（新しい PHP クラスはテストの部品だけ＝本番で `composer dump-autoload` は要らない）。

| 区分 | 実装内容 |
|------|---------|
| 走査 | `tests/Feature/OldInputRuleCoverageTest.php`（書き直し）・新規 `tests/Concerns/BindsOldInputToPages.php`。画面（`@include`・`<x-…>` でたどった木の親）の中のすべての `old()` が、木の中のすべてのフォームの送り先（ルートの URL と送る方式〈`method` と `@method`〉で引いた `Class@method` と、そこから `$this->` で呼ぶメソッドの入力チェック。`CollectsValidationKeys` の sites をファイルと行で当てる）に規則を持つこと。控えを作らない送り先（入力チェックも `withInput(` も `ValidationException` も届かない。削除など 13）は外す |
| 見つけて直した 2 件 | ①問合せの詳細の対応履歴の 3 欄（`action_date`・`action_type`・`content`）: 同じ画面の状態の変更 `Tenant\InquiryController@updateStatus` に規則が無く、そこへ `content[]=x` を足して状態の値で断られると、戻った画面が `{{ old('content') }}` で 500（テストで再現）→ `updateStatus` に 3 欄を `exclude` で足した ②賃貸マンションの部屋契約の登録の `property_id`: `Mansion\ContractController` の登録に規則が無く、配列が控えに残っていた（`@json` で描くので 500 にはならなかった）→ `exclude` を足した（駐車場契約の物件と同じ形） |
| テスト | `tests/Feature/Tenant/Screens/ArrayInputTest.php`・`tests/Feature/Mansion/Screens/ArrayInputTest.php` に 1 本ずつ（直す前に 500／配列が残る、で赤を確かめた）。走査は本体 1 本＋見本 7 本。4570 → **4579 tests / 44756 assertions green**（PHP 8.3.36・メモリのピーク 171.50 MB） |

### 要点

- **控えは画面全体で共有される**。断ったときに控えから外すのは、断った入力チェックの規則の欄だけ（`bootstrap/app.php` の render）なので、同じ画面の**別のフォーム**の送り先にも規則が要る（①はこの形。前の走査も、送り直しの測定 `probe-replay` も、送った欄の形しか変えないので見えなかった）
- カナリア: `CustomerController` の `memo` の規則を消すと、前の走査は緑・新しい走査は `buyers/create`・`buyers/edit` の 2 画面で `buyers/_form.blade.php:311` を名指しして赤
- 送り先は名前ではなく **URL と送る方式**で引く（名前だけだと `route('login')` が GET のルートを指す）。名前を変数で組む送り先（`route("{$department}.customers.store")`）は、その画面を描くコントローラのルートに絞る（絞らないと `tenant.customers.store` という別の画面のルートまで拾う）。部品の `{{ $action }}` は呼び出し側の `:action="route(…)"` から読む
- Blade のコメントを落としてから読む（前の分類表の `survey` はコメントの中の文字に当たっていた）。`old($引数)` を包む関数（ZEAL 試算表の項目のフォームの `$val('code')`）も読む。引数の無い「old()」（コメントの文中）は数えない
- GET のフォームも見る（GET の入力チェックで断られても、送った値は控えに入る）
- 読めない書き方（送り先の無い画面・閉じていない／入れ子のフォーム・送り先を読めないフォーム・入力チェックが 2 つ届く送り先・FormRequest を受けるメソッド・読めない `old($変数)`）は落とす
- 分類表は 2 つ: `WITHOUT_RULE`（書いてあるビュー => 欄 => [`old()` の件数, 理由]。`remember`・`keep_adding`・`is_active`・`survey.{$qId}`）と `NOT_RENDERED_ON`（画面 => 欄 => 理由。周辺ビルの編集画面では描かない初回調査の 5 欄）。件数が変わる・規則がある・使われていない分類は落ちる
- 下限（実測）: 画面の `old()` 909・欄 242・画面 80・送り先 81・外した送り先 13・名前を変数で組むフォーム 5・部品の属性から読んだ送り先 1・包む関数で読んだ欄 10
- `CollectsValidationKeys` は変えていない（`JapaneseValidationMessagesTest` は緑のまま）

### 検証

- 変異（`.claude/route-coverage-tools/mutate/specs.old-input-scope.py`・結果は `results-old-input-scope.txt`）: 38 通り（カナリアを含む）。1 回目は 34 通りが期待どおりで、4 通りが期待と違った: 2 通り（B02・B24）は狙ったテストが落ちていて期待の書き方の誤り（下限が先に落ちる・PHPUnit が文字の種類を書き足す）／T03（決裁の画面を親とする木も見る）は緑で等価（決裁の画面が読み込む基幹の部品に `old()` が無い）／T04（GET のフォームも見る）は緑で、外す理由が無いので GET も見る形に直した。直したあとの 2 回目は **38 通りすべて期待どおり**（緑は等価の T03・T04 だけ）
- 見本で固定した分かれ目（実データでは変えても緑になるもの）: 三項演算の 2 つ目の送り先・`route()` のほかの式・入れ子と閉じていないフォーム・タグの中にじかに書いた `{{ … }}`・`$this->` の 2 段目・`withInput(` だけの送り先・入力チェックが 2 つの送り先・FormRequest・「どれか 1 つの送り先で通す」・規則の `*`。見本を書いている途中で、属性のあいだにじかに書いた `{{ … }}` で属性の読み取りが止まる穴を見つけて直した
- 直した 2 件を直す前に戻すと、新しい走査がちょうどその 4 欄を名指しした

### 範囲外（気づいたが直していない）

- 問合せの詳細の画面は入力エラーを出さない（`$errors` も `@error` も無い。状態の値は画面のボタンが決めるので、断られるのは手で組んだ送信だけ）
- 走査の死角（テストの docblock に列挙）: `@if` で描く／描かないが変わる欄（`NOT_RENDERED_ON` で扱う）・条件つきで足す規則もどの送り先にもあるとみなす・`exclude` だけの欄で入力チェックが通ったあとに `ValidationException::withMessages()` を投げる経路・app/Http の外が投げる `ValidationException`・コントローラの中の `old()`（9 か所。どれも自分の送り先に規則がある）・`@includeWhen` などと `@include($変数)`
- 決裁の画面（「社内決裁申請」の会話の担当）

### 本番反映（2026-10-30 実施）

利用者の指示で、`13.x` を `27330d0e` から **`9dff3423`** へ早送りして `./deploy.sh` を流した（push はしていない）。「社内決裁申請」の会話は止まっていた（idle）。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `27330d0e`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルはコントローラ 2 本だけ |
| `./deploy.sh` | 2026-10-30 11:30 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはコントローラ 2 本と、前の作業で変えたテスト用の migration 1 本（`0001_01_01_000007_create_contracts_table.php`。本番は `migrate` を使わないので置かれるだけ）。CSS・JS は名前も中身も前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り・利用者の承認のうえ） | コントローラ 2 本 | 手元（`9dff3423`）と **md5 が一致**・`exclude` の規則が入っている（部屋契約 1・問合せ 3）・`php -l` で構文エラーなし |
| | コンパイル済みビューの `php -l` | **315 本 / INVALID 0 件** |
| | 記録とキャッシュ | `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 11:30 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は `app-DernpbB7.css`・`app-NiVQbl_Q.js` だけ |
| | ログイン画面（ログイン不要） | 200 |

⚠ 直した 2 件は手で組んだ送信だけで起きる形なので、本番ではフォームを送っていない（テストで確かめてある）。⚠ `origin/13.x` への push はしていない。→ 2026-10-30 に push 済み（`27330d0e..6ba8ed07`）。

---

## ✅ アンケート設問の管理で編集と並び替えを画面からできるようにする（Bug #122）— 本番反映済み

設計書: @docs/superpowers/specs/2026-11-02-survey-question-admin-design.md
実装計画（直す前の実測・本番の設問の形・変異テスト・実ブラウザ・全体の点検の記録つき）: @docs/superpowers/plans/2026-11-02-survey-question-admin.md

利用者の依頼と決定（2026-11-02）で、アンケート設問の管理（`/admin/survey-questions`・経営層のみ）を作り直した（docs/RULES.md の Bug #122）。
買主のご来場アンケート（住宅事業・不動産で共用。設問は部署ごと）の設問が追加と削除しかできなかった。「編集」は一覧を開き直すだけ・「ドラッグ＆ドロップで並び替えできます」と書いてあるのに並び替えを送る所が無く、選択肢は JSON を手で書く欄でしか入れられず、スライダーの範囲を入れる欄も無かった（Bug #120 の範囲外に残した「設問の種類を変えたとき」と、引き継ぎの不具合 3〜9 も一緒に直した）。
作業場所 `route-coverage`・枝 `survey-question-admin`（13.x = `6ba8ed07` から・先頭は `4a2b9230`）。**DB 変更・依存の変更は無し。新しい PHP クラスが 1 つ増える**（`App\Support\SurveyQuestionForm`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。**ルートが 5 → 7 に変わる**（`./deploy.sh` が `route:cache` を作り直す）。

### 直す前に測ったこと

使い捨てのテストと実ブラウザで測った（2026-11-02・計画の実測記録 Task 0）。見込みは 4 つとも当たった。

| # | 直す前 |
|---|---|
| M1 | 追加の XHR のあと一覧を開き直しても「設問を追加しました。」は出ない（設問は追加される。XHR が `X-Requested-With` を付けないので、転送先の GET がフラッシュを使い切る） |
| M2 | `PUT` で `options` に壊れた JSON（`'["A",'`）を送ると、今の選択肢が NULL に消える（応答は 302・理由なし） |
| M3 | 回答がある設問に `PUT` で別の種類を送ると、種類が変わる（`single_select` → `slider`。回答は前の形のまま残る） |
| M4 | 追加の小窓の外枠が `display: block` になり、白い箱が画面の左上に貼り付く（静的な `display: flex` と `x-show` が同じ要素にある。Bug #32 の形） |

ほかに、顧客の CSV 取込は見出しの `Q{n}:` の番号だけで設問に結びつけ、`:` の後ろの設問の文を見ないので、並び替え・無効化・文の変更のあと、前にダウンロードしたテンプレートの列が黙って別の設問の回答になる（D8 で直した）。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Support | `App\Support\SurveyQuestionForm`（新規・約 650 行）— 保存の形の組み立て `definition()`・最初の状態 `state()`・中身の検査 `problems()`・選択肢ごとの回答の件数 `answerCounts()`・`isChoice()` の 1 か所。`App\Support\SurveyAnswers` に `chosenValues()`（保存済みの回答が選んだ値を種類ごとに読む）とスライダーの最初の位置を刻みの上へ寄せる直し |
| Controller | `Admin\SurveyQuestionController` — `create`・`edit`・`move` を足し、`store`・`update`・`destroy` をふつうのフォームの送信に。`reorder` と XHR の JSON の道を外した ／ `Admin\CustomerImportController` — 設問の列の文を今の設問と照らし合わせる（D8） |
| Model | `SurveyQuestion::scopeOrdered()` の同点を id で決める |
| Blade | `admin/master/survey-questions/` の `create`・`edit`・`_form`（新規）・`index`・`_question_item`（作り直し）／ `admin/customers/import.blade.php`（案内文）／ `buyers/_survey_form.blade.php`（付随の欄の見出しを設問から出す・数値入力の `min`・`max` の属性を外す） |
| ルート | 5 → 7（`create`・`edit`・`move` を足し、`reorder` を外した） |
| テスト | 新規 `tests/Unit/Support/SurveyQuestionFormTest.php`・`tests/Feature/Admin/Master/SurveyQuestion{Create,Edit}Test.php`・土台 `SurveyQuestionScreenTestCase`・`tests/Feature/BrowserFormNestedRowsTest.php`（道具）／ 書き直し `SurveyQuestionMasterTest` ／ 追加 `CustomerImportTest`（D8）・`SurveyAnswersTest`・`SurveyScreensTest`。道具 `DrivesAlpineFetch::browserForm()` を行の中の行（入れ子の `x-for`）まで広げた |
| 全件テスト | 4579 → **4711 tests / 48112 assertions green**（PHP 8.3.36・メモリのピーク 171.50 MB）／ コンパイル済みビュー **318 本 / INVALID 0**（13.x の 315 本から `create`・`edit`・`_form` の 3 本増） |
| 本番の DB | **変更なし**（`survey_questions` の列・`options`・`settings` の JSON の形は今の読み手が読む形のまま） |

### 決めたこと（利用者の決定・2026-11-02）

| # | 決めたこと |
|---|---|
| D1 | 直せるもの: 設問の文・有効／無効・選択肢（足す・外す・並べ替え・名前）・種類ごとの設定・付随テキストの有無と見出し・条件分岐の付随の欄 |
| D2 | 回答がある設問の種類は変えさせない（理由を画面に出す）。回答が無ければ変えられる |
| D3 | 回答がある選択肢の名前は変えてよい。過去の回答は元の名前のまま残し、その選択肢を選んだ回答の件数を出す |
| D4 | 並び替えは ↑↓ のボタン（設問も、設問の中の選択肢も） |
| D5 | 選択肢は 1 行ずつの入力欄。内部のキーは自動で付け、画面に出さない |
| D6 | 追加・編集は別の画面（ふつうのフォームの送信）。誤りは理由を出し、入れた内容を残す |
| D7 | 条件分岐の付随の欄の見出しを設問ごとに入れられるようにする（空なら「▼ 選択肢名」だけ） |
| D8 | 顧客の CSV 取込は、`Q{n}:` の後ろの設問の文も今の設問と照らし合わせ、違えばプレビューでも確定でもファイルごと断る（当たる設問の無い列も） |

### 本番の設問の形（2026-11-02 に利用者が読み取りを流して貼ってくれた。形だけ・設問の文と選択肢の名前は載せない）

| 設問 | 種類 | `options` の形 | `settings` | 回答 |
|---|---|---|---|---|
| 問 1 | 選択肢＋付随テキスト | 11 個。全部オブジェクト `{has_text, value}`（文字を入れない 9 個にも `has_text` がある）・2 個だけ `{has_text, text_label, value}` | null | 1 |
| 問 2 | 条件分岐付き選択 | 6 個。全部 `{sub_fields, value}`（欄の無い 2 個にも `sub_fields` がある）・欄は `{key, label, type}`。欄のキーは選択肢をまたいで重なる（1・2 個目 `city` `area` `land_tsubo` `building_tsubo`／3 個目 `city` `town` `land_tsubo`／6 個目 `other_text`） | null | 0 |
| 問 3〜5 | 単一選択 | 文字の並び（4・5・5 個） | null | 0 |
| 問 6 | スライダー | null | `{"max": 6000, "min": 2000, "step": 100, "unit": "万円"}` | 0 |

不動産事業の設問は 0 問。設計書 5 章に無いキーは無かった。この形に合わせて、保存の形は `has_text` を、条件分岐は `sub_fields` をどの選択肢にも書く（そのまま保存しても JSON が変わらない）。

### 要点（動きが変わるもの）

- **付随の欄のキーは見出しを変えても変えない**。画面は今ある欄のキーと選択肢の元の名前（`original`）を hidden で送る（送らないと今ある欄に新しいキーが付き、過去の回答の付随の値が外れる）。新しい欄には、その設問の中で使われていないキーを自動で付ける
- **回答がある設問の種類は変えさせない**。押せない select は送られないので、サーバーは今の種類を使う。手で送った違う種類は理由つきで断る
- **保存されている選択肢の名前を空にすると断る**（外すときは × を押す）。全体の点検で見つけた — 件数を見せてから直す守りを、名前を消す操作がすり抜けて黙って消えていた
- 選択肢の名前は保存で前後の空白（全角も）を落とす。同じ設問の中で同じ名前は断る
- 数・スライダーの設定は整数（`integer` と `regex`。「+2000」は断る）。スライダーは下限 < 上限・刻み > 0・（上限 − 下限）が刻みで割り切れること。最初の位置は下限から数えて刻みの上へ寄せる（本番の 2000〜6000・刻み 100 は変わらない）
- 一覧: 番号は有効な設問だけの通し番号（無効の設問には出さない。CSV の `Q` の番号・アンケートの画面の並びとそろう）。↑↓ は押すたびに保存して一覧を開き直し、無効の設問も飛ばさず入れ替える。削除は一覧だけで、回答があれば無効にするだけ（編集の画面のチェックで有効に戻せる）。部署は編集で変えない
- **顧客の CSV テンプレート**: 設問の並び替え・有効／無効・文を変えると、前にダウンロードしたテンプレートは断られる（ダウンロードし直す。一覧と取込の画面に書いた）。見出しは引用符で囲んで書き、取込の見出しは逃がしなしで読む（カンマ・引用符を含む設問文が割れない）。Excel が Shift_JIS で保存し直すと「～」「－」などが別の字に読み替えられるので、比べるときだけ既知の対（cp932 と JIS の違い 7 組）を同じ字にそろえる。断るときは同じ部署の取込の画面へ戻す
- 並びの同点は id で決める（`scopeOrdered()`）。今の本番は `sort_order` が重ならないので並びは変わらない
- 全体の点検（fable）の直し 5 つ: 一覧の ↑↓ の二度押し止め（共通の部品 `submitOnce()`）・保存されている選択肢の名前を空にしたら断る・削除の確認の文を回答の有無で出し分ける・別のフォームの控えで編集画面が空で描かれる件・カンマのテンプレートのテストの失敗の理由

### 範囲外・後回し（全体の点検が「待てる」と仕分けたもの。控えの約 40 件を要約）

- データの行の読み方（今も既定の逃がしの `str_getcsv`。PHP 8.4 の非推奨の警告が出る。見出しと読み方がそろわない）— PHP 8.4 へ上げる日に `CsvImportReader` と一緒に直すのが自然
- 部署の一覧が 3 か所にある（設問の管理の `DEPARTMENTS` と `in:housing,realestate`・顧客の取込の `DEPARTMENTS` など）・`validateQuestion()` が約 75 行・`CustomerImportController::execute()` の照らし合わせの切り出し
- 一覧の ↑↓ の止めはフォームごと（別の ↑↓ を続けて押すと 2 本届く）・削除のフォームに二度押し止めが無い（結果は同じ）
- 押せない ↑↓ の理由は包む span の `title` だけ（キーボード・読み上げに届かない・`aria-label` がどの行か言わない）・無効の行の地 `#fafafa` は `#fff` と 1.04:1 でほぼ見分けられない（設問文の灰色・番号なし・「無効」のピルで見分けられる）
- 選択肢 50 行・付随の欄 10 行の上限は画面に案内も止め方も無い（送ってから断られる）・取込の画面の誤りの `<li>`・付随の欄の見出しの `div` に `wrap-anywhere` が無い（空白の無い長い文が 375px ではみ出しうる。前からの形）
- 確認画面の「← やり直す」とほかの 2 つの断り（データなし・鍵の使用済み）は部署を付けない（前から）・`colMap` が Q の列の見出しの文も標準の列と比べる（設問文が「職業」などだと標準の列を上書きしうる・前から）（→ 2026-11-12 に直した。テンプレートの見出し「Q1:職業」で実際に上書きされることを測った。Bug #129）
- `problems()` の 8 行のうち画面のテストがあるのは 3 行（残りは部品のテストだけ）・開いたあとで回答が消えると、種類の欄が送られず「種類は必須です」になる
- やらないと決めたもの: 過去の回答を新しい選択肢の名前へ書き換えること・回答の集計と買主の詳細に回答を出すこと・部署をまたいだ設問の移動・設問の控え（`question_snapshot`）を読む画面

### 検証

- 全件 **OK (4711 tests, 48112 assertions)**（`4a2b9230`・CLAUDE.md の流し方・3 分 36 秒）／ コンパイル済みビュー 318 本 / INVALID 0
- 変異（`.claude/route-coverage-tools/mutate/specs.survey-question-admin.py`・docs/RULES.md Bug #44 の作法）: 52 通り（カナリアを含む）。1 回目は 49 通りが期待どおり・3 通り（N1・J4・O1）は期待の書き方の誤り・**緑のまま通った穴が 2 つ**（K1・K1b＝上限・刻みの `regex` を外しても全部緑）→ テストを足して（`97e7b18f`）2 回目は 52 通りすべて期待どおり（落ちたテストの組・理由の 1 行目・全文まで一致。表に無いテストは 1 本も落ちなかった）。全体の点検の直し 5 つは、直す前の赤で確かめた（52 通りの流し直しはしていない）
- 実ブラウザ（使い捨ての環境・Playwright・画面が見えている状態）8 項目: 一覧の ↑↓ と端の押せないボタンの理由・追加の 7 種類で送られる欄・条件分岐の行と欄の足し引きと並べ替え（保存して開き直しても同じ）・入力エラーで戻る・回答がある設問の種類の固定と選択肢ごとの件数・問 2 の見出し（空だと「▼ 建売住宅の購入」だけ、入れると「▼ 建売住宅の購入 — 希望エリア」）・375px と 1440px の横スクロール 0・コンソール 0 件。すべて OK・新しい欠陥 0。⚠ 作業場所に `public/build` が無く、メインのビルドに新しいビューの `max-sm:p-3` が無かったので作業場所でビルドして測った（`./deploy.sh` が毎回ビルドするので本番は問題ない）
- 全体の点検（fable）: Ready to merge（Critical・Important 0・Minor 8）。直す 5 つを入れ、範囲を絞った再点検で 5 件すべて addressed
- コミット（`6ba8ed07` の次から）: 設計書 `323a6af9`・計画 `a232a983`・直す前の実測 `96a1864d`・本番の形 `b9e73b31` ／ 道具 `4bd0c788` ／ アンケートの画面の側 `1ce044f7`・`8b71bdca`・`2c896ee6`・`34075956` ／ `SurveyQuestionForm` `304d415f` ／ 追加 `c1259990` ／ 編集 `8cee152f`・`14099fd2`・`c1beb8f2`・`187f60d3` ／ 一覧・↑↓ `31d10449`・`89ecdb29`・`b09776d3` ／ CSV（D8）`8ece69ec`・`50eebc7f`・`3f1e8f2a`・`4df9efd6`・`a280f019`・`1c4e07c3`・`4101015b` ／ 穴を塞ぐテスト `97e7b18f` ／ 記録 `9aa3e8d6`・`4a945234` ／ 全体の点検の直し `b41d056c`・`520f77ff`・`0af2b14d`・`32cdadf2`・`4a2b9230`

### 本番反映の手順

**DB 変更は無い**（SQL は流さない）。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・「社内決裁申請」の会話に知らせて `13.x` の早送りと `./deploy.sh` を待ってもらう・本番の読み取り（変わるファイルの md5・`laravel.log`。承認のうえ）
2. main repo の cwd で `13.x` を `survey-question-admin` へ早送りし、**`composer dump-autoload --no-dev --optimize`**（新しい PHP クラス `App\Support\SurveyQuestionForm`。worktree から流さない＝autoloader に worktree のパスが焼き込まれる）
3. `./deploy.sh`（ルートが 5 → 7 になるので `route:cache` が作り直される・新しい Tailwind のクラスが入るので CSS の名前が変わる）。反映のあと、変えたファイルの md5・コンパイル済みビューの lint・`laravel.log` を読み取りで確かめる
4. **`./deploy.sh` のすぐあと、本番の今の条件分岐の設問（問 2）の付随の欄の見出しに「希望エリア」を入れる**（設計書 8 章。新しい編集画面で。利用者が入れるか、利用者の承認を取ってこちらが入れる。本番のデータを変える操作）。入れるまでは、買主のアンケートの付随の欄が「▼ 選択肢名」だけになる（問 2 の `settings` が NULL のため）。使い捨ての環境では、保存すると欄の無い 2 つの選択肢に `"sub_fields":[]` が足されただけで回答は変わらなかった（本番の今の形は欄の無い選択肢にも `sub_fields` を持つので、変わるのは `settings` の `sub_heading` だけの見込み）
5. 問 1 は**初めて保存する前に**、選択肢の名前の前後の空白と重複を確かめる（台帳の Ruling 10。保存で名前が `trim` され、変わると過去の回答 1 件が「（選択肢に無い値）」になりうる。重複は理由つきで断られる）
6. ログイン済みの実 Chrome で確かめる（見るだけ・フォームは送らない）: ①一覧の ↑↓ を 1 回押して 1 段だけ動くこと（並びを変える操作なので、押すなら利用者の承認のうえ元へ戻す）②問 1 の編集画面で、種類が押せず「回答が N件あるため、種類は変えられません。」と出ること ③Excel で Shift_JIS に保存し直した顧客の CSV テンプレートが、プレビューで設問の列を理由に断られないこと（確定は押さない）④対象の画面でコンソールのエラー 0・`main` の横スクロール 0

### 本番反映（2026-11-03 実施）

利用者の指示で、`13.x` を `6ba8ed07` から `survey-question-admin` へ早送りし（記録のコミット `c804d4f2` まで）、origin へ push してから（origin/13.x = `c804d4f2`）反映した。main repo の cwd で `composer dump-autoload --no-dev --optimize` を流してから `./deploy.sh`。**DB 変更なし**（SQL は流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 9 本の md5・新しい 4 本・ルート・記録・ビルド | 9 本とも `6ba8ed07` と一致・新しい 4 本は無い ／ ルート 5 本（`reorder` あり）／ `laravel.log` は Jun 18 17:41 ／ CSS は `app-DernpbB7.css` |
| `./deploy.sh` | 2026-11-03 18:22 ごろ終了（日本時間） | exit 0・6 段すべて |
| 反映の後（本番の読み取り） | 13 本と読み込みの表 2 本の md5・クラス・ルート・ビュー・記録 | 15 本とも手元と一致 ／ `SurveyQuestionForm` が表に 1 行・読める ／ ルート 7 本（`create`・`edit`・`move` が増え、`reorder` が消えた）／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま ／ `bootstrap/cache` は 18:22 に作り直し（600／700）／ CSS は `app-_7h98Lga.css`（2 か所とも）／ ログイン画面 200 |
| 問 2 の見出し（手順の 4。利用者の承認のうえ、ログイン済みの Chrome で入れた） | 編集画面で「付随の欄の見出し」に「希望エリア」、選択肢「その他」の欄（キー `other_text`）の見出しに「内容」（利用者の決定）を入れて「更新する」 | 「設問を更新しました。」 ／ 開き直した編集画面で 2 つとも入っており、欄のキー 12 個は保存の前と同じ ／ 住宅事業の顧客の登録画面で「▼ 建売住宅の購入 — 希望エリア」ほか 4 つの見出し・「その他」の欄に「内容」・`main` の横スクロール 0・コンソールのエラー 0 |

⚠ **見つけた直し漏れ（直していない）**: 本番の問 2 の「その他」の欄は見出しが空で保存されていた。新しい編集画面は、キーのある欄の見出しが空だと「選択肢 6 行目の欄 1 行目の見出しを入力してください。」で断る（`SurveyQuestionForm::fieldProblems()`）。検査を外すだけでも、保存の形を組み立てる `conditionalOptions()` が見出しの空の欄を捨てるので、欄が消える。テストのデータはこの欄の見出しを「内容」で作っており、本番の形との食い違いを見落としていた。今回は見出しを入れて保存した（買主の画面の「その他」の欄の上に「内容」が出るようになった）。→ **2026-11-03 に利用者が決めた: 付随の欄の見出しは必須のまま（コードは変えない）**。本番で見出しの空の欄を持っていたのは問 2 の「その他」の 1 つだけで、上のとおり見出しを入れたので今は 0（条件分岐の設問は問 2 だけ・不動産事業の設問は 0 問）。新しい画面は見出しの空の欄を断るので、これから増えることもない。
⚠ 見出しは設問に 1 つなので、「自己所有の土地に新築」「その他」の箱にも「— 希望エリア」が付く（設計どおり。選択肢ごとの見出しにするなら別の作業）。

#### 手順の 5・6 の確かめ（2026-11-03 実施）

| 段 | 見たこと | 結果 |
|---|---|---|
| 手順の 5（本番の読み取り・利用者の承認のうえ） | 本番の設問 6 問すべてで「編集画面を開いて何も変えずに保存したら」を本番のコードで計算した（保存はしない。`tinker` で `SurveyQuestionForm::state()` から画面が送る形を組み、`validateQuestion()` と `definition()` に通す。設問の文と選択肢の名前は出さない） | 6 問とも、前後の空白で変わる名前 0・重複 0・入力チェックの誤り 0・保存したあと選択肢から外れる回答 0 ／ 保存の形（`options`・`settings`）は、問 1 のほかは今と同じ。問 2（11/03 に新しい画面で保存）が「同じ」と出ることで計算の正しさを確かめた ／ `laravel.log` は流す前も後も Jun 18 17:41 |
| | 問 1 の保存の形の違い（形だけ読んだ） | 違うのは 11 行目の選択肢だけ: `has_text` が true で `text_label` が空の文字（`""`）。保存すると空の `text_label` のキーが無くなる（`withTextOption()` は見出しがあるときだけ書く）。買主の画面は `text_label` が無いのも空も同じに読む（`SurveyAnswers::choice()` が `''` にする）ので、見た目も回答の扱いも変わらない ＝ **問 1 はそのまま保存してよい** |
| 手順の 6 ②（ログイン済みの Chrome・見るだけ・送らない） | 問 1 の編集画面 | 種類の選択欄が押せず（`disabled`）、包む span の `title` と本文（`aria-describedby`）に「回答が 1件あるため、種類は変えられません。」 ／ 「この選択肢を選んだ回答が N件あります」の行が 1 つ ／ 選択肢 11 行・「文字も入れる」は 10・11 行目にチェック（11 行目の見出しの欄は空） |
| 手順の 6 ④ | 問 1 の編集画面・一覧 | どちらも 1440px で `main` の横スクロール 0・読み込み直したときのコンソールのメッセージ 0 件 ／ 一覧の ↑↓ のフォーム 10（6 問 × 2 − 両端の 2）・`submitOnce` が読める |

⚠ 手順の 6 ①（一覧の ↑↓ を押す・並び順が変わる）と ③（Shift_JIS で保存し直したテンプレートを取込のプレビューに上げる・本番のフォームを送る）は、利用者の判断で本番では行わない（テスト〈変異 52 通り〉とローカルの実ブラウザで確かめてある）。

---

## ✅ 顧客の取込で住所が空欄の行も重複候補にする（Bug #123）— 本番反映済み

「顧客CSVインポートの確定を通す」「二重送信を止める」の 2 つの節の範囲外に残した件を、利用者の選択（2026-11-03）で確かめて直した（docs/RULES.md の Bug #123）。
作業場所 `route-coverage`・枝 `customer-import-blank-dupes`（13.x = `25898343` から）。**DB 変更・ルート変更・ビューの変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前に測ったこと

使い捨てのテスト（scratchpad）で、同じ CSV を 2 回取り込んだ。

| CSV の都道府県・市区町村 | 1 回目の保存 | 2 回目のプレビュー | 2 回目を確定すると |
|---|---|---|---|
| どちらも空欄 | NULL・NULL | 重複候補 0・取り込める 1 | **買主が 2 人になる** |
| 市区町村だけ空欄 | 愛媛県・NULL | 同上 | **2 人になる** |
| 都道府県だけ空欄 | NULL・松山市 | 同上 | **2 人になる** |
| どちらも入っている | 愛媛県・松山市 | 重複候補 1・取り込める 0 | 1 人のまま（正しい） |

- 原因: 空欄は NULL で保存される（取込は空欄の列を保存に渡さず、画面の登録も空欄を NULL にする）のに、重複の確かめは空欄を空文字にしてから `where('prefecture', '')` で比べていた
- 本番の読み取り（利用者の承認のうえ・件数と作成日時だけ）: 買主 17 人（削除済み 1 人を除く）・都道府県の空欄 0・市区町村の空欄 4 人（どれも NULL・空文字は 0）・同じ姓・名・住所（空欄どうしも同じとみなす）の組 0・同姓同名の組 0 ＝ これまでに二重になった人はいない＝片付けは要らない

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\CustomerImportController` — 重複の確かめで、空欄の側は NULL と空文字の両方に当てる（括弧でくくる）。重複候補の行の住所は、どちらも空欄なら「住所なし」 |
| テスト | `tests/Feature/Admin/CustomerImportTest.php` に 12 本（上げ直し 3 通り・空文字で入っている行・空欄と入っている値は別の人 4 通り・名前の違う空欄の人・住所の出し方 3 通り）。4711 → **4723 tests / 48208 assertions green**（PHP 8.3.36・ピーク 169.50 MB） |

- 利用者の決定（2026-11-03・案 A）: 空欄どうしを同じとみなす。姓・名だけで比べる案 B（住所に空欄がある行）は採らなかった（住所の入った同姓同名の別人も重複候補に入り、初期状態で取り込まれなくなる）
- 変異 8 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.customer-import-blank-dupes.py`・結果は `results-customer-import-blank-dupes.txt`）すべて期待どおり

### 範囲外（気づいたが直していない）

- 重複の確かめは部署を見ない（住宅事業の取込で不動産の同じ人も重複候補になり、チェックを入れると別の買主として入る。画面の重複の確かめ〈`checkDuplicate`〉は自部署とほかの部署を分けて返す。コードから読んだだけで未実測）（→ 2026-11-03 に実測して直した。下の「顧客の取込でほかの部署にだけいる同じ人にはこの部署を足す」の節・Bug #124）
- CSV の中の同じ人の 2 行は両方入る（前からの範囲外）

### 本番反映（2026-11-03 実施）

利用者の指示で、`13.x` を `25898343` から **`8e785f63`** へ早送りし、origin へ push してから（origin/13.x = `8e785f63`）`./deploy.sh` を流した。「社内決裁申請」の会話は止まっていた（最終の活動は 10/28）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `25898343`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番のコード（`c804d4f2`）から変わるアプリのファイルは `CustomerImportController.php` の 1 本だけ |
| `./deploy.sh` | 2026-11-03 20:11 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはコントローラ 1 本とビルドだけ（CSS・JS の名前は前と同じ `app-_7h98Lga.css`・`app-NiVQbl_Q.js` で旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | コントローラ・ビュー・記録・キャッシュ | コントローラが手元（`8e785f63`）と **md5 が一致**（`dc65eae7…`）・`php -l` で構文エラーなし ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 20:11 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 変わったのはプレビュー・確定の重複の確かめと重複候補の行の表示だけで、取込の画面（GET）は変わっていないので、Chrome での確認はしていない。本番で実際に上げ直して重複候補になるかは、ファイルを上げる操作なので行っていない（テストと変異で確かめてある）。

---

## ✅ 顧客の取込でほかの部署にだけいる同じ人にはこの部署を足す（Bug #124）— 本番反映済み

上の節の範囲外に残した「重複の確かめは部署を見ない」を、利用者の指示（2026-11-03）で確かめて直した（docs/RULES.md の Bug #124）。
作業場所 `route-coverage`・枝 `customer-import-blank-dupes`（上の節の続き。`6cf54aaf` の次から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい。ビューが変わる）。

### 直す前に測ったこと

使い捨てのテスト（scratchpad）で、登録済みの山田さん（愛媛県松山市）がいる状態で、住宅事業へ同じ人を取り込んだ。

| 登録済みの山田さん | チェック | プレビュー | 確定したあと | 住宅事業の一覧 |
|---|---|---|---|---|
| 不動産だけ | なし | 重複候補 1（「重複の可能性」） | 何も入らない（「プレビューのあとに、同じ人が登録された可能性があります」） | **出ない** |
| 不動産だけ | あり | 同上 | **買主が 2 人**（#1 不動産・#2 住宅事業） | 出る（別の買主として） |
| 住宅事業 | なし | 重複候補 1 | 何も入らない | 出る（正しい） |
| どの部署にもいない | なし | 重複候補 1 | 何も入らない | **出ない** |

- 顧客は 1 人を部署で共有する作り（`buyer_departments`）。画面の登録は同じ人を見つけると「この部署に取り込む」で同じ買主に部署を足すのに、取込だけがこの作りから外れていた
- 本番の読み取り（利用者の承認のうえ・件数だけ）: 買主 17 人（削除済み 1 人を除く）＝住宅事業だけ 8・不動産だけ 9・両方 0・どこにも無い 0。同じ姓・名・住所の組は 0（上の節の読み取り）＝まだ二重になった人はいない＝片付けは要らない

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\CustomerImportController` — 当たった人のうち取込先の部署にいる人がいれば重複候補（既存ID はその人）。いなければ新しく作らず、最初に登録された人（`orderBy('id')`）に取込先の部署を足す（取得日は CSV・ランクは C・アンケートの列があればその人に作る・中身は書き換えない）。同じ CSV の 2 行目からは、前の行で足す人の重複候補にする。完了の文に足した件数 |
| Blade | `admin/customers/import.blade.php` — 件数の帯に「うち登録済みの顧客に足す: N 件」（足す人がいるときだけ。帯は `flex-wrap`）・行ごとの「➕ 行N: 登録済みの顧客にこの部署を足します — … は不動産事業に登録済みです ※ 既存ID: N」・取込の画面に案内を 1 行 |
| テスト | `tests/Feature/Admin/CustomerImportTest.php` に 9 本・1 本に 2 行足した。部品 `existingBuyer()` は取込先の部署（住宅事業）にいる人を作るようにした（重複候補のテストの意味を保つ）。4723 → **4732 tests / 48327 assertions green**（PHP 8.3.36） |

- 利用者の決定（2026-11-03）: ① 案 A（チェックなしで足す。B＝チェックを入れたときだけ足す・C＝今のまま、は採らなかった）② どの部署にもいない人にも足す ③ 本番の読み取りは件数だけ
- 変異 15 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.customer-import-cross-dept.py`・結果は `results-customer-import-cross-dept.json`）すべて期待どおり
- 実ブラウザ（使い捨ての環境・Playwright）: 不動産だけ・どこにもいない・両方にいる・新しい人・エラー行の 5 行の CSV で、確認画面（1440px・375px とも `main` の横スクロール 0・行の文字は枠の中）→ 確定「3件のインポートが完了しました（うち2件は、登録済みの顧客にこの部署を足しました）。」→ 住宅事業の一覧に 4 人・山田さんの電話番号と不動産のランク B はそのまま・コンソールのエラー 0
- コンパイル済みビュー 318 本 / INVALID 0

### 範囲外（気づいたが直していない）

- CSV の中の同じ人の 2 行は、どこにもいない新しい人なら今までどおり両方入る（前からの範囲外）
- 削除した買主とは突き合わせない（削除した人と同じ人は新しく作る。今までと同じ）
- 足したことの記録（画面の「この部署に取り込む」は `Log::info` に残すが、本番の `LOG_LEVEL` は `error` なので残らない）は足していない

### 本番反映（2026-11-03 実施）

利用者の指示で、`13.x` を `6cf54aaf` から **`51e1ff28`** へ早送りし、origin へ push してから（origin/13.x = `51e1ff28`）`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `6cf54aaf`・早送りできる・`vendor/bin/phpunit` は無い |
| `./deploy.sh` | 2026-11-03 22:15 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはコントローラ 1 本・ビュー 1 本とビルドだけ（CSS・JS の名前は前と同じ `app-_7h98Lga.css`・`app-NiVQbl_Q.js` で旧バンドルの削除 0 件）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 2 本の md5・ビュー・記録・キャッシュ | 2 本とも手元（`51e1ff28`）と **md5 が一致**（コントローラ `c4c1e413…`・ビュー `12e5930e…`）・コントローラの `php -l` で構文エラーなし ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 22:15 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |
| Chrome（利用者の Chrome・見るだけ・フォームは送らない） | 顧客CSVインポートの画面 | 200・新しい案内「※ ほかの部署に登録済みの顧客と同じ人（姓・名・都道府県・市区町村が同じ）は、新しく作らずに、その顧客にこの部署を足します」が出る ／ 部署のラジオ 2 つ・ファイル欄あり ／ 読み込んだ CSS は `app-_7h98Lga.css` ／ `main` の横スクロール 0 ／ 読み込み直したときのコンソールのメッセージ 0 件 |

⚠ 確認画面の「➕ 行N: 登録済みの顧客にこの部署を足します」と確定はファイルを上げないと出ないので、本番では見ていない（テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。本番で実際に取り込むのは利用者。

---

## ✅ 顧客の取込の CSV の中の同じ人・セルの中の改行・確定の DB のエラー（Bug #125・#126・#89 の形）— 本番反映済み

Bug #123・#124 の範囲外に残した 3 つを、利用者の依頼（2026-11-04。本番で実際の顧客を取り込む前に）で確かめて直した（docs/RULES.md の Bug #125・#126 と Bug #89 の追記）。
作業場所 `route-coverage`・枝 `customer-import-csv-rows`（13.x = `4b082512` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（`CsvImportReader` にメソッドを 1 つ足しただけ。本番反映は `./deploy.sh` だけでよい）。

### 直す前に測ったこと（使い捨てのテスト・2026-11-04）

| # | 場面 | 直す前 |
|---|---|---|
| 1 | 登録済みでない同じ人（姓・名・都道府県・市区町村が同じ）が CSV に 2 行 | プレビューは 2 行とも「正常」、確定で買主が 2 人（住所が空欄でも・回答が違っても同じ） |
| 2a | 住所詳細（途中の列）にセルの中の改行 | その人は取り込まれず、意味の通らないエラー行が 2 つ |
| 2b | 担当者名（後ろの列）にセルの中の改行 | 改行の手前までの値で取り込まれ、余分なエラー行が 1 つ |
| 2c | アンケートの文字の回答（最後の列）にセルの中の改行 | 「南向き（改行）駐車場2台」が「南向き」だけで保存、エラーなし |
| 3 | 確定で DB のエラー | 赤い帯に `インポートに失敗しました: SQLSTATE[22001] … values (佐藤, 花子, 089-222-2222)`・laravel.log に残らない（全行の取り消しは効いていた） |

### 直したこと

| # | 利用者の決定 | 直し方 |
|---|---|---|
| 1（Bug #126） | A: 2 行目を重複候補にする | 2 行目からは DB に同じ人がいるときと同じく重複候補（チェックを入れたときだけ別人として作る）。確認画面は「※ この CSV の行2 と同じ人」 |
| 2（Bug #125） | A: 正しく読み、セルの中の改行は空白 1 つにして保存 | `CsvImportReader::records()`（1 件ずつ読む・RFC 4180）を足して使う。行番号が Excel の行と合う |
| 3（Bug #89 の形） | — | 画面には「取り込めませんでした（行N まで進んだところでデータベースのエラーが起きました）。1件も登録していません。…理由はシステムの記録（laravel.log）に残しています。」・`report($e)` |

取込の画面に案内を 2 行足した（「※ 同じ CSV に同じ人（…）が 2 行以上あるときは、2 行目からを重複候補にします」「※ セルの中の改行は、空白 1 つにして取り込みます」）。

| 区分 | 実装内容 |
|------|---------|
| Support | `CsvImportReader::records()`（新しいメソッド。`parse()` は変えていない） |
| Controller | `Admin\CustomerImportController`（1 件ずつ読む・セルの中の改行を空白に・`$newPeople`・確定の例外の案内と `report()`） |
| Blade | `admin/customers/import.blade.php`（案内 2 行・重複候補の行の出し分け） |
| テスト | `tests/Unit/Support/CsvImportReaderTest.php`（6 本）・`tests/Feature/Admin/CustomerImportTest.php`（16 本。既存の 2 本は新しい案内の文に合わせ、住所の出し方のテストに既存ID まで足した） |
| 全件テスト | 4732 → **4754 tests / 48464 assertions green**（PHP 8.3.36・ピーク 169.50 MB）／ コンパイル済みビュー **318 本 / INVALID 0** |

### 検証

- 足したテストは、直す前のコードで赤（改行は「取得日が未入力です」などのエラー行・同じ人は validCount 3・DB のエラーは帯の文）を確かめてから直した。守りのテスト（名前や住所が違えば別の人・エラーの行は数えない・部署を足すところの DB のエラー・登録済みの人の既存ID）は直したあとに書き、変異で効いていることを確かめた
- 変異 24 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.customer-import-csv-rows.py`・結果は `results-customer-import-csv-rows.txt`）: 22 通りは期待どおりの組と理由で落ちた。2 通りは狙ったテストは落ちていて、期待の書き方の誤りだった（R1: `str_getcsv` も行末の CR を落とすので行末のテストは落ちない・R4: 取込の画面の描画のテストも落ちた〈テンプレートの見出しの下に空のセルの行を足して上げる〉）→ 表を直して流し直し、2 通りとも期待どおり
- 実ブラウザ（使い捨ての環境・Playwright。Excel と同じ CRLF・セルの中の改行・DB の人の重複・CSV の中の同じ人・エラー行の 5 行）: 取込の画面に案内 2 行／確認画面は「全 5 件・正常 2・エラー 1（⚠ 行6）・重複候補 2（行3 は「既存ID: 1」・行4 は「この CSV の行2 と同じ人」）」・ボタンは「インポート実行（2件）」／1440px・375px とも `main` の横スクロール 0・行の文字は枠の中／確定で「2件のインポートが完了しました。」・山田は 1 人（1 行目の電話番号）・住所詳細「1-2-3 2階」・建物名「ミツワ ハイツ」・勤務先「ミツワ都市開発 （本社）」・佐藤は増えない／コンソールのエラー 0

### 本番反映（2026-11-04 実施）

利用者の指示で、`13.x` を `4b082512` から **`13bd0505`** へ早送りし、origin へ push してから（origin/13.x = `13bd0505`）`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。テナント・賃貸マンションの取込の続きの会話は自分の作業場所で動いていて、13.x には触れない。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `4b082512`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 3 本（コントローラ・`CsvImportReader`・ビュー） |
| `./deploy.sh` | 2026-11-04 9:24 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 3 本とビルドだけ（CSS・JS の名前は前と同じ `app-_7h98Lga.css`・`app-NiVQbl_Q.js`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 3 本の md5・構文・ビュー・記録・キャッシュ | 3 本とも手元（`13bd0505`）と **md5 が一致**・`php -l` で構文エラーなし・`CsvImportReader::records()` が読める ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま（Chrome で画面を開いたあとも）＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 9:24 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |
| Chrome（利用者の Chrome・見るだけ・フォームは送らない） | 顧客CSVインポートの画面 | 200・新しい案内 2 行（同じ CSV の同じ人・セルの中の改行）が出る ／ 読み込んだ CSS は `app-_7h98Lga.css` ／ `main` の横スクロール 0 ／ 読み込み直したときのコンソールのメッセージ 0 件 |

⚠ 確認画面（重複候補の「この CSV の行N と同じ人」）と確定は、ファイルを上げないと出ないので本番では見ていない（テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。本番で実際に取り込むのは利用者。

### 範囲外（気づいたが直していない）

- テナント・賃貸マンション・決裁の利用者の CSV 取込も、`CsvImportReader::parse()` が `explode("\n")` で行に分ける（同じ「改行で行が割れる」形。未実測。決裁は「社内決裁申請」の会話の担当）（→ テナント・賃貸マンションは 2026-11-04 に直した。下の節・Bug #127。決裁も 2026-11-06 に直し、`parse()` を消した）
- テナント・賃貸マンションの取込の確定も `'インポートに失敗しました: ' . $e->getMessage()`（テナント 2 か所・賃貸マンション 6 か所。SQL が画面に出る形。未実測）（→ 2026-11-04 に実測して直した。テナントは 5 か所だった。下の節）
- CSV の中の同じ人の比べ方は文字どおり（本番の MySQL の照合順序は全角・半角などを同じとみなす）
- 2 行目のアンケートは取り込まれない（チェックを入れたときは別人として取り込む）

## ✅ テナント・賃貸マンションの取込のセルの中の改行・確定の DB のエラー（Bug #127・#89 の形）— 本番反映済み

上の節の範囲外に残した 2 つを、manage の会話の依頼（2026-11-04）で確かめて直した（docs/RULES.md の Bug #127 と Bug #89 の追記）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`（枝 `claude/nostalgic-jennings-a2186c`。`customer-import-csv-rows`＝`13bd0505` の上に積み、13.x `601f8f05` を取り込んだ）。**DB 変更・ルート変更・ビューの変更・依存の変更・新しい PHP クラスは無し**（`CsvImportReader` にメソッドを 1 つ足しただけ。本番反映は `./deploy.sh` だけでよい）。

### 直す前に測ったこと（使い捨てのテスト・2026-11-04）

| # | 場面 | 直す前 |
|---|---|---|
| 1 | テナントの物件の住所にセルの中の改行 | 住所「愛媛県松山市」・構造／築年月／階数が空で取り込まれ、割れた後ろ半分が「所有区分「稼働中」は不正な値です」のエラー行。後ろの行の番号が 1 つずれる |
| 2 | テナントの契約の備考／屋号に改行 | 備考は「1行目のメモ」だけ・「部屋番号が未入力です」のエラー行／屋号が切れ、備考が消える |
| 3 | テナントの顧客の代表者名に改行 | 担当者・電話番号・メール・郵便番号・住所が消える |
| 4 | 賃貸マンションの物件の備考／入居者の勤務先に改行 | 備考が切れて「住所が未入力です」のエラー行／緊急連絡先・備考が消える |
| 5 | 確定で DB のエラー（11 タブすべて） | 赤い帯に `インポートに失敗しました: SQLSTATE[22001] … values (二つ目ビル, 愛媛県松山市二番町9-9)`・laravel.log に残らない（全行の取り消しは効いていた）。引き継ぎの「テナント 2 か所」は数え違いで、実際は 5 か所 |

### 直したこと

| # | 利用者の決定 | 直し方 |
|---|---|---|
| 改行（Bug #127） | ① A: テナント・賃貸マンションだけを 1 件ずつ読む形に移す（`parse()` は決裁の利用者の取込のために残す）② ア: 画面が textarea の欄（備考・メモ）は改行を残し、ほかの欄は空白 1 つ | `CsvImportReader::rows()`（`records()` で読み、`parse()` と同じ形の行配列を返す）。各コントローラの `MULTILINE_KEYS`（テナント `notes`・賃貸マンション `notes`・`memo`）の列は CRLF・CR を LF にそろえて残し、ほかの列は続く改行をまとめて空白 1 つ。行番号が Excel の行と合う |
| DB のエラー（Bug #89 の形） | — | 画面には「取り込めませんでした（行N まで進んだところでデータベースのエラーが起きました）。1件も登録していません。…理由はシステムの記録（laravel.log）に残しています。」（書き込みを始める前に落ちたら「書き込みを始める前に」）・`report($e)`（`importFailed()`。顧客の取込と同じ文） |

| 区分 | 実装内容 |
|------|---------|
| Support | `CsvImportReader::rows()`・`columnIndex()`（新しいメソッド。`parse()` は変えていない） |
| Controller | `Admin\TenantImportController`・`Admin\MansionImportController`（`loadCsv()` を `rows()` に・`MULTILINE_KEYS`・確定の例外の `importFailed()` と `report()`） |
| テスト | `tests/Unit/Support/CsvImportReaderTest.php`（`rows()` の 8 本）・新規 `tests/Feature/Admin/TenantImportLineBreakTest.php`（6 本）・`MansionImportLineBreakTest.php`（5 本）・`TenantImportDoubleSubmitTest`・`MansionImportDoubleSubmitTest` の「確定のときのデータベースのエラー」の節（全タブのデータ＋書き込み前の 1 本）・新規 `tests/Concerns/FailsImportWrites.php` ／ `TenantImportRejectionTest`・`MansionImportRejectionTest` の古い文言を直した |
| 全件テスト | 4754 → **4786 tests / 48808 assertions green**（PHP 8.3・ピーク 169.50 MB） |

### 検証

- 足したテストは、直す前のコードで赤を確かめてから直した（改行: `rows()` の 8 本は「メソッドが無い」・画面からの往復の 11 本は「改行の手前で値が切れる」「行の数が 3」／DB のエラー: 帯の文）
- 変異 25 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.tenant-mansion-import-csv-rows.py`・結果は `results-tenant-mansion-import-csv-rows.txt`）: 24 通りは期待どおりの組と理由で落ちた（改行: `rows()` を explode に戻す・改行を残す列を空白にする／どの列も残す・CRLF をそろえない・`+` を外す・CR を残す・前後の空白・見出しの空白・見出しだけの CSV・必須の見出し・2 つの取込を `parse()` に戻す・`MULTILINE_KEYS` の 3 通り／DB のエラー: `report` を消す・書き込み前の言い方・直す前の文に戻す・行の番号を覚えない・null で始めない）。カナリア C0 は落ちた組（7 本）は合っていて、`TenantImportRejectionTest` の理由の文を表で書き違えていただけ → 表を直して流し直し、期待どおり。直す前の読み方（explode）に戻すと、足した 19 本のうち 16 本が落ちる（`rows()` の見出しの読み方・データ行なし・必須の見出しの 3 本は explode でも通る＝改行と関わらない守り）
- 実ブラウザの確認はしていない（画面は変えていない。読み方と保存の値はテストで固定）

### 本番反映（2026-11-04 実施）

利用者の指示で、`13.x` を `601f8f05` から **`93012c13`** へ早送りし、origin へ push してから（origin/13.x = `93012c13`）`./deploy.sh` を流した。早送りの前に、manage の会話が同じコミットで全件を流し直した（**OK (4786 tests, 48808 assertions)**・PHP 8.3.36）。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 3 本の md5・記録・ビルド | 3 本とも `601f8f05` と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-_7h98Lga.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-11-04 14:00 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはコントローラ 2 本・`CsvImportReader` とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 3 本の md5・構文・ビュー・記録・キャッシュ | 3 本とも手元（`93012c13`）と **md5 が一致**・`php -l` で構文エラーなし・`CsvImportReader::rows()` が読める ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 14:00 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ Chrome（claude-in-chrome）が繋がらなかったので、ログインした画面の確認はしていない（画面は変えていない。変わったのはアップロードのあとの読み方と、確定の DB のエラーの帯の文だけ）。確認画面と確定はファイルを上げないと出ないので、本番では見ていない（テスト・変異で確かめてある）。本番で実際に取り込むのは利用者。

### 範囲外（気づいたが直していない）

- 決裁の利用者の取込は `CsvImportReader::parse()` のまま（セルの中の改行で行が割れる形。決裁の画面とテストは「社内決裁申請」の会話の担当）（→ 2026-11-06 に直し、`parse()` を消した。下の「決裁の利用者の取込のセルの中の改行」の節）
- テナント・賃貸マンションの取込も、セルの前後の空白は ASCII の空白だけを落とす（`trim()`。全角の空白は残る。直す前と同じ）（→ 2026-11-06 に直した。下の「CSV 取込のセルと見出しの前後の全角の空白」の節・Bug #128）

## ✅ 決裁の利用者の取込のセルの中の改行（Bug #127 の残り）— 本番反映済み

上の節の範囲外に残した「決裁の利用者の取込は `CsvImportReader::parse()` のまま」を、利用者の指示（2026-11-06。「社内決裁申請」の会話が動いていないことを確かめてから、利用者が「1 で進めて」と許可）で直した（docs/RULES.md の Bug #127 の追記・#125 の注記）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`（枝 `approval-import-csv-rows`。13.x = `da306592` から）。**DB 変更・ルート変更・ビューの変更・依存の変更・新しい PHP クラスは無し**（`CsvImportReader::parse()` を消しただけ。本番反映は `./deploy.sh` だけでよい）。

### 直す前に測ったこと（テストで再現・2026-11-06）

| CSV（Excel と同じ形＝行の終わりは CRLF・セルの中の改行は LF） | 直す前 |
|---|---|
| 所属部門のセルの中で改行（`"RE⏎SA"`） | 行 3 に「社員番号「SA"」は英数字とハイフン 20 文字までで入力してください」のエラー |
| 氏名のセルの中で改行（`"甲⏎一郎"`） | 行 2「所属部門を 1 つ以上入力してください」と、行 3「社員番号「一郎"」…」の 2 つのエラー |
| 改行のある行より後ろの行 | エラーの行番号が Excel の行より 1 つ多い |

- 確定は、エラーの行が 1 つでもあると断る作り。そのため値が黙って欠けることはなかった。ただし、決裁を使い始める前の利用者の一括登録が、意味の通らないエラーで止まっていた

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Controller | `Approval\UserImportController::analyze()` を `CsvImportReader::rows()` に（改行を残す列は無い＝4 列とも 1 行の入力欄なので、改行は空白 1 つ。所属部門の「RE SA」は今の区切りの規則で 2 つの部門に分かれる）。`parse()` を指す注記 2 か所を直した |
| Support | `CsvImportReader::parse()` を消した（使う所が無くなった。壊れた読み方を次にまた使わないため）。docblock から `parse()` の説明を外し、「行を `explode("\n")` で分ける形に戻さない」と書いた |
| テスト | `tests/Feature/Approval/ApprovalUserImportTest.php` に 3 本（所属部門の改行で 1 件のまま RE・SA の 2 部門に入る〈確定まで往復〉・氏名の改行は空白 1 つ・後ろの行の番号が Excel の行と合う）／ `tests/Unit/Support/CsvImportReaderTest.php` の `parse()` の 9 本を `rows()` を呼ぶ形に替えた（BOM・Shift_JIS・CRLF・引用符の中のカンマなどの検査は残る） |
| 全件テスト | 4786 → **4789 tests / 48826 assertions green**（PHP 8.3.36・ピーク 171.50 MB） |

### 検証

- 足した 3 本は、直す前のコードで赤を確かめてから直した（理由は上の表と同じ）
- 変異 4 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.approval-import-csv-rows.py`・結果は `results-approval-import-csv-rows.txt`）すべて期待どおり: カナリア（社員番号の形の誤りの文を変える）は行番号のテストの 1 本だけ／直す前の読み方（`explode("\n")` で行に分ける）に戻すと足した 3 本が直す前と同じ理由で落ちる／氏名の改行を残すと氏名のテストの 1 本だけ／所属部門の改行を残すのは等価で緑（区切りの規則が改行も区切りとみなす）。`rows()` 自身の変異は前の節の表で済んでいる
- 画面（ビュー）は変えていないので、コンパイル済みビューの lint と実ブラウザの確認はしていない（確定までの往復は Feature テストが描いた画面のフォームを送り返して見る）

### 本番反映（2026-11-06 実施）

利用者の指示で、main repo の `13.x` を `da306592` から **`f0808d1b`** へ早送りし、`./deploy.sh` を流した。⚠ **origin への push はしていない**（「本番へ反映」の指示には push が含まれないとして止められた。origin/13.x = `da306592` のまま。→ 2026-11-06 に push 済み〈`da306592..45c89169`〉）。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 2 本の md5・記録・ビルド | 2 本とも `da306592` と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-_7h98Lga.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-11-06 18:54 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは `Approval/UserImportController.php`・`CsvImportReader.php` の 2 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 2 本の md5・構文・ビュー・記録・キャッシュ | 2 本とも手元（`f0808d1b`）と **md5 が一致**・`php -l` で構文エラーなし・`CsvImportReader::rows()` があり `parse()` は無い ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 18:54 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 画面は変えていないので、ログインした画面の確認はしていない。確認画面と確定はファイルを上げないと出ないので、本番では見ていない（テスト・変異で確かめてある）。本番で実際に取り込むのは利用者（決裁の管理者）。

### 範囲外（気づいたが直していない）

- 決裁の利用者の取込も、セルの前後の全角の空白は落とさない（社員番号・メールは `LoginId::normalize()` が別に扱う。今と同じ）（→ 2026-11-06 に直した。下の節・Bug #128）

## ✅ CSV 取込のセルと見出しの前後の全角の空白（Bug #128）— 本番反映済み

上の 2 つの節の範囲外に残した「テナント・賃貸マンション・決裁の利用者の取込は、セルの前後の全角の空白を落とさない」を、利用者の選択（2026-11-06）で直した（docs/RULES.md の Bug #128）。
計画: `~/.claude/plans/jiggly-sleeping-sparrow.md`（リポジトリの外。要点はこの節と Bug #128）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`（枝 `csv-cell-fullwidth-trim`。13.x = `45c89169` から）。**DB 変更・ルート変更・ビューの変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前に測ったこと（使い捨てのテスト・2026-11-06・SQLite）

| 取込 | CSV の値 | 直す前 |
|---|---|---|
| テナント・物件 | 築年月 `2010-04　` | 「形式が不正です」のエラー |
| テナント・物件 | `既存ビル　`（登録済みの「既存ビル」と同じつもり） | 登録済みと見なされず、取り込める行になった（別の物件ができる。⚠ 本番の MySQL では末尾の空白は無視されるので、本番で起きるのは先頭に空白があるとき。下の「本番の読み取り」） |
| テナント・契約 | 物件 `　既存ビル` | 「物件がシステムに登録されていません」 |
| テナント・物件 | 見出し `物件名　` | ファイルごと「必須ヘッダー「物件名」がCSVに見つかりません」 |
| テナント・契約 | 見出し `備考　` | 備考が**黙って空**で入った |
| 賃貸マンション・入居者 | 氏名 `　山田太郎　`・電話 `089-111-1111　` | 空白つきのまま保存 |
| 決裁の利用者 | 氏名 `　甲 一郎　` | 空白つきのまま保存。登録済みの人には「氏名が登録と違います」の注意 |

決裁の利用者の社員番号・メールは `LoginId::normalize()` が全角の空白も落とすので、影響は氏名だけだった。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Support | `CsvImportReader::rows()` のセルと見出しを `trim()` → `Str::trim()`（画面の登録の `TrimStrings`・顧客の取込の `BuyerCsvRow` と同じ）。前後の全角の空白・ノーブレークスペース・ゼロ幅スペース・BOM も落ちる。値の中の空白は残す。備考・メモ（改行を残す列）は値全体の前後だけを落とす |
| Controller | 変えていない（決裁の `trim($row['name'])` は残しても害が無いのでそのまま） |
| テスト | `tests/Unit/Support/CsvImportReaderTest.php` に 3 本・新規 `tests/Feature/Admin/TenantImportCellSpaceTest.php`（4 本）・`tests/Feature/Admin/MansionImportCellSpaceTest.php`（2 本）・`tests/Feature/Approval/ApprovalUserImportTest.php` に 2 本 |
| 全件テスト | 4789 → **4800 tests / 48937 assertions green**（PHP 8.3.36・ピーク 171.50 MB） |

- 利用者の決定（2026-11-06・計画の承認）: セルも見出しも `Str::trim()`・範囲は `rows()` の 1 か所・本番の既存データは反映の前に読み取りだけで確かめる
- 決裁の利用者の取込は「社内決裁申請」の会話の担当だが、ListAgents に出ていなかった（動いていない）。変えたのは共通の部品で、決裁のテストに 2 本足した

### 検証

- 足した 11 本は、直す前のコードで赤を確かめてから直した（理由は上の表と同じ）
- 変異 6 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.csv-cell-fullwidth-trim.py`・結果は `results-csv-cell-fullwidth-trim.txt`）すべて期待どおり（落ちたテストの組と理由の 1 行目まで）: カナリア（必須の見出しの文を変える）は単体の 2 本だけ／セルを `trim()` に戻すと 9 本／見出しを戻すと 2 本／改行を残す列だけ戻すと単体の 1 本／ほかの列だけ戻すと 8 本／ノーブレークスペースを落とさない形にすると単体の 1 本
- ⚠ 備考・メモの前後の全角の空白と、ノーブレークスペースを見るのは単体のテスト 1 本ずつだけ（画面からのテストには見本が無い）
- 本番の読み取りのあと、登録済みの物件のスキップのテストの見本を「　既存ビル　」（先頭にも空白）に直し、変異 F1・F4 を流し直して期待どおりを確かめた
- ビュー・画面は変えていないので、ビューの lint と実ブラウザの確認はしていない

### 範囲外（気づいたが直していない）

- 値の**中**の全角の空白（そのまま）
- 顧客の取込の見出しの `trim()`（`CustomerImportController`。全角の空白の入った見出しは列に結びつかない）（→ 2026-11-12 に直した。下の「顧客CSVインポートの見出しの全角の空白と、標準の列名と同じ文の設問」の節・Bug #129）
- `CsvImportReader::records()` の空の行の判定（顧客と共用。Excel の行は必ずカンマを含むので影響が小さい）
- 本番に前後の空白つきで入っている値の書き換え（本番は 0 件。下の「本番の読み取り」）

### 本番の読み取り（2026-11-06・利用者の承認のうえ・読み取りだけ）

`ssh … /bin/sh < 手順ファイル` で `artisan tinker --execute`（PHP 8.3）を流し、件数だけを出した（値は出していない・書き込みなし）。

| 見たこと | 結果 |
|---|---|
| 前後に全角の空白（`E38080`）かノーブレークスペース（`C2A0`）がある値 | `properties.name` 0／17 件・`customers.name` 0／113・`units.room_number` 0／158・`ms_properties.property_name` 0／17・`ms_tenants.name`・`ms_rooms.room_number`・`ms_parkings.parking_number` は行が 0 件・`users.name` 0／6（どれも論理削除も含む）＝ 直したあとの上げ直しで照合が外れる値は無い |
| 照合順序 | 接続も `properties.name`・`ms_tenants.name` も `utf8mb4_unicode_ci`。`'ビル' = 'ビル　'` → 真・`'ビル' = '　ビル'` → 偽 ＝ 末尾の全角の空白は以前から比べるときに無視されていた（照合は先頭の空白のときだけ外れていた）。保存される値・日付と金額の形・見出しの結びつきは、末尾の空白でも直す前は崩れていた |

### 本番反映（2026-11-06 実施）

利用者の指示で、main repo の `13.x` を `45c89169` から **`b8201bc7`** へ早送りし、`./deploy.sh` を流した。⚠ **origin への push はしていない**（origin/13.x = `45c89169` のまま。→ 2026-11-06 に利用者の指示で push 済み〈`45c89169..3e3375c2`〉）。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x は `45c89169` のまま・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは `app/Support/CsvImportReader.php` の 1 本だけ |
| 反映の前（本番の読み取り） | 1 本の md5・記録・ビルド | `CsvImportReader.php` は `45c89169` と一致（`097e2795…`）＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-_7h98Lga.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-11-06 20:00 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは `CsvImportReader.php` の 1 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | md5・構文・ビュー・記録・キャッシュ | `CsvImportReader.php` が手元（`b8201bc7`）と **md5 が一致**（`d1753f7d…`）・`php -l` で構文エラーなし・`Str::trim` が 3 か所 ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 20:00 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 画面は変えていないので、ログインした画面の確認はしていない。確認画面と確定はファイルを上げないと出ないので、本番では見ていない（テスト・変異で確かめてある）。本番で実際に取り込むのは利用者。

---

## ✅ 顧客CSVインポートの見出しの全角の空白と、標準の列名と同じ文の設問（Bug #129）— 本番反映済み

上の節（Bug #128）の範囲外「顧客の取込の見出しの `trim()`」と、アンケート設問の管理の節の範囲外「`colMap` が Q の列の見出しの文も標準の列と比べる」を、利用者の選択（2026-11-12）でまとめて直した（docs/RULES.md の Bug #129）。
計画: `~/.claude/plans/jiggly-sleeping-sparrow.md`（リポジトリの外。要点はこの節と Bug #129）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`（枝 `customer-import-header-trim`。13.x = `3e3375c2` から）。**DB 変更・ルート変更・ビューの変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前に測ったこと（使い捨てのテスト・2026-11-12・SQLite）

| CSV | 直す前 |
|---|---|
| 見出し `電話番号　`（末尾に全角の空白） | 電話番号が **null** で保存。警告なし |
| 見出し `姓　` | 「姓が未入力です」のエラー（姓は入っているのに） |
| 見出し `　Q1:来場のきっかけ`（先頭に全角の空白） | アンケートが**作られない**。警告なし |
| 設問の文が `職業`（テンプレートの見出しが `Q1:職業`） | 職業の列（会社員）が設問の回答（自営業の予定）で**上書き**される |
| 設問の文が `姓`・回答は空欄 | 「姓が未入力です」のエラー（姓は入っているのに） |

テンプレートのダウンロードも `…,担当者名,Q1:職業` と書くので、下の 2 つはテンプレートどおりに書いても起きる。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\CustomerImportController::execute()` — 見出しを `Str::trim()` で読む（`CsvImportReader::rows()` と同じ）。標準の列の対応（`$colMap`）は見出しをそのまま標準の列名と比べ、設問の列の `Q1:` を除かない |
| テスト | `tests/Feature/Admin/CustomerImportTest.php` に 4 本（標準の列の見出しの前後の全角の空白・設問の列の見出しの先頭の全角の空白・設問の文が `職業`／`姓` のテンプレートの見出しの下に書いた行）。直す前に 4 本とも赤 |
| 全件テスト | 4800 → **4804 tests / 48999 assertions green**（PHP 8.3.36・ピーク 171.50 MB） |

- 最初は設問の列（`/^Q\d+:/`）を飛ばす行も足したが、見出しをそのまま比べれば `Q1:職業` が `職業` に当たることは無く、どの変異でも見分けられない行になるので外した（テストは両方の形で緑）

### 検証

- 変異 5 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.customer-import-header-trim.py`・結果は `results-customer-import-header-trim.{json,txt}`）すべて期待どおり（落ちたテストの組と理由の 1 行目まで）: カナリア（当たる設問の無い列の文を変える）は 1 本／見出しを `trim()` に戻すと 2 本（標準の列・設問の列）／先頭の空白だけ落とすと標準の列の 1 本／末尾の空白だけ落とすと 2 本（電話番号が空・設問の列）／`Q1:` を除いて比べる形に戻すと、設問の文が「職業」「姓」の 2 本
- 1 回目の全件テストは、途中で `AreaBuildingMapTabTest` の 1 本（`test_the_locate_mode_walks_the_list_and_never_moves_the_map`）が赤になった（ほかのテストを同時に流していたとき）。最後のコードで全件を流し直すと緑で、そのテストのファイルを単独で 3 回流しても緑だった（再現しない。node を呼ぶテストが負荷で落ちた可能性が高い。原因は突き止めていない）
- ビュー・画面は変えていないので、ビューの lint と実ブラウザの確認はしていない

### 範囲外（気づいたが直していない）

- 値の**中**の全角の空白・全角の「Ｑ１：」の見出し
- 同じ標準の列名の見出しが 2 つある CSV（後ろの列が勝つ。前から）
- `CsvImportReader::records()` の空の行の判定（顧客と共用）
- ZEAL 会員の取込（`HacomonoMemberMapper`・`ZealMemberImportController`）の `trim()`（hacomono が書き出すファイルなので、全角の空白は入りにくい）

### 本番の読み取り（2026-11-12・利用者の承認のうえ・読み取りだけ）

`ssh … /bin/sh < 手順ファイル` で `artisan tinker --execute`（PHP 8.3）を流し、件数と、標準の列名と同じ文だけを出す形にした（書き込みなし）。

| 見たこと | 結果 |
|---|---|
| 設問 | 住宅事業の有効な 6 問だけ（無効 0・不動産事業 0） |
| 文が標準の列名（`BuyerCsvRow::COLUMNS` の 21 個）と同じ設問 | 0 問（直す前の比べ方〈半角の空白だけ落とす〉でも、全角の空白も落とす比べ方でも）＝ これまでの取込で、職業などが設問の回答で上書きされた顧客はいない |
| 文の前後に全角の空白かノーブレークスペースがある設問 | 0 問 |

⚠ 見出しの前後の全角の空白で値が黙って抜けた取込があったかは、上げた CSV が本番に残らないので確かめられない。

### 本番反映（2026-11-12 実施）

利用者の指示で、main repo の `13.x` を `3e3375c2` から **`212ae939`** へ早送りし、`./deploy.sh` を流した。⚠ **origin への push はしていない**（origin/13.x = `3e3375c2` のまま。→ 2026-11-12 に利用者の指示で push 済み〈`3e3375c2..d409fd45`〉）。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `3e3375c2`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは `CustomerImportController.php` の 1 本だけ |
| 反映の前（本番の読み取り） | 1 本の md5・記録・ビルド | `CustomerImportController.php` は `3e3375c2` と一致（`59371728…`）＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-_7h98Lga.css`・`app-NiVQbl_Q.js` |
| `./deploy.sh` | 2026-11-12 15:49 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルはコントローラ 1 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | md5・構文・ビュー・記録・キャッシュ | `CustomerImportController.php` が手元（`212ae939`）と **md5 が一致**（`c2cc240d…`）・`php -l` で構文エラーなし・`Str::trim($column)` が 1 か所 ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 15:49 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 画面は変えていないので、ログインした画面の確認はしていない。確認画面と確定はファイルを上げないと出ないので、本番では見ていない（テスト・変異で確かめてある）。本番で実際に取り込むのは利用者。

---

## ✅ ZEAL 会員の編集で、保存済みの値をそのまま保存できるようにする（Bug #130）— 本番反映済み

計画: `~/.claude/plans/jiggly-sleeping-sparrow.md`（リポジトリの外。要点はこの節と Bug #130）。
「ZEAL 会員の画面の入力チェックを列に合わせる」の節（Bug #73）の範囲外に残した 3 つを、利用者の選択（2026-11-12・2026-11-13）で確かめて直した（docs/RULES.md の Bug #130）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`（枝 `zeal-member-edit-saved-values`。13.x = `d409fd45` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 本番の読み取り（2026-11-12・利用者の承認のうえ・件数だけ）

編集画面の入力チェックと同じ規則を、本番の会員の保存済みの値に当てて数えた（`zeal_members` を読むだけ・書き込みなし）。

| | 人数 | 編集で断られる人 | 理由 |
|---|---|---|---|
| 在籍 | 40 | 17 | フリガナが空 17・性別が空 1 |
| 退会 | 5 | 5 | フリガナが空 5 |

氏名・住所の長さ・メールの形式・備考の長さで断られる人は 0。店舗 1・トレーナー 1 とも有効で、無効の店舗・トレーナーが付いた人は 0。`laravel.log` は 6 月 18 日のまま。

### 直したこと（利用者の決定 2026-11-13・A 案）

| 項目 | 直す前 | 直した後 |
|---|---|---|
| フリガナ・性別 | 必須（取込で空のまま入った会員は、ほかの項目だけ直したいときも断られる） | 保存されている値が空のときに限り任意（一度入れると、次からは必須）。画面はラベル「任意」・`required` なし・案内つき |
| 氏名・住所 | 50・200 文字まで（列と取込は 100・300） | 100・300 文字まで（入力欄の `maxlength` も） |
| メール | 形式を検査し、入力欄も `type="email"` | 保存されている値と同じなら形式を問わない。入力欄は `type="text" inputmode="email"`（形式はサーバーが見る） |
| 店舗・担当トレーナー | 有効なものだけを選択肢に出す（無効にした店舗は「必須です」、無効の担当は保存で黙って外れる） | 有効なもの ＋ この会員の今の値（無効は「（無効）」）。入力チェックも同じ集合＝新しく選べるのは有効なものだけ |

| 区分 | 実装内容 |
|------|---------|
| Controller | `Zeal\MemberController` — `update()` の規則・新しい非公開メソッド `selectableStores()`・`selectableTrainers()`・`kanaRequired()`・`genderRequired()`・`savedText()`。`edit()` が選択肢と「必須か」をビューへ渡す |
| Blade | `zeal/members/_form.blade.php`（フリガナ・性別の任意の表示・上限・メールの欄・「（無効）」）・`zeal/members/edit.blade.php`（渡す値） |
| テスト | 新規 `tests/Feature/Zeal/MemberEditSavedValuesTest.php`（8 本）・`MemberInputLimitsTest` に氏名・住所（6 本）・`MemberScreenTestCase` に `editHtml()`・`assertRefused()`・`tagWithId()`（`MemberInputLimitsTest` から移した） |
| 全件テスト | 4804 → **4818 tests / 49208 assertions green**（PHP 8.3.36・ピーク 171.50 MB） |

### 検証

- 足した 14 本のうち 8 本は、直す前のコードで狙った理由で赤を確かめてから直した（フリガナの欄の `required`・`type="email"`・今の店舗が選ばれない・無効の店舗が通る・氏名と住所の上限）。残り 6 本は「直しすぎ」を止める守りのテスト（フリガナのある会員は今どおり必須・変えたメールは形式を見る・有効な店舗は選べる など）
- 変異 26 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.zeal-member-edit-saved-values.py`・結果は `results-zeal-member-edit-saved-values.{txt,json}`）: 1 回目は 24 通りが期待どおり。G2（性別を常に任意）・S4（担当の入力チェックを `exists` に戻す）は狙ったテストが落ちたが理由が違った — 同じテストの中で 1 回目に断られた送信の入力（`old()`）が 2 回目の画面に残り、別の欄でも断られていた → 2 回目の前に `flushSession()` を入れて（`1f82d318`）流し直し、26 通りすべて期待どおり（1 回目の結果は `results-zeal-member-edit-saved-values.round1.txt`）
- コンパイル済みビュー **318 本 / INVALID 0**
- 実ブラウザ（使い捨ての環境・Playwright。demo.sqlite の写しで、会員のフリガナ・性別を空・メールを `yamada@`・店舗とトレーナーを無効にした）: 1440px で、フリガナの欄に `required` が無く案内が 2 つ出て、ブラウザのフォームの検査が通る／電話番号だけ変えて「更新する」→「「在籍 太郎」の情報を更新しました。」・DB は電話番号だけ変わり、フリガナ・性別は空・メール `yamada@`・店舗とトレーナーはそのまま／メールを `tanaka@` にすると「メールアドレスは正しいメールアドレスの形式で入力してください。」で断られる／375px で `main` の横スクロール 0・案内は枠の中／コンソールの警告・エラー 0

### 範囲外（気づいたが直していない）

- 取込（`HacomonoMemberMapper`）はそのまま（フリガナ・性別が空・形の崩れたメールも入れる）
- 本番の空のフリガナ・性別を埋めること（編集のときに入れれば、次からは必須になる）
- ZEAL の会員の新規登録の画面は無い（取込だけ）

### 本番反映（2026-11-13 実施）

利用者の指示で、main repo の `13.x` を `d409fd45` から **`aefb8f61`** へ早送りし、`./deploy.sh` を流した。⚠ **origin への push はしていない**（origin/13.x = `d409fd45` のまま。→ 2026-11-13 に push 済み〈`d409fd45..7bf1c4d5`〉）。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `d409fd45`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 3 本（`Zeal/MemberController.php`・`zeal/members/_form.blade.php`・`edit.blade.php`） |
| 反映の前（本番の読み取り） | 3 本の md5・記録・ビルド | 3 本とも `d409fd45` と一致＝本番は 13.x のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-_7h98Lga.css` ／ フリガナが空の在籍の会員の先頭の id は 25・26・28（確認の画面を選ぶためだけに id を読んだ） |
| `./deploy.sh` | 2026-11-13 9:05 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 3 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | md5・構文・ビュー・記録・キャッシュ | 3 本とも手元（`aefb8f61`）と **md5 が一致**・コントローラの `php -l` で構文エラーなし ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラーは 0 件 ／ `bootstrap/cache` は 9:05 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |
| Chrome（利用者の Chrome・見るだけ・保存しない） | フリガナが空の在籍の会員（id 25）の編集画面 | フリガナは空・`required` なし・ラベル「任意」・案内あり（性別は登録済みなので必須のまま・案内なし）／ メールの欄は `type="text" inputmode="email"`／ 氏名 100・住所 300 の `maxlength` ／ 店舗は選ばれたまま（今は無効の店舗・トレーナーが無いので「（無効）」は 0）／ ブラウザのフォームの検査が通る ／ `main` の横スクロール 0 ／ コンソールのエラー 0（読み取りを始めたのは画面を開いたあと） |

---

## ✅ 取込で入ったデータが編集画面で保存できないか（Bug #130 の形）を本番で数えた — 0 件（コードは変えていない）

Bug #130（取込で入ったデータが、編集画面の入力チェックで断られて、そのままでは保存できない）と同じ形が、ほかの画面に無いかを、利用者の指示（2026-11-13）で本番の読み取りで数えた。
**結果はどの画面も 0 件**なので、記録だけ（アプリ・テスト・DB の変更なし）。数える手順は git の管理外 `.claude/route-coverage-tools/edit-saved-values/`（main repo）に残した。

### 数え方

各行について、編集画面がブラウザから送る値を組み立てて（Blade の初期値・選択肢・Alpine の初期値を読んで写した）、4 つを数えた。値と名前は出さず件数だけ。

| 区分 | 中身 |
|---|---|
| サーバーが断る | 各コントローラの `update()` の規則を写したもの（`TrimStrings`・`ConvertEmptyStringsToNull` と同じく前後の空白を落として空は null・textarea は改行を CRLF にして長さを数える）。テナントの区画の 0 階と表示名の重なり、賃貸マンションの部屋・駐車場の「契約中なのに空室・空き」も |
| ブラウザが送信を止める | number の欄の `min`・`max`・`step`（刻みの基準は `min`）・`type="email"`（WHATWG の形）・面積の欄の `pattern="[0-9.]*"`。⚠ `step` と範囲の外は、利用者がその欄に触らなくても送信を止める（`maxlength` は触った欄だけ） |
| 保存で黙って変わる | 選択肢に無い値が空になる・月の欄（`type="month"`）が `YYYY-MM` でない値を空にする・年月の部品が値を作り直す・テナントの物件の所有区分が空なら「自社所有」になりオーナー名が消える など |
| 画面が開けない | 区分（enum）の型の列に壊れた値（モデルを読むときに落ちる） |

手元の使い捨ての DB にわざとおかしな値を入れて流し、上の 4 つをすべて拾うことを確かめてから本番で流した（カナリア）。

### 本番の結果（2026-11-13・利用者の承認のうえ・読み取りだけ）

| 画面 | 対象の行 | 保存できない | 黙って変わる |
|---|---|---|---|
| テナントの物件 | 16（論理削除を除く。どれも `tenant`） | 0 | 0 |
| テナントの区画 | 151 | 0 | 0 |
| テナントの顧客 | 111 | 0 | 0 |
| テナントの契約（解約していないもの） | 93 | 0 | 0 |
| 賃貸マンションの物件 | 17 | 0 | 0 |
| 賃貸マンションの部屋・駐車場・入居者・部屋契約・駐車場契約 | 0（行がまだ無い） | — | — |
| 買主（住宅事業の部署） | 8 | 0 | 0 |
| 買主（不動産の部署） | 9 | 0 | 0 |

列が無いものは 0・`laravel.log` は流す前も後も Jun 18 17:41（エラーは増えていない）。

### 今は 0 件でも、取込で入りうる形（コードを読んで確かめた・直していない）

| 画面 | 取込が入れる値 | 編集画面で起きること |
|---|---|---|
| 賃貸マンションの部屋 | 家賃・共益費・敷金・礼金は 0 以上の整数なら何でも（例: 家賃 65,500 円） | 4 つの欄が `step="1000"` なので、1,000 円の倍数でないと**ブラウザが送信を止める**（触らなくても。登録画面でも同じ金額は打てない） → 2026-11-13 に直した（画面の側）（Bug #131） |
| 賃貸マンションの部屋 | 階 0 | 階の欄が `min="1" max="50"`（サーバーは 0〜255）なので**送信を止める** → 2026-11-13 に直した（画面の側）（Bug #131） |
| 賃貸マンションの部屋 | 間取りは文字なら何でも | 選択肢（1R〜4LDK・その他）に無い間取りは保存で**空になる** → 2026-11-13 に直した（画面の側）（Bug #131） |
| 賃貸マンションの駐車場・駐車場契約 | 月額料金は整数なら何でも（例: 3,300 円） | `step="500"`。駐車場契約の敷金は `step="1000"`、部屋契約は家賃・敷金・礼金 1000・共益費 500 → 2026-11-13 に直した（画面の側）（Bug #131） |
| 賃貸マンションの部屋契約 | 入居者は駐車場のみ利用者でも入る（範囲外 D5） | 入居者の選択肢は居住者だけなので空になり「入居者は必須です」 |
| 賃貸マンションの物件 | 構造は文字なら何でも・階数は 0 以上なら何でも | 構造は選択肢（RC造・S造・SRC造・木造・その他）に無いと**空になる**・階数 51 以上は `max="50"` で**送信を止める**（サーバーは 255 まで） → 2026-11-13 に直した（画面の側）（Bug #131） |
| テナントの物件 | 築年月 `2010/4` は `2010-4` で入る（月を 2 桁にそろえない。`2010-13` も入る） | 月の欄が `YYYY-MM` でない値を空にするので、保存で築年月が**黙って消える** → 2026-11-13 に直した（取込の側で断る）（Bug #131） |
| テナントの物件 | 階数は 1 以上なら上限なし | 100 以上は `max="99"` で**送信を止める** → 2026-11-13 に直した（取込の側で断る）（Bug #131） |
| 買主 | メールの形を見ない（Bug #89 の注記） | 形の崩れたメールは `type="email"` で**送信を止める**（Bug #130 と同じ形） → 2026-11-13 に直した（画面の側）（Bug #131） |
| 買主 | 職業・都道府県は文字なら何でも（例: 「パート」「愛媛」） | 選択肢（会社員・公務員・自営業・医師・自由業・その他／47 都道府県）に無い値は保存で**空になる** → 2026-11-13 に直した（画面の側）（Bug #131） |
| 買主 | 生年月日は未来の日でも入る | 「今日以前」で断られる → 2026-11-13 に直した（取込の側で断る）（Bug #131） |

⚠ 数える手順は規則と画面の写しなので、コントローラや編集画面を変えたら写しも直す。

## ✅ 取込で入った値を編集画面からそのまま保存できるようにする（Bug #131）— 本番反映済み

設計書: @docs/superpowers/specs/2026-11-13-import-edit-roundtrip-design.md
実装計画（直す前の赤・変異・実ブラウザの記録つき）: @docs/superpowers/plans/2026-11-13-import-edit-roundtrip.md

上の「取込で入ったデータが編集画面で保存できないか（Bug #130 の形）を本番で数えた」の「取込で入りうる形」の表を、利用者の依頼（2026-11-13）で直した（docs/RULES.md の Bug #131）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`・枝 `edit-saved-values-audit`（13.x = `7bf1c4d5` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（テストの部品と走査は増えた。本番反映は `./deploy.sh` だけでよい）。

### 直したこと

| 画面 | 直す前 | 直した後 |
|---|---|---|
| 賃貸マンションの金額 17 か所（部屋・駐車場・部屋契約・駐車場契約・改定・敷金精算） | `step="1000"` などで、1 円単位の値（65,300 円・3,300 円）が触らなくても送信を止められる | `step` を外した（`min="0"` は残す） |
| 部屋の階・物件の総戸数と階数 | 階 `min="1" max="50"`（取込は 0〜255）・総戸数と階数の上限が列より狭い | 階 `min="0" max="255"`・総戸数 `max="65535"`・階数 `max="255"` |
| 部屋の間取り・物件の構造・買主の都道府県と職業 | 選択肢に無い値は保存で空になる | 保存済みの値が選択肢に無いときだけ「値（選択肢に無い値）」を選んだまま描く。入力エラーで戻ったら選び直した値を優先 |
| 買主のメール欄 | `type="email"` が `yamada@` を止める | `type="text" inputmode="email"`（形式はサーバーが見る） |
| 取込の築年月（テナントの物件・賃貸マンションの物件） | `2010/4` が `2010-4` のまま入り、`2010-13` も入る（月の欄が空にする） | `CsvDate::normalizeYearMonth()` で 2 桁にそろえ、月 1〜12 以外は誤りの行 |
| テナントの物件の取込の階数 | 上限なし（編集画面は `max="99"`） | 1〜99 以外は誤りの行 |
| 顧客の取込の生年月日 | 日本の今日より後でも通る | その行の誤り（今日は `JapanTime::today()` を引数で渡す） |

テストの部品: `ChecksBrowserConstraints`（フォームを分解して `step`・`min`・`max`・`type="email"`・`type="month"`・`required`・`pattern` を満たすか確かめる。`assertBrowserSubmits()`）・`NumberInputStepScanTest`（画面の `step` を全件分類）。

### 決めたこと

- 設計の範囲に加えて、**賃貸マンションの物件の取込の築年月も直した**（同じ `CsvDate` の部品。設計では編集画面側だけだった）
- 「止める」制約は、サーバーの規則と本番の列に合わせて広げる（取込で入れられる値を編集画面が止めない）。選択肢に無い値は、新しく選べる選択肢には足さず、今の値としてだけ残す（Bug #82・#113 と同じ考え方）

### 範囲外（気づいたが直していない）

- 部屋契約の入居者が駐車場のみ利用者のとき（D5）・テナントの顧客と賃貸マンションの入居者のメール・不動産の建蔽率／容積率の `step="10"`・本番のデータの書き換え
- 賃貸マンションの取込は総戸数・階数・部屋の階・構造・間取りの上限を見ない（列を超えると確定で DB が断り、取込全体が取り消される可能性が高い。出るのは Bug #89 の総称の文で、どの値が悪いかは出ない。SQLite のテストでは測れていない推測で未実測）（→ 2026-11-15 に、6 タブの 24 列と小数の金額を直した。下の節・Bug #132）
- 道具の死角: `maxlength`・name の無い欄・select と textarea
- 点検の小さな指摘（後回し）: トレイトの private メソッド名の衝突の恐れ（接頭辞をそろえる）・走査の見本に単一引用符と `script` 内の `step` と `approvals/` の除外が無い・年 `0000-04` が通る・全角数字／`2010-4-1` の取込の誤りは Unit だけで固定・買主の選び直しのテストが都道府県と職業を同時に見る・住宅事業の買主の画面を通るテストは無い（共用ビュー）

### 検証

- 全件 **OK (4894 tests, 50102 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **318 本 / INVALID 0**
- 変異 46 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.import-edit-roundtrip.py`・結果は `results-import-edit-roundtrip.*`）: 1 回目は期待どおり 38・表の誤り 3・穴 5（入力エラーで戻った画面を `old()` でなく保存済みの値で描く変異 4 つと職業の余計な option）。テストを 4 本足して最終すべて期待どおり。B6 は流し直して MATCH（`results-import-edit-roundtrip.B6-rerun.txt`）
- 実ブラウザ（使い捨ての環境・Playwright。demo.sqlite の写し）: 部屋 102（家賃 65,300・階 0・間取り「メゾネット」）→「更新する」で「部屋を更新しました」・DB は同じ値／物件（総戸数 65,535・構造「軽量鉄骨造」）→「物件を更新しました」／部屋契約の改定（賃料 65,300 のまま共益費だけ 6,000 に）→「賃料を改定しました」／不動産の買主（都道府県「東京」・職業「パート」・メール `yamada@`）→「顧客情報を更新しました。」で値は変わらない／テナントの物件の取込（築年月 `2010/4`）→確定で DB は `2010-04`・編集画面を開いて保存しても `2010-04` のまま。部屋・物件・買主の編集画面は 1440px・375px で `main` の横スクロール 0・コンソールのエラー 0
- 最後の点検（fable）の直し（Minor 8 件）: 取込の築年月の年 `0000` を誤りにした（`type="month"` は年が 1 以上で、編集画面が空にするため。アプリの動きが変わるのはここだけ）／道具 `ChecksBrowserConstraints` の月の判定も年 0 を妥当と数えないようにし、配列の値と不正な `pattern`（警告になっていた）と `\/` を含む `pattern` を違反または通過として返す／道具と `NumberInputStepScanTest` の死角の注記を足した／`SavedValuesRoundTripTest` は選択肢にある値の selected がちょうど 1 つであることを見る／RULES の誤った括弧（敷金は 10）を消し、範囲外の記述と前の節の表（画面の側・取込の側）を直した。見本 5 行と Unit 1 行を足し、全件 OK (4894 tests, 50102 assertions)・警告なし。
- ⚠ 使い捨ての環境に構造マスター（`structure_types`）が無く、テナントの物件の編集画面は 500 になる（環境のせい。手元で表を足して確かめた）

## ✅ 賃貸マンションの CSV 取込で、本番の列に入らない値をその行の誤りにする（Bug #132）— 本番反映済み

設計書（短）: @docs/superpowers/specs/2026-11-15-mansion-import-column-limits-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-15-mansion-import-column-limits.md

上の節（Bug #131）の範囲外に残した「賃貸マンションの取込は総戸数・階数・部屋の階・構造・間取りの上限を見ない」を、利用者の決定（2026-11-15）で直した（docs/RULES.md の Bug #132）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`・枝 `mansion-import-column-limits`（13.x = `b8816e4c` から）。**DB 変更・ルート変更・ビューの変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 数えたこと（コードと `database/sql/create_mansion_tables.sql` の突き合わせ）

本番の列に入らない値を通していた列は 24 列。画面の入力チェック（`Mansion\*Controller`）はもう列に合わせてあり、取込だけが外れていた。本番は strict なので、1 行でも入らない値があると確定で例外になり、総称の文で全行が取り消され、どの値が悪いかは出なかった。

| タブ | 列（本番の上限） | 直す前の取込 |
|---|---|---|
| 物件 | オーナー名 100・郵便番号 10・構造 50 文字 | 長さを見ない |
| 物件 | 総戸数 0〜65535・階数 0〜255 | 0 以上の整数だけ |
| 部屋 | 階 0〜255・間取り 20 文字・面積 0〜999999.99 | 上限・長さを見ない |
| 部屋 | 家賃・共益費・敷金・礼金（0〜4,294,967,295） | `is_numeric` で 0 以上だけ |
| 駐車場 | 月額料金 | 同上 |
| 入居者 | 電話番号・緊急連絡先電話 20・勤務先・緊急連絡先氏名 100・続柄 50 文字 | 長さを見ない |
| 部屋契約 | 家賃・共益費・敷金・礼金 | 同上 |
| 駐車場契約 | 月額料金・敷金 | 同上 |

あわせて、金額が `is_numeric` なので `55000.9` は黙って 55000 で保存され、`1e10` も通っていた。本番の `ms_rooms`・`ms_parkings`・`ms_tenants`・契約の表は 0 行（2026-11-13 の読み取り）＝部屋から後のタブは先回りの直し。

### 決めたこと（利用者の決定 2026-11-15）

1. 範囲は 6 タブの 24 列すべて（上限は画面の入力チェックと同じ値）
2. 構造・間取りは長さだけ見る（選択肢に限らない。Bug #131 で、編集画面は選択肢に無い値も残す形にした）
3. テナントの取込（`TenantImportController`。長さの検査が 0 件・同じ形の列が約 25 列）は別の作業にする
4. 金額は整数だけを通す（`55,000` のカンマはこれまでどおり通す）

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\MansionImportController` — 部品 `tooLongMessage()`（`mb_strlen`・タブごとの上限の定数）と `readUnsignedInt()`（`ctype_digit`・上限との比べは `(float)`。カンマを除くのは金額だけで、総戸数・階数・階は直す前と同じくカンマ入りを断る）。6 タブの今ある検査の位置で呼ぶ。面積は `(float) > 999999.99` を断る |
| 文 | 長すぎる:「{見出し}は{上限}文字以内で入力してください」（既存の形）／上限を超える:「{見出し}「{値}」は{上限}以下の値にしてください」（画面の `max` と同じ言い方）／形の誤り: 今の文のまま |
| テスト | 新規 `tests/Feature/Admin/MansionImportColumnLimitsTest.php`（28 本）。4894 → **4922 tests / 50422 assertions green**（PHP 8.3・警告なし） |

- 検査の順は変えない: 部屋契約・駐車場契約は「日付 → CSV 内の重複 → 照合 → 金額」（登録済みの行は金額が上限を超えてもスキップ）。物件・入居者の新しい長さの検査は登録済みのスキップより後ろ
- オーナー名は自社所有で保存しない行でも長さを見る（画面の入力チェックと同じ。こちらの判断）

### 範囲外（気づいたが直していない）

- テナントの取込（長さの検査が 0 件・物件名 200・住所 500・顧客の 8 列・区画と契約の金額 INT など約 25 列。テナントは本番で使われている取込なので別の作業で）（→ 2026-11-15 に直した。下の節・Bug #133）
- 備考・メモ（TEXT・65,535 バイト。画面も長さを見ていない）
- 部屋の取込のあとで数え直す物件の総戸数（SMALLINT。部屋が 65,536 件を超えないと起きない）

### 検証

- 足したテスト 28 本のうち 24 本は、直す前のコードで狙った理由（その行が誤りにならず通る）で赤を確かめてから直した。残り 4 本（登録済みの行はスキップのまま）は順の守りで、直す前から緑
- 全件 **OK (4922 tests, 50422 assertions)**（PHP 8.3・警告なし）。ビューは変えていないので、ビューの lint と実ブラウザの確認はしていない（プレビューの描画は Feature テストが見る）
- 変異 25 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.mansion-import-column-limits.py`・結果は `results-mansion-import-column-limits.*`）: すべて期待どおり（`mb_strlen`→`strlen`・`>`→`>=`・定数から 1 列ずつ外す〈9 通り〉・カンマを除かない・`ctype_digit`→`is_numeric`・上限を 1 増やす・階数と階を総戸数の上限で見る・面積の比べを外す・契約の金額の検査を照合の前へ・物件と入居者の長さの検査をスキップの前へ・オーナー名を管理受託の行でだけ見る・カンマ入りのセルを `(int)` で読む）
- 最後の点検（fable・読み取りだけ）: 「マージしてよい」・Critical・Important 0・Minor 5。M1（部品がカンマを除くので、総戸数・階数・階が `2,5` を黙って 25 で通すようになっていた。直す前は断っていた）は、黙って値が変わるので Important とみなして直した（総戸数・階数・階はカンマを除かない。テストを足して RED→GREEN・全件 OK (4922 tests, 50422 assertions)・警告なし）。変異の表はこの直しの前に流したもの（M14 の置き換え前の行は今のコードには無い）
- 後回しにした点検の Minor: 金額の桁区切りの位置を見ない（`5,5000` も通る。直す前から同じ）（→ 2026-11-16 に金額と面積の両方を直した。下の「CSV 取込の金額と面積で、カンマの区切りが崩れた値をその行の誤りにする」の節）／`55000.00`・`+5`・`-0` が形の誤りになり、部屋・契約のタブの文は「…は不正な値です」で整数で書けばよいことが伝わらない（Excel で小数 2 桁の書式にしたセルの CSV で起きうる。「整数だけ」は利用者の決定）／テストの見本に `+5`・`-1`・`55000.00` が無い・部屋番号の `>=` の文字列の比べ／面積の上限の文が float の連結に頼る

## ✅ テナントの CSV 取込で、本番の列に入らない値をその行の誤りにする（Bug #133）— 本番反映済み

設計書（短）: @docs/superpowers/specs/2026-11-15-tenant-import-column-limits-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-15-tenant-import-column-limits.md

上の節（Bug #132・賃貸マンション）で「別の作業」とした件を、利用者の指示（2026-11-15）でこの会話で行った（docs/RULES.md の Bug #133）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`・枝 `tenant-import-column-limits`（`mansion-import-column-limits` の上に積んだ）。**DB 変更・ルート変更・ビューの変更・依存の変更は無し。新しい PHP クラスが 1 つ**（`App\Support\CsvImportLimits`。本番への反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。

### 数えたこと

テナントの取込（`Admin\TenantImportController`・5 タブ）は、物件の階数のほかは長さも範囲も見ていなかった。本番の `SHOW CREATE TABLE`（4 表）を読み取りで確かめ（2026-11-15・承認のうえ）、テスト用の写しと同じ大きさだった。

| タブ | 見ていなかった列（本番の列＝画面の上限） |
|---|---|
| 物件 | 物件名 200・郵便番号 10・住所 500・構造 50・オーナー名 200 文字 |
| 区画 | 部屋番号 20 文字・面積（列は DECIMAL(8,2)・画面は 9,999.99 坪）・募集の 5 金額（INT＝0〜2,147,483,647） |
| 顧客 | テナント名・テナントカナ 200・代表者名・担当者 100・電話番号 20・メールアドレス 255・郵便番号 10・住所 500 文字 |
| 契約 | 5 金額・屋号 200 文字・備考（TEXT。画面は 5,000 文字まで） |
| 過去契約 | 5 金額・屋号 200・テナント名 200 文字（取込で作る顧客の名前）・備考（同上） |

金額は `is_numeric` で読まれ、`55000.9` は黙って 55000 で保存されていた。本番の件数（読み取り）: 面積が 9,999.99 坪を超える区画 0・備考が 5,000 文字を超える契約 0。

### 決めたこと（利用者の決定 2026-11-15）

1. 範囲は 33 列＋契約・過去契約の備考 5,000 文字（取込で超えて入ると編集画面で保存できない＝Bug #130 の形）
2. 区画の面積の上限は画面と同じ 9,999.99 坪
3. 金額は賃貸マンションと同じく整数だけ（カンマは通す）
4. 本番の列の定義を読み取りで確かめる（済み）

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Support | `App\Support\CsvImportLimits`（新規・static）— 賃貸マンションの取込の部品 `tooLongMessage()`・`readUnsignedInt()` を移した。`MansionImportController` もこれを使う（動きは変わらない） |
| Controller | `Admin\TenantImportController` — 上限の定数 5 つと `MAX_AREA_TSUBO`。5 タブの今ある検査の位置で呼ぶ（物件・顧客は登録済みのスキップの後・区画の部屋番号は区画の重複の後・契約と過去契約は照合の後で金額の前） |
| テスト | 新規 `tests/Feature/Admin/TenantImportColumnLimitsTest.php`（40 本）・`tests/Unit/Support/CsvImportLimitsTest.php`（15 本）。4922 → **4977 tests / 50904 assertions green**（PHP 8.3・警告なし） |

- 文は Bug #132 と同じ形（見出しは CSV の見出し）。オーナー名は所有区分が「オーナー」でなく保存しない行でも長さを見る（画面と同じ）
- 備考の 5,000 文字は、改行を編集画面と同じく 2 文字（CRLF）と数える（ブラウザの textarea は改行を CRLF で送り、画面の入力チェックは 2 文字と数える。1 文字と数えると、改行の多い 5,000 文字近い備考が取り込めるのに編集画面で保存できない＝最後の点検の指摘で直した）
- ⚠ メールアドレスの列は 255 文字だが、形の検査（`filter_var`）が 254 文字までしか通さないので、取り込めるのは 254 文字まで（256 文字は長さの検査が先に断る）

### 範囲外（気づいたが直していない）

- 物件・区画・顧客の備考（取込は書かない）
- 過去契約の「顧客 N件を自動作成」の数え方（誤りで落ちた行の顧客まで数える。前から）（→ 2026-11-16 に直した。下の「過去契約の取込の「顧客 N件を自動作成」を取り込む行から数える」の節）

### 検証

- 足したテストのうち 35 本は、直す前のコードで狙った理由（その行が誤りにならず通る）で赤を確かめてから直した。残り 4 本（登録済みの行はスキップのまま）は順の守りで、直す前から緑。部品の Unit テストはクラスが無いことで赤
- 全件 **OK (4977 tests, 50904 assertions)**（PHP 8.3・警告なし）。ビューは変えていないので、ビューの lint と実ブラウザの確認はしていない
- 変異 35 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs.tenant-import-column-limits.py`・結果は `results-tenant-import-column-limits.*`）: すべて期待どおり（上限の定数から 1 列ずつ外す〈19 通り〉・面積の上限を増やす／比べを外す・3 タブの金額の上限を 1 増やす・契約と過去契約の文字と金額の検査を照合の前へ・物件と顧客の長さの検査をスキップの前へ・オーナー名を「オーナー」の行でだけ見る・部品の `ctype_digit`→`is_numeric`・`mb_strlen`→`strlen`・`>`→`>=`）
- 最後の点検（fable・読み取りだけ）: 「マージしてよい」・Critical・Important 0・Minor 5。Minor 1（備考の改行を 1 文字と数えるので、改行の多い 5,000 文字近い備考が取り込めるのに編集画面で保存できない）は、利用者の決定 1 の目的そのものなので Important とみなして直した（`65ebe20d`。改行を 2 文字と数える。テストを足して RED→GREEN・全件 OK (4977 tests, 50904 assertions)・警告なし）。変異の表はこの直しの前に流したもの（T33 の置き換え前の行は今のコードには無い）
- 後回しにした点検の Minor: `55000.0`・`55000.00`・`+55000` が「…は不正な値です」で断られ、整数で書けばよいことが伝わらない（直す前は通っていた。「整数だけ」は利用者の決定。本番で使われている取込なので、反映のときに一言伝える）／過去契約のプレビューの「顧客 N件を自動作成」に、誤りで作られない 201 文字のテナント名も載る（表示だけ。数え方は前から）（→ 2026-11-16 に直した。下の「過去契約の取込の「顧客 N件を自動作成」を取り込む行から数える」の節）／テストの小さな穴（未登録の契約で文字と金額のどちらの文が先に出るか・登録済みの契約のスキップに長い備考の見本が無い）／部品の Unit テストの docblock が Bug #132 だけを指す
- 後回しにした点検の Minor の 1 つ目（小数点付きの金額）は、利用者の確認（2026-11-15）で直さないことにした: 金額に `55000.00` のような小数点が付くことは無い（小数点が付くのは坪単価・面積など）。金額は今のまま整数だけを通す

### 本番反映（2026-11-15 実施。Bug #131・#132・#133 を一緒に）

利用者の指示で、ローカルの `13.x` を `b8816e4c` → `e758d3dd` へ早送りし、origin へ push してから（`7bf1c4d5..e758d3dd`）反映した。**DB 変更・ルート変更・依存の変更は無し。** 新しいクラス `App\Support\CsvImportLimits` があるので、main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3）を流してから `./deploy.sh`。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（手元） | 本番に送られる差分 | 本番の版 `7bf1c4d5` からのアプリの変更は 15 本（コントローラ 3・Support 3〈うち新規 `CsvImportLimits`〉・ビュー 9）で、どれも Bug #131・#132・#133 のもの |
| 反映の前（本番の読み取り） | 14 本の md5・新しいクラス・記録・ビルド | 14 本とも `7bf1c4d5` と一致＝本番は前回の反映のまま ／ `CsvImportLimits.php` は無い ／ `laravel.log` は Jun 18 17:41 ／ CSS は `app-_7h98Lga.css` |
| 読み込みの表 | main repo の cwd で `composer dump-autoload --no-dev --optimize` | 7,320 クラス・`CsvImportLimits` が 1 行・作業場所のパスは 0・`vendor/bin/phpunit` は無い |
| `./deploy.sh` | 2026-11-15 19:13 ごろ（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 15 本と読み込みの表 2 本だけ。CSS の名前は前と同じ（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | 15 本の md5・ビュー・クラス・記録・キャッシュ | 15 本とも手元（`e758d3dd`）と一致 ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `CsvImportLimits` が読め、`4,294,967,296` は「…4294967295以下の値にしてください」 ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 19:13 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 画面（Bug #131 で変えた賃貸マンションと買主の編集画面）は、Claude in Chrome が繋がらず見ていない。コンパイル済みビューの lint と、テスト・使い捨ての環境の実ブラウザ（2026-11-13）で確かめてある。取込の確定・編集画面の保存は本番では送っていない。

#### 本番の画面の確認（2026-11-16・利用者の指示・ログイン済みの Chrome・見るだけ）

Bug #131 で変えたビュー 9 本のうち、本番に行がある画面を開いた（同じオリジンの iframe で 1440px・375px）。保存・送信はしていない。

| 画面 | 見たこと |
|---|---|
| 賃貸マンションの物件の編集（物件 2） | 総戸数 `min="0" max="65535"`・階数 `min="0" max="255"`・どちらも `step` なし ／ 構造は今の値「RC造」が選ばれたまま（「（選択肢に無い値）」の選択肢は 0＝本番の値は選択肢にある） |
| 部屋の登録（物件 2） | 階 `min="0" max="255"`・家賃・共益費・敷金・礼金は `min="0"` だけで `step` なし ／ 間取りの選択肢 13（余計な選択肢なし） |
| 駐車場の登録（物件 2） | 月額料金 `step` なし |
| 部屋契約の登録 | 家賃・共益費・敷金・礼金 `step` なし |
| 駐車場契約の登録 | 月額料金・敷金 `step` なし |
| 買主の編集（住宅事業 3・不動産 6） | メール欄は `type="text" inputmode="email"` ／ 都道府県・職業は今の値が選ばれたまま（住宅事業 3 は「愛媛県」「自営業」・不動産 6 は「愛媛県」・職業は空）・「（選択肢に無い値）」は 0 ／ フォームの `checkValidity()` は true（ブラウザが送信を止めない） |

- 6 画面とも 200・Alpine が起動・`main` の横スクロールは 1440px・375px とも 0 ／ 読み込み直して開いたときのコンソールの出力 0 件 ／ CSS は `app-_7h98Lga.css`
- ⚠ 開けなかった画面: 部屋・駐車場・部屋契約・駐車場契約の編集、賃料改定・料金改定・解約（本番の部屋・駐車場・契約が 0 行）。この画面の `step` を外したことは、上の登録画面と同じ部品（`_form`）か同じ形の行で、テストと使い捨ての環境の実ブラウザ（2026-11-13）で確かめてある
- ⚠ 「（選択肢に無い値）」と、形の崩れたメールで送信が止まらないことは、本番にその値の行が無いので見ていない（テストと使い捨ての環境で確かめてある）

## ✅ CSV 取込の金額と面積で、カンマの区切りが崩れた値をその行の誤りにする（Bug #132 の追記）— 本番反映済み

設計書（短）: @docs/superpowers/specs/2026-11-16-import-comma-grouping-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-16-import-comma-grouping.md

上の Bug #132 の節で「後回しにした点検の Minor」に残した「金額の桁区切りの位置を見ない」を、利用者の指示（2026-11-16）で直した（docs/RULES.md の Bug #132 の追記・#133 の追記）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`・枝 `tenant-import-column-limits`（13.x = `32759892` から）。**DB 変更・ルート変更・画面の変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前

賃貸マンション・テナントの取込は、金額と面積を**カンマを全部取り除いてから**読んでいたので、打ち間違いが黙って別の値で入った。

| セル | 直す前 |
|---|---|
| `5,5000` | 55000 で保存 |
| `5,,000` | 5000 で保存 |
| `5,50` | 550 で保存（5,500 のつもりかもしれない） |
| `,5000`・`5000,` | 5000 で保存 |

金額は共通の部品 `CsvImportLimits::readUnsignedInt()`（両方の取込のすべての金額）、面積は 2 つのコントローラ（賃貸マンションの部屋の専有面積・テナントの区画の面積）が、それぞれ `str_replace(',', '', …)` で読んでいた。

### 決めたこと（利用者の決定 2026-11-16・案 B）

- 金額と面積の 2 列を同じ決まりで読む（案 A＝金額だけ は採らなかった）
- カンマが無い値は今までどおり。カンマがある値は、整数の部分が 3 桁ごとの正しい区切り（`^\d{1,3}(?:,\d{3})+$`）で、小数の部分にカンマが無いときだけカンマを除いて読む
  - 通る: `55,000`・`1,000,000`・`4,294,967,295`・`1,234.56`・`055,000`
  - 断る: `5,5000`・`5,,000`・`5,50`・`,5000`・`5000,`・`5555,000`・`1,234.5,6`・`-1,000`
- 崩れた値は今の形の誤りの文でその行を誤りにする（「家賃「5,5000」は不正な値です」「面積「5,5000」は不正な値です」）。新しい文は作らない
- 決まりは部品 `CsvImportLimits::withoutThousandsSeparators(string $raw): ?string` の 1 か所（崩れていれば null）。`readUnsignedInt()` と 2 つの面積の読み方が使う
- 検査の順・上限・ほかの文・総戸数／階数／階（カンマを通さない）は変えない

### 検証

- 足したテストは、直す前のコードで狙った理由で赤を確かめてから直した（部品が無い・崩れた値が入る・rowErrors が空）
- 回帰テスト: `tests/Unit/Support/CsvImportLimitsTest.php` に 24 本（通る形 8・崩れた形 8 を部品と `readUnsignedInt()` で）と既存の 1 本の書き換え（`2,5` → `2,500`）・`MansionImportColumnLimitsTest`（家賃の `5,5000`・`test_area_with_thousands_separators_is_read_only_when_well_grouped`）・`TenantImportColumnLimitsTest`（区画の 5 つの金額に `5,5000`・同名の面積のテスト。テナントの面積は `1,50` で見る＝`5,5000` は直す前も上限 9,999.99 で断られる）
- 全件 **OK (5003 tests, 50954 assertions)**（PHP 8.3・警告なし。4977 本に 26 本）
- 変異 12 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs.import-comma-grouping.py`・結果は `results-import-comma-grouping.*`）すべて期待どおり（部品を直す前の形に戻す・先頭の組を何桁でもに緩める・後ろの組を何桁でもに緩める・小数の部分のカンマの確かめを外す・`^`／`$` を外す・`readUnsignedInt()` を戻す・2 つの面積をそれぞれ戻す。緑は等価の 2 通り＝面積の `$val === null ||` を外す。`is_numeric(null)` は false なので同じ文で断る）
- 画面は変えていないので、ビューの lint と実ブラウザの確認はしていない
- 最後の点検（fable・読み取りだけ）: 「マージしてよい」・Critical 0・Important 1・Minor 5。Important（周辺ビル調査の取込に同じ形が残るのに記録に無い）は、記録の漏れとして設計書・RULES・この節の範囲外に書き足した（コードは別の作業）
- 後回しにした点検の Minor: 正規表現の `$` が末尾の改行を許す（`"1,000\n"` が一致する。`CsvImportReader::rows()` が前後を落とすので今は届かない。直すなら `/…$/D` か `\z`）／面積で `+1,000`・`1,000e3` も断るようになった（直す前は 1000・1000000 で入った。厳しくなる向き。見本は `-1,000` だけ）／登録済みの契約 × 崩れたカンマのテストが無い（上限超えと同じ分岐なので今は守られている）／`misplaced()` に `,500`・`5,000,` が無い（先頭の組を `\d{0,3}` に緩める変異が落ちない）／`055,000` を `readUnsignedInt()` で直接見るテストが無い

### 範囲外（気づいたが直していない）

- 面積の `is_numeric` が通すカンマと関係しない形（`1e3`・`+5` など）
- ZEAL 会員の取込（`HacomonoMemberMapper`。hacomono の書き出しで、カンマは機械が付ける）・アンケートの数の回答（`SurveyAnswers`）のカンマ
- **周辺ビル調査の取込にも同じ形が残る**（最後の点検で見つかった。設計の段では数え漏らしていた）: `Tenant\AreaBuildingImportController::parseInt()`（営業・空き・不明の件数）と `FloorNumber::parse()`（階）は、全角のカンマ・空白・「円」と一緒にカンマを全部除いてから読むので、`1,5` が 15 件、`2,5` が 25 階で黙って入る。別の読み方（全角も落とす）なので今回の部品にはそろえていない。そろえるかは利用者の判断（→ 2026-11-17 に、件数はこの部品で、階はカンマを通さない形で直した。下の「周辺ビル調査の取込で、件数と階の崩れたカンマと数字のあいだの空白を断る」の節・Bug #134）
- 全角のカンマ（`，`。今も断っている）・取込の画面の案内文
- 本番のデータ（崩れた区切りで入った値があるかは、上げた CSV が本番に残らないので確かめられない）

### 本番反映（2026-11-16 実施）

利用者の指示で、13.x を `32759892` → `7a2507f4` へ早送りし、origin へ push してから（`32759892..7a2507f4`）`./deploy.sh` を流した。直前に枝の先頭（`7a2507f4`）で全件 OK (5003 tests, 50954 assertions)・警告なし。ほかに 13.x を動かしている会話は無かった（ListAgents）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 3 本の md5・記録・ビルド | 3 本とも前回の反映 `e758d3dd` と一致＝本番は前回のまま ／ `laravel.log` は Jun 18 17:41 ／ CSS は `app-_7h98Lga.css` |
| `./deploy.sh` | 2026-11-16 12:01 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは `CsvImportLimits.php`・`MansionImportController.php`・`TenantImportController.php` の 3 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | md5・構文・ビュー・部品の動き・記録・キャッシュ | 3 本とも手元（`7a2507f4`）と **md5 が一致**・`php -l` で構文エラーなし ／ コンパイル済みビュー **318 本 / INVALID 0** ／ 本番の PHP 8.3 で部品が `55,000` → `'55000'`・`5,5000` → null・`1,234.56` → `'1234.56'`・`1,234.5,6` → null、`readUnsignedInt('5,5000', …)` → `[null, 'FORMAT']` ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 12:01 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 画面は変えていないので、Chrome での確認はしていない。取込のプレビューと確定はファイルを上げないと動かないので、本番では送っていない（テスト・変異で確かめてある）。本番で実際に取り込むのは利用者。

## ✅ 過去契約の取込の「顧客 N件を自動作成」を取り込む行から数える（Bug #70 の形）— 本番反映済み

設計書（短）: @docs/superpowers/specs/2026-11-16-past-contract-create-list-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-16-past-contract-create-list.md

上の Bug #70（契約の上げ直し）の節の範囲外「過去契約の「顧客 N件を自動作成」の数え方」と、Bug #133 の節の後回しの Minor
「誤りで作られない 201 文字のテナント名も載る」を、利用者の指示（2026-11-16）で直した（docs/RULES.md の Bug #70 の追記）。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`・枝 `tenant-import-column-limits`（13.x = `5a10f511` から）。
**DB 変更・ルート変更・画面の変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前（使い捨てのテストで実測・2026-11-16）

テナントの過去契約の取込（`/admin/tenant-import` の過去契約タブ）の確認画面は、「作成予定」に「過去契約 N件を新規作成（顧客 M件を自動作成: 名前…）」と出す。

| 行 | 中身 | 直す前の扱い |
|---|---|---|
| 2 | 作られる商事（正常） | 取り込む |
| 3 | 金額誤り商事（家賃 `abc`） | 誤り。でも「自動作成」に載る |
| 4 | 201 文字のテナント名 | 誤り。でも載る |
| 5 | 屋号長い商事（屋号 201 文字） | 誤り。でも載る |
| 6 | 日付誤り商事（契約日 `2020-13-01`） | 誤り。載らない（顧客の確かめより前で止まる） |

確認画面は「顧客 **4件**を自動作成: 作られる商事、金額誤り商事、いいい…」、確定の完了の文は「顧客 **1件**を自動作成」（作られたのは作られる商事だけ）。
原因は、顧客がマスタに無いと分かった時点（行の検査の途中）で名前を積んでいたこと。確定はもともと取り込む行（`$validRows`）から顧客を作るので正しかった。
ほかのタブ・賃貸マンションの取込には同じ形は無い（要約は `$validRows` から作る）。

### 決めたこと（利用者の決定 2026-11-16・案 B）

- 「自動作成の予定」を検査の途中で積むのをやめ、ループのあとで**取り込む行から作る**（区画タブの「復元」の件数と同じ作り）。
  別案の「名前を足す所を取り込むと決めた位置へ移す」（Bug #70 の警告と同じ形）は、途中で積む入れ物が残るので採らなかった
- 先の行が誤りでも、あとの行が同じ名前で取り込まれるならその名前は載る（確定でも作られる）
- 文言・5 件まで並べて「 ...」を付ける形・確定の完了の文は変えない

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Controller | `Admin\TenantImportController::executePastContract()` — `$customerCreateList` の途中の積み上げをなくし、プレビューの前で `array_values(array_unique(array_column(array_filter($validRows, fn (array $row) => $row['_customer_will_be_created']), 'customer_name')))` から作る |
| テスト | 新規 `tests/Feature/Admin/TenantPastContractCustomerListTest.php`（3 本）。5003 → **5006 tests / 50992 assertions green**（PHP 8.3・警告なし） |

### 検証

- 足した 3 本のうち 2 本（誤りの行の名前が載らない・6 件以上の並び）は、直す前のコードで要約の食い違い（「顧客 4件を自動作成: …」「顧客 7件を自動作成: あ商事、誤り商事、…」）の赤を確かめてから直した。残り 1 本（あとの行が同じ名前で取り込まれるなら 1 回だけ載る）は直しすぎを止める守りで、直す前から緑
- 全件 **OK (5006 tests, 50992 assertions)**（PHP 8.3・警告なし・3 分 05 秒・ピーク 171.50 MB）
- 変異 7 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs.past-contract-create-list.py`・結果は `results-past-contract-create-list.*`）すべて期待どおり（`array_unique` を外す・`array_filter` を外す・条件を反転・5 件を 6 件に・「 ...」を外す・件数を取り込む行の数に。最後の 1 つは 6 件どうしのテスト 3 では緑の見込みどおり）
- 画面（ビュー）は変えていないので、ビューの lint と実ブラウザの確認はしていない（確認画面の描画は Feature テストが見る）

### 範囲外（気づいたが直していない）

- CSV の中の重複の確かめの「最初の行」は、その行があとで誤りになっても覚えたまま（Bug #70 の設計どおり）
- 本番のデータ（表示だけの食い違いで、保存される値は変わらない）

### 本番反映（2026-11-16 実施）

利用者の指示で、13.x を `5a10f511` → `b77978ff` へ早送りし、origin へ push してから（`5a10f511..b77978ff`）`./deploy.sh` を流した。直前に枝の `5d9a0d16`（アプリのコードは `b77978ff` と同じ）で全件 OK (5006 tests, 50992 assertions)・警告なし。manage を触るほかの会話は動いていなかった（ListAgents）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 1 本の md5・記録・ビルド | `TenantImportController.php` は前回の反映 `7a2507f4` と一致（`4d4e9939…`）＝本番は前回のまま ／ `laravel.log` は Jun 18 17:41 ／ CSS は `app-_7h98Lga.css` |
| `./deploy.sh` | 2026-11-16 13:38 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは `TenantImportController.php` の 1 本とビルドだけ（CSS・JS の名前は前と同じで旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | md5・構文・ビュー・記録・キャッシュ | 手元（`b77978ff`）と **md5 が一致**（`adab5d1d…`）・`php -l` で構文エラーなし・取り込む行から作る新しい行があり、途中で積む行（`$customerCreateList[] = `）は 0 ／ コンパイル済みビュー **318 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 13:38 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 画面（ビュー）は変えていないので、Chrome での確認はしていない。確認画面はファイルを上げないと出ないので、本番では送っていない（テスト・変異で確かめてある）。本番で実際に取り込むのは利用者。

## ✅ 原価管理の一覧入力（見込み額・確定額の表）— 本番反映済み

設計書: @docs/superpowers/specs/2026-11-16-realestate-cost-grid-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-16-realestate-cost-grid.md
モック: @docs/mockups/realestate/procurement-cost-grid.html

利用者の依頼（2026-11-16）は、不動産の原価管理（仕入れ案件・分譲地の詳細画面と新規登録画面）の「＋ 費用追加」を 1 行ずつ入れる形から、Excel のように一覧で入れる形に変えること。
作業場所 `.claude/worktrees/realestate-cost-grid`・枝 `realestate-cost-grid`（13.x = `8bd5169d` から。実装は `4fa5a965` から `b29885ac` まで）。**DB 変更・依存の変更は無し。新しい PHP クラスが 1 つとトレイトが 1 つある**（クラス `App\Support\RealEstateCostGrid`・トレイト `ValidatesCostGrid`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。ルートは 4 本消えて 2 本足した。

### 何ができるようになったか

- 原価管理に「見込み額を入力」「確定額を入力」の 2 つのボタン。押すと費用項目マスタの全項目を並べた表が開き、押したほうの列だけがセルになる（確定額の表は見込み額の無い項目も並ぶ）
- 確定額の表にだけ「差額（確定 − 見込み）」の列（見込みより高いと赤・低いと緑）
- Enter・Shift+Enter・上下の矢印でセルを移動。Excel の列をコピーして 1 つのセルに貼ると下の行へ順に入る（物件購入費の自動の行は飛ばす）
- 変えたセルは黄色・「保存していない変更 N 件」・保存せずに離れようとすると確かめる
- 保存は表の全部を 1 回の要求でまとめて送る（`PUT …/costs/batch`）。サーバーは 1 つのトランザクションで保存し、1 行でも断る行があれば何も保存せず、その行を赤くして理由を出す
- 新規登録の原価欄も同じ表（物件購入費の行は出さない）。入れた内容は登録ボタンで案件と一緒に保存する。表を開いたまま「登録する」を押すと止めて案内を出す
- 一覧の行の「編集」と「＋ 費用追加」は外した（「削除」は経営層だけで今どおり）。古い 1 行ずつの `storeCost`・`updateCost`（2 コントローラ・4 ルート）は消した。試算表 Excel 取込は残した

| 区分 | 実装内容 |
|------|---------|
| 受け口 | 新規 `App\Support\RealEstateCostGrid`（保存の決まり）・`ValidatesCostGrid`（検査の配列を 1 か所に）。`ProcurementController`・`ProjectController` に `saveCostGrid()`。batch のルートは `costs.update` より前に置く |
| 画面 | 新規 `realestate/_partials/_cost_grid.blade.php`・`_cost_grid_script.blade.php`（`costGridFactory(opts)`）を詳細 2 画面と新規登録で共用。見るだけの一覧と合計の getter は各画面に残した（Top trap #8） |
| ルート | 4 本消し、`costs/batch` の 2 本を足した（`role:executive,manager`） |
| テスト | 新規 `CostGridSaveTest`・`CostGridScreenTest`・`CostGridCreateFormTest`。道具 `DrivesAlpineFetch` の偽の window に `addEventListener` を足した |
| 本番の DB | **変更なし** |

### 決めたこと

| # | 決めたこと |
|---|---|
| 利用者（2026-11-16） | 案 A（2 つのボタンと表）・分譲地と新規登録も同じ形・同じ費用項目を 2 行に分ける機能は作らない（本番の仕入れ案件で 0 件。分譲地は「その他」2 行の 1 件があるが、表では別の行として 1 行ずつ出る）・試算表 Excel 取込は残す |
| 計画 | 受け口は 1 本にまとめて保存（途中の行で断られても半端な保存が残らない）／ 衝突は親の行と原価の行を `lockForUpdate()` して、開いたときの値（`original`）を今の DB と比べる ／ 物件購入費は詳細画面の表にだけ出して表示だけ ／ 開いたまま登録は `@submit.window` で止める ／ 確定額の空は「未定」に戻す（行は残す）／ 管理者は保存済みの行を消せない（経営層だけ） |
| Ruling 1 | 物件購入費の新しい行のテストでは、その案件に保存済みの物件購入費の行を作らない（衝突の確かめが先に当たる順は計画どおりで、テストのデータの側の前提） |
| Ruling 2 | 詳細画面を動かす既存テストの「一緒に読む script」に `costGridFactory` を足してよい（アサートは変えない。古いテストを緑のまま保つ唯一の機械的な道） |
| Ruling 3 | 「未定」の表示は詳細画面の一覧だけ。新規登録の一覧の確定額の空は「—」のまま |
| I2 の裁定 | 検査の 3 配列の逐語の写しは、2 コントローラが使うトレイト 1 か所にまとめた（中はリテラルの配列のまま。和名の走査が読めることをカナリアで確かめた） |
| Task 3 の持ち越し | 表を開いているあいだ、ボタン 3 つと一覧を隠すことのテストをその Task で足した |
| Task 5 の分け方 | 変異と実ブラウザを同じ作業場所で同時に動かすと、変異の入ったコードを画面が読むので、全件・変異 → 実ブラウザ → 記録の順に分けた |

### 検証

- 全件 **OK (5137 tests, 53267 assertions)**（PHP 8.3・警告なし・3 分 11 秒・ピーク 173.50 MB）。最後の点検の直しのあとは **OK (5141 tests, 53317 assertions)**。着手前は OK (5006 tests, 50992 assertions)
- コンパイル済みビュー **320 本 / INVALID 0**
- 変異 41 通り（カナリアを含む・`.claude/route-coverage-tools/mutate/specs.realestate-cost-grid.py`・結果は `results-realestate-cost-grid.txt`）: 期待どおり 26・期待の書き方の誤り 9（狙ったテストは落ちた）・**緑のまま通った穴 3 系統**。穴は `b29885ac` でテストを足して塞ぎ、当て直して狙った理由で赤になった
  - 詳細画面から表の include を外しても全部緑（仕入れ案件・分譲地）→ 描いた HTML に表のマークアップと保存のボタンがあることを見るテスト
  - `DB::transaction` を外しても全部緑 → `test_the_save_locks_the_owner_and_its_costs` にトランザクションが 1 つあることを足した
  - 計画のカナリア C0（`MESSAGES` の文を変える）は、テストが定数を引くので緑 → 実在の文を変える C0b に替えた
- ⚠ `X-Requested-With` を外す変異（J5）を捕まえるのは `test_the_estimate_grid_is_saved_in_one_request` の 1 本だけ（`AjaxFetchSessionGuardTest` は GET の fetch しか見ない。保存は PUT なので Top trap #9 の対象外）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態）: 仕入れ案件の ①〜⑪ と分譲地の ①④⑦⑨ がすべて OK（NG 0）。最初のセルへの focus・Enter / 矢印での移動（物件購入費を飛ばす）・カンマ整形と合計・本物の `paste`・保存と一覧への反映・差額の色・リロードで確かめ・削除の確かめ・登録を開いたまま止める・375px と 1440px で `main` の横スクロール 0・コンソールのエラー 0。一般の管理者ロールには「削除」ボタンが出ず、保存済みの見込み額を空にすると行に理由が出る
- ⚠ 観察（ブラウザの確認で見つけ、最後の点検の直しで直した・`1f393dfe`）: 新規登録の備考セルで Enter を押すと暗黙の送信になり、「原価の表を開いたままです…」の alert が出ていた（値は残る。金額セルの Enter は行移動で alert なし）。備考の Enter を下の行の備考へ移す形に直し、実ブラウザで alert が出ないことを確かめた（備考でも上下の矢印で動く）。日本語入力の確定の Enter は再現できず未確認。成功の文が一瞬で消えるのは既存の `showMessage` が 3 秒で消すため
- 最後の全体の点検（With fixes）の直し（1 回。`1f393dfe`・`ef6b617a`・`88ed2bd1`・`1c226e00`・`f7b7bb50`）: 備考の Enter を下の行へ移す（I1・観察の件）・1 行だけのタブ入りの貼り付け（`300\t9` など）は 1 列目だけを入れる（M1）・受け口の `rows.*.notes` に `present`（M2）・費用項目の id を整数で比べる（M3。`in_array` が型に頼る件）・断りを出す前置き 6 行を 1 か所に（重複）・ARCHITECTURE.md の不動産のルート数（M6）。直しのあとの再点検で 6 件とも ADDRESSED・新しい Critical・Important なし。全件 OK (5141 tests, 53317 assertions)・ビュー 320 本 / INVALID 0。M4（セッション切れの文言）・M5（Excel 取込とのロックの順）は後回し（新しい文言・取込を変えない決めのため）（→ 2026-11-17 に直した。下の「原価管理の一覧入力の後回し 2 つ（M4・M5）」の節・Bug #135・#136）

### 範囲外・後回し

設計書 12 章:

- 同じ費用項目を 2 行に分けて入れる機能・試算表 Excel 取込の「追加」モードが同じ項目の 2 行目を作ること・契約の画面など原価を読むだけの画面・費用項目のマスタの画面
- ⚠ **権限の食い違い**: 試算表 Excel 取込（`role:executive,manager`）の「上書き」を選ぶと、物件購入費以外の保存済みの行をまとめて消す。管理者もこれを使える（確認は出る）。表では管理者に行を消させない。利用者の決定（2026-11-16）で、この作業では取込を変えない（→ 2026-11-19 に、詳細画面の上書きを経営層だけにした。下の「利用者の判断を待っていた 1〜3 を直す」の節・Bug #140）

点検の Minor（直していない）:

- 登録の一覧の確定額の空が「—」のまま。何も変えずに「表を閉じる」を見るテストが無い。1 行目の 1 列目が空の `\t9` を貼ると、空のセルは今の値のままの決まりで「0 行に貼り付けました…」と出てセルは変わらない
- 保存の受け口: 親が `lockForUpdate` の前に消されると 500（競合のときだけ）・「衝突が行の決まりより先」を固定するテストが無い・行の個数（0・101）や応答の `costs` の中身を見るテストが無い・`exists` が行ごとに 1 問い合わせ（最大 100）・トレイトを完全修飾名で書いている
- 画面: 行の編集中に新しいボタンが出る（古い編集は Task 3 で消えた）・JS の上限 2147483647 が `Controller::MAX_INT_COLUMN` の写し・`beforeunload` を 1 回だけ登録することのテストが無い・表を閉じたあと一覧の横スクロールのヒントを測り直さない・422 の行の番号の戻しのテストが 1 行だけ・モックの「…を入力中」のピルは無い
- 詳細画面: ホストの `costItems` が追加フォームを消したあと読まれているか未確認・`test_the_detail_list_has_no_add_or_edit_controls` の本体が 2 ファイルで逐語の写し・「未定」のアサートが HTML の形の正規表現に頼る
- テスト: `DB::transaction(` の数を 1 と数える確かめは、正当に 2 つ目を足しても落ちる・ロックが閉包の中にあるかは見ない・表のマークアップの確かめは属性の綴りに頼る

### 本番反映（2026-11-17 実施）

利用者の承認を段ごとにとって流した。`13.x` を `e599025d` から **`f7b7bb50`** へ早送りし（push なし）、`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・依存の変更は無し**（SQL は流していない）。新しいクラス・トレイトがあるので main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3）を流した。ルートが変わるが `route:cache` は `deploy.sh` が作り直す。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | `13.x` と「社内決裁申請」の会話 | `13.x` = origin/13.x = `e599025d` のまま・早送りできる ／ ListAgents に無い ／ 本番へ出るアプリのファイルは 12 本（コントローラ 2・トレイト 1〈新〉・モデル 1・Support 2〈うち新 1〉・ビュー 5〈うち新 2〉・routes/web.php） |
| 2 本番の読み取り（反映の前） | 既存 8 本の md5・新しい 4 本・記録・ビルド・索引・件数 | 既存 8 本とも `13.x`（`e599025d`）と一致・新しい 4 本は本番に無い ／ `laravel.log` は Jun 18 17:41 ／ build は `app-_7h98Lga.css`・`app-NiVQbl_Q.js`（2 か所とも）／ 索引は `re_procurement_costs` に `procurement_id`・`re_project_costs` に `project_id`（`FOR UPDATE` は案件の行に絞られる）／ 原価の行 232 / 182・費用項目 17（停止中 0） |
| 3 反映 | 早送り・読み込みの表・`./deploy.sh` | `13.x` を `e599025d` → `f7b7bb50` ／ `composer dump-autoload` 7,322 クラス（`RealEstateCostGrid`・`ValidatesCostGrid` が表に入った・作業場所のパス 0・`vendor/bin/phpunit` は無い）／ `./deploy.sh` exit 0・6 段すべて。送ったアプリのファイルは 12 本（期待どおり）とビルドと読み込みの表 2 本。CSS が `app-_7h98Lga.css` → `app-Dtchh7WU.css` に変わり、旧 CSS を 2 か所で削除。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 本番の読み取り（反映の後） | md5・構文・ビュー・ルート・クラス・記録・キャッシュ | 12 本とも手元（`f7b7bb50`）と **md5 が一致** ／ 変えた PHP 7 本の `php -l` すべて ok ／ コンパイル済みビュー **320 本 / INVALID 0** ／ ルート 498 本（`costs/batch` が 2 本・PUT。`storeCost`・`updateCost` の store/update は 0 本・destroy は 2 本のまま）／ `RealEstateCostGrid` と `ValidatesCostGrid` が読める・`MESSAGES` 8 ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 11:27 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は `app-Dtchh7WU.css`・`app-NiVQbl_Q.js`（2 か所とも） |
| 5 Chrome（利用者の Chrome・見るだけ・保存も入力もしない） | 仕入れ案件 #26・分譲地 #16 の詳細と 2 つの新規登録を、同じオリジンの iframe で 1440px・375px（計 8 通り） | 「＋ 費用追加」は無く、「見込み額を入力」「確定額を入力」が出る ／ 見込み額の表が開き最初の入力セルに focus（詳細は物件購入費の次の `estimated-1`・新規登録は `estimated-0`）／ 詳細は 17 行（自動 1）・新規登録は 16 行（物件購入費なし）／ 詳細は「見込み額を保存」・新規登録は「表を閉じる」・取消で閉じる ／ `main` の横のはみ出し 0（開く前も開いたあとも）／ #26 で確定額の表も開き、差額の列・16 セル・`table-layout: fixed`・`min-width: 760px`・枠は `overflow-x: auto` ／ CSS は `app-Dtchh7WU.css` ／ コンソールのエラー 0（Google Maps の以前からの警告だけ）／ 作ったタブは閉じた |

⚠ 保存（`PUT …/costs/batch`）・新規登録は本番では送っていない（本番のデータを変える操作のため）。動きはテスト・変異・使い捨ての環境の実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。

## ✅ 周辺ビル調査の取込で、件数と階の崩れたカンマと数字のあいだの空白を断る（Bug #134）— 本番反映済み

実装計画: `~/.claude/plans/peaceful-humming-tome.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #134）

上の「CSV 取込の金額と面積で、カンマの区切りが崩れた値をその行の誤りにする」の範囲外に残した件を、利用者の依頼（2026-11-17）で直した。
作業場所 `.claude/worktrees/nostalgic-jennings-a2186c`・枝 `area-import-comma`（13.x = `37abfa0b` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前（2026-11-17 に使い捨てで実測）

周辺ビル調査の Excel 取込（`/tenant/area-buildings/import`）は、件数と階を、カンマと空白（件数は「円」「¥」も）を全部取り除いてから読んでいた。サーバ（PHP）もプレビュー（JS）も同じ作りなので、打ち間違いが警告なしで別の値で入った。

| セル | 直す前 | 直した後 |
|---|---|---|
| 件数 `1,5`・`,15`・`15,`・`1，5` | 15 | その行の誤り |
| 件数 `5,,0` | 50 | その行の誤り |
| 件数 `1 5`・`1円5`・`1, 234` | 15・15・1234 | その行の誤り |
| 件数 `1,234`・`１，２３４`・`1,234円`・`¥1,234` | 1234 | 1234（今どおり） |
| 階 `2,5`・`2 5` | 25 | その行の誤り |
| 階 `1,0階`・`B1,0` | 10・-10 | その行の誤り |
| 階 `地下 1階`・`B 1`・`1 F`・` 4 F ` | -1・-1・1・4 | 今どおり |

- ⚠ Excel の数のセルは SheetJS が数のまま渡す（`sheet_to_json` の既定）ので、カンマや空白が届くのは文字として打ったセル

### 決めたこと（利用者の決定 2026-11-17・案 A）

- 件数は CSV の取込と同じ決まり（3 桁ごとの正しい区切りだけ通す。全角のカンマは半角とみなしてから確かめる）
- 階・総階数はカンマを通さない（正しい区切りは 1,000 以上で、どれも上限 200 を超える）
- 数字のあいだの空白はその行の誤り。前後の空白と、語のあいだの空白（`地下 1階`）は今どおり
- 案 B（空白は今のまま）・案 C（件数もカンマを一切通さない）は採らなかった
- 数字のあいだの「円」「¥」（`1円5`）も、空白と同じ形なので一緒に断った（計画の承認で含めた）

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| サーバ | `AreaBuildingImportController::parseInt()` — 全角のカンマを半角に・数字とカンマのあいだの空白と「円」「¥」を断る・`CsvImportLimits::withoutThousandsSeparators()` で区切りを確かめる ／ `FloorNumber::parse()` — カンマを落とさない・数字のあいだの空白を断る |
| プレビュー | `import.blade.php` — `areaImportNormalizeNumber()` を `areaImportParseCount()`（`{ state: blank / bad / range / ok }`。PHP の `parseCount()` と同じ結果）に置き換え・`areaImportParseFloor()` に同じ空白の断り |
| テスト | `FloorNumberTest` に 10 行・`AreaBuildingImportTest` に 3 本・新規 `AreaBuildingImportParserParityTest`（2 本。取込の画面が描いた script を node の vm で動かし、件数 43・階 43 の入力を PHP の読み方と突き合わせる）。5141 → **5156 tests green** |
| 記録 | 設計書 2026-08-12 §7.3・`CsvImportLimits` の docblock・RULES の Bug #134 と #132 の注記・CLAUDE.md の件数 |

- ⚠ プレビューの `-1` の警告は「数値でない」から「0〜9999 の範囲外」に変わった（サーバはどちらでも断る）
- ⚠ 最初は階にカンマを断る判定を別に足したが、カンマを落とさなくなった時点で数字の判定が同じ結果を返し、どのテストでも見分けられない（等価）ので外した（`dfdc346d`）

### 検証

- 足したテストは、どれも直す前のコードで赤を確かめてから直した（例: 階 `2,5` が 25・`B1,0` が -10 で入る・プレビューに `areaImportParseCount` が無い）
- 全件 **OK (5156 tests, 53494 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **320 本 / INVALID 0**
- 変異 15 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs.area-import-comma.py`・結果は `results-area-import-comma.*`）すべて期待どおり（落ちたテストの組と理由まで）。JS 側の 8 通りは、突き合わせのテスト（7 通り）と構造のテスト（「数値でない」の警告を外す 1 通り）だけが拾う
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright。文字のセルの xlsx を PhpSpreadsheet で作った）
  - ビル＋調査: プレビューで `1,5`・`1 5` に「operating が数値でない」、`2,5`・`2 5` に「階数が読めない」、`-1` に「0〜9999 の範囲外」。`1,234`・`地上 5階` は警告なしで、送ると 1234・5 で保存
  - テナント明細: `2,5`・`2 5`・`B1,0` に「階が読めない」、`地下 1階` は -1 で保存
  - 1440px・375px とも `main` の横のはみ出し 0・コンソールのエラーと警告 0

### 範囲外（気づいたが直していない）

- 調査年月（`parseMonth()`）の読み方は変えていない
- 件数の前後の「円」「¥」の位置は見ない（`円1`・`1¥` も 1 と読む。直す前と同じ）
- 本番のデータ（取込で崩れた区切りから入った値があるかは、上げたファイルが本番に残らないので確かめられない）
- 最後の点検（独立の担当・読み取りだけ）の指摘で、反映を止めるものは無かった（下の 3 つは実測で確かめて範囲外にした）:
  - プレビューとサーバで空白の扱いが割れる入力が残る（件数・階の `5\u{0085}`〈NEL〉・`5\0`〈NUL〉はサーバが読みプレビューが断る／セルの中ほどの BOM `1\u{FEFF}F` はプレビューが読みサーバが断る。JS の `\s`・`trim()` と PHP の `trim()`・`\s` の範囲の違い）。直す前も同じ割れ方で、Excel の文字のセルに入る文字ではない。突き合わせのテストの入力にも無い
  - 件数の `0,000`・`01,234` は 0・1234 と読む（先頭の 0 を通す。CSV の取込と同じ部品の決まり）
  - 壊れた UTF-8 は `withoutThousandsSeparators()` に null が渡って TypeError になりうるが、行は JSON で届き `json_decode` が壊れた UTF-8 を断る（null → 入力チェック）ので届かない（実測）
  - 階の範囲（-10〜200・0〜200）はプレビューとサーバの値の突き合わせではなく、定数の一致のテスト（`test_limits_match_between_php_and_js`）が守る（今回は変えていない）

### 本番反映（2026-11-17 実施）

利用者の指示で、main repo の `13.x` を `37abfa0b` から **`2a12c3ab`** へ早送りし（push なし）、`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 作業ツリーは空・`13.x` = origin/13.x = `37abfa0b`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番のコード（`f7b7bb50`）から変わるアプリのファイルは 4 本（コントローラ・`CsvImportLimits`〈docblock だけ〉・`FloorNumber`・取込の画面） |
| 2 本番の読み取り（反映の前） | 4 本の md5・記録・ビルド・キャッシュ | 4 本とも `f7b7bb50` と一致＝本番は前回の反映のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-Dtchh7WU.css`・`app-NiVQbl_Q.js`（2 か所とも） |
| 3 反映 | 早送り・`./deploy.sh` | `13.x` を `37abfa0b` → `2a12c3ab` ／ `./deploy.sh` exit 0。送ったアプリのファイルは 4 本だけ。CSS・JS の名前は前と同じ（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 本番の読み取り（反映の後） | md5・構文・ビュー・読み方・記録・キャッシュ | 4 本とも手元（`2a12c3ab`）と **md5 が一致** ／ PHP 3 本の `php -l` ok ／ コンパイル済みビュー **320 本 / INVALID 0** ／ 本番の PHP 8.3 で `FloorNumber::parse()` が `2,5`・`2 5`・`B1,0` → false、`地下 1階` → -1、`1F` → 1 ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 12:23 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |

⚠ 取込（プレビュー・確定）は本番では送っていない（本番のデータを変える操作のため）。動きはテスト・変異・使い捨ての環境の実ブラウザで確かめてある。本番で実際に取り込むのは利用者。

⚠ `origin/13.x` への push はしていない。


## ✅ 原価管理の一覧入力の後回し 2 つ（M4 ログインが切れたときの文・M5 取込とのロックの順）— 本番反映済み

実装計画: `~/.claude/plans/jaunty-jingling-blum.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #135・#136）

上の「原価管理の一覧入力」の節の最後の点検で後回しにした 2 つを、利用者の指示（2026-11-17）で直した。
作業場所 `loving-swirles-29abca`・枝 `cost-grid-followups`（13.x = `e219a5dd` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（反映は `./deploy.sh` だけでよい。CSS も変わらない）。

### 直す前（2026-11-17 に実測）

**M5（Bug #135）** — 使い捨ての MySQL 8.4（scratchpad・ポート 3417。手元の実データの MySQL とは別）で、原価の表の保存と試算表 Excel 取込が出す SQL を 2 本の接続で重ねた:

| 原価の表の外部キー | 取込 | 直す前（取込は親をロックしない） | 直したあと（取込の最初に親を FOR UPDATE） |
|---|---|---|---|
| あり | 上書き | **デッドロック 1213（表の保存が落ちる＝500）** | 順に流れる |
| あり | 追加 | 順に流れる | 順に流れる |
| なし | 上書き・追加 | 待つだけ | 順に流れる |

本番の読み取り（2026-11-17・利用者の承認のうえ・`SHOW CREATE TABLE` だけ）: `re_procurement_costs.procurement_id` → `re_procurements`・`re_project_costs.project_id` → `re_projects` の外部キーが**どちらもある**（`ON DELETE CASCADE`。ほかに費用項目への外部キー）＝**本番でも上書きの取込と表の保存が重なるとデッドロックしうる形だった**。`laravel.log` は Jun 18 17:41 のまま（起きた記録は無い）。

**M4（Bug #136）** — 画面の JS が組んだ要求を、ログインが切れた・無効・権限の無い状態で本当に送り、応答を JS に戻した:

| 場面 | 表の保存 | 取込 | 削除 |
|---|---|---|---|
| ログインが切れた（419） | サーバーエラーが発生しました（419）。何も保存していません。 | 取込に失敗しました: CSRF token mismatch. | CSRF token mismatch. |
| ログインが無い（401・英語） | サーバーエラーが発生しました（401）。… | 取込に失敗しました: Unauthenticated. | Unauthenticated. |
| 無効にされた（401・日本語） | サーバーエラーが発生しました（401）。… | 取込に失敗しました: このアカウントは無効に… | このアカウントは無効に… |
| 権限が無い（403・日本語） | サーバーエラーが発生しました（403）。… | 取込に失敗しました: このページへの… | このページへの… |

### 決めたこと（利用者の承認 2026-11-17・計画のおすすめのまま）

- M4 の範囲は原価のカードの 3 つ（表の保存・試算表取込・削除）。直す場所は画面の JS（サーバーの応答は変えない。決裁の URL だけ日本語にした前例と「基幹の応答は変えない」の決めはそのまま）
- 文: 419 と英語の 401 は「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、保存できませんでした（何も保存していません）。画面を開き直すと表に入れた内容は消えるので、控えてから開き直してください。」（取込は「取り込めませんでした（何も取り込んでいません）。画面を開き直してから、もう一度取り込んでください。」、削除は「削除できませんでした（何も削除していません）。画面を開き直してから、もう一度削除してください。」）。日本語の理由のある 401・403 はその文＋「何も…していません。」。ほかは今の文のまま
- M5 は取込の中身（上書き・追加の動き）を変えず、ロックの順だけをそろえる（「この作業では取込を変えない」の決めは権限の件で、今回も権限は変えていない）

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| M5 | `RealEstateCostImportService` の `importToProcurement()`・`importToProject()` が、トランザクションの最初に親の行を `->whereKey()->lockForUpdate()->first()`（表の保存と同じ形・同じ順） |
| M4 | `_cost_grid_script.blade.php` の先頭に共通の関数 `realEstateCostAccessError(status, body, action)`。表の保存（表は開いたまま）・`_cost_excel_import_script.blade.php` の取込・`procurements/show`・`projects/show` の削除が使う |
| テスト | 新規 `CostImportLockOrderTest`（6 本）・`CostCardAccessErrorTest`（30 本）。`RealEstateScreenTestCase::enforceCsrfToken()`（テストでも CSRF を確かめる写しを差し込み、本物の 419 を出す）。5156 → **5192 tests / 53990 assertions green**（PHP 8.3・ピーク 173.50 MB） |

### 検証

- 足したテストは、どれも直す前のコードで赤を確かめてから直した（M5: 「取込が親の行を読んでいない」「取込が親の行をロックしていない」6 本 ／ M4: 24 本が今の文で落ちた。404 の 6 本は直す前から緑＝今の文のままを固定する対）
- コンパイル済みビュー **320 本 / INVALID 0**
- 変異 19 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs_cost_grid_followups.py`・実行役 `run_cgf.py`・結果は `results-cost-grid-followups.*`）すべて期待どおり（落ちたテストの組と理由まで）。⚠ FOR UPDATE だけを外す変異（L3）は、流れた SQL の順のテストでは見えず（SQLite はロックを書かない）、字句のテストだけが落とす
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`〈`APP_DEBUG=false`〉＋ Playwright。cookie を消してログインを切った）: 仕入れ案件の表の保存・取込・削除で本物の 419 に新しい文（保存は表の上・取込と削除は alert）・表は開いたまま入れた値が残る・一覧の行は変わらない ／ 分譲地の 375px でふつうの保存は今どおり通り、ログイン切れの文は枠の中で折り返す・`main` の横のはみ出し 0 ／ コンソールは 419 の読み込みの失敗の 1 件だけ

### 範囲外（気づいたが直していない）

- 原価のカードの外の Ajax も、419 で英語の文（`CSRF token mismatch.`）を出しうる（419 を見ているのは仕入れ先の簡易登録・建売の詳細のファイルの 2 か所だけ）
- 試算表 Excel 取込の「上書き」を管理者も使える件（権限の食い違い。利用者が決める）（→ 2026-11-19 に経営層だけにした。下の「利用者の判断を待っていた 1〜3 を直す」の節・Bug #140）

### 本番反映（2026-11-17 実施）

利用者の指示で、main repo の `13.x` を `e219a5dd` から **`dce049f9`** へ早送りし（push なし）、`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 作業ツリーは空・`13.x` = origin/13.x = `e219a5dd`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 5 本（取込のサービス・ビュー 4 本。composer・DB・ルート・設定の差なし） |
| 2 本番の読み取り（反映の前） | 5 本の md5・記録・キャッシュ・ビルド | 5 本とも `e219a5dd` と一致＝本番は前回の反映のまま ／ `laravel.log` は Jun 18 17:41 ／ build は `app-Dtchh7WU.css`・`app-NiVQbl_Q.js`（2 か所とも） |
| 3 反映 | 早送り・`./deploy.sh` | `13.x` を `e219a5dd` → `dce049f9` ／ `./deploy.sh` exit 0・6 段すべて。送ったアプリのファイルは 5 本だけ。CSS・JS の名前は前と同じ（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 本番の読み取り（反映の後） | md5・構文・ビュー・記録・キャッシュ | 5 本とも手元（`dce049f9`）と **md5 が一致** ／ サービスの `php -l` ok ／ コンパイル済みビュー **320 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 16:46 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ ログイン画面 200 |
| 5 Chrome（利用者の Chrome・見るだけ・保存も入力もしない） | 仕入れ案件 #26・分譲地 #16 の詳細 | `realEstateCostAccessError` が読め、表の保存・取込・削除の 3 つとも使っている ／ 関数を直に呼ぶと 419 は新しい文・403 はサーバーの理由＋「何も取り込んでいません。」・500 は null ／ `main` の横のはみ出し 0 ／ 読み込み直したときのコンソールのエラー 0 ／ CSS は `app-Dtchh7WU.css` ／ 作ったタブは閉じた |

⚠ 保存・取込・削除は本番では送っていない（本番のデータを変える操作のため）。ログインが切れたときの文は、テスト・変異・使い捨ての環境の実ブラウザ（本物の 419）で確かめてある。

⚠ `origin/13.x` への push はしていない。


## ✅ ログインが切れたとき（419）に日本語の理由を出す（Bug #137）— 本番反映済み

実装計画: `~/.claude/plans/jaunty-enchanting-island.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #137）

上の節（Bug #136）の範囲外に残した「原価のカードの外の Ajax も 419 で英語の文を出しうる」を、利用者の指示（2026-11-17）で確かめて直した。
作業場所 `.claude/worktrees/expired-session`・枝 `expired-session-ja`（13.x = `945b4d8e` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（ビューが 1 本増える。反映は `./deploy.sh` だけでよい。CSS の名前が変わる）。

### 直す前（2026-11-17 に 41 画面を走査して実測）

画面を開いたまま時間がたつか、ほかの画面でログアウトしたあとに保存すると、サーバーは 419（送信の鍵が合わない）を返す。

| 送り方 | 直す前に出たもの | 画面 |
|---|---|---|
| Ajax で、サーバーの文をそのまま出す | 英語の `CSRF token mismatch.`（例「保存に失敗しました: CSRF token mismatch.」） | 約 16（工程表・添付の部品・分譲地の区画・マスタ 5 つの並び替え・ZEAL の店舗とトレーナー・注文住宅・周辺ビルの地図 など） |
| Ajax で、番号だけを出す | 「ステータスの更新に失敗しました（419）」 | 仕入れ案件・分譲地・建売の一覧のステータスの小窓・契約の画面の買主の小窓（登録・重複の確認） |
| Ajax で、何も出さない | 何も出ない（ランクが変わらないだけ。419 に限らず断られても） | 買主一覧のランク変更・買主の「この部署に取り込む」 |
| ふつうのフォームの送信（ログイン画面を含む） | Laravel の英語の画面「419 Page Expired」 | 全部 |

### 決めたこと（利用者の決定 2026-11-17: 範囲と直し方を任せる）

- 419 だけ基幹も日本語にする（`bootstrap/app.php` の 419 の処理から `approvals/*` の条件を外す。文は決裁と同じ）。401・404・413 は今のまま決裁だけ（原価のカードは英語の 401 で「ログインが切れた」を見分けている）
- ふつうのフォームは `resources/views/errors/419.blade.php`（`<x-guest-layout>`。番号は 419 のまま。決裁の画面のフォームにも出る）
- ZEAL 試算表の項目の並び替えは変えない（もう「ページをリロードしてください」と言えている）
- 買主の重複の確認（`checkDuplicate()`）は補助なので今のまま黙る
- 決裁に触れる 2 つ（`bootstrap/app.php` の 419 の処理と `ApprovalJsonExpiredSessionTest` の 1 行）は計画の承認に含めた（「社内決裁申請」の会話は動いていない）

### 直したこと

| コミット | 中身 |
|---|---|
| `a35a6746` test | 本物の 419 を出す部品を `tests/Concerns/EnforcesCsrfToken.php` へ移す（`enforceCsrfToken()`・画面の JS が組んだ要求の鍵を前のセッションのものに差し替える `withExpiredToken()`） |
| `c5e57676` fix | 基幹の JSON の 419 も日本語の文に（`bootstrap/app.php`・決裁のテストの 1 行・新規 `ExpiredSessionResponseTest`・マスタ 4 つの並び替え・ZEAL の店舗） |
| `c8771e4b` fix | ふつうのフォームの 419 を日本語の画面に（見出し「画面の有効期限が切れました」・理由〈何も保存していません〉・次にすること・「前の画面に戻る」・「トップページへ」・「エラー番号 419」） |
| `42b558a9` fix | 番号だけを出していた 5 か所は、419 のときだけ返ってきた JSON の `message` を出す（ほかの番号は今の文のまま。null 返しの形は崩さない） |
| `53d010b8` fix | 買主のランク変更と「この部署に取り込む」は、断られたら 419 は `message`・ほかは「ランクを変更できませんでした（N）」「この部署に取り込めませんでした（N）」 |
| `4bbf8af3` test | 走査 `AjaxFetchSessionGuardTest::test_all_same_origin_saves_ask_for_json`: 自社への GET 以外の fetch・XMLHttpRequest は `Accept: application/json` を送る（送らないと 419 が HTML で返り、画面の JS が日本語の理由を読めない。下限 fetch 30〈実測 39〉・XHR 2〈実測 3〉。見本 11） |
| `a8b223cc` docs | RULES に Bug #137・CLAUDE.md の件数 137 |

### 検証

- 全件 **OK (5213 tests, 54226 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **321 本 / INVALID 0**（`errors/419` の 1 本が増えた）
- 変異 28 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs_expired_session.py`・実行役 `run_es.py`・結果は `results-expired-session.*`〈作業場所で流し、作業場所を消す前に写した。テストの出力の XML は写していない〉。関係するテスト 726 本で流した）すべて期待どおり（落ちたテストの組と理由まで）: `approvals/*` の条件を戻す（14 本が `CSRF token mismatch.` で落ちる）・JSON を求めない送信にも JSON を返す・419 の画面の見出し／2 つのボタン／「何も保存していません」・5 か所と 2 か所をそれぞれ直す前の形に戻す／どの番号でもサーバーの文を出す・走査の分かれ目 7 つ（1 つの fetch から `Accept` を外すと `components/attachment-section.blade.php:229` を名指し）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`〈`APP_DEBUG=false`〉＋ Playwright。cookie を消してから送った）:
  - 仕入れ案件の一覧のステータスの小窓 → alert「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした。画面を開き直して、もう一度やり直してください。」・バッジも DB も変わらない
  - ZEAL の店舗の追加 → 同じ文が画面の帯に出る・応答は `application/json` の 419・店舗は増えない
  - テナントの顧客の登録（ふつうのフォーム）→ 日本語の 419 の画面（`Page Expired` の文字は無い）。「前の画面に戻る」で登録画面に戻り、入れた名前が残っていた（Chromium）
  - ログイン画面から送る（パスワードは入れず、フォームをそのまま送った）→ 同じ画面。「トップページへ」はログインしていなければログイン画面へ
  - 375px・1440px で横のはみ出し 0・コンソールは意図した 419 の読み込みの失敗と、手元に地図の API キーが無いことの警告だけ
- ビルドした CSS は `app-o1yvrcrl.css`（本番の `app-Dtchh7WU.css` から名前が変わる。`./deploy.sh` がビルドする）
- 最後の点検（fable・読み取りだけ）: 「マージしてよい」・Critical・Important 0・Minor 6（下の範囲外に書いた。直していない）

### 範囲外（気づいたが直していない）

- GET の Ajax でログインが切れたときの「データの取得に失敗しました（401）」（約 15 か所。番号だけ）・401 の文（→ GET は 2026-11-17〜18 に・保存〈GET 以外〉の 401 の文は 2026-11-18 に直した。下の節）
- 419 の画面の `<title>` は `config('app.name')`（使い捨ての環境では「Laravel」。本番は本番の設定の名前）。ログイン画面とタブの名前で区別がつかない（点検の Minor）（→ 2026-11-17 に直した。下の「Bug #137 の後回し 4 つ」の節）
- 点検の Minor（後回し）:
  - 買主のランク変更と「この部署に取り込む」（XHR）は `onerror` が無く、通信そのものの失敗（オフライン・接続断）ではまだ何も出ない（→ 2026-11-17 に直した。下の節）
  - 419 の本文を読む処理が `buyers/index` の `xhrFailureMessage()` と `buyers/_form` のインラインの 2 か所にあり、判定の形も fetch の 5 か所（`body && body.message`）と少し違う（今は同じ結果）（→ 2026-11-17 に XHR の 2 か所を 1 つの部品にした。fetch の 5 か所の読み方はそのまま。下の節）
  - 419 の画面の主ボタン「前の画面に戻る」で戻った画面は古い鍵のままで、そのまま送り直すとまた 419 になる（文面は「控えてから画面を開き直して」と案内している）。新しいタブで開いた 419 の画面では押しても何も起きない（→ 前の半分は 2026-11-18 に直した。下の「419 の画面から「前の画面に戻る」で戻った画面を送り直せるようにする」の節。新しいタブの件は範囲外のまま）
  - 走査 `FETCH_ASKS_FOR_JSON` は、fetch の中の JS コメントに書いた `'Accept': 'application/json'` でも通り、`'application/json, text/plain'` は弾く。オプションを変数で渡す `fetch(url, opts)` は GET と数える（実物は 0 件。docblock に死角として書いていない）（→ 2026-11-17 に docblock に書き、見本で固定した。下の節）
  - 直した 7 か所は、419 のほかの 401・403 では番号だけ（サーバーの日本語の理由は出ない。計画の決定どおり）

### 本番反映（2026-11-17 実施）

利用者の指示で、main repo の `13.x` を `945b4d8e` から **`62a06cc9`** へ早送りし（push なし）、`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 作業ツリーは空・`13.x` = origin/13.x = `945b4d8e`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 8 本（`bootstrap/app.php`・ビュー 7 本〈うち新規 `errors/419.blade.php`〉。composer・DB・ルート・設定の差なし） |
| 2 反映 | 早送り・`./deploy.sh` | `13.x` を `945b4d8e` → `62a06cc9` ／ `./deploy.sh` exit 0・6 段すべて。送ったアプリのファイルは 8 本だけ。CSS が `app-Dtchh7WU.css` → `app-o1yvrcrl.css` に変わり、旧 CSS を 2 か所で削除（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 3 外からの確かめ（ssh なし・データは送らない） | ログイン画面・CSS・鍵の無い空の送信 | ログイン画面 200 で `app-o1yvrcrl.css` を読む・本番の CSS は手元のビルドと md5 が一致 ／ ログインへの空の送信（`-d ''`）は **419 の日本語の画面**（見出し「画面の有効期限が切れました」・「前の画面に戻る」・「トップページへ」〈`/index.php` → 未ログインはログイン画面へ 302〉・「エラー番号 419」・`Page Expired` の文字なし）／ JSON を求める空の送信は **419 `application/json` で日本語の文**（「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした。画面を開き直して、もう一度やり直してください。」） |
| 4 本番の読み取り（ssh・反映の後・利用者の承認のうえ） | md5・構文・ビュー・記録・キャッシュ・ビルド | 8 本とも手元（`62a06cc9`）と **md5 が一致** ／ `bootstrap/app.php` の `php -l` ok ／ コンパイル済みビュー **321 本 / INVALID 0** ／ `laravel.log` は Jun 18 17:41 のまま＝反映のあと（空の送信の 419 のあとも）エラー 0 件 ／ `bootstrap/cache` は 19:21 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build はアプリ側・公開側とも `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` だけ |

⚠ 本番の保存（ステータスの変更など）はしていない。ログインが切れたときの Ajax の文は、テスト・変異・使い捨ての環境の実ブラウザで確かめてある。

⚠ `origin/13.x` への push はしていない。


## ✅ Bug #137 の後回し 4 つ（通信の失敗・419 の読み取り・419 のタブ名・走査の死角）— 本番反映済み

設計メモ（短）: @docs/superpowers/specs/2026-11-17-expired-session-followups-design.md
実装計画: @docs/superpowers/plans/2026-11-17-expired-session-followups.md

上の節（Bug #137）の範囲外に残した点検の Minor のうち 4 つを、利用者の指示（2026-11-17・「1 で進めてください」）で直した（docs/RULES.md の Bug #137 の追記）。
作業場所 `.claude/worktrees/expired-session-minors`・枝 `expired-session-minors`（13.x = `4d1313e6` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（ビューが 1 本増える。反映は `./deploy.sh` だけでよい）。

| # | 直す前 | 直したあと |
|---|---|---|
| 1 | 買主一覧のランク変更・登録画面の「この部署に取り込む」（XMLHttpRequest）に `onerror` が無く、通信そのものが失敗すると何も出ない | 「通信エラーが発生しました。画面を開き直して、ランクが変わったか確かめてください。」「通信エラーが発生しました。顧客の一覧で、この部署に取り込まれたか確かめてください。」（サーバーに届いて変わった可能性があるので「変わっていません」とは言わない。原価の表の言い方と同じ） |
| 2 | 419 の本文を読む処理が `buyers/index` と `buyers/_form` に 2 回書いてある | 部品 `resources/views/buyers/_xhr_failure_message.blade.php`（`<script>`・`@once`）の `xhrFailureMessage(xhr, fallback)` を 2 画面が `@include` する（振る舞いは同じ） |
| 3 | 419 の画面のタブ名がアプリ名だけで、ログイン画面と区別がつかない | 「画面の有効期限が切れました - アプリ名」（`components/guest-layout` に任意の `title`。`layouts/app` と同じ形。ログイン画面はアプリ名だけのまま） |
| 4 | 保存の Ajax の走査（`AjaxFetchSessionGuardTest`）の見落としが書かれていない | 死角 3 つ（fetch の中のコメントの Accept も求めているとみなす・`fetch(url, 変数)` は GET とみなす・`xhr.open(変数, …)` は数えない）と厳しすぎる向き 1 つ（`application/json, text/plain` は断る）を docblock に書き、見本で今の動きを固定（実物はどれも 0 件と 2026-11-17 に数えた） |

| 区分 | 実装内容 |
|------|---------|
| 画面 | `buyers/_xhr_failure_message.blade.php`（新規）・`buyers/index.blade.php`・`buyers/_form.blade.php`・`components/guest-layout.blade.php`・`errors/419.blade.php` |
| テストの道具 | `tests/Concerns/DrivesAlpineFetch.php` — 応答 `networkFailure()`（`['network_error' => true]`）を渡すと、偽の XMLHttpRequest が status 0・本文なしで `onerror` を呼ぶ（XMLHttpRequest だけ。今ある応答の形と使い方は変えていない） |
| テスト | `Housing\Screens\CustomerScreensTest` に 3 本（通信の失敗 2・419 の読み取りが部品だけ 1）と、既存の 2 本に部品の script を読ませる・419 で本文が HTML なら番号つきの文 ／ `ExpiredSessionResponseTest` に 419 の画面とログイン画面のタブ名 ／ `AjaxFetchSessionGuardTest` に見本 4 つ。5213 → **5216 tests / 54258 assertions green**（PHP 8.3.36・警告なし） |

### 検証

- 新しいテストは直す前に赤を確かめてから直した（部品の script がちょうど 1 つ描かれていない・419 の読み取りが 2 か所・通信の失敗で alert が出ない・タブ名がアプリ名だけ）。走査の見本 4 つは今の動きの固定なので、効いていることは変異で見た
- 全件 **OK (5216 tests, 54258 assertions)**・警告なし ／ コンパイル済みビュー **322 本 / INVALID 0**（新しい部品の 1 本が増えた）
- 変異 12 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs_expired_session_followups.py`・実行役 `run_esf.py`・結果は `results-expired-session-followups.*`。関係するテスト 655 本で）すべて期待どおり（落ちたテストの組と理由まで）: 2 画面の `onerror` を消す・道具が `onerror` を呼ばない・`_form` を直す前のインラインの読み取りに戻す・2 画面の `@include` を消す・部品が常に fallback を返す・419 の画面から title を外す・レイアウトが title を出さない／無くても「 - 」を付ける・`FETCH_ASKS_FOR_JSON` が型を並べた Accept も通す
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`・Playwright）: 買主一覧でランクの小窓を開き、登録画面で不動産だけにいる買主の「この顧客を住宅事業にも追加」を出したあと、サーバーを止めて押した → 2 画面とも新しい文の alert（ランクは A のまま・小窓は閉じる／登録画面は移らない）。コンソールは意図した接続拒否（`ERR_CONNECTION_REFUSED`）の 1 件ずつだけ。`curl` でログイン画面のタブ名「Laravel」（使い捨ての環境のアプリ名）・ログインへの空の送信は 419 で「画面の有効期限が切れました - Laravel」

### 範囲外（気づいたが直していない）

- fetch の 5 か所（`body && body.message` の形）の 419 の読み方をそろえること
- GET の Ajax でログインが切れたときの「データの取得に失敗しました（401）」（約 15 か所。番号だけ）・401 の文（上の節の範囲外のまま）（→ GET は 2026-11-17〜18 に・保存〈GET 以外〉の 401 の文は 2026-11-18 に直した。下の節）
- 419 の画面の「前の画面に戻る」で戻った画面は古い鍵のまま（文面で案内している。上の節の範囲外のまま）（→ 2026-11-18 に直した。下の「419 の画面から「前の画面に戻る」で戻った画面を送り直せるようにする」の節）
- 走査 `test_the_419_reason_of_an_xhr_is_read_in_one_place` は `xhr.status === 419` の字面だけを見る（変数の名前を変えた書き足しは見えない）
- 最後の点検（fable・読み取りだけ）: 「マージしてよい」・Critical・Important 0・Minor 5。直していない Minor:
  - ランクを押した直後に別の画面へ移ると、Firefox が送信中の XHR を `onerror` で止めれば、偽の通信エラーの alert が出る可能性（未確認。実ブラウザの確認は Chromium だけ。文は「確かめてください」なので害は小さい）
  - 偽の fetch に `networkFailure()` を渡すと、黙って status が undefined の応答になる（道具の守りが無い。docblock は「XMLHttpRequest だけ」）
  - 全ビューを走査する `test_the_419_reason_of_an_xhr_is_read_in_one_place` が、住宅事業の顧客の画面のテストの中にある（置き場所だけ。失敗の文はファイルを名指しする）
  - `guest-layout` の先頭の Blade コメントで、`<!DOCTYPE html>` の前に空行が 1 つ出る（標準モードのまま・害なし）
- 点検が判断を控えた、今回より前からの形: ランクの 200 が JSON でないと何も出ない・「この部署に取り込む」の二度押し止めが無い・ランクの送信中に別のバッジを開くと応答が別のバッジに描かれうる

### 本番反映（2026-11-17 実施）

利用者の指示（「13.x へ早送りして本番へ反映してください」）で、main repo の `13.x` を `4d1313e6` から **`941fa3e6`** へ早送りし（push なし）、`./deploy.sh` を流した。「社内決裁申請」の会話は ListAgents に出ていなかった（動いていない）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 作業ツリーは空・`13.x` = origin/13.x = `4d1313e6`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルはビュー 5 本（新規 `buyers/_xhr_failure_message.blade.php`・`buyers/index`・`buyers/_form`・`components/guest-layout`・`errors/419`。composer・DB・ルート・設定の差なし） |
| 2 反映 | 早送り・`./deploy.sh` | `13.x` を `4d1313e6` → `941fa3e6` ／ `./deploy.sh` exit 0・6 段すべて。送ったアプリのファイルはビュー 5 本だけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`）で旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 3 外からの確かめ（ssh なし・データは送らない） | ログイン画面・鍵の無い空の送信 | ログイン画面 200・タブ名は「ミツワ都市開発管理システム」のまま（本番のアプリ名）・`app-o1yvrcrl.css` を読む ／ ログインへの空の送信（`-d ''`）は 419 で、タブ名が **「画面の有効期限が切れました - ミツワ都市開発管理システム」**・見出しと「エラー番号 419」は今までどおり ／ JSON を求める空の送信は 419 `application/json` で日本語の文のまま |

⚠ ssh での本番の読み取り（md5・コンパイル済みビューの lint・`laravel.log`）は反映のときはしていない（→ 2026-11-17 に利用者の承認のうえ読み取りで確かめた: 送ったビュー 5 本が手元と md5 で一致・コンパイル済みビュー 322 本 / INVALID 0・`laravel.log` は Jun 18 17:41 のまま〈反映のあとのエラー 0 件〉・`bootstrap/cache` は 22:32〜22:33 に作り直し〈`config.php`・`routes-v7.php` 600、名簿 2 つ 700〉・CSS は両側とも `app-o1yvrcrl.css` だけ）。買主のランク変更・「この部署に取り込む」の通信の失敗は、本番では起こしていない（テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。

⚠ `origin/13.x` への push はしていない。→ 2026-11-17 に push 済み（`4c4c3a05` に含まれる）。


## ✅ GET の Ajax でログインが切れたときに理由を出す（Bug #137 の追記）— 本番反映済み

設計メモ（短）: @docs/superpowers/specs/2026-11-17-get-ajax-failure-design.md
実装計画: @docs/superpowers/plans/2026-11-17-get-ajax-failure.md

上の 2 つの節（Bug #137・その後回し 4 つ）の範囲外に残した「GET の Ajax でログインが切れたときの「データの取得に失敗しました（401）」」を、利用者の指示（2026-11-17・案 A＝画面の JS だけで直す）で直した（docs/RULES.md の Bug #137 の追記）。
作業場所 `.claude/worktrees/get-ajax-failure`・枝 `get-ajax-failure`（13.x = `4c4c3a05` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラス（アプリ側）は無し**（ビューが 1 本増える。反映は `./deploy.sh` だけでよい）。

### 直す前（2026-11-17 に `resources/views` を数えた）

GET は送信の鍵を確かめないので、ログインが切れたときは 419 ではなく英語の 401（`Unauthenticated.`）になる。基幹の画面で自社へ GET する Ajax は 24 か所・失敗を扱う分岐は 23（決裁の申請書の 1 か所と工程表の `send()` を除く）。

| 出し方 | 分岐 | 401 のとき画面に出たもの |
|---|---|---|
| alert に番号 | 15（建売・注文住宅の区画と仕入れ案件の情報・賃貸マンションの空き部屋と空き駐車場・仕入れ先の検索・不動産の契約の登録と編集の原価と区画・テナントの契約の登録の空き区画と問合せ） | 「空き区画の取得に失敗しました（401）」・「データの取得に失敗しました（401）」 |
| 欄の下に番号 | 5（顧客の検索） | 「顧客の検索に失敗しました（401）」 |
| throw して画面の欄 | 1（ZEAL 試算表の実績の確認） | 「プレビューの取得に失敗しました（HTTP 401）」 |
| XMLHttpRequest | 2（住所→〒。買主・賃貸マンションの物件） | 「該当する郵便番号が見つかりませんでした」 |

どの失敗の分岐も、テストで一度も通っていなかった。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| 部品（新規） | `resources/views/_partials/_ajax_get_failure.blade.php`（`<script>`・`@once`）— `ajaxGetFailureMessage(status, body, what, fallback)` と `ajaxGetFailure(res, what, fallback)`（本文を JSON で読み、読めなければ本文なしで文を作る） |
| 画面（14 本） | 23 の分岐が部品を通す。呼ぶファイル自身が `@include('_partials._ajax_get_failure')` |
| テスト | 新規 `tests/Feature/{Tenant,Housing,Mansion,RealEstate}/Screens/GetAjaxFailureTest.php`・`tests/Feature/GetAjaxFailureScanTest.php`・`tests/Concerns/ScansSameOriginGetAjax.php`（`AjaxFetchSessionGuardTest` から GET の Ajax を拾う走査を移して共用）・`Zeal/SimulationSyncActualsTest` に 1 本 |
| サーバー | **変更なし**（401 を日本語にするのは決裁の URL だけ＝Bug #137 の決め） |

| 場面 | 直したあと |
|---|---|
| ログインが切れた（英語の 401） | 「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、空き区画を取得できませんでした。入力中の内容があれば控えてから、画面を開き直してください。」 |
| 無効にされた・部署が無い（日本語の理由のある 401・403） | 「顧客を検索できませんでした。この部門のデータへのアクセス権限がありません。」（注文住宅の顧客名はテナントの顧客を検索するので、住宅事業だけの人に今もある場面） |
| 404・500 など | 今の番号つきの文（「データの取得」の 7 か所は「仕入れ案件の原価の取得に失敗しました（500）」のように何を取りに行っていたかで言う） |
| 住所→〒で本文が JSON でない（買主） | 「該当する郵便番号が見つかりませんでした」（直す前は JSON として読めずに止まり、何も出なかった） |

### 範囲外（気づいたが直していない）

- 保存の Ajax が 401 で出す英語の `Unauthenticated.`（ほかの画面でパスワードを変えたときなど、まれ）（→ 2026-11-18 に直した。下の「保存の Ajax でログインが切れた（401）ときに日本語の理由を出す」の節）
- 不動産の契約の登録・編集で分譲地を選ぶと GET が 2 本並ぶので、失敗すると alert が 2 回出る（今も 2 回）
- 決裁の画面（サーバーが日本語の 401 を返す。担当は「社内決裁申請」の会話）・工程表の `send()`
- 初回のパスワード変更を強制されている人（`ForcePasswordChange` は GET を転送するので、Ajax は HTML を受け取り「通信エラー」になる。まれ）
- 無効にされた人の場面で、並んだ 2 本目はログインの無い 401 になる（1 本目でログアウトされる。どちらの文も次にすること〈開き直す〉は同じ）
- 走査の死角（`GetAjaxFailureScanTest` の docblock）: fetch の後ろで最初に現れる `.ok` の分岐をその fetch の分岐とみなす・呼ぶ画面のファイル自身の include だけを見る
- 最後の点検（「マージしてよい」）の後回しの Minor 6 つ: 走査の部品の説明 1 か所が実装と逆（method を変数で渡す fetch）／走査の死角の説明が 3 つ足りない／賃貸マンションの部屋契約で片方だけ断られる場合のテストが無い／「日本語の理由か」を ASCII 以外の文字の有無で決める／顧客の検索の失敗の書き換えが少し遅い／仕入れ先の検索の alert が打つ手を止めるたびに出る（直す前も同じ回数）

### 検証

- 新しいテストは、どれも直す前に赤を確かめてから直した（部品の script が無い。要求の数と本物の 401・403・404 の番号はその時点で合っていた）。走査は 13.x のビュー 14 本に戻して流し、GET の fetch 22 行（分岐 21）と XMLHttpRequest 2 か所を名指しして赤になることを確かめた
- 全件 **OK (5248 tests, 54588 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **323 本 / INVALID 0**（部品の 1 本が増えた）
- 変異 20 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/specs_get_ajax_failure.py`・実行役 `run_gaf.py`・結果は `results-get-ajax-failure.*`）すべて期待どおり（落ちたテストの組と理由まで）: 部品の決まり 6（日本語の理由・403・ASCII の判定・ログインが切れた・本文を読まない・json() の拒否）・部署ごとに 1 か所を部品に通さない 5・住所→〒 3・include を消す 1・走査の分かれ目 5。1 回目は 4 通り（1 画面の呼び出しを消す変異）で、狙ったテストのほかに走査の下限（呼ぶファイル 14 を実数ちょうどにしていた）も「走査が機能していない」で落ちた → 下限に余裕を持たせて（呼ぶファイル 10・分岐 15）流し直し、期待どおり
- 実ブラウザ（使い捨ての環境・Playwright。画面を開いたあとで cookie を消す）: テナントの契約の登録で物件を選ぶ → 空き区画と問合せの 2 つの alert が新しい文 ／ 賃貸マンションの物件の登録で住所→〒 → 新しい文 ／ 不動産の契約の登録で分譲地を選ぶ → 区画と原価の 2 つの alert ／ ログインしたままなら alert は出ない ／ `main` の横のはみ出し 0 ／ コンソールのエラーは意図した 401 だけ
- 本番の読み取り（前回の反映のあと・上の節に書いた）は 2026-11-17 に済んだ

### 本番反映（2026-11-18 実施）

利用者の指示（「get-ajax-failure も 13.x へ早送りして本番へ反映してください」）で反映した。13.x がログイン画面の 419 の直し（下の節・Bug #138）で `4c4c3a05` → `dcc8455f` へ進んでいたので、作業場所で 13.x を取り込んでから（`49106bfa`。rebase はしない。衝突は `docs/RULES.md` と `docs/BACKLOG.md` だけで、どちらも両方の記録を残した＝#137 の行は追記つき・そのあとに #138 の行／節は日付の順）、main repo の `13.x` を `dcc8455f` から **`49106bfa`** へ早送りし（push なし）、`./deploy.sh` を流した。早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 取り込み（作業場所） | `git merge 13.x`・全件テスト・コンパイル済みビュー | 全件 **OK (5251 tests, 54627 assertions)**（PHP 8.3・警告なし。5216 本＋この枝の 32 本＋13.x の 3 本）／ コンパイル済みビュー **323 本 / INVALID 0** |
| 2 手元 | main repo | 作業ツリーは空・`vendor/bin/phpunit` は無い・早送りできる ／ 本番のコード（`56e7b725`）から変わるアプリのファイルはビュー 15 本（新規の部品 `_partials/_ajax_get_failure.blade.php` と 14 画面）だけ |
| 3 反映 | 早送り・`./deploy.sh` | 2026-11-18 8:48 ごろ終了（日本時間）・exit 0・6 段すべて。送ったアプリのファイルはビュー 15 本だけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 外からの確かめ（ssh なし・データは送らない） | ログイン画面・鍵の無い空の送信 | ログイン画面 200・`app-o1yvrcrl.css` を読む ／ ログインへの空の送信は 302 でログイン画面へ（Bug #138 の直しのまま） |

⚠ 直したのは画面の JS だけで、サーバーの応答は変わらない。部品はログインした画面にしか描かれないので、外からは見えない。利用者の Chrome はログインしていない状態だった（ログイン画面へ戻された）ので、こちらからはログインせず、画面での確認はしていない（テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。ssh での本番の読み取り（md5・コンパイル済みビューの lint・`laravel.log`）もしていない（利用者の承認が要る）。（→ 2026-11-19 に確かめた。下の「ここ 4 回の本番反映を、ログインした画面と本番の読み取りで確かめた」の節）

⚠ `origin/13.x` への push はしていない。→ 2026-11-18 に push 済み（913e362f に含まれる）。

---

## ✅ ログイン画面の 419 を行き止まりにしない（Bug #138）— 本番反映済み

実装計画: @docs/superpowers/plans/2026-11-18-login-419-redirect.md

2026-11-18 の深夜、利用者が本番でログインできなくなった（しばらくして直った）。利用者の決定（2026-11-18・案 A）で直した（docs/RULES.md の Bug #138）。
作業場所 `.claude/worktrees/login-419-redirect`・枝 `login-419-redirect`（13.x = `4c4c3a05` から。GET の Ajax の枝 `get-ajax-failure` とは別にし、こちらだけ先に出せるようにした）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（反映は `./deploy.sh` だけでよい）。

### 直す前（2026-11-18 に使い捨ての環境・Playwright で再現）

| 手順 | 結果 |
|---|---|
| ① ログイン画面を開いたまま、セッションが切れる（本番の有効期限は 2 時間。ほかの画面でログアウトしても同じ） | — |
| ② ID とパスワードを入れて送る | 419「画面の有効期限が切れました」（Bug #137 で 11/17 に本番へ出した日本語の画面） |
| ③ 緑のボタン「前の画面に戻る」を押す | ブラウザが古いログイン画面をそのまま出す（鍵は①と同じ古いもの） |
| ④ もう一度送る | また 419（何度やっても同じ） |
| ⑤ 「トップページへ」を押すか、ログイン画面を開き直す | 新しい鍵の画面になり、ログインできる |

- 本番は読むだけで確かめた（ログイン画面を 2 回開いただけ）: 鍵が 2 回とも同じ＝セッションの保存は正常。セッションのクッキーの有効期限は 7200 秒（2 時間）
- Bug #137 の節の範囲外に「419 の画面の「前の画面に戻る」で戻った画面は古い鍵のまま」と書いて後回しにしていた件そのもの。ふつうの画面は文で案内しているが、ログイン画面ではそのまま押し直すので行き止まりになる

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| `bootstrap/app.php` | 419 の render に、ログインの送信（POST `/login`）の分岐を足した。419 の画面を出さず、新しいログイン画面へ戻して赤い帯に理由を出す。ID と「ログイン状態を保持」は残し、パスワードは控えない。文字でない値は控えない（Bug #121）。JSON を求める要求は今の日本語の JSON の 419 が先 |
| `AuthController` | 文の定数 `EXPIRED_PAGE_MESSAGE`「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、ログインできませんでした。もう一度ログインしてください。」 |
| `errors/419.blade.php` | 先頭の注記だけ（ログインの送信には出さない） |
| テスト | `ExpiredSessionResponseTest` の 1 本を書き換え、3 本を足した |

### 範囲外（気づいたが直していない）

- ほかの画面の 419 の「前の画面に戻る」で古い鍵の画面に戻ること（文で案内している・Bug #137 の後回しのまま）（→ 2026-11-18 に直した。下の節）
- ログアウトの送信の 419（「トップページへ」でログイン画面に着く）
- 最初の 419 のきっかけ（2 時間以上開いていた・別の画面でログアウトした・Chrome が前回のタブを開き直した）のどれだったかは確かめていない（本番のアクセスの記録を読むには ssh の承認が要る）

### 検証

- 新しいテストは、直す前のコードで 3 本が狙った理由（419 のまま）で赤を確かめてから直した（JSON の 1 本は分岐の順番を守るためのもので、直す前から緑）
- 全件 **OK (5219 tests, 54297 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **322 本 / INVALID 0**
- 変異 12 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/specs_login_419.py`・実行役 `run_l419.py`・結果は `results-login-419.*`）すべて期待どおり（落ちたテストの組と理由まで）: 分岐を消す・JSON の分岐より前へ動かす・文字でない値の絞りを外す・パスワードも控える・ID を控えない・文を変える・エラーのキーを `login_id` にする・戻り先をトップページにする・ルート名で見る・ログインに限らず戻す・POST の確かめを外す（等価）。1 回目は 3 通りで期待の書き方を誤った（どれも狙ったテストは落ちていた。テストの道具が失敗の文を組む途中で「all() on array」になる・ID を控えないと戻った画面からの往復も落ちる・決裁の 419 のテストも落ちる）→ 表を直して流し直し、期待どおり
- 実ブラウザ（使い捨ての環境・Playwright）: 時間切れのまま送ると、1 回目で新しいログイン画面に戻り、赤い帯に理由・ID は残る・パスワードは空・「ログイン状態を保持」は残る・419 の画面は出ない → パスワードを入れて送ると経営ダッシュボードに着く ／ 375px で帯は枠の中・横のはみ出し 0 ／ コンソールのエラー 0

### 本番反映（2026-11-18 実施）

利用者の指示（「13.x へ早送りして本番へ反映してください」）で、main repo の `13.x` を `4c4c3a05` から **`56e7b725`** へ早送りし（push なし）、`./deploy.sh` を流した（2026-11-18 1:38 ごろ・日本時間）。⚠ 早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 13.x・作業ツリーは空・13.x と origin/13.x はどちらも `4c4c3a05`・`vendor/bin/phpunit` は無い ／ 本番のコード（`941fa3e6`）から変わるアプリのファイルは 3 本（`bootstrap/app.php`・`AuthController.php`・`errors/419.blade.php`）。`941fa3e6..4c4c3a05` は文書だけ |
| 2 反映 | 早送り・`./deploy.sh` | exit 0。送ったアプリのファイルは 3 本だけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`）。3 つのキャッシュとも成功 |
| 3 外からの確かめ（ssh なし・データは送らない） | 鍵の無い空の送信 | ログインへ空の送信は **302 でログイン画面へ**戻り、戻った画面の赤い帯に「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、ログインできませんでした。もう一度ログインしてください。」 ／ JSON を求める空の送信は 419 で日本語の JSON のまま ／ パスワードの変更への空の送信（`_method=PUT`）は 419 で日本語の画面のまま（タブ名「画面の有効期限が切れました - ミツワ都市開発管理システム」） |

⚠ ssh での本番の読み取り（md5・コンパイル済みビューの lint・`laravel.log`）はしていない（利用者の承認が要る）。ログインそのもの（正しいパスワードで送る）は本番では試していない（テストと使い捨ての環境の実ブラウザで確かめてある）。（→ 2026-11-19 に、利用者が本番でログインできた。ssh の読み取りも同じ日に済んだ。下の「ここ 4 回の本番反映を、ログインした画面と本番の読み取りで確かめた」の節）

⚠ `origin/13.x` への push はしていない。→ 2026-11-18 に push 済み（913e362f に含まれる）。

---

## ✅ 419 の画面から「前の画面に戻る」で戻った画面を送り直せるようにする（Bug #137 の追記）— 本番反映済み

設計メモ: @docs/superpowers/specs/2026-11-18-419-back-fresh-token-design.md
実装計画（変異テスト・実ブラウザの記録つき）: @docs/superpowers/plans/2026-11-18-419-back-fresh-token.md

上の 2 つの節（Bug #137・Bug #138）の範囲外に残した「ほかの画面の 419 の「前の画面に戻る」で古い鍵の画面に戻る」を、
利用者の選択（2026-11-18・案 A）で直した（docs/RULES.md の Bug #137 の追記）。
作業場所 `.claude/worktrees/back-fresh-token`・枝 `back-fresh-token`（13.x = `913e362f` から）。
**DB 変更・ルート変更・依存の変更・新しい PHP クラス（アプリ側）は無し**（ビューが 1 本増える。反映は `./deploy.sh` だけでよい）。

### 直す前

画面を開いたまま時間がたってから（またはほかの画面でログアウトしたあとに）送ると、419 の画面が出る。緑のボタン
「前の画面に戻る」（`history.back()`）を押すと、ブラウザは読み直さずに手元の画面を出すので、鍵は古いまま。そのまま送るとまた 419 になり、
開き直すまで抜けられない（ログイン画面で利用者が本番で踏んだ形。ログインの送信だけは Bug #138 で直した）。

### 直したこと（案 A）

| 区分 | 実装内容 |
|------|---------|
| 部品（新規） | `resources/views/_partials/_fresh_csrf_token.blade.php`（`<script>`）。2 つのレイアウト（`layouts/app`・`components/guest-layout`）の head の鍵のメタの直後・`@vite` より前で読む。読み込みのたびに `sessionStorage['freshCsrfTokenAfter419']` を読んで消し、「戻る・進む」で表示したとき（ナビゲーションの種類が `back_forward`）だけメタと hidden の `_token` を差し替える（head で走った時点・`DOMContentLoaded`・`pageshow`）。bfcache から出したとき（`pageshow` の `persisted`）も同じ。関数 `window.rememberFreshCsrfToken()` を持つ |
| 419 の画面 | 新しい鍵を残す（`rememberFreshCsrfToken()`）。ログインしているか（`auth()->check()`。⚠ 鍵のメタを描くレイアウトより前に確かめる＝「ログイン状態を保持」の人はここで自動でログインし直し鍵が変わる）で 2 段落目と 2 つめのボタンを出し分ける |
| テスト | 新規 `FreshCsrfTokenTest`（部品の script を node の vm で動かす 8 本＋構造 1 本）・`ExpiredSessionResponseTest` に 3 本（本物の 419 で、ログインしたまま鍵が変わった／ログインが切れた／「ログイン状態を保持」）・新規トレイト `RemovesFreshCsrfTokenScript` |

419 の画面の 2 段落目:

- ログインしている: 「「前の画面に戻る」で戻ると、そのまま送り直せます。入力した内容が消えていたら、入れ直してください。」（ボタンは「前の画面に戻る」「トップページへ」）
- ログインしていない: 「ログインが切れています。「前の画面に戻る」で戻って入力した内容を控えてから、ログインし直して、もう一度入力してください（戻った画面から送ると、ログイン画面に移ります）。」（ボタンは「前の画面に戻る」「ログイン画面へ」）

戻ったあと送ると: ログインしたまま鍵が変わった（ほかの画面でログインし直した）・「ログイン状態を保持」で自動でログインし直した → そのまま保存される ／
ログインが切れた → 鍵は通り、ログイン画面に移る（ログインするとホームへ。入力は消える）。

### 気づいて直したこと（計画に無かった）

- 決裁の 2 本（`ApprovalUserManagementTest`・`LedgerScreenTest` の `test_the_filter_form_is_reset_whenever_the_page_is_shown`）が、ページ全体の `pageshow` を
  「ちょうど 1 つ」と数えて落ちた（レイアウトの部品が全画面に 1 つ足す）。テストの意図は「絞り込みのフォームを戻すリスナーが 1 つ」なので、
  部品の script を除いてから（ちょうど 1 回描かれていることも確かめて）数える形にした（`RemovesFreshCsrfTokenScript`）。決裁の会話は動いていなかった

### 範囲外（設計メモの範囲外のまま）

- 419 になる前の「戻る」（ほかのタブでログインし直したあとに「戻る」で開いた画面）
- 画面を開いた時に鍵を変数に持つ Ajax（bfcache から出したとき）と、Blade で鍵を直に書く 7 か所の Ajax（保存すると JSON の 419 の日本語の文）
- 新しいタブで開いた 419 の画面の「前の画面に戻る」が何もしない件
- ログインのあとの行き先（今はいつもホーム）・入力の控えをサーバーが持つこと

### 検証

- 新しいテストは、どれも直す前に赤を確かめてから直した（部品が無い・419 の画面の新しい文が無い）
- 全件 **OK (5264 tests, 54768 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **324 本 / INVALID 0**
- 変異 16 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/specs_back_fresh_token.py`・実行役 `run_bft.py`・結果は `results-back-fresh-token.*`）
  すべて期待どおり（落ちたテストの組と理由まで）: 部品の 8 か所（戻る・進むの判定・鍵を消す・head／DOMContentLoaded／pageshow での差し替え・bfcache の枝・try・キー）・
  レイアウト 2 つ・419 の画面 5 つ（鍵を残す script・判定の固定 2 つ・`hasUser()`・判定をメタより後ろに置く形）。1 回目は V2 だけ期待の書き方が狭かった（狙ったテストは落ちていた）→ 直して流し直し
- 実ブラウザ（使い捨ての環境・Playwright の Chromium）: ①ほかのタブでログアウトしてログインし直す → 419（ログインしている文）→「前の画面に戻る」
  → 入力が残り、hidden の鍵 2 つとメタが新しい鍵 → そのまま登録できた（顧客 1 件だけ増えた）②cookie を消す → 419（ログインが切れている文・「ログイン画面へ」）
  → 戻って送るとログイン画面（419 でない）③419 の画面は 375・1440px で横スクロール 0。Chromium は「戻る」で bfcache を使わず HTTP のキャッシュから出した
  （bfcache の道は node のテストだけで見ている）

### 本番反映（2026-11-18 実施）

利用者の指示（「1 で進めて、本番へ反映してください」）で、main repo の `13.x` を `913e362f` から **`916bee81`** へ早送りし（push なし）、`./deploy.sh` を流した。
⚠ 早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた（「社内決裁申請」の会話は無し）。
**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `913e362f`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルはビュー 4 本（新規の部品 `_partials/_fresh_csrf_token.blade.php`・2 つのレイアウト・`errors/419.blade.php`）だけ |
| 2 反映の前（外から） | 本番のログイン画面 | 200・部品（`freshCsrfTokenAfter419`）は 0 件＝本番は前のまま |
| 3 反映 | 早送り・`./deploy.sh` | exit 0・6 段すべて。送ったアプリのファイルはビュー 4 本だけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 反映の後（外から・ssh なし・データは送らない） | ログイン画面・鍵の無い空の送信 | ログイン画面に部品が 1 つ・位置は鍵のメタの直後で Vite の読み込みより前 ／ パスワードの変更への空の送信（`_method=PUT`）は 419 で、ログインしていない側の文（「ログインが切れています。…」）・「ログイン画面へ」（`/login`）・鍵を残す script（`window.rememberFreshCsrfToken()`）・タブ名「画面の有効期限が切れました - ミツワ都市開発管理システム」 ／ ログインへの空の送信は 302 でログイン画面へ（Bug #138 のまま）／ JSON を求める空の送信は 419 で日本語の JSON のまま |

⚠ ログインした画面での確認（ほかのタブでログインし直してから送る → 419 → 戻る → 送る）は本番ではしていない（こちらからはログインしない。テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。
ssh での本番の読み取り（md5・コンパイル済みビューの lint・`laravel.log`）もしていない（利用者の承認が要る）。（→ 2026-11-19 に確かめた。下の「ここ 4 回の本番反映を、ログインした画面と本番の読み取りで確かめた」の節）

⚠ `origin/13.x` への push はしていない。→ 2026-11-18 に push 済み（`ab1734c7` に含まれる）。

## ✅ 鍵の差し替えの部品を読まない HTML の画面を走査で止める（Bug #137 の追記の後回し）— テストと使われていないビューの削除だけ

計画: `~/.claude/plans/quiet-booping-falcon.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #137 の追記）

上の節で足した構造のテスト（部品をレイアウトが読むか）は、2 つのレイアウトを手書きの配列で見ていた（Top trap #13 の列挙リスト方式。新しいレイアウトを足しても検査に入らない）。
利用者の選択（2026-11-18・引き継ぎの候補 2）で、全件の走査に替えた（docs/RULES.md の Bug #137 の追記）。
作業場所 `.claude/worktrees/fresh-token-scan`・枝 `fresh-token-scan`（13.x = `ab1734c7` から）。
**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し。使われていないビューを 1 本消した**（CSS は変わらない）。

### 数えたこと（2026-11-18・読むだけ）

- `<html` を持つビューは 4 本: `layouts/app`・`components/guest-layout`・`components/app-layout`・`approvals/login-guide`
- 鍵のメタ（`<meta name="csrf-token"`）を持つのは上の 3 つのレイアウト（login-guide はログイン案内の印刷用で、フォームも `@csrf` も無い）
- `components/app-layout.blade.php` はどこからも使われていなかった（`app/`・`resources/`・`routes/`・`config/`・`tests/`・`bootstrap/`・`lang/` に参照 0。
  2026-04-27 の `14502e7e` で足されたまま。中身は `@yield('content')` で、`<x-app-layout>` で呼んでも本文が出ない形）。鍵のメタを持つのに部品を読まない

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| テスト | `FreshCsrfTokenTest` の構造のテストを `test_every_html_document_reads_the_partial_right_after_the_token_meta` に替えた。`resources/views` の `*.blade.php` を全部読み、Blade のコメントを落としてから、`<html` を持つビュー（HTML の画面）と鍵のメタを持つビューのそれぞれが、①鍵のメタがちょうど 1 つ・部品の `@include` がちょうど 1 つ・並びが メタ → 部品 → `@vite(` か、②分類表 `DOCUMENTS_WITHOUT_TOKEN`（ビュー => 理由。今は `approvals/login-guide` の 1 本）に載っていて `@csrf`・`csrf_token(`・`csrf-token`・`<form` を 1 つも持たない、のどちらかでないと、ファイルを名指しして落ちる。古い分類・走査の下限（HTML の画面 3 本）・2 つのレイアウトが見つかることも見る |
| ビュー | `resources/views/components/app-layout.blade.php` を消した |

- 新しいレイアウトを足すと、部品を読むか、理由をつけて分類表に載せるまで、この走査が落ちる

### 検証

- 直す前の赤: 走査が `components/app-layout.blade.php` だけを名指しして落ちた（`23e1d807`）→ 消して緑（`bf7887ce`）
- 全件 **OK (5264 tests, 54760 assertions)**（PHP 8.3・警告なし。アサーションが 8 減ったのは、2 つのレイアウトを 1 つずつ見ていた形から走査に替えたため）
- コンパイル済みビュー **323 本 / INVALID 0**（消した 1 本の分減った）
- 変異 9 通り（カナリアを含む・scratchpad の実行役。関係するテスト 4 本で）すべて期待どおり（落ちたテストと理由の文まで）:
  guest-layout から部品を外す（カナリア）・layouts/app の部品を `@vite` の後ろへ・消した app-layout を戻す・login-guide に `@csrf` を足す・
  分類表から login-guide を外す・分類表に実在しないビューを足す・HTML の画面の判定を壊す・コメントを落とさず guest-layout のコメントに部品を書く。
  コメントを落とさないだけの形は緑（今のビューのコメントに部品の文字は無い。情報として流した）
- CSS: ビルドすると名前が本番と同じ `app-o1yvrcrl.css`（消したビューのクラスはほかのビューでも使われていた）
- 画面は変えていないので、実ブラウザの確認はしていない

### 本番の扱い

- アプリで変わるのは、使われていないビューを 1 本消すことだけ。`./deploy.sh` の rsync は本番のファイルを消さない（`--delete` は `public/build/` だけ）ので、
  反映しても本番の `components/app-layout.blade.php` は残る（どこからも呼ばれない。`view:cache` が 1 本余計にコンパイルするだけで害は無い）
- 本番から消すなら、ssh の承認のうえで「md5 が `ab1734c7` の版と一致するときだけ消す」（前例: 2026-10-26 の `PageNumbers.php`）

### 範囲外（気づいたが直していない）

- 上の節の引き継ぎのほかの後回しの指摘（古い Safari の保険・419 の画面のログインの判定が DB を引く・「そのまま送り直せます」が当たらない画面・
  419 のあと別のタブでログインし直してから戻ったとき）
- 走査は字面（`@include` の有無と並び）で見る。部品が実際に動くかは、同じファイルの node で動かすテスト（8 本）が見る

---

## ✅ 保存の Ajax でログインが切れた（401）ときに日本語の理由を出す（Bug #137 の追記）— 本番反映済み

設計メモ（短）: @docs/superpowers/specs/2026-11-18-save-ajax-401-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-18-save-ajax-401.md

上の「GET の Ajax でログインが切れたときに理由を出す」の節の範囲外に残した「保存の Ajax が 401 で出す英語の `Unauthenticated.`」を、利用者の選択（2026-11-18・案 A）で直した（docs/RULES.md の Bug #137 の追記）。
作業場所 `.claude/worktrees/save-ajax-401`・枝 `save-ajax-401`（13.x = `ad6c6f66` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラス（アプリ側）は無し**（定数を 1 つ足した。反映は `./deploy.sh` だけでよい。CSS も変わらない）。

### 直す前（2026-11-18 に `resources/views` を数えた。決裁を除く保存〈GET 以外〉の Ajax 40 か所）

ログイン中に、ほかの画面・端末でパスワードを変えた・管理者が再発行した・利用者が消された、のあとに最初に保存すると、Laravel がその場でログアウトし（`AuthenticateSession`・認証の確かめ）、英語の `Unauthenticated.` で 401 を返す。時間がたっただけのときは先に鍵の確かめで 419（日本語。Bug #137）になるので、401 になるのはこの場面だけ（まれ）。

| 表示 | か所 | 401 のとき画面に出たもの |
|---|---|---|
| サーバーの文をそのまま出す | 26（マスタ 5 つの並び替え・添付の部品・分譲地の区画・工程・注文住宅と建売の詳細・ZEAL の店舗とトレーナー・周辺ビルの地図 など） | 「Unauthenticated.」「保存に失敗しました: Unauthenticated.」など |
| 番号だけ | 8（仕入れ案件・分譲地・建売の一覧のステータスの小窓・契約の画面の買主の小窓〈重複の確認・登録〉・仕入れ先の簡易登録・買主のランク変更と「この部署に取り込む」） | 「ステータスの更新に失敗しました（401）」など（無効にされた人でも番号だけ） |
| もう日本語 | 4（原価のカード `realEstateCostAccessError`） | 「画面を開いてから時間がたったか、…控えてから開き直してください。」 |
| 何も出さない・決まった文 | 2（買主の重複の確認・ZEAL 試算表の項目の並び替え。Bug #137 でこのままと決めた） | — |

### 直したこと（案 A）

| 区分 | 実装内容 |
|------|---------|
| サーバー | `bootstrap/app.php` の `AuthenticationException` の render の条件を「JSON を求める要求で、決裁の URL か GET 以外」に広げた。文は定数 `AuthController::SIGNED_OUT_MESSAGE`「ログインが切れました。ログインし直してから、もう一度やり直してください。」（決裁の URL の今の文と同じ。直書きを定数へ移した） |
| 番号だけの 8 か所 | fetch の 5 か所は `(status === 419 \|\| status === 401) && body && body.message`・仕入れ先の簡易登録は 401 の分岐を足した（419 の独自の文「セッションが切れました。…」は変えない）・XHR の 2 か所は部品 `buyers/_xhr_failure_message` の `xhrFailureMessage` が 401 も読む |
| 原価のカード | 出す文は変えない。`realEstateCostAccessError` は、サーバーの文が `SIGNED_OUT_MESSAGE` と同じなら「ログインが切れた」側（控えてから開き直す案内）、ほかの日本語の 401・403 は理由＋何もしていない（定数は `@json` で画面へ渡す） |
| 注記 | `bootstrap/app.php`・`_partials/_ajax_get_failure`・`ApprovalJsonExpiredSessionTest` の docblock（アサートは変えていない。触る前に `ListAgents` で「社内決裁申請」の会話が動いていないことを確かめた） |
| テスト | 新規 `tests/Feature/SignedOutSaveResponseTest.php`（5 本）・道具 `tests/Concerns/SignsOutElsewhere.php` ／ 追加 マスタの並び替え（`InlineNameMasterTestCase` に 1 本＝4 つのマスタが継承）・ZEAL の店舗 1・原価のカード 6 件・仕入れ案件 2・分譲地 1・建売 1・買主 3・仕入れ先 2 |

| 場面 | 直したあと |
|---|---|
| ほかの画面でパスワードを変えた・利用者が消された・ログインが無い（GET 以外） | サーバーの文をそのまま出す画面と番号だけの 8 か所に「ログインが切れました。ログインし直してから、もう一度やり直してください。」 |
| 無効にされた（日本語の理由のある 401） | 番号だけの 8 か所にも「このアカウントは無効になっています。管理者にお問い合わせください。」 |
| 原価のカード | 今の文のまま（ログインが切れた＝控えてから開き直す案内・無効＝理由＋何もしていない） |
| GET の Ajax・ふつうのフォームの送信・決裁の画面 | 今のまま（GET は英語の 401 で部品が見分ける・フォームはログイン画面へ） |
| 401 でも本文が JSON でない | 番号つきの今の文 |

### 範囲外（気づいたが直していない）

- 番号だけの 8 か所の 403（権限が無い）の日本語の理由（今どおり番号）
- 仕入れ先の簡易登録・建売の詳細のファイルの、419 の独自の文（「セッションが切れました。…」）
- 初回のパスワード変更を強制されている人（`ForcePasswordChange`）・決裁の画面

### 検証

- 新しいテストは、どれも直す前に赤を確かめてから直した（サーバーとマスタ・店舗は `Unauthenticated.`・番号だけの 8 か所は「…（401）」・原価のカードはサーバーを直したあとで 12 件が「ログインが切れました。…何も保存していません。」になることも確かめてから直した）
- 全件 **OK (5289 tests, 55042 assertions)**（PHP 8.3・警告なし。着手前の 5264 本に 25 本）／ コンパイル済みビュー **323 本 / INVALID 0**
- 変異 15 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/specs_save_ajax_401.py`・実行役 `run_sa401.py`・結果は `results-save-ajax-401.*`）すべて期待どおり（落ちたテストの組と理由まで）: 文の定数・render の条件 3（決裁だけに戻す・GET の除外を外す・`expectsJson()` を外す）・原価のカードの見分け・fetch の 5 か所・仕入れ先 2・XHR の部品・道具 2。1 回目は S3（`expectsJson()` を外す）で、落ちるテストの組を 1 本書き漏らした（`ExpiredSessionResponseTest` のログアウト後のフォームの送信。同じ理由で落ちていた）→ 表を直して S3 を流し直し、期待どおり
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright。画面を開いたまま DB のパスワードを変えてから操作）: 仕入れ案件の一覧のステータスの小窓 → alert が「ログインが切れました。…」・バッジと DB は変わらない ／ 用途地域マスターの実際のドラッグ → 同じ文・並びは変わらない ／ 原価のカードの見込み額の保存 → 今の文「…控えてから開き直してください。」・表は開いたまま打った値が残る・DB は変わらない ／ コンソールのエラーは意図した 401 だけ ／ ログインしたままならステータスは変わり alert は出ない

### 本番反映（2026-11-18 実施）

利用者の指示（「13.x へ早送りして本番へ反映してください」）で、main repo の `13.x` を `ad6c6f66` から **`fd1e568b`** へ早送りし（push なし）、`./deploy.sh` を流した。早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた（「社内決裁申請」の会話は無し）。**DB 変更・ルート変更・新しい PHP クラス（アプリ側）・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 作業ツリーは空・13.x と origin/13.x はどちらも `ad6c6f66`・早送りできる・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 10 本（`AuthController.php`・`bootstrap/app.php`・ビュー 8 本）だけ |
| 2 反映 | 早送り・`./deploy.sh` | exit 0・6 段すべて。送ったアプリのファイルは 10 本だけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`）で旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 3 外からの確かめ（ssh なし・データは送らない） | ログイン画面・鍵の無い空の送信・ログインの無い GET | ログイン画面 200 ／ ログインへの空の送信は 302 でログイン画面へ（Bug #138 のまま）／ 基幹の保存（PATCH）の JSON の空の送信は 419 で日本語の文のまま ／ 基幹の GET の JSON（仕入れ先の検索）は 401 で英語の `Unauthenticated.` のまま ／ 決裁の GET の JSON（関連する決裁No の候補）は 401 で「ログインが切れました。…」（定数から） |

⚠ 基幹の GET 以外の 401 そのものは、外からは見られない（鍵の確かめが先に 419 で断る）。ログインした画面での確認と、ssh での本番の読み取り（md5・コンパイル済みビューの lint・`laravel.log`）はしていない（テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。（→ 2026-11-19 に確かめた。下の「ここ 4 回の本番反映を、ログインした画面と本番の読み取りで確かめた」の節）

⚠ `origin/13.x` への push はしていない。

---

## ✅ 保存の Ajax の 401 の後回し 3 つ（Bug #137 の追記）— テストとコメントだけ（本番への反映は不要）

計画: `~/.claude/plans/adaptive-growing-canyon.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #137 の追記）

上の節の最後の点検で後回しにした 3 つを、利用者の選択（2026-11-18）で片付けた。
作業場所 `.claude/worktrees/save-ajax-401-minors`・枝 `save-ajax-401-minors`（13.x = `df4a02be` から）。
**アプリで変わるのは `bootstrap/app.php` のコメントだけ**（動きは変わらない）。DB・ルート・ビュー・依存の変更なし。

| # | 直す前 | 直したこと |
|---|---|---|
| 1 | 番号だけだった 6 か所（一覧のステータスの小窓 3・買主の小窓の登録と重複の確認・仕入れ先の簡易登録）に「本文が JSON でない 419・401」のテストが無い。⚠ しかもテストの道具の偽の fetch の `json()` は本文が文字でもそのまま返す（本物のブラウザは失敗する）ので、テストを書いても画面の `r.json().catch(…)` を消して緑のままだった | 偽の fetch の `json()` を本物に合わせた（本文が文字なら生の本文として JSON で読み、読めなければ SyntaxError で失敗。文字でない本文〈`asFetchResponse()` が読んだ値〉は今までどおり）。`htmlResponse(int $status)` を足し、6 か所に `htmlResponse(419 / 401)` で番号つきの文が出るテストを足した（画面のコードは変えていない） |
| 2 | `bootstrap/app.php` の条件 `! $request->isMethod('GET')` で HEAD も日本語の側を通ることが書かれていない | 使い捨てのテストで実測（HEAD の JSON の 401 は本文が空）し、「HEAD も日本語の側を通るが本文が空なので見え方は同じ・画面の JS も HEAD を送らない」と書いた |
| 3 | `SignedOutSaveResponseTest` の説明が「ログインの無い保存（利用者が消された・**ログアウトした**）」「AuthenticateSession がログインを切り、**認証の確かめが 401 にする**」 | ほかの画面でログアウトしたときは `AuthController::logout()` が鍵を作り直すので、本物のブラウザでは先に 419（テストは鍵の確かめを飛ばすので 401 になるだけ）。パスワードが変わったときは auth が先に通り、AuthenticateSession 自身が AuthenticationException を投げる（Laravel の優先順で auth が先）。ルートは見本（`web`・`auth` だけ）で画面の別名は通らない、と書いた。`SignsOutElsewhere` の説明も同じ誤りを直した |

| 区分 | 実装内容 |
|------|---------|
| テストの道具 | `tests/Concerns/DrivesAlpineFetch.php`（偽の fetch の `json()`・`htmlResponse()`・`driveAlpine()` の説明）／ 新規 `tests/Feature/FakeFetchJsonTest.php`（4 本） |
| テスト | `RealEstate\Screens\{Procurement,Project,Supplier}ScreensTest`・`Housing\Screens\{Property,Customer}ScreensTest` に 1 本ずつ（買主の小窓の手順は `modalSteps()` にまとめた） |
| コメント・説明 | `bootstrap/app.php`・`tests/Feature/SignedOutSaveResponseTest.php`・`tests/Concerns/SignsOutElsewhere.php`（アサートは変えていない） |
| 全件テスト | 5289 → **5298 tests / 55104 assertions green**（PHP 8.3.36・警告なし） |

### 検証

- 道具の確かめは、道具を直す前に 3 本が「`'resolved'` で本文の文字がそのまま返る」で赤を確かめてから直した。画面の 6 か所のテストは守りのテスト（書いた時点で緑）
- 変異 15 通り（カナリアを含む・main repo の `.claude/route-coverage-tools/mutate/specs_save_ajax_401_minors.py`（作業場所で流して写した）・実行役 `run_sa401m.py`・結果は `results-save-ajax-401-minors.*`。関係するテスト 1336 本で）すべて期待どおり（落ちたテストの組と理由の文まで）: カナリア・道具を直す前に戻す（`FakeFetchJsonTest` の 3 本だけが落ち、画面のテストは緑）・JSON でない文字で null を返す・5 か所の `.catch(function() { return null; })` を 1 つずつ外す（外側の catch の文で落ちる）・5 か所の `body && ` を 1 つずつ外す・仕入れ先の `.catch(… data: {} …)` を外す・仕入れ先の `typeof … === 'string' && ` を外す
- 画面（ビュー）は変えていないので、ビューの lint と実ブラウザの確認はしていない

### 範囲外（気づいたが直していない）

- `asFetchResponse()` はサーバーの HTML の応答を本文 null として渡す（本物のブラウザは json() が失敗する。今の 6 か所はどちらでも同じ結果になる）
- 買主の小窓の登録の 422（`res.json().then(…)`）は json() の失敗を受け止めない（422 は Laravel の JSON で返るので通常は起きない）
- 引き継ぎのほかの候補（番号だけの 8 か所の 403 の理由・26 か所の 401 の案内・初回のパスワード変更を強制されている人の Ajax・419 の画面の後回し・本番でログインした画面での確認）（→ 本番でログインした画面での確認は 2026-11-19 に済んだ。下の節）

---

## ✅ ここ 4 回の本番反映を、ログインした画面と本番の読み取りで確かめた（2026-11-19）— 確認だけ（コードの変更なし）

2026-11-17〜18 に本番へ出した 4 回は、ログインした画面と ssh での読み取りを省いたままだった（どの節も「外から確かめた」まで）。利用者の指示（2026-11-19・利用者が Chrome で本番にログインし、ssh の読み取りも承認）で、まとめて確かめた。**フォームは 1 つも送っていない・本番のデータは変えていない。**

| 反映 | 節 |
|---|---|
| `56e7b725` | ログイン画面の 419 を行き止まりにしない（Bug #138） |
| `49106bfa` | GET の Ajax でログインが切れたときに理由を出す |
| `916bee81` | 419 の画面から「前の画面に戻る」で戻った画面を送り直せるようにする |
| `fd1e568b` | 保存の Ajax でログインが切れた（401）ときに日本語の理由を出す |

### 本番の読み取り（ssh・読むだけ）

| 見たこと | 結果 |
|---|---|
| 4 回で送ったファイル 28 本（`941fa3e6..fd1e568b` で変わった `app`・`bootstrap/app.php`・`resources` の全部）の md5 | **28 本とも `fd1e568b` の版と一致**。git で消した `components/app-layout.blade.php` は、予定どおり本番に残っている（`941fa3e6` の版と一致。どこからも呼ばれない） |
| コンパイル済みビューの `php -l` | **324 本 / INVALID 0**（手元の 323 本＋本番に残る app-layout の 1 本） |
| `bootstrap/cache` | 2026-11-18 17:08 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700） |
| build | アプリ側・公開側とも `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` だけ |
| `laravel.log` | ⚠ **6 月 18 日から更新が無かったのが、2026-11-18 01:45 に更新されていた**（下） |

### `laravel.log` の 9 件（2026-11-18 01:05〜01:45 日本時間）

- 記録の時刻は UTC（2026-11-17 16:05〜16:45）。5 分おきに 9 件、どれも `SQLSTATE[HY000] [2002]`（最初は Connection refused、16:20 UTC から Operation timed out）＝ **データベースにつながらなかった**
- 9 件とも、スタックは `Foundation/Console/Kernel`（定期実行のキュー処理 `queue:work`）。`Foundation/Http/Kernel`（画面の要求）は **0 件**
- 2026-11-19 11:55（日本時間）の定期実行のキュー処理は `DONE` で終わっている。そのあとの記録は無い
- 止まった理由は分かっていない。さくら側の一時的な不調の可能性が高い（推測・確かめていない）
- 時刻は、深夜にログインできなくなった件（Bug #138）と重なる。ただし、あのときの 419 は送信の鍵の確かめで断られていて、データベースに問い合わせる前に返っている（画面の要求の記録も 0 件）。データベースの停止が利用者の操作に影響したことは、確かめた範囲では見つからなかった
- ⚠ **これからの反映のあとの確かめは、「`laravel.log` が Jun 18 17:41 のまま」ではなく「Nov 18 01:45 のまま」と比べる**

### ログインした画面（利用者の Chrome・見るだけ・同じ画面で JS を実行）

| 画面 | 見たこと |
|---|---|
| テナント契約の登録 | 鍵を差し替える部品（`freshCsrfTokenAfter419`）がちょうど 1 つ・並びは 鍵のメタ → 部品 → JS の読み込み ／ `rememberFreshCsrfToken`・`ajaxGetFailure`・`ajaxGetFailureMessage` が関数 ／ `ajaxGetFailureMessage()` に値を渡すと、英語の 401 は「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、空き区画を取得できませんでした。入力中の内容があれば控えてから、画面を開き直してください。」・無効の 401 は「空き区画を取得できませんでした。このアカウントは無効になっています。管理者にお問い合わせください。」・部署の無い 403 は「顧客を検索できませんでした。この部門のデータへのアクセス権限がありません。」・500 は番号つきの文 |
| 仕入れ案件の一覧 | 部品が 1 つ ／ ステータスの小窓の script に `(r.status === 419 \|\| r.status === 401) && body && body.message` がある |
| 仕入れ案件の詳細（#26） | `realEstateCostAccessError` が関数 ／ 保存のとき、`SIGNED_OUT_MESSAGE` の 401・英語の 401・419 はどれも今までの文「…控えてから開き直してください。」・無効の 401 は「このアカウントは無効になっています。管理者にお問い合わせください。何も保存していません。」・500 は null（呼ぶ側の今の文） |
| 住宅事業の買主の一覧 | `xhrFailureMessage` が関数 ／ 理由のある 401 は「ログインが切れました。ログインし直してから、もう一度やり直してください。」・本文が HTML の 401 は「ランクを変更できませんでした（401）」 |
| 用途マスター | 部品が 1 つ ／ 行 8 |

- 5 画面とも Alpine が起動・`main` の横のはみ出し 0・読み込み直したあとのコンソールのエラー 0
- 利用者が本番でログインできた（Bug #138 の反映のあとで初めて、正しいパスワードでのログインを本番で確かめたことになる）
- ⚠ 見ていないもの: 419 の画面から実際に「戻る」で戻って送り直すこと・ログインが切れた状態で実際に保存すること（本番のデータを変えるか、ログインを切る操作になるため。テスト・変異・使い捨ての環境の実ブラウザで確かめてある）

---

## ✅ 既存の部品の既知の弱点を直す 2 回目（今の大きな版の中で更新）— 本番反映済み

前回（2026-10-03・上の「既存の部品の既知の弱点を直す」の節）から 1 か月半で、`composer audit` が **14 件（8 部品）**を出した（2026-11-19）。
利用者の指示（2026-11-19）で、前回と同じ形で直した。作業場所 `.claude/worktrees/deps-audit-1119`・枝 `deps-audit-1119`（13.x = `5d7dd3ba` から）。
**`composer.json`・`app/`・`resources/`・`routes/`・DB の変更は無し**（変わったのは `composer.lock` だけ）。

| 部品 | 前 | 後 | 知らせ |
|---|---|---|---|
| `phpoffice/phpspreadsheet` | 5.9.0 | **5.11.0** | 重い 3（XmlScanner をすり抜ける XXE 2・ODS の読み取りで CPU とメモリを使い切る 1）・中 1（`Drawing::setPath` の SSRF） |
| `symfony/http-foundation` | v7.4.20 | v7.4.23 | 重さの表示なし 4（作る URL の Host を利用者が選べる・HEAD が `access_control` をすり抜ける ほか） |
| `symfony/http-kernel`・`routing`・`filesystem` | v7.4.18〜20 | v7.4.23 | 重さの表示なし 1 ずつ |
| `symfony/mime` | v7.4.19 | v7.4.22 | 中 1（メールの見出しへの細工） |
| `laravel/framework` | v12.69.3 | v12.73.0 | 低い 1（Concurrency の出力への細工） |
| `symfony/yaml` | v7.4.20 | v7.4.23 | 重さの表示なし 1（開発用だけ・本番の vendor には入らない） |

- 更新のコマンド（作業場所の cwd）: `composer update phpoffice/phpspreadsheet laravel/framework symfony/filesystem symfony/http-foundation symfony/http-kernel symfony/mime symfony/routing symfony/yaml --with-dependencies`（大きな版は上げない。Laravel 13 は別の機会）
- 依存で **45 部品**が上がり（guzzle 7.16.3・carbon 3.15.1・monolog 3.13.0 など。どれも同じ大きな版の中）、**2 部品が新しく入る**: `phpoffice/wmf` 0.2.1（表計算の部品が WMF の画像を読むため）・`symfony/polyfill-time` v1.42.0。どちらも MIT
- **当たる可能性が高いのは表計算の部品の XXE 2 件**: アップロードされた xlsx をサーバーで読む取込が 2 つある（工程表の取込 `ScheduleImportSheet`〈住宅事業の経営層・管理者〉・決裁の紙の台帳の取込 `PaperLedgerFile`〈決裁の管理者〉）。ODS の DoS は当たらない見込み（工程表は Xlsx だけ・紙の台帳は Xls と Xlsx だけを読む）。SSRF は書き出す側の画像のパスの話で、アプリは使っていない
- 5.10・5.11 で変わったこと（変更履歴を読んだ）: 暗号化されたブックの中の DOCTYPE・実体の宣言を断る ほか。**Xls の読み取りが WMF・EMF の画像を読むようになった**が、2 つの取込はどちらも `setReadDataOnly(true)` なので画像を読まない
- ⚠ 表計算の部品は決裁の紙の台帳の取込（「社内決裁申請」の会話の担当）でも使う。その会話は止まっているので、前回と同じく `composer.lock` を先に入れる形にした（決裁のテストも全件の中で流れて緑）

### 検証

- `composer audit --locked`: **14 件 → 0 件**
- 全件 **OK (5298 tests, 55104 assertions)**（PHP 8.3・警告なし・4 分 34 秒・ピーク 175.50 MB。上げる前と同じ本数）
- コンパイル済みビュー（Blade のコンパイラも上がる）: **323 本 / INVALID 0**
- 画面・アプリのコードは変えていないので、実ブラウザの確認はしていない

### 本番反映の手順（前回と同じ）

**DB 変更なし・新しいアプリのクラスなし**。新しい部品が 2 つ入るので、main repo で入れ直す。

1. 反映の前に本番を読む（承認のうえ）: `laravel/framework`・`phpoffice/phpspreadsheet` の版・`laravel.log`（Nov 18 01:45 のまま）・ログイン画面 200
2. main repo で `13.x` を早送り → `composer install --no-dev` → `composer dump-autoload --no-dev --optimize`（どちらも PHP 8.3 で。⚠ `vendor/bin/phpunit` が無いことを確かめる）
3. `./deploy.sh`（vendor の部品が送られる）
4. 反映の後に本番を読む: 部品の版・コンパイル済みビューの lint・`laravel.log`・ログイン画面。ログインした Chrome で数画面（見るだけ）
- ⚠ 戻すときは、`5d7dd3ba` で main repo の `composer install --no-dev` をし直して `./deploy.sh` する（表の変更が無いのでそのまま戻せる）

### 本番反映（2026-11-19 実施）

利用者の指示（「13.xへ早送りしてpushし、本番へ反映してください」）で、上の手順どおりに流した。ListAgents で 13.x を動かすほかの会話が無いことを確かめた。main repo の `13.x` を `5d7dd3ba` から **`a538f4b6`** へ早送りして push し（`5d7dd3ba..a538f4b6`）、main repo の cwd で `composer install --no-dev` → `composer dump-autoload --no-dev --optimize`（PHP 8.3）→ `./deploy.sh`。**DB 変更なし**（SQL は流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 反映の前（本番の読み取り） | 部品の版・`installed.json`・記録・キャッシュ・ビルド | `laravel/framework` v12.69.3・`phpoffice/phpspreadsheet` 5.9.0・新しい 2 部品は無い・部品 94 ／ `vendor/composer/installed.json` が手元の今の vendor と md5 で一致（＝本番は前回の反映のまま）／ `vendor/bin/phpunit` は無い ／ `laravel.log` は Nov 18 01:45（3333 行）／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` |
| 2 main repo | `composer install --no-dev`・`dump-autoload` | 45 部品を上げ、2 部品を入れた ／ 7,418 クラス・読み込みの表に作業場所のパスは 0 ／ `vendor/bin/phpunit` は無い ／ 作業ツリーは空（`composer.lock` は変わらない） |
| 3 `./deploy.sh` | 2026-11-19 15:15:48〜15:16:02（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは `composer.lock`・`bootstrap/app.php`（前回の「保存の Ajax の 401 の後回し 3 つ」のコメントの直し）と vendor・ビルドだけ。CSS・JS の名前は前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 反映の後（本番の読み取り） | 版・md5・構文・表計算の部品・記録・キャッシュ | `laravel/framework` v12.73.0・`phpoffice/phpspreadsheet` 5.11.0・`phpoffice/wmf` 0.2.1・`symfony/polyfill-time` v1.42.0・`symfony/http-foundation` v7.4.23・`symfony/mime` v7.4.22・guzzle 7.16.3・carbon 3.15.1・部品 96 ／ `installed.json`・`composer.lock`・`bootstrap/app.php`・`autoload_classmap.php` が手元と **md5 で一致**・読み込みの表に作業場所のパスは 0・`vendor/bin/phpunit` は無い ／ `artisan --version` は Laravel Framework 12.73.0 ／ 本番の PHP 8.3 で、表計算の部品で一時の xlsx を書いて `setReadDataOnly(true)` で読み戻すと「工程 2026/11/19」（一時のフォルダは消した）・`ScheduleImportSheet` が読める ／ コンパイル済みビュー **324 本 / INVALID 0**（手元の 323 本＋本番に残る `components/app-layout`）／ `laravel.log` は Nov 18 01:45 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 15:15〜15:16 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は両側とも `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` だけ |
| 5 外からの確かめ（ログインしない・データは送らない） | ログイン画面・鍵の無い空の送信・ログインの無い GET | ログイン画面 200・`app-o1yvrcrl.css` を読む ／ ログインへの空の送信は 302 でログイン画面へ（Bug #138 のまま）／ 鍵の無い JSON の保存は 419 で日本語の文のまま ／ ログインの無い GET の JSON は 401 で英語の `Unauthenticated.` のまま（Bug #137 の決めのまま）／ このあとも `laravel.log` は Nov 18 01:45 のまま |
| 6 ログインした画面（利用者の Chrome・見るだけ・送信しない。最初は拡張機能につながらず、利用者がログインし直してから見た） | 経営ダッシュボード・仕入れ案件 #26 の詳細と、同じオリジンの GET で 12 画面 | 経営ダッシュボード: Alpine が起動・`app-o1yvrcrl.css`・鍵を差し替える部品が 1 つ・`main` の横のはみ出し 0 ／ 仕入れ案件 #26: `x-data` 22 個がすべて起動・`realEstateCostAccessError` が関数・「見込み額を入力」「確定額を入力」が出る・`main` の横のはみ出し 0 ／ テナントダッシュボード・仕入れ案件一覧・建売一覧・**工程表の取込（建売 #9）**・テナント契約の登録・住宅事業の顧客一覧・決裁のホーム・**決裁の紙の台帳の取込**（`approvals/admin/papers`）・テナントと顧客の CSV 取込・周辺ビル調査の取込が 200 で、エラーの画面は 0 ／ 2 画面ともコンソールのエラー 0 ／ このあとも `laravel.log` は Nov 18 01:45 のまま |

⚠ 工程表・紙の台帳の取込（アップロード）は本番では送っていない（表計算の部品は、上の 4 で本番の PHP で読み書きを確かめてある）。

⚠ rsync は vendor に `--delete` を付けないので、上げた部品で消えたファイルは本番の vendor に残る（読み込みの表に載らないので読まれない。前回の更新と同じ）。

---

## ✅ 利用者の判断を待っていた 1〜3 を直す（仕入れ案件の二重契約・原価の上書きの権限・DAD の年度）— 本番反映済み

計画: `~/.claude/plans/adaptive-growing-canyon.md`（リポジトリの外。要点はこの節と docs/RULES.md の Bug #139〜#141）

これまでの節の範囲外に「業務の判断が要る」として残っていた 8 件を 2026-11-19 に並べ、利用者が 1〜3 を「案のとおり」と決めた（docs/RULES.md の Bug #139〜#141）。
作業場所 `.claude/worktrees/pending-decisions`・枝 `pending-decisions`（13.x = `271fbd55` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（反映は `./deploy.sh` だけでよい）。

| # | 直す前 | 決めたこと | 直したこと |
|---|---|---|---|
| 1（Bug #139） | 仕入れ案件の一覧のステータスの小窓（と編集画面）で、契約のある案件を「販売済」から「販売中」に戻せた。戻すと契約の登録画面にまた出て、同じ案件に 2 件目の契約を作れた。契約の登録の入力チェックも `exists` だけで、古い画面から 2 件目が通った | 契約のある案件は「販売済」から戻せないようにする（理由を出す） | 契約のある案件は「販売済」か今のステータスにしかできない（小窓は 422 で理由の alert・編集画面は入力エラー）。契約の登録・編集画面の選択肢は契約の無い案件だけ。入力チェックで 2 件目を断る |
| 2（Bug #140） | 試算表の取込の「既存原価を上書き」（物件購入費以外の保存済みの原価をまとめて消す）を管理者も使えた | 上書きは経営層だけ | 詳細画面は経営層でない人に上書きの選択肢を出さず、注記を 1 行出す。サーバーも 403 で断る（画面は理由と「何も取り込んでいません。」）。新規登録の原価欄は今どおり（画面の中の行を入れ替えるだけ） |
| 3（Bug #141） | DAD の工事案件の一覧の年度が 5 月始まり（CLAUDE.md と `ZealFiscalYear` は 6 月始まり） | 6 月始まりにそろえる（本番の DAD は 0 件） | 今年度と絞り込みを `ZealFiscalYear` から作る。絞り込みは翌年度の初日より前（半開区間）で比べる |

| 区分 | 実装内容 |
|------|---------|
| 1 | `ReProcurement`（`refusesStatus()`・`STATUS_LOCKED_BY_CONTRACT`）・`ProcurementController`（`statusLockedByContract()`。小窓と編集画面の `status` の規則に `bail` つきで足す）・`ReContractController`（選択肢の `whereDoesntHave('contracts')`・`Rule::unique(...)->ignore(今の契約)`）・`realestate/procurements/index`（小窓の JS が 422 の理由も出す）・`realestate/contracts/create`（注記） |
| 2 | `RealEstateCostImportService`（`refusesOverwrite()`・`OVERWRITE_FOR_EXECUTIVES_ONLY`）・`ProcurementController` / `ProjectController` の `bulkImportCosts`・`realestate/_partials/_cost_excel_import`（`$canOverwrite`）と、それを読む 3 画面 |
| 3 | `Dad\ProjectController`（`currentFiscalYear()` を消して `ZealFiscalYear::current()`）・`JapanBusinessDayTest`（5 月の一覧から DAD を外す。複製 8 → 7 か所・下限 9 → 8） |
| テスト | 新規 `tests/Feature/RealEstate/Screens/ProcurementContractLockTest.php`（11 本）・`CostImportOverwriteTest.php`（10 本）・`tests/Feature/Dad/Screens/ListAndPermissionTest.php` に 2 本。5298 → **5321 tests** |

### 検証

- 足したテストは、守りのテスト（直しすぎを止めるもの）を除き、直す前のコードで直すべき理由で赤を確かめてから直した
- 全件 OK（下の数字）・コンパイル済みビュー **323 本 / INVALID 0**
- 変異 21 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/specs_pending_decisions.py`・実行役 `run_pd.py`。結果は `results-pending-decisions.*`。main repo の同じ場所へ写す）: 1 回目に 20 通りが期待どおり（落ちたテストの組と理由まで）。M1i（`ignore` を外す）は狙ったテストのほかに既存の 4 本（`ProcurementStatusTransitionTest`・`ReContractBuyerRankTest`）も同じ理由で落ち、表が狭かっただけ → 表を直して流し直し MATCH
- ⚠ 変異の表を書く途中で、テストの穴を 2 つ見つけて足した（契約があるのに販売中の古いデータを販売済に直せる・契約の編集画面の選択肢に契約のある別の案件を出さない。変異 M1d・M1g が捕まえる）
- 実ブラウザ（使い捨ての環境・Playwright。DB の写しに契約のある案件・管理者・DAD の境目の工事を足した）: 一覧の小窓で契約のある案件を「販売中」にすると理由の alert が出て「販売済」のまま（コンソールのエラーは意図した 422 だけ）・契約の登録画面の選択肢は契約の無い案件だけで注記も新しい文・取込の選択肢は経営層に「追加」「上書き」・管理者に「追加」と注記（375px で枠の中・`main` の横スクロール 0）・DAD の 2025 年度に 5/31 の工事・2026 年度に 6/1 の工事

### 範囲外（気づいたが直していない）

- ほぼ同時に届いた 2 回の契約の登録（入力チェックは順に届けば断る。同時に届く 2 回を止めるにはロックか一意の索引〈DB 変更〉が要る）
- 分譲地の区画の二重契約（区画の選択肢と入力チェックの話。Bug #87 の範囲外のまま）
- 判断待ちの残り 5 件: ④住宅事業の契約のあとで区画を変えても販売済が付いてこない ⑤土地原価の手入力をやめても前の値が残る ⑥注文住宅の顧客名が自由入力で紐づく顧客と食い違う ⑦賃貸マンションの部屋契約に駐車場だけの利用者を入れられる（手で組んだ送信だけ）⑧画面から呼ばれていない `mansion.rooms.updateStatus`（→ ④ は 2026-11-20 に案 B で直した。下の「契約のある建売・注文住宅の土地の紐付けを変えさせない」の節・Bug #142）（→ ⑤ は 2026-11-20 に案 A＋b で直した。下の「土地原価の手動入力をやめたら紐付け先から計算し直す」の節・Bug #143）（→ ⑥ は 2026-11-20 に案 A で直した。下の「注文住宅の編集画面でも買主を選ぶ」の節・Bug #144）
- 本番へ出すときは、承認のうえで「契約があるのに販売済でない仕入れ案件の件数」を読み取りで数える（あれば、契約の登録画面の選択肢から消えることを伝える）（→ 下の「本番反映」で数えた: 0 件）

### 本番反映（2026-11-19 実施）

利用者の指示で、`13.x` を `271fbd55` から **`21f7db50`** へ早送りして push し（`271fbd55..21f7db50`）、`./deploy.sh` を流した。早送りの前に、manage を触るほかの会話が無いことを ListAgents で確かめた。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 反映の前（本番の読み取り） | 変わる 12 本の md5・記録・ビルド・件数（件数だけ） | 12 本とも `271fbd55` と一致＝本番は 13.x のまま ／ `laravel.log` は Nov 18 01:45（3333 行）／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` ／ 契約のある仕入れ案件 6 件・**そのうち販売済でないもの 0 件**・契約が 2 件以上ある案件 0 件 ／ DAD の工事案件 0 件 ／ 管理者 2 人 |
| `./deploy.sh` | 2026-11-19 17:40 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 12 本（コントローラ 4・モデル 1・Support 1・ビュー 6）とビルドだけ。CSS・JS の名前は前と同じで旧バンドルの削除 0 件（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 反映の後（本番の読み取り） | md5・構文・ビュー・本番の PHP での判定・記録・キャッシュ | 12 本とも手元（`21f7db50`）と **md5 が一致**・PHP 6 本の `php -l` ok ／ コンパイル済みビュー **324 本 / INVALID 0** ／ 本番の PHP 8.3 で `refusesStatus()` があり、管理者の上書きは断り・追加は通す・`ZealFiscalYear::current()` は 2026 ／ `laravel.log` は Nov 18 01:45 のまま（画面を開いたあとも）＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 17:40 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は両側とも前と同じ 2 本 |
| ログインした画面（利用者の Chrome・見るだけ・送信しない。利用者は経営層） | 契約の登録・仕入れ案件の一覧（販売済）と詳細・DAD の工事案件の一覧 | 契約の登録画面の仕入れ案件の選択肢は 4 件（販売中で契約の無い案件）・注記は新しい文 ／ 一覧の小窓の script は 422 の理由も出す形・Alpine のコンポーネント 29/29 が起動 ／ 詳細の取込は経営層なので「上書き」の選択肢があり注記なし ／ DAD の年度の先頭は 2026 年度 ／ どの画面も `main` の横のはみ出し 0・読み込み直したときのコンソールのエラー 0 |

⚠ ステータスの変更・契約の登録・取込は本番では送っていない（本番のデータを変える操作のため）。断る動きは、テスト・変異・使い捨ての環境の実ブラウザで確かめてある。管理者の画面は本番では見ていない（ログインしているのは経営層）。

## ✅ 契約のある建売・注文住宅の土地の紐付けを変えさせない（判断待ち ④・Bug #142）— 本番反映済み

設計（短）: @docs/superpowers/specs/2026-11-20-housing-land-lock-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-20-housing-land-lock.md

上の節の範囲外に残した判断待ちの ④ を、利用者の決定（2026-11-20・案 B＝契約のある物件・注文住宅は区画を変えさせない）で直した（docs/RULES.md の Bug #142）。
作業場所 `.claude/worktrees/housing-land-lock`・枝 `housing-land-lock`（13.x = `422dac5f` から）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP の trait が 1 つ**（`App\Models\Concerns\LocksLandAfterContract`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。

### 直す前

契約のあとで区画を変える・お客様の土地に変えると、区画の「販売済」がついてこない（前の区画は販売済のまま・新しい区画は販売中のまま＝同じ区画を 2 回売れる形）。

| 経路 | 直す前 |
|---|---|
| 建売の編集 | 画面は土地をいつも読み取り専用で出すが、サーバーは送られた値を受け付けた（手で組んだ送信なら変えられる）。土地の欄が無い送信は区画・仕入れ案件を空にした |
| 注文住宅の編集 | 契約済み（ステータスが「契約」以降）でも自由に変えられた。区画の販売済はステータスが「契約」をまたぐときしか動かない |
| 契約一覧から開く注文住宅の契約の編集 | 同じく自由に変えられ、区画の販売済をまったく動かさなかった |

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| 判定 | `LocksLandAfterContract::refusesLandChange(array $input)`（新規の trait）。比べるのは `[種別, 種別が分譲地区画のときだけ区画 id, 種別が仕入れ案件のときだけ仕入れ案件 id]`（使わない欄の残りは見ない）。`HsProperty::isLandLockedByContract()` ＝ 契約の行がある／`HsCustomOrder::isLandLockedByContract()` ＝ **保存済み**のステータスが「契約」以降。文言は各モデルの `LAND_LOCKED_BY_CONTRACT` |
| 保存 | 3 つの経路（`PropertyController::update`・`CustomOrderController::update`・`HsContractListController::updateCustomOrder`）の入力チェックを `Validator::make(...)->after(...)->validate()` にし、紐付けが今と違えば `land_source_type` の入力エラー（ほかの誤りと一度に出る）。**今と同じなら通す** |
| 画面 | 注文住宅の編集と契約の編集は、契約済みなら土地を読み取り専用の表示＋hidden（保存済みの値）にし、「変えるときはステータスを『見積り』以前に戻して保存してから」と案内する。建売の画面は今のまま |
| 契約の編集 | 固定のときは種別を必須にしない（種別が空の契約済みの注文住宅も、画面のまま保存できる）・欄が無い送信でも 500 にしない |
| テスト | 新規 `tests/Feature/Housing/Screens/LandLockTest.php`（21 本）。意味が変わった既存 3 本（`ContractScreensTest` の D1・`JsEmbeddingTest` の契約の編集・`HousingContractBuyerRankTest` の登録の土地）を直した。5321 → **5342 tests / 56397 assertions green**（PHP 8.3.36・警告なし。最後の点検の直しのあと） |

- 注文住宅の土地を変えたいときは、ステータスを「見積り」以前に戻して保存する（区画が販売中に戻る）→ 土地を変える → 「契約」に進める（新しい区画が販売済になる）。**同じ保存で戻しながら変えるのは断る**（保存済みのステータスで判定する。同時に許すと区画の連動が新しい区画を販売中にし、前の区画が販売済のまま残る）
- 契約の無い建売・契約前の注文住宅は今どおり（建売の画面はもとから読み取り専用）

### 検証

- 新しいテストは、守りのテスト（そのまま保存は通る・契約の無い建売／契約前の注文住宅は今どおり変えられる・ステータスを戻すだけなら区画が販売中に戻る）を除き、直す前のコードで赤を確かめてから直した
- 全件 **OK (5341 tests, 56334 assertions)**（警告なし）→ 最後の点検の直しのあと **OK (5342 tests, 56397 assertions)** ／ コンパイル済みビュー **323 本 / INVALID 0**
- 変異 23 通り（カナリアを含む・scratchpad の `mutate/specs_housing_land_lock.py`・実行役 `run_hll.py`）: すべて期待どおり。1 回目は M19 の表が狭かった（`AmountLimitTest` の 1 本も同じ欄を読んで落ちる＝検出は正しい）→ 表を直して流し直し MATCH。M18（固定のときも JS の種別に入力を使う）は予定どおり緑＝画面の表示だけの死角（送る値は hidden の保存済みの値）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`・Playwright）: 契約済みの注文住宅の編集と契約の編集で、土地のラジオ・選択欄が無く読み取り専用の表示と案内が出る・送る値は保存済み・ブラウザのフォームの検査が通る・そのまま保存できる（区画 3 のまま販売済のまま）／契約前の注文住宅は今どおり選べる／1440px・375px とも `main` の横スクロール 0・枠からのはみ出し 0／コンソールのエラー・警告 0
- 最後の点検（別の担当・最も強いモデル・読み取りだけ）: 「マージしてよい」・Critical・Important 0・Minor 6。Minor 1（契約の編集で、契約済みかつ種別が空の注文住宅に、手で組んだ送信で仕入れ案件の紐付けを書けた＝種別が空のとき仕入れ案件の枝へ落ちていた）は今回の約束の穴なので Important とみなして直した（`test_the_contract_edit_does_not_write_a_link_for_an_order_without_a_land_type` で赤を確かめてから、種別が空なら紐付けを書かない分岐を足した）

### 範囲外（気づいたが直していない）

- 登録のときに販売済の区画を選べること（区画の選択肢は販売済も出す）・ほぼ同時の 2 回の登録（ロックか一意の索引が要る）
- 建売の削除で区画の販売済が残ること（`PropertyController::destroy` は区画を戻さない＝売れない向き）
- 契約の編集の画面で、種別が空の契約済みの注文住宅は JS が種別を `'procurement'` とみなすので、土地の価格・原価の欄が出る（保存は種別が空のまま。本番は種別が空の注文住宅 0 件の見込み）
- 判断待ちの残り ⑤〜⑧（→ ⑤ は 2026-11-20 に直した。下の「土地原価の手動入力をやめたら紐付け先から計算し直す」の節・Bug #143）
- 最後の点検の Minor（後回し）: ①固定中で「種別はあるのに紐付け先が空」の注文住宅は 2 画面とも保存できない（`required_if` が断る。抜け道は一覧のステータスで戻すこと。2026-10-06 の読み取りでは 0 件・紐付け先が空の保存は D1 で断るので新しくはできない）②建売の文に次にすることが無い（出るのは手組みの送信と古い画面だけ）③テストの見本が分譲地区画に寄っている（仕入れ案件・お客様の土地の固定の画面をそのまま保存する往復・`_form` の種別が空の契約済み・ほかの欄の入力エラーから戻った固定の画面の hidden）④契約の編集で種別が空のとき表示だけ仕入れ案件の扱い（上の範囲外と同じ）⑤固定の判定から保存までロックしない（ほぼ同時の操作の隙間。Bug #139 の範囲外と同じ扱い）
- 本番へ出す前に、承認のうえで「契約済みで種別が空の注文住宅」「契約がある（建売）・契約済み（注文住宅）で種別はあるのに紐付け先が空の行」の件数を読み取りで数える（0 なら上の Minor ①④ は本番で起きない）（→ 2026-11-20 の反映の前に数えた: どれも 0 件。下の「本番反映」）

### 本番反映の手順

**DB 変更は無い**（SQL は流さない）。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・ほかの会話に知らせる・本番の読み取り（変わるファイルの md5・`laravel.log`〈Nov 18 01:45 のまま〉・承認のうえ）
2. main repo の cwd で `13.x` を早送りし、**`composer dump-autoload --no-dev --optimize`**（新しい trait）
3. `./deploy.sh` → 反映後の読み取り（md5・コンパイル済みビューの lint・`laravel.log`）→ ログインした画面（契約済みの注文住宅の編集と契約の編集が読み取り専用・保存はしない）

### 本番反映（2026-11-20 実施）

利用者の指示（「1 で13.xへ早送りして本番へ反映してください」）で、main repo の `13.x` を `422dac5f` から **`c97a35ee`** へ早送りし（push なし）、main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3）を流してから `./deploy.sh` を流した。早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた。13.x は作業のあいだ進んでいなかった（取り込み不要）。**DB 変更・ルート変更・依存の変更は無し**（SQL は流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | 全件テスト・main repo | 枝の先頭（`c97a35ee`）で全件 **OK (5342 tests, 56397 assertions)**（PHP 8.3・警告なし）／ main repo は 13.x で作業ツリーは空・`vendor/bin/phpunit` は無い |
| 2 反映の前（本番の読み取り・件数だけ） | 変わる 8 本の md5・記録・ビルド・件数 | 7 本は `422dac5f` と一致・新しい trait は本番に無い＝本番は 13.x のまま ／ `laravel.log` は Nov 18 01:45（3333 行）／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` ／ 注文住宅 4 件は**4 件とも契約済み**（反映すると 4 件とも土地が読み取り専用になる）・建売 13 件のうち契約のあるもの 5 件 ／ 契約済み（契約あり）で種別が空・種別はあるのに紐付け先が空・紐付けた区画が販売済でない、は建売・注文住宅とも **0 件**（上の Minor ①④ は本番で起きない） |
| 3 反映 | 早送り・読み込みの表・`./deploy.sh` | `13.x` を `422dac5f` → `c97a35ee` ／ `composer dump-autoload` 7,419 クラス・`LocksLandAfterContract` が表に 1 行・作業場所のパスは 0 ／ `./deploy.sh` exit 0・6 段すべて。送ったアプリのファイルは 8 本（コントローラ 3・trait 1〈新〉・モデル 2・ビュー 2）と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じ。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 反映の後（本番の読み取り） | md5・構文・ビュー・判定・記録・キャッシュ | 10 本とも手元（`c97a35ee`）と **md5 が一致** ／ PHP 6 本の `php -l` ok ／ コンパイル済みビュー **324 本 / INVALID 0** ／ 本番の PHP 8.3 で、注文住宅 4/4 件・建売 5/13 件が固定・今と同じ紐付けで断られるものは 0 件・固定の注文住宅に「お客様の土地」を送ると 2 件が断られる（残り 2 件は今もお客様の土地＝同じなので通る）（保存はしていない）／ `laravel.log` は Nov 18 01:45 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 09:57 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は両側とも前と同じ 2 本 |
| 5 ログインした画面（利用者の Chrome・見るだけ・保存しない） | 注文住宅 4 件の編集と契約の編集（計 8 画面） | 8 画面とも 200・土地のラジオと選択欄は 0・保存済みの種別と区画を hidden で送る（分譲地区画 2 件・お客様の土地 2 件）・読み取り専用の案内が出る ／ CO-001 の編集画面と契約の編集画面を開いて、Alpine のコンポーネントがすべて起動・`main` の横のはみ出し 0・コンソールのエラー 0・CSS は `app-o1yvrcrl.css` ／ 注文住宅の編集画面はブラウザのフォームの検査が通る ／ ⚠ 契約の編集画面（CO-001）は買主の選択欄（`customer_id`）が空で検査が通らない＝今回より前から（2026-10-06 の確認で、このデータに買主が紐づいていない可能性が高いと記録済み。今回の差分は買主の欄に触れていない） |

⚠ 土地を変える送信（断られることの確認）は本番では送っていない（本番のデータを変えうる操作のため。断る動きはテスト・変異・使い捨ての環境の実ブラウザと、上の 4 の判定で確かめてある）。

⚠ `origin/13.x` への push はしていない。

## ✅ 土地原価の手動入力をやめたら紐付け先から計算し直す（判断待ち ⑤・Bug #143）— 本番反映済み

設計（短）: @docs/superpowers/specs/2026-11-20-housing-land-cost-auto-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-20-housing-land-cost-auto.md

上の節の範囲外に残した判断待ちの ⑤ を、利用者の決定（2026-11-20・案 A＝手入力をやめたらサーバーが計算し直す／すでにある行は案 b＝「今の計算値にする」を押したときだけ合わせる）で直した（docs/RULES.md の Bug #143）。
作業場所 `.claude/worktrees/housing-land-cost`・枝 `housing-land-cost`（13.x = `a7c40dab` から）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスが 1 つ**（`App\Support\HousingLandCost`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。

### 直す前

土地原価は保存した時点の値を持ち、手動かどうかは `is_land_cost_manual`。手動 OFF の保存で:

| 画面 | 直す前 |
|---|---|
| 建売・注文住宅の登録と編集 | 欄の値（読み取り専用）をそのまま送り、保存した。画面の JS は区画・仕入れ案件を選んだときだけ自動の値を入れ、チェックを外しても取り直さない＝前に手で入れた値が残る。「チェックを入れて打つ → 外す」でも打った値が保存された |
| 契約一覧から開く契約の編集（建売・注文住宅） | 土地原価を送らず、サーバーも書かない＝保存済みの値が残る。その値を「（区画名）から自動参照」と出していた |

本番（2026-11-20 の読み取り・件数だけ）: 手動 OFF の建売 5 件のうち 3 件で、保存されている値が今の計算値と違う（3 件とも契約前）。手動 ON は建売 8・注文住宅 2。

### 決めたこと（利用者の決定 2026-11-20）

- 手動 OFF の保存では、送られた値を使わずサーバーが決める（4 つの保存の経路すべて。手動 ON は今どおり送られた値）
  - 新規登録／保存済みが手動 ON（＝手入力をやめた）／紐付け先が保存済みと違う／「今の計算値にする」を押した → 紐付け先から計算（計算できなければ空）
  - それ以外（チェックに触らず保存）→ 保存済みの値のまま（「古い契約の原価が勝手に動かないように」）
- すでにある行で保存値と今の計算値が違うときは、編集画面に「保存されている値は、今の紐付け先から計算した値（Y円）と違います。」とチェック「今の計算値（Y円）にする」を出す。押して保存したときだけ合わせる
- 本番のデータは書き換えない

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Support | 新規 `App\Support\HousingLandCost`（`forLink()` 紐付け先から計算・`decide()` 決まり・`whenAutomatic()` 保存済みを `getOriginal()` で読んで決める） |
| Model | `ReProject::housingLotLandCost()`（区画の一覧と同じ按分を 1 つの式に）・`LocksLandAfterContract::landLinkDiffers()`（紐付け先の比べ方を契約の有無と切り離す） |
| Controller | `Housing\PropertyController`・`CustomOrderController`（登録・編集）・`HsContractListController`（契約の編集 2 つ）が `whenAutomatic()` を使う。入力チェックに `refresh_land_cost`（保存しない欄）。仕入れ案件の情報の API に `land_cost` |
| 画面 | 新規の部品 `housing/_land_cost_functions`（JS の決まり `housingLandCostView()`）・`housing/_land_cost_field`（登録・編集の欄）・`housing/contracts/_land_cost_ref`（契約の編集の表示）。建売の契約の編集の x-data は名前つきの関数 `buildingContractCostForm()` に |
| テスト | 新規 `HousingLandCostTest`（8 本）・`LandCostAutoTest`（27 本）・`LandCostViewParityTest`（画面とサーバの決まりを 96 の場面で突き合わせる）。`ContractScreensTest`・`AmountLimitTest` は新しい関数名でフォームを組む |

- 土地原価の手動 OFF の入力欄は送らない（`:disabled`）。表示は読み取り専用の別の欄で、今の決まりで保存される値を出す
- 紐付け先の比べ方は `LocksLandAfterContract` の `landLink()`（種別と、その種別で使う id だけ。先頭の 0 を落とす）。画面の JS も同じ比べ方（`housingLandLinkChanged()`）
- 「保存済みが手動 ON か」は DB の値で決める（入力エラーで戻った画面でも、手入力をやめたことを忘れない）。「今の計算値にする」は `old()` で戻す
- 注文住宅の契約の編集で紐付け先を変えたとき（契約前の注文住宅を URL で開いた場合だけ）は、新しい紐付け先の金額が分からないので金額を出さず「保存すると、新しい紐付け先から計算します。」

### 検証

- 足したテストは、守りのテスト（直しすぎを止めるもの）を除き、直す前のコードで狙った理由で赤を確かめてから直した
- 全件 **OK (5378 tests, 58250 assertions)**（PHP 8.3・警告なし。着手前の 5342 本に 36 本。最後の点検の直しのあと）／ コンパイル済みビュー **326 本 / INVALID 0**（新しい部品 3 本の分増えた）
- 変異 23 通り（カナリアを含む・scratchpad の `mutate/specs_housing_land_cost.py`・実行役 `run_hlc.py`。関係するテスト 362 本で）すべて期待どおり（落ちたテストの組と理由まで）: `decide()` の 4 つの条件を 1 つずつ外す・`whenAutomatic()` が保存前の属性を読む 2 つ・`forLink()` の「原価の行が無ければ計算できない」を外す・仕入れ案件の情報の API から `land_cost` を外す・区画の一覧だけ按分がずれる・4 つの保存の経路の手動 OFF の枝を外す・`refresh_land_cost` を読まない・JS の `willCompute` の 4 つの条件を 1 つずつ外す・`refreshOffered` の「保存値と違う」を外す・`unavailable` の「紐付け先を選んでいる」を外す・注文住宅の契約の編集で紐付け先を変えても前の計算値を出す・保存済みが手動 ON かを `old()` から作る。1 回目は J6 の表が狭かった（検出は正しい。注文住宅の契約の編集のテストでも落ちた）→ 表を直して流し直し、期待どおり
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`・Playwright・作業場所でビルドした CSS は本番と同じ名前 `app-o1yvrcrl.css`）: 建売の編集で手動入力を外す → 計算値 6,000,000 に替わり保存も 6,000,000 ／ 保存値が古い建売に「今の計算値（9,000,000円）にする」→ 押して保存で 9,000,000 ／ 販売価格の欠けた分譲地の区画で外す → 「計算できません」の文 ／ 建売の契約の編集で「3,000,000円（…3号地から取得した値）」と「今の計算値にする」→ 5,000,000 ／ 注文住宅の契約の編集でチェックに触らず保存 → 5,000,000 のまま ／ 注文住宅の編集で外す → 5,000,000 ／ 4 画面とも 1440px・375px で `main` の横スクロール 0・コンソールのエラーと警告 0
- ⚠ 実ブラウザで見つけて直したもの: 375px の契約の編集で「今の計算値（Y円）にする」が flex の項目ごとに縦に割れた（「今の / 計算 / 値（」）→ 文を 1 つの span にまとめ、構造のテスト `test_the_refresh_label_reads_as_one_run_of_text` を足した（直す前に赤）
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ）: 判定「マージしてよい」・Critical・Important 0・Minor 6。利用者に届く影響で格付けし直して 2 つを直した（どちらも直す前に赤のテストを書き、実ブラウザで確かめた）: ①紐付けの無い行で手動入力を外すと、欄は空なのに「保存すると、紐付け先から計算したこの値になります。」と出ていた（保存すると前の手入力の値が消える）→ 値があるときだけ出す（`test_an_unlinked_row_does_not_claim_a_computed_value`）②新しい部品の文（琥珀色の「このまま保存すると空になります」など）が Alpine の起動まで一瞬見えた。契約の編集の画面は `x-show` の要素を `style="display: none;"` で隠す流儀で、置き換えた部分だけそれが抜けていた → 表示の決まりで出し分ける要素を最初は隠す（`test_the_land_cost_messages_start_hidden`）
- 後回しにした点検の Minor: 「今の計算値にする」を付けたまま計算できなくなると空で保存される（手で組んだ送信か、開いてから保存までに区画の販売価格が消えたときだけ。設計は「計算できなければ空」）／仕入れ案件の情報の取得に失敗すると画面だけ「計算できません」と出る・続けて 2 件選ぶと遅れた応答の値が残りうる（今までの自動入力と同じ形）／突き合わせのテストは値の決まりだけで、紐付けの比べ方（`housingLandLinkChanged()` と `landLink()`）は突き合わせていない（今は同じと読んで確かめた）／仕入れ案件の情報の API が同じ案件をもう一度引く

### 範囲外（気づいたが直していない）

- 分譲地・仕入れ案件の原価が変わったときに、手動 OFF の行の土地原価を自動で追いかけること（目標でない。編集画面のチェックで合わせる）
- 本番の 3 件（手動 OFF で保存値が今の計算値と違う建売）の値をこちらで書き換えること（利用者が編集画面でチェックを押したときだけ合わせる）
- 一覧・詳細・ダッシュボードなど、保存された土地原価を読むだけの画面（表示は保存値のまま）
- 判断待ちの残り ⑥〜⑧（→ ⑥ は 2026-11-20 に案 A で直した。下の「注文住宅の編集画面でも買主を選ぶ」の節・Bug #144）

### 本番反映の手順

**DB 変更は無い**（SQL は流さない）。新しいクラスがあるので、main repo の cwd で `13.x` を早送り → `composer dump-autoload --no-dev --optimize` → `./deploy.sh` → 本番の読み取り（変えたファイルの md5・コンパイル済みビューの lint・`laravel.log` が Nov 18 01:45 のまま）→ ログインした画面で 4 画面を見るだけ（保存しない。本番の 3 件は書き換えない）。CSS の名前は変わらない見込み（作業場所のビルドが本番と同じ `app-o1yvrcrl.css`）。

### 本番反映（2026-11-20 実施）

利用者の指示（「1で13.xへ早送りして本番へ反映してください」）で、main repo の `13.x` を `a7c40dab` から **`002dc6d8`** へ早送りし（push なし）、main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3）を流してから `./deploy.sh` を流した。早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた。13.x は作業のあいだ進んでいなかった（取り込み不要）。**DB 変更・ルート変更・依存の変更は無し**（SQL は流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | 全件テスト・main repo | 枝の先頭（`002dc6d8`）で全件 **OK (5378 tests, 58250 assertions)**（PHP 8.3・警告なし）／ main repo は 13.x で作業ツリーは空・`vendor/bin/phpunit` は無い |
| 2 反映の前（本番の読み取り） | 変わる 10 本の md5・新しい 4 本・記録・ビルド | 10 本とも `a7c40dab` と一致・新しい 4 本（`HousingLandCost`・部品 3 本）は本番に無い＝本番は 13.x のまま ／ `laravel.log` は Nov 18 01:45 ／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` |
| 3 反映 | 早送り・読み込みの表・`./deploy.sh` | `13.x` を `a7c40dab` → `002dc6d8` ／ `composer dump-autoload` 7,420 クラス・`HousingLandCost` が表に入った・作業場所のパスは 0 ／ `./deploy.sh` は 12:04 ごろ（日本時間）に exit 0・6 段すべて。送ったアプリのファイルは 14 本（コントローラ 3・モデル 1・trait 1・新しいクラス 1・lang 1・ビュー 7〈うち新規 3〉）と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じで旧バンドルの削除 0 件。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 反映の後（本番の読み取り） | md5・構文・ビュー・決まり・記録・キャッシュ | 16 本とも手元（`002dc6d8`）と **md5 が一致** ／ PHP 7 本の `php -l` ok ／ コンパイル済みビュー **327 本 / INVALID 0**（手元の 326 本＋本番に残る `components/app-layout`）／ 本番の PHP 8.3 で `HousingLandCost` が読め、`decide()` はチェックに触らない保存で保存値（5,000,000）・手入力をやめた保存で計算値（9,600,000）／ `laravel.log` は Nov 18 01:45 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 12:04 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は両側とも前と同じ 2 本 |
| 5 ログインした画面（利用者の Chrome・見るだけ・保存しない） | 建売の編集・契約の編集 10 画面・注文住宅の編集 | 手動 OFF の建売（物件 17）の編集画面は保存値 12,465,894 円を出し、「保存されている値は、今の紐付け先から計算した値（12,734,911円）と違います。」と「今の計算値（12,734,911円）にする」を出す（押していない）／ 契約の編集 10 画面がどれも 200・手動 OFF の表示は「（…から取得した値）」で「から自動参照」は 0 件 ／ 手動 ON の契約（建売の契約 6・注文住宅の契約 1）と注文住宅 CO-001 の編集は、手入力の値のまま案内もチェックも出ない ／ `main` の横のはみ出し 0・コンソールのエラー 0 |

⚠ 保存は本番では送っていない（本番のデータを変える操作のため）。手入力をやめたときと「今の計算値にする」を押したときの保存は、テスト・変異・使い捨ての環境の実ブラウザで確かめてある。本番の 3 件（手動 OFF で保存値が今の計算値と違う建売）は書き換えていない（利用者が編集画面でチェックを押して保存したときだけ合わせる）。

⚠ `origin/13.x` への push はしていない。

## ✅ 注文住宅の編集画面でも買主を選ぶ（判断待ち ⑥・Bug #144）— 本番反映済み

設計（短）: @docs/superpowers/specs/2026-11-20-custom-order-edit-buyer-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-20-custom-order-edit-buyer.md

上の 2 つの節の範囲外に残した判断待ちの ⑥ を、利用者の決定（2026-11-20・案 A＝登録画面と同じ買主の選択にする）で直した（docs/RULES.md の Bug #144）。
作業場所 `.claude/worktrees/custom-order-buyer`・枝 `custom-order-buyer`（13.x = `7ea02bb0` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前

注文住宅の**編集画面だけ**、顧客名が自由入力で、テナントの顧客（`/api/tenant/customers/search`。住宅事業の買主とは別の表）を検索していた（住宅事業だけの人は 403）。保存は `customer_id` を受け取らない（`exclude`）ので、顧客名だけ変わって紐付く買主と食い違った。登録画面・契約一覧から開く契約の編集画面は、もう買主を選ぶ形だった。

本番（2026-11-20 の読み取り・件数だけ・名前は出していない）: 注文住宅 4 件（すべて契約以降）・**買主なし 1 件**（契約以降）・紐付いた 3 件は買主の行が無い 0・削除済み 0・住宅事業にいない 0・**顧客名の食い違い 0**・住宅事業の買主 9 人。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| 画面 | `housing/custom-orders/_form.blade.php` — 顧客情報を登録・編集とも `housing/contracts/_buyer-select` に（自由入力・テナントの検索・テナントの顧客の登録へのリンクと、JS の `searchCustomer` 一式を外した）。`edit.blade.php` は `buyers` を渡す |
| 保存 | `Housing\CustomOrderController` — 編集の保存も買主を必須にし、顧客名は買主の名前で置き換える（`store()` と同じ）。選択肢は `selectableBuyers()`（住宅事業の買主＋今の買主〈削除済み・住宅事業にいなくても〉）の 1 か所で、画面と**編集の保存の入力チェック（`Rule::in`）が同じ集合**を使う |
| ランク | 契約済みで買主を変えると、今ある `HsCustomOrder` の saved フックで新しい買主が成約（前の買主は成約のまま。コードは足していない） |
| テスト | 新規 `tests/Feature/Housing/Screens/CustomOrderEditBuyerTest.php`（11 メソッド・13 本。契約の編集の 2 メソッド・3 本は最後の点検の直し）。土台 `HousingScreenTestCase` に `customOrderEditForm()`（`composedForm` で買主の部品ごと組む）と、`customOrder()` の既定の買主。既存の 5 ファイルの編集画面の組み方を部品ごとに・`JsEmbeddingTest` の顧客名のテストを買主の名前で・`GetAjaxFailureTest` の顧客の検索の 2 本を外した。住宅事業の上限の走査は DAD と同じく `Rule::in` / `Rule::exists` も上限ありとして読む |

- **自動のセキュリティ点検の指摘**（2026-11-20）: 編集の保存が、手で書き換えたどの買主の番号でも受け付けた（ほかの部署・削除済みの買主を紐付けられ、契約済みならその買主が住宅事業に足されて連絡先まで見える）。利用者の了承（「その方針で」）で、編集の保存だけを選択肢の集合に絞った（`00a0a234`）。最後の点検（別の担当）の指摘で、同じ注文住宅を契約一覧から開く契約の編集の保存（`HsContractListController::updateCustomOrder`）にも同じ道が残っていたので、同じく画面の選択肢（`selectableCustomOrderBuyers()`。画面と入力チェックで共用）に絞った
- ⚠ **登録の保存は今のまま広く受け付ける** — 前の設計（買主ランクの自動成約 §7.1 #11）で、不動産だけの買主・削除済みの買主を登録で受け付けることを `HousingContractBuyerRankTest` の 3 本が固定している（変異 M11）。同じ点検が登録の保存についても指摘した。絞るかは利用者の判断（下の範囲外）
- ⚠ 実ブラウザでは買主の欄の `required` で、選ばずに押すとブラウザが「リスト内の項目を選択してください。」で止める（登録画面と同じ）。「顧客は必須です。」の赤い枠が出るのは手で組んだ送信だけ

### 検証

- 新しいテストは、直す前のコードで狙った理由（買主の選択が無い・選択肢に無い買主が通る）で赤を確かめてから直した
- 全件 **OK (5386 tests, 59232 assertions)**（PHP 8.3・警告なし）／ コンパイル済みビュー **326 本 / INVALID 0** → 最後の点検の直しのあと **OK (5389 tests, 59423 assertions)**
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ）: 「マージしてよい」・Critical 0・Important 1・Minor 5。Important（契約一覧から開く注文住宅の契約の編集の保存が `exists:buyers,id` のままで、同じ案件に選択肢に無い買主を紐付けられた）は、直す前に赤のテスト（選択肢に無い買主を断る 2 本）を書いてから直した。今の買主が削除済みでも契約の編集から保存できる守りのテストも足した。Minor は後回し（本番の 1 件と同じ「契約済み＋買主なし」のテストが無い・選択肢に出ないことを `value="番号"` の字面で見ている・買主の選択肢の組み立てが同じ形で 4 か所・「顧客名（自動）」は保存済みの名前を出し保存で買主の今の名前になる・`customOrder()` の既定の買主で買主なしの案件を通るテストが 1 本だけ）
- 変異 15 通り（カナリアを含む。scratchpad の `mutate/specs_custom_order_buyer.py`・実行役 `run_cob.py`・結果 `results-custom-order-buyer.*`）: 14 通りが流す前に書いた期待どおり（等価は M3・M7）。M9（`edit.blade.php` から `'buyers' => $buyers` を消す）は緑だった — Blade の `@include` は親の画面の変数を引き継ぐので等価。本当に壊れる形 M9b（`edit()` の `compact` から `buyers` を外す）を足し、編集画面のテスト 27 本が落ちて期待どおり
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve`・Playwright）: 買主のいる案件で今の買主が選ばれ、不動産だけの買主は選択肢に出ず、買主を替えて保存すると紐付けと顧客名が替わり新しい買主が成約 ／ 買主のない契約済みの案件は未選択で開き、選ばずに押すとブラウザが止めて備考が残り、選ぶと保存できる ／「新規顧客を登録」で作った買主が選ばれて保存できる ／ 375px・1440px で `main` の横のはみ出し 0・コンソールのエラーと警告 0

### 範囲外（気づいたが直していない）

- **注文住宅の登録の保存と、建売の契約の登録・編集 2 つの保存は、選択肢に無い買主も受け付ける**（`CustomOrderController::store`・`Housing\ContractController` の登録と更新・`HsContractListController::updateBuilding`。前の設計 §7.1 #11 のテストが固定するのは登録の 2 経路〈建売の契約の登録・注文住宅の登録〉だけで、建売の契約の編集 2 つは固定されていない。自動のセキュリティ点検の指摘。絞るかは利用者の判断）
- 本番の買主のない 1 件は書き換えていない（次に編集画面で保存するとき買主を選ぶ。選ぶとその買主が住宅事業で成約）
- 判断待ちの残り ⑦・⑧

### 本番反映（2026-11-20 実施）

利用者の指示（「1で13.xへ早送りして本番へ反映してください」）で、main repo の `13.x` を `7ea02bb0` から **`f2a757c5`** へ早送りし（push なし）、`./deploy.sh` を流した。早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いことを確かめた（「社内決裁申請」の会話は無し）。13.x は作業のあいだ進んでいなかった（取り込み不要）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | 全件テスト・main repo | 枝の先頭（`55af385b` のコード）で全件 **OK (5389 tests, 59423 assertions)**（PHP 8.3・警告なし）／ main repo は 13.x で作業ツリーは空・13.x と origin/13.x はどちらも `7ea02bb0`・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 5 本（コントローラ 2・ビュー 3） |
| 2 反映の前（本番の読み取り） | 5 本の md5・記録・ビルド・キャッシュ | 5 本とも `7ea02bb0` と一致＝本番は 13.x のまま ／ `laravel.log` は Nov 18 01:45 ／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` |
| 3 反映 | 早送り・`./deploy.sh` | `13.x` を `7ea02bb0` → `f2a757c5` ／ `./deploy.sh` は 16:21 ごろ（日本時間）に exit 0・6 段すべて。送ったアプリのファイルは 5 本とビルドだけ。CSS・JS の名前は前と同じ（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 反映の後（本番の読み取り） | md5・構文・ビュー・記録・キャッシュ | 5 本とも手元（`f2a757c5`）と **md5 が一致** ／ コントローラ 2 本の `php -l` ok ／ コンパイル済みビュー **327 本 / INVALID 0** ／ `laravel.log` は Nov 18 01:45 のまま（画面を開いたあとも）＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 16:21 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は両側とも前と同じ 2 本 ／ ログイン画面 200 |
| 5 ログインした画面（利用者の Chrome・見るだけ・保存しない） | 注文住宅 4 件の編集画面と契約の編集画面（計 8 画面） | 8 画面とも 200・買主の選択欄（選択肢 10＝「選択してください」＋住宅事業の買主 9 人）が出る・テナントの顧客の検索は 0 件 ／ 買主のいる 3 件は今の買主が選ばれたまま・**買主なしの CO-001 は未選択で開く**（選択欄は必須。選ばずに送るとブラウザが止める）／ CO-001 の編集画面を開いて、Alpine のコンポーネント 21/21 が起動・土地は読み取り専用（案内あり）・`main` の横のはみ出し 0・読み込み直したときのコンソールのメッセージ 0 |

⚠ 保存は本番では送っていない（本番のデータを変える操作のため）。買主を選んで保存する動きは、テスト・変異・使い捨ての環境の実ブラウザで確かめてある。買主なしの CO-001 は、次に編集画面か契約の編集画面で保存するとき買主を選ぶ（選ぶとその買主が住宅事業で成約になる）。

⚠ `origin/13.x` への push はしていない。

## ✅ 土地（旧「仲介土地」）の区画登録と、分譲地への変更 — 本番反映済み

設計書: @docs/superpowers/specs/2026-11-20-realestate-land-lot-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-20-realestate-land-lot.md

利用者の依頼（2026-11-20）。不動産管理の仕入れ案件のうち物件種別「仲介土地」は区画を登録できず、広さなどを確認できなかった。あわせて、1 つの土地を 2 区画以上に分けて別々の買主に売ることがあるので、当初は土地で登録していても分譲地に変えられるようにする。表記は「仲介土地」→「土地」。
作業場所 `.claude/worktrees/land-lot`・枝 `land-lot`（13.x = `c557ef26` から。実装は `5ebc03ee` から `01a5b15a` まで）。**新しい表が 1 つ（DB 変更あり）。新しい PHP クラスがある**（本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。**ルートが 4 本増える**。2026-11-20 に本番反映済み（下の「本番反映」）。

### 直す前

| 場面 | 直す前 |
|---|---|
| 物件種別「仲介土地」の仕入れ案件 | 区画を登録できない（区画を持てるのは分譲地だけ）。一覧の「区画」ボタンも分譲地の行にしか出ない |
| 1 つの土地を 2 区画以上に分けて売る | 土地のままでは登録できず、分譲地を作り直して原価・工程・添付を手で移すしかない |
| 名前 | 「仲介土地」でも取引種別が「自社買取」のものがあり、名前と中身が食い違っていた |

### 決めたこと

利用者の決定（2026-11-20）: 表記は「土地」・土地は基本 1 区画・2 区画以上に分けるときは分譲地に変える・区画が 1 つでも建売・注文住宅になることはある・契約または建売・注文住宅の紐付けがある土地は分譲地に変えなくてよい（断る）・作り方は「土地の区画用の小さな表を新しく作る」（B 案）。

計画で決めた 8 項目（設計書から変える・書き足したこと）:

| # | 決めたこと |
|---|---|
| 1 | 変えたあとの文は「分譲地「…」に変えました（元の仕入れ案件番号 … は分譲地の詳細に残しています）。」（設計書の「備考に残しました」は専用の列にした決定と食い違うので） |
| 2 | 変えた分譲地のステータスは区画の状態で集約する（区画が「成約」なら「販売済」。分譲地の区画の登録と同じ決まり） |
| 3 | 区画の保存でも、販売価格と面積から求めた坪単価が列（符号付き INT）に入るかを見る（Bug #91 と同じ文）。分譲地に変えるときに坪単価を写すので、ここで断らないと変える操作が 500 になる。確かめは基底 `Controller` へ移して共用 |
| 4 | 仕入れ案件を消すとき、区画もコードで消す（本番は外部キーの CASCADE だが、テスト用スキーマは外部キーを張らないため＝Bug #81 の形） |
| 5 | 「土地でない」「2 つ目」「区画が無いのに更新・削除」は入力エラーでなく帯の理由で詳細へ戻す（古い画面か手で組んだ送信だけで起きる） |
| 6 | 変える途中のデータベースのエラーは、何も変えずに詳細へ戻し、帯に理由を出して `report()` で記録する（Bug #89 と同じ形） |
| 7 | 「分譲地に変える」の二度押しの 2 回目は、404 でなく一覧へ戻して理由を出す（仕入れ案件がもう無いため） |
| 8 | 一般担当は区画の欄を見るだけ（登録・編集・分譲地に変えるは経営層・管理者、削除は経営層） |

設計書の「私の判断」（利用者の承認に含む）: 現地調査 → 検討に写す・区画がある土地の物件種別は変えさせない・区画のステータスの初期値は今ある紐付けから決める（契約・建売の契約・「契約」以降の注文住宅があれば「成約」、なければ「販売中」）・区画の削除は紐付けがあっても断らない・元の番号は専用の列に残し使い回さない・原価の行はすべてそのまま写す・仕入れ先の詳細と削除の歯止めに分譲地を足す。

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| 表記 | `RealEstatePropertyType::BrokerageLand` の表記を「土地」に（値 `brokerage_land` は変えない。契約種別「仕入れ土地販売」・取引種別「仲介」の表記も変えない）。登録・編集の注記・コメントも |
| 区画の表とモデル | 新しい表 `re_procurement_lots`（`procurement_id` が一意＝1 案件 1 行・外部キー `ON DELETE CASCADE`）とモデル `ReProcurementLot`。坪数・坪単価は保存せず `AreaConverter`・`TsuboPrice` で毎回出す。`re_projects.source_procurement_code`（varchar(20)・一意）を足した。SQL は `database/sql/2026-11-20-create-re-procurement-lots.sql`（2 文）。テスト用スキーマにも同じ形 |
| 区画の欄 | 仕入れ案件の詳細に区画の欄（登録・編集・削除。ふつうのフォームの送信）。`ProcurementLotController`。最初の区画は土地面積と想定販売価格（土地）を入れた状態で開く。2 つ目は断って「分譲地に変える」へ案内。区画がある土地の物件種別を変える保存は断る |
| 状態の連動 | 分譲地の区画と同じ動き。不動産の契約の登録・削除・付け替え、建売の契約の登録・削除、注文住宅が「契約」をまたぐとき・契約以降で消すときに、土地の区画を成約／販売中にする（`ReProcurementLot::markSoldFor()`・`markOnSaleFor()`） |
| 画面への表示 | 仕入れ案件の一覧に土地の「区画」ボタンと「区画 成約数 / 区画数」・不動産の契約の登録・編集・詳細に区画（号地・面積・坪数・販売価格）・住宅事業の詳細に土地元の区画の面積と坪数 |
| 分譲地に変える | サービス `ProcurementToProjectConverter`。変えられる条件（土地・自社買取・契約・建売・注文住宅の紐付けが無い）の判定 `refusal()` を画面のボタンとサーバが共用（Bug #41）。1 つのトランザクションで、基本情報・価格・原価（すべての行）・区画・工程・添付を新しい分譲地へ移し、元の仕入れ案件を消す。案件番号は分譲地の番号（`RE-PRJ-…`）に変わり、元の番号は `source_procurement_code` に残して分譲地の詳細に「元の仕入れ案件番号」として出す。仕入れ案件の番号の振り方は、分譲地に残した元の番号も含めて最大値＋1（使い回さない）。戻せない。紐付けがあるときはボタンを押せず理由を出す（包む span の `title` と本文＝Top trap #12） |
| 仕入れ先 | 仕入れ先の詳細に「関連する分譲地」を出し、削除の歯止めで分譲地も数える（「この仕入れ先は仕入れ案件・分譲地で使用されているため削除できません。」）。⚠ 分譲地だけで使われている仕入れ先は、これから削除できなくなる |

### 検証

- 全件 **OK (5476 tests, 60164 assertions)**（PHP 8.3・警告なし・最後の点検の直しのあと）／ コンパイル済みビュー **327 本 / INVALID 0**
- 変異 35 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/specs_land_lot.py`・実行役 `run_ll.py`・結果は `results-land-lot.json` / `.txt`）: 34 通りが期待どおり（M31 は期待の理由の書き違いを直して流し直し MATCH）。**穴が 1 つ（M30）**: 分譲地の詳細の「元の仕入れ案件番号」の行を消しても全部緑だった（成功の帯の文に同じ語が入っているため＝Bug #43 の型）→ 開き直した詳細で見出しと値を 1 本の正規表現で見るテストを足し（`818d8bcb`）、M30 を流し直してそのテストだけが落ちることを確かめた
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright）9 項目すべて OK: 区画の登録（号地 1・面積 132.69・販売価格 25,000,000・販売中 → `132.69㎡（40.13坪）`）・編集と入力エラー・一覧の区画ボタンと表記「土地」・契約の登録画面の区画の行・契約のある土地の押せないボタン（理由が出る）・分譲地に変える（確認の文・変えたあとの帯・元の番号の行・区画と原価が移る）・管理者と一般担当の権限・仕入れ先の「関連する分譲地」・1440px・375px で `main` の横スクロール 0・コンソールのエラー 0（Google Maps の警告だけ）
- 実ブラウザで見つけた欠陥 2 つを直した（`800ac904`）: ①分譲地の詳細の元の番号の行だけ上の線が二重になっていた（2px）→ ほかの行と同じ下線にそろえた ②区画の入力エラーの戻り先が画面の先頭で、区画の欄に出た理由が見えなかった → 戻り先を詳細の区画の欄（`#lot`）にした（Bug #64 の形）。どちらも直す前に赤のテストを足した
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ）: 「直してからマージ」・Critical 0・Important 1・Minor 6。直したもの（`bbecfcc9`。直す前に赤のテストを書いた）:
  - Important: 区画の欄（`#lot`）へ戻すと、上の帯が画面の外になり、とくに「分譲地に変える」の断りと途中の失敗が見えなかった → 区画の欄の見出しの下に結果を出す（専用のフラッシュ `lot_notice` / `lot_error`。上の帯には出さない）。区画の欄が無い画面（土地でない案件）と、画面の先頭に着く場合（変えたあと・二度押しの 2 回目）は今の帯のまま。実ブラウザで 1440px・375px とも画面の中に出ることを測った
  - 建蔽率・容積率を分譲地へ写すとき小数が落ちていた（59.5 → 59）→ 生の値を写す
  - 区画の登録・編集と「分譲地に変える」のフォームに二度押し止め（`submitOnce()`。二度押しで POST 1 回を実ブラウザで確かめた）
  - 説明の文の「土地（自社買取）の区画」を直した（区画は取引種別を問わない）・途中の失敗のテストを、工程・添付を付け替えたあとで失敗させる形に・SQL の 2 文目で止まったときの注記
  - 範囲を絞った点検で 6 件すべて直ったことを確かめた。新しく出た Minor 2 件は残した（下の範囲外）

### 範囲外（設計書 §8 と、台帳の後回しのうち利用者に関わるもの）

- 建売・注文住宅が契約になっても、土地の仕入れ案件のステータスは販売中のまま。そのため、建売で売れた土地にも不動産の契約（仕入れ土地販売）を登録できてしまう（今もそう）
- 仕入れ案件を消しても添付の行が残る（今もそう）
- 分譲地に変えるのと、ほぼ同時に契約や建売を保存すると、両方通りうる（ロックか一意の索引が要る。順に届けば断る）
- 1 つの土地に不動産の契約と建売の契約の両方が付いた場合、片方を消すと区画は販売中に戻る（分譲地と同じ素朴な戻し方）
- 契約一覧から開く注文住宅の契約の編集は、分譲地の区画と同じく土地の区画の状態を動かさない
- 後回しの小さな指摘: 区画の登録がトランザクションの外（ほぼ同時の 2 回は一意制約で 500。順に届けば二度押し止めと「1 つまで」が止める）・坪単価の式が 4 か所・契約の種別を手で書き換える送信では、旧い土地の区画が成約のまま残る・詳細画面の問い合わせが数本増えた（土地でない案件でも区画の初期の状態と変えられるかを求める）
- 最後の点検の直しのあとに残した Minor: 区画が無い土地でない案件へ、古い画面から区画の更新・削除を送ると、理由がどこにも出ない（区画があるあいだは物件種別を変えられないので、まれ・何も変わらない）・結果の欄が区画の欄の中にあるかのテストは、欄の始まりより後ろかだけを見る・テストの説明 3 か所に「自社買取」の言い方が残る・区画の削除のフォームに二度押し止めが無い（2 回目は「区画は登録されていません」になるだけ）

### 本番反映の手順

**DB が先・`./deploy.sh` が後**（新しいコードが `re_procurement_lots` と `re_projects.source_procurement_code` を読む）。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・ほかの会話に知らせる
2. 本番の読み取り（利用者の承認のうえ・`ssh … /bin/sh < 手順ファイル` で `artisan tinker --execute`・php は 8.3 を名指し）: `SHOW CREATE TABLE` の `re_procurements`・`re_projects`・`re_project_lots`（SQL の型〈`re_procurements.id` が BIGINT UNSIGNED・`procurement_code` / `project_code` の長さ・`re_project_lots.status` の型〉と合うか）・`re_procurements` を指す外部キー（`information_schema.KEY_COLUMN_USAGE` の `REFERENCED_TABLE_NAME = 're_procurements'`。分譲地に変えるときに元の案件を消すため）・`re_projects` に `source_procurement_code` の列も同じ名前の索引も無いこと・件数だけ（物件種別×取引種別ごとの仕入れ案件・土地のうち紐付けのあるもの）・`laravel.log` が Nov 18 01:45 のまま。型が違えば SQL を直す
3. `database/sql/2026-11-20-create-re-procurement-lots.sql`（2 文）を流す（`tinker` から `DB::statement()` を 1 文ずつ）→ 流したあとの `SHOW CREATE TABLE` で確かめる
4. main repo の cwd で `13.x` を `land-lot` へ早送りし、**`composer dump-autoload --no-dev --optimize`**（新しいクラス `ReProcurementLot`・`ProcurementLotController`・`ProcurementToProjectConverter` ほか。worktree から流さない）
5. `./deploy.sh`（ルートが 4 本増えるので `route:cache` が作り直される）
6. 反映の後の読み取り（変えたファイルの md5・コンパイル済みビューの lint・ルート・`laravel.log`・`tinker` で `ReProcurement::nextProcurementCode()` が返ること〈仕入れ案件の新規登録の採番が `source_procurement_code` を読むので、SQL が入っていないと登録が 500 になる〉）→ ログインした画面で土地の仕入れ案件の詳細・一覧・契約の登録画面を見るだけ（登録・変換は押さない）

### 本番反映（2026-11-20 実施）

利用者の承認を段ごとにとって、上の手順どおりに流した（本番の読み取り・SQL・早送りと読み込みの表・`./deploy.sh`・ログインした画面のそれぞれで承認を取った）。`13.x` は作業のあいだ `c557ef26` のまま進んでいなかった（取り込み不要）。main repo の `13.x` を **`90845f5c`** へ早送りした（push なし）。manage を触るほかの会話は無かった（`ListAgents`）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 本番の読み取り（反映の前） | 変わるファイルの md5・新しいファイル・記録・型・外部キー・件数 | 変わる 29 本とも `c557ef26` と一致・新しい 6 本は本番に無い＝本番は 13.x のまま ／ `laravel.log` は Nov 18 01:45（3333 行）／ `re_procurements.id` は bigint unsigned・`procurement_code`・`project_code` は varchar(20)・`re_project_lots.status` は varchar(30)・InnoDB・utf8mb4_unicode_ci ＝ SQL と合う（SQL は直していない）／ `re_procurement_lots` も `source_procurement_code` もその索引も無い ／ `re_procurements` を指す外部キーは 4 本（建売・注文住宅・不動産の契約は SET NULL＝分譲地に変える処理が紐付けとして断る・原価は CASCADE＝変える処理がコードで消す）／ 仕入れ案件は土地（自社買取）19 件（紐付けあり 1）・マンション1棟 2・中古戸建 4・中古マンション 6（仲介の取引種別は 0）・分譲地 17 件・最大 RE-PRC-031／RE-PRJ-020 |
| 2 SQL（DB が先） | 2 文を `tinker` から 1 文ずつ（先頭で表か列があれば止まる。SQL は base64 で埋め込んだ） | **OK 1・OK 2** ／ 流したあとの `SHOW CREATE TABLE` は SQL のとおり（`re_procurement_lots` の列・一意の索引・外部キー CASCADE ／ `re_projects.source_procurement_code` varchar(20) NULL・`project_code` の後ろ・一意の索引 `uq_re_projects_source_procurement_code`）／ 区画 0 行・分譲地 17 行のまま |
| 3 早送りと読み込みの表 | main repo の `13.x` を `c557ef26` → `90845f5c`（52 ファイル）・main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3） | 7,424 クラス・新しい 4 クラス（`ProcurementLotController`・`ReProcurementLot`・`ProcurementConversionRefused`・`ProcurementToProjectConverter`）が表に入った・作業場所のパス 0・`vendor/bin/phpunit` は無い・作業ツリーは空 |
| 4 `./deploy.sh` | 2026-11-20 22:07 ごろ（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 35 本と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 5 本番の読み取り（反映の後） | md5・構文・ビュー・ルート・記録・キャッシュ・本番の PHP で読んだ値 | 37 本とも手元（`90845f5c`）と **md5 が一致** ／ 変えた PHP の `php -l` ok ／ コンパイル済みビュー **328 本 / INVALID 0**（区画の欄の 1 本ぶん増えた）／ 新しいルート 4 本（`lot.store`・`lot.update`・`lot.destroy`・`convert`）／ `laravel.log` は Nov 18 01:45 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 22:07 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ 次の番号 RE-PRC-032／RE-PRJ-021・区画 0 件・表記「土地」・土地 19 件のうち分譲地に変えられる 18／断る 1 |
| 6 ログインした画面（利用者の Chrome・1440px・見るだけ・送信しない） | 一覧・土地の詳細 19 件・契約の画面・分譲地・建売・注文住宅・仕入れ先 | 一覧に「土地」・「区画」ボタン・「区画 0 / 0」・「仲介土地」の文字は 0 ／ 土地 19 件の詳細がすべて 200 で区画の欄が出る（区画の初期の値は土地面積と想定販売価格〈土地〉）／ 紐付けのある #21 だけ「分譲地に変える」が押せず「契約 1 件が紐づいているため、分譲地に変えられません。」（包む span の `title` と `aria-describedby`）・区画の初期の状態は「成約」 ／ #31 の区画と変える処理のフォームの送り先が正しく、二度押し止め（`submitOnce()`）がある ／ 契約の登録（仕入れ案件の選択肢 4 件）・契約の詳細と編集・分譲地・建売・注文住宅の詳細・仕入れ案件の編集が 200 ／ 仕入れ先 6 社の詳細に「関連する分譲地」 ／ どの画面も `main` の横のはみ出し 0・Alpine がすべて起動・コンソールのエラー 0 |

⚠ 区画の登録・分譲地に変える操作は本番では押していない（本番のデータを変える操作のため。動きはテスト・変異・使い捨ての環境の実ブラウザで確かめてある）。本番で実際に区画を入れる・変えるのは利用者。

⚠ 分譲地で使われている仕入れ先（本番で 6 社）は、これから削除できない（削除を押すと「この仕入れ先は仕入れ案件・分譲地で使用されているため削除できません。」）。

⚠ `origin/13.x` への push はしていない。

## ✅ 土地の仕入れ案件に区画を複数登録できるようにする — 本番反映済み

設計書: @docs/superpowers/specs/2026-11-20-realestate-land-multi-lot-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-21-realestate-land-multi-lot.md

利用者の依頼（2026-11-20）。上の節の「土地は基本 1 区画。2 つ以上に分けるときは分譲地に変える」という前提が違っていた。利用者の言葉は「土地の場合も複数区画がある場合があります。現状は 1 区画しか登録できません」「土地の場合は土地売りをするという意味で、分譲地は建物と土地を両方売るということです」。会話で決まったこと: 区画が 2 つ以上ある土地にも、区画ごとに建売・注文住宅を建てることがある・区画ごとの原価は販売価格の比率で分ける（分譲地と同じ式）・「分譲地に変える」は残す・作り方は「今の土地の区画の表を広げ、契約・建売・注文住宅に土地の区画の列を足す」（案 1）。
作業場所 `.claude/worktrees/land-multi-lot`・枝 `land-multi-lot`（13.x = `37a96107` から。設計 `7d96c0a4`・計画 `00995aeb`・実装は `bd4e9608` から `ac5af1b4` まで）。**DB 変更あり（列 3 つと索引）。新しい PHP クラスが 3 つ**（`LandCostAllocation`・`ProcurementLotClaims`・`HousingProcurementLots`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。**ルートは区画の 3 本を置き換えた**（`…/lot` → `…/lots`・`…/lots/{lot}`。数は同じ）。

### 直す前

| 場面 | 直す前 |
|---|---|
| 土地の仕入れ案件の区画 | 1 つまで。2 つ目は「区画は 1 つまでです。2 つ目を足すときは「分譲地に変える」を使ってください。」で断られた |
| 1 つの土地を区画に分けて売る | 土地のままでは売れず、分譲地に変えるしかなかった（分譲地は「建物と土地をあわせて売る」案件で、意味が違う） |
| 区画が 1 つの土地の契約・建売・注文住宅 | 区画を選ばずに仕入れ案件だけを選び、区画の状態は土地の全区画に付いてきた（建売は何件でも付けられた） |

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| 区画の欄 | 1 案件に何区画でも登録・編集・削除できる（「1 つまで」の断りを消した）。仕入れ案件の詳細に区画の表（号地・面積（坪）・販売価格（坪単価）・ステータス・備考・紐づいているもの・操作）。編集は行のすぐ下に開くフォーム。2 つ目からの追加のフォームは号地「いちばん大きい号地＋1」・面積と販売価格は空・販売中。同じ土地の中で号地番号が重なる登録・更新は「この号地番号はこの土地にすでにあります。別の番号にしてください。」で断る（入力チェック。DB の一意の索引は無い）。紐づいている区画は「契約 1 件が紐づいているため、この区画は削除できません。」の形で断る（見積りの注文住宅も数える）。表の「紐づいているもの」に契約・建売・注文住宅の詳細へのリンク |
| 1 区画 1 件 | 1 つの区画に付けられるのは、不動産の契約・建売・契約以降の注文住宅のうち 1 件だけ（見積り以前の注文住宅は何件でも付けられる・建売は契約の前から数える）。取られているかは紐付けで決め、区画のステータスでは決めない。判定は `ProcurementLotClaims` の 1 か所で、どの経路も同じ文「この区画（1号地）は契約「…」に紐づいています。ほかの区画を選んでください。」。区画は選んだ仕入れ案件のものだけ受け付ける（別の土地の区画・無い区画・配列は断る）。区画のある土地では区画が必須（「区画を選んでください。」）。ただし区画の無い保存済みのレコード（本番の #21 の形）は、何も変えずにそのまま保存できる（編集画面に「区画を選ばずに保存すると、今のまま（区画なし）で保存します。」）。契約どうしは `re_contracts.procurement_lot_id` の一意の索引でも守る |
| 区画を選ぶ画面 | 不動産の契約の登録・編集（サーバーが選べる区画の一覧を最初から渡す）・建売の登録・注文住宅の登録と編集・契約一覧から開く注文住宅の契約の編集（`GET /api/housing/procurement-info/{procurement}` に `lots`）。選択肢は取られていない区画と今の区画だけで「1号地 132.69㎡（40.13坪） 販売価格 20,000,000円 販売中」の形。選べる区画が 1 つならその区画を選んだ状態にする。区画を選ぶと、不動産の契約の「土地の契約金額」に区画の販売価格、住宅事業の「土地面積」に区画の面積、建売の契約の「土地販売価格」の初期値に区画の販売価格が入る。不動産の契約の仕入れ案件の選択肢は「販売中で区画も契約も無い案件」と「販売中で取られていない区画のある土地」（編集は今の案件も）。建売・注文住宅の登録画面は全区画が取られた土地を出さない。契約の登録の注記は「※ ステータスが「販売中」で、契約の無い案件と、空いている区画のある土地を表示」。建売の編集画面と契約済みの注文住宅の土地は今どおり読み取り専用（区画も表示と hidden）。区画が 1 つの土地も同じ作り（区画を選ぶ・必須・1 件まで） |
| 原価の按分 | 区画 0〜1 の土地は仕入れ案件の原価の全額（区画が 1 つなら販売価格が空でも全額）。2 つ以上の土地は「区画の販売価格 ÷ 全区画の販売価格の合計 × 原価の合計」（分譲地と同じ式・同じ丸め。`LandCostAllocation::share()` を分譲地と共用）。全区画に販売価格が無ければ空。不動産の契約の原価欄と、建売・注文住宅の土地原価の自動の値（`HousingLandCost::forLink()`）が同じ値を使う。不動産の契約の詳細は、区画が 2 つ以上の土地の区画の契約で「この区画の按分: 販売価格 X円 ÷ 全区画の販売価格の合計 Y円 → Z円」の行を出し、食い違いの警告は按分した額と比べる（按分できなければ「全区画に販売価格が入っていないため、按分できません」で警告なし） |
| 状態 | 区画のステータスは、その区画の不動産の契約・建売の契約・注文住宅の「契約」に合わせて、その区画だけ動く（`ReProcurementLot::markSold()`・`markOnSale()`）。付け替えたら旧の区画は販売中・新の区画は成約。区画のある土地の仕入れ案件のステータスは区画から集約する（全区画が成約で販売済・1 つでも戻れば販売中・不成約は触らない。`ReProcurement::syncStatusFromLots()`）。区画の登録・更新・削除のあとも集約する。区画のある土地では、不動産の契約の登録で仕入れ案件を「販売済」にしない。区画の無い土地は今どおり |
| 住宅事業の紐付け | 区画だけを変えるのも「紐付けが変わった」とみなす（契約のある建売・契約済みの注文住宅は区画も変えさせない＝Bug #142 ／ 土地原価の計算し直し＝Bug #143）。画面の JS の `housingLandLinkChanged()` も同じで、PHP と突き合わせるテストがある |
| 一覧・分譲地に変える | 一覧の「区画 成約数 / 区画数」を全区画で数える。「分譲地に変える」は全区画を号地の順に写す。1 区画の名残（`ReProcurement::lot()`・`markSoldFor()`・`markOnSaleFor()`・`ONLY_ONE`）を消した |
| 詳細 | 不動産の契約・建売・注文住宅・契約一覧から開く注文住宅の契約の詳細に、紐づけた区画を出す（号地・面積・坪数。契約一覧から開く注文住宅の契約の詳細は建築土地に号地。土地のほかの区画は出さない） |
| 表 | SQL `database/sql/2026-11-21-re-procurement-lots-multi.sql`（6 文）: `re_procurement_lots.procurement_id` の一意の索引をふつうの索引に替える（先にふつうの索引を足してから一意の索引を外す＝MySQL の error 1553 を避ける順）・列のコメント・新しい列 `re_contracts.procurement_lot_id`（一意）・`hs_properties.re_procurement_lot_id`・`hs_custom_orders.re_procurement_lot_id`（どれも `BIGINT UNSIGNED NULL`・外部キー → `re_procurement_lots.id`・`ON DELETE RESTRICT`）。テスト用スキーマにも同じ形（`ProcurementLotModelTest` が突き合わせる） |

### 決めたこと

利用者の決定（2026-11-21・どちらも A）: ①区画を選んだら区画の値を入れる（区画の販売価格が空なら金額は入れない）②建売の編集画面の土地は今どおり読み取り専用（区画の無い建売にあとから区画をつなぐ画面は作らない）。

計画で決めたこと（設計書の任せた所。計画の「決めたこと」3〜10）: 区画の決まりは「選んだ仕入れ案件に区画があるか」で決める・見積りの注文住宅も選べるのは取られていない区画と今の区画だけ・区画の表の「紐づいているもの」は見積りの注文住宅も含めて全部出す・区画のルートは `…/lots/{lot}` でその案件の区画に絞る・区画の行の編集は行のすぐ下に開く・不動産の契約は区画の一覧をサーバーが最初から渡し、住宅事業は API に `lots` を足す・区画の選択欄に HTML の `required` を付けない（#21 の形を何も変えずに保存できるように）・同時の保存にロックは足さない。

作っている途中の決めごと（台帳 `.superpowers/sdd/2026-11-21-realestate-land-multi-lot/progress.md` の Ruling）:

| # | 決めたこと |
|---|---|
| 2 | 区画が見つからないとき（ほかの案件の区画を含む）は 404 にせず、詳細の区画の欄へ戻して「区画は登録されていません（ほかの画面で削除された可能性があります）。」。仕入れ案件も無ければ一覧へ |
| 3 | 区画の削除の確認の文を「区画を削除しますか？ 登録した面積・販売価格などが消えます。」に（紐づいている区画は消せなくなったので、前の「紐付けは消えません」は事実と合わない） |
| 4 | 区画のある土地に付いた区画の無い契約（#21 の形）の登録・更新・削除は、区画を動かさず、仕入れ案件のステータスは区画から集約するだけ |
| 5 | 区画の自動の選択（選べる区画が 1 つならそれを選ぶ）は、利用者が仕入れ案件を選んだときだけ。区画の無い保存済みの契約の編集画面を開いても自動では選ばない |
| 6 | 区画のある土地では、原価の API の応答のあとの原価欄は「選んだ区画の按分した原価（区画を選んでいなければ空）」。全額を残すと 2 区画以上の土地で過大な原価のまま保存されうる。⚠ 2 区画以上の土地では、区画を選ぶまで原価欄が空（今までは全額が入った） |
| 8 | 建売・注文住宅の登録画面の仕入れ案件の選択肢から、区画が全部取られた土地を外す（選んでも区画が出ず、保存で「区画を選んでください。」に詰まるため。今の仕入れ案件は残す） |
| 9 | 区画が要るのにまだ選んでいない間は、土地原価の「計算できません」を出さない（保存は「区画を選んでください。」が断る） |
| 10 | 「計算できません」の文を「紐付け先から計算できません（分譲地・土地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。」に |
| 11 | 「取られている」で断られて戻った画面では、取られた区画を選んだままにせず、その区画の土地原価も出さない（建売・注文住宅） |
| 12 | 注文住宅で「取られている」を断るのは、「契約」へ進むとき（見積り以前 → 契約以降）と、区画を新しく選ぶ・変えるときだけ。契約以降の注文住宅を何も変えずに保存する・契約 → 着工へ進めるのは断らない（Bug #130 の形を作らない） |
| 14 | 不動産の契約の登録・編集画面にも Ruling 11 と同じ直しを入れる（取られた区画を外し、その区画が自動で入れた土地の契約金額と原価も外す）。あわせて、土地や区画を選び直したときも、前の区画が自動で入れた金額と原価を空にする（手で打った値は残す） |
| 15 | 不動産の契約と建売で、今の区画のまま何も変えずに保存するときは「取られている」で断らない（新しく選ぶ・付け替えるときは今どおり断る）。契約の編集画面の区画の選択肢には、今の区画がほかにも付いていても残す。計画の指定（今の区画でも断る）から変えた |

ほかの Ruling は進め方と記録のもの（1 = 区画のフォームの hidden `lot_form` の和名「区画のフォーム」・7 = 下の「利用者に伝えること」・13・16・17 = 確かめの順と直しの範囲・18 = `docs/RULES.md` に Bug の行を足さない〈見つけて直した欠陥はどれもこの枝で作った新しいコードの中で、本番の欠陥ではない〉）。

### 検証

- 全件: 着手前 **OK (5476 tests, 60164 assertions)** → 最後 **OK (5576 tests, 66056 assertions)**（PHP 8.3.36・4 分 13 秒・ピーク 179.50 MB・警告なし）／ コンパイル済みビュー **327 本 / INVALID 0**
- Task ごとの点検（Task 1〜8。実装の担当 → 別の点検の担当）: Important は 2 件で、どちらも直した ①Task 2: `refusal()` の「保存済みの持ち主が区画を変える」の枝をテストが通っていなかった → テストを足し、変異で赤を確かめた（`65c9647d`）②Task 7: 土地の最後の区画が売れて仕入れ案件が販売済になると、その土地の見積りの注文住宅（と区画の無い古い注文住宅）を編集画面からそのまま保存できなかった → 編集画面は今の仕入れ案件をステータスに関わらず残す（`88d279f5`・`66a5f9a8`）。ほかは Approved
- 変異 33 通り（カナリアを含む・関係するテスト 2117 本・約 46 分。道具は作業場所の `.claude/route-coverage-tools/mutate/run_lml.py`・表は `specs_land_multi_lot.py`・結果は `results-land-multi-lot.*`。main repo の同じ場所へ写した）: MATCH 30・DIFF 0（1 回目の T1・T4 は期待の理由の書き違い → 直して MATCH）・**生き残った変異 3 つ**（V1c 契約の編集画面の区画の選択肢の `:selected` を消す・V2b / V2d 契約の編集画面で区画を選び直したときの原価・土地の契約金額の入れ替えを消す。編集画面の選び直しのテストが同じ販売価格の区画どうしだったため）→ 最後の点検の直しの波でテストを足し、round 3 の 14 通り（直しの波を測る新しい 9 通りを含む）がすべて MATCH
- 実ブラウザ 1 回目（`7c3b79e1`・使い捨ての SQLite ＋ `artisan serve` ＋ Playwright・画面が見えている状態）: 区画 3 つの土地で、1号地を不動産の契約（原価 15,000,000 ＝ 30,000,000 × 20/40）・2号地を建売（土地原価 7,500,000）・3号地を注文住宅（契約）にし、選択肢が 1 つずつ減って全部取られると 3 つの登録画面の仕入れ案件の選択肢から土地が消える・建売の契約で 2号地が成約になると土地が販売済 ／ #21 の形（区画の無い契約がある土地に区画を足し、契約の編集画面を何も変えずに保存できる・区画は空のまま）／ V1c・V2b・V2d は画面では正しく動いた ／ 古い画面から取られた区画を送ると全文で断る ／ 16 画面 × 1440・375px で `main` の横スクロール 0・コンソールのエラー 0。懸念 1 つ（不動産の契約の登録で断られて戻ると、取られた区画の金額と原価が残る＝表示だけ・誤保存なし）→ Ruling 14
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ・`37a96107..7c3b79e1`）: 「直してからマージ」・Critical 0・Important 2（I1 土地を選び直すと前の区画の土地の契約金額が残る ／ I2 Ruling 14 の直しは編集画面にも要る〈#21 の契約の編集では、取られた区画の金額と原価のまま保存できうる〉）・Minor 7 → 直しの波（1 回・`a901b2d3`〜`ac5af1b4`）で I1・I2・M1（契約の編集画面の選択肢に今の区画を残す）・M2（Ruling 15）・M5（SQL の頭の注記に設計書の名前と戻し方）とテストの穴（V1c・V2b / V2d・区画の無い土地と区画のある土地のあいだの付け替えのステータス・Ruling 4 の建売）を直した → 範囲を絞った点検ですべて addressed（新しい Critical・Important なし）
- 実ブラウザ 2 回目（`ac5af1b4`・不動産の契約の登録と編集だけ）: 5 項目すべて OK（土地を替えると前の区画の金額と原価が空になり、手で打った金額は残る ／ 断られて戻った画面に取られた区画が残らない〈登録・#21 の編集〉／ 編集画面で区画を選び直すと金額と原価が入れ替わり、戻せば元の値 ／ 2 画面 × 1440・375px で横スクロール 0・コンソールのエラー 0）

### 利用者に伝えること

- **#21 の土地に最初の区画を足すと、初期値のステータスは「成約」**（今の決まり）だが、どの契約にもつながっていない。#21 の契約をあとで消す・ほかの土地へ付け替えると、その区画は成約のまま残り、土地が販売済のまま契約の登録画面の選択肢から消えうる。直し方は、区画の表でその区画を「販売中」にする（集約で土地も販売中に戻る）（Ruling 7・最後の点検の M7）（→ 2026-11-22〜23 に直した。区画を足すとき、区画を選んでいない契約などをその区画につなぐ。下の「区画を足すとき、区画を選んでいない契約などをその区画につなぐ」の節・Bug #146）
- **区画が 2 つ以上ある土地で、契約があるのに仕入れ案件を一覧の小窓や編集画面で手で「販売済」にすると、そこからは戻せない**（Bug #139 の「契約のある仕入れ案件は販売済から戻させない」をそのままにしたため）。区画を 1 つ保存し直すと、区画から集約し直されて販売中に戻る（最後の点検の M3）（→ 2026-11-22 に直した。区画のある土地は「販売済」かどうかを区画で決める。下の「区画のある土地の仕入れ案件のステータスを区画で決める」の節・Bug #145）
- **不動産の契約の登録画面の「仕入れ案件の原価情報（自動参照）」の箱は原価合計に全額を出し、原価欄には按分した額が入る**（区画が 2 つ以上の土地で見え方が食い違う。保存されるのは原価欄の値）（最後の点検の M4）
- **ほぼ同時の 2 つの保存で、1 つの区画に 2 件が付きうる**（自動のセキュリティ点検の指摘。とくに注文住宅のステータスを「契約」へ進めるとき）。順に届けば断る。契約どうしは一意の索引が止める（2 件目は 500 になりうる）。決めたこと 10（ロックを足さない）の範囲で、直すならロックか、区画ごとに 1 件の一意の索引（DB 変更）
- 契約の編集画面で区画を選んでから「— 区画を選択 —」に戻すと、土地の契約金額と原価が空になる（保存済みの値に戻らない。保存は「必須」で止まり、誤保存はない）
- 不動産の契約の登録で、土地の最後の空き区画をほかに取られて断られて戻ると、仕入れ案件の選択欄も空に見える（その土地が選択肢から消えるため。保存は止まる）

### 範囲外・後回し

- 設計書と計画の範囲外: 区画の表に区画ごとの原価額・粗利額の列 ／ 土地の区画の図面 ／ 分譲地から土地へ戻すこと ／ 区画の無い土地の今の動き（建売で売れた区画の無い土地にも不動産の契約を登録できる、など）／ 契約一覧に区画を出すこと
- データベースで守らないもの: 号地番号の重なりは入力チェックだけ（DB の一意の索引は無い）・建売と注文住宅の「1 区画 1 件」は表をまたぐのでアプリの判定だけ
- 小さな動き: 区画が見つからない送信は役割の門番より先に動く（一般担当は 403 でなく「区画は登録されていません」）／ 最後の区画を消しても仕入れ案件のステータスは触らない ／ 数字でない区画の id で文が 2 つ出る（手で組んだ送信だけ）／ 自動の値かどうかは値が同じかで決める（手で打った値がたまたま区画の販売価格と同じなら消える）／ 契約の編集で同じ仕入れ案件のまま区画を空にすると「保存すると、新しい紐付け先から計算します。」が出るが保存は断られる ／ 契約済みの建売を削除すると区画が成約のまま残る（前からの範囲外。→ 2026-11-23 に直した。Bug #147）
- 見た目: 建売・注文住宅の詳細で号地が 2 回出る（リンクの文字と区画の行）／ 区画を選んだあとも案内の文が出る ／ 区画が多いと入力エラーの一覧と開いた行が離れる
- 問い合わせの数: 契約の登録・編集画面を開くたびに 3 本増える（`procurementLotAuto()`）・住宅事業の区画の一覧（`housingLotOptions()`）が区画ごとに読む
- 重複: 区画の JS の関数（契約の登録と編集）・区画の追加と編集のフォームの欄・取られた区画の外し方（`HousingProcurementLots::excludedLotIds()` と `ProcurementLotClaims::selectableLots()`）・数字の id の読み方
- テストの穴: 一覧の区画の数は画面の文字でなく行の値で見る ／ 複数の案件 id で `takenLotIds()` を引くテスト ／ ほかの案件の区画を `forLink()` に渡すテスト ／ 区画の無い古い見積りの注文住宅を契約の編集画面からそのまま保存するテスト

### 本番反映の手順

**DB が先・`./deploy.sh` が後**（新しいコードが 3 つの新しい列を読み書きするので、コードを先に送ると 500 になる）。どの段も利用者の承認を取ってから。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・ほかの会話に 13.x の早送りと deploy を待ってもらう
2. 本番の読み取り（`ssh … /bin/sh < 手順ファイル` で `artisan tinker --execute`・php は 8.3 を名指し）: `SHOW CREATE TABLE` の `re_procurement_lots`・`re_contracts`・`hs_properties`・`hs_custom_orders`（⚠ SQL の `AFTER` が指す列 `re_contracts.lot_id`・`hs_properties.re_procurement_id`・`hs_custom_orders.re_procurement_id` があること〈この 3 つの表の DDL はリポジトリに無く、作っているあいだに確かめられなかった〉・索引と外部キーの名前・`re_procurement_lots.id` の型・新しい列がまだ無いこと）／ 件数だけ: 土地の区画の数・区画のある土地の数・土地に紐づく契約・建売・注文住宅の数（#21 を含めて設計書 §5.7 と同じか。前の反映のあとで区画が入っているかも）／ 変わるファイルの md5 が今の 13.x と一致 ／ `laravel.log` が Nov 18 01:45 のまま。違えば SQL を直す
3. `database/sql/2026-11-21-re-procurement-lots-multi.sql` の 6 文を `tinker` の `DB::statement()` で 1 文ずつ・1 → 6 の順に（先頭で `re_contracts.procurement_lot_id` があれば 1 文も流さずに止まる。途中で止まったら止まった文から続ける）→ 流したあとの `SHOW CREATE TABLE` が SQL のとおりか確かめる
4. main repo で `git checkout 13.x && git merge --ff-only land-multi-lot` → main repo の cwd で **`composer dump-autoload --no-dev --optimize`**（PHP 8.3。新しいクラス 3 つが表に入り、作業場所のパスが 0・`vendor/bin/phpunit` が無い）
5. `./deploy.sh`（ルートの名前が変わるので `route:cache` が作り直される）
6. 反映の後の読み取り: 送ったファイルの md5 が手元と一致・コンパイル済みビューの lint INVALID 0・ルート `realestate.procurements.lots.*` が 3 本で `….lot.*` が 0 本・`laravel.log` に反映のあとのエラーが無い
7. ログインした画面（見るだけ・送信しない）: 仕入れ案件の一覧・土地の詳細（区画の表と追加のフォーム）・#21 の契約の編集（区画の選択欄・何も選ばれていない・案内の文）・契約の登録（仕入れ案件の選択肢と注記）・建売と注文住宅の登録（区画の無い土地では区画の選択欄が出ない）。`main` の横スクロール 0・コンソールのエラー 0
8. 記録（この節に「本番反映」の表・計画の実測記録）。push は利用者が「push して」と言ったときだけ

### 本番反映（2026-11-21 実施）

利用者の承認を段ごとにとって、上の手順どおりに流した（本番の読み取り・SQL・早送り・`./deploy.sh`・反映の後の読み取り・ログインした画面のそれぞれで承認を取った）。`13.x` は作業のあいだ `37a96107` のまま進んでいなかった（取り込み不要）。main repo の `13.x` を **`79b44597`** へ早送りした（push なし）。13.x を動かすほかの会話は無かった（`ListAgents`）。手順ファイルと出力は scratchpad の `prod10/`（`prod-read-before.sh`・`prod-sql.sh`・`prod-read-after.sh`・`*.out`）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 本番の読み取り（反映の前） | 変わるファイルの md5・新しいファイル・記録・表の定義・件数 | 変わる 34 本とも `37a96107` と一致・新しい 4 本は本番に無い＝本番は 13.x のまま ／ `laravel.log` は Nov 18 01:45（3333 行）／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` ／ `re_procurement_lots.id` は bigint unsigned・一意の索引 `uq_re_procurement_lots_procurement`・外部キー `fk_re_procurement_lots_procurement`（CASCADE）＝SQL と合う ／ `AFTER` が指す列 `re_contracts.lot_id`・`hs_properties.re_procurement_id`・`hs_custom_orders.re_procurement_id` はある・新しい列は無い（SQL は直していない）|
| | 件数（件数と id・ステータスだけ） | 土地 19 件・**区画 2 件**（設計書 §5.7 を書いた 11/20 の 0 件から、前の反映のあとに利用者が入れた）＝ #9（決済完了・自社買取）1号地・未販売・価格あり ／ #18（査定・検討・自社買取）1号地・販売中・価格あり。区画が 2 つ以上の土地 0 ／ 土地に付く不動産の契約 1 件（#21・区画 0）・建売 0・注文住宅 0。#9・#18 とも販売済でないので、区画からの集約は案件のステータスを変えない |
| 2 SQL（DB が先） | 6 文を `tinker` から 1 文ずつ（先頭で `re_contracts.procurement_lot_id` があれば止まる。SQL は base64 で埋め込んだ） | **OK 1〜6** ／ 流したあとの `SHOW CREATE TABLE` は SQL のとおり（区画の表は `idx_re_procurement_lots_procurement` だけで一意の索引は無い・外部キーは CASCADE のまま・コメント「1 案件に何区画でも」／ `re_contracts.procurement_lot_id` は `lot_id` の後・一意の索引・外部キー RESTRICT ／ `hs_properties`・`hs_custom_orders.re_procurement_lot_id` は `re_procurement_id` の後・索引・外部キー RESTRICT）／ 区画 2 件のまま・新しい列はどれも空 |
| 3 早送りと読み込みの表 | main repo の `13.x` を `37a96107` → `79b44597`・main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3） | 7,427 クラス・新しい 3 クラス（`LandCostAllocation`・`ProcurementLotClaims`・`HousingProcurementLots`）が表に入った・作業場所のパス 0・`vendor/bin/phpunit` は無い・作業ツリーは空 |
| 4 `./deploy.sh` | 2026-11-21 23:33 ごろ（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 38 本（名前の一覧と一致）と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じ・旧バンドルの削除 0（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 5 本番の読み取り（反映の後） | md5・構文・ビュー・ルート・記録・キャッシュ | 40 本とも手元（`79b44597`）と **md5 が一致** ／ 変えた PHP の `php -l` ok ／ コンパイル済みビュー **328 本 / INVALID 0**（手元の 327 本＋本番に残る `components/app-layout`）／ ルート 502 本・`realestate.procurements.lots.{store,update,destroy}` の 3 本・旧 `….lot.*` 0 本 ／ `laravel.log` は Nov 18 01:45 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 23:33 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build は両側とも前と同じ 2 本 |
| 6 ログインした画面（利用者の Chrome・1440px・見るだけ・送信しない） | 一覧・#9 と #21 の詳細・#21 の契約の編集・契約の登録・建売と注文住宅の登録 | 一覧（土地・すべて）19 件で「区画 0 / 1」が 2 件（#9・#18）・ほか「区画 0 / 0」・「仲介土地」0 ／ #9 の詳細: 区画の表（1号地 194.76㎡（58.91坪）・15,000,000円（@25.5万円）・未販売・編集・削除）と追加のフォーム（号地 2・面積と価格は空・販売中・「いちばん大きい号地の次の番号」の案内）・二度押し止めつき・「1 つまで」の文は 0 ／ #21 の詳細: 最初の区画のフォーム（号地 1・952.00・成約）・「契約 1 件が紐づいているため、分譲地に変えられません。」 ／ #21 の契約（id 8）の編集: 土地に区画が無いので区画の選択欄は出ない・案内も出ない ／ 契約の登録: 仕入れ土地販売で仕入れ案件の選択肢 4 件（#6・#10・#11・#22。#9・#18 は販売中でないので出ない）・新しい注記 ／ 建売・注文住宅の登録: #9 を選ぶと区画の選択欄に「1号地 194.76㎡（58.91坪） 販売価格 15,000,000円 未販売」が出て自動で選ばれ土地面積 194.76、区画の無い #6 では選択欄が出ない ／ どの画面も Alpine がすべて起動・`main` の横のはみ出し 0・コンソールのエラー 0 |

⚠ 区画の登録・保存・分譲地に変えるは本番では押していない（本番のデータを変える操作のため）。仕入れ案件を選ぶ・契約種別を選ぶは画面の中だけの操作で、送ったのは区画の一覧を読む GET だけ。ログインした画面のあとの `laravel.log` は読んでいない（読み取りの承認は反映の直後の分）。

⚠ `origin/13.x` への push はしていない。

## ✅ 区画のある土地の仕入れ案件のステータスを区画で決める（Bug #145）— 本番反映済み

設計書（短）: @docs/superpowers/specs/2026-11-21-procurement-lot-status-lock-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-21-procurement-lot-status-lock.md

上の「土地の仕入れ案件に区画を複数登録できるようにする」の節で、利用者に伝えてあった「直していないこと」の 2 つ目を、利用者の依頼（2026-11-21）で直した（docs/RULES.md の Bug #145）。
作業場所 `.claude/worktrees/lot-status-lock`・枝 `lot-status-lock`（13.x = `410db928` から）。**DB 変更・ルート変更・画面の変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直す前

区画が 2 つ以上ある土地で 1 区画にだけ契約があるとき、仕入れ案件を一覧の小窓か編集画面で手で「販売済」にすると、Bug #139 の歯止め（契約のある案件は「販売済」から戻させない）で「販売中」に戻せなかった。そのあいだは、空いた区画があっても契約の登録画面の選択肢に土地が出ない。抜け道は区画を 1 つ保存し直すことだけだった。本番は区画のある土地が #9（決済完了）・#18（査定・検討）の 2 件で、どちらも販売済ではない＝戻せなくなっていた案件は 0 件。

### 決めたこと（利用者の決定 2026-11-21・案 ア）

区画のある土地は「販売済」かどうかを区画で決める（区画の無い案件は Bug #139 のまま）。

| 場面 | 手での変更（一覧の小窓・編集画面） |
|---|---|
| 今と同じステータス | 通す |
| 全区画が成約 | 「販売済」にしかできない |
| 成約でない区画がある | 「販売済」にはできない（ほかへは自由） |

- 選ばなかった案 イ（分譲地と同じく歯止めを効かせない）は、#21 の形（紐付けの無い「成約」の区画）で手で販売中に戻すと、同じ土地にもう 1 件の契約を登録できてしまう
- こちらの判断: 全区画が成約なら「不成約 → 販売中」のような変更も断る（手での変更で区画と食い違う形を作らない）

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| Model | `ReProcurement::statusRefusal(string $status): ?string`（`refusesStatus()` を置き換え）・断る文の定数 `STATUS_SOLD_NEEDS_ALL_LOTS`（N 入り）・`STATUS_STAYS_SOLD_WHILE_ALL_LOTS_SOLD` |
| Controller | `ProcurementController::statusLockRule()`（`statusLockedByContract()` を改名。一覧の小窓と編集画面の 2 か所） |
| テスト | `ProcurementContractLockTest` に節「区画のある土地」（7 メソッド・10 本）。5576 → **5586 tests / 66440 assertions green**（PHP 8.3・警告なし） |
| 画面・DB・ルート | **変更なし**（一覧の小窓は 422 の文を alert・編集画面は入力エラーの帯に出す） |

断る文:
- 「区画のある土地は、すべての区画が成約になると自動で「販売済」になります（成約でない区画が N 件あります）。」
- 「すべての区画が成約のため、ステータスは「販売済」にしかできません。区画を成約から戻すと、自動で「販売中」に戻ります。」

### 検証

- 足したテストは直す前のコードで 7 本が狙った理由で赤（一覧の小窓は 200／422 の取り違え・編集画面は帯の文が無い）、直しすぎを止める 3 本（全区画が成約なら販売済にできる・食い違った古いデータもそのまま保存できる 2 データ）は緑を確かめてから直した
- 不動産のテスト **OK (677 tests, 10675 assertions)**・全件 **OK (5586 tests, 66440 assertions)**（PHP 8.3・3 分 58 秒・ピーク 175.50 MB・警告なし）
- 変異 10 通り（カナリアを含む・不動産のテストで流した・道具は scratchpad の `mutate_lsl.py`）すべて計画の期待どおりの組と理由で落ちた。1 回目は L1 の置き換え先が 2 か所で当てずに止まり（無効な測定）、狙う行を絞って流し直した
- 画面を変えないので実ブラウザの確認は行っていない（一覧の小窓の JS は node の vm で動かして確かめた）

### 範囲外（設計書のとおり）

分譲地の手でのステータス変更（今も歯止めが無い）・画面の選択肢を隠すこと・#21 の形の最初の区画が「成約」で作られること（上の節の「利用者に伝えること」の 1 つ目のまま）（→ 2026-11-22〜23 に直した。Bug #146）

### 本番反映の手順

**DB 変更・新しいクラス・ルートの変更は無い**（SQL も `composer dump-autoload` も要らない）。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・ほかの会話に知らせる
2. main repo で `git checkout 13.x && git merge --ff-only lot-status-lock`
3. `./deploy.sh`
4. 反映の後の読み取り（承認のうえ）: 変えたファイル 2 本の md5・コンパイル済みビューの lint・`laravel.log` が Nov 18 01:45 のまま

### 本番反映（2026-11-22 実施）

利用者の指示（「1 で13.xへ早送りして本番へ反映してください」）で、main repo の `13.x` を `410db928` から **`b04aa8a9`** へ早送りし（push なし）、`./deploy.sh` を流した。早送りの前に `ListAgents` で 13.x を動かすほかの会話が無いこと・13.x が進んでいないことを確かめた。反映の前に、最後の点検の Minor 1（注記の「手で販売中に戻させない」が実際の守りより強い）だけ、コードのコメントと RULES #145 の言い方を直した（`b04aa8a9`・動きは変わらない・`ProcurementContractLockTest` OK (21 tests, 712 assertions)）。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 13.x で作業ツリーは空・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 2 本（`ReProcurement.php`・`ProcurementController.php`） |
| 2 反映 | 早送り・`./deploy.sh` | exit 0・6 段すべて。送ったアプリのファイルは 2 本とビルドだけ。CSS・JS の名前は前と同じ（`app-o1yvrcrl.css`・`app-NiVQbl_Q.js`。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。config・route・view の 3 つのキャッシュとも成功 |
| 3 外からの確かめ（ssh なし・データは送らない） | ログイン画面・ログインの無い一覧 | ログイン画面 200・`app-o1yvrcrl.css` を読む ／ 仕入れ案件の一覧はログイン画面へ 302 |
| 4 本番の読み取り（2026-11-22・利用者の承認のうえ・読むだけ） | md5・構文・ビュー・記録・キャッシュ・ビルド・新しい判定 | 2 本とも手元（`b04aa8a9`）と **md5 が一致**・`php -l` で構文エラーなし ／ コンパイル済みビュー **328 本 / INVALID 0** ／ `laravel.log` は Nov 18 01:45（3333 行）のまま＝反映のあとのエラー 0 件（画面を開いたあとも同じ）／ `bootstrap/cache` は 11/22 0:20 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build はアプリ側・公開側とも `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` だけ ／ 本番の PHP で `statusRefusal()` を本番のデータに当てた（保存はしない）: #9（決済完了・区画 1 未販売・契約 0）と #18（査定・検討・区画 1 販売中・契約 0）は「販売済」を「成約でない区画が 1 件あります」で断り、「販売中」と今のステータスは通す ／ #21（販売済・区画 0・契約 1）は「販売中」を今までの Bug #139 の文で断る ／ 戻せなくなっていた案件（区画のある販売済で成約でない区画がある）は **0 件** |
| 5 ログインした画面（利用者の Chrome・見るだけ・送信しない） | #9 の編集・一覧・#9 / #18 / #21 の詳細と編集・契約の登録 | #9 の編集は物件種別「土地」・ステータス「決済完了」・Alpine 20/20・`main` の横のはみ出し 0・CSS は `app-o1yvrcrl.css` ／ 一覧はステータスの小窓 20 個（`realestateStatusCell` が関数）・422 の理由を出す処理あり・Alpine 40/40・はみ出し 0・読み込み直したときのコンソールのエラー 0 件 ／ ほかの 6 画面は同じオリジンの GET でどれも 200・エラー画面なし |

⚠ ステータスの変更は本番では送っていない（本番のデータを変える操作のため。断る動きはテストと変異と、上の 4 の判定で確かめてある）。

⚠ `origin/13.x` への push は反映のときはしていない。→ 2026-11-22 に利用者の指示で push 済み（`410db928..c41cb7d3`）。

## ✅ 区画を足すとき、区画を選んでいない契約などをその区画につなぐ（Bug #146）— 本番反映済み

設計書（短）: @docs/superpowers/specs/2026-11-22-lot-link-on-add-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-22-lot-link-on-add.md

上の「土地の仕入れ案件に区画を複数登録できるようにする」の節の「利用者に伝えること」の 1 つ目（#21 の形）を、利用者の依頼（2026-11-22・案 A＝自動でつなぐ）で直した（docs/RULES.md の Bug #146）。
作業場所 `.claude/worktrees/lot-link-on-add`・枝 `lot-link-on-add`（13.x = `790f502f` から）。**DB 変更・ルート変更・依存の変更は無し。新しい PHP クラスが 1 つ**（`App\Support\ProcurementLotLinks`。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る）。

### 直す前（2026-11-22・使い捨てのテストで実測）

区画の無い土地（販売済）に区画を選んでいない契約 1 件（本番の #21 の形）:

| 手順 | 直す前 |
|---|---|
| 詳細の追加のフォーム | ステータスの初期値は「成約」（`ReProcurement::defaultLotStatus()`） |
| そのまま登録 | 区画は成約・契約の区画は空のまま（つながらない）・土地は販売済 |
| 区画を「販売中」にして、その区画で 2 件目の契約を登録 | 入力エラーなしで通り、同じ土地に契約が 2 件 |
| 区画を成約に戻してから、区画を選んでいない契約を削除 | 区画は成約のまま・土地も販売済のまま（契約の登録画面の選択肢から消える） |

- 建売と契約以降の注文住宅は、土地の欄が読み取り専用（Bug #142）なので、区画を足してもあとからつなぐ道が無かった
- 区画のある土地で区画の無い見積りの注文住宅は、一覧の小窓で「契約」へ進めると 200 で通った（区画を選んでいない契約以降の注文住宅が新しくできる）
- 本番（2026-11-21 の読み取り）: 区画を選んでいないものがある土地は #21 だけ（区画 0・契約 1）。仕入れ案件を土地元にした建売・注文住宅は 0 件。区画があるのに区画を選んでいないものが残る土地は 0 件 ＝ データの手直しは要らない

### 直したこと

| 区分 | 実装内容 |
|------|---------|
| 顔ぶれ | `ProcurementLotClaims::unlinkedTakers()`（その土地を土地元にしていて区画が空の、不動産の契約・建売〈契約の前も〉・契約以降の注文住宅＝区画を取ると数える顔ぶれと同じ。見積り以前の注文住宅は入れない）・`lotMissing()`（区画のある土地で区画を選んでいないか） |
| 候補とつなぎ方 | 新しいクラス `App\Support\ProcurementLotLinks`: `candidates()`（キー・名前・つないだあとの区画のステータス・持ち主）・`fingerprint()`（開いたときの候補）・`link()`（持ち主の区画の列だけを `toBase()->update()` で書く） |
| 区画の登録 | `ProcurementLotController::store()`: 候補があれば、選んだ（1 件なら hidden の）持ち主に足す区画をつなぐ。区画のステータスはサーバーが決める（契約・契約のある建売・契約以降の注文住宅 → 成約／契約の無い建売 → 販売中）。区画の作成・つなぐ書き込み・集約は 1 つのトランザクション。顔ぶれが開いたときと違えば何も保存せず入力エラー。区画の行の編集（更新）は変えない |
| 画面 | `_lot_section.blade.php`: 候補 1 件なら「この区画は契約「…」に紐づけます（区画のステータスは「成約」になります）。」と hidden・2 件以上なら必須の選択欄「この区画に紐づけるもの」（「— 選んでください —」・選択肢は「建売物件「HS-001 …」（区画は販売中）」の形）。どちらもステータスの欄を出さない。候補の無い土地は今のまま（ステータスの初期値は「販売中」） |
| 決まり 7 | 区画のある土地で、区画を選んでいない見積りの注文住宅は「契約」へ進めない（一覧の小窓は 422 で alert・編集画面は区画の欄の誤り）。`ReProcurement::defaultLotStatus()` は消した |
| テスト | 新規 `ProcurementLotLinkScreensTest`（9 メソッド）・`ProcurementLotClaimsTest` に 5 本・`ProcurementLotCustomOrderTest` に 4 本。`ProcurementLotModelTest` の `defaultLotStatus()` の 7 本を消した。5586 → **5606 tests / 66848 assertions green**（PHP 8.3・警告なし） |
| ルート / DB | **どちらも変更なし** |

文:
- 帯: 「区画を登録しました（契約「勝山の土地（山田 太郎 様）」をこの区画に紐づけました）。」（名前は削除の歯止めと同じ形）
- 選ばずに送った: 「この区画に紐づけるものを選んでください。」（ブラウザは `required` で先に止める）
- 開いたあとで顔ぶれが変わった: 「区画を選んでいない契約・建売物件・注文住宅が、画面を開いたあとで変わりました。紐づけるものを確かめて、もう一度登録してください。」
- 決まり 7（小窓）: 「区画のある土地で区画を選んでいないため、「契約」へ進められません。編集画面で区画を選んでから進めてください。」／（編集画面）「…「契約」へ進められません。区画を選んでください。」

### 決めたこと

- 利用者の決定（2026-11-22）: 案 A＝区画を選んでいないものが 1 件なら自動でつなぎ、2 件以上なら追加のフォームで選ぶ（「つながない」は無い）。案 B（フォームでいつも選ぶ）・案 C（つながず新しい契約を止める）は採らなかった
- 設計書の「こちらの判断」: 決まり 7（区画のある土地で区画なしの見積りの注文住宅を「契約」へ進めない）
- 実装中の Ruling（台帳 `.superpowers/sdd/2026-11-22-lot-link-on-add/progress.md`）: 帯の文の定数名は `LINKED_ON_ADD`（`LINKED` は削除の断りで使っていた）／全件で見つけた `ProcurementLotStatusSyncTest` の最後の段を決まり 7 に合わせた／変異の実行役に和名と `old()` の走査 2 本も足した
- 最後の点検の Ruling: 古くなったコメント 2 か所（`ReProcurement::statusRefusal()` の「#21 の形」・`ProcurementLotClaims` の「最初の区画が成約で作られても」）を記録のコミットで直した（動きは変わらない）

### 検証

- 全件 **OK (5606 tests, 66848 assertions)**（PHP 8.3・`--display-phpunit-deprecations`・警告なし）／ コンパイル済みビュー **327 本 / INVALID 0**
- 変異 24 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/run_lla.py`・表は `specs_lot_link_on_add.py`・結果は `results-lot-link-on-add*`）すべて期待どおり（緑のまま通った変異 0。H4 は狙ったテストのほかにもう 1 本が同じ理由で落ちた）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright・作業場所でビルドした CSS は本番と同じ名前 `app-o1yvrcrl.css`）: #21 の形の土地で 1 件の案内 → 登録で区画は成約・「紐づいているもの」に契約 → 契約を削除で区画も土地も販売中 ／ 区画を選んでいない建売 2 件と契約の注文住宅の土地で、選ばずに押すとブラウザが止める・サーバーも断る（打った値は残る）→ 選ぶと契約のある建売は成約・契約の無い建売は販売中・注文住宅は成約・全部つながるとステータスの欄に戻る ／ 開いたあとで契約 2 件を足してから送ると顔ぶれの文で何も保存されず、今の 2 件の選択欄で選び直して登録できる ／ 注文住宅の一覧の小窓で区画なしの見積りを「契約」へ → alert・見積りのまま ／ 375px・1440px とも `main` の横スクロール 0・文と選択欄は枠の中・コンソールのエラーは意図した 422 の 1 件だけ
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ）: 「マージしてよい」・Critical・Important 0・Minor 6（下の範囲外と利用者に伝えること）

### 利用者に伝えること

- **#21 に区画を足すと、追加のフォームに「この区画は契約「…」に紐づけます」と出て、登録するとその契約につながる**（区画は成約。契約を消せば区画も土地も販売中に戻る）
- **区画を選んでいないものが 2 件以上ある土地では、区画を足すたびにどれにつなぐかを選ぶ**（「つながない」は無い。区画が足りれば全部つながる。足りないあいだは空いた区画ができない）
- **区画のある土地で、区画を選んでいない見積りの注文住宅は「契約」へ進められない**（編集画面で区画を選んでから進める）
- 区画を足した直後に、開きっぱなしだった契約済みの注文住宅の編集画面から保存すると「土地の紐付けは変えられません」が出る（開き直せば保存できる。データは壊れない）（→ 2026-11-23 に直した。開いていた画面からそのまま保存でき、つないだ区画が残る）
- 2 人がほぼ同時に同じ土地へ区画を足すと、まれに 1 つの区画が紐付けなしの成約で残りうる（区画の表の「紐づいているもの」が空の成約の区画があれば、区画の表で販売中にする）（→ 2026-11-23 に直した。後の保存は何も保存せず断る）

### 範囲外・後回し

- 設計書の範囲外: 区画の行の編集でつなぐこと ／ ほぼ同時の 2 つの保存（ロックは足さない）／ すでに区画があり、区画を選んでいないものも残る土地の空いている区画（本番 0 件。次に区画を足すとつながる）
- 契約済みの建売を削除すると、つないだ区画が成約のまま残る（前からの範囲外。つながる建売が増えるぶん当たりやすくなる）（→ 2026-11-23 に直した。下の「契約済みの建売の削除で区画を戻す ／ 区画を足すときつなぐ変更の残り」の節・Bug #147）
- 最後の点検の Minor（直していない）: 候補が開いたあとで 0 件になった土地へ候補ありの画面から送ると、顔ぶれの断りに「ステータスは必須です。」が並ぶ ／ 同時の 2 つの区画の追加で後の保存が持ち主の区画を上書きする（`link()` の更新に `whereNull` を足して 0 行なら巻き戻せば塞げる）／ 候補の指紋が建売の契約の有無を含まない（開いたあとで建売に契約が付くと、画面の「区画は販売中」と違い成約で保存される。サーバーの値は正しい）／ 上の「利用者に伝えること」の 4 つ目（→ 4 つとも 2026-11-23 に直した。下の「契約済みの建売の削除で区画を戻す ／ 区画を足すときつなぐ変更の残り」の節・Bug #146 の追記）

### 本番反映の手順

**DB 変更・ルート変更は無い**（SQL は流さない）。どの段も利用者の承認を取ってから。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・`git merge-base --is-ancestor 13.x HEAD` と `ListAgents` で確かめる
2. 本番の読み取り（`ssh … /bin/sh < 手順ファイル`）: 変わるファイルの md5 が今の 13.x と一致・`ProcurementLotLinks.php` が無い・`laravel.log` が Nov 18 01:45 のまま・区画を選んでいないものがある土地の件数（#21 のほか）
3. main repo で `git checkout 13.x && git merge --ff-only lot-link-on-add` → main repo の cwd で **`composer dump-autoload --no-dev --optimize`**（PHP 8.3。`ProcurementLotLinks` が表に入り、作業場所のパスが 0・`vendor/bin/phpunit` が無い）
4. `./deploy.sh`
5. 反映の後の読み取り: 送ったファイルの md5・コンパイル済みビューの lint・`laravel.log`・本番の PHP で #21 の候補が契約 1 件になること（保存はしない）
6. ログインした画面（見るだけ・送信しない）: #21 の詳細の追加のフォームに案内が出る・ステータスの欄が無い
7. 作業場所を消す前に、`.claude/route-coverage-tools/mutate/` の `run_lla.py`・`specs_lot_link_on_add.py`・結果を main repo の同じ場所へ写す

### 本番反映（2026-11-23 実施）

利用者の承認を段ごとにとって、上の手順どおりに流した（本番の読み取り → 早送りと dump-autoload と `./deploy.sh` → 反映のあとの読み取り）。早送りの前に `git merge-base --is-ancestor 13.x lot-link-on-add` と `ListAgents` で、13.x が進んでいないこと・13.x を動かすほかの会話が無いことを確かめた。main repo の `13.x` を `790f502f` から **`c7c6f8ed`** へ早送りした（push なし）。**DB 変更・ルート変更・依存の変更は無し**（SQL は流していない）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 本番の読み取り（反映の前・読むだけ） | 変わる 9 本の md5・新しいクラス・記録・ビルド・件数 | 9 本とも `790f502f` と一致＝本番は 13.x のまま ／ `ProcurementLotLinks.php` は無い ／ `laravel.log` は Nov 18 01:45（3333 行）／ build は `app-o1yvrcrl.css`・`app-NiVQbl_Q.js` ／ `vendor/bin/phpunit` は無い ／ 区画を選んでいないものがある仕入れ案件 6 件のうち土地は **#21 だけ**（区画 0・契約 1。ほかの 5 件は中古戸建・中古マンションで区画の欄が無い）／ 決まり 7 で「契約」へ進めなくなる注文住宅 0 件 ／ 区画 2 件（#9・#18） |
| 2 早送りと読み込みの表 | main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3） | 7,428 クラス・`ProcurementLotLinks` が表に入った・作業場所のパス 0・`vendor/bin/phpunit` は無い・作業ツリーは空 |
| 3 `./deploy.sh` | 2026-11-23 14:03 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 10 本（コントローラ 3・モデル 2・Support 2〈うち新 1〉・lang 1・ビュー 2）と読み込みの表 2 本とビルドだけ。CSS・JS の名前は前と同じ（ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 4 本番の読み取り（反映の後・読むだけ） | md5・構文・ビュー・記録・キャッシュ・ビルド・#21 の候補 | 12 本とも手元（`c7c6f8ed`）と **md5 が一致**・PHP 8 本の `php -l` ok ／ コンパイル済みビュー **328 本 / INVALID 0** ／ `laravel.log` は Nov 18 01:45 のまま＝反映のあとのエラー 0 件 ／ `bootstrap/cache` は 14:03 に作り直し（`config.php`・`routes-v7.php` 600、名簿 2 つ 700）／ build はアプリ側・公開側とも前と同じ 2 本 ／ 本番の PHP で `ProcurementLotLinks::candidates()`（保存はしない）: #21 は候補 1 件（契約・つないだ区画は成約）・#9・#18 は 0 件 |
| 5 外からの確かめ（ログインしない・送らない） | ログイン画面・#21 | ログイン画面 200・`app-o1yvrcrl.css` を読む ／ #21 はログイン画面へ 302 |
| 6 ログインした画面（利用者の Chrome・見るだけ・送信しない） | #21 の詳細 | 追加のフォームに「この区画は契約「星岡（… 様）」に紐づけます（区画のステータスは「成約」になります）。」・ステータスの欄なし・hidden の `lot_link` と候補は `contract:8`・送り先は #21 の区画の登録・号地 1・面積 952.00 ／ 同じオリジンの iframe で 375px・1440px とも `main` の横スクロール 0・案内の文は枠の中 ／ 読み込み直したときのコンソールのエラー 0 件 ／ CSS は `app-o1yvrcrl.css` |

⚠ ログインした画面（6）は、反映の直後は Claude in Chrome の拡張につながらず（2 回試した）、利用者が Chrome にログインし直したあとで見た。区画の登録は本番では送っていない（本番のデータを変える操作のため。#21 に区画を足すのは利用者）。

⚠ `origin/13.x` への push は反映のときはしていない。→ 2026-11-23 に利用者の指示で push 済み（`790f502f..15843f6a`。この注記の直しも push 済み）。

## ✅ 契約済みの建売の削除で区画を戻す ／ 区画を足すときつなぐ変更の残り（Bug #147・#146 の追記）— 本番反映済み

設計（短）: @docs/superpowers/specs/2026-11-23-lot-link-followups-design.md
実装計画（実測記録つき）: @docs/superpowers/plans/2026-11-23-lot-link-followups.md

上の「区画を足すとき、区画を選んでいない契約などをその区画につなぐ（Bug #146）」の節の範囲外・後回しに残した 5 つを、利用者の依頼（2026-11-23「A と B を一緒に進めて」）で直した（docs/RULES.md の Bug #147 と Bug #146 の追記）。
作業場所 `.claude/worktrees/lot-link-followups`・枝 `lot-link-followups`（13.x = `7267e8b1` から）。**DB 変更・ルート変更・依存の変更・新しい PHP クラスは無し**（本番反映は `./deploy.sh` だけでよい）。

### 直したこと

| # | 直す前 | 直した後 |
|---|---|---|
| A（Bug #147） | 契約のある建売を削除すると、土地の区画と分譲地の区画が「成約」のまま残る（契約は連鎖削除で消え、区画を戻す処理が動かない）。土地は「販売済」のまま選択肢から消える | 区画を販売中に戻す（土地のステータスは区画から集約・分譲地のステータスも集約）。契約の無い建売の削除は今のまま。削除と 1 つのトランザクション |
| B1 | 2 人がほぼ同時に同じ候補を選んで区画を足すと、後の保存が持ち主の区画を上書きし、先の区画が「成約・紐付けなし」で残る | 後の保存は何も保存せず「区画を選んでいない契約・建売物件・注文住宅が、画面を開いたあとで変わりました。…」で断る |
| B2 | 画面は「（区画は販売中）」と出したのに、開いたあとで建売に契約が付くと「成約」で保存される | 指紋にステータスも入れたので、顔ぶれの断りで止まる（画面のとおりに保存される） |
| B3 | 区画を足した直後に、その前から開いていた編集画面から保存すると「土地の紐付けは変えられません」「区画を選んでください」で断られる（選ぶ欄の無い画面で） | 土地を変えられない画面（建売の編集・契約済みの注文住宅の編集と契約の編集）はそのまま保存でき、つないだ区画が残る。区画を選べる不動産の契約の編集は「区画を選んでください。この契約は今、区画（1号地）に紐づいています（画面を開いたあとで紐づけられた可能性があります）。」で、もう一度押すと保存できる |
| B4 | 開いたあとで候補が 0 件になった土地から送ると、顔ぶれの断りに「ステータスは必須です。」も並ぶ | 顔ぶれの断りだけ |

### 決めたこと

- 利用者の決定（2026-11-23）: B3 は案 1（土地を変えられない画面は区画を残して通す・不動産の契約の編集は理由の分かる文で断る）
- 設計書の「こちらの判断」: A は分譲地の区画も戻す ／ A はモデルの削除のフックにしない（注文住宅の削除と同じく削除の経路から呼ぶ）／ B1 はロックを足さず UPDATE の条件で閉じる
- 実装中の Ruling: 変異の表を書く途中で、守りの条件 2 つ（`lotKeptFor()` の保存済みの種別・`LOT_HELD` の同じ土地）にテストが無いと分かり、変異の前に 2 本足した

### 検証

- 全件 5606 → **5632 tests / 67316 assertions green**（PHP 8.3・`--display-phpunit-deprecations` で警告 0）／ コンパイル済みビュー **327 本 / INVALID 0**
- 変異 24 通り（カナリアを含む・作業場所の `.claude/route-coverage-tools/mutate/run_llf.py`・表は `specs_lot_link_followups.py`・結果は `results-lot-link-followups.*`。関係するテスト 1092 本で）すべて期待どおり（1 回目は A4・H6 の期待の表が狭かった＝検出は正しい。直して流し直し MATCH）
- 実ブラウザ（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright）: 契約のある建売を詳細から削除 → 土地の区画も土地も販売中 ／ 追加のフォームの指紋に `property:2=on_sale` ／ 開いていた建売の編集画面から保存 →「建売物件「HS-102」を更新しました。」・区画が残り土地原価は保存値のまま ／ 開いていた不動産の契約の編集画面から保存 → 新しい文・メモは残り区画が選ばれた状態 → もう一度押して「契約情報を更新しました。」 ／ 375px・1440px で `main` の横スクロール 0 ／ コンソールのエラーは使い捨ての DB に `ms_*` が無い経営ダッシュボードの 1 件だけ
- 最後の点検（別の担当・いちばん強いモデル・読み取りだけ）: 「直してからマージ」・Critical 0・Important 1（Review Focus 4 のテストが保存値と計算値の同じ値どうしで見分けない → 保存値を 12,345,678 円にし、いつも計算し直す変異で赤を確かめた）・Minor 6（下の後回し）

### 利用者に伝えること

- **反映の前から開いていた区画の追加の画面は、1 回だけ「…画面を開いたあとで変わりました」で断られる**（指紋の形が変わったため。開き直せば登録できる。本番で候補のある土地は #21 だけ）
- 開いていた不動産の契約の編集画面から保存して上の文が出たら、そのままもう一度「更新する」を押せばよい（区画は選ばれた状態で戻る）

### 範囲外・後回し

- 建売の契約の削除・注文住宅の削除がトランザクションでない（前から）／ 分譲地の区画を 2 つの建売が共有していると、片方の削除で販売中に戻る（分譲地の区画に「1 区画 1 件」の決まりが無い・前から）／ `link()` は書く瞬間に注文住宅の段階・建売の契約の有無を見直さない（指紋の約束・ロックは足さない）
- 最後の点検の Minor（直していない）: 削除が落ちたときのテストの `ReProcurementLot::count()` は何も確かめない ／ `lotKeptFor()` は保存済みの仕入れ案件が空で送られた値も空なら「同じ土地」とみなす（種別が仕入れ案件・区画あり・仕入れ案件なしの壊れたデータだけ）／ 建売の削除のファイルの物理削除がトランザクションの外・前（前から）／ 成約にする処理はコントローラ・戻す処理はモデルに分かれた ／ B1 の画面のテストは持ち主が契約の場合だけ（住宅事業は `link()` の単体テストと変異が守る）／ 建売の削除のコメントが少し読みにくい

### 本番反映の手順

**DB 変更・ルート変更・新しいクラスは無い**（SQL も `composer dump-autoload` も要らない）。どの段も利用者の承認を取ってから。

1. 反映の前: `13.x` が進んでいれば枝へ取り込んで全件を流し直す・`git merge-base --is-ancestor 13.x HEAD` と `ListAgents` で確かめる
2. 本番の読み取り（`ssh … /bin/sh < 手順ファイル`）: 変わるファイルの md5 が今の 13.x と一致・`laravel.log` が Nov 18 01:45 のまま・**契約済みの建売の削除で成約のまま残った区画**（何にも紐づいていない成約の土地の区画・分譲地の区画）の件数
3. main repo で `git checkout 13.x && git merge --ff-only lot-link-followups` → `./deploy.sh`
4. 反映の後の読み取り: 送ったファイルの md5・コンパイル済みビューの lint・`laravel.log`
5. 作業場所を消す前に、`.claude/route-coverage-tools/mutate/` の `run_llf.py`・`specs_lot_link_followups.py`・結果を main repo の同じ場所へ写す（2026-11-23 に写した）

### 本番反映（2026-11-23 実施）

利用者の指示（「1 で13.xへ早送りして本番へ反映してください」）で、main repo の `13.x` を `7267e8b1` から **`f72523ad`** へ早送りし（push なし）、`./deploy.sh` を流した。早送りの前に `git merge-base --is-ancestor` と `ListAgents` で、13.x が進んでいないこと・13.x を動かすほかの会話が無いことを確かめた。**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（SQL も `composer dump-autoload` も流していない）。直前に統合する木で全件 **OK (5632 tests, 67316 assertions)**（PHP 8.3・警告 0）。

| 段 | 見たこと | 結果 |
|---|---|---|
| 1 手元 | main repo | 13.x で作業ツリーは空・`vendor/bin/phpunit` は無い ／ 本番へ出るアプリのファイルは 9 本（コントローラ 6・モデル 1・trait 1・Support 1）。composer・DB・ルート・設定の差なし |
| 2 `./deploy.sh` | 2026-11-23 16:45 ごろ終了（日本時間） | exit 0・6 段すべて。送ったアプリのファイルは 9 本だけとビルド（CSS・JS の名前は前と同じ `app-o1yvrcrl.css`・`app-NiVQbl_Q.js`・旧バンドルの削除 0 件。ビルドの警告 1 件は以前からの Google Fonts の `@import`）。部品の名簿の作り直しと 3 つのキャッシュとも成功 |
| 3 外からの確かめ（ログインしない・送らない） | ログイン画面・仕入れ案件の詳細 | ログイン画面 200・`app-o1yvrcrl.css` を読む ／ 仕入れ案件の詳細はログイン画面へ 302 |

⚠ 本番の読み取り（送ったファイルの md5・コンパイル済みビューの lint・`laravel.log`・契約済みの建売の削除で成約のまま残った区画の件数）は、反映のときはしていない（承認が要る）。区画の追加・建売の削除・編集の保存は本番では送っていない（テスト・変異・使い捨ての環境の実ブラウザで確かめてある）。

⚠ `origin/13.x` への push はしていない。

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

**工程表ボードのガント改修（2026-09-03〜04）と、テナント契約一覧の契約日順・見出しの並び替え（2026-09-11）は、
2026-09-11 に一緒に本番反映済み**（上記の 2 節。最終 `13.x` = `e9a899d7`）。本番でコンパイル済みビュー 267 本の
lint（INVALID 0）と、ログイン済みの実 Chrome での目視まで確認した。
⚠ **ガント改修の既知の未修正（初期スクロールが 220px 手前に着地する）が本番の住宅の工程表ボードで出ていた。**
同日に修正して**本番反映済み**（呼び出しを DOMContentLoaded まで待たせる。`13.x` = `60ea80cb`。本番で右端に止まることを実測）。

**地下区画の表記（`-1B1A` ／ 修繕の `33A` ／ フロアマップの `-1F`）と、横スクロールのヒント（4 一覧・PC 展開サイドバーの `x-cloak`・開閉後の測り直し）の修正（2026-09-12〜13）も 2026-09-13 に本番反映済み**（上記の節。本番の実データで 4 一覧 × 7 幅・修繕の選択肢 151 件・フロアマップの `B1F` まで確認）。

**論理削除した区画を参照する画面の修正（2026-09-14）も同日に本番反映済み**（上記の節。本番の解約済み契約 C-1991-001 が契約詳細・契約一覧・物件詳細・顧客詳細で「5A（削除済み）」と出ることまで確認）。

**削除した物件を参照する画面（物件の削除の歯止め）と、区画の CSV 取込の削除済み同名区画（2026-09-14）も同日に一緒に本番反映済み**（上記の 2 節。`13.x` = `3e9647f2`。本番のコンパイル済みビュー 269 本の lint（INVALID 0）と、読み取りだけの画面確認まで）。

**日時の表示と「今日」の日本時間化（2026-09-22〜23）は 2026-09-23 に本番反映済み**（上記の節。`13.x` = `e6787c20`）。

**月末の溢れ（前月・来月・過去 18 か月。2026-09-23）は 2026-09-24 に本番反映済み**（上記の節。日付ピッカーの「1ヶ月前」と一緒に反映した）。

**日付ピッカーの「1ヶ月前」（2026-09-24）も同日に一緒に本番反映済み**（上記の節。`13.x` = `999729f2`。本番の 7 画面が 200・コンソールのエラー 0 件、「1ヶ月前」で今日の 1 か月前（2026-08-24）が選ばれることまで確認。賃貸マンションの改定・解約の 4 画面は、本番に契約が無いので未確認）。

**決裁申請 段階1（基幹の改修）は 2026-09-25 に本番反映済み**（上記の節。`13.x` = `6e9b59fc`）。DB の変更 12 文 →
`composer install --no-dev`（chillerlan/php-qrcode）→ main repo での `composer dump-autoload` → `./deploy.sh` の順で流し、
本番のコンパイル済みビュー 274 本の lint（INVALID 0）と、ログイン済みの実 Chrome で決裁の画面が誰にも見えないこと
（管理系 2 画面が 403・サイドバーに「決裁」なし）まで確認した。段階0（定期実行・メール送信の土台・暗号化バックアップ）は本番稼働中。
⚠ **メールアドレスでのログインと Safari での案内の印刷は、利用者の確認待ち。** 社員の一括登録とログイン案内の配布は稼働の直前まで行わない。

**CSV 取込の確認画面から断られると 405 になる件（基幹の取込 4 本）も同日に本番反映済み**（上記の節。`13.x` = `b06f89ac`。
本番の 4 本が手元と md5 まで一致・取込の 6 画面が 200）。

**取込の入力チェックの包み忘れを止める走査テスト（2026-09-25）**はテストと文書だけの変更で、本番への反映は要らない（上の節。`13.x` へ早送りするだけ）。2026-09-25 に push 済み（`9fffc965` に含まれる）。

**顧客CSVインポートの確定を通す（2026-09-26）は 2026-09-27 に本番反映済み**（上の節。`13.x` = `a7335c27`。本番の 3 本が手元と md5 まで一致・コンパイル済みビュー 274 本の lint（INVALID 0）・取込画面の初期フォームをログイン済みの実 Chrome で確認。Web の `post_max_size` は 512M の可能性が高い）。⚠ 本番で実際に取り込む（確定まで）のは利用者。

**顧客CSVインポートの二重送信を止める（2026-09-27）は 2026-09-28 に本番反映済み**（上の節。`13.x` = `df213c7b`。本番の 3 本が手元と md5 まで一致・コンパイル済みビュー 274 本の lint（INVALID 0）・本番の CSS が手元のビルドとバイト一致・取込画面の初期フォームをログイン済みの実 Chrome で確認）。⚠ 二度押し止めと鍵はプレビューのあとにしか出ないので本番では見ていない。本番で実際に取り込む（確定まで）のは利用者。

**日本語だけで拡張子の無い名前の添付の 500（2026-09-28）は 2026-09-28 に本番反映済み**（上の節。`13.x` = `e4e48188`。本番の 1 本が手元と md5 まで一致・コンパイル済みビュー 274 本の lint（INVALID 0）・本番の添付のうち直す前に開けなかった名前は 0 件）。

**決裁申請 段階2a（申請して社長の決裁まで一通り回る）は 2026-09-28 に本番反映済み**（上の段階2 の節。`13.x` = `b28881d8`。DB を先に 11 文・`./deploy.sh`・本番のビュー 282 本の lint〈INVALID 0〉・ルート 39 本・`launched_at` は空。申請を回す画面は使い始める日〈段階6〉まで誰にも見えないことを、ログインした画面でも確かめた）。**2b（出し直しの変更点と履歴・進行中の申請の管理・基幹のメニューの件数）は 2026-09-30 に本番反映済み**（上の段階2 の節の「2b」。`13.x` = `3d18815d`。表の変更なし・`./deploy.sh` だけ・本番のアプリのファイルが手元と md5 まで一致・ビュー 288 本の lint〈INVALID 0〉・ルート 43 本・`launched_at` は空のまま）。

**ほかの取込の二重送信を止める（2026-09-28）は 2026-09-29 に本番反映済み**（上の節。`13.x` = `b5576d7e`。本番の 14 本が手元と md5 まで一致・コンパイル済みビュー 283 本の lint（INVALID 0）・取込の画面 6 つが 200 でコンソールのエラー 0 件・周辺ビルの画面で鍵（40 文字）と二度押し止めの部品を確認）。⚠ 確認画面の二度押し止めと鍵はプレビューのあとにしか出ないので本番では見ていない。本番で実際に取り込む（確定まで）のは利用者。

**契約の上げ直しで二重にしない（2026-09-29〜30）は 2026-09-30 に本番反映済み**（上の節。`13.x` = `e942596d`。反映の前の読み取りで、見分けのキーが同じテナントの契約が 1 組〈同じ入居の 2 回の登録。利用者が同日に後から入ったほう `C-2023-005` を削除し、今は 0 組〉・本番の 6 本が手元と md5 まで一致・コンパイル済みビュー 288 本の lint（INVALID 0）・取込の画面 2 つが 200 で契約の 4 タブに説明の 1 行・コンソールのエラー 0 件）。⚠ 確認画面のスキップの一覧はプレビューのあとにしか出ないので本番では見ていない。本番で実際に取り込む（確定まで）のは利用者。

**同名の顧客の契約も上げ直しで二重にしない（2026-09-30）は 2026-09-30 に本番反映済み**（上の節。`13.x` = `f5902b27`。テナントの契約・過去契約タブの照合を、顧客の id から名前〈同名の顧客のどれか〉へ。本番のコントローラが手元と md5 まで一致・コンパイル済みビュー 288 本の lint（INVALID 0）・取込の画面の 2 タブが 200 でコンソールのエラー 0 件）。

**同名の顧客の紐づけ先を最初に登録された顧客に固定する（2026-10-01）は 2026-10-01 に本番反映済み**（上の節。`13.x` = `1e52e132`。テナントの契約・過去契約タブで、新しい契約を同名の顧客のうち最初に登録された顧客に紐づける〈`orderBy('id')`〉。本番の同名の組は 0 組・本番のコントローラが手元と md5 まで一致・コンパイル済みビュー 288 本の lint（INVALID 0））。

**周辺ビルのテナント明細の上げ直しで二重にしない（2026-10-01〜02）は 2026-10-02 に本番反映済み**（上の節。`13.x` = `a32a766b`。ビル・階・部屋番号・テナント名が同じ登録済みの行〈退去済みも含む。名前が空欄の退去済みの行は除く〉は、登録済みの行の数までスキップし、書き換えない。全件 3252 本・変異 28 通り・ローカルの実ブラウザ 6 項目・独立レビューの指摘に対応。本番のテナント明細は反映の前に 0 行・本番の 2 本が手元と md5 まで一致・コンパイル済みビュー 294 本の lint（INVALID 0）・取込の画面で新しい注意書き）。⚠ 実際に取り込むのは利用者。

**ZEAL 会員の取込の確定で DB の例外が出ると 500 になる件（2026-10-02）は 2026-10-03 に本番反映済み**（上の節。`13.x` = `5edebe4c`。本番の列に入らない値をその行のエラーにし〈B〉、それでも起きた DB の例外は受け止めて全員を取り消し、取込の画面へ戻して理由を出す〈A〉。全件 3320 本・変異 19 通り＋カナリア）。

**ZEAL 会員の画面の入力チェックを列に合わせる（とプラン変更の 500）（2026-10-02）は 2026-10-03 に本番反映済み**（上の節。`13.x` = `5edebe4c`。本番の 7 本が手元と md5 まで一致・コンパイル済みビュー 296 本の lint（INVALID 0）・会員の画面 3 つが 200 でコンソールの出力 0 件。メール・プラン変更の備考・適用価格・選択肢の 3 項目を本番の列に合わせ〈Bug #73〉、どの値でも 500 だったプラン変更を直した〈Bug #74〉。全件 3336 本・変異 15 通り＋カナリア）。

**ZEAL のテストで一度も通らないルートをなくす（2026-10-04）は 2026-10-04 に本番反映済み**（上の節。`13.x` = `2deef25f`。25 本に画面から成功まで通るテストを足し、見つけた欠陥 3 つ〈Bug #75〜#77〉とテストで通せなかった会員一覧の SQL を直した。ZEAL の「一度も通らない」は 25 → 0。本番の 4 本が手元と md5 まで一致・コンパイル済みビュー 296 本の lint（INVALID 0）・会員一覧と試算表の画面をログインした Chrome で確認・コンソールのエラー 0 件）。

**各マスタのテストで一度も通らないルートをなくす（2026-10-04）は 2026-10-05 に本番反映済み**（上の節。`13.x` = `3390b5d7`。38 本に画面から成功まで通るテストを足し、見つけた欠陥〈Bug #78〜#82・Bug #76 の残り・Bug #73 と同じ形〉を直した。各マスタの「一度も通らない」は 38 → 0。本番の 13 本が手元と md5 まで一致・コンパイル済みビュー 296 本の lint（INVALID 0）・ZEAL 試算表の項目の新規登録画面が 200〈直す前は 500〉・マスタと編集の 9 画面をログインした Chrome で確認・コンソールのエラー 0 件）。

**テナントのテストで一度も通らないルートをなくす（2026-10-05）は 2026-10-05 に本番反映済み**（上の節。`13.x` = `3813a309`。23 本に画面から成功まで通るテストを足し、見つけた欠陥〈Bug #83〜#86・Bug #73 と同じ形〉を直した。テナントの「一度も通らない」は 23 → 0。本番の 9 本が手元と md5 まで一致・コンパイル済みビュー 296 本の lint（INVALID 0）・契約の編集など 11 画面をログインした Chrome で確認・コンソールのエラー 0 件）。

**不動産のテストで一度も通らないルートをなくす（2026-10-05）は 2026-10-05 に本番反映済み**（上の節。`13.x` = `e5a2406d`。34 本〈と不動産の API 3 本〉に画面から成功まで通るテストを足し、見つけた欠陥〈Bug #87〜#92・Bug #73 と #76 と同じ形〉を直した。顧客の画面は住宅事業と共用なので、顧客の入力チェックの直しは住宅事業にも効く。不動産の「一度も通らない」は 34 → 0。本番の 11 本が手元と md5 まで一致・コンパイル済みビュー 298 本の lint（INVALID 0）・契約の登録・仕入れ案件の詳細と編集・分譲地の詳細・顧客の登録の 5 画面をログインした Chrome で確認・コンソールのエラー 0 件）。

**住宅事業のテストで一度も通らないルートをなくす（2026-10-06）は 2026-10-06 に本番反映済み**（上の節。`13.x` = `97da2c87`。27 本〈と住宅事業の画面が使う API 5 本〉に画面から成功まで通るテストを足し、見つけた欠陥〈Bug #93〜#99・Bug #73 と #76 と同じ形〉を直した。住宅事業の「一度も通らない」は 27 → 0。本番の 21 本が手元と md5 まで一致・コンパイル済みビュー 298 本の lint（INVALID 0）・注文住宅の編集の区画（H1）など 11 画面をログインした Chrome で確認・コンソールのエラー 0 件）。

**賃貸マンションのテストで一度も通らないルートをなくす（2026-10-07）は 2026-10-07 に本番反映済み**（上の節。`13.x` = `a64bdba2`。43 本〈と契約の登録画面が叩く API 2 本〉に画面から成功まで通るテストを足し、見つけた欠陥〈Bug #101〜#109・Bug #29・#37・#73・#76・#85・#93・#96・#97 と同じ形〉を直した。本番の部屋・契約はまだ 0 件で、どれも発火前。賃貸マンションの「一度も通らない」は 43 → 0。本番の 21 本が手元と md5 まで一致・コンパイル済みビュー 299 本の lint（INVALID 0）・ダッシュボード・物件の編集（削除の欄が保存バーに隠れない）など 5 画面をログインした Chrome で確認・コンソールのエラー 0 件）。

**入力エラーの項目名（和名）の漏れを直し、走査を広げる（2026-10-07）も同日に一緒に本番反映済み**（上の節。`13.x` = `a64bdba2`。不動産の契約の 5 項目と DAD の原価の 3 項目に和名を足し、和名の走査をリテラルの `validate([...])` 以外の書き方へ広げた〈Bug #110〉）。

**DAD のテストで一度も通らないルートをなくす（28 本）＋ 375px で横にはみ出す画面（2026-10-07）は 2026-10-07 に本番反映済み**（上の節。`13.x` = `2b3a2907`。DAD の 28 本に画面から成功まで通るテストを足し、見つけた欠陥〈Bug #111〜#113・地図の吹き出しに工事名が HTML のまま入る・退職者の配置が保存で消える・人員配置を検査していない ほか〉と、375px のはみ出し〈Bug #114〉を直した。DAD の「一度も通らない」は 28 → 0・全体は 35 → 7。本番の 34 本が手元と md5 まで一致・コンパイル済みビュー 299 本の lint（INVALID 0）・DAD の画面と 375px で直した画面をログインした Chrome で 1440px・375px で確認・コンソールのエラー 0 件。ZEAL の会員の詳細の長いメールアドレスが 375px で 24px 切れる直し残しを見つけた）。

**長いメールアドレス・URL・ファイル名が狭い画面で切れる件と、一覧の絞り込みの行のはみ出し（2026-10-08）は 2026-10-08 に本番反映済み**（上の節。`13.x` = `1fb16ef6`。Bug #115・#116。33 ビュー・57 か所の長い値に折り返し・絞り込みの行 21 本に `sm:flex-wrap`。本番のビュー 54 本が手元と md5 まで一致・コンパイル済みビュー 305 本の lint（INVALID 0）・ZEAL の会員の詳細と 5 つの一覧をログインした Chrome で幅を変えて測った）。

**一覧のページ送りを部品 `<x-pager>` にそろえる（2026-10-13〜22）は 2026-10-26 に本番反映済み**（上の節。`13.x` = `24207618`。Bug #117。31 本〈基幹 29・決裁 2〉と、取り込んだ 5b の紙の決裁の一覧のページ送りを部品に。本番の 42 本が手元と md5 まで一致・コンパイル済みビュー 312 本の lint（INVALID 0）・本番に残った古い `PageNumbers.php` は消した・テナント契約一覧を 375px・1440px で測ってボタン 32px・切れ 0）。

**仕入れ案件・分譲地の地図の吹き出しが名前を HTML のまま描く件（2026-10-26）は 2026-10-26 に本番反映済み**（上の節。`13.x` = `3dc7a6f8`。Bug #119。「マップで確認」の小窓 4 画面と詳細の地図 2 画面で、名前と所在地を逃がしてから吹き出しを組む。本番の 5 本が手元と md5 まで一致・コンパイル済みビュー 312 本の lint（INVALID 0）・4 画面をログインした Chrome で開いて吹き出しが文字として描かれることを確認）。

**仕入れ案件の登録・編集で入力エラーのあと画面の JS が止まる件（2026-10-26）も 2026-10-26 に本番反映済み**（上の節。`13.x` = `887f3a77`。Bug #76 の形。消費税率は文字の欄で、`10\` と打って入力エラーになると戻った画面の JS が止まり消費税率・金額・物件種別の欄が空になっていた → 初期値 8 行を `Js::from` に。本番のフォームが手元と md5 まで一致・コンパイル済みビュー 312 本の lint（INVALID 0）・新規登録と編集の画面をログインした Chrome で開いて JS が組み立てられることを確認）。

**画面の JS に `old()` を引用符で直に書く 41 か所の直しと、`old()` 以外の値の全件分類（アンケートの条件分岐付き選択の 3 か所の直し）（2026-10-26）は 2026-10-26 に一緒に本番反映済み**（上の 2 節。`13.x` = `178ce803`。Bug #76・#97 の形。本番の 21 本が手元と md5 まで一致・コンパイル済みビュー 312 本の lint（INVALID 0）・直した画面と URL を書き換えた 21 通りをログインした Chrome で開いて、どれも 200 で画面の JS がすべて組み立てられることを確認）。

**アンケートの回答の検査と編集画面での読み戻し（2026-10-27）は 2026-10-28 に本番反映済み**（上の節。Bug #120。`13.x` = `6eb55ab3`。本番の 8 本が手元と md5 まで一致・コンパイル済みビュー 315 本の lint（INVALID 0）・アンケートの編集・登録と顧客の登録をログインした Chrome で開いて新しい部品が描かれることを確認）。買主のご来場アンケート（住宅事業・不動産で共用）の回答を保存するときに検査し〈選択肢に無い値・範囲の外の数・長すぎる文字を日本語の理由つきで断る〉、保存した回答を編集画面へ種類ごとに正しく読み戻す〈直す前は数値入力・テキスト入力・数字の単一選択が空に、スライダーが真ん中の値に戻り、そのまま保存すると消える・書き換わった。本番の問 6（予算）の 5 件は、どれも保存された値が 4000〉。顧客の CSV 取込のアンケートの列も画面と同じ形・同じ基準にした。全件テスト 4437 本・変異 37 通り・実ブラウザで確認済み。新しい PHP クラスが 1 つ（`App\Support\SurveyAnswers`）あるので、main repo の cwd で `composer dump-autoload --no-dev --optimize` を流してから `./deploy.sh` で出した（DB 変更なし・本番の回答は書き換えていない）。問 6 の 5 件の 4000 は、担当の方が予算を聞いていなかったと分かったので、同じ日に画面から「回答しない」で消した（上の節）。

**手で組んだ配列の送信で 500 にならないようにする（2026-10-28）は 2026-10-28 に本番反映済み**（上の節。Bug #121。`13.x` = `f78071b8`。形の判定を入力チェックの 1 か所〈`App\Support\ShapeCheckingValidator`〉に置き、形が合わない欄を日本語の理由つきで断り、戻った画面の控えからその欄を外す。送り直しの測定で 保存の 500 = 11 → 3・戻った画面の 500 = 79 → 1〈残りはどれも決裁〉。全件テスト 4560 本・変異 40 通り。本番の 17 本が手元と md5 まで一致・コンパイル済みビュー 315 本の lint（INVALID 0）・直した画面 13 通りをログインした Chrome で開いて確認）。

**欄そのものが無い送信で 500 にならないようにする（2026-10-29）は 2026-10-29 に本番反映済み**（上の節。Bug #92 の形。`13.x` = `8e65e88f`。修繕の登録・更新は区画の欄が無い送信を断り、ZEAL 会員の更新はきっかけ・入会目的の欄が無ければ未設定で保存する。欄を 1 つずつ抜いて送り直す測定で、書き込みの経路 207 本のうち 201 本を測った。本番の 2 本が手元と md5 まで一致・コンパイル済みビュー 315 本の lint（INVALID 0））。

**テスト用スキーマの契約の家賃発生日を本番どおり空欄可にする（2026-10-29）**はテストと文書だけの変更で、本番への反映は要らない（上の節。`13.x` へ早送りするだけ）。

**`old()` の走査を画面のフォームの送り先の規則と突き合わせる（2026-10-30）は 2026-10-30 に本番反映済み**（上の節。Bug #121 の死角。`13.x` = `9dff3423`。走査を直したら、同じ画面の別のフォームの送り先に規則が無い欄〈問合せの詳細の対応履歴の 3 欄・戻った画面が 500〉と、部屋契約の登録の `property_id` が見つかり、どちらも `exclude` を足した。本番のコントローラ 2 本が手元と md5 まで一致・コンパイル済みビュー 315 本の lint（INVALID 0）・`laravel.log` に反映のあとのエラー 0 件）。

**アンケート設問の管理で編集と並び替えを画面からできるようにする（2026-11-02）は 2026-11-03 に本番反映済み**（上の節。Bug #122。ルート 5 → 7・新しい PHP クラス `SurveyQuestionForm`・DB 変更なし。追加・編集を別の画面にし、選択肢・設定・有効／無効・並び順を JSON なしで直せるようにした。回答がある設問の種類は変えさせず、選択肢ごとの回答の件数を出す。顧客の CSV 取込は設問の列の文も今の設問と照らし合わせる。全件 4711 本・変異 52 通り・実ブラウザ 8 項目・全体の点検〈Ready to merge〉まで済み）。本番の問 2 の付随の欄の見出しへ「希望エリア」、「その他」の欄の見出しへ「内容」を同じ日に入れた（見出しは必須のままと利用者が決めた・コードは変えない。問 1 はそのまま保存してよいことを本番の読み取りで確かめた。上の節）。

**顧客の取込で住所が空欄の行も重複候補にする（2026-11-03）は 2026-11-03 に本番反映済み**（上の節。Bug #123。`13.x` = `8e785f63`・本番のコントローラが手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。都道府県か市区町村が空欄の行は、同じ CSV を上げ直すと重複候補にならず同じ人が 2 人になっていた → 空欄どうしを同じとみなす。本番に二重になった買主は 0・DB 変更なし・反映は `./deploy.sh` だけ）。

**顧客の取込の CSV の中の同じ人・セルの中の改行・確定の DB のエラー（2026-11-04）は 2026-11-04 に本番反映済み**（上の節。Bug #125・#126・#89 の形。`13.x` = `13bd0505`・本番の 3 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）・取込の画面に新しい案内 2 行。全件 4754 本・変異 24 通り・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**顧客の取込でほかの部署にだけいる同じ人にはこの部署を足す（2026-11-03）は 2026-11-03 に本番反映済み**（上の節。Bug #124。`13.x` = `51e1ff28`・本番の 2 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）・取込の画面に新しい案内。取込先の部署にいない同じ人を重複候補にせず、新しく作らずにその人へ取込先の部署を足す。全件 4732 本・変異 15 通り・実ブラウザで確認。DB 変更なし・反映は `./deploy.sh` だけ）。

**テナント・賃貸マンションの取込のセルの中の改行・確定の DB のエラー（2026-11-04）は 2026-11-04 に本番反映済み**（上の節。Bug #127・#89 の形。`13.x` = `93012c13`・本番の 3 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。全件 4786 本・変異 25 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**決裁の利用者の取込のセルの中の改行（2026-11-06）は 2026-11-06 に本番反映済み**（上の節。Bug #127 の残り。`13.x` = `f0808d1b`〈反映のときは push していない。同じ日に push 済み〉・本番の 2 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。決裁の利用者の取込も `rows()` で読み、使い道の無くなった `CsvImportReader::parse()` を消した。全件 4789 本・変異 4 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**CSV 取込のセルと見出しの前後の全角の空白（2026-11-06）は 2026-11-06 に本番反映済み**（上の節。Bug #128。`13.x` = `b8201bc7`〈反映のときは push していない。同じ日に push 済み〉・本番の 1 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。テナント・賃貸マンション・決裁の利用者の取込で、セルと見出しの前後を画面の登録と同じ `Str::trim()` で落とす。本番の名前の列に前後の空白つきの値は 0 件〈読み取りで確認〉。全件 4800 本・変異 6 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**顧客CSVインポートの見出しの全角の空白と、標準の列名と同じ文の設問（2026-11-12）は 2026-11-12 に本番反映済み**（上の節。Bug #129。`13.x` = `212ae939`〈反映のときは push していない。同じ日に push 済み〉・本番の 1 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。見出しを `Str::trim()` で読み、設問の列の「Q1:」を除いて標準の列と比べない。本番の設問に、文が標準の列名と同じものは 0 問〈読み取りで確認〉。全件 4804 本・変異 5 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**ZEAL 会員の編集で、保存済みの値をそのまま保存できるようにする（2026-11-13）は 2026-11-13 に本番反映済み**（上の節。Bug #130。`13.x` = `aefb8f61`〈反映のときは push していない。同じ日に push 済み〉・本番の 3 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。取込で空のまま入ったフリガナ・性別は、保存済みが空なら編集で任意〈本番は在籍 40 人のうち 17 人が該当していた〉・メールは保存済みと同じなら形式を問わない・氏名 100・住所 300・無効の店舗とトレーナーも今の値なら残す。全件 4818 本・変異 26 通り・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**取込で入ったデータが編集画面で保存できないか（Bug #130 の形）を 2026-11-13 に本番で数えた**（上の節。テナントの物件・区画・顧客・契約、賃貸マンションの 6 画面、買主の 11 画面の更新の規則とブラウザの止め方を本番の行に当てた。どれも 0 件で、記録だけ。賃貸マンションの部屋・駐車場・入居者・契約は本番にまだ行が無い。取込で入りうる形〈部屋の家賃の `step="1000"`・選択肢に無い値・築年月の月の欄・買主のメールの形 など〉は節に表で残した）。

**取込で入った値を編集画面からそのまま保存できるようにする（2026-11-13・Bug #131）は 2026-11-15 に本番反映済み**（上の節。賃貸マンションの `step` などの制約・選択肢に無い値・取込の築年月と生年月日を直した。全件 4889 本・変異 46 通り・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**賃貸マンションの CSV 取込で、本番の列に入らない値をその行の誤りにする（2026-11-15・Bug #132）は 2026-11-15 に本番反映済み**（上の節。6 タブの 24 列の長さと範囲・小数の金額を、画面の入力チェックと同じ上限で断る。全件 4922 本・変異 25 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ。⚠ 本番へ出すと、まだ出していない Bug #131 も一緒に出る）。

**テナントの CSV 取込で、本番の列に入らない値をその行の誤りにする（2026-11-15・Bug #133）は 2026-11-15 に本番反映済み**（上の節。33 列＋備考の長さと範囲・小数の金額を、画面の入力チェックと同じ上限で断る。部品は `App\Support\CsvImportLimits` へ移して賃貸マンションと共用。全件 4977 本・変異 35 通り。DB 変更なし。新しいクラスがあるので main repo の cwd で `composer dump-autoload --no-dev --optimize` のあと `./deploy.sh`。⚠ 本番へ出すと、まだ出していない Bug #131・#132 も一緒に出る）。

**CSV 取込の金額と面積で、カンマの区切りが崩れた値をその行の誤りにする（2026-11-16・Bug #132 の追記）は 2026-11-16 に本番反映済み**（上の節。`13.x` = `7a2507f4`・本番の 3 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。2 つの取込の金額と面積の 2 列で、`5,5000`・`5,50` のような崩れた区切りを今の文でその行の誤りにする。全件 5003 本・変異 12 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**過去契約の取込の「顧客 N件を自動作成」を取り込む行から数える（2026-11-16・Bug #70 の形）は 2026-11-16 に本番反映済み**（上の節。`13.x` = `b77978ff`・本番の 1 本が手元と md5 まで一致・コンパイル済みビュー 318 本の lint（INVALID 0）。確認画面の「自動作成」に誤りで取り込まれない行のテナント名まで載っていた → 取り込む行から数える。全件 5006 本・変異 7 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**原価管理の一覧入力（見込み額・確定額の表。2026-11-16〜17）は 2026-11-17 に本番反映済み**（上の節。`13.x` = `f7b7bb50`〈反映のときは push していない〉・本番の 12 本が手元と md5 まで一致・コンパイル済みビュー 320 本の lint（INVALID 0）。不動産の仕入れ案件・分譲地の原価を、1 行ずつの追加から一覧の表で入れる形に変えた。保存は 1 回の要求でまとめて・断る行が 1 つでもあれば何も保存しない。全件 5141 本・変異 41 通り・実ブラウザで確認。DB 変更なし・新しいクラスとトレイトあり〈dump-autoload 済み〉・反映は dump-autoload のあと `./deploy.sh`）。

**周辺ビル調査の取込で、件数と階の崩れたカンマと数字のあいだの空白を断る（2026-11-17・Bug #134）は 2026-11-17 に本番反映済み**（上の節。`13.x` = `2a12c3ab`〈反映のときは push していない〉・本番の 4 本が手元と md5 まで一致・コンパイル済みビュー 320 本の lint（INVALID 0）。件数は CSV の取込と同じ 3 桁ごとの区切りだけ通し、階はカンマを通さない。数字のあいだの空白はその行の誤り。プレビューとサーバを同じ入力で突き合わせるテストを足した。全件 5156 本・変異 15 通り。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**原価管理の一覧入力の後回し 2 つ（M4 ログインが切れたときの文・M5 取込とのロックの順。2026-11-17・Bug #135・#136）は 2026-11-17 に本番反映済み**（上の節。`13.x` = `dce049f9`〈反映のときは push していない〉・本番の 5 本が手元と md5 まで一致・コンパイル済みビュー 320 本の lint（INVALID 0）。取込が表の保存と同じく親の行を先にロックする＝上書きの取込と重なると表の保存がデッドロックで落ちていた件・原価のカードの表の保存・取込・削除が、ログインが切れた・無効・権限が無いときに理由と次にすることを出す。全件 5192 本・変異 19 通り・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**ログインが切れたとき（419）に日本語の理由を出す（2026-11-17・Bug #137）は 2026-11-17 に本番反映済み**（上の節。`13.x` = `62a06cc9`〈反映のときは push していない〉・本番のログインへの空の送信で日本語の 419 の画面と JSON の日本語の文を確かめた・本番の 8 本が手元と md5 まで一致・コンパイル済みビュー 321 本の lint（INVALID 0）。基幹の JSON の 419 も決裁と同じ日本語の文にし、ふつうのフォームの 419 は日本語の画面〈`errors/419.blade.php`〉、番号だけ・何も出さなかった Ajax 7 か所も理由を出す。全件 5213 本・変異 28 通り・ビュー 321 本の lint（INVALID 0）・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ〈CSS の名前が変わる〉）。

**Bug #137 の後回し 4 つ（2026-11-17）は 2026-11-17 に本番反映済み**（上の節。`13.x` = `941fa3e6`〈反映のときは push していない〉・本番の 419 の画面のタブ名が「画面の有効期限が切れました - ミツワ都市開発管理システム」になったことを外から確かめた。買主のランク変更と「この部署に取り込む」で通信が失敗したら理由を出す・419 の読み取りを 1 つの部品に・419 の画面のタブ名・走査の死角を書き残す。全件 5216 本・変異 12 通り・ビュー 322 本の lint（INVALID 0）・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**GET の Ajax でログインが切れたときに理由を出す（2026-11-17〜18・Bug #137 の追記）は 2026-11-18 に本番反映済み**（上の節。`13.x` = `49106bfa`〈反映のときは push していない〉・送ったアプリのファイルはビュー 15 本だけ・反映の前の全件 5251 本・コンパイル済みビュー 323 本の lint（INVALID 0）。ログインした画面での確認と ssh での本番の読み取りはしていない。物件・分譲地を選んだときの区画などの取得・顧客や仕入れ先の検索・ZEAL の実績の確認・住所→〒の 23 の分岐が、ログインが切れた・無効にされた・部署が無いときに番号ではなく理由を出す。共通の部品 `_partials/_ajax_get_failure` を通し、サーバーは変えない。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**ログイン画面の 419 を行き止まりにしない（2026-11-18・Bug #138）は 2026-11-18 に本番反映済み**（上の節。`13.x` = `56e7b725`〈反映のときは push していない〉・本番でログインへ空の送信がログイン画面へ戻り赤い帯に理由が出ることを外から確かめた。ログイン画面を開いたまま時間がたってから送ると 419 の画面が出て、「前の画面に戻る」が古い鍵のログイン画面へ戻すので何度送ってもログインできなかった〈2026-11-18 の深夜に本番で起きた〉→ ログインの送信だけは新しいログイン画面へ戻して赤い帯に理由を出す。全件 5219 本・変異 12 通り・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**419 の画面から「前の画面に戻る」で戻った画面を送り直せるようにする（2026-11-18・Bug #137 の追記）は 2026-11-18 に本番反映済み**（上の節。`13.x` = `916bee81`〈反映のときは push していない〉・本番の 419 の画面とログイン画面に新しい部品と文が出ることを外から確かめた。419 の画面が新しい鍵を残し、レイアウトの部品が「戻る」で表示した画面の鍵を差し替える。ログインしたままなら戻った画面からそのまま送れ、ログインが切れていればログイン画面へ移る〈419 の繰り返しにならない〉。419 の画面の案内もログインしているかで出し分ける。全件 5264 本・変異 16 通り・ビュー 324 本の lint（INVALID 0）・実ブラウザで確認。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**鍵の差し替えの部品を読まない HTML の画面を走査で止める（2026-11-18）**はテストと、使われていないビュー（`components/app-layout.blade.php`）1 本の削除だけで、本番の動きは変わらない（上の節。構造のテストが 2 つのレイアウトの手書きから、HTML の画面の全件の走査になった。全件 5264 本・変異 9 通り・ビュー 323 本の lint（INVALID 0）。`./deploy.sh` は本番のファイルを消さないので、本番の app-layout は残る＝消すなら ssh の承認のうえで）。

**保存の Ajax でログインが切れた（401）ときに日本語の理由を出す（2026-11-18・Bug #137 の追記）**は 2026-11-18 に本番反映済み（上の節。ほかの画面でパスワードを変えた・利用者が消された、のあとの保存〈GET 以外〉の Ajax の 401 に、決裁と同じ日本語の文を返す。サーバーの文をそのまま出す 26 か所は画面を変えずに直り、番号だけの 8 か所は 401 でもサーバーの文を出す。GET の 401 は英語のまま・原価のカードは今の文のまま。全件 5289 本・変異 15 通り・ビュー 323 本の lint（INVALID 0）・実ブラウザで確認。`13.x` = `fd1e568b`〈反映のときは push していない〉・外から 419 と GET の 401 の文を確かめた。DB 変更・新しい PHP クラスなし・反映は `./deploy.sh` だけ）。

**保存の Ajax の 401 の後回し 3 つ（2026-11-18・Bug #137 の追記）**はテストとコメントだけの変更で、本番の動きは変わらない（上の節。テストの道具の偽の fetch の `json()` を本物のブラウザに合わせ〈JSON でない本文で失敗する〉、番号だけだった 6 か所に「本文が JSON でない 419・401 は番号つきの文」のテストを足した。HEAD も日本語の側を通るが本文が空なので見え方は同じ、と `bootstrap/app.php` に書き、`SignedOutSaveResponseTest`・`SignsOutElsewhere` の説明の誤りを直した。全件 5298 本・変異 15 通り。アプリで変わるのは `bootstrap/app.php` のコメントだけなので、次の `./deploy.sh` で一緒に出ればよい）。

**ここ 4 回の本番反映（2026-11-17〜18）を、2026-11-19 にログインした画面と本番の読み取りで確かめた**（上の節。送ったファイル 28 本が手元と md5 で一致・コンパイル済みビュー 324 本の lint（INVALID 0）・ログインした 5 画面でコンソールのエラー 0、失敗のときの文を作る関数が反映のときに決めた文を返す。`laravel.log` に 2026-11-18 01:05〜01:45〈日本時間〉の定期実行のキュー処理がデータベースにつながらなかった記録が 9 件あった〈画面の要求の記録は 0 件〉＝ これからは「Nov 18 01:45 のまま」と比べる。コードの変更なし）。

**既存の部品の既知の弱点を直す 2 回目（2026-11-19）は 2026-11-19 に本番反映済み**（上の節。`composer audit` の 14 件〈8 部品〉を、今の大きな版の中の更新で 0 件にした。いちばん重いのは表計算の部品の XXE〈アップロードされた xlsx を読む取込が 2 つある〉。変わったのは `composer.lock` だけ・全件 5298 本・ビュー 323 本の lint〈INVALID 0〉。`13.x` = `a538f4b6`〈push 済み〉・main repo で `composer install --no-dev` のあと `./deploy.sh`。本番の部品の版と `installed.json` などが手元と md5 で一致・本番のビュー 324 本の lint〈INVALID 0〉・本番の PHP で xlsx を書いて読み戻せた・`laravel.log` は Nov 18 01:45 のまま・ログインした画面〈経営ダッシュボード・仕入れ案件の詳細と、工程表と紙の台帳の取込を含む 12 画面〉が開きコンソールのエラー 0）。

**利用者の判断を待っていた 1〜3（2026-11-19・Bug #139〜#141）は 2026-11-19 に本番反映済み**（上の節。`13.x` = `21f7db50`〈push 済み〉・本番の 12 本が手元と md5 で一致・本番のビュー 324 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま。契約のある仕入れ案件を「販売済」から戻させず 2 件目の契約を断る・試算表の取込の上書きを経営層だけにする・DAD の工事案件の一覧の年度を 6 月始まりにそろえる。全件 5321 本・変異 21 通り・実ブラウザで確認。DB 変更・新しい PHP クラスなし）。

**契約のある建売・注文住宅の土地の紐付けを変えさせない（2026-11-20・判断待ち ④・Bug #142）は 2026-11-20 に本番反映済み**（上の節。`13.x` = `c97a35ee`〈反映のときは push していない〉・本番の 10 本が手元と md5 で一致・本番のビュー 324 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・本番の注文住宅 4 件はどれも契約済みで、編集と契約の編集の 8 画面が読み取り専用で開くことを確かめた。契約のある建売・契約済みの注文住宅の種別・区画・仕入れ案件を、3 つの保存の経路のどこからも変えさせない・注文住宅の 2 画面は読み取り専用で出す。全件 5342 本・変異 23 通り・実ブラウザ・最後の点検〈マージしてよい〉で確認。DB 変更なし・新しい trait が 1 つ〈本番反映では dump-autoload が要る〉）。

**土地原価の手動入力をやめたら紐付け先から計算し直す（2026-11-20・判断待ち ⑤・Bug #143）は 2026-11-20 に本番反映済み**（上の節。`13.x` = `002dc6d8`〈反映のときは push していない〉・本番の 16 本が手元と md5 で一致・本番のビュー 327 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・ログインした画面で建売の編集と契約の編集 10 画面を見るだけで確かめた。手動 OFF の保存では送られた値を使わずサーバーが決める＝新規登録・手入力をやめた・紐付け先を変えた・「今の計算値にする」を押したときだけ紐付け先から計算し、それ以外は保存済みの値のまま。4 画面に保存される値と、保存値が今の計算値と違うときのチェックを出す。全件 5378 本・変異 23 通り・実ブラウザで確認。DB 変更なし・新しいクラスが 1 つ〈本番反映では dump-autoload が要る〉。本番の 3 件は書き換えない）。

**注文住宅の編集画面でも買主を選ぶ（2026-11-20・判断待ち ⑥・Bug #144）は 2026-11-20 に本番反映済み**（上の節。`13.x` = `f2a757c5`〈反映のときは push していない〉・本番の 5 本が手元と md5 で一致・本番のビュー 327 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・本番の注文住宅 4 件の編集と契約の編集の 8 画面で買主の選択が出て、買主なしの CO-001 は未選択で開くことを確かめた。編集画面も登録と同じ買主の選択にし、保存は買主を必須・顧客名は買主の名前に。編集の保存と、契約一覧から開く契約の編集の保存は、画面の選択肢の買主だけを受け付ける〈自動のセキュリティ点検と最後の点検の指摘・利用者の了承〉。全件 5389 本・変異 15 通り・ビュー 326 本の lint〈INVALID 0〉・実ブラウザで確認。DB 変更・新しいクラスなし＝本番反映は `./deploy.sh` だけ。登録の保存などを絞るかは利用者の判断）。

**土地（旧「仲介土地」）の区画登録と、分譲地への変更（2026-11-20）は 2026-11-20 に本番反映済み**（上の節。`13.x` = `90845f5c`〈反映のときは push していない〉・SQL を先に流し、本番の 37 本が手元と md5 で一致・本番のビュー 328 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・ログインした画面で土地 19 件の詳細と一覧を見るだけで確かめた。表記を「土地」にし、土地の仕入れ案件に区画を 1 つ登録できるようにし、紐付けの無い自社買取の土地を中身ごと分譲地に変えられるようにした。区画の状態は契約・建売・注文住宅に合わせて動く。新しい表 `re_procurement_lots` と列 `re_projects.source_procurement_code`〈DB が先〉・新しい PHP クラス〈dump-autoload が要る〉・ルート 4 本。全件 5476 本・変異 35 通り・実ブラウザ 9 項目・最後の点検〈直してからマージ → Important 1 と Minor 5 を直した〉）。

**土地の仕入れ案件に区画を複数登録できるようにする（2026-11-21）は 2026-11-21 に本番反映済み**（上の節。`13.x` = `79b44597`〈反映のときは push していない〉・SQL を先に流し、本番の 40 本が手元と md5 で一致・本番のビュー 328 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・ログインした画面で一覧・土地の詳細・契約と建売と注文住宅の登録を見るだけで確かめた。本番の区画は #9・#18 の 1 区画ずつ。区画を何個でも登録でき、1 つの区画に付けられるのは不動産の契約・建売・契約以降の注文住宅のうち 1 件。区画の契約の原価欄と住宅事業の土地原価に、販売価格の比率で分けた額が入る。区画の状態はその区画だけ動き、仕入れ案件の状態は区画から集約する。列 3 つと索引〈DB が先〉・新しい PHP クラス 3 つ〈dump-autoload が要る〉・区画のルート 3 本の置き換え。全件 5576 本・変異 33 通り＋round 3 の 14 通り・実ブラウザ 2 回・最後の点検〈直してからマージ → Important 2 と Minor を直した〉）。

**区画のある土地の仕入れ案件のステータスを区画で決める（2026-11-22・Bug #145）は 2026-11-22 に本番反映済み**（上の節。`13.x` = `b04aa8a9`〈反映のあとで push 済み〉・本番の 2 本が手元と md5 で一致・本番のビュー 328 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・本番のデータで新しい判定を確かめ、戻せなくなっていた案件は 0 件・ログインした画面で一覧と編集画面を見るだけで確かめた。1 区画にだけ契約がある土地を手で「販売済」にすると戻せなかった件。区画のある土地は、全区画が成約なら「販売済」にしかできず、成約でない区画があれば「販売済」にはできない。区画の無い案件は Bug #139 のまま。全件 5586 本・変異 10 通り。DB 変更・新しいクラス・画面の変更なし＝本番反映は `./deploy.sh` だけ）。

**区画を足すとき、区画を選んでいない契約などをその区画につなぐ（2026-11-22〜23・Bug #146）は 2026-11-23 に本番反映済み**（上の節。`13.x` = `c7c6f8ed`〈反映のあとで push 済み〉・本番の 12 本が手元と md5 で一致・本番のビュー 328 本の lint〈INVALID 0〉・`laravel.log` は Nov 18 01:45 のまま・本番の #21 の候補は契約 1 件・ログインした画面で #21 の追加のフォームの案内を確かめた。#21 の形の土地に区画を足すと、区画を選んでいない契約・建売・契約以降の注文住宅のうち 1 つにつなぎ〈1 件なら自動・2 件以上なら選ぶ〉、区画のステータスはつないだものに合わせる。区画のある土地で区画なしの見積りの注文住宅は「契約」へ進めない。全件 5606 本・変異 24 通り・実ブラウザ・最後の点検〈マージしてよい〉。DB 変更なし・新しいクラスが 1 つ〈dump-autoload 済み〉）。

**契約済みの建売の削除で区画を戻す ／ 区画を足すときつなぐ変更の残り（2026-11-23・Bug #147・#146 の追記）は 2026-11-23 に本番反映済み**（上の節。`13.x` = `f72523ad`〈反映のときは push していない〉・送ったアプリのファイルは 9 本だけ・外からログイン画面 200 を確かめた。契約のある建売を削除すると、土地の区画と分譲地の区画を販売中に戻す。区画を足すときの同時の保存は後の保存を断り、指紋にステータスを入れ、開いていた建売・注文住宅の編集画面はそのまま保存でき、不動産の契約の編集は理由の分かる文で断る。全件 5632 本・変異 24 通り・実ブラウザ・最後の点検〈直してからマージ → Important 1 を直した〉。DB 変更・新しいクラスなし）。

その他の新規要件は別途追記する。

✅ **2026-09-01 に本番目視を実施した**（上記「本番確認の結果」）。**7 点中 6 点が確認済み**で、
残る 1 点（登録モードで元 POI 位置をクリック）は**測る対象が存在しないため意図的に見送った**。

⚠ 8/31 の反映直後は「全ルートが 302」までしか見ておらず、**302 は認証リダイレクトで
ビューを描画する前に起きる**ので、あの時点では本番のレンダリングを一度も確認できていなかった。
**302 を「アプリは正常」の証明に使わないこと。**
