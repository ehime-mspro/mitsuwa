# 住宅事業 契約管理 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 既存の`HsContractListController`を拡張し、建売契約と注文住宅契約の統合管理ページに編集機能・新規登録導線・注文住宅詳細を追加する。

**Architecture:** 既存の統合コントローラ方式（案A）を踏襲。新規テーブル・モデルは作成せず、`HsContract`・`HsCustomOrder`・`HsProperty`の既存モデルを活用。URL構造は既存の`/housing/contracts/{hsContract}`を維持しつつ、`/housing/contracts/custom-order/{id}`等を追加。

**Tech Stack:** Laravel 12.x, PHP 8.5.4, MySQL 8.0, Blade, Tailwind CSS v4 (Vite build), Alpine.js v3

**Spec:** `docs/superpowers/specs/2026-04-16-housing-contracts-design.md`

**Environment Note:** PHP CLIなし。テストはブラウザによる手動動作確認が中心。`sudo mysql manage < file.sql` でSQL実行、`sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2` でキャッシュクリア。`sed`はBSD版（`sed -i ''`）。

---

## 実装フェーズ構成

| Phase | 内容 | 主要タスク数 |
|---|---|---|
| Phase 1 | デザインモック作成 → ユーザー承認 | 1 |
| Phase 2 | Controller拡張（DTO・ルート定義） | 3 |
| Phase 3 | 一覧ページ改修 | 2 |
| Phase 4 | 詳細ページ（建売改修+注文住宅新規） | 2 |
| Phase 5 | 編集ページ（建売+注文住宅） | 2 |
| Phase 6 | 新規登録導線 | 2 |
| Phase 7 | 手動テスト + 完了確認 | 1 |

---

# Phase 1: デザインモック作成

## Task 1: デザインモック6種作成・ユーザー承認

CLAUDE.md「実装する前はデザインモックで確認」方針に従う。静的HTMLで6画面のモックを作成し、ユーザー承認後に実装着手。

**Files:**
- Create: `docs/mockups/housing-contracts/index.html`
- Create: `docs/mockups/housing-contracts/show-building.html`
- Create: `docs/mockups/housing-contracts/show-custom-order.html`
- Create: `docs/mockups/housing-contracts/edit-building.html`
- Create: `docs/mockups/housing-contracts/edit-custom-order.html`
- Create: `docs/mockups/housing-contracts/select-building-property.html`

- [ ] **Step 1: モック用ディレクトリ作成**

```bash
mkdir -p docs/mockups/housing-contracts
```

- [ ] **Step 2: 既存`index.blade.php`を元に`index.html`モック作成**

既存の `resources/views/housing/contracts/index.blade.php` (200行) をベースに、以下の変更を加えた静的HTMLを作る:
- サマリーカードを5枚に（契約件数/契約額/土地粗利/建物粗利/合計粗利）
- テーブルを11列に（契約日/種別/物件名/顧客/契約額/土地粗利/建物粗利/合計粗利/進行状況/担当/アクション）
- 右上に `[+ 新規契約登録]` ドロップダウンボタン（Alpine.js）
- 各セルにサンプルデータ（建売3件、注文住宅2件）

Viteビルドされたクラスは`<link rel="stylesheet" href="../../build/assets/app-xxxx.css">`相対参照、またはCDN TailwindでモックのみOK。

- [ ] **Step 3: `show-building.html`（建売詳細改修版）モック作成**

既存 `show.blade.php` をベースに、ヘッダー右のボタンを `[編集] [元ページへ] [戻る] [削除]` に変更した版を作成。

- [ ] **Step 4: `show-custom-order.html`（注文住宅詳細・新規）モック作成**

セクション構成:
- ヘッダー（注文住宅バッジ、order_name、[編集][元ページへ][戻る]）
- 基本情報 + 金額サマリー（並列）
- 契約金額内訳（土地販売価格/建物契約価格/建物消費税/税込合計）
- 原価内訳（土地原価/建物原価/合計原価）
- 粗利分析（土地粗利/建物粗利/合計粗利）
- 物件情報（注文コード/住所/面積/構造/階数/完成予定/引渡日）
- **進行ステータスバー**（商談→設計→見積→契約→着工→完成→引渡し）

**顧客所有地パターンも1ファイル内に併記**（2パターン見せる）または別ファイル`show-custom-order-customer-land.html`として、土地関連項目が「— （顧客所有地）」になる挙動を見せる。

- [ ] **Step 5: `edit-building.html`（建売編集フォーム・新規）モック作成**

フォームセクション:
- 基本情報（契約日/顧客/担当者/備考）
- 契約金額（土地販売価格/建物販売価格/税率）
- 原価（土地原価[手動入力切替]/建物原価）
- 送信ボタン（キャンセル/更新/元ページで全項目編集）

`margin-bottom:26px`で項目間隔。`placeholder="0"`禁止。

- [ ] **Step 6: `edit-custom-order.html`（注文住宅編集フォーム・新規）モック作成**

建売編集と同構造だが、Alpine.jsで`land_source_type`に応じた表示切替:
- `customer_land` → 土地販売価格・土地原価セクション非表示
- それ以外 → 全表示

- [ ] **Step 7: `select-building-property.html`（建売物件選択画面・新規）モック作成**

未契約物件のカード/テーブルリスト。各行に「この物件で契約登録する」ボタン。空状態「未契約の建売物件がありません」。

- [ ] **Step 8: モック6ファイルをユーザーに提示し承認取得**

ユーザーにファイルパスを示し、レビューを依頼:

```
docs/mockups/housing-contracts/index.html
docs/mockups/housing-contracts/show-building.html
docs/mockups/housing-contracts/show-custom-order.html
docs/mockups/housing-contracts/edit-building.html
docs/mockups/housing-contracts/edit-custom-order.html
docs/mockups/housing-contracts/select-building-property.html
```

**承認までコード実装に着手しない。**修正指示があればモックに反映、再承認。

- [ ] **Step 9: モック承認後Commit**

```bash
git add docs/mockups/housing-contracts/
git commit -m "住宅事業 契約管理のデザインモックを追加（6画面）"
```

---

# Phase 2: Controller拡張（DTO・ルート定義）

## Task 2: ルート定義追加（routes/web.php）

**Files:**
- Modify: `routes/web.php` (L678-686付近に追加)

- [ ] **Step 1: 既存ルート定義箇所を確認**

`routes/web.php` の L670-690 を読み、既存の `housing.contract-list.index` / `housing.contract-list.show` 定義箇所と、住宅事業プレフィックスの構造を把握。

- [ ] **Step 2: 新規ルート追加**

既存の `housing.contract-list.index` の直後（建売物件ルート群の前）に、以下を追加:

```php
// 新規登録導線（全員=staff含む）
Route::get('/contracts/create/building',
    [\App\Http\Controllers\Housing\HsContractListController::class, 'createBuilding'])
    ->name('housing.contract-list.create-building');
Route::get('/contracts/create/custom-order',
    [\App\Http\Controllers\Housing\HsContractListController::class, 'createCustomOrder'])
    ->name('housing.contract-list.create-custom-order');

// 注文住宅詳細（全員）
Route::get('/contracts/custom-order/{customOrder}',
    [\App\Http\Controllers\Housing\HsContractListController::class, 'showCustomOrder'])
    ->name('housing.contract-list.show-custom-order');

// 編集系（manager/executive のみ）
Route::middleware('role:executive,manager')->group(function () {
    Route::get('/contracts/{hsContract}/edit',
        [\App\Http\Controllers\Housing\HsContractListController::class, 'editBuilding'])
        ->name('housing.contract-list.edit-building');
    Route::put('/contracts/{hsContract}',
        [\App\Http\Controllers\Housing\HsContractListController::class, 'updateBuilding'])
        ->name('housing.contract-list.update-building');
    Route::get('/contracts/custom-order/{customOrder}/edit',
        [\App\Http\Controllers\Housing\HsContractListController::class, 'editCustomOrder'])
        ->name('housing.contract-list.edit-custom-order');
    Route::put('/contracts/custom-order/{customOrder}',
        [\App\Http\Controllers\Housing\HsContractListController::class, 'updateCustomOrder'])
        ->name('housing.contract-list.update-custom-order');
});
```

**重要**: `/contracts/custom-order/{customOrder}` は `/contracts/{hsContract}` より**先に**登録されるよう配置する（Laravelルート照合順序対策）。

- [ ] **Step 3: ルート登録確認**

ブラウザで `https://domain/manage/public/housing/contracts/create/building` にアクセスし、「Method editBuilding... does not exist」系のエラーで「ルートは認識されている」ことを確認（メソッド未実装のためエラー内容は変わる）。

- [ ] **Step 4: Commit**

```bash
git add routes/web.php
git commit -m "住宅事業契約管理のルート追加（編集・注文住宅詳細・新規登録導線）"
```

## Task 3: DTOマッパー拡張

既存`mapTateuriToDto` / `mapCustomOrderToDto`に粗利分離・ステータス情報を追加。

**Files:**
- Modify: `app/Http/Controllers/Housing/HsContractListController.php` (L148-200)

- [ ] **Step 1: 既存マッパーメソッドを読む**

`mapTateuriToDto()` (L148-172)と`mapCustomOrderToDto()` (L177-200)の現在の返却キーを確認。

- [ ] **Step 2: `mapTateuriToDto`に以下のキーを追加**

```php
// 既存の戻り値配列に追加
'land_profit'         => $c->getLandProfit(),        // int
'building_profit'     => $c->getBuildingProfit(),    // int
'land_selling_price'  => $c->selling_price_land ?? 0,
'land_cost'           => $c->property?->land_cost ?? 0,
'status_label'        => '成約済',
'status_badge_style'  => 'background:#DBEAFE;color:#1E40AF;',
'edit_url'            => in_array(auth()->user()->role ?? '', ['executive','manager'])
    ? route('housing.contract-list.edit-building', $c)
    : null,
```

（既存キーは削除せず保持）

- [ ] **Step 3: `mapCustomOrderToDto`に以下のキーを追加**

```php
// 既存の戻り値配列に追加
'land_profit'         => $c->isCustomerLand() ? null : $c->getLandProfit(),
'building_profit'     => $c->getBuildingProfit(),
'land_selling_price'  => $c->isCustomerLand() ? null : ($c->land_selling_price ?? 0),
'land_cost'           => $c->isCustomerLand() ? null : ($c->land_cost ?? 0),
'status_label'        => $c->status->label(),
'status_badge_style'  => $c->status->badgeStyle(),
'edit_url'            => in_array(auth()->user()->role ?? '', ['executive','manager'])
    ? route('housing.contract-list.edit-custom-order', $c)
    : null,
```

**既存の`detail_url`キーの値も確認し、注文住宅の場合は`route('housing.contract-list.show-custom-order', $c)`に差し替え**（既存が`/housing/custom-orders/{id}`へリダイレクトしているなら）。

- [ ] **Step 4: indexメソッドのサマリー計算拡張**

`index()`メソッド（L23-120）のサマリー集計部分を拡張。既存で合計契約額・粗利等を計算している箇所に、以下を追加:

```php
// 既存のサマリー計算に追加
$landProfitTotal = $allContracts->sum('land_profit');       // nullは自動で0扱い
$buildingProfitTotal = $allContracts->sum('building_profit');
```

View用変数として渡す:

```php
return view('housing.contracts.index', compact(
    // 既存変数
    'contracts', 'fiscalYears', 'staffList', 'filters',
    'tateuriCount', 'customCount', 'sellingTotal', 'costTotal',
    'profitTotal', 'profitRate',
    // 新規変数
    'landProfitTotal', 'buildingProfitTotal'
));
```

- [ ] **Step 5: 既存index動作確認（影響ないこと）**

ブラウザで `/housing/contracts` にアクセスし、既存の一覧が正常表示されることを確認。この時点で新サマリーはまだ表示されないが、既存表示が壊れていないかを確認（DTOに新キーが増えてもBladeは無視するはず）。

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Housing/HsContractListController.php
git commit -m "DTOマッパー拡張（土地粗利/建物粗利/ステータス/編集URL追加）"
```

## Task 4: Controller新規メソッドスケルトン追加

すべての新ルートに対応する空メソッドをController に追加（中身は後続タスクで実装）。

**Files:**
- Modify: `app/Http/Controllers/Housing/HsContractListController.php`

- [ ] **Step 1: 以下のメソッドスケルトンをController末尾（クラス閉じ括弧の直前）に追加**

```php
    /**
     * 注文住宅契約詳細
     */
    public function showCustomOrder(HsCustomOrder $customOrder)
    {
        abort_if(
            $customOrder->status->stepIndex() < CustomOrderStatus::Contracted->stepIndex()
            || $customOrder->contract_date === null,
            404
        );
        // TODO: View用データ準備は Task 7 で実装
        return view('housing.contracts.show-custom-order', compact('customOrder'));
    }

    /**
     * 建売契約編集フォーム表示
     */
    public function editBuilding(HsContract $hsContract)
    {
        // TODO: 必要データロードは Task 9 で実装
        return view('housing.contracts.edit-building', compact('hsContract'));
    }

    /**
     * 建売契約更新
     */
    public function updateBuilding(Request $request, HsContract $hsContract)
    {
        // TODO: Task 9 で実装
        abort(501, 'Not implemented yet');
    }

    /**
     * 注文住宅契約編集フォーム表示
     */
    public function editCustomOrder(HsCustomOrder $customOrder)
    {
        return view('housing.contracts.edit-custom-order', compact('customOrder'));
    }

    /**
     * 注文住宅契約更新
     */
    public function updateCustomOrder(Request $request, HsCustomOrder $customOrder)
    {
        abort(501, 'Not implemented yet');
    }

    /**
     * 新規建売契約登録: 未契約物件選択画面
     */
    public function createBuilding()
    {
        $properties = \App\Models\HsProperty::whereDoesntHave('contract')
            ->orderBy('property_code')
            ->get();
        return view('housing.contracts.select-building-property',
            compact('properties'));
    }

    /**
     * 新規注文住宅契約登録: 既存create画面へリダイレクト
     */
    public function createCustomOrder()
    {
        return redirect()->route('housing.custom-orders.create');
    }
```

- [ ] **Step 2: 動作確認**

ブラウザで各ルートにアクセス:
- `/housing/contracts/create/custom-order` → `/housing/custom-orders/create` へリダイレクト
- `/housing/contracts/create/building` → Bladeなしでエラー（View未作成）

Viewエラーが出ることは想定内（Task 11以降で作成）。

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Housing/HsContractListController.php
git commit -m "HsContractListControllerに新規メソッドスケルトン追加"
```

---

# Phase 3: 一覧ページ改修

## Task 5: 一覧ページのサマリーカード拡張（5カード化）

**Files:**
- Modify: `resources/views/housing/contracts/index.blade.php`

- [ ] **Step 1: 既存のサマリーカード部分を読む**

`index.blade.php`を開き、現在のサマリーカード（推定L30-60付近）の構造を把握。

- [ ] **Step 2: 土地粗利・建物粗利カード2枚を追加**

既存の「粗利合計」カードの直前に、以下の2カードを挿入:

```blade
<div class="bg-white border border-gray-200 rounded-lg p-4">
    <div class="text-xs text-gray-500 mb-1">土地粗利合計</div>
    <div style="color: #047857; font-weight: 700; font-size: 18px;">
        {{ number_format($landProfitTotal) }}円
    </div>
</div>
<div class="bg-white border border-gray-200 rounded-lg p-4">
    <div class="text-xs text-gray-500 mb-1">建物粗利合計</div>
    <div style="color: #047857; font-weight: 700; font-size: 18px;">
        {{ number_format($buildingProfitTotal) }}円
    </div>
</div>
```

※ グリッドクラスを既存の`grid-cols-3`等から`grid-cols-5`に変更（またはレスポンシブ対応: `grid-cols-2 md:grid-cols-3 lg:grid-cols-5`）。`grid-cols-5`は確認要、動作しない場合はインラインスタイルで`grid-template-columns: repeat(5, 1fr); gap: 16px;`を使う（Viteビルド`docs/RULES.md`参照）。

- [ ] **Step 3: ブラウザ確認**

`/housing/contracts`で土地粗利合計・建物粗利合計の2カードが表示されることを確認。金額が計算されて表示されているか、カードの幅・間隔が崩れていないか確認。

- [ ] **Step 4: Commit**

```bash
git add resources/views/housing/contracts/index.blade.php
git commit -m "一覧サマリーに土地粗利・建物粗利カード追加"
```

## Task 6: 一覧ページのテーブル列拡張（11列化）+ 新規登録ドロップダウン

**Files:**
- Modify: `resources/views/housing/contracts/index.blade.php`

- [ ] **Step 1: 既存テーブルのth/td部分を確認**

現在の9列（契約日/種別/物件名/顧客/契約額/原価/粗利額/担当/詳細）の構造を確認。

- [ ] **Step 2: 列構成を11列に変更**

- 「原価」列を削除
- 「粗利額」列を「土地粗利」「建物粗利」「合計粗利」の3列に分割
- 「進行状況」列を「担当」の前に追加

```blade
<thead>
    <tr class="bg-gray-50 text-xs text-gray-600 border-b border-gray-200">
        <th class="text-left px-3 py-2">契約日</th>
        <th class="text-left px-3 py-2">種別</th>
        <th class="text-left px-3 py-2">物件名</th>
        <th class="text-left px-3 py-2">顧客</th>
        <th class="text-right px-3 py-2">契約額</th>
        <th class="text-right px-3 py-2">土地粗利</th>
        <th class="text-right px-3 py-2">建物粗利</th>
        <th class="text-right px-3 py-2">合計粗利</th>
        <th class="text-left px-3 py-2">進行状況</th>
        <th class="text-left px-3 py-2">担当</th>
        <th class="text-center px-3 py-2">アクション</th>
    </tr>
</thead>
<tbody>
    @foreach($contracts as $c)
        <tr class="border-b border-gray-100 hover:bg-gray-50">
            <td class="px-3 py-2">{{ $c['contract_date']?->format('Y/m/d') ?? '—' }}</td>
            <td class="px-3 py-2">
                <span style="{{ $c['type_badge_style'] ?? 'background:#E5E7EB;color:#374151;' }} padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                    {{ $c['type_label'] ?? ($c['type'] === 'building' ? '建売' : '注文住宅') }}
                </span>
            </td>
            <td class="px-3 py-2">
                <a href="{{ $c['detail_url'] }}" class="text-emerald-600 hover:underline">
                    {{ $c['name'] }}
                </a>
            </td>
            <td class="px-3 py-2">{{ $c['customer_name'] }}</td>
            <td class="text-right px-3 py-2">{{ number_format($c['selling_price_total']) }}円</td>
            <td class="text-right px-3 py-2">
                @if($c['land_profit'] === null)
                    <span class="text-gray-400">—</span>
                @else
                    <span style="color:#047857;font-weight:600;">{{ number_format($c['land_profit']) }}円</span>
                @endif
            </td>
            <td class="text-right px-3 py-2">
                <span style="color:#047857;font-weight:600;">{{ number_format($c['building_profit']) }}円</span>
            </td>
            <td class="text-right px-3 py-2">
                <span style="color:#047857;font-weight:700;">{{ number_format($c['profit']) }}円</span>
            </td>
            <td class="px-3 py-2">
                <span style="{{ $c['status_badge_style'] }} padding:2px 8px; border-radius:4px; font-size:11px;">
                    {{ $c['status_label'] }}
                </span>
            </td>
            <td class="px-3 py-2">{{ $c['staff_name'] }}</td>
            <td class="text-center px-3 py-2">
                <a href="{{ $c['detail_url'] }}" class="text-xs text-emerald-600 hover:underline">詳細</a>
            </td>
        </tr>
    @endforeach
</tbody>
```

既存の`type_label` / `type_badge_style`キーの有無を確認。なければDTOマッパーに追加する（Task 3で扱い済みの可能性あり）。

- [ ] **Step 3: ヘッダー右側に `[+ 新規契約登録]` ドロップダウン追加**

既存のページヘッダー（h1タイトル部分）の右側に、Alpine.jsのドロップダウン追加:

```blade
<div class="flex items-center justify-between mb-5">
    <h1 class="text-lg font-bold text-gray-900">契約管理</h1>

    <div x-data="{ open: false }" class="relative">
        <button type="button"
                @click="open = !open"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-md text-sm font-medium">
            + 新規契約登録
        </button>
        <div x-show="open" @click.away="open = false"
             style="display:none;"
             class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-10">
            <a href="{{ route('housing.contract-list.create-building') }}"
               class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                建売を登録
            </a>
            <a href="{{ route('housing.contract-list.create-custom-order') }}"
               class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                注文住宅を登録
            </a>
        </div>
    </div>
</div>
```

**Alpine.js重要注意**: CLAUDE.mdに従い、`x-data`内で`=>`禁止、`style=`と`:style=`の併用禁止。`x-show`初期時の`style="display:none;"`でチラつき防止。

- [ ] **Step 4: ブラウザ確認**

1. `/housing/contracts`で11列表示、土地粗利・建物粗利・合計粗利が3列で正しく表示される
2. 顧客所有地の注文住宅行で土地粗利が「—」になる
3. 進行状況列にステータスバッジが表示される
4. 「+ 新規契約登録」ボタンをクリックしてドロップダウンが開き、2項目がリンクとして機能する

- [ ] **Step 5: Viewキャッシュクリア**

```bash
sudo rm -f storage/framework/views/*.php
```

（systemctlはmacOSにはないので、Apacheが動いていれば不要、apache2の代わりにbrewのapacheを手動restartする必要がある場合のみ実施）

- [ ] **Step 6: Commit**

```bash
git add resources/views/housing/contracts/index.blade.php
git commit -m "一覧テーブル11列化、新規契約登録ドロップダウン追加"
```

---

# Phase 4: 詳細ページ

## Task 7: 建売詳細ページの改修（「元ページへ」ボタン追加）

**Files:**
- Modify: `resources/views/housing/contracts/show.blade.php`

- [ ] **Step 1: 既存`show.blade.php`のヘッダーボタン部分を確認**

現在のヘッダー右は`[契約一覧に戻る] [編集] [削除]`。

- [ ] **Step 2: 「元ページへ」ボタンを追加、順序調整**

既存の`[編集]`と`[削除]`の間、または編集の右に「元ページへ」を追加:

```blade
<a href="{{ route('housing.properties.show', $hsContract->property) }}"
   class="inline-flex items-center h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
    元ページへ
</a>
```

既存の `[契約一覧に戻る]` は「戻る」に名称変更するか、そのまま残すか判断（今回はユーザー確認不要のためそのまま残す）。

- [ ] **Step 3: ブラウザ確認**

建売契約詳細ページで「元ページへ」ボタンをクリックし、`/housing/properties/{id}` に遷移することを確認。

- [ ] **Step 4: Commit**

```bash
git add resources/views/housing/contracts/show.blade.php
git commit -m "建売契約詳細に「元ページへ」ボタン追加"
```

## Task 8: 注文住宅詳細ページ新規作成

**Files:**
- Create: `resources/views/housing/contracts/show-custom-order.blade.php`

- [ ] **Step 1: Controllerの`showCustomOrder`メソッドに必要データロード追加**

`HsContractListController::showCustomOrder()`を以下に更新:

```php
public function showCustomOrder(HsCustomOrder $customOrder)
{
    abort_if(
        $customOrder->status->stepIndex() < CustomOrderStatus::Contracted->stepIndex()
        || $customOrder->contract_date === null,
        404
    );

    $customOrder->load(['projectLot', 'procurement', 'staff', 'customer']);

    return view('housing.contracts.show-custom-order', compact('customOrder'));
}
```

（`customer`リレーションがSoftDelete対応ならwithTrashed）

- [ ] **Step 2: `show-custom-order.blade.php`新規作成**

既存の建売`show.blade.php`を参考にしつつ、注文住宅向けに以下を含める:

```blade
@extends('layouts.app')

@section('title', $customOrder->order_name . ' — 契約詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contract-list.index') }}" class="hover:text-emerald-600">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $customOrder->order_name }}</span>
@endsection

@section('content')

{{-- ヘッダー --}}
<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <span style="background:#FEF3C7;color:#92400E;padding:2px 10px;border-radius:4px;font-size:12px;font-weight:600;">
            注文住宅
        </span>
        <h1 class="text-lg font-bold text-gray-900">{{ $customOrder->order_name }}</h1>
    </div>
    <div class="flex items-center gap-2">
        @if(in_array(auth()->user()->role, ['executive', 'manager']))
            <a href="{{ route('housing.contract-list.edit-custom-order', $customOrder) }}"
               class="inline-flex items-center h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                編集
            </a>
        @endif
        <a href="{{ route('housing.custom-orders.show', $customOrder) }}"
           class="inline-flex items-center h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
            元ページへ
        </a>
        <a href="{{ route('housing.contract-list.index') }}"
           class="inline-flex items-center h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
            戻る
        </a>
    </div>
</div>

{{-- 基本情報 + 金額サマリー (2カラム) --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">基本情報</h2>
        <dl class="text-sm space-y-2">
            <div class="flex justify-between"><dt class="text-gray-500">契約日</dt>
                <dd>{{ $customOrder->contract_date?->format('Y/m/d') ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">顧客名</dt>
                <dd>{{ $customOrder->customer_name }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">担当者</dt>
                <dd>{{ $customOrder->staff?->name ? explode(' ', $customOrder->staff->name)[0] : '—' }}</dd></div>
        </dl>
    </div>

    {{-- 金額サマリー --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">金額サマリー</h2>
        <dl class="text-sm space-y-2">
            <div class="flex justify-between"><dt class="text-gray-500">契約額合計</dt>
                <dd>{{ number_format($customOrder->getTotalSellingPrice()) }}円</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">原価合計</dt>
                <dd>{{ number_format($customOrder->getTotalCost()) }}円</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">合計粗利</dt>
                <dd style="color:#047857;font-weight:700;">{{ number_format($customOrder->getTotalProfit()) }}円</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">粗利率</dt>
                <dd>{{ number_format($customOrder->getTotalProfitRate(), 1) }}%</dd></div>
        </dl>
    </div>
</div>

{{-- 契約金額内訳 --}}
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">契約金額内訳</h2>
    <dl class="text-sm space-y-2">
        @if($customOrder->isCustomerLand())
            <div class="flex justify-between"><dt class="text-gray-500">土地販売価格</dt>
                <dd class="text-gray-400">— （顧客所有地）</dd></div>
        @else
            <div class="flex justify-between"><dt class="text-gray-500">土地販売価格</dt>
                <dd>{{ number_format($customOrder->land_selling_price ?? 0) }}円</dd></div>
        @endif
        <div class="flex justify-between"><dt class="text-gray-500">建物契約価格</dt>
            <dd>{{ number_format($customOrder->building_contract_price ?? 0) }}円（税率 {{ number_format($customOrder->tax_rate ?? 10, 1) }}%）</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">建物消費税</dt>
            <dd>{{ number_format(($customOrder->building_contract_price ?? 0) * ($customOrder->tax_rate ?? 10) / 100) }}円</dd></div>
        <div class="flex justify-between border-t border-gray-100 pt-2"><dt class="font-semibold">税込合計</dt>
            <dd class="font-semibold">{{ number_format($customOrder->getTotalSellingPriceWithTax() ?? $customOrder->getTotalSellingPrice()) }}円</dd></div>
    </dl>
</div>

{{-- 原価内訳 --}}
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">原価内訳</h2>
    <dl class="text-sm space-y-2">
        @if($customOrder->isCustomerLand())
            <div class="flex justify-between"><dt class="text-gray-500">土地原価</dt>
                <dd class="text-gray-400">— （顧客所有地）</dd></div>
        @else
            <div class="flex justify-between"><dt class="text-gray-500">土地原価</dt>
                <dd>{{ number_format($customOrder->land_cost ?? 0) }}円</dd></div>
        @endif
        <div class="flex justify-between"><dt class="text-gray-500">建物原価</dt>
            <dd>{{ number_format($customOrder->building_cost ?? 0) }}円</dd></div>
        <div class="flex justify-between border-t border-gray-100 pt-2"><dt class="font-semibold">合計原価</dt>
            <dd class="font-semibold">{{ number_format($customOrder->getTotalCost()) }}円</dd></div>
    </dl>
</div>

{{-- 粗利分析 --}}
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">粗利分析</h2>
    <dl class="text-sm space-y-2">
        @if($customOrder->isCustomerLand())
            <div class="flex justify-between"><dt class="text-gray-500">土地粗利</dt>
                <dd class="text-gray-400">— （顧客所有地）</dd></div>
        @else
            <div class="flex justify-between"><dt class="text-gray-500">土地粗利</dt>
                <dd style="color:#047857;font-weight:600;">
                    {{ number_format($customOrder->getLandProfit()) }}円
                    （{{ number_format($customOrder->getLandProfitRate(), 1) }}%）
                </dd></div>
        @endif
        <div class="flex justify-between"><dt class="text-gray-500">建物粗利</dt>
            <dd style="color:#047857;font-weight:600;">
                {{ number_format($customOrder->getBuildingProfit()) }}円
                （{{ number_format($customOrder->getBuildingProfitRate(), 1) }}%）
            </dd></div>
        <div class="flex justify-between border-t border-gray-100 pt-2"><dt class="font-semibold">合計粗利</dt>
            <dd style="color:#047857;font-weight:700;">
                {{ number_format($customOrder->getTotalProfit()) }}円
                （{{ number_format($customOrder->getTotalProfitRate(), 1) }}%）
            </dd></div>
    </dl>
</div>

{{-- 物件情報 --}}
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">物件情報</h2>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div><dt class="text-gray-500 text-xs">注文コード</dt><dd>{{ $customOrder->order_code }}</dd></div>
        <div><dt class="text-gray-500 text-xs">住所</dt><dd>{{ $customOrder->address }}</dd></div>
        <div><dt class="text-gray-500 text-xs">土地面積</dt><dd>{{ $customOrder->land_area_sqm }} ㎡</dd></div>
        <div><dt class="text-gray-500 text-xs">建物面積</dt><dd>{{ $customOrder->building_area_sqm }} ㎡</dd></div>
        <div><dt class="text-gray-500 text-xs">構造</dt><dd>{{ $customOrder->structure }}</dd></div>
        <div><dt class="text-gray-500 text-xs">階数</dt><dd>{{ $customOrder->floors }}階</dd></div>
        <div><dt class="text-gray-500 text-xs">完成予定</dt><dd>{{ $customOrder->scheduled_completion_date?->format('Y/m/d') ?? '—' }}</dd></div>
        <div><dt class="text-gray-500 text-xs">引渡日</dt><dd>{{ $customOrder->delivery_date?->format('Y/m/d') ?? '—' }}</dd></div>
    </dl>
</div>

{{-- 進行ステータスバー --}}
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">進行ステータス</h2>
    @php
        $steps = [
            ['label' => '商談', 'index' => 0],
            ['label' => '設計', 'index' => 1],
            ['label' => '見積', 'index' => 2],
            ['label' => '契約', 'index' => 3],
            ['label' => '着工', 'index' => 4],
            ['label' => '完成', 'index' => 5],
            ['label' => '引渡し', 'index' => 6],
        ];
        $currentIndex = $customOrder->status->stepIndex();
    @endphp
    <div class="flex items-center justify-between">
        @foreach($steps as $i => $step)
            <div class="flex flex-col items-center" style="flex: 1;">
                <div style="width:24px;height:24px;border-radius:50%;
                    background:{{ $step['index'] <= $currentIndex ? '#047857' : '#E5E7EB' }};
                    color:{{ $step['index'] <= $currentIndex ? 'white' : '#9CA3AF' }};
                    display:flex;align-items:center;justify-content:center;
                    font-size:11px;font-weight:600;">
                    {{ $i + 1 }}
                </div>
                <span class="mt-1 text-xs {{ $step['index'] <= $currentIndex ? 'text-gray-900' : 'text-gray-400' }}">
                    {{ $step['label'] }}
                </span>
            </div>
            @if(!$loop->last)
                <div style="height:2px;background:{{ $step['index'] < $currentIndex ? '#047857' : '#E5E7EB' }};flex: 1;margin: 0 4px 16px;"></div>
            @endif
        @endforeach
    </div>
    <p class="text-xs text-gray-500 mt-3">※ステータス変更は<a href="{{ route('housing.custom-orders.show', $customOrder) }}" class="text-emerald-600 hover:underline">注文住宅ページ</a>で行ってください。</p>
</div>

{{-- 備考 --}}
@if($customOrder->notes)
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">備考</h2>
    <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $customOrder->notes }}</p>
</div>
@endif

@endsection
```

**注意**: モデルメソッド名(`getTotalSellingPriceWithTax`, `getLandProfitRate`, `getBuildingProfitRate` 等)は既存の`HsCustomOrder`に実在するか確認。なければ既存メソッド(`getTotalSellingPrice` 等)を使って計算。

- [ ] **Step 3: ブラウザ確認**

1. `/housing/contracts/custom-order/{id}` で注文住宅詳細が正しく表示される
2. 顧客所有地の場合、土地関連項目が「—」になる
3. 進行ステータスバーが現在のステップまで緑色、以降がグレー
4. 各アクションボタンが正しく機能する
5. 契約前ステータス（Design等）のIDでアクセス→404

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Housing/HsContractListController.php resources/views/housing/contracts/show-custom-order.blade.php
git commit -m "注文住宅契約詳細ページ新規作成（進行ステータスバー含む）"
```

---

# Phase 5: 編集ページ

## Task 9: 建売契約編集ページ

**Files:**
- Create: `resources/views/housing/contracts/edit-building.blade.php`
- Modify: `app/Http/Controllers/Housing/HsContractListController.php`

- [ ] **Step 1: Controllerの`editBuilding`と`updateBuilding`を実装**

```php
public function editBuilding(HsContract $hsContract)
{
    $hsContract->load(['property', 'property.projectLot', 'property.procurement']);
    $staffList = \App\Models\User::orderBy('name')->get();
    $buyers = \App\Models\Buyer::withTrashed()->orderBy('name')->get();
    return view('housing.contracts.edit-building',
        compact('hsContract', 'staffList', 'buyers'));
}

public function updateBuilding(Request $request, HsContract $hsContract)
{
    $validated = $request->validate([
        'contract_date'          => 'required|date',
        'customer_name'          => 'required|string|max:255',
        'customer_id'            => 'nullable|integer|exists:buyers,id',
        'staff_user_id'          => 'required|integer|exists:users,id',
        'selling_price_land'     => 'required|integer|min:0',
        'selling_price_building' => 'required|integer|min:0',
        'tax_rate'               => 'required|numeric|min:0|max:100',
        'notes'                  => 'nullable|string|max:2000',
        'is_land_cost_manual'    => 'nullable|boolean',
        'land_cost'              => 'required_if:is_land_cost_manual,1|integer|min:0',
        'building_cost'          => 'required|integer|min:0',
    ]);

    \DB::transaction(function() use ($validated, $hsContract, $request) {
        $hsContract->update([
            'contract_date'          => $validated['contract_date'],
            'customer_id'            => $validated['customer_id'] ?? null,
            'customer_name'          => $validated['customer_name'],
            'staff_user_id'          => $validated['staff_user_id'],
            'selling_price_land'     => $validated['selling_price_land'],
            'selling_price_building' => $validated['selling_price_building'],
            'tax_rate'               => $validated['tax_rate'],
            'notes'                  => $validated['notes'] ?? null,
            'updated_by'             => auth()->id(),
        ]);
        $hsContract->property->update([
            'land_cost'           => $request->boolean('is_land_cost_manual')
                ? ($validated['land_cost'] ?? 0)
                : $hsContract->property->land_cost,
            'building_cost'       => $validated['building_cost'],
            'is_land_cost_manual' => $request->boolean('is_land_cost_manual'),
        ]);
    });

    return redirect()->route('housing.contract-list.show', $hsContract)
        ->with('success', '契約情報を更新しました');
}
```

- [ ] **Step 2: `edit-building.blade.php`を新規作成**

既存`edit.blade.php`(物件サブリソース用、145行)を参考にしつつ、契約情報+原価のみに絞ったフォームを作成。

```blade
@extends('layouts.app')

@section('title', $hsContract->property->property_code . ' — 契約編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contract-list.index') }}" class="hover:text-emerald-600">契約管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contract-list.show', $hsContract) }}" class="hover:text-emerald-600">{{ $hsContract->property->property_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

<div class="mb-5">
    <h1 class="text-lg font-bold text-gray-900">契約編集 — {{ $hsContract->property->property_name }}</h1>
</div>

<form method="POST" action="{{ route('housing.contract-list.update-building', $hsContract) }}"
      x-data="{ isLandCostManual: {{ $hsContract->property->is_land_cost_manual ? 'true' : 'false' }} }">
    @csrf
    @method('PUT')

    {{-- 基本情報セクション --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">基本情報</h2>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">契約日 <span class="text-red-500">*</span></label>
            <input type="date" name="contract_date"
                   value="{{ old('contract_date', $hsContract->contract_date?->format('Y-m-d')) }}"
                   class="form-input w-full" required>
            @error('contract_date')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">顧客名 <span class="text-red-500">*</span></label>
            <input type="text" name="customer_name"
                   value="{{ old('customer_name', $hsContract->customer_name) }}"
                   class="form-input w-full" required>
            @error('customer_name')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">顧客（買主マスタから選択）</label>
            <select name="customer_id" class="form-input w-full">
                <option value="">— マスタから選択しない（手入力） —</option>
                @foreach($buyers as $b)
                    <option value="{{ $b->id }}"
                        {{ old('customer_id', $hsContract->customer_id) == $b->id ? 'selected' : '' }}>
                        {{ $b->name }}{{ $b->deleted_at ? '（削除済）' : '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">担当者 <span class="text-red-500">*</span></label>
            <select name="staff_user_id" class="form-input w-full" required>
                @foreach($staffList as $u)
                    <option value="{{ $u->id }}"
                        {{ old('staff_user_id', $hsContract->staff_user_id) == $u->id ? 'selected' : '' }}>
                        {{ explode(' ', $u->name)[0] ?? $u->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">備考</label>
            <textarea name="notes" rows="3" class="form-input w-full">{{ old('notes', $hsContract->notes) }}</textarea>
        </div>
    </div>

    {{-- 契約金額セクション --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">契約金額</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom:26px;">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地販売価格 <span class="text-red-500">*</span></label>
                <div class="flex items-center gap-2">
                    <input type="number" name="selling_price_land"
                           value="{{ old('selling_price_land', $hsContract->selling_price_land) }}"
                           class="form-input w-full" min="0" required>
                    <span class="text-sm text-gray-500">円</span>
                </div>
                @error('selling_price_land')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物販売価格 <span class="text-red-500">*</span></label>
                <div class="flex items-center gap-2">
                    <input type="number" name="selling_price_building"
                           value="{{ old('selling_price_building', $hsContract->selling_price_building) }}"
                           class="form-input w-full" min="0" required>
                    <span class="text-sm text-gray-500">円</span>
                </div>
                @error('selling_price_building')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">税率 <span class="text-red-500">*</span></label>
            <div class="flex items-center gap-2" style="width:150px;">
                <input type="number" name="tax_rate" step="0.1"
                       value="{{ old('tax_rate', $hsContract->tax_rate ?? 10) }}"
                       class="form-input w-full" min="0" max="100" required>
                <span class="text-sm text-gray-500">%</span>
            </div>
            @error('tax_rate')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- 原価セクション --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">原価情報</h2>

        <div style="margin-bottom:26px;">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_land_cost_manual" value="1"
                       x-model="isLandCostManual"
                       {{ old('is_land_cost_manual', $hsContract->property->is_land_cost_manual) ? 'checked' : '' }}>
                <span>土地原価を手動入力する</span>
            </label>
        </div>

        <div x-show="isLandCostManual" style="margin-bottom:26px; display: none;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">土地原価</label>
            <div class="flex items-center gap-2">
                <input type="number" name="land_cost"
                       :disabled="!isLandCostManual"
                       value="{{ old('land_cost', $hsContract->property->land_cost) }}"
                       class="form-input w-full" min="0">
                <span class="text-sm text-gray-500">円</span>
            </div>
            @error('land_cost')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        <div x-show="!isLandCostManual" class="text-xs text-gray-500 p-2 bg-gray-50 rounded"
             style="margin-bottom:26px;">
            紐付け元（分譲地PJ/仕入案件）の販売価格を土地原価として自動参照します。
        </div>

        <div style="margin-bottom:26px;">
            <label class="block text-sm font-semibold text-gray-700 mb-1">建物原価 <span class="text-red-500">*</span></label>
            <div class="flex items-center gap-2">
                <input type="number" name="building_cost"
                       value="{{ old('building_cost', $hsContract->property->building_cost) }}"
                       class="form-input w-full" min="0" required>
                <span class="text-sm text-gray-500">円</span>
            </div>
            @error('building_cost')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- 送信ボタン --}}
    <div class="flex items-center justify-between gap-2">
        <a href="{{ route('housing.properties.contract.edit', $hsContract->property) }}"
           class="inline-flex items-center h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50">
            元ページで全項目編集
        </a>
        <div class="flex items-center gap-2">
            <a href="{{ route('housing.contract-list.show', $hsContract) }}"
               class="inline-flex items-center h-9 px-4 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-medium">
                更新
            </button>
        </div>
    </div>
</form>

@endsection
```

**CLAUDE.md遵守確認**:
- `placeholder="0"` 使わない ✓
- `style=` + `:style=` の併用なし ✓
- Alpine `x-show`時に`:disabled`併用 ✓
- `margin-bottom:26px`でitem spacing ✓

- [ ] **Step 3: ブラウザ確認**

1. `/housing/contracts/{id}/edit` にアクセス→フォーム表示
2. 必須項目を空で送信→バリデーションエラー表示
3. 手動入力チェックボックスのON/OFFで土地原価入力欄の表示切替
4. 正常入力→送信→詳細ページにリダイレクト、「契約情報を更新しました」表示
5. HsContractとHsPropertyの両方が更新されることをphpMyAdminで確認
6. staffでURL直打ち→403エラー

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Housing/HsContractListController.php \
        resources/views/housing/contracts/edit-building.blade.php
git commit -m "建売契約編集ページ新規作成（HsContract + HsProperty同時更新）"
```

## Task 10: 注文住宅契約編集ページ

**Files:**
- Create: `resources/views/housing/contracts/edit-custom-order.blade.php`
- Modify: `app/Http/Controllers/Housing/HsContractListController.php`

- [ ] **Step 1: Controllerの`editCustomOrder`と`updateCustomOrder`を実装**

```php
public function editCustomOrder(HsCustomOrder $customOrder)
{
    abort_if($customOrder->contract_date === null, 404);
    $customOrder->load(['projectLot', 'procurement']);
    $staffList = \App\Models\User::orderBy('name')->get();
    $buyers = \App\Models\Buyer::withTrashed()->orderBy('name')->get();
    return view('housing.contracts.edit-custom-order',
        compact('customOrder', 'staffList', 'buyers'));
}

public function updateCustomOrder(Request $request, HsCustomOrder $customOrder)
{
    $validated = $request->validate([
        'contract_date'           => 'required|date',
        'customer_name'           => 'required|string|max:255',
        'customer_id'             => 'nullable|integer|exists:buyers,id',
        'staff_user_id'           => 'required|integer|exists:users,id',
        'land_source_type'        => 'required|in:project_lot,procurement,customer_land',
        'land_selling_price'      => 'required_unless:land_source_type,customer_land|nullable|integer|min:0',
        'building_contract_price' => 'required|integer|min:0',
        'tax_rate'                => 'required|numeric|min:0|max:100',
        'notes'                   => 'nullable|string|max:2000',
        'is_land_cost_manual'     => 'nullable|boolean',
        'land_cost'               => 'nullable|integer|min:0',
        'building_cost'           => 'required|integer|min:0',
    ]);

    // 顧客所有地以外かつ手動入力なら土地原価必須
    if ($validated['land_source_type'] !== 'customer_land'
        && $request->boolean('is_land_cost_manual')
        && ($validated['land_cost'] ?? null) === null) {
        return back()->withErrors(['land_cost' => '土地原価を入力してください'])->withInput();
    }

    \DB::transaction(function() use ($validated, $customOrder, $request) {
        $customOrder->update([
            'contract_date'           => $validated['contract_date'],
            'customer_id'             => $validated['customer_id'] ?? null,
            'customer_name'           => $validated['customer_name'],
            'staff_user_id'           => $validated['staff_user_id'],
            'land_selling_price'      => $validated['land_source_type'] === 'customer_land'
                ? null : ($validated['land_selling_price'] ?? 0),
            'building_contract_price' => $validated['building_contract_price'],
            'tax_rate'                => $validated['tax_rate'],
            'land_cost'               => $validated['land_source_type'] === 'customer_land'
                ? null : ($request->boolean('is_land_cost_manual') ? $validated['land_cost'] : $customOrder->land_cost),
            'building_cost'           => $validated['building_cost'],
            'is_land_cost_manual'     => $request->boolean('is_land_cost_manual'),
            'notes'                   => $validated['notes'] ?? null,
            'updated_by'              => auth()->id(),
        ]);
    });

    return redirect()->route('housing.contract-list.show-custom-order', $customOrder)
        ->with('success', '契約情報を更新しました');
}
```

- [ ] **Step 2: `edit-custom-order.blade.php`を新規作成**

`edit-building.blade.php`をベースに以下を変更:

- 基本情報セクション: 建売と同じ
- 契約金額セクション: Alpine.jsで`land_source_type`(`customer_land`)時に土地販売価格を非表示+`:disabled`
- 原価セクション: 顧客所有地時に土地原価を非表示+`:disabled`、建物原価は常時表示

Alpine.js初期値:
```blade
x-data="{
    landSourceType: '{{ $customOrder->land_source_type->value }}',
    isLandCostManual: {{ $customOrder->is_land_cost_manual ? 'true' : 'false' }}
}"
```

土地販売価格セクション:
```blade
<div x-show="landSourceType !== 'customer_land'"
     style="margin-bottom:26px; display: none;">
    <label class="block text-sm font-semibold text-gray-700 mb-1">土地販売価格 <span class="text-red-500">*</span></label>
    <input type="number" name="land_selling_price"
           :disabled="landSourceType === 'customer_land'"
           value="{{ old('land_selling_price', $customOrder->land_selling_price) }}"
           class="form-input w-full" min="0">
    {{-- 省略 --}}
</div>

<div x-show="landSourceType === 'customer_land'"
     class="text-xs text-gray-500 p-2 bg-gray-50 rounded" style="margin-bottom:26px; display: none;">
    顧客所有地のため、土地関連項目は入力不要です。
</div>
```

（全体のBladeは上記の建売版edit-building.blade.phpとほぼ同じ構造。差分は`land_source_type`切替のみ。完全なコードは実装時に建売版をコピーして調整）

- [ ] **Step 3: ブラウザ確認**

1. `/housing/contracts/custom-order/{id}/edit` にアクセス→フォーム表示
2. CompanyLand（ProjectLot/Procurement）の注文住宅で土地関連項目が表示される
3. CustomerLandの注文住宅で土地関連項目が非表示になる
4. 必須項目バリデーションエラー
5. 正常入力→送信→詳細ページにリダイレクト
6. HsCustomOrderレコードが更新されることをphpMyAdminで確認

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Housing/HsContractListController.php \
        resources/views/housing/contracts/edit-custom-order.blade.php
git commit -m "注文住宅契約編集ページ新規作成（土地源別の表示切替対応）"
```

---

# Phase 6: 新規登録導線

## Task 11: 建売物件選択画面

**Files:**
- Create: `resources/views/housing/contracts/select-building-property.blade.php`

- [ ] **Step 1: Viewファイル作成**

```blade
@extends('layouts.app')

@section('title', '建売契約登録 — 物件選択')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contract-list.index') }}" class="hover:text-emerald-600">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">物件選択</span>
@endsection

@section('content')

<div class="mb-5 flex items-center justify-between">
    <h1 class="text-lg font-bold text-gray-900">建売契約登録 — 物件選択</h1>
    <a href="{{ route('housing.contract-list.index') }}"
       class="inline-flex items-center h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
        戻る
    </a>
</div>

<p class="text-sm text-gray-600 mb-4">契約を登録する建売物件を選択してください。一覧には未契約の物件のみ表示されています。</p>

@if($properties->isEmpty())
    <div class="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p class="text-gray-500 mb-4">未契約の建売物件がありません。</p>
        <a href="{{ route('housing.properties.create') }}"
           class="inline-flex items-center h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-medium">
            建売物件を新規登録
        </a>
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-600 border-b border-gray-200">
                <tr>
                    <th class="text-left px-3 py-2">物件コード</th>
                    <th class="text-left px-3 py-2">物件名</th>
                    <th class="text-left px-3 py-2">ステータス</th>
                    <th class="text-left px-3 py-2">住所</th>
                    <th class="text-center px-3 py-2">アクション</th>
                </tr>
            </thead>
            <tbody>
                @foreach($properties as $p)
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-3 py-2">{{ $p->property_code }}</td>
                        <td class="px-3 py-2">{{ $p->property_name }}</td>
                        <td class="px-3 py-2">
                            <span style="{{ $p->status->badgeStyle() ?? 'background:#E5E7EB;color:#374151;' }}
                                   padding:2px 8px;border-radius:4px;font-size:11px;">
                                {{ $p->status?->label() ?? '—' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-gray-600">{{ $p->address }}</td>
                        <td class="text-center px-3 py-2">
                            <a href="{{ route('housing.properties.contract.create', $p) }}"
                               class="inline-flex items-center h-8 px-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-xs font-medium">
                                この物件で契約登録
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
```

**ルート名確認**: `housing.properties.contract.create` が既存ルート名と一致するか確認。`routes/web.php`の該当箇所を検索して実際のルート名を使う。

- [ ] **Step 2: ブラウザ確認**

1. `/housing/contracts/create/building` にアクセス→未契約物件一覧表示
2. 物件がない場合の空状態表示
3. 「この物件で契約登録」クリック→既存の契約登録画面へ遷移
4. 一覧から「+ 新規契約登録」→「建売を登録」ドロップダウン→この画面に来ることを確認

- [ ] **Step 3: Commit**

```bash
git add resources/views/housing/contracts/select-building-property.blade.php
git commit -m "建売契約登録の物件選択画面を新規作成"
```

## Task 12: 注文住宅新規登録リダイレクト動作確認

（Task 4のスケルトン実装でリダイレクトは既に動作するはず。再確認のみ）

- [ ] **Step 1: ブラウザ確認**

1. 一覧ページで「+ 新規契約登録」→「注文住宅を登録」クリック
2. `/housing/contracts/create/custom-order` にアクセス
3. `/housing/custom-orders/create` へリダイレクトされることを確認

- [ ] **Step 2: 問題なければ次のタスクへ**

リダイレクトが機能しない場合は、`createCustomOrder()`メソッドのルート名`housing.custom-orders.create`が正しいか`routes/web.php`で確認し、必要に応じて修正。

---

# Phase 7: 手動テスト + 完了確認

## Task 13: 包括的な手動テスト

**Files:** なし（テストのみ）

- [ ] **Step 1: staffアカウントで動作確認**

テスト用staffアカウント（住宅事業所属）でログインし、以下を確認:
- ログイン後 `/housing/contracts` にアクセス可能
- 一覧ページの全11列表示、5サマリーカード表示
- フィルター各種動作
- 建売詳細にアクセス可能、編集ボタン**非表示**
- 注文住宅詳細にアクセス可能、編集ボタン**非表示**
- 「+ 新規契約登録」ドロップダウン表示、両オプション遷移可能
- `/housing/contracts/{id}/edit` 直接URLアクセス→**403**
- `/housing/contracts/custom-order/{id}/edit` 直接URLアクセス→**403**

- [ ] **Step 2: managerアカウントで動作確認**

- 全操作可能
- 編集ボタン表示
- 編集実行→正常更新→詳細へリダイレクト
- 建売編集でHsContract + HsPropertyが両方更新されることをphpMyAdminで確認
- 注文住宅編集でHsCustomOrderが更新されることを確認

- [ ] **Step 3: executiveアカウントで動作確認**

- 全操作可能
- staffでも住宅事業外の場合にアクセス不可→executiveは部署問わず可能

- [ ] **Step 4: エッジケース確認**

- 契約前の注文住宅ID（Design等）でURL直打ち→404
- 契約日nullの注文住宅でURL直打ち→404
- 存在しないID→404
- 不正な`{type}`→404
- 顧客所有地の注文住宅詳細→土地関連「—」表示
- 顧客所有地の注文住宅編集→土地関連セクション非表示

- [ ] **Step 5: サイドバー確認**

住宅事業以外のページから、サイドバーの「契約管理」リンククリック→`/housing/contracts`へ遷移、アクティブ判定。

- [ ] **Step 6: 既存機能の非破壊確認**

本実装が既存機能に影響していないことを確認:
- `/housing/properties/{id}` 物件詳細 → 正常
- `/housing/properties/{id}/contract/create` 既存の契約登録 → 正常
- `/housing/properties/{id}/contract/edit` 既存の契約編集 → 正常
- `/housing/custom-orders/*` → 正常
- `/realestate/*` → 正常（影響なし）
- `/tenant/*` → 正常（影響なし）

- [ ] **Step 7: 動作確認ログを簡潔にまとめて最終コミット**

```bash
git log --oneline -20  # 実装コミットの確認
```

すべての手動テストが通過したら、作業完了。

- [ ] **Step 8: BACKLOG.md 優先度1のエントリを「実装完了」に更新**

```bash
# BACKLOG.mdの「優先度1: 住宅事業 契約管理」セクションに「✓ 実装完了（2026-04-XX）」を追記
```

`docs/BACKLOG.md`を編集して完了マークを付け、コミット:

```bash
git add docs/BACKLOG.md
git commit -m "BACKLOG: 住宅事業 契約管理の実装完了マーク"
```

---

## スコープ外（再掲）

本実装では**やらないこと**:

- 契約削除機能の新設（既存showの[削除]は維持）
- 注文住宅のステータス遷移（元ページで管理）
- 添付ファイル管理（元ページで管理）
- 決済日(settlement_date)の表示・編集
- 自動テストコード作成（手動テストのみ）
- Fecture/Unit Testの追加

---

## 想定される落とし穴

1. **Viteビルド外のTailwindクラス**: `gap-5`, `grid-cols-5` 等は`docs/RULES.md`の「Broken Tailwind Classes」参照。動かない場合はinline styleで対応。
2. **Alpine.js `x-data`内の`=>`**: アロー関数の`>`がHTML閉じタグと解釈される。名前付きfunctionを`<script>`に出す、または`$watch`で回避。
3. **重複name + x-show**: データ消失バグの原因。`:disabled`を併用。
4. **`@json()`内のPHP関数**: `number_format()`等はController側で事前計算。
5. **ルートモデルバインディング順序**: `/contracts/custom-order/{customOrder}`を`/contracts/{hsContract}`より先に登録。
6. **Buyerのwithtrashed**: SoftDeletes対応必須。
7. **ViewキャッシュAppleSilicon**: `sudo rm -f storage/framework/views/*.php`で対応（systemctlがない環境ではbrewのapache再起動またはキャッシュ削除のみで十分な場合が多い）。
8. **既存のルート名**: 設計書で`housing.contract-list.*`と記載したが、実装中にルート名が異なることが判明した場合はコード修正が必要。

---

## 実装順序の根拠

1. **Phase 1（モック）**: ユーザー承認を得てからコード着手（手戻り防止）
2. **Phase 2（Controller+ルート）**: バックエンド基盤を先に固めることで、以降のView作業がスムーズ
3. **Phase 3（一覧）**: 既存機能の改修で早期に動作確認
4. **Phase 4（詳細）**: 新ルートが機能することを早期確認
5. **Phase 5（編集）**: コア機能、手戻りが大きいので中盤で実装
6. **Phase 6（新規登録導線）**: 既存への依存度が高いので最後
7. **Phase 7（テスト）**: 総合確認

各フェーズの完了後にcommitし、問題発生時のロールバックを容易にする。
