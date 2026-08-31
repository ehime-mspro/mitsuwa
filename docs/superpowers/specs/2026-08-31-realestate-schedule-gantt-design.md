# 工程表（ガント表示）— 設計書

**作成日**: 2026-08-31
**対象部署**: 不動産管理 / 住宅事業（**当面はこの 2 部署のみ**。利用者確認済み）
**モック**: `docs/mockups/realestate/schedule-gantt-proposals.html`（詳細カード 3案比較＋入力画面）/
`docs/mockups/realestate/schedule-board.html`（横断ボード）

---

## 1. 背景と目的

契約や着工のあとに走る工程（造成・開発許可・確定測量・分筆登記・建築確認・上棟・販売など）を、
**Excel の工程表のように横棒で見たい**という依頼。

現状の日付列は各親に数個ずつしか無く、工程を表すデータは**1 つも無い**。よって DB 追加が要る。

| 親 | 既存の日付列 |
|---|---|
| 仕入れ案件 `re_procurements` | `info_obtained_date` / `contract_date` / `settlement_date` |
| 分譲地PJ `re_projects` | `info_obtained_date` / `contract_date` / `settlement_date` |
| 建売物件 `hs_properties` | `scheduled_completion_date` / `actual_completion_date` |
| 注文住宅 `hs_custom_orders` | `contract_date` / `scheduled_completion_date` / `actual_completion_date` / `delivery_date` |

アプリ内にガント表示の前例は無い（DAD 工事案件が `period_start` / `period_end` を
持つが、数値として表示しているだけで棒は引いていない）。

⚠ **機能名は「契約後工程」ではなく「工程表」とする。** 建売物件は**売る前に建てる**ので
「契約後」が成り立たない。依頼の出発点は不動産の契約後工程だったが、
住宅事業まで広げた時点でその呼び方は対象を正しく表さなくなった。

---

## 2. 確定した事項

| # | 論点 | 決定 | 理由 |
|---|---|---|---|
| 1 | 工程名は固定マスタか | **案件ごとに自由に足す**（マスタ無し） | 案件によって発生する工程が違う（利用者回答） |
| 2 | 表示場所 | **詳細ページ内カード ＋ 横断ボードの両方** | 利用者回答 |
| 3 | 描画方式 | **1 行 1 本（モックの案1）** | 利用者選択。入力が軽く印刷にも強い |
| 4 | 日付の持ち方 | **予定と実績の 2 組（4 日付）** | 利用者選択。詳細は 1 本のままで、遅れは横断ボードで見る |
| 5 | テーブル構成 | **ポリモーフィック 1 本** | ボードが 1 クエリで済む／Controller・Blade・テストを親の数だけ書かずに済む |
| 6 | 既存の日付列 | **自動で ◆ を描く**（工程行として作らない） | 二重入力を作らない |
| 7 | 対象 | **仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の 4 親** | 利用者回答。建売契約 `hs_contracts` は対象外（工期は物件に属する） |
| 8 | 実装の順 | **4 親を今回まとめて作る** | 利用者選択。#5 のおかげで増えるのはルートと権限テストだけ |
| 9 | ボードの分け方 | **部署ごとに 2 つ**（不動産用・住宅用） | 利用者選択。既存のサイドバーと `department.access` の作りにそのまま乗る |

⚠ #3 と #4 の組み合わせが本設計の肝。**画面は 1 本しか引かないが DB には予定・実績の両方が入る**ため、
詳細はスッキリしたまま、ボードで「遅延 4」「+16 日」が出せる。
実績を空のまま運用すれば見た目は「予定だけの単純な工程表」になり、後から実績を入れ始めても画面は変わらない。

### 2.1 モックと違う点（モックを正本にしないこと）

モックは不動産だけを想定して先に描いたので、本設計と 4 点ずれている。**本設計を正本とする。**

| # | モック | 本設計 | 理由 |
|---|---|---|---|
| 1 | ドラッグハンドル（⠿）で並べ替え | **↑↓ ボタン** | 手書き JS が増えモバイルで壊れやすい（§4.4） |
| 2 | `isLate()` は「実績なし」を遅延と見なさない | **未着手のまま予定終了を過ぎたら遅延** | 着手すらしていない工程が一番危ない（§5.4） |
| 3 | 担当者フィルタ（佐伯 / 山西 / 古澤） | **無し** | **4 親のどれにも担当者カラムが無い**（§4.2） |
| 4 | 不動産の案件だけ | **住宅事業の 2 親も並ぶ** | #7 |

⚠ #2 と #3 は**モックを見比べても気づけない**。#2 はサンプルデータに該当が 1 件も無いので
KPI「遅延 2 件」がどちらの規則でも同じ数字になる。#3 は架空の担当者名を描いてあるため、
実物があるように見える。

---

## 3. データ構造

### 3.1 新テーブル `schedule_steps`

```sql
CREATE TABLE `schedule_steps` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedulable_type` VARCHAR(255)    NOT NULL,   -- 下記 4 クラスのいずれか
  `schedulable_id`   BIGINT UNSIGNED NOT NULL,
  `name`             VARCHAR(100)    NOT NULL,   -- 工程名（自由入力）
  `category`         VARCHAR(20)     NOT NULL DEFAULT 'other',
  `planned_start`    DATE            NULL,
  `planned_end`      DATE            NULL,
  `actual_start`     DATE            NULL,
  `actual_end`       DATE            NULL,
  `sort_order`       INT             NOT NULL DEFAULT 0,
  `notes`            VARCHAR(255)    NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `updated_by`       BIGINT UNSIGNED NULL,
  `created_at`       TIMESTAMP       NULL,
  `updated_at`       TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sched_owner` (`schedulable_type`, `schedulable_id`, `sort_order`),
  KEY `idx_sched_planned_start` (`planned_start`),
  KEY `idx_sched_planned_end`   (`planned_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

⚠ **テーブル名に `re_` を付けない。** 不動産と住宅の両方がぶら下がるため。
部署をまたぐ既存テーブル（`attachments` / `buyers` / `users`）と同じく接頭辞なしにする。

**外部キーは張らない。** `schedulable_type` が 4 種類あるため単一の FK では表現できない。
親を消したときの削除は §3.5。

⚠ **`re_*` / `hs_*` と同じく raw SQL 管理**（Laravel migration ではない）。
本テーブルは **`database/sql/2026-08-31-create-schedule-steps.sql`** と
**`tests/Concerns/CreatesRealEstateSchema.php`** の**両方**に足す。
片方を忘れると「本番は動くのにテストだけ落ちる」または「テストは緑なのに本番で `Unknown column`」になる。

⚠ 追加先の trait は **1 本でよい**（2026-08-31 実測）。`CreatesRealEstateSchema.php` は
名前に反して **4 親すべて**を作っている:
`re_procurements` / `re_projects` / `hs_properties` / `hs_custom_orders`（ほかに
`buyers` / `re_contracts` / `hs_contracts` など計 16 テーブル）。
住宅用の別 trait は存在しない。

### 3.2 親（`schedulable_type` に入る 4 クラス）

| クラス | 部署 | コード列 | 名称列 |
|---|---|---|---|
| `App\Models\ReProcurement` | realestate | `procurement_code` | `property_name` |
| `App\Models\ReProject` | realestate | `project_code` | `project_name` |
| `App\Models\HsProperty` | housing | `property_code` | `property_name` |
| `App\Models\HsCustomOrder` | housing | `order_code` | `order_name` |

⚠ **コード列・名称列の名前が親ごとに違う。** ボードで直に `$model->name` と書くと
静かに空欄になる。次の trait 経由でのみ触ること。

### 3.3 trait `App\Models\Concerns\HasScheduleSteps`

4 親が `use` する。親ごとの差はこの trait のメソッドを override して吸収し、
**ボードと共通 partial は親の実クラスを知らないまま動く**。

```php
scheduleSteps(): MorphMany            // 共通実装（sort_order 順）
scheduleCode(): string                // 親が override（procurement_code など）
scheduleName(): string                // 親が override
scheduleUrl(): string                 // 親が override（詳細へのリンク）
scheduleDepartment(): string          // 'realestate' | 'housing'
autoMilestones(): array               // §3.4
```

### 3.4 自動マイルストーン（既存の日付列から ◆ を描く）

工程行を作らず、親が既に持っている日付から ◆ を描く。定義は `autoMilestones()` が返す。

| 親 | 自動 ◆ |
|---|---|
| `ReProcurement` | 契約 = `contract_date` ／ 決済 = `settlement_date` |
| `ReProject` | 契約 = `contract_date` ／ 決済 = `settlement_date` |
| `HsProperty` | 完成 = `actual_completion_date` ?? `scheduled_completion_date` |
| `HsCustomOrder` | 契約 = `contract_date` ／ 完成 = `actual_completion_date` ?? `scheduled_completion_date` ／ 引渡し = `delivery_date` |

**塗り分けは日付だけで決める**（列が予定か実績かを知る必要がない）:

```
その日付が 今日以前  → 塗りつぶし ◆
その日付が 今日より後 → 白抜き ◆
日付が NULL          → 描かない
```

⚠ **`scheduled_completion_date` と `actual_completion_date` は同じ「完成」という 1 つの節目**なので、
◆ を 2 つ描かない。実績があれば実績、無ければ予定の位置に 1 つだけ描く。
2 つ描くと「完成が 2 回ある」ように見える。

⚠ **自動 ◆ は読み取り専用。** 工程表の入力欄からは触れない。動かしたければ親の編集画面で直す。

### 3.5 親の削除

親を削除するとき、工程は**一緒に消す**（削除をブロックしない）。
工程は親に完全従属する記録で、単体で参照する価値が無いため。

⚠ 既存の `DeletionBlockers` に**工程を足さない**こと。足すと
「工程を 1 行でも書いた案件は二度と消せない」という別の不具合になる。
各親の `destroy()` で `$model->scheduleSteps()->delete()` を先に呼ぶ。

⚠ **4 親すべての `destroy()` に入れる。** 1 つ忘れると、そこだけ孤児レコードが溜まり続ける
（`schedulable_id` は再利用されるので、**将来同じ id の別案件に他人の工程が生える**）。
テストで 4 親ぶんを対称に固定する。

### 3.6 `category`（色分けだけに使う）

`App\Enums\ScheduleStepCategory`（backed enum, string）。

| value | label | 色 |
|---|---|---|
| `permit` | 許認可・申請 | `#3B82F6` |
| `work`   | 工事 | `#059669` |
| `survey` | 測量・登記 | `#8B5CF6` |
| `sale`   | 販売 | `#F59E0B` |
| `other`  | その他 | `#6B7280` |

色は enum の `color()` が hex を返す。Tailwind クラスは返さない（inline style で使うため）。

⚠ 分類は**色分け以外の意味を持たない**。集計にも権限にも使わない。
⚠ **住宅事業向けに `work` を細分化しない**（着工・上棟・内装…）。工程名が自由入力なので、
分類を増やすほど「どれを選ぶか」で迷いが増える。色は 5 色で足りる。

### 3.7 行の種別（マイルストーンか棒か）

**専用フラグは持たない。** 日付の入り方だけで決まる。

```
マイルストーン（◆）:  planned_end が NULL かつ actual_start が NULL かつ actual_end が NULL
                       → 位置 = planned_start
棒:                   上記以外
描画しない:            planned_start と actual_start がどちらも NULL
                       （左の一覧には出し、期間欄に「日付未設定」と表示する）
```

⚠ **「描画しない」を握り潰さない。** 日付が入っていない行を黙って消すと、
利用者は「保存できていない」と誤解する。必ず一覧には残す。

---

## 4. 画面

### 4.1 詳細ページの「工程表」カード（4 画面）

**共通 partial `resources/views/_partials/_schedule_section.blade.php` を 1 本だけ作り**、
4 つの `show.blade.php` が `@include` する。

| 画面 | 挿入位置 |
|---|---|
| `realestate/procurements/show.blade.php` | 原価管理カードの下 |
| `realestate/projects/show.blade.php` | 原価管理カードの下 |
| `housing/properties/show.blade.php` | 建築・原価情報の下 |
| `housing/custom-orders/show.blade.php` | 建築・原価情報の下 |

⚠ **partial は 1 本にする。** 同じマークアップを 4 箇所にコピーすると、一部だけ直す事故が起きる
（Bug #41 / 地図 POI の件で実測済み）。テストで「定義が 1 箇所であること」も固定する。

⚠ **partial は部署ディレクトリに置かない。** `realestate/_partials/` に置くと
住宅から `@include` したときに「不動産の部品を住宅が借りている」形になり、
次に触る人が不動産都合で壊す。中立な `resources/views/_partials/` に置く。

**新規登録画面（create）には入れない。** 案件を作る時点で工程は無く、
親の id が無いと保存先が決まらないため。詳細ページから足す。

**中身**（モック案1 のとおり）:

- 左カラム 262px: 工程名（長ければ省略記号）＋ 期間テキスト（`3/16〜7/03`）
- 右: 月グリッド＋横棒 1 本。四半期の頭（1/4/7/10 月）だけ罫線を濃くする
- 今日の位置に赤い破線＋「今日 8/31」のピル（最上段のみ）
- 凡例（色 5 種＋ ◆）
- 工程が 0 件のときは「工程が登録されていません」＋ 追加ボタン（空のガントを描かない）

### 4.2 横断ボード（2 画面）

| URL | ルート名 | サイドバー | 対象 |
|---|---|---|---|
| `/realestate/schedules` | `realestate.schedules.index` | 不動産管理 › 「分譲地」の次に「工程表」 | 仕入れ案件・分譲地PJ |
| `/housing/schedules` | `housing.schedules.index` | 住宅事業 › 「注文住宅」の次に「工程表」 | 建売物件・注文住宅 |

**中身は 2 つとも同じ**（`App\Services\ScheduleBoardService` を共有し、対象クラスの配列だけ差し替える）:

- KPI 4 枚: 工程が動いている案件 / 予定より遅れている案件 / 30 日以内に始まる工程 / 30 日以内に終わる工程
- フィルタ: **種別**（すべて / その部署の 2 種）・**ステータス**（進行中 / すべて / 遅延 / 完了）・**キーワード**（案件名・工程名）
- ズーム: 週 / 月 / 四半期（月ヘッダの粒度が変わるだけ。棒の位置計算は同じ）
- 1 行 1 案件。工程を色帯で並べ、**期間が重なる工程は段に振り分ける**（§5.3）
- 行頭の ▸ で展開すると、その案件の工程が予定（細いグレー）＋実績（色つき）の 2 段で出る
- 遅れている工程には赤い枠、まだ始まっていない工程は薄く

⚠ **担当者フィルタは作らない。** モックには描いてあるが、**4 親のどれにも担当者カラムが無い**
（`staff_user_id` を持つのは DAD 工事案件だけ。既存の一覧にも担当者フィルタは存在しない）。
`created_by` / `updated_by` で代用しない —— あれは「登録した人 / 最後に触った人」であって担当者ではなく、
一括投入や他人の軽微な修正で簡単に別人になる。担当者が要るなら**親テーブルへの列追加が先**で、
それはこの機能の範囲外。

⚠ **工程が 0 件の案件はボードに出さない。** 出すと、まだ使い始めていない案件で画面が埋まる。
「工程未登録 12 件」のような件数だけを KPI の下に出し、そこから一覧へ飛ばす。

⚠ **ページングしない**（絞り込み後の全件）。周辺ビル調査の地図タブと同じ判断。
1 部署の案件数が 200 を超えたら見直す。

⚠ **フィルタのクエリキーに null を渡さない。** `Arr::query()` は null のキーを丸ごと捨てるので、
リンク生成前に `''` へ正規化する（Bug #31）。

### 4.3 部署をまたがせない

ボードは `department.access:<部署>` の中に置くので、**住宅だけの権限の利用者に不動産の案件は出ない**。

⚠ **`ScheduleBoardService` に「全部の親クラス」を既定値として持たせない。**
呼び出し側が対象クラスを渡す形にする。既定値を持たせると、新しい部署のボードを足した人が
引数を省略した瞬間に**全部署の案件が漏れる**。引数は必須にする。

⚠ 工程の CRUD 側も同じ。ルートが親の prefix の下にあるので `department.access` は効くが、
**`{step}` が本当にその親のものか**は別途 §6 のガードで確かめる。

### 4.4 入力

原価管理と同じ **Ajax 即時保存**（詳細画面の既存流儀）。

**入力項目**: 工程名 / 種類 / 予定開始 / 予定終了 / 実績開始 / 実績終了 / 備考

⚠ **並べ替えはドラッグではなく ↑↓ ボタン**にする。
モックにはドラッグハンドル（⠿）を描いたが、ドラッグは手書き JS が増えモバイルで壊れやすい。
ZEAL の項目マスタ（`Admin\ZealSimulationCategoryController`）に drag&drop の前例があるので、
実装時にそれをそのまま流用できるなら流用してよい。**新しくライブラリは入れない。**

⚠ **JSON を GET する `fetch` には `X-Requested-With: XMLHttpRequest` を必ず付ける**（Bug #35）。
`Accept: application/json` だけでは効かない。付け忘れるとバリデーションエラー時の `back()` が
その API へ飛び、入力が全消失する。

⚠ **`@json` を `x-data` 属性に入れない**（Bug #23）。工程データは `<script>` 内の named function で組む。
⚠ **`<script>` 内のコメントに `@json` や `<x-` と書かない**（Bug #30）。書くなら `@@json`。
⚠ **日本語入力欄の Enter ハンドラには `$event.isComposing ||` を挟む**（工程名の入力欄）。

### 4.5 バリデーション

| 項目 | ルール |
|---|---|
| `name` | required, string, max:100 |
| `category` | required, in:（enum の値） |
| `planned_start` / `planned_end` / `actual_start` / `actual_end` | nullable, date |
| `planned_end` | after_or_equal:planned_start |
| `actual_end` | after_or_equal:actual_start |
| `notes` | nullable, string, max:255 |

⚠ **実績終了だけが入って実績開始が空、という状態を許さない**（`actual_end` が入るなら `actual_start` も必須）。
許すと §5.2 の分岐が「実績開始が無い」側へ落ち、**実績終了を入れたのに予定の棒が出る**という
無音の食い違いになる。逆（実績開始だけ）は「進行中」として正当なので許す。

⚠ **`lang/ja/validation.php` の `attributes` に和名を足す**（Bug #37）。
`name` は既に別画面と衝突する語（顧客名 / 物件名 …）なので、**コントローラの `validate()` 第 3 引数**で
「工程名」を上書きする（第 2 引数は messages）。
走査テスト `JapaneseValidationMessagesTest` が和名漏れを自動で拾う。

⚠ **`planned_start` も `actual_start` も空の行を弾かない。** 「先に名前だけ並べて後から日付を入れる」
のは自然な使い方。§3.7 のとおり一覧に残して描画対象から外す。

---

## 5. 描画のアルゴリズム

**JS ライブラリを足さない。** 日付 → 位置(%) の変換は PHP 側で行い、
Blade は inline style で置くだけにする。外部 CDN は 1 本も増えない（Chart.js も使わない）。

### 5.1 `App\Support\GanttScale`

時間軸の 1 区間 `[from, to]` を保持し、日付を % に変換する。

```php
left(CarbonInterface $d):                  ($d - $from)->days / $total * 100
width(CarbonInterface $s, CarbonInterface $e): (($e - $s)->days + 1) / $total * 100
```

⚠ **`width` の `+ 1` が要る。** 開始日と終了日の両端を含めるため。
これが無いと 1 日だけの工程（`start === end`）が**幅 0 になって消える**。

⚠ **日付は必ず `startOfDay()` に揃えてから引く。** 揃えないと実行環境の timezone や
時刻成分で ±1 日ずれる（Bug #54 ①: Laravel を起動しない Unit テストは
`config/app.php` ではなく `php.ini` の timezone に支配される）。
`GanttScaleTest` は `setUp()` で `date_default_timezone_set('UTC')` を固定し、
**`tearDown()` で必ず戻す**（戻さないと同一プロセスの後続テストへ漏れる）。

⚠ **範囲外の日付を渡されても壊さない。** 0% 未満 / 100% 超を返しうるので、
呼び出し側で 0〜100 に clamp する（棒が枠外へ飛び出してレイアウトを壊さないように）。
clamp は `GanttScale` の責務にしない —— clamp してしまうと「範囲がおかしい」ことに気づけなくなる。

### 5.2 描画区間（1 行 1 本）

```
実績開始がある → [actual_start, actual_end ?? 今日]   （actual_end が NULL なら「進行中」＝右端を開く）
実績開始が無い → [planned_start, planned_end ?? planned_start]
```

⚠ **実績を優先する。** 「予定 5/18〜9/30・実績 6/1〜10/16」の工程は **6/1〜10/16 の 1 本**が出る。
詳細画面では遅れは棒からは読めない（それが案1 を選んだということ）。遅れはボードで見る。

### 5.3 段の振り分け（`App\Support\LanePacker`。ボードのサマリ行）

開始が早い順に見て、**入る段があればそこ、無ければ新しい段**（greedy interval partitioning）。

```
各段の最後の要素の終了 < その工程の開始  → 同じ段に置く
どの段にも入らない                        → 新しい段
```

⚠ **判定は「より後」（`<`）であって「以降」ではない。**
前の工程が 9/30 に終わり次が 9/30 に始まる場合は**別の段**になる。
同じ段に置くと棒が 1 日ぶん重なって 1 本に見えるため。10/1 開始なら同じ段で隣り合う。

行の高さ = `8 + 段数 × 17 + 6` px。実データ相当のサンプルで最大 3 段。

⚠ **段分けが無いと重なった工程が潰れて読めない。** モックの初版がそうなっていた（実測）。

### 5.4 遅延の判定

```
実績終了あり:            actual_end > planned_end  → 遅延日数 = actual_end - planned_end
実績開始あり・終了なし:  今日 > planned_end        → 遅延日数 = 今日 - planned_end
実績なし:                今日 > planned_end        → 遅延（未着手のまま予定を過ぎた）
planned_end が NULL:     遅延判定しない
```

⚠ **「実績なしでも予定終了を過ぎていれば遅延」**を含める。含めないと、
**着手すらしていない工程が一番危ないのに一番静か**という逆転が起きる。
§2.1 のとおりモックはこの規則になっていない。**本節を正本とする。**

**判定は 1 箇所（`ScheduleStep` のメソッド、または `App\Support\ScheduleStepStatus`）に集約する。**
詳細カード・ボードのバッジ・KPI が別々に計算すると、画面ごとに数が食い違う（Bug #46）。

### 5.5 時間軸の範囲

| 画面 | 範囲 |
|---|---|
| 詳細カード | その案件の全日付（工程の 4 日付＋§3.4 の自動 ◆ の日付）の最小〜最大。前後に 1 ヶ月の余白を取り月初・月末に丸める。今日が範囲外なら今日も含める |
| ボード | 既定 = 今日の 6 ヶ月前 〜 12 ヶ月後。フィルタで変更 |

⚠ **日付が 1 つも無い案件は時間軸を作れない。** その場合はガントを描かず
「工程が登録されていません」を出す（0 除算とレイアウト崩れの両方を防ぐ）。

### 5.6 レイアウト上の注意

⚠ **`x-show` と `:style` を同じ要素に置かない**（Bug #32）。`x-show` は `display` を自分のものとして扱い、
`:style` に書いた `display: flex` を奪う。出し分けが要るときは内側のラッパーに寄せる。

⚠ **CSS Grid のトラックは `minmax(0, 1fr)`**（Bug #29）。素の `1fr` は最小値が `auto` なので、
中身の min-content 幅でカードがコンテンツ幅を超えて膨らみ、`<main>` に横スクロールが出る。
ガントは `overflow-x: auto` のコンテナに入れ、**`<main>` 自体は横に伸ばさない**。
検証はスクリーンショットでなく `main.scrollWidth === main.clientWidth` の実測で行い、**広い幅と狭い幅の両方**で測る。

⚠ **`style=` と `:style=` を併用しない**（Bug #2 / #5）。全部 `:style` にまとめる。

---

## 6. ルートと権限

**新規ルートは 18 本**（工程 CRUD 4 親 × 4 ＋ ボード 2）。

```php
// ---- ボード（各部署の prefix 内）----
Route::get('/schedules', [RealEstate\ScheduleBoardController::class, 'index'])->name('realestate.schedules.index');
Route::get('/schedules', [Housing\ScheduleBoardController::class,    'index'])->name('housing.schedules.index');

// ---- 工程 CRUD（各親の下 / role:executive,manager）----
// 4 親ぶん、同じ 4 本を対称に定義する
Route::post  ('/procurements/{procurement}/schedule-steps',         [ScheduleStepController::class, 'store'])  ->name('realestate.procurements.schedule-steps.store');
Route::patch ('/procurements/{procurement}/schedule-steps/reorder', [ScheduleStepController::class, 'reorder'])->name('realestate.procurements.schedule-steps.reorder');
Route::patch ('/procurements/{procurement}/schedule-steps/{step}',  [ScheduleStepController::class, 'update']) ->name('realestate.procurements.schedule-steps.update');
Route::delete('/procurements/{procurement}/schedule-steps/{step}',  [ScheduleStepController::class, 'destroy'])->name('realestate.procurements.schedule-steps.destroy');
// projects / properties / custom-orders も同型
```

| 操作 | 権限 |
|---|---|
| 閲覧（詳細カード / ボード） | `department.access:<部署>`（既存どおり全ロール） |
| 追加・更新・削除・並べ替え | `role:executive,manager`（原価管理と同じ） |

### 6.1 親の解決

`ScheduleStepController` は **`{type}` のようなルートパラメータを持たない**。
`AttachmentController` の `TYPE_MAP` 方式は、ルートの `where()` 正規表現と
マップの同期漏れで 404 になる事故がある（Bug #20）。代わりに、
**バインド済みのルートパラメータのうちどれが来たかを見る**:

```php
private const OWNER_PARAMS = ['procurement', 'project', 'property', 'customOrder'];
```

⚠ **この配列とルートのパラメータ名がずれると無音で 404 になる。**
走査テストで「`*.schedule-steps.*` という名前のルートすべてについて、
そのパラメータ名が `OWNER_PARAMS` に含まれること」を**全件分類**で固定する（Bug #45）。
「直したものを並べる」形にすると、新しく足したルートが検査対象に入らず永遠に緑になる。

### 6.2 所有権のガード

⚠ **`{step}` が本当にその親のものかを必ず確かめる**。
ルートモデルバインディングは id を見るだけなので、他案件の工程 id を投げると通ってしまう
（部署共通 Controller の所有権ガード欠落による IDOR を過去に実際に踏んでいる）。

```php
abort_unless(
    $step->schedulable_id === $parent->getKey() && $step->schedulable_type === $parent::class,
    404
);
```

⚠ **`schedulable_id` だけでは足りない。** 4 親は別テーブルなので **id が衝突する**
（仕入れ案件 #12 と建売物件 #12 が両方存在しうる）。**型も必ず突き合わせる。**
テストは「別部署の同じ id」で当てること —— 同部署の別 id だけでは
`schedulable_type` の比較を消しても緑のまま通る。

### 6.3 ルートの順序

⚠ **`reorder` を `{step}` より先に登録する**。`schedule-steps/reorder` は
`schedule-steps/{step}` にマッチしうる。ただし `route:list` の並びは登録順ではなく URI 辞書順なので、
**優先順位は `route:list` を見て確かめてはいけない**。ルータに実マッチさせて測る。

⚠ **4 親 × 4 本を対称に定義する。** 片側が足りないと、共通 partial が `route()` を呼んだ瞬間に
**その画面だけ本番で 500** になる（Bug #25。realestate に surveys ルートが無くて起きた前例と同型）。
テストで 16 本の存在を機械的に突き合わせる。

---

## 7. 本番反映

1. worktree で実装 → `/commit`
2. main repo で `git checkout 13.x && git merge --ff-only realestate-schedule`
3. **新規 PHP クラスを追加するので main repo の cwd で `composer dump-autoload`**
   （worktree から実行すると autoloader に worktree パスが焼き込まれる）
4. **DB が先**: `database/sql/2026-08-31-create-schedule-steps.sql` を本番へ適用
5. **その後に** `./deploy.sh`（`route:cache` / `view:cache` 再生成）

⚠ **順番を逆にしない。** テーブルが無い状態でコードだけ本番に出ると、
4 つの詳細ページとボード 2 つが `Base table or view not found` で 500 する。

⚠ **`./deploy.sh` はユーザーの明示承認がある時だけ実行する。**

---

## 8. テスト方針

### 8.1 単体（`tests/Unit/Support/`）

- `GanttScaleTest` — left / width の境界: 区間の先頭・末尾・1 日工程（幅が 0 にならない）・
  範囲外の日付・うるう日をまたぐ区間。**`setUp()` で timezone を UTC に固定し `tearDown()` で戻す**
- `ScheduleStepStatusTest` — 遅延判定の 4 分岐 × 境界（ちょうど同日は遅延ではない）
- `LanePackerTest` — 重なり無し=1 段 / 全部重なる=N 段 /
  **同日終了・同日開始が別段になること** / 開始が同じ複数工程 / 空配列

### 8.2 Feature

- `ScheduleStepCrudTest` — **4 親ぶん**の追加・更新・削除・並べ替えの往復。
  ⚠ **画面が描いたフォーム（`action` と全 hidden）を解析してそのまま送り返す**
  （`tests/Concerns/ParsesForms.php`）。値を直接 POST すると、
  画面から操作が消えてもテストが緑のまま通る（Bug #47 / #54 ②）
- `ScheduleStepAuthorizationTest` — staff が追加・更新・削除できないこと ＋
  **他案件の工程 id が 404**。⚠ **「別部署の同じ id」を必ず含める**（§6.2）
- `ScheduleSectionRenderTest` — **4 つの詳細画面**が 200 で、
  工程 0 件のとき空のガントを描かず案内文が出ること
- `ScheduleBoardTest` — 2 ボード × フィルタ 3 種 × 空/非空。
  **KPI の数がボード本体の表示と一致すること**。
  ⚠ **住宅の権限しか無い利用者に不動産の案件が出ないこと**を必ず 1 本置く
- `ScheduleAutoMilestoneTest` — 4 親の `autoMilestones()` が §3.4 の表どおりで、
  **完成の ◆ が 2 つ出ない**こと
- `ScheduleParentDeletionTest` — **4 親すべて**で、親を消したら工程も消えること

### 8.3 構造テスト（走査）

- **partial の定義が 1 箇所**であること（4 つの show が同じ partial を `@include`）
- **ルート 16 本が対称に定義**されていること
- **`OWNER_PARAMS` が全ルートのパラメータ名を網羅**していること（全件分類・§6.1）
- **`database/sql` の DDL とテスト用 schema trait の列が一致**すること

### 8.4 検証の作法（過去に踏んだ罠）

⚠ **「テストが緑」は検証にならない。変異を入れて赤になることを実測する。**
作法（Bug #44 / #54）:

1. **先にコミットする**（未コミットのまま変異を当てると `git checkout --` で自分の編集ごと巻き戻る）
2. 各変異の**前**に `git status --porcelain` が空であることを確認
3. `git diff --stat` が**非空**で着弾を確認（0 箇所置換を「検出しない」と誤読しない）
4. 赤/緑ではなく**落ちた理由の文言**まで突き合わせる
5. 変異は**検査対象に入るはずの場所**へ当てる

当てる変異（最低限）:
`width` の `+1` を消す / 遅延判定の `>` を `>=` に / 段振り分けを 1 段固定に /
実績優先をやめて予定固定に / **`schedulable_type` の比較を消す** / ルートを 1 本だけ消す /
partial の `@include` をインライン複製に置換 / `ScheduleBoardService` の対象クラスを全親に広げる /
完成 ◆ を予定・実績の 2 つ描くようにする

⚠ **KPI と本体の両方をアサートする。** 同じ数字が 2 箇所に出るので、片方だけ消しても
部分一致で緑になる（Bug #43 / #46 / #49 で繰り返し踏んでいる）。`viewData()` で役割ごとに見る。

⚠ **`assertSessionHasErrors()` を呼ぶと、そのあと描画した画面からエラー表示が消える**（Bug #49）。
画面の描画を見るテストではセッションに触らず、期待文言は `trans()` で組む。

---

## 9. やらないこと（YAGNI）

| 項目 | 理由 |
|---|---|
| 工程間の依存関係（矢印・前工程が終わったら次が動く） | 使うか分からない。データが溜まってから判断する |
| ドラッグで期間を変える操作 | 実装量に対して効果が薄い。日付欄で足りる |
| 進捗 % | 案1 を選んだ時点で不要。人が手で入れる主観の数字は更新が止まると嘘になる |
| 通知・アラートメール | 遅延はボードで見る。まず見る習慣ができてから |
| 担当者フィルタ | **4 親のどれにも担当者カラムが無い**（§4.2）。親テーブルへの列追加が先で、それは別の話 |
| 建売契約 `hs_contracts` への工程 | 工期は物件に属する。契約は金額と日付の記録 |
| DAD / 賃貸マンション / ZEAL への展開 | **当面は不動産と住宅のみ**（利用者確認済み）。ポリモーフィックなので後から親を足せる |
| 部署をまたぐ「全部入り」ボード | 部署ごとに 2 つで合意。経営層から要望が出たら別途 |
| Excel 出力 | 依頼が出たら別途 |
| 工程テンプレート（よく使う工程一式を流し込む） | 「案件ごとにバラバラ」という前提と矛盾しうる。運用してから判断 |

---

## 10. 実装しても画面では確かめられないこと

⚠ 以下はテストでは押さえられないので、**デプロイ後にブラウザで目視する**。

1. 月グリッドと棒の位置が**視覚的に**合っているか（% 計算が合っていても CSS で崩れうる）
2. ガントの横スクロールが `<main>` を押し広げていないか（`main.scrollWidth === main.clientWidth`。
   **広い幅と狭い幅の両方**で測る。Bug #29 は超過幅が一定なので片方だけでは判定できない）
3. Ajax 保存後にガントが再描画されるか
4. **本番の `view:cache` コンパイルが通るか**（Bug #21 / #26 が「本番だけ壊れる」前例）。
   デプロイ前にローカルで
   `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`
5. 375px 幅で 4 つの詳細カードとボード 2 つが崩れないか
   （モバイル崩れは 2 類型あり、`main` の横スクロール計測だけでは半分見逃す）
6. **4 画面すべて**を開くこと。共通 partial なので 1 画面で足りると思いがちだが、
   `@include` の位置と親の `autoMilestones()` は画面ごとに違う
