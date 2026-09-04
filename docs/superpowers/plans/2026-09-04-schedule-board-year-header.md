# 工程表ボードの軸ヘッダに年を出し、初期スクロールを前月に変える 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 工程表ボード・詳細カードの軸ヘッダに**毎月の年**を 1 行で出し、ボードの初期スクロールを「今日の前月の 1 日が左端」に変える。

**Architecture:** サーバ側は `ScheduleBoardService` に 2 つの値を足すだけ（`headers()` の各要素に `year` / `axis` に `initialPct`）。Blade は受け取った値を置くだけで、日付 → % の計算も出し分けの判定も持たない。年の見た目は共有 partial の `.gantt-year` **1 箇所**が持つ。**DB 変更・ルート変更・新規 composer 依存はいずれも無し。**

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（JS ライブラリなし）/ PHPUnit

---

## 0. 前提（先に読むこと）

### 0.1 正本

**設計書 `docs/superpowers/specs/2026-09-03-schedule-board-gantt-design.md` の §12 が今回の正本。**
（§12.1 なぜ / §12.2 決定 D13〜D15 / §12.3 案①を棄却した実測 / §12.4 実装 / §12.5 Carbon の罠 /
§12.6 テスト / §12.7 やらないこと）

⚠ **§7.1 だけを読んで実装しないこと。** §7.1 は旧 D9（今日を中央）の式で、D15 に置き換わっている。
§2 の D9 行と §7.1 の本文に訂正の道しるべが入っている。

### 0.2 確定した決定

| # | 決定 |
|---|---|
| D13 | 年は**毎月**、月名の**前に 1 行で**置く（9.5px / `#9CA3AF` / `margin-right: 3px`）。ヘッダは 42px・1 行のまま |
| D14 | **詳細カードも同じ形に揃える**（**2 段 → 1 行**。年は今までどおり毎月出る） |
| D15 | 初期スクロールは「今日の**前月の 1 日**」を軸の左端に置く。**今日が軸の外でも常にスクロールする**（0% / 100% で止まる） |

⚠ **「毎月は冗長だから境目（先頭セルと 1 月セル）だけにしよう」と考え直さないこと。**
一度その案を選んだうえで、**現在の本番データ（軸 2026-02〜09）ですら年が画面外になる**ことを
モックで実測して棄却した。経緯は §12.3。

### 0.3 作業環境

**既存の worktree をそのまま使う。新しく作らない**（vendor は `cp -a` の実体コピー済み・
カナリア確認済み・ベースライン実測済み）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/schedule-board-year-header
```

- branch `schedule-board-year-header` = `b03fa2d8`（`13.x` から分岐・ff-merge 可能）
- **ベースライン `OK (1307 tests, 8521 assertions)`**

⚠ **worktree に `.env` は無い。** `APP_KEY` を環境変数で渡す。**偽の鍵（`base64:x`）を使わない**:

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
```

以降 `./vendor/bin/phpunit` と書いたら、この `APP_KEY` を渡した状態で実行すること。

⚠ **`.env` を作ろうとしない。** 本プロジェクトの安全装置でハードブロックされる（`ls -la .env` すら拒否）。
これは実 MySQL への到達を防いでいるので、迂回しない。

⚠ **main repo（`/Users/masanori/site/manage`）では `phpunit` が無い**（`--no-dev`）。
dev 依存を入れて戻し忘れると `deploy.sh` が本番へ rsync する。**必ず worktree で回す。**

### 0.4 実測で確定済みの値（この plan の期待値の裏付け。2026-09-04 実測）

**Carbon の月末溢れ**（PHP 8.3.30 / worktree の vendor で実測）:

| 今日 | `startOfMonth()->subMonth()` | `subMonth()->startOfMonth()` |
|---|---|---|
| 2026-03-31 | **2026-02-01** ✅ | **2026-03-01** ✗ |
| 2026-03-29 | 2026-02-01 ✅ | 2026-03-01 ✗ |
| 2026-05-31 | 2026-04-01 ✅ | 2026-05-01 ✗ |
| 2026-12-31 | 2026-11-01 ✅ | 2026-12-01 ✗ |
| 2026-08-31 | 2026-07-01 | 2026-07-01（**同じ**） |
| 2026-09-04 | 2026-08-01 | 2026-08-01（**同じ**） |

⚠ **`ScheduleBoardTest` のクラス既定 `TODAY = '2026-08-31'` では、この変異が原理的に素通りする。**
Carbon の罠を測るテストだけ `2026-03-31` に上書きすること。

**`initialPct` の期待値**（`GanttScale` に直接通して実測）:

| 軸 | 今日 | 正しい `initialPct` | 逆順にしたときの値 | `trackWidthPx` |
|---|---|---|---|---|
| 2026-01-01〜2026-05-31 | 2026-03-31 | **20.529801324503** | **39.072847682119** | 750 |
| 2026-01-01〜2026-01-31 | 2026-08-31 | **100**（右端クランプ） | 100 | 150 |
| 2026-10-01〜2026-12-31 | 2026-08-31 | **0**（左端クランプ） | 0 | 450 |
| 2026-05-01〜2026-09-30 | 2026-08-31 | 39.869281045752 | 39.869281045752 | 750 |

**年またぎ 14 ヶ月の軸**（工程 2025-06-15〜2026-07-10 → 軸 2025-06-01〜2026-07-31）:

```
count  = 14
labels = ['6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月','5月','6月','7月']
years  = ['2025','2025','2025','2025','2025','2025','2025','2026','2026','2026','2026','2026','2026','2026']
strong = [false,true,false,false,true,false,false,true,false,false,true,false,false,true]
trackWidthPx = 2100
```

⚠ **`6月` と `7月` が 2 回ずつ出る。** これが Codex Minor 4 の再現そのもの。

---

## 1. 変更するファイル

**実体は 4 ファイル。`ScheduleCardService` は変更しない**（`months()` は既に `year` を返している）。

| ファイル | 責務 | 変更 |
|---|---|---|
| `app/Services/ScheduleBoardService.php` | ボードのデータ組み立て | `headers()` に `year` ／ `axis` に `initialPct` ＋ 私有メソッド `initialScrollPct()` |
| `resources/views/_partials/_schedule_gantt_style.blade.php` | ガント CSS の**唯一の定義** | `.gantt-year` を追加 |
| `resources/views/_partials/_schedule_board.blade.php` | ボード本体 | 月セルに年 span ／ スクロールを `initialPct` 基準に改名・改式 |
| `resources/views/_partials/_schedule_gantt.blade.php` | 詳細カード本体 | 月セルを 2 段 → 1 行、インライン style → `.gantt-year` |

テスト（既存ファイルに追記・書き換え。新規ファイルは作らない）:

| ファイル | 変更 |
|---|---|
| `tests/Feature/Schedule/ScheduleBoardTest.php` | 新規 6 本 ＋ 既存 3 箇所の書き換え（401 / 581 / 623 行） |
| `tests/Feature/Schedule/ScheduleCardAxisTest.php` | 新規 3 本 ＋ 既存 1 箇所の needle 更新（232 行） |

⚠ **`scheduleBoardScrollToToday` は計 8 箇所ある**（2026-09-04 実測）。Task 6 で全部追従させる:

```
resources/views/_partials/_schedule_board.blade.php:136   （関数定義）
resources/views/_partials/_schedule_board.blade.php:142   （呼び出し）
tests/Feature/Schedule/ScheduleBoardTest.php:401
tests/Feature/Schedule/ScheduleBoardTest.php:593
tests/Feature/Schedule/ScheduleBoardTest.php:596
tests/Feature/Schedule/ScheduleBoardTest.php:609
tests/Feature/Schedule/ScheduleBoardTest.php:635
tests/Feature/Schedule/ScheduleCardAxisTest.php:232
```

（`docs/superpowers/` 配下の旧プラン・設計書にも出てくるが、**過去の記録なので触らない**。）

⚠ **この表は「触るファイル」であって「守るべき不変条件」の一覧ではない。**
2026-09-04 のレビューで、表に無い 2 つの不変条件が無防備だと実測で分かった ——
①**`<script>` タグに属性が付いていないこと**（`type="text/template"` にすると内容は残るのに
実行だけ止まり、当初フルスイート 1315 本が緑だった）②**`initialPct = 0` の経路の描画**
（`{{ … ?: 100 }}` が 216 本すべて素通りした）。**同型の改修では「触る場所」でなく
「壊れうる形」を先に数えること。**

---

## Task 1: `headers()` に `year` を足す

**Files:**
- Modify: `app/Services/ScheduleBoardService.php:410-441`（`headers()`）
- Test: `tests/Feature/Schedule/ScheduleBoardTest.php`（新規 1 本）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleBoardTest.php` の
`test_the_axis_headers_are_month_labels_with_quarter_emphasis`（420 行）の**直後**に足す:

```php
    /**
     * 軸が 12 ヶ月を超えると同じ月名が複数出るので、月ごとに年を持たせる
     * （Codex レビュー Minor 4 / 設計書 §12.2 D13）。
     *
     * ⚠ **既存の `test_the_axis_headers_are_month_labels_with_quarter_emphasis` は
     *   単年 6 ヶ月の軸**なので、年またぎのケースを一度も通らない。ここは別に置く。
     *
     * ⚠ **フィクスチャは 6月 / 7月 が 2 回ずつ出る軸にする。** これが Minor 4 の再現で、
     *   年が無ければ利用者が区別できない状態そのもの。単年の軸で測ると
     *   「年を落としても月名だけで読める」ので、欠陥の再現になっていない。
     */
    public function test_the_axis_headers_carry_the_year_of_every_month(): void
    {
        // 工程 2025-06-15〜2026-07-10 → 軸 2025-06-01〜2026-07-31 ＝ 14 ヶ月
        $this->caseWithSteps('PRC-CROSS', [['planned_start' => '2025-06-15', 'planned_end' => '2026-07-10']]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $headers = $axis['headers'];

        $this->assertSame('2025-06-01', $axis['from'], '軸の始まりが変わっている');
        $this->assertSame('2026-07-31', $axis['to'], '軸の終わりが変わっている');
        $this->assertCount(14, $headers, '年をまたぐ 14 ヶ月の軸になっていない');

        $this->assertSame(
            ['6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月', '4月', '5月', '6月', '7月'],
            array_column($headers, 'label'),
            '同じ月名が 2 回出る軸になっていない（このテストの前提が崩れている）'
        );

        $this->assertSame(
            ['2025', '2025', '2025', '2025', '2025', '2025', '2025',
             '2026', '2026', '2026', '2026', '2026', '2026', '2026'],
            array_column($headers, 'year'),
            '月ごとの年が出ていない（6月 / 7月 が 2 回ずつ出るのに区別できない）'
        );
    }
```

- [ ] **Step 2: 失敗することを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter test_the_axis_headers_carry_the_year_of_every_month
```

期待: FAIL（`Undefined array key "year"` または `array_column` が空配列を返して
`Failed asserting that two arrays are identical.`）

- [ ] **Step 3: 実装する**

`app/Services/ScheduleBoardService.php` の `headers()` の docblock と配列を差し替える。

**変更前**（410-416 行付近）:

```php
    /**
     * 月の見出し。⚠ 粒度の切り替え（週 / 四半期）は 2026-09-03 に削除した（設計書 §5）。
     *
     * @return list<array{label: string, widthPct: float, strong: bool}>
     */
    private function headers(GanttScale $scale): array
```

**変更後**:

```php
    /**
     * 月の見出し。⚠ 粒度の切り替え（週 / 四半期）は 2026-09-03 に削除した（設計書 §5）。
     *
     * ⚠ **`year` を落とさないこと**（設計書 §12.2 D13）。軸が 12 ヶ月を超えると
     *   同じ月名が複数出て、年が無いと区別できない（Codex レビュー Minor 4）。
     *   ⚠ **出し分けの真偽値（`showYear` の類）を作らない。** 毎月出すので判定そのものが無い。
     *     判定を持つとボードとカードの 2 箇所に同じ式が並び、片方だけ直る余地ができる（Bug #41）。
     *
     * @return list<array{label: string, year: string, widthPct: float, strong: bool}>
     */
    private function headers(GanttScale $scale): array
```

**配列**（433-437 行付近）:

```php
            $headers[] = [
                'label'    => $cursor->format('n') . '月',
                'year'     => $cursor->format('Y'),
                'widthPct' => $days / $scale->totalDays() * 100,
                'strong'   => in_array($cursor->month, [1, 4, 7, 10], true),
            ];
```

- [ ] **Step 4: 通ることを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter 'ScheduleBoardTest'
```

期待: PASS（既存の `test_the_axis_headers_are_month_labels_with_quarter_emphasis` も
`array_column` で `label` / `strong` しか見ていないので緑のまま）

- [ ] **Step 5: コミットする**

```bash
git add app/Services/ScheduleBoardService.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程表ボードの軸ヘッダに月ごとの年を持たせる

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: `axis` に `initialPct` を足す

**Files:**
- Modify: `app/Services/ScheduleBoardService.php:123-131`（`axis` の組み立て）＋ 私有メソッドを 1 つ追加
- Test: `tests/Feature/Schedule/ScheduleBoardTest.php`（新規 3 本）

- [ ] **Step 1: 失敗するテストを書く**

Task 1 で足したテストの直後に 3 本足す。

⚠ **1 本目の「今日」を `2026-03-31` にするのが load-bearing。** クラス既定の
`TODAY = '2026-08-31'` では `startOfMonth()->subMonth()` と `subMonth()->startOfMonth()` が
**同じ値を返す**ので、§12.5 の順序ミスが原理的に検出できない（§0.4 の表）。

```php
    /**
     * 開いた直後の横スクロール位置は「今日の前月の 1 日」（設計書 §2 D15 / §12.4）。
     *
     * ⚠ **「今日」をクラス既定（2026-08-31）から `2026-03-31` に上書きするのが load-bearing。**
     *   Carbon の `subMonth()` は月末日で溢れるので、月初へ正規化する**前**に引くと
     *   前月ではなく当月が返る（設計書 §12.5）。8/31 や 9/4 では**どちらの順序でも同じ値**に
     *   なるため、月末以外の日で測るとこの変異は素通りする。
     *   実測（2026-09-04）: 2026-03-31 → 正 2026-02-01 / 誤 2026-03-01。
     *
     * ⚠ **クランプの端（0 / 100）でない値で測る。** 0 や 100 だと「常に 0 を返す」
     *   「常に 100 を返す」変異と区別が付かない。
     */
    public function test_the_initial_scroll_puts_the_first_day_of_last_month_at_the_left_edge(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-03-31');
        \Carbon\Carbon::setTestNow('2026-03-31');

        // 工程 2026-01-15〜2026-05-20 → 軸 2026-01-01〜2026-05-31（151 日 / 5 ヶ月）
        $this->caseWithSteps('PRC-MONTHEND', [['planned_start' => '2026-01-15', 'planned_end' => '2026-05-20']]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $this->assertSame('2026-01-01', $axis['from'], 'このテストが前提にしている軸が変わっている');
        $this->assertSame('2026-05-31', $axis['to'], 'このテストが前提にしている軸が変わっている');
        $this->assertSame(750, $axis['trackWidthPx'], '5 ヶ月 × 150px でない');

        // 2026-02-01 は軸の 31 日目 → 31 / 151 * 100 = 20.529801324503%
        // ⚠ 順序を逆にすると 2026-03-01（59 日目）= 39.072847682119% になる
        $this->assertEqualsWithDelta(
            20.529801324503,
            $axis['initialPct'],
            0.0001,
            '初期スクロールが「前月の 1 日」でない（39.07 なら subMonth() の順序ミス。設計書 §12.5）'
        );
    }

    /**
     * ⚠ **今日が軸より後でもスクロールする**（設計書 §12.4）。
     *   従来（D9）は今日が軸の外ならスクリプトごと出さず左端＝一番古い月に留まっていた。
     *   工程表は「現状の工程を確認するもの」なので、直近の月が見えるほうが妥当。
     */
    public function test_the_initial_scroll_clamps_to_the_right_edge_when_today_is_after_the_axis(): void
    {
        // 工程は 2026-01 で終わり。今日（2026-08-31）は軸の外。
        $this->caseWithSteps('PRC-PAST', [[
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
        ]]);

        $axis = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all')
            ->viewData('board')['axis'];

        $this->assertSame('2026-01-01', $axis['from']);
        $this->assertSame('2026-01-31', $axis['to']);
        $this->assertNull($axis['todayPct'], 'このフィクスチャでは今日が軸の外のはず');
        $this->assertSame(100.0, $axis['initialPct'], '軸より後の今日が右端（100）にクランプされていない');
    }

    /** ⚠ 今日が軸より前なら 0%（＝左端。従来と同じ見え方）。上の 100 と**対で**置く。 */
    public function test_the_initial_scroll_clamps_to_the_left_edge_when_today_is_before_the_axis(): void
    {
        // 工程は 2026-10〜12。今日（2026-08-31）の前月 2026-07-01 は軸より前。
        $this->caseWithSteps('PRC-FUTURE', [['planned_start' => '2026-10-05', 'planned_end' => '2026-12-20']]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $axis     = $response->viewData('board')['axis'];

        $this->assertSame('2026-10-01', $axis['from']);
        $this->assertSame('2026-12-31', $axis['to']);
        $this->assertNull($axis['todayPct'], 'このフィクスチャでは今日が軸の外のはず');
        $this->assertSame(0.0, $axis['initialPct'], '軸より前の今日が左端（0）にクランプされていない');

        // ⚠ **描画まで見る。** サービス層だけ見ていると、Blade で 0 を別の値へ潰す変異が
        //    素通りする（2026-09-04 実測: `{{ $axis['initialPct'] ?: 100 }}` で全 216 本が緑だった）。
        //    100 側（test_the_board_still_scrolls_when_today_is_outside_the_axis）は
        //    描画まで固定してあるので、**0 側だけ抜けている非対称**を塞ぐ。
        //    ⚠ `{{ 0.0 }}` は `"0"` になる（科学記法にならないことを 2026-09-04 に実測）。
        $this->assertStringContainsString(
            "scheduleBoardSetInitialScroll('schedule-board-scroller', 0, {$axis['trackWidthPx']});",
            $response->getContent(),
            '今日が軸より前でも左端（0）でスクロール指示が出るはず'
        );
    }
```

- [ ] **Step 2: 失敗することを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter 'test_the_initial_scroll'
```

期待: 3 本とも FAIL（`Undefined array key "initialPct"`）

- [ ] **Step 3: 実装する**

`app/Services/ScheduleBoardService.php` の `axis` に 1 行足す（123-131 行付近）:

```php
            'axis'              => [
                'from'         => $scale->from()->toDateString(),
                'to'           => $scale->to()->toDateString(),
                'headers'      => $this->headers($scale),
                'trackWidthPx' => $scale->trackWidthPx(),
                'todayPct'     => $scale->contains($today) ? $scale->left($today) : null,
                'todayLabel'   => $today->format('n/j'),
                'initialPct'   => $this->initialScrollPct($scale, $today),
            ],
```

`scale()` メソッドの**直前**（`headers()` の並びに合わせるなら `headers()` の直後でもよい。
どちらか一方に置くこと）に私有メソッドを足す:

```php
    /**
     * 開いた直後に軸の**左端**へ置く位置(%)（設計書 §2 D15 / §12.4）。
     * 「今日の前月の 1 日」を 0〜100 にクランプして返す。
     *
     * ⚠ **`null` を返さない。** 今日が軸の外でも前月は必ず計算できるので、Blade 側に
     *   分岐を作らない（今日が軸より前なら 0 ＝ 左端、後なら 100 ＝ 右端で止まる）。
     *   旧 D9 が `todayPct` の null 分岐を持っていたのは `scrollLeft = NaN` を避けるためで、
     *   **その理由は D15 で消えている。理由が消えた分岐を残さない。**
     *
     * ⚠ **`startOfMonth()` を先に通してから `subMonth()` する。順序を逆にしてはいけない。**
     *   Carbon の `subMonth()` は月末日で溢れるので、逆順だと**前月ではなく当月**が返る
     *   （実測 2026-09-04: 2026-03-31 → 正 2026-02-01 / 誤 2026-03-01。設計書 §12.5）。
     *   ⚠ **設計書 §6.1 で軸の月が 1 ヶ月ずれた件とまったく同じ罠。同じ機能で 2 回目。**
     */
    private function initialScrollPct(GanttScale $scale, CarbonImmutable $today): float
    {
        return GanttScale::clamp($scale->left($today->startOfMonth()->subMonth()), 0.0, 100.0);
    }
```

⚠ `CarbonImmutable` と `GanttScale` は既に `use` 済み。追加の import は不要
（`head -20 app/Services/ScheduleBoardService.php` で確認できる）。

- [ ] **Step 4: 通ることを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter 'ScheduleBoardTest'
```

期待: PASS（既存テストは `axis` のキー集合を固定していないので緑のまま。
キー集合を固定しているのは `rows` の 12 キーのテストだけ）

- [ ] **Step 5: コミットする**

```bash
git add app/Services/ScheduleBoardService.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程表ボードの初期スクロール位置を前月基準で算出する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 3: `.gantt-year` を共有 CSS partial に足す

**Files:**
- Modify: `resources/views/_partials/_schedule_gantt_style.blade.php:20-42`（`<style>` の中）
- Test: `tests/Feature/Schedule/ScheduleBoardTest.php`（新規 1 本）＋
  `tests/Feature/Schedule/ScheduleCardAxisTest.php`（新規 1 本）

⚠ **ボードとカードの両方にテストを置く**（Bug #44「被覆されているはずの場所へ当てる」）。
片方だけ測ると、もう片方に変異を当てたとき「守られている」と誤読する。

⚠ **§9.5 の自己参照の罠を踏まないこと。** needle は**宣言の形**（`.gantt-year` + `{`）に限る。
partial に書く注意書きの中で `.gantt-year {` と**波括弧まで**書くと、実体を消しても
コメントが一致して緑のまま通る（設計書 §9.5 / Bug #42 ② / Bug #30 と同型）。
以下の実装では注意書きに `` `.gantt-year` `` としか書かない。

⚠ **needle は `セレクタ + {` にしない。** `\{` をアンカーにするとセレクタリスト
（`.gantt-year, .x { … }`）を取りこぼす（`docs/RULES.md`「Tailwind 監査の落とし穴 3」）。
2026-09-04 実測: その形の複製をカードの Blade へ足すと**全 61 本が緑のまま通り**、しかも
body 側の `<style>` は `@push('styles')` の `<head>` より後ろに出るので**後勝ちで実行時には
複製が勝つ**。**§9.5（自己参照）と落とし穴 3（セレクタリスト）の両方を同時に満たす needle**が
要る —— `/\.gantt-year(?![\w-])/` がその両立解で、代わりに「コメントにクラス名を書けない」
という制約を負う（罠が「値」から「個数」へ移る）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleBoardTest.php` の
`test_the_gantt_style_partial_is_rendered_with_the_label_width_variables`（455 行）の
**直後**に足す:

```php
    /**
     * 年の見た目は共有 partial が**唯一の定義**として持つ（設計書 §12.4）。
     *
     * ⚠ **`font-size: 9.5px` を needle にしない。** 今日のピル（`今日 8/31`）が
     *   ボード・カードとも同じ 9.5px をインラインで持っており、部分一致で false-pass する
     *   （実測 2026-09-04: `_schedule_board:67` と `_schedule_gantt:48`。Bug #43）。
     *
     * ⚠ **宣言がちょうど 1 つであることまで見る。** 片方の Blade へインラインで複製する
     *   変更（`_map_style.blade.php` の AREA_MAP_STYLES で実際に踏んだ型）を止める。
     */
    public function test_the_year_style_is_declared_exactly_once_on_the_board(): void
    {
        $this->caseWithSteps('PRC-STYLE', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $html = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->getContent();

        // 宣言の個数は**セレクタ**で数える。
        // ⚠ `{` をアンカーにしない —— セレクタリスト（`.gantt-year, .x { … }`）を取りこぼす
        //    （RULES「Tailwind 監査の落とし穴 3」）。実測 2026-09-04: その形の複製を
        //    _schedule_gantt.blade.php へ足すと全 61 本が緑のまま通り、しかも後勝ちで
        //    実行時にはその複製が勝つ。
        // ⚠ `(?![\w-])` は `.gantt-year-foo` への前方一致を防ぐ。`class="gantt-year"` は
        //    先頭のドットが無いので元から当たらない。
        // ⚠ **この形にすると、注意書きの中で `.gantt-year` と書いた瞬間に個数が狂う**
        //    （設計書 §9.5 の自己参照の罠が「値」から「個数」へ移る）。共有 partial の
        //    コメントにクラス名を書かないこと。
        preg_match_all('/\.gantt-year(?![\w-])/', $html, $m);
        $this->assertCount(1, $m[0], '年のスタイル宣言が 1 つでない（共有 partial 以外にも定義がある）');

        // ⚠ 3 宣言を 1 本の正規表現で連結して見ない —— CSS の宣言順に意味は無いので、
        //    並べ替えただけで「D13 と違う」と嘘の理由で赤くなる（実測）。役割ごとに分ける。
        $this->assertSame(1, preg_match('/\.gantt-year[^{]*\{([^}]*)\}/', $html, $r), '年のスタイルのブロックを切り出せない');
        $this->assertMatchesRegularExpression('/font-size:\s*9\.5px;/', $r[1], '年の文字サイズが D13 と違う');
        $this->assertMatchesRegularExpression('/color:\s*#9CA3AF;/', $r[1], '年の色が D13 と違う');
        $this->assertMatchesRegularExpression('/margin-right:\s*3px;/', $r[1], '年と月名の間隔が D13 と違う');
    }
```

`tests/Feature/Schedule/ScheduleCardAxisTest.php` の
`test_the_card_page_renders_the_shared_gantt_style`（293 行）の**直後**に足す:

```php
    /**
     * ⚠ **ボード側（`ScheduleBoardTest::test_the_year_style_is_declared_exactly_once_on_the_board`）と
     *   対で置く。** カードは `_schedule_gantt_style` を別の画面から @include するので、
     *   片方だけ測ると「守られている」と誤読する（Bug #44）。
     */
    public function test_the_year_style_is_declared_exactly_once_on_the_card(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        // 宣言の個数は**セレクタ**で数える。
        // ⚠ `{` をアンカーにしない —— セレクタリスト（`.gantt-year, .x { … }`）を取りこぼす
        //    （RULES「Tailwind 監査の落とし穴 3」）。実測 2026-09-04: その形の複製を
        //    _schedule_gantt.blade.php へ足すと全 61 本が緑のまま通り、しかも後勝ちで
        //    実行時にはその複製が勝つ。
        // ⚠ `(?![\w-])` は `.gantt-year-foo` への前方一致を防ぐ。`class="gantt-year"` は
        //    先頭のドットが無いので元から当たらない。
        // ⚠ **この形にすると、注意書きの中で `.gantt-year` と書いた瞬間に個数が狂う**
        //    （設計書 §9.5 の自己参照の罠が「値」から「個数」へ移る）。共有 partial の
        //    コメントにクラス名を書かないこと。
        preg_match_all('/\.gantt-year(?![\w-])/', $html, $m);
        $this->assertCount(1, $m[0], '年のスタイル宣言が 1 つでない（共有 partial 以外にも定義がある）');

        // ⚠ 3 宣言を 1 本の正規表現で連結して見ない —— CSS の宣言順に意味は無いので、
        //    並べ替えただけで「D13 と違う」と嘘の理由で赤くなる（実測）。役割ごとに分ける。
        $this->assertSame(1, preg_match('/\.gantt-year[^{]*\{([^}]*)\}/', $html, $r), '年のスタイルのブロックを切り出せない');
        $this->assertMatchesRegularExpression('/font-size:\s*9\.5px;/', $r[1], '年の文字サイズが D13 と違う');
        $this->assertMatchesRegularExpression('/color:\s*#9CA3AF;/', $r[1], '年の色が D13 と違う');
        $this->assertMatchesRegularExpression('/margin-right:\s*3px;/', $r[1], '年と月名の間隔が D13 と違う');
    }
```

- [ ] **Step 2: 失敗することを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter 'test_the_year_style_is_declared_exactly_once'
```

期待: 2 本とも FAIL（`Failed asserting that actual size 0 matches expected size 1.`）

- [ ] **Step 3: 実装する**

`resources/views/_partials/_schedule_gantt_style.blade.php` の
`.gantt-label--head` の行（33 行）の**直後**に足す:

```blade
            /* 月ヘッダの年（設計書 §12.2 D13）。**毎月**、月名の前に 1 行で出す。
               ⚠ ここが年のスタイルの唯一の定義。ボード・カードのどちらにも
                  インライン style で複製しない（同じ値が 2 箇所に散る）。
               ⚠ margin-right が無いと年と月名がくっついて `20263月` に見える。 */
            .gantt-year         { font-size: 9.5px; color: #9CA3AF; margin-right: 3px; }
```

⚠ **`@media` ブロックより前に置く**（`@media` が `.gantt-scroll--card` より後ろでなければ
ならないという既存の制約を壊さないため。35-41 行のコメントを参照）。

- [ ] **Step 4: 通ることを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter 'ScheduleBoardTest|ScheduleCardAxisTest'
```

期待: PASS

- [ ] **Step 5: コミットする**

```bash
git add resources/views/_partials/_schedule_gantt_style.blade.php \
        tests/Feature/Schedule/ScheduleBoardTest.php tests/Feature/Schedule/ScheduleCardAxisTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): ガントの共有 CSS に年ラベルの唯一の定義を置く

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 4: ボードの月セルに年を出す

**Files:**
- Modify: `resources/views/_partials/_schedule_board.blade.php:63-65`（月セルの `@foreach`）
- Test: `tests/Feature/Schedule/ScheduleBoardTest.php`（新規 1 本）

- [ ] **Step 1: 失敗するテストを書く**

Task 3 で足したテストの直後に足す:

```php
    /**
     * 描画された月セルが**全部**年を出していること（設計書 §12.2 D13）。
     *
     * ⚠ **`assertSee('2026')` のような素の年で数えない。** 工程の日付テキストや
     *   案件名に一致して false-pass する（Bug #43）。**タグ込みか件数で見る。**
     *
     * ⚠ **件数はサービスの月セル数と突き合わせる。** 固定値で書くと、軸の月数が変わる
     *   変異と年が落ちる変異の区別が付かない。
     */
    public function test_every_month_cell_on_the_board_shows_its_year_before_the_month(): void
    {
        // 年をまたぐ 14 ヶ月（6月 / 7月 が 2 回ずつ出る）
        $this->caseWithSteps('PRC-CROSS', [['planned_start' => '2025-06-15', 'planned_end' => '2026-07-10']]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $html     = $response->getContent();
        $headers  = $response->viewData('board')['axis']['headers'];

        $this->assertCount(14, $headers, 'このテストが前提にしている軸が変わっている');

        $this->assertSame(
            count($headers),
            substr_count($html, 'class="gantt-year"'),
            '月セルの年 span の形が変わっている（class="gantt-year" の数が月セル数と一致しない。'
            . '属性の書式を変えただけでもここは落ちる —— 意図した変更なら needle 側も更新すること）'
        );

        // 年 → 月名の順で、間に何も挟まないこと
        $this->assertStringContainsString('<span class="gantt-year">2025</span>6月', $html, '2025 年の 6月 が出ていない（順序が逆か、間に要素がある）');
        $this->assertStringContainsString('<span class="gantt-year">2026</span>6月', $html, '2026 年の 6月 が出ていない（2 つの 6月 が区別されていない）');
        $this->assertStringContainsString('<span class="gantt-year">2026</span>1月', $html, '年の切り替わり（1月）が出ていない');

        // ⚠ **月セルは 1 行**（カードと同じ形。設計書 §12.2 D14）。
        //   `<div style="…"><span class="gantt-year">` の style を切り出して直接見る。
        //   ページ全体を `assertStringNotContainsString('flex-direction: column')` で見ると、
        //   無関係な要素が縦積みになっただけで落ちる脆いテストになる。
        preg_match_all('/<div style="([^"]*)"><span class="gantt-year">/', $html, $cells);
        $this->assertCount(14, $cells[1], '月セルの構造が変わっている（年 span が div の直後に無い）');
        foreach ($cells[1] as $style) {
            $this->assertStringNotContainsString('flex-direction: column', $style, '月セルが 2 段になっている');
            // ⚠ flex の min-width は既定 auto ＝ 中身の min-content が下限を作る。年を足したことで
            //    min-content が 12px → 40.6px に増えた（2026-09-04 実ブラウザ実測）。overflow が
            //    visible 以外のときだけ自動最小サイズが 0 になるので、これは外せない（Bug #29）。
            $this->assertStringContainsString('overflow: hidden', $style, '月セルの overflow: hidden が無い（Bug #29）');
            // ⚠ **肯定的に固定する。** `flex-direction: column` を外したことで
            //    justify-content と align-items の役割が入れ替わった（column では縦/横、
            //    row では横/縦）。値はどちらも center なので見た目は同じだが、旧コードの記憶で
            //    片方だけ触ると 42px の中で上寄せ／左寄せに無音で寄る（2026-09-04 実測: どちらを
            //    flex-start にしてもフルスイート緑だった）。
            $this->assertStringContainsString('display: flex; align-items: center; justify-content: center;', $style, '月セルの中央揃えが崩れている');
        }
    }
```

- [ ] **Step 2: 失敗することを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter test_every_month_cell_on_the_board_shows_its_year_before_the_month
```

期待: FAIL（`Failed asserting that 0 is identical to 14.`）

- [ ] **Step 3: 実装する**

`resources/views/_partials/_schedule_board.blade.php` の 63-65 行を差し替える。

**変更前**:

```blade
                        @foreach($axis['headers'] as $h)
                            <div style="width: {{ $h['widthPct'] }}%; border-right: 1px solid {{ $h['strong'] ? '#D1D5DB' : '#E5E7EB' }}; font-size: 11px; color: #6B7280; display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: hidden;">{{ $h['label'] }}</div>
                        @endforeach
```

**変更後**（`{{ $h['label'] }}` の前に年 span を差し込むだけ。**改行を入れない**）:

```blade
                        {{-- ⚠ 年 span と月名の間に**改行も空白も入れない**（設計書 §12.4）。
                             ⚠ **見た目は変わらない** —— このセルは display: flex なので、改行込みの
                                テキスト実行は匿名ブロックの flex アイテムになり行頭の空白が除去される
                                （2026-09-04 実ブラウザ実測: 改行あり／なしとも間隔 3.000px で完全一致）。
                                変わるのは HTML の形だけで、テストの隣接チェック
                                （<span class="gantt-year">2025</span>6月）が落ちる。
                             ⚠ ただし将来このセルの display: flex を外すと本当に空白が入る
                                （同実測で block 化すると内容が 3.66px 広がる）。 --}}
                        @foreach($axis['headers'] as $h)
                            <div style="width: {{ $h['widthPct'] }}%; border-right: 1px solid {{ $h['strong'] ? '#D1D5DB' : '#E5E7EB' }}; font-size: 11px; color: #6B7280; display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: hidden;"><span class="gantt-year">{{ $h['year'] }}</span>{{ $h['label'] }}</div>
                        @endforeach
```

- [ ] **Step 4: 通ることを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter 'ScheduleBoardTest'
```

期待: PASS

- [ ] **Step 5: コミットする**

```bash
git add resources/views/_partials/_schedule_board.blade.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程表ボードの月ヘッダに年を出す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 5: 詳細カードの月セルを 2 段から 1 行にする

**Files:**
- Modify: `resources/views/_partials/_schedule_gantt.blade.php:41-46`（月セルの `@foreach`）
- Test: `tests/Feature/Schedule/ScheduleCardAxisTest.php`（新規 1 本）

⚠ **カードは既に年を出しているが、2 段（`flex-direction: column`）でインライン style。**
D14 で**ボードと同じ 1 行**に揃える。年が消えるわけではない。

⚠ **設計書 §12.4 の一覧に無い変更を 1 つ入れる: カードの月セルに `overflow: hidden` を足す。**
ボードの月セル（`_schedule_board:64`）は最初から持っており、カードだけ持っていなかった。
**根拠は D14「同じ形に揃える」だけで足りる。**

⚠ **「実害の備え」としては書かない。カードでは構造的に到達しない**（2026-09-04 実測）。
flex の `min-width` は既定 `auto` で中身の min-content 幅が下限を作り、2 段から 1 行にすると
min-content が **12px → 40.6px** に増える。だが `ScheduleCardService::months()` は
`daysInMonth / totalDays` を**クランプせずに**使うので、部分月のある軸では `widthPct` の合計が
**121.6%〜300%** になり、flex の shrink で全セルが比例縮小する ——
**セル幅は月数によらず常に約 138〜153px に収束し、40.6px の床には原理的に届かない。**
床に当たり得るのは、`$scale->to()` でクランプした日数を使う**ボードの `headers()` のほう**
（同実測で最小 **7.5px** のセルが作れる）。

⚠ **カードで部分月が来たときの本当の症状は「`widthPct` の合計が 100% を超えて月グリッドが
棒と静かにズレる」**で、`overflow` はそれを防ぎも起こしもしない。この乖離を今回揃えなかった
理由は付録 A に記録した（Bug #48「安全網を入れない判断にも理由を書く」）。

- [ ] **Step 1: 失敗するテストを書く**

Task 3 で `ScheduleCardAxisTest` に足したテストの直後に足す:

```php
    /**
     * 詳細カードの月セルもボードと**同じ形**にする（設計書 §12.2 D14）。
     * 年 → 月名の 1 行で、見た目は共有 CSS の `.gantt-year` が持つ。
     *
     * ⚠ **「年が出ているか」だけを見ない。** カードは改修前から年を出していたので、
     *   それだけでは 2 段のまま・インライン style のままの状態と区別が付かない。
     *   **1 行であること**と **`class="gantt-year"` を使っていること**を対で見る。
     */
    public function test_the_card_month_cells_are_a_single_line_with_the_shared_year_class(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2025-12-15', 'planned_end' => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $html   = $response->getContent();
        $months = $response->viewData('schedule')['gantt']['months'];

        // 余白 ±1 ヶ月込みで 2025-11-01〜2026-02-28 ＝ 4 ヶ月（年またぎ）
        // ⚠ **単年の軸にしない。** 4 ヶ月すべてが同じ年だと、年を定数に固定する変異が
        //   フルスイート 1315 本すべて緑のまま通る（2026-09-04 実測）。ボード側は
        //   年またぎ 14 ヶ月なので落ちる ＝ 設計書 §12.6 が禁じた「片方だけ守られている」形になる。
        $this->assertCount(4, $months, 'このテストが前提にしているカードの軸が変わっている');

        // ⚠ ついでに「今日が軸の外」も通る（工程が過去なので todayPct が null）。
        //   カードで年を見ているテストは従来この経路を一度も通っていなかった。
        $this->assertNull($response->viewData('schedule')['gantt']['todayPct'], '今日が軸の外である前提が崩れている');

        $this->assertSame(
            count($months),
            substr_count($html, 'class="gantt-year"'),
            '月セルの年 span の形が変わっている（class="gantt-year" の数が月セル数と一致しない。'
            . '属性の書式を変えただけでもここは落ちる —— 意図した変更なら needle 側も更新すること）'
        );

        $this->assertStringContainsString('<span class="gantt-year">2025</span>11月', $html, '年 → 月名の順で 1 行になっていない');
        $this->assertStringContainsString('<span class="gantt-year">2026</span>1月', $html, '年またぎで年が切り替わっていない（年を固定値にしていないか）');

        // ⚠ 月セルの style を切り出して 2 段でないことを見る（ページ全体を見ない）
        preg_match_all('/<div style="([^"]*)"><span class="gantt-year">/', $html, $cells);
        $this->assertCount(4, $cells[1], '月セルの構造が変わっている（年 span が div の直後に無い）');
        foreach ($cells[1] as $style) {
            $this->assertStringNotContainsString('flex-direction: column', $style, 'カードの月セルが 2 段のまま');
            $this->assertStringNotContainsString('line-height: 1.35', $style, '2 段時代の line-height が残っている');
            $this->assertStringNotContainsString('font-size: 9.5px', $style, '年の見た目がインライン style に残っている');
            // ⚠ D14（ボードと同じ形）の固定。⚠ **カードでは現状 load-bearing ではない** ——
            //    months() は daysInMonth をクランプしないので収縮後のセルは常に約 138〜153px で
            //    min-content の床（実測 40.6px）に届かない（2026-09-04 実測）。床に当たり得るのは
            //    クランプ済みの headers() を持つボードのほう。形を揃えるために固定する。
            // ⚠ literal で見ているので `overflow: clip`（自動最小サイズの効果は同じ）でも赤くなる。
            //    意図した変更なら needle 側も更新すること。
            $this->assertStringContainsString('overflow: hidden', $style, 'カードの月セルの overflow: hidden が無い（D14: ボードと同じ形）');
            // ⚠ **肯定的に固定する。** `flex-direction: column` を外したことで
            //    justify-content と align-items の役割が入れ替わった（column では縦/横、
            //    row では横/縦）。値はどちらも center なので見た目は同じだが、旧コードの記憶で
            //    片方だけ触ると 42px の中で上寄せ／左寄せに無音で寄る（2026-09-04 実測: どちらを
            //    flex-start にしてもフルスイート緑だった）。
            $this->assertStringContainsString('display: flex; align-items: center; justify-content: center;', $style, '月セルの中央揃えが崩れている');
        }
    }
```

⚠ 期待値 4 の根拠: `ScheduleCardService` は前後に 1 ヶ月ずつ余白を足す（`PADDING_MONTHS`）。
工程 2025-12-15〜2026-01-20 → 軸 2025-11-01〜2026-02-28 ＝ 11 / 12 / 1 / 2 月の 4 ヶ月（年またぎ）。
**2026 は閏年でないので 2 月は 28 日。**

- [ ] **Step 2: 失敗することを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter test_the_card_month_cells_are_a_single_line_with_the_shared_year_class
```

期待: FAIL（`Failed asserting that 0 is identical to 4.` ＝ `class="gantt-year"` が無い）

- [ ] **Step 3: 実装する**

`resources/views/_partials/_schedule_gantt.blade.php` の 41-46 行を差し替える。

**変更前**:

```blade
                        @foreach($g['months'] as $m)
                            <div style="width: {{ $m['widthPct'] }}%; border-right: 1px solid #E5E7EB; {{ $m['quarterStart'] ? 'border-left: 1px solid #D1D5DB;' : '' }} font-size: 11px; color: #6B7280; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.35; box-sizing: border-box;">
                                <span style="font-size: 9.5px; color: #9CA3AF;">{{ $m['year'] }}</span>
                                <span>{{ $m['label'] }}</span>
                            </div>
                        @endforeach
```

**変更後**（`flex-direction: column` と `line-height: 1.35` を外し、
インライン style の年 span を `.gantt-year` に置き換え、**1 行**にする）:

```blade
                        {{-- ⚠ **形の正本はボード**（_schedule_board.blade.php の月セル）。同じ形にすること（設計書 §12.2 D14）。
                             年 span と月名の間に改行も空白も入れない。flex と改行まわりの実測は
                             ボード側のコメントに 1 箇所だけ置いてある（2 箇所に書くと食い違う）。
                             ⚠ `overflow: hidden` は D14（ボードと同じ形）のために置いてある。
                                ⚠ **カードでは現状 load-bearing ではない** —— months() は daysInMonth を
                                   クランプせず使うので、部分月があっても収縮後のセルは常に約 138〜153px で
                                   min-content の床（実測 40.6px）に届かない（2026-09-04 実測）。
                                   床に当たり得るのはクランプ済みの headers() を持つボードのほう（同実測 7.5px）。
                                ⚠ カードで部分月が来たときの本当の症状は「widthPct の合計が 100% を超えて
                                   月グリッドが棒とズレる」ほうで、これは overflow では直らない。 --}}
                        @foreach($g['months'] as $m)
                            <div style="width: {{ $m['widthPct'] }}%; border-right: 1px solid #E5E7EB; {{ $m['quarterStart'] ? 'border-left: 1px solid #D1D5DB;' : '' }} font-size: 11px; color: #6B7280; display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: hidden;"><span class="gantt-year">{{ $m['year'] }}</span>{{ $m['label'] }}</div>
                        @endforeach
```

- [ ] **Step 4: 通ることを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter 'ScheduleCardAxisTest|ScheduleSectionRenderTest'
```

期待: PASS。⚠ `ScheduleSectionRenderTest::test_the_padding_month_survives_a_step_that_starts_on_the_last_day_of_a_month`
（339 行）はサービスの戻り値を見ているので緑のまま。落ちたら Blade でなく
`ScheduleCardService` を壊している。

- [ ] **Step 5: コミットする**

```bash
git add resources/views/_partials/_schedule_gantt.blade.php tests/Feature/Schedule/ScheduleCardAxisTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 詳細カードの月ヘッダをボードと同じ 1 行に揃える

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 6: 初期スクロールを前月基準に変える（関数の改名を含む）

**Files:**
- Modify: `resources/views/_partials/_schedule_board.blade.php:123-146`
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php:401`（needle 更新）
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php:572-617`（書き換え）
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php:619-636`（**挙動が変わる**ので書き換え）
- Modify: `tests/Feature/Schedule/ScheduleCardAxisTest.php:232`（needle 更新）

⚠ **関数名を `scheduleBoardScrollToToday` → `scheduleBoardSetInitialScroll` に変える。**
もう今日基準ではないので、名前が残ると次に読む人が式を誤解する。**8 箇所すべて追従させる**（§1 の表）。

- [ ] **Step 1: 既存テスト 2 本を書き換える（この時点では失敗する）**

`tests/Feature/Schedule/ScheduleBoardTest.php` の 572-636 行（2 本）を、まるごと次に置き換える:

```php
    /**
     * 開いた直後に「今日の前月」が軸の左端に来る位置までスクロールしておく
     * （設計書 §2 D15 / §12.4）。⚠ **旧 D9（今日を中央）から変わった。**
     *
     * ⚠ **定義側と呼び出し側を対で見る。** 片方だけ消えても HTML としては妥当なので、
     *   呼び出しだけ見ると「関数が無いのに緑」になる（Bug #28）。
     *
     * ⚠ **`--gantt-label-w` を読む式に戻っていないことも見る。** 左端に置くなら
     *   案件名の列の幅を引く必要が無い（設計書 §12.4 の座標系の導出）。
     *   引き算が残っていると前月より左（＝前々月あたり）が左端に来る。
     */
    public function test_the_board_opens_scrolled_to_the_first_day_of_last_month(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $html     = $response->getContent();
        $axis     = $response->viewData('board')['axis'];

        // 定義側
        $this->assertStringContainsString('function scheduleBoardSetInitialScroll(id, pct, trackPx)', $html);
        // 呼び出し側（引数は実際の値で出ていること）
        $this->assertStringContainsString(
            "scheduleBoardSetInitialScroll('schedule-board-scroller', {$axis['initialPct']}, {$axis['trackWidthPx']});",
            $html
        );
        // スクロール先の要素
        $this->assertStringContainsString('id="schedule-board-scroller"', $html);

        // 式そのもの。⚠ 左端に置くだけなので引き算もラベル幅の読み取りも要らない。
        // ⚠ **固定長の窓で切らない**（Bug #45 ④）。定義側と呼び出し側の**両端で挟んで**
        //    関数の中身だけを取り出す。`--gantt-label-w` は共有 CSS にも出るので、
        //    ページ全体を見ると必ず一致して false-pass する（Bug #43）。
        preg_match(
            '/function scheduleBoardSetInitialScroll\(id, pct, trackPx\)(.*?)scheduleBoardSetInitialScroll\(\'schedule-board-scroller\'/s',
            $html,
            $fn
        );
        $this->assertNotEmpty($fn, 'スクロール関数の定義と呼び出しが揃っていない');
        $this->assertStringContainsString('el.scrollLeft = trackPx * pct / 100;', $fn[1], 'スクロール量の式が変わっている');
        $this->assertStringNotContainsString('--gantt-label-w', $fn[1], 'スクロール関数がラベル幅を読んでいる（設計書 §12.4 で不要になった）');
        $this->assertStringNotContainsString('clientWidth', $fn[1], 'スクロール関数が可視幅を使っている（中央寄せの式に戻っている）');

        // ⚠ 今日基準の値を渡していないこと。todayPct と initialPct が偶然一致する
        //    フィクスチャだとこのアサートは意味を失うので、両者が違う値であることも見る。
        $this->assertNotNull($axis['todayPct'], 'このフィクスチャでは今日が軸の中にあるはず');
        $this->assertNotEqualsWithDelta(
            $axis['todayPct'],
            $axis['initialPct'],
            0.0001,
            'initialPct が todayPct と同値になっている（実装が今日基準へ戻ったか、'
                . 'フィクスチャで両者が一致してこのテストの検出力が消えている）'
        );

        // ⚠ **スクリプトはスクローラーの HTML より後ろに出ていなければならない**（Bug #28）。
        //   @push('scripts') を @push('styles') に押し間違えると <head> 側に出るため、
        //   body の描画前に走って document.getElementById() が null を返し、
        //   if (! el) return; のガードで**無音でスクロールが起きない**（コンソールエラーも出ない）。
        //   ⚠ **文字列の存在を見るだけでは検出できない** —— 内容は消えず場所が変わるだけなので、
        //     実測（2026-09-03）では styles へ押し間違える変異が 2 本とも緑のまま通った。
        $scrollerPos = strpos($html, 'id="schedule-board-scroller"');
        $scriptPos   = strpos($html, 'function scheduleBoardSetInitialScroll');
        $this->assertNotFalse($scrollerPos, 'スクローラーの要素が無い');
        $this->assertNotFalse($scriptPos, 'スクロールの関数定義が無い');

        // ⚠ **タグが実行されるかまで見る。** 文字列の存在・順序だけでは、
        //    `<script type="text/template">` にするだけでスクロールが丸ごと不活性になる変異が
        //    素通りする（2026-09-04 実測でフルスイート 1315 本が緑だった）。
        //    ⚠ 2026-08-31 の周辺ビル調査の改修で名指しして塞いだのと同一の型（BACKLOG）。
        $this->assertSame(
            1,
            preg_match('/<script([^>]*)>\s*function scheduleBoardSetInitialScroll/', $html, $tag),
            'スクロールの関数が <script> の直下に無い'
        );
        $this->assertSame('', $tag[1], 'script タグに属性が付いている（type="module" / "text/template" だと実行されない）');

        $this->assertGreaterThan(
            $scrollerPos,
            $scriptPos,
            'スクロールのスクリプトがスクローラーより前に出ている（@push の宛先が scripts でない可能性）'
        );
    }

    /**
     * ⚠ **今日が軸の外でも必ずスクロールする**（設計書 §12.4）。**旧実装から挙動が変わった。**
     *   従来（D9）は `todayPct` が null になるのでスクリプトごと出さず、
     *   一番古い月が左端に残っていた。D15 は 0 / 100 にクランプした値を必ず渡すので、
     *   **完了済みの案件では一番新しい月（右端）が見える。**
     *
     * ⚠ `initialPct` は 0〜100 にクランプ済みで **null にならない**ので、
     *   `scrollLeft = NaN` を避けるための分岐（§7.1 の理由）は不要になった。
     *   **理由が消えた分岐を残さない。**
     */
    public function test_the_board_still_scrolls_when_today_is_outside_the_axis(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $html     = $response->getContent();
        $axis     = $response->viewData('board')['axis'];

        $this->assertNull($axis['todayPct'], 'このフィクスチャでは今日が軸の外のはず');
        $this->assertSame(100.0, $axis['initialPct'], '軸より後の今日が右端（100）にクランプされていない');

        // ⚠ **「スクリプトが出ないこと」を見る旧テストから逆転している。**
        $this->assertStringContainsString('function scheduleBoardSetInitialScroll(id, pct, trackPx)', $html);
        $this->assertStringContainsString(
            "scheduleBoardSetInitialScroll('schedule-board-scroller', 100, {$axis['trackWidthPx']});",
            $html,
            '今日が軸の外でもクランプ値でスクロールするはず'
        );
    }
```

⚠ 期待値の裏付け（2026-09-04 実測）: このフィクスチャは軸 2026-05-01〜2026-09-30 /
`trackWidthPx = 750` / `initialPct = 39.869281045752`（前月 2026-08-01）/
`todayPct = 79.738562091503`（2026-08-31）。**両者が違う値**なので
「今日基準へ戻す」変異を検出できる。
（`assertNotEqualsWithDelta` は本 worktree の **PHPUnit 11.5.55** に存在することを確認済み。）

同ファイル 401 行の needle を更新する:

```php
        $this->assertStringNotContainsString('scheduleBoardSetInitialScroll(', $html, 'rows が空なのにスクロールのスクリプトが出ている');
```

`tests/Feature/Schedule/ScheduleCardAxisTest.php` の 232 行も更新する:

```php
        $this->assertStringNotContainsString('scheduleBoardSetInitialScroll', $html);
```

- [ ] **Step 2: 失敗することを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit \
  --filter 'test_the_board_opens_scrolled_to_the_first_day_of_last_month|test_the_board_still_scrolls_when_today_is_outside_the_axis'
```

期待: 2 本とも FAIL
（1 本目は `contains "function scheduleBoardSetInitialScroll(id, pct, trackPx)"` の不一致、
2 本目も同じ理由。⚠ **「赤になった」だけでなく理由の文言まで確認する**）

- [ ] **Step 3: 実装する**

`resources/views/_partials/_schedule_board.blade.php` の **123 行から 145 行**
（`@if($axis['todayPct'] !== null)` から対応する `@endif` まで）を差し替える。
**146 行の `@endif`（`@if($board['rows'] === [])` の対）は残す。**

**変更前**:

```blade
    @if($axis['todayPct'] !== null)
        {{-- 開いた直後に今日が見える位置まで横スクロールしておく（設計書 §7.1）。
             …（略）…
             ⚠ ラベル欄の幅は画面幅で変わるので CSS 変数から実行時に読む。 --}}
        @push('scripts')
            <script>
                function scheduleBoardScrollToToday(id, pct, trackPx) {
                    var el = document.getElementById(id);
                    if (! el) { return; }
                    var labelW = parseFloat(getComputedStyle(el).getPropertyValue('--gantt-label-w')) || 0;
                    el.scrollLeft = Math.max(0, trackPx * pct / 100 - (el.clientWidth - labelW) / 2);
                }
                scheduleBoardScrollToToday('schedule-board-scroller', {{ $axis['todayPct'] }}, {{ $axis['trackWidthPx'] }});
            </script>
        @endpush
    @endif
@endif
```

**変更後**（`@if` が 1 段減るのでインデントも 4 つ戻す）:

```blade
    {{-- 開いた直後に「今日の前月の 1 日」が軸の左端に来る位置まで横スクロールしておく
         （設計書 §2 D15 / §12.4）。

         ⚠ **アロー関数を属性にも <script> にも書かない。** Blade の属性内では
            `=>` の `>` が HTML 終了タグとして解釈される（Top trap #4）。
            x-init ではなく名前付き関数にしているのはこのため。

         ⚠ **位置(%) は PHP が出す。** ここが計算するのはスクロール量だけで、
            日付 → % の計算は持たない（Bug #41 の二重実装を避ける）。

         ⚠ **`--gantt-label-w` は読まない。** 案件名の列は position: sticky; left: 0 なので、
            scrollLeft = S のとき軸の見えている範囲は月エリア座標で
            [S, S + (clientWidth − labelW)] ＝ **左端はちょうど S**。
            左端に置くだけなら引き算そのものが要らない（設計書 §12.4）。
            旧実装（D9）は今日を「見えている幅の中央」に置くために引いていた。

         ⚠ **今日が軸の外でも必ずスクロールする。** initialPct は 0〜100 に
            クランプ済みで null にならないので、`pct` の null 分岐を作らない。
            §7.1 が挙げた「null だと scrollLeft = NaN」という理由は D15 で消えている。

         ⚠ **pct = 100 は trackPx をそのまま代入する（意図的な過大値）。** ブラウザが
            scrollWidth − clientWidth にクランプするので右端で止まる（設計書 §12.4 の表）。
            Math.min で自前に丸め直さない —— 丸めるなら labelW を引く誘惑が生まれるが、
            それは中央寄せの式（旧 D9）へ戻る道である。

         ⚠ **`if (! el) { return; }` は失敗を無音にする**（@push の宛先を押し間違えたときの
            TypeError を握り潰す。Bug #48）。**スクローラーより後ろに出ることを見る位置比較の
            テストと対で成立している**ので、片方だけ消さないこと。
            ⚠ `pct` は素通しでよい —— サーバ側が 0〜100 にクランプ済みで、仮に異常値が来ても
               scrollLeft のセッタが非有限値を 0 に正規化し範囲外をクランプするため、
               どの入力でも「スクロールしない」に縮退するだけ（例外は起きない）。 --}}
    @push('scripts')
        <script>
            function scheduleBoardSetInitialScroll(id, pct, trackPx) {
                var el = document.getElementById(id);
                if (! el) { return; }
                el.scrollLeft = trackPx * pct / 100;
            }
            scheduleBoardSetInitialScroll('schedule-board-scroller', {{ $axis['initialPct'] }}, {{ $axis['trackWidthPx'] }});
        </script>
    @endpush
@endif
```

- [ ] **Step 4: 通ることを確認する**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter 'Schedule'
```

期待: PASS。⚠ 旧名 `scheduleBoardScrollToToday` がアプリとテストから消えたことも見る:

```bash
grep -rn "scheduleBoardScrollToToday" app resources tests
```

期待: **0 件**（`docs/` にある過去の記録はヒットしてよい。上のコマンドは `docs/` を見ない）

- [ ] **Step 5: コミットする**

```bash
git add resources/views/_partials/_schedule_board.blade.php \
        tests/Feature/Schedule/ScheduleBoardTest.php tests/Feature/Schedule/ScheduleCardAxisTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程表ボードの初期表示を今日の前月からにする

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 7: 変異テスト（27 通り）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

⚠ **「テストが緑」は検証にならない。** 変異を入れて赤になることを実測して初めて、
テストが load-bearing だと言える。

### 7.1 作法（Bug #44。この順序を守らないと測定そのものが無効になる）

1. **先にコミットする**（Task 6 まで済んでいること）
2. 各変異の**前**に `git status --porcelain` が**空**であることを確認する
   （前の変異の残骸が測定を汚す）
3. 変異を当てたら `git diff --stat` が**非空**であることを確認する
   ⚠ **着弾していない変異を「検出しない」と誤読する事故が実際に起きている**
   （シェルのエスケープが合わず 0 箇所置換なのに成功表示が出た。Bug #55）
4. テストを回す
5. **赤/緑でなく、落ちた理由の文言まで**突き合わせる
   ⚠ 意図と別の機構が落としているのに「検出できた」と誤読する事故が実際に起きている
6. `git checkout -- <当該ファイル>` で戻し、再度 `git status --porcelain` が空

⚠ **同じ文字列がファイル内に複数あるときの一括置換に注意**（Bug #44）。狙う行を明示すること。

⚠ **変異は「検査対象に入るはずの場所」へ当てる**（Bug #44 の 2026-08-17 追記）。
**ボードとカードの両方に当てる** —— 片方だけ測ると、もう片方に当てたとき
「守られている」と誤読する（設計書 §12.6）。

⚠ **2026-09-04 に実際に起きた事故 —— 手順 6 の「戻したことを自分の目で確認する」を省くと、
次の変異の測定が丸ごと無効になる。** Task 7 に着手する直前、作業ツリーに
`_schedule_gantt.blade.php` へ足した `<style>.gantt-year { … }</style>` の 1 行（= M10 の変異）が
**残ったまま**になっていた。直前のレビューエージェントは「`git checkout --` で戻して
`git status --porcelain` が空であることを確認した」と報告していたが、実際には残っていた
（報告と実態が食い違っていた）。**これは Bug #44 が名指ししている「前の変異の残骸が測定を汚す」
状態そのもの**で、そのまま次の変異を当てれば赤にも緑にも化ける。
**対策は「戻したつもり」を信用しないこと** —— 手順 6 の `git status --porcelain` を
**毎回コマンドとして実行し、出力が空であることを目で見る**（1 行でも出たら止まって報告する）。
Task 7 ではこれをシェルの定型に組み込み、`git checkout -- .` の**直後**に再確認して
空でなければ非ゼロ終了する形にした（27 + 6 回の測定すべてで空を確認済み）。

コマンド:

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
git status --porcelain            # 空
# …変異を当てる…
git diff --stat                   # 非空（着弾確認）
./vendor/bin/phpunit 2>&1 | tail -30
git checkout -- <file>
git status --porcelain            # 空
```

### 7.2 当てる変異

| # | 変異 | ファイル | 期待する守り手 |
|---|---|---|---|
| M1 | `headers()` の `'year' => …` 行を削除 | `ScheduleBoardService.php` | `test_the_axis_headers_carry_the_year_of_every_month` |
| M2 | `'year' => $cursor->format('Y')` を `'year' => '2026'` に固定 | `ScheduleBoardService.php` | 同上（2025 の 7 件が落ちる） |
| M3 | `initialScrollPct()` を `$today->subMonth()->startOfMonth()` の順に | `ScheduleBoardService.php` | `test_the_initial_scroll_puts_the_first_day_of_last_month_at_the_left_edge` |
| M4 | `initialScrollPct()` の `GanttScale::clamp(…)` を外して素の `left()` に | `ScheduleBoardService.php` | クランプ 2 本（0 / 100） |
| M5 | `initialScrollPct()` を `$scale->left($today)`（今日基準）に | `ScheduleBoardService.php` | 前月テスト ＋ `test_the_board_opens_scrolled_to_the_first_day_of_last_month` |
| M6 | `initialScrollPct()` を `subMonths(2)` に（前々月） | `ScheduleBoardService.php` | 前月テスト |
| M7 | `axis` から `'initialPct' => …` の行を削除 | `ScheduleBoardService.php` | `initialPct` を見る全テスト（`Undefined array key`） |
| M8 | style partial から `.gantt-year` の宣言行を削除 | `_schedule_gantt_style.blade.php` | `..._declared_exactly_once_on_the_board` ＋ `..._on_the_card`（**2 本とも赤になること**） |
| M9 | `.gantt-year` から `margin-right: 3px;` を削除 | `_schedule_gantt_style.blade.php` | 同上（正規表現が不一致） |
| M10 | `.gantt-year` の宣言を**カードの Blade へインライン複製**（partial の定義は残す） | `_schedule_gantt.blade.php` | `..._declared_exactly_once_on_the_card`（件数 2） |
| M11 | ボードの月セルから `<span class="gantt-year">…</span>` を削除 | `_schedule_board.blade.php` | `test_every_month_cell_on_the_board_shows_its_year_before_the_month` |
| M12 | ボードの年 span を月名の**後ろ**へ移動 | `_schedule_board.blade.php` | 同上（隣接チェック） |
| M13 | ボードの年 span と月名の**間に改行を入れる** | `_schedule_board.blade.php` | 同上（隣接チェック） |
| M14 | ボードの年 span を `class="gantt-year"` からインライン style に | `_schedule_board.blade.php` | 同上（件数 0） |
| M15 | カードの月セルから年 span を削除 | `_schedule_gantt.blade.php` | `test_the_card_month_cells_are_a_single_line_with_the_shared_year_class` |
| M16 | カードの月セルに `flex-direction: column;` を戻す | `_schedule_gantt.blade.php` | 同上（2 段チェック） |
| M17 | スクロール関数の**定義だけ**消す（呼び出しは残す） | `_schedule_board.blade.php` | `test_the_board_opens_scrolled_to_the_first_day_of_last_month` |
| M18 | スクロール関数の**呼び出しだけ**消す（定義は残す） | `_schedule_board.blade.php` | 同上 |
| M19 | `@push('scripts')` を `@push('styles')` に | `_schedule_board.blade.php` | 同上（**位置**の比較。⚠ 文字列の存在では検出できない。Bug #28） |
| M20 | 呼び出しの `{{ $axis['initialPct'] }}` を `{{ $axis['todayPct'] }}` に | `_schedule_board.blade.php` | 同上（引数の実値が一致しない） |
| M21 | JS の式を `trackPx * pct / 100` から `trackPx * pct` に | `_schedule_board.blade.php` | 同上（式の文字列） |
| M22 | **カード**の `{{ $m['year'] }}` を literal `2026` に固定 | `_schedule_gantt.blade.php` | `test_the_card_month_cells_are_a_single_line_with_the_shared_year_class`（⚠ **カードの軸が単年だと素通りする。2026-09-04 実測でフルスイート 1315 本が緑だった**） |
| M23 | カードの `$m['quarterStart']` の三項演算子を削除（四半期の `border-left` が消える） | `_schedule_gantt.blade.php` | ⚠ **2026-09-04 実測で未検出**（§12.7 が対象外と宣言した領域。守り手を足すかどうかを Task 7 で判断する） |
| M24 | ボードの `$h['strong']` の三項を `false` に潰す（四半期の濃い罫線が消える） | `_schedule_board.blade.php` | ⚠ **2026-09-04 実測で未検出**（同上） |
| M25 | 月セルの `justify-content: center` を `flex-start` に（ボード / カードの両方） | 両 Blade | `..._shows_its_year_before_the_month` / `..._single_line_with_the_shared_year_class`（Fix 3 で足した肯定的な固定） |
| M26 | `<script>` を `<script type="text/template">` に（**内容は変わらず実行だけ止まる**） | `_schedule_board.blade.php` | `test_the_board_opens_scrolled_to_the_first_day_of_last_month`（⚠ **2026-09-04 実測では当初フルスイート 1315 本すべて緑だった**。Fix で塞いだ。2026-08-31 の POI 改修で名指しした型と同一） |
| M27 | 呼び出しの `{{ $axis['initialPct'] }}` を `{{ $axis['initialPct'] ?: 100 }}` に（**0 の経路だけ壊す**） | `_schedule_board.blade.php` | `test_the_initial_scroll_clamps_to_the_left_edge_when_today_is_before_the_axis`（⚠ **当初 216 本すべて緑**。0 側だけ描画層のアサートが無かった） |

- [x] **Step 1: M1〜M27 を 1 つずつ当てて、赤になることと落ちた理由を記録する**

- [x] **Step 2: 検出されなかった変異があればテストを足す**

⚠ **穴が見つかったら「テストを足してから赤になること」まで確認する。**
前回（2026-09-03）は 46 通り中 21 通りが**当初は検出漏れ**で、そのすべてがテスト設計の欠落だった。
検出漏れがゼロなら、それ自体が疑わしい（測り方が甘い可能性）ので手順 7.1 を見直す。

- [x] **Step 3: 実測結果をこのプランに書き足す**

下の「### 7.3 変異テストの実測結果」に、**検出 / 当初検出漏れ→追加で検出 / 等価変異**を
区別して書く。表の列は `# / 変異 / ファイル / 着弾(git diff) / 落ちた本数 / 守り手 / 落ちた理由の文言 / 判定`。

- [x] **Step 4: コミットする**（テストを足した場合のみ）

```bash
git add tests/ docs/superpowers/plans/2026-09-04-schedule-board-year-header.md
git commit -m "$(cat <<'MSG'
test(schedule): 軸ヘッダの年と初期スクロールの変異テストの穴を塞ぐ

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

### 7.3 変異テストの実測結果

**2026-09-04 に M1〜M27（M25 はボード / カードへ別々に当てたので実質 28 通り）＋
追加で見つけた 2 通り＝計 30 通りを実測した。**

**ベースライン**（変異を当てる前・`c365d50e` の clean な木で実測）:

| 測り方 | 結果 |
|---|---|
| `./vendor/bin/phpunit --filter 'Schedule'` | **OK (216 tests, 1781 assertions)** |
| `./vendor/bin/phpunit`（フルスイート） | **OK (1315 tests, 8639 assertions)** |

⚠ **緑になった変異はフルスイートで測り直した**（`--filter 'Schedule'` の 216 本の外に
守り手がいる可能性を潰すため）。赤は `--filter 'Schedule'` の結果で判定している。

#### 結果（30 通り）

| # | 変異 | ファイル | 着弾(git diff) | 落ちた本数 | 守り手 | 落ちた理由の文言 | 判定 |
|---|---|---|---|---|---|---|---|
| M1 | `'year' => …` の行を削除 | `ScheduleBoardService.php:462` | 1 deletion | **40** | `..._carry_the_year_of_every_month` ほか（ボードを描く全テスト） | `ErrorException: Undefined array key "year"`（compiled view :54）→ 500 | 検出 |
| M2 | `'year' => '2026'` に固定 | 同上 `:462` | 1 ins / 1 del | 2 | `..._carry_the_year_of_every_month` ＋ `..._shows_its_year_before_the_month` | 「月ごとの年が出ていない（6月 / 7月 が 2 回ずつ出るのに区別できない）」／「2025 年の 6月 が出ていない（順序が逆か、間に要素がある）」 | 検出 |
| M3 | `subMonth()->startOfMonth()` の逆順 | 同上 `:154` | 1 ins / 1 del | 1 | `..._puts_the_first_day_of_last_month_at_the_left_edge` | 「初期スクロールが「前月の 1 日」でない…」`Failed asserting that 39.0728476821192 matches expected 20.529801324503` | 検出（§0.4 の予測値と一致） |
| M4 | `GanttScale::clamp(…)` を外す | 同上 `:154` | 1 ins / 1 del | 3 | `..._clamps_to_the_right_edge` / `..._clamps_to_the_left_edge` / `..._still_scrolls_when_today_is_outside_the_axis` | `583.8709677419355 is identical to 100.0` ／ `-100.0 is identical to 0.0` | 検出（0 / 100 の**両側**） |
| M5 | `$scale->left($today)`（今日基準）に | 同上 `:154` | 1 ins / 1 del | 2 | `..._puts_the_first_day_of_last_month…` ＋ `..._board_opens_scrolled_to_the_first_day_of_last_month` | `58.94039735099338 matches expected 20.529801324503` ／「initialPct が todayPct と同値になっている（実装が今日基準へ戻ったか…）」 | 検出 |
| M6 | `subMonths(2)`（前々月）に | 同上 `:154` | 1 ins / 1 del | 1 | `..._puts_the_first_day_of_last_month…` | `0.0 matches expected 20.529801324503` | 検出 |
| M7 | `'initialPct' => …` の行を削除 | 同上 `:133` | 1 deletion | **40** | `initialPct` を見る全テスト | `ErrorException: Undefined array key "initialPct"`（compiled view :121）→ 500 | 検出 |
| M8 | `.gantt-year` の宣言行を削除 | `_schedule_gantt_style.blade.php:39` | 1 deletion | 2 | `..._declared_exactly_once_on_the_board` ＋ `..._on_the_card` | 「年のスタイル宣言が 1 つでない（共有 partial 以外にも定義がある）」`actual size 0 matches expected size 1` | 検出（**ボード・カード 2 本とも**） |
| M9 | `margin-right: 3px;` を削除 | 同上 `:39` | 1 ins / 1 del | 2 | 同上 2 本 | 「年と月名の間隔が D13 と違う」`' font-size: 9.5px; color: #9CA3AF; ' matches PCRE pattern "/margin-right:\s*3px;/"` | 検出 |
| M10 | `.gantt-year` をカードの Blade へインライン複製 | `_schedule_gantt.blade.php:33`（挿入） | 1 insertion | 1 | `..._declared_exactly_once_on_the_card` | `actual size 2 matches expected size 1` | 検出（件数 2） |
| M11 | ボードの年 span を削除 | `_schedule_board.blade.php:72` | 1 ins / 1 del | 1 | `..._shows_its_year_before_the_month` | 「月セルの年 span の形が変わっている…」`0 is identical to 14` | 検出 |
| M12 | ボードの年 span を月名の後ろへ | 同上 `:72` | 1 ins / 1 del | 1 | 同上 | 「2025 年の 6月 が出ていない（順序が逆か、間に要素がある）」 | 検出（隣接チェック） |
| M13 | 年 span と月名の間に改行 | 同上 `:72` | 2 ins / 1 del | 1 | 同上 | 同上 | 検出（⚠ 付録 B #13 のとおり**見た目は変わらない**。落ちるのは隣接チェックだけ） |
| M14 | `class="gantt-year"` をインライン style に | 同上 `:72` | 1 ins / 1 del | 1 | 同上 | `0 is identical to 14` | 検出 |
| M15 | カードの年 span を削除 | `_schedule_gantt.blade.php:52` | 1 ins / 1 del | 1 | `..._single_line_with_the_shared_year_class` | `0 is identical to 4` | 検出 |
| M16 | カードに `flex-direction: column;` を戻す | 同上 `:52` | 1 ins / 1 del | 1 | 同上 | 「カードの月セルが 2 段のまま」 | 検出 |
| M17 | スクロール関数の**定義だけ**削除 | `_schedule_board.blade.php:164-168` | 5 deletions | 2 | `..._board_opens_scrolled…` ＋ `..._still_scrolls_when_today_is_outside_the_axis` | `assertStringContainsString('function scheduleBoardSetInitialScroll(id, pct, trackPx)')` の不一致（メッセージ無しの素の失敗） | 検出 |
| M18 | スクロール関数の**呼び出しだけ**削除 | 同上 `:169` | 1 deletion | 3 | 上記 2 本 ＋ `..._clamps_to_the_left_edge…` | 「今日が軸より前でも左端（0）でスクロール指示が出るはず」／「今日が軸の外でもクランプ値でスクロールするはず」 | 検出 |
| M19 | `@push('scripts')` → `@push('styles')` | 同上 `:162` | 1 ins / 1 del | 1 | `..._board_opens_scrolled…` | 「スクロールのスクリプトがスクローラーより前に出ている（@push の宛先が scripts でない可能性）」`Failed asserting that 2543 is greater than 27146` | 検出（⚠ **位置の比較だけが捕まえた**。文字列の存在は全部残っている。Bug #28） |
| M20 | 呼び出しの引数を `$axis['todayPct']` に | 同上 `:169` | 1 ins / 1 del | 3 | `..._clamps_to_the_left_edge…` / `..._board_opens_scrolled…` / `..._still_scrolls…` | 「今日が軸より前でも左端（0）でスクロール指示が出るはず」ほか | 検出 |
| M21 | JS の式から `/ 100` を落とす | 同上 `:167` | 1 ins / 1 del | 1 | `..._board_opens_scrolled…` | 「スクロール量の式が変わっている」 | 検出 |
| M22 | **カード**の `{{ $m['year'] }}` を literal `2026` に | `_schedule_gantt.blade.php:52` | 1 ins / 1 del | 1 | `..._single_line_with_the_shared_year_class` | 「年 → 月名の順で 1 行になっていない」 | 検出（**Task 5 `1b738ba7` の年またぎフィクスチャが効いている**。付録 B #15） |
| M23 | カードの `$m['quarterStart']` の三項を削除 | 同上 `:52` | 1 ins / 1 del | **0 → 1** | （当初なし）→ `..._single_line_with_the_shared_year_class` | （当初: **フルスイート 1315 本すべて緑**）→「3 番目の月セル（20261月）に四半期の罫線が無い」 | **当初検出漏れ → 追加で検出** |
| M24 | ボードの `$h['strong']` の三項を `#E5E7EB` に潰す | `_schedule_board.blade.php:72` | 1 ins / 1 del | **0 → 1** | （当初なし）→ `..._shows_its_year_before_the_month` | （当初: **フルスイート 1315 本すべて緑**）→「2 番目の月セル（20257月）の四半期罫線が headers() の strong と食い違っている」 | **当初検出漏れ → 追加で検出** |
| M25a | **ボード**の `justify-content: center` → `flex-start` | 同上 `:72` | 1 ins / 1 del | 1 | `..._shows_its_year_before_the_month` | 「月セルの中央揃えが崩れている」 | 検出 |
| M25b | **カード**の `justify-content: center` → `flex-start` | `_schedule_gantt.blade.php:52` | 1 ins / 1 del | 1 | `..._single_line_with_the_shared_year_class` | 「月セルの中央揃えが崩れている」 | 検出 |
| M26 | `<script>` → `<script type="text/template">` | `_schedule_board.blade.php:163` | 1 ins / 1 del | 1 | `..._board_opens_scrolled…` | 「script タグに属性が付いている（type="module" / "text/template" だと実行されない）」 | 検出（**Task 6 `c365d50e` の穴埋めが効いている**。付録 B #16） |
| M27 | 呼び出しを `{{ $axis['initialPct'] ?: 100 }}` に（0 の経路だけ壊す） | 同上 `:169` | 1 ins / 1 del | 1 | `..._clamps_to_the_left_edge_when_today_is_before_the_axis` | 「今日が軸より前でも左端（0）でスクロール指示が出るはず」 | 検出（**Task 6 の穴埋めが効いている**） |
| **X1** | ボードの `width: {{ $h['widthPct'] }}%` を `width: 10%` に | `_schedule_board.blade.php:72` | 1 ins / 1 del | **0 → 1** | （当初なし）→ `..._shows_its_year_before_the_month` | （当初: **フルスイート 1315 本すべて緑**）→「1 番目の月セルの幅が headers() の widthPct と食い違っている」 | **表に無かった穴。追加で発見 → 検出** |
| **X2** | カードの `width: {{ $m['widthPct'] }}%` を `width: 10%` に | `_schedule_gantt.blade.php:52` | 1 ins / 1 del | **0 → 1** | （当初なし）→ `..._single_line_with_the_shared_year_class` | （当初: **フルスイート 1315 本すべて緑**）→「1 番目の月セルの幅が months() の widthPct と食い違っている」 | **表に無かった穴。追加で発見 → 検出** |

**内訳: 検出 26 通り / 当初検出漏れ→追加で検出 4 通り（M23 / M24 / X1 / X2）/ 等価変異 0 通り。**

#### 当初検出漏れの正体（4 通りとも同じ型）

**サービス側の値は固定されているのに、Blade がその値を使っているかを誰も見ていなかった** ——
Bug #47 の「配線の半分しか押さえていない」型そのもの。

- `test_the_axis_headers_are_month_labels_with_quarter_emphasis` は `headers()` の
  **`strong` / `widthPct` の配列**を見ている。`ScheduleCardService::months()` の
  `quarterStart` も同様。**どちらも「Blade がその値を出しているか」は一度も見ていなかった。**
- ⚠ これは **2026-09-03 の振り返りが「`headers()` の出力 3 フィールド（label / strong / widthPct）も
  すべて無防備だった」と名指しした穴の残り**である。当時 `label` だけが
  （年の隣接チェック `<span class="gantt-year">2025</span>6月` によって）塞がり、
  **`strong` と `widthPct` は塞がらないまま残っていた。**
- ⚠ **X1 / X2 は 7.2 の表に無い。** 表の 27 通りを終えた時点で検出漏れが M23 / M24 の 2 件しか
  無く、「測り方が甘いのではないか」と疑って**同じ行の隣接する不変条件**を追加で当てて見つけた。
  **表を消化しただけで終えないこと。**

#### 塞いだ穴（テストを足した箇所）

新規テストファイルは作らず、**既に月セルの style を切り出しているループの中**へ追加した
（`preg_match_all('/<div style="([^"]*)"><span class="gantt-year">/', …)` の結果を
サービス側の配列と**添字で突き合わせる**形）。

| ファイル | 足したもの |
|---|---|
| `tests/Feature/Schedule/ScheduleBoardTest.php`<br>`test_every_month_cell_on_the_board_shows_its_year_before_the_month` | ①`$headers[$i]['strong']` に応じた `border-right: 1px solid #D1D5DB;` / `#E5E7EB;` の突き合わせ ②`width: {$headers[$i]['widthPct']}%;` の突き合わせ ③**空振り防止**（`strong` に true / false が両方在ること・`widthPct` が 2 種類以上在ること） |
| `tests/Feature/Schedule/ScheduleCardAxisTest.php`<br>`test_the_card_month_cells_are_a_single_line_with_the_shared_year_class` | ①`$months[$i]['quarterStart']` が true なら `border-left: 1px solid #D1D5DB;` が在り、false なら `border-left` が**無い**こと ②`width: {$months[$i]['widthPct']}%;` の突き合わせ ③同じ空振り防止 |

⚠ **セルごとに切り出した style の中だけで見る。** ページ全体で `#D1D5DB` を探すと
共有 CSS（`.gantt-label--head` 等）や他のセルに一致して false-pass する（Bug #43）。

⚠ **空振り防止を対で置く**（Bug #45）。フィクスチャの軸が「全部四半期」/「全部同じ幅」に
変わると、突き合わせのループが**片側しか測っていないのに緑**になる。

⚠ **四半期強調の非対称は揃えない**（設計書 §12.7）。ボードは `border-right` /
カードは `border-left` に `#D1D5DB` を置いている。**揃えるのではなく両方を別々に守る。**

#### 事後のテスト本数

| 測り方 | ベースライン | Task 7 後 |
|---|---|---|
| `--filter 'Schedule'` | 216 tests / 1781 assertions | **216 tests / 1823 assertions** |
| フルスイート | 1315 tests / 8639 assertions | **1315 tests / 8681 assertions** |

**テストの本数は増えていない**（既存 2 本のループにアサートを足したので +42 assertions のみ）。

---

## Task 8: 全体検証（テスト / コンパイル済みビューの lint / 実ブラウザ）

**Files:** なし（測るだけ）

- [ ] **Step 1: フルスイート**

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit 2>&1 | tail -5
```

期待: `OK (1317+ tests, …)`。ベースラインは **1307 tests / 8521 assertions**（新規 11 本を足すので 1318 前後）。
⚠ **失敗が 1 本でもあれば先へ進まない。**

- [ ] **Step 2: コンパイル済みビューを `php -l` する**

⚠ **`view:cache` の成功表示（`Blade templates cached successfully.`）では足りない**
（コンパイル済み PHP を lint しないため。Bug #21 / #26 / #30）。

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" php artisan view:cache \
  && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done \
  && ls storage/framework/views/*.php | wc -l \
  && APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" php artisan view:clear
```

期待: `INVALID:` が **0 行**。ビューの本数（前回 267 本）を記録する。

- [ ] **Step 3: 使い捨て SQLite ＋ 開発サーバを立てる**

⚠ **`preview_start` を使わない。** 実測（2026-09-03）で、サブエージェントの `preview_start` は
**main repo の `.claude/launch.json`** を解決し、**main repo の実 `.env`（実 MySQL）へ
接続する 500 画面**が出た。`EnterWorktree` も「cwd が worktree の外」で拒否される。
**`php artisan serve` を Bash の `run_in_background` で worktree 内・環境変数直渡しで起動し、
`mcp__Claude_Browser__navigate` で接続する。**

⚠ **`.env` は作らない。** 本プロジェクトの安全装置でハードブロックされる（`ls -la .env` すら拒否）。
環境変数だけで起動する。

⚠ **worktree に `public/build` が無いので初回は Vite manifest 未検出の 500**。
main repo の vite を cwd=worktree で走らせて解決する（検証後に `public/build` は消す）。

⚠ **`User::create([... 'role' => 'executive' ...])` は効かない。**
`role` / `status` は `$fillable` から外れており黙って捨てられて staff になり `/housing/*` が 403 になる。
**作成後に明示代入する。**

⚠ **`storage/` は gitignore されていない。** 置いた sqlite / php は**あとで必ず消す。**

`storage/verify-seed.php` を作る:

```php
<?php
// 使い捨ての検証用データ。⚠ 実装コードではない。確認が終わったら storage/ ごと消す。
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::call('migrate', ['--force' => true]);

// ⚠ re_* / hs_* / schedule_steps はテスト用 trait が DDL の正本
$trait = new class { use Tests\Concerns\CreatesRealEstateSchema; public function run() { $this->createRealEstateSchema(); } };
$trait->run();

Artisan::call('db:seed', ['--class' => 'DepartmentSeeder', '--force' => true]);

$u = User::create([
    'name' => '検証用', 'email' => 'verify@example.test',
    'password' => bcrypt('password'), 'must_change_password' => false,
]);
// ⚠ role / status は fillable でないので create() では入らない
$u->role = 'executive';
$u->status = 'active';
$u->save();
foreach (['realestate', 'housing'] as $code) {
    $u->departments()->attach(App\Models\Department::where('code', $code)->value('id'));
}

// ---- 本番と同じ形の建売 1 件（8 ヶ月・1 日の工程を多数含む） ----
$prop = HsProperty::create([
    'property_code' => 'HS-008', 'property_name' => 'JG西長戸4号地',
    'status' => 'construction', 'address' => '愛媛県松山市西長戸町',
    'construction_start_date' => '2026-02-19', 'scheduled_completion_date' => '2026-09-27',
    'created_by' => $u->id,
]);
$steps = [
    ['仮設工事 / 仮設水道',        'work',  '2026-02-19', '2026-02-20'],
    ['仮設工事 / 仮囲・仮設トイレ', 'work',  '2026-02-21', '2026-02-21'],
    ['地盤改良 / 柱状改良',        'other', '2026-03-09', '2026-03-10'],
    ['基礎工事 / 基礎工事',        'work',  '2026-04-22', '2026-05-28'],
    ['基礎工事 / JIO検査',         'work',  '2026-05-02', '2026-05-02'],
    ['検査 / 配筋検査',            'permit','2026-05-02', '2026-05-02'],
    ['大工工事 / 上棟',            'work',  '2026-06-05', '2026-06-05'],
    ['大工工事 / 造作',            'work',  '2026-06-06', '2026-08-05'],
    ['外壁工事 / サイディング工事', 'work',  '2026-06-19', '2026-07-13'],
    ['設備工事 / キッチン取付',     'work',  '2026-08-28', '2026-08-28'],
    ['美装工事 / 美装工事',        'work',  '2026-09-03', '2026-09-04'],
    ['検査 / 竣工検査',            'permit','2026-09-07', '2026-09-07'],
    ['外構工事 / 外構工事',        'work',  '2026-09-08', '2026-09-27'],
];
foreach ($steps as $i => [$name, $cat, $from, $to]) {
    $prop->scheduleSteps()->create([
        'name' => $name, 'category' => $cat,
        'planned_start' => $from, 'planned_end' => $to, 'sort_order' => $i + 1,
    ]);
}

// ---- ⚠ **年をまたぐ案件を必ず 1 件入れる。** これが無いと今回の改修の主目的
//         （同じ月名が 2 回出る軸で年が読めること）を画面で一度も確認できない。
$proc = ReProcurement::create([
    'procurement_code' => 'PRC-001', 'property_type' => 'used_house',
    'transaction_type' => 'purchase', 'status' => 'contracted',
    'property_name' => '井門町 更地（年またぎ）', 'address' => '愛媛県松山市', 'created_by' => $u->id,
]);
$proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2025-06-15', 'planned_end' => '2025-12-20', 'sort_order' => 1]);
$proc->scheduleSteps()->create(['name' => '造成', 'category' => 'work',   'planned_start' => '2026-01-10', 'planned_end' => '2026-07-10', 'sort_order' => 2]);

echo "seeded: property={$prop->id} procurement={$proc->id} user={$u->email} / password\n";
```

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export DB_CONNECTION=sqlite
export DB_DATABASE="$(pwd)/storage/verify.sqlite"
: > "$DB_DATABASE"
php storage/verify-seed.php
/Users/masanori/site/manage/node_modules/.bin/vite build      # public/build を作る
lsof -nP -iTCP:8123 -sTCP:LISTEN                              # 空いていること
```

サーバは **Bash の `run_in_background`** で:

```bash
APP_KEY="$APP_KEY" DB_CONNECTION=sqlite DB_DATABASE="$DB_DATABASE" \
APP_ENV=local APP_DEBUG=true APP_URL=http://localhost:8123 \
php artisan serve --port=8123
```

- [ ] **Step 4: 画面で 6 点を実測する**

⚠ **テストが原理的に測れない領域だけを見る。** HTML に出ているかはテストが見ている。

| # | 見るもの | 判定 |
|---|---|---|
| 1 | 不動産ボード（年またぎ 14 ヶ月）で **`2025 6月` … `2026 6月`** が読めること | 2 つの 6月 が区別できる |
| 2 | 月ヘッダの高さが **42px のまま**・年と月名が **1 行** | `getBoundingClientRect().height === 42` |
| 3 | **開いた直後の `scrollLeft`** が「前月の 1 日」の位置 | `document.getElementById('schedule-board-scroller').scrollLeft` を実測し、`trackWidthPx * initialPct / 100` と一致 |
| 4 | **その位置で年が画面に見えている**（§12.3 の判定そのもの） | 左端の月セルの年 span が可視範囲に入る |
| 5 | 詳細カードの月ヘッダも **1 行**（2 段でない） | 建売物件の詳細を開いて目視 ＋ 月セルの `getComputedStyle().flexDirection === 'row'` |
| 6 | **`main.scrollWidth === main.clientWidth`** を **4 画面 × 1800 / 1200 / 375px = 12 通り** | Bug #29。⚠ 超過幅は一定なので**片方の幅だけでは判定できない** |

対象画面: `/realestate/schedules` / `/housing/schedules` / 建売物件の詳細 / 仕入れ案件の詳細

⚠ **375px も必ず測る。** ラベル欄が 140px になるので、年が入って月セルが広がると
真っ先に崩れるのがここ。

- [ ] **Step 5: コンソール出力が 0 件であることを確認する**

`mcp__Claude_Browser__read_console_messages` で 4 画面とも 0 件。

- [ ] **Step 6: 後始末**

```bash
kill %1                                    # artisan serve を止める
rm -f storage/verify.sqlite storage/verify-seed.php
rm -rf public/build
git status --porcelain                     # **空であること**
```

⚠ `.env` は最後まで一度も作らない。`ls -la .env` で不在を確認する。

- [ ] **Step 7: 実測結果をこのプランの「### 8.1 実ブラウザ確認の実測結果」に書く**

### 8.1 実ブラウザ確認の実測結果（2026-09-04 実施。`743e96f7`）

環境: 使い捨て SQLite ＋ `php artisan serve --port=8123`（Bash の `run_in_background`）＋
`mcp__Claude_Browser__navigate`。⚠ **`preview_start` は使っていない**（main repo の
`.claude/launch.json` を解決して実 MySQL へ到達する既知の事故）。`.env` は一度も作っていない。
検証後に `storage/verify*` と `public/build` を削除し `git status --porcelain` が空であることを確認済み。

#### ブラウザ不要の確認

| 見たこと | 結果 |
|---|---|
| フルスイート（clean な木で実測） | **`OK (1315 tests, 8681 assertions)`** |
| コンパイル済みビューの `php -l` | **267 本 / INVALID 0 件**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）|

#### D13 / D14 の意匠（`getComputedStyle` の実測。**ボードとカードで同一**）

| 見たもの | ボード | カード |
|---|---|---|
| 月セルの `flex-direction` | **`row`** | **`row`**（2 段でない ＝ D14）|
| `align-items` / `justify-content` | `center` / `center` | `center` / `center` |
| `overflow-x` | **`hidden`** | **`hidden`**（Bug #29 の床を消す）|
| 年の `font-size` | **`9.5px`** | **`9.5px`** |
| 年の `color` | **`rgb(156, 163, 175)`**（= `#9CA3AF`）| 同左 |
| 年の `margin-right` | **`3px`** | **`3px`** |
| ヘッダの高さ | **42px** | **42px**（セル高 41px。D13 の「42px・1 行のまま」）|

- **不動産ボード（年またぎ 14 ヶ月）**: `.gantt-year` が **14 個**、内訳 **2025 × 7 / 2026 × 7**。
  画面で `2025 12月` → **`2026 1月`** の切り替わりが読める ＝ **Codex Minor 4 の解消を目視で確認**
- **仕入れ案件の詳細カード**: `2025 5月` … と 1 行で出る。**実績開始・実績終了の列は残っている**
  （不動産は実績を持つ ＝ 住宅専用機能が漏れていない）
- **カードにスクロールのスクリプトは無い**（D11。`hasScrollScript: false` を両カードで実測）
- 375px で**ラベル欄 140px**（D6）

#### Bug #29（`main` の横スクロール）— **4 画面 × 1800 / 1200 / 375px = 12 通りすべて `scrollWidth === clientWidth`**

対象: `/realestate/schedules` / `/housing/schedules` / 仕入れ案件の詳細 / 建売物件の詳細。
⚠ 超過幅は一定なので**片方の幅だけでは判定できない**（Bug #29）。

#### D15 の初期スクロール — **式そのものは実測で正しい**

| 画面 / 幅 | 呼び出し | 実測 `scrollLeft` | 判定 |
|---|---|---|---|
| 住宅ボード / 375px | `(…, 74.793388429752, 1200)` | **897.5** | **`trackPx × pct / 100` と厳密に一致**（右端 999 に達していない＝クランプされていない）|
| 不動産ボード / 900px | `(…, 100, 2100)` | **1554 ＝ 右端** | `pct = 100` がブラウザのクランプで右端に止まる（§12.4 の表どおり）|
| 住宅ボード / 1800px | `(…, 74.793388429752, 1200)` | 0（`max = 6`）| **軸が画面に収まっており動く余地が無い**＝正常 |

#### ⚠ 未確定で残した 1 件（**実ブラウザでの再確認が要る**）

**幅 1024px 以上（サイドバーが出る幅）で、目標位置が右端に届く場合だけ、着地が 220px 手前になった。**

| 幅 | サイドバー | `scrollLeft` | 右端 | ズレ |
|---|---|---|---|---|
| 900px | 0px（非表示）| 1554 | 1554 | **0** |
| 1200px | 220px | 1286 | 1506 | **-220** |
| 1800px | 220px | 686 | 906 | **-220** |

ズレ幅は**サイドバーの 220px と完全一致**する。呼び出し時点の値を一時的に記録して測ると:

```
readyState: "loading" / document.hidden: true / innerWidth: 1800 / matchMedia('(min-width:1024px)'): true
styleSheets.length: 2 / aside の inline style: null / それでも getComputedStyle(aside).display === "none"
→ 直後には display: "flex" / 220px
```

**⚠ これは「隠れたペインでは Chrome がスタイルとレイアウトを遅延する」ためである可能性が高く、
この環境からは実ブラウザの挙動と区別できない。** 判断の材料:

- **エミュレーション幅のせいではない**（呼び出し時点で `innerWidth: 1800` / `mqLg: true` と実測）
- **Alpine のせいでもない**（`x-data` は `<body>` の `sidebarExpanded: true` で、
  呼び出し時点の aside に inline style は無い）
- `document.hidden` は最初から最後まで `true`。本リポジトリは
  「自動操作のタブは `document.hidden` で Google がタイルを描かない」を BACKLOG に繰り返し記録している
- ⚠ **この改修が持ち込んだものではない。** 旧実装（D9）は
  `Math.max(0, trackPx * pct / 100 - (el.clientWidth - labelW) / 2)` と**同じ瞬間に `clientWidth` を直接読んで**
  おり、同じ露出を持っていた（しかも中央寄せなので影響は常時）。新実装はブラウザのクランプに
  当たるときだけ露出する
- **式そのものは正しい**（上表のとおり、クランプに当たらない条件では厳密に一致）

**→ 本番反映後の目視（Task 9 の手順）で、`pct` が右端に当たる案件を 1 件開いて
`scrollLeft === scrollWidth - clientWidth` を確認すること。** そこでズレるなら
「レイアウト確定後にスクロールする」形（`requestAnimationFrame` 等）への変更を別途検討する。
⚠ **ここで先回りして直さない** —— 実ブラウザで再現しない可能性があり、
直せばテストが固定している式（`el.scrollLeft = trackPx * pct / 100;`）も変わるため。

#### コンソール

**対象 4 画面はいずれもエラー 0 件。**
⚠ `/dashboard/executive`（ログイン後のリダイレクト先）だけ **500** が出たが、これは
**検証用 seed が `ms_*` / `zeal_*` のテーブルを作っていない**ため（経営ダッシュボードは 5 事業を横断する）。
本改修とは無関係であることを、seed が作った 50 テーブルに `ms_properties` / `zeal_members` が
無いことを実測して確認済み。

---

## Task 9: ドキュメントを更新してマージ準備をする

**Files:**
- Modify: `docs/BACKLOG.md`（「工程表ボードのガントを読めるようにする」の節に追補を足す）

⚠ **`docs/` は `deploy.sh` の rsync 対象外**なので本番には行かない。記録のためだけに書く。

- [ ] **Step 1: BACKLOG に追補の節を足す**

`docs/BACKLOG.md` の「## ✅ 工程表ボードのガントを読めるようにする — 本番未反映」の節の
**末尾（`### ⚠ 本番反映の手順` の直前）** に足す:

```markdown
### 追補（2026-09-04）— 軸ヘッダの年表示と初期スクロール

詳細仕様: 設計書の **§12**（同じファイルに追補した。新しい設計書は作っていない）
実装計画: @docs/superpowers/plans/2026-09-04-schedule-board-year-header.md
モック: @docs/mockups/housing/schedule-board-gantt-year-header.html（年の出し方 4 案）
／ @docs/mockups/housing/schedule-board-gantt-year-initial-view.html（初期表示で年が見えるか）

Codex レビュー **Minor 4**（軸が 12 ヶ月を超えると同じ月名が複数出て年が識別できない）と、
利用者の追加依頼（**初期表示を現在月の 1 ヶ月前から**）を同時に片づけた。
**DB 変更・ルート変更・新規 composer 依存はいずれも無し。**

| # | 決定 |
|---|---|
| D13 | 年は**毎月**、月名の**前に 1 行で**置く（9.5px / `#9CA3AF` / `margin-right: 3px`）。ヘッダは 42px・1 行のまま |
| D14 | **詳細カードも同じ形に揃える**（2 段 → 1 行。年は今までどおり毎月出る） |
| D15 | 初期スクロールは「今日の**前月の 1 日**」を軸の左端に置く。**今日が軸の外でも常にスクロールする**（0% / 100% で止まる） |

⚠ **D9（今日を中央）は D15 で置き換えた。** スクロール関数も
`scheduleBoardScrollToToday` → **`scheduleBoardSetInitialScroll`** に改名した（8 箇所が追従）。
**`--gantt-label-w` を読む必要が無くなった** —— 案件名の列は `position: sticky; left: 0` なので
`scrollLeft = S` のとき軸の左端はちょうど `S`。左端に置くだけなら引き算が要らない（設計書 §12.4）。

⚠ **今日が軸より後（工程が全部終わっている）のとき挙動が変わる。**
従来は左端＝一番古い月だったのが、**右端＝一番新しい月**になる。
工程表は「現状の工程を確認するもの」（2026-09-02 の利用者判断）なので直近が見えるほうが妥当で、
利用者に提示して承認を得た（2026-09-04）。

⚠ **「毎月は冗長だから境目（先頭セルと 1 月セル）だけにしよう」と考え直さないこと。**
一度その案（承認済みモック準拠）で進めたが、**現在の本番データ（軸 2026-02〜09）ですら
年が画面外になる**ことをモックで実測して棄却した。経緯は設計書 §12.3。

⚠ **Carbon の月末溢れを 2 回目に踏みかけた。** 「前月の 1 日」は
**`startOfMonth()` を先に通してから `subMonth()`**。逆順だと**前月ではなく当月**が返る
（実測: 2026-03-31 → 正 2026-02-01 / 誤 2026-03-01）。設計書 §6.1 の軸のずれとまったく同じ罠。
⚠ **テストの「今日」を月末以外にすると、この変異は素通りする**（2026-08-31 や 2026-09-04 では
どちらの順序でも同じ値）。回帰テストは `2026-03-31` を使っている。
```

⚠ 併せて、同じ節の見出し「— 本番未反映」と、末尾の「バックログ完了状況」に書いてある
「未反映のものが 1 件ある」の記述は**そのまま**にする（この追補も同じ未反映の塊に入る）。

- [ ] **Step 2: コミットする**

```bash
git add docs/BACKLOG.md docs/superpowers/plans/2026-09-04-schedule-board-year-header.md
git commit -m "$(cat <<'MSG'
docs: 工程表ボードの年表示と初期スクロールの改修を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 3: マージ可能であることを確認する**

```bash
git -C /Users/masanori/site/manage rev-parse 13.x
git merge-base --is-ancestor 13.x HEAD && echo "ff-merge 可能"
git log --oneline 13.x..HEAD
```

- [ ] **Step 4: 完了を報告する（マージもデプロイもしない）**

⚠ **`13.x` への FF マージと `./deploy.sh` は行わない。**
本番デプロイは**利用者の明示承認**が要る（自動モードの分類器がブロックする）。
本番反映の手順は BACKLOG の「⚠ 本番反映の手順」に書いてあるとおりで、この追補でも変わらない
（**DB 変更なし・ルート変更なし・新規 PHP クラスなし** ＝ `composer dump-autoload` は不要）:

```bash
git checkout 13.x && git merge --ff-only schedule-board-year-header
./deploy.sh
```

⚠ **`resources/css/app.css` は変更していない**ので、この改修に `npm run build` は要らない
（CSS はビューの `@push('styles')` に入る）。`deploy.sh` は従来どおりビルドを走らせる。

⚠ **本番反映後の目視は別途必要**（Bug #21 / #26 が「本番だけ壊れる」前例）。
本番の URL は **`/system/manage/index.php/...`** を挟む（素のパスは 302 で流れる）。
見るのは Task 8 Step 4 の 6 点と同じ。

---

## 付録 A: この改修で**やらないこと**（設計書 §12.7）

- 四半期強調の非対称を揃えること（**ボードは `border-right` / カードは `border-left`** に
  `#D1D5DB` を置いている。判定はどちらも `[1, 4, 7, 10]` で同一。Minor 4 とは別件）
- ガントの上に「表示期間: 2026年2月 〜 2026年9月」の行を出すこと（§12.3 で棄却）
- 年をスクロールに追従させること（sticky な年ラベル。JS が要り、位置計算を JS に持たせない方針に反する）
- 軸の月数・`MONTH_WIDTH_PX = 150`・案件名の列の幅（D3 / D4 / D6 のまま）
- Codex の **Minor 3**（`GanttScale` の逆転区間の契約。現行経路には到達しない）
- Codex の **Minor 5**（軸の最大期間。業務仕様の判断が要る）
- **`ScheduleCardService::months()` の `widthPct` をクランプすること。** ボードの `headers()` は
  `$scale->to()` で日数を打ち切る三項を「将来の防御」として持つが、カードには無い。2026-09-04 実測で、
  部分月のある軸ではカードの `widthPct` 合計が **121.6%〜300%** になり（`daysInMonth` をそのまま使うため）、
  flex の shrink で全セルが比例縮小して**月グリッドが棒と静かにズレる**。⚠ **現行経路では到達しない**
  （両サービスとも軸を `startOfMonth()`〜`endOfMonth()` で作る）。`GanttScale` の構築元が増えた日に
  ボードの三項と対で揃えること。**今回 `overflow: hidden` という「防御の片割れ」だけをカードへ持ち込んだ**
  ので、揃えなかったこと自体を記録に残す（Bug #48「安全網を入れない判断にも理由を書く」）。

## 付録 B: 踏みやすい罠のまとめ

| # | 罠 | 対処 |
|---|---|---|
| 1 | Carbon の月末溢れ | `startOfMonth()` → `subMonth()` の順。テストの「今日」は **`2026-03-31`**（月末以外だと素通りする） |
| 2 | `assertSee('2026')` が工程の日付（`2026/03/31`）や案件名に一致 | **タグ込みか件数**で見る（Bug #43） |
| 3 | `font-size: 9.5px` が今日のピルにも出る | `.gantt-year` の**宣言の形**で見る（Bug #43） |
| 4 | `@push('scripts')` → `styles` の押し間違い | **内容は消えず場所だけ変わる**ので、スクローラーとの**位置**を比較する（Bug #28） |
| 5 | 警告コメント自身がテストの needle に一致 | needle は**宣言の形**（`.gantt-year` + `{`）に限り、コメントに波括弧まで書かない（設計書 §9.5） |
| 5.5 | needle を `セレクタ + {` にしてセレクタリスト形の複製を取りこぼす | `/\.gantt-year(?![\w-])/` のように**セレクタで数える**（RULES「Tailwind 監査の落とし穴 3」。2026-09-04 に実測で発見） |
| 6 | 片方（ボード or カード）だけ測って「守られている」と誤読 | **両方に当てる**（Bug #44） |
| 7 | 固定長の窓でブロックを切って隣のハンドラを拾う | **両端で挟んで**切り出す（Bug #45 ④） |
| 8 | 変異が着弾していないのに「検出しない」と誤読 | `git diff --stat` が非空であることを毎回確認（Bug #55） |
| 9 | 未コミットのまま変異を当て `git checkout --` で自分の編集ごと巻き戻す | **先にコミット**してから当てる（Bug #44 の 2026-08-17 追記） |
| 10 | サブエージェントの `preview_start` が main repo の実 MySQL に到達 | `artisan serve` を Bash の `run_in_background` で ＋ `navigate` |
| 11 | `User::create([... 'role' => …])` が黙って捨てられ 403 | 作成後に明示代入 |
| 12 | worktree に `public/build` が無く Vite manifest 未検出の 500 | main repo の `node_modules/.bin/vite build` を cwd=worktree で |
| 13 | Blade の改行が見た目を変えると思い込んで注記を書く | **flex コンテナでは変わらない**（2026-09-04 実ブラウザ実測: 改行あり/なしとも間隔 3.000px。block 化すると 3.66px 広がる）。落ちるのは**テストの隣接チェック**だけ。誤った理由の注記は次の読み手を誤らせる（Bug #42②） |
| 14 | 年を足して min-content を増やしたのに `overflow: hidden` を無防備のまま置く | flex の `min-width` 既定 auto の床が 12px → **40.6px** に増える（同実測）。`overflow` が visible 以外のときだけ自動最小サイズが 0（Bug #29）。**ボードとカードの両方**でアサートする |
| 15 | 片方のフィクスチャが単年で、年の値の変異が素通りする | **カードとボードの両方を年またぎの軸にする**（2026-09-04 実測: カードの軸が 2026-07〜10 の単年だったため、年を定数に固定してもフルスイート 1315 本が緑だった。設計書 §12.6 が名指しした非対称） |
| 16 | `<script>` の**内容**だけ見て**実行されるか**を見ない | `type` 属性が空であることまで見る（`type="module"` / `"text/template"` は内容が残るのに実行されない。2026-08-31 の POI 改修と 2026-09-04 の本改修で 2 回踏んだ） |
