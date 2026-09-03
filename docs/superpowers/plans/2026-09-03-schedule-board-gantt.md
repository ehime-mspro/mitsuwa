# 工程表ボードのガントを読めるようにする 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 工程表ボードから KPI カードとズームセレクタを取り除き、軸を「データの範囲」に、1 ヶ月を 150px の固定幅にして横スクロールさせ、案件名の列を固定表示にする。同じ幅の規則を工程表カードにも適用する。

**Architecture:** 位置(%) の計算（`GanttScale`）は 1 行も変えない。トラックに px 幅を与えるだけで既存の `left: X%` / `width: X%` がそのまま正しく動く。`ScheduleBoardService::build()` だけは「軸 → 行」から「行 → 軸 → 位置」の 3 パスへ組み替える（軸が絞り込み結果に依存するため）。ラベル列の幅は CSS 変数 1 個で切り替え、PHP には px を持たせない。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine 3 / PHPUnit（`vendor/bin/phpunit`）

**設計書:** `docs/superpowers/specs/2026-09-03-schedule-board-gantt-design.md`（決定 D1〜D12）
**モック:** `docs/mockups/housing/schedule-board-gantt.html`

---

## 作業環境

**worktree:** `.claude/worktrees/schedule-board-gantt`（branch `schedule-board-gantt`、`36e29e79` から分岐済み）

すべてのコマンドはこの worktree の中で実行する。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/schedule-board-gantt
```

⚠ **テストは worktree で回す。** main repo の `vendor` は `--no-dev` で `vendor/bin/phpunit` が無く、
dev を入れて戻し忘れると `deploy.sh` が本番へ rsync してしまう。

⚠ **worktree に `.env` は無い。** `APP_KEY` を環境変数で渡す。**偽の鍵（`base64:x`）を使わない**:

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
```

以降 `./vendor/bin/phpunit` と書いたら、この `APP_KEY` を渡した状態で実行すること。

---

## ファイル構成

| ファイル | 責務 | Task |
|---|---|---|
| `app/Support/GanttScale.php` | 日付 → 位置(%)（**不変**）＋ 月数とトラックの px 幅（**追加**） | 1 |
| `app/Services/ScheduleBoardService.php` | ボード 1 枚の組み立て。3 パス化・KPI とズームの削除 | 2 / 3 / 4 |
| `app/Services/ScheduleCardService.php` | 詳細カード 1 枚の組み立て。force-today の削除・`trackWidthPx` の追加 | 7 |
| `resources/views/_partials/_schedule_gantt_style.blade.php` | **新規**。CSS 変数と sticky の唯一の定義 | 5 |
| `resources/views/_partials/_schedule_board.blade.php` | ボードの表示 | 2 / 3 / 5 / 6 |
| `resources/views/_partials/_schedule_gantt.blade.php` | カードの表示 | 8 |
| `tests/Unit/Support/GanttScaleTest.php` | `GanttScale` の単体テスト（**既存**。月数と px 幅を末尾に足す）| 1 |
| `tests/Feature/Schedule/ScheduleBoardTest.php` | ボードの挙動 | 2 / 3 / 4 / 5 / 6 |
| `tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php` | 住宅の機能が不動産へ漏れていないこと | 2 |
| `tests/Feature/Schedule/ScheduleDateStateTest.php` | 状態チップとラベル欄 | 8 |
| `tests/Feature/Schedule/ScheduleCardAxisTest.php` | **新規**。カードの軸と幅 | 7 / 8 |

---

## Task 0: ベースラインを取る

**Files:** なし（測るだけ）

- [ ] **Step 1: worktree を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/schedule-board-gantt
git log --oneline -1
git status --porcelain
```

期待: `b0a443ae` 以降の 13.x 先端（`git log` が spec のコミットを含む）。`git status` は**空**。

⚠ worktree が `36e29e79` のままなら、先に `git merge --ff-only 13.x` で spec の訂正コミットを取り込む。

- [ ] **Step 2: 全テストを走らせて件数を控える**

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: `OK (1283 tests, 8351 assertions)`。
⚠ **違う数字が出たらそれをベースラインとして記録する**（この数字は 2026-09-02 時点の記録で、
以降にテストが増えている可能性がある）。以降のタスクで「±N 本」を語るときはこの実測値を基準にする。

- [ ] **Step 3: 工程表のテストだけを走らせて控える**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
```

期待: `OK (N tests, M assertions)`。この N を控える。

---

## Task 1: `GanttScale` に月数とトラック幅を足す

**Files:**
- Modify: `app/Support/GanttScale.php`
- Modify: `tests/Unit/Support/GanttScaleTest.php`

⚠ **`GanttScale` の単体テストの正本は既存の `tests/Unit/Support/GanttScaleTest.php`。**
新しいファイルを作らない（同じクラスのテストを 2 ファイル・2 namespace に割ると、
次に触る人が片方を見落とす）。既存ファイルには `setUp()` / `tearDown()` の timezone 固定と
`private function d(string $s): CarbonImmutable` ヘルパが**既にある**ので、それを使う。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/GanttScaleTest.php` の**末尾**に足す（`$this->d(...)` は既存のヘルパ）:

```php
    // ============================================================
    // トラックの幅（1 ヶ月 = 150px 固定。設計書 §3）
    //
    // ⚠ **1 ヶ月 = 150px という数字は GanttScale::MONTH_WIDTH_PX 1 箇所にしか無い。**
    //   Blade にも PHP の別の場所にも書かない（Bug #41 の二重実装）。
    // ============================================================

    public function test_one_month_range_counts_one_month(): void
    {
        $scale = new GanttScale($this->d('2026-09-01'), $this->d('2026-09-30'));

        $this->assertSame(1, $scale->monthCount());
        $this->assertSame(150, $scale->trackWidthPx());
    }

    /** 本番の実データ（2026-02-19 〜 2026-09-27）を月初・月末に丸めた範囲 */
    public function test_the_production_range_is_eight_months(): void
    {
        $scale = new GanttScale($this->d('2026-02-01'), $this->d('2026-09-30'));

        $this->assertSame(8, $scale->monthCount());
        $this->assertSame(1200, $scale->trackWidthPx());
    }

    /**
     * ⚠ **年をまたぐ範囲を必ず 1 本置く。** 月番号の引き算だけで書くと
     *   2026-11 〜 2027-02 が「11 → 2」で負になり、月数が 0 以下になる。
     */
    public function test_a_range_that_crosses_a_year_counts_correctly(): void
    {
        $scale = new GanttScale($this->d('2026-11-01'), $this->d('2027-02-28'));

        $this->assertSame(4, $scale->monthCount());
        $this->assertSame(600, $scale->trackWidthPx());
    }

    /**
     * ⚠ **月の途中で始まり途中で終わる範囲も、掛かっている月を全部数える。**
     *   ヘッダは月セルを掛かっている月ぶん出すので、数え方がずれるとトラック幅と
     *   月セルの合計が食い違い、最後の月だけ幅が違って見える。
     */
    public function test_a_partial_month_still_counts_as_a_whole_month(): void
    {
        $scale = new GanttScale($this->d('2026-02-19'), $this->d('2026-09-27'));

        $this->assertSame(8, $scale->monthCount());
    }

    /**
     * 同じ日で始まり終わる範囲でも 1 ヶ月ぶんの幅を持つ（0 除算・幅 0 を防ぐ）。
     *
     * ⚠ 既存の `test_a_single_day_range_does_not_divide_by_zero` と紛らわしいので
     *   `_is_still_one_month_wide` と名前を分けている。
     */
    public function test_a_single_day_range_is_still_one_month_wide(): void
    {
        $day = $this->d('2026-09-03');

        $this->assertSame(1, (new GanttScale($day, $day))->monthCount());
        $this->assertSame(150, (new GanttScale($day, $day))->trackWidthPx());
    }

    /**
     * ⚠ **逆転区間（from > to）でも負の幅を返さない。** 既存の `totalDays()` が
     *   `max(1, ...)` で守っているのと同じ扱いに揃える。`monthCount()` は public なので
     *   将来の呼び出し元が逆転区間を渡しうる。実測では `max(1, ...)` を外すと
     *   `monthCount() = -2` / `trackWidthPx() = -300` になり、Blade へ
     *   `width: calc(var(--gantt-label-w) + -300px)` が渡る。
     */
    public function test_a_reversed_range_never_yields_a_negative_width(): void
    {
        $scale = new GanttScale($this->d('2027-02-01'), $this->d('2026-11-01'));

        $this->assertSame(1, $scale->monthCount());
        $this->assertSame(150, $scale->trackWidthPx());
    }
```

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit tests/Unit/Support/GanttScaleTest.php
```

期待: `Error: Call to undefined method App\Support\GanttScale::monthCount()`（新しい 6 本が失敗）

- [ ] **Step 3: 実装する**

`app/Support/GanttScale.php` の `totalDays()` の**直後**に足す:

```php
    /**
     * 軸が掛かっている月の数（月初・月末に丸めない範囲でも、掛かっている月を全部数える）。
     *
     * ⚠ **`diffInMonths()` を使わない。** Carbon 3 は float を返し、月末日をまたぐと
     *   端数が出る（`GanttScale::days()` の注記と同じ理由）。年と月の整数演算で出す。
     */
    public function monthCount(): int
    {
        // ⚠ 既存の totalDays() と揃えて必ず 1 以上を返す。逆転区間（from > to）でも
        //   負の px 幅を Blade へ渡さないため（実測: 外すと -2 / -300px になる）。
        return max(1, ($this->to->year - $this->from->year) * 12
            + ($this->to->month - $this->from->month) + 1);
    }

    /**
     * ガントのトラック（案件名の列を除いた軸の部分）の幅（px）。
     *
     * ⚠ **「1 ヶ月 150px」はこの定数 1 箇所にしか無い。** Blade にも別のサービスにも
     *   数字を書かない（Bug #41）。ラベル列の幅（320 / 262 / 140px）は CSS 変数側が持ち、
     *   PHP は一切知らない（設計書 §4.2）。
     */
    public function trackWidthPx(): int
    {
        return $this->monthCount() * self::MONTH_WIDTH_PX;
    }
```

同じファイルのクラス本体の先頭（`private CarbonImmutable $from;` の**直前**）に定数を足す:

```php
    /**
     * 1 ヶ月ぶんの幅（px）。設計書 §3.2。
     *
     * モックで承認した密度（1 ヶ月 145px / 1 日 4.79px）を丸めた値。**画面幅から算出しない**
     * ——固定にすることで 1 日の工程の太さが画面幅に依存しなくなる（375px でも同じ約 4.9px）。
     */
    public const MONTH_WIDTH_PX = 150;

```

- [ ] **Step 4: テストが通ることを確認する**

```bash
./vendor/bin/phpunit tests/Unit/Support/GanttScaleTest.php
```

期待: `OK (16 tests, 29 assertions)`（既存 10 本 + 新規 6 本）

- [ ] **Step 5: 既存テストが壊れていないことを確認する**

```bash
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: Task 0 Step 2 の件数 + 5 で `OK`

- [ ] **Step 6: コミット**

```bash
git add app/Support/GanttScale.php tests/Unit/Support/GanttScaleTest.php
git commit -m "$(cat <<'EOF'
feat(schedule): GanttScale に軸の月数とトラックの px 幅を足す

1 ヶ月 = 150px の固定幅（設計書 §3）。位置(%) の計算は変更なし。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: KPI カードを削除する（D1）

**Files:**
- Modify: `app/Services/ScheduleBoardService.php`
- Modify: `resources/views/_partials/_schedule_board.blade.php:10-25`
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php`
- Modify: `tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php:127-145`

- [ ] **Step 1: 消す測定器の代わりが在ることを確かめる**

`test_the_housing_soon_kpi_still_counts_a_step_even_if_you_try_to_give_it_actuals` は
KPI を**測定器**にして「住宅の工程は保存時に実績が null 化される」ことを裏取りしている。
消す前に、同じ不変条件を直接固定しているテストが在ることを実行して確かめる:

```bash
./vendor/bin/phpunit tests/Feature/Schedule/ScheduleActualsPolicyTest.php --testdox 2>&1 | tail -12
```

期待: 8 本すべて緑で、次の 6 本が並んでいること:

```
The validation rules drop the actual columns for housing
Saving a housing step clears any actual dates already in the database
Creating a housing step with actual dates stores none
Re saving an untouched housing step still clears its actual dates
The hook leaves realestate actual dates alone
Posting actual dates to a housing step stores nothing
```

⚠ **1 本でも欠けていたら KPI のテストを消してはいけない。** その場合は先に
`ScheduleActualsPolicyTest` へ同等のテストを足す（Bug #48 の逆向き＝測定器を消すと守りが消える）。

- [ ] **Step 2: KPI を使うテストを削除する**

`tests/Feature/Schedule/ScheduleBoardTest.php` から次の 8 本を**メソッドごと**削除する
（直前の docblock も一緒に消す）:

```
test_the_kpis_agree_with_the_rows_on_screen
test_the_kpis_follow_the_filter
test_the_housing_kpis_are_scoped_to_the_filtered_status
test_the_housing_board_shows_three_step_based_kpis
test_the_realestate_board_keeps_four_kpis
test_the_housing_soon_kpi_still_counts_a_step_even_if_you_try_to_give_it_actuals
test_the_housing_kpi_cards_are_actually_rendered
test_the_realestate_kpi_cards_are_actually_rendered_as_four
```

併せて `private function kpiCards(string $html): array` ヘルパも削除する（使う人がいなくなる）。

クラスの docblock にある次の一文も削除する（KPI が無くなるので嘘になる）:

```
 * ⚠ **KPI と本体の両方をアサートする。** 同じ数字が 2 箇所に出るので、片方だけ消しても
 *   部分一致で緑になる（Bug #43 / #46 / #49 で繰り返し踏んでいる）。役割ごとに
 *   `viewData()` で見る。
```

代わりに次を入れる:

```
 * ⚠ **同じ数字・同じ語が 2 箇所に出るときは役割ごとに `viewData()` で見る**
 *   （Bug #43 / #46 / #49）。KPI カードは 2026-09-03 に削除した（設計書 §2 D1）。
```

- [ ] **Step 3: 「KPI が戻らない」テストを足す**

`ScheduleBoardTest` の末尾（クラスの閉じ括弧の直前）に足す:

```php
    // ============================================================
    // 消したものが戻らないこと（設計書 §2 D1 / D7）
    // ============================================================

    /**
     * ⚠ **viewData と HTML の両方を見る。** サービスが `kpi` を返さなくなっても、
     *   Blade が別のところから数字を組み立てて描いてしまう経路を塞ぐ。
     *
     * ⚠ **ラベルの文字列で見る。** 「カードの div が無い」で見ると、意匠を変えただけで
     *   落ちるテストになる。
     */
    public function test_the_boards_no_longer_show_kpi_cards(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        foreach (['/housing/schedules', '/realestate/schedules'] as $url) {
            $response = $this->actingAs($this->manager())->get($url)->assertOk();

            $this->assertArrayNotHasKey('kpi', $response->viewData('board'), "{$url} が kpi を返している");

            $html = $response->getContent();

            foreach (['進行中の工程', '進行中の案件', '遅れている案件', '30日以内に始まる工程', '30日以内に終わる工程'] as $label) {
                $this->assertStringNotContainsString($label, $html, "{$url} に KPI ラベル「{$label}」が残っている");
            }
        }
    }

    /**
     * 工程が未登録の案件の件数は**残す**（設計書 §2 D2）。
     *
     * ⚠ KPI を消すついでにこの行まで消す変異を止める。
     */
    public function test_the_unregistered_count_line_survives(): void
    {
        $withSteps = $this->makeParent('property');
        $withSteps->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $this->makeParent('property', ['property_code' => 'HS-002', 'property_name' => '未登録物件']);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertStringContainsString('工程が未登録の案件が 1 件あります', $html);
    }
```

- [ ] **Step 4: `ScheduleRealEstateUntouchedTest` を書き換える**

`tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php:127` の
`test_the_realestate_board_still_shows_four_kpis_a_delay_badge_and_its_four_way_filter` を
次に**丸ごと置き換える**（メソッド名も変える）。まず既存の中身を読み、
KPI の `assertSame([...], array_column($board['kpi'], 'label'))` の行と、その前後の
KPI 用フィクスチャだけを落として、遅延バッジと 4 択フィルタの検証は**そのまま残す**:

```php
    /**
     * 不動産のボードは遅延の概念を保つ（住宅の変更が漏れていないこと）。
     *
     * ⚠ **KPI カードは 2026-09-03 に両ボードから削除した**（設計書 §2 D1）ので、
     *   かつてここにあった「不動産は 4 枚 / 住宅は 3 枚」という証拠はもう使えない。
     *
     * ⚠ **残る証拠は次の 3 つだけ**（設計書 §9.3）。**これ以上減らすときは、
     *   代わりの証拠をこのファイルに足すこと**:
     *     ① 遅延バッジ（行の `+N日` と 棒の `border: 2px solid #DC2626`）
     *     ② 4 択フィルタ（進行中 / すべて / 遅延 / 完了。住宅は 進行中 / すべて / これから / 済）
     *     ③ 詳細カードの実績 2 列（`ScheduleActualsPolicyTest` と本ファイルの別テストが担当）
     */
    public function test_the_realestate_board_keeps_its_delay_badge_and_four_way_filter(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-07-01', 'planned_end' => '2026-08-20', 'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $board    = $response->viewData('board');
        $html     = $response->getContent();

        // 4 択の絞り込み（住宅の 3 択に巻き込まれていない）
        $this->assertSame(
            ['running' => '進行中', 'all' => 'すべて', 'late' => '遅延', 'done' => '完了'],
            $board['statuses']
        );

        // ⚠ M-2: 走査の空振り（PRC-001 の行が無い）を「行が無いので null」と誤読しないよう名指しする
        $row = collect($board['rows'])->firstWhere('code', 'PRC-001');
        $this->assertNotNull($row, 'PRC-001 の行がボードに出ていない');

        // 行自身も遅延を持つ（住宅は delayDays を常に 0 に潰す。ここが不動産だけの経路であること）
        $this->assertSame('late', $row['status']);
        $this->assertSame(13, $row['delayDays']);
        // ⚠ I-2: steps[].delayDays（展開した工程明細の遅延）は row['delayDays']（案件行の遅延の
        //   最大値）と別のサイトで、以前は不動産方向にまったく固定されていなかった（0 に潰しても
        //   1283 全緑）。$s->delayDays($today) と同じ計算なので同じ 13 日。
        $this->assertSame(13, $row['steps'][0]['delayDays'], '工程明細の遅延も残る');

        // ⚠ I-2: bars[].late（遅延した棒の赤枠）を肯定的に見ているテストはアプリ全体に無かった
        //   （`false` に潰しても 1283 全緑）。border: 2px solid #DC2626 はこの画面に 1 箇所しか無い
        //   （grep 済み）ので部分一致の心配は無い。
        $this->assertStringContainsString('border: 2px solid #DC2626', $html, '遅延した棒は赤枠');

        // ⚠ I-1（Bug #43）: 遅延バッジは案件行と工程明細の 2 箇所にあり、
        //   `/color: #DC2626; font-weight: 700[^>]*>\+13日/` は両方に当たるため片方だけ消しても
        //   緑のままだった。役割ごとに分けて見る。
        $this->assertMatchesRegularExpression(
            '/margin-left: auto; font-size: 10\.5px; color: #DC2626; font-weight: 700[^>]*>\+13日/',
            $html,
            '案件行の遅延バッジが描かれていない'
        );
        $this->assertStringContainsString(
            '<span style="color: #DC2626; font-weight: 700;">+13日</span>',
            $html,
            '工程明細の遅延バッジが描かれていない'
        );
    }
```

⚠ 元のメソッドから落とすのは **KPI ラベルを見る `assertSame([...], array_column($board['kpi'], 'label'))`
の 4 行だけ**。他はすべてそのまま残す（`+13日` の 13 は今日 2026-08-31 − 予定終了 2026-08-20）。

- [ ] **Step 5: この時点でテストが赤いことを確認する**

```bash
./vendor/bin/phpunit --filter 'ScheduleBoardTest' 2>&1 | tail -20
```

期待: `test_the_boards_no_longer_show_kpi_cards` が **FAIL**
（`Failed asserting that an array does not have the key 'kpi'`）。
まだサービスが `kpi` を返しているので当然。

- [ ] **Step 6: サービスから KPI を消す**

`app/Services/ScheduleBoardService.php` から次を削除する:

- `private const SOON_DAYS = 30;`（docblock も）
- `private function kpi(...)` メソッド全体（docblock も）
- `private function countRunningSteps(...)` メソッド全体（docblock も）
- `private function countSoon(...)` メソッド全体（docblock も）
- `build()` 内の `$keptSteps = [];` とその行のコメント
- `build()` 内の `$keptSteps[] = $steps;` とその 2 行のコメント
- `build()` の戻り値の `'kpi' => $this->kpi($rows, $keptSteps, $today, $tracksActuals),`

クラスの docblock からも次の 2 行を削除する:

```
 * ⚠ **KPI は絞り込み後の行から数える**（プラン 決定 H）。全件から数えると
 *   絞り込んだときに画面の行数と食い違う（Bug #46）。
```

`use App\Support\ScheduleStepStatus;` は `row()` の `ring` 判定で使い続けるので**残す**。

- [ ] **Step 7: Blade から KPI ブロックを消す**

`resources/views/_partials/_schedule_board.blade.php` の 10〜25 行目
（`{{-- KPI。…` から `</div>` まで）を**丸ごと削除**する。

⚠ 直後の `@if($board['unregisteredCount'] > 0)` ブロックは**残す**（D2）。

- [ ] **Step 8: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
```

期待: `OK`。件数は Task 0 Step 3 の N から **−8 +2 = −6**。

```bash
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: `OK`（全体も −6）

- [ ] **Step 9: コミット**

```bash
git add app/Services/ScheduleBoardService.php resources/views/_partials/_schedule_board.blade.php tests/Feature/Schedule/
git commit -m "$(cat <<'EOF'
feat(schedule): 工程表ボードから KPI カードを削除する

住宅・不動産の両ボードとも削除（設計書 §2 D1）。

⚠ test_the_housing_soon_kpi_still_counts_a_step_... は KPI を測定器にして
「住宅の実績が保存時に null 化される」ことを裏取りしていた。同じ不変条件を
ScheduleActualsPolicyTest が 6 本で直接固定していることを実行で確認したうえで消した。

⚠ ScheduleRealEstateUntouchedTest の「不動産は KPI 4 枚」という証拠も消えるため、
残る証拠（遅延バッジ / 4 択フィルタ / 実績 2 列）を docblock に明記した。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: ズームセレクタを削除する（D7）

**Files:**
- Modify: `app/Services/ScheduleBoardService.php`
- Modify: `resources/views/_partials/_schedule_board.blade.php:57-62`
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php`

- [ ] **Step 1: ズームのテストを削除し、「戻らない」テストを足す**

`ScheduleBoardTest` から次の 2 本をメソッドごと削除する:

```
test_zoom_changes_both_the_range_and_the_header_granularity
test_an_unknown_zoom_falls_back_to_month
```

Task 2 Step 3 で足したセクションの末尾に足す:

```php
    /**
     * ズームセレクタは削除した（設計書 §2 D7 / §5）。
     *
     * ⚠ **「効かない」だけでなく「画面に出ていない」ことも見る。** サービスが `zoom` を
     *   無視するようになっても、Blade に `<select name="zoom">` が残っていれば
     *   利用者は押せてしまい、押しても何も起きない画面になる。
     *
     * ⚠ 既存の URL `?zoom=week` は**無視されるだけ**でよい（リダイレクトはしない）。
     */
    public function test_the_zoom_selector_is_gone_and_the_query_key_is_ignored(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $plain = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk();
        $zoomed = $this->actingAs($this->manager())->get('/housing/schedules?zoom=quarter')->assertOk();

        $this->assertArrayNotHasKey('zooms', $plain->viewData('board'), 'zooms がまだ view へ渡っている');
        $this->assertArrayNotHasKey('zoom', $plain->viewData('board')['filters'], 'filters に zoom が残っている');

        $this->assertStringNotContainsString('name="zoom"', $plain->getContent(), 'ズームの select が残っている');
        $this->assertStringNotContainsString('表示: ', $plain->getContent(), 'ズームのラベルが残っている');

        // ⚠ 軸が ?zoom で変わらないこと（無視されている証拠）
        $this->assertSame(
            $plain->viewData('board')['axis']['from'],
            $zoomed->viewData('board')['axis']['from'],
            '?zoom= が軸を変えている'
        );
        $this->assertSame(
            $plain->viewData('board')['axis']['to'],
            $zoomed->viewData('board')['axis']['to'],
            '?zoom= が軸を変えている'
        );
    }
```

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit --filter 'test_the_zoom_selector_is_gone_and_the_query_key_is_ignored' 2>&1 | tail -12
```

期待: FAIL（`Failed asserting that an array does not have the key 'zooms'`）

- [ ] **Step 3: サービスからズームを消す**

`app/Services/ScheduleBoardService.php`:

- `public const ZOOMS = [...]` を docblock ごと削除
- `private const DEFAULT_ZOOM = 'month';` を削除
- `build()` の `$zoom = self::ZOOMS[$filters['zoom']];` を削除
- `build()` の軸を作る 3 行を、いったん**今までと同じ範囲を直接書いて**置き換える
  （案B は Task 4 で入れる。ここでは振る舞いを変えない）:

```php
        // ⚠ **月初に正規化してから加減算する。** Carbon の subMonths()/addMonths() は
        //   月末日で溢れる（実測: 2026-08-31 の 6 ヶ月前は 2026-03-03 になり、そのあと
        //   startOfMonth() を通しても 3/1 ＝ 軸が 1 ヶ月ずれる）。
        $anchor = $today->startOfMonth();
        $scale  = new GanttScale(
            $anchor->subMonths(6),
            $anchor->addMonths(12)->endOfMonth()->startOfDay()
        );
```

- `build()` の戻り値から `'zooms' => self::ZOOMS,` を削除
- `build()` の `'granularity' => $zoom['granularity'],` を削除
- `build()` の `'headers' => $this->headers($scale, $zoom['granularity']),` を
  `'headers' => $this->headers($scale),` に変更
- `filters()` からズームの 4 行を削除し、戻り値の `'zoom' => $zoom,` も削除:

```php
        $zoom = (string) ($request->query('zoom') ?? self::DEFAULT_ZOOM);
        if (! array_key_exists($zoom, self::ZOOMS)) {
            $zoom = self::DEFAULT_ZOOM;
        }
```

- `headers()` を月固定にする（`$granularity` 引数を削る）:

```php
    /**
     * 月の見出し。⚠ 粒度の切り替え（週 / 四半期）は 2026-09-03 に削除した（設計書 §5）。
     *
     * @return list<array{label: string, widthPct: float, strong: bool}>
     */
    private function headers(GanttScale $scale): array
    {
        $headers = [];
        $cursor  = $scale->from()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($scale->to())) {
            $next = $cursor->addMonth()->startOfMonth();

            // ⚠ 最後のセルが軸をはみ出さないように、区間の終わりで打ち切る
            $end  = $next->greaterThan($scale->to()) ? $scale->to()->addDay() : $next;
            $days = max(1, (int) round($cursor->diffInDays($end)));

            $headers[] = [
                'label'    => $cursor->format('n') . '月',
                'widthPct' => $days / $scale->totalDays() * 100,
                'strong'   => in_array($cursor->month, [1, 4, 7, 10], true),
            ];

            $cursor = $next;
        }

        return $headers;
    }
```

- [ ] **Step 4: Blade からズームの select を消す**

`resources/views/_partials/_schedule_board.blade.php` の 57〜62 行目
（`<select name="zoom" …>` から `</select>` まで）を削除する。

- [ ] **Step 5: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
```

期待: `OK`。件数は Task 2 の結果から **−2 +1 = −1**。

- [ ] **Step 6: コミット**

```bash
git add app/Services/ScheduleBoardService.php resources/views/_partials/_schedule_board.blade.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'EOF'
feat(schedule): 工程表ボードのズームセレクタを削除する

設計書 §2 D7 / §5。見出しは常に月。既定の「今日の 6 ヶ月前〜12 ヶ月後」という
軸そのものは Task 4 で案B へ差し替えるので、ここでは範囲を直書きして振る舞いを変えない。

?zoom= は無視されるだけ（リダイレクトしない）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 軸をデータの範囲にする（D3 / D8 / §6）

**Files:**
- Modify: `app/Services/ScheduleBoardService.php`
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php`

- [ ] **Step 1: 既存の軸テストを書き換え、案B のテストを足す**

`ScheduleBoardTest` の `test_the_default_axis_is_six_months_back_and_twelve_forward` を
**丸ごと次の 5 本に置き換える**:

```php
    // ============================================================
    // 軸はデータの範囲（案B。設計書 §2 D3 / §6）
    // ============================================================

    /**
     * ⚠ **今日は 2026-08-31 に固定してある**（setUp）。旧実装なら軸は
     *   2026-03-01 〜 2027-08-31 の 19 ヶ月になる。案B ではデータの範囲だけ。
     */
    public function test_the_axis_spans_only_the_range_the_bars_occupy(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);
        $proc->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-07-01', 'planned_end' => '2026-07-20', 'sort_order' => 2]);

        $axis = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->viewData('board')['axis'];

        $this->assertSame('2026-05-01', $axis['from'], '軸の始まりが最小の開始月でない');
        $this->assertSame('2026-07-31', $axis['to'], '軸の終わりが最大の終了月でない');
        $this->assertSame(450, $axis['trackWidthPx'], '3 ヶ月 × 150px でない');
    }

    /**
     * ⚠ **絞り込みを変えると軸が変わる**（案B のトレードオフ。設計書 §2 D3）。
     *   意図した挙動なのでテストで固定する。「軸が動くのは不具合」と読んで
     *   全件から軸を出す変異に戻すのを止める。
     */
    public function test_the_axis_follows_the_filter(): void
    {
        $late = $this->makeParent('procurement');
        $late->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);

        $done = $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '完了案件']);
        $done->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $all = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board')['axis'];
        $this->assertSame('2026-01-01', $all['from'], '全件では完了案件まで含めた範囲になるはず');

        // ⚠ **`status=running` にしない。** 測量は予定終了 6/5 が今日（8/31）を過ぎていて
        //   実績が無いので判定は `late`、決済は実績が揃っているので `done` ＝ **running は 0 件**になり、
        //   軸が「今日の 1 ヶ月」フォールバックに落ちて別の理由で緑になる。
        $late = $this->actingAs($this->manager())->get('/realestate/schedules?status=late')->viewData('board')['axis'];
        $this->assertSame('2026-05-01', $late['from'], '絞り込み後の案件だけで軸を出していない');
        $this->assertSame('2026-06-30', $late['to'], '完了案件の範囲が混ざっている');
    }

    /**
     * ⚠ **◆（自動マイルストーン）の日付も軸に入れる**（設計書 §6.1）。
     *   入れないと clamp() で 0% / 100% に貼り付き、**軸の端に嘘の位置で出る**。
     */
    public function test_the_axis_includes_the_auto_milestones(): void
    {
        $prop = $this->makeParent('property', ['construction_start_date' => '2026-02-10']);
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);

        $board = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->viewData('board');

        $this->assertSame('2026-02-01', $board['axis']['from'], '着工予定日が軸に入っていない');

        // ⚠ 端に貼り付いていないこと（0% ちょうどでも 100% ちょうどでもない）
        $left = $board['rows'][0]['milestones'][0]['leftPct'];
        $this->assertGreaterThan(0.0, $left);
        $this->assertLessThan(100.0, $left);
    }

    /**
     * 今日が軸の外なら今日線を描かない（設計書 §2 D8 / §6）。**軸は伸ばさない。**
     *
     * ⚠ **viewData と HTML の両方を見る。** `todayPct` が null でも Blade が
     *   別経路で赤い線を描いていたら意味がない。
     */
    public function test_a_past_only_board_draws_no_today_marker_and_does_not_stretch_the_axis(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $board    = $response->viewData('board');

        $this->assertSame('2026-01-01', $board['axis']['from']);
        $this->assertSame('2026-01-31', $board['axis']['to'], '今日まで軸が伸びている');
        $this->assertNull($board['axis']['todayPct']);

        $html = $response->getContent();
        $this->assertStringNotContainsString('今日 8/31', $html, '今日バッジが出ている');
        $this->assertStringNotContainsString('dashed #EF4444', $html, '今日線が出ている');
    }

    /**
     * 工程はあるが日付が 1 つも無い案件だけが残ったとき（設計書 §6.2）。
     *
     * ⚠ **0 除算で落ちない・案件が画面から消えない**の 2 つを見る。
     *   軸は今日の 1 ヶ月にフォールバックする。
     */
    public function test_a_board_of_undated_steps_falls_back_to_the_current_month(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '日付未定', 'category' => 'work', 'sort_order' => 1]);

        $board = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->viewData('board');

        $this->assertSame('2026-08-01', $board['axis']['from']);
        $this->assertSame('2026-08-31', $board['axis']['to']);
        $this->assertSame(150, $board['axis']['trackWidthPx']);
        $this->assertSame(['HS-001'], array_column($board['rows'], 'code'), '案件が画面から消えている');
        $this->assertSame([], $board['rows'][0]['bars'], '棒が描かれている');
    }
```

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit --filter 'ScheduleBoardTest' 2>&1 | tail -20
```

期待: 上の 5 本が FAIL（軸がまだ今日基準の 19 ヶ月）

- [ ] **Step 3: `build()` を 3 パスに組み替える**

`app/Services/ScheduleBoardService.php` の `build()` を丸ごと次に置き換える:

```php
    /**
     * @param  array<string, array{0: class-string, 1: string}>  $kinds  絞り込みキー => [親クラス, 表示名]
     *
     * ⚠ 第 1 引数に既定値を付けないこと（設計書 §4.3。ReflectionMethod でテストが固定している）。
     *
     * ⚠ **3 パスなのは軸が「絞り込み後の行」に依存するから**（案B。2026-09-03 の設計書 §8.1）。
     *   ① 絞り込み ② 軸を決める ③ 位置(%) を計算する、の順。①で作る値は軸に依存しない
     *   （ステータス・遅延日数・工程明細はどれも日付だけで決まる）。
     */
    public function build(array $kinds, Request $request, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        // ⚠ **1 枚のボードで親の方針が混ざらないことを先に確かめる**（決定 P4）。
        //   混ざったまま進むと、案件ごとに遅延の有無が食い違う画面になる。
        $tracksActuals = $this->tracksActuals($kinds);
        // ⚠ **ここ 1 箇所だけで出す**（M-2）。build()（view へ返す側）と filters()
        //   （入力を検証する側）の 2 箇所に同じ三項演算子を置くと、語彙が 3 つ目に
        //   増えたときどちらかを直し忘れる形になる。
        $statuses = $tracksActuals ? self::STATUSES : self::DATE_STATUSES;
        $filters  = $this->filters($kinds, $request, $statuses);

        // ---- パス1: 絞り込み。軸に依存しない値だけを作る ----
        $kept         = [];
        $unregistered = 0;

        foreach ($kinds as $key => [$class, $label]) {
            if ($filters['kind'] !== self::STATUS_ALL && $filters['kind'] !== $key) {
                continue;
            }

            foreach ($class::with('scheduleSteps')->get() as $owner) {
                $steps = $owner->scheduleSteps;

                // ⚠ 工程が 0 件の案件は出さない（件数だけ数える。設計書 §4.2）
                if ($steps->isEmpty()) {
                    $unregistered++;

                    continue;
                }

                $meta = $this->meta($owner, $label, $key, $steps, $today, $tracksActuals);

                if (! $this->matches($meta, $owner, $steps, $filters)) {
                    continue;
                }

                $kept[] = ['owner' => $owner, 'steps' => $steps, 'meta' => $meta];
            }
        }

        // ---- パス2: 残った案件から軸を決める ----
        $scale = $this->scale($kept, $today);

        // ---- パス3: 位置(%) を計算する ----
        $rows = [];

        foreach ($kept as $k) {
            $rows[] = $this->position($k['meta'], $k['owner'], $k['steps'], $scale, $today, $tracksActuals);
        }

        return [
            'rows'              => $rows,
            'unregisteredCount' => $unregistered,
            'filters'           => $filters,
            'kinds'             => $kinds,
            'statuses'          => $statuses,
            'axis'              => [
                'from'         => $scale->from()->toDateString(),
                'to'           => $scale->to()->toDateString(),
                'headers'      => $this->headers($scale),
                'trackWidthPx' => $scale->trackWidthPx(),
                'todayPct'     => $scale->contains($today) ? $scale->left($today) : null,
                'todayLabel'   => $today->format('n/j'),
            ],
        ];
    }

    /**
     * 絞り込み後の案件から軸を決める（案B。設計書 §6.1）。
     *
     * 集めるのは**画面に描くもの**の日付だけ ——棒の区間（`drawStart()` 〜 `drawEnd($today)`）と
     * ◆ の日付。生の `planned_end` を使わないのは、不動産で実績開始があり実績終了が無い工程は
     * 棒が今日まで伸びるため（`drawEnd()` が今日を返す）。
     *
     * ⚠ **◆ を入れ忘れないこと。** 着工予定日・完成予定日が棒の範囲の外にあると、
     *   `clamp()` で 0% / 100% に貼り付いて**軸の端に嘘の位置で出る**。
     *
     * ⚠ **今日を必ず含める処理は入れない**（設計書 §2 D8）。今日が範囲外なら
     *   今日線を描かないだけにする。伸ばすと案A で却下した「空白だらけ」に戻る。
     *
     * @param  list<array{owner: Model, steps: \Illuminate\Support\Collection, meta: array}>  $kept
     */
    private function scale(array $kept, CarbonImmutable $today): GanttScale
    {
        $dates = [];

        foreach ($kept as $k) {
            foreach ($k['steps'] as $step) {
                if (! $step->isDrawable()) {
                    continue;
                }

                $dates[] = CarbonImmutable::instance($step->drawStart())->startOfDay();
                $dates[] = CarbonImmutable::instance($step->drawEnd($today))->startOfDay();
            }

            foreach ($k['owner']->autoMilestones() as $m) {
                $dates[] = CarbonImmutable::instance($m['date'])->startOfDay();
            }
        }

        // ⚠ 日付が 1 つも無いとき（行が 0 件 / 工程はあるが全部未設定）は今日の 1 ヶ月に倒す
        //   （設計書 §6.2）。0 除算を避けつつ案件の行は残す。
        if ($dates === []) {
            return new GanttScale($today->startOfMonth(), $today->endOfMonth()->startOfDay());
        }

        // ⚠ endOfMonth() は 23:59:59.999999 を返すので startOfDay() で揃える。
        //   揃えないと日数が 1 多く出る（GanttScale の注記と同じ理由）。
        return new GanttScale(
            min($dates)->startOfMonth(),
            max($dates)->endOfMonth()->startOfDay()
        );
    }
```

- [ ] **Step 4: `row()` を `meta()` と `position()` に割る**

同じファイルの `private function row(...)` を丸ごと次の 2 つに置き換える:

```php
    /**
     * 1 案件ぶんの、**軸に依存しない**値（パス1で作る）。
     *
     * ⚠ ステータス・遅延日数・工程明細はどれも日付だけで決まるので、軸より先に確定できる。
     *   絞り込み（`matches()`）が `status` を見るため、ここで作れないと 3 パスが成立しない。
     */
    private function meta(Model $owner, string $kindLabel, string $kindKey, $steps, CarbonImmutable $today, bool $tracksActuals): array
    {
        return [
            'kind'      => $kindKey,
            'kindLabel' => $kindLabel,
            'code'      => $owner->scheduleCode(),
            'name'      => $owner->scheduleName(),
            'url'       => $owner->scheduleUrl(),
            'status'    => $tracksActuals
                ? $this->status($steps, $today)
                : $this->dateStatus($steps, $today),
            // ⚠ 実績を持たない親では遅延を出さない（設計書 §8）
            'delayDays' => $tracksActuals ? (int) $steps->max(fn (ScheduleStep $s) => $s->delayDays($today)) : 0,
            'steps'     => $steps->map(fn (ScheduleStep $s) => [
                'name'       => $s->name,
                'color'      => $s->category->color(),
                'periodText' => $s->periodText($today),
                'delayDays'  => $tracksActuals ? $s->delayDays($today) : 0,
                'progress'   => $s->progress(),
            ])->all(),
        ];
    }

    /** 軸が決まってから位置(%) を足す（パス3） */
    private function position(array $meta, Model $owner, $steps, GanttScale $scale, CarbonImmutable $today, bool $tracksActuals): array
    {
        $drawable = $steps->filter(fn (ScheduleStep $s) => $s->isDrawable())->values();

        $spans = $drawable->map(fn (ScheduleStep $s) => [
            'from' => CarbonImmutable::instance($s->drawStart())->startOfDay(),
            'to'   => CarbonImmutable::instance($s->drawEnd($today))->startOfDay(),
        ])->all();

        $lanes = LanePacker::assign($spans);

        $bars = [];

        foreach ($drawable as $i => $step) {
            $left = GanttScale::clamp($scale->left($spans[$i]['from']), 0.0, 100.0);

            $bars[] = [
                'name'     => $step->name,
                'color'    => $step->category->color(),
                'lane'     => $lanes[$i],
                'topPx'    => LanePacker::LANE_TOP + $lanes[$i] * LanePacker::LANE_HEIGHT,
                'leftPct'  => $left,
                'widthPct' => GanttScale::clamp($scale->width($spans[$i]['from'], $spans[$i]['to']), 0.0, 100.0 - $left),
                'late'     => $tracksActuals && $step->isLate($today),
                // まだ始まっていない工程は薄く出す（設計書 §4.2）。
                // ⚠ 実績を持たない親では薄くしない（住宅は分類色のまま。設計書 §8）。
                'future'   => $tracksActuals && $step->actual_start === null && $spans[$i]['from']->greaterThan($today),
                // 進行中の棒だけ濃い輪郭。詳細カードと同じ規則（設計書 §8）
                'ring'     => ! $tracksActuals && $step->dateState($today) === ScheduleStepStatus::RUNNING,
            ];
        }

        $laneCount = LanePacker::laneCount($lanes);

        return $meta + [
            'laneCount'  => $laneCount,
            'rowHeight'  => LanePacker::rowHeight($laneCount),
            'bars'       => $bars,
            'milestones' => $this->milestones($owner, $scale, $today),
        ];
    }
```

⚠ `matches()` の第 1 引数名を `array $row` から `array $meta` に変え、
`$row['status']` を `$meta['status']` にする（中身は同じ）。

- [ ] **Step 5: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
```

期待: `OK`。件数は Task 3 の結果から **−1 +5 = +4**。

```bash
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: `OK`

- [ ] **Step 6: コミット**

```bash
git add app/Services/ScheduleBoardService.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'EOF'
feat(schedule): 工程表ボードの軸をデータの範囲にする

案B（設計書 §2 D3 / §6）。19 ヶ月のうち 12 ヶ月が空白だった状態を解消する。

軸が絞り込み結果に依存するので build() を 3 パス（絞り込み → 軸 → 位置）へ組み替えた。
row() は meta()（軸に依存しない）と position()（位置(%)）に割った。

今日が軸の外なら今日線を描かない（軸は伸ばさない）。日付が 1 つも無いときは
今日の 1 ヶ月にフォールバックする。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 共有 CSS とボードの固定幅・固定表示（D4 / D5 / D6）

**Files:**
- Create: `resources/views/_partials/_schedule_gantt_style.blade.php`
- Modify: `resources/views/_partials/_schedule_board.blade.php`
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

Task 4 Step 1 で足したセクションの末尾に足す:

```php
    // ============================================================
    // トラックの幅と案件名の固定表示（設計書 §3 / §4）
    // ============================================================

    /**
     * ⚠ **CSS はレンダリング済み HTML で見る**（先行例 `CustomOrderIndexListColumnsTest`）。
     *   `app.css` に置くとビルド済み CSS が worktree に無く（`.gitignore`）テストで測れない。
     */
    public function test_the_gantt_style_partial_is_rendered_with_the_label_width_variables(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/\.gantt-scroll\s*\{[^}]*--gantt-label-w:\s*320px;/', $html);
        $this->assertMatchesRegularExpression('/\.gantt-scroll--card\s*\{[^}]*--gantt-label-w:\s*262px;/', $html);
        $this->assertMatchesRegularExpression('/\.gantt-label\s*\{[^}]*position:\s*sticky;/', $html);
        $this->assertMatchesRegularExpression('/\.gantt-label\s*\{[^}]*left:\s*0;/', $html);

        // ⚠ **メディアクエリは --card より後ろでなければならない。** 詳細度が同じ (0,1,0) なので
        //   前に置くとカードだけ 262px のまま残る。位置関係で固定する。
        $card  = strpos($html, '.gantt-scroll--card');
        $media = strpos($html, '@media (max-width: 640px)');
        $this->assertNotFalse($card);
        $this->assertNotFalse($media);
        $this->assertGreaterThan($card, $media, 'メディアクエリが .gantt-scroll--card より前にある');
        $this->assertMatchesRegularExpression('/@media \(max-width: 640px\)\s*\{[^}]*\.gantt-scroll\s*\{[^}]*--gantt-label-w:\s*140px;/', $html);
    }

    /**
     * トラックの幅は「ラベル幅 + 月数 × 150px」（設計書 §3）。
     *
     * ⚠ **`min-width` が残っていないことも見る。** 残ると狭い画面で意図しない下限ができる。
     */
    public function test_the_track_is_as_wide_as_the_months_it_spans(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-07-20', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();

        $this->assertSame(450, $response->viewData('board')['axis']['trackWidthPx']);

        $html = $response->getContent();
        $this->assertStringContainsString('width: calc(var(--gantt-label-w) + 450px)', $html);
        $this->assertStringNotContainsString('min-width: 1000px', $html, '旧い固定 min-width が残っている');
    }

    /**
     * 案件名の列が固定表示になっていること。
     *
     * ⚠ **件数の下限も固定する**（走査が空振りして緑になる事故を防ぐ。Bug #45）。
     *   このフィクスチャ（案件 1 件）ではヘッダ 1 + 行 1 の 2 個。
     *
     * ⚠ **`flex: 0 0 320px` のような px 直書きが残っていないことも見る。**
     *   残ると CSS 変数が効かず、モバイルで 140px にならない。
     */
    public function test_the_case_name_column_is_sticky_and_sized_by_the_css_variable(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->getContent();

        preg_match_all('/flex: 0 0 var\(--gantt-label-w\);/', $html, $m);
        $this->assertCount(2, $m[0], 'ラベル欄の数が想定と違う（ヘッダ 1 + 行 1）');

        $this->assertStringContainsString('class="gantt-label gantt-label--head"', $html, 'ヘッダのラベル欄にクラスが無い');
        $this->assertStringContainsString('class="gantt-label"', $html, '行のラベル欄にクラスが無い');
        $this->assertStringNotContainsString('flex: 0 0 320px', $html, 'px 直書きが残っている');
    }
```

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit --filter 'ScheduleBoardTest' 2>&1 | tail -20
```

期待: 上の 3 本が FAIL

- [ ] **Step 3: CSS の partial を作る**

`resources/views/_partials/_schedule_gantt_style.blade.php`:

```blade
{{--
    ガントの CSS（設計書 §4.2）。**ここがこの CSS の唯一の定義**で、
    ボード（_schedule_board）と詳細カード（_schedule_gantt）の両方が @include する。

    ⚠ **`resources/css/app.css` には置かない。** ビルド済み CSS は .gitignore 済みで
       worktree に存在しないため、テストが実物を見られなくなる
       （RULES「Tailwind 監査の落とし穴 1」）。先行例は housing/contracts/index.blade.php の
       .co-sticky と _partials/_map_style.blade.php の AREA_MAP_STYLES。

    ⚠ **@once で囲む。** 現状ボードとカードが同一ページに同居することは無いが、
       将来同居したときに <style> が 2 回出るのを防ぐ。

    ⚠ **ラベル欄の幅（320 / 262 / 140px）はここだけが持つ。** PHP は
       `calc(var(--gantt-label-w) + <月数×150>px)` としか書かない（設計書 §4.2）。
--}}
@once
    @push('styles')
        <style>
            .gantt-scroll       { --gantt-label-w: 320px; }
            .gantt-scroll--card { --gantt-label-w: 262px; }

            /* 案件名（カードは工程名）の列を左端に貼り付ける。
               ⚠ 影は ::after でなく box-shadow で出す。ラベルのセルは overflow: hidden を
                  持っており（Bug #29 対策で外せない）、::after は right: -6px でクリップされる。
                  overflow は子孫を切るが、その要素自身の box-shadow は切らない。 */
            .gantt-label        { position: sticky; left: 0; z-index: 5; background: #fff;
                                  box-shadow: 6px 0 6px -6px rgba(0, 0, 0, 0.18); }
            .gantt-label--head  { z-index: 6; background: #F9FAFB; }

            /* ⚠ **この @media は .gantt-scroll--card より後ろでなければならない。**
                  詳細度はどちらも (0,1,0) なので後勝ち。前に置くとカードだけ 262px のまま残り、
                  375px で軸が 81px しか見えなくなる（PHP もテストも素通りする型。Bug #29）。
               ⚠ 640px は app.css の既存ユーティリティ（.grid-stack-sm 等）と同じ境目に揃えている。 */
            @media (max-width: 640px) {
                .gantt-scroll   { --gantt-label-w: 140px; }
            }
        </style>
    @endpush
@endonce
```

- [ ] **Step 4: ボードの Blade を直す**

`resources/views/_partials/_schedule_board.blade.php`:

1. ファイル先頭の `@php($axis = $board['axis'])` の**直後**に足す:

```blade
@include('_partials._schedule_gantt_style')
```

2. `<div style="overflow-x: auto;">` と `<div style="min-width: 1000px;">` の 2 行を置き換える:

```blade
        <div id="schedule-board-scroller" class="gantt-scroll" style="overflow-x: auto;">
            <div style="width: calc(var(--gantt-label-w) + {{ $axis['trackWidthPx'] }}px);">
```

3. ヘッダ行のラベル欄（`flex: 0 0 320px; border-right: …">案件</div>` の行）を置き換える:

```blade
                    <div class="gantt-label gantt-label--head" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">案件</div>
```

4. 案件行のラベル欄（`flex: 0 0 320px; border-right: … gap: 6px; …`）の開始タグを置き換える:

```blade
                            <div class="gantt-label" style="flex: 0 0 var(--gantt-label-w); border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; min-width: 0; overflow: hidden;">
```

⚠ **`min-width: 0` を落とさないこと**（Bug #29）。`overflow: hidden` は新規に足す
（sticky にすると中身がはみ出したとき軸に重なるため）。

- [ ] **Step 5: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: どちらも `OK`（+3）

- [ ] **Step 6: コミット**

```bash
git add resources/views/_partials/ tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'EOF'
feat(schedule): ボードのガントを 1 ヶ月 150px 固定にして案件名の列を貼り付ける

設計書 §2 D4 / D5 / D6。min-width: 1000px を
calc(var(--gantt-label-w) + 月数×150px) に置き換えて横スクロールさせる。

CSS は _schedule_gantt_style.blade.php（新規）に 1 本化し @push('styles') で出す。
app.css に置かないのは、ビルド済み CSS が worktree に無くテストで見られないため。

ラベル欄の幅（320 / 262 / 140px）は CSS 変数だけが持ち、PHP は知らない。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 開いた直後に今日までスクロールする（D9）

**Files:**
- Modify: `resources/views/_partials/_schedule_board.blade.php`
- Modify: `tests/Feature/Schedule/ScheduleBoardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

Task 5 Step 1 のセクションの末尾に足す:

```php
    /**
     * 開いた直後に今日が見える位置までスクロールしておく（設計書 §2 D9 / §7.1）。
     *
     * ⚠ **定義側と呼び出し側を対で見る。** 片方だけ消えても HTML としては妥当なので、
     *   呼び出しだけ見ると「関数が無いのに緑」になる（Bug #28）。
     *
     * ⚠ **`@push('scripts')` が実際に出ていることまで見る。** `@stack` が無い時代は
     *   push した中身が黙って捨てられていた（Bug #28）。
     */
    public function test_the_board_scrolls_to_today_on_open(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk();
        $html     = $response->getContent();
        $axis     = $response->viewData('board')['axis'];

        $this->assertNotNull($axis['todayPct'], 'このフィクスチャでは今日が軸の中にあるはず');

        // 定義側
        $this->assertStringContainsString('function scheduleBoardScrollToToday(id, pct, trackPx)', $html);
        // 呼び出し側（引数は実際の値で出ていること）
        $this->assertStringContainsString(
            "scheduleBoardScrollToToday('schedule-board-scroller', {$axis['todayPct']}, {$axis['trackWidthPx']});",
            $html
        );
        // スクロール先の要素
        $this->assertStringContainsString('id="schedule-board-scroller"', $html);
    }

    /**
     * ⚠ 今日が軸の外なら**スクロールのスクリプトごと出さない**（設計書 §7.1）。
     *   `pct` が null のまま出すと `scrollLeft = NaN` になる。
     */
    public function test_no_scroll_script_when_today_is_outside_the_axis(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->assertOk()->getContent();

        $this->assertStringNotContainsString('scheduleBoardScrollToToday', $html);
    }
```

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit --filter 'test_the_board_scrolls_to_today_on_open' 2>&1 | tail -12
```

期待: FAIL（`Failed asserting that '...' contains "function scheduleBoardScrollToToday..."`）

- [ ] **Step 3: 実装する**

`resources/views/_partials/_schedule_board.blade.php` の `@else` ブロックの末尾
（表を包む `</div>` の直後、`@endif` の直前）に足す:

```blade
    @if($axis['todayPct'] !== null)
        {{-- 開いた直後に今日が見える位置まで横スクロールしておく（設計書 §7.1）。

             ⚠ **アロー関数を属性にも <script> にも書かない。** Blade の属性内では
                `=>` の `>` が HTML の終了タグとして解釈される（Top trap #4）。
                x-init ではなく名前付き関数にしているのはこのため。

             ⚠ **位置(%) は PHP が出す。** ここが計算するのはスクロール量だけで、
                日付 → % の計算は持たない（Bug #41 の二重実装を避ける）。

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
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: どちらも `OK`（+2）

- [ ] **Step 5: コミット**

```bash
git add resources/views/_partials/_schedule_board.blade.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'EOF'
feat(schedule): ボードを開いた直後に今日が見える位置までスクロールする

設計書 §2 D9 / §7.1。案B の軸はデータの開始月から始まるので、そのままだと
今日を見るのに毎回右へスクロールが要る。

位置(%) は PHP が出し、JS はスクロール量だけを出す（Bug #41）。
アロー関数は使わない（Top trap #4）。今日が軸の外ならスクリプトごと出さない。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: カードの軸から「今日まで伸ばす」を外す（D8）

**Files:**
- Modify: `app/Services/ScheduleCardService.php`
- Create: `tests/Feature/Schedule/ScheduleCardAxisTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleCardAxisTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 詳細カードのガントの軸と幅（設計書 §2 D8 / D10）。
 *
 * ⚠ **ボードと規則を揃える。** 今日が軸の外でも軸を伸ばさない。
 */
class ScheduleCardAxisTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const TODAY = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        CarbonImmutable::setTestNow(self::TODAY);
        \Carbon\Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 今日が軸の外（±1 ヶ月のパディングを足してもなお外）なら、軸を伸ばさない。
     *
     * ⚠ 工程は 2026-01-05〜2026-01-20。パディング込みで 2025-12-01〜2026-02-28。
     *   今日は 2026-08-31 なので範囲外。旧実装は `to` を 2026-08-31 まで伸ばしていた。
     */
    public function test_the_card_axis_is_not_stretched_to_today(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $gantt = $response->viewData('schedule')['gantt'];

        $this->assertSame('2025-12-01', $gantt['from'] ?? null, 'パディング込みの始まりが違う');
        $this->assertSame('2026-02-28', $gantt['to'] ?? null, '今日まで軸が伸びている');
        $this->assertNull($gantt['todayPct'], '軸の外なのに今日線を描こうとしている');

        $this->assertStringNotContainsString('dashed #EF4444', $response->getContent(), '今日線が出ている');
    }

    /**
     * ⚠ **今日より前に始まる案件では軸を伸ばさなくても今日が入る**ことも 1 本置く。
     *   「常に null になる」変異（`contains()` を false に固定する等）を止める。
     */
    public function test_a_current_case_still_draws_the_today_line(): void
    {
        $owner = $this->makeParent('procurement', ['procurement_code' => 'PRC-002']);
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $this->assertNotNull($response->viewData('schedule')['gantt']['todayPct']);
        $this->assertStringContainsString('今日 8/31', $response->getContent());
    }
}
```

⚠ `$gantt['from']` / `$gantt['to']` は現在の `ScheduleCardService` が返していない。
**Step 3 で足す**（軸をテストから見えるようにするため。ボードの `axis` と対称になる）。

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit tests/Feature/Schedule/ScheduleCardAxisTest.php 2>&1 | tail -15
```

期待: `test_the_card_axis_is_not_stretched_to_today` が FAIL
（`from` が null ＝ キーが無い）

- [ ] **Step 3: 実装する**

`app/Services/ScheduleCardService.php` の `gantt()` から次の 5 行を削除する:

```php
        // 今日が範囲外なら今日も含める（今日線が枠外に出ないように）
        if ($today->lessThan($from)) {
            $from = $today->startOfMonth();
        }
        if ($today->greaterThan($to)) {
            $to = $today->endOfMonth()->startOfDay();
        }
```

代わりに `$scale = new GanttScale($from, $to);` の直前へコメントを置く:

```php
        // ⚠ **今日が範囲外でも軸を伸ばさない**（2026-09-03 の設計書 §2 D8）。
        //   伸ばすとボードと規則が食い違い、完了案件のカードが空白だらけになる。
        //   今日が外なら todayPct が null になり、Blade が今日線を描かない。
```

`return [` の配列に 3 つ足す（`'months' => …` の直前）:

```php
            // ⚠ from / to / trackWidthPx はボードの `axis` と対称にしておく。
            //   テストが軸を直接見られるようにするため（HTML だけでは月ヘッダから逆算になる）。
            'from'          => $scale->from()->toDateString(),
            'to'            => $scale->to()->toDateString(),
            'trackWidthPx'  => $scale->trackWidthPx(),
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
./vendor/bin/phpunit tests/Feature/Schedule/ScheduleCardAxisTest.php 2>&1 | tail -5
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: どちらも `OK`（+2）

- [ ] **Step 5: コミット**

```bash
git add app/Services/ScheduleCardService.php tests/Feature/Schedule/ScheduleCardAxisTest.php
git commit -m "$(cat <<'EOF'
feat(schedule): 詳細カードの軸から「今日まで伸ばす」を外す

設計書 §2 D8。ボードと規則を揃える。今日が軸の外なら今日線を描かないだけにする。

併せて gantt に from / to / trackWidthPx を足し、ボードの axis と対称にした
（テストが軸を直接見られるようにするため）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: カードのガントを 1 ヶ月 150px 固定にする（D10）

**Files:**
- Modify: `resources/views/_partials/_schedule_gantt.blade.php`
- Modify: `tests/Feature/Schedule/ScheduleDateStateTest.php:242-263`
- Modify: `tests/Feature/Schedule/ScheduleCardAxisTest.php`

- [ ] **Step 1: 既存テストのリテラルを直す**

`tests/Feature/Schedule/ScheduleDateStateTest.php` の
`test_the_label_column_cannot_be_pushed_wider_than_its_track` は
`flex: 0 0 262px;` を**リテラルで走査**している。次に置き換える:

```php
        preg_match_all('/flex: 0 0 var\(--gantt-label-w\);([^"]*)"/', $html, $m);
```

docblock の冒頭に一文を足す:

```
     * ⚠ **262px は 2026-09-03 に CSS 変数 `--gantt-label-w` へ移した**（設計書 §4.2）。
     *   px の値は `_schedule_gantt_style.blade.php` が持つ。ここで見るのは
     *   「ラベル欄が中身に押し広げられないこと」（min-width: 0 / overflow: hidden）。
```

⚠ **件数 4 のアサートと `min-width: 0` / `overflow: hidden` の検査はそのまま残す。**
そこが Bug #29 の本体。

- [ ] **Step 2: カードの幅と固定表示のテストを足す**

`tests/Feature/Schedule/ScheduleCardAxisTest.php` の末尾に足す:

```php
    /**
     * カードも「ラベル幅 + 月数 × 150px」（設計書 §2 D10）。
     *
     * ⚠ 工程 2026-05-11〜2026-06-05 に ±1 ヶ月のパディングで
     *   2026-04-01 〜 2026-07-31 の 4 ヶ月 = 600px。
     */
    public function test_the_card_track_is_as_wide_as_the_months_it_spans(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $this->assertSame(600, $response->viewData('schedule')['gantt']['trackWidthPx']);

        $html = $response->getContent();
        $this->assertStringContainsString('width: calc(var(--gantt-label-w) + 600px)', $html);
        $this->assertStringNotContainsString('min-width: 940px', $html, '旧い固定 min-width が残っている');
        $this->assertStringContainsString('class="gantt-scroll gantt-scroll--card"', $html, 'カード用のクラスが無い');
    }

    /**
     * 工程名の列が固定表示になっていること。
     *
     * ⚠ **件数の下限も固定する**（Bug #45）。このフィクスチャ（工程 1 行・◆ 0 件）では
     *   月ヘッダ 1 + 行 1 の 2 個。◆ が増えると「節目」のラベル欄も 1 個増える。
     */
    public function test_the_step_name_column_is_sticky(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        preg_match_all('/flex: 0 0 var\(--gantt-label-w\);/', $html, $m);
        $this->assertCount(2, $m[0], 'ラベル欄の数が想定と違う（月ヘッダ 1 + 行 1）');

        $this->assertStringContainsString('class="gantt-label gantt-label--head"', $html);
        $this->assertStringNotContainsString('flex: 0 0 262px', $html, 'px 直書きが残っている');
    }

    /**
     * ⚠ **カードには初期スクロールを入れない**（設計書 §2 D11 / §7.2）。
     *   Ajax 保存のたびにガントを差し替えるので、毎回今日へ跳ぶ画面になる。
     */
    public function test_the_card_has_no_scroll_script(): void
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

        $this->assertStringNotContainsString('scheduleBoardScrollToToday', $html);
    }
```

- [ ] **Step 3: 失敗を確認する**

```bash
./vendor/bin/phpunit tests/Feature/Schedule/ScheduleCardAxisTest.php tests/Feature/Schedule/ScheduleDateStateTest.php 2>&1 | tail -20
```

期待: 新規 3 本と `test_the_label_column_cannot_be_pushed_wider_than_its_track` が FAIL

- [ ] **Step 4: 実装する**

`resources/views/_partials/_schedule_gantt.blade.php`:

1. `<div id="schedule-gantt">` の**直後**に足す:

```blade
@include('_partials._schedule_gantt_style')
```

2. 33〜34 行目の 2 行を置き換える:

```blade
        <div class="gantt-scroll gantt-scroll--card" style="overflow-x: auto;">
            <div style="width: calc(var(--gantt-label-w) + {{ $g['trackWidthPx'] }}px);">
```

3. **4 箇所**の `flex: 0 0 262px;` をすべて置き換える（月ヘッダ / 節目 / 工程の行）。
   月ヘッダ（38 行目）だけ `gantt-label--head` を付ける:

```blade
                    <div class="gantt-label gantt-label--head" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">工程</div>
```

節目の行（55 行目）:

```blade
                        <div class="gantt-label" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 12.5px; color: #6B7280;">節目</div>
```

工程の行（78 行目。直前の docblock コメントは**残す**):

```blade
                        <div class="gantt-label" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; color: #111827;">
```

⚠ 行の背景（`$loop->odd ? 'background: #FCFCFD;'`）は**行の div** に付いているが、
`.gantt-label` は `background: #fff` を持つので**縞模様がラベル欄で切れる**。
実ブラウザ確認（Task 10）で見て気になるようなら、`.gantt-label` の背景を
`background: inherit;` に変えて `_schedule_gantt_style.blade.php` の該当行だけ直す。
⚠ **その場合はボードのヘッダ（`#F9FAFB`）が透けないよう `--head` 側の背景は残すこと。**

- [ ] **Step 5: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -5
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: どちらも `OK`（+3）

- [ ] **Step 6: コミット**

```bash
git add resources/views/_partials/_schedule_gantt.blade.php tests/Feature/Schedule/
git commit -m "$(cat <<'EOF'
feat(schedule): 詳細カードのガントも 1 ヶ月 150px 固定にして工程名の列を貼り付ける

設計書 §2 D10。min-width: 940px を calc(var(--gantt-label-w) + 月数×150px) に置き換える。

⚠ ScheduleDateStateTest が flex: 0 0 262px; をリテラルで走査していたので
正規表現を var(--gantt-label-w) へ更新した。件数 4 と min-width: 0 /
overflow: hidden の検査（Bug #29 の本体）はそのまま残している。

カードには初期スクロールを入れない（Ajax 差し替えのたびに跳ぶため）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: 変異テストで検出力を測る

**Files:** なし（測るだけ。結果はこのプランに追記する）

⚠ **作法（Bug #44 / #50）:**
①**先にコミットしておく** ②各変異の**前**に `git status --porcelain` が空であることを確認
③`git diff --stat` が**非空**であることで着弾を確認（当たっていない変異を「検出しない」と誤読する事故が実際に起きている）
④`git checkout -- <file>` で戻す ⑤**赤/緑でなく落ちた理由の文言**を突き合わせる

- [ ] **Step 1: 変異を 1 つずつ当てて記録する**

各行について次を実行する（`<file>` は変異したファイル）:

```bash
git status --porcelain          # 空であること
# ここで変異を当てる
git diff --stat                 # 非空であること（着弾の確認）
./vendor/bin/phpunit --filter 'Schedule' 2>&1 | tail -20
git checkout -- <file>
```

| # | 変異 | 対象 | 期待して落ちるテスト |
|---|---|---|---|
| M1 | `MONTH_WIDTH_PX` を `150` → `120` | `GanttScale.php` | `GanttScaleTest` ＋ 幅のテスト |
| M2 | `monthCount()` の `+ 1` を消す | `GanttScale.php` | `GanttScaleTest`（幅のテスト複数本）|
| M2b | `monthCount()` の `max(1, ...)` を外す | `GanttScale.php` | `test_a_reversed_range_never_yields_a_negative_width`（**実測済み**: `Failed asserting that -2 is identical to 1.`）|
| M3 | `monthCount()` の `($this->to->year - $this->from->year) * 12` を消す | `GanttScale.php` | `test_a_range_that_crosses_a_year_counts_correctly` |
| M4 | `scale()` の `$k['owner']->autoMilestones()` のループを消す | `ScheduleBoardService.php` | `test_the_axis_includes_the_auto_milestones` |
| M5 | `scale()` の `drawEnd($today)` を `$step->planned_end` に替える | 同上 | 軸の `to` を見るテスト |
| M6 | `scale()` を「全件から軸を出す」（`$kept` でなく全案件）に戻す | 同上 | `test_the_axis_follows_the_filter` |
| M7 | `scale()` に「今日が範囲外なら伸ばす」を足す | 同上 | `test_a_past_only_board_draws_no_today_marker_and_does_not_stretch_the_axis` |
| M8 | `scale()` の `$dates === []` フォールバックを消す | 同上 | `test_a_board_of_undated_steps_falls_back_to_the_current_month`（例外で赤になるので**理由の文言**を確認） |
| M9 | `build()` の戻り値に `'kpi' => []` を足す | 同上 | `test_the_boards_no_longer_show_kpi_cards` |
| M10 | Blade に `<select name="zoom">` を戻す | `_schedule_board.blade.php` | `test_the_zoom_selector_is_gone_and_the_query_key_is_ignored` |
| M11 | `unregisteredCount` の `@if` ブロックを消す | 同上 | `test_the_unregistered_count_line_survives` |
| M12 | `class="gantt-label"` を消す（style はそのまま） | 同上 | `test_the_case_name_column_is_sticky_and_sized_by_the_css_variable` |
| M13 | `width: calc(...)` を `min-width: 1000px` に戻す | 同上 | `test_the_track_is_as_wide_as_the_months_it_spans` |
| M14 | `scheduleBoardScrollToToday(...)` の**呼び出し行だけ**消す | 同上 | `test_the_board_scrolls_to_today_on_open` |
| M15 | 同じく**関数の定義だけ**消す（呼び出しは残す） | 同上 | 同上（⚠ 片方だけで緑になったらテストが弱い。Bug #28） |
| M16 | `@include('_partials._schedule_gantt_style')` をボードから消す | 同上 | `test_the_gantt_style_partial_is_rendered_with_the_label_width_variables` |
| M17 | CSS partial の `@media` を `.gantt-scroll--card` の**前**へ移す | `_schedule_gantt_style.blade.php` | 同上（位置関係のアサート） |
| M18 | CSS partial の `--gantt-label-w: 320px` を `300px` に | 同上 | 同上 |
| M19 | カードの force-today を戻す | `ScheduleCardService.php` | `test_the_card_axis_is_not_stretched_to_today` |
| M20 | カードの `width: calc(...)` を `min-width: 940px` に戻す | `_schedule_gantt.blade.php` | `test_the_card_track_is_as_wide_as_the_months_it_spans` |
| M21 | カードの工程の行だけ `flex: 0 0 262px` に戻す | 同上 | `test_the_step_name_column_is_sticky`（件数 2 → 1）＋ `ScheduleDateStateTest`（件数 4 → 3） |
| M22 | カードにスクロールのスクリプトを足す | 同上 | `test_the_card_has_no_scroll_script` |
| M23 | `meta()` の `'status'` を常に `STATUS_RUNNING` にする | `ScheduleBoardService.php` | 既存の絞り込みテスト（3 パス化で壊れていないことの確認） |
| M24 | `position()` の `'ring'` を常に `false` にする | 同上 | `ScheduleDateStateTest` / ボードの輪郭テスト |

- [ ] **Step 2: 未検出があればテストを足す**

⚠ **未検出は「テストが弱い」ことの発見であって、変異を消してよい理由ではない。**
落ちなかった変異には**テストを足してから**、もう一度当てて赤になることを確認する。

- [ ] **Step 3: 結果をプランに追記してコミット**

このファイルの末尾に「## 変異テストの実測結果」を作り、24 行の表（変異 / 着弾 / 落ちたテスト / 落ちた理由の文言）を書く。

```bash
git add docs/superpowers/plans/2026-09-03-schedule-board-gantt.md
git commit -m "$(cat <<'EOF'
docs(plan): 工程表ボードのガント改修の変異テスト実測結果を残す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: コンパイル済みビューの lint と実ブラウザ確認

**Files:** なし（検証のみ）

- [ ] **Step 1: 全テストを走らせる**

```bash
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: `OK`。Task 0 のベースラインから **+13**。内訳:

| | 本数 |
|---|---|
| 削除 | 11（Task 2 の 8 ＋ Task 3 の 2 ＋ Task 4 で 1 本を 5 本に置き換えた分の 1）|
| 追加 | 24（Task 1: 6 / Task 2: 2 / Task 3: 1 / Task 4: 5 / Task 5: 3 / Task 6: 2 / Task 7: 2 / Task 8: 3）|
| 差引 | **+13** |

⚠ `ScheduleRealEstateUntouchedTest` と `ScheduleDateStateTest` は**その場で書き換える**ので本数は変わらない。
⚠ **実測が +13（= 1296 tests）でなければ、どこかで削除・追加を取りこぼしている。** 数が合うまで進めない。

- [ ] **Step 2: コンパイル済みビューを全数 lint する**

⚠ `view:cache` の成功表示だけでは足りない（compiled PHP を lint しない。Bug #21 / #26 / #30）:

```bash
php artisan view:cache \
  && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done \
  && ls storage/framework/views/*.php | wc -l \
  && php artisan view:clear
```

期待: `INVALID:` が **0 行**。ビューの本数を記録する。

- [ ] **Step 3: 使い捨て SQLite ＋ 開発サーバを立てる**

⚠ **2026-09-02 のプラン（`2026-09-02-housing-schedule-current-state.md` Task 11 Step 3）で
実証済みの手順をそのまま使う。** 落とし穴も同じ:

- **`artisan serve` は終わらないプロセス。必ずバックグラウンドで起動する。**
  先にポートの空きを見る: `lsof -nP -iTCP:8123 -sTCP:LISTEN`
- **`User::create([... 'role' => 'executive' ...])` は効かない。**
  `role` / `status` は `$fillable` から外されており黙って捨てられて staff になり、
  `/housing/*` が **403** になる。**作成後に明示代入する。**
- **`storage/` は gitignore されていない。** 置いた sqlite / php は**あとで必ず消す。**

`storage/verify-seed.php` を作る（`re_*` / `hs_*` は raw SQL 管理で migration に無いものが
あるので、テスト用 trait の DDL を使う）:

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
//    （tests/Concerns/CreatesRealEstateSchema.php）。同じものを流す。
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
// ⚠ 本番の JG西長戸4号地 は 64 工程・2026-02-19〜2026-09-27・うち 35 件が 1 日。
//    全量はモック docs/mockups/housing/schedule-board-gantt.html の REAL 配列にある。
//    ここでは**軸の月数（8）と 1 日の工程の存在**が測れればよいので、代表を並べる。
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

// ---- 不動産も 1 件（遅延バッジが従来どおり出ることの確認用） ----
$proc = ReProcurement::create([
    'procurement_code' => 'PRC-001', 'property_type' => 'used_house',
    'transaction_type' => 'purchase', 'status' => 'contracted',
    'property_name' => '井門町 更地', 'address' => '愛媛県松山市', 'created_by' => $u->id,
]);
$proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1]);
$proc->scheduleSteps()->create(['name' => '造成', 'category' => 'work',   'planned_start' => '2026-07-01', 'planned_end' => '2026-09-30', 'sort_order' => 2]);

echo "seeded: property={$prop->id} procurement={$proc->id} user={$u->email} / password\n";
```

```bash
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export DB_CONNECTION=sqlite
export DB_DATABASE="$(pwd)/storage/verify.sqlite"
: > "$DB_DATABASE"
php storage/verify-seed.php
lsof -nP -iTCP:8123 -sTCP:LISTEN     # 空いていること
```

サーバは `.claude/launch.json` の `laravel-verify`（ポート 8123）を
**Browser pane の preview_start でバックグラウンド起動**する（Bash の `artisan serve` を使わない）。

⚠ `preview_start` は `.env` を読むので、上の環境変数はサーバプロセスに渡らない。
`.env` が無い worktree では `.env` を一時的に作る:

```bash
printf 'APP_KEY=%s\nAPP_ENV=local\nAPP_DEBUG=true\nDB_CONNECTION=sqlite\nDB_DATABASE=%s\nAPP_URL=http://localhost:8123\n' \
  "$APP_KEY" "$DB_DATABASE" > .env
```

⚠ **この `.env` も検証後に必ず消す**（`rm -f .env`）。worktree に `.env` が残ると
以降のテストが実 DB を向く事故につながる。

- [ ] **Step 4: 4 画面 × 3 幅 = 12 通りで `main` の横スクロールを測る**

⚠ **Bug #29 は超過幅が一定なので、片方の幅だけでは判定できない。**

対象画面: `/housing/schedules` / `/realestate/schedules` / 建売物件の詳細 / 注文住宅の詳細
幅: **1800 / 1200 / 375px**

各組み合わせでブラウザのコンソールから測る:

```js
(function () { var m = document.querySelector('main'); return [m.scrollWidth, m.clientWidth]; })()
```

期待: 12 通りすべてで `scrollWidth === clientWidth`

- [ ] **Step 5: ガント自体の実測を取る**

ボード（1200px）で:

```js
(function () {
  var el = document.getElementById('schedule-board-scroller');
  var bars = document.querySelectorAll('#schedule-board-scroller [title]');
  var w = [];
  bars.forEach(function (b) { w.push(Math.round(b.getBoundingClientRect().width * 100) / 100); });
  w.sort(function (a, b) { return a - b; });
  return {
    scrollLeft: el.scrollLeft,
    clientWidth: el.clientWidth,
    scrollWidth: el.scrollWidth,
    labelW: getComputedStyle(el).getPropertyValue('--gantt-label-w'),
    narrowestBar: w[0],
    bars: w.length
  };
})()
```

期待:
- `labelW` が `320px`
- `narrowestBar` が **約 4.9px**（1 日の工程。現状は 1〜1.5px だった）
- `scrollLeft` が **0 より大きい**（今日までスクロールしている）
- `scrollWidth > clientWidth`（横スクロールが出ている）

- [ ] **Step 6: 固定表示が実際に効いているか見る**

⚠ **`position: sticky` は HTML に出ていても効かないことがある**（間に `overflow: hidden` の
祖先が入る等）。**実際にスクロールさせて測る**:

```js
(function () {
  var el = document.getElementById('schedule-board-scroller');
  var label = el.querySelector('.gantt-label:not(.gantt-label--head)');
  var before = label.getBoundingClientRect().left;
  el.scrollLeft = 300;
  var after = label.getBoundingClientRect().left;
  return { before: Math.round(before), after: Math.round(after), stuck: Math.abs(before - after) < 1 };
})()
```

期待: `stuck: true`（スクロールしても案件名の左端が動かない）

- [ ] **Step 7: 375px での見え方を測る**

```js
(function () {
  var el = document.getElementById('schedule-board-scroller');
  var labelW = parseFloat(getComputedStyle(el).getPropertyValue('--gantt-label-w'));
  return { labelW: labelW, visibleAxis: Math.round(el.clientWidth - labelW) };
})()
```

期待: `labelW: 140`、`visibleAxis` が **約 200px**

- [ ] **Step 8: カードのガントを見る**

建売物件の詳細で:
- 工程名の列が固定表示（Step 6 と同じ方法で `.gantt-scroll--card` に対して測る）
- 1 日の工程が約 4.9px
- **工程を 1 件保存して Ajax 差し替えが動く**こと（棒の本数が増え、ガントが描き直される）
- ⚠ **保存後にスクロール位置が今日へ跳ばない**こと（D11）
- ⚠ 縞模様（`$loop->odd`）がラベル欄で切れて見苦しくないか（Task 8 Step 4 の注記）

- [ ] **Step 9: コンソール出力を確認する**

期待: 4 画面とも**出力 0 件**

- [ ] **Step 10: 結果をプランに追記してコミット**

このファイルの末尾に「## 実ブラウザ確認の実測結果」を作り、Step 4〜9 の実測値を書く。

```bash
git add docs/superpowers/plans/2026-09-03-schedule-board-gantt.md
git commit -m "$(cat <<'EOF'
docs(plan): 工程表ボードのガント改修のローカル実ブラウザ確認の実測を残す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

⚠ **後始末を必ずやる**（`storage/` は gitignore されていない）:

```bash
rm -f storage/verify.sqlite storage/verify-seed.php .env
git status --porcelain     # 空であること
```

### 10 の実測結果（2026-09-03 実施）

**Step 1**: `OK (1304 tests, 8498 assertions)`。

**Step 2**: `php artisan view:cache` → **267 本 / INVALID 0 件**。

⚠ **想定していた `preview_start`（`.claude/launch.json` の `laravel-verify`）は使えなかった。**
実測すると、このサブエージェントは cwd が main repo に pinned されており、
`preview_start` は **main repo の `.claude/launch.json`**（既存の `laravel-verify` エントリ、
`runtimeExecutable: "php"`, cwd=main repo）を解決した。1 回目の起動でこれを踏み、
**main repo の実 `.env`（実 MySQL `masa8787kanri63732`）へ接続する 500 画面**が出た
（`SELECT` 系の GET 1 回のみで書き込みは無し。即座に `preview_stop` で停止）。
`EnterWorktree(path=…)` も「cwd が worktree の外（＝main repo）にあるサブエージェントからの
切替は不可」で拒否された。**main repo の `.claude/launch.json` は変更しない**（作業禁止指示を優先）。
代わりに `php artisan serve --port=8123` を **Bash の `run_in_background`** で
worktree 内・env 変数直渡し（`.env` ファイルは作らない。作成しようとした最初の 2 回は
パーミッションでハードブロックされた——`.env` は本プロジェクトの安全装置で
`ls -la .env` すら拒否される）で起動し、`mcp__Claude_Browser__navigate`（`preview_start` の
launch.json 解決を経由しない）で接続した。⚠ **worktree に `public/build` が無く初回は
Vite manifest 未検出の 500** だったため、main repo の `node_modules/.bin/vite build` を
cwd=worktree で実行して解決（RULES.md「Tailwind 監査の落とし穴 1」と同型の対処）。
検証後は `kill` でサーバーを停止し、`storage/verify.sqlite` `storage/verify-seed.php` と
worktree に作った（結局使われなかった）`.claude/launch.json` を削除、`public/build` も削除して
worktree を元の状態に戻した。`.env` は最後まで一度も作成されていない（`ls` で確認済み）。

**Step 4（5-1 相当）: 4 画面 × 1800 / 1200 / 375px = 12 通り**——**全通り `scrollWidth === clientWidth`**:

| 画面 | 1800px | 1200px | 375px |
|---|---|---|---|
| `/housing/schedules` | 1580 / 1580 | 980 / 980 | 375 / 375 |
| `/realestate/schedules`（`?status=all`）| 1580 / 1580 | 980 / 980 | 375 / 375 |
| `/housing/properties/{id}` | 1580 / 1580 | 980 / 980 | 375 / 375 |
| `/housing/custom-orders/{id}` | 1580 / 1580 | 980 / 980 | 375 / 375 |

⚠ 不動産ボードは既定の「ステータス: 進行中」だと 0 件表示だった
（種のシード工程が判定上「遅延」扱いになり「進行中」に含まれないだけで、
`?status=all` では正しく表示される。**Task 10 のスコープ外の絞り込みロジック**なので未調査・未修正）。

**Step 5（5-2 相当）: ガント実測（`/housing/schedules`、1200px）**——

```json
{
  "scrollLeft": 386, "clientWidth": 914, "scrollWidth": 1520,
  "labelW": "320px", "narrowestBar": 4.95, "widestBar": 302.48, "bars": 13
}
```

期待どおり全項目一致（`labelW: 320px` / `narrowestBar` 約 4.9px＝改修前の 1〜1.5px から拡大 /
`scrollLeft > 0`＝今日までスクロール済み / `scrollWidth(1520) > clientWidth(914)`＝横スクロールあり）。

**Step 6（5-3 相当）: 固定表示の実測**——スクロールさせて測定:
`{ before: 253, after: 253, stuck: true }`（`scrollLeft` を 386→300 に変えてもラベル左端は不動）。
併せて **z-index の実測**: 最大スクロール位置（`scrollLeft=606`）でラベルと棒が重なる座標を
`document.elementFromPoint()` で叩き、返った最前面要素が `.gantt-label` 自身であることを確認
（`isLabelOrDescendant: true`）——ラベルが棒の上に来ることを実行時に確認済み。

**Step 7（5-4 相当）: 375px（`/housing/schedules`）**——
`{ labelW: 140, visibleAxis: 201, elClientWidth: 341 }`。期待どおり（`labelW: 140` / 約 200px）。

**Step 8（5-5 相当）: カードのガント（`/housing/properties/{id}`、1200px）**——

- 固定表示: `.gantt-scroll--card` で同じ方法により `{ before: 274, after: 274, stuck: true }`
- 1 日の工程: `narrowestBar: 4.93px`（13 本中 6 本が該当。期待の約 4.9px と一致）
- 縞模様: `.schedule-gantt-track` の奇数行で `rowBg` と対応する `.gantt-label` の `labelBg` が
  **完全一致**（`rgb(252, 252, 253)` = `#FCFCFD`）することを 7 行で確認——ラベル欄で縞が途切れていない。
  偶数行は両方とも実質白（row は透過・label は共有 CSS の `#fff`）で視覚的に無矛盾
- **Ajax 差し替え**: 「＋ 工程を追加」→ サーバが `id` を発番した新規行が Alpine の `rows` に載ることを確認
  （POST 往復が実際に起きている）。続けてその行に `planned_start`/`planned_end` を設定し
  `save(row)` を直接呼んで PATCH 往復を実行した結果:
  `{ nodeReplaced: true, barsBefore: 13, barsAfter: 14, message: "保存しました。" }`——
  `#schedule-gantt` の outerHTML 差し替えが実際に起き、棒が 13→14 本に増えた
- **スクロール位置**: 差し替え前に `.gantt-scroll--card.scrollLeft = 300` にしてから保存し、
  差し替え後のスクローラで `scrollAfter: 0` を確認。**「今日」の位置（軸右寄り）へジャンプしていない**
  ——カード側に scroll-to-today のスクリプトが無い設計（D11）どおり、単に新しい DOM ノードの
  既定値（0）になっているだけであることを確認した

**Step 9（5-6 相当）: コンソール出力**——最初のタブでは早期に立ち寄った `/dashboard/executive`
（`ms_rooms` 未作成のシード漏れによる 500。検証対象外の寄り道）由来の error が 1 件残留していたため、
**汚染の無い新規タブ**で 4 画面を撮り直した: `housing/schedules` / `realestate/schedules?status=all` /
`housing/properties/{id}` / `housing/custom-orders/{id}` の**すべてで `No console logs.`（0 件）**。

### ⚠ 確認できなかったこと

- **`preview_start` 経由の起動は未検証**（このサブエージェントの cwd 制約により Bash 直接起動に切り替えたため）。
  ただし到達した実体（worktree のコード・throwaway SQLite・Vite ビルド済み CSS/JS）は
  プランが要求するものと同一で、`preview_start` はブラウザタブを開く手段の違いに過ぎない
- **不動産ボードの既定フィルタ（`status` 未指定）で件数 0 件になる件**は未調査（Task 10 の
  スコープ外。工程の遅延判定ロジックの話で、今回の幅改修とは無関係と判断した）
- 本番の `view:cache` コンパイル・本番ブラウザでの目視は**未実施**（デプロイ後に別途必要。
  Bug #21 / #26 の前例どおり）

**Files:**
- Modify: `docs/BACKLOG.md`
- Modify: `docs/superpowers/specs/2026-09-03-schedule-board-gantt-design.md`（実装で分かったことがあれば）

- [ ] **Step 1: `BACKLOG.md` に節を足す**

「## ✅ 工程表を『現状の工程』に寄せる（住宅事業）— 本番反映済み」の節の**直後**、
「## バックログ完了状況」の**手前**に足す。⚠ **本番反映するまで「本番稼働中」と書かない。**

雛形（`N` / `実測値` は Task 0〜10 の実測で埋める）:

```markdown
## ✅ 工程表ボードのガントを読めるようにする — 本番未反映

詳細仕様: @docs/superpowers/specs/2026-09-03-schedule-board-gantt-design.md
実装計画: @docs/superpowers/plans/2026-09-03-schedule-board-gantt.md
モック: @docs/mockups/housing/schedule-board-gantt.html

利用者の依頼（2026-09-03）は 3 つ —— ①**KPI カードは不要** ②**ガントの初期表示を 4 ヶ月に**
（横に広がりすぎて月の間隔が狭く非常に見にくい）③**横スクロールできるように**。
**DB 変更・ルート変更なし。**

| 区分 | 実装内容 |
|------|---------|
| Support | `GanttScale` に `MONTH_WIDTH_PX = 150` / `monthCount()` / `trackWidthPx()` を追加（**位置(%) の計算は不変**）|
| Service | `ScheduleBoardService::build()` を 3 パス化（絞り込み → 軸 → 位置）。KPI・ズームを削除 ／ `ScheduleCardService` の force-today を削除 |
| Blade | `_schedule_gantt_style.blade.php` を新設（CSS の唯一の定義）＋ ボード / カードの 2 partial を改修 |
| ルート / DB | **どちらも変更なし** |
| テスト | 実測値 → 実測値 tests / 実測値 assertions green |

### 主な変更

- **KPI カードを両ボードとも削除**（D1）。「工程が未登録の案件が N 件」の行は残す（D2）
- **軸をデータの範囲に**（D3）。19 ヶ月のうち 12 ヶ月が空白だった状態を解消
- **1 ヶ月 = 150px の固定値**（D4）。⚠ 「4 ヶ月」を JS で画面幅から算出**しない** ——
  固定にすることで **1 日の工程が 375px でも PC でも同じ約 4.9px**（現状は 1〜1.5px）
- **案件名の列を固定表示**（D5）。幅は PC 320px / カード 262px / 640px 未満は 140px（D6）。
  ⚠ **px は CSS 変数だけが持ち PHP は知らない**
- **ズームセレクタを削除**（D7）。既定の「今日の 6 ヶ月前〜12 ヶ月後」が見にくさの原因だった
- **今日が軸の外なら今日線を描かない**（D8）。カードの「今日まで伸ばす」も外して規則を揃えた
- **ボードは開いた直後に今日までスクロール**（D9）。カードには入れない（Ajax 差し替えのたびに跳ぶ）（D11）
- **詳細カードも同じ幅の規則**（D10）。1 日の工程が 2.79px → 約 4.9px

### ⚠ 実装中に分かったこと

（Task 9 / 10 で出たものをここに書く。無ければ「特筆すべき欠陥は出なかった」と書く）

### 検証

- 全テスト 実測値 → 実測値（+実測値）
- **変異 24 通りを実測**（検出 実測値 / 未検出 実測値 とその対処）
- **コンパイル済みビュー 実測値 本を `php -l`** → INVALID 0 件
- 実ブラウザ（使い捨て SQLite ＋ 開発サーバ）: **4 画面 × 1800 / 1200 / 375px = 12 通り**で
  `main.scrollWidth === main.clientWidth`。1 日の工程の実測幅 実測値px。
  固定表示はスクロールさせて実測（HTML に出ていても効かないことがある）。コンソール出力 0 件
```

- [ ] **Step 2: 設計書に実装時の訂正を追記する（あれば）**

Task 1〜10 で設計書と実測が食い違った点があれば、設計書の該当箇所に
「⚠ 実装時の実測で訂正」として追記する（削除ではなく追記。判断の履歴を残す）。

- [ ] **Step 3: コミット**

```bash
git add docs/
git commit -m "$(cat <<'EOF'
docs: 工程表ボードのガント改修を BACKLOG に記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 4: 完了報告**

利用者へ次を報告する:

- 全テストの実測件数（ベースライン → 変更後）
- 変異 24 通りの検出結果（検出 / 未検出とその対処）
- 実ブラウザ 12 通りの結果と 1 日の工程の実測幅
- **本番反映の手順**: DB 変更・ルート変更・`composer` の変更は**無い**ので、
  main repo で `git checkout 13.x && git merge --ff-only schedule-board-gantt` →
  `./deploy.sh`（`view:cache` の再生成が要る）
- ⚠ **本番反映は利用者の明示的な承認を得てから**（自動モードの分類器がブロックする）

---

## 変異テストの実測結果

**実施日**: 2026-09-03。**環境**: worktree `.claude/worktrees/schedule-board-gantt`（`a409b0d7`、着手時 clean）。
**手順**（Bug #44 / #50 の作法どおり）: 各変異について
①`git status --porcelain` が空であることを確認 →
②変異を当てる →
③`git diff --stat` が非空であることで着弾を確認 →
④`APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter 'Schedule|Gantt'`
（ベースライン 221 tests / 1669 assertions、全緑）で判定 →
⑤落ちた理由の文言をそのまま記録 →
⑥`git checkout -- <file>` で戻し、`git status --porcelain` が再び空であることを確認。

**M1〜M24 ＋ M2b の 25 行は全件、本セッションで実際にコードへ当てて実行した**（引き継ぎメモの記述を
転記したものではない）。加えて、引き継ぎメモが「Task 1〜8 で実測済み」と述べていた行のうち
具体的な内容を特定できたものは、コミットメッセージ／ソースコード中の実測注記で裏取りした
（表2）。さらに、引き継ぎメモにあって裏取りできなかった記述のうち再現可能なものは、
このセッションで追加に実行して確認した（表3）。

全 31 通り（M 25 ＋ 表3 6 通り）を戻したあとの最終状態は
`./vendor/bin/phpunit`（フィルタ無し）で **OK (1304 tests, 8498 assertions)** ——
着手前の実測値と完全に一致しており、取りこぼし・戻し忘れが無いことを確認済み。

### ⚠ 着手前に判明した食い違い（引き継ぎメモ vs プランの実物）

1. **M7 の内容が引き継ぎメモとプランの実物で異なっていた。** 引き継ぎメモの M7
   （「`scale()` のフォールバックを消す｜検出 8 本｜`ValueError: min(): Argument #1 ($value)
   must contain at least one element`」）は、**プランの実物では M8 の内容**
   （`$dates === []` フォールバックの削除）そのものだった。プランの実物の M7 は
   「`scale()` に『今日が範囲外なら伸ばす』を足す」で、これは引き継ぎメモに無かった内容。
   このセッションでは **M7・M8 の両方を実際に当てて実測**し、どちらも検出を確認した
   （下表）ので、番号のズレによる実害（検出漏れの見落とし）は無かった。
2. **具体的な失敗文言・件数が、いくつかの行で引き継ぎメモと実測値で異なっていた**
   （M3 / M17 / M19 / M21）。**判定（検出/未検出）が覆った行は無い**——すべて「検出」で
   一致しており、差異は文言中の具体的な数値（フィクスチャの組み方や実装の当て方の違いに
   由来すると見られる）にとどまる。
3. **M24 は引き継ぎメモが「ScheduleDateStateTest / ボードの輪郭テスト」の 2 本が検出すると
   述べていたが、実測では ScheduleBoardTest の 1 本のみが検出した。** `ScheduleDateStateTest`
   が見ている `ring` は **詳細カード側**（`ScheduleCardService::row()`）の実装で、
   `ScheduleBoardService::position()` とは別の独立した実装であり、ボード側だけを変異させても
   カード側のテストは無関係のため反応しない。判定（検出）自体は変わらないが、
   「どのテストが守っているか」の理解は訂正が要る。

### 表1: M1〜M24 ＋ M2b（25 件・全件フレッシュに実測）

| # | 変異 | 対象 | 着弾 | Failures/221 | 検出したテスト（代表） | 落ちた理由の文言（代表） | 判定 |
|---|---|---|:--:|:--:|---|---|---|
| M1 | `MONTH_WIDTH_PX` を `150`→`120` | GanttScale.php | ✅ | 10 | `GanttScaleTest::test_a_single_day_range_is_still_one_month_wide` 他9本 | `Failed asserting that 120 is identical to 150.` | 検出 |
| M2 | `monthCount()` の `+ 1` を消す | GanttScale.php | ✅ | 7 | `GanttScaleTest::test_the_production_range_is_eight_months` 他6本 | `Failed asserting that 7 is identical to 8.` | 検出 |
| M2b | `monthCount()` の `max(1, ...)` を外す | GanttScale.php | ✅ | 1 | `test_a_reversed_range_never_yields_a_negative_width` | `Failed asserting that -2 is identical to 1.` | 検出 |
| M3 | `monthCount()` の `($this->to->year - $this->from->year) * 12` を消す | GanttScale.php | ✅ | 2 | `test_a_range_that_crosses_a_year_counts_correctly` ＋ `test_a_reversed_range_never_yields_a_negative_width` | `Failed asserting that 1 is identical to 4.`（引き継ぎメモは `-8` と記録していたが実測は `1`。判定への影響なし） | 検出 |
| M4 | `scale()` の `autoMilestones()` ループを消す | ScheduleBoardService.php | ✅ | 1 | `test_the_axis_includes_the_auto_milestones` | `着工予定日が軸に入っていない` ／ `-'2026-02-01' +'2026-05-01'` | 検出 |
| M5 | `scale()` の `drawEnd($today)` を `$step->planned_end` に替える | ScheduleBoardService.php | ✅ | 1 | `test_the_axis_uses_draw_end_not_the_raw_planned_end_for_running_steps` | `軸の終わりが今日（drawEnd）でなく、まだ先の planned_end になっている` ／ `-'2026-08-31' +'2027-06-30'` | 検出 |
| M6 | `scale()` を絞り込み前の全件から出す | ScheduleBoardService.php | ✅ | 1 | `test_the_axis_follows_the_filter` | `絞り込み後の案件だけで軸を出していない` ／ `-'2026-05-01' +'2026-01-01'` | 検出 |
| M7 | `scale()` に「今日が範囲外なら伸ばす」を足す（**プランの実物の内容**） | ScheduleBoardService.php | ✅ | 5 | `test_a_past_only_board_draws_no_today_marker_and_does_not_stretch_the_axis` 他4本 | `今日まで軸が伸びている` ／ `-'2026-01-31' +'2026-08-31'` | 検出 |
| M8 | `scale()` の `$dates === []` フォールバックを消す（**プランの実物の内容**。引き継ぎメモは「M7」として記録していた） | ScheduleBoardService.php | ✅ | 8 | `test_a_board_of_undated_steps_falls_back_to_the_current_month` 他7本 | `min(): Argument #1 ($value) must contain at least one element`（未捕捉例外） | 検出 |
| M9 | `build()` の戻り値に `'kpi' => []` を足す | ScheduleBoardService.php | ✅ | 1 | `test_the_boards_no_longer_show_kpi_cards` | `/housing/schedules が kpi を返している` ／ `Failed asserting that an array does not have the key 'kpi'.` | 検出 |
| M10 | Blade に `<select name="zoom">` を戻す | _schedule_board.blade.php | ✅ | 1 | `test_the_zoom_selector_is_gone_and_the_query_key_is_ignored` | `does not contain "name="zoom""` | 検出 |
| M11 | `unregisteredCount` の `@if` ブロックを消す | _schedule_board.blade.php | ✅ | 1 | `test_the_unregistered_count_line_survives` | `contains "工程が未登録の案件が 1 件あります"`（期待した文言が消えて不一致） | 検出 |
| M12 | 行の `class="gantt-label"` を消す（style はそのまま） | _schedule_board.blade.php | ✅ | 1 | `test_the_case_name_column_is_sticky_and_sized_by_the_css_variable` | `行のラベル欄にクラスが無い` | 検出 |
| M13 | `width: calc(...)` を `min-width: 1000px` に戻す | _schedule_board.blade.php | ✅ | 1 | `test_the_track_is_as_wide_as_the_months_it_spans` | `contains "width: calc(var(--gantt-label-w) + 450px)"`（不一致） | 検出 |
| M14 | `scheduleBoardScrollToToday(...)` の**呼び出し行だけ**消す | _schedule_board.blade.php | ✅ | 1 | `test_the_board_scrolls_to_today_on_open` | `contains "scheduleBoardScrollToToday('schedule-board-scroller', 79.738562091503, 750);"`（不一致） | 検出 |
| M15 | 同じく**関数定義だけ**消す（呼び出しは残す） | _schedule_board.blade.php | ✅ | 1 | `test_the_board_scrolls_to_today_on_open`（M14 と同一テストの別アサートが検出） | `contains "function scheduleBoardScrollToToday(id, pct, trackPx)"`（不一致） | 検出 |
| M16 | `@include('_partials._schedule_gantt_style')` をボードから消す | _schedule_board.blade.php | ✅ | 1 | `test_the_gantt_style_partial_is_rendered_with_the_label_width_variables` | `ボードの --gantt-label-w(320px) が無い` | 検出 |
| M17 | CSS partial の `@media` を `.gantt-scroll--card` の**前**へ移す | _schedule_gantt_style.blade.php | ✅ | 1 | `test_the_gantt_style_partial_is_rendered_with_the_label_width_variables` | `メディアクエリが .gantt-scroll--card の実ルールより前にある` ／ `Failed asserting that 301 is greater than 475.`（引き継ぎメモは `1822`/`1936` と記録。位置オフセットは移し方の実装差で変わるため数値自体に意味は無い） | 検出 |
| M18 | CSS partial の `--gantt-label-w: 320px` を `300px` に | _schedule_gantt_style.blade.php | ✅ | 1 | `test_the_gantt_style_partial_is_rendered_with_the_label_width_variables` | `ボードの --gantt-label-w(320px) が無い` | 検出 |
| M19 | カードの force-today を戻す | ScheduleCardService.php | ✅ | 3（引き継ぎメモは「2 本」） | `test_the_card_axis_is_not_stretched_to_today` ＋ `test_every_parent_avoids_stretching_the_axis_to_today` ＋ `test_the_card_track_is_as_wide_as_the_months_it_spans` | `今日まで軸が伸びている` ／ `-'2026-02-28' +...`（トラック幅のテストは `Failed asserting that 900 is identical to 600.`） | 検出 |
| M20 | カードの `width: calc(...)` を `min-width: 940px` に戻す | _schedule_gantt.blade.php | ✅ | 1 | `test_the_card_track_is_as_wide_as_the_months_it_spans` | `contains "width: calc(var(--gantt-label-w) + ...px)"`（不一致） | 検出 |
| M21 | カードの工程の行だけ `flex: 0 0 262px` に戻す | _schedule_gantt.blade.php | ✅ | 4（引き継ぎメモは「2 本 ＋ ScheduleDateStateTest」の計3本相当） | `test_the_step_name_column_is_sticky`（2→1）／`test_the_striping_reaches_into_the_sticky_label_column`（3→0）／`test_the_milestone_row_label_cannot_be_pushed_wider_either`（3→2）／`ScheduleDateStateTest::test_the_label_column_cannot_be_pushed_wider_than_its_track`（4→1） | `ラベル欄の数が想定と違う（月ヘッダ1+行1）` ／ `Failed asserting that actual size 1 matches expected size 2.` | 検出 |
| M22 | カードにスクロールのスクリプトを足す | _schedule_gantt.blade.php | ✅ | 1 | `test_the_card_has_no_scroll_script` | `does not contain "scheduleBoardScrollToToday"`（含まれてしまっている） | 検出 |
| M23 | `meta()` の `'status'` を常に `STATUS_RUNNING` にする | ScheduleBoardService.php | ✅ | 7 | `test_status_is_done_when_every_step_has_finished_even_if_it_was_late` 他6本（`ScheduleRealEstateUntouchedTest` 含む） | `完了は遅延より優先する（決定G）` ／ `Failed asserting that two strings are identical.` | 検出 |
| M24 | `position()` の `'ring'` を常に `false` にする | ScheduleBoardService.php | ✅ | 1 | `test_the_housing_board_puts_a_ring_only_on_the_running_bar`（`ScheduleDateStateTest` はカード側 `ring` を見ており無関係。上記「食い違い③」参照） | `輪郭が付くのは進行中（HS-RUN）の1本だけ` ／ `Failed asserting that actual size 0 matches expected size 1.` | 検出 |

**25 行中 25 行が検出。未検出 0 件。**

### 表2: プランの表に無いが、Task 1〜8 の実装中に実測済みだった変異（出典つき・本セッションでは未再実行）

⚠ この表はコミットメッセージとソースコード中の「実測」注記を実際に読み、file:line で
裏取りしたうえで転記したもので、**このセッションでコードへ当て直してはいない**。

| # | 変異 | 対象 | 出典 | 実測結果の引用 | 判定 |
|---|---|---|---|---|---|
| A1 | `scale()` 末尾の明示 `startOfDay()` 呼び出しを外す | ScheduleBoardService.php:176-178 | ソース内注記 | 「外しても 1288 本すべて緑＝等価変異」（GanttScale のコンストラクタが from/to を必ず startOfDay() するため冗長） | 等価変異 |
| A2 | `headers()` の `$end = ... ? ... : $next;` 三項演算子を外す | ScheduleBoardService.php:423-427 | ソース内注記 | 「この三項演算子を外しても 183 本すべて緑＝同値変異」（`$scale->to()` が現状の構築経路では常に月末に揃うため実質未到達） | 等価変異 |
| A3 | `scale()` が `drawEnd($today)` でなく生の `planned_end` を使う（M5 の初出時点） | ScheduleBoardService.php（Task 4） | commit `dcd51a5a` | 「planned_end に替えても 188 本すべて緑のまま通る状態だった（実測）」→ `test_the_axis_uses_draw_end_not_the_raw_planned_end_for_running_steps` を追加して解消（現 M5 として本セッションでも再確認済み） | 当初検出漏れ→テスト追加で検出 |
| A4 | `headers()` の `label` を `''` に／`strong` を常に `false` に／`widthPct` を正規化前の日数に | ScheduleBoardService.php（Task 3） | commit `94577bbb` | 「3 つとも無防備でいずれかを潰してもフルスイートが緑のまま通る状態だった（実測）」→ `test_the_axis_headers_are_month_labels_with_quarter_emphasis` を追加 | 当初検出漏れ→テスト追加で検出 |
| A5 | ボードのラベル欄（ヘッダ側・行側）の `min-width: 0` / `overflow: hidden` を落とす | _schedule_board.blade.php（Task 5） | commit `749c6190` | 「ヘッダ側・行側のどちらから min-width: 0 を落としても 1292 本すべて緑のまま通る状態だった」 | 当初検出漏れ→テスト追加で検出 |
| A6 | `class="gantt-scroll"`（CSS変数スコープ用クラス）だけを落とす | _schedule_board.blade.php（Task 5） | commit `749c6190` ／ ScheduleBoardTest.php:467 | 「実測 2026-09-03: class だけ落とす変異が 119 本すべて緑だった」 | 当初検出漏れ→テスト追加で検出 |
| A7 | `.gantt-label--head` の `z-index` / `background` を丸ごと消す | _schedule_gantt_style.blade.php（Task 5） | ScheduleBoardTest.php:434 | 「`.gantt-label--head` を丸ごと消しても検出できなかった（実測 2026-09-03: 0/119）」 | 当初検出漏れ→テスト追加で検出 |
| A8 | `.gantt-label` の `box-shadow` を消す | _schedule_gantt_style.blade.php（Task 5） | ScheduleBoardTest.php:441 | 同上（実測 2026-09-03: 0/119） | 当初検出漏れ→テスト追加で検出 |
| A9 | `.gantt-scroll--card` の位置判定を素の `strpos($html, '.gantt-scroll--card')` で見る（実ルールでなく警告コメント中の同一文字列に前方一致してしまう。Bug #42②） | ScheduleBoardTest.php（Task 5） | commit `749c6190` | 「本物のルールだけを @media の後ろへ移す変異が 41 本すべて緑だった」→ needle を宣言 `.gantt-scroll--card {` に限定（M17 の判定方法の前身） | 当初検出漏れ→テスト追加で検出 |
| A10 | `@push('scripts')` を `@push('styles')` に押し間違える（ボードのスクロール script） | _schedule_board.blade.php（Task 6） | commit `5265f5eb` ／ ScheduleBoardTest.php:574 | 「実測（2026-09-03）では styles へ押し間違える変異が 2 本とも緑のまま通った」→ スクローラー要素とスクリプトの出現位置の前後関係を見るアサートを追加 | 当初検出漏れ→テスト追加で検出 |
| A11 | `build()` の `'rows'` を `[]` に潰す（KPI 削除確認テストの死角） | ScheduleBoardService.php（Task 2） | ScheduleBoardTest.php:1003 | 「実測: build() の 'rows' を [] に潰しても緑だった」→ `assertNotEmpty($response->viewData('board')['rows'], ...)` を先に追加 | 当初検出漏れ→テスト追加で検出 |
| A12 | `meta()` に `'bars' => []` を足す（`$meta + [...]` の左辺優先マージでキー衝突） | ScheduleBoardService.php（Task 4） | commit `dcd51a5a` ／ ScheduleBoardTest.php:635-637 | 「meta() へ 'bars' => [] を足す変異を当てて実測: `test_a_row_has_exactly_the_twelve_keys...` は落ちない」。ただし `bars` の中身を見る他の5本（`test_overlapping_steps_are_spread_across_lanes` 等）が確実に検出することを同じ実測で確認 | 単独では未検出（意図的な役割分担。別の5本が担保） |
| A13 | 4 親を回すループの消費先を procurement 1 種に絞る（`PARENTS` 定数自体は 4 件のまま） | ScheduleCardAxisTest.php（Task 7） | commit `4537a329` ／ ScheduleCardAxisTest.php:116-122 | 「ループの消費先を procurement 1 種に絞る変異が実測 0/3 本で素通りしていた」→ 「回した親」を記録して突き合わせる形に強化 | 当初検出漏れ→テスト追加で検出 |
| A14 | カードから CSS partial の `@include('_partials._schedule_gantt_style')` を消す | _schedule_gantt.blade.php（Task 8） | commit `a409b0d7` ／ ScheduleCardAxisTest.php:286 | 「実測 2026-09-03: 1302 本すべて緑だった。定義が消えると --gantt-label-w が未定義になり固定幅も sticky も丸ごと無効になっても」 | 当初検出漏れ→テスト追加で検出 |
| A15 | 節目（◆）行のラベル欄の `min-width: 0` を落とす | _schedule_gantt.blade.php（Task 8） | commit `a409b0d7` ／ ScheduleCardAxisTest.php:314-315 | 「実測 2026-09-03: 節目行の min-width: 0 を落としても 1302 本すべて緑」（◆ が出るフィクスチャが無く構造的に未検査だった） | 当初検出漏れ→テスト追加で検出 |

### 表3: 引き継ぎメモにあったが裏取りできなかった記述 — 本セッションで追加に実行して確認（6 通り）

⚠ 表2 と違い、**この 6 通りはこのセッションで実際にコードへ当てて実行した**（表1と同じ作法）。

| # | 変異 | 対象 | 着弾 | Failures/221 | 検出したテスト／理由 | 判定 |
|---|---|---|---|:--:|---|---|
| A16 | `position()` の `return $meta + [...]` を `return [...] + $meta` に反転（union の順序） | ScheduleBoardService.php | ✅ | 0 | ― | 等価変異（`$meta` と追加4キーは互いに素なので PHP の `+` 演算子は順序に関わらず同じ結果を返す。実測で確認） |
| A17 | `scale()` 最終 `return` の `min($dates)->startOfMonth()` から `->startOfMonth()` を外す | ScheduleBoardService.php | ✅ | 6 | `test_the_axis_spans_only_the_range_the_bars_occupy` 他5本 | 検出（`軸の始まりが最小の開始月でない` ／ `Failed asserting that two strings are identical.`） |
| A18 | `headers()` の `$next = $cursor->addMonth()->startOfMonth();` から `->startOfMonth()` を外す | ScheduleBoardService.php | ✅ | 0 | ― | 等価変異（`$cursor` は既に月初（day=1）なので `addMonth()` の結果も必ず月初になり、`startOfMonth()` は無意味。実測で確認） |
| A19 | カードの工程行ラベル欄の縞模様（`$loop->odd ? ' background: #FCFCFD;' : ''`）を全行へ無条件に付ける | _schedule_gantt.blade.php | ✅ | 1 | `test_the_striping_reaches_into_the_sticky_label_column` | 検出（`2本目（偶数行）に縞模様が付いている`） |
| A20 | ボードのスクロール関数から `if (! el) { return; }` を消す | _schedule_board.blade.php | ✅ | 0 | ― | **未検出（テストの死角）**。現行テストは関数シグネチャの行と呼び出し行の文字列一致しか見ておらず、本文中間行の増減を検知できない。JS を実行するテスト（node vm 等）が無いため、PHPUnit だけでは原理的に検出不能 |
| A21 | ボードのスクロール関数の `Math.max(0, ...)` クランプを外す | _schedule_board.blade.php | ✅ | 0 | ― | **未検出（テストの死角）**。A20 と同型。JS の数式部分の変更は文字列完全一致のアサートに引っかからない限り検出できない |

⚠ **A20 / A21 は「等価変異」ではなく「テストの死角」と判定した。** ロジックとしては
`el` が null のときの挙動や、`scrollLeft` が負値になり得るケースで実際の振る舞いが変わりうるが、
①スクローラー要素は常に存在してからスクリプトが呼ばれる設計（設計上 el は null にならない）
②現行のアサートが本文の中間行を見ていない、の 2 つが重なって PHPUnit からは観測できない。
「ロジック上変わらないから緑」ではなく「観測する手段が無いから緑」なので、A1/A2/A16/A18 の
等価変異とは性質が異なる。

### 裏取りできなかった記述

引き継ぎメモにあった次の 1 件は、対象キーが特定できず（「`meta()` のキー改名」がどのキーを
指すか不明）、本セッションでは再現・実行しなかった: **「`meta()` のキー改名（28本検出）」**。
`meta()` が返すキーは `kind` / `kindLabel` / `code` / `name` / `url` / `status` / `delayDays` /
`steps` の 8 種あり、どれを改名した実測かが分からないため、誤った変異を作って誤判定を報告する
くらいなら、事実として「未検証」と明記するほうが安全と判断した。

### 等価変異（equivalent mutant）まとめ

| # | 変異 | なぜ等価か |
|---|---|---|
| A1 | `scale()` の明示的 `startOfDay()` を外す | `GanttScale` のコンストラクタが `from` / `to` を無条件に `startOfDay()` するため、呼び出し側の明示呼び出しは意味を持たない |
| A2 | `headers()` の `$end` 三項演算子を外す | 現状の構築経路では `$scale->to()` が常に月末に揃っており、`$next->greaterThan($scale->to())` が真になる分岐へ実質到達しない |
| A16 | `position()` の union順序を反転 | `$meta`（8キー）と追加の4キー（`laneCount`/`rowHeight`/`bars`/`milestones`）は完全に素な集合であり、PHP の `+` 演算子は重複が無ければ順序に関わらず同じ結果を返す |
| A18 | `headers()` の `$next` の `startOfMonth()` を外す | ループの不変条件として `$cursor` は常に月初（day=1）で入るため、`addMonth()` の結果も必ず月初になり `startOfMonth()` は無意味 |

### 判定の異なる「テストの死角」（等価変異ではない）

| # | 変異 | 死角の理由 |
|---|---|---|
| A20 | ボードのスクロール関数の null ガード `if (! el) { return; }` を消す | JS の本文中間行は文字列一致でしか見ておらず、かつ JS 実行系のテストハーネスが無い（node vm 等は本機能では未導入）ため、行の増減そのものを検出できない |
| A21 | 同関数の `Math.max(0, ...)` クランプを外す | 同上。数式の変更も文字列完全一致のアサートの外側にあるため検出できない |

### 総括

- **本セッションで実際にコードへ当てて実行した変異**: 31 通り（M1〜M24 ＋ M2b の 25 通り ＋ 表3 の 6 通り）
- **検出**: 29 通り（表1 25 通りすべて ＋ A17 ＋ A19）
- **等価変異**: 2 通り（A16 ／ A18。本セッションで新規に実測）
- **未検出（テストの死角）**: 2 通り（A20 ／ A21。JS 実行系のテストが無いための構造的な限界）
- 別途、**出典つきで確認した「Task 1〜8 で実測済み」の記録**: 15 通り（表2 A1〜A15。
  うち等価変異 2、当初検出漏れ→テスト追加で検出 12、単独では未検出だが別の5本が担保 1）
- **裏取りできず未検証のまま残した記述**: 1 件（`meta()` のキー改名）
- **M1〜M24 ＋ M2b（プラン本来の対象範囲）に限れば、25 行中 25 行が検出、未検出 0 件、
  判定が覆った行は無い。** 全変異を戻したあとの最終状態は着手前と完全に一致
  （`OK (1304 tests, 8498 assertions)`）。

---

## 本番反映（利用者の承認後）

⚠ **DB 変更なし・ルート変更なし・新規 composer 依存なし。**

```bash
cd /Users/masanori/site/manage
git checkout 13.x
git merge --ff-only schedule-board-gantt
./deploy.sh
```

⚠ **新規 PHP クラスは追加していない**ので `composer dump-autoload` は不要
（新規ファイルは Blade partial 1 本とテストのみ）。

反映後、本番のブラウザで Task 10 Step 4〜9 を**もう一度**行う
（Bug #21 / #26 が「本番だけ壊れる」前例。`view:cache` は本番で再生成される）。
⚠ 本番の URL は `/system/manage/index.php/...` を挟む（素のパスは 302 で流れる）。
⚠ **302 を「アプリは正常」の証明に使わない** —— 認証リダイレクトはビューを描画する前に起きる。
