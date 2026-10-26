# 一覧のページ送りを部品 `<x-pager>` にそろえる — 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ページ番号を 1 から最後まで全部並べている 31 本の一覧（基幹 29・決裁 2）を、先頭・最後・今のページの前後だけを出す部品 `<x-pager>` にそろえ、狭い画面でボタンが縮んだり両端が切れたりしないようにする。

**Architecture:** 番号の決め方 `App\Support\Approval\PageNumbers` を `App\Support\PageNumbers` へ移し（中身は同じ）、新しい匿名コンポーネント `resources/views/components/pager.blade.php` がそれを呼ぶ。31 本のページ送りの塊（`@if($x->hasPages())` から対の `@endif` まで）は、塊を「決まった形」と照らし合わせる置き換えスクリプトで `<x-pager :paginator="$x" class="…" />` の 1 行にする。決裁の `approvals/_pager` は部品を呼ぶ包みとして残し、決裁の 3 つのコントローラから `pages` を消す。全ビューを全件分類する走査テストで、手作りのページ送りが戻らないようにする。

**Tech Stack:** Laravel 12・Blade（匿名コンポーネント・`@props`・`$attributes->class()`）・PHPUnit 11・PHP 8.3

**Spec:** `/Users/masanori/site/manage/.claude/worktrees/route-coverage/docs/superpowers/specs/2026-10-13-list-pager-design.md`（枝 `page-numbers` にだけある。読んでから始める）

## Global Constraints

- PHP は 8.3（テスト・lint・composer とも `/opt/homebrew/opt/php@8.3/bin`）。本番も 8.3
- 作業は作業場所 `/Users/masanori/site/manage/.claude/worktrees/route-coverage`（枝 `page-numbers`）だけで行う。main repo（`/Users/masanori/site/manage`）ではテストを流さない（main repo の vendor は `--no-dev`・`.env` は実 DB）
- 作業場所に `.env` を作らない（作らないことが実 DB へ届かない安全策）。テストは道具 `run-tests.sh` で流す（使い捨ての `APP_KEY` を付ける）
- コマンドは `git -C <作業場所>` と絶対パスで書く（`cd` を混ぜると、作業場所の会話でコマンドを断られることがある・シェルの cwd が main repo へ戻ることがある）
- ボタンの見た目（class）は今の 31 本と同じ。変わるのは番号の並び・外側の要素（`div` → `nav aria-label="ページ送り"`）・`aria-current="page"`・不動産と住宅事業の 6 本の `transition-colors`・買主の箱の書き方だけ（設計書 §3.5）
- 部品の属性に `&quot;` を書かない（Bug #21）。`:paginator` には変数だけを渡す
- `->links()` を使わない（Bug #24）
- 基幹のコントローラは触らない（決裁の 3 つのコントローラは `pages` を消すだけ）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）。本文の最後の段落に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（下の各 Task のコマンドどおり `-m` を 2 つ渡す）。テストのコミットと直しのコミットは分ける。`git stash`・`--no-verify` は使わない
- `.env` など秘密のファイルは読まない・開かない・grep しない
- 本番の読み取り・`13.x` への早送り・`./deploy.sh`・Chrome の確認・push は、段ごとに利用者の明示の承認を取る（Task 8）。それまで本番にも `13.x` にも触らない
- 返答・記録は日本語。選択肢は本文の表で出す

## Review Focus

1. 手で打った大きな `?page=`（整数の最大 `9223372036854775807`）・行の無いページ — 500 にせず、最後のページの周りの番号を出し、どれも今のページの色にしない（Task 2 の `test_a_page_past_the_last_page`・Task 4 の `PagerRoundTripTest`）
2. 絞り込みの条件 — 番号・「<」「>」のリンクに残る（Task 2 の `test_the_links_keep_the_query`・Task 3 の自分の申請・Task 4 の住宅事業ダッシュボード）
3. `?page=abc` のような数字でないページ番号 — 1 ページ目として描く（Task 4 の往復）
4. 0 件・1 ページ — ページ送りを出さない（Task 2 の `test_nothing_is_rendered_when_there_is_one_page`）
5. 320〜375px の幅 — ボタンが 375px で 32px のまま・320px でも切れない（Task 6 の実ブラウザ。PHP のテストでは測れない）

---

## 0. この計画の前提

### 0.1 置き場所

| 名前 | パス |
|---|---|
| 作業場所（WT） | `/Users/masanori/site/manage/.claude/worktrees/route-coverage`（枝 `page-numbers`・vendor は実体） |
| main repo | `/Users/masanori/site/manage`（`13.x`。Task 8 まで触らない） |
| 道具（TOOLS） | `/Users/masanori/site/manage/.claude/route-coverage-tools`（git の対象外。この計画を書いたときに置いた） |
| 置き換えスクリプト | `TOOLS/pager/replace-pagers.php`（中身は付録 A） |
| テストを流す | `TOOLS/pager/run-tests.sh [phpunit の引数…]` |
| ビューの lint | `TOOLS/pager/lint-views.sh <WT> <出力先>` |
| 使い捨てのサーバー | `TOOLS/pager/serve.sh <写しのフォルダ>`（Task 6） |
| 変異の表 | `TOOLS/mutate/specs.pager.py`（Task 5 で `specs.py` に写して流す。中身は付録 B） |
| SCRATCH | そのセッションの scratchpad（システムプロンプトの Scratchpad directory）。下では `<SCRATCH>` と書く |

### 0.2 試作で測ったこと（2026-10-13・作業場所の写し `dc3ba7f3` に当てた）

この計画のコードと置き換えは、作業場所の写し（vendor ごと実体でコピーし、git を切り離したもの）に当てて測ってから書いた。置き換え前と後は試作の編集スクリプトから差し込んだ（手で写していない）。

| 段 | 結果 |
|---|---|
| Task 1 の赤 → 緑 | 赤: `App\Support\PageNumbers` が無い・古いファイルが残る ／ 緑: `PageNumbersTest` と決裁の 3 本で OK (68 tests) |
| Task 2 の赤 → 緑 | 赤: `Unable to locate a class or view for component [pager].` ×12 ／ 緑: OK (12 tests) |
| Task 3 の赤 → 緑 | 赤: 包みが `Undefined variable $pages`・自分の申請に `nav` が無い ／ 緑: 部品のテスト・番号のテスト・`tests/Feature/Approval` で OK (1035 tests) |
| Task 4 の赤 → 緑 | 赤: 走査が 29 本 145 か所を名指し・呼び出しが 3 か所・往復 4 本と住宅事業ダッシュボードに `nav` が無い ／ 緑: OK (19 tests) |
| 全件 | OK (4105 tests, 39003 assertions)（着手前の 4064 本 ＋ この計画で足した 41 本） |
| コンパイル済みビューの lint | views=306 invalid=0（305 本 ＋ 部品 1 本） |
| 変異 | 38 通り（カナリアを含む）がすべて期待どおり。1 回目に 1 通り（S06）だけ表が狭かった（`links` を外すと行番号の見本も落ちる＝正しい動き）ので表を直した。緑のまま通った変異（穴）は 0 |
| 実ブラウザ | 10 画面 × 320・375・641・1024・1440px: ボタンは 375px 以上で 32px のまま・320px で 26.2〜30.1px（`px-4` の箱が 26.2px）・切れ 0・`main` の横スクロール 0・コンソールのエラー 0。「>」を押すと次のページへ移る |

### 0.3 設計書から変えた・足したこと

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.3 の印の正規表現は `(->\|?->)` | `->\s*(…)\s*\(` だけ | `?->` も `->` を含むので当たる（見本 `$p?->links()` で固定）。`\??` を外しても結果が変わらない＝変異で測れない枝を持たない |
| 2 | §5.4 の往復は並び・`aria-current`・「>」・整数の最大 | 外側の箱の class と `?page=abc` も見る | 外側の class を落とす変異（V04）を止めるため・Review Focus 3 |
| 3 | §5 に決裁の側のテストが無い | Task 3 に「包みが部品をそのまま描く」と「自分の申請の往復」を足した | 決裁の側の直しにも「先に赤」が要る |
| 4 | §3.3 で住宅事業ダッシュボードの `@php($paginated->withQueryString())` を残すとだけ書いた | `PageNumberClampTest` にページ送りが年度・期を運ぶテストを足した | 試作で、その 1 行を消す変異を止めるテストが無かった（コントローラの paginator は path だけで作る） |
| 5 | §3.2 の「320px では約 29.8px」 | 320px は 26.2〜30.1px（`px-4` の箱で 26.2px・切れない） | 実ブラウザの実測 |
| 6 | §3.1 は `git mv` だけ | 作業場所で `composer dump-autoload` も流す | 作業場所の読み込みの表は `optimize-autoloader` で App のクラスも載せるので、古い場所が表に残る（`class_exists` で古い名前を引くと、無いファイルを include しにいく） |
| 7 | §3.4 の注記の言い方を直す | ⑩・⑥ の注記を「ページ送りの番号は部品 `<x-pager>` が抑える」にした | `PageNumbers::around()` をコントローラが呼ばなくなる |
| 8 | §6.3 の実ブラウザは 0・1・3・15・60 ページ | 1・15・16・60 ページ（使い捨てのデータの都合）。0・3 ページは部品のテスト（Task 2）が見る | `many-pages.php` は 300 件・1200 件にする。0・3 ページはボタンが 9 個より少なく、幅の心配が無い |

### 0.4 ファイルの一覧

| 区分 | ファイル | Task |
|---|---|---|
| 移す | `app/Support/Approval/PageNumbers.php` → `app/Support/PageNumbers.php` | 1 |
| 新規 | `resources/views/components/pager.blade.php` | 2 |
| 変更 | `resources/views/approvals/_pager.blade.php`（包みに）・`approvals/admin/requests`・`approvals/ledger/index`・`approvals/notices/index`（`pages` を渡さない） | 1・3 |
| 変更 | `app/Http/Controllers/Approval/AdminRequestController.php`・`NoticeController.php`・`LedgerController.php` | 1・3 |
| 変更 | ページ送りの塊を持つビュー 31 本（決裁 2 本は Task 3・基幹 29 本は Task 4） | 3・4 |
| 新規テスト | `tests/Unit/Support/PageNumbersTest.php`・`tests/Concerns/ReadsPager.php`・`tests/Feature/PagerComponentTest.php`・`tests/Feature/PagerUsageScanTest.php`・`tests/Feature/PagerRoundTripTest.php` | 1・2・4 |
| 変更テスト | `tests/Feature/Approval/Phase2/HomeAndListTest.php`・`tests/Feature/Housing/Screens/PageNumberClampTest.php` | 3・4 |
| 記録 | `docs/RULES.md`・`CLAUDE.md`・`docs/BACKLOG.md`・この計画の「実測記録」・メモリ | 5・6・7・8 |

---

## Task 0: 着手前の確かめ

**Files:** なし（読み取りだけ）

- [ ] **Step 1: 作業場所の状態**

Run:
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage status --porcelain
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage branch --show-current
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage log --oneline -3
```
Expected: 1 つ目は何も出ない。2 つ目は `page-numbers`。3 つ目の先頭がこの計画のコミット、その下が `dc3ba7f3 docs: 一覧のページ番号を前後だけにする設計書を書く`。

- [ ] **Step 2: `13.x` が進んでいないか**

Run:
```bash
git -C /Users/masanori/site/manage rev-parse --short 13.x
```
Expected: `2d0d54f9`。違えば止めて利用者に報告する（取り込むなら作業場所で `git -C <WT> merge 13.x`。rebase はしない＝記録のコミット番号を壊さない。取り込んだら Task 0 からやり直す）。

- [ ] **Step 3: vendor が実体で、道具がそろっている**

Run:
```bash
ls -ld /Users/masanori/site/manage/.claude/worktrees/route-coverage/vendor
```
```bash
ls /Users/masanori/site/manage/.claude/route-coverage-tools/pager /Users/masanori/site/manage/.claude/route-coverage-tools/mutate/pager-data
```
Expected: vendor は `drwx…`（`l` で始まる symlink でない。symlink だと変異が無音で no-op になる。Bug #50）。道具は `lint-views.sh` `replace-pagers.php` `run-tests.sh` `serve.sh` と `old_customers_block.txt` `old_pager.txt`。無ければ付録 A・B の中身で作る。

- [ ] **Step 4: 件数を測り直す**

Run:
```bash
grep -rl "getUrlRange(1," /Users/masanori/site/manage/.claude/worktrees/route-coverage/resources/views
```
Expected: 31 行（付録 C の 31 本と同じ）。違えば止めて報告する。

---

## Task 1: 番号の決め方を `App\Support\PageNumbers` へ移す

**Files:**
- Create: `tests/Unit/Support/PageNumbersTest.php`
- Move: `app/Support/Approval/PageNumbers.php` → `app/Support/PageNumbers.php`（namespace と docblock だけ直す。`around()` の中身は変えない）
- Modify: `app/Http/Controllers/Approval/AdminRequestController.php`・`NoticeController.php`・`LedgerController.php`（`use` の行）・`resources/views/approvals/_pager.blade.php`（注記の名前）

**Interfaces:**
- Produces: `App\Support\PageNumbers::around(int $current, int $last): list<int|null>`（null が「…」。先頭・最後・今のページの前後 1 つ。間が 1 ページだけなら番号。多くて 7 つ。⚠ `$current` は 1〜`$last` に抑えてから渡す）

- [ ] **Step 1: 落ちるテストを書く**

`tests/Unit/Support/PageNumbersTest.php` を次の内容で作る:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\PageNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ページ送りに出す番号（先頭・最後・今のページの前後 1 つ。間は「…」＝ null）。
 * 決裁の ⑩ から寄せた App\Support\Approval\PageNumbers を App\Support へ移した（中身は変えない）。
 * 画面の部品は <x-pager>（resources/views/components/pager.blade.php）。
 */
class PageNumbersTest extends TestCase
{
    /** @return array<string, array{int, int, list<int|null>}> */
    public static function layouts(): array
    {
        return [
            '1 ページ'                      => [1, 1, [1]],
            '2 ページの 1 ページ目'         => [1, 2, [1, 2]],
            '2 ページの 2 ページ目'         => [2, 2, [1, 2]],
            '3 ページの 1 ページ目'         => [1, 3, [1, 2, 3]],
            '3 ページの 2 ページ目'         => [2, 3, [1, 2, 3]],
            '3 ページの 3 ページ目'         => [3, 3, [1, 2, 3]],
            '11 ページの 1 ページ目'        => [1, 11, [1, 2, null, 11]],
            '11 ページの 3 ページ目'        => [3, 11, [1, 2, 3, 4, null, 11]],
            '11 ページの 4 ページ目（間が 1 ページなら番号）' => [4, 11, [1, 2, 3, 4, 5, null, 11]],
            '11 ページの 6 ページ目'        => [6, 11, [1, null, 5, 6, 7, null, 11]],
            '11 ページの 8 ページ目（間が 1 ページなら番号）' => [8, 11, [1, null, 7, 8, 9, 10, 11]],
            '11 ページの 11 ページ目'       => [11, 11, [1, null, 10, 11]],
            '60 ページの 8 ページ目'        => [8, 60, [1, null, 7, 8, 9, null, 60]],
            '60 ページの 30 ページ目'       => [30, 60, [1, null, 29, 30, 31, null, 60]],
        ];
    }

    /** @param list<int|null> $expected */
    #[DataProvider('layouts')]
    public function test_numbers_are_the_first_the_last_and_the_neighbours(int $current, int $last, array $expected): void
    {
        $this->assertSame($expected, PageNumbers::around($current, $last));
    }

    /** 60 ページのどのページでも、番号と「…」は 7 つまで。先頭・最後・今のページは必ず出る */
    public function test_no_page_of_sixty_shows_more_than_seven(): void
    {
        foreach (range(1, 60) as $current) {
            $numbers = PageNumbers::around($current, 60);
            $this->assertLessThanOrEqual(7, count($numbers), "{$current} ページ目");
            $this->assertSame(1, $numbers[0], "{$current} ページ目の先頭");
            $this->assertSame(60, $numbers[count($numbers) - 1], "{$current} ページ目の最後");
            $this->assertContains($current, $numbers, "{$current} ページ目に今のページ");
        }
    }

    /** 同じ計算を 2 か所に持たない（決裁の App\Support\Approval\PageNumbers は移した） */
    public function test_the_old_approval_copy_is_gone(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertFileDoesNotExist($root . '/app/Support/Approval/PageNumbers.php');

        $old = 'Support\\Approval\\' . 'PageNumbers';
        $left = [];
        foreach (['app', 'resources/views', 'routes'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (str_contains((string) file_get_contents($file->getPathname()), $old)) {
                    $left[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $left, '古い名前 App\\Support\\Approval\\PageNumbers が残っている');
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Unit/Support/PageNumbersTest.php
```
Expected: `Tests: 16`・`Errors: 15`・`Failures: 1`。15 本は `Error: Class "App\Support\PageNumbers" not found`、`test_the_old_approval_copy_is_gone` は `…/app/Support/Approval/PageNumbers.php" does not exist.` で落ちる。

- [ ] **Step 3: テストをコミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add tests/Unit/Support/PageNumbersTest.php
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "test: ページ送りの番号の決め方を App\\Support で確かめる" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: ファイルを移す**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage mv app/Support/Approval/PageNumbers.php app/Support/PageNumbers.php
```

- [ ] **Step 5: namespace・注記・`use` を直す（7 か所。どれも置き換え前がちょうど 1 か所あること）**

`app/Support/PageNumbers.php` — 置き換え前:

```php
namespace App\Support\Approval;

/**
 * ページ送りに出す番号（段階2 の ⑩「決裁済み・否決」から寄せた。⑥ のお知らせ一覧も使う。段階3 設計書 §5.6）。
 * 画面の部品は approvals._pager（->links() は使わない。Bug #24）。
 */
```

置き換え後:

```php
namespace App\Support;

/**
 * ページ送りに出す番号（段階2 の ⑩「決裁済み・否決」から寄せた。2026-10-13 に決裁の名前空間からここへ移し、全部の一覧で使う。Bug #117）。
 * 呼ぶのは画面の部品 <x-pager>（resources/views/components/pager.blade.php）だけ（->links() は使わない。Bug #24）。
 */
```

`app/Support/PageNumbers.php` — 置き換え前:

```php
 * ⚠ $current は呼ぶ側が 1〜$last に抑えてから渡す（手で打った大きなページ番号のままだと $current + 1 が float になり TypeError。Bug #100）
```

置き換え後:

```php
 * ⚠ $current は呼ぶ側が 1〜$last に抑えてから渡す（手で打った大きなページ番号のままだと $current + 1 が float になり TypeError。Bug #100）。
 *   <x-pager> が min(今のページ, 最後のページ) にして渡す
```

`app/Http/Controllers/Approval/AdminRequestController.php` — 置き換え前:

```php
use App\Support\Approval\FormInput;
use App\Support\Approval\PageNumbers;
use App\Support\Approval\PendingWork;
```

置き換え後:

```php
use App\Support\Approval\FormInput;
use App\Support\Approval\PendingWork;
```

`app/Http/Controllers/Approval/AdminRequestController.php` — 置き換え前:

```php
use App\Support\Approval\Workflow;
use Illuminate\Http\RedirectResponse;
```

置き換え後:

```php
use App\Support\Approval\Workflow;
use App\Support\PageNumbers;
use Illuminate\Http\RedirectResponse;
```

`app/Http/Controllers/Approval/NoticeController.php` — 置き換え前:

```php
use App\Support\Approval\PageNumbers;
use App\Support\Approval\RequestVisibility;
```

置き換え後:

```php
use App\Support\Approval\RequestVisibility;
use App\Support\PageNumbers;
```

`app/Http/Controllers/Approval/LedgerController.php` — 置き換え前:

```php
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\PageNumbers;
```

置き換え後:

```php
use App\Support\Approval\LedgerFilter;
use App\Support\PageNumbers;
```

`resources/views/approvals/_pager.blade.php` — 置き換え前:

```blade
番号は App\Support\Approval\PageNumbers::around()
```

置き換え後:

```blade
番号は App\Support\PageNumbers::around()
```

- [ ] **Step 6: 作業場所の読み込みの表を作り直す**

作業場所の vendor は作業場所だけのもの（main repo の vendor には触れない）。

Run:
```bash
env PATH=/opt/homebrew/opt/php@8.3/bin:/opt/homebrew/bin:/usr/bin:/bin composer --working-dir=/Users/masanori/site/manage/.claude/worktrees/route-coverage dump-autoload
```
Expected: 最後に `Generated optimized autoload files containing … classes`。

Run:
```bash
grep -n "PageNumbers'" /Users/masanori/site/manage/.claude/worktrees/route-coverage/vendor/composer/autoload_classmap.php
```
Expected: ちょうど 1 行 `'App\\Support\\PageNumbers' => $baseDir . '/app/Support/PageNumbers.php',`（`Approval` の付いた行が無い）。

- [ ] **Step 7: 通ることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Unit/Support/PageNumbersTest.php tests/Feature/Approval/Phase2/AdminRequestsTest.php tests/Feature/Approval/Phase3/NoticeScreenTest.php tests/Feature/Approval/Phase4/LedgerScreenTest.php
```
Expected: `OK (68 tests, …)`

- [ ] **Step 8: コミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add app/Support/PageNumbers.php app/Http/Controllers/Approval/AdminRequestController.php app/Http/Controllers/Approval/NoticeController.php app/Http/Controllers/Approval/LedgerController.php resources/views/approvals/_pager.blade.php
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage status --porcelain
```
Expected: `R  app/Support/Approval/PageNumbers.php -> app/Support/PageNumbers.php` と `M ` の 4 行だけ（左の列に文字＝ステージ済み。右の列に `M` が残っていない）。
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "refactor: ページ送りの番号の決め方を App\\Support へ移す" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 2: 部品 `<x-pager>`

**Files:**
- Create: `tests/Concerns/ReadsPager.php`・`tests/Feature/PagerComponentTest.php`
- Create: `resources/views/components/pager.blade.php`

**Interfaces:**
- Consumes: Task 1 の `App\Support\PageNumbers::around()`
- Produces: Blade の部品 `<x-pager :paginator="$x" class="…" />`（`$x` は `LengthAwarePaginator`。ページが 1 つなら何も出さない。外側は `<nav aria-label="ページ送り" class="flex justify-center gap-0.5 {渡した class}">`）／ テストの部品 `Tests\Concerns\ReadsPager::pager(string $html): array{items: list<string>, current: list<string>, links: array<string, string>, class: string}`（nav がちょうど 1 つでなければ落とす）

- [ ] **Step 1: 落ちるテストを書く**

`tests/Concerns/ReadsPager.php`:

```php
<?php

namespace Tests\Concerns;

/**
 * 描いたページ送り（<x-pager>。nav aria-label="ページ送り"）を読む。
 * 決裁の AdminRequestsTest::pager() と同じ読み方（a と span を順に読み、aria-current と href を拾う）。
 *
 * - items: ボタンの文字の並び（「<」「…」「>」を含む）
 * - current: aria-current="page" の付いたボタンの文字（今のページ。行の無いページを開いたときは空）
 * - links: リンクになっているボタンの文字 => href（実体参照を戻したもの）
 * - class: nav の class
 */
trait ReadsPager
{
    /** @return array{items: list<string>, current: list<string>, links: array<string, string>, class: string} */
    protected function pager(string $html): array
    {
        $this->assertSame(1, substr_count($html, 'aria-label="ページ送り"'), 'ページ送りがちょうど 1 つ出ていない');
        $at = strpos($html, 'aria-label="ページ送り"');
        $open = strrpos(substr($html, 0, $at), '<nav');
        $this->assertNotFalse($open, 'ページ送りが nav でない');
        $close = strpos($html, '</nav>', $at);
        $tag = substr($html, $open, strpos($html, '>', $at) - $open + 1);
        $nav = substr($html, $open, $close - $open);

        preg_match('#\bclass="([^"]*)"#', $tag, $class);
        preg_match_all('#<(a|span)\b([^>]*)>([^<]*)</\1>#u', $nav, $found, PREG_SET_ORDER);
        $pager = ['items' => [], 'current' => [], 'links' => [], 'class' => $class[1] ?? ''];
        foreach ($found as [, $element, $attributes, $text]) {
            $label = html_entity_decode(trim($text));
            $pager['items'][] = $label;
            if (str_contains($attributes, 'aria-current="page"')) {
                $pager['current'][] = $label;
            }
            if ($element === 'a' && preg_match('#href="([^"]*)"#', $attributes, $href)) {
                $pager['links'][$label] = html_entity_decode($href[1]);
            }
        }

        return $pager;
    }
}
```

`tests/Feature/PagerComponentTest.php`（決裁の包みのテストは Task 3 で足す。ここでは 12 本）:

```php
<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsPager;
use Tests\TestCase;

/**
 * ページ送りの部品 <x-pager>（resources/views/components/pager.blade.php）。
 * 番号は先頭・最後・今のページの前後 1 つだけ（間は「…」）。全部を並べるとスマホの幅で縮み、多いとカードに切られる（Bug #117）。
 *
 * ⚠ 一覧の画面からの往復（描いた「>」をたどる）は PagerRoundTripTest。ここは部品を Blade::render で描いて見る。
 */
class PagerComponentTest extends TestCase
{
    use ReadsPager;

    private const PER_PAGE = 20;

    /** 行の中身は部品に関係しない（数・ページ・URL だけを見る） */
    private function paginator(int $total, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], $total, self::PER_PAGE, $page, ['path' => 'http://localhost/list']);
    }

    private function render(LengthAwarePaginator $paginator, string $class = 'px-4 py-3 border-t border-gray-200'): string
    {
        return Blade::render('<x-pager :paginator="$p" class="' . $class . '" />', ['p' => $paginator]);
    }

    /** 0 件・1 ページのときは何も出さない（今の 31 本の @if(hasPages()) と同じ） */
    public function test_nothing_is_rendered_when_there_is_one_page(): void
    {
        $this->assertSame('', trim($this->render($this->paginator(0, 1))));
        $this->assertSame('', trim($this->render($this->paginator(20, 1))));
    }

    /** 3 ページの 2 ページ目: 番号は全部・今のページは aria-current・前後は隣のページ */
    public function test_three_pages(): void
    {
        $p = $this->paginator(50, 2);
        $pager = $this->pager($this->render($p));

        $this->assertSame(['<', '1', '2', '3', '>'], $pager['items']);
        $this->assertSame(['2'], $pager['current']);
        $this->assertSame(['<' => $p->url(1), '1' => $p->url(1), '3' => $p->url(3), '>' => $p->url(3)], $pager['links']);
    }

    /** 最初のページの「<」・最後のページの「>」はリンクにしない */
    public function test_the_arrows_at_the_ends_are_not_links(): void
    {
        $first = $this->pager($this->render($this->paginator(50, 1)));
        $this->assertSame(['<', '1', '2', '3', '>'], $first['items']);
        $this->assertArrayNotHasKey('<', $first['links']);
        $this->assertSame($this->paginator(50, 1)->url(2), $first['links']['>']);

        $last = $this->pager($this->render($this->paginator(50, 3)));
        $this->assertArrayNotHasKey('>', $last['links']);
        $this->assertSame($this->paginator(50, 3)->url(2), $last['links']['<']);
    }

    /** @return array<string, array{int, list<string>}> */
    public static function elevenPages(): array
    {
        return [
            '1 ページ目'  => [1, ['<', '1', '2', '…', '11', '>']],
            '3 ページ目'  => [3, ['<', '1', '2', '3', '4', '…', '11', '>']],
            '6 ページ目'  => [6, ['<', '1', '…', '5', '6', '7', '…', '11', '>']],
            '11 ページ目' => [11, ['<', '1', '…', '10', '11', '>']],
        ];
    }

    /** 11 ページ（決裁の AdminRequestsTest と同じ並び） @param list<string> $expected */
    #[DataProvider('elevenPages')]
    public function test_eleven_pages(int $page, array $expected): void
    {
        $pager = $this->pager($this->render($this->paginator(220, $page)));

        $this->assertSame($expected, $pager['items']);
        $this->assertSame([(string) $page], $pager['current']);
    }

    /** 60 ページのどのページでも、ボタンは 9 個まで（32px × 9 ＋ 隙間 ＝ 304px。375px のカードの内側 341px に収まる）・今のページは開いたページだけ */
    public function test_no_page_of_sixty_shows_more_than_nine_buttons(): void
    {
        foreach (range(1, 60) as $page) {
            $pager = $this->pager($this->render($this->paginator(1200, $page)));
            $this->assertLessThanOrEqual(9, count($pager['items']), "{$page} ページ目");
            $this->assertSame([(string) $page], $pager['current'], "{$page} ページ目");
        }
    }

    /** @return array<string, array{int}> */
    public static function pagesPastTheLast(): array
    {
        return ['99999 ページ目' => [99999], '整数の最大' => [PHP_INT_MAX]];
    }

    /**
     * 最後のページより先（行の無いページ）を開いても落ちない。最後のページの周りの番号を出し、どれも今のページにしない（Bug #100）。
     * ⚠ 部品が今のページを最後のページまでに抑えないと、整数の最大で around() の $current + 1 が float になり TypeError
     */
    #[DataProvider('pagesPastTheLast')]
    public function test_a_page_past_the_last_page(int $page): void
    {
        $pager = $this->pager($this->render($this->paginator(50, $page)));

        $this->assertSame(['<', '1', '2', '3', '>'], $pager['items']);
        $this->assertSame([], $pager['current']);
        $this->assertArrayNotHasKey('>', $pager['links']);
    }

    /** 絞り込みの条件（appends・withQueryString）がリンクに残る＝部品は $paginator->url() を使う（Bug #31） */
    public function test_the_links_keep_the_query(): void
    {
        $p = $this->paginator(220, 6)->appends(['status' => 'all', 'q' => 'a b']);
        $pager = $this->pager($this->render($p));

        foreach (['1' => 1, '5' => 5, '7' => 7, '11' => 11, '<' => 5, '>' => 7] as $label => $page) {
            $this->assertSame($p->url($page), $pager['links'][$label], "「{$label}」");
            $this->assertStringContainsString('status=all', $pager['links'][$label], "「{$label}」に絞り込みが残る");
        }
    }

    /** 渡した class は nav に足され、既定の flex justify-center gap-0.5 も残る */
    public function test_the_class_is_added_to_the_default(): void
    {
        $this->assertSame('flex justify-center gap-0.5 mt-4', $this->pager($this->render($this->paginator(50, 1), 'mt-4'))['class']);
        $this->assertSame('flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200', $this->pager($this->render($this->paginator(50, 1)))['class']);
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Feature/PagerComponentTest.php
```
Expected: `Tests: 12`・`Errors: 12`。どれも `InvalidArgumentException: Unable to locate a class or view for component [pager].`

- [ ] **Step 3: テストをコミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add tests/Concerns/ReadsPager.php tests/Feature/PagerComponentTest.php
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "test: ページ送りの部品の並び・端・行の無いページを確かめる" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: 部品を作る**

`resources/views/components/pager.blade.php`:

```blade
{{-- 一覧のページ送り（->links() は使わない。Bug #24）。番号は先頭・最後・今のページの前後 1 つだけで、間は「…」
     （1 から最後まで全部並べると、375px で 9 ページから縮み、21 ページあたりからカードに両端が黙って切られる。Bug #117）。
     使い方: <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
     - paginator: LengthAwarePaginator（lastPage() を使う）。絞り込みの条件は呼ぶ側の withQueryString()・appends() がリンクに残す
     - class: 外側の nav に足す（既定の flex justify-center gap-0.5 は残る）
     - ページが 1 つなら何も出さない
     - 番号は App\Support\PageNumbers::around()。今のページは最後のページまでに抑えて渡す（手で打った大きな ?page= で 500 にしない。Bug #100）。
       行の無いページ（最後より先）を開いたときは、最後のページの周りの番号を出し、どれも今のページの色にしない
     ⚠ 属性に &quot; を書かない（Bug #21）。:paginator には変数だけを渡す
     ⚠ 一覧のページ送りはこの部品だけで描く（PagerUsageScanTest が全ビューを見る） --}}
@props(['paginator'])
@if($paginator->hasPages())
    @php
        $pageNumbers = \App\Support\PageNumbers::around(min($paginator->currentPage(), $paginator->lastPage()), $paginator->lastPage());
    @endphp
    <nav aria-label="ページ送り" {{ $attributes->class(['flex justify-center gap-0.5']) }}>
        @if($paginator->onFirstPage())
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
        @endif
        @foreach($pageNumbers as $page)
            @if($page === null)
                <span class="w-8 h-8 flex items-center justify-center text-xs text-gray-400">…</span>
            @elseif($page === $paginator->currentPage())
                <span aria-current="page" class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
            @endif
        @endforeach
        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
        @else
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
        @endif
    </nav>
@endif
```

- [ ] **Step 5: 通ることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Feature/PagerComponentTest.php tests/Unit/Support/PageNumbersTest.php
```
Expected: `OK (28 tests, …)`

- [ ] **Step 6: コミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add resources/views/components/pager.blade.php
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "feat: 一覧のページ送りの部品 <x-pager> を足す" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 3: 決裁の側（包み・`pages`・決裁の 2 本）

決裁の会話（「社内決裁申請」）と合意した範囲（設計書 §1・§3.4）。`approvals/_pager.blade.php` は消さない（5b の台帳と新しい一覧が読み込む）。

**Files:**
- Modify: `tests/Feature/PagerComponentTest.php`（包みのテストを足す）・`tests/Feature/Approval/Phase2/HomeAndListTest.php`（自分の申請の往復を足す）
- Modify: `resources/views/approvals/_pager.blade.php`（全体を 4 行に）・`approvals/admin/requests.blade.php`・`approvals/ledger/index.blade.php`・`approvals/notices/index.blade.php`
- Modify: `app/Http/Controllers/Approval/AdminRequestController.php`・`NoticeController.php`・`LedgerController.php`
- Modify（スクリプト）: `resources/views/approvals/requests/index.blade.php`・`resources/views/approvals/admin/users/index.blade.php`

**Interfaces:**
- Consumes: Task 2 の `<x-pager>`・`ReadsPager::pager()`
- Produces: `@include('approvals._pager', ['paginator' => $x])`（`pages` は渡さない。決裁の会話の 5b はこの形で読み込む）

- [ ] **Step 1: 落ちるテストを足す（4 か所）**

`tests/Feature/PagerComponentTest.php` — 置き換え前:

```php
    /** 渡した class は nav に足され、既定の flex justify-center gap-0.5 も残る */
```

置き換え後:

```php
    /**
     * 決裁の包み approvals._pager は、部品をそのまま呼ぶ（中身を 2 か所に持たない。決裁の 5b の一覧も読み込む）。
     * ⚠ 渡すのは paginator だけ（前は番号の並び $pages もコントローラから渡していた）
     */
    public function test_the_approval_wrapper_draws_the_component(): void
    {
        foreach ([[50, 2], [220, 6], [20, 1]] as [$total, $page]) {
            $p = $this->paginator($total, $page);
            $this->assertSame(
                trim($this->render($p, 'py-3 border-t border-gray-200')),
                trim(view('approvals._pager', ['paginator' => $p])->render()),
                "{$total} 件の {$page} ページ目",
            );
        }
    }

    /** 渡した class は nav に足され、既定の flex justify-center gap-0.5 も残る */
```

`tests/Feature/Approval/Phase2/HomeAndListTest.php` — 置き換え前:

```php
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;
```

置き換え後:

```php
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsPager;
use Tests\TestCase;
```

`tests/Feature/Approval/Phase2/HomeAndListTest.php` — 置き換え前:

```php
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 状態と番号を直接入れた、終わった申請（ここで見るのは画面の並びだけ） */
```

置き換え後:

```php
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsPager;

    /** 状態と番号を直接入れた、終わった申請（ここで見るのは画面の並びだけ） */
```

`tests/Feature/Approval/Phase2/HomeAndListTest.php` — 置き換え前:

```php
            ->assertSee('下書きは「自分の申請」から開けます。');
    }
}
```

置き換え後:

```php
            ->assertSee('下書きは「自分の申請」から開けます。');
    }

    /**
     * 自分の申請のページ送りは、先頭・最後・今のページの前後だけ（部品 <x-pager>。Bug #117）。
     * 描いた「>」をそのままたどると、絞り込みを残したまま次のページ（?page= を自分で組み立てない。Bug #31・#47）
     */
    public function test_my_requests_pager_numbers_only_the_pages_around_the_current_one(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 221; $i++) {
            $this->draftFor($w, ['subject' => "下書き{$i}"]);
        }

        $html  = $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => 'draft', 'page' => 6]))->assertOk()->getContent();
        $pager = $this->pager($html);
        $this->assertSame(['<', '1', '…', '5', '6', '7', '…', '12', '>'], $pager['items']);
        $this->assertSame(['6'], $pager['current']);
        $this->assertSame('flex justify-center gap-0.5 py-3 border-t border-gray-200', $pager['class']);
        $this->assertStringContainsString('filter=draft', $pager['links']['>'], '「>」に絞り込みが残る');

        $next = $this->actingAs($w['applicant'])->get($pager['links']['>'])->assertOk()->getContent();
        $this->assertSame(['7'], $this->pager($next)['current']);
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh --filter "test_the_approval_wrapper_draws_the_component|test_my_requests_pager_numbers_only_the_pages_around_the_current_one" tests/Feature/PagerComponentTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php
```
Expected: `Tests: 2`。包みのテストは `Illuminate\View\ViewException: Undefined variable $pages`、自分の申請は `ページ送りがちょうど 1 つ出ていない`（今の画面は `nav` でなく `div`）で落ちる。

- [ ] **Step 3: テストをコミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add tests/Feature/PagerComponentTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "test: 決裁のページ送りが部品を描くことを確かめる" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: 決裁の 2 本を、書き換えずに確かめる**

Run:
```bash
/opt/homebrew/opt/php@8.3/bin/php /Users/masanori/site/manage/.claude/route-coverage-tools/pager/replace-pagers.php /Users/masanori/site/manage/.claude/worktrees/route-coverage --check resources/views/approvals/requests/index.blade.php resources/views/approvals/admin/users/index.blade.php
```
Expected（ちょうどこの 3 行）:
```
resources/views/approvals/requests/index.blade.php:89  $requests  →  <x-pager :paginator="$requests" class="py-3 border-t border-gray-200" />
resources/views/approvals/admin/users/index.blade.php:269  $users  →  <x-pager :paginator="$users" class="py-3 border-t border-gray-200" />
確かめただけ（書き換えていない）: 2 本
```
「止めた: …」が出たら、その一覧の塊が決まった形でない（手で直してある）。書き換えずに止めて報告する。

- [ ] **Step 5: 決裁の 2 本を書き換える**

Run（Step 4 から `--check` を外しただけ）:
```bash
/opt/homebrew/opt/php@8.3/bin/php /Users/masanori/site/manage/.claude/route-coverage-tools/pager/replace-pagers.php /Users/masanori/site/manage/.claude/worktrees/route-coverage resources/views/approvals/requests/index.blade.php resources/views/approvals/admin/users/index.blade.php
```
Expected: Step 4 と同じ 2 行と `書き換えた: 2 本`

- [ ] **Step 6: 包み・`@include`・コントローラを直す（13 か所。1 つ目はファイル全体）**

`resources/views/approvals/_pager.blade.php` — ファイル全体を次の内容にする:

```blade
{{-- 決裁の一覧のページ送り。中身は部品 <x-pager>（resources/views/components/pager.blade.php。番号は先頭・最後・今のページの前後だけ。Bug #117）。
     2026-10-13 にページ送りを全部の一覧で使う部品へ移し、ここは包みとして残した（⑩ の「決裁済み・否決」・⑥ のお知らせ一覧・台帳と、これから作る一覧が読み込む）。
     渡すもの: $paginator（LengthAwarePaginator） --}}
<x-pager :paginator="$paginator" class="py-3 border-t border-gray-200" />
```

`resources/views/approvals/admin/requests.blade.php` — 置き換え前:

```blade
@include('approvals._pager', ['paginator' => $requests, 'pages' => $pages])
```

置き換え後:

```blade
@include('approvals._pager', ['paginator' => $requests])
```

`resources/views/approvals/ledger/index.blade.php` — 置き換え前:

```blade
@include('approvals._pager', ['paginator' => $rows, 'pages' => $pages])
```

置き換え後:

```blade
@include('approvals._pager', ['paginator' => $rows])
```

`resources/views/approvals/notices/index.blade.php` — 置き換え前:

```blade
@include('approvals._pager', ['paginator' => $notices, 'pages' => $pages])
```

置き換え後:

```blade
@include('approvals._pager', ['paginator' => $notices])
```

`app/Http/Controllers/Approval/AdminRequestController.php` — 置き換え前:

```php
use App\Support\Approval\Workflow;
use App\Support\PageNumbers;
use Illuminate\Http\RedirectResponse;
```

置き換え後:

```php
use App\Support\Approval\Workflow;
use Illuminate\Http\RedirectResponse;
```

`app/Http/Controllers/Approval/AdminRequestController.php` — 置き換え前:

```php
            // ページ番号は最後のページまで（Bug #100。手で打った大きな番号で PageNumbers::around() が TypeError の 500・行の無いページは件数と食い違う）
```

置き換え後:

```php
            // ページ番号は最後のページまで（Bug #100。行の無いページは件数と食い違う。ページ送りの番号は部品 <x-pager> が抑える）
```

`app/Http/Controllers/Approval/AdminRequestController.php` — 置き換え前:

```php
            return view('approvals.admin.requests', ['tab' => $tab, 'requests' => $requests, 'rows' => null, 'pages' => PageNumbers::around($requests->currentPage(), $requests->lastPage())]);
```

置き換え後:

```php
            return view('approvals.admin.requests', ['tab' => $tab, 'requests' => $requests, 'rows' => null]);
```

`app/Http/Controllers/Approval/AdminRequestController.php` — 置き換え前:

```php
        return view('approvals.admin.requests', ['tab' => $tab, 'requests' => null, 'rows' => $rows, 'pages' => null]);
```

置き換え後:

```php
        return view('approvals.admin.requests', ['tab' => $tab, 'requests' => null, 'rows' => $rows]);
```

`app/Http/Controllers/Approval/NoticeController.php` — 置き換え前:

```php
use App\Support\Approval\RequestVisibility;
use App\Support\PageNumbers;
```

置き換え後:

```php
use App\Support\Approval\RequestVisibility;
```

`app/Http/Controllers/Approval/NoticeController.php` — 置き換え前:

```php
        // ページ番号は最後のページまで（Bug #100。手で打った大きな番号で PageNumbers::around() が TypeError の 500・行の無いページは件数と食い違う）
```

置き換え後:

```php
        // ページ番号は最後のページまで（Bug #100。行の無いページは件数と食い違う。ページ送りの番号は部品 <x-pager> が抑える）
```

`app/Http/Controllers/Approval/NoticeController.php` — 置き換え前:

```php
            'notices'   => $notices,
            'pages'     => PageNumbers::around($notices->currentPage(), $notices->lastPage()),
```

置き換え後:

```php
            'notices'   => $notices,
```

`app/Http/Controllers/Approval/LedgerController.php` — 置き換え前:

```php
use App\Support\Approval\LedgerFilter;
use App\Support\PageNumbers;
```

置き換え後:

```php
use App\Support\Approval\LedgerFilter;
```

`app/Http/Controllers/Approval/LedgerController.php` — 置き換え前:

```php
            'rows'       => $rows,
            'pages'      => PageNumbers::around($rows->currentPage(), $rows->lastPage()),
```

置き換え後:

```php
            'rows'       => $rows,
```

- [ ] **Step 7: 通ることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Feature/PagerComponentTest.php tests/Unit/Support/PageNumbersTest.php tests/Feature/Approval
```
Expected: `OK (1035 tests, …)`（決裁のテストは包みを通っても同じ HTML になるので、そのまま通る）

Run:
```bash
grep -rn "pages" /Users/masanori/site/manage/.claude/worktrees/route-coverage/app/Http/Controllers/Approval/AdminRequestController.php /Users/masanori/site/manage/.claude/worktrees/route-coverage/app/Http/Controllers/Approval/NoticeController.php /Users/masanori/site/manage/.claude/worktrees/route-coverage/app/Http/Controllers/Approval/LedgerController.php
```
Expected: 何も出ない（`'pages'` も `PageNumbers` も残っていない）。

- [ ] **Step 8: コミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add resources/views/approvals app/Http/Controllers/Approval
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage status --porcelain
```
Expected: `M ` の 9 行だけ（`_pager`・`admin/requests`・`admin/users/index`・`ledger/index`・`notices/index`・`requests/index` と 3 つのコントローラ）。
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "feat: 決裁の一覧のページ送りを部品 <x-pager> にする" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 4: 基幹の 29 本（走査・往復・置き換え）

**Files:**
- Create: `tests/Feature/PagerUsageScanTest.php`・`tests/Feature/PagerRoundTripTest.php`
- Modify: `tests/Feature/Housing/Screens/PageNumberClampTest.php`（住宅事業ダッシュボードの絞り込みを足す）
- Modify（スクリプト）: 付録 C の基幹 29 本

**Interfaces:**
- Consumes: Task 2 の `<x-pager>`・`ReadsPager::pager()`、付録 A のスクリプト

- [ ] **Step 1: 落ちるテストを書く**

`tests/Feature/PagerUsageScanTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 一覧のページ送りは部品 <x-pager> だけで描く（Bug #117）。
 *
 * 【背景】2026-10-13 まで 31 本の一覧がページ番号を 1 から最後まで全部並べていた（getUrlRange(1, lastPage())）。
 *   375px で 9 ページから縮み、21〜22 ページからカードの overflow: hidden に両端が黙って切れる（main の横スクロールは 0 のまま）。
 *   本番でもテナント契約（すべて）13 ページで 18.7px まで縮んでいた。部品は先頭・最後・今のページの前後だけを出す。
 *
 * 【守るもの】全ビューを全件分類する（Top trap #13）
 *   - 手作りのページ送りの印（->getUrlRange( ・->previousPageUrl( ・->nextPageUrl( ・->onFirstPage( ・->hasMorePages( ・->links( ）は
 *     components/pager.blade.php の中にしか無い。ほかにあれば、ファイルと行を名指しして落とす
 *   - <x-pager の呼び出しは、どれも :paginator= を持つ
 *   - 読んだビューと <x-pager の呼び出しの数の下限（走査の空振りで緑にならないように）
 *
 * ⚠ Blade のコメント {{-- --}} は落としてから調べる（注記にメソッド名を書いても落ちない。行の番号は保つ）。
 * ⚠ 死角:
 *   - ページ送りの 1 行そのものを消した一覧は、呼び出しの数が下限を割るまで見えない（下限は件数ぴったりにしない＝決裁が新しい一覧で
 *     <x-pager> を足すたびに赤くならないように）
 *   - ->render() ・ paginator を別の名前の変数に入れて呼ぶ書き方・JS で組むページ送りは見ない
 */
class PagerUsageScanTest extends TestCase
{
    private const COMPONENT = 'components/pager.blade.php';

    private const MIN_VIEWS = 250;

    private const MIN_PAGER_CALLS = 32;

    /** 手作りのページ送りの印（`->` の直後のメソッド名と括弧。`?->` も `->` を含むので当たる） */
    private const MARKER = '/->\s*(getUrlRange|previousPageUrl|nextPageUrl|onFirstPage|hasMorePages|links)\s*\(/';

    /** <x-pager の呼び出し（<x-pager-foo は数えない。属性の中の > は引用符の中なら読み飛ばす） */
    private const CALL = '/<x-pager(?=[\s\/>])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/';

    /** @return array<string, string> ビューの相対パス => 中身 */
    private function views(): array
    {
        $views = [];
        $root = resource_path('views') . '/';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $views[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($views);

        return $views;
    }

    /** Blade のコメントを、同じ数の改行に置き換えて落とす（行の番号を保つ） */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")), $source);
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }

    /** @return list<string> 「行: 印」 */
    private function markers(string $source): array
    {
        $source = $this->withoutComments($source);
        preg_match_all(self::MARKER, $source, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $m) => $this->lineAt($source, $m[1]) . ': ' . $m[0], $found[0]);
    }

    /** @return list<array{line: int, attributes: string}> */
    private function calls(string $source): array
    {
        $source = $this->withoutComments($source);
        preg_match_all(self::CALL, $source, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        return array_map(fn (array $m) => ['line' => $this->lineAt($source, $m[0][1]), 'attributes' => $m[1][0]], $found);
    }

    private function hasPaginator(string $attributes): bool
    {
        return preg_match('/(?:^|\s):paginator\s*=\s*"[^"]+"/', $attributes) === 1;
    }

    public function test_handmade_pagers_exist_only_in_the_component(): void
    {
        $views = $this->views();
        $this->assertGreaterThanOrEqual(self::MIN_VIEWS, count($views), '読んだビューが少なすぎる（走査の空振り）');
        $this->assertArrayHasKey(self::COMPONENT, $views, '部品が無い');
        $this->assertNotSame([], $this->markers($views[self::COMPONENT]), '部品が手作りのページ送りの印を持たない（検出器の空振り）');

        $found = [];
        foreach ($views as $path => $source) {
            if ($path === self::COMPONENT) {
                continue;
            }
            foreach ($this->markers($source) as $marker) {
                $found[] = "{$path}:{$marker}";
            }
        }
        $this->assertSame([], $found, "ページ送りは <x-pager :paginator=\"…\" /> で描く（番号を全部並べるとスマホの幅で切れる）:\n" . implode("\n", $found));
    }

    public function test_every_pager_call_passes_a_paginator(): void
    {
        $calls = 0;
        $missing = [];
        foreach ($this->views() as $path => $source) {
            foreach ($this->calls($source) as $call) {
                $calls++;
                if (! $this->hasPaginator($call['attributes'])) {
                    $missing[] = "{$path}:{$call['line']}";
                }
            }
        }
        $this->assertGreaterThanOrEqual(self::MIN_PAGER_CALLS, $calls, "<x-pager> の呼び出しを {$calls} か所しか拾えていない");
        $this->assertSame([], $missing, "<x-pager> に :paginator=\"\$…\" を渡していない:\n" . implode("\n", $missing));
    }

    /** 見本: 印は当たる（メソッド名ごと・?->・空白） */
    public function test_sample_markers_are_found(): void
    {
        $hits = [
            '$p->getUrlRange(1, $p->lastPage())',
            '$p->previousPageUrl()',
            '$p->nextPageUrl()',
            '$p->onFirstPage()',
            '$p->hasMorePages()',
            '$p->links()',
            '$p?->links()',
            '$p-> links()',
            "\$p\n    ->links()",
            '$p->links ()',
        ];
        foreach ($hits as $sample) {
            $this->assertCount(1, $this->markers("{{ {$sample} }}"), "当たるべき: {$sample}");
        }
    }

    /** 見本: コメントの中・文の中の語・似た名前のメソッドには当たらない */
    public function test_sample_lookalikes_are_not_markers(): void
    {
        $misses = [
            '{{-- $p->links() は使わない --}}',
            "{{--\n \$p->getUrlRange(1, 3)\n--}}",
            '<p>getUrlRange は使わない</p>',
            '{{ $menu->sublinks() }}',
            '{{ $p->linksCount() }}',
            '{{ $p->nextPageUrlFor(2) }}',
        ];
        foreach ($misses as $sample) {
            $this->assertSame([], $this->markers($sample), "当たってはいけない: {$sample}");
        }
    }

    /** 見本: コメントを落としても行の番号は保つ */
    public function test_sample_line_numbers_survive_comments(): void
    {
        $this->assertSame(['4: ->links('], $this->markers("{{-- 1\n2 --}}\n\n{{ \$p->links() }}"));
    }

    /** 見本: 呼び出しの数え方（複数行・属性の中の >・似た名前の部品・:paginator の有無） */
    public function test_sample_pager_calls(): void
    {
        $this->assertCount(1, $this->calls('<x-pager :paginator="$a" class="mt-4" />'));
        $multi = $this->calls("<x-pager\n    :paginator=\"\$a\"\n    class=\"mt-4\"\n/>");
        $this->assertCount(1, $multi);
        $this->assertTrue($this->hasPaginator($multi[0]['attributes']), '複数行でも :paginator を読む');
        $this->assertTrue($this->hasPaginator($this->calls('<x-pager class="a>b" :paginator="$a" />')[0]['attributes']), '属性の中の > を読み飛ばす');
        $this->assertSame([], $this->calls('<x-pager-foo :paginator="$a" />'), '似た名前の部品は数えない');
        $this->assertSame([], $this->calls('{{-- <x-pager :paginator="$a" /> --}}'), 'コメントの中は数えない');

        $this->assertFalse($this->hasPaginator($this->calls('<x-pager class="mt-4" />')[0]['attributes']), ':paginator が無い');
        $this->assertFalse($this->hasPaginator($this->calls('<x-pager paginator="$a" />')[0]['attributes']), 'コロンの無い paginator は文字列になる');
        $this->assertFalse($this->hasPaginator($this->calls('<x-pager :paginator="" />')[0]['attributes']), '空の :paginator');
        $this->assertFalse($this->hasPaginator($this->calls('<x-pager data-x:paginator="$a" />')[0]['attributes']), '名前の一部の :paginator');
    }
}
```

`tests/Feature/PagerRoundTripTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDadSchema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\ReadsPager;
use Tests\TestCase;

/**
 * 一覧の画面からページ送りをたどる（Bug #117・#47・#31）。外側の箱の違う代表 4 本:
 * テナントの顧客（px-4 py-3 border-t）・DAD の発注者（mt-4）・不動産の仕入れ先（px-4 py-3 border-t。前は番号に transition-colors が無かった）・
 * 基幹の利用者管理（py-3 border-t）。
 *
 * 221 件＝20 件ずつで 12 ページ。6 ページ目を開くと `< 1 … 5 6 7 … 12 >`。描いた「>」をそのまま GET して 7 ページ目に着く
 * （?page= を自分で組み立てると、リンクが壊れていても緑になる。Bug #31）。?page= に整数の最大を入れても落ちない（Bug #100）。
 * ⚠ 部品そのものの並び・端・行の無いページは PagerComponentTest。全ビューの書き方は PagerUsageScanTest。
 */
class PagerRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use ReadsPager;
    use CreatesDadSchema;
    use CreatesRealEstateSchema;

    private const ROWS = 221;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(callable $row): array
    {
        $now = now();

        return array_map(fn (int $i) => $row(sprintf('%03d', $i)) + ['created_at' => $now, 'updated_at' => $now], range(1, self::ROWS));
    }

    /** $class は外側の箱（置き換える前の箱と同じ余白・線になること） */
    private function assertRoundTrip(string $route, string $class): void
    {
        $page6 = $this->actingAs($this->user)->get(route($route, ['page' => 6]));
        $page6->assertOk();
        $pager = $this->pager($page6->getContent());
        $this->assertSame(['<', '1', '…', '5', '6', '7', '…', '12', '>'], $pager['items'], "{$route} の 6 ページ目");
        $this->assertSame(['6'], $pager['current'], "{$route} の 6 ページ目");
        $this->assertSame("flex justify-center gap-0.5 {$class}", $pager['class'], "{$route} の外側の箱");

        $page7 = $this->actingAs($this->user)->get($pager['links']['>']);
        $page7->assertOk();
        $after = $this->pager($page7->getContent());
        $this->assertSame(['7'], $after['current'], "{$route}: 描いた「>」をたどると 7 ページ目");
        $this->assertSame(['<', '1', '…', '6', '7', '8', '…', '12', '>'], $after['items'], "{$route} の 7 ページ目");

        $max = $this->actingAs($this->user)->get(route($route, ['page' => PHP_INT_MAX]));
        $max->assertOk();
        $this->assertSame(['<', '1', '…', '11', '12', '>'], $this->pager($max->getContent())['items'], "{$route}: 整数の最大のページ");

        // 数字でないページ番号は 1 ページ目（Laravel が 1 に読み替える）
        $text = $this->actingAs($this->user)->get(route($route, ['page' => 'abc']));
        $text->assertOk();
        $this->assertSame(['1'], $this->pager($text->getContent())['current'], "{$route}: ?page=abc");
    }

    public function test_tenant_customers(): void
    {
        $this->insert('customers', $this->rows(fn (string $n) => ['code' => "CU-{$n}", 'name' => "顧客{$n}", 'customer_type' => 'corporation']));

        $this->assertRoundTrip('tenant.customers.index', 'px-4 py-3 border-t border-gray-200');
    }

    public function test_dad_clients(): void
    {
        $this->createDadSchema();
        $this->insert('dad_clients', $this->rows(fn (string $n) => ['client_type' => 'municipality', 'name' => "発注者{$n}", 'created_by' => $this->user->id]));

        $this->assertRoundTrip('dad.clients.index', 'mt-4');
    }

    public function test_realestate_suppliers(): void
    {
        $this->createRealEstateSchema();
        $this->insert('re_suppliers', $this->rows(fn (string $n) => ['supplier_code' => "SUP-{$n}", 'type' => 'corporation', 'name' => "仕入れ先{$n}"]));

        $this->assertRoundTrip('realestate.suppliers.index', 'px-4 py-3 border-t border-gray-200');
    }

    public function test_admin_users(): void
    {
        User::factory()->count(self::ROWS - 1)->create(['must_change_password' => false]);

        $this->assertRoundTrip('admin.users.index', 'py-3 border-t border-gray-200');
    }
}
```

`tests/Feature/Housing/Screens/PageNumberClampTest.php` に足す（3 か所）:

`tests/Feature/Housing/Screens/PageNumberClampTest.php` — 置き換え前:

```php
use PHPUnit\Framework\Attributes\DataProvider;

/**
```

置き換え後:

```php
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsPager;

/**
```

`tests/Feature/Housing/Screens/PageNumberClampTest.php` — 置き換え前:

```php
class PageNumberClampTest extends HousingScreenTestCase
{
```

置き換え後:

```php
class PageNumberClampTest extends HousingScreenTestCase
{
    use ReadsPager;

```

`tests/Feature/Housing/Screens/PageNumberClampTest.php` — 置き換え前:

```php
        $this->assertPage(route('housing.dashboard', ['page' => $page]), 'paginated', $page, $expectedPage, $expectedRows);
    }
}
```

置き換え後:

```php
        $this->assertPage(route('housing.dashboard', ['page' => $page]), 'paginated', $page, $expectedPage, $expectedRows);
    }

    /**
     * ダッシュボードのページ送りは、年度・期の絞り込みを運ぶ。コントローラの paginator は path だけで作るので、
     * ビューの @php($paginated->withQueryString())（<x-pager> の前に残した 1 行。Bug #117）が消えると 2 ページ目で今年度に戻る
     */
    public function test_the_dashboard_pager_keeps_the_filter(): void
    {
        $this->twentyOneContracts();

        $html  = $this->actingAs($this->user)->get(route('housing.dashboard', ['fiscal_year' => 'all', 'period' => 'all']))->assertOk()->getContent();
        $pager = $this->pager($html);
        $this->assertSame(['<', '1', '2', '>'], $pager['items']);
        $this->assertSame('flex justify-center gap-0.5 mt-4', $pager['class']);
        $this->assertStringContainsString('fiscal_year=all', $pager['links']['>'], '「>」に年度が残る');
        $this->assertStringContainsString('period=all', $pager['links']['>'], '「>」に期が残る');

        $next = $this->actingAs($this->user)->get($pager['links']['>'])->assertOk();
        $this->assertSame(['2'], $this->pager($next->getContent())['current']);
        $this->assertSame('all', $next->viewData('fiscalYear'));
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Feature/PagerUsageScanTest.php tests/Feature/PagerRoundTripTest.php tests/Feature/Housing/Screens/PageNumberClampTest.php
```
Expected: `Tests: 19`・`Failures: 7`。
- `test_handmade_pagers_exist_only_in_the_component` が付録 C の基幹 29 本を 145 か所（1 本に 5 つずつ: `onFirstPage` `previousPageUrl` `getUrlRange` `hasMorePages` `nextPageUrl`）名指しして落ちる（決裁の 2 本と `approvals/_pager` は Task 3 で直したので出ない）
- `test_every_pager_call_passes_a_paginator` が `<x-pager> の呼び出しを 3 か所しか拾えていない`
- 往復 4 本と `test_the_dashboard_pager_keeps_the_filter` が `ページ送りがちょうど 1 つ出ていない`
- 見本の 4 本（`test_sample_…`）とほかの 8 本は通る

- [ ] **Step 3: テストをコミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add tests/Feature/PagerUsageScanTest.php tests/Feature/PagerRoundTripTest.php tests/Feature/Housing/Screens/PageNumberClampTest.php
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "test: 一覧のページ送りを全ビューで走査し、画面からたどる" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: 基幹の 29 本を、書き換えずに確かめる**

Run（ファイルは付録 C の基幹 29 本。順番どおり）:
```bash
/opt/homebrew/opt/php@8.3/bin/php /Users/masanori/site/manage/.claude/route-coverage-tools/pager/replace-pagers.php /Users/masanori/site/manage/.claude/worktrees/route-coverage --check resources/views/admin/users/index.blade.php resources/views/buyers/index.blade.php resources/views/dad/clients/index.blade.php resources/views/dad/employees/index.blade.php resources/views/dad/projects/index.blade.php resources/views/dad/subcontractors/index.blade.php resources/views/housing/_dashboard_contracted.blade.php resources/views/housing/contracts/index.blade.php resources/views/housing/contracts/select-building-property.blade.php resources/views/housing/custom-orders/index.blade.php resources/views/housing/properties/index.blade.php resources/views/mansion/contracts/index.blade.php resources/views/mansion/parking-contracts/index.blade.php resources/views/mansion/properties/index.blade.php resources/views/mansion/tenants/index.blade.php resources/views/realestate/contracts/index.blade.php resources/views/realestate/procurements/index.blade.php resources/views/realestate/projects/index.blade.php resources/views/realestate/suppliers/index.blade.php resources/views/tenant/area-buildings/index.blade.php resources/views/tenant/contracts/index.blade.php resources/views/tenant/customers/index.blade.php resources/views/tenant/inquiries/index.blade.php resources/views/tenant/investments/index.blade.php resources/views/tenant/properties/index.blade.php resources/views/tenant/repairs/index.blade.php resources/views/tenant/units/index.blade.php resources/views/zeal/inquiries/index.blade.php resources/views/zeal/members/index.blade.php
```
Expected（ちょうどこの 30 行）:
```
resources/views/admin/users/index.blade.php:203  $users  →  <x-pager :paginator="$users" class="py-3 border-t border-gray-200" />
resources/views/buyers/index.blade.php:110  $buyers  →  <x-pager :paginator="$buyers" class="px-4 py-3 border-t border-gray-200" />
resources/views/dad/clients/index.blade.php:108  $clients  →  <x-pager :paginator="$clients" class="mt-4" />
resources/views/dad/employees/index.blade.php:127  $employees  →  <x-pager :paginator="$employees" class="mt-4" />
resources/views/dad/projects/index.blade.php:128  $projects  →  <x-pager :paginator="$projects" class="mt-4" />
resources/views/dad/subcontractors/index.blade.php:109  $subcontractors  →  <x-pager :paginator="$subcontractors" class="mt-4" />
resources/views/housing/_dashboard_contracted.blade.php:75  $paginated  →  @php($paginated->withQueryString()) ／     <x-pager :paginator="$paginated" class="mt-4" />
resources/views/housing/contracts/index.blade.php:367  $contracts  →  <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
resources/views/housing/contracts/select-building-property.blade.php:166  $properties  →  <x-pager :paginator="$properties" class="px-4 py-3 border-t border-gray-200" />
resources/views/housing/custom-orders/index.blade.php:304  $orders  →  <x-pager :paginator="$orders" class="mt-4" />
resources/views/housing/properties/index.blade.php:314  $properties  →  <x-pager :paginator="$properties" class="mt-4" />
resources/views/mansion/contracts/index.blade.php:210  $contracts  →  <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
resources/views/mansion/parking-contracts/index.blade.php:191  $contracts  →  <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
resources/views/mansion/properties/index.blade.php:145  $properties  →  <x-pager :paginator="$properties" class="px-4 py-3 border-t border-gray-200" />
resources/views/mansion/tenants/index.blade.php:158  $tenants  →  <x-pager :paginator="$tenants" class="px-4 py-3 border-t border-gray-200" />
resources/views/realestate/contracts/index.blade.php:201  $contracts  →  <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
resources/views/realestate/procurements/index.blade.php:215  $rows  →  <x-pager :paginator="$rows" class="px-4 py-3 border-t border-gray-200" />
resources/views/realestate/projects/index.blade.php:170  $projects  →  <x-pager :paginator="$projects" class="px-4 py-3 border-t border-gray-200" />
resources/views/realestate/suppliers/index.blade.php:82  $suppliers  →  <x-pager :paginator="$suppliers" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/area-buildings/index.blade.php:240  $rows  →  <x-pager :paginator="$rows" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/contracts/index.blade.php:151  $contracts  →  <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/customers/index.blade.php:123  $customers  →  <x-pager :paginator="$customers" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/inquiries/index.blade.php:147  $inquiries  →  <x-pager :paginator="$inquiries" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/investments/index.blade.php:139  $investments  →  <x-pager :paginator="$investments" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/properties/index.blade.php:148  $properties  →  <x-pager :paginator="$properties" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/repairs/index.blade.php:129  $repairs  →  <x-pager :paginator="$repairs" class="px-4 py-3 border-t border-gray-200" />
resources/views/tenant/units/index.blade.php:214  $units  →  <x-pager :paginator="$units" class="px-4 py-3 border-t border-gray-200" />
resources/views/zeal/inquiries/index.blade.php:219  $inquiries  →  <x-pager :paginator="$inquiries" class="px-4 py-3 border-t border-gray-200" />
resources/views/zeal/members/index.blade.php:207  $members  →  <x-pager :paginator="$members" class="px-4 py-3 border-t border-gray-200" />
確かめただけ（書き換えていない）: 29 本
```
外側の箱は `px-4 py-3 border-t border-gray-200` が 19 本（買主のインラインの style を含む）・`mt-4` が 7 本・`py-3 border-t border-gray-200` が 1 本（基幹の利用者管理）。決裁の 2 本と合わせて設計書 §3.3 の 21・7・3 本になる。「止めた: …」が出たら書き換えずに止めて報告する。

- [ ] **Step 5: 基幹の 29 本を書き換える**

Run: Step 4 のコマンドから `--check` を外したもの。
Expected: Step 4 と同じ 29 行と `書き換えた: 29 本`

- [ ] **Step 6: 通ることを確かめる**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh tests/Feature/PagerUsageScanTest.php tests/Feature/PagerRoundTripTest.php tests/Feature/Housing/Screens/PageNumberClampTest.php
```
Expected: `OK (19 tests, …)`

Run:
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage diff --stat
```
Expected: 最後の行が `29 files changed, 30 insertions(+), 676 deletions(-)`（住宅事業ダッシュボードだけ `@php(…withQueryString())` の行を残すので 2 行）。

- [ ] **Step 7: コミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add resources/views
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "fix: 一覧のページ送りを今のページの前後だけにする（基幹の 29 本）" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 5: 全件テスト・lint・変異

**Files:**
- Modify: この計画の末尾の「実測記録」（結果を書く）
- 道具: `TOOLS/mutate/specs.py`（`specs.pager.py` を写す）

- [ ] **Step 1: 全件テスト**

Run（4 分ほど。Bash の `run_in_background` で流してよい）:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/run-tests.sh
```
Expected: `OK (4105 tests, 39003 assertions)`（着手前の 4064 本 ＋ この計画で足した 41 本）

- [ ] **Step 2: コンパイル済みビューの lint**

Run:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/lint-views.sh /Users/masanori/site/manage/.claude/worktrees/route-coverage <SCRATCH>/compiled-pager
```
Expected: 最後の行が `views=306 invalid=0`（`view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）。続けて `git -C <WT> status --porcelain` が空。

- [ ] **Step 3: 変異の表を入れ替える**

今の `specs.py` は前の回の表（`specs.wrap-example.py` と同じ）。同じであることを確かめてから写す。

Run:
```bash
cmp /Users/masanori/site/manage/.claude/route-coverage-tools/mutate/specs.py /Users/masanori/site/manage/.claude/route-coverage-tools/mutate/specs.wrap-example.py
```
Expected: 何も出ない（同じ）。違えば止めて報告する（ほかの回の表を消さない）。
```bash
cp /Users/masanori/site/manage/.claude/route-coverage-tools/mutate/specs.pager.py /Users/masanori/site/manage/.claude/route-coverage-tools/mutate/specs.py
```

- [ ] **Step 4: 変異を流す**

作業ツリーが空であることを実行役が毎回確かめ、1 つずつ当てて戻す（docs/RULES.md Bug #44 の作法）。⚠ 流している間は作業場所のファイルに触らない（別のテストも流さない）。

Run（15 分ほど。Bash の `run_in_background`）:
```bash
python3 /Users/masanori/site/manage/.claude/route-coverage-tools/mutate/run.py
```
Expected: 38 行すべて `MATCH`（K00 カナリア・C01〜C09 部品・N01〜N05 番号・S01〜S16 走査・V01〜V05 ビュー・A01〜A02 決裁。表は付録 B）。`DIFF`・`NOT APPLIED`・`NO DIFF` が出たら、表を合わせに行かずに、落ちたテストと理由を読んで原因を報告する（表が狭いのか、テストの穴か）。最後に `git -C <WT> status --porcelain` が空。

- [ ] **Step 5: 実測記録を書いてコミットする**

この計画の末尾の「実測記録」に、Step 1 の最後の行・Step 2 の最後の行・Step 4 の 38 行の結果（`MATCH` の数と、そうでなかったもの）を書く。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add docs/superpowers/plans/2026-10-13-list-pager.md
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "docs: 一覧のページ送りの全件テストと変異の結果を記録する" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 6: 実ブラウザ（使い捨ての環境）

PHP のテストでは測れない「幅」を見る（Review Focus 5）。手順は `TOOLS/browser/README.md` と同じ。⚠ 作業場所の `routes/web.php` に足す一時ログインはコミットしない・最後に必ず外す（全 GET ルートを数えるテストに混ざる）。

**Files:** なし（一時ログインは Step 9 で外す）

- [ ] **Step 1: データの写しを作る**

Run:
```bash
mkdir -p <SCRATCH>/browser
```
```bash
cp /Users/masanori/site/manage/.claude/route-coverage-tools/browser/demo.sqlite /Users/masanori/site/manage/.claude/route-coverage-tools/browser/gym.sqlite <SCRATCH>/browser/
```
```bash
/opt/homebrew/opt/php@8.3/bin/php /Users/masanori/site/manage/.claude/route-coverage-tools/browser/many-pages.php <SCRATCH>/browser/demo.sqlite
```
Expected: `customers: +299`・`dad_clients: +299`・`re_suppliers: +299`・`users: +299`・`zeal_members: +299`・`ms_tenants: +299`・`buyers: +299`・`customers: +900`（テナントの顧客は 1200 件＝60 ページ）。

決裁の利用者の管理を開けるように、利用者 1 を決裁の管理者にする（写しだけ）:
```bash
sqlite3 <SCRATCH>/browser/demo.sqlite "insert into approval_members (user_id, can_view_all, is_admin, created_at, updated_at) values (1, 0, 1, datetime('now'), datetime('now'));"
```

- [ ] **Step 2: CSS と一時ログイン**

部品は今のビルドにある class だけを使うので、main repo のビルドを借りる（作業場所の `public/build` は gitignore 済み）:
```bash
ln -sfn /Users/masanori/site/manage/public/build /Users/masanori/site/manage/.claude/worktrees/route-coverage/public/build
```
`/Users/masanori/site/manage/.claude/route-coverage-tools/browser/login-route.php.txt` の中身を、作業場所の `routes/web.php` の末尾に足す（Edit で。コミットしない）。

- [ ] **Step 3: サーバーを起こす**

Run（Bash の `run_in_background`。⚠ `preview_start` は main repo の launch.json を読むので使わない）:
```bash
/Users/masanori/site/manage/.claude/route-coverage-tools/pager/serve.sh <SCRATCH>/browser
```

- [ ] **Step 4: ログインして、幅ごとに測る**

Playwright で `http://127.0.0.1:8031/_dev/login-as/1?to=/tenant/customers` を開き、`browser_evaluate` で次を流す（同じオリジンの iframe を幅を決めて開き、ページ送りを測る）:

```js
async () => {
  const urls = ['/tenant/customers?page=1', '/tenant/customers?page=30', '/tenant/customers?page=60', '/dad/clients?page=8', '/realestate/suppliers?page=8', '/admin/users?page=8', '/approvals/admin/users?page=8', '/zeal/members?page=8', '/mansion/tenants?page=8', '/realestate/customers?page=8'];
  const widths = [320, 375, 641, 1024, 1440];
  const out = [];
  for (const u of urls) {
    for (const w of widths) {
      const f = document.createElement('iframe');
      f.style.width = w + 'px'; f.style.height = '900px'; f.style.position = 'absolute'; f.style.left = '-5000px';
      document.body.appendChild(f);
      await new Promise(r => { f.onload = r; f.src = u; });
      await new Promise(r => setTimeout(r, 150));
      const d = f.contentDocument;
      const nav = d.querySelector('nav[aria-label="ページ送り"]');
      const main = d.querySelector('main');
      const row = { url: u, w, title: d.title, mainOverflow: main ? main.scrollWidth - main.clientWidth : null };
      if (!nav) { row.nav = false; out.push(row); f.remove(); continue; }
      const btns = [...nav.children];
      row.items = btns.map(b => b.textContent.trim()).join(' ');
      row.current = btns.filter(b => b.getAttribute('aria-current') === 'page').map(b => b.textContent.trim()).join(',');
      row.minW = Math.min(...btns.map(b => b.getBoundingClientRect().width));
      let anc = nav.parentElement;
      while (anc && getComputedStyle(anc).overflowX === 'visible') anc = anc.parentElement;
      const ar = (anc || d.documentElement).getBoundingClientRect();
      row.clipped = btns.filter(b => { const r = b.getBoundingClientRect(); return r.left < ar.left - 0.5 || r.right > ar.right + 0.5; }).length;
      row.navClass = nav.className;
      out.push(row);
      f.remove();
    }
  }
  return out;
}
```

Expected（試作の実測。50 行）:
- どの行も `mainOverflow: 0`・`clipped: 0`・`nav` がある
- 375・641・1024・1440px は `minW: 32`。320px は 9 個並ぶ行だけ縮む（`px-4` の箱で 26.2px・`mt-4` で 30.1px・`py-3` で 29.9px）。5〜6 個の行（テナントの顧客の 1・60 ページ目）は 320px でも 32px
- `items`: テナントの顧客 1 ページ目 `< 1 2 … 60 >`・30 ページ目 `< 1 … 29 30 31 … 60 >`・60 ページ目 `< 1 … 59 60 >`／DAD の発注者 `< 1 … 7 8 9 … 16 >`／ほかの 6 本 `< 1 … 7 8 9 … 15 >`
- `current` は開いたページ（1・30・60・8）
- `navClass`: テナントの顧客・仕入れ先・ZEAL の会員・入居者・不動産の顧客は `flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200`／DAD の発注者は `flex justify-center gap-0.5 mt-4`／基幹の利用者管理・決裁の利用者の管理は `flex justify-center gap-0.5 py-3 border-t border-gray-200`

- [ ] **Step 5: 押して移る**

`http://127.0.0.1:8031/tenant/customers?page=30` を開き、`browser_evaluate` で「>」を押す:
```js
() => { [...document.querySelectorAll('nav[aria-label="ページ送り"] a')].find(a => a.textContent.trim() === '>').click(); }
```
（画面が移るので、結果は「Execution context was destroyed」になってよい）続けて:
```js
() => ({ url: location.pathname + location.search, current: [...document.querySelectorAll('nav[aria-label="ページ送り"] [aria-current="page"]')].map(x => x.textContent.trim()), items: [...document.querySelector('nav[aria-label="ページ送り"]').children].map(x => x.textContent.trim()).join(' ') })
```
Expected: `{ url: '/tenant/customers?page=31', current: ['31'], items: '< 1 … 30 31 32 … 60 >' }`

- [ ] **Step 6: 1 ページの一覧と、コンソール**

`http://127.0.0.1:8031/tenant/properties` を開き、`document.querySelector('nav[aria-label="ページ送り"]') === null` が `true`（1 ページなら出さない）。`browser_console_messages`（level: error・all: true）が 0 件。

- [ ] **Step 7: スクリーンショット（記録用）**

375px で `/tenant/customers?page=30` のページ送りの辺りを `browser_take_screenshot` で撮る（ファイル名 `pager-375-60p-after.png`。Playwright の保存先 `/Users/masanori/site/manage/.playwright-mcp/` に出る）。

- [ ] **Step 8: 片付け**

1. `browser_close`
2. サーバーを止める: `pkill -f "artisan serve --host=127.0.0.1 --port=8031"`
3. 一時ログインを外す: `git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage checkout -- routes/web.php`
4. `rm /Users/masanori/site/manage/.claude/worktrees/route-coverage/public/build`（リンクだけを消す）
5. `git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage status --porcelain` が空
6. この回に Playwright が `/Users/masanori/site/manage/.playwright-mcp/` に作ったスナップショット（`page-*.yml`）を消す（スクリーンショットは残す）

- [ ] **Step 9: 実測記録を書いてコミットする**

この計画の末尾の「実測記録」に Step 4〜6 の結果を書く（試作と違う行があれば、その行と理由）。
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add docs/superpowers/plans/2026-10-13-list-pager.md
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "docs: 一覧のページ送りを実ブラウザで測った結果を記録する" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 7: 記録（RULES・CLAUDE.md・BACKLOG・メモリ）

**Files:**
- Modify: `docs/RULES.md`（Bug #24・#100 の追記と新しい行）・`CLAUDE.md`（件数とページネーションの行）・`docs/BACKLOG.md`（2b の「後回し」と新しい節）
- メモリ: `/Users/masanori/.claude/projects/-Users-masanori-site-manage/memory/project_pagination_inline_numbered_convention.md`

- [ ] **Step 1: 番号を数え直す**

並行する決裁の会話も RULES に番号を足すことがある（Bug 番号がぶつかった前例あり）。

Run:
```bash
git -C /Users/masanori/site/manage grep -n "^| 117 |" 13.x -- docs/RULES.md
```
```bash
git -C /Users/masanori/site/manage grep -n "^| 117 |" approval-phase3 -- docs/RULES.md
```
Expected: どちらも何も出ない（#117 は空いている）。出たら、空いている次の番号に読み替えて Step 2 の「117」「Bug #117」「#1–117」「全 117 件」をすべてその番号にする。

- [ ] **Step 2: 記録を直す（8 か所）**

Task 5・6 の実測が下の数（4105 tests・変異 38 通り・320px で 26〜30px）と違えば、実測に合わせて書く。

`docs/RULES.md` — 置き換え前:

```markdown
この行は「壊れるから避けろ」ではなく **規約**として読むこと |
```

置き換え後:

```markdown
この行は「壊れるから避けろ」ではなく **規約**として読むこと。⚠ **2026-10-13 追記**: 番号を 1 から最後まで全部並べるこの形は、375px で 9 ページから縮み、21〜22 ページから両端が黙って切れる。ページ送りは部品 `<x-pager :paginator="$x" class="…" />`（先頭・最後・今のページの前後だけ）で描き、手で組まない（Bug #117。`PagerUsageScanTest` が全ビューで止める） |
```

`docs/RULES.md` — 置き換え前:

```markdown
`PageNumbers::around()` は今のページが最後のページ以下である前提（呼ぶ側の 3 つ〈台帳・⑥・⑩〉が抑える）。
```

置き換え後:

```markdown
`PageNumbers::around()`（2026-10-13 から `App\Support\PageNumbers`）は今のページが最後のページ以下である前提で、ページ送りの部品 `<x-pager>` が抑える（Bug #117。台帳・⑥・⑩ のコントローラの抑えは、行を出すために残す）。
```

`docs/RULES.md` — 置き換え前:

```markdown
実ブラウザで一覧 39 本 × 13 幅（375〜1440px）の `main` と絞り込みの行のはみ出し 0。本番反映は `./deploy.sh` |
```

置き換え後:

```markdown
実ブラウザで一覧 39 本 × 13 幅（375〜1440px）の `main` と絞り込みの行のはみ出し 0。本番反映は `./deploy.sh` |
| 117 | 一覧のページ送りが番号を 1 から最後まで全部並べ、狭い画面でボタンが縮み、ページが多いと両端が黙って切れる（31 本。基幹 29・決裁 2）。手元の測定（2026-10-08）: 375px で 15 ページは 32px → 15〜18px に縮み、60 ページは 375・641・1024px でカードの `overflow: hidden` に両端が切られて、今のページも「<」「>」も見えない（`main` の横スクロールは 0 のまま）。本番（2026-10-13・読み取り）でもテナント契約（すべて）13 ページで 18.7px まで縮んでいた（切れはまだ無かった）（2026-10-13 修正） | Bug #24 の対応で、インラインの番号付き（`getUrlRange(1, $x->lastPage())`）を 31 本に同じ形で写していた。決裁 段階2b で ⑩・⑥・台帳だけを「先頭・最後・今のページの前後」（`PageNumbers::around()`・`approvals/_pager`）に直し、ほかは後回しにしていた | 部品 `<x-pager :paginator="$x" class="…" />`（`resources/views/components/pager.blade.php`）に 1 本化し、31 本の塊（21〜25 行）を 1 行にした。番号の決め方は `App\Support\Approval\PageNumbers` を `App\Support\PageNumbers` へ移した（中身は同じ）。決裁の `approvals/_pager` は部品を呼ぶ包みとして残し、決裁の 3 つのコントローラから `pages` を消した。ボタンは多くて 9 個＝ 375px 以上で 32px のまま・320px で 26〜30px（切れない）。⚠ **今のページは部品の中で最後のページまでに抑える**（`min(currentPage(), lastPage())`。抑えないと手で打った整数の最大の `?page=` で `around()` の `$current + 1` が float になり TypeError の 500。Bug #100。基幹の SQL の一覧はコントローラで抑えていない）。行の無いページは最後のページの周りの番号を出し、どれも今のページにしない。⚠ 住宅事業ダッシュボードの `@php($paginated->withQueryString())` は部品の前に残す（コントローラの paginator は path だけで作るので、消すと 2 ページ目で年度・期が落ちる）。⚠ 置き換えは塊を「決まった形」と照らし合わせるスクリプト（`.claude/route-coverage-tools/pager/replace-pagers.php`）で行い、手で直してある塊があれば何も書かずに止まる。回帰テスト: `PageNumbersTest`（Unit・番号の並び）／ `PagerComponentTest`（部品・行の無いページ・絞り込み・決裁の包み）／ `PagerUsageScanTest`（全ビューを全件分類: 手作りのページ送りの印 `->getUrlRange(` など 6 つは部品の中だけ・`<x-pager` は必ず `:paginator=` を持つ・下限はビュー 250 本と呼び出し 32 か所。Blade のコメントは落としてから調べる。分かれ目は見本で固定）／ `PagerRoundTripTest`（外側の箱の違う代表 4 本: 221 件の 6 ページ目 → 描いた「>」をたどって 7 ページ目・`?page=` に整数の最大と `abc`）／ 自分の申請（`HomeAndListTest`）と住宅事業ダッシュボード（`PageNumberClampTest`）の往復。⚠ 死角: ページ送りの 1 行を消した一覧は、呼び出しの数が下限を割るまで見えない・`->render()` や paginator を別の変数に入れて呼ぶ書き方は見ない。変異 38 通り（カナリアを含む）はすべて期待どおり（`.claude/route-coverage-tools/mutate/specs.pager.py`）。本番反映は main repo の cwd で `composer dump-autoload --no-dev --optimize`（クラスの置き場所が変わる）→ `./deploy.sh`。本番に残る古い `app/Support/Approval/PageNumbers.php` は rsync が消さない（どこからも読まれない） |
```

`CLAUDE.md` — 置き換え前:

```markdown
全 116 件の詳細バグカタログ
```

置き換え後:

```markdown
全 117 件の詳細バグカタログ
```

`CLAUDE.md` — 置き換え前:

```markdown
- @docs/RULES.md — Bug #1–116 +
```

置き換え後:

```markdown
- @docs/RULES.md — Bug #1–117 +
```

`CLAUDE.md` — 置き換え前:

```markdown
- ページネーション: 20 件 / page
```

置き換え後:

```markdown
- ページネーション: 20 件 / page。ページ送りは部品 `<x-pager :paginator="$x" class="…" />` で描く（番号は先頭・最後・今のページの前後だけ。手で組まない・`->links()` も使わない。`PagerUsageScanTest` が全ビューで止める。Bug #24 / #117）
```

`docs/BACKLOG.md` — 置き換え前:

```markdown
2b では ⑩ の「決裁済み・否決」だけを、今のページの前後だけにした（計画の Task 9 の実測記録の B1・利用者の決定 C5）
```

置き換え後:

```markdown
2b では ⑩ の「決裁済み・否決」だけを、今のページの前後だけにした（計画の Task 9 の実測記録の B1・利用者の決定 C5） → **2026-10-13 に 31 本とも部品 `<x-pager>` へ置き換えた**（下の「一覧のページ送りを部品 `<x-pager>` にそろえる」の節。Bug #117）
```

`docs/BACKLOG.md` — 置き換え前:

```markdown
---

## バックログ完了状況
```

置き換え後:

```markdown
---

## 🚧 一覧のページ送りを部品 `<x-pager>` にそろえる — 本番未反映

設計書: @docs/superpowers/specs/2026-10-13-list-pager-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-10-13-list-pager.md

決裁 段階2b の「後回し」に残した「ページ番号を全部並べるほかの一覧（31 か所）」を直した（docs/RULES.md の Bug #117）。
利用者の依頼（2026-10-08 の引き継ぎ）・設計の承認（2026-10-13）。決裁の会話と合意したこと: `PageNumbers` を `App\Support` へ移す・
`approvals/_pager` は部品を呼ぶ包みとして残す・決裁の 2 本（自分の申請・利用者の管理）はこちらで置き換える・決裁の 3 つのコントローラから `pages` を消す。
**DB 変更・ルート変更・依存の変更は無し。PHP クラスの置き場所が 1 つ変わる**（`App\Support\Approval\PageNumbers` → `App\Support\PageNumbers`）ので、
本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る。

| 区分 | 実装内容 |
|------|---------|
| 部品 | `resources/views/components/pager.blade.php`（`<x-pager :paginator="$x" class="…" />`。番号は先頭・最後・今のページの前後だけ・今のページは最後のページまでに抑える） |
| Support | `App\Support\PageNumbers`（`App\Support\Approval\PageNumbers` を移しただけ） |
| 画面 | 31 本（基幹 29・決裁 2）のページ送りの塊を 1 行に。外側の箱は 3 通り（`px-4 py-3 border-t border-gray-200` 21 本・`mt-4` 7 本・`py-3 border-t border-gray-200` 3 本）。決裁の `approvals/_pager` は包み |
| 決裁のコントローラ | `AdminRequestController`・`NoticeController`・`LedgerController` から `pages` を消した（ページ番号を抑える処理は残す） |
| テスト | 新規 `PageNumbersTest`・`PagerComponentTest`・`PagerUsageScanTest`・`PagerRoundTripTest`・`Tests\Concerns\ReadsPager` ／ `HomeAndListTest`・`PageNumberClampTest` に 1 本ずつ。4064 → **4105 tests** |
| ルート / DB | **どちらも変更なし** |

### 見た目の変化（設計書 §3.5）

- 番号が先頭・最後・今のページの前後だけになる（間は「…」）
- 不動産・住宅事業の 6 本の番号に `transition-colors`（ホバーの色の変化）が付く
- 買主の一覧の枠線の色が、インラインの `#e5e7eb` から Tailwind の `gray-200` になる（ほぼ同じ色）
- 外側が `<div>` から `<nav aria-label="ページ送り">` になり、今のページに `aria-current="page"` が付く

### 範囲外（設計書 §4）

- コントローラでページ番号を最後のページまでに抑えること（基幹の SQL の一覧は、行の無いページで表が空になるまま。部品は落ちない）
- ページ番号を直接打って移る入力欄・「最初へ」「最後へ」のボタン・1 ページの件数・ボタンの見た目

---

## バックログ完了状況
```

- [ ] **Step 3: 置き換えの確かめ**

Run:
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage diff --stat
```
Expected: `CLAUDE.md`・`docs/BACKLOG.md`・`docs/RULES.md` の 3 本だけ。

⚠ `CLAUDE.md` はリポジトリの一番上にあり、Tailwind の走査の対象（`docs/` だけが外れている）。足した文に新しい Tailwind の class 名を書いていないこと（`<x-pager … class="…" />` の「…」のまま）。

- [ ] **Step 4: メモリを直す**

`/Users/masanori/.claude/projects/-Users-masanori-site-manage/memory/project_pagination_inline_numbered_convention.md` を読み、本文の最後に次の段落を足す（ファイルを丸ごと書き換えない）:

```markdown
2026-10-13〜: 一覧のページ送りは部品 `<x-pager :paginator="$x" class="…" />`（resources/views/components/pager.blade.php）だけで描く。番号は先頭・最後・今のページの前後だけ（`App\Support\PageNumbers::around()`。決裁から移した）・今のページは部品が最後のページまでに抑える。31 本（基幹 29・決裁 2）を置き換え、決裁の `approvals/_pager` は包み。`PagerUsageScanTest` が全ビューを全件分類で見る（手作りの `->getUrlRange(` などは部品の外に書けない）。Bug #117。**本番反映は Task 8 の記録を見る**（[[project_route_coverage_probe]]）
```

`MEMORY.md` の同じメモリの行の末尾の「2026-10-13 に `<x-pager>`（番号は前後だけ・PageNumbers を App\Support へ）へ移す設計を承認（枝 page-numbers・未反映）」を「2026-10-13 に `<x-pager>` へ移した（Bug #117・枝 page-numbers）」に直す。

- [ ] **Step 5: コミットする**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage add CLAUDE.md docs/RULES.md docs/BACKLOG.md
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage commit -m "docs: 一覧のページ送りを部品にそろえたことを記録する（Bug #117）" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 8: 本番へ出す（段ごとに利用者の承認を取る）

⚠ 各 Step の前に、何をするかを本文で示して利用者の明示の承認を取る。承認が無い Step は行わない。DB・ルートの変更は無い。クラスの置き場所が変わるので、main repo の cwd で読み込みの表を作り直す。

**Files:**
- Modify: `docs/BACKLOG.md`（本番反映の記録）・この計画の「実測記録」

- [ ] **Step 1: 決裁の会話に知らせる（承認を取ってから）**

`ListAgents` で「社内決裁申請」の会話を確かめ、`SendMessage` で知らせる: 「一覧のページ送り（`<x-pager>`）を 13.x に入れて本番へ出します。13.x を進める前と `./deploy.sh` の前にお知らせします。決裁の 3 か所は `@include('approvals._pager', ['paginator' => $x])`（`pages` なし）・3 つのコントローラから `pages` と `use … PageNumbers` を消しました。台帳（LedgerController・approvals/ledger/index）は 5b で取り込んで合わせてください」。返事を待つ（向こうが 13.x を進めている途中なら終わるまで待つ）。

- [ ] **Step 2: `13.x` が進んでいないかを確かめる**

Run:
```bash
git -C /Users/masanori/site/manage rev-parse --short 13.x
```
```bash
git -C /Users/masanori/site/manage/.claude/worktrees/route-coverage merge-base --is-ancestor 13.x HEAD
```
Expected: `13.x` が `2d0d54f9` のまま・2 つ目は終了コード 0。進んでいたら、利用者の承認を取って作業場所で `git -C <WT> merge 13.x`（rebase しない）→ Task 5 の Step 1・2 をやり直す。

- [ ] **Step 3: `13.x` へ早送り（承認を取ってから）**

Run:
```bash
git -C /Users/masanori/site/manage status --porcelain
```
Expected: 空。
```bash
git -C /Users/masanori/site/manage merge --ff-only page-numbers
```
Expected: `Fast-forward`。決裁の会話に「13.x を進めました（この版の番号）。`./deploy.sh` の前にもう一度お知らせします」と知らせる。

- [ ] **Step 4: main repo の読み込みの表を作り直す**

⚠ main repo の cwd で（作業場所から実行すると作業場所のパスが焼き込まれる。CLAUDE.md の手順 3）。`--working-dir` は cwd を main repo にするのと同じ。

Run:
```bash
env PATH=/opt/homebrew/opt/php@8.3/bin:/opt/homebrew/bin:/usr/bin:/bin composer --working-dir=/Users/masanori/site/manage dump-autoload --no-dev --optimize
```
```bash
grep -n "PageNumbers'" /Users/masanori/site/manage/vendor/composer/autoload_classmap.php
```
Expected: ちょうど 1 行 `'App\\Support\\PageNumbers' => $baseDir . '/app/Support/PageNumbers.php',`。`ls /Users/masanori/site/manage/vendor/bin/phpunit` が「No such file」（dev の部品が混ざっていない。混ざると `deploy.sh` が本番へ送る）。`git -C /Users/masanori/site/manage status --porcelain` が空。

- [ ] **Step 5: 本番の読み取り（承認を取ってから）**

送る前に、本番のファイルが `13.x` の前の版（`2d0d54f9`）と同じであることを読み取りで確かめる（本番は csh・`$()` は使えない。`ssh <server> /bin/sh < ローカルの手順` の形。memory の project_prod_ssh_csh_diagnostics）。見るもの: 変わるアプリのファイル（`git -C /Users/masanori/site/manage diff --name-only 2d0d54f9 13.x -- app resources routes` の 40 本。移したファイルは新しい名前の 1 本で出る）の md5・`storage/logs/laravel.log` の日時・`public/build` の CSS の名前。

- [ ] **Step 6: `./deploy.sh`（承認を取ってから）**

決裁の会話に「これから deploy.sh を流します」と知らせてから:
```bash
/Users/masanori/site/manage/deploy.sh
```
（cwd を main repo にして流す。Bash の `run_in_background` でもよい）Expected: exit 0・6 段すべて。送ったアプリのファイルが Step 5 の一覧と同じ。

- [ ] **Step 7: 反映後の読み取り（承認を取ってから）**

本番で: 変えたファイルの md5 が手元（`13.x`）と一致・コンパイル済みビューの `php -l` が 306 本 / INVALID 0・`laravel.log` が更新されていない・`bootstrap/cache` の権限。⚠ 本番の `app/Support/Approval/PageNumbers.php` は rsync が消さないので残る（どこからも読まれない）。消すかは利用者に聞く（消すなら md5 が `2d0d54f9` の版と一致するときだけ）。

- [ ] **Step 8: Chrome で確かめる（承認を取ってから）**

利用者の Chrome（claude-in-chrome。見るだけ・フォームは送らない）で、`https://www.mitsuwat.co.jp/system/manage/index.php/tenant/contracts?status=all` を同じオリジンの iframe で 375・1440px に開き、Task 6 Step 4 と同じ測り方で: ページ送りが `nav`・ボタン 32px（前は 18.7px）・切れ 0・`main` の横スクロール 0・1 ページ目は `< 1 2 … 13 >`。⚠ javascript_tool の結果に `?status=…` のような URL の問い合わせ部分を入れない（`[BLOCKED: Cookie/query string data]` で返らない）→ 画面の名前のラベルで返す。コンソールのエラー 0。

- [ ] **Step 9: 決裁の会話に版の番号を知らせ、記録する**

決裁の会話に `13.x` の版の番号を知らせる（5b の試作をその上で作り直すため）。`docs/BACKLOG.md` の節の見出しを「— 本番反映済み」にし、本番反映の表（段・見たこと・結果）を足す。「バックログ完了状況」の最後の段落の前に 1 段落足す。この計画の「実測記録」にも書く。作業場所でコミットし、利用者の承認を取って `13.x` へ早送りする（docs だけなので `./deploy.sh` は要らない）。push は利用者の指示があったときだけ。

---

## 実測記録

### Task 5（2026-10-13・作業場所 `f6dc2355`）

| 段 | 結果 |
|---|---|
| 全件テスト | `OK (4105 tests, 39003 assertions)`（6 分 24 秒・PHP 8.3.35） |
| コンパイル済みビューの lint | `views=306 invalid=0`（作業場所の `git status --porcelain` は空のまま） |
| 変異（`specs.pager.py` を `specs.py` に写して流した。写す前の `specs.py` は `specs.wrap-example.py` と同じ） | **38 通りすべて `MATCH`**（`DIFF`・`NOT APPLIED`・`NO DIFF` は 0）。流したあとの作業ツリーは空 |

変異ごとの落ちたテストの数（どれも表の期待どおりの集合と理由）:

| # | 変異 | 落ちた数 |
|---|---|---|
| K00 | カナリア: <x-pager> の呼び出しの下限を 999 にする | 1 |
| C01 | 部品: 今のページを最後のページまでに抑えない | 5 |
| C02 | 部品: 今のページの aria-current を外す | 15 |
| C03 | 部品: 「<」を次のページへ向ける | 3 |
| C04 | 部品: hasPages() の包みを外す（1 ページでも出す） | 1 |
| C05 | 部品: 渡した class を足さない | 7 |
| C06 | 部品: 番号を 1 から最後まで全部並べる形に戻す | 12 |
| C07 | 部品: 行の無いページでも最後のページを今のページの色にする | 2 |
| C08 | 部品: 番号のリンクを ?page= の手組みにする（絞り込みが落ちる） | 2 |
| C09 | 部品: 「…」を出さない（null も番号として描く） | 8 |
| N01 | 番号: 前後を出さない | 20 |
| N02 | 番号: 前後 2 つにする | 21 |
| N03 | 番号: 先頭を落とす | 22 |
| N04 | 番号: 最後を落とす | 21 |
| N05 | 番号: 間が 1 ページでも「…」にする | 2 |
| S01 | 走査: 印から getUrlRange を外す | 1 |
| S02 | 走査: 印から previousPageUrl を外す | 1 |
| S03 | 走査: 印から nextPageUrl を外す | 1 |
| S04 | 走査: 印から onFirstPage を外す | 1 |
| S05 | 走査: 印から hasMorePages を外す | 1 |
| S06 | 走査: 印から links を外す | 2 |
| S07 | 走査: -> の後ろの空白を読まない | 1 |
| S08 | 走査: 括弧の前の空白を読まない | 1 |
| S09 | 走査: Blade のコメントを落とさない | 4 |
| S10 | 走査: コメントを落とすときに改行を残さない（行の番号がずれる） | 1 |
| S11 | 走査: 部品のファイルを除かない | 1 |
| S12 | 走査: 呼び出しの先読みを外す（<x-pager-foo も数える） | 1 |
| S13 | 走査: 属性の中の引用符を読まない（> で切れる） | 1 |
| S14 | 走査: :paginator の前の区切りを見ない | 1 |
| S15 | 走査: 空の :paginator を認める | 1 |
| S16 | 走査: ビューの数の下限を 999 にする | 1 |
| V01 | ビュー: テナントの顧客を元の全部並べる形に戻す | 3 |
| V02 | ビュー: DAD の発注者の :paginator からコロンを落とす | 2 |
| V03 | ビュー: ZEAL の会員のページ送りを消す | 1 |
| V04 | ビュー: DAD の発注者の外側の class を落とす | 1 |
| V05 | ビュー: 住宅事業ダッシュボードの withQueryString を消す | 1 |
| A01 | 決裁: _pager を元の手作りに戻す | 10 |
| A02 | 決裁: _pager の外側の class を変える | 1 |

### Task 6（2026-10-13・作業場所 `d50a75ae`・使い捨ての SQLite ＋ `artisan serve` ＋ Playwright）

| 段 | 結果 |
|---|---|
| データの写し | `many-pages.php` が 7 表に +299・テナントの顧客に +900（Expected どおり）。利用者 1 を決裁の管理者に（写しだけ） |
| 幅ごとの測定（10 画面 × 320・375・641・1024・1440px ＝ 50 行） | **試作と同じ**。どの行も `nav` があり `mainOverflow: 0`・`clipped: 0`。375px 以上はどれも `minW: 32`。320px は 9 個並ぶ行だけ縮む（`px-4 …` の箱 26.16px・`mt-4` 30.13px・`py-3 …` 29.90px）。テナントの顧客の 1・60 ページ目（5〜6 個）は 320px でも 32px |
| 並び | テナントの顧客 1 ページ目 `< 1 2 … 60 >`・30 ページ目 `< 1 … 29 30 31 … 60 >`・60 ページ目 `< 1 … 59 60 >`／DAD の発注者 `< 1 … 7 8 9 … 16 >`／ほかの 6 本 `< 1 … 7 8 9 … 15 >`。`current` は開いたページ |
| 外側の箱 | テナントの顧客・仕入れ先・ZEAL の会員・入居者・不動産の顧客は `flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200`／DAD の発注者は `… mt-4`／基幹の利用者管理・決裁の利用者の管理は `… py-3 border-t border-gray-200` |
| 押して移る | 30 ページ目で「>」を押すと `{ url: '/tenant/customers?page=31', current: ['31'], items: '< 1 … 30 31 32 … 60 >' }` |
| 1 ページの一覧 | `/tenant/properties`（1 行）でページ送りが出ない（`nav` が null） |
| コンソール | エラー・警告 0 件（セッション全体） |
| スクリーンショット | `/Users/masanori/site/manage/.playwright-mcp/pager-375-60p-after.png`（375px・60 ページの 30 ページ目） |
| 片付け | サーバーを止め、一時ログインを `git checkout -- routes/web.php` で外し、この回の `page-*.yml` 4 つを消した。作業場所の `public/build`（main repo の build へのリンク）は 10/8 の回から既にあったので消していない。作業場所・main repo とも `git status --porcelain` は空 |

（Task 8 の結果は下に書く）

### Task 8 の前段: 13.x（決裁 5b）の取り込み（2026-10-22・作業場所）

決裁の会話が 5b を先に本番へ出したので（13.x = `53b00db9`・本番のコード = `e0fe3a05`）、合意どおりこちらが後から取り込んで合わせた。

| 段 | 結果 |
|---|---|
| 取り込み | `git merge 13.x`（`53b00db9`。rebase しない）。当たりは `docs/RULES.md` だけ（#116 の後ろの #117 と #118）。#117 → #118 の順で両方残し、merge コミット `52a75844`。`LedgerController`・`approvals/ledger/index`・`CLAUDE.md`・`BACKLOG.md` は自動で合わさり、古い `PageNumbers`・`'pages'` は残らなかった |
| 先に赤 | 読み込みの表を作り直してから `PageNumbersTest` と `Phase5/PaperListTest` を流し、7 本が赤（Expected どおり）: `test_the_old_approval_copy_is_gone` が `app/Http/Controllers/Approval/PaperRecordController.php` を名指し・紙の決裁の一覧の 6 本が `Class "App\Support\Approval\PageNumbers" not found`（`PaperRecordController.php:79`） |
| 直し | `9fc2bb3b`: `PaperRecordController` の `use` と `'pages'` を消し、注記を ⑩・⑥ と同じ形に／`approvals/admin/papers/index` の `@include('approvals._pager', …)` から `'pages'` を外した。`PageNumbersTest`・`Phase5` 一式・ページ送りのテスト 3 本で `OK (189 tests, 2297 assertions)` |
| 全件テスト | `OK (4223 tests, 40130 assertions)`（3 分 38 秒・PHP 8.3.35。4105 本に 5b の 118 本が足された）。メモリのピークは 444.50 MB（前回 418.50 MB・上限 512M） |
| コンパイル済みビューの lint | `views=312 invalid=0`（306 本に 5b の新しいビュー 6 本） |


### Task 8 Step 2〜9: 本番反映（2026-10-26）

| Step | 結果 |
|---|---|
| 1 | 済み（2026-10-13・決裁の会話と合意）。決裁の会話は 10/13 から止まっていて、Step 3・6・9 の知らせは送れなかった（同時に 13.x を動かす会話が無いことを `list_sessions` で確かめた） |
| 2 | 13.x = origin/13.x = `53b00db9`・`merge-base --is-ancestor 13.x HEAD` は 0 |
| 3 | `merge --ff-only page-numbers` → 13.x = `24207618` |
| 4 | `composer dump-autoload --no-dev --optimize`（main repo の cwd）→ 7,312 クラス・`'App\\Support\\PageNumbers'` 1 行・`vendor/bin/phpunit` 無し・作業ツリー空 |
| 5 | 比べる前の版を計画の `2d0d54f9` から `53b00db9` に読み替えた（本番 = 5b ＋ docs だけ）。変わる 41 本の md5 がすべて一致・新しい 2 本は無い・`laravel.log` は Jun 18 17:41・build は `app-BGXzsqXz.css`・`app-NiVQbl_Q.js` |
| 6 | `./deploy.sh` 08:57:08〜08:57:19 exit 0。送ったアプリのファイルは期待の 42 本と完全一致。CSS・JS の名前は前と同じ |
| 7 | 42 本の md5 が `24207618` と一致・classmap は新しい名前 1・古い名前 0・ビュー 312 / INVALID 0・`laravel.log` 不変・`bootstrap/cache` 600／700。古い `Approval/PageNumbers.php` は md5 一致を確かめて消した（csh の引用の誤りで最初のコマンドは何も実行されず、`/bin/sh < 手順ファイル` で流し直した） |
| 8 | テナント契約一覧（すべて）: 375・1440px とも `nav`・ボタン 32px・切れ 0・横スクロール 0・`< 1 2 … 13 >`／7 ページ目 `< 1 … 6 7 8 … 13 >`・「<」「>」は絞り込みを保つ・コンソール 0 件 |
| 9 | この記録（BACKLOG の見出しと本番反映の表・RULES #117・計画）。push は利用者の指示を待つ |

---

## 付録 A: 置き換えスクリプト `TOOLS/pager/replace-pagers.php`

```php
<?php

/**
 * 一覧のページ送りの塊（@if($x->hasPages()) から対の @endif まで）を <x-pager> の 1 行にする（Bug #117）。
 *
 * 使い方: php replace-pagers.php <作業場所> [--check] <ビューの相対パス>...
 *   --check を付けると書き換えずに、見つけた塊と置き換え後の 1 行を出すだけ
 *
 * 塊は「決まった形」と空白を詰めて比べ、違えば何も書かずに止める（手で直した一覧を黙って壊さない）。
 * 決まった形の違いは 2 つだけ認める:
 *   - 外側の箱（4 通り。下の BOXES）
 *   - リンクの transition-colors の有無（不動産・住宅事業の 6 本は無い）
 *   - 箱の前の @php($x->withQueryString())（住宅事業ダッシュボードだけ。部品の前にそのまま残す）
 */

const BOXES = [
    '<div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">' => 'px-4 py-3 border-t border-gray-200',
    '<div class="flex justify-center gap-0.5" style="padding: 12px 16px; border-top: 1px solid #e5e7eb;">' => 'px-4 py-3 border-t border-gray-200',
    '<div class="mt-4 flex justify-center gap-0.5">' => 'mt-4',
    '<div class="flex justify-center gap-0.5 py-3 border-t border-gray-200">' => 'py-3 border-t border-gray-200',
];

function canonical(string $v, string $box, bool $withQuery): string
{
    $off = 'w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200';
    $link = 'w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50';
    $current = 'w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold';

    return implode(' ', array_filter([
        "@if(\${$v}->hasPages())",
        $withQuery ? "@php(\${$v}->withQueryString())" : null,
        $box,
        "@if(\${$v}->onFirstPage())",
        "<span class=\"{$off}\">&lt;</span>",
        '@else',
        "<a href=\"{{ \${$v}->previousPageUrl() }}\" class=\"{$link}\">&lt;</a>",
        '@endif',
        "@foreach(\${$v}->getUrlRange(1, \${$v}->lastPage()) as \$page => \$url)",
        "@if(\$page == \${$v}->currentPage())",
        "<span class=\"{$current}\">{{ \$page }}</span>",
        '@else',
        "<a href=\"{{ \$url }}\" class=\"{$link}\">{{ \$page }}</a>",
        '@endif',
        '@endforeach',
        "@if(\${$v}->hasMorePages())",
        "<a href=\"{{ \${$v}->nextPageUrl() }}\" class=\"{$link}\">&gt;</a>",
        '@else',
        "<span class=\"{$off}\">&gt;</span>",
        '@endif',
        '</div>',
        '@endif',
    ]));
}

function squeeze(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', str_replace(' transition-colors', '', $s)));
}

/** @return array{start: int, end: int, line: int, var: string, indent: string} 塊の位置（行頭から、対の @endif の行の改行まで） */
function findBlock(string $src, string $path): array
{
    preg_match_all('/^([ \t]*)@if\(\$(\w+)->hasPages\(\)\)[ \t]*\n/m', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    if (count($m) !== 1) {
        fail("{$path}: @if(\$x->hasPages()) が " . count($m) . ' か所（1 か所でない）');
    }
    [$whole, $indent, $var] = $m[0];
    $start = $whole[1];
    preg_match_all('/@(if|endif)\b/', $src, $tokens, PREG_OFFSET_CAPTURE, $start);
    $depth = 0;
    foreach ($tokens[1] as $i => [$name, $offset]) {
        $depth += $name === 'if' ? 1 : -1;
        if ($depth === 0) {
            $end = strpos($src, "\n", $offset);

            return ['start' => $start, 'end' => $end === false ? strlen($src) : $end + 1, 'line' => substr_count($src, "\n", 0, $start) + 1, 'var' => $var[0], 'indent' => $indent[0]];
        }
    }
    fail("{$path}: 対の @endif が無い");
}

function fail(string $message): never
{
    fwrite(STDERR, "止めた: {$message}\n");
    exit(1);
}

$args = array_slice($argv, 1);
$root = rtrim((string) array_shift($args), '/');
$check = in_array('--check', $args, true);
$files = array_values(array_filter($args, fn (string $a) => $a !== '--check'));
if (! is_dir("{$root}/resources/views") || $files === []) {
    fail('使い方: php replace-pagers.php <作業場所> [--check] <ビューの相対パス>...');
}

$plans = [];
foreach ($files as $path) {
    $src = (string) file_get_contents("{$root}/{$path}");
    $block = findBlock($src, $path);
    $text = substr($src, $block['start'], $block['end'] - $block['start']);
    $v = $block['var'];
    $withQuery = str_contains($text, "@php(\${$v}->withQueryString())");
    $found = null;
    foreach (BOXES as $box => $class) {
        if (squeeze($text) === squeeze(canonical($v, $box, $withQuery))) {
            $found = $class;
        }
    }
    if ($found === null) {
        fail("{$path}:{$block['line']}: ページ送りの塊が決まった形でない（手で直してある？）");
    }
    $replacement = ($withQuery ? "{$block['indent']}@php(\${$v}->withQueryString())\n" : '')
        . "{$block['indent']}<x-pager :paginator=\"\${$v}\" class=\"{$found}\" />\n";
    $plans[] = [$path, $block, $replacement, $src];
}

foreach ($plans as [$path, $block, $replacement, $src]) {
    printf("%s:%d  \$%s  →  %s\n", $path, $block['line'], $block['var'], trim(str_replace("\n", ' ／ ', trim($replacement))));
    if (! $check) {
        file_put_contents("{$root}/{$path}", substr($src, 0, $block['start']) . $replacement . substr($src, $block['end']));
    }
}
echo ($check ? '確かめただけ（書き換えていない）: ' : '書き換えた: ') . count($plans) . " 本\n";
```

付録 A の道具ほか（同じフォルダ）:

`TOOLS/pager/run-tests.sh`:
```bash
#!/bin/zsh
# 作業場所でテストを流す（PHP 8.3・使い捨ての APP_KEY。作業場所に .env は無い）
# 使い方: run-tests.sh [phpunit の引数…]   作業場所を変えるときは環境変数 WT
WT=${WT:-/Users/masanori/site/manage/.claude/worktrees/route-coverage}
cd "$WT" || exit 1
export APP_KEY="base64:$(openssl rand -base64 32)"
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
exec vendor/bin/phpunit --do-not-cache-result "$@"
```

`TOOLS/pager/lint-views.sh`:
```bash
#!/bin/zsh
# コンパイル済みビューを php -l する: lint-views.sh <作業場所> <出力先の空のフォルダ>
# （view:cache の成功表示だけでは足りない。Bug #21 / #26 / #30）
WT="$1"
OUT="$2"
if [[ -z "$WT" || -z "$OUT" ]]; then echo "使い方: lint-views.sh <作業場所> <出力先>"; exit 2; fi
mkdir -p "$OUT" || exit 1
rm -f "$OUT"/*.php(N)
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
cd "$WT" || exit 1
APP_KEY="base64:$(openssl rand -base64 32)" VIEW_COMPILED_PATH="$OUT" php artisan view:cache || exit 1
total=0
bad=0
for f in "$OUT"/*.php(N); do
  total=$((total + 1))
  if ! php -l "$f" > /dev/null 2>&1; then
    bad=$((bad + 1))
    echo "INVALID: $f"
  fi
done
echo "views=$total invalid=$bad"
```

`TOOLS/pager/serve.sh`:
```bash
#!/bin/zsh
# 作業場所を使い捨ての SQLite で起こす（実 DB には届かない。browser/README.md の 4 と同じ）
# 使い方: serve.sh <demo.sqlite と gym.sqlite の写しを置いたフォルダ>   作業場所を変えるときは環境変数 WT
B="$1"
WT=${WT:-/Users/masanori/site/manage/.claude/worktrees/route-coverage}
if [[ -z "$B" || ! -f "$B/demo.sqlite" || ! -f "$B/gym.sqlite" ]]; then echo "使い方: serve.sh <写しのフォルダ>"; exit 2; fi
cd "$WT" || exit 1
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
exec env APP_KEY="$(cat /Users/masanori/site/manage/.claude/route-coverage-tools/browser/demo-key.txt)" APP_ENV=local \
  DB_CONNECTION=sqlite DB_DATABASE="$B/demo.sqlite" ZEAL_DB_URL="sqlite:///$B/gym.sqlite" \
  APP_URL=http://127.0.0.1:8031 CACHE_STORE=file SESSION_DRIVER=file APP_LOCALE=ja APP_FALLBACK_LOCALE=ja \
  php artisan serve --host=127.0.0.1 --port=8031
```

## 付録 B: 変異の表 `TOOLS/mutate/specs.pager.py`

`pager-data/old_customers_block.txt` はテナントの顧客の今の塊（`resources/views/tenant/customers/index.blade.php` の 123〜147 行）、`pager-data/old_pager.txt` は今の `approvals/_pager.blade.php`（`dc3ba7f3` の版）。

```python
# 一覧のページ送り（<x-pager>。2026-10-13）の変異の表。流す前に期待を書く。
# expect は「クラス::メソッド: 理由の 1 行目の一部」。at_least は「少なくともこれが落ちる」。
# contains は「失敗の全文に含まれるべき文字列」（名指しされた箇所は 2 行目以降に出るため）。
# 前回（長い値の折り返しの回）の表は specs.wrap-example.py。
import os

HERE = os.path.dirname(os.path.abspath(__file__))

PNT = 'tests/Unit/Support/PageNumbersTest.php'
PCT = 'tests/Feature/PagerComponentTest.php'
PST = 'tests/Feature/PagerUsageScanTest.php'
PRT = 'tests/Feature/PagerRoundTripTest.php'
HAL = 'tests/Feature/Approval/Phase2/HomeAndListTest.php'
ART = 'tests/Feature/Approval/Phase2/AdminRequestsTest.php'
NST = 'tests/Feature/Approval/Phase3/NoticeScreenTest.php'
LST = 'tests/Feature/Approval/Phase4/LedgerScreenTest.php'
HPC = 'tests/Feature/Housing/Screens/PageNumberClampTest.php'
ALL = [PNT, PCT, PST, PRT, HAL, ART, NST, LST, HPC]
SCAN = [PST]

COMP = 'resources/views/components/pager.blade.php'
NUMS = 'app/Support/PageNumbers.php'

PC = 'PagerComponentTest::'
PN = 'PageNumbersTest::'
PS = 'PagerUsageScanTest::'
PR = 'PagerRoundTripTest::'
ROUND = {PR + 'test_tenant_customers': '', PR + 'test_dad_clients': '', PR + 'test_realestate_suppliers': '', PR + 'test_admin_users': ''}
MARKS = PS + 'test_handmade_pagers_exist_only_in_the_component'
CALLS = PS + 'test_every_pager_call_passes_a_paginator'
SAMPLE_HITS = PS + 'test_sample_markers_are_found'
SAMPLE_MISSES = PS + 'test_sample_lookalikes_are_not_markers'
SAMPLE_LINES = PS + 'test_sample_line_numbers_survive_comments'
SAMPLE_CALLS = PS + 'test_sample_pager_calls'


def ds(method, name):
    return f'{method} with data set "{name}"'


def read(name):
    return open(os.path.join(HERE, 'pager-data', name), encoding='utf-8').read()


SPECS = [
    {'id': 'K00', 'desc': 'カナリア: <x-pager> の呼び出しの下限を 999 にする', 'file': PST, 'tests': SCAN,
     'old': 'MIN_PAGER_CALLS = 32;', 'new': 'MIN_PAGER_CALLS = 999;',
     'expect': {CALLS: '<x-pager> の呼び出しを 32 か所しか拾えていない'}},

    # ---- 部品 ----
    {'id': 'C01', 'desc': '部品: 今のページを最後のページまでに抑えない', 'file': COMP, 'tests': ALL,
     'old': 'around(min($paginator->currentPage(), $paginator->lastPage()), $paginator->lastPage())',
     'new': 'around($paginator->currentPage(), $paginator->lastPage())',
     'at_least': True,
     'expect': {PC + ds('test_a_page_past_the_last_page', '整数の最大'): 'must be of type int', **{k: '' for k in ROUND}}},
    {'id': 'C02', 'desc': '部品: 今のページの aria-current を外す', 'file': COMP, 'tests': ALL,
     'old': '<span aria-current="page" class=', 'new': '<span class=',
     'at_least': True,
     'expect': {PC + 'test_three_pages': '', PC + 'test_no_page_of_sixty_shows_more_than_nine_buttons': '', **ROUND}},
    {'id': 'C03', 'desc': '部品: 「<」を次のページへ向ける', 'file': COMP, 'tests': [PCT, PRT],
     'old': '<a href="{{ $paginator->previousPageUrl() }}"', 'new': '<a href="{{ $paginator->nextPageUrl() }}"',
     'expect': {PC + 'test_three_pages': '', PC + 'test_the_arrows_at_the_ends_are_not_links': '', PC + 'test_the_links_keep_the_query': '「<」'}},
    {'id': 'C04', 'desc': '部品: hasPages() の包みを外す（1 ページでも出す）', 'file': COMP, 'tests': ALL,
     'old': '@if($paginator->hasPages())', 'new': '@if(true)',
     'at_least': True,
     'expect': {PC + 'test_nothing_is_rendered_when_there_is_one_page': ''}},
    {'id': 'C05', 'desc': '部品: 渡した class を足さない', 'file': COMP, 'tests': ALL,
     'old': "{{ $attributes->class(['flex justify-center gap-0.5']) }}", 'new': 'class="flex justify-center gap-0.5"',
     'at_least': True,
     'expect': {PC + 'test_the_class_is_added_to_the_default': '', 'HomeAndListTest::test_my_requests_pager_numbers_only_the_pages_around_the_current_one': ''}},
    {'id': 'C06', 'desc': '部品: 番号を 1 から最後まで全部並べる形に戻す', 'file': COMP, 'tests': ALL,
     'old': '@foreach($pageNumbers as $page)', 'new': '@foreach(range(1, $paginator->lastPage()) as $page)',
     'at_least': True,
     'expect': {PC + ds('test_eleven_pages', '1 ページ目'): '', PC + 'test_no_page_of_sixty_shows_more_than_nine_buttons': '', **ROUND}},
    {'id': 'C07', 'desc': '部品: 行の無いページでも最後のページを今のページの色にする', 'file': COMP, 'tests': [PCT, PRT],
     'old': '@elseif($page === $paginator->currentPage())', 'new': '@elseif($page === min($paginator->currentPage(), $paginator->lastPage()))',
     'expect': {PC + ds('test_a_page_past_the_last_page', '99999 ページ目'): '', PC + ds('test_a_page_past_the_last_page', '整数の最大'): ''}},
    {'id': 'C08', 'desc': '部品: 番号のリンクを ?page= の手組みにする（絞り込みが落ちる）', 'file': COMP, 'tests': [PCT, PRT, HAL, HPC],
     'old': '<a href="{{ $paginator->url($page) }}"', 'new': '<a href="?page={{ $page }}"',
     'expect': {PC + 'test_three_pages': '', PC + 'test_the_links_keep_the_query': '「1」'}},
    {'id': 'C09', 'desc': '部品: 「…」を出さない（null も番号として描く）', 'file': COMP, 'tests': [PCT, PRT],
     'old': '@if($page === null)', 'new': '@if(false)',
     'at_least': True,
     'expect': {PC + ds('test_eleven_pages', '1 ページ目'): '', **ROUND}},

    # ---- 番号の決め方 ----
    {'id': 'N01', 'desc': '番号: 前後を出さない', 'file': NUMS, 'tests': ALL,
     'old': '[1, $current - 1, $current, $current + 1, $last]', 'new': '[1, $current, $last]',
     'at_least': True,
     'expect': {PN + ds('test_numbers_are_the_first_the_last_and_the_neighbours', '11 ページの 6 ページ目'): '', **ROUND}},
    {'id': 'N02', 'desc': '番号: 前後 2 つにする', 'file': NUMS, 'tests': ALL,
     'old': '[1, $current - 1, $current, $current + 1, $last]', 'new': '[1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $last]',
     'at_least': True,
     'expect': {PN + ds('test_numbers_are_the_first_the_last_and_the_neighbours', '11 ページの 6 ページ目'): '', PN + 'test_no_page_of_sixty_shows_more_than_seven': '', **ROUND}},
    {'id': 'N03', 'desc': '番号: 先頭を落とす', 'file': NUMS, 'tests': ALL,
     'old': '[1, $current - 1, $current, $current + 1, $last]', 'new': '[$current - 1, $current, $current + 1, $last]',
     'at_least': True,
     'expect': {PN + 'test_no_page_of_sixty_shows_more_than_seven': '先頭', **ROUND}},
    {'id': 'N04', 'desc': '番号: 最後を落とす', 'file': NUMS, 'tests': ALL,
     'old': '[1, $current - 1, $current, $current + 1, $last]', 'new': '[1, $current - 1, $current, $current + 1]',
     'at_least': True,
     'expect': {PN + 'test_no_page_of_sixty_shows_more_than_seven': '最後', **ROUND}},
    {'id': 'N05', 'desc': '番号: 間が 1 ページでも「…」にする', 'file': NUMS, 'tests': [PNT, PCT],
     'old': 'if ($between === 1) {', 'new': 'if ($between === -1) {',
     'expect': {PN + ds('test_numbers_are_the_first_the_last_and_the_neighbours', '11 ページの 4 ページ目（間が 1 ページなら番号）'): '',
                PN + ds('test_numbers_are_the_first_the_last_and_the_neighbours', '11 ページの 8 ページ目（間が 1 ページなら番号）'): ''}},

    # ---- 走査の検出器 ----
    *[{'id': f'S0{i + 1}', 'desc': f'走査: 印から {name} を外す', 'file': PST, 'tests': SCAN,
       'old': 'getUrlRange|previousPageUrl|nextPageUrl|onFirstPage|hasMorePages|links',
       'new': '|'.join(n for n in ['getUrlRange', 'previousPageUrl', 'nextPageUrl', 'onFirstPage', 'hasMorePages', 'links'] if n != name),
       'expect': {SAMPLE_HITS: name, **({SAMPLE_LINES: ''} if name == 'links' else {})}}
      for i, name in enumerate(['getUrlRange', 'previousPageUrl', 'nextPageUrl', 'onFirstPage', 'hasMorePages', 'links'])],
    {'id': 'S07', 'desc': '走査: -> の後ろの空白を読まない', 'file': PST, 'tests': SCAN,
     'old': "MARKER = '/->\\s*(", 'new': "MARKER = '/->(",
     'expect': {SAMPLE_HITS: '-> links'}},
    {'id': 'S08', 'desc': '走査: 括弧の前の空白を読まない', 'file': PST, 'tests': SCAN,
     'old': '|links)\\s*\\(/', 'new': '|links)\\(/',
     'expect': {SAMPLE_HITS: '->links ()'}},
    {'id': 'S09', 'desc': '走査: Blade のコメントを落とさない', 'file': PST, 'tests': SCAN,
     'old': "return (string) preg_replace_callback('/\\{\\{--.*?--\\}\\}/s'", 'new': "return $source; (string) preg_replace_callback('/\\{\\{--.*?--\\}\\}/s'",
     'at_least': True,
     'expect': {SAMPLE_MISSES: '{{--', SAMPLE_CALLS: 'コメントの中は数えない', MARKS: ''}},
    {'id': 'S10', 'desc': '走査: コメントを落とすときに改行を残さない（行の番号がずれる）', 'file': PST, 'tests': SCAN,
     'old': 'str_repeat("\\n", substr_count($m[0], "\\n"))', 'new': "''",
     'expect': {SAMPLE_LINES: ''}},
    {'id': 'S11', 'desc': '走査: 部品のファイルを除かない', 'file': PST, 'tests': SCAN,
     'old': '            if ($path === self::COMPONENT) {\n                continue;\n            }\n', 'new': '',
     'expect': {MARKS: ''}, 'contains': {MARKS: 'components/pager.blade.php:'}},
    {'id': 'S12', 'desc': '走査: 呼び出しの先読みを外す（<x-pager-foo も数える）', 'file': PST, 'tests': SCAN,
     'old': '<x-pager(?=[\\s\\/>])', 'new': '<x-pager',
     'expect': {SAMPLE_CALLS: '似た名前の部品は数えない'}},
    {'id': 'S13', 'desc': '走査: 属性の中の引用符を読まない（> で切れる）', 'file': PST, 'tests': SCAN,
     'old': '((?:[^>"\\\']|"[^"]*"|\\\'[^\\\']*\\\')*)>', 'new': '([^>]*)>',
     'expect': {SAMPLE_CALLS: '属性の中の > を読み飛ばす'}},
    {'id': 'S14', 'desc': '走査: :paginator の前の区切りを見ない', 'file': PST, 'tests': SCAN,
     'old': "'/(?:^|\\s):paginator", 'new': "'/:paginator",
     'expect': {SAMPLE_CALLS: '名前の一部の :paginator'}},
    {'id': 'S15', 'desc': '走査: 空の :paginator を認める', 'file': PST, 'tests': SCAN,
     'old': ':paginator\\s*=\\s*"[^"]+"/', 'new': ':paginator\\s*=\\s*"[^"]*"/',
     'expect': {SAMPLE_CALLS: '空の :paginator'}},
    {'id': 'S16', 'desc': '走査: ビューの数の下限を 999 にする', 'file': PST, 'tests': SCAN,
     'old': 'MIN_VIEWS = 250;', 'new': 'MIN_VIEWS = 999;',
     'expect': {MARKS: '読んだビューが少なすぎる'}},

    # ---- ビュー ----
    {'id': 'V01', 'desc': 'ビュー: テナントの顧客を元の全部並べる形に戻す', 'file': 'resources/views/tenant/customers/index.blade.php', 'tests': [PST, PRT],
     'old': '        <x-pager :paginator="$customers" class="px-4 py-3 border-t border-gray-200" />\n', 'new': read('old_customers_block.txt'),
     'expect': {MARKS: '', CALLS: '31 か所しか拾えていない', PR + 'test_tenant_customers': 'ちょうど 1 つ'},
     'contains': {MARKS: 'tenant/customers/index.blade.php:'}},
    {'id': 'V02', 'desc': 'ビュー: DAD の発注者の :paginator からコロンを落とす', 'file': 'resources/views/dad/clients/index.blade.php', 'tests': [PST, PRT],
     'old': '<x-pager :paginator="$clients"', 'new': '<x-pager paginator="$clients"',
     'expect': {CALLS: ':paginator', PR + 'test_dad_clients': ''},
     'contains': {CALLS: 'dad/clients/index.blade.php:'}},
    {'id': 'V03', 'desc': 'ビュー: ZEAL の会員のページ送りを消す', 'file': 'resources/views/zeal/members/index.blade.php', 'tests': [PST, PRT],
     'old': '<x-pager :paginator="$members" class="px-4 py-3 border-t border-gray-200" />', 'new': '',
     'expect': {CALLS: '31 か所しか拾えていない'}},
    {'id': 'V04', 'desc': 'ビュー: DAD の発注者の外側の class を落とす', 'file': 'resources/views/dad/clients/index.blade.php', 'tests': [PST, PRT],
     'old': '<x-pager :paginator="$clients" class="mt-4" />', 'new': '<x-pager :paginator="$clients" />',
     'expect': {PR + 'test_dad_clients': ''}},
    {'id': 'V05', 'desc': 'ビュー: 住宅事業ダッシュボードの withQueryString を消す', 'file': 'resources/views/housing/_dashboard_contracted.blade.php', 'tests': [PST, HPC],
     'old': '    @php($paginated->withQueryString())\n', 'new': '',
     'expect': {'PageNumberClampTest::test_the_dashboard_pager_keeps_the_filter': '年度が残る'}},

    # ---- 決裁 ----
    {'id': 'A01', 'desc': '決裁: _pager を元の手作りに戻す', 'file': 'resources/views/approvals/_pager.blade.php', 'tests': ALL,
     'old': '<x-pager :paginator="$paginator" class="py-3 border-t border-gray-200" />\n', 'new': read('old_pager.txt'),
     'at_least': True,
     'expect': {PC + 'test_the_approval_wrapper_draws_the_component': '$pages', MARKS: ''},
     'contains': {MARKS: 'approvals/_pager.blade.php:'}},
    {'id': 'A02', 'desc': '決裁: _pager の外側の class を変える', 'file': 'resources/views/approvals/_pager.blade.php', 'tests': [PCT],
     'old': 'class="py-3 border-t border-gray-200" />', 'new': 'class="py-3" />',
     'expect': {PC + 'test_the_approval_wrapper_draws_the_component': ''}},
]
```

## 付録 C: ページ送りの塊を持つ 31 本（2026-10-13・`dc3ba7f3`）

決裁の 2 本（Task 3）: `resources/views/approvals/requests/index.blade.php`・`resources/views/approvals/admin/users/index.blade.php`

基幹の 29 本（Task 4。この順でスクリプトに渡す）:
```
resources/views/admin/users/index.blade.php
resources/views/buyers/index.blade.php
resources/views/dad/clients/index.blade.php
resources/views/dad/employees/index.blade.php
resources/views/dad/projects/index.blade.php
resources/views/dad/subcontractors/index.blade.php
resources/views/housing/_dashboard_contracted.blade.php
resources/views/housing/contracts/index.blade.php
resources/views/housing/contracts/select-building-property.blade.php
resources/views/housing/custom-orders/index.blade.php
resources/views/housing/properties/index.blade.php
resources/views/mansion/contracts/index.blade.php
resources/views/mansion/parking-contracts/index.blade.php
resources/views/mansion/properties/index.blade.php
resources/views/mansion/tenants/index.blade.php
resources/views/realestate/contracts/index.blade.php
resources/views/realestate/procurements/index.blade.php
resources/views/realestate/projects/index.blade.php
resources/views/realestate/suppliers/index.blade.php
resources/views/tenant/area-buildings/index.blade.php
resources/views/tenant/contracts/index.blade.php
resources/views/tenant/customers/index.blade.php
resources/views/tenant/inquiries/index.blade.php
resources/views/tenant/investments/index.blade.php
resources/views/tenant/properties/index.blade.php
resources/views/tenant/repairs/index.blade.php
resources/views/tenant/units/index.blade.php
resources/views/zeal/inquiries/index.blade.php
resources/views/zeal/members/index.blade.php
```
