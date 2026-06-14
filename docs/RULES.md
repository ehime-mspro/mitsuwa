# Technical Rules & Bug Catalog

## Vite Build — Broken Tailwind Classes

These classes are NOT in the compiled CSS. Always use inline styles instead:

`gap-5`, `md:grid-cols-2`, `mt-auto`, `py-0.5`, `pb-2.5`, `items-end`, `border-red-600`,
`pl-9`, `pl-10`, `border-l-4 border-emerald-500`, custom `shadow-[]`

## Working Tailwind Classes (confirmed in build)

`form-input`, `gap-3`, `grid`, `grid-cols-1`, `sm:grid-cols-2`, `w-full`, `text-sm`,
`font-semibold`, `text-gray-700`, `bg-white`, `border`, `border-gray-200`, `border-gray-300`,
`rounded-md`, `rounded-lg`, `px-3`, `px-4`, `py-2`, `py-2.5`, `py-3`, `mb-4`, `mb-5`,
`flex`, `items-center`, `justify-between`, `justify-center`, `hover:bg-gray-50`,
`bg-emerald-600`, `hover:bg-emerald-700`, `text-white`, `text-emerald-600`,
`bg-emerald-50`, `border-emerald-200`, `text-emerald-800`, `text-red-500`,
`text-center`, `text-left`, `text-right`, `whitespace-nowrap`, `overflow-hidden`,
`h-9`, `text-xs`, `text-lg`, `font-bold`, `font-medium`

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
| 19 | Tailwind classes silently have no effect on a single page (e.g. `min-w-[140px]` on the search input, `hover:bg-red-100` / `hover:border-red-300` on a delete button) | Vite's JIT does NOT include arbitrary-value classes (`min-w-[140px]`, `bg-[#abc]`, etc.) and only emits the exact color/state combinations actually scanned at build time. Adding a new utility to a Blade after build does not auto-add it to `app-*.css` | Audit with `grep -oE "\\.<class>" public/build/assets/app-*.css` before assuming a class works. Replace arbitrary values with `style="min-width:140px"` and unsupported hover variants with `onmouseover/onmouseout` inline handlers. To check the gap quickly: extract all `class="..."` tokens from a Blade and grep each against the compiled CSS |
| 20 | ファイルアップロード時に「The route attachments/{type}/{id} could not be found.」で失敗する。例: `attachments/projects/11`, `attachments/ms_tenants/3`, `attachments/dad_projects/5` | `AttachmentController::TYPE_MAP` に新しい type を追加した際、`routes/web.php` の `Route::post('/attachments/{type}/{id}', ...)->where('type', '...')` の正規表現を更新し忘れた。`where` 正規表現に存在しない type は Laravel ルーターで弾かれ 404 になる（`procurements` 等の既存 type は通る） | `routes/web.php` の `where('type', '...')` 正規表現を `AttachmentController::TYPE_MAP` のキー全部と同期させる。新しい type を `TYPE_MAP` に追加するときは必ず `where` 正規表現にも同じ文字列を `\|` 区切りで追加すること。本番反映は `git push` だけでは不十分で `./deploy.sh` を実行する必要がある（rsync + `route:cache` 再生成） |
| 21 | `<x-form-actions :cancel-url="route(&quot;{$var}.xxx&quot;)" />` のように Blade コンポーネント属性式内に `&quot;` を含めたページが本番でだけ 500 `syntax error, unexpected token "&"` で落ちる。ローカルでは再現しない（例: `/housing/customers/create` が本番 500、`/realestate/customers/create` は同じ `_form.blade.php` でもアクセスタイミング次第で動いて見えてしまう） | Blade の Anonymous Component（`@props` のみで定義されたビュー型コンポーネント）の属性式に `&quot;` HTML エンティティを書くと、本番（PHP 8.3 + `view:cache` で全 Blade を precompile）経由ではデコードが漏れ、生のコンパイル済み PHP に literal `&quot;` が残って PHP 構文エラーになる。ローカル（lazy compile + PHP 8.5）では発火しないため気づけない | Blade コンポーネント属性で動的な route name を渡すときは `&quot;` を避け、PHP 文字列連結で組み立てる: `:cancel-url="route($department.'.customers.index')"`。route name が完全に静的ならシングルクォートで素直に書ける: `:cancel-url="route('tenant.customers.index')"`。残存検査は `grep -rn "&quot;" resources/views/` で一覧化。本番反映は `./deploy.sh`（`view:cache` 再生成）必須 |
| 22 | 一覧/詳細画面が本番でだけ 500 `TypeError: App\Enums\XxxStatus::tryFrom(): Argument #1 ($value) must be of type string\|int, App\Enums\XxxStatus given`。該当データ（例: 注文住宅の契約済レコード）が 1 件でも存在するときだけ発火し、空データのローカルでは該当行に到達せず素通りして気づけない（ローカル CLI も実 PHP 8.3 系なのでバージョン差ではなく **データ差**） | Eloquent の `casts()` で `'status' => XxxStatus::class` と enum キャストした属性は、読み出し時点で既に enum インスタンス。`tryFrom()` は `string\|int` のみ受け付けるため enum を渡すと TypeError。`Housing\HsContractListController::mapCustomOrderToDto` の `CustomOrderStatus::tryFrom($c->status)` が原因（2026-06-08 本番 500、commit c15812a1 で修正） | キャスト済み属性はそのまま使う: `$statusEnum = $model->status;`（null 可能性があれば `$model->status ? $model->status->label() : '—'` でガード）。生文字列からの変換が必要な場面だけ `tryFrom()` を使う。`whereIn('status', [Status::Foo->value, ...])` のようにクエリで `->value` を使うのは正しい。横展開検査: `grep -rn "::tryFrom(\$" app/` でキャスト属性への誤用を洗い出す |
| 23 | `x-data="someFunc({ data: @json($x) })"` のように `@json` を**二重引用符の `x-data="..."` 属性内**に入れると、Alpine が `... is not defined` を多発しコンポーネントが全く初期化されない（ボタン非表示・x-show/x-model/x-text 全滅。ただし x-cloak は外れるのでカード枠だけ表示され気づきにくい）。本番・ローカル両方で発火。例: 仕入れ案件/分譲地PJ 新規登録の原価管理（Excel取込・費用追加ボタンが出ない）、`tenant/repairs` の物件→区画絞り込み。原価管理は追加(7f0c9536)以来 create で一度も表示できていなかった | `@json`（= `json_encode($x, 15, 512)`）の `JSON_HEX_QUOT` は**文字列値の中の `"` のみ**エスケープし、**JSON の構造区切りの `"` は生のまま**出力する（`json_encode(['a'=>1],15)` → `{"a":1}`）。二重引用符の HTML 属性に入れると最初の構造 `"` で属性が途切れ、Alpine が受け取る式が `someFunc({ data: [{` のように壊れて評価不能 | ① データを `<script>` 内の named function で組み立て、属性は `x-data="someFunc()"` だけにする（詳細画面パターン。`_cost_section_form` を 511aa23b で修正）。または ② 属性に直接入れるなら `@json` でなく `{{ \Illuminate\Support\Js::from($x) }}`（`JSON.parse('...')` 形式で構造クォートも `"` 化＝属性安全。repairs を fffd9d47 で修正）。横展開検査: `grep -rn -A3 'x-data="' resources/views/ \| grep '@json'`。本番反映は `./deploy.sh`（view:cache 再生成）|

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
