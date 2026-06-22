# 区画詳細からの家賃改定 入口追加 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント管理の家賃（賃料）改定を、契約詳細だけでなく区画詳細画面からも実行できるようにし、その起点のときは改定後に区画詳細へ戻す。

**Architecture:** 改定の入力画面・処理ロジック・`RentRevision` 履歴は既存（`tenant.contracts.revise` / `ContractController::revise`）を完全再利用。共有フローに `return_to` フラグ（`unit` のみ特別扱い・それ以外＝従来＝契約詳細）を1つ通すだけ。区画詳細に「賃料改定」ボタンと「契約詳細を見る」リンクを追加。新規ルート・テーブル・スキーマ変更なし。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade / PHPUnit（SQLite in-memory, `RefreshDatabase`）。

**設計書:** [docs/superpowers/specs/2026-06-22-tenant-unit-rent-revision-design.md](../specs/2026-06-22-tenant-unit-rent-revision-design.md)

---

## 作業環境（重要 — 必読）

- 本プランは worktree ではなく main repo（`/Users/masanori/site/manage`）の feature ブランチで実施する。
  理由: DB を伴う Feature テスト（`vendor/bin/phpunit`）には dev 依存（phpunit）が必要だが、worktree には vendor が一切無くテストが走らない。main repo は本番同期のため現在 `--no-dev` 状態で phpunit 未インストール。
- 手順の骨子:
  1. main repo（branch `13.x`・作業ツリーは clean・spec/plan は既に 13.x にコミット済み）で feature ブランチを切る。
  2. `composer install`（dev 依存を入れて phpunit を使えるようにする）。
  3. TDD で実装。
  4. 全テスト green を確認したら `composer install --no-dev`（本番同期用 vendor に戻す）。
  5. `13.x` に `--ff-only` マージ → `./deploy.sh`。
- 新規 PHP クラスは追加しない（Controller 改修＋Blade 改修＋テスト追加のみ）ため `composer dump-autoload` は不要。`tests/` は deploy.sh の rsync 除外対象なので本番には送られない。

---

## ファイル構成

| 区分 | パス | 責務 |
|---|---|---|
| Modify | `app/Http/Controllers/Tenant/ContractController.php` | `showRevise` に `Request` を受けて `$returnTo` を算出・ビューへ渡す / `revise` の成功リダイレクトを `return_to` で分岐 |
| Modify | `resources/views/tenant/contracts/revise.blade.php` | パンくず・戻る・キャンセルの遷移先を `$returnTo` で分岐 ＋ hidden `return_to` を POST まで保持 |
| Modify | `resources/views/tenant/units/show.blade.php` | 「現在の契約条件」カードに「賃料改定」ボタン（経営層のみ）＋「現在の契約」タブの「契約詳細を見る →」リンクを有効化 |
| Create | `tests/Feature/Tenant/ContractReviseEntryTest.php` | `revise` のリダイレクト分岐（return_to 有無）と経営層限定（403）を検証 |

---

## Task 0: ブランチ作成と dev 依存インストール

**Files:** （コード変更なし・環境準備）

- [ ] **Step 1: main repo で clean / branch を確認**

Run: `cd /Users/masanori/site/manage && git status --short && git branch --show-current`
Expected: 出力なし（clean）＋ `13.x`

- [ ] **Step 2: feature ブランチを作成**

Run: `cd /Users/masanori/site/manage && git checkout -b feature/tenant-unit-rent-revision`
Expected: `Switched to a new branch 'feature/tenant-unit-rent-revision'`

- [ ] **Step 3: dev 依存（phpunit 等）をインストール**

Run: `cd /Users/masanori/site/manage && composer install`
Expected: 完了後 `vendor/bin/phpunit` が存在する（`ls vendor/bin/phpunit`）。

- [ ] **Step 4: ベースラインのテストが green か確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit 2>&1 | tail -20`
Expected: 既存テストがすべて PASS。1件でも赤いなら本実装前に原因を確認する（環境問題の切り分け）。

---

## Task 1: Controller — `return_to` リダイレクト分岐（TDD）

**Files:**
- Create: `tests/Feature/Tenant/ContractReviseEntryTest.php`
- Modify: `app/Http/Controllers/Tenant/ContractController.php`（`showRevise` 約436-448行 / `revise` の末尾リダイレクト 約498-500行）

- [ ] **Step 1: 失敗するテストを書く**

Create `tests/Feature/Tenant/ContractReviseEntryTest.php`:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 区画詳細からの家賃改定 入口追加（return_to 分岐）の検証。
 *
 * 対象テーブル（users/departments/customers/properties/units/contracts/rent_revisions）は
 * Laravel マイグレーション管理のため SQLite in-memory + RefreshDatabase で利用可能。
 * 改定 POST はリダイレクトを返すため Blade 全体描画には依存しない（描画は Playwright で別途確認）。
 */
class ContractReviseEntryTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 入居中の区画＋契約中の契約を1セット作成して返す */
    private function makeActiveContract(): Contract
    {
        $customer = Customer::create([
            'code' => 'CUST-RT-001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);

        $property = Property::create([
            'code' => 'PROP-RT-001',
            'name' => 'テストビル',
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A',
            'display_name' => '1A',
            'status' => 'occupied',
        ]);

        return Contract::create([
            'contract_number' => 'C-TEST-001',
            'department' => 'tenant',
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => 'active',
            'contract_date' => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
        ]);
    }

    /** return_to 不在 → 契約詳細にリダイレクト（既存挙動の回帰防止）＋履歴作成・費用更新 */
    public function test_revise_without_return_to_redirects_to_contract_show(): void
    {
        $contract = $this->makeActiveContract();

        $response = $this->actingAs($this->executive())->post(
            route('tenant.contracts.revise.execute', $contract),
            [
                'revision_date' => '2026-07-01',
                'new_rent' => 150000,
                'new_common_fee' => 12000,
                'new_garbage_fee' => 2000,
                'new_pest_control_fee' => 1000,
                'reason' => 'テスト改定',
            ]
        );

        $response->assertRedirect(route('tenant.contracts.show', $contract));

        $this->assertDatabaseHas('rent_revisions', [
            'contract_id' => $contract->id,
            'old_rent' => 100000,
            'new_rent' => 150000,
        ]);

        $contract->refresh();
        $this->assertSame(150000, $contract->rent);
        $this->assertSame(12000, $contract->common_fee);
    }

    /** return_to=unit → 区画詳細にリダイレクト */
    public function test_revise_with_return_to_unit_redirects_to_unit_show(): void
    {
        $contract = $this->makeActiveContract();

        $response = $this->actingAs($this->executive())->post(
            route('tenant.contracts.revise.execute', $contract),
            [
                'revision_date' => '2026-07-01',
                'new_rent' => 150000,
                'return_to' => 'unit',
            ]
        );

        $response->assertRedirect(route('tenant.units.show', $contract->unit));
    }

    /** 非経営層（manager）は賃料改定フォームにアクセスできない（403） */
    public function test_revise_route_blocks_manager(): void
    {
        $contract = $this->makeActiveContract();

        $manager = User::factory()->create([
            'role' => UserRole::Manager->value,
            'must_change_password' => false,
        ]);

        // department.access:tenant を通すため tenant 部門に所属させ、403 が role ゲート由来であることを保証
        $this->seed(DepartmentSeeder::class);
        $manager->departments()->attach(Department::where('code', 'tenant')->value('id'));

        $response = $this->actingAs($manager)->get(
            route('tenant.contracts.revise', $contract)
        );

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/ContractReviseEntryTest.php 2>&1 | tail -25`
Expected: `test_revise_with_return_to_unit_redirects_to_unit_show` が FAIL（現状は return_to を見ず契約詳細にリダイレクトするため）。他2件（without / manager 403）は現状コードでも PASS。もし他2件が落ちる場合は環境問題（DB/seed/route）なので先に解消する。

- [ ] **Step 3: `showRevise` に `Request` を受け `$returnTo` を渡す**

`app/Http/Controllers/Tenant/ContractController.php` の `showRevise`（現状 `public function showRevise(Contract $contract)`）を次に置き換える:

```php
    /**
     * 賃料改定フォーム
     * Route: GET /tenant/contracts/{contract}/revise
     */
    public function showRevise(Request $request, Contract $contract)
    {
        // 契約中のみアクセス可能
        if ($contract->isTerminated()) {
            return redirect()
                ->route('tenant.contracts.show', $contract)
                ->with('error', '解約済みの契約は賃料改定できません。');
        }

        $contract->load(['property', 'unit', 'customer']);

        // 改定の起点が区画詳細のときのみ 'unit'。それ以外（不在・不正値）は従来どおり 'contract'
        $returnTo = $request->query('return_to') === 'unit' ? 'unit' : 'contract';

        return view('tenant.contracts.revise', compact('contract', 'returnTo'));
    }
```

- [ ] **Step 4: `revise` の成功リダイレクトを `return_to` で分岐**

同ファイル `revise()` 末尾の現状:

```php
        return redirect()
            ->route('tenant.contracts.show', $contract)
            ->with('success', "契約「{$contract->contract_number}」の賃料改定を実行しました。");
```

を次に置き換える（`DB::transaction(...)` ブロックは変更しない・直後のリダイレクトのみ差し替え）:

```php
        // 起点が区画詳細なら区画詳細へ、それ以外は従来どおり契約詳細へ戻す
        if ($request->input('return_to') === 'unit') {
            return redirect()
                ->route('tenant.units.show', $contract->unit)
                ->with('success', "区画「{$contract->unit->display_name}」の賃料改定を実行しました。");
        }

        return redirect()
            ->route('tenant.contracts.show', $contract)
            ->with('success', "契約「{$contract->contract_number}」の賃料改定を実行しました。");
```

（`revise(Request $request, Contract $contract)` は既に `$request` を受け取っている。`$contract->unit` は遅延ロードで取得される。）

- [ ] **Step 5: テストを実行して PASS を確認**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit tests/Feature/Tenant/ContractReviseEntryTest.php 2>&1 | tail -15`
Expected: 3件すべて PASS（OK (3 tests)）。

- [ ] **Step 6: 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l app/Http/Controllers/Tenant/ContractController.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add app/Http/Controllers/Tenant/ContractController.php tests/Feature/Tenant/ContractReviseEntryTest.php
git commit -m "feat(tenant): 賃料改定に return_to で区画詳細へ戻る分岐を追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `revise.blade.php` — 遷移先の分岐 ＋ hidden `return_to`

**Files:**
- Modify: `resources/views/tenant/contracts/revise.blade.php`（breadcrumb 約5-14行 / 戻るリンク 約18-23行 / `@csrf` 約94-95行 / キャンセル 約157-160行）

> ビュー描画分岐は Feature テストの全体描画依存を避けるため、ここでは静的 lint ＋ Task 4 の Playwright で確認する。`return_to` の挙動（POST）は Task 1 で担保済み。

- [ ] **Step 1: パンくずを `$returnTo` で分岐**

現状の `@section('breadcrumb') ... @endsection`（約5-14行）を次に置き換える:

```blade
@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    @if(($returnTo ?? 'contract') === 'unit')
        <a href="{{ route('tenant.properties.show', $contract->property) }}" class="hover:text-emerald-600 transition-colors">{{ $contract->property->name }}</a>
        <span class="mx-1.5">›</span>
        <a href="{{ route('tenant.units.show', $contract->unit) }}" class="hover:text-emerald-600 transition-colors">区画: {{ $contract->unit->display_name }}</a>
    @else
        <a href="{{ route('tenant.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約一覧</a>
        <span class="mx-1.5">›</span>
        <a href="{{ route('tenant.contracts.show', $contract) }}" class="hover:text-emerald-600 transition-colors">{{ $contract->contract_number }}</a>
    @endif
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">賃料改定</span>
@endsection
```

- [ ] **Step 2: 戻るリンクを `$backUrl` 化（`@section('content')` 冒頭・約18-23行）**

現状:

```blade
    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.contracts.show', $contract) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        契約詳細に戻る
    </a>
```

を次に置き換える（先頭に `$backUrl` を定義し、文言も分岐）:

```blade
    @php
        $isFromUnit = ($returnTo ?? 'contract') === 'unit';
        $backUrl = $isFromUnit ? route('tenant.units.show', $contract->unit) : route('tenant.contracts.show', $contract);
    @endphp

    {{-- 戻るリンク --}}
    <a href="{{ $backUrl }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        {{ $isFromUnit ? '区画詳細に戻る' : '契約詳細に戻る' }}
    </a>
```

- [ ] **Step 3: hidden `return_to` を追加（`@csrf` 直後・約94-95行）**

現状:

```blade
    <form method="POST" action="{{ route('tenant.contracts.revise.execute', $contract) }}">
        @csrf
```

を次に置き換える:

```blade
    <form method="POST" action="{{ route('tenant.contracts.revise.execute', $contract) }}">
        @csrf
        <input type="hidden" name="return_to" value="{{ $returnTo ?? 'contract' }}">
```

- [ ] **Step 4: キャンセルボタンを `$backUrl` 化（約157-160行）**

現状:

```blade
            <a href="{{ route('tenant.contracts.show', $contract) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
```

を次に置き換える（`$backUrl` は Step 2 で同 `@section('content')` 内に定義済み）:

```blade
            <a href="{{ $backUrl }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
```

- [ ] **Step 5: Blade 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l resources/views/tenant/contracts/revise.blade.php`
Expected: `No syntax errors detected`（本格検証は Task 4 の view:cache）

- [ ] **Step 6: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add resources/views/tenant/contracts/revise.blade.php
git commit -m "feat(tenant): 賃料改定画面の戻り先を return_to で区画/契約に分岐

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `units/show.blade.php` — 賃料改定ボタン ＋ 契約詳細リンク

**Files:**
- Modify: `resources/views/tenant/units/show.blade.php`（「現在の契約条件」カードのタイトル 約176行 / 「現在の契約」タブ内のコメントアウト 約295-298行）

- [ ] **Step 1: 「現在の契約条件」カードのタイトル行に「賃料改定」ボタンを追加**

現状（約176行）:

```blade
            <div class="text-sm font-bold pb-2 mb-3 border-b border-blue-200" style="color:#1e3a5f">現在の契約条件</div>
```

を次に置き換える（経営層のみボタン表示・既存改定ルートへ `return_to=unit` 付きで遷移）:

```blade
            <div class="flex items-center justify-between pb-2 mb-3 border-b border-blue-200">
                <span class="text-sm font-bold" style="color:#1e3a5f">現在の契約条件</span>
                @if(auth()->user()->role->isExecutive())
                    <a href="{{ route('tenant.contracts.revise', ['contract' => $activeContract, 'return_to' => 'unit']) }}"
                       style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; font-size:12px; font-weight:600; color:#b45309; border:1px solid #fde68a; border-radius:6px; text-decoration:none; background:#fff;">
                        <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        賃料改定
                    </a>
                @endif
            </div>
```

> このブロックは `@if($unit->status === Occupied && $activeContract)` の内側なので、`$activeContract` は必ず存在する。スタイルは units/show の既存ボタン慣習（inline style）に合わせたアンバー系。

- [ ] **Step 2: 「契約詳細を見る →」リンクを有効化**

「現在の契約」タブ内の現状コメントアウト（約295-298行）:

```blade
                        {{-- 契約詳細リンク（STEP 6で実装後にルートを有効化）--}}
                        {{-- <div class="mt-3 pt-3 border-t border-gray-100">
                            <a href="#" class="text-sm text-emerald-600 hover:text-emerald-700 font-semibold">契約詳細を見る →</a>
                        </div> --}}
```

を次に置き換える:

```blade
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <a href="{{ route('tenant.contracts.show', $activeContract) }}" class="text-sm text-emerald-600 hover:text-emerald-700 font-semibold">契約詳細を見る →</a>
                        </div>
```

- [ ] **Step 3: Blade 構文チェック**

Run: `cd /Users/masanori/site/manage && php -l resources/views/tenant/units/show.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: コミット**

Run:
```bash
cd /Users/masanori/site/manage
git add resources/views/tenant/units/show.blade.php
git commit -m "feat(tenant): 区画詳細に賃料改定ボタンと契約詳細リンクを追加

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: 総合検証 → main 反映 → デプロイ

**Files:** （検証・マージ・デプロイのみ）

- [ ] **Step 1: 全テストを実行（green 確認）**

Run: `cd /Users/masanori/site/manage && vendor/bin/phpunit 2>&1 | tail -20`
Expected: 既存テスト＋新規 `ContractReviseEntryTest`（3件）すべて PASS。

- [ ] **Step 2: view:cache でコンパイルし、コンパイル済み PHP を `php -l`（Bug #26 同型ガード）**

Run: `cd /Users/masanori/site/manage && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear && echo "view lint done"`
Expected: `INVALID:` の行が出ないこと＋末尾 `view lint done`。（本件は多行 `@json` を使わないが、Blade 分岐追加のため一応コンパイル後 lint で担保。）

- [ ] **Step 3: 本番同期用に dev 依存を外す（デプロイ前に必須）**

Run: `cd /Users/masanori/site/manage && composer install --no-dev 2>&1 | tail -5`
Expected: 完了（vendor から dev パッケージが除去され本番同等に戻る）。
> ⚠ この後はテスト実行不可（phpunit が消える）。テストを再実行したくなったら再度 `composer install` する。

- [ ] **Step 4: feature ブランチを `13.x` に fast-forward マージ**

Run: `cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only feature/tenant-unit-rent-revision && git log --oneline -5`
Expected: fast-forward 成功。`13.x` の先頭に Task1〜3 の3コミットが乗る。
> 新規 PHP クラスは無いので `composer dump-autoload` は不要。

- [ ] **Step 5: 本番へデプロイ**

Run: `cd /Users/masanori/site/manage && ./deploy.sh`
Expected: rsync 転送 → 本番で `config:cache && route:cache && view:cache` 成功。

- [ ] **Step 6: 本番動作確認（Playwright または手動）**

確認シナリオ（経営層アカウントで本番 `https://www.mitsuwat.co.jp/system/manage`）:
1. 入居中の区画詳細を開く → 「現在の契約条件」カード右に「賃料改定」ボタンが表示される。
2. ボタン押下 → 改定フォームに遷移。パンくず・戻る・キャンセルが区画詳細を指す。
3. 新・月額家賃を変更して「賃料改定を実行する」→ 区画詳細に戻り、成功メッセージ表示。区画詳細の契約家賃が更新済み。
4. 「現在の契約」タブの「契約詳細を見る →」で契約詳細に遷移できる。
5. （回帰）契約一覧 → 契約詳細 →「賃料改定」→ 実行で、従来どおり契約詳細に戻る（区画には飛ばない）。
6. （任意）一般担当アカウントで区画詳細を開くと「賃料改定」ボタンが出ない。

- [ ] **Step 7: feature ブランチを削除（任意・後片付け）**

Run: `cd /Users/masanori/site/manage && git branch -d feature/tenant-unit-rent-revision`
Expected: `Deleted branch feature/tenant-unit-rent-revision`
> `origin/13.x` への push はユーザーの明示指示があった時のみ。

---

## セルフレビュー結果（spec 突合）

- spec §3.1 Controller 改修 → Task 1（showRevise の Request/$returnTo、revise の分岐）✅
- spec §3.2 revise.blade 分岐＋hidden → Task 2 ✅
- spec §3.3 units/show ボタン＋契約詳細リンク → Task 3 ✅
- spec §4 安全性（return_to は unit 以外すべて contract 扱い／`$contract->unit` リレーション使用でIDOR無し／二重認可） → Controller 三項演算と Blade `@if isExecutive` で担保、Task 1 の manager 403 テストで認可確認 ✅
- spec §6 テスト（回帰=契約詳細・return_to=unit=区画詳細・403） → Task 1 の3メソッド ✅／view:cache 後 `php -l` → Task 4 Step 2 ✅
- プレースホルダ無し（全 Step に実コード・実コマンド・期待出力あり）✅
- 型・名称整合: ルート名 `tenant.contracts.revise` / `tenant.contracts.revise.execute` / `tenant.units.show` / `tenant.contracts.show`、フラグ値 `'unit'` / `'contract'`、`$returnTo` を Controller→Blade で一貫使用 ✅
