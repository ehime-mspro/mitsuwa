# 住宅事業の工程表を「現状の工程」に寄せる 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 住宅事業（建売 / 注文住宅）の工程表から予定・実績の区別を外し、工程の状態を日付だけで「これから / 進行中 / 済」として出す。遅延の概念を住宅事業から取り除く。**不動産（仕入れ案件 / 分譲地PJ）は一切変えない。**

**Architecture:** 「実績を持つか」を親モデルの abstract メソッド `scheduleTracksActuals(): bool` 1 本で宣言し、共有部品（サービス・partial・コントローラ）は**親に聞くだけ**にする。`instanceof` も部署名も書かない。状態の判定は `ScheduleStepStatus` に集約し、`actual_*` の正規化は `ScheduleStep` の `saving` フック 1 箇所に寄せる。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（Alpine 3）/ PHPUnit 11 / SQLite（テスト）/ MySQL 8（本番。`hs_*` は raw SQL 管理で migration 無し）

**設計書（正本）:** `docs/superpowers/specs/2026-09-02-housing-schedule-current-state-design.md`
**モック（§4.2 の確定根拠）:** `docs/mockups/housing/schedule-current-state.html`

---

## 作業環境（着手前に必ず読む）

- **作業ディレクトリ**: `/Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates`
  （既存の worktree。新しく作らない。ブランチ `housing-schedule-dates`）
- **テストの回し方**（`.env` が無いので `APP_KEY` を環境変数で渡す）:

```bash
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

  ⚠ **`APP_KEY="base64:x"` のような偽の鍵を使わない。** 構造テストは通るが暗号を使う経路が落ち、
  「変異が効いた」と誤読する。必ず 32 byte を base64 で渡す。
  ⚠ `vendor/` は 2026-09-02 にこの worktree へ `composer install` 済み（`vendor/bin/phpunit` あり）。
  無い場合は worktree の cwd で `composer install`。**main repo では絶対にやらない**（`--no-dev` が壊れる）。
- **ベースライン**: 着手前に上記を 1 回流し、`OK (N tests, M assertions)` の N をメモすること。
  2026-09-01 時点の記録は **1202 tests / 8065 assertions**。
- **1 ファイルだけ流す**: `--filter` を使う。例
  `APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleStepStatusTest`
- ⚠ **cwd がターンをまたぐと main repo へ戻る。** コマンドごとに `cd` を明示する。
- ⚠ **main repo で `artisan migrate` を実行しない**（実 DB は raw SQL 管理。config:cache 後は env 上書きが効かず実 DB を壊しうる）。

---

## 全体の設計判断（タスクに落とす前に共有する前提）

| # | 決めたこと | なぜ |
|---|---|---|
| P1 | `scheduleTracksActuals(): bool` は **abstract**。既定実装を置かない | `HasScheduleSteps` 冒頭の規約。既定値を置くと新しい親を足した人が override を忘れて**無音で片方に倒れる** |
| P2 | 状態の値は **`upcoming` / `running` / `done` / `undated`**。`running` と `done` は既存 `ScheduleStepStatus::RUNNING` / `DONE` と**同じ文字列**を使う | ボードの絞り込みの URL（`?status=running`）が部署で食い違わない。語彙を 2 つ持たない |
| P3 | 状態の判定は **`planned_start` / `planned_end` を直接見る**（`drawStart()` / `drawEnd()` を経由しない） | 住宅では `actual_*` が常に null なので同じ結果になるが、**設計書 §4.1 の 4 分岐をそのまま書ける**。`drawEnd()` は「実績開始があれば今日まで伸ばす」を含むので分岐が二重になる |
| P4 | ボードは **`$kinds` の全クラスが `scheduleTracksActuals()` で一致すること**を要求し、混在したら `LogicException` | 静かにどちらかへ倒れるのを防ぐ。現状 2 ボードとも同質 |
| P5 | KPI は**サービスがカードの並び（label / value / color）を返す**。partial は並べるだけ | partial に「住宅なら 3 枚」と書かない（設計書 §8 の規約）。4 枚 → 3 枚の差が data-driven になる |
| P6 | ラベル欄に **`min-width: 0; overflow: hidden;`** を足す（**両部署とも**） | チップで押し広げられると**その行の棒だけ最大 31.1px（約 12.6 日）ずれる**。モックで実測（Bug #29 と同型）。不動産側も同じ潜在欠陥を持つので分けない |
| P7 | 棒の状態表現は **`ring`（bool）** をサービスが返し、partial が `box-shadow` を当てる | CSS 文字列を PHP が組み立てない。既存も色は hex を返して partial が組む形 |

---

## ファイル構成

### 変更

| ファイル | 役割 |
|---|---|
| `app/Models/Concerns/HasScheduleSteps.php` | `scheduleTracksActuals()` を abstract で追加。`autoMilestones()` の docblock を書き換え |
| `app/Models/ReProcurement.php` / `ReProject.php` | `scheduleTracksActuals(): true` |
| `app/Models/HsProperty.php` / `HsCustomOrder.php` | `scheduleTracksActuals(): false` ＋ 列改名 ＋ `autoMilestones()` を着工・完成の 2 つに |
| `app/Models/ScheduleStep.php` | `saving` フックで `actual_*` を正規化。`dateState()` を追加 |
| `app/Support/ScheduleStepStatus.php` | `dateState()` と `UPCOMING` / `UNDATED` / `STATE_LABELS` を追加（遅延判定は**そのまま残す**） |
| `app/Services/ScheduleCardService.php` | 行に `state` / `stateLabel` / `ring` を載せ、gantt に `tracksActuals` を載せる |
| `app/Services/ScheduleBoardService.php` | 親に応じてステータス集合・案件ステータス・KPI カードを切り替える |
| `app/Http/Controllers/ScheduleStepController.php` | 住宅では `actual_*` を validate しない |
| `app/Http/Controllers/Housing/ScheduleImportController.php` | 取込時に着工予定日 / 完成予定日を更新。プレビューへ予告 |
| `app/Http/Controllers/Housing/PropertyController.php` / `CustomOrderController.php` | validate の列名 |
| `resources/views/_partials/_schedule_section.blade.php` | 実績 2 列を親に応じて出し分け |
| `resources/views/_partials/_schedule_gantt.blade.php` | 状態チップ ＋ 進行中の輪郭 ＋ ラベル欄の `min-width: 0` ＋ 凡例 |
| `resources/views/_partials/_schedule_board.blade.php` | KPI をループ描画に。遅延バッジを出し分け |
| `resources/views/housing/properties/{show,_form}.blade.php` | 着工予定日（完成予定日の前） |
| `resources/views/housing/custom-orders/{show,_form}.blade.php` | 同上 |
| `resources/views/housing/properties/schedule-import.blade.php` | 予告行 |
| `lang/ja/validation.php` | `actual_completion_date` を消し `construction_start_date` = 着工予定日 |
| `tests/Concerns/CreatesRealEstateSchema.php` | 2 テーブルの列改名（本番 DDL と対で維持） |
| `tests/Feature/Schedule/ScheduleAutoMilestoneTest.php` | 住宅の ◆ が 2 つになったことへ追従 |

### 新規

| ファイル | 役割 |
|---|---|
| `database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql` | 2 テーブルの `CHANGE COLUMN` |
| `tests/Feature/Schedule/ScheduleActualsPolicyTest.php` | 「実績を持つか」を親が宣言し、住宅では保存されないこと |
| `tests/Feature/Schedule/ScheduleDateStateTest.php` | 状態が日付だけで決まり、画面に出ること |
| `tests/Feature/Housing/HousingConstructionStartDateTest.php` | 列改名・画面の並び・取込による自動入力 |

---

## Task 1: 「実績を持つか」を親が宣言する（設計書 §3.1 D1）

**Files:**
- Modify: `app/Models/Concerns/HasScheduleSteps.php`
- Modify: `app/Models/ReProcurement.php:376`（`scheduleRoutePrefix()` の直後）
- Modify: `app/Models/ReProject.php:305`（同上）
- Modify: `app/Models/HsProperty.php:436`（同上）
- Modify: `app/Models/HsCustomOrder.php:423`（同上）
- Test: `tests/Feature/Schedule/ScheduleActualsPolicyTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleActualsPolicyTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Models\Concerns\HasScheduleSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 「実績（actual_start / actual_end）を持つか」は親モデルが宣言する（設計書 §3）。
 *
 * ⚠ **既定実装を置かないことまで固定する。** 既定値があると、新しい親を足した人が
 *   override を忘れた瞬間に無音で片方の挙動へ倒れる。abstract なら PHP が Fatal で止める。
 */
class ScheduleActualsPolicyTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_the_trait_declares_it_abstract_without_a_default(): void
    {
        $method = new ReflectionMethod(HasScheduleSteps::class, 'scheduleTracksActuals');

        $this->assertTrue($method->isAbstract(), 'scheduleTracksActuals() は abstract であること（既定実装を置かない）');
        $this->assertTrue($method->isPublic());
    }

    public function test_every_parent_declares_whether_it_tracks_actuals(): void
    {
        $expected = [
            'procurement' => true,
            'project'     => true,
            'property'    => false,
            'customOrder' => false,
        ];

        // ⚠ 4 親を全件見る。代表 1 種だけだと残りの経路が一度も実行されない（Bug #44）
        $this->assertSame(array_keys(self::PARENTS), array_keys($expected), '親の一覧と期待値の一覧がずれている');

        foreach ($expected as $key => $tracks) {
            $owner = $this->makeParent($key);

            $this->assertSame(
                $tracks,
                $owner->scheduleTracksActuals(),
                "{$key} の scheduleTracksActuals() が期待と違う"
            );

            // 親自身が宣言していること（trait から継がれた既定ではない）
            $declaring = (new ReflectionClass($owner))->getMethod('scheduleTracksActuals')->getDeclaringClass()->getName();
            $this->assertSame($owner::class, $declaring, "{$key} が自分で宣言していない");
        }
    }
}
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleActualsPolicyTest
```

Expected: FAIL — `ReflectionException: Method ... does not exist`

- [ ] **Step 3: trait に abstract メソッドを足す**

`app/Models/Concerns/HasScheduleSteps.php` の `abstract public function scheduleRoutePrefix(): string;` の**直後**に追加:

```php
    /**
     * 実績（`actual_start` / `actual_end`）を扱うか（設計書 §3.1 D1）。
     *
     * `false` のとき:
     *   - 編集表に実績の 2 列を出さない
     *   - 保存経路が `actual_*` を受け付けず、`ScheduleStep` の saving フックが null に正規化する
     *   - 遅延を判定しない（工程の状態は日付だけで決まる。設計書 §4.1）
     *
     * ⚠ **既定実装を置かない**（この trait 冒頭の規約）。既定値を置くと、新しい親を足した人が
     *   override を忘れた瞬間に**無音で片方の挙動へ倒れる**。abstract なら PHP が Fatal で止める。
     *
     * ⚠ **共有部品（サービス・partial・コントローラ）は `instanceof` を書かず、必ずここに聞く。**
     */
    abstract public function scheduleTracksActuals(): bool;
```

- [ ] **Step 4: 4 親に実装を足す**

`app/Models/ReProcurement.php` と `app/Models/ReProject.php` の `scheduleRoutePrefix()` の直後に、それぞれ:

```php
    /** ⚠ 不動産は予定と実績を分けて持つ（設計書 §4.3）。住宅事業と対称に保つこと。 */
    public function scheduleTracksActuals(): bool
    {
        return true;
    }
```

`app/Models/HsProperty.php` と `app/Models/HsCustomOrder.php` の `scheduleRoutePrefix()` の直後に、それぞれ:

```php
    /**
     * ⚠ 住宅事業は実績を持たない（設計書 §2 D1）。工程表は「いま現在どういう工程で
     *   動いているか」を見るもので、予定の管理は基本情報の着工予定日・完成予定日で行う。
     */
    public function scheduleTracksActuals(): bool
    {
        return false;
    }
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleActualsPolicyTest
```

Expected: PASS（2 tests）

- [ ] **Step 6: 全体テストが緑のままか確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK (1204 tests, ...)` — ベースライン 1202 から +2

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Models/Concerns/HasScheduleSteps.php app/Models/ReProcurement.php app/Models/ReProject.php app/Models/HsProperty.php app/Models/HsCustomOrder.php tests/Feature/Schedule/ScheduleActualsPolicyTest.php
git commit -F- <<'EOF'
feat(schedule): 実績を持つかを親モデルが宣言する

住宅事業（建売 / 注文住宅）は実績を持たず、不動産（仕入れ案件 / 分譲地PJ）は持つ。
共有部品が instanceof や部署名で分岐しないよう、abstract メソッド 1 本にする。

既定実装を置かないことも対で固定した。既定値があると、新しい親を足した人が
override を忘れた瞬間に無音で片方へ倒れる。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 2: 日付だけで決まる状態（設計書 §4.1）

**Files:**
- Modify: `app/Support/ScheduleStepStatus.php`
- Modify: `app/Models/ScheduleStep.php`
- Test: `tests/Unit/Support/ScheduleStepStatusTest.php`（既存に追記）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ScheduleStepStatusTest.php` の**末尾の `}` の直前**に追加:

```php
    // ============================================================
    // 日付だけで決まる状態（設計書 §4.1）— 住宅事業が使う。遅延とは無関係
    // ============================================================

    public function test_a_step_with_no_dates_is_undated(): void
    {
        $this->assertSame(
            ScheduleStepStatus::UNDATED,
            ScheduleStepStatus::dateState(null, null, CarbonImmutable::parse('2026-09-02'))
        );
    }

    public function test_a_step_that_starts_after_today_is_upcoming(): void
    {
        $this->assertSame(
            ScheduleStepStatus::UPCOMING,
            ScheduleStepStatus::dateState(
                CarbonImmutable::parse('2026-09-03'),
                CarbonImmutable::parse('2026-09-10'),
                CarbonImmutable::parse('2026-09-02')
            )
        );
    }

    /**
     * ⚠ **開始日ちょうどは「進行中」。** 判定を `<` から `<=` に変えるとここが「これから」になる
     *   （設計書 §12 の変異 3）。
     */
    public function test_a_step_starting_exactly_today_is_running(): void
    {
        $this->assertSame(
            ScheduleStepStatus::RUNNING,
            ScheduleStepStatus::dateState(
                CarbonImmutable::parse('2026-09-02'),
                CarbonImmutable::parse('2026-09-10'),
                CarbonImmutable::parse('2026-09-02')
            )
        );
    }

    public function test_a_step_ending_exactly_today_is_still_running(): void
    {
        $this->assertSame(
            ScheduleStepStatus::RUNNING,
            ScheduleStepStatus::dateState(
                CarbonImmutable::parse('2026-08-20'),
                CarbonImmutable::parse('2026-09-02'),
                CarbonImmutable::parse('2026-09-02')
            )
        );
    }

    public function test_a_step_that_ended_before_today_is_done(): void
    {
        $this->assertSame(
            ScheduleStepStatus::DONE,
            ScheduleStepStatus::dateState(
                CarbonImmutable::parse('2026-08-20'),
                CarbonImmutable::parse('2026-09-01'),
                CarbonImmutable::parse('2026-09-02')
            )
        );
    }

    /** 終了日が無い行（＝ ◆ マイルストーン）は、今日より後なら これから／それ以外は 済 */
    public function test_a_milestone_row_without_an_end_is_upcoming_or_done(): void
    {
        $today = CarbonImmutable::parse('2026-09-02');

        $this->assertSame(
            ScheduleStepStatus::UPCOMING,
            ScheduleStepStatus::dateState(CarbonImmutable::parse('2026-09-03'), null, $today)
        );
        $this->assertSame(
            ScheduleStepStatus::DONE,
            ScheduleStepStatus::dateState(CarbonImmutable::parse('2026-09-02'), null, $today)
        );
        $this->assertSame(
            ScheduleStepStatus::DONE,
            ScheduleStepStatus::dateState(CarbonImmutable::parse('2026-08-01'), null, $today)
        );
    }

    /** 開始日が無く終了日だけの行（入力上ありうるので分岐を落とさない） */
    public function test_a_step_with_only_an_end_falls_back_to_done_or_running(): void
    {
        $today = CarbonImmutable::parse('2026-09-02');

        $this->assertSame(
            ScheduleStepStatus::DONE,
            ScheduleStepStatus::dateState(null, CarbonImmutable::parse('2026-09-01'), $today)
        );
        $this->assertSame(
            ScheduleStepStatus::RUNNING,
            ScheduleStepStatus::dateState(null, CarbonImmutable::parse('2026-09-30'), $today)
        );
    }

    /**
     * ⚠ **状態は「今日」を引数で受け取る。** 内部で now() を呼ぶ実装に戻すと、
     *   このテストは実行日に依存して「凍結したつもりで効いていない」状態になる。
     */
    public function test_today_is_a_required_argument(): void
    {
        $method = new \ReflectionMethod(ScheduleStepStatus::class, 'dateState');
        $today  = $method->getParameters()[2];

        $this->assertSame('today', $today->getName());
        $this->assertFalse($today->isOptional(), '「今日」に既定値を持たせない');
    }

    /** 4 状態すべてに日本語のラベルがあること（画面のチップに出る） */
    public function test_every_state_has_a_japanese_label(): void
    {
        $this->assertSame(
            [
                ScheduleStepStatus::UPCOMING => 'これから',
                ScheduleStepStatus::RUNNING  => '進行中',
                ScheduleStepStatus::DONE     => '済',
                ScheduleStepStatus::UNDATED  => '未定',
            ],
            ScheduleStepStatus::STATE_LABELS
        );
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleStepStatusTest
```

Expected: FAIL — `Undefined constant App\Support\ScheduleStepStatus::UNDATED`

- [ ] **Step 3: `ScheduleStepStatus` に状態を足す**

`app/Support/ScheduleStepStatus.php` の `public const TODO = 'todo';` の**直後**に追加:

```php
    /**
     * 日付だけで決まる状態（設計書 §4.1）。**住宅事業が使う。遅延とは別の軸。**
     *
     * ⚠ `RUNNING` / `DONE` は上の進捗の定数と**同じ文字列**を意図的に共有している。
     *   ボードの絞り込みの URL（`?status=running`）が部署で食い違わないようにするため。
     *   語彙を 2 つ持つと、どちらの 'running' なのかが読めなくなる。
     */
    public const UPCOMING = 'upcoming';

    public const UNDATED = 'undated';

    /** チップに出す日本語。⚠ 画面はここだけを見る（Blade に直書きしない） */
    public const STATE_LABELS = [
        self::UPCOMING => 'これから',
        self::RUNNING  => '進行中',
        self::DONE     => '済',
        self::UNDATED  => '未定',
    ];
```

同ファイルの `isReached()` の**直前**に追加:

```php
    /**
     * 日付だけで決まる状態（設計書 §4.1）。
     *
     * ```
     * 開始日も終了日も無い     → 未定
     * 今日 < 開始日            → これから
     * 開始日 ≤ 今日 ≤ 終了日   → 進行中
     * 終了日 < 今日            → 済
     * ```
     *
     * ⚠ **終了日が無く開始日だけの行（＝ ◆）**: `今日 < 開始日` なら これから、それ以外は 済。
     * ⚠ **開始日が無く終了日だけの行**は「終了日 < 今日 なら 済 / そうでなければ 進行中」。
     *   入力上ありうるので分岐を落とさない。
     * ⚠ **判定は `<` であって `<=`。** 開始日ちょうどの工程は「これから」ではなく「進行中」。
     * ⚠ **「今日」は必ず引数で受け取る**（このクラスの方針）。
     */
    public static function dateState(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        CarbonInterface $today
    ): string {
        $t = CarbonImmutable::instance($today)->startOfDay();
        $s = $start !== null ? CarbonImmutable::instance($start)->startOfDay() : null;
        $e = $end !== null ? CarbonImmutable::instance($end)->startOfDay() : null;

        if ($s === null && $e === null) {
            return self::UNDATED;
        }

        if ($s === null) {
            return $e->lessThan($t) ? self::DONE : self::RUNNING;
        }

        if ($t->lessThan($s)) {
            return self::UPCOMING;
        }

        if ($e === null) {
            return self::DONE;
        }

        return $e->lessThan($t) ? self::DONE : self::RUNNING;
    }
```

- [ ] **Step 4: `ScheduleStep` に委譲メソッドを足す**

`app/Models/ScheduleStep.php` の `public function progress(): string` の**直後**に追加:

```php
    /**
     * 日付だけで決まる状態（設計書 §4.1）。住宅事業の画面が使う。
     *
     * ⚠ **`planned_*` を直接見る**（`drawStart()` / `drawEnd()` を経由しない）。
     *   `drawEnd()` は「実績開始があれば今日まで伸ばす」を含むので分岐が二重になる。
     *   実績を持つ親では使わない（そちらは遅延と進捗で見る）。
     */
    public function dateState(CarbonInterface $today): string
    {
        return ScheduleStepStatus::dateState($this->planned_start, $this->planned_end, $today);
    }

    public function stateLabel(CarbonInterface $today): string
    {
        return ScheduleStepStatus::STATE_LABELS[$this->dateState($today)];
    }
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleStepStatusTest
```

Expected: PASS

- [ ] **Step 6: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK (1213 tests, ...)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Support/ScheduleStepStatus.php app/Models/ScheduleStep.php tests/Unit/Support/ScheduleStepStatusTest.php
git commit -F- <<'EOF'
feat(schedule): 日付だけで決まる工程の状態を足す

これから / 進行中 / 済 / 未定 を planned_start と planned_end だけで決める。
住宅事業が使う。遅延の判定は不動産用にそのまま残す。

running と done は既存の進捗の定数と同じ文字列を共有する。ボードの絞り込みの
URL が部署で食い違わないようにするため。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 3: 住宅事業では実績を保存しない（設計書 §3.3）

⚠ **このタスク単独では本番へ出せない。** Task 3 の時点では住宅の画面に実績入力欄が残った
ままで、`save()` はレスポンスから行を再同期しないため、**入力欄に値が残ったまま
「保存しました。」が出て実際には保存されない**中間状態になる（Task 4 が同じブランチで
続くので問題ないが、ここで切り出してデプロイしてはいけない）。

**Files:**
- Modify: `app/Models/ScheduleStep.php`（`booted()` を新設）
- Modify: `app/Http/Controllers/ScheduleStepController.php:58,87,236-250`
- Test: `tests/Feature/Schedule/ScheduleActualsPolicyTest.php`（Task 1 で作ったものに追記）

⚠ **この 2 つは二重防御で、片方が効いていても応答からは区別が付かない**（Bug #48
「安全網が測定器を鈍らせる」）。テストは**応答ではなく**、
①`rules()` が住宅で `actual_*` を持たないこと（構造）②DB に入っている `actual_*` が
保存時に null へ潰れること（挙動）を**別々に**見る。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleActualsPolicyTest.php` の**末尾の `}` の直前**に追加:

```php
    // ============================================================
    // ① validate 側（構造）— 住宅ではルールに actual_* が無い
    // ============================================================

    /**
     * ⚠ **応答では測れない。** saving フックが同じ結果を作るので、ルールを戻しても
     *   HTTP の結果は変わらない（Bug #48）。ルールの中身を直接見る。
     */
    public function test_the_validation_rules_drop_the_actual_columns_for_housing(): void
    {
        $rules = new \ReflectionMethod(\App\Http\Controllers\ScheduleStepController::class, 'rules');
        $rules->setAccessible(true);
        $controller = new \App\Http\Controllers\ScheduleStepController();

        foreach (['procurement', 'project'] as $key) {
            $r = $rules->invoke($controller, $this->makeParent($key, ['procurement_code' => 'PRC-R' . $key, 'project_code' => 'PRJ-R' . $key]));
            $this->assertArrayHasKey('actual_start', $r, "{$key}（不動産）は実績を受け付ける");
            $this->assertArrayHasKey('actual_end', $r);
        }

        foreach (['property', 'customOrder'] as $key) {
            $r = $rules->invoke($controller, $this->makeParent($key, ['property_code' => 'HS-R' . $key, 'order_code' => 'CO-R' . $key]));
            $this->assertArrayNotHasKey('actual_start', $r, "{$key}（住宅）は実績を受け付けない");
            $this->assertArrayNotHasKey('actual_end', $r);
        }
    }

    // ============================================================
    // ② saving フック側（挙動）— DB に入っていても保存時に潰れる
    // ============================================================

    /**
     * ⚠ **validate を通さない経路で入れてから測る。** コントローラ経由だと
     *   ①のルールが先に落とすので、フックが効いているかを区別できない（Bug #48）。
     */
    public function test_saving_a_housing_step_clears_any_actual_dates_already_in_the_database(): void
    {
        $owner = $this->makeParent('property');
        $step  = $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        // フックを通さずに直接書き込む（過去データや手動 SQL を模す）
        \Illuminate\Support\Facades\DB::table('schedule_steps')->where('id', $step->id)
            ->update(['actual_start' => '2026-05-02', 'actual_end' => '2026-05-19']);

        $reloaded = \App\Models\ScheduleStep::find($step->id);
        $this->assertNotNull($reloaded->actual_start, '前提: DB には実績が入っている');

        // 何か 1 つ触って保存すると正規化される
        $reloaded->name = '基礎工事（改）';
        $reloaded->save();

        $this->assertNull(\App\Models\ScheduleStep::find($step->id)->actual_start);
        $this->assertNull(\App\Models\ScheduleStep::find($step->id)->actual_end);
    }

    public function test_the_hook_leaves_realestate_actual_dates_alone(): void
    {
        $owner = $this->makeParent('procurement');
        $step  = $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20',
            'actual_start'  => '2026-05-02', 'actual_end'  => '2026-05-19',
            'sort_order'    => 1,
        ]);

        $step->name = '造成工事（改）';
        $step->save();

        $fresh = \App\Models\ScheduleStep::find($step->id);
        $this->assertSame('2026-05-02', $fresh->actual_start->toDateString(), '不動産は実績を保持する');
        $this->assertSame('2026-05-19', $fresh->actual_end->toDateString());
    }

    /** 画面（Ajax）から実績を送り込んでも住宅では入らない — 経路の裏取り */
    public function test_posting_actual_dates_to_a_housing_step_stores_nothing(): void
    {
        $owner = $this->makeParent('property');

        $this->actingAs($this->manager())
            ->postJson(route($owner->scheduleStepRoute('store'), $owner), $this->stepInput([
                'actual_start' => '2026-05-11',
                'actual_end'   => '2026-06-05',
            ]))
            ->assertOk();

        $step = $owner->scheduleSteps()->first();
        $this->assertNull($step->actual_start);
        $this->assertNull($step->actual_end);
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleActualsPolicyTest
```

Expected: FAIL — `rules()` が引数を取らない（`ArgumentCountError`）／実績が残る

- [ ] **Step 3: `ScheduleStep` に saving フックを足す**

`app/Models/ScheduleStep.php` の `protected function casts(): array` の**直前**に追加:

```php
    /**
     * ⚠ **住宅事業の工程は実績を持たない**（設計書 §3.3）。画面から消すだけにすると、
     *   `Validator::validated()` が未送信キーを結果に含めないため `update($validated)` が
     *   **そのカラムに触れず旧値を残す**（Bug #38 と同型）。書き込み経路が増えても漏れないよう、
     *   ここ 1 箇所で正規化する。
     *
     * ⚠ **`schedulable` は `associate()` 済みなのでクエリは増えない。** 親が未解決のときは
     *   何もしない（保存前に親を紐づけていない呼び出しは元々成立しない）。
     */
    protected static function booted(): void
    {
        static::saving(function (self $step): void {
            $owner = $step->schedulable;

            if ($owner !== null && ! $owner->scheduleTracksActuals()) {
                $step->actual_start = null;
                $step->actual_end   = null;
            }
        });
    }
```

- [ ] **Step 4: コントローラの `rules()` を親に応じて変える**

`app/Http/Controllers/ScheduleStepController.php`:

`rules()` を差し替える（`private function rules(): array` の宣言と本体を丸ごと）:

```php
    /**
     * @return array<string, mixed>
     *
     * ⚠ **実績の 2 列は親が扱うと宣言したときだけ受け付ける**（設計書 §3.3）。
     *   住宅事業ではキーごと落とすので `validated()` に現れず、`fill()` も触らない。
     *   ⚠ これは `ScheduleStep` の saving フックとの**二重防御**。片方だけ壊しても
     *     HTTP の応答は変わらないので、テストは構造と挙動を別々に見ること（Bug #48）。
     */
    private function rules(Model $owner): array
    {
        $rules = [
            'name'          => 'required|string|max:100',
            'category'      => ['required', Rule::in(ScheduleStepCategory::values())],
            'planned_start' => 'nullable|date',
            'planned_end'   => 'nullable|date|after_or_equal:planned_start',
            'notes'         => 'nullable|string|max:255',
        ];

        if ($owner->scheduleTracksActuals()) {
            // ⚠ 実績終了だけが入って実績開始が空、という状態を許さない（設計書 §4.5）。
            //   許すと描画が「実績開始が無い」側へ落ち、**実績終了を入れたのに予定の棒が出る**
            //   という無音の食い違いになる。逆（実績開始だけ）は「進行中」なので正当。
            $rules['actual_start'] = 'nullable|date|required_with:actual_end';
            $rules['actual_end']   = 'nullable|date|after_or_equal:actual_start';
        }

        return $rules;
    }
```

`store()`（58 行付近）と `update()`（87 行付近）の呼び出しを直す:

```php
        $data  = $request->validate($this->rules($owner), [], $this->attributes());
```

```php
        $data = $request->validate($this->rules($owner), [], $this->attributes());
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleActualsPolicyTest
```

Expected: PASS（6 tests）

- [ ] **Step 6: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK (1217 tests, ...)`
⚠ ここで既存テストが落ちたら、**住宅の親に実績を入れているテスト**が原因。
落ちたテストを不動産の親へ移すのではなく、「住宅では実績が入らない」という**新しい仕様どおりに**直すこと。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Models/ScheduleStep.php app/Http/Controllers/ScheduleStepController.php tests/Feature/Schedule/ScheduleActualsPolicyTest.php
git commit -F- <<'EOF'
feat(schedule): 住宅事業の工程で実績を保存しない

画面から実績の列を消すだけにすると、validated() が未送信キーを含めないため
update() が旧値を残す（Bug #38 と同型）。書き込み経路が増えても漏れないよう
ScheduleStep の saving フック 1 箇所で null に正規化する。

併せてコントローラの rules() を親に応じて変え、住宅では actual_* をそもそも
受け付けないようにした。二重防御なので、テストは構造（ルールの中身）と挙動
（保存された値）を別々に見ている（Bug #48）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 4: 詳細カード — 状態チップと実績列の出し分け（設計書 §4.2）

**Files:**
- Modify: `app/Services/ScheduleCardService.php`
- Modify: `resources/views/_partials/_schedule_gantt.blade.php`
- Modify: `resources/views/_partials/_schedule_section.blade.php:52-53,85-86`
- Test: `tests/Feature/Schedule/ScheduleDateStateTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleDateStateTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleCardService;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 住宅事業の工程表は状態（これから / 進行中 / 済）を日付だけで出し、遅延を出さない（設計書 §4）。
 *
 * ⚠ **不動産が変わっていないことも対で見る。** 「住宅が変わった」だけを見ると、
 *   共有部品の変更が不動産を壊しても緑のまま通る（Bug #41）。
 */
class ScheduleDateStateTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** テスト中の「今日」。すべての日付をこの日基準で置く。 */
    private const TODAY = '2026-09-02';

    /**
     * ⚠ **「今日」を固定する。** HTTP 経由の描画は `ScheduleCardService` が
     *   `CarbonImmutable::today()` に落ちるので、凍結しないと**実行日に依存**する
     *   （このクラスの注意書きと同じ失敗を、テスト側で作らないこと）。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        CarbonImmutable::setTestNow(self::TODAY);
        \Carbon\Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        // ⚠ 戻さないと同一プロセスの後続テストへ漏れる
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /** 済 / 進行中 / これから の 3 本を持つ建売物件 */
    private function housingWithThreeStates(): \App\Models\HsProperty
    {
        $owner = $this->makeParent('property');

        foreach ([
            ['済です',     '2026-05-01', '2026-05-20'],
            ['進行中です', '2026-08-20', '2026-09-30'],
            ['これからです', '2026-10-01', '2026-10-05'],
        ] as $i => [$name, $s, $e]) {
            $owner->scheduleSteps()->create([
                'name' => $name, 'category' => 'work',
                'planned_start' => $s, 'planned_end' => $e, 'sort_order' => $i + 1,
            ]);
        }

        return $owner;
    }

    private function card(\Illuminate\Database\Eloquent\Model $owner): array
    {
        return app(ScheduleCardService::class)->build($owner, CarbonImmutable::parse(self::TODAY));
    }

    public function test_housing_rows_carry_a_state_derived_from_the_dates(): void
    {
        $card = $this->card($this->housingWithThreeStates());

        $this->assertFalse($card['tracksActuals'], '住宅は実績を扱わない');
        $this->assertFalse($card['gantt']['tracksActuals']);

        $this->assertSame(
            [ScheduleStepStatus::DONE, ScheduleStepStatus::RUNNING, ScheduleStepStatus::UPCOMING],
            array_column($card['gantt']['rows'], 'state')
        );
        $this->assertSame(['済', '進行中', 'これから'], array_column($card['gantt']['rows'], 'stateLabel'));
    }

    /** 進行中の棒だけ輪郭を出す（案B′。モックで確定） */
    public function test_only_the_running_row_asks_for_a_ring(): void
    {
        $rows = $this->card($this->housingWithThreeStates())['gantt']['rows'];

        $this->assertSame([false, true, false], array_column($rows, 'ring'));
    }

    /**
     * ⚠ **住宅では遅延を 0 にする。** 実績を取り込まない仕様なので、予定終了を過ぎた工程は
     *   全部「遅延」になってしまう（本番で 64 本中 57 本が赤くなった）。
     */
    public function test_housing_rows_never_report_a_delay(): void
    {
        $rows = $this->card($this->housingWithThreeStates())['gantt']['rows'];

        $this->assertSame([0, 0, 0], array_column($rows, 'delayDays'));
    }

    /** ⚠ 不動産は従来どおり遅延を出し、状態チップは出さない（巻き込み事故の検出。Bug #41） */
    public function test_realestate_still_reports_delays_and_gets_no_state(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-07-01', 'planned_end' => '2026-08-20', 'sort_order' => 1,
        ]);

        $card = $this->card($owner);

        $this->assertTrue($card['tracksActuals']);
        $this->assertTrue($card['gantt']['tracksActuals']);
        $this->assertSame(13, $card['gantt']['rows'][0]['delayDays'], '8/20 から 9/02 で 13 日');
        $this->assertNull($card['gantt']['rows'][0]['state'], '不動産に状態は付けない');
        $this->assertFalse($card['gantt']['rows'][0]['ring']);
    }

    // ============================================================
    // 画面（HTML）— 「配列に載った」だけでは足りない
    // ============================================================

    public function test_the_housing_detail_page_shows_state_chips_and_no_delay_badge(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        // ⚠ タグ込みで見る。素の「進行中」はボードの KPI 名などにも前方一致する（Bug #43）
        $this->assertStringContainsString('>これから</span>', $html);
        $this->assertStringContainsString('>進行中</span>', $html);
        $this->assertStringContainsString('>済</span>', $html);
        $this->assertDoesNotMatchRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+\d+日/', $html, '住宅に遅延バッジを出さない');
    }

    /**
     * ⚠ **ラベル欄の min-width: 0 を落とさない。** flex の min-width は既定が auto なので、
     *   チップで押し広げられた行は 262px を超え、**その行の棒だけ最大 31.1px（約 12.6 日）
     *   ずれる**（モックで実測。Bug #29）。HTML では位置ズレを測れないので属性で固定する。
     */
    public function test_the_label_column_cannot_be_pushed_wider_than_its_track(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        preg_match_all('/flex: 0 0 262px;([^"]*)"/', $html, $m);

        $this->assertNotEmpty($m[1], 'ラベル欄が 1 つも見つからない（走査の空振り）');
        foreach ($m[1] as $style) {
            $this->assertStringContainsString('min-width: 0', $style);
            $this->assertStringContainsString('overflow: hidden', $style);
        }
    }

    /**
     * ⚠ **棒の側だけを数える。** 同じ box-shadow は凡例のサンプルにも 1 つ出るので、
     *   HTML 全体で数えると凡例を消しても緑のまま通る（Bug #43 の型）。
     */
    public function test_the_running_bar_carries_the_ring_in_the_html(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        preg_match_all('/position: absolute; top: 11px; height: 12px;[^"]*/', $html, $bars);
        $ringed = array_filter($bars[0], fn ($s) => str_contains($s, 'box-shadow: 0 0 0 1.5px #111827'));

        $this->assertCount(3, $bars[0], '棒が 3 本描かれている');
        $this->assertCount(1, $ringed, '輪郭が付くのは進行中の 1 本だけ');
    }

    /** 凡例にも状態の説明が出ること（棒の検査とは別に見る） */
    public function test_the_legend_explains_the_states(): void
    {
        $owner = $this->housingWithThreeStates();

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('進行中は棒にも輪郭', $html);
    }

    // ============================================================
    // 編集表の実績 2 列
    // ============================================================

    public function test_the_housing_edit_table_has_no_actual_columns(): void
    {
        $owner = $this->makeParent('property');
        $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('>実績開始<', $html);
        $this->assertStringNotContainsString('>実績終了<', $html);
        $this->assertStringNotContainsString('x-model="row.actual_start"', $html);
        $this->assertStringNotContainsString('x-model="row.actual_end"', $html);
        $this->assertStringContainsString('>予定開始<', $html, '予定の列は残る');
    }

    /** ⚠ 不動産の編集表は 4 列のまま（巻き込み事故の検出） */
    public function test_the_realestate_edit_table_keeps_the_actual_columns(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('>実績開始<', $html);
        $this->assertStringContainsString('>実績終了<', $html);
        $this->assertStringContainsString('x-model="row.actual_start"', $html);
    }
}
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleDateStateTest
```

Expected: FAIL — `Undefined array key "tracksActuals"`

- [ ] **Step 3: `ScheduleCardService` に状態を載せる**

`app/Services/ScheduleCardService.php`:

`build()` の戻り値へ `tracksActuals` を足し、`gantt()` へ渡す（`'endpoints' => ...` の直前と `'gantt' => ...` の行）:

```php
            'endpoints'     => $this->endpoints($owner),
            'categories'    => $this->categories(),
            'today'         => $today,
            // ⚠ 親に聞く。partial やサービスに instanceof / 部署名を書かない（設計書 §3.1）
            'tracksActuals' => $owner->scheduleTracksActuals(),
            'gantt'         => $this->gantt($steps, $owner->autoMilestones(), $today, $owner->scheduleTracksActuals()),
```

`gantt()` のシグネチャを変える:

```php
    private function gantt(Collection $steps, array $milestones, CarbonImmutable $today, bool $tracksActuals): ?array
```

`gantt()` の `return [` ブロックを差し替える:

```php
        return [
            'months'        => $this->months($scale),
            'rows'          => $steps->map(fn (ScheduleStep $s) => $this->row($s, $scale, $today, $tracksActuals))->all(),
            'milestones'    => $this->milestones($milestones, $scale, $today),
            'todayPct'      => $scale->contains($today) ? $scale->left($today) : null,
            'todayLabel'    => $today->format('n/j'),
            'tracksActuals' => $tracksActuals,
            // 凡例のチップに出す日本語。⚠ Blade に直書きしない（語が 2 箇所に散らない）
            'stateLabels'   => ScheduleStepStatus::STATE_LABELS,
        ];
```

`row()` を差し替える（シグネチャと先頭の配列）:

```php
    private function row(ScheduleStep $step, GanttScale $scale, CarbonImmutable $today, bool $tracksActuals): array
    {
        // ⚠ 実績を扱う親（不動産）には状態を付けない。あちらは遅延と進捗で見る（設計書 §4.3）
        $state = $tracksActuals ? null : $step->dateState($today);

        $row = [
            'id'         => $step->id,
            'name'       => $step->name,
            'color'      => $step->category->color(),
            'periodText' => $step->periodText($today),
            // ⚠ **住宅では 0 に潰す。** 実績を取り込まない仕様なので、予定終了を過ぎた工程が
            //   全部「遅延」になる（本番で 64 本中 57 本が赤くなった。設計書 §1）
            'delayDays'  => $tracksActuals ? $step->delayDays($today) : 0,
            'progress'   => $step->progress(),
            'state'      => $state,
            'stateLabel' => $state === null ? '' : ScheduleStepStatus::STATE_LABELS[$state],
            // 進行中の棒だけ濃い輪郭（案B′）。⚠ CSS は Blade が組む。ここは真偽値だけ返す
            'ring'       => $state === ScheduleStepStatus::RUNNING,
            'kind'       => 'none',
            'leftPct'    => 0.0,
            'widthPct'   => 0.0,
        ];
```

（以降の `if (! $step->isDrawable())` 〜 `return $row;` は変更なし）

- [ ] **Step 4: ガントの partial を直す**

`resources/views/_partials/_schedule_gantt.blade.php`:

`@php($g = $schedule['gantt'])` の**直後**にチップの見た目を定義:

```blade
    {{-- 状態チップの見た目（案B′。モック docs/mockups/housing/schedule-current-state.html で確定）。
         ⚠ CSS はここに置く。サービスは真偽値と語だけを返す。 --}}
    @php($chipStyle = [
        'upcoming' => 'background: #fff; color: #6B7280; border: 1px solid #D1D5DB;',
        'running'  => 'background: #111827; color: #fff; border: 1px solid #111827;',
        'done'     => 'background: #F3F4F6; color: #9CA3AF; border: 1px solid #F3F4F6;',
        'undated'  => 'background: #fff; color: #9CA3AF; border: 1px dashed #D1D5DB;',
    ])
```

工程の行（`@foreach($g['rows'] as $row)` の中）のラベル欄を差し替える:

```blade
                        {{-- ⚠ **min-width: 0; overflow: hidden; を落とさないこと。**
                             flex の min-width は既定が auto なので、チップを足した行が 262px を
                             超えて広がり、**その行の棒だけ最大 31.1px（軸 275 日で約 12.6 日）
                             右へずれる**（モックで実測。月ヘッダは 262px のままなので月境界とも
                             合わなくなる）。Bug #29 と同型。 --}}
                        <div style="flex: 0 0 262px; min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; color: #111827;">
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">{{ $row['name'] }}</span>
                            @if($g['tracksActuals'])
                                @if($row['delayDays'] > 0)
                                    <span style="margin-left: auto; font-size: 10.5px; color: #DC2626; font-weight: 700; white-space: nowrap;">+{{ $row['delayDays'] }}日</span>
                                @else
                                    <span style="margin-left: auto; font-size: 10.5px; color: #9CA3AF; white-space: nowrap;">{{ $row['periodText'] }}</span>
                                @endif
                            @else
                                <span style="margin-left: auto;"></span>
                                <span style="flex: 0 0 auto; font-size: 10px; font-weight: 700; line-height: 1.5; padding: 0 5px; border-radius: 3px; white-space: nowrap; {{ $chipStyle[$row['state']] }}">{{ $row['stateLabel'] }}</span>
                                <span style="margin-left: auto; font-size: 10.5px; color: #9CA3AF; white-space: nowrap;">{{ $row['periodText'] }}</span>
                            @endif
                        </div>
```

同じ `@foreach` の中の棒（`@if($row['kind'] === 'bar')` の中身）を差し替える:

```blade
                                <div style="position: absolute; top: 11px; height: 12px; border-radius: 4px; box-sizing: border-box; left: {{ $row['leftPct'] }}%; width: {{ $row['widthPct'] }}%; background: {{ $row['color'] }};{{ $row['ring'] ? ' box-shadow: 0 0 0 1.5px #111827;' : '' }}"></div>
```

凡例（末尾の `{{-- 凡例 --}}` ブロック）の**閉じ `</div>` の直後**に状態の行を足す:

```blade
    @if(! $g['tracksActuals'])
        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 7px; font-size: 11.5px; color: #6B7280; align-items: center;">
            <span style="color: #9CA3AF; font-weight: 700;">状態</span>
            @foreach(['upcoming', 'running', 'done'] as $s)
                <span style="font-size: 10px; font-weight: 700; line-height: 1.5; padding: 0 5px; border-radius: 3px; {{ $chipStyle[$s] }}">{{ $g['stateLabels'][$s] }}</span>
            @endforeach
            <span><i style="display: inline-block; width: 22px; height: 9px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; background: #059669; box-shadow: 0 0 0 1.5px #111827;"></i>進行中は棒にも輪郭</span>
        </div>
    @endif
```

⚠ これで同じ `box-shadow` が凡例にも 1 つ出る。Step 1 のテストは**棒の側だけを数える**形に
してあるのでそのまま通る。HTML 全体で `substr_count` する形に書き換えないこと
（凡例を消しても緑のまま通るようになる。Bug #43 の型）。

- [ ] **Step 5: 編集表の実績 2 列を出し分ける**

`resources/views/_partials/_schedule_section.blade.php`:

52-53 行目の `<th>`（実績開始 / 実績終了）を `@if` で囲む:

```blade
                            @if($schedule['tracksActuals'])
                                <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">実績開始</th>
                                <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">実績終了</th>
                            @endif
```

85-86 行目の `<td>`（実績開始 / 実績終了の入力）を同じ条件で囲む:

```blade
                                @if($schedule['tracksActuals'])
                                    <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.actual_start" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                    <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.actual_end" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                @endif
```

⚠ **`<th>` と `<td>` を同じ条件で囲むこと。** 片方だけにすると列数が食い違って表が崩れる。

⚠ **JS（`save()` / `newRow()`）は触らない。** 住宅では `actual_*` が常に null のまま送られ、
サーバ側は `rules()` がキーごと落とすので無害。JS を分岐させると PHP と JS の二重実装になる（Bug #41）。
partial 冒頭の注意書きにこの理由を 1 行足しておくこと。

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleDateStateTest
```

Expected: PASS（10 tests）

- [ ] **Step 7: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK (1227 tests, ...)`

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Services/ScheduleCardService.php resources/views/_partials/_schedule_gantt.blade.php resources/views/_partials/_schedule_section.blade.php tests/Feature/Schedule/ScheduleDateStateTest.php
git commit -F- <<'EOF'
feat(schedule): 住宅事業の工程表に状態チップを出し遅延バッジをやめる

棒は分類色のまま（濃さを変えない）、状態はラベル欄のチップ、進行中の棒だけ
濃い輪郭。モックの採寸で確定した案B′（設計書 §4.2）。

ラベル欄に min-width: 0; overflow: hidden; を足した。flex の min-width が
既定 auto のままだとチップで押し広げられた行が 262px を超え、その行の棒だけ
最大 31.1px（約 12.6 日）ずれる（モックで実測。Bug #29 と同型）。

編集表の実績 2 列は親の scheduleTracksActuals() で出し分ける。不動産が
変わっていないことも対でテストに固定した（Bug #41）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 5: 住宅事業ボード — 状態 3 種 / KPI 3 枚（設計書 §8）

**Files:**
- Modify: `app/Services/ScheduleBoardService.php`（`row()` の `$bars[]` — `future` / `ring` の出し分けを含む）
- Modify: `resources/views/_partials/_schedule_board.blade.php:17-29,110-113,138-140`（棒の style に `ring` の box-shadow を含む）
- Test: `tests/Feature/Schedule/ScheduleBoardTest.php`（既存に追記）

**設計:** partial に「住宅なら」と書かない。サービスが
①`statuses`（絞り込みの選択肢）②`kpi`（カードの**並び**）を親に応じて返し、partial は並べるだけ（決定 P5）。

⚠ **プラン初版はここを取りこぼしていた（設計書 §8 の『棒の色』行）。** 設計書 §8 は住宅ボードの棒を
「分類色のまま（濃さを変えない）＋ 進行中だけ輪郭。§4.2 と同じ」と定めていたが、
下記 Step 1〜4 の初版は KPI と遅延バッジしか挙げておらず、`future`（opacity で薄くする）と
`ring`（進行中の輪郭）の対処が本文に一度も出てこない。実装時のコード品質レビューで発覚し、
Task 5 の範囲として追加で直した（詳細は Step 4 の末尾の追記を参照）。詳細カード
（`ScheduleCardService` / `_schedule_gantt.blade.php`。Task 4）は最初から `ring` を持っていたため、
直すまで同じ住宅事業の 2 画面で棒の規則が食い違っていた。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleBoardTest.php` の**末尾の `}` の直前**に追加:

```php
    // ============================================================
    // 住宅事業ボード（設計書 §8）— 状態 3 種 / KPI 3 枚 / 遅延なし
    // ============================================================

    /** 済 / 進行中 / これから を 1 件ずつ持つ 3 物件を作る */
    private function housingBoardFixture(): void
    {
        // ⚠ 日付は ScheduleBoardTest の凍結日 2026-08-31 基準。
        //   30 日以内の窓は 2026-08-31 〜 2026-09-30。
        foreach ([
            ['HS-DONE', '2026-05-01', '2026-05-20'],
            ['HS-RUN',  '2026-08-20', '2026-09-30'],
            ['HS-SOON', '2026-09-15', '2026-09-20'],
        ] as $i => [$code, $s, $e]) {
            $owner = $this->makeParent('property', ['property_code' => $code, 'property_name' => $code . ' 邸']);
            $owner->scheduleSteps()->create([
                'name' => '工程' . $i, 'category' => 'work',
                'planned_start' => $s, 'planned_end' => $e, 'sort_order' => 1,
            ]);
        }
    }

    public function test_the_housing_board_offers_the_three_date_states(): void
    {
        $board = $this->actingAs($this->manager())->get('/housing/schedules')->viewData('board');

        $this->assertSame(
            ['running' => '進行中', 'all' => 'すべて', 'upcoming' => 'これから', 'done' => '済'],
            $board['statuses'],
            '住宅事業に「遅延」は無い（設計書 §8）'
        );
    }

    /** ⚠ 不動産のボードは 4 種のまま（巻き込み事故の検出。Bug #41） */
    public function test_the_realestate_board_keeps_its_own_statuses(): void
    {
        $board = $this->actingAs($this->manager())->get('/realestate/schedules')->viewData('board');

        $this->assertSame(
            ['running' => '進行中', 'all' => 'すべて', 'late' => '遅延', 'done' => '完了'],
            $board['statuses']
        );
    }

    public function test_a_housing_case_is_classified_by_its_dates(): void
    {
        $this->housingBoardFixture();

        $rows = collect(
            $this->actingAs($this->manager())->get('/housing/schedules?status=all')->viewData('board')['rows']
        )->keyBy('code');

        $this->assertSame('done', $rows['HS-DONE']['status']);
        $this->assertSame('running', $rows['HS-RUN']['status']);
        $this->assertSame('upcoming', $rows['HS-SOON']['status']);
        $this->assertSame([0, 0, 0], $rows->pluck('delayDays')->all(), '住宅に遅延日数は出さない');
    }

    public function test_the_housing_status_filter_narrows_the_rows(): void
    {
        $this->housingBoardFixture();

        $rows = $this->actingAs($this->manager())->get('/housing/schedules?status=upcoming')->viewData('board')['rows'];

        $this->assertSame(['HS-SOON'], array_column($rows, 'code'));
    }

    /**
     * KPI は 3 枚。⚠ **3 枚とも数えるのは「工程」であって案件ではない**（設計書 §8）。
     */
    public function test_the_housing_board_shows_three_step_based_kpis(): void
    {
        $this->housingBoardFixture();

        $board = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->viewData('board');

        $this->assertSame(
            ['進行中の工程', '30日以内に始まる工程', '30日以内に終わる工程'],
            array_column($board['kpi'], 'label')
        );

        // ⚠ **3 枚を別々の値にしてある。** 全部同じ数だと、カードの並びを入れ替える変異が
        //   素通りする（凍結日 8/31 / 窓は 9/30 まで）:
        //     進行中の工程       = HS-RUN の 1 本
        //     30日以内に始まる工程 = HS-SOON の 9/15 だけ（HS-RUN は 8/20 開始で既に始まっている）
        //     30日以内に終わる工程 = HS-RUN の 9/30 と HS-SOON の 9/20 で 2 本
        $this->assertSame([1, 1, 2], array_column($board['kpi'], 'value'));
    }

    /** ⚠ 不動産の KPI は 4 枚のまま */
    public function test_the_realestate_board_keeps_four_kpis(): void
    {
        $board = $this->actingAs($this->manager())->get('/realestate/schedules')->viewData('board');

        $this->assertSame(
            ['進行中の案件', '遅れている案件', '30日以内に始まる工程', '30日以内に終わる工程'],
            array_column($board['kpi'], 'label')
        );
    }

    public function test_the_housing_board_never_paints_a_delay_badge(): void
    {
        $this->housingBoardFixture();

        $html = $this->actingAs($this->manager())->get('/housing/schedules?status=all')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+\d+日/', $html);
        $this->assertStringNotContainsString('border: 2px solid #DC2626', $html, '棒の赤枠も出さない');
        $this->assertStringContainsString('HS-RUN 邸', $html, '走査の空振りでないこと');
    }

    /**
     * ⚠ **1 枚のボードに実績を持つ親と持たない親を混ぜない。** 静かにどちらかへ倒れるのを防ぐ
     *   （決定 P4）。
     */
    public function test_mixing_tracked_and_untracked_kinds_on_one_board_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        app(\App\Services\ScheduleBoardService::class)->build([
            'procurement' => [\App\Models\ReProcurement::class, '仕入れ案件'],
            'property'    => [\App\Models\HsProperty::class, '建売'],
        ], new \Illuminate\Http\Request());
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleBoardTest
```

Expected: FAIL — statuses に `late` が残っている／`kpi` が連想配列のまま

- [ ] **Step 3: `ScheduleBoardService` に状態の集合を足す**

`app/Services/ScheduleBoardService.php`:

`STATUS_DONE` の定数の**直後**に追加:

```php
    public const STATUS_UPCOMING = 'upcoming';
```

`STATUSES` の定数の**直後**に追加:

```php
    /**
     * 実績を持たない親（住宅事業）の絞り込み（設計書 §8）。**遅延は無い。**
     *
     * ⚠ 「すべて」は空文字にしない（`Arr::query()` が null / 空のキーを捨てる。Bug #31）。
     * ⚠ `running` / `done` は不動産と同じ文字列。URL の語彙を 2 つ持たない（決定 P2）。
     */
    public const DATE_STATUSES = [
        self::STATUS_RUNNING  => '進行中',
        self::STATUS_ALL      => 'すべて',
        self::STATUS_UPCOMING => 'これから',
        self::STATUS_DONE     => '済',
    ];
```

`build()` の先頭（`$today = ...` の直後）に追加:

```php
        // ⚠ **1 枚のボードで親の方針が混ざらないことを先に確かめる**（決定 P4）。
        //   混ざったまま進むと、案件ごとに遅延の有無が食い違う画面になる。
        $tracksActuals = $this->tracksActuals($kinds);
```

`build()` の `$filters = $this->filters($kinds, $request);` を差し替え:

```php
        $filters = $this->filters($kinds, $request, $tracksActuals);
```

`build()` の `$row = $this->row(...)` を差し替え:

```php
                $row = $this->row($owner, $label, $key, $steps, $scale, $today, $tracksActuals);
```

`build()` の `return [` の `'kpi'` と `'statuses'` を差し替え:

```php
            'kpi'               => $this->kpi($rows, $keptSteps, $today, $tracksActuals),
            'statuses'          => $tracksActuals ? self::STATUSES : self::DATE_STATUSES,
```

`filters()` のシグネチャと status の正規化を差し替え:

```php
    private function filters(array $kinds, Request $request, bool $tracksActuals): array
    {
        $kind = (string) ($request->query('kind') ?? self::STATUS_ALL);
        if ($kind !== self::STATUS_ALL && ! array_key_exists($kind, $kinds)) {
            $kind = self::STATUS_ALL;
        }

        $statuses = $tracksActuals ? self::STATUSES : self::DATE_STATUSES;

        $status = (string) ($request->query('status') ?? self::STATUS_RUNNING);
        if (! array_key_exists($status, $statuses)) {
            $status = self::STATUS_RUNNING;
        }
```

（以降 `$zoom` 〜 `return [...]` は変更なし）

`row()` のシグネチャと status / delayDays / bars を差し替え:

```php
    private function row(Model $owner, string $kindLabel, string $kindKey, $steps, GanttScale $scale, CarbonImmutable $today, bool $tracksActuals): array
```

```php
            'status'     => $tracksActuals
                ? $this->status($steps, $today)
                : $this->dateStatus($steps, $today),
            // ⚠ 実績を持たない親では遅延を出さない（設計書 §8）
            'delayDays'  => $tracksActuals ? (int) $steps->max(fn (ScheduleStep $s) => $s->delayDays($today)) : 0,
```

`row()` の `$bars[] = [...]` の `'late'` を差し替え:

```php
                'late'     => $tracksActuals && $step->isLate($today),
```

`row()` の `'steps' => $steps->map(...)` の `'delayDays'` を差し替え:

```php
                'delayDays'  => $tracksActuals ? $s->delayDays($today) : 0,
```

`status()` の**直後**に追加:

```php
    /**
     * 実績を持たない親の案件ステータス（設計書 §8: **済 > 進行中 > これから**）。
     *
     * ⚠ 「未定」（日付が 1 つも無い工程）は判定に数えない。全部が未定の案件は
     *   「これから」に倒す（絞り込みから消えると、日付を入れ忘れた案件が画面から
     *   見えなくなって直せない）。
     */
    private function dateStatus($steps, CarbonImmutable $today): string
    {
        $states = $steps->map(fn (ScheduleStep $s) => $s->dateState($today));

        if ($states->contains(ScheduleStepStatus::RUNNING)) {
            return self::STATUS_RUNNING;
        }

        if ($states->contains(ScheduleStepStatus::UPCOMING)) {
            // 済 と これから が混ざっていれば「進行中」に見せる（工事は動いている）
            return $states->contains(ScheduleStepStatus::DONE)
                ? self::STATUS_RUNNING
                : self::STATUS_UPCOMING;
        }

        return $states->contains(ScheduleStepStatus::DONE)
            ? self::STATUS_DONE
            : self::STATUS_UPCOMING;
    }

    /**
     * 1 枚のボードに乗る親が全部同じ方針か（決定 P4）。
     *
     * ⚠ **混在したら黙ってどちらかへ倒さない。** 案件ごとに遅延の有無が食い違う画面になり、
     *   絞り込みの選択肢も決められない。
     */
    private function tracksActuals(array $kinds): bool
    {
        $flags = [];

        foreach ($kinds as [$class]) {
            $flags[] = (new $class())->scheduleTracksActuals();
        }

        if ($flags === [] || count(array_unique($flags)) > 1) {
            throw new \LogicException(
                '1 枚のボードに、実績を持つ親と持たない親を混ぜられません。'
                . 'ScheduleBoardController の KINDS を部署ごとに分けてください。'
            );
        }

        return $flags[0];
    }
```

`kpi()` を差し替える（**カードの並びを返す**形にする。決定 P5）:

```php
    /**
     * KPI カードの並び（決定 P5）。**partial は並べるだけ**にして「住宅なら 3 枚」を書かせない。
     *
     * @param  list<array<string, mixed>>  $rows       絞り込み**後**の行（決定 H）
     * @param  list<\Illuminate\Support\Collection>  $keptSteps  同じ添字の案件の工程
     * @return list<array{label: string, value: int, color: string}>
     *
     * ⚠ **`$rows` と `$keptSteps` は添字が揃っている前提。** `build()` が同じループで
     *   一緒に積んでいる。片方だけ絞り込むと KPI と画面が食い違う（設計書 §8.4）。
     */
    private function kpi(array $rows, array $keptSteps, CarbonImmutable $today, bool $tracksActuals): array
    {
        $limit = $today->addDays(self::SOON_DAYS);

        $soon = [
            ['label' => '30日以内に始まる工程', 'value' => $this->countSoon($keptSteps, 'start', $today, $limit), 'color' => '#1D4ED8'],
            ['label' => '30日以内に終わる工程', 'value' => $this->countSoon($keptSteps, 'end', $today, $limit),   'color' => '#B45309'],
        ];

        if (! $tracksActuals) {
            // ⚠ 3 枚とも数えるのは**工程**であって案件ではない（設計書 §8）
            return array_merge([[
                'label' => '進行中の工程',
                'value' => $this->countRunningSteps($keptSteps, $today),
                'color' => '#047857',
            ]], $soon);
        }

        return array_merge([
            ['label' => '進行中の案件',   'value' => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_RUNNING)), 'color' => '#047857'],
            ['label' => '遅れている案件', 'value' => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_LATE)),    'color' => '#B91C1C'],
        ], $soon);
    }

    /** @param  list<\Illuminate\Support\Collection>  $keptSteps */
    private function countRunningSteps(array $keptSteps, CarbonImmutable $today): int
    {
        $count = 0;

        foreach ($keptSteps as $steps) {
            foreach ($steps as $step) {
                if ($step->dateState($today) === ScheduleStepStatus::RUNNING) {
                    $count++;
                }
            }
        }

        return $count;
    }
```

⚠ `use App\Support\ScheduleStepStatus;` は既に import 済み（`isReached()` で使っている）。

- [ ] **Step 4: ボードの partial を直す**

`resources/views/_partials/_schedule_board.blade.php`:

KPI ブロック（17-29 行の `<div class="grid-2col-sm" ...>` から `</div>` まで）を差し替え:

```blade
{{-- KPI。⚠ **枚数と中身はサービスが決める**（不動産 4 枚 / 住宅 3 枚）。
     ここに「住宅なら」と書かない（設計書 §8）。
     ⚠ **`grid-2col-sm` を使う**（`grid-stack-sm` ではない）。app.css の注記どおり
        `grid-2col-sm` が「KPI カードなど 4〜6 列のもの」用。
     ⚠ トラックは `minmax(0, 1fr)`（素の 1fr は最小値が auto で中身に押し広げられる。Bug #29）。 --}}
<div class="grid-2col-sm" style="display: grid; grid-template-columns: repeat({{ count($board['kpi']) }}, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px;">
    @foreach($board['kpi'] as $card)
        <div style="background: white; border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px 14px;">
            <div style="font-size: 11.5px; color: #6B7280; margin-bottom: 4px;">{{ $card['label'] }}</div>
            <div style="font-size: 22px; font-weight: 700; color: {{ $card['color'] }};">{{ $card['value'] }}</div>
        </div>
    @endforeach
</div>
```

案件行の遅延バッジ（110-113 行の `@if($row['delayDays'] > 0)` ブロック）は**そのまま**でよい
（住宅では `delayDays` が 0 なので描かれない）。⚠ **条件を消さないこと**——不動産で必要。

工程明細の遅延（138-140 行の `@if($step['delayDays'] > 0)`）も**そのまま**。

⚠ **【実装時の追記】プラン初版が取りこぼしていた棒の見せ方（設計書 §8）。**
上記の KPI ブロックの差し替えだけでは、住宅の棒が不動産と同じ `future`（opacity 0.45）の
まま薄く出て、進行中の輪郭（`ring`）も無いままだった。`row()` の `$bars[] = [...]` に
以下を追加で当てる（Task 4 の `ScheduleCardService::row()` の `ring` 計算と同じ考え方）:

```php
'late'     => $tracksActuals && $step->isLate($today),
// まだ始まっていない工程は薄く出す（設計書 §4.2）。
// ⚠ 実績を持たない親では薄くしない（§4.2 は opacity 案を「済を 1.6:1 まで
//   落とす」として却下しており、住宅は分類色のまま出す。設計書 §8）。
'future'   => $tracksActuals && $step->actual_start === null && $spans[$i]['from']->greaterThan($today),
// 進行中の棒だけ濃い輪郭。詳細カード（_schedule_gantt.blade.php）と同じ規則にする（設計書 §8）
'ring'     => ! $tracksActuals && $step->dateState($today) === ScheduleStepStatus::RUNNING,
```

partial 側（棒の `<div>` の `style`）には `ring` の box-shadow をカードと同じ hex・太さで足す:

```blade
{{ $bar['late'] ? 'border: 2px solid #DC2626;' : '' }}{{ $bar['ring'] ? ' box-shadow: 0 0 0 1.5px #111827;' : '' }}
```

テストは「住宅の棒に opacity が 1 つも出ない」「進行中の棒だけに輪郭が出る」
「不動産は従来どおり `future` で薄く出る（巻き込み事故の検出）」の 3 本を追加した。
実装は 2 コミットに分割し、この棒の見せ方だけを独立した `feat:` コミットにした
（①②のテスト強化・軽微修正は別の `test:` コミット）。

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleBoardTest
```

Expected: PASS

- [ ] **Step 6: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK (1235 tests, ...)`
⚠ 既存の `test_the_kpis_agree_with_the_rows_on_screen` / `test_the_kpis_follow_the_filter` は
`$board['kpi']['running']` のように**連想キーで参照している可能性がある**。落ちたら
`array_column($board['kpi'], 'value', 'label')` で引く形に直す（**不動産のボードを見ている
テストなので枚数は 4 のまま**）。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Services/ScheduleBoardService.php resources/views/_partials/_schedule_board.blade.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -F- <<'EOF'
feat(schedule): 住宅事業ボードを状態 3 種 / KPI 3 枚にする

絞り込みは すべて / これから / 進行中 / 済。案件ステータスは 済 > 進行中 >
これから。KPI は 3 枚とも「工程」を数える。遅延は出さない。

KPI はサービスがカードの並びを返し partial は並べるだけにした。partial に
「住宅なら 3 枚」と書かないため。1 枚のボードに実績を持つ親と持たない親が
混ざったら LogicException で止める（静かにどちらかへ倒れるのを防ぐ）。

不動産のボードが 4 枚・遅延ありのままであることも対で固定した（Bug #41）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 6: 列の改名 `actual_completion_date` → `construction_start_date`（設計書 §5）

**Files:**
- Create: `database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql`
- Modify: `tests/Concerns/CreatesRealEstateSchema.php:267,318`
- Modify: `app/Models/HsProperty.php:38,60` / `app/Models/HsCustomOrder.php:46,68`
- Modify: `app/Http/Controllers/Housing/PropertyController.php:485` / `CustomOrderController.php:374`
- Modify: `resources/views/housing/properties/{_form,show}.blade.php`
- Modify: `resources/views/housing/custom-orders/{_form,show}.blade.php`
- Modify: `lang/ja/validation.php:439`
- Test: `tests/Feature/Housing/HousingConstructionStartDateTest.php`（新規）

⚠ **本番はどちらのテーブルも当該 2 列が全行 NULL**（建売 7 件 / 注文住宅 2 件を 2026-09-02 に実測）。
**データの移行は不要**——列の付け替えだけ。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Housing/HousingConstructionStartDateTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Housing;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 基本情報の「実際の完成日」を「着工予定日」へ付け替える（設計書 §5）。
 *
 * ⚠ **並びは 着工予定日 → 完成予定日。** 逆に置くと、工事の順番と画面の順番が食い違う。
 */
class HousingConstructionStartDateTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_both_tables_have_the_new_column_and_not_the_old_one(): void
    {
        foreach (['hs_properties', 'hs_custom_orders'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'construction_start_date'), "{$table} に着工予定日が無い");
            $this->assertFalse(Schema::hasColumn($table, 'actual_completion_date'), "{$table} に旧列が残っている");
        }
    }

    /**
     * ⚠ **本番 DDL とテスト用スキーマを対で維持する。** 片方だけだと SQLite テストと本番が
     *   黙って drift する（過去に実際に起きている）。
     */
    public function test_the_raw_sql_renames_both_tables(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql'));

        foreach (['hs_properties', 'hs_custom_orders'] as $table) {
            $this->assertMatchesRegularExpression(
                '/ALTER TABLE\s+`?' . $table . '`?\s+CHANGE COLUMN\s+`?actual_completion_date`?\s+`?construction_start_date`?/i',
                $sql,
                "{$table} の CHANGE COLUMN が DDL に無い"
            );
        }
    }

    public function test_the_column_is_mass_assignable_and_cast_to_a_date(): void
    {
        foreach ([HsProperty::class, HsCustomOrder::class] as $class) {
            $model = new $class();
            $this->assertContains('construction_start_date', $model->getFillable(), $class);
            $this->assertSame('date', $model->getCasts()['construction_start_date'] ?? null, $class);
            $this->assertNotContains('actual_completion_date', $model->getFillable(), $class);
        }
    }

    public function test_the_property_form_offers_the_construction_start_date_on_create_too(): void
    {
        // ⚠ 旧実装は @if($isEdit) で編集画面にしか出していなかった。着工予定日は登録時から分かる
        $html = $this->actingAs($this->manager())->get('/housing/properties/create')->assertOk()->getContent();

        $this->assertStringContainsString('name="construction_start_date"', $html);
        $this->assertStringContainsString('着工予定日', $html);
        $this->assertStringNotContainsString('name="actual_completion_date"', $html);
        $this->assertStringNotContainsString('実際の完成日', $html);
    }

    public function test_the_custom_order_form_offers_it_too(): void
    {
        $html = $this->actingAs($this->manager())->get('/housing/custom-orders/create')->assertOk()->getContent();

        $this->assertStringContainsString('name="construction_start_date"', $html);
        $this->assertStringContainsString('着工予定日', $html);
        $this->assertStringNotContainsString('name="actual_completion_date"', $html);
        $this->assertStringNotContainsString('実際の完成日', $html);
    }

    /** ⚠ 画面の**並び**まで見る。「両方出ている」だけでは順番の入れ替わりを検出できない */
    public function test_the_construction_start_date_comes_before_the_completion_date(): void
    {
        foreach ([
            '/housing/properties/create',
            '/housing/custom-orders/create',
        ] as $url) {
            $html = $this->actingAs($this->manager())->get($url)->assertOk()->getContent();

            $start = strpos($html, 'name="construction_start_date"');
            $end   = strpos($html, 'name="scheduled_completion_date"');

            $this->assertNotFalse($start, $url);
            $this->assertNotFalse($end, $url);
            $this->assertLessThan($end, $start, "{$url}: 着工予定日は完成予定日の前に置く");
        }
    }

    /**
     * ⚠ **詳細画面も見る。** フォーム（/create）しか見ていないと、show のラベルだけを
     *   「実際の完成日」に戻す変異（値は construction_start_date のまま）が緑のまま通る
     *   （コード品質レビュー指摘・実測済み。Bug #43 / #46 / #49 と同型）。
     *
     * ⚠ **ラベルの有無だけでなく「ラベル ↔ 値」の対で見る。** ラベルだけ見ると、値を旧列
     *   （もう存在しない actual_completion_date）から読む変異——結果は常に null＝「—」——を
     *   検出できない。空白を正規化したうえで「ラベルの div の直後に、そのラベルに対応する
     *   正しい日付の div が続く」ことを固定する。
     */
    public function test_the_property_show_page_pairs_the_label_with_its_own_value(): void
    {
        $property = HsProperty::create([
            'property_code' => 'HS-CS2', 'property_name' => 'ラベル対応テスト', 'status' => 'construction',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date'   => '2026-03-10',
            'scheduled_completion_date' => '2026-04-20',
        ]);

        $html       = $this->actingAs($this->manager())->get("/housing/properties/{$property->id}")->assertOk()->getContent();
        $normalized = preg_replace('/\s+/', ' ', $html);

        $this->assertStringNotContainsString('実際の完成日', $html);
        $this->assertStringContainsString(
            '着工予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/03/10</div>',
            $normalized,
            '着工予定日のラベル直後に着工予定日の値（2026/03/10）が無い'
        );
        $this->assertStringContainsString(
            '完成予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/04/20</div>',
            $normalized,
            '完成予定日のラベル直後に完成予定日の値（2026/04/20）が無い'
        );
    }

    /** ⚠ 建売と対で見る（Bug #44 の「代表 1 種だけ」を避ける）。同じ変異は注文住宅の show にも当たりうる */
    public function test_the_custom_order_show_page_pairs_the_label_with_its_own_value(): void
    {
        $order = HsCustomOrder::create([
            'order_code' => 'CO-CS2', 'order_name' => 'ラベル対応テスト', 'status' => 'construction',
            'customer_name' => 'テスト顧客', 'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date'   => '2026-05-15',
            'scheduled_completion_date' => '2026-06-25',
        ]);

        $html       = $this->actingAs($this->manager())->get("/housing/custom-orders/{$order->id}")->assertOk()->getContent();
        $normalized = preg_replace('/\s+/', ' ', $html);

        $this->assertStringNotContainsString('実際の完成日', $html);
        $this->assertStringContainsString(
            '着工予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/05/15</div>',
            $normalized,
            '着工予定日のラベル直後に着工予定日の値（2026/05/15）が無い'
        );
        $this->assertStringContainsString(
            '完成予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/06/25</div>',
            $normalized,
            '完成予定日のラベル直後に完成予定日の値（2026/06/25）が無い'
        );
    }

    public function test_saving_a_property_stores_the_construction_start_date(): void
    {
        $property = HsProperty::create([
            'property_code' => 'HS-CS1', 'property_name' => '着工テスト', 'status' => 'construction',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date' => '2026-02-19',
        ]);

        $this->assertSame('2026-02-19', $property->fresh()->construction_start_date->toDateString());
    }

    /** 和名が無いとエラー文に英字 `construction start date` が出る（Bug #37） */
    public function test_the_validation_attribute_has_a_japanese_name(): void
    {
        $attributes = require base_path('lang/ja/validation.php');

        $this->assertSame('着工予定日', $attributes['attributes']['construction_start_date'] ?? null);
        $this->assertArrayNotHasKey('actual_completion_date', $attributes['attributes']);
    }
}
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter HousingConstructionStartDateTest
```

Expected: FAIL — 列が無い／DDL ファイルが無い

- [ ] **Step 3: 本番 DDL を書く**

`database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql` を新規作成:

```sql
-- 住宅事業: 「実際の完成日」を「着工予定日」へ付け替える（設計書 §5.1）
--
-- 実行: sudo mysql は非対話でパスワードを渡せないため使えない。main repo の cwd で
--   1 呼び出し 1 ALTER として tinker に流す（多重ステートメントは
--   PDO::MYSQL_ATTR_MULTI_STATEMENTS 未設定のため保証されない。同種の DDL は既に
--   tinker 案内へ改められている: database/sql/2026-09-01-add-source-to-schedule-steps.sql）:
--     php artisan tinker --execute="DB::statement('ALTER TABLE hs_properties CHANGE COLUMN actual_completion_date construction_start_date DATE NULL DEFAULT NULL');"
--     php artisan tinker --execute="DB::statement('ALTER TABLE hs_custom_orders CHANGE COLUMN actual_completion_date construction_start_date DATE NULL DEFAULT NULL');"
--
-- ⚠ 実行前に本番を実測したところ、両テーブルとも actual_completion_date は
--    全行 NULL だった（建売 7 件 / 注文住宅 2 件。2026-09-02）。よってデータの移行は不要。
-- ⚠ このファイルと tests/Concerns/CreatesRealEstateSchema.php は対で維持する。
--    片方だけ直すと SQLite テストと本番が黙って drift する。
-- ⚠ **DB が先・deploy.sh が後。** 列が無い DB に新しいコードを乗せると住宅の画面が 500 する。

ALTER TABLE `hs_properties`
  CHANGE COLUMN `actual_completion_date` `construction_start_date` DATE NULL DEFAULT NULL;

ALTER TABLE `hs_custom_orders`
  CHANGE COLUMN `actual_completion_date` `construction_start_date` DATE NULL DEFAULT NULL;

-- ロールバック（データは失われない。全行 NULL のため）:
--   ALTER TABLE `hs_properties`    CHANGE COLUMN `construction_start_date` `actual_completion_date` DATE NULL DEFAULT NULL;
--   ALTER TABLE `hs_custom_orders` CHANGE COLUMN `construction_start_date` `actual_completion_date` DATE NULL DEFAULT NULL;
```

⚠ **①②はコード品質レビュー指摘で追加**（`sudo mysql` は非対話でパスワードを渡せず書いてあるとおりには
流せない／リネーム系 DDL の直近前例 `2026-07-30-split-re-contract-amount-land-building.sql` には
ロールバック行があるのに本ファイルには無かった）。両ファイルを実際に読んで house style に揃えた。

- [ ] **Step 4: テスト用スキーマを直す**

`tests/Concerns/CreatesRealEstateSchema.php` の 267 行目と 318 行目、どちらも:

```php
            $t->date('actual_completion_date')->nullable();
```

を次に差し替える（⚠ **`scheduled_completion_date` の行の前へ移す**。本番の列順とは無関係だが、
読む人が画面の並びと同じ順で追えるようにする）:

```php
            $t->date('construction_start_date')->nullable();
```

- [ ] **Step 5: モデルを直す**

`app/Models/HsProperty.php`:
- `$fillable`（38 行目）の `'actual_completion_date',` を `'construction_start_date',` に。
  ⚠ **`'scheduled_completion_date',` の前へ移す**
- `casts()`（60 行目）の `'actual_completion_date' => 'date',` を
  `'construction_start_date' => 'date',` に（同じく前へ移す）

`app/Models/HsCustomOrder.php` の 46 行目 / 68 行目も同じ。

- [ ] **Step 6: コントローラの validate を直す**

`app/Http/Controllers/Housing/PropertyController.php:485` と
`app/Http/Controllers/Housing/CustomOrderController.php:374`:

```php
            'construction_start_date'       => 'nullable|date',
```

⚠ **`'scheduled_completion_date'` の行の前へ移す**（ルールの並びも画面と揃える）。

- [ ] **Step 7: 和名を直す**

`lang/ja/validation.php:439` の

```php
        'actual_completion_date' => '実際の完成日',
```

を次に差し替え:

```php
        'construction_start_date' => '着工予定日',
```

- [ ] **Step 8: 画面を直す**

`resources/views/housing/properties/_form.blade.php`（163-173 行あたり）の
「完成予定日」の `<div>` と `@if($isEdit)` ブロックを、次の 2 つの `<div>` に差し替える
（⚠ **`@if($isEdit)` を外す**。着工予定日は登録時から分かる）:

```blade
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">着工予定日</label>
                <input type="date" name="construction_start_date" value="{{ old('construction_start_date', $p?->construction_start_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">完成予定日</label>
                <input type="date" name="scheduled_completion_date" value="{{ old('scheduled_completion_date', $p?->scheduled_completion_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
```

`resources/views/housing/custom-orders/_form.blade.php`（203-212 行あたり）も同様に、
「完成予定日」と「実際の完成日」の 2 つの `<div>` を上と同じ並び（`$p` を `$o` に読み替え）へ差し替える。

`resources/views/housing/properties/show.blade.php`（102-105 行）の 2 行 4 セルを差し替え:

```blade
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">着工予定日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->construction_start_date?->format('Y/m/d') ?? '—' }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">完成予定日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->scheduled_completion_date?->format('Y/m/d') ?? '—' }}</div>
```

`resources/views/housing/custom-orders/show.blade.php`（101-104 行）も同様（`$property` を `$o` に読み替え）。

- [ ] **Step 9: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter HousingConstructionStartDateTest
```

Expected: PASS（10 tests。うち show 画面のラベル↔値の対を見る 2 本はコード品質レビューで追加）

- [ ] **Step 10: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

⚠ `ScheduleAutoMilestoneTest` が落ちる（131 / 151 行が旧列名を使っている）。**Task 7 で直す**ので、
ここでは落ちたまま次へ進んでよい。それ以外が落ちたら旧列名の取りこぼしなので
`grep -rn "actual_completion_date" app resources routes database tests lang` で洗う（**0 件になること**）。

- [ ] **Step 11: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql tests/Concerns/CreatesRealEstateSchema.php app/Models/HsProperty.php app/Models/HsCustomOrder.php app/Http/Controllers/Housing/PropertyController.php app/Http/Controllers/Housing/CustomOrderController.php resources/views/housing lang/ja/validation.php tests/Feature/Housing/HousingConstructionStartDateTest.php
git commit -F- <<'EOF'
feat(housing): 実際の完成日を着工予定日へ付け替える

工程表から予定・実績の区別を外すのに合わせ、予定の管理を基本情報へ寄せる。
並びは 着工予定日 → 完成予定日。新規登録でも出す（旧実装は編集画面だけだった）。

本番は両テーブルとも当該列が全行 NULL（建売 7 件 / 注文住宅 2 件を実測）なので
データの移行は不要。本番 DDL とテスト用スキーマを対で直した。

⚠ 本番反映は DB の ALTER が先・deploy.sh が後。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 7: ガントの節目を 着工 と 完成 の 2 つにする（設計書 §6）

**Files:**
- Modify: `app/Models/Concerns/HasScheduleSteps.php`（`autoMilestones()` の docblock）
- Modify: `app/Models/HsProperty.php:441-451` / `app/Models/HsCustomOrder.php:428-438`
- Modify: `tests/Feature/Schedule/ScheduleAutoMilestoneTest.php:16,125-160`

- [ ] **Step 1: 既存テストを新しい仕様へ書き換える**

`tests/Feature/Schedule/ScheduleAutoMilestoneTest.php` の 16 行目のクラス docblock を差し替え:

```php
 * ⚠ **住宅事業の ◆ は「着工」と「完成」の 2 つ**（設計書 §6）。別々の節目なので 2 つ描いてよい。
 *   ⚠ 不動産（仕入れ案件 / 分譲地PJ）の ◆ は変えない。
```

131 行目・151 行目付近の `'actual_completion_date' => '2026-11-28'` / `'2026-11-15'` を使っている
2 本のテストを、次の 2 本へ差し替える（**旧テストは「完成は 1 つだけ」を固定しており、
新しい仕様と正面から食い違うので残さない**）:

```php
    public function test_a_property_draws_a_milestone_for_the_construction_start_and_the_completion(): void
    {
        $owner = $this->makeParent('property', [
            'construction_start_date'   => '2026-02-19',
            'scheduled_completion_date' => '2026-09-27',
        ]);
        $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-03-01', 'planned_end' => '2026-03-20', 'sort_order' => 1,
        ]);

        $milestones = $owner->autoMilestones();

        $this->assertSame(['着工', '完成'], array_column($milestones, 'label'));
        $this->assertSame('2026-02-19', $milestones[0]['date']->toDateString());
        $this->assertSame('2026-09-27', $milestones[1]['date']->toDateString());
    }

    public function test_a_property_with_only_one_of_the_two_dates_draws_only_that_one(): void
    {
        $onlyStart = $this->makeParent('property', [
            'property_code'           => 'HS-ONLY-START',
            'construction_start_date' => '2026-02-19',
        ]);
        $this->assertSame(['着工'], array_column($onlyStart->autoMilestones(), 'label'));

        $onlyEnd = $this->makeParent('property', [
            'property_code'             => 'HS-ONLY-END',
            'scheduled_completion_date' => '2026-09-27',
        ]);
        $this->assertSame(['完成'], array_column($onlyEnd->autoMilestones(), 'label'));
    }

    public function test_a_custom_order_keeps_its_contract_and_delivery_milestones(): void
    {
        $owner = $this->makeParent('customOrder', [
            'contract_date'             => '2026-01-10',
            'construction_start_date'   => '2026-02-19',
            'scheduled_completion_date' => '2026-09-27',
            'delivery_date'             => '2026-10-15',
        ]);

        $this->assertSame(
            ['契約', '着工', '完成', '引渡し'],
            array_column($owner->autoMilestones(), 'label'),
            '注文住宅は契約・引渡しも節目に持つ（順序は日付の並びどおり）'
        );
    }

    /** ⚠ 不動産の ◆ は変えない（巻き込み事故の検出。Bug #41） */
    public function test_the_realestate_milestones_are_untouched(): void
    {
        $before = (new \ReflectionMethod(\App\Models\ReProcurement::class, 'autoMilestones'))->getDeclaringClass()->getName();

        $this->assertSame(\App\Models\ReProcurement::class, $before);
        $this->assertTrue((new \App\Models\ReProcurement())->scheduleTracksActuals());
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleAutoMilestoneTest
```

Expected: FAIL — `['完成'] !== ['着工', '完成']`

- [ ] **Step 3: `HsProperty::autoMilestones()` を差し替える**

`app/Models/HsProperty.php`（441-451 行、docblock ごと）:

```php
    /**
     * ガントの ◆（設計書 §6）。**着工と完成の 2 つ。**
     *
     * ⚠ 以前は「完成は 1 つだけ」という注意書きだった。あれは `scheduled_completion_date` と
     *   `actual_completion_date` が**同じ節目の予定と実績**だったから。
     *   `construction_start_date` へ付け替えた今は**別の節目**なので 2 つ描いてよい。
     *
     * ⚠ 2 つは独立に判定する。片方だけ入っている案件は片方だけ描く。
     */
    public function autoMilestones(): array
    {
        return array_values(array_filter([
            $this->construction_start_date   ? ['label' => '着工', 'date' => $this->construction_start_date] : null,
            $this->scheduled_completion_date ? ['label' => '完成', 'date' => $this->scheduled_completion_date] : null,
        ]));
    }
```

- [ ] **Step 4: `HsCustomOrder::autoMilestones()` を差し替える**

`app/Models/HsCustomOrder.php`（428-438 行）:

```php
    /** ⚠ HsProperty と同じく、着工と完成は**別の節目**なので 2 つ描く（設計書 §6） */
    public function autoMilestones(): array
    {
        return array_values(array_filter([
            $this->contract_date             ? ['label' => '契約',   'date' => $this->contract_date] : null,
            $this->construction_start_date   ? ['label' => '着工',   'date' => $this->construction_start_date] : null,
            $this->scheduled_completion_date ? ['label' => '完成',   'date' => $this->scheduled_completion_date] : null,
            $this->delivery_date             ? ['label' => '引渡し', 'date' => $this->delivery_date] : null,
        ]));
    }
```

- [ ] **Step 5: trait の docblock を直す**

`app/Models/Concerns/HasScheduleSteps.php` の `autoMilestones()` の docblock から
`⚠ **「完成」は 1 つだけ。** scheduled と actual は同じ節目なので ◆ を 2 つ描かない。`
の 1 行を削除し、次に差し替える:

```php
     * ⚠ **同じ節目を 2 回描かない。** 不動産は予定と実績が同じ節目なので 1 つに畳む。
     *   住宅事業の「着工」と「完成」は**別の節目**なので 2 つ描く（設計書 §6）。
```

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleAutoMilestoneTest
```

Expected: PASS

- [ ] **Step 7: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK`（緑に戻る）

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Models/HsProperty.php app/Models/HsCustomOrder.php app/Models/Concerns/HasScheduleSteps.php tests/Feature/Schedule/ScheduleAutoMilestoneTest.php
git commit -F- <<'EOF'
feat(schedule): 住宅事業のガントの節目を着工と完成の 2 つにする

以前の「完成は 1 つだけ」は scheduled と actual が同じ節目の予定と実績だった
から。construction_start_date へ付け替えた今は別の節目なので 2 つ描く。
trait の docblock も直した（古い注意書きが残ると次に触る人が誤解する）。

不動産の節目は変えていないことを対で固定した。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 8: 取込が着工予定日・完成予定日を入れる（設計書 §7）

**Files:**
- Modify: `app/Http/Controllers/Housing/ScheduleImportController.php`
- Modify: `resources/views/housing/properties/schedule-import.blade.php:113-124`
- Test: `tests/Feature/Housing/HousingConstructionStartDateTest.php`（Task 6 で作ったものに追記）

**仕様:** 着工予定日 = 取り込む工程の `planned_start` の**最小値** / 完成予定日 = `planned_end` の**最大値**。
**常に上書き**し、確定前のプレビューで予告する。**値が変わらないときは予告の行を出さない。**

⚠ **ファイルのヘッダーの「工事期間」は使わない。** 実測で実データの範囲と一致しない
（固定資産では D1 が `07/28` 開始なのに実データの最小は `07/23`）。**画面に出るのと同じソース
（工程の日付）から出す。**

⚠ **2 つは独立に決める。** 開始日が 1 つも無ければ着工予定日は更新しない。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Housing/HousingConstructionStartDateTest.php` の**末尾の `}` の直前**に追加:

```php
    // ============================================================
    // 取込による自動入力（設計書 §7）
    // ============================================================

    /** 取込のプレビュー → 確定を、画面が描いたフォームどおりに往復する */
    private function importRows(HsProperty $property, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.execute', $property),
            ['rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return list<array<string, mixed>> */
    private function importableRows(): array
    {
        return [
            ['name' => '仮設工事 / 仮囲', 'category' => 'work', 'planned_start' => '2026-02-19', 'planned_end' => '2026-02-19', 'notes' => '', 'sort_order' => 1],
            ['name' => '基礎工事 / 配筋', 'category' => 'work', 'planned_start' => '2026-03-05', 'planned_end' => '2026-03-20', 'notes' => '', 'sort_order' => 2],
            ['name' => '検査 / 竣工検査', 'category' => 'permit', 'planned_start' => '2026-09-20', 'planned_end' => '2026-09-27', 'notes' => '', 'sort_order' => 3],
        ];
    }

    public function test_importing_sets_the_construction_start_and_completion_dates(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-IMP1']);

        $this->importRows($property, $this->importableRows())->assertRedirect();

        $fresh = $property->fresh();
        $this->assertSame('2026-02-19', $fresh->construction_start_date->toDateString(), '最も早い開始日');
        $this->assertSame('2026-09-27', $fresh->scheduled_completion_date->toDateString(), '最も遅い終了日');
    }

    /** ⚠ 常に上書きする（設計書 §7.2） */
    public function test_importing_overwrites_dates_that_were_already_there(): void
    {
        $property = $this->makeParent('property', [
            'property_code'             => 'HS-IMP2',
            'construction_start_date'   => '2020-01-01',
            'scheduled_completion_date' => '2020-12-31',
        ]);

        $this->importRows($property, $this->importableRows());

        $fresh = $property->fresh();
        $this->assertSame('2026-02-19', $fresh->construction_start_date->toDateString());
        $this->assertSame('2026-09-27', $fresh->scheduled_completion_date->toDateString());
    }

    /**
     * ⚠ **2 つは独立。** 開始日が 1 つも無い取込で着工予定日を潰さない。
     *   「両方そろわなければ何もしない」にもしない（片方だけ入ることはありうる）。
     */
    public function test_a_column_with_no_dates_leaves_that_field_alone(): void
    {
        $property = $this->makeParent('property', [
            'property_code'           => 'HS-IMP3',
            'construction_start_date' => '2020-01-01',
        ]);

        $this->importRows($property, [
            ['name' => '終了日だけの工程', 'category' => 'work', 'planned_start' => null, 'planned_end' => '2026-09-27', 'notes' => '', 'sort_order' => 1],
        ]);

        $fresh = $property->fresh();
        $this->assertSame('2020-01-01', $fresh->construction_start_date->toDateString(), '開始日が無いので着工予定日は据え置き');
        $this->assertSame('2026-09-27', $fresh->scheduled_completion_date->toDateString(), '完成予定日は更新する');
    }

    /**
     * ⚠ **工程と親の日付は同じトランザクションで**（設計書 §7.3）。
     *   片方だけ通ると工程とヘッダーの数字が食い違ったまま残る。
     */
    public function test_the_steps_and_the_parent_dates_move_together(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-IMP4']);

        $this->importRows($property, $this->importableRows());

        $this->assertSame(3, $property->scheduleSteps()->count());
        $this->assertSame('2026-02-19', $property->fresh()->construction_start_date->toDateString());

        // 取り込んだ工程の端と、親に入った日付が一致していること（別ソースにしない。Bug #46）
        $this->assertSame(
            $property->scheduleSteps()->min('planned_start'),
            $property->fresh()->construction_start_date->toDateString()
        );
    }

    // ---- プレビューの予告 ----

    private function preview(HsProperty $property): string
    {
        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.preview', $property),
            ['file' => new \Illuminate\Http\UploadedFile(
                base_path('tests/fixtures/schedule-import/list-format.xlsx'),
                'list-format.xlsx', null, null, true
            )]
        )->assertOk()->getContent();
    }

    public function test_the_preview_announces_the_dates_it_will_write(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-PRE1']);

        $html = $this->preview($property);

        $this->assertStringContainsString('着工予定日を', $html);
        $this->assertStringContainsString('完成予定日を', $html);
        $this->assertStringContainsString('2026/07/23', $html, '固定資産の最小の開始日');
        $this->assertStringContainsString('2026/12/25', $html, '固定資産の最大の終了日');
    }

    /** ⚠ 値が変わらないときは行を出さない（「2026/09/27 → 2026/09/27」というノイズを出さない） */
    public function test_the_preview_stays_quiet_when_a_date_would_not_change(): void
    {
        $property = $this->makeParent('property', [
            'property_code'             => 'HS-PRE2',
            'construction_start_date'   => '2026-07-23',
            'scheduled_completion_date' => '2026-12-25',
        ]);

        $html = $this->preview($property);

        $this->assertStringNotContainsString('着工予定日を', $html);
        $this->assertStringNotContainsString('完成予定日を', $html);
        $this->assertStringContainsString('取り込むと どうなるか', $html, '走査の空振りでないこと');
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter HousingConstructionStartDateTest
```

Expected: FAIL — 親の日付が更新されない

- [ ] **Step 3: コントローラに日付の算出を足す**

`app/Http/Controllers/Housing/ScheduleImportController.php` の `base()` の**直前**に追加:

```php
    /**
     * 取り込む工程から親に入れる日付を出す（設計書 §7.1）。
     *
     * ⚠ **ファイルのヘッダーの「工事期間」は使わない。** 実測で実データの範囲と一致しない
     *   （固定資産では D1 が 07/28 開始なのに実データの最小は 07/23）。ガントの棒の両端と
     *   基本情報の数字が食い違うのを防ぐため、**画面に出るのと同じソース**から出す。
     *
     * ⚠ **2 つは独立に決める。** 片方の日付が 1 つも無ければ、その項目は null を返して
     *   現在値を保つ。「両方そろわなければ何もしない」にはしない（片方だけ入ることはありうる）。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{construction_start_date: ?string, scheduled_completion_date: ?string}
     */
    public static function derivedDates(array $rows): array
    {
        $starts = array_filter(array_column($rows, 'planned_start'));
        $ends   = array_filter(array_column($rows, 'planned_end'));

        return [
            'construction_start_date'   => $starts === [] ? null : min($starts),
            'scheduled_completion_date' => $ends === [] ? null : max($ends),
        ];
    }
```

`execute()` の `DB::transaction(function () use ($property, $sanitized, $userId) {` のクロージャの
**末尾（`foreach` の閉じ括弧の直後、クロージャの閉じ括弧の直前）** に追加:

```php
            // ⚠ **工程の入れ替えと同じトランザクションで**（設計書 §7.3）。
            //   片方だけ通ると、工程とヘッダーの数字が食い違ったまま残る。
            $dates = array_filter(self::derivedDates($sanitized['rows']), fn ($v) => $v !== null);

            if ($dates !== []) {
                $property->fill($dates)->save();
            }
```

- [ ] **Step 4: プレビューへ予告の材料を渡す**

`preview()` の `return view(...)` を差し替え:

```php
        return view('housing.properties.schedule-import', $this->base($property) + [
            'result'    => $result,
            // ⚠ **キー名は rowErrors。`errors` にすると Blade の $errors（ViewErrorBag）を
            //   壊して、そのビューが $errors->any() を呼んだ瞬間に 500 する（Bug #53）。
            'rowErrors' => $result['rowErrors'],
            'warnings'  => $result['warnings'],
            // 取り込むと親の日付がどうなるか（設計書 §7.2）。⚠ **変わらない項目は出さない**
            'dateChanges' => $this->dateChanges($property, $result['rows']),
        ]);
```

`derivedDates()` の**直後**に追加:

```php
    /**
     * プレビューに出す「日付がこう変わる」の行（設計書 §7.2）。
     *
     * ⚠ **値が変わらない項目は返さない。**「2026/09/27 → 2026/09/27」というノイズを出さない。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label: string, from: string, to: string}>
     */
    private function dateChanges(HsProperty $property, array $rows): array
    {
        $labels = [
            'construction_start_date'   => '着工予定日',
            'scheduled_completion_date' => '完成予定日',
        ];

        $changes = [];

        foreach (self::derivedDates($rows) as $column => $to) {
            if ($to === null) {
                continue;
            }

            $current = $property->{$column}?->toDateString();

            if ($current === $to) {
                continue;
            }

            $changes[] = [
                'label' => $labels[$column],
                'from'  => $current === null ? '—' : \Carbon\CarbonImmutable::parse($current)->format('Y/m/d'),
                'to'    => \Carbon\CarbonImmutable::parse($to)->format('Y/m/d'),
            ];
        }

        return $changes;
    }
```

- [ ] **Step 5: プレビューの画面に予告を出す**

`resources/views/housing/properties/schedule-import.blade.php` の
`<li>手で追加した工程 ...</li>` の**直後**に追加:

```blade
                @foreach($dateChanges ?? [] as $change)
                    <li>{{ $change['label'] }}を <span class="font-bold">{{ $change['to'] }}</span> にします（現在: {{ $change['from'] }}）</li>
                @endforeach
```

⚠ `?? []` を付けるのは、このビューが**アップロード前の入口**（`form()`）でも描かれるため。
`form()` は `dateChanges` を渡さない。

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter HousingConstructionStartDateTest
```

Expected: PASS（15 tests）

- [ ] **Step 7: 全体テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
```

Expected: `OK`

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add app/Http/Controllers/Housing/ScheduleImportController.php resources/views/housing/properties/schedule-import.blade.php tests/Feature/Housing/HousingConstructionStartDateTest.php
git commit -F- <<'EOF'
feat(schedule): 取込が着工予定日と完成予定日を入れる

着工予定日 = 工程の planned_start の最小値 / 完成予定日 = planned_end の最大値。
常に上書きし、確定前のプレビューで予告する（値が変わらない項目は出さない）。

ファイルのヘッダーの「工事期間」は使わない。実測で実データの範囲と一致しない
ため、ガントの棒の両端と基本情報の数字が食い違う。画面に出るのと同じソースから
出す。2 つの日付は独立に決め、片方が無ければその項目は据え置く。

工程の入れ替えと親の日付の更新は同じトランザクションで行う。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 9: 不動産を巻き込んでいないことを固定する（Bug #41）

**Files:**
- Test: `tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php`（新規）

⚠ **Task 1〜8 の各テストにも「不動産は変わらない」を 1 本ずつ入れてある**が、
それは各タスクの範囲での確認。**ここは横断で 1 枚**にして、
「住宅の都合で共有部品を触ったときに不動産が壊れる」を 1 本で捕まえる。

- [ ] **Step 1: テストを書く**

`tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleCardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 住宅事業の改修が不動産（仕入れ案件 / 分譲地PJ）を巻き込んでいないこと（設計書 §12 の変異 8）。
 *
 * ⚠ **「住宅が変わった」だけを見ると、共有部品の変更が不動産を壊しても緑のまま通る**（Bug #41）。
 *   共有しているのは ScheduleCardService / ScheduleBoardService / _schedule_gantt /
 *   _schedule_section / _schedule_board / ScheduleStepController の 6 つ。
 */
class ScheduleRealEstateUntouchedTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** テスト中の「今日」。⚠ 凍結しないと遅延日数が実行日に依存する */
    private const TODAY = '2026-09-02';

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

    /** 不動産の 2 親とも、実績で描き・遅延を出し・状態チップを出さない */
    public function test_both_realestate_parents_still_draw_from_actuals_and_report_delays(): void
    {
        foreach (['procurement', 'project'] as $key) {
            $owner = $this->makeParent($key);
            $owner->scheduleSteps()->create([
                'name' => '造成工事', 'category' => 'work',
                'planned_start' => '2026-05-18', 'planned_end' => '2026-08-20',
                'actual_start'  => '2026-06-01', 'actual_end'  => null,
                'sort_order'    => 1,
            ]);

            $card = app(ScheduleCardService::class)->build($owner, CarbonImmutable::parse(self::TODAY));
            $row  = $card['gantt']['rows'][0];

            $this->assertTrue($card['tracksActuals'], "{$key} は実績を扱う");
            $this->assertNull($row['state'], "{$key} に状態チップは付けない");
            $this->assertFalse($row['ring'], "{$key} に輪郭は付けない");
            $this->assertSame(13, $row['delayDays'], "{$key} の遅延は 8/20 から 9/02 で 13 日");
            $this->assertSame('6/01〜9/02', $row['periodText'], "{$key} は実績開始から今日まで描く（設計書 §5.2）");
        }
    }

    /** 不動産の詳細画面は遅延バッジを出し、状態チップを出さない */
    public function test_the_realestate_detail_page_still_shows_the_delay_badge(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-07-01', 'planned_end' => '2026-08-20', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/color: #DC2626; font-weight: 700[^>]*>\+13日/', $html);
        $this->assertStringNotContainsString('>これから</span>', $html);
        $this->assertStringNotContainsString('box-shadow: 0 0 0 1.5px #111827', $html);
    }

    /** 不動産の工程は実績を受け付け、DB に残る */
    public function test_a_realestate_step_still_accepts_actual_dates_over_http(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())
            ->postJson(route($owner->scheduleStepRoute('store'), $owner), $this->stepInput([
                'actual_start' => '2026-05-11',
                'actual_end'   => '2026-06-05',
            ]))
            ->assertOk();

        $step = $owner->scheduleSteps()->first();
        $this->assertSame('2026-05-11', $step->actual_start->toDateString());
        $this->assertSame('2026-06-05', $step->actual_end->toDateString());
    }

    /** 実績終了だけを送るのは従来どおり弾く（設計書 §4.5） */
    public function test_a_realestate_step_still_rejects_an_end_without_a_start(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())
            ->postJson(route($owner->scheduleStepRoute('store'), $owner), $this->stepInput([
                'actual_start' => null,
                'actual_end'   => '2026-06-05',
            ]))
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: テストを流す**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit --filter ScheduleRealEstateUntouchedTest
```

Expected: PASS（4 tests。**すでに実装済みなので最初から緑**。これは回帰の網なので TDD の
「先に赤」は当てはまらない。代わりに **Task 10 の変異でこの網が効くことを確かめる**）

- [ ] **Step 3: 全体テスト＆コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" ./vendor/bin/phpunit
git add tests/Feature/Schedule/ScheduleRealEstateUntouchedTest.php
git commit -F- <<'EOF'
test(schedule): 不動産を巻き込んでいないことを横断で固定する

住宅事業の改修で触った共有部品は 6 つ。「住宅が変わった」だけを見ると、
共有部品の変更が不動産を壊しても緑のまま通る（Bug #41）ので、実績で描くこと・
遅延を出すこと・状態チップを出さないこと・実績を受け付けることを 1 枚にまとめた。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

### ⚠ Task 9 完了後のコードレビューで見つかった穴 2 件（I-1 / I-2）

レビュアーが Step 1 のテスト（当初 5 本。プラン逐語の 4 本 ＋ (B) で追加したボードテスト 1 本）に
**変異を全 1283 本に当てて**実測し、2 つの穴を見つけた（commit d0fe843f で修正）。

- **I-1**: `_schedule_board.blade.php` は遅延バッジを**案件行**と**展開した工程明細**の 2 箇所に描くが、
  `/color: #DC2626; font-weight: 700[^>]*>\+13日/` は両方に当たるため、片方だけを消す変異が
  **1283 全緑**で通っていた（実測: 案件行のバッジ削除 → 全緑 / 工程明細のバッジ削除 → 全緑）。
  役割ごとに分けた（案件行は `margin-left: auto; font-size: 10\.5px;` を前置きにした正規表現、
  工程明細は `<span style="color: #DC2626; font-weight: 700;">+13日</span>` の完全一致）。
- **I-2**: `ScheduleBoardService::row()` の `bars[].late`（棒の赤枠）と `steps[].delayDays`
  （工程明細の遅延）は不動産方向にまったく固定されておらず、`false` / `0` に潰しても
  **1283 全緑**だった。`border: 2px solid #DC2626` を肯定的に見るテストがアプリ全体に 1 本も無く、
  下記 Task 10 の変異 15 は住宅側が赤枠を出す向きしか測っていなかった（不動産が赤枠を
  **失う**向きは素通り）。`border: 2px solid #DC2626` はこの画面に 1 箇所しか無いことを grep で
  確認したうえで固定した。

### ⚠ 記録の訂正 — c2（`_schedule_gantt.blade.php` の `@if($g['tracksActuals'])` を消す）の検出理由

Step 1 完了直後の実測記録は「c2 は 2/5 で赤になった」で止めており、**落ちた理由の文言**まで
突き合わせていなかった（Bug #44 の「赤になっただけでは足りない、理由まで見る」に反する）。

コードレビューの指摘を受けて再実測すると、赤になった 2 本
（`test_the_realestate_detail_page_still_shows_the_delay_badge` /
`test_a_realestate_step_still_accepts_actual_dates_over_http`）は**どちらも `assertOk()` の 500**
（`$chipStyle[$row['state']]` が `state=null` の不動産行で
`ErrorException: Undefined array key ""`）で落ちていた。**狙っていた
`assertMatchesRegularExpression` は一度も評価されていなかった。**

`$chipStyle[$row['state']] ?? ''` と**検証のためだけに一時的に**フォールバックを足してから
c2 を当て直すと、`test_the_realestate_detail_page_still_shows_the_delay_badge` が今度は
正規表現不一致で赤になる（`Failed asserting that '<!DOCTYPE html>...'` — PHPUnit の regex
不一致の出力形式）ことを実測で確認した。**網自体は本物で、`$chipStyle[null]` の 500 が
それを覆い隠していただけ。**

⚠ **このフォールバックは実装に入れていない。** `state` と `tracksActuals` は同じ 1 つの変数
（`ScheduleCardService::row()` の `$state = $tracksActuals ? null : $step->dateState($today);`）
から出るので実運用では `$chipStyle[null]` に到達しない。フォールバックを入れると
「無音で無スタイルのチップが出る」へ化けるので、**騒がしく落ちる側（フォールバック無し）
のままにする**のが正しい判断。

---

## Task 10: 変異テスト（設計書 §12）

**「テストが緑」は検証にならない。変異を当てて赤になることを実測する。**

### 作法（Bug #44 / #50 で実際に踏んだもの。省略しない）

1. **先にコミットする。** 未コミットのまま変異を当てて `git checkout --` で戻すと**自分の編集ごと巻き戻る**
2. 各変異の**前**に `git status --porcelain` が**空**であることを確認（前の変異の残骸で測定が汚れる）
3. 変異を当てたら `git diff --stat` が**非空**であることを確認（**当たっていない変異を「検出しない」と誤読する事故**）
4. テストを流し、**赤/緑だけでなく「落ちた理由の文言」まで**突き合わせる（別の機構が落としている可能性を排除）
5. `git checkout -- <当該ファイル>` で戻す

⚠ **変異は「検査対象に入るはずの場所」へ当てること。** 除外リストに載っている場所へ当てて
「検出しない」と誤読した前例がある（Bug #44）。

- [ ] **Step 1: 変異 1〜12 を順に当てて実測する**

各行について、変異を当てる → テストを流す → 落ちた**テスト名と文言**を記録 → 戻す。

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 1 | `HsProperty::scheduleTracksActuals()` を `true` に | `ScheduleActualsPolicyTest::test_every_parent_declares_whether_it_tracks_actuals` ＋ `ScheduleDateStateTest`（実績列が出る・チップが消える）|
| 2 | `ScheduleStep::booted()` の `saving` フック本体を削除 | `ScheduleActualsPolicyTest::test_saving_a_housing_step_clears_any_actual_dates_already_in_the_database` |
| 3 | `ScheduleStepController::rules()` の `if ($owner->scheduleTracksActuals())` を消して常に実績を入れる | `ScheduleActualsPolicyTest::test_the_validation_rules_drop_the_actual_columns_for_housing` **だけ**が落ちること（②の安全網があるので挙動テストは緑のまま ＝ Bug #48 の「安全網が測定器を鈍らせる」を実測で確認する）|
| 4 | `ScheduleStepStatus::dateState()` の `$t->lessThan($s)` を `lessThanOrEqualTo` に | `ScheduleStepStatusTest::test_a_step_starting_exactly_today_is_running` |
| 5 | 同 `$e->lessThan($t)` を `lessThanOrEqualTo` に | `ScheduleStepStatusTest::test_a_step_ending_exactly_today_is_still_running` |
| 6 | `ScheduleCardService::row()` の `'ring' => false` に固定 | `ScheduleDateStateTest::test_only_the_running_row_asks_for_a_ring` ＋ `..._carries_the_ring_in_the_html` |
| 7 | `_schedule_gantt.blade.php` のラベル欄から `min-width: 0; overflow: hidden;` を消す | `ScheduleDateStateTest::test_the_label_column_cannot_be_pushed_wider_than_its_track` |
| 8 | `ScheduleCardService::row()` の `'delayDays'` を常に `$step->delayDays($today)` に | `ScheduleDateStateTest::test_housing_rows_never_report_a_delay` |
| 9 | `ScheduleBoardService::kpi()` の `if (! $tracksActuals)` を消す | `ScheduleBoardTest::test_the_housing_board_shows_three_step_based_kpis` |
| 10 | `ScheduleImportController` のトランザクション内の日付更新ブロックを削除 | `HousingConstructionStartDateTest::test_importing_sets_the_construction_start_and_completion_dates` |
| 11 | `derivedDates()` の `min` と `max` を入れ替える | 同上（着工＞完成という値になる）|
| 12 | `HsProperty::autoMilestones()` から `着工` の行を削除 | `ScheduleAutoMilestoneTest::test_a_property_draws_a_milestone_for_the_construction_start_and_the_completion` |

- [ ] **Step 2: 巻き込み事故の変異 13〜15（これが本命）**

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 13 | `ReProcurement::scheduleTracksActuals()` を `false` に | `ScheduleRealEstateUntouchedTest` の 4 本すべて |
| 14 | `_schedule_gantt.blade.php` の `@if($g['tracksActuals'])` を消して常にチップを出す | `ScheduleRealEstateUntouchedTest::test_the_realestate_detail_page_still_shows_the_delay_badge` |
| 15 | `ScheduleBoardService::row()` の `'late' => $tracksActuals && ...` から `$tracksActuals &&` を消す | `ScheduleBoardTest::test_the_housing_board_never_paints_a_delay_badge` |

⚠ **16〜17 は Task 3 のコードレビューで追加した**（Bug #48「安全網が測定器を鈍らせる」が
Task 3 の中でテストをまたいで起きた実例。実測済み——詳細は Task 3 の実装コミット参照）。

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 16 | `ScheduleStep::booted()` の `static::saving` を `static::updating` に | `ScheduleActualsPolicyTest::test_creating_a_housing_step_with_actual_dates_stores_none` と `..._re_saving_an_untouched_...`（⚠ 既存の `test_saving_a_housing_step_clears_...` は**緑のまま**であること＝イベント名の選択が load-bearing である証明）|
| 17 | `ScheduleImportController::execute()` の `new ScheduleStep([...])` に `'actual_start' => $row['planned_start']` を足す | `ScheduleImportTest` は**緑のまま**（saving フックが潰すので挙動では測れない）／`test_the_importer_never_writes_actual_dates`（構造）だけが赤 |

⚠ **18〜22 は Task 4 完了後のコード品質レビューで追加した**（Bug #43「凡例が行チップと同じ
ラベル文字列を出すため、行チップを丸ごと消しても凡例だけで素の文字列アサートが満たされる」を
実測で確認した実例）。**18 と 21 は「Task 4 完了時点のテスト（コミット `e247676e`）では
検出しなかった」ことも実測している**（＝この改善が load-bearing であることの証明。作法は
上記と同じ: 旧テストファイルを一時的に `git show e247676e:tests/Feature/Schedule/ScheduleDateStateTest.php`
で展開し変異を当てて green を確認 → 新テストファイルへ戻して同じ変異で red を確認）。
⚠ **19 は既存の変異 14 とは逆方向。** 14 は `@if($g['tracksActuals'])` を消して**常にチップを出す**
（不動産が壊れる向き）。19 は `@if(true)` にして**常に実績分岐（遅延バッジ / 期間テキスト）へ落とす**
（住宅のチップが消える向き）。片方向だけでは巻き込み事故の半分しか守れない。

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 18 | 行チップの `<span>`（`{{ $row['stateLabel'] }}` を出す部分。ラベル欄 `@else` 節の 2 行目）を丸ごと削除 | `ScheduleDateStateTest::test_each_housing_row_carries_its_own_state_chip`（＋ `..._shows_the_undated_chip` も判別子を共有するため巻き添えで赤）。⚠ **旧テスト（`e247676e`）では green のまま**（実測）。凡例が同じ `>これから</span>` 等を出すため、当時の素の文字列アサート（`assertStringContainsString('>これから</span>', $html)`）は凡例だけで満たされていた |
| 19 | ラベル欄の `@if($g['tracksActuals'])`（住宅向き。既存の変異 14 とは逆方向）を `@if(true)` に | `test_each_housing_row_carries_its_own_state_chip` ＋ `..._shows_the_undated_chip` |
| 20 | `$chipStyle` の `running`/`done` の**値**を入れ替え（キーはそのまま） | `test_the_running_chip_is_paired_with_its_dark_color` **だけ**が赤。ラベル文字列自体は変わらず色だけが変わるため、①②のチップ個数・存在テストは検出しない（実測: 他 12 本は green のまま） |
| 21 | 凡例の `@foreach(['upcoming', 'running', 'done'] as $s) ... @endforeach` ブロック（3 行）を削除 | `test_the_legend_explains_the_states`（凡例チップ 3 個の assertSame）。⚠ **旧テスト（`e247676e`）では green のまま**（実測）。`進行中は棒にも輪郭` は `@foreach` の外の兄弟 `<span>` で無関係に残るため、当時のテストはこの文字列しか見ておらず気づけなかった |
| 22 | 行のラベル欄（`@foreach($g['rows']...)` 内の 1 箇所だけ）の `262px` を `260px` に（月ヘッダ・節目行は不変） | `test_the_label_column_cannot_be_pushed_wider_than_its_track`（件数の下限アサート。4→1 に減り不一致で赤）。⚠ 件数下限を足す前の `assertNotEmpty` 単体だと、月ヘッダの `flex: 0 0 262px;` だけで非空を満たすため検出できない穴だった |

⚠ **23〜27 は Task 5 完了後のコード品質レビューで追加した。** 指摘は 3 件（①決定 H が住宅で一度も
測られていない ②`dateStatus()` の 3 分岐のうち 2 本が一度も実行されていない ③設計書 §8 の
「棒の色」がプランごと落ちていた）で、**23〜25 は「足す前なら緑だった」ことも実測している**
（＝この改善が load-bearing であることの証明。作法は上記と同じ：新しいテストを除いて残りが
green のままであることを、変異を当てた同じ実行結果の中で確認した）。

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 23 | `build()` の `$keptSteps[] = $steps;` を `matches()` の early-return より上へ移す（決定 H の絞り込みを無視） | `ScheduleBoardTest::test_the_housing_kpis_are_scoped_to_the_filtered_status`（新設）**だけ**が赤（実測: 38 本中 1 本。Expected `[0, 1, 1]` / Actual `[1, 2, 3]` ＝ 絞り込み前の全件の数字がそのまま出ている）。⚠ **足す前は緑だった**——この 1 本を除く 37 本（`?status=all` しか見ていない既存の KPI テストを含む）はこの変異を検出しない |
| 24 | `dateStatus()` の「済 と これから が混ざれば進行中」の三項演算子を `self::STATUS_UPCOMING` 固定に | `test_a_housing_case_is_classified_by_its_dates`（新設アサート「済とこれからが混ざれば進行中に見せる」）＋ 巻き込みで `test_the_housing_status_filter_narrows_the_rows` / `test_the_housing_kpis_are_scoped_to_the_filtered_status` も赤（実測: 38 本中 3 本）。⚠ **足す前は緑だった**——HS-MIX 自体が新設の fixture なので、この変異は旧テストからは原理的に見えない |
| 25 | `dateStatus()` の「全部未定なら これから」を `self::STATUS_DONE` 固定に | `test_a_housing_case_is_classified_by_its_dates`（新設アサート「全部未定ならこれから」）＋ `test_the_housing_status_filter_narrows_the_rows`（実測: 38 本中 2 本）。⚠ **足す前は緑だった**——HS-UNDATED も新設 fixture |
| 26 | `ScheduleBoardService::row()` の `'future' => $tracksActuals && ...` から `$tracksActuals &&` を消す（設計書 §8 の「住宅は薄くしない」を無効化） | `test_the_housing_board_bars_are_never_dimmed`（新設）が赤。実測の失敗理由: `opacity: 0.45` を含む棒が 1 本検出された（該当バーの実際の style 属性がテスト出力に出る） |
| 27 | `ScheduleBoardService::row()` の `'ring' => ...` を `false` 固定に | `test_the_housing_board_puts_a_ring_only_on_the_running_bar`（新設）が赤。実測: 輪郭ありの棒が 1 本 → 0 本に |

⚠ **28〜29 は Task 6 完了後のコード品質レビューで追加した。** 新規テストがフォーム（`/create`）
しか見ておらず、詳細画面（`show`）の**ラベルだけ**を「実際の完成日」に戻す変異（値は
`construction_start_date` のまま）が全テスト緑のまま通っていた実例。**建売・注文住宅の両方に
当てること**——片方だけだと Bug #44 の「代表 1 種だけ」と同型の穴になる。

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 28 | `properties/show.blade.php` の「着工予定日」ラベルだけを「実際の完成日」に戻す（値は `construction_start_date` のまま） | `HousingConstructionStartDateTest::test_the_property_show_page_pairs_the_label_with_its_own_value` |
| 29 | `custom-orders/show.blade.php` の「着工予定日」ラベルだけを「実際の完成日」に戻す（値は `construction_start_date` のまま） | `HousingConstructionStartDateTest::test_the_custom_order_show_page_pairs_the_label_with_its_own_value` |

- [ ] **Step 3: 検出できなかった変異があればテストを足す**

⚠ **「検出しなかった」で終わらせない。** 穴が見つかったらテストを足し、**足したあとに同じ変異で
赤になることまで確かめる**（Bug #45 の「改善が load-bearing であることの証明」）。

- [ ] **Step 4: 実測結果をこのプランに追記してコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
git add docs/superpowers/plans/2026-09-02-housing-schedule-current-state.md
git commit -F- <<'EOF'
docs(plan): 工程表の現状表示の変異テスト N 通りの実測を残す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 11: コンパイル検証と実ブラウザ確認

- [ ] **Step 1: コンパイル済みビューを lint する**

⚠ **`view:cache` の成功表示だけでは足りない**（コンパイル済み PHP を lint しないため。Bug #21 / #26 / #30）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" php artisan view:cache \
  && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done \
  && APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" php artisan view:clear
```

Expected: `INVALID:` の行が **0 件**

- [ ] **Step 2: 旧列名が残っていないことを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-schedule-dates
grep -rn "actual_completion_date" app resources routes database tests lang
```

Expected: **0 件**（`database/sql/2026-09-02-...sql` の `CHANGE COLUMN` の左側だけは残る。
そのファイルだけがヒットするなら正常）

- [ ] **Step 3: 開発サーバを立てて実ブラウザで見る**

⚠ **`artisan serve` は終わらないプロセス。必ずバックグラウンドで起動する。**
ポートは先に空きを確認する:

```bash
lsof -nP -iTCP:8000 -sTCP:LISTEN
```

使い捨ての SQLite に**建売 1 件 ＋ 工程 65 件**を作り、本番と同じ「済が大半」の形にして開く。
デモ用の seed で `User::create([... 'role' => 'executive' ...])` は**効かない**
（`role` / `status` は `$fillable` から外されており黙って捨てられて staff になり
`/housing/*` が 403 になる）。**作成後に明示代入する**こと:

```php
$u = User::create([...]);
$u->role = 'executive';
$u->status = 'active';
$u->save();
```

⚠ `storage/` は gitignore されていないので、置いた sqlite / php は**あとで必ず消す**。

見るもの（**テストが原理的に測れない領域**）:

| # | 見ること |
|---|---|
| 1 | 65 行のガントで **状態チップが 3 種とも読める**（済が大半でも沈まない）|
| 2 | **進行中の棒に輪郭が出ている**（1 本だけ）|
| 3 | **1 日の工程の棒が潰れていない**（塗りのまま。枠線に化けていない）|
| 4 | 赤い遅延バッジが **1 つも無い** |
| 5 | ◆ が **着工 と 完成 の 2 つ**出て、位置が月グリッドと合っている |
| 6 | **`main.scrollWidth === main.clientWidth`** を 建売詳細 / 注文住宅詳細 / 住宅ボード / 取込 の **4 画面 × 1800 / 1200 / 375px = 12 通り**（Bug #29 は超過幅が一定なので片方の幅だけでは判定できない）|
| 7 | ラベル欄が **どの行も同じ幅**（チップで押し広げられていない）。`document.querySelectorAll('[style*="flex: 0 0 262px"]')` の `getBoundingClientRect().width` が全部 262 |
| 8 | 住宅ボードの **KPI が 3 枚**・絞り込みが すべて / これから / 進行中 / 済 |
| 9 | **不動産の詳細とボードが従来のまま**（遅延バッジあり・KPI 4 枚・実績 2 列あり）|
| 10 | 基本情報が **着工予定日 → 完成予定日**の順。新規登録画面にも着工予定日が出る |
| 11 | 取込のプレビューに **日付の予告 2 行**が出る |
| 12 | **コンソール出力 0 件**（4 画面とも）|
| 13 | **着工日が工程より大幅に早い案件**（例: 着工予定日を登録済み工程の開始より半年以上前にする）で、軸が引き伸ばされて工程バーが視覚的に圧縮されないか。◆ の日付も軸計算に含まれる（`ScheduleCardService::gantt()`）ため起こりうる相互作用。**設計書 §6 はこの相互作用に触れていない**（Task 7 コード品質レビューで指摘） |
| 14 | **着工＝完成が同日の案件**で、2 つの ◆ とラベルが重ならずに読めるか。`_schedule_gantt.blade.php` はラベル付き ◆ を衝突回避なしで絶対配置している（ボード側はラベル無しの小さい ◆ なので影響は軽微）。**設計書 §6 はこの相互作用に触れていない**（Task 7 コード品質レビューで指摘） |

- [ ] **Step 4: 実測結果をプランに追記してコミット**

---

## Task 12: 本番反映

⚠ **DB（`ALTER`）が先・`./deploy.sh` が後。** 列が無い DB に新しいコードを乗せると住宅の画面が 500 する。

- [ ] **Step 1: main repo へ FF マージ**

```bash
cd /Users/masanori/site/manage
git checkout 13.x && git merge --ff-only housing-schedule-dates
```

⚠ FF できない場合は worktree で `git rebase 13.x` してから。

- [ ] **Step 2: 本番の現状を実測してから ALTER を流す**

⚠ **流す前に測る。** 本番は 2026-09-02 時点で両テーブルとも当該 2 列が全行 NULL だったが、
運用が進んで値が入っている可能性がある。**入っていたら移行方針を決め直す**（この改修は
「実際の完成日」の記録を捨てる前提で書かれている）。

```sql
SELECT COUNT(*) AS rows_with_value FROM hs_properties     WHERE actual_completion_date IS NOT NULL;
SELECT COUNT(*) AS rows_with_value FROM hs_custom_orders  WHERE actual_completion_date IS NOT NULL;
```

0 件を確認したうえで:

```bash
sudo mysql manage < database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql
```

実行後 `SHOW CREATE TABLE hs_properties;` / `hs_custom_orders;` で列名を確認する。

⚠ **住宅事業の工程に残っている実績も、同じタイミングで測って掃除する。** `ScheduleStep` の
`saving` フックは「保存し直したとき」にしか働かないため、Task 3 の反映後も**一度も編集されて
いない行には実績が残ったまま**になる。残った実績は画面のどこにも出ないのに、
`ScheduleStep::drawStart()`（`actual_start ?? planned_start`）と `ScheduleCardService` の
軸の収集が親を問わず `actual_*` を見るため、**棒の位置と軸の範囲を無音で動かしてしまう**。
しかも Task 4 で住宅の実績入力欄が画面から消えるため、**画面から直す手段が無くなる**——
今のうちに（測ってから）掃除しておく。**0 と決め打ちしない。**

```sql
-- 住宅事業の工程に残っている実績（saving フックは保存し直したときしか働かない）
SELECT COUNT(*) AS housing_steps_with_actuals FROM schedule_steps
 WHERE schedulable_type IN ('App\\Models\\HsProperty', 'App\\Models\\HsCustomOrder')
   AND (actual_start IS NOT NULL OR actual_end IS NOT NULL);

-- 0 でなければ掃除する（DDL ではないので deploy.sh の前後どちらでもよいが、測るのは先）
UPDATE schedule_steps SET actual_start = NULL, actual_end = NULL
 WHERE schedulable_type IN ('App\\Models\\HsProperty', 'App\\Models\\HsCustomOrder');
```

- [ ] **Step 3: デプロイ**

```bash
cd /Users/masanori/site/manage
./deploy.sh
```

⚠ **新規 PHP クラスは追加していない**ので `composer dump-autoload` は不要。
⚠ **新規依存も無い**ので `composer install` は不要。

- [ ] **Step 4: 本番で確認**

| # | 見ること |
|---|---|
| 1 | **コンパイル済みビューの `php -l`** が INVALID 0 件（Bug #21 / #26 の「本番だけ 500」を排除）|
| 2 | 建売物件の詳細（工程 65 件が入っている HS-008）で状態チップが出て赤バッジが消えている |
| 3 | ◆ が 着工 と 完成 の 2 つ |
| 4 | 住宅ボードの KPI 3 枚・絞り込み 4 種 |
| 5 | **不動産の詳細とボードが従来のまま** |
| 6 | 基本情報が 着工予定日 → 完成予定日 |
| 7 | コンソールエラー 0 件 |

⚠ **本番の URL は `/index.php/` を挟む。** 素の `.../manage/housing/properties` は 302 で流れる。
⚠ **302 を「アプリは正常」の証明に使わない**——認証リダイレクトはビューを描画する前に起きる。

- [ ] **Step 5: `docs/BACKLOG.md` に記録を足してコミット**

---

## 自己レビュー（このプランを書いたあとに確認したこと）

| 設計書の節 | 対応するタスク |
|---|---|
| §2 D1 / §3.1（親が宣言）| Task 1 |
| §3.2（列を残す）| DDL 変更なし ＝ 何もしない（Task 6 は `hs_*` だけ触る）|
| §3.3（saving で正規化 ＋ validate）| Task 3 |
| §4.1（日付だけの状態）| Task 2 |
| §4.2（見せ方＝案B′）| Task 4 |
| §4.3（不動産との違い）| Task 9 |
| §5（列の改名・画面の並び）| Task 6 |
| §6（節目 2 つ）| Task 7 |
| §7（取込の自動入力・予告・トランザクション）| Task 8 |
| §8（ボード）| Task 5 |
| §9（既存データ）| Task 12 Step 2 で**流す前に実測**して確かめる |
| §10（やらないこと）| どのタスクにも含めていない |
| §12（検証）| Task 10 / 11 |

⚠ **設計書 §11 の「触るファイル」表は 1 箇所ずれている。** KPI と絞り込みと遅延バッジは
`housing/schedules/index.blade.php` ではなく**共有の `_partials/_schedule_board.blade.php`** にある
（`housing/schedules/index.blade.php` は 19 行で partial を include するだけ）。
このプランは共有 partial 側を直し、部署の知識を持ち込まないようサービスから
データで駆動する形にしている（決定 P5）。

⚠ **`hs_properties` / `hs_custom_orders` には `CREATE TABLE` の DDL がリポジトリに無い**
（本番で直接作られたまま。`survey_questions` と同じ状況）。よって
`ScheduleSchemaTest` のような DDL↔trait の突き合わせは**この 2 テーブルには存在しない**。
Task 6 は ALTER ファイルとテスト用スキーマを対で直し、その対応をテストで固定している。
