# ZEAL（フィットネス事業）管理 要件定義書 v2

## 変更履歴

| 版 | 日付 | 変更内容 |
|----|------|---------|
| v1 | 2026-05-01 | 初版作成 |
| v2 | 2026-05-01 | プラン料金履歴・キャンペーン管理・税処理を強化。`zeal_member_contracts` テーブルを新設し SCD Type-2 でプラン変更を時系列管理。`zeal_plans` に `campaign_starts_on` / `campaign_ends_on` を追加。税抜カノニカル + 税込併記の表示ルールを明文化。ペアプラン料金（通常 22,770 円 / キャンペーン 20,700 円・税込）を確定。ダッシュボードの月会費売上集計クエリ仕様を追加。 |

---

## 1. 概要

ZEAL は ミツワ都市開発グループ 6 番目の事業として立ち上がるフィットネス事業（プレオープン 2025-10-03〜10-15、グランドオープン 2025-10-17）。本モジュールは経営管理システム manage の中で **ZEAL の会員・体験予約・売上・集客を一元的に把握する管理画面** を提供する。

### 1.1 事業形態

- パーソナルトレーニング（1対1）+ セミパーソナル（1トレーナーで最大4名）の複合型
- マシン自由利用・グループレッスンは無し
- 完全予約制、月会費プラン中心、稀に回数券利用あり
- 月内未消化分は当月末で失効（持ち越し無し）

### 1.2 開発スコープ

| # | 機能 | 内容 |
|---|------|------|
| 1 | 体験予約パイプライン閲覧 | 外部 DB `gym_inquiries` を参照し、問合せ → 体験 → 入会のリードを可視化 |
| 2 | 会員管理 | 会員マスタ CRUD（住所・連絡先・現プラン・担当トレーナー・退会情報）+ **プラン変更履歴管理（時系列で過去契約を追跡）** |
| 3 | 会員 CSV インポート | テナント・顧客 CSV と同パターンの一括取込 |
| 4 | プランマスタ | 月会費プランの登録・編集（通常価格 / キャンペーン価格 2 系統 + **キャンペーン期間管理**） |
| 5 | トレーナーマスタ | 名前のみのシンプルマスタ（セレクトボックス用） |
| 6 | ペアプラン管理 | 主契約者に紐付ける同伴者会員の登録 |
| 7 | ダッシュボード | 在籍会員数・月別入退会・**月会費売上（プラン別・税抜/税込）**・体験→入会率・集客チャネル・退会理由・月次グラフ |
| 8 | 添付ファイル | 既存 attachment 機能流用（会員詳細に書類等添付） |

### 1.3 スコープ外

- 日々の予約スケジュール管理（外部システムで運用継続）
- トレーナーの勤怠・給与・シフト管理
- 会員側のセルフ予約 Web UI
- POS / 物販管理
- LINE / メール配信
- マシンや器具のメンテナンス管理
- 売上の自動仕訳・会計連携

---

## 2. データソース構成

ZEAL は **複数のデータソースを統合表示する読み取り中心の管理画面** という性格を持つ。

| データ | ソース | 本システムでの扱い |
|--------|--------|------------------|
| 体験予約・問合せ | 外部 DB `mitsuwa-ud_zeel-b.gym_inquiries`（同 MySQL サーバー内・別 DB） | Laravel multi-connection で **読み取りのみ** 参照（書き込みは Spreadsheet 同期側） |
| 会員情報・契約履歴 | 本システムが master | CRUD + CSV インポート + プラン変更履歴 |
| プラン・トレーナー・店舗 | 本システムが master | CRUD |
| 日々の予約 | 外部システム（管理対象外） | スコープ外 |

### 2.1 外部 DB 接続設定

`.env` に以下を追記する:

```env
# ZEAL 体験予約データベース接続（既存DBの参照、読み取り中心）
ZEAL_DB_HOST=127.0.0.1
ZEAL_DB_PORT=3306
ZEAL_DB_DATABASE=mitsuwa-ud_zeel-b
ZEAL_DB_USERNAME=（読み取り権限のあるユーザー）
ZEAL_DB_PASSWORD=（パスワード）
```

`config/database.php` に `'zeal'` 接続を定義し、`GymInquiry` モデルで `protected $connection = 'zeal';` を指定する。マイグレーションは作成しない（既存テーブルを参照するのみ）。

---

## 3. DB 設計

### 3.1 `zeal_stores`（店舗マスタ）

当面は 1 店舗運用だが、将来の多店舗展開に備え `store_id` を全テーブルに持たせる。

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| name | varchar(100) | NO | 店舗名 |
| address | varchar(300) | YES | 住所 |
| phone | varchar(20) | YES | 電話 |
| open_date | date | YES | 開店日 |
| display_order | int | NO | 表示順（既定 0）|
| active | tinyint(1) | NO | 有効フラグ（既定 1）|
| created_at / updated_at | timestamp | | |

### 3.2 `zeal_plans`（プランマスタ）

通常価格・キャンペーン価格の 2 系統に加え、キャンペーンの適用可能期間を保持する。**マスタは「現状の標準価格 + 現状適用中のキャンペーン情報」のみ持ち、過去のキャンペーン履歴は契約レコード（`zeal_member_contracts`）に焼き付けるため不要**。

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| name | varchar(100) | NO | プラン名（例: パーソナル&セミパーソナル通い放題（2枠）） |
| regular_price_excl | int unsigned | NO | 通常価格（税抜） |
| campaign_price_excl | int unsigned | YES | キャンペーン価格（税抜） |
| campaign_starts_on | date | YES | キャンペーン適用可能期間 開始日 |
| campaign_ends_on | date | YES | キャンペーン適用可能期間 終了日 |
| max_concurrent_reservations | int | YES | 同時予約可能数（例: 1, 2、NULL = 月4回プランや回数券）|
| includes_personal | tinyint(1) | NO | パーソナル含む |
| includes_semi_personal | tinyint(1) | NO | セミパーソナル含む |
| monthly_session_limit | int | YES | 月間利用上限（NULL = 通い放題、4 = 月4回プラン）|
| is_pair_plan | tinyint(1) | NO | ペアプランフラグ（既定 0）|
| display_order | int | NO | 表示順 |
| active | tinyint(1) | NO | 有効フラグ |
| created_at / updated_at | timestamp | | |

#### 初期データ（v1 投入、税抜価格）

PDF 記載は税込みなので、税抜換算は通常税込 ÷ 1.1 で算出。既存プランの「キャンペーン税込 = 通常税抜」パターンと整合する。

| name | regular_price_excl | campaign_price_excl | max_concurrent | personal | semi | monthly_limit | is_pair |
|------|-------------------|-------------------|---------------|----------|------|---------------|---------|
| パーソナル&セミパーソナル通い放題（2枠） | 24,000 | 21,819 | 2 | 1 | 1 | NULL | 0 |
| パーソナル&セミパーソナル通い放題（1枠） | 18,000 | 16,364 | 1 | 1 | 1 | NULL | 0 |
| パーソナル&セミパーソナル月4回 | 13,000 | 11,819 | 1 | 1 | 1 | 4 | 0 |
| セミパーソナル通い放題 | 9,800 | 8,909 | 1 | 0 | 1 | NULL | 0 |
| ペアプラン | 20,700 | 18,818 | 1 | 1 | 1 | NULL | 1 |

※ ペアプラン: 通常 22,770円（税込）/ キャンペーン 20,700円（税込）。
※ キャンペーン期間は v1 投入時点では NULL（プレオープン期間に応じて運用側で設定）。

### 3.3 `zeal_trainers`（トレーナーマスタ）

セレクトボックス用の最小マスタ。雇用形態・連絡先・給与等は管理しない。

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| name | varchar(100) | NO | トレーナー氏名 |
| display_order | int | NO | 表示順（既定 0）|
| active | tinyint(1) | NO | 有効フラグ（既定 1）|
| created_at / updated_at | timestamp | | |

### 3.4 `zeal_member_contracts`（プラン契約履歴）

会員のプラン変更を時系列で管理する SCD Type-2（Slowly Changing Dimension Type-2）テーブル。1 行 = 1 契約期間。プラン変更時は現契約を `period_end` で閉じて新契約レコードを INSERT する。

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| member_id | bigint FK → zeal_members | NO | 会員（ON DELETE CASCADE）|
| plan_id | bigint FK → zeal_plans | NO | 当時のプラン（ON DELETE RESTRICT）|
| period_start | date | NO | 契約開始日（通常は月初）|
| period_end | date | YES | 契約終了日（NULL = 現契約。後続契約の前日で閉じる）|
| applied_price_excl | int unsigned | NO | **焼付け**：通常 or キャンペーン適用後の税抜額 |
| is_campaign_applied | tinyint(1) | NO | キャンペーン価格を適用した契約かのフラグ |
| tax_rate_at_contract | decimal(5,2) | NO | **焼付け**：契約時の消費税率（settings.tax_rate のスナップショット。例: 10.00）|
| change_reason | varchar(50) | YES | 変更理由 Enum（new_join / plan_change / campaign_apply / price_revise / withdraw）|
| note | varchar(200) | YES | 備考 |
| created_by | int unsigned | NO | 登録者 |
| created_at / updated_at | timestamp | | |

**インデックス**:
- `idx_member_period` (`member_id`, `period_start`, `period_end`)

**外部キー**:
- `member_id` → `zeal_members.id` ON DELETE CASCADE
- `plan_id` → `zeal_plans.id` ON DELETE RESTRICT

**運用ルール**:
- **不変性**: 確定済みの過去契約レコード（period_end が入っているもの）は原則更新不可。誤入力修正以外で UPDATE しない
- **重複禁止**: 同一会員で `period_end IS NULL` のレコードは常に 1 件以下
- **連続性**: 同一会員の契約は時系列で隙間なく連続する（休会期間は別途 `zeal_members.suspended_*` で管理可、v2 ではスコープ外）
- **整合性**: INSERT/UPDATE 時に `zeal_members.current_plan_id` を必ず同期（最新の open contract の plan_id をミラー）

### 3.5 `zeal_members`（会員マスタ）

| カラム | 型 | NULL | 内容 |
|--------|-----|------|------|
| id | bigint PK AI | | |
| store_id | bigint FK → zeal_stores | NO | 所属店舗 |
| gym_inquiry_id | int | YES | 外部 DB の `gym_inquiries.id` への参照（任意。体験から入会した人を紐付け） |
| name | varchar(100) | NO | 氏名 |
| name_kana | varchar(100) | YES | フリガナ |
| gender | varchar(10) | YES | 男性 / 女性 / その他（ZealGender Enum） |
| birthday | date | YES | 生年月日 |
| phone | varchar(20) | YES | 電話 |
| email | varchar(100) | YES | メール |
| postal_code | varchar(8) | YES | 郵便番号 |
| address | varchar(300) | YES | 住所 |
| joined_on | date | NO | 入会日 |
| withdrew_on | date | YES | 退会日（NULL = 在籍中） |
| withdraw_reason | varchar(50) | YES | 退会理由 Enum |
| withdraw_note | text | YES | 退会理由詳細 |
| **current_plan_id** | bigint FK → zeal_plans | YES | **【キャッシュ】** `zeal_member_contracts` の最新 open contract（`period_end IS NULL`）の `plan_id` をミラー。一覧画面の N+1 回避用 |
| trainer_id | bigint FK → zeal_trainers | YES | 担当トレーナー |
| pair_parent_member_id | bigint FK → zeal_members（自己参照）| YES | ペアプランの主契約者（NULL = 通常会員）|
| acquisition_source | varchar(30) | YES | 当店を知ったきっかけ Enum |
| purpose | varchar(50) | YES | 目的 Enum |
| memo | text | YES | 備考メモ（特徴・トレーニングデータ等を自由記述） |
| created_by | int unsigned | NO | 登録者 |
| updated_by | int unsigned | YES | 更新者 |
| created_at / updated_at | timestamp | | |

**インデックス**:
- `store_id`
- `joined_on`, `withdrew_on`
- `current_plan_id`
- `pair_parent_member_id`
- `gym_inquiry_id`

**`current_plan_id` の整合性ルール**:
- 入会時 / プラン変更時 / 退会時 のトランザクション内で `zeal_member_contracts` 操作と必ず同期
- 真実は `zeal_member_contracts`、`current_plan_id` はあくまでキャッシュ
- バリデーション: `current_plan_id` は最新 open contract の `plan_id` と一致しなければならない（マイグレーション後の整合性チェッククエリで検証）

### 3.6 `gym_inquiries`（外部 DB 参照のみ）

接続: `mitsuwa-ud_zeel-b` データベース。

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AI | |
| inquiry_date | date NOT NULL | 問合せ日 |
| trial_date | date | 体験日 |
| trial_time | time | 体験時間 |
| status | varchar(50) | ステータス（日程調整中/来店予定/未入会/入会/退会/追撃不要 等の文字列） |
| contract_plan | varchar(100) | 契約プラン名（自由記述） |
| name | varchar(100) NOT NULL | 氏名 |
| gender | varchar(10) | 性別 |
| age | int | 年齢（実年齢の数値） |
| purpose | varchar(100) | 目的 |
| purpose_detail | text | 目的詳細 |
| memo | text | 備考 |
| special_notes | text | 特記事項 |
| phone | varchar(20) | 電話 |
| email | varchar(100) | メール |
| created_at / updated_at | timestamp | |
| UNIQUE KEY | (inquiry_date, name) | |

**注意事項**:
- 本テーブルへの書き込みは **行わない**（Spreadsheet 同期側で更新される）
- マイグレーションは作成しない
- シートにあった他カラム（体験担当者・自力集客の有無・契約 ID・フリガナ・職業・入会理由・お客様住所・入会月・退会月・休会期間・契約期間・退会理由・退会理由詳細・当店を知ったきっかけ・トレーニングデータ）は本 DB には含まれない

---

## 4. Enum 一覧

### 4.1 `ZealGymInquiryStatus`（gym_inquiries.status の表示制御）

外部 DB の値は varchar だが、本システム側で表示用 Enum を定義してバッジ色・並び順を制御する。

| 値 | 表示名 | バッジ色 |
|----|--------|---------|
| 日程調整中 | 日程調整中 | gray |
| 来店予定 | 来店予定 | blue |
| 未入会 | 未入会 | amber |
| 入会 | 入会 | emerald |
| 退会 | 退会 | red |
| 追撃不要 | 追撃不要 | gray |

未知の値が来た場合は文字列をそのまま表示（neutral バッジ）。

### 4.2 `ZealGender`

| 値 | 表示名 |
|----|--------|
| male | 男性 |
| female | 女性 |
| other | その他 |

### 4.3 `ZealWithdrawReason`

| 値 | 表示名 |
|----|--------|
| financial | 金銭的理由 |
| moving | 引っ越し |
| busy | 忙しい |
| reservation | 予約が取りにくい |
| other | その他 |

### 4.4 `ZealAcquisitionSource`

| 値 | 表示名 |
|----|--------|
| sns | SNS |
| search | 検索エンジン |
| referral | 紹介 |
| word_of_mouth | 口コミ |
| flyer | ポスティングチラシ |
| street_flyer | 街頭チラシ |
| map_search | 地図検索 |
| phone | 電話 |
| unknown | 不明 |
| other | その他 |

### 4.5 `ZealPurpose`

| 値 | 表示名 |
|----|--------|
| body_make | ボディメイク |
| diet | ダイエット |
| exercise | 運動不足解消 |
| function | 機能改善 |
| lower_body | 下半身強化 |
| stamina | 体力向上 |
| stress | ストレス発散 |
| health | 健康増進 |
| other | その他 |

### 4.6 `ZealContractChangeReason`（v2 で追加）

| 値 | 表示名 | 用途 |
|----|--------|------|
| new_join | 新規入会 | 入会時に最初の契約レコードを作る |
| plan_change | プラン変更 | 既存会員がプラン変更を行った |
| campaign_apply | キャンペーン適用 | 既存会員にキャンペーン価格を遡及または個別適用 |
| price_revise | 料金改定 | プランマスタの価格改訂に伴い、契約金額を更新 |
| withdraw | 退会 | 退会処理で契約を閉じる |

---

## 5. URL / ルート設計

| メソッド | パス | コントローラー | 名前 | 役割 |
|---------|------|---------------|------|------|
| GET | `/zeal` | `Zeal\DashboardController@index` | `zeal.dashboard` | ダッシュボード |
| GET | `/zeal/inquiries` | `Zeal\InquiryController@index` | `zeal.inquiries.index` | 体験予約一覧（外部 DB） |
| GET | `/zeal/inquiries/{id}` | `Zeal\InquiryController@show` | `zeal.inquiries.show` | 体験予約詳細 |
| GET | `/zeal/members` | `Zeal\MemberController@index` | `zeal.members.index` | 会員一覧 |
| GET | `/zeal/members/create` | `Zeal\MemberController@create` | `zeal.members.create` | 会員登録 |
| POST | `/zeal/members` | `Zeal\MemberController@store` | `zeal.members.store` | 登録処理（最初の契約レコードも作成）|
| GET | `/zeal/members/{id}` | `Zeal\MemberController@show` | `zeal.members.show` | 会員詳細（4タブ）|
| GET | `/zeal/members/{id}/edit` | `Zeal\MemberController@edit` | `zeal.members.edit` | 会員編集（プラン以外）|
| PUT | `/zeal/members/{id}` | `Zeal\MemberController@update` | `zeal.members.update` | 更新処理（プラン以外）|
| **POST** | **`/zeal/members/{id}/change-plan`** | **`Zeal\MemberController@changePlan`** | **`zeal.members.change-plan`** | **プラン変更（履歴レコード作成 + キャッシュ更新）** |
| **POST** | **`/zeal/members/{id}/withdraw`** | **`Zeal\MemberController@withdraw`** | **`zeal.members.withdraw`** | **退会処理（最終契約クローズ + withdrew_on 設定）** |
| DELETE | `/zeal/members/{id}` | `Zeal\MemberController@destroy` | `zeal.members.destroy` | 削除（要：経営層） |
| GET | `/zeal/plans` | `Zeal\PlanController@index` | `zeal.plans.index` | プラン一覧 |
| GET | `/zeal/plans/create` | `Zeal\PlanController@create` | `zeal.plans.create` | プラン登録 |
| POST | `/zeal/plans` | `Zeal\PlanController@store` | `zeal.plans.store` | |
| GET | `/zeal/plans/{id}/edit` | `Zeal\PlanController@edit` | `zeal.plans.edit` | プラン編集 |
| PUT | `/zeal/plans/{id}` | `Zeal\PlanController@update` | `zeal.plans.update` | |
| DELETE | `/zeal/plans/{id}` | `Zeal\PlanController@destroy` | `zeal.plans.destroy` | |
| GET | `/zeal/trainers` | `Zeal\TrainerController@index` | `zeal.trainers.index` | トレーナー一覧 |
| POST | `/zeal/trainers` | `Zeal\TrainerController@store` | `zeal.trainers.store` | （Ajax 追加）|
| PUT | `/zeal/trainers/{id}` | `Zeal\TrainerController@update` | `zeal.trainers.update` | （Ajax 更新）|
| DELETE | `/zeal/trainers/{id}` | `Zeal\TrainerController@destroy` | `zeal.trainers.destroy` | |
| GET | `/admin/zeal/member-import` | `Admin\ZealMemberImportController@index` | `admin.zeal.member-import` | CSV インポート画面（経営層のみ）|
| POST | `/admin/zeal/member-import/preview` | `Admin\ZealMemberImportController@preview` | | プレビュー |
| POST | `/admin/zeal/member-import/execute` | `Admin\ZealMemberImportController@execute` | | 取込実行 |

**ルート総数**: 約 26 本（v1 から change-plan / withdraw が追加）

ペアプラン作成は `/zeal/members/create?pair_parent_id={id}` のように主契約者 ID をクエリパラメータで渡す方式。

---

## 6. UI / UX 方針

### 6.1 ダッシュボード（`/zeal`）

トップに KPI カード、続いて月次グラフ、リスト系ウィジェットの順で配置。年度フィルター（過去2年〜来年度+1 + 全期間）と期フィルター（全期/上期/下期）を上部に置く。

**レポート要件**:

| # | カード/グラフ | 集計クエリ概要 |
|---|--------------|---------------|
| 1 | 在籍会員数（合計 / プラン別 / 性別 / 年代別） | zeal_members WHERE withdrew_on IS NULL の集計 |
| 2 | 月別 入会数 / 退会数 / 純増数 | YEAR_MONTH(joined_on) と YEAR_MONTH(withdrew_on) で集計 |
| 3 | **月会費売上（プラン別、税抜・税込併記）** | **下記「6.9 月会費売上集計」のクエリ参照** |
| 4 | 体験予約からの入会率 | gym_inquiries の status='入会' / (status='入会' + status='未入会') |
| 5 | 集客チャネル別の入会数 | zeal_members.acquisition_source で集計 |
| 6 | 退会理由の集計 | zeal_members.withdraw_reason で集計 |
| 7 | 月次グラフ（売上推移・会員推移） | Chart.js（cdn.jsdelivr.net 経由）。売上推移は月会費売上クエリを月次で評価 |

担当トレーナー別の会員数はスコープ外。

### 6.2 体験予約一覧（`/zeal/inquiries`）

- 外部 DB `gym_inquiries` を参照
- フィルター: ステータス / 月（inquiry_date） / 検索キーワード（name 部分一致）
- カラム: 問合せ日 / 体験日 / 体験時間 / 氏名 / ステータス（バッジ）/ 契約プラン / 性別 / 年齢 / 電話 / メール
- ソート: 問合せ日 desc 既定
- 各行 → 詳細リンク
- 詳細画面（`show`）: 全カラム表示。`zeal_members.gym_inquiry_id` で紐付く会員があれば「会員詳細へ」ボタン表示
- **書き込みは不可**（CRUD 操作 UI を一切出さない）

### 6.3 会員一覧（`/zeal/members`）

- フィルター: ステータス（在籍中 / 退会済み）/ プラン / 担当トレーナー / 性別 / 入会月 / キーワード（氏名・メール・電話）
- カラム: 氏名 / フリガナ / 性別 / 年齢（生年月日から算出）/ プラン / 担当トレーナー / 入会日 / 退会日 / 詳細ボタン
- 在籍 / 退会の判定: `withdrew_on IS NULL` ⇔ 在籍中
- ペアプラン会員は氏名横にバッジ表示（「ペア」）+ 主契約者へのリンク
- **プラン名表示は `current_plan_id` キャッシュを参照**（履歴テーブル JOIN を一覧で行わない、N+1 回避）
- ページネーション 20 件/ページ

### 6.4 会員詳細（`/zeal/members/{id}`）

**4 タブ構成（v2 で「契約履歴」タブを追加）**:

1. **基本情報**: 氏名・連絡先・住所・性別・年齢・入会日・現プラン（`current_plan_id`）・担当トレーナー・退会情報・備考
2. **集客・目的**: acquisition_source / purpose / 体験予約とのリンク（gym_inquiries 詳細へ）
3. **契約履歴**（v2 新設）:
   - `zeal_member_contracts` を `period_start desc` で表示
   - カラム: 期間（period_start 〜 period_end）/ プラン名 / 適用価格（税抜・税込併記）/ キャンペーン適用フラグ / 変更理由 / 備考
   - 「**プラン変更**」ボタン: モーダルで `/zeal/members/{id}/change-plan` POST 送信
   - 「**退会処理**」ボタン: 退会日と退会理由を入力するモーダル → `/zeal/members/{id}/withdraw` POST
4. **添付ファイル**: 既存 attachment-section コンポーネントを `@include` で組み込み

ペア主契約者の場合は「同伴会員」タブを追加して、紐付くペア会員一覧を表示する（タブが 5 つになるケース）。

### 6.5 会員登録・編集（`/zeal/members/create`, `/edit`）

- `customers/_form.blade.php` の class 構造を踏襲（`form-input`, `gap-3`, `grid grid-cols-1 sm:grid-cols-2`）
- 入力項目はモーダル化せず、1 ページに縦積み
- フリガナ自動入力（既存 `compositionend` パターン流用）
- 郵便番号 → 住所自動入力（zipcloud API、既存パターン流用）
- ペアプラン主契約者選択（`pair_parent_member_id`）はオートコンプリート

**新規登録時**:
- 入会日・初回プラン・適用価格（通常 / キャンペーン）・キャンペーン適用フラグ を選択
- store 内部で `zeal_members` INSERT + `zeal_member_contracts` INSERT（`change_reason='new_join'`）+ `current_plan_id` SET をトランザクションで実行

**編集時**:
- プラン変更は edit 画面では行えない（プラン変更は別エンドポイント `change-plan`）
- edit で扱うのは個人情報のみ（氏名・連絡先・住所・備考・担当トレーナー等）

### 6.6 プラン変更モーダル（v2 新設）

会員詳細「契約履歴」タブの「プラン変更」ボタン押下で開くモーダル。

入力項目:
- 新プラン（`zeal_plans` セレクト、active=1 のみ）
- 変更日（既定: 翌月 1 日。月初切替を運用ルールとする）
- 適用価格タイプ: 通常価格 / キャンペーン価格（キャンペーンが現状適用可能なら選択可）
- 適用価格（税抜）: タイプ選択で自動入力、手動編集も可（特例価格対応）
- 備考

POST `/zeal/members/{id}/change-plan` 受信後、Controller 側で `DB::transaction` 内に:
1. 現契約 (`period_end IS NULL`) の `period_end` を変更日の前日に UPDATE
2. 新契約レコードを INSERT（`period_start = 変更日`, `change_reason = 'plan_change'`, `applied_price_excl = 入力値`, `tax_rate_at_contract = settings.tax_rate`）
3. `zeal_members.current_plan_id` を新 plan_id に UPDATE

参考実装: `app/Http/Controllers/Mansion/ContractController.php::revise`（履歴 + 本体即時更新のハイブリッド型）。

### 6.7 プランマスタ（`/zeal/plans`）

- 一覧: プラン名 / 通常価格 / キャンペーン価格 / キャンペーン期間 / 月間利用上限 / 同時予約数 / 含まれるコース / 有効フラグ
- 登録・編集: フォームは標準（`_form.blade.php` パターン）
  - キャンペーン期間は `<input type="date">` ではなく案 C datepicker を使用
- v1 シード: PDF の 4 プラン + ペアプランを `database/sql/zeal_plans_seed.sql` で投入

### 6.8 トレーナーマスタ（`/zeal/trainers`）

- 既存の `realestate/suppliers` のような Ajax CRUD パターンを踏襲
- カラム: 氏名 / 表示順 / 有効フラグ / 編集・削除ボタン
- 一覧画面内インライン追加

### 6.9 月会費売上集計（v2 新設、ダッシュボード #3 / #7 用）

**月別売上クエリ**:

```sql
SELECT
  p.name AS plan_name,
  COUNT(*) AS member_count,
  SUM(mc.applied_price_excl) AS revenue_excl,
  SUM(ROUND(mc.applied_price_excl * (1 + mc.tax_rate_at_contract / 100))) AS revenue_incl
FROM zeal_member_contracts mc
JOIN zeal_plans p ON p.id = mc.plan_id
WHERE mc.period_start <= LAST_DAY(?)              -- ? = '2026-05-01' 等の月初
  AND (mc.period_end IS NULL OR mc.period_end >= ?)
GROUP BY p.id, p.name
ORDER BY p.display_order;
```

**月次推移グラフ用**: 上記クエリを過去 N か月分ループ（または CTE で月リスト × LATERAL JOIN）して時系列データを構築する。

**集計の前提**:
- 1 か月内にプラン変更があった会員はそのプランで複数月分計上されないよう、`period_start <= 月末 AND (period_end IS NULL OR period_end >= 月初)` のフィルタを使用（変更日を月初に統一する運用前提）
- 月途中変更の日割り計算は v2 ではスコープ外（運用で月初切替に統一）

### 6.10 税抜・税込表示ルール（v2 新設）

- **DB 保存値はすべて税抜**（`applied_price_excl`, `regular_price_excl`, `campaign_price_excl`）
- **税率は契約レコードに焼き付け**（`tax_rate_at_contract`、過去の税率変動に対応）
- **表示**: 税抜が原則。重要画面では「**税抜 NN,NNN円**（税込 NN,NNN円）」形式で併記:
  - 会員詳細「契約履歴」タブの適用価格欄
  - ダッシュボード月会費売上カード
  - プラン詳細ページ
- **税込計算**: Eloquent アクセサ `getDisplayPriceWithTaxAttribute()` で `applied_price_excl × (1 + tax_rate_at_contract / 100)` を返す（`HsContract::getBuildingTax()` と同パターン）
- **税率取得**: 既存の `getDefaultTaxRate()` ヘルパを共用（`app/Http/Controllers/Housing/ContractController.php` L186-195 を参考）

### 6.11 会員 CSV インポート（`/admin/zeal/member-import`）

- 既存 `admin/customers/import` および `admin/tenant-import` の 3 ステップパターン踏襲
  1. ファイル選択 → 列マッピング
  2. プレビュー（バリデーション結果表示）
  3. 取込実行
- 期待列: 氏名 / フリガナ / 性別 / 生年月日 / 電話 / メール / 郵便番号 / 住所 / 入会日 / 退会日 / プラン名（zeal_plans に存在チェック）/ 担当トレーナー名（zeal_trainers に存在チェック）/ 集客チャネル / 目的 / 備考
- プラン名・トレーナー名は名前マッチング（存在しないものはエラーで表示）
- 既存会員（同名 + 同電話）の更新 / 重複警告
- **取込時は `zeal_member_contracts` の最初の契約レコード（`change_reason='new_join'`）も自動生成**（プラン名と現状の通常価格 or キャンペーン価格を適用）

---

## 7. ロールとアクセス制御

| ロール | 既存定義 | ZEAL での権限 |
|--------|---------|--------------|
| executive | 経営層 | 全機能 + CSV インポート + 削除 |
| manager | 管理者 | 会員 CRUD・プラン変更・退会処理・プラン CRUD・トレーナー CRUD・体験予約閲覧 |
| staff | 一般担当 | 会員閲覧・登録・編集 / 体験予約閲覧（プラン変更・退会処理・削除不可） |

**ミドルウェア**:
- ルート全体に `role:executive,manager,staff` + `belongsToDepartment('zeal')`
- プラン変更 / 退会処理 ルートは `role:executive,manager`
- 削除系ルートは `role:executive,manager`
- CSV インポートは `role:executive`

**サイドバー表示制御**:
- `sidebar.blade.php` 冒頭で `$hasZealAccess = $isExecutive || $user->belongsToDepartment('zeal');` を追加
- `@if($hasZealAccess)` でグループを囲む

---

## 8. 既存パターンの活用

実装時は以下を流用してスクラッチ実装を最小化する。

| 既存パターン | 流用先 |
|------------|--------|
| `customers/_form.blade.php` の form 構造 | `zeal/members/_form.blade.php` |
| `admin/customers/import` の 3 ステップ CSV 取込 | `admin/zeal/member-import` |
| `realestate/suppliers` の Ajax CRUD | `zeal/trainers` |
| zipcloud 郵便番号 API（既存 JS） | 会員登録・編集の住所入力 |
| `attachment-section` コンポーネント | 会員詳細 |
| 既存 `housing/dashboard` の年度+期フィルター UI | ZEAL ダッシュボード |
| Chart.js (`cdn.jsdelivr.net`) | 月次グラフ |
| **`Mansion/ContractController::revise` の DB::transaction（履歴 + 本体即時更新）** | **`Zeal\MemberController::changePlan` のトランザクション処理** |
| **`ms_contract_revisions` テーブル DDL（ON DELETE CASCADE 含む）** | **`zeal_member_contracts` の DDL 構造** |
| **`Housing/ContractController::getDefaultTaxRate` (L186-195)** | **`settings.tax_rate` の取得ヘルパ** |
| **`HsContract::getBuildingTax()` / `getSellingPriceTotalWithTax()`** | **税込計算アクセサのパターン** |
| **`housing/contracts/show-building.blade.php` の「税抜 / 消費税 / 税込」表示** | **月会費の税抜・税込併記 UI** |

---

## 9. サイドバー配置

manage のサイドバーに、DAD グループの直下に新規グループ「ZEAL」を追加する。

```
住宅事業
DAD
ZEAL              ← 新規
├ ダッシュボード
├ 会員管理
├ 体験予約
├ プランマスタ
└ トレーナーマスタ

システム管理（経営層）
└ ZEAL サブ見出し
  └ 会員 CSV インポート
```

アクセント色は既存統一の `bg-emerald-50` を使用（変更なし）。

---

## 10. フェーズ計画

DAD・賃貸マンションと同様、3 フェーズで進める。

### Phase 1: 要件定義（**本ドキュメント**）

- 確定済み

### Phase 2: モック作成（HTML）

配置先: `docs/mockups/zeal/`

| ファイル | 役割 | 必要性 |
|---------|------|------|
| `dashboard.html` | ZEAL ダッシュボード（KPI + 月会費売上 + グラフ） | ★ ZEAL 独自レイアウト |
| `members/index.html` | 会員一覧 | ★ ZEAL 独自フィルター |
| `members/show.html` | 会員詳細（**4 タブ構成 + プラン変更モーダル**） | ★ 契約履歴タブが新規 UI |
| `members/_form.html`（create/edit 共通モック） | 会員登録・編集 | ☆ customers と類似だがプラン/トレーナー選択独自 |

**モック点数: 4 本**（残りの一覧 / 詳細 / 登録 系は既存テンプレ流用で実装段階で起こす）

### Phase 3: Laravel 実装

| Phase | 内容 | 主な成果物 |
|-------|------|-----------|
| 3-A | 基盤 | DDL SQL ファイル（zeal_stores / zeal_plans / zeal_trainers / **zeal_member_contracts** / zeal_members を `database/sql/` に配置し phpMyAdmin で直接実行）、**Enum 6 本**（v1 比 +1: ZealContractChangeReason）、Model、サイドバー追加、`config/database.php` の `zeal` 接続定義、`GymInquiry` Model（外部 DB 参照） |
| 3-B | 体験予約閲覧 | `Zeal\InquiryController` index/show、Blade 2 本 |
| 3-C | プランマスタ | `Zeal\PlanController` フル CRUD（**キャンペーン期間入力含む**）、Blade 4 本、初期 SQL シード |
| 3-D | トレーナーマスタ | `Zeal\TrainerController` Ajax CRUD、Blade 1 本 |
| 3-E | 会員管理 | `Zeal\MemberController` フル CRUD + **`changePlan` / `withdraw` メソッド（DB::transaction で履歴更新）**、Blade 5 本（show は 4 タブ）、attachment 連携、**プラン変更モーダル** |
| 3-F | 会員 CSV インポート | `Admin\ZealMemberImportController`、Blade 3 本（select / preview / result）、PhpSpreadsheet 利用、**取込時に `zeal_member_contracts` の new_join レコードも生成** |
| 3-G | ダッシュボード | `Zeal\DashboardController`、Blade 1 本、Chart.js、**月会費売上集計クエリ実装（6.9 のクエリ）**|
| 3-H | 30 点品質監査 + デプロイ | CLAUDE.md 準拠確認、本番デプロイ |

**合計**: Controller 7 本 / Blade 約 18 本 / ルート約 26 本 / Model 6 本（**zeal_member_contracts 追加**） / Enum 6 本

### 想定外で確認が必要な事項

実装着手前に下記を確定させる:

1. **外部 DB 接続情報**（`.env` への投入は導入時）
2. **gym_inquiries の status の正確な値リスト**（Enum バッジ色マッピングの最終確認）
3. **会員 CSV インポートのカラム仕様詳細**（旧シート 31 列のうち何を取り込むか）
4. **プラン変更時の日割り処理方針**: v2 のたたき台では「変更日を月初固定に統一（運用で月初切替）」とするが、月途中切替を許容する場合は日割り計算ロジックが必要

---

## 付録 A: 既存資産との整合

| 項目 | 既存値 | ZEAL での扱い |
|------|--------|--------------|
| 消費税率 | settings テーブル（`getDefaultTaxRate()` 経由）| 既存値を共用、契約時に `tax_rate_at_contract` に焼き付け |
| 金額表示 | 税抜 + 円サフィックス（例: `28,500,000円`、`¥` プレフィクス禁止）| 同準拠。重要画面では税抜 + 税込併記 |
| 担当者表示 | 苗字のみ（同姓重複時のみフルネーム）| 同準拠 |
| 年度開始 | 5 月 1 日（5月〜4月）| 同準拠 |
| バッジ | `badgeStyle()` メソッドからインラインスタイル | 同準拠 |
| Chart.js CDN | `cdn.jsdelivr.net` のみ | 同準拠 |
| Vite ビルド済 Tailwind | 既存クラスのみ使用、新規は `<style>` か inline | 同準拠 |

## 付録 B: 用語集

| 用語 | 意味 |
|------|------|
| パーソナル | 1 対 1 のトレーニング |
| セミパーソナル | 1 トレーナー : 最大 4 名のトレーニング |
| 枠 | プランで持てる「同時に保持できる未消化予約数」（例: 2 枠 = 来週月・火を同時に予約 OK） |
| ペアプラン | 既契約者の同伴で同セッションに参加する 1 名追加プラン（夫婦等。稀少） |
| 通い放題 | 月間利用回数の上限なし（ただし枠数の同時予約縛りあり） |
| 月 4 回 | 月間 4 回までのプラン。当月内未消化分は失効 |
| キャンペーン価格 | 通常価格より割引された期間限定価格（税込が通常税抜と一致） |
| gym_inquiries | 外部 DB の体験予約・問合せパイプラインテーブル |
| SCD Type-2 | Slowly Changing Dimension Type-2: 期間カラム（period_start / period_end）で履歴を保持する手法。`zeal_member_contracts` で採用 |
| 焼付け | 契約時の値（適用価格・税率）を契約レコードに保存し、後日マスタが変わっても影響を受けないようにすること |
