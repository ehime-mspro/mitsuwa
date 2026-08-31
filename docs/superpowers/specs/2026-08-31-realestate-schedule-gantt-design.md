# 不動産 契約後工程のガント表示 — 設計書

**作成日**: 2026-08-31
**対象**: 不動産管理の 仕入れ案件（`re_procurements`）/ 分譲地PJ（`re_projects`）
**モック**: `docs/mockups/realestate/schedule-gantt-proposals.html`（詳細カード 3案比較＋入力画面）/
`docs/mockups/realestate/schedule-board.html`（全案件横断ボード）

---

## 1. 背景と目的

契約〜決済のあとに走る工程（造成・開発許可・確定測量・分筆登記・販売など）を、
**Excel の工程表のように横棒で見たい**という依頼。

現状 `re_procurements` / `re_projects` が持っている日付は
`info_obtained_date` / `contract_date` / `settlement_date` の **3 つだけ**で、
契約後の工程を表すデータは**1 つも無い**。よって DB 追加が要る。

アプリ内にガント表示の前例は無い（DAD 工事案件が `period_start` / `period_end` を
持つが、数値として表示しているだけで棒は引いていない）。

---

## 2. ブレストで確定した事項

| # | 論点 | 決定 | 理由 |
|---|---|---|---|
| 1 | 工程名は固定マスタか | **案件ごとに自由に足す**（マスタ無し） | 案件によって発生する工程が違う（利用者回答） |
| 2 | 表示場所 | **詳細ページ内カード ＋ 横断ボードの両方** | 利用者回答 |
| 3 | 描画方式 | **1 行 1 本（モックの案1）** | 利用者選択。入力が軽く印刷にも強い |
| 4 | 日付の持ち方 | **予定と実績の 2 組（4 日付）** | 利用者選択。詳細は 1 本のままで、遅れは横断ボードで見る |
| 5 | テーブル構成 | **ポリモーフィック 1 本** | 横断ボードが 1 クエリで済む／Controller・Blade・テストを二重に書かない |
| 6 | 契約日・決済日 | **既存カラムから自動で ◆ を描く**（工程行として作らない） | 二重入力を作らない |

⚠ #3 と #4 の組み合わせが本設計の肝。**画面は 1 本しか引かないが DB には予定・実績の両方が入る**ため、
詳細はスッキリしたまま、ボードで「遅延 4」「+16 日」が出せる。
実績を空のまま運用すれば見た目は「予定だけの単純な工程表」になり、後から実績を入れ始めても画面は変わらない。

---

## 3. データ構造

### 3.1 新テーブル `re_schedule_steps`

```sql
CREATE TABLE `re_schedule_steps` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedulable_type` VARCHAR(255)    NOT NULL,   -- App\Models\ReProcurement / App\Models\ReProject
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

**外部キーは張らない。** `schedulable_type` が 2 種類あるため単一の FK では表現できない。
親を消したときの削除は既存の削除ガード（`DeletionBlockers`）と同じ層で扱う（§3.4）。

⚠ **`re_*` は raw SQL 管理**（Laravel migration ではない）。
本テーブルは **`database/sql/2026-08-31-create-re-schedule-steps.sql`** と
**`tests/Concerns/CreatesRealEstateSchema.php`** の**両方**に足す。
片方を忘れると「本番は動くのにテストだけ落ちる」または「テストは緑なのに本番で `Unknown column`」になる
（memory: migration と live schema の drift）。

### 3.2 `category`（色分けだけに使う）

`App\Enums\ScheduleStepCategory`（backed enum, string）。

| value | label | 色 |
|---|---|---|
| `permit` | 許認可・申請 | `#3B82F6` |
| `work`   | 工事 | `#059669` |
| `survey` | 測量・登記 | `#8B5CF6` |
| `sale`   | 販売 | `#F59E0B` |
| `other`  | その他 | `#6B7280` |

**色は enum のメソッドが返す**（`color()`）。Tailwind クラスではなく生の hex を返し、
inline style と `<svg>` の両方から同じ値を使う。

⚠ 分類は**色分け以外の意味を持たない**。集計にも権限にも使わない。
意味を持たせたくなったら、その時に別の列を足す。

### 3.3 行の種別（マイルストーンか棒か）

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

### 3.4 親の削除

仕入れ案件 / 分譲地を削除するとき、工程は**一緒に消す**（削除をブロックしない）。
工程は親に完全従属する記録で、単体で参照する価値が無いため。

⚠ 既存の `DeletionBlockers` に**工程を足さない**こと。足すと
「工程を 1 行でも書いた案件は二度と消せない」という別の不具合になる。
親コントローラの `destroy()` で `$model->scheduleSteps()->delete()` を先に呼ぶ。

---

## 4. 画面

### 4.1 詳細ページの「工程表」カード

**場所**: `realestate/procurements/show.blade.php` と `realestate/projects/show.blade.php` の
**原価管理カードの下**。共通 partial `realestate/_partials/_schedule_section.blade.php` を両方が `@include`。

⚠ **partial は 1 本にする。** 同じマークアップを 2 箇所にコピーすると、片方だけ直す事故が起きる
（Bug #41 / 地図 POI の件で実測済み）。テストで「定義が 1 箇所であること」も固定する。

**新規登録画面（create）には入れない。** 案件を作る時点で工程は無く、
親の id が無いと保存先が決まらないため。詳細ページから足す。

**中身**（モック案1 のとおり）:

- 左カラム 262px: 工程名（長ければ省略記号）＋ 期間テキスト（`3/16〜7/03`）
- 右: 月グリッド＋横棒 1 本。四半期の頭（1/4/7/10 月）だけ罫線を濃くする
- 今日の位置に赤い破線＋「今日 8/31」のピル（最上段のみ）
- 凡例（色 4 種＋ ◆）
- 工程が 0 件のときは「工程が登録されていません」＋ 追加ボタン（空のガントを描かない）

### 4.2 横断ボード

**URL**: `/realestate/schedules`（`realestate.schedules.index`）
**サイドバー**: 「不動産管理」グループの **「分譲地」の次**に「工程表」を追加

**中身**（モック `schedule-board.html` のとおり）:

- KPI 4 枚: 工程が動いている案件 / 予定より遅れている案件 / 30 日以内に始まる工程 / 30 日以内に終わる工程
- フィルタ: 種別（すべて / 仕入れ案件 / 分譲地）・ステータス（進行中 / すべて / 遅延 / 完了）・担当者・キーワード
- ズーム: 週 / 月 / 四半期（月ヘッダの粒度が変わるだけ。棒の位置計算は同じ）
- 1 行 1 案件。工程を色帯で並べ、**期間が重なる工程は段に振り分ける**（§5.3）
- 行頭の ▸ で展開すると、その案件の工程が予定（細いグレー）＋実績（色つき）の 2 段で出る
- 遅れている工程には赤い枠、まだ始まっていない工程は薄く

⚠ **ページングしない**（絞り込み後の全件）。周辺ビル調査の地図タブと同じ判断。
案件数が 200 を超えたら見直す。

⚠ **フィルタのクエリキーに null を渡さない。** `Arr::query()` は null のキーを丸ごと捨てるので、
リンク生成前に `''` へ正規化する（Bug #31）。

### 4.3 入力

原価管理と同じ **Ajax 即時保存**（詳細画面の既存流儀）。

| 操作 | メソッド | ルート |
|---|---|---|
| 追加 | POST | `realestate.procurements.schedule-steps.store` / `...projects...` |
| 更新 | PATCH | `....schedule-steps.update` |
| 削除 | DELETE | `....schedule-steps.destroy` |
| 並べ替え | PATCH | `....schedule-steps.reorder` |

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

### 4.4 バリデーション

| 項目 | ルール |
|---|---|
| `name` | required, string, max:100 |
| `category` | required, in:（enum の値） |
| `planned_start` / `planned_end` / `actual_start` / `actual_end` | nullable, date |
| `planned_end` | after_or_equal:planned_start |
| `actual_end` | after_or_equal:actual_start, **required_with:—（下記）** |
| `notes` | nullable, string, max:255 |

⚠ **実績終了だけが入って実績開始が空、という状態を許さない**（`actual_end` が入るなら `actual_start` も必須）。
許すと §5.2 の分岐が「実績開始が無い」側へ落ち、**実績終了を入れたのに予定の棒が出る**という
無音の食い違いになる。逆（実績開始だけ）は「進行中」として正当なので許す。

⚠ **`lang/ja/validation.php` の `attributes` に和名を足す**（Bug #37）。
`name` は既に別画面と衝突する語なので、**コントローラの `validate()` 第 3 引数**で
「工程名」を上書きする（第 2 引数は messages）。
走査テスト `JapaneseValidationMessagesTest` が和名漏れを自動で拾う。

⚠ **`planned_start` も `actual_start` も空の行を弾かない。** 「先に名前だけ並べて後から日付を入れる」
のは自然な使い方。§3.3 のとおり一覧に残して描画対象から外す。

---

## 5. 描画のアルゴリズム

**JS ライブラリを足さない。** 日付 → 位置(%) の変換は PHP 側で行い、
Blade は inline style で置くだけにする。外部 CDN は 1 本も増えない（Chart.js も使わない）。

### 5.1 `App\Support\GanttScale`

時間軸の 1 区間 `[from, to]` を保持し、日付を % に変換する。

```php
left(Carbon $d):  ($d - $from)->days / $total * 100
width(Carbon $s, Carbon $e): (($e - $s)->days + 1) / $total * 100
```

⚠ **`width` の `+ 1` が要る。** 開始日と終了日の両端を含めるため。
これが無いと 1 日だけの工程（`start === end`）が**幅 0 になって消える**。

⚠ **日付は必ず `startOfDay()` に揃えてから引く。** 揃えないと実行環境の timezone や
時刻成分で ±1 日ずれる（Bug #54 ①: Laravel を起動しない Unit テストは
`config/app.php` ではなく `php.ini` の timezone に支配される）。
`GanttScaleTest` は `setUp()` で `date_default_timezone_set('UTC')` を固定し、
**`tearDown()` で必ず戻す**（戻さないと同一プロセスの後続テストへ漏れる）。

### 5.2 描画区間（1 行 1 本）

```
実績開始がある → [actual_start, actual_end ?? 今日]   （actual_end が NULL なら「進行中」＝右端を開く）
実績開始が無い → [planned_start, planned_end ?? planned_start]
```

⚠ **実績を優先する。** 「予定 5/18〜9/30・実績 6/1〜10/16」の工程は **6/1〜10/16 の 1 本**が出る。
詳細画面では遅れは棒からは読めない（それが案1 を選んだということ）。遅れはボードで見る。

### 5.3 段の振り分け（ボードのサマリ行）

開始が早い順に見て、**入る段があればそこ、無ければ新しい段**（greedy interval partitioning）。

```
各段の最後の要素の終了 < その工程の開始  → 同じ段に置く
どの段にも入らない                        → 新しい段
```

⚠ **判定は「より後」（`>`）であって「以降」（`>=`）ではない。**
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

⚠ **ここはモックと違う。** `schedule-board.html` の `isLate()` は「実績なし」を遅延と見なしていない。
本設計のほうが正しい。**モックではなく本節を正本とする。**
（サンプルデータには「未着手のまま予定終了を過ぎた工程」が 1 件も無いため、
モックの KPI「遅延 2 件」はどちらの規則でも同じ数になる＝モックを見ても違いに気づけない）

**判定は `App\Support\ScheduleStepStatus`（または `ReScheduleStep` のメソッド）1 箇所に集約する。**
詳細カード・ボードのバッジ・KPI が別々に計算すると、画面ごとに数が食い違う（Bug #46）。

### 5.5 時間軸の範囲

| 画面 | 範囲 |
|---|---|
| 詳細カード | その案件の全日付（工程の 4 日付＋契約日＋決済日）の最小〜最大。前後に 1 ヶ月の余白を取り月初・月末に丸める。今日が範囲外なら今日も含める |
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

---

## 6. ルートと権限

```php
// realestate prefix / middleware('department.access:realestate') の中
Route::get('/schedules', [ScheduleBoardController::class, 'index'])
    ->name('realestate.schedules.index');

// 各親の下（role:executive,manager）
Route::post  ('/procurements/{procurement}/schedule-steps',        ...)->name('realestate.procurements.schedule-steps.store');
Route::patch ('/procurements/{procurement}/schedule-steps/{step}', ...)->name('realestate.procurements.schedule-steps.update');
Route::delete('/procurements/{procurement}/schedule-steps/{step}', ...)->name('realestate.procurements.schedule-steps.destroy');
Route::patch ('/procurements/{procurement}/schedule-steps/reorder',...)->name('realestate.procurements.schedule-steps.reorder');
// projects 側も同じ 4 本を対称に
```

| 操作 | 権限 |
|---|---|
| 閲覧（詳細カード / ボード） | `department.access:realestate`（既存どおり全ロール） |
| 追加・更新・削除・並べ替え | `role:executive,manager`（原価管理と同じ） |

⚠ **`{step}` が本当にその親のものかを必ず確かめる**。
ルートモデルバインディングは id を見るだけなので、他案件の工程 id を投げると通ってしまう
（memory: 部署共通 Controller の所有権ガード欠落による IDOR を実際に踏んでいる）。
`abort_unless($step->schedulable_id === $parent->id && $step->schedulable_type === $parent::class, 404)`。

⚠ **`reorder` のルートを `{step}` より先に登録する**。`schedule-steps/reorder` は
`schedule-steps/{step}` にマッチしうる。ただし `route:list` の並びは登録順ではないので、
**優先順位はルータに実マッチさせて確かめる**（memory: `route:list` の並びは URI 辞書順）。

⚠ **仕入れ案件と分譲地の 4 本 × 2 = 8 本を対称に定義する。**
片側だけ足りないと、共通 partial が `route()` を呼んだ瞬間に本番だけ 500 になる（Bug #25）。

---

## 7. 本番反映

1. worktree で実装 → `/commit`
2. main repo で `git checkout 13.x && git merge --ff-only realestate-schedule`
3. **新規 PHP クラスを追加するので main repo の cwd で `composer dump-autoload`**
   （worktree から実行すると autoloader に worktree パスが焼き込まれる）
4. **DB が先**: `database/sql/2026-08-31-create-re-schedule-steps.sql` を本番へ適用
5. **その後に** `./deploy.sh`（`route:cache` / `view:cache` 再生成）

⚠ **順番を逆にしない。** テーブルが無い状態でコードだけ本番に出ると、
詳細ページが `Base table or view not found` で 500 する
（memory: SoftDelete のときに同じ順序問題を踏んでいる）。

⚠ **`./deploy.sh` はユーザーの明示承認がある時だけ実行する。**

---

## 8. テスト方針

### 8.1 単体（`tests/Unit/Support/`）

- `GanttScaleTest` — left / width の境界: 区間の先頭・末尾・1 日工程（幅が 0 にならない）・
  範囲外の日付・うるう日をまたぐ区間。**`setUp()` で timezone を UTC に固定し `tearDown()` で戻す**
- `ScheduleStepStatusTest` — 遅延判定の 4 分岐 × 境界（ちょうど同日は遅延ではない）
- `LanePackerTest` — 段振り分け: 重なり無し=1 段 / 全部重なる=N 段 /
  **同日終了・同日開始が別段になること** / 開始が同じ複数工程

### 8.2 Feature（`tests/Feature/RealEstate/`）

- `ScheduleStepCrudTest` — 追加・更新・削除・並べ替えの往復。
  ⚠ **画面が描いたフォーム（`action` と全 hidden）を解析してそのまま送り返す**
  （`tests/Concerns/ParsesForms.php` を使う）。値を直接 POST すると、
  画面から操作が消えてもテストが緑のまま通る（Bug #47 / #54 ②）
- `ScheduleStepAuthorizationTest` — staff が追加・更新・削除できないこと ＋
  **他案件の工程 id を投げたら 404**（両親ぶん）
- `ScheduleSectionRenderTest` — 仕入れ案件・分譲地の**両方**の詳細が 200 で、
  工程 0 件のとき空のガントを描かず案内文が出ること
- `ScheduleBoardTest` — フィルタ 4 種 × 空/非空、KPI の数がボード本体の表示と一致すること

### 8.3 構造テスト（走査）

- **partial の定義が 1 箇所であること**（`_schedule_section.blade.php` を両 show が `@include`）
- **ルートが 8 本すべて対称に定義されていること**（片側欠落で落ちる）
- **`sql` と `tests/Concerns/CreatesRealEstateSchema.php` の列が一致すること**

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
実績優先をやめて予定固定に / ルートを片側だけ消す / 所有権ガードを消す /
partial の `@include` をインライン複製に置換

⚠ **KPI と本体の両方をアサートする。** 同じ数字が 2 箇所に出るので、片方だけ消しても
部分一致で緑になる（Bug #43 / #46 / #49 で繰り返し踏んでいる）。
`viewData()` で役割ごとに見る。

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
| 住宅事業 / DAD / 賃貸マンションへの展開 | 今回は不動産の 2 つだけ。ポリモーフィックなので後から親を足せる |
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
5. 375px 幅で表が崩れないか（memory: モバイル崩れは 2 類型あり main の横スクロール計測だけでは半分見逃す）
