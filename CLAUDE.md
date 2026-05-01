# ミツワ都市開発 経営管理システム（manage）

Laravel 12.x / PHP 8.5.4 / MySQL 8.0 / Blade + Tailwind CSS v4 (Vite build) + Alpine.js v3
URL: `https://domain/manage/public/` — 約185ルート

## Server Environment

- macOS. No PHP CLI available
- Migrations: `sudo mysql manage < file.sql`
- Cache clear: `sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2`
- sed: `sed -i ''` (BSD syntax, not GNU)

## CRITICAL CSS Rule

CSS is Vite-built (`build/assets/app-xxxx.css`), NOT CDN. Only classes already present in existing Blade files work. New Tailwind classes have no effect. Use `<style>` blocks or inline styles for any new styling. See @docs/RULES.md for full list of broken classes.

## CRITICAL Alpine.js Rules

- NEVER use `>` in `x-data` attributes (arrow functions `=>`). Browser parses `>` as HTML closing tag. Extract to `<script>` with named functions: `x-data="myFunc()"`
- NEVER use arrow functions in `<script>` tags. Use `function()` syntax only
- NEVER combine `style=` and `:style=` on the same element. Alpine's `:style` overwrites all static styles. Merge everything into a single `:style` binding
- Use `x-show` instead of `<template x-if>` inside `x-for` or SVG

## CRITICAL Blade Rules

- `@if/@else/@endif` MUST be multi-line. Single-line `@else<` causes Laravel 12 compile errors
- NEVER use functions inside `@json()` — no `number_format()`, no collection methods. Pre-format in Controller
- Attachments: use `@include('components.attachment-section', [...])`, not `<x-attachment-section>`
- **NEVER call `env()` directly inside Blade.** `php artisan config:cache` (run by deploy.sh) makes Blade-side `env()` return empty string. Always register the value in `config/services.php` (or other config file) and read it via `config('services.xxx.yyy')`. Past incident: Google Maps API key returned empty in production after config:cache, causing "For development purposes only" watermark. See @docs/RULES.md Bug #17

## Form Conventions

- Item spacing: `margin-bottom: 26px`
- Follow `customers/_form.blade.php` class structure: `form-input`, `gap-3`, `grid grid-cols-1 sm:grid-cols-2`
- NEVER put default `0` in monetary input fields
- Duplicate `name` attributes + `x-show` = data loss. Use hidden inputs with Alpine binding or `:disabled`

## Display Conventions

- Amounts: tax-excluded, yen suffix (`28,500,000円`), never `¥` prefix
- Gross profit color: `color: #047857; font-weight: 700`
- Building coverage / floor area ratio: integer display (no decimals)
- Badges: inline styles via `badgeStyle()` method (not Tailwind classes)
- Staff name: last name only (full name only when duplicate last names exist)
- Fiscal year: starts May 1 (May–April)

## Filter Bar (all list pages)

- `onchange="document.getElementById('filter-form').submit()"` for immediate filtering
- Clear button: `h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400`
- Pagination: 20 items/page

## Laravel-specific

- Department detection: `resolveDepartment()` via `request()->segment(1)`, NOT `defaults()`
- User model: no `deleted_at` column confirmed. Use `User::orderBy('name')` only
- Buyer model uses SoftDeletes → always `->withTrashed()` on relationships
- `re_projects` table column is `project_name` (not `name`)
- Default prefecture: 愛媛県
- Chart.js CDN: `cdn.jsdelivr.net` only (`cdnjs.cloudflare.com` is blocked)

## Architecture

See @docs/ARCHITECTURE.md for directory structure and module overview.

## Backlog

See @docs/BACKLOG.md for unimplemented features with full specifications.

## Technical Lessons

See @docs/RULES.md for detailed technical rules and past bug catalog.
