# テナント管理 一覧の並び替え Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント管理の物件一覧（入居率・賃料収入）と部屋一覧（面積・家賃・月額合計）の列見出しを押して並び替えられるようにする。

**Architecture:** 並び替えは**サーバ側で全件に対して**行い、そのあとページを切る（1 ページ目の中だけで並ぶ壊れ方を原理的に排除する）。計算の在り処に合わせて **物件一覧は PHP・部屋一覧は SQL** で並べる。パラメータの解釈・3 状態の遷移・リンク URL の生成だけを `App\Support\ListSort` に集約し、見出しは JS を使わない素のリンク（`x-sortable-th` コンポーネント）にする。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（Alpine・JS の追加なし）/ PHPUnit（SQLite in-memory + `RefreshDatabase`）

**設計書:** @docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md
**モック:** @docs/mockups/tenant/list-sorting.html（**見た目と操作感の正本**。並べ替え規則の正本は設計書 §4.4）

---

## 前提の確認（実装前に必ず読む）

| # | 事実 | 出典 |
|---|---|---|
| 1 | **DB 変更なし。** マイグレーションも raw SQL も書かない | 設計書 §9 |
| 2 | **JavaScript を 1 行も足さない。** 見出しはただの `<a href>` | 設計書 §5.1 |
| 3 | **「—」を末尾へ回すのは「画面に `—` と出る列」だけ。** `units.rent` は nullable だが画面には `0円` と出るので **`COALESCE(units.rent, 0)` で 0 として並べる** | 設計書 §4.4 |
| 4 | `MONTHLY_TOTAL_SQL` は `COALESCE` 済みで **NULL になりえない** → null 判定句を書かない（常に false の死んだ SQL になる） | 設計書 §4.4 |
| 5 | **`->links()` に戻さない。** 既存のインライン番号付きページネーションを維持する | Bug #24 |
| 6 | Blade コンポーネント属性に **`&quot;` を書かない**（本番の `view:cache` でだけ 500） | Bug #21 |
| 7 | `Arr::query()` は **null のキーを丸ごと捨てる**（実測: `['a'=>null,'b'=>'','c'=>'x']` → `b=&c=x`）。リンク生成前に null を `''` へ正規化する | Bug #31 |
| 8 | テストの SQLite は**綴りを間違えたカラム参照を例外なく 0 で返す** | Bug #40 |
| 9 | **モックの `sortRows()` を並べ替え規則の正本として読まない**（列によらず null を末尾へ回すので #3 と食い違う） | 設計書 §4.5 |

---

## File Structure

| | ファイル | 責務 |
|---|---|---|
| 新規 | `app/Support/ListSort.php` | `?sort` / `?dir` の解釈・3 状態の遷移・見出しリンクの URL 生成。**並べ替えそのものはしない** |
| 新規 | `resources/views/components/sortable-th.blade.php` | 並び替え見出し `<th>`（リンク・矢印・`aria-sort`） |
| 新規 | `resources/views/components/sort-hidden.blade.php` | 並び順をフィルターフォームに持ち回す hidden（2 画面で共有） |
| 新規 | `tests/Concerns/ParsesSortLinks.php` | 描画された見出しリンクの href / aria-sort を取り出す（2 つの Feature テストが共有） |
| 新規 | `tests/Feature/SortableListWiringTest.php` | `<x-sortable-th` を持つビューは必ず `<x-sort-hidden` も持つことを**全件走査**で固定（Bug #45） |
| 新規 | `tests/Unit/Support/ListSortTest.php` | `ListSort` の単体テスト（DB 不要） |
| 新規 | `tests/Feature/Tenant/UnitListSortTest.php` | 部屋一覧の並び替え |
| 新規 | `tests/Feature/Tenant/PropertyListSortTest.php` | 物件一覧の並び替え |
| 変更 | `app/Models/Unit.php` | `MONTHLY_TOTAL_SQL` 定数を `getMonthlyTotalAttribute()` の**真横**に置く |
| 変更 | `app/Http/Controllers/Tenant/UnitController.php` | `index()` の `ORDER BY` を組み替える |
| 変更 | `app/Http/Controllers/Tenant/PropertyController.php` | `index()` を「全件取得 → 計算 → 並べ替え → 手動ページング」に |
| 変更 | `resources/views/tenant/units/index.blade.php` | 見出し 3 本を差し替え ＋ フィルタに hidden |
| 変更 | `resources/views/tenant/properties/index.blade.php` | 見出し 2 本を差し替え ＋ フィルタに hidden |

---

## Task 0: 作業用 worktree を用意する

**Files:** なし（環境準備のみ）

⚠ **main repo で `composer install` してはいけない。** dev 依存が入ると `./deploy.sh` が
`vendor/` を本番へ rsync してしまう（main repo に `vendor/bin/phpunit` が無いのは意図的）。
テストは必ず worktree で回す。

- [ ] **Step 1: worktree を作る**

`13.x` の現在の HEAD から分岐させる（`origin/13.x` 基準だと本番に出ている変更を含まないことがある）。

```bash
git worktree add .claude/worktrees/tenant-list-sorting -b tenant-list-sorting HEAD
```

- [ ] **Step 2: 分岐元が正しいことを確認する**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/tenant-list-sorting merge-base --is-ancestor 13.x HEAD && echo "OK: 13.x を含んでいる"
```

Expected: `OK: 13.x を含んでいる`

- [ ] **Step 3: dev 依存を入れる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-list-sorting && composer install
```

- [ ] **Step 4: テストが走ることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-list-sorting && vendor/bin/phpunit --testsuite=Unit
```

Expected: 全て PASS（この時点では既存テストのみ）

**以降、すべてのコマンドは `/Users/masanori/site/manage/.claude/worktrees/tenant-list-sorting` を cwd として実行する。**

---

## Task 1: `ListSort` — 並び替えパラメータの解釈

**Files:**
- Create: `app/Support/ListSort.php`
- Test: `tests/Unit/Support/ListSortTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ListSortTest.php` を新規作成:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\ListSort;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 並び替えパラメータの解釈（設計書 §4.1 / §4.2 / §5.2）。
 *
 * ⚠ Request::create() は**ミドルウェアを通らない**ので、実 HTTP なら
 *   ConvertEmptyStringsToNull が null にする値がここでは '' のまま届く（Bug #31）。
 *   両方を通すこと。null は query->set() で明示注入する。
 */
class ListSortTest extends TestCase
{
    private const ALLOWED = ['area', 'rent', 'monthly'];

    private function request(string $uri): Request
    {
        return Request::create($uri);
    }

    public function test_unknown_key_falls_back_to_default_order(): void
    {
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort=name'), self::ALLOWED));
    }

    public function test_array_sort_does_not_explode(): void
    {
        // ?sort[]=a は query('sort') が配列を返す。is_string() のガードが要る
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort[]=area'), self::ALLOWED));
    }

    public function test_script_like_sort_falls_back_to_default_order(): void
    {
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort=<script>'), self::ALLOWED));
    }

    public function test_empty_string_and_null_both_fall_back_to_default_order(): void
    {
        // Request::create() 経由（'' が届く）
        $this->assertNull(ListSort::fromRequest($this->request('/x?sort='), self::ALLOWED));

        // 実 HTTP 経由（ミドルウェアが null にしたもの）を明示注入
        $request = $this->request('/x');
        $request->query->set('sort', null);
        $this->assertNull(ListSort::fromRequest($request, self::ALLOWED));
    }

    public function test_direction_defaults_to_descending(): void
    {
        $this->assertSame(ListSort::DESC, ListSort::fromRequest($this->request('/x?sort=rent'), self::ALLOWED)->direction);
        $this->assertSame(ListSort::DESC, ListSort::fromRequest($this->request('/x?sort=rent&dir=up'), self::ALLOWED)->direction);
        $this->assertSame(ListSort::ASC, ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED)->direction);
    }

    public function test_key_is_kept(): void
    {
        $sort = ListSort::fromRequest($this->request('/x?sort=monthly&dir=asc'), self::ALLOWED);

        $this->assertSame('monthly', $sort->key);
        $this->assertTrue($sort->isAscending());
    }

    public function test_next_cycles_default_then_desc_then_asc_then_default(): void
    {
        $none = null;
        $this->assertSame(ListSort::DESC, ListSort::next($none, 'rent'));

        $desc = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);
        $this->assertSame(ListSort::ASC, ListSort::next($desc, 'rent'));

        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);
        $this->assertNull(ListSort::next($asc, 'rent'), '3 巡目は並び替え解除');
    }

    public function test_next_on_another_column_starts_at_desc(): void
    {
        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);

        $this->assertSame(ListSort::DESC, ListSort::next($asc, 'area'));
    }

    public function test_state_of_only_reports_the_active_column(): void
    {
        $desc = ListSort::fromRequest($this->request('/x?sort=rent&dir=desc'), self::ALLOWED);

        $this->assertSame(ListSort::DESC, ListSort::stateOf($desc, 'rent'));
        $this->assertNull(ListSort::stateOf($desc, 'area'));
        $this->assertNull(ListSort::stateOf(null, 'rent'));
    }

    public function test_url_drops_the_page_parameter(): void
    {
        $request = $this->request('/tenant/units?page=5');

        $url = ListSort::url($request, 'rent', null);

        $this->assertStringNotContainsString('page=', $url, '並べ替えたら 1 ページ目に戻す');
        $this->assertStringContainsString('sort=rent', $url);
        $this->assertStringContainsString('dir=desc', $url);
    }

    public function test_url_keeps_the_existing_filters(): void
    {
        $request = $this->request('/tenant/units?status=vacant&keyword=%E6%9C%AC%E7%94%BA');

        $url = ListSort::url($request, 'area', null);

        $this->assertStringContainsString('status=vacant', $url);
        $this->assertStringContainsString('keyword=', $url);
    }

    public function test_url_keeps_a_null_filter_by_normalising_it_to_an_empty_string(): void
    {
        // 実 HTTP では ?operation_status= がミドルウェアで null になる。
        // 正規化しないと Arr::query() がキーごと捨てて絞り込みがリンクから消える（Bug #31）
        $request = $this->request('/tenant/properties');
        $request->query->set('operation_status', null);

        $url = ListSort::url($request, 'occupancy', null);

        $this->assertStringContainsString('operation_status=', $url);
    }

    public function test_url_removes_sort_on_the_third_click(): void
    {
        $asc = ListSort::fromRequest($this->request('/x?sort=rent&dir=asc'), self::ALLOWED);
        $request = $this->request('/tenant/units?sort=rent&dir=asc&status=vacant');

        $url = ListSort::url($request, 'rent', $asc);

        $this->assertStringNotContainsString('sort=', $url, '3 巡目は既定順へ戻す');
        $this->assertStringNotContainsString('dir=', $url);
        $this->assertStringContainsString('status=vacant', $url, '絞り込みは残す');
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=ListSortTest
```

Expected: FAIL — `Class "App\Support\ListSort" not found`

- [ ] **Step 3: `ListSort` を実装する**

`app/Support/ListSort.php` を新規作成:

```php
<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * 一覧の並び替え指定（?sort=xxx&dir=asc|desc）の解釈と、見出しリンクの URL 生成。
 *
 * 設計書: docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md
 *
 * ⚠ ここは「指定をどう読むか」だけを持ち、**実際の並べ替えはしない**。
 *   物件一覧は PHP・部屋一覧は SQL と方法が違うため（設計書 §3.2）。
 *
 * ⚠ 「—」を末尾へ回す規則はここには無い。**列ごとに違う**ので各画面が持つ（設計書 §4.4）。
 */
class ListSort
{
    public const ASC = 'asc';

    public const DESC = 'desc';

    private function __construct(
        public readonly string $key,
        public readonly string $direction,
    ) {
    }

    /**
     * リクエストから並び替え指定を読む。
     *
     * ⚠ 不正・未知・未指定はすべて null（＝既定順）へ落とす。**500 にしない。**
     *   `?sort[]=a` のように配列で来ることがあるので `is_string()` が要る。
     *   `?sort=` は実 HTTP だと ConvertEmptyStringsToNull が null にし、
     *   `Request::create()` だと '' のまま届く（Bug #31）。どちらも許可リストを通らない。
     *
     * @param  list<string>  $allowed  この画面で並び替えを許すキー
     */
    public static function fromRequest(Request $request, array $allowed): ?self
    {
        $key = $request->query('sort');

        if (! is_string($key) || ! in_array($key, $allowed, true)) {
            return null;
        }

        $direction = $request->query('dir') === self::ASC ? self::ASC : self::DESC;

        return new self($key, $direction);
    }

    public function isAscending(): bool
    {
        return $this->direction === self::ASC;
    }

    /**
     * その列を今押したときの次の向き。null は「並び替え解除（既定順へ戻す）」。
     * 既定 → 降順 → 昇順 → 既定 の 3 状態（設計書 §4.2）。
     *
     * ⚠ 1 回目が降順なのは、金額と率なので「多い順」を先に見たいため。
     */
    public static function next(?self $current, string $key): ?string
    {
        if ($current === null || $current->key !== $key) {
            return self::DESC;
        }

        return $current->isAscending() ? null : self::ASC;
    }

    /** その列の現在の向き。並び替えに使っていなければ null */
    public static function stateOf(?self $current, string $key): ?string
    {
        return $current !== null && $current->key === $key ? $current->direction : null;
    }

    /**
     * 見出しリンクの URL。現在の絞り込みは維持しつつ並び替えだけ変える。
     *
     * ⚠ page は必ず落とす。並べ替えた直後に 5 ページ目に居るのはおかしい（設計書 §4.3-5）。
     * ⚠ null の値は '' へ正規化してから Arr::query() に渡す。怠ると
     *   `?operation_status=` のような空の絞り込みが**リンクから丸ごと消える**
     *   （実測: Arr::query(['a'=>null,'b'=>'','c'=>'x']) === 'b=&c=x'。Bug #31）。
     */
    public static function url(Request $request, string $key, ?self $current): string
    {
        $query = $request->query();
        unset($query['page']);

        $next = self::next($current, $key);

        if ($next === null) {
            unset($query['sort'], $query['dir']);
        } else {
            $query['sort'] = $key;
            $query['dir'] = $next;
        }

        $queryString = Arr::query(array_map(fn ($value) => $value ?? '', $query));

        return $request->url() . ($queryString === '' ? '' : '?' . $queryString);
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=ListSortTest
```

Expected: PASS（13 tests）。⚠ 後述の追加テスト（配列の絞り込み）を入れると **14 tests** になる

- [ ] **Step 5: コミット**

```bash
git add app/Support/ListSort.php tests/Unit/Support/ListSortTest.php
git commit -m "$(cat <<'EOF'
feat(support): 一覧の並び替え指定を読む ListSort を足す

?sort / ?dir の解釈・3 状態の遷移・見出しリンクの URL 生成だけを持たせる。
不正な値は 500 にせず既定順へ落とし、リンク生成では page を落として
null の絞り込みを '' へ正規化する（Arr::query が null のキーを捨てるため）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `Unit::MONTHLY_TOTAL_SQL` — PHP アクセサの真横に SQL 式を置く

**Files:**
- Modify: `app/Models/Unit.php:126-136`（`getMonthlyTotalAttribute()` の直前）
- Test: `tests/Feature/Tenant/UnitListSortTest.php`（新規。以降の Task でも使う）

月額合計だけは PHP と SQL の**二重実装**になる（Bug #41 そのもの）。
**離れた場所に書かず、アクセサの真横に置く**のが 1 段目の防御。2 段目がこの Task のテスト。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/UnitListSortTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 部屋一覧の並び替え（設計書 §4.4 / §5.4 / §6）。
 *
 * ⚠ 「—」を末尾へ回すのは**画面に「—」と出る列だけ**。
 *   面積は末尾へ、家賃は「0円」と出るので 0 として並べる。**別々のテストで固定する**
 *   （期待する位置が正反対なので、1 本にまとめると片方の変異が素通りする）。
 */
class UnitListSortTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProperty(string $code = 'T-S001'): Property
    {
        return Property::create([
            'code'             => $code,
            'name'             => '並び替えビル',
            'property_type'    => 'tenant',
            'department'       => 'tenant',
            'operation_status' => 'active',
            'address'          => '愛媛県松山市本町1-1',
        ]);
    }

    /** 部屋を 1 つ作る。金額・面積は指定が無ければ既定値。 */
    private function makeUnit(Property $property, string $room, array $attrs = []): Unit
    {
        return Unit::create(array_merge([
            'property_id'      => $property->id,
            'floor'            => 1,
            'room_number'      => $room,
            'display_name'     => $room,
            'status'           => 'vacant',
            'area_tsubo'       => 20.00,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 200000,
        ], $attrs));
    }

    /** 月額合計の SQL 式と PHP アクセサが同じ値を出すこと（Bug #41） */
    public function test_the_monthly_total_sql_agrees_with_the_php_accessor(): void
    {
        $property = $this->makeProperty();
        $this->makeUnit($property, '101', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000]);
        $this->makeUnit($property, '102', ['rent' => 180000, 'common_fee' => 18000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $this->makeUnit($property, '103', ['rent' => 95000,  'common_fee' => 9000,  'garbage_fee' => 1500, 'pest_control_fee' => 700]);
        $this->makeUnit($property, '104', ['rent' => null,   'common_fee' => null,  'garbage_fee' => null, 'pest_control_fee' => null]);

        $fromSql = Unit::selectRaw('id, ' . Unit::MONTHLY_TOTAL_SQL . ' as total')->pluck('total', 'id')->all();

        $values = [];
        foreach (Unit::orderBy('id')->get() as $unit) {
            $this->assertSame(
                $unit->monthly_total,
                (int) $fromSql[$unit->id],
                "部屋 {$unit->room_number} の月額合計が SQL 式と PHP アクセサで食い違う"
            );
            $values[] = $unit->monthly_total;
        }

        // ⚠ SQLite は綴りを間違えたカラム参照を**例外なく 0 で返す**（Bug #40）。
        //   値に分散が無いと「SQL を全部壊しても PHP と一致」で false-pass しうるので固定する。
        $this->assertGreaterThan(1, count(array_unique($values)), '月額合計に分散が無いデータでは検出力が出ない');
        $this->assertContains(315000, $values, '4 項目すべてを足していない（285000+25000+3000+2000）');
        $this->assertSame(0, $values[3], 'NULL ばかりの部屋は COALESCE で 0 になる');
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: FAIL — `Undefined constant App\Models\Unit::MONTHLY_TOTAL_SQL`

- [ ] **Step 3: 定数をアクセサの真横に置く**

`app/Models/Unit.php` の `getMonthlyTotalAttribute()` の**直前**（現状 126 行のコメントブロックの前）に挿入:

```php
    /**
     * 月額合計の SQL 式。**下の getMonthlyTotalAttribute() と同じ計算をする。**
     *
     * ⚠ 片方だけ直すと、画面の数字は正しいのに**並び順だけが別の値で並ぶ**（Bug #41）。
     *   画面から気づけないので、UnitListSortTest が両者の一致を固定している。
     * ⚠ COALESCE 済みなので**この式は NULL にならない**。
     *   並び替えで `(… IS NULL)` を前置しても常に false ＝ 死んだ SQL になるので書かないこと。
     */
    public const MONTHLY_TOTAL_SQL = '(COALESCE(units.rent, 0) + COALESCE(units.common_fee, 0)'
        . ' + COALESCE(units.garbage_fee, 0) + COALESCE(units.pest_control_fee, 0))';

```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: PASS（1 test）

- [ ] **Step 5: コミット**

```bash
git add app/Models/Unit.php tests/Feature/Tenant/UnitListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 月額合計の SQL 式を PHP アクセサの真横に置く

並び替えを SQL でやるため月額合計の式が PHP と 2 実装になる。離れた場所に
書くと片方だけ直す事故が起きるのでアクセサの直前に定数として置き、
両者が同じ値を出すことをテストで固定する。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 部屋一覧をサーバ側で並び替える

**Files:**
- Modify: `app/Http/Controllers/Tenant/UnitController.php:26-70`（`index()`）
- Test: `tests/Feature/Tenant/UnitListSortTest.php`（追記）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/UnitListSortTest.php` の `test_the_monthly_total_sql_agrees_with_the_php_accessor()` の**後ろ**に追記:

```php
    /**
     * ページ送りのリンクを実際に辿って、全ページの部屋 ID を順に集める。
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

            $paginator = $response->viewData('units');
            foreach ($paginator as $unit) {
                $ids[] = $unit->id;
            }

            $url = $paginator->nextPageUrl();

            $this->assertLessThan(20, ++$guard, 'ページ送りが終わらない');
        }

        return $ids;
    }

    /** 並び替え指定が無ければ、今までと同じ順（property_id → floor → room_number） */
    public function test_the_default_order_is_unchanged(): void
    {
        $property = $this->makeProperty();
        $c = $this->makeUnit($property, '301', ['floor' => 3]);
        $a = $this->makeUnit($property, '101', ['floor' => 1]);
        $b = $this->makeUnit($property, '201', ['floor' => 2]);

        $response = $this->actingAs($this->executive())->get(route('tenant.units.index'));

        $response->assertOk();
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $response->viewData('units')->pluck('id')->all()
        );
    }

    /** 不正な sort / dir は 500 にせず既定順へ落ちる */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $property = $this->makeProperty();
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 300000]);
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 100000]);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => 200000]);

        $expected = [$a->id, $b->id, $c->id];
        $user = $this->executive();

        foreach ([
            '?sort=name',            // 許可リストに無い
            '?sort[]=rent',          // 配列で来る
            '?sort=%3Cscript%3E',    // 手入力・古いブックマーク
            '?sort=',                // 空
            '?sort=rent&dir=up',     // dir だけ不正 → 既定の降順になる（下で別途確認）
        ] as $queryString) {
            $response = $this->actingAs($user)->get(route('tenant.units.index') . $queryString);
            $response->assertOk();

            if ($queryString === '?sort=rent&dir=up') {
                continue;   // sort は妥当なので既定順ではなく「降順」になる
            }

            $this->assertSame(
                $expected,
                $response->viewData('units')->pluck('id')->all(),
                "{$queryString} で既定順に落ちていない"
            );
        }

        // dir だけ不正なら降順（設計書 §4.2: 1 回目は降順）
        $response = $this->actingAs($user)->get(route('tenant.units.index') . '?sort=rent&dir=up');
        $this->assertSame([$c->id, $b->id, $a->id], $response->viewData('units')->pluck('id')->all());
    }

    /** 面積は画面で「—」と出るので、昇順でも降順でも末尾（設計書 §4.4） */
    public function test_units_without_an_area_sort_last_in_both_directions(): void
    {
        $property = $this->makeProperty();
        $small   = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 10.00]);
        $noArea  = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => null]);
        $large   = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 30.00]);

        $user = $this->executive();

        $desc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'desc']));
        $this->assertSame([$large->id, $small->id, $noArea->id], $desc->viewData('units')->pluck('id')->all());

        $asc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'asc']));
        $this->assertSame([$small->id, $large->id, $noArea->id], $asc->viewData('units')->pluck('id')->all());
    }

    /**
     * 家賃 NULL は画面に「0円」と出るので **0 として並べる**（末尾へ飛ばさない）。
     *
     * ⚠ 上の面積のテストと**期待する位置が正反対**。1 本にまとめてはいけない。
     */
    public function test_units_with_a_null_rent_sort_as_zero_not_last(): void
    {
        $property = $this->makeProperty();
        $high    = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 300000]);
        $nullish = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => null]);
        $low     = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 100000]);

        $user = $this->executive();

        // ⚠ 0 ではなく NULL が入っていることを先に固定する。
        //   0 で作ると NULL の経路を一度も通らず、この検査が空振りして緑になる（設計書 §6.2-5）
        $this->assertNull($nullish->fresh()->rent, '家賃が NULL のデータになっていない');

        $asc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'rent', 'dir' => 'asc']));
        $this->assertSame(
            [$nullish->id, $low->id, $high->id],
            $asc->viewData('units')->pluck('id')->all(),
            'NULL の家賃が 0 として先頭に来ていない（末尾へ飛ばしている）'
        );

        $desc = $this->actingAs($user)->get(route('tenant.units.index', ['sort' => 'rent', 'dir' => 'desc']));
        $this->assertSame([$high->id, $low->id, $nullish->id], $desc->viewData('units')->pluck('id')->all());
    }

    /**
     * 既知の穴（設計書 §4.4）: area_tsubo = 0 は画面では「—」と出るが IS NULL ではないので
     * **末尾へ飛ばず 0 として並ぶ**。実データに 0 件で到達不能なため直さない。
     * 意図であることをここで固定しておく（勝手に「直した」ときに落ちる）。
     */
    public function test_a_zero_area_is_not_pushed_to_the_end(): void
    {
        $property = $this->makeProperty();
        $zero   = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 0]);
        $noArea = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => null]);
        $large  = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 30.00]);

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'asc']));

        $this->assertSame(
            [$zero->id, $large->id, $noArea->id],
            $response->viewData('units')->pluck('id')->all(),
            '0 の面積は末尾へ回さない（NULL だけを回す）'
        );
    }

    /**
     * 同点の中は既定順。
     *
     * ⚠ **id の昇順と既定順（floor 昇順）がわざと食い違う順で作る。**
     *   同じ順で作ると、第 2 キーを消しても SQLite が id 順＝既定順で返してしまい
     *   変異が素通りする（Bug #52 の「真ん中の行が落ちるデータで書く」と同じ理屈）。
     */
    public function test_tied_rows_keep_the_default_order(): void
    {
        $property = $this->makeProperty();
        $third  = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 100000]);
        $first  = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 100000]);
        $second = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => 100000]);

        $this->assertSame(
            [$third->id, $first->id, $second->id],
            Unit::orderBy('id')->pluck('id')->all(),
            'id 順と既定順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.units.index', ['sort' => 'rent', 'dir' => 'desc']));

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $response->viewData('units')->pluck('id')->all(),
            '同点の中が既定順になっていない'
        );
    }

    /**
     * ページをまたいでも行が重複せず・消えず・全体を通して降順であること。
     *
     * ⚠ **1 ページ目だけでは測れない。** 1 ページ目の 20 件が降順に並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（設計書 §3.1）。
     */
    public function test_paging_through_a_sorted_list_yields_every_unit_exactly_once(): void
    {
        $property = $this->makeProperty();
        $rentById = [];

        // 25 件（＝ 2 ページ）。家賃は作成順と逆向きに増やすので、
        // 「ページを切ってから並べ替える」実装では 2 ページ目に大きい値が現れる
        for ($i = 1; $i <= 25; $i++) {
            $unit = $this->makeUnit($property, sprintf('%03d', $i), [
                'floor' => $i,
                'rent'  => $i * 10000,
            ]);
            $rentById[$unit->id] = $i * 10000;
        }

        $ids = $this->collectIdsAcrossPages(
            $this->executive(),
            route('tenant.units.index', ['sort' => 'rent', 'dir' => 'desc'])
        );

        $this->assertCount(25, $ids, 'ページ送りで行が消えている');
        $this->assertCount(25, array_unique($ids), 'ページ送りで行が重複している');
        $this->assertEqualsCanonicalizing(Unit::pluck('id')->all(), $ids);

        $rents = array_map(fn ($id) => $rentById[$id], $ids);
        $sorted = $rents;
        rsort($sorted);
        $this->assertSame($sorted, $rents, 'ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）');
        $this->assertSame(250000, $rents[0], '最大の家賃が 1 ページ目の先頭に来ていない');
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: FAIL — 並び替えが未実装なので `test_units_without_an_area_sort_last_in_both_directions` 等が
`Failed asserting that two arrays are identical.` で落ちる

- [ ] **Step 3: `UnitController::index()` を書き換える**

`app/Http/Controllers/Tenant/UnitController.php` の冒頭 `use` に 2 行足す。
**既存の並びはアルファベット順**なので、その順序を保って挿入する:

```php
use App\Support\ListSort;                        // ← App\Services\... の直後
use Illuminate\Database\Eloquent\Builder;        // ← App\Support\... の直後
```

まず `index()` の**直前**（クラスの先頭、`public function index` の上）に定数を置く:

```php
    /**
     * 部屋一覧で並び替えを許す列 → [SQL 式, 「—」を末尾へ回すか]。
     *
     * ⚠ **許可リストはここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡す。
     *   許可リストと式のマップを別々のリテラルで持つと、キーを足して片方を忘れたときに
     *   一覧が 500 になる。しかも「不正なキーを投げる」テストでは**この向きの取り違えを
     *   原理的に検出できない**（不正キーは既定順に落ちるだけなので）。
     *
     * ⚠ 「—」を末尾へ回すのは**画面に「—」と出る列だけ**（設計書 §4.4）。
     *   units.rent は nullable だが、ビューは number_format(null) で **「0円」**と描画するので、
     *   末尾へ飛ばさず COALESCE で 0 として並べる。ここを揃え損なうと
     *   「画面は 0円 なのにその行だけ末尾に飛ぶ」という食い違いになる（Bug #41 / #46）。
     */
    private const SORTABLE = [
        'area' => ['units.area_tsubo', true],           // NULL は画面で「—」  → 末尾へ
        'rent' => ['COALESCE(units.rent, 0)', false],   // NULL は画面で「0円」→ 0 として
        'monthly' => [Unit::MONTHLY_TOTAL_SQL, false],  // COALESCE 済みで NULL になりえない
    ];
```

`index()` の中で、既存の並び順の 4 行:

```php
        $units = $query->orderBy('property_id')
                       ->orderBy('floor')
                       ->orderBy('room_number')
                       ->paginate(20)
                       ->withQueryString();
```

を次に置き換える:

```php
        $this->applySort($query, $sort);

        $units = $query->paginate(20)->withQueryString();
```

`return view(...)` の `compact()` に `'sort'` を足す。あわせて `index()` の **1 行目**
（`$query = Unit::whereHas(...)` の前）に次を置く:

```php
        $sort = ListSort::fromRequest($request, array_keys(self::SORTABLE));
```

`return` 行はこうなる:

```php
        return view('tenant.units.index', compact('units', 'properties', 'propertyIdsForJs', 'sort'));
```

そして `index()` の**直後**に private メソッドを足す:

```php
    /**
     * 部屋一覧の並び替えを適用する。指定が無ければ既定順だけを付ける。
     *
     * ⚠ 「—」を末尾へ回すのは**画面に「—」と出る列だけ**（設計書 §4.4）。
     *   units.rent は nullable だが、ビューは number_format(null) で **「0円」**と描画するので、
     *   末尾へ飛ばさず COALESCE で 0 として並べる。ここを揃え損なうと
     *   「画面は 0円 なのにその行だけ末尾に飛ぶ」という食い違いになる（Bug #41 / #46）。
     *
     * ⚠ 許可リストを通った値しか来ないので、self::SORTABLE のキーは必ず存在し、
     *   式は必ずコード内の定数。利用者の入力が SQL に混ざる経路は無い。
     */
    private function applySort(Builder $query, ?ListSort $sort): void
    {
        if ($sort !== null) {
            [$expression, $nullsLast] = self::SORTABLE[$sort->key];

            if ($nullsLast) {
                $query->orderByRaw("({$expression} IS NULL) asc");
            }

            $query->orderByRaw($expression . ' ' . ($sort->isAscending() ? 'asc' : 'desc'));
        }

        // ⚠ 既定順は**必ず最後に**付ける。これが無いと同点の行がページをまたいで
        //   重複したり消えたりする（設計書 §4.3-3）。
        $query->orderBy('units.property_id')
              ->orderBy('units.floor')
              ->orderBy('units.room_number');
    }
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: PASS（8 tests）

- [ ] **Step 5: 既存テストが壊れていないことを確認する**

```bash
vendor/bin/phpunit
```

Expected: 全て PASS

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Tenant/UnitController.php tests/Feature/Tenant/UnitListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 部屋一覧を面積・家賃・月額合計でサーバ側から並び替える

156 件 8 ページあるので、ページを切ったあとに並べると 1 ページ目の中だけで
並んで答えが変わる。ORDER BY を組み替えて全件を並べてからページを切る。

「—」を末尾へ回すのは画面に「—」と出る面積だけにする。家賃は nullable だが
画面には 0円 と出るので COALESCE で 0 として並べ、表示と並び順を揃える。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 見出しコンポーネント `x-sortable-th` ＋ 部屋一覧の見出し差し替え

**Files:**
- Create: `resources/views/components/sortable-th.blade.php`
- Create: `tests/Concerns/ParsesSortLinks.php`（部屋一覧・物件一覧の**両方**が使う）
- Modify: `resources/views/tenant/units/index.blade.php:114,116,120`（面積 / 家賃 / 月額合計の `<th>`）
- Test: `tests/Feature/Tenant/UnitListSortTest.php`（追記）

- [ ] **Step 1: 失敗するテストを書く**

まず共有トレイトを作る。`tests/Concerns/ParsesSortLinks.php` を新規作成:

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
 * ⚠ 部屋一覧・物件一覧の両方が使う。**複製しないこと**（片方だけ直す事故が起きる）。
 */
trait ParsesSortLinks
{
    protected function sortLinkFor(string $html, string $label): string
    {
        $pattern = '/<a\b[^>]*\bhref="([^"]*)"[^>]*>\s*' . preg_quote($label, '/') . '\s*</u';

        $this->assertMatchesRegularExpression($pattern, $html, "「{$label}」の並び替えリンクが見つからない");
        preg_match($pattern, $html, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
}
```

`tests/Feature/Tenant/UnitListSortTest.php` の `use` に 1 行足す（`use Tests\TestCase;` の**上**）:

```php
use Tests\Concerns\ParsesSortLinks;
```

クラス宣言直下の `use RefreshDatabase;` を次に変える:

```php
    use ParsesSortLinks;
    use RefreshDatabase;
```

そのうえで、クラスの末尾（閉じ括弧の直前）に追記:

```php
    /**
     * 見出しを 3 回押す往復。既定 → 降順 → 昇順 → 既定。
     *
     * ⚠ 面積の昇順と既定順（floor 昇順）が**わざと食い違う**データにしてある。
     *   一致させると 2 回目と 3 回目の結果が同じになり、片方が壊れても緑になる。
     */
    public function test_clicking_the_area_header_three_times_cycles_back_to_the_default_order(): void
    {
        $property = $this->makeProperty();
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 20.00]);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => 30.00]);
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 10.00]);

        $user    = $this->executive();
        $default = [$a->id, $b->id, $c->id];

        $this->assertNotSame($default, [$c->id, $a->id, $b->id], '既定順と面積昇順が同じデータになっている');

        // 1 回目: 既定 → 降順
        $html  = $this->actingAs($user)->get(route('tenant.units.index'))->getContent();
        $first = $this->actingAs($user)->get($this->sortLinkFor($html, '面積'));
        $first->assertOk();
        $this->assertSame([$b->id, $a->id, $c->id], $first->viewData('units')->pluck('id')->all());
        $this->assertStringContainsString('aria-sort="descending"', $first->getContent());

        // 2 回目: 降順 → 昇順
        $second = $this->actingAs($user)->get($this->sortLinkFor($first->getContent(), '面積'));
        $second->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], $second->viewData('units')->pluck('id')->all());
        $this->assertStringContainsString('aria-sort="ascending"', $second->getContent());

        // 3 回目: 昇順 → 既定順
        $thirdUrl = $this->sortLinkFor($second->getContent(), '面積');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 巡目は並び替えを解除する');
        $third = $this->actingAs($user)->get($thirdUrl);
        $third->assertOk();
        $this->assertSame($default, $third->viewData('units')->pluck('id')->all());
    }

    /** 家賃と月額合計の見出しもそれぞれの列で並び替わる */
    public function test_the_rent_and_monthly_headers_sort_their_own_columns(): void
    {
        $property = $this->makeProperty();
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'rent' => 100000, 'common_fee' => 50000]);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'rent' => 300000, 'common_fee' => 0]);
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'rent' => 200000, 'common_fee' => 150000]);

        $user = $this->executive();
        $html = $this->actingAs($user)->get(route('tenant.units.index'))->getContent();

        // 家賃の降順: 300000 > 200000 > 100000
        $byRent = $this->actingAs($user)->get($this->sortLinkFor($html, '家賃'));
        $this->assertSame([$b->id, $c->id, $a->id], $byRent->viewData('units')->pluck('id')->all());

        // 月額合計の降順: c(353000) > b(303000) > a(153000)
        // ⚠ **家賃の順（b, c, a）とわざと食い違わせてある。**
        //   揃えると 'monthly' の式を 'rent' の式に変えても同じ順になり、変異が素通りする。
        //   実測: c の common_fee を 100000（= 合計 303000、b と同点）にすると
        //   monthly を units.rent に差し替えても順が変わらず、このテストは緑のまま通った。
        $byMonthly = $this->actingAs($user)->get($this->sortLinkFor($html, '月額合計'));
        $this->assertSame(
            [$c->id, $b->id, $a->id],
            $byMonthly->viewData('units')->pluck('id')->all(),
            '月額合計の降順になっていない（家賃の順と同じなら式を取り違えている）'
        );
        $this->assertSame(
            [353000, 303000, 153000],
            $byMonthly->viewData('units')->map(fn ($unit) => $unit->monthly_total)->all(),
            '月額合計の実値が想定と違う'
        );
    }

    /** 並び替え不可の列には矢印もリンクも出さない */
    public function test_non_sortable_headers_stay_plain(): void
    {
        $this->makeUnit($this->makeProperty(), '101');

        $html = $this->actingAs($this->executive())->get(route('tenant.units.index'))->getContent();

        $this->assertStringContainsString('>敷金</th>', $html, '敷金の見出しが素の <th> でなくなっている');
        $this->assertStringContainsString('>共益費</th>', $html);
    }

    /** 2 ページ目で見出しを押したら 1 ページ目へ戻る（設計書 §4.3-5） */
    public function test_clicking_a_header_from_page_two_returns_to_page_one(): void
    {
        $property = $this->makeProperty();
        for ($i = 1; $i <= 25; $i++) {
            $this->makeUnit($property, sprintf('%03d', $i), ['floor' => $i, 'rent' => $i * 10000]);
        }

        $user = $this->executive();

        $page1 = $this->actingAs($user)->get(route('tenant.units.index'));
        $page2 = $this->actingAs($user)->get($page1->viewData('units')->nextPageUrl());
        $page2->assertOk();
        $this->assertSame(2, $page2->viewData('units')->currentPage());

        $url = $this->sortLinkFor($page2->getContent(), '家賃');

        $this->assertStringNotContainsString('page=', $url, '見出しリンクが page を持ち越している');
        $this->assertSame(1, $this->actingAs($user)->get($url)->viewData('units')->currentPage());
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: FAIL — `「面積」の並び替えリンクが見つからない`

- [ ] **Step 3: コンポーネントを作る**

`resources/views/components/sortable-th.blade.php` を新規作成:

```blade
{{-- 並び替えできる列見出し（設計書 §4.2 / §5.6）

使い方:
  <x-sortable-th column="area" label="面積" :sort="$sort" align="right" link-style="padding: 14px 20px;" />
  <x-sortable-th column="occupancy" label="入居率" :sort="$sort" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />

props:
  column     … ?sort に載るキー（コントローラの許可リストと揃える）
  label      … 見出しの文字
  sort       … App\Support\ListSort|null（コントローラから渡す）
  align      … left | center | right（既定 center）。<th> の text-align と <a> の justify-content
  linkClass  … <a> に足すクラス。**パディングはここ**（Tailwind の responsive を使いたい画面用）
  linkStyle  … <a> に足す inline style。**パディングはここ**（inline style で組まれた画面用）

⚠ 属性式に &quot; を書かないこと。本番の view:cache でだけ 500 になる（Bug #21）。
⚠ パディングは <th> ではなく中の <a> に載せる。**見出しセル全体を押せるようにするため**で、
   <th> 側に残すと文字の上しか反応しない。HTML を見ても分からないので画面で確かめる（Bug #43）。
⚠ **<a> の中は「ラベル → 矢印」の順にする。** テストの sortLinkFor() が
   <a …> の直後にラベルが来ることを要求しているので、矢印を先に置くとリンクを見つけられない。
⚠ JS は 1 行も使わない。ただのリンク。
--}}
@props([
    'column',
    'label',
    'sort' => null,
    'align' => 'center',
    'linkClass' => '',
    'linkStyle' => '',
])
@php
    $state = \App\Support\ListSort::stateOf($sort, $column);

    $ariaSort = match ($state) {
        \App\Support\ListSort::ASC => 'ascending',
        \App\Support\ListSort::DESC => 'descending',
        default => 'none',
    };

    $justify = match ($align) {
        'left' => 'flex-start',
        'right' => 'flex-end',
        default => 'center',
    };

    $iconColor = $state === null ? '#D1D5DB' : '#059669';
    $labelColor = $state === null ? 'inherit' : '#047857';
@endphp
<th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap"
    style="padding: 0; text-align: {{ $align }};"
    aria-sort="{{ $ariaSort }}">
    <a href="{{ \App\Support\ListSort::url(request(), $column, $sort) }}"
       class="hover:bg-gray-100 transition-colors {{ $linkClass }}"
       style="display: flex; align-items: center; justify-content: {{ $justify }}; gap: 5px; text-decoration: none; cursor: pointer; user-select: none; color: {{ $labelColor }}; {{ $linkStyle }}">
        {{ $label }}
        <span style="flex-shrink: 0; width: 12px; height: 12px; color: {{ $iconColor }};">
            @if($state === \App\Support\ListSort::ASC)
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 15 12 8 18 15"/></svg>
            @elseif($state === \App\Support\ListSort::DESC)
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 16 18 9"/></svg>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>
            @endif
        </span>
    </a>
</th>
```

- [ ] **Step 4: 部屋一覧の見出し 3 本を差し替える**

`resources/views/tenant/units/index.blade.php` の `<thead>` 内で、次の 3 行をそれぞれ置き換える
（**既存の `text-align` をそのまま保つ**こと。面積は right、家賃・月額合計は center）。

置き換え前（114 行）:
```blade
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">面積</th>
```
置き換え後:
```blade
                            <x-sortable-th column="area" label="面積" :sort="$sort" align="right" link-style="padding: 14px 20px;" />
```

置き換え前（116 行）:
```blade
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">家賃</th>
```
置き換え後:
```blade
                            <x-sortable-th column="rent" label="家賃" :sort="$sort" align="center" link-style="padding: 14px 20px;" />
```

置き換え前（120 行）:
```blade
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">月額合計</th>
```
置き換え後:
```blade
                            <x-sortable-th column="monthly" label="月額合計" :sort="$sort" align="center" link-style="padding: 14px 20px;" />
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: PASS（12 tests）

- [ ] **Step 6: コンパイル済みビューを lint する**

⚠ `view:cache` は「成功」と表示してもコンパイル結果を lint しない（Bug #26 / #30）。
新しい Blade コンポーネントを足したので必ず実行する。

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 7: コミット**

```bash
git add resources/views/components/sortable-th.blade.php tests/Concerns/ParsesSortLinks.php resources/views/tenant/units/index.blade.php tests/Feature/Tenant/UnitListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 部屋一覧の面積・家賃・月額合計を見出しで並び替えられるようにする

見出しは JS を使わない素のリンクにし、既定 → 降順 → 昇順 → 既定 の 3 状態で
巡回させる。押したら 1 ページ目へ戻し、aria-sort で今の向きを伝える。

パディングを <th> から中の <a> へ移して見出しセル全体を押せるようにした。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 部屋一覧のフィルタに並び順を持ち回させる

**Files:**
- Modify: `resources/views/tenant/units/index.blade.php:20-21`（`<form id="filter-form">` の直後）
- Test: `tests/Feature/Tenant/UnitListSortTest.php`（追記）

⚠ 「クリア」ボタンは `route('tenant.units.index')` への素のリンクなので、**手を入れない**。
従来どおりフィルタも並び順も全部が初期化される（設計書 §4.3-4）。

- [ ] **Step 1: 失敗するテストを書く**

テストクラスの `use` に 1 行足す（Task 4 で足した `use Tests\Concerns\ParsesSortLinks;` の**上**）:

```php
use Tests\Concerns\ParsesForms;
```

クラス宣言直下のトレイト取り込みを次に変える:

```php
    use ParsesForms;
    use ParsesSortLinks;
    use RefreshDatabase;
```

クラスの末尾（閉じ括弧の直前）に追記:

```php
    /**
     * 並び替え中にフィルタを変えても並び順が消えない（設計書 §4.3-4）。
     *
     * ⚠ hidden があることを見るだけでは足りない。**画面が描画したフォームを解析して
     *   そのまま送り返す**（Bug #47）。フィルターフォームは GET なので
     *   fields をクエリ文字列に組み直して送る。
     * ⚠ **面積の昇順（b, a）と既定順（a, b）をわざと食い違わせてある。**
     *   揃えると sort / dir が落ちても同じ並びになり、往復の後半が飾りになる（実測済み）。
     */
    public function test_changing_a_filter_keeps_the_current_sort(): void
    {
        $property = $this->makeProperty();
        $a = $this->makeUnit($property, '101', ['floor' => 1, 'area_tsubo' => 30.00, 'status' => 'vacant']);
        $b = $this->makeUnit($property, '201', ['floor' => 2, 'area_tsubo' => 20.00, 'status' => 'vacant']);
        $c = $this->makeUnit($property, '301', ['floor' => 3, 'area_tsubo' => 10.00, 'status' => 'occupied']);

        $user = $this->executive();

        $html = $this->actingAs($user)
            ->get(route('tenant.units.index', ['sort' => 'area', 'dir' => 'asc']))
            ->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.units.index') . '"');

        $this->assertSame('area', $form['fields']['sort'] ?? null, 'フィルターフォームが sort を持ち回していない');
        $this->assertSame('asc', $form['fields']['dir'] ?? null, 'フィルターフォームが dir を持ち回していない');

        // ブラウザと同じように、ステータスだけ変えて送り返す
        $fields = $form['fields'];
        $fields['status'] = 'vacant';

        $response = $this->actingAs($user)->get($form['action'] . '?' . http_build_query($fields));

        $response->assertOk();
        $this->assertSame(
            [$b->id, $a->id],
            $response->viewData('units')->pluck('id')->all(),
            'フィルタを変えたら並び順が既定に戻った（面積の昇順のままであるべき）'
        );
        $this->assertNotContains($c->id, $response->viewData('units')->pluck('id')->all(), 'ステータス絞り込みが効いていない');
    }

    /** 並び替えていないときは余計な hidden を出さない */
    public function test_no_sort_hidden_fields_when_not_sorting(): void
    {
        $this->makeUnit($this->makeProperty(), '101');

        $html = $this->actingAs($this->executive())->get(route('tenant.units.index'))->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.units.index') . '"');

        $this->assertArrayNotHasKey('sort', $form['fields']);
        $this->assertArrayNotHasKey('dir', $form['fields']);
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=test_changing_a_filter_keeps_the_current_sort
```

Expected: FAIL — `フィルターフォームが sort を持ち回していない`

- [ ] **Step 3: hidden を足す**

`resources/views/tenant/units/index.blade.php` の `<form id="filter-form" ...>` の開始タグの
**直後**（`{{-- 上段: 物件フィルターチップ（常時表示） --}}` の前）に挿入:

```blade

            {{-- 並び替え中にフィルタを変えても並び順が消えないように持ち回す（設計書 §4.3-4）。
                 ⚠ 「クリア」は route(...) への素のリンクなので、従来どおり全部が初期化される。 --}}
            @if($sort)
                <input type="hidden" name="sort" value="{{ $sort->key }}">
                <input type="hidden" name="dir" value="{{ $sort->direction }}">
            @endif
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=UnitListSortTest
```

Expected: PASS（15 tests）

- [ ] **Step 5: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: 全て PASS

- [ ] **Step 6: コミット**

```bash
git add resources/views/tenant/units/index.blade.php tests/Feature/Tenant/UnitListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 部屋一覧でフィルタを変えても並び順が消えないようにする

フィルターフォームに sort / dir を hidden で持たせる。並び替えていないときは
出さない。「クリア」は素のリンクのままなので従来どおり全部を初期化する。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 物件一覧を全件並べ替えてからページを切る

**Files:**
- Modify: `app/Http/Controllers/Tenant/PropertyController.php:28-68`（`index()`）
- Test: `tests/Feature/Tenant/PropertyListSortTest.php`（新規）

⚠ **これが本件で一番壊れやすい箇所。** 「ページを切ってから並べ替える」実装は
1 ページ目だけを見ると正しく動いて見える（設計書 §3.1）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/PropertyListSortTest.php` を新規作成:

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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 物件一覧の並び替え（設計書 §5.3 / §6）。
 *
 * ⚠ 入居率・賃料収入は DB 列ではなく PropertyController::calculatePropertyStats が
 *   入れる派生値。**非稼働のときだけ null**（稼働は坪数 0 でも 0）なので、
 *   「値を持つ行＝稼働中・「—」の行＝非稼働」にきれいに分かれる。
 */
class PropertyListSortTest extends TestCase
{
    use ParsesForms;
    use RefreshDatabase;

    private ?Customer $customer = null;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 契約に要る顧客（1 件を使い回す） */
    private function customer(): Customer
    {
        return $this->customer ??= Customer::create([
            'code' => 'CUST-S001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
    }

    /**
     * 物件を 1 つ作る。入居率 = $contracted / $units、賃料収入 = $contracted × $rentEach。
     *
     * @param  int  $n  連番（code は T-S00n。**既定順は code 昇順**）
     */
    private function makeProperty(
        int $n,
        int $units = 1,
        int $contracted = 0,
        int $rentEach = 0,
        string $operationStatus = 'active',
    ): Property {
        $property = Property::create([
            'code' => sprintf('T-S%03d', $n),
            'name' => sprintf('並び替えビル%02d', $n),
            'property_type' => 'tenant',
            'department' => 'tenant',
            'operation_status' => $operationStatus,
            'address' => '愛媛県松山市本町1-1',
        ]);

        for ($u = 1; $u <= $units; $u++) {
            $unit = Unit::create([
                'property_id' => $property->id,
                'floor' => $u,
                'room_number' => sprintf('%d01', $u),
                'display_name' => sprintf('%d01', $u),
                'status' => $u <= $contracted ? 'occupied' : 'vacant',
                'area_tsubo' => 10.00,
                'rent' => 0,
                'common_fee' => 0,
                'garbage_fee' => 0,
                'pest_control_fee' => 0,
                'deposit' => 0,
            ]);

            if ($u <= $contracted) {
                Contract::create([
                    'contract_number' => sprintf('C-S%03d-%d', $n, $u),
                    'department' => 'tenant',
                    'property_id' => $property->id,
                    'unit_id' => $unit->id,
                    'customer_id' => $this->customer()->id,
                    'status' => 'active',
                    'contract_date' => '2026-04-01',
                    'rent_start_date' => '2026-04-01',
                    'rent' => $rentEach,
                    'common_fee' => 0,
                    'garbage_fee' => 0,
                    'pest_control_fee' => 0,
                ]);
            }
        }

        return $property;
    }

    /**
     * ページ送りのリンクを実際に辿って、全ページの物件 ID を順に集める。
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

            $paginator = $response->viewData('properties');
            foreach ($paginator as $property) {
                $ids[] = $property->id;
            }

            $url = $paginator->nextPageUrl();

            $this->assertLessThan(20, ++$guard, 'ページ送りが終わらない');
        }

        return $ids;
    }

    /** 並び替え指定が無ければ、今までと同じ順（稼働が先 → code 昇順） */
    public function test_the_default_order_is_unchanged(): void
    {
        $inactive = $this->makeProperty(3, operationStatus: 'inactive');
        $b = $this->makeProperty(2);
        $a = $this->makeProperty(1);

        $response = $this->actingAs($this->executive())->get(route('tenant.properties.index'));

        $response->assertOk();
        $this->assertSame(
            [$a->id, $b->id, $inactive->id],
            $response->viewData('properties')->pluck('id')->all()
        );
    }

    /** 不正な sort / dir は 500 にせず既定順へ落ちる */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 200000);
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 100000);

        $expected = [$a->id, $b->id];
        $user = $this->executive();

        foreach (['?sort=name', '?sort[]=income', '?sort=%3Cscript%3E', '?sort=', '?dir=up'] as $queryString) {
            $response = $this->actingAs($user)->get(route('tenant.properties.index') . $queryString);
            $response->assertOk();
            $this->assertSame(
                $expected,
                $response->viewData('properties')->pluck('id')->all(),
                "{$queryString} で既定順に落ちていない"
            );
        }
    }

    /**
     * 非稼働（画面では「—」）は昇順でも降順でも末尾。
     *
     * ⚠ **稼働を 3 棟にしてあるのは意図。** 2 棟だと既定順（code 昇順）が
     *   昇順か降順のどちらかと必ず一致してしまい、その向きが飾りになる（実測済み）。
     *   3 棟なら 既定 [1,2,3] / 降順 [2,1,3] / 昇順 [3,1,2] で全部食い違う。
     */
    public function test_inactive_properties_sort_last_in_both_directions(): void
    {
        $mid = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $high = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $low = $this->makeProperty(3, units: 1, contracted: 1, rentEach: 100000);
        $inactive = $this->makeProperty(4, operationStatus: 'inactive');

        $user = $this->executive();

        $desc = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame(
            [$high->id, $mid->id, $low->id, $inactive->id],
            $desc->viewData('properties')->pluck('id')->all()
        );
        $this->assertNull(
            $desc->viewData('properties')->firstWhere('id', $inactive->id)->rental_income,
            '非稼働物件に賃料収入が入っている（「—」にならない）'
        );

        $asc = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'asc']));
        $this->assertSame(
            [$low->id, $mid->id, $high->id, $inactive->id],
            $asc->viewData('properties')->pluck('id')->all()
        );
    }

    /**
     * 入居率と賃料収入は別々の列として並ぶ（列の取り違えを検出する）。
     *
     * ⚠ **3 つの並びを全部食い違わせてある。** 既定 [1,2,3] / 入居率降順 [2,1,3] /
     *   賃料収入降順 [3,1,2]。どれか 2 つが揃うと、列を取り違えた実装でも緑になる。
     *   実測: 賃料収入降順を既定順と同じにすると、その半分が飾りになった。
     */
    public function test_occupancy_and_income_sort_by_different_columns(): void
    {
        // 入居率 50% / 賃料収入 200,000円（どちらも真ん中）
        $mid = $this->makeProperty(1, units: 2, contracted: 1, rentEach: 200000);
        // 入居率 100%（最大） / 賃料収入 20,000円（最小）
        $fullOccupancy = $this->makeProperty(2, units: 2, contracted: 2, rentEach: 10000);
        // 入居率 25%（最小） / 賃料収入 300,000円（最大）
        $topIncome = $this->makeProperty(3, units: 4, contracted: 1, rentEach: 300000);

        $user = $this->executive();

        $byOccupancy = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'occupancy', 'dir' => 'desc']));
        $this->assertSame(
            [$fullOccupancy->id, $mid->id, $topIncome->id],
            $byOccupancy->viewData('properties')->pluck('id')->all(),
            '入居率の降順になっていない'
        );

        $byIncome = $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame(
            [$topIncome->id, $mid->id, $fullOccupancy->id],
            $byIncome->viewData('properties')->pluck('id')->all(),
            '賃料収入の降順になっていない（入居率と同じ並びなら列を取り違えている）'
        );
    }

    /**
     * 同点の中は既定順（code 昇順）。
     *
     * ⚠ **作成順（id 昇順）と code 順がわざと食い違うようにする。**
     *   揃えると第 2 キーを消しても同じ並びになり、変異が素通りする。
     * ⚠ 実データでも 16 件中 14 件が入居率 0.0% / 賃料収入 0 円で同点（設計書 §2.1）。
     */
    public function test_tied_properties_keep_the_default_order(): void
    {
        $c = $this->makeProperty(3);
        $a = $this->makeProperty(1);
        $b = $this->makeProperty(2);

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            Property::orderBy('id')->pluck('id')->all(),
            'id 順と既定順が食い違うデータになっていない（変異が検出できなくなる）'
        );

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']));

        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $response->viewData('properties')->pluck('id')->all(),
            '同点の中が既定順（code 昇順）になっていない'
        );
    }

    /**
     * ページをまたいでも行が重複せず・消えず、後のページに大きい値が現れない。
     *
     * ⚠ **これが本件の中心。** 1 ページ目の 20 件が降順に並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（設計書 §3.1）。
     */
    public function test_paging_through_a_sorted_list_never_shows_a_larger_value_on_a_later_page(): void
    {
        $incomeById = [];

        // code の昇順（＝既定順）と賃料収入の降順がわざと逆向きになるようにする
        for ($i = 1; $i <= 25; $i++) {
            $property = $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
            $incomeById[$property->id] = $i * 10000;
        }

        $ids = $this->collectIdsAcrossPages(
            $this->executive(),
            route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc'])
        );

        $this->assertCount(25, $ids, 'ページ送りで行が消えている');
        $this->assertCount(25, array_unique($ids), 'ページ送りで行が重複している');
        $this->assertEqualsCanonicalizing(Property::pluck('id')->all(), $ids);

        $incomes = array_map(fn ($id) => $incomeById[$id], $ids);
        $sorted = $incomes;
        rsort($sorted);

        $this->assertSame($sorted, $incomes, 'ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）');
        $this->assertSame(250000, $incomes[0], '最大の賃料収入が 1 ページ目の先頭に来ていない');
        $this->assertSame(50000, $incomes[20], '2 ページ目の先頭が 1 ページ目の最小を下回っていない');
    }

    /**
     * 全件取得にしても物件 1 件あたりのクエリが増えないこと（N+1 の検出）。
     *
     * ⚠ 絶対本数ではなく「件数を増やしても本数が変わらないこと」を見る。
     *   本数を決め打ちすると、無関係な変更で落ちる脆いテストになる。
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_properties(): void
    {
        $user = $this->executive();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        for ($i = 1; $i <= 5; $i++) {
            $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
        }
        $queries = 0;
        $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))->assertOk();
        $withFive = $queries;

        for ($i = 6; $i <= 25; $i++) {
            $this->makeProperty($i, units: 1, contracted: 1, rentEach: $i * 10000);
        }
        $queries = 0;
        $this->actingAs($user)->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))->assertOk();
        $withTwentyFive = $queries;

        $this->assertSame(
            $withFive,
            $withTwentyFive,
            "物件が増えるとクエリが増える（N+1）: 5 件で {$withFive} 本 / 25 件で {$withTwentyFive} 本"
        );
    }
}
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=PropertyListSortTest
```

Expected: FAIL — `test_paging_through_a_sorted_list_never_shows_a_larger_value_on_a_later_page` などが
`Failed asserting that two arrays are identical.`（並び替えが未実装）

- [ ] **Step 3: `PropertyController::index()` を書き換える**

冒頭の `use` に 3 行足す（既存の並びに合わせてアルファベット順に挿入する）:

```php
use App\Support\ListSort;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
```

`index()` の**直前**（クラスの先頭、`public function index` の上）に定数を置く:

```php
    /**
     * 物件一覧で並び替えを許す列 → calculatePropertyStats() が入れる属性の名前。
     *
     * ⚠ **許可リストはここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡す。
     *   三項演算子で書くと、キーを足したときに**黙って賃料収入で並んでしまう**
     *   （落ちないので画面から気づけない）。
     */
    private const SORTABLE = [
        'occupancy' => 'occupancy_rate',
        'income' => 'rental_income',
    ];
```

`index()` の 1 行目（`$query = Property::where(...)` の**前**）に足す:

```php
        $sort = ListSort::fromRequest($request, array_keys(self::SORTABLE));

```

既存の並び順・ページング・計算ループの 9 行:

```php
        $properties = $query->orderBy('operation_status', 'asc')  // 稼働が先
                            ->orderBy('code', 'asc')
                            ->paginate(20)
                            ->withQueryString();

        // 各物件の入居率・賃料収入を計算（Eager Loadedデータを使用、追加クエリなし）
        foreach ($properties as $property) {
            $this->calculatePropertyStats($property);
        }
```

を次に置き換える:

```php
        // ⚠ 並べ替えの対象は「全件」。ページを切ってから並べると 1 ページ目の中だけで
        //   並んで答えが変わる（設計書 §3.1）。入居率・賃料収入は PHP にしか計算が無いので
        //   ここで全件に計算してから並べる。eager load は据え置きなのでクエリ本数は増えない。
        $all = $query->orderBy('operation_status', 'asc')  // 稼働が先
                     ->orderBy('code', 'asc')
                     ->get();

        // 各物件の入居率・賃料収入を計算（Eager Loadedデータを使用、追加クエリなし）
        foreach ($all as $property) {
            $this->calculatePropertyStats($property);
        }

        $properties = $this->paginateCollection(
            $request,
            $sort !== null ? $this->sortProperties($all, $sort) : $all,
            20
        );
```

`return` 行を次に変える:

```php
        return view('tenant.properties.index', compact('properties', 'sort'));
```

`index()` の**直後**に private メソッドを 2 つ足す:

```php
    /**
     * 入居率・賃料収入で並べ替える（設計書 §5.3）。
     *
     * ⚠ calculatePropertyStats() は**非稼働のときだけ null** を入れる（稼働は坪数 0 でも 0）。
     *   よって「値を持つ行＝稼働中・null の行＝非稼働」に分かれ、null を末尾へ回す処理が
     *   既定順の「稼働が先」とも矛盾しない。末尾の中は code 昇順＝既定順。
     *
     * ⚠ 同点の第 2 キーに code を**明示指定する**。PHP のソートが安定であることに
     *   暗黙に頼らない。これが無いとページをまたいで行が重複・消失する（設計書 §4.3-3）。
     */
    private function sortProperties(Collection $properties, ListSort $sort): Collection
    {
        $field = self::SORTABLE[$sort->key];

        $withValue = $properties->filter(fn (Property $p) => $p->{$field} !== null);
        $withoutValue = $properties->filter(fn (Property $p) => $p->{$field} === null);

        return $withValue
            ->sortBy([[$field, $sort->isAscending() ? 'asc' : 'desc'], ['code', 'asc']])
            ->concat($withoutValue->sortBy('code'))
            ->values();
    }

    /**
     * 並べ替え済みのコレクションを手でページングする。
     *
     * ⚠ ->links() には戻さない。既存ビューはインライン番号付きページネーション
     *   （hasPages / getUrlRange / nextPageUrl）を使っている（Bug #24 以来の規約）。
     *   LengthAwarePaginator を自分で組めばそのまま動く。
     *
     * ⚠ **withQueryString() は使わない。** null 値のキーは http_build_query が丸ごと捨てるため、
     *   `?operation_status=` のような空の絞り込みがページ送りリンクから消える（Bug #31）。
     *   AreaBuildingListService / ProcurementListService と同じ形にする。
     *   ⚠ 見出しリンク側（ListSort::url()）も同じ正規化をしている。**片方だけ変えないこと。**
     *
     * @param  Collection<int, Property>  $items
     */
    private function paginateCollection(Request $request, Collection $items, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => array_map(fn ($value) => $value ?? '', $request->query()),
            ]
        );
    }
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=PropertyListSortTest
```

Expected: PASS（8 tests）

- [ ] **Step 5: 既存テストが壊れていないことを確認する**

```bash
vendor/bin/phpunit
```

Expected: 全て PASS

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Tenant/PropertyController.php tests/Feature/Tenant/PropertyListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 物件一覧を入居率・賃料収入で全件並べ替えてからページを切る

入居率と賃料収入は PHP にしか計算が無いので、SQL へ写さず全件を取得して
計算してから PHP で並べ、LengthAwarePaginator を手で組んでページを切る。
SQL へ写すと表示と並び順が別実装になり、画面から気づけない食い違いを生む。

非稼働（画面では「—」）は昇順でも降順でも末尾へ回し、同点は code 昇順で
固定してページをまたいだ重複・消失を防ぐ。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 物件一覧の見出し差し替え ＋ フィルタに並び順を持ち回させる

**Files:**
- Modify: `resources/views/tenant/properties/index.blade.php:68,69`（入居率 / 賃料収入の `<th>`）
- Modify: `resources/views/tenant/properties/index.blade.php:27-28`（`<form id="filter-form">` の直後）
- Test: `tests/Feature/Tenant/PropertyListSortTest.php`（追記）

⚠ 物件一覧の `<th>` は Tailwind の**レスポンシブなパディング**（`px-4 py-3 lg:px-5 lg:py-3.5`）を
使っている。inline style へ書き換えるとブレークポイントが失われるので、**`link-class` で渡す**。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/PropertyListSortTest.php` の `use` に 1 行足す（`use Tests\TestCase;` の**上**）:

```php
use Tests\Concerns\ParsesSortLinks;
```

クラス宣言直下のトレイト取り込みを次に変える:

```php
    use ParsesForms;
    use ParsesSortLinks;
    use RefreshDatabase;
```

クラスの末尾（閉じ括弧の直前）に追記:

```php
    /**
     * 見出しを 3 回押す往復。既定 → 降順 → 昇順 → 既定。
     *
     * ⚠ 賃料収入の昇順と既定順（code 昇順）が**わざと食い違う**データにしてある。
     *   一致させると 2 回目と 3 回目の結果が同じになり、片方が壊れても緑になる。
     */
    public function test_clicking_the_income_header_three_times_cycles_back_to_the_default_order(): void
    {
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $c = $this->makeProperty(3, units: 1, contracted: 1, rentEach: 100000);

        $user = $this->executive();
        $default = [$a->id, $b->id, $c->id];

        // 1 回目: 既定 → 降順
        $html = $this->actingAs($user)->get(route('tenant.properties.index'))->getContent();
        $first = $this->actingAs($user)->get($this->sortLinkFor($html, '賃料収入'));
        $first->assertOk();
        $this->assertSame([$b->id, $a->id, $c->id], $first->viewData('properties')->pluck('id')->all());
        $this->assertSame('descending', $this->ariaSortFor($first->getContent(), '賃料収入'));

        // 2 回目: 降順 → 昇順
        $second = $this->actingAs($user)->get($this->sortLinkFor($first->getContent(), '賃料収入'));
        $second->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], $second->viewData('properties')->pluck('id')->all());
        $this->assertSame('ascending', $this->ariaSortFor($second->getContent(), '賃料収入'));

        // 3 回目: 昇順 → 既定順
        $thirdUrl = $this->sortLinkFor($second->getContent(), '賃料収入');
        $this->assertStringNotContainsString('sort=', $thirdUrl, '3 巡目は並び替えを解除する');
        $third = $this->actingAs($user)->get($thirdUrl);
        $third->assertOk();
        $this->assertSame($default, $third->viewData('properties')->pluck('id')->all());
    }

    /** 入居率の見出しも自分の列で並び替わる */
    public function test_the_occupancy_header_sorts_by_occupancy(): void
    {
        $half = $this->makeProperty(1, units: 2, contracted: 1, rentEach: 100000);  // 50%
        $full = $this->makeProperty(2, units: 2, contracted: 2, rentEach: 10000);   // 100%
        $none = $this->makeProperty(3, units: 2, contracted: 0);                    // 0%

        $user = $this->executive();
        $html = $this->actingAs($user)->get(route('tenant.properties.index'))->getContent();

        $response = $this->actingAs($user)->get($this->sortLinkFor($html, '入居率'));

        $response->assertOk();
        $this->assertSame([$full->id, $half->id, $none->id], $response->viewData('properties')->pluck('id')->all());
    }

    /** 並び替え不可の列には矢印もリンクも出さない */
    public function test_non_sortable_headers_stay_plain(): void
    {
        $this->makeProperty(1);

        $html = $this->actingAs($this->executive())->get(route('tenant.properties.index'))->getContent();

        $this->assertStringContainsString('>所有者</th>', $html, '所有者の見出しが素の <th> でなくなっている');
        $this->assertStringContainsString('>稼働</th>', $html);
    }

    /**
     * aria-sort が**並び替え中の列だけ**に載ること、パディングが <a> 側にあること。
     *
     * ⚠ Task 4（部屋一覧）で、ページ全体を見る assertStringContainsString だけだと
     *   **3 列全部に descending を出す変異が緑のまま通る**ことを実測した。物件一覧にも同じ網を張る。
     * ⚠ **物件一覧は `link-class` を使う最初の画面**（部屋一覧は `link-style`）。
     *   この経路が黙って効かないと <th> も <a> も padding 0 で見出しが潰れるので、
     *   レスポンシブなパディングが本当に <a> へ載ったかを見る。
     */
    public function test_only_the_sorted_column_is_marked_and_the_padding_sits_on_the_link(): void
    {
        $this->makeProperty(1, units: 1, contracted: 1, rentEach: 100000);

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))
            ->getContent();

        $this->assertSame('descending', $this->ariaSortFor($html, '賃料収入'));
        $this->assertSame('none', $this->ariaSortFor($html, '入居率'), '並び替えていない列に aria-sort が載っている');
        $this->assertSame('none', $this->ariaSortFor($html, '所有者'), '並び替え不可の列に aria-sort が載っている');

        // ⚠ link-class は物件一覧が最初の利用者。効いていなければ見出しが潰れる
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="[^"]*px-4 py-3 lg:px-5 lg:py-3\.5/u',
            $html,
            'link-class のレスポンシブなパディングが <a> に載っていない'
        );
        $this->assertMatchesRegularExpression(
            '/<th\b[^>]*style="padding: 0;/u',
            $html,
            '見出しセルのパディングが 0 になっていない'
        );
    }

    /**
     * 並び替え中にフィルタを変えても並び順が消えない（設計書 §4.3-4）。
     *
     * ⚠ hidden があることを見るだけでは足りない。**画面が描画したフォームを解析して
     *   そのまま送り返す**（Bug #47）。フィルターフォームは GET なので
     *   fields をクエリ文字列に組み直して送る。
     */
    public function test_changing_a_filter_keeps_the_current_sort(): void
    {
        $a = $this->makeProperty(1, units: 1, contracted: 1, rentEach: 200000);
        $b = $this->makeProperty(2, units: 1, contracted: 1, rentEach: 300000);
        $inactive = $this->makeProperty(3, operationStatus: 'inactive');

        $user = $this->executive();

        $html = $this->actingAs($user)
            ->get(route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc']))
            ->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.properties.index') . '"');

        $this->assertSame('income', $form['fields']['sort'] ?? null, 'フィルターフォームが sort を持ち回していない');
        $this->assertSame('desc', $form['fields']['dir'] ?? null, 'フィルターフォームが dir を持ち回していない');

        // ブラウザと同じように、稼働状態だけ変えて送り返す
        $fields = $form['fields'];
        $fields['operation_status'] = 'active';

        $response = $this->actingAs($user)->get($form['action'] . '?' . http_build_query($fields));

        $response->assertOk();
        $this->assertSame(
            [$b->id, $a->id],
            $response->viewData('properties')->pluck('id')->all(),
            'フィルタを変えたら並び順が既定に戻った（賃料収入の降順のままであるべき）'
        );
        $this->assertNotContains($inactive->id, $response->viewData('properties')->pluck('id')->all());
    }

    /** 並び替えていないときは余計な hidden を出さない */
    public function test_no_sort_hidden_fields_when_not_sorting(): void
    {
        $this->makeProperty(1);

        $html = $this->actingAs($this->executive())->get(route('tenant.properties.index'))->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.properties.index') . '"');

        $this->assertArrayNotHasKey('sort', $form['fields']);
        $this->assertArrayNotHasKey('dir', $form['fields']);
    }
```

- [ ] **Step 2: テストが失敗することを確認する**

```bash
vendor/bin/phpunit --filter=PropertyListSortTest
```

Expected: FAIL — `「賃料収入」の並び替えリンクが見つからない`

- [ ] **Step 3: 見出し 2 本を差し替える**

`resources/views/tenant/properties/index.blade.php` の `<thead>` 内で置き換える。

置き換え前（68 行）:
```blade
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">入居率</th>
```
置き換え後:
```blade
                            <x-sortable-th column="occupancy" label="入居率" :sort="$sort" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
```

置き換え前（69 行）:
```blade
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
```
置き換え後:
```blade
                            <x-sortable-th column="income" label="賃料収入" :sort="$sort" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
```

- [ ] **Step 4: フィルタに hidden を足す**

`<form id="filter-form" ...>` の開始タグの**直後**（`<select name="operation_status" ...>` の前）に挿入:

```blade

        <x-sort-hidden :sort="$sort" />
```

⚠ **hidden を直書きしないこと。** Task 5 で `resources/views/components/sort-hidden.blade.php` に
切り出してあります（同じ 7 行を 2 画面へ逐語コピーすると片方だけ直す事故が起きる。Bug #41 / #46）。
⚠ `tests/Feature/SortableListWiringTest.php` が **`<x-sortable-th` を持つビューは必ず
`<x-sort-hidden` も持つこと**を全件走査で検査しています。忘れると赤になります。

- [ ] **Step 5: テストが通ることを確認する**

```bash
vendor/bin/phpunit --filter=PropertyListSortTest
```

Expected: PASS（14 tests）

- [ ] **Step 6: コンパイル済みビューを lint する**

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 7: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: 全て PASS

- [ ] **Step 8: コミット**

```bash
git add resources/views/tenant/properties/index.blade.php tests/Feature/Tenant/PropertyListSortTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 物件一覧の入居率・賃料収入を見出しで並び替えられるようにする

部屋一覧と同じ 3 状態のリンク見出しにし、フィルターフォームへ sort / dir を
hidden で持たせる。<th> のレスポンシブなパディングは link-class 経由で
そのまま <a> へ渡し、見出しセル全体を押せるようにする。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: 変異テストで「テストが本当に守っているか」を測る

**Files:** なし（測定のみ。プランの表に結果を書き戻す）

⚠ **「テストが緑」は検証にならない。** 変異を入れて赤になることを実測する（Bug #42 / #44 / #45）。

### 作法（Bug #44。1 つでも省くと測定が無効になる）

1. **先にコミットする。** 未コミットのまま変異を当てて `git checkout --` すると**自分の実装ごと巻き戻る**
2. 各変異の**前**に `git status --porcelain` が**空**であることを確認する（前の変異の残骸で測定が汚れる）
3. 変異を当てたら `git diff --stat` が**非空**であることを確認する（当たっていない変異を「検出しない」と誤読する事故が実際に起きている）
4. 赤／緑だけでなく**落ちた理由の文言**を突き合わせる（別の機構が落としている可能性を排除する）
5. `git checkout -- <対象ファイル>` で戻す

⚠ **シェル経由の置換（`sed` / `perl`）はエスケープが合わず 0 箇所置換でも成功に見える。**
エディタで編集し、必ず 3 の `git diff --stat` で着弾を確認すること。

- [ ] **Step 1: 作業ツリーが清浄であることを確認する**

```bash
git status --porcelain && echo "--- 空なら OK ---"
```

### Task 1 の時点で既に実測済み（再測不要。結果をそのまま採用する）

| 変異 | 実測（2026-08-25） |
|---|---|
| #3 `sortProperties()` から `['code', 'asc']`（第 2 キー）を消す | **緑**（予告どおり）。PHP 8 の `uasort` は安定で、`$all` が SQL 側で既に `code` 昇順なので第 2 キーが無くても同じ並びになる。**冗長だが、将来 `$all` の取得順が変わったときの保険＋意図の記録として残す** |
| #13 `! is_string($key)` を消す | **緑**（予測どおり）。`in_array(['area'], $allowed, true)` が false を返すのでガードが無くても null に落ちる。ガードは純粋な二重防御であり、`test_array_sort_does_not_explode` が実際に守っているのは `in_array` のほう |
| #15 `array_map(fn ($v) => $v ?? '', $query)` を `$query` に戻す | **赤**。`ListSortTest::test_url_keeps_a_null_filter_by_normalising_it_to_an_empty_string` が `Failed asserting that '...?sort=occupancy&dir=desc' contains "operation_status="` で落ちる |
| （追加）`Arr::query(...)` を素の `http_build_query(...)` に替える | **緑**。`Arr::query()` は `http_build_query($array, '', '&', PHP_QUERY_RFC3986)` の薄いラッパーで（`vendor/laravel/framework/.../Arr.php:939-942`）、配列の展開ロジックは同一。差は RFC3986 と RFC1738 のエンコード（スペースの扱い等）だけで、テストデータにスペースが無いため差が出なかった |

⚠ **`test_url_keeps_an_array_filter` は上記 2 通りの変異では落ちない**ことが実測で分かっている。
これは**特性テスト（現状の固定）**であって、検出力があるという主張はしないこと。
配列の往復がどこにも書かれていない暗黙の前提だったので置いてある。

- [ ] **Step 2: 27 通りの変異を 1 つずつ当てて測る**

各行について「変異を当てる → `git diff --stat` で着弾確認 → 該当テストを走らせる → 文言を控える → `git checkout --`」を繰り返す。

| # | 変異 | 対象 | 期待 |
|---|---|---|---|
| 1 | 並べ替えを**ページを切った後**に移す（下記コード） | `PropertyController::index` | `PropertyListSortTest::test_paging_through_a_sorted_list_never_shows_a_larger_value_on_a_later_page` が赤。文言「ページをまたいで降順になっていない（1 ページ目の中だけで並んでいる）」 |
| 2 | 既定順の 3 行 `->orderBy('units.property_id')...` を消す | `UnitController::applySort` | `UnitListSortTest::test_tied_rows_keep_the_default_order` が赤。文言「同点の中が既定順になっていない」 |
| 3 | `['code', 'asc']` を消す | `PropertyController::sortProperties` | ⚠ **緑になる見込み。** PHP 8 の sort は安定で、`$all` が既定順で来るので第 2 キーが無くても同じ並びになる。**実測して結果を記録する**（緑なら「冗長だが将来 `$all` の取得順が変わったときの保険＋意図の記録」として残す。Bug #54 ⑤ と同型） |
| 4 | `$query->orderByRaw("({$expression} IS NULL) asc")` の行を消す | `UnitController::applySort` | `UnitListSortTest::test_units_without_an_area_sort_last_in_both_directions` が赤。⚠ **降順は偶然通る**（SQLite は NULL を最小として扱うので降順なら末尾）。**昇順のアサートだけが落ちる**ので、両方向を見ているのが load-bearing |
| 5 | `sortProperties` の null 分離（`$withValue` / `$withoutValue`）をやめて全件 `sortBy` にする | `PropertyController::sortProperties` | `PropertyListSortTest::test_inactive_properties_sort_last_in_both_directions` が赤。⚠ #4 と同じく**昇順だけ**落ちる |
| 5b | `sortProperties()` の判定を `!== null` / `=== null` から truthy（`(bool) $p->{$field}` / `! $p->{$field}`）へ変える | `PropertyController::sortProperties` | **実測済み（Task 6）: 赤。** `test_an_active_property_with_zero_income_is_not_pushed_to_the_end` の**昇順側**が「0 円の稼働物件が末尾へ飛んでいる」で落ちる。⚠ **降順は素通りする**（0 は元々最後に来るので末尾へ飛ばしても同じ並び）＝ 昇順・降順の**両方**を見ているのが load-bearing。⚠ このテストを足すまでは 987 テスト全部が緑だった |
| 6 | `'rent' => ['COALESCE(units.rent, 0)', false]` を `['units.rent', true]` に変える | `UnitController::applySort` | `UnitListSortTest::test_units_with_a_null_rent_sort_as_zero_not_last` が赤。文言「NULL の家賃が 0 として先頭に来ていない（末尾へ飛ばしている）」 |
| 7 | `href="{{ \App\Support\ListSort::url(...) }}"` を `href="{{ url()->current() }}"` に変える | `components/sortable-th.blade.php` | `UnitListSortTest::test_clicking_the_area_header_three_times_cycles_back_to_the_default_order` と `PropertyListSortTest::test_clicking_the_income_header_...` が赤 |
| 7b | `$ariaSort` の算出を `match ($state)` から `match ($sort?->direction)` に変える（＝ 並び替え中はどの列も `descending` になる） | `components/sortable-th.blade.php` | `UnitListSortTest::test_only_the_sorted_column_is_marked_and_the_padding_sits_on_the_link` が赤。文言「並び替えていない列に aria-sort が載っている」。⚠ **ページ全体を見る `assertStringContainsString('aria-sort="descending"')` だけでは緑のまま通る**（Task 4 のレビューで実測） |
| 7c | `<th>` の `padding: 0` を `padding: 14px 20px` に戻し、`<a>` 側の `{{ $linkStyle }}` を消す | `components/sortable-th.blade.php` | 同テストが赤。文言「見出しセルのパディングが 0 になっていない（`<a>` 側へ移していない）」。⚠ 当たり判定そのものは HTML では測れないが（Bug #43）、**どちらに載っているか**は測れる |
| 8 | `<x-sort-hidden :sort="$sort" />` の行を消す | `tenant/units/index.blade.php` | **実測済み（Task 5）: 赤。** `SortableListWiringTest` が「index.blade.php は並び替え見出しを持つのに、並び順を持ち回す hidden が無い」で落ちる |
| 8c | `sort-hidden.blade.php` の `value="{{ $sort->direction }}"` を `value="asc"` に固定する | `components/sort-hidden.blade.php` | **実測済み（Task 5）: 赤。** 文言「フィルターフォームが dir=desc を持ち回していない」。⚠ **往復が 1 組（area/asc）だけのときは緑だった。** この変異が本番で起きると「降順で見ていた利用者がフィルタを触った瞬間に昇順へ静かに反転する」 |
| 8d | 同じく `value="{{ $sort->key }}"` を `value="area"` に固定する | `components/sort-hidden.blade.php` | **実測済み（Task 5）: 赤。** 文言「フィルターフォームが sort=rent を持ち回していない」 |
| 8e | 「クリア」の `href` を `route('tenant.units.index', ['sort' => 'area'])` にする | `tenant/units/index.blade.php` | **実測済み（Task 5）: 赤。** ⚠ **素の `assertStringContainsString('href="…"')` では緑だった** — サイドバーの「部屋一覧」が同じ bare URL を指すため（Bug #47 の「同じ URL を指す要素が 2 つある」）。`sortLinkFor()` で「クリア」ラベルに絞って初めて落ちる |
| 8b | 往復の送信フィールドから `sort` / `dir` を落とす（`unset($fields['sort'], $fields['dir'])`） | `UnitListSortTest` の往復テスト | `test_changing_a_filter_keeps_the_current_sort` が赤。⚠ **面積の昇順と既定順を食い違わせるまでは緑だった**（旧データで実測）。往復の後半が飾りになっていないことの証明 |
| 9 | `<x-sort-hidden :sort="$sort" />` の行を物件一覧から消す | `tenant/properties/index.blade.php` | `SortableListWiringTest` が赤（走査は全件分類なので物件一覧も自動で対象） |
| 9b | `calculatePropertyStats()` の `$property->contracts` を `$property->contracts()->where('status', ContractStatus::Active)->get()` に変える（**値は不変・クエリだけ N 本になる**） | `PropertyController` | **実測済み（Task 6 レビュー）: N+1 テストだけが赤・他 7 本は緑**。文言「物件が増えるとクエリが増える（N+1）: 5 件で 8 本 / 25 件で 28 本」。⚠ `->with([...])` を丸ごと消す変異は契約の絞り込みまで変わって**別の理由で赤**になるので測定として濁る |
| 9c | 物件一覧の「クリア」の `href` を `route('tenant.properties.index', ['sort' => 'income', 'dir' => 'desc'])` にする | `tenant/properties/index.blade.php` | **実測済み（Task 7）: 赤。** `PropertyListSortTest::test_the_clear_link_drops_the_sort` が「クリアがクエリ付きのリンクになっている」で落ちる。⚠ **足すまでは 994 テスト全部が緑だった。** サイドバーが同じ bare URL を **3 箇所**で出すので、素の href 一致では**確実に**飾りになる |
| 9d | 見出しの `align="center"` を `align="left"` にする | `tenant/properties/index.blade.php` | **実測済み（Task 7）: 赤。** ⚠ 足すまでは 15 本全緑だった。⚠ **部屋一覧側のアサートは `text-align: right;`** で書くこと — 面積は right で、`center` にすると**家賃・月額合計の `<th>` に一致して**面積列を一切見なくなる（実測） |
| 10 | `MONTHLY_TOTAL_SQL` から `+ COALESCE(units.pest_control_fee, 0)` を落とす | `app/Models/Unit.php` | `UnitListSortTest::test_the_monthly_total_sql_agrees_with_the_php_accessor` が赤 |
| 11 | `MONTHLY_TOTAL_SQL` の `units.rent` を `units.rentt` に綴り間違える | `app/Models/Unit.php` | 赤。⚠ **理由が違う**ことに注意 — Bug #40 の「SQLite が黙って 0 を返す」は**二重引用符で囲まれた識別子**（`->sum('col')` 経由）の話で、`selectRaw` / `orderByRaw` に素で書いた識別子は `no such column` で**例外**になる。**文言を必ず確認する。** 併せて `assertGreaterThan(1, ...)` と `assertContains(315000, ...)` の 2 行を外しても赤のままかを実測し、**その 2 行が load-bearing かどうかを記録する** |
| 12 | `! in_array($key, $allowed, true)` を消す（`! is_string($key)` だけ残す） | `app/Support/ListSort.php` | `UnitListSortTest::test_invalid_sort_parameters_fall_back_to_the_default_order` が赤（`?sort=name` が `self::SORTABLE['name']` で `Undefined array key` → 500） |
| 12b | `self::SORTABLE` の `'monthly'` の式を `Unit::MONTHLY_TOTAL_SQL` から `'COALESCE(units.rent, 0)'` に変える | `UnitController` | `UnitListSortTest::test_the_rent_and_monthly_headers_sort_their_own_columns` が赤。⚠ **定数そのものでなく「定数が並び替えに使われているか」を測る唯一の変異。** #10 / #11 は定数を直接評価するテストが拾うだけで、この経路は無検査だった（Task 2 のレビューで発覚） |
| 13 | `! is_string($key)` を消す（`in_array` だけ残す） | `app/Support/ListSort.php` | ⚠ **緑になる見込み。** `in_array(['area'], $allowed, true)` は false を返すので、ガードが無くても null へ落ちる。**実測して記録する**（緑なら「守っているのは `in_array` のほうで、`is_string` は型の保証として残す」と書く） |
| 14 | `unset($query['page']);` を消す | `app/Support/ListSort.php` | `ListSortTest::test_url_drops_the_page_parameter` と `UnitListSortTest::test_clicking_a_header_from_page_two_returns_to_page_one` が赤 |
| 15 | `array_map(fn ($value) => $value ?? '', $query)` を `$query` に戻す | `app/Support/ListSort.php` | `ListSortTest::test_url_keeps_a_null_filter_by_normalising_it_to_an_empty_string` が赤（Bug #31） |
| 16 | `$request->query('dir') === self::ASC ? self::ASC : self::DESC` の三項を反転して既定を `asc` にする | `app/Support/ListSort.php` | `ListSortTest::test_direction_defaults_to_descending` と 2 つの 3 状態往復テストが赤 |

変異 #1 のコード（`index()` の `$properties = $this->paginateCollection(...)` を丸ごと置き換える）:

```php
        // ⚠ 変異: ページを切ってから並べ替える（JS 案と同じ壊れ方の再現）
        $properties = $this->paginateCollection($request, $all, 20);
        if ($sort !== null) {
            $properties->setCollection($this->sortProperties($properties->getCollection(), $sort));
        }
        $properties = $properties->withQueryString();
```

- [ ] **Step 3: 結果をこのプランに書き戻す**

上の表の「期待」欄の横に**実測**（赤／緑・落ちたテスト名・文言）を追記する。
⚠ **「緑になる見込み」と書いた #3 #13 が本当に緑なら、そう書く。** 思い込みでなく実測を残す。
⚠ **赤になるはずの変異が緑だったら、そこがテストの穴。** テストを足してから先へ進む。

- [ ] **Step 4: 作業ツリーが清浄に戻っていることを確認する**

```bash
git status --porcelain && echo "--- 空なら OK（変異が残っていない） ---"
```

- [ ] **Step 5: 全テストを走らせる**

```bash
vendor/bin/phpunit
```

Expected: 全て PASS

- [ ] **Step 6: プランの更新をコミット**

```bash
git add docs/superpowers/plans/2026-08-25-tenant-list-sorting.md
git commit -m "$(cat <<'EOF'
docs(plan): 並び替えの変異テスト 16 通りの実測結果を書き戻す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: 画面での確認（テストでは原理的に測れないもの）

**Files:** なし（確認のみ）

⚠ 以下は**HTML に出ているかでは判定できない**（Bug #43 / #26 / #30）。

- [ ] **Step 1: コンパイル済みビューを lint する**

`view:cache` は「成功」と表示してもコンパイル結果を lint しない。

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```

Expected: `INVALID:` が 1 行も出ない

- [ ] **Step 2: ローカルで CSS をビルドする**

見出しに `hover:bg-gray-100` `transition-colors` を使う。ローカルで見た目を見るならビルドが要る
（本番は `./deploy.sh` が自動でビルドする）。worktree には `node_modules` が無いので main repo のものを使う。

```bash
/Users/masanori/site/manage/node_modules/.bin/vite build
```

- [ ] **Step 3: ブラウザで 6 点を確認する**

`/tenant/units` と `/tenant/properties` を開いて確認する。

⚠ **4 と 5 は、コード側が「Task 9 で見る」と明示的に約束している項目**。
ここに無いと「テストは別の場所で見ると言い、その別の場所が存在しない」状態になる（Bug #28 / #43 と同型）。

1. **見出しセル全体が押せること** — 文字の上だけでなく、`<th>` の余白部分を押しても並び替わる。
   ⚠ `title` と同じで **HTML では検証できない**（Bug #43）。実際にセルの端をクリックするか、
   `document.elementFromPoint(x, y)` から祖先を辿って `<a>` に到達するかを見る
2. **部屋一覧を横スクロールした状態でも「月額合計」の見出しが押せること**（12 列あり画面外に出る）
3. **3 状態が見た目で区別できること** — 未使用は薄い上下矢印（⇅）、降順は ▼、昇順は ▲。
   並び替え中の列だけ文字と矢印が緑になる
4. **物件チップを押しても並び順が維持されること**（部屋一覧）。
   ⚠ `UnitListSortTest::test_changing_a_filter_keeps_the_current_sort` の docblock が
   「この経路は未カバー。ブラウザでの確認に回す（Task 9）」と明記している。
   Alpine の `x-model` が実行時に `checked` を立てるため `parseForm()` が拾えず、
   **PHP の Feature テストからは原理的に測れない**。
   `<x-sort-hidden>` が Alpine 管理下のフォームでも送信されることの**唯一の確認手段**
5. **見出しに Tab でフォーカスし、緑のリングが「上辺も含めて」見えること**（両画面）。
   部屋一覧は**横スクロールした状態でも**見ること。
   ⚠ `resources/css/app.css` の `.sortable-th-link:focus-visible` は
   「検証は『HTML にクラスが出ているか』では不可能。実ブラウザで Tab して目視すること」と書いている。
   ⚠ `outline-offset: -2px` は「`overflow-x: auto` のコンテナで上辺が切れる」という
   **推測に対する未検証の対策**。実際に切れるのか・-2px で足りるのかを誰も見ていない
6. **入居率の見出しが列からはみ出していないこと**（物件一覧）。
   ⚠ 矢印で +17px（gap 5px + icon 12px）増えたので、`table-layout: fixed` の 13% 列に対し
   **viewport 375px と 1024px の 2 幅で約 2px 不足**する試算がある（未実測）。
   直すなら `link-class` を `px-3 py-3 lg:px-4 lg:py-3.5` にするか `<col>` を 13%→14% に振る

- [ ] **Step 4: 確認結果を記録する**

分かったことを `docs/BACKLOG.md` に追記する（本番反映後に本節を仕上げる）。

---

## Task 10: 本番へ反映する

⚠ **`./deploy.sh` はユーザーの明示的な承認が要る。** 承認なしに実行しない。

- [ ] **Step 1: main repo へ FF マージする**

```bash
git checkout 13.x && git merge --ff-only tenant-list-sorting
```

- [ ] **Step 2: オートローダを作り直す**

新規 PHP クラス（`App\Support\ListSort`）を足したので、classmap を正確に保つために実行する。

⚠ **「忘れたら本番が落ちる」ではない**（2026-08-26 実測）。`composer.json` は
`"optimize-autoloader": true` だが **`setClassMapAuthoritative` は設定されていない**ので、
`ClassLoader::findFile()` は classmap ミス時に **PSR-4 へフォールバック**する。
忘れても `App\Support\ListSort` は解決される。それでも実行するのは house convention として。

⚠ **必ず main repo を cwd にして実行する。** worktree から実行すると autoloader の
`$baseDir` に worktree のパスが焼き込まれ、main repo の Apache が worktree を参照する事故になる。

```bash
cd /Users/masanori/site/manage && composer dump-autoload
```

- [ ] **Step 3: ユーザーに本番デプロイの可否を確認する**

`AskUserQuestion` で明示的に確認してから次へ進む。

- [ ] **Step 4: デプロイする**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

⚠ DB 変更は無いので SQL の実行は不要。`view:cache` の再生成は `deploy.sh` が行う。

- [ ] **Step 5: 本番で動作を確認する**

⚠ 本番 URL は `/index.php/` prefix が要る（`.../manage/tenant/units` は 302 で流れる）。
Playwright は未ログインで止まるので、実 Chrome（claude-in-chrome）の既存セッションを使う。

確認する 3 点:
1. `/tenant/units` で「家賃」見出しを押す → 全 156 件で最も高い家賃が 1 ページ目の先頭に来る
2. その状態でステータス絞り込みを変える → 並び順が維持される
3. `/tenant/properties` で「賃料収入」を 3 回押す → 既定順（稼働が先 → コード順）に戻る

- [ ] **Step 6: worktree を片付ける**

```bash
cd /Users/masanori/site/manage && git worktree remove .claude/worktrees/tenant-list-sorting && git branch -d tenant-list-sorting
```

---

## 自己レビュー（プラン作成後の確認）

### 1. 設計書のカバレッジ

| 設計書 | 対応する Task |
|---|---|
| §3.2 物件は PHP・部屋は SQL | Task 6 / Task 3 |
| §4.1 URL 仕様（許可リスト・不正値は既定順） | Task 1（`fromRequest`）＋ Task 3 / 6 のテスト |
| §4.2 3 状態（1 回目は降順） | Task 1（`next`）＋ Task 4 / 7 の往復テスト |
| §4.3-1 既定順は現状のまま | Task 3 / 6 の `test_the_default_order_is_unchanged` |
| §4.3-2 「—」は末尾 | Task 3（面積）／ Task 6（非稼働） |
| §4.3-3 同点は既定順 | Task 3 / 6 の `test_tied_*` ＋ ページ送りテスト |
| §4.3-4 フィルタと共存 | Task 5 / Task 7 |
| §4.3-5 押したら 1 ページ目 | Task 1（`url` が page を落とす）＋ Task 4 の `..._from_page_two_returns_to_page_one` |
| §4.4 列ごとの「—」の扱い | Task 3（`applySort` の match）＋ #3' と #10 のテスト |
| §4.4 既知の穴（`area_tsubo = 0`） | Task 3 の `test_a_zero_area_is_not_pushed_to_the_end` |
| §5.2 `ListSort` の API | Task 1 |
| §5.5 二重実装の塞ぎ方 | Task 2 |
| §5.6 見出しコンポーネントの注意点 | Task 4 |
| §6.1 の #1〜#10 | #1 Task 6 ／ #2 Task 3・6 ／ #3 Task 3・6 ／ #3' Task 3 ／ #4 Task 4・7 ／ #5 Task 5・7 ／ #6 Task 2 ／ #7 Task 3・6 ／ #8 Task 3・6 ／ #9 Task 6 ／ #10 Task 3 |
| §6.3 変異テスト | Task 8 |
| §6.5 画面での確認 | Task 9 |
| §9 本番反映 | Task 10 |

**穴なし。** 設計書のスコープ外（§8）に手を出している Task も無い。

### 2. プレースホルダ

「TODO」「あとで」「適宜」「同様に」の類は無い。コードを変える全ステップに実コードを載せてある。

### 3. 型・名前の一貫性

| 名前 | 定義 | 使う場所 |
|---|---|---|
| `ListSort::ASC` / `::DESC` | Task 1 | Task 3 / 4 / 6 |
| `ListSort::fromRequest(Request, array): ?self` | Task 1 | Task 3（`['area','rent','monthly']`）/ Task 6（`['occupancy','income']`） |
| `ListSort::next(?self, string): ?string` | Task 1 | `url()` の内部 |
| `ListSort::stateOf(?self, string): ?string` | Task 1 | Task 4 のコンポーネント |
| `ListSort::url(Request, string, ?self): string` | Task 1 | Task 4 のコンポーネント |
| `->key` / `->direction` / `->isAscending()` | Task 1 | Task 3 / 4 / 5 / 6 / 7 |
| `Unit::MONTHLY_TOTAL_SQL` | Task 2 | Task 3 の `applySort` |
| `UnitController::applySort(Builder, ?ListSort): void` | Task 3 | Task 3 の `index()` |
| `PropertyController::sortProperties(Collection, ListSort): Collection` | Task 6 | Task 6 の `index()` |
| `PropertyController::paginateCollection(Request, Collection, int): LengthAwarePaginator` | Task 6 | Task 6 の `index()` |
| `$sort`（ビュー変数） | Task 3 / 6 の `compact()` | Task 4 / 5 / 7 の Blade |
| `ParsesSortLinks::sortLinkFor(string, string): string` | Task 4 | Task 4 / 7 |
| `x-sortable-th` の props（`column` `label` `sort` `align` `linkClass` `linkStyle`） | Task 4 | Task 4（`link-style`）/ Task 7（`link-class`） |

**食い違いなし。**

### 4. 実測で分かっている「緑になる見込み」の変異

Task 8 の #3 と #13 は**赤にならない見込み**で、それを承知で表に入れてある
（「守っているつもりだったが守っていなかった」を発見するのが変異テストの目的なので、
緑だった事実を記録することに価値がある。Bug #54 ⑤ と同じ扱い）。

### 5. プラン作成時に実測したこと（推測で書いていない箇所の裏取り）

| 確認したこと | 結果 |
|---|---|
| プラン内の PHP コード 30 ブロック（完全ファイル 5・断片 25）の構文 | `php -l` で**全部 clean**（断片は class / function で包んで検査） |
| `x-sortable-th` コンポーネントの Blade コンパイル | `BladeCompiler::compileString()` が成功し、生成された PHP も `php -l` clean（3,101 bytes） |
| コンポーネントの使い方コメントに書いた `<x-sortable-th …>` が展開されないか（**Bug #30**） | **安全。** `BladeCompiler::compileString()` は `compileComponentTags($this->compileComments($value))` の順で、**Blade コメントが先に落ちる**（`BladeCompiler.php:288-290`）。compiled 出力に `<x-` の残留 **0 個**。既存の `components/form-actions.blade.php` が同じ形で本番稼働中 |
| `LengthAwarePaginator::__construct` の引数 | `($items, $total, $perPage, $currentPage, $options)` ＝ Task 6 の呼び出しと一致 |
| `resolveCurrentPage` / `setCollection` / `getCollection` / `withQueryString` / `getUrlRange` / `nextPageUrl` / `forPage` / `concat` | **全て存在** |
| `Collection::sortBy([[key, dir], …])` の配列形式 | `sortByMany()` へ委譲され `uasort` で比較（`Collection.php:1586-1665`）。⚠ **`uasort` は PHP 8 で安定**なので、入力が既定順なら第 2 キーが無くても同じ並びになる ＝ Task 8 #3 が緑になる見込みの根拠 |
| Bug #40（SQLite が未知カラムを 0 で返す）の適用範囲 | **二重引用符で囲まれた識別子**（`->sum('col')` 経由）に限る。`selectRaw` / `orderByRaw` に素で書いた `units.rentt` は `no such column` で**例外**になる ＝ Task 8 #11 の「理由が違う」の根拠 |
| `ORDER BY (col IS NULL) asc, col desc/asc` の挙動 | MySQL・SQLite で**同一**（設計書 §2.3 で両エンジン実測済み） |
| `Arr::query(['a'=>null,'b'=>'','c'=>'x'])` | **`b=&c=x`** ＝ null のキーが消える（Bug #31。Task 1 の正規化の根拠） |
| `number_format(null)` | `'0'` を返す（PHP 8.3 では Deprecated 警告つき）＝ 家賃 NULL が画面で `0円` になる根拠 |
| `units` の実スキーマ | `area_tsubo` `rent` `common_fee` `garbage_fee` `pest_control_fee` `floor` すべて **nullable**（金額 4 種は既定値 `0`）。migration も同じなので SQLite テストで NULL を作れる |
| 実データの NULL | 面積・家賃とも **0 件** ＝ テストで作らないと NULL 経路は空振りする |
| `$sort` という変数名 | `resources/views/tenant/` と `app/Http/Controllers/Tenant/` のどこでも未使用 ＝ 衝突なし（Bug #53） |
