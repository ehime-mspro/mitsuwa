# Technical Rules & Bug Catalog

## Vite Build — CSS はビルド成果物である

**`public/build/assets/app-*.css` は `npm run build`（`vite build`）で生成されるビルド成果物**で、
git 管理外（`.gitignore`）。

**2026-07-15 に `deploy.sh` の先頭へ `npm run build` を組み込んだ**ので、
デプロイすれば CSS は必ず最新になる（ビルド失敗時は本番へ何も転送せず中断する）。

それ以前は `deploy.sh` が rsync するだけだったため、2026-04-23 のビルド以降 3 ヶ月弱
CSS が**凍結スナップショット**のままで、「Blade に足したクラスが無音で効かない」が
常態化していた。Bug #19 の inline style 回避も、RULES.md の「効かないクラス一覧」も、
すべてこの凍結の副作用だった。

**現状: アプリが使う Tailwind ユーティリティ 393 種は全てコンパイル済み＝無効クラスは 0。**

⚠ ローカルで開発サーバを使わず Blade を直接見ている場合、**クラスを足しただけでは効かない**
（ローカルの `public/build` も手で `npm run build` するまで古いまま）。デプロイ時は自動。

### 走査対象（docs/ は除外済み）

Tailwind v4 の**自動コンテンツ検出**は `.gitignore` 以外の**リポジトリ全体**を走査する。
これには `docs/*.md` も含まれるため、**2026-07-15 に `@source not` で docs/ を除外した**:

```css
/* resources/css/app.css */
@import "tailwindcss";
@source not "../../docs";   /* パスはスタイルシートからの相対 */
```

**なぜ必要だったか**——除外前は、この文書にクラス名を書いた事自体がビルドに取り込まれていた:

- 2026-04-17 に RULES.md へ「`gap-5` は壊れているから使うな」と書いた
  → 04-23 のビルドが RULES.md を走査 → `.gap-5{...}` が生成され **`gap-5` が実在してしまった**
- 2026-07-15 に「`w-7` `w-40` `sm:flex` は無い」と書いたら、同日の再ビルドで**全部実在した**
  （`w-40` はその「存在しない例」として挙げたものだった）

つまり **「クラス X は存在しない」と書くと次のビルドで自分自身が偽になる**状態だった。
過去の「効かないクラス一覧」が 12/12 誤りだったのはこれが原因で、
信じた人が「コンパイル済みのクラスをわざわざ inline style に書き換える」無駄をしていた。
除外により docs 由来の 42 クラスが CSS から消え、**ビルドはアプリの実使用だけを反映する**
ようになった（52,104 → 48,238 bytes）。

⚠ **走査対象は `resources/` だけではない。** `app/Enums/UnitStatus.php` は
フロアマップの色を `'bg-blue-50 border-blue-300'` のような**Tailwind クラス文字列で返す**ため
`app/` も走査が要る。`@source not` で除外先を増やす時は必ず実測してから
（`badgeStyle()` 系は inline style を返すので無関係）。

**それでも「効かないクラス一覧」は置かない。** Tailwind v4 の spacing scale は**動的**で
（`w-<任意の数>` は書けば生成される）、一覧は結局メンテできないため。使う前に測る。

### 新しいクラスを使いたい時

**普通に Tailwind クラスを書いてよい。`./deploy.sh` がビルドまでやる。**

- ローカルで見た目を確認したい時だけ、手で `npm run build`
- inline style での回避は**もう不要**（Bug #19 は凍結時代の話）
- 「今このクラスは効くか」を測りたい時は下記「Tailwind 監査」

### 旧バンドルの掃除（2026-07-15 に deploy.sh へ追加済み）

CSS/JS を変更するとハッシュ名が変わるため、旧 `app-*.css` が本番に孤児として残る
（`manifest.json` は新を指すので無害だが、デプロイの度に蓄積する）。
`deploy.sh` の `[4/6]` で `public/build/` だけを `--delete` 付きで再同期して掃除している:

```bash
rsync -avz --delete ./public/build/ ${SERVER}:${WEB_PATH}/build/
rsync -avz --delete ./public/build/ ${SERVER}:${APP_PATH}/public/build/
```

転送先が 2 つあるのは、**APP_PATH = Laravel が `public_path('build/manifest.json')` を読む側**、
**WEB_PATH = ブラウザが実ファイルを取りに行く側**の両方に配る必要があるため。

**⚠ `public/` 全体に `--delete` を付けるのは厳禁。**
`public/storage` は `storage/app/public` への symlink ＝ **本番のアップロード物を消しうる**。
`--delete` してよいのは Vite の出力しか入らない `public/build/` のみ。

⚠ 本番のファイルを消す変更を入れる時は、必ず先に `--dry-run` で消える物を確認すること:

```bash
rsync -avz --dry-run --delete ./public/build/ <server>:<web>/build/
```

## Tailwind 監査の落とし穴（クラス実在チェックの正しいやり方）

Bug #19 の「使う前にコンパイル済み CSS を grep して確かめる」は正しいが、**やり方を間違えると
逆方向に誤判定する**。2026-07-15 に両方を実測で踏んだので手順を確定させる。

### 正しいコマンド（main repo で実行すること）

**クラス名が英数字とハイフンだけなら**（`w-8` `gap-3` `items-center` 等）:

```bash
grep -oE "\.<class>[,{:>~+ ]" public/build/assets/app-*.css
```

**クラス名に `:` `.` `[` `]` を含むなら**（`hover:bg-red-50` `gap-1.5` `min-w-[140px]` 等）、
CSS 側はそれらをバックスラッシュでエスケープしているので `grep -oF` で**エスケープ後の literal**を探す:

```bash
grep -oF '.hover\:text-emerald-600' public/build/assets/app-*.css
grep -oF '.gap-1\.5'                public/build/assets/app-*.css
grep -oF '.min-w-\[140px\]'         public/build/assets/app-*.css
```

**`-o` は必須。** ビルド済み CSS はミニファイされていて**ファイル全体がほぼ1行**なので、
`-o` を省くと「マッチした行」＝ CSS 全体（約50KB）が流れてきて何も読み取れない。

**迷ったら実際のセレクタを目視するのが確実**（前方一致の誤判定も一目で分かる）:

```bash
grep -oE '\.w-8[^{]*\{[^}]*\}' public/build/assets/app-*.css
#=> .w-8{width:calc(var(--spacing) * 8)}
```

### 落とし穴 1: worktree では全クラスが「無い」ように見える

**`public/build` は `.gitignore` 済み（`.gitignore:16`）＝ worktree にビルド済み CSS が存在しない。**
worktree で grep すると全部 MISSING に見え、「コンパイル済みのクラスをわざわざ inline style に
書き換える」という無駄な作業に走る。**監査は必ず main repo（`/Users/masanori/site/manage`）で行う。**

### 落とし穴 2: アンカー無し = false positive（存在しないクラスを「ある」と誤判定）

Tailwind のクラス名は前方一致するものが多い。実測:

```
$ grep -oE "\.w-4" app-Df177yiQ.css     # ← アンカー無し
.w-4
.w-4          ← 実体は .w-48{...} の前半分にマッチしている
```

`.w-4{...}` と `.w-48{...}` の両方にヒットする。つまり **`w-40` のような存在しないクラスでも
`.w-4x` 系が1つでもあれば「ある」と誤判定**する。

### 落とし穴 3: `\{` アンカー = false negative（あるクラスを「無い」と誤判定）

false positive を潰そうと `\{` を付けると、今度は**セレクタリストに束ねられたルールを取りこぼす**。
Tailwind v4 は旧名と新名を1ルールにまとめるため、実体はこうなっている:

```
.flex-shrink-0,.shrink-0{flex-shrink:0}
```

`.flex-shrink-0` の直後は `{` ではなく `,` なので、`grep -oE "\.flex-shrink-0\{"` は**空を返す**。
コンパイル済みなのに「無い」と判断してしまう。

### 落とし穴 4: 小数を含むクラス名 = false negative

**クラス名の中の `.` を、CSS は `\.` にエスケープする。** `gap-1.5` の実体はこう:

```
.gap-1\.5{gap:calc(var(--spacing) * 1.5)}
```

ERE の `\.` は「リテラルのドット」であって「バックスラッシュ＋ドット」ではないので、
`grep -oE "\.gap-1\.5[,{: ]"` は**空を返す**。`gap-1.5` は実際にはコンパイル済みで
`attachment-section.blade.php` が現に使っている。
→ **小数入りは `grep -oF '.gap-1\.5'`**（エスケープ後の literal を探す）。

同様に該当し、いずれも実測でコンパイル済み: `gap-2.5` `py-1.5` `py-2.5` `w-3.5` `h-3.5`
`mb-3.5` `space-y-0.5` `space-y-1.5` `space-y-3.5`。

### 落とし穴 5: コンビネータ付きユーティリティ = false negative

`space-y-*` / `divide-*` は**複合セレクタ**になるため、クラス名の直後が `>` になる:

```
.space-y-1\.5>:not(:last-child){--tw-space-y-reverse:0; ...}
```

アンカー `[,{: ]` に `>` が無いので**空を返す**（`space-y-1.5` は小数と `>` の**二重**に該当）。
→ **アンカーは `[,{:>~+ ]`**（コンビネータ 3 種を含める）。

### 結論

| クラス名の形 | 使うコマンド |
|---|---|
| 英数字とハイフンのみ（`w-8` `gap-3`） | `grep -oE "\.<class>[,{:>~+ ]"` |
| `:` `.` `[` `]` を含む（`hover:bg-red-50` `gap-1.5` `min-w-[140px]`） | `grep -oF` でエスケープ後の literal |

妥当性確認済み（2026-07-15 実測）:

| 期待 | クラス | 確認したこと |
|---|---|---|
| 検出 | `w-4` / `w-48` | 前方一致の両方を正しく区別 |
| 検出 | `flex-shrink-0` | セレクタリスト `.flex-shrink-0,.shrink-0{...}` |
| 検出 | `gap-1.5` | 小数（`.gap-1\.5`） |
| 検出 | `space-y-1.5` | 小数＋コンビネータ（`>` が直後に来る） |
| 検出 | `hover:bg-red-50` | バリアント（`.hover\:bg-red-50`） |
| 検出 | `min-w-[140px]` | 任意値（`.min-w-\[140px\]`） |
| **非検出** | `w-40` `w-7` `hover:bg-red-100` | 実在しないクラスを誤検出しない |

**2026-07-15 に docs/ を `@source not` で除外したので、この表に非検出の例を書ける。**
除外前は、書いた時点で次のビルドがその文字列を拾って実在させてしまうため不可能だった
（冒頭「走査対象」参照）。⚠ ただし Tailwind v4 の spacing scale は動的なので、
`w-<数字>` 系は `resources/` や `app/` で使えば何でも生成される。上の非検出は
「**現在どこからも使われていない**」という意味であって、使えば実在するようになる。

## Past Bug Catalog

| # | Symptom | Root Cause | Fix |
|---|---------|-----------|-----|
| 1 | JS text displayed as HTML in x-data | `=>` arrow function's `>` parsed as HTML close tag | Extract to `<script>` named function |
| 2 | Styles disappear on Alpine toggle | `style=` + `:style=` conflict | Merge all into single `:style` |
| 3 | Form data lost on submit | Duplicate `name` attrs with `x-show` (hidden inputs still submit) | Use hidden + Alpine var or `:disabled` |
| 4 | Redirect fragment not working | Fragment passed as route param | `redirect(route(...) . '#fragment')` |
| 5 | Checkbox state lost after validation | `old()` returns string array | `.map(Number)` |
| 6 | Blade compile error | `@else` immediately followed by `<` or alphanumeric | Multi-line `@if/@else/@endif` |
| 7 | `@json()` error | PHP function inside `@json()` | Pre-format in Controller |
| 8 | SQL error on User query | `User::whereNull('deleted_at')` but no `deleted_at` column | Remove `whereNull` |
| 9 | Wrong column name error | `re_projects.name` doesn't exist | Use `project_name` |
| 10 | Controller argument not injected | `defaults()` doesn't work in Laravel 12 without URL param | Use `resolveDepartment()` |
| 11 | Kanji in furigana auto-input | `compositionupdate` event.data becomes kanji | Use `input` event + `compositionend` for katakana |
| 12 | Soft-deleted buyer not in dropdown | Buyer uses SoftDeletes | `->withTrashed()` on relation + include current buyer in edit |
| 13 | Building coverage shows `80.00%` | Model cast `decimal:2` | Change to `integer` |
| 14 | Cost item font too large after toggle | `style` + `:style` conflict on `<td>` | Merge into `:style` |
| 15 | Lot section cost not synced with Alpine | PHP rendered static value | Use Alpine `effectiveTotal` |
| 16 | `<option x-show>` not hiding + duplicate values in select | Browsers ignore `display:none` on `<option>` (spec-allowed inconsistency); also `<template x-for>` renders options AFTER `x-model` syncs | Use static `<option>` tags (not `x-for`) + filter data before rendering |
| 17 | Google Maps shows "For development purposes only" watermark + auth error dialog; `key=` empty in API URL | Blade called `env('GOOGLE_MAPS_API_KEY', '')` directly. After `php artisan config:cache` (run by deploy.sh on every deploy), Laravel disables direct `.env` reads outside config files — `env()` returns empty string in Blade | Register the value in `config/services.php` (`'google_maps' => ['api_key' => env('GOOGLE_MAPS_API_KEY')]`) and read it via `config('services.google_maps.api_key')` in Blade. Never call `env()` directly outside config files. |
| 18 | `<input type="file" class="form-input">` renders as a sharp-cornered, unstyled-looking box; the native "Choose File" button decoration disappears | The compiled `.form-input` rule contains `appearance: none; border-radius: 0`. On `<input type="file">` this strips the browser's native file-picker chrome and removes rounded corners, breaking visual consistency with surrounding rounded buttons | Do NOT apply `.form-input` to `<input type="file">`. Use an inline style instead, e.g. `style="display:block; width:100%; max-width:520px; padding:8px 12px; font-size:13px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; cursor:pointer; box-sizing:border-box;"`. Pages that override `.form-input` locally inside a `<style>` block (e.g. `zeal/members/_form.blade.php`) work, but only on that page — file inputs on other pages still get the bare `.form-input` definition |
| 19 | **【2026-07-15 解決済み】**Blade に足した Tailwind クラスが無音で効かない | `public/build/assets/app-*.css` はビルド成果物なのに、当時の `./deploy.sh` は rsync するだけでビルドしなかった。誰も手で `npm run build` しなかったため 2026-04-23〜07-15 の 3 ヶ月弱 CSS が凍結し、この症状が常態化していた | **`deploy.sh` の先頭に `npm run build` を組み込んで根治済み**（ビルド失敗時は本番へ何も転送せず中断）。**今は Tailwind クラスを普通に書いてよく、inline style での回避は不要**。ローカルで見た目を確認する時だけ手で `npm run build`。⚠ **かつてここに列挙していた「効かないクラス一覧」は 12/12 が誤りだった**——一覧に書いた事自体が Tailwind の走査対象（`docs/*.md` も含む）になりビルドへ含めてしまうため。**一覧は原理的に維持できないので置かない**（詳細は冒頭「Vite Build」）。現状を測りたい時は「Tailwind 監査の落とし穴」の手順で（⚠ 誤判定が4通りある。`public/build` は gitignore 済みで worktree には無いので main repo で） |
| 20 | ファイルアップロード時に「The route attachments/{type}/{id} could not be found.」で失敗する。例: `attachments/projects/11`, `attachments/ms_tenants/3`, `attachments/dad_projects/5` | `AttachmentController::TYPE_MAP` に新しい type を追加した際、`routes/web.php` の `Route::post('/attachments/{type}/{id}', ...)->where('type', '...')` の正規表現を更新し忘れた。`where` 正規表現に存在しない type は Laravel ルーターで弾かれ 404 になる（`procurements` 等の既存 type は通る） | `routes/web.php` の `where('type', '...')` 正規表現を `AttachmentController::TYPE_MAP` のキー全部と同期させる。新しい type を `TYPE_MAP` に追加するときは必ず `where` 正規表現にも同じ文字列を `\|` 区切りで追加すること。本番反映は `git push` だけでは不十分で `./deploy.sh` を実行する必要がある（rsync + `route:cache` 再生成） |
| 21 | `<x-form-actions :cancel-url="route(&quot;{$var}.xxx&quot;)" />` のように Blade コンポーネント属性式内に `&quot;` を含めたページが本番でだけ 500 `syntax error, unexpected token "&"` で落ちる。ローカルでは再現しない（例: `/housing/customers/create` が本番 500、`/realestate/customers/create` は同じ `_form.blade.php` でもアクセスタイミング次第で動いて見えてしまう） | Blade の Anonymous Component（`@props` のみで定義されたビュー型コンポーネント）の属性式に `&quot;` HTML エンティティを書くと、本番（PHP 8.3 + `view:cache` で全 Blade を precompile）経由ではデコードが漏れ、生のコンパイル済み PHP に literal `&quot;` が残って PHP 構文エラーになる。ローカル（lazy compile + PHP 8.5）では発火しないため気づけない | Blade コンポーネント属性で動的な route name を渡すときは `&quot;` を避け、PHP 文字列連結で組み立てる: `:cancel-url="route($department.'.customers.index')"`。route name が完全に静的ならシングルクォートで素直に書ける: `:cancel-url="route('tenant.customers.index')"`。残存検査は `grep -rn "&quot;" resources/views/` で一覧化。本番反映は `./deploy.sh`（`view:cache` 再生成）必須 |
| 22 | 一覧/詳細画面が本番でだけ 500 `TypeError: App\Enums\XxxStatus::tryFrom(): Argument #1 ($value) must be of type string\|int, App\Enums\XxxStatus given`。該当データ（例: 注文住宅の契約済レコード）が 1 件でも存在するときだけ発火し、空データのローカルでは該当行に到達せず素通りして気づけない（ローカル CLI も実 PHP 8.3 系なのでバージョン差ではなく **データ差**） | Eloquent の `casts()` で `'status' => XxxStatus::class` と enum キャストした属性は、読み出し時点で既に enum インスタンス。`tryFrom()` は `string\|int` のみ受け付けるため enum を渡すと TypeError。`Housing\HsContractListController::mapCustomOrderToDto` の `CustomOrderStatus::tryFrom($c->status)` が原因（2026-06-08 本番 500、commit c15812a1 で修正） | キャスト済み属性はそのまま使う: `$statusEnum = $model->status;`（null 可能性があれば `$model->status ? $model->status->label() : '—'` でガード）。生文字列からの変換が必要な場面だけ `tryFrom()` を使う。`whereIn('status', [Status::Foo->value, ...])` のようにクエリで `->value` を使うのは正しい。横展開検査: `grep -rn "::tryFrom(\$" app/` でキャスト属性への誤用を洗い出す |
| 23 | `x-data="someFunc({ data: @json($x) })"` のように `@json` を**二重引用符の `x-data="..."` 属性内**に入れると、Alpine が `... is not defined` を多発しコンポーネントが全く初期化されない（ボタン非表示・x-show/x-model/x-text 全滅。ただし x-cloak は外れるのでカード枠だけ表示され気づきにくい）。本番・ローカル両方で発火。例: 仕入れ案件/分譲地PJ 新規登録の原価管理（Excel取込・費用追加ボタンが出ない）、`tenant/repairs` の物件→区画絞り込み。原価管理は追加(7f0c9536)以来 create で一度も表示できていなかった | `@json`（= `json_encode($x, 15, 512)`）の `JSON_HEX_QUOT` は**文字列値の中の `"` のみ**エスケープし、**JSON の構造区切りの `"` は生のまま**出力する（`json_encode(['a'=>1],15)` → `{"a":1}`）。二重引用符の HTML 属性に入れると最初の構造 `"` で属性が途切れ、Alpine が受け取る式が `someFunc({ data: [{` のように壊れて評価不能 | ① データを `<script>` 内の named function で組み立て、属性は `x-data="someFunc()"` だけにする（詳細画面パターン。`_cost_section_form` を 511aa23b で修正）。または ② 属性に直接入れるなら `@json` でなく `{{ \Illuminate\Support\Js::from($x) }}`（`JSON.parse('...')` 形式で構造クォートも `"` 化＝属性安全。repairs を fffd9d47 で修正）。横展開検査: `grep -rn -A3 'x-data="' resources/views/ \| grep '@json'`。本番反映は `./deploy.sh`（view:cache 再生成）|
| 24 | 一覧画面のページネーションが番号付き（`< 1 2 3 … >`）でなく左右2ボタンだけになる（ZEALでは翻訳キー `pagination.previous` `pagination.next` が生表示）。本番・ローカル両方。`$paginator->hasPages()` が true（2ページ以上）の時だけ顕在化するので空データのローカルでは気づきにくい | デフォルトの `{{ $x->links() }}`（=`pagination::tailwind`）は番号付きを `hidden sm:flex` の desktop ブロックで描画するが、`sm:flex` `sm:hidden` 等のレスポンシブクラスは**プロジェクト Blade が使っておらず Tailwind JIT 未コンパイル**（フレームワーク vendor ビューはコンテンツスキャン対象外）。よって desktop ブロックが常時 hidden→`sm:hidden` のモバイル2ボタンだけ残る。ラベルも locale=ja で `lang/ja/pagination.php` 不在なら生キー表示（別件で lang ファイル追加 b26208e9） | `->links()` を使わず `resources/views/zeal/inquiries/index.blade.php` の**インライン番号付きマークアップ**を流用（`@foreach($x->getUrlRange(1, $x->lastPage()) as $page => $url)` ＋現在ページ `bg-emerald-600` ＋ `&lt;`/`&gt;`、コンテナ `flex justify-center gap-0.5`、ボタン `w-8 h-8 flex items-center justify-center rounded text-xs`）。2026-06-15 に旧 `->links()` 全10画面を統一（081ab5a7 + c8891f2c）しアプリ内 `->links()`=0。フィルタ保持はコントローラで `paginate()->withQueryString()` か Blade で `@php($x->withQueryString())` を番号付き出力の前に1回。横展開検査: `grep -rn "\->links()" resources/views/`。本番反映は `./deploy.sh`（view:cache 再生成）。⚠ **2026-07-15 追記**: 根本原因だった「`sm:flex` / `sm:hidden` 未コンパイル」は CSS 凍結（Bug #19）の副作用で、再ビルド後は repo 内にその文字列が在るかで決まる＝**もはや恒久的な事実ではない**。ただしアプリ内 `->links()` は 0 件でインライン番号付きが定着済みなので、**`->links()` に戻す理由は無い**（意匠も統一されている）。この行は「壊れるから避けろ」ではなく **規約**として読むこと |
| 25 | 不動産(realestate)の顧客詳細・新規登録後が本番でだけ 500 `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [realestate.customers.surveys.create] not defined.`。一覧は表示できるが「詳細」ボタンと「登録」ボタンで落ちる。買主データが無い空ローカルでは詳細(show)を一度も開かず素通りするため気づけない（2026-06-17 本番 500、commit 5b119f1d で修正） | `resources/views/buyers/show.blade.php` は housing/realestate **共通ビュー**で、「アンケートを追加」ボタンを `@forelse` の外＝**無条件**に `route("{$department}.customers.surveys.create", $buyer)` で描画する。だが `routes/web.php` のアンケート(`customers.surveys.*` 5ルート)は **housing にしか定義されておらず realestate に欠落**していた → ルート未定義で 500。surveys が空でも無条件ボタンは必ず実行されるので新規買主で即発火。store 成功後は show へリダイレクトするため「登録ボタンで 500」「詳細ボタンで 500」は同一画面。一覧(index)は surveys リンクが無く無事。`CustomerSurveyController` と登録画面(`buyers/_form.blade.php`)は両部署対応済み（housing 固有処理は `if($department==='housing')` でガード）で、**ルート定義だけが漏れていた**不整合 | realestate に surveys ルート5本(create/store/edit/update/destroy)を housing と対称・同一 middleware(create/store/edit/update=`role:executive,manager` / destroy=`role:executive`)で追加。横展開検査: 部署共通 Blade が `route("{$department}.xxx")` を無条件で呼ぶ箇所は、全部署にそのルートが定義されているか確認する `grep -rn 'route("{$department}' resources/views/`。空データのローカルで再現しない本番 500 は `php artisan view:cache` を有効化しローカルで本番同等にレンダリングして特定できる（Bug #22 と同じ「データが有る時だけ発火」型）。本番反映は `./deploy.sh`（route:cache 再生成）必須（git push だけでは不十分。Bug #20 と同じ）|
| 26 | テナント契約編集が本番でだけ全件 500 `ParseError: Unclosed '[' on line N does not match ')'`（compiled view 由来 `storage/framework/views/<hash>.php`）。一覧/詳細/新規作成は無事で **edit だけ**落ちる。空データのローカルは edit を一度もレンダリングしないため素通り。`view:cache` は「Blade templates cached successfully」と**成功表示するのに**発火する（2026-06-18、投資回収紐付けデプロイ直後、commit 56430ecf で修正） | `@json($x ? [` 改行 `'k' => $x->y->method(),` … `] : null)` のように **`@json()` に多行の配列リテラル（特に `->method()` 呼び出しを含む）** を渡すと、Blade の引数パーサ（括弧カウント）が誤って `json_encode(...)` の引数を途中で打ち切り、`[` 未閉じの壊れた PHP を生成する（例: `json_encode($x ? [ 'id'=>.., 'pl'=>$x->pattern->label()) ?>` と `total_amount`/`] : null` が欠落）。`view:cache` はコンパイル済み PHP を **lint しない**ため成功扱いになり、実レンダリングで `require` された瞬間に ParseError → 500。`php -l <.blade>` も Blade を緩く見るだけで検出不可。Bug #7（@json 内に関数）・Bug #23（x-data 属性内 @json）の同系統 | 配列は**コントローラで組み立て**、Blade には単一変数だけ渡す: `$currentInvestment = $contract->investment ? [...] : null;` → Blade `@json($currentInvestment)`（内部に括弧/角括弧が無く誤パース不能）。横展開検査: `grep -rn '@json(' resources/views/ \| grep '\['`（`?? []` や `old(...,[])` の単一行デフォルトは安全。**多行の配列リテラル＋メソッド呼び出しが危険**）。**検証は `view:cache` 成功では不十分** → コンパイル済みビューを必ず `php -l` する: `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null \|\| echo "INVALID: $f"; done && php artisan view:clear`。あるいは実データでレンダリングして確認（空ローカルは不可＝Bug #22/#25 と同型）。本番反映は `./deploy.sh`（view:cache 再生成）|
| 27 | 契約管理一覧 `/housing/contracts` が「**注文住宅の契約が1件以上あり、かつ建売契約が0件**」になる年度・絞り込みでだけ 500 `Error: Call to a member function getKey() on array`。建売が1件でもあれば通るので**データ依存**（種別・担当者・年度フィルタの組み合わせでも発火しうる）。空ローカルは両方0件で素通りし、本番も両種別が揃っていると気づけない（2026-07-25、契約一覧の3ゾーン様式刷新中に発見、commit 0d4761e7 で同時修正） | `$query->get()->map(fn ($m) => [...配列DTO...])` は、返り値が Model でなければ結果を base `Support\Collection` にダウングレードするが、**空コレクションの場合はその判定（要素の走査）が空振りするため `Eloquent\Collection` のまま残る**。`EloquentCollection::merge()` は**引数側の全要素に `getKey()` を呼ぶ**実装なので、「空の Eloquent コレクション」に「配列 DTO のコレクション」を merge すると配列に対して `getKey()` が呼ばれて致命エラー → 500。建売が1件でもあれば左辺は base 化済みなので `Support\Collection::merge()`（キー結合のみ）が使われ、症状が出ない。Bug #22/#25/#26 と同じ「**実データが特定の形の時だけ発火**」型 | merge の**左辺を明示的に base 化**する: `$tateuriItems->toBase()->merge($customItems)`。非空時は既に base なので `toBase()` は no-op ＝挙動不変。回帰テストで固定: `test_custom_order_only_period_does_not_500`（`tests/Feature/Housing/HsContractListColumnsTest.php`。建売を作らず注文住宅だけ作って一覧が 200 を返すことを検証）。横展開検査: `grep -rn '\->merge(' app/`（2026-07-25 実測でアプリ内は当該1箇所のみ）。**予防**: 複数ソースのリストを統合する時は `$a->toBase()->merge($b)` と書くか、最初から `collect()` に詰め替える。⚠ 「`->get()->map()` は必ず base になる」という思い込みが原因なので、**空ケースを1件でもテストに入れる**のが最短の防御。本番反映は `./deploy.sh`|
| 28 | `@push('scripts')` に入れたスクリプトが**出力されず、例外も警告も出ない**。症状は「ボタンを押しても無反応」。注文住宅一覧 `/housing/custom-orders` の進捗バッジは `onclick="openStepBar(this)"` を持つのに `window.openStepBar` が `undefined` で、クリックしてもステップバーが開かない（一覧からのステータス変更 PATCH が起動不能）。建売契約編集 `/housing/properties/{property}/contract/edit` は `x-data="contractEditForm()"` の関数定義が失われ Alpine がコンポーネントを初期化できず顧客名の Ajax 検索が死ぬ（Bug #23 と同じ症状クラス）。**初期コミット 2046289d 以来ずっと動いていなかった**（2026-07-26 に本番のブラウザ検証で発覚、commit 683a09d6 で修正） | `resources/views/layouts/app.blade.php` に **`@stack('scripts')` が一度も存在しなかった**（`git log -S"@stack" -- resources/views/layouts/app.blade.php` が空）。Blade の `@push` は対応する `@stack` が無いと**スタックに積んだまま誰も展開せず、静かに捨てられる**（Laravel は警告を出さない）。コンパイルも `view:cache` も成功し、`php -l` も通り、テストも「`onclick` 属性がある」ことだけ見ていれば緑になる。**呼び出し側（属性）と定義側（`<script>`）が別の場所にあり、片方だけ消えても HTML として妥当**なのが気づけない理由 | `</body>` 直前に `@stack('scripts')` を追加（`@push` が無いページでは何も出力しないので約 200 ルートへの影響ゼロ）。位置はレイアウト内の既存 `<script>` より後・`@yield('content')` より後にする（`@vite` は module = defer なので Alpine 起動前に関数定義が間に合い、スクリプトが参照する DOM 要素も既に存在する）。回帰テストで固定: `tests/Feature/LayoutScriptStackTest.php` — **呼び出し側と定義側を必ず対で検証する**（`onclick="openStepBar(this)"` と `function openStepBar(badge)` の両方。属性だけ見ると「定義が無いのに通る」）＋「push が無いページで余計な出力が増えない」ことも固定。横展開検査: `grep -rln "@push(" resources/views/` と `grep -n "@stack" resources/views/layouts/app.blade.php` を**セットで**見る。⚠ **`styles` スタックは今も無い** — `@push('styles')` は依然サイレントに破棄される（`mansion/properties/index.blade.php:12` に空の `@push('styles')`/`@endpush` が残っているので、そこに書くと消える）。スタイルは各ビューの `<style>` に直接置く。⚠ **検証は「HTML に出るか」だけでは不十分** — この JS は一度も実行されたことがなかったので、ブラウザで実際にクリックして動くことまで確認する。本番反映は `./deploy.sh`（`view:cache` 再生成）|
| 29 | Chart.js の `<canvas>` を CSS Grid のトラック（`grid-template-columns: 1fr`）に置くと、**カードがコンテンツ幅を超えて横に膨らみ `<main>` に横スクロールが出て、グラフの右端（最新月）が見切れる**。超過幅はウィンドウ幅によらず**常に一定**（ZEAL では +220px。1800px 幅でも 1200px 幅でも同じ）なので「狭い画面だけの問題」に見えず、逆に**広い画面でも直らない**。`view:cache` も `php -l` もテストも全部通り、**単体 HTML のモックでは再現しない**（2026-07-27、ZEAL ダッシュボードのグラフ縦積み化＋過去1年化の直後に本番ブラウザ検証で発覚、commit ecc95c3d で修正）| **原因は 2 つあり、両方揃わないと直らない**。① `grid-template-columns: 1fr` は `minmax(auto, 1fr)` の略で、**最小値 `auto` が中身の min-content 幅で下限を作る**。`layouts/app.blade.php` の `<main class="flex-1 overflow-y-auto">` は flex 子で `min-width: auto` のため、この膨張を止める側もいない → トラックがコンテンツ幅を超えて広がる。② Chart.js（`responsive: true` / `maintainAspectRatio: false`）は **`<canvas>` に inline の `style.width` を px で書き込む**。CSS 側に `max-width` が無いと**一度広がった canvas が二度と縮まず**、その値が min-content 幅の下限になり続ける（`chart.resize()` を呼んでも縮まない）。①②が噛み合って「コンテンツ幅 + 定数」で安定する。⚠ **横並び（`1fr 1fr`）の時代は 1 トラックが半分幅だったので閾値が高く、顕在化していなかっただけ**で、罠自体は前からあった | **2 点セットで直す。片方だけでは直らない**（`minmax` だけだとカードは正しく 916px に縮むが canvas が 1094px のまま残ってカードから溢れる）: <br>`.zd-chart-stack { grid-template-columns: minmax(0, 1fr); }`<br>`.zd-chart-card canvas { max-height: 220px; max-width: 100%; }`<br>**検証はスクリーンショットでなく DOM 実測で**——ブラウザで `main.scrollWidth === main.clientWidth` を確認する（`document.querySelector('main')`）。広い幅と狭い幅の**両方**で測ること（定数超過なので片方だけでは判定できない）。横展開検査: `grep -rln "<canvas" resources/views/`（2026-07-27 実測 8 本）と `grep -rln "grid-template-columns:[^;]*1fr" resources/views/`（同 59 本）の**積集合**を見る。実測では canvas が bare `1fr` トラックの直下にあるのは `zeal/dashboard.blade.php` のみで、`dashboard/_executive_housing.blade.php` は canvas が `.chart-wrap` 側にあり該当しない。⚠ **モックでの確認は無効** — 本番は flex な `<main>` の中にあるのに単体 HTML モックにはその階層が無く、ブローアウトが起きない。**グリッド幅が絡む変更は本番同等のレイアウト階層で測る**。本番反映は `./deploy.sh` |
| 30 | `view:cache` が `ParseError: syntax error, unexpected token ","` で落ちる。原因の行は **JavaScript の `//` コメント**で、そこには PHP も Blade 式も書いていない。コメントを消すと直るので「コメントが悪い」とは思い至らず、周囲の `@json($var)` を疑って延々と時間を溶かす（2026-07-28、仕入れ案件一覧への分譲地統合で発生。**Bug #23 の注意書きをコメントとして書いた事が発火源**という自己参照的な事故） | **Blade のディレクティブコンパイラは JS のコメントを解釈しない。** `//` の中だろうと `@json` という文字列を見つけたらディレクティブとして展開する。直後が `(` でない（日本語の「は」等が続く）と**引数が空のまま** `<?php echo json_encode(, 15, 512) ?>` にコンパイルされ、PHP 構文エラーになる。実測: <br>`// ⚠ @json は <script> の中だけ` → `// ⚠ <?php echo json_encode(, 15, 512) ?> は <script> の中だけ`<br>`@json` に限らず `@if` `@foreach` など**全ディレクティブ名が対象**。Bug #7 / #23 / #26 と同じ「@json 一族」だが、**これまでの 3 件は式に問題があったのに対し、本件は式が一切無いコメント行で起きる**のが違い | コメント中でディレクティブ名を書くときは **`@@json` とエスケープする**（Blade は `@@` を リテラルの `@` として出力するので、表示は `@json` のまま）。Blade コメント `{{-- --}}` に逃がす手もあるが、`<script>` 内では JS コメントのほうが自然。**検出は `php artisan view:cache` の成功表示では不可**（compiled PHP を lint しないため）。必ず生成物を lint する:<br>`php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`<br>横展開検査: `grep -rnE '^[[:space:]]*//.*@(json|if|foreach|php|include|section|yield|stack|push)' resources/views/ \| grep -v '@@'`。⚠ **`@php ... @endphp` の中は raw block なので Blade が statement-compile せず安全**——2026-07-28 実測でヒットした 8 件は全部 `@php` 内の PHP コメントで無害だった（279 ビューの lint も 0 件）。**危険なのは `<script>` など Blade がコンパイルする領域に置いた `//` コメント。** grep は目星をつけるだけに使い、判定は必ず compiled view の `php -l` で行う。本番反映は `./deploy.sh` |
| 31 | 一覧の「ステータス: 全て」（`?status=`）が **実 HTTP でだけ 0 件**になる。サービス層のテストは全部緑で、`Request::create('/x?status=')` で叩く限り再現しない（2026-07-28、仕入れ案件一覧への分譲地統合中に既存の回帰テストが拾って発覚） | `ConvertEmptyStringsToNull` は **HTTP ミドルウェア**なので、`Request::create()` で作ったリクエストには効かない。同じ `?status=` が<br>・実 HTTP → **`null`**<br>・`Request::create()` → **`''`**<br>と別の値で届く。`if (! is_string($status)) { return NO_MATCH; }` のような型ガードを`filled()` 判定より**前**に置くと、`''`（string）は通るのに `null` は「想定外の型」に落ちて 0 件になる。⚠ `$request->input('status', 'active')` は**キーが存在すれば既定値を返さない**ので、null は既定値 `'active'` にも救われない | 型ガードより先に **`if ($status === null) { return null; }`**（＝「全て」）を置く。**テストは `''` と `null` の両方を通すこと。** `Request::create()` に null は渡せないので`$request->query->set('status', null)` で明示注入する（実測でこの方法なら `input()` が null を返す）。⚠ **サービス層のテストだけでは原理的に検出できない**——ミドルウェアを通る HTTP レベルのテスト（`$this->get('/realestate/procurements?status=')`）を必ず 1 本残す。横展開検査: `grep -rn 'is_string(' app/Http/ app/Services/`。⚠ **同型の「ページ送りでフィルタが飛ぶ」欠陥は他の一覧に残っている。** `->paginate()->withQueryString()` は `appends()` → `Arr::query()`（= `http_build_query`）を通り、**null 値のキーを丸ごと捨てる**ため、`?status=`（全て）で 2 ページ目に行くと既定に戻る。2026-07-28 実測: `/realestate/projects?status=` は 1 ページ目 total=21 に対し 2 ページ目の URL に `status` キーが無く total=0 になる。**⚠ 2026-07-30 に全一覧を実測して決着（commit は下記）。「候補多数」は誤りで、実際に該当したのは `/realestate/projects` 1 本だけだった。** 判定条件は「**①「全て」オプションの値が空** かつ **②コントローラの既定が絞り込み**」の**両方**が揃うこと。実測: `/zeal/members` `/housing/properties` `/tenant/{contracts,inquiries}` `/dad/employees` 買主マスタは「全て」が `value="all"`（空でない）ので `http_build_query` に捨てられず**無事**。`/tenant/{units,investments}` `/zeal/inquiries` は空オプションだが既定も「全て」なので**キーが落ちても同じ結果**。`/realestate/procurements` は `ProcurementListService::paginationQuery()` で対策済み。**残っていた `/realestate/projects` を `->appends(array_map(fn ($v) => $v ?? '', $request->query()))` に修正**（`withQueryString()` をやめる）。⚠ **2026-07-28 に「total=21 → 2 ページ目 0 件」と記録したが、その後データが 16 件に減ってページ送り自体が出なくなり再現しなくなっていた**——件数依存で消えるので、コードを直してテストで固定するまで「直った」と判断しないこと。回帰テストで固定: `tests/Feature/RealEstate/ProjectListPaginationFilterTest.php`。⚠ **テストで `?status=&page=2` と URL を自分で組み立ててはいけない**——リンクが壊れていても status が付いた状態で届くので**必ず緑になる**。1 ページ目を描画させ **`$paginator->nextPageUrl()` を実際に辿る**こと（この誤りを実際に踏んだ。修正前は 3 本中 1 本しか変異を検出できなかった）。⚠ 「既定は従来どおり絞り込みのまま」も併せて固定する（正規化のついでに全件化していないことの確認）。**`withQueryString()` に戻す変異で 2 本が赤になることを確認済み**（2 ページ目が 25 → 20 件になり販売済 5 件が消える）。本番反映は `./deploy.sh` |
| 32 | Alpine の `x-show` で開くポップオーバーが**縦積みにならず横 1 列で開く**（実測 466px × 43px にバッジ 8〜9 個が並ぶ）。`:style` にはちゃんと `display: flex; flex-direction: column;` と書いてあり、Blade も `view:cache` も `php -l` もテストも全部通る。**壊れているのはブラウザだけ**（2026-07-28 本番ブラウザ検証で発見。仕入れ案件/分譲地/建売の 3 一覧で同一マークアップが稼働していた） | **`x-show` は `display` プロパティを自分のものとして扱う。** 同じ要素の `:style` に `display` を書いても Alpine に奪われる。実測した style 属性: <br>非表示 `... min-width: 130px; display: none; flex-direction: column; gap: 4px;` ← flex を none で**上書き**<br>表示   `... min-width: 130px; flex-direction: column; gap: 4px;` ← display を**丸ごと削除**<br>結果 computed display が `block` になり、残った `flex-direction: column` はflex コンテナが無いので無効。Bug #2（`style=` と `:style=` の競合）と同族だが、**相手が `x-show`** という別パターン | `display` / `flex-direction` / `gap` を**内側のラッパー div へ移す**:<br>`<div x-show="open" :style="'position: fixed; ... min-width: 130px;'">`<br>`  <div style="display: flex; flex-direction: column; gap: 4px;">`<br>`    <template x-for="...">...</template>`<br>`  </div>`<br>`</div>`<br>回帰テストで固定: `tests/Feature/AlpineXShowDisplayConflictTest.php`（`resources/views/` を走査し、`x-show` と `:style` を同一タグに持つ要素の `:style` に `display` が無いことを検証。**走査が空振りして緑になる事故を防ぐため「Blade を 100 本以上拾えていること」も併せて固定**）。横展開検査はテストが自動で行うので個別追加は不要。⚠ **検証は「HTML に出るか」では不可能** — 属性は正しく出ているのに Alpine が実行時に書き換える。ブラウザで開いて `getComputedStyle(el).display` を見るか、上記テストで見る。本番反映は `./deploy.sh` |
| 33 | 不動産・住宅事業の坪数表示が正しい値より 0.01 坪大きい。ユーザー報告: **132.69㎡ が 40.14坪 と出るが正は 40.13坪**（132.69 × 0.3025 = 40.138725 → 小数第3位以下切り捨て）。全画面で恒常的にズレるが「1 銭の差」なので長く気づかれなかった（2026-07-29 修正）| 5 モデル 6 メソッドが `round($sqm / 3.30579, 2)` と**四捨五入**で実装されていた（初期コミット 2046289d 以来）。仕様は切り捨て。⚠ **単に `round` を `floor` に替えるだけでは直らない**——`1 ÷ 3.30579 = 0.30249995…` で 0.3025 より僅かに小さく、四捨五入では吸収されていた差が切り捨てで表面化する（100㎡ → 30.24坪、正は 30.25坪 / 200㎡ → 60.49坪、正は 60.5坪）。⚠ さらに `floor($sqm * 0.3025 * 100) / 100` と float で書いても**二進誤差で下振れ**する（0.01㎡ 刻み 10 万件中 **41 件**が誤り: 28㎡ → 8.46坪、正は 8.47坪 / 44㎡ → 13.3坪、正は 13.31坪 / 60㎡ → 18.14坪、正は 18.15坪）| `App\Support\AreaConverter::sqmToTsubo()` に一本化し、**除算をやめて `× 0.3025`**、かつ **1/100㎡ 単位の整数演算**にする（㎡ カラムは全て `decimal(10,2)` なので厳密になる）: `intdiv((int) round($sqm * 100) * 3025, 10000) / 100`。bcmath の厳密解と 220,001 件照合して不一致 0 件を確認済み。回帰テストで固定: `tests/Unit/Support/AreaConverterTest.php`（**罠①②それぞれに戻したら落ちる値**を明示的に置いている。実際に 3 通りの誤実装へ変異させて赤になることを確認済み——値を消すと再発を検出できなくなる）＋ `app/Models/` に `3.30579` が残っていないことを走査で固定。⚠ **分譲地区画だけ `re_project_lots.area_tsubo` が DB 保存カラム**（`ProjectController` の store/update が書き込む）なので、コード修正に加えて既存行の一括更新が要る: `UPDATE re_project_lots SET area_tsubo = FLOOR(area_sqm * 0.3025 * 100) / 100`（MySQL の `DECIMAL` 演算は二進誤差が無いので SQL でも厳密）。⚠ **テナント管理の `units.area_tsubo` は坪を直接入力**しており㎡換算していないので無関係。本番反映は `./deploy.sh` |
| 34 | 坪単価の丸めが「必ず切り上げ」の仕様に反する。**ただし画面表示は一見正しく、本番 34 区画すべて仕様どおりに出ていた**ため、実データを掃引するまで欠陥が見えなかった（2026-07-29、Bug #33 に続けてユーザーが仕様を明示したことで発覚）| **欠陥は 2 つあり、どちらも「丸めが 2 回ある / float で丸めている」型**。⚠ **①二段階丸め**: `ProjectController` が保存時に `(int) round($price / $tsubo)` と**四捨五入**して円/坪を保存し、`ReProjectLot::getSellingPricePerTsuboFormatted()` がそこから `ceil($yen / 1000) / 10` と万円へ切り上げていた。真値が 1,000 円の倍数を **0.5 円未満だけ超える**とき前段の四捨五入が引き下げ、後段の切り上げが無効化される（19,990,000円 / 20.01坪 = 999,000.4998円/坪 → @99.9、正は @100.0）。実測 800 万通り中 **1,529 件**（0.019%）。⚠ **②float の `ceil`**: テナント賃料坪単価の `(int) ceil($rent / (float) $areaTsubo)` が、**割り切れる場合に 1 円上振れ**する（153,000円 ÷ 5.10坪 = ちょうど 30,000円 なのに 30,001円）。実測 877,851 通り中 **115 件**。⚠ さらに Bug #33 で `area_tsubo` を切り捨てに直した際、派生カラム `selling_price_per_tsubo` を再計算しなかったため本番 34 件中 **16 件**が stale 化していた（表示は偶然影響なし）| `App\Support\TsuboPrice` に一本化し、**丸めを 1 回だけにする＋除算を整数演算に置き換える**。坪カラムは `decimal(10,2)` / `decimal(8,2)` なので坪を 1/100 単位の整数にすれば厳密: <br>円/坪 `intdiv($amount * 100 + $h - 1, $h)`（$h = 坪×100）<br>万円/坪の1/10単位 `intdiv($price + $h * 10 - 1, $h * 10)`<br>**表示は保存済みの円/坪カラムを見ない**（見ると二段階丸めに戻る）。販売価格と坪数から直接 1 回で丸める。bcmath の厳密解と 53,109 通り照合し不一致 0 件。回帰テストで固定: `tests/Unit/Support/TsuboPriceTest.php`（**罠①②それぞれに戻したら落ちる値**＋「保存カラムにわざと嘘の値を入れても表示が変わらない」ことで経路も固定。実際に 2 通りの誤実装へ変異させ赤になることを確認済み）＋ `resources/views/` `app/` に坪単価の直書き `ceil` が残っていないことを走査で固定。⚠ **坪数は null がありうる**（テナント区画は坪を手入力するので未設定行がある）。ヘルパーの引数を非 null に狭めると区画詳細・契約詳細・フロアマップが 500 になる——実際に `tests/Feature/Tenant/UnitRentRevisionTest` が検出した。⚠ **`is_price_manual` は常に `true` が代入されるだけでどこからも読まれていない**（デッドフラグ。今回は未着手）。本番反映は `./deploy.sh` ＋ stale な中間カラムの再計算 |
| 35 | バリデーションエラーで差し戻されたとき、**フォームに戻らず生の JSON ページが表示され、入力が全部消える**。⚠ **「何も入力せず送信」では再現しない** — 画面上の Ajax（仕入れ先検索・仕入れ案件選択など）を**一度でも叩いた後**にだけ発火するため、素朴なエラー確認では素通りする。ローカルでもテストでも再現せず、`view:cache` も `php -l` も全部通る（2026-07-29、仕入れ案件一覧からの分譲地登録導線を実装中にコード品質レビューが指摘 → 実測で再現。commit 76a750b8 / f2e353f1 で修正）| Laravel の `StartSession::storeCurrentUrl()` は「**GET・ルートあり・非 Ajax・非 prefetch**」のリクエストごとにセッションの `_previous.url` を上書きする。`$request->ajax()` は **`X-Requested-With: XMLHttpRequest` ヘッダーの有無だけ**で決まる（`Accept: application/json` では効かない）。JSON API のルートは `routes/web.php` にあり `web` ミドルウェア（`StartSession`）配下なので該当する。よって**ヘッダーの無い `fetch` で JSON を GET すると、その URL が「直前 URL」として記録され**、その後 `$request->validate()` が失敗したときの `back()`（= `url()->previous()`）がそこへ飛ぶ。実測（2026-07-29）: <br>`/realestate/projects/create?from=procurements` → 仕入れ先検索 → POST 失敗 → back() が **`/api/realestate/suppliers/search?q=abc`** へ<br>`/realestate/contracts/create` → 仕入れ案件を選択 → POST 失敗 → back() が **`/api/realestate/procurement-cost/1`** へ<br>同じ操作でヘッダーを付けると正しくフォームへ戻る。⚠ **同一ファイル内で非対称だった** — `supplier-picker.blade.php` の簡易登録 POST には `X-Requested-With` が付いていたのに、すぐ上の検索 GET には無かった。契約画面の 6 箇所は**ヘッダーが一切無い** `fetch(url)` だった | JSON API を叩く `fetch` には必ず `headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }` を付ける。回帰テストで固定: `tests/Feature/RealEstate/AjaxFetchSessionGuardTest.php`（**`resources/views/` 全体を走査**し、`/api/realestate/` を叩く `fetch` すべてにヘッダーがあることを検証。走査が空振りして緑になる事故を防ぐため呼び出し箇所数の下限も併せて固定。Bug #32 のテストと同じ流儀）＋ `tests/Feature/RealEstate/SupplierSearchBackUrlTest.php`。⚠ **挙動テストだけでは原理的に検出できない** — テストは PHP からヘッダーを手で送るので、**Blade からヘッダーが消えても緑のまま通る**。呼び出し側（Blade）と挙動の**両方を対で**検証すること（Bug #28 と同じ構図）。⚠ **`Request::create()` でも検出できない**（ミドルウェアを通らない。Bug #31 と同型）。横展開検査は走査テストが自動で行うので個別追加は不要だが、手で見るなら `grep -rn "fetch(" resources/views/` で `/api/` を叩くものを洗う。⚠ **確認手順**: 本番/ローカルのブラウザで「**Ajax を一度叩いてから**必須項目を空で送信」する。これを省くと再発を見逃す。本番反映は `./deploy.sh`（`view:cache` 再生成）|
| 36 | バリデーションエラーの文言が **`validation.required` という生の翻訳キー**で画面に出る。**アプリ全体のフォームが該当**（約 200 ルート）。⚠ **画面を開くだけでは分からない** — 実際にエラーを起こさないと出ないため、初期コミット以来ずっと放置されていた（2026-07-29、Bug #35 の本番ブラウザ検証で「空送信 → 戻り先確認」をしたついでに初めて可視化された）| `APP_LOCALE=ja` / `APP_FALLBACK_LOCALE=ja` なのに **`lang/ja/validation.php` が存在しなかった**（`lang/ja/` の中身は `pagination.php` 1 本だけで、それも Bug #24 の対応でインライン番号付きページネーションへ統一した結果もう使われていない ＝ **実質 lang/ は空**）。fallback も ja なので **en の組み込みメッセージにも落ちない**（`__('validation.required')` が自分自身を返すことを tinker で確認）。⚠ **横展開 grep に出ない** — `$request->validate()` は **Laravel 内部**で `__('validation.*')` を呼ぶので、`grep -rn "__('\|@lang(" resources/ app/` では 1 件もヒットしない。⚠ **テストでは原理的に検出できなかった** — `phpunit.xml` が `APP_LOCALE` を設定しておらず、`config/app.php` の既定 `env('APP_LOCALE','en')` により**テストだけ locale=en** で走っていた。en には Laravel 組み込みの英語メッセージがあるため、テストは常に英語の正しい文を受け取り緑になる | `lang/ja/validation.php` を追加。**キー構造を `vendor/laravel/framework/.../lang/en/validation.php` と 1 対 1 に揃える**（135 キー。アップグレードで増減したとき diff で追えるように順序も原文どおり）＋ `attributes` に画面ラベル由来の和名。⚠ **`attributes` はアプリ全体で 1 つのマップしか持てない** — 実測で 259 キー中 **24 キーが画面ごとに別の意味**（`name` は 顧客名/発注者名/氏名/プラン名/物件名 等 **7 通り**で 32 箇所に出る）。中立な語を当てており、厳密な文言が要る画面は各コントローラの `validate()` **第 3 引数**で個別指定する（`validate($rules, $messages, $attributes)`。**第 2 引数は messages なので空配列を渡す**。第 3 引数が翻訳ファイルより優先されることは `FormatsMessages::getDisplayableAttribute()` で確認済み）。⚠ **2026-07-30 追記 — 項目名は別途 Bug #37 として洗い直した。**⚠ **`phpunit.xml` に `APP_LOCALE=ja` / `APP_FALLBACK_LOCALE=ja` を追加した**（本番と揃える。この 2 行を消すとロケール依存の欠陥が再び検出不能になる。追加後も既存 368 テストは全て green ＝ 英語メッセージに依存したテストは無かった）。回帰テストで固定: `tests/Feature/JapaneseValidationMessagesTest.php`（①**全 135 キーを直接引いて自分自身が返らないこと**＝ HTTP でいくつか踏むだけでは踏まなかったルールの欠落を見逃すため ②HTTP 経路で複数エラーが全部日本語になること ③フレームワークとのキー集合の一致＝**Laravel アップグレードでルールが増えたら落ちる** ④`app()->getLocale()==='ja'`）。⚠ 翻訳ファイルは `config:cache` / `view:cache` の対象外で**実行時読み込み**＝キャッシュクリア不要。`deploy.sh` の rsync 対象なので本番反映は `./deploy.sh` のみでよい |
| 37 | バリデーションの**項目名**（`:attribute`）が画面と食い違う。2 種類ある: ①**未定義キー 160 件** — `attributes` に無い項目は Laravel が snake_case を単語に開いて出すので、画面に **`guarantor1 name` `started at` `desired area min` のような英字**が出る（テナント契約の保証人欄・修繕の実施日・問合せの希望面積など）②**語のズレ 77 件** — 分譲地の画面ラベルは「所在地」なのにメッセージは「**住所**は必須です。」、DAD 工事案件は「工事名」なのに「プロジェクト名」、従業員は「在籍状況」なのに「ステータス」。⚠ **Bug #36 を直した直後の本番ブラウザ検証で発覚**（生キーが日本語になって初めて項目名の粗が見えた）。⚠ **画面を開くだけでは分からない**のは #36 と同じで、実際にエラーを起こす必要がある（2026-07-30 修正）| `attributes` は**アプリ全体で 1 つのマップしか持てない**ため、Bug #36 では「どの画面でも通じる中立な語」を当てて先送りしていた。未定義キーのほうは単純な追加漏れで、**Blade のラベルが `<label for="x">` と改行で分かれている画面（ZEAL / DAD）は素朴な grep に出ない**ため取りこぼしていた（最初の走査で 86 件、`<label>` の複数行対応を入れて再走査したら **160 件**あった）| **キーを 2 群に分けると大半はコントローラを触らずに済む。** ①**画面ラベルが 1 種類しかないキー**（実測 93 + 追加分）→ `lang/ja/validation.php` のグローバル値を直すだけ ②**画面ごとに語が変わるキー**（`name` は 物件名/顧客名/氏名/発注者名/専門分野名/名前 の 6 通り、`address` は 住所/所在地/市区町村・番地以降）→ グローバルは多数派のままにし、**少数派の画面だけコントローラの `validate()` 第3引数**で上書き（22 本）。⚠ **第 2 引数ではない**（`validate($rules, $messages, $attributes)`。間違えると messages として解釈され黙って無視される）。⚠ **括弧の注記は項目名に含めない**方針（「共益費（月額）」→ 共益費、「顧客（任意）」→ 顧客）。単位・税区分・任意/自動は項目名ではなく、「顧客（任意）は必須です」という矛盾も防げる。例外は `area_sqm`「面積（㎡）」/ `area_tsubo`「面積（坪）」で、単位が項目の区別そのもの。⚠ **保証人・緊急連絡先は画面ラベルが単に「氏名」「住所」**で複数エラー時にどちらか分からないため接頭辞を付けた（「保証人1 氏名」）。⚠ **`costs.*.estimated_amount` のようなワイルドカードは `attributes` 側でもそのまま書ける**（Laravel が `costs.0.…` を `costs.*.…` で解決する）。回帰テストで固定: `tests/Feature/JapaneseValidationMessagesTest.php` に 2 本追加 — ①**全コントローラの `validate()` キーを走査して和名が無いものが 0 件**（新しいフォームで和名を忘れたら落ちる。走査が空振りして緑になる事故を防ぐためキー数の下限 300 も固定）②**グローバル既定が「住所」のままであることと、分譲地の HTTP エラーが「所在地」であることを同時に検証**（片方だけ見ると「グローバルを所在地に書き換えただけ」で緑になり、住所ラベルの画面が壊れる）。**2 通りの変異（和名を 1 件消す / 第3引数を消す）で実際に赤になることを確認済み。** 本番反映は `./deploy.sh`（翻訳ファイルは実行時読み込みだがコントローラは `route:cache` 再生成が要る）|

## Postal Code APIs

- Forward lookup (zip → address): zipcloud API (frontend JS direct call)
- Reverse lookup (address → zip): HeartRails GeoAPI `getTowns` (server-side cURL, `reverseZipLookup` method)

## Google Maps

- Used for 仕入れ案件 and 分譲地PJ (geocoding + draggable/clickable pin)
- DB columns: `latitude`/`longitude` as `DECIMAL(10,7)`
- API key in `.env` as `GOOGLE_MAPS_API_KEY`

## Full-width → Half-width Numeric Conversion

Global listener in `resources/views/layouts/app.blade.php` automatically converts full-width digits (`０-９`) to half-width (`0-9`) on every numeric input across the entire application (all 185 routes inherit via single layout).

**Target elements:** `input[inputmode="numeric"]` or `input[type="number"]`

**Mechanism:**
- `document.addEventListener('input', fn, true)` — capture phase, runs before Alpine sync
- Preserves caret position via `selectionStart` / `selectionEnd`
- Digit transliteration only — no other character stripping

**Effect:** User types `１０００００` → immediately becomes `100000`. Alpine `x-model` values are always half-width, so downstream calculations work without per-field normalization.

**Scope:** Single injection point — no per-form wiring needed. Applies automatically to any new form added to the app.

## Excel Import (Client-side SheetJS → Server-side PhpSpreadsheet)

Mockup pattern for Excel cost-breakdown upload (DAD 工事案件 `projects/create.html`):

- Library: SheetJS (`cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js`) — `cdn.jsdelivr.net` only
- Flow: File → Sheet/Header selection → Column mapping (auto-guess from header text) → Preview with warnings → Commit (append to target array)
- Category alias normalization: `材料/資材 → 材料費`, `外注/下請 → 外注費`, `労務 → 人件費`, `機械/重機 → 機械経費`, `経費 → 諸経費`
- Amount normalization: full-width digits, comma/space/円/¥ stripped; non-numeric flagged
- UI: inline expansion inside the target card (not modal), 3-step indicator
- Alpine caveat: `<option>` list MUST be static — `<template x-for>` renders after `x-model` syncs and causes select value mismatch (see Bug #16)

Server-side swap path: replace `commitImport()` with `<form>` submit to `ProjectImportController@preview` + `@execute` using PhpSpreadsheet. UI panel HTML stays unchanged.

## Fiscal Year Calculation

```php
$month = now()->month;
$year = now()->year;
$fiscalYear = $month >= 5 ? $year : $year - 1;
$start = "{$fiscalYear}-05-01";
$end = ($fiscalYear + 1) . "-04-30";
```

## Development Workflow

1. Requirements definition with structured Q&A — lock all decisions before coding
2. Design mock (HTML) creation and review — explicit approval required
3. Implementation — check against all rules in this document
4. 30-point quality audit before delivery
5. Package as handoff ZIP with updated HANDOFF_PROMPT.md
6. Deploy: file placement → SQL execution → cache clear → browser verification
