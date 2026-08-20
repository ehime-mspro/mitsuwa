# 周辺ビル調査（テナント管理） — 設計書

作成日: 2026-08-12
対象: テナント管理 `/tenant/area-buildings`

---

## 1. 背景

自社ビルの周辺にあるビルを歩いて回り、「ビル名 / 階数 / 営業 / 空き / 不明」を記録した一覧が
Excel で 50 棟以上ある。現在この情報はシステムに入っておらず、共有も蓄積もされていない。

これをデータベース化し、一覧と地図で見られるようにする。用途は 4 つとも該当する（ユーザー確認済み）:

1. **テナント誘致の営業リスト** — 周辺ビルの入居テナントを把握し、自社の空き区画へ誘致する
2. **エリアの空室率把握** — 自社物件の賃料設定・募集条件・投資判断の材料にする
3. **買収・仕入れ候補の発掘** — 空きが多いビルを不動産部門の仕入れ候補として共有する
4. **台帳＋地図としての記録** — 調査結果を失わずに残し、いつでも参照できる

---

## 2. 決定事項（ユーザー確認済み）

| # | 決定 |
|---|---|
| 1 | ビル（恒久情報）/ 調査回（時点情報）/ 入居テナント（現況）の **3 テーブル** |
| 2 | 調査は **年月単位**で積み上げる。同じビルの同じ年月は 1 件 |
| 3 | テナントは「現況リスト」1 本。差分だけ直し、退去は `moved_out_on` で履歴として残す |
| 4 | 件数は**入力値が正**。テナント明細からの集計値を併記し、乖離時のみ警告（Bug #46 対策） |
| 5 | 空室率 = **(空き + 不明) ÷ (営業 + 空き + 不明)**。「不明」は空きとして扱う |
| 6 | 既存 Excel が 50 棟以上あるため **Excel 取込を作る**（SheetJS 方式。ビル＋調査 / テナント明細の 2 種） |
| 7 | 自社物件とは紐づけない**独立エリア台帳**。近接は地図上で見て判断する |
| 8 | ビルの追加項目（オーナー・管理会社・築年・構造・エリア区分・写真）は**持たない**。地図で確認できるため |
| 9 | 画面は**一覧タブ＋地図タブ**（案A）。詳細は既存意匠に揃える |
| 10 | 調査回・テナントの追加編集は **Ajax ではなく別画面** |
| 11 | 権限は既存テナント管理と同じ（閲覧＝全ロール / 登録・編集＝経営層+管理者 / 削除＝経営層） |
| 12 | 実装は **2 段階**（第1段＝台帳として使える状態 / 第2段＝見える化） |
| 13 | **費用は最小限に抑える**（§6.0）。Places API は不採用 / 詳細画面はリンクのみで埋め込み地図なし / Street View のボタンは出さない / 地図は押したときだけ生成 / ジオコーディングは 1 棟 1 回 |

---

## 3. データモデル

テーブル名は `area_*` プレフィックス。既存の `properties` / `units`（自社物件）と混同しないようにする。
DB は raw SQL 管理（`database/sql/`）だが、**SQLite テスト用の migration も同時に書く**
（migration と live schema の drift は過去に実害を出している）。

### 3.1 `area_buildings` — ビル（恒久情報）

| カラム | 型 | NULL | 備考 |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | | |
| `name` | VARCHAR(255) | NOT NULL | ビル名 |
| `address` | VARCHAR(255) | NULL | 所在地 |
| `latitude` | DECIMAL(10,7) | NULL | 既存の `re_procurements` と同型 |
| `longitude` | DECIMAL(10,7) | NULL | 同上 |
| `total_floors` | INT | NULL | 総階数 |
| `notes` | TEXT | NULL | 備考 |
| `created_by` | BIGINT UNSIGNED | NULL | FK `users.id` ON DELETE SET NULL |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | NULL | SoftDeletes |

インデックス: `INDEX(name)`（Excel 取込のビル名照合と一覧のキーワード検索で使う）

**`postal_code` と `basement_floors` は持たない。** 住所は地図とジオコーディングで足り、
地下階数は既存の調査データに存在しないため（YAGNI）。

### 3.2 `area_building_surveys` — 調査回（時点情報）

| カラム | 型 | NULL | 備考 |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | | |
| `area_building_id` | BIGINT UNSIGNED | NOT NULL | FK `area_buildings.id` ON DELETE CASCADE |
| `surveyed_month` | DATE | NOT NULL | **調査年月。日は `01` 固定**。画面は `2026年8月` 表示・月選択で入力 |
| `operating_count` | INT UNSIGNED | NOT NULL DEFAULT 0 | 営業（そのビルのテナント部屋数） |
| `vacant_count` | INT UNSIGNED | NOT NULL DEFAULT 0 | 空き（明らかに空き店舗と分かる数） |
| `unknown_count` | INT UNSIGNED | NOT NULL DEFAULT 0 | 不明（判断できない数） |
| `surveyed_by` | BIGINT UNSIGNED | NULL | FK `users.id` ON DELETE SET NULL。調査者 |
| `notes` | TEXT | NULL | その回の所見 |
| `created_at` / `updated_at` | TIMESTAMP | | SoftDeletes なし（調査回は物理削除） |

制約: `UNIQUE(area_building_id, surveyed_month)`
→ 同じビルの同じ月は 1 件。取込・登録で衝突したら**上書きせず確認を出す**。

⚠ `surveyed_month` は DATE 型だが**意味は年月**。日を `01` 以外で保存してはならない。
モデルの `saving` フックで `startOfMonth()` に正規化し、回帰テストで固定する。

`surveyed_by` の既定はログインユーザー。ただし**変更可能にする**（現地を歩いた担当と入力者が
違うことがあるため）。Excel 取込では取込を実行したユーザーを入れる。

⚠ **`User` は SoftDeletes なので、調査者名を表示するリレーションには `withTrashed()` を付ける。**
付け忘れると退職者が調査した行の調査者欄が空になる。
セレクトの選択肢は既存の実装に合わせ、**新規画面は `User::assignable()`、編集画面は
`User::assignableWith($survey->surveyed_by)`**（現在の調査者が無効化済みでも選択肢に残る）。

### 3.3 `area_building_tenants` — 入居テナント（現況リスト）

| カラム | 型 | NULL | 備考 |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | | |
| `area_building_id` | BIGINT UNSIGNED | NOT NULL | FK ON DELETE CASCADE |
| `floor` | INT | NULL | 階。地下は負数（B1 = `-1`） |
| `room_number` | VARCHAR(50) | NULL | 部屋番号・区画名 |
| `name` | VARCHAR(255) | NULL | テナント名。空き区画の行では NULL |
| `industry` | VARCHAR(100) | NULL | 業種 |
| `status` | VARCHAR(20) | NOT NULL | `operating` / `vacant` / `unknown` |
| `confirmed_on` | DATE | NULL | 最終確認日 |
| `moved_out_on` | DATE | NULL | 退去日。入ると現況リストから外れ履歴になる |
| `notes` | TEXT | NULL | |
| `created_at` / `updated_at` | TIMESTAMP | | |

インデックス: `INDEX(area_building_id, moved_out_on)`（現況リストの抽出）、`INDEX(name)`（テナント名検索）

### 3.4 モデルと Enum

```
app/Models/AreaBuilding.php          SoftDeletes / hasMany surveys, tenants / belongsTo creator
app/Models/AreaBuildingSurvey.php    belongsTo building, surveyor
app/Models/AreaBuildingTenant.php    belongsTo building
app/Enums/AreaTenantStatus.php       Operating='operating' / Vacant='vacant' / Unknown='unknown'
```

`AreaTenantStatus` は既存 Enum の流儀に従い `label()` と `badgeStyle()` を持つ
（`badgeStyle()` は `'background: #d1fae5; color: #065f46;'` 形式の inline style を返す。Tailwind クラスは返さない）。

- 営業 `operating` → `background: #d1fae5; color: #065f46;`（緑）
- 空き `vacant` → `background: #fee2e2; color: #991b1b;`（赤）
- 不明 `unknown` → `background: #f3f4f6; color: #374151;`（灰）

`status` は `casts()` で `AreaTenantStatus::class` にキャストする。
⚠ キャスト済み属性に `tryFrom()` を呼んではならない（Bug #22）。クエリで使うときだけ `->value`。

---

## 4. 空室率の定義

> **2026-08-20 変更 — 画面は「入居率」を主に見せる。**
> 利用者の依頼で、一覧の表・詳細の KPI・地図の丸を **入居率**主体に切り替えた。
> `入居率 = 100 − 空室率` で、`VacancyRate::occupancyPercent()` / `occupancyLabel()` /
> `occupancyCompactLabel()` として**同じヘルパーに同居**させてある。
> ⚠ **「営業 ÷ 総数」で独立に切り捨ててはいけない。** 画面に両方並べる以上、和が
> 100.0% にならない行が出てはならない（営業 1 / 空き 2 で 66.6% + 33.3% = 99.9%。Bug #46）。
> ⚠ **float の引き算にもしない**（`100.0 - percent()` は 33.400000000000006 を返す）。
> 1/10% 単位の整数で引いてから 1 回だけ 10 で割る。
> ⚠ **閾値（`BAND_MID` 25.0 / `BAND_HIGH` 50.0）と `level()` は空室率のまま**で、
> `LEVELS` のラベルだけを入居率で言い直した（`満室（100%）` / `76〜99%` / `51〜75%` / `50% 以下`）。
> 帯のキー（none / low / mid / high）は空室率の段階を指す内部名。

```
総区画数   = operating_count + vacant_count + unknown_count
空室数     = vacant_count + unknown_count          ← 「不明」は空き扱い
空室率(%)  = 空室数 × 100 ÷ 総区画数
```

実装は **`App\Support\VacancyRate`（純粋な static ヘルパー）に一本化**し、
`AreaBuildingSurvey` はそれを呼ぶ薄いメソッドを持つだけにする
（`AreaConverter` / `TsuboPrice` / `ConsumptionTax` と同じ流儀）。
一覧・詳細・地図・Excel 取込プレビューのすべてがこの 1 箇所を通る。
**呼び出し側で計算式を直書きしない**（同じ計算が複数経路に散ると片方だけ直す事故が起きる。Bug #41）。

- **総区画数が 0 のときは `null` を返す**（ゼロ除算。画面は `—`）
- 丸めは **1/10 % 単位で切り捨て**、整数演算で行う: `intdiv($vacancies * 1000, $total)` → 表示は `/ 10`
  float の割り算と `round` は使わない（Bug #33 / #34 と同じ理由）
- `total_floors`（総階数）は率の計算に**使わない**。階数と部屋数は別物

一覧・地図では率だけでなく **営業 / 空き / 不明を別列（別行）で必ず併記**する。
率だけだと「空きが多いのか、不明が多いのか」が判別できなくなるため。

---

## 5. 画面と URL

### 5.1 ルート（`routes/web.php` の `tenant` プレフィックス配下）

```
GET    /tenant/area-buildings                                        index      全ロール
GET    /tenant/area-buildings/create                                 create     executive,manager
POST   /tenant/area-buildings                                        store      executive,manager
GET    /tenant/area-buildings/import                                 取込画面   executive,manager
POST   /tenant/area-buildings/import                                 取込実行   executive,manager
GET    /tenant/area-buildings/{building}                             show       全ロール
GET    /tenant/area-buildings/{building}/edit                        edit       executive,manager
PUT    /tenant/area-buildings/{building}                             update     executive,manager
DELETE /tenant/area-buildings/{building}                             destroy    executive

GET    /tenant/area-buildings/{building}/surveys/create              調査追加   executive,manager
POST   /tenant/area-buildings/{building}/surveys                     調査登録   executive,manager
GET    /tenant/area-buildings/{building}/surveys/{survey}/edit       調査編集   executive,manager
PUT    /tenant/area-buildings/{building}/surveys/{survey}            調査更新   executive,manager
DELETE /tenant/area-buildings/{building}/surveys/{survey}            調査削除   executive

GET    /tenant/area-buildings/{building}/tenants/create              テナント追加 executive,manager
POST   /tenant/area-buildings/{building}/tenants                     テナント登録 executive,manager
GET    /tenant/area-buildings/{building}/tenants/{tenant}/edit       テナント編集 executive,manager
PUT    /tenant/area-buildings/{building}/tenants/{tenant}            テナント更新 executive,manager
DELETE /tenant/area-buildings/{building}/tenants/{tenant}            テナント削除 executive
```

⚠ **`create` と `import` は `/{building}` より先に宣言する。** 後に置くとルーターが
`create` を ID として解釈し、モデルバインディングで 404 になる。

ルート名は `tenant.area-buildings.*` / `tenant.area-buildings.surveys.*` / `tenant.area-buildings.tenants.*`。

### 5.2 コントローラ

```
app/Http/Controllers/Tenant/AreaBuildingController.php         index / create / store / show / edit / update / destroy
app/Http/Controllers/Tenant/AreaBuildingSurveyController.php   create / store / edit / update / destroy
app/Http/Controllers/Tenant/AreaBuildingTenantController.php   create / store / edit / update / destroy
app/Http/Controllers/Tenant/AreaBuildingImportController.php   form / execute
```

一覧のクエリ組み立て（フィルタ 3 種＋並び順＋最新調査回のサブクエリ）は
`App\Services\AreaBuildingListService` に分離する。コントローラに置くと肥大化し、
地図タブが同じ絞り込み結果を必要とするため共有できる形にしておく。

### 5.3 一覧タブ `/tenant/area-buildings`

**第1段では一覧のみ。** 第2段で上部に「一覧 / 地図」の切り替えを追加し、地図タブは `?view=map`
とする（別 URL にせず同じフィルタ状態を引き継ぐため）。

**列**: ビル名 / 所在地 / 総階数 / 営業 / 空き / 不明 / 空室率 / 最終調査年月 / 操作

> **2026-08-20 変更**: 実装の列は ビル名 / 総階数 / 営業 / 空き / 不明 / **入居率** / 空室率 /
> 位置 / 最終調査 / 操作 の 10 本（所在地は出さない）。**入居率は空室率の前**。
> 列を足し引きするときは colgroup の合計 100% / th の本数 / 空行の colspan を 3 点セットで揃える。

**既定の並び順**: 空室率の降順（空きが多いビルが上＝営業先も買収候補も先頭に来る）。
空室率が `null`（調査未入力）の行は末尾。

**フィルタ**（既存のフィルタバー規約に準拠。`onchange` で即時 submit、クリアボタンは
`h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400`）:

| フィルタ | 値 | 備考 |
|---|---|---|
| キーワード | 自由入力 | ビル名 / 所在地 / **入居テナント名**を横断検索 |
| ~~空室率~~ → **入居率**（2026-08-20） | 全て / 満室（100%）/ 空きあり（99% 以下）/ 入居率 75% 以下 / 入居率 50% 以下 | 「全て」は空値。クエリキーは `occupancy`。判定の中身は空室率のまま（`>= BAND_MID` / `>= BAND_HIGH`）で、同じ境界を反対側から言い直しただけ |
| 調査年 | 全て / 各年 | 最終調査年月の年で絞る |

「空きの有無」は空室率フィルタに含めた（`満室(0%)` と `1%以上`）。
2 つに分けると条件が重複し、「空きあり かつ 満室」のような矛盾した組み合わせが選べてしまうため。

キーワードのテナント名検索は**現況の行（`moved_out_on IS NULL`）のみ**を対象にする。
退去済みまで拾うと「もう居ない会社」でヒットしてしまう。

**ページネーション**: 20 件 / ページ。`->links()` は使わず、`zeal/inquiries/index.blade.php` の
インライン番号付きマークアップを流用する（プロジェクト規約）。

⚠ **N+1 対策**: 各行が「最新の調査回」を必要とする。`with('surveys')` で全件ロードすると
50 棟 × 数回分を毎回引くことになる。最新 1 件だけを引くサブクエリで組む。

⚠ **フィルタの null 問題**: `ConvertEmptyStringsToNull` により `?vacancy=` は `null` で届く。
`filled()` で判定し、型ガードより先に `null` を「全て」として返す。
ページャは `withQueryString()` ではなく
`->appends(array_map(fn ($v) => $v ?? '', $request->query()))` を使う
（`http_build_query` が null 値のキーを捨て、2 ページ目でフィルタが飛ぶため）。

### 5.4 詳細 `/tenant/area-buildings/{building}`

1. **ヘッダ** — ビル名 / 所在地 / 総階数 / 最新調査の 営業・空き・不明 / 空室率
2. **位置** — 緯度経度があれば「**Google マップで開く**」リンク（`https://www.google.com/maps/search/?api=1&query=<lat>,<lng>`、`target="_blank" rel="noopener"`）。
   **埋め込み地図は出さない**（詳細は 1 棟ずつ何度も開くため。§6.0 参照）。無ければ「位置未登録」の案内と編集への導線
3. **調査履歴** — 調査年月（降順）/ 営業 / 空き / 不明 / 空室率 / 調査者 / 所見 ＋ 編集・削除。上部に「調査を追加」
4. **入居テナント一覧** — 階の降順・部屋番号順。退去済み（`moved_out_on` あり）は折りたたみ。上部に「テナントを追加」
5. **乖離の警告** — 下記

**乖離の警告（Bug #46 対策）**

最新の調査回について、次の 2 つを**同じ画面に並べて出す**。

- 「調査時の実測（入力値）」— `operating_count` / `vacant_count` / `unknown_count`
- 「テナント明細からの集計」— `moved_out_on IS NULL` の行を `status` ごとに数えた値

テナント明細が **0 行のビルでは比較しない**（明細を入れていないだけで警告が出ると意味がない）。
1 行でもあれば比較し、いずれかが食い違うときだけ警告を出す。
空室率などの下流の値は**常に入力値を正**として算出する（明細に寄せると、明細が途中まで
しか入っていないビルの数字が壊れる）。

### 5.5 登録・編集フォーム

ビル名（必須） / 所在地 / 総階数 / 備考 ＋ 地図（住所からジオコーディング → ドラッグピン）。

**新規登録時のみ**、同じ画面で 1 回目の調査（調査年月 / 営業 / 空き / 不明 / 所見）も入力でき、
保存時に調査回を 1 件同時に作成する。編集画面には調査欄を出さない（調査は履歴側で管理する）。

⚠ 金額系ではないが、**件数の入力欄に `value="0"` の既定値を入れない**（空欄スタートが原則）。
未入力は 0 として保存する。

### 5.6 調査・テナントの追加編集（別画面）

それぞれ独立した `create` / `edit` 画面を持つ。保存後は該当ビルの詳細へ戻る。

**テナント追加画面には「保存して続けて登録」を付ける。** チェックが入っていれば
保存後に同じビルの追加画面へ戻す。1 棟あたり 10〜20 区画になるため、往復を減らす。

⚠ 状態セレクトの `<option>` は `@foreach` で静的に生成する。`<template x-for>` は使わない（Bug #16）。

### 5.7 サイドバー

「テナント管理」グループの `問合せ管理` の後、`分析` サブ見出しの前に
`周辺ビル調査`（`/tenant/area-buildings`）を追加する。

---

## 6. 地図

既存 8 画面と同じく、各ページが個別に `<script src="https://maps.googleapis.com/maps/api/js?...">`
を持つ構成。API キーは `config('services.google_maps.api_key')` 経由で読む
（Blade から `env()` を直接呼ばない。Bug #17）。

`layouts/app.blade.php` の `gm_authFailure` フックに乗せ、API 認証失敗時は代替表示に落ちるようにする。

### 6.0 費用最小化の方針（ユーザー確認済み）

Google Maps Platform の課金対象と、本機能での抑え方。

| 課金対象 | 発生タイミング | 本機能での方針 |
|---|---|---|
| Maps JavaScript API | `new google.maps.Map()` の実行ごと 1 回（パン・ズームは追加課金なし） | **利用者が明示的に押したときだけ生成する**（下記） |
| Geocoding | 住所→座標の変換ごと | **1 棟につき 1 回だけ**。結果は DB に保存し二度と取り直さない |
| Places Autocomplete / Details | 打鍵ごと＋確定時 | **採用しない**（§12 スコープ外） |
| Street View | 利用者がパノラマを開いたとき | **コントロールを出さない**（`streetViewControl: false`） |

**地図を生成する箇所は 2 つだけに絞る。**

1. **登録・編集フォーム** — 「地図で確認」ボタンを押したときのみ（既存の仕入れ案件と同じ。押さなければ 0 回）
2. **一覧の地図タブ** — `?view=map` を開いたときのみ。表で見ている限り課金は発生しない

**詳細画面には埋め込み地図を置かない。** 「Google マップで開く」リンクで別タブに飛ばす（課金ゼロ）。
詳細は 1 棟ずつ何度も開くため、ここを埋め込みにすると回数が積み上がる。

⚠ **`streetViewControl: false` にする。** 既存の仕入れ案件は `true` だが、
コントロールを出すと利用者が開いた回数だけ Street View が課金される。外観を見たいときは
「Google マップで開く」から Google 側で見れば無料。

### 6.1 フォームの地図（第1段）

`realestate/procurements/_form.blade.php` の実装をそのまま移植する。本番で枯れているコード。

- `onGoogleMapsReady` コールバックで `Geocoder` を初期化。編集時は保存済み緯度経度で地図を出す
- 「地図で確認」ボタン → **段階フォールバック**（フル住所 → 番地除去 → 丁目除去 → 市区町村 → 都道府県）
- ヒットしたレベルに応じてズームとメッセージを変える
- ピンは `draggable`。地図クリックでも移動。`dragend` / `click` で hidden input を `toFixed(7)` で更新
- 住所が空 / 全部失敗 → 松山市中心（`33.8392, 132.7657` zoom 13）にフォールバックして手動指定を促す
- **`streetViewControl: false`**（§6.0）。`mapTypeControl` と `fullscreenControl` は既存どおり

⚠ **段階フォールバックは 1 クリックで最大 5 回ジオコーディングを叩く。**
フル住所で失敗するたびに次の候補へ進むため。手作業の 1 棟ずつなら許容範囲だが、
一括処理でこの関数を使い回してはいけない（§7.4）。

### 6.2 一覧地図（第2段・新規）

- フィルタ適用後のビルを**全部ピン表示**し、**空室率で色分け**する
  初期案: 0% 緑 / 〜20% 黄緑 / 〜40% 橙 / 40%〜 赤 / 調査未入力 灰
  **閾値は第1段で実データを入れてから確定する**（想定で決め打ちしない）
- **自社物件 16 棟を青ピンで重ねる**（下記 6.4）
- ピンクリックで InfoWindow（ビル名 / 総階数 / 営業・空き・不明 / **入居率** / 空室率 / 最終調査 / 詳細へのリンク）
  > **2026-08-20 変更**: 寄せたとき丸の中に出す数字は入居率（`pinLabel`）。
  > **値は「100 − 空室率の整数」**＝ `VacancyRate::compactLabel()` の出力を 100 から引く。
  > `ceil(100 − v) === 100 − floor(v)` なので**2 つの整数の和が必ず 100 になる**（構造として保証）。
  > ⚠ 入居率を独立に切り捨てると空室率から見て切り上げになり、帯（色）からはみ出す
  > （実測 20,300 通り中 290 件。29 区画/空き 7 は空室率 24.1% ＝黄なのに丸へ「75%」）。
  > この形なら 100 件へ減り、残るのは「空室率 1% 未満 → 丸に 100%」＝総区画 101 以上のみ。
  > ⚠ 表の 1 桁（57.2%）と丸（58%）は食い違って見えるが、**丸は表の空室率の整数 42 と足して 100**。
  > 吹き出しには入居率と空室率を**この順で両方**出す —— 丸の数字だけでは突き合わせられない。
  > 凡例のラベルも入居率の言い方。
- 全ピンに `fitBounds` して初期表示。ピン 0 件なら松山市中心へフォールバック

⚠ **ピン配列は必ずコントローラで組み立て、単一変数として Blade に渡す。**
`@json()` に多行の配列リテラルやメソッド呼び出しを渡すと壊れた PHP にコンパイルされる（Bug #26）。
また `x-data="..."` 属性の中に `@json` を置かない。`<script>` 内の named function で受けるか
`{{ \Illuminate\Support\Js::from($pins) }}` を使う（Bug #23）。

⚠ `<script>` 内の `//` コメントに `@json` `@if` などのディレクティブ名を書かない。
書く必要があれば `@@json` とエスケープする（Bug #30）。

### 6.3 自社物件を地図に重ねる（第2段）

`properties`（テナント物件）に緯度経度カラムが無いため追加が必要。

- `properties` に `latitude` / `longitude` DECIMAL(10,7) NULL を追加（raw SQL ＋ migration）
- テナント物件の編集画面に 6.1 と同じ地図パーツを載せ、位置を登録できるようにする
- 既存 16 棟は §7.4 と同じ一括ジオコーディング（1 棟 1 回）で下地を作り、ズレだけ手で直す

⚠ **`Property` モデルの `$fillable` にも追加する。** 忘れると保存が無音で落ちる。

### 6.4 API キーの保護（実装とは別に必須）

**社内限定でもキーはブラウザに露出する**（`<script src="...key=...">` としてページのソースに出る）。
本機能の実装とは独立して、Google Cloud Console で次を設定すること。無料で、これが最大の防御になる。

- **HTTP リファラー制限** — `https://www.mitsuwat.co.jp/*` からの呼び出しだけ許可
- **API 制限** — このキーで使えるのを **Maps JavaScript API と Geocoding API だけ**に絞る（Places は有効にしない）
- **予算アラート** — 想定外の課金に早く気づくため

⚠ **Google Maps JS API の bootstrap スクリプトに SRI は付けられない。**
`maps.googleapis.com/maps/api/js` は内容が動的に生成されるためハッシュが固定されない。
SRI を付けられるのはバージョン固定の SheetJS のような静的ファイルだけ（§7）。

---

## 7. Excel 取込

DAD 工事案件・仕入れ案件と同じ **SheetJS（クライアント側）→ サーバ確定** 方式。
ライブラリは `cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js`
（`cdnjs.cloudflare.com` は本番でブロックされる）。

新規に追加する `<script>` タグには **`integrity="sha384-…" crossorigin="anonymous"`（SRI）を付ける**。
既存の DAD・仕入れ案件の取込画面は SRI 無しで動いているが、新しく増やす分は付けておく
（CDN が改竄されたときに実行を止められる。既存分を揃えるかは別件とする）。

UI は 3 ステップ: シート/ヘッダ行選択 → 列マッピング（見出し文言から自動推測）→ プレビューで警告 → 確定。

### 7.1 ビル＋調査（主）

対象列: ビル名 / 所在地（任意） / 階数 / 営業 / 空き / 不明

- **調査年月は取込画面で 1 つ指定**して全行に適用する。Excel 側に年月列があればそちらを優先
- 既存ビルとの突合は**ビル名**で行う（前後の空白と全角半角スペースを正規化して比較）
  - 一致 → そのビルに調査回を 1 件追加。`address` / `total_floors` が空のビルは Excel の値で補完する（既存の値は上書きしない）
  - 不一致 → ビルごと新規作成
  - **同じビル・同じ調査年月が既にあれば取り込まずスキップ**し、件数を結果に報告する
- 緯度経度は取込では設定しない（登録後にフォームからジオコーディングする）

### 7.2 テナント明細（任意・後から）

対象列: ビル名 / 階 / 部屋番号 / テナント名 / 業種 / 状態

- ビル名が台帳に無い行は**取り込まず警告に出す**（ビルの自動生成はしない）
- 状態はエイリアス正規化: `営業中` `入居` → 営業 / `空室` `空き店舗` → 空き / 空欄・`?` → 不明

### 7.3 数値の正規化

既存実装と同じく、**全角数字・カンマ・空白・「円」「¥」を除去してから**数値判定する。
数値にならない値は行ごと警告に出し、取り込まない。

⚠ 取込プレビューの `<option>` は静的生成（Bug #16）。フォームの数値入力は
グローバルの全角→半角変換リスナーが `inputmode="numeric"` に自動適用される。

### 7.4 座標の一括取得（ジオコーディング）

取込では座標を設定しない。代わりに**一覧の上部に「座標未設定 N 件を一括取得」ボタン**を置く。

- 対象は **`latitude IS NULL` の行だけ**。何度押しても未設定分しか叩かないので二重課金にならない
- **1 棟につきフル住所で 1 回だけ**ジオコーディングする。§6.1 の段階フォールバック
  （最大 5 回）は**使わない**。失敗した棟は「位置未取得」として一覧に印を付け、
  必要なものだけ登録フォームから手で確定する
- 住所が空の行は最初から対象外（リクエストを消費しない）
- 実行前に「N 件を取得します」と件数を見せ、押した人が回数を把握できるようにする

⚠ **1 回の実行で叩く上限を設ける**（例: 200 件）。上限に達したら残件数を報告して止める。
無制限にすると、取込ミスで大量の行が入ったときにそのままリクエストが飛ぶ。

これで 50 棟なら**生涯で最大 50 リクエスト**に収まる。座標は DB に保存し、以後は取り直さない。

---

## 8. 権限

既存テナント管理と完全に同じ構成。`department.access:tenant` 配下に置く。

| 操作 | ロール |
|---|---|
| 一覧・詳細の閲覧 | 全ロール |
| ビル / 調査 / テナントの登録・編集、Excel 取込 | `role:executive,manager` |
| ビル / 調査 / テナントの削除 | `role:executive` |

⚠ ビルの削除は SoftDeletes。調査回とテナントは FK `ON DELETE CASCADE` だが、
SoftDeletes ではビル行が残るため子は消えない（これは意図どおり。復元可能にするため）。

---

## 9. 実装の段階

### 第1段 — 台帳として使える状態

3 テーブル / Enum / モデル / 一覧（表のみ）/ 詳細（地図なし・リンクのみ）/
ビル登録編集フォーム（ジオコーディング＋ドラッグピン）/ 調査の追加編集画面 /
テナントの追加編集画面 / Excel 取込（ビル＋調査、テナント明細）/ 座標の一括取得 / サイドバー。

**この時点で既存 Excel を流し込んで運用を開始できる。**

### 第2段 — 見える化

一覧地図（色分け・自社物件重ね）/ `properties` への緯度経度追加と物件編集画面の地図 /
エリア集計と空室率の推移。

**第2段の着手前に第1段のデータを見て、色分け閾値と集計の粒度を確定する。**

**この設計書に続く実装プランは第1段だけを対象とする。** 第2段は第1段の本番投入後、
実データを見てから改めてプランを作る。想定で閾値を決めても外すため。

---

## 10. 過去バグへの対策（設計時点で塞ぐもの）

| 罠 | 今回の適用箇所 |
|---|---|
| **#46** 内訳と合計が別ソースで無音に食い違う | 5.4 の乖離警告。入力値と明細集計を並べて出す |
| **#31 / #24** 「全て」フィルタが 0 件・ページ送りでフィルタが飛ぶ | 5.3。`filled()` 判定 ＋ `appends(array_map(...))` |
| **#27** 空コレクションの `merge()` で 500 | 一覧は単一クエリで組み、複数ソースを混ぜない |
| **#23 / #26 / #30** `@json` 一族 | 6.2。ピン配列はコントローラで組み立てて単一変数化 |
| **#16** `<option>` を `x-for` で生成 | 5.6 / 7.3。状態セレクトと取込マッピングは `@foreach` |
| **#36 / #37** 生の翻訳キー・英字の項目名 | 下記 10.1 |
| **#22** キャスト済み enum への `tryFrom()` | 3.4 |
| **#17** Blade で `env()` 直呼び | 6。`config('services.google_maps.api_key')` |
| **#29 / モバイル崩れ** | 表は横スクロールコンテナに入れる。地図を置くグリッドは `minmax(0, 1fr)` ＋ `max-width: 100%` |
| **#33 / #34** float 由来の丸め誤差 | 4。空室率は整数演算で 1/10 % 単位の切り捨て |
| **#41** 同じ計算の経路が複数できる | 4。空室率の算出を 1 箇所に集約する |
| **#20** ルート正規表現と TYPE_MAP の非同期 | 添付ファイルは今回使わないため該当なし |
| **migration drift** | 3。raw SQL と同時にテスト用 migration も書く |

### 10.1 日本語バリデーション

`lang/ja/validation.php` の `attributes` に和名を追加する。

`total_floors`「総階数」/ `surveyed_month`「調査年月」/ `operating_count`「営業」/
`vacant_count`「空き」/ `unknown_count`「不明」/ `room_number`「部屋番号」/
`industry`「業種」/ `confirmed_on`「最終確認日」/ `moved_out_on`「退去日」

`name` と `address` はアプリ全体で語が異なるため**グローバルは変えず**、
各コントローラの `validate()` **第 3 引数**で上書きする
（`validate($rules, $messages, $attributes)`。第 2 引数は messages）。

- `AreaBuildingController` → `name`「ビル名」、`address`「所在地」
- `AreaBuildingTenantController` → `name`「テナント名」

⚠ 走査テスト `JapaneseValidationMessagesTest` が和名漏れを自動で拾うので、
追加を忘れるとテストが落ちる（それが正しい挙動）。

---

## 11. テスト方針

「テストが緑」は検証にならない。**各テストについて、誤実装に戻したら赤になることを実測する。**

| # | テスト | 何を守るか |
|---|---|---|
| 1 | 空室率の計算（Unit） | 不明を空きに含めること / 総数 0 で `null` / 1/10 % 切り捨て。**`round` や float 除算に戻すと赤**になる値を明示的に置く |
| 2 | `surveyed_month` の月初正規化（Unit） | 日付を月中で渡しても `01` に丸まる。正規化を外すと `UNIQUE` が効かなくなる |
| 3 | 一覧の「全て」フィルタ（**HTTP レベル**） | `?vacancy=` で 0 件にならない。⚠ `Request::create()` ではミドルウェアを通らず**原理的に検出できない** |
| 4 | ページ送りでフィルタが維持される | 1 ページ目を描画し **`nextPageUrl()` を実際に辿る**。URL を手で組むと必ず緑になる |
| 5 | 空データで 200 | 調査 0 件・テナント 0 件のビルで一覧と詳細が落ちない（Bug #27 型） |
| 6 | 乖離警告 | 明細が食い違うとき出て、一致するとき出ない、**明細 0 行では出ない**の 3 通り |
| 7 | Excel 取込 | 新規ビル作成 / 既存ビルへの調査追加 / **同一調査年月のスキップ** / 数値不正行の警告 |
| 8 | テナント明細取込 | 台帳に無いビル名の行が取り込まれず警告に出る |
| 9 | 権限 | staff が登録・編集・削除に到達できない（3 コントローラ分） |
| 10 | ルート順序 | `/tenant/area-buildings/create` が `create` という ID として解釈されない |
| 11 | 座標の一括取得が既存座標を叩かない | 座標入りの行を混ぜても対象件数に入らない（§7.4。**二重課金の防止が load-bearing**） |
| 12 | 詳細に埋め込み地図が無い | `maps.googleapis.com` を読み込む `<script>` が詳細ビューに現れない（§6.0 の費用方針が崩れたら赤になる） |

⚠ 集計値をテストするときは **`assertSee` だけで判定しない**。一覧は各行にも数字を出すため、
合計が 0 でも行の文字列に一致して false-pass する。`viewData()` で厳密に見るか、
合計にしか現れない一意な値を作る（Bug #40 / #43）。

⚠ SQLite は存在しないカラムの `SUM` を**例外なしで 0 に**する。カラム名を変える改修では
集計が実額であることを固定するテストを必ず 1 本置く。

---

## 12. スコープ外

- **Places API によるビル名補完**（費用最小化のため不採用。§12.1）
- **詳細画面の埋め込み地図**（「Google マップで開く」リンクに置き換え。§6.0）
- **Street View**（`streetViewControl: false`。§6.0）
- 自社物件との自動的な距離計算・近接ランキング（地図上で目視する）
- 周辺ビルの賃料・坪単価の記録（現地調査では取得できない）
- 空室率の推移グラフ（第2段で実データを見てから判断する）
- 調査結果の外部公開・PDF 出力
- 写真の添付（ユーザー判断で不要）
- テナント名の外部データソース連携

### 12.1 Places API を採用しない理由（2026-08-12 判断）

一度は第2段に入れたが、費用最小化の方針で外した。判断の根拠を残す。

- **費用対効果が最も低い。** 50 棟以上は Excel 取込で入り、住所があれば既存のジオコーディングで
  座標が取れる。Places が効くのは**後から手で 1 棟ずつ足すとき**だけ
- **打鍵ごとにリクエストが飛ぶ。** セッショントークン（JS のウィジェットを使えば自動管理）で
  1 セッションに束ねられるが、それでも入力のたびに課金される
- **ヒットしない可能性がある。** Places のデータは基本的に店舗・企業などの POI であり、
  地方の小規模ビルは「ビル名」として登録されていないことがある

⚠ **将来採用を再検討するなら、先に実測すること。** 調査リストから 10 棟ほど Google マップで
手で検索し、ビル名でヒットする割合を確かめる。ヒット率が低ければ課金する価値は無い。

---

## 13. 本番反映

1. worktree で実装 → `/commit`
2. main repo で `git checkout 13.x && git merge --ff-only <branch>`
3. **新規 PHP クラスを追加するので、main repo の cwd で `composer dump-autoload`**
   （worktree から実行すると autoloader に worktree パスが焼き込まれる）
4. DB へ raw SQL を適用（`database/sql/2026-08-12-create-area-building-tables.sql`）
   ローカル: `php artisan tinker --execute="DB::unprepared(file_get_contents('database/sql/....sql'));"`
   本番: 要ユーザー明示承認
5. `./deploy.sh`（`npm run build` → rsync → `config:cache && route:cache && view:cache`）
6. 本番ブラウザで確認 — **一覧のフィルタ「全て」→ 2 ページ目、詳細の乖離警告、
   「Google マップで開く」リンク、フォームの「地図で確認」**を実際に触る
   （HTML に出ているかだけでは判定できない項目がある）
7. **デプロイ後に Google Cloud Console でリクエスト数を確認する**（§6.0 の想定どおりか。
   一括ジオコーディングを流した直後は棟数と同じだけ増えているのが正しい）

⚠ ルートを追加するので `git push` だけでは本番に反映されない。`./deploy.sh` で `route:cache` の
再生成が必要（Bug #20 / #25 と同じ）。
