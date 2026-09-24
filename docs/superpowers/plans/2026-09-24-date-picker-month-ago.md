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
