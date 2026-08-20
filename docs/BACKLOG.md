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

- 一覧: 空室率フィルタ（満室 / 空きあり / **25% 以上 / 50% 以上**）・調査年フィルタ・キーワード検索
  （ビル名 / **在籍中のテナント名**。退去済みは引っかからない）・インライン番号付きページネーション
  ⚠ 閾値と検索対象は第2段（下記）で変わった。第1段は 20 / 40 で所在地も検索していた
- 詳細: 最新調査の KPI・**調査時の実測とテナント明細の乖離警告**（両方を並べて出す。Bug #46 の教訓）・
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
| ルート | **1本追加**（`POST /tenant/area-buildings/{building}/coordinates`。`role:executive,manager`。**上書き可**）。地図タブは既存 index に `?view=map` で統合し新ルートを作らない |
| Controller | `AreaBuildingController` に `storeCoordinate()` / `mapPins()` / `mapUnlocated()` ＋ `index()` の `$isMap` 分岐 |
| Support | `VacancyRate` に `BAND_MID` / `BAND_HIGH` / `LEVEL_*` / `LEVELS` / `level()` を追加（**閾値はここ 1 箇所だけ**）|
| Service | `AreaBuildingListService::paginateRows()` を切り出し（地図タブは全件とページャの両方が要る）|
| Blade | `_map.blade.php` を新設（`?view=map` のときだけ include）＋ index / show / _form を改修 |
| テスト | 781 → **909 tests / 5457 assertions green** |

### 主な変更

- **空室率の帯を 0 / 25 / 50 に統一**（実データ 187 棟で 24:18:26:31 とほぼ四等分。20 / 40 だと赤が 4 割で差が見えない）。
  一覧フィルタも地図の凡例も `VacancyRate::BAND_MID` / `BAND_HIGH` を見る
- **地図タブ**（`?view=map`）: 空室率で色分けしたピン・吹き出し・`fitBounds`（ピン 0 件なら松山市中心）。
  **ページングしない**（絞り込み後の全件）
- **登録モード**（経営層＋管理者のみ）: 未登録の棟のリストを出し、地図クリックで即保存 → 自動で次の棟へ。
  スキップ・置き直し（上書き）可。**保存しても地図の中心とズームを動かさない**
- **所在地を画面から消した**（一覧の列 / 詳細 / 登録編集フォーム / キーワード検索）。
  ⚠ **DB 列・Excel 取込の「所在地」マッピング・住所からの座標一括取得は温存**
- **マーカーの形（2026-08-20 追加）**: 直径 14px の丸だと背景の白い街路に沈むという指摘を受け、
  モック 3 案から「**しずく型のピンを既定にし、拡大したときだけ空室率の数字つきの丸へ切り替える**」を採用。
  境目は `AREA_MAP_LABEL_ZOOM = 18` の 1 箇所（松山で 0.50m/px ＝ 30m 離れた棟が 60px 離れる）。
  ⚠ 数字は**切り捨ての整数**（`VacancyRate::compactLabel()`）。四捨五入だと 49.6% が `50%` と出るのに
  色は橙（25〜49%）のままで**数字と色が矛盾する**。切り捨てなら原理的に起きない。
  ⚠ 既知の穴: 率が **1% 未満**だと `0%` と出るのに帯は low（黄）。到達には 1 棟 101 区画以上が必要で
  実データには無いため、純粋な切り捨てを優先して直していない（テストで件数 102 を固定して名指し）。
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

## バックログ完了状況

優先度 1〜5 のすべてのバックログ項目が本番稼働中。周辺ビル調査は第1段（2026-08-17）に加え、
第2段の一部（一覧の地図タブと位置登録）も 2026-08-20 に本番稼働。
新規要件は別途追記する。
