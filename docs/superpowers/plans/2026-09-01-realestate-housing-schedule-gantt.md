# 工程表（ガント表示）実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の 4 親に「工程表」を足し、各詳細ページのガントカードと、部署ごとの横断ボード 2 画面で見られるようにする。

**Architecture:** ポリモーフィックな `schedule_steps` テーブル 1 本に 4 親がぶら下がる。日付 → 位置(%) の変換は PHP（`GanttScale`）だけが持ち、Blade は inline style で置くだけ。**JS ライブラリ・外部 CDN は 1 本も足さない。** 詳細カードは共通 partial 1 本を 4 画面が `@include` し、横断ボードは対象クラスの配列だけを差し替えた同一サービスを 2 つのコントローラが呼ぶ。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）/ MySQL 8（本番）・SQLite in-memory（テスト）/ Blade + Alpine.js 3 / Carbon 3.11.3

**正本:** `docs/superpowers/specs/2026-08-31-realestate-schedule-gantt-design.md`
**モック:** `docs/mockups/realestate/schedule-gantt-proposals.html` / `docs/mockups/realestate/schedule-board.html`
⚠ **モックは正本ではない**（設計書 §2.1 に 4 点のズレが明記されている）。迷ったら設計書に従う。

---

## 0. 着手前に読むこと

- `CLAUDE.md` — Top traps 16 件
- `docs/RULES.md` — Bug #1〜#55
- 設計書（上記）— **全部**。特に §3.7 / §5.2 / §5.4 / §6.2 は実装の分岐そのもの

### 実測済みの前提（2026-09-01 にこのプランを書くとき測った）

| 項目 | 実測値 |
|---|---|
| ベースラインのテスト | **1050 tests / 6680 assertions green**（36 秒） |
| Carbon | 3.11.3。`diffInDays()` は **符号つき float** を返す（`$a->diffInDays($b)` は $b が後なら正） |
| `endOfMonth()` | 時刻が **23:59:59.999999** になる。`startOfDay()` を通さずに引くと **1 日多く**出る（実測: 2026-02-01〜2026-08-31 が 212 日でなく 213 日になった） |
| morph map | **未設定**（`grep -rn "morphMap" app/ config/` が 0 件）→ `schedulable_type` には FQCN がそのまま入る |
| `after_or_equal:planned_start` で planned_start が null | **PASS**（誤って弾かれない。実測済み） |
| `required_with:actual_end` | 実測どおり `actual_start` を要求する |
| 4 親のルートパラメータ名 | `{procurement}` / `{project}` / `{property}` / `{customOrder}`（実測） |
| テスト用スキーマ trait | `CreatesRealEstateSchema` **1 本**で 4 親すべてを作っている（16 テーブル） |
| worktree の `vendor` | **無い**（Task 0 で入れる） |

### コマンドの実行場所

**このプランのコマンドは、断りが無い限り worktree
`/Users/masanori/site/manage/.claude/worktrees/realestate-schedule` の中で実行する。**
`cd` を省いて書いてある短いコマンド（`./vendor/bin/phpunit --filter …` など）も同じ。
⚠ **main repo（`/Users/masanori/site/manage`）で実行してよいのは Task 13 の
ff-merge / `composer dump-autoload` / `./deploy.sh` だけ。**
main repo には `vendor/bin/phpunit` が無く（`--no-dev`）、dev 依存を入れて戻し忘れると
`deploy.sh` がそれを本番へ rsync する。

### テスト本数の書き方について

各タスクの「全体が壊れていないことを確認する」に書いた本数（`OK (1086 tests, ...)` 等）は
**1050 からの積み上げの目安**。テストを 1 本足し引きすればズレる。**その数字ちょうどでないことを
理由に止まらないこと。** 見るべきは ①**緑であること** ②**本数が前のタスクより減っていないこと**
（減っていたら既存テストを壊している）。
一方、`--filter` 付きの本数は**そのファイルの中身がプランどおりなら必ず一致する**ので、
そちらが合わないときは書き写しの漏れを疑う。

### このプランで設計書に足した決定（設計書に書かれていない部分）

| # | 決定 | 理由 |
|---|---|---|
| A | Ajax の応答に **サーバでレンダリングし直したガントの HTML 断片**（`gantt_html`）を載せ、JS はそれを差し替えるだけにする | §4.4 の「Ajax 即時保存」と §5 の「位置計算は PHP だけ」を両立させる唯一の形。JS 側で % を再計算すると **同じ計算の 2 実装**になり無音で漂流する（Bug #41 / #47）。日付を動かすと軸の範囲（§5.5）ごと変わるので、部分的な再計算では原理的に足りない |
| B | §8.2 の `ParsesForms` は Ajax には直接使えないので、**画面が実際に出力したエンドポイント設定（`SCHEDULE_ENDPOINTS`）をテストが抜き出して、それを叩く** | §8.2 の意図は「画面が描いたものをそのまま送り返す」。フォームが無い画面での同等物がこれ。URL をテスト側で `route()` から組むと、画面側が壊れても緑のまま通る（Bug #47） |
| C | `HasScheduleSteps` の親ごとメソッドは **`abstract`** にする（既定実装を置かない） | 既定値を置くと、新しい親を足した人が override を忘れた瞬間に**無音で空欄**になる（設計書 §3.2 の警告そのもの）。`abstract` なら PHP が Fatal で止める |
| D | ボードに **ステータスバッジを出さない**（モックには在る） | §4.2 の「中身」の列挙に無い。§2.1「モックを正本にしない」に従う。必要になったら足す |
| E | 遅延（`delayDays`）と進捗（`done` / `running` / `todo`）を **別の軸**として返す | 「完了したが遅れた」が表現できなくなるのを防ぐ。表示側がどう混ぜるかを決める |

---

## 1. ファイル構成

### 新規

| ファイル | 責務 |
|---|---|
| `database/sql/2026-08-31-create-schedule-steps.sql` | 本番 DDL（raw SQL 管理） |
| `app/Enums/ScheduleStepCategory.php` | 5 分類のラベルと色（hex）。色分け以外の意味を持たない |
| `app/Support/GanttScale.php` | 区間 `[from, to]` を持ち、日付 → 位置(%) / 幅(%) に変換するだけ |
| `app/Support/ScheduleStepStatus.php` | 遅延日数・進捗状態・◆ の塗り分けの**唯一の判定場所** |
| `app/Support/LanePacker.php` | 重なる工程を段に振り分ける（greedy interval partitioning） |
| `app/Models/ScheduleStep.php` | 工程 1 行。描画区間（§5.2）と行種別（§3.7）を持つ |
| `app/Models/Concerns/HasScheduleSteps.php` | 4 親が `use` する。親ごとの差を吸収する |
| `app/Http/Controllers/ScheduleStepController.php` | 工程 CRUD（4 親共通・Ajax） |
| `app/Http/Controllers/RealEstate/ScheduleBoardController.php` | 不動産ボード |
| `app/Http/Controllers/Housing/ScheduleBoardController.php` | 住宅ボード |
| `app/Services/ScheduleCardService.php` | 詳細カード 1 枚ぶんの表示データを組み立てる |
| `app/Services/ScheduleBoardService.php` | ボード 1 枚ぶん（KPI・フィルタ・行）を組み立てる |
| `resources/views/_partials/_schedule_section.blade.php` | 詳細カード（ガント＋編集テーブル）。**4 画面が共有する唯一の定義** |
| `resources/views/_partials/_schedule_gantt.blade.php` | ガント本体だけ。Ajax の応答でも同じものをレンダリングする |
| `resources/views/_partials/_schedule_board.blade.php` | ボード本体（2 部署で共有） |
| `resources/views/realestate/schedules/index.blade.php` | 不動産ボードの画面 |
| `resources/views/housing/schedules/index.blade.php` | 住宅ボードの画面 |

⚠ **partial は `resources/views/_partials/` に置く**（部署ディレクトリに置かない。設計書 §4.1）。

### 変更

| ファイル | 変更内容 |
|---|---|
| `tests/Concerns/CreatesRealEstateSchema.php` | `schedule_steps` を足す |
| `app/Models/ReProcurement.php` / `ReProject.php` / `HsProperty.php` / `HsCustomOrder.php` | `use HasScheduleSteps` ＋ 6 メソッド実装 |
| `app/Http/Controllers/RealEstate/ProcurementController.php` / `ProjectController.php` | `show()` に `$schedule` を足す ＋ `destroy()` で工程を消す |
| `app/Http/Controllers/Housing/PropertyController.php` / `CustomOrderController.php` | 同上 |
| `resources/views/realestate/procurements/show.blade.php` / `projects/show.blade.php` | `@include('_partials._schedule_section')` |
| `resources/views/housing/properties/show.blade.php` / `custom-orders/show.blade.php` | 同上 |
| `routes/web.php` | 18 本追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 「工程表」を 2 部署 × 2 ブロック = 4 箇所 |
| `lang/ja/validation.php` | `attributes` に 4 件（`planned_start` / `planned_end` / `actual_start` / `actual_end`） |
| `tests/Feature/AjaxErrorFeedbackTest.php` | `VIEWS_NULL_RETURN` に新 partial を登録（**しないと落ちる**） |
| `docs/BACKLOG.md` | 完了記録 |

### 新規テスト

| ファイル | 対象 |
|---|---|
| `tests/Unit/Schedule/ScheduleStepCategoryTest.php` | §3.6 |
| `tests/Unit/Support/GanttScaleTest.php` | §5.1 |
| `tests/Unit/Support/ScheduleStepStatusTest.php` | §5.4 |
| `tests/Unit/Support/LanePackerTest.php` | §5.3 |
| `tests/Feature/Schedule/ScheduleSchemaTest.php` | §8.3（DDL と trait の列一致） |
| `tests/Feature/Schedule/ScheduleAutoMilestoneTest.php` | §3.4 |
| `tests/Feature/Schedule/ScheduleStepCrudTest.php` | §8.2（4 親の往復） |
| `tests/Feature/Schedule/ScheduleStepAuthorizationTest.php` | §6.2（IDOR・ロール） |
| `tests/Feature/Schedule/ScheduleRouteWiringTest.php` | §6.1 / §6.3（全件分類） |
| `tests/Feature/Schedule/ScheduleSectionRenderTest.php` | §4.1（4 画面 ＋ partial の唯一性） |
| `tests/Feature/Schedule/ScheduleBoardTest.php` | §4.2 / §4.3 |
| `tests/Feature/Schedule/ScheduleParentDeletionTest.php` | §3.5（4 親） |

---

## Task 0: 作業環境を用意し、ベースラインを記録する

**Files:**
- 変更なし（`vendor/` は `.gitignore` 済み。**コミットしない**）

⚠ この worktree には `vendor/` が無い（実測）。main repo の `vendor` は `--no-dev` で
`phpunit` が入っていないので、**必ずこの worktree で `composer install` する**。
⚠ main repo に dev 依存を入れてはいけない（`deploy.sh` が `vendor` を本番へ rsync する）。

- [ ] **Step 1: worktree に dev 依存を入れる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
composer install
```

- [ ] **Step 2: テストが走ることとベースラインを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: 最終行が `OK (1050 tests, 6680 assertions)`

⚠ **この数字と違ったら先へ進まない。** 差分の原因（未コミットの変更・vendor のバージョン差）を
先に潰すこと。ベースラインが揺れていると、あとの変異テストで「赤くなった理由」が読めなくなる。

- [ ] **Step 3: worktree が clean であることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && git status --porcelain
```

Expected: 何も出力されない（`vendor/` と `composer.lock` は gitignore 済み or 変更なし）

⚠ `composer.lock` に差分が出たら **`git checkout -- composer.lock` で戻す**。
依存を更新するのはこの作業の範囲外（`CLAUDE.md`「理由なく lock を更新しない」）。

---

## Task 1: `schedule_steps` テーブル（DDL ＋ テスト用スキーマ ＋ 整合テスト）

**Files:**
- Create: `database/sql/2026-08-31-create-schedule-steps.sql`
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`（末尾の `hs_custom_orders` ブロックの後）
- Test: `tests/Feature/Schedule/ScheduleSchemaTest.php`

⚠ **両方に足すこと。** 片方だけだと「本番は動くのにテストだけ落ちる」または
「テストは緑なのに本番で `Unknown column`」になる（設計書 §3.1）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 本番 DDL（database/sql）とテスト用スキーマ（CreatesRealEstateSchema）が
 * 同じ列を宣言していることを固定する（設計書 §8.3）。
 *
 * ⚠ schedule_steps は re_* / hs_* と同じ raw SQL 管理で Laravel migration が無い。
 *   よって「テストは緑なのに本番で Unknown column」を止められるのはこのテストだけ。
 *   ⚠ 逆方向（DDL にあるのに trait に無い）も見る。片方向だけだと、
 *     trait に列を足し忘れたまま本番 DDL を直したときに素通りする。
 */
class ScheduleSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const DDL = 'database/sql/2026-08-31-create-schedule-steps.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_raw_sql_and_test_schema_declare_the_same_columns(): void
    {
        $sql = file_get_contents(base_path(self::DDL));

        // CREATE TABLE 本体だけを見る（INDEX / KEY / PRIMARY KEY / CONSTRAINT の行は列ではない）
        preg_match('/CREATE TABLE[^(]*\((.*)\)\s*ENGINE/s', $sql, $m);
        $this->assertNotEmpty($m, 'DDL から CREATE TABLE の本体を切り出せない');

        $ddlColumns = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if (preg_match('/^`([a-z_]+)`\s+[A-Z]/', $line, $c)) {
                $ddlColumns[] = $c[1];
            }
        }
        sort($ddlColumns);

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThanOrEqual(14, count($ddlColumns), 'DDL の列を拾えていない（走査の空振り防止）');

        $testColumns = Schema::getColumnListing('schedule_steps');
        sort($testColumns);

        $this->assertSame(
            $ddlColumns,
            $testColumns,
            "本番 DDL とテスト用スキーマの列が食い違っています。\n"
            . 'DDL: ' . implode(',', $ddlColumns) . "\n"
            . 'test: ' . implode(',', $testColumns)
        );
    }

    /** 親を引くときのインデックスが DDL にあること（ボードは全件を舐めるので効く） */
    public function test_the_owner_index_is_declared(): void
    {
        $this->assertStringContainsString(
            '(`schedulable_type`, `schedulable_id`, `sort_order`)',
            file_get_contents(base_path(self::DDL)),
            '親 + 並び順の複合インデックスが DDL にありません'
        );
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleSchemaTest
```

Expected: FAIL。`file_get_contents(...): Failed to open stream` （DDL がまだ無い）

- [ ] **Step 3: 本番 DDL を書く**

`database/sql/2026-08-31-create-schedule-steps.sql`:

```sql
-- 工程表（不動産 / 住宅事業）— 2026-08-31
--
-- 設計書: docs/superpowers/specs/2026-08-31-realestate-schedule-gantt-design.md §3.1
--
-- ⚠ tests/Concerns/CreatesRealEstateSchema.php と対で維持すること。
--   片方だけ直すと「テストは緑なのに本番で Unknown column」になる
--   （ScheduleSchemaTest::test_raw_sql_and_test_schema_declare_the_same_columns が拾う）。
--
-- ⚠ テーブル名に re_ / hs_ の接頭辞を付けない。不動産と住宅の両方がぶら下がるため
--   （attachments / buyers / users と同じ扱い）。
--
-- ⚠ 外部キーは張らない。schedulable_type が 4 種類あるため単一の FK では表現できない。
--   親を消したときの削除は各コントローラの destroy() が行う（設計書 §3.5）。
--
-- 適用: sudo mysql manage < database/sql/2026-08-31-create-schedule-steps.sql
--   CREATE TABLE IF NOT EXISTS なので再実行して安全。
--
-- ⚠ 本番反映は **この DDL が先・./deploy.sh が後**。逆にすると詳細 4 画面とボード 2 画面が
--   Base table or view not found で 500 する（設計書 §7）。

CREATE TABLE IF NOT EXISTS `schedule_steps` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedulable_type` VARCHAR(255)    NOT NULL COMMENT '親クラスの FQCN',
  `schedulable_id`   BIGINT UNSIGNED NOT NULL COMMENT '親の id',
  `name`             VARCHAR(100)    NOT NULL COMMENT '工程名（自由入力）',
  `category`         VARCHAR(20)     NOT NULL DEFAULT 'other' COMMENT '色分けのみに使う分類',
  `planned_start`    DATE            NULL COMMENT '予定開始',
  `planned_end`      DATE            NULL COMMENT '予定終了',
  `actual_start`     DATE            NULL COMMENT '実績開始',
  `actual_end`       DATE            NULL COMMENT '実績終了',
  `sort_order`       INT             NOT NULL DEFAULT 0 COMMENT '画面の並び順',
  `notes`            VARCHAR(255)    NULL COMMENT '備考',
  `created_by`       BIGINT UNSIGNED NULL,
  `updated_by`       BIGINT UNSIGNED NULL,
  `created_at`       TIMESTAMP       NULL,
  `updated_at`       TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sched_owner` (`schedulable_type`, `schedulable_id`, `sort_order`),
  KEY `idx_sched_planned_start` (`planned_start`),
  KEY `idx_sched_planned_end`   (`planned_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工程表の 1 行';
```

- [ ] **Step 4: テスト用スキーマに足す**

`tests/Concerns/CreatesRealEstateSchema.php` の `hs_custom_orders` の `Schema::create(...)` ブロックの**直後**（`}` で `createRealEstateSchema()` が閉じる直前）に挿入:

```php
        // 工程表（設計書 §3.1）。⚠ database/sql/2026-08-31-create-schedule-steps.sql と対で維持する。
        //   ⚠ 4 親（re_procurements / re_projects / hs_properties / hs_custom_orders）が
        //     ポリモーフィックにぶら下がるので re_ / hs_ の接頭辞を付けない。
        Schema::create('schedule_steps', function (Blueprint $t) {
            $t->id();
            $t->string('schedulable_type', 255);
            $t->unsignedBigInteger('schedulable_id');
            $t->string('name', 100);
            $t->string('category', 20)->default('other');
            $t->date('planned_start')->nullable();
            $t->date('planned_end')->nullable();
            $t->date('actual_start')->nullable();
            $t->date('actual_end')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->index(['schedulable_type', 'schedulable_id', 'sort_order'], 'idx_sched_owner');
        });
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleSchemaTest
```

Expected: `OK (2 tests, ...)`

- [ ] **Step 6: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1052 tests, ...)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add database/sql/2026-08-31-create-schedule-steps.sql tests/Concerns/CreatesRealEstateSchema.php tests/Feature/Schedule/ScheduleSchemaTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程表テーブルの DDL とテスト用スキーマを対で足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: `ScheduleStepCategory` enum

**Files:**
- Create: `app/Enums/ScheduleStepCategory.php`
- Test: `tests/Unit/Schedule/ScheduleStepCategoryTest.php`
  （⚠ Enum のテストは **Support ではなく領域フォルダ**に置くのが既存の流儀。
  実測: `App\Enums\ProcurementStatus` → `tests/Unit/RealEstate/`、
  `App\Enums\AreaTenantStatus` → `tests/Unit/Tenant/`。
  `App\Support\*` のほうは `tests/Unit/Support/` が正しいので Task 3〜5 は据え置き）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Schedule/ScheduleStepCategoryTest.php`:

```php
<?php

namespace Tests\Unit\Schedule;

use App\Enums\ScheduleStepCategory;
use PHPUnit\Framework\TestCase;

/**
 * 工程の分類（設計書 §3.6）。**色分け以外の意味を持たない。**
 *
 * ⚠ 色は hex を返す。Tailwind クラスを返さないこと
 *   （ガントの棒は inline style で置くため。CLAUDE.md「ステータスバッジ」の規約と同じ）。
 */
class ScheduleStepCategoryTest extends TestCase
{
    public function test_the_five_categories_match_the_design(): void
    {
        $this->assertSame(
            ['permit', 'work', 'survey', 'sale', 'other'],
            ScheduleStepCategory::values(),
            '分類を増減させないこと（設計書 §3.6: 住宅事業向けに work を細分化しない）'
        );
    }

    public function test_every_category_has_a_japanese_label_and_a_hex_color(): void
    {
        $expected = [
            'permit' => ['許認可・申請', '#3B82F6'],
            'work'   => ['工事', '#059669'],
            'survey' => ['測量・登記', '#8B5CF6'],
            'sale'   => ['販売', '#F59E0B'],
            'other'  => ['その他', '#6B7280'],
        ];

        foreach (ScheduleStepCategory::cases() as $case) {
            [$label, $color] = $expected[$case->value];
            $this->assertSame($label, $case->label());
            $this->assertSame($color, $case->color());
        }
    }

    /**
     * ⚠ 色は inline style に入れるので hex でなければならない。
     *   Tailwind クラス（bg-blue-500 など）に戻す変異をここで止める。
     */
    public function test_colors_are_hex_not_tailwind_classes(): void
    {
        foreach (ScheduleStepCategory::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9A-F]{6}$/',
                $case->color(),
                "{$case->value} の色が hex でない（ガントは inline style で塗るので Tailwind クラスは効かない）"
            );
        }
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleStepCategoryTest
```

Expected: FAIL。`Class "App\Enums\ScheduleStepCategory" not found`

- [ ] **Step 3: enum を書く**

`app/Enums/ScheduleStepCategory.php`:

```php
<?php

namespace App\Enums;

/**
 * 工程の分類（設計書 §3.6）。
 *
 * ⚠ **色分け以外の意味を持たない。** 集計にも権限にも使わない。
 * ⚠ 住宅事業向けに work を細分化しない（着工・上棟・内装…）。
 *   工程名が自由入力なので、分類を増やすほど「どれを選ぶか」で迷いが増える。
 * ⚠ モデルで casts() にかけるので、読み出した属性は既に enum インスタンス。
 *   キャスト済み属性に tryFrom() を呼ばないこと（Bug #22）。
 */
enum ScheduleStepCategory: string
{
    case Permit = 'permit';
    case Work   = 'work';
    case Survey = 'survey';
    case Sale   = 'sale';
    case Other  = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Permit => '許認可・申請',
            self::Work   => '工事',
            self::Survey => '測量・登記',
            self::Sale   => '販売',
            self::Other  => 'その他',
        };
    }

    /**
     * ガントの棒の色。
     *
     * ⚠ **hex を返す。Tailwind クラスは返さない。** 棒は inline style で塗るため
     *   （CLAUDE.md「ステータスバッジはモデルのメソッド経由・Tailwind クラス指定 NG」と同じ理由）。
     */
    public function color(): string
    {
        return match ($this) {
            self::Permit => '#3B82F6',
            self::Work   => '#059669',
            self::Survey => '#8B5CF6',
            self::Sale   => '#F59E0B',
            self::Other  => '#6B7280',
        };
    }

    /** validate() の in: ルール用 */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleStepCategoryTest
```

Expected: `OK (3 tests, ...)`

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Enums/ScheduleStepCategory.php tests/Unit/Schedule/ScheduleStepCategoryTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程の分類 enum を足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 3: `GanttScale`（日付 → 位置(%)）

**Files:**
- Create: `app/Support/GanttScale.php`
- Test: `tests/Unit/Support/GanttScaleTest.php`

⚠ **`width()` の `+1` が要る**（両端を含めるため）。無いと 1 日だけの工程が幅 0 で消える（設計書 §5.1）。
⚠ **`startOfDay()` に揃えてから引く。** 実測: `endOfMonth()` は時刻が `23:59:59.999999` になるので、
揃えないと 2026-02-01〜2026-08-31 が **213 日**（正は 212 日）になる。
⚠ **clamp しない。** 範囲外は 0% 未満 / 100% 超をそのまま返す（呼び出し側が clamp する）。
clamp を `GanttScale` の責務にすると「範囲がおかしい」ことに気づけなくなる（設計書 §5.1）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/GanttScaleTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\GanttScale;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ガントの時間軸（設計書 §5.1）。
 *
 * ⚠ **timezone を UTC に固定する。** Laravel を起動しない Unit テストは config/app.php でなく
 *   php.ini の date.timezone に支配され、phpunit.xml も無指定なので**走らせるマシン任せ**になる
 *   （Bug #54 ①。epoch の回帰テストが 6 環境中 5 環境で無音になっていた前例がある）。
 *   ⚠ **tearDown() で必ず戻す。** 戻さないと同一プロセスの後続テストへ UTC が漏れ、
 *     別のテストの検出力を削る。
 */
class GanttScaleTest extends TestCase
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

    private function january(): GanttScale
    {
        // 2026-01-01 〜 2026-01-31 = 31 日
        return new GanttScale(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
    }

    private function d(string $s): CarbonImmutable
    {
        return CarbonImmutable::parse($s);
    }

    public function test_total_days_includes_both_ends(): void
    {
        $this->assertSame(31, $this->january()->totalDays());
    }

    public function test_left_is_zero_at_the_start_of_the_range(): void
    {
        $this->assertSame(0.0, $this->january()->left($this->d('2026-01-01')));
    }

    public function test_left_of_the_last_day_is_not_one_hundred(): void
    {
        // 最終日は「最後の 1 日ぶんの幅」を残した位置から始まる
        $this->assertEqualsWithDelta(96.7742, $this->january()->left($this->d('2026-01-31')), 0.0001);
    }

    /**
     * ⚠ **これが `+1` を守るテスト。** `+1` を消すと 0.0 になり、1 日の工程が画面から消える。
     */
    public function test_a_single_day_step_has_a_non_zero_width(): void
    {
        $w = $this->january()->width($this->d('2026-01-01'), $this->d('2026-01-01'));

        $this->assertGreaterThan(0.0, $w, '1 日だけの工程は幅 0 になってはいけない（width の +1）');
        $this->assertEqualsWithDelta(3.2258, $w, 0.0001);
    }

    public function test_width_spans_both_ends(): void
    {
        // 1/1 〜 1/31 は 31 日 = 区間まるごと
        $this->assertEqualsWithDelta(100.0, $this->january()->width($this->d('2026-01-01'), $this->d('2026-01-31')), 0.0001);
    }

    /**
     * ⚠ 範囲外は clamp せずそのまま返す（呼び出し側で clamp する）。
     */
    public function test_dates_outside_the_range_are_not_clamped(): void
    {
        $this->assertEqualsWithDelta(-3.2258, $this->january()->left($this->d('2025-12-31')), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->january()->left($this->d('2026-02-01')), 0.0001);
    }

    /**
     * ⚠ **時刻成分を持つ日付を渡されても 1 日ずれない。**
     *   endOfMonth() は 23:59:59.999999 を返すので、startOfDay() を通さないと日数が 1 多く出る
     *   （実測: 2026-02-01 〜 2026-08-31 が 213 日になった。正は 212 日）。
     */
    public function test_time_components_are_normalised_to_the_start_of_the_day(): void
    {
        $scale = new GanttScale(
            CarbonImmutable::parse('2026-02-01')->startOfMonth(),
            CarbonImmutable::parse('2026-08-31')->endOfMonth(),   // 23:59:59.999999
        );

        $this->assertSame(212, $scale->totalDays(), 'endOfMonth の時刻成分で 1 日ずれている');
    }

    /** うるう日をまたぐ区間（2024 年は閏年） */
    public function test_a_range_across_a_leap_day(): void
    {
        $scale = new GanttScale(CarbonImmutable::parse('2024-02-01'), CarbonImmutable::parse('2024-03-31'));

        $this->assertSame(60, $scale->totalDays(), '2024 年 2 月は 29 日');
        $this->assertEqualsWithDelta(48.3333, $scale->left($this->d('2024-03-01')), 0.0001);
        // 2/28・2/29・3/1 の 3 日ぶん
        $this->assertEqualsWithDelta(5.0, $scale->width($this->d('2024-02-28'), $this->d('2024-03-01')), 0.0001);
    }

    /** 区間の始点と終点が同じ日でも 0 除算しない */
    public function test_a_single_day_range_does_not_divide_by_zero(): void
    {
        $scale = new GanttScale(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-01'));

        $this->assertSame(1, $scale->totalDays());
        $this->assertSame(0.0, $scale->left($this->d('2026-01-01')));
        $this->assertEqualsWithDelta(100.0, $scale->width($this->d('2026-01-01'), $this->d('2026-01-01')), 0.0001);
    }

    /** clamp は呼び出し側の責務だが、道具はここに置く（実装が 1 箇所で済む） */
    public function test_clamp_keeps_bars_inside_the_track(): void
    {
        $this->assertSame(0.0, GanttScale::clamp(-12.5, 0.0, 100.0));
        $this->assertSame(100.0, GanttScale::clamp(140.0, 0.0, 100.0));
        $this->assertSame(42.0, GanttScale::clamp(42.0, 0.0, 100.0));
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter GanttScaleTest
```

Expected: FAIL。`Class "App\Support\GanttScale" not found`

- [ ] **Step 3: `GanttScale` を書く**

`app/Support/GanttScale.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ガントの時間軸（設計書 §5.1）。区間 [from, to] を保持し、日付を位置(%) と幅(%) に変換する。
 *
 * ⚠ **JS ライブラリを足さない方針の中核。** 位置の計算はここだけが持ち、Blade は
 *   結果を inline style に置くだけにする。JS 側で同じ計算を持つと無音で漂流する（Bug #41）。
 *
 * ⚠ **日付は必ず startOfDay() に揃えてから引く。** 揃えないと実行環境の timezone や
 *   時刻成分で 1 日ずれる（実測: endOfMonth() は 23:59:59.999999 を返すため、
 *   2026-02-01 〜 2026-08-31 が 212 日でなく 213 日になった）。
 *
 * ⚠ **範囲外の日付を clamp しない。** 0% 未満 / 100% 超をそのまま返す。
 *   ここで clamp すると「範囲の作り方がおかしい」ことに気づけなくなる。
 *   棒が枠外へ飛び出さないようにするのは呼び出し側の責務で、道具は clamp() に置いてある。
 */
class GanttScale
{
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    private int $totalDays;

    public function __construct(CarbonInterface $from, CarbonInterface $to)
    {
        $this->from = CarbonImmutable::instance($from)->startOfDay();
        $this->to   = CarbonImmutable::instance($to)->startOfDay();

        // 両端を含む日数。始点と終点が同じ日なら 1（0 除算を防ぐ）。
        $this->totalDays = max(1, self::days($this->from, $this->to) + 1);
    }

    public function from(): CarbonImmutable
    {
        return $this->from;
    }

    public function to(): CarbonImmutable
    {
        return $this->to;
    }

    public function totalDays(): int
    {
        return $this->totalDays;
    }

    /** 区間内かどうか（今日線を描くかの判定に使う） */
    public function contains(CarbonInterface $date): bool
    {
        $d = CarbonImmutable::instance($date)->startOfDay();

        return $d->greaterThanOrEqualTo($this->from) && $d->lessThanOrEqualTo($this->to);
    }

    /** 区間の先頭から見た位置（%）。範囲外は負や 100 超を返す。 */
    public function left(CarbonInterface $date): float
    {
        return self::days($this->from, CarbonImmutable::instance($date)->startOfDay())
            / $this->totalDays * 100;
    }

    /**
     * 開始日から終了日までの幅（%）。
     *
     * ⚠ **`+ 1` を消さないこと。** 両端を含めるための 1 日で、これが無いと
     *   1 日だけの工程（start === end）が幅 0 になって画面から消える。
     */
    public function width(CarbonInterface $start, CarbonInterface $end): float
    {
        $s = CarbonImmutable::instance($start)->startOfDay();
        $e = CarbonImmutable::instance($end)->startOfDay();

        return (self::days($s, $e) + 1) / $this->totalDays * 100;
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * 日数の差（符号つき）。
     *
     * ⚠ Carbon 3 の diffInDays() は **float** を返す（実測 3.11.3）。DST のある地域で
     *   23 時間の日があると 0.958… のような値になりうるので round() で整数に丸める。
     *   両端を startOfDay() に揃えてあるので、通常は誤差なく整数になる。
     */
    private static function days(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return (int) round($a->diffInDays($b));
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter GanttScaleTest
```

Expected: `OK (10 tests, ...)`

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Support/GanttScale.php tests/Unit/Support/GanttScaleTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 日付を位置(%)に変換する GanttScale を足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 4: `ScheduleStepStatus`（遅延判定・進捗・◆ の塗り分け）

**Files:**
- Create: `app/Support/ScheduleStepStatus.php`
- Test: `tests/Unit/Support/ScheduleStepStatusTest.php`

⚠ **判定はここ 1 箇所に集約する**（設計書 §5.4）。詳細カード・ボードのバッジ・KPI が
別々に計算すると画面ごとに数が食い違う（Bug #46）。

⚠ **遅延と進捗は別の軸**（決定 E）。「完了したが遅れた」を表現できなくしないため、
`delayDays()` と `progress()` を分ける。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ScheduleStepStatusTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 遅延判定（設計書 §5.4）。
 *
 * ⚠ timezone を UTC に固定し、tearDown() で戻す（Bug #54 ①）。
 * ⚠ 「今日」は必ず引数で渡す。now() を内部で呼ぶと、実行日によって結果が変わる
 *   テストになり、時刻を凍結したつもりでも効いていないことに気づけない。
 */
class ScheduleStepStatusTest extends TestCase
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

    private function d(?string $s): ?CarbonImmutable
    {
        return $s === null ? null : CarbonImmutable::parse($s);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-31');
    }

    // ---- 分岐 1: 実績終了あり ----

    public function test_finished_after_the_planned_end_is_late_by_that_many_days(): void
    {
        $this->assertSame(
            16,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-10-16'), $this->today())
        );
    }

    public function test_finished_before_the_planned_end_is_not_late(): void
    {
        $this->assertSame(
            0,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-09-20'), $this->today())
        );
    }

    /** ⚠ 境界: ちょうど同じ日は遅延ではない（`>` であって `>=` ではない） */
    public function test_finishing_exactly_on_the_planned_end_is_not_late(): void
    {
        $this->assertSame(
            0,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-09-30'), $this->today()),
            '予定終了ちょうどに終わったのを遅延にしないこと'
        );
    }

    // ---- 分岐 2: 実績開始あり・終了なし（進行中） ----

    public function test_still_running_past_the_planned_end_is_late_from_today(): void
    {
        // 今日 2026-08-31、予定終了 2026-08-20 → 11 日遅れ
        $this->assertSame(
            11,
            ScheduleStepStatus::delayDays($this->d('2026-08-20'), null, $this->today())
        );
    }

    public function test_still_running_before_the_planned_end_is_not_late(): void
    {
        $this->assertSame(
            0,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), null, $this->today())
        );
    }

    // ---- 分岐 3: 実績なし ----

    /**
     * ⚠ **未着手のまま予定終了を過ぎたものも遅延。**
     *   含めないと「着手すらしていない工程が一番危ないのに一番静か」という逆転が起きる。
     *   ⚠ モックはこの規則になっていない（設計書 §2.1）。設計書 §5.4 が正本。
     */
    public function test_never_started_past_the_planned_end_is_late(): void
    {
        $this->assertSame(
            11,
            ScheduleStepStatus::delayDays($this->d('2026-08-20'), null, $this->today()),
            '未着手のまま予定終了を過ぎたら遅延（設計書 §5.4）'
        );
    }

    // ---- 分岐 4: planned_end が NULL ----

    public function test_a_step_without_a_planned_end_is_never_late(): void
    {
        $this->assertSame(0, ScheduleStepStatus::delayDays(null, null, $this->today()));
        $this->assertSame(0, ScheduleStepStatus::delayDays(null, $this->d('2026-12-31'), $this->today()));
    }

    public function test_is_late_is_derived_from_delay_days(): void
    {
        $this->assertTrue(ScheduleStepStatus::isLate($this->d('2026-08-20'), null, $this->today()));
        $this->assertFalse(ScheduleStepStatus::isLate($this->d('2026-09-30'), null, $this->today()));
    }

    // ---- 進捗（遅延とは別の軸） ----

    public function test_progress_is_independent_of_lateness(): void
    {
        // 遅れて終わった = 完了 かつ 遅延
        $this->assertSame(
            ScheduleStepStatus::DONE,
            ScheduleStepStatus::progress($this->d('2026-06-01'), $this->d('2026-10-16'))
        );
        $this->assertSame(
            16,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-10-16'), $this->today())
        );
    }

    public function test_progress_states(): void
    {
        $this->assertSame(ScheduleStepStatus::DONE, ScheduleStepStatus::progress($this->d('2026-06-01'), $this->d('2026-07-01')));
        $this->assertSame(ScheduleStepStatus::RUNNING, ScheduleStepStatus::progress($this->d('2026-06-01'), null));
        $this->assertSame(ScheduleStepStatus::TODO, ScheduleStepStatus::progress(null, null));
    }

    /**
     * ⚠ 実績終了だけが入って実績開始が空、という状態は validate() が禁じている（設計書 §4.5）。
     *   ここでは万一入っても「完了」に倒す（描画側の分岐が壊れないように）。
     */
    public function test_an_end_without_a_start_still_counts_as_done(): void
    {
        $this->assertSame(ScheduleStepStatus::DONE, ScheduleStepStatus::progress(null, $this->d('2026-07-01')));
    }

    // ---- 自動マイルストーンの塗り分け（設計書 §3.4） ----

    public function test_a_milestone_on_or_before_today_is_reached(): void
    {
        $this->assertTrue(ScheduleStepStatus::isReached($this->d('2026-08-30'), $this->today()));
        $this->assertTrue(ScheduleStepStatus::isReached($this->d('2026-08-31'), $this->today()), '今日ちょうどは到達済み');
        $this->assertFalse(ScheduleStepStatus::isReached($this->d('2026-09-01'), $this->today()));
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleStepStatusTest
```

Expected: FAIL。`Class "App\Support\ScheduleStepStatus" not found`

- [ ] **Step 3: `ScheduleStepStatus` を書く**

`app/Support/ScheduleStepStatus.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 工程の遅延・進捗の判定（設計書 §5.4）。
 *
 * ⚠ **判定はここ 1 箇所だけ。** 詳細カード・ボードのバッジ・KPI が別々に計算すると
 *   画面ごとに数が食い違う（Bug #46）。
 *
 * ⚠ **遅延と進捗は別の軸として返す。** 混ぜると「完了したが遅れた」が表現できなくなる。
 *   どう見せるか（赤枠にするのかチップにするのか）は表示側が決める。
 *
 * ⚠ **「今日」は必ず引数で受け取る。** 内部で now() を呼ぶと、テストが実行日に依存して
 *   「凍結したつもりで効いていない」状態を作る。
 */
final class ScheduleStepStatus
{
    public const DONE    = 'done';

    public const RUNNING = 'running';

    public const TODO    = 'todo';

    /**
     * 遅延日数。遅れていなければ 0（設計書 §5.4）。
     *
     * ```
     * planned_end が NULL      → 判定しない（0）
     * 実績終了あり             → actual_end  > planned_end なら その差
     * 実績終了なし             → 今日        > planned_end なら その差
     * ```
     *
     * ⚠ 「実績開始あり・終了なし」と「実績なし」は**同じ式**になる（どちらも今日と比べる）。
     *   分けて書く必要はないが、**未着手でも遅延に数えるのが肝**なので消さないこと。
     *   落とすと「着手すらしていない工程が一番危ないのに一番静か」という逆転が起きる。
     *
     * ⚠ 判定は `>` であって `>=` ではない。予定終了ちょうどに終わったのは遅延ではない。
     */
    public static function delayDays(
        ?CarbonInterface $plannedEnd,
        ?CarbonInterface $actualEnd,
        CarbonInterface $today
    ): int {
        if ($plannedEnd === null) {
            return 0;
        }

        $due  = CarbonImmutable::instance($plannedEnd)->startOfDay();
        $mark = CarbonImmutable::instance($actualEnd ?? $today)->startOfDay();

        if ($mark->lessThanOrEqualTo($due)) {
            return 0;
        }

        return (int) round($due->diffInDays($mark));
    }

    public static function isLate(
        ?CarbonInterface $plannedEnd,
        ?CarbonInterface $actualEnd,
        CarbonInterface $today
    ): bool {
        return self::delayDays($plannedEnd, $actualEnd, $today) > 0;
    }

    /**
     * 進捗の状態。遅延とは独立。
     *
     * ⚠ 実績終了だけが入って実績開始が空、という状態は validate() が禁じている（設計書 §4.5）が、
     *   万一入っても「完了」に倒して描画側の分岐が壊れないようにする。
     */
    public static function progress(?CarbonInterface $actualStart, ?CarbonInterface $actualEnd): string
    {
        if ($actualEnd !== null) {
            return self::DONE;
        }

        return $actualStart !== null ? self::RUNNING : self::TODO;
    }

    /**
     * 自動マイルストーン（◆）の塗り分け（設計書 §3.4）。
     *
     * ⚠ **日付だけで決める。** その列が予定なのか実績なのかを知る必要はない。
     *   今日以前 → 塗りつぶし ◆ ／ 今日より後 → 白抜き ◆
     */
    public static function isReached(CarbonInterface $date, CarbonInterface $today): bool
    {
        return CarbonImmutable::instance($date)->startOfDay()
            ->lessThanOrEqualTo(CarbonImmutable::instance($today)->startOfDay());
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleStepStatusTest
```

Expected: `OK (12 tests, ...)`

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Support/ScheduleStepStatus.php tests/Unit/Support/ScheduleStepStatusTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 遅延と進捗の判定を 1 箇所に集約する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 5: `LanePacker`（重なる工程を段に振り分ける）

**Files:**
- Create: `app/Support/LanePacker.php`
- Test: `tests/Unit/Support/LanePackerTest.php`

⚠ **判定は「より後」（`<`）であって「以降」ではない**（設計書 §5.3）。
前の工程が 9/30 に終わり次が 9/30 に始まるなら**別の段**。同じ段に置くと 1 日ぶん重なって 1 本に見える。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/LanePackerTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\LanePacker;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ボードのサマリ行で、期間が重なる工程を段に振り分ける（設計書 §5.3）。
 *
 * ⚠ 段分けが無いと重なった工程が潰れて読めない（モックの初版が実際にそうだった）。
 */
class LanePackerTest extends TestCase
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

    /** @param list<array{0: string, 1: string}> $pairs */
    private function spans(array $pairs): array
    {
        return array_map(
            fn (array $p) => ['from' => CarbonImmutable::parse($p[0]), 'to' => CarbonImmutable::parse($p[1])],
            $pairs
        );
    }

    public function test_non_overlapping_steps_all_fit_on_one_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-01-31'],
            ['2026-02-05', '2026-02-28'],
            ['2026-03-10', '2026-03-20'],
        ]));

        $this->assertSame([0, 0, 0], $lanes);
        $this->assertSame(1, LanePacker::laneCount($lanes));
    }

    public function test_fully_overlapping_steps_each_get_their_own_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-03-31'],
            ['2026-01-05', '2026-03-31'],
            ['2026-01-10', '2026-03-31'],
        ]));

        $this->assertSame([0, 1, 2], $lanes);
        $this->assertSame(3, LanePacker::laneCount($lanes));
    }

    /**
     * ⚠ **同日終了・同日開始は別の段。** 判定が `<` でなく `<=` になると同じ段に載り、
     *   棒が 1 日ぶん重なって 1 本に見える。
     */
    public function test_a_step_starting_on_the_day_the_previous_one_ends_goes_to_a_new_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-09-30'],
            ['2026-09-30', '2026-12-31'],
        ]));

        $this->assertSame([0, 1], $lanes, '同日終了・同日開始は別の段（設計書 §5.3）');
    }

    /** 翌日開始なら同じ段で隣り合う */
    public function test_a_step_starting_the_next_day_shares_the_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-09-30'],
            ['2026-10-01', '2026-12-31'],
        ]));

        $this->assertSame([0, 0], $lanes);
    }

    /** 開始が同じ複数工程は必ず別の段（同点でも重なるので） */
    public function test_steps_with_the_same_start_never_share_a_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-01-10'],
            ['2026-01-01', '2026-02-10'],
        ]));

        $this->assertSame([0, 1], $lanes);
    }

    /**
     * ⚠ **返り値は入力の順序を保つ**（Blade が元の行と突き合わせるため）。
     *   内部では開始が早い順に見るが、キーは入力の添字のまま返す。
     */
    public function test_the_result_keeps_the_input_order(): void
    {
        // 入力は開始が遅い順
        $lanes = LanePacker::assign($this->spans([
            ['2026-03-01', '2026-03-31'],
            ['2026-01-01', '2026-01-31'],
        ]));

        $this->assertSame([0, 0], $lanes);
        $this->assertSame([0, 1], array_keys($lanes), '入力の添字をそのまま返すこと');
    }

    public function test_an_empty_input_produces_no_lanes(): void
    {
        $this->assertSame([], LanePacker::assign([]));
        $this->assertSame(0, LanePacker::laneCount([]));
    }

    /** 行の高さ = 8 + 段数 × 17 + 6（設計書 §5.3） */
    public function test_row_height_grows_with_the_lane_count(): void
    {
        $this->assertSame(31, LanePacker::rowHeight(1));
        $this->assertSame(48, LanePacker::rowHeight(2));
        $this->assertSame(65, LanePacker::rowHeight(3));
    }

    /** 工程が 0 件でも行がぺしゃんこにならない（最低 1 段ぶんの高さを持つ） */
    public function test_row_height_never_collapses(): void
    {
        $this->assertSame(31, LanePacker::rowHeight(0));
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter LanePackerTest
```

Expected: FAIL。`Class "App\Support\LanePacker" not found`

- [ ] **Step 3: `LanePacker` を書く**

`app/Support/LanePacker.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * 重なる工程を段に振り分ける（greedy interval partitioning。設計書 §5.3）。
 *
 * 開始が早い順に見て、入る段があればそこ、無ければ新しい段。
 *
 * ⚠ **段分けが無いと重なった工程が潰れて読めない**（モックの初版が実際にそうだった）。
 */
final class LanePacker
{
    /** 1 段の高さ（px）。モックの実測値と揃えてある。 */
    public const LANE_HEIGHT = 17;

    public const LANE_TOP    = 8;

    public const LANE_BOTTOM = 6;

    /**
     * 各要素が何段目に載るかを返す。
     *
     * ⚠ **返り値は入力の添字のまま**（Blade が元の行と突き合わせるため）。
     *   内部で開始順に並べ替えるが、キーは動かさない。
     *
     * @param  array<int, array{from: CarbonInterface, to: CarbonInterface}>  $spans
     * @return array<int, int>  添字 => 段番号（0 始まり）
     */
    public static function assign(array $spans): array
    {
        $order = array_keys($spans);

        // PHP 8 の sort は安定なので、開始が同じものは入力順のまま
        usort($order, fn (int $a, int $b) => $spans[$a]['from'] <=> $spans[$b]['from']);

        /** @var list<CarbonInterface> $laneEnds 段ごとの「最後に置いた要素の終了日」 */
        $laneEnds = [];
        $lanes    = [];

        foreach ($order as $i) {
            $placed = false;

            foreach ($laneEnds as $lane => $end) {
                // ⚠ 「より後」であって「以降」ではない。同日終了・同日開始は別の段にする
                //   （同じ段に置くと棒が 1 日ぶん重なって 1 本に見える）。
                if ($spans[$i]['from'] > $end) {
                    $laneEnds[$lane] = $spans[$i]['to'];
                    $lanes[$i]       = $lane;
                    $placed          = true;
                    break;
                }
            }

            if (! $placed) {
                $laneEnds[] = $spans[$i]['to'];
                $lanes[$i]  = array_key_last($laneEnds);
            }
        }

        ksort($lanes);

        return $lanes;
    }

    /** @param array<int, int> $lanes assign() の返り値 */
    public static function laneCount(array $lanes): int
    {
        return $lanes === [] ? 0 : max($lanes) + 1;
    }

    /**
     * 段数から行の高さ（px）を出す。
     *
     * ⚠ 0 段でも 1 段ぶんの高さを返す。工程が 1 件も描けない案件で行がぺしゃんこになり、
     *   ボードの罫線だけが並ぶ見た目になるのを防ぐ。
     */
    public static function rowHeight(int $laneCount): int
    {
        return self::LANE_TOP + max(1, $laneCount) * self::LANE_HEIGHT + self::LANE_BOTTOM;
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter LanePackerTest
```

Expected: `OK (9 tests, ...)`

- [ ] **Step 5: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1086 tests, ...)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Support/LanePacker.php tests/Unit/Support/LanePackerTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 重なる工程を段へ振り分ける LanePacker を足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 6: `ScheduleStep` モデル ＋ `HasScheduleSteps` trait ＋ 4 親を繋ぐ

**Files:**
- Create: `app/Models/ScheduleStep.php`
- Create: `app/Models/Concerns/HasScheduleSteps.php`（`app/Models/Concerns/` ディレクトリごと新規）
- Modify: `app/Models/ReProcurement.php` / `ReProject.php` / `HsProperty.php` / `HsCustomOrder.php`
- Test: `tests/Feature/Schedule/ScheduleAutoMilestoneTest.php`

⚠ **親ごとに違う部分は `abstract` にする**（決定 C）。既定実装を置くと、新しい親を足した人が
override を忘れた瞬間に**無音で空欄**になる（設計書 §3.2 の「静かに空欄になる」）。
親が実装するのは **4 本だけ**（`scheduleCode` / `scheduleName` / `scheduleRoutePrefix` / `autoMilestones`）。
`scheduleUrl()` / `scheduleDepartment()` / `scheduleStepRoute()` は `scheduleRoutePrefix()` から導く
＝ **親を足すときに触る場所が 1 ファイルで済む**。

⚠ **コード列・名称列の名前が親ごとに違う**（`procurement_code` / `project_code` / `property_code` / `order_code`、
`property_name` / `project_name` / `property_name` / `order_name`）。ボードで直に `$model->name` と書かない。

⚠ **「今日」を受け取るメソッドは全部 `$today` を必須にする**（`drawEnd` / `periodText` /
`delayDays` / `isLate`）。`App\Support\ScheduleStepStatus` と同じ方針で、既定値を置いて
内部で `now()` に落ちる経路を残すと、**テストが実行日に依存して「時刻を凍結したつもりで
効いていない」状態**を作れてしまう（Bug #54 ① と同型の測定不能）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleAutoMilestoneTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 自動マイルストーン（設計書 §3.4）。既存の日付列から ◆ を描く。工程行としては作らない。
 *
 * ⚠ **`scheduled_completion_date` と `actual_completion_date` は同じ「完成」という 1 つの節目。**
 *   ◆ を 2 つ描くと「完成が 2 回ある」ように見える。実績があれば実績、無ければ予定で 1 つだけ。
 *
 * ⚠ 親ごとにアクセサ名が違う（procurement_code / project_code / property_code / order_code）ので、
 *   trait 経由で読めることも 4 親ぶん対称に固定する（直に $model->name と書くと静かに空欄になる）。
 */
class ScheduleAutoMilestoneTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function procurement(array $attrs = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => 'PRC-001',
            'property_type'    => 'used_house',
            'transaction_type' => 'purchase',
            'status'           => 'contracted',
            'property_name'    => '井門町 更地',
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ], $attrs));
    }

    private function project(array $attrs = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code' => 'PRJ-001',
            'project_name' => '余戸南 分譲地',
            'status'       => 'selling',
            'address'      => '愛媛県松山市2-2-2',
            'created_by'   => 1,
        ], $attrs));
    }

    private function property(array $attrs = []): HsProperty
    {
        return HsProperty::create(array_merge([
            'property_code' => 'HS-001',
            'property_name' => '余戸南 3号地',
            'status'        => 'construction',
            'address'       => '愛媛県松山市3-3-3',
            'created_by'    => 1,
        ], $attrs));
    }

    private function customOrder(array $attrs = []): HsCustomOrder
    {
        return HsCustomOrder::create(array_merge([
            'order_code'    => 'CO-001',
            'order_name'    => '松山市 T様邸',
            'status'        => 'construction',
            'customer_name' => 'T様',
            'address'       => '愛媛県松山市4-4-4',
            'created_by'    => 1,
        ], $attrs));
    }

    /** @return list<string> ラベルだけ取り出す */
    private function labels(array $milestones): array
    {
        return array_column($milestones, 'label');
    }

    // ============================================================
    // 設計書 §3.4 の表どおりであること
    // ============================================================

    public function test_procurement_shows_contract_and_settlement(): void
    {
        $m = $this->procurement([
            'contract_date'   => '2026-01-23',
            'settlement_date' => '2026-05-29',
        ])->autoMilestones();

        $this->assertSame(['契約', '決済'], $this->labels($m));
        $this->assertSame('2026-01-23', $m[0]['date']->toDateString());
        $this->assertSame('2026-05-29', $m[1]['date']->toDateString());
    }

    public function test_project_shows_contract_and_settlement(): void
    {
        $m = $this->project([
            'contract_date'   => '2026-02-10',
            'settlement_date' => '2026-06-30',
        ])->autoMilestones();

        $this->assertSame(['契約', '決済'], $this->labels($m));
    }

    public function test_null_dates_produce_no_milestone(): void
    {
        $this->assertSame([], $this->procurement()->autoMilestones());
        $this->assertSame([], $this->project()->autoMilestones());
        $this->assertSame([], $this->property()->autoMilestones());
        $this->assertSame([], $this->customOrder()->autoMilestones());
    }

    // ============================================================
    // 完成の ◆ は 1 つだけ
    // ============================================================

    /**
     * ⚠ **これが「◆ が 2 つ出る」変異を止めるテスト。**
     */
    public function test_property_completion_is_a_single_milestone_even_when_both_dates_exist(): void
    {
        $m = $this->property([
            'scheduled_completion_date' => '2026-12-11',
            'actual_completion_date'    => '2026-11-28',
        ])->autoMilestones();

        $this->assertSame(['完成'], $this->labels($m), '完成の ◆ を 2 つ描かないこと（設計書 §3.4）');
        $this->assertSame('2026-11-28', $m[0]['date']->toDateString(), '実績があれば実績を採る');
    }

    public function test_property_falls_back_to_the_scheduled_completion(): void
    {
        $m = $this->property(['scheduled_completion_date' => '2026-12-11'])->autoMilestones();

        $this->assertSame(['完成'], $this->labels($m));
        $this->assertSame('2026-12-11', $m[0]['date']->toDateString());
    }

    public function test_custom_order_shows_contract_completion_and_delivery(): void
    {
        $m = $this->customOrder([
            'contract_date'             => '2026-04-18',
            'scheduled_completion_date' => '2026-11-20',
            'actual_completion_date'    => '2026-11-15',
            'delivery_date'             => '2026-11-30',
        ])->autoMilestones();

        $this->assertSame(['契約', '完成', '引渡し'], $this->labels($m));
        $this->assertSame('2026-11-15', $m[1]['date']->toDateString(), '完成は実績を優先し 1 つだけ');
    }

    // ============================================================
    // trait 経由で親の差を吸収できていること
    // ============================================================

    public function test_every_parent_exposes_its_code_name_department_and_url(): void
    {
        $cases = [
            [$this->procurement(), 'PRC-001', '井門町 更地', 'realestate.procurements', '/realestate/procurements/'],
            [$this->project(),     'PRJ-001', '余戸南 分譲地', 'realestate.projects',     '/realestate/projects/'],
            [$this->property(),    'HS-001',  '余戸南 3号地', 'housing.properties',      '/housing/properties/'],
            [$this->customOrder(), 'CO-001',  '松山市 T様邸', 'housing.custom-orders',   '/housing/custom-orders/'],
        ];

        foreach ($cases as [$model, $code, $name, $prefix, $urlFragment]) {
            $class = $model::class;

            $this->assertSame($code, $model->scheduleCode(), "{$class}: コード列が違う");
            $this->assertSame($name, $model->scheduleName(), "{$class}: 名称列が違う");
            $this->assertSame($prefix, $model->scheduleRoutePrefix(), "{$class}: ルート接頭辞が違う");
            $this->assertSame(explode('.', $prefix)[0], $model->scheduleDepartment(), "{$class}: 部署が違う");
            $this->assertStringContainsString($urlFragment, $model->scheduleUrl(), "{$class}: 詳細 URL が違う");
        }
    }

    public function test_schedule_steps_come_back_in_sort_order(): void
    {
        $p = $this->procurement();

        foreach ([['C', 3], ['A', 1], ['B', 2]] as [$name, $order]) {
            $p->scheduleSteps()->create([
                'name' => $name, 'category' => 'work', 'sort_order' => $order,
            ]);
        }

        $this->assertSame(['A', 'B', 'C'], $p->scheduleSteps()->pluck('name')->all());
    }

    /**
     * ⚠ **id は 4 親のあいだで衝突する**（別テーブルなので）。
     *   型も一緒に見ていないと他人の工程を拾う。
     */
    public function test_steps_are_scoped_by_type_as_well_as_id(): void
    {
        $proc = $this->procurement();
        $prop = $this->property();

        $this->assertSame($proc->getKey(), $prop->getKey(), '前提: 同じ id の別テーブル行を作れている');

        $proc->scheduleSteps()->create(['name' => '仕入れ側の工程', 'category' => 'work']);

        $this->assertCount(1, $proc->scheduleSteps()->get());
        $this->assertCount(0, $prop->scheduleSteps()->get(), '型で絞れていないと他人の工程が見える');
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleAutoMilestoneTest
```

Expected: FAIL。`Call to undefined method App\Models\ReProcurement::autoMilestones()`

- [ ] **Step 3: `ScheduleStep` モデルを書く**

`app/Models/ScheduleStep.php`:

```php
<?php

namespace App\Models;

use App\Enums\ScheduleStepCategory;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 工程表の 1 行（設計書 §3）。4 親（仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅）に
 * ポリモーフィックにぶら下がる。
 *
 * ⚠ **行の種別（棒かマイルストーンか）に専用フラグを持たない**（設計書 §3.7）。
 *   日付の入り方だけで決まる。フラグを持つと「日付とフラグが食い違う」状態を作れてしまう。
 */
class ScheduleStep extends Model
{
    protected $table = 'schedule_steps';

    protected $fillable = [
        'name',
        'category',
        'planned_start',
        'planned_end',
        'actual_start',
        'actual_end',
        'sort_order',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'category'      => ScheduleStepCategory::class,
            'planned_start' => 'date',
            'planned_end'   => 'date',
            'actual_start'  => 'date',
            'actual_end'    => 'date',
            'sort_order'    => 'integer',
        ];
    }

    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    // ============================================================
    // 行の種別（設計書 §3.7）
    // ============================================================

    /**
     * 描画できるか。
     *
     * ⚠ **描画しない行を一覧から消さないこと。** 日付が入っていない行を黙って消すと、
     *   利用者は「保存できていない」と誤解する。一覧には残して期間欄に「日付未設定」と出す。
     */
    public function isDrawable(): bool
    {
        return $this->planned_start !== null || $this->actual_start !== null;
    }

    /** ◆ 1 個で描く行（棒にする期間が無い） */
    public function isMilestone(): bool
    {
        return $this->isDrawable()
            && $this->planned_end === null
            && $this->actual_start === null
            && $this->actual_end === null;
    }

    // ============================================================
    // 描画区間（設計書 §5.2）— 画面の棒は 1 本だけ
    // ============================================================

    /**
     * ⚠ **実績を優先する。** 「予定 5/18〜9/30・実績 6/1〜10/16」の工程は 6/1〜10/16 の 1 本。
     *   詳細画面では遅れは棒から読めない（案1 を選んだということ）。遅れはボードで見る。
     */
    public function drawStart(): ?CarbonInterface
    {
        return $this->actual_start ?? $this->planned_start;
    }

    /**
     * ⚠ 実績開始があって実績終了が無いときは「進行中」なので右端を今日まで伸ばす。
     *
     * ⚠ **`$today` は必須。既定値を持たせない**（`ScheduleStepStatus` と同じ方針）。
     *   内部で `now()` に落ちる経路を残すと、テストが実行日に依存して
     *   「時刻を凍結したつもりで効いていない」状態を作れてしまう。
     *   呼び出し側（`ScheduleCardService` / `ScheduleBoardService`）は必ず渡している。
     */
    public function drawEnd(CarbonInterface $today): ?CarbonInterface
    {
        if ($this->actual_start !== null) {
            return $this->actual_end ?? $today;
        }

        if ($this->planned_start === null) {
            return null;
        }

        return $this->planned_end ?? $this->planned_start;
    }

    // ============================================================
    // 遅延・進捗（判定は App\Support\ScheduleStepStatus に集約。Bug #46）
    // ============================================================

    public function delayDays(CarbonInterface $today): int
    {
        return ScheduleStepStatus::delayDays($this->planned_end, $this->actual_end, $today);
    }

    public function isLate(CarbonInterface $today): bool
    {
        return ScheduleStepStatus::isLate($this->planned_end, $this->actual_end, $today);
    }

    public function progress(): string
    {
        return ScheduleStepStatus::progress($this->actual_start, $this->actual_end);
    }

    /**
     * 左カラムに出す期間テキスト（`3/16〜7/03`）。描けない行は「日付未設定」。
     *
     * ⚠ `drawEnd()` と同じ理由で `$today` は必須。
     */
    public function periodText(CarbonInterface $today): string
    {
        if (! $this->isDrawable()) {
            return '日付未設定';
        }

        if ($this->isMilestone()) {
            return $this->drawStart()->format('n/d');
        }

        return $this->drawStart()->format('n/d') . '〜' . $this->drawEnd($today)->format('n/d');
    }
}
```

- [ ] **Step 4: `HasScheduleSteps` trait を書く**

`app/Models/Concerns/HasScheduleSteps.php`（ディレクトリごと新規）:

```php
<?php

namespace App\Models\Concerns;

use App\Models\ScheduleStep;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 工程表を持てる親（設計書 §3.3）。仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 が use する。
 *
 * ⚠ **親ごとの差は abstract メソッドで吸収する。既定実装を置かない。**
 *   コード列・名称列の名前は親ごとに違う（procurement_code / project_code /
 *   property_code / order_code）。既定値を置くと、新しい親を足した人が override を
 *   忘れた瞬間に**無音で空欄**になる。abstract なら PHP が Fatal で止める。
 *
 * ⚠ **ボードと共通 partial は親の実クラスを知らないまま動く。** 直に $model->name と
 *   書かないこと。
 */
trait HasScheduleSteps
{
    /**
     * ⚠ 並び順は sort_order → id。id を第 2 キーに入れないと、
     *   sort_order が同値のとき DB 依存の順序になり画面がちらつく。
     */
    public function scheduleSteps(): MorphMany
    {
        return $this->morphMany(ScheduleStep::class, 'schedulable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** 画面に出す案件コード */
    abstract public function scheduleCode(): string;

    /** 画面に出す案件名 */
    abstract public function scheduleName(): string;

    /**
     * ルート名の接頭辞（例: `realestate.procurements`）。
     *
     * ⚠ **親を足すときに触るのはここだけ**にするための 1 本。詳細 URL も部署も工程ルートも
     *   すべてこの接頭辞から導く。サービス側に「クラス => 接頭辞」の対応表を持つと、
     *   親を足した人が表を更新し忘れて `Route [.schedule-steps.store] not defined` になる。
     */
    abstract public function scheduleRoutePrefix(): string;

    /** 詳細ページの URL */
    public function scheduleUrl(): string
    {
        return route($this->scheduleRoutePrefix() . '.show', $this);
    }

    /**
     * 'realestate' | 'housing'
     *
     * ⚠ 接頭辞の先頭がそのまま部署コードになっている（`realestate.procurements` → `realestate`）。
     *   ボードの絞り込みと `department.access` の引数がこれと一致している必要がある。
     */
    public function scheduleDepartment(): string
    {
        return explode('.', $this->scheduleRoutePrefix())[0];
    }

    /** 工程 CRUD のルート名（`store` / `reorder` / `update` / `destroy`） */
    public function scheduleStepRoute(string $action): string
    {
        return $this->scheduleRoutePrefix() . '.schedule-steps.' . $action;
    }

    /**
     * 既存の日付列から描く ◆（設計書 §3.4）。工程行としては作らない。
     *
     * ⚠ **読み取り専用。** 工程表の入力欄からは触れない。動かしたければ親の編集画面で直す。
     * ⚠ **「完成」は 1 つだけ。** scheduled と actual は同じ節目なので ◆ を 2 つ描かない。
     *
     * @return list<array{label: string, date: \Carbon\CarbonInterface}>
     */
    abstract public function autoMilestones(): array;
}
```

- [ ] **Step 5: 4 親に trait を足す**

各モデルで ① `use App\Models\Concerns\HasScheduleSteps;` を import 節に足し
② クラス直下の `use HasFactory;` の行に続けて `use HasScheduleSteps;` を足し
③ 下記メソッドを**リレーション定義の後ろ**に足す。

`app/Models/ReProcurement.php`:

```php
    // ============================================================
    // 工程表（設計書 §3.3）
    // ============================================================

    public function scheduleCode(): string
    {
        return $this->procurement_code;
    }

    public function scheduleName(): string
    {
        return $this->property_name;
    }

    public function scheduleRoutePrefix(): string
    {
        return 'realestate.procurements';
    }

    public function autoMilestones(): array
    {
        return array_values(array_filter([
            $this->contract_date   ? ['label' => '契約', 'date' => $this->contract_date] : null,
            $this->settlement_date ? ['label' => '決済', 'date' => $this->settlement_date] : null,
        ]));
    }
```

`app/Models/ReProject.php`:

```php
    // ============================================================
    // 工程表（設計書 §3.3）
    // ============================================================

    public function scheduleCode(): string
    {
        return $this->project_code;
    }

    public function scheduleName(): string
    {
        return $this->project_name;
    }

    public function scheduleRoutePrefix(): string
    {
        return 'realestate.projects';
    }

    public function autoMilestones(): array
    {
        return array_values(array_filter([
            $this->contract_date   ? ['label' => '契約', 'date' => $this->contract_date] : null,
            $this->settlement_date ? ['label' => '決済', 'date' => $this->settlement_date] : null,
        ]));
    }
```

`app/Models/HsProperty.php`:

```php
    // ============================================================
    // 工程表（設計書 §3.3）
    // ============================================================

    public function scheduleCode(): string
    {
        return $this->property_code;
    }

    public function scheduleName(): string
    {
        return $this->property_name;
    }

    public function scheduleRoutePrefix(): string
    {
        return 'housing.properties';
    }

    /**
     * ⚠ **「完成」は 1 つだけ。** scheduled_completion_date と actual_completion_date は
     *   同じ節目なので、実績があれば実績・無ければ予定の位置に ◆ を 1 つだけ描く。
     *   2 つ描くと「完成が 2 回ある」ように見える（設計書 §3.4）。
     */
    public function autoMilestones(): array
    {
        $completion = $this->actual_completion_date ?? $this->scheduled_completion_date;

        return $completion ? [['label' => '完成', 'date' => $completion]] : [];
    }
```

`app/Models/HsCustomOrder.php`:

```php
    // ============================================================
    // 工程表（設計書 §3.3）
    // ============================================================

    public function scheduleCode(): string
    {
        return $this->order_code;
    }

    public function scheduleName(): string
    {
        return $this->order_name;
    }

    public function scheduleRoutePrefix(): string
    {
        return 'housing.custom-orders';
    }

    /** ⚠ HsProperty と同じく「完成」は 1 つだけ（設計書 §3.4） */
    public function autoMilestones(): array
    {
        $completion = $this->actual_completion_date ?? $this->scheduled_completion_date;

        return array_values(array_filter([
            $this->contract_date ? ['label' => '契約', 'date' => $this->contract_date] : null,
            $completion          ? ['label' => '完成', 'date' => $completion] : null,
            $this->delivery_date ? ['label' => '引渡し', 'date' => $this->delivery_date] : null,
        ]));
    }
```

- [ ] **Step 6: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleAutoMilestoneTest
```

Expected: `OK (9 tests, ...)`

- [ ] **Step 7: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1095 tests, ...)`

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Models/ScheduleStep.php app/Models/Concerns/HasScheduleSteps.php app/Models/ReProcurement.php app/Models/ReProject.php app/Models/HsProperty.php app/Models/HsCustomOrder.php tests/Feature/Schedule/ScheduleAutoMilestoneTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 工程モデルと 4 親をつなぐ trait を足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 7: 工程 CRUD のルート 16 本 ＋ `ScheduleStepController` ＋ 和名

**Files:**
- Create: `app/Http/Controllers/ScheduleStepController.php`
- Modify: `routes/web.php`（2 箇所に新ブロックを挿入）
- Modify: `lang/ja/validation.php`（`attributes` に 4 件）
- Test: `tests/Feature/Schedule/ScheduleRouteWiringTest.php`
- Test: `tests/Feature/Schedule/ScheduleStepAuthorizationTest.php`
- Test: `tests/Feature/Schedule/ScheduleStepCrudTest.php`

⚠ **ボードのルートもここで先に置く**（Task 9 でコントローラを実装する）と、
Task 9 まで `route()` が未定義でボード用のリンクが 500 になる。**ボードのルートは Task 9 で足す。**
このタスクで足すのは**工程 CRUD の 16 本だけ**。

⚠ **`reorder` を `{step}` より先に登録する**（設計書 §6.3）。`schedule-steps/reorder` は
`schedule-steps/{step}` にマッチしうる。⚠ **`route:list` の並びは登録順ではなく URI 辞書順**なので、
優先順位を `route:list` で確かめてはいけない。**ルータに実マッチさせて測る。**

- [ ] **Step 1: 和名を足す**

`lang/ja/validation.php` の `'attributes' => [` の中、`'notes' => '備考',` の**次の行**に挿入:

```php
        // --- 工程表（設計書 §4.5）---
        // ⚠ name は画面ごとに意味が変わる語なので、ここでは上書きしない。
        //   工程名は ScheduleStepController の validate() 第 3 引数で指定する。
        'planned_start' => '予定開始',
        'planned_end' => '予定終了',
        'actual_start' => '実績開始',
        'actual_end' => '実績終了',
```

⚠ `category` / `notes` / `ids` は既に登録済み（実測）。重複させないこと。

- [ ] **Step 2: 失敗するテストを書く（配線）**

`tests/Feature/Schedule/ScheduleRouteWiringTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Http\Controllers\ScheduleStepController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 工程 CRUD のルート配線（設計書 §6.1 / §6.3）。
 *
 * ⚠ **4 親 × 4 本を対称に定義する。** 片側が足りないと、共通 partial が route() を
 *   呼んだ瞬間に**その画面だけ本番で 500** になる（Bug #25。realestate に surveys ルートが
 *   無くて起きた前例と同型で、空データのローカルでは再現しない）。
 *
 * ⚠ **OWNER_PARAMS の網羅は「全件分類」で見る。** 「直したものを並べる」形にすると、
 *   新しく足したルートが検査対象に入らず永遠に緑になる（Bug #45）。
 */
class ScheduleRouteWiringTest extends TestCase
{
    use RefreshDatabase;

    /** ルート名の接頭辞 => 親のルートパラメータ名 => URI の接頭辞 */
    private const OWNERS = [
        'realestate.procurements' => ['procurement', 'realestate/procurements', 'realestate'],
        'realestate.projects'     => ['project',     'realestate/projects',     'realestate'],
        'housing.properties'      => ['property',    'housing/properties',      'housing'],
        'housing.custom-orders'   => ['customOrder', 'housing/custom-orders',   'housing'],
    ];

    private const ACTIONS = ['store', 'reorder', 'update', 'destroy'];

    public function test_all_sixteen_routes_are_defined_symmetrically(): void
    {
        $missing = [];

        foreach (self::OWNERS as $prefix => $_) {
            foreach (self::ACTIONS as $action) {
                $name = "{$prefix}.schedule-steps.{$action}";
                if (! Route::has($name)) {
                    $missing[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "工程ルートが対称に定義されていません（欠けた側の詳細画面だけが本番で 500 します）:\n"
            . implode("\n", $missing)
        );
    }

    /**
     * 全件分類（Bug #45）: `*.schedule-steps.*` という名前のルートすべてについて、
     * 親のパラメータ名が OWNER_PARAMS に入っていること。
     *
     * ⚠ これが無いと、ルートのパラメータ名を打ち間違えたときに**無音で 404** になる。
     */
    public function test_every_schedule_step_route_uses_a_known_owner_param(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), '.schedule-steps.'));

        $this->assertCount(16, $routes, '工程ルートの本数が 16 でない（走査の空振り防止）');

        $offenders = [];

        foreach ($routes as $route) {
            $owners = array_values(array_diff($route->parameterNames(), ['step']));

            if (count($owners) !== 1 || ! in_array($owners[0], ScheduleStepController::OWNER_PARAMS, true)) {
                $offenders[] = $route->getName() . ' => {' . implode(',', $owners) . '}';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ScheduleStepController::OWNER_PARAMS に無い親パラメータがあります（親を解決できず落ちます）:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * ⚠ **`reorder` が `{step}` より先にマッチすること。**
     *   ⚠ `route:list` の並びは登録順ではなく URI 辞書順なので、それを見て確かめてはいけない。
     *     ルータに実マッチさせて測る。
     */
    public function test_reorder_wins_over_the_step_parameter(): void
    {
        foreach (self::OWNERS as $prefix => [$_param, $uriPrefix, $_dept]) {
            $matched = Route::getRoutes()->match(
                Request::create("/{$uriPrefix}/1/schedule-steps/reorder", 'PATCH')
            );

            $this->assertSame(
                'reorder',
                $matched->getActionMethod(),
                "{$prefix}: /schedule-steps/reorder が {step} に食われている（登録順を直すこと）"
            );
        }
    }

    /**
     * ⚠ **モデルの `scheduleRoutePrefix()` から導いた工程ルート名が実在すること。**
     *   接頭辞を打ち間違えると `Route [...] not defined` で**その画面だけ本番 500** になる（Bug #25）。
     *   ⚠ この 1 本があるので、サービス側に「クラス => 接頭辞」の対応表を持たなくてよい。
     */
    public function test_the_route_prefix_of_every_parent_resolves_to_real_step_routes(): void
    {
        $models = [new \App\Models\ReProcurement, new \App\Models\ReProject, new \App\Models\HsProperty, new \App\Models\HsCustomOrder];

        foreach ($models as $model) {
            foreach (self::ACTIONS as $action) {
                $name = $model->scheduleStepRoute($action);

                $this->assertTrue(Route::has($name), $model::class . ": ルート {$name} が定義されていない");
            }
        }
    }

    /** 権限（設計書 §6）: 全 16 本が role:executive,manager と部署ガードの中にあること */
    public function test_every_route_is_gated_by_role_and_department(): void
    {
        foreach (self::OWNERS as $prefix => [$_param, $_uri, $department]) {
            foreach (self::ACTIONS as $action) {
                $middleware = Route::getRoutes()->getByName("{$prefix}.schedule-steps.{$action}")->gatherMiddleware();

                $this->assertContains('role:executive,manager', $middleware, "{$prefix}.{$action}: ロールガードが無い");
                $this->assertContains("department.access:{$department}", $middleware, "{$prefix}.{$action}: 部署ガードが無い");
            }
        }
    }
}
```

- [ ] **Step 3: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleRouteWiringTest
```

Expected: FAIL。`Class "App\Http\Controllers\ScheduleStepController" not found`

- [ ] **Step 4: コントローラを書く**

`app/Http/Controllers/ScheduleStepController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleStepCategory;
use App\Models\ScheduleStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 工程の CRUD（設計書 §4.4 / §6）。4 親で 1 本のコントローラを共有する。
 *
 * ⚠ **`{type}` のようなルートパラメータを持たない。** AttachmentController の TYPE_MAP 方式は、
 *   ルートの where() 正規表現とマップの同期漏れで 404 になる事故がある（Bug #20）。
 *   代わりに「バインド済みのルートパラメータのうちどれが来たか」を見る。
 *
 * ⚠ **OWNER_PARAMS とルートのパラメータ名がずれると無音で落ちる。**
 *   ScheduleRouteWiringTest が全件分類で固定している（Bug #45）。
 */
class ScheduleStepController extends Controller
{
    /**
     * 親を指すルートパラメータ名。⚠ ルート定義と必ず揃えること。
     * `routes/web.php` の `{procurement}` / `{project}` / `{property}` / `{customOrder}` に対応。
     */
    public const OWNER_PARAMS = ['procurement', 'project', 'property', 'customOrder'];

    /**
     * 工程を追加する。
     * Route: POST /<部署>/<親>/{parent}/schedule-steps
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $data  = $request->validate($this->rules(), [], $this->attributes());

        $step = new ScheduleStep($data);
        $step->schedulable()->associate($owner);
        // 末尾に足す。max が null（0 件目）でも 1 になる
        $step->sort_order = ((int) $owner->scheduleSteps()->max('sort_order')) + 1;
        $step->created_by = $request->user()->id;
        $step->updated_by = $request->user()->id;
        $step->save();

        return response()->json(['success' => true, 'step' => $this->payload($step)]);
    }

    /**
     * 工程を更新する。
     * Route: PATCH /<部署>/<親>/{parent}/schedule-steps/{step}
     *
     * ⚠ `$step` は名前（`{step}`）でルートモデルバインドされる。親はパラメータ名が
     *   4 通りあるので型宣言では受け取れず、owner() で解決する。
     */
    public function update(Request $request, ScheduleStep $step): JsonResponse
    {
        $owner = $this->owner($request);
        $this->assertOwned($step, $owner);

        $data = $request->validate($this->rules(), [], $this->attributes());

        $step->fill($data);
        $step->updated_by = $request->user()->id;
        $step->save();

        return response()->json(['success' => true, 'step' => $this->payload($step->fresh())]);
    }

    /**
     * 工程を削除する。
     * Route: DELETE /<部署>/<親>/{parent}/schedule-steps/{step}
     */
    public function destroy(Request $request, ScheduleStep $step): JsonResponse
    {
        $owner = $this->owner($request);
        $this->assertOwned($step, $owner);

        $step->delete();

        return response()->json(['success' => true, 'id' => $step->id]);
    }

    /**
     * 並べ替え（↑↓ ボタン。設計書 §4.4 — ドラッグにはしない）。
     * Route: PATCH /<部署>/<親>/{parent}/schedule-steps/reorder
     *
     * ⚠ **その親の工程を過不足なく全部渡すこと**を要求する。部分的な並びを許すと、
     *   渡されなかった行の sort_order が取り残されて順序が壊れる。
     */
    public function reorder(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ], [], ['ids' => '並び順']);

        $ids   = array_map('intval', $data['ids']);
        $owned = $owner->scheduleSteps()->pluck('id')->map('intval')->all();

        // ⚠ 他人の工程 id を混ぜられないこと（§6.2 と同じ理由）＋ 取りこぼしが無いこと
        abort_unless(
            count($ids) === count($owned) && array_diff($ids, $owned) === [] && array_diff($owned, $ids) === [],
            404
        );

        foreach ($ids as $i => $id) {
            ScheduleStep::whereKey($id)->update([
                'sort_order' => $i + 1,
                'updated_by' => $request->user()->id,
            ]);
        }

        return response()->json(['success' => true, 'ids' => $ids]);
    }

    // ============================================================
    // 内部
    // ============================================================

    /**
     * バインド済みのルートパラメータから親を取り出す（設計書 §6.1）。
     *
     * ⚠ 見つからないときは**黙って 404 にしない**。無音の 404 はまさに避けたい失敗なので、
     *   配線ミスとして大きく落とす（ScheduleRouteWiringTest が本来ここへ到達させない）。
     */
    private function owner(Request $request): Model
    {
        foreach (self::OWNER_PARAMS as $param) {
            $model = $request->route($param);

            if ($model instanceof Model) {
                return $model;
            }
        }

        throw new \LogicException(
            '工程の親をルートパラメータから解決できません。ルートのパラメータ名を '
            . 'ScheduleStepController::OWNER_PARAMS と揃えてください。'
        );
    }

    /**
     * その工程が本当にこの親のものか（設計書 §6.2）。
     *
     * ⚠ **`schedulable_id` だけでは足りない。** 4 親は別テーブルなので **id が衝突する**
     *   （仕入れ案件 #12 と建売物件 #12 が両方存在しうる）。**型も必ず突き合わせる。**
     *
     * ⚠ **int にキャストしてから比較する。** 片方が文字列だと `===` が常に false になり、
     *   正しいリクエストまで 404 になる（そして「なぜか動かない」に見える）。
     */
    private function assertOwned(ScheduleStep $step, Model $owner): void
    {
        abort_unless(
            (int) $step->schedulable_id === (int) $owner->getKey()
                && $step->schedulable_type === $owner::class,
            404
        );
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name'          => 'required|string|max:100',
            'category'      => ['required', Rule::in(ScheduleStepCategory::values())],
            'planned_start' => 'nullable|date',
            'planned_end'   => 'nullable|date|after_or_equal:planned_start',
            // ⚠ 実績終了だけが入って実績開始が空、という状態を許さない（設計書 §4.5）。
            //   許すと描画が「実績開始が無い」側へ落ち、**実績終了を入れたのに予定の棒が出る**
            //   という無音の食い違いになる。逆（実績開始だけ）は「進行中」なので正当。
            'actual_start'  => 'nullable|date|required_with:actual_end',
            'actual_end'    => 'nullable|date|after_or_equal:actual_start',
            'notes'         => 'nullable|string|max:255',
        ];
    }

    /**
     * ⚠ **`validate()` の第 3 引数**（第 2 引数は messages）。
     *   `name` は画面ごとに意味が変わる語なので、グローバルの `attributes` を書き換えず
     *   ここで上書きする（Bug #37）。
     *
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['name' => '工程名'];
    }

    /** @return array<string, mixed> */
    private function payload(ScheduleStep $step): array
    {
        return [
            'id'            => $step->id,
            'name'          => $step->name,
            'category'      => $step->category->value,
            'planned_start' => $step->planned_start?->toDateString(),
            'planned_end'   => $step->planned_end?->toDateString(),
            'actual_start'  => $step->actual_start?->toDateString(),
            'actual_end'    => $step->actual_end?->toDateString(),
            'sort_order'    => $step->sort_order,
            'notes'         => $step->notes ?? '',
        ];
    }
}
```

- [ ] **Step 5: ルートを足す（不動産）**

`routes/web.php` の

```
    /*
    |----------------------------------------------------------------------
    | 不動産 仕入れ先 Ajax 検索 + 簡易登録（2ルート）
```

というコメントブロックの**直前**に挿入:

```php
    /*
    |----------------------------------------------------------------------
    | 不動産 工程表 — 工程 CRUD（8ルート）
    |----------------------------------------------------------------------
    | 設計書: docs/superpowers/specs/2026-08-31-realestate-schedule-gantt-design.md §6
    |
    | ⚠ reorder を {step} より先に登録すること。schedule-steps/reorder は
    |   schedule-steps/{step} にマッチしうる。route:list の並びは URI 辞書順なので
    |   優先順位の確認には使えない（ScheduleRouteWiringTest がルータに実マッチさせて測る）。
    |
    | ⚠ 4 親 × 4 本を対称に定義すること。片側が足りないと共通 partial の route() で
    |   その画面だけ本番 500 になる（Bug #25）。
    */
    Route::prefix('realestate')
        ->middleware(['department.access:realestate', 'role:executive,manager'])
        ->group(function () {
            // --- 仕入れ案件 ---
            Route::post('/procurements/{procurement}/schedule-steps', [\App\Http\Controllers\ScheduleStepController::class, 'store'])
                ->name('realestate.procurements.schedule-steps.store');
            Route::patch('/procurements/{procurement}/schedule-steps/reorder', [\App\Http\Controllers\ScheduleStepController::class, 'reorder'])
                ->name('realestate.procurements.schedule-steps.reorder');
            Route::patch('/procurements/{procurement}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'update'])
                ->name('realestate.procurements.schedule-steps.update');
            Route::delete('/procurements/{procurement}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'destroy'])
                ->name('realestate.procurements.schedule-steps.destroy');

            // --- 分譲地PJ ---
            Route::post('/projects/{project}/schedule-steps', [\App\Http\Controllers\ScheduleStepController::class, 'store'])
                ->name('realestate.projects.schedule-steps.store');
            Route::patch('/projects/{project}/schedule-steps/reorder', [\App\Http\Controllers\ScheduleStepController::class, 'reorder'])
                ->name('realestate.projects.schedule-steps.reorder');
            Route::patch('/projects/{project}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'update'])
                ->name('realestate.projects.schedule-steps.update');
            Route::delete('/projects/{project}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'destroy'])
                ->name('realestate.projects.schedule-steps.destroy');
        });

```

- [ ] **Step 6: ルートを足す（住宅事業）**

`routes/web.php` の

```
    /*
    |----------------------------------------------------------------------
    | 顧客管理 買主マスタ — 住宅事業（7ルート）
```

というコメントブロックの**直前**に挿入:

```php
    /*
    |----------------------------------------------------------------------
    | 住宅事業 工程表 — 工程 CRUD（8ルート）
    |----------------------------------------------------------------------
    | ⚠ 不動産側（上）と対称に保つこと。注意点は同じ。
    */
    Route::prefix('housing')
        ->middleware(['department.access:housing', 'role:executive,manager'])
        ->group(function () {
            // --- 建売物件 ---
            Route::post('/properties/{property}/schedule-steps', [\App\Http\Controllers\ScheduleStepController::class, 'store'])
                ->name('housing.properties.schedule-steps.store');
            Route::patch('/properties/{property}/schedule-steps/reorder', [\App\Http\Controllers\ScheduleStepController::class, 'reorder'])
                ->name('housing.properties.schedule-steps.reorder');
            Route::patch('/properties/{property}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'update'])
                ->name('housing.properties.schedule-steps.update');
            Route::delete('/properties/{property}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'destroy'])
                ->name('housing.properties.schedule-steps.destroy');

            // --- 注文住宅 ---
            Route::post('/custom-orders/{customOrder}/schedule-steps', [\App\Http\Controllers\ScheduleStepController::class, 'store'])
                ->name('housing.custom-orders.schedule-steps.store');
            Route::patch('/custom-orders/{customOrder}/schedule-steps/reorder', [\App\Http\Controllers\ScheduleStepController::class, 'reorder'])
                ->name('housing.custom-orders.schedule-steps.reorder');
            Route::patch('/custom-orders/{customOrder}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'update'])
                ->name('housing.custom-orders.schedule-steps.update');
            Route::delete('/custom-orders/{customOrder}/schedule-steps/{step}', [\App\Http\Controllers\ScheduleStepController::class, 'destroy'])
                ->name('housing.custom-orders.schedule-steps.destroy');
        });

```

- [ ] **Step 7: 配線テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleRouteWiringTest
```

Expected: `OK (5 tests, ...)`

---

- [ ] **Step 8: 共通のテスト土台を書く**

`tests/Feature/Schedule/ScheduleTestCase.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 工程表の Feature テスト共通土台。
 *
 * ⚠ 4 親を対称に作れるようにしておく。「代表 1 種だけ」でテストを書くと、
 *   残り 3 種の経路が一度も実行されないまま緑になる（Bug #44 で 3 回続けて踏んでいる）。
 */
abstract class ScheduleTestCase extends TestCase
{
    use ParsesForms;

    private bool $departmentsSeeded = false;

    /** 4 親のラベル => [モデルのクラス, ルート名の接頭辞, 部署] */
    protected const PARENTS = [
        'procurement' => [ReProcurement::class, 'realestate.procurements', 'realestate'],
        'project'     => [ReProject::class,     'realestate.projects',     'realestate'],
        'property'    => [HsProperty::class,    'housing.properties',      'housing'],
        'customOrder' => [HsCustomOrder::class, 'housing.custom-orders',   'housing'],
    ];

    /**
     * ⚠ DepartmentSeeder は Department::create() なので冪等ではない。1 度だけ流す。
     *
     * @param  list<string>  $departments  所属させる部署コード
     */
    protected function actor(UserRole $role, array $departments = ['realestate', 'housing']): User
    {
        if (! $this->departmentsSeeded) {
            $this->seed(DepartmentSeeder::class);
            $this->departmentsSeeded = true;
        }

        $user = User::factory()->create([
            'role'                 => $role->value,
            'must_change_password' => false,
        ]);

        foreach ($departments as $code) {
            $user->departments()->attach(Department::where('code', $code)->value('id'));
        }

        return $user;
    }

    protected function manager(array $departments = ['realestate', 'housing']): User
    {
        return $this->actor(UserRole::Manager, $departments);
    }

    protected function staff(array $departments = ['realestate', 'housing']): User
    {
        return $this->actor(UserRole::Staff, $departments);
    }

    /** 親をラベルで作る。列名が親ごとに違うのでここで吸収する。 */
    protected function makeParent(string $label, array $attrs = []): \Illuminate\Database\Eloquent\Model
    {
        $base = match ($label) {
            'procurement' => [
                'procurement_code' => 'PRC-001',
                'property_type'    => 'used_house',
                'transaction_type' => 'purchase',
                'status'           => 'contracted',
                'property_name'    => '井門町 更地',
                'address'          => '愛媛県松山市1-1-1',
                'created_by'       => 1,
            ],
            'project' => [
                'project_code' => 'PRJ-001',
                'project_name' => '余戸南 分譲地',
                'status'       => 'selling',
                'address'      => '愛媛県松山市2-2-2',
                'created_by'   => 1,
            ],
            'property' => [
                'property_code' => 'HS-001',
                'property_name' => '余戸南 3号地',
                'status'        => 'construction',
                'address'       => '愛媛県松山市3-3-3',
                'created_by'    => 1,
            ],
            'customOrder' => [
                'order_code'    => 'CO-001',
                'order_name'    => '松山市 T様邸',
                'status'        => 'construction',
                'customer_name' => 'T様',
                'address'       => '愛媛県松山市4-4-4',
                'created_by'    => 1,
            ],
        };

        [$class] = self::PARENTS[$label];

        return $class::create(array_merge($base, $attrs));
    }

    /** 工程の妥当な入力一式 */
    protected function stepInput(array $overrides = []): array
    {
        return array_merge([
            'name'          => '建築確認申請',
            'category'      => 'permit',
            'planned_start' => '2026-05-11',
            'planned_end'   => '2026-06-05',
            'actual_start'  => null,
            'actual_end'    => null,
            'notes'         => '',
        ], $overrides);
    }
}
```

- [ ] **Step 9: CRUD の失敗するテストを書く**

`tests/Feature/Schedule/ScheduleStepCrudTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 工程 CRUD の往復（設計書 §8.2）。**4 親すべて**で対称に測る。
 *
 * ⚠ 「代表 1 種だけ」で書くと残り 3 種の経路が一度も実行されない（Bug #44）。
 *   ここでは data provider を使わず**テスト本体でループ**する
 *   （プロバイダは Laravel 起動前に評価されるので route() が使えない。Bug #53 で実測）。
 */
class ScheduleStepCrudTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_a_manager_can_add_a_step_to_every_parent(): void
    {
        foreach (self::PARENTS as $label => [$class, $prefix, $_dept]) {
            $owner = $this->makeParent($label);

            $response = $this->actingAs($this->manager())
                ->postJson(route("{$prefix}.schedule-steps.store", $owner), $this->stepInput());

            $response->assertOk()->assertJsonPath('success', true);

            $step = ScheduleStep::where('schedulable_type', $class)
                ->where('schedulable_id', $owner->getKey())
                ->sole();

            $this->assertSame('建築確認申請', $step->name, "{$label}: 工程名が保存されていない");
            $this->assertSame('permit', $step->category->value, "{$label}: 分類が保存されていない");
            $this->assertSame('2026-05-11', $step->planned_start->toDateString(), "{$label}: 予定開始が保存されていない");
            $this->assertSame(1, $step->sort_order, "{$label}: 末尾に足されていない");
        }
    }

    public function test_new_steps_are_appended_to_the_end(): void
    {
        $owner = $this->makeParent('procurement');
        $manager = $this->manager();

        foreach (['A', 'B', 'C'] as $name) {
            $this->actingAs($manager)->postJson(
                route('realestate.procurements.schedule-steps.store', $owner),
                $this->stepInput(['name' => $name])
            )->assertOk();
        }

        $this->assertSame([1, 2, 3], $owner->scheduleSteps()->pluck('sort_order')->all());
        $this->assertSame(['A', 'B', 'C'], $owner->scheduleSteps()->pluck('name')->all());
    }

    public function test_a_manager_can_update_and_delete_a_step_on_every_parent(): void
    {
        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $owner   = $this->makeParent($label);
            $manager = $this->manager();

            $created = $this->actingAs($manager)
                ->postJson(route("{$prefix}.schedule-steps.store", $owner), $this->stepInput())
                ->json('step.id');

            $this->actingAs($manager)->patchJson(
                route("{$prefix}.schedule-steps.update", [$owner, $created]),
                $this->stepInput(['name' => '地盤改良', 'category' => 'work', 'actual_start' => '2026-06-15'])
            )->assertOk();

            $step = ScheduleStep::findOrFail($created);
            $this->assertSame('地盤改良', $step->name, "{$label}: 更新が効いていない");
            $this->assertSame('2026-06-15', $step->actual_start->toDateString(), "{$label}: 実績開始が保存されていない");

            $this->actingAs($manager)->deleteJson(
                route("{$prefix}.schedule-steps.destroy", [$owner, $created])
            )->assertOk();

            $this->assertNull(ScheduleStep::find($created), "{$label}: 削除が効いていない");
        }
    }

    public function test_reorder_rewrites_sort_order_for_every_parent(): void
    {
        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $owner   = $this->makeParent($label);
            $manager = $this->manager();

            $ids = [];
            foreach (['A', 'B', 'C'] as $name) {
                $ids[$name] = $this->actingAs($manager)->postJson(
                    route("{$prefix}.schedule-steps.store", $owner),
                    $this->stepInput(['name' => $name])
                )->json('step.id');
            }

            $this->actingAs($manager)->patchJson(
                route("{$prefix}.schedule-steps.reorder", $owner),
                ['ids' => [$ids['C'], $ids['A'], $ids['B']]]
            )->assertOk();

            $this->assertSame(
                ['C', 'A', 'B'],
                $owner->scheduleSteps()->pluck('name')->all(),
                "{$label}: 並べ替えが効いていない"
            );
        }
    }

    /**
     * ⚠ **部分的な並びを受け付けないこと。** 渡されなかった行の sort_order が
     *   取り残されて順序が壊れるため。
     */
    public function test_reorder_rejects_a_partial_list(): void
    {
        $owner   = $this->makeParent('procurement');
        $manager = $this->manager();

        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $ids[] = $this->actingAs($manager)->postJson(
                route('realestate.procurements.schedule-steps.store', $owner),
                $this->stepInput(['name' => $name])
            )->json('step.id');
        }

        $this->actingAs($manager)->patchJson(
            route('realestate.procurements.schedule-steps.reorder', $owner),
            ['ids' => [$ids[1], $ids[0]]]
        )->assertNotFound();

        $this->assertSame(['A', 'B', 'C'], $owner->scheduleSteps()->pluck('name')->all(), '拒否したのに並びが変わっている');
    }

    // ============================================================
    // バリデーション（設計書 §4.5）
    // ============================================================

    /**
     * ⚠ **実績終了だけが入って実績開始が空、を許さない。**
     *   許すと描画が「実績開始が無い」側へ落ち、実績終了を入れたのに予定の棒が出る。
     */
    public function test_an_actual_end_without_an_actual_start_is_rejected(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['actual_start' => null, 'actual_end' => '2026-07-01'])
        )->assertStatus(422)->assertJsonValidationErrors('actual_start');

        $this->assertSame(0, $owner->scheduleSteps()->count());
    }

    public function test_an_end_before_its_start_is_rejected(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['planned_start' => '2026-07-03', 'planned_end' => '2026-03-16'])
        )->assertStatus(422)->assertJsonValidationErrors('planned_end');
    }

    /**
     * ⚠ **日付が 1 つも無い行を弾かないこと**（設計書 §4.5）。
     *   「先に名前だけ並べて後から日付を入れる」のは自然な使い方。
     */
    public function test_a_step_with_no_dates_at_all_is_accepted(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['planned_start' => null, 'planned_end' => null])
        )->assertOk();

        $this->assertSame(1, $owner->scheduleSteps()->count());
    }

    /**
     * 和名（Bug #37）。
     *
     * ⚠ **期待文言は trans() で組む。** 画面の描画を見るテストでセッションに触ると
     *   エラー表示が消える（Bug #49）が、ここは JSON なので影響しない。それでも
     *   生の文字列をベタ書きすると翻訳ファイルを直したときに二重管理になる。
     */
    public function test_validation_messages_use_the_japanese_field_names(): void
    {
        $owner = $this->makeParent('procurement');

        $errors = $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['name' => ''])
        )->assertStatus(422)->json('errors');

        $this->assertSame(
            [trans('validation.required', ['attribute' => '工程名'])],
            $errors['name'],
            'name の和名が「工程名」に上書きされていない（validate() の第 3 引数）'
        );
    }

    public function test_the_planned_date_labels_are_japanese(): void
    {
        $owner = $this->makeParent('procurement');

        $errors = $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['planned_start' => 'not-a-date'])
        )->assertStatus(422)->json('errors');

        $this->assertSame(
            [trans('validation.date', ['attribute' => '予定開始'])],
            $errors['planned_start'],
            'lang/ja/validation.php の attributes に予定開始が無い（画面に英字が出る）'
        );
    }
}
```

- [ ] **Step 10: 権限の失敗するテストを書く**

`tests/Feature/Schedule/ScheduleStepAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 工程 CRUD の権限と所有権（設計書 §6.2 / §4.3）。
 *
 * ⚠ **「別部署の同じ id」を必ず含める。** 4 親は別テーブルなので id が衝突する。
 *   同部署の別 id だけでは `schedulable_type` の比較を消しても緑のまま通る。
 */
class ScheduleStepAuthorizationTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_a_staff_user_cannot_add_update_delete_or_reorder(): void
    {
        $owner = $this->makeParent('procurement');
        $step  = $owner->scheduleSteps()->create(['name' => '既存', 'category' => 'work', 'sort_order' => 1]);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->postJson(route('realestate.procurements.schedule-steps.store', $owner), $this->stepInput())
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson(route('realestate.procurements.schedule-steps.update', [$owner, $step]), $this->stepInput())
            ->assertForbidden();

        $this->actingAs($staff)
            ->deleteJson(route('realestate.procurements.schedule-steps.destroy', [$owner, $step]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson(route('realestate.procurements.schedule-steps.reorder', $owner), ['ids' => [$step->id]])
            ->assertForbidden();

        $this->assertSame(1, $owner->scheduleSteps()->count(), 'staff の操作で件数が動いている');
        $this->assertSame('既存', $step->fresh()->name);
    }

    /**
     * ⚠ **これが `schedulable_type` の比較を守るテスト。**
     *   仕入れ案件 #1 と建売物件 #1 を両方作り、建売の工程 id を仕入れの URL へ投げる。
     */
    public function test_a_step_belonging_to_a_same_id_parent_in_another_department_is_not_found(): void
    {
        $procurement = $this->makeParent('procurement');
        $property    = $this->makeParent('property');

        $this->assertSame(
            $procurement->getKey(),
            $property->getKey(),
            '前提: 別テーブルで同じ id の行を作れている'
        );

        $foreign = $property->scheduleSteps()->create(['name' => '建売の工程', 'category' => 'work', 'sort_order' => 1]);
        $manager = $this->manager();

        $this->actingAs($manager)->patchJson(
            route('realestate.procurements.schedule-steps.update', [$procurement, $foreign]),
            $this->stepInput(['name' => '乗っ取り'])
        )->assertNotFound();

        $this->actingAs($manager)->deleteJson(
            route('realestate.procurements.schedule-steps.destroy', [$procurement, $foreign])
        )->assertNotFound();

        $this->assertSame('建売の工程', $foreign->fresh()->name, '他部署の工程が書き換えられた');
    }

    /** 同部署の別案件も当然 404 */
    public function test_a_step_belonging_to_another_case_in_the_same_department_is_not_found(): void
    {
        $a = $this->makeParent('procurement');
        $b = $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '別案件']);

        $step = $b->scheduleSteps()->create(['name' => 'B の工程', 'category' => 'work', 'sort_order' => 1]);

        $this->actingAs($this->manager())->patchJson(
            route('realestate.procurements.schedule-steps.update', [$a, $step]),
            $this->stepInput()
        )->assertNotFound();
    }

    /** ⚠ reorder にも他人の id を混ぜられないこと */
    public function test_reorder_rejects_ids_from_another_case(): void
    {
        $a = $this->makeParent('procurement');
        $b = $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '別案件']);

        $mine    = $a->scheduleSteps()->create(['name' => 'A1', 'category' => 'work', 'sort_order' => 1]);
        $foreign = $b->scheduleSteps()->create(['name' => 'B1', 'category' => 'work', 'sort_order' => 1]);

        $this->actingAs($this->manager())->patchJson(
            route('realestate.procurements.schedule-steps.reorder', $a),
            ['ids' => [$foreign->id, $mine->id]]
        )->assertNotFound();

        $this->assertSame(1, $foreign->fresh()->sort_order, '他人の工程の並び順が書き換わった');
    }

    /**
     * 部署をまたがせない（設計書 §4.3）。
     * 住宅だけの権限しか無い manager は不動産の工程を触れない。
     */
    public function test_a_housing_only_manager_cannot_touch_realestate_steps(): void
    {
        $owner   = $this->makeParent('procurement');
        $manager = $this->manager(['housing']);

        $this->actingAs($manager)
            ->postJson(route('realestate.procurements.schedule-steps.store', $owner), $this->stepInput())
            ->assertForbidden();

        $this->assertSame(0, ScheduleStep::count());
    }

    public function test_a_realestate_only_manager_cannot_touch_housing_steps(): void
    {
        $owner   = $this->makeParent('property');
        $manager = $this->manager(['realestate']);

        $this->actingAs($manager)
            ->postJson(route('housing.properties.schedule-steps.store', $owner), $this->stepInput())
            ->assertForbidden();

        $this->assertSame(0, ScheduleStep::count());
    }
}
```

- [ ] **Step 11: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter 'Schedule'
```

Expected: `OK (32 tests, ...)`（Schema 2 + Milestone 9 + Wiring 5 + Crud 10 + Auth 6）

- [ ] **Step 12: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1116 tests, ...)`

⚠ `JapaneseValidationMessagesTest::test_every_validated_field_has_a_japanese_attribute_label` が
落ちたら、Step 1 の和名追加が漏れている。**このテストは新しいコントローラの `validate()` キーを
自動で拾う**ので、和名を足すまで赤になる（それが正しい動き）。

- [ ] **Step 13: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Http/Controllers/ScheduleStepController.php routes/web.php lang/ja/validation.php tests/Feature/Schedule/
git commit -m "$(cat <<'MSG'
feat(schedule): 工程 CRUD のルート 16 本とコントローラを足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 8: 詳細カード（`ScheduleCardService` ＋ 共通 partial ＋ 4 画面）

**Files:**
- Create: `app/Services/ScheduleCardService.php`
- Create: `resources/views/_partials/_schedule_gantt.blade.php`
- Create: `resources/views/_partials/_schedule_section.blade.php`
- Modify: `app/Http/Controllers/ScheduleStepController.php`（応答に `gantt_html` を足す）
- Modify: 4 コントローラの `show()`（`$schedule` を渡す）
- Modify: 4 つの `show.blade.php`（`@include`）
- Modify: `tests/Feature/AjaxErrorFeedbackTest.php`（新 partial を分類に足す）
- Test: `tests/Feature/Schedule/ScheduleSectionRenderTest.php`

### このタスクのレイアウト上の決め事（全部理由つき）

⚠ **ガントの行は grid ではなく flex にする。** 理由が 2 つある:
① 素の `1fr` トラックは最小値が `auto` で、中身の min-content 幅がカードを押し広げる（Bug #29）。
② `grid-template-columns: 262px …` は `MobileLayoutTest::test_inline_multi_column_grids_declare_a_mobile_class`
に**固定 px を含む多列グリッド**として拾われ、モバイル用クラスか除外リスト入りを要求される。
ガントは `overflow-x: auto` ＋ `min-width: 940px` の**横スクローラの中で完結する**ので
「モバイルで段組みを落とす」対象ではない。flex（`flex: 0 0 262px` ＋ `flex: 1 1 auto; min-width: 0;`）で書けば
どちらの罠にも触れず、除外リストを増やさずに済む。

⚠ **`min-width: 0` を track 側に必ず付ける。** flex アイテムの既定 `min-width: auto` が
Bug #29 ① と同じ膨張を起こす。

⚠ **`x-show` と `:style` を同じタグに置かない**（Bug #32）。出し分けは `x-show` 単独で行い、
`display` を含むスタイルは内側のラッパーに置く。

⚠ **`<option>` は `@foreach` で静的に出す**（Bug #16。`<template x-for>` は x-model 同期後に描画される）。

⚠ **`<script>` 内のコメントに `@json` や `<x-` と書かない**（Bug #30）。書くなら `@@json`。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleSectionRenderTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 詳細ページの工程表カード（設計書 §4.1）。
 *
 * ⚠ **4 画面すべてを開く。** 共通 partial なので 1 画面で足りると思いがちだが、
 *   `@include` の位置と親の autoMilestones() は画面ごとに違う（設計書 §10-6）。
 *
 * ⚠ **partial の定義が 1 箇所であることも固定する。** 同じマークアップを 4 箇所にコピーすると
 *   一部だけ直す事故が起きる（Bug #41 / 地図 POI の件で実測済み）。
 */
class ScheduleSectionRenderTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const SECTION_PARTIAL = 'resources/views/_partials/_schedule_section.blade.php';

    private const GANTT_PARTIAL   = 'resources/views/_partials/_schedule_gantt.blade.php';

    /** 4 つの show.blade.php */
    private const SHOW_VIEWS = [
        'resources/views/realestate/procurements/show.blade.php',
        'resources/views/realestate/projects/show.blade.php',
        'resources/views/housing/properties/show.blade.php',
        'resources/views/housing/custom-orders/show.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 4 親の詳細 URL */
    private function showUrl(string $label, \Illuminate\Database\Eloquent\Model $owner): string
    {
        return route(self::PARENTS[$label][1] . '.show', $owner);
    }

    // ============================================================
    // 描画
    // ============================================================

    public function test_every_detail_page_renders_the_schedule_card(): void
    {
        foreach (array_keys(self::PARENTS) as $label) {
            $owner = $this->makeParent($label);
            $owner->scheduleSteps()->create([
                'name' => '建築確認申請', 'category' => 'permit',
                'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'sort_order' => 1,
            ]);

            $html = $this->actingAs($this->manager())->get($this->showUrl($label, $owner))
                ->assertOk()->getContent();

            $this->assertStringContainsString('工程表', $html, "{$label}: カードの見出しが無い");
            $this->assertStringContainsString('建築確認申請', $html, "{$label}: 工程名が出ていない");
            $this->assertStringContainsString('id="schedule-gantt"', $html, "{$label}: ガントの入れ物が無い");
        }
    }

    /**
     * ⚠ **工程 0 件のとき、空のガントを描かず案内文を出すこと**（設計書 §5.5）。
     *   日付が 1 つも無い案件は時間軸を作れない（0 除算とレイアウト崩れの両方を防ぐ）。
     */
    public function test_a_case_without_steps_shows_a_notice_instead_of_an_empty_gantt(): void
    {
        foreach (array_keys(self::PARENTS) as $label) {
            $owner = $this->makeParent($label);

            $html = $this->actingAs($this->manager())->get($this->showUrl($label, $owner))
                ->assertOk()->getContent();

            $this->assertStringContainsString('工程が登録されていません', $html, "{$label}: 案内文が無い");
            $this->assertStringNotContainsString('schedule-gantt-track', $html, "{$label}: 空のガントを描いている");
        }
    }

    /**
     * ⚠ **日付が 1 つも無い工程は一覧に残す**（設計書 §3.7）。
     *   黙って消すと利用者は「保存できていない」と誤解する。
     */
    public function test_a_step_without_dates_is_listed_with_a_placeholder(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create(['name' => '未定の工程', 'category' => 'other', 'sort_order' => 1]);

        $html = $this->actingAs($this->manager())->get($this->showUrl('procurement', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('未定の工程', $html, '日付が無い工程を画面から消してはいけない');
        $this->assertStringContainsString('日付未設定', $html, '期間欄に「日付未設定」を出すこと');
    }

    /** 自動マイルストーンが画面に出ること（設計書 §3.4） */
    public function test_auto_milestones_are_drawn_from_the_existing_date_columns(): void
    {
        $owner = $this->makeParent('procurement', [
            'contract_date'   => '2026-01-23',
            'settlement_date' => '2026-05-29',
        ]);
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-02-01', 'planned_end' => '2026-03-01', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->manager())->get($this->showUrl('procurement', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('契約', $html);
        $this->assertStringContainsString('決済', $html);
    }

    // ============================================================
    // 権限（画面側）
    // ============================================================

    /**
     * staff は閲覧できるが編集 UI は出ない。
     *
     * ⚠ **「無いこと」は生 HTML で見る。** Ajax の URL が消えていることを直接確かめる
     *   （ボタンの文言だけ見ると別の場所の同じ語に一致しうる）。
     */
    public function test_a_staff_user_sees_the_gantt_but_no_editing_ui(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-02-01', 'planned_end' => '2026-03-01', 'sort_order' => 1,
        ]);

        $html = $this->actingAs($this->staff())->get($this->showUrl('procurement', $owner))
            ->assertOk()->getContent();

        $this->assertStringContainsString('測量', $html, 'staff もガントは見られる');
        $this->assertStringNotContainsString(
            route('realestate.procurements.schedule-steps.store', $owner),
            $html,
            'staff の画面に工程追加のエンドポイントが出ている'
        );
    }

    // ============================================================
    // 画面が描いたエンドポイントを、そのまま送り返す（設計書 §8.2 / プラン 決定 B）
    // ============================================================

    /**
     * ⚠ **URL をテスト側で route() から組み立ててはいけない。** 画面側の配線が壊れても
     *   緑のまま通る（Bug #47 / #54 ②）。**画面が出力した設定を抜き出して、それを叩く。**
     */
    private function endpointsFromPage(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/var SCHEDULE_ENDPOINTS = (\{.*?\});/s',
            $html,
            '画面がエンドポイント設定を出力していない'
        );

        preg_match('/var SCHEDULE_ENDPOINTS = (\{.*?\});/s', $html, $m);
        $endpoints = json_decode($m[1], true);

        $this->assertIsArray($endpoints, 'SCHEDULE_ENDPOINTS が JSON として読めない');

        return $endpoints;
    }

    public function test_the_endpoints_the_page_emits_actually_work(): void
    {
        foreach (array_keys(self::PARENTS) as $label) {
            $owner   = $this->makeParent($label);
            $manager = $this->manager();

            $html      = $this->actingAs($manager)->get($this->showUrl($label, $owner))->getContent();
            $endpoints = $this->endpointsFromPage($html);

            // 追加
            $created = $this->actingAs($manager)
                ->postJson($endpoints['store'], $this->stepInput())
                ->assertOk()->json('step.id');

            // 更新（__ID__ を実 id に差し替えるのは JS と同じ手順）
            $this->actingAs($manager)->patchJson(
                str_replace('__ID__', (string) $created, $endpoints['update']),
                $this->stepInput(['name' => '差し替え後'])
            )->assertOk();

            $this->assertSame('差し替え後', ScheduleStep::findOrFail($created)->name, "{$label}: update の URL が違う");

            // 削除
            $this->actingAs($manager)->deleteJson(
                str_replace('__ID__', (string) $created, $endpoints['destroy'])
            )->assertOk();

            $this->assertNull(ScheduleStep::find($created), "{$label}: destroy の URL が違う");
        }
    }

    /**
     * ⚠ **CSRF トークンの meta が出ていること。**
     *   `@csrf` / `_token` の欠落は Feature テストでは原理的に挙動から検出できない
     *   （`VerifyCsrfToken::handle()` が `runningUnitTests()` で素通りする）。
     *   描画されていることを見るのが唯一の手（Bug #47）。
     */
    public function test_the_page_exposes_a_csrf_token_for_the_ajax_calls(): void
    {
        $owner = $this->makeParent('procurement');

        $html = $this->actingAs($this->manager())->get($this->showUrl('procurement', $owner))->getContent();

        $this->assertStringContainsString('name="csrf-token"', $html, 'Ajax が使う CSRF トークンが無い');
    }

    /** Ajax の応答が、描き直したガントを返すこと（プラン 決定 A） */
    public function test_saving_returns_a_freshly_rendered_gantt(): void
    {
        $owner = $this->makeParent('procurement');

        $json = $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['name' => '地盤改良'])
        )->assertOk()->json();

        $this->assertArrayHasKey('gantt_html', $json, 'ガントを描き直して返していない（保存後に画面が古いままになる）');
        $this->assertStringContainsString('id="schedule-gantt"', $json['gantt_html']);
        $this->assertStringContainsString('地盤改良', $json['gantt_html'], '保存した工程が描き直しに反映されていない');
    }

    // ============================================================
    // 構造 — partial の定義は 1 箇所
    // ============================================================

    /**
     * ⚠ 4 つの show が**同じ partial を `@include` している**こと。
     *   インラインの複製に置き換える変異をここで止める（Bug #41）。
     */
    public function test_all_four_detail_views_include_the_one_shared_partial(): void
    {
        foreach (self::SHOW_VIEWS as $view) {
            $this->assertStringContainsString(
                "@include('_partials._schedule_section'",
                File::get(base_path($view)),
                "{$view} が共通 partial を include していない（マークアップを複製していないか）"
            );
        }
    }

    /**
     * ⚠ **マークアップの実体が partial 側にしか無いこと。**
     *   include を残したままインラインへ複製する変異は、上のテストだけでは止まらない。
     */
    public function test_the_gantt_markup_lives_only_in_the_partial(): void
    {
        $owners = [];

        foreach (array_merge(self::SHOW_VIEWS, [self::SECTION_PARTIAL, self::GANTT_PARTIAL]) as $view) {
            if (str_contains(File::get(base_path($view)), 'schedule-gantt-track')) {
                $owners[] = $view;
            }
        }

        $this->assertSame(
            [self::GANTT_PARTIAL],
            $owners,
            'ガントのマークアップが partial 以外にもあります（複製すると一部だけ直す事故が起きます）'
        );
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleSectionRenderTest
```

Expected: FAIL。`Failed asserting that '' contains "工程表"`（まだ何も描画していない）

- [ ] **Step 3: `ScheduleCardService` を書く**

`app/Services/ScheduleCardService.php`:

```php
<?php

namespace App\Services;

use App\Enums\ScheduleStepCategory;
use App\Models\ScheduleStep;
use App\Support\GanttScale;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 詳細ページの工程表カード 1 枚ぶんの表示データを組み立てる（設計書 §4.1 / §5）。
 *
 * ⚠ **日付 → 位置(%) の変換はここ（と GanttScale）だけが行う。** JS 側に同じ計算を持たせない
 *   （Bug #41: 同じ計算の 2 実装は無音で漂流する）。だから Ajax の応答でも
 *   `_partials._schedule_gantt` を**サーバでレンダリングし直して**返す。
 *
 * ⚠ **「今日」は引数で受け取れるようにする。** テストが実行日に依存しないようにするため。
 */
class ScheduleCardService
{
    /** 軸の前後に取る余白（月）。設計書 §5.5 */
    private const PADDING_MONTHS = 1;

    /** 罫線を濃くする月（四半期の頭） */
    private const QUARTER_MONTHS = [1, 4, 7, 10];

    /**
     * @return array{
     *   owner: Model, steps: Collection, endpoints: array<string, string>,
     *   categories: list<array{value: string, label: string, color: string}>,
     *   today: CarbonImmutable, gantt: array|null
     * }
     */
    public function build(Model $owner, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $steps = $owner->scheduleSteps()->get();

        return [
            'owner'      => $owner,
            'steps'      => $steps,
            // ⚠ JS へ渡す配列は**ここで組み立てる**。Blade の `@json()` に多行の配列リテラルや
            //   `->method()` を渡すと、Blade の引数パーサが途中で打ち切って壊れた PHP を吐き、
            //   本番の view:cache 後に ParseError で 500 する（Bug #26）。
            //   Blade 側は必ず `@json($単一変数)` の形にする。
            'rows'       => $steps->map(fn (ScheduleStep $s) => [
                'id'            => $s->id,
                'name'          => $s->name,
                'category'      => $s->category->value,
                'planned_start' => $s->planned_start?->toDateString(),
                'planned_end'   => $s->planned_end?->toDateString(),
                'actual_start'  => $s->actual_start?->toDateString(),
                'actual_end'    => $s->actual_end?->toDateString(),
                'notes'         => $s->notes ?? '',
            ])->values()->all(),
            'endpoints'  => $this->endpoints($owner),
            'categories' => $this->categories(),
            'today'      => $today,
            'gantt'      => $this->gantt($steps, $owner->autoMilestones(), $today),
        ];
    }

    /**
     * ⚠ `update` / `destroy` は id を含むので **`__ID__` のひな型**を返す。
     *   JS 側が実 id へ差し替える。テストも同じ手順を踏む（プラン 決定 B）。
     */
    private function endpoints(Model $owner): array
    {
        return [
            'store'   => route($owner->scheduleStepRoute('store'), $owner),
            'reorder' => route($owner->scheduleStepRoute('reorder'), $owner),
            'update'  => route($owner->scheduleStepRoute('update'), [$owner, '__ID__']),
            'destroy' => route($owner->scheduleStepRoute('destroy'), [$owner, '__ID__']),
        ];
    }

    /** @return list<array{value: string, label: string, color: string}> */
    private function categories(): array
    {
        return array_map(
            fn (ScheduleStepCategory $c) => ['value' => $c->value, 'label' => $c->label(), 'color' => $c->color()],
            ScheduleStepCategory::cases()
        );
    }

    /**
     * 時間軸と描画用の行を作る。日付が 1 つも無ければ null（＝ガントを描かない）。
     *
     * ⚠ **日付が 1 つも無い案件は軸を作れない**（設計書 §5.5）。0 除算とレイアウト崩れの
     *   両方を防ぐため、ここで null を返して Blade 側に案内文を出させる。
     */
    private function gantt(Collection $steps, array $milestones, CarbonImmutable $today): ?array
    {
        $dates = [];

        foreach ($steps as $step) {
            foreach ([$step->planned_start, $step->planned_end, $step->actual_start, $step->actual_end] as $d) {
                if ($d !== null) {
                    $dates[] = CarbonImmutable::instance($d)->startOfDay();
                }
            }
        }

        foreach ($milestones as $m) {
            $dates[] = CarbonImmutable::instance($m['date'])->startOfDay();
        }

        if ($dates === []) {
            return null;
        }

        $from = min($dates)->subMonths(self::PADDING_MONTHS)->startOfMonth();
        // ⚠ endOfMonth() は 23:59:59.999999 を返すので startOfDay() で揃える。
        //   揃えないと日数が 1 多く出る（実測: 2026-02-01〜2026-08-31 が 213 日になった）。
        $to = max($dates)->addMonths(self::PADDING_MONTHS)->endOfMonth()->startOfDay();

        // 今日が範囲外なら今日も含める（今日線が枠外に出ないように）
        if ($today->lessThan($from)) {
            $from = $today->startOfMonth();
        }
        if ($today->greaterThan($to)) {
            $to = $today->endOfMonth()->startOfDay();
        }

        $scale = new GanttScale($from, $to);

        return [
            'months'     => $this->months($scale),
            'rows'       => $steps->map(fn (ScheduleStep $s) => $this->row($s, $scale, $today))->all(),
            'milestones' => $this->milestones($milestones, $scale, $today),
            'todayPct'   => $scale->contains($today) ? $scale->left($today) : null,
            'todayLabel' => $today->format('n/j'),
        ];
    }

    /** @return list<array{label: string, year: string, widthPct: float, quarterStart: bool}> */
    private function months(GanttScale $scale): array
    {
        $months = [];
        $cursor = $scale->from()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($scale->to())) {
            $months[] = [
                'label'        => $cursor->format('n') . '月',
                'year'         => $cursor->format('Y'),
                'widthPct'     => $cursor->daysInMonth / $scale->totalDays() * 100,
                'quarterStart' => in_array($cursor->month, self::QUARTER_MONTHS, true),
            ];

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $months;
    }

    /**
     * 1 工程ぶんの描画情報。
     *
     * ⚠ **範囲外へはみ出さないよう clamp するのは呼び出し側（ここ）の責務**（設計書 §5.1）。
     *   幅は「左端からの残り」までに抑える（棒が枠を突き抜けてレイアウトを壊さないため）。
     */
    private function row(ScheduleStep $step, GanttScale $scale, CarbonImmutable $today): array
    {
        $row = [
            'id'         => $step->id,
            'name'       => $step->name,
            'color'      => $step->category->color(),
            'periodText' => $step->periodText($today),
            'delayDays'  => $step->delayDays($today),
            'progress'   => $step->progress(),
            'kind'       => 'none',
            'leftPct'    => 0.0,
            'widthPct'   => 0.0,
        ];

        if (! $step->isDrawable()) {
            return $row;
        }

        $start          = $step->drawStart();
        $row['kind']    = $step->isMilestone() ? 'milestone' : 'bar';
        $row['leftPct'] = GanttScale::clamp($scale->left($start), 0.0, 100.0);

        if ($row['kind'] === 'bar') {
            $row['widthPct'] = GanttScale::clamp(
                $scale->width($start, $step->drawEnd($today)),
                0.0,
                100.0 - $row['leftPct']
            );
        }

        return $row;
    }

    /** @return list<array{label: string, leftPct: float, reached: bool}> */
    private function milestones(array $milestones, GanttScale $scale, CarbonImmutable $today): array
    {
        return array_map(function (array $m) use ($scale, $today) {
            $date = CarbonImmutable::instance($m['date'])->startOfDay();

            return [
                'label'   => $m['label'],
                'leftPct' => GanttScale::clamp($scale->left($date), 0.0, 100.0),
                // ⚠ 塗り分けは日付だけで決める（その列が予定か実績かは見ない。設計書 §3.4）
                'reached' => ScheduleStepStatus::isReached($date, $today),
            ];
        }, $milestones);
    }
}
```

- [ ] **Step 4: ガント本体の partial を書く**

`resources/views/_partials/_schedule_gantt.blade.php`:

```blade
{{--
    工程表のガント本体（設計書 §4.1 / §5）。

    ⚠ Ajax の保存後もこの partial を**サーバでレンダリングし直して**差し替える。
       位置(%) の計算を JS 側に持たせないため（Bug #41）。差し替えは outerHTML なので
       この partial の**最外殻は id="schedule-gantt" の 1 要素**でなければならない。

    ⚠ 行は grid ではなく flex にしている。素の 1fr トラックは最小値が auto で中身の
       min-content 幅がカードを押し広げるため（Bug #29）。track 側の min-width: 0 が要。
--}}
<div id="schedule-gantt">
@if($schedule['gantt'] === null)
    <div style="padding: 28px 16px; text-align: center; color: #9CA3AF; font-size: 13px;">
        工程が登録されていません。「＋ 工程を追加」から登録してください。
    </div>
@else
    @php($g = $schedule['gantt'])
    <div style="border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; background: white;">
        <div style="overflow-x: auto;">
            <div style="min-width: 940px;">

                {{-- 月ヘッダ --}}
                <div style="display: flex; height: 42px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                    <div style="flex: 0 0 262px; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">工程</div>
                    <div style="flex: 1 1 auto; min-width: 0; position: relative; display: flex;">
                        @foreach($g['months'] as $m)
                            <div style="width: {{ $m['widthPct'] }}%; border-right: 1px solid #E5E7EB; {{ $m['quarterStart'] ? 'border-left: 1px solid #D1D5DB;' : '' }} font-size: 11px; color: #6B7280; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.35; box-sizing: border-box;">
                                <span style="font-size: 9.5px; color: #9CA3AF;">{{ $m['year'] }}</span>
                                <span>{{ $m['label'] }}</span>
                            </div>
                        @endforeach
                        @if($g['todayPct'] !== null)
                            <div style="position: absolute; top: 2px; left: {{ $g['todayPct'] }}%; transform: translateX(-50%); background: #EF4444; color: white; font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; z-index: 4;">今日 {{ $g['todayLabel'] }}</div>
                        @endif
                    </div>
                </div>

                {{-- 自動マイルストーン（既存の日付列から描く ◆。読み取り専用） --}}
                @if($g['milestones'] !== [])
                    <div class="schedule-gantt-track" style="display: flex; height: 34px; border-bottom: 1px solid #F3F4F6;">
                        <div style="flex: 0 0 262px; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 12.5px; color: #6B7280;">節目</div>
                        <div style="flex: 1 1 auto; min-width: 0; position: relative;">
                            @if($g['todayPct'] !== null)
                                <div style="position: absolute; top: 0; bottom: 0; left: {{ $g['todayPct'] }}%; width: 0; border-left: 2px dashed #EF4444; z-index: 3;"></div>
                            @endif
                            @foreach($g['milestones'] as $ms)
                                <div style="position: absolute; top: 11px; left: {{ $ms['leftPct'] }}%; z-index: 2;">
                                    <span style="display: block; width: 11px; height: 11px; border-radius: 2px; transform: rotate(45deg); {{ $ms['reached'] ? 'background: #111827;' : 'background: white; border: 2px solid #111827;' }}"></span>
                                    <span style="position: absolute; left: 15px; top: -3px; font-size: 10.5px; font-weight: 600; color: #374151; white-space: nowrap;">{{ $ms['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- 工程の行（1 行 1 本。設計書 §5.2） --}}
                @foreach($g['rows'] as $row)
                    <div class="schedule-gantt-track" style="display: flex; height: 34px; border-bottom: 1px solid #F3F4F6; {{ $loop->odd ? 'background: #FCFCFD;' : '' }}">
                        <div style="flex: 0 0 262px; border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 8px; padding: 0 12px; font-size: 12.5px; color: #111827;">
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">{{ $row['name'] }}</span>
                            @if($row['delayDays'] > 0)
                                <span style="margin-left: auto; font-size: 10.5px; color: #DC2626; font-weight: 700; white-space: nowrap;">+{{ $row['delayDays'] }}日</span>
                            @else
                                <span style="margin-left: auto; font-size: 10.5px; color: #9CA3AF; white-space: nowrap;">{{ $row['periodText'] }}</span>
                            @endif
                        </div>
                        <div style="flex: 1 1 auto; min-width: 0; position: relative;">
                            @if($g['todayPct'] !== null)
                                <div style="position: absolute; top: 0; bottom: 0; left: {{ $g['todayPct'] }}%; width: 0; border-left: 2px dashed #EF4444; z-index: 3;"></div>
                            @endif
                            @if($row['kind'] === 'bar')
                                <div style="position: absolute; top: 11px; height: 12px; border-radius: 4px; box-sizing: border-box; left: {{ $row['leftPct'] }}%; width: {{ $row['widthPct'] }}%; background: {{ $row['color'] }};"></div>
                            @elseif($row['kind'] === 'milestone')
                                <div style="position: absolute; top: 11px; left: {{ $row['leftPct'] }}%; z-index: 2;">
                                    <span style="display: block; width: 11px; height: 11px; border-radius: 2px; transform: rotate(45deg); background: {{ $row['color'] }};"></span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

    {{-- 凡例 --}}
    <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; font-size: 11.5px; color: #6B7280;">
        @foreach($schedule['categories'] as $c)
            <span><i style="display: inline-block; width: 22px; height: 9px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; background: {{ $c['color'] }};"></i>{{ $c['label'] }}</span>
        @endforeach
        <span><span style="display: inline-block; width: 9px; height: 9px; background: #111827; transform: rotate(45deg); margin-right: 7px; vertical-align: -1px;"></span>節目（塗り＝到達済み / 白抜き＝これから）</span>
    </div>
@endif
</div>
```

- [ ] **Step 5: カード本体の partial を書く**

`resources/views/_partials/_schedule_section.blade.php`:

```blade
{{--
    工程表カード（設計書 §4.1）。**4 つの詳細画面が共有する唯一の定義**。

    ⚠ 部署ディレクトリに置かないこと。不動産の部品を住宅が借りている形にすると、
       次に触る人が不動産都合で壊す（設計書 §4.1）。

    必要な変数: $schedule（App\Services\ScheduleCardService::build() の戻り値）
                $scheduleCanEdit（bool）
--}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
    <div class="flex items-center gap-2 mb-3">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h2 class="text-base font-bold text-gray-900">工程表</h2>
    </div>

    @include('_partials._schedule_gantt', ['schedule' => $schedule])

    @if($scheduleCanEdit)
        <div x-data="scheduleSection()" style="margin-top: 18px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <button type="button" @click="startAdd()"
                        style="padding: 5px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; background: white; cursor: pointer;">
                    ＋ 工程を追加
                </button>
                <span x-show="message" x-text="message" style="font-size: 12px; color: #047857;"></span>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
                    <thead>
                        <tr>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap; width: 70px;">並び</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">工程名</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">種類</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">予定開始</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">予定終了</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">実績開始</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">実績終了</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">備考</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="row.id">
                            <tr>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">
                                    {{-- 並べ替えはドラッグでなく ↑↓ ボタン（設計書 §4.4） --}}
                                    <button type="button" @click="move(i, -1)" :disabled="i === 0" title="上へ"
                                            style="border: 1px solid #D1D5DB; background: white; border-radius: 4px; width: 24px; height: 24px; cursor: pointer;">↑</button>
                                    <button type="button" @click="move(i, 1)" :disabled="i === rows.length - 1" title="下へ"
                                            style="border: 1px solid #D1D5DB; background: white; border-radius: 4px; width: 24px; height: 24px; cursor: pointer;">↓</button>
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;">
                                    {{-- ⚠ 日本語入力の確定 Enter で誤発火しないように isComposing を挟む --}}
                                    <input type="text" x-model="row.name" maxlength="100" @change="save(row)"
                                           @keydown.enter="$event.isComposing || save(row)"
                                           style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;">
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;">
                                    {{-- ⚠ option は @@foreach で静的に出す（x-for は x-model 同期後に描画される。Bug #16） --}}
                                    <select x-model="row.category" @change="save(row)"
                                            style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;">
                                        @foreach($schedule['categories'] as $c)
                                            <option value="{{ $c['value'] }}">{{ $c['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.planned_start" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.planned_end" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.actual_start" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.actual_end" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;">
                                    <input type="text" x-model="row.notes" maxlength="255" @change="save(row)"
                                           @keydown.enter="$event.isComposing || save(row)"
                                           style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;">
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">
                                    <button type="button" @click="remove(row)"
                                            style="color: #DC2626; border: none; background: none; cursor: pointer; font-size: 16px; line-height: 1;">×</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ⚠ @@json には**単一の変数**しか渡さない（Bug #26）。多行の配列リテラルや
             ->method() を渡すと Blade の引数パーサが途中で打ち切り、本番の view:cache 後に
             ParseError で 500 する。配列は ScheduleCardService が組み立てて 'rows' で渡す。 --}}
        @php($scheduleEndpoints = $schedule['endpoints'])
        @php($scheduleRows = $schedule['rows'])
        <script>
        var SCHEDULE_ENDPOINTS = @json($scheduleEndpoints);
        var SCHEDULE_ROWS = @json($scheduleRows);

        function scheduleSection() {
            return {
                rows: SCHEDULE_ROWS,
                message: '',
                token: document.querySelector('meta[name="csrf-token"]').content,

                // 保存のたびにサーバでガントを描き直して差し替える。
                // 位置の計算を JS 側に持たせないため（同じ計算の 2 実装は無音で漂流する）。
                apply: function (data, fn) {
                    if (!data) { return; }
                    if (data.gantt_html) {
                        document.getElementById('schedule-gantt').outerHTML = data.gantt_html;
                    }
                    if (fn) { fn(data); }
                    this.notify();
                },

                notify: function () {
                    var self = this;
                    self.message = '保存しました。';
                    setTimeout(function () { self.message = ''; }, 3000);
                },

                send: function (url, method, body) {
                    return fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.token,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body ? JSON.stringify(body) : null
                    })
                    .then(function (res) {
                        if (!res.ok) {
                            return res.json().then(function (err) {
                                var msg = err.message || 'エラーが発生しました。';
                                if (err.errors) { msg = msg + '\n' + Object.values(err.errors).flat().join('\n'); }
                                alert(msg);
                                return null;
                            }).catch(function () {
                                alert('サーバーエラーが発生しました（' + res.status + '）');
                                return null;
                            });
                        }
                        return res.json();
                    })
                    .catch(function () { alert('通信に失敗しました。'); return null; });
                },

                payload: function (row) {
                    return {
                        name: row.name,
                        category: row.category,
                        planned_start: row.planned_start || null,
                        planned_end: row.planned_end || null,
                        actual_start: row.actual_start || null,
                        actual_end: row.actual_end || null,
                        notes: row.notes || null
                    };
                },

                startAdd: function () {
                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.store, 'POST', {
                        name: '新しい工程', category: 'other',
                        planned_start: null, planned_end: null, actual_start: null, actual_end: null, notes: null
                    }).then(function (d) {
                        self.apply(d, function (data) { self.rows.push(data.step); });
                    });
                },

                save: function (row) {
                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.update.replace('__ID__', row.id), 'PATCH', self.payload(row))
                        .then(function (d) { self.apply(d, null); });
                },

                remove: function (row) {
                    if (!confirm('この工程を削除しますか？')) { return; }
                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.destroy.replace('__ID__', row.id), 'DELETE', null)
                        .then(function (d) {
                            self.apply(d, function (data) {
                                self.rows = self.rows.filter(function (r) { return r.id !== data.id; });
                            });
                        });
                },

                move: function (index, delta) {
                    var target = index + delta;
                    if (target < 0 || target >= this.rows.length) { return; }

                    var moved = this.rows.slice();
                    var tmp = moved[index];
                    moved[index] = moved[target];
                    moved[target] = tmp;
                    this.rows = moved;

                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.reorder, 'PATCH', {
                        ids: moved.map(function (r) { return r.id; })
                    }).then(function (d) { self.apply(d, null); });
                }
            };
        }
        </script>
    @endif
</div>
```

⚠ **`SCHEDULE_ENDPOINTS` / `SCHEDULE_ROWS` は `<script>` の中で定義する**（`x-data` 属性に
`@json` を入れない。Bug #23）。`x-data="scheduleSection()"` は関数呼び出しだけにする（Bug #4）。

⚠ **上の Blade コメントで `@@foreach` とエスケープしているのは意図的**（Bug #30）。
`<script>` の外でも、Blade コメントの外にディレクティブ名を素で書くとコンパイラが展開する。

- [ ] **Step 6: 応答にガントの再レンダリングを足す**

`app/Http/Controllers/ScheduleStepController.php` に import と private メソッドを足し、
`store` / `update` / `destroy` / `reorder` の `response()->json([...])` へ `'gantt_html' => $this->ganttHtml($owner)` を加える。

import 節に追加:

```php
use App\Services\ScheduleCardService;
```

メソッド追加（`payload()` の下）:

```php
    /**
     * 保存後のガントをサーバでレンダリングして返す（プラン 決定 A）。
     *
     * ⚠ **JS 側で位置(%) を再計算させない。** 同じ計算の 2 実装は無音で漂流する（Bug #41）。
     *   しかも日付を動かすと軸の範囲（設計書 §5.5）ごと変わるので、部分的な再計算では
     *   原理的に足りない。
     */
    private function ganttHtml(Model $owner): string
    {
        return view('_partials._schedule_gantt', [
            'schedule' => app(ScheduleCardService::class)->build($owner->refresh()),
        ])->render();
    }
```

各アクションの戻り値を次のように変える:

```php
        // store
        return response()->json([
            'success'    => true,
            'step'       => $this->payload($step),
            'gantt_html' => $this->ganttHtml($owner),
        ]);

        // update
        return response()->json([
            'success'    => true,
            'step'       => $this->payload($step->fresh()),
            'gantt_html' => $this->ganttHtml($owner),
        ]);

        // destroy
        return response()->json([
            'success'    => true,
            'id'         => $step->id,
            'gantt_html' => $this->ganttHtml($owner),
        ]);

        // reorder
        return response()->json([
            'success'    => true,
            'ids'        => $ids,
            'gantt_html' => $this->ganttHtml($owner),
        ]);
```

- [ ] **Step 7: 4 つの `show()` に `$schedule` を渡す**

各コントローラの `show()` で、`return view(...)` の**直前**に次を足し、view データに加える。

`RealEstate/ProcurementController::show()`:

```php
        // 工程表（設計書 §4.1）
        $schedule        = app(\App\Services\ScheduleCardService::class)->build($procurement);
        $scheduleCanEdit = $request->user()->role->isManagerOrAbove();
```

⚠ **`show()` の引数に `Request $request` が無ければ足すこと**（`public function show(Request $request, ReProcurement $procurement)`）。
⚠ `compact(...)` を使っている箇所は `'schedule', 'scheduleCanEdit'` を足す。
`view(..., [...])` 形式なら配列に `'schedule' => $schedule, 'scheduleCanEdit' => $scheduleCanEdit,` を足す。

同様に:

| コントローラ | 親の変数 |
|---|---|
| `RealEstate/ProjectController::show()` | `$project` |
| `Housing/PropertyController::show()` | `$property` |
| `Housing/CustomOrderController::show()` | `$customOrder`（ビュー内では `$o` に代入されている場合がある。**view データのキーは `schedule` で統一**） |

- [ ] **Step 8: 4 つの `show.blade.php` に `@include` を足す**

いずれも次の 1 行を挿入する:

```blade
    {{-- 工程表（4 画面共通の partial。設計書 §4.1） --}}
    @include('_partials._schedule_section', ['schedule' => $schedule, 'scheduleCanEdit' => $scheduleCanEdit])
```

挿入位置（**行番号ではなくアンカーで探すこと**。行はずれる）:

| ファイル | アンカー（この行の**直前**に入れる） |
|---|---|
| `resources/views/realestate/procurements/show.blade.php` | `{{-- 添付ファイル --}}` |
| `resources/views/realestate/projects/show.blade.php` | `{{-- 添付ファイル --}}` |
| `resources/views/housing/properties/show.blade.php` | `{{-- ファイル管理 --}}` |
| `resources/views/housing/custom-orders/show.blade.php` | `{{-- 備考 --}}` |

- [ ] **Step 9: 走査テストの分類に新 partial を足す**

⚠ **これをしないと `AjaxErrorFeedbackTest::test_every_fetch_view_is_classified` が落ちる**
（`fetch` を持つビューは全件どれかのリストに入っていなければならない。Bug #45 の全件分類）。

`tests/Feature/AjaxErrorFeedbackTest.php` の `VIEWS_NULL_RETURN` 配列に、**アルファベット順の位置**へ追加:

```php
        '_partials/_schedule_section.blade.php',                    // null 返し（工程表の CRUD）
```

⚠ この方式が満たすべき不変条件は「`.ok` の分岐数 == `!data` ガードの数」。
partial は `send()` に fetch を 1 本だけ持ち、`if (!res.ok)` 1 個・`if (!data)` 1 個なので **1 == 1** で通る。
**fetch を増やすときはこの対応を崩さないこと**（増やすなら `send()` を経由させる）。

- [ ] **Step 10: テストが通ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit --filter ScheduleSectionRenderTest
```

Expected: `OK (10 tests, ...)`

- [ ] **Step 11: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1126 tests, ...)`

⚠ ここで落ちやすい既存テストと原因:
- `AjaxErrorFeedbackTest` → Step 9 の分類追加漏れ
- `MobileLayoutTest::test_every_table_has_a_horizontally_scrollable_ancestor` → 編集テーブルを
  `overflow-x: auto` の div で包み忘れ
- `AlpineXShowDisplayConflictTest` → `x-show` と `:style` を同じタグに書いた

- [ ] **Step 12: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Services/ScheduleCardService.php resources/views/_partials/ app/Http/Controllers/ScheduleStepController.php app/Http/Controllers/RealEstate/ProcurementController.php app/Http/Controllers/RealEstate/ProjectController.php app/Http/Controllers/Housing/PropertyController.php app/Http/Controllers/Housing/CustomOrderController.php resources/views/realestate/procurements/show.blade.php resources/views/realestate/projects/show.blade.php resources/views/housing/properties/show.blade.php resources/views/housing/custom-orders/show.blade.php tests/Feature/AjaxErrorFeedbackTest.php tests/Feature/Schedule/ScheduleSectionRenderTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 詳細 4 画面に共通の工程表カードを足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 9: 横断ボード 2 画面（`ScheduleBoardService` ＋ ルート 2 本 ＋ サイドバー）

**Files:**
- Create: `app/Services/ScheduleBoardService.php`
- Create: `app/Http/Controllers/RealEstate/ScheduleBoardController.php`
- Create: `app/Http/Controllers/Housing/ScheduleBoardController.php`
- Create: `resources/views/_partials/_schedule_board.blade.php`
- Create: `resources/views/realestate/schedules/index.blade.php`
- Create: `resources/views/housing/schedules/index.blade.php`
- Modify: `routes/web.php`（ボード 2 本。**Task 7 で足した工程 CRUD のブロックの直前**に置く）
- Modify: `resources/views/layouts/partials/sidebar.blade.php`（**4 箇所**）
- Test: `tests/Feature/Schedule/ScheduleBoardTest.php`

### このタスクで確定させる仕様（設計書に無い細部）

| # | 決定 | 理由 |
|---|---|---|
| F | **ズームは「軸の範囲」と「ヘッダの粒度」を一緒に変える**（週 = −1〜+2 ヶ月・週ヘッダ / 月 = −6〜+12 ヶ月・月ヘッダ / 四半期 = −12〜+24 ヶ月・四半期ヘッダ） | 設計書 §4.2 は「月ヘッダの粒度が変わるだけ」と書いているが、§5.5 の既定範囲（18 ヶ月）に週ヘッダを当てると 78 セットになり読めない。§5.5 の「フィルタで変更」を**ズームが兼ねる**形にすると、控えめな UI で 3 つとも実用になる。**既定（月）は §5.5 の −6〜+12 ヶ月ちょうど** |
| G | 案件のステータスは **完了 > 遅延 > 進行中** の優先順で 1 つに決める | 「遅れて終わった案件」を遅延一覧に出すとノイズになる。完了した案件はもう手当てできない |
| H | **KPI は絞り込み後の行から数える** | 設計書 §8.4 が「KPI の数がボード本体の表示と一致すること」を求めている。全件から数えると絞り込み時に食い違う |
| I | ステータスの「すべて」の値は **`all`**（空文字にしない） | 空値 ＋「既定が絞り込み」の組み合わせは `Arr::query()` が null キーを捨てて既定へ戻る Bug #31 そのもの。`all` なら原理的に起きない |

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleBoardTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleBoardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 横断ボード（設計書 §4.2 / §4.3）。
 *
 * ⚠ **部署をまたがせないことを必ず 1 本置く。** 住宅だけの権限の利用者に
 *   不動産の案件が出てはいけない。
 *
 * ⚠ **KPI と本体の両方をアサートする。** 同じ数字が 2 箇所に出るので、片方だけ消しても
 *   部分一致で緑になる（Bug #43 / #46 / #49 で繰り返し踏んでいる）。役割ごとに
 *   `viewData()` で見る。
 *
 * ⚠ **「今日」を固定する。** 30 日以内の KPI と遅延判定が実行日に依存するため。
 */
class ScheduleBoardTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /** テスト中の「今日」。すべての日付をこの日基準で置く。 */
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
        // ⚠ 戻さないと同一プロセスの後続テストへ漏れる
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    // ============================================================
    // 表示と絞り込み
    // ============================================================

    public function test_the_realestate_board_lists_both_realestate_kinds(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $prj = $this->makeParent('project');
        $prj->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk();

        $codes = array_column($response->viewData('board')['rows'], 'code');

        $this->assertContains('PRC-001', $codes);
        $this->assertContains('PRJ-001', $codes);
    }

    public function test_the_housing_board_lists_both_housing_kinds(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $order = $this->makeParent('customOrder');
        $order->scheduleSteps()->create(['name' => '実施設計', 'category' => 'permit', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $codes = array_column(
            $this->actingAs($this->manager())->get('/housing/schedules')->assertOk()->viewData('board')['rows'],
            'code'
        );

        $this->assertContains('HS-001', $codes);
        $this->assertContains('CO-001', $codes);
    }

    /**
     * ⚠ **これが「対象クラスを全部の親に広げる」変異を止めるテスト**（設計書 §4.3）。
     */
    public function test_the_realestate_board_never_shows_housing_cases(): void
    {
        $prop = $this->makeParent('property');
        $prop->scheduleSteps()->create(['name' => '基礎工事', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $response = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk();

        $this->assertSame([], array_column($response->viewData('board')['rows'], 'code'));
        $this->assertStringNotContainsString('HS-001', $response->getContent(), '不動産のボードに住宅の案件が出ている');
    }

    /** 住宅だけの権限しか無い利用者は不動産のボードを開けない（設計書 §4.3） */
    public function test_a_housing_only_user_cannot_open_the_realestate_board(): void
    {
        $this->actingAs($this->staff(['housing']))->get('/realestate/schedules')->assertForbidden();
        $this->actingAs($this->staff(['housing']))->get('/housing/schedules')->assertOk();
    }

    /**
     * ⚠ **工程が 0 件の案件はボードに出さない**（設計書 §4.2）。
     *   出すと、まだ使い始めていない案件で画面が埋まる。件数だけ別に出す。
     */
    public function test_cases_without_steps_are_counted_but_not_listed(): void
    {
        $withSteps = $this->makeParent('procurement');
        $withSteps->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '未登録案件']);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules')->assertOk()->viewData('board');

        $this->assertSame(['PRC-001'], array_column($board['rows'], 'code'));
        $this->assertSame(1, $board['unregisteredCount'], '工程未登録の件数が合わない');
    }

    public function test_the_kind_filter_narrows_to_one_kind(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $prj = $this->makeParent('project');
        $prj->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?kind=project')->assertOk()->viewData('board');

        $this->assertSame(['PRJ-001'], array_column($board['rows'], 'code'));
    }

    public function test_the_keyword_filter_matches_the_case_name_and_the_step_name(): void
    {
        $proc = $this->makeParent('procurement');
        $proc->scheduleSteps()->create(['name' => '確定測量', 'category' => 'survey', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $prj = $this->makeParent('project');
        $prj->scheduleSteps()->create(['name' => '造成', 'category' => 'work', 'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'sort_order' => 1]);

        $byCase = $this->actingAs($this->manager())->get('/realestate/schedules?q=' . urlencode('余戸南'))->viewData('board');
        $this->assertSame(['PRJ-001'], array_column($byCase['rows'], 'code'), '案件名で絞れていない');

        $byStep = $this->actingAs($this->manager())->get('/realestate/schedules?q=' . urlencode('確定測量'))->viewData('board');
        $this->assertSame(['PRC-001'], array_column($byStep['rows'], 'code'), '工程名で絞れていない');
    }

    /**
     * ⚠ **「すべて」の値は空文字ではなく `all`**（決定 I / Bug #31）。
     *   空値だと `Arr::query()` がキーごと捨てて既定（進行中）に戻る。
     */
    public function test_the_all_status_option_is_not_an_empty_value(): void
    {
        $html = $this->actingAs($this->manager())->get('/realestate/schedules')->getContent();

        $this->assertStringContainsString('value="all"', $html, '「すべて」の値が all でない（Bug #31 の形）');
        // ⚠ 空値の option が 1 つでもあると、そのキーが Arr::query() に捨てられて既定へ戻る
        $this->assertStringNotContainsString('<option value=""', $html, '空値の絞り込み option がある（Bug #31 の形）');
    }

    // ============================================================
    // ステータスの判定（決定 G: 完了 > 遅延 > 進行中）
    // ============================================================

    private function caseWithSteps(string $code, array $steps): void
    {
        $owner = $this->makeParent('procurement', ['procurement_code' => $code, 'property_name' => $code]);

        foreach ($steps as $i => $s) {
            $owner->scheduleSteps()->create(array_merge(
                ['name' => "工程{$i}", 'category' => 'work', 'sort_order' => $i + 1],
                $s
            ));
        }
    }

    public function test_status_is_done_when_every_step_has_finished_even_if_it_was_late(): void
    {
        // 予定 8/20 に対し実績 8/25 = 遅れたが完了
        $this->caseWithSteps('PRC-DONE', [
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-08-20', 'actual_start' => '2026-08-01', 'actual_end' => '2026-08-25'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $row   = collect($board['rows'])->firstWhere('code', 'PRC-DONE');

        $this->assertSame('done', $row['status'], '完了は遅延より優先する（決定 G）');
    }

    public function test_status_is_late_when_a_step_is_overdue_and_unfinished(): void
    {
        $this->caseWithSteps('PRC-LATE', [
            ['planned_start' => '2026-07-01', 'planned_end' => '2026-08-20'],   // 未着手のまま予定終了を過ぎた
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $row   = collect($board['rows'])->firstWhere('code', 'PRC-LATE');

        $this->assertSame('late', $row['status']);
        $this->assertSame(11, $row['delayDays'], '8/20 から 8/31 で 11 日');
    }

    public function test_status_is_running_otherwise(): void
    {
        $this->caseWithSteps('PRC-RUN', [
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'actual_start' => '2026-08-01'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');

        $this->assertSame('running', collect($board['rows'])->firstWhere('code', 'PRC-RUN')['status']);
    }

    public function test_the_status_filter_narrows_the_rows(): void
    {
        $this->caseWithSteps('PRC-LATE', [['planned_start' => '2026-07-01', 'planned_end' => '2026-08-20']]);
        $this->caseWithSteps('PRC-RUN',  [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'actual_start' => '2026-08-01']]);

        $late = $this->actingAs($this->manager())->get('/realestate/schedules?status=late')->viewData('board');
        $this->assertSame(['PRC-LATE'], array_column($late['rows'], 'code'));

        $all = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $this->assertCount(2, $all['rows']);
    }

    // ============================================================
    // KPI（決定 H: 絞り込み後の行から数える）
    // ============================================================

    /**
     * ⚠ **KPI と本体を別々にアサートする。** 片方だけ消しても、同じ数字が画面の
     *   もう一方に出ているので部分一致で緑になる（Bug #43 / #46）。
     */
    public function test_the_kpis_agree_with_the_rows_on_screen(): void
    {
        $this->caseWithSteps('PRC-LATE', [['planned_start' => '2026-07-01', 'planned_end' => '2026-08-20']]);
        // ⚠ planned_end を 12/31 にしてある。9/30 にすると「30 日以内に終わる工程」に
        //    入ってしまい、KPI の期待値が PRC-SOON と混ざって何を測っているか読めなくなる。
        $this->caseWithSteps('PRC-RUN',  [['planned_start' => '2026-08-01', 'planned_end' => '2026-12-31', 'actual_start' => '2026-08-01']]);
        // 30 日以内に始まる（9/10）・30 日以内に終わる（9/05）
        $this->caseWithSteps('PRC-SOON', [
            ['planned_start' => '2026-09-10', 'planned_end' => '2026-10-31'],
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-09-05', 'actual_start' => '2026-08-01'],
        ]);
        // ⚠ **この 1 件が countSoon の「すでに始まった工程は数えない」ガードを load-bearing にする。**
        //    予定開始 9/15 は 30 日の窓の中だが、実績開始が入っているので startingSoon には
        //    数えない。この案件が無いと、ガードを消す変異が素通りする（実測して足した）。
        //    予定終了 9/25 は実績終了が空なので endingSoon には数える。
        $this->caseWithSteps('PRC-STARTED', [
            ['planned_start' => '2026-09-15', 'planned_end' => '2026-09-25', 'actual_start' => '2026-09-05'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');

        $this->assertSame(3, $board['kpi']['running'], '進行中の案件数');
        $this->assertSame(1, $board['kpi']['late'], '遅延の案件数');
        $this->assertSame(1, $board['kpi']['startingSoon'], '30 日以内に始まる工程（実績開始済みは数えない）');
        $this->assertSame(2, $board['kpi']['endingSoon'], '30 日以内に終わる工程');

        // 本体の行と突き合わせる（KPI だけ・行だけ、のどちらの変異も止める）
        $byStatus = array_count_values(array_column($board['rows'], 'status'));
        $this->assertSame($board['kpi']['running'], $byStatus['running'] ?? 0);
        $this->assertSame($board['kpi']['late'], $byStatus['late'] ?? 0);
    }

    /** ⚠ 絞り込むと KPI も一緒に動く（決定 H） */
    public function test_the_kpis_follow_the_filter(): void
    {
        $this->caseWithSteps('PRC-LATE', [['planned_start' => '2026-07-01', 'planned_end' => '2026-08-20']]);
        $this->caseWithSteps('PRC-RUN',  [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30', 'actual_start' => '2026-08-01']]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=late')->viewData('board');

        $this->assertSame(1, $board['kpi']['late']);
        $this->assertSame(0, $board['kpi']['running'], '絞り込み後の行から数えていない');
    }

    // ============================================================
    // 軸とズーム（決定 F）
    // ============================================================

    public function test_the_default_axis_is_six_months_back_and_twelve_forward(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');

        $this->assertSame('2026-02-01', $board['axis']['from'], '既定の軸の始まりが設計書 §5.5 と違う');
        $this->assertSame('2027-08-31', $board['axis']['to'], '既定の軸の終わりが設計書 §5.5 と違う');
    }

    public function test_zoom_changes_both_the_range_and_the_header_granularity(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $week = $this->actingAs($this->manager())->get('/realestate/schedules?status=all&zoom=week')->viewData('board');
        $this->assertSame('week', $week['axis']['granularity']);
        $this->assertSame('2026-07-01', $week['axis']['from']);

        $quarter = $this->actingAs($this->manager())->get('/realestate/schedules?status=all&zoom=quarter')->viewData('board');
        $this->assertSame('quarter', $quarter['axis']['granularity']);
        $this->assertSame('2025-08-01', $quarter['axis']['from']);
    }

    /** 不正なズーム値で 500 にしない（既定へ落とす） */
    public function test_an_unknown_zoom_falls_back_to_month(): void
    {
        $this->caseWithSteps('PRC-RUN', [['planned_start' => '2026-08-01', 'planned_end' => '2026-09-30']]);

        $board = $this->actingAs($this->manager())
            ->get('/realestate/schedules?status=all&zoom=' . urlencode('<script>'))
            ->assertOk()->viewData('board');

        $this->assertSame('month', $board['axis']['granularity']);
    }

    // ============================================================
    // 段の振り分け（設計書 §5.3）
    // ============================================================

    public function test_overlapping_steps_are_spread_across_lanes(): void
    {
        $this->caseWithSteps('PRC-OVERLAP', [
            ['planned_start' => '2026-08-01', 'planned_end' => '2026-10-31'],
            ['planned_start' => '2026-08-15', 'planned_end' => '2026-10-31'],
        ]);

        $board = $this->actingAs($this->manager())->get('/realestate/schedules?status=all')->viewData('board');
        $row   = collect($board['rows'])->firstWhere('code', 'PRC-OVERLAP');

        $this->assertSame(2, $row['laneCount'], '重なる工程が同じ段に載っている（読めなくなる）');
        $this->assertSame([0, 1], array_column($row['bars'], 'lane'));
    }

    // ============================================================
    // サービスの契約（設計書 §4.3）
    // ============================================================

    // ============================================================
    // サイドバーの導線
    // ============================================================

    /**
     * ⚠ **`assertSee('工程表')` では原理的に検出できない。**
     *   「工程表」はボードの `<h1>` にも詳細カードの見出しにも出るので、
     *   サイドバーのリンクを 4 箇所とも消しても緑になる（Bug #43 の部分一致と同型）。
     *   **href の出現回数**で見る。
     *
     * ⚠ **ボード自身のページで数えない。** フィルタの `action` と「クリア」リンクが
     *   同じ URL を指すので数が混ざる。**別の画面**（一覧）で数える。
     *
     * ⚠ 期待値 2 は「PC 展開サイドバー」＋「モバイルドロワー」の 2 ブロック
     *   （折りたたみサイドバーは部署ごとにアイコン 1 個なので項目を持たない）。
     *   **どちらか片方の編集を忘れると 1 になって落ちる。** これが 4 箇所編集の担保。
     */
    public function test_both_sidebar_blocks_link_to_each_board(): void
    {
        $executive = $this->actor(\App\Enums\UserRole::Executive);

        foreach ([
            '/realestate/procurements' => '/realestate/schedules',
            '/housing/properties'      => '/housing/schedules',
        ] as $page => $link) {
            $html = $this->actingAs($executive)->get($page)->assertOk()->getContent();

            $this->assertSame(
                2,
                substr_count($html, 'href="' . url($link) . '"'),
                "{$link} へのサイドバー導線が 2 箇所（PC 展開 / モバイルドロワー）ありません。"
                . 'sidebar.blade.php は同じグループが 2 ブロックに出てくるので両方直すこと'
            );
        }
    }

    /**
     * ⚠ **対象クラスを既定値にしない。** 既定を持たせると、新しい部署のボードを足した人が
     *   引数を省略した瞬間に全部署の案件が漏れる。
     */
    public function test_the_service_requires_its_target_kinds_explicitly(): void
    {
        $method = new \ReflectionMethod(ScheduleBoardService::class, 'build');

        $this->assertFalse(
            $method->getParameters()[0]->isDefaultValueAvailable(),
            '対象クラスの引数に既定値があります（引数を省略すると全部署が漏れます。設計書 §4.3）'
        );
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleBoardTest
```

Expected: FAIL。`Class "App\Services\ScheduleBoardService" not found`

- [ ] **Step 3: `ScheduleBoardService` を書く**

`app/Services/ScheduleBoardService.php`:

```php
<?php

namespace App\Services;

use App\Models\ScheduleStep;
use App\Support\GanttScale;
use App\Support\LanePacker;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * 横断ボード 1 枚ぶんを組み立てる（設計書 §4.2）。不動産用・住宅用の 2 つが共有する。
 *
 * ⚠ **対象クラスに既定値を持たせない**（設計書 §4.3）。既定を持たせると、新しい部署の
 *   ボードを足した人が引数を省略した瞬間に**全部署の案件が漏れる**。
 *
 * ⚠ **KPI は絞り込み後の行から数える**（プラン 決定 H）。全件から数えると
 *   絞り込んだときに画面の行数と食い違う（Bug #46）。
 *
 * ⚠ **ページングしない**（設計書 §4.2）。1 部署の案件数が 200 を超えたら見直す。
 */
class ScheduleBoardService
{
    public const STATUS_ALL     = 'all';

    public const STATUS_RUNNING = 'running';

    public const STATUS_LATE    = 'late';

    public const STATUS_DONE    = 'done';

    /** ⚠ 「すべて」は空文字にしない（Arr::query() が null キーを捨てる。Bug #31 / 決定 I） */
    public const STATUSES = [
        self::STATUS_RUNNING => '進行中',
        self::STATUS_ALL     => 'すべて',
        self::STATUS_LATE    => '遅延',
        self::STATUS_DONE    => '完了',
    ];

    /**
     * ズーム（プラン 決定 F）。範囲とヘッダの粒度を一緒に変える。
     * ⚠ 既定 `month` は設計書 §5.5 の「今日の 6 ヶ月前 〜 12 ヶ月後」ちょうど。
     */
    public const ZOOMS = [
        'week'    => ['label' => '週',     'before' => 1,  'after' => 2,  'granularity' => 'week'],
        'month'   => ['label' => '月',     'before' => 6,  'after' => 12, 'granularity' => 'month'],
        'quarter' => ['label' => '四半期', 'before' => 12, 'after' => 24, 'granularity' => 'quarter'],
    ];

    private const DEFAULT_ZOOM = 'month';

    /** 「まもなく」の窓（日） */
    private const SOON_DAYS = 30;

    /**
     * @param  array<string, array{0: class-string, 1: string}>  $kinds  絞り込みキー => [親クラス, 表示名]
     *
     * ⚠ 第 1 引数に既定値を付けないこと（設計書 §4.3。ReflectionMethod でテストが固定している）。
     */
    public function build(array $kinds, Request $request, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $filters = $this->filters($kinds, $request);
        $zoom    = self::ZOOMS[$filters['zoom']];

        $from  = $today->subMonths($zoom['before'])->startOfMonth();
        $to    = $today->addMonths($zoom['after'])->endOfMonth()->startOfDay();
        $scale = new GanttScale($from, $to);

        $rows         = [];
        $keptSteps    = [];   // 絞り込みを通った案件の工程。KPI がこれを数える（Blade へは渡さない）
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

                $row = $this->row($owner, $label, $key, $steps, $scale, $today);

                if (! $this->matches($row, $owner, $steps, $filters)) {
                    continue;
                }

                $rows[]      = $row;
                // ⚠ $rows と添字を揃える。KPI は「絞り込み後」から数える（決定 H）ので、
                //   ここで一緒に積むのが唯一ずれない書き方。
                $keptSteps[] = $steps;
            }
        }

        return [
            'rows'              => $rows,
            'kpi'               => $this->kpi($rows, $keptSteps, $today),
            'unregisteredCount' => $unregistered,
            'filters'           => $filters,
            'kinds'             => $kinds,
            'statuses'          => self::STATUSES,
            'zooms'             => self::ZOOMS,
            'axis'              => [
                'from'        => $scale->from()->toDateString(),
                'to'          => $scale->to()->toDateString(),
                'granularity' => $zoom['granularity'],
                'headers'     => $this->headers($scale, $zoom['granularity']),
                'todayPct'    => $scale->contains($today) ? $scale->left($today) : null,
                'todayLabel'  => $today->format('n/j'),
            ],
        ];
    }

    /**
     * ⚠ **クエリキーに null を入れない**（設計書 §4.2 / Bug #31）。
     *   ズームのリンクを組むときに `Arr::query()` が null のキーを丸ごと捨てるため、
     *   ここで `''` / 既定値へ正規化しておく。
     */
    private function filters(array $kinds, Request $request): array
    {
        $kind = (string) ($request->query('kind') ?? self::STATUS_ALL);
        if ($kind !== self::STATUS_ALL && ! array_key_exists($kind, $kinds)) {
            $kind = self::STATUS_ALL;
        }

        $status = (string) ($request->query('status') ?? self::STATUS_RUNNING);
        if (! array_key_exists($status, self::STATUSES)) {
            $status = self::STATUS_RUNNING;
        }

        $zoom = (string) ($request->query('zoom') ?? self::DEFAULT_ZOOM);
        if (! array_key_exists($zoom, self::ZOOMS)) {
            $zoom = self::DEFAULT_ZOOM;
        }

        return [
            'kind'   => $kind,
            'status' => $status,
            'zoom'   => $zoom,
            'q'      => trim((string) ($request->query('q') ?? '')),
        ];
    }

    /** 1 案件ぶんの行（サマリの色帯 ＋ 展開用の工程明細） */
    private function row(Model $owner, string $kindLabel, string $kindKey, $steps, GanttScale $scale, CarbonImmutable $today): array
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
                'late'     => $step->isLate($today),
                // まだ始まっていない工程は薄く出す（設計書 §4.2）
                'future'   => $step->actual_start === null && $spans[$i]['from']->greaterThan($today),
            ];
        }

        $laneCount = LanePacker::laneCount($lanes);

        return [
            'kind'       => $kindKey,
            'kindLabel'  => $kindLabel,
            'code'       => $owner->scheduleCode(),
            'name'       => $owner->scheduleName(),
            'url'        => $owner->scheduleUrl(),
            'status'     => $this->status($steps, $today),
            'delayDays'  => (int) $steps->max(fn (ScheduleStep $s) => $s->delayDays($today)),
            'laneCount'  => $laneCount,
            'rowHeight'  => LanePacker::rowHeight($laneCount),
            'bars'       => $bars,
            'milestones' => $this->milestones($owner, $scale, $today),
            'steps'      => $steps->map(fn (ScheduleStep $s) => [
                'name'       => $s->name,
                'color'      => $s->category->color(),
                'periodText' => $s->periodText($today),
                'delayDays'  => $s->delayDays($today),
                'progress'   => $s->progress(),
            ])->all(),
        ];
    }

    /**
     * 案件のステータス（プラン 決定 G: **完了 > 遅延 > 進行中**）。
     *
     * ⚠ 「遅れて終わった案件」を遅延一覧に出さない。完了した案件はもう手当てできず、
     *   遅延の一覧をノイズで埋めるだけになる。
     */
    private function status($steps, CarbonImmutable $today): string
    {
        if ($steps->every(fn (ScheduleStep $s) => $s->actual_end !== null)) {
            return self::STATUS_DONE;
        }

        if ($steps->contains(fn (ScheduleStep $s) => $s->isLate($today))) {
            return self::STATUS_LATE;
        }

        return self::STATUS_RUNNING;
    }

    private function matches(array $row, Model $owner, $steps, array $filters): bool
    {
        if ($filters['status'] !== self::STATUS_ALL && $row['status'] !== $filters['status']) {
            return false;
        }

        if ($filters['q'] === '') {
            return true;
        }

        // 案件名・案件コード・工程名のいずれかに含まれること（設計書 §4.2）
        $haystack = $owner->scheduleName() . ' ' . $owner->scheduleCode() . ' '
            . $steps->pluck('name')->implode(' ');

        return mb_stripos($haystack, $filters['q']) !== false;
    }

    /** @param list<array<string, mixed>> $rows 絞り込み**後**の行（決定 H） */
    /**
     * @param  list<array<string, mixed>>  $rows       絞り込み**後**の行（決定 H）
     * @param  list<\Illuminate\Support\Collection>  $keptSteps  同じ添字の案件の工程
     *
     * ⚠ **`$rows` と `$keptSteps` は添字が揃っている前提。** `build()` が同じループで
     *   一緒に積んでいる。片方だけ絞り込むと KPI と画面が食い違う（設計書 §8.4）。
     */
    private function kpi(array $rows, array $keptSteps, CarbonImmutable $today): array
    {
        $limit = $today->addDays(self::SOON_DAYS);

        return [
            'running'      => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_RUNNING)),
            'late'         => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_LATE)),
            'startingSoon' => $this->countSoon($keptSteps, 'start', $today, $limit),
            'endingSoon'   => $this->countSoon($keptSteps, 'end', $today, $limit),
        ];
    }

    /**
     * ⚠ 数えるのは**工程**であって案件ではない（設計書 §4.2 の KPI 3 / 4）。
     *   すでに始まった（終わった）工程は数えない。
     *
     * ⚠ **工程は行の配列を経由させず、Eloquent のまま直接受け取る。**
     *   かつては行に `rawSteps` を紛れ込ませていたが、キーが欠けたときに例外ではなく
     *   **KPI が静かに 0 になる**（Bug #40 の「静かな 0」そのもの）。
     *   Blade へ渡す配列に Eloquent Collection を混ぜない形にも揃う。
     *
     * @param  list<\Illuminate\Support\Collection>  $keptSteps
     */
    private function countSoon(array $keptSteps, string $edge, CarbonImmutable $today, CarbonImmutable $limit): int
    {
        $count = 0;

        foreach ($keptSteps as $steps) {
            foreach ($steps as $step) {
                $actual  = $edge === 'start' ? $step->actual_start : $step->actual_end;
                $planned = $edge === 'start' ? $step->planned_start : $step->planned_end;

                if ($actual !== null || $planned === null) {
                    continue;
                }

                $d = CarbonImmutable::instance($planned)->startOfDay();

                if ($d->greaterThanOrEqualTo($today) && $d->lessThanOrEqualTo($limit)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function milestones(Model $owner, GanttScale $scale, CarbonImmutable $today): array
    {
        return array_map(function (array $m) use ($scale, $today) {
            $date = CarbonImmutable::instance($m['date'])->startOfDay();

            return [
                'label'   => $m['label'],
                'leftPct' => GanttScale::clamp($scale->left($date), 0.0, 100.0),
                'reached' => ScheduleStepStatus::isReached($date, $today),
            ];
        }, $owner->autoMilestones());
    }

    /** @return list<array{label: string, widthPct: float, strong: bool}> */
    private function headers(GanttScale $scale, string $granularity): array
    {
        $headers = [];
        $cursor  = $granularity === 'week'
            ? $scale->from()->startOfWeek()
            : $scale->from()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($scale->to())) {
            [$next, $label, $strong] = match ($granularity) {
                'week'    => [$cursor->addWeek(), $cursor->format('n/j'), $cursor->day <= 7],
                'quarter' => [$cursor->addMonths(3), $cursor->format('Y') . ' Q' . (intdiv($cursor->month - 1, 3) + 1), true],
                default   => [$cursor->addMonth()->startOfMonth(), $cursor->format('n') . '月', in_array($cursor->month, [1, 4, 7, 10], true)],
            };

            // ⚠ 最後のセルが軸をはみ出さないように、区間の終わりで打ち切る
            $end  = $next->greaterThan($scale->to()) ? $scale->to()->addDay() : $next;
            $days = max(1, (int) round($cursor->diffInDays($end)));

            $headers[] = [
                'label'    => $label,
                'widthPct' => $days / $scale->totalDays() * 100,
                'strong'   => $strong,
            ];

            $cursor = $next;
        }

        return $headers;
    }
}
```

⚠ **`$rows` と `$keptSteps` の添字が揃っていることが KPI の前提。** `build()` の同じループで
一緒に積んでいるのはそのため。**片方だけに `continue` を足すような編集をしない**こと
（KPI と画面が食い違い、設計書 §8.4 が禁じている状態になる）。
上のテスト `test_the_kpis_agree_with_the_rows_on_screen` が KPI 4 値と行の内訳を突き合わせて押さえている。

⚠ **KPI 用の工程を行の配列に紛れ込ませない。** 以前の版は `row()` に `rawSteps` を持たせていたが、
キーが欠けたときに例外ではなく **KPI が静かに 0 になる**（Bug #40 の「静かな 0」）。
Blade へ渡す配列に Eloquent Collection を混ぜない形にも揃う。

- [ ] **Step 4: ボードのコントローラを 2 本書く**

`app/Http/Controllers/RealEstate/ScheduleBoardController.php`:

```php
<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Services\ScheduleBoardService;
use Illuminate\Http\Request;

/**
 * 不動産の工程表ボード（設計書 §4.2）。
 *
 * ⚠ **対象クラスはここで明示的に渡す。** サービス側に既定値を置くと、
 *   新しい部署のボードを足した人が引数を省略した瞬間に全部署が漏れる（設計書 §4.3）。
 */
class ScheduleBoardController extends Controller
{
    /** 絞り込みキー => [親クラス, 画面に出す種別名] */
    private const KINDS = [
        'procurement' => [ReProcurement::class, '仕入れ'],
        'project'     => [ReProject::class, '分譲地'],
    ];

    public function index(Request $request, ScheduleBoardService $service)
    {
        return view('realestate.schedules.index', [
            'board' => $service->build(self::KINDS, $request),
        ]);
    }
}
```

`app/Http/Controllers/Housing/ScheduleBoardController.php`:

```php
<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Services\ScheduleBoardService;
use Illuminate\Http\Request;

/**
 * 住宅事業の工程表ボード（設計書 §4.2）。⚠ 不動産側と対称に保つこと。
 */
class ScheduleBoardController extends Controller
{
    /** 絞り込みキー => [親クラス, 画面に出す種別名] */
    private const KINDS = [
        'property'    => [HsProperty::class, '建売'],
        'customOrder' => [HsCustomOrder::class, '注文住宅'],
    ];

    public function index(Request $request, ScheduleBoardService $service)
    {
        return view('housing.schedules.index', [
            'board' => $service->build(self::KINDS, $request),
        ]);
    }
}
```

- [ ] **Step 5: ボードのルートを 2 本足す**

Task 7 で足した「不動産 工程表 — 工程 CRUD（8ルート）」ブロックの**直前**に:

```php
    /*
    |----------------------------------------------------------------------
    | 不動産 工程表 — 横断ボード（1ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('realestate')->middleware('department.access:realestate')->group(function () {
        // 全ロール閲覧可（編集は工程 CRUD 側の role ガードで制御する）
        Route::get('/schedules', [\App\Http\Controllers\RealEstate\ScheduleBoardController::class, 'index'])
            ->name('realestate.schedules.index');
    });

```

「住宅事業 工程表 — 工程 CRUD（8ルート）」ブロックの**直前**に:

```php
    /*
    |----------------------------------------------------------------------
    | 住宅事業 工程表 — 横断ボード（1ルート）
    |----------------------------------------------------------------------
    */
    Route::prefix('housing')->middleware('department.access:housing')->group(function () {
        Route::get('/schedules', [\App\Http\Controllers\Housing\ScheduleBoardController::class, 'index'])
            ->name('housing.schedules.index');
    });

```

- [ ] **Step 6: ボードの partial と 2 つの画面を書く**

`resources/views/_partials/_schedule_board.blade.php`:

```blade
{{--
    工程表ボードの本体（設計書 §4.2）。不動産用・住宅用の 2 画面が共有する唯一の定義。

    必要な変数: $board（App\Services\ScheduleBoardService::build() の戻り値）
                $boardRoute（'realestate.schedules.index' など）
--}}
@php($f = $board['filters'])
@php($axis = $board['axis'])

{{-- KPI 4 枚。
     ⚠ **`grid-2col-sm` を使う**（`grid-stack-sm` ではない）。app.css の注記どおり
        `grid-2col-sm` が「KPI カードなど 4〜6 列のもの」用で、既存の
        `realestate/contracts/index` `dad/projects/show` も 4〜5 列の KPI にこれを当てている。
        `grid-stack-sm` だと 375px で 4 枚が縦 1 列に伸びて既存画面と挙動が食い違う。
     ⚠ トラックは `minmax(0, 1fr)`（素の 1fr は最小値が auto で中身に押し広げられる。Bug #29）。
        既存箇所は `1fr` だが、こちらのほうが安全で見た目は変わらない。 --}}
<div class="grid-2col-sm" style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px;">
    @foreach([
        ['進行中の案件', $board['kpi']['running'], '#047857'],
        ['遅れている案件', $board['kpi']['late'], '#B91C1C'],
        ['30日以内に始まる工程', $board['kpi']['startingSoon'], '#1D4ED8'],
        ['30日以内に終わる工程', $board['kpi']['endingSoon'], '#B45309'],
    ] as [$label, $value, $color])
        <div style="background: white; border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px 14px;">
            <div style="font-size: 11.5px; color: #6B7280; margin-bottom: 4px;">{{ $label }}</div>
            <div style="font-size: 22px; font-weight: 700; color: {{ $color }};">{{ $value }}</div>
        </div>
    @endforeach
</div>

@if($board['unregisteredCount'] > 0)
    <div style="font-size: 12px; color: #6B7280; margin-bottom: 12px;">
        工程が未登録の案件が {{ $board['unregisteredCount'] }} 件あります（ボードには出ません）。
    </div>
@endif

{{-- 絞り込み。
     ⚠ **既存のフィルタバーとまったく同じマークアップにする**（`realestate/procurements/index.blade.php`
        から書き写した）。⚠ **`class="form-input"` を使わない** —— アプリのフィルタバーは
        どこも下のユーティリティ列を直接書いており、`.form-input` は
        `appearance: none; border-radius: 0` を含むのでセレクトから矢印が消える（Bug #18 と同型）。
     ⚠ フォーム側の `flex flex-col sm:flex-row` がモバイルでの縦積みを担っている。
        インラインの `display: flex` で置き換えない。 --}}
<form id="filter-form" method="GET" action="{{ route($boardRoute) }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
    <select name="kind" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="all" @selected($f['kind'] === 'all')>種別: すべて</option>
        @foreach($board['kinds'] as $key => $kind)
            <option value="{{ $key }}" @selected($f['kind'] === $key)>種別: {{ $kind[1] }}</option>
        @endforeach
    </select>

    <select name="status" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        @foreach($board['statuses'] as $value => $label)
            <option value="{{ $value }}" @selected($f['status'] === $value)>ステータス: {{ $label }}</option>
        @endforeach
    </select>

    <select name="zoom" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        @foreach($board['zooms'] as $value => $zoom)
            <option value="{{ $value }}" @selected($f['zoom'] === $value)>表示: {{ $zoom['label'] }}</option>
        @endforeach
    </select>

    <input type="text" name="q" value="{{ $f['q'] }}" placeholder="案件名・工程名で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full sm:w-56">

    <button type="submit" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-600">検索</button>
    <a href="{{ route($boardRoute) }}" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400"
       style="display: inline-flex; align-items: center; justify-content: center;">クリア</a>
</form>

@if($board['rows'] === [])
    <div style="background: white; border: 1px solid #E5E7EB; border-radius: 8px; padding: 28px 16px; text-align: center; color: #9CA3AF; font-size: 13px;">
        該当する案件がありません。
    </div>
@else
    <div style="border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; background: white;">
        <div style="overflow-x: auto;">
            <div style="min-width: 1000px;">

                {{-- ヘッダ --}}
                <div style="display: flex; height: 42px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                    <div style="flex: 0 0 320px; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">案件</div>
                    <div style="flex: 1 1 auto; min-width: 0; position: relative; display: flex;">
                        @foreach($axis['headers'] as $h)
                            <div style="width: {{ $h['widthPct'] }}%; border-right: 1px solid {{ $h['strong'] ? '#D1D5DB' : '#E5E7EB' }}; font-size: 11px; color: #6B7280; display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: hidden;">{{ $h['label'] }}</div>
                        @endforeach
                        @if($axis['todayPct'] !== null)
                            <div style="position: absolute; top: 2px; left: {{ $axis['todayPct'] }}%; transform: translateX(-50%); background: #EF4444; color: white; font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; z-index: 4;">今日 {{ $axis['todayLabel'] }}</div>
                        @endif
                    </div>
                </div>

                {{-- 1 行 1 案件 --}}
                @foreach($board['rows'] as $row)
                    <div x-data="{ open: false }" style="border-bottom: 1px solid #F3F4F6;">
                        <div style="display: flex; height: {{ $row['rowHeight'] }}px;">
                            <div style="flex: 0 0 320px; border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; min-width: 0;">
                                <button type="button" @click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                                        style="border: none; background: none; cursor: pointer; color: #6B7280; font-size: 12px; padding: 0 2px;">▸</button>
                                <span style="font-size: 10px; font-weight: 700; color: #6B7280; background: #F3F4F6; border-radius: 4px; padding: 1px 6px; white-space: nowrap;">{{ $row['kindLabel'] }}</span>
                                <a href="{{ $row['url'] }}" style="color: #111827; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">{{ $row['name'] }}</a>
                                @if($row['delayDays'] > 0)
                                    <span style="margin-left: auto; font-size: 10.5px; color: #DC2626; font-weight: 700; white-space: nowrap;">+{{ $row['delayDays'] }}日</span>
                                @endif
                            </div>
                            <div style="flex: 1 1 auto; min-width: 0; position: relative;">
                                @if($axis['todayPct'] !== null)
                                    <div style="position: absolute; top: 0; bottom: 0; left: {{ $axis['todayPct'] }}%; width: 0; border-left: 2px dashed #EF4444; z-index: 3;"></div>
                                @endif
                                @foreach($row['milestones'] as $ms)
                                    <div style="position: absolute; top: 2px; left: {{ $ms['leftPct'] }}%; z-index: 2;">
                                        <span style="display: block; width: 9px; height: 9px; border-radius: 2px; transform: rotate(45deg); {{ $ms['reached'] ? 'background: #111827;' : 'background: white; border: 2px solid #111827;' }}"></span>
                                    </div>
                                @endforeach
                                @foreach($row['bars'] as $bar)
                                    <div title="{{ $bar['name'] }}"
                                         style="position: absolute; height: 13px; border-radius: 3px; box-sizing: border-box; top: {{ $bar['topPx'] }}px; left: {{ $bar['leftPct'] }}%; width: {{ $bar['widthPct'] }}%; background: {{ $bar['color'] }}; {{ $bar['future'] ? 'opacity: 0.45;' : '' }} {{ $bar['late'] ? 'border: 2px solid #DC2626;' : '' }}"></div>
                                @endforeach
                            </div>
                        </div>

                        {{-- 展開: その案件の工程明細。⚠ x-show と :style を同じタグに置かない（Bug #32） --}}
                        <div x-show="open" x-cloak>
                            <div style="background: #FCFCFD; border-top: 1px solid #F3F4F6; padding: 8px 12px 10px 40px;">
                                @foreach($row['steps'] as $step)
                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: #374151; padding: 2px 0;">
                                        <span style="display: inline-block; width: 18px; height: 8px; border-radius: 3px; background: {{ $step['color'] }};"></span>
                                        <span style="min-width: 180px;">{{ $step['name'] }}</span>
                                        <span style="color: #9CA3AF;">{{ $step['periodText'] }}</span>
                                        @if($step['delayDays'] > 0)
                                            <span style="color: #DC2626; font-weight: 700;">+{{ $step['delayDays'] }}日</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>
@endif
```

`resources/views/realestate/schedules/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', '工程表')

@section('breadcrumb')
    <span>不動産管理</span>
    <span>/</span>
    <span>工程表</span>
@endsection

@section('content')
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h1 class="text-base font-bold text-gray-900">工程表</h1>
    </div>

    @include('_partials._schedule_board', ['board' => $board, 'boardRoute' => 'realestate.schedules.index'])
@endsection
```

`resources/views/housing/schedules/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', '工程表')

@section('breadcrumb')
    <span>住宅事業</span>
    <span>/</span>
    <span>工程表</span>
@endsection

@section('content')
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h1 class="text-base font-bold text-gray-900">工程表</h1>
    </div>

    @include('_partials._schedule_board', ['board' => $board, 'boardRoute' => 'housing.schedules.index'])
@endsection
```

⚠ `@section('breadcrumb')` の中身は既存画面（`realestate/procurements/index.blade.php` 等）の
書き方に合わせること。上はひな型なので、**実ファイルを 1 本開いて同じマークアップに揃える**。

- [ ] **Step 7: サイドバーに「工程表」を足す（4 箇所）**

`resources/views/layouts/partials/sidebar.blade.php` は**同じグループが 2 ブロックに出てくる**
（PC 展開サイドバーとモバイルドロワー。実測で `label="不動産管理"` が 2 箇所・`label="住宅事業"` が 2 箇所）。
**4 箇所すべてに足す。** 折りたたみサイドバーは部署ごとにアイコン 1 個なので変更不要。

不動産（2 箇所とも）: 「分譲地」の行の**直後**に

```blade
            <x-sidebar-item :href="url('/realestate/schedules')" label="工程表" :active="request()->is('realestate/schedules*')" />
```

住宅事業（2 箇所とも）: 「注文住宅」の行の**直後**に

```blade
            <x-sidebar-item :href="url('/housing/schedules')" label="工程表" :active="request()->is('housing/schedules*')" />
```

- [ ] **Step 8: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleBoardTest
```

Expected: `OK (20 tests, ...)`

- [ ] **Step 9: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1146 tests, ...)`

- [ ] **Step 10: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Services/ScheduleBoardService.php app/Http/Controllers/RealEstate/ScheduleBoardController.php app/Http/Controllers/Housing/ScheduleBoardController.php resources/views/_partials/_schedule_board.blade.php resources/views/realestate/schedules/ resources/views/housing/schedules/ routes/web.php resources/views/layouts/partials/sidebar.blade.php tests/Feature/Schedule/ScheduleBoardTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 部署ごとの工程表ボードを 2 画面足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 10: 親を削除したら工程も消す（4 親）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php` / `ProjectController.php`
- Modify: `app/Http/Controllers/Housing/PropertyController.php` / `CustomOrderController.php`
- Test: `tests/Feature/Schedule/ScheduleParentDeletionTest.php`

⚠ **`DeletionBlockers` に工程を足さないこと**（設計書 §3.5）。足すと
「工程を 1 行でも書いた案件は二度と消せない」という別の不具合になる。

⚠ **4 親すべてに入れる。** 1 つ忘れるとそこだけ孤児レコードが溜まり続け、
`schedulable_id` は再利用されるので**将来同じ id の別案件に他人の工程が生える**。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Schedule/ScheduleParentDeletionTest.php`:

```php
<?php

namespace Tests\Feature\Schedule;

use App\Enums\UserRole;
use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 親を消したら工程も消える（設計書 §3.5）。**4 親すべてで対称に固定する。**
 *
 * ⚠ 工程は削除をブロックしない。工程は親に完全従属する記録で、単体で参照する価値が無い。
 */
class ScheduleParentDeletionTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_deleting_any_parent_removes_its_steps(): void
    {
        foreach (self::PARENTS as $label => [$class, $prefix, $_dept]) {
            $owner = $this->makeParent($label);
            $owner->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'sort_order' => 1]);

            $this->assertSame(1, ScheduleStep::where('schedulable_type', $class)->count());

            $this->actingAs($this->actor(UserRole::Executive))
                ->delete(route("{$prefix}.destroy", $owner))
                ->assertRedirect();

            $this->assertSame(
                0,
                ScheduleStep::where('schedulable_type', $class)->count(),
                "{$label}: 親を消したのに工程が残っている（孤児レコードになり、将来同じ id の別案件に生える）"
            );
        }
    }

    /**
     * ⚠ **他の親の工程を巻き添えにしないこと。**
     *   `ScheduleStep::where('schedulable_id', $id)->delete()` のように型を見ない実装だと、
     *   別テーブルの同じ id の工程まで消える。
     */
    public function test_deleting_a_parent_leaves_the_steps_of_a_same_id_parent_alone(): void
    {
        $procurement = $this->makeParent('procurement');
        $property    = $this->makeParent('property');

        $this->assertSame($procurement->getKey(), $property->getKey(), '前提: 同じ id の別テーブル行');

        $procurement->scheduleSteps()->create(['name' => '仕入れの工程', 'category' => 'work', 'sort_order' => 1]);
        $property->scheduleSteps()->create(['name' => '建売の工程', 'category' => 'work', 'sort_order' => 1]);

        $this->actingAs($this->actor(UserRole::Executive))
            ->delete(route('realestate.procurements.destroy', $procurement))
            ->assertRedirect();

        $this->assertSame(0, $procurement->scheduleSteps()->count());
        $this->assertSame(1, $property->scheduleSteps()->count(), '別テーブルの同じ id の工程まで消えた');
    }

    /**
     * ⚠ **工程は削除をブロックしない**（設計書 §3.5）。
     *   DeletionBlockers に工程を足すと「工程を書いた案件は二度と消せない」になる。
     */
    public function test_having_steps_does_not_block_deletion(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'sort_order' => 1]);

        $this->actingAs($this->actor(UserRole::Executive))
            ->delete(route('realestate.procurements.destroy', $owner))
            ->assertRedirect(route('realestate.procurements.index'));

        $this->assertNull($owner->fresh(), '工程があるせいで削除がブロックされている');
    }
}
```

- [ ] **Step 2: 失敗することを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleParentDeletionTest
```

Expected: FAIL。`親を消したのに工程が残っている`

- [ ] **Step 3: 4 つの `destroy()` に 1 行ずつ足す**

各コントローラの `destroy()` で、**`$model->delete();` の直前**に:

```php
        // 工程は親に完全従属するので一緒に消す（設計書 §3.5）。
        // ⚠ DeletionBlockers には足さない（足すと工程を書いた案件が二度と消せなくなる）。
        // ⚠ morphMany 経由で消すこと。schedulable_id だけで消すと、別テーブルの
        //   同じ id の工程まで巻き添えになる。
        $procurement->scheduleSteps()->delete();
```

| ファイル | 変数名 | 置く位置 |
|---|---|---|
| `RealEstate/ProcurementController::destroy()` | `$procurement` | `$procurement->delete();` の直前（`deletionBlockers()` のガードより**後**） |
| `RealEstate/ProjectController::destroy()` | `$project` | `$project->delete();` の直前（同上） |
| `Housing/PropertyController::destroy()` | `$property` | `$property->delete();` の直前 |
| `Housing/CustomOrderController::destroy()` | `$customOrder` | `$customOrder->delete();` の直前（`releaseLot()` の後でよい） |

⚠ **ガードより後に置くこと。** 前に置くと、削除がブロックされたのに工程だけ消える
（Bug: 分譲地の図面削除で同型の順序問題があり、`ProjectController` には既に
「ブロックされたのにファイルだけ消える事故を防ぐ」というコメントが入っている）。

- [ ] **Step 4: テストが通ることを確認する**

```bash
./vendor/bin/phpunit --filter ScheduleParentDeletionTest
```

Expected: `OK (3 tests, ...)`

- [ ] **Step 5: 全体が壊れていないことを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && ./vendor/bin/phpunit
```

Expected: `OK (1149 tests, ...)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add app/Http/Controllers/RealEstate/ProcurementController.php app/Http/Controllers/RealEstate/ProjectController.php app/Http/Controllers/Housing/PropertyController.php app/Http/Controllers/Housing/CustomOrderController.php tests/Feature/Schedule/ScheduleParentDeletionTest.php
git commit -m "$(cat <<'MSG'
feat(schedule): 親を削除したときに工程も消す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 11: 変異テスト（テストが本当に守っているかを測る）

**Files:** なし（測るだけ。変異は必ず元に戻す）

⚠ **「テストが緑」は検証にならない。** 変異を入れて赤になることを実測する。
このプロジェクトでは、これを省いたときに**毎回**穴が見つかっている（Bug #42 / #44 / #45 / #54 / #55）。

### 作法（Bug #44 / #54 で確定済み。1 つでも飛ばすと測定が無効になる）

1. **先にコミットする。** 未コミットのまま変異を当てて `git checkout --` すると**自分の編集ごと巻き戻る**
2. 各変異の**前**に `git status --porcelain` が**空**であることを確認（前の変異の残骸で測定が汚れる）
3. 変異を当てたら `git diff --stat` が**非空**であることを確認（**0 箇所置換を「検出しない」と誤読しない**）
4. テストを走らせ、**赤/緑ではなく「落ちた理由の文言」**まで突き合わせる
5. `git checkout -- <当該ファイル>` で戻す
6. 変異は**検査対象に入るはずの場所**へ当てる（除外リストに載っている場所へ当てて「検出しない」と誤読しない）

- [ ] **Step 1: 作業ツリーが clean であることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && git status --porcelain
```

Expected: 出力なし

- [ ] **Step 2: 16 通りの変異を 1 つずつ測る**

各変異について、下のひな型で回す（`<file>` `<from>` `<to>` を表から埋める）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git status --porcelain            # 空であること
perl -0pi -e 's/\Q<from>\E/<to>/' <file>
git diff --stat                   # 非空であること（空なら置換が当たっていない＝無効な測定）
./vendor/bin/phpunit 2>&1 | tail -25
git checkout -- <file>
```

⚠ **BSD sed は GNU 構文が使えない**（`sed -i ''` が必要）。上のように `perl -0pi -e` を使うほうが安全。

| # | 変異 | ファイル | 期待する赤（**この文言まで確かめる**） |
|---|---|---|---|
| 1 | `width()` の `+ 1` を消す（`(self::days($s, $e) + 1)` → `(self::days($s, $e))`） | `app/Support/GanttScale.php` | `GanttScaleTest::test_a_single_day_step_has_a_non_zero_width` — `1 日だけの工程は幅 0 になってはいけない` |
| 2 | `startOfDay()` を外す（`CarbonImmutable::instance($to)->startOfDay()` → `CarbonImmutable::instance($to)`） | `app/Support/GanttScale.php` | `GanttScaleTest::test_time_components_are_normalised_to_the_start_of_the_day` — `endOfMonth の時刻成分で 1 日ずれている` |
| 3 | 遅延判定を `>=` にする（`lessThanOrEqualTo($due)` → `lessThan($due)`） | `app/Support/ScheduleStepStatus.php` | `ScheduleStepStatusTest::test_finishing_exactly_on_the_planned_end_is_not_late` — `予定終了ちょうどに終わったのを遅延にしないこと` |
| 4 | 未着手の遅延を数えない（`$actualEnd ?? $today` → `$actualEnd ?? $due`） | `app/Support/ScheduleStepStatus.php` | `ScheduleStepStatusTest::test_never_started_past_the_planned_end_is_late` — `未着手のまま予定終了を過ぎたら遅延` |
| 5 | 段振り分けを 1 段固定にする（`if ($spans[$i]['from'] > $end)` → `if (true)`） | `app/Support/LanePacker.php` | `LanePackerTest::test_fully_overlapping_steps_each_get_their_own_lane` ＋ `ScheduleBoardTest::test_overlapping_steps_are_spread_across_lanes` — `重なる工程が同じ段に載っている` |
| 6 | 段の判定を「以降」にする（`> $end` → `>= $end`） | `app/Support/LanePacker.php` | `LanePackerTest::test_a_step_starting_on_the_day_the_previous_one_ends_goes_to_a_new_lane` — `同日終了・同日開始は別の段` |
| 7 | 実績優先をやめる（`return $this->actual_start ?? $this->planned_start;` → `return $this->planned_start ?? $this->actual_start;`） | `app/Models/ScheduleStep.php` | `ScheduleSectionRenderTest` / `ScheduleBoardTest` の期間・棒に関するアサート（**落ちたテスト名を記録する**） |
| 8 | 所有権の型比較を消す（`&& $step->schedulable_type === $owner::class` を削除） | `app/Http/Controllers/ScheduleStepController.php` | `ScheduleStepAuthorizationTest::test_a_step_belonging_to_a_same_id_parent_in_another_department_is_not_found` — `他部署の工程が書き換えられた` |
| 9 | 完成 ◆ を 2 つ描く（`HsProperty::autoMilestones()` を予定・実績の 2 件返す形へ） | `app/Models/HsProperty.php` | `ScheduleAutoMilestoneTest::test_property_completion_is_a_single_milestone_even_when_both_dates_exist` — `完成の ◆ を 2 つ描かないこと` |
| 10 | ルートを 1 本消す（`housing.custom-orders.schedule-steps.reorder` の 2 行をコメント化） | `routes/web.php` | `ScheduleRouteWiringTest::test_all_sixteen_routes_are_defined_symmetrically` — `工程ルートが対称に定義されていません` <br>⚠ **構文エラーで赤くなっていないことを確かめる**（Bug #53 で実際に誤読した）。`php -l routes/web.php` が通ることを先に見る |
| 11 | `reorder` を `{step}` の**後ろ**へ移す（不動産・仕入れ案件の 2 行を入れ替える） | `routes/web.php` | `ScheduleRouteWiringTest::test_reorder_wins_over_the_step_parameter` — `/schedule-steps/reorder が {step} に食われている` |
| 12 | ボードの対象を全親に広げる（`RealEstate\ScheduleBoardController::KINDS` に `HsProperty::class` を足す） | `app/Http/Controllers/RealEstate/ScheduleBoardController.php` | `ScheduleBoardTest::test_the_realestate_board_never_shows_housing_cases` — `不動産のボードに住宅の案件が出ている` |
| 13 | 共通 partial の `@include` をインライン複製に置換（`realestate/procurements/show.blade.php` の 1 行を `_schedule_section` の中身のコピーへ） | `resources/views/realestate/procurements/show.blade.php` | `ScheduleSectionRenderTest::test_all_four_detail_views_include_the_one_shared_partial` ＋ `test_the_gantt_markup_lives_only_in_the_partial` — `ガントのマークアップが partial 以外にもあります` |
| 14 | `countSoon()` の「すでに始まった工程は数えない」ガードを消す（`if ($actual !== null \|\| $planned === null)` → `if ($planned === null)`） | `app/Services/ScheduleBoardService.php` | `ScheduleBoardTest::test_the_kpis_agree_with_the_rows_on_screen` — `30 日以内に始まる工程（実績開始済みは数えない）` が 1 でなく 2 |
| 15 | KPI を絞り込み前から数える（`kpi()` の `count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_RUNNING))` → `count($rows)`） | `app/Services/ScheduleBoardService.php` | `ScheduleBoardTest::test_the_kpis_follow_the_filter` — `絞り込み後の行から数えていない` |
| 16 | サイドバーのモバイルドロワー側だけ「工程表」を消す（`sidebar.blade.php` の 2 つ目の `label="住宅事業"` ブロックの行を削除） | `resources/views/layouts/partials/sidebar.blade.php` | `ScheduleBoardTest::test_both_sidebar_blocks_link_to_each_board` — `サイドバー導線が 2 箇所（PC 展開 / モバイルドロワー）ありません` |

- [ ] **Step 3: 結果を表に書き起こす**

このプラン末尾の「変異テストの実測結果」の表を埋める。**検出しなかった変異があれば、
テストを足してから赤になることを確認し、その事実も残す**（Bug #55 で 1 件そうなった）。

⚠ **「検出しない」と書く前に、必ず Step 2 の手順 3（`git diff --stat` が非空）を見返すこと。**
0 箇所置換を「検出しない」と誤読する事故が過去に 2 回起きている。

- [ ] **Step 4: 作業ツリーが clean に戻っていることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && git status --porcelain && ./vendor/bin/phpunit | tail -3
```

Expected: `git status` は出力なし、テストは `OK (1149 tests, ...)`

- [ ] **Step 5: 結果をコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add docs/superpowers/plans/2026-09-01-realestate-housing-schedule-gantt.md
git commit -m "$(cat <<'MSG'
docs(plan): 工程表の変異 16 通りの実測結果を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---


## Task 12: ローカル検証（コンパイル ＋ 実ブラウザ）

**Files:** なし（測るだけ）

⚠ **テストでは押さえられないことがある**（設計書 §10）。ここを飛ばすと本番でだけ壊れる。

### 12-1. コンパイル済みビューを lint する（Bug #21 / #26 / #30）

⚠ **`view:cache` の「成功」表示では不十分。** コンパイル済み PHP を lint しないので、
`ParseError` を吐くビューでも `Blade templates cached successfully.` と出る。
本番は `view:cache` 済みで動くため、**この lint を通していないと「本番だけ 500」になる**。

⚠ worktree には `.env` が無いが、`APP_KEY` を環境変数で渡せば artisan はブートする（実測済み）。

- [ ] **Step 1: 全ビューをコンパイルして lint する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan view:cache
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && for f in storage/framework/views/*.php; do php -l "$f" > /dev/null || echo "INVALID: $f"; done; echo "lint done"
```

Expected: `INVALID:` の行が **1 件も出ない**（`lint done` だけ）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan view:clear
```

⚠ **`view:clear` を必ず実行する。** 消し忘れるとローカルの開発サーバがキャッシュを見続ける。

- [ ] **Step 2: `<script>` 内にディレクティブ名やコンポーネントタグを素で書いていないか見る（Bug #30）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && grep -rnE '^[[:space:]]*//.*@(json|if|foreach|php|include|section|yield|stack|push)' resources/views/_partials/ | grep -v '@@'; echo "grep done"
```

Expected: 何も出ない（`grep done` だけ）

⚠ **grep は目星をつけるだけ。** 判定は Step 1 の `php -l` で確定させる。

### 12-2. 実ブラウザで見る

⚠ **モックでの確認は無効**（Bug #29）。本番と同じ `<main class="flex-1 overflow-y-auto">` の
階層の中で測らないと、グリッド／flex の膨張は再現しない。

- [ ] **Step 3: 使い捨ての SQLite にデータを入れて開発サーバを立てる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && touch storage/schedule-demo.sqlite && echo created
```

⚠ **main repo では絶対にやらない。** main repo の `.env` は実 MySQL を指しており、
`config:cache` 後は env の上書きが効かないので `migrate` が実 DB を壊しうる（memory に記録あり）。
**worktree の `.env` は存在しない**ので、下のように環境変数だけで完結させる。

デモデータ投入用のスクリプトを `storage/schedule-demo.php` に置く:

```php
<?php
// ⚠ 使い捨ての検証用。コミットしないこと（storage/ は .gitignore 済み）。
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$parents = [
    [ReProcurement::class, ['procurement_code' => 'PRC-2026-001', 'property_type' => 'used_house', 'transaction_type' => 'purchase', 'status' => 'contracted', 'property_name' => '井門町 更地', 'address' => '愛媛県松山市1-1-1', 'contract_date' => '2026-01-23', 'settlement_date' => '2026-05-29', 'created_by' => 1]],
    [ReProject::class,     ['project_code' => 'PRJ-2026-004', 'project_name' => '余戸南 分譲地', 'status' => 'selling', 'address' => '愛媛県松山市2-2-2', 'contract_date' => '2026-02-10', 'settlement_date' => '2026-06-30', 'created_by' => 1]],
    [HsProperty::class,    ['property_code' => 'HS-2026-014', 'property_name' => '余戸南 3号地', 'status' => 'construction', 'address' => '愛媛県松山市3-3-3', 'scheduled_completion_date' => '2026-12-11', 'created_by' => 1]],
    [HsCustomOrder::class, ['order_code' => 'CO-2026-007', 'order_name' => '松山市 T様邸', 'status' => 'construction', 'customer_name' => 'T様', 'address' => '愛媛県松山市4-4-4', 'contract_date' => '2026-04-18', 'scheduled_completion_date' => '2026-11-20', 'created_by' => 1]],
];

// 重なる工程を入れて段の振り分けを目で見る（設計書 §5.3）
$steps = [
    ['name' => '建築確認申請', 'category' => 'permit', 'planned_start' => '2026-05-11', 'planned_end' => '2026-06-05', 'actual_start' => '2026-05-11', 'actual_end' => '2026-06-12', 'sort_order' => 1],
    ['name' => '地盤改良',     'category' => 'work',   'planned_start' => '2026-06-15', 'planned_end' => '2026-06-26', 'actual_start' => '2026-06-15', 'actual_end' => '2026-06-24', 'sort_order' => 2],
    ['name' => '木工事',       'category' => 'work',   'planned_start' => '2026-07-27', 'planned_end' => '2026-09-25', 'actual_start' => '2026-08-03', 'sort_order' => 3],
    ['name' => '販売',         'category' => 'sale',   'planned_start' => '2026-08-01', 'planned_end' => '2027-03-31', 'sort_order' => 4],
    ['name' => '確定測量',     'category' => 'survey', 'planned_start' => '2026-08-20', 'sort_order' => 5],   // マイルストーン（◆）
    ['name' => '未定の工程',   'category' => 'other',  'sort_order' => 6],                                    // 日付未設定
];

foreach ($parents as [$class, $attrs]) {
    $owner = $class::create($attrs);
    foreach ($steps as $s) {
        $owner->scheduleSteps()->create($s);
    }
}

User::create([
    'name' => '検証用', 'email' => 'demo@example.test',
    'password' => Hash::make('password'), 'role' => 'executive', 'must_change_password' => false,
]);

echo "seeded\n";
```

⚠ **経営層は `department.access` を素通りする**ので、部署の紐付けを作らなくても 6 画面すべて開ける。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=storage/schedule-demo.sqlite php artisan migrate --force
```

⚠ `migrate` は `re_*` / `hs_*` / `schedule_steps` を作らない（**raw SQL 管理**）ので、
デモ用スクリプトの**先頭でテスト用 trait を呼んで**作る。`storage/schedule-demo.php` の
`use` 文の直後に次を置くこと:

```php
// re_* / hs_* / schedule_steps は raw SQL 管理で migration に無いので、テスト用 trait で作る。
// ⚠ Tests\ の autoload は composer の autoload-dev。worktree で composer install 済みなら通る。
(new class {
    use \Tests\Concerns\CreatesRealEstateSchema;

    public function build(): void { $this->createRealEstateSchema(); }
})->build();
```

スキーマ作成とデータ投入を 1 回で流す:

```bash
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=storage/schedule-demo.sqlite php artisan tinker --execute="require 'storage/schedule-demo.php';"
```

Expected: `seeded`

⚠ `Class "Tests\Concerns\CreatesRealEstateSchema" not found` が出たら、この検証用途に限り
worktree で `composer dump-autoload` を実行する。それでも通らなければこの Step は飛ばし、
**Task 12-1 の lint と `--filter ScheduleSectionRenderTest` で代用してよい**。
ただし **12-2 の Step 5〜8（Ajax の再描画・`main` の幅・375px・コンソール）は飛ばさない**
—— そこはテストが原理的に測れない領域で、飛ばすと検証の穴がそのまま残る。

CSS が要るので、main repo の Vite で worktree をビルドする（worktree に `node_modules` は無い）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && /Users/masanori/site/manage/node_modules/.bin/vite build
```

開発サーバを立てる。

⚠ **`artisan serve` は終わらないプロセス。必ずバックグラウンドで起動すること。**
前面で起動すると、そのままステップが止まって以降の確認へ進めない
（エージェントが実行する場合は `run_in_background: true`、人が実行する場合は別ターミナル）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=storage/schedule-demo.sqlite php artisan serve --port=8000
```

起動を確認してから次へ進む:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/login
```

Expected: `200`

- [ ] **Step 4: 6 画面を目で見る**

`http://127.0.0.1:8000/login` で `demo@example.test` / `password` としてログインし、次を確認する。

| # | 画面 | 見るもの |
|---|---|---|
| 1 | `/realestate/procurements/1` | 工程表カードが出る。棒の位置が月グリッドと**視覚的に**合っている。◆（契約 / 決済）が出る。「未定の工程」が一覧に残り期間欄が「日付未設定」 |
| 2 | `/realestate/projects/1` | 同上 |
| 3 | `/housing/properties/1` | 完成の ◆ が **1 つだけ** |
| 4 | `/housing/custom-orders/1` | 契約・完成・引渡しの ◆ |
| 5 | `/realestate/schedules` | KPI 4 枚・行 2 本・重なる工程が段に分かれている・▸ で展開できる |
| 6 | `/housing/schedules` | 同上（建売・注文住宅の 2 行） |

⚠ **4 画面すべてを開くこと**（設計書 §10-6）。共通 partial なので 1 画面で足りると思いがちだが、
`@include` の位置と親の `autoMilestones()` は画面ごとに違う。

- [ ] **Step 5: Ajax の保存でガントが描き直されることを確認する（設計書 §10-3）**

`/realestate/procurements/1` で「＋ 工程を追加」を押し、追加された行の**予定開始・予定終了を入れる**。
**ページを再読み込みせずに**上のガントに棒が増えることを見る。
次に ↑↓ で並べ替え、ガントの行の順序も変わることを見る。

⚠ **これはテストでは押さえられない**（PHP のテストは JS を実行しない）。

- [ ] **Step 6: 横スクロールが `<main>` を押し広げていないことを DOM で測る（Bug #29）**

⚠ **スクリーンショットでは判定できない。** 超過幅はウィンドウ幅によらず一定なので、
**広い幅と狭い幅の両方**で測る。ブラウザのコンソールで:

```js
(() => { const m = document.querySelector('main'); return { w: m.scrollWidth, c: m.clientWidth, ok: m.scrollWidth === m.clientWidth }; })()
```

Expected: 6 画面すべてで `ok: true`。**幅 1800px と 1200px の両方**で測る。

⚠ ガント自身は `overflow-x: auto` の中で横スクロールしてよい。見るのは **`<main>` が伸びていないこと**。

- [ ] **Step 7: 375px 幅で崩れないことを見る**

デベロッパーツールで 375px にして、6 画面で `<main>` に横スクロールが出ないことを Step 6 と同じ式で測る。

⚠ **モバイル崩れは 2 類型あり、`main` の横スクロール計測だけでは半分見逃す**（memory に記録あり）。
`overflow: hidden` で無音に切り落とされていないかも目で見る（KPI の数字・案件名が欠けていないか）。

- [ ] **Step 8: コンソールにエラーが 0 件であることを確認する**

⚠ **6 画面すべてで見る。** `_schedule_section` の JS は PHP のテストが一度も実行していない
（設計書 §10-2 と同じ穴）。

- [ ] **Step 9: 後片付け**

⚠ **バックグラウンドで起動した `artisan serve` を止めること**（ポート 8000 を掴んだままになる）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule && rm -f storage/schedule-demo.sqlite storage/schedule-demo.php && git status --porcelain && echo "clean"
```

Expected: `clean` だけ（`storage/` と `public/build` は gitignore 済み）

---

## Task 13: ドキュメント更新 ＋ 本番反映

**Files:**
- Modify: `docs/BACKLOG.md`
- Modify: `docs/ARCHITECTURE.md`

- [ ] **Step 1: `docs/BACKLOG.md` に節を足す**

「バックログ完了状況」の見出しの**直前**に挿入（数字は実測値で置き換える）:

```markdown
## ✅ 工程表（ガント表示）— 不動産 / 住宅事業

詳細仕様: @docs/superpowers/specs/2026-08-31-realestate-schedule-gantt-design.md
実装計画: @docs/superpowers/plans/2026-09-01-realestate-housing-schedule-gantt.md
モック: @docs/mockups/realestate/schedule-gantt-proposals.html / @docs/mockups/realestate/schedule-board.html

契約や着工のあとに走る工程（造成・開発許可・確定測量・建築確認・上棟・販売など）を
Excel の工程表のように横棒で見る機能。**JS ライブラリ・外部 CDN は 1 本も足していない**
（日付 → 位置(%) は PHP の `GanttScale` が出し、Blade が inline style で置く）。

| 区分 | 実装内容 |
|------|---------|
| DB | `schedule_steps` **1 本**（ポリモーフィック。`re_` / `hs_` の接頭辞は付けない）|
| 親 | `ReProcurement` / `ReProject` / `HsProperty` / `HsCustomOrder` の **4 種**（建売契約は対象外＝工期は物件に属する）|
| Enum | `ScheduleStepCategory`（5 分類。**色分け以外の意味を持たない**）|
| Support | `GanttScale`（日付→%）/ `ScheduleStepStatus`（遅延・進捗・◆ の塗り分け）/ `LanePacker`（段の振り分け）|
| Model | `ScheduleStep` ＋ `Concerns\HasScheduleSteps`（親が実装するのは 4 メソッドだけ）|
| Service | `ScheduleCardService`（詳細カード）/ `ScheduleBoardService`（横断ボード）|
| Controller | `ScheduleStepController`（4 親共通の CRUD）＋ `RealEstate\ScheduleBoardController` / `Housing\ScheduleBoardController` |
| Blade | `_partials/_schedule_section` / `_schedule_gantt` / `_schedule_board` ＋ ボード 2 画面 |
| ルート | **18 本**（工程 CRUD 4 親 × 4 ＋ ボード 2）|
| テスト | 1050 → **N tests / M assertions green** |

### 要点

- **画面の棒は 1 本だけ**（実績があれば実績、無ければ予定）。DB には予定・実績の 4 日付が入る。
  遅れは横断ボードのバッジと KPI で見る
- **工程名は案件ごとに自由入力**（マスタ無し）。並べ替えは **↑↓ ボタン**（ドラッグではない）
- **既存の日付列から ◆ を自動で描く**（工程行として作らない）。
  ⚠ **完成は 1 つだけ** —— `scheduled_completion_date` と `actual_completion_date` は同じ節目
- **詳細カードの partial は 1 本**を 4 画面が `@include` する（`resources/views/_partials/`。
  部署ディレクトリに置かない）
- **保存後のガントはサーバで描き直して返す**（`gantt_html`）。位置(%) の計算を JS 側に
  持たせないため（Bug #41）。日付を動かすと軸の範囲ごと変わるので部分的な再計算では足りない
- **ボードは部署ごとに 2 つ**で、対象クラスは各コントローラが**明示的に**渡す
  （サービス側に既定値を置くと、新しい部署のボードを足した人が引数を省略した瞬間に全部署が漏れる）
- **工程が 0 件の案件はボードに出さない**（件数だけ KPI の下に出す）
- **ページングしない**（絞り込み後の全件）。1 部署 200 件を超えたら見直す

### やらないこと（設計書 §9）

工程間の依存関係 / ドラッグで期間を変える / 進捗% / 通知メール / **担当者フィルタ**
（4 親のどれにも担当者カラムが無い）/ 建売契約への工程 / DAD・賃貸マンション・ZEAL への展開 /
部署をまたぐ「全部入り」ボード / Excel 出力 / 工程テンプレート

### ⚠ テストで測れないこと（デプロイ後の目視が最終検証）

1. 月グリッドと棒の位置が**視覚的に**合っているか
2. Ajax 保存後にガントが描き直されるか（PHP のテストは JS を実行しない）
3. ガントの横スクロールが `<main>` を押し広げていないか（**広い幅と狭い幅の両方**で測る。Bug #29）
4. 375px で 6 画面が崩れないか
5. **本番の `view:cache` コンパイル**（Bug #21 / #26 が「本番だけ壊れる」前例）
```

- [ ] **Step 2: `docs/ARCHITECTURE.md` に追記する**

`## Key Database Tables` の表に 1 行足す:

```markdown
| `schedule_steps` | 工程表（ポリモーフィック。仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の 4 親）|
```

`app/Enums/` の列挙に `ScheduleStepCategory.php` を、
`app/Models/` の列挙に `ScheduleStep.php` を足す。

- [ ] **Step 3: ドキュメントをコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/realestate-schedule
git add docs/BACKLOG.md docs/ARCHITECTURE.md
git commit -m "$(cat <<'MSG'
docs: 工程表（ガント表示）の実装内容を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 4: main repo へ ff-merge する**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only realestate-schedule
```

⚠ ff-merge できないときは main repo の `13.x` が先へ進んでいる。
worktree で `git rebase 13.x` してから測り直す（**テストを回し直すこと**）。

- [ ] **Step 5: autoloader を更新する（新規 PHP クラスを足したので必須）**

```bash
cd /Users/masanori/site/manage && composer dump-autoload
```

⚠ **必ず main repo の cwd で実行する。** worktree から実行すると autoloader の `$baseDir` に
worktree のパスが焼き込まれ、main repo の Apache が worktree を参照する事故になる。

- [ ] **Step 6: ローカルの DB にも DDL を流す（⚠ 忘れるとローカルが 6 画面 500 になる）**

⚠ **ff-merge した瞬間、ローカルの Apache は新しいコードを実 MySQL に対して動かす。**
`schedule_steps` がまだ無いので、詳細 4 画面とボード 2 画面が
`Base table or view not found` で落ちる。**本番と同じ理由がローカルで先に起きる。**

```bash
cd /Users/masanori/site/manage && sudo mysql manage < database/sql/2026-08-31-create-schedule-steps.sql
```

⚠ **実 DB 名は `manage` とは限らない。** memory の記録では実体は別名なので、
`sudo mysql` が非対話で通らない場合も含めて、迷ったら
`php artisan tinker --execute="DB::unprepared(file_get_contents('database/sql/2026-08-31-create-schedule-steps.sql')); echo 'ok';"`
で `.env` の接続先へ流す（`CREATE TABLE IF NOT EXISTS` の単一文なので再実行して安全）。

- [ ] **Step 7: 本番へ DDL を流す（⚠ `./deploy.sh` より先）**

⚠ **順番を逆にしない。** テーブルが無い状態でコードだけ本番へ出ると、
**詳細 4 画面とボード 2 画面が `Base table or view not found` で 500 する**（設計書 §7）。

⚠ **本番の生 ssh は分類器にブロックされる**ので、**ユーザーに実行してもらう**か、
`php artisan tinker --execute` 経由で流す（memory「SoftDelete等スキーマ依存コードは DB 先→コード後」参照）。
DDL は `CREATE TABLE IF NOT EXISTS` の**単一ステートメント**なので `DB::unprepared()` で流せる。

- [ ] **Step 8: 本番反映の可否をユーザーに確認する**

⚠ **`./deploy.sh` はユーザーの明示承認がある時だけ実行する**（設計書 §7）。
承認が無い文脈では自動モードの分類器が止める。`AskUserQuestion` で明示的に聞くこと。

- [ ] **Step 9: 承認が得られたらデプロイする**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

- [ ] **Step 10: 本番で目視する**

Task 12 の Step 4〜8 と同じ 6 画面を、**本番の URL**（`https://www.mitsuwat.co.jp/system/manage/...`）で見る。

⚠ **本番の URL は `/index.php/` が要る場合がある**（memory「本番ブラウザ検証は /index.php/ 必須」）。
302 で流れたら prefix 漏れを先に疑う。
⚠ **302 は認証リダイレクトでビューを描画する前に起きる**ので、「全ルートが 302」を
「アプリは正常」の証明にしてはいけない（BACKLOG の POI の件で実際にこれを踏んでいる）。

---

## 変異テストの実測結果（Task 11 で実測。2026-09-01）

作法（Bug #44 / #54）はスクリプトで強制した: ①事前に `git status --porcelain` が空
②`git diff --stat` が非空で着弾を確認 ③`php -l` が通ることを確認（構文エラーで赤くなる誤読を防ぐ）
④**落ちた理由の文言**まで突き合わせる ⑤必ず `git checkout --` で戻す。

⚠ **最初に perl の `\Q...\E` で置換しようとして 2 件が 0 箇所置換になった。**
`\Q...\E` の中でも `$s` `$e` のような**変数は展開される**ため。スクリプトが「0 箇所＝無効な測定」と
して止めたので誤読せずに済んだ（これを止めないと「検出しない」と読み違える。過去に 2 回踏んでいる）。
以降は python のリテラル置換に切り替えた。

| # | 変異 | 当たったか | 検出したテスト | 落ちた理由の文言 |
|---|---|---|---|---|
| 1 | `width()` の `+1` を消す | ✅ 1 file, 1+/1- | `GanttScaleTest::test_a_single_day_step_has_a_non_zero_width` ほか 3 本 | `1 日だけの工程は幅 0 になってはいけない`／`Failed asserting that 0.0 is greater than 0.0` |
| 2 | `startOfDay()` を外す | ✅ 1 file, 1+/1- | `GanttScaleTest::test_time_components_are_normalised_to_the_start_of_the_day` | `endOfMonth の時刻成分で 1 日ずれている`／`213 is identical to 212` |
| 3 | 遅延判定を `>=` に（`lessThanOrEqualTo` → `lessThan`） | ✅ 1 file, 1+/1- | **検出せず（全 1151 緑）** | — ⚠ **振る舞いが変わらない変異だった**（equivalent mutant）。等値のとき早期 return を通らなくても `diffInDays` が 0 を返すため、`delayDays` は 0 のまま。**テストの穴ではない** |
| 3b | 境界を 1 日ずらす（`$due` を `subDay()`）＝ #3 の意図を測り直したもの | ✅ 1 file, 1+/1- | `ScheduleStepStatusTest::test_finishing_exactly_on_the_planned_end_is_not_late` ほか 5 本 | `予定終了ちょうどに終わったのを遅延にしないこと` ⇒ **境界は実際に守られている** |
| 4 | 未着手の遅延を数えない | ✅ 1 file, 1+/1- | `ScheduleStepStatusTest::test_never_started_past_the_planned_end_is_late` ほか 6 本（ボードのステータスまで波及） | `Failed asserting that 0 is identical to 11` |
| 5 | 段振り分けを 1 段固定に | ✅ 1 file, 1+/1- | `LanePackerTest` 3 本 ＋ `ScheduleBoardTest::test_overlapping_steps_are_spread_across_lanes` | `重なる工程が同じ段に載っている（読めなくなる）` |
| 6 | 段の判定を「以降」に | ✅ 1 file, 1+/1- | `LanePackerTest::test_a_step_starting_on_the_day_the_previous_one_ends_goes_to_a_new_lane` | `Failed asserting that two arrays are identical`（`[0,1]` が `[0,0]` に） |
| 7 | 実績優先をやめる | ✅ 1 file, 1+/1- | **初回は検出せず（全 1151 緑）→ テストを足して赤を実測** | ⚠ **実在の穴だった。** 予定と実績が**両方**入った工程を 1 件も作っていなかったので、`actual_start ?? planned_start` を入れ替えても差が出なかった。設計書 §5.2 の例（予定 5/18〜9/30・実績 6/1〜10/16）を `ScheduleSectionRenderTest::test_a_step_with_both_planned_and_actual_dates_is_drawn_from_the_actual_ones` として追加（`16e27cb1`）→ 再測定で `実績で描いていない（設計書 §5.2）` で赤 |
| 8 | 所有権の型比較を消す | ✅ 1 file, 1+/2- | `ScheduleStepAuthorizationTest::test_a_step_belonging_to_a_same_id_parent_in_another_department_is_not_found` | `Failed asserting that 200 is identical to 404`（他部署の工程が書き換えられた） |
| 9 | 完成 ◆ を 2 つ描く | ✅ 1 file, 4+/3- | `ScheduleAutoMilestoneTest::test_property_completion_is_a_single_milestone_even_when_both_dates_exist` | `完成の ◆ を 2 つ描かないこと（設計書 §3.4）` |
| 10 | ルートを 1 本消す | ✅ 1 file, 2+/2-（`php -l` 通過を確認済み＝構文エラーで赤くなっていない） | `ScheduleRouteWiringTest` 3 本 ＋ `ScheduleStepCrudTest` 2 本 | `工程ルートの本数が 16 でない（走査の空振り防止）`／`actual size 15 matches expected size 16` |
| 11 | `reorder` を `{step}` の後ろへ | ✅ 1 file, 2+/2- | `ScheduleRouteWiringTest::test_reorder_wins_over_the_step_parameter` ＋ `ScheduleStepCrudTest::test_reorder_rewrites_sort_order_for_every_parent` | `realestate.procurements: /schedule-steps/reorder が {step} に食われている（登録順を直すこと）`／`404 is identical to 200` |
| 12 | ボードの対象を全親に広げる | ✅ 1 file, 1+ | `ScheduleBoardTest::test_the_realestate_board_never_shows_housing_cases` | `Failed asserting that two arrays are identical`（住宅の案件が不動産のボードに出た） |
| 13 | `@include` をインライン複製に | ✅ 1 file, 6+/1- | 挙動 6 本 ＋ **構造 2 本**（`test_all_four_detail_views_include_the_one_shared_partial` / `test_the_gantt_markup_lives_only_in_the_partial`） | `共通 partial を include していない（マークアップを複製していないか）` ⚠ 構造テストが**名指しで**落ちることを、その 2 本だけに絞って別途確認した |
| 14 | `countSoon` の実績ガードを消す | ✅ 1 file, 1+/1- | `ScheduleBoardTest::test_the_kpis_agree_with_the_rows_on_screen` | `Failed asserting that 2 is identical to 1`（着手済みの工程まで数えた） |
| 15 | KPI を絞り込み前から数える | ✅ 1 file, 1+/1- | `ScheduleBoardTest::test_the_kpis_follow_the_filter` ＋ `test_the_kpis_agree_with_the_rows_on_screen` | `絞り込み後の行から数えていない` |
| 16 | サイドバーの片ブロックを消す（**モバイルドロワー側だけ**） | ✅ 1 file, 1- | `ScheduleBoardTest::test_both_sidebar_blocks_link_to_each_board` | `/housing/schedules へのサイドバー導線が 2 箇所（PC 展開 / モバイルドロワー）ありません` |

**結論: 17 通り測って 16 検出 / 1 は equivalent mutant（#3）。**
穴は **#7 の 1 件**で、テストを足して赤になることまで確認した。

⚠ **#3 を「テストの穴」と書かなかった理由**を残しておく —— 変異が当たっている（`git diff` 非空）のに
緑だった場合、**まず「その変異で本当に振る舞いが変わるのか」を確かめる**こと。
#3 は等値のとき早期 return を通らなくても `diffInDays` が 0 を返すので出力が変わらない。
意図（境界の `>` と `>=`）が守られているかは **#3b の意味が変わる変異**で測り直して確認した。

---

## 完了の定義

- [ ] `./vendor/bin/phpunit` が緑で、本数が 1050 から減っていない
- [ ] コンパイル済みビューの `php -l` が 0 件（Task 12-1）
- [ ] 実ブラウザで 6 画面を目視し、`main.scrollWidth === main.clientWidth` を広い幅・狭い幅の両方で確認
- [ ] 変異 16 通りの結果表が埋まっている
- [ ] `docs/BACKLOG.md` / `docs/ARCHITECTURE.md` を更新した
- [ ] main repo で ff-merge ＋ `composer dump-autoload` を実行した
- [ ] **本番 DDL を流してから** `./deploy.sh`（ユーザーの明示承認あり）
- [ ] 本番で 6 画面を目視した
