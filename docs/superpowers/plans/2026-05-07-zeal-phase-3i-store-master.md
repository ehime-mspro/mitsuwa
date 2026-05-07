# ZEAL Phase 3-I 実装計画: 店舗マスタ管理 + 会員紐付け

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ZEAL モジュールに店舗マスタ管理画面 (Ajax CRUD) を追加し、会員編集フォームと CSV インポートで店舗紐付けが機能するようにする。

**Architecture:** Trainer Ajax CRUD のパターンを完全に踏襲した StoreController + 単一の `index.blade.php`。会員フォームに `store_id` セレクトを追加。CSV インポートでは「所属店舗」列を任意化し、未指定時は表示順最小の有効店舗にフォールバック。

**Tech Stack:** Laravel 12.x / Blade / Alpine.js v3 / Tailwind CSS v4 (Vite-built) / MySQL 8.0

**設計書:** [docs/superpowers/specs/2026-05-07-zeal-phase-3i-store-master-design.md](../specs/2026-05-07-zeal-phase-3i-store-master-design.md)

**重要前提:**
- macOS / PHP CLI なし → テスト = ブラウザ動作確認のみ
- SQL は phpMyAdmin で手動実行 (マイグレーションファイル使用しない / メモリ参照: feedback_migration.md)
- 新規 Tailwind クラス追加禁止 (Vite ビルド済み / CLAUDE.md)
- `<input type="date">` 使用可 (既存 `_form.blade.php` も使っている / CLAUDE.md "案C datepicker" は新規モック向けの推奨で既存パターンとは別)
- アロー関数 `=>` を `x-data` 内で使用禁止 / `<script>` 内も `function()` 構文のみ

---

## ファイル構造

### 新規作成

| ファイル | 責務 | 行数目安 |
|---|---|---|
| `database/sql/zeal_stores_seed.sql` | 初期 1件 INSERT | 10行 |
| `app/Http/Controllers/Zeal/StoreController.php` | Ajax CRUD コントローラー | 130行 |
| `resources/views/zeal/stores/index.blade.php` | 店舗マスタ管理画面 (一覧 + Ajax 編集) | 420行 |

### 変更

| ファイル | 変更箇所 | 内容 |
|---|---|---|
| `routes/web.php` | 1311行付近 (Trainer ルートの直後) | 店舗マスタ 4 ルート追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 127行 / 384行 (Trainer 直前) | 「店舗マスタ」リンク追加 |
| `resources/views/zeal/members/_form.blade.php` | 15行 / 167-180行 / 222行付近 | $valStore 追加 / 所属店舗 select 追加 |
| `app/Http/Controllers/Zeal/MemberController.php` | 8-11行 (use) / 124-134行 (edit) / 142-167行 (update) | $stores 渡し / store_id バリデーション |
| `app/Http/Controllers/Admin/ZealMemberImportController.php` | 13行 (use) / 37行 (columnMap) / 119-121行 (sample) / 142-258行 (preview) / 269-368行 (execute) | 店舗列・フォールバック追加 |
| `resources/views/admin/zeal-member-import/index.blade.php` | テンプレート列ヘルプ箇所 | 「所属店舗」列の説明追加 |
| `resources/views/admin/zeal-member-import/preview.blade.php` | プレビュー表 | 「所属店舗」列追加 |

---

## Task 1: zeal_stores 初期データ SQL ファイル作成

**Files:**
- Create: `database/sql/zeal_stores_seed.sql`

- [ ] **Step 1: SQL ファイル作成**

ファイル: `database/sql/zeal_stores_seed.sql`

```sql
-- ============================================================
-- ZEAL 店舗マスタ 初期データ
-- 実行先: phpMyAdmin から DB `manage` に対して実行
-- 冪等性: name で重複チェックしてから INSERT
-- ============================================================

INSERT INTO `zeal_stores`
    (`name`, `address`, `phone`, `open_date`, `display_order`, `active`, `created_at`, `updated_at`)
SELECT
    'ZEAL BOXING FITNESS 松山市駅前店',
    '愛媛県松山市湊町6-2-2 ミツワ市駅西ビル2階',
    NULL,
    '2025-10-17',
    1,
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `zeal_stores`
    WHERE `name` = 'ZEAL BOXING FITNESS 松山市駅前店'
);
```

- [ ] **Step 2: 動作確認 — phpMyAdmin で SQL 実行はユーザー側で行う想定**

このタスクではファイル作成のみ。動作確認は Task 8 でユーザーに phpMyAdmin で実行してもらう。

- [ ] **Step 3: コミット**

```bash
git add database/sql/zeal_stores_seed.sql
git commit -m "ZEAL Phase 3-I: zeal_stores 初期データ SQL を追加"
```

---

## Task 2: StoreController (Ajax CRUD) 作成

**Files:**
- Create: `app/Http/Controllers/Zeal/StoreController.php`

- [ ] **Step 1: StoreController を作成**

ファイル: `app/Http/Controllers/Zeal/StoreController.php`

```php
<?php

namespace App\Http\Controllers\Zeal;

use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZEAL 店舗マスタ Ajax CRUD コントローラー
 *
 * 一覧はページ内管理（1画面）。追加・更新・削除はすべて Ajax で実行する。
 * Trainer マスタ (TrainerController) と同パターン。
 */
class StoreController extends Controller
{
    /**
     * 店舗一覧（マスタ管理ページ）
     */
    public function index()
    {
        $stores = ZealStore::orderBy('display_order')->orderBy('id')->get();

        // Alpine.js 用 JSON（@json() 内で関数呼び出ししないよう事前整形）
        $storesJson = $stores->map(function ($s) {
            return [
                'id'            => $s->id,
                'name'          => $s->name,
                'address'       => $s->address ?? '',
                'phone'         => $s->phone ?? '',
                'open_date'     => $s->open_date ? $s->open_date->format('Y-m-d') : '',
                'display_order' => $s->display_order,
                'active'        => (bool) $s->active,
            ];
        })->values();

        // 新規追加時のデフォルト表示順（現在の最大値 + 1）
        $nextOrder = ($stores->max('display_order') ?? 0) + 1;

        return view('zeal.stores.index', compact('storesJson', 'nextOrder'));
    }

    /**
     * 店舗追加（Ajax）
     * Route: POST /zeal/stores
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'address'       => 'nullable|string|max:300',
            'phone'         => 'nullable|string|max:20',
            'open_date'     => 'nullable|date',
            'display_order' => 'required|integer|min:0|max:9999',
            'active'        => 'boolean',
        ]);

        $validated['active']    = $request->boolean('active', true);
        $validated['address']   = $validated['address'] ?? null;
        $validated['phone']     = $validated['phone'] ?? null;
        $validated['open_date'] = $validated['open_date'] ?? null;

        $store = ZealStore::create($validated);

        return response()->json([
            'success' => true,
            'store' => [
                'id'            => $store->id,
                'name'          => $store->name,
                'address'       => $store->address ?? '',
                'phone'         => $store->phone ?? '',
                'open_date'     => $store->open_date ? $store->open_date->format('Y-m-d') : '',
                'display_order' => $store->display_order,
                'active'        => (bool) $store->active,
            ],
            'message' => '「' . $store->name . '」を追加しました。',
        ]);
    }

    /**
     * 店舗更新（Ajax）
     * Route: PUT /zeal/stores/{store}
     */
    public function update(Request $request, ZealStore $store): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'address'       => 'nullable|string|max:300',
            'phone'         => 'nullable|string|max:20',
            'open_date'     => 'nullable|date',
            'display_order' => 'required|integer|min:0|max:9999',
            'active'        => 'boolean',
        ]);

        $validated['active']    = $request->boolean('active');
        $validated['address']   = $validated['address'] ?? null;
        $validated['phone']     = $validated['phone'] ?? null;
        $validated['open_date'] = $validated['open_date'] ?? null;

        $store->update($validated);

        return response()->json([
            'success' => true,
            'store' => [
                'id'            => $store->id,
                'name'          => $store->name,
                'address'       => $store->address ?? '',
                'phone'         => $store->phone ?? '',
                'open_date'     => $store->open_date ? $store->open_date->format('Y-m-d') : '',
                'display_order' => $store->display_order,
                'active'        => (bool) $store->active,
            ],
            'message' => '「' . $store->name . '」を更新しました。',
        ]);
    }

    /**
     * 店舗削除（Ajax）
     * Route: DELETE /zeal/stores/{store}
     * 所属会員がいる店舗は削除不可（無効化してください）
     */
    public function destroy(ZealStore $store): JsonResponse
    {
        // 所属会員がいる場合は削除不可
        if (ZealMember::where('store_id', $store->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => '「' . $store->name . '」には所属会員がいるため削除できません。「無効」に変更してご利用ください。',
            ], 422);
        }

        $name = $store->name;
        $store->delete();

        return response()->json([
            'success' => true,
            'message' => '「' . $name . '」を削除しました。',
        ]);
    }
}
```

- [ ] **Step 2: ZealStore モデルに `open_date` の date キャストがあるか確認**

```bash
grep -nE "open_date|protected \$casts|protected \$dates" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/app/Models/ZealStore.php
```

期待: `open_date` が `casts` 配列内に `'open_date' => 'date'` で存在すること。
もし存在しなければ次の Step 3 で追加する。

- [ ] **Step 3: 必要なら ZealStore モデルに casts を追加**

`open_date` のキャストがない場合のみ、`app/Models/ZealStore.php` の class 内に以下を追加:

```php
protected $casts = [
    'open_date' => 'date',
    'active'    => 'boolean',
];
```

すでに `$casts` がある場合は `'open_date' => 'date'` を追記する。

- [ ] **Step 4: コミット**

```bash
git add app/Http/Controllers/Zeal/StoreController.php
# Step 3 で casts を変更した場合は ZealStore.php もステージ
git status  # 変更ファイル確認
git commit -m "ZEAL Phase 3-I: StoreController (Ajax CRUD) を追加"
```

---

## Task 3: 店舗マスタ管理 Blade ビュー作成

**Files:**
- Create: `resources/views/zeal/stores/index.blade.php`

- [ ] **Step 1: views ディレクトリ確認・必要なら作成**

```bash
ls /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/resources/views/zeal/stores/ 2>/dev/null || mkdir -p /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/resources/views/zeal/stores/
```

- [ ] **Step 2: index.blade.php を作成**

ファイル: `resources/views/zeal/stores/index.blade.php`

```blade
@extends('layouts.app')

@section('title', '店舗マスタ')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">店舗マスタ</span>
@endsection

@section('content')

<style>
    .zeal-badge {
        display: inline-flex; align-items: center;
        padding: 2px 10px; border-radius: 9999px;
        font-size: 11px; font-weight: 600; white-space: nowrap;
    }
    .badge-active   { background: #d1fae5; color: #065f46; }
    .badge-inactive { background: #f3f4f6; color: #6b7280; }
    /* 住所列の折り返し */
    .store-address-cell {
        white-space: normal;
        word-break: break-word;
        max-width: 300px;
    }
</style>

<div x-data="zealStoreManager()">

    {{-- トースト通知 --}}
    <div x-show="message" x-cloak
         :style="messageType === 'success'
             ? 'background:#d1fae5; border:1px solid #6ee7b7; color:#065f46;'
             : 'background:#fee2e2; border:1px solid #fca5a5; color:#991b1b;'"
         style="display:flex; align-items:center; gap:8px; padding:12px 16px; margin-bottom:16px; border-radius:8px; font-size:14px;"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <svg x-show="messageType === 'success'" style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <svg x-show="messageType === 'error'" style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span x-text="message"></span>
    </div>

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">店舗マスタ</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <button type="button" x-show="!adding" @click="startAdd()"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                店舗を追加
            </button>
        @endif
    </div>

    {{-- ========== 新規追加フォーム ========== --}}
    @if(auth()->user()->role->isManagerOrAbove())
        <div x-show="adding" x-cloak style="margin-bottom: 20px;">
            <div class="bg-white border border-emerald-300 rounded-lg p-5">
                <div style="font-size: 14px; font-weight: 700; color: #065f46; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
                    店舗を追加
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 14px;">
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            店舗名<span style="color:#dc2626; font-size:11px; margin-left:4px; font-weight:700;">*必須</span>
                        </label>
                        <input type="text" x-model="newName" placeholder="例: ZEAL BOXING FITNESS ◯◯店"
                               maxlength="100"
                               @keydown.enter="submitAdd()"
                               @keydown.escape="cancelAdd()"
                               class="form-input w-full"
                               x-ref="newNameInput">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            電話
                        </label>
                        <input type="tel" x-model="newPhone" placeholder="例: 089-123-4567"
                               maxlength="20"
                               class="form-input w-full">
                    </div>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                        住所
                    </label>
                    <input type="text" x-model="newAddress" placeholder="例: 愛媛県松山市湊町6-2-2"
                           maxlength="300"
                           class="form-input w-full">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 14px;">
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            開店日
                        </label>
                        <input type="date" x-model="newOpenDate"
                               class="form-input w-full">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            表示順
                        </label>
                        <input type="number" x-model.number="newOrder" min="0" max="9999"
                               inputmode="numeric"
                               class="form-input w-full">
                        <div style="font-size:11px; color:#9ca3af; margin-top:3px;">小さい値ほど先に表示</div>
                    </div>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:#374151;">
                        <input type="checkbox" x-model="newActive"
                               style="width:16px; height:16px; accent-color:#059669; cursor:pointer;">
                        有効（無効にするとプルダウンに表示されません）
                    </label>
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" @click="cancelAdd()"
                            style="padding:8px 16px; border:1px solid #d1d5db; border-radius:6px; background:white; font-size:13px; font-weight:600; color:#374151; cursor:pointer;">
                        キャンセル
                    </button>
                    <button type="button" @click="submitAdd()" :disabled="saving"
                            style="display:inline-flex; align-items:center; gap:6px; padding:8px 20px; background:#059669; color:white; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">
                        <span x-show="!saving">追加する</span>
                        <span x-show="saving">追加中...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== 店舗一覧テーブル ========== --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="w-full border-collapse" style="min-width: 900px;">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="width: 22%;">店舗名</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">住所</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">電話</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">開店日</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">表示順</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="store in stores" :key="store.id">
                    <tr class="hover:bg-gray-50 transition-colors border-b border-gray-200"
                        :class="store.active ? '' : 'opacity-60'">
                        {{-- 店舗名 --}}
                        <td class="px-4 py-3">
                            <input x-show="editingId === store.id"
                                   type="text" x-model="editingName"
                                   maxlength="100"
                                   @keydown.enter="submitEdit()"
                                   @keydown.escape="cancelEdit()"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm font-semibold text-gray-900" x-text="store.name"></span>
                        </td>
                        {{-- 住所 --}}
                        <td class="px-4 py-3 store-address-cell">
                            <input x-show="editingId === store.id"
                                   type="text" x-model="editingAddress"
                                   maxlength="300"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.address || '-'"></span>
                        </td>
                        {{-- 電話 --}}
                        <td class="px-4 py-3 whitespace-nowrap">
                            <input x-show="editingId === store.id"
                                   type="tel" x-model="editingPhone"
                                   maxlength="20"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.phone || '-'"></span>
                        </td>
                        {{-- 開店日 --}}
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <input x-show="editingId === store.id"
                                   type="date" x-model="editingOpenDate"
                                   class="form-input"
                                   style="width: 150px; margin: 0 auto;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.open_date || '-'"></span>
                        </td>
                        {{-- 表示順 --}}
                        <td class="px-4 py-3 text-center">
                            <input x-show="editingId === store.id"
                                   type="number" x-model.number="editingOrder"
                                   min="0" max="9999" inputmode="numeric"
                                   class="form-input text-center"
                                   style="width:80px; margin:0 auto;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.display_order"></span>
                        </td>
                        {{-- 状態 --}}
                        <td class="px-4 py-3 text-center">
                            <label x-show="editingId === store.id"
                                   style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; color:#374151;">
                                <input type="checkbox" x-model="editingActive"
                                       style="width:14px; height:14px; accent-color:#059669; cursor:pointer;">
                                有効
                            </label>
                            <span x-show="editingId !== store.id"
                                  class="zeal-badge"
                                  :class="store.active ? 'badge-active' : 'badge-inactive'"
                                  x-text="store.active ? '有効' : '無効'"></span>
                        </td>
                        {{-- 操作 --}}
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            {{-- 編集中のボタン --}}
                            <div x-show="editingId === store.id" style="display:flex; gap:6px; justify-content:center;">
                                <button type="button" @click="submitEdit()" :disabled="saving"
                                        style="font-size:12px; font-weight:600; color:#065f46; padding:4px 12px; border:1px solid #6ee7b7; border-radius:4px; background:#d1fae5; cursor:pointer;">
                                    <span x-show="!saving">保存</span>
                                    <span x-show="saving">...</span>
                                </button>
                                <button type="button" @click="cancelEdit()"
                                        style="font-size:12px; font-weight:600; color:#6b7280; padding:4px 12px; border:1px solid #d1d5db; border-radius:4px; background:white; cursor:pointer;">
                                    取消
                                </button>
                            </div>
                            {{-- 通常のボタン --}}
                            <div x-show="editingId !== store.id" style="display:flex; gap:6px; justify-content:center;">
                                @if(auth()->user()->role->isManagerOrAbove())
                                    <button type="button" @click="startEdit(store)"
                                            style="font-size:12px; font-weight:600; color:#065f46; padding:4px 12px; border:1px solid #6ee7b7; border-radius:4px; background:#d1fae5; cursor:pointer;">
                                        編集
                                    </button>
                                @endif
                                @if(auth()->user()->role->isExecutive())
                                    <button type="button"
                                            @click="deleteStore(store)"
                                            style="font-size:12px; font-weight:600; color:#dc2626; padding:4px 12px; border:1px solid #fca5a5; border-radius:4px; background:#fee2e2; cursor:pointer;">
                                        削除
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                </template>

                {{-- 空のとき --}}
                <tr x-show="stores.length === 0">
                    <td colspan="7" class="px-5 py-10 text-center text-sm text-gray-400">
                        店舗が登録されていません。
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 12px; font-size: 12px; color: #6b7280;">
        ※ 所属会員がいる店舗は削除できません。利用中止の場合は「無効」に変更してください。
    </div>

</div>

<script>
/**
 * ZEAL 店舗マスタ管理
 * Alpine.js CLAUDE.md 規約: 関数名を指定した named function で定義（arrow function 禁止）
 */
function zealStoreManager() {
    return {
        stores: @json($storesJson),
        adding: false,
        newName: '',
        newAddress: '',
        newPhone: '',
        newOpenDate: '',
        newOrder: {{ $nextOrder }},
        newActive: true,
        editingId: null,
        editingName: '',
        editingAddress: '',
        editingPhone: '',
        editingOpenDate: '',
        editingOrder: 0,
        editingActive: true,
        saving: false,
        message: '',
        messageType: 'success',
        _messageTimer: null,

        /** 追加フォームを開く */
        startAdd: function () {
            this.cancelEdit();
            this.adding = true;
            this.$nextTick(function () {
                var el = this.$refs.newNameInput;
                if (el) { el.focus(); }
            }.bind(this));
        },

        /** 追加フォームをキャンセル */
        cancelAdd: function () {
            this.adding = false;
            this.newName = '';
            this.newAddress = '';
            this.newPhone = '';
            this.newOpenDate = '';
        },

        /** 店舗を追加（Ajax POST）*/
        submitAdd: function () {
            var self = this;
            var name = this.newName.trim();
            if (!name) {
                self.showMessage('店舗名を入力してください。', 'error');
                return;
            }
            self.saving = true;
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('name', name);
            body.append('address', self.newAddress || '');
            body.append('phone', self.newPhone || '');
            body.append('open_date', self.newOpenDate || '');
            body.append('display_order', self.newOrder);
            body.append('active', self.newActive ? '1' : '0');

            fetch('{{ route("zeal.stores.store") }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.saving = false;
                if (data.success) {
                    self.stores.push(data.store);
                    self.stores.sort(function (a, b) {
                        return a.display_order - b.display_order || a.id - b.id;
                    });
                    self.newName = '';
                    self.newAddress = '';
                    self.newPhone = '';
                    self.newOpenDate = '';
                    self.newOrder = Math.max.apply(null, self.stores.map(function (s) { return s.display_order; })) + 1;
                    self.adding = false;
                    self.showMessage(data.message, 'success');
                } else {
                    self.showMessage(data.message || '追加に失敗しました。', 'error');
                }
            })
            .catch(function () {
                self.saving = false;
                self.showMessage('通信エラーが発生しました。', 'error');
            });
        },

        /** 編集モードを開始 */
        startEdit: function (store) {
            this.cancelAdd();
            this.editingId       = store.id;
            this.editingName     = store.name;
            this.editingAddress  = store.address || '';
            this.editingPhone    = store.phone || '';
            this.editingOpenDate = store.open_date || '';
            this.editingOrder    = store.display_order;
            this.editingActive   = store.active;
        },

        /** 編集をキャンセル */
        cancelEdit: function () {
            this.editingId = null;
        },

        /** 店舗を更新（Ajax PUT）*/
        submitEdit: function () {
            var self = this;
            var name = this.editingName.trim();
            if (!name) {
                self.showMessage('店舗名を入力してください。', 'error');
                return;
            }
            self.saving = true;
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('_method', 'PUT');
            body.append('name', name);
            body.append('address', self.editingAddress || '');
            body.append('phone', self.editingPhone || '');
            body.append('open_date', self.editingOpenDate || '');
            body.append('display_order', self.editingOrder);
            body.append('active', self.editingActive ? '1' : '0');

            fetch('/zeal/stores/' + self.editingId, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.saving = false;
                if (data.success) {
                    var idx = self.stores.findIndex(function (s) { return s.id === data.store.id; });
                    if (idx !== -1) {
                        self.stores.splice(idx, 1, data.store);
                        self.stores.sort(function (a, b) {
                            return a.display_order - b.display_order || a.id - b.id;
                        });
                    }
                    self.editingId = null;
                    self.showMessage(data.message, 'success');
                } else {
                    self.showMessage(data.message || '更新に失敗しました。', 'error');
                }
            })
            .catch(function () {
                self.saving = false;
                self.showMessage('通信エラーが発生しました。', 'error');
            });
        },

        /** 店舗を削除（Ajax DELETE）*/
        deleteStore: function (store) {
            var self = this;
            if (!confirm('「' + store.name + '」を削除しますか？\n所属会員がいる場合は削除できません。')) {
                return;
            }
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('_method', 'DELETE');

            fetch('/zeal/stores/' + store.id, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    self.stores = self.stores.filter(function (s) { return s.id !== store.id; });
                    self.showMessage(data.message, 'success');
                } else {
                    self.showMessage(data.message || '削除に失敗しました。', 'error');
                }
            })
            .catch(function () {
                self.showMessage('通信エラーが発生しました。', 'error');
            });
        },

        /** トースト通知を表示（5秒後自動消去）*/
        showMessage: function (msg, type) {
            var self = this;
            self.message = msg;
            self.messageType = type || 'success';
            if (self._messageTimer) { clearTimeout(self._messageTimer); }
            self._messageTimer = setTimeout(function () { self.message = ''; }, 5000);
        },
    };
}
</script>

@endsection
```

- [ ] **Step 3: コミット**

```bash
git add resources/views/zeal/stores/index.blade.php
git commit -m "ZEAL Phase 3-I: 店舗マスタ管理画面 (Ajax CRUD) を追加"
```

---

## Task 4: ルート追加

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Trainer ルートの位置を特定**

```bash
grep -n "zeal.trainers" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/routes/web.php
```

期待: 3〜5 行ヒット (1301-1311 付近)。

- [ ] **Step 2: 既存ルートに store ルートを追加**

`routes/web.php` の Trainer ルートブロックの**直後**に以下を挿入する。

具体的には、現在の構造:
```php
        // 行1311付近
        Route::delete('/trainers/{trainer}', ...)->name('zeal.trainers.destroy');
```
の直後 (delete ルートの閉じ ; の次の行) に以下を追加:

```php

        // 店舗マスタ
        Route::get('/stores', [\App\Http\Controllers\Zeal\StoreController::class, 'index'])
            ->name('zeal.stores.index');
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/stores', [\App\Http\Controllers\Zeal\StoreController::class, 'store'])
                ->name('zeal.stores.store');
            Route::put('/stores/{store}', [\App\Http\Controllers\Zeal\StoreController::class, 'update'])
                ->name('zeal.stores.update');
        });
        Route::delete('/stores/{store}', [\App\Http\Controllers\Zeal\StoreController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('zeal.stores.destroy');
```

挿入時の注意: インデント (8 スペース) を周囲と合わせる。Trainer ブロックと同じ。

- [ ] **Step 3: ルート追加を確認**

```bash
grep -nE "zeal\.stores" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/routes/web.php
```

期待: 4 件ヒット (index/store/update/destroy)。

- [ ] **Step 4: ルートキャッシュクリア (ユーザー側で実行)**

ユーザーに以下を提示 (本番ではない、ローカル):

```bash
# Apache 経由なので routes/web.php を編集しただけで反映される。
# キャッシュクリアが必要な場合のみ:
sudo rm -f /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/bootstrap/cache/routes-v7.php
```

- [ ] **Step 5: コミット**

```bash
git add routes/web.php
git commit -m "ZEAL Phase 3-I: 店舗マスタの 4 ルート (zeal.stores.*) を追加"
```

---

## Task 5: サイドバーに店舗マスタリンク追加

**Files:**
- Modify: `resources/views/layouts/partials/sidebar.blade.php`

- [ ] **Step 1: サイドバーで Trainer リンクの位置を確認**

```bash
grep -nE "zeal/trainers" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/resources/views/layouts/partials/sidebar.blade.php
```

期待: 2 件 (manager パネル: 127行付近、staff パネル: 384行付近)。

- [ ] **Step 2: 1 箇所目 (127行付近) にリンク追加**

`resources/views/layouts/partials/sidebar.blade.php` の 127 行目 (`<x-sidebar-item :href="url('/zeal/trainers')" ...>`) **の直前**に以下を挿入:

```blade
            <x-sidebar-item :href="url('/zeal/stores')" label="店舗マスタ" :active="request()->is('zeal/stores*')" />
```

- [ ] **Step 3: 2 箇所目 (384行付近) にも同じリンク追加**

384 行目の `<x-sidebar-item :href="url('/zeal/trainers')" ...>` **の直前**にも同じ行を挿入する:

```blade
            <x-sidebar-item :href="url('/zeal/stores')" label="店舗マスタ" :active="request()->is('zeal/stores*')" />
```

- [ ] **Step 4: 追加後の確認**

```bash
grep -nE "zeal/stores" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/resources/views/layouts/partials/sidebar.blade.php
```

期待: 2 件ヒット。

- [ ] **Step 5: ビューキャッシュクリア (ユーザー側で実行)**

```bash
sudo rm -f /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/storage/framework/views/*.php
```

- [ ] **Step 6: コミット**

```bash
git add resources/views/layouts/partials/sidebar.blade.php
git commit -m "ZEAL Phase 3-I: サイドバーに「店舗マスタ」リンクを追加"
```

---

## Task 6: 会員編集フォームに「所属店舗」セレクト追加

**Files:**
- Modify: `app/Http/Controllers/Zeal/MemberController.php`
- Modify: `resources/views/zeal/members/_form.blade.php`

### Step 1: MemberController を変更

- [ ] **Step 1a: use 文に ZealStore を追加**

ファイル: `app/Http/Controllers/Zeal/MemberController.php`
変更箇所: 10行目 (`use App\Models\ZealTrainer;`) の直後

old:
```php
use App\Models\ZealTrainer;
use App\Support\Settings;
```

new:
```php
use App\Models\ZealStore;
use App\Models\ZealTrainer;
use App\Support\Settings;
```

(アルファベット順を維持)

- [ ] **Step 1b: edit() メソッドに $stores を追加**

ファイル: `app/Http/Controllers/Zeal/MemberController.php`
変更箇所: 124-134 行目 (edit メソッド全体)

old:
```php
    public function edit(ZealMember $member)
    {
        $member->load(['currentPlan', 'trainer', 'store']);

        $trainers = ZealTrainer::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return view('zeal.members.edit', compact('member', 'trainers'));
    }
```

new:
```php
    public function edit(ZealMember $member)
    {
        $member->load(['currentPlan', 'trainer', 'store']);

        $trainers = ZealTrainer::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $stores = ZealStore::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return view('zeal.members.edit', compact('member', 'trainers', 'stores'));
    }
```

- [ ] **Step 1c: update() メソッドのバリデーションと整形に store_id を追加**

ファイル: `app/Http/Controllers/Zeal/MemberController.php`
変更箇所: 142-167 行目 (validate と整形ブロック)

`'name' => 'required|string|max:50',` の直前に `'store_id' => 'required|integer|exists:zeal_stores,id',` を挿入。
さらに整形ブロックには store_id を入れる必要はない (required なので必ず存在する)。

old:
```php
        $validated = $request->validate([
            'name'               => 'required|string|max:50',
            'name_kana'          => 'required|string|max:100',
            'gender'             => 'required|string|in:male,female,other',
            'birthday'           => 'nullable|date',
            'phone'              => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:200',
            'postal_code'        => 'nullable|string|max:8',
            'address'            => 'nullable|string|max:200',
            'trainer_id'         => 'nullable|exists:zeal_trainers,id',
            'acquisition_source' => 'nullable|string',
            'purpose'            => 'nullable|string',
            'memo'               => 'nullable|string|max:1000',
        ]);
```

new:
```php
        $validated = $request->validate([
            'store_id'           => 'required|integer|exists:zeal_stores,id',
            'name'               => 'required|string|max:50',
            'name_kana'          => 'required|string|max:100',
            'gender'             => 'required|string|in:male,female,other',
            'birthday'           => 'nullable|date',
            'phone'              => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:200',
            'postal_code'        => 'nullable|string|max:8',
            'address'            => 'nullable|string|max:200',
            'trainer_id'         => 'nullable|exists:zeal_trainers,id',
            'acquisition_source' => 'nullable|string',
            'purpose'            => 'nullable|string',
            'memo'               => 'nullable|string|max:1000',
        ]);
```

### Step 2: `_form.blade.php` に所属店舗 select を追加

- [ ] **Step 2a: $valStore 変数を @php ブロックに追加**

ファイル: `resources/views/zeal/members/_form.blade.php`
変更箇所: 6-19 行目 (@php ブロック)

old:
```php
@php
    $valName      = old('name',      $member->name      ?? '');
    $valKana      = old('name_kana', $member->name_kana ?? '');
    $valGender    = old('gender',    $member->gender?->value ?? '');
    $valBirthday  = old('birthday',  $member->birthday  ? $member->birthday->format('Y-m-d') : '');
    $valPhone     = old('phone',     $member->phone     ?? '');
    $valEmail     = old('email',     $member->email     ?? '');
    $valPostal    = old('postal_code', $member->postal_code ?? '');
    $valAddress   = old('address',   $member->address   ?? '');
    $valTrainer   = old('trainer_id', $member->trainer_id ?? '');
    $valAcq       = old('acquisition_source', $member->acquisition_source?->value ?? '');
    $valPurpose   = old('purpose',   $member->purpose?->value ?? '');
    $valMemo      = old('memo',      $member->memo      ?? '');
@endphp
```

new:
```php
@php
    $valStore     = old('store_id',  $member->store_id  ?? '');
    $valName      = old('name',      $member->name      ?? '');
    $valKana      = old('name_kana', $member->name_kana ?? '');
    $valGender    = old('gender',    $member->gender?->value ?? '');
    $valBirthday  = old('birthday',  $member->birthday  ? $member->birthday->format('Y-m-d') : '');
    $valPhone     = old('phone',     $member->phone     ?? '');
    $valEmail     = old('email',     $member->email     ?? '');
    $valPostal    = old('postal_code', $member->postal_code ?? '');
    $valAddress   = old('address',   $member->address   ?? '');
    $valTrainer   = old('trainer_id', $member->trainer_id ?? '');
    $valAcq       = old('acquisition_source', $member->acquisition_source?->value ?? '');
    $valPurpose   = old('purpose',   $member->purpose?->value ?? '');
    $valMemo      = old('memo',      $member->memo      ?? '');
@endphp
```

- [ ] **Step 2b: 「担当・集客情報」カードの先頭に「所属店舗」select を追加**

ファイル: `resources/views/zeal/members/_form.blade.php`
変更箇所: 162-196 行目 (担当・集客情報カード)

old (162-196行):
```blade
    {{-- ========== 担当・集客 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">担当・集客情報</div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 16px;">
            {{-- 担当トレーナー --}}
            <div>
                <label class="zeal-form-label" for="trainer_id">
                    担当トレーナー<span class="optional">任意</span>
                </label>
                <select id="trainer_id" name="trainer_id" class="form-input w-full" style="margin-bottom: 0;">
                    <option value="">未設定</option>
                    @foreach($trainers as $trainer)
                        <option value="{{ $trainer->id }}" {{ $valTrainer == $trainer->id ? 'selected' : '' }}>
                            {{ $trainer->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- 集客チャネル --}}
            <div>
                <label class="zeal-form-label" for="acquisition_source">
                    集客チャネル<span class="optional">任意</span>
                </label>
                <select id="acquisition_source" name="acquisition_source" class="form-input w-full" style="margin-bottom: 0;">
                    <option value="">未設定</option>
                    @foreach(\App\Enums\ZealAcquisitionSource::cases() as $src)
                        <option value="{{ $src->value }}" {{ $valAcq === $src->value ? 'selected' : '' }}>
                            {{ $src->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
```

new:
```blade
    {{-- ========== 担当・集客 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">担当・集客情報</div>

        {{-- 所属店舗 --}}
        <div style="margin-bottom: 16px;">
            <label class="zeal-form-label" for="store_id">
                所属店舗<span class="required">*必須</span>
            </label>
            @if($stores->isEmpty())
                <select id="store_id" name="store_id" class="form-input w-full" style="margin-bottom: 0;" disabled>
                    <option value="">店舗マスタが未登録です</option>
                </select>
                <div style="margin-top: 6px; padding: 8px 12px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; font-size: 12px; color: #92400e;">
                    会員を保存するには、先に <a href="{{ route('zeal.stores.index') }}" style="color: #92400e; text-decoration: underline; font-weight: 600;">店舗マスタ</a> を 1 件以上登録してください。
                </div>
            @else
                <select id="store_id" name="store_id" class="form-input w-full" style="margin-bottom: 0;" required>
                    <option value="">選択してください</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" {{ $valStore == $store->id ? 'selected' : '' }}>
                            {{ $store->name }}
                        </option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 16px;">
            {{-- 担当トレーナー --}}
            <div>
                <label class="zeal-form-label" for="trainer_id">
                    担当トレーナー<span class="optional">任意</span>
                </label>
                <select id="trainer_id" name="trainer_id" class="form-input w-full" style="margin-bottom: 0;">
                    <option value="">未設定</option>
                    @foreach($trainers as $trainer)
                        <option value="{{ $trainer->id }}" {{ $valTrainer == $trainer->id ? 'selected' : '' }}>
                            {{ $trainer->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- 集客チャネル --}}
            <div>
                <label class="zeal-form-label" for="acquisition_source">
                    集客チャネル<span class="optional">任意</span>
                </label>
                <select id="acquisition_source" name="acquisition_source" class="form-input w-full" style="margin-bottom: 0;">
                    <option value="">未設定</option>
                    @foreach(\App\Enums\ZealAcquisitionSource::cases() as $src)
                        <option value="{{ $src->value }}" {{ $valAcq === $src->value ? 'selected' : '' }}>
                            {{ $src->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
```

- [ ] **Step 3: ビューキャッシュクリア (ユーザー側で実行)**

```bash
sudo rm -f /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/storage/framework/views/*.php
```

- [ ] **Step 4: コミット**

```bash
git add app/Http/Controllers/Zeal/MemberController.php resources/views/zeal/members/_form.blade.php
git commit -m "ZEAL Phase 3-I: 会員編集フォームに「所属店舗」セレクトを追加"
```

---

## Task 7: CSV インポートに「所属店舗」列を追加 (案Z)

**Files:**
- Modify: `app/Http/Controllers/Admin/ZealMemberImportController.php`
- Modify: `resources/views/admin/zeal-member-import/index.blade.php`
- Modify: `resources/views/admin/zeal-member-import/preview.blade.php`

### Step 1: ZealMemberImportController を変更

- [ ] **Step 1a: use 文に ZealStore を追加**

ファイル: `app/Http/Controllers/Admin/ZealMemberImportController.php`
変更箇所: 13 行目 (`use App\Models\ZealTrainer;`) の直前

old:
```php
use App\Models\ZealPlan;
use App\Models\ZealTrainer;
```

new:
```php
use App\Models\ZealPlan;
use App\Models\ZealStore;
use App\Models\ZealTrainer;
```

- [ ] **Step 1b: columnMap に「所属店舗」を追加**

ファイル: `app/Http/Controllers/Admin/ZealMemberImportController.php`
変更箇所: 37-53 行目 (`$columnMap`)

`'メモ'` の直前に `'所属店舗' => 'store_name',` を追加:

old:
```php
    private array $columnMap = [
        '氏名'          => 'name',
        'フリガナ'      => 'name_kana',
        '性別'          => 'gender',
        '生年月日'      => 'birthday',
        '電話番号'      => 'phone',
        'メールアドレス'=> 'email',
        '郵便番号'      => 'postal_code',
        '住所'          => 'address',
        '入会日'        => 'joined_on',
        'プラン名'      => 'plan_name',
        '月会費（税抜）'=> 'applied_price_excl',
        '担当トレーナー'=> 'trainer_name',
        '集客チャネル'  => 'acquisition_source',
        '入会目的'      => 'purpose',
        'メモ'          => 'memo',
    ];
```

new:
```php
    private array $columnMap = [
        '氏名'          => 'name',
        'フリガナ'      => 'name_kana',
        '性別'          => 'gender',
        '生年月日'      => 'birthday',
        '電話番号'      => 'phone',
        'メールアドレス'=> 'email',
        '郵便番号'      => 'postal_code',
        '住所'          => 'address',
        '入会日'        => 'joined_on',
        'プラン名'      => 'plan_name',
        '月会費（税抜）'=> 'applied_price_excl',
        '担当トレーナー'=> 'trainer_name',
        '集客チャネル'  => 'acquisition_source',
        '入会目的'      => 'purpose',
        '所属店舗'      => 'store_name',
        'メモ'          => 'memo',
    ];
```

- [ ] **Step 1c: テンプレート CSV のサンプル行に店舗名を追加**

ファイル: `app/Http/Controllers/Admin/ZealMemberImportController.php`
変更箇所: 118-121 行目 (`$sampleRows`)

old:
```php
        $sampleRows = [
            ['山本 健太', 'ヤマモト ケンタ', '男性', '1992-03-14', '090-1234-5678', 'yamamoto@example.com', '790-0001', '愛媛県松山市一番町1-2-3', '2025-10-17', 'パーソナル&セミパーソナル通い放題（1枠）', '18000', '田中', 'SNS', 'ダイエット', ''],
            ['佐藤 花子', 'サトウ ハナコ', '女性', '1985-07-22', '080-9876-5432', '',               '790-0023', '愛媛県松山市本町2-3',     '2025-11-01', 'パーソナル&セミパーソナル通い放題（2枠）', '',      '',     '',      '',          '週3回希望'],
        ];
```

new:
```php
        $sampleRows = [
            ['山本 健太', 'ヤマモト ケンタ', '男性', '1992-03-14', '090-1234-5678', 'yamamoto@example.com', '790-0001', '愛媛県松山市一番町1-2-3', '2025-10-17', 'パーソナル&セミパーソナル通い放題（1枠）', '18000', '田中', 'SNS', 'ダイエット', 'ZEAL BOXING FITNESS 松山市駅前店', ''],
            ['佐藤 花子', 'サトウ ハナコ', '女性', '1985-07-22', '080-9876-5432', '',               '790-0023', '愛媛県松山市本町2-3',     '2025-11-01', 'パーソナル&セミパーソナル通い放題（2枠）', '',      '',     '',      '',          '',                                  '週3回希望'],
        ];
```

(店舗未指定でフォールバックを示すため、2 行目は空欄に)

- [ ] **Step 1d: preview() に店舗マッピング・フォールバック処理を追加**

ファイル: `app/Http/Controllers/Admin/ZealMemberImportController.php`
変更箇所: 142-258 行目 (preview メソッド)

具体的には:
1. `$trainerMap` の取得直後に store マップ取得とフォールバックチェック
2. 行ループ内で `store_name` の検証
3. `$validRows` に `store_id` / `store_name_label` / `store_is_fallback` を追加

old (preview メソッド全体):
```php
    public function preview(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // プランマスタ（名前→ID マップ）
        $planMap = ZealPlan::pluck('id', 'name')->toArray();

        // トレーナーマスタ（名前→ID マップ）
        $trainerMap = ZealTrainer::where('active', true)->pluck('id', 'name')->toArray();

        // 現在の税率（settings テーブル / 不在時は 10% フォールバック）
        $taxRate = Settings::taxRate();
```

new (上記ブロックの直後に store マップ取得を追加):
```php
    public function preview(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // プランマスタ（名前→ID マップ）
        $planMap = ZealPlan::pluck('id', 'name')->toArray();

        // トレーナーマスタ（名前→ID マップ）
        $trainerMap = ZealTrainer::where('active', true)->pluck('id', 'name')->toArray();

        // 店舗マスタ（名前→ID マップ）と既定店舗（フォールバック用）
        $storeMap = ZealStore::where('active', true)->pluck('id', 'name')->toArray();
        $defaultStore = ZealStore::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
        if (!$defaultStore) {
            return back()->with('error', '有効な店舗が登録されていません。先に店舗マスタを登録してください。');
        }

        // 現在の税率（settings テーブル / 不在時は 10% フォールバック）
        $taxRate = Settings::taxRate();
```

次に、行ループの「任意項目のチェック」セクション (197-205 行目あたり) で trainer の検証行の直後に store の検証を追加:

old (任意項目チェックセクション末尾):
```php
            if ($row['trainer_name'] !== '' && !isset($trainerMap[$row['trainer_name']])) {
                $rowErrors[] = "担当トレーナー「{$row['trainer_name']}」が見つかりません";
            }

            if (!empty($rowErrors)) {
```

new:
```php
            if ($row['trainer_name'] !== '' && !isset($trainerMap[$row['trainer_name']])) {
                $rowErrors[] = "担当トレーナー「{$row['trainer_name']}」が見つかりません";
            }
            // 店舗名チェック（未指定はフォールバック OK / 有効でも該当なしはエラー）
            if ($row['store_name'] !== '' && !isset($storeMap[$row['store_name']])) {
                $rowErrors[] = "所属店舗「{$row['store_name']}」が見つかりません";
            }

            if (!empty($rowErrors)) {
```

最後に、`$validRows[]` に store 関連を追加 (231-253 行目):

old (`$validRows[] = [...]` の末尾あたり):
```php
                'purpose'            => $row['purpose'] !== '' ? ($this->purposeMap[$row['purpose']] ?? null) : null,
                'memo'               => $row['memo'],
            ];
```

new (`'memo' => $row['memo'],` の前に store 情報を追加):
```php
                'purpose'            => $row['purpose'] !== '' ? ($this->purposeMap[$row['purpose']] ?? null) : null,
                'store_id'           => $row['store_name'] !== '' ? $storeMap[$row['store_name']] : $defaultStore->id,
                'store_name'         => $row['store_name'] !== '' ? $row['store_name'] : $defaultStore->name,
                'store_is_fallback'  => $row['store_name'] === '',
                'memo'               => $row['memo'],
            ];
```

- [ ] **Step 1e: execute() で store_id を ZealMember::create() に渡す**

ファイル: `app/Http/Controllers/Admin/ZealMemberImportController.php`
変更箇所: 269-368 行目 (execute メソッド)

具体的には:
1. preview と同様に store マップとフォールバックを取得
2. ZealMember::create() の引数に `'store_id' => $storeId,` を追加

old (execute メソッド先頭):
```php
    public function execute(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // プランマスタ・トレーナーマスタを再取得
        $planMap    = ZealPlan::pluck('id', 'name')->toArray();
        $trainerMap = ZealTrainer::where('active', true)->pluck('id', 'name')->toArray();
        $taxRate    = Settings::taxRate();
```

new:
```php
    public function execute(Request $request)
    {
        $result = $this->loadCsv($request);
        if ($result instanceof \Illuminate\Http\RedirectResponse) {
            return $result;
        }
        [$rows, $content] = $result;

        // プランマスタ・トレーナーマスタ・店舗マスタを再取得
        $planMap    = ZealPlan::pluck('id', 'name')->toArray();
        $trainerMap = ZealTrainer::where('active', true)->pluck('id', 'name')->toArray();
        $storeMap   = ZealStore::where('active', true)->pluck('id', 'name')->toArray();
        $defaultStore = ZealStore::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
        if (!$defaultStore) {
            return back()->with('error', '有効な店舗が登録されていません。先に店舗マスタを登録してください。');
        }
        $taxRate    = Settings::taxRate();
```

そして必須チェックの中に store 名の検証 (空でない場合のみ) を追加:

old (必須チェック if 文):
```php
                if (
                    $row['name'] === ''
                    || $row['name_kana'] === ''
                    || $row['gender'] === ''
                    || $row['joined_on'] === ''
                    || $row['plan_name'] === ''
                    || !isset($this->genderMap[$row['gender']])
                    || !isset($planMap[$row['plan_name']])
                ) {
                    $errorCount++;
                    continue;
                }
```

new (店舗名が空でなくマップにない場合もエラー):
```php
                if (
                    $row['name'] === ''
                    || $row['name_kana'] === ''
                    || $row['gender'] === ''
                    || $row['joined_on'] === ''
                    || $row['plan_name'] === ''
                    || !isset($this->genderMap[$row['gender']])
                    || !isset($planMap[$row['plan_name']])
                    || ($row['store_name'] !== '' && !isset($storeMap[$row['store_name']]))
                ) {
                    $errorCount++;
                    continue;
                }
```

最後に ZealMember::create に store_id を追加:

old (ZealMember::create ブロック):
```php
                // 1. ZealMember を作成
                $member = ZealMember::create([
                    'name'               => $row['name'],
                    'name_kana'          => $row['name_kana'],
                    'gender'             => $this->genderMap[$row['gender']],
                    'birthday'           => $this->normalizeDate($row['birthday'] ?: '') ?: null,
                    'phone'              => $row['phone'] ?: null,
                    'email'              => $row['email'] ?: null,
                    'postal_code'        => $row['postal_code'] ?: null,
                    'address'            => $row['address'] ?: null,
                    'joined_on'          => $joinedOn,
                    'current_plan_id'    => $planId,
                    'trainer_id'         => isset($trainerMap[$row['trainer_name']]) ? $trainerMap[$row['trainer_name']] : null,
                    'acquisition_source' => isset($this->acquisitionMap[$row['acquisition_source']]) ? $this->acquisitionMap[$row['acquisition_source']] : null,
                    'purpose'            => isset($this->purposeMap[$row['purpose']]) ? $this->purposeMap[$row['purpose']] : null,
                    'memo'               => $row['memo'] ?: null,
                    'created_by'         => auth()->id(),
                    'updated_by'         => auth()->id(),
                ]);
```

new:
```php
                // 店舗 ID 決定（CSV 値がマップにあればそれを、なければ既定店舗）
                $storeId = $row['store_name'] !== '' && isset($storeMap[$row['store_name']])
                    ? $storeMap[$row['store_name']]
                    : $defaultStore->id;

                // 1. ZealMember を作成
                $member = ZealMember::create([
                    'store_id'           => $storeId,
                    'name'               => $row['name'],
                    'name_kana'          => $row['name_kana'],
                    'gender'             => $this->genderMap[$row['gender']],
                    'birthday'           => $this->normalizeDate($row['birthday'] ?: '') ?: null,
                    'phone'              => $row['phone'] ?: null,
                    'email'              => $row['email'] ?: null,
                    'postal_code'        => $row['postal_code'] ?: null,
                    'address'            => $row['address'] ?: null,
                    'joined_on'          => $joinedOn,
                    'current_plan_id'    => $planId,
                    'trainer_id'         => isset($trainerMap[$row['trainer_name']]) ? $trainerMap[$row['trainer_name']] : null,
                    'acquisition_source' => isset($this->acquisitionMap[$row['acquisition_source']]) ? $this->acquisitionMap[$row['acquisition_source']] : null,
                    'purpose'            => isset($this->purposeMap[$row['purpose']]) ? $this->purposeMap[$row['purpose']] : null,
                    'memo'               => $row['memo'] ?: null,
                    'created_by'         => auth()->id(),
                    'updated_by'         => auth()->id(),
                ]);
```

- [ ] **Step 1f: loadCsv の必須ヘッダーリストに「所属店舗」を加えるかどうか**

`loadCsv` の `$requiredHeaders` に **`store_name` を追加しない**。
理由: 案Z は「列が無くても先頭店舗にフォールバック」する仕様。列が無くても loadCsv が通り、preview で `$row['store_name']` は空文字列にフォールバックされる (`columnMap` が定義する全カラムは空文字列で初期化される `loadCsv` のループ参照 → 434-437 行)。

この Step は変更なし。

### Step 2: index.blade.php (CSV インポート画面) のヘルプ更新

- [ ] **Step 2: CSV インポート画面のヘルプテキストを更新**

ファイル: `resources/views/admin/zeal-member-import/index.blade.php`

「所属店舗」列の説明箇所を追加する。具体的な追加場所はファイル現状を確認してから決定する:

```bash
grep -nE "プラン名|担当トレーナー|集客チャネル|メモ" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/resources/views/admin/zeal-member-import/index.blade.php
```

CSV カラムの説明リストを表示している場所を見つけて、「入会目的」と「メモ」の間に以下を挿入する:

```blade
<li><strong>所属店舗</strong>（任意）：店舗マスタの店舗名と完全一致。空欄の場合は表示順が最も小さい有効店舗（現在: ZEAL BOXING FITNESS 松山市駅前店）に自動で紐付きます。</li>
```

挿入位置がリストでない場合 (テーブルやカードの場合) は、周囲のスタイルに合わせる。

### Step 3: preview.blade.php に「所属店舗」列を追加

- [ ] **Step 3: preview.blade.php に store 列を追加**

ファイル: `resources/views/admin/zeal-member-import/preview.blade.php`

現状を確認:
```bash
grep -nE "trainer_name|担当トレーナー|プラン名|入会目的|メモ" /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/resources/views/admin/zeal-member-import/preview.blade.php
```

有効行プレビューテーブルに「所属店舗」列を追加。担当トレーナーの隣 or 入会目的の隣など、関連する位置に。

具体例 (preview ビューの構造を保ったまま):

担当トレーナーの `<td>...{{ $row['trainer_name'] ?: '-' }}...</td>` の直後あたりに以下を挿入:

```blade
<td style="padding: 6px 10px;">
    {{ $row['store_name'] }}
    @if($row['store_is_fallback'])
        <span style="display:inline-block; margin-left:4px; padding:1px 6px; font-size:10px; background:#fef3c7; color:#92400e; border-radius:9999px;">既定値</span>
    @endif
</td>
```

対応するヘッダー `<th>` セルにも「所属店舗」を追加する。

エラー行プレビューでは store_name はそのまま `$row['store_name']` で表示。

### Step 4: ビューキャッシュクリア

- [ ] **Step 4: ビューキャッシュクリア (ユーザー側で実行)**

```bash
sudo rm -f /Users/masanori/site/manage/.claude/worktrees/stupefied-hertz-631287/storage/framework/views/*.php
```

### Step 5: コミット

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Admin/ZealMemberImportController.php resources/views/admin/zeal-member-import/
git commit -m "ZEAL Phase 3-I: CSV インポートに「所属店舗」列を追加（任意・フォールバック）"
```

---

## Task 8: ローカル動作確認

**Files:** (なし - 検証のみ)

このタスクはユーザーがローカル環境 (`https://localhost/manage/public`) で実施する。

- [ ] **Step 1: SQL 投入 (ユーザー操作)**

ユーザーへ以下を依頼:

> phpMyAdmin で以下のいずれかを実行してください:
> - `database/sql/zeal_stores_seed.sql` の中身をそのまま貼り付け
> - もしくはターミナルから `sudo mysql manage < database/sql/zeal_stores_seed.sql`
>
> 実行後、`SELECT * FROM zeal_stores;` で 1 件入っていることを確認。

- [ ] **Step 2: 店舗マスタ管理画面を開く**

ブラウザで `https://localhost/manage/public/zeal/stores` にアクセス。

期待:
- 「ZEAL BOXING FITNESS 松山市駅前店」が 1 行表示されている
- 「店舗を追加」ボタンが表示される (manager 以上)

- [ ] **Step 3: 店舗追加 (Ajax)**

「店舗を追加」をクリック → 適当なテスト店舗を入力 → 「追加する」を押下。

期待:
- 一覧に新しい店舗が追加される
- トースト通知「『◯◯』を追加しました。」が表示される

- [ ] **Step 4: 店舗編集 (Ajax)**

任意の行の「編集」をクリック → 名前を変更 → 「保存」を押下。

期待:
- 一覧の名前が更新される
- トースト通知「『◯◯』を更新しました。」が表示される

- [ ] **Step 5: 店舗削除 (Ajax) - 会員紐付けなし**

Step 3 で追加したテスト店舗の「削除」をクリック → 確認ダイアログで「OK」。

期待:
- 一覧から消える
- トースト通知「『◯◯』を削除しました。」

- [ ] **Step 6: テスト会員を SQL で 1 件挿入 (ユーザー操作)**

phpMyAdmin で:

```sql
INSERT INTO `zeal_members`
    (`store_id`, `name`, `name_kana`, `gender`, `joined_on`, `current_plan_id`, `created_at`, `updated_at`)
VALUES (
    (SELECT id FROM zeal_stores WHERE name = 'ZEAL BOXING FITNESS 松山市駅前店'),
    'テスト 太郎',
    'テスト タロウ',
    'male',
    '2026-01-01',
    (SELECT id FROM zeal_plans LIMIT 1),
    NOW(),
    NOW()
);
```

- [ ] **Step 7: 会員編集フォームで店舗 select 表示確認**

ブラウザで `/zeal/members` → テスト会員を開く → 編集ボタンを押下。

期待:
- 「所属店舗」select に `ZEAL BOXING FITNESS 松山市駅前店` が表示
- 既存の値 (1件目の店舗) が selected になっている
- `*必須` バッジが表示されている

- [ ] **Step 8: 店舗を別の店舗に変更して保存**

Step 3 で別の店舗を残していなければ、まず 1 件追加 → 会員編集で別店舗を選択 → 「更新する」を押下。

期待:
- 詳細画面にリダイレクトされ「更新しました」トースト
- DB の `zeal_members.store_id` が変わっていることを phpMyAdmin で確認

- [ ] **Step 9: 紐付き会員のいる店舗を削除試行**

会員が所属する店舗を `/zeal/stores` で削除しようとする。

期待:
- 確認ダイアログで OK 押下後、エラー通知
- メッセージ「『◯◯』には所属会員がいるため削除できません。『無効』に変更してご利用ください。」

- [ ] **Step 10: 店舗マスタ未登録時の会員フォーム挙動 (任意確認)**

`zeal_stores` の全店舗を `active = 0` に手動更新 → 会員編集画面を開く。

期待:
- select は無効状態 (「店舗マスタが未登録です」option)
- 黄色警告ボックスで「先に店舗マスタを登録してください」が表示

確認後、`active = 1` に戻す。

- [ ] **Step 11: CSV インポート — テンプレートダウンロード**

`/admin/zeal/member-import` でテンプレート CSV をダウンロード → Excel/エディタで開く。

期待:
- ヘッダー列に `所属店舗` が含まれる (位置: `入会目的` と `メモ` の間)
- サンプル行 1 行目に `ZEAL BOXING FITNESS 松山市駅前店` が入っている
- サンプル行 2 行目の所属店舗は空欄

- [ ] **Step 12: CSV インポート — 店舗未指定でフォールバック**

「所属店舗」列が空欄の CSV を作成 → アップロード → プレビュー画面。

期待:
- プレビューに「既定値」バッジが表示される
- 取込実行後、`SELECT store_id FROM zeal_members ORDER BY id DESC LIMIT 1;` で先頭店舗の ID が入っている

- [ ] **Step 13: CSV インポート — 存在しない店舗名**

「所属店舗」列に `存在しない店舗` を入れた CSV をアップロード → プレビュー画面。

期待:
- エラー行に「所属店舗『存在しない店舗』が見つかりません」が表示
- 「実行する」ボタン押下後、その行はスキップされる (errorCount に計上)

- [ ] **Step 14: 動作確認の結果を報告**

すべて期待通りなら、ユーザーに「全項目 OK」と報告。
失敗があれば該当 Task の修正に戻る。

---

## Self-Review チェックリスト

実装中に振り返るための仕様書カバレッジ:

| 仕様書セクション | 該当 Task |
|---|---|
| 初期データ | Task 1, Task 8 Step 1-2 |
| Store CRUD: index | Task 2 Step 1, Task 3 |
| Store CRUD: store/update/destroy | Task 2 Step 1, Task 8 Step 3-5, 9 |
| ルート定義 | Task 4 |
| サイドバー | Task 5 |
| 会員フォーム store select | Task 6 Step 2, Task 8 Step 7-8 |
| Store マスタ未登録時の挙動 | Task 6 Step 2b, Task 8 Step 10 |
| CSV: テンプレート列追加 | Task 7 Step 1c, Task 8 Step 11 |
| CSV: 任意・フォールバック | Task 7 Step 1d-e, Task 8 Step 12 |
| CSV: 存在しない店舗エラー | Task 7 Step 1d-e, Task 8 Step 13 |
| 有効店舗ゼロ件のエラー停止 | Task 7 Step 1d-e |

すべて Task でカバー済み。Type 不一致なし (`store` プロパティは Trainer の `trainer` と平行構造)。プレースホルダーなし。
