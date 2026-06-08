# 住宅事業 契約⇔顧客マスタ連携（フェーズ2）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 住宅事業（建売・注文住宅）の契約フォームで買主マスタから顧客を選択／その場で新規登録（モーダル・Ajax）できるようにし、`customer_id` 紐付けを必須化、`customer_name` を買主名で自動セットして「契約したのに顧客一覧に出ない」連携漏れを構造的に無くす。

**Architecture:** 既存資産を流用する最小改修。① `CustomerController::quickStore`（Ajax API）+ ルート1本。② 共通 Blade partial `_buyer-select.blade.php`（`customer_id` セレクト + 読み取り専用 `customer_name` + ＋新規モーダル + インライン script、すべて inline style・独立 `x-data`）を新設し、create×2 / edit×2 の4フォームに組み込む。③ store×2 / update×2 で `customer_id` 必須化 + `customer_name` を `Buyer::full_name` で上書き。

**Tech Stack:** Laravel 12 / PHP 8.3(prod)・8.5(local) / Blade + Alpine.js 3 / Tailwind v4(Vite ビルド済) / MySQL 8。買主マスタ = `Buyer`（SoftDeletes）+ `buyer_departments` ピボット。

---

## 設計書との対応・意図的な逸脱

本計画は `docs/superpowers/specs/2026-06-08-housing-contract-customer-linkage-design.md` §5 を実装する。実コード確認の結果、設計 §10 の「影響ファイル」リストから以下を意図的に調整した（理由付き）。

1. **注文住宅 create は `_form.blade.php` を編集**（設計は `custom-orders/create.blade.php`）。
   `custom-orders/create.blade.php` はフィールドを共通 `_form.blade.php`（create/案件edit 共用）に委譲しており、`customer_name` は `_form` 内にある。よって `_form` の「顧客情報」セクションを `@isset($buyers)` で分岐し、create のみ partial を表示、案件edit は既存の自由入力を維持する。`create.blade.php` は `_form` include に `'buyers' => $buyers` を渡すだけ変更。
2. **partial が `customer_name`（読み取り専用）も所有**（設計は「テキスト欄を残しサーバー側上書き」）。
   4フォームで `customer_name` の扱いが異なる（建売createは `x-model`、editは素の input、注文createは autocomplete）。partial が `customer_id` セレクト + 自動補完される読み取り専用 `customer_name` を一体で持つことで4フォーム共通化し、見た目も統一。サーバー側上書きはバックストップとして維持。
3. **建売 create の死にコードを除去**。`contracts/create.blade.php` は `contractForm()` を `@push('scripts')` で定義しているが、レイアウトに `@stack('scripts')` が無い（設計 §5.5 も指摘）ため **現状ロードされていない**（`x-data="contractForm()"` は未定義関数参照）。partial がこの顧客機構を置換するため、`x-data="contractForm()"` と `@push` ブロックを削除する。
4. **スタイルは全て inline**。edit フォームはページ固有クラス（`hc-input` 等）、create フォームは Tailwind（`form-input` 等）と CSS 系統が異なり、かつ Vite ビルド済 Tailwind の制約（Bug #19）がある。partial は inline style で統一し、両系統で一貫表示する。

### ⚠ 承認時に確認したい挙動変更（要ユーザー判断）

- **注文住宅案件の新規登録（`custom-orders/create`）で `customer_id` が必須になる。** 注文住宅は `商談 / 設計 / 見積り` という契約前ステータスでも案件登録でき、この画面は全ステータス共通。よって「商談段階の見込み客でも買主マスタ登録（＋新規モーダルで1クリック可）が必須」になる。設計 §5.3 で確定済みの方針だが、早期見込み客に必須化したくない場合は「`status` が `contracted` 以降のときのみ必須」に変更可能。**本計画はまず設計通り「常に必須」で実装**する。
- 建売契約（`contracts/create`）は契約成立時のみ作成されるため、必須化は自然。

---

## File Structure

| ファイル | 操作 | 責務 |
|---|---|---|
| `app/Http/Controllers/CustomerController.php` | 変更 | `quickStore()` Ajax メソッド追加（買主最小登録 → JSON） |
| `routes/web.php` | 変更 | `POST /housing/customers/quick-store` 1本追加 |
| `resources/views/housing/contracts/_buyer-select.blade.php` | 新規 | 共通 partial（セレクト + 読取専用名 + モーダル + script） |
| `app/Http/Controllers/Housing/CustomOrderController.php` | 変更 | `create()` に `$buyers`、`store()` に `customer_id` 必須 + 名上書き |
| `resources/views/housing/custom-orders/create.blade.php` | 変更 | `_form` include に `$buyers` を渡す |
| `resources/views/housing/custom-orders/_form.blade.php` | 変更 | 顧客情報セクションを `@isset($buyers)` で partial / 既存に分岐 |
| `app/Http/Controllers/Housing/ContractController.php` | 変更 | `create()` に `$buyers`、`store()` に `customer_id` 必須 + 名上書き |
| `resources/views/housing/contracts/create.blade.php` | 変更 | 顧客名ブロックを partial に置換、死にコード除去 |
| `app/Http/Controllers/Housing/HsContractListController.php` | 変更 | `updateBuilding` / `updateCustomOrder` を `customer_id` 必須 + 名上書き |
| `resources/views/housing/contracts/edit-building.blade.php` | 変更 | 顧客名 + 既存セレクトを partial に置換 |
| `resources/views/housing/contracts/edit-custom-order.blade.php` | 変更 | 顧客名 + 既存セレクトを partial に置換 |

新規 PHP クラスは無し（`quickStore` は既存コントローラのメソッド、partial はビュー）→ **`composer dump-autoload` 不要**。

---

## 検証方針（このプロジェクト固有の制約）

- worktree では `vendor` が main repo への `--no-dev` symlink のため **PHPUnit / `php artisan test` は実行不可**。`php artisan` 自体も worktree では不可（`vendor/autoload.php` 不在）。
- 設計 §8 の Feature テストは本ワークフローでは実行環境が無い（`hs_*` / `buyers` がテスト DB 不在）。**tinker + 静的検証 + 本番読み取り**で代替する（フェーズ1と同じ方針）。
- 各タスクの検証ゲート:
  - **worktree 内**: `php -l <changed.php>`（PHP 構文）、`grep` による Blade 罠検査（`&quot;` 不在=Bug #21、`<option>` を `x-for` で生成していない=Bug #16）。
  - **FF-merge 後 main repo**: `php artisan route:clear && php artisan route:list --path=housing/customers`（新ルート確認）、`php artisan view:clear && php artisan view:cache`（**全 Blade precompile = Bug #21 検出**）、`php artisan config:clear`。
  - **tinker（main repo・ローカル実 DB）**: 買主クイック登録ロジック、`customer_id` 必須 + `customer_name` 上書きを実データで確認。
  - **本番**: `./deploy.sh` 後に SSH tinker / 画面で読み取り確認。

---

## Task 1: 買主クイック登録 API（`CustomerController::quickStore` + ルート）

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php`（`checkDuplicate` の直前あたり、Ajax メソッド群に追加）
- Modify: `routes/web.php:1140` 付近（housing 顧客管理ブロックの `role:executive,manager` グループ内）

- [ ] **Step 1: `quickStore()` メソッドを追加**

`app/Http/Controllers/CustomerController.php` の `checkDuplicate()` メソッドの直前に追加する（`use App\Models\Buyer;` と `use Illuminate\Support\Facades\DB;` は既存）。

```php
    /**
     * 買主クイック登録（Ajax）
     * 契約フォームのモーダルから最小項目で買主マスタへ登録する。
     * 通常の顧客登録（store）と異なりアンケートは作らない。
     * 認可は登録と同じライン（経営層+管理者 ＋ 自部署）をルート側で担保。
     */
    public function quickStore(Request $request)
    {
        $department = $this->resolveDepartment();

        $validated = $request->validate([
            'last_name'       => 'required|max:50',
            'first_name'      => 'required|max:50',
            'last_name_kana'  => 'nullable|max:50',
            'first_name_kana' => 'nullable|max:50',
            'acquired_date'   => 'required|date',
            'postal_code'     => 'nullable|max:10',
            'prefecture'      => 'nullable|max:20',
            'city'            => 'nullable|max:50',
            'address_detail'  => 'nullable|max:100',
            'phone'           => 'nullable|max:20',
        ]);

        $buyer = DB::transaction(function () use ($validated, $department) {
            $buyer = Buyer::create([
                'last_name'       => $validated['last_name'],
                'first_name'      => $validated['first_name'],
                'last_name_kana'  => $validated['last_name_kana'] ?? null,
                'first_name_kana' => $validated['first_name_kana'] ?? null,
                'postal_code'     => $validated['postal_code'] ?? null,
                'prefecture'      => $validated['prefecture'] ?? null,
                'city'            => $validated['city'] ?? null,
                'address_detail'  => $validated['address_detail'] ?? null,
                'phone'           => $validated['phone'] ?? null,
            ]);
            // rank は addToDepartment のデフォルト 'C'
            $buyer->addToDepartment($department, $validated['acquired_date']);
            return $buyer;
        });

        return response()->json([
            'id'             => $buyer->id,
            'full_name'      => $buyer->full_name,
            'full_name_kana' => $buyer->full_name_kana,
            'prefecture'     => $buyer->prefecture,
            'city'           => $buyer->city,
        ]);
    }
```

- [ ] **Step 2: ルートを追加**

`routes/web.php` の housing 顧客管理ブロック内、`housing.customers.store`（POST `/customers`）の直後・同じ `Route::middleware('role:executive,manager')->group(...)` の中に追加する（現在 1139-1140 行付近）。

```php
            Route::post('/customers', [\App\Http\Controllers\CustomerController::class, 'store'])
                ->name('housing.customers.store');
            // フェーズ2: 契約フォームからの買主クイック登録（Ajax）
            Route::post('/customers/quick-store', [\App\Http\Controllers\CustomerController::class, 'quickStore'])
                ->name('housing.customers.quick-store');
```

ルート順序: `POST /customers/quick-store` は `POST /customers`(store) や `GET/PUT/DELETE /customers/{buyer}` と動詞・パスが重ならず衝突しない。`resolveDepartment()` は `segment(1)='housing'` を返す。

- [ ] **Step 3: PHP 構文チェック（worktree）**

Run: `php -l app/Http/Controllers/CustomerController.php`
Expected: `No syntax errors detected`

`routes/web.php` は worktree では `php -l` のみ（`php artisan route:list` は merge 後）:
Run: `php -l routes/web.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: コミット**

```bash
git add app/Http/Controllers/CustomerController.php routes/web.php
git commit -m "feat(housing): 買主クイック登録 API とルートを追加"
```

---

## Task 2: 共通 partial `_buyer-select.blade.php`

**Files:**
- Create: `resources/views/housing/contracts/_buyer-select.blade.php`

設計の罠回避を全て織り込む: 静的 `@foreach` で `<option>`（Bug #16 回避、`x-for` で option を作らない）／IME Enter ガード（Bug #6）／inline style 統一（Bug #7・#19）／`x-data` は名前付き関数（Bug #1・Top trap #4）／モーダル入力欄は `name` 無し（メインフォーム非送信・Enter で外側 form を submit させない）。

- [ ] **Step 1: partial を新規作成**

`resources/views/housing/contracts/_buyer-select.blade.php`:

```blade
{{-- 買主マスタ紐付け 共通パーシャル（フェーズ2）
     注文住宅・建売の create/edit 4フォームで共通利用。
     引数:
       $buyers       … Buyer コレクション（住宅事業所属 + 編集時は現 buyer を withTrashed で含む）
       $selectedId   … 現在の customer_id（呼び出し側で old() 反映済みを渡す。null 可）
       $selectedName … 現在の customer_name（old() 反映済み。既定 ''）
       $department   … 既定 'housing'
     設計上の注意:
       - <option> は静的 @foreach（Bug #16: x-for で option を作らない）
       - スタイルは全て inline（Bug #7/#19: Vite未収録Tailwind回避・edit/create両CSS系で一貫表示）
       - モーダル入力欄は name 無し → メインフォームに送信されない。Enter は IME ガード付きで握りつぶす（Bug #6）
       - x-data は名前付き関数 buyerSelect()（Top trap #4） --}}
@php
    $bsSelectedId   = $selectedId ?? null;
    $bsSelectedName = $selectedName ?? '';
    $bsDepartment   = $department ?? 'housing';
@endphp

<style>[x-cloak]{display:none !important;}</style>

<div x-data="buyerSelect(@js($bsSelectedName))">
    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px;">
        買主マスタ紐付け<span style="color:#dc2626; margin-left:2px;">*</span>
    </label>

    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <select name="customer_id" x-ref="sel" @change="onSelect()" required
                style="flex:1; min-width:220px; height:40px; padding:0 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; color:#1f2937; background:#fff; cursor:pointer;">
            <option value="">— 買主を選択してください —</option>
            @foreach($buyers as $buyer)
                <option value="{{ $buyer->id }}"
                        data-name="{{ $buyer->full_name }}"
                        @selected((string) old('customer_id', $bsSelectedId) === (string) $buyer->id)>
                    {{ $buyer->full_name }}@if($buyer->trashed()) （削除済み）@endif
                </option>
            @endforeach
        </select>

        <button type="button" @click="openModal()"
                style="display:inline-flex; align-items:center; gap:4px; height:40px; padding:0 14px; font-size:13px; font-weight:600; color:#fff; background:#059669; border:none; border-radius:6px; cursor:pointer; white-space:nowrap;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            新規顧客を登録
        </button>
    </div>
    @error('customer_id') <p style="font-size:12px; color:#dc2626; margin-top:4px;">{{ $message }}</p> @enderror

    {{-- 顧客名（買主選択で自動補完・読み取り専用。サーバー側でも buyer 名で上書き） --}}
    <div style="margin-top:10px;">
        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px;">顧客名（自動）</label>
        <input type="text" name="customer_name" x-model="customerName" readonly
               style="width:100%; max-width:360px; height:40px; padding:0 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:14px; color:#6b7280; background:#f9fafb; box-sizing:border-box;">
        @error('customer_name') <p style="font-size:12px; color:#dc2626; margin-top:4px;">{{ $message }}</p> @enderror
    </div>

    {{-- ＋新規顧客 モーダル（入力欄は name 無し = メインフォーム非送信） --}}
    <div x-show="modalOpen" x-cloak
         style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px; overflow-y:auto;"
         @click.self="closeModal()">
        <div style="background:#fff; border-radius:10px; width:100%; max-width:560px; box-shadow:0 20px 50px rgba(0,0,0,0.25);">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <h3 style="font-size:15px; font-weight:700; color:#111827; margin:0;">新規顧客を登録</h3>
                <button type="button" @click="closeModal()" style="border:none; background:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">&times;</button>
            </div>

            <div style="padding:18px 20px;">
                {{-- 重複サジェスト（非ブロッキング） --}}
                <div x-show="duplicates.length > 0" x-cloak
                     style="margin-bottom:14px; padding:10px 12px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:12px; color:#92400e;">
                    <p style="font-weight:700; margin:0 0 4px;">同名の買主が既に登録されています:</p>
                    <ul style="margin:0; padding-left:16px;">
                        <template x-for="d in duplicates" :key="d.id">
                            <li x-text="d.full_name + (d.same_dept ? '（住宅事業に登録済み）' : '（他部署）')"></li>
                        </template>
                    </ul>
                    <p style="margin:6px 0 0;">別人であればこのまま登録してください。</p>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">姓<span style="color:#dc2626;">*</span></label>
                        <input type="text" x-model="f.last_name" @blur="checkDup()"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">名<span style="color:#dc2626;">*</span></label>
                        <input type="text" x-model="f.first_name" @blur="checkDup()"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">姓カナ</label>
                        <input type="text" x-model="f.last_name_kana"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">名カナ</label>
                        <input type="text" x-model="f.first_name_kana"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">取得日<span style="color:#dc2626;">*</span></label>
                        <input type="date" x-model="f.acquired_date"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">電話</label>
                        <input type="text" inputmode="numeric" x-model="f.phone"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">郵便番号</label>
                        <input type="text" inputmode="numeric" x-model="f.postal_code"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">都道府県</label>
                        <input type="text" x-model="f.prefecture"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div style="grid-column:span 2;">
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">市区町村</label>
                        <input type="text" x-model="f.city"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div style="grid-column:span 2;">
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">住所詳細</label>
                        <input type="text" x-model="f.address_detail"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                </div>

                <p x-show="error" x-cloak x-text="error" style="margin-top:12px; font-size:12px; color:#dc2626;"></p>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; padding:14px 20px; border-top:1px solid #e5e7eb;">
                <button type="button" @click="closeModal()"
                        style="height:38px; padding:0 16px; font-size:13px; font-weight:600; color:#374151; background:#fff; border:1px solid #d1d5db; border-radius:6px; cursor:pointer;">キャンセル</button>
                <button type="button" @click="submitModal()" :disabled="submitting"
                        :style="submitting ? 'opacity:0.6; cursor:not-allowed; height:38px; padding:0 18px; font-size:13px; font-weight:700; color:#fff; background:#059669; border:none; border-radius:6px;' : 'height:38px; padding:0 18px; font-size:13px; font-weight:700; color:#fff; background:#059669; border:none; border-radius:6px; cursor:pointer;'">
                    <span x-text="submitting ? '登録中…' : '登録して選択'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function buyerSelect(initialName) {
    return {
        customerName: initialName || '',
        modalOpen: false,
        submitting: false,
        error: '',
        duplicates: [],
        f: {
            last_name: '', first_name: '', last_name_kana: '', first_name_kana: '',
            acquired_date: '{{ now()->format('Y-m-d') }}',
            postal_code: '', prefecture: '', city: '', address_detail: '', phone: ''
        },

        csrf: function() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        },

        onSelect: function() {
            var sel = this.$refs.sel;
            var opt = sel.options[sel.selectedIndex];
            this.customerName = (opt && opt.dataset.name) ? opt.dataset.name : '';
        },

        openModal: function() {
            this.error = '';
            this.duplicates = [];
            this.modalOpen = true;
        },

        closeModal: function() {
            this.modalOpen = false;
        },

        checkDup: function() {
            var self = this;
            if (!self.f.last_name || !self.f.first_name) { return; }
            fetch('{{ route('api.customers.check-duplicate') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': self.csrf() },
                body: JSON.stringify({
                    last_name: self.f.last_name,
                    first_name: self.f.first_name,
                    prefecture: self.f.prefecture,
                    city: self.f.city,
                    department: '{{ $bsDepartment }}'
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) { self.duplicates = data.duplicates || []; })
            .catch(function() { self.duplicates = []; });
        },

        submitModal: function() {
            var self = this;
            if (self.submitting) { return; }
            if (!self.f.last_name || !self.f.first_name || !self.f.acquired_date) {
                self.error = '姓・名・取得日は必須です。';
                return;
            }
            self.submitting = true;
            self.error = '';
            fetch('{{ route('housing.customers.quick-store') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': self.csrf() },
                body: JSON.stringify(self.f)
            })
            .then(function(res) {
                if (!res.ok) { throw new Error('登録に失敗しました（' + res.status + '）'); }
                return res.json();
            })
            .then(function(data) {
                var sel = self.$refs.sel;
                var opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.full_name;
                opt.dataset.name = data.full_name;
                sel.appendChild(opt);
                sel.value = String(data.id);
                self.customerName = data.full_name;
                self.submitting = false;
                self.modalOpen = false;
            })
            .catch(function(err) {
                self.submitting = false;
                self.error = err.message || '登録に失敗しました。';
            });
        }
    };
}
</script>
```

注: `@js($bsSelectedName)` は Blade が安全に JS リテラル化（引用符エスケープ込み）するため、`x-data="buyerSelect('...')"` に氏名を直接埋めるより安全（Bug #21 系の `&quot;` 混入を避ける）。

- [ ] **Step 2: Blade 罠の静的検査（worktree）**

```bash
# Bug #21: &quot; がコンパイル済み PHP に残ると本番500。partial に &quot; が無いこと
grep -n "&quot;" resources/views/housing/contracts/_buyer-select.blade.php   # 0件であること
# Bug #16: customer_id の <option> を x-for で生成していないこと（静的 @foreach のみ）
grep -n "x-for" resources/views/housing/contracts/_buyer-select.blade.php    # duplicates 表示の <template x-for> のみ（option ではない）
```
Expected: `&quot;` 0件。`x-for` は重複サジェストの `<li>` 用のみ（`<option>` には無い）。

- [ ] **Step 3: コミット**

```bash
git add resources/views/housing/contracts/_buyer-select.blade.php
git commit -m "feat(housing): 買主選択+新規登録モーダルの共通 partial を追加"
```

---

## Task 3: 注文住宅 create に partial 組み込み + store 必須化

**Files:**
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`（`use` 追加・`create()`・`store()`）
- Modify: `resources/views/housing/custom-orders/create.blade.php`（`_form` include に `$buyers`）
- Modify: `resources/views/housing/custom-orders/_form.blade.php`（顧客情報セクション分岐）

- [ ] **Step 1: `CustomOrderController` に `Buyer` を import**

`app/Http/Controllers/Housing/CustomOrderController.php` の use 群（`use App\Models\HsCustomOrder;` の付近）に追加:

```php
use App\Models\Buyer;
```

- [ ] **Step 2: `create()` で買主リストを渡す**

```php
    public function create()
    {
        $projectsForJs = $this->getProjectsForJs();
        $procurementsForJs = $this->getProcurementsForJs();
        $defaultTaxRate = $this->getDefaultTaxRate();
        $buyers = Buyer::ofDepartment('housing')->orderBy('last_name_kana')->get();

        return view('housing.custom-orders.create', compact('projectsForJs', 'procurementsForJs', 'defaultTaxRate', 'buyers'));
    }
```

- [ ] **Step 3: `store()` で `customer_id` 必須 + `customer_name` 上書き**

`validateOrder()` は `update()` と共用のため触らない。`store()` 内で個別に追加する。`$validated = $this->validateOrder($request);` の直後に挿入:

```php
    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);

        // フェーズ2: 買主マスタ紐付けを必須化し、customer_name を買主名で上書き（連携漏れ防止）
        $request->validate([
            'customer_id' => ['required', 'integer', 'exists:buyers,id'],
        ]);
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;

        $validated['order_code'] = $this->generateOrderCode();
        $validated['created_by'] = auth()->id();

        $this->setLandSourceForeignKeys($validated);
        $this->clearLandFieldsForCustomerLand($validated);

        $order = HsCustomOrder::create($validated);

        $this->syncLotStatus($order, null);

        return redirect()
            ->route('housing.custom-orders.show', $order)
            ->with('success', "注文住宅案件「{$order->order_code}」を登録しました。");
    }
```

- [ ] **Step 4: `create.blade.php` の `_form` include に `$buyers` を渡す**

`resources/views/housing/custom-orders/create.blade.php:26` の include 配列に `'buyers' => $buyers` を追加:

```blade
        @include('housing.custom-orders._form', ['customOrder' => null, 'projectsForJs' => $projectsForJs, 'procurementsForJs' => $procurementsForJs, 'defaultTaxRate' => $defaultTaxRate, 'buyers' => $buyers])
```

- [ ] **Step 5: `_form.blade.php` の顧客情報セクションを分岐**

`resources/views/housing/custom-orders/_form.blade.php` の「顧客情報」カード（38-70 行）の内側 `<div> ... </div>`（顧客名 input + autocomplete + 顧客登録リンクの塊、40-69 行）を `@isset($buyers)` で分岐する。カード枠（38-39 行と閉じ 70 行）は維持。

変更前（40-69 行の塊）を、次で置換:

```blade
        @isset($buyers)
            {{-- フェーズ2: 買主マスタ連携（create のみ。$buyers 未指定の案件edit は従来の自由入力） --}}
            @include('housing.contracts._buyer-select', [
                'buyers'       => $buyers,
                'selectedId'   => old('customer_id', $o?->customer_id),
                'selectedName' => old('customer_name', $o?->customer_name ?? ''),
                'department'   => 'housing',
            ])
        @else
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">顧客名<span class="text-red-600 ml-0.5">*</span></label>
                <div style="position: relative;">
                    <input type="text" name="customer_name" x-model="customerName"
                           @input="searchCustomer()" @focus="searchCustomer()"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           placeholder="顧客名を入力して検索..." autocomplete="off">
                    <div x-show="customerResults.length > 0"
                         @click.outside="customerResults = []"
                         style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 6px 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; max-height: 200px; overflow-y: auto;">
                        <template x-for="cust in customerResults" :key="cust.id">
                            <div @click="selectCustomer(cust)"
                                 style="padding: 8px 12px; font-size: 13px; cursor: pointer; border-bottom: 1px solid #f3f4f6;"
                                 class="hover:bg-gray-50">
                                <div class="text-sm font-semibold text-gray-900" x-text="cust.name"></div>
                                <div class="text-xs text-gray-500" x-text="cust.address || ''"></div>
                            </div>
                        </template>
                    </div>
                </div>
                @error('customer_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                <div class="flex gap-2 mt-1" style="align-items: center;">
                    <p class="text-xs text-gray-500">顧客マスタから検索。未登録の場合は直接入力</p>
                    <a href="{{ route('tenant.customers.create') }}" target="_blank"
                       style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #1d4ed8; text-decoration: none; padding: 2px 8px; border: 1px solid #93c5fd; border-radius: 4px; background: #eff6ff;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        顧客登録
                    </a>
                </div>
            </div>
        @endisset
```

（`@else` 側は現行 40-69 行をそのまま移植。`customerName` / `searchCustomer` / `selectCustomer` は `customOrderForm()` に既存のため案件edit で従来通り動作。）

- [ ] **Step 6: PHP 構文チェック（worktree）**

Run: `php -l app/Http/Controllers/Housing/CustomOrderController.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/Housing/CustomOrderController.php resources/views/housing/custom-orders/create.blade.php resources/views/housing/custom-orders/_form.blade.php
git commit -m "feat(housing): 注文住宅 新規登録に買主選択を組み込み customer_id を必須化"
```

---

## Task 4: 建売契約 create に partial 組み込み + store 必須化

**Files:**
- Modify: `app/Http/Controllers/Housing/ContractController.php`（`use` 追加・`create()`・`store()`）
- Modify: `resources/views/housing/contracts/create.blade.php`（顧客名ブロック置換・死にコード除去）

- [ ] **Step 1: `ContractController` に `Buyer` を import**

`app/Http/Controllers/Housing/ContractController.php` の use 群（`use App\Models\HsContract;` 付近）に追加:

```php
use App\Models\Buyer;
```

- [ ] **Step 2: `create()` で買主リストを渡す**

```php
    public function create(HsProperty $property)
    {
        if ($property->contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', 'この物件は既に契約済みです。');
        }

        $property->load(['projectLot.project', 'procurement.costs']);
        $defaults = $this->getContractDefaults($property);
        $defaultTaxRate = $this->getDefaultTaxRate();
        $buyers = Buyer::ofDepartment('housing')->orderBy('last_name_kana')->get();

        return view('housing.contracts.create', compact('property', 'defaults', 'defaultTaxRate', 'buyers'));
    }
```

- [ ] **Step 3: `store()` で `customer_id` 必須 + `customer_name` 上書き**

`validateContract()` は `update()` と共用のため触らない。`store()` 内で個別追加:

```php
    public function store(Request $request, HsProperty $property)
    {
        if ($property->contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', 'この物件は既に契約済みです。');
        }

        $validated = $this->validateContract($request);

        // フェーズ2: 買主マスタ紐付けを必須化し、customer_name を買主名で上書き
        $request->validate([
            'customer_id' => ['required', 'integer', 'exists:buyers,id'],
        ]);
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;

        $validated['property_id'] = $property->id;
        $validated['created_by'] = auth()->id();

        $contract = HsContract::create($validated);

        $this->updateLotStatusOnSold($property);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', '契約を登録しました。物件のステータスが「成約」に更新されました。');
    }
```

- [ ] **Step 4: `create.blade.php` の form タグから死んだ `x-data` を除去**

`resources/views/housing/contracts/create.blade.php:30`:

変更前:
```blade
    <form method="POST" action="{{ route('housing.contracts.store', $property) }}" x-data="contractForm()">
```
変更後:
```blade
    <form method="POST" action="{{ route('housing.contracts.store', $property) }}">
```

- [ ] **Step 5: 顧客名ブロックを partial に置換**

「顧客情報」カード（80-126 行）の中身を、partial（フル幅）+ 契約日/決済日グリッドに再構成する。81 行のセクションタイトル直後〜125 行の `</div>`（グリッド閉じ）を次で置換:

```blade
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">顧客情報</div>
            {{-- フェーズ2: 買主マスタ紐付け（必須・＋新規モーダル） --}}
            <div class="mb-4">
                @include('housing.contracts._buyer-select', [
                    'buyers'       => $buyers,
                    'selectedId'   => old('customer_id'),
                    'selectedName' => old('customer_name', ''),
                    'department'   => 'housing',
                ])
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">契約日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="contract_date" value="{{ old('contract_date') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('contract_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">決済日</label>
                    <input type="date" name="settlement_date" value="{{ old('settlement_date') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    <p class="text-xs text-gray-500 mt-1">引渡し日（未定の場合は空欄）</p>
                </div>
            </div>
```

- [ ] **Step 6: 死んだ `@push('scripts')` ブロックを除去**

`resources/views/housing/contracts/create.blade.php:145-177`（`@push('scripts')` 〜 `@endpush`、`contractForm()` 定義）を丸ごと削除する。レイアウトに `@stack('scripts')` が無くロードされていない死にコードで、partial が顧客機構を置換済みのため。

- [ ] **Step 7: PHP 構文チェック（worktree）**

Run: `php -l app/Http/Controllers/Housing/ContractController.php`
Expected: `No syntax errors detected`

- [ ] **Step 8: コミット**

```bash
git add app/Http/Controllers/Housing/ContractController.php resources/views/housing/contracts/create.blade.php
git commit -m "feat(housing): 建売契約 登録に買主選択を組み込み customer_id を必須化"
```

---

## Task 5: 契約管理 編集×2 に partial 組み込み + update 必須化

**Files:**
- Modify: `app/Http/Controllers/Housing/HsContractListController.php`（`updateBuilding` / `updateCustomOrder`）
- Modify: `resources/views/housing/contracts/edit-building.blade.php`（顧客名 + 既存セレクト置換）
- Modify: `resources/views/housing/contracts/edit-custom-order.blade.php`（同）

`Buyer` は `HsContractListController` で import 済み。`editBuilding` / `editCustomOrder` は既に `$buyers`（現 buyer を `withTrashed` で含む）を渡すため変更不要。

- [ ] **Step 1: `updateBuilding` を `customer_id` 必須 + 名上書き**

`app/Http/Controllers/Housing/HsContractListController.php` の `updateBuilding`:

バリデーション行を変更:
```php
            'customer_id'            => 'required|integer|exists:buyers,id',
```
（`nullable` → `required`）

トランザクション先頭で buyer を引き、`customer_name` を上書き。`DB::transaction(function () use ($validated, $request, $hsContract, $property) {` の直後と `$contractData` を変更:
```php
        DB::transaction(function () use ($validated, $request, $hsContract, $property) {
            $buyer = Buyer::withTrashed()->findOrFail($validated['customer_id']);
            $contractData = [
                'customer_name'          => $buyer->full_name, // 買主名で上書き（整合性確保）
                'customer_id'            => $buyer->id,
                'contract_date'          => $validated['contract_date'],
                'selling_price_land'     => $validated['selling_price_land'],
                'selling_price_building' => $validated['selling_price_building'],
                'tax_rate'               => $validated['tax_rate'],
                'notes'                  => $validated['notes'] ?? null,
                'updated_by'             => auth()->id(),
            ];
            // 担当者（created_by）は明示指定された場合のみ上書き
            if (!empty($validated['created_by'])) {
                $contractData['created_by'] = $validated['created_by'];
            }
            $hsContract->update($contractData);

            // 物件側の原価項目を更新（変更なし）
            $isManual = $request->boolean('is_land_cost_manual');
            $propertyData = [
                'building_cost'       => $validated['building_cost'],
                'is_land_cost_manual' => $isManual,
                'updated_by'          => auth()->id(),
            ];
            if ($isManual) {
                $propertyData['land_cost'] = $validated['land_cost'] ?? null;
            }
            $property->update($propertyData);
        });
```

- [ ] **Step 2: `updateCustomOrder` を `customer_id` 必須 + 名上書き**

`updateCustomOrder` のバリデーション行を変更:
```php
            'customer_id'             => 'required|integer|exists:buyers,id',
```
（`nullable` → `required`）

トランザクション先頭で buyer を引き、`$data` の `customer_name` / `customer_id` を上書き:
```php
        DB::transaction(function () use ($validated, $request, $hsCustomOrder) {
            $buyer = Buyer::withTrashed()->findOrFail($validated['customer_id']);
            $isManual = $request->boolean('is_land_cost_manual');
            $sourceType = $validated['land_source_type'];

            $data = [
                'customer_name'           => $buyer->full_name, // 買主名で上書き
                'customer_id'             => $buyer->id,
                'contract_date'           => $validated['contract_date'],
                'notes'                   => $validated['notes'] ?? null,
                'land_source_type'        => $sourceType,
                'building_contract_price' => $validated['building_contract_price'],
                'tax_rate'                => $validated['tax_rate'],
                'building_cost'           => $validated['building_cost'],
                'is_land_cost_manual'     => $isManual,
                'updated_by'              => auth()->id(),
            ];
            if (!empty($validated['created_by'])) {
                $data['created_by'] = $validated['created_by'];
            }

            // 土地種別に応じた整理（変更なし）
            if ($sourceType === HousingLandSourceType::CustomerLand->value) {
                $data['re_project_lot_id']   = null;
                $data['re_procurement_id']   = null;
                $data['land_selling_price']  = null;
                $data['land_cost']           = null;
                $data['is_land_cost_manual'] = false;
            } else {
                if ($sourceType === HousingLandSourceType::ProjectLot->value) {
                    $data['re_project_lot_id'] = $validated['re_project_lot_id'] ?? null;
                    $data['re_procurement_id'] = null;
                } else {
                    $data['re_procurement_id'] = $validated['re_procurement_id'] ?? null;
                    $data['re_project_lot_id'] = null;
                }
                $data['land_selling_price'] = $validated['land_selling_price'] ?? null;
                if ($isManual) {
                    $data['land_cost'] = $validated['land_cost'] ?? null;
                }
            }

            $hsCustomOrder->update($data);
        });
```

- [ ] **Step 3: `edit-building.blade.php` の顧客欄を partial に置換**

`resources/views/housing/contracts/edit-building.blade.php` の `<div class="hc-field-row">`（424 行）〜その閉じ `</div>`（443 行）= 顧客名 input + 買主マスタ紐付けセレクトの2カラムを、次で置換:

```blade
            <div class="hc-field-row">
                <div class="hc-field" style="flex:1;">
                    @include('housing.contracts._buyer-select', [
                        'buyers'       => $buyers,
                        'selectedId'   => old('customer_id', $hsContract->customer_id),
                        'selectedName' => old('customer_name', $hsContract->customer_name),
                        'department'   => 'housing',
                    ])
                </div>
            </div>
```

- [ ] **Step 4: `edit-custom-order.blade.php` の顧客欄を partial に置換**

`resources/views/housing/contracts/edit-custom-order.blade.php` の `<div class="hc-field-row">`（536 行）〜その閉じ `</div>`（555 行）を、次で置換:

```blade
            <div class="hc-field-row">
                <div class="hc-field" style="flex:1;">
                    @include('housing.contracts._buyer-select', [
                        'buyers'       => $buyers,
                        'selectedId'   => old('customer_id', $hsCustomOrder->customer_id),
                        'selectedName' => old('customer_name', $hsCustomOrder->customer_name),
                        'department'   => 'housing',
                    ])
                </div>
            </div>
```

- [ ] **Step 5: PHP 構文チェック（worktree）**

Run: `php -l app/Http/Controllers/Housing/HsContractListController.php`
Expected: `No syntax errors detected`

罠検査（4フォーム横断）:
```bash
grep -rn "&quot;" resources/views/housing/contracts/_buyer-select.blade.php resources/views/housing/contracts/create.blade.php resources/views/housing/contracts/edit-building.blade.php resources/views/housing/contracts/edit-custom-order.blade.php resources/views/housing/custom-orders/_form.blade.php resources/views/housing/custom-orders/create.blade.php
```
Expected: 0件。

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HsContractListController.php resources/views/housing/contracts/edit-building.blade.php resources/views/housing/contracts/edit-custom-order.blade.php
git commit -m "feat(housing): 契約編集（建売・注文住宅）に買主選択を組み込み customer_id を必須化"
```

---

## Task 6: 統合検証 → FF-merge → デプロイ → 本番確認

**Files:** なし（検証・デプロイのみ）

- [ ] **Step 1: main repo へ FF-merge**

```bash
cd /Users/masanori/site/manage
git checkout 13.x
git merge --ff-only <worktree-branch>
```
新規 PHP クラスは無いため `composer dump-autoload` は不要。

- [ ] **Step 2: ルート・Blade コンパイル検証（main repo）**

```bash
php artisan route:clear
php artisan route:list --path=housing/customers/quick-store
# → POST housing/customers/quick-store ... housing.customers.quick-store が表示されること

php artisan view:clear
php artisan view:cache
# → エラーなく全 Blade が precompile されること（Bug #21 検出）

php artisan config:clear
```
Expected: quick-store ルートが1件表示。`view:cache` がエラー0で完了。

- [ ] **Step 3: tinker でロジック検証（main repo・ローカル実 DB）**

```bash
php artisan tinker --execute='
use App\Models\Buyer;
use App\Models\HsCustomOrder;
// (1) クイック登録相当: housing 所属の買主を作成
$b = Buyer::create(["last_name"=>"検証","first_name"=>"太郎","last_name_kana"=>"ケンショウ","first_name_kana"=>"タロウ","prefecture"=>"愛媛県","city"=>"松山市"]);
$b->addToDepartment("housing", now()->format("Y-m-d"));
echo "buyer_id=".$b->id." full_name=".$b->full_name." ofHousing=".(Buyer::ofDepartment("housing")->where("id",$b->id)->exists()?"yes":"no").PHP_EOL;
// (2) customer_name 上書きロジックの確認（store と同じ式）
$buyer = Buyer::withTrashed()->findOrFail($b->id);
echo "overwrite customer_name => ".$buyer->full_name.PHP_EOL;
// 後始末
$b->departments()->forceDelete(); $b->forceDelete();
echo "cleanup done".PHP_EOL;
'
```
Expected: `ofHousing=yes`、`overwrite customer_name => 検証 太郎`、`cleanup done`。

- [ ] **Step 4: デプロイ**

```bash
./deploy.sh
```
（rsync → 本番で `config:cache && route:cache && view:cache`。`view:cache` 再生成で Bug #21 系を本番でも検出。）

- [ ] **Step 5: 本番 read-only 確認**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp 'cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan route:list --path=housing/customers/quick-store'
# → housing.customers.quick-store が本番でも登録されていること
```
ブラウザ（本番）で `/housing/properties/{未契約物件}/contract/create` と `/housing/custom-orders/create` を開き、買主セレクト + ＋新規モーダルが表示され、モーダル登録 → セレクトに追加・選択 → 契約保存 → 顧客一覧（`/housing/customers`）に出ることを確認（ユーザー操作 or Playwright）。

- [ ] **Step 6: worktree クリーンアップ・origin 反映（ユーザー指示時）**

```bash
git worktree remove .claude/worktrees/<name>
# push はユーザー明示指示があった場合のみ:
# git push origin 13.x
```

---

## Self-Review（spec §5 との照合）

- §5.1 クイック登録 API … Task 1（`quickStore` + ルート、JSON `{id, full_name, full_name_kana, prefecture, city}`、`addToDepartment` rank=C、アンケート無し）✅
- §5.2 create フォームへの買主選択 + モーダル … Task 2 partial（静的 `<option>`・IME ガード・inline style・`x-data` 名前付き関数）+ Task 3/4 組み込み ✅
- §5.3 store の `customer_id` 必須 + `customer_name` 上書き … Task 3/4（共用 validate を避け store 個別で追加）✅
- §5.4 編集画面の必須化 … Task 5（`updateBuilding`/`updateCustomOrder` を required + 名上書き、edit×2 に partial）✅
- §5.5 共通化（partial 化・script インライン内包）… Task 2（`_buyer-select.blade.php` に script 同梱）✅
- §7 エッジケース … contract_date null は既存ガード維持（本変更は触れない）／同名は `checkDup` 非ブロッキング警告／SoftDelete 買主は editController が `withTrashed` で候補に含む（既存）＋ partial は `$buyers` をそのまま描画／`customer_id`↔`customer_name` 不一致はサーバー上書きで解消／二重送信は `submitting` で防止 ✅

**Placeholder スキャン**: 全ステップに実コードを記載。TBD/TODO 無し。
**型整合**: JSON キー（`full_name` 等）・Alpine メソッド名（`onSelect`/`openModal`/`closeModal`/`checkDup`/`submitModal`/`csrf`）・`$refs.sel`・partial 引数（`$buyers`/`$selectedId`/`$selectedName`/`$department`）はタスク間で一貫。

## 既存データ（spec §6）

既存契約2件（今津正則=買主登録済み / 高木豊=未登録）は `customer_id=null`。本フェーズはデータ移行スクリプトを作らない。リリース後、各契約の編集画面で「既存選択（今津）」または「＋新規（高木）」を1回行えば顧客一覧に載る（手動・2件のみ）。
