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

⚠ **サイドバーの開閉の動きは本番では測っていない。** 自動操作したタブが裏（`visibility: hidden`）で、Alpine の x-show の切り替えが setTimeout 経由になり順序が変わるため（Bug #56）。本番に置かれたコードが修正後であることだけ確かめた（動きはローカルの Playwright で確認済み）。⚠ `origin/13.x` への push はしていない（利用者の指示があったときに行う）。

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
⚠ `origin/13.x` への push はしていない（利用者の指示があったときに行う）。

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

- テスト用スキーマの `contracts.customer_id` / `rent_start_date` は NOT NULL だが、本番はどちらも NULL 可（反映前の読み取りで確認）＝テスト用スキーマの漂流。
  アプリはどちらも空のまま契約を作る経路を持つ（テナント名が空の契約の取込・賃料開始日が空の過去契約）ので、その経路は確定までテストで通せない。テストは両方を埋めている
  （SQLite で NOT NULL を外すとテーブルの作り直しになり CHECK が消えるので、直すなら別の作業で測りながら）
- 過去契約の取込の「解約日が今日より未来です」の警告は途中で積むので、その後の検査でエラーになる行にも出る（今回の注意は採用時にだけ積む形にした）
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
⚠ `origin/13.x` への push はしていない（利用者の指示があったときに行う）。

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
  CLAUDE.md と `ZealFiscalYear::START_MONTH` は「ZEAL/DAD は 6/1 始まり」。**業務判断が要る**
- `birthday` に `before_or_equal:today` を足すか
- `app/Mail/BackupFailedMail.php:31` は `JapanTime::format()` と逐語で同値（既定の書式まで一致）だが、
  段階0 のメール経路なので保留
- `Tenant/UnitController.php:529` の並び替えキーが `created_at` の `H:i:s` だけを見ており、
  同じ改定日に UTC の日をまたいだ 2 行で新旧が逆に並ぶ（**timezone とは独立した既存のバグ**）
- 日付ピッカー 6 ビューの `var now = new Date();` は**ブラウザのローカル日**に従う（サーバの日本の今日ではない）
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
両 worktree とも作業ツリー清浄。⚠ `origin/13.x` への push はしていない。

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

## ✅ 月末の溢れを直す（前月・来月・過去 18 か月）— 本番未反映

実装計画（走査・掃引・変異テストの記録つき）: @docs/superpowers/plans/2026-09-23-month-end-overflow.md

上の「日時の表示と『今日』を日本時間にそろえる」で宿題にした月末の溢れ（docs/RULES.md Bug #62）。
Carbon の `subMonth()` / `addMonth()` / `subMonths($i)` は、移った先の月にその日が無いと翌月へ溢れる（3/31 の 1 か月前 = 2/31 → 3/3）。
「今日」から直接足し引きしていた 5 行を、既存の規約どおり「月初へ寄せてから足し引き」に直した。
**DB 変更・ルート変更・新規 PHP クラス・新規依存はいずれも無し。**

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

- **走査**: アプリの月の足し引きは 22 件。直したのは 5 件、残り 17 件は起点が `startOfMonth()` か `Carbon::create(年, 月, 1)`（1 件ずつ起点まで遡った）
- **溢れる日**（2026 年の全日を掃引）: 前月 7 日（3/29・3/30・3/31・5/31・7/31・10/31・12/31）／ 来月 7 日（1/29・1/30・1/31・3/31・5/31・8/31・10/31）／ 過去 18 か月の一覧 29 日。閏年の 2028 年は 6 / 6 / 25 日
- **Bug #61 との関係**（2026 年を 1 時間ごとに掃引）: 前月側で日本時間の丸一日溢れるようになったのは 3/29・5/31・7/31・10/31・12/31 の 5 日（それまでは 15 時間）。各月 1 日の朝 9 時間の誤りは Bug #61 で消えていたので、前月の誤りの時間は年間 **186 h → 168 h → 今回 0 h**
- **回帰テストの「今日」は 2026-03-31 に固定**し、その日が前月・来月とも溢れる日であることをテスト自身が確かめる（`assertTodayOverflows()`）。同じ画面の 3 つの「前月」（ビル別カードのラベル・集計・全体カードの実績期間）を 1 本で見る（Bug #41）
- 体験予約（外部 DB の `'zeal'` 接続）はテストで SQLite のメモリへ向け直し、ダッシュボードの件数が 0 であることで向け直しが効いたと確かめる

### 範囲外（気づいたが直していない）

- 日付ピッカーの「1ヶ月前」ボタン（JS の `setMonth(getMonth() - 1)`。定義 7 か所・ボタン 8 か所）— 同じ形に溢れる（3/31 に押すと 3/3）。ブラウザのローカル日なので Bug #61 の影響は受けておらず、直し方も別（前月の末日で止めるか）→ 仕様を利用者に確認中
- 新しく足した月の足し引きを止める走査テスト（22 件の全件分類）— 別の作業の候補
- `DashboardController::aggregateBuildingStats()` の入居率は、前月末日に終わる契約を入居に数えない可能性が高い（`endOfMonth()` の 23:59:59 と、日付だけの解約日の 0:00 を比べているため）。意図どおりかは未確認

### 検証

- 全テスト **1752 → 1756 tests / 10807 assertions green**
- **変異 17 通りすべて期待どおり**（docs/RULES.md Bug #44 の作法。カナリア 1・等価 1 を含む）— 5 行それぞれを元の順序へ戻すと、狙ったテストだけが狙ったアサートの文言で落ちる。自己検査は「今日」を 3/15 にすると前月側、7/31 にすると来月側で 4 本とも落ち、自己検査を外して 3/15 にすると #1 を戻してもテスト 1 が緑になる（＝自己検査が効いている）。全表は計画の「実測記録」
- コンパイル済みビュー **269 本を `php -l`** → INVALID 0 件
- 実装 → 仕様適合レビュー → コード品質レビュー（Minor 3 点を取り込み、再レビューで承認）

### 本番反映の手順

**DB 変更なし・ルート変更なし・新規 PHP クラスなし**なので `composer dump-autoload` は不要。

```
git checkout 13.x && git merge --ff-only month-end-overflow
./deploy.sh
```

⚠ 画面の見た目が変わるのは溢れる日だけなので、反映後の本番確認は「落ちないこと」を見る
（テナントダッシュボード・ZEAL ダッシュボード・体験予約一覧・項目マスタの編集画面が 200 で、コンソールにエラーが無いこと）。

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

**工程表ボードのガント改修（2026-09-03〜04）と、テナント契約一覧の契約日順・見出しの並び替え（2026-09-11）は、
2026-09-11 に一緒に本番反映済み**（上記の 2 節。最終 `13.x` = `e9a899d7`）。本番でコンパイル済みビュー 267 本の
lint（INVALID 0）と、ログイン済みの実 Chrome での目視まで確認した。
⚠ **ガント改修の既知の未修正（初期スクロールが 220px 手前に着地する）が本番の住宅の工程表ボードで出ていた。**
同日に修正して**本番反映済み**（呼び出しを DOMContentLoaded まで待たせる。`13.x` = `60ea80cb`。本番で右端に止まることを実測）。

**地下区画の表記（`-1B1A` ／ 修繕の `33A` ／ フロアマップの `-1F`）と、横スクロールのヒント（4 一覧・PC 展開サイドバーの `x-cloak`・開閉後の測り直し）の修正（2026-09-12〜13）も 2026-09-13 に本番反映済み**（上記の節。本番の実データで 4 一覧 × 7 幅・修繕の選択肢 151 件・フロアマップの `B1F` まで確認）。

**論理削除した区画を参照する画面の修正（2026-09-14）も同日に本番反映済み**（上記の節。本番の解約済み契約 C-1991-001 が契約詳細・契約一覧・物件詳細・顧客詳細で「5A（削除済み）」と出ることまで確認）。

**削除した物件を参照する画面（物件の削除の歯止め）と、区画の CSV 取込の削除済み同名区画（2026-09-14）も同日に一緒に本番反映済み**（上記の 2 節。`13.x` = `3e9647f2`。本番のコンパイル済みビュー 269 本の lint（INVALID 0）と、読み取りだけの画面確認まで）。

**月末の溢れ（前月・来月・過去 18 か月。2026-09-23）は実装・検証まで済み、本番未反映**（上記の節）。

その他の新規要件は別途追記する。

✅ **2026-09-01 に本番目視を実施した**（上記「本番確認の結果」）。**7 点中 6 点が確認済み**で、
残る 1 点（登録モードで元 POI 位置をクリック）は**測る対象が存在しないため意図的に見送った**。

⚠ 8/31 の反映直後は「全ルートが 302」までしか見ておらず、**302 は認証リダイレクトで
ビューを描画する前に起きる**ので、あの時点では本番のレンダリングを一度も確認できていなかった。
**302 を「アプリは正常」の証明に使わないこと。**
