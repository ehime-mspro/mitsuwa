# テナント契約 削除機能 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 経営層が誤登録したテナント契約を論理削除でき、その際に区画の空室戻し・問合せの未成約差し戻し・投資紐付け解除を巻き戻す。

**Architecture:** 既存 `terminate`（確認画面→実行の2段階・トランザクション内で区画を戻す）を踏襲した最小差分。`Tenant\ContractController` に `confirmDelete`（確認画面）と `destroy`（トランザクションで巻き戻し→論理削除）を追加。DB スキーマ変更なし（`Contract` は既に `SoftDeletes`）、新規 PHP クラスなし。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（Alpine/@json 不使用の単純確認画面）/ PHPUnit（SQLite in-memory + RefreshDatabase）

**設計の正:** `docs/superpowers/specs/2026-07-08-tenant-contract-deletion-design.md`（D1〜D7・T1〜T7）。本計画はその実装手順。

---

## Prerequisites — worktree テスト環境

このプロジェクトの worktree には `vendor/` が無いため、TDD 実行前に一度だけ準備する（既知の罠。`project_test_env_worktree_vendor`）。

- [ ] **P1: worktree を用意**（`superpowers:using-git-worktrees`。ブランチ名例 `tenant-contract-deletion`）
- [ ] **P2: 依存とテスト用ダミー鍵**

```bash
# worktree ルートで
composer install
[ -f .env ] || cp .env.example .env
php artisan key:generate
```

- [ ] **P3: テスト実行の土台を確認**（既存テナント契約テストが通ることでSQLite/RefreshDatabase 環境を検証）

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractReviseEntryTest.php`
Expected: OK（3 tests）。落ちる場合は環境問題として `superpowers:systematic-debugging` で解消してから Task 1 へ。

> テストは `phpunit.xml` で `APP_URL=http://localhost`（パス無し固定）・SQLite in-memory。`vendor/bin/phpunit` を使う（`artisan test` / `pest` は無い）。

---

## File Structure

| ファイル | 区分 | 責務 |
|---|---|---|
| `routes/web.php` | 変更 | STEP 6 契約ブロックに `role:executive` の削除ルート2本を追加（`244-288` 行、賃料改定グループ直後） |
| `app/Http/Controllers/Tenant/ContractController.php` | 変更 | `confirmDelete()`（確認画面・件数集計）と `destroy()`（巻き戻し→論理削除）を追加。import 追加不要（`Inquiry`/`InquiryHistory`/`Investment`/`InquiryStatus`/`UnitStatus`/`DB`/`Auth` は既存） |
| `resources/views/tenant/contracts/delete.blade.php` | 新規 | 削除確認画面。Alpine/@json 不使用。件数はスカラーで受けて表示 |
| `resources/views/tenant/contracts/show.blade.php` | 変更 | 下部アクション群の外に `role:executive` 限定の「契約を削除」ボタンを追加（解約済みでも表示） |
| `tests/Feature/Tenant/ContractDeletionTest.php` | 新規 | T1〜T6 の Feature テスト（RefreshDatabase） |

---

## Task 1: 削除ルート + `destroy()` 骨格 + `confirmDelete()`（バックエンド）

契約中/解約済みの論理削除と区画の空室戻し（`$wasActive` ガード）をまず通す。問合せ/投資の巻き戻しは Task 3/4 で追加する（この時点では未実装）。`confirmDelete()` は件数を集計してビューを返す（ビューは Task 2 で作成。この Task のテストはビューを描画しない）。

**Files:**
- Modify: `routes/web.php`（`244` 行コメント / `288` 行の後にルート追加）
- Modify: `app/Http/Controllers/Tenant/ContractController.php`（`revise()` の後・Ajax API セクションの前にメソッド追加）
- Test: `tests/Feature/Tenant/ContractDeletionTest.php`（新規）

- [ ] **Step 1: 失敗するテストを書く（T1/T2/T5/T6 + ヘルパー）**

`tests/Feature/Tenant/ContractDeletionTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\Investment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * テナント契約の削除（論理削除）機能の検証。
 *
 * 対象テーブル（users/departments/customers/properties/units/contracts/
 * inquiries/inquiry_histories/investments）は Laravel マイグレーション管理のため
 * SQLite in-memory + RefreshDatabase で利用可能。
 * 設計の正: docs/superpowers/specs/2026-07-08-tenant-contract-deletion-design.md
 */
class ContractDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * tenant 部門所属の管理者。department.access:tenant を通過させ、
     * 403 が role:executive ゲート由来であることを保証する。
     */
    private function manager(): User
    {
        $this->seed(DepartmentSeeder::class);
        $manager = User::factory()->create([
            'role' => UserRole::Manager->value,
            'must_change_password' => false,
        ]);
        $manager->departments()->attach(Department::where('code', 'tenant')->value('id'));

        return $manager;
    }

    /** 物件＋区画＋契約を1セット作成して返す（コード重複回避に連番付与） */
    private function makeContract(string $status = 'active', string $unitStatus = 'occupied'): Contract
    {
        $this->seq++;

        $customer = Customer::create([
            'code' => 'CUST-DEL-' . $this->seq,
            'name' => 'テスト商事' . $this->seq,
            'customer_type' => 'corporation',
        ]);

        $property = Property::create([
            'code' => 'PROP-DEL-' . $this->seq,
            'name' => 'テストビル' . $this->seq,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A',
            'display_name' => '1A',
            'status' => $unitStatus,
        ]);

        return Contract::create([
            'contract_number' => 'C-DEL-' . str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'department' => 'tenant',
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'contract_date' => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'contract_end_date' => $status === 'terminated' ? '2026-06-30' : null,
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
        ]);
    }

    /** T1: active 契約削除 → 論理削除され、区画が occupied→vacant に戻る */
    public function test_executive_can_delete_active_contract_and_vacate_unit(): void
    {
        $contract = $this->makeContract('active', 'occupied');
        $unitId = $contract->unit_id;

        $response = $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract));

        $response->assertRedirect(route('tenant.contracts.index'));
        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'vacant']);
    }

    /** T2: terminated 契約削除 → 論理削除されるが、区画ステータスは触らない（$wasActive ガード） */
    public function test_deleting_terminated_contract_does_not_touch_unit(): void
    {
        // 解約後に別テナントが同区画へ入居中（unit=occupied）のシナリオ
        $contract = $this->makeContract('terminated', 'occupied');
        $unitId = $contract->unit_id;

        $response = $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract));

        $response->assertRedirect(route('tenant.contracts.index'));
        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);
        // 現入居者のため occupied のまま（誤って空室化しない）
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'occupied']);
    }

    /** T5: manager は DELETE できない（role:executive で 403） */
    public function test_manager_cannot_delete_contract(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $response = $this->actingAs($this->manager())
            ->delete(route('tenant.contracts.destroy', $contract));

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('contracts', ['id' => $contract->id]);
    }

    /** T6: 削除後は show/edit/terminate が 404（ルートモデルバインディングが soft-delete 除外） */
    public function test_contract_routes_return_404_after_deletion(): void
    {
        $contract = $this->makeContract('active', 'occupied');
        $executive = $this->executive();

        $this->actingAs($executive)->delete(route('tenant.contracts.destroy', $contract));

        $this->actingAs($executive)->get(route('tenant.contracts.show', $contract))->assertNotFound();
        $this->actingAs($executive)->get(route('tenant.contracts.edit', $contract))->assertNotFound();
        $this->actingAs($executive)->get(route('tenant.contracts.terminate', $contract))->assertNotFound();
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractDeletionTest.php`
Expected: FAIL（`Route [tenant.contracts.destroy] not defined.` 相当のエラー。ルート未定義）

- [ ] **Step 3: 削除ルートを追加**

`routes/web.php`。まず `244` 行付近のコメント `テナント契約管理（10ルート）— STEP 6` を `テナント契約管理（12ルート）— STEP 6` に変更。次に賃料改定グループの閉じ（`288` 行 `});`）と STEP 8 コメント（`289` 行 `/*`）の間に以下を挿入:

```php

        // 契約削除（経営層のみ・契約中/解約済みの両方）
        Route::middleware('role:executive')->group(function () {
            Route::get('/contracts/{contract}/delete', [\App\Http\Controllers\Tenant\ContractController::class, 'confirmDelete'])
                ->name('tenant.contracts.delete');
            Route::delete('/contracts/{contract}', [\App\Http\Controllers\Tenant\ContractController::class, 'destroy'])
                ->name('tenant.contracts.destroy');
        });
```

> `{contract}` に `->withTrashed()` は付けない（削除後 404 が仕様）。

- [ ] **Step 4: `confirmDelete()` と `destroy()` 骨格を追加**

`app/Http/Controllers/Tenant/ContractController.php`。`revise()` メソッドの閉じ `}`（`516` 行）と Ajax API セクションのコメント（`518` 行 `/**` `Ajax API: 空室・商談中の区画取得`）の間に以下を挿入:

```php
    /**
     * 契約削除の確認画面
     * Route: GET /tenant/contracts/{contract}/delete
     */
    public function confirmDelete(Contract $contract)
    {
        $contract->load(['property', 'unit', 'customer']);

        // 関連データ件数（多行配列を @json に渡さないためスカラーで個別に渡す・Bug #26 回避）
        $relatedInquiryCount = Inquiry::where('contract_id', $contract->id)->count();
        $hasInvestment       = Investment::where('contract_id', $contract->id)->exists();
        $rentRevisionCount   = $contract->rentRevisions()->count();
        $attachmentCount     = $contract->attachments()->count();

        return view('tenant.contracts.delete', compact(
            'contract',
            'relatedInquiryCount',
            'hasInvestment',
            'rentRevisionCount',
            'attachmentCount'
        ));
    }

    /**
     * 契約削除の実行（論理削除 + 副作用の巻き戻し）
     * Route: DELETE /tenant/contracts/{contract}
     */
    public function destroy(Contract $contract)
    {
        $wasActive = $contract->isActive();

        DB::transaction(function () use ($contract, $wasActive) {
            // 契約中だった場合のみ区画を空室に戻す（terminated は触らない=後続契約の区画を誤って空けないため）
            if ($wasActive) {
                $contract->unit->update(['status' => UnitStatus::Vacant->value]);
            }

            // 契約を論理削除
            $contract->delete();
        });

        return redirect()
            ->route('tenant.contracts.index')
            ->with('success', "契約「{$contract->contract_number}」を削除しました。");
    }
```

- [ ] **Step 5: テストが通ることを確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractDeletionTest.php`
Expected: PASS（4 tests）。`confirmDelete` はまだテストしていない（ビュー未作成のため。Task 2 で描画テスト）。

- [ ] **Step 6: コミット**

```bash
git add routes/web.php app/Http/Controllers/Tenant/ContractController.php tests/Feature/Tenant/ContractDeletionTest.php
git commit -m "feat(tenant): 契約削除ルートと destroy 骨格を追加（区画空室戻し・権限/404）"
```

---

## Task 2: 確認画面ビュー + 削除ボタン + 描画テスト

`delete.blade.php`（確認画面）と `show.blade.php` の削除ボタンを追加。関連データを紐付けた実データで描画し、本番だけの 500（Bug #26 型）を CI で検出する。

**Files:**
- Create: `resources/views/tenant/contracts/delete.blade.php`
- Modify: `resources/views/tenant/contracts/show.blade.php`（`280` 行 `@endif` の後、`282` 行タブセクションの前）
- Test: `tests/Feature/Tenant/ContractDeletionTest.php`（描画テスト2件を追加）

- [ ] **Step 1: 失敗する描画テストを追加**

`ContractDeletionTest` クラスに以下2メソッドを追加:

```php
    /** 描画: executive は確認画面を表示できる（関連データ有りで Bug #26 型 500 を検出） */
    public function test_executive_sees_delete_confirmation_screen(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        Inquiry::create([
            'inquiry_number' => 'INQ-DEL-S',
            'property_id' => $contract->property_id,
            'contact_name' => '確認太郎',
            'inquiry_date' => '2026-03-01',
            'status' => InquiryStatus::Converted->value,
            'contract_id' => $contract->id,
        ]);
        Investment::create([
            'investment_number' => 'INV-DEL-S',
            'property_id' => $contract->property_id,
            'unit_id' => $contract->unit_id,
            'pattern' => 'renovation',
            'description' => '内装',
            'total_amount' => 500000,
            'contract_id' => $contract->id,
        ]);

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.delete', $contract));

        $response->assertOk();
        $response->assertSee('契約削除');
        $response->assertSee($contract->contract_number);
        // DELETE フォームの action が出力されている
        $response->assertSee(route('tenant.contracts.destroy', $contract), false);
    }

    /** 描画: manager は確認画面を開けない（403） */
    public function test_manager_cannot_open_delete_confirmation_screen(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $this->actingAs($this->manager())
            ->get(route('tenant.contracts.delete', $contract))
            ->assertStatus(403);
    }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter test_executive_sees_delete_confirmation_screen tests/Feature/Tenant/ContractDeletionTest.php`
Expected: FAIL（`View [tenant.contracts.delete] not found.`）

- [ ] **Step 3: 確認画面ビューを作成**

`resources/views/tenant/contracts/delete.blade.php` を新規作成（`terminate.blade.php` のクラスを流用＝すべて Vite ビルド済み。Alpine/@json 不使用・route 名は静的シングルクォート）:

```blade
@extends('layouts.app')

@section('title', '契約削除: ' . $contract->contract_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.show', $contract) }}" class="hover:text-emerald-600 transition-colors">{{ $contract->contract_number }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">削除</span>
@endsection

@section('content')

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.contracts.show', $contract) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        契約詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">契約削除: {{ $contract->contract_number }}</h1>

    {{-- 警告 --}}
    <div class="flex items-start gap-2 mb-4 rounded-lg border border-red-200 bg-red-50 p-3.5">
        <svg class="w-5 h-5 text-red-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div class="text-sm text-red-800 leading-relaxed">
            <strong>この契約を削除します。削除すると以下が実行されます:</strong><br>
            ・契約は論理削除され、一覧・詳細から見えなくなります（データはDBに残ります。復元が必要な場合は管理者に連絡してください）。
            @if($contract->isActive())
                <br>・契約中のため、区画「{{ $contract->unit?->display_name ?? '—' }}」は<strong>空室に戻ります</strong>。
            @endif
            <br>・紐づく問合せは<strong>未成約（フォロー）に差し戻され</strong>ます。
            <br>・紐づく投資案件は区画に残り、この契約との紐付けのみ解除されます。
        </div>
    </div>

    {{-- 対象契約情報 --}}
    @php
        $monthlyTotal = $contract->rent + ($contract->common_fee ?? 0) + ($contract->garbage_fee ?? 0) + ($contract->pest_control_fee ?? 0);
        $unit = $contract->unit;
        $dn = $unit?->display_name ?? '';
        $unitLabel = ($unit && $unit->floor !== null && !preg_match('/^\d/', $dn)) ? $unit->floor . $dn : $dn;
    @endphp
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">対象契約</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">契約番号</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->contract_number }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->status->label() }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件 / 区画</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->property?->name ?? '（物件データなし）' }} / {{ $unitLabel !== '' ? $unitLabel : '（区画データなし）' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">テナント</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->customer?->name ?? $contract->store_name ?? '（顧客データなし）' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">月額家賃</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($contract->rent) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">月額合計</div>
                <div class="text-sm font-bold" style="color:#065F46;">{{ number_format($monthlyTotal) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
        </div>
    </div>

    {{-- 関連データ件数（confirmDelete からスカラーで受ける） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">この契約に紐づくデータ</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">投資案件</div>
                <div class="text-sm font-medium text-gray-900">{{ $hasInvestment ? 'あり（紐付け解除）' : 'なし' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">賃料改定履歴</div>
                <div class="text-sm font-medium text-gray-900">{{ $rentRevisionCount }}件</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">紐づく問合せ</div>
                <div class="text-sm font-medium text-gray-900">{{ $relatedInquiryCount }}件</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">添付ファイル</div>
                <div class="text-sm font-medium text-gray-900">{{ $attachmentCount }}件</div>
            </div>
        </div>
    </div>

    {{-- アクションボタン --}}
    <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
        <a href="{{ route('tenant.contracts.show', $contract) }}"
           class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
            キャンセル
        </a>
        <form method="POST" action="{{ route('tenant.contracts.destroy', $contract) }}"
              onsubmit="return confirm('本当にこの契約を削除しますか？この操作は元に戻せません。');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-red-600 text-white rounded-md text-sm font-semibold hover:bg-red-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                削除する
            </button>
        </form>
    </div>

@endsection
```

- [ ] **Step 4: `show.blade.php` に削除ボタンを追加**

`resources/views/tenant/contracts/show.blade.php`。`280` 行の `@endif`（`@if($contract->isActive())` アクション群の閉じ）と `282` 行の `{{-- タブセクション: 賃料改定履歴 / 添付ファイル --}}` の間（現状 `281` は空行）に以下を挿入:

```blade
    {{-- 契約削除（経営層のみ・契約中/解約済み問わず） --}}
    @if(auth()->user()->role->isExecutive())
        <div class="flex flex-wrap gap-2 mb-4">
            <a href="{{ route('tenant.contracts.delete', $contract) }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border rounded-md text-sm font-semibold hover:bg-red-50 transition-colors"
               style="border-color:#fca5a5; color:#b91c1c;">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                契約を削除
            </a>
        </div>
    @endif
```

> 危険色（border/text）は Bug #19 回避のため inline style で確実化。`hover:bg-red-50` / レイアウト系クラスは terminate ボタンで実績のあるビルド済みクラスのみ使用。

- [ ] **Step 5: 描画テストが通ることを確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractDeletionTest.php`
Expected: PASS（6 tests）

- [ ] **Step 6: コンパイル済みビューを lint（Bug #26 対策・view:cache 成功では不十分）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` の出力が無いこと（全ビューが構文的に正しくコンパイルされる）

- [ ] **Step 7: コミット**

```bash
git add resources/views/tenant/contracts/delete.blade.php resources/views/tenant/contracts/show.blade.php tests/Feature/Tenant/ContractDeletionTest.php
git commit -m "feat(tenant): 契約削除の確認画面と削除ボタンを追加"
```

---

## Task 3: 問合せの未成約差し戻し（D6）

`destroy()` に問合せ連携の巻き戻しを追加。`contract_id` を null、`Converted→Follow`、自動理由（`契約登録に伴い成約`）のみクリア、`InquiryHistory` に解除履歴を記録する。

**Files:**
- Modify: `app/Http/Controllers/Tenant/ContractController.php`（`destroy()` のトランザクション先頭に追加）
- Test: `tests/Feature/Tenant/ContractDeletionTest.php`（T3 追加）

- [ ] **Step 1: 失敗するテスト T3 を追加**

`ContractDeletionTest` クラスに追加:

```php
    /** T3: 削除で紐づく問合せが未成約に差し戻される（自動理由はクリア・手動理由は保持） */
    public function test_deleting_contract_unwinds_linked_inquiries(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $auto = Inquiry::create([
            'inquiry_number' => 'INQ-DEL-A',
            'property_id' => $contract->property_id,
            'contact_name' => '問合せ太郎',
            'inquiry_date' => '2026-03-01',
            'status' => InquiryStatus::Converted->value,
            'result_reason' => '契約登録に伴い成約',
            'contract_id' => $contract->id,
        ]);
        $manual = Inquiry::create([
            'inquiry_number' => 'INQ-DEL-B',
            'property_id' => $contract->property_id,
            'contact_name' => '問合せ花子',
            'inquiry_date' => '2026-03-02',
            'status' => InquiryStatus::Converted->value,
            'result_reason' => '個別交渉により成約',
            'contract_id' => $contract->id,
        ]);

        $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract))
            ->assertRedirect(route('tenant.contracts.index'));

        $auto->refresh();
        $this->assertNull($auto->contract_id);
        $this->assertSame(InquiryStatus::Follow, $auto->status);
        $this->assertNull($auto->result_reason); // 自動理由はクリア

        $manual->refresh();
        $this->assertNull($manual->contract_id);
        $this->assertSame(InquiryStatus::Follow, $manual->status);
        $this->assertSame('個別交渉により成約', $manual->result_reason); // 手動理由は保持

        // 解除履歴が各問合せに1件ずつ記録される
        $this->assertDatabaseHas('inquiry_histories', [
            'inquiry_id' => $auto->id,
            'action_type' => 'other',
        ]);
        $this->assertSame(1, InquiryHistory::where('inquiry_id', $auto->id)->count());
        $this->assertSame(1, InquiryHistory::where('inquiry_id', $manual->id)->count());
    }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter test_deleting_contract_unwinds_linked_inquiries tests/Feature/Tenant/ContractDeletionTest.php`
Expected: FAIL（`contract_id` が null にならず、`assertNull($auto->contract_id)` で失敗）

- [ ] **Step 3: `destroy()` に問合せ巻き戻しを追加**

`destroy()` のトランザクションを以下に置き換える（問合せ巻き戻しを区画処理の**前**に追加。処理順: 問合せ→区画→削除）:

```php
        DB::transaction(function () use ($contract, $wasActive) {
            // ① 問合せ連携の解除（未成約に差し戻し・D6）
            $inquiries = Inquiry::where('contract_id', $contract->id)->get();
            foreach ($inquiries as $inquiry) {
                $inquiry->contract_id = null;
                if ($inquiry->status === InquiryStatus::Converted) {
                    $inquiry->status = InquiryStatus::Follow->value;
                    // 契約登録時に自動設定した理由のみクリア（手動入力の理由は残す）
                    if ($inquiry->result_reason === '契約登録に伴い成約') {
                        $inquiry->result_reason = null;
                    }
                }
                $inquiry->save();

                InquiryHistory::create([
                    'inquiry_id'  => $inquiry->id,
                    'action_type' => 'other',
                    'action_date' => now()->toDateString(),
                    'content'     => '契約 ' . $contract->contract_number . ' の削除に伴い連携解除（未成約に差し戻し）',
                    'created_by'  => Auth::id(),
                ]);
            }

            // ② 契約中だった場合のみ区画を空室に戻す（terminated は触らない）
            if ($wasActive) {
                $contract->unit->update(['status' => UnitStatus::Vacant->value]);
            }

            // ③ 契約を論理削除
            $contract->delete();
        });
```

- [ ] **Step 4: 全テストが通ることを確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractDeletionTest.php`
Expected: PASS（7 tests。T1/T2 の区画挙動も回帰なし）

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Tenant/ContractController.php tests/Feature/Tenant/ContractDeletionTest.php
git commit -m "feat(tenant): 契約削除で紐づく問合せを未成約に差し戻す（D6）"
```

---

## Task 4: 投資案件の紐付け解除（D7）

`destroy()` に投資の `contract_id` null 化を追加。`nullOnDelete` は物理削除専用で論理削除では効かないため明示的に行う。投資レコード自体は区画に残す。

**Files:**
- Modify: `app/Http/Controllers/Tenant/ContractController.php`（`destroy()` の問合せ処理と区画処理の間に追加）
- Test: `tests/Feature/Tenant/ContractDeletionTest.php`（T4 追加）

- [ ] **Step 1: 失敗するテスト T4 を追加**

`ContractDeletionTest` クラスに追加:

```php
    /** T4: 削除で投資の紐付けは解除されるが、投資レコードは区画に残る（D7） */
    public function test_deleting_contract_unlinks_investment_but_keeps_it(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $investment = Investment::create([
            'investment_number' => 'INV-DEL-001',
            'property_id' => $contract->property_id,
            'unit_id' => $contract->unit_id,
            'pattern' => 'renovation',
            'status' => 'recovering',
            'description' => '内装改修',
            'end_date' => '2026-03-31',
            'total_amount' => 1000000,
            'contract_id' => $contract->id,
        ]);

        $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract))
            ->assertRedirect(route('tenant.contracts.index'));

        $investment->refresh();
        $this->assertNull($investment->contract_id);   // 紐付け解除
        $this->assertFalse($investment->trashed());     // 投資レコードは残る
        $this->assertDatabaseHas('investments', ['id' => $investment->id, 'contract_id' => null]);
    }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter test_deleting_contract_unlinks_investment_but_keeps_it tests/Feature/Tenant/ContractDeletionTest.php`
Expected: FAIL（`contract_id` が null にならず `assertNull($investment->contract_id)` で失敗）

- [ ] **Step 3: `destroy()` に投資紐付け解除を追加**

`destroy()` のトランザクション内、問合せループ（① の閉じ `}`）の後・区画処理（② の `if ($wasActive)`）の前に、以下1行のコメントブロックを挿入:

```php
            // ②' 投資案件の紐付け解除（投資レコードは区画に残す・D7。nullOnDelete は物理削除専用のため明示 null 化）
            Investment::where('contract_id', $contract->id)->update(['contract_id' => null]);
```

挿入後のトランザクション全体（確認用）:

```php
        DB::transaction(function () use ($contract, $wasActive) {
            // ① 問合せ連携の解除（未成約に差し戻し・D6）
            $inquiries = Inquiry::where('contract_id', $contract->id)->get();
            foreach ($inquiries as $inquiry) {
                $inquiry->contract_id = null;
                if ($inquiry->status === InquiryStatus::Converted) {
                    $inquiry->status = InquiryStatus::Follow->value;
                    if ($inquiry->result_reason === '契約登録に伴い成約') {
                        $inquiry->result_reason = null;
                    }
                }
                $inquiry->save();

                InquiryHistory::create([
                    'inquiry_id'  => $inquiry->id,
                    'action_type' => 'other',
                    'action_date' => now()->toDateString(),
                    'content'     => '契約 ' . $contract->contract_number . ' の削除に伴い連携解除（未成約に差し戻し）',
                    'created_by'  => Auth::id(),
                ]);
            }

            // ②' 投資案件の紐付け解除（投資レコードは区画に残す・D7。nullOnDelete は物理削除専用のため明示 null 化）
            Investment::where('contract_id', $contract->id)->update(['contract_id' => null]);

            // ② 契約中だった場合のみ区画を空室に戻す（terminated は触らない）
            if ($wasActive) {
                $contract->unit->update(['status' => UnitStatus::Vacant->value]);
            }

            // ③ 契約を論理削除
            $contract->delete();
        });
```

- [ ] **Step 4: 全テストが通ることを確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractDeletionTest.php`
Expected: PASS（8 tests）

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Tenant/ContractController.php tests/Feature/Tenant/ContractDeletionTest.php
git commit -m "feat(tenant): 契約削除で投資案件の紐付けを解除（D7）"
```

---

## Task 5: 総合検証 + デプロイ準備

実装完了後の総合確認。回帰・ビューコンパイル・実データ描画を確認し、デプロイ手順を提示する。

**Files:** なし（検証のみ）

- [ ] **Step 1: テナント契約まわりの回帰確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/`
Expected: PASS（`ContractDeletionTest` 8 + 既存 `ContractReviseEntryTest` 3 ほか、全て緑）

- [ ] **Step 2: 全ビューのコンパイル lint（Bug #26 最終ゲート）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 出力なし

- [ ] **Step 3: セルフレビュー**

`/review`（code-review プラグイン）で差分をレビュー。過去バグ（#21/#22/#23/#26）と本プロジェクト規約に照らして確認。

- [ ] **Step 4: main への統合**

worktree で `/commit` 済みの前提。main repo（`/Users/masanori/site/manage`）で:
```bash
git checkout 13.x && git merge --ff-only <branch>
```
> 新規 PHP クラスなし（メソッド追加のみ）→ `composer dump-autoload` 不要。DB 変更なし → SQL / マイグレーション不要。

- [ ] **Step 5: 本番デプロイ（要ユーザー明示承認）**

`AskUserQuestion` で本番デプロイ可否を確認してから `./deploy.sh`（生 ssh は分類器にブロックされる。`project_deploy_needs_explicit_user_authorization`）。デプロイ後、実データ（投資・問合せ・添付が紐づく active 契約 / terminated 契約）で確認画面→削除を実行し、区画ステータス・問合せ差し戻し・投資紐付け解除・削除後404 を確認（spec §6）。

- [ ] **Step 6: origin/13.x への push**

ユーザー明示指示があった場合のみ実施。

---

## Self-Review（計画の自己点検）

**Spec カバレッジ（D1〜D7 / T1〜T7）:**
- D1 論理削除・DB変更なし → Task 1 `destroy()` の `$contract->delete()`（`Contract` は既に SoftDeletes）。復元UIはスコープ外。
- D2 executive 限定 → Task 1 ルートの `role:executive` グループ / Task 2 ボタンの `isExecutive()`。T5 で担保。
- D3 active/terminated 両方 → `destroy()` は status で分岐せず削除。T1（active）/T2（terminated）で担保。
- D4 関連データは警告のみ・物理削除しない → Task 2 確認画面の件数表示。投資/問合せは巻き戻しのみ、添付は不変。
- D5 専用確認画面（2段階）→ Task 1 `confirmDelete` + Task 2 `delete.blade.php`。
- D6 問合せ差し戻し → Task 3。T3 で自動/手動理由の差・履歴記録まで担保。
- D7 投資紐付け解除 → Task 4。T4 で紐付け解除＋レコード残存を担保。
- T1〜T6 → 各 Task のテストに実装済み。**T7（回収/賃料集計からの自動除外）は spec でも「任意」**。`calculateRecovery()` は unit_id ベースかつ論理削除でグローバルスコープ除外されるため契約削除で自動的に集計外となる（コード改変不要）。回帰リスクが低く、必要なら Task 5 の実データ確認で目視。計画では独立テスト化を省略（YAGNI）。

**プレースホルダscan:** 各ステップに完全なコード・正確なコマンド・期待出力を記載。TBD/TODO なし。

**型・シグネチャ整合:** `confirmDelete(Contract $contract)` / `destroy(Contract $contract)`、`InquiryStatus::Converted`/`::Follow`、`UnitStatus::Vacant`、`InquiryHistory::create([...])`（fillable 一致）、`Investment::where('contract_id', ...)->update(...)`、`$contract->rentRevisions()`/`->attachments()`（実在リレーション）を全 Task で統一。ビューに渡す変数名（`relatedInquiryCount`/`hasInvestment`/`rentRevisionCount`/`attachmentCount`/`contract`）は `confirmDelete` の `compact()` と `delete.blade.php` の参照で一致。

**罠の回避（本プロジェクト固有）:**
- Bug #21: 確認画面の route 名は静的シングルクォート、`&quot;` 不使用。
- Bug #22: cast 済み enum は直接比較（`=== InquiryStatus::Converted`）。`tryFrom` 不使用。
- Bug #23/#26: `delete.blade.php` は Alpine/@json 不使用、件数はスカラー。検証は `php -l` ループ（Task 2 Step6 / Task 5 Step2）。
- Bug #19: 削除ボタンの危険色は inline style。レイアウトは terminate 実績クラスのみ。
- 最重要リスク（terminated 削除で現入居者を空室化）: `$wasActive` ガード。T2 で担保。
