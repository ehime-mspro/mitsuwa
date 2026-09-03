# 工程表ボードのガントを読めるようにする — 設計書

- 日付: 2026-09-03
- 対象: 工程表ボード 2 画面（`/housing/schedules` / `/realestate/schedules`）
  ＋ 工程表カード 4 画面（仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の詳細）
- モック: `docs/mockups/housing/schedule-board-gantt.html`（本番の実データ 64 工程で描画）
- 前提となる設計書: `2026-08-31-realestate-schedule-gantt-design.md` / `2026-09-02-housing-schedule-current-state-design.md`
- DB 変更: **なし** / ルート変更: **なし**

---

## 1. なぜ変えるのか

利用者の依頼（2026-09-03）は 3 つ:

1. KPI カード（進行中の工程 / 30日以内に始まる工程 / 30日以内に終わる工程）は**不要**
2. ガントの**初期表示を 4 ヶ月**にしたい。今のままでは横に広がりすぎて月の間隔が狭く非常に見にくい
3. **横スクロール**できるようにして見やすくしたい

### 1.1 「見にくい」の実体は 2 つ

本番の建売 **JG西長戸4号地**（工程 **64 件**、うち **35 件（55%）が 1 日**、
データ範囲 **2026-02-19 〜 2026-09-27**）で測った。

| # | 原因 | 数字 |
|---|---|---|
| ① | 軸が **19 ヶ月**（`ZOOMS` の既定 `month` = 今日の 6 ヶ月前〜12 ヶ月後） | 2026-03-01 〜 2027-09-30 ＝ 579 日 |
| ② | そのうち **12 ヶ月が完全な空白**（データは 2026-09-27 で終わるのに軸は 2027-09 まで） | 幅の **約 2/3** が無駄 |

**1 日の工程の幅 = 軸トラック幅 ÷ 軸の日数**なので、現状は**画面幅に依存する**:

- PC（`main` 1220px → `p-8` を引いて 1156px → 枠線 2px → 案件名 320px ＝ 軸トラック **834px**）
  → 834 ÷ 579 = **約 1.44px**
- 前セッションのモック計測で記録した **1.00px** は、それより狭い画面幅での値

いずれにせよ **1〜1.5px** ＝ 1 ピクセルの線で、55% を占める 1 日の工程が読めない。

### 1.2 3 案を実データで比べた結果（モック）

| 案 | 軸 | 空白 | 結果 |
|---|---|---|---|
| 現状 | 今日の 6 ヶ月前〜12 ヶ月後を画面幅に圧縮 | 12 ヶ月 | 1 日が 1〜1.5px ＝ 却下 |
| 案A | 同じ 19 ヶ月に固定幅を与えて横スクロール | 12 ヶ月 | 右へ延々と空白が続く ＝ **利用者が却下** |
| **案B** | **データの範囲**（全案件の最小開始月〜最大終了月） | なし | **採用** |

初期表示は 3 / 4 / 5 / 6 ヶ月を切り替えて見比べ、**4 ヶ月**で確定した（6 ヶ月は「まだ見にくい」）。

---

## 2. 決定事項

| # | 決定 | 根拠 |
|---|---|---|
| D1 | KPI カードは**住宅・不動産の両ボードとも削除** | 利用者が「両方消す」を選択 |
| D2 | 「工程が未登録の案件が N 件あります」の行は**残す** | 件数だけは知りたい |
| D3 | 軸の範囲は**案B**（絞り込み後の全案件で、棒と ◆ が占める範囲）。**余白なし** | §1.2 |
| D4 | **1 ヶ月 = 150px の固定値**にする（「4 ヶ月」は目標であって手段ではない） | §3 |
| D5 | 案件名の列を**固定表示**（`position: sticky; left: 0`） | 横スクロールしても行が判別できる |
| D6 | 案件名（カードは工程名）の幅は PC **ボード 320px / カード 262px**、**640px 未満はどちらも 140px** | §4 |
| D7 | 「表示: 月 / 週 / 四半期」セレクタ（`ZOOMS`）は**削除**。見出しは常に月 | §5 |
| D8 | 今日が軸の外なら**今日線を描かない**。軸を伸ばさない。**カードの「今日まで伸ばす」処理も外す** | §6 |
| D9 | ボードは開いた直後に**今日が見える位置まで横スクロール**しておく | §7 |
| D10 | **工程表カードも**同じ「1 ヶ月 = 150px ＋ 横スクロール ＋ 固定表示」に揃える | 画面間で密度を揃える |
| D11 | カードには**初期スクロールを入れない** | §7.2 |
| D12 | 不動産のボードにも D3 / D4 を適用する | サービスと partial を 2 画面が共有しており、部署分岐を作らずに済む。不動産は工程データが本番に 0 件なので今揃えるのが最も安い |

---

## 3. 1 ヶ月 = 150px 固定（D4）

### 3.1 「4 ヶ月」を JS で実現しない

「初期表示 4 ヶ月」は **1 ヶ月を何 px にするか**と等価で、実現方法が 2 通りある:

| 方法 | 1 日の工程の幅 | 375px での挙動 |
|---|---|---|
| **固定 px（採用）** | **どの画面幅でも同じ** | 月幅は変わらず、スクロール量だけ増える |
| JS で画面幅から算出 | 画面幅に比例して変わる | 1 ヶ月 20px ＝ 1 日 **0.66px** で**現状より悪化** |

利用者の不満は「1 日の工程が読めない」ことなので、**読みやすさを画面幅から切り離す**ほうが直接効く。
JS 案は 375px で悪化するうえ、位置の計算を JS が持つ形に一歩近づく（Bug #41）。

### 3.2 150px の根拠

モックで承認した密度は **1 ヶ月 145px / 1 日 4.79px**（そのときの軸トラック 898px ÷ 4 ヶ月）。
これを丸めて **150px** にする。

| | 現状 | 変更後 |
|---|---|---|
| 軸の月数 | 19 ヶ月 | **8 ヶ月** |
| トラックの幅 | 画面幅いっぱい（`min-width: 1000px`） | 320 + 8 × 150 = **1520px** |
| 1 日の工程 | 約 1.4px（画面幅依存） | **約 4.9px（固定）**（1200px ÷ 242 日 = 4.96px。モック実測 4.93px） |
| PC で一度に見える月数 | 19 ヶ月（潰れて読めない） | (1154 − 320) ÷ 150 = 約 **5.5 ヶ月** |
| 横スクロール量 | なし | 1520 − 1154 = **366px** |

⚠ **月セルの幅は日数に比例する（`%`）まま**にする。2 月は 138px、3 月は 153px のように多少ばらつくが、
**1 日あたりの px が一定**になるのはこちら。「1 ヶ月 = 150px」は月セルの平均であって厳密な等幅ではない。

### 3.3 定数の置き場所

`App\Support\GanttScale` に置く。日付 → 位置(%) を持つクラスがトラックの px 幅も出すことで、
**「1 ヶ月 150px」が 1 箇所にしか存在しない**（Bug #41）。

```php
public const MONTH_WIDTH_PX = 150;
public function monthCount(): int;      // from の月 〜 to の月 の個数
public function trackWidthPx(): int;    // monthCount() * MONTH_WIDTH_PX
```

⚠ **位置(%) の計算（`left()` / `width()` / `clamp()`）は 1 行も変えない。**
トラックに px 幅を与えるだけで、既存の `left: X%` / `width: X%` はそのまま正しく動く。

---

## 4. 案件名の列（D5 / D6）

### 4.1 固定表示

`position: sticky; left: 0; z-index: 5;` ＋ 背景色（ヘッダ行 `#F9FAFB` / 案件行 `white`）。
右端に薄い影を出して「ここから先はスクロールする」ことを示す（`box-shadow`。§4.2 の CSS partial に置く）。

⚠ **展開パネル（工程明細）は sticky にしない。** 全幅のテキストブロックなので、
左端に戻れば読める。sticky にすると明細が案件名の列に重なる。

### 4.2 幅は CSS 変数 1 個で切り替える

375px（サイドバーは `hidden lg:flex` なので出ない → `main` 375px → `p-4` を引いて 343px）での実測:

| 案件名の列 | 見える軸 | 見える月数 | 1 日の工程 |
|---|---|---|---|
| 320px 固定 | **19px** | 0.13 ヶ月 | 4.93px |
| **140px（採用）** | **199px** | 1.33 ヶ月 | 4.93px |
| 320px で sticky をやめる | 339px | 2.26 ヶ月 | 4.93px |

320px 固定は軸が 19px しか見えず実質使えない。sticky をやめると軸は一番広く見えるが、
右へスクロールすると案件名が画面外へ流れて**どの案件の棒か分からなくなる**。

⚠ **幅の数値（320 / 262 / 140）は PHP に持たせない。** CSS 変数だけが持つ。

置き場所は **`resources/views/_partials/_schedule_gantt_style.blade.php`（新規）** で、
`@push('styles')` に入れてボードとカードの両 partial が `@include` する。
`resources/css/app.css` には置かない。理由:

- アプリの先行例がこの形（`housing/contracts/index.blade.php` の `.co-sticky` は
  ビューの中に CSS を置き、`CustomOrderIndexListColumnsTest` が
  **レンダリング済み HTML の正規表現**で `left: 0` / `left: 96px` を固定している）
- **テストが CSS を直接見られる。** `app.css` に置くと、ビルド済み CSS は `.gitignore` 済みで
  worktree に存在しないため（RULES「Tailwind 監査の落とし穴 1」）、テストはソースを読むしかない
- ビルドに依存しないので、ローカルで `npm run build` をしなくても効く
- `AREA_MAP_STYLES` を `_map_style.blade.php` に 1 本化した先行例（2026-08-31）と同じ形

```css
/* 横スクロールする外側の div に当てる。ボードは .gantt-scroll だけ、
   カードは .gantt-scroll.gantt-scroll--card の 2 つを当てる（後者が変数を上書きする）。 */
.gantt-scroll       { --gantt-label-w: 320px; }
.gantt-scroll--card { --gantt-label-w: 262px; }
.gantt-label        { position: sticky; left: 0; z-index: 5; background: #fff;
                      box-shadow: 6px 0 6px -6px rgba(0, 0, 0, 0.18); }
.gantt-label--head  { z-index: 6; background: #F9FAFB; }
@media (max-width: 640px) {
    .gantt-scroll   { --gantt-label-w: 140px; }   /* --card より後に置く。両方 140px になる */
}
```

⚠ **メディアクエリを `--card` より後ろに置く。** 詳細度は両方 (0,1,0) なので後勝ちになり、
カードにも 140px が効く。順序を入れ替えるとカードだけ 262px のまま残る（Bug #29 の
「PHP もテストも素通りしてブラウザでだけ壊れる」型）。

⚠ **影は `::after` ではなく `box-shadow` で出す。** ラベルのセルは `overflow: hidden` を
持っており（Bug #29 対策で外せない）、`::after` を `right: -6px` に置くと**クリップされて消える**。
`overflow` は子孫を切るが、その要素自身の `box-shadow` は切らない。

⚠ **`@once` で囲む。** ボードとカードが同一ページに同居することは現状ないが、
将来同居したときに `<style>` が 2 回出るのを防ぐ。

PHP が出すのは動的な部分だけ:

```blade
<div style="width: calc(var(--gantt-label-w) + {{ $axis['trackWidthPx'] }}px);">
```

案件名セルは `flex: 0 0 var(--gantt-label-w)`。軸トラックは `flex: 1 1 auto; min-width: 0`（現状のまま）。
親の幅が確定するのでトラックはちょうど `trackWidthPx` を取る。

⚠ 境目は **`max-width: 640px`** にする。`app.css` の既存ユーティリティ
（`.grid-stack-sm` / `.grid-2col-sm` / `.dl-stack-sm`）がこの値なので**それに揃える**
（Tailwind の `sm:` の厳密な補集合は 639.98px だが、アプリ内で境目が 2 種類あるほうが害が大きい）。

---

## 5. ズームセレクタの削除（D7）

現在の `ZOOMS` は**軸の範囲と見出しの粒度を一緒に変える**作り:

```php
'week'    => ['before' => 1,  'after' => 2,  'granularity' => 'week'],
'month'   => ['before' => 6,  'after' => 12, 'granularity' => 'month'],   // 既定
'quarter' => ['before' => 12, 'after' => 24, 'granularity' => 'quarter'],
```

案B で範囲がデータ側に決まると、`before` / `after` は意味を失う。
粒度だけ残しても、月幅 150px 固定のもとで週見出しを出すと 1 セル約 35px で潰れる。
**既定の `month` が §1.1 の見にくさの原因そのもの**なので、残す理由が弱い。

⚠ 既存の URL `?zoom=week` は**無視されるだけ**（未知のクエリキーは効かない）。リダイレクトは不要。
⚠ 数年にわたる案件でスクロールが長くなりすぎたら、「月 / 四半期」の 2 択として**後から戻せる**。
その場合は「範囲」ではなく「1 単位の px 幅」を切り替える形にすること。

---

## 6. 今日が軸の外にあるとき（D8）

案B では、今日より前に全部終わった案件だけを「済」で絞り込むと、**今日が軸の外に出る**。

**軸は伸ばさない。今日線・今日バッジを描かない。**
`$scale->contains($today) ? $scale->left($today) : null` という既存の分岐がそのまま効き、
Blade 側も `@if($axis['todayPct'] !== null)` で守られているので、コードの追加は不要。

⚠ **`ScheduleCardService::gantt()` の「今日が範囲外なら軸を今日まで伸ばす」2 ブロックを削除する。**
残すとボードとカードで規則が食い違う。

```php
// 削除する
if ($today->lessThan($from))    { $from = $today->startOfMonth(); }
if ($today->greaterThan($to))   { $to   = $today->endOfMonth()->startOfDay(); }
```

⚠ カードには **±1 ヶ月のパディング**（`PADDING_MONTHS = 1`）があるので、
今日が範囲外になるのは「1 ヶ月以上離れているとき」だけ。パディングは**変えない**。

### 6.1 軸に使う日付

| | 集める日付 | 余白 |
|---|---|---|
| ボード | 各 drawable 工程の `drawStart()` と `drawEnd($today)` ＋ 各案件の `autoMilestones()` の日付 | なし |
| カード | 現状のまま（4 日付すべて ＋ ◆） | ±1 ヶ月 |

⚠ **ボードで ◆ の日付を入れ忘れないこと。** 着工予定日・完成予定日が棒の範囲の外にあると、
`clamp()` で 0% / 100% に貼り付いて**軸の端に嘘の位置で出る**。

⚠ **`drawEnd($today)` を使う（生の `planned_end` ではない）。** 不動産で実績開始があり実績終了が無い工程は
「進行中」として棒が今日まで伸びるので、軸も今日を含む必要がある。

### 6.2 日付が 1 つも無いとき

工程はあるが日付が全部未設定の案件だけが残ると、軸を作る種がない。
**今日の 1 ヶ月**（`$today->startOfMonth()` 〜 `$today->endOfMonth()`）にフォールバックする。
棒は 0 本なので実害はなく、0 除算を避けつつ行を並べられる。

⚠ これは `ScheduleCardService` の既存の扱い（`if ($dates === [] && $steps->isNotEmpty()) { $dates[] = $today; }`）と同じ考え方。
「工程が登録されていません」という**嘘の案内**を出さないこと。

---

## 7. 開いた直後の横スクロール（D9 / D11）

### 7.1 ボード

案B の軸は**データの開始月**から始まるので、本番のデータでは開いた直後に 2〜7 月あたりが見え、
**今日（9/3）は右に 366px スクロールしないと見えない**。工程表は「現状の工程を確認するもの」
（2026-09-02 の利用者判断）なので、開いた瞬間に今が見えているべき。

サーバが渡す `axis.todayPct`（既存）と `axis.trackWidthPx` を使い、スクロール量だけを JS が出す。
**位置(%) の計算は PHP のままなので、二重実装にはならない**（Bug #41）。

⚠ **`x-init` にアロー関数を書かない**（Top trap #4: `=>` の `>` が HTML 終了タグとして解釈される）。
`@push('scripts')` に**名前付き関数と呼び出し**を置く:

```blade
@push('scripts')
<script>
function scheduleBoardScrollToToday(id, pct, trackPx) {
    var el = document.getElementById(id);
    if (!el) { return; }
    var labelW = parseFloat(getComputedStyle(el).getPropertyValue('--gantt-label-w')) || 0;
    el.scrollLeft = Math.max(0, trackPx * pct / 100 - (el.clientWidth - labelW) / 2);
}
scheduleBoardScrollToToday('schedule-board-scroller', {{ $axis['todayPct'] }}, {{ $axis['trackWidthPx'] }});
</script>
@endpush
```

今日を「固定表示の列より右側の中央」に置く式:
`scrollLeft = trackPx × pct/100 − (見えている幅 − 案件名の幅) / 2`。
案件名の幅は画面幅で変わるので、**CSS 変数から実行時に読む**（PHP に px を持たせない §4.2 と揃う）。

- `@stack('scripts')` は `layouts/app.blade.php` の `</body>` 直前に存在する（Bug #28 で追加済み）。
  ボードの HTML はそれより前にあるので、実行時に要素は存在する
- `todayPct` が `null` のときは `@push` ごと出さない（左端のまま）
- ⚠ **定義側（`function …`）と呼び出し側（`scheduleBoardScrollToToday(…)`）をテストで対にする**。
  片方だけ消えても HTML としては妥当なので、片方だけ見ると無音で死ぬ（Bug #28）
- ⚠ **JS のコメントに `@json` `@if` や `<x-…>` を書かない**（Blade がコメントを解釈して壊れる。Bug #30）

### 7.2 カードには入れない

カードは工程を保存するたびに `gantt_html` でガント全体を差し替える
（`_schedule_section.blade.php` の `document.getElementById('schedule-gantt').outerHTML = data.gantt_html`）。
初期スクロールを入れると、**遠い過去の工程を編集して保存するたびに今日へ跳ぶ**。
利用者が今いる場所から動かされるほうが害が大きい。

---

## 8. 触るファイル

### 変更

| ファイル | 変更 |
|---|---|
| `app/Support/GanttScale.php` | `MONTH_WIDTH_PX` / `monthCount()` / `trackWidthPx()` を追加。**位置計算は不変** |
| `app/Services/ScheduleBoardService.php` | `build()` を 3 パスへ組み替え（§8.1）。`ZOOMS` / `DEFAULT_ZOOM` / `SOON_DAYS` / `kpi()` / `countSoon()` / `countRunningSteps()` / `$keptSteps` / `$filters['zoom']` を削除。`axis.trackWidthPx` を追加 |
| `app/Services/ScheduleCardService.php` | 今日を含める 2 ブロックを削除。`gantt.trackWidthPx` を追加 |
| `resources/views/_partials/_schedule_board.blade.php` | KPI ブロックとズーム `<select>` を削除。`min-width: 1000px` → `calc(var(--gantt-label-w) + Npx)`。案件名セルを sticky ＋ `flex: 0 0 var(--gantt-label-w)`。`@push('scripts')` を追加 |
| `resources/views/_partials/_schedule_gantt.blade.php` | `min-width: 940px` → 同上。工程名セルを sticky。ラッパに `gantt-scroll--card` |
| `resources/views/_partials/_schedule_gantt_style.blade.php` | **新規**。`@push('styles')` に §4.2 の CSS。ボードとカードの両 partial が `@include` する |
| `tests/Feature/Schedule/ScheduleBoardTest.php` | §9 |
| `tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php` | §9 |

### 新規

- `resources/views/_partials/_schedule_gantt_style.blade.php`（CSS の唯一の定義）

PHP のクラス・ルート・DB は増えない。

### 8.1 `build()` の 3 パス

現在は **軸を先に作って行を後から作る**（`$scale` を `row()` に渡す）。
案B では軸が「絞り込み後の行」に依存するので、**順序が逆転する**:

1. **絞り込み** — 案件と工程を集め、`status` / `q` で落とす。
   ⚠ ステータス判定（`status()` / `dateStatus()`）は工程の日付だけを見るので、**軸に依存しない**。
   ここで確定できる（`laneCount` / `rowHeight` も日付だけで決まるので軸非依存）
2. **軸を決める** — 残った案件から §6.1 の日付を集めて `GanttScale` を作る
3. **位置を計算する** — 棒の `leftPct` / `widthPct`、◆ の `leftPct`、`todayPct`、ヘッダの月セル

⚠ **`$keptSteps` は KPI 専用だったので消える。** 「`$rows` と添字を揃える」という既存の注意書きも一緒に消す。

---

## 9. テスト

### 9.1 削除・書き換え（13 本）

`ScheduleBoardTest`（削除 10 / 書き換え 1）:

| テスト | 扱い |
|---|---|
| `test_the_kpis_agree_with_the_rows_on_screen` | 削除 |
| `test_the_kpis_follow_the_filter` | 削除 |
| `test_the_housing_kpis_are_scoped_to_the_filtered_status` | 削除 |
| `test_the_housing_board_shows_three_step_based_kpis` | 削除 |
| `test_the_realestate_board_keeps_four_kpis` | 削除 |
| `test_the_housing_kpi_cards_are_actually_rendered` | 削除 |
| `test_the_realestate_kpi_cards_are_actually_rendered_as_four` | 削除 |
| `test_the_housing_soon_kpi_still_counts_a_step_even_if_you_try_to_give_it_actuals` | 削除（§9.2）|
| `test_zoom_changes_both_the_range_and_the_header_granularity` | 削除 |
| `test_an_unknown_zoom_falls_back_to_month` | 削除 |
| `test_the_default_axis_is_six_months_back_and_twelve_forward` | **書き換え**（案B の軸を固定する）|

`ScheduleDateStateTest`（書き換え 1）:

`test_the_label_column_cannot_be_pushed_wider_than_its_track` は
**`flex: 0 0 262px;` をリテラルで走査**している（`preg_match_all` ＋ 件数 4 を固定）。
`var(--gantt-label-w)` に変えると**このテストが落ちる**ので、正規表現を新しいリテラルへ更新する。
⚠ **件数 4 と `min-width: 0` / `overflow: hidden` の検査は残すこと。** そこが Bug #29 の本体。

`ScheduleRealEstateUntouchedTest`（書き換え 1）:

`test_the_realestate_board_still_shows_four_kpis_a_delay_badge_and_its_four_way_filter`
→ KPI の部分を落とし、**遅延バッジと 4 択フィルタ**の検証として残す。

### 9.2 ⚠ 測定器を消すときは代替の存在を確かめる

`test_the_housing_soon_kpi_still_counts_a_step_even_if_you_try_to_give_it_actuals` は
**KPI を測定器として**「住宅の工程は保存時に実績が null 化される」ことを裏取りしていた。
KPI を消すと測定器ごと消える。

**代替が既にあることを実測で確認済み** — `ScheduleActualsPolicyTest` が同じ不変条件を **6 本**で直接固定している:

```
test_the_validation_rules_drop_the_actual_columns_for_housing
test_saving_a_housing_step_clears_any_actual_dates_already_in_the_database
test_creating_a_housing_step_with_actual_dates_stores_none
test_re_saving_an_untouched_housing_step_still_clears_its_actual_dates
test_the_hook_leaves_realestate_actual_dates_alone
test_posting_actual_dates_to_a_housing_step_stores_nothing
```

⚠ Bug #48 の逆向き（安全網を**足す**と測定器が鈍る／測定器を**消す**と守りが消える）。
**機構を消すときは、それを測っていたテストが他に何を守っていたかを数える。**

### 9.3 ⚠ 「不動産に漏れていない」ことの証拠が 1 つ減る

`ScheduleRealEstateUntouchedTest` は「住宅の変更が不動産のボードに漏れていない」ことを守る要で、
その証拠の 1 つが **KPI 4 枚 vs 3 枚**だった。これが消えるので、残る証拠は次の 3 つになる:

- 遅延バッジ（`+N日` / 棒の `border: 2px solid #DC2626`）
- 4 択フィルタ（進行中 / すべて / 遅延 / 完了。住宅は 進行中 / すべて / これから / 済）
- 詳細カードの実績 2 列（実績開始・実績終了）

⚠ **今後この 3 つを減らすときは、`ScheduleRealEstateUntouchedTest` に代わりの証拠を足すこと。**

### 9.4 追加

| 何を | なぜ |
|---|---|
| 軸が絞り込み後のデータ範囲になる（案B） | D3 の本体 |
| 絞り込みを変えると軸が変わる | 案B のトレードオフを意図として固定する |
| 今日が軸の外なら `todayPct` が null で、HTML に今日線が無い | D8。**viewData と HTML の両方**を見る |
| 日付が 1 つも無いときのフォールバック（今日の 1 ヶ月） | §6.2。0 除算と嘘の案内の両方 |
| `trackWidthPx` == 月数 × 150 | D4 |
| HTML に `calc(var(--gantt-label-w) + …px)` と `position: sticky` が出る | D5 / D6 |
| **KPI ブロックが無い / ズーム select が無い** | 消したものが戻らない |
| 初期スクロールの**定義側と呼び出し側が対で在る** | Bug #28 |
| カードの force-today が消えた（今日が範囲外の案件で `todayPct` が null） | D8 |
| **レンダリング済み HTML** に `.gantt-scroll { --gantt-label-w: 320px }` と `.gantt-label { position: sticky` が出る／`@media (max-width: 640px)` が `--card` より**後ろ**にある | 変数名のタイプミスと順序の入れ替えを止める（先行例 `CustomOrderIndexListColumnsTest`）|

⚠ **走査するテストは「拾えた件数の下限」も併せて固定する**（空振りして緑になる事故を防ぐ。Bug #45）。

⚠ **CSS をビューに置いたので `app.css` は触らない** ＝ ビルド済み CSS
（`public/build/assets/app-*.css`。`.gitignore` 済みで worktree に存在しない）を
確認する必要がそもそも無くなる。`view:cache` の対象なので**本番反映は `./deploy.sh`**。

---

## 10. 検証

1. **変異テスト** — 決定ごとに 1 つ以上。⚠ 作法は Bug #44:
   ①先にコミット ②各変異の前に `git status --porcelain` が空 ③`git diff --stat` が非空で着弾を確認
   ④**落ちた理由の文言まで**突き合わせる
2. **コンパイル済みビュー全数の `php -l`**（`view:cache` の成功表示だけでは足りない。Bug #21 / #26）
3. **実ブラウザ**（使い捨て SQLite ＋ `artisan serve`）:
   - **4 画面 × 1800 / 1200 / 375px = 12 通り**で `main.scrollWidth === main.clientWidth`（Bug #29。
     超過幅は一定なので片方の幅だけでは判定できない）
   - 1 日の工程の**実測幅**が約 4.9px であること
   - **右へスクロールしても案件名が残る**こと（sticky は HTML に出ていても効かないことがある）
   - 開いた直後に**今日が画面内にある**こと
   - 375px で案件名が 140px になり、軸が約 200px 見えること
   - コンソール出力 0 件
4. **本番反映後の目視**（Bug #21 / #26 が「本番だけ壊れる」前例）

⚠ **`resources/css/app.css` は変更しない**ので、この改修に `npm run build` は要らない
（CSS はビューの `@push('styles')` に入る）。`./deploy.sh` は従来どおりビルドを走らせる。

⚠ **DB 変更・ルート変更なし** → 本番反映は `./deploy.sh` のみ。

---

## 11. やらないこと

- 「4 ヶ月ちょうど」を JS で画面幅から算出すること（§3.1）
- ズームを別の形で作り直すこと（§5。必要になったら後から）
- カードへの初期スクロール（§7.2）
- カードの軸のパディング（±1 ヶ月）を変えること
- 案件名の列を**幅可変**にすること（つかんで広げる等）
- ガントの棒の色・状態チップ・遅延バッジの見せ方（2026-09-02 の設計のまま）
- ボードのページング（工程表の設計書 §4.2 のまま。1 部署 200 件を超えたら見直す）
- 不動産と住宅で軸の規則を分けること（D12 で揃える決定をした）
