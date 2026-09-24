# 日付ピッカーの「1ヶ月前」を前月の末日で止める — 実装計画

- 作業場所: worktree `.claude/worktrees/date-picker-month-ago`（ブランチ `date-picker-month-ago`、起点 `month-end-overflow` = `29a43c89`）
- 承認: 2026-09-24 に Plan Mode で承認済み（利用者の仕様の決定は A 案）
- 基準: `OK (1756 tests, 10807 assertions)`


## Context

- 月末の溢れ（docs/RULES.md Bug #62。ブランチ `month-end-overflow`）を直している途中で、同じ形の溢れを JS で見つけた。
- 7 画面の日付ピッカーにある「1ヶ月前」ボタンが、前月にその日が無い日に押すと**当月**の日付を選ぶ。カレンダーも当月のまま。
  - 3/31 → 3/3、5/31 → 5/1、12/31 → 12/1
  - 2026 年なら 3/29・3/30・3/31・5/31・7/31・10/31・12/31 の 7 日で起きる
- 原因: `new Date(年, 月, 日)` から `setMonth(getMonth() - 1)` で月だけ戻している。JS の Date は無い日（2/31）を翌月へ繰り越す。
- **利用者の決定（2026-09-24）: A 案**。前月に同じ日が無ければ前月の末日で止める（Excel の `EDATE` と同じ）。ふつうの日の結果は今と変わらない。
- 「今日」はブラウザのローカル日から取るので、Bug #61（日本時間化）の影響は受けていない。

## 対象（7 か所）

`setMonthAgo` の中身は、7 か所とも空白を除いて完全に同じ。`datePicker()` 全体は 2 系統に分かれる（DAD・住宅の 3 本／マンションの 4 本）。どちらも関数の中に Blade 構文は無いので、ソースとブラウザが受け取る文字列は同じ。

| 部署 | ファイル（`resources/views/` の下） |
|---|---|
| DAD 工事案件 | `dad/projects/_form.blade.php`（ボタンは partial の `_date-picker` と `_date-picker-row`）|
| 住宅（建売・注文住宅の契約の編集） | `housing/contracts/edit-building.blade.php`・`edit-custom-order.blade.php` |
| 賃貸マンション（部屋・駐車場の改定と解約） | `mansion/contracts/{revise,terminate}.blade.php`・`mansion/parking-contracts/{revise,terminate}.blade.php` |

## 変更（7 か所とも同じ 2 行の置き換え）

```js
setMonthAgo: function () {
    // 前月に同じ日が無いとき（3/31 など）は前月の末日で止める（Excel の EDATE と同じ）。
    // 月だけを 1 つ戻すと 2/31 → 3/3 のように当月へ溢れる（docs/RULES.md Bug #62）
    var lastDay = new Date(this.todayYear, this.todayMonth, 0).getDate();
    var d = new Date(this.todayYear, this.todayMonth - 1, Math.min(this.todayDate, lastDay));
    this.selected = d;          // ← ここから下は今のまま
    ...
}
```

- `new Date(年, 月, 0)` は前月の末日（JS の月は 0 始まり）。1 月は `todayMonth - 1` が -1 になり、JS が前年の 12 月として扱う
- ⚠ コメントに `@` や `<x-` を書かない。Blade は JS のコメントの中も展開する（Bug #30）
- ⚠ コメントに `setMonth(` と書かない。下の走査（ビューの `setMonth(` は 0 件）に当たる
- 試作で動作を確かめた（scratchpad で両系統のコピーに当て、node で実行）:

| 押した日 | 結果 | |
|---|---|---|
| 3/31・3/29 | 2/28 | |
| 5/31 | 4/30 | |
| 12/31 | 11/30 | |
| 1/31 | 前年 12/31 | 年をまたぐ |
| 2028-03-31 | 2/29 | 閏年 |
| 3/15 | 2/15 | ふつうの日は今と同じ |

  いずれもカレンダーの表示月が前月へ移る。

## 回帰テスト（新規 `tests/Feature/DatePickerMonthAgoTest.php`）

PHP のテストから JS は直接動かない。前例 `tests/Feature/Tenant/AreaBuildingMapTabTest.php` の `runMapScript()` と同じく、node の vm で実際に動かす。

1. **全件分類で拾う。**
   - `resources/views` の Blade から、`setMonthAgo: function` を持つファイルを機械的に列挙する。空振りを防ぐため、下限を 7 本にする
   - 各ファイルの `function datePicker(` を、波括弧の対応で切り出す
   - 切り出した中に Blade 構文（`{{` `{!!` `@`）が無いことも確かめる（＝ソースとブラウザが同じ文字列）
2. **振る舞い。**
   - 各コピーを node の vm に読み込み、引数なしの `new Date()` だけを固定の日時に差し替える
   - `datePicker('')` → `setMonthAgo()` を呼び、`isoValue` / `viewYear` / `viewMonth` / `open` を見る
   - 期待値はリテラルで書く: 上の表の 7 通り
3. **掃引。** 各コピーについて 2026-01-01〜2028-12-31 の全 1,096 日を、別の書き方の答えと突き合わせる。答えは月の日数の表と閏年の規則で求め、実装と同じ式では作らない
4. **自己検査。** 3/31 が「月だけを 1 つ戻す書き方」では当月へ溢れる日であることを、node で確かめる。日付を差し替えた瞬間に落ちる（Bug #62 の `assertTodayOverflows()` と同じ考え方）
5. **走査（ラチェット）。** 直した後、ビューの `setMonth(` は 0 件。新しく足したら、理由を書いて分類するまで落ちる

node が無い環境では `markTestSkipped` にする（前例と同じ）。

## 作業場所・進め方・コミット

- **worktree:** `month-end-overflow` の先頭（`29a43c89`）から、新しいブランチ `date-picker-month-ago` を切る
  - 13.x から切らない理由: 記録を Bug #62 と Top trap #20 への追記にするので、同じ docs が衝突しないようにする
  - 月末の溢れを先に 13.x へ入れれば、こちらも早送りで入る
- vendor は既存の worktree から `cp -a` で実体コピーする
- **進め方:** superpowers:subagent-driven-development（実装 → 仕様適合レビュー → コード品質レビュー）
  - レビュー役は、ファイルを変えない・変異テストをしない・git を触らない
  - 変異テストと最終確認は親セッションが行う
- **コミット:**
  1. 計画（`docs/superpowers/plans/2026-09-24-date-picker-month-ago.md`）
  2. `fix:` Blade 7 本
  3. `test:`
  4. `docs:` RULES.md の Bug #62 への追記・CLAUDE.md の Top trap #20 への一言・BACKLOG の範囲外の項目を完了に

## 検証

- **全件テスト。** 基準は 1756 tests / 10807 assertions で、これに新しいテストが加わる
- **変異テスト**（Bug #44 の作法）:
  - カナリア
  - 7 つのコピーを 1 つずつ元の書き方へ戻す。どれを戻しても、そのファイルを名指しして落ちること（＝全件分類の証明）
  - `Math.min` を外す
  - `new Date(年, 月, 0)` の月を +1 にずらす
  - `todayMonth - 1` を `todayMonth` にする
  - テストの日付を 3/15 にする（自己検査で落ちる）
  - 新しいビューに `setMonth(` を足す（走査で落ちる）
- **コンパイル済みビューの `php -l`。** 基準は 269 本 / INVALID 0 件
- **実ブラウザ**（Playwright。画面が見えている状態で）:
  - 2 系統から 1 画面ずつ見る（マンションの部屋契約の賃料改定・住宅の建売契約の編集）
  - 準備は memory の手順どおり: 使い捨て SQLite と `artisan serve` を使い、認証済みで描画した HTML を public に出す。vite build は main repo の node_modules で行う
  - Playwright の時計を 2026-03-31 に固定し、「1ヶ月前」を押す
  - 表示が 2026/02/28、送信欄（`revision_date` / `contract_date`）が 2026-02-28 になり、コンソールのエラーが 0 件であること
  - 時計を固定できなければ、今日の日付で押して退行が無いことだけを見る
  - 確かめたら出力物を消す

## 範囲外

- 7 つの複製を 1 つの共通スクリプトにまとめること。今回は同じ 2 行を 7 か所に当てる。まとめるなら別の作業
- 月末の溢れのブランチ（`month-end-overflow`）の扱い（マージ・本番反映）は、別途の指示を待つ

---

## 実測記録（2026-09-24）

### コミット

| コミット | 内容 |
|---|---|
| `2d21daea` | docs: この計画 |
| `406dc555` | fix: Blade 7 本の `setMonthAgo` を前月の末日で止める |
| `ff29243e` | test: `tests/Feature/DatePickerMonthAgoTest.php`（node の vm で実際に動かす） |
| `c42f1380` | test: ハーネスの甘さ（`new` なしの Date）・検出器の自己テスト・死角の列挙など（コード品質レビューの Important 3 件と Minor） |
| `e60adfab` | test: 大文字のディレクティブ（`@If(...)`）のサンプル・構文エラーの種類を失敗文に出す（再レビューの Minor） |

### 直す前の赤（TDD）

テストだけを置いた状態で、テスト 1（全件分類）は緑、2〜4 は溢れが理由で赤:
- テスト 2: dad `_form` で今日が 3/31 のとき `2026-03-03 / 表示 2026-03`（期待 `2026-02-28 / 2026-02`）
- テスト 3: 食い違い 20 日（3/29→3/01・3/30→3/02・3/31→3/03・5/31→5/01・7/31→7/01 …）
- テスト 4: `.setMonth(` が 7 件（7 か所の定義）

### レビュー

- 仕様適合: 指摘なし（実装者が広げた 4 点＝列挙の正規表現・Blade 構文の検出・ハーネスの調整・`ALLOWED` の形は「改善」と判定）
- コード品質（1 回目）: 「直してから」。Important 3 件 — ①偽の Date が `new` なしの呼び出しで Date を返す（ブラウザは文字列）ため `new` を落とした変異が素通りする ②3 つの検出器の枝に自己テストが無い ③走査とハーネスの死角が書かれていない。Minor 5 件（`</script` の検査・KEY_DAY の説明・初期値ありの行・下限 240・「定義 7 か所・画面 8」）
- コード品質（再レビュー）: 「マージしてよい」。残りの Minor 2 件（大文字のディレクティブ・構文エラーの種類）も取り込んだ
- 据え置き: Blade ファイルの中の JS の `//` コメントの「（docs/RULES.md Bug #62）」（参照先が分かるので）・テスト 1 の名前

### 変異テスト（29 通り。docs/RULES.md Bug #44 の作法）

実行役は `scratchpad/mutate2.py`（作業場所は環境変数。各変異の前後に作業ツリーが空・置換対象がちょうど 1 回・`git diff --stat` で着弾・全件を `--log-junit`・`finally` で戻す）。

| ID | 変異 | 結果（落ちたテストと理由） |
|---|---|---|
| P00 | カナリア: edit-custom-order の JS の構文を壊す | ✅ テスト 2・3「node で datePicker() を動かせなかったコピー」で edit-custom-order を名指し |
| P01〜P07 | 7 コピーを 1 つずつ元の書き方へ戻す | ✅ 7 通りとも、テスト 2・3・4 がそのファイルを名指し（テスト 4 は `ファイル:行`） |
| P08 | `Math.min` を外す（dad `_form`） | ✅ テスト 2・3 が dad `_form` を名指し |
| P09 | 末日の月を +1 にずらす（parking-contracts/terminate） | ✅ テスト 2・3 が名指し |
| P10 | 前月でなく当月を選ぶ（edit-building） | ✅ テスト 2・3 が名指し |
| P11 | テストの KEY_DAY を 3/15 | ✅ テスト 2 だけ「KEY_DAY（2026-03-15）は、月だけを 1 つ戻す素朴な書き方でも前月へ移る…＝溢れない日」 |
| P12 | 定義の無いビュー（dad `_date-picker`）に `.setMonth(` を足す | ✅ テスト 4 だけ（`_date-picker` を名指し） |
| P13 | 定義の名前を変えて列挙から外す | ✅ テスト 1・2・3 が「定義が 6 か所しか見つからない（下限 7）」 |
| P14 | datePicker() の中の JS コメントに `@json` | ✅ テスト 1 だけ「Blade 構文『@j』がある」 |
| P15 | 掃引の範囲を 2026 年だけに | ✅ テスト 3 だけ「掃引した日数が 365 日（…1096 日と合わない）」（各コピー） |
| P16 | `setMonthAgo` の `this.open = false` を消す | ✅ テスト 2 だけ（`open: true`） |
| P17 | 等価のはず: 短縮記法 `setMonthAgo() {` にする | ✅ 全件緑（広げた列挙が拾う） |
| Q01 | 直した行の `new` を落とす | ✅ テスト 2・3 が node の errors で名指し |
| Q02 | 末日の行の `new` を落とす | ✅ 同上 |
| Q03 | `var now = new Date();` の `new` を落とす | ✅ 同上 |
| Q04 | 検出器 SET_MONTH から `(UTC)?` を消す | ✅ 自己テストだけ「SET_MONTH が拾えていない: d.setUTCMonth(1);」 |
| Q05 | 検出器 BLADE_SYNTAX からコンポーネントのタグと生の PHP の枝を消す | ✅ 自己テストだけ「BLADE_SYNTAX が拾えていない: `<x-foo>`」 |
| Q06 | 検出器 DEFINITION から短縮記法の枝を消す | ✅ 自己テストだけ「DEFINITION が拾えていない: setMonthAgo() {」 |
| Q07 | datePicker() の中の JS コメントに `</script>` | ✅ テスト 1 だけ（SCRIPT_END） |
| Q08 | 死角: `<script>` を `type="text/template"` にする | ✅ 全件緑（docblock の死角のとおり） |
| Q09 | 「1ヶ月前」を選択中の日付の基準にする | ✅ テスト 2 だけ（初期値 2020-06-15 の行が 2020-05-15） |
| Q10 | 死角: 定義の無いビューに `new Date(2026, 3 - 1, 31)` を足す | ✅ 全件緑（docblock の死角のとおり） |
| Q11 | 検出器 BLADE_SYNTAX の `@[A-Za-z]` を `@[a-z]` に | ✅ 自己テストだけ「BLADE_SYNTAX が拾えていない: @If($x)」 |

### 最終確認

- 全件 `OK (1761 tests, 10979 assertions)`（基準 1756 本・10807 から +5 本・+172）
- コンパイル済みビュー **269 本 / INVALID 0 件**（新しいコードは 7 本に入っている）
- 実ブラウザ（Playwright・時計を `2026-03-31T12:00+09:00` に固定）。使い捨ての SQLite に 2 画面ぶんのデータを作り、認証済みで描画した HTML を `artisan serve` で配った（`public/build` は main repo のものをリンク）:
  - マンション（部屋契約の賃料改定）: 初期 2026/09/24 →「1ヶ月前」で表示 2026/02/28・`revision_date` 2026-02-28・表示月 2026-02・押した直後に `open` が false（600ms 後にポップアップも消える）・コンソールのエラー 0 件
  - 住宅（建売契約の編集）: 初期 2026/07/01 → 表示 2026/02/28・`contract_date` 2026-02-28・表示月 2026-02・閉じる・エラー 0 件（初期値があっても基準は今日）
  - ⚠ 最初の描画は `public/build` のリンクを張り忘れて 500（Vite manifest not found）。memory の手順どおりリンクしてやり直した
  - 後片付け: サーバ停止・描画した HTML とリンクと SQLite を削除・Playwright の出力なし・作業ツリーは空

### 計画からの訂正（最終レビューの指摘）

- 冒頭の「7 画面」は、正確には「定義 7 か所・画面 8」（DAD の `_form` は登録と編集の 2 画面に入る）
- 「回帰テスト」の 2 の「リテラルの 7 通り」は、コード品質レビューで初期値 2020-06-15 で開く行を足して **8 通り**になった
- **テストの番号の数え方が 2 通りある。** 計画の「回帰テスト」の 1〜5 は「4＝自己検査・5＝走査」だが、上の実測記録と変異表の「テスト 1〜5」はテストメソッドの順（1＝全件分類・2＝振る舞い（先頭に自己検査）・3＝掃引・4＝走査・5＝検出器の自己テスト）。変異表の「テスト 4 だけ」は走査のこと
- 変異表の結果の文言は要約。実際の失敗文は、自己テストのサンプルが JSON の二重引用符つき（例: `SET_MONTH が拾えていない: "d.setUTCMonth(1);"`）で、末尾を省いたもの（P11・P13・P15）がある
