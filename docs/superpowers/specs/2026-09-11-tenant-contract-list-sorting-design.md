# テナント契約一覧 契約日の既定順と見出しの並び替え — 設計書

作成日: 2026-09-11
対象: `/tenant/contracts`（テナント契約一覧）
前例: @docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md（物件一覧・部屋一覧。URL 仕様・3 状態・約束ごと）
　　　@docs/superpowers/specs/2026-08-28-area-building-sorting-design.md（周辺ビル調査。見出しの意匠・並び順バー）

---

## 1. 背景と依頼

利用者からの依頼:

> テナント管理の「契約一覧」画面ですが契約日順に初期表示を変更して下さい。
> そしてテーブルにも契約日を追加して下さい。

対話で確定したこと（2026-09-11）:

| 論点 | 決定 |
|---|---|
| 並びの向き | **新しい順（降順）** |
| 契約日列の位置 | **先頭（左端）** —— 並び順の基準が一番左に来る |
| 見出しでの並び替え | **入れる**（案 2）。対象は **契約日 / 物件 / 区画 / 賃料収入** の 3 列 |

---

## 2. 調査で分かった事実（2026-09-11 実測）

### 2.1 現状と経緯

- 現在の既定順は **物件名 → 階数 → 号室**（`ContractController::index()` 63〜69 行）
- **初期実装は「契約日の新しい順」だった**（`orderByDesc('contract_date')->orderByDesc('id')`）。
  2026-05-11 の `cd8759cf` で「スクロール時に同物件内で階数がバラバラ（2B, 2A, 3AB, 1A 等）で見づらい」
  という理由で今の順に変わった。**今回は既定を契約日へ戻し、旧既定は「物件 / 区画」見出しの
  1 回目で呼び出せるようにする**（§4.3）
- 同日の `455e93af` で契約番号列を削除して 5 列になった
- この一覧を検証する Feature テストは **0 本**（`ContractDeletionTest` は削除後のリダイレクト先として踏むだけ）

### 2.2 データの性質

- **契約日は全経路で必須** —— 登録・編集は `required|date`、CSV 取込も必須キー
  （`TenantImportController`）。migration も `date` NOT NULL
- テナント画面の日付は **`Y/m/d`** で出している（契約詳細・区画詳細・物件詳細・顧客詳細）
- 賃料収入列の値は `Contract::getMonthlyTotalAttribute()` ＝ 家賃 ＋ 共益費 ＋ ゴミ代 ＋ 駆除代
  （null は 0）。共益費・ゴミ代・駆除代は **nullable・既定 0**
- `units.floor` は **nullable**（平屋型）。`units.room_number` は `varchar(20)`
- ローカル DB のテナント契約は **10 件**（すべて契約中・同じ契約日の重複 0・2006-09-13〜2025-03-05）。
  ⚠ **同点・解約済み・null の経路は実データでは空振りする**ので、テストで意図的に作る

### 2.3 現在の並びは一意でない

同じ区画に**旧契約（解約済み）と新契約**がある場合、物件名・階数・号室の 3 キーがすべて同点になる。
MySQL は同点の行の順序を保証しないので、「ステータス: すべて」でページをまたぐと
**同じ契約が 2 ページに出たり、どのページにも出なかったりしうる**。
今回、最後のキーに契約 ID を足して並びを一意にする（§4.4）。

### 2.4 共通部品の前提

- `ListSort::next()` は **「1 回目は必ず降順」**（金額と率なので多い順を先に見たい、という前例の判断）
- `ListSort::stateOf()` は **並び替え指定が無ければどの列も null**（＝初期表示ではどの見出しにも矢印が点かない）
- 前例の 3 画面はいずれも**既定順の列が並び替え対象に無い**（物件一覧＝稼働中が先・コード順、
  部屋一覧＝物件・階・部屋番号順、周辺ビル調査＝ビル名順）ので、この 2 つの前提で困らなかった
- `SortableListWiringTest` と `SortBarTest` は並び替え画面を**全件分類**している（Bug #45）。
  4 画面目は登録しないとテストが落ちる

---

## 3. 方式の決定

### 3.1 却下: 共通部品を変えずに載せる

- **契約日**: 既定が降順なので、**1 回目（降順）を押しても並びが変わらない**。
  しかも初期表示では契約日で並んでいるのに見出しは ⇅ のまま（`aria-sort="none"`＝読み上げにも嘘をつく）
- **物件 / 区画**: 1 回目が物件名の**逆順**になり、旧既定（昇順）まで 2 回押す必要がある

### 3.2 採用: 列ごとに「1 回目の向き」と「既定順の列か」を指定できるようにする

`ListSort` の `stateOf()` / `next()` / `url()` に**省略可能な引数**を足す（§5）。
**省略時は従来と 1 行も変わらない**ので、物件一覧・部屋一覧・周辺ビル調査の挙動は不変
（既存テストがそのまま通ることで確かめる）。

---

## 4. 設計① 画面の挙動

### 4.1 列の構成

| # | 見出し | 並び替え | セルの中身 |
|---|---|---|---|
| 1 | **契約日** | ○（既定順の列） | `2025/03/05`（`Y/m/d`） |
| 2 | 物件 / 区画 | ○（1 回目は昇順） | 現状どおり |
| 3 | 店舗名 | — | 現状どおり（未入力は「—」） |
| 4 | 賃料収入 | ○ | 現状どおり（家賃発生日未設定の ⚠ も含む） |
| 5 | 状態 | — | 現状どおり |
| 6 | 操作 | — | 現状どおり |

### 4.2 URL

```
/tenant/contracts?sort=contract_date&dir=asc
/tenant/contracts?sort=income&dir=desc&status=all&property_id=3
```

`sort` は `contract_date` / `property_unit` / `income`。`dir` は `asc` / `desc`。
**不正な `sort` は既定順に落とす**（500 にしない。前例 §4.1 と同じ）。
不正な `dir`（`up` など）は**降順**として扱う（`ListSort::fromRequest()` の既存の仕様。変えない）。
`income` は物件一覧の「賃料収入」と同じキー名にそろえる。

### 4.3 見出しを押したときの切り替わり

| 列 | 切り替わり |
|---|---|
| 契約日 | 既定（**▼ 新しい順が点灯**）→ 古い順 ▲ → 既定 |
| 物件 / 区画 | 既定 → **昇順 ▲**（物件名 → 階数 → 号室。5/11〜9/11 の既定）→ 降順 ▼ → 既定 |
| 賃料収入 | 既定 → 多い順 ▼ → 少ない順 ▲ → 既定（前例の金額列と同じ） |

- **他の列で並び替え中に「契約日」を押すと既定へ戻る**（URL に `sort` を載せない）。
  既定と同じ並びを「契約日 新しい順」という別の状態として作らない
- 手入力の `?sort=contract_date&dir=desc` は既定と同じ並びになる。押すと古い順へ進む（正規化はしない）

### 4.4 並びの定義

**既定順**:

```
contracts.contract_date DESC → properties.name → units.floor → units.room_number → contracts.id DESC
```

**並び替え中**: 指定した列の後ろに**既定順を丸ごと**付ける（同点は既定順。前例 §4.3-3）。

| キー | ORDER BY（`<dir>` は asc / desc） |
|---|---|
| `contract_date` | `contracts.contract_date <dir>` |
| `property_unit` | `properties.name <dir>, units.floor <dir>, units.room_number <dir>` —— **3 キーとも同じ向き**（降順は全体の逆順） |
| `income` | `Contract::MONTHLY_TOTAL_SQL <dir>`（`COALESCE` 済みの 4 列の和） |

- **「—」を出す並び替え列は無い**（契約日・物件 / 区画・賃料収入はどれも必ず値が出る）。
  よって前例 §4.4 の「—は末尾」の処理は要らない
- ⚠ `units.floor` の null（平屋型）は MySQL・SQLite とも**昇順で先頭・降順で末尾**。
  画面に「—」は出ないので末尾送りはしない（旧既定と同じ挙動）
- ⚠ `properties.name` の順序は本番 MySQL の照合順序（`utf8mb4_unicode_ci`）に従い、
  テストの SQLite はバイト順。**テストの物件名は両者で順序が一致するものを使う**
- 最後のキー `contracts.id` で並びが一意になり、§2.3 の重複・欠落が起きなくなる

### 4.5 約束ごと（前例 §4.3 と同じ）

1. **サーバ側で全件を並べてからページを切る**（1 ページ目の中だけで並ぶ壊れ方を原理的に排除）
2. **同点は既定順**
3. **並び替えとフィルタは共存する** —— フィルターフォームに `<x-sort-hidden>` を置く
4. **見出しを押したら 1 ページ目へ**（`ListSort::url()` が `page` を落とす）
5. **「解除」は並び順だけ、「クリア」は全部**を初期化する

### 4.6 並び順バー

フィルターバーの下・表の上に `<x-sort-bar>` を置く。`default-label="契約日の新しい順"` →
初期表示は「**並び替え: 既定（契約日の新しい順）**」。
バーの「向きの言い方」は `SORT_COLUMNS` から引く（§6.2）。

### 4.7 列幅

6 列になるので `colgroup` と表の最小幅（今は `min-w-[640px]`）を見直す。
**値は実ブラウザで測って決める**（§8）。判定基準:

- 375 / 1200 / 1800px のどれでも、**どのセルも中身が枠からはみ出さない**
  （`td` / `th` の `scrollWidth <= clientWidth`。`table-layout: fixed` ＋ `nowrap` なので、
  はみ出すと隣の列に文字が重なる）
- 画面全体が横スクロールしない（`main.scrollWidth === main.clientWidth`。Bug #29）

---

## 5. 設計② `ListSort` の拡張

```php
/**
 * @param  string  $first      その列を初めて押したときの向き（省略時 DESC ＝ 従来どおり）
 * @param  bool    $isDefault  その列が画面の既定順か。既定順は「その列の $first 向き」とみなす
 */
public static function stateOf(?self $current, string $key, string $first = self::DESC, bool $isDefault = false): ?string
{
    if ($current === null) {
        return $isDefault ? $first : null;      // 既定順の列は、並び替え指定が無くても点灯する
    }

    return $current->key === $key ? $current->direction : null;
}

public static function next(?self $current, string $key, string $first = self::DESC, bool $isDefault = false): ?string
{
    $state = self::stateOf($current, $key, $first, $isDefault);

    if ($state === null) {
        return $isDefault ? null : $first;      // 既定順の列は「既定へ戻す」＝ sort を載せない
    }

    return $state === $first ? self::opposite($first) : null;
}

public static function url(Request $request, string $key, ?self $current, string $first = self::DESC, bool $isDefault = false): string
```

**省略時の挙動が従来と同一であること**（`$first = DESC` / `$isDefault = false`）:

| 現在 | 従来の `next()` | 新しい `next()`（省略時） |
|---|---|---|
| 並び替え無し | DESC | state null → `$first` ＝ DESC |
| 別の列 | DESC | state null → DESC |
| この列の降順 | ASC | state DESC ＝ `$first` → ASC |
| この列の昇順 | null | state ASC ≠ `$first` → null |

- `$first` が `asc` / `desc` 以外なら **`InvalidArgumentException`**（黙って変な周期で回るより、
  配線テストで 500 として見つかるほうが良い。`x-sortable-th` の column 打ち間違いと同じ方針）
- `$isDefault` の列の既定の向きは `$first` と同じとみなす。**既定順の列に「既定と逆向きの 1 回目」は
  定義しない**（そうすると既定から押した先が既定と同じになり、周期が壊れる）

---

## 6. 設計③ 実装の構造

### 6.1 触るファイル（新規 1 / 変更 8 ＋ 文書）

| ファイル | 変更 |
|---|---|
| `app/Support/ListSort.php` | §5 の拡張 ＋ docblock の更新（「1 回目は必ず降順」の記述を列ごとへ） |
| `resources/views/components/sortable-th.blade.php` | `$columns[$column]` から `first` / `default` を読んで ListSort に渡す |
| `app/Http/Controllers/Tenant/ContractController.php` | `SORT_COLUMNS` ＋ `fromRequest()` ＋ `applySort()`（既定順を含む）＋ view へ `sort` / `sortColumns` |
| `app/Models/Contract.php` | `MONTHLY_TOTAL_SQL`（`Unit::MONTHLY_TOTAL_SQL` と同じ形） |
| `resources/views/tenant/contracts/index.blade.php` | 契約日列・見出し 3 本・hidden・バー・colgroup・最小幅・空行の colspan |
| `tests/Feature/Tenant/ContractListSortTest.php` | **新規**（§7.1） |
| `tests/Unit/Support/ListSortTest.php` | 拡張分（§7.2） |
| `tests/Feature/SortableListWiringTest.php` | 4 画面目の登録 ＋ `SORT_COLUMNS` の任意キーの形（§7.3） |
| `tests/Feature/Tenant/SortBarTest.php` | 4 画面目の登録 ＋ 既定順の文言と並びの対（§7.3） |
| `docs/BACKLOG.md` | 完了の記録 |

**DB 変更・ルート変更・新規クラスはいずれも無し。**

### 6.2 `SORT_COLUMNS`（ContractController）

```php
public const SORT_COLUMNS = [
    // 既定順の列。初期表示が新しい順なので、押すと古い順 → もう一度で既定（2 状態）
    'contract_date' => ['label' => '契約日',      'desc' => '新しい順', 'asc' => '古い順',   'default' => true],
    // 1 回目は昇順（物件名 → 階数 → 号室 ＝ 2026-05-11〜09-11 の既定順）
    'property_unit' => ['label' => '物件 / 区画', 'desc' => '降順',     'asc' => '昇順',     'first' => ListSort::ASC],
    // 金額なので 1 回目は多い順（物件一覧の「賃料収入」と同じ言い方）
    'income'        => ['label' => '賃料収入',    'desc' => '多い順',   'asc' => '少ない順'],
];
```

- **許可リスト・ラベル・向きの言い方・周期の指定をここ 1 箇所に集める**（前例 §7.1。Bug #41 / #46）
- `first` / `default` を読むのは `x-sortable-th` だけ。コントローラの並べ替えは `match` で書く
  （3 列とも形が違う: 1 列 / 3 列 / 式）

### 6.3 `x-sortable-th`

```php
$spec      = $columns[$column];
$first     = $spec['first'] ?? \App\Support\ListSort::DESC;
$isDefault = $spec['default'] ?? false;
$state     = \App\Support\ListSort::stateOf($sort, $column, $first, $isDefault);
// href は \App\Support\ListSort::url(request(), $column, $sort, $first, $isDefault)
```

### 6.4 並び順を実行する場所

`ContractController::index()` の JOIN はそのまま残し、`orderBy` の塊を `applySort()` に切り出す
（`UnitController::applySort()` と同じ形）。`match` に `default` アームは書かない
（キーは `fromRequest()` の許可リストを通ったものしか来ない。書き漏れは配線テストが 500 で拾う）。

---

## 7. テスト計画

### 7.1 新規 `ContractListSortTest`

| 守ること | 書き方の要点 |
|---|---|
| 既定順が契約日の新しい順 | **物件名の順と契約日の順が逆になる**データで（旧既定に戻す変異を確実に赤くする） |
| 同じ契約日の中は 物件名 → 階数 → 号室 → 契約 ID の新しい順 | 同日の契約を複数の物件・階・号室で作る。**同じ区画の旧契約と新契約**（ID だけが違う）も入れる |
| 契約日が各行の**先頭セル**に `Y/m/d` で出る | ページ全体の文字列一致ではなく**行ごとに先頭の `<td>` を見る**（別の場所の文字に一致する false-pass を防ぐ） |
| 初期表示で契約日の見出しだけが `aria-sort="descending"` | 列ごとに切り出して見る（`ParsesSortLinks::ariaSortFor()`） |
| 契約日: 既定 → 古い順 → 既定 | **描画された href を辿る**（URL を自分で組み立てると、リンクが壊れていても緑になる。Bug #47） |
| 物件 / 区画: 既定 → 昇順 → 降順 → 既定 | 1 回目が**昇順**であること・降順は 3 キーとも逆になること |
| 賃料収入: 既定 → 多い順 → 少ない順 → 既定 | 同額の行は既定順 |
| 他の列で並び替え中に契約日を押すと既定へ | href に `sort` が無いこと |
| `MONTHLY_TOTAL_SQL` とアクセサが一致する | 共益費などが **null** の行を含める |
| ページ送りで全件がちょうど 1 回ずつ出る | 11 件以上・同点を含む。`nextPageUrl()` を辿る（前例 Bug #31 の教訓） |
| 並び替え中にフィルタを変えても並び順が残る | フィルターフォームを解析して送り返す（`ParsesForms`） |
| 2 ページ目から見出しを押すと 1 ページ目へ | 描画された href に `page` が無いこと |
| 並び替えない見出し（店舗名・状態・操作）は素の `<th>` | リンクも矢印も無い |
| 不正な `sort` は既定順 ／ 不正な `dir` は降順 | `?sort[]=x` / `?sort=<script>` は既定順、`?sort=income&dir=up` は多い順 |
| 絞り込みは並び替え中も効く | `status` / `property_id` / `keyword` |

### 7.2 `ListSortTest`（Unit）

- `first = asc` の周期（null → asc → desc → null）
- 既定順の列の周期（既定で点灯 → 逆向き → null ／ 別の列から押すと null）
- `url()` が `first` / `isDefault` を反映する
- `$first` が不正なら例外
- **既存テストは 1 本も書き換えない**（省略時の挙動が不変であることの証明になる）

### 7.3 配線テスト・バーのテスト

- `SortableListWiringTest`: `SORT_COLUMN_SOURCES` / `SORT_ENDPOINTS` に契約一覧を登録、クラス数 3 → 4、
  1 行作る seed に契約を足す。**`SORT_COLUMNS` の任意キーの形**も固定する
  （`first` は asc / desc のみ・`default` は true のみ・既定順の列は画面に高々 1 本・
  既知のキー以外は置かない＝`frist` のような打ち間違いが黙って無視されるのを防ぐ）
- `SortBarTest`: `test_each_list_names_its_own_default_order` の表に 4 画面目を足す ＋
  **既定順の文言と実際の並びを対で見る**テストを足す（前例 §6 の ⚠）

### 7.4 変異テスト（実施は必須）

「テストが緑」は検証にならない。少なくとも次を当てて**赤になることと、落ちた理由の文言**を確かめる
（Bug #44 の作法: 先にコミット → 変異ごとに `git status --porcelain` が空 → `git diff --stat` で着弾確認 → 戻す）:

1. 既定順を旧既定（物件名 → 階数 → 号室）に戻す
2. 既定順の契約日を昇順にする
3. 同日内の物件名キーを消す
4. 最後の `contracts.id DESC` を消す
5. `property_unit` の降順で 3 キー目以降を昇順のまま残す
6. `income` の式から 1 列落とす
7. 契約日のセルを消す ／ 2 列目へ動かす ／ 書式を `Y-m-d` にする
8. `SORT_COLUMNS` から `'default' => true` を消す ／ `'first' => ListSort::ASC` を消す
9. `ListSort::next()` の既定順の列の分岐を消す ／ `stateOf()` の既定点灯を消す
10. `x-sortable-th` が `first` / `default` を渡さない
11. `<x-sort-hidden>` を消す
12. バーの `default-label` を別の文言にする

---

## 8. ブラウザでの確認（テストでは原理的に測れないもの）

使い捨て SQLite ＋ `artisan serve`（worktree 内。main repo の実 DB には触れない）で:

1. 375 / 1200 / 1800px で `main.scrollWidth === main.clientWidth`（Bug #29）と、
   **全 `td` / `th` で `scrollWidth <= clientWidth`**（§4.7）。これで列幅と最小幅を確定する
2. 見出しを**実際にクリック**して並びが変わる（3 列 × 周期ひと回り）
3. 初期表示で契約日の見出しが緑の ▼（`aria-sort="descending"`）、他の並び替え列は灰色の ⇅
4. 見出しに Tab でフォーカスしたときのリング（`sortable-th-link:focus-visible`）が上下で切れない
5. 並び替え中の列にホバーしても緑の下線が保たれる（周辺ビル調査で踏んだ詳細度の罠）
6. コンソール出力 0 件

⚠ ローカルで CSS を載せるには、main repo の `node_modules/.bin/vite build` を cwd=worktree で実行する
（worktree には `public/build` も `node_modules` も無い）。

---

## 9. やらないこと

- 店舗名・状態の並び替え（依頼の対象外。店舗名は漢字が読み順にならない）
- 解約済みの絞り込み時に基準日を解約日へ切り替えること
- 手入力の `?sort=contract_date&dir=desc` を既定へ正規化すること
- 1 ページ 10 件・キーワード欄（プレースホルダは「店舗名で検索」だが契約番号も検索している）など、依頼と無関係な箇所
- 前例の 3 画面の周期の変更（省略時の挙動は不変）

---

## 10. 本番反映

DB 変更・ルート変更・新規 PHP クラスが無いので、`composer dump-autoload` は不要。

```
git checkout 13.x && git merge --ff-only contract-list-sorting
./deploy.sh
```

⚠ `deploy.sh` はユーザーの明示承認を得てから流す。
⚠ デプロイ後、本番のコンパイル済みビューを `php -l` し（Bug #21 / #26）、
ログイン済みの実 Chrome で `/system/manage/index.php/tenant/contracts` を開いて並びと見出しを目視する。
