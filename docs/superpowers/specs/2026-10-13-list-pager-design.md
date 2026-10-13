# 一覧のページ番号を「今のページの前後だけ」にする — 設計書

作成日: 2026-10-13
対象:
- 新しい部品: `resources/views/components/pager.blade.php`（`<x-pager>`）
- 番号の決め方: `app/Support/Approval/PageNumbers.php` → `app/Support/PageNumbers.php`（移すだけ・中身は変えない）
- ページ番号を全部並べている一覧 31 本（基幹 29 本・決裁 2 本）
- 決裁のページ送りの包み: `resources/views/approvals/_pager.blade.php`
- 決裁の 3 つのコントローラ（`NoticeController`・`AdminRequestController`・`LedgerController`）と、`_pager` を読み込む 3 つの画面

前例: 決裁 段階2b の ⑩「決裁済み・否決」のページ送り（Task 9 の B1・利用者の決定 C5）。
　　　Bug #24（`->links()` を使わずインラインの番号付き）・#31（`?status=` の null とページ送り）・#47（描画したリンクをたどる往復）・
　　　#100（大きなページ番号の 500）・#114〜#116（狭い画面の崩れ・走査の作り）・Top trap #13（走査は全件分類）

---

## 1. 背景と依頼

ページ番号を 1 から最後まで全部並べている一覧が 31 本ある（`getUrlRange(1, …->lastPage())`。Bug #24 のインラインの番号付き）。
決裁の画面（⑩・⑥・台帳）は 2b で「先頭・最後・今のページの前後 1 つだけ（間は「…」）」に直してあり、
2b の「後回し」に「ほかの 31 か所も、スマホで多ページのときはみ出す。別の機会にまとめて直す」と記録していた。

利用者の依頼（2026-10-08 の引き継ぎ・2026-10-13 に承認）: 31 本を決裁と同じ形にそろえ、狭い画面でページ送りが崩れないようにする。

対話で決まったこと（2026-10-13。どれも承認済み）:

| 論点 | 決定 |
|---|---|
| 進め方 | 案 A: 共通の部品 `<x-pager :paginator="…" />` を作り 31 本を置き換える。番号は部品の中で計算する。コントローラ（基幹）は触らない |
| 番号の決め方の置き場所 | `App\Support\Approval\PageNumbers` を `App\Support\PageNumbers` へ移す（中身は変えない）。決裁の会話も賛成（同じ計算を 2 か所に分けない）|
| 決裁の `_pager` | 消さずに `<x-pager>` を呼ぶ 1 行の包みにする（決裁の会話のお願い。5b の台帳と新しい ⑪ の一覧が読み込む予定）|
| 決裁の 2 本（自分の申請・利用者の管理）| こちらで `<x-pager>` に置き換える（決裁の会話が了承。5b では触らない）|
| 決裁のコントローラの `pages` | 案 ア: 使われなくなるので消す（4 行）。`@include('approvals._pager', …)` の 3 か所も `paginator` だけを渡す |
| テスト | §5（番号の Unit テスト・部品のテスト・全件分類の走査・代表 4 本の往復）|
| 確かめ方・記録・本番 | §6〜§8 |

---

## 2. 調査で分かった事実

読んだのは `13.x` = `2d0d54f9`（作業場所 `route-coverage` の枝 `page-numbers` の分岐元）。

### 2.1 31 本の形

番号のボタンの書き方は 31 本とも同じで、違うのは外側の箱の class とリンクの `transition-colors` だけ（Blade を正規化して比べた。6 通り）。

| 外側の箱 | 本数 | 一覧 |
|---|---|---|
| `px-4 py-3 border-t border-gray-200` | 14 | 賃貸マンションの部屋契約・駐車場契約・物件・入居者、テナントの周辺ビル調査・契約・顧客・問合せ・投資・物件・修繕・区画、ZEAL の体験予約・会員 |
| 同上（リンクに `transition-colors` が無い）| 6 | 住宅事業の契約管理・建売の契約の物件選び（`housing/contracts/select-building-property`）、不動産の契約・仕入れ案件・分譲地・仕入れ先 |
| インラインの `style="padding: 12px 16px; border-top: 1px solid #e5e7eb;"` | 1 | 買主（`buyers/index`。住宅事業・不動産で共用）|
| `mt-4` | 6 | DAD の発注者・従業員・工事案件・協力業者、住宅事業の注文住宅・建売 |
| `mt-4`（前に `@php($paginated->withQueryString())`）| 1 | 住宅事業ダッシュボード（`housing/_dashboard_contracted`）|
| `py-3 border-t border-gray-200` | 3 | 基幹の利用者管理（`admin/users`）、決裁の利用者の管理・自分の申請 |

- 31 本とも `@if($x->hasPages())` で包み、ページが 1 つなら何も出さない
- リンクは `getUrlRange()` の URL（中で `$paginator->url($page)` を呼ぶ）と `previousPageUrl()`・`nextPageUrl()`。絞り込みの条件は `withQueryString()`／`appends()` で残る
- ほかに手作りのページ送りを持つビューは `approvals/_pager.blade.php` だけ（`previousPageUrl`・`nextPageUrl`・`onFirstPage`・`hasMorePages`・`->links(`・`getUrlRange` を全ビューで探した）

### 2.2 決裁の部品

- `App\Support\Approval\PageNumbers::around(int $current, int $last): list<int|null>` — 先頭・最後・今のページの前後 1 つ。間が 1 ページだけなら「…」にせずその番号。null が「…」。多くて 7 つ
  - ⚠ `$current` は呼ぶ側が 1〜`$last` に抑えてから渡す（大きな番号のままだと `$current + 1` が float になり TypeError。Bug #100）
- `approvals/_pager.blade.php` — `$paginator` と `$pages` を受け、`<nav aria-label="ページ送り" class="flex justify-center gap-0.5 py-3 border-t border-gray-200">`。今のページに `aria-current="page"`
- 呼ぶのは ⑩ `AdminRequestController`（72 行・86 行で `pages` を渡す）・⑥ `NoticeController`（41 行）・台帳 `LedgerController`（52 行）。⑩・⑥ は `paginate()` の前にページ番号を最後のページまでに抑えている
- テスト: `AdminRequestsTest`（`pager()` で `aria-label="ページ送り"` の nav を読む・31 ページ・3 ページ・11 ページの並び）・`NoticeScreenTest`・`ListPageNumberClampTest`

### 2.3 手元で測った結果（使い捨ての環境・2026-10-08）

1 ページ 20 件。代表 7 本（テナントの顧客・ZEAL の会員・賃貸マンションの入居者・DAD の発注者・不動産の仕入れ先・基幹の利用者管理・不動産の顧客）を 375・641・1024・1440px で測った。

| ページ数 | 375px | 641px | 1024px | 1440px |
|---|---|---|---|---|
| 15（8 ページ目）| ボタンが 32px → 15.5〜17.8px に縮む（320px では 10.5px）| 32px | 32px | 32px |
| 60（8・30 ページ目）| 両端がカードの `overflow: hidden` に黙って切られる。8 ページ目で見えるのは 24〜40 だけ（今のページ・「<」「>」も見えない）| 切れる | 切れる | 収まる |

- どの場合も `main` の横スクロールは 0（ボタンは flex で縮み、縮みきると中央寄せのまま両側へはみ出してカードに切られる）
- 計算では、375px（カードの内側 341px）で縮み始めるのは 9 ページから、切れ始めるのは 21〜22 ページから

### 2.4 本番の今の様子（2026-10-13・利用者の Chrome で読み取りだけ）

基幹の 29 本を既定の絞り込み（テナント契約は「すべて」）で 375px で開いた。2 ページ以上は 7 本:

| 一覧 | ページ数 | 375px のボタンの幅 |
|---|---|---|
| テナント契約（すべて）| 13 | 18.7px |
| 周辺ビル調査 | 10 | 23.9px |
| 区画一覧 | 8 | 29.1px |
| テナントの顧客・ZEAL の体験予約 | 6 | 32px |
| 投資・ZEAL の会員 | 3 | 32px |
| 仕入れ案件 | 2 | 32px |

縮んでいる 3 本も 641・1024・1440px では 32px。横スクロール 0・切れているボタン 0。ほかの 22 本は 1 ページ。決裁の 2 本は使い始める前なので数えていない。

---

## 3. 設計

### 3.1 番号の決め方 `App\Support\PageNumbers`

`git mv app/Support/Approval/PageNumbers.php app/Support/PageNumbers.php` で移し、namespace を `App\Support` にする。中身（`around()`）は変えない。
docblock の「⚠ $current は呼ぶ側が抑えてから渡す」は残し、呼ぶのは `<x-pager>` であることを書き足す。

### 3.2 部品 `resources/views/components/pager.blade.php`

```blade
{{-- docblock（使い方・props・⚠ の注意。§3.2 の下の箇条） --}}
@props(['paginator'])
@if($paginator->hasPages())
    @php
        $pages = \App\Support\PageNumbers::around(min($paginator->currentPage(), $paginator->lastPage()), $paginator->lastPage());
    @endphp
    <nav aria-label="ページ送り" {{ $attributes->class(['flex justify-center gap-0.5']) }}>
        {{-- 「<」・番号（null は「…」・今のページは aria-current の緑）・「>」。中身は今の approvals/_pager と同じ --}}
    </nav>
@endif
```

- 呼び方: `<x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />`
- 番号のリンクは `$paginator->url($page)`、前後は `previousPageUrl()`・`nextPageUrl()`（今と同じ。絞り込みの条件はリンクに残る）
- ボタンの class は今の 31 本と同じ（`w-8 h-8 flex items-center justify-center rounded text-xs …`・番号のリンクは `transition-colors` 付き）。今のページは `aria-current="page"` の `span`
- 今のページは 1〜最後のページに抑えて `around()` へ渡す（部品の中で抑える＝呼ぶ側の抑え忘れで 500 にならない。Bug #100）
- 最後のページより先のページ（行の無いページ。SQL の `paginate()` は抑えない）を開いたときは、最後のページの周りの番号を出し、どれも今のページの色にしない（今のページかは `$page === $paginator->currentPage()` で決める）。「<」は `previousPageUrl()` のまま（今と同じ）
- ページが 1 つなら何も出さない（今と同じ）
- ボタンは多くて 9 個＝32px × 9 ＋ 2px × 8 ＝ 304px。375px（カードの内側 341px）で縮まない。320px（内側約 286px）では約 29.8px まで縮むが切れない
- `LengthAwarePaginator` だけを受ける（31 本と決裁の 3 か所はどれも `lastPage()` を持つ）
- ⚠ 属性式に `&quot;` を書かない（Bug #21）。`:paginator="$x"` の変数だけを渡す

### 3.3 置き換え

31 本のページ送りの塊（`@if($x->hasPages())` から対の `@endif` まで。21〜25 行）を 1 行にする。外側の箱は 3 通りにまとまる:

| 渡す class | 一覧 |
|---|---|
| `px-4 py-3 border-t border-gray-200` | §2.1 の 14 本・6 本・買主（インラインの style を同じ値の class に）＝ 21 本 |
| `mt-4` | DAD の 4 本・住宅事業の注文住宅・建売・住宅事業ダッシュボード ＝ 7 本 |
| `py-3 border-t border-gray-200` | 基幹の利用者管理・決裁の利用者の管理・自分の申請 ＝ 3 本（＋決裁の `_pager`）|

- 住宅事業ダッシュボードの `@php($paginated->withQueryString())` は、部品の前にそのまま残す（`@if` の外に出ても、1 ページのときに呼んで害は無い）
- コントローラ（基幹）は触らない

### 3.4 決裁の側

- `approvals/_pager.blade.php` を `<x-pager :paginator="$paginator" class="py-3 border-t border-gray-200" />` の 1 行（＋注記）にする。ファイルは消さない
- `@include('approvals._pager', [...])` の 3 か所（`approvals/admin/requests`・`approvals/ledger/index`・`approvals/notices/index`）は `['paginator' => …]` だけを渡す
- 3 つのコントローラから `use App\Support\Approval\PageNumbers;` と `'pages' => …` を消す（`AdminRequestController` は 72 行と 86 行の 2 か所・`NoticeController` 41 行・`LedgerController` 52 行）。⑩・⑥ のページ番号を抑える処理は残す（行の表示に要る）。その注記の `PageNumbers::around()` の言い方は、部品の名前に合わせて直す
- 決裁の会話には、`13.x` に入れたら版の番号を知らせる（5b の試作をその上で作り直すため）

### 3.5 見た目の変化

- 番号が先頭・最後・今のページの前後だけになる（間は「…」）
- 不動産・住宅事業の 6 本の番号に `transition-colors`（ホバーの色の変化）が付く
- 買主の一覧の枠線の色が、インラインの `#e5e7eb` から Tailwind の `gray-200` になる（ほぼ同じ色）
- 外側が `<div>` から `<nav aria-label="ページ送り">` になり、今のページに `aria-current="page"` が付く（読み上げで今のページが分かる）

---

## 4. 決めなかったこと・範囲外

- コントローラでページ番号を最後のページまでに抑えること（基幹の SQL の一覧は、行の無いページで表が空になるまま。部品は落ちない）
- ページ番号を直接打って移る入力欄・「最初へ」「最後へ」のボタン
- 1 ページの件数（20 件のまま）
- ボタンの見た目（大きさ・色）
- `->render()`・`->links()` で Laravel の既定の見た目を出すこと（アプリでは使わない。Bug #24）

---

## 5. テスト

どれも先に赤（直す前に落ちる）を確かめてから直す。

### 5.1 番号の決め方の Unit テスト `tests/Unit/Support/PageNumbersTest.php`

- 1 ページ `[1]`・2 ページ・3 ページ（1・2・3 ページ目）・11 ページ（1・3・6・11 ページ目）・60 ページ（8・30 ページ目）の並び
- 間が 1 ページだけなら「…」でなく番号（11 ページの 4 ページ目 → `1 2 3 4 5 … 11`）
- 60 ページのどのページでも 7 つまで
- `App\Support\Approval\PageNumbers` が残っていない（同じ計算を 2 か所に持たない）

### 5.2 部品のテスト `tests/Feature/PagerComponentTest.php`

`Blade::render('<x-pager :paginator="$p" … />', ['p' => new LengthAwarePaginator(…)])` で描画し、ボタンの並び・`aria-current`・リンクを読み取る（決裁の `AdminRequestsTest::pager()` と同じ読み方。新しいテストの部品にする）。

- 0 件・1 ページでは何も出ない（`nav` が無い）
- 3 ページ（2 ページ目）→ `< 1 2 3 >`・今のページは 2・「<」は 1 ページ目・「>」は 3 ページ目
- 最初のページの「<」・最後のページの「>」はリンクでない
- 11 ページの 1・3・6・11 ページ目の並び（決裁の `AdminRequestsTest` と同じ）
- 60 ページのどのページでもボタンは 9 個まで・今のページは開いたページだけ
- 最後より先のページ（3 ページで 99999・整数の最大）→ 落ちない・`< 1 2 3 >`・どれも今のページの色でない
- 絞り込みの条件が残る: `appends()` を付けた paginator で、番号・前後のリンクが `$p->url(n)` と同じ
- 渡した `class` が `nav` に付き、既定の `flex justify-center gap-0.5` も残る

### 5.3 走査テスト（全件分類）`tests/Feature/PagerUsageScanTest.php`

- 全ビュー `resources/views/**/*.blade.php`（2026-10-13 に 285 本）を読み、Blade のコメント `{{-- --}}` を落としてから調べる
- 手作りのページ送りの印 `(->|?->)\s*(getUrlRange|previousPageUrl|nextPageUrl|onFirstPage|hasMorePages|links)\s*\(` は、`components/pager.blade.php` の中にしか無い。ほかに見つかればファイルと行を名指しして落とす
- `<x-pager`（直後が空白・`/`・`>`）の呼び出しは、どれも `:paginator=` を持つ
- 下限: 読んだビュー 250 本以上・`<x-pager` の呼び出し 32 か所以上（31 本＋決裁の包み）。件数ぴったりにはしない（決裁が新しい一覧で `<x-pager>` を足すたびに赤くならないように）
- 分かれ目ごとの見本（同じ検出器に通す）:
  - 当たる: 各メソッド名・`->` の後ろの空白・`?->`・括弧の前の空白
  - 当たらない: コメントの中・文の中の語（「getUrlRange は使わない」）・`->sublinks(`・`->linksCount(`
  - 呼び出し: 複数行の呼び出しを数える・`<x-pager-foo` を数えない・`:paginator=` の無い呼び出しを落とす
- 死角（テストの注記に書く）: ページ送りの 1 行そのものを消した一覧は、32 か所の下限を割るまで見えない／`->render()` などの書き方は見ない

### 5.4 画面からの往復（代表 4 本）

外側の箱の違う代表: テナントの顧客（`px-4 …`）・DAD の発注者（`mt-4`）・不動産の仕入れ先（`px-4 …`・旧 `transition-colors` 無し）・基幹の利用者管理（`py-3 …`）。

- 1 ページ 20 件なので 221 件（12 ページ）を入れて 6 ページ目を開き、`< 1 … 5 6 7 … 12 >` と `aria-current`（6）を見る
- 描画された「>」のリンクをそのまま GET して 7 ページ目に着く（Bug #47・#31）
- `?page=` に整数の最大を入れても 200

### 5.5 今のテストへの影響

- 決裁の `AdminRequestsTest`・`NoticeScreenTest` のページ送りのテストは、包みを通っても同じ HTML（`nav` の class も同じ並び）になるので、そのまま通る見込み
- `ProcurementListWithProjectsTest` は今のページのボタンの class 列（`bg-emerald-600 border border-emerald-600 font-semibold">1</span>`）を見る。class は変えないので通る見込み

---

## 6. 確かめ方

### 6.1 全件テスト・lint

- 作業場所で全件（`vendor/bin/phpunit`・PHP 8.3）
- コンパイル済みビューを `php -l`（`view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

### 6.2 変異テスト

流す前に期待（落ちるテストの集合と理由）を書いた表で当てる（`.claude/route-coverage-tools/mutate/run.py`。今の `specs.py` は前の回の表＝ `specs.wrap-example.py` に写し済みなので入れ替える）。当てる先:

- 部品: 今のページを抑える処理を外す・`around()` に `currentPage()` をそのまま渡す・`aria-current` を外す・「<」「>」のリンクを入れ替える・`hasPages()` の包みを外す・`$attributes` を外す・`getUrlRange(1, …)` の全部並べる形に戻す
- 番号の決め方: 前後 1 つを 0 や 2 にする・先頭／最後を落とす・間が 1 ページのときも「…」にする
- 走査: 検出の分かれ目ごと（メソッド名・`?->`・空白・コメントを落とす処理・`<x-pager` の先読み・`:paginator=` の確かめ）・下限
- ビュー: 1 本を元の全部並べる形に戻す・`:paginator` を落とす・呼び出しを 1 つ消す（下限との関係を確かめる）
- 決裁: `_pager` を元の手作りに戻す

### 6.3 実ブラウザ（手元の使い捨ての環境）

- 外側の箱の 3 通りの代表と決裁の利用者の管理に、0・1・3・15・60 ページのデータを入れる
- 375・641・1024・1440px で: ボタン 9 個まで・32px のまま縮まない・切れているボタン 0・今のページと「<」「>」が見える・`main` の横スクロール 0・コンソールのエラー 0
- 番号をたどって次のページへ移れる

---

## 7. 記録

- `docs/RULES.md`: 新しい番号（記録の直前に最新の `13.x` と決裁の枝で数え直す。2026-10-13 時点で #117）。Bug #24 の「インラインの番号付きマークアップを流用」を `<x-pager>` に書き換える。Bug #100 の「`PageNumbers::around()` は呼ぶ側の 3 つが抑える」を部品が抑える形に直す
- `CLAUDE.md`: 件数（全 N 件）と Conventions のページネーションの行（`<x-pager>` を使う）
- `docs/BACKLOG.md`: 節を足し、決裁の 2b の「後回し」の 2 つ目（31 か所）を済みにする
- メモリ: ページネーションの約束ごと（`project_pagination_inline_numbered_convention.md`）

## 8. 本番へ出す手順（段ごとに利用者の承認を取る）

- DB・ルートの変更は無い。`PageNumbers` の置き場所が変わるので、`13.x` へ入れたあと main repo の cwd で `composer dump-autoload --no-dev --optimize` が要る（worktree から実行しない）
- CSS はおそらく変わらない（使う class は今のビューにある）。`./deploy.sh` がビルドする
- 順番:
  1. 決裁の会話に知らせる
  2. `13.x` へ入れる（早送り。`13.x` が進んでいたら枝へ取り込んで全件を流し直す）
  3. 読み込みの表を作り直す
  4. `./deploy.sh`
  5. 本番の読み取りと Chrome での確認（テナント契約の「すべて」13 ページを 375px で 32px のまま）
  6. 決裁の会話に `13.x` の版の番号を知らせる
