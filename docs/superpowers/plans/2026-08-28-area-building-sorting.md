# 周辺ビル調査の並び替え ＋ 並び替え見出しの視認性 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 周辺ビル調査 `/tenant/area-buildings` の表を 7 列で並び替えられるようにし、既定の並び順をビル名の昇順に変え、あわせて「並び替えできる列が分かりにくい」（未使用の矢印が 1.41:1）を 3 一覧まとめて直す。

**Architecture:** 並び替えは既に PHP で全件を並べている `AreaBuildingListService::rows()` の中で行う（SQL は 1 行も触らない）。パラメータの解釈・3 状態・リンク URL は既存の `App\Support\ListSort` をそのまま使う。列のラベルと「向きの言い方」は**画面ごとに 1 つのマップ（`SORT_COLUMNS`）へ集約**し、見出し（`x-sortable-th`）と新しい「現在の並び順バー」（`x-sort-bar`）の両方がそこから引く。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（Alpine・JavaScript の追加は **1 行も無し**）/ Tailwind v4（`resources/css/app.css` に素の CSS を追記）/ PHPUnit（SQLite in-memory + `RefreshDatabase`）

**設計書:** @docs/superpowers/specs/2026-08-28-area-building-sorting-design.md
**前設計書:** @docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md（URL 仕様・3 状態・「—」末尾・ページャの規約の出どころ）
**モック:** @docs/mockups/tenant/sortable-header-affordance.html（**意匠の正本**。採用したのは「案A ＋ 案C のバー」で、**見出しの塗り分けは不採用**）

---

## 前提の確認（実装前に必ず読む）

| # | 事実 | 出典 |
|---|---|---|
| 1 | **DB 変更なし。** マイグレーションも raw SQL も書かない | 設計書 §7.2 / §11 |
| 2 | **JavaScript を 1 行も足さない。** 見出しも「解除」もただの `<a href>` | 設計書 §7.2 |
| 3 | 既定順は **`rows()` の `sortByDesc()` を削除するだけ**で実現する。`baseQuery()` が既に `ORDER BY area_buildings.name, area_buildings.id` で引いており、`map()` / `filter()` は順序を保つ | 設計書 §2.5 / §4.4 |
| 4 | 「—」を末尾へ回すのは **`partition` で分けて連結**。`[null 判定, 値]` の複合キーは**向きでフラグを反転しないと末尾に行かない** | 設計書 §4.2 |
| 5 | 入居率は **`VacancyRate::occupancyPercent()` を使わず空室率の符号を反転**して並べる（画面に並ぶ 2 つの数字と並び順が食い違う余地を作らない） | 設計書 §4.3 / Bug #46 |
| 6 | **「調査回はあるが総区画 0」の行がありうる。** そのとき `rate` だけ `null`（率は「—」）で、`operating` / `vacant` / `unknown` は **`0`**、`month` は実在する日付。**列ごとに末尾かどうかが違う**のが正しい | 設計書 §2.2 |
| 7 | `Arr::query()` は **null のキーを丸ごと捨てる**。リンク生成前に null を `''` へ正規化する | Bug #31 |
| 8 | `->links()` に戻さない。インライン番号付きページネーションのまま | Bug #24 |
| 9 | Blade コンポーネント属性に **`&quot;` を書かない**（本番の `view:cache` でだけ 500） | Bug #21 |
| 10 | **Blade のコメントの中に `<x-…>` や `@json` を書かない。** JS コメント（`//` と `/* */`）の中でも Blade が展開して `view:cache` を壊す。逃がすなら Blade コメント `{{-- --}}` | Bug #30 |
| 11 | テストは **SQLite（BINARY 照合）** で走る。**大文字小文字だけ／ひらがなカタカナだけが違う名前をテストデータに使わない**（MySQL は同一視し SQLite は区別する＝本番とテストで順が変わる） | 設計書 §4.4 |
| 12 | **`x-sortable-th` の `column` を打ち間違えると、その画面は 500 になる**（`$columns[$column]['label']` の未定義キー警告を Laravel が `ErrorException` に変えるため）。従来は黙って既定順へ落ちていた。これは**承知のうえの取引**で、静的な防波堤が `SortableListWiringTest` | 設計書 §7.1 |
| 13 | **`assertSessionHas*()` を呼ぶと、その後に描画した画面からエラー表示が消える。** 画面の描画を検査するテストではセッションに触らない | Bug #49 |

### この計画で決めた「設計書に無い」こと（実装者が迷わないための明示）

| 論点 | 決定 | 理由 |
|---|---|---|
| 物件一覧・部屋一覧の「向きの言い方」 | 面積＝広い順/狭い順、家賃・月額合計＝高い順/安い順、入居率＝高い順/低い順、賃料収入＝多い順/少ない順 | 設計書 §4.1 は周辺ビルの 7 列しか定めていない。バー（§6）は 3 画面に出るので残り 5 列にも要る。§4.1 の原則（率＝高い/低い、件数＝多い/少ない、日付＝新しい/古い）に合わせ、金額は「高い」、収入は「多い」を当てる |
| 並び替え中の下線を緑にする CSS の掛け方 | `th[aria-sort="…"] .sortable-th-label` | 状態を表す class を別に足すと、支援技術向けの `aria-sort` と見た目が別々に動く（片方だけ直す事故。Bug #41）。`aria-sort` は既存テストが固定済み |
| 「解除」リンクの取り出し方（テスト） | 既存の `ParsesSortLinks::sortLinkFor($html, '解除')` を流用 | 「解除」はラベルが `<a>` の直後に来るので専用ヘルパが要らない |

---

## File Structure

| | ファイル | 責務 |
|---|---|---|
| 新規 | `resources/views/components/sort-bar.blade.php` | 現在の並び順バー（ピル・解除・ヒント文）。**3 一覧が共有** |
| 新規 | `tests/Unit/Concerns/ParsesSortLinksTest.php` | テストヘルパ自身の検査（正規表現を**広げすぎていない**こと） |
| 新規 | `tests/Feature/Tenant/AreaBuildingListSortTest.php` | 周辺ビル調査の並び替え |
| 新規 | `tests/Feature/Tenant/SortBarTest.php` | バー（3 一覧） |
| 新規 | `tests/Feature/SortAffordanceTest.php` | 案A の意匠（矢印の色・ラベルの span・`app.css` の点線下線） |
| 変更 | `app/Support/ListSort.php` | `clearUrl()` を追加（URL 組み立ての重複も 1 箇所へ） |
| 変更 | `app/Services/Tenant/AreaBuildingListService.php` | `SORT_COLUMNS` ＋ `applySort()` ＋ `sortValue()`。**既定順を `sortByDesc()` の削除でビル名昇順へ** |
| 変更 | `app/Http/Controllers/Tenant/AreaBuildingController.php` | `$sort` を作って `rows()` と view へ渡す ＋ **`mapUnlocated()` をビル名昇順で固定** |
| 変更 | `app/Http/Controllers/Tenant/UnitController.php` | `SORTABLE` → `SORT_COLUMNS`（public）＋ `$sortColumns` を view へ |
| 変更 | `app/Http/Controllers/Tenant/PropertyController.php` | 同上 |
| 変更 | `resources/views/components/sortable-th.blade.php` | `label` プロップ廃止 → `columns` から引く／ラベルを span で包む／未使用の矢印を `#6B7280` へ |
| 変更 | `resources/css/app.css` | `.sortable-th-label` の点線下線とホバー・並び替え中 |
| 変更 | `resources/views/tenant/area-buildings/index.blade.php` | 見出し 7 本 ＋ `x-sort-hidden` ＋ `x-sort-bar` |
| 変更 | `resources/views/tenant/units/index.blade.php` | 見出しの `label` → `columns` ＋ `x-sort-bar` |
| 変更 | `resources/views/tenant/properties/index.blade.php` | 同上 |
| 変更 | `tests/Concerns/ParsesSortLinks.php` | 正規表現を span 対応へ ＋ `thInnerFor()` 追加（`<th>` 抽出を 1 箇所に集約） |
| 変更 | `tests/Feature/SortableListWiringTest.php` | `column` がラベル表に載っていることを**全件分類**で固定（本数も固定） |
| 変更 | `tests/Feature/Tenant/AreaBuildingListTest.php` | 既定順テストを名前ごと書き換え ＋ `test_occupancy_bands` の嘘コメントを直す |
| 変更 | `tests/Feature/Tenant/AreaBuildingMapTabTest.php` | 未登録リストが**表の並び替えに追従しない**ことを固定 |

---

## Task 0: 作業用 worktree を用意する

**Files:** なし（環境準備のみ）

⚠ **main repo で `composer install` してはいけない。** dev 依存が入ると `./deploy.sh` が
`vendor/` を本番へ rsync してしまう（main repo に `vendor/bin/phpunit` が無いのは意図的）。
テストは必ず worktree で回す。

- [ ] **Step 1: worktree を作る**

⚠ `EnterWorktree` を使わないこと。既定の baseRef が `origin` で、ローカル `13.x` は
origin より 45 コミット先行しているため**本番に出ている変更を含まないツリー**から分岐する。

```bash
cd /Users/masanori/site/manage && git worktree add .claude/worktrees/area-building-sorting -b area-building-sorting HEAD
```

- [ ] **Step 2: 分岐元が正しいことを確認する**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/area-building-sorting merge-base --is-ancestor 13.x HEAD && echo "OK: 13.x を含んでいる"
```

Expected: `OK: 13.x を含んでいる`

- [ ] **Step 3: vendor と .env を実体コピーする**

⚠ **symlink にしないこと。** `vendor/composer/autoload_psr4.php` の `$baseDir` が
symlink をたどって元の worktree に解決され、**この worktree で書き換えたコードが一切読まれず
全部緑に見える**（Bug #50。2 人のレビュアーが独立に踏んだ）。

```bash
cp -a /Users/masanori/site/manage/.claude/worktrees/tenant-list-sorting/vendor /Users/masanori/site/manage/.claude/worktrees/area-building-sorting/vendor
cp -a /Users/masanori/site/manage/.claude/worktrees/tenant-list-sorting/.env /Users/masanori/site/manage/.claude/worktrees/area-building-sorting/.env
```

⚠ コピー元の worktree が既に片付けられていたら、代わりに worktree の中で `composer install` する
（**main repo では絶対にしない**）。

- [ ] **Step 4: 基準になるテスト結果を実測する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && vendor/bin/phpunit
```

Expected: `OK (997 tests, 6141 assertions)`

⚠ **引き継ぎメモの「6138 assertions」は古い。** 2026-08-28 に `13.x`（コードは `ebec4420` と
同一 ＝ 以降の 3 コミットは docs のみ）で実測し直した結果が **997 tests / 6141 assertions**。
以降の Task は「997 → N」と書くのでこの数を基準にする。

- [ ] **Step 5: 分岐直後のコピーが正しく効いていることを確認する**

⚠ Bug #50 の「隔離できているつもり」を潰すためのカナリア。**確実に赤くなるはずの変異**を
1 つ当てて、実際に赤くなることを見る。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting
perl -0pi -e 's/\Qreturn \$this->total_floors === null ? \E/return \$undefined_canary_variable ? /' app/Models/AreaBuilding.php
git diff --stat   # 非空であること（当たっていない変異は測定にならない）
vendor/bin/phpunit --filter AreaBuildingListTest 2>&1 | tail -5
git checkout -- app/Models/AreaBuilding.php
git status --porcelain   # 空に戻ること
```

Expected: 変異中は FAILURES（`Undefined variable $undefined_canary_variable`）、戻すと clean

**以降、すべてのコマンドは `/Users/masanori/site/manage/.claude/worktrees/area-building-sorting` を cwd として実行する。**

---

## Task 1: `ListSort::clearUrl()` — 並び順だけを解除する URL

**Files:**
- Modify: `app/Support/ListSort.php`
- Test: `tests/Unit/Support/ListSortTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ListSortTest.php` の末尾（`test_url_removes_sort_on_the_third_click()` の後、
クラスの閉じ括弧の前）に足す:

```php
    /**
     * 「解除」は**並び順だけ**を消し、絞り込みは残す（設計書 §6）。
     *
     * ⚠ フィルタごと初期化する「クリア」ボタンとは役割が違う。両方が同じ結果になるなら
     *   バーに解除を出す意味が無い。**フィルタが残ることを必ず対で見る。**
     */
    public function test_clear_url_removes_only_the_sort(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=occupancy&dir=desc&occupancy=under75&year=2026');

        $url = ListSort::clearUrl($request);

        $this->assertStringNotContainsString('sort=', $url, '並び順が消えていない');
        $this->assertStringNotContainsString('dir=', $url, '向きが消えていない');
        $this->assertStringContainsString('occupancy=under75', $url, '絞り込みまで消えている（「クリア」と区別が無くなる）');
        $this->assertStringContainsString('year=2026', $url, '絞り込みまで消えている');
    }

    /** 並べ替えを解除したら 1 ページ目へ戻す（url() と同じ規約。前設計書 §4.3-5） */
    public function test_clear_url_drops_the_page_parameter(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=vacancy&dir=asc&page=3');

        $this->assertStringNotContainsString('page=', ListSort::clearUrl($request));
    }

    /**
     * null の絞り込みを '' へ正規化してから組み立てる（Bug #31）。
     *
     * ⚠ 怠ると `?occupancy=` のような空の絞り込みが**解除リンクから丸ごと消える**
     *   （実測: Arr::query(['a'=>null,'b'=>'','c'=>'x']) === 'b=&c=x'）。
     */
    public function test_clear_url_keeps_a_null_filter_by_normalising_it_to_an_empty_string(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=vacancy');
        $request->query->set('occupancy', null);

        $this->assertStringContainsString('occupancy=', ListSort::clearUrl($request));
    }

    /** 絞り込みが 1 つも無ければ素の URL（`?` を付けない） */
    public function test_clear_url_returns_a_bare_url_when_nothing_else_remains(): void
    {
        $request = $this->request('/tenant/area-buildings?sort=vacancy&dir=desc');

        $this->assertSame('http://localhost/tenant/area-buildings', ListSort::clearUrl($request));
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter ListSortTest
```

Expected: FAIL（`Call to undefined method App\Support\ListSort::clearUrl()`）

- [ ] **Step 3: `clearUrl()` を実装する（URL 組み立ての重複も 1 箇所へ寄せる）**

`app/Support/ListSort.php` の `url()` の末尾 3 行を差し替え、`clearUrl()` と
`private static function buildUrl()` を足す。**`url()` の末尾**:

```php
        $queryString = Arr::query(array_map(fn ($value) => $value ?? '', $query));

        return $request->url() . ($queryString === '' ? '' : '?' . $queryString);
    }
```

を、こう置き換える:

```php
        return self::buildUrl($request, $query);
    }

    /**
     * 並び順だけを解除する URL（バーの「解除」。設計書 §6）。
     *
     * ⚠ **絞り込みは残す。** フィルタごと初期化する「クリア」ボタン（route(...) への素のリンク）
     *   とは役割が違う。両方が同じ結果になるなら、バーに解除を出す意味が無い。
     * ⚠ page も落とす。並びが変わるので 5 ページ目に居るのはおかしい（url() と同じ規約）。
     */
    public static function clearUrl(Request $request): string
    {
        $query = $request->query();
        unset($query['page'], $query['sort'], $query['dir']);

        return self::buildUrl($request, $query);
    }

    /**
     * クエリ配列から URL を組み立てる。
     *
     * ⚠ null の値は '' へ正規化してから Arr::query() に渡す。怠ると
     *   `?occupancy=` のような空の絞り込みが**リンクから丸ごと消える**
     *   （実測: Arr::query(['a'=>null,'b'=>'','c'=>'x']) === 'b=&c=x'。Bug #31）。
     * ⚠ url() と clearUrl() で**同じ正規化**を通すためにここに集約している。
     *   片方だけ直すと、見出しリンクと解除リンクで絞り込みの残り方が食い違う。
     *
     * @param  array<string, mixed>  $query
     */
    private static function buildUrl(Request $request, array $query): string
    {
        $queryString = Arr::query(array_map(fn ($value) => $value ?? '', $query));

        return $request->url() . ($queryString === '' ? '' : '?' . $queryString);
    }
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter ListSortTest
```

Expected: `OK (18 tests, ...)`（既存 14 本 ＋ 新規 4 本）

- [ ] **Step 5: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1001 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add app/Support/ListSort.php tests/Unit/Support/ListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 並び順だけを解除する URL を ListSort に足す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: テストヘルパ `ParsesSortLinks` を span 対応に広げる

**Files:**
- Modify: `tests/Concerns/ParsesSortLinks.php`
- Create: `tests/Unit/Concerns/ParsesSortLinksTest.php`

⚠ Task 4 でラベルを `<span class="sortable-th-label">` で包むと、現在の正規表現
（`<a …>` の**直後**にラベルが来ることを要求）が一致しなくなる。**先に広げておく。**
⚠ **広げすぎると意味が無い。** `.*?` にすると「別のリンクの中に偶然そのラベルがある」形にも
一致して壊れたリンクを掴む。許すのは `<span …>` **1 つだけ**で、それを両方向のテストで固定する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Concerns/ParsesSortLinksTest.php` を新規作成:

```php
<?php

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ParsesSortLinks;

/**
 * テストヘルパ自身の検査。
 *
 * ⚠ **ヘルパが緩むと、それを使う全テストの検出力が静かに落ちる。** 案A（設計書 §5）で
 *   ラベルを <span> で包むため正規表現を 1 トークン広げるので、
 *   **広げた方向と広げていない方向を対で固定する**（Bug #45 ②③ の「決め打ち／広すぎ」の両方）。
 *
 * ⚠ Laravel を起動しない（Illuminate の TestCase を継承しない）。この trait は
 *   PHPUnit のアサーションしか使っていない。
 */
class ParsesSortLinksTest extends TestCase
{
    use ParsesSortLinks;

    /** 素のラベル（span で包む前の形）も引き続き見つかること */
    public function test_it_finds_a_link_whose_label_is_bare(): void
    {
        $this->assertSame('/x?sort=area', $this->sortLinkFor('<a href="/x?sort=area">面積</a>', '面積'));
    }

    /** span で包まれたラベル（案A の形）も見つかること */
    public function test_it_finds_a_link_whose_label_is_wrapped_in_a_span(): void
    {
        $html = '<a href="/x?sort=area" class="sortable-th-link"><span class="sortable-th-label">面積</span><span>▼</span></a>';

        $this->assertSame('/x?sort=area', $this->sortLinkFor($html, '面積'));
    }

    /** href の HTML エンティティをほどくこと（&amp; が生の & に戻る） */
    public function test_it_decodes_entities_in_the_href(): void
    {
        $html = '<a href="/x?sort=area&amp;dir=desc"><span>面積</span></a>';

        $this->assertSame('/x?sort=area&dir=desc', $this->sortLinkFor($html, '面積'));
    }

    /**
     * **広げすぎていないことの証明①**: 許すのは <span> だけ。
     *
     * ⚠ 任意のタグを許す（`.*?`）形にすると、矢印の svg を先に置いた壊れた見出しも
     *   通ってしまう。コンポーネントは「ラベル → 矢印」の順であることが規約
     *   （sortable-th.blade.php の docblock）。
     */
    public function test_it_refuses_a_link_that_puts_another_element_before_the_label(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->sortLinkFor('<a href="/x"><svg></svg>面積</a>', '面積');
    }

    /** **広げすぎていないことの証明②**: 別のラベルのリンクを掴まないこと */
    public function test_it_refuses_a_link_that_carries_a_different_label(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->sortLinkFor('<a href="/x"><span>家賃</span></a>', '面積');
    }

    /** <th> の中身をそのまま返すヘルパ（案A の矢印の色を列ごとに測るのに使う） */
    public function test_th_inner_returns_the_cell_contents(): void
    {
        $html = '<th aria-sort="none"><a href="/x"><span class="sortable-th-label">面積</span></a></th>'
              . '<th aria-sort="descending"><a href="/y"><span class="sortable-th-label">家賃</span></a></th>';

        $this->assertStringContainsString('href="/y"', $this->thInnerFor($html, '家賃'));
        $this->assertStringNotContainsString('href="/x"', $this->thInnerFor($html, '家賃'), '別の列のセルを掴んでいる');
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter ParsesSortLinksTest
```

Expected: FAIL（span 版が見つからない ＋ `thInnerFor()` が未定義）

- [ ] **Step 3: `ParsesSortLinks` を書き換える**

`tests/Concerns/ParsesSortLinks.php` を丸ごと下記にする（`<th>` の抽出を
`sortableHeaderCell()` に集約したので、同じループの 3 つ目のコピーを作らずに `thInnerFor()` を足せる）:

```php
<?php

namespace Tests\Concerns;

/**
 * 描画された並び替え見出しのリンクを取り出す（Bug #47 の往復を並び替えに適用したもの）。
 *
 * ⚠ URL を自分で組み立てると、**リンクが壊れていてもテストは緑になる**。
 *   コントローラが正しくても見出しの href が間違っていれば画面は動かないので、
 *   画面が実際に描画した href をそのまま辿ること。
 *
 * ⚠ 部屋一覧・物件一覧・周辺ビル調査の 3 つが使う。**複製しないこと**（片方だけ直す事故が起きる）。
 */
trait ParsesSortLinks
{
    /**
     * ラベルを持つ並び替えリンクの href。
     *
     * ⚠ ラベルは `<span class="sortable-th-label">` で包まれている（案A の点線下線を
     *   ラベルだけに掛けるため。設計書 §5）。**任意のタグを許すように広げないこと** ——
     *   `.*?` にすると「矢印を先に置いた壊れた見出し」や「別のリンクの中に偶然その文字がある」形にも
     *   一致する。許すのは `<span …>` **1 つだけ**で、両方向を ParsesSortLinksTest が固定している。
     */
    protected function sortLinkFor(string $html, string $label): string
    {
        $pattern = '/<a\b[^>]*\bhref="([^"]*)"[^>]*>\s*(?:<span\b[^>]*>\s*)?' . preg_quote($label, '/') . '\s*</u';

        $this->assertMatchesRegularExpression($pattern, $html, "「{$label}」の並び替えリンクが見つからない");
        preg_match($pattern, $html, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * ラベルを含む見出しセルの aria-sort を返す。属性が無ければ 'none'。
     *
     * ⚠ ページ全体に対する assertStringContainsString('aria-sort="descending"') では
     *   **3 列すべてに descending を出す変異が緑のまま通る**（実測済み）。
     *   「どの列に載っているか」を見るにはセル単位で切り出す必要がある。
     */
    protected function ariaSortFor(string $html, string $label): string
    {
        [$attributes] = $this->sortableHeaderCell($html, $label);

        return preg_match('/\baria-sort="([a-z]+)"/', $attributes, $matches) ? $matches[1] : 'none';
    }

    /**
     * ラベルを含む見出しセルの style 属性を返す。属性が無ければ空文字。
     *
     * ⚠ ページ全体に対する assertMatchesRegularExpression では、**同じ値の <th> が
     *   1 つでも残っていれば一致する**ので「一覧あたり 1 列」しか守れない（実測済み:
     *   2 列とも壊すと赤・1 列だけ壊すと 997 本すべて緑）。列ごとに切り出して見ること。
     */
    protected function thStyleFor(string $html, string $label): string
    {
        [$attributes] = $this->sortableHeaderCell($html, $label);

        return preg_match('/\bstyle="([^"]*)"/', $attributes, $matches) ? $matches[1] : '';
    }

    /**
     * ラベルを含む見出しセルの**中身**を返す（矢印の色を列ごとに測るのに使う）。
     *
     * ⚠ 矢印の色をページ全体で見てはいけない。3 列のうち 1 列だけ壊した変異が
     *   「別の列に同じ色が残っている」ことで緑になる（thStyleFor と同じ理屈）。
     */
    protected function thInnerFor(string $html, string $label): string
    {
        [, $inner] = $this->sortableHeaderCell($html, $label);

        return $inner;
    }

    /**
     * ラベルを含む `<th>` を切り出して [属性, 中身] を返す。
     *
     * ⚠ 境界を `(?:^|>)` … `(?:<|$)` にしてあるのは意図。素の部分一致だと
     *   「家賃」が「家賃収入」に誤マッチするが、`>ラベル<` だけに絞ると
     *   **並び替え不可の素の `<th>敷金</th>` に一致しなくなる**（$inner は `<th>` の
     *   中身だけ ＝ `敷金` で、`>` も `<` も含まないため）。実測で 4 通り確認済み:
     *   素の <th>敷金</th> ○ / <a>面積<span> ○ / 「家賃」→「家賃収入」× / 「面積」→「面積合計」×
     *
     * @return array{0: string, 1: string} [<th> の属性, <th> の中身]
     */
    private function sortableHeaderCell(string $html, string $label): array
    {
        preg_match_all('/<th\b([^>]*)>(.*?)<\/th>/su', $html, $cells, PREG_SET_ORDER);

        foreach ($cells as [, $attributes, $inner]) {
            if (! preg_match('/(?:^|>)\s*' . preg_quote($label, '/') . '\s*(?:<|$)/u', $inner)) {
                continue;
            }

            return [$attributes, $inner];
        }

        $this->fail("「{$label}」の見出しセルが見つからない");
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter ParsesSortLinksTest
```

Expected: `OK (6 tests, ...)`

- [ ] **Step 5: 既存テストが壊れていないことを確認する**

```bash
vendor/bin/phpunit
```

Expected: `OK (1007 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add tests/Concerns/ParsesSortLinks.php tests/Unit/Concerns/ParsesSortLinksTest.php
git commit -m "$(cat <<'EOF'
test(tenant): 並び替えリンクの抽出を span 付きラベルに対応させる

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: ラベルを画面ごとの 1 つのマップ（`SORT_COLUMNS`）へ集約する

**Files:**
- Modify: `app/Http/Controllers/Tenant/UnitController.php`
- Modify: `app/Http/Controllers/Tenant/PropertyController.php`
- Modify: `resources/views/components/sortable-th.blade.php`
- Modify: `resources/views/tenant/units/index.blade.php`
- Modify: `resources/views/tenant/properties/index.blade.php`
- Test: `tests/Feature/SortableListWiringTest.php`

⚠ 見出し（§5）とバー（§6）が同じ列名を出すので、**文字列を 2 箇所に置かない**（Bug #41 / #46）。
⚠ 代償: ビューを見ただけでは列の日本語が読めなくなり、`column` を打ち間違えるとその画面が
500 になる。これは承知のうえの取引で、Step 1 の走査テストが静的な防波堤になる（設計書 §7.1）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/SortableListWiringTest.php` を丸ごと下記にする（既存の 1 本は残し、2 本目を足す）:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\Tenant\PropertyController;
use App\Http\Controllers\Tenant\UnitController;
use Tests\TestCase;

/**
 * 並び替え見出しを持つ一覧の配線（設計書 §7.1）。
 *
 * ⚠ **全件分類**（Bug #45）。「直したビューを配列に並べる」形にすると、
 *   新しい一覧が無検査のまま増えて永遠に緑になる。resources/views を機械的に
 *   走査し、<x-sortable-th を持つビューを**全部**拾って検査する。
 * ⚠ **コメントを落としてから判定する**（Bug #42 ②と同型）。`sortable-th.blade.php`
 *   自身の docblock に使い方の例として「<x-sortable-th column="area" …」という
 *   リテラル文字列があり、素の str_contains だと**コンポーネント定義ファイル自身**が
 *   「並び替え見出しを持つビュー」と誤判定されて false-fail する（実測済み）。
 */
class SortableListWiringTest extends TestCase
{
    /**
     * 見出しの column を照合するラベル表と、その画面の見出しの本数。
     *
     * ⚠ **ここに無いビューが走査で見つかったら落とす**（列挙ではなく分類。Bug #45 ①）。
     * ⚠ 本数も固定する。走査が空振りして緑になる事故を防ぐ（Bug #45）。
     */
    private const SORT_COLUMN_SOURCES = [
        'tenant/properties/index.blade.php' => [PropertyController::class, 2],
        'tenant/units/index.blade.php'      => [UnitController::class, 3],
    ];

    public function test_every_sortable_list_carries_the_sort_in_its_filter_form(): void
    {
        $scanned = 0;
        $sortable = [];

        foreach ($this->bladeFiles() as $relative => $source) {
            $scanned++;

            if (! str_contains($source, '<x-sortable-th')) {
                continue;
            }

            $sortable[] = $relative;

            $this->assertStringContainsString(
                '<x-sort-hidden',
                $source,
                "{$relative} は並び替え見出しを持つのに、並び順を持ち回す hidden が無い"
            );
        }

        // ⚠ 走査が空振りして緑になる事故を防ぐ（Bug #45）
        $this->assertGreaterThan(100, $scanned, 'Blade の走査が空振りしている');
        $this->assertNotEmpty($sortable, '並び替え見出しを持つビューが 1 本も見つからない');
    }

    /**
     * 見出しの column が、その画面のラベル表に全部載っていること。
     *
     * ⚠ `column` を打ち間違えると `sortable-th` が `$columns[$column]['label']` の
     *   未定義キーを引き、Laravel が警告を ErrorException に変えて**その画面が 500 になる**。
     *   これはその静的な防波堤（設計書 §7.1）。
     * ⚠ `label="…"` の書き残しも落とす。プロップを廃止したので、残っていても
     *   **属性バッグへ素通りして黙って無視される**（`<th label="面積">` が出るだけ）。
     */
    public function test_every_sortable_header_names_a_column_that_exists_in_its_label_map(): void
    {
        $found = [];

        foreach ($this->bladeFiles() as $relative => $source) {
            if (! str_contains($source, '<x-sortable-th')) {
                continue;
            }

            $found[] = $relative;

            $this->assertArrayHasKey(
                $relative,
                self::SORT_COLUMN_SOURCES,
                "{$relative} は並び替え見出しを持つのに、ラベル表との対応が登録されていない"
            );

            [$class, $expectedCount] = self::SORT_COLUMN_SOURCES[$relative];
            $keys = array_keys($class::SORT_COLUMNS);

            preg_match_all('/<x-sortable-th\b[^>]*>/s', $source, $tags);
            $this->assertCount($expectedCount, $tags[0], "{$relative} の並び替え見出しの本数が変わっている");

            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression('/\bcolumn="([a-z_]+)"/', $tag, "column が無い見出しがある: {$tag}");
                preg_match('/\bcolumn="([a-z_]+)"/', $tag, $matches);

                $this->assertContains(
                    $matches[1],
                    $keys,
                    "{$relative} の column=\"{$matches[1]}\" が {$class} の SORT_COLUMNS に無い（この画面は 500 になる）"
                );
                $this->assertStringContainsString(':columns="', $tag, "{$relative} の見出しがラベル表を受け取っていない: {$tag}");
                $this->assertStringNotContainsString(' label="', $tag, "{$relative} に label プロップの書き残しがある（黙って無視される）: {$tag}");
            }
        }

        $this->assertEqualsCanonicalizing(
            array_keys(self::SORT_COLUMN_SOURCES),
            $found,
            'ラベル表に登録済みのビューが走査で見つからない（ビューを消したか、表が古い）'
        );
    }

    /**
     * resources/views の Blade を「相対パス => コメント除去済みソース」で返す。
     *
     * ⚠ ファイル名だけをキーにしないこと。3 一覧とも `index.blade.php` で衝突する。
     *
     * @return array<string, string>
     */
    private function bladeFiles(): array
    {
        $root = resource_path('views') . DIRECTORY_SEPARATOR;
        $out = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace($root, '', $file->getPathname());
            $out[$relative] = $this->withoutComments(file_get_contents($file->getPathname()));
        }

        return $out;
    }

    /**
     * Blade コメント・JS ブロックコメント・JS 行コメントを落とす
     * （`tests/Feature/Tenant/AreaBuildingMapTabTest.php::withoutComments()` と同じ実装。
     *   ⚠ 行頭アンカーを外すと `https://` まで消える）。
     */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^[ \t]*//.*$#m', '', $source);
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter SortableListWiringTest
```

Expected: FAIL（`Undefined constant UnitController::SORT_COLUMNS`）

- [ ] **Step 3: `UnitController` のラベル表を作る**

`app/Http/Controllers/Tenant/UnitController.php` の `private const SORTABLE = [...]` を
docblock ごと下記に置き換える:

```php
    /**
     * 部屋一覧で並び替えを許す列。
     *
     * ⚠ **許可リストもラベルもここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡し、
     *   ビュー（見出しとバー）にはこの配列そのものを渡す。許可リスト・SQL 式・日本語ラベルを
     *   別々のリテラルで持つと、キーを足して片方を忘れたときに一覧が 500 になる。しかも
     *   「不正なキーを投げる」テストでは**この向きの取り違えを原理的に検出できない**
     *   （不正キーは既定順に落ちるだけなので）。
     *
     * ⚠ `desc` / `asc` は**バーに出る「向きの言い方」**（設計書 §6）。列ごとに語が違う
     *   （率は「高い/低い」、件数は「多い/少ない」、日付は「新しい/古い」）。
     *
     * ⚠ `nullsLast` ＝ 「—」を末尾へ回すかは**画面に「—」と出る列だけ**（前設計書 §4.4）。
     *   units.rent は nullable だが、ビューは number_format(null) で **「0円」**と描画するので、
     *   末尾へ飛ばさず COALESCE で 0 として並べる。ここを揃え損なうと
     *   「画面は 0円 なのにその行だけ末尾に飛ぶ」という食い違いになる（Bug #41 / #46）。
     */
    public const SORT_COLUMNS = [
        // NULL は画面で「—」→ 末尾へ
        'area'    => ['label' => '面積',     'desc' => '広い順', 'asc' => '狭い順', 'expr' => 'units.area_tsubo', 'nullsLast' => true],
        // NULL は画面で「0円」→ 0 として
        'rent'    => ['label' => '家賃',     'desc' => '高い順', 'asc' => '安い順', 'expr' => 'COALESCE(units.rent, 0)', 'nullsLast' => false],
        // COALESCE 済みで NULL になりえない
        'monthly' => ['label' => '月額合計', 'desc' => '高い順', 'asc' => '安い順', 'expr' => Unit::MONTHLY_TOTAL_SQL, 'nullsLast' => false],
    ];
```

`index()` の `$sort = ListSort::fromRequest($request, array_keys(self::SORTABLE));` を:

```php
        $sort = ListSort::fromRequest($request, array_keys(self::SORT_COLUMNS));
```

`applySort()` の `[$expression, $nullsLast] = self::SORTABLE[$sort->key];` を:

```php
            ['expr' => $expression, 'nullsLast' => $nullsLast] = self::SORT_COLUMNS[$sort->key];
```

`index()` の `return view(...)` の直前に 1 行足し、view へ渡す:

```php
        $sortColumns = self::SORT_COLUMNS;

        return view('tenant.units.index', compact('units', 'properties', 'propertyIdsForJs', 'sort', 'sortColumns'));
```

- [ ] **Step 4: `PropertyController` のラベル表を作る**

`app/Http/Controllers/Tenant/PropertyController.php` の `private const SORTABLE = [...]` を
docblock ごと下記に置き換える:

```php
    /**
     * 物件一覧で並び替えを許す列。
     *
     * ⚠ **許可リストもラベルもここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡し、
     *   ビュー（見出しとバー）にはこの配列そのものを渡す。三項演算子で書くと、
     *   キーを足したときに**黙って賃料収入で並んでしまう**（落ちないので画面から気づけない）。
     *
     * ⚠ `desc` / `asc` は**バーに出る「向きの言い方」**（設計書 §6）。
     * ⚠ `attribute` は Property に生えるメモリ上の属性（実カラムではない）。
     */
    public const SORT_COLUMNS = [
        'occupancy' => ['label' => '入居率',   'desc' => '高い順', 'asc' => '低い順',   'attribute' => 'occupancy_rate'],
        'income'    => ['label' => '賃料収入', 'desc' => '多い順', 'asc' => '少ない順', 'attribute' => 'rental_income'],
    ];
```

`index()` の許可リスト:

```php
        $sort = ListSort::fromRequest($request, array_keys(self::SORT_COLUMNS));
```

`sortProperties()` の `$field = self::SORTABLE[$sort->key];` を:

```php
        $field = self::SORT_COLUMNS[$sort->key]['attribute'];
```

`index()` の `return view('tenant.properties.index', compact('properties', 'sort'));` を:

```php
        $sortColumns = self::SORT_COLUMNS;

        return view('tenant.properties.index', compact('properties', 'sort', 'sortColumns'));
```

- [ ] **Step 5: `x-sortable-th` を `columns` から引く形にする**

`resources/views/components/sortable-th.blade.php` の docblock 冒頭 6 行と `@props` / `@php` を
下記に置き換える（`<a>` 以下の本体は Task 4 まで触らない）:

```blade
{{-- 並び替えできる列見出し（前設計書 §4.2 / §5.6、設計書 2026-08-28 §7.1）

使い方:
  <x-sortable-th column="area" :sort="$sort" :columns="$sortColumns" align="right" link-style="padding: 14px 20px;" />
  <x-sortable-th column="occupancy" :sort="$sort" :columns="$sortColumns" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />

props:
  column     … ?sort に載るキー（コントローラの許可リストと揃える）
  columns    … その画面の SORT_COLUMNS（日本語ラベルと「向きの言い方」）
  sort       … App\Support\ListSort|null（コントローラから渡す）
  align      … left | center | right（既定 center）。<th> の text-align と <a> の justify-content
  linkClass  … <a> に足すクラス。**パディングはここ**（Tailwind の responsive を使いたい画面用）
  linkStyle  … <a> に足す inline style。**パディングはここ**（inline style で組まれた画面用）

⚠ **ラベルはここに書かない。** バー（x-sort-bar）が同じ列名を出すので、
   2 箇所に文字列を置くと片方だけ直す事故が起きる（Bug #41 / #46）。
⚠ column を打ち間違えると `$columns[$column]` が未定義になり、Laravel が警告を
   ErrorException へ変えて**この画面が 500 になる**。黙って既定順へ落ちるより良い
   （設計書 §7.1）。配線は SortableListWiringTest が静的に守っている。
⚠ 属性式に &quot; を書かないこと。本番の view:cache でだけ 500 になる（Bug #21）。
⚠ パディングは <th> ではなく中の <a> に載せる。**見出しセル全体を押せるようにするため**で、
   <th> 側に残すと文字の上しか反応しない。HTML を見ても分からないので画面で確かめる（Bug #43）。
⚠ **<a> の中は「ラベル → 矢印」の順にする。** テストの sortLinkFor() が
   <a …> の直後（span 1 つは可）にラベルが来ることを要求しているので、矢印を先に置くと
   リンクを見つけられない。
⚠ JS は 1 行も使わない。ただのリンク。
⚠ `color` は inline style なので `hover:text-*` / `focus:text-*` は**効かない**（inline が勝つ）。
   文字色を状態で変えたいなら inline 側を CSS 変数にするか app.css へ逃がすこと。
⚠ <a> の高さは自分の content + padding で決まり、行の高さには追従しない。
   将来どこかの見出しが 2 行になると、並び替え可能な列だけ当たり判定が縮む（HTML では見えない）。
--}}
@props([
    'column',
    'columns',
    'sort' => null,
    'align' => 'center',
    'linkClass' => '',
    'linkStyle' => '',
])
@php
    $label = $columns[$column]['label'];
    $state = \App\Support\ListSort::stateOf($sort, $column);
```

（`$ariaSort` 以下の `@php` ブロックの残りはそのまま。`{{ $label }}` の行も Task 4 まで据え置き。）

- [ ] **Step 6: 既存 2 ビューの見出しから `label` を外して `columns` を渡す**

`resources/views/tenant/units/index.blade.php`:

```blade
                            <x-sortable-th column="area" :sort="$sort" :columns="$sortColumns" align="right" link-style="padding: 14px 20px;" />
```
```blade
                            <x-sortable-th column="rent" :sort="$sort" :columns="$sortColumns" align="center" link-style="padding: 14px 20px;" />
```
```blade
                            <x-sortable-th column="monthly" :sort="$sort" :columns="$sortColumns" align="center" link-style="padding: 14px 20px;" />
```

`resources/views/tenant/properties/index.blade.php`:

```blade
                            <x-sortable-th column="occupancy" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="income" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
```

- [ ] **Step 7: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter "SortableListWiringTest|UnitListSortTest|PropertyListSortTest"
```

Expected: 全て PASS

⚠ 既存 2 本の並び替えテストは**描画されたラベル**を見ているので、ラベルの出どころが
変わっても緑のままであるべき。ここが赤くなったら `SORT_COLUMNS` のラベル文字列が
元の `label="…"` と違う。

- [ ] **Step 8: コンパイル済みビューを lint する**

⚠ `view:cache` は「成功」と表示してもコンパイル結果を lint しない（Bug #26 / #30）。

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 9: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1008 tests, ...)`

- [ ] **Step 10: コミット**

```bash
git add app/Http/Controllers/Tenant/UnitController.php app/Http/Controllers/Tenant/PropertyController.php resources/views/components/sortable-th.blade.php resources/views/tenant/units/index.blade.php resources/views/tenant/properties/index.blade.php tests/Feature/SortableListWiringTest.php
git commit -m "$(cat <<'EOF'
refactor(tenant): 並び替え列のラベルを画面ごとの 1 つのマップへ集約する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 案A — 未使用の矢印を見えるようにし、ラベルに点線下線を引く

**Files:**
- Modify: `resources/views/components/sortable-th.blade.php`
- Modify: `resources/css/app.css`
- Create: `tests/Feature/SortAffordanceTest.php`

⚠ 「並び替えできる列が分かりにくい」の実体は、未使用の ⇅ が `#D1D5DB` ＝ 見出し背景 `#F9FAFB`
に対して **1.41:1**（UI 部品の要件 3:1 を大きく下回る）ことにある。`#6B7280` にすると **4.63:1**。
⚠ **3 一覧すべてに同時に効く**（周辺ビル調査は Task 7 で見出しを持つ）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/SortAffordanceTest.php` を新規作成:

```php
<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * 並び替え見出しの意匠（設計書 2026-08-28 §5 / モック 案A）。
 *
 * ⚠ **これは「見えるか」の証明ではない。** 色とクラスが**意図した場所に載っていること**
 *   しか測れない。実際に見えるか・点線がラベルだけに掛かっているかは
 *   実ブラウザで見る（Task 11。Bug #28 / #43 / #51 と同型）。
 * ⚠ 行は要らない。見出しはデータが 0 件でも描画されるので、物件を 1 件も作らない。
 */
class SortAffordanceTest extends TestCase
{
    use ParsesSortLinks;
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 未使用の矢印は #6B7280（4.63:1）、並び替え中は #059669。
     *
     * ⚠ **列ごとに切り出して見る。** ページ全体で見ると、別の列に同じ色が残っているだけで
     *   緑になる（thStyleFor の docblock に実測済みの同型の罠）。
     */
    public function test_the_idle_arrow_is_dark_enough_to_be_seen(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'occupancy', 'dir' => 'desc']))
            ->getContent();

        $idle = $this->thInnerFor($html, '賃料収入');
        $this->assertStringContainsString('#6B7280', $idle, '未使用の矢印が薄いまま（1.41:1 の #D1D5DB に戻っている）');
        $this->assertStringNotContainsString('#D1D5DB', $idle, '未使用の矢印に旧色が残っている');

        $active = $this->thInnerFor($html, '入居率');
        $this->assertStringContainsString('#059669', $active, '並び替え中の矢印が緑でない');
    }

    /** ラベルが span で包まれていること（点線下線をラベルだけに掛けるため） */
    public function test_the_label_is_wrapped_so_the_underline_can_target_the_text_only(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index'))
            ->getContent();

        $this->assertStringContainsString(
            '<span class="sortable-th-label">入居率</span>',
            $this->thInnerFor($html, '入居率'),
            'ラベルが sortable-th-label で包まれていない（下線が矢印にも掛かる）'
        );
    }

    /**
     * 点線下線とその状態変化が app.css にあること。
     *
     * ⚠ **Tailwind クラスではないので Blade の走査では守れない。** CSS ファイルを直接見る。
     * ⚠ **コメントを落としてから測る。** 説明に色コードを書いてあるので、
     *   実体を消してもコメントに一致して緑になる（Bug #42 ②）。
     */
    public function test_the_stylesheet_underlines_the_label_and_marks_the_active_column(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));

        $this->assertMatchesRegularExpression(
            '/\.sortable-th-label\s*\{[^}]*text-decoration:\s*underline dotted #9CA3AF/s',
            $css,
            'ラベルの点線下線が無い'
        );
        $this->assertMatchesRegularExpression(
            '/\.sortable-th-label\s*\{[^}]*text-underline-offset:\s*4px/s',
            $css,
            '下線がラベルに近すぎる（オフセットが無い）'
        );
        $this->assertMatchesRegularExpression(
            '/\.sortable-th-link:hover \.sortable-th-label\s*\{[^}]*text-decoration-color:\s*#4B5563/s',
            $css,
            'ホバーで下線が濃くならない'
        );
        $this->assertMatchesRegularExpression(
            '/th\[aria-sort="ascending"\] \.sortable-th-label,\s*th\[aria-sort="descending"\] \.sortable-th-label\s*\{[^}]*text-decoration-color:\s*#059669/s',
            $css,
            '並び替え中の列で下線が緑にならない'
        );
    }

    /**
     * 並び替え中の緑は**ホバーより後**に置くこと。
     *
     * ⚠ 順序が逆だと、並び替え中の列にマウスを乗せた瞬間に下線がグレーへ落ち、
     *   「今どの列で並んでいるか」の手掛かりが消える。CSS は同じ詳細度なら後勝ち。
     */
    public function test_the_active_underline_rule_comes_after_the_hover_rule(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));

        $hover  = strpos($css, '.sortable-th-link:hover .sortable-th-label');
        $active = strpos($css, 'th[aria-sort="ascending"] .sortable-th-label');

        $this->assertNotFalse($hover);
        $this->assertNotFalse($active);
        $this->assertGreaterThan($hover, $active, '並び替え中の下線がホバーに負ける順序で書かれている');
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter SortAffordanceTest
```

Expected: FAIL（矢印が `#D1D5DB` のまま ＋ `.sortable-th-label` が無い）

- [ ] **Step 3: コンポーネントの矢印の色とラベルの包み方を直す**

`resources/views/components/sortable-th.blade.php` の `@php` ブロックの
`$iconColor = $state === null ? '#D1D5DB' : '#059669';` を:

```php
    // ⚠ #D1D5DB は見出し背景 #F9FAFB に対して 1.41:1 しかなく、UI 部品の 3:1 を大きく下回る。
    //   #6B7280 なら 4.63:1（設計書 §2.3 の実測表）。**手掛かりの本体はこの矢印**で、
    //   ラベルの点線下線は補強（app.css）。
    $iconColor = $state === null ? '#6B7280' : '#059669';
```

`<a>` の中の `{{ $label }}` の行を:

```blade
        <span class="sortable-th-label">{{ $label }}</span>
```

- [ ] **Step 4: `app.css` に点線下線を足す**

`resources/css/app.css` の `.sortable-th-link:focus-visible { … }` ブロックの**直後**、
`/* ===== スクロールヒント …… ===== */` の**前**に足す:

```css
/* 並び替えできる列であることの手掛かり（設計書 2026-08-28 §5 / モック 案A）。
   ⚠ **矢印の色は Blade 側の inline style にある**（#6B7280 / #059669）。color は inline が
      勝つのでここからは変えられない。下線の色は inline に無いのでここで状態を出し分ける。
   ⚠ 状態は <th> の aria-sort を見る。見た目用の class を別に足すと、支援技術向けの属性と
      見た目が別々に動く（片方だけ直す事故＝ Bug #41）。
   ⚠ 点線そのものは #9CA3AF ＝ 2.43:1 で UI 部品の 3:1 に届かない。これは承知のうえで、
      手掛かりの本体は 4.63:1 の矢印、下線は「文字がリンクである」ことの補強として扱う
      （濃くすると 10 列の見出しが騒がしくなる。モックで選ばれた濃さ）。
   ⚠ 検証は「HTML にクラスが出ているか」では不可能。実ブラウザで見ること（Bug #28 / #43 / #51）。 */
.sortable-th-label {
    text-decoration: underline dotted #9CA3AF;
    text-underline-offset: 4px;
    text-decoration-thickness: 1px;
}
.sortable-th-link:hover .sortable-th-label {
    text-decoration-color: #4B5563;
}
/* ⚠ ホバーより後に置くのが load-bearing。逆だと並び替え中の列にマウスを乗せた瞬間に
      下線がグレーへ落ち、「今どの列で並んでいるか」の手掛かりが消える。 */
th[aria-sort="ascending"] .sortable-th-label,
th[aria-sort="descending"] .sortable-th-label {
    text-decoration-color: #059669;
}
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter SortAffordanceTest
```

Expected: `OK (4 tests, ...)`

- [ ] **Step 6: 既存の並び替えテストが壊れていないことを確認する**

⚠ ここが Task 2（`sortLinkFor` の span 対応）が効いているかの答え合わせ。

```bash
vendor/bin/phpunit --filter "UnitListSortTest|PropertyListSortTest"
```

Expected: 全て PASS

- [ ] **Step 7: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1012 tests, ...)`

- [ ] **Step 8: コミット**

```bash
git add resources/views/components/sortable-th.blade.php resources/css/app.css tests/Feature/SortAffordanceTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 並び替えできる列の手掛かりを見えるようにする

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 周辺ビル調査の既定順をビル名の昇順へ変える

**Files:**
- Modify: `app/Services/Tenant/AreaBuildingListService.php`
- Test: `tests/Feature/Tenant/AreaBuildingListTest.php`

⚠ 利用者の明示の依頼（設計書 §1 / §4.4）。**「降順」と書かれていたが確認したら「昇順」だった。**
⚠ 従来の既定順（空室率の降順）は失われない —— Task 6 で空室率を並び替えできる列にするので、
見出しを 1 回押せば戻る。**その対のテストは Task 6 で足す。この 2 本は対で意味を持つので、
Task 6 を飛ばさないこと。**

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingListTest.php` の
`test_default_order_is_vacancy_rate_desc_with_unsurveyed_last()` を docblock ごと下記に置き換える
（**削除ではなく名前ごと書き換え**。「変えた」と「壊した」を区別できる形にする）:

```php
    /**
     * 既定の並び順はビル名の昇順（設計書 2026-08-28 §4.4。利用者の依頼で変更）。
     *
     * ⚠ 従来は空室率の降順・未調査は末尾だった。**その順は失われていない** ——
     *   空室率は並び替えできる列になったので、見出しを 1 回押せば戻る。
     *   その対の固定は AreaBuildingListSortTest::test_the_old_default_order_is_still_reachable()。
     *
     * ⚠ データは名前順と率順が**わざと食い違う**（率順なら う→い→え→あ）。
     *   揃えると「並べ替えを消しただけ」の変異が緑のまま通る。
     *
     * ⚠ 漢字は「読み」ではなく文字コード順に並ぶ（読みがな列が無い。設計書 §4.4）。
     *   かな 4 文字なので SQLite（BINARY）と MySQL（utf8mb4_unicode_ci）で順は一致する。
     */
    public function test_the_default_order_is_the_building_name_ascending(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);
        $this->makeSurvey($this->makeBuilding('え率0'), '2026-08-01', 8, 0);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $this->assertSame(['あ未調査', 'い率10', 'う率50', 'え率0'], $this->listedNames($response));
    }
```

同ファイルの `test_occupancy_bands()` の中のコメント
`// ⚠ 並びは従来どおり空室率の降順＝入居率の昇順` を下記に置き換える:

```php
        // ⚠ **このテストは並び順を守っていない。** 既定順がビル名の昇順になっても結果が同じで
        //   （'入居50' < '入居70' < '入居90' がたまたま率の降順と一致する）、
        //   **緑のままなので検出力がゼロ**。並び順の固定は
        //   test_the_default_order_is_the_building_name_ascending と AreaBuildingListSortTest が行う。
        //   ここが見ているのは**絞り込みの帯**だけ。
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter test_the_default_order_is_the_building_name_ascending
```

Expected: FAIL（`['う率50','い率10','え率0','あ未調査']` が返る）

- [ ] **Step 3: `rows()` から PHP 側の並べ替えを外す**

`app/Services/Tenant/AreaBuildingListService.php` の `rows()` を下記に置き換える
（docblock も差し替える）:

```php
    /**
     * 絞り込み済みの行（既定順 ＝ ビル名の昇順）。
     *
     * ⚠ **PHP 側で並べ替えない。** baseQuery() が
     *   `ORDER BY area_buildings.name, area_buildings.id` で引いており、
     *   map() / filter() は順序を保つので、**ここに並べ替えを書かないことがビル名の昇順**
     *   （設計書 2026-08-28 §4.4。利用者の依頼で従来の「空室率の降順」から変更）。
     *   従来の順は「空室率」の見出しを 1 回押せば戻る。
     *
     * @return Collection<int, array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, occupancy_label: string, rate_label: string}>
     */
    public function rows(Request $request): Collection
    {
        $occupancy = $request->input('occupancy');
        $year      = $request->input('year');

        return $this->baseQuery($request)
            ->get()
            ->map(fn (AreaBuilding $building) => $this->toRow($building))
            ->filter(fn (array $row) => $this->matchesYear($row, $year) && $this->matchesOccupancy($row, $occupancy))
            ->values();
    }
```

`baseQuery()` の末尾のコメントも実態に合わせる:

```php
        // ⚠ **これが既定順そのもの**（設計書 §4.4）。PHP 側は並べ替えないのでこの順が残る。
        //   並び替え中は同点のタイブレークとしても効く（PHP のソートは 8.0 以降 stable）。
        return $query->orderBy('area_buildings.name')->orderBy('area_buildings.id');
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingListTest
```

Expected: `OK (23 tests, ...)`

- [ ] **Step 5: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1012 tests, ...)`（本数は変わらない。既存 1 本を書き換えただけ）

⚠ ここで `AreaBuildingMapTabTest` が赤くなったら、地図タブのどこかが**旧既定順に依存**している。
その場合は落ちたテストの期待順を実態（ビル名の昇順）へ直し、**なぜ変わったか**をコメントに残す。

- [ ] **Step 6: コミット**

```bash
git add app/Services/Tenant/AreaBuildingListService.php tests/Feature/Tenant/AreaBuildingListTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 周辺ビル調査の既定の並び順をビル名の昇順にする

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 周辺ビル調査の並び替え（サービスとコントローラ）

**Files:**
- Modify: `app/Services/Tenant/AreaBuildingListService.php`
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`
- Create: `tests/Feature/Tenant/AreaBuildingListSortTest.php`

⚠ この Task では `?sort=` を**直接叩いて**検証する。画面が描画したリンクを辿る往復は Task 7。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingListSortTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;

/**
 * 周辺ビル調査の並び替え（設計書 2026-08-28 §4）。
 *
 * ⚠ **期待順は既定順（ビル名の昇順）と必ず食い違わせる。** 揃えると式を取り違えても
 *   緑になる（前設計書で実測済みの罠）。下の seedFourBuildings() は 7 列すべての降順が
 *   互いに違う並びになるように作ってある。
 *
 * ⚠ テストは SQLite（BINARY 照合）で走る。**大文字小文字だけ／ひらがなカタカナだけが違う
 *   名前を使わないこと**（MySQL は同一視し SQLite は区別するので本番と順が変わる。設計書 §4.4）。
 */
class AreaBuildingListSortTest extends AreaBuildingTestCase
{
    use ParsesSortLinks;
    use RefreshDatabase;

    /**
     * 7 列すべてで既定順と食い違う並びになるデータ。
     *
     * | 棟 | 総階数 | 営業 | 空き | 不明 | 空室率 | 入居率 | 最終調査 |
     * |----|-------|------|------|------|--------|--------|----------|
     * | A  |   3   |  5   |  3   |  5   | 61.5%  | 38.5%  | 2026-05  |
     * | B  |   8   |  2   |  7   |  1   | 80.0%  | 20.0%  | 2026-08  |
     * | C  |  12   |  9   |  0   |  2   | 18.1%  | 81.9%  | 2026-06  |
     * | D  |   6   |  7   |  12  |  3   | 68.1%  | 31.9%  | 2026-07  |
     *
     * ⚠ 空室率 = (空き + 不明) ÷ 総数 の 1/10% 単位切り捨て（VacancyRate::percent）。
     *   入居率はその裏返しで、和は必ず 100.0%（Bug #46）。
     */
    private function seedFourBuildings(): void
    {
        $a = $this->makeBuilding('Aビル', ['total_floors' => 3]);
        $this->makeSurvey($a, '2026-05-01', 5, 3, 5);

        $b = $this->makeBuilding('Bビル', ['total_floors' => 8]);
        $this->makeSurvey($b, '2026-08-01', 2, 7, 1);

        $c = $this->makeBuilding('Cビル', ['total_floors' => 12]);
        $this->makeSurvey($c, '2026-06-01', 9, 0, 2);

        $d = $this->makeBuilding('Dビル', ['total_floors' => 6]);
        $this->makeSurvey($d, '2026-07-01', 7, 12, 3);
    }

    /** 7 列それぞれが**自分の列の値**で並ぶこと（列を取り違える変異を検出する） */
    public function test_every_sortable_column_sorts_by_its_own_values(): void
    {
        $this->seedFourBuildings();
        $staff = $this->staff();

        $expected = [
            'floors'    => ['Cビル', 'Bビル', 'Dビル', 'Aビル'],
            'operating' => ['Cビル', 'Dビル', 'Aビル', 'Bビル'],
            'vacant'    => ['Dビル', 'Bビル', 'Aビル', 'Cビル'],
            'unknown'   => ['Aビル', 'Dビル', 'Cビル', 'Bビル'],
            'vacancy'   => ['Bビル', 'Dビル', 'Aビル', 'Cビル'],
            'occupancy' => ['Cビル', 'Aビル', 'Dビル', 'Bビル'],
            'month'     => ['Bビル', 'Dビル', 'Cビル', 'Aビル'],
        ];

        // ⚠ データが「検出力のある形」であること自体を先に固定する
        $default = ['Aビル', 'Bビル', 'Cビル', 'Dビル'];
        $this->assertSame($default, $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings')), '既定順が名前の昇順でない');
        foreach ($expected as $key => $names) {
            $this->assertNotSame($default, $names, "{$key} の期待順が既定順と同じデータになっている（並べ替えを消しても緑になる）");
        }
        $signatures = array_map(fn (array $names) => implode(',', $names), $expected);
        $this->assertCount(7, array_unique($signatures), '7 列の期待順に重複がある（列の取り違えを検出できない）');

        foreach ($expected as $key => $names) {
            $desc = $this->actingAs($staff)->get("/tenant/area-buildings?sort={$key}&dir=desc");
            $desc->assertOk();
            $this->assertSame($names, $this->listedNames($desc), "{$key} の降順が違う");

            $asc = $this->actingAs($staff)->get("/tenant/area-buildings?sort={$key}&dir=asc");
            $asc->assertOk();
            $this->assertSame(array_reverse($names), $this->listedNames($asc), "{$key} の昇順が降順の逆になっていない");
        }
    }

    /**
     * 入居率の降順と空室率の昇順は**完全に同じ並び**（設計書 §4.3）。
     *
     * ⚠ 入居率を VacancyRate::occupancyPercent() で別に計算して並べると、
     *   画面に並ぶ 2 つの数字と並び順が食い違う余地ができる（Bug #46）。
     *   実装は空室率の符号を反転しているので、これは**構造として**成り立つ。
     */
    public function test_the_occupancy_order_is_exactly_the_reverse_of_the_vacancy_order(): void
    {
        $this->seedFourBuildings();
        $this->makeBuilding('Eビル');   // 調査なし＝率は「—」

        $staff = $this->staff();

        $byOccupancyDesc = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=occupancy&dir=desc'));
        $byVacancyAsc    = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?sort=vacancy&dir=asc'));

        $this->assertCount(5, $byOccupancyDesc);
        $this->assertSame($byVacancyAsc, $byOccupancyDesc, '入居率と空室率で並びが食い違う（片方を別計算で出している。Bug #46）');
        $this->assertSame('Eビル', end($byOccupancyDesc), '率が「—」の棟が末尾に来ていない');
    }

    /**
     * 「—」は昇順でも降順でも末尾（前設計書 §4.3-2 / 設計書 §4.2）。
     *
     * ⚠ **「—」になる条件は列ごとに違う。** A は総階数だけ空（調査はある）、
     *   D は調査回がまるごと無い（総階数はある）。**同じ棟が列によって末尾だったり
     *   普通に並んだりする**のが正しい（設計書 §2.2）。
     * ⚠ `[null 判定, 値]` の複合キーで書くと、**向きでフラグを反転しないと末尾に行かない**。
     *   実装は partition で分けて連結している。
     */
    public function test_blank_values_stay_at_the_end_in_both_directions(): void
    {
        $a = $this->makeBuilding('Aビル');                          // 総階数 null
        $this->makeSurvey($a, '2026-08-01', 3, 1, 0);
        $b = $this->makeBuilding('Bビル', ['total_floors' => 4]);
        $this->makeSurvey($b, '2026-06-01', 4, 1, 0);
        $c = $this->makeBuilding('Cビル', ['total_floors' => 9]);
        $this->makeSurvey($c, '2026-07-01', 1, 9, 0);
        $this->makeBuilding('Dビル', ['total_floors' => 7]);          // 調査回なし

        $staff = $this->staff();
        $get = fn (string $q) => $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?' . $q));

        // 総階数: A だけが「—」
        $this->assertSame(['Cビル', 'Dビル', 'Bビル', 'Aビル'], $get('sort=floors&dir=desc'));
        $this->assertSame(['Bビル', 'Dビル', 'Cビル', 'Aビル'], $get('sort=floors&dir=asc'));

        // 営業: D だけが「—」（A は 3 で普通に並ぶ）
        $this->assertSame(['Bビル', 'Aビル', 'Cビル', 'Dビル'], $get('sort=operating&dir=desc'));
        $this->assertSame(['Cビル', 'Aビル', 'Bビル', 'Dビル'], $get('sort=operating&dir=asc'));

        // 最終調査: D だけが「—」
        $this->assertSame(['Aビル', 'Cビル', 'Bビル', 'Dビル'], $get('sort=month&dir=desc'));
        $this->assertSame(['Bビル', 'Cビル', 'Aビル', 'Dビル'], $get('sort=month&dir=asc'));
    }

    /**
     * 「調査回はあるが総区画 0」の棟は、**率でだけ**末尾（設計書 §2.2 / §8.1-4）。
     *
     * ⚠ 画面表示との一致がこのテストの本体。営業・空き・不明は「0」と出るので 0 として並び、
     *   入居率・空室率は「—」と出るので末尾へ回る。**揃える相手は NULL ではなく画面の表示**。
     */
    public function test_a_surveyed_building_with_no_units_is_blank_for_the_rates_only(): void
    {
        $zero = $this->makeBuilding('Cゼロ区画', ['total_floors' => 5]);
        $this->makeSurvey($zero, '2026-08-01', 0, 0, 0);           // 総区画 0 → 率だけ null
        $low = $this->makeBuilding('Aビル', ['total_floors' => 2]);
        $this->makeSurvey($low, '2026-06-01', 1, 9, 0);            // 空室率 90.0%
        $high = $this->makeBuilding('Bビル', ['total_floors' => 3]);
        $this->makeSurvey($high, '2026-07-01', 9, 1, 0);           // 空室率 10.0%

        $staff = $this->staff();

        // 画面の表示: 営業/空き/不明は「0」、入居率・空室率は「—」
        $row = collect($this->actingAs($staff)->get('/tenant/area-buildings')->viewData('rows')->items())
            ->first(fn (array $r) => $r['building']->name === 'Cゼロ区画');
        $this->assertSame(0, $row['operating'], '営業が 0 でなく null になっている（画面は「0」と出る）');
        $this->assertSame('—', $row['occupancy_label']);
        $this->assertSame('—', $row['rate_label']);

        $get = fn (string $q) => $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?' . $q));

        // 率では末尾（画面が「—」なので）
        $this->assertSame(['Aビル', 'Bビル', 'Cゼロ区画'], $get('sort=vacancy&dir=desc'));
        // 営業では 0 として普通に並ぶ。**昇順で先頭**（末尾へ回す実装なら最後に来る）
        $this->assertSame(['Cゼロ区画', 'Aビル', 'Bビル'], $get('sort=operating&dir=asc'));
        // 最終調査は実在するので普通に並ぶ
        $this->assertSame(['Cゼロ区画', 'Bビル', 'Aビル'], $get('sort=month&dir=desc'));
    }

    /**
     * 同点の中は既定順（＝ビル名の昇順）。
     *
     * ⚠ **id 順と名前順をわざと食い違わせる。** 揃えると、既定順への安定ソート依存を壊す変異が
     *   SQLite の返す id 順に救われて素通りする（Bug #52 の「真ん中の行が落ちるデータで書く」と
     *   同じ理屈）。これが無いと同点の行がページをまたいで重複／消失する（前設計書 §4.3-3）。
     */
    public function test_tied_rows_keep_the_building_name_order(): void
    {
        $this->makeBuilding('Zビル', ['total_floors' => 5]);
        $this->makeBuilding('Aビル', ['total_floors' => 5]);
        $this->makeBuilding('Mビル', ['total_floors' => 9]);

        $this->assertSame(
            ['Zビル', 'Aビル', 'Mビル'],
            AreaBuilding::orderBy('id')->pluck('name')->all(),
            'id 順と名前順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $this->assertSame(
            ['Mビル', 'Aビル', 'Zビル'],
            $this->listedNames($this->actingAs($this->staff())->get('/tenant/area-buildings?sort=floors&dir=desc'))
        );
    }

    /**
     * 旧既定順（空室率の降順・未調査は末尾）が**失われていない**こと。
     *
     * ⚠ Task 5 の test_the_default_order_is_the_building_name_ascending と**対で**意味を持つ。
     *   片方だけだと「変えた」と「壊した」が区別できない（設計書 §8.1.1）。
     * ⚠ データは既定順テストと同一。
     */
    public function test_the_old_default_order_is_still_reachable(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);
        $this->makeSurvey($this->makeBuilding('え率0'), '2026-08-01', 8, 0);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?sort=vacancy&dir=desc');

        $this->assertSame(['う率50', 'い率10', 'え率0', 'あ未調査'], $this->listedNames($response));
    }

    /**
     * 絞り込みと並び替えが共存し、ページをまたいでも全体が降順であること。
     *
     * ⚠ **1 ページ目だけでは測れない。** 1 ページ目の 20 件が降順に並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（前設計書 §3.1）。
     * ⚠ `?page=2` を自分で組み立てない。ページャの nextPageUrl() を辿ること（Bug #31）。
     */
    public function test_sorting_survives_filters_and_paging(): void
    {
        // 総階数は作成順と同じ向きに増やす。名前は 対象01…対象25 なので
        // **総階数の降順は名前の降順**＝既定順の逆になり、並べ替えを消すと必ず落ちる
        for ($i = 1; $i <= 25; $i++) {
            $b = $this->makeBuilding(sprintf('対象%02d', $i), ['total_floors' => $i]);
            $this->makeSurvey($b, '2026-08-01', 5, 5, 0);   // 空室率 50.0% → occupancy=under75 に入る
        }
        $out = $this->makeBuilding('対象外', ['total_floors' => 99]);
        $this->makeSurvey($out, '2026-08-01', 10, 0, 0);    // 満室 → under75 では外れる

        $staff = $this->staff();
        $url   = '/tenant/area-buildings?occupancy=under75&sort=floors&dir=desc';
        $names = [];
        $guard = 0;

        while ($url !== null) {
            $response = $this->actingAs($staff)->get($url);
            $response->assertOk();
            $names = array_merge($names, $this->listedNames($response));
            $url = $response->viewData('rows')->nextPageUrl();
            $this->assertLessThan(10, ++$guard, 'ページ送りが終わらない');
        }

        $this->assertCount(25, $names, '絞り込みが効いていない、または行が消えている');
        $this->assertCount(25, array_unique($names), 'ページ送りで行が重複している');
        $this->assertNotContains('対象外', $names, '並び替えで絞り込みが外れている');
        $this->assertSame('対象25', $names[0], '総階数の降順になっていない');
        $this->assertSame('対象01', end($names), 'ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）');
    }

    /** 不正な sort / dir は 500 にせず既定順へ落ちる（Bug #31） */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $this->seedFourBuildings();
        $default = ['Aビル', 'Bビル', 'Cビル', 'Dビル'];
        $staff = $this->staff();

        foreach ([
            '?sort=name',           // 許可リストに無い（ビル名は対象外。設計書 §4.1 / §10）
            '?sort[]=floors',       // 配列で来る
            '?sort=%3Cscript%3E',   // 手入力・古いブックマーク
            '?sort=',               // 空
        ] as $queryString) {
            $response = $this->actingAs($staff)->get('/tenant/area-buildings' . $queryString);
            $response->assertOk();
            $this->assertSame($default, $this->listedNames($response), "{$queryString} で既定順に落ちていない");
        }

        // dir だけ不正なら降順（前設計書 §4.2: 1 回目は降順）
        $response = $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=up');
        $this->assertSame(['Cビル', 'Bビル', 'Dビル', 'Aビル'], $this->listedNames($response));
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingListSortTest
```

Expected: FAIL（`?sort=` が無視され、全部が既定順で返る）

- [ ] **Step 3: サービスに並び替えを足す**

`app/Services/Tenant/AreaBuildingListService.php` の先頭の `use` に 1 行足す:

```php
use App\Support\ListSort;
```

クラス冒頭の定数群（`OCCUPANCY_OPTIONS` の後）に足す:

```php
    /**
     * 並び替えを許す列 → 日本語ラベルと「向きの言い方」（設計書 2026-08-28 §4.1 / §7.1）。
     *
     * ⚠ **許可リストもラベルもここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡し、
     *   ビュー（見出しとバー）にはこの配列そのものを渡す。見出しとバーで別々に文字列を持つと
     *   食い違う（Bug #41 / #46）。
     *
     * ⚠ 「多い/少ない」「高い/低い」「新しい/古い」を**列ごとに持つ**。率に「大きい順」、
     *   日付に「多い順」は日本語として不自然で、バーにそのまま出る。
     *
     * ⚠ ビル名・位置・操作は並び替えない（設計書 §4.1 / §10）。ビル名は**既定順**であって
     *   見出しからは押せない。
     */
    public const SORT_COLUMNS = [
        'floors'    => ['label' => '総階数',   'desc' => '多い順',   'asc' => '少ない順'],
        'operating' => ['label' => '営業',     'desc' => '多い順',   'asc' => '少ない順'],
        'vacant'    => ['label' => '空き',     'desc' => '多い順',   'asc' => '少ない順'],
        'unknown'   => ['label' => '不明',     'desc' => '多い順',   'asc' => '少ない順'],
        'occupancy' => ['label' => '入居率',   'desc' => '高い順',   'asc' => '低い順'],
        'vacancy'   => ['label' => '空室率',   'desc' => '高い順',   'asc' => '低い順'],
        'month'     => ['label' => '最終調査', 'desc' => '新しい順', 'asc' => '古い順'],
    ];
```

`rows()` を下記に置き換える（Task 5 で書いた形に `$sort` を足す）:

```php
    /**
     * 絞り込み・並び替え済みの行。
     *
     * ⚠ **既定順（$sort が null）はビル名の昇順。** baseQuery() が
     *   `ORDER BY area_buildings.name, area_buildings.id` で引いており、
     *   map() / filter() は順序を保つので、並べ替えを書かないことがそのまま既定順になる
     *   （設計書 2026-08-28 §4.4）。従来の「空室率の降順」は `?sort=vacancy&dir=desc`。
     *
     * ⚠ 呼び出し元は AreaBuildingController::index() の 1 箇所だけ（2026-08-28 実測）。
     *
     * @return Collection<int, array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, occupancy_label: string, rate_label: string}>
     */
    public function rows(Request $request, ?ListSort $sort = null): Collection
    {
        $occupancy = $request->input('occupancy');
        $year      = $request->input('year');

        $rows = $this->baseQuery($request)
            ->get()
            ->map(fn (AreaBuilding $building) => $this->toRow($building))
            ->filter(fn (array $row) => $this->matchesYear($row, $year) && $this->matchesOccupancy($row, $occupancy));

        return $this->applySort($rows, $sort);
    }

    /**
     * 並び替えを適用する。指定が無ければ既定順（ビル名の昇順）のまま返す。
     *
     * ⚠ **「—」は昇順でも降順でも末尾**（前設計書 §4.3-2）。partition で分けて連結する。
     *   `[null 判定, 値]` の複合キーを sortBy / sortByDesc に渡す形は、**向きによって
     *   null フラグの 0/1 を反転させないと末尾に行かない**（降順では「値あり」を 1、
     *   昇順では 0 にする必要がある）。書いた本人しか気づけない取り違えになるので、
     *   読んだだけで正しいと分かる形を優先する（設計書 §4.2）。
     *
     * ⚠ 並び替えは**既定順に並べ終えた列に対して**掛ける。PHP のソートは 8.0 以降 stable なので
     *   同点の行はビル名順のまま残る。これが無いとページをまたいで行が重複／消失する
     *   （前設計書 §4.3-3）。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applySort(Collection $rows, ?ListSort $sort): Collection
    {
        if ($sort === null) {
            return $rows->values();
        }

        [$known, $blank] = $rows->partition(fn (array $row) => $this->sortValue($row, $sort->key) !== null);

        $sorted = $sort->isAscending()
            ? $known->sortBy(fn (array $row) => $this->sortValue($row, $sort->key))
            : $known->sortByDesc(fn (array $row) => $this->sortValue($row, $sort->key));

        return $sorted->concat($blank)->values();
    }

    /**
     * その列の並べ替えキー。null は「画面に —」＝末尾へ回す行。
     *
     * ⚠ **入居率は VacancyRate::occupancyPercent() を使わない。** 空室率の符号を反転する。
     *   入居率 = 100 − 空室率 なので順序は厳密に一致し（VacancyRate が 1/10% 単位の同じ整数から
     *   両方を作っており、和が 100.0% になることは構造で保証されている）、別々に計算すると
     *   **画面に並ぶ 2 つの数字と並び順が食い違う余地**を自分で作る（設計書 §4.3 / Bug #46）。
     *
     * ⚠ 「調査回はあるが総区画 0」の行は rate だけが null。営業・空き・不明は 0（画面も「0」）、
     *   month は実在する日付なので、**列によって末尾かどうかが変わる**のが正しい（設計書 §2.2）。
     *
     * ⚠ 日付は Carbon のまま比べず `format('Y-m-d')` の文字列にする（辞書順で一致し、
     *   比較の意味がオブジェクトの実装に依存しない。設計書 §4.4.1）。
     *
     * ⚠ default アームを書かないのは意図。$sort->key は ListSort::fromRequest() の
     *   許可リストを通った値しか来ない（ListSort のコンストラクタが private な根拠）。
     */
    private function sortValue(array $row, string $key): int|float|string|null
    {
        return match ($key) {
            'floors'    => $row['building']->total_floors,
            'operating' => $row['operating'],
            'vacant'    => $row['vacant'],
            'unknown'   => $row['unknown'],
            'occupancy' => $row['rate'] === null ? null : -$row['rate'],
            'vacancy'   => $row['rate'],
            'month'     => $row['month']?->format('Y-m-d'),
        };
    }
```

⚠ クラス docblock の 2 層の説明（`PHP … 並び替え …`）はそのままで正しい。

- [ ] **Step 4: コントローラで `$sort` を作って渡す**

`app/Http/Controllers/Tenant/AreaBuildingController.php` の `use` に 1 行足す:

```php
use App\Support\ListSort;
```

`index()` の `$canEdit` の行の直前に足す:

```php
        $sort = ListSort::fromRequest($request, array_keys(AreaBuildingListService::SORT_COLUMNS));
```

`$rows = $service->rows($request);` を:

```php
        $rows = $service->rows($request, $sort);
```

`return view(...)` の配列に 2 行足す（`'canEdit' => $canEdit,` の直後）:

```php
            'sort'                => $sort,
            'sortColumns'         => AreaBuildingListService::SORT_COLUMNS,
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingListSortTest
```

Expected: `OK (8 tests, ...)`

- [ ] **Step 6: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1020 tests, ...)`

- [ ] **Step 7: コミット**

```bash
git add app/Services/Tenant/AreaBuildingListService.php app/Http/Controllers/Tenant/AreaBuildingController.php tests/Feature/Tenant/AreaBuildingListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 周辺ビル調査の一覧を 7 列で並び替えられるようにする

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 周辺ビル調査の見出し 7 本 ＋ 並び順の hidden

**Files:**
- Modify: `resources/views/tenant/area-buildings/index.blade.php`
- Modify: `tests/Feature/SortableListWiringTest.php`
- Test: `tests/Feature/Tenant/AreaBuildingListSortTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingListSortTest.php` の末尾（クラスの閉じ括弧の前）に足す:

```php
    /**
     * 見出しを 3 回押す往復。既定 → 降順 → 昇順 → 既定（前設計書 §4.2）。
     *
     * ⚠ **URL を自分で組み立てない。** 画面が描画した href をそのまま辿ること。
     *   組み立てると、リンクが壊れていても sort が付いた状態で届くので**必ず緑**になる（Bug #31）。
     * ⚠ 入居率の昇順と既定順（名前の昇順）が**わざと食い違う**データ。
     *   一致させると 2 回目と 3 回目の結果が同じになり、片方が壊れても緑になる。
     */
    public function test_clicking_the_occupancy_header_three_times_cycles_back_to_the_default_order(): void
    {
        $this->seedFourBuildings();

        $staff   = $this->staff();
        $default = ['Aビル', 'Bビル', 'Cビル', 'Dビル'];

        $html  = $this->actingAs($staff)->get('/tenant/area-buildings')->getContent();
        $first = $this->actingAs($staff)->get($this->sortLinkFor($html, '入居率'));
        $first->assertOk();
        $this->assertSame(['Cビル', 'Aビル', 'Dビル', 'Bビル'], $this->listedNames($first), '1 回目が入居率の降順でない');
        $this->assertSame('descending', $this->ariaSortFor($first->getContent(), '入居率'));

        $second = $this->actingAs($staff)->get($this->sortLinkFor($first->getContent(), '入居率'));
        $second->assertOk();
        $this->assertSame(['Bビル', 'Dビル', 'Aビル', 'Cビル'], $this->listedNames($second), '2 回目が入居率の昇順でない');
        $this->assertSame('ascending', $this->ariaSortFor($second->getContent(), '入居率'));

        $thirdUrl = $this->sortLinkFor($second->getContent(), '入居率');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 巡目は並び替えを解除する');
        $third = $this->actingAs($staff)->get($thirdUrl);
        $third->assertOk();
        $this->assertSame($default, $this->listedNames($third));
    }

    /**
     * 7 列すべてに並び替えリンクがあり、aria-sort は**並び替え中の列だけ**に載ること。
     *
     * ⚠ ページ全体に対する assertStringContainsString('aria-sort="descending"') では
     *   **全列に descending を出す変異が緑のまま通る**（ParsesSortLinks の docblock に実測済み）。
     */
    public function test_all_seven_headers_link_and_only_the_sorted_one_is_marked(): void
    {
        $this->seedFourBuildings();

        $html = $this->actingAs($this->staff())
            ->get('/tenant/area-buildings?sort=month&dir=asc')
            ->getContent();

        foreach (['総階数', '営業', '空き', '不明', '入居率', '空室率', '最終調査'] as $label) {
            $this->assertStringContainsString('sort=', $this->sortLinkFor($html, $label), "「{$label}」の見出しが並び替えリンクになっていない");
        }

        $this->assertSame('ascending', $this->ariaSortFor($html, '最終調査'));
        foreach (['総階数', '営業', '空き', '不明', '入居率', '空室率'] as $label) {
            $this->assertSame('none', $this->ariaSortFor($html, $label), "並び替えていない列「{$label}」に aria-sort が載っている");
        }
    }

    /** 並び替え対象外の列は素の <th> のまま（設計書 §4.1 / §10） */
    public function test_the_name_location_and_actions_columns_stay_plain(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();

        $this->assertStringContainsString('>ビル名</th>', $html, 'ビル名が並び替え見出しになっている（対象外）');
        $this->assertStringContainsString('>位置</th>', $html);
        $this->assertStringContainsString('>操作</th>', $html);
        $this->assertSame('none', $this->ariaSortFor($html, 'ビル名'));
    }

    /**
     * フィルタを変えても並び順が消えないこと（`x-sort-hidden`。前設計書 §4.3-4）。
     *
     * ⚠ 画面が描画したフォームを分解してそのまま送り返す（Bug #47）。
     *   hidden が無いと GET で送り直された瞬間に ?sort と ?dir が落ち、**黙って既定順へ戻る**。
     */
    public function test_changing_a_filter_keeps_the_current_sort(): void
    {
        $this->seedFourBuildings();
        $this->makeSurvey($this->makeBuilding('Eビル', ['total_floors' => 1]), '2025-08-01', 5, 5, 0);

        $staff = $this->staff();
        $html  = $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc')->getContent();
        $form  = $this->parseForm($html, 'action="' . route('tenant.area-buildings.index') . '"');

        $this->assertSame('floors', $form['fields']['sort'] ?? null, 'フィルターフォームが sort を持ち回していない');
        $this->assertSame('desc', $form['fields']['dir'] ?? null, 'フィルターフォームが dir を持ち回していない');

        // ブラウザと同じように、調査年だけ変えて送り返す
        $fields = $form['fields'];
        $fields['year'] = '2026';

        $response = $this->actingAs($staff)->get($form['action'] . '?' . http_build_query($fields));

        $response->assertOk();
        $this->assertSame(
            ['Cビル', 'Bビル', 'Dビル', 'Aビル'],
            $this->listedNames($response),
            'フィルタを変えたら並び順が既定に戻った（総階数の降順のままであるべき）'
        );
        $this->assertNotContains('Eビル', $this->listedNames($response), '調査年の絞り込みが効いていない');
    }

    /** 並び替えていないときは余計な hidden を出さない（?sort= が URL に現れて汚れる） */
    public function test_no_sort_hidden_fields_when_not_sorting(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.index') . '"');

        $this->assertArrayNotHasKey('sort', $form['fields']);
        $this->assertArrayNotHasKey('dir', $form['fields']);
    }

    /**
     * タブを切り替えても並び順が持ち回されること（設計書 §4.5）。
     *
     * ⚠ タブリンクは `request()->except(['view','page'])` なので何もしなくても付くが、
     *   **そこを触ったときに気づけないと「表へ戻ると並び順が消える」**が無音で入る。
     * ⚠ ここで sortLinkFor() を使うのは並び替え見出しではなく**タブのリンク**。
     *   要件（`<a …>` の直後にラベル）が同じなので流用できる。URL を組み立てないための流用で、
     *   「表」が別のリンク（サイドバーの『経営試算表』など）に誤マッチしないことは
     *   境界 `>ラベル<` が保証している（ParsesSortLinks の docblock に実測済み）。
     */
    public function test_the_sort_survives_a_trip_through_the_map_tab(): void
    {
        $this->seedFourBuildings();

        $staff  = $this->staff();
        $sorted = ['Cビル', 'Bビル', 'Dビル', 'Aビル'];

        $tableHtml = $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc')->getContent();
        $mapUrl    = $this->sortLinkFor($tableHtml, '地図');
        $this->assertStringContainsString('sort=floors', $mapUrl, '地図タブのリンクが並び順を落としている');

        $mapHtml = $this->actingAs($staff)->get($mapUrl)->assertOk()->getContent();
        $backUrl = $this->sortLinkFor($mapHtml, '表');
        $this->assertStringContainsString('sort=floors', $backUrl, '表タブのリンクが並び順を落としている');

        $back = $this->actingAs($staff)->get($backUrl);
        $this->assertSame($sorted, $this->listedNames($back), '表へ戻ったら並び順が消えている');
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingListSortTest
```

Expected: FAIL（`「総階数」の並び替えリンクが見つからない`）

- [ ] **Step 3: 見出し 7 本を差し替える**

`resources/views/tenant/area-buildings/index.blade.php` の `<thead>` の `<tr>` の中身を
丸ごと下記に置き換える（**ビル名・位置・操作は素の `<th>` のまま**）:

```blade
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ビル名</th>
                            <x-sortable-th column="floors" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="operating" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="vacant" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="unknown" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="occupancy" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="vacancy" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">位置</th>
                            <x-sortable-th column="month" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
```

⚠ **列の順番は変えない**（`<colgroup>` の 10 本・空行の `colspan="10"` と 3 点セット）。

- [ ] **Step 4: フィルターフォームに並び順の hidden を足す**

同ファイルのフィルターフォームの `<form id="filter-form" …>` の直後（`<input type="text" name="keyword" …>` の前）に足す:

```blade
        <x-sort-hidden :sort="$sort" />
```

- [ ] **Step 5: 走査テストの分類表に周辺ビル調査を足す**

⚠ **Step 3 の直後から `SortableListWiringTest` は赤になる**（登録されていないビューが
見出しを持ったため）。それが「全件分類」（Bug #45）が効いている証拠。ここで登録する。

`tests/Feature/SortableListWiringTest.php` の `use` に 1 行足す:

```php
use App\Services\Tenant\AreaBuildingListService;
```

`SORT_COLUMN_SOURCES` に 1 行足す:

```php
        'tenant/area-buildings/index.blade.php' => [AreaBuildingListService::class, 7],
```

- [ ] **Step 6: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter "AreaBuildingListSortTest|SortableListWiringTest"
```

Expected: `OK (16 tests, ...)`

- [ ] **Step 7: コンパイル済みビューを lint する**

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 8: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1026 tests, ...)`

- [ ] **Step 9: コミット**

```bash
git add resources/views/tenant/area-buildings/index.blade.php tests/Feature/SortableListWiringTest.php tests/Feature/Tenant/AreaBuildingListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 周辺ビル調査の見出し 7 本を並び替えリンクにする

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: 現在の並び順バー（`x-sort-bar`）を 3 一覧へ

**Files:**
- Create: `resources/views/components/sort-bar.blade.php`
- Modify: `resources/views/tenant/area-buildings/index.blade.php`
- Modify: `resources/views/tenant/units/index.blade.php`
- Modify: `resources/views/tenant/properties/index.blade.php`
- Modify: `tests/Feature/SortableListWiringTest.php`
- Create: `tests/Feature/Tenant/SortBarTest.php`

⚠ **これが現状で唯一どこにも出ていない情報を出す。** 既定順は今まで画面に一切現れていなかった。
⚠ 周辺ビル調査では**表タブにだけ出す**（並び替えが地図タブの見た目を変えないため。設計書 §4.5）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/SortBarTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesSortLinks;

/**
 * 現在の並び順バー（設計書 2026-08-28 §6 / モック 案C のバー）。
 *
 * ⚠ **ヒント文とピルは役割が違うので別々にアサートする。** まとめて見ると片方が消えても
 *   緑になる（Bug #43 / #46 / #49 と同型）。
 * ⚠ 経営層は department.access を素通りするので、1 人で 3 画面とも見られる。
 * ⚠ 行は要らない画面が多いが、周辺ビル調査だけは**文言と実際の並びを対で**見るのでデータを作る。
 */
class SortBarTest extends AreaBuildingTestCase
{
    use ParsesSortLinks;
    use RefreshDatabase;

    /** 3 画面それぞれが**自分の**既定順を名乗ること */
    public function test_each_list_names_its_own_default_order(): void
    {
        $user = $this->executive();

        $area = $this->actingAs($user)->get('/tenant/area-buildings')->getContent();
        $this->assertStringContainsString('並び替え: 既定（ビル名順）', $area);

        $properties = $this->actingAs($user)->get(route('tenant.properties.index'))->getContent();
        $this->assertStringContainsString('並び替え: 既定（稼働中が先・コード順）', $properties);
        $this->assertStringNotContainsString('ビル名順', $properties, '別の画面の既定順が出ている');

        $units = $this->actingAs($user)->get(route('tenant.units.index'))->getContent();
        $this->assertStringContainsString('並び替え: 既定（物件・階・部屋番号順）', $units);
        $this->assertStringNotContainsString('ビル名順', $units, '別の画面の既定順が出ている');
    }

    /**
     * 周辺ビル調査の既定順の**文言と実際の並びが揃っている**こと。
     *
     * ⚠ 片方だけ直すと「既定（空室率が高い順）」と書いてあるのに名前順で並ぶ、という
     *   本設計が最も嫌う形の嘘になる（設計書 §6 の ⚠）。**必ず対で見る。**
     */
    public function test_the_area_building_bar_names_the_real_default_order(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $this->assertStringContainsString('並び替え: 既定（ビル名順）', $response->getContent());
        $this->assertSame(['あ未調査', 'い率10', 'う率50'], $this->listedNames($response), 'バーの文言と実際の並びが食い違っている');
    }

    /**
     * 並び替え中は列名と**向きの言い方**が出ること。
     *
     * ⚠ 率は「高い/低い」、件数は「多い/少ない」、日付は「新しい/古い」。
     *   ここを 1 語に統一すると日本語として不自然になる（設計書 §4.1）。
     */
    public function test_the_bar_names_the_column_and_the_direction(): void
    {
        $this->makeBuilding('Aビル');
        $staff = $this->staff();

        $get = fn (string $q) => $this->actingAs($staff)->get('/tenant/area-buildings?' . $q)->getContent();

        $this->assertStringContainsString('並び替え: 入居率 高い順', $get('sort=occupancy&dir=desc'));
        $this->assertStringContainsString('並び替え: 入居率 低い順', $get('sort=occupancy&dir=asc'));
        $this->assertStringContainsString('並び替え: 総階数 多い順', $get('sort=floors&dir=desc'));
        $this->assertStringContainsString('並び替え: 総階数 少ない順', $get('sort=floors&dir=asc'));
        $this->assertStringContainsString('並び替え: 最終調査 新しい順', $get('sort=month&dir=desc'));
        $this->assertStringContainsString('並び替え: 最終調査 古い順', $get('sort=month&dir=asc'));
    }

    /**
     * 「解除」は並び順だけを消し、**絞り込みは残す**（設計書 §6）。
     *
     * ⚠ フィルタも消える実装だと、フィルタごと初期化する「クリア」と区別が無くなる。
     *   **フィルタ付きで踏んで確認する。**
     */
    public function test_the_clear_link_removes_only_the_sort(): void
    {
        $this->makeSurvey($this->makeBuilding('Aビル'), '2026-08-01', 5, 5);   // 空室率 50% → under75
        $this->makeSurvey($this->makeBuilding('Bビル'), '2026-08-01', 10, 0);  // 満室 → under75 では外れる

        $staff = $this->staff();
        $html  = $this->actingAs($staff)
            ->get('/tenant/area-buildings?sort=occupancy&dir=desc&occupancy=under75')
            ->getContent();

        $clearUrl = $this->sortLinkFor($html, '解除');
        $this->assertStringNotContainsString('sort=', $clearUrl, '解除リンクが並び順を残している');
        $this->assertStringNotContainsString('dir=', $clearUrl);
        $this->assertStringContainsString('occupancy=under75', $clearUrl, '解除リンクが絞り込みまで消している（「クリア」と区別が無い）');

        $cleared = $this->actingAs($staff)->get($clearUrl);
        $cleared->assertOk();
        $this->assertStringContainsString('並び替え: 既定（ビル名順）', $cleared->getContent());
        $this->assertSame(['Aビル'], $this->listedNames($cleared), '解除したら絞り込みまで外れている');
    }

    /** 並び替えていないときは解除リンクを出さない（消すものが無い） */
    public function test_the_clear_link_is_absent_when_nothing_is_sorted(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->getContent();

        $this->assertStringNotContainsString('>解除</a>', $html);
    }

    /**
     * ヒント文は並び替えの有無にかかわらず出ること。
     *
     * ⚠ **ピルとは別々にアサートする。** 1 本にまとめると、ヒント文だけを消す変異が
     *   ピルのアサートに救われて緑になる（Bug #43 / #46 / #49）。
     */
    public function test_the_hint_is_shown_whether_or_not_the_list_is_sorted(): void
    {
        $this->makeBuilding('Aビル');
        $staff = $this->staff();

        $this->assertStringContainsString(
            '見出しをクリックすると並び替えできます',
            $this->actingAs($staff)->get('/tenant/area-buildings')->getContent()
        );
        $this->assertStringContainsString(
            '見出しをクリックすると並び替えできます',
            $this->actingAs($staff)->get('/tenant/area-buildings?sort=floors&dir=desc')->getContent()
        );
    }

    /**
     * 地図タブにはバーを出さない（設計書 §4.5）。
     *
     * ⚠ 未登録リストは常にビル名の昇順で固定なので、並び替えは地図タブの見た目を一切変えない。
     *   出すと「並び替え中」と書いてあるのに何も変わらない画面になる。
     */
    public function test_the_map_tab_has_no_sort_bar(): void
    {
        $this->makeBuilding('Aビル');

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map&sort=floors&dir=desc')->getContent();

        $this->assertStringNotContainsString('並び替え:', $html, '地図タブにバーが出ている');
        $this->assertStringNotContainsString('見出しをクリックすると並び替えできます', $html);
    }
}
```

`tests/Feature/SortableListWiringTest.php` の
`test_every_sortable_list_carries_the_sort_in_its_filter_form()` の `assertStringContainsString`
の直後に 1 つ足す:

```php
            $this->assertStringContainsString(
                '<x-sort-bar',
                $source,
                "{$relative} は並び替え見出しを持つのに、現在の並び順バーが無い（既定順が画面のどこにも出ない）"
            );
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter "SortBarTest|SortableListWiringTest"
```

Expected: FAIL（バーが無い）

- [ ] **Step 3: `x-sort-bar` を作る**

`resources/views/components/sort-bar.blade.php` を新規作成:

```blade
{{-- 現在の並び順バー（設計書 2026-08-28 §6 / モック 案C のバー）

使い方（表のすぐ上・フィルターバーやタブより下に置く）:
  <x-sort-bar :sort="$sort" :columns="$sortColumns" default-label="ビル名順" />

props:
  sort         … App\Support\ListSort|null（コントローラから渡す）
  columns      … その画面の SORT_COLUMNS（日本語ラベルと「向きの言い方」）
  defaultLabel … 並び替えていないときに出す既定順の説明（「ビル名順」など）

⚠ **defaultLabel は実際の既定順と必ず揃える。** 片方だけ直すと
   「既定（空室率が高い順）」と書いてあるのに名前順で並ぶ、という嘘になる（設計書 §6）。
   SortBarTest::test_the_area_building_bar_names_the_real_default_order が文言と並びを対で見ている。
⚠ **列名は columns から引く。** 見出し（x-sortable-th）と同じ表を見ることで、
   2 箇所に文字列を置く事故を防ぐ（Bug #41 / #46）。
⚠ 「解除」は**並び順だけ**を消す。フィルタごと初期化する「クリア」ボタンとは役割が違う。
⚠ ヒント文とピルは役割が違うので、テストでも別々にアサートすること（Bug #43 / #46 / #49）。
⚠ JS は 1 行も使わない。ただのリンク。
⚠ 属性式に &quot; を書かないこと。本番の view:cache でだけ 500 になる（Bug #21）。
--}}
@props([
    'sort' => null,
    'columns',
    'defaultLabel',
])
@php
    $column    = $sort === null ? null : $columns[$sort->key];
    $direction = $sort === null ? null : ($sort->isAscending() ? $column['asc'] : $column['desc']);
@endphp
<div class="flex flex-wrap items-center gap-2 mb-2.5 text-xs text-gray-500">
    @if($sort === null)
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-600">
            並び替え: 既定（{{ $defaultLabel }}）
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-emerald-200 bg-emerald-50 text-xs font-bold text-emerald-700">
            並び替え: {{ $column['label'] }} {{ $direction }}
            <span style="flex-shrink: 0; width: 11px; height: 11px;">
                @if($sort->isAscending())
                    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 15 12 8 18 15"/></svg>
                @else
                    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 16 18 9"/></svg>
                @endif
            </span>
        </span>
        <a href="{{ \App\Support\ListSort::clearUrl(request()) }}"
           class="px-2 py-0.5 rounded border border-gray-200 bg-white text-[11px] font-semibold text-gray-500 hover:text-gray-700 hover:border-gray-300 hover:bg-gray-50 transition-colors">解除</a>
    @endif
    <span class="inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5 text-gray-400" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        見出しをクリックすると並び替えできます
    </span>
</div>
```

- [ ] **Step 4: 3 つのビューへ置く**

いずれも `{{-- テーブル --}}` の**直前**に 1 行入れる。

`resources/views/tenant/area-buildings/index.blade.php`（`@else` と `{{-- テーブル --}}` の間。
**表タブの分岐の中なので地図タブには出ない**）:

```blade
    @else
    <x-sort-bar :sort="$sort" :columns="$sortColumns" default-label="ビル名順" />

    {{-- テーブル --}}
```

`resources/views/tenant/properties/index.blade.php`:

```blade
    <x-sort-bar :sort="$sort" :columns="$sortColumns" default-label="稼働中が先・コード順" />

    {{-- テーブル --}}
```

`resources/views/tenant/units/index.blade.php`:

```blade
    <x-sort-bar :sort="$sort" :columns="$sortColumns" default-label="物件・階・部屋番号順" />

    {{-- テーブル --}}
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter "SortBarTest|SortableListWiringTest"
```

Expected: `OK (9 tests, ...)`

- [ ] **Step 6: コンパイル済みビューを lint する**

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 7: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1033 tests, ...)`

- [ ] **Step 8: コミット**

```bash
git add resources/views/components/sort-bar.blade.php resources/views/tenant/area-buildings/index.blade.php resources/views/tenant/properties/index.blade.php resources/views/tenant/units/index.blade.php tests/Feature/SortableListWiringTest.php tests/Feature/Tenant/SortBarTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 一覧に現在の並び順バーを出す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: 地図タブの未登録リストをビル名の昇順で固定する

**Files:**
- Modify: `app/Http/Controllers/Tenant/AreaBuildingController.php`
- Test: `tests/Feature/Tenant/AreaBuildingMapTabTest.php`

⚠ 187 棟を上から順に登録していく作業なので、**表で何をしていても作業リストの順番が
変わらない**ことが要る（設計書 §4.5。利用者の選択）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingMapTabTest.php` の末尾（クラスの閉じ括弧の前）に足す:

```php
    /**
     * 登録モードの作業リストは**常にビル名の昇順**で、表の並び替えに追従しない（設計書 §4.5）。
     *
     * ⚠ **必ず `?sort` 付きで測ること。** 素の地図タブでは既定順がもう名前順なので、
     *   `$rows` を素通しする実装と結果が同じになり**原理的に区別が付かない**（設計書 §8.1.1-4）。
     * ⚠ 名前順と表の並び順がわざと食い違うデータにしてある（総階数の降順は C→B→A）。
     */
    public function test_the_locate_list_ignores_the_table_sort(): void
    {
        $this->makeBuilding('Aビル', ['total_floors' => 1]);
        $this->makeBuilding('Bビル', ['total_floors' => 5]);
        $this->makeBuilding('Cビル', ['total_floors' => 9]);

        $staff = $this->staff();

        $sorted = $this->actingAs($staff)->get('/tenant/area-buildings?view=map&sort=floors&dir=desc');
        $sorted->assertOk();
        $this->assertSame(
            ['Aビル', 'Bビル', 'Cビル'],
            array_column($sorted->viewData('mapUnlocated'), 'name'),
            '作業リストが表の並び替えに追従している（登録作業の途中で順番が変わる）'
        );

        // 逆向きでも同じ（片方だけ固定する実装を落とす）
        $reverse = $this->actingAs($staff)->get('/tenant/area-buildings?view=map&sort=floors&dir=asc');
        $this->assertSame(
            ['Aビル', 'Bビル', 'Cビル'],
            array_column($reverse->viewData('mapUnlocated'), 'name')
        );
    }

    /** 作業リストの並びが、画面に描画される <li> の並びと一致していること */
    public function test_the_locate_list_markup_follows_the_same_order(): void
    {
        $this->makeBuilding('Aビル', ['total_floors' => 1]);
        $this->makeBuilding('Bビル', ['total_floors' => 5]);
        $this->makeBuilding('Cビル', ['total_floors' => 9]);

        $html = $this->actingAs($this->manager())
            ->get('/tenant/area-buildings?view=map&sort=floors&dir=desc')
            ->getContent();

        preg_match_all('/data-locate-index="(\d+)"[^>]*>([^<]+)</u', $html, $matches, PREG_SET_ORDER);

        $this->assertCount(3, $matches, '作業リストの行が描画されていない');
        $this->assertSame(['0', '1', '2'], array_column($matches, 1), 'data-locate-index が連番でない');
        $this->assertSame(['Aビル', 'Bビル', 'Cビル'], array_column($matches, 2));
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter "test_the_locate_list"
```

Expected: FAIL（`['Cビル','Bビル','Aビル']` が返る）

- [ ] **Step 3: `mapUnlocated()` を名前順で固定する**

`app/Http/Controllers/Tenant/AreaBuildingController.php` の `mapUnlocated()` を置き換える:

```php
    /**
     * 座標が無くて地図に出せない棟。件数の表示と、登録モードの作業リストに使う。
     *
     * ⚠ **常にビル名の昇順に固定する（表の並び替えに追従させない）。** 187 棟を上から順に
     *   登録していく作業なので、表で何をしていても順番が変わらないことが要る（設計書 §4.5）。
     *   ⚠ 表は SQL（本番は utf8mb4_unicode_ci）、ここは PHP（バイト順）で並ぶ。漢字・かな主体の
     *      名前では一致するが、**大文字小文字だけが違う名前では本番で 2 つの順が食い違いうる**。
     *      テストデータにそういう名前を使わないこと（設計書 §4.4）。
     *   ⚠ 同名の棟は id 昇順のまま（PHP のソートは stable で、$rows は baseQuery の
     *      `ORDER BY name, id` を保っている）。
     *
     * ⚠ 既知の例外: 登録モード中に「位置を消す」を押した棟は、_map.blade.php の
     *   ensureInLocateList() が AREA_MAP_UNLOCATED の**末尾に push する**ので、その 1 行だけ
     *   名前順から外れて最下部に出る。名前順の位置へ差し込むには data-locate-index と
     *   onclick の引数を全行で振り直す必要があり、得られる利益に対して壊す面積が大きいので
     *   **意図してそのままにしている**（設計書 §4.5）。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{id: int, name: string}>
     */
    private function mapUnlocated(Collection $rows): array
    {
        return $rows
            ->reject(fn (array $row) => $row['building']->hasCoordinates())
            ->sortBy(fn (array $row) => $row['building']->name)
            ->map(fn (array $row) => [
                'id'   => $row['building']->id,
                'name' => $row['building']->name,
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingMapTabTest
```

Expected: `OK (43 tests, ...)`

- [ ] **Step 5: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1035 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Tenant/AreaBuildingController.php tests/Feature/Tenant/AreaBuildingMapTabTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 地図の位置登録リストをビル名の昇順で固定する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: 変異テストで「テストが本当に守っているか」を測る

**Files:** なし（測定のみ。結果を下の表に書き戻す）

⚠ **「テストが緑」は検証にならない。** 変異を当てて赤になること・**落ちた理由の文言まで**
突き合わせることを実測する（設計書 §8.6）。

### 作法（Bug #44。1 つでも省くと測定が無効になる）

1. **先にコミットする**（Task 9 まで済んでいること）。未コミットのまま変異を当てて
   `git checkout --` すると**自分の編集ごと巻き戻る**（実測でファイル 4 本が消えた）
2. 各変異の**前**に `git status --porcelain` が**空**であること（前の変異の残骸で測定が汚れる）
3. 変異を当てた**直後**に `git diff --stat` が**非空**であること（当たっていない変異を
   「検出しない」と誤読する事故が実際に起きている）
4. `git checkout -- <当該ファイル>` で戻す
5. **赤/緑ではなく落ちた理由の文言**を突き合わせる（意図と別の機構が落としている可能性を排除する）

- [x] **Step 1: 作業ツリーが清浄であることを確認する**

```bash
git status --porcelain && echo "---clean---"
```

Expected: `---clean---` だけ

- [x] **Step 2: 15 通りの変異を 1 つずつ当てて測る**（＋ #16 / #17 の 2 通りを追加）

各行について「変異 → `git diff --stat` で着弾確認 → 指定のテストを走らせる → 理由を読む → 戻す」。

| # | 変異する場所 | 変異の内容 | 赤になるはずのテスト | 結果 |
|---|---|---|---|---|
| 1 | `AreaBuildingListService::sortValue()` | `'occupancy' => … -$row['rate']` の `-` を消す | `AreaBuildingListSortTest::test_every_sortable_column_sorts_by_its_own_values` ＋ `..._reverse_of_the_vacancy_order` | ✅ 赤 3 本（`occupancy の降順が違う` / `入居率と空室率で並びが食い違う（片方を別計算で出している。Bug #46）`。ほかに 3 巡目のテストも） |
| 2 | 同 `applySort()` | `partition` をやめて `$rows` を丸ごと `sortBy` / `sortByDesc` する | `..._blank_values_stay_at_the_end_in_both_directions` | ✅ 赤 3 本（`blank_values…`: 「—」の 0号ビル が末尾でなく**先頭**に来る） |
| 3 | 同 `applySort()` | `->concat($blank)` を落とす（「—」の行が消える） | `..._blank_values_…`（件数が合わない） | ✅ 赤 5 本（`blank_values…`: `Failed asserting that actual size 4 matches expected size 5` ＝ 「—」の行が**消える**） |
| 4 | 同 `applySort()` | `$sort->isAscending()` の分岐を逆にする | `..._every_sortable_column_…`（昇順と降順が入れ替わる） | ✅ 赤 10 本（`floors の降順が違う` ＝ 昇順と降順が入れ替わる） |
| 5 | 同 `rows()` | 既定順を元の `->sortByDesc([$row['rate'] === null ? 0 : 1, $row['rate'] ?? 0.0])` に戻す | `AreaBuildingListTest::test_the_default_order_is_the_building_name_ascending` ＋ `SortBarTest::test_the_area_building_bar_names_the_real_default_order` | ✅ 赤 6 本（`既定順が名前の昇順でない` ＋ `SortBarTest::…names_the_real_default_order` ＋ **構造テスト** `test_filtered_rows_does_not_sort_in_php`）⚠ 変異先は refactor 後の `filteredRows()`（末尾に旧 `->sortByDesc([...])->values()` を戻す） |
| 6 | `AreaBuildingListService::baseQuery()` | `->orderBy('area_buildings.name')` を消す（既定順への安定ソート依存を壊す） | `AreaBuildingListSortTest::test_tied_rows_keep_the_building_name_order` | ✅ 赤 5 本（`降順での「—」ブロック内部の並びが名前の昇順になっていない` / `tied_rows…` / `occupancy_bands` / 地図の同名 id 順） |
| 7 | `AreaBuildingController::mapUnlocated()` | `->sortBy(...)` を外して `$rows` を素通しさせる | `AreaBuildingMapTabTest::test_the_locate_list_ignores_the_table_sort` ⚠ **`?sort` 付きで測る** | ✅ 赤 3 本（`作業リストが表の並び替えに追従している（登録作業の途中で順番が変わる）`）⚠ **読み替えて測定**（下記メモ） |
| 8 | `sort-bar.blade.php` | `default-label` の受け渡しを無視して常に「空室率が高い順」と出す | `SortBarTest::test_the_area_building_bar_names_the_real_default_order` | ✅ 赤 5 本（`/tenant/area-buildings が自分の既定順を名乗っていない` ＋ 物件・部屋の 3 画面とも） |
| 9 | `sort-bar.blade.php` | ヒント文の `<span>` だけを消す | `SortBarTest::test_the_hint_is_shown_whether_or_not_the_list_is_sorted` | ✅ 赤 1 本（ヒント文だけが消え、ピルのアサートに救われない＝役割分けが効いている） |
| 10 | `sort-bar.blade.php` | ピル（`並び替え: …` の span）だけを消す | `SortBarTest::test_each_list_names_its_own_default_order` ＋ `..._names_the_column_and_the_direction` | ✅ 赤 6 本 ⚠ **穴が 1 つあった**。修正前は 5 本で `test_the_bar_names_the_column_and_the_direction` が**緑のまま**だった（下記メモ） |
| 11 | `ListSort::clearUrl()` | `unset($query['page'], $query['sort'], $query['dir']);` を `$query = [];` にする | `ListSortTest::test_clear_url_removes_only_the_sort` ＋ `SortBarTest::test_the_clear_link_removes_only_the_sort` | ✅ 赤 3 本（`絞り込みまで消えている（「クリア」と区別が無くなる）`） |
| 12 | `sortable-th.blade.php` | `$iconColor` の `#6B7280` を `#D1D5DB` に戻す | `SortAffordanceTest::test_the_idle_arrow_is_dark_enough_to_be_seen` | ✅ 赤 1 本（`未使用の矢印が薄いまま（1.41:1 の #D1D5DB に戻っている）`） |
| 13 | `sortable-th.blade.php` | `<span class="sortable-th-label">` を外して素の `{{ $label }}` に戻す | `SortAffordanceTest::test_the_label_is_wrapped_…` | ✅ 赤 2 本（`ラベルが sortable-th-label で包まれていない（下線が矢印にも掛かる）` ＋ 見出しの並びのテスト） |
| 14 | `area-buildings/index.blade.php` | `<x-sort-hidden :sort="$sort" />` を消す | `AreaBuildingListSortTest::test_changing_a_filter_keeps_the_current_sort` ＋ `SortableListWiringTest` | ✅ 赤 2 本（`…並び替え見出しを持つのに、並び順を持ち回す hidden が無い` / `フィルターフォームが sort を持ち回していない`） |
| 15 | `SortableListWiringTest` | 走査を 1 本に絞る（`if ($relative !== 'tenant/units/index.blade.php') continue;`） | 自分自身（`assertEqualsCanonicalizing` と `assertGreaterThan(100, $scanned)`） | ✅ 赤 2 本（`Blade の走査が空振りしている`＝`1 is greater than 100` ＋ `ラベル表に登録済みのビューが走査で見つからない`） |

⚠ **#7 を `?sort` 無しで測ってはいけない。** 既定順が名前順になった以上、
素の地図タブでは `$rows` を素通ししても結果が同じで、**「検出しない」と誤読する**。

⚠ **#12 / #13 の「当たり先」に注意**（Bug #44 の 2026-08-17 追記）。変異は
**検査対象に入るはずの場所**へ当てること。`sortable-th.blade.php` の docblock の中の
色コードを書き換えても、テストは `<th>` の中身しか見ていないので当たらない。

各変異のコマンド例（#1）:

```bash
git status --porcelain && echo "---clean---"
perl -0pi -e "s/\Q'occupancy' => \$row['rate'] === null ? null : -\$row['rate'],\E/'occupancy' => \$row['rate'] === null ? null : \$row['rate'],/" app/Services/Tenant/AreaBuildingListService.php
git diff --stat            # 非空であること
vendor/bin/phpunit --filter AreaBuildingListSortTest 2>&1 | tail -20
git checkout -- app/Services/Tenant/AreaBuildingListService.php
```

- [x] **Step 3: 結果をこのプランに書き戻す**

上の表の「結果」列を `✅ 赤（理由: …）` / `❌ 緑` で埋める。
**緑だったものは、テストを足すか設計上の穴として明記する**（偽の安心より正直な穴のほうがよい）。

### 実測メモ（2026-08-29）

**結論: 17 通り測って 17 通りとも赤。** ただし**そのうち 1 通り（#10）は測って初めて穴が見つかり、
テストを足してから赤になった**。測らなければ「守られている」と誤読したまま先へ進んでいた。

⚠ **#10 で見つかった穴（Bug #43 / #46 と同型）。** `SortBarTest::test_the_bar_names_the_column_and_the_direction`
は `assertStringContainsString('並び替え: 入居率 高い順', $html)` と素で見ていたが、**同じ文字列が
解除リンクの `aria-label="並び替え: 入居率 高い順 を解除"` にも出る**ため、
**ピルの span を丸ごと消しても緑**だった（実測: 修正前 5 本赤 → ピルのテストは緑）。
`aria-label="…"` を落とした HTML でピルを見て、読み上げ名は**別のアサート**で見る形に直した（`8b0a709f`）。
追加の 2 通りで両方が独立に load-bearing であることを実測:

| # | 変異 | 結果 |
|---|---|---|
| 16 | 並び替え中のピル（emerald の span）**だけ**を消す | ✅ 赤 1 本（`…のピルに列名と向きが出ていない（画面に見える文字が消えている）`）|
| 17 | 解除リンクの `aria-label` **だけ**を消す | ✅ 赤 1 本（`…の解除リンクが何を解除するのか名乗っていない`）|

⚠ **#7 は表のとおりには測れない**（Task 9 の refactor で `mapUnlocated()` の `->sortBy(...)` が消えたため）。
**`index()` の `mapUnlocated($base)` を `mapUnlocated($rows)`（並び替え後）へ戻す**変異に読み替えて測定した。
`?sort` 付きで見るテストが 3 本とも赤になる。

⚠ **Task 0 Step 5 のカナリア変異コマンドは動かない。** perl の `\Q…\E` の中では `\$` が
「バックスラッシュ＋ドル」としてクォートされるので、`$this` を含む needle は **0 件マッチで exit 0**
（`git diff --stat` が空＝無効な測定）。**置換件数を数えて 0 なら止まる道具**を使うこと
（needle / replacement は**ファイル渡し**にしてシェルのクォートを一切通さない）。

⚠ **測定装置のカナリアを先に通した**（Bug #50）。`index.blade.php` へ未定義変数を 1 つ足す変異で
**67 本が赤**になることを確認してから本測定に入った。

⚠ **変異ランナーの出力を `head` に通してはいけない。** SIGPIPE でスクリプトが
**`git checkout --` の前に死に**、変異が作業ツリーに残る（実際に踏んだ。次の測定の
「清浄確認」で止まったので事故にはならなかった＝作法 ② が効いた）。出力の間引きは
スクリプトの内側でやること。

⚠ **本数は実測を信じる。** プラン執筆時点の `1035 tests` は既に古い（ガードテストを多数
足したため）。下の Step 5 の Expected は 2026-08-29 実測の **1043 tests / 6549 assertions** に更新した。


- [x] **Step 4: 作業ツリーが清浄に戻っていることを確認する**

```bash
git status --porcelain && echo "---clean---"
```

- [x] **Step 5: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: `OK (1043 tests, 6549 assertions)`（2026-08-29 実測）

- [x] **Step 6: プランの更新をコミット**

```bash
git add docs/superpowers/plans/2026-08-28-area-building-sorting.md
git commit -m "$(cat <<'EOF'
docs(plan): 並び替えの変異テスト 15 通りの実測結果を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: 画面での確認（テストでは原理的に測れないもの）

**Files:** なし（確認のみ）

⚠ 以下は **HTML に出ているかでは判定できない**（Bug #28 / #43 / #51）。

- [ ] **Step 1: コンパイル済みビューを lint する**

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 2: ローカルで CSS をビルドする**

⚠ **`resources/css/app.css` を変えたので必須。** `public/build` は gitignore 済みで
worktree には存在せず、ビルドするまで点線下線は**1px も出ない**。
worktree には `node_modules` が無いので main repo のものを cwd=worktree で使う。

```bash
/Users/masanori/site/manage/node_modules/.bin/vite build
```

Expected: `public/build/assets/app-*.css` が生成される

```bash
grep -oF '.sortable-th-label' public/build/assets/app-*.css | head -3
```

Expected: 1 行以上ヒットする

- [ ] **Step 3: ログインできる状態を作る**

⚠ **実 MySQL に触らない。** 使い捨ての SQLite ＋ worktree の artisan で見る
（前回の Task 9 と同じ手）。⚠ **パスワードを一切扱わない**よう、一時的な dev 専用ルートを
`routes/web.php` の末尾へ足し、**確認後に `git checkout -- routes/web.php` で必ず消す**。

```php
// ⚠ 画面確認用の使い捨てルート。Task 11 が終わったら git checkout -- routes/web.php で消すこと
Route::get('/__preview-login', function () {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId(\App\Models\User::query()->value('id'));

    return redirect('/tenant/area-buildings');
});
```

- [ ] **Step 4: ブラウザで 7 点を確認する**

`/tenant/area-buildings`・`/tenant/units`・`/tenant/properties` を開いて確認する。

1. **未使用の ⇅ が見えること**（3 画面とも）。`#6B7280` が背景 `#F9FAFB` に対して
   はっきり見えるか。⚠ **これが依頼②の本体。** 見えないなら意匠の選択が間違っている
2. **点線下線がラベルだけに掛かり、矢印の下に線が出ていないこと**。
   ⚠ 設計書 §2.6 が「`<a>` に直接掛けると装飾が flex アイテムへ伝播するかがブラウザ依存で、
   `getComputedStyle` は伝播した装飾を子孫に対して必ず `none` と報告するため
   **DOM 実測では決着しない**」と書いている。**目視が唯一の答え合わせ**
3. **見出しセルのどこを押しても並び替わること**（文字の上だけでないこと）。
   ⚠ `title` と同じで HTML では検証できない（Bug #43）。セルの端を実際にクリックする
4. **`Tab` でフォーカスしたときにリングが切れずに出ること**（既存の
   `.sortable-th-link:focus-visible` の `outline-offset: -2px` が生きていること）
5. **バーの「解除」を押してフィルタが残ること**。周辺ビル調査で
   「入居率 75% 以下」＋「入居率」で並び替え → 解除 → 絞り込みが残り並びだけ既定へ戻る
6. **地図タブの登録モードの作業リストがビル名順であること**。
   ⚠ **表で別の列（総階数など）に並び替えてから**タブを切り替えて確認する。
   既定のままでは名前順と区別が付かない（設計書 §9-6）
7. **表タブの初期表示がビル名順であること**。
   ⚠ 漢字が読み順（あいうえお順）でないのは仕様（読みがな列が無い。設計書 §4.4 / §10）。
   `三 → 久 → 千 → 大 → 武 → 湊 → 相 → 雅` のような並びで正しい

- [ ] **Step 5: 使い捨てルートを消す**

```bash
git checkout -- routes/web.php && git status --porcelain && echo "---clean---"
```

- [ ] **Step 6: 確認結果をこのプランに書き戻す**

| # | 確認項目 | 結果 |
|---|---|---|
| 1 | 未使用の ⇅ が見える | 未確認 |
| 2 | 点線がラベルだけに掛かる | 未確認 |
| 3 | 見出しセル全体が押せる | 未確認 |
| 4 | フォーカスリングが切れない | 未確認 |
| 5 | 解除でフィルタが残る | 未確認 |
| 6 | 作業リストがビル名順（表を並び替えた状態で） | 未確認 |
| 7 | 表の初期表示がビル名順 | 未確認 |

```bash
git add docs/superpowers/plans/2026-08-28-area-building-sorting.md
git commit -m "$(cat <<'EOF'
docs(plan): ブラウザ確認 7 点の実測結果を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: 本番へ反映する

⚠ **`./deploy.sh` はユーザーの明示的な承認が要る。** 承認なしに実行しない。

- [ ] **Step 1: main repo へ FF マージする**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only area-building-sorting
```

⚠ FF できないなら 13.x が先に進んでいる。worktree で `git rebase 13.x` してからやり直す。

- [ ] **Step 2: `composer dump-autoload` は不要**

新規の PHP クラスは 1 つも足していない（新規は Blade コンポーネントとテストだけ）。
classmap に変化が無いので実行しない。

- [ ] **Step 3: ユーザーに本番デプロイの可否を確認する**

`AskUserQuestion` で明示的に確認してから次へ進む。

- [ ] **Step 4: デプロイする**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

⚠ DB 変更は無いので SQL の実行は不要。
⚠ **`resources/css/app.css` を変えたので `npm run build` が要る** —— `deploy.sh` の先頭に
組み込み済みなので自動（ビルド失敗時は本番へ何も転送せず中断する）。
`view:cache` / `route:cache` の再生成も `deploy.sh` が行う。

- [ ] **Step 5: 本番で動作を確認する**

⚠ 本番 URL は `/index.php/` prefix が要る（`.../manage/tenant/area-buildings` は 302 で流れる）。
Playwright は未ログインで止まるので、実 Chrome（claude-in-chrome）の既存セッションを使う。

確認する 5 点:
1. `/tenant/area-buildings` の初期表示が**ビル名順**（187 棟。⚠ 漢字は読み順でない＝仕様）
2. バーに「並び替え: 既定（ビル名順）」が出ている
3. 「入居率」の見出しを押す → 入居率の高い順に並び、バーが「入居率 高い順」になる
4. その状態で「入居率 75% 以下」に絞る → **並び順が維持される**
5. 地図タブ → 登録モードの作業リストが**ビル名順**（⚠ 手順 3 の並び替えを掛けた状態で見る）

⚠ **本番の CSS が更新されたことの確認**（認証不要で取れる）:

```bash
curl -s https://www.mitsuwat.co.jp/system/manage/build/manifest.json | head -c 200
```

取得した `app-*.css` のファイル名がローカルのビルド成果物と一致し、
`grep -oF '.sortable-th-label'` がヒットすれば転移できている。

- [ ] **Step 6: worktree を片付ける**

```bash
cd /Users/masanori/site/manage && git worktree remove .claude/worktrees/area-building-sorting && git branch -d area-building-sorting
```

---

## 自己レビュー（プラン作成後の確認）

### 1. 設計書のカバレッジ

| 設計書の節 | 対応する Task |
|---|---|
| §4.1 対象列と URL（7 列） | Task 6 Step 3（`SORT_COLUMNS`）／Task 7 Step 3（見出し 7 本） |
| §4.2 「—」は昇順でも降順でも末尾（partition） | Task 6 Step 3（`applySort()`）／Task 6 Step 1 のテスト |
| §4.3 入居率は空室率の符号反転 | Task 6 Step 3（`sortValue()`）／`..._reverse_of_the_vacancy_order` |
| §4.4 既定順をビル名の昇順へ | Task 5 |
| §4.4.1 同点の扱い・日付は文字列で比較 | Task 6 Step 3 ＋ `test_tied_rows_keep_the_building_name_order` |
| §4.5 フィルタ・ページャ・地図タブ／未登録リストの固定 | Task 7（hidden・タブ往復）／Task 9（未登録リスト） |
| §5 見出しの視認性（案A） | Task 4 |
| §6 現在の並び順バー（案C のバー） | Task 8 |
| §7.1 ラベルを 1 つのマップへ集約 | Task 3（物件・部屋）／Task 6（周辺ビル） |
| §7.2 触るファイル 新規 1 / 変更 11 | File Structure の表（テストを除くと一致） |
| §8.1 並び替えのテスト 9 項目 | Task 6 Step 1（1〜8 のうち URL 直叩き分）／Task 7 Step 1（往復・タブ） |
| §8.1.1 既定順の変更 4 項目 | Task 5 Step 1（1・`test_occupancy_bands` の是正）／Task 6（2）／Task 9（3・4） |
| §8.2 意匠 | Task 4 Step 1 |
| §8.3 バー | Task 8 Step 1 |
| §8.4 ラベルの単一化（本数の固定） | Task 3 Step 1 ／ Task 7 Step 5 |
| §8.5 既存テストへの影響 | Task 2（ヘルパの両方向）／各 Task の「全テスト」ステップ |
| §8.6 変異テスト 10 通り | Task 10（15 通りに拡張） |
| §9 ブラウザでの確認 7 点 | Task 11 Step 4 |
| §10 やらないこと | どの Task にも含めていない（ビル名の見出し・読み順・塗り分け・`ensureInLocateList` の差し込み・位置/操作の並び替え・所在地の再表示） |
| §11 本番反映 | Task 12 |

**穴なし。** ただし §8.1-9（地図タブで並び順が持ち回される）は設計書では 1 項目だが、
Task 7 の `test_the_sort_survives_a_trip_through_the_map_tab` で表↔地図の**往復**まで見ている。

### 2. プレースホルダ

「TBD」「後で」「適切に」「同様に」を検索して 0 件。すべての Step に実際のコード・
実際のコマンド・期待する出力がある。

### 3. 型・名前の一貫性

- 定数名は 3 画面とも `SORT_COLUMNS`（`public const`）。`SORTABLE` は Task 3 で消える
- ビューへ渡す変数名は 3 画面とも `$sort` / `$sortColumns`
- コンポーネントの props は `column` / `columns` / `sort` / `align` / `linkClass` / `linkStyle`、
  バーは `sort` / `columns` / `defaultLabel`
- サービスの新規メソッドは `applySort()` / `sortValue()`（どちらも `private`）
- `ListSort` の新規は `clearUrl()`（public）と `buildUrl()`（private）
- テストヘルパの新規は `thInnerFor()`、内部は `sortableHeaderCell()`
- `rows(Request $request, ?ListSort $sort = null)` — 既定値を持たせてあるので、
  もし将来 2 つ目の呼び出し元ができても既定順で動く（**既存の唯一の呼び出し元は Task 6 で更新済み**）

### 4. テスト本数の見込み

| Task | 増減 | 累計 |
|---|---|---|
| 基準（2026-08-28 実測） | — | 997 |
| Task 1（`clearUrl` 4 本） | +4 | 1001 |
| Task 2（ヘルパ 6 本） | +6 | 1007 |
| Task 3（走査 1 本） | +1 | 1008 |
| Task 4（意匠 4 本） | +4 | 1012 |
| Task 5（既存 1 本を書き換え） | ±0 | 1012 |
| Task 6（並び替え 8 本） | +8 | 1020 |
| Task 7（往復 6 本） | +6 | 1026 |
| Task 8（バー 7 本） | +7 | 1033 |
| Task 9（地図 2 本） | +2 | 1035 |

⚠ **この数は目安**。各 Task の「全テストを走らせる」で実測値と食い違ったら、
**プランの数字ではなく実測を信じる**（前回は引き継ぎメモの 6138 が実測 6141 と食い違った）。

### 5. プラン作成時に実測したこと（推測で書いていない箇所の裏取り）

| 事実 | 測り方 |
|---|---|
| 基準は 997 tests / 6141 assertions | `.claude/worktrees/tenant-list-sorting` で `vendor/bin/phpunit`（コードは 13.x と同一：`git diff --stat ebec4420 179c9482` が docs 2 本のみ） |
| `rows()` の呼び出し元は 1 箇所 | `grep -rn '\->rows(\$request' app/ tests/` → `AreaBuildingController.php:45` のみ |
| `total_floors` は `integer` キャスト | `AreaBuilding::casts()` |
| `x-sortable-th` を使うのは 2 ビュー・`ListSort` を使うのは 2 コントローラ | `grep -n "x-sortable-th\|x-sort-hidden" resources/views/tenant/{units,properties}/index.blade.php` |
| `app.css` の並び替えセクションは 84〜93 行（`:focus-visible` のみ） | `sed -n '70,110p' resources/css/app.css` |
| 3 一覧とも `{{-- テーブル --}}` の直後に表のコンテナが来る | 3 ファイルを実読 |
| 空室率 = (空き + 不明) ÷ 総数 の 1/10% 切り捨て、入居率はその裏返し | `VacancyRate::percent()` / `occupancyPercent()` |
| `main repo/node_modules/.bin/vite` が存在する | `ls -la` |

### 6. 既知の穴（意図して塞いでいないもの）

- **表と作業リストで照合順序が違う。** 表は SQL（本番 `utf8mb4_unicode_ci`）、
  地図の作業リストは PHP（バイト順）。漢字・かな主体の名前では一致するが、
  大文字小文字だけが違う名前では本番で食い違いうる（Task 9 Step 3 の docblock に明記）
- **`ensureInLocateList()` で復帰した棟は作業リストの末尾に出る**（設計書 §4.5 の既知の例外）
- **`x-sortable-th` の `column` を打ち間違えるとその画面が 500**（設計書 §7.1 の意図した取引。
  `SortableListWiringTest` が静的に守る）
- **点線下線そのものは 2.43:1 で 3:1 に届かない**（設計書 §5 の意図した選択。
  手掛かりの本体は 4.63:1 の矢印）
