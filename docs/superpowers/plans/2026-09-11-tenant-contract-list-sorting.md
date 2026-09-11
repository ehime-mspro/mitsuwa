# テナント契約一覧 契約日の既定順と見出しの並び替え Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント契約一覧 `/tenant/contracts` の初期表示を契約日の新しい順にし、表の先頭に契約日の列を足し、契約日・物件 / 区画・賃料収入の 3 列を見出しで並び替えられるようにする。

**Architecture:** 並べ替えは SQL（`ContractController::applySort()`）で全件に対して行い、そのあとページを切る。`?sort` / `?dir` の解釈・周期・リンク URL は既存の `App\Support\ListSort` を使い、**列ごとに「1 回目の向き」と「既定順の列か」を指定できる省略可能な引数**を足す（省略すれば従来と 1 行も変わらない）。見出しは既存の `x-sortable-th` が `SORT_COLUMNS` の `first` / `default` を読んで `ListSort` へ渡す。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（Alpine・JavaScript の追加は **1 行も無し**）/ Tailwind v4 / PHPUnit 11（SQLite in-memory + `RefreshDatabase`）

**設計書:** @docs/superpowers/specs/2026-09-11-tenant-contract-list-sorting-design.md
**前例の設計書:** @docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md（URL 仕様・3 状態・約束ごと）／ @docs/superpowers/specs/2026-08-28-area-building-sorting-design.md（見出しの意匠・並び順バー）
**前例の計画:** @docs/superpowers/plans/2026-08-25-tenant-list-sorting.md ／ @docs/superpowers/plans/2026-08-28-area-building-sorting.md

---

## 前提の確認（実装前に必ず読む）

| # | 事実 | 出典 |
|---|---|---|
| 1 | **DB 変更・ルート変更・新規 PHP クラスはいずれも無し。** マイグレーションも raw SQL も書かない。`composer dump-autoload` も不要 | 設計書 §6.1 / §10 |
| 2 | **JavaScript を 1 行も足さない。** 見出しも「解除」もただの `<a href>` | 前例 |
| 3 | **`ListSort` の省略時の挙動は従来と 1 行も変わらない。既存テストは 1 本も書き換えない**（それ自体が「物件一覧・部屋一覧・周辺ビル調査の周期が不変」の証明になる） | 設計書 §3.2 / §7.2 |
| 4 | 契約一覧の**既定の絞り込みは「契約中」**（`$request->input('status', 'active')`）。**解約済みを含めて並びを見るテストは `?status=all` を付ける** | `ContractController::index()` |
| 5 | テストの SQLite（migration）では `contracts.customer_id` と `contracts.rent_start_date` が **NOT NULL**。契約を作るときは顧客と家賃発生日を必ず入れる。⚠ よって**「家賃発生日未設定の ⚠」はテストでは作れない**（本番は nullable。ブラウザ確認で扱う） | `0001_01_01_000007_create_contracts_table.php` |
| 6 | `units` は **UNIQUE(property_id, display_name)**。区画の `display_name` は **`Unit::generateDisplayName($floor, $room)`**（本番と同じ関数）で作る | `0001_01_01_000006_create_units_table.php` |
| 7 | **SQLite は同点の行を id の昇順で返す**（2026-09-11 実測・SQLite 3.53.4。下の「プラン作成時に実測したこと」）。よって「最後の `contracts.id DESC` を消す」変異は、**id の大きい行を既定順で先に出す**データで赤くなる | 実測 |
| 8 | テストの物件名は **`A館` / `B館` / `C館`**（先頭が ASCII 大文字）。SQLite のバイト順と本番 MySQL の `utf8mb4_unicode_ci` で**順序が一致する** | 設計書 §4.4 |
| 9 | `withQueryString()` は**変えない**。null のキーを捨てる（Bug #31）が、この画面は「すべて」が `value="all"`（空でない）、`property_id=` / `keyword=` の空は既定と同じ意味なので**落ちても結果が変わらない**（Bug #31 の 2026-07-30 全一覧実測で `/tenant/contracts` は「無事」と判定済み） | 設計書 §9 / Bug #31 |
| 10 | Blade コンポーネントの属性に **`&quot;` を書かない**（本番の `view:cache` でだけ 500） | Bug #21 |
| 11 | **JS のコメント（`//` と `/* */`）の中に `@ディレクティブ` や `<x-…>` を書かない**（Blade が展開して `view:cache` を壊す）。説明を書くなら Blade コメント `{{-- --}}` | Bug #30 |
| 12 | `table-layout: fixed` の表が横スクロール枠の中にあるときは **`min-width` を持つこと**が `MobileLayoutTest` で強制されている。`min-w-[…]` を消さない | `tests/Feature/MobileLayoutTest.php:183` |
| 13 | `x-sortable-th` の `column` を打ち間違えると**その画面が 500**（意図した取引）。静的な防波堤は `SortableListWiringTest` | 前例 §7.1 |
| 14 | **「テストが緑」は検証にならない。** 変異を当てて赤になること・**落ちた理由の文言まで**確かめる | Bug #42 / #44 / #45 |

### この計画で決めた「設計書に無い」こと（実装者が迷わないための明示）

| 論点 | 決定 | 理由 |
|---|---|---|
| テストの部屋番号 | 英字 `A` / `B` / `C`（`display_name` は `1A` 形式） | 設計書 §2.2 の実データの形（「号室名（例: A, B, C）」）に合わせる |
| `$first` の検査の置き場所 | `stateOf()` の先頭（`next()` と `url()` は `stateOf()` を経由するので 3 つとも例外になる） | 1 箇所に置けば迂回路が無い。テストは 3 つそれぞれを叩いて固定する |
| 列幅の初期値 | `min-w-[900px]`、列 **14 / 20 / 20 / 16 / 12 / 18 %** | 字幅の見積もり（Task 4 の表）。**確定値は Task 8 の実測で決める**（設計書 §4.7） |
| 手入力 `?sort=contract_date&dir=desc` のバー | 「並び替え: 契約日 新しい順」＋「解除」をそのまま出す | 設計書 §4.3 が「正規化はしない」と決めている。並び替え中と名乗るのは事実どおりで、解除しても同じ並びに戻るだけ |
| テストの補助メソッド | テストファイルごとに持つ（共通 trait は作らない） | 前例（`UnitListSortTest` / `PropertyListSortTest` / `SortBarTest` が同じ最小構成を持つ）に合わせる。設計書 §6.1 のファイル一覧にも trait は無い |
| 変異の当て方 | **置換件数がちょうど 1 でなければ止まる PHP スクリプト**（needle / replacement はファイル渡し） | 前例の計画の欠陥 #1（perl の `\Q…\E` が 0 件マッチで exit 0 ＝ 無効な測定） |

---

## File Structure

| | ファイル | 責務 |
|---|---|---|
| 新規 | `tests/Feature/Tenant/ContractListSortTest.php` | 契約一覧の既定順・並び替え・見出しの周期・契約日の列 |
| 変更 | `app/Support/ListSort.php` | `stateOf()` / `next()` / `url()` に省略可能な `$first` / `$isDefault` ＋ `opposite()` / `assertDirection()` |
| 変更 | `resources/views/components/sortable-th.blade.php` | `$columns[$column]` から `first` / `default` を読んで `ListSort` へ渡す |
| 変更 | `app/Models/Contract.php` | `MONTHLY_TOTAL_SQL`（`getMonthlyTotalAttribute()` の**真横**） |
| 変更 | `app/Http/Controllers/Tenant/ContractController.php` | `SORT_COLUMNS` ＋ `index()` の組み替え ＋ `applySort()` |
| 変更 | `resources/views/tenant/contracts/index.blade.php` | 契約日の列・見出し 3 本・hidden・バー・colgroup・最小幅・空行の colspan |
| 変更 | `tests/Unit/Support/ListSortTest.php` | 拡張分のテストを**末尾に追加するだけ**（既存は触らない） |
| 変更 | `tests/Feature/SortableListWiringTest.php` | 4 画面目の登録 ＋ `SORT_COLUMNS` の任意キーの形 |
| 変更 | `tests/Feature/Tenant/SortBarTest.php` | 4 画面目の登録 ＋ 既定順の文言と並びの対 ＋ 向きの言い方 |
| 変更 | `docs/BACKLOG.md` | 完了の記録 |

---

## Task 0: 作業環境を確認する

**Files:** なし（確認のみ）

⚠ worktree は前セッションで作成済み。**作り直さない。**
⚠ **main repo でテストを流さない**（vendor が `--no-dev`。dev 依存を入れると `./deploy.sh` が本番へ送る）。
⚠ **worktree に `.env` を作らない**（ハーネスが止める。`.env` が無いこと自体が、実 DB へ届かないための安全装置）。

**以降、すべてのコマンドは `/Users/masanori/site/manage/.claude/worktrees/contract-list-sorting` を cwd として実行する。**
サブエージェントへ渡すときはプロンプトに「Work from: /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting」と明記し、
git は `git -C /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting …` で呼ぶ（サブエージェントの cwd は main repo に固定される）。

- [ ] **Step 1: worktree と HEAD を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting
git worktree list
git log --oneline -1
git status --porcelain && echo "---clean---"
git merge-base --is-ancestor 13.x HEAD && echo "OK: 13.x を含んでいる"
```

Expected: `contract-list-sorting` の行がある ／ HEAD は設計書のコミット `f119e2ef`（またはこの計画のコミット）／ `---clean---` ／ `OK: 13.x を含んでいる`

- [ ] **Step 2: 設定キャッシュと `.env` が無いことを確認する**

⚠ `bootstrap/cache/config.php` があると、Task 8 で環境変数による DB の差し替えが**黙って無視され**、実 DB の設定で動く。

```bash
test ! -f bootstrap/cache/config.php && echo "OK: 設定キャッシュなし"
test ! -e .env && echo "OK: .env なし"
```

Expected: 2 行とも OK

- [ ] **Step 3: 基準になるテスト結果を実測する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1315 tests, 8681 assertions)`（2026-09-11 に実測済み）

⚠ 数が違ったら、**プランの数字ではなく実測を信じる。** 以降の Task は「1315 → N」と書くので、ここで得た数を基準にする。

---

## Task 1: `ListSort` — 列ごとに「1 回目の向き」と「既定順の列か」を指定できるようにする

**Files:**
- Modify: `app/Support/ListSort.php`
- Test: `tests/Unit/Support/ListSortTest.php`（**末尾に追加するだけ**。既存 18 本は 1 文字も触らない）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ListSortTest.php` の**最後の `}`（クラスの閉じ）の直前**に追加する:

```php
    // ================================================================
    // 列ごとの周期（設計書 2026-09-11 §5）
    // ⚠ ここより上の既存テストは 1 本も書き換えない。省略時の挙動が
    //   従来と同一であることの証明になる（設計書 §7.2）。
    // ================================================================

    /** 1 回目が昇順の列: 既定 → 昇順 → 降順 → 既定（契約一覧の「物件 / 区画」） */
    public function test_a_column_whose_first_click_is_ascending_cycles_asc_desc_then_default(): void
    {
        $this->assertSame(ListSort::ASC, ListSort::next(null, 'rent', ListSort::ASC), '1 回目が昇順になっていない');

        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);
        $this->assertSame(ListSort::DESC, ListSort::next($asc, 'rent', ListSort::ASC), '2 回目が降順になっていない');

        $desc = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $this->assertNull(ListSort::next($desc, 'rent', ListSort::ASC), '3 回目は並び替え解除');

        // 別の列で並び替え中に押しても、1 回目は昇順から
        $this->assertSame(ListSort::ASC, ListSort::next($desc, 'area', ListSort::ASC));

        // first は「押したときの向き」であって「今の状態」ではない。並び替えていなければ消灯のまま
        $this->assertNull(ListSort::stateOf(null, 'rent', ListSort::ASC));
    }

    /**
     * 既定順の列: 既定（＝その列の first 向きが点灯）→ 逆向き → 既定（契約一覧の「契約日」）。
     *
     * ⚠ 並び替え指定が無いときに null を返すと、契約日で並んでいるのに見出しは ⇅ のまま・
     *   aria-sort="none" になり、読み上げにも嘘をつく（設計書 §3.1）。
     */
    public function test_the_default_column_is_lit_without_a_sort_and_steps_to_the_opposite_then_back(): void
    {
        $this->assertSame(ListSort::DESC, ListSort::stateOf(null, 'area', ListSort::DESC, isDefault: true), '既定順の列が初期表示で点灯していない');
        $this->assertSame(ListSort::ASC, ListSort::next(null, 'area', ListSort::DESC, isDefault: true), '既定から押すと逆向きへ進むべき');

        $asc = ListSort::fromRequest($this->request('/x?sort=area&dir=asc'), self::ALLOWED);
        $this->assertSame(ListSort::ASC, ListSort::stateOf($asc, 'area', ListSort::DESC, isDefault: true));
        $this->assertNull(ListSort::next($asc, 'area', ListSort::DESC, isDefault: true), '逆向きの次は既定へ戻す');

        // 別の列で並び替え中 → この列は消灯し、押すと既定へ戻す（sort を載せない）
        $rent = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $this->assertNull(ListSort::stateOf($rent, 'area', ListSort::DESC, isDefault: true), '別の列で並び替え中なのに既定順の列が点灯している');
        $this->assertNull(ListSort::next($rent, 'area', ListSort::DESC, isDefault: true), '別の列から押したら既定へ戻す（既定と同じ並びを別の状態として作らない）');

        // 手入力の「既定と同じ向き」は正規化しない。押すと逆向きへ進む（設計書 §4.3）
        $desc = ListSort::fromRequest($this->request('/x?sort=area&dir=desc'), self::ALLOWED);
        $this->assertSame(ListSort::DESC, ListSort::stateOf($desc, 'area', ListSort::DESC, isDefault: true));
        $this->assertSame(ListSort::ASC, ListSort::next($desc, 'area', ListSort::DESC, isDefault: true));
    }

    /** url() が first / isDefault を反映すること（見出しの href はここから出る） */
    public function test_url_follows_the_first_direction_and_the_default_column(): void
    {
        // 1 回目が昇順の列: 並び替え無しから押すと dir=asc（page は落とす）
        $url = ListSort::url($this->request('/tenant/contracts?page=2'), 'rent', null, ListSort::ASC);
        $this->assertStringContainsString('sort=rent', $url);
        $this->assertStringContainsString('dir=asc', $url, '1 回目が昇順の列なのに dir=asc になっていない');
        $this->assertStringNotContainsString('page=', $url);

        // 既定順の列: 並び替え無しから押すと逆向き
        $url = ListSort::url($this->request('/tenant/contracts'), 'area', null, ListSort::DESC, isDefault: true);
        $this->assertStringContainsString('sort=area', $url);
        $this->assertStringContainsString('dir=asc', $url, '既定順の列を押したら逆向きへ進むべき');

        // 既定順の列: 別の列で並び替え中に押すと既定へ（sort も dir も載せない・絞り込みは残す）
        $rent = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $url = ListSort::url($this->request('/tenant/contracts?sort=rent&dir=desc&status=all'), 'area', $rent, ListSort::DESC, isDefault: true);
        $this->assertStringNotContainsString('sort=', $url, '既定順の列を押したのに並び替えが残っている');
        $this->assertStringNotContainsString('dir=', $url);
        $this->assertStringContainsString('status=all', $url, '絞り込みまで消えている');
    }

    /**
     * $first が asc / desc 以外なら例外（設計書 §5）。
     *
     * ⚠ 黙って変な周期で回るより、配線テストで 500 として見つかるほうが良い
     *   （x-sortable-th の column 打ち間違いと同じ方針）。
     * ⚠ **3 つの入口をそれぞれ叩く。** 検査は stateOf() に 1 箇所だけ置き、next() と url() は
     *   そこを経由する作りだが、誰かが経由をやめても落ちるように入口ごとに固定する。
     * ⚠ 'ASC'（大文字）も不正。ListSort::ASC は小文字の 'asc'。
     */
    public function test_an_unknown_first_direction_is_rejected(): void
    {
        $calls = [
            'stateOf' => fn (string $first) => ListSort::stateOf(null, 'rent', $first),
            'next'    => fn (string $first) => ListSort::next(null, 'rent', $first),
            'url'     => fn (string $first) => ListSort::url($this->request('/x'), 'rent', null, $first),
        ];

        foreach ($calls as $name => $call) {
            foreach (['ASC', 'up', ''] as $bad) {
                try {
                    $call($bad);
                    $this->fail("{$name}() が first='{$bad}' を黙って受け入れた");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString("'{$bad}'", $e->getMessage(), '例外の文言に不正な値が出ていない');
                }
            }
        }
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ListSortTest
```

Expected: 追加した 4 本が FAIL / ERROR（`1 回目が昇順になっていない` ／ `Unknown named parameter $isDefault` ／ `stateOf() が first='ASC' を黙って受け入れた`）。**既存 18 本は OK のまま**。

⚠ PHP は**ユーザー定義関数への余分な位置引数を黙って捨てる**ので、`next(null, 'rent', ListSort::ASC)` は現状でもエラーにならず「降順」を返す。落ちる理由がアサーションの文言であることを確かめる。

- [ ] **Step 3: `ListSort` を実装する**

`app/Support/ListSort.php` を次の 5 か所だけ変える。

① クラスの docblock の設計書の行（11 行目）:

```php
 * 設計書: docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md
```

を次に置き換える:

```php
 * 設計書: docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md
 *         docs/superpowers/specs/2026-09-11-tenant-contract-list-sorting-design.md（列ごとの周期）
```

② `next()`（docblock ごと）を次に置き換える:

```php
    /**
     * その列を今押したときの次の向き。null は「並び替え解除（既定順へ戻す）」。
     *
     * 周期は列ごとの 2 つの指定で決まる（設計書 2026-09-11 §5）:
     *   $first     … その列を初めて押したときの向き。省略時 DESC
     *                （金額と率なので「多い順」を先に見たい、という前例の判断）
     *   $isDefault … その列が画面の既定順か。既定順は「その列の $first 向き」とみなす
     *
     *   通常の列:   既定 → $first → 逆向き → 既定（3 状態）
     *   既定順の列: 既定（＝ $first が点灯）→ 逆向き → 既定（2 状態）
     *
     * ⚠ **省略時は従来と 1 行も変わらない**（既定 → 降順 → 昇順 → 既定）。
     * ⚠ 既定順の列に「既定と逆向きの 1 回目」は定義しない。既定から押した先が
     *   既定と同じ並びになり、押しても何も変わらない状態が生まれる（設計書 §5）。
     */
    public static function next(?self $current, string $key, string $first = self::DESC, bool $isDefault = false): ?string
    {
        $state = self::stateOf($current, $key, $first, $isDefault);

        if ($state === null) {
            // ⚠ 既定順の列は「既定へ戻す」＝ sort を載せない。既定と同じ並びを
            //   「契約日 新しい順」という別の状態として作らない（設計書 §4.3）
            return $isDefault ? null : $first;
        }

        return $state === $first ? self::opposite($first) : null;
    }
```

③ `stateOf()`（docblock ごと）を次に置き換える:

```php
    /**
     * その列の現在の向き。並び替えに使っていなければ null。
     *
     * ⚠ 既定順の列（$isDefault）は、並び替え指定が無ければ $first を返す＝初期表示で見出しが点灯する。
     *   null を返すと、契約日で並んでいるのに見出しは ⇅ のまま・aria-sort="none" になり、
     *   読み上げにも嘘をつく（設計書 2026-09-11 §3.1）。
     * ⚠ $first の検査はここ 1 箇所。next() と url() はここを経由する。
     */
    public static function stateOf(?self $current, string $key, string $first = self::DESC, bool $isDefault = false): ?string
    {
        self::assertDirection($first);

        if ($current === null) {
            return $isDefault ? $first : null;
        }

        return $current->key === $key ? $current->direction : null;
    }
```

④ `url()` のシグネチャと `next()` の呼び出しの 2 行だけを変える。

```php
    public static function url(Request $request, string $key, ?self $current): string
```

を

```php
    public static function url(Request $request, string $key, ?self $current, string $first = self::DESC, bool $isDefault = false): string
```

に、

```php
        $next = self::next($current, $key);
```

を

```php
        $next = self::next($current, $key, $first, $isDefault);
```

に置き換える（`url()` の docblock と残りの行は変えない）。

⑤ `buildUrl()` の後ろ（クラスの閉じ `}` の直前）に追加する:

```php

    private static function opposite(string $direction): string
    {
        return $direction === self::ASC ? self::DESC : self::ASC;
    }

    /**
     * ⚠ 黙って変な周期で回るより、配線テストで 500 として見つかるほうが良い
     *   （x-sortable-th の column 打ち間違いと同じ方針。設計書 2026-09-11 §5）。
     *   SORT_COLUMNS に 'first' => 'ASC'（大文字）と書くと、ここで止まる。
     */
    private static function assertDirection(string $direction): void
    {
        if ($direction !== self::ASC && $direction !== self::DESC) {
            throw new \InvalidArgumentException("並び替えの向きは asc か desc のどちらか: '{$direction}'");
        }
    }
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ListSortTest
```

Expected: `OK (22 tests, …)`

- [ ] **Step 5: 全テストを走らせる（前例の 3 画面の周期が不変であることの確認）**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1319 tests, …)`。**既存の `UnitListSortTest` / `PropertyListSortTest` / `AreaBuildingListSortTest` / `SortBarTest` が 1 本も落ちないこと**（`x-sortable-th` はまだ 2 引数で呼んでいる＝省略時の挙動だけで動いている）。

- [ ] **Step 6: コミット**

```bash
git add app/Support/ListSort.php tests/Unit/Support/ListSortTest.php
git commit -m "$(cat <<'EOF'
feat(sort): 列ごとに 1 回目の向きと既定順の列を指定できるようにする

ListSort::stateOf() / next() / url() に省略可能な $first / $isDefault を足す。
省略時の挙動は従来と同一（既存テストは書き換えていない）。
$first が asc / desc 以外なら InvalidArgumentException。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `Contract::MONTHLY_TOTAL_SQL` — 賃料収入を SQL でも出す

**Files:**
- Modify: `app/Models/Contract.php`
- Create: `tests/Feature/Tenant/ContractListSortTest.php`（以降の Task でもこのファイルにテストを足していく）

- [ ] **Step 1: テストファイルを作り、失敗するテストを書く**

`tests/Feature/Tenant/ContractListSortTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * テナント契約一覧の既定順と見出しの並び替え（設計書 2026-09-11）。
 *
 * ⚠ 既定の絞り込みは「契約中」。解約済みを含めて並びを見るときは ?status=all を付ける。
 * ⚠ 物件名は A館 / B館 / C館（先頭が ASCII 大文字）。SQLite のバイト順と本番 MySQL の
 *   utf8mb4_unicode_ci で順序が一致する（設計書 §4.4）。漢字で始まる名前にすると
 *   本番とテストで順が変わりうる。
 * ⚠ **作成順（＝ id 順）を、既定順・並び替えた順のどれとも食い違わせる。** SQLite は同点の行を
 *   id の昇順で返す（2026-09-11 実測）ので、揃えるとキーを 1 つ消す変異が「たまたま同じ順」で
 *   素通りする（UnitListSortTest::test_tied_rows_keep_the_default_order と同じ理屈）。
 *   各テストは作成順を assertSame で先に固定してから並びを見る。
 * ⚠ 経営層は department.access を素通りする（部門の紐付けは要らない）。
 */
class ContractListSortTest extends TestCase
{
    use ParsesForms;
    use ParsesSortLinks;
    use RefreshDatabase;

    private ?Customer $customer = null;

    private int $seq = 0;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 契約に要る顧客（テストの SQLite では customer_id が NOT NULL。1 件を使い回す） */
    private function customer(): Customer
    {
        return $this->customer ??= Customer::create([
            'code' => 'CUST-CS001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
    }

    /** ⚠ $name は SQLite と MySQL で順序が一致するもの（クラスの docblock） */
    private function makeProperty(string $name): Property
    {
        return Property::create([
            'code' => sprintf('T-CS%03d', ++$this->seq),
            'name' => $name,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'operation_status' => 'active',
            'address' => '愛媛県松山市本町1-1',
        ]);
    }

    /** display_name は本番と同じ Unit::generateDisplayName() で作る（UNIQUE(property_id, display_name)） */
    private function makeUnit(Property $property, ?int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => 'occupied',
        ]);
    }

    /**
     * 契約を 1 件作る。家賃発生日は契約日と同じ（テストの SQLite では NOT NULL）。
     *
     * @param  array<string, mixed>  $attrs  rent / common_fee / status / store_name などの上書き
     */
    private function makeContract(Unit $unit, string $contractDate, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'contract_number'  => sprintf('C-CS-%03d', ++$this->seq),
            'department'       => 'tenant',
            'property_id'      => $unit->property_id,
            'unit_id'          => $unit->id,
            'customer_id'      => $this->customer()->id,
            'status'           => 'active',
            'contract_date'    => $contractDate,
            'rent_start_date'  => $contractDate,
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 作成順が id 順であることを固定する（崩れると各テストの「変異の検出力」の前提が成立しない） */
    private function assertCreatedInOrder(array $contracts): void
    {
        $this->assertSame(
            array_map(fn (Contract $c) => $c->id, $contracts),
            Contract::orderBy('id')->pluck('id')->all(),
            '作成順が id 順になっていない（テストの前提が崩れている）'
        );
    }

    /** 1 ページ目の契約 ID（表示順のまま） */
    private function listedIds(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('contracts')->pluck('id')->all();
    }

    /** 契約の ID の並び */
    private function ids(Contract ...$contracts): array
    {
        return array_map(fn (Contract $c) => $c->id, $contracts);
    }

    /**
     * 賃料収入の SQL 式と PHP アクセサが同じ値を出すこと（Bug #41。設計書 §7.1）。
     *
     * ⚠ 片方だけ直すと、画面の数字は正しいのに並び順だけが別の値で並ぶ。画面から気づけない。
     * ⚠ 共益費・ゴミ代・駆除代が NULL の行を必ず含める。0 で作ると COALESCE の経路を
     *   一度も通らず、COALESCE を外す変異が素通りする。
     * ⚠ (int) キャストは NULL を 0 に潰すので、キャストの前に NULL でないことを見る
     *   （UnitListSortTest と同じ。COALESCE を全部外す変異が緑のまま通った前例がある）。
     */
    public function test_the_income_sql_agrees_with_the_php_accessor(): void
    {
        $property = $this->makeProperty('A館');
        $this->makeContract($this->makeUnit($property, 1, 'A'), '2026-04-01', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000]);
        $this->makeContract($this->makeUnit($property, 1, 'B'), '2026-04-01', ['rent' => 180000, 'common_fee' => 18000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $nulls = $this->makeContract($this->makeUnit($property, 2, 'A'), '2026-04-01', ['rent' => 95000, 'common_fee' => null, 'garbage_fee' => null, 'pest_control_fee' => null]);
        $this->makeContract($this->makeUnit($property, 2, 'B'), '2026-04-01', ['rent' => 120000, 'common_fee' => 0, 'garbage_fee' => null, 'pest_control_fee' => 700]);

        $fresh = $nulls->fresh();
        $this->assertNull($fresh->common_fee, '共益費が NULL のデータになっていない（COALESCE の経路を通らない）');
        $this->assertNull($fresh->garbage_fee);
        $this->assertNull($fresh->pest_control_fee);

        $fromSql = Contract::selectRaw('id, ' . Contract::MONTHLY_TOTAL_SQL . ' as total')->pluck('total', 'id')->all();

        $values = [];
        foreach (Contract::orderBy('id')->get() as $contract) {
            $this->assertNotNull($fromSql[$contract->id], 'COALESCE が外れて式が NULL になっている');
            $this->assertSame(
                $contract->monthly_total,
                (int) $fromSql[$contract->id],
                "契約 {$contract->contract_number} の月額合計が SQL 式と PHP アクセサで食い違う"
            );
            $values[] = $contract->monthly_total;
        }

        // ⚠ 値に分散が無いと「SQL を壊しても PHP と一致」で false-pass しうる（Bug #40）
        $this->assertGreaterThan(1, count(array_unique($values)), '月額合計に分散が無いデータでは検出力が出ない');
        $this->assertContains(315000, $values, '4 項目すべてを足していない（285000+25000+3000+2000）');
        $this->assertContains(95000, $values, 'NULL の項目を 0 として足していない');
        $this->assertContains(120700, $values, '駆除代を足していない（120000+0+NULL+700）');
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ContractListSortTest
```

Expected: ERROR — `Undefined constant App\Models\Contract::MONTHLY_TOTAL_SQL`

- [ ] **Step 3: 定数を足す**

`app/Models/Contract.php` の `getMonthlyTotalAttribute()` の docblock（`/**` で始まる「契約条件の月額合計（家賃 + 共益費 + ゴミ代 + 駆除代）」）の**直前**に追加する（`Unit::MONTHLY_TOTAL_SQL` と同じ置き方）:

```php
    /**
     * 月額合計の SQL 式。**下の getMonthlyTotalAttribute() と同じ計算をする。**
     *
     * ⚠ 片方だけ直すと、画面の数字は正しいのに**並び順だけが別の値で並ぶ**（Bug #41）。
     *   画面から気づけないので、ContractListSortTest が両者の一致を固定している。
     * ⚠ 列は必ず `contracts.` で修飾する。契約一覧は units を JOIN しており、units にも
     *   rent / common_fee / garbage_fee / pest_control_fee があるので、無修飾だと
     *   ambiguous column で落ちる。
     * ⚠ COALESCE 済みなので**この式は NULL にならない**（Unit::MONTHLY_TOTAL_SQL と同じ形）。
     *   並び替えで `(… IS NULL)` を前置しても常に false ＝ 死んだ SQL になるので書かないこと。
     */
    public const MONTHLY_TOTAL_SQL = '(COALESCE(contracts.rent, 0) + COALESCE(contracts.common_fee, 0)'
        . ' + COALESCE(contracts.garbage_fee, 0) + COALESCE(contracts.pest_control_fee, 0))';

```

- [ ] **Step 4: テストが通ることを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ContractListSortTest
```

Expected: `OK (1 test, …)`

- [ ] **Step 5: 全テストを走らせる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1320 tests, …)`

- [ ] **Step 6: コミット**

```bash
git add app/Models/Contract.php tests/Feature/Tenant/ContractListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 契約の月額合計を SQL 式でも出せるようにする

契約一覧を賃料収入で並べるための Contract::MONTHLY_TOTAL_SQL。
getMonthlyTotalAttribute() との一致をテストで固定する（NULL の項目を含む）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 契約一覧の既定順を契約日にし、並び替えを受け付ける（コントローラ）

**Files:**
- Modify: `app/Http/Controllers/Tenant/ContractController.php`
- Modify: `tests/Feature/SortableListWiringTest.php`（`SORT_ENDPOINTS` へ登録。**登録しないと `SORT_COLUMNS` を足した瞬間に落ちる**）
- Test: `tests/Feature/Tenant/ContractListSortTest.php`

⚠ この Task のテストは `?sort=` を**自分で組み立てた URL** で並びだけを見る（仕組みのテスト）。
**見出しの href を辿る往復**は Task 5 で見る（Bug #47 / `ParsesSortLinks`）。前例の `UnitListSortTest` と同じ分け方。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/ContractListSortTest.php` の**クラスの最後の `}` の直前**に追加する:

```php
    /**
     * ページ送りのリンクを実際に辿って、全ページの契約 ID を順に集める。
     *
     * ⚠ **`?page=2` を自分で組み立ててはいけない。** リンクが壊れていても sort が付いた
     *   状態で届くので**必ず緑**になる（Bug #31）。$paginator->nextPageUrl() を辿ること。
     */
    private function collectIdsAcrossPages(User $user, string $url): array
    {
        $ids = [];
        $guard = 0;

        while ($url !== null) {
            $response = $this->actingAs($user)->get($url);
            $response->assertOk();

            $paginator = $response->viewData('contracts');
            foreach ($paginator as $contract) {
                $ids[] = $contract->id;
            }

            $url = $paginator->nextPageUrl();

            $this->assertLessThan(20, ++$guard, 'ページ送りが終わらない');
        }

        return $ids;
    }

    /**
     * 物件名の順と契約日の順が逆になる 3 件（B館 → C館 → A館 の順に作る）。
     *
     *   | 返り値 | 物件 | 契約日     | 作成順 |
     *   | [0] $a | A館  | 2024-04-01 | 3 |
     *   | [1] $b | B館  | 2025-04-01 | 1 |
     *   | [2] $c | C館  | 2026-04-01 | 2 |
     *
     *   既定（契約日の新しい順）: [$c, $b, $a]
     *   旧既定（物件名順）・契約日の古い順: [$a, $b, $c]   id の降順: [$a, $c, $b]
     *
     * @return array{0: Contract, 1: Contract, 2: Contract} [$a, $b, $c]
     */
    private function threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder(): array
    {
        $b = $this->makeContract($this->makeUnit($this->makeProperty('B館'), 1, 'A'), '2025-04-01');
        $c = $this->makeContract($this->makeUnit($this->makeProperty('C館'), 1, 'A'), '2026-04-01');
        $a = $this->makeContract($this->makeUnit($this->makeProperty('A館'), 1, 'A'), '2024-04-01');

        $this->assertCreatedInOrder([$b, $c, $a]);

        return [$a, $b, $c];
    }

    /**
     * 既定順は契約日の新しい順（設計書 §4.4）。
     *
     * ⚠ **物件名の順と契約日の順を逆にしてある。** 旧既定（物件名 → 階数 → 号室）に戻す
     *   変異を確実に赤くする（設計書 §7.1）。作成順も両方と食い違わせてあるので、
     *   「新しい契約 ＝ id が大きい」と取り違えて id の降順だけで並べる変異も赤くなる。
     */
    public function test_the_default_order_is_the_contract_date_newest_first(): void
    {
        [$a, $b, $c] = $this->threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder();

        $this->assertSame(
            $this->ids($c, $b, $a),
            $this->listedIds($this->actingAs($this->executive())->get(route('tenant.contracts.index'))),
            '既定順が契約日の新しい順になっていない'
        );
    }

    /**
     * 契約日の古い順（?sort=contract_date&dir=asc）。
     *
     * ⚠ 上の 3 件は使わない。あのデータの「契約日の古い順」は旧既定（物件名順）と同じ並びになり、
     *   **実装前から緑**になる（＝このテストが何も測らない）。物件 / 区画用の 4 件なら
     *   古い順 [$a2, $b1, $a3, $a1] ／ 旧既定 [$a1, $a2, $a3, $b1] ／ 既定 [$a1, $a3, $b1, $a2] ／
     *   id 順 [$b1, $a3, $a1, $a2] がすべて食い違う。
     */
    public function test_contract_date_can_be_sorted_oldest_first(): void
    {
        [$a1, $a2, $a3, $b1] = $this->fourContractsForThePropertyUnitColumn();

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.index', ['sort' => 'contract_date', 'dir' => 'asc']));

        $this->assertSame($this->ids($a2, $b1, $a3, $a1), $this->listedIds($response), '契約日の古い順になっていない');
    }

    /**
     * 同じ契約日の中は 物件名 → 階数 → 号室 → 契約 ID の新しい順（設計書 §4.4）。
     *
     * ⚠ **4 つのキーそれぞれが単独で検出力を持つ**ように組んである（SortBarTest の
     *   既定順テストと同じ流儀。キーを 1 つ消すと必ず並びが変わる）:
     *
     *   | 変数  | 物件 | 階 | 号室 | 状態     | 作成順 |
     *   | $old  | A館  | 5  | B    | 解約済み | 1 | ← $new と同じ区画（旧契約）
     *   | $u5a  | A館  | 5  | A    | 契約中   | 2 |
     *   | $new  | A館  | 5  | B    | 契約中   | 3 | ← $old と同じ区画（新契約）
     *   | $u2c  | A館  | 2  | C    | 契約中   | 4 |
     *   | $b1a  | B館  | 1  | A    | 契約中   | 5 |
     *
     *   期待: [$u2c, $u5a, $new, $old, $b1a]
     *   - 物件名を消すと: 階の昇順で B館 1A が先頭 → [$b1a, $u2c, $u5a, $new, $old]
     *   - 階を消すと:     A館の中が号室順 → [$u5a, $new, $old, $u2c, $b1a]
     *   - 号室を消すと:   5 階の中が id の降順 → [$u2c, $new, $u5a, $old, $b1a]
     *   - id DESC を消すと: 同点の $old / $new を SQLite が id の昇順で返す → [$u2c, $u5a, $old, $new, $b1a]
     *     （2026-09-11 に同じ形の SQL で実測）
     *
     * ⚠ 同じ区画の旧契約と新契約は物件名・階数・号室が全部同点になる。これが並びを一意にする
     *   最後のキー（contracts.id DESC）が要る理由（設計書 §2.3）。解約済みを含めるので ?status=all。
     */
    public function test_contracts_on_the_same_date_follow_property_floor_room_then_the_newer_contract(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');
        $unit5b = $this->makeUnit($a, 5, 'B');

        $old = $this->makeContract($unit5b, '2026-04-01', ['status' => 'terminated', 'contract_end_date' => '2026-06-30']);
        $u5a = $this->makeContract($this->makeUnit($a, 5, 'A'), '2026-04-01');
        $new = $this->makeContract($unit5b, '2026-04-01');
        $u2c = $this->makeContract($this->makeUnit($a, 2, 'C'), '2026-04-01');
        $b1a = $this->makeContract($this->makeUnit($b, 1, 'A'), '2026-04-01');

        $this->assertCreatedInOrder([$old, $u5a, $new, $u2c, $b1a]);

        $response = $this->actingAs($this->executive())->get(route('tenant.contracts.index', ['status' => 'all']));

        $this->assertSame(
            $this->ids($u2c, $u5a, $new, $old, $b1a),
            $this->listedIds($response),
            '同じ契約日の中が 物件名 → 階数 → 号室 → 契約 ID の新しい順になっていない'
        );
    }

    /**
     * 物件 / 区画で並べる 4 件（B館 1A → A館 2B → A館 1C → A館 2A の順に作る）。
     *
     *   | 返り値  | 物件 | 階 | 号室 | 契約日     | 作成順 |
     *   | [0] $a1 | A館  | 1  | C    | 2026-01-01 | 3 |
     *   | [1] $a2 | A館  | 2  | A    | 2024-01-01 | 4 |
     *   | [2] $a3 | A館  | 2  | B    | 2025-06-01 | 2 |
     *   | [3] $b1 | B館  | 1  | A    | 2025-01-01 | 1 |
     *
     *   昇順 [$a1, $a2, $a3, $b1] ／ 降順 [$b1, $a3, $a2, $a1] ／ 既定 [$a1, $a3, $b1, $a2]
     *   昇順でキーを 1 つ消すと:  物件名→[$b1, $a1, $a2, $a3]  階→[$a2, $a3, $a1, $b1]  号室→[$a1, $a3, $a2, $b1]
     *   降順で 1 キーだけ昇順に残すと: 階→[$b1, $a1, $a3, $a2]  号室→[$b1, $a2, $a3, $a1]
     *   ⚠ 号室の違いが階の違いと逆向き（1C / 2A）なので、階と号室を取り違えても赤くなる。
     *
     * @return array{0: Contract, 1: Contract, 2: Contract, 3: Contract} [$a1, $a2, $a3, $b1]
     */
    private function fourContractsForThePropertyUnitColumn(): array
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $b1 = $this->makeContract($this->makeUnit($b, 1, 'A'), '2025-01-01');
        $a3 = $this->makeContract($this->makeUnit($a, 2, 'B'), '2025-06-01');
        $a1 = $this->makeContract($this->makeUnit($a, 1, 'C'), '2026-01-01');
        $a2 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2024-01-01');

        $this->assertCreatedInOrder([$b1, $a3, $a1, $a2]);

        return [$a1, $a2, $a3, $b1];
    }

    /** 物件 / 区画は 物件名 → 階数 → 号室 の 3 キーとも同じ向きで並ぶ（降順は昇順の完全な逆順。設計書 §4.4） */
    public function test_property_unit_sorts_all_three_keys_in_the_same_direction(): void
    {
        [$a1, $a2, $a3, $b1] = $this->fourContractsForThePropertyUnitColumn();
        $user = $this->executive();

        $asc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'property_unit', 'dir' => 'asc']));
        $this->assertSame($this->ids($a1, $a2, $a3, $b1), $this->listedIds($asc), '物件 / 区画の昇順（物件名 → 階数 → 号室）になっていない');

        $desc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'property_unit', 'dir' => 'desc']));
        $this->assertSame($this->ids($b1, $a3, $a2, $a1), $this->listedIds($desc), '物件 / 区画の降順が昇順の完全な逆順になっていない（3 キーのどれかが昇順のまま）');
    }

    /**
     * 賃料収入で並べる 4 件。**4 項目のどれを式から落としても並びが変わる**ように組んである。
     *
     *   | 返り値 | 家賃   | 共益費 | ゴミ代 | 駆除代 | 合計   | 契約日     | 作成順 |
     *   | [0] $x | 100000 | 50000  | 0      | 0      | 150000 | 2024-04-01 | 2 |
     *   | [1] $w | 80000  | 0      | 0      | 60000  | 140000 | 2023-04-01 | 3 |
     *   | [2] $z | 90000  | 0      | 40000  | 0      | 130000 | 2025-04-01 | 1 |
     *   | [3] $y | 120000 | 0      | 0      | 0      | 120000 | 2026-04-01 | 4 |
     *
     *   多い順 [$x, $w, $z, $y] ／ 少ない順 [$y, $z, $w, $x] ／ 既定 [$y, $z, $x, $w]
     *   家賃だけで並べると [$y, $x, $z, $w]、共益費を落とすと [$w, $z, $y, $x]、
     *   ゴミ代を落とすと [$x, $w, $y, $z]、駆除代を落とすと [$x, $z, $y, $w]
     *
     * @return array{0: Contract, 1: Contract, 2: Contract, 3: Contract} [$x, $w, $z, $y]
     */
    private function fourContractsForTheIncomeColumn(): array
    {
        $property = $this->makeProperty('A館');

        $z = $this->makeContract($this->makeUnit($property, 1, 'A'), '2025-04-01', ['rent' => 90000,  'garbage_fee' => 40000]);
        $x = $this->makeContract($this->makeUnit($property, 1, 'B'), '2024-04-01', ['rent' => 100000, 'common_fee' => 50000]);
        $w = $this->makeContract($this->makeUnit($property, 2, 'A'), '2023-04-01', ['rent' => 80000,  'pest_control_fee' => 60000]);
        $y = $this->makeContract($this->makeUnit($property, 2, 'B'), '2026-04-01', ['rent' => 120000]);

        $this->assertCreatedInOrder([$z, $x, $w, $y]);

        return [$x, $w, $z, $y];
    }

    /** 賃料収入は 4 項目の合計で並ぶ（設計書 §4.4） */
    public function test_income_sorts_by_the_monthly_total_in_both_directions(): void
    {
        [$x, $w, $z, $y] = $this->fourContractsForTheIncomeColumn();
        $user = $this->executive();

        $desc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame($this->ids($x, $w, $z, $y), $this->listedIds($desc), '賃料収入の多い順になっていない（4 項目の合計で並べていない）');
        $this->assertSame(
            [150000, 140000, 130000, 120000],
            $desc->viewData('contracts')->map(fn (Contract $c) => $c->monthly_total)->all(),
            '賃料収入の実値が想定と違う'
        );

        $asc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'asc']));
        $this->assertSame($this->ids($y, $z, $w, $x), $this->listedIds($asc), '賃料収入の少ない順になっていない');
    }

    /**
     * 並び替え中に値が同じ行は、既定順を丸ごと後ろに付けて並べる（設計書 §4.4 / 前例 §4.3-3）。
     *
     *   | 変数 | 物件 | 階 | 契約日     | 賃料収入 | 作成順 |
     *   | $r1  | B館  | 1  | 2025-01-01 | 100000   | 1 |
     *   | $r2  | A館  | 1  | 2025-01-01 | 100000   | 2 |
     *   | $r3  | A館  | 2  | 2026-01-01 | 100000   | 3 |
     *
     *   賃料収入は 3 件とも同点 → **多い順でも少ない順でも**既定順 [$r3, $r2, $r1]
     *   - 後ろの既定順を丸ごと落とすと id 順 [$r1, $r2, $r3]
     *   - 後ろに契約日しか付けないと 2025 年の 2 件が id 順 [$r3, $r1, $r2]
     *   契約日の古い順は 2025 年の 2 件が同点 → 物件名順 [$r2, $r1, $r3]（後ろを落とすと [$r1, $r2, $r3]）
     */
    public function test_rows_tied_on_the_sorted_column_keep_the_whole_default_order(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $r1 = $this->makeContract($this->makeUnit($b, 1, 'A'), '2025-01-01');
        $r2 = $this->makeContract($this->makeUnit($a, 1, 'A'), '2025-01-01');
        $r3 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2026-01-01');

        $this->assertCreatedInOrder([$r1, $r2, $r3]);

        $user = $this->executive();

        foreach (['desc', 'asc'] as $dir) {
            $response = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => $dir]));
            $this->assertSame($this->ids($r3, $r2, $r1), $this->listedIds($response), "賃料収入が同点の行が既定順になっていない（{$dir}）");
        }

        $byDate = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'contract_date', 'dir' => 'asc']));
        $this->assertSame($this->ids($r2, $r1, $r3), $this->listedIds($byDate), '契約日が同点の行が既定順（物件名順）になっていない');
    }

    /** 不正な sort は 500 にせず既定順、不正な dir は降順（設計書 §4.2 / ListSort::fromRequest() の既存仕様） */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $property = $this->makeProperty('A館');
        $y = $this->makeContract($this->makeUnit($property, 1, 'A'), '2025-04-01', ['rent' => 300000]);
        $x = $this->makeContract($this->makeUnit($property, 1, 'B'), '2026-04-01', ['rent' => 100000]);
        $z = $this->makeContract($this->makeUnit($property, 1, 'C'), '2024-04-01', ['rent' => 200000]);

        $this->assertCreatedInOrder([$y, $x, $z]);

        $user = $this->executive();

        foreach ([
            '?sort=name',            // 許可リストに無い（店舗名・状態は並び替えない）
            '?sort[]=income',        // 配列で来る
            '?sort=%3Cscript%3E',    // 手入力・古いブックマーク
            '?sort=',                // 空
        ] as $queryString) {
            $this->assertSame(
                $this->ids($x, $y, $z),
                $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index') . $queryString)),
                "{$queryString} で既定順に落ちていない"
            );
        }

        // dir だけ不正なら降順（多い順）
        $this->assertSame(
            $this->ids($y, $z, $x),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index') . '?sort=income&dir=up')),
            '不正な dir が降順として扱われていない'
        );
    }

    /** 絞り込み（ステータス・物件・キーワード）は並び替え中も効く（設計書 §7.1） */
    public function test_filters_still_apply_while_sorted(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $a1 = $this->makeContract($this->makeUnit($a, 1, 'A'), '2024-01-01', ['rent' => 150000, 'store_name' => 'カフェ本町']);
        $a2 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2023-01-01', ['rent' => 120000, 'store_name' => '本町書店', 'status' => 'terminated', 'contract_end_date' => '2025-12-31']);
        $a3 = $this->makeContract($this->makeUnit($a, 3, 'A'), '2025-01-01', ['rent' => 130000, 'store_name' => '本町薬局']);
        $b1 = $this->makeContract($this->makeUnit($b, 1, 'A'), '2026-01-01', ['rent' => 110000, 'store_name' => 'カフェ湊町']);
        $b2 = $this->makeContract($this->makeUnit($b, 2, 'A'), '2022-01-01', ['rent' => 140000, 'store_name' => '湊町書店', 'status' => 'terminated', 'contract_end_date' => '2025-12-31']);

        $user = $this->executive();

        // ステータス: 解約済みだけを多い順（既定順なら [$a2, $b2]）
        $this->assertSame(
            $this->ids($b2, $a2),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index', ['status' => 'terminated', 'sort' => 'income', 'dir' => 'desc']))),
            'ステータスの絞り込みと賃料収入の並び替えが両立していない'
        );

        // 物件: A館の契約中だけを物件 / 区画の昇順（既定順なら [$a3, $a1]）
        $this->assertSame(
            $this->ids($a1, $a3),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index', ['property_id' => $a->id, 'sort' => 'property_unit', 'dir' => 'asc']))),
            '物件の絞り込みと物件 / 区画の並び替えが両立していない'
        );

        // キーワード: 「カフェ」の契約中だけを契約日の古い順（既定順なら [$b1, $a1]）
        $this->assertSame(
            $this->ids($a1, $b1),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index', ['keyword' => 'カフェ', 'sort' => 'contract_date', 'dir' => 'asc']))),
            'キーワードの絞り込みと契約日の並び替えが両立していない'
        );
    }

    /**
     * ページをまたいでも行が重複せず・消えず・全体を通して並んでいること（設計書 §7.1）。
     *
     * ⚠ **1 ページ目だけでは測れない。** 1 ページ目の 10 件が並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（前例 §3.1）。
     * ⚠ 23 件 ＝ 3 ページ。日付 12 通り・賃料 4 通りで**同点だらけ**にしてある。
     * ⚠ withQueryString() を外すと 2 ページ目以降で sort が落ち、並びが途中で既定に戻る。
     */
    public function test_paging_through_a_sorted_list_yields_every_contract_exactly_once(): void
    {
        $properties = [$this->makeProperty('A館'), $this->makeProperty('B館'), $this->makeProperty('C館')];
        $incomeById = [];
        $dateById = [];

        for ($i = 1; $i <= 23; $i++) {
            // 物件ごとに階が重ならない（UNIQUE(property_id, display_name)）
            $unit = $this->makeUnit($properties[$i % 3], intdiv($i, 3) + 1, 'A');
            $date = sprintf('2025-%02d-01', ($i * 5) % 12 + 1);
            $rent = 100000 + ($i % 4) * 10000;

            $contract = $this->makeContract($unit, $date, ['rent' => $rent]);
            $incomeById[$contract->id] = $rent;
            $dateById[$contract->id] = $date;
        }

        $user = $this->executive();

        $cases = [
            '既定'         => [route('tenant.contracts.index'), fn (int $id) => $dateById[$id], 'desc'],
            '賃料収入 多い順' => [route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'desc']), fn (int $id) => $incomeById[$id], 'desc'],
            '賃料収入 少ない順' => [route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'asc']), fn (int $id) => $incomeById[$id], 'asc'],
        ];

        foreach ($cases as $label => [$url, $valueOf, $direction]) {
            $ids = $this->collectIdsAcrossPages($user, $url);

            $this->assertCount(23, $ids, "{$label}: ページ送りで行が消えている");
            $this->assertCount(23, array_unique($ids), "{$label}: ページ送りで行が重複している");
            $this->assertEqualsCanonicalizing(Contract::pluck('id')->all(), $ids, "{$label}: 全件が出ていない");

            $values = array_map($valueOf, $ids);
            $expected = $values;
            $direction === 'desc' ? rsort($expected) : sort($expected);
            $this->assertSame($expected, $values, "{$label}: ページをまたいで並んでいない（1 ページ目の中だけで並んでいる）");
        }
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ContractListSortTest
```

Expected: 新しい 9 本が**すべて** FAIL（`既定順が契約日の新しい順になっていない` など。現状の既定は物件名順で、
`?sort` は無視される）。Task 2 の 1 本は OK のまま。

⚠ **1 本でも緑なら、そのテストは実装前から通っている＝何も測っていない。** データを見直すこと
（実際に計画の段階で `test_contract_date_can_be_sorted_oldest_first` がこれを踏みかけた。docblock 参照）。

- [ ] **Step 3: `ContractController` を実装する**

`app/Http/Controllers/Tenant/ContractController.php` を次の 5 か所だけ変える。

① `use App\Models\Unit;` の次の行に `use App\Support\ListSort;` を、
`use Carbon\Carbon;` の次の行に `use Illuminate\Database\Eloquent\Builder;` を足す。

② `private const AUTO_CONVERT_REASON = '契約登録に伴い成約';` の**次**（空行を 1 つ挟む）に追加する:

```php

    /**
     * 契約一覧で並び替えを許す列（設計書 2026-09-11 §6.2）。
     *
     * ⚠ **許可リスト・ラベル・向きの言い方・周期の指定はここ 1 箇所だけ。** ListSort::fromRequest() には
     *   array_keys() を渡し、ビュー（見出しとバー）にはこの配列そのものを渡す（前例 §7.1。Bug #41 / #46）。
     * ⚠ `desc` / `asc` はバーに出る「向きの言い方」。
     * ⚠ `'default' => true` は**画面の既定順の列**。初期表示で見出しが点灯し、押すと逆向き → 既定の
     *   2 状態で回る。既定順を変えるときは applySort() の末尾の既定順と、ビューの x-sort-bar の
     *   default-label も揃えること（片方だけ直すと画面が嘘をつく）。
     * ⚠ `first` は 1 回目の向き（省略時 desc）。`first` / `default` を読むのは x-sortable-th だけ。
     * ⚠ 並べ替えの式は applySort() の match にある（3 列とも形が違う: 1 列 / 3 列 / 式）。
     */
    public const SORT_COLUMNS = [
        // 既定順の列。初期表示が新しい順なので、押すと古い順 → もう一度で既定（2 状態）
        'contract_date' => ['label' => '契約日',      'desc' => '新しい順', 'asc' => '古い順',   'default' => true],
        // 1 回目は昇順（物件名 → 階数 → 号室 ＝ 2026-05-11〜09-11 の既定順）
        'property_unit' => ['label' => '物件 / 区画', 'desc' => '降順',     'asc' => '昇順',     'first' => ListSort::ASC],
        // 金額なので 1 回目は多い順（物件一覧の「賃料収入」と同じ言い方）
        'income'        => ['label' => '賃料収入',    'desc' => '多い順',   'asc' => '少ない順'],
    ];
```

③ `index()` の最初の行（`// JOIN するためカラム名にテーブルプレフィックスを付与する` の前）に追加する:

```php
        $sort = ListSort::fromRequest($request, array_keys(self::SORT_COLUMNS));

```

④ `index()` の中の次の塊:

```php
        // 物件名 → 階数 → 号室 の順で並べる（テナント契約一覧）
        $contracts = $query
            ->join('properties', 'contracts.property_id', '=', 'properties.id')
            ->join('units', 'contracts.unit_id', '=', 'units.id')
            ->orderBy('properties.name')
            ->orderBy('units.floor')
            ->orderBy('units.room_number')
            ->select('contracts.*')
            ->paginate(10)
            ->withQueryString();
```

を次に置き換える:

```php
        // properties / units は並び替え（物件 / 区画・既定順）のために JOIN する
        $query->join('properties', 'contracts.property_id', '=', 'properties.id')
            ->join('units', 'contracts.unit_id', '=', 'units.id')
            ->select('contracts.*');

        $this->applySort($query, $sort);

        $contracts = $query->paginate(10)->withQueryString();
```

同じく `index()` の最後の

```php
        return view('tenant.contracts.index', compact('contracts', 'properties'));
    }
```

を次に置き換え、そのすぐ後ろに `applySort()` を足す:

```php
        $sortColumns = self::SORT_COLUMNS;

        return view('tenant.contracts.index', compact('contracts', 'properties', 'sort', 'sortColumns'));
    }

    /**
     * 契約一覧の並び替えを適用する。**既定順は必ず最後に丸ごと付ける**（設計書 2026-09-11 §4.4）。
     *
     * 既定順: 契約日の新しい順 → 物件名 → 階数 → 号室 → 契約 ID の新しい順
     *
     * ⚠ 最後の contracts.id DESC が並びを一意にする。同じ区画の旧契約と新契約は
     *   物件名・階数・号室が全部同点になり、MySQL は同点の順序を保証しないので、
     *   「ステータス: すべて」でページをまたぐと同じ契約が 2 ページに出たり消えたりしうる（設計書 §2.3）。
     * ⚠ 並び替え中も既定順を丸ごと後ろに付ける（同点は既定順。前例 §4.3-3）。
     * ⚠ 物件 / 区画の降順は 3 キーとも同じ向き（＝昇順の完全な逆順）。
     * ⚠ units.floor の NULL（平屋型）は MySQL・SQLite とも昇順で先頭・降順で末尾。画面に「—」は
     *   出ないので末尾送りはしない（旧既定と同じ挙動。設計書 §4.4）。
     * ⚠ match に default アームを書かないのは意図。$sort->key は fromRequest() の許可リストを通った
     *   値しか来ない。SORT_COLUMNS にキーを足して並べ替えを書き忘れると UnhandledMatchError で 500 に
     *   なり、SortableListWiringTest が拾う（黙って既定順に落ちるより良い）。
     * ⚠ 式はコード内の定数だけ。利用者の入力が SQL に混ざる経路は無い。
     */
    private function applySort(Builder $query, ?ListSort $sort): void
    {
        if ($sort !== null) {
            $direction = $sort->isAscending() ? 'asc' : 'desc';

            match ($sort->key) {
                'contract_date' => $query->orderBy('contracts.contract_date', $direction),
                'property_unit' => $query->orderBy('properties.name', $direction)
                    ->orderBy('units.floor', $direction)
                    ->orderBy('units.room_number', $direction),
                'income' => $query->orderByRaw(Contract::MONTHLY_TOTAL_SQL . ' ' . $direction),
            };
        }

        $query->orderByDesc('contracts.contract_date')
            ->orderBy('properties.name')
            ->orderBy('units.floor')
            ->orderBy('units.room_number')
            ->orderByDesc('contracts.id');
    }
```

⚠ `withQueryString()` は**変えない**（前提 #9）。

- [ ] **Step 4: 契約一覧のテストが通ることを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ContractListSortTest
```

Expected: `OK (10 tests, …)`

- [ ] **Step 5: 配線テストが落ちることを確認する（登録漏れの検出）**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter SortableListWiringTest
```

Expected: FAIL — `SORT_COLUMNS を定義するクラスの数が変わった（走査漏れ、または画面の増減）`（3 → 4）。
**これは正しい失敗**（`public const SORT_COLUMNS =` を持つクラスを app/ 全体から機械的に拾っている。Bug #45）。

- [ ] **Step 6: 配線テストに 4 画面目の並び替えを登録する**

`tests/Feature/SortableListWiringTest.php` を次の 5 か所だけ変える。

① `use` を 3 行足す（アルファベット順の位置へ）:

```php
use App\Http\Controllers\Tenant\ContractController;
```

を `use App\Http\Controllers\Tenant\PropertyController;` の前に、

```php
use App\Models\Contract;
use App\Models\Customer;
```

を `use App\Models\Property;` の前に。

② `SORT_ENDPOINTS` に 1 行足す:

```php
    private const SORT_ENDPOINTS = [
        AreaBuildingListService::class => ['/tenant/area-buildings', 7],
        UnitController::class          => ['/tenant/units', 3],
        PropertyController::class      => ['/tenant/properties', 2],
        ContractController::class      => ['/tenant/contracts', 3],
    ];
```

⚠ **`SORT_COLUMN_SOURCES`（ビュー → 列数）にはまだ登録しない。** ビューが `<x-sortable-th` を持つのは
Task 5 から。今登録すると「登録済みのビューが走査で見つからない」で落ちる。

③ `test_every_sort_column_can_be_requested_without_erroring()` の `assertCount` の数を 3 → 4 にする:

```php
        $this->assertCount(
            4,
            $definingClasses,
            'SORT_COLUMNS を定義するクラスの数が変わった（走査漏れ、または画面の増減）'
        );
```

④ `seedOneRowPerScreen()` の末尾（最後の `Unit::create([...]);` の後ろ）に追加する:

```php

        // テナント契約一覧（設計書 2026-09-11 §7.3）。⚠ テストの SQLite では customer_id が NOT NULL
        $occupied = Unit::create([
            'property_id'  => $property->id,
            'floor'        => 1,
            'room_number'  => '102',
            'display_name' => '102',
            'status'       => 'occupied',
        ]);

        Contract::create([
            'contract_number' => 'C-WIRE-001',
            'department'      => 'tenant',
            'property_id'     => $property->id,
            'unit_id'         => $occupied->id,
            'customer_id'     => Customer::create(['code' => 'CUST-WIRE01', 'name' => '配線検査用テナント', 'customer_type' => 'corporation'])->id,
            'status'          => 'active',
            'contract_date'   => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'rent'            => 100000,
        ]);
```

⑤ docblock の「3 画面」を「4 画面」にする（数え方の説明になっている次の 4 か所だけ）:

| 変える前 | 変えた後 |
|---|---|
| `（CheckDepartmentAccess::handle()）ので、3 画面とも部門の紐付けは要らない` | `（CheckDepartmentAccess::handle()）ので、4 画面とも部門の紐付けは要らない` |
| `に起きるため行数に依存しないが、**3 画面とも同じ流儀で最低 1 行作る**` | `に起きるため行数に依存しないが、**4 画面とも同じ流儀で最低 1 行作る**` |
| `     * 3 画面それぞれに最低 1 行を作る。` | `     * 4 画面それぞれに最低 1 行を作る。` |
| `部屋一覧・物件一覧は行数に依存しない欠落検出だが、3 画面とも同じ流儀で揃える。` | `部屋一覧・物件一覧は行数に依存しない欠落検出だが、4 画面とも同じ流儀で揃える。` |

⚠ 「知っている 3 画面」を手で列挙すると…（`classesDefiningSortColumns()` の説明）は**一般論なので変えない**。

- [ ] **Step 7: 配線テストと全テストを走らせる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter SortableListWiringTest
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: 配線テスト OK ／ 全体 `OK (1329 tests, …)`

⚠ この時点で**画面の見た目は何も変わっていない**（契約日の列も見出しのリンクもまだ無い）。変わったのは並び順だけ。

- [ ] **Step 8: コミット**

```bash
git add app/Http/Controllers/Tenant/ContractController.php tests/Feature/Tenant/ContractListSortTest.php tests/Feature/SortableListWiringTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 契約一覧の既定順を契約日の新しい順にし並び替えを受け付ける

既定順は 契約日の新しい順 → 物件名 → 階数 → 号室 → 契約 ID の新しい順。
最後の契約 ID で並びが一意になり、同じ区画の旧契約と新契約がページをまたいで
重複・欠落しなくなる。?sort=contract_date / property_unit / income を受け付け、
並び替え中の同点は既定順を丸ごと後ろに付けて並べる。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 表の先頭に契約日の列を足す

**Files:**
- Modify: `resources/views/tenant/contracts/index.blade.php`
- Test: `tests/Feature/Tenant/ContractListSortTest.php`

⚠ この Task では見出しは**素の `<th>`** のまま（並び替えのリンクは Task 5）。依頼の前半
「テーブルにも契約日を追加して下さい」だけを先に片づける。

### 列幅の初期値（見積もり）

⚠ **確定値は Task 8 の実測で決める**（設計書 §4.7）。ここでの値は、Task 8 の測定の出発点。
見積もりは 14px の太字（`text-sm font-semibold`）で全角 14px・数字 8.5px・横の余白は `lg:px-5` の 40px:

| 列 | 最も幅を取る中身（Task 8 の検証用データ） | 必要な幅の見積もり | 初期値（900px のとき） |
|---|---|---|---|
| 契約日 | `2026/09/11` ＋ 見出し「契約日 ▼」 | 約 119px | 14%（126px） |
| 物件 / 区画 | `千舟町スクエア / B1A`（7 字 ＋ 区画） | 約 180px | 20%（180px） |
| 店舗名 | 全角 10 字 | 約 180px | 20%（180px） |
| 賃料収入 | `1,234,567円 ⚠` | 約 139px | 16%（144px） |
| 状態 | バッジ「解約済み」 | 約 108px | 12%（108px） |
| 操作 | 「詳細」＋「編集」 | 約 154px | 18%（162px） |

⚠ 今の 5 列（最小 640px）でも**状態と操作は 375px で既にはみ出している見込み**（状態 64px にバッジ 68px、
操作 128px にボタン 2 つで約 114px ＋ 余白）。設計書 §4.7 の判定は**全セル**なので、既存の列も直す。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/ContractListSortTest.php` の**クラスの最後の `}` の直前**に追加する:

```php
    /**
     * 表の見出しの文字列（左から順に。タグと空白の揺れを落とす）。
     *
     * ⚠ このページの表は 1 つだけ（最初の <thead>）。並び替え見出しはリンクと矢印の SVG を含むが、
     *   strip_tags で文字だけになる。
     */
    private function headerTexts(string $html): array
    {
        $this->assertMatchesRegularExpression('/<thead\b[^>]*>(.*?)<\/thead>/su', $html, '表の見出し行が見つからない');
        preg_match('/<thead\b[^>]*>(.*?)<\/thead>/su', $html, $thead);
        preg_match_all('/<th\b[^>]*>(.*?)<\/th>/su', $thead[1], $cells);

        return array_map(fn (string $cell) => $this->plainText($cell), $cells[1]);
    }

    /**
     * 表の本体の各行のセルの文字列（上から順に。各行は左から順に）。
     *
     * @return list<list<string>>
     */
    private function bodyRows(string $html): array
    {
        $this->assertMatchesRegularExpression('/<tbody\b[^>]*>(.*?)<\/tbody>/su', $html, '表の本体が見つからない');
        preg_match('/<tbody\b[^>]*>(.*?)<\/tbody>/su', $html, $tbody);
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/su', $tbody[1], $rows);

        return array_map(function (string $row) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/su', $row, $cells);

            return array_map(fn (string $cell) => $this->plainText($cell), $cells[1]);
        }, $rows[1]);
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    /**
     * 契約日が**各行の先頭セル**に Y/m/d で出る（設計書 §4.1 / §7.1）。
     *
     * ⚠ ページ全体の文字列一致で見てはいけない。同じ文字が別の場所に出ても通ってしまう。
     *   行ごとに先頭の <td> を見る。
     * ⚠ 期待値は**リテラルで書く**（viewData から組み立てると、表示と並びが一緒に壊れても一致しうる）。
     * ⚠ 見出しの並びも固定する。セルだけ動かす／見出しだけ動かす、のどちらも落とす。
     */
    public function test_each_row_starts_with_its_contract_date(): void
    {
        $this->threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder();

        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();

        $this->assertSame(
            ['契約日', '物件 / 区画', '店舗名', '賃料収入', '状態', '操作'],
            $this->headerTexts($html),
            '見出しの並びが設計書 §4.1 と違う（契約日が先頭でない）'
        );

        $rows = $this->bodyRows($html);

        $this->assertSame(
            ['2026/04/01', '2025/04/01', '2024/04/01'],
            array_map(fn (array $cells) => $cells[0] ?? null, $rows),
            '各行の先頭セルが契約日（Y/m/d）になっていない'
        );

        foreach ($rows as $i => $cells) {
            $this->assertCount(6, $cells, ($i + 1) . ' 行目のセルの数が見出しの数と違う');
        }

        // 2 列目は物件 / 区画のまま（契約日の列を足しただけで、既存の列の中身は変えない）
        $this->assertSame('C館 / 1A', $rows[0][1], '2 列目が物件 / 区画でない');
    }

    /** 契約が 0 件のときの行が全列にまたがる（列を足したら colspan も揃える） */
    public function test_the_empty_row_spans_every_column(): void
    {
        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();

        $pattern = '/<td colspan="(\d+)"[^>]*>\s*契約データがありません。/u';
        $this->assertMatchesRegularExpression($pattern, $html, '0 件の行が見つからない');
        preg_match($pattern, $html, $matches);

        $this->assertSame(count($this->headerTexts($html)), (int) $matches[1], '0 件の行の colspan が見出しの数と違う');
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ContractListSortTest
```

Expected: `test_each_row_starts_with_its_contract_date` が FAIL（`見出しの並びが設計書 §4.1 と違う（契約日が先頭でない）`）。
`test_the_empty_row_spans_every_column` は**緑**。

⚠ **`test_the_empty_row_spans_every_column` は実装前だと「見出し 5・colspan 5」で一致して緑になる。**
これは正しい（列を足す前は揃っている）。この本は「列を足したのに colspan を直し忘れる」ことを止める柵で、
Step 3 のあと・Task 7 の変異（colspan を 5 に戻す）で赤くなることを測る。

- [ ] **Step 3: ビューを変える**

`resources/views/tenant/contracts/index.blade.php` を次の 3 か所だけ変える。

① 表の開始タグと `<colgroup>`、見出しの先頭:

```blade
                <table class="w-full border-collapse min-w-[640px]" style="table-layout:fixed">
                    <colgroup>
                        <col style="width:25%">
                        <col style="width:25%">
                        <col style="width:20%">
                        <col style="width:10%">
                        <col style="width:20%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件 / 区画</th>
```

を次に置き換える:

```blade
                {{-- ⚠ 列幅と最小幅は実ブラウザで測って決める値（設計書 §4.7: 375 / 1200 / 1800px のどれでも
                     どのセルも中身が枠からはみ出さない）。列を足し引きしたら測り直すこと。
                     ⚠ min-w-[…] は消さない（MobileLayoutTest が table-layout: fixed の表に最小幅を要求する） --}}
                <table class="w-full border-collapse min-w-[900px]" style="table-layout:fixed">
                    <colgroup>
                        <col style="width:14%">{{-- 契約日 --}}
                        <col style="width:20%">{{-- 物件 / 区画 --}}
                        <col style="width:20%">{{-- 店舗名 --}}
                        <col style="width:16%">{{-- 賃料収入 --}}
                        <col style="width:12%">{{-- 状態 --}}
                        <col style="width:18%">{{-- 操作 --}}
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">契約日</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件 / 区画</th>
```

② 行の先頭（`{{-- 物件 / 区画 --}}` の直前）に契約日のセルを足す:

```blade
                            <tr class="{{ $contract->isTerminated() ? 'contract-row-terminated' : '' }} hover:bg-gray-50 transition-colors">
                                {{-- 物件 / 区画 --}}
```

を次に置き換える:

```blade
                            <tr class="{{ $contract->isTerminated() ? 'contract-row-terminated' : '' }} hover:bg-gray-50 transition-colors">
                                {{-- 契約日（テナント画面の日付は Y/m/d。契約詳細・区画詳細と同じ） --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $contract->contract_date->format('Y/m/d') }}
                                </td>
                                {{-- 物件 / 区画 --}}
```

⚠ `contract_date` は全経路で必須・migration も NOT NULL（設計書 §2.2）なので `?->` は付けない。

③ 0 件の行の `colspan="5"` を `colspan="6"` にする:

```blade
                                <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ContractListSortTest
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter MobileLayoutTest
```

Expected: `OK (12 tests, …)` ／ MobileLayoutTest も OK（`min-w-[900px]` が最小幅として数えられる）

- [ ] **Step 5: 全テストを走らせる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1331 tests, …)`

- [ ] **Step 6: コミット**

```bash
git add resources/views/tenant/contracts/index.blade.php tests/Feature/Tenant/ContractListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 契約一覧の先頭に契約日の列を足す

契約日を Y/m/d で左端に出す（並び順の基準が一番左に来る）。
6 列になるので colgroup と最小幅を見直す（確定値はブラウザで測って決める）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 見出し 3 本で並び替える（`x-sortable-th` の周期指定・hidden・バー）

**Files:**
- Modify: `resources/views/components/sortable-th.blade.php`
- Modify: `resources/views/tenant/contracts/index.blade.php`
- Modify: `tests/Feature/SortableListWiringTest.php`（`SORT_COLUMN_SOURCES` へ登録）
- Modify: `tests/Feature/Tenant/SortBarTest.php`（4 画面目 ＋ 既定順の文言と並びの対 ＋ 向きの言い方）
- Test: `tests/Feature/Tenant/ContractListSortTest.php`

⚠ この Task のテストは**画面が描画した href を辿る**（URL を自分で組み立てると、リンクが壊れていても緑になる。
Bug #47 / `ParsesSortLinks::sortLinkFor()`）。

- [ ] **Step 1: 失敗するテストを書く（契約一覧）**

`tests/Feature/Tenant/ContractListSortTest.php` の**クラスの最後の `}` の直前**に追加する:

```php
    /**
     * 初期表示では契約日の見出しだけが点灯する（▼・緑・aria-sort="descending"。設計書 §4.3 / §7.1）。
     *
     * ⚠ 列ごとに切り出して見る（ParsesSortLinks::ariaSortFor()）。ページ全体の
     *   assertStringContainsString('aria-sort="descending"') は、全列に descending を出す変異でも通る。
     * ⚠ 並び替え指定が無いのに点灯させるのは既定順の列だけ。ListSort::stateOf() の既定点灯を
     *   外すと、契約日で並んでいるのに ⇅ のまま・aria-sort="none" になる（設計書 §3.1）。
     */
    public function test_only_the_contract_date_header_is_lit_on_the_default_screen(): void
    {
        $this->threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder();

        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();

        $this->assertSame('descending', $this->ariaSortFor($html, '契約日'), '初期表示で契約日の見出しが点灯していない');
        $this->assertSame('none', $this->ariaSortFor($html, '物件 / 区画'), '並び替えていない列に aria-sort が載っている');
        $this->assertSame('none', $this->ariaSortFor($html, '賃料収入'), '並び替えていない列に aria-sort が載っている');
        $this->assertSame('none', $this->ariaSortFor($html, '店舗名'), '並び替えない列に aria-sort が載っている');

        $dateHeader = $this->thInnerFor($html, '契約日');
        $this->assertStringContainsString('points="6 9 12 16 18 9"', $dateHeader, '契約日の見出しに ▼ が出ていない');
        $this->assertStringContainsString('color: #059669;', $dateHeader, '契約日の見出しの矢印が緑でない');

        foreach (['物件 / 区画', '賃料収入'] as $label) {
            $this->assertStringContainsString('points="7 15 12 20 17 15"', $this->thInnerFor($html, $label), "{$label} の見出しが ⇅ でない");
        }
    }

    /**
     * 契約日の見出し: 既定（▼ 新しい順）→ 古い順 ▲ → 既定（2 状態。設計書 §4.3）。
     *
     * ⚠ 1 回目の href が dir=asc であることを先に見る。'default' => true を落とすと契約日が
     *   ふつうの列に戻り、1 回目が dir=desc（＝既定と同じ並び）になって**押しても何も変わらない**。
     */
    public function test_the_contract_date_header_goes_oldest_first_then_back_to_the_default(): void
    {
        [$a1, $a2, $a3, $b1] = $this->fourContractsForThePropertyUnitColumn();
        $user = $this->executive();

        $html = $this->actingAs($user)->get(route('tenant.contracts.index'))->getContent();
        $firstUrl = $this->sortLinkFor($html, '契約日');
        $this->assertStringContainsString('sort=contract_date', $firstUrl);
        $this->assertStringContainsString('dir=asc', $firstUrl, '既定から押したら古い順へ進むべき');

        $first = $this->actingAs($user)->get($firstUrl);
        $this->assertSame($this->ids($a2, $b1, $a3, $a1), $this->listedIds($first), '古い順になっていない');
        $this->assertSame('ascending', $this->ariaSortFor($first->getContent(), '契約日'));

        $secondUrl = $this->sortLinkFor($first->getContent(), '契約日');
        $this->assertStringNotContainsString('sort=', $secondUrl, '古い順の次は既定へ戻す（2 状態）');
        $this->assertStringNotContainsString('dir=', $secondUrl);

        $second = $this->actingAs($user)->get($secondUrl);
        $this->assertSame($this->ids($a1, $a3, $b1, $a2), $this->listedIds($second), '既定順に戻っていない');
        $this->assertSame('descending', $this->ariaSortFor($second->getContent(), '契約日'), '既定に戻ったのに契約日の見出しが点灯していない');
    }

    /**
     * 他の列で並び替え中に「契約日」を押すと既定へ戻る（URL に sort を載せない。設計書 §4.3）。
     *
     * ⚠ 既定と同じ並びを「契約日 新しい順」という別の状態として作らない。ListSort::next() の
     *   既定順の列の分岐を外すと、ここで dir=desc 付きのリンクになる。
     */
    public function test_clicking_the_contract_date_header_while_another_column_is_sorted_returns_to_the_default(): void
    {
        [$x, $w, $z, $y] = $this->fourContractsForTheIncomeColumn();
        $user = $this->executive();

        $html = $this->actingAs($user)->get(route('tenant.contracts.index'))->getContent();
        $byIncome = $this->actingAs($user)->get($this->sortLinkFor($html, '賃料収入'));
        $this->assertSame($this->ids($x, $w, $z, $y), $this->listedIds($byIncome));
        $this->assertSame('none', $this->ariaSortFor($byIncome->getContent(), '契約日'), '別の列で並び替え中なのに契約日の見出しが点灯している');

        $url = $this->sortLinkFor($byIncome->getContent(), '契約日');
        $this->assertStringNotContainsString('sort=', $url, '契約日を押したら既定へ戻すべき（並び替えを載せない）');
        $this->assertStringNotContainsString('dir=', $url);

        $back = $this->actingAs($user)->get($url);
        $this->assertSame($this->ids($y, $z, $x, $w), $this->listedIds($back), '既定順（契約日の新しい順）に戻っていない');
        $this->assertSame('descending', $this->ariaSortFor($back->getContent(), '契約日'));
    }

    /** 手入力の ?sort=contract_date&dir=desc は既定と同じ並びになり、押すと古い順へ進む（正規化はしない。設計書 §4.3） */
    public function test_a_hand_typed_newest_first_shows_the_default_order_and_moves_on_to_oldest(): void
    {
        [$a, $b, $c] = $this->threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder();
        $user = $this->executive();

        $response = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'contract_date', 'dir' => 'desc']));
        $this->assertSame($this->ids($c, $b, $a), $this->listedIds($response));
        $this->assertSame('descending', $this->ariaSortFor($response->getContent(), '契約日'));

        $this->assertStringContainsString('dir=asc', $this->sortLinkFor($response->getContent(), '契約日'), '押したら古い順へ進むべき');
    }

    /**
     * 物件 / 区画の見出し: 既定 → **昇順** ▲ → 降順 ▼ → 既定（設計書 §4.3）。
     *
     * ⚠ 1 回目が昇順（2026-05-11〜09-11 の既定順を 1 回で呼び出す）。'first' => ListSort::ASC を
     *   落とすと 1 回目が物件名の逆順になる。
     */
    public function test_the_property_unit_header_starts_ascending_then_descending_then_default(): void
    {
        [$a1, $a2, $a3, $b1] = $this->fourContractsForThePropertyUnitColumn();
        $user = $this->executive();

        $html = $this->actingAs($user)->get(route('tenant.contracts.index'))->getContent();
        $first = $this->actingAs($user)->get($this->sortLinkFor($html, '物件 / 区画'));
        $this->assertSame($this->ids($a1, $a2, $a3, $b1), $this->listedIds($first), '1 回目が昇順（物件名 → 階数 → 号室）になっていない');
        $this->assertSame('ascending', $this->ariaSortFor($first->getContent(), '物件 / 区画'));

        $second = $this->actingAs($user)->get($this->sortLinkFor($first->getContent(), '物件 / 区画'));
        $this->assertSame($this->ids($b1, $a3, $a2, $a1), $this->listedIds($second), '2 回目が降順になっていない');
        $this->assertSame('descending', $this->ariaSortFor($second->getContent(), '物件 / 区画'));

        $thirdUrl = $this->sortLinkFor($second->getContent(), '物件 / 区画');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 回目は並び替えを解除する');
        $this->assertSame($this->ids($a1, $a3, $b1, $a2), $this->listedIds($this->actingAs($user)->get($thirdUrl)));
    }

    /** 賃料収入の見出し: 既定 → 多い順 ▼ → 少ない順 ▲ → 既定（前例の金額列と同じ） */
    public function test_the_income_header_cycles_most_then_least_then_default(): void
    {
        [$x, $w, $z, $y] = $this->fourContractsForTheIncomeColumn();
        $user = $this->executive();

        $html = $this->actingAs($user)->get(route('tenant.contracts.index'))->getContent();
        $first = $this->actingAs($user)->get($this->sortLinkFor($html, '賃料収入'));
        $this->assertSame($this->ids($x, $w, $z, $y), $this->listedIds($first), '1 回目が多い順になっていない');
        $this->assertSame('descending', $this->ariaSortFor($first->getContent(), '賃料収入'));

        $second = $this->actingAs($user)->get($this->sortLinkFor($first->getContent(), '賃料収入'));
        $this->assertSame($this->ids($y, $z, $w, $x), $this->listedIds($second), '2 回目が少ない順になっていない');
        $this->assertSame('ascending', $this->ariaSortFor($second->getContent(), '賃料収入'));

        $thirdUrl = $this->sortLinkFor($second->getContent(), '賃料収入');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 回目は並び替えを解除する');
        $this->assertSame($this->ids($y, $z, $x, $w), $this->listedIds($this->actingAs($user)->get($thirdUrl)));
    }

    /** 並び替えない見出し（店舗名・状態・操作）は素の <th>（リンクも矢印も無い） */
    public function test_non_sortable_headers_stay_plain(): void
    {
        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();

        foreach (['店舗名', '状態', '操作'] as $label) {
            $this->assertStringContainsString(">{$label}</th>", $html, "{$label} の見出しが素の <th> でなくなっている");
        }
    }

    /**
     * 並び替え中にフィルタを変えても並び順が消えない（設計書 §4.5-3）。
     *
     * ⚠ hidden があることを見るだけでは足りない。**画面が描画したフォームを解析して
     *   そのまま送り返す**（Bug #47）。フォームは GET なので fields をクエリ文字列に組み直す。
     * ⚠ **2 組（賃料収入の少ない順 ／ 物件 / 区画の降順）を通す。** キーも向きも変えることで、
     *   hidden の値をハードコードする変異がどちらでも落ちる。
     * ⚠ 絞り込み後に 3 件残し、既定順・賃料収入の少ない順・物件 / 区画の降順がすべて食い違う:
     *   既定 [$a1, $a3, $a2] ／ 賃料収入の少ない順 [$a2, $a3, $a1] ／ 物件 / 区画の降順 [$a3, $a2, $a1]
     */
    public function test_changing_a_filter_keeps_the_current_sort(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $a1 = $this->makeContract($this->makeUnit($a, 1, 'A'), '2026-01-01', ['rent' => 150000]);
        $a2 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2024-01-01', ['rent' => 120000]);
        $a3 = $this->makeContract($this->makeUnit($a, 3, 'A'), '2025-01-01', ['rent' => 130000]);
        $excluded = $this->makeContract($this->makeUnit($b, 1, 'A'), '2027-01-01', ['rent' => 100000]);

        $user = $this->executive();

        $this->assertFilterRoundTripKeepsOrder($user, $a, 'income', 'asc', $this->ids($a2, $a3, $a1), $excluded);
        $this->assertFilterRoundTripKeepsOrder($user, $a, 'property_unit', 'desc', $this->ids($a3, $a2, $a1), $excluded);
    }

    /** 並び替え中の画面のフィルターフォームを解析し、物件だけ変えて送り返して並び順が保たれることを見る */
    private function assertFilterRoundTripKeepsOrder(
        User $user,
        Property $property,
        string $key,
        string $direction,
        array $expected,
        Contract $excluded,
    ): void {
        $html = $this->actingAs($user)
            ->get(route('tenant.contracts.index', ['sort' => $key, 'dir' => $direction]))
            ->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.contracts.index') . '"');

        $this->assertSame($key, $form['fields']['sort'] ?? null, "フィルターフォームが sort={$key} を持ち回していない");
        $this->assertSame($direction, $form['fields']['dir'] ?? null, "フィルターフォームが dir={$direction} を持ち回していない");
        $this->assertArrayNotHasKey('page', $form['fields'], 'フィルタを変えたら 1 ページ目に戻るべき');

        // ブラウザと同じように、物件だけ変えて送り返す
        $fields = $form['fields'];
        $fields['property_id'] = (string) $property->id;

        $ids = $this->listedIds($this->actingAs($user)->get($form['action'] . '?' . http_build_query($fields)));

        $this->assertSame($expected, $ids, "フィルタを変えたら並び順が既定に戻った（{$key} の {$direction} のままであるべき）");
        $this->assertNotContains($excluded->id, $ids, '物件の絞り込みが効いていない');
    }

    /** 並び替えていないときは余計な hidden を出さない（?sort= が URL に現れて汚れる） */
    public function test_no_sort_hidden_fields_when_not_sorting(): void
    {
        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.contracts.index') . '"');

        $this->assertArrayNotHasKey('sort', $form['fields']);
        $this->assertArrayNotHasKey('dir', $form['fields']);
    }

    /** 2 ページ目で見出しを押したら 1 ページ目へ戻る（設計書 §4.5-4）。3 列とも見る */
    public function test_clicking_a_header_from_page_two_returns_to_page_one(): void
    {
        $property = $this->makeProperty('A館');
        for ($i = 1; $i <= 11; $i++) {
            $this->makeContract($this->makeUnit($property, $i, 'A'), sprintf('2025-%02d-01', $i), ['rent' => 100000 + $i]);
        }

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get(route('tenant.contracts.index'));
        $page2 = $this->actingAs($user)->get($page1->viewData('contracts')->nextPageUrl());
        $this->assertSame(2, $page2->viewData('contracts')->currentPage());

        foreach (['契約日', '物件 / 区画', '賃料収入'] as $label) {
            $url = $this->sortLinkFor($page2->getContent(), $label);
            $this->assertStringNotContainsString('page=', $url, "{$label} の見出しリンクが page を持ち越している");
            $this->assertSame(1, $this->actingAs($user)->get($url)->viewData('contracts')->currentPage());
        }
    }

    /**
     * 「クリア」は並び順も初期化する（設計書 §4.5-5「クリアは全部」）。
     *
     * ⚠ サイドバーの「契約一覧」メニューも同じ素の URL を指すので、href="…" の部分一致では
     *   クリアの href を見ずに常に緑になる（前例 UnitListSortTest の実測）。ラベルで取り出す。
     */
    public function test_the_clear_link_drops_the_sort(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'desc', 'status' => 'all']))
            ->getContent();

        $this->assertSame(route('tenant.contracts.index'), $this->sortLinkFor($html, 'クリア'), 'クリアがクエリ付きのリンクになっている（並び順が残る）');
    }

    /** バーの「解除」は並び順だけを消し、絞り込みは残す（設計書 §4.5-5「解除は並び順だけ」） */
    public function test_the_bar_clear_link_keeps_the_filters(): void
    {
        $html = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'desc', 'status' => 'all']))
            ->getContent();

        $url = $this->sortLinkFor($html, '解除');
        $this->assertStringNotContainsString('sort=', $url, '解除リンクが並び順を残している');
        $this->assertStringNotContainsString('dir=', $url);
        $this->assertStringContainsString('status=all', $url, '解除リンクが絞り込みまで消している（「クリア」と区別が無い）');
    }
```

- [ ] **Step 2: 失敗するテストを書く（並び順バー）**

`tests/Feature/Tenant/SortBarTest.php` を次の 4 か所だけ変える。

① `use` を 2 行足す（`use App\Models\Property;` の前）:

```php
use App\Models\Contract;
use App\Models\Customer;
```

② `use RefreshDatabase;` の次に、連番のプロパティを足す:

```php

    private int $contractSeq = 0;
```

③ `test_each_list_names_its_own_default_order()` の表に 4 画面目を足す:

```php
        $screens = [
            '/tenant/area-buildings'              => 'ビル名順',
            route('tenant.properties.index')      => '稼働中が先・コード順',
            route('tenant.units.index')           => '物件・階・部屋番号順',
            route('tenant.contracts.index')       => '契約日の新しい順',
        ];
```

④ `makeUnit()` の後ろ（`test_each_list_names_its_own_default_order()` の docblock の前）に補助メソッドを、
`test_the_units_bar_names_the_real_default_order()` の後ろにテスト 2 本を足す:

```php
    /**
     * テナント契約を 1 件作る（`ContractListSortTest::makeContract()` と同じ最小フィールド構成）。
     *
     * ⚠ テストの SQLite では customer_id / rent_start_date が NOT NULL。
     */
    private function makeTenantContract(string $propertyName, string $contractDate): Contract
    {
        $n = ++$this->contractSeq;

        $property = Property::create([
            'code'             => sprintf('T-BARC%02d', $n),
            'name'             => $propertyName,
            'property_type'    => 'tenant',
            'department'       => 'tenant',
            'operation_status' => 'active',
            'address'          => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id'  => $property->id,
            'floor'        => 1,
            'room_number'  => 'A',
            'display_name' => '1A',
            'status'       => 'occupied',
        ]);

        return Contract::create([
            'contract_number' => sprintf('C-BAR-%03d', $n),
            'department'      => 'tenant',
            'property_id'     => $property->id,
            'unit_id'         => $unit->id,
            'customer_id'     => Customer::create(['code' => sprintf('CUST-BAR%02d', $n), 'name' => 'テスト商事', 'customer_type' => 'corporation'])->id,
            'status'          => 'active',
            'contract_date'   => $contractDate,
            'rent_start_date' => $contractDate,
            'rent'            => 100000,
        ]);
    }
```

```php
    /**
     * テナント契約一覧の既定順の**文言と実際の並びが揃っている**こと（設計書 2026-09-11 §4.6）。
     *
     * ⚠ 物件名の順と契約日の順を逆にしてある（旧既定＝物件名順に戻す変異を赤くする）。
     *   作成順も両方と食い違わせてある（id の降順で並べる変異も赤くする）。
     */
    public function test_the_contracts_bar_names_the_real_default_order(): void
    {
        $b = $this->makeTenantContract('B館', '2025-04-01');
        $c = $this->makeTenantContract('C館', '2026-04-01');
        $a = $this->makeTenantContract('A館', '2024-04-01');

        $response = $this->actingAs($this->staff())->get(route('tenant.contracts.index'));

        $this->assertStringContainsString('並び替え: 既定（契約日の新しい順）', $response->getContent());
        $this->assertSame(
            [$c->id, $b->id, $a->id],
            $response->viewData('contracts')->pluck('id')->all(),
            'バーの文言と実際の並びが食い違っている'
        );
    }

    /**
     * テナント契約一覧の並び替え中は列名と**向きの言い方**が出ること（設計書 2026-09-11 §6.2）。
     *
     * ⚠ `sort=contract_date&dir=desc` は手入力の「既定と同じ並び」。正規化しないので、
     *   バーは素直に「契約日 新しい順」と名乗る（計画で決めた。設計書 §4.3）。
     *   この経路でしか 'desc' => '新しい順' は画面に出ない。
     * ⚠ ピルは aria-label を落としてから見る（test_the_bar_names_the_column_and_the_direction と同じ理由）。
     */
    public function test_the_contracts_bar_names_each_column_and_direction(): void
    {
        $staff = $this->staff();

        $cases = [
            'sort=contract_date&dir=asc'  => '契約日 古い順',
            'sort=contract_date&dir=desc' => '契約日 新しい順',
            'sort=property_unit&dir=asc'  => '物件 / 区画 昇順',
            'sort=property_unit&dir=desc' => '物件 / 区画 降順',
            'sort=income&dir=desc'        => '賃料収入 多い順',
            'sort=income&dir=asc'         => '賃料収入 少ない順',
        ];

        foreach ($cases as $query => $phrase) {
            $html = $this->actingAs($staff)->get(route('tenant.contracts.index') . '?' . $query)->getContent();

            $this->assertStringContainsString(
                "並び替え: {$phrase}",
                $this->withoutAriaLabels($html),
                "?{$query} のピルに列名と向きが出ていない（画面に見える文字が消えている）"
            );
            $this->assertStringContainsString(
                'aria-label="並び替え: ' . $phrase . ' を解除"',
                $html,
                "?{$query} の解除リンクが何を解除するのか名乗っていない"
            );
        }
    }
```

- [ ] **Step 3: テストが失敗することを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ContractListSortTest|SortBarTest'
```

Expected:
- 契約一覧の新しい 12 本のうち **9 本が FAIL**（`初期表示で契約日の見出しが点灯していない` ／ `「契約日」の並び替えリンクが見つからない` など）
- **3 本は最初から緑**（`test_non_sortable_headers_stay_plain` ／ `test_no_sort_hidden_fields_when_not_sorting` ／ `test_the_clear_link_drops_the_sort`）。
  これらは**壊さないための柵**で、実装後も緑のままであることに意味がある（Task 7 の変異で赤くなることを測る）
- SortBarTest の 3 本（4 画面目の表・文言と並びの対・向きの言い方）が FAIL（バーがまだ無い）

- [ ] **Step 4: `x-sortable-th` に列ごとの周期を渡す**

`resources/views/components/sortable-th.blade.php` を次の 3 か所だけ変える。

① docblock の props の `columns` の行:

```
  columns    … その画面の SORT_COLUMNS（日本語ラベルと「向きの言い方」）
```

を次に置き換える:

```
  columns    … その画面の SORT_COLUMNS（日本語ラベルと「向きの言い方」）。
               列ごとに任意で 'first'（1 回目の向き。省略時 desc）と
               'default'（画面の既定順の列なら true）を持てる（設計書 2026-09-11 §6.3）
```

② docblock の `⚠ JS は 1 行も使わない。ただのリンク。` の**前**に 1 項目足す:

```
⚠ 'default' => true の列は、並び替え指定が無くても点灯する（初期表示で ▼ ・aria-sort="descending"）。
   押すと逆向き → 既定の 2 状態で回る。周期の決まりは ListSort::next() の docblock が正本。
```

③ `@php` の先頭 2 行:

```php
    $label = $columns[$column]['label'];
    $state = \App\Support\ListSort::stateOf($sort, $column);
```

を次に置き換える:

```php
    $spec  = $columns[$column];
    $label = $spec['label'];
    // 列ごとの周期（設計書 2026-09-11 §6.3）。省略時は従来どおり（1 回目は降順・既定順の列ではない）
    $first     = $spec['first'] ?? \App\Support\ListSort::DESC;
    $isDefault = $spec['default'] ?? false;
    $state = \App\Support\ListSort::stateOf($sort, $column, $first, $isDefault);
```

さらに `<a href="…">` の

```blade
    <a href="{{ \App\Support\ListSort::url(request(), $column, $sort) }}"
```

を次に置き換える:

```blade
    <a href="{{ \App\Support\ListSort::url(request(), $column, $sort, $first, $isDefault) }}"
```

⚠ 属性に `&quot;` を書かない（Bug #21）。`$spec['first']` のような PHP は `@php` の中だけに置く。

- [ ] **Step 5: 契約一覧のビューに見出し・hidden・バーを置く**

`resources/views/tenant/contracts/index.blade.php` を次の 3 か所だけ変える。

① フィルターフォームの開始タグの直後に hidden を置く（物件一覧・周辺ビル調査と同じ位置）:

```blade
    <form id="filter-form" method="GET" action="{{ route('tenant.contracts.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select onchange="document.getElementById('filter-form').submit()" name="property_id"
```

を次に置き換える:

```blade
    <form id="filter-form" method="GET" action="{{ route('tenant.contracts.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">

        <x-sort-hidden :sort="$sort" />
        <select onchange="document.getElementById('filter-form').submit()" name="property_id"
```

② フォームの閉じタグと表の間にバーを置く:

```blade
    </form>

    {{-- テーブル --}}
```

を次に置き換える:

```blade
    </form>

    {{-- ⚠ default-label は ContractController::applySort() の既定順と必ず揃える（SortBarTest が文言と並びを対で見ている） --}}
    <x-sort-bar :sort="$sort" :columns="$sortColumns" default-label="契約日の新しい順" />

    {{-- テーブル --}}
```

③ 見出しの 3 本を差し替える。Task 4 で足した契約日の `<th>` と、物件 / 区画・賃料収入の `<th>`:

```blade
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">契約日</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件 / 区画</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
```

を次に置き換える（店舗名・状態・操作は素の `<th>` のまま）:

```blade
                            <x-sortable-th column="contract_date" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="property_unit" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                            <x-sortable-th column="income" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
```

⚠ パディングは `<th>` でなく `link-class` 側（見出しセル全体を押せるようにするため。`x-sortable-th` の docblock）。

- [ ] **Step 6: 配線テストに 4 画面目のビューを登録する**

`tests/Feature/SortableListWiringTest.php` の `SORT_COLUMN_SOURCES` に 1 行足す:

```php
    private const SORT_COLUMN_SOURCES = [
        'tenant/area-buildings/index.blade.php' => [AreaBuildingListService::class, 7],
        'tenant/properties/index.blade.php'     => [PropertyController::class, 2],
        'tenant/units/index.blade.php'          => [UnitController::class, 3],
        'tenant/contracts/index.blade.php'      => [ContractController::class, 3],
    ];
```

- [ ] **Step 7: テストが通ることを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ContractListSortTest|SortBarTest|SortableListWiringTest|SortAffordanceTest'
```

Expected: 4 クラスとも OK（ContractListSortTest は 24 本）

- [ ] **Step 8: 全テストを走らせる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1345 tests, …)`。**前例 3 画面のテスト（`UnitListSortTest` / `PropertyListSortTest` / `AreaBuildingListSortTest`）が 1 本も落ちないこと**
（`x-sortable-th` が `first` / `default` を読むようになったが、3 画面の `SORT_COLUMNS` はどちらも持たない＝省略時の値で動く）。

- [ ] **Step 9: コミット**

```bash
git add resources/views/components/sortable-th.blade.php resources/views/tenant/contracts/index.blade.php tests/Feature/Tenant/ContractListSortTest.php tests/Feature/Tenant/SortBarTest.php tests/Feature/SortableListWiringTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 契約一覧の見出しで契約日・物件 / 区画・賃料収入を並び替える

x-sortable-th が SORT_COLUMNS の first / default を読んで ListSort に渡す。
契約日は既定順の列（初期表示で ▼ が点灯・押すと古い順 → 既定）、
物件 / 区画は 1 回目が昇順、賃料収入は多い順から。
並び順バーと、並び順をフィルターフォームに持ち回す hidden も置く。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: `SORT_COLUMNS` の任意キーの形を配線テストで固定する

**Files:**
- Modify: `tests/Feature/SortableListWiringTest.php`

⚠ `x-sortable-th` は `$spec['first'] ?? DESC` / `$spec['default'] ?? false` で読むので、
**打ち間違えたキー（`'frist'`）は黙って無視され、従来の周期で回る**（落ちない）。これを静的に止める（設計書 §7.3）。

- [ ] **Step 1: テストを書く**

① `use App\Support\ListSort;` を足す（`use App\Services\Tenant\AreaBuildingListService;` の次）。

② `test_every_sort_column_can_be_requested_without_erroring()` の**後ろ**（`seedOneRowPerScreen()` の docblock の前）に追加する:

```php
    /**
     * SORT_COLUMNS の任意キー（first / default）の形（設計書 2026-09-11 §7.3）。
     *
     * ⚠ x-sortable-th は `$spec['first'] ?? DESC` / `$spec['default'] ?? false` で読むので、
     *   **打ち間違えたキー（'frist'）は黙って無視され、従来の周期で回る**（落ちない）。
     *   既知のキー以外を置かないことをここで固定する。
     * ⚠ `first` は asc / desc だけ（ListSort::assertDirection() と同じ規則を静的にも見る。
     *   実行時の例外は、その列の見出しを描画するまで起きないため）。
     * ⚠ `default` は true だけ（false を書くくらいなら書かない。「既定順の列ではない」のか
     *   「書き忘れ」なのか読み手が区別できなくなる）。既定順の列は画面に高々 1 本。
     * ⚠ 走査の空振り防止（Bug #45）: 少なくとも 1 画面は first / default を実際に使っていること。
     *   使っている画面が無いと、上の検査は全部「対象ゼロで緑」になる。
     */
    public function test_sort_column_options_are_well_formed(): void
    {
        // label / desc / asc は全画面共通。first / default は周期の指定（契約一覧）。
        // expr / nullsLast（部屋一覧）と attribute（物件一覧）は各画面の並べ替えが読む
        $known = ['label', 'desc', 'asc', 'first', 'default', 'expr', 'nullsLast', 'attribute'];

        $usesFirst = false;
        $usesDefault = false;

        foreach (array_keys(self::SORT_ENDPOINTS) as $class) {
            $defaults = 0;

            foreach ($class::SORT_COLUMNS as $key => $spec) {
                $this->assertSame(
                    [],
                    array_values(array_diff(array_keys($spec), $known)),
                    "{$class}::SORT_COLUMNS['{$key}'] に知らないキーがある（打ち間違いは黙って無視される）"
                );

                if (array_key_exists('first', $spec)) {
                    $this->assertContains(
                        $spec['first'],
                        [ListSort::ASC, ListSort::DESC],
                        "{$class}::SORT_COLUMNS['{$key}']['first'] が asc / desc でない"
                    );
                    $usesFirst = true;
                }

                if (array_key_exists('default', $spec)) {
                    $this->assertTrue($spec['default'], "{$class}::SORT_COLUMNS['{$key}']['default'] は true だけ（false なら書かない）");
                    $defaults++;
                    $usesDefault = true;
                }
            }

            $this->assertLessThanOrEqual(1, $defaults, "{$class} に既定順の列が 2 本以上ある");
        }

        $this->assertTrue($usesFirst, 'first を使う列が 1 本も無い（走査が空振りしている。契約一覧の物件 / 区画が持つはず）');
        $this->assertTrue($usesDefault, 'default を使う列が 1 本も無い（走査が空振りしている。契約一覧の契約日が持つはず）');
    }
```

⚠ PHPUnit 11 の `assertContains()` / `assertTrue()` は**厳密比較**（`'ASC'` や `1` を通さない）。

- [ ] **Step 2: テストが通ることを確認する**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter SortableListWiringTest
```

Expected: OK。⚠ **この本は柵なので最初から緑。** 検出力は Task 7 の変異（`'frist'` への打ち間違い・`default` / `first` の削除）で測る。

- [ ] **Step 3: 全テストを走らせる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1346 tests, …)`

- [ ] **Step 4: コミット**

```bash
git add tests/Feature/SortableListWiringTest.php
git commit -m "$(cat <<'EOF'
test(sort): SORT_COLUMNS の任意キーの形を配線テストで固定する

first は asc / desc だけ・default は true だけ・既定順の列は画面に高々 1 本・
既知のキー以外は置かない（frist のような打ち間違いが黙って無視されるのを防ぐ）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 変異テストで「テストが本当に守っているか」を測る

**Files:** なし（測定のみ。結果をこのプランの表に書き戻してコミットする）

⚠ **「テストが緑」は検証にならない。** 変異を当てて赤になること・**落ちた理由の文言まで**突き合わせる（設計書 §7.4）。

### 作法（Bug #44。1 つでも省くと測定が無効になる）

1. **先にコミットする**（Task 6 まで済んでいること）。未コミットのまま変異を当てて `git checkout --` すると**自分の編集ごと巻き戻る**
2. 各変異の**前**に `git status --porcelain` が**空**
3. 当てた**直後**に `git diff --stat` が**非空**（当たっていない変異を「検出しない」と誤読しない）
4. `git checkout -- <当該ファイル>` で戻し、**もう一度** `git status --porcelain` が空
5. **赤/緑ではなく落ちた理由の文言**を突き合わせる（意図と別の機構が落としている可能性を排除する）
6. **変異は検査対象に入るはずの場所へ当てる**（docblock の中の同じ文字列に当てても測定にならない）

⚠ 1〜4 は下の `run.sh` が機械的にやる。**5 と 6 は人が読む。**
⚠ **`run.sh` の出力を `head` などへパイプしない**（SIGPIPE でスクリプトが `git checkout --` の前に死に、変異が作業ツリーに残る。前例で実際に踏んだ）。

- [ ] **Step 1: 道具を用意する**

変異の needle / replacement は**ファイル渡し**にし、シェルのクォートを一切通さない
（前例の計画の欠陥 #1: perl の `\Q…\E` が `$` を含む needle で 0 件マッチのまま exit 0 した）。
⚠ `MUT` はこのセッションの scratchpad 配下の**一意なディレクトリ**（別のセッションで実行するなら、そのセッションの scratchpad に読み替える）。

```bash
MUT=/private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-mut
mkdir -p "$MUT"

cat > "$MUT/mutate.php" <<'PHP'
<?php
// 使い方: php mutate.php <対象ファイル> <needle のファイル> <replacement のファイル>
// ⚠ needle がちょうど 1 件でなければ何もせず exit 1（0 件のまま「検出しない」と誤読する事故を防ぐ）
[, $target, $needleFile, $replacementFile] = $argv;
$source = file_get_contents($target);
$needle = rtrim(file_get_contents($needleFile), "\n");
$replacement = rtrim(file_get_contents($replacementFile), "\n");
$count = substr_count($source, $needle);
if ($count !== 1) {
    fwrite(STDERR, "needle が {$count} 件（ちょうど 1 件でなければ中止）: {$target}\n");
    exit(1);
}
file_put_contents($target, str_replace($needle, $replacement, $source));
echo "mutated: {$target}\n";
PHP

cat > "$MUT/run.sh" <<'SH'
#!/bin/bash
# 使い方: bash run.sh <番号> <対象ファイル> <phpunit の --filter>
# Bug #44 の作法: 前に清浄 → 当てる → 着弾確認 → 測る → 戻す → 後に清浄
set -u
MUT="$(cd "$(dirname "$0")" && pwd)"
id="$1"; target="$2"; filter="$3"
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting || exit 1
[ -z "$(git status --porcelain)" ] || { echo "中止: #$id の前に作業ツリーが汚れている"; exit 1; }
php "$MUT/mutate.php" "$target" "$MUT/$id.needle" "$MUT/$id.replacement" || exit 1
[ -n "$(git diff --stat)" ] || { echo "中止: #$id が当たっていない"; git checkout -- "$target"; exit 1; }
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter "$filter" > "$MUT/$id.log" 2>&1
status=$?
git checkout -- "$target"
[ -z "$(git status --porcelain)" ] || { echo "中止: #$id のあと作業ツリーが戻っていない"; exit 1; }
summary="$(grep -E '^(OK \(|Tests: )' "$MUT/$id.log" | tail -1)"
echo "#$id exit=$status $summary" | tee -a "$MUT/summary.txt"
grep -E -A2 '^[0-9]+\) ' "$MUT/$id.log"
SH

git status --porcelain && echo "---clean---"
```

Expected: `---clean---`（`MUT` は worktree の外なので git status に出ない）

- [ ] **Step 2: カナリアを通す（測定装置が worktree のコードを読んでいることの確認。Bug #50）**

**確実に赤くなるはずの変異**を 1 つ当て、実際に赤くなることを見てから本測定に入る。

```bash
cat > "$MUT/canary.needle" <<'EOF'
    {{-- テーブル --}}
EOF
cat > "$MUT/canary.replacement" <<'EOF'
    {{ $undefinedCanaryVariable }}
    {{-- テーブル --}}
EOF
bash "$MUT/run.sh" canary resources/views/tenant/contracts/index.blade.php ContractListSortTest
```

Expected: `exit=1` で、落ちた理由が **`Undefined variable $undefinedCanaryVariable`**。緑なら測定装置が壊れているので**先へ進まない**。

- [ ] **Step 3: 28 通りの変異を当てて測る**

needle / replacement の中身は Step 4 のスクリプトで作る。表の「結果」列を `✅ 赤（理由: …）` / `❌ 緑` で埋める。

| # | 変異する場所 | 変異の内容 | 赤になるはずのテスト（落ちる理由の文言） | 結果 |
|---|---|---|---|---|
| 1 | `ContractController::applySort()` の既定順 | 契約日のキーを外す（＝旧既定の 物件名 → 階数 → 号室） | `test_the_default_order_is_the_contract_date_newest_first`（既定順が契約日の新しい順になっていない）／ `SortBarTest::test_the_contracts_bar_names_the_real_default_order`（バーの文言と実際の並びが食い違っている） | ✅ 赤 10 本（`既定順が契約日の新しい順になっていない` ／ `バーの文言と実際の並びが食い違っている` ほか、同点・不正な sort・ページ送り・先頭セル・往復の 8 本） |
| 2 | 同 | 契約日を昇順にする | 同上 | ✅ 赤 10 本（#1 と同じ 10 本・同じ文言） |
| 3 | 同 | 物件名のキーを消す | `test_contracts_on_the_same_date_…`（同じ契約日の中が 物件名 → 階数 → 号室 → 契約 ID の新しい順になっていない） | ✅ 赤 1 本（表どおりの文言） |
| 4 | 同 | 階のキーを消す | 同上 | ✅ 赤 1 本（同上） |
| 5 | 同 | 号室のキーを消す | 同上 | ✅ 赤 1 本（同上） |
| 6 | 同 | 最後の `contracts.id DESC` を消す | 同上（`$old` と `$new` が入れ替わる） | ✅ 赤 1 本（同上。SQLite が同点を id の昇順で返す前提どおり） |
| 7 | 同 | 並び替え中は既定順を付けない（既定順の文全体を `if ($sort === null)` にする） | `test_rows_tied_on_the_sorted_column_keep_the_whole_default_order` | ✅ 赤 1 本（`賃料収入が同点の行が既定順になっていない（desc）`） |
| 8 | `applySort()` の `property_unit` | 号室を常に昇順にする | `test_property_unit_sorts_all_three_keys_in_the_same_direction`（降順が昇順の完全な逆順になっていない）＋ 見出しの往復 | ✅ 赤 2 本（`物件 / 区画の降順が昇順の完全な逆順になっていない（3 キーのどれかが昇順のまま）` ／ `2 回目が降順になっていない`） |
| 9 | 同 | 階を常に昇順にする | 同上 | ✅ 赤 3 本（#8 の 2 本 ＋ `フィルタを変えたら並び順が既定に戻った（property_unit の desc のままであるべき）`） |
| 10 | `Contract::MONTHLY_TOTAL_SQL` | 駆除代を式から落とす | `test_the_income_sql_agrees_with_the_php_accessor`（SQL 式と PHP アクセサで食い違う）＋ `test_income_sorts_…` | ✅ 赤 4 本（`契約 C-CS-002 の月額合計が SQL 式と PHP アクセサで食い違う` ／ `賃料収入の多い順になっていない（4 項目の合計で並べていない）` ／ `1 回目が多い順になっていない` ほか 1 本） |
| 11 | 同 | 共益費の `COALESCE` を外す | `test_the_income_sql_agrees_…`（COALESCE が外れて式が NULL になっている） | ✅ 赤 1 本（表どおりの文言） |
| 12 | `index.blade.php` | 契約日のセルを消す | `test_each_row_starts_with_its_contract_date`（各行の先頭セルが契約日（Y/m/d）になっていない） | ✅ 赤 1 本（表どおりの文言） |
| 13 | 同 | 契約日のセルを 2 列目へ動かす | 同上 | ✅ 赤 1 本（同上） |
| 14 | 同 | 書式を `Y-m-d` にする | 同上 | ✅ 赤 1 本（同上） |
| 15 | 同 | 0 件の行の `colspan` を 5 に戻す | `test_the_empty_row_spans_every_column` | ✅ 赤 1 本（`0 件の行の colspan が見出しの数と違う`） |
| 16 | `ContractController::SORT_COLUMNS` | 契約日の `'default' => true` を消す | `test_only_the_contract_date_header_is_lit…`（初期表示で契約日の見出しが点灯していない）／ 契約日の往復（既定から押したら古い順へ進むべき）／ 配線（default を使う列が 1 本も無い） | ✅ 赤 4 本（表の 3 つ ＋ `契約日を押したら既定へ戻すべき（並び替えを載せない）`） |
| 17 | 同 | 物件 / 区画の `'first' => ListSort::ASC` を消す | 物件 / 区画の往復（1 回目が昇順…になっていない）／ 配線（first を使う列が 1 本も無い） | ✅ 赤 2 本（表どおりの文言） |
| 18 | 同 | `'first'` を `'frist'` に打ち間違える | 配線（知らないキーがある）／ 物件 / 区画の往復 | ✅ 赤 2 本（`…['property_unit'] に知らないキーがある（打ち間違いは黙って無視される）` ／ `1 回目が昇順…になっていない`） |
| 19 | 同 | 物件 / 区画の `desc` / `asc` の言い方を入れ替える | `SortBarTest::test_the_contracts_bar_names_each_column_and_direction`（ピルに列名と向きが出ていない） | ✅ 赤 1 本（`?sort=property_unit&dir=asc のピルに列名と向きが出ていない`） |
| 20 | `ListSort::next()` | 既定順の列の分岐を消す（`return $isDefault ? null : $first;` → `return $first;`） | `ListSortTest`（別の列から押したら既定へ戻す）／ `test_clicking_the_contract_date_header_while_another_column_is_sorted…`（契約日を押したら既定へ戻すべき） | ✅ 赤 3 本（表の 2 つ ＋ `ListSortTest`: `既定順の列を押したのに並び替えが残っている`） |
| 21 | `ListSort::stateOf()` | 既定点灯を消す（`return $isDefault ? $first : null;` → `return null;`） | `ListSortTest`（既定順の列が初期表示で点灯していない）／ 初期表示の点灯 | ✅ 赤 5 本（表の 2 つ ＋ url の周期・契約日の往復 2 本） |
| 22 | 同 | `self::assertDirection($first);` を消す | `ListSortTest::test_an_unknown_first_direction_is_rejected`（stateOf() が first='ASC' を黙って受け入れた） | ✅ 赤 1 本（表どおりの文言） |
| 23 | `sortable-th.blade.php` | `stateOf()` に `first` / `default` を渡さない | 初期表示の点灯 ／ 往復で既定に戻ったときの点灯 | ✅ 赤 3 本（`初期表示で契約日の見出しが点灯していない` ／ `既定に戻ったのに契約日の見出しが点灯していない` ほか 1 本） |
| 24 | 同 | `url()` に `first` / `default` を渡さない | 契約日・物件 / 区画の往復（1 回目の向き） | ✅ 赤 3 本（`既定から押したら古い順へ進むべき` ／ `契約日を押したら既定へ戻すべき` ／ `1 回目が昇順…になっていない`） |
| 25 | 同 | `$spec['default'] ?? false` を `?? true` にする | 初期表示の点灯（並び替えていない列に aria-sort が載っている）＋ 前例 3 画面の aria-sort のテスト | ✅ 赤 11 本（契約一覧 4 本 ＋ 前例 3 画面 7 本: 周辺ビル 2・物件 3・部屋 2） |
| 26 | `index.blade.php` | `<x-sort-hidden>` を消す | `test_changing_a_filter_keeps_the_current_sort`（フィルターフォームが sort=… を持ち回していない）／ 配線（並び順を持ち回す hidden が無い） | ✅ 赤 2 本（表どおりの文言） |
| 27 | 同 | バーの `default-label` を「物件・階・部屋番号順」にする | `SortBarTest`（自分の既定順を名乗っていない／別の画面の既定順が出ている） | ✅ 赤 2 本（`http://localhost/tenant/contracts が自分の既定順を名乗っていない` ＋ 文言と並びの対のテスト） |
| 28 | `ContractController::index()` | `withQueryString()` を外す | `test_paging_through_a_sorted_list_…`（ページをまたいで並んでいない） | ✅ 赤 1 本（`賃料収入 多い順: ページ送りで行が重複している`）⚠ 文言は表と違う（下の実測メモ ③） |

⚠ #6 は「SQLite が同点を id の昇順で返す」ことに依存している（プラン作成時に実測済み）。緑だったら**測定を疑う前にその前提を測り直す**。

- [ ] **Step 4: needle / replacement と一覧（manifest）を作る**

⚠ needle は **Task 2〜5 で書いたコードと 1 文字も違わない**こと。`mutate.php` が「ちょうど 1 件」を確かめるので、
ずれていれば当てる前に止まる（その場合は needle をファイルの実物に合わせて直す。変異の意図は変えない）。

#1〜#15（コントローラ・モデル・ビュー）:

```bash
MUT=/private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-mut

# --- #1 既定順から契約日を外す（旧既定に戻す）
cat > "$MUT/1.needle" <<'EOF'
        $query->orderByDesc('contracts.contract_date')
            ->orderBy('properties.name')
EOF
cat > "$MUT/1.replacement" <<'EOF'
        $query->orderBy('properties.name')
EOF

# --- #2 既定順の契約日を昇順に
cat > "$MUT/2.needle" <<'EOF'
        $query->orderByDesc('contracts.contract_date')
EOF
cat > "$MUT/2.replacement" <<'EOF'
        $query->orderBy('contracts.contract_date')
EOF

# --- #3 既定順の物件名を消す
cat > "$MUT/3.needle" <<'EOF'
            ->orderBy('properties.name')
            ->orderBy('units.floor')
EOF
cat > "$MUT/3.replacement" <<'EOF'
            ->orderBy('units.floor')
EOF

# --- #4 既定順の階を消す
cat > "$MUT/4.needle" <<'EOF'
            ->orderBy('units.floor')
            ->orderBy('units.room_number')
EOF
cat > "$MUT/4.replacement" <<'EOF'
            ->orderBy('units.room_number')
EOF

# --- #5 既定順の号室を消す
cat > "$MUT/5.needle" <<'EOF'
            ->orderBy('units.room_number')
            ->orderByDesc('contracts.id');
EOF
cat > "$MUT/5.replacement" <<'EOF'
            ->orderByDesc('contracts.id');
EOF

# --- #6 最後の contracts.id DESC を消す
cat > "$MUT/6.needle" <<'EOF'
            ->orderBy('units.room_number')
            ->orderByDesc('contracts.id');
EOF
cat > "$MUT/6.replacement" <<'EOF'
            ->orderBy('units.room_number');
EOF

# --- #7 並び替え中は既定順を付けない
cat > "$MUT/7.needle" <<'EOF'
        $query->orderByDesc('contracts.contract_date')
EOF
cat > "$MUT/7.replacement" <<'EOF'
        if ($sort === null) $query->orderByDesc('contracts.contract_date')
EOF

# --- #8 property_unit の号室を常に昇順に
cat > "$MUT/8.needle" <<'EOF'
                    ->orderBy('units.room_number', $direction),
EOF
cat > "$MUT/8.replacement" <<'EOF'
                    ->orderBy('units.room_number'),
EOF

# --- #9 property_unit の階を常に昇順に
cat > "$MUT/9.needle" <<'EOF'
                    ->orderBy('units.floor', $direction)
EOF
cat > "$MUT/9.replacement" <<'EOF'
                    ->orderBy('units.floor')
EOF

# --- #10 賃料収入の式から駆除代を落とす（app/Models/Contract.php）
cat > "$MUT/10.needle" <<'EOF'
        . ' + COALESCE(contracts.garbage_fee, 0) + COALESCE(contracts.pest_control_fee, 0))';
EOF
cat > "$MUT/10.replacement" <<'EOF'
        . ' + COALESCE(contracts.garbage_fee, 0))';
EOF

# --- #11 共益費の COALESCE を外す
cat > "$MUT/11.needle" <<'EOF'
COALESCE(contracts.common_fee, 0)
EOF
cat > "$MUT/11.replacement" <<'EOF'
contracts.common_fee
EOF

# --- #12 契約日のセルを消す（resources/views/tenant/contracts/index.blade.php）
cat > "$MUT/12.needle" <<'EOF'
                                {{-- 契約日（テナント画面の日付は Y/m/d。契約詳細・区画詳細と同じ） --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $contract->contract_date->format('Y/m/d') }}
                                </td>
EOF
: > "$MUT/12.replacement"

# --- #13 契約日のセルを 2 列目へ動かす（物件 / 区画のセルと入れ替える）
cat > "$MUT/13.needle" <<'EOF'
                                {{-- 契約日（テナント画面の日付は Y/m/d。契約詳細・区画詳細と同じ） --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $contract->contract_date->format('Y/m/d') }}
                                </td>
                                {{-- 物件 / 区画 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    @php
                                        $dn = $contract->unit->display_name;
                                        $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                                    @endphp
                                    {{ $contract->property->name }} / {{ $unitLabel }}
                                </td>
EOF
cat > "$MUT/13.replacement" <<'EOF'
                                {{-- 物件 / 区画 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    @php
                                        $dn = $contract->unit->display_name;
                                        $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                                    @endphp
                                    {{ $contract->property->name }} / {{ $unitLabel }}
                                </td>
                                {{-- 契約日（テナント画面の日付は Y/m/d。契約詳細・区画詳細と同じ） --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $contract->contract_date->format('Y/m/d') }}
                                </td>
EOF

# --- #14 書式を Y-m-d に
cat > "$MUT/14.needle" <<'EOF'
{{ $contract->contract_date->format('Y/m/d') }}
EOF
cat > "$MUT/14.replacement" <<'EOF'
{{ $contract->contract_date->format('Y-m-d') }}
EOF

# --- #15 0 件の行の colspan を 5 に戻す
cat > "$MUT/15.needle" <<'EOF'
<td colspan="6"
EOF
cat > "$MUT/15.replacement" <<'EOF'
<td colspan="5"
EOF
```

#16〜#28（周期の指定・共通部品・ビューの配線）と manifest:

```bash
MUT=/private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-mut

# --- #16 契約日の 'default' => true を消す（ContractController::SORT_COLUMNS）
cat > "$MUT/16.needle" <<'EOF'
'asc' => '古い順',   'default' => true],
EOF
cat > "$MUT/16.replacement" <<'EOF'
'asc' => '古い順'],
EOF

# --- #17 物件 / 区画の 'first' => ListSort::ASC を消す
cat > "$MUT/17.needle" <<'EOF'
'asc' => '昇順',     'first' => ListSort::ASC],
EOF
cat > "$MUT/17.replacement" <<'EOF'
'asc' => '昇順'],
EOF

# --- #18 'first' を 'frist' に打ち間違える
cat > "$MUT/18.needle" <<'EOF'
'first' => ListSort::ASC],
EOF
cat > "$MUT/18.replacement" <<'EOF'
'frist' => ListSort::ASC],
EOF

# --- #19 物件 / 区画の向きの言い方を入れ替える
cat > "$MUT/19.needle" <<'EOF'
'desc' => '降順',     'asc' => '昇順',
EOF
cat > "$MUT/19.replacement" <<'EOF'
'desc' => '昇順',     'asc' => '降順',
EOF

# --- #20 ListSort::next() の既定順の列の分岐を消す（app/Support/ListSort.php）
cat > "$MUT/20.needle" <<'EOF'
            return $isDefault ? null : $first;
EOF
cat > "$MUT/20.replacement" <<'EOF'
            return $first;
EOF

# --- #21 ListSort::stateOf() の既定点灯を消す
cat > "$MUT/21.needle" <<'EOF'
            return $isDefault ? $first : null;
EOF
cat > "$MUT/21.replacement" <<'EOF'
            return null;
EOF

# --- #22 $first の検査を消す
cat > "$MUT/22.needle" <<'EOF'
        self::assertDirection($first);
EOF
: > "$MUT/22.replacement"

# --- #23 x-sortable-th が stateOf() に first / default を渡さない（resources/views/components/sortable-th.blade.php）
cat > "$MUT/23.needle" <<'EOF'
    $state = \App\Support\ListSort::stateOf($sort, $column, $first, $isDefault);
EOF
cat > "$MUT/23.replacement" <<'EOF'
    $state = \App\Support\ListSort::stateOf($sort, $column);
EOF

# --- #24 x-sortable-th が url() に first / default を渡さない
cat > "$MUT/24.needle" <<'EOF'
\App\Support\ListSort::url(request(), $column, $sort, $first, $isDefault)
EOF
cat > "$MUT/24.replacement" <<'EOF'
\App\Support\ListSort::url(request(), $column, $sort)
EOF

# --- #25 default の既定値を true にする（全列が既定順の列扱い）
cat > "$MUT/25.needle" <<'EOF'
    $isDefault = $spec['default'] ?? false;
EOF
cat > "$MUT/25.replacement" <<'EOF'
    $isDefault = $spec['default'] ?? true;
EOF

# --- #26 <x-sort-hidden> を消す（resources/views/tenant/contracts/index.blade.php）
cat > "$MUT/26.needle" <<'EOF'
        <x-sort-hidden :sort="$sort" />
EOF
: > "$MUT/26.replacement"

# --- #27 バーの default-label を別の画面の文言にする
cat > "$MUT/27.needle" <<'EOF'
default-label="契約日の新しい順"
EOF
cat > "$MUT/27.replacement" <<'EOF'
default-label="物件・階・部屋番号順"
EOF

# --- #28 withQueryString() を外す（ContractController::index()）
cat > "$MUT/28.needle" <<'EOF'
        $contracts = $query->paginate(10)->withQueryString();
EOF
cat > "$MUT/28.replacement" <<'EOF'
        $contracts = $query->paginate(10);
EOF

# --- manifest: 番号 <TAB> 対象ファイル <TAB> phpunit の --filter
C=app/Http/Controllers/Tenant/ContractController.php
M=app/Models/Contract.php
V=resources/views/tenant/contracts/index.blade.php
L=app/Support/ListSort.php
T=resources/views/components/sortable-th.blade.php
SHARED='ListSortTest|SortBarTest|SortableListWiringTest|SortAffordanceTest'
{
  printf '%s\t%s\t%s\n' 1 "$C" 'ContractListSortTest|SortBarTest'
  printf '%s\t%s\t%s\n' 2 "$C" 'ContractListSortTest|SortBarTest'
  for i in 3 4 5 6 7 8 9; do printf '%s\t%s\t%s\n' "$i" "$C" 'ContractListSortTest'; done
  for i in 10 11; do printf '%s\t%s\t%s\n' "$i" "$M" 'ContractListSortTest'; done
  for i in 12 13 14 15; do printf '%s\t%s\t%s\n' "$i" "$V" 'ContractListSortTest'; done
  for i in 16 17 18; do printf '%s\t%s\t%s\n' "$i" "$C" 'ContractListSortTest|SortableListWiringTest'; done
  printf '%s\t%s\t%s\n' 19 "$C" 'SortBarTest'
  for i in 20 21 22; do printf '%s\t%s\t%s\n' "$i" "$L" "$SHARED"; done
  for i in 23 24 25; do printf '%s\t%s\t%s\n' "$i" "$T" "$SHARED"; done
  printf '%s\t%s\t%s\n' 26 "$V" 'ContractListSortTest|SortableListWiringTest'
  printf '%s\t%s\t%s\n' 27 "$V" 'SortBarTest'
  printf '%s\t%s\t%s\n' 28 "$C" 'ContractListSortTest'
} > "$MUT/manifest.tsv"
wc -l "$MUT/manifest.tsv"
```

Expected: `28 …/manifest.tsv`

- [ ] **Step 5: 全部を測る**

⚠ 28 本 × 数秒〜十数秒なので **Bash の `run_in_background`** で流し、完了の通知を待つ（途中で止まったら `summary.txt` の最後の番号から再開する）。

```bash
MUT=/private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-mut
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting
git status --porcelain && echo "---clean---"
: > "$MUT/summary.txt"
while IFS=$'\t' read -r id target filter; do
  bash "$MUT/run.sh" "$id" "$target" "$filter" > "$MUT/$id.out" 2>&1 || { echo "中止: #$id（$MUT/$id.out を読む）"; break; }
done < "$MUT/manifest.tsv"
cat "$MUT/summary.txt"
git status --porcelain && echo "---clean---"
```

Expected: `summary.txt` に 28 行、**すべて `exit=1`**（＝赤）。最後にもう一度 `---clean---`。

- [ ] **Step 6: 落ちた理由を読み、表の「結果」列を埋める**

各 `"$MUT/<番号>.log"` の `1) Tests\…::test_…` の直後の行（失敗の文言）を、表の「赤になるはずのテスト」と突き合わせる。

- **`exit=0`（緑）だった変異** → テストの穴。**テストを足してから**その変異で赤になることを測り直す
  （足せない穴なら、理由を「既知の穴」に正直に書く。偽の安心より正直な穴のほうがよい）
- **赤だが理由が表と違う変異** → 意図と別の機構が落としている可能性。**理由の文言が表と一致するまで**調べる
  （前例: 赤の理由が意図したアサーションでなく 500 だった）

- [ ] **Step 7: 全テストを走らせる（作業ツリーが元に戻っていることの確認を兼ねる）**

```bash
git status --porcelain && echo "---clean---"
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `---clean---` ／ `OK (1346 tests, …)`（Step 6 でテストを足したなら、その分増える）

- [ ] **Step 8: 結果をこのプランに書き戻してコミット**

表の「結果」列と、下の「実測メモ」を埋める。

### 実測メモ（Task 7 の実施時に、この見出しの下へ書く）

次の 4 点を書く（無ければ「無し」と書く。**空欄のまま残さない**）:
① カナリアの結果（落ちた本数と理由の文言）
② `exit=0`（緑）だった変異と、その後の処置（足したテストのコミット、または既知の穴としての記録）
③ 赤だが理由が表と違った変異と、その原因
④ 測り方で踏んだ罠（needle が当たらなかった・理由の読み違い など）

**2026-09-11 実施（`dd14abd3` の上で測定。変異の前後とも `git status --porcelain` は空）**

**結論: 28 通りすべて赤・緑（検出漏れ）0。** 実施後の全テストは `OK (1346 tests, 9015 assertions)`。

① **カナリア**: 契約一覧 24 本中 **23 本が赤**。理由は `ErrorException: Undefined variable $undefinedCanaryVariable`
（`…/worktrees/contract-list-sorting/storage/framework/views/…` ＝ **worktree のコンパイル済みビュー**）。
測定装置が worktree のコードを読んでいることを確認した。緑の 1 本はビューを描画しない一致のテストで、想定どおり。

② **緑だった変異: 無し**（28 / 28 が赤）。テストの追加は不要だった。

③ **理由が表と違った変異: #28 だけ。** 表の想定は「ページをまたいで並んでいない」だが、実際は
`賃料収入 多い順: ページ送りで行が重複している`。同じテストの中で、**並びの検査より前に置いた件数・重複の検査が先に落ちた**ため。
`withQueryString()` が外れると 2 ページ目以降の URL から `sort` が消え、既定順（契約日）の 2 ページ目が混ざる
＝**意図した機構そのもの**で、別の機構が落としているわけではない。
なお「既定」のケースは素通りする（既定の画面は URL にクエリを持たないので、落ちるものが無い）。

④ **測り方で踏んだ罠**:
- カナリアの理由を `grep -c "Undefined variable \$undefinedCanaryVariable"` で数えたら **0 件**になった。
  正規表現の中で `$` が**行末アンカー**として解釈されたため（ログには実際に出ていた）。**固定文字列は `grep -F` で探す**こと。
  0 件を「カナリアが別の理由で落ちた」と読み違えないよう、ログを目で読んで理由を確かめた
- 変異の実行中、ハーネスが「ファイルが変わった」と差分を通知してきた（#1 / #20 / #25 の最中）。
  **戻すのは `run.sh` の役目なので手を出さず**、完了まで worktree に一切触らなかった（親の編集が変異の手順を壊すレースの予防）
- 観察: #3〜#6（既定順の 物件名 / 階 / 号室 / 契約 ID）は**それぞれ 1 本のテスト**（同じ契約日の中の並び）だけが検出する。
  4 キーの検出力はそのテストのデータ（キーを 1 つ消すと必ず並びが変わる形）に集中しているので、**データを簡単にしないこと**

```bash
git add docs/superpowers/plans/2026-09-11-tenant-contract-list-sorting.md
git commit -m "$(cat <<'EOF'
docs(plan): 契約一覧の並び替えの変異テストの実測結果を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

⚠ Step 6 でテストを足したときは、**テストの追加を先に別コミット**にする（`test(tenant): …`）。

---

## Task 8: 実ブラウザで確認し、列幅と最小幅を確定する（テストでは原理的に測れないもの）

**Files:**
- Modify（実測の結果しだい）: `resources/views/tenant/contracts/index.blade.php` の `colgroup` と `min-w-[…]`
- 一時的に変更して**必ず戻す**: `routes/web.php`（使い捨てのログイン用ルート）

⚠ 以下は **HTML に出ているかでは判定できない**（Bug #28 / #29 / #43 / #51）。
⚠ **`preview_start` を使わない**（main repo の `.claude/launch.json` が使われ、**実 MySQL** につながる。前例で実際に到達した）。
**`php artisan serve` を Bash の `run_in_background` で worktree 内・環境変数直渡しで起動し、`mcp__Claude_Browser__navigate` でつなぐ。**
⚠ **`.env` を作らない。** 使い捨ての SQLite・シードのスクリプトは **scratchpad に置く**（worktree の外なので git status が汚れない）。
⚠ **パスワードを一切扱わない。** 使い捨てのルートで `Auth::loginUsingId()` する（前例 2026-08-28 の Task 11 と同じ手）。

- [ ] **Step 1: ローカルで CSS をビルドする**

`min-w-[900px]` は任意値クラスなので、**ビルドするまで効かない**（worktree には `public/build` も `node_modules` も無い）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting
/Users/masanori/site/manage/node_modules/.bin/vite build
grep -oF '.min-w-\[900px\]' public/build/assets/app-*.css | head -1
git status --porcelain && echo "---clean---"
```

Expected: ビルド成功 ／ `.min-w-\[900px\]` が 1 行 ／ `---clean---`（`public/build` は gitignore 済み）

- [ ] **Step 2: 使い捨ての SQLite に検証用データを入れる**

⚠ **検証用データが列幅の判定基準そのもの**になる（設計書 §4.7 の「はみ出さない」はデータに依存する）。
次の上限を「収まるべき長さ」として扱う: **物件名 7 字・区画 `B1A` / `10A`・店舗名 10 字・賃料収入 7 桁（`1,234,567円`）・バッジ「解約済み」・ボタン 2 つ（詳細＋編集）**。
解約済みの行（同じ区画の旧契約を含む）・地下（`B1A`）・平屋（階なし）も入れる。

```bash
SP=/private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-verify
mkdir -p "$SP"
printf 'export APP_KEY=%q\nexport DB_CONNECTION=sqlite\nexport DB_DATABASE=%q\nexport APP_ENV=local\nexport APP_DEBUG=true\nexport APP_URL=http://localhost:8123\n' \
  "base64:$(php -r 'echo base64_encode(random_bytes(32));')" "$SP/verify.sqlite" > "$SP/verify-env.sh"
: > "$SP/verify.sqlite"

cat > "$SP/seed.php" <<'PHP'
<?php
// 使い捨ての検証用データ。⚠ 実装コードではない。確認が終わったら消す。
$wt = '/Users/masanori/site/manage/.claude/worktrees/contract-list-sorting';
require $wt . '/vendor/autoload.php';
$app = require $wt . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// ⚠ 実 DB に触れないための安全装置: sqlite かつ scratchpad のファイルでなければ止まる
$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'sqlite' || ! str_contains($database, '/scratchpad/')) {
    fwrite(STDERR, "中止: 接続が scratchpad の sqlite ではない（{$connection} / {$database}）\n");
    exit(1);
}

Artisan::call('migrate', ['--force' => true]);

// ⚠ role / status は $fillable に無いので create() の後に代入する。パスワードは使わない（使い捨てルートでログイン）
$user = User::create(['name' => '検証用', 'email' => 'verify@example.test', 'password' => bcrypt(bin2hex(random_bytes(16))), 'must_change_password' => false]);
$user->role = 'executive';
$user->status = 'active';
$user->save();

$customer = Customer::create(['code' => 'CU-V01', 'name' => '検証商事', 'customer_type' => 'corporation']);

$properties = [];
foreach ([['T-V01', '千舟町スクエア'], ['T-V02', '大街道プラザ'], ['T-V03', 'ミツワ本町ビル'], ['T-V04', '湊町テラス']] as [$code, $name]) {
    $properties[$name] = Property::create([
        'code' => $code, 'name' => $name, 'property_type' => 'tenant', 'department' => 'tenant',
        'operation_status' => 'active', 'address' => '愛媛県松山市',
    ]);
}

$n = 0;
$make = function (string $propertyName, ?int $floor, string $room, string $date, array $attrs = []) use (&$n, $properties, $customer) {
    $unit = Unit::firstOrCreate(
        ['property_id' => $properties[$propertyName]->id, 'display_name' => Unit::generateDisplayName($floor, $room)],
        ['floor' => $floor, 'room_number' => $room, 'status' => 'occupied']
    );

    return Contract::create(array_merge([
        'contract_number' => sprintf('C-V-%03d', ++$n), 'department' => 'tenant',
        'property_id' => $unit->property_id, 'unit_id' => $unit->id, 'customer_id' => $customer->id,
        'status' => 'active', 'contract_date' => $date, 'rent_start_date' => $date,
        'rent' => 180000, 'common_fee' => 15000, 'garbage_fee' => 3000, 'pest_control_fee' => 0,
    ], $attrs));
};

$make('千舟町スクエア', -1, 'A', '2025-03-05', ['rent' => 1180000, 'common_fee' => 54567, 'garbage_fee' => 0, 'store_name' => 'カフェ＆バル千舟町']);   // 1,234,567円
$make('千舟町スクエア', 1, 'A', '2024-11-20', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000, 'store_name' => '整体院ミツワ千舟町']);
$make('千舟町スクエア', 2, 'A', '2023-06-01', ['status' => 'terminated', 'contract_end_date' => '2025-03-31', 'store_name' => '居酒屋 大街道はなれ']);  // 旧契約
$make('千舟町スクエア', 2, 'A', '2025-04-15', ['store_name' => 'ベーカリー千舟町']);                                                                // 同じ区画の新契約
$make('大街道プラザ', 1, 'A', '2022-09-01');                                                                                                   // 店舗名なし（—）
$make('大街道プラザ', 1, 'B', '2021-01-10', ['store_name' => 'ドラッグストア大街道']);
$make('大街道プラザ', 3, 'C', '2025-03-05', ['store_name' => 'ヘアサロン大街道']);                                                             // 1 行目と同じ契約日
$make('大街道プラザ', 2, 'A', '2015-10-10', ['store_name' => '古書店大街道']);
$make('ミツワ本町ビル', 1, 'A', '2020-02-01', ['store_name' => 'ミツワ本町クリニック']);
$make('ミツワ本町ビル', 10, 'A', '2019-07-07', ['store_name' => '学習塾ミツワ本町校']);                                                         // 10A
$make('湊町テラス', null, 'A', '2018-04-01', ['store_name' => '湊町テラス食堂']);                                                             // 平屋（階なし）
$make('湊町テラス', null, 'B', '2017-12-24', ['status' => 'terminated', 'contract_end_date' => '2024-12-31', 'store_name' => '古着屋湊町']);
$make('湊町テラス', null, 'C', '2016-05-05', ['store_name' => '花屋みなとまち']);

echo 'seeded: contracts=' . Contract::count() . ' (active=' . Contract::where('status', 'active')->count() . ")\n";
PHP

source "$SP/verify-env.sh" && php "$SP/seed.php"
```

Expected: `seeded: contracts=13 (active=11)`（契約中 11 件 ＝ 2 ページ）

- [ ] **Step 3: 使い捨てのログイン用ルートを足す（Task 8 の最後に必ず消す）**

`routes/web.php` の**末尾**に追加する:

```php

// ⚠ 画面確認用の使い捨てルート。Task 8 が終わったら git checkout -- routes/web.php で消すこと
Route::get('/__preview-login', function () {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId(\App\Models\User::query()->value('id'));

    return redirect('/tenant/contracts');
});
```

- [ ] **Step 4: 開発サーバを立てる（Bash の `run_in_background`）**

```bash
lsof -nP -iTCP:8123 -sTCP:LISTEN || echo "8123 は空いている"
```

```bash
SP=/private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-verify
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting && source "$SP/verify-env.sh" && php artisan serve --port=8123
```

⚠ 後者は**終わらないプロセス**なので `run_in_background: true` で起動する。

`mcp__Claude_Browser__navigate` で `http://localhost:8123/__preview-login` を開く → `/tenant/contracts` に着地すること。

- [ ] **Step 5: 列ごとに「中身が要る幅」を実測する（1200px・`lg:` の余白で）**

⚠ **表を一時的に `table-layout: auto; width: auto` にすると、各列が中身の最大幅（`nowrap` なので 1 行ぶん）まで広がる。**
その見出しセルの幅が「その列に要る幅（余白込み）」。**画面を変えるのは測る間だけ**で、最後に元へ戻す（読み込み直しでも戻る）。
⚠ 本番には「家賃発生日未設定の ⚠」の行がありうるが、テストのスキーマでは作れない（前提 #5）。
**測る前に、賃料収入が最も長い行へ ⚠ を 1 つ差し込んで**本番の最悪ケースを再現する。

`mcp__Claude_Browser__resize_window` で **1200 × 900** にし、`http://localhost:8123/tenant/contracts?status=all` を開いてから
`mcp__Claude_Browser__javascript_tool` で実行する:

```js
(() => {
  const table = document.querySelector('main table');
  // 本番の最悪ケース: 賃料収入が最も長いセルに「家賃発生日未設定の ⚠」を足す（測定のためだけ）
  const incomeCells = [...table.querySelectorAll('tbody tr')].map(tr => tr.cells[3]).filter(Boolean);
  const widest = incomeCells.sort((a, b) => b.innerText.length - a.innerText.length)[0];
  const warn = document.createElement('span');
  warn.className = 'ml-1 text-amber-600 cursor-help';
  warn.textContent = '⚠';
  widest.append(warn);

  const saved = { className: table.className, style: table.getAttribute('style'), cols: [...table.querySelectorAll('col')].map(c => c.getAttribute('style')) };
  table.className = 'border-collapse';
  table.setAttribute('style', 'table-layout:auto; width:auto');
  table.querySelectorAll('col').forEach(c => c.removeAttribute('style'));
  const need = [...table.querySelectorAll('thead th')].map(th => Math.ceil(th.getBoundingClientRect().width));

  table.className = saved.className;
  table.setAttribute('style', saved.style);
  table.querySelectorAll('col').forEach((c, i) => c.setAttribute('style', saved.cols[i]));
  return { viewport: innerWidth, need, sum: need.reduce((a, b) => a + b, 0) };
})()
```

⚠ **並び替えた画面でも同じく測る**（`?status=all&sort=income&dir=desc`。見出しの ▼ / ▲ は ⇅ と同じ 12px 幅なので変わらないはずだが、測って確かめる）。
2 回の `need` の列ごとの**大きいほう**を採る。

- [ ] **Step 6: 列幅と最小幅を決める**

1. **最小幅** `W` ＝ `need` の合計を **10px 単位に切り上げ**
2. **各列の割合** `p_i` ＝ `need_i / W` を**百分率の整数に切り上げ**。合計が 100 を超えたら、
   **余裕（`p_i × W − need_i`）が最も大きい列から 1 ずつ**引いて 100 にそろえる
3. **全列で `p_i × W ≥ need_i`** を確かめる。満たさない列が出たら `W` を 10px 増やして 1 からやり直す
4. ⚠ **`W` が 1200px 幅での表の実幅（約 914px ＝ 1200 − サイドバー 220 − 余白 64 − 枠 2）を超えたら、
   1200px でも表が横スクロールする。** その場合は**実装を止めて利用者に見せて判断を仰ぐ**（収まるべき長さの上限を下げる等）

決めた値で `index.blade.php` の `min-w-[900px]` と 6 本の `<col style="width:…%">` を書き換え、
**任意値クラスが変わったので CSS をビルドし直す**:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting && /Users/masanori/site/manage/node_modules/.bin/vite build
```

⚠ 初期値（`900px` ／ 14・20・20・16・12・18 %）のままで Step 7 がすべて通るなら、**書き換えない**。

- [ ] **Step 7: はみ出しを 3 幅 × 3 画面で実測する（設計書 §4.7 の判定そのもの）**

幅: **375 × 812 ／ 1200 × 900 ／ 1800 × 1000**（`mcp__Claude_Browser__resize_window`。375 は preset `mobile`）
画面: **`/tenant/contracts` ／ `?status=all` ／ `?status=all&sort=income&dir=desc`**
⚠ **各画面で、読み込み直すたびに Step 5 と同じ ⚠ の差し込みをしてから**測る（読み込み直すと消える）:

⚠ **実施時に判明: 下の検出器は余白の内側への食い込みを検出できない**（`scrollWidth` は文字がセルの外枠を越えたときしか増えない）。
実際に 1200px で 3 列が食い込んでいたのに `offenders: []` と出た。**測り直しには「Task 8 の実測結果」の検出器を使うこと。**

```js
(() => {
  const main = document.querySelector('main');
  const table = main.querySelector('table');
  const incomeCells = [...table.querySelectorAll('tbody tr')].map(tr => tr.cells[3]).filter(Boolean);
  const widest = incomeCells.sort((a, b) => b.innerText.length - a.innerText.length)[0];
  if (widest && !widest.innerText.includes('⚠')) {
    const warn = document.createElement('span');
    warn.className = 'ml-1 text-amber-600 cursor-help';
    warn.textContent = '⚠';
    widest.append(warn);
  }

  const offenders = [...table.querySelectorAll('th, td, th a')]
    .filter(el => el.scrollWidth > el.clientWidth)
    .map(el => ({ col: el.closest('th, td').cellIndex, tag: el.tagName, text: el.innerText.replace(/\s+/g, ' ').trim().slice(0, 24), over: el.scrollWidth - el.clientWidth }));
  return {
    viewport: innerWidth,
    mainOverflow: main.scrollWidth - main.clientWidth,
    tableWidth: Math.round(table.getBoundingClientRect().width),
    headerWidths: [...table.querySelectorAll('thead th')].map(th => Math.round(th.getBoundingClientRect().width)),
    offenders,
  };
})()
```

Expected（**9 通りすべて**）: `mainOverflow: 0`（Bug #29）・`offenders: []`

⚠ 1 つでも `offenders` が出たら Step 6 へ戻る。**超過幅は画面幅によらず一定のことがある**（Bug #29）ので、片方の幅だけで判断しない。

- [ ] **Step 8: 操作と見た目を 5 点確かめる（設計書 §8 の 2〜6）**

| # | 確かめること | 測り方 |
|---|---|---|
| 1 | 見出しを**実際にクリック**して並びが変わる（契約日 2 回・物件 / 区画 3 回・賃料収入 3 回で既定に戻る） | `mcp__Claude_Browser__computer` の `left_click`（**見出しセルの端**＝文字の無い余白を押す。セル全体が押せることの確認を兼ねる）。URL と 1 列目・2 列目の並びを見る |
| 2 | 初期表示で契約日の見出しが**緑の ▼**（`aria-sort="descending"`）、物件 / 区画・賃料収入は**灰色の ⇅** | `getComputedStyle(矢印の span).color` が `rgb(5, 150, 105)` / `rgb(107, 114, 128)` |
| 3 | 見出しに Tab でフォーカスしたときのリングが**上下で切れない** | Tab を押して `:focus-visible` を確かめ、**1 秒待ってから** `outline` を読む（`transition-colors` が `outline-color` を遷移させるので、直後は灰色に見える。前例で誤読しかけた）。拡大して目視 |
| 4 | 並び替え中の列に**ホバーしても緑の下線が保たれる** | 初期表示の契約日と、賃料収入で並べた状態の賃料収入の 2 か所。`hover` のあと **1 秒待って** `getComputedStyle(ラベルの span).textDecorationColor === 'rgb(5, 150, 105)'` |
| 5 | コンソール出力 0 件 | `mcp__Claude_Browser__read_console_messages` |

⚠ 検証用データの「千舟町スクエア B1A」は画面に **`-1B1A`** と出る。**これは今回の改修と無関係な既存の表示の癖**
（`index.blade.php` の `$unitLabel` が「表示名が数字で始まらなければ階を前に付ける」ため、地下の `B1A` に `-1` が付く）。
**この Task では直さない**（設計書 §9。別タスクとして提案する）。列幅の実測にはこの 5 文字のまま含める。

- [ ] **Step 9: 後始末（使い捨てのルート・サーバ・ビルド・データを消す）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting
git checkout -- routes/web.php
lsof -nP -iTCP:8123 -sTCP:LISTEN -t | xargs kill 2>/dev/null; sleep 1; lsof -nP -iTCP:8123 -sTCP:LISTEN || echo "サーバ停止"
rm -rf public/build
rm -rf /private/tmp/claude-501/-Users-masanori-site-manage/05a1f2bd-6158-4741-a4b3-66f11582d4d1/scratchpad/contract-sort-verify
test ! -e .env && echo "OK: .env なし"
git status --porcelain
```

Expected: `サーバ停止` ／ `OK: .env なし` ／ `git status --porcelain` は **Step 6 で列幅を変えたときの `index.blade.php` だけ**（変えていなければ空）。
⚠ **`routes/web.php` が残っていたら絶対にコミットしない。**

- [ ] **Step 10: 列幅を変えたときだけ — テストとコミット**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ContractListSortTest|MobileLayoutTest'
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
git add resources/views/tenant/contracts/index.blade.php
git commit -m "$(cat <<'EOF'
fix(tenant): 契約一覧の列幅と最小幅を実測に合わせる

375 / 1200 / 1800px のどれでも、どのセルも中身が枠からはみ出さない値にする
（設計書 §4.7。実測の手順と値は計画の Task 8 に記録）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

Expected: どちらも OK（テストの本数は Task 7 の終わりと同じ）

- [ ] **Step 11: 実測の結果をこのプランに書き戻してコミット**

下の表と `need` の実測値・決めた `W` と割合を書く。

### Task 8 の実測結果（2026-09-11 実施）

⚠ **Step 7 の検出器（`scrollWidth > clientWidth`）には欠陥があった。** td の `scrollWidth` が `clientWidth` を
超えるのは、文字が**セルの外枠（パディングの外）**まで出たときだけで、**余白の内側への食い込みは原理的に現れない**。
実測（物件 / 区画の `千舟町スクエア / -1B1A` のセル）:

| 列の割合 | 内容幅を越えた量 | 外枠を越えた量 | `clientWidth` | `scrollWidth` |
|---|---|---|---|---|
| 20%（当時の値） | 17.2px | −2.8px（越えていない） | 183 | **183**（検出されない） |
| 15%（一時的に） | 55.7px | 35.7px | 144 | 180（＝ 左余白 20 ＋ 文字 160） |
| 12%（一時的に） | 80.8px | 60.8px | 119 | 180 |

旧検出器は 9 通りすべて `offenders: []` と報告したが、実際には 1200px で 3 列が食い込んでいた
（契約日 3.4px ・物件 / 区画 17.2px ・賃料収入＋⚠ 5.3px）。**設計書 §4.7 の判定式（`scrollWidth <= clientWidth`）も
同じ理由で「隣の列に重なる」ことしか測れない。**
→ 文字の範囲を取り、セルの**内容幅**（余白への食い込み）と**外枠**（隣の列への越境）の両方と比べる検出器に差し替えた:

```js
(() => {
  const main = document.querySelector('main');
  const table = main.querySelector('table');
  const px = (v) => parseFloat(v) || 0;
  // Step 5 と同じ ⚠ の差し込み（本番の最悪ケース）
  const incomeCells = [...table.querySelectorAll('tbody tr')].map(tr => tr.cells[3]).filter(Boolean);
  const widest = incomeCells.slice().sort((a, b) => b.innerText.length - a.innerText.length)[0];
  if (widest && !widest.innerText.includes('⚠')) {
    const warn = document.createElement('span');
    warn.className = 'ml-1 text-amber-600 cursor-help';
    warn.textContent = '⚠';
    widest.append(warn);
  }
  // 並び替えできる見出しは <th> でなく中の <a> が余白を持つ
  const owner = (cell) => cell.querySelector(':scope > a.sortable-th-link') || cell;
  // 文字（Range）と、インライン要素・フレックス項目・SVG の外形の和集合
  const ink = (root) => {
    let L = Infinity, R = -Infinity;
    const range = document.createRange();
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      if (!walker.currentNode.textContent.trim()) continue;
      range.selectNodeContents(walker.currentNode);
      for (const rc of range.getClientRects()) if (rc.width > 0) { L = Math.min(L, rc.left); R = Math.max(R, rc.right); }
    }
    for (const el of root.querySelectorAll('*')) {
      const d = getComputedStyle(el).display;
      const pd = getComputedStyle(el.parentElement).display;
      if (!(d.startsWith('inline') || /flex|grid/.test(pd) || el instanceof SVGElement)) continue;
      const rc = el.getBoundingClientRect();
      if (rc.width > 0) { L = Math.min(L, rc.left); R = Math.max(R, rc.right); }
    }
    return { L, R };
  };
  const into = Array(6).fill(-Infinity), beyond = Array(6).fill(-Infinity);
  for (const tr of table.rows) for (const cell of tr.cells) {
    const o = owner(cell), cs = getComputedStyle(o), r = o.getBoundingClientRect(), { L, R } = ink(o);
    if (!isFinite(L)) continue;
    const c = cell.cellIndex;
    into[c] = Math.max(into[c], r.left + px(cs.paddingLeft) - L, R - (r.right - px(cs.paddingRight)));
    beyond[c] = Math.max(beyond[c], r.left - L, R - r.right);
  }
  const f = (a) => a.map(v => +v.toFixed(1));
  // maxIntoPadding > 0 ＝ 余白へ食い込む ／ maxBeyondCell > 0 ＝ 隣の列へ越境する
  return { viewport: innerWidth, mainOverflow: main.scrollWidth - main.clientWidth, maxIntoPadding: f(into), maxBeyondCell: f(beyond) };
})()
```

| # | 確かめたこと | 結果 |
|---|---|---|
| 幅 | `need`（1200px・列ごと・⚠ 差し込み後） | **132 / 200 / 180 / 152 / 108 / 154 ＝ 926px**（Step 5 の自動レイアウト法と上の Range 法で一致。並び替えた画面でも同じ）。⚠ 無しなら賃料収入 129（計 903）、6 桁＋⚠ は 138。物件 / 区画の 200 は `-1B1A`（既存の表示の癖。`-1` のぶん約 9px） |
| 幅 | 決めた `W` と割合 | Step 6 の規則だと **W = 950**（整数 % への切り上げで、930・940 は合計が 103 になり成立しない）＞ 914 → **規則 4 で止めて利用者に確認した**。3 案（割合だけ配り直す ／ 最小幅 930px ／ この表だけ余白 16px）から **「割合だけ配り直す」に決定**: 最小幅 **900px 据え置き**・割合 **14.3 / 21.6 / 19.4 / 16.4 / 11.7 / 16.6 %**（`need` に比例、小数第 1 位。`3a8d57ec`） |
| 1 | 9 通りで `mainOverflow: 0`・隣の列への越境 0 | ✅ 9 通りとも。**余白への食い込み（列ごとの最大）**: 1200px ＝ 0.6 / 2.6 / 2.7 / 1.6 / 1.1 / 1.1px（許容と決めた範囲。3 画面とも同じ）／ 1800px ＝ なし（余裕 34.6px 以上）／ 375px ＝ なし（余裕 1.2px 以上。表は 900px でカード内スクロール） |
| 2 | 見出しの実クリックで 3 列とも周期どおり | ✅ 見出しセルの**左端から 8px**（文字の無い余白）をクリック。契約日: 古い順 → 既定 ／ 物件 / 区画: 昇順 → 降順 → 既定（物件名・階・号室がそろって向きを変える）／ 賃料収入: 多い順 → 少ない順（同額は既定順のまま）→ 既定。各段で URL・`aria-sort`・バーの文言も一致 |
| 3 | 初期表示の点灯（緑 ▼ ／ 灰色 ⇅） | ✅ 契約日 `aria-sort="descending"`・矢印 `rgb(5, 150, 105)`、物件 / 区画・賃料収入 `none`・`rgb(107, 114, 128)` |
| 4 | フォーカスリングが上下で切れない | ✅ 「クリア」から Tab 1 回で契約日の見出しへ。`:focus-visible`・1 秒後に `solid 2px rgb(5, 150, 105)`・offset −2px。スクリーンショットで緑の枠が 4 辺とも見える |
| 5 | ホバーで緑の下線が保たれる（2 か所） | ✅ 初期表示の契約日・多い順の賃料収入とも、1 秒後 `rgb(5, 150, 105)`。対照として並び替えていない物件 / 区画はホバーで `rgb(75, 85, 99)` に変わる（＝ホバーが効いた状態での結果） |
| 6 | コンソール出力 0 件 | ✅ 既定 ／ 賃料収入で並び替え ／ `?status=all&page=2` で 0 件 |

⚠ **フォントは Mac の Hiragino Sans で測った値。** `--font-sans` の先頭の Noto Sans JP は Web フォントとして読み込まれておらず
（`<link>` も `FontFace` も無い）、Windows では Meiryo に落ちて字幅が変わりうる。ここでは測れない。

⚠ ブラウザペインでは、**ページ遷移のたびにスクリーンショットを撮り直さないと座標クリックが拒否される**（座標の基準がリセットされる）。

```bash
git add docs/superpowers/plans/2026-09-11-tenant-contract-list-sorting.md
git commit -m "$(cat <<'EOF'
docs(plan): 契約一覧のブラウザ確認の実測結果を記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: 仕上げ（コンパイル済みビューの lint・全テスト・BACKLOG）

**Files:**
- Modify: `docs/BACKLOG.md`

- [ ] **Step 1: コンパイル済みビューを lint する（本番の `view:cache` でだけ壊れる型を排除。Bug #21 / #26 / #30）**

⚠ **`view:cache` の成功表示だけでは足りない**（コンパイル済みの PHP を lint しないため）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-list-sorting
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
php artisan view:cache && n=0 && for f in storage/framework/views/*.php; do n=$((n+1)); php -l "$f" >/dev/null || echo "INVALID: $f"; done; echo "views=$n"; php artisan view:clear
git status --porcelain && echo "---clean---"
```

Expected: `INVALID:` が **0 行** ／ `views=` の本数を記録する（前回 267 本。今回ビューの本数は増えていない）／ `---clean---`

- [ ] **Step 2: 全テストを走らせる**

```bash
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit
```

Expected: `OK (1346 tests, …)`（Task 7 でテストを足したなら、その分増える。**実測の数を BACKLOG に書く**）

- [ ] **Step 3: BACKLOG に記録する**

`docs/BACKLOG.md` の `## バックログ完了状況` の**直前**（その上の `---` の前）に節を足す。
⚠ 最小幅と割合・テストの本数は **Task 8 / Step 2 の実測値**を書く（この計画の数字を写さない）。
⚠ 下の塊は中にコードブロックを含むので、外側の囲いは**バッククォート 4 つ**にしてある（貼るのは内側だけ）。

````markdown
## ✅ テナント契約一覧 契約日の既定順と見出しの並び替え — 本番未反映

詳細仕様: @docs/superpowers/specs/2026-09-11-tenant-contract-list-sorting-design.md
実装計画: @docs/superpowers/plans/2026-09-11-tenant-contract-list-sorting.md

利用者の依頼（2026-09-11）は「テナント管理の『契約一覧』画面ですが契約日順に初期表示を変更して下さい。
そしてテーブルにも契約日を追加して下さい。」。対話の中で「列見出しでも並び替えたい」に広がった。
**DB 変更・ルート変更・新規 PHP クラスはいずれも無し。**

| 区分 | 実装内容 |
|------|---------|
| Support | `ListSort::stateOf()` / `next()` / `url()` に省略可能な `$first`（1 回目の向き）/ `$isDefault`（既定順の列か）。**省略時は従来と同一** |
| Model | `Contract::MONTHLY_TOTAL_SQL`（`getMonthlyTotalAttribute()` と同じ計算） |
| Controller | `Tenant\ContractController` に `SORT_COLUMNS`（3 列）＋ `applySort()` |
| Blade | `x-sortable-th` が `SORT_COLUMNS` の `first` / `default` を読む ／ 契約一覧に契約日の列・見出し 3 本・hidden・並び順バー |
| ルート / DB | **どちらも変更なし** |
| テスト | 1315 → **（Step 2 の実測）tests** |

### 要点

- **既定順は 契約日の新しい順 → 物件名 → 階数 → 号室 → 契約 ID の新しい順。** 最後の契約 ID で並びが一意になり、
  同じ区画の旧契約と新契約が「ステータス: すべて」でページをまたいで重複・欠落しなくなった（従来の並びは一意でなかった）
- 見出しで並び替える列は **契約日・物件 / 区画・賃料収入**（店舗名・状態・操作は並び替えない）
  - 契約日: 既定（▼ が点灯）→ 古い順 → 既定。**他の列で並び替え中に押すと既定へ戻る**
  - 物件 / 区画: **1 回目は昇順**（2026-05-11〜09-11 の既定順）→ 降順 → 既定。降順は 3 キーとも逆
  - 賃料収入: 多い順 → 少ない順 → 既定
- ⚠ **`ListSort` の周期を列ごとに指定できるようにした**（`SORT_COLUMNS` の `'first'` / `'default'`）。
  物件一覧・部屋一覧・周辺ビル調査は指定を持たないので**挙動は不変**（既存テストは 1 本も書き換えていない）
- 表は 6 列。最小幅と列幅は**実ブラウザで測って決めた**（計画の Task 8。375 / 1200 / 1800px のどれでも、どのセルもはみ出さない）
- ⚠ 地下の区画（表示名 `B1A`）が一覧で `-1B1A` と出る**既存の表示の癖**を見つけた（今回は直していない）

### 検証

- 全テスト（Step 2 の実測）／ 変異（Task 7 の本数と結果）／ コンパイル済みビューの `php -l`（Step 1 の本数・INVALID 0）
- ローカル実ブラウザ（Task 8 の表）

### 本番反映の手順

**DB 変更なし・ルート変更なし・新規 PHP クラスなし**なので `composer dump-autoload` は不要。

```
git checkout 13.x && git merge --ff-only contract-list-sorting
./deploy.sh
```

⚠ `13.x` には**未反映の「工程表ボードのガント改修」（2026-09-03〜04）**も入っているので、一緒に本番へ出る。

---
````

さらに `## バックログ完了状況` の中の「⚠ **未反映のものが 1 件ある**」の段落の**後ろ**に 1 段落足す:

```markdown
⚠ **もう 1 件未反映がある** —— 「テナント契約一覧 契約日の既定順と見出しの並び替え」（2026-09-11。上記の節）。
DB 変更・ルート変更・新規 composer 依存はいずれも無い。ガント改修と同じ `13.x` に乗るので、`deploy.sh` を流すと**両方が一緒に出る**。
```

- [ ] **Step 4: コミット**

```bash
git add docs/BACKLOG.md
git commit -m "$(cat <<'EOF'
docs: テナント契約一覧の契約日順と見出しの並び替えを BACKLOG に記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: 本番へ反映する（利用者の明示承認が要る）

⚠ **`./deploy.sh` はユーザーの明示的な承認を得てから流す。** 承認なしに実行しない（自動モードの分類器にも止められる）。
⚠ **`13.x` から `./deploy.sh` を流すと、未反映の「工程表ボードのガント改修」（2026-09-03〜04）も一緒に本番へ出る。** 承認を求めるときに必ず伝える。
⚠ **origin/13.x への push は、利用者が明示的に指示したときだけ。**

- [ ] **Step 1: 利用者に本番反映の可否を確認する**

`AskUserQuestion` で、次の 3 点を明示して確認する:
① この改修を本番へ出してよいか ② **ガント改修も一緒に出る**がよいか ③ origin へ push するか

- [ ] **Step 2: main repo の状態を確認する**

```bash
git -C /Users/masanori/site/manage status --porcelain && echo "---main clean---"
git -C /Users/masanori/site/manage rev-parse --abbrev-ref HEAD
git -C /Users/masanori/site/manage merge-base --is-ancestor 13.x contract-list-sorting && echo "OK: FF できる"
test ! -e /Users/masanori/site/manage/vendor/bin/phpunit && echo "OK: main repo の vendor に dev 依存が混ざっていない"
```

Expected: `---main clean---` ／ `13.x` ／ `OK: FF できる` ／ `OK: main repo の vendor に dev 依存が混ざっていない`
⚠ FF できないなら `13.x` が先に進んでいる。worktree で `git rebase 13.x` し、**全テストを流し直してから**やり直す。
⚠ `vendor/bin/phpunit` があるなら `deploy.sh` が dev 依存を本番へ送る。**流さずに利用者へ報告する。**

- [ ] **Step 3: FF マージする**

```bash
git -C /Users/masanori/site/manage merge --ff-only contract-list-sorting
git -C /Users/masanori/site/manage log --oneline -3
```

- [ ] **Step 4: デプロイする**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0（`npm run build` → rsync → 本番で `config:cache` / `route:cache` / `view:cache` がすべて成功）
⚠ `resources/css/app.css` は変えていないが、`min-w-[…]` の任意値が新しいので**ビルドで CSS のハッシュが変わる**。`deploy.sh` がビルドまでやる。

- [ ] **Step 5: 本番のコンパイル済みビューを lint する（利用者の承認が要る）**

⚠ 本番への ssh は read-only でも**明示の承認が要る**。本番のログインシェルは csh なので、`/bin/sh` へローカルの heredoc を流す。

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
n=0; bad=0
for f in storage/framework/views/*.php; do
  n=$((n+1))
  /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }
done
echo "views=$n invalid=$bad"
SH
```

Expected: `invalid=0`

- [ ] **Step 6: 本番で目視する（ログイン済みの実 Chrome ＝ claude-in-chrome）**

⚠ URL は **`/system/manage/index.php/tenant/contracts`**（`/index.php/` が必須。素のパスは 302 で流れる）。
⚠ **302 を「アプリは正常」の証明に使わない**（認証リダイレクトはビューを描画する前に起きる）。

| # | 確かめること |
|---|---|
| 1 | 表の先頭列が契約日（`Y/m/d`）で、**新しい順**に並んでいる |
| 2 | バーに「並び替え: 既定（契約日の新しい順）」、契約日の見出しが緑の ▼ |
| 3 | 「物件 / 区画」を押す → 物件名 → 階数 → 号室の昇順（2026-09-11 までの並び）になり、バーが「物件 / 区画 昇順」 |
| 4 | その状態でステータスを「すべて」に変える → **並び順が保たれる**。**全ページを送って 500 が出ない**（契約日が空の行があれば落ちる） |
| 5 | 1200px 前後の幅で `main` に横スクロールが出ていない（`document.querySelector('main').scrollWidth === clientWidth`）|
| 6 | コンソールのエラー 0 件 |

- [ ] **Step 7: 結果を BACKLOG に書き、見出しを「本番反映済み」にしてコミットする**

`docs/BACKLOG.md` の節の見出し `— 本番未反映` を `— 本番反映済み` に変え、反映日・`13.x` のハッシュ・Step 5 / 6 の結果を足す。
「もう 1 件未反映がある」の段落を消す（ガント改修の段落も、一緒に出たので同じく更新する）。

⚠ **この docs のコミットは main repo の `13.x` に直接積む**（worktree のブランチは FF 済みで役目を終えている）。
`git -C /Users/masanori/site/manage add docs/BACKLOG.md` → `git -C /Users/masanori/site/manage commit`（トレーラー付き）。
⚠ `docs/` は `deploy.sh` の rsync 対象外なので、この後の再デプロイは要らない。

- [ ] **Step 8: 利用者が指示したときだけ origin へ push する**

```bash
git -C /Users/masanori/site/manage push origin 13.x
```

---

## 自己レビュー（プラン作成後の確認）

### 1. 設計書のカバレッジ

| 設計書の節 | 対応する Task |
|---|---|
| §4.1 列の構成（6 列・契約日が先頭・`Y/m/d`） | Task 4（セル・見出し）／ Task 5（並び替え見出し 3 本） |
| §4.2 URL（キー 3 つ・不正な sort は既定・不正な dir は降順） | Task 3（`SORT_COLUMNS`・`test_invalid_sort_parameters_…`） |
| §4.3 見出しを押したときの切り替わり（3 列 × 周期・他の列から契約日で既定・手入力の降順） | Task 1（`ListSort`）／ Task 5（往復のテスト 5 本） |
| §4.4 並びの定義（既定順 5 キー・同点は既定順を丸ごと・3 キーとも同じ向き） | Task 3（`applySort()`・テスト 6 本） |
| §4.5 約束ごと 1〜5 | 1: Task 3 のページ送り ／ 2: Task 3 の同点 ／ 3: Task 5 のフォーム往復 ／ 4: Task 5 の 2 ページ目 ／ 5: Task 5 のクリアと解除 |
| §4.6 並び順バー | Task 5（バーの設置）＋ `SortBarTest` 3 本 |
| §4.7 列幅（375 / 1200 / 1800px ではみ出さない） | Task 4（初期値）／ Task 8（実測で確定） |
| §5 `ListSort` の拡張（省略時は同一・不正な `$first` は例外） | Task 1 |
| §6.1 触るファイル | File Structure（新規 1 / 変更 8 ＋ BACKLOG。設計書と一致） |
| §6.2 `SORT_COLUMNS` | Task 3 Step 3 ② |
| §6.3 `x-sortable-th` | Task 5 Step 4 |
| §6.4 並び順を実行する場所（JOIN は残す・`match` に default アーム無し） | Task 3 Step 3 ④ |
| §7.1 新規 `ContractListSortTest`（表の 15 行） | Task 2（1 行）／ Task 3（6 行）／ Task 4（1 行）／ Task 5（7 行） |
| §7.2 `ListSortTest`（4 項目・既存は書き換えない） | Task 1 |
| §7.3 配線テスト・バーのテスト | Task 3（`SORT_ENDPOINTS`）／ Task 5（`SORT_COLUMN_SOURCES`・`SortBarTest`）／ Task 6（任意キーの形） |
| §7.4 変異テスト 12 項目 | Task 7（28 通りに展開。1→#1 ／ 2→#2 ／ 3→#3 ／ 4→#6 ／ 5→#8・#9 ／ 6→#10 ／ 7→#12〜#14 ／ 8→#16・#17 ／ 9→#20・#21 ／ 10→#23・#24 ／ 11→#26 ／ 12→#27） |
| §8 ブラウザでの確認 6 点 | Task 8（Step 5〜7 が 1、Step 8 が 2〜6） |
| §9 やらないこと | どの Task にも含めていない（店舗名・状態の並び替え／解約日への切り替え／手入力の正規化／1 ページ 10 件・キーワード欄／前例 3 画面の周期） |
| §10 本番反映 | Task 10 |

**穴なし。**

### 2. プレースホルダ

「TBD」「後で」「適切に」「同様に」を検索して 0 件。すべての Step に実際のコード・コマンド・期待する出力がある。
**実測してから決める値**（列幅と最小幅・テストの最終本数）は、Task 8 / Task 9 に**決め方の手順と判定基準**を書いてあり、
結果を書き戻す表を置いてある（設計書 §4.7 が「値は実ブラウザで測って決める」と定めているため）。

### 3. 型・名前の一貫性

- `ListSort::stateOf(?self $current, string $key, string $first = self::DESC, bool $isDefault = false)`、`next()` / `url()` も同じ並び。非公開の新メソッドは `opposite()` / `assertDirection()`
- `SORT_COLUMNS` のキーは `contract_date` / `property_unit` / `income`。任意キーは `first` / `default`
- ビューへ渡す変数は前例 3 画面と同じ `$sort` / `$sortColumns`
- `x-sortable-th` の中の変数は `$spec` / `$label` / `$first` / `$isDefault` / `$state`
- `ContractListSortTest` の補助メソッド: `executive()` / `customer()` / `makeProperty()` / `makeUnit()` / `makeContract()` / `assertCreatedInOrder()` / `listedIds()` / `ids()` / `collectIdsAcrossPages()` / `threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder()` / `fourContractsForThePropertyUnitColumn()` / `fourContractsForTheIncomeColumn()` / `headerTexts()` / `bodyRows()` / `plainText()` / `assertFilterRoundTripKeepsOrder()`
- `SortBarTest` の補助メソッド: `makeTenantContract()`（プロパティ `$contractSeq`）

### 4. テスト本数の見込み

| Task | 増減 | 累計 |
|---|---|---|
| 基準（2026-09-11 実測） | — | 1315 |
| Task 1（`ListSortTest` 4 本） | +4 | 1319 |
| Task 2（一致のテスト 1 本） | +1 | 1320 |
| Task 3（並びの仕組み 9 本） | +9 | 1329 |
| Task 4（契約日の列 2 本） | +2 | 1331 |
| Task 5（見出し 12 本 ＋ バー 2 本） | +14 | 1345 |
| Task 6（任意キーの形 1 本） | +1 | 1346 |

⚠ **この数は目安。** 各 Task の「全テスト」で実測と食い違ったら、**プランの数字ではなく実測を信じる。**

### 5. プラン作成時に実測したこと（推測で書いていない箇所の裏取り）

| 事実 | 測り方 |
|---|---|
| 基準は **1315 tests / 8681 assertions** | worktree で `vendor/bin/phpunit`（2026-09-11） |
| **SQLite は同点の行を id の昇順で返す**（3.53.4） | scratchpad の PDO スクリプトで Task 3 の「同じ契約日」と同じ形の SQL を流した: `id DESC` あり `4,2,3,1,5` ／ なし `4,2,1,3,5` |
| worktree に設定キャッシュが無い | `ls bootstrap/cache/` → `packages.php` / `services.php` のみ |
| PHPUnit 11.5.55（`assertContains` は厳密比較） | `composer.lock` |
| テストのスキーマで `contracts.customer_id` / `rent_start_date` は NOT NULL、`units` は UNIQUE(property_id, display_name) | `database/migrations/0001_01_01_000006/7_*.php` |
| 契約一覧を描画する Feature テストは 0 本 | `grep -rn "tenant.contracts.index" tests/`（`ContractDeletionTest` のリダイレクト先だけ） |
| `SORT_COLUMNS` を定義するクラスは 3 つ（周辺ビル調査のサービス・部屋・物件） | `grep -rn "public const SORT_COLUMNS" app/` |
| `table-layout: fixed` の表は最小幅が必須 | `tests/Feature/MobileLayoutTest.php:183` |
| レイアウトの寸法（サイドバー 220px・`main` の余白 `p-4 lg:p-8`） | `layouts/app.blade.php` / `layouts/partials/sidebar.blade.php` |
| バッジは 12px・左右 10px の余白 | `resources/css/app.css` の `@utility badge` |
| `deploy.sh` の `SERVER` / `APP_PATH` / PHP 8.3 のパス | `deploy.sh` 4〜6 行・77〜79 行 |
| 地下の区画（`B1A`）が `-1B1A` と出る | `php -r` で `$unitLabel` の式を評価 → `-1B1A`。同じ式が契約の一覧・編集・解約・賃料改定・削除の 5 画面にある |

### 6. 既知の穴（意図して塞いでいないもの）

- **`units.floor` の NULL（平屋型）の並び位置はテストで固定していない**（MySQL・SQLite とも昇順で先頭・降順で末尾。旧既定と同じ挙動で、設計書 §4.4 も注記のみ）
- **「家賃発生日未設定の ⚠」はテストのスキーマで作れない**（NOT NULL）。列幅の実測は JS で ⚠ を差し込んで代用する（Task 8）
- **本番 MySQL の照合順序（`utf8mb4_unicode_ci`）での並びはテストでは測れない**。テストの物件名を ASCII で始めて順序が一致するデータにし、本番は Task 10 で目視する
- **MySQL で同点の順序が保証されない（設計書 §2.3）ことは SQLite では再現しない**（SQLite は決定的）。`contracts.id DESC` を消す変異は「SQLite が同点を id の昇順で返す」ことを利用して検出する（#6）
- **列幅の判定は検証用データに依存する**（物件名 7 字・店舗名 10 字・7 桁の賃料まで）。それより長い名前は従来どおりはみ出しうる
- **手入力 `?sort=contract_date&dir=desc` は正規化しない**（設計書 §4.3 の決定。バーは「契約日 新しい順」と名乗る）

### 7. 範囲外で見つけたこと（この計画では直さない）

- **地下の区画が `-1B1A` と出る。** 契約の一覧・編集・解約・賃料改定・削除の 5 画面の `$unitLabel` が
  「表示名が数字で始まらなければ階を前に付ける」式で、`Unit::generateDisplayName()` が作る地下の表示名 `B1A`（数字で始まらない）に
  `-1` を付けてしまう。平屋型（階なし）と地上階は正しい。設計書 §9（依頼と無関係な箇所は触らない）に従い、**別のタスクとして提案する**。

