# 仕入れ案件一覧から分譲地を新規登録できるようにする — 設計書

- 日付: 2026-07-29
- 対象画面: `/realestate/procurements`（仕入れ案件一覧）/ `/realestate/projects/create`（分譲地 新規登録）
- ブランチ: `feature/procurement-create-dropdown`
- 前提となる設計書: `docs/superpowers/specs/2026-07-27-procurement-list-with-projects-design.md`

---

## 1. 背景と目的

2026-07-28 に仕入れ案件一覧へ分譲地の行を統合し、一覧は「不動産案件の横断ビュー」になった。
しかし前設計書の**決定 #10 で「新規登録ボタンは現状のまま（仕入れ案件の登録へ）。
分譲地の登録は分譲地一覧ページから」と決めていた**ため、
一覧に分譲地が並んでいるのに、そこから分譲地を登録する導線が無い。

**決定 #10 を今回撤回し、一覧から両種別を登録できるようにする。**

**DB 変更・ルート追加は不要。** 既存の 2 つの登録画面へ振り分けるだけで足りる。

---

## 2. 現状調査（設計判断の根拠）

### 2.1 登録フォームは 2 本立てで、項目が重ならない

| 区分 | 項目 |
|---|---|
| 共通（15） | 所在地 / 査定額 / 建蔽率 / 容積率 / 契約日 / 決済日 / 情報入手日 / 土地面積 / 緯度 / 経度 / 備考 / 購入価格 / 想定販売価格 / ステータス / 用途地域 |
| 仕入れ案件のみ（5） | `property_type` `transaction_type` `building_area_sqm` `built_year_month` `structure` |
| 名称カラムの差 | 仕入れ案件 `property_name` / 分譲地 `project_name` |

### 2.2 2 つのフォームは 1 画面に同居できない

両フォームとも Google Maps を **`onGoogleMapsReady` というグローバル関数名**で初期化している。

- `resources/views/realestate/procurements/_form.blade.php:369`
- `resources/views/realestate/projects/_form.blade.php:326`

1 画面に両方を置くと後に定義した方が勝ち、**片方の地図が無言で初期化されない**
（`view:cache` も `php -l` もテストも通り、壊れるのはブラウザだけ。Bug #28 と同型）。
さらに両者は原価管理の partial `realestate/_partials/_cost_section_form` を共有しており、
要素 ID も衝突する。

→ **統合フォーム案は採らない**（§3 の C 案）。

### 2.3 同型の前例が本番稼働している

住宅事業の契約管理 `/housing/contracts` は建売と注文住宅の混在一覧で、
ヘッダーが「新規契約登録 ▾」のドロップダウン →「建売を登録」「注文住宅を登録」の 2 項目。
`resources/views/housing/contracts/index.blade.php:68-92`。**今回と同じ問題形をすでに解いている。**

### 2.4 認可は両者で完全対称

| ルート | ミドルウェア |
|---|---|
| `GET /realestate/procurements/create` | `department.access:realestate` + `role:executive,manager` |
| `GET /realestate/projects/create` | `department.access:realestate` + `role:executive,manager` |

`routes/web.php:715-720` / `routes/web.php:864-869` で実測確認。
一覧ヘッダーの既存ガード `auth()->user()->role->isManagerOrAbove()`
（`UserRole::isManagerOrAbove()` = Executive または Manager）がそのまま両方をカバーする。
**認可の追加実装は不要。**

---

## 3. 検討した案

| 案 | 内容 | 判定 |
|---|---|---|
| **A（採用）** | 「新規登録 ▾」ドロップダウン → 既存の 2 つの create 画面へ遷移 | 変更 3 ファイル。フォーム・バリデーション・store は無変更。§2.3 の前例と意匠が揃う |
| B | ボタン 2 つを横並び | 1 クリックで到達するが、ヘッダーが横に伸びスマホ幅で 2 段になる。前例と不一致 |
| C | 統合フォーム（種別ラジオで項目を出し分け、振り分け保存） | §2.2 の地図衝突・ID 衝突に加え、同一 `name` + `x-show` は hidden でも送信される（Bug #3）、バリデーション分岐、`old()` 復元の分岐と過去バグの温床に集中的に触る。**採らない** |

---

## 4. 決定事項（ユーザー承認済み）

| # | 決定 | 内容 |
|---|------|------|
| 1 | 導線 | 一覧ヘッダーの「新規登録」を**ドロップダウン**にし、「仕入れ案件を登録」「分譲地を登録」の 2 項目にする |
| 2 | 遷移先 | 既存の `procurements/create` / `projects/create` へそのまま遷移する。新画面は作らない |
| 3 | キャンセル時の戻り先 | 仕入れ案件一覧から分譲地登録に入った場合、**キャンセルは仕入れ案件一覧へ戻す** |
| 4 | 登録成功時の遷移 | **現状のまま**（分譲地の詳細画面へ）。区画登録へ続けられるため変えない |
| 5 | 分譲地一覧ページ | **残す**。そこから登録した場合の戻り先も従来どおり分譲地一覧 |
| 6 | モック | **作らない**。§2.3 に同型 UI が本番稼働しており、デプロイ後のブラウザ確認で代える |

---

## 5. 画面仕様

### 5.1 一覧ヘッダー

```
┌─────────────────────────────────────────────┐
│ 仕入れ案件一覧              [ ＋ 新規登録 ▾ ] │
└─────────────────────────────────────────────┘
                                  ↓ クリックで開く
                          ┌──────────────────┐
                          │ 仕入れ案件を登録  │
                          ├──────────────────┤
                          │ 分譲地を登録      │
                          └──────────────────┘
```

- トリガーの見た目（緑・角丸・`＋` アイコン）は現行ボタンのまま。右端に `▾` を足す
- 項目の順序は「仕入れ案件」が先（この画面の主対象のため）
- パネルは右寄せ・幅 200px 以上。外側クリックで閉じる
- staff ロールにはドロップダウンごと出さない（現行ガードのまま）

### 5.2 分譲地 新規登録画面

`?from=procurements` の有無で、**パンくずの中間リンク**と**キャンセルボタンの戻り先**だけが変わる。

| 入口 | URL | パンくず | キャンセル |
|---|---|---|---|
| 仕入れ案件一覧から | `/realestate/projects/create?from=procurements` | 不動産管理 › **仕入れ案件一覧** › 新規登録 | 仕入れ案件一覧 |
| 分譲地一覧から（従来） | `/realestate/projects/create` | 不動産管理 › 分譲地一覧 › 新規登録 | 分譲地一覧 |

- 見出し「分譲地 新規登録」・入力項目・原価管理・地図は**一切変わらない**
- 登録成功時はどちらの入口でも分譲地の詳細画面へ（決定 #4）
- バリデーション失敗時は Laravel の `back()` がセッションの直前 GET URL
  （`?from=procurements` 付き）へ戻すため、パラメータは保持される

---

## 6. 実装設計

### 6.1 構成

| ファイル | 区分 | 内容 |
|---|---|---|
| `resources/views/realestate/procurements/index.blade.php` | 変更 | ヘッダーのボタンをドロップダウン化 |
| `app/Http/Controllers/RealEstate/ProjectController.php` | 変更 | `create()` で戻り先を決める |
| `resources/views/realestate/projects/create.blade.php` | 変更 | パンくず・キャンセル URL を変数化 |
| `tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php` | 新規 | 回帰テスト |

**新規 PHP クラスは追加しないため `composer dump-autoload` は不要。**

### 6.2 一覧ヘッダー（`procurements/index.blade.php:20-29` を差し替え）

```blade
{{-- ページヘッダー（+ 新規登録ドロップダウン） --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <h1 class="text-lg font-bold text-gray-900">仕入れ案件一覧</h1>
    @if(auth()->user()->role->isManagerOrAbove())
        {{-- 一覧に分譲地の行も並ぶため、登録先を種別ごとに選ばせる。
             /housing/contracts（建売 / 注文住宅）と同じパターン --}}
        <div x-data="{ open: false }" class="relative w-full sm:w-auto">
            <button type="button" @click="open = !open"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規登録
                <svg class="w-3 h-3 ml-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div x-show="open" @click.outside="open = false" x-cloak
                 class="absolute right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg min-w-[200px] z-10 overflow-hidden">
                <a href="{{ route('realestate.procurements.create') }}"
                   class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-emerald-600 border-b border-gray-100 transition-colors">
                    仕入れ案件を登録
                </a>
                <a href="{{ route('realestate.projects.create', ['from' => 'procurements']) }}"
                   class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-emerald-600 transition-colors">
                    分譲地を登録
                </a>
            </div>
        </div>
    @endif
</div>
```

実装上の注意:

- ⚠ **`x-show` と同じ要素の `:style` に `display` を書かない**（Bug #32）。
  ここでは `:style` を一切使わず静的な Tailwind クラスだけなので抵触しない。
  既存の回帰テスト `tests/Feature/AlpineXShowDisplayConflictTest.php` は
  `x-show` + `:style` の組み合わせだけを走査するため、この書き方は素通りする
- `x-cloak` の `display: none` は `resources/css/app.css:19` に定義済み
- 現行の `<a>` を `<button>` に変える。`cursor-pointer` は不要
  （ビルド済み CSS に preflight 由来の `button{cursor:pointer}` が入っていることを
  `grep -oE 'button[^{]*\{[^}]*cursor[^}]*\}' public/build/assets/app-*.css` で実測確認済み）
- Tailwind クラスは普通に書いてよい（`./deploy.sh` が `npm run build` する）。
  ローカルで見た目を確認するときだけ手で `npm run build`

### 6.3 `ProjectController::create()`

現在の `public function create()` に `Request $request` を足す
（`Illuminate\Http\Request` は同ファイルで import 済み）。

```php
public function create(Request $request)
{
    $zoningTypes = ZoningType::orderBy('sort_order')->get();

    // ... 既存の原価管理セクション用データ組み立ては変更なし ...

    // 戻り先。仕入れ案件一覧から入ったときだけそちらへ戻す。
    // 受け付ける値は 'procurements' の 1 語だけで、URL はこちらが route() から組む
    // （リクエストの文字列を href へ素通しさせないのでオープンリダイレクトにならない）。
    $fromProcurements = $request->query('from') === 'procurements';
    $backUrl   = $fromProcurements
        ? route('realestate.procurements.index')
        : route('realestate.projects.index');
    $backLabel = $fromProcurements ? '仕入れ案件一覧' : '分譲地一覧';

    return view('realestate.projects.create', compact(
        'zoningTypes', 'costItemsForJs', 'costAliasMap', 'costSkipList', 'costSubtotalKws',
        'backUrl', 'backLabel'
    ));
}
```

**Blade の `@php` ブロックではなくコントローラで組む理由**:
`@section('breadcrumb')` は子ビューの実行順にキャプチャされるため、
`@php` で変数を作る場合は必ず breadcrumb セクションより前に置く必要があり、
行を入れ替えただけで未定義変数になる。コントローラで渡せばこの順序依存が消える。

### 6.4 `projects/create.blade.php`

```blade
@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ $backUrl }}" class="hover:text-emerald-600 transition-colors">{{ $backLabel }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection
```

```blade
<x-form-actions submit-label="登録する" :cancel-url="$backUrl" />
```

⚠ **Bug #21 回避がここの要点。** Blade の Anonymous Component の属性式に
`route(&quot;...&quot;)` のような HTML エンティティを書くと、本番（PHP 8.3 + `view:cache` で
全 Blade を precompile）でだけデコードが漏れ、`syntax error, unexpected token "&"` で
500 になる。ローカルの lazy compile では再現しない。
**属性には PHP 変数 1 本だけを渡す。** 三項演算子や `route()` 呼び出しを属性内に書かない。

`x-form-actions` は `cancelUrl` プロップを `<a href="{{ $cancelUrl }}">キャンセル</a>` に
そのまま流すだけなので、文字列を渡せば足りる（`resources/views/components/form-actions.blade.php`）。

### 6.5 仕入れ案件側は無変更

「仕入れ案件を登録」の遷移先 `procurements/create` は、
キャンセル URL が既に `route('realestate.procurements.index')` を指しており、
パンくずも「仕入れ案件一覧 › 新規登録」で正しい。**触らない。**

---

## 7. テスト

`tests/Feature/RealEstate/ProcurementListCreateDropdownTest.php` を新設する。

| テスト | 内容 |
|---|---|
| `test_list_shows_both_create_links` | 一覧に `procurements/create` と `projects/create?from=procurements` の両方のリンクが出る |
| `test_staff_sees_no_create_links` | staff ロールではどちらのリンクも出ない |
| `test_project_create_from_procurements_cancels_to_procurement_list` | `?from=procurements` でキャンセル URL が仕入れ案件一覧 |
| `test_project_create_from_procurements_breadcrumb_points_to_procurement_list` | 同上でパンくずが「仕入れ案件一覧」 |
| `test_project_create_without_from_cancels_to_project_list` | パラメータ無しなら従来どおり分譲地一覧（既存挙動の回帰） |
| `test_unknown_from_value_falls_back_to_project_list` | `?from=housing` など未知の値は分譲地一覧に落ちる（ホワイトリストの確認） |

注意点:

- ⚠ **`assertSee('分譲地一覧')` のような文字列一致に頼らない。**
  パンくずと画面内の他要素の両方に同じ語が出るため false-pass する。
  **`assertSee(route(...))` で URL 文字列そのものを見る**
  （`?from=procurements` の有無まで含めて一意に判定できる）
- `phpunit.xml` の `APP_URL` はパス無しの `http://localhost` 固定なので、
  `route()` は `http://localhost/realestate/projects/create?from=procurements` を返す。
  クエリが 1 個だけで `&` を含まないため HTML エスケープの影響を受けない
- テスト実行は main repo で `composer install`（dev 込み）→ `vendor/bin/phpunit`。
  worktree で走らせる場合は worktree 側でも `composer install` とテスト用ダミー鍵の
  `.env` が要る（`artisan test` も `pest` も無い）

---

## 8. 変更しないもの

| 対象 | 理由 |
|---|---|
| `realestate/procurements/_form.blade.php` | 仕入れ案件の登録内容は変わらない |
| `realestate/projects/_form.blade.php` | 分譲地の登録内容は変わらない（地図・原価管理・仕入れ先ピッカーすべて無変更） |
| `ProjectController::store()` / `validateProject()` | 保存とバリデーションは同一 |
| `ProcurementController` | 全メソッド無変更 |
| 登録成功後のリダイレクト先 | 決定 #4。分譲地詳細のままにして区画登録へ続けられるようにする |
| `/realestate/projects` 一覧・サイドバーの「分譲地」 | 分譲地だけを見たいときの導線として残す |
| ルート定義 | `from` はクエリ文字列。新規ルート不要 |
| DB スキーマ | 追加カラム不要 |

---

## 9. デプロイ

1. worktree で `/commit`
2. main repo で `git checkout 13.x && git merge --ff-only feature/procurement-create-dropdown`
3. `composer dump-autoload` は**不要**（新規 PHP クラスなし）
4. `./deploy.sh`（`npm run build` → rsync → `config:cache && route:cache && view:cache`）
5. 本番ブラウザで確認（下記）

### 本番での確認項目

- ⚠ **実際にドロップダウンを開く。** HTML にリンクが出ていることと、
  Alpine が動いてパネルが開くことは別問題（Bug #28 と同型で、
  スクリプトが一度も実行されていないケースを取り逃す）
- パネルが**縦に 2 項目並ぶ**こと（横 1 列に潰れていないこと。Bug #32 と同型）
- 外側クリックで閉じること
- 「分譲地を登録」→ 分譲地の登録画面が開き、パンくずが「仕入れ案件一覧」になっていること
- その画面で**キャンセル → 仕入れ案件一覧へ戻る**こと
- 必須項目を空のまま登録 → エラーで戻ったあとも**キャンセル先が仕入れ案件一覧のまま**であること
  （`?from=procurements` がセッションの直前 URL 経由で保持されているかの確認）
- 「仕入れ案件を登録」が従来どおり動くこと（回帰）
- 分譲地一覧から登録した場合はキャンセル先が分譲地一覧のままであること（回帰）
- スマホ幅でヘッダーが崩れていないこと
