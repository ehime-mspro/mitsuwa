# 工程表を「現状の工程」に寄せる（住宅事業）— 設計書

**作成日**: 2026-09-02
**対象**: 建売物件（`HsProperty`）/ 注文住宅（`HsCustomOrder`）
**対象外**: 仕入れ案件（`ReProcurement`）/ 分譲地PJ（`ReProject`）—— **一切変更しない**
**前提**: 工程表と工程表の取込は 2026-09-01 に本番稼働済み

---

## 1. なぜ変えるのか

工程表の取込を本番で 1 件流したところ、**64 工程のうち 57 本に赤い「遅延」バッジ**が出た
（最大 `+193日`）。壊れているのではなく、**実績を取り込まない**仕様（取り込む日付は予定）と
**予定終了が過ぎて実績が無ければ遅延**という判定が噛み合った結果。

実態は取り込んだファイルの「状態」列でこうなっていた:

| 状態 | 件数 |
|---|---:|
| 責任者承認済 | 34 |
| 作業完了 | 10 |
| 作業前 | 20 |

**44 件は実際には終わっている**のに全部「遅れている」と表示されていた。

利用者の判断（2026-09-02）:

> 基本的には「予定・実績」の概念は必要ありません。工程表は現状の工程を確認するための
> 認識にして下さい。予定に関しての管理は物件の基本情報の完成予定でしたいと思います。

つまり **工程表 = いま現在どういう工程で動いているかの一覧**、
**予定の管理 = 物件の基本情報（着工予定日・完成予定日）** と役割を分ける。

---

## 2. 決定事項

| # | 決めたこと |
|---|---|
| D1 | 「実績を持つか」は**親モデルが宣言する**（abstract メソッド 1 本）。住宅事業 = 持たない |
| D2 | 住宅事業の工程の状態は**日付だけ**で決める（これから / 進行中 / 済）。**遅延の概念は無い** |
| D3 | `actual_completion_date` を **`construction_start_date`（着工予定日）へ改名**し、**完成予定日の前**に置く |
| D4 | ガントの節目 ◆ は **着工** と **完成** の 2 つ |
| D5 | 取込時に **着工予定日 = 最も早い開始日 / 完成予定日 = 最も遅い終了日** を**常に上書き**し、プレビューで予告する |
| D6 | 住宅事業ボードは **状態 3 種 / KPI 3 枚**。遅延は出さない |
| D7 | `schedule_steps` の `actual_*` 列は **DB に残す**（不動産が使う）|

---

## 3. 「実績を持つか」は親が宣言する（D1）

### 3.1 決め方

`app/Models/Concerns/HasScheduleSteps.php` に **abstract メソッドを 1 本**足す:

```php
/**
 * 実績（actual_start / actual_end）を扱うか。
 *
 * false のとき:
 *   - 編集表に実績の列を出さない
 *   - 保存経路が actual_* を受け付けず null に正規化する
 *   - 遅延を判定しない（状態は日付だけで決まる）
 */
abstract public function scheduleTracksActuals(): bool;
```

| 親 | 返り値 |
|---|---|
| `ReProcurement` | `true` |
| `ReProject` | `true` |
| `HsProperty` | `false` |
| `HsCustomOrder` | `false` |

⚠ **既定実装を置かない。** この trait の先頭のコメントが名指ししているとおり、既定値を置くと
新しい親を足した人が override を忘れた瞬間に**無音で片方の挙動に倒れる**。abstract なら
PHP が Fatal で止める。

⚠ **共有 partial・サービス・コントローラは `instanceof` を書かない。** 必ず親に聞く。
「partial の中で親の種別を判定しないこと」という既存の規約（`_schedule_section.blade.php`
冒頭）をそのまま守る。

### 3.2 列は残す（D7）

`schedule_steps.actual_start` / `actual_end` は不動産が使うので**残す**。DDL 変更なし。

### 3.3 ⚠ 「画面から消すだけ」にしない

住宅事業の保存経路で `actual_*` を **`null` に正規化する**。

**根拠**: Bug #38 と同型。`:disabled` や列の非表示で送らせないだけにすると、
`Validator::validated()` が未送信キーを結果に含めないため `update($validated)` が
**そのカラムに触れず旧値を残す**。「画面に無いのに DB に値が残る」状態を作る。

**置き場所**: `ScheduleStep` の `saving` フック。

```php
static::saving(function (ScheduleStep $step) {
    $owner = $step->schedulable;   // associate() 済みなのでクエリは増えない
    if ($owner !== null && ! $owner->scheduleTracksActuals()) {
        $step->actual_start = null;
        $step->actual_end   = null;
    }
});
```

⚠ コントローラ側だけで落とすと、書き込み経路が増えたとき（今は手入力 CRUD と取込の 2 本）に
片方だけ漏れる。**1 箇所に寄せる。**

⚠ `ScheduleStepController::update()` の validate からも住宅事業では `actual_*` を外す。
フックは二重防御だが、**フックを入れると validate 側の変異が検出できなくなる**
（Bug #48 の「安全網が測定器を鈍らせる」型）。テストは**応答ではなく
「保存された値」と「validate が弾いたか」を別々に**見ること。

---

## 4. 状態は日付だけで決める（D2）

### 4.1 判定

`ScheduleStepStatus` の遅延判定（`delayDays` / `isLate`）は**不動産用にそのまま残す**。
住宅事業用の状態を**新しく足す**。

```
開始日も終了日も無い        → 未定（undated）
今日 < 開始日               → これから（upcoming）
開始日 ≤ 今日 ≤ 終了日      → 進行中（running）
終了日 < 今日               → 済（done）
```

⚠ **終了日が無く開始日だけの行（＝ ◆ マイルストーン）**: `今日 < 開始日` なら これから、
それ以外は 済。

⚠ **開始日が無く終了日だけの行**は「終了日 < 今日 なら 済 / そうでなければ 進行中」に倒す。
（入力上ありうるので分岐を落とさない）

⚠ **「今日」は必ず引数で受け取る。** 内部で `now()` を呼ぶと、テストが実行日に依存して
「凍結したつもりで効いていない」状態を作る（既存 `ScheduleStepStatus` の注意書きと同じ）。

### 4.2 見せ方

**分類の色（工事＝緑 / 許認可＝青 / 測量＝紫 / 販売＝橙 / その他＝灰）は変えない。**
**状態は棒の濃さではなく、左のラベル欄の「状態チップ」で出す**（案B′。
モック `docs/mockups/housing/schedule-current-state.html` を実ブラウザで採寸して 2026-09-02 に決定）。

| 状態 | 棒 | チップ |
|---|---|---|
| 進行中 | 分類色を塗る ＋ **`box-shadow: 0 0 0 1.5px #111827`（濃い輪郭）** | 黒地・白文字「進行中」 |
| 済 | 分類色を**そのまま**塗る（薄くしない）| 薄灰地・灰文字「済」 |
| これから | 分類色を**そのまま**塗る（枠線にしない）| 白地・灰枠・灰文字「これから」 |
| 未定 | 棒を描かない（現状どおり）| 「未定」 |

- 左のラベル欄: 赤い遅延チップをやめ、**状態チップ ＋ 期間テキスト**を出す
- 凡例は分類 5 色 ＋ 節目 ＋ 状態チップ 3 種（チップは自分で名乗るので実質 1 段のまま）
- **赤は使わない**（遅延の概念が無いので）

⚠ **ラベル欄に `min-width: 0; overflow: hidden;` を足すこと。** 今の `flex: 0 0 262px` は
`min-width` が `auto` のままなので、チップを足すと入りきらない行が **262px を超えて広がり、
その行の棒だけ右へずれる**。モックの実測で 66 行中 2 行が 267.3 / 293.1px になり、
**最大 31.1px ＝ 軸 275 日で約 12.6 日ぶん**ずれた（月ヘッダは 262px のままなので月境界とも合わない）。
`min-width: 0; overflow: hidden;` で全行 262px・ずれ 0px に戻ることは実地で確認済み
（代償は長い工程名 2 件が `text-overflow: ellipsis` で省略されること）。Bug #29 と同型。

⚠ **濃淡（`opacity`）と枠線で状態を出す案は採らない。** 当初案だったが、採寸で 2 つとも破綻した:

1. **1 日の工程で枠線が塗りに化ける。** `box-sizing: border-box` なので、幅（軸 275 日で
   **2.46px**）より枠線 2 本ぶん（1.5px × 2 = 3px）が太いと中身がゼロになり、要素が丸ごと
   枠線で塗られる。実測 **3.00px / `clientWidth` 0** ＝ **未着手の工程が進行中より濃く・22% 太く**見え、
   意味が反転する。実データは 65 工程中 **26 件が 1 日**なので例外ではない
2. **`opacity: 0.4` は「済」を 1.6:1 まで落とす**（行背景 `#FCFCFD` に対し 工事 1.64 / 許認可 1.60 /
   測量 1.68 / その他 1.69）。非テキストの図形に要る 3:1 に届かせるには `opacity 0.76〜0.87` が必要 ＝
   **ほとんど薄くできない**。本番相当のデータでは **65 本中 55 本（85%）**がその薄さになる

⚠ **販売（橙 `#F59E0B`）は `opacity: 1.0` でも 2.11:1** —— これは現行の本番でもそうで、
今回の変更とは無関係の既存の弱点。**この設計では直さない**（直すなら分類色の見直しという別の決定）。

⚠ **`x-show` と `:style` を同じタグに置かない**（Bug #32）。棒は静的レンダリングなので該当しないが、
凡例を Alpine で出し分けるなら注意。

⚠ **色は inline style で出す**（既存のガントがそうしている）。Tailwind クラスに変えない。

### 4.3 不動産との違い（意図的）

| | 不動産 | 住宅事業 |
|---|---|---|
| 日付 | 予定 2 + 実績 2 | 開始・終了の 2 だけ |
| 棒の描画 | 実績があれば実績、無ければ予定 | 常に開始〜終了 |
| 遅延 | あり（赤バッジ・KPI・絞り込み） | **無し** |
| 状態 | 完了 / 進行中（実績ベース） | これから / 進行中 / 済（日付ベース） |
| 状態の出し方 | 赤い遅延バッジ | **ラベル欄の状態チップ**（棒の濃さは変えない）|

---

## 5. 基本情報の項目（D3）

### 5.1 列の改名

| テーブル | 変更前 | 変更後 |
|---|---|---|
| `hs_properties` | `actual_completion_date` | **`construction_start_date`** |
| `hs_custom_orders` | `actual_completion_date` | **`construction_start_date`** |

⚠ **本番はどちらのテーブルも当該 2 列が全行 NULL**（`scheduled_completion_date` /
`actual_completion_date` とも。建売 7 件 / 注文住宅 2 件を 2026-09-02 に実測）。
**データの移行は不要**（列の付け替えだけ）。

⚠ `database/sql/` に `ALTER TABLE ... CHANGE COLUMN` を置き、**テスト用スキーマ
`tests/Concerns/CreatesRealEstateSchema.php` も対で直す**。片方だけだと
SQLite テストと本番が黙って drift する（過去に実際に起きている）。

### 5.2 画面

**並び**: … → **着工予定日** → **完成予定日**

- 詳細（`show.blade.php`）: 建売・注文住宅とも同じ並び
- フォーム（`_form.blade.php`）: 同じ並び。⚠ **今の `@if($isEdit)` を外し、新規登録でも出す**
  （着工予定日は登録時から分かる）
- `validate()`: `actual_completion_date` → `construction_start_date`（`nullable|date`）
- `lang/ja/validation.php` の `attributes` に **`construction_start_date` = 着工予定日**を足す
  （忘れると英字 `construction start date` がエラー文に出る。Bug #37）

### 5.3 モデル

`$fillable` / `casts()` を両モデルで改名。

---

## 6. ガントの節目（D4）

`autoMilestones()` を両モデルで書き換える:

```php
return array_values(array_filter([
    $this->construction_start_date     ? ['label' => '着工', 'date' => $this->construction_start_date] : null,
    $this->scheduled_completion_date   ? ['label' => '完成', 'date' => $this->scheduled_completion_date] : null,
]));
```

⚠ 既存の「**完成は 1 つだけ**」という注意書きは、`scheduled` と `actual` が**同じ節目**
だったから。**着工と完成は別の節目**なので 2 つ描いてよい。docblock を書き換えること
（古い注意書きが残ると、次に触る人が「◆ は 1 つのはず」と誤解する）。

⚠ **不動産の 2 親の `autoMilestones()` は変えない。**

---

## 7. 取込時の自動入力（D5）

### 7.1 入れる値

| 項目 | 値 |
|---|---|
| 着工予定日 | 取り込んだ工程の **`planned_start` の最小値** |
| 完成予定日 | 取り込んだ工程の **`planned_end` の最大値** |

⚠ **ファイルのヘッダーにある「工事期間」は使わない。** 実装時の実測で
**実データの範囲と一致しない**ことが分かっている（固定資産では D1 が `07/28` 開始なのに
実データの最小は `07/23`）。ガントの棒の両端と基本情報の数字が食い違うのを防ぐため、
**画面に出るのと同じソース（工程の日付）から出す**。

⚠ **2 つは独立に決める。** 開始日が 1 つも無ければ着工予定日は更新せず、終了日が 1 つも
無ければ完成予定日は更新しない（`min()` / `max()` が null を返すので現在値を保つ）。
片方だけ入ることはありうるので、「両方そろわなければ何もしない」にはしない。

### 7.2 上書き

**常に上書きする。** 確定前のプレビューの「取り込むと どうなるか」に予告を出す:

```
・取り込み済みの既存の工程 65 件を削除します
・ファイルから 64 件を登録します
・手で追加した工程 3 件は残ります
・着工予定日を 2026/02/19 にします（現在: —）
・完成予定日を 2026/09/27 にします（現在: 2026/09/15）
```

⚠ **値が変わらないときは行を出さない**（「2026/09/27 → 2026/09/27」というノイズを出さない）。

⚠ **プレビューは DB を書かない**（現状どおり）。書き込みは確定時のみ。

### 7.3 トランザクション

工程の入れ替えと親の日付更新は**同じトランザクション**で行う。
片方だけ通ると、工程とヘッダーの数字が食い違ったまま残る。

---

## 8. 住宅事業ボード（D6）

`Housing\ScheduleBoardController` / `ScheduleBoardService` の住宅事業側だけ変える。

| | 変更前 | 変更後 |
|---|---|---|
| 案件のステータス | 完了 > 遅延 > 進行中 | **済 > 進行中 > これから** |
| ステータス絞り込み | すべて / 進行中 / 遅延 / 完了 | **すべて / これから / 進行中 / 済** |
| KPI | 進行中の**案件** / 遅れている**案件** / 30日以内に始まる**工程** / 30日以内に終わる**工程**（4 枚） | **進行中の工程 / 30日以内に始まる工程 / 30日以内に終わる工程（3 枚。⚠ 3 枚とも数えるのは「工程」であって案件ではない）** |
| 行のバッジ | 遅延日数（赤） | **出さない** |
| 棒の色 | 実績優先＋遅延の赤 | **分類色のまま（濃さを変えない）＋ 進行中だけ輪郭。§4.2 と同じ** |

⚠ **不動産のボードは一切変えない**（4 枚・遅延あり・従来の絞り込み）。
サービスは**親の `scheduleTracksActuals()` を見て**分ける。コントローラ側に
「住宅なら」と書かない。

⚠ 「工程が未登録の案件が N 件あります」の行は**そのまま残す**。

---

## 9. 既存データ

- 本番の 64 工程は `actual_start` / `actual_end` が**全部 null**（2026-09-02 実測）なので
  **手当て不要**
- 備考に残っている「責任者承認済 / 作業完了 / 作業前」は**そのまま残す**。
  **状態の判定には使わない**（§10）

---

## 10. やらないこと

- 不動産（仕入れ案件 / 分譲地PJ）の工程表・ボード・モデルを変える
- ファイルの「状態」列から進捗を決める —— 日付だけで決めるという方針と矛盾する。
  「作業完了なのに終了日が未来」のような食い違いを画面で説明できなくなる
- `schedule_steps` から `actual_*` 列を落とす（不動産が使う）
- 工程ごとの進捗率、依存関係、通知
- 注文住宅への取込（取込は建売だけ。着工予定日は手入力）

---

## 11. 触るファイル

### 変更

| ファイル | 内容 |
|---|---|
| `app/Models/Concerns/HasScheduleSteps.php` | `scheduleTracksActuals()` を abstract で追加 |
| `app/Models/ReProcurement.php` / `ReProject.php` | `true` を返す実装 |
| `app/Models/HsProperty.php` / `HsCustomOrder.php` | `false` を返す実装 ＋ 列改名 ＋ `autoMilestones()` |
| `app/Models/ScheduleStep.php` | `saving` フックで `actual_*` を正規化 |
| `app/Support/ScheduleStepStatus.php` | 日付だけの状態判定を追加（遅延判定は残す） |
| `app/Services/ScheduleCardService.php` | 状態を行に載せる / 棒の描き分け |
| `app/Services/ScheduleBoardService.php` | 住宅事業のステータス・KPI・バッジ |
| `app/Http/Controllers/ScheduleStepController.php` | 住宅事業では `actual_*` を validate しない |
| `app/Http/Controllers/Housing/ScheduleImportController.php` | 親の日付を更新 ＋ プレビューへ予告 |
| `app/Http/Controllers/Housing/PropertyController.php` / `CustomOrderController.php` | validate の列名 |
| `resources/views/_partials/_schedule_section.blade.php` | 実績 2 列を親に応じて出し分け |
| `resources/views/_partials/_schedule_gantt.blade.php` | 棒の描き分け ＋ 凡例 |
| `resources/views/_partials/_schedule_board.blade.php` | バッジ |
| `resources/views/housing/properties/{show,_form}.blade.php` | 着工予定日 |
| `resources/views/housing/custom-orders/{show,_form}.blade.php` | 着工予定日 |
| `resources/views/housing/properties/schedule-import.blade.php` | 予告行 |
| `resources/views/housing/schedules/index.blade.php` | KPI 3 枚・絞り込み（⚠ 不動産側 `realestate/schedules/index.blade.php` は変えない）|
| `lang/ja/validation.php` | `construction_start_date` = 着工予定日 |
| `tests/Concerns/CreatesRealEstateSchema.php` | 列改名（本番 DDL と対で維持） |

### 新規

| ファイル | 内容 |
|---|---|
| `database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql` | 2 テーブルの `CHANGE COLUMN` |

---

## 12. 検証の観点

「テストが緑」は検証にならない。**変異を当てて赤になることを実測する。** 最低限、次を測る:

| # | 変異 | 落ちるべきもの |
|---|---|---|
| 1 | `HsProperty::scheduleTracksActuals()` を `true` に | 住宅の編集表に実績列が出る / 遅延バッジが復活 |
| 2 | `saving` フックの正規化を外す | 住宅の工程に `actual_end` を送ると DB に残る |
| 3 | 状態判定の `<` を `<=` に | 開始日ちょうどの行が「これから」になる |
| 4 | 取込の日付更新を外す | 着工予定日・完成予定日が更新されない |
| 5 | 取込の日付更新を `min`/`max` 取り違え | 着工＞完成という値になる |
| 6 | プレビューの予告行を消す | 画面に予告が出ない |
| 7 | `autoMilestones()` から着工を落とす | ◆ が 1 つになる |
| 8 | 不動産側の遅延判定を消す | 不動産のボード・カードが変わる（**巻き込み事故の検出**）|

⚠ **不動産を巻き込んでいないことを対で固定する。** 「住宅が変わった」だけを見ると、
共有部品の変更が不動産を壊しても緑のまま通る（Bug #41 の「経路が複数」型）。

⚠ **画面の表示は「HTML に出るか」だけでは足りない。** 棒の濃淡は inline style なので
値を突き合わせる。ブラウザでの目視も別途行う（Bug #28 / #43 / #51 の型）。

⚠ **`assertSee` の部分一致に注意。** 「進行中」は「進行中の工程」に前方一致する。
タグ込みか `viewData()` で見る（Bug #43 / #46 / #49）。

⚠ **本番反映は DB（`CHANGE COLUMN`）が先・`deploy.sh` が後。**
