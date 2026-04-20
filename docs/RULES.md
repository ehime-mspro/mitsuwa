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

## Postal Code APIs

- Forward lookup (zip → address): zipcloud API (frontend JS direct call)
- Reverse lookup (address → zip): HeartRails GeoAPI `getTowns` (server-side cURL, `reverseZipLookup` method)

## Google Maps

- Used for 仕入れ案件 and 分譲地PJ (geocoding + draggable/clickable pin)
- DB columns: `latitude`/`longitude` as `DECIMAL(10,7)`
- API key in `.env` as `GOOGLE_MAPS_API_KEY`

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
