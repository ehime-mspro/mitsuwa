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
| `tests/Unit/Schedule/GanttScaleWidthTest.php` | **新規**。月数と px 幅 | 1 |
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
- Create: `tests/Unit/Schedule/GanttScaleWidthTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Schedule/GanttScaleWidthTest.php`:

```php
<?php

namespace Tests\Unit\Schedule;

use App\Support\GanttScale;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 軸のトラック幅（設計書 §3）。
 *
 * ⚠ **1 ヶ月 = 150px という数字はここ 1 箇所にしか無い。** Blade にも PHP の別の場所にも
 *   書かない（Bug #41 の二重実装）。
 *
 * ⚠ Laravel を起動しない Unit テストなので `config/app.php` の timezone は効かない
 *   （実際に効くのは `php.ini`。Bug #54 ①）。ここで扱うのは日付だけで時刻に依存しないが、
 *   念のため `setUp()` で固定し `tearDown()` で戻す。
 */
class GanttScaleWidthTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    public function test_one_month_range_counts_one_month(): void
    {
        $scale = new GanttScale(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30')
        );

        $this->assertSame(1, $scale->monthCount());
        $this->assertSame(150, $scale->trackWidthPx());
    }

    /** 本番の実データ（2026-02-19 〜 2026-09-27）を月初・月末に丸めた範囲 */
    public function test_the_production_range_is_eight_months(): void
    {
        $scale = new GanttScale(
            CarbonImmutable::parse('2026-02-01'),
            CarbonImmutable::parse('2026-09-30')
        );

        $this->assertSame(8, $scale->monthCount());
        $this->assertSame(1200, $scale->trackWidthPx());
    }

    /**
     * ⚠ **年をまたぐ範囲を必ず 1 本置く。** 月番号の引き算だけで書くと
     *   2026-11 〜 2027-02 が「11 → 2」で負になり、月数が 0 以下になる。
     */
    public function test_a_range_that_crosses_a_year_counts_correctly(): void
    {
        $scale = new GanttScale(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2027-02-28')
        );

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
        $scale = new GanttScale(
            CarbonImmutable::parse('2026-02-19'),
            CarbonImmutable::parse('2026-09-27')
        );

        $this->assertSame(8, $scale->monthCount());
    }

    /** 同じ日で始まり終わる範囲でも 1 ヶ月ぶんの幅を持つ（0 除算・幅 0 を防ぐ） */
    public function test_a_single_day_range_is_one_month_wide(): void
    {
        $day = CarbonImmutable::parse('2026-09-03');

        $this->assertSame(1, (new GanttScale($day, $day))->monthCount());
        $this->assertSame(150, (new GanttScale($day, $day))->trackWidthPx());
    }
}
```

- [ ] **Step 2: 失敗を確認する**

```bash
./vendor/bin/phpunit tests/Unit/Schedule/GanttScaleWidthTest.php
```

期待: `Error: Call to undefined method App\Support\GanttScale::monthCount()`（5 本とも失敗）

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
        return ($this->to->year - $this->from->year) * 12
            + ($this->to->month - $this->from->month) + 1;
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
./vendor/bin/phpunit tests/Unit/Schedule/GanttScaleWidthTest.php
```

期待: `OK (5 tests, 8 assertions)`

- [ ] **Step 5: 既存テストが壊れていないことを確認する**

```bash
./vendor/bin/phpunit 2>&1 | tail -5
```

期待: Task 0 Step 2 の件数 + 5 で `OK`

- [ ] **Step 6: コミット**

```bash
git add app/Support/GanttScale.php tests/Unit/Schedule/GanttScaleWidthTest.php
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
| M1 | `MONTH_WIDTH_PX` を `150` → `120` | `GanttScale.php` | `GanttScaleWidthTest` ＋ 幅のテスト |
| M2 | `monthCount()` の `+ 1` を消す | `GanttScale.php` | `GanttScaleWidthTest`（5 本） |
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

期待: `OK`。Task 0 のベースラインから **+12**。内訳:

| | 本数 |
|---|---|
| 削除 | 11（Task 2 の 8 ＋ Task 3 の 2 ＋ Task 4 で 1 本を 5 本に置き換えた分の 1）|
| 追加 | 23（Task 1: 5 / Task 2: 2 / Task 3: 1 / Task 4: 5 / Task 5: 3 / Task 6: 2 / Task 7: 2 / Task 8: 3）|
| 差引 | **+12** |

⚠ `ScheduleRealEstateUntouchedTest` と `ScheduleDateStateTest` は**その場で書き換える**ので本数は変わらない。
⚠ **実測が +12 でなければ、どこかで削除・追加を取りこぼしている。** 数が合うまで進めない。

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

---

## Task 11: 文書を更新する

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
