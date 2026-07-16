# Technical Rules & Bug Catalog

## Vite Build — CSS はビルド成果物である（2026-07-15 再ビルド済み）

**`public/build/assets/app-*.css` は `npm run build`（`vite build`）を手で叩いた時だけ再生成される。**
`./deploy.sh` はできあがった `public/build` を rsync するだけ＝**ビルドしない**。

このため 2026-04-23 のビルド以降 3 ヶ月弱、CSS は**凍結スナップショット**のままだった。
「効くクラス／効かないクラス」は Tailwind の仕様ではなく
**ビルド時点のリポジトリにその文字列が在ったか**で決まっていた。
2026-07-15 に再ビルドして解消済み（`app-Df177yiQ.css` → `app-CfjY1Ajc.css`、本番反映済み）。

**現状: アプリが使う Tailwind ユーティリティ 393 種は全てコンパイル済み＝無効クラスは 0。**

### ⚠ このドキュメントに書いたクラス名は実在するようになる

Tailwind v4 は `resources/css/app.css` が `@import "tailwindcss";` だけ（`@source` 指定なし）
＝**自動コンテンツ検出**で、`.gitignore` 以外の**リポジトリ全体**を走査する。
`resources/views/` だけでなく **`docs/*.md` も走査対象**。

つまり **`docs/` にクラス名を書くと、次のビルドでそのクラスが CSS に生成される**。実証済み:

- 2026-04-17 に RULES.md へ「`gap-5` は壊れているから使うな」と書いた
  → 04-23 のビルドが RULES.md を走査 → `.gap-5{...}` が生成され **`gap-5` は効くようになった**
- 2026-07-15、この文書に「`w-7` `w-40` `sm:flex` `hover:bg-red-100` は無い」と書いた
  → 同日の再ビルドで**全部実在するようになった**（`w-40` はその「存在しない例」だったのに）

**帰結: 「クラス X は存在しない」と書くと、次のビルドで自分自身が偽になる。**
だから**この文書に「効かないクラス一覧」は置かない**。原理的に維持できない。
過去に置いていた一覧は 2026-07-15 の実測で **12/12 が誤り**で、
信じた人が「コンパイル済みのクラスをわざわざ inline style に書き換える」無駄をしていた。

さらに Tailwind v4 の spacing scale は**動的**（`.w-40{width:calc(var(--spacing) * 40)}` が
書いただけで生成される）ので、「存在しないクラスの例」を文書に書くこと自体が不可能。

### 新しいクラスを使いたい時

**`npm run build` してから `./deploy.sh`** ——これが本来の手順。Blade に新しいクラスを足しても
リビルドするまで CSS には入らない（＝無音で効かない）。

- バンドル名のハッシュが変わり本番反映が必須になるので、**独断で実行せずユーザーに確認する**
- リビルドできない事情がある時だけ、暫定回避として inline style（Bug #19）
- リビルド前に「今このクラスは効くか」を知りたい時は下記「Tailwind 監査」で測る

⚠ `deploy.sh` の rsync に `--delete` が無いため、旧 `app-*.css` は本番に孤児として残る
（`manifest.json` が新を指すので無害）。

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

実在するクラスで妥当性確認済み（2026-07-15）:
`w-4` / `w-48` / `flex-shrink-0`（セレクタリスト）/ `gap-1.5`（小数）/
`space-y-1.5`（小数＋コンビネータ）/ `hover:bg-red-50`（バリアント）/
`min-w-[140px]`（任意値）——**全て正しく検出**。

**⚠ 「非検出になるはずの例」をここに書くことはできない。**
書いた時点で次の `npm run build` がその文字列を拾い、実在するようになってしまうため
（冒頭「⚠ このドキュメントに書いたクラス名は実在するようになる」参照）。
false positive を自分で確かめたい時は、**この文書に書かれていない適当な名前**を
その場で試すこと。ただし Tailwind v4 の spacing scale は動的なので
`w-<数字>` 系は何でも生成されうる点に注意（`w-999` すら作られる）。
前方一致の誤判定を確かめるなら `.w-4` と `.w-48` の関係を実セレクタで見るのが早い。

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
| 19 | Blade に足した Tailwind クラスが無音で効かない | `public/build/assets/app-*.css` は `npm run build` を手で叩いた時だけ再生成される成果物で、`./deploy.sh` は rsync するだけ。**リビルドするまで新しいクラスは CSS に入らない**。2026-04-23〜07-15 は 3 ヶ月弱リビルドされず凍結していたため、この症状が常態化していた（2026-07-15 に再ビルドし解消） | **クラスを足したら `npm run build` → `./deploy.sh`**（バンドル名が変わるのでユーザー確認必須）。リビルドできない時だけ暫定回避で inline style / `onmouseover` インラインハンドラ。リビルド前に現状を知りたければ **main repo で実測**（コマンドは「Tailwind 監査の落とし穴」。⚠ 誤判定が4通りある。`public/build` は gitignore 済みで worktree には無い）。⚠ **かつてここに列挙していた「効かないクラス一覧」は 2026-07-15 の実測で 12/12 が誤りだった**——一覧に書いた事自体が Tailwind の走査対象になりビルドへ含めてしまうため。**一覧は信じず測る**（詳細は冒頭「Vite Build」） |
| 20 | ファイルアップロード時に「The route attachments/{type}/{id} could not be found.」で失敗する。例: `attachments/projects/11`, `attachments/ms_tenants/3`, `attachments/dad_projects/5` | `AttachmentController::TYPE_MAP` に新しい type を追加した際、`routes/web.php` の `Route::post('/attachments/{type}/{id}', ...)->where('type', '...')` の正規表現を更新し忘れた。`where` 正規表現に存在しない type は Laravel ルーターで弾かれ 404 になる（`procurements` 等の既存 type は通る） | `routes/web.php` の `where('type', '...')` 正規表現を `AttachmentController::TYPE_MAP` のキー全部と同期させる。新しい type を `TYPE_MAP` に追加するときは必ず `where` 正規表現にも同じ文字列を `\|` 区切りで追加すること。本番反映は `git push` だけでは不十分で `./deploy.sh` を実行する必要がある（rsync + `route:cache` 再生成） |
| 21 | `<x-form-actions :cancel-url="route(&quot;{$var}.xxx&quot;)" />` のように Blade コンポーネント属性式内に `&quot;` を含めたページが本番でだけ 500 `syntax error, unexpected token "&"` で落ちる。ローカルでは再現しない（例: `/housing/customers/create` が本番 500、`/realestate/customers/create` は同じ `_form.blade.php` でもアクセスタイミング次第で動いて見えてしまう） | Blade の Anonymous Component（`@props` のみで定義されたビュー型コンポーネント）の属性式に `&quot;` HTML エンティティを書くと、本番（PHP 8.3 + `view:cache` で全 Blade を precompile）経由ではデコードが漏れ、生のコンパイル済み PHP に literal `&quot;` が残って PHP 構文エラーになる。ローカル（lazy compile + PHP 8.5）では発火しないため気づけない | Blade コンポーネント属性で動的な route name を渡すときは `&quot;` を避け、PHP 文字列連結で組み立てる: `:cancel-url="route($department.'.customers.index')"`。route name が完全に静的ならシングルクォートで素直に書ける: `:cancel-url="route('tenant.customers.index')"`。残存検査は `grep -rn "&quot;" resources/views/` で一覧化。本番反映は `./deploy.sh`（`view:cache` 再生成）必須 |
| 22 | 一覧/詳細画面が本番でだけ 500 `TypeError: App\Enums\XxxStatus::tryFrom(): Argument #1 ($value) must be of type string\|int, App\Enums\XxxStatus given`。該当データ（例: 注文住宅の契約済レコード）が 1 件でも存在するときだけ発火し、空データのローカルでは該当行に到達せず素通りして気づけない（ローカル CLI も実 PHP 8.3 系なのでバージョン差ではなく **データ差**） | Eloquent の `casts()` で `'status' => XxxStatus::class` と enum キャストした属性は、読み出し時点で既に enum インスタンス。`tryFrom()` は `string\|int` のみ受け付けるため enum を渡すと TypeError。`Housing\HsContractListController::mapCustomOrderToDto` の `CustomOrderStatus::tryFrom($c->status)` が原因（2026-06-08 本番 500、commit c15812a1 で修正） | キャスト済み属性はそのまま使う: `$statusEnum = $model->status;`（null 可能性があれば `$model->status ? $model->status->label() : '—'` でガード）。生文字列からの変換が必要な場面だけ `tryFrom()` を使う。`whereIn('status', [Status::Foo->value, ...])` のようにクエリで `->value` を使うのは正しい。横展開検査: `grep -rn "::tryFrom(\$" app/` でキャスト属性への誤用を洗い出す |
| 23 | `x-data="someFunc({ data: @json($x) })"` のように `@json` を**二重引用符の `x-data="..."` 属性内**に入れると、Alpine が `... is not defined` を多発しコンポーネントが全く初期化されない（ボタン非表示・x-show/x-model/x-text 全滅。ただし x-cloak は外れるのでカード枠だけ表示され気づきにくい）。本番・ローカル両方で発火。例: 仕入れ案件/分譲地PJ 新規登録の原価管理（Excel取込・費用追加ボタンが出ない）、`tenant/repairs` の物件→区画絞り込み。原価管理は追加(7f0c9536)以来 create で一度も表示できていなかった | `@json`（= `json_encode($x, 15, 512)`）の `JSON_HEX_QUOT` は**文字列値の中の `"` のみ**エスケープし、**JSON の構造区切りの `"` は生のまま**出力する（`json_encode(['a'=>1],15)` → `{"a":1}`）。二重引用符の HTML 属性に入れると最初の構造 `"` で属性が途切れ、Alpine が受け取る式が `someFunc({ data: [{` のように壊れて評価不能 | ① データを `<script>` 内の named function で組み立て、属性は `x-data="someFunc()"` だけにする（詳細画面パターン。`_cost_section_form` を 511aa23b で修正）。または ② 属性に直接入れるなら `@json` でなく `{{ \Illuminate\Support\Js::from($x) }}`（`JSON.parse('...')` 形式で構造クォートも `"` 化＝属性安全。repairs を fffd9d47 で修正）。横展開検査: `grep -rn -A3 'x-data="' resources/views/ \| grep '@json'`。本番反映は `./deploy.sh`（view:cache 再生成）|
| 24 | 一覧画面のページネーションが番号付き（`< 1 2 3 … >`）でなく左右2ボタンだけになる（ZEALでは翻訳キー `pagination.previous` `pagination.next` が生表示）。本番・ローカル両方。`$paginator->hasPages()` が true（2ページ以上）の時だけ顕在化するので空データのローカルでは気づきにくい | デフォルトの `{{ $x->links() }}`（=`pagination::tailwind`）は番号付きを `hidden sm:flex` の desktop ブロックで描画するが、`sm:flex` `sm:hidden` 等のレスポンシブクラスは**プロジェクト Blade が使っておらず Tailwind JIT 未コンパイル**（フレームワーク vendor ビューはコンテンツスキャン対象外）。よって desktop ブロックが常時 hidden→`sm:hidden` のモバイル2ボタンだけ残る。ラベルも locale=ja で `lang/ja/pagination.php` 不在なら生キー表示（別件で lang ファイル追加 b26208e9） | `->links()` を使わず `resources/views/zeal/inquiries/index.blade.php` の**インライン番号付きマークアップ**を流用（`@foreach($x->getUrlRange(1, $x->lastPage()) as $page => $url)` ＋現在ページ `bg-emerald-600` ＋ `&lt;`/`&gt;`、コンテナ `flex justify-center gap-0.5`、ボタン `w-8 h-8 flex items-center justify-center rounded text-xs`）。2026-06-15 に旧 `->links()` 全10画面を統一（081ab5a7 + c8891f2c）しアプリ内 `->links()`=0。フィルタ保持はコントローラで `paginate()->withQueryString()` か Blade で `@php($x->withQueryString())` を番号付き出力の前に1回。横展開検査: `grep -rn "\->links()" resources/views/`。本番反映は `./deploy.sh`（view:cache 再生成）。⚠ **2026-07-15 追記**: 根本原因だった「`sm:flex` / `sm:hidden` 未コンパイル」は CSS 凍結（Bug #19）の副作用で、再ビルド後は repo 内にその文字列が在るかで決まる＝**もはや恒久的な事実ではない**。ただしアプリ内 `->links()` は 0 件でインライン番号付きが定着済みなので、**`->links()` に戻す理由は無い**（意匠も統一されている）。この行は「壊れるから避けろ」ではなく **規約**として読むこと |
| 25 | 不動産(realestate)の顧客詳細・新規登録後が本番でだけ 500 `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [realestate.customers.surveys.create] not defined.`。一覧は表示できるが「詳細」ボタンと「登録」ボタンで落ちる。買主データが無い空ローカルでは詳細(show)を一度も開かず素通りするため気づけない（2026-06-17 本番 500、commit 5b119f1d で修正） | `resources/views/buyers/show.blade.php` は housing/realestate **共通ビュー**で、「アンケートを追加」ボタンを `@forelse` の外＝**無条件**に `route("{$department}.customers.surveys.create", $buyer)` で描画する。だが `routes/web.php` のアンケート(`customers.surveys.*` 5ルート)は **housing にしか定義されておらず realestate に欠落**していた → ルート未定義で 500。surveys が空でも無条件ボタンは必ず実行されるので新規買主で即発火。store 成功後は show へリダイレクトするため「登録ボタンで 500」「詳細ボタンで 500」は同一画面。一覧(index)は surveys リンクが無く無事。`CustomerSurveyController` と登録画面(`buyers/_form.blade.php`)は両部署対応済み（housing 固有処理は `if($department==='housing')` でガード）で、**ルート定義だけが漏れていた**不整合 | realestate に surveys ルート5本(create/store/edit/update/destroy)を housing と対称・同一 middleware(create/store/edit/update=`role:executive,manager` / destroy=`role:executive`)で追加。横展開検査: 部署共通 Blade が `route("{$department}.xxx")` を無条件で呼ぶ箇所は、全部署にそのルートが定義されているか確認する `grep -rn 'route("{$department}' resources/views/`。空データのローカルで再現しない本番 500 は `php artisan view:cache` を有効化しローカルで本番同等にレンダリングして特定できる（Bug #22 と同じ「データが有る時だけ発火」型）。本番反映は `./deploy.sh`（route:cache 再生成）必須（git push だけでは不十分。Bug #20 と同じ）|
| 26 | テナント契約編集が本番でだけ全件 500 `ParseError: Unclosed '[' on line N does not match ')'`（compiled view 由来 `storage/framework/views/<hash>.php`）。一覧/詳細/新規作成は無事で **edit だけ**落ちる。空データのローカルは edit を一度もレンダリングしないため素通り。`view:cache` は「Blade templates cached successfully」と**成功表示するのに**発火する（2026-06-18、投資回収紐付けデプロイ直後、commit 56430ecf で修正） | `@json($x ? [` 改行 `'k' => $x->y->method(),` … `] : null)` のように **`@json()` に多行の配列リテラル（特に `->method()` 呼び出しを含む）** を渡すと、Blade の引数パーサ（括弧カウント）が誤って `json_encode(...)` の引数を途中で打ち切り、`[` 未閉じの壊れた PHP を生成する（例: `json_encode($x ? [ 'id'=>.., 'pl'=>$x->pattern->label()) ?>` と `total_amount`/`] : null` が欠落）。`view:cache` はコンパイル済み PHP を **lint しない**ため成功扱いになり、実レンダリングで `require` された瞬間に ParseError → 500。`php -l <.blade>` も Blade を緩く見るだけで検出不可。Bug #7（@json 内に関数）・Bug #23（x-data 属性内 @json）の同系統 | 配列は**コントローラで組み立て**、Blade には単一変数だけ渡す: `$currentInvestment = $contract->investment ? [...] : null;` → Blade `@json($currentInvestment)`（内部に括弧/角括弧が無く誤パース不能）。横展開検査: `grep -rn '@json(' resources/views/ \| grep '\['`（`?? []` や `old(...,[])` の単一行デフォルトは安全。**多行の配列リテラル＋メソッド呼び出しが危険**）。**検証は `view:cache` 成功では不十分** → コンパイル済みビューを必ず `php -l` する: `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null \|\| echo "INVALID: $f"; done && php artisan view:clear`。あるいは実データでレンダリングして確認（空ローカルは不可＝Bug #22/#25 と同型）。本番反映は `./deploy.sh`（view:cache 再生成）|

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
